<?php
/**
 * Channel Gateway — Bootstrap
 *
 * Core infrastructure for multi-channel messaging:
 *  - Adapter interface + registry
 *  - Inbound webhook routing
 *  - Outbound unified send
 *  - User + Blog resolvers (multisite)
 *
 * Channel plugins register adapters via:
 *   add_action('bizcity_register_channel', function($bridge){ $bridge->register_adapter(...); });
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' ) ) {
	return;
}
define( 'BIZCITY_CHANNEL_GATEWAY_LOADED', true );

$gateway_dir = __DIR__ . '/includes/';

require_once $gateway_dir . 'interface-channel-adapter.php';
require_once $gateway_dir . 'class-gateway-bridge.php';
require_once $gateway_dir . 'class-gateway-sender.php';
require_once $gateway_dir . 'class-user-resolver.php';
require_once $gateway_dir . 'class-blog-resolver.php';
require_once $gateway_dir . 'class-channel-role.php';
require_once $gateway_dir . 'class-integration.php';
require_once $gateway_dir . 'class-integration-registry.php';

// PHASE 0.37 — Shared Channel API (load BEFORE admin-menu so menu sees channels).
require_once $gateway_dir . 'class-channel-adapter-base.php';
require_once $gateway_dir . 'class-channel-integration.php';
require_once $gateway_dir . 'class-channel-menu-registry.php';

// PHASE 0.37 M3.W3 — Built-in stub adapters for legacy platforms.
$adapters_dir = $gateway_dir . 'adapters/';
$webchat_dir  = $gateway_dir . 'webchat/';
require_once $adapters_dir . 'class-telegram-adapter.php';
require_once $webchat_dir  . 'class-webchat-adapter.php';
require_once $adapters_dir . 'class-adminchat-adapter.php';
require_once $adapters_dir . 'class-email-smtp-adapter.php';
require_once $adapters_dir . 'class-zalo-hotline-adapter.php';

// PHASE 0.36 W1 — WebChat-as-Channel auto-binding bootstrap.
// Ensures `_bizcity_channel_bindings` has a row for (blog_id, WEBCHAT, blog_id)
// on first webchat message so Universal Listener can resolve a Guru.
require_once $webchat_dir . 'class-webchat-binding-bootstrap.php';

// PHASE 0.36 W4 — WebChat Inbox REST (admin SPA bubble UI backend).
require_once $webchat_dir . 'class-webchat-inbox-rest.php';

// PHASE 0.37 M4 — Full BizCity_Channel_Integration adapters (credential storage + send + webhook).
require_once $adapters_dir . 'class-facebook-page-integration.php';
require_once $adapters_dir . 'class-zalo-bot-oa-integration.php';

// PHASE 0.37 M4.1 — Hand-off bridge between gateway accounts and the legacy
// bizcity-facebook-bot OAuth flow (?biz_fb_oauth=user_start/callback).
require_once $adapters_dir . 'class-facebook-page-oauth-bridge.php'; 

// Phase 5 — Facebook SPA tab system bridge REST (pages/bots/history/test-send/post).
require_once $adapters_dir . 'class-facebook-page-rest.php';
BizCity_Facebook_Page_REST::init(); // Always call after require_once — file-scope init() at end of file won't run on second require_once.

// PHASE 0.37 M1.W2 — Migrate 13 bizcity-channels submenus → bizchat-gateway hub.
require_once $gateway_dir . 'class-channel-menu-migrate.php';

// 2026-06-04 — Unified Google Hub admin page (group=integrations,sub=google-hub).
require_once $gateway_dir . 'class-google-hub-page.php';

require_once $gateway_dir . 'class-admin-menu.php';
require_once $gateway_dir . 'class-admin-menu-spa.php';

// PHASE 0.31 Sprint 6 — T-S4.3 / T-S6.1 / T-S6.3 always-load.
require_once $gateway_dir . 'class-network-oauth-page.php';
require_once $gateway_dir . 'class-oauth-proxy.php';
require_once $gateway_dir . 'class-channel-messages.php';

// PHASE CG-SCHEDULER v0.2 — FB Publisher bridge to core/scheduler reminder cron.
require_once $gateway_dir . 'class-fb-publisher.php';

// PHASE CG-TASK-UNIFY v0.1 — Web Post Publisher: event_type='web_post' →
// wp_insert_post. Mirrors FB Publisher pattern (same hook, same metadata
// convention). Contract: core/diagnostics/changelog/core.scheduler.json v3.2.0.
require_once $gateway_dir . 'class-web-post-publisher.php';

// PHASE CG-TASK-UNIFY v0.2 — Zalo Reminder handler + Admin Router + CMD Classifier.
// event_type='reminder_zalo' → bizcity_channel_send via ZaloBotOA integration.
// Admin NLU: bizcity_zalo_message_received → classify → create draft event.
// Contract: core/diagnostics/changelog/core.scheduler.json v3.2.1.
require_once $gateway_dir . 'class-zalo-reminder.php';
require_once $gateway_dir . 'class-cmd-classifier.php';
require_once $gateway_dir . 'class-cg-admin-router.php';

// PHASE CG-TASK-UNIFY v0.3 — Phase 3 handlers (legacy migration).
// event_type='woo_product_create'/'woo_product_edit' → BizCity_Woo_Product_Handler (priority 35).
// event_type='lead_report' → BizCity_Lead_Report_Handler (priority 38).
// event_type='woo_order_create' → BizCity_Woo_Order_Handler (priority 40).
// Contract: core/diagnostics/changelog/core.scheduler.json v3.3.0.
require_once $gateway_dir . 'class-woo-product-handler.php';
require_once $gateway_dir . 'class-lead-report-handler.php';
require_once $gateway_dir . 'class-woo-order-handler.php';

// Debug Logger — JSON-Lines pipeline tracer (uploads/[sites/{id}/]bizcity-cg-logs/).
require_once $gateway_dir . 'class-cg-debug-logger.php';
BizCity_CG_Debug_Logger::init();

// PHASE-N (2026-05-25) — Flows sub-module (port of bizgpt-custom-flows).
// Schema: core/diagnostics/changelog/modules.flows.json
require_once $gateway_dir . 'flows/bootstrap.php';

// PHASE 0.33 M1 — Webhook Router + daily-partition log + Guru bindings.
require_once $gateway_dir . 'class-webhook-log.php';
require_once $gateway_dir . 'class-channel-binding.php';
require_once $gateway_dir . 'class-webhook-router.php';
require_once $gateway_dir . 'class-universal-channel-listener.php';
require_once $gateway_dir . 'class-webhook-inspector.php';
require_once $gateway_dir . 'class-character-page-binding-ui.php';
require_once $gateway_dir . 'class-responder-stamper.php';
require_once $gateway_dir . 'class-inbox-send-rest.php';
require_once $gateway_dir . 'class-webhook-replay.php';

// Phase CG-Listener S1 — live tail bus + SSE for Listener UI + Automation debug.
require_once $gateway_dir . 'listener/class-listener-bus.php';
require_once $gateway_dir . 'listener/class-listener-rest.php';
require_once $gateway_dir . 'listener/class-listener-automation-bridge.php';

// Schema install + admin-side network OAuth page registration.
add_action( 'admin_init', array( 'BizCity_Channel_Messages', 'maybe_install' ) );
add_action( 'admin_init', array( 'BizCity_Channel_Binding',  'maybe_install' ) );

// Boot the Webhook Router (rewrite rules + parse_request intake).
BizCity_Webhook_Router::init();

// Boot the universal channel listener (taps known triggers @ priority 5).
BizCity_Universal_Channel_Listener::init();

// Boot the Webhook Inspector (Tools menu + REST namespace bizcity/cg/v1).
BizCity_Webhook_Inspector::init();

// Phase CG-Listener S1 — Listener Bus + REST (live tail SSE + polling fallback).
BizCity_Listener_Bus::init();
BizCity_Listener_REST::init();
BizCity_Listener_Automation_Bridge::init();

// PHASE 0.37 M3.W3 — Register built-in stub adapters with Gateway Bridge.
// These provide coverage for legacy platforms until full adapters are built (M5).
add_action( 'bizcity_register_channel', function ( $bridge ) {
	$bridge->register_adapter( new BizCity_Telegram_Adapter() );
	$bridge->register_adapter( new BizCity_WebChat_Adapter() );
	$bridge->register_adapter( new BizCity_AdminChat_Adapter() );
	$bridge->register_adapter( new BizCity_Email_SMTP_Adapter() );
	$bridge->register_adapter( new BizCity_Zalo_Hotline_Adapter() );
}, 20 );

// PHASE 0.37 M4 — Register full BizCity_Channel_Integration adapters.
// These provide credential storage + webhook handling + outbound dispatch.
add_action( 'bizcity_register_integrations', function ( $registry ) {
	( new BizCity_Facebook_Page_Integration() )->register_with_gateway( $registry );
	( new BizCity_Zalo_Bot_OA_Integration() )->register_with_gateway( $registry );
}, 20 );

// Inject channel-binding pills into Twin Guru character cards (admin-only).
if ( is_admin() ) {
	BizCity_Character_Page_Binding_UI::init();
}

// PHASE 0.34 M4.2 — Trace manifesto runtime: stamp every outbound with responder.
BizCity_Responder_Stamper::init();
BizCity_Inbox_Send_REST::init();
BizCity_Webhook_Replay::init();

// PHASE CG-SCHEDULER v0.2 — FB Publisher subscribes to bizcity_scheduler_reminder_fire.
// Idempotent + safe to call multiple times (priority 20 → runs after scheduler internals).
BizCity_FB_Publisher::init();

// PHASE CG-TASK-UNIFY v0.1 — Web Post Publisher subscribes at priority 25.
BizCity_Web_Post_Publisher::init();

// PHASE CG-TASK-UNIFY v0.2 — Zalo Reminder + Admin Router.
BizCity_Zalo_Reminder::init();
BizCity_CG_Admin_Router::init();

// PHASE CG-TASK-UNIFY v0.3 — Phase 3 handlers.
BizCity_Woo_Product_Handler::init();
BizCity_Lead_Report_Handler::init();
BizCity_Woo_Order_Handler::init();

// PHASE-0.35 GURU-ZALO-BOT §1.3/§1.5 (2026-05-26) — Channel formatter base +
// Zalo concrete + REST controller fronting BizCity_Guru_Runtime. R-CH-NS
// namespace bizcity-channel/v1. Schema contract: core.persona.json v1.0.0.
require_once $gateway_dir . 'formatters/class-channel-formatter.php';
require_once $gateway_dir . 'formatters/class-zalo-formatter.php';
require_once $gateway_dir . 'class-guru-turn-controller.php';
BizCity_Guru_Turn_Controller::init();

// Register prune cron (TTL 3 days).
BizCity_Webhook_Log::register_cron();
add_action( 'admin_init', function () {
	if ( ! wp_next_scheduled( BizCity_Webhook_Log::CRON_HOOK ) ) {
		BizCity_Webhook_Log::register_cron();
	}
} );

// One-time cleanup: DROP legacy wp_{date}_webhook_log tables left over from
// the DB-based ledger (PHASE 0.33 M1.5 file-based refactor).
add_action( 'admin_init', function () {
	if ( get_option( 'bizcity_webhook_log_legacy_dropped' ) === '1' ) {
		return;
	}
	$dropped = BizCity_Webhook_Log::drop_legacy_tables();
	update_option( 'bizcity_webhook_log_legacy_dropped', '1', false );
	if ( $dropped ) {
		set_transient( 'bizcity_webhook_log_legacy_dropped_notice', $dropped, HOUR_IN_SECONDS );
	}
} );
// Register OAuth page in both site-admin and network-admin contexts.
// No mu-plugin shim needed — channel-gateway bootstrap owns this entirely.
if ( ( is_admin() || is_network_admin() ) && class_exists( 'BizCity_Network_OAuth_Page' ) ) {
	BizCity_Network_OAuth_Page::instance()->register();
}

// PHASE 0.31 — sprint-by-sprint validation page (Tools → Channel GW Sprint Diag)
if ( is_admin() ) {
	require_once $gateway_dir . 'class-sprint-diagnostic.php';
	// PHASE-0.35 / 2026-05-14 — Phase C/D sections live in sibling class.
	$_phase_cd = $gateway_dir . 'class-sprint-diagnostic-phase-cd.php';
	if ( file_exists( $_phase_cd ) ) { require_once $_phase_cd; }
	// PHASE 0.37 — Unify Channel diagnostic (Tools → Channel P0.37 Diag)
	require_once $gateway_dir . 'class-phase-037-diagnostic.php';
}

// Sprint 5.5 (T-S5b.2) — Test-Run single block AJAX endpoint.
require_once $gateway_dir . 'class-test-run-block-api.php';

// PHASE 0.37 — Channel REST API (bizcity-channel/v1).
require_once $gateway_dir . 'class-channel-rest-api.php';
add_action( 'rest_api_init', [ 'BizCity_Channel_REST_API', 'init' ] );

// Boot admin UI (hooks into bizchat_register_menus).
if ( is_admin() ) {
	BizCity_Gateway_Admin::instance();
	BizCity_Gateway_Admin_SPA::instance();
}

/* ─── Helper Functions (public API) ─── */

