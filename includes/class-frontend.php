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

	private ?string $meta_description_tag = null;

	private bool $meta_description_filtered = false;

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

		// 最後に開始した（最も内側の）バッファにして、キャッシュ系より先に処理する
		add_action( 'template_redirect', array( $this, 'start_meta_description_buffer' ), PHP_INT_MAX );

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

		$headers = array_merge( $headers, $this->settings_manager->get_header_sets( $settings )['page'] );

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

		if ( ! empty( $settings['enable_meta_description'] ) ) {
			$this->meta_description_tag = '<meta name="description" content="' . esc_attr( $this->get_meta_description() ) . '">';
			echo $this->meta_description_tag . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
		}

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

		$robots_directives = $this->settings_manager->get_robots_directives( $settings );

		if ( ! empty( $robots_directives ) ) {
			echo '<meta name="robots" content="' . esc_attr( implode( ', ', $robots_directives ) ) . '">' . "\n";
		}

		if ( ! empty( $settings['enable_jsonld'] ) ) {
			$this->output_jsonld();
		}
	}

	/**
	 * 現在のページのメタディスクリプションを決定（空文字は返さない）
	 *
	 * 訪問者の入力値（検索語など）は使わない。
	 */
	private function get_meta_description(): string {
		$candidates = array();
		$queried    = get_queried_object();

		if ( is_front_page() ) {
			// 設定した説明文は本人が書いた文章なので切り詰めない。空ならキャッチフレーズ（下のフォールバック）
			$custom = $this->clean_text( $this->settings_manager->get_settings()['front_page_description'] ?? '' );
			if ( '' !== $custom ) {
				return $custom;
			}
		} elseif ( is_singular() && $queried instanceof WP_Post ) {
			// パスワード保護記事は本文・抜粋を出さない
			if ( '' === $queried->post_password ) {
				$candidates[] = $queried->post_excerpt;
				$candidates[] = $queried->post_content;
			}
			$candidates[] = $queried->post_title;
		} elseif ( ( is_category() || is_tag() || is_tax() ) && $queried instanceof WP_Term ) {
			$candidates[] = $queried->description;
		}

		$candidates[] = get_bloginfo( 'description' );
		$candidates[] = get_bloginfo( 'name' );

		foreach ( $candidates as $text ) {
			$text = $this->clean_meta_description( (string) $text );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	private function clean_meta_description( string $text ): string {
		$text = $this->clean_text( strip_shortcodes( $text ) );

		if ( mb_strlen( $text ) > 120 ) {
			$text = mb_substr( $text, 0, 120 ) . '…';
		}

		return $text;
	}

	/**
	 * タグを除去し、実体参照をデコードして空白を整える
	 *
	 * 切り詰めで実体参照が壊れないよう、また JSON-LD に実体参照が残らないようデコードする。
	 * HTML に出す場合は出力時に esc_attr で再エスケープすること。
	 */
	private function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
	}

	public function start_meta_description_buffer(): void {
		$settings = $this->settings_manager->get_settings();

		if ( ! empty( $settings['enable_meta_description'] ) ) {
			ob_start( array( $this, 'filter_meta_description_buffer' ) );
		}
	}

	/**
	 * head 内の他の description を除去し、Mati のタグを1つだけ残す
	 */
	public function filter_meta_description_buffer( string $html ): string {
		if ( $this->meta_description_filtered || null === $this->meta_description_tag ) {
			return $html;
		}

		$head_end = stripos( $html, '</head>' );
		if ( false === $head_end ) {
			return $html;
		}

		$this->meta_description_filtered = true;

		$kept = false;
		$head = preg_replace_callback(
			'/<meta\s(?:[^>]*\s)?name\s*=\s*(?:"description"|\'description\'|description(?=[\s\/>]))[^>]*>\n?/i',
			function ( array $m ) use ( &$kept ): string {
				if ( ! $kept && rtrim( $m[0] ) === $this->meta_description_tag ) {
					$kept = true;
					return $m[0];
				}
				return '';
			},
			substr( $html, 0, $head_end )
		);

		if ( null === $head ) {
			return $html;
		}

		return $head . substr( $html, $head_end );
	}

	/**
	 * JSON-LD を @graph 1つにまとめて出力（サイト・運営者・記事・パンくずを @id で相互参照）
	 *
	 * AI学習・画像インデックス拒否と矛盾しないよう、画像（ロゴ・アイキャッチ）は出力しない。
	 */
	private function output_jsonld(): void {
		$settings  = $this->settings_manager->get_settings();
		$site_name = $this->clean_text( get_bloginfo( 'name' ) );
		$home      = esc_url_raw( $this->get_static_url( home_url( '/' ) ) );

		$description = '';
		if ( ! empty( $settings['enable_meta_description'] ) ) {
			$description = $this->clean_text( $settings['front_page_description'] ?? '' );
		}
		if ( '' === $description ) {
			$description = $this->clean_text( get_bloginfo( 'description' ) );
		}

		$website = array(
			'@type'      => 'WebSite',
			'@id'        => $home . '#website',
			'url'        => $home,
			'name'       => $site_name,
			'inLanguage' => get_bloginfo( 'language' ),
			'publisher'  => array( '@id' => $home . '#publisher' ),
		);
		if ( '' !== $description ) {
			$website['description'] = $description;
		}

		$publisher_name = $this->clean_text( $settings['jsonld_publisher_name'] ?? '' );
		$publisher      = array(
			'@type' => 'person' === ( $settings['jsonld_publisher_type'] ?? '' ) ? 'Person' : 'Organization',
			'@id'   => $home . '#publisher',
			'name'  => '' !== $publisher_name ? $publisher_name : $site_name,
			'url'   => $home,
		);

		$same_as = array_merge(
			(array) ( $settings['fediverse_profile_urls'] ?? array() ),
			(array) ( $settings['jsonld_publisher_urls'] ?? array() )
		);
		// 保存済みのプロフィールURLは登録時のハンドルのままで、ドメイン認証でハンドルを変えると開けなくなるため DID から組み立てる
		if ( ! empty( $settings['bluesky_did'] ) ) {
			$same_as[] = 'https://bsky.app/profile/' . $settings['bluesky_did'];
		}
		$same_as = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $same_as ) ) ) );
		if ( ! empty( $same_as ) ) {
			$publisher['sameAs'] = $same_as;
		}

		$graph = array( $website, $publisher );

		$post = get_queried_object();
		if ( is_singular() && $post instanceof WP_Post ) {
			$permalink = esc_url_raw( $this->get_static_url( (string) get_permalink( $post ) ) );
			$title     = $this->clean_text( $post->post_title );

			if ( 'post' === $post->post_type ) {
				$graph[] = array(
					'@type'            => 'BlogPosting',
					'@id'              => $permalink . '#article',
					'headline'         => $title,
					'datePublished'    => get_the_date( DATE_W3C, $post ),
					'dateModified'     => get_the_modified_date( DATE_W3C, $post ),
					'mainEntityOfPage' => $permalink,
					'isPartOf'         => array( '@id' => $home . '#website' ),
					'publisher'        => array( '@id' => $home . '#publisher' ),
				);
			}

			$crumbs = array( array( 'ホーム', $home ) );

			$categories = 'post' === $post->post_type ? get_the_category( $post->ID ) : array();
			if ( ! empty( $categories ) ) {
				$crumbs[] = array(
					$this->clean_text( $categories[0]->name ),
					esc_url_raw( $this->get_static_url( get_category_link( $categories[0] ) ) ),
				);
			}

			$crumbs[] = array( $title, $permalink );

			$items = array();
			foreach ( $crumbs as $i => $crumb ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $crumb[0],
					'item'     => $crumb[1],
				);
			}

			$graph[] = array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $permalink . '#breadcrumb',
				'itemListElement' => $items,
			);
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		// JSON_HEX_TAG: 値に含まれる </script> で script 要素を抜けられないようにする
		// JSON_UNESCAPED_SLASHES / JSON_HEX_QUOT: CarryPod の絶対URL変換（\/" や \"/ の置換）に巻き込まれないようにする
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_QUOT ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG で < > をエスケープ済み
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
			$styles[] = 'img { -webkit-user-drag: none !important; -moz-user-drag: none !important; -ms-user-drag: none !important; user-drag: none !important; -webkit-user-select: none !important; -moz-user-select: none !important; -ms-user-select: none !important; user-select: none !important; -webkit-touch-callout: none !important; }';
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

			// モバイル長押し対策: 長押しで発火する contextmenu を画像に限定して抑止する
			$var_cm = $this->generate_var_name( $rng );

			$scripts[] = sprintf(
				'document["%s"]("context"+"menu",function(%s){if(%s["%s"]["%s"]==="IMG"){%s["%s"]();return!1}},!0);',
				$enc_addEventListener,
				$var_cm,
				$var_cm, $enc_target, $enc_tagName,
				$var_cm, $enc_preventDefault
			);
		}

		if ( ! empty( $scripts ) ) {
			echo '<script data-cfasync="false">' . implode( ' ', $scripts ) . '</script>' . "\n";
		}
	}

	/**
	 * 「実機モバイルである」フラグを宣言する。
	 *
	 * 実機モバイルでは検出処理が誤検知しやすいため、このフラグが真のときは
	 * mobile debugger (eruda / vConsole) の検出だけを残して他を抑止する。
	 *
	 * ただし UA とタッチ有無だけで判定すると、デスクトップの端末エミュレーションを
	 * 挟むだけで保護を無効化できてしまう。そこで「モバイル UA を名乗っているが
	 * 実体はデスクトップブラウザ」と分かる矛盾を検出した場合はフラグを立てない。
	 *
	 * - navigator.userAgentData.mobile === false: Chromium がモバイル UA を
	 *   名乗りつつクライアントヒントではデスクトップと申告している状態。
	 * - iOS 系 UA なのに navigator.vendor が Apple 以外: 実機 iOS は WebKit のみで
	 *   vendor は必ず "Apple Computer, Inc." になるため、Blink が iPhone/iPad を
	 *   偽装している状態と判断できる。
	 * - Android UA なのに navigator.platform が Win/Mac: 実機 Android は
	 *   "Linux armv8l" 等を返すため、デスクトップ実体と判断できる。
	 *
	 * これらの矛盾検出はあくまで補助であり、UA を偽装しないレスポンシブ表示などは
	 * 素通りする。UA に依存しない検出は generate_debugger_check() が担う。
	 */
	private function generate_mobile_guard( int &$rng, string $var_mob ): string {
		$var_u  = $this->generate_var_name( $rng );
		$var_ch = $this->generate_var_name( $rng );
		$var_pf = $this->generate_var_name( $rng );

		return sprintf(
			'var %s=function(){var %s=navigator.userAgent||"";if(!/iPhone|iPad|iPod|Android/.test(%s))return!1;if(!("ontouchstart" in window))return!1;var %s=navigator["userAgentData"];if(%s&&%s["mobile"]===!1)return!1;if(/iPhone|iPad|iPod/.test(%s)&&(navigator["vendor"]||"")!=="Apple Computer, Inc.")return!1;var %s=navigator["platform"]||"";if(/Android/.test(%s)&&/^(Win|Mac)/.test(%s))return!1;return!0}();',
			$var_mob, $var_u, $var_u, $var_ch, $var_ch, $var_ch, $var_u, $var_pf, $var_u, $var_pf
		);
	}

	/**
	 * debugger 文の停止時間による DevTools 接続検出を生成する。
	 *
	 * DevTools が接続されている場合のみ debugger 文で実行が停止するため、
	 * 前後の経過時間が閾値を超えたら接続中と判断する。
	 *
	 * UA・タッチ有無・表示中のパネル種別のいずれにも依存しないため、
	 * 端末エミュレーションやレスポンシブ表示を挟んでも回避できない。
	 * DevTools 非接続時は debugger 文が no-op となり経過時間はほぼ 0 になるので、
	 * 実機モバイルを含む通常の閲覧では発火しない。
	 *
	 * @param string $var_block ブロック処理を行う関数の変数名
	 */
	private function generate_debugger_check( int &$rng, string $var_block ): string {
		$var_t = $this->generate_var_name( $rng );

		$enc_now = $this->encode_string( 'now', $rng );

		return sprintf(
			'var %s=Date["%s"]();debugger;if(Date["%s"]()-%s>100){%s();return}',
			$var_t, $enc_now, $enc_now, $var_t, $var_block
		);
	}

	private function generate_devtools_immediate_check( int &$rng, bool $force = false ): string {
		$var_h   = $this->generate_var_name( $rng );
		$var_mob = $this->generate_var_name( $rng );
		$var_arr = $this->generate_var_name( $rng );
		$var_i   = $this->generate_var_name( $rng );
		$var_s   = $this->generate_var_name( $rng );
		$var_t1  = $this->generate_var_name( $rng );
		$var_t2  = $this->generate_var_name( $rng );
		$var_bl  = $this->generate_var_name( $rng );
		$var_div = $this->generate_var_name( $rng );
		$var_b   = $this->generate_var_name( $rng );

		$enc_location       = $this->encode_string( 'location', $rng );
		$enc_hostname       = $this->encode_string( 'hostname', $rng );
		$enc_endsWith       = $this->encode_string( 'endsWith', $rng );
		$enc_createElement  = $this->encode_string( 'createElement', $rng );
		$enc_div            = $this->encode_string( 'div', $rng );
		$enc_defineProperty = $this->encode_string( 'defineProperty', $rng );
		$enc_id             = $this->encode_string( 'id', $rng );
		$enc_get            = $this->encode_string( 'get', $rng );
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

		$mobile_guard   = $this->generate_mobile_guard( $rng, $var_mob );
		$debugger_check = $this->generate_debugger_check( $rng, $var_b );

		return sprintf(
			'!function(){%s%s'
			// ブロック処理は共通の関数にまとめる。getter トラップは console の描画が
			// 非同期に走ってから発火するため、フラグを同期的に読むのではなく
			// 発火時点でこの関数を直接呼ばせる必要がある。
			. 'var %s=function(){var %s=new Blob(["<style>*{margin:0;padding:0}body{min-height:100vh}</style>"],{type:"text/html"});window["%s"]["%s"](URL["%s"](%s))};'
			// 実機モバイルは描画トラップ・タイミング判定ともに誤検知するため実行しない。
			// 代わりに debugger 検出を使う。実機では DevTools 非接続で no-op となり
			// 停止が発生しないので、端末エミュレーション／レスポンシブ表示のみを捕捉できる。
			. 'if(!%s){'
			. 'var %s=document["%s"]("%s");Object["%s"](%s,"%s",{"%s":function(){%s()}});console["%s"](%s);console["%s"]();'
			. 'var %s=[];for(var %s=0;%s<500;%s++)%s.push({a:%s,b:"x".repeat(20)});'
			. 'var %s=Date["%s"]();console["%s"](%s);var %s=Date["%s"]()-%s;'
			. '%s=Date["%s"]();console["%s"](%s);var %s=Date["%s"]()-%s;console["%s"]();'
			. 'if(%s>0&&%s>0&&%s>%s*10){%s();return}'
			. '}else{%s}'
			. 'if(window["%s"]&&window["%s"]["%s"]&&window["%s"]["%s"]["%s"]===!0){%s();return}'
			. 'if(window["%s"]&&document["%s"]("%s")){%s();return}'
			. '}();',
			$localhost_guard,
			$mobile_guard,
			$var_b, $var_bl, $enc_location, $enc_replace, $enc_createObjectURL, $var_bl,
			$var_mob,
			$var_div, $enc_createElement, $enc_div,
			$enc_defineProperty, $var_div, $enc_id, $enc_get, $var_b,
			$enc_log, $var_div, $enc_clear,
			$var_arr, $var_i, $var_i, $var_i, $var_arr, $var_i,
			$var_s, $enc_now, $enc_table, $var_arr, $var_t1, $enc_now, $var_s,
			$var_s, $enc_now, $enc_log, $var_arr, $var_t2, $enc_now, $var_s, $enc_clear,
			$var_t1, $var_t2, $var_t1, $var_t2, $var_b,
			$debugger_check,
			$enc_eruda, $enc_eruda, $enc_devTools, $enc_eruda, $enc_devTools, $enc_isShow, $var_b,
			$enc_vcOrig, $enc_querySelector, $enc_vcSelector, $var_b
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

		$mobile_guard   = $this->generate_mobile_guard( $rng, $var_ua );
		$debugger_check = $this->generate_debugger_check( $rng, $var_f );

		return sprintf(
			'!function(){%s%svar %s="";var %s=function(){if(!%s&&document["%s"])%s=window["%s"](document["%s"])["%s"]||"";var %s=new Blob(["<style>*{margin:0;padding:0}body{min-height:100vh;background:"+%s+"}</style>"],{type:"text/html"});window["%s"]["%s"](URL["%s"](%s))};var %s=new Date();var %s=0;%s["%s"]=function(){%s++;return""};var %s=function(){};var %s=0;%s["%s"]=function(){%s++;return""};var %s=document["%s"]("%s");Object["%s"](%s,"%s",{"%s":function(){%s()}});var %s=[];if(!%s)for(var %s=0;%s<500;%s++)%s.push({a:%s,b:"x".repeat(20)});window["%s"](function(){if(!%s&&document["%s"])%s=window["%s"](document["%s"])["%s"]||"";if(!%s){%s=0;console["%s"](%s);console["%s"]();if(%s>=2){%s();return}%s=0;console["%s"](%s);console["%s"]();if(%s>=2){%s();return}console["%s"](%s);console["%s"]();var %s=Date["%s"]();console["%s"](%s);var %s=Date["%s"]()-%s;%s=Date["%s"]();console["%s"](%s);var %s=Date["%s"]()-%s;console["%s"]();if(%s>0&&%s>0&&%s>%s*10){%s();return}}else{%s}if(window["%s"]&&window["%s"]["%s"]&&window["%s"]["%s"]["%s"]===!0){%s();return}if(window["%s"]&&document["%s"]("%s")){%s();return}},500)}();',
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
			$var_arr, $var_ua, $var_i, $var_i, $var_i, $var_arr, $var_i,
			$enc_setInterval,
			$var_bg, $enc_body, $var_bg, $enc_getComputedStyle, $enc_body, $enc_backgroundColor,
			$var_ua,
			$var_c, $enc_log, $var_d, $enc_clear, $var_c, $var_f,
			$var_c2, $enc_log, $var_fn, $enc_clear, $var_c2, $var_f,
			$enc_log, $var_div, $enc_clear,
			$var_s, $enc_now, $enc_table, $var_arr, $var_t1, $enc_now, $var_s,
			$var_s, $enc_now, $enc_log, $var_arr, $var_t2, $enc_now, $var_s,
			$enc_clear,
			$var_t1, $var_t2, $var_t1, $var_t2, $var_f,
			$debugger_check,
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
