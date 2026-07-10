<?php
/**
 * BizCity CRM — Campaign Scenario Dispatcher (PHASE 0.35 M6.W13 + W14).
 *
 * Two-stage pipeline so we can dispatch scenario outputs into a real
 * conversation:
 *
 *   STAGE 1 — Queue on visit
 *     crm_campaign_visit_recorded (W12) → cache {campaign_id, scenario_*}
 *     keyed by client_id in a transient (TTL = 10 min). At this point the
 *     conversation row may not exist yet (FB Ingestor inserts the inbound
 *     message at a later priority).
 *
 *   STAGE 2 — Drain on first message
 *     crm_message_received (priority 30, AFTER Linker@20 stamps contact_id)
 *     → pop cached envelope by client_id → dispatch action branch:
 *         - run_shortcode      : do_shortcode whitelist → insert outbound
 *         - send_message       : scenario_template (with optional KG inject)
 *         - kg_grounded_reply  : delegate to Action_Send_KG_Reply (M2.W4.4.3)
 *         - delay_only         : no immediate output, only schedule reminder
 *
 *   STAGE 3 — Reminder reap (W14)
 *     wp_schedule_single_event(`bizcity_crm_campaign_reminder_tick`) →
 *     check `last_inbound_at > visit_at` (skip if user replied) → render
 *     `reminder_text` → insert outbound → emit chained event with
 *     parent_event_uuid pointing back at the visit event.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W13, M6.W14)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Campaign_Scenario_Dispatcher {

	/** Transient key prefix for queued visit envelopes. */
	const QUEUE_PREFIX  = 'bizcrm_camp_pending_';
	/** Cache TTL — long enough that user "Get Started" → first reply gets matched. */
	const QUEUE_TTL_SEC = 600;
	/** Reminder cron tick action name. */
	const REMINDER_HOOK = 'bizcity_crm_campaign_reminder_tick';

	/** Whitelist of shortcodes the run_shortcode branch is allowed to execute. */
	const SHORTCODE_WHITELIST = array(
		'tim_san_pham',
		'tim_bai_viet',
		'tim_chuong_trinh_uu_dai',
		'kiem_tra_diem',
		'doi_diem',
		'tin_tuc_moi_nhat',
	);

	/* ============================================================
	 * Bootstrapping
	 * ============================================================ */

	public static function register(): void {
		// IMPORTANT: BizCity_CRM_Event_Emitter::emit() fans out via
		// `bizcity_crm_event_<type>` — use that prefix or the listener silently
		// never fires. Same root cause as the M6.W4 Conversion_Linker bug.
		add_action( 'bizcity_crm_event_crm_campaign_visit_recorded', array( __CLASS__, 'on_visit_recorded' ),  30, 1 );
		// P0-Q2 (2026-05-26): MUST run BEFORE AI Replier (@10) so envelope-hit
		// can claim the turn and veto the generic AI auto-reply (no double reply).
		add_action( 'bizcity_crm_event_crm_message_received',        array( __CLASS__, 'on_message_received' ),  5, 1 );
		add_action( self::REMINDER_HOOK,                              array( __CLASS__, 'on_reminder_tick' ),    10, 3 );
		// M6.W13.9 — release dispatcher idempotency lock after TTL.
		add_action( 'bizcrm_cdsp_release_lock', array( __CLASS__, 'on_release_lock' ), 10, 1 );

		// Register the explicit Action_Registry entry so admins/automation rules
		// can also invoke this dispatcher manually (R-CMP-1 — campaign IS scenario).
		add_filter( 'bizcity_crm_register_actions', array( __CLASS__, 'filter_register_action' ), 20, 1 );
	}

	/**
	 * @param array<string,array> $actions
	 */
	public static function filter_register_action( array $actions ): array {
		$actions['dispatch_campaign_scenario'] = array(
			'type'         => 'dispatch_campaign_scenario',
			'label'        => __( 'Dispatch campaign scenario', 'bizcity-twin-crm' ),
			'description'  => __( 'Resolve campaign by id → run its scenario_action_type branch (run_shortcode | send_message | kg_grounded_reply | delay_only).', 'bizcity-twin-crm' ),
			'param_schema' => array(
				'campaign_id'     => array( 'type' => 'integer', 'required' => true ),
				'conversation_id' => array( 'type' => 'integer', 'required' => false ),
			),
			'handler'      => array( __CLASS__, 'action_handler' ),
		);
		return $actions;
	}

	/* ============================================================
	 * Seeded default automation rule (M6.W13.5)
	 *
	 * Wires `crm_campaign_visit_recorded` → `dispatch_campaign_scenario`
	 * out-of-the-box so every published campaign auto-dispatches without
	 * requiring an admin to hand-create the rule.
	 *
	 * Idempotent: identified by the exact sentinel name + an option flag
	 * fast-path. Safe to call repeatedly (migration + manual re-run).
	 * ============================================================ */

	const DEFAULT_RULE_NAME    = '[default] Dispatch campaign scenario';
	const DEFAULT_RULE_OPTION  = 'bizcity_crm_default_rule_campaign_dispatch_id';

	/**
	 * Insert the default rule once. Returns the rule id (existing or newly created),
	 * or 0 if the Repository / table is unavailable.
	 */
	public static function seed_default_rule(): int {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) { return 0; }

		// Fast-path — option remembers the seeded rule id.
		$cached_id = (int) get_option( self::DEFAULT_RULE_OPTION, 0 );
		if ( $cached_id > 0 ) {
			$row = BizCity_CRM_Repository::get_automation_rule( $cached_id );
			if ( $row && (string) ( $row['name'] ?? '' ) === self::DEFAULT_RULE_NAME ) {
				return $cached_id;
			}
			// Stale option (rule was deleted) — clear and fall through to recreate.
			delete_option( self::DEFAULT_RULE_OPTION );
		}

		// Slow-path — search by name in case the option was lost but rule exists.
		$existing = BizCity_CRM_Repository::list_automation_rules( array(
			'event_name' => 'crm_campaign_visit_recorded',
			'limit'      => 50,
		) );
		foreach ( $existing as $row ) {
			if ( (string) ( $row['name'] ?? '' ) === self::DEFAULT_RULE_NAME ) {
				$id = (int) $row['id'];
				update_option( self::DEFAULT_RULE_OPTION, $id, false );
				return $id;
			}
		}

		// Insert. Empty conditions → matches every visit. Action delegates to
		// this dispatcher's registered Action_Registry handler.
		$new_id = BizCity_CRM_Repository::upsert_automation_rule( array(
			'name'        => self::DEFAULT_RULE_NAME,
			'description' => 'Auto-seeded by M6.W13.5 — fires dispatch_campaign_scenario on every campaign visit. Safe to disable; the visit-time hook in this class still works without it.',
			'event_name'  => 'crm_campaign_visit_recorded',
			'inbox_id'    => null,
			'conditions'  => array(
				'__seeded'         => 'campaign_scenario_v1',
				'__seeded_version' => 1,
			),
			'actions'     => array(
				array(
					'type'   => 'dispatch_campaign_scenario',
					'params' => array(
						'campaign_id' => '{{payload.campaign_id}}',
					),
				),
			),
			'active'      => 1,
		) );
		if ( $new_id > 0 ) {
			update_option( self::DEFAULT_RULE_OPTION, $new_id, false );
		}
		return $new_id;
	}

	/* ============================================================
	 * STAGE 1 — Queue on visit
	 * ============================================================ */

	public static function on_visit_recorded( $payload ): void {
		$dbg = class_exists( 'BizCity_CG_Debug_Logger' );
		if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'on_visit_recorded_enter', is_array( $payload ) ? $payload : array( 'payload' => $payload ) ); }
		if ( ! is_array( $payload ) ) { return; }
		$client_id   = (string) ( $payload['client_id']   ?? '' );
		$campaign_id = (int)    ( $payload['campaign_id'] ?? 0 );
		if ( $client_id === '' || $campaign_id <= 0 ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'on_visit_recorded_skip_empty', array( 'client_id' => $client_id, 'campaign_id' => $campaign_id ), 'warn' ); }
			return;
		}

		// Persist enough context for STAGE 2 to fire without re-reading campaign row.
		$envelope = array(
			'campaign_id'          => $campaign_id,
			'visit_id'             => (int) ( $payload['visit_id'] ?? 0 ),
			'scenario_action_type' => (string) ( $payload['scenario_action_type'] ?? 'send_message' ),
			'parent_event_uuid'    => $payload['parent_event_uuid'] ?? null,
			'queued_at'            => time(),
		);
		$key = self::QUEUE_PREFIX . md5( $client_id );
		$ok  = set_transient( $key, $envelope, self::QUEUE_TTL_SEC );
		if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'envelope_cached', array( 'transient_key' => $key, 'client_id' => $client_id, 'envelope' => $envelope, 'set_ok' => $ok, 'ttl_sec' => self::QUEUE_TTL_SEC ) ); }

		// M6.W13.7 — Immediate dispatch on m.me click when an existing conversation
		// can be resolved from (inbox_id + PSID). This delivers the scenario as the
		// first message the user sees in Messenger right after clicking the link,
		// instead of waiting for them to type something.
		$conv_id = self::try_resolve_conv_for_visit( $payload );
		if ( $conv_id > 0 ) {
			// M6.W13.9 — Idempotency lock. FB Conversation API frequently fires the
			// same referral webhook 2-3 times within 1s (different worker pids). Without
			// a cross-process lock, each hit produces its own immediate_dispatch + FB
			// send call → user sees "ch\u00e0o s\u1ebfp" 3x in Messenger. Lock keyed by
			// (campaign_id + client_id) for 60s. Use add_option with autoload=no as a
			// DB-atomic lock (transients/object-cache are not safe across php-fpm pids
			// when memcached is absent).
			$lock_key = 'bizcrm_cdsp_' . md5( $campaign_id . '|' . $client_id );
			$lock_ok  = add_option( $lock_key, time(), '', 'no' );
			if ( ! $lock_ok ) {
				if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'immediate_dispatch_skip_locked', array( 'lock_key' => $lock_key, 'campaign_id' => $campaign_id, 'client_id' => $client_id ) ); }
				return;
			}
			// Schedule lock cleanup so the same campaign can re-fire after 60s for the
			// same user (allows re-test from same Messenger thread).
			if ( ! wp_next_scheduled( 'bizcrm_cdsp_release_lock', array( $lock_key ) ) ) {
				wp_schedule_single_event( time() + 60, 'bizcrm_cdsp_release_lock', array( $lock_key ) );
			}
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'immediate_dispatch_attempt', array( 'conversation_id' => $conv_id, 'client_id' => $client_id, 'campaign_id' => $campaign_id, 'lock_key' => $lock_key ) ); }
			// One-shot: drop cache so STAGE 2 doesn't double-fire when the user later types.
			delete_transient( $key );
			$result = self::dispatch( array(
				'campaign_id'       => $campaign_id,
				'conversation_id'   => $conv_id,
				'contact_id'        => (int) ( $payload['contact_id'] ?? 0 ),
				'inbox_id'          => (int) ( $payload['channel_inbox_id'] ?? 0 ),
				'visit_id'          => (int) ( $payload['visit_id'] ?? 0 ),
				'parent_event_uuid' => $payload['parent_event_uuid'] ?? null,
				// M6.W13.10 — carry raw client_id so render_template can extract
				// FB PSID for {{client_id}} placeholder (used in loyalty URLs etc.)
				'client_id'         => $client_id,
			) );
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'immediate_dispatch_result', $result, ! empty( $result['ok'] ) ? 'info' : 'warn' ); }
		} elseif ( $dbg ) {
			BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'immediate_dispatch_skip_no_conv', array( 'client_id' => $client_id, 'hint' => 'no existing conversation \u2014 will wait for first inbound (STAGE 2)' ) );
		}
	}

	/**
	 * M6.W13.9 — release the cross-pid dispatcher lock so the same
	 * (campaign + client) can dispatch again on a future visit.
	 */
	public static function on_release_lock( string $lock_key ): void {
		if ( $lock_key === '' ) { return; }
		delete_option( $lock_key );
	}

	/**
	 * Resolve an existing conversation_id from a visit payload (fb_messenger mode only).
	 * Returns 0 when conv doesn't exist yet (e.g. brand-new user clicking m.me for the
	 * first time \u2014 FB hasn't delivered any message yet so contact_inbox + conv rows
	 * haven't been created by the FB Ingestor).
	 */
	private static function try_resolve_conv_for_visit( array $payload ): int {
		$client_id = (string) ( $payload['client_id'] ?? '' );
		$inbox_id  = (int)    ( $payload['channel_inbox_id'] ?? 0 );
		if ( $inbox_id <= 0 ) { return 0; }

		// Parse PSID out of "fb_<page>_<psid>".
		if ( strpos( $client_id, 'fb_' ) !== 0 ) { return 0; }
		$rest = substr( $client_id, 3 );
		$pos  = strpos( $rest, '_' );
		if ( $pos === false ) { return 0; }
		$psid = substr( $rest, $pos + 1 );
		if ( $psid === '' ) { return 0; }

		global $wpdb;
		$ci_tbl   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT c.id FROM {$conv_tbl} c
			   JOIN {$ci_tbl} ci ON ci.id = c.contact_inbox_id
			  WHERE c.inbox_id   = %d
			    AND ci.source_id = %s
			  ORDER BY c.id DESC LIMIT 1",
			$inbox_id, $psid
		) );
	}

	/* ============================================================
	 * STAGE 2 — Drain on first message + dispatch
	 * ============================================================ */

	public static function on_message_received( $payload ): void {
		$dbg = class_exists( 'BizCity_CG_Debug_Logger' );
		if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'on_message_received_enter', is_array( $payload ) ? $payload : array( 'payload' => $payload ) ); }
		if ( ! is_array( $payload ) ) { return; }
		if ( ( $payload['sender_type'] ?? '' ) !== 'contact' ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'on_message_received_skip_not_contact', array( 'sender_type' => $payload['sender_type'] ?? null ) ); }
			return;
		}

		$conv_id = (int) ( $payload['conversation_id'] ?? 0 );
		if ( $conv_id <= 0 ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'on_message_received_skip_no_conv', $payload, 'warn' ); }
			return;
		}

		// Resolve the client_id this message belongs to (mirror Linker logic).
		if ( ! class_exists( 'BizCity_CRM_Campaign_Conversion_Linker' ) ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'on_message_received_skip_no_linker_class', array(), 'warn' ); }
			return;
		}
		$resolved = BizCity_CRM_Campaign_Conversion_Linker::resolve_client_id_for_conversation( $conv_id );
		if ( ! $resolved ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'on_message_received_resolve_null', array( 'conversation_id' => $conv_id ), 'warn' ); }
			// [2026-06-09 Johnny Chu] R-CG-FB-WEBHOOK — error_log when Linker can't resolve
			// client_id (usually means no bizcity_crm_inboxes row for this FB page).
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[bizcity-crm] scenario_dispatcher on_message_received: resolve_client_id_for_conversation returned null for conv_id=' . $conv_id . '. Likely cause: no inbox row in bizcity_crm_inboxes for this Facebook page. Scenario dispatch SKIPPED.' );
			}
			return;
		}

		$client_id  = (string) $resolved['client_id'];
		$contact_id = (int)    $resolved['contact_id'];
		$key        = self::QUEUE_PREFIX . md5( $client_id );
		$envelope   = get_transient( $key );
		if ( ! is_array( $envelope ) ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'envelope_miss', array( 'transient_key' => $key, 'client_id' => $client_id, 'conversation_id' => $conv_id, 'hint' => 'no STAGE-1 cached envelope for this client_id — either visit not recorded, TTL expired, or client_id mismatch between Tracker and Linker' ), 'warn' ); }
			// [2026-06-09 Johnny Chu] R-CG-FB-WEBHOOK — error_log transient miss so
			// we can distinguish "referral never processed" from "transient expired".
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[bizcity-crm] scenario_dispatcher envelope_miss: no Stage-1 transient for client_id=' . $client_id . ' key=' . $key . '. Possible causes: (1) bizcity_facebook_referral_received never fired (check messaging_referrals webhook subscription), (2) TTL expired (>10min between referral click and first message), (3) client_id mismatch.' );
			}
			// STAGE 2.5 — KEYWORD-MATCH FALLBACK (2026-05-27)
			// When there's no referral-cached envelope, try matching the inbound
			// message text against active campaign `name` (trigger keyword) or
			// `code` (slug). If a campaign matches, dispatch it immediately so
			// users can trigger scenarios by typing the trigger keyword too.
			self::try_dispatch_by_keyword( $payload, $conv_id, $client_id, $contact_id );
			return;
		}
		if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'envelope_hit', array( 'transient_key' => $key, 'client_id' => $client_id, 'conversation_id' => $conv_id, 'envelope' => $envelope ) ); }
		// One-shot dispatch — drop cache before processing to avoid re-entry.
		delete_transient( $key );

		// P0-Q2 (2026-05-26) — claim the turn so AI_Autoreply_Listener (@10)
		// short-circuits and DOESN'T send a second generic reply alongside the
		// scenario template/shortcode this dispatcher is about to emit.
		// Filter `bizcity_crm_scenario_claim_ai_turn` lets ops opt-out (e.g. allow
		// scenario + AI reply to co-exist for a specific campaign type).
		$claim = (bool) apply_filters(
			'bizcity_crm_scenario_claim_ai_turn',
			true,
			$envelope,
			$conv_id,
			$client_id
		);
		if ( $claim ) {
			set_transient( 'bz_crm_scenario_claim_' . $conv_id, array(
				'campaign_id' => (int) $envelope['campaign_id'],
				'client_id'   => $client_id,
				'at'          => time(),
			), 90 );
		}

		$result = self::dispatch( array(
			'campaign_id'       => (int) $envelope['campaign_id'],
			'conversation_id'   => $conv_id,
			'contact_id'        => $contact_id,
			'inbox_id'          => (int) ( $payload['inbox_id'] ?? 0 ),
			'visit_id'          => (int) ( $envelope['visit_id'] ?? 0 ),
			'parent_event_uuid' => $envelope['parent_event_uuid'] ?? null,
			'client_id'         => $client_id,
		) );
		if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'dispatch_result', $result, ! empty( $result['ok'] ) ? 'info' : 'warn' ); }
	}

	/**
	 * STAGE 2.5 — Match inbound message text against active campaign trigger
	 * keywords (`name` exact match, case-insensitive trim) or campaign slug
	 * (`code` exact match). Dispatches the first match found.
	 *
	 * Called only when STAGE-1 envelope is missing (i.e. user typed the
	 * keyword directly instead of clicking a m.me referral link).
	 */
	private static function try_dispatch_by_keyword( array $payload, int $conv_id, string $client_id, int $contact_id ): void {
		$dbg = class_exists( 'BizCity_CG_Debug_Logger' );
		$msg_id = (int) ( $payload['message_id'] ?? 0 );
		if ( $msg_id <= 0 ) { return; }
		if ( ( $payload['content_type'] ?? 'text' ) !== 'text' ) { return; }
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) { return; }

		$msg = BizCity_CRM_Repository::get_message( $msg_id );
		$content = is_array( $msg ) ? (string) ( $msg['content'] ?? '' ) : '';
		// Normalise: trim + lowercase (mb-safe), strip surrounding quotes.
		$needle = trim( $content );
		if ( $needle === '' || mb_strlen( $needle ) > 120 ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'keyword_skip', array( 'reason' => 'empty_or_too_long', 'len' => mb_strlen( $needle ) ) ); }
			return;
		}
		$needle_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $needle, 'UTF-8' ) : strtolower( $needle );

		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		// Match `name` (display keyword) OR `code` (slug). Take the most
		// recently updated active row to favour the latest scenario edit.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl}
			   WHERE deleted_at IS NULL
			     AND status = 'active'
			     AND ( LOWER(TRIM(name)) = %s OR LOWER(TRIM(code)) = %s )
			   ORDER BY updated_at DESC, id DESC
			   LIMIT 1",
			$needle_lc,
			$needle_lc
		), ARRAY_A );

		if ( ! $row ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'keyword_no_match', array( 'needle' => $needle_lc, 'conversation_id' => $conv_id ) ); }
			return;
		}

		$campaign_id = (int) $row['id'];
		$campaign    = class_exists( 'BizCity_CRM_Campaign_Repository' )
			? BizCity_CRM_Campaign_Repository::get( $campaign_id )
			: $row;

		if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'keyword_match', array(
			'campaign_id' => $campaign_id,
			'name'        => (string) ( $row['name'] ?? '' ),
			'code'        => (string) ( $row['code'] ?? '' ),
			'needle'      => $needle_lc,
			'conversation_id' => $conv_id,
		) ); }

		// Dedupe: avoid re-firing same keyword campaign for same client within
		// a short window (mirrors Tracker's 60s dedupe so users hitting the
		// keyword twice in a row don't get spammed).
		$dedupe_key = 'bz_crm_kw_dedupe_' . md5( $campaign_id . '|' . $client_id );
		if ( get_transient( $dedupe_key ) ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'keyword_dedupe_within_60s', array( 'campaign_id' => $campaign_id, 'client_id' => $client_id ) ); }
			return;
		}
		set_transient( $dedupe_key, 1, 60 );

		// Claim AI turn so AI_Autoreply_Listener@10 skips this message.
		$claim = (bool) apply_filters(
			'bizcity_crm_scenario_claim_ai_turn',
			true,
			array( 'campaign_id' => $campaign_id, 'source' => 'keyword' ),
			$conv_id,
			$client_id
		);
		if ( $claim ) {
			set_transient( 'bz_crm_scenario_claim_' . $conv_id, array(
				'campaign_id' => $campaign_id,
				'client_id'   => $client_id,
				'at'          => time(),
				'source'      => 'keyword',
			), 90 );
		}

		// Dispatch directly. We do NOT route through record_visit() because
		// (1) we already know the conversation_id (no need to re-resolve via PSID)
		// and (2) record_visit + on_visit_recorded would also set its own lock
		// + dispatch, producing a duplicate send. Visit logging for keyword
		// triggers is intentionally skipped in v1 — add later if dashboards
		// require it (would need a "no-auto-dispatch" flag on record_visit).
		$result = self::dispatch( array(
			'campaign_id'       => $campaign_id,
			'conversation_id'   => $conv_id,
			'contact_id'        => $contact_id,
			'inbox_id'          => (int) ( $payload['inbox_id'] ?? 0 ),
			'visit_id'          => 0,
			'parent_event_uuid' => $payload['event_uuid'] ?? null,
			'client_id'         => $client_id,
		) );
		if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'keyword_dispatch_result', array_merge( array( 'campaign_id' => $campaign_id ), is_array( $result ) ? $result : array() ), ! empty( $result['ok'] ) ? 'info' : 'warn' ); }
	}

	/**
	 * Action_Registry handler entry — params: { campaign_id, conversation_id }.
	 * $context: { event_name, conversation_id?, message_id?, contact_id?, dry_run }.
	 *
	 * @return array { ok: bool, detail: string, data?: array }
	 */
	public static function action_handler( array $params, array $context ): array {
		$campaign_id = (int) ( $params['campaign_id'] ?? 0 );
		$conv_id     = (int) ( $params['conversation_id'] ?? ( $context['conversation_id'] ?? 0 ) );
		if ( $campaign_id <= 0 ) {
			return array( 'ok' => false, 'detail' => 'campaign_id required' );
		}
		if ( $conv_id <= 0 ) {
			return array( 'ok' => false, 'detail' => 'conversation_id required' );
		}
		if ( ! empty( $context['dry_run'] ) ) {
			return array( 'ok' => true, 'detail' => 'dry_run — would dispatch', 'data' => array( 'campaign_id' => $campaign_id, 'conversation_id' => $conv_id ) );
		}

		$out = self::dispatch( array(
			'campaign_id'       => $campaign_id,
			'conversation_id'   => $conv_id,
			'contact_id'        => (int) ( $context['contact_id'] ?? 0 ),
			'inbox_id'          => (int) ( $context['inbox_id'] ?? 0 ),
			'visit_id'          => 0,
			'parent_event_uuid' => $context['event_uuid'] ?? null,
		) );

		return array(
			'ok'     => ! empty( $out['ok'] ),
			'detail' => (string) ( $out['detail'] ?? '' ),
			'data'   => $out,
		);
	}

	/* ============================================================
	 * Core dispatcher — branches per scenario_action_type
	 * ============================================================ */

	/**
	 * @param array $ctx { campaign_id, conversation_id, contact_id, inbox_id, visit_id, parent_event_uuid }
	 * @return array { ok: bool, branch: string, detail: string, message_id?: int, reminder_scheduled?: bool }
	 */
	public static function dispatch( array $ctx ): array {
		if ( ! class_exists( 'BizCity_CRM_Campaign_Repository' ) ) {
			return array( 'ok' => false, 'branch' => 'unknown', 'detail' => 'repository missing' );
		}
		$campaign = BizCity_CRM_Campaign_Repository::get( (int) $ctx['campaign_id'] );
		if ( ! $campaign ) {
			return array( 'ok' => false, 'branch' => 'unknown', 'detail' => 'campaign not found' );
		}

		$action       = (string) ( $campaign['scenario_action_type'] ?? 'send_message' );
		$reminder_only= ! empty( $campaign['reminder_only'] );
		$result       = array( 'ok' => false, 'branch' => $action, 'detail' => '' );

		if ( ! $reminder_only ) {
			switch ( $action ) {
				case 'run_shortcode':
					$result = self::branch_run_shortcode( $campaign, $ctx );
					break;
				case 'kg_grounded_reply':
					$result = self::branch_kg_grounded_reply( $campaign, $ctx );
					break;
				case 'delay_only':
					$result = array( 'ok' => true, 'branch' => 'delay_only', 'detail' => 'no immediate dispatch (delay_only)' );
					break;
				case 'send_message':
				default:
					$result = self::branch_send_message( $campaign, $ctx );
					break;
			}
		} else {
			$result = array( 'ok' => true, 'branch' => $action, 'detail' => 'reminder_only=1 — immediate dispatch skipped' );
		}

		// Always emit a dispatch event so downstream automation/audit can chain.
		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_campaign_scenario_dispatched', array(
				'campaign_id'       => (int) $campaign['id'],
				'conversation_id'   => (int) $ctx['conversation_id'],
				'contact_id'        => (int) $ctx['contact_id'],
				'visit_id'          => (int) $ctx['visit_id'],
				'action'            => $action,
				'ok'                => ! empty( $result['ok'] ),
				'detail'            => (string) ( $result['detail'] ?? '' ),
				'parent_event_uuid' => $ctx['parent_event_uuid'] ?? null,
			) );
		}

		// Schedule reminder if configured.
		$delay_sec = self::reminder_delay_sec( $campaign );
		if ( $delay_sec > 0 ) {
			$scheduled = wp_schedule_single_event(
				time() + $delay_sec,
				self::REMINDER_HOOK,
				array(
					(int) $ctx['conversation_id'],
					(int) $campaign['id'],
					(string) ( $ctx['parent_event_uuid'] ?? '' ),
				)
			);
			$result['reminder_scheduled'] = ( $scheduled !== false );
			$result['reminder_delay_sec'] = $delay_sec;
		}

		return $result;
	}

	/* ============================================================
	 * Branch implementations
	 * ============================================================ */

	private static function branch_run_shortcode( array $campaign, array $ctx ): array {
		$sc = trim( (string) ( $campaign['scenario_shortcode'] ?? '' ) );
		if ( $sc === '' ) {
			return array( 'ok' => false, 'branch' => 'run_shortcode', 'detail' => 'scenario_shortcode empty' );
		}
		// Whitelist guard — extract the shortcode tag and check.
		if ( ! self::shortcode_is_whitelisted( $sc ) ) {
			return array( 'ok' => false, 'branch' => 'run_shortcode', 'detail' => 'shortcode tag not whitelisted' );
		}
		$rendered = trim( (string) do_shortcode( $sc ) );
		if ( $rendered === '' || $rendered === $sc ) {
			return array( 'ok' => false, 'branch' => 'run_shortcode', 'detail' => 'shortcode produced no output' );
		}
		// Some bizgpt shortcodes return a JSON envelope { success, msgs:[...] }.
		// Normalize that into a plain text body for the outbound message.
		$dec = json_decode( $rendered, true );
		if ( is_array( $dec ) && isset( $dec['msgs'] ) && is_array( $dec['msgs'] ) ) {
			$rendered = implode( "\n\n", array_map( 'strval', $dec['msgs'] ) );
		}
		$msg_id = self::insert_outbound( $ctx, $rendered, 'cmp_short' );
		return array( 'ok' => $msg_id > 0, 'branch' => 'run_shortcode', 'detail' => 'msg_id=' . $msg_id, 'message_id' => $msg_id );
	}

	private static function branch_send_message( array $campaign, array $ctx ): array {
		$dbg = class_exists( 'BizCity_CG_Debug_Logger' );
		$tpl = trim( (string) ( $campaign['scenario_template'] ?? '' ) );
		$tpl_src = 'scenario_template';
		if ( $tpl === '' ) {
			// Fall back to scenario_prompt as flat text (defensive for legacy imports).
			$tpl = trim( (string) ( $campaign['scenario_prompt'] ?? '' ) );
			$tpl_src = 'scenario_prompt';
		}
		if ( $dbg ) {
			BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'branch_send_message_template', array(
				'campaign_id'        => (int) ( $campaign['id'] ?? 0 ),
				'tpl_src'            => $tpl_src,
				'tpl_len'            => strlen( $tpl ),
				'tpl_preview'        => mb_substr( $tpl, 0, 200 ),
				'has_scenario_template' => isset( $campaign['scenario_template'] ),
				'has_scenario_prompt'   => isset( $campaign['scenario_prompt'] ),
				'campaign_keys'      => array_keys( $campaign ),
			) );
		}
		if ( $tpl === '' ) {
			return array( 'ok' => false, 'branch' => 'send_message', 'detail' => 'no template/prompt' );
		}
		$rendered = self::render_template( $tpl, $campaign, $ctx );
		if ( $dbg ) {
			BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'branch_send_message_rendered', array(
				'rendered_len'     => strlen( $rendered ),
				'rendered_preview' => mb_substr( $rendered, 0, 300 ),
				'ctx_conv_id'      => (int) ( $ctx['conversation_id'] ?? 0 ),
				'ctx_inbox_id'     => (int) ( $ctx['inbox_id'] ?? 0 ),
			) );
		}
		$msg_id   = self::insert_outbound( $ctx, $rendered, 'cmp_send' );
		return array( 'ok' => $msg_id > 0, 'branch' => 'send_message', 'detail' => 'msg_id=' . $msg_id, 'message_id' => $msg_id );
	}

	private static function branch_kg_grounded_reply( array $campaign, array $ctx ): array {
		// Delegate to the Action_Send_KG_Reply (M2.W4.4.3) when present.
		if ( ! class_exists( 'BizCity_CRM_Action_Send_KG_Reply' ) || ! method_exists( 'BizCity_CRM_Action_Send_KG_Reply', 'handle' ) ) {
			// Soft fallback — render template + emit notice that KG reply is not wired.
			// Production warn log so this silent degradation surfaces in WP_DEBUG_LOG.
			if ( function_exists( 'error_log' ) ) {
				error_log( sprintf(
					'[bizcity-crm M6.W13.3] kg_grounded_reply branch falling back to send_message: BizCity_CRM_Action_Send_KG_Reply not available · campaign_id=%d · conversation_id=%d',
					(int) ( $campaign['id'] ?? 0 ),
					(int) ( $ctx['conversation_id'] ?? 0 )
				) );
			}
			$out             = self::branch_send_message( $campaign, $ctx );
			$out['fallback'] = 'send_message';
			$out['detail']   = trim( ( $out['detail'] ?? '' ) . ' [kg_action_unavailable]' );
			return $out;
		}
		$params = array(
			'notebook_id'   => (int) ( $campaign['bound_notebook_id'] ?? 0 ),
			'character_id'  => (int) ( $campaign['bound_character_id'] ?? 0 ),
			'prompt'        => (string) ( $campaign['scenario_prompt'] ?? ( $campaign['scenario_template'] ?? '' ) ),
		);
		$context = array(
			'event_name'      => 'crm_campaign_visit_recorded',
			'conversation_id' => (int) $ctx['conversation_id'],
			'contact_id'      => (int) $ctx['contact_id'],
			'inbox_id'        => (int) $ctx['inbox_id'],
			'event_uuid'      => $ctx['parent_event_uuid'] ?? null,
			'dry_run'         => false,
		);
		$out = call_user_func( array( 'BizCity_CRM_Action_Send_KG_Reply', 'handle' ), $params, $context );
		return array(
			'ok'     => ! empty( $out['ok'] ),
			'branch' => 'kg_grounded_reply',
			'detail' => (string) ( $out['detail'] ?? '' ),
			'data'   => $out,
		);
	}

	/* ============================================================
	 * STAGE 3 — Reminder reaper (W14)
	 * ============================================================ */

	public static function on_reminder_tick( $conv_id, $campaign_id, $parent_event_uuid = '' ): void {
		$conv_id     = (int) $conv_id;
		$campaign_id = (int) $campaign_id;
		if ( $conv_id <= 0 || $campaign_id <= 0 ) { return; }
		if ( ! class_exists( 'BizCity_CRM_Campaign_Repository' ) ) { return; }
		$campaign = BizCity_CRM_Campaign_Repository::get( $campaign_id );
		if ( ! $campaign ) { return; }

		$reminder = trim( (string) ( $campaign['reminder_text'] ?? '' ) );
		if ( $reminder === '' ) { return; }

		// Skip-if-replied check — last_inbound_at must NOT be after the visit was queued.
		// We compare against last_outbound_at (our own dispatch) as the conservative anchor:
		// any inbound after our outbound = user is engaged → no reminder needed.
		if ( self::user_replied_since_dispatch( $conv_id ) ) { return; }

		// Resolve inbox_id from conversation.
		global $wpdb;
		$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT inbox_id, contact_inbox_id FROM {$conv_tbl} WHERE id = %d LIMIT 1",
			$conv_id
		), ARRAY_A );
		if ( ! $row ) { return; }

		$rendered = self::render_template( $reminder, $campaign, array( 'conversation_id' => $conv_id ) );
		$msg_id   = self::insert_outbound(
			array(
				'conversation_id' => $conv_id,
				'inbox_id'        => (int) $row['inbox_id'],
			),
			$rendered,
			'cmp_remind'
		);

		if ( $msg_id > 0 && class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_campaign_reminder_sent', array(
				'campaign_id'       => $campaign_id,
				'conversation_id'   => $conv_id,
				'message_id'        => $msg_id,
				'parent_event_uuid' => $parent_event_uuid !== '' ? (string) $parent_event_uuid : null,
			) );
		}
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	private static function reminder_delay_sec( array $campaign ): int {
		$d = (int) ( $campaign['reminder_delay'] ?? 0 );
		if ( $d <= 0 ) { return 0; }
		$unit = (string) ( $campaign['reminder_unit'] ?? 'minutes' );
		switch ( $unit ) {
			case 'hours':   return $d * HOUR_IN_SECONDS;
			case 'days':    return $d * DAY_IN_SECONDS;
			case 'minutes':
			default:        return $d * MINUTE_IN_SECONDS;
		}
	}

	private static function shortcode_is_whitelisted( string $sc ): bool {
		if ( ! preg_match( '/\[\s*([a-z0-9_]+)\b/i', $sc, $m ) ) { return false; }
		$tag = strtolower( $m[1] );
		return in_array( $tag, self::SHORTCODE_WHITELIST, true );
	}

	/**
	 * Lightweight template renderer — supports {{client_id}} {{contact_id}}
	 * {{client_name}} {{campaign_name}} {{campaign_code}} placeholders.
	 * {{client_id}} is an alias for {{contact_id}} (same value — FB PSID or CRM id).
	 */
	private static function render_template( string $tpl, array $campaign, array $ctx ): string {
		$contact_id_str = (string) ( (int) ( $ctx['contact_id'] ?? 0 ) );
		// {{client_id}} priority order:
		//   1. FB PSID extracted from raw client_id `fb_<page>_<psid>` (from FB hook)
		//   2. fallback to contact_id when not running on FB Messenger
		$client_id_str = $contact_id_str;
		$raw_cid = (string) ( $ctx['client_id'] ?? '' );
		if ( $raw_cid !== '' && strpos( $raw_cid, 'fb_' ) === 0 ) {
			$rest = substr( $raw_cid, 3 );
			$pos  = strpos( $rest, '_' );
			if ( $pos !== false ) {
				$psid = substr( $rest, $pos + 1 );
				if ( $psid !== '' ) { $client_id_str = $psid; }
			}
		} elseif ( $raw_cid !== '' ) {
			// Non-FB channel — pass raw client_id through.
			$client_id_str = $raw_cid;
		}
		$repl = array(
			'{{campaign_name}}' => (string) ( $campaign['name'] ?? '' ),
			'{{campaign_code}}' => (string) ( $campaign['code'] ?? '' ),
			'{{contact_id}}'    => $contact_id_str,
			'{{client_id}}'     => $client_id_str,
		);
		return strtr( $tpl, $repl );
	}

	/**
	 * Insert outbound message via Repository — single source of truth so the
	 * channel adapter dispatcher picks it up automatically.
	 */
	private static function insert_outbound( array $ctx, string $body, string $responder_kind ): int {
		$dbg = class_exists( 'BizCity_CG_Debug_Logger' );
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			if ( $dbg ) { BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'insert_outbound_skip_no_repo', array(), 'error' ); }
			return 0;
		}
		$conv_id  = (int) ( $ctx['conversation_id'] ?? 0 );
		$inbox_id = (int) ( $ctx['inbox_id']        ?? 0 );
		if ( $conv_id <= 0 || $inbox_id <= 0 || trim( $body ) === '' ) {
			if ( $dbg ) {
				BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'insert_outbound_skip_invalid', array(
					'conv_id'   => $conv_id,
					'inbox_id'  => $inbox_id,
					'body_len'  => strlen( $body ),
					'responder' => $responder_kind,
				), 'warn' );
			}
			return 0;
		}
		$mid = (int) BizCity_CRM_Repository::insert_message( array(
			'conversation_id' => $conv_id,
			'inbox_id'        => $inbox_id,
			'content'         => $body,
			'content_type'    => 'text',
			'message_type'    => 'outgoing',
			'sender_type'     => 'bot',
			'status'          => 'sent',
			// responder_kind column is VARCHAR(10) — must truncate or insert silently fails
			// under MySQL STRICT_TRANS_TABLES. Use short slugs: cmp_send / cmp_short / cmp_remind.
			'responder_kind'  => substr( $responder_kind, 0, 10 ),
		) );
		if ( $dbg ) {
			BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'insert_outbound_result', array(
				'conv_id'    => $conv_id,
				'inbox_id'   => $inbox_id,
				'body_len'   => strlen( $body ),
				'responder'  => $responder_kind,
				'message_id' => $mid,
			), $mid > 0 ? 'info' : 'warn' );
		}

		// M6.W13.8 — DB insert is not enough; we must dispatch via the channel
		// adapter to actually deliver the message. AI_Replier + Action_Registry +
		// REST controller all do this same step. Without it, the outbound row
		// sits in DB and FB user never sees the scenario message.
		if ( $mid > 0 && class_exists( 'BizCity_CRM_Channel_Registry' ) ) {
			$inbox = BizCity_CRM_Repository::get_inbox( $inbox_id );
			$conv  = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( $inbox && $conv ) {
				$adapter = BizCity_CRM_Channel_Registry::get( (string) $inbox['channel_type'] );
				if ( $adapter && method_exists( $adapter, 'send' ) ) {
					try {
						$result = $adapter->send( $conv, array( 'content' => $body, 'content_type' => 'text' ) );
						if ( $dbg ) {
							BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'adapter_send_result', array(
								'message_id'    => $mid,
								'channel_type'  => (string) $inbox['channel_type'],
								'dispatched'    => (bool) ( $result['success'] ?? false ),
								'result'        => $result,
							), ! empty( $result['success'] ) ? 'info' : 'warn' );
						}
					} catch ( \Throwable $e ) {
						if ( $dbg ) {
							BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'adapter_send_exception', array(
								'message_id' => $mid,
								'error'      => $e->getMessage(),
							), 'error' );
						}
					}
				} elseif ( $dbg ) {
					BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'adapter_send_skip_no_adapter', array( 'channel_type' => (string) $inbox['channel_type'] ), 'warn' );
				}
			} elseif ( $dbg ) {
				BizCity_CG_Debug_Logger::log( 'scenario_dispatcher', 'adapter_send_skip_no_inbox_or_conv', array( 'inbox_id' => $inbox_id, 'conv_id' => $conv_id, 'has_inbox' => (bool) $inbox, 'has_conv' => (bool) $conv ), 'warn' );
			}
		}

		return $mid;
	}

	private static function user_replied_since_dispatch( int $conv_id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		// Latest outbound from us
		$last_out = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(created_at) FROM {$tbl} WHERE conversation_id = %d AND message_type = 'outgoing'",
			$conv_id
		) );
		// Any inbound after that?
		$has_inbound_after = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl}
			  WHERE conversation_id = %d
			    AND message_type   = 'incoming'
			    AND created_at     > %s",
			$conv_id, $last_out !== '' ? $last_out : '1970-01-01 00:00:00'
		) );
		return $has_inbound_after > 0;
	}
}
