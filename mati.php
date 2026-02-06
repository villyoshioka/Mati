<?php
/**
 * Plugin Name: Mati
 * Version: 1.5.0
 * Description: コンテンツ保護・メタタグ管理・SEO設定を簡単に制御できるWordPressプラグイン。
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Vill Yoshioka
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: mati
 */

// 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// プラグインの定数を定義
define( 'MATI_VERSION', '1.5.0' );
define( 'MATI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MATI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MATI_PLUGIN_FILE', __FILE__ );

/**
 * メインプラグインクラス
 */
class Mati {

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
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * 依存ファイルを読み込み
	 */
	private function load_dependencies() {
		// クラスファイルを読み込み
		require_once MATI_PLUGIN_DIR . 'includes/class-settings.php';
		require_once MATI_PLUGIN_DIR . 'includes/class-admin.php';
		require_once MATI_PLUGIN_DIR . 'includes/class-frontend.php';
		require_once MATI_PLUGIN_DIR . 'includes/class-updater.php';
	}

	/**
	 * フックを初期化
	 */
	private function init_hooks() {
		// 有効化/無効化フック
		register_activation_hook( MATI_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( MATI_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// プラグイン削除時のフック
		register_uninstall_hook( MATI_PLUGIN_FILE, array( 'Mati', 'uninstall' ) );

		// アップグレード処理
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );

		// GitHub自動更新を初期化
		new Mati_Updater();

		// 管理画面を初期化
		if ( is_admin() ) {
			Mati_Admin::get_instance();
		}

		// フロントエンドを初期化
		if ( ! is_admin() ) {
			Mati_Frontend::get_instance();
		}
	}

	/**
	 * プラグイン有効化時の処理
	 */
	public function activate() {
		// 初期設定を作成
		$settings_manager = Mati_Settings::get_instance();
		$settings = $settings_manager->get_settings();

		// 設定が存在しない場合のみ初期化
		if ( empty( $settings ) ) {
			$settings_manager->reset_settings();
		}
	}

	/**
	 * プラグイン無効化時の処理
	 */
	public function deactivate() {
		// 設定をリセット（サイトへの変更を元に戻す）
		$settings_manager = Mati_Settings::get_instance();
		$settings_manager->reset_settings();
	}

	/**
	 * プラグイン削除時の処理（静的メソッド）
	 */
	public static function uninstall() {
		// 設定を完全に削除
		delete_option( 'mati_settings' );
		delete_option( 'mati_version' );
	}

	/**
	 * アップグレード処理
	 */
	public function maybe_upgrade() {
		$previous_version = get_option( 'mati_version', '0.0.0' );

		// 1.3.1未満からのアップグレードの場合
		if ( version_compare( $previous_version, '1.3.1', '<' ) ) {
			$this->upgrade_to_1_3_1();
		}

		// バージョン番号を更新
		if ( version_compare( $previous_version, MATI_VERSION, '<' ) ) {
			update_option( 'mati_version', MATI_VERSION );
		}
	}

	/**
	 * v1.3.1へのアップグレード処理
	 */
	private function upgrade_to_1_3_1() {
		$settings_manager = Mati_Settings::get_instance();
		$current_settings = $settings_manager->get_settings();

		// 設定が存在する場合のみseedを再生成
		if ( ! empty( $current_settings ) ) {
			// 既存設定を維持したままseedのみ再生成（CP連携をスキップ）
			$settings_manager->save_settings( $current_settings, array( 'skip_cp_clear' => true ) );
		}
	}
}

// プラグインを初期化
Mati::get_instance();