/**
 * Send a message to any channel — unified API.
 *
 * Backward-compatible replacement for legacy bizcity_gateway_send_message() once
 * channel plugins are in place. During transition, the legacy function still works
 * and both route to the same sender.
 *
 * @param string $chat_id  Chat ID with platform prefix.
 * @param string $message  Message text.
 * @param string $type     'text', 'image', 'file'.
 * @param array  $extra    Extra data.
 * @return array ['sent' => bool, 'error' => string, 'platform' => string]
 */
function bizcity_channel_send( string $chat_id, string $message, string $type = 'text', array $extra = [] ): array {
	return BizCity_Gateway_Sender::instance()->send( $chat_id, $message, $type, $extra );
}

/**
 * Detect platform from chat_id.
 *
 * @param string $chat_id
 * @return string Platform name (uppercase): 'TELEGRAM', 'ZALO_BOT', 'FACEBOOK', etc.
 */
function bizcity_channel_detect_platform( string $chat_id ): string {
	return BizCity_Gateway_Bridge::instance()->detect_platform( $chat_id );
}

/**
 * Get the Gateway Bridge singleton.
 *
 * @return BizCity_Gateway_Bridge
 */
function bizcity_gateway_bridge(): BizCity_Gateway_Bridge {
	return BizCity_Gateway_Bridge::instance();
}

