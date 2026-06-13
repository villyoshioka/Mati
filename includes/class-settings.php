<?php
/**
 * 設定管理クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( false ) {
	class CP_Cache {
		public static function get_instance(): self { return new self(); }
		public function clear_all(): void {}
	}
}

class Mati_Settings {

	private static ?self $instance = null;

	const string OPTION_NAME = 'mati_settings';

	/**
	 * ベータモードパスワードハッシュ（SHA-256）
	 * セキュリティ: ハッシュ値のみを保存し、平文パスワードはソースコードに含めない
	 */
	private string $beta_password_hash = '15b85ad5e5d01a928251f71e447b682aa06c026295e2c1f89287d0c4837ec6f3';

	public static function get_instance(): static {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
	}

	/**
	 * WordPress Playground（php-wasm）環境かどうかを判定
	 *
	 * WordPress Studio は内部で Playground を使用しており、
	 * SERVER_SOFTWARE には 'PHP.wasm' が設定される。
	 */
	public static function is_playground(): bool {
		$server_software = $_SERVER['SERVER_SOFTWARE'] ?? '';
		return false !== stripos( $server_software, 'php.wasm' );
	}

	public function get_settings(): array {
		$defaults = $this->get_default_settings();
		$settings = get_option( self::OPTION_NAME, array() );
		$merged   = wp_parse_args( $settings, $defaults );

		if ( empty( $merged['obfuscation_seed'] ) ) {
			$merged['obfuscation_seed'] = $this->generate_seed();
		}

		return $merged;
	}

	public function get_default_settings(): array {
		return array(
			'meta_removal_enabled'       => true,
			'remove_generator'           => true,
			'remove_rest_api_link'       => true,
			'remove_oembed'              => true,
			'remove_rsd'                 => true,
			'remove_wlwmanifest'         => true,
			'remove_shortlink'           => true,
			'remove_pingback'            => true,

			'content_protection_enabled' => true,
			'disable_right_click'        => true,
			'disable_devtools_keys'      => true,
			'disable_save_keys'          => true,
			'disable_image_drag'         => true,
			'disable_print'              => true,
			'add_noarchive_meta'         => true,
			'add_noimageindex_meta'      => true,
			'add_noai_meta'              => true,

			'google_analytics_id'        => '',
			'google_verification'        => '',
			'bing_verification'          => '',
			'fediverse_profile_urls'     => array(),
			'bluesky_profile_url'        => '',
			'bluesky_did'                => '',
			'enable_jsonld'              => true,
			'add_noindex_meta'           => false,

			'disable_text_selection'     => false,
			'frame_ancestors_domains'    => '',

			'obfuscation_seed'           => '',
		);
	}

	/**
	 * 設定を保存
	 *
	 * @param array $new_settings 新しい設定値
	 * @param array $options オプション（skip_seed_regen, skip_cp_clear）
	 */
	public function save_settings( array $new_settings, array $options = array() ): bool {
		if ( empty( $options['skip_seed_regen'] ) ) {
			$new_settings['obfuscation_seed'] = $this->generate_seed();
		}

		$sanitized = $this->sanitize_settings( $new_settings );
		update_option( self::OPTION_NAME, $sanitized );

		if ( empty( $options['skip_cp_clear'] ) && class_exists( 'CP_Cache' ) ) {
			$cache = CP_Cache::get_instance();
			// 無限ループ回避: CPにMatiシード再生成をスキップさせる
			add_filter( 'cp_skip_mati_seed_regen', '__return_true', 9999 );

			try {
				$cache->clear_all();
			} finally {
				// 例外発生時も必ずフィルターを解除
				remove_filter( 'cp_skip_mati_seed_regen', '__return_true', 9999 );
			}
		}

		return true;
	}

	private function sanitize_settings( array $settings ): array {
		$sanitized = array();

		$checkbox_keys = array(
			'meta_removal_enabled',
			'remove_generator',
			'remove_rest_api_link',
			'remove_oembed',
			'remove_rsd',
			'remove_wlwmanifest',
			'remove_shortlink',
			'remove_pingback',
			'content_protection_enabled',
			'disable_right_click',
			'disable_devtools_keys',
			'disable_save_keys',
			'disable_text_selection',
			'disable_image_drag',
			'disable_print',
			'add_noarchive_meta',
			'add_noimageindex_meta',
			'add_noai_meta',
			'enable_jsonld',
			'add_noindex_meta',
		);

		foreach ( $checkbox_keys as $key ) {
			$sanitized[ $key ] = ! empty( $settings[ $key ] );
		}

		$sanitized['google_analytics_id'] = $this->sanitize_ga_id( $settings['google_analytics_id'] ?? '' );
		$sanitized['google_verification'] = $this->sanitize_verification_code( $settings['google_verification'] ?? '' );
		$sanitized['bing_verification']   = $this->sanitize_verification_code( $settings['bing_verification'] ?? '' );

		$sanitized['fediverse_profile_urls'] = $this->sanitize_profile_urls( $settings['fediverse_profile_urls'] ?? array() );
		$sanitized['bluesky_profile_url'] = $this->sanitize_profile_url( $settings['bluesky_profile_url'] ?? '' );
		$sanitized['bluesky_did'] = $this->sanitize_bluesky_did( $settings['bluesky_did'] ?? '' );
		$sanitized['frame_ancestors_domains'] = $this->sanitize_frame_ancestors_domains( $settings['frame_ancestors_domains'] ?? '' );

		return $sanitized;
	}

	private function sanitize_verification_code( string $code ): string {
		if ( empty( $code ) ) {
			return '';
		}

		$code = preg_replace( '/[^a-zA-Z0-9_-]/', '', $code );

		return sanitize_text_field( $code );
	}

	/**
	 * Google Analytics測定IDをサニタイズ（G-XXXXXXXXXX形式）
	 */
	private function sanitize_ga_id( string $id ): string {
		if ( empty( $id ) ) {
			return '';
		}

		$id = sanitize_text_field( trim( $id ) );

		if ( ! preg_match( '/^G-[A-Z0-9]+$/', $id ) ) {
			return '';
		}

		return $id;
	}

	/**
	 * プロフィールURLをサニタイズ（HTTPS必須）
	 */
	private function sanitize_profile_url( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		$url = esc_url_raw( $url, array( 'https' ) );

		if ( ! str_starts_with( $url, 'https://' ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * プロフィールURLの配列をサニタイズ（最大5個まで）
	 */
	private function sanitize_profile_urls( array|string $urls ): array {
		if ( ! is_array( $urls ) ) {
			return array();
		}

		$sanitized = array();
		$count     = 0;

		foreach ( $urls as $url ) {
			if ( $count >= 5 ) {
				break;
			}

			$sanitized_url = $this->sanitize_profile_url( $url );

			if ( ! empty( $sanitized_url ) ) {
				$sanitized[] = $sanitized_url;
				$count++;
			}
		}

		return $sanitized;
	}

	/**
	 * Bluesky DIDをサニタイズ
	 */
	private function sanitize_bluesky_did( string $did ): string {
		if ( empty( $did ) ) {
			return '';
		}

		$did = sanitize_text_field( $did );

		// did:plc: or did:web: で始まることを確認
		if ( ! preg_match( '/^did:(plc|web):[a-zA-Z0-9._:%-]+$/', $did ) ) {
			return '';
		}

		return $did;
	}

	/**
	 * frame-ancestors許可ドメインをサニタイズ
	 */
	private function sanitize_frame_ancestors_domains( string $domains ): string {
		if ( empty( $domains ) ) {
			return '';
		}

		$lines           = explode( "\n", $domains );
		$sanitized_lines = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$line = str_replace(
				array( "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d" ),
				'',
				$line
			);
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			if ( str_starts_with( $line, 'http://' ) ) {
				$line = 'https://' . substr( $line, 7 );
			}

			$line = esc_url_raw( $line, array( 'https' ) );
			if ( ! str_starts_with( $line, 'https://' ) ) {
				continue;
			}

			$parsed = wp_parse_url( $line );
			if ( $parsed && isset( $parsed['host'] ) ) {
				$origin = 'https://' . $parsed['host'];
				if ( ! empty( $parsed['port'] ) ) {
					$origin .= ':' . intval( $parsed['port'] );
				}
				$sanitized_lines[] = $origin;
			}
		}

		$sanitized_lines = array_unique( $sanitized_lines );

		return implode( "\n", $sanitized_lines );
	}

	public function reset_settings(): bool {
		$defaults = $this->get_default_settings();
		update_option( self::OPTION_NAME, $defaults );

		if ( class_exists( 'CP_Cache' ) ) {
			add_filter( 'cp_skip_mati_seed_regen', '__return_true', 9999 );
			try {
				CP_Cache::get_instance()->clear_all();
			} finally {
				remove_filter( 'cp_skip_mati_seed_regen', '__return_true', 9999 );
			}
		}

		return true;
	}

	public function get_setting( string $key, mixed $default = null ): mixed {
		$settings = $this->get_settings();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * 設定をエクスポート
	 *
	 * @return string JSON形式の設定データ
	 */
	public function export_settings(): string {
		$settings = $this->get_settings();

		$export_data = array_merge(
			array( 'version' => MATI_VERSION ),
			$settings
		);

		return wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * 設定をインポート
	 *
	 * @param string $json JSON形式の設定データ
	 * @return true|\WP_Error 成功ならtrue、失敗ならWP_Error
	 */
	public function import_settings( string $json ): true|\WP_Error {
		// JSONサイズチェック（100KB制限）
		if ( strlen( $json ) > 100000 ) {
			return new WP_Error( 'json_too_large', 'JSONデータが大きすぎます（最大100KB）。' );
		}

		if ( ! json_validate( $json ) ) {
			return new WP_Error( 'invalid_json', 'JSONの形式が正しくありません。' );
		}

		// ネスト深さを10階層に制限
		$imported = json_decode( $json, true, 10 );

		if ( ! is_array( $imported ) ) {
			return new WP_Error( 'invalid_format', '設定データの形式が正しくありません。' );
		}

		// キー数の上限チェック（DoS対策）
		if ( count( $imported, COUNT_RECURSIVE ) > 100 ) {
			return new WP_Error( 'too_many_keys', '設定データのキー数が多すぎます。' );
		}

		$settings_data = $imported;
		if ( isset( $settings_data['version'] ) ) {
			unset( $settings_data['version'] );
		}

		// 後方互換性: ネスト構造（旧形式）にも対応
		if ( isset( $imported['settings'] ) && is_array( $imported['settings'] ) ) {
			$settings_data = $imported['settings'];
		}

		$current = $this->get_settings();
		$merged = array_merge( $current, $settings_data );

		$sanitized = $this->sanitize_settings( $merged );
		update_option( self::OPTION_NAME, $sanitized );

		if ( class_exists( 'CP_Cache' ) ) {
			add_filter( 'cp_skip_mati_seed_regen', '__return_true', 9999 );
			try {
				CP_Cache::get_instance()->clear_all();
			} finally {
				remove_filter( 'cp_skip_mati_seed_regen', '__return_true', 9999 );
			}
		}

		return true;
	}

	public function is_beta_mode_enabled(): bool {
		return (bool) get_transient( 'mati_beta_channel' );
	}

	/**
	 * ベータモードを有効化（パスワード検証付き）
	 *
	 * セキュリティ:
	 * - タイミングセーフな比較（hash_equals）でハッシュ値を検証
	 * - パスワードは平文保存せず、SHA-256ハッシュ値のみ保存
	 * - ブルートフォース攻撃対策: レート制限実装済み（5回失敗で10分間ロック）
	 */
	public function enable_beta_mode( string $password ): bool|\WP_Error {
		$user_id      = get_current_user_id();
		$attempts_key = 'mati_beta_attempts_' . $user_id;

		// レート制限チェック（5回失敗で10分間ロック）
		$attempts = get_transient( $attempts_key );
		if ( $attempts >= 5 ) {
			return new WP_Error( 'rate_limit', 'ログイン試行回数が超過しました。10分後に再試行してください。' );
		}

		// タイミングセーフなハッシュ比較
		if ( hash_equals( $this->beta_password_hash, hash( 'sha256', $password ) ) ) {
			delete_transient( $attempts_key );
			set_transient( 'mati_beta_channel', true, DAY_IN_SECONDS );
			return true;
		}

		$new_attempts = $attempts ? $attempts + 1 : 1;
		set_transient( $attempts_key, $new_attempts, 10 * MINUTE_IN_SECONDS );

		return false;
	}

	public function disable_beta_mode(): void {
		delete_transient( 'mati_beta_channel' );
		delete_transient( 'mati_github_release_cache_beta' );
	}

	private function generate_seed(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 32, false );
		}
		// Fallback: pluggable.php 読み込み前の場合
		return bin2hex( random_bytes( 16 ) );
	}
}
