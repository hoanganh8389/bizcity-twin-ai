<?php
/**
 * BizCity Zalo Personal & OA Gateway — Bootstrap
 *
 * Load order (PHASE-0.39):
 *  1. shared/  — Bridge client, mapping repo, inbound emitter, hook log, REST controller
 *  2. personal/ — Zalo Personal integration + broadcast adapter
 *  3. Hooks: register the Personal integration + platform tile
 *
 * Module layout (2026-06-14 split):
 * includes/shared/   — Personal bridge infrastructure
 *  includes/personal/ — Zalo cá nhân (QR login via zca-bridge sidecar)
 *
 * @package BizCity_Zalo_Personal
 * @since   1.0.0
 * @see     docs/ARCHITECTURE.md
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 — bootstrap entry
// [2026-06-14 Johnny Chu] PHASE-0.39 — refactored to module split (shared/personal/oa)
// [2026-08-23 Johnny Chu] PHASE-0.39D — this plugin owns Personal only; OA is loaded by its own plugin.
defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_ZALO_PERSONAL_BOOTSTRAP_LOADED' ) ) {
	return;
}
define( 'BIZCITY_ZALO_PERSONAL_BOOTSTRAP_LOADED', true );

$_shared   = BIZCITY_ZALO_PERSONAL_DIR . 'includes/shared/';
$_personal = BIZCITY_ZALO_PERSONAL_DIR . 'includes/personal/';

// ── 1. Shared infrastructure (load first — no channel-specific deps) ──────
require_once $_shared . 'class-zalo-mapping-repo.php';
require_once $_shared . 'class-zalo-personal-hub-client.php';
require_once $_shared . 'class-zalo-bridge-client.php';
require_once $_shared . 'class-zalo-hook-log.php';
require_once $_shared . 'class-zalo-inbound-emitter.php';
require_once $_shared . 'class-zalo-bridge-rest.php';

// ── 2. Personal module ────────────────────────────────────────────────────
require_once $_personal . 'class-zalo-personal-integration.php';
// [2026-06-07 Johnny Chu] PHASE-0.39 M2 — static adapter for Broadcast Dispatcher (friend_request + invite_group).
require_once $_personal . 'class-zalo-personal-adapter.php';

unset( $_shared, $_personal );

// [2026-08-21 Johnny Chu] PHASE-0.39B — provision mapping schema on activation and REST maintenance context.
register_activation_hook( BIZCITY_ZALO_PERSONAL_DIR . 'bizcity-zalo-personal.php', array( 'BizCity_Zalo_Mapping_Repo', 'maybe_install' ) );
add_action( 'admin_init', array( 'BizCity_Zalo_Mapping_Repo', 'maybe_install' ) );
add_action( 'rest_api_init', array( 'BizCity_Zalo_Mapping_Repo', 'maybe_install' ), 1 );

// Register channel integrations with Gateway Bridge + Integration Registry.
add_action( 'bizcity_register_integrations', static function ( $registry ) {
	( new BizCity_Zalo_Personal_Integration() )->register_with_gateway( $registry );
}, 25 );

// Add platform tiles to Channel Gateway SPA catalog.
add_filter( 'bizcity_channel_platform_catalog', static function ( array $catalog ): array {
	// [2026-06-14 Johnny Chu] PHASE-0.39 — ready = plugin loaded (class exists),
	// NOT sidecar connected. Bridge config status shown inside the channel's own settings tab.
	// was: BizCity_Zalo_Bridge_Client::instance()->is_ready_fast() → false when URL/token blank → "SOON" bug.
	$plugin_ready = class_exists( 'BizCity_Zalo_Bridge_Client' );

	$catalog[] = array(
		'code'     => 'zalo_personal',
		'label'    => 'Zalo Cá nhân',
		'platform' => 'ZALO_PERSONAL',
		'icon'     => 'zalo',
		'group'    => 'social',
		'zone'     => 'customer',
		'ready'    => $plugin_ready,
		'desc'     => 'Tài khoản Zalo cá nhân — đăng nhập QR qua zca-bridge. Nhận & gửi tin vào CRM Inbox.',
	);
	// [2026-06-30 Johnny Chu] HOTFIX — zalo_oa tile đã có trong class-admin-menu-spa.php catalog;
	// entry này tạo tile trùng 'Zalo OA (OAuth)' → removed per user spec.
	return $catalog;
} );

// Inject zaloBridge config into SPA BOOT data.
add_filter( 'bizcity_cg_boot_data', static function ( array $boot ): array {
	// [2026-06-07 Johnny Chu] PHASE-0.39 — expose bridge readiness + REST prefix to SPA.
	$bridge_ok = class_exists( 'BizCity_Zalo_Bridge_Client' )
		&& BizCity_Zalo_Bridge_Client::instance()->is_ready_fast();

	$boot['zaloBridge'] = array(
		'ready'      => $bridge_ok,
		'restPrefix' => 'zalo-bridge',
	);
	return $boot;
} );

// [2026-06-14 Johnny Chu] PHASE-0.39 — call init() directly so it can add its rest_api_init hook
// BEFORE rest_api_init fires. Double-hook (add_action inside add_action on same hook) doesn't work
// because WordPress snapshots the priority list at hook dispatch time.
// Pattern: php-require-once-init-pattern.md
BizCity_Zalo_Bridge_REST::init();
