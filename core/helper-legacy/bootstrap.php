<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Helper Legacy — Bootstrap
 *
 * Loads ALL legacy classes, functions, and flow helpers migrated from
 * mu-plugins/bizcity-admin-hook/. Every file uses class_exists() or
 * function_exists() guards, so whichever plugin loads first wins.
 *
 * When this constant is defined, the mu-plugin skips its own boot.
 *
 * @package BizCity_Twin_AI
 * @since   2.5.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent double-loading
if ( defined( 'BIZCITY_HELPER_LEGACY_LOADED' ) ) {
	return;
}
define( 'BIZCITY_HELPER_LEGACY_LOADED', true );

$legacyDir = __DIR__ . '/';
$flowsDir  = __DIR__ . '/flows/';

// ══════════════════════════════════════════════════════════════════════════════
// 1. Legacy Classes
// ══════════════════════════════════════════════════════════════════════════════

require_once $legacyDir . 'legacy_class-ai-router.php';
require_once $legacyDir . 'legacy_class-telegram.php';
require_once $legacyDir . 'legacy_class-maintenance.php';
require_once $legacyDir . 'legacy_class-webchat.php';
require_once $legacyDir . 'legacy_class-adminmenu.php';

// Intent Provider requires base class from the intent engine
if ( class_exists( 'BizCity_Intent_Provider' ) ) {
	require_once $legacyDir . 'legacy_class-intent-provider.php';
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. Compat wrapper functions
// ══════════════════════════════════════════════════════════════════════════════

require_once $legacyDir . 'legacy_functions-compat.php';

// ══════════════════════════════════════════════════════════════════════════════
// 3. Flow Router — twf_process_flow_from_params()
// ══════════════════════════════════════════════════════════════════════════════

require_once $legacyDir . 'legacy_flow-router.php';

// ══════════════════════════════════════════════════════════════════════════════
// 4. Legacy Flow Helpers (from flows/ directory)
// ══════════════════════════════════════════════════════════════════════════════

// ── Core content & creation helpers ──────────────────────────────────────────
if ( ! function_exists( 'biz_create_content' ) ) {
	require_once $flowsDir . 'legacy_content.php';
}

if ( ! function_exists( 'biz_create_task' ) ) {
	require_once $flowsDir . 'legacy_bizgpt_task.php';
}

if ( ! function_exists( 'biz_create_doc' ) ) {
	require_once $flowsDir . 'legacy_bizgpt_doc.php';
}

// Skip legacy facebook flow if bizcity-tool-facebook plugin is active
if ( ! defined( 'BZTOOL_FB_VERSION' ) && ! function_exists( 'biz_create_facebook' ) ) {
	require_once $flowsDir . 'legacy_bizgpt_facebook.php';
}

// ── Messaging & image helpers ────────────────────────────────────────────────
if ( ! function_exists( 'biz_send_message' ) ) {
	require_once $flowsDir . 'legacy_functions.php';
}

// ── WooCommerce helpers ──────────────────────────────────────────────────────
if ( ! function_exists( 'biz_create_product' ) ) {
	require_once $flowsDir . 'legacy_woo.php';
}

if ( ! function_exists( 'biz_create_order' ) ) {
	require_once $flowsDir . 'legacy_orders.php';
}

// ── Reporting / statistics helpers ───────────────────────────────────────────
if ( ! function_exists( 'twf_get_order_stats_range' ) ) {
	require_once $flowsDir . 'legacy_thongke.php';
}

if ( file_exists( $flowsDir . 'legacy_thongke_hanghoa.php' ) && ! function_exists( 'twf_bao_cao_top_product' ) ) {
	require_once $flowsDir . 'legacy_thongke_hanghoa.php';
}

if ( file_exists( $flowsDir . 'legacy_thongke_khachhang.php' ) && ! function_exists( 'twf_bao_cao_top_customers' ) ) {
	require_once $flowsDir . 'legacy_thongke_khachhang.php';
}

if ( file_exists( $flowsDir . 'legacy_thongke_xnt.php' ) && ! function_exists( 'twf_parse_phieu_nhap_kho_ai' ) ) {
	require_once $flowsDir . 'legacy_thongke_xnt.php';
}

// ── Scheduling & customer helpers ────────────────────────────────────────────
if ( file_exists( $flowsDir . 'legacy_lenlich.php' ) && ! function_exists( 'twf_parse_schedule_post_ai' ) ) {
	require_once $flowsDir . 'legacy_lenlich.php';
}

if ( ! function_exists( 'twf_telegram_users_admin_page' ) ) {
	require_once $flowsDir . 'legacy_shortcode_login.php';
}

if ( ! function_exists( 'twf_handle_find_customer_order_by_phone' ) ) {
	require_once $flowsDir . 'legacy_khachhang.php';
}

// ── Task list & FAQ ──────────────────────────────────────────────────────────
if ( file_exists( $flowsDir . 'legacy_tasklist.php' ) && ! function_exists( 'waic_ai_build_tasklist_json' ) ) {
	require_once $flowsDir . 'legacy_tasklist.php';
}

$faqFile = $flowsDir . 'legacy_action_faq.php';
if ( file_exists( $faqFile ) ) {
	require_once $faqFile;
}

// ── Admin menu (legacy callbacks) ────────────────────────────────────────────
if ( file_exists( $flowsDir . 'legacy_admin_menu.php' ) && ! function_exists( 'twf_telegram_bot_guide_page' ) ) {
	require_once $flowsDir . 'legacy_admin_menu.php';
}

// ══════════════════════════════════════════════════════════════════════════════
// 5. Register Hooks — same registrations as the mu-plugin boot
// ══════════════════════════════════════════════════════════════════════════════

BizCity_AdminHook_Maintenance::register();
BizCity_AdminHook_Telegram::register();
BizCity_AdminHook_AdminMenu::register();

// Register Intent Provider
if ( class_exists( 'BizCity_AdminHook_Intent_Provider' ) ) {
	add_action( 'bizcity_intent_register_providers', function( $registry ) {
		$registry->register( new BizCity_AdminHook_Intent_Provider() );
	} );
}

unset( $legacyDir, $flowsDir, $faqFile );
