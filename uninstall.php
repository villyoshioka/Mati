<?php
/**
 * プラグインアンインストール時の処理
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'mati_settings' );
delete_transient( 'mati_force_protection' );
wp_cache_flush();
