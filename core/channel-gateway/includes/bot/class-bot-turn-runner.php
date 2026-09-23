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
	const ZALO_REPLY_MAX_CHARS = 1800;

	/** @var callable|null test seam: fn(object $character, array $messages, array $claim): array {success,message,error} */
	public static $llm = null;
	/** @var callable|null test seam: fn(array $claim, string $text, array $meta): array {ok,message_id,error} */
	public static $sender = null;
	/** @var callable|null test seam: fn(int $contact_id, int $delay): void */
	public static $scheduler = null;

	public static function init(): void {
		add_action( 'bizcity_crm_message_persisted', array( __CLASS__, 'on_persisted' ), 10, 1 );
		add_action( 'bizcity_crm_message_inserted', array( __CLASS__, 'on_message_inserted' ), 10, 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'on_run_turn_cron' ), 10, 1 );
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
		// Sanity cross-check: the claim (from the normalized envelope) and this persisted
		// event must agree on which contact this is, or we skip rather than misfire.
		if ( (int) ( $payload['contact_id'] ?? 0 ) !== (int) $claim['contact_id'] ) {
			return;
		}
		$claim['conversation_id'] = (int) ( $payload['conversation_id'] ?? 0 );
		$claim['message_id']      = (int) ( $payload['message_id'] ?? 0 );

		// [2026-09-23 03:50 PM Claude Fable 5.1] PHASE-0.60D S3.3 — the operator's workflow wins; the bot yields.
		if ( ! empty( $claim['workflow_matched'] ) ) {
			self::emit_event( 'bot_turn_yielded', array( 'conversation_id' => $claim['conversation_id'], 'workflow_id' => (int) ( $claim['workflow_id'] ?? 0 ), 'channel' => 'zalo_personal' ) );
			return;
		}
		self::schedule_debounced_turn( $claim );
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
		$conversation = BizCity_CRM_Repository::get_conversation( (int) ( $row['conversation_id'] ?? 0 ) );
		$contact_id   = is_array( $conversation ) ? (int) ( $conversation['contact_id'] ?? 0 ) : 0;
		if ( $contact_id <= 0 ) {
			return;
		}
		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		BizCity_Bot_Turn_Claim::set_paused( $contact_id, (int) $tuning['pause_window_minutes'] );
		// A human took over: drop any turn still waiting for this contact.
		delete_transient( self::claim_key( $contact_id ) );
		BizCity_Bot_Turn_Claim::clear_active( $contact_id );
	}

	/**
	 * Invariant 1 — "im lặng đủ": each new message resets the timer, so only the
	 * LAST message of a burst fires the cron event; the turn then re-reads the
	 * fresh window instead of replying per message.
	 */
	private static function schedule_debounced_turn( array $claim ): void {
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		if ( $conversation_id <= 0 || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
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
		self::schedule( $contact_id, $debounce );
	}

	private static function schedule( int $contact_id, int $delay ): void {
		if ( is_callable( self::$scheduler ) ) {
			call_user_func( self::$scheduler, $contact_id, $delay );
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $contact_id ) );
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $contact_id ) );
	}

	/**
	 * Cron fire: re-check "hội thoại rảnh" at fire time, not just claim time —
	 * a human may have jumped in during the debounce window (invariant 2).
	 */
	public static function on_run_turn_cron( $contact_id ): void {
		$contact_id = (int) $contact_id;
		$claim = get_transient( self::claim_key( $contact_id ) );
		if ( ! is_array( $claim ) || empty( $claim['conversation_id'] ) ) {
			delete_transient( self::claim_key( $contact_id ) );
			BizCity_Bot_Turn_Claim::clear_active( $contact_id );
			return;
		}
		if ( self::is_locked( $contact_id ) ) {
			// Invariant 2 — busy thread: PARK, do not queue. Bounded so a stuck lock cannot loop forever.
			$claim['parks'] = (int) ( $claim['parks'] ?? 0 ) + 1;
			if ( $claim['parks'] > self::MAX_PARKS ) {
				delete_transient( self::claim_key( $contact_id ) );
				BizCity_Bot_Turn_Claim::clear_active( $contact_id );
				self::emit_event( 'bot_turn_dropped', array( 'conversation_id' => (int) $claim['conversation_id'], 'reason' => 'parked_too_long' ) );
				return;
			}
			$tuning = BizCity_Bot_Config_Repo::get_tuning();
			set_transient( self::claim_key( $contact_id ), $claim, (int) $tuning['debounce_seconds'] + 120 );
			BizCity_Bot_Turn_Claim::mark_active( $contact_id, 'parked', array( 'conversation_id' => (int) $claim['conversation_id'], 'parks' => $claim['parks'] ) );
			self::schedule( $contact_id, max( 2, (int) $tuning['debounce_seconds'] ) );
			return;
		}
		delete_transient( self::claim_key( $contact_id ) );
		if ( ! self::may_still_send( $claim ) ) {
			BizCity_Bot_Turn_Claim::clear_active( $contact_id );
			self::emit_event( 'bot_turn_dropped', array( 'conversation_id' => (int) $claim['conversation_id'], 'reason' => 'conditions_changed' ) );
			return; // dropped, not sent — e.g. a human replied or office hours started mid-wait.
		}
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
		if ( $conversation_id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			$result['reason'] = 'module_not_loaded';
			return $result;
		}
		$character = BizCity_Knowledge_Database::instance()->get_character( (int) $claim['character_id'] );
		if ( ! $character ) {
			$result['reason'] = 'character_missing';
			return $result;
		}
		$tuning  = BizCity_Bot_Config_Repo::get_tuning();
		$timeout = (int) $tuning['turn_timeout_seconds'];

		// B4.6 — same hardening as AI_Replier: the customer already saw "delivered", finish the turn.
		if ( function_exists( 'ignore_user_abort' ) ) { ignore_user_abort( true ); }
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( $timeout + 15 ); }

		self::lock( $contact_id, $timeout + 5 );
		BizCity_Bot_Turn_Claim::mark_active( $contact_id, 'running', array( 'conversation_id' => $conversation_id ) );

		$trace_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'bot_', true );
		$started  = microtime( true );
		self::emit_event( 'guru_turn_started', array( 'trace_id' => $trace_id, 'character_id' => (int) $claim['character_id'], 'channel' => 'zalo_personal', 'engine' => 'bot', 'conversation_id' => $conversation_id ) );

		if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
			BizCity_Responder_Stamper::push( array( 'kind' => 'hybrid' === ( $claim['mode'] ?? '' ) ? 'hybrid' : 'auto', 'character_id' => (int) $claim['character_id'], 'source' => 'bot:' . (int) $claim['character_id'] ) );
		}

		try {
			$extra_system = array();
			$disclaimer   = '';

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
				? BizCity_Bot_Tool_Registry::effective( $character, (array) ( $claim['character_off'] ?? array() ), (array) ( $claim['binding_off'] ?? array() ) )
				: array();
			$tools_block = ! empty( $tools ) ? "=== CÔNG CỤ ĐÃ DÙNG ===\n(kết quả công cụ, nếu có, nằm trong các khối [DỮ LIỆU NGOÀI] bên dưới)" : '';
			$max_steps   = (int) $tuning['max_tool_steps'];
			$seen        = array();
			$context_opts = array(
				'history_limit'  => (int) $claim['history_limit'],
				'char_budget'    => (int) $tuning['history_char_budget'],
				'context_source' => (string) ( $claim['context_source'] ?? 'hybrid' ),
			);
			for ( $step = 0; $step < $max_steps && ! empty( $tools ) && class_exists( 'BizCity_Bot_Tools' ); $step++ ) {
				$probe = BizCity_Bot_Context_Builder::build( $character, $conversation_id, $contact_id, $context_opts + array( 'extra_system' => $extra_system ) );
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
				if ( ! empty( $run['ok'] ) && $run['content'] !== '' ) {
					$extra_system[] = (string) $run['content'];
					if ( ! empty( $run['disclaimer'] ) ) {
						$disclaimer = (string) $run['disclaimer'];
					}
					if ( ! empty( $run['ask'] ) ) {
						break; // the tool wants the model to ask the customer; no further tools this turn.
					}
				} else {
					$extra_system[] = '[Công cụ ' . $plan['tool'] . ' không dùng được: ' . (string) ( $run['error'] ?? 'unknown' ) . '. Trả lời không dựa vào nó và nói thật nếu cần.]';
				}
			}

			// ── context + model ──
			$built    = BizCity_Bot_Context_Builder::build( $character, $conversation_id, $contact_id, $context_opts + array( 'tools_block' => $tools_block, 'extra_system' => $extra_system ) );
			$messages = $built['messages'];
			$last     = end( $messages );
			if ( ! $last || 'user' !== ( $last['role'] ?? '' ) ) {
				$messages[] = array( 'role' => 'user', 'content' => (string) ( $claim['text'] !== '' ? $claim['text'] : '(tin nhắn không có chữ)' ) );
			}
			$llm   = self::call_llm( $character, $messages, $claim );
			$reply = ! empty( $llm['success'] ) ? trim( (string) ( $llm['message'] ?? '' ) ) : '';

			if ( '' === $reply ) {
				// B4.7 — inherited obligation: provider dead ≠ customer left in silence. Never leak the raw error.
				$sent = 'hybrid' === ( $claim['mode'] ?? '' ) ? array( 'ok' => true, 'message_id' => 0 ) : self::send( $claim, self::FALLBACK_TEXT, array( 'trace_id' => $trace_id, 'fallback' => true ) );
				self::emit_event( 'guru_turn_failed', array( 'trace_id' => $trace_id, 'reason' => 'provider_error', 'error' => (string) ( $llm['error'] ?? 'empty_reply' ), 'fallback_sent' => ! empty( $sent['ok'] ) ) );
				$result = array( 'status' => 'fallback', 'reply' => self::FALLBACK_TEXT, 'reason' => 'provider_error' );
				return $result;
			}

			$reply = class_exists( 'BizCity_Bot_Vertical_Tools' )
				? BizCity_Bot_Vertical_Tools::trim_for_zalo( $reply, self::ZALO_REPLY_MAX_CHARS, $disclaimer )
				: mb_substr( $reply, 0, self::ZALO_REPLY_MAX_CHARS );

			if ( 'hybrid' === ( $claim['mode'] ?? '' ) ) {
				// B-04 "Chỉ gợi ý cho nhân viên": draft as an internal note, never auto-sent.
				$note_id = self::store_draft( $claim, $reply, $trace_id );
				self::emit_event( 'guru_turn_completed', array( 'trace_id' => $trace_id, 'character_id' => (int) $claim['character_id'], 'channel' => 'zalo_personal', 'engine' => 'bot', 'mode' => 'hybrid', 'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'reply_len' => mb_strlen( $reply ), 'draft_message_id' => $note_id ) );
				$result = array( 'status' => 'draft', 'reply' => $reply, 'reason' => '' );
				return $result;
			}

			// B5.5 — a human-ish pause before sending (only when running under cron, never in a unit test seam).
			self::human_delay( $tuning );
			$sent = self::send( $claim, $reply, array( 'trace_id' => $trace_id ) );
			if ( empty( $sent['ok'] ) ) {
				self::emit_event( 'guru_turn_failed', array( 'trace_id' => $trace_id, 'reason' => 'send_failed', 'error' => (string) ( $sent['error'] ?? '' ) ) );
				$result = array( 'status' => 'send_failed', 'reply' => $reply, 'reason' => (string) ( $sent['error'] ?? 'send_failed' ) );
				return $result;
			}
			BizCity_Bot_Turn_Claim::increment_today_count( $contact_id );
			self::emit_event( 'guru_turn_completed', array( 'trace_id' => $trace_id, 'character_id' => (int) $claim['character_id'], 'channel' => 'zalo_personal', 'engine' => 'bot', 'mode' => 'auto', 'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'reply_len' => mb_strlen( $reply ), 'message_id' => (int) ( $sent['message_id'] ?? 0 ), 'history' => $built['meta'] ) );
			// [2026-09-23 03:50 PM Claude Fable 5.1] PHASE-0.60D Q-D2 — explicit "after bot replied" mark for automation (the dispatcher's outgoing row also fires bizcity_crm_message_inserted).
			do_action( 'bizcity_bot_turn_completed', array(
				'conversation_id' => $conversation_id,
				'contact_id'      => $contact_id,
				'character_id'    => (int) $claim['character_id'],
				'message_id'      => (int) ( $sent['message_id'] ?? 0 ),
				'account_id'      => (string) $claim['account_id'],
				'chat_id'         => (string) $claim['chat_id'],
				'trace_id'        => $trace_id,
			) );
			$result = array( 'status' => 'sent', 'reply' => $reply, 'reason' => '' );
			return $result;
		} catch ( \Throwable $e ) {
			$sent = 'hybrid' === ( $claim['mode'] ?? '' ) ? array( 'ok' => true ) : self::send( $claim, self::FALLBACK_TEXT, array( 'trace_id' => $trace_id, 'fallback' => true ) );
			self::emit_event( 'guru_turn_failed', array( 'trace_id' => $trace_id, 'reason' => 'exception', 'error' => $e->getMessage(), 'fallback_sent' => ! empty( $sent['ok'] ) ) );
			$result = array( 'status' => 'fallback', 'reply' => self::FALLBACK_TEXT, 'reason' => 'exception' );
			return $result;
		} finally {
			if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
				BizCity_Responder_Stamper::pop();
			}
			self::unlock( $contact_id );
			BizCity_Bot_Turn_Claim::clear_active( $contact_id );
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
	 */
	private static function send( array $claim, string $text, array $meta = array() ): array {
		if ( is_callable( self::$sender ) ) {
			return (array) call_user_func( self::$sender, $claim, $text, $meta );
		}
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		$attempt         = ! empty( $meta['fallback'] ) ? 'fb' : 'r';
		$idem            = 'bot-' . md5( $conversation_id . '|' . (string) ( $claim['external_message_id'] ?? '' ) . '|' . (string) ( $claim['message_id'] ?? '' ) . '|' . $attempt );
		if ( class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) ) {
			$envelope = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
				'conversation_id' => $conversation_id,
				'content'         => $text,
				'content_type'    => 'text',
				'idempotency_key' => $idem,
				'request_hash'    => md5( $text ),
				'actor'           => 'system',
				'system_source'   => 'ai_autoreply',
				'responder_kind'  => 'auto',
				'trace_id'        => (string) ( $meta['trace_id'] ?? '' ),
			) );
			$ok = is_array( $envelope ) && 'failed' !== (string) ( $envelope['outcome'] ?? $envelope['status'] ?? 'failed' );
			return array( 'ok' => $ok, 'message_id' => (int) ( $envelope['message_id'] ?? 0 ), 'error' => $ok ? '' : (string) ( $envelope['code'] ?? $envelope['error'] ?? 'dispatch_failed' ) );
		}
		// Last resort (dispatcher not loaded): still exactly one message, through the existing bridge boundary.
		if ( class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			$res = BizCity_Zalo_Bridge_Client::instance()->enqueue_outbound( (string) $claim['account_id'], self::peer_from_chat_id( (string) $claim['chat_id'], (string) $claim['account_id'] ), $text, 'text', array(), 'group' === ( $claim['chat_kind'] ?? '' ) ? 'group' : 'user', array(), $idem );
			return array( 'ok' => ! empty( $res['success'] ), 'message_id' => 0, 'error' => ! empty( $res['success'] ) ? '' : 'bridge_' . (string) ( $res['code'] ?? 'failed' ) );
		}
		return array( 'ok' => false, 'message_id' => 0, 'error' => 'no_sender' );
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

	private static function emit_event( string $type, array $payload ): void {
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( $type, $payload );
			} catch ( \Throwable $e ) {
				// never let the event bus break the turn.
			}
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) && defined( 'BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY' ) ) {
			try {
				BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_INFO, 'bot_' . $type, 'Bot Studio turn event.', array_diff_key( $payload, array( 'history' => 1 ) ) );
			} catch ( \Throwable $e ) {
				// logging must never break the turn.
			}
		}
	}
}
