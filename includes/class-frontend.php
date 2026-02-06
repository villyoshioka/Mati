<?php
/**
 * フロントエンド出力クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mati_Frontend {

	/**
	 * シングルトンインスタンス
	 */
	private static $instance = null;

	/**
	 * 設定マネージャー
	 */
	private $settings_manager;

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
		$this->settings_manager = Mati_Settings::get_instance();

		// メタタグ削除系のフック
		$this->init_meta_removal_hooks();

		// SEOメタタグ追加のフック
		add_action( 'wp_head', array( $this, 'add_seo_meta_tags' ), 1 );

		// レスポンスヘッダー追加のフック
		add_filter( 'wp_headers', array( $this, 'add_security_headers' ), 10 );

		// コンテンツ保護のフック（優先度1で最優先実行）
		add_action( 'wp_head', array( $this, 'add_protection_styles' ), 1 );
		add_action( 'wp_head', array( $this, 'add_protection_scripts' ), 1 );
	}

	/**
	 * メタタグ削除系のフックを初期化
	 */
	private function init_meta_removal_hooks() {
		$settings = $this->settings_manager->get_settings();

		// 個別のチェックボックスがONの場合のみ削除
		if ( ! empty( $settings['remove_generator'] ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( ! empty( $settings['remove_rest_api_link'] ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}

		if ( ! empty( $settings['remove_oembed'] ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}

		if ( ! empty( $settings['remove_rsd'] ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( ! empty( $settings['remove_wlwmanifest'] ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( ! empty( $settings['remove_shortlink'] ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( ! empty( $settings['remove_pingback'] ) ) {
			add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
		}
	}

	/**
	 * Pingbackヘッダーを削除
	 */
	public function remove_pingback_header( $headers ) {
		if ( isset( $headers['X-Pingback'] ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/**
	 * セキュリティヘッダーを追加
	 */
	public function add_security_headers( $headers ) {
		$settings = $this->settings_manager->get_settings();

		// X-Robots-Tag ヘッダーの追加
		$robots_directives = array();

		if ( ! empty( $settings['add_noindex_meta'] ) ) {
			$robots_directives[] = 'noindex';
		}

		if ( ! empty( $settings['add_noarchive_meta'] ) ) {
			$robots_directives[] = 'noarchive';
		}

		if ( ! empty( $settings['add_noimageindex_meta'] ) ) {
			$robots_directives[] = 'noimageindex';
		}

		if ( ! empty( $settings['add_noai_meta'] ) ) {
			$robots_directives[] = 'noai';
			$robots_directives[] = 'noimageai';
		}

		if ( ! empty( $robots_directives ) ) {
			$headers['X-Robots-Tag'] = implode( ', ', $robots_directives );
		}

		// Content-Security-Policy: frame-ancestors 'self' を追加
		if ( isset( $headers['Content-Security-Policy'] ) ) {
			// 既存のCSPヘッダーがある場合
			$csp = $headers['Content-Security-Policy'];
			// frame-ancestorsが既に含まれていない場合のみ追加
			if ( stripos( $csp, 'frame-ancestors' ) === false ) {
				$headers['Content-Security-Policy'] = $csp . "; frame-ancestors 'self'";
			}
		} else {
			// CSPヘッダーがない場合は新規作成
			$headers['Content-Security-Policy'] = "frame-ancestors 'self'";
		}

		// X-Content-Type-Options: nosniff を追加
		$headers['X-Content-Type-Options'] = 'nosniff';

		return $headers;
	}

	/**
	 * SEOメタタグを追加
	 */
	public function add_seo_meta_tags() {
		$settings = $this->settings_manager->get_settings();

		// Google Search Console認証
		if ( ! empty( $settings['google_verification'] ) ) {
			echo '<meta name="google-site-verification" content="' . esc_attr( $settings['google_verification'] ) . '">' . "\n";
		}

		// Bing Webmaster Tools認証
		if ( ! empty( $settings['bing_verification'] ) ) {
			echo '<meta name="msvalidate.01" content="' . esc_attr( $settings['bing_verification'] ) . '">' . "\n";
		}

		// Fediverse本人確認（Misskey、Mastodonなど）
		if ( ! empty( $settings['fediverse_profile_urls'] ) && is_array( $settings['fediverse_profile_urls'] ) ) {
			foreach ( $settings['fediverse_profile_urls'] as $url ) {
				if ( ! empty( $url ) ) {
					echo '<link rel="me" href="' . esc_url( $url ) . '" />' . "\n";
				}
			}
		}

		// robots メタタグの統合管理
		$robots_directives = array();

		if ( ! empty( $settings['add_noindex_meta'] ) ) {
			$robots_directives[] = 'noindex';
		}

		if ( ! empty( $settings['add_noarchive_meta'] ) ) {
			$robots_directives[] = 'noarchive';
		}

		if ( ! empty( $settings['add_noimageindex_meta'] ) ) {
			$robots_directives[] = 'noimageindex';
		}

		if ( ! empty( $settings['add_noai_meta'] ) ) {
			$robots_directives[] = 'noai';
			$robots_directives[] = 'noimageai';
		}

		if ( ! empty( $robots_directives ) ) {
			echo '<meta name="robots" content="' . esc_attr( implode( ', ', $robots_directives ) ) . '">' . "\n";
		}

		// JSON-LD出力
		if ( ! empty( $settings['enable_jsonld'] ) ) {
			$this->output_jsonld();
		}
	}

	/**
	 * JSON-LDを出力
	 */
	private function output_jsonld() {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url( '/' );

		// CarryPod連携: 静的化URL変換
		$static_site_url = $this->get_static_url( $site_url );

		// WebSite構造化データ
		$website_data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'url'      => esc_url( $static_site_url ),
			'name'     => esc_html( $site_name ),
		);

		echo '<script type="application/ld+json">' . wp_json_encode( $website_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";

		// Organization構造化データ（フロントページのみ）
		if ( is_front_page() ) {
			$organization_data = array(
				'@context' => 'https://schema.org',
				'@type'    => 'Organization',
				'url'      => esc_url( $static_site_url ),
				'name'     => esc_html( $site_name ),
			);

			echo '<script type="application/ld+json">' . wp_json_encode( $organization_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
		}

		// BreadcrumbList構造化データ（投稿・固定ページのみ）
		if ( is_singular() ) {
			$breadcrumb_items = array(
				array(
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => 'ホーム',
					'item'     => esc_url( $static_site_url ),
				),
			);

			$post = get_post();
			if ( $post ) {
				$post_url        = get_permalink( $post );
				$static_post_url = $this->get_static_url( $post_url );

				$breadcrumb_items[] = array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => esc_html( get_the_title( $post ) ),
					'item'     => esc_url( $static_post_url ),
				);
			}

			$breadcrumb_data = array(
				'@context'        => 'https://schema.org',
				'@type'           => 'BreadcrumbList',
				'itemListElement' => $breadcrumb_items,
			);

			echo '<script type="application/ld+json">' . wp_json_encode( $breadcrumb_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
		}
	}

	/**
	 * 静的化URL変換（CarryPod連携）
	 *
	 * @param string $url 変換元のURL
	 * @return string 変換後のURL（CarryPodが無効または設定がない場合は元のURLを返す）
	 */
	private function get_static_url( $url ) {
		// CarryPodが有効かチェック
		if ( ! class_exists( 'CP_Settings' ) ) {
			return $url;
		}

		$cp_settings = CP_Settings::get_instance();
		$cp_config   = $cp_settings->get_settings();
		$base_url    = isset( $cp_config['base_url'] ) ? $cp_config['base_url'] : '';

		// base_urlが設定されていない場合は変換しない
		if ( empty( $base_url ) ) {
			return $url;
		}

		// 動的サイトのURLを静的サイトのURLに置換
		$home_url  = home_url( '/' );
		$static_url = str_replace( $home_url, trailingslashit( $base_url ), $url );

		return $static_url;
	}

	/**
	 * コンテンツ保護のスタイルを追加
	 */
	public function add_protection_styles() {
		$settings = $this->settings_manager->get_settings();
		$styles   = array();

		// テキスト選択禁止
		if ( ! empty( $settings['disable_text_selection'] ) ) {
			$styles[] = 'body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }';
		}

		// 画像ドラッグ禁止
		if ( ! empty( $settings['disable_image_drag'] ) ) {
			$styles[] = 'img { -webkit-user-drag: none; -moz-user-drag: none; -ms-user-drag: none; user-drag: none; pointer-events: none; }';
		}

		// 印刷禁止
		if ( ! empty( $settings['disable_print'] ) ) {
			$styles[] = '@media print { body { display: none !important; } }';
		}

		// スタイルを出力
		if ( ! empty( $styles ) ) {
			echo '<style>' . implode( ' ', $styles ) . '</style>' . "\n";
		}
	}

	/**
	 * コンテンツ保護のスクリプトを追加
	 */
	public function add_protection_scripts() {
		$settings = $this->settings_manager->get_settings();
		$scripts  = array();

		// Seedからランダムジェネレータを初期化
		$seed = isset( $settings['obfuscation_seed'] ) ? $settings['obfuscation_seed'] : '';
		$rng  = $this->create_rng_from_seed( $seed );

		// 右クリック禁止
		if ( ! empty( $settings['disable_right_click'] ) ) {
			$var_name            = $this->generate_var_name( $rng );
			$enc_addEventListener = $this->encode_string( 'addEventListener', $rng );
			$enc_preventDefault  = $this->encode_string( 'preventDefault', $rng );

			$scripts[] = sprintf(
				'document["%s"]("context"+"menu",function(%s){%s["%s"]();return!1});',
				$enc_addEventListener,
				$var_name,
				$var_name,
				$enc_preventDefault
			);
		}

		// デベロッパーツール系キー無効化
		if ( ! empty( $settings['disable_devtools_keys'] ) ) {
			$var1                = $this->generate_var_name( $rng );
			$var2                = $this->generate_var_name( $rng );
			$var3                = $this->generate_var_name( $rng );
			$enc_location        = $this->encode_string( 'location', $rng );
			$enc_hostname        = $this->encode_string( 'hostname', $rng );
			$enc_endsWith        = $this->encode_string( 'endsWith', $rng );
			$enc_test            = $this->encode_string( 'test', $rng );
			$enc_userAgent       = $this->encode_string( 'userAgent', $rng );
			$enc_addEventListener = $this->encode_string( 'addEventListener', $rng );
			$enc_key             = $this->encode_string( 'key', $rng );
			$enc_keyCode         = $this->encode_string( 'keyCode', $rng );
			$enc_preventDefault  = $this->encode_string( 'preventDefault', $rng );
			$enc_ctrlKey         = $this->encode_string( 'ctrlKey', $rng );
			$enc_shiftKey        = $this->encode_string( 'shiftKey', $rng );
			$enc_metaKey         = $this->encode_string( 'metaKey', $rng );
			$enc_altKey          = $this->encode_string( 'altKey', $rng );

			$scripts[] = sprintf(
				'!function(){var %s=window["%s"]["%s"];if(%s["%s"]("."+"local")||%s==="localhost"||%s==="127.0.0.1")return;var %s=/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i["%s"](navigator["%s"])||"ontouchstart"in window||navigator["maxTouchPoints"]>0;document["%s"]("key"+"down",function(%s){if(%s["%s"]==="F"+"12"||%s["%s"]===123){%s["%s"]();return!1}if(/^[IiJjCc]$/["%s"](%s["%s"])){if(%s["%s"]&&%s["%s"]){%s["%s"]();return!1}if(%s["%s"]&&%s["%s"]){%s["%s"]();return!1}}if(/^[Uu]$/["%s"](%s["%s"])){if(%s["%s"]&&!%s["%s"]&&!%s["%s"]){%s["%s"]();return!1}if(%s["%s"]&&!%s["%s"]&&!%s["%s"]){%s["%s"]();return!1}}})}();',
				$var1, $enc_location, $enc_hostname, $var1, $enc_endsWith, $var1, $var1,
				$var2, $enc_test, $enc_userAgent,
				$enc_addEventListener, $var3,
				$var3, $enc_key, $var3, $enc_keyCode,
				$var3, $enc_preventDefault,
				$enc_test, $var3, $enc_key,
				$var3, $enc_ctrlKey, $var3, $enc_shiftKey,
				$var3, $enc_preventDefault,
				$var3, $enc_metaKey, $var3, $enc_altKey,
				$var3, $enc_preventDefault,
				$enc_test, $var3, $enc_key,
				$var3, $enc_ctrlKey, $var3, $enc_shiftKey, $var3, $enc_altKey,
				$var3, $enc_preventDefault,
				$var3, $enc_metaKey, $var3, $enc_shiftKey, $var3, $enc_altKey,
				$var3, $enc_preventDefault
			);
		}

		// サイト保存キー無効化
		if ( ! empty( $settings['disable_save_keys'] ) ) {
			$var_name            = $this->generate_var_name( $rng );
			$enc_addEventListener = $this->encode_string( 'addEventListener', $rng );
			$enc_ctrlKey         = $this->encode_string( 'ctrlKey', $rng );
			$enc_metaKey         = $this->encode_string( 'metaKey', $rng );
			$enc_key             = $this->encode_string( 'key', $rng );
			$enc_preventDefault  = $this->encode_string( 'preventDefault', $rng );

			$scripts[] = sprintf(
				'document["%s"]("key"+"down",function(%s){if((%s["%s"]||%s["%s"])&&(%s["%s"]==="s"||%s["%s"]==="S")){%s["%s"]();return!1}});',
				$enc_addEventListener,
				$var_name,
				$var_name, $enc_ctrlKey, $var_name, $enc_metaKey,
				$var_name, $enc_key, $var_name, $enc_key,
				$var_name, $enc_preventDefault
			);
		}

		// スクリプトを出力（Cloudflare Rocket Loader除外）
		if ( ! empty( $scripts ) ) {
			echo '<script data-cfasync="false">' . implode( ' ', $scripts ) . '</script>' . "\n";
		}
	}

	/**
	 * Seedから再現可能な乱数生成器を作成
	 *
	 * @param string $seed シード文字列
	 * @return int 初期RNG値
	 */
	private function create_rng_from_seed( $seed ) {
		if ( empty( $seed ) ) {
			$seed = 'default_seed';
		}
		return abs( crc32( $seed ) );
	}

	/**
	 * ランダムな変数名を生成
	 *
	 * @param int $rng RNG値（参照渡し）
	 * @return string 変数名
	 */
	private function generate_var_name( &$rng ) {
		$chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$length = 6;
		$name   = '_0x';

		for ( $i = 0; $i < $length; $i++ ) {
			// 線形合同法で疑似乱数生成
			$rng  = ( $rng * 1103515245 + 12345 ) % 2147483648;
			$name .= $chars[ $rng % strlen( $chars ) ];
		}

		return $name;
	}

	/**
	 * 文字列をランダムなエンコード方式でエンコード
	 *
	 * @param string $str エンコードする文字列
	 * @param int    $rng RNG値（参照渡し）
	 * @return string エンコードされた文字列
	 */
	private function encode_string( $str, &$rng ) {
		// エンコード方式をランダム選択（0: hex, 1: unicode）
		$rng    = ( $rng * 1103515245 + 12345 ) % 2147483648;
		$method = $rng % 2;

		$result = '';
		for ( $i = 0; $i < strlen( $str ); $i++ ) {
			$char = $str[ $i ];
			$code = ord( $char );

			switch ( $method ) {
				case 0: // Hex
					$result .= sprintf( '\x%02x', $code );
					break;
				case 1: // Unicode
					$result .= sprintf( '\u%04x', $code );
					break;
			}
		}

		return $result;
	}
}
