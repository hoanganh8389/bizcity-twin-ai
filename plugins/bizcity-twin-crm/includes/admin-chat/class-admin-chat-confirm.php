<?php
/**
 * BizCity CRM — Admin Chat Skill Confirm (Phase 3.5-WC Wave C).
 *
 * Intercepts `bizcity_twinbrain_tool_dispatch_gate` (Wave B hook) to apply
 * the Admin Chat Policy before a skill executes. When the policy returns
 * `confirm`, the skill is blocked and a 2-step HIL (Human-in-the-Loop)
 * confirm token is created:
 *
 *   1. Gate fires → policy says `confirm` → store transient + send reply
 *      "🔔 Xác nhận: [skill]? Reply OK to proceed (hết hạn 10 phút)."
 *   2. User replies "OK" → CG Admin Router / session handler calls
 *      `BizCity_CRM_Admin_Chat_Confirm::resolve($user_id)` which retrieves
 *      the pending token, re-validates policy, and fires
 *      `do_action('bizcity_crm_admin_skill_confirmed', $token_payload)`.
 *
 * Transient key schema:
 *   bizcity_crm_skill_confirm_{user_id}
 *   Value: JSON { tool_slug, tool_class, guru_id, binding_id, chat_id, hint, ts }
 *   TTL: 600 seconds (10 minutes)
 *
 * Audit log statuses used:
 *   confirm_pending  → stored, waiting user reply
 *   confirm_expired  → TTL passed before OK
 *   success          → executed after user confirmed
 *   denied           → policy denied outright
 *   attempted        → gate fired but policy class unavailable (degraded)
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 3.5-WC
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Admin_Chat_Confirm {

	const CONFIRM_TTL = 600; // 10 minutes

	/**
	 * Bootstrap: hook gate filter + confirmed-skill executor.
	 */
	public static function register(): void {
		// [2026-06-13 Johnny Chu] PHASE-0.40 G3 Wave-B — wire policy gate
		add_filter( 'bizcity_twinbrain_tool_dispatch_gate', array( __CLASS__, 'on_dispatch_gate' ), 10, 3 );

		// [2026-06-13 Johnny Chu] PHASE-0.40 G3 Wave-C — execute skill when user confirmed
		add_action( 'bizcity_crm_admin_skill_confirmed', array( __CLASS__, 'on_confirmed' ), 10, 1 );
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Gate filter — Wave B
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * `bizcity_twinbrain_tool_dispatch_gate` filter.
	 *
	 * @param false|string $veto       false = allow, 'confirm_pending' = block, 'denied' = block
	 * @param array        $tool_meta  {slug, tool_class, guru_id, needs_approval, …}
	 * @param array        $ctx        {trace_id, user_id, guru_id, session_id, inbound_chat_id, surface}
	 * @return false|string
	 */
	public static function on_dispatch_gate( $veto, array $tool_meta, array $ctx ) {
		// Already vetoed by a higher-priority filter — respect it.
		if ( false !== $veto ) {
			return $veto;
		}

		$user_id  = (int) ( $ctx['user_id']  ?? 0 );
		$guru_id  = (int) ( $ctx['guru_id']  ?? ( $tool_meta['guru_id'] ?? 0 ) );
		$tool_id  = (string) ( $tool_meta['slug']       ?? '' );
		$tool_cls = strtoupper( (string) ( $tool_meta['tool_class'] ?? 'R' ) );

		if ( ! $user_id || ! $tool_id ) {
			return false; // Not enough context — allow (degraded).
		}

		// Gate only applies to admin-surface or inbound channel surface.
		$surface = (string) ( $ctx['surface'] ?? '' );
		if ( $surface !== '' && ! in_array( $surface, array( 'admin', 'twinbrain', 'channel' ), true ) ) {
			return false;
		}

		if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Policy' ) ) {
			self::log_audit( $user_id, $guru_id, $tool_id, 'attempted', 'policy_class_missing' );
			return false; // Degrade gracefully — allow.
		}

		$policy = BizCity_CRM_Admin_Chat_Policy::evaluate( $user_id, $guru_id, null, $tool_id, $tool_cls );
		$decision = (string) ( $policy['decision'] ?? BizCity_CRM_Admin_Chat_Policy::DECISION_DENY );
		$reason   = (string) ( $policy['reason']   ?? '' );
		$grant_id = isset( $policy['grant_id'] ) ? (int) $policy['grant_id'] : null;

		switch ( $decision ) {
			case BizCity_CRM_Admin_Chat_Policy::DECISION_ALLOW:
				self::log_audit( $user_id, $guru_id, $tool_id, 'attempted', $reason, $grant_id );
				return false; // Allow — execution proceeds.

			case BizCity_CRM_Admin_Chat_Policy::DECISION_DENY:
				self::log_audit( $user_id, $guru_id, $tool_id, 'denied', $reason, $grant_id );
				return 'denied';

			case BizCity_CRM_Admin_Chat_Policy::DECISION_CONFIRM:
				// Store confirm token + send "🔔 Xác nhận?" reply.
				$chat_id = (string) ( $ctx['inbound_chat_id'] ?? '' );
				self::store_confirm_token( $user_id, $guru_id, $tool_id, $tool_cls, $grant_id, $chat_id );
				self::log_audit( $user_id, $guru_id, $tool_id, 'confirm_pending', $reason, $grant_id );
				// Fire action so channel handler can send the confirm message.
				do_action( 'bizcity_crm_admin_skill_confirm_needed', $user_id, $tool_id, $chat_id );
				return 'confirm_pending';

			default:
				return false;
		}
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Confirm token store / retrieve
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Store a pending skill confirm token as a WP transient.
	 */
	public static function store_confirm_token(
		int $user_id,
		int $guru_id,
		string $tool_slug,
		string $tool_class,
		?int $grant_id,
		string $chat_id = ''
	): void {
		$payload = array(
			'tool_slug'  => $tool_slug,
			'tool_class' => $tool_class,
			'guru_id'    => $guru_id,
			'grant_id'   => $grant_id,
			'chat_id'    => $chat_id,
			'ts'         => time(),
		);
		set_transient( self::transient_key( $user_id ), wp_json_encode( $payload ), self::CONFIRM_TTL );
	}

	/**
	 * Retrieve and delete the pending confirm token for a user.
	 * Returns null if not found or expired.
	 *
	 * @return array|null Decoded payload.
	 */
	public static function pop_confirm_token( int $user_id ) {
		$key = self::transient_key( $user_id );
		$raw = get_transient( $key );
		if ( false === $raw ) {
			return null;
		}
		delete_transient( $key );
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		// Guard against stale transient (shouldn't happen, but be safe).
		if ( time() - (int) ( $payload['ts'] ?? 0 ) > self::CONFIRM_TTL ) {
			return null;
		}
		return $payload;
	}

	/**
	 * Called by CG Admin Router (or session handler) when user replies "OK".
	 * Retrieves the pending token, re-validates policy, then fires the
	 * `bizcity_crm_admin_skill_confirmed` action so skills can execute.
	 *
	 * @return bool  true = executed (or attempted), false = no pending token.
	 */
	public static function resolve( int $user_id ): bool {
		$token = self::pop_confirm_token( $user_id );
		if ( ! $token ) {
			return false;
		}

		$tool_slug = (string) ( $token['tool_slug']  ?? '' );
		$tool_cls  = (string) ( $token['tool_class'] ?? 'R' );
		$guru_id   = (int)    ( $token['guru_id']    ?? 0 );
		$grant_id  = isset( $token['grant_id'] ) ? (int) $token['grant_id'] : null;
		$chat_id   = (string) ( $token['chat_id']   ?? '' );

		if ( ! $tool_slug ) {
			return false;
		}

		// Re-validate policy before executing (token could have been stolen).
		if ( class_exists( 'BizCity_CRM_Admin_Chat_Policy' ) ) {
			$policy   = BizCity_CRM_Admin_Chat_Policy::evaluate( $user_id, $guru_id, null, $tool_slug, $tool_cls );
			$decision = (string) ( $policy['decision'] ?? BizCity_CRM_Admin_Chat_Policy::DECISION_DENY );
			if ( $decision === BizCity_CRM_Admin_Chat_Policy::DECISION_DENY ) {
				self::log_audit( $user_id, $guru_id, $tool_slug, 'denied', 'recheck_denied', $grant_id );
				return true; // Consumed the token, logged denial.
			}
		}

		self::log_audit( $user_id, $guru_id, $tool_slug, 'success', 'user_confirmed', $grant_id );

		/**
		 * Fires when an admin user has confirmed a skill execution.
		 * Skill executors should hook here to perform the actual work.
		 *
		 * @param array $token {tool_slug, tool_class, guru_id, grant_id, chat_id, ts, user_id}
		 */
		$token['user_id'] = $user_id;
		do_action( 'bizcity_crm_admin_skill_confirmed', $token );

		return true;
	}

	/* ═══════════════════════════════════════════════════════════
	 *  `bizcity_crm_admin_skill_confirmed` handler (default no-op executor)
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Fallback handler — logs + sends a "skill running..." reply if no other
	 * plugin hooked into `bizcity_crm_admin_skill_confirmed` with higher
	 * priority. Real skill implementations add_action with priority < 10.
	 */
	public static function on_confirmed( array $token ): void {
		$tool_slug = (string) ( $token['tool_slug'] ?? '' );
		$chat_id   = (string) ( $token['chat_id']  ?? '' );

		// Execute via Tool Registry if available.
		if ( class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$tool = BizCity_Twin_Tool_Registry::instance()->get( $tool_slug );
			if ( $tool && method_exists( $tool, 'execute' ) ) {
				$ctx = array(
					'user_id'    => (int) ( $token['user_id'] ?? 0 ),
					'guru_id'    => (int) ( $token['guru_id'] ?? 0 ),
					'surface'    => 'channel',
					'scope'      => array( 'admin' => true ),
					'session_id' => '',
					'trace_id'   => 'confirm-' . substr( md5( wp_json_encode( $token ) ), 0, 8 ),
				);
				try {
					$result  = (array) $tool->execute( array(), $ctx );
					$summary = (string) ( $result['summary'] ?? '' );
					if ( $chat_id ) {
						$msg = $summary !== '' ? "✅ {$summary}" : "✅ Skill *{$tool_slug}* đã chạy xong.";
						bizcity_channel_send( $chat_id, $msg );
					}
				} catch ( \Throwable $e ) {
					if ( $chat_id ) {
						bizcity_channel_send( $chat_id, "⚠️ Lỗi khi chạy skill: " . $e->getMessage() );
					}
				}
				return;
			}
		}

		// No tool found — inform the user.
		if ( $chat_id ) {
			bizcity_channel_send( $chat_id, "⚠️ Skill *{$tool_slug}* không tìm thấy trong registry." );
		}
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════════════════════ */

	private static function transient_key( int $user_id ): string {
		return 'bizcity_crm_skill_confirm_' . $user_id;
	}

	/**
	 * Log to `bizcity_crm_admin_chat_audit` if the class is available.
	 */
	private static function log_audit(
		int $user_id,
		int $guru_id,
		string $tool_slug,
		string $status,
		string $reason = '',
		?int $grant_id = null
	): void {
		if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Audit' ) ) {
			return;
		}
		BizCity_CRM_Admin_Chat_Audit::log( array(
			'user_id'     => $user_id,
			'guru_id'     => $guru_id,
			'action'      => $tool_slug,
			'status'      => $status,
			'reason'      => $reason,
			'grant_id'    => $grant_id,
			'input_text'  => '',
		) );
	}

	private static function log( string $msg ): void {
		error_log( '[bizcity-crm-admin-chat-confirm] ' . $msg );
	}
}
