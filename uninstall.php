<?php
/**
 * プラグインアンインストール時の処理
 */

// 直接アクセスを防止
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 設定を完全に削除
delete_option( 'mati_settings' );

// キャッシュをクリア（念のため）
wp_cache_flush();