/* ─── PHASE 0.31 T-S3.1 — Brain → Workflow bridge (defensive fallback) ───
 *
 * The canonical registration lives in
 *   core/knowledge/kg-hub/includes/integration-notebook.php
 * but `core/knowledge/bootstrap.php` short-circuits with `return;` when a
 * legacy mu-plugin already loaded `BizCity_Knowledge_Database`, which means
 * kg-hub/bootstrap.php (and hence the bridge) never gets required.
 *
 * Channel-gateway bootstrap always loads, so we expose a named function and
 * register it here. The same function is re-used (and de-duplicated by WP)
 * if kg-hub bootstrap also registers it.
 */
if ( ! function_exists( 'bizcity_twin_notebook_event_bridge' ) ) {
	function bizcity_twin_notebook_event_bridge( $event_subtype, $payload = array() ) {
		static $map = array(
			'note_created' => 'bizcity_twin_note_created',
			'note_updated' => 'bizcity_twin_note_updated',
			'note_tagged'  => 'bizcity_twin_note_tagged',
		);
		$key = isset( $map[ $event_subtype ] ) ? $map[ $event_subtype ] : null;
		if ( ! $key ) { return; }
		do_action( 'waic_twf_process_flow', $key, $payload );
	}
}
add_action( 'bizcity_twin_notebook_event', 'bizcity_twin_notebook_event_bridge', 10, 2 );

