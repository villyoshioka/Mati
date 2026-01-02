<?php
/**
 * 設定管理クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mati_Settings {

	/**
	 * シングルトンインスタンス
	 */
	private static $instance = null;

	/**
	 * 設定のオプション名
	 */
	const OPTION_NAME = 'mati_settings';

	/**
	 * ベータモードパスワードハッシュ（SHA-256）
	 * パスワード: Mati_test9999
	 * セキュリティ: ハッシュ値のみを保存し、平文パスワードはソースコードに含めない
	 */
	private $beta_password_hash = '15b85ad5e5d01a928251f71e447b682aa06c026295e2c1f89287d0c4837ec6f3';

	/**
	 * シングルトンインスタンスを取得
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * コンストラクタ
	 */
	private function __construct() {
		// 特に初期化処理は不要
	}

	/**
	 * 設定を取得
	 */
	public function get_settings() {
		$defaults = $this->get_default_settings();
		$settings = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * デフォルト設定を取得
	 */
	public function get_default_settings() {
		return array(
			// メタタグ削除系
			'meta_removal_enabled'       => false, // 親チェックボックス
			'remove_generator'           => false,
			'remove_rest_api_link'       => false,
			'remove_oembed'              => false,
			'remove_rsd'                 => false,
			'remove_wlwmanifest'         => false,
			'remove_shortlink'           => false,
			'remove_pingback'            => false,

			// コンテンツ保護
			'content_protection_enabled' => false, // 親チェックボックス
			'disable_right_click'        => false,
			'disable_devtools_keys'      => false,
			'disable_save_keys'          => false,
			'disable_text_selection'     => false,
			'disable_image_drag'         => false,
			'disable_print'              => false,
			'add_noarchive_meta'         => false,
			'add_noimageindex_meta'      => false,
			'add_noai_meta'              => false,

			// SEO
			'google_verification'        => '',
			'bing_verification'          => '',
			'fediverse_profile_urls'     => array(),
			'enable_jsonld'              => false,
		);
	}

	/**
	 * 設定を保存
	 */
	public function save_settings( $new_settings ) {
		// サニタイズ
		$sanitized = $this->sanitize_settings( $new_settings );

		// 保存
		update_option( self::OPTION_NAME, $sanitized );

		return true;
	}

	/**
	 * 設定をサニタイズ
	 */
	private function sanitize_settings( $settings ) {
		$sanitized = array();

		// チェックボックス系（boolean）
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
		);

		foreach ( $checkbox_keys as $key ) {
			$sanitized[ $key ] = ! empty( $settings[ $key ] );
		}

		// テキスト入力系（SEO認証メタタグ）
		// 英数字、ハイフン、アンダースコアのみ許可
		$sanitized['google_verification'] = $this->sanitize_verification_code( $settings['google_verification'] ?? '' );
		$sanitized['bing_verification']   = $this->sanitize_verification_code( $settings['bing_verification'] ?? '' );

		// Fediverse プロフィールURL（HTTPS必須、配列形式）
		$sanitized['fediverse_profile_urls'] = $this->sanitize_profile_urls( $settings['fediverse_profile_urls'] ?? array() );

		return $sanitized;
	}

	/**
	 * 認証コードをサニタイズ
	 */
	private function sanitize_verification_code( $code ) {
		// 空の場合はそのまま返す
		if ( empty( $code ) ) {
			return '';
		}

		// 英数字、ハイフン、アンダースコアのみ許可
		$code = preg_replace( '/[^a-zA-Z0-9_-]/', '', $code );

		return sanitize_text_field( $code );
	}

	/**
	 * プロフィールURLをサニタイズ（HTTPS必須）
	 *
	 * @param string $url 入力されたURL
	 * @return string サニタイズされたURL
	 */
	private function sanitize_profile_url( $url ) {
		// 空の場合はそのまま返す
		if ( empty( $url ) ) {
			return '';
		}

		// URLとして整形
		$url = esc_url_raw( $url, array( 'https' ) );

		// HTTPSで始まっているか確認
		if ( strpos( $url, 'https://' ) !== 0 ) {
			return '';
		}

		return $url;
	}

	/**
	 * プロフィールURLの配列をサニタイズ（最大5個まで）
	 *
	 * @param array $urls プロフィールURLの配列
	 * @return array サニタイズされたURLの配列
	 */
	private function sanitize_profile_urls( $urls ) {
		if ( ! is_array( $urls ) ) {
			return array();
		}

		$sanitized = array();
		$count     = 0;

		foreach ( $urls as $url ) {
			// 最大5個まで
			if ( $count >= 5 ) {
				break;
			}

			$sanitized_url = $this->sanitize_profile_url( $url );

			// 空のURLはスキップ
			if ( ! empty( $sanitized_url ) ) {
				$sanitized[] = $sanitized_url;
				$count++;
			}
		}

		return $sanitized;
	}

	/**
	 * 設定をリセット
	 */
	public function reset_settings() {
		$defaults = $this->get_default_settings();
		update_option( self::OPTION_NAME, $defaults );
		return true;
	}

	/**
	 * 特定の設定値を取得
	 */
	public function get_setting( $key, $default = null ) {
		$settings = $this->get_settings();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * 設定をエクスポート
	 *
	 * @return string JSON形式の設定データ
	 */
	public function export_settings() {
		$settings = $this->get_settings();

		// バージョン情報を先頭に追加（フラット構造）
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
	 * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
	 */
	public function import_settings( $json ) {
		// JSONサイズチェック（100KB制限）
		if ( strlen( $json ) > 100000 ) {
			return new WP_Error( 'json_too_large', 'JSONデータが大きすぎます（最大100KB）。' );
		}

		// JSONデコード（ネスト深さを10階層に制限）
		$imported = json_decode( $json, true, 10 );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', 'JSONの形式が正しくありません。' );
		}

		if ( ! is_array( $imported ) ) {
			return new WP_Error( 'invalid_format', '設定データの形式が正しくありません。' );
		}

		// キー数の上限チェック（DoS対策）
		if ( count( $imported, COUNT_RECURSIVE ) > 100 ) {
			return new WP_Error( 'too_many_keys', '設定データのキー数が多すぎます。' );
		}

		// バージョン情報を取得（フラット構造）
		$import_version = isset( $imported['version'] ) ? $imported['version'] : null;

		// 設定データを取得（versionキーを除外）
		$settings_data = $imported;
		if ( isset( $settings_data['version'] ) ) {
			unset( $settings_data['version'] );
		}

		// 後方互換性: ネスト構造（旧形式）にも対応
		if ( isset( $imported['settings'] ) && is_array( $imported['settings'] ) ) {
			$settings_data = $imported['settings'];
		}

		// 現在の設定を取得
		$current = $this->get_settings();

		// インポート設定をマージ（現在の設定を上書き）
		$merged = array_merge( $current, $settings_data );

		// サニタイズして保存
		$sanitized = $this->sanitize_settings( $merged );
		update_option( self::OPTION_NAME, $sanitized );

		return true;
	}

	/**
	 * ベータモードが有効かどうかを確認
	 *
	 * @return bool 有効ならtrue
	 */
	public function is_beta_mode_enabled() {
		return (bool) get_transient( 'mati_beta_channel' );
	}

	/**
	 * ベータモードを有効化（パスワード検証付き）
	 *
	 * セキュリティ:
	 * - タイミングセーフな比較（hash_equals）でハッシュ値を検証
	 * - パスワードは平文保存せず、SHA-256ハッシュ値のみ保存
	 * - ブルートフォース攻撃対策: レート制限実装済み（5回失敗で10分間ロック）
	 *
	 * @param string $password 入力されたパスワード
	 * @return bool|WP_Error 認証成功ならtrue、レート制限超過ならWP_Error
	 */
	public function enable_beta_mode( $password ) {
		$user_id      = get_current_user_id();
		$attempts_key = 'mati_beta_attempts_' . $user_id;

		// レート制限チェック（5回失敗で10分間ロック）
		$attempts = get_transient( $attempts_key );
		if ( $attempts >= 5 ) {
			return new WP_Error( 'rate_limit', 'ログイン試行回数が超過しました。10分後に再試行してください。' );
		}

		// タイミングセーフなハッシュ比較
		if ( hash_equals( $this->beta_password_hash, hash( 'sha256', $password ) ) ) {
			// 成功時は試行回数をクリア
			delete_transient( $attempts_key );
			set_transient( 'mati_beta_channel', true, DAY_IN_SECONDS );
			return true;
		}

		// 失敗回数をインクリメント（10分間保持）
		$new_attempts = $attempts ? $attempts + 1 : 1;
		set_transient( $attempts_key, $new_attempts, 10 * MINUTE_IN_SECONDS );

		return false;
	}

	/**
	 * ベータモードを無効化
	 */
	public function disable_beta_mode() {
		delete_transient( 'mati_beta_channel' );
		// ベータ用キャッシュもクリア
		delete_transient( 'mati_github_release_cache_beta' );
	}
}
