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

// [2026-09-27] CORE-REDUCTION WP-04 / PHASE-0.80 doc 27 L-01 #4 — every file goes through BizCity_Safe_Loader.
// A non-atomic upload (2026-09-26 15:10 UTC: bootstrap.php landed before class-zalo-account-flags.php) was a
// site-wide "Failed opening required" fatal. Now a missing or half-written file makes this plugin inert instead
// (no claim filter, no REST routes, no cron): Bot Studio and the CRM keep running and the sidecar retries.
$_bizcity_zp_files = array(
	// ── 1. Shared infrastructure (load first — no channel-specific deps) ──
	$_shared . 'class-zalo-mapping-repo.php',
	// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-2/4a-5/4a-6 (D-L43) — per-account provider + AI flags, and zalo-hub events other than inbound_forward.
	$_shared . 'class-zalo-account-flags.php',
	$_shared . 'class-zalo-hub-events.php',
	$_shared . 'class-zalo-personal-hub-client.php',
	// [2026-09-26] PHASE-0.80 Lane C 4a-8 — Bot Studio config (persona, FAQ, tools, policy, mode, staff hours) → zalo-hub cell via the Hub.
	$_shared . 'class-zalo-hub-config-sync.php',
	$_shared . 'class-zalo-bridge-client.php',
	$_shared . 'class-zalo-hook-log.php',
	$_shared . 'class-zalo-inbound-emitter.php',
	// [2026-09-18] PHASE-0.48F U10 — one phone / one Zalo login = one Personal account per site (R-ZP-DUP).
	$_shared . 'class-zalo-duplicate-guard.php',
	// [2026-09-18] R-ZP-ERR — canonical session-state + error catalog (contract zalo-personal-session-errors@1).
	$_shared . 'class-zalo-session-errors.php',
	$_shared . 'class-zalo-bridge-rest.php',
	$_shared . 'class-zalo-connection-status.php', // [2026-09-27] PHASE-0.80 doc 26 OB-2 — one owner of the Zalo connection report (L0–L10)
	$_shared . 'class-zalo-start-page.php', // [2026-09-27] PHASE-0.80 doc 26 OB-4 — wp-admin "BizCity — Bắt đầu" (3 steps), activation redirect, dashboard widget
	// [2026-09-19] PHASE-0.60 — periodic reconciliation backstop for cross-site session takeovers
	// the best-effort webhook missed; off by default (see class doc for why).
	$_shared . 'class-zalo-personal-reconciler.php',
	// ── 2. Personal module ──
	$_personal . 'class-zalo-personal-integration.php',
	// [2026-06-07 Johnny Chu] PHASE-0.39 M2 — static adapter for Broadcast Dispatcher (friend_request + invite_group).
	$_personal . 'class-zalo-personal-adapter.php',
);
$_bizcity_zp_ready = class_exists( 'BizCity_Safe_Loader', false );
if ( $_bizcity_zp_ready ) {
	foreach ( $_bizcity_zp_files as $_bizcity_zp_file ) {
		if ( ! BizCity_Safe_Loader::require_file( $_bizcity_zp_file, 'zalo_personal.' . basename( $_bizcity_zp_file, '.php' ) ) ) {
			$_bizcity_zp_ready = false;
			break;
		}
	}
} else {
	error_log( '[BizCity_Zalo_Personal] safe_loader_missing plugin_inert' );
}
unset( $_shared, $_personal, $_bizcity_zp_files, $_bizcity_zp_file );
if ( ! $_bizcity_zp_ready ) {
	unset( $_bizcity_zp_ready );
	return;
}
unset( $_bizcity_zp_ready );

// [2026-09-19] PHASE-0.60 — register cron at file-load time (matches Broadcast Dispatcher's R-CR.1
// convention); no-ops (unschedules) unless `bizcity_zp_reconcile_enabled` is turned on per site.
BizCity_Zalo_Personal_Reconciler::init_cron();

// [2026-08-21 Johnny Chu] PHASE-0.39B — provision mapping schema on activation and REST maintenance context.
register_activation_hook( BIZCITY_ZALO_PERSONAL_DIR . 'bizcity-zalo-personal.php', array( 'BizCity_Zalo_Mapping_Repo', 'maybe_install' ) );
add_action( 'admin_init', array( 'BizCity_Zalo_Mapping_Repo', 'maybe_install' ) );
add_action( 'rest_api_init', array( 'BizCity_Zalo_Mapping_Repo', 'maybe_install' ), 1 );

// [2026-09-26 Claude Opus 5.5] PHASE-0.80 Lane C 4a-6 — Bot Studio (core) asks, this plugin answers: a `zalo_hub` number is
// answered by the Hub-side assistant (`replier_is_zalo_hub`); a number whose AI the Hub switched off (plan downgrade,
// D-L36/D-L43) gets no PHP auto-reply (`ai_disabled`) whatever its provider. Customer messages still reach the CRM.
add_filter( 'bizcity_bot_studio_account_gate', array( 'BizCity_Zalo_Account_Flags', 'filter_bot_gate' ), 10, 3 );

// [2026-09-26] PHASE-0.80 Lane C 4a-8 — debounced on `bizcity_bot_config_changed`, plus a 5-minute fingerprint tick; no-ops on sites without a zalo-hub number.
BizCity_Zalo_Hub_Config_Sync::boot();

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
		'desc'     => 'Tài khoản Zalo cá nhân — quét QR là kết nối, bot AI trả lời qua Zalo Hub (mặc định). Nhận & gửi tin vào CRM Inbox.',
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
BizCity_Zalo_Connection_Status::init(); // [2026-09-27] PHASE-0.80 doc 26 OB-2 — GET zalo-connection/status, POST zalo-connection/echo
BizCity_Zalo_Start_Page::init(); // [2026-09-27] PHASE-0.80 doc 26 OB-4
