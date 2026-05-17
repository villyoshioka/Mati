<?php
/**
 * Plugin Name: Mati
 * Version: 2.0.0
 * Description: コンテンツ保護・メタタグ管理・SEO設定を簡単に制御できるWordPressプラグイン。
 * Requires at least: 6.8
 * Tested up to: 7.0
 * Requires PHP: 8.3
 * Author: Vill Yoshioka
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: mati
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MATI_VERSION', '2.0.0' );
define( 'MATI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MATI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MATI_PLUGIN_FILE', __FILE__ );

/**
 * メインプラグインクラス
 */
class Mati {

	private static ?self $instance = null;

	public static function get_instance(): static {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	private function load_dependencies(): void {
		require_once MATI_PLUGIN_DIR . 'includes/class-settings.php';
		require_once MATI_PLUGIN_DIR . 'includes/class-admin.php';
		require_once MATI_PLUGIN_DIR . 'includes/class-frontend.php';
		require_once MATI_PLUGIN_DIR . 'includes/class-updater.php';
	}

	private function init_hooks(): void {
		register_activation_hook( MATI_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( MATI_PLUGIN_FILE, array( $this, 'deactivate' ) );
		register_uninstall_hook( MATI_PLUGIN_FILE, array( 'Mati', 'uninstall' ) );

		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );

		new Mati_Updater();

		if ( is_admin() ) {
			Mati_Admin::get_instance();
		}

		if ( ! is_admin() ) {
			Mati_Frontend::get_instance();
		}
	}

	public function activate(): void {
		$settings_manager = Mati_Settings::get_instance();
		$settings = $settings_manager->get_settings();

		if ( empty( $settings ) ) {
			$settings_manager->reset_settings();
		}
	}

	public function deactivate(): void {
		$settings_manager = Mati_Settings::get_instance();
		$settings_manager->reset_settings();
	}

	public static function uninstall(): void {
		delete_option( 'mati_settings' );
		delete_option( 'mati_version' );
	}

	public function maybe_upgrade(): void {
		$previous_version = get_option( 'mati_version', '0.0.0' );

		if ( version_compare( $previous_version, '1.3.1', '<' ) ) {
			$this->upgrade_to_1_3_1();
		}

		if ( version_compare( $previous_version, MATI_VERSION, '<' ) ) {
			update_option( 'mati_version', MATI_VERSION );
		}
	}

	private function upgrade_to_1_3_1(): void {
		$settings_manager = Mati_Settings::get_instance();
		$current_settings = $settings_manager->get_settings();

		if ( ! empty( $current_settings ) ) {
			$settings_manager->save_settings( $current_settings, array( 'skip_cp_clear' => true ) );
		}
	}
}

Mati::get_instance();
