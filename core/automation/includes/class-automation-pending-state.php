<?php
/**
 * BizCity_Automation_Pending_State — multi-turn conversational slot store.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION BE-7.C (2026-05-30)
 *
 * Replaces legacy transient pattern `bizgpt_image_<md5(client_id)>` with a
 * canonical, automation-aware slot keyed by canonical `chat_id` (e.g.
 * `zalo_<id>`, `zalobot_<bot>_<id>`, `fb_<page>_<psid>`, `adminchat_<sess>`).
 *
 * Storage: WP transient (object cache friendly, TTL-backed). No DDL.
 *
 * Schema of payload:
 *   [
 *     'intent'         => string   // free-form slot intent ID, e.g. 'awaiting_post_image'
 *     'workflow_id'    => int      // workflow to resume on next inbound (priority over keyword/fallback)
 *     'slots'          => array    // free-form key/value bag
 *     'attachment_url' => string   // captured media URL (image/file)
 *     'created_at'     => int      // unix
 *   ]
 *
 * TTL: 15 minutes (matches legacy `bizgpt_image_*`).
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Pending_State {

	const PREFIX        = 'bizcity_auto_pending_';
	const DEFAULT_TTL   = 900; // 15 minutes — same as legacy transient.

	private static function key( string $chat_id ): string {
		return self::PREFIX . md5( $chat_id );
	}

	/**
	 * Get current pending state for a chat_id (or empty array).
	 */
	public static function get( string $chat_id ): array {
		if ( $chat_id === '' ) { return array(); }
		$raw = get_transient( self::key( $chat_id ) );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Replace pending state. $payload is merged with `created_at`.
	 */
	public static function set( string $chat_id, array $payload, int $ttl = self::DEFAULT_TTL ): bool {
		if ( $chat_id === '' ) { return false; }
		$payload['created_at'] = time();
		return (bool) set_transient( self::key( $chat_id ), $payload, max( 60, $ttl ) );
	}

	/**
	 * Shallow-merge into existing state (slots[] merged, top-level overwrite).
	 */
	public static function patch( string $chat_id, array $patch, int $ttl = self::DEFAULT_TTL ): bool {
		$cur = self::get( $chat_id );
		if ( isset( $patch['slots'] ) && is_array( $patch['slots'] ) ) {
			$cur['slots'] = array_merge( (array) ( $cur['slots'] ?? array() ), $patch['slots'] );
			unset( $patch['slots'] );
		}
		$next = array_merge( $cur, $patch );
		return self::set( $chat_id, $next, $ttl );
	}

	public static function clear( string $chat_id ): void {
		if ( $chat_id === '' ) { return; }
		delete_transient( self::key( $chat_id ) );
	}

	/**
	 * Returns true iff pending state present AND has a workflow_id to resume.
	 */
	public static function has_resume( string $chat_id ): bool {
		$st = self::get( $chat_id );
		return ! empty( $st['workflow_id'] );
	}
}
