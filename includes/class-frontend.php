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

		// AI学習防止メタタグ
		if ( ! empty( $settings['add_noai_meta'] ) ) {
			echo '<meta name="robots" content="noai, noimageai">' . "\n";
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
			$scripts[] = 'document.addEventListener("contextmenu", function(e) { e.preventDefault(); return false; });';
		}

		// デベロッパーツール系キー無効化
		if ( ! empty( $settings['disable_devtools_keys'] ) ) {
			$scripts[] = 'document.addEventListener("keydown", function(e) {
				if (e.key === "F12" || e.keyCode === 123 ||
				    (e.ctrlKey && e.shiftKey && (e.key === "I" || e.key === "i")) ||
				    (e.ctrlKey && e.shiftKey && (e.key === "J" || e.key === "j")) ||
				    (e.ctrlKey && e.shiftKey && (e.key === "C" || e.key === "c")) ||
				    (e.ctrlKey && (e.key === "U" || e.key === "u")) ||
				    (e.metaKey && e.altKey && (e.key === "I" || e.key === "i")) ||
				    (e.metaKey && e.altKey && (e.key === "J" || e.key === "j")) ||
				    (e.metaKey && e.altKey && (e.key === "C" || e.key === "c")) ||
				    (e.metaKey && (e.key === "U" || e.key === "u"))) {
					e.preventDefault();
					return false;
				}
			});';
		}

		// サイト保存キー無効化
		if ( ! empty( $settings['disable_save_keys'] ) ) {
			$scripts[] = 'document.addEventListener("keydown", function(e) {
				if ((e.ctrlKey || e.metaKey) && (e.key === "s" || e.key === "S")) {
					e.preventDefault();
					return false;
				}
			});';
		}

		// スクリプトを出力
		if ( ! empty( $scripts ) ) {
			echo '<script id="mati-protection-scripts">' . implode( ' ', $scripts ) . '</script>' . "\n";
		}
	}
}
