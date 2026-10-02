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
	BizCity_Safe_Loader::require_file( BIZCITY_ZALO_PERSONAL_DIR . 'includes/remote/class-remote-zalo-normalizer.php', 'zalo_personal.remote.normalizer' );
	BizCity_Safe_Loader::require_file( BIZCITY_ZALO_PERSONAL_DIR . 'includes/remote/class-remote-zalo-poller.php', 'zalo_personal.remote.poller' );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		BizCity_Safe_Loader::require_file( BIZCITY_ZALO_PERSONAL_DIR . 'includes/remote/class-remote-zalo-cli.php', 'zalo_personal.remote.cli' );
	}
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
	// [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-D1 — owner's own 1-1 message (owner-capture@1) → per-number daily KG notebook.
	$_shared . 'class-zalo-personal-knowledge-capture.php',
	$_shared . 'class-zalo-owner-capture-rest.php',
	// [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-1 / CL-D4 — projection packs (Hub → site) + pack invalidation (site → Hub).
	$_shared . 'class-zalo-pack-rest.php',
	$_shared . 'class-zalo-pack-invalidate.php',
	$_shared . 'class-zalo-mcp-bridge-rest.php', // [2026-10-01 Claude Opus 5.5] PHASE-0.88 L3-1 — POST zalo-bridge/mcp (cell → Hub → core/mcp, delegated principal)
	$_shared . 'class-zalo-owner-contact.php', // [2026-09-30] PHASE-0.87 CL-D2 — owner 1-1 contact ⇒ role:owner
	// [2026-10-01 Claude Opus 5.5] PHASE-0.87 W2-1 — staff who may use the Agent on a number (doc 50) + Bot Studio REST.
	$_shared . 'class-zalo-agent-principals.php',
	$_shared . 'class-zalo-staff-principals-rest.php',
	$_shared . 'class-zalo-uid-verify.php', // [2026-10-01] PHASE-0.87 CL-14 — verify a UID by a one-time link (D-TAA-6)
	$_shared . 'class-zalo-deep-analysis.php', // [2026-10-01 Claude Opus 5.5] PHASE-0.87 CL-8 — deep-analysis-job@1 (SEAM-6): 202 + TwinBrain MPR in cron
	// [2026-09-18] PHASE-0.48F U10 — one phone / one Zalo login = one Personal account per site (R-ZP-DUP).
	$_shared . 'class-zalo-duplicate-guard.php',
	// [2026-09-18] R-ZP-ERR — canonical session-state + error catalog (contract zalo-personal-session-errors@1).
	$_shared . 'class-zalo-session-errors.php',
	$_shared . 'class-zalo-bridge-rest.php',
	// [2026-09-27] PHASE-0.81 C1.1–C1.4/C2.2 — notebook version, notebook pack routes (C-4), Guru/notebook change notice to the Hub (C-10).
	$_shared . 'class-zalo-guru-knowledge-version.php',
	$_shared . 'class-zalo-guru-knowledge-rest.php',
	$_shared . 'class-zalo-hub-guru-invalidate.php',
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

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-C0 — load the cheap feature gate and delegate all optional remote artifacts to its safe loader.
if ( class_exists( 'BizCity_Safe_Loader' ) ) {
	BizCity_Safe_Loader::require_file( BIZCITY_ZALO_PERSONAL_DIR . 'includes/remote/class-remote-zalo-feature.php', 'zalo_personal.remote.feature' );
	BizCity_Safe_Loader::require_file( BIZCITY_ZALO_PERSONAL_DIR . 'includes/remote/loader.php', 'zalo_personal.remote.loader' );
}

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
BizCity_Zalo_Hub_Guru_Invalidate::boot(); // [2026-09-27] PHASE-0.81 C1.4/C2.2 — debounced; no-op on sites without a zalo-hub number
BizCity_Zalo_Personal_Knowledge_Capture::boot(); // [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-D1 — cron handler; capture is scheduled only from BizCity_Zalo_Owner_Capture_REST
BizCity_Zalo_Owner_Capture_REST::init(); // [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-D1 — POST zalo-bridge/owner-capture (contract owner-capture@1)
BizCity_Zalo_Pack_REST::init(); // [2026-09-30] PHASE-0.87 CL-1 — GET zalo-bridge/packs[/{kind}]
BizCity_Zalo_MCP_Bridge_REST::init(); // [2026-10-01 Claude Opus 5.5] PHASE-0.88 L3-1 — POST zalo-bridge/mcp?account_id= (bizcity-mcp-bridge@1.0.0)
BizCity_Zalo_Pack_Invalidate::boot(); // [2026-09-30] PHASE-0.87 CL-2 / CL-D4 — notebook change ⇒ Hub packs/invalidate (60 s debounce)
BizCity_Zalo_Agent_Principals::boot(); // [2026-10-01] PHASE-0.87 W2-1 — CRM suspend/reactivate re-projects the bundle
BizCity_Zalo_Staff_Principals_REST::init(); // [2026-10-01] PHASE-0.87 W2-1 — bot/policy/{binding_id}/staff routes
BizCity_Zalo_Uid_Verify::init(); // [2026-10-01] PHASE-0.87 CL-14 — POST bot/policy/{binding_id}/verify-uid + ?bizcity_uid_verify= landing
BizCity_Zalo_Deep_Analysis::init(); // [2026-10-01 Claude Opus 5.5] PHASE-0.87 CL-8 — POST zalo-bridge/deep-analysis + cron worker → Hub zalo-hub/deep-analysis/result

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
	$boot['remoteZalo'] = array(
		'available' => class_exists( 'BizCity_Remote_Zalo_Feature' ) && BizCity_Remote_Zalo_Feature::enabled(),
		'actorId'  => (int) get_current_user_id(),
	);
	return $boot;
} );

// [2026-06-14 Johnny Chu] PHASE-0.39 — call init() directly so it can add its rest_api_init hook
// BEFORE rest_api_init fires. Double-hook (add_action inside add_action on same hook) doesn't work
// because WordPress snapshots the priority list at hook dispatch time.
// Pattern: php-require-once-init-pattern.md
BizCity_Zalo_Bridge_REST::init();
BizCity_Zalo_Guru_Knowledge_REST::init(); // [2026-09-27] PHASE-0.81 C1.1/C1.2 — GET zalo-bridge/guru/{ref}/knowledge[/{nb}]
BizCity_Zalo_Connection_Status::init(); // [2026-09-27] PHASE-0.80 doc 26 OB-2 — GET zalo-connection/status, POST zalo-connection/echo
BizCity_Zalo_Start_Page::init(); // [2026-09-27] PHASE-0.80 doc 26 OB-4
