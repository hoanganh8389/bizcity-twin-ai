<?php
/**
 * Bot Studio — turn runner, nhịp 2 (PHASE-0.60A W3/W4/W5).
 *
 * Hooks:
 *   - `bizcity_crm_message_persisted` (fb-ingestor.php:310, incoming only) — has
 *     conversation_id, so the reply can be attached to the right thread. Consumes
 *     the claim recorded at priority 0 (BizCity_Bot_Turn_Claim) and schedules a
 *     debounced turn.
 *   - `bizcity_crm_message_inserted` (class-repository.php::insert_message, the
 *     single insertion point) — an OUTGOING row with responder_kind=manual arms the
 *     pause-on-manual-reply window (a human is talking to this customer).
 *   - `bizcity_bot_run_turn` (WP-Cron single event) — the actual turn.
 *
 * Queue invariants ported from the reference library (doc §3.5):
 *   1. a turn fires only after `debounce_seconds` of silence AND the thread is free;
 *   2. busy thread → the batch PARKS (re-scheduled), it is not queued;
 *   3. window cap `max_batch_messages` bounds memory; extra messages stay in CRM history.
 * PHP has no resident process, so "busy" is a short transient lock + the
 * dispatcher's idempotency key — never an in-memory promise (B5.4).
 *
 * Sending goes through the canonical CRM owner
 * `BizCity_CRM_Outbound_Dispatcher::dispatch()` with responder_kind=auto, so the
 * Inbox shows 🤖 without any ConversationDetail change (B-09) and the message is
 * one CRM row with delivery state — exactly like a workflow's send_message.
 *
 * Inherited obligation (R-CH-UNI §1.2, doc §0.3): the bot turned the
 * default-reply net off, so a dead provider still produces ONE honest sentence
 * (B4.7) — never silence, never a raw error in the thread.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since 1.0.0 (PHASE-0.60A W3)
 */

