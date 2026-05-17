<?php
/**
 * GitHub からの自動更新クラス
 *
 * GitHub Releases API を使用してプラグインの更新をチェックし、
 * WordPress の更新システムに統合します。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mati_Updater {

	private string $github_owner = 'villyoshioka';

	private string $github_repo = 'Mati';

	private string $plugin_basename;

	private string $plugin_slug;

	private string $current_version;

	private string $cache_key = 'mati_github_release_cache';

	private int $cache_expiry = 43200; // 12時間

	public function __construct() {
		$this->plugin_basename = plugin_basename( MATI_PLUGIN_DIR . 'mati.php' );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		$this->current_version = MATI_VERSION;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade_complete' ), 10, 2 );
	}

	/**
	 * プラグイン更新完了時にキャッシュをクリア
	 */
	public function on_upgrade_complete( \WP_Upgrader $upgrader, array $options ): void {
		if ( $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
			return;
		}

		$plugins = isset( $options['plugins'] ) ? $options['plugins'] : array();
		if ( ! is_array( $plugins ) ) {
			$plugins = array( $plugins );
		}

		if ( in_array( $this->plugin_basename, $plugins, true ) ) {
			delete_transient( $this->cache_key );
			delete_transient( $this->cache_key . '_beta' );

			$update_plugins = get_site_transient( 'update_plugins' );
			if ( $update_plugins && isset( $update_plugins->response[ $this->plugin_basename ] ) ) {
				unset( $update_plugins->response[ $this->plugin_basename ] );
				set_site_transient( 'update_plugins', $update_plugins );
			}
		}
	}

	/**
	 * 更新をチェック
	 */
	public function check_for_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// WordPressが認識している実際のインストール済みバージョンを使用
		$current_version = isset( $transient->checked[ $this->plugin_basename ] )
			? $transient->checked[ $this->plugin_basename ]
			: $this->current_version;

		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $transient;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( $current_version, $latest_version, '<' ) ) {
			$download_url = $this->get_download_url( $release );

			if ( $download_url ) {
				$transient->response[ $this->plugin_basename ] = (object) array(
					'slug'         => $this->plugin_slug,
					'plugin'       => $this->plugin_basename,
					'new_version'  => $latest_version,
					'url'          => $release['html_url'],
					'package'      => $download_url,
					'icons'        => array(),
					'banners'      => array(),
					'tested'       => '7.0',
					'requires_php' => '8.3',
				);
			}
		} else {
			if ( isset( $transient->response[ $this->plugin_basename ] ) ) {
				unset( $transient->response[ $this->plugin_basename ] );
			}
			if ( ! isset( $transient->no_update[ $this->plugin_basename ] ) ) {
				$transient->no_update[ $this->plugin_basename ] = (object) array(
					'slug'        => $this->plugin_slug,
					'plugin'      => $this->plugin_basename,
					'new_version' => $current_version,
					'url'         => '',
					'package'     => '',
				);
			}
		}

		return $transient;
	}

	/**
	 * プラグイン情報を取得（詳細ポップアップ用）
	 */
	public function plugin_info( false|object|array $result, string $action, object $args ): false|object {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $result;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );
		$download_url   = $this->get_download_url( $release );

		return (object) array(
			'name'              => 'Mati',
			'slug'              => $this->plugin_slug,
			'version'           => $latest_version,
			'author'            => '<a href="https://github.com/villyoshioka">Vill Yoshioka</a>',
			'author_profile'    => 'https://github.com/villyoshioka',
			'homepage'          => 'https://github.com/villyoshioka/Mati',
			'short_description' => 'サイトを悪意から守る。コンテンツ保護・メタタグ管理・SEO設定を簡単に制御できるWordPressプラグイン。',
			'sections'          => array(
				'description' => $this->get_readme_description(),
				'changelog'   => $this->format_changelog( $release['body'] ),
			),
			'download_link'     => $download_url,
			'requires'          => '6.8',
			'tested'            => '7.0',
			'requires_php'      => '8.3',
			'last_updated'      => $release['published_at'],
		);
	}

	/**
	 * GitHub から最新リリース情報を取得
	 *
	 * @return array|false リリース情報または失敗時false
	 */
	private function get_latest_release(): array|false {
		$include_prerelease = $this->is_beta_channel_enabled();

		$cache_key = $include_prerelease ? $this->cache_key . '_beta' : $this->cache_key;

		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		if ( $include_prerelease ) {
			$url = sprintf(
				'https://api.github.com/repos/%s/%s/releases',
				$this->github_owner,
				$this->github_repo
			);
		} else {
			$url = sprintf(
				'https://api.github.com/repos/%s/%s/releases/latest',
				$this->github_owner,
				$this->github_repo
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3, // SSRF対策: リダイレクト回数を3回に制限
				'headers'     => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return false;
		}

		if ( $include_prerelease ) {
			$body = $this->get_latest_from_releases( $body );
		}

		// 必須フィールドの検証
		if ( empty( $body ) || ! is_array( $body ) ) {
			return false;
		}

		$required_fields = array( 'tag_name', 'html_url', 'zipball_url' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $body[ $field ] ) || ! is_string( $body[ $field ] ) ) {
				return false;
			}
		}

		if ( ! preg_match( '/^v?\d+\.\d+(\.\d+)?(-[a-zA-Z0-9.]+)?$/', $body['tag_name'] ) ) {
			return false;
		}

		set_transient( $cache_key, $body, $this->cache_expiry );

		return $body;
	}

	private function is_beta_channel_enabled(): bool {
		return (bool) get_transient( 'mati_beta_channel' );
	}

	/**
	 * リリース一覧から最新のリリースを取得（プレリリース含む）
	 */
	private function get_latest_from_releases( array $releases ): array|false {
		if ( empty( $releases ) ) {
			return false;
		}

		foreach ( $releases as $release ) {
			if ( is_array( $release ) && isset( $release['tag_name'] ) ) {
				return $release;
			}
		}

		return false;
	}

	/**
	 * ダウンロードURLを取得
	 *
	 * @param array $release リリース情報
	 * @return string|false ダウンロードURL
	 */
	private function get_download_url( array $release ): string|false {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && $asset['name'] === 'mati.zip' ) {
					if ( isset( $asset['browser_download_url'] ) ) {
						// SSRF対策: URLがGitHubのドメインであることを検証
						if ( $this->is_valid_github_url( $asset['browser_download_url'] ) ) {
							return $asset['browser_download_url'];
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * URLが正当なGitHub URLかどうかを検証（SSRF対策）
	 */
	private function is_valid_github_url( string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );

		if ( ! isset( $parsed['scheme'] ) || $parsed['scheme'] !== 'https' ) {
			return false;
		}

		if ( ! isset( $parsed['host'] ) ) {
			return false;
		}

		$allowed_hosts = array(
			'api.github.com',
			'github.com',
			'codeload.github.com',
			'objects.githubusercontent.com', // リリースアセットのダウンロード先
		);

		if ( ! in_array( $parsed['host'], $allowed_hosts, true ) ) {
			return false;
		}

		if ( ! isset( $parsed['path'] ) ) {
			return false;
		}

		// objects.githubusercontent.com はリダイレクト先なのでパス検証をスキップ
		if ( $parsed['host'] === 'objects.githubusercontent.com' ) {
			return true;
		}

		$expected_path_part = '/' . $this->github_owner . '/' . $this->github_repo;
		if ( ! str_contains( $parsed['path'], $expected_path_part ) ) {
			return false;
		}

		return true;
	}

	/**
	 * ソースディレクトリ名を修正
	 *
	 * GitHub の zipball は「owner-repo-hash」形式のディレクトリ名になるため、
	 * 正しいプラグインディレクトリ名に修正する
	 */
	public function fix_source_dir( string $source, string $remote_source, \WP_Upgrader $upgrader, array $hook_extra ): string|\WP_Error {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		$source_dirname = basename( untrailingslashit( $source ) );
		if ( $source_dirname === $this->plugin_slug ) {
			return $source;
		}

		$github_pattern = '/^' . preg_quote( $this->github_owner, '/' ) . '-' . preg_quote( $this->github_repo, '/' ) . '-[a-f0-9]+$/i';
		if ( ! preg_match( $github_pattern, $source_dirname ) ) {
			return $source;
		}

		// パストラバーサル対策: ソースパスの検証
		$real_source = realpath( $source );
		$real_remote = realpath( $remote_source );

		if ( $real_source === false || $real_remote === false ) {
			return new WP_Error( 'invalid_path', '無効なパスが検出されました。' );
		}

		if ( ! str_starts_with( $real_source, $real_remote ) ) {
			return new WP_Error( 'path_traversal', 'パストラバーサルが検出されました。' );
		}

		$correct_dir = trailingslashit( $remote_source ) . $this->plugin_slug;

		// Null バイトチェック
		if ( str_contains( $correct_dir, "\0" ) ) {
			return new WP_Error( 'null_byte', '無効な文字が含まれています。' );
		}

		if ( $wp_filesystem->exists( $correct_dir ) ) {
			$wp_filesystem->delete( $correct_dir, true );
		}

		if ( $wp_filesystem->move( $source, $correct_dir ) ) {
			return trailingslashit( $correct_dir );
		}

		return new WP_Error( 'rename_failed', 'プラグインディレクトリ名の変更に失敗しました。' );
	}

	private function get_readme_description(): string {
		return 'Matiは、WordPressサイトを悪意のある行為から守るためのプラグインです。' .
			   'コンテンツ保護機能、不要なメタタグの非表示、SEO設定を簡単に管理できます。';
	}

	private function format_changelog( string $body ): string {
		if ( empty( $body ) ) {
			return '<p>変更履歴はありません。</p>';
		}

		$html = esc_html( $body );
		$html = nl2br( $html );
		$html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.+<\/li>\s*)+/', '<ul>$0</ul>', $html );

		return $html;
	}

	public function clear_cache(): bool {
		// 認可チェック: 管理者のみ実行可能
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		delete_transient( $this->cache_key );
		return true;
	}
}
