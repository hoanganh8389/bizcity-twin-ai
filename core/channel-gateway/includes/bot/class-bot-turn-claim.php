<?php
/**
 * Bot Studio — turn claim, nhịp 1 (PHASE-0.60A W3).
 *
 * Hooks `bizcity_channel_normalized` at PRIORITY 0 — before the layer that
 * actually decides, `BizCity_Automation_Trigger_Matcher` at priority 30
 * (class-automation-trigger-matcher.php:69; the Automation_Listener at 1 only
 * logs). Cheap: no DB writes, no model call. Decides "does the bot answer this
 * turn?" and, if yes, turns off the built-in Default_Reply safety net for this
 * request only (R-CH-UNI §1.2 permits this explicitly — see doc §0.3).
 *
 * Yielding to workflows (0.60D S3.3): when the matcher enqueues a workflow run
 * in the same request (`bizcity_automation_run_enqueued`), the claim is marked
 * `workflow_matched` and the runner drops the turn — the operator's explicit
 * workflow wins, the bot only replaces the default-reply net.
 *
 * Claim context is handed to BizCity_Bot_Turn_Runner via a static property:
 * both hooks fire inside the SAME PHP request (one inbound webhook = one
 * synchronous pass through the whole chain — confirmed by tracing
 * class-universal-channel-listener.php → automation → CRM ingestor).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since 1.0.0 (PHASE-0.60A W3)
 */