// [2026-09-23 03:50 PM Claude Fable 5.1] PHASE-0.60A W3/W4/W5 — rewrite: lock/park, tools, dispatcher send, workflow yield, hybrid drafts.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Turn_Runner {

	const FALLBACK_TEXT = 'Xin lỗi, hiện mình chưa thể trả lời ngay. Bạn vui lòng đợi nhân viên hỗ trợ nhé.';
	const CRON_HOOK     = 'bizcity_bot_run_turn';
	const MAX_PARKS     = 6;
	/** Recurring job that rescues a turn whose one-shot cron event was lost (see sweep_overdue()). */
	const SWEEP_HOOK    = 'bizcity_bot_turn_sweep';
	/** A waiting turn is overdue once it is older than debounce + this many seconds. */
	const SWEEP_GRACE_SECONDS = 25;
	/** How many times one turn is re-armed before it is given up as `cron_lost`. */
	const MAX_RESCUES     = 2;
	const ZALO_REPLY_MAX_CHARS = 1800;

	/** @var callable|null test seam: fn(object $character, array $messages, array $claim): array {success,message,error} */
	public static $llm = null;
	/** @var callable|null test seam: fn(array $claim, string $text, array $meta): array {ok,message_id,error} */
	public static $sender = null;
	/** @var callable|null test seam: fn(int $contact_id, int $delay): void */
	public static $scheduler = null;
	/** @var callable|null test seam: fn(array $request): array — replaces BizCity_CRM_Outbound_Dispatcher::dispatch() so send() itself is testable. */
	public static $dispatcher = null;
	/**
	 * [2026-09-25 Claude Sonnet 5] PHASE-0.60K §15 — the turn currently holding the thread lock, and how far it got. A turn
	 * killed by a PHP fatal / max_execution_time never reaches its `finally`, so the lock stayed (composer "AI reply" then
	 * answers `bot_busy` for up to turn_timeout+5s) and NOTHING said what happened. on_shutdown() reads this.
	 *
	 * @var array{contact_id:int,conversation_id:int,trace_id:string,stage:string,started:float}|null
	 */
	private static $running = null;
	private static $shutdown_registered = false;
	/**
	 * [2026-09-25 Claude Sonnet 5] Where one turn's wall-clock went, phase by phase (ms). `llm_ms` alone left 37 of a live
	 * turn's 55 seconds unexplained. Reset per turn; read into the lifecycle events. Never carries text.
	 *
	 * @var array<string,float|int>
	 */
	private static $timing = array();

	/** @var callable|null test seam: fn(string $type, array $payload): void — sees every emitted event, in addition to the bus + file log. */
	public static $event_observer = null;

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K §15.2 — the lifecycle stages of one automatic turn, in the order they
	 * happen. A turn is PASS only when the chain is unbroken; each stage is one bounded event carrying the same
	 * `trace_id`, so "where did this message stop?" is a single filter on the log instead of a guess.
	 */
	const LIFECYCLE_STAGES = array(
		'bot_turn_skipped', // a turn Bot Studio declined to take — outside the claimed→delivery chain, but the reason a customer hears nothing.
		'bot_turn_claimed',
		'bot_turn_scheduled',
		'bot_turn_cron_started',
		'bot_turn_llm_completed',
		'bot_turn_dispatch_started',
		'bot_turn_dispatch_completed',
		'bot_turn_zalo_delivery',
		'bot_turn_failed',
		'goal_loop_post_turn',
	);

	/**
	 * The only keys a lifecycle event may carry — ids, counters, buckets. Never text, prompts, tokens or raw UIDs.
	 *
	 * Every key must also SURVIVE BizCity_Channel_File_Logger::scrub_context(), which redacts any key matching
	 * /token|secret|password|authorization|api[_-]?key|raw|body|message|phone|email/. That is why the CRM message id
	 * travels as `crm_msg_id` (a `message_id` key would be written as "[redacted]" and the row could not be traced);
	 * callers still pass `message_id`, lifecycle() renames it. BotLifecycleEvidenceTest pins this against the real scrubber.
	 */
	const LIFECYCLE_KEYS = array(
		'trace_id', 'conversation_id', 'contact_id', 'message_id', 'character_id', 'mode', 'trigger', 'channel', 'chat_kind',
		'account_ref', 'delay_seconds', 'pending', 'parks', 'ran', 'ok', 'state', 'source', 'stage', 'kind', 'attempt',
		'latency_ms', 'reply_chars', 'tool_steps', 'purpose', 'outcome', 'code', 'reason_bucket', 'retryable', 'replayed',
		'delivery_mode', 'system_source', 'responder_kind', 'fallback', 'binding_id', 'prep_ms', 'plan_ms', 'context_ms', 'pre_send_ms', 'planner',
	);

	public static function init(): void {
		add_action( 'bizcity_crm_message_persisted', array( __CLASS__, 'on_persisted' ), 10, 1 );
		add_action( 'bizcity_crm_message_inserted', array( __CLASS__, 'on_message_inserted' ), 10, 2 );
		add_action( 'bizcity_crm_message_delivery_updated', array( __CLASS__, 'on_delivery_updated' ), 10, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'on_run_turn_cron' ), 10, 1 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep_overdue' ), 10, 0 );
		add_action( 'init', array( __CLASS__, 'register_sweeper_job' ), 20 );
	}

	/**
	 * [2026-09-25 Claude Sonnet 5] A turn rides ONE one-shot WP-Cron event. If that event is lost — WP could not save the cron
	 * list (`could_not_set`, seen 3x in the live log), or the runner failed to load in the request that fired it (WP then
	 * runs the hook with no listener and forgets it) — the claim sits in its transient until it expires and the customer is
	 * silent forever. Live: msg#70 (17:53:38) was scheduled and never started. A once-a-minute sweeper re-arms it.
	 */
	public static function register_sweeper_job(): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return; // no cron manager on this deployment: do not schedule an untracked job.
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => 'channel_bot_turn_sweeper',
			'hook'        => self::SWEEP_HOOK,
			'interval'    => 'bizcity_every_minute',
			'owner'       => 'core/channel-gateway',
			'description' => 'Re-arm Bot Studio turns whose cron event was lost (PHASE-0.60K §15)',
			'singleton'   => true,
			'enabled'     => true,
			'retention'   => 14,
		) );
	}

	/**
	 * Re-arm every `waiting` turn that is overdue, drop what can no longer run. Bounded (MAX_RESCUES per turn), never
	 * touches `running` / `parked` turns (the thread lock and the shutdown guard own those), and safe against a slow-but-
	 * alive original event: the claim is consumed once, and the dispatcher key is stable per message.
	 *
	 * @param int $now Test seam for the clock (0 = time()).
	 * @return array{rescued:int,gave_up:int,cleared:int}
	 */
	public static function sweep_overdue( $now = 0 ): array {
		$out = array( 'rescued' => 0, 'gave_up' => 0, 'cleared' => 0 );
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return $out; // R-CLI-ASYNC-ISOLATION
		}
		if ( ! class_exists( 'BizCity_Bot_Turn_Claim' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return $out;
		}
		$now      = (int) $now > 0 ? (int) $now : time();
		$tuning   = BizCity_Bot_Config_Repo::get_tuning();
		$debounce = max( 1, (int) $tuning['debounce_seconds'] );
		foreach ( BizCity_Bot_Turn_Claim::active_contacts() as $key => $row ) {
			if ( 'waiting' !== (string) ( $row['state'] ?? '' ) || ( $now - (int) ( $row['at'] ?? 0 ) ) < $debounce + self::SWEEP_GRACE_SECONDS ) {
				continue;
			}
			$contact_id      = (int) $key;
			$conversation_id = (int) ( $row['conversation_id'] ?? 0 );
			$claim           = get_transient( self::claim_key( $contact_id ) );
			if ( ! is_array( $claim ) || empty( $claim['conversation_id'] ) ) {
				// The claim already expired or was consumed: there is nothing left to run, only a stale row to clear.
				BizCity_Bot_Turn_Claim::clear_active( $contact_id );
				self::lifecycle( 'bot_turn_failed', array( 'conversation_id' => $conversation_id, 'contact_id' => $contact_id, 'stage' => 'sweep', 'reason_bucket' => 'claim_expired' ) );
				$out['cleared']++;
				continue;
			}
			$trace_id           = (string) ( $claim['trace_id'] ?? '' );
			$claim['rescues']   = (int) ( $claim['rescues'] ?? 0 ) + 1;
			if ( $claim['rescues'] > self::MAX_RESCUES ) {
				delete_transient( self::claim_key( $contact_id ) );
				BizCity_Bot_Turn_Claim::clear_active( $contact_id );
				self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $trace_id, 'conversation_id' => (int) $claim['conversation_id'], 'contact_id' => $contact_id, 'stage' => 'sweep', 'reason_bucket' => 'cron_lost' ) );
				$out['gave_up']++;
				continue;
			}
			set_transient( self::claim_key( $contact_id ), $claim, $debounce + 120 ); // keep the claim alive for the re-armed run
			BizCity_Bot_Turn_Claim::mark_active( $contact_id, 'waiting', array( 'conversation_id' => (int) $claim['conversation_id'], 'pending' => (int) ( $claim['pending_messages'] ?? 1 ) ) );
			$queued = self::schedule( $contact_id, 1 );
			self::lifecycle( 'bot_turn_scheduled', array(
				'trace_id'        => $trace_id,
				'conversation_id' => (int) $claim['conversation_id'],
				'contact_id'      => $contact_id,
				'ok'              => $queued,
				'delay_seconds'   => 1,
				'source'          => 'sweeper',
				'attempt'         => $claim['rescues'],
				'reason_bucket'   => $queued ? '' : 'schedule_rejected',
			) );
			$out['rescued']++;
		}
		return $out;
	}

	/* ── nhịp 2 entry ─────────────────────────────────────────────────── */

	public static function on_persisted( array $payload ): void {
		if ( 'incoming' !== (string) ( $payload['direction'] ?? '' ) ) {
			return;
		}
		$claim = class_exists( 'BizCity_Bot_Turn_Claim' ) ? BizCity_Bot_Turn_Claim::consume_claim() : null;
		if ( ! $claim ) {
			return;
		}
		// Sanity cross-check: the claim (from the normalized envelope) and this persisted event must be
		// the same message, or we skip rather than misfire.
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60H — this used to compare CRM `contact_id` with the envelope's
		// `contact_id`, which is an Identity Hub row id (never equal, and 0 on ZALO_PERSONAL) — see
		// Bot_Turn_Claim::on_normalized(). It now checks what actually identifies the message: same adapter,
		// same phone (inbox ↔ account), and — when the claim already knew the CRM contact — the same contact.
		if ( ! self::persisted_matches_claim( $payload, $claim ) ) {
			self::emit_event( 'bot_turn_claim_mismatch', array( 'conversation_id' => (int) ( $payload['conversation_id'] ?? 0 ), 'channel' => 'zalo_personal', 'reason_bucket' => 'claim_mismatch' ) );
			return;
		}
		$claim['contact_id']      = (int) ( $payload['contact_id'] ?? 0 ); // the REAL CRM contact from here on.
		$claim['conversation_id'] = (int) ( $payload['conversation_id'] ?? 0 );
		$claim['message_id']      = (int) ( $payload['message_id'] ?? 0 );
		// A brand-new customer skipped the claim-time pause/cap checks (no CRM id yet) — run them now, before
		// anything is scheduled. may_still_send() runs them again at fire time.
		if ( ! self::may_still_send( $claim ) ) {
			self::emit_event( 'bot_turn_dropped', array( 'conversation_id' => $claim['conversation_id'], 'reason' => 'conditions_at_persist', 'reason_bucket' => 'conditions_changed' ) );
			return;
		}

		// [2026-09-23 03:50 PM Claude Fable 5.1] PHASE-0.60D S3.3 — the operator's workflow wins; the bot yields.
		if ( ! empty( $claim['workflow_matched'] ) ) {
			self::emit_event( 'bot_turn_yielded', array( 'conversation_id' => $claim['conversation_id'], 'workflow_id' => (int) ( $claim['workflow_id'] ?? 0 ), 'channel' => 'zalo_personal' ) );
			return;
		}
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60K §15.2 — the turn gets its trace_id NOW, not when the cron fires, so
		// claimed → scheduled → cron → llm → dispatch → delivery all carry one id. Debounce keeps the FIRST id: a burst
		// of messages is one turn (schedule_debounced_turn carries it forward).
		if ( empty( $claim['trace_id'] ) ) {
			$waiting           = get_transient( self::claim_key( (int) $claim['contact_id'] ) );
			$claim['trace_id'] = is_array( $waiting ) && ! empty( $waiting['trace_id'] ) ? (string) $waiting['trace_id'] : self::new_trace_id();
		}
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K8 — a debounce burst is ONE turn but several customer messages: remember
		// every Zalo msgId so the turn can mark them all "đã xem" (capped at one bridge batch).
		if ( ! empty( $claim['seen_receipts'] ) && class_exists( 'BizCity_Bot_Zalo_Actions' ) ) {
			$waiting_now = get_transient( self::claim_key( (int) $claim['contact_id'] ) );
			$seen_ids    = is_array( $waiting_now ) && ! empty( $waiting_now['seen_ids'] ) ? (array) $waiting_now['seen_ids'] : array();
			$this_id     = BizCity_Bot_Zalo_Actions::zalo_msg_id( $claim );
			if ( '' !== $this_id ) {
				$seen_ids[] = $this_id;
			}
			$claim['seen_ids'] = array_slice( array_values( array_unique( array_map( 'strval', $seen_ids ) ) ), -50 );
		}
		self::lifecycle( 'bot_turn_claimed', array(
			'trace_id'        => $claim['trace_id'],
			'conversation_id' => $claim['conversation_id'],
			'contact_id'      => $claim['contact_id'],
			'message_id'      => $claim['message_id'],
			'character_id'    => (int) ( $claim['character_id'] ?? 0 ),
			'mode'            => (string) ( $claim['mode'] ?? '' ),
			'chat_kind'       => (string) ( $claim['chat_kind'] ?? '' ),
			'channel'         => 'zalo_personal',
			'account_ref'     => self::account_ref( (string) ( $claim['account_id'] ?? '' ) ),
		) );
		self::schedule_debounced_turn( $claim );
	}

	/**
	 * Is this persisted CRM message the one the claim was taken for?
	 *
	 * Public for tests. Pure apart from one read of the inbox row.
	 */
	public static function persisted_matches_claim( array $payload, array $claim ): bool {
		if ( (int) ( $payload['contact_id'] ?? 0 ) <= 0 ) {
			return false;
		}
		$adapter = (string) ( $payload['adapter_code'] ?? '' );
		if ( '' !== $adapter && BizCity_Bot_Turn_Claim::CODE !== $adapter ) {
			return false;
		}
		// Claim already knew the CRM contact (returning customer): it must be the same one.
		if ( (int) ( $claim['contact_id'] ?? 0 ) > 0 && (int) $payload['contact_id'] !== (int) $claim['contact_id'] ) {
			return false;
		}
		// Same phone: the persisted inbox must be this claim's Zalo account.
		if ( class_exists( 'BizCity_CRM_Repository' ) ) {
			$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $payload['inbox_id'] ?? 0 ) );
			if ( ! is_array( $inbox ) || (string) ( $inbox['channel_ref_id'] ?? '' ) !== (string) ( $claim['account_id'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Manual outgoing row → arm the pause window for that contact (doc §3.2 step 3). */
	public static function on_message_inserted( $message_id, $row ): void {
		if ( ! is_array( $row ) || 'outgoing' !== (string) ( $row['message_type'] ?? '' ) ) {
			return;
		}
		if ( 'manual' !== (string) ( $row['responder_kind'] ?? '' ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Bot_Turn_Claim' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return;
		}
		$contact_id = self::conversation_contact_id( (int) ( $row['conversation_id'] ?? 0 ) );
		if ( $contact_id <= 0 ) {
			return;
		}
		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		BizCity_Bot_Turn_Claim::set_paused( $contact_id, (int) $tuning['pause_window_minutes'] );
		// A human took over: drop any turn still waiting for this contact. That is by design (staff answered), but the dropped
		// turn must say so under ITS OWN trace_id: without this it stays an unexplained "stopped after scheduled".
		$waiting = get_transient( self::claim_key( $contact_id ) );
		if ( is_array( $waiting ) && ! empty( $waiting['trace_id'] ) ) {
			self::lifecycle( 'bot_turn_cron_started', array( 'trace_id' => (string) $waiting['trace_id'], 'conversation_id' => (int) ( $waiting['conversation_id'] ?? 0 ), 'contact_id' => $contact_id, 'ran' => false, 'reason_bucket' => 'human_took_over' ) );
		}
		delete_transient( self::claim_key( $contact_id ) );
		BizCity_Bot_Turn_Claim::clear_active( $contact_id );
	}

	/**
	 * [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 — the CRM conversations table has no `contact_id` column
	 * (it stores `contact_inbox_id`), so reading `$conversation['contact_id']` was always 0 on a real site and
	 * "pause when staff replies by hand" never armed. Resolve through the CRM's own join; the plain-row read
	 * stays only as the fallback for an older CRM build without the helper.
	 */
	private static function conversation_contact_id( int $conversation_id ): int {
		if ( $conversation_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return 0;
		}
		if ( method_exists( 'BizCity_CRM_Repository', 'get_conversation_contact_id' ) ) {
			return (int) BizCity_CRM_Repository::get_conversation_contact_id( $conversation_id );
		}
		$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
		return is_array( $conversation ) ? (int) ( $conversation['contact_id'] ?? 0 ) : 0;
	}

	/**
	 * Invariant 1 — "im lặng đủ": each new message resets the timer, so only the
	 * LAST message of a burst fires the cron event; the turn then re-reads the
	 * fresh window instead of replying per message.
	 */
	private static function schedule_debounced_turn( array $claim ): void {
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60I P0 R-CLI-ASYNC-ISOLATION — a diagnostics run must never enqueue or
		// execute a production bot turn (it would call the LLM and send a real Zalo message to a real customer).
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		if ( $conversation_id <= 0 || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			// A claimed message that can never be scheduled must leave a trace, not vanish.
			self::lifecycle( 'bot_turn_failed', array( 'trace_id' => (string) ( $claim['trace_id'] ?? '' ), 'conversation_id' => $conversation_id, 'stage' => 'schedule', 'reason_bucket' => 'module_not_loaded' ) );
			return;
		}
		$contact_id = (int) $claim['contact_id'];
		$tuning     = BizCity_Bot_Config_Repo::get_tuning();
		$debounce   = max( 1, (int) $tuning['debounce_seconds'] );

		$existing = get_transient( self::claim_key( $contact_id ) );
		$claim['pending_messages'] = is_array( $existing ) ? (int) ( $existing['pending_messages'] ?? 1 ) + 1 : 1;
		$claim['parks']            = is_array( $existing ) ? (int) ( $existing['parks'] ?? 0 ) : 0;
		if ( $claim['pending_messages'] > (int) $tuning['max_batch_messages'] && empty( $existing['cap_warned'] ) ) {
			// Invariant 3 — warn once per cap hit; messages beyond the cap still live in CRM history.
			$claim['cap_warned'] = true;
			self::emit_event( 'bot_batch_cap_hit', array( 'conversation_id' => $conversation_id, 'pending' => $claim['pending_messages'] ) );
		} elseif ( ! empty( $existing['cap_warned'] ) ) {
			$claim['cap_warned'] = true;
		}
		set_transient( self::claim_key( $contact_id ), $claim, $debounce + 120 );
		BizCity_Bot_Turn_Claim::mark_active( $contact_id, 'waiting', array( 'conversation_id' => $conversation_id, 'pending' => $claim['pending_messages'] ) );
		$queued = self::schedule( $contact_id, $debounce );
		self::lifecycle( 'bot_turn_scheduled', array(
			'trace_id'        => (string) ( $claim['trace_id'] ?? '' ),
			'conversation_id' => $conversation_id,
			'contact_id'      => $contact_id,
			'ok'              => $queued,
			'delay_seconds'   => $debounce,
			'pending'         => (int) $claim['pending_messages'],
			'parks'           => (int) $claim['parks'],
			'reason_bucket'   => $queued ? '' : 'schedule_rejected',
		) );
		if ( ! $queued ) {
			self::lifecycle( 'bot_turn_failed', array( 'trace_id' => (string) ( $claim['trace_id'] ?? '' ), 'conversation_id' => $conversation_id, 'stage' => 'schedule', 'reason_bucket' => 'schedule_rejected' ) );
		}
	}

	/** @return bool false when WP-Cron refused the event — the caller must say so, not assume the turn is queued. */
	private static function schedule( int $contact_id, int $delay ): bool {
		if ( is_callable( self::$scheduler ) ) {
			return false !== call_user_func( self::$scheduler, $contact_id, $delay ); // a seam that returns nothing = accepted.
		}
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60I P0 R-CLI-ASYNC-ISOLATION — a diagnostics run must never enqueue or
		// execute a production bot turn (it would call the LLM and send a real Zalo message to a real customer).
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return true; // isolated on purpose, not a failure.
		}
		self::report_overdue_turns();
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $contact_id ) );
		return true === wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $contact_id ) );
	}

	/** A scheduled turn this late means WP-Cron is not running on this site. */
	const CRON_OVERDUE_SECONDS = 120;

	/**
	 * [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 — Bot Studio is now the ONLY auto-replier for Zalo Cá nhân,
	 * and every turn rides WP-Cron. If cron is dead the customer gets silence with no trace anywhere, which is
	 * exactly how the 2026-09-24 incident looked. This makes it loud: an event + log line naming how late.
	 * Pure read of the cron array; it never runs or reschedules anything itself.
	 *
	 * @return array{count:int,max_late:int,wp_cron_disabled:bool}
	 */
	public static function overdue_turns(): array {
		$out = array( 'count' => 0, 'max_late' => 0, 'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		if ( ! function_exists( '_get_cron_array' ) ) {
			return $out;
		}
		$now = time();
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			if ( ! isset( $hooks[ self::CRON_HOOK ] ) || ( $now - (int) $ts ) <= self::CRON_OVERDUE_SECONDS ) {
				continue;
			}
			$out['count']   += count( (array) $hooks[ self::CRON_HOOK ] );
			$out['max_late'] = max( $out['max_late'], $now - (int) $ts );
		}
		return $out;
	}

	private static function report_overdue_turns(): void {
		$overdue = self::overdue_turns();
		if ( $overdue['count'] > 0 ) {
			self::emit_event( 'bot_cron_overdue', $overdue );
		}
	}

	/**
	 * Cron fire: re-check "hội thoại rảnh" at fire time, not just claim time —
	 * a human may have jumped in during the debounce window (invariant 2).
	 */
	public static function on_run_turn_cron( $contact_id ): void {
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60I P0 R-CLI-ASYNC-ISOLATION — a diagnostics run must never enqueue or
		// execute a production bot turn (it would call the LLM and send a real Zalo message to a real customer).
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		$contact_id = (int) $contact_id;
		$claim = get_transient( self::claim_key( $contact_id ) );
		if ( ! is_array( $claim ) || empty( $claim['conversation_id'] ) ) {
			delete_transient( self::claim_key( $contact_id ) );
			BizCity_Bot_Turn_Claim::clear_active( $contact_id );
			// The cron fired but the claim is gone (expired, or a human took over and cleared it): say so.
			self::lifecycle( 'bot_turn_cron_started', array( 'contact_id' => $contact_id, 'ran' => false, 'reason_bucket' => 'claim_expired' ) );
			return;
		}
		$trace_id = (string) ( $claim['trace_id'] ?? '' );
		if ( self::is_locked( $contact_id ) ) {
			// Invariant 2 — busy thread: PARK, do not queue. Bounded so a stuck lock cannot loop forever.
			$claim['parks'] = (int) ( $claim['parks'] ?? 0 ) + 1;
			if ( $claim['parks'] > self::MAX_PARKS ) {
				delete_transient( self::claim_key( $contact_id ) );
				BizCity_Bot_Turn_Claim::clear_active( $contact_id );
				self::emit_event( 'bot_turn_dropped', array( 'conversation_id' => (int) $claim['conversation_id'], 'reason' => 'parked_too_long', 'reason_bucket' => 'parked_too_long' ) );
				self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $trace_id, 'conversation_id' => (int) $claim['conversation_id'], 'stage' => 'cron', 'reason_bucket' => 'parked_too_long' ) );
				return;
			}
			$tuning = BizCity_Bot_Config_Repo::get_tuning();
			set_transient( self::claim_key( $contact_id ), $claim, (int) $tuning['debounce_seconds'] + 120 );
			BizCity_Bot_Turn_Claim::mark_active( $contact_id, 'parked', array( 'conversation_id' => (int) $claim['conversation_id'], 'parks' => $claim['parks'] ) );
			$requeued = self::schedule( $contact_id, max( 2, (int) $tuning['debounce_seconds'] ) );
			self::lifecycle( 'bot_turn_cron_started', array( 'trace_id' => $trace_id, 'conversation_id' => (int) $claim['conversation_id'], 'contact_id' => $contact_id, 'ran' => false, 'parks' => (int) $claim['parks'], 'reason_bucket' => 'thread_busy' ) );
			if ( ! $requeued ) {
				self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $trace_id, 'conversation_id' => (int) $claim['conversation_id'], 'stage' => 'cron', 'reason_bucket' => 'schedule_rejected' ) );
			}
			return;
		}
		delete_transient( self::claim_key( $contact_id ) );
		if ( ! self::may_still_send( $claim ) ) {
			BizCity_Bot_Turn_Claim::clear_active( $contact_id );
			self::emit_event( 'bot_turn_dropped', array( 'conversation_id' => (int) $claim['conversation_id'], 'reason' => 'conditions_changed', 'reason_bucket' => 'conditions_changed' ) );
			self::lifecycle( 'bot_turn_cron_started', array( 'trace_id' => $trace_id, 'conversation_id' => (int) $claim['conversation_id'], 'contact_id' => $contact_id, 'ran' => false, 'reason_bucket' => 'conditions_changed' ) );
			return; // dropped, not sent — e.g. a human replied or office hours started mid-wait.
		}
		self::lifecycle( 'bot_turn_cron_started', array( 'trace_id' => $trace_id, 'conversation_id' => (int) $claim['conversation_id'], 'contact_id' => $contact_id, 'ran' => true, 'parks' => (int) ( $claim['parks'] ?? 0 ) ) );
		self::run_turn( $claim );
	}

	private static function may_still_send( array $claim ): bool {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return false;
		}
		$binding = BizCity_Channel_Binding::resolve( BizCity_Bot_Turn_Claim::PLATFORM, (string) $claim['account_id'] );
		if ( ! $binding || (int) ( $binding['character_id'] ?? 0 ) !== (int) $claim['character_id'] ) {
			return false; // binding changed/removed since claim time (E11 rollback: disable binding → bot silent).
		}
		if ( ! in_array( (string) ( $binding['mode'] ?? '' ), array( 'auto', 'hybrid' ), true ) ) {
			return false;
		}
		$policy = BizCity_Bot_Turn_Claim::decode_office_hours( $binding['office_hours_json'] ?? '' );
		if ( class_exists( 'BizCity_Bot_Office_Hours' ) && BizCity_Bot_Office_Hours::is_staff_on_duty( $policy ) ) {
			return false;
		}
		if ( ! empty( $policy['pause_on_manual_reply'] ) && BizCity_Bot_Turn_Claim::is_paused( (int) $claim['contact_id'] ) ) {
			return false;
		}
		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		return BizCity_Bot_Turn_Claim::today_count( (int) $claim['contact_id'] ) < (int) $tuning['daily_message_cap'];
	}

	/* ── the actual turn ──────────────────────────────────────────────── */

	/**
	 * Public so the diagnostics probe can drive a fixture with the test seams set (never a real provider).
	 *
	 * @return array{status:string,reply:string,reason:string}
	 */
	public static function run_turn( array $claim ): array {
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		$contact_id      = (int) ( $claim['contact_id'] ?? 0 );
		$result          = array( 'status' => 'skipped', 'reply' => '', 'reason' => '' );
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60I P0 R-CLI-ASYNC-ISOLATION — a diagnostics run must never enqueue or
		// execute a production bot turn (it would call the LLM and send a real Zalo message to a real customer).
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			$result['reason'] = 'diagnostics_async_isolated';
			return $result;
		}
		$claimed_trace = (string) ( $claim['trace_id'] ?? '' );
		if ( $conversation_id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			$result['reason'] = 'module_not_loaded';
			self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $claimed_trace, 'conversation_id' => $conversation_id, 'stage' => 'run', 'reason_bucket' => 'module_not_loaded' ) );
			return $result;
		}
		$character = BizCity_Knowledge_Database::instance()->get_character( (int) $claim['character_id'] );
		if ( ! $character ) {
			$result['reason'] = 'character_missing';
			self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $claimed_trace, 'conversation_id' => $conversation_id, 'character_id' => (int) $claim['character_id'], 'stage' => 'run', 'reason_bucket' => 'character_missing' ) );
			return $result;
		}
		$tuning  = BizCity_Bot_Config_Repo::get_tuning();
		$timeout = (int) $tuning['turn_timeout_seconds'];

		// B4.6 — same hardening as AI_Replier: the customer already saw "delivered", finish the turn.
		if ( function_exists( 'ignore_user_abort' ) ) { ignore_user_abort( true ); }
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( $timeout + 15 ); }

		self::lock( $contact_id, $timeout + 5 );
		BizCity_Bot_Turn_Claim::mark_active( $contact_id, 'running', array( 'conversation_id' => $conversation_id ) );
		self::$running = array( 'contact_id' => $contact_id, 'conversation_id' => $conversation_id, 'trace_id' => '', 'stage' => 'context', 'started' => microtime( true ) );
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}

		// PHASE-0.60K §15.2 — an automatic turn arrives with the trace_id minted at claim time; a composer turn has none.
		$trace_id = '' !== $claimed_trace ? $claimed_trace : self::new_trace_id();
		if ( is_array( self::$running ) ) { self::$running['trace_id'] = $trace_id; }
		$started  = microtime( true );
		self::$timing = array();
		$result['trace_id'] = $trace_id;
		$claim['trace_id']  = $trace_id; // tools that book async work (K3) tie it to this turn's trace.
		// [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 — `trigger` tells an automatic turn (`auto`) from one a staff
		// member pushed from the composer (`composer`); both are the same Bot Studio turn in the event stream.
		$trigger  = (string) ( $claim['trigger'] ?? 'auto' );
		$actor    = (int) ( $claim['actor_user_id'] ?? 0 );
		self::emit_event( 'guru_turn_started', array( 'trace_id' => $trace_id, 'character_id' => (int) $claim['character_id'], 'channel' => 'zalo_personal', 'engine' => 'bot', 'conversation_id' => $conversation_id, 'trigger' => $trigger, 'actor_user_id' => $actor ) );
		// Trace for the Inbox "Thinking" timeline (ThinkingTimeline.jsx reads ai_metadata.steps).
		$trace_steps = array();

		if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
			BizCity_Responder_Stamper::push( array( 'kind' => 'hybrid' === ( $claim['mode'] ?? '' ) ? 'hybrid' : 'auto', 'character_id' => (int) $claim['character_id'], 'source' => 'bot:' . (int) $claim['character_id'] ) );
		}

		// [2026-09-24 Claude Opus 5.5] PHASE-0.60E EA-4 — "đang nhập…" for an automatic, sending turn. The bridge refreshes it
		// and stops it on the next send; a failure here is swallowed (a typing bubble must never cost the reply).
		$presence_ok = 'auto' === $trigger && 'auto' === (string) ( $claim['mode'] ?? 'auto' ) && class_exists( 'BizCity_Bot_Zalo_Actions' );
		if ( $presence_ok && ! empty( $claim['typing_indicator'] ) ) {
			BizCity_Bot_Zalo_Actions::presence( 'typing', $claim );
		}
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K8 — "đã xem" now, when the bot starts on the message (not for anything the
		// claim refused: those never reach run_turn). Same never-throws, never-blocks contract as typing.
		if ( $presence_ok && ! empty( $claim['seen_receipts'] ) ) {
			BizCity_Bot_Zalo_Actions::presence( 'seen', $claim );
		}

		$t_prep0 = microtime( true ); // everything from here to the tool loop is 'prep': memory recall, astro capture, registry
		try {
			$extra_system = array();
			$disclaimer   = '';
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H1 — what the bot remembers about THIS customer (private chats
			// only; '' otherwise). Goes first so tool results can refer back to it; prompt_for_turn() never throws.
			if ( class_exists( 'BizCity_Bot_Memory' ) ) {
				$memory_block = BizCity_Bot_Memory::prompt_for_turn( $claim );
				if ( '' !== $memory_block ) {
					$extra_system[] = $memory_block;
				}
			}
			// D-H7 — text a staff member typed before pressing "AI reply" is an instruction to the bot, never a
			// customer message: it steers this one turn and is not stored as the customer's words.
			$staff_instruction = trim( (string) ( $claim['staff_instruction'] ?? '' ) );
			if ( '' !== $staff_instruction ) {
				$extra_system[] = 'schedule' === $trigger
					? "=== VIỆC ĐÃ HẸN TỪ TRƯỚC (không phải lời khách) ===\nLàm việc này NGAY BÂY GIỜ và câu trả lời cuối của bạn sẽ được gửi cho khách. Không hỏi lại. Nếu không có gì mới để báo, trả lời đúng một dòng: [SILENT]\n" . mb_substr( $staff_instruction, 0, 2000 )
					: "=== YÊU CẦU CỦA NHÂN VIÊN (không phải lời khách) ===\n" . mb_substr( $staff_instruction, 0, 2000 );
			}

			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K2 — photos the customer sent in this turn become text (describe mode only).
			$vision_count = 0;
			if ( 'describe' === (string) ( $claim['vision_mode'] ?? 'off' ) && (int) ( $tuning['vision_max_images_per_turn'] ?? 0 ) > 0 && class_exists( 'BizCity_Bot_Vision' ) ) {
				$vision = self::vision_context( $claim, $tuning, $trace_id );
				foreach ( $vision['blocks'] as $vision_block ) {
					$extra_system[] = $vision_block;
				}
				$vision_count = $vision['count'];
			}

			// ── 0.60D S1 — capture a birth date the customer just gave (only when the bot asked). ──
			if ( class_exists( 'BizCity_Bot_Astro_Tool' ) && ! empty( $claim['text'] ) ) {
				$captured = BizCity_Bot_Astro_Tool::capture_from_message( $claim, (string) $claim['text'] );
				if ( ! empty( $captured['saved'] ) ) {
					$extra_system[] = '=== CHIÊM TINH ===\nĐã lưu ngày sinh khách vừa cho (' . BizCity_Bot_VN_Date::format_vn( $captured['date'] ) . '). Cảm ơn ngắn gọn rồi trả lời câu hỏi chiêm tinh trước đó.';
					$claim['astro_just_saved'] = true;
				} elseif ( in_array( $captured['status'] ?? '', array( 'ambiguous', 'invalid' ), true ) ) {
					$confirm = BizCity_Bot_Astro_Tool::confirm_instruction( $captured );
					if ( $confirm !== '' ) {
						$extra_system[] = $confirm;
					}
				}
			}

			// ── W5 — tools: plan → run, bounded, repeat-guarded. ──
			$tools = class_exists( 'BizCity_Bot_Tool_Registry' )
				? BizCity_Bot_Tool_Registry::effective( $character, (array) ( $claim['character_off'] ?? array() ), (array) ( $claim['binding_off'] ?? array() ), (array) ( $claim['enabled_optional'] ?? array() ) )
				: array();
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-7 (D-E2) — a second, per-TURN filter on
			// top of the character-level effective() above; list_threads/read_thread only survive
			// this when the exact sender/private-chat/owner_uid conditions hold for THIS message.
			if ( class_exists( 'BizCity_Bot_Tool_Registry' ) ) {
				$tools = BizCity_Bot_Tool_Registry::effective_for_turn( $tools, $claim );
			}
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K6 — Libe-Zalo `runsInScheduledTurn:false`: a scheduled turn keeps read-only tools and loses every
			// action tool (send, react, save memory, schedule, create files, tts, image…) — nobody is watching it run.
			if ( 'schedule' === $trigger ) {
				$tools = array_values( array_filter( $tools, static function ( $row ) { return 'read' === ( $row['group'] ?? '' ); } ) );
			}
			$tools_block = ! empty( $tools ) ? "=== CÔNG CỤ ĐÃ DÙNG ===\n(kết quả công cụ, nếu có, nằm trong các khối [DỮ LIỆU NGOÀI] bên dưới)" : '';
			$max_steps   = (int) $tuning['max_tool_steps'];
			self::$timing['prep_ms'] = (int) round( ( microtime( true ) - $t_prep0 ) * 1000 );
			$t_plan0 = microtime( true );
			$seen        = array();
			$context_opts = array(
				'history_limit'  => (int) $claim['history_limit'],
				'char_budget'    => (int) $tuning['history_char_budget'],
				'context_source' => (string) ( $claim['context_source'] ?? 'hybrid' ),
				// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-3.3
				'passive_listen_in_group' => ! isset( $claim['passive_listen_in_group'] ) || (bool) $claim['passive_listen_in_group'],
			);
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 (doc §6.1 G-04) — the only cross-step
			// state the tool loop now carries beyond $extra_system: an image the model generated
			// this turn, to be attached when the reply is sent below. Zero unless generate_image
			// actually succeeds (class-bot-tools.php enforces the real attachment-owner rule).
			$pending_image_attachment_id = 0;
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H3 — an MP3 the `tts` tool produced, sent after the text reply.
			$pending_audio_attachment_id = 0;
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K1 — members the `mention_member` tool asked to @tag at the head of the reply.
			$pending_mentions = array();
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4 — a file the `create_document` tool produced, sent after the text reply.
			$pending_document_attachment_id = 0;
			// D-K9 — planner_mode: 0 off · 1 auto (only when the message can need an available tool) · 2 always. `ran` / `skipped_auto` / `off`.
			$planner_mode = isset( $tuning['planner_mode'] ) ? (int) $tuning['planner_mode'] : 1;
			$planner_note = empty( $tools ) ? 'no_tools' : ( ( $max_steps > 0 && 0 !== $planner_mode ) ? 'ran' : 'off' );
			if ( 0 === $planner_mode ) {
				$max_steps = 0;
			}
			for ( $step = 0; $step < $max_steps && ! empty( $tools ) && class_exists( 'BizCity_Bot_Tools' ); $step++ ) {
				self::mark_stage( 'tools' );
				$probe = BizCity_Bot_Context_Builder::build( $character, $conversation_id, $contact_id, $context_opts + array( 'extra_system' => $extra_system ) );
				if ( 0 === $step && 1 === $planner_mode && ! BizCity_Bot_Tools::needs_planner( $probe['messages'], $tools, $claim ) ) {
					$planner_note = 'skipped_auto'; // chit-chat: no tool signal ⇒ no extra LLM round-trip
					break;
				}
				$plan  = BizCity_Bot_Tools::plan( $character, $probe['messages'], $tools );
				if ( ! $plan ) {
					break;
				}
				$run = BizCity_Bot_Tools::run( $plan['tool'], $plan['args'], $claim );
				$key = BizCity_Bot_Tools::repeat_key( $plan['tool'], $plan['args'], (string) ( $run['error'] ?? '' ) );
				if ( isset( $seen[ $key ] ) ) {
					break; // B7.5 — same tool + args + error again → stop.
				}
				$seen[ $key ] = true;
				self::emit_event( 'bot_tool_called', array( 'trace_id' => $trace_id, 'tool' => $plan['tool'], 'ok' => ! empty( $run['ok'] ), 'error' => (string) ( $run['error'] ?? '' ) ) );
				$trace_steps[] = array( 'name' => 'tool:' . $plan['tool'], 'ms' => 0, 'detail' => array( 'ok' => ! empty( $run['ok'] ), 'error' => (string) ( $run['error'] ?? '' ) ) );
				if ( ! empty( $run['ok'] ) && $run['content'] !== '' ) {
					$extra_system[] = (string) $run['content'];
					if ( ! empty( $run['disclaimer'] ) ) {
						$disclaimer = (string) $run['disclaimer'];
					}
					if ( ! empty( $run['image_attachment_id'] ) ) {
						$pending_image_attachment_id = (int) $run['image_attachment_id'];
					}
					if ( ! empty( $run['audio_attachment_id'] ) ) {
						$pending_audio_attachment_id = (int) $run['audio_attachment_id'];
					}
					if ( ! empty( $run['mention'] ) && is_array( $run['mention'] ) && count( $pending_mentions ) < BizCity_Bot_Mentions::MAX_TARGETS ) {
						$pending_mentions[] = array( 'uid' => (string) ( $run['mention']['uid'] ?? '' ), 'name' => (string) ( $run['mention']['name'] ?? '' ) );
					}
					if ( ! empty( $run['document_attachment_id'] ) ) {
						$pending_document_attachment_id = (int) $run['document_attachment_id'];
					}
					if ( ! empty( $run['ask'] ) ) {
						break; // the tool wants the model to ask the customer; no further tools this turn.
					}
				} else {
					$extra_system[] = '[Công cụ ' . $plan['tool'] . ' không dùng được: ' . (string) ( $run['error'] ?? 'unknown' ) . '. Trả lời không dựa vào nó và nói thật nếu cần.]';
				}
			}

			self::$timing['plan_ms'] = (int) round( ( microtime( true ) - $t_plan0 ) * 1000 ); // every planner call AND tool run of the loop
			$t_ctx0 = microtime( true );
			// ── context + model ──
			$built    = BizCity_Bot_Context_Builder::build( $character, $conversation_id, $contact_id, $context_opts + array( 'tools_block' => $tools_block, 'extra_system' => $extra_system ) );
			self::$timing['context_ms'] = (int) round( ( microtime( true ) - $t_ctx0 ) * 1000 );
			$messages = $built['messages'];
			$last     = end( $messages );
			if ( ! $last || 'user' !== ( $last['role'] ?? '' ) ) {
				// K2 — a photo-only message that was described is not "a message I cannot see": the description is in the system block.
				$fallback_text = '' !== $claim['text'] ? $claim['text'] : ( $vision_count > 0 ? '(Khách vừa gửi ' . $vision_count . ' ảnh — xem mô tả ở phần dữ liệu ngoài phía trên.)' : self::describe_no_text_message( (int) ( $claim['message_id'] ?? 0 ) ) );
				$messages[] = array( 'role' => 'user', 'content' => $fallback_text );
			}
			$trace_steps = array_merge( array( array( 'name' => 'resolve_context', 'ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'detail' => array( 'character_id' => (int) $claim['character_id'], 'engine' => 'bot_studio', 'trigger' => $trigger, 'prompt_chars' => mb_strlen( (string) ( $claim['text'] ?? '' ) ), 'history' => $built['meta'] ?? array() ) ) ), $trace_steps );
			self::mark_stage( 'llm' );
			$llm_t0 = microtime( true );
			$llm    = self::call_llm( $character, $messages, $claim );
			$reply  = ! empty( $llm['success'] ) ? trim( (string) ( $llm['message'] ?? '' ) ) : '';
			$trace_steps[] = array( 'name' => 'llm_generate', 'ms' => (int) round( ( microtime( true ) - $llm_t0 ) * 1000 ), 'detail' => array( 'reply_chars' => mb_strlen( $reply ), 'note' => '' === $reply ? (string) ( $llm['error'] ?? 'empty_reply' ) : '' ) );
			// PHASE-0.60K §15.2 — "LLM HTTP succeeded" is not "there is a final reply": `ok` is true only when text came back.
			$llm_bucket = '' !== $reply ? '' : ( ! empty( $llm['success'] ) ? 'llm_empty' : 'llm_error' );
			self::lifecycle( 'bot_turn_llm_completed', array(
				'trace_id'        => $trace_id,
				'conversation_id' => $conversation_id,
				'ok'              => '' !== $reply,
				'purpose'         => 'bot_reply',
				'trigger'         => $trigger,
				// what happens to the answer: sent to the customer, returned to the composer, or stored as an internal note
				'kind'            => ! empty( $claim['return_draft'] ) ? 'draft' : ( 'hybrid' === ( $claim['mode'] ?? '' ) ? 'hybrid_note' : 'send' ),
				'latency_ms'      => (int) round( ( microtime( true ) - $llm_t0 ) * 1000 ),
				'reply_chars'     => mb_strlen( $reply ),
				'tool_steps'      => count( $seen ),
				'reason_bucket'   => $llm_bucket,
				'prep_ms'         => (int) ( self::$timing['prep_ms'] ?? 0 ),
				'plan_ms'         => (int) ( self::$timing['plan_ms'] ?? 0 ),
				'context_ms'      => (int) ( self::$timing['context_ms'] ?? 0 ),
				'planner'         => $planner_note,
			) );
			self::$timing['t_after_llm'] = microtime( true );

			if ( '' === $reply ) {
				self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $trace_id, 'conversation_id' => $conversation_id, 'stage' => 'llm', 'reason_bucket' => $llm_bucket ) );
				// B4.7 — inherited obligation: provider dead ≠ customer left in silence. Never leak the raw error.
				// D-H7 — a composer turn is different: a staff member is watching and gets the error; sending the
				// customer an apology they never asked for would be wrong.
				$no_fallback = 'hybrid' === ( $claim['mode'] ?? '' ) || ! empty( $claim['return_draft'] ) || ! empty( $claim['no_fallback'] );
				$sent = $no_fallback ? array( 'ok' => false, 'message_id' => 0 ) : self::send( $claim, self::FALLBACK_TEXT, array( 'trace_id' => $trace_id, 'fallback' => true ) );
				self::emit_event( 'guru_turn_failed', array( 'trace_id' => $trace_id, 'reason' => 'provider_error', 'error' => self::safe_error( (string) ( $llm['error'] ?? 'empty_reply' ) ), 'fallback_sent' => ! empty( $sent['ok'] ), 'trigger' => $trigger ) );
				$result = array( 'status' => $no_fallback ? 'failed' : 'fallback', 'reply' => $no_fallback ? '' : self::FALLBACK_TEXT, 'reason' => 'provider_error', 'trace_id' => $trace_id, 'message_id' => (int) ( $sent['message_id'] ?? 0 ), 'steps' => $trace_steps );
				return $result;
			}

			if ( 'schedule' === $trigger && class_exists( 'BizCity_Bot_Schedule' ) && BizCity_Bot_Schedule::is_silent( $reply ) ) {
				// K6 — "nothing worth saying": no send, no daily-cap count, no fallback.
				self::emit_event( 'guru_turn_completed', array( 'trace_id' => $trace_id, 'character_id' => (int) $claim['character_id'], 'channel' => 'zalo_personal', 'engine' => 'bot', 'mode' => 'silent', 'trigger' => $trigger, 'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'reply_len' => 0 ) );
				$result = array( 'status' => 'silent', 'reply' => '', 'reason' => '', 'trace_id' => $trace_id, 'message_id' => 0, 'steps' => $trace_steps );
				return $result;
			}
			$reply = class_exists( 'BizCity_Bot_Vertical_Tools' )
				? BizCity_Bot_Vertical_Tools::trim_for_zalo( $reply, self::ZALO_REPLY_MAX_CHARS, $disclaimer )
				: mb_substr( $reply, 0, self::ZALO_REPLY_MAX_CHARS );

			if ( ! empty( $claim['return_draft'] ) ) {
				// D-H7 — composer "💡 Gợi ý": the text goes back to the staff member's composer. No CRM row, no send,
				// no daily-cap count — nothing reached the customer.
				self::emit_event( 'guru_turn_completed', array( 'trace_id' => $trace_id, 'character_id' => (int) $claim['character_id'], 'channel' => 'zalo_personal', 'engine' => 'bot', 'mode' => 'draft', 'trigger' => $trigger, 'actor_user_id' => $actor, 'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'reply_len' => mb_strlen( $reply ) ) );
				$result = array( 'status' => 'draft', 'reply' => $reply, 'reason' => '', 'trace_id' => $trace_id, 'message_id' => 0, 'steps' => $trace_steps );
				return $result;
			}

			if ( 'hybrid' === ( $claim['mode'] ?? '' ) ) {
				// B-04 "Chỉ gợi ý cho nhân viên": draft as an internal note, never auto-sent.
				$note_id = self::store_draft( $claim, $reply, $trace_id );
				self::emit_event( 'guru_turn_completed', array( 'trace_id' => $trace_id, 'character_id' => (int) $claim['character_id'], 'channel' => 'zalo_personal', 'engine' => 'bot', 'mode' => 'hybrid', 'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'reply_len' => mb_strlen( $reply ), 'draft_message_id' => $note_id ) );
				$result = array( 'status' => 'draft', 'reply' => $reply, 'reason' => '', 'trace_id' => $trace_id, 'message_id' => (int) $note_id, 'steps' => $trace_steps );
				return $result;
			}

			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K1 — @tags go at the head of the ONE reply (never a second message). Only for a
			// message that is really being sent: a draft/hybrid note above returned before this point.
			$mentions = array();
			if ( 'group' === (string) ( $claim['chat_kind'] ?? '' ) && class_exists( 'BizCity_Bot_Mentions' ) ) {
				$targets = $pending_mentions;
				$back    = self::tag_back_target( $claim, $tuning, $pending_mentions );
				if ( null !== $back ) {
					$targets[] = $back;
				}
				if ( ! empty( $targets ) ) {
					$applied  = BizCity_Bot_Mentions::apply( $reply, $targets );
					$reply    = $applied['text'];
					$mentions = $applied['mentions'];
				}
			}
			// [2026-09-24 Claude Opus 5.5] PHASE-0.60E EA-5.3 — react only to a message the bot is about to answer, so a
			// reaction never signals "the bot heard you" on a turn that was refused or produced nothing.
			if ( $presence_ok && ! empty( $claim['auto_react'] ) ) {
				BizCity_Bot_Zalo_Actions::presence( 'react', $claim );
			}
			// B5.5 — a human-ish pause before sending (only when running under cron, never in a unit test seam).
			self::human_delay( $tuning );
			self::mark_stage( 'dispatch' );
			$send_t0 = microtime( true );
			self::$timing['pre_send_ms'] = (int) round( ( $send_t0 - (float) ( self::$timing['t_after_llm'] ?? $send_t0 ) ) * 1000 ); // trim + typing/react + the human-ish pause
			$sent = self::send( $claim, $reply, array( 'trace_id' => $trace_id, 'image_attachment_id' => $pending_image_attachment_id, 'audio_attachment_id' => $pending_audio_attachment_id, 'document_attachment_id' => $pending_document_attachment_id, 'mentions' => $mentions ) );
			$trace_steps[] = array( 'name' => 'dispatch', 'ms' => (int) round( ( microtime( true ) - $send_t0 ) * 1000 ), 'detail' => array( 'sent' => ! empty( $sent['ok'] ), 'platform' => 'zalo_personal', 'error' => (string) ( $sent['error'] ?? '' ) ) );
			if ( empty( $sent['ok'] ) ) {
				self::emit_event( 'guru_turn_failed', array( 'trace_id' => $trace_id, 'reason' => 'send_failed', 'error' => (string) ( $sent['error'] ?? '' ), 'trigger' => $trigger ) );
				self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $trace_id, 'conversation_id' => $conversation_id, 'stage' => 'dispatch', 'code' => (string) ( $sent['error'] ?? '' ), 'reason_bucket' => (string) ( $sent['reason_bucket'] ?? '' ) ?: 'dispatch_failed' ) );
				$result = array( 'status' => 'send_failed', 'reply' => $reply, 'reason' => (string) ( $sent['error'] ?? 'send_failed' ), 'trace_id' => $trace_id, 'message_id' => (int) ( $sent['message_id'] ?? 0 ), 'steps' => $trace_steps );
				return $result;
			}
			BizCity_Bot_Turn_Claim::increment_today_count( $contact_id );
			$latency_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
			self::attach_trace( (int) ( $sent['message_id'] ?? 0 ), $trace_id, $claim, $trace_steps, $latency_ms );
			self::emit_event( 'guru_turn_completed', array( 'trace_id' => $trace_id, 'character_id' => (int) $claim['character_id'], 'channel' => 'zalo_personal', 'engine' => 'bot', 'mode' => 'auto', 'trigger' => $trigger, 'actor_user_id' => $actor, 'latency_ms' => $latency_ms, 'reply_len' => mb_strlen( $reply ), 'message_id' => (int) ( $sent['message_id'] ?? 0 ), 'history' => $built['meta'] ) );
			// PHASE-0.60K §15.2/§15.3 Slice C — Goal Loop is NOT wired into Bot Studio yet (its post_turn needs the
			// canonical TwinBrain runtime + a resolved identity_uuid/session). Until it is, say SKIP with the real
			// reason on every turn — never a PASS this code did not earn.
			self::lifecycle( 'goal_loop_post_turn', array( 'trace_id' => $trace_id, 'conversation_id' => $conversation_id, 'state' => 'skip', 'reason_bucket' => 'goal_loop_not_wired' ) );
			// [2026-09-23 03:50 PM Claude Fable 5.1] PHASE-0.60D Q-D2 — explicit "after bot replied" mark for automation (the dispatcher's outgoing row also fires bizcity_crm_message_inserted).
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60G G2 — the reply is already delivered here. A throwing
			// listener must not reach the outer catch, which would send FALLBACK_TEXT as a second message.
			try {
				do_action( 'bizcity_bot_turn_completed', array(
					'conversation_id' => $conversation_id,
					'contact_id'      => $contact_id,
					'character_id'    => (int) $claim['character_id'],
					'message_id'      => (int) ( $sent['message_id'] ?? 0 ),
					'account_id'      => (string) $claim['account_id'],
					'chat_id'         => (string) $claim['chat_id'],
					'trace_id'        => $trace_id,
				) );
			} catch ( \Throwable $listener_error ) {
				error_log( '[bot-turn] bizcity_bot_turn_completed listener threw after delivery: ' . get_class( $listener_error ) . ': ' . $listener_error->getMessage() );
			}
			$result = array( 'status' => 'sent', 'reply' => $reply, 'reason' => '', 'trace_id' => $trace_id, 'message_id' => (int) ( $sent['message_id'] ?? 0 ), 'steps' => $trace_steps, 'latency_ms' => $latency_ms );
			return $result;
		} catch ( \Throwable $e ) {
			$no_fallback = 'hybrid' === ( $claim['mode'] ?? '' ) || ! empty( $claim['return_draft'] ) || ! empty( $claim['no_fallback'] );
			$sent = $no_fallback ? array( 'ok' => false ) : self::send( $claim, self::FALLBACK_TEXT, array( 'trace_id' => $trace_id, 'fallback' => true ) );
			self::emit_event( 'guru_turn_failed', array( 'trace_id' => $trace_id, 'reason' => 'exception', 'error' => self::safe_error( $e->getMessage() ), 'fallback_sent' => ! empty( $sent['ok'] ), 'trigger' => $trigger ) );
			// The message is not logged here (it can quote a prompt or a key); the class is enough to find it.
			self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $trace_id, 'conversation_id' => $conversation_id, 'stage' => 'run', 'code' => sanitize_key( get_class( $e ) ), 'reason_bucket' => 'exception', 'fallback' => ! empty( $sent['ok'] ) ) );
			$result = array( 'status' => $no_fallback ? 'failed' : 'fallback', 'reply' => $no_fallback ? '' : self::FALLBACK_TEXT, 'reason' => 'exception', 'trace_id' => $trace_id, 'message_id' => 0, 'steps' => $trace_steps );
			return $result;
		} finally {
			if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
				BizCity_Responder_Stamper::pop();
			}
			self::$running = null; // finished normally (or via a caught error): the shutdown guard has nothing to report.
			self::unlock( $contact_id );
			BizCity_Bot_Turn_Claim::clear_active( $contact_id );
		}
	}

	/** Remember which stage the running turn is in, so an abort can say where it died. */
	private static function mark_stage( string $stage ): void {
		if ( is_array( self::$running ) ) {
			self::$running['stage'] = $stage;
		}
	}

	/**
	 * Shutdown guard. If a turn is still marked running when PHP shuts down, it did not finish: a fatal, a
	 * max_execution_time hit, or an exit. Report the stage it was in, free the thread lock (otherwise the customer's next
	 * message and the composer's AI reply are refused as `bot_busy` until the lock expires) and clear the active row.
	 * A web-server kill (SIGKILL / request_terminate_timeout) skips PHP's shutdown; that case is still visible as a
	 * `stalled` turn whose last event is bot_turn_cron_started. Public for tests.
	 */
	public static function on_shutdown(): void {
		$run = self::$running;
		if ( ! is_array( $run ) ) {
			return;
		}
		self::$running = null;
		$err   = function_exists( 'error_get_last' ) ? error_get_last() : null;
		$fatal = is_array( $err ) && in_array( (int) $err['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
		self::lifecycle( 'bot_turn_failed', array(
			'trace_id'        => $run['trace_id'],
			'conversation_id' => $run['conversation_id'],
			'stage'           => $run['stage'],
			'latency_ms'      => (int) round( ( microtime( true ) - $run['started'] ) * 1000 ),
			'code'            => $fatal ? 'php_fatal' : 'aborted',
			'reason_bucket'   => $fatal ? ( false !== stripos( (string) $err['message'], 'maximum execution time' ) ? 'time_limit' : 'php_fatal' ) : 'aborted_mid_turn',
		) );
		if ( $fatal ) {
			// the message only (no file path), redacted and capped — it is what tells a timeout from a code bug.
			self::emit_event( 'bot_turn_aborted', array( 'trace_id' => $run['trace_id'], 'stage' => $run['stage'], 'error' => self::safe_error( (string) $err['message'] ) ) );
		}
		self::unlock( $run['contact_id'] );
		if ( class_exists( 'BizCity_Bot_Turn_Claim' ) ) {
			BizCity_Bot_Turn_Claim::clear_active( $run['contact_id'] );
		}
	}

	/* ── D-H7: composer "AI reply" / "Gợi ý" → the same Bot Studio turn ─ */

	/**
	 * [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 — the ONE entry a staff member's composer button uses for a
	 * Zalo Cá nhân conversation. It builds the same claim a webhook turn builds and runs the same run_turn(), so
	 * persona, tools, context, lock, daily cap and the Twin event stream are shared; only the trigger differs.
	 *
	 * Deliberate differences from an automatic turn (a person asked for it, and is watching):
	 *  - pause / office hours / allowlist / binding mode are NOT applied — they exist to keep the bot quiet
	 *    when nobody asked; here somebody did.
	 *  - still applied: a bound Guru, the thread lock, and the daily cap when something is actually sent.
	 *  - a provider failure is returned to the staff member; the customer never gets the fallback apology.
	 *
	 * @param array $opts { draft?:bool (true = text back to the composer, nothing sent), instruction?:string, actor_user_id?:int }
	 * @return array{status:string,reply:string,reason:string,trace_id:string,message_id:int,steps:array}
	 */
	public static function run_on_request( int $conversation_id, array $opts = array() ): array {
		$claim = self::claim_for_conversation( $conversation_id, $opts );
		if ( is_string( $claim ) ) {
			return array( 'status' => 'refused', 'reply' => '', 'reason' => $claim, 'trace_id' => '', 'message_id' => 0, 'steps' => array() );
		}
		return self::run_turn( $claim );
	}

	/**
	 * Public for tests. Returns the claim, or a refusal reason string.
	 *
	 * @return array|string
	 */
	public static function claim_for_conversation( int $conversation_id, array $opts = array() ) {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_Channel_Binding' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) || ! class_exists( 'BizCity_Bot_Turn_Claim' ) ) {
			return 'module_not_loaded';
		}
		$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
		if ( ! is_array( $conversation ) ) {
			return 'conversation_not_found';
		}
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! is_array( $inbox ) || BizCity_Bot_Turn_Claim::CODE !== strtolower( (string) ( $inbox['channel_type'] ?? '' ) ) ) {
			return 'not_bot_channel';
		}
		$account_id = (string) ( $inbox['channel_ref_id'] ?? '' );
		if ( '' === $account_id ) {
			return 'inbox_not_bound';
		}
		$ref = method_exists( 'BizCity_CRM_Repository', 'get_conversation_thread_ref' )
			? BizCity_CRM_Repository::get_conversation_thread_ref( $conversation_id )
			: array( 'contact_id' => (int) ( $conversation['contact_id'] ?? 0 ), 'source_id' => (string) ( $conversation['source_id'] ?? '' ) );
		$contact_id = (int) ( $ref['contact_id'] ?? 0 );
		$source_id  = (string) ( $ref['source_id'] ?? '' );
		if ( $contact_id <= 0 || '' === $source_id ) {
			return 'contact_missing';
		}
		$binding = BizCity_Channel_Binding::resolve( BizCity_Bot_Turn_Claim::PLATFORM, $account_id );
		$character_id = is_array( $binding ) ? (int) ( $binding['character_id'] ?? 0 ) : 0;
		if ( $character_id <= 0 ) {
			return 'bot_not_bound';
		}
		$draft  = ! empty( $opts['draft'] );
		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		if ( ! $draft && BizCity_Bot_Turn_Claim::today_count( $contact_id ) >= (int) $tuning['daily_message_cap'] ) {
			return 'daily_cap';
		}
		if ( self::is_locked( $contact_id ) ) {
			return 'busy';
		}

		// The customer's latest words — for astro capture and the no-history fallback, exactly as a webhook turn.
		$text = '';
		$message_id = 0;
		foreach ( array_reverse( (array) BizCity_CRM_Repository::list_messages( $conversation_id, 50 ) ) as $m ) {
			if ( 'incoming' === (string) ( $m['message_type'] ?? '' ) ) {
				$text       = (string) ( $m['content'] ?? '' );
				$message_id = (int) ( $m['id'] ?? 0 );
				break;
			}
		}

		$is_group   = 0 === strpos( $source_id, 'group:' );
		$group_id   = $is_group ? substr( $source_id, 6 ) : '';
		$peer       = $is_group ? $group_id : $source_id;
		$policy     = BizCity_Bot_Turn_Claim::decode_office_hours( $binding['office_hours_json'] ?? '' );
		$bot_policy = BizCity_Bot_Turn_Claim::decode_office_hours( $binding['policy_json'] ?? '' );
		$settings   = BizCity_Bot_Config_Repo::get( $character_id );
		$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'composer_', true );

		return array(
			'character_id'        => $character_id,
			'binding_id'          => (int) ( $binding['id'] ?? 0 ),
			'account_id'          => $account_id,
			'chat_id'             => 'zalop_' . $account_id . '_' . $peer,
			'chat_kind'           => $is_group ? 'group' : 'user',
			'contact_id'          => $contact_id,
			'sender_uid'          => $is_group ? '' : $source_id,
			'source_id'           => $source_id,
			'group_id'            => $group_id,
			'owner_uid'           => trim( (string) ( $bot_policy['owner_uid'] ?? '' ) ),
			'mode'                => 'auto',
			'text'                => $text,
			// A fresh id per click: the dispatcher's idempotency key must not collapse two deliberate requests.
			'external_message_id' => 'composer:' . $request_id,
			'history_limit'       => (int) $settings['history_limit'],
			'bypass_notebook'     => ! empty( $settings['bypass_notebook'] ),
			'context_source'      => (string) $settings['context_source'],
			'character_off'       => (array) $settings['disabled_tools'],
			'enabled_optional'    => (array) ( $settings['enabled_optional_tools'] ?? array() ),
			'vision_mode'         => (string) ( $settings['vision_mode'] ?? 'off' ),
			'binding_off'         => BizCity_Bot_Config_Repo::sanitize_tool_list( $policy['disabled_tools'] ?? array() ),
			'passive_listen_in_group' => ! isset( $bot_policy['passive_listen_in_group'] ) || (bool) $bot_policy['passive_listen_in_group'],
			'workflow_matched'    => false,
			'claimed_at'          => time(),
			'conversation_id'     => $conversation_id,
			'message_id'          => $message_id,
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K6 — a scheduled agent job is the same turn with trigger `schedule` (fewer tools, [SILENT] allowed).
			'trigger'             => in_array( (string) ( $opts['trigger'] ?? '' ), array( 'composer', 'schedule' ), true ) ? (string) $opts['trigger'] : 'composer',
			'actor_user_id'       => (int) ( $opts['actor_user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ),
			'staff_instruction'   => (string) ( $opts['instruction'] ?? '' ),
			'return_draft'        => $draft,
			'no_fallback'         => true,
		);
	}

	/** Write the turn's trace onto the CRM row the dispatcher created, so the Inbox shows "Thinking". */
	private static function attach_trace( int $message_id, string $trace_id, array $claim, array $steps, int $latency_ms ): void {
		if ( $message_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'set_message_ai_metadata' ) ) {
			return;
		}
		try {
			BizCity_CRM_Repository::set_message_ai_metadata( $message_id, array(
				'trace_uuid'   => $trace_id,
				'engine'       => 'bot_studio',
				'trigger'      => (string) ( $claim['trigger'] ?? 'auto' ),
				'character_id' => (int) ( $claim['character_id'] ?? 0 ),
				'notebook_id'  => 0,
				'latency_ms'   => $latency_ms,
				'steps'        => $steps,
				'sources'      => array(),
			) );
		} catch ( \Throwable $e ) {
			// the reply is already delivered; a missing trace must never turn into a fallback message — but it is logged.
			self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $trace_id, 'message_id' => $message_id, 'stage' => 'attach_trace', 'code' => sanitize_key( get_class( $e ) ), 'reason_bucket' => 'trace_attach_failed', 'fallback' => false ) );
		}
	}

	/** LLM call — fast path (persona + history) or the Guru_Runtime path when notebook use is on (B6.5). */
	private static function call_llm( $character, array $messages, array $claim ): array {
		if ( is_callable( self::$llm ) ) {
			return (array) call_user_func( self::$llm, $character, $messages, $claim );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array( 'success' => false, 'message' => '', 'error' => 'llm_missing' );
		}
		if ( empty( $claim['bypass_notebook'] ) && class_exists( 'BizCity_Guru_Runtime' ) ) {
			// D-3 — same pipeline as every other channel: L1/L2 free, L3 only when a notebook is bound.
			$notebook_id = self::default_notebook_id( $character );
			$history = array();
			$prompt  = '';
			foreach ( $messages as $m ) {
				if ( 'system' === $m['role'] ) {
					$history[] = $m; // Guru_Runtime keeps system rows verbatim after its own system block.
					continue;
				}
				$history[] = $m;
			}
			$last = array_pop( $history );
			$prompt = is_array( $last ) ? (string) $last['content'] : (string) $claim['text'];
			$dto = BizCity_Guru_Runtime::instance()->reply( array(
				'character_id' => (int) $claim['character_id'],
				'notebook_id'  => $notebook_id,
				'channel'      => 'zalo_personal',
				'prompt'       => $prompt,
				'history'      => $history,
				'user_id'      => 0,
			), array( 'purpose' => 'bot_reply' ) );
			if ( is_wp_error( $dto ) ) {
				return array( 'success' => false, 'message' => '', 'error' => $dto->get_error_code() );
			}
			if ( is_object( $dto ) && ! empty( $dto->error ) ) {
				return array( 'success' => false, 'message' => '', 'error' => (string) ( $dto->error['code'] ?? 'guru_error' ) );
			}
			return array( 'success' => true, 'message' => is_object( $dto ) ? (string) $dto->text : '', 'error' => '' );
		}
		return BizCity_LLM_Client::instance()->chat_with_character( $character, $messages );
	}

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 (doc §6.1, "STT inbound thật ... chưa làm") — a
	 * message with no text is very often a voice message, image, or file the bot has no STT/vision
	 * path for yet, NOT an empty message. The old placeholder "(tin nhắn không có chữ)" gave the
	 * model nothing to react to honestly, so it would confidently answer a question that was never
	 * asked. This looks up the real message row and gives the model something true to say instead —
	 * a stopgap, not real STT/vision (that needs `class-bot-turn-claim.php` to carry attachment data
	 * at all, which it does not today — a bigger, separate change).
	 */
	private static function describe_no_text_message( int $message_id ): string {
		$generic = '(Khách vừa gửi một tin nhắn không có chữ. Không đoán nội dung — trả lời ngắn gọn rằng bạn chưa đọc được loại tin này, xin khách mô tả lại bằng chữ hoặc chờ nhân viên hỗ trợ.)';
		if ( $message_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'get_message' ) ) {
			return $generic;
		}
		try {
			$message = BizCity_CRM_Repository::get_message( $message_id );
		} catch ( \Throwable $e ) {
			return $generic;
		}
		if ( ! is_array( $message ) ) {
			return $generic;
		}
		$attachments = (array) ( $message['attachments'] ?? array() );
		if ( empty( $attachments ) ) {
			return $generic; // genuinely empty text, not an unreadable attachment.
		}
		$first     = reset( $attachments );
		$file_type = is_array( $first ) ? (string) ( $first['file_type'] ?? '' ) : '';
		if ( 'image' === $file_type ) {
			return '(Khách vừa gửi một ẢNH, không kèm chữ. Bot hiện chưa xem được nội dung ảnh — trả lời ngắn gọn rằng bạn chưa xem được ảnh, xin khách mô tả bằng chữ hoặc chờ nhân viên hỗ trợ.)';
		}
		// Voice messages land here too (content_type='file', no dedicated audio marker today).
		return '(Khách vừa gửi một TỆP hoặc TIN NHẮN THOẠI, không kèm chữ. Bot hiện chưa nghe/đọc được nội dung này — trả lời ngắn gọn rằng bạn chưa xử lý được loại tin này, xin khách gõ lại bằng chữ hoặc chờ nhân viên hỗ trợ.)';
	}

	/**
	 * First notebook bound to the character (KG attachment table, legacy character_id fallback) — the
	 * same lookup class-character-quick-edit-rest.php uses for "Notebooks đã gắn". Filterable.
	 */
	private static function default_notebook_id( $character ): int {
		// [2026-09-23 03:50 PM Claude Fable 5.1] PHASE-0.60A B6.5 — notebook path only when bypass is OFF.
		$id = (int) apply_filters( 'bizcity_bot_default_notebook_id', 0, $character );
		if ( $id > 0 || ! class_exists( 'BizCity_KG_Database' ) ) {
			return $id;
		}
		global $wpdb;
		try {
			$kg     = BizCity_KG_Database::instance();
			$tbl_nb = $kg->tbl_notebooks();
			$id     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl_nb} WHERE character_id = %d ORDER BY updated_at DESC LIMIT 1", (int) ( $character->id ?? 0 ) ) );
		} catch ( \Throwable $e ) {
			$id = 0;
		}
		return $id;
	}

	/**
	 * Send through the canonical CRM outbound owner. Returns {ok, message_id, error}.
	 *
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 (doc §6.1 G-04) — `meta.image_attachment_id`
	 * (from a successful generate_image tool call, class-bot-tools.php) sends as content_type=image.
	 * class-bot-tools.php::resolve_attachment_owner() already checked an owner exists before the
	 * image was even generated, but that owner can theoretically change between tool-call time and
	 * send time (assignee reassigned mid-turn) — so if the dispatcher STILL rejects the attachment,
	 * this falls back to a plain text send rather than losing the customer's reply entirely (B4.7:
	 * a media failure must never mean silence).
	 */
	private static function send( array $claim, string $text, array $meta = array() ): array {
		if ( is_callable( self::$sender ) ) {
			return (array) call_user_func( self::$sender, $claim, $text, $meta );
		}
		$conversation_id      = (int) ( $claim['conversation_id'] ?? 0 );
		$attempt              = ! empty( $meta['fallback'] ) ? 'fb' : 'r';
		$image_attachment_id  = (int) ( $meta['image_attachment_id'] ?? 0 );
		$idem_base            = $conversation_id . '|' . (string) ( $claim['external_message_id'] ?? '' ) . '|' . (string) ( $claim['message_id'] ?? '' ) . '|' . $attempt;
		if ( class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) || is_callable( self::$dispatcher ) ) {
			$fallback = ! empty( $meta['fallback'] );
			if ( $image_attachment_id > 0 ) {
				$image_idem = 'bot-img-' . md5( $idem_base );
				$envelope   = self::dispatch_with_evidence( array(
					'conversation_id' => $conversation_id,
					'content'         => $text,
					'content_type'    => 'image',
					'attachments'     => array( $image_attachment_id ),
					'idempotency_key' => $image_idem,
					'request_hash'    => md5( $text . '|' . $image_attachment_id ),
					'actor'           => 'system',
					'system_source'   => 'ai_autoreply',
					'responder_kind'  => 'auto',
					'trace_id'        => (string) ( $meta['trace_id'] ?? '' ),
				), 'image', $fallback );
				$ok = is_array( $envelope ) && 'failed' !== (string) ( $envelope['outcome'] ?? $envelope['status'] ?? 'failed' );
				if ( $ok ) {
					self::send_voice_followup( $conversation_id, (int) ( $meta['audio_attachment_id'] ?? 0 ), $idem_base, (string) ( $meta['trace_id'] ?? '' ) );
					self::send_document_followup( $conversation_id, (int) ( $meta['document_attachment_id'] ?? 0 ), $idem_base, (string) ( $meta['trace_id'] ?? '' ) );
					return array( 'ok' => true, 'message_id' => (int) ( $envelope['message_id'] ?? 0 ), 'error' => '', 'reason_bucket' => '' );
				}
				self::emit_event( 'bot_image_send_fallback_to_text', array(
					'conversation_id' => $conversation_id,
					'trace_id'        => (string) ( $meta['trace_id'] ?? '' ),
					'reason'          => (string) ( $envelope['code'] ?? $envelope['error'] ?? 'dispatch_failed' ),
				) );
				// fall through to a plain text send below — never drop the reply because the image failed.
			}
			$idem     = 'bot-' . md5( $idem_base );
			$envelope = self::dispatch_with_evidence( array(
				'conversation_id' => $conversation_id,
				'content'         => $text,
				'content_type'    => 'text',
				// PHASE-0.60K K1 — {pos,len,uid} over UTF-16; the dispatcher validates them again and drops them on attachments.
				'mentions'        => (array) ( $meta['mentions'] ?? array() ),
				'idempotency_key' => $idem,
				'request_hash'    => md5( $text ),
				'actor'           => 'system',
				'system_source'   => 'ai_autoreply',
				'responder_kind'  => 'auto',
				'trace_id'        => (string) ( $meta['trace_id'] ?? '' ),
			), 'text', $fallback );
			$ok = is_array( $envelope ) && 'failed' !== (string) ( $envelope['outcome'] ?? $envelope['status'] ?? 'failed' );
			if ( $ok ) {
				self::send_voice_followup( $conversation_id, (int) ( $meta['audio_attachment_id'] ?? 0 ), $idem_base, (string) ( $meta['trace_id'] ?? '' ) );
					self::send_document_followup( $conversation_id, (int) ( $meta['document_attachment_id'] ?? 0 ), $idem_base, (string) ( $meta['trace_id'] ?? '' ) );
			}
			return array(
				'ok'            => $ok,
				'message_id'    => (int) ( $envelope['message_id'] ?? 0 ),
				'error'         => $ok ? '' : (string) ( $envelope['code'] ?? $envelope['error'] ?? 'dispatch_failed' ),
				'reason_bucket' => $ok ? '' : (string) ( $envelope['reason_bucket'] ?? '' ),
			);
		}
		// Last resort (dispatcher not loaded): still exactly one message, through the existing bridge boundary.
		// [OW-4] no attachment support on this fallback path — it predates image-out and only ever
		// sent 'text'; an image_attachment_id here is silently dropped, same as before this change.
		if ( class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			$res = BizCity_Zalo_Bridge_Client::instance()->enqueue_outbound( (string) $claim['account_id'], self::peer_from_chat_id( (string) $claim['chat_id'], (string) $claim['account_id'] ), $text, 'text', array(), 'group' === ( $claim['chat_kind'] ?? '' ) ? 'group' : 'user', array(), 'bot-' . md5( $idem_base ) );
			return array( 'ok' => ! empty( $res['success'] ), 'message_id' => 0, 'error' => ! empty( $res['success'] ) ? '' : 'bridge_' . (string) ( $res['code'] ?? 'failed' ) );
		}
		return array( 'ok' => false, 'message_id' => 0, 'error' => 'no_sender' );
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H3 — the MP3 from the `tts` tool, as its OWN message right after the
	 * text reply was accepted. Never blocks or fails the turn: the customer already has the answer in text; a voice
	 * failure is only an event. Public for tests.
	 *
	 * @return array{sent:bool,code:string}
	 */
	public static function send_voice_followup( int $conversation_id, int $audio_attachment_id, string $idem_base, string $trace_id ): array {
		if ( $audio_attachment_id <= 0 || $conversation_id <= 0 || ( ! class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) && ! is_callable( self::$dispatcher ) ) ) {
			return array( 'sent' => false, 'code' => 'skipped' );
		}
		$envelope = self::dispatch_with_evidence( array(
			'conversation_id' => $conversation_id,
			'content'         => '',
			'content_type'    => 'file',
			'attachments'     => array( $audio_attachment_id ),
			'idempotency_key' => 'bot-voice-' . md5( $idem_base ),
			'request_hash'    => md5( 'voice|' . $audio_attachment_id ),
			'actor'           => 'system',
			'system_source'   => 'ai_autoreply',
			'responder_kind'  => 'auto',
			'trace_id'        => $trace_id,
		), 'voice', false );
		$ok   = is_array( $envelope ) && 'failed' !== (string) ( $envelope['outcome'] ?? $envelope['status'] ?? 'failed' );
		$code = is_array( $envelope ) ? (string) ( $envelope['code'] ?? '' ) : 'dispatch_failed';
		if ( ! $ok ) {
			self::emit_event( 'bot_voice_send_failed', array( 'conversation_id' => $conversation_id, 'trace_id' => $trace_id, 'reason' => $code ) );
		}
		return array( 'sent' => $ok, 'code' => $code );
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K2 — describe this turn's customer photos and return the system blocks for the model.
	 * A photo that cannot be fetched/described is reported as exactly that (Libe-Zalo: without it the model invents a reason).
	 * The description is third-party text (words inside a photo can be instructions) so it is fenced as unverified.
	 *
	 * @return array{blocks:string[],count:int}
	 */
	private static function vision_context( array $claim, array $tuning, string $trace_id ): array {
		$out = array( 'blocks' => array(), 'count' => 0 );
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return $out;
		}
		try {
			$rows = (array) BizCity_CRM_Repository::list_messages( (int) $claim['conversation_id'], 40, 0 );
			$imgs = BizCity_Bot_Vision::turn_images( $rows, (int) ( $claim['message_id'] ?? 0 ), (int) $tuning['vision_max_images_per_turn'] );
		} catch ( Throwable $e ) {
			return $out;
		}
		$described = 0;
		$cached    = 0;
		$failed    = 0;
		foreach ( $imgs as $n => $img ) {
			$d = BizCity_Bot_Vision::describe( $img );
			if ( empty( $d['ok'] ) ) {
				$failed++;
				continue;
			}
			$described++;
			$cached += ! empty( $d['cached'] ) ? 1 : 0;
			$out['blocks'][] = BizCity_Bot_Tools::fence( 'Ảnh khách vừa gửi (' . ( $n + 1 ) . '/' . count( $imgs ) . ', mô tả tự động' . ( ! empty( $d['truncated'] ) ? ', có thể bị cắt' : '' ) . ')', (string) $d['text'] );
		}
		if ( $failed > 0 ) {
			$out['blocks'][] = '[' . $failed . ' ảnh khách vừa gửi bị lỗi khi tải hoặc không xem được — hãy nói thật là chưa xem được ảnh đó, KHÔNG đoán nội dung.]';
		}
		$out['count'] = $described;
		if ( ! empty( $imgs ) ) {
			// counters only — never a description, a URL or a customer id.
			self::emit_event( 'bot_vision_described', array( 'trace_id' => $trace_id, 'conversation_id' => (int) $claim['conversation_id'], 'images' => count( $imgs ), 'described' => $described, 'cached' => $cached, 'failed' => $failed ) );
		}
		return $out;
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K1 — who to tag back when the bot itself was @-mentioned in a group.
	 * Null when: not mentioned / policy off / no sender uid / sender not in the roster (no name to write — never guess one) /
	 * a tool already tags them / the cooldown for this (group, sender) has not passed. The cooldown is stamped BEFORE the
	 * send (Libe-Zalo rule): a slow send must not let a second turn tag the same person again.
	 *
	 * @param array<int,array{uid:string,name:string}> $already Targets the model chose with mention_member.
	 * @return array{uid:string,name:string}|null
	 */
	private static function tag_back_target( array $claim, array $tuning, array $already ): ?array {
		if ( empty( $claim['mention_detected'] ) || empty( $claim['auto_tag_back'] ) || ! class_exists( 'BizCity_Bot_Zalo_Actions' ) ) {
			return null;
		}
		$uid      = trim( (string) ( $claim['sender_uid'] ?? '' ) );
		$group_id = (string) ( $claim['group_id'] ?? '' );
		$account  = (string) ( $claim['account_id'] ?? '' );
		if ( ! preg_match( '/^\d{3,32}$/', $uid ) || '' === $group_id || '' === $account ) {
			return null;
		}
		foreach ( $already as $t ) {
			if ( (string) ( $t['uid'] ?? '' ) === $uid ) {
				return null;
			}
		}
		$name = (string) ( BizCity_Bot_Zalo_Actions::roster( $account, $group_id )[ $uid ] ?? '' );
		if ( '' === $name ) {
			return null;
		}
		$key = 'bzbot_tagback_' . self::blog() . '_' . md5( $account ) . '_' . md5( $group_id ) . '_' . $uid;
		if ( get_transient( $key ) ) {
			return null;
		}
		set_transient( $key, time(), max( 5, (int) ( $tuning['tagback_cooldown_seconds'] ?? 45 ) ) );
		return array( 'uid' => $uid, 'name' => $name );
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4 — the file from `create_document`, as its OWN message right after the text reply was
	 * accepted. Same contract as the voice follow-up: never blocks or fails the turn (the customer already has the answer in text);
	 * a send failure is an event. The idempotency key derives from the ATTACHMENT id, so a retried turn cannot deliver the file twice
	 * and two different files in one conversation can never collide (K0-1). Public for tests.
	 *
	 * @return array{sent:bool,code:string}
	 */
	public static function send_document_followup( int $conversation_id, int $attachment_id, string $idem_base, string $trace_id ): array {
		if ( $attachment_id <= 0 || $conversation_id <= 0 || ( ! class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) && ! is_callable( self::$dispatcher ) ) ) {
			return array( 'sent' => false, 'code' => 'skipped' );
		}
		$envelope = self::dispatch_with_evidence( array(
			'conversation_id' => $conversation_id,
			'content'         => '',
			'content_type'    => 'file',
			'attachments'     => array( $attachment_id ),
			'idempotency_key' => 'bot-doc-' . $attachment_id,
			'request_hash'    => md5( 'doc|' . $attachment_id ),
			'actor'           => 'system',
			'system_source'   => 'ai_autoreply',
			'responder_kind'  => 'auto',
			'trace_id'        => $trace_id,
		), 'document', false );
		$ok   = is_array( $envelope ) && 'failed' !== (string) ( $envelope['outcome'] ?? $envelope['status'] ?? 'failed' );
		$code = is_array( $envelope ) ? (string) ( $envelope['code'] ?? '' ) : 'dispatch_failed';
		if ( ! $ok ) {
			self::emit_event( 'bot_document_send_failed', array( 'conversation_id' => $conversation_id, 'trace_id' => $trace_id, 'reason' => $code ) );
		}
		return array( 'sent' => $ok, 'code' => $code );
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K3 — the message an ASYNC media job sends when it finishes (a file) or fails (one short
	 * text), through the same evidence wrapper as every other Bot Studio send. The idempotency key comes from the caller (per job) so a
	 * re-fired cron can never deliver twice, and two jobs with empty captions can never collapse (K0-1). Public for the job worker.
	 *
	 * @return array{sent:bool,code:string}
	 */
	public static function send_media_message( int $conversation_id, string $text, int $attachment_id, string $idem_key, string $trace_id, string $kind, array $mentions = array() ): array {
		if ( $conversation_id <= 0 || ( '' === $text && $attachment_id <= 0 ) || ( ! class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) && ! is_callable( self::$dispatcher ) ) ) {
			return array( 'sent' => false, 'code' => 'skipped' );
		}
		$request = array(
			'conversation_id' => $conversation_id,
			'content'         => $text,
			'content_type'    => $attachment_id > 0 ? 'file' : 'text',
			'idempotency_key' => $idem_key,
			'request_hash'    => md5( $kind . '|' . $attachment_id . '|' . $text ),
			'actor'           => 'system',
			'system_source'   => 'ai_autoreply',
			'responder_kind'  => 'auto',
			'trace_id'        => $trace_id,
		);
		if ( $attachment_id > 0 ) {
			$request['attachments'] = array( $attachment_id );
		} elseif ( ! empty( $mentions ) ) {
			$request['mentions'] = $mentions; // {pos,len,uid} over UTF-16 — the dispatcher validates them again (K1).
		}
		try {
			$envelope = self::dispatch_with_evidence( $request, 'media_' . $kind, false );
		} catch ( \Throwable $e ) {
			return array( 'sent' => false, 'code' => 'dispatch_exception' ); // already reported by dispatch_with_evidence().
		}
		$ok = is_array( $envelope ) && 'failed' !== (string) ( $envelope['outcome'] ?? $envelope['status'] ?? 'failed' );
		return array( 'sent' => $ok, 'code' => is_array( $envelope ) ? (string) ( $envelope['code'] ?? '' ) : 'dispatch_failed' );
	}

	/** Hybrid mode: the suggestion lands as an internal note the agent can copy/send (B-04). */
	private static function store_draft( array $claim, string $reply, string $trace_id ): int {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return 0;
		}
		$conversation = BizCity_CRM_Repository::get_conversation( (int) $claim['conversation_id'] );
		if ( ! is_array( $conversation ) ) {
			return 0;
		}
		return (int) BizCity_CRM_Repository::insert_message( array(
			'conversation_id' => (int) $claim['conversation_id'],
			'inbox_id'        => (int) ( $conversation['inbox_id'] ?? 0 ),
			'content'         => "🤖 Gợi ý trả lời (bot, chưa gửi):\n" . $reply,
			'content_type'    => 'text',
			'message_type'    => 'private_note',
			'sender_type'     => 'bot',
			'sender_id'       => 0,
			'status'          => 'note',
			'responder_kind'  => 'hybrid',
			'character_id'    => (int) $claim['character_id'],
			'trace_id'        => $trace_id,
		) );
	}

	private static function human_delay( array $tuning ): void {
		if ( ! function_exists( 'wp_doing_cron' ) || ! wp_doing_cron() ) {
			return; // never sleep inside a REST/test request.
		}
		$min = max( 0, (int) $tuning['send_delay_min_ms'] );
		$max = max( $min, (int) $tuning['send_delay_max_ms'] );
		if ( $max <= 0 ) {
			return;
		}
		usleep( 1000 * wp_rand( $min, $max ) );
	}

	/* ── thread lock (B5.4) ──────────────────────────────────────────── */

	public static function is_locked( int $contact_id ): bool {
		return (bool) get_transient( self::lock_key( $contact_id ) );
	}

	private static function lock( int $contact_id, int $ttl ): void {
		set_transient( self::lock_key( $contact_id ), time(), max( 10, $ttl ) );
	}

	private static function unlock( int $contact_id ): void {
		delete_transient( self::lock_key( $contact_id ) );
	}

	private static function blog(): string {
		return (string) ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
	}

	private static function lock_key( int $contact_id ): string {
		return 'bzbot_lock_' . self::blog() . '_' . $contact_id;
	}

	private static function claim_key( int $contact_id ): string {
		return 'bzbot_debounce_' . self::blog() . '_' . $contact_id;
	}

	private static function peer_from_chat_id( string $chat_id, string $account_id ): string {
		$prefix = 'zalop_' . $account_id . '_';
		return strpos( $chat_id, $prefix ) === 0 ? substr( $chat_id, strlen( $prefix ) ) : $chat_id;
	}

	/* ── lifecycle evidence (PHASE-0.60K §15.2) ──────────────────────── */

	private static function new_trace_id(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'bot_', true );
	}

	/**
	 * A provider/exception message is free text and can quote a key or a token (`HTTP 401 … sk-abc…`). Events go to the
	 * bus AND the file log, so keep the diagnostic shape (status, verb) and redact anything credential-shaped, capped.
	 * Public for tests.
	 */
	public static function safe_error( string $message ): string {
		$message = (string) preg_replace( '/\b(?:sk|pk|rk|key|tok)[-_][A-Za-z0-9_-]{6,}/i', '[redacted]', $message );
		$message = (string) preg_replace( '/\b(token|api[_ -]?key|secret|password|bearer|authorization)\b\s*[:=]?\s*\S+/i', '$1 [redacted]', $message );
		$message = (string) preg_replace( '/[A-Za-z0-9_\-]{32,}/', '[redacted]', $message );
		return mb_substr( $message, 0, 160 );
	}

	/** A Zalo account id is a phone-like identifier: logs get a short stable hash, never the value (R-CH-IDMEM). */
	private static function account_ref( string $account_id ): string {
		return '' === $account_id ? '' : substr( md5( $account_id ), 0, 8 );
	}

	/**
	 * Emit ONE lifecycle event: a known stage, only allow-listed keys (ids, counters, buckets — never text, prompts,
	 * tokens or raw UIDs), and — for any failure — a `reason_bucket`, so no failure is ever an unlabeled silence.
	 * Public for tests.
	 */
	public static function lifecycle( string $stage, array $ctx ): void {
		if ( ! in_array( $stage, self::LIFECYCLE_STAGES, true ) ) {
			return; // a typo must not invent an event name the self-check cannot know about.
		}
		$payload = array_intersect_key( $ctx, array_flip( self::LIFECYCLE_KEYS ) );
		$payload = array_filter( $payload, static function ( $v ) { return null !== $v; } ); // null = not measured; never written as a fake 0
		if ( 'bot_turn_failed' === $stage && empty( $payload['reason_bucket'] ) ) {
			$payload['reason_bucket'] = 'unknown'; // never unlabeled.
		}
		if ( isset( $payload['reason_bucket'] ) && '' === $payload['reason_bucket'] ) {
			unset( $payload['reason_bucket'] );
		}
		if ( isset( $payload['message_id'] ) ) {
			$payload['crm_msg_id'] = (int) $payload['message_id']; // see LIFECYCLE_KEYS: `message_id` would be scrubbed.
			unset( $payload['message_id'] );
		}
		self::emit_event( $stage, $payload );
	}

	/**
	 * The one place Bot Studio calls the CRM outbound owner. Emits dispatch_started → dispatch_completed →
	 * zalo_delivery(accepted|failed) around it, so "the dispatcher has a row but the bridge said nothing" is visible.
	 * `accepted` means the bridge took the job — NOT that Zalo delivered it; that is the later callback
	 * (on_delivery_updated).
	 *
	 * @return array The dispatcher envelope, untouched.
	 * @throws \Throwable Re-thrown after a bot_turn_failed event — the caller's fallback logic still runs.
	 */
	private static function dispatch_with_evidence( array $request, string $kind, bool $fallback ): array {
		$trace_id        = (string) ( $request['trace_id'] ?? '' );
		$conversation_id = (int) ( $request['conversation_id'] ?? 0 );
		self::lifecycle( 'bot_turn_dispatch_started', array(
			'trace_id'        => $trace_id,
			'conversation_id' => $conversation_id,
			'kind'            => $kind,
			'system_source'   => (string) ( $request['system_source'] ?? '' ),
			'responder_kind'  => (string) ( $request['responder_kind'] ?? '' ),
			'fallback'        => $fallback,
			'pre_send_ms'     => isset( self::$timing['pre_send_ms'] ) ? (int) self::$timing['pre_send_ms'] : null,
		) );
		unset( self::$timing['pre_send_ms'] ); // only the first dispatch of a turn carries it
		$t_dispatch0 = microtime( true );
		try {
			$envelope = is_callable( self::$dispatcher )
				? (array) call_user_func( self::$dispatcher, $request )
				: (array) BizCity_CRM_Outbound_Dispatcher::dispatch( $request );
		} catch ( \Throwable $e ) {
			self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $trace_id, 'conversation_id' => $conversation_id, 'stage' => 'dispatch', 'kind' => $kind, 'code' => sanitize_key( get_class( $e ) ), 'reason_bucket' => 'dispatch_exception' ) );
			throw $e;
		}
		$outcome    = (string) ( $envelope['outcome'] ?? $envelope['status'] ?? 'failed' );
		$ok         = 'failed' !== $outcome;
		$message_id = (int) ( $envelope['message_id'] ?? 0 );
		$bucket     = $ok ? '' : ( (string) ( $envelope['reason_bucket'] ?? '' ) ?: 'dispatch_failed' );
		self::lifecycle( 'bot_turn_dispatch_completed', array(
			'trace_id'        => $trace_id,
			'conversation_id' => $conversation_id,
			'kind'            => $kind,
			'latency_ms'      => (int) round( ( microtime( true ) - $t_dispatch0 ) * 1000 ), // CRM row write + adapter + the bridge HTTP call
			'ok'              => $ok,
			'outcome'         => $outcome,
			'code'            => (string) ( $envelope['code'] ?? '' ),
			'message_id'      => $message_id,
			'retryable'       => ! empty( $envelope['retryable'] ),
			'replayed'        => ! empty( $envelope['replayed'] ),
			'delivery_mode'   => (string) ( $envelope['delivery_mode'] ?? '' ),
			'reason_bucket'   => $bucket,
		) );
		self::lifecycle( 'bot_turn_zalo_delivery', array(
			'trace_id'        => $trace_id,
			'conversation_id' => $conversation_id,
			'message_id'      => $message_id,
			'source'          => 'dispatch',
			'kind'            => $kind,
			// queued = the bridge took the job (Zalo Personal is asynchronous); a replay sent nothing new.
			'state'           => ! $ok ? 'failed' : ( ! empty( $envelope['replayed'] ) ? 'replayed' : ( 'queued' === $outcome ? 'accepted' : $outcome ) ),
			'code'            => (string) ( $envelope['code'] ?? '' ),
			'reason_bucket'   => $bucket,
		) );
		if ( $ok && $message_id > 0 ) {
			self::mark_bot_message( $message_id, $trace_id );
		}
		return $envelope;
	}

	/**
	 * Tag the outgoing CRM row as Bot Studio's the moment it exists. The full trace replaces this later
	 * (attach_trace); the marker only has to be there BEFORE the bridge's first delivery callback can arrive.
	 */
	private static function mark_bot_message( int $message_id, string $trace_id ): void {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'set_message_ai_metadata' ) ) {
			return;
		}
		try {
			BizCity_CRM_Repository::set_message_ai_metadata( $message_id, array( 'engine' => 'bot_studio', 'trace_uuid' => $trace_id ) );
		} catch ( \Throwable $e ) {
			self::lifecycle( 'bot_turn_failed', array( 'trace_id' => $trace_id, 'message_id' => $message_id, 'stage' => 'mark_message', 'code' => sanitize_key( get_class( $e ) ), 'reason_bucket' => 'mark_message_failed' ) );
		}
	}

	/**
	 * `bizcity_crm_message_delivery_updated` — the bridge's asynchronous verdict on a message. Only Bot Studio's own
	 * outgoing rows (`ai_metadata.engine = bot_studio`, written by mark_bot_message) become a `bot_turn_zalo_delivery`
	 * event; queued/accepted were already reported by dispatch_with_evidence(). Never throws into the CRM write path.
	 *
	 * @param mixed $payload { message_id, event_uuid, delivery:{outcome,platform,reason_code} }
	 */
	public static function on_delivery_updated( $payload ): void {
		if ( ! is_array( $payload ) ) {
			return;
		}
		$delivery = isset( $payload['delivery'] ) && is_array( $payload['delivery'] ) ? $payload['delivery'] : array();
		$state    = sanitize_key( (string) ( $delivery['outcome'] ?? '' ) );
		if ( ! in_array( $state, array( 'sent', 'delivered', 'failed' ), true ) ) {
			return;
		}
		$message_id = (int) ( $payload['message_id'] ?? 0 );
		if ( $message_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'get_message' ) ) {
			return;
		}
		try {
			$row = BizCity_CRM_Repository::get_message( $message_id );
		} catch ( \Throwable $e ) {
			self::lifecycle( 'bot_turn_failed', array( 'message_id' => $message_id, 'stage' => 'delivery_read', 'code' => sanitize_key( get_class( $e ) ), 'reason_bucket' => 'delivery_read_failed' ) );
			return;
		}
		if ( ! is_array( $row ) || 'outgoing' !== (string) ( $row['message_type'] ?? '' ) || 'auto' !== (string) ( $row['responder_kind'] ?? '' ) ) {
			return;
		}
		$meta = json_decode( (string) ( $row['ai_metadata_json'] ?? '' ), true );
		if ( ! is_array( $meta ) || 'bot_studio' !== (string) ( $meta['engine'] ?? '' ) ) {
			return; // another automation's message, not ours.
		}
		self::lifecycle( 'bot_turn_zalo_delivery', array(
			'trace_id'        => (string) ( $meta['trace_uuid'] ?? $row['trace_id'] ?? '' ),
			'conversation_id' => (int) ( $row['conversation_id'] ?? 0 ),
			'message_id'      => $message_id,
			'source'          => 'callback',
			'state'           => $state,
			'code'            => 'failed' === $state ? sanitize_key( (string) ( $delivery['reason_code'] ?? '' ) ) : '',
			'reason_bucket'   => 'failed' === $state ? ( sanitize_key( (string) ( $delivery['reason_code'] ?? '' ) ) ?: 'provider_error' ) : '',
		) );
	}

	private static function emit_event( string $type, array $payload ): void {
		if ( is_callable( self::$event_observer ) ) {
			call_user_func( self::$event_observer, $type, $payload );
		}
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( $type, $payload );
			} catch ( \Throwable $e ) {
				// never let the event bus break the turn.
			}
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) && defined( 'BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY' ) ) {
			try {
				// PHASE-0.60K §15.2 — the log event is named exactly like the stage (`bot_turn_claimed`); the old
				// unconditional 'bot_' prefix turned it into `bot_bot_turn_claimed`. Events without the prefix keep it.
				$log_event = ( 0 === strpos( $type, 'bot_' ) || in_array( $type, self::LIFECYCLE_STAGES, true ) ) ? $type : 'bot_' . $type;
				BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_INFO, $log_event, 'Bot Studio turn event.', array_diff_key( $payload, array( 'history' => 1 ) ) );
			} catch ( \Throwable $e ) {
				// logging must never break the turn.
			}
		}
	}
}
