<?php
/**
 * Plugin Name: BizCity Chat Web
 * Plugin URI:  https://bizcity.vn/
 * Description: WebChat channel extension shell for the BizCity Channel Gateway. Public widget/runtime extraction starts in PHASE-0.41A; CRM Inbox remains owned by bizcity-twin-crm.
 * Version:     0.1.0
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Author:      BizCity
 * Author URI:  https://bizcity.vn/
 * Text Domain: bizcity-chat-web
 * License:     GPL-2.0-or-later
 *
 * @package BizCity_Chat_Web
 */

defined( 'ABSPATH' ) || exit;

// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-0.41A — establish the WebChat extraction boundary without loading legacy runtime or registering duplicate hooks.
if ( ! defined( 'BIZCITY_CHAT_WEB_VERSION' ) ) {
	define( 'BIZCITY_CHAT_WEB_VERSION', '0.1.0' );
}
if ( ! defined( 'BIZCITY_CHAT_WEB_DIR' ) ) {
	define( 'BIZCITY_CHAT_WEB_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_CHAT_WEB_URL' ) ) {
	define( 'BIZCITY_CHAT_WEB_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_CHAT_WEB_SHELL_ONLY' ) ) {
	define( 'BIZCITY_CHAT_WEB_SHELL_ONLY', true );
}

// The shell intentionally has no bootstrap, hooks, routes, tables, or legacy
// module loader until an extraction slice owns a specific public surface.
