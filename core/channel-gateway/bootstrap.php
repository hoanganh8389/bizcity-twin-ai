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
require_once $gateway_dir . 'class-admin-menu.php';

// Boot admin UI (hooks into bizchat_register_menus).
if ( is_admin() ) {
	BizCity_Gateway_Admin::instance();
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
