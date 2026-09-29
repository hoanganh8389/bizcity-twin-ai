<?php
/**
 * Plugin Name: BizCity Zalo Personal & OA Gateway
 * Plugin URI:  https://bizcity.vn/
 * Description: Kết nối tài khoản Zalo cá nhân (QR login qua zca-bridge sidecar) và Zalo Official Account (OAuth v4 + webhook MAC) vào Channel Gateway + bizcity-twin-crm Inbox.
 * Version:     1.1.0
 * Author:      Johnny Chu / BizCity
 * Author URI:  https://bizcity.vn/
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: bizcity-zalo-personal
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;
define( 'BIZCITY_ZALO_REMOTE_HUB_ENABLED', true );
// Guard: must run after bizcity-twin-ai core is loaded (channel-gateway bootstrap).
if ( ! defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' ) ) {
	add_action( 'admin_notices', static function () {
		echo '<div class="notice notice-error"><p><strong>BizCity Zalo Personal & OA Gateway</strong> yêu cầu plugin <strong>bizcity-twin-ai</strong> được kích hoạt trước.</p></div>';
	} );
	return;
}

define( 'BIZCITY_ZALO_PERSONAL_VERSION', '1.1.0' ); // [2026-08-21 Johnny Chu] PHASE-0.39B — owner binding schema/runtime contract.
define( 'BIZCITY_ZALO_PERSONAL_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BIZCITY_ZALO_PERSONAL_URL',     plugin_dir_url( __FILE__ ) );

// [2026-09-27] PHASE-0.80 doc 27 L-01 #4 — the entry file loads the bootstrap through the Safe Loader too, so a half-written bootstrap.php is a logged skip, not a fatal.
if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
	BizCity_Safe_Loader::require_file( __DIR__ . '/bootstrap.php', 'zalo_personal.bootstrap' );
} else {
	require_once __DIR__ . '/bootstrap.php';
}
