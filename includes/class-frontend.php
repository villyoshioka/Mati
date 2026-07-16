<?php
/**
 * フロントエンド出力クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( false ) {
	class CP_Settings {
		public static function get_instance(): self { return new self(); }
		/** @return array<string, mixed> */
		public function get_settings(): array { return []; }
	}
}

class Mati_Frontend {

	private static ?self $instance = null;

	private Mati_Settings $settings_manager;

	public static function get_instance(): static {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings_manager = Mati_Settings::get_instance();

		$this->init_meta_removal_hooks();

		add_action( 'wp_head', array( $this, 'add_seo_meta_tags' ), 1 );

		add_action( 'init', array( $this, 'handle_atproto_did_request' ) );

		add_filter( 'wp_headers', array( $this, 'add_security_headers' ), 10 );

		add_action( 'wp_head', array( $this, 'add_protection_styles' ), 1 );
		add_action( 'wp_head', array( $this, 'add_protection_scripts' ), 1 );
	}

	private function init_meta_removal_hooks(): void {
		$settings = $this->settings_manager->get_settings();

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

	public function remove_pingback_header( array $headers ): array {
		if ( isset( $headers['X-Pingback'] ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	public function handle_atproto_did_request(): void {
		$settings = $this->settings_manager->get_settings();
		$did      = $settings['bluesky_did'] ?? '';

		if ( empty( $did ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( $path !== '/.well-known/atproto-did' ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: inline' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		echo $did; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DID is sanitized on save
		exit;
	}

	public function add_security_headers( array $headers ): array {
		$settings = $this->settings_manager->get_settings();

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

		$frame_ancestors = "'self'";
		$custom_domains  = $settings['frame_ancestors_domains'] ?? '';
		if ( ! empty( $custom_domains ) ) {
			$domains = array_filter( array_map( 'trim', explode( "\n", $custom_domains ) ) );
			if ( ! empty( $domains ) ) {
				$frame_ancestors .= ' ' . implode( ' ', $domains );
			}
		}

		if ( isset( $headers['Content-Security-Policy'] ) ) {
			$csp = $headers['Content-Security-Policy'];
			if ( ! str_contains( $csp, 'frame-ancestors' ) ) {
				$headers['Content-Security-Policy'] = $csp . '; frame-ancestors ' . $frame_ancestors;
			}
		} else {
			$headers['Content-Security-Policy'] = 'frame-ancestors ' . $frame_ancestors;
		}

		$headers['X-Content-Type-Options'] = 'nosniff';

		return $headers;
	}

	public function add_seo_meta_tags(): void {
		$settings = $this->settings_manager->get_settings();

		if ( ! empty( $settings['google_analytics_id'] ) ) {
			$ga_id = esc_attr( $settings['google_analytics_id'] );
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $ga_id . '"></script>' . "\n";
			echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','" . $ga_id . "')</script>\n";
		}

		if ( ! empty( $settings['google_verification'] ) ) {
			echo '<meta name="google-site-verification" content="' . esc_attr( $settings['google_verification'] ) . '">' . "\n";
		}

		if ( ! empty( $settings['bing_verification'] ) ) {
			echo '<meta name="msvalidate.01" content="' . esc_attr( $settings['bing_verification'] ) . '">' . "\n";
		}

		if ( ! empty( $settings['fediverse_profile_urls'] ) && is_array( $settings['fediverse_profile_urls'] ) ) {
			foreach ( $settings['fediverse_profile_urls'] as $url ) {
				if ( ! empty( $url ) ) {
					echo '<link rel="me" href="' . esc_url( $url ) . '" />' . "\n";
				}
			}
		}

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

		if ( ! empty( $settings['enable_jsonld'] ) ) {
			$this->output_jsonld();
		}
	}

	private function output_jsonld(): void {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url( '/' );

		$static_site_url = $this->get_static_url( $site_url );

		$website_data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'url'      => esc_url( $static_site_url ),
			'name'     => esc_html( $site_name ),
		);

		echo '<script type="application/ld+json">' . wp_json_encode( $website_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";

		if ( is_front_page() ) {
			$organization_data = array(
				'@context' => 'https://schema.org',
				'@type'    => 'Organization',
				'url'      => esc_url( $static_site_url ),
				'name'     => esc_html( $site_name ),
			);

			echo '<script type="application/ld+json">' . wp_json_encode( $organization_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
		}

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
	private function get_static_url( string $url ): string {
		if ( ! class_exists( 'CP_Settings' ) ) {
			return $url;
		}

		$cp_settings = CP_Settings::get_instance();
		$cp_config   = $cp_settings->get_settings();
		$base_url    = $cp_config['base_url'] ?? '';

		if ( empty( $base_url ) ) {
			return $url;
		}

		$home_url   = home_url( '/' );
		$static_url = str_replace( $home_url, trailingslashit( $base_url ), $url );

		return $static_url;
	}

	public function add_protection_styles(): void {
		$settings = $this->settings_manager->get_settings();
		$styles   = array();

		if ( $this->should_disable_text_selection( $settings ) ) {
			$styles[] = 'body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }';
		}

		if ( ! empty( $settings['disable_image_drag'] ) ) {
			$styles[] = 'img { -webkit-user-drag: none !important; -moz-user-drag: none !important; -ms-user-drag: none !important; user-drag: none !important; -webkit-user-select: none !important; -moz-user-select: none !important; -ms-user-select: none !important; user-select: none !important; }';
		}

		if ( ! empty( $settings['disable_print'] ) ) {
			$styles[] = '@media print { body { display: none !important; } }';
		}

		if ( ! empty( $styles ) ) {
			echo '<style>' . implode( ' ', $styles ) . '</style>' . "\n";
		}
	}

	/**
	 * テキスト選択禁止を現在のページに適用すべきか判定
	 *
	 * カテゴリー未指定時は disable_text_selection の値そのまま（従来動作）。
	 * 指定ありの場合、ONなら指定カテゴリーを制限から除外し、
	 * OFFなら指定カテゴリーのみ制限する。
	 */
	private function should_disable_text_selection( array $settings ): bool {
		$enabled      = ! empty( $settings['disable_text_selection'] );
		$category_ids = array_map( 'intval', (array) ( $settings['text_selection_categories'] ?? array() ) );
		$category_ids = array_filter( $category_ids );

		if ( empty( $category_ids ) ) {
			return $enabled;
		}

		$matches = ( is_singular() && has_category( $category_ids ) ) || is_category( $category_ids );

		return $enabled ? ! $matches : $matches;
	}

	public function add_protection_scripts(): void {
		$settings = $this->settings_manager->get_settings();
		$scripts  = array();

		$seed = $settings['obfuscation_seed'] ?? '';
		$rng  = $this->create_rng_from_seed( $seed );

		if ( ! empty( $settings['disable_devtools_keys'] ) ) {
			$force = (bool) get_transient( 'mati_force_protection' );
			$scripts[] = $this->generate_devtools_immediate_check( $rng, $force );
		}

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

		if ( ! empty( $settings['disable_devtools_keys'] ) ) {
			$force     = (bool) get_transient( 'mati_force_protection' );
			$var_h     = $this->generate_var_name( $rng );
			$var_m     = $this->generate_var_name( $rng );
			$var_e     = $this->generate_var_name( $rng );
			$var_k     = $this->generate_var_name( $rng );
			$enc_location        = $this->encode_string( 'location', $rng );
			$enc_hostname        = $this->encode_string( 'hostname', $rng );
			$enc_endsWith        = $this->encode_string( 'endsWith', $rng );
			$enc_test            = $this->encode_string( 'test', $rng );
			$enc_platform        = $this->encode_string( 'platform', $rng );
			$enc_userAgent       = $this->encode_string( 'userAgent', $rng );
			$enc_addEventListener = $this->encode_string( 'addEventListener', $rng );
			$enc_keyCode         = $this->encode_string( 'keyCode', $rng );
			$enc_which           = $this->encode_string( 'which', $rng );
			$enc_preventDefault  = $this->encode_string( 'preventDefault', $rng );
			$enc_ctrlKey         = $this->encode_string( 'ctrlKey', $rng );
			$enc_shiftKey        = $this->encode_string( 'shiftKey', $rng );
			$enc_metaKey         = $this->encode_string( 'metaKey', $rng );
			$enc_altKey          = $this->encode_string( 'altKey', $rng );

			$localhost_guard = $force ? '' : sprintf(
				'var %s=window["%s"]["%s"];if(%s["%s"]("."+"local")||%s==="localhost"||%s==="127.0.0.1")return;',
				$var_h, $enc_location, $enc_hostname, $var_h, $enc_endsWith, $var_h, $var_h
			);

			$scripts[] = sprintf(
				'!function(){%svar %s=/Mac|iPod|iPhone|iPad/["%s"](navigator["%s"]||navigator["%s"]);document["%s"]("key"+"down",function(%s){var %s=%s["%s"]||%s["%s"];if(%s===123){%s["%s"]();return!1}if(%s===73||%s===74||%s===67){if(%s?(%s["%s"]&&%s["%s"]):(%s["%s"]&&%s["%s"])){%s["%s"]();return!1}}if(%s===85){if(%s?%s["%s"]:(%s["%s"])){%s["%s"]();return!1}}},!0)}();',
				$localhost_guard,
				$var_m, $enc_test, $enc_platform, $enc_userAgent,
				$enc_addEventListener, $var_e,
				$var_k, $var_e, $enc_keyCode, $var_e, $enc_which,
				$var_k, $var_e, $enc_preventDefault,
				$var_k, $var_k, $var_k,
				$var_m, $var_e, $enc_metaKey, $var_e, $enc_altKey,
				$var_e, $enc_ctrlKey, $var_e, $enc_shiftKey,
				$var_e, $enc_preventDefault,
				$var_k,
				$var_m, $var_e, $enc_metaKey,
				$var_e, $enc_ctrlKey,
				$var_e, $enc_preventDefault
			);
			$scripts[] = $this->generate_devtools_detect_script( $rng, $force );
		}

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

		if ( ! empty( $settings['disable_image_drag'] ) ) {
			$var_name            = $this->generate_var_name( $rng );
			$enc_addEventListener = $this->encode_string( 'addEventListener', $rng );
			$enc_preventDefault  = $this->encode_string( 'preventDefault', $rng );
			$enc_target          = $this->encode_string( 'target', $rng );
			$enc_tagName         = $this->encode_string( 'tagName', $rng );

			$scripts[] = sprintf(
				'document["%s"]("drag"+"start",function(%s){if(%s["%s"]["%s"]==="IMG"){%s["%s"]();return!1}},!0);',
				$enc_addEventListener,
				$var_name,
				$var_name, $enc_target, $enc_tagName,
				$var_name, $enc_preventDefault
			);
		}

		if ( ! empty( $scripts ) ) {
			echo '<script data-cfasync="false">' . implode( ' ', $scripts ) . '</script>' . "\n";
		}
	}

	private function generate_mobile_guard( int &$rng, string $var_ua ): string {
		return sprintf(
			'var %s=navigator.userAgent||"";if(/iPhone|iPad|iPod|Android/.test(%s)&&("ontouchstart" in window))return;',
			$var_ua, $var_ua
		);
	}

	private function generate_devtools_immediate_check( int &$rng, bool $force = false ): string {
		$var_h   = $this->generate_var_name( $rng );
		$var_ua  = $this->generate_var_name( $rng );
		$var_arr = $this->generate_var_name( $rng );
		$var_i   = $this->generate_var_name( $rng );
		$var_s   = $this->generate_var_name( $rng );
		$var_t1  = $this->generate_var_name( $rng );
		$var_t2  = $this->generate_var_name( $rng );
		$var_bl  = $this->generate_var_name( $rng );

		$enc_location       = $this->encode_string( 'location', $rng );
		$enc_hostname       = $this->encode_string( 'hostname', $rng );
		$enc_endsWith       = $this->encode_string( 'endsWith', $rng );
		$enc_now            = $this->encode_string( 'now', $rng );
		$enc_table          = $this->encode_string( 'table', $rng );
		$enc_log            = $this->encode_string( 'log', $rng );
		$enc_clear          = $this->encode_string( 'clear', $rng );
		$enc_replace        = $this->encode_string( 'replace', $rng );
		$enc_createObjectURL = $this->encode_string( 'createObjectURL', $rng );
		$enc_eruda          = $this->encode_string( 'eruda', $rng );
		$enc_devTools       = $this->encode_string( '_devTools', $rng );
		$enc_isShow         = $this->encode_string( '_isShow', $rng );
		$enc_vcOrig         = $this->encode_string( '_vcOrigConsole', $rng );
		$enc_querySelector  = $this->encode_string( 'querySelector', $rng );
		$enc_vcSelector     = $this->encode_string( '#__vconsole.vc-toggle', $rng );

		$localhost_guard = $force ? '' : sprintf(
			'var %s=window["%s"]["%s"];if(%s["%s"]("."+"local")||%s==="localhost"||%s==="127.0.0.1")return;',
			$var_h, $enc_location, $enc_hostname, $var_h, $enc_endsWith, $var_h, $var_h
		);

		$mobile_guard = $this->generate_mobile_guard( $rng, $var_ua );

		return sprintf(
			'!function(){%s%svar %s=[];for(var %s=0;%s<500;%s++)%s.push({a:%s,b:"x".repeat(20)});var %s=Date["%s"]();console["%s"](%s);var %s=Date["%s"]()-%s;%s=Date["%s"]();console["%s"](%s);var %s=Date["%s"]()-%s;console["%s"]();if(%s>0&&%s>0&&%s>%s*10){var %s=new Blob(["<style>*{margin:0;padding:0}body{min-height:100vh}</style>"],{type:"text/html"});window["%s"]["%s"](URL["%s"](%s));return}if(window["%s"]&&window["%s"]["%s"]&&window["%s"]["%s"]["%s"]===!0){var %s=new Blob(["<style>*{margin:0;padding:0}body{min-height:100vh}</style>"],{type:"text/html"});window["%s"]["%s"](URL["%s"](%s));return}if(window["%s"]&&document["%s"]("%s")){var %s=new Blob(["<style>*{margin:0;padding:0}body{min-height:100vh}</style>"],{type:"text/html"});window["%s"]["%s"](URL["%s"](%s));return}}();',
			$localhost_guard,
			$mobile_guard,
			$var_arr, $var_i, $var_i, $var_i, $var_arr, $var_i,
			$var_s, $enc_now, $enc_table, $var_arr, $var_t1, $enc_now, $var_s,
			$var_s, $enc_now, $enc_log, $var_arr, $var_t2, $enc_now, $var_s,
			$enc_clear,
			$var_t1, $var_t2, $var_t1, $var_t2,
			$var_bl, $enc_location, $enc_replace, $enc_createObjectURL, $var_bl,
			$enc_eruda, $enc_eruda, $enc_devTools, $enc_eruda, $enc_devTools, $enc_isShow,
			$var_bl, $enc_location, $enc_replace, $enc_createObjectURL, $var_bl,
			$enc_vcOrig, $enc_querySelector, $enc_vcSelector,
			$var_bl, $enc_location, $enc_replace, $enc_createObjectURL, $var_bl
		);
	}

	/**
	 *
	 * @param int
	 * @return string 難読化された検出スクリプト
	 */
	private function generate_devtools_detect_script( int &$rng, bool $force = false ): string {
		$var_f      = $this->generate_var_name( $rng );
		$var_d      = $this->generate_var_name( $rng );
		$var_c      = $this->generate_var_name( $rng );
		$var_fn     = $this->generate_var_name( $rng );
		$var_c2     = $this->generate_var_name( $rng );
		$var_div    = $this->generate_var_name( $rng );
		$var_arr    = $this->generate_var_name( $rng );
		$var_mx     = $this->generate_var_name( $rng );
		$var_t1     = $this->generate_var_name( $rng );
		$var_t2     = $this->generate_var_name( $rng );
		$var_s      = $this->generate_var_name( $rng );
		$var_i      = $this->generate_var_name( $rng );
		$var_h      = $this->generate_var_name( $rng );
		$var_bg     = $this->generate_var_name( $rng );
		$var_bl     = $this->generate_var_name( $rng );
		$var_ua     = $this->generate_var_name( $rng );

		$enc_location        = $this->encode_string( 'location', $rng );
		$enc_hostname        = $this->encode_string( 'hostname', $rng );
		$enc_endsWith        = $this->encode_string( 'endsWith', $rng );
		$enc_log             = $this->encode_string( 'log', $rng );
		$enc_clear           = $this->encode_string( 'clear', $rng );
		$enc_table           = $this->encode_string( 'table', $rng );
		$enc_toString        = $this->encode_string( 'toString', $rng );
		$enc_createElement   = $this->encode_string( 'createElement', $rng );
		$enc_div             = $this->encode_string( 'div', $rng );
		$enc_defineProperty  = $this->encode_string( 'defineProperty', $rng );
		$enc_id              = $this->encode_string( 'id', $rng );
		$enc_get             = $this->encode_string( 'get', $rng );
		$enc_now             = $this->encode_string( 'now', $rng );
		$enc_setInterval     = $this->encode_string( 'setInterval', $rng );
		$enc_body            = $this->encode_string( 'body', $rng );
		$enc_getComputedStyle  = $this->encode_string( 'getComputedStyle', $rng );
		$enc_backgroundColor   = $this->encode_string( 'backgroundColor', $rng );
		$enc_replace         = $this->encode_string( 'replace', $rng );
		$enc_createObjectURL = $this->encode_string( 'createObjectURL', $rng );
		$enc_eruda           = $this->encode_string( 'eruda', $rng );
		$enc_devTools        = $this->encode_string( '_devTools', $rng );
		$enc_isShow          = $this->encode_string( '_isShow', $rng );
		$enc_vcOrig          = $this->encode_string( '_vcOrigConsole', $rng );
		$enc_querySelector   = $this->encode_string( 'querySelector', $rng );
		$enc_vcSelector      = $this->encode_string( '#__vconsole.vc-toggle', $rng );

		$localhost_guard = $force ? '' : sprintf(
			'var %s=window["%s"]["%s"];if(%s["%s"]("."+"local")||%s==="localhost"||%s==="127.0.0.1")return;',
			$var_h, $enc_location, $enc_hostname, $var_h, $enc_endsWith, $var_h, $var_h
		);

		$mobile_guard = $this->generate_mobile_guard( $rng, $var_ua );

		return sprintf(
			'!function(){%s%svar %s="";var %s=function(){if(!%s&&document["%s"])%s=window["%s"](document["%s"])["%s"]||"";var %s=new Blob(["<style>*{margin:0;padding:0}body{min-height:100vh;background:"+%s+"}</style>"],{type:"text/html"});window["%s"]["%s"](URL["%s"](%s))};var %s=new Date();var %s=0;%s["%s"]=function(){%s++;return""};var %s=function(){};var %s=0;%s["%s"]=function(){%s++;return""};var %s=document["%s"]("%s");Object["%s"](%s,"%s",{"%s":function(){%s()}});var %s=[];for(var %s=0;%s<500;%s++)%s.push({a:%s,b:"x".repeat(20)});var %s=0;window["%s"](function(){if(!%s&&document["%s"])%s=window["%s"](document["%s"])["%s"]||"";%s=0;console["%s"](%s);console["%s"]();if(%s>=2){%s();return}%s=0;console["%s"](%s);console["%s"]();if(%s>=2){%s();return}console["%s"](%s);console["%s"]();var %s=Date["%s"]();console["%s"](%s);var %s=Date["%s"]()-%s;%s=Date["%s"]();console["%s"](%s);var %s=Date["%s"]()-%s;console["%s"]();%s=Math.max(%s,%s);if(%s>0&&%s>0&&%s>%s*10){%s();return}if(window["%s"]&&window["%s"]["%s"]&&window["%s"]["%s"]["%s"]===!0){%s();return}if(window["%s"]&&document["%s"]("%s")){%s();return}},500)}();',
			$localhost_guard,
			$mobile_guard,
			$var_bg,
			$var_f,
			$var_bg, $enc_body, $var_bg, $enc_getComputedStyle, $enc_body, $enc_backgroundColor,
			$var_bl, $var_bg, $enc_location, $enc_replace, $enc_createObjectURL, $var_bl,
			$var_d, $var_c,
			$var_d, $enc_toString, $var_c,
			$var_fn, $var_c2,
			$var_fn, $enc_toString, $var_c2,
			$var_div, $enc_createElement, $enc_div,
			$enc_defineProperty, $var_div, $enc_id, $enc_get, $var_f,
			$var_arr, $var_i, $var_i, $var_i, $var_arr, $var_i,
			$var_mx,
			$enc_setInterval,
			$var_bg, $enc_body, $var_bg, $enc_getComputedStyle, $enc_body, $enc_backgroundColor,
			$var_c, $enc_log, $var_d, $enc_clear, $var_c, $var_f,
			$var_c2, $enc_log, $var_fn, $enc_clear, $var_c2, $var_f,
			$enc_log, $var_div, $enc_clear,
			$var_s, $enc_now, $enc_table, $var_arr, $var_t1, $enc_now, $var_s,
			$var_s, $enc_now, $enc_log, $var_arr, $var_t2, $enc_now, $var_s,
			$enc_clear, $var_mx, $var_mx, $var_t2,
			$var_t1, $var_mx, $var_t1, $var_mx, $var_f,
			$enc_eruda, $enc_eruda, $enc_devTools, $enc_eruda, $enc_devTools, $enc_isShow, $var_f,
			$enc_vcOrig, $enc_querySelector, $enc_vcSelector, $var_f
		);
	}

	/**
	 * Seedから再現可能な乱数生成器を作成
	 *
	 * @param string $seed シード文字列
	 * @return int 初期RNG値
	 */
	private function create_rng_from_seed( string $seed ): int {
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
	private function generate_var_name( int &$rng ): string {
		$chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$length = 6;
		$name   = '_0x';

		for ( $i = 0; $i < $length; $i++ ) {
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
	private function encode_string( string $str, int &$rng ): string {
		$rng    = ( $rng * 1103515245 + 12345 ) % 2147483648;
		$method = $rng % 2;

		$result = '';
		for ( $i = 0; $i < strlen( $str ); $i++ ) {
			$char = $str[ $i ];
			$code = ord( $char );

			switch ( $method ) {
				case 0:
					$result .= sprintf( '\x%02x', $code );
					break;
				case 1:
					$result .= sprintf( '\u%04x', $code );
					break;
			}
		}

		return $result;
	}
}