/* ─── PHASE 0.31 T-S5.4 — WP Form bridges (DEPRECATED 2026-05-29) ───
 *
 * Bridge to legacy `WaicTrigger_wp_form_submitted` REMOVED — the old
 * `plugins/bizcity-automation` Laravel-style framework is being replaced by
 * `core/automation` (xyflow-based). WP form triggers will be re-ported as
 * native blocks under `core/automation/includes/triggers/`.
 *
 * Kept the no-op `class_exists` guard so any 3rd-party that already calls
 * `register_bridges()` continues to work without fatal.
 */
add_action( 'init', function () {
	if ( class_exists( 'WaicTrigger_wp_form_submitted' ) ) {
		WaicTrigger_wp_form_submitted::register_bridges();
	}
}, 5 );

/* ─── Fire Registration Hook ─── */

/**
 * At plugins_loaded @5 — let channel plugins register adapters.
 *
 * Why @5? Core boots at @0, modules at @1-4, channel plugins register at @5.
 * This ensures all dependencies are available when adapters register.
 */
add_action( 'plugins_loaded', function () {
	$bridge = BizCity_Gateway_Bridge::instance();

	/**
	 * Channel plugins register their adapters via this hook.
	 *
	 * @param BizCity_Gateway_Bridge $bridge The gateway bridge instance.
	 */
	do_action( 'bizcity_register_channel', $bridge );

}, 5 );

/* ─── REST API Routes ─── */