// [2026-09-23 Claude Sonnet 5] PHASE-0.60A W3
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Turn_Claim {

	const PLATFORM = 'ZALO_PERSONAL';
	const CODE     = 'zalo_personal';
	/** Priority of the real decision layer we must run before (doc §1.1a). */
	const TRIGGER_MATCHER_PRIORITY = 30;
	const ACTIVE_TTL = 900;

	/** @var array|null Claim context for the message currently in flight this request. */
	private static $claim = null;

	public static function init(): void {
		add_action( 'bizcity_channel_normalized', array( __CLASS__, 'on_normalized' ), 0, 2 );
		// [2026-09-23 03:45 PM Claude Fable 5.1] PHASE-0.60D S3.3 — a matched workflow (enqueued in this request) makes the bot yield.
		add_action( 'bizcity_automation_run_enqueued', array( __CLASS__, 'on_workflow_enqueued' ), 10, 3 );
	}

	/**
	 * @param array  $envelope    Normalized envelope, class-universal-channel-listener.php:422-468.
	 * @param string $trigger_key
	 */
	public static function on_normalized( array $envelope, string $trigger_key ): void {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return;
		}
		if ( strtoupper( (string) ( $envelope['platform'] ?? '' ) ) !== self::PLATFORM ) {
			return;
		}
		// [2026-09-23 03:45 PM Claude Fable 5.1] PHASE-0.60A B4.1a — symmetric bail: Zone 1 Personal only (Guru_Bridge bails the other way).
		$raw_code = isset( $envelope['raw']['code'] ) ? sanitize_key( (string) $envelope['raw']['code'] ) : '';
		if ( $raw_code !== '' && $raw_code !== self::CODE ) {
			return;
		}
		$account_id = (string) ( $envelope['account_id'] ?? '' );
		$chat_id    = (string) ( $envelope['chat_id'] ?? '' );
		if ( $account_id === '' || $chat_id === '' ) {
			return;
		}
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60H — `$envelope['contact_id']` is NOT a CRM contact id: the
		// listener fills it from BizCity_Identity_Hub (`identity_contacts.id`, class-universal-channel-listener.php
		// ~L432), and for ZALO_PERSONAL it is always 0 (not a guest channel there). Requiring it > 0 meant this
		// claim returned for every real Zalo Cá nhân message — the bot never took a live turn — while the unit
		// tests stayed green because their fixture put a CRM-looking id straight into the envelope.
		// The CRM contact is now resolved from the CRM itself (read-only), and adopted from the persisted event
		// when the customer is brand new (BizCity_Bot_Turn_Runner::on_persisted).
		$thread     = self::thread_ref( $envelope );
		$contact_id = self::resolve_crm_contact_id( $account_id, $thread['source_id'] );

		$binding = BizCity_Channel_Binding::resolve( self::PLATFORM, $account_id );
		if ( ! $binding ) {
			return;
		}
		$character_id = (int) ( $binding['character_id'] ?? 0 );
		$mode         = (string) ( $binding['mode'] ?? '' );
		if ( $character_id <= 0 || ! in_array( $mode, array( 'auto', 'hybrid' ), true ) ) {
			return; // reuses existing binding fields — no separate "bot enabled" flag (doc §3.2).
		}

		// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-1.2 — decoded once, reused below for EA-2's
		// reply_in_group and EA-3's passive_listen_in_group (same policy_json blob, doc §3.2).
		$bot_policy = self::decode_json_map( $binding['policy_json'] ?? '' );
		if ( ! self::allowlist_pass( $bot_policy, $envelope, $contact_id ) ) {
			return; // EA-1.3: not allowlisted → the bot does not take the turn, and — because we
			        // return here before the default-reply filter below ever runs — the built-in
			        // Default_Reply safety net stays ON, so the customer is never left in silence.
		}

		$policy = self::decode_office_hours( $binding['office_hours_json'] ?? '' );
		if ( BizCity_Bot_Office_Hours::is_staff_on_duty( $policy ) ) {
			return; // staff on duty → bot silent (E10 polarity).
		}
		// A brand-new customer has no CRM contact yet ($contact_id 0): nobody can have paused or capped them,
		// and the runner re-checks both against the real CRM id before it ever sends (may_still_send()).
		if ( $contact_id > 0 && ! empty( $policy['pause_on_manual_reply'] ) && self::is_paused( $contact_id ) ) {
			return; // a human just replied — leave room, per the doc's pause-window mitigation.
		}

		$chat_kind = $thread['chat_kind'];
		if ( 'group' === $chat_kind ) {
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-2.1/EA-2.3 — reply_in_group=false wins over
			// require_mention_in_group: the bot never answers in ANY group thread, so the @mention
			// gate below would be moot and is skipped entirely (doc §6 EA-2.3).
			if ( isset( $bot_policy['reply_in_group'] ) && ! $bot_policy['reply_in_group'] ) {
				return; // EA-2.2: chat riêng của cùng số Zalo vẫn trả lời bình thường (not reached here).
			}
			if ( ! empty( $policy['require_mention_in_group'] ) && empty( $envelope['mention_detected'] ) ) {
				return; // group chat requires @mention unless explicitly turned off.
			}
		}

		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		if ( $contact_id > 0 && self::today_count( $contact_id ) >= (int) $tuning['daily_message_cap'] ) {
			return; // daily cap reached — the account-ban mitigation the doc's risk table flags.
		}

		$bot_settings = BizCity_Bot_Config_Repo::get( $character_id );

		self::$claim = array(
			'character_id'     => $character_id,
			'binding_id'       => (int) ( $binding['id'] ?? 0 ),
			'account_id'       => $account_id,
			'chat_id'          => $chat_id,
			'chat_kind'        => $chat_kind,
			'contact_id'       => $contact_id,
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-7 (D-E2) — the raw platform sender uid
			// (distinct from `contact_id`, the CRM identity) and the binding's configured owner uid,
			// so BizCity_Bot_Tool_Registry::effective_for_turn() can gate list_threads/read_thread
			// to exactly the account owner, in a private chat, per turn.
			'sender_uid'       => $thread['sender_uid'],
			// [2026-09-24] PHASE-0.60H — the CRM thread key (`<uid>` or `group:<group_id>`), used to verify the
			// persisted event belongs to THIS claim; and the group id, for group-scoped memory provenance.
			'source_id'        => $thread['source_id'],
			'group_id'         => $thread['group_id'],
			'owner_uid'        => trim( (string) ( $bot_policy['owner_uid'] ?? '' ) ),
			'mode'             => $mode, // auto = send · hybrid = draft only (doc B-04)
			'text'             => (string) ( $envelope['message_text_clean'] ?? $envelope['message'] ?? '' ),
			'external_message_id' => (string) ( $envelope['message_id'] ?? '' ),
			'history_limit'    => (int) $bot_settings['history_limit'],
			'bypass_notebook'  => ! empty( $bot_settings['bypass_notebook'] ),
			'context_source'   => (string) $bot_settings['context_source'],
			'character_off'    => (array) $bot_settings['disabled_tools'],
			'binding_off'      => BizCity_Bot_Config_Repo::sanitize_tool_list( $policy['disabled_tools'] ?? array() ),
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-3.3 — carried to the runner so
			// Bot_Context_Builder::build() can filter non-@mention group rows out of history
			// when this is off (doc §6 EA-3.3); default true keeps today's behavior.
			'passive_listen_in_group' => ! isset( $bot_policy['passive_listen_in_group'] ) || (bool) $bot_policy['passive_listen_in_group'],
			'workflow_matched' => false,
			'claimed_at'       => time(),
		);

		// [2026-09-23 Claude Sonnet 5] PHASE-0.60A W3 (B4.2) — the single most important line
		// in this feature: turn off the built-in default-reply net for THIS request only, since
		// the bot is taking the turn instead. R-CH-UNI §1.2 explicitly permits this.
		add_filter( 'bizcity_automation_default_reply_enabled', '__return_false' );
	}

	/** A workflow run was enqueued for the message we claimed → the workflow wins (0.60D §4.2). */
	public static function on_workflow_enqueued( $run_id, $workflow_id = 0, $payload = array() ): void {
		if ( null === self::$claim ) {
			return;
		}
		self::$claim['workflow_matched']    = true;
		self::$claim['workflow_id']         = (int) $workflow_id;
	}

	/** Consumed once by BizCity_Bot_Turn_Runner; null if no claim was recorded this request. */
	public static function consume_claim(): ?array {
		$claim       = self::$claim;
		self::$claim = null;
		return $claim;
	}

	/** Read without consuming (tests / diagnostics). */
	public static function peek_claim(): ?array {
		return self::$claim;
	}

	/* ── pause / cap / active registry — keys are blog-scoped (B10.3) ── */

	public static function is_paused( int $contact_id ): bool {
		return (bool) get_transient( self::pause_key( $contact_id ) );
	}

	public static function set_paused( int $contact_id, int $minutes ): void {
		set_transient( self::pause_key( $contact_id ), 1, max( 60, $minutes * 60 ) );
	}

	public static function today_count( int $contact_id ): int {
		$val = get_transient( self::cap_key( $contact_id ) );
		return $val ? (int) $val : 0;
	}

	public static function increment_today_count( int $contact_id ): void {
		$key = self::cap_key( $contact_id );
		$val = self::today_count( $contact_id ) + 1;
		// Expire at local midnight so the cap resets once per calendar day.
		$seconds_to_midnight = 86400 - ( (int) current_time( 'timestamp' ) % 86400 );
		set_transient( $key, $val, max( 60, $seconds_to_midnight ) );
	}

	/** Contacts with a pending/running turn — for GET /bot/queue/status (B-08). */
	public static function active_contacts(): array {
		$list = get_transient( self::active_key() );
		return is_array( $list ) ? $list : array();
	}

	public static function mark_active( int $contact_id, string $state, array $extra = array() ): void {
		$list = self::active_contacts();
		$list[ (string) $contact_id ] = array_merge( array( 'state' => $state, 'at' => time() ), $extra );
		// Drop stale entries so the list cannot grow without bound.
		foreach ( $list as $k => $row ) {
			if ( ( time() - (int) ( $row['at'] ?? 0 ) ) > self::ACTIVE_TTL ) {
				unset( $list[ $k ] );
			}
		}
		set_transient( self::active_key(), $list, self::ACTIVE_TTL );
	}

	public static function clear_active( int $contact_id ): void {
		$list = self::active_contacts();
		unset( $list[ (string) $contact_id ] );
		set_transient( self::active_key(), $list, self::ACTIVE_TTL );
	}

	private static function blog(): string {
		return (string) ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
	}

	private static function pause_key( int $contact_id ): string {
		return 'bzbot_pause_' . self::blog() . '_' . $contact_id;
	}

	private static function cap_key( int $contact_id ): string {
		return 'bzbot_cap_' . self::blog() . '_' . $contact_id . '_' . ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
	}

	private static function active_key(): string {
		return 'bzbot_active_' . self::blog();
	}

	public static function decode_office_hours( $raw ): array {
		return self::decode_json_map( $raw );
	}

	/** Shared decode for any binding *_json column: array passthrough, or a JSON string. */
	private static function decode_json_map( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * EA-1 · ALLOWLIST người gửi (doc §6 EA-1). Three modes:
	 *   all           (default) — everyone, today's behavior.
	 *   contacts_only — only senders whose identity is not a throwaway/guest one.
	 *   list          — only the specific sender UIDs configured on the binding.
	 */
	private static function allowlist_pass( array $policy, array $envelope, int $crm_contact_id = 0 ): bool {
		$mode = isset( $policy['allowlist_mode'] ) ? (string) $policy['allowlist_mode'] : 'all';
		if ( ! in_array( $mode, array( 'all', 'contacts_only', 'list' ), true ) ) {
			$mode = 'all'; // EA-1.6: unknown/unset value must never change today's behavior.
		}
		if ( 'all' === $mode ) {
			return true;
		}
		if ( 'contacts_only' === $mode ) {
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60H — the UI promises "Chỉ người đã là contact CRM". The
			// previous check (`empty( identity_temporary )`) is always true on ZALO_PERSONAL, so this mode
			// silently behaved like "all". The claim now knows the real CRM contact (resolved read-only),
			// which is exactly what the label means: someone the CRM already has.
			return $crm_contact_id > 0;
		}
		// list
		$uids       = isset( $policy['allowlist_uids'] ) && is_array( $policy['allowlist_uids'] ) ? $policy['allowlist_uids'] : array();
		$sender_uid = (string) ( $envelope['user_id'] ?? '' );
		return $sender_uid !== '' && in_array( $sender_uid, $uids, true );
	}

	/**
	 * Pure: what thread this envelope belongs to, in CRM terms.
	 *
	 * Zalo Cá nhân does not set the generic `chat_kind`: its inbound payload (kept under `raw`) says
	 * `thread_kind` = 'group'|'personal' / `is_group`, and in a group the CRM conversation belongs to the GROUP
	 * (`source_id = 'group:<id>'`, class-adapter-zalo-personal.php:42) while the sender is message metadata.
	 * Reading only `chat_kind` made every group message look private — the reply_in_group / @mention gates
	 * never ran, and anything keyed "private vs group" (R-CH-IDMEM) got it wrong.
	 *
	 * @return array{chat_kind:string,source_id:string,sender_uid:string,group_id:string}
	 */
	public static function thread_ref( array $envelope ): array {
		$raw        = isset( $envelope['raw'] ) && is_array( $envelope['raw'] ) ? $envelope['raw'] : array();
		$sender_uid = trim( (string) ( $envelope['user_id'] ?? $raw['from_user_id'] ?? '' ) );
		$explicit   = strtolower( trim( (string) ( $envelope['chat_kind'] ?? '' ) ) );
		$is_group   = 'group' === $explicit
			|| 'group' === strtolower( (string) ( $raw['thread_kind'] ?? '' ) )
			|| ! empty( $raw['is_group'] );
		$group_id   = $is_group ? trim( (string) ( $raw['group_id'] ?? $raw['thread_id'] ?? '' ) ) : '';
		return array(
			'chat_kind'  => $is_group ? 'group' : 'user',
			'source_id'  => $is_group ? ( '' !== $group_id ? 'group:' . $group_id : '' ) : $sender_uid,
			'sender_uid' => $sender_uid,
			'group_id'   => $group_id,
		);
	}

	/** Read-only CRM lookup: the contact already attached to this phone's inbox + thread, or 0 (new / CRM not loaded). */
	private static function resolve_crm_contact_id( string $account_id, string $source_id ): int {
		if ( '' === $source_id || ! class_exists( 'BizCity_CRM_Repository' )
			|| ! method_exists( 'BizCity_CRM_Repository', 'get_inbox_by_ref' )
			|| ! method_exists( 'BizCity_CRM_Repository', 'find_contact_id_by_source' ) ) {
			return 0;
		}
		$inbox = BizCity_CRM_Repository::get_inbox_by_ref( self::CODE, $account_id );
		if ( ! is_array( $inbox ) ) {
			return 0;
		}
		return (int) BizCity_CRM_Repository::find_contact_id_by_source( (int) ( $inbox['id'] ?? 0 ), $source_id );
	}
}
