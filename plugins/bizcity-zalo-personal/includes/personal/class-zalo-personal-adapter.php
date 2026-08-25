<?php
/**
 * BizCity Zalo Personal Adapter — Static bridge for Broadcast Dispatcher (PHASE-0.39 M2)
 *
 * Provides static entry points for BizCity_CRM_Broadcast_Dispatcher actions:
 *   - BizCity_Zalo_Personal_Adapter::send_friend_request( $chat_id, $message )
 *   - BizCity_Zalo_Personal_Adapter::invite_to_group( $group_id, $chat_id )
 *
 * chat_id format: zalop_{bridge_account_id}_{zalo_thread_id}
 *   e.g. zalop_3_5555000000000001
 *   → account_id = '3', user_id = '5555000000000001'
 *
 * Both methods are fail-open: if the sidecar is not ready they return WP_Error
 * with reason bucket matching R-CRON-META (friend_request_error / invite_group_error).
 * The Dispatcher logs via note_event() and continues.
 *
 * R-ZP-2: only bridge URL + token stored in WP; credentials live in sidecar.
 * R-ZP-3: never throw — always return WP_Error or true.
 * PHP 7.4 compat: no union return types.
 *
 * @package BizCity_Zalo_Personal
 * @since   1.0.0
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 M2 — static adapter for broadcast friend_request + invite_group
defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Personal_Adapter {

	const CHAT_ID_PREFIX = 'zalop_';

	// ── Public static API ─────────────────────────────────────────────────

	/**
	 * Send a Zalo Personal friend request.
	 *
	 * Called by BizCity_CRM_Broadcast_Dispatcher::send_one() when action_flags has send_friend_request=true.
	 *
	 * @param string $chat_id   CRM chat_id in form zalop_{account_id}_{user_id}.
	 * @param string $message   Greeting text to include with the friend request.
	 * @return true|WP_Error    true on success; WP_Error with reason bucket on failure.
	 */
	public static function send_friend_request( string $chat_id, string $message ) {
		// [2026-06-07 Johnny Chu] PHASE-0.39 M2 — parse zalop prefix + dispatch to bridge
		$parsed = self::parse_chat_id( $chat_id );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return new WP_Error( 'permission_denied', 'BizCity_Zalo_Bridge_Client not available.' );
		}

		$client = BizCity_Zalo_Bridge_Client::instance();
		if ( ! $client->is_ready_fast() ) {
			return new WP_Error( 'gateway_degraded', 'zca-bridge not configured.' );
		}

		$result = $client->send_friend_request( $parsed['account_id'], $parsed['user_id'], $message );

		if ( empty( $result['success'] ) ) {
			$err_msg = isset( $result['message'] ) ? (string) $result['message'] : 'send_friend_request failed';
			$code    = ! empty( $result['_degraded'] ) ? 'gateway_degraded' : 'friend_request_error';
			return new WP_Error( $code, $err_msg );
		}

		return true;
	}

	/**
	 * Invite a Zalo user to a group via Personal account.
	 *
	 * Called by BizCity_CRM_Broadcast_Dispatcher::send_one() when action_flags has invite_group=true.
	 *
	 * @param string $group_id  Zalo group ID (plain, not prefixed).
	 * @param string $chat_id   CRM chat_id in form zalop_{account_id}_{user_id}.
	 * @return true|WP_Error    true on success; WP_Error with reason bucket on failure.
	 */
	public static function invite_to_group( string $group_id, string $chat_id ) {
		// [2026-06-07 Johnny Chu] PHASE-0.39 M2 — parse zalop prefix + dispatch to bridge
		$parsed = self::parse_chat_id( $chat_id );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return new WP_Error( 'permission_denied', 'BizCity_Zalo_Bridge_Client not available.' );
		}

		$client = BizCity_Zalo_Bridge_Client::instance();
		if ( ! $client->is_ready_fast() ) {
			return new WP_Error( 'gateway_degraded', 'zca-bridge not configured.' );
		}

		$result = $client->invite_to_group( $parsed['account_id'], $group_id, $parsed['user_id'] );

		if ( empty( $result['success'] ) ) {
			$err_msg = isset( $result['message'] ) ? (string) $result['message'] : 'invite_to_group failed';
			$code    = ! empty( $result['_degraded'] ) ? 'gateway_degraded' : 'invite_group_error';
			return new WP_Error( $code, $err_msg );
		}

		return true;
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Parse a zalop_ chat_id into { account_id, user_id }.
	 *
	 * Format: zalop_{bridge_account_id}_{zalo_thread_id}
	 * The bridge_account_id may not contain underscores; thread_id may.
	 * Strategy: first segment after prefix is account_id, rest is user_id.
	 *
	 * @param string $chat_id
	 * @return array|WP_Error  array{ account_id:string, user_id:string } or WP_Error.
	 */
	private static function parse_chat_id( string $chat_id ) {
		// [2026-06-07 Johnny Chu] PHASE-0.39 M2 — parse zalop_{account_id}_{user_id}
		$prefix = self::CHAT_ID_PREFIX;
		$plen   = strlen( $prefix );

		if ( substr( $chat_id, 0, $plen ) !== $prefix ) {
			return new WP_Error(
				'invalid_param',
				'chat_id does not have zalop_ prefix (got: ' . esc_html( substr( $chat_id, 0, 20 ) ) . ')'
			);
		}

		$remainder = substr( $chat_id, $plen ); // "{account_id}_{user_id}"
		$sep       = strpos( $remainder, '_' );

		if ( $sep === false || $sep === 0 ) {
			return new WP_Error( 'invalid_param', 'chat_id malformed — missing account_id segment.' );
		}

		$account_id = substr( $remainder, 0, $sep );
		$user_id    = substr( $remainder, $sep + 1 );

		if ( $user_id === '' ) {
			return new WP_Error( 'invalid_param', 'chat_id malformed — missing user_id segment.' );
		}

		return array(
			'account_id' => $account_id,
			'user_id'    => $user_id,
		);
	}
}