add_action( 'rest_api_init', function () {
	// POST /bizcity/v1/channel/send — Outbound send (admin only)
	register_rest_route( 'bizcity/v1', '/channel/send', [
		'methods'             => 'POST',
		'callback'            => function ( WP_REST_Request $request ) {
			$chat_id = sanitize_text_field( $request->get_param( 'chat_id' ) );
			$message = sanitize_textarea_field( $request->get_param( 'message' ) );
			$type    = sanitize_text_field( $request->get_param( 'type' ) ?: 'text' );

			if ( ! $chat_id || ! $message ) {
				return new WP_REST_Response( [ 'success' => false, 'error' => 'Missing chat_id or message' ], 400 );
			}

			$result = bizcity_channel_send( $chat_id, $message, $type );
			return new WP_REST_Response( [ 'success' => $result['sent'], 'data' => $result ] );
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	] );

	// GET /bizcity/v1/channel/status — Channel health overview (admin only)
	register_rest_route( 'bizcity/v1', '/channel/status', [
		'methods'             => 'GET',
		'callback'            => function () {
			$bridge   = BizCity_Gateway_Bridge::instance();
			$adapters = $bridge->get_adapters();
			$channels = [];

			foreach ( $adapters as $platform => $adapter ) {
				$channels[] = [
					'platform'  => $platform,
					'prefix'    => $adapter->get_prefix(),
					'endpoints' => $adapter->get_endpoints(),
				];
			}

			return new WP_REST_Response( [
				'success' => true,
				'data'    => [
					'total'    => count( $channels ),
					'channels' => $channels,
				],
			] );
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	] );
} );

/* ─── DB Installation ─── */

/**
 * Install global tables on activation (called from BizCity_Twin_AI::activate).
 */
function bizcity_channel_gateway_install(): void {
	BizCity_User_Resolver::maybe_install();
	BizCity_Blog_Resolver::maybe_install_inbox();
}

/* ─── Backward Compat: Override twf_send_message_override ─── */

/**
 * Route twf_telegram_send_message() calls through Gateway Sender
 * when chat_id belongs to webchat, adminchat, or zalo_bot.
 *
 * This replaces the legacy bizcity_gateway_override_send_for_webchat().
 */
add_filter( 'twf_send_message_override', function ( $default, $chat_id, $text ) {
	if ( $default !== false ) {
		return $default;
	}

	$platform = bizcity_channel_detect_platform( $chat_id );

	if ( in_array( $platform, [ 'WEBCHAT', 'ADMINCHAT', 'ZALO_BOT' ], true ) ) {
		$result = bizcity_channel_send( $chat_id, $text );
		return $result['sent'] ? [ 'status' => 'sent', 'platform' => $platform ] : false;
	}

	return false;
}, 7, 3 ); // Priority 7 — before legacy @8

/* ─── Backward Compat: Bridge chat → automation ─── */

/**
 * Bridge: Chat Gateway processed message → fire automation trigger (ADMINCHAT only).
 *
 * WEBCHAT is knowledge-only and should NOT trigger TWF.
 */
add_action( 'bizcity_chat_message_processed', function ( $event_data ) {
	if ( ! is_array( $event_data ) ) {
		return;
	}

	$platform_type = strtolower( $event_data['platform_type'] ?? '' );

	// Only ADMINCHAT triggers automation
	if ( $platform_type !== 'adminchat' ) {
		return;
	}

	// Prevent double-fire
	if ( ! empty( $GLOBALS['bizcity_gateway_trigger_fired'] ) ) {
		return;
	}

	// Prevent infinite loop
	global $waic_current_trigger;
	if ( ! empty( $waic_current_trigger ) ) {
		return;
	}

	$bridge  = BizCity_Gateway_Bridge::instance();
	$trigger = bizcity_gateway_normalize_trigger_compat( $event_data, $platform_type );
	$bridge->fire_trigger( $trigger, (array) $event_data );
}, 9 ); // Priority 9 — before legacy @10

/**
 * Build a legacy-compatible normalized trigger from event data.
 *
 * @param array  $data
 * @param string $platform
 * @return array
 */
function bizcity_gateway_normalize_trigger_compat( array $data, string $platform ): array {
	// If the legacy function exists, delegate to it
	if ( function_exists( 'bizcity_gateway_normalize_trigger' ) ) {
		return bizcity_gateway_normalize_trigger( $data, $platform );
	}

	// Minimal normalization
	return [
		'platform'    => $platform,
		'chat_id'     => $data['session_id'] ?? $data['chat_id'] ?? '',
		'client_id'   => $data['session_id'] ?? $data['client_id'] ?? '',
		'user_id'     => $data['user_id'] ?? '',
		'message'     => $data['user_message'] ?? $data['text'] ?? '',
		'text'        => $data['user_message'] ?? $data['text'] ?? '',
		'client_name' => $data['client_name'] ?? '',
		'raw'         => $data,
	];
}
