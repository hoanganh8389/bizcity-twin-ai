<?php
/**
 * BizCity Zalo Personal — WP → zalo-hub config sync (PHASE-0.80 Lane C 4a-8, doc 30 §4).
 *
 * A number served by zalo-hub is answered by the cell, so the cell must know what Bot Studio would have used:
 * the Guru instruction (persona), quick FAQ, tool switches, group/allowlist policy, the binding `mode`, and staff
 * hours. This class turns Bot Studio state into a `config_bundle` (01 §5.5) and sends it through the exact-key Hub
 * (`POST zalo-personal-bridge/config`, the Hub relays `PUT /wp/config` to the cell that hosts each account).
 *
 * Design rules
 *  - BASE LEVEL ONLY (R-GURU-PRIVATE): instruction + quick FAQ. No notebooks, KG, memory, secrets (D-L12 = no secrets in the bundle).
 *  - FAIL CLOSED: a number with no binding/Guru, `manual` mode, or a setting the cell cannot honour (`contacts_only`)
 *    goes to the cell as `mode=manual` (bot silent, messages still reach the CRM) — never as "answer everybody".
 *  - Change detection by FINGERPRINT (hash of the bundle without version/generated_at): the hook `bizcity_bot_config_changed`
 *    debounces a send, and a 5-minute tick covers edits that fire no hook (Guru persona, quick FAQ) without touching Knowledge core.
 *    Nothing is sent when nothing changed. Versions only move forward (cell rule T-7); a stale-version answer re-bases the counter.
 *  - Staff hours are INVERTED on the way out: Bot Studio's `office_hours` = hours a human is on duty (bot silent inside);
 *    the cell's `office_hours` = hours the bot works (silent outside). The bundle carries the complement.
 *  - No model is sent: the Hub picks the plan default (Branch 21, empty `model`).
 *
 * @package BizCity_Zalo_Personal
 * @since   1.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Hub_Config_Sync', false ) ) {
	return;
}

final class BizCity_Zalo_Hub_Config_Sync {

	const STATE_OPTION = 'bizcity_zalo_hub_config_sync';
	const HOOK_CHANGED = 'bizcity_bot_config_changed';
	const CRON_RUN     = 'bizcity_zalo_hub_config_sync_run';
	const CRON_TICK    = 'bizcity_zalo_hub_config_sync_tick';
	const LOCK         = 'bizcity_zalo_hub_config_sync_lock';
	const PLATFORM     = 'ZALO_PERSONAL';
	// [2026-09-27 Claude Opus 5.5] PHASE-0.81 C0.5 (C-5). A cell older than A5.5 validates `contract` as the 1.0 literal and rejects
	// 1.1; the filter `bizcity_zalo_hub_bundle_contract` returning CONTRACT_V10 is the way back while such a cell is still serving.
	const CONTRACT     = 'zalo-hub-bridge/1.1';
	const CONTRACT_V10 = 'zalo-hub-bridge/1.0';
	const DEBOUNCE     = 5;
	const RETRY_AFTER  = 60;
	const MAX_ATTEMPTS = 10;
	const MAX_ACCOUNTS = 200;
	const FAQ_MAX      = 100;
	const PROMPT_MAX   = 60000;

	/**
	 * Test seams (same pattern as BizCity_Bot_Context_Builder): name => callable.
	 * accounts(): list<{bridge_id,label,owner_user_id}> · binding(bridge_id): ?array · character(id): ?object
	 * faq(id): list<{q,a}> · bot_settings(id): array · sender(bundle): array · now(): int
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/** Bot Studio tool id → zalo-hub tool key(s) (doc 30 §4.2). Ids without a row are simply not sent (the cell would put them in ignored[]). */
	const TOOL_MAP = array(
		'web_search'      => array( 'web_search' ),
		'read_url'        => array( 'web_fetch' ),
		'read_image'      => array( 'read_image' ),
		'generate_image'  => array( 'create_image' ),
		'create_document' => array( 'create_word_document', 'create_excel_file', 'create_csv_file', 'create_markdown_file' ),
		'tts'             => array( 'create_voice' ),
		'current_datetime' => array( 'get_datetime' ),
		'save_memory'     => array( 'save_memory' ),
		'schedule_task'   => array( 'schedule_task' ),
		// [2026-09-26 Claude Opus 5.5] the registry id is scrape_social_data (was `scrape_social`, which matched nothing, so the switch never left WP).
		'scrape_social_data' => array( 'scrape_social_data' ),
		'send_file'       => array( 'send_file' ),
		'list_threads'    => array( 'list_threads' ),
		'read_thread'     => array( 'read_thread' ),
		'search_arxiv'    => array( 'search_arxiv' ),
		'search_scholar'  => array( 'search_scholar' ),
		'search_github'   => array( 'search_github' ),
		'search_stackexchange' => array( 'search_stackexchange' ),
		'search_hackernews' => array( 'search_hackernews' ),
		'search_wikipedia' => array( 'search_wikipedia' ),
		// Zalo actions (BizCity_Bot_Zalo_Actions::tools()) — same action, the cell's own name.
		'get_group_info'  => array( 'get_group_info' ),
		'mention_member'  => array( 'tag_member' ),
		'react_message'   => array( 'add_reaction' ),
		'send_sticker'    => array( 'send_sticker' ),
		'recall_message'  => array( 'undo_message' ),
		'create_poll'     => array( 'create_poll' ),
		'lock_poll'       => array( 'lock_poll' ),
		'list_pending_group_members' => array( 'list_pending_group_members' ),
		'review_pending_group_member' => array( 'review_pending_member' ),
		'kick_group_member' => array( 'kick_member' ),
		'transfer_group_owner' => array( 'transfer_group_owner' ),
		'set_group_deputy' => array( 'set_group_deputy' ),
		'set_group_member_blocked' => array( 'set_group_member_blocked' ),
		'set_group_invite_link' => array( 'set_group_invite_link' ),
		'rename_group'    => array( 'rename_group' ),
		'change_group_avatar' => array( 'change_group_avatar' ),
		'pin_group_note'  => array( 'pin_group_note' ),
	);

	/** Cell bounds (zalo-hub tuning-definitions.ts): MESSAGE_BATCH_DEBOUNCE_MS ≤ 15 000 ms. Bot Studio allows up to 120 s. */
	const CELL_DEBOUNCE_MAX_S = 15;

	/** Saves that fire no `bizcity_bot_config_changed` but change what the cell must know (Guru persona/FAQ, site tuning, provider). */
	const EXTRA_HOOKS = array( 'bizcity_knowledge_character_saved', 'bizcity_knowledge_character_deleted', 'bizcity_zalo_account_flags_changed', 'update_option_bizcity_bot_tuning' );

	/* ================================================================
	 *  Wiring
	 * ================================================================ */

	public static function boot(): void {
		add_action( self::HOOK_CHANGED, array( __CLASS__, 'on_changed' ), 10, 0 );
		foreach ( self::EXTRA_HOOKS as $hook ) { add_action( $hook, array( __CLASS__, 'on_changed' ), 10, 0 ); }
		// Guru quick-edit (instruction / quick FAQ) fires no hook and lives in Knowledge core: watch its REST write instead of editing core.
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'after_rest' ), 10, 3 );
		add_action( self::CRON_RUN, array( __CLASS__, 'run_scheduled' ) );
		add_action( self::CRON_TICK, array( __CLASS__, 'tick' ) );
		add_filter( 'cron_schedules', static function ( $s ) {
			if ( ! isset( $s['bizcity_five_minutes'] ) ) { $s['bizcity_five_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 minutes (BizCity)' ); }
			return $s;
		} );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_TICK ) && function_exists( 'wp_schedule_event' ) ) {
			wp_schedule_event( time() + 60, 'bizcity_five_minutes', self::CRON_TICK );
		}
	}

	/** Listener for `bizcity_bot_config_changed` — ignores whatever scope/id the emitter passes. */
	public static function on_changed( ...$ignored ): void { self::schedule(); }

	/** A successful non-GET call to `bizcity-knowledge/v1/characters/{id}/quick-edit` schedules a sync; the response passes through untouched. */
	public static function after_rest( $response, $handler = null, $request = null ) {
		if ( is_object( $request ) && method_exists( $request, 'get_route' ) && method_exists( $request, 'get_method' )
			&& 'GET' !== strtoupper( (string) $request->get_method() )
			&& preg_match( '#^/bizcity-knowledge/v1/characters/\d+/quick-edit#', (string) $request->get_route() )
			&& ! ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) )
			&& ! ( is_object( $response ) && method_exists( $response, 'get_status' ) && (int) $response->get_status() >= 300 ) ) {
			self::schedule();
		}
		return $response;
	}

	/** Debounced send: many saves in a row collapse into one run `DEBOUNCE` seconds after the first. */
	public static function schedule( int $delay = self::DEBOUNCE ): void {
		if ( ! self::has_accounts() ) { return; }
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_RUN ) ) { return; }
		if ( function_exists( 'wp_schedule_single_event' ) ) { wp_schedule_single_event( self::now() + max( 1, $delay ), self::CRON_RUN ); }
	}

	public static function run_scheduled(): void { self::run( false ); }

	/** Five-minute safety net: cheap when the site has no zalo-hub number, a hash compare when nothing changed. */
	public static function tick(): void {
		if ( ! self::has_accounts() ) { return; }
		self::run( false );
	}

	/* ================================================================
	 *  Bundle
	 * ================================================================ */

	/** zalo-hub numbers of THIS site (provider from the Hub-learnt flags; label/owner from the local mapping). */
	public static function accounts(): array {
		if ( isset( self::$readers['accounts'] ) ) { return (array) call_user_func( self::$readers['accounts'] ); }
		if ( ! class_exists( 'BizCity_Zalo_Account_Flags' ) ) { return array(); }
		$labels = array();
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'list_personal_accounts' ) ) {
			foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) ) as $row ) {
				$labels[ (string) ( $row['bridge_account_id'] ?? '' ) ] = $row;
			}
		}
		$out = array();
		foreach ( array_keys( BizCity_Zalo_Account_Flags::all() ) as $bridge_id ) {
			$bridge_id = (string) $bridge_id;
			if ( BizCity_Zalo_Account_Flags::provider( $bridge_id ) !== BizCity_Zalo_Account_Flags::PROVIDER_ZALO_HUB ) { continue; }
			$row = $labels[ $bridge_id ] ?? array();
			$out[] = array( 'bridge_id' => $bridge_id, 'label' => (string) ( $row['label'] ?? $row['account_name'] ?? '' ), 'owner_user_id' => (int) ( $row['owner_user_id'] ?? 0 ) );
		}
		return $out;
	}

	public static function has_accounts(): bool { return self::accounts() !== array(); }

	/**
	 * @return array{bundle:array,warnings:string[]} `warnings` are WP-side notes (settings the cell cannot honour) shown in the status.
	 */
	public static function build( int $version ): array {
		$accounts = array();
		$agents   = array();
		$warnings = array();
		// [2026-09-26 Claude Opus 5.5] PHASE-0.80 R-GURU-SOURCE (GS-2) — legacy "no Guru" bindings default to mode=auto and were silent;
		// normalise them once BEFORE gate 0 can give them the default Guru (same guard as the PHP claim), so no number starts talking.
		if ( self::resolver() ) {
			BizCity_Guru_Context_Resolver::normalize_legacy_bindings();
		}
		foreach ( array_slice( self::accounts(), 0, self::MAX_ACCOUNTS ) as $acc ) {
			$bridge_id = (string) $acc['bridge_id'];
			$binding   = self::binding( $bridge_id );
			$row = array( 'account_id' => $bridge_id, 'label' => mb_substr( (string) $acc['label'], 0, 100 ) );
			// NEVER send `owner_user_id`: it is a WordPress user id, but the cell maps it onto `account.ownerUserId`, which is the ZALO uid
			// allowed to run owner-only tools (cross-thread-gate / owner-action-gate compare it with the sender's Zalo id). A WP id there
			// would replace the fail-closed empty owner with a bogus one. The real owner is `policy.owner_uid` (a Zalo uid), below.

			$character_id = is_array( $binding ) ? (int) ( $binding['character_id'] ?? 0 ) : 0;
			$mode         = is_array( $binding ) ? (string) ( $binding['mode'] ?? '' ) : '';
			if ( ! in_array( $mode, array( 'auto', 'hybrid', 'manual' ), true ) ) { $mode = 'manual'; }
			// R-GURU-SOURCE R-GS-8 (gate 0) — AI on + no Guru chosen ⇒ the tenant default Guru, exactly like a PHP turn.
			if ( $character_id <= 0 && in_array( $mode, array( 'auto', 'hybrid' ), true ) ) {
				$character_id = isset( self::$readers['default_guru'] ) ? (int) call_user_func( self::$readers['default_guru'] ) : ( self::resolver() ? BizCity_Guru_Context_Resolver::default_character_id( true ) : 0 );
			}
			if ( $character_id <= 0 ) {
				// No Guru bound: Bot Studio says `no_binding`/`no_character` and stays silent. The cell must too.
				$row['mode'] = 'manual';
				// [2026-09-28 Claude Sonnet 5] PHASE-0.81 N-1 (peer review, confirmed) — send `office_hours` here too (same shape as the
				// bound path below): without it, a number that HAD hours/pause_on_manual_reply configured before its Guru was unbound
				// keeps whatever the cell already holds. Moot while silent, but stale the moment it gets a Guru rebound before its next
				// unrelated edit re-sends this block.
				$no_guru_hours = self::decode( is_array( $binding ) ? ( $binding['office_hours_json'] ?? '' ) : '' );
				$oh = self::bot_hours( $no_guru_hours );
				$row['office_hours'] = null !== $oh ? $oh : array( 'enabled' => false, 'pause_on_manual_reply' => ! empty( $no_guru_hours['pause_on_manual_reply'] ) );
				$warnings[] = $bridge_id . ': chưa gắn Guru (binding) — bot zalo-hub im lặng, tin vẫn về CRM';
				$accounts[] = $row;
				continue;
			}

			$policy = self::decode( is_array( $binding ) ? ( $binding['policy_json'] ?? '' ) : '' );
			$hours  = self::decode( is_array( $binding ) ? ( $binding['office_hours_json'] ?? '' ) : '' );
			// Wire ref of the Guru (R-GURU-SOURCE contract): the default Guru is always `guru:0`, so the cell and the Hub
			// `GET zalo-hub/guru/{ref}` address it the same way whichever character row currently plays that role.
			$ref = self::guru_ref( $character_id );
			$row['agent_ref'] = $ref;

			$pol = array();
			$allow = isset( $policy['allowlist_mode'] ) ? (string) $policy['allowlist_mode'] : 'all';
			if ( 'contacts_only' === $allow ) {
				// The cell has no CRM contact table (doc 30 §4.3): fail closed instead of widening to "everybody".
				$mode = 'manual';
				$warnings[] = $bridge_id . ': allowlist "chỉ contact CRM" chưa hỗ trợ trên zalo-hub — bot tạm im lặng';
			} elseif ( 'list' === $allow ) {
				$pol['allowlist_mode'] = 'list';
				$pol['allowlist_uids'] = array_values( array_map( 'strval', (array) ( $policy['allowlist_uids'] ?? array() ) ) );
			} else {
				$pol['allowlist_mode'] = 'all';
			}
			$pol['reply_in_group']           = ! isset( $policy['reply_in_group'] ) || (bool) $policy['reply_in_group'];
			$pol['passive_listen_in_group']  = ! isset( $policy['passive_listen_in_group'] ) || (bool) $policy['passive_listen_in_group'];
			$pol['require_mention_in_group'] = ! empty( $hours['require_mention_in_group'] ) || ! empty( $policy['require_mention_in_group'] );
			if ( '' !== trim( (string) ( $policy['owner_uid'] ?? '' ) ) ) { $pol['owner_uid'] = trim( (string) $policy['owner_uid'] ); }
			$pol['typing_indicator'] = ! empty( $policy['typing_indicator'] );
			$pol['read_receipts']    = 'delivered_seen' === (string) ( $policy['read_receipts'] ?? 'off' );
			$pol['auto_react']       = ! empty( $policy['auto_react'] );
			if ( '' !== (string) ( $policy['react_icon'] ?? '' ) ) { $pol['react_icon'] = sanitize_key( (string) $policy['react_icon'] ); }
			$row['policy'] = $pol;
			// [2026-09-27 Claude Opus 5.5] PHASE-0.81 C0.1 (D-S81-3, G-22) — the cell has no "suggest only" mode; sending `hybrid` let it
			// answer like `auto`. The number goes manual (silent, messages still reach CRM) and `mode_source` lets Bot Studio say why.
			if ( 'hybrid' === $mode ) {
				$mode = 'manual';
				$row['mode_source'] = 'hybrid';
				$warnings[] = $bridge_id . ': chế độ Chỉ gợi ý chưa hỗ trợ trên zalo-hub — số đang để thủ công';
			}
			$row['mode']   = $mode;

			// Staff hours off ⇒ no working-hours restriction, but the manual-reply pause still applies: Bot Studio pauses only when the
			// flag is set (absent ⇒ no pause, as in BizCity_Bot_Turn_Claim), so say so instead of leaving the cell on its own default.
			// [2026-09-27 Claude Opus 5.5] PHASE-0.81 C0.2 (S81-S2) — `enabled:false` is explicit: without it the cell kept the hours it
			// already held after the owner switched them off.
			$oh = self::bot_hours( $hours );
			$row['office_hours'] = null !== $oh ? $oh : array( 'enabled' => false, 'pause_on_manual_reply' => ! empty( $hours['pause_on_manual_reply'] ) );
			// Per-number history depth, resolved exactly like a Bot Studio turn (binding → Guru → 20, clamped 20–200).
			$row['tuning'] = array( 'history_limit' => self::history_limit( $policy, self::bot_settings( $character_id ) ) );
			$accounts[] = $row;

			if ( ! isset( $agents[ $ref ] ) ) {
				$agent = self::agent( $ref, $character_id, $binding, $warnings );
				if ( null !== $agent ) { $agents[ $ref ] = $agent; }
			}
		}
		$bundle = array(
			'contract'     => self::contract(),
			'version'      => max( 1, $version ),
			'generated_at' => gmdate( 'c', self::now() ),
			'accounts'     => $accounts,
			'agents'       => array_values( $agents ),
		);
		$tuning = self::site_tuning( $warnings );
		if ( $tuning ) { $bundle['tuning'] = $tuning; }
		return array( 'bundle' => $bundle, 'warnings' => array_values( array_unique( $warnings ) ) );
	}

	private static function contract(): string {
		$c = function_exists( 'apply_filters' ) ? (string) apply_filters( 'bizcity_zalo_hub_bundle_contract', self::CONTRACT ) : self::CONTRACT;
		return in_array( $c, array( self::CONTRACT, self::CONTRACT_V10 ), true ) ? $c : self::CONTRACT;
	}

	/** Cell floor of LLM_TURN_TIMEOUT_MS (tuning-definitions.ts), in seconds. Bot Studio allows 10 s. */
	const CELL_TURN_TIMEOUT_MIN_S = 60;

	/** Cell range of SCHEDULER_MAX_PROACTIVE_PER_DAY, where `daily_message_cap` lands (D-S81-6). Bot Studio allows 500. */
	const CELL_DAILY_CAP_MAX = 100;

	/** Tuning keys this class sends (site_tuning + per-account history_limit). Every other Bot Studio tuning key is not applied on zalo-hub. */
	const SENT_TUNING = array( 'debounce_seconds', 'pause_window_minutes', 'max_tool_steps', 'turn_timeout_seconds', 'daily_message_cap', 'reasoning_effort' );

	/** Guru-level Bot Studio settings a zalo-hub number does not apply (PHASE-0.81 C0.4, shown by C3.2). */
	const GURU_NOT_APPLIED = array(
		'vision_mode'            => 'Xem ảnh khách gửi (Guru)',
		'context_source'         => 'Nguồn ngữ cảnh CRM/Context Bank (Guru)',
		'enabled_optional_tools' => 'Công cụ tuỳ chọn bật thêm (Guru)',
		'bypass_notebook'        => 'Bỏ qua notebook (Guru)',
	);

	/**
	 * Site-wide Bot Studio tuning sent under `tuning` (C-5). `history_char_budget` stays home (tokens vs characters).
	 * [2026-09-27 Claude Opus 5.5] PHASE-0.81 C0.4 (S81-S7) — `max_tool_steps`, `turn_timeout_seconds`, `daily_message_cap` are now sent:
	 *  - `max_tool_steps`: Bot Studio counts tool rounds (0–5), the cell counts LLM steps (≥ 1, the answer is a step) ⇒ rounds + 1;
	 *  - `turn_timeout_seconds`: raised to the cell floor (60 s) with a warning;
	 *  - `daily_message_cap`: sent as is; the cell (A5.5) owns its meaning and answers a warning when out of its range.
	 */
	private static function site_tuning( array &$warnings ): array {
		$t = isset( self::$readers['tuning'] ) ? (array) call_user_func( self::$readers['tuning'] ) : ( class_exists( 'BizCity_Bot_Config_Repo' ) && method_exists( 'BizCity_Bot_Config_Repo', 'get_tuning' ) ? (array) BizCity_Bot_Config_Repo::get_tuning() : array() );
		$out = array();
		if ( isset( $t['debounce_seconds'] ) && is_numeric( $t['debounce_seconds'] ) ) {
			$d = max( 1, (int) $t['debounce_seconds'] );
			if ( $d > self::CELL_DEBOUNCE_MAX_S ) {
				$warnings[] = 'tuning: chờ gộp tin ' . $d . ' giây vượt trần ' . self::CELL_DEBOUNCE_MAX_S . ' giây của zalo-hub — gửi ' . self::CELL_DEBOUNCE_MAX_S . ' giây';
				$d = self::CELL_DEBOUNCE_MAX_S;
			}
			$out['debounce_seconds'] = $d;
		}
		if ( isset( $t['pause_window_minutes'] ) && is_numeric( $t['pause_window_minutes'] ) ) {
			$out['pause_window_minutes'] = max( 1, min( 1440, (int) $t['pause_window_minutes'] ) );
		}
		if ( isset( $t['max_tool_steps'] ) && is_numeric( $t['max_tool_steps'] ) ) {
			$out['max_tool_steps'] = max( 0, min( 5, (int) $t['max_tool_steps'] ) ) + 1;
		}
		if ( isset( $t['turn_timeout_seconds'] ) && is_numeric( $t['turn_timeout_seconds'] ) ) {
			$s = min( 300, (int) $t['turn_timeout_seconds'] );
			if ( $s < self::CELL_TURN_TIMEOUT_MIN_S ) {
				$warnings[] = 'tuning: trần thời gian một lượt ' . $s . ' giây thấp hơn mức tối thiểu ' . self::CELL_TURN_TIMEOUT_MIN_S . ' giây của zalo-hub — gửi ' . self::CELL_TURN_TIMEOUT_MIN_S . ' giây';
				$s = self::CELL_TURN_TIMEOUT_MIN_S;
			}
			$out['turn_timeout_seconds'] = $s;
		}
		if ( isset( $t['daily_message_cap'] ) && is_numeric( $t['daily_message_cap'] ) ) {
			// [2026-09-27 Claude Opus 5.5] D-S81-6 — the cell keeps its own setting when it has one, otherwise takes this value within 1–100
			// (it counts proactive messages only). Clamp here too so Bot Studio shows the cut instead of the cell doing it silently.
			$cap = max( 1, (int) $t['daily_message_cap'] );
			if ( $cap > self::CELL_DAILY_CAP_MAX ) {
				$warnings[] = 'tuning: trần ' . $cap . ' tin/ngày vượt mức ' . self::CELL_DAILY_CAP_MAX . ' của zalo-hub (chỉ đếm tin chủ động) — gửi ' . self::CELL_DAILY_CAP_MAX;
				$cap = self::CELL_DAILY_CAP_MAX;
			}
			$out['daily_message_cap'] = $cap;
		}
		// Bot Studio has no reasoning setting today; sent only when one exists (C-5: missing ⇒ cell default).
		if ( isset( $t['reasoning_effort'] ) && in_array( $t['reasoning_effort'], array( 'off', 'low', 'medium', 'high' ), true ) ) {
			$out['reasoning_effort'] = (string) $t['reasoning_effort'];
		}
		return $out;
	}

	/**
	 * Bot Studio settings a zalo-hub number does not apply (tuning keys not sent + Guru-level switches), for the Bot Studio notice.
	 *
	 * @return list<array{key:string,label:string}>
	 */
	public static function not_applied(): array {
		$out = array();
		$registry = class_exists( 'BizCity_Bot_Config_Repo' ) && method_exists( 'BizCity_Bot_Config_Repo', 'tuning_registry' ) ? (array) BizCity_Bot_Config_Repo::tuning_registry() : array();
		foreach ( $registry as $key => $row ) {
			if ( ! in_array( (string) $key, self::SENT_TUNING, true ) ) {
				$out[] = array( 'key' => (string) $key, 'label' => (string) ( $row['label'] ?? $key ) );
			}
		}
		foreach ( self::GURU_NOT_APPLIED as $key => $label ) {
			$out[] = array( 'key' => $key, 'label' => $label );
		}
		return $out;
	}

	private static function history_limit( array $policy, array $settings ): int {
		if ( class_exists( 'BizCity_Bot_Config_Repo' ) && method_exists( 'BizCity_Bot_Config_Repo', 'resolve_history_limit' ) ) {
			return BizCity_Bot_Config_Repo::resolve_history_limit( $policy, $settings );
		}
		$v = isset( $policy['history_limit'] ) && (int) $policy['history_limit'] > 0 ? (int) $policy['history_limit'] : ( isset( $settings['history_limit'] ) && (int) $settings['history_limit'] > 0 ? (int) $settings['history_limit'] : 20 );
		return max( 20, min( 200, $v ) );
	}

	private static function bot_settings( int $character_id ): array {
		if ( isset( self::$readers['bot_settings'] ) ) { return (array) call_user_func( self::$readers['bot_settings'], $character_id ); }
		return class_exists( 'BizCity_Bot_Config_Repo' ) ? (array) BizCity_Bot_Config_Repo::get( $character_id ) : array();
	}

	/** R-GURU-SOURCE — the resolver is the one content source; this class only falls back to its own readers when it is not loaded (tests). */
	private static function resolver(): bool {
		return empty( self::$readers ) && class_exists( 'BizCity_Guru_Context_Resolver' );
	}

	private static function guru_ref( int $character_id ): string {
		if ( isset( self::$readers['default_guru'] ) && (int) call_user_func( self::$readers['default_guru'] ) === $character_id ) { return 'guru:0'; }
		if ( self::resolver() && BizCity_Guru_Context_Resolver::default_character_id( false ) === $character_id ) { return BizCity_Guru_Context_Resolver::DEFAULT_REF; }
		return 'guru:' . $character_id;
	}

	/** One agent from a Guru: instruction + quick FAQ + disabled tools. No model (the Hub picks), no keys, no notebook. */
	private static function agent( string $ref, int $character_id, $binding, array &$warnings ): ?array {
		$char = isset( self::$readers['character'] ) ? call_user_func( self::$readers['character'], $character_id ) : self::character( $character_id );
		if ( ! $char ) { $warnings[] = $ref . ': Guru không tồn tại — cell dùng persona rỗng'; return null; }
		// R-GURU-SOURCE (GS-1) — instruction + FAQ come from the SAME resolver the PHP engine composes from, so both reply paths start
		// from identical content (R-GS-10). The bundle copy is a warm cache; `guru_version` lets the cell refetch the profile (GS-6).
		$profile = self::resolver() ? BizCity_Guru_Context_Resolver::profile( $character_id ) : null;
		$prompt = null !== $profile ? trim( (string) $profile['instruction']['text'] ) : trim( (string) ( $char->system_prompt ?? '' ) );
		if ( mb_strlen( $prompt ) > self::PROMPT_MAX ) { $prompt = mb_substr( $prompt, 0, self::PROMPT_MAX ); $warnings[] = $ref . ': instruction dài hơn ' . self::PROMPT_MAX . ' ký tự — đã cắt'; }
		$faq = array();
		$rows = null !== $profile ? (array) $profile['instruction']['faq'] : ( isset( self::$readers['faq'] ) ? (array) call_user_func( self::$readers['faq'], $character_id ) : self::faq( $character_id ) );
		foreach ( array_slice( $rows, 0, self::FAQ_MAX ) as $r ) {
			$q = trim( (string) ( $r['q'] ?? '' ) ); $a = trim( (string) ( $r['a'] ?? '' ) );
			if ( '' === $q && '' === $a ) { continue; }
			$faq[] = array( 'q' => mb_substr( $q, 0, 2000 ), 'a' => mb_substr( $a, 0, 8000 ) );
		}
		$settings = self::bot_settings( $character_id );
		$off = (array) ( $settings['disabled_tools'] ?? array() );
		$hours = self::decode( is_array( $binding ) ? ( $binding['office_hours_json'] ?? '' ) : '' );
		$off = array_merge( $off, (array) ( $hours['disabled_tools'] ?? array() ) );
		$disabled = array();
		foreach ( $off as $id ) { foreach ( self::TOOL_MAP[ (string) $id ] ?? array() as $cell_tool ) { $disabled[ $cell_tool ] = true; } }
		$agent = array( 'ref' => $ref, 'name' => mb_substr( (string) ( $char->name ?? $ref ), 0, 100 ), 'system_prompt' => $prompt, 'faq' => $faq );
		if ( null !== $profile ) { $agent['guru_version'] = (string) $profile['guru']['etag']; }
		if ( $disabled ) { $agent['tools_disabled'] = array_keys( $disabled ); } // never `tools_enabled`: the cell would then disable everything else
		$knowledge = self::knowledge( $character_id );
		if ( null !== $knowledge ) { $agent['knowledge'] = $knowledge; }
		return $agent;
	}

	/**
	 * [2026-09-27 Claude Opus 5.5] PHASE-0.81 C0.3 (C-5) — `{notebook_ids, versions}` when the Guru opted into `base+notebooks`, else null
	 * (no key: base level only, R-GURU-PRIVATE). Ids are the one source of C1.0; a notebook edit moves its version, so the fingerprint
	 * changes and the next tick re-sends the bundle. Only ids and versions travel here — the content goes over C-4.
	 */
	private static function knowledge( int $character_id ): ?array {
		if ( isset( self::$readers['knowledge'] ) ) {
			$scope = (array) call_user_func( self::$readers['knowledge'], $character_id );
		} elseif ( self::resolver() ) {
			$scope = BizCity_Guru_Context_Resolver::scope( $character_id );
		} else {
			return null;
		}
		if ( 'base+notebooks' !== ( $scope['knowledge'] ?? 'base' ) ) { return null; }
		$ids = array_values( array_filter( array_map( 'intval', (array) ( $scope['notebook_ids'] ?? array() ) ), static function ( $v ) { return $v > 0; } ) );
		$versions = array();
		foreach ( $ids as $id ) {
			$versions[ (string) $id ] = class_exists( 'BizCity_Zalo_Guru_Knowledge_Version' ) ? BizCity_Zalo_Guru_Knowledge_Version::for_notebook( $id ) : '';
		}
		return array( 'notebook_ids' => $ids, 'versions' => (object) $versions ); // object: `{}` on the wire even when empty
	}

	/**
	 * Staff duty hours (Bot Studio) → bot working hours (cell) = the complement inside each weekday.
	 * Returns null when the feature is off (the cell then imposes no restriction).
	 */
	public static function bot_hours( array $hours ): ?array {
		if ( empty( $hours['enabled'] ) ) { return null; }
		$names = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );
		$src = (array) ( $hours['days'] ?? array() );
		$out_days = array();
		foreach ( $names as $i => $name ) {
			$ranges = array();
			$list = $src[ $name ] ?? $src[ $i ] ?? $src[ (string) $i ] ?? array();
			foreach ( (array) $list as $r ) {
				$s = self::minutes( is_array( $r ) ? ( $r['start'] ?? $r[0] ?? '' ) : '' );
				$e = self::minutes( is_array( $r ) ? ( $r['end'] ?? $r[1] ?? '' ) : '' );
				if ( null === $s || null === $e ) { continue; }
				if ( $e > $s ) { $ranges[] = array( $s, $e ); }
				elseif ( $e < $s ) { $ranges[] = array( $s, 1440 ); $ranges[] = array( 0, $e ); } // crosses midnight
			}
			usort( $ranges, static function ( $a, $b ) { return $a[0] <=> $b[0]; } );
			$cursor = 0; $free = array();
			foreach ( $ranges as $r ) {
				if ( $r[0] > $cursor ) { $free[] = array( $cursor, $r[0] ); }
				$cursor = max( $cursor, $r[1] );
			}
			if ( $cursor < 1440 ) { $free[] = array( $cursor, 1440 ); }
			$out_days[ $name ] = array_map( static function ( $f ) { return array( self::hhmm( $f[0] ), self::hhmm( $f[1] ) ); }, $free );
		}
		$tz = trim( (string) ( $hours['timezone'] ?? '' ) );
		if ( '' === $tz && function_exists( 'wp_timezone_string' ) ) { $tz = (string) wp_timezone_string(); }
		$oh = array( 'enabled' => true, 'days' => $out_days, 'pause_on_manual_reply' => ! empty( $hours['pause_on_manual_reply'] ) );
		if ( '' !== $tz ) { $oh['timezone'] = $tz; }
		return $oh;
	}

	private static function minutes( $hhmm ): ?int {
		if ( ! is_string( $hhmm ) || ! preg_match( '/^\s*(\d{1,2}):(\d{2})\s*$/', $hhmm, $m ) ) { return null; }
		$h = (int) $m[1]; $p = (int) $m[2];
		return $h > 24 || $p > 59 ? null : min( 1440, $h * 60 + $p );
	}

	private static function hhmm( int $min ): string { return sprintf( '%02d:%02d', intdiv( $min, 60 ), $min % 60 ); }

	/** Hash of what the cell would see; version and timestamp excluded so an unchanged config never re-sends. */
	public static function fingerprint( array $bundle ): string {
		unset( $bundle['version'], $bundle['generated_at'] );
		return md5( (string) wp_json_encode( $bundle ) );
	}

	/* ================================================================
	 *  Send
	 * ================================================================ */

	/**
	 * Build → compare → send. `$force` re-sends even when nothing changed ("Đồng bộ lại").
	 *
	 * @return array{ok:bool,code:string,version:int,message:string}
	 */
	public static function run( bool $force = false ): array {
		if ( ! self::has_accounts() ) { return array( 'ok' => true, 'code' => 'no_accounts', 'version' => (int) ( self::state()['version'] ?? 0 ), 'message' => 'Site chưa có số zalo-hub.' ); }
		if ( function_exists( 'get_transient' ) && false !== get_transient( self::LOCK ) ) { return array( 'ok' => true, 'code' => 'busy', 'version' => (int) ( self::state()['version'] ?? 0 ), 'message' => 'Đang có một lượt đồng bộ khác.' ); }
		if ( function_exists( 'set_transient' ) ) { set_transient( self::LOCK, 1, 30 ); }
		try {
			return self::run_locked( $force );
		} finally {
			if ( function_exists( 'delete_transient' ) ) { delete_transient( self::LOCK ); }
		}
	}

	private static function run_locked( bool $force ): array {
		$state = self::state();
		$prev  = (int) ( $state['version'] ?? 0 );
		$built = self::build( $prev + 1 );
		$bundle = $built['bundle'];
		$hash = self::fingerprint( $bundle );
		if ( ! $force && ! empty( $state['ok'] ) && ( $state['hash'] ?? '' ) === $hash ) {
			return array( 'ok' => true, 'code' => 'unchanged', 'version' => $prev, 'message' => 'Cấu hình không đổi từ v' . $prev . '.' );
		}
		$result = self::send( $bundle );
		$ok = ! empty( $result['success'] ) || ! empty( $result['ok'] );
		$applied = (int) ( $result['version_applied'] ?? 0 );
		$ignored = array(); $cell_warnings = array();
		foreach ( (array) ( $result['cells'] ?? array() ) as $c ) {
			foreach ( (array) ( $c['ignored'] ?? array() ) as $i ) { $ignored[] = (string) $i; }
			foreach ( (array) ( $c['warnings'] ?? array() ) as $w ) { $cell_warnings[] = (string) $w; }
		}
		$stale = in_array( 'stale_version', $ignored, true );
		$attempts = $ok && ! $stale ? 0 : (int) ( $state['attempts'] ?? 0 ) + 1;
		$new = array(
			'version'   => $ok && ! $stale ? $bundle['version'] : max( $prev, $applied ),  // a stale answer re-bases the counter to what the cell already has
			'hash'      => $ok && ! $stale ? $hash : (string) ( $state['hash'] ?? '' ),
			'ok'        => $ok && ! $stale,
			'at'        => self::now(),
			'code'      => $ok ? ( $stale ? 'stale_version' : 'ok' ) : (string) ( $result['code'] ?? 'managed_bridge_unavailable' ),
			'applied'   => $applied,
			'accounts'  => count( $bundle['accounts'] ),
			'agents'    => count( $bundle['agents'] ),
			'ignored'   => array_slice( array_values( array_unique( $ignored ) ), 0, 30 ),
			'warnings'  => array_slice( array_merge( $built['warnings'], array_values( array_unique( $cell_warnings ) ) ), 0, 30 ),
			'attempts'  => $attempts,
			'hybrid_accounts' => $ok && ! $stale ? self::hybrid_accounts( $bundle ) : (array) ( $state['hybrid_accounts'] ?? array() ),
		);
		self::save_state( $new );
		if ( ! $new['ok'] && $attempts <= self::MAX_ATTEMPTS ) { self::schedule( self::RETRY_AFTER ); }
		return array( 'ok' => $new['ok'], 'code' => $new['code'], 'version' => (int) $new['version'], 'message' => $new['ok'] ? 'Đã đồng bộ zalo-hub v' . $new['version'] . ' (' . $new['accounts'] . ' số, ' . $new['agents'] . ' trợ lý).' : 'Chưa đồng bộ được (' . $new['code'] . ')' . ( $attempts <= self::MAX_ATTEMPTS ? ' — sẽ thử lại sau ' . self::RETRY_AFTER . ' giây.' : ' — hết lượt thử tự động.' ) );
	}

	/**
	 * [2026-09-26 Claude Opus 5.5] PHASE-0.80 D-ZA-2 — PULL side of the sync (cell → Hub → this site, doc 22 §4 / 23 §6).
	 * The cell reconciles on boot, every 10 minutes and on the first message of a tenant without config, so a lost push
	 * (Hub/cell down, new cell, DB restore) heals without waiting for this site's cron. Same bundle and version rules as
	 * the push: an unchanged fingerprint keeps the current version; a changed one mints version+1 and records it, so the
	 * next push sees `unchanged` instead of sending the same content twice.
	 *
	 * @return array{ok:bool,code:string,version:int,source_updated_at:int,config_bundle?:array}
	 */
	public static function pull( int $have_version = 0 ): array {
		if ( ! self::has_accounts() ) { return array( 'ok' => true, 'code' => 'no_accounts', 'version' => (int) ( self::state()['version'] ?? 0 ), 'source_updated_at' => 0 ); }
		if ( function_exists( 'get_transient' ) && false !== get_transient( self::LOCK ) ) { return array( 'ok' => false, 'code' => 'busy', 'version' => (int) ( self::state()['version'] ?? 0 ), 'source_updated_at' => 0 ); }
		if ( function_exists( 'set_transient' ) ) { set_transient( self::LOCK, 1, 30 ); }
		try {
			$state = self::state();
			$prev  = (int) ( $state['version'] ?? 0 );
			$built = self::build( $prev + 1 );
			$hash  = self::fingerprint( $built['bundle'] );
			$same  = ( $state['hash'] ?? '' ) === $hash && $prev > 0;
			$version = $same ? $prev : $prev + 1;
			$source_at = (int) ( $state['source_at'] ?? $state['at'] ?? 0 );
			if ( ! $same ) {
				$source_at = self::now();
				self::save_state( array_merge( $state, array(
					'version' => $version, 'hash' => $hash, 'ok' => true, 'at' => self::now(), 'source_at' => $source_at, 'code' => 'pulled',
					'accounts' => count( $built['bundle']['accounts'] ), 'agents' => count( $built['bundle']['agents'] ), 'warnings' => array_slice( $built['warnings'], 0, 30 ), 'attempts' => 0, 'hybrid_accounts' => self::hybrid_accounts( $built['bundle'] ),
				) ) );
			}
			$out = array( 'ok' => true, 'code' => $version <= $have_version ? 'unchanged' : 'ok', 'version' => $version, 'source_updated_at' => $source_at );
			if ( $version > $have_version ) {
				$bundle = $built['bundle'];
				$bundle['version'] = $version;
				$out['config_bundle'] = $bundle;
			}
			return $out;
		} finally {
			if ( function_exists( 'delete_transient' ) ) { delete_transient( self::LOCK ); }
		}
	}

	/** Numbers set to "Chỉ gợi ý" in Bot Studio that were sent as manual (C0.1). */
	private static function hybrid_accounts( array $bundle ): array {
		$out = array();
		foreach ( (array) ( $bundle['accounts'] ?? array() ) as $a ) {
			if ( 'hybrid' === ( $a['mode_source'] ?? '' ) ) { $out[] = (string) $a['account_id']; }
		}
		return $out;
	}

	private static function send( array $bundle ): array {
		if ( isset( self::$readers['sender'] ) ) { return (array) call_user_func( self::$readers['sender'], $bundle ); }
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast() ) {
			return array( 'success' => false, 'code' => 'api_key_missing' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->post_managed_path( '/zalo-personal-bridge/config', array( 'config_bundle' => $bundle ) );
	}

	/* ================================================================
	 *  State / readers
	 * ================================================================ */

	public static function state(): array {
		$s = get_option( self::STATE_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function save_state( array $state ): void { update_option( self::STATE_OPTION, $state, false ); }

	/** For the UI: last result + what would be sent now (counts only, never persona text). */
	public static function status(): array {
		$s = self::state();
		return array(
			'has_accounts' => self::has_accounts(),
			'version'      => (int) ( $s['version'] ?? 0 ),
			'ok'           => ! empty( $s['ok'] ),
			'code'         => (string) ( $s['code'] ?? 'never_synced' ),
			'at'           => (int) ( $s['at'] ?? 0 ),
			'accounts'     => (int) ( $s['accounts'] ?? 0 ),
			'agents'       => (int) ( $s['agents'] ?? 0 ),
			'ignored'      => (array) ( $s['ignored'] ?? array() ),
			'warnings'     => (array) ( $s['warnings'] ?? array() ),
			'attempts'     => (int) ( $s['attempts'] ?? 0 ),
			// [2026-09-27 Claude Opus 5.5] PHASE-0.81 C0.1/C0.4 — for the Bot Studio notice (C3.2).
			'contract'     => self::contract(),
			'hybrid_accounts' => array_values( array_map( 'strval', (array) ( $s['hybrid_accounts'] ?? array() ) ) ),
			'not_applied'  => self::not_applied(),
		);
	}

	private static function binding( string $bridge_id ): ?array {
		if ( isset( self::$readers['binding'] ) ) { $b = call_user_func( self::$readers['binding'], $bridge_id ); return is_array( $b ) ? $b : null; }
		return class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( self::PLATFORM, $bridge_id ) : null;
	}

	private static function character( int $id ) {
		return class_exists( 'BizCity_Knowledge_Database' ) ? BizCity_Knowledge_Database::instance()->get_character( $id ) : null;
	}

	/** Quick FAQ rows of a Guru: `bizcity_knowledge_sources` where source_type = 'quick_faq', content = {"title","content"}. */
	private static function faq( int $character_id ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return array(); }
		$table = $wpdb->prefix . 'bizcity_knowledge_sources';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT content FROM `{$table}` WHERE character_id = %d AND source_type = 'quick_faq' AND status = 'ready' ORDER BY id ASC LIMIT %d", $character_id, self::FAQ_MAX ), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$c = json_decode( (string) ( $r['content'] ?? '' ), true );
			if ( is_array( $c ) ) { $out[] = array( 'q' => (string) ( $c['title'] ?? '' ), 'a' => (string) ( $c['content'] ?? '' ) ); }
		}
		return $out;
	}

	private static function decode( $raw ): array {
		if ( is_array( $raw ) ) { return $raw; }
		$d = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $d ) ? $d : array();
	}

	private static function now(): int { return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time(); }
}
