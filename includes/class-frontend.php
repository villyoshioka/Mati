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

		// コンテンツ保護のフック
		add_action( 'wp_head', array( $this, 'add_protection_styles' ), 999 );
		add_action( 'wp_footer', array( $this, 'add_protection_scripts' ), 999 );
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

		// WebSite構造化データ
		$website_data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'url'      => esc_url( $site_url ),
			'name'     => esc_html( $site_name ),
		);

		echo '<script type="application/ld+json">' . wp_json_encode( $website_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";

		// Organization構造化データ（フロントページのみ）
		if ( is_front_page() ) {
			$organization_data = array(
				'@context' => 'https://schema.org',
				'@type'    => 'Organization',
				'url'      => esc_url( $site_url ),
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
					'item'     => esc_url( $site_url ),
				),
			);

			$post = get_post();
			if ( $post ) {
				$breadcrumb_items[] = array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => esc_html( get_the_title( $post ) ),
					'item'     => esc_url( get_permalink( $post ) ),
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
			echo '<style id="mati-protection-styles">' . implode( ' ', $styles ) . '</style>' . "\n";
		}
	}

	/**
	 * コンテンツ保護のスクリプトを追加
	 */
	public function add_protection_scripts() {
		$settings = $this->settings_manager->get_settings();
		$scripts  = array();

		// 右クリック禁止
		if ( ! empty( $settings['disable_right_click'] ) ) {
			$scripts[] = 'document["\x61\x64\x64\x45\x76\x65\x6e\x74\x4c\x69\x73\x74\x65\x6e\x65\x72"]("context"+"menu",function(_0x7g8h){_0x7g8h["\x70\x72\x65\x76\x65\x6e\x74\x44\x65\x66\x61\x75\x6c\x74"]();return!1});';
		}

		// デベロッパーツール系キー無効化
		if ( ! empty( $settings['disable_devtools_keys'] ) ) {
			$scripts[] = '!function(){var _0x1a2b=window["\x6c\x6f\x63\x61\x74\x69\x6f\x6e"]["\x68\x6f\x73\x74\x6e\x61\x6d\x65"];if(_0x1a2b["\x65\x6e\x64\x73\x57\x69\x74\x68"]("."+"\x6c\x6f\x63\x61\x6c")||_0x1a2b==="\x6c\x6f\x63\x61\x6c\x68\x6f\x73\x74"||_0x1a2b==="127.0.0.1")return;var _0x3c4d=/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i["\x74\x65\x73\x74"](navigator["\x75\x73\x65\x72\x41\x67\x65\x6e\x74"])||"\x6f\x6e\x74\x6f\x75\x63\x68\x73\x74\x61\x72\x74"in window||navigator["\x6d\x61\x78\x54\x6f\x75\x63\x68\x50\x6f\x69\x6e\x74\x73"]>0;document["\x61\x64\x64\x45\x76\x65\x6e\x74\x4c\x69\x73\x74\x65\x6e\x65\x72"]("key"+"down",function(_0x5e6f){if(_0x5e6f["\x6b\x65\x79"]==="F"+"12"||_0x5e6f["\x6b\x65\x79\x43\x6f\x64\x65"]===123){_0x5e6f["\x70\x72\x65\x76\x65\x6e\x74\x44\x65\x66\x61\x75\x6c\x74"]();_0x5e6f["\x73\x74\x6f\x70\x50\x72\x6f\x70\x61\x67\x61\x74\x69\x6f\x6e"]();return!1}if(/^[IiJjCc]$/["\x74\x65\x73\x74"](_0x5e6f["\x6b\x65\x79"])){if(_0x5e6f["\x63\x74\x72\x6c\x4b\x65\x79"]&&_0x5e6f["\x73\x68\x69\x66\x74\x4b\x65\x79"]){_0x5e6f["\x70\x72\x65\x76\x65\x6e\x74\x44\x65\x66\x61\x75\x6c\x74"]();_0x5e6f["\x73\x74\x6f\x70\x50\x72\x6f\x70\x61\x67\x61\x74\x69\x6f\x6e"]();return!1}if(_0x5e6f["\x6d\x65\x74\x61\x4b\x65\x79"]&&_0x5e6f["\x61\x6c\x74\x4b\x65\x79"]){_0x5e6f["\x70\x72\x65\x76\x65\x6e\x74\x44\x65\x66\x61\x75\x6c\x74"]();_0x5e6f["\x73\x74\x6f\x70\x50\x72\x6f\x70\x61\x67\x61\x74\x69\x6f\x6e"]();return!1}}if(/^[Uu]$/["\x74\x65\x73\x74"](_0x5e6f["\x6b\x65\x79"])){if(_0x5e6f["\x63\x74\x72\x6c\x4b\x65\x79"]&&!_0x5e6f["\x73\x68\x69\x66\x74\x4b\x65\x79"]&&!_0x5e6f["\x61\x6c\x74\x4b\x65\x79"]){_0x5e6f["\x70\x72\x65\x76\x65\x6e\x74\x44\x65\x66\x61\x75\x6c\x74"]();_0x5e6f["\x73\x74\x6f\x70\x50\x72\x6f\x70\x61\x67\x61\x74\x69\x6f\x6e"]();return!1}if(_0x5e6f["\x6d\x65\x74\x61\x4b\x65\x79"]&&!_0x5e6f["\x73\x68\x69\x66\x74\x4b\x65\x79"]&&!_0x5e6f["\x61\x6c\x74\x4b\x65\x79"]){_0x5e6f["\x70\x72\x65\x76\x65\x6e\x74\x44\x65\x66\x61\x75\x6c\x74"]();_0x5e6f["\x73\x74\x6f\x70\x50\x72\x6f\x70\x61\x67\x61\x74\x69\x6f\x6e"]();return!1}}},!0)}();';
		}

		// サイト保存キー無効化
		if ( ! empty( $settings['disable_save_keys'] ) ) {
			$scripts[] = 'document["\x61\x64\x64\x45\x76\x65\x6e\x74\x4c\x69\x73\x74\x65\x6e\x65\x72"]("key"+"down",function(_0x9i0j){if((_0x9i0j["\x63\x74\x72\x6c\x4b\x65\x79"]||_0x9i0j["\x6d\x65\x74\x61\x4b\x65\x79"])&&(_0x9i0j["\x6b\x65\x79"]==="s"||_0x9i0j["\x6b\x65\x79"]==="S")){_0x9i0j["\x70\x72\x65\x76\x65\x6e\x74\x44\x65\x66\x61\x75\x6c\x74"]();return!1}});';
		}

		// スクリプトを出力
		if ( ! empty( $scripts ) ) {
			echo '<script id="mati-protection-scripts">' . implode( ' ', $scripts ) . '</script>' . "\n";
		}
	}
}
