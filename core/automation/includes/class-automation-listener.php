<?php
/**
 * BizCity_Automation_Listener — Test-Listen (capture-first) for FE Builder.
 *
 * BE-6.B — port of legacy WAIC `waic_workflow_listen_trigger` admin-ajax
 * pattern sang REST namespace `bizcity-automation/v1`.
 *
 * FE flow:
 *   1. User mở Builder → click "Chạy thử" trên một trigger node.
 *   2. FE POST /test/listen { trigger_code, node_id, settings, ttl_seconds }
 *      → BE trả về { listener_id, expires_at }.
 *   3. FE polling GET /test/poll?listener_id=... mỗi 2s.
 *   4. User send real message qua channel (Zalo/FB/Webhook…) →
 *      hook `bizcity_channel_message_received` (hoặc webhook fire) match
 *      trigger_code → ghi payload vào transient.
 *   5. FE thấy `status=captured`, replay payload vào canvas debug panel
 *      hoặc POST /workflows/{id}/run với payload đó.
 *   6. FE bấm "Stop" → POST /test/stop { listener_id }.
 *
 * Storage: transient `bizcity_automation_listener_<lid>`. TTL 30..900s.
 *
 * Capture priority: priority 1 trên `bizcity_channel_message_received` để
 * chạy TRƯỚC matcher (BE-4). Capture-only, KHÔNG short-circuit matcher
 * (workflow vẫn enqueue như bình thường).
 *
 * R-CH-NS: KHÔNG đăng ký route ở namespace `bizcity-channel/v1`. Module này
 * thuộc Automation, dùng `bizcity-automation/v1` qua REST class.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION BE-6 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Listener {

	const TRANSIENT_PREFIX = 'bizcity_automation_listener_';
	const INDEX_OPTION     = 'bizcity_automation_listener_active';
	const MAX_ACTIVE       = 10; // hard cap to prevent runaway transients.

	public static function init(): void {
		// (A) Canonical Gateway Bridge path — only fires when inbound goes through
		//     BizCity_Gateway_Bridge::handle_inbound() (rare for Zalo standalone).
		add_action( 'bizcity_channel_message_received', array( __CLASS__, 'on_channel_capture' ), 1, 1 );

		// (B) UCL normalized envelope — canonical for everything bridged via
		//     waic_twf_process_flow (Zalo bot direct + FB + WebChat). Fires
		//     at priority 6 in UCL::on_trigger, after envelope is built.
		add_action( 'bizcity_channel_normalized', array( __CLASS__, 'on_channel_normalized' ), 1, 2 );

		// (C) Direct platform-specific fallbacks — belt-and-suspenders for the
		//     same scenario as CG Listener Bus hotfix S1.1 (Zalo Bot fires
		//     `bizcity_zalo_message_received` directly, may skip UCL on some
		//     environments). Deduped by message id in `capture_for()`.
		add_action( 'bizcity_zalo_message_received',      array( __CLASS__, 'on_zalo_direct' ),       1, 1 );
		add_action( 'bizcity_facebook_message_received',  array( __CLASS__, 'on_fb_message_direct' ), 1, 1 );
		add_action( 'bizcity_facebook_comment_received',  array( __CLASS__, 'on_fb_comment_direct' ), 1, 1 );
		add_action( 'bizcity_facebook_image_received',    array( __CLASS__, 'on_fb_image_direct' ),   1, 1 );

		// (D) Capture webhook fire BEFORE matcher's dispatch (REST callback).
		add_action( 'bizcity_automation_webhook_received', array( __CLASS__, 'on_webhook_capture' ), 1, 2 );

		// (E) Capture TwinBrain intent fire.
		add_action( 'bizcity_twinbrain_intent', array( __CLASS__, 'on_twinbrain_capture' ), 1, 2 );

		// (F) Capture Twin Event Bus stream → automation trigger codes.
		// Priority 25 so Twin_Event_Bus projector (priority 10) writes first,
		// and TwinBrain_Bridge::on_twin_event (priority 20) fan-out runs first
		// (it also calls Listener::inject() itself).
		// This hook is for backup capture when bridge skipped (e.g. workflow
		// disabled) so the FE "Test Listen" panel still sees the event.
		add_action( 'bizcity_twin_event', array( __CLASS__, 'on_twin_event_capture' ), 25, 2 );
	}

	/**
	 * Manual seed — bypass real channel; used by REST `/test/fire` so admin
	 * can verify listener UI without sending a real Zalo/FB message.
	 *
	 * @return int Number of listeners that received the payload.
	 */
	public static function inject( string $trigger_code, array $payload ): int {
		$code = sanitize_key( $trigger_code );
		if ( $code === '' ) { return 0; }
		return self::capture_for( $code, array_merge( array( '_injected' => true ), $payload ) );
	}

	/**
	 * Start a listener. Returns { listener_id, expires_at, trigger_code }.
	 *
	 * @param array{trigger_code:string,node_id?:string,settings?:array,ttl_seconds?:int} $opts
	 * @return array|WP_Error
	 */
	public static function start( array $opts ) {
		$trigger_code = sanitize_key( (string) ( $opts['trigger_code'] ?? '' ) );
		if ( $trigger_code === '' ) {
			return new WP_Error( 'invalid_param', 'trigger_code bắt buộc.', array( 'status' => 422 ) );
		}

		// Enforce hard cap to avoid orphan transients filling up.
		$active = self::active_index();
		if ( count( $active ) >= self::MAX_ACTIVE ) {
			// Auto-evict oldest (LRU).
			$oldest = array_key_first( $active );
			if ( $oldest ) { self::stop( $oldest ); }
		}

		$lid    = wp_generate_uuid4();
		$now    = time();
		$ttl    = max( 30, min( 900, (int) ( $opts['ttl_seconds'] ?? 300 ) ) );
		$record = array(
			'listener_id'   => $lid,
			'trigger_code'  => $trigger_code,
			'node_id'       => sanitize_text_field( (string) ( $opts['node_id'] ?? '' ) ),
			'settings'      => is_array( $opts['settings'] ?? null ) ? $opts['settings'] : array(),
			'status'        => 'listening',
			'created_at'    => $now,
			'expires_at'    => $now + $ttl,
			'captured_at'   => null,
			'captured_payload' => null,
		);

		set_transient( self::TRANSIENT_PREFIX . $lid, $record, $ttl );
		self::index_add( $lid, $trigger_code, $now + $ttl );

		return array(
			'listener_id'  => $lid,
			'trigger_code' => $trigger_code,
			'expires_at'   => $now + $ttl,
			'status'       => 'listening',
		);
	}

	/**
	 * Poll a listener. Returns { status, captured_payload?, expires_at }.
	 *
	 * @return array|WP_Error
	 */
	public static function poll( string $listener_id ) {
		$rec = get_transient( self::TRANSIENT_PREFIX . $listener_id );
		if ( ! is_array( $rec ) ) {
			return new WP_Error( 'expired', 'Listener đã hết hạn hoặc không tồn tại.', array( 'status' => 410 ) );
		}
		return $rec;
	}

	public static function stop( string $listener_id ): bool {
		$ok = delete_transient( self::TRANSIENT_PREFIX . $listener_id );
		self::index_remove( $listener_id );
		return (bool) $ok;
	}

	// ─── Capture hooks ───────────────────────────────────────────────────

	public static function on_channel_capture( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		$platform = strtoupper( (string) ( $payload['platform'] ?? '' ) );
		$role     = strtoupper( (string) ( $payload['channel_role'] ?? '' ) );
		if ( $role === 'ASSISTANT' ) { return; }

		// Map platform → trigger_code (mirror matcher logic).
		$event_subtype = (string) ( $payload['event_subtype'] ?? '' );
		if ( $event_subtype === '' && $platform === 'FACEBOOK' ) {
			$entry         = $payload['raw']['entry'][0] ?? array();
			$event_subtype = ! empty( $entry['messaging'] ) ? 'messenger'
				: ( ! empty( $entry['changes'] ) ? 'feed' : 'unknown' );
		}
		$codes = array();
		if ( strpos( $platform, 'ZALO' ) !== false )     { $codes[] = 'zalo_inbound'; }
		if ( strpos( $platform, 'TELEGRAM' ) !== false ) { $codes[] = 'telegram_inbound'; }
		if ( $platform === 'FACEBOOK' ) {
			$codes[] = $event_subtype === 'feed' ? 'fb_comment' : 'fb_message';
		}

		$mid = (string) ( $payload['mid'] ?? '' );
		foreach ( $codes as $code ) {
			if ( $mid !== '' && self::seen( $code, $mid ) ) { continue; }
			self::capture_for( $code, array(
				'channel'       => $payload['platform'] ?? '',
				'event_subtype' => $event_subtype,
				'text'          => (string) ( $payload['message'] ?? $payload['text'] ?? '' ),
				'instance_id'   => (string) ( $payload['instance_id'] ?? '' ),
				'sender_id'     => (string) ( $payload['sender_id'] ?? '' ),
				'chat_id'       => (string) ( $payload['chat_id'] ?? '' ),
				'mid'           => $mid,
				'raw'           => $payload['raw'] ?? null,
				'_source'       => 'gateway_bridge',
			) );
		}
	}

	public static function on_webhook_capture( string $slug, $payload ): void {
		self::capture_for( 'webhook', array(
			'slug'    => $slug,
			'payload' => $payload,
		) );
	}

	public static function on_twinbrain_capture( string $intent_id, $payload ): void {
		self::capture_for( 'twinbrain_intent', array(
			'intent_id' => $intent_id,
			'payload'   => $payload,
		) );
	}

	/** Map Twin Event Bus key → automation trigger_code for FE Test Listen. */
	private const TWIN_EVENT_CAPTURE_MAP = array(
		'synthesis_done'  => 'twinbrain_turn_completed',
		'final_done'      => 'twinbrain_turn_completed',
		'agent_loop_done' => 'twinbrain_turn_completed',
		'tool_decided'    => 'twinbrain_tool_decided',
	);

	public static function on_twin_event_capture( $event_key, $payload = array() ): void {
		if ( ! is_string( $event_key ) || ! isset( self::TWIN_EVENT_CAPTURE_MAP[ $event_key ] ) ) { return; }
		$code     = self::TWIN_EVENT_CAPTURE_MAP[ $event_key ];
		$payload  = is_array( $payload ) ? $payload : array( '_raw' => $payload );
		$trace_id = (string) ( $payload['trace_id'] ?? '' );
		// Dedup synthesis_done + final_done into single trigger fire per trace.
		if ( $trace_id !== '' && self::seen( $code, $trace_id ) ) { return; }
		self::capture_for( $code, array_merge( $payload, array(
			'_event_key' => $event_key,
			'_source'    => 'twin_event_bus',
		) ) );
	}

	/**
	 * UCL canonical envelope subscriber.
	 *
	 * @param array  $envelope    Normalized {platform, account_id, user_id, chat_id,
	 *                            message_id, message, event_type, raw, ...}.
	 * @param string $trigger_key Original WAIC trigger key.
	 */
	public static function on_channel_normalized( $envelope, $trigger_key = '' ): void {
		if ( ! is_array( $envelope ) ) { return; }
		$platform      = strtoupper( (string) ( $envelope['platform']   ?? '' ) );
		$event_type    = (string)        ( $envelope['event_type'] ?? 'message' );
		$message_id    = (string)        ( $envelope['message_id'] ?? '' );

		$codes = self::map_platform_to_codes( $platform, $event_type );
		foreach ( $codes as $code ) {
			if ( $message_id !== '' && self::seen( $code, $message_id ) ) { continue; }
			self::capture_for( $code, array(
				'channel'       => $platform,
				'event_subtype' => $event_type,
				'text'          => (string) ( $envelope['message']    ?? '' ),
				'instance_id'   => (string) ( $envelope['account_id'] ?? '' ),
				'sender_id'     => (string) ( $envelope['user_id']    ?? '' ),
				'chat_id'       => (string) ( $envelope['chat_id']    ?? '' ),
				'mid'           => $message_id,
				'raw'           => $envelope['raw'] ?? null,
				'_source'       => 'normalized',
				'_trigger_key'  => (string) $trigger_key,
			) );
		}
	}

	/**
	 * Direct fallback: Zalo Bot fires `bizcity_zalo_message_received` with
	 * fields {conversation_id, from_user_id, message_text, message_id}.
	 */
	public static function on_zalo_direct( $message_data ): void {
		if ( ! is_array( $message_data ) ) { return; }
		$mid = (string) ( $message_data['message_id'] ?? $message_data['msg_id'] ?? '' );
		if ( $mid !== '' && self::seen( 'zalo_inbound', $mid ) ) { return; }
		self::capture_for( 'zalo_inbound', array(
			'channel'       => 'ZALO_BOT',
			'event_subtype' => 'message',
			'text'          => (string) ( $message_data['message_text'] ?? $message_data['message'] ?? '' ),
			'instance_id'   => (string) ( $message_data['conversation_id'] ?? $message_data['oa_id'] ?? '' ),
			'sender_id'     => (string) ( $message_data['from_user_id']    ?? $message_data['user_id'] ?? '' ),
			'chat_id'       => (string) ( $message_data['chat_id']         ?? '' ),
			'mid'           => $mid,
			'raw'           => $message_data,
			'_source'       => 'zalo_direct',
		) );
	}

	public static function on_fb_message_direct( $msg ): void {
		self::fb_capture( 'fb_message', $msg, 'message_text' );
	}
	public static function on_fb_comment_direct( $msg ): void {
		self::fb_capture( 'fb_comment', $msg, 'message' );
	}
	public static function on_fb_image_direct( $msg ): void {
		self::fb_capture( 'fb_message', $msg, 'image_url' );
	}

	private static function fb_capture( string $code, $msg, string $text_field ): void {
		if ( ! is_array( $msg ) ) { return; }
		$mid = (string) ( $msg['mid'] ?? $msg['comment_id'] ?? $msg['message_id'] ?? '' );
		if ( $mid !== '' && self::seen( $code, $mid ) ) { return; }
		$is_comment = $code === 'fb_comment';
		self::capture_for( $code, array(
			'channel'       => $is_comment ? 'FB_FEED' : 'FB_MESS',
			'event_subtype' => $is_comment ? 'feed' : 'messenger',
			'text'          => (string) ( $msg[ $text_field ] ?? '' ),
			'instance_id'   => (string) ( $msg['page_id'] ?? '' ),
			'sender_id'     => (string) ( $msg['user_id'] ?? $msg['from_id'] ?? '' ),
			'chat_id'       => '',
			'mid'           => $mid,
			'raw'           => $msg,
			'_source'       => 'fb_direct',
		) );
	}

	private static function map_platform_to_codes( string $platform, string $event_type = 'message' ): array {
		$codes = array();
		if ( strpos( $platform, 'ZALO' )     !== false ) { $codes[] = 'zalo_inbound'; }
		if ( strpos( $platform, 'TELEGRAM' ) !== false ) { $codes[] = 'telegram_inbound'; }
		if ( $platform === 'FB_MESS' || $platform === 'FACEBOOK' ) {
			$codes[] = $event_type === 'comment' ? 'fb_comment' : 'fb_message';
		}
		if ( $platform === 'FB_FEED' ) { $codes[] = 'fb_comment'; }
		return $codes;
	}

	/** Request-scoped dedup so canonical + direct events don't double-capture. */
	private static $seen_mids = array();
	private static function seen( string $code, string $mid ): bool {
		$key = $code . '|' . $mid;
		if ( isset( self::$seen_mids[ $key ] ) ) { return true; }
		self::$seen_mids[ $key ] = true;
		return false;
	}

	/**
	 * Persist first matching capture into all active listeners with same code.
	 *
	 * @return int Number of listeners that flipped from listening → captured.
	 */
	private static function capture_for( string $trigger_code, array $payload ): int {
		$active = self::active_index();
		if ( empty( $active ) ) { return 0; }
		$now   = time();
		$hits  = 0;
		foreach ( $active as $lid => $meta ) {
			if ( ( $meta['code'] ?? '' ) !== $trigger_code ) { continue; }
			$rec = get_transient( self::TRANSIENT_PREFIX . $lid );
			if ( ! is_array( $rec ) )             { self::index_remove( $lid ); continue; }
			if ( $rec['status'] !== 'listening' ) { continue; } // already captured.

			$ttl_remaining = max( 30, (int) ( $rec['expires_at'] - $now ) );
			$rec['status']           = 'captured';
			$rec['captured_at']      = $now;
			$rec['captured_payload'] = $payload;
			set_transient( self::TRANSIENT_PREFIX . $lid, $rec, $ttl_remaining );
			$hits++;
		}
		return $hits;
	}

	// ─── Active index helpers ────────────────────────────────────────────

	private static function active_index(): array {
		$idx = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $idx ) ) { return array(); }
		// GC expired.
		$now    = time();
		$dirty  = false;
		foreach ( $idx as $lid => $meta ) {
			if ( ! is_array( $meta ) || empty( $meta['expires_at'] ) || $meta['expires_at'] < $now ) {
				unset( $idx[ $lid ] );
				$dirty = true;
			}
		}
		if ( $dirty ) { update_option( self::INDEX_OPTION, $idx, false ); }
		return $idx;
	}

	private static function index_add( string $lid, string $code, int $expires_at ): void {
		$idx = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $idx ) ) { $idx = array(); }
		$idx[ $lid ] = array( 'code' => $code, 'expires_at' => $expires_at );
		update_option( self::INDEX_OPTION, $idx, false );
	}

	private static function index_remove( string $lid ): void {
		$idx = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $idx ) || ! isset( $idx[ $lid ] ) ) { return; }
		unset( $idx[ $lid ] );
		update_option( self::INDEX_OPTION, $idx, false );
	}
}
