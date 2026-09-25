<?php
/**
 * BizCity_KG_Channel_Upload_Link_Service — channel-agnostic "instant upload
 * link" capability-URL service for the channel → notebook capture bridge.
 *
 * Problem this solves: some inbound provider events (e.g. Zalo Bot Platform's
 * `message.unsupported.received`) carry NO downloadable media URL at all, so
 * the normal sideload-from-URL capture path cannot run. Instead of silently
 * dropping the file, the channel listener mints a short-lived, capability-URL
 * "upload link" the user can open in a browser to upload the file directly
 * to this server (→ WP Media), which then mounts into the SAME pending-inbox
 * / open-session transient state the rest of the capture pipeline already
 * uses (see `BizCity_KG_Channel_Notebook_Bridge`).
 *
 * SECURITY MODEL — "instant link" (no login required), chosen deliberately
 * over a login-gated upload page. See docs/roadmaps/PHASE-0.46-CHANNEL-NOTEBOOK-BRIDGE.md
 * §Wave 6 for the full security tradeoff writeup. Summary of mitigations
 * implemented here (defense in depth for a capability URL):
 *   - Token is a 48-hex-char (24-byte) CSPRNG value (`random_bytes()`), never
 *     guessable/enumerable, never derived from user_id/chat_id/message_id.
 *   - Token is opaque server-side state (WP transient) — nothing in the URL
 *     itself grants access without a matching, unexpired, not-yet-exhausted
 *     server record. This mirrors WordPress's own password-reset-link pattern.
 *   - Hard TTL (`$ttl`, default matches the existing 20-25 min pending/session
 *     window so it never outlives the conversation it belongs to).
 *   - Hard open-count budget (`$max_opens`, default 5) — each GET render of
 *     the page consumes one open; once exhausted the token is deleted
 *     server-side and can never be reopened, bounding the blast radius of a
 *     leaked/forwarded link even within its TTL.
 *   - Token is bound at mint time to (channel, chat_key, bot_id, wp_user_id,
 *     mode, notebook_id?) — the upload handler NEVER trusts client-supplied
 *     identity, only what is stored server-side against the token.
 *   - No new DB table/schema (R-DCL avoided) — pure transient, auto-expires.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub
 * @since      PHASE-0.46 Wave 6
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_KG_Channel_Upload_Link_Service', false ) ) {
	return;
}

class BizCity_KG_Channel_Upload_Link_Service {

	/** Default TTL — mirrors the bridge's own pending/session window (25 min). */
	const DEFAULT_TTL = 1500;

	/** Default max number of times the link page may be opened before it is invalidated. */
	const DEFAULT_MAX_OPENS = 5;

	const TRANSIENT_PREFIX = 'bizcity_kg_upload_link_';

	/**
	 * Mint a new instant upload-link token bound to the given context.
	 *
	 * @param array $ctx {
	 *   channel:string        REQUIRED e.g. 'zalobot'.
	 *   chat_key:string       REQUIRED provider-level conversation id (matches
	 *                         the bridge's own inbox/session chat_key).
	 *   provider_chat_id:string Raw provider chat id used to send the reply.
	 *   bot_id:int            Channel account/bot id (used to send confirmations).
	 *   wp_user_id:int        REQUIRED resolved WP user — never mint for user_id=0.
	 *   mode:string           'pending'|'session'. 'pending' → mount into the
	 *                         bridge's pre-trigger inbox. 'session' → mount
	 *                         directly into an ALREADY-OPEN capture session
	 *                         (Dạng 2 — "@ghichu" issued first).
	 *   automation_chat_id:string Optional, mirrored to Automation Pending State.
	 * }
	 * @return array|WP_Error { token, expires_at (unix ts), max_opens }
	 */
	public static function create( array $ctx, int $ttl = self::DEFAULT_TTL, int $max_opens = self::DEFAULT_MAX_OPENS ) {
		$channel    = sanitize_key( (string) ( $ctx['channel'] ?? '' ) );
		$chat_key   = (string) ( $ctx['chat_key'] ?? '' );
		$wp_user_id = (int) ( $ctx['wp_user_id'] ?? 0 );
		$mode       = (string) ( $ctx['mode'] ?? 'pending' );
		$mode       = in_array( $mode, array( 'pending', 'session' ), true ) ? $mode : 'pending';

		if ( $channel === '' || $chat_key === '' || $wp_user_id <= 0 ) {
			return new WP_Error( 'upload_link_invalid_context', 'Thiếu định danh channel/chat/user — không thể tạo link tải lên.', array( 'status' => 400 ) );
		}

		$ttl       = max( 60, $ttl );
		$max_opens = max( 1, $max_opens );
		$token     = bin2hex( random_bytes( 24 ) ); // 48 hex chars, CSPRNG, not guessable/enumerable.
		$expires_at = time() + $ttl;

		$record = array(
			'channel'            => $channel,
			'chat_key'           => $chat_key,
			'provider_chat_id'   => (string) ( $ctx['provider_chat_id'] ?? $chat_key ),
			'bot_id'             => (int) ( $ctx['bot_id'] ?? 0 ),
			'wp_user_id'         => $wp_user_id,
			'mode'               => $mode,
			'notebook_id'        => (int) ( $ctx['notebook_id'] ?? 0 ),
			'automation_chat_id' => (string) ( $ctx['automation_chat_id'] ?? '' ),
			'opens_remaining'    => $max_opens,
			'max_opens'          => $max_opens,
			'expires_at'         => $expires_at,
			'created_at'         => time(),
			'uploads_count'      => 0,
		);

		set_transient( self::TRANSIENT_PREFIX . $token, $record, $ttl );

		return array(
			'token'      => $token,
			'expires_at' => $expires_at,
			'max_opens'  => $max_opens,
		);
	}

	/**
	 * Peek at a token's context WITHOUT consuming an "open". Used by the
	 * upload POST handler (the open was already consumed by the GET that
	 * rendered the form).
	 *
	 * @return array|WP_Error
	 */
	public static function peek( string $token ) {
		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — allow POST submit on
		// the very last opened form page even when opens_remaining already hit 0.
		return self::load_valid_record( $token, true );
	}

	/**
	 * Validate token AND consume one "open" (call this exactly once per GET
	 * page render). When the final open is consumed, keep a short-lived
	 * "final open armed" marker in the transient so that the already-rendered
	 * HTML form can still submit successfully via POST.
	 *
	 * @return array|WP_Error the record (before decrementing further).
	 */
	public static function resolve_and_consume_open( string $token ) {
		$record = self::load_valid_record( $token, false );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$record['opens_remaining'] = max( 0, (int) $record['opens_remaining'] - 1 );
		$record['_final_open_armed'] = 0;

		if ( $record['opens_remaining'] <= 0 ) {
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — DO NOT delete now.
			// If we delete here, the last opened form page cannot POST anymore.
			// Keep a server-side one-shot marker to allow the already-open form
			// to submit; new GET requests are still blocked because load_valid_record()
			// requires opens_remaining>0 unless allow_post_when_exhausted=true.
			$record['_final_open_armed'] = 1;
			$record['_final_open_armed_at'] = time();
			$record['_final_open'] = true;
		}

		$remaining_ttl = max( 1, (int) $record['expires_at'] - time() );
		set_transient( self::TRANSIENT_PREFIX . $token, $record, $remaining_ttl );
		return $record;
	}

	/**
	 * Record a successful upload against the token (for audit/observability
	 * only — does NOT affect the open-count budget, which is consumed by
	 * page views, not by upload attempts).
	 */
	public static function record_upload( string $token, int $count = 1 ): void {
		$key    = self::TRANSIENT_PREFIX . $token;
		$record = get_transient( $key );
		if ( ! is_array( $record ) ) {
			return; // token already exhausted/expired — nothing to update.
		}
		$record['uploads_count'] = (int) ( $record['uploads_count'] ?? 0 ) + max( 0, $count );
		$remaining_ttl = max( 1, (int) $record['expires_at'] - time() );
		set_transient( $key, $record, $remaining_ttl );
	}

	/** Force-invalidate a token immediately (e.g. after a deliberate "done" action). */
	public static function invalidate( string $token ): void {
		delete_transient( self::TRANSIENT_PREFIX . $token );
	}

	/**
	 * @return array|WP_Error
	 */
	private static function load_valid_record( string $token, bool $allow_post_when_exhausted = false ) {
		$token = trim( $token );
		if ( $token === '' || ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) {
			return new WP_Error( 'upload_link_invalid', 'Link tải lên không hợp lệ.', array( 'status' => 404 ) );
		}
		$record = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $record ) ) {
			return new WP_Error( 'upload_link_expired', 'Link tải lên đã hết hạn hoặc đã hết lượt mở.', array( 'status' => 410 ) );
		}
		if ( (int) ( $record['expires_at'] ?? 0 ) < time() ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
			return new WP_Error( 'upload_link_expired', 'Link tải lên đã hết hạn.', array( 'status' => 410 ) );
		}
		if ( (int) ( $record['opens_remaining'] ?? 0 ) <= 0 ) {
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX — allow only POST
			// submit path after the last GET has already opened and armed it.
			if ( $allow_post_when_exhausted && ! empty( $record['_final_open_armed'] ) ) {
				return $record;
			}
			delete_transient( self::TRANSIENT_PREFIX . $token );
			return new WP_Error( 'upload_link_exhausted', 'Link tải lên đã hết lượt mở (tối đa ' . (int) ( $record['max_opens'] ?? 0 ) . ' lần).', array( 'status' => 410 ) );
		}
		return $record;
	}
}
