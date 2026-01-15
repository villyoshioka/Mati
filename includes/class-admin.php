<?php
/**
 * 管理画面クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mati_Admin {

	/**
	 * シングルトンインスタンス
	 */
	private static $instance = null;

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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Ajax処理
		add_action( 'wp_ajax_mati_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_mati_reset_settings', array( $this, 'ajax_reset_settings' ) );
		add_action( 'wp_ajax_mati_export_settings', array( $this, 'ajax_export_settings' ) );
		add_action( 'wp_ajax_mati_import_settings', array( $this, 'ajax_import_settings' ) );
	}

	/**
	 * 管理メニューを追加
	 */
	public function add_admin_menu() {
		add_menu_page(
			'Mati',
			'Mati',
			'manage_options',
			'mati',
			array( $this, 'render_settings_page' ),
			'dashicons-shield',
			79 // 設定の上に表示
		);
	}

	/**
	 * スクリプトとスタイルを読み込み
	 */
	public function enqueue_scripts( $hook ) {
		// Matiの設定ページでのみ読み込む
		if ( $hook !== 'toplevel_page_mati' ) {
			return;
		}

		wp_enqueue_style(
			'mati-admin-css',
			MATI_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MATI_VERSION
		);

		wp_enqueue_script(
			'mati-admin-js',
			MATI_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			MATI_VERSION,
			true
		);

		// JavaScriptに渡すデータ
		wp_localize_script( 'mati-admin-js', 'matiData', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'mati_nonce' ),
		) );
	}

	/**
	 * 設定画面を表示
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '設定変更の権限がありません。' );
		}

		$settings_manager = Mati_Settings::get_instance();
		$settings         = $settings_manager->get_settings();

		// ベータモードのURLパラメータ処理
		$beta_message = '';
		if ( isset( $_GET['mati_beta'] ) ) {
			$beta_param = sanitize_text_field( wp_unslash( $_GET['mati_beta'] ) );
			if ( $beta_param === 'on' ) {
				// 既にベータモードが有効な場合はスキップ
				if ( $settings_manager->is_beta_mode_enabled() ) {
					// 既に有効、何もしない
				} elseif ( isset( $_POST['mati_beta_password'] ) && isset( $_POST['mati_beta_nonce'] ) ) {
					// パスワード認証処理
					if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mati_beta_nonce'] ) ), 'mati_beta_auth' ) ) {
						$password = sanitize_text_field( wp_unslash( $_POST['mati_beta_password'] ) );
						$result   = $settings_manager->enable_beta_mode( $password );
						if ( is_wp_error( $result ) ) {
							$beta_message = 'rate_limit';
						} elseif ( $result === true ) {
							$beta_message = 'activated';
						} else {
							$beta_message = 'wrong_password';
						}
					}
				} else {
					$beta_message = 'need_password';
				}
			} elseif ( $beta_param === 'off' ) {
				$settings_manager->disable_beta_mode();
				$beta_message = 'deactivated';
			}
		}
		$is_beta_mode = $settings_manager->is_beta_mode_enabled();

		?>
		<div class="wrap mati-admin-wrap">
			<h1>Mati 設定</h1>

			<?php if ( $is_beta_mode ) : ?>
			<div class="notice notice-info">
				<p><strong>ベータモード</strong> - プレリリース版のアップデートが有効です。無効にするには <code>&mati_beta=off</code> を追加してください。</p>
			</div>
			<?php endif; ?>

			<?php if ( $beta_message === 'need_password' ) : ?>
			<div class="notice notice-warning">
				<p><strong>ベータモード認証</strong></p>
				<form method="post" style="margin: 10px 0;">
					<?php wp_nonce_field( 'mati_beta_auth', 'mati_beta_nonce' ); ?>
					<input type="password" name="mati_beta_password" placeholder="パスワードを入力" style="width: 200px;" />
					<input type="submit" class="button" value="認証" />
				</form>
			</div>
			<?php elseif ( $beta_message === 'rate_limit' ) : ?>
			<div class="notice notice-error">
				<p>ログイン試行回数が超過しました。10分後に再試行してください。</p>
			</div>
			<?php elseif ( $beta_message === 'wrong_password' ) : ?>
			<div class="notice notice-error">
				<p>パスワードが正しくありません。</p>
			</div>
			<div class="notice notice-warning">
				<p><strong>ベータモード認証</strong></p>
				<form method="post" style="margin: 10px 0;">
					<?php wp_nonce_field( 'mati_beta_auth', 'mati_beta_nonce' ); ?>
					<input type="password" name="mati_beta_password" placeholder="パスワードを入力" style="width: 200px;" />
					<input type="submit" class="button" value="認証" />
				</form>
			</div>
			<?php elseif ( $beta_message === 'activated' ) : ?>
			<div class="notice notice-success">
				<p>ベータモードを有効化しました。</p>
			</div>
			<?php elseif ( $beta_message === 'deactivated' ) : ?>
			<div class="notice notice-info">
				<p>ベータモードを無効化しました。</p>
			</div>
			<?php endif; ?>

			<?php $this->check_carry_pod_compatibility(); ?>

			<div id="mati-message" class="notice" style="display:none;"></div>

			<form id="mati-settings-form">
				<?php wp_nonce_field( 'mati_save_settings', 'mati_settings_nonce' ); ?>

				<!-- コンテンツ保護アコーディオン -->
				<div class="mati-accordion-section" data-section="content-protection">
					<button type="button" class="mati-accordion-header"
					        id="header-content-protection"
					        aria-expanded="true"
					        aria-controls="accordion-content-protection">
						<span class="mati-accordion-title">コンテンツ保護設定</span>
						<span class="mati-accordion-icon" aria-hidden="true"></span>
					</button>
					<div id="accordion-content-protection"
					     class="mati-accordion-content"
					     role="region"
					     aria-labelledby="header-content-protection"
					     aria-hidden="false">

						<!-- 親チェックボックス -->
						<div class="mati-form-group">
							<label>
								<input type="checkbox" id="mati-content-protection-enabled" name="content_protection_enabled" value="1" <?php checked( ! empty( $settings['content_protection_enabled'] ) ); ?>>
								<strong>コンテンツ保護をすべて有効にする</strong>
							</label>
							<p class="description">※ これらの機能は完全な保護ではなく、あくまで抑止力として機能します。</p>
						</div>

						<!-- 子チェックボックス -->
						<div class="mati-subsection">
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_right_click" value="1" <?php checked( ! empty( $settings['disable_right_click'] ) ); ?>>
									右クリック禁止
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">マウスの右クリックメニューを無効化します。画像やテキストのコピーを抑止できます。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_devtools_keys" value="1" <?php checked( ! empty( $settings['disable_devtools_keys'] ) ); ?>>
									デベロッパーツール系キー無効化（F12, Ctrl+Shift+I等）
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">ブラウザの開発者ツールを開くキーボードショートカットを無効化します。<br>※ ブラウザの仕様により、一部のショートカットはブロックできない場合があります。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_save_keys" value="1" <?php checked( ! empty( $settings['disable_save_keys'] ) ); ?>>
									サイト保存キー無効化（Ctrl+S等）
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">ページ保存のキーボードショートカットを無効化します。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_text_selection" value="1" <?php checked( ! empty( $settings['disable_text_selection'] ) ); ?>>
									テキスト選択禁止
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">ページ上のテキストを選択できないようにします。コピー防止に役立ちます。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_image_drag" value="1" <?php checked( ! empty( $settings['disable_image_drag'] ) ); ?>>
									画像ドラッグ禁止
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">画像のドラッグ＆ドロップでの保存を無効化します。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_print" value="1" <?php checked( ! empty( $settings['disable_print'] ) ); ?>>
									印刷禁止
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">ページの印刷機能を無効化します。印刷時にコンテンツが表示されなくなります。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="add_noarchive_meta" value="1" <?php checked( ! empty( $settings['add_noarchive_meta'] ) ); ?>>
									検索エンジンのキャッシュ保存を拒否（noarchive）
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">検索エンジンにページのキャッシュを保存しないよう指示します。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="add_noimageindex_meta" value="1" <?php checked( ! empty( $settings['add_noimageindex_meta'] ) ); ?>>
									画像の検索インデックス登録を拒否（noimageindex）
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">検索エンジンに画像をインデックスしないよう指示します。画像検索結果に表示されなくなります。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="add_noai_meta" value="1" <?php checked( ! empty( $settings['add_noai_meta'] ) ); ?>>
									AI学習防止メタタグ（noai, noimageai）を追加
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">AI学習用のクローラーに対してコンテンツの学習を拒否する意思表示を行います。</span>
									</span>
								</label>
							</div>
						</div>

					</div>
				</div>

				<!-- 不要な情報の非表示 -->
				<div class="mati-accordion-section" data-section="meta-removal">
					<button type="button" class="mati-accordion-header"
					        id="header-meta-removal"
					        aria-expanded="false"
					        aria-controls="accordion-meta-removal">
						<span class="mati-accordion-title">サイトメタ情報の表示設定</span>
						<span class="mati-accordion-icon" aria-hidden="true"></span>
					</button>
					<div id="accordion-meta-removal"
					     class="mati-accordion-content"
					     role="region"
					     aria-labelledby="header-meta-removal"
					     aria-hidden="true"
					     style="display: none;">

						<!-- 親チェックボックス -->
						<div class="mati-form-group">
							<label>
								<input type="checkbox" id="mati-meta-removal-enabled" name="meta_removal_enabled" value="1" <?php checked( ! empty( $settings['meta_removal_enabled'] ) ); ?>>
								<strong>不要な情報の非表示をすべて有効にする</strong>
							</label>
						</div>

						<!-- 子チェックボックス -->
						<div class="mati-subsection">
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_generator" value="1" <?php checked( ! empty( $settings['remove_generator'] ) ); ?>>
									WordPressバージョン情報の非表示
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">WordPressのバージョン情報を非表示にします。セキュリティ向上に役立ちます。静的化する場合も事前に非表示にしておくことを推奨します。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_rest_api_link" value="1" <?php checked( ! empty( $settings['remove_rest_api_link'] ) ); ?>>
									REST API (wp-json) リンクの非表示
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">REST APIディスカバリーリンク（&lt;link rel="https://api.w.org/"&gt;）を非表示にします。静的化する場合は非表示にすることを推奨します。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_oembed" value="1" <?php checked( ! empty( $settings['remove_oembed'] ) ); ?>>
									oEmbedリンクの非表示
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">oEmbed関連のリンクを非表示にします。外部サービスの埋め込みを使わない場合や静的化する場合は非表示にすることを推奨します。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_rsd" value="1" <?php checked( ! empty( $settings['remove_rsd'] ) ); ?>>
									RSDリンクの非表示
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">外部ブログエディタ用のRSDリンクを非表示にします。通常は不要で、静的化する場合も非表示にすることを推奨します。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_wlwmanifest" value="1" <?php checked( ! empty( $settings['remove_wlwmanifest'] ) ); ?>>
									wlwmanifestリンクの非表示
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">Windows Live Writer用のリンクを非表示にします。Windows Live Writerはサポート終了済みのため、非表示にすることを推奨します。静的化する場合も不要です。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_shortlink" value="1" <?php checked( ! empty( $settings['remove_shortlink'] ) ); ?>>
									shortlinkの非表示
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">短縮URLへのリンクを非表示にします。短縮URLを使用していない場合や静的化する場合は非表示にすることを推奨します。</span>
									</span>
								</label>
							</div>
							<div class="mati-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_pingback" value="1" <?php checked( ! empty( $settings['remove_pingback'] ) ); ?>>
									pingbackの無効化
									<span class="mati-tooltip-wrapper">
										<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="mati-tooltip-content" role="tooltip">ピンバック機能を無効化します。スパムコメント対策に効果的で、静的化する場合は不要な機能です。</span>
									</span>
								</label>
							</div>
						</div>

					</div>
				</div>

				<!-- SEOアコーディオン -->
				<div class="mati-accordion-section" data-section="seo">
					<button type="button" class="mati-accordion-header"
					        id="header-seo"
					        aria-expanded="false"
					        aria-controls="accordion-seo">
						<span class="mati-accordion-title">SEO設定</span>
						<span class="mati-accordion-icon" aria-hidden="true"></span>
					</button>
					<div id="accordion-seo"
					     class="mati-accordion-content"
					     role="region"
					     aria-labelledby="header-seo"
					     aria-hidden="true"
					     style="display: none;">

						<div class="mati-form-group">
							<label for="mati-google-verification">
								Google Search Console 認証コード
								<span class="mati-tooltip-wrapper">
									<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="mati-tooltip-content" role="tooltip">Google Search Consoleの認証メタタグのcontent値を入力してください</span>
								</span>
							</label>
							<input type="text" id="mati-google-verification" name="google_verification" class="regular-text" value="<?php echo esc_attr( $settings['google_verification'] ?? '' ); ?>" placeholder="例: 1234567890abcdef">
						</div>

						<div class="mati-form-group">
							<label for="mati-bing-verification">
								Bing Webmaster Tools 認証コード
								<span class="mati-tooltip-wrapper">
									<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="mati-tooltip-content" role="tooltip">Bing Webmaster Toolsの認証メタタグのcontent値を入力してください</span>
								</span>
							</label>
							<input type="text" id="mati-bing-verification" name="bing_verification" class="regular-text" value="<?php echo esc_attr( $settings['bing_verification'] ?? '' ); ?>" placeholder="例: 1234567890ABCDEF1234567890ABCDEF">
						</div>

						<div class="mati-form-group">
							<label>
								<input type="checkbox" name="enable_jsonld" value="1" <?php checked( ! empty( $settings['enable_jsonld'] ) ); ?>>
								JSON-LD構造化データを出力
								<span class="mati-tooltip-wrapper">
									<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="mati-tooltip-content" role="tooltip">WebSite、Organization、BreadcrumbListの構造化データを自動生成してSEOを改善します。</span>
								</span>
							</label>
						</div>

						<div class="mati-form-group">
							<label>
								<input type="checkbox" name="add_noindex_meta" value="1" <?php checked( ! empty( $settings['add_noindex_meta'] ) ); ?>>
								検索エンジンのインデックス登録を拒否(noindex)
								<span class="mati-tooltip-wrapper">
									<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="mati-tooltip-content" role="tooltip">検索エンジンにサイトをインデックスしないよう指示します。サイト全体が検索結果に表示されなくなります。<br><strong>非常に強力な設定のため、使用時は十分注意してください。</strong></span>
								</span>
							</label>
						</div>

					</div>
				</div>

				<!-- Misskey/Mastodon本人認証アコーディオン -->
				<div class="mati-accordion-section" data-section="fediverse">
					<button type="button" class="mati-accordion-header"
					        id="header-fediverse"
					        aria-expanded="false"
					        aria-controls="accordion-fediverse">
						<span class="mati-accordion-title">Misskey/Mastodon本人認証設定</span>
						<span class="mati-accordion-icon" aria-hidden="true"></span>
					</button>
					<div id="accordion-fediverse"
					     class="mati-accordion-content"
					     role="region"
					     aria-labelledby="header-fediverse"
					     aria-hidden="true"
					     style="display: none;">

						<div class="mati-form-group">
							<label>
								プロフィール URL
								<span class="mati-tooltip-wrapper">
									<span class="mati-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="mati-tooltip-content" role="tooltip">Misskey・Mastodonで本人確認マーク（緑のチェック✓）を付けるためのプロフィールURL（最大5個）。このサイトとプロフィールを紐付けることで、サイト所有者であることを証明し、なりすましを防止できます</span>
								</span>
							</label>
							<div id="mati-fediverse-urls-container">
								<?php
								$fediverse_urls = $settings['fediverse_profile_urls'] ?? array();
								if ( empty( $fediverse_urls ) ) {
									// 最初の1つは必ず表示
									?>
									<div class="mati-fediverse-url-row">
										<input type="url" name="fediverse_profile_urls[]" class="regular-text" value="" placeholder="例: https://misskey.io/@username">
										<button type="button" class="mati-remove-url" style="display: none;">削除</button>
									</div>
									<?php
								} else {
									foreach ( $fediverse_urls as $url ) {
										?>
										<div class="mati-fediverse-url-row">
											<input type="url" name="fediverse_profile_urls[]" class="regular-text" value="<?php echo esc_attr( $url ); ?>" placeholder="例: https://misskey.io/@username">
											<button type="button" class="mati-remove-url">削除</button>
										</div>
										<?php
									}
								}
								?>
							</div>
							<button type="button" class="button button-primary" id="mati-add-fediverse-url" style="margin-top: 10px;">URL を追加</button>
						</div>

					</div>
				</div>

				<!-- 保存・リセットボタン -->
				<div class="mati-form-actions">
					<button type="submit" class="button button-primary" id="mati-save-button">
						設定を保存
					</button>
					<button type="button" class="button button-danger" id="mati-reset-button">
						リセット
					</button>
					<button type="button" class="button" id="mati-export-settings">
						設定をエクスポート
					</button>
					<button type="button" class="button" id="mati-import-settings">
						設定をインポート
					</button>
					<input type="file" id="mati-import-file" accept=".json" style="display:none;">
					<span class="spinner"></span>
				</div>

			</form>

			<div class="mati-version-info">
				Mati <a href="https://github.com/villyoshioka/mati/releases/tag/v<?php echo esc_attr( MATI_VERSION ); ?>" target="_blank" rel="noopener noreferrer">v<?php echo esc_html( MATI_VERSION ); ?></a>
			</div>

		</div>
		<?php
	}

	/**
	 * Ajax: 設定を保存
	 */
	public function ajax_save_settings() {
		// 権限チェック
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
		}

		// Nonceチェック
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mati_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。' ) );
		}

		// 設定を保存
		$settings_manager = Mati_Settings::get_instance();
		$new_settings     = $_POST['settings'] ?? array(); // サニタイズはsave_settings内で実施
		$result           = $settings_manager->save_settings( $new_settings );

		if ( $result ) {
			wp_send_json_success( array( 'message' => '設定を保存しました。' ) );
		} else {
			wp_send_json_error( array( 'message' => '設定の保存に失敗しました。' ) );
		}
	}

	/**
	 * Ajax: 設定をリセット
	 */
	public function ajax_reset_settings() {
		// 権限チェック
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
		}

		// Nonceチェック
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mati_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。' ) );
		}

		// 設定をリセット
		$settings_manager = Mati_Settings::get_instance();
		$result           = $settings_manager->reset_settings();

		if ( $result ) {
			wp_send_json_success( array( 'message' => '設定をリセットしました。' ) );
		} else {
			wp_send_json_error( array( 'message' => '設定のリセットに失敗しました。' ) );
		}
	}

	/**
	 * Ajax: 設定をエクスポート
	 */
	public function ajax_export_settings() {
		// 権限チェック
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
		}

		// Nonceチェック
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mati_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。' ) );
		}

		$settings_manager = Mati_Settings::get_instance();
		$json             = $settings_manager->export_settings();

		wp_send_json_success( array( 'data' => $json ) );
	}

	/**
	 * Ajax: 設定をインポート
	 */
	public function ajax_import_settings() {
		// 権限チェック
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
		}

		// Nonceチェック
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mati_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。' ) );
		}

		if ( ! isset( $_POST['data'] ) ) {
			wp_send_json_error( array( 'message' => 'データが送信されていません。' ) );
		}

		// インポートデータをサニタイズ（JSON文字列としてエスケープ処理）
		$import_data = wp_unslash( $_POST['data'] );

		$settings_manager = Mati_Settings::get_instance();
		$result           = $settings_manager->import_settings( $import_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => '設定をインポートしました。' ) );
	}

	/**
	 * CarryPod互換性チェック
	 */
	public function check_carry_pod_compatibility() {
		// CarryPodがインストールされていない場合は何も表示しない
		if ( ! defined( 'CP_VERSION' ) ) {
			return;
		}

		$cp_version = CP_VERSION;

		// バージョン形式の検証（セマンティックバージョニング: x.y.z または x.y.z-suffix）
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9\-]+)?$/', $cp_version ) ) {
			// 不正なバージョン形式の場合は警告を表示せず終了
			return;
		}

		// 両方とも x.3.0以降の場合は何も表示しない
		if ( version_compare( $cp_version, '2.3.0', '>=' ) ) {
			return;
		}

		// CarryPodが古い場合は警告を表示
		?>
		<div class="notice notice-warning">
			<p>
				<strong>⚠️ CarryPod連携</strong><br>
				CarryPod 2.3.0以降にアップデートすると、双方向連携機能が有効になります。<br>
				<small>現在: CarryPod <?php echo esc_html( $cp_version ); ?> → 推奨: CarryPod 2.3.0+</small>
			</p>
		</div>
		<?php
	}
}
