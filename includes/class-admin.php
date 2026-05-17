<?php
/**
 * 管理画面クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( false ) {
	define( 'CP_VERSION', '' );
}

class Mati_Admin {

	private static ?self $instance = null;

	public static function get_instance(): static {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_mati_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_mati_reset_settings', array( $this, 'ajax_reset_settings' ) );
		add_action( 'wp_ajax_mati_export_settings', array( $this, 'ajax_export_settings' ) );
		add_action( 'wp_ajax_mati_import_settings', array( $this, 'ajax_import_settings' ) );
	}

	public function add_admin_menu(): void {
		add_menu_page(
			'Mati',
			'Mati',
			'manage_options',
			'mati',
			array( $this, 'render_settings_page' ),
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0iY3VycmVudENvbG9yIj48cGF0aCBkPSJNMTEuNDksMi44NGwtLjQzLTEuNTZoLS41N2MtLjA4LS4xNy0uMjctLjI5LS40OC0uMjlzLS40LjEyLS40OC4yOWgtLjU3bC0uNDMsMS41NkM0LjI1LDMuNDksMSw2LjgzLDEsMTAuODZjMCw0LjQ5LDQuMDMsOC4xNCw5LDguMTRzOS0zLjY0LDktOC4xNGMwLTQuMDMtMy4yNS03LjM3LTcuNTEtOC4wMlpNMTAsMTcuNTZjLTQuMDksMC03LjQxLTMtNy40MS02LjdzMy4zMi02LjcsNy40MS02LjcsNy40MSwzLDcuNDEsNi43LTMuMzIsNi43LTcuNDEsNi43WiIvPjxwYXRoIGQ9Ik0xMCw1Ljg4Yy0zLjA0LDAtNS41MSwyLjIzLTUuNTEsNC45OHMyLjQ3LDQuOTgsNS41MSw0Ljk4LDUuNTEtMi4yMyw1LjUxLTQuOTgtMi40Ny00Ljk4LTUuNTEtNC45OFpNMTAsMTMuNTRjLTEuNjQsMC0yLjk2LTEuMi0yLjk2LTIuNjhzMS4zMy0yLjY4LDIuOTYtMi42OCwyLjk2LDEuMiwyLjk2LDIuNjgtMS4zMywyLjY4LTIuOTYsMi42OFoiLz48ZWxsaXBzZSBjeD0iMTAiIGN5PSIxMC44NiIgcng9IjEuNTkiIHJ5PSIxLjQ0Ii8+PC9zdmc+Cg==',
			79
		);
	}

	public function enqueue_scripts( string $hook ): void {
		if ( $hook !== 'toplevel_page_mati' ) {
			return;
		}

		wp_enqueue_style(
			'nau-admin-fw',
			MATI_PLUGIN_URL . 'assets/css/admin-fw.css',
			array(),
			MATI_VERSION
		);

		wp_enqueue_style(
			'mati-admin-css',
			MATI_PLUGIN_URL . 'assets/css/admin.css',
			array( 'nau-admin-fw' ),
			MATI_VERSION
		);

		wp_enqueue_script(
			'mati-admin-js',
			MATI_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			MATI_VERSION,
			true
		);

		wp_localize_script( 'mati-admin-js', 'matiData', array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'mati_nonce' ),
			'cpIsRunning' => (bool) ( get_transient( 'cp_manual_running' ) || get_transient( 'cp_auto_running' ) ),
		) );
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '設定変更の権限がありません。' );
		}

		$settings_manager = Mati_Settings::get_instance();
		$settings         = $settings_manager->get_settings();

		$cp_is_running = (bool) ( get_transient( 'cp_manual_running' ) || get_transient( 'cp_auto_running' ) );

		$beta_message = '';
		if ( isset( $_GET['mati_beta'] ) ) {
			$beta_param = sanitize_text_field( wp_unslash( $_GET['mati_beta'] ) );
			if ( $beta_param === 'on' ) {
				if ( $settings_manager->is_beta_mode_enabled() ) {
				} elseif ( isset( $_POST['mati_beta_password'] ) && isset( $_POST['mati_beta_nonce'] ) ) {
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

		$force_message = '';
		if ( isset( $_GET['mati_force'] ) ) {
			$force_param = sanitize_text_field( wp_unslash( $_GET['mati_force'] ) );
			if ( $force_param === 'on' ) {
				set_transient( 'mati_force_protection', true, DAY_IN_SECONDS );
				$force_message = 'activated';
			} elseif ( $force_param === 'off' ) {
				delete_transient( 'mati_force_protection' );
				$force_message = 'deactivated';
			}
		}
		$is_force_mode = (bool) get_transient( 'mati_force_protection' );

		?>
		<div class="wrap nau-admin-wrap">
			<h1>Mati 設定</h1>

			<?php if ( $is_force_mode ) : ?>
			<div class="notice notice-warning">
				<p><strong>強制保護モード</strong> - ローカル環境でもコンテンツ保護が有効です。無効にするには <code>&mati_force=off</code> を追加してください。</p>
			</div>
			<?php elseif ( $force_message === 'deactivated' ) : ?>
			<div class="notice notice-info mati-auto-dismiss">
				<p>強制保護モードを無効化しました。</p>
			</div>
			<script>setTimeout(function(){var n=document.querySelector('.mati-auto-dismiss');if(n)n.style.transition='opacity .5s',n.style.opacity='0',setTimeout(function(){n.remove()},500)},3000);</script>
			<?php endif; ?>

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

			<form id="mati-settings-form" class="nau-settings-form">
				<?php wp_nonce_field( 'mati_save_settings', 'mati_settings_nonce' ); ?>

				<!-- コンテンツ保護アコーディオン -->
				<div class="nau-accordion-section" data-section="content-protection">
					<button type="button" class="nau-accordion-header"
					        id="header-content-protection"
					        aria-expanded="true"
					        aria-controls="accordion-content-protection">
						<span class="nau-accordion-title">コンテンツ保護設定</span>
						<span class="nau-accordion-icon" aria-hidden="true"></span>
					</button>
					<div id="accordion-content-protection"
					     class="nau-accordion-content"
					     role="region"
					     aria-labelledby="header-content-protection"
					     aria-hidden="false">

						<div class="nau-form-group">
							<label>
								<input type="checkbox" id="mati-content-protection-enabled" name="content_protection_enabled" value="1" <?php checked( ! empty( $settings['content_protection_enabled'] ) ); ?>>
								<strong>コンテンツ保護をすべて有効にする</strong>
							</label>
							<p class="description mati-mobile-hidden">※ これらの機能は完全な保護ではなく、あくまで抑止力として機能します。</p>
						</div>

						<div class="nau-subsection">
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_right_click" value="1" <?php checked( ! empty( $settings['disable_right_click'] ) ); ?>>
									右クリック禁止
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">右クリックメニューを無効化します</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_devtools_keys" value="1" <?php checked( ! empty( $settings['disable_devtools_keys'] ) ); ?>>
									デベロッパーツール系キー無効化
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">DevToolsを開くショートカットキーを無効化します</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_save_keys" value="1" <?php checked( ! empty( $settings['disable_save_keys'] ) ); ?>>
									サイト保存キー無効化
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">Ctrl+S等のページ保存キーを無効化します</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_image_drag" value="1" <?php checked( ! empty( $settings['disable_image_drag'] ) ); ?>>
									画像ドラッグ禁止
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">画像のドラッグ保存を無効化します</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="disable_print" value="1" <?php checked( ! empty( $settings['disable_print'] ) ); ?>>
									印刷禁止
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">印刷時にコンテンツを非表示にします</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="add_noarchive_meta" value="1" <?php checked( ! empty( $settings['add_noarchive_meta'] ) ); ?>>
									検索エンジンのキャッシュ保存を拒否
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">検索エンジンのキャッシュ保存を拒否します</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="add_noimageindex_meta" value="1" <?php checked( ! empty( $settings['add_noimageindex_meta'] ) ); ?>>
									画像の検索インデックスを拒否
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">画像検索の結果に表示されなくなります</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-content-protection-enabled" name="add_noai_meta" value="1" <?php checked( ! empty( $settings['add_noai_meta'] ) ); ?>>
									AI学習防止メタタグを追加
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">AIクローラーに対して学習拒否の意思表示を行います</span>
									</span>
								</label>
							</div>
						</div>

					</div>
				</div>

				<div class="nau-accordion-section" data-section="meta-removal">
					<button type="button" class="nau-accordion-header"
					        id="header-meta-removal"
					        aria-expanded="false"
					        aria-controls="accordion-meta-removal">
						<span class="nau-accordion-title">サイトメタ情報の表示設定</span>
						<span class="nau-accordion-icon" aria-hidden="true"></span>
					</button>
					<div id="accordion-meta-removal"
					     class="nau-accordion-content"
					     role="region"
					     aria-labelledby="header-meta-removal"
					     aria-hidden="true"
					     style="display: none;">

						<div class="nau-form-group">
							<label>
								<input type="checkbox" id="mati-meta-removal-enabled" name="meta_removal_enabled" value="1" <?php checked( ! empty( $settings['meta_removal_enabled'] ) ); ?>>
								<strong>不要な情報の非表示をすべて有効にする</strong>
							</label>
						</div>

						<div class="nau-subsection">
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_generator" value="1" <?php checked( ! empty( $settings['remove_generator'] ) ); ?>>
									WordPressバージョン情報の非表示
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">WPバージョンの露出を防ぎます</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_rest_api_link" value="1" <?php checked( ! empty( $settings['remove_rest_api_link'] ) ); ?>>
									REST API リンクの非表示
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">REST APIのエンドポイント情報を非表示にします</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_oembed" value="1" <?php checked( ! empty( $settings['remove_oembed'] ) ); ?>>
									oEmbedリンクの非表示
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">外部埋め込み用のoEmbedリンクを非表示にします</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_rsd" value="1" <?php checked( ! empty( $settings['remove_rsd'] ) ); ?>>
									RSDリンクの非表示
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">外部ブログエディタ用のリンクを非表示にします</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_wlwmanifest" value="1" <?php checked( ! empty( $settings['remove_wlwmanifest'] ) ); ?>>
									wlwmanifestリンクの非表示
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">サポート終了済みのWindows Live Writer用リンクを非表示にします</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_shortlink" value="1" <?php checked( ! empty( $settings['remove_shortlink'] ) ); ?>>
									shortlinkの非表示
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">短縮URLのリンクを非表示にします</span>
									</span>
								</label>
							</div>
							<div class="nau-form-group">
								<label>
									<input type="checkbox" class="mati-child-checkbox" data-parent="mati-meta-removal-enabled" name="remove_pingback" value="1" <?php checked( ! empty( $settings['remove_pingback'] ) ); ?>>
									pingbackの無効化
									<span class="nau-tooltip-wrapper">
										<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
										<span class="nau-tooltip-content" role="tooltip">ピンバック機能を無効化します</span>
									</span>
								</label>
							</div>
						</div>

					</div>
				</div>

				<div class="nau-accordion-section" data-section="seo">
					<button type="button" class="nau-accordion-header"
					        id="header-seo"
					        aria-expanded="false"
					        aria-controls="accordion-seo">
						<span class="nau-accordion-title">SEO設定</span>
						<span class="nau-accordion-icon" aria-hidden="true"></span>
					</button>
					<div id="accordion-seo"
					     class="nau-accordion-content"
					     role="region"
					     aria-labelledby="header-seo"
					     aria-hidden="true"
					     style="display: none;">

						<div class="nau-form-group">
							<label for="mati-google-analytics-id">
								Google Analytics 測定ID
								<span class="nau-tooltip-wrapper">
									<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="nau-tooltip-content" role="tooltip">G-XXXXXXXXXXの形式で入力してください</span>
								</span>
							</label>
							<input type="text" id="mati-google-analytics-id" name="google_analytics_id" class="regular-text" value="<?php echo esc_attr( $settings['google_analytics_id'] ?? '' ); ?>" placeholder="例: G-XXXXXXXXXX">
						</div>

						<div class="nau-form-group">
							<label for="mati-google-verification">
								Google Search Console 認証コード
								<span class="nau-tooltip-wrapper">
									<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="nau-tooltip-content" role="tooltip">認証メタタグのcontent値を入力してください</span>
								</span>
							</label>
							<input type="text" id="mati-google-verification" name="google_verification" class="regular-text" value="<?php echo esc_attr( $settings['google_verification'] ?? '' ); ?>" placeholder="例: 1234567890abcdef">
						</div>

						<div class="nau-form-group">
							<label for="mati-bing-verification">
								Bing Webmaster Tools 認証コード
								<span class="nau-tooltip-wrapper">
									<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="nau-tooltip-content" role="tooltip">認証メタタグのcontent値を入力してください</span>
								</span>
							</label>
							<input type="text" id="mati-bing-verification" name="bing_verification" class="regular-text" value="<?php echo esc_attr( $settings['bing_verification'] ?? '' ); ?>" placeholder="例: 1234567890ABCDEF1234567890ABCDEF">
						</div>

						<div class="nau-form-group">
							<label>
								<input type="checkbox" name="enable_jsonld" value="1" <?php checked( ! empty( $settings['enable_jsonld'] ) ); ?>>
								JSON-LD構造化データを出力
								<span class="nau-tooltip-wrapper">
									<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="nau-tooltip-content" role="tooltip">構造化データ（WebSite・パンくずリスト等）を自動生成します</span>
								</span>
							</label>
						</div>

						<div class="nau-form-group">
							<label>
								<input type="checkbox" name="add_noindex_meta" value="1" <?php checked( ! empty( $settings['add_noindex_meta'] ) ); ?>>
								検索エンジンのインデックスを拒否
								<span class="nau-tooltip-wrapper">
									<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="nau-tooltip-content" role="tooltip">サイト全体が検索結果に表示されなくなります。<br><strong>注意: 有効にすると検索流入がなくなります</strong></span>
								</span>
							</label>
						</div>

					</div>
				</div>

				<div class="nau-accordion-section" data-section="fediverse">
					<button type="button" class="nau-accordion-header"
					        id="header-fediverse"
					        aria-expanded="false"
					        aria-controls="accordion-fediverse">
						<span class="nau-accordion-title">SNS本人認証設定</span>
						<span class="nau-accordion-icon" aria-hidden="true"></span>
					</button>
					<div id="accordion-fediverse"
					     class="nau-accordion-content"
					     role="region"
					     aria-labelledby="header-fediverse"
					     aria-hidden="true"
					     style="display: none;">

						<div class="nau-form-group">
							<label>
								Mastodon/Misskey プロフィール URL
								<span class="nau-tooltip-wrapper">
									<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="nau-tooltip-content" role="tooltip">サイトとアカウントを紐付けて本人認証できます（最大5個）</span>
								</span>
							</label>
							<div id="mati-fediverse-urls-container">
								<?php
								$fediverse_urls = $settings['fediverse_profile_urls'] ?? array();
								if ( empty( $fediverse_urls ) ) {
									?>
									<div class="nau-input-row mati-fediverse-url-row">
										<input type="url" name="fediverse_profile_urls[]" class="regular-text" value="" placeholder="プロフィールURL（例: https://misskey.io/@username）">
										<button type="button" class="button button-caution nau-input-row-remove mati-remove-url">削除</button>
									</div>
									<?php
								} else {
									foreach ( $fediverse_urls as $url ) {
										?>
										<div class="nau-input-row mati-fediverse-url-row">
											<input type="url" name="fediverse_profile_urls[]" class="regular-text" value="<?php echo esc_attr( $url ); ?>" placeholder="プロフィールURL（例: https://misskey.io/@username）">
											<button type="button" class="button button-caution nau-input-row-remove mati-remove-url">削除</button>
										</div>
										<?php
									}
								}
								?>
							</div>
							<button type="button" class="button button-primary nau-input-row-add" id="mati-add-fediverse-url">URL を追加</button>
						</div>

						<div class="nau-form-group">
							<label>
								Bluesky DID
								<span class="nau-tooltip-wrapper">
									<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="nau-tooltip-content" role="tooltip">サイトのドメインをハンドル名にして本人認証できます</span>
								</span>
							</label>
							<?php
							$has_bluesky = ! empty( $settings['bluesky_did'] );
							$bluesky_placeholder = $has_bluesky ? '設定済み（変更する場合は新しいURLを入力）' : 'プロフィールURL（例: https://bsky.app/profile/username.bsky.social）';
							?>
							<div class="nau-input-row mati-fediverse-url-row">
								<input type="url" name="bluesky_profile_url" class="regular-text" value="" placeholder="<?php echo esc_attr( $bluesky_placeholder ); ?>">
								<button type="button" class="button button-caution nau-input-row-remove mati-remove-url mati-clear-bluesky"<?php echo $has_bluesky ? '' : ' style="display: none;"'; ?>>削除</button>
							</div>
						</div>

					</div>
				</div>

				<div class="nau-accordion-section" data-section="other-settings">
					<button type="button" class="nau-accordion-header"
					        id="header-other-settings"
					        aria-expanded="false"
					        aria-controls="accordion-other-settings">
						<span class="nau-accordion-title">その他の設定</span>
						<span class="nau-accordion-icon" aria-hidden="true"></span>
					</button>
					<div id="accordion-other-settings"
					     class="nau-accordion-content"
					     role="region"
					     aria-labelledby="header-other-settings"
					     aria-hidden="true"
					     style="display: none;">

						<div class="nau-form-group">
							<label>
								<input type="checkbox" name="disable_text_selection" value="1" <?php checked( ! empty( $settings['disable_text_selection'] ) ); ?>>
								テキスト選択禁止
								<span class="nau-tooltip-wrapper">
									<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="nau-tooltip-content" role="tooltip">テキストの選択・コピーを無効化します</span>
								</span>
							</label>
						</div>
						<div class="nau-form-group">
							<label>
								iframe埋め込み許可ドメイン
								<span class="nau-tooltip-wrapper">
									<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>
									<span class="nau-tooltip-content" role="tooltip">iframe埋め込みを許可するドメイン（1行に1つ、https://のみ）</span>
								</span>
							</label>
							<textarea name="frame_ancestors_domains" class="large-text" rows="4" placeholder="例: https://example.com"><?php echo esc_textarea( $settings['frame_ancestors_domains'] ?? '' ); ?></textarea>
						</div>

					</div>
				</div>

				<div class="nau-form-actions">
					<button type="submit" class="button button-primary" id="mati-save-button" <?php echo $cp_is_running ? 'disabled' : ''; ?>>
						設定を保存
					</button>
					<button type="button" class="button button-danger" id="mati-reset-button" <?php echo $cp_is_running ? 'disabled' : ''; ?>>
						設定をリセット
					</button>
					<button type="button" class="button" id="mati-export-settings">
						設定をエクスポート
					</button>
					<button type="button" class="button" id="mati-import-settings" <?php echo $cp_is_running ? 'disabled' : ''; ?>>
						設定をインポート
					</button>
					<input type="file" id="mati-import-file" accept=".json" style="display:none;">
					<span class="spinner"></span>
				</div>

			</form>

			<div class="nau-version-info">
				Mati <a href="https://github.com/villyoshioka/mati/releases/tag/v<?php echo esc_attr( MATI_VERSION ); ?>" target="_blank" rel="noopener noreferrer">v<?php echo esc_html( MATI_VERSION ); ?></a>
			</div>

		</div>
		<?php
	}

	public function ajax_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mati_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。' ) );
		}

		if ( get_transient( 'cp_manual_running' ) || get_transient( 'cp_auto_running' ) ) {
			wp_send_json_error( array( 'message' => 'Carry Podの静的化実行中は設定を変更できません。' ) );
		}

		$settings_manager = Mati_Settings::get_instance();
		$new_settings     = $_POST['settings'] ?? array();

		$bluesky_clear = ! empty( $new_settings['bluesky_clear'] );
		$bluesky_url   = isset( $new_settings['bluesky_profile_url'] ) ? sanitize_text_field( $new_settings['bluesky_profile_url'] ) : '';
		unset( $new_settings['bluesky_clear'] );

		if ( $bluesky_clear ) {
			$new_settings['bluesky_profile_url'] = '';
			$new_settings['bluesky_did']         = '';
		} elseif ( ! empty( $bluesky_url ) ) {
			$bluesky_did = $this->resolve_bluesky_did( $bluesky_url );
			if ( is_wp_error( $bluesky_did ) ) {
				wp_send_json_error( array( 'message' => 'Blueskyアカウントの設定に失敗しました。URLを確認してください。' ) );
				return;
			}
			$new_settings['bluesky_did'] = $bluesky_did;
		} else {
			// 空の場合: 既存の設定を維持（UIではURLを非表示にしているため）
			$current_settings = $settings_manager->get_settings();
			$new_settings['bluesky_profile_url'] = $current_settings['bluesky_profile_url'] ?? '';
			$new_settings['bluesky_did']         = $current_settings['bluesky_did'] ?? '';
		}

		$result = $settings_manager->save_settings( $new_settings );

		if ( $result ) {
			wp_send_json_success( array( 'message' => '設定を保存しました。' ) );
		} else {
			wp_send_json_error( array( 'message' => '設定の保存に失敗しました。' ) );
		}
	}

	/**
	 * Bluesky プロフィールURLからDIDを解決
	 *
	 * @param string $profile_url BlueskyプロフィールURL
	 * @return string|\WP_Error DID文字列、または失敗時WP_Error
	 */
	private function resolve_bluesky_did( string $profile_url ): string|\WP_Error {
		$profile_url = str_replace(
			array( "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d" ),
			'',
			$profile_url
		);
		$profile_url = trim( $profile_url );

		$parsed = wp_parse_url( $profile_url );
		if ( ! $parsed || empty( $parsed['path'] ) ) {
			return new WP_Error( 'invalid_url', 'URLの形式が正しくありません。' );
		}

		$path = trim( $parsed['path'], '/' );
		if ( preg_match( '#^profile/(.+)$#', $path, $matches ) ) {
			$handle = $matches[1];
		} else {
			return new WP_Error( 'invalid_url', 'BlueskyプロフィールURLの形式が正しくありません。' );
		}

		$handle = sanitize_text_field( $handle );
		if ( empty( $handle ) ) {
			return new WP_Error( 'invalid_handle', 'ハンドルが取得できません。' );
		}

		$api_url  = 'https://bsky.social/xrpc/com.atproto.identity.resolveHandle?handle=' . urlencode( $handle );
		$response = wp_remote_get( $api_url, array(
			'timeout' => 10,
			'headers' => array(
				'Accept' => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', 'Bluesky APIへの接続に失敗しました。' );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return new WP_Error( 'api_error', 'Bluesky APIからエラーが返されました。' );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || empty( $data['did'] ) ) {
			return new WP_Error( 'no_did', 'DIDが取得できませんでした。' );
		}

		return sanitize_text_field( $data['did'] );
	}

	public function ajax_reset_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mati_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。' ) );
		}

		if ( get_transient( 'cp_manual_running' ) || get_transient( 'cp_auto_running' ) ) {
			wp_send_json_error( array( 'message' => 'Carry Podの静的化実行中は設定をリセットできません。' ) );
		}

		$settings_manager = Mati_Settings::get_instance();
		$result           = $settings_manager->reset_settings();

		if ( $result ) {
			wp_send_json_success( array( 'message' => '設定をリセットしました。' ) );
		} else {
			wp_send_json_error( array( 'message' => '設定のリセットに失敗しました。' ) );
		}
	}

	public function ajax_export_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mati_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。' ) );
		}

		$settings_manager = Mati_Settings::get_instance();
		$json             = $settings_manager->export_settings();

		wp_send_json_success( array( 'data' => $json ) );
	}

	public function ajax_import_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mati_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。' ) );
		}

		if ( get_transient( 'cp_manual_running' ) || get_transient( 'cp_auto_running' ) ) {
			wp_send_json_error( array( 'message' => 'Carry Podの静的化実行中は設定をインポートできません。' ) );
		}

		if ( ! isset( $_POST['data'] ) ) {
			wp_send_json_error( array( 'message' => 'データが送信されていません。' ) );
		}

		$import_data = wp_unslash( $_POST['data'] );

		$settings_manager = Mati_Settings::get_instance();
		$result           = $settings_manager->import_settings( $import_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => '設定をインポートしました。' ) );
	}

	public function check_carry_pod_compatibility(): void {
		if ( ! defined( 'CP_VERSION' ) ) {
			return;
		}

		$cp_version = CP_VERSION;

		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9\-]+)?$/', $cp_version ) ) {
			return;
		}

		if ( version_compare( $cp_version, '3.0.0', '>=' ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong>⚠️ CarryPod連携</strong><br>
				CarryPod 3.0.0以降にアップデートすると、双方向連携機能が有効になります。<br>
				<small>現在: CarryPod <?php echo esc_html( $cp_version ); ?> → 推奨: CarryPod 3.0.0+</small>
			</p>
		</div>
		<?php
	}
}
