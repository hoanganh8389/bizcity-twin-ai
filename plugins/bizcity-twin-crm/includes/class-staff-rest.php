<?php
/**
 * BizCity CRM — Staff management REST controller (PHASE-0.48F F6).
 *
 * Namespace: bizcity-crm/v1
 *
 * Every route here mutates or reveals data about another `user_id` and MUST
 * gate through {@see BizCity_CRM_Staff_Policy::can()} before doing anything
 * else (R-CRMF-2). None of these routes create a new table — they compose
 * `bizcity_crm_team_members` / `bizcity_crm_inbox_members` (already installed
 * by {@see BizCity_CRM_DB_Installer_V2}), `bizcity_zalo_accounts`
 * (bizcity-zalo-personal), WordPress users/roles and the existing
 * `BizCity_CRM_Repository`/`BizCity_CRM_Audit_Log` owners.
 *
 * Routes:
 *   GET    /crm-staff                        — visible roster for the actor
 *   POST   /crm-staff/lookup                 — email → exists_in_site|exists_in_network|new
 *   POST   /crm-staff                        — create/attach a staff account
 *   PATCH  /crm-staff/{user_id}              — change team role
 *   POST   /crm-staff/{user_id}/suspend      — deactivate, transferring work first
 *   POST   /crm-staff/{user_id}/reactivate   — restore team membership only
 *   POST   /crm-staff/{user_id}/password-reset
 *   GET    /crm-staff/{user_id}/workspace    — bounded projection for "Không gian làm việc"
 *   DELETE /inboxes/{id}/members/{user_id}   — F-UID-03b (was create-only)
 *
 * See docs/PHASE-0.48F-CRM-MANAGER-INBOX-TEAM-COMMAND-CENTER-RESEARCH.md §4B.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.48F 2026-09-17
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Staff_REST' ) ) { return; }

final class BizCity_CRM_Staff_REST {

	const META_STATUS = 'bizcity_crm_staff_status';
	const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;
	const RATE_LIMIT_MAX    = 20;
	const IDEMPOTENCY_TTL   = 10 * MINUTE_IN_SECONDS;
	// PHASE-0.56 G-1/G-2 — usermeta on the LEADER (not a table row, no bizcity_crm_tasks-style entity):
	// timestamp of the last "Gửi hướng dẫn /gpt/" invite send, read by the onboarding checklist's
	// `invite` step and written by the (not yet built) `POST /crm-staff/onboarding/invite` (G-2).
	const META_ONBOARDING_INVITED_AT = 'bizcity_crm_onboarding_invited_at';

	// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F D3 — team dashboard
	// thresholds, decided by the user on the mockup review (2026-09-17): a
	// customer waiting past this many minutes is flagged; an employee with no
	// outbound message for this many minutes (while holding open conversations)
	// is flagged; a rate/percentage is only shown once the sample is at least
	// this large, otherwise the UI must say "ít dữ liệu" rather than a
	// misleading 0%/100%.
	const WAIT_MINUTES_BREACH  = 15;
	const SILENT_MINUTES_ALERT = 120;
	const MIN_SAMPLE_FOR_RATE  = 10;

	public static function register_routes(): void {
		$ns = BIZCITY_CRM_REST_NS;

		register_rest_route( $ns, '/crm-staff', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_roster' ),
				'permission_callback' => array( __CLASS__, 'can_use_crm' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_staff' ),
				'permission_callback' => array( __CLASS__, 'can_use_crm' ),
			),
		) );

		register_rest_route( $ns, '/crm-staff/lookup', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'lookup_email' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'update_staff' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		// [2026-09-19 Johnny Chu] PHASE-0.56 T-3 (11.B2 tree view "Chuyển nhóm") — a dedicated route
		// rather than overloading `PATCH /crm-staff/{id}` (`update_staff()`, which only ever changes
		// `team_role` WITHIN the subject's existing team): moving teams has its own, stricter gate
		// (effectively admin-only, see `move_staff_team()`) and its own audit entity action.
		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)/move-team', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'move_staff_team' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)/suspend', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'suspend_staff' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)/reactivate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reactivate_staff' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)/password-reset', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'password_reset' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)/workspace', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_workspace' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		// [2026-09-19 Johnny Chu] PHASE-0.56 C-7 (D56-2) — generate/inspect a Zalo Bot link code
		// addressed to a NAMED employee, gated by `staff.link_bot` (supervisor+ within the same team,
		// C-2) instead of the self-only `/gpt/` `POST /twin-gpt/zalo-bot/link` flow, which is untouched.
		register_rest_route( $ns, '/crm-staff/zalo-bots', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_zalo_bots' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)/zalo-bot-link', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_staff_bot_link' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		register_rest_route( $ns, '/crm-staff/(?P<id>\d+)/zalo-bot-link/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'staff_bot_link_status' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		// [2026-09-19] PHASE-0.56 G-1 (11.B2 "Hướng dẫn 2 lớp") — the leader's 7-step onboarding checklist
		// (§4.8): server-computed, not a browser-stored flag, so it stays correct across devices/reloads.
		register_rest_route( $ns, '/crm-staff/onboarding', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_onboarding' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		// PHASE-0.56 G-2 — records the `invite` onboarding step and, optionally, actually sends the
		// `/gpt/` invite text to every staff member's linked Zalo Bot.
		register_rest_route( $ns, '/crm-staff/onboarding/invite', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_onboarding_invite' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F F-UID-03b — the
		// companion DELETE for the create-only `POST /inboxes/{id}/members`
		// registered in class-rest-controller.php.
		register_rest_route( $ns, '/inboxes/(?P<id>\d+)/members/(?P<user_id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'remove_inbox_member' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F F1 (S2 slice) —
		// QR login for a Zalo Personal phone identified by its CRM `inbox_id`
		// (not the bridge's raw account id), gated by `BizCity_CRM_Staff_Policy`
		// instead of `bizcity-zalo-personal`'s own `can_manage()`
		// (`manage_options`-only) — a supervisor may relogin their team's
		// phones, an agent their own (§4B.2, R-CRMF-2).
		register_rest_route( $ns, '/crm-phones/(?P<inbox_id>\d+)/qr', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'start_phone_qr' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		register_rest_route( $ns, '/crm-phones/(?P<inbox_id>\d+)/qr-reset', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reset_phone_qr' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		register_rest_route( $ns, '/crm-phones/(?P<inbox_id>\d+)/qr-status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'phone_qr_status' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		register_rest_route( $ns, '/crm-phones/(?P<inbox_id>\d+)/ping', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'ping_phone' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N2 (E4-03/G3) — create a Zalo Personal
		// number for an explicit owner, QR right after. `owner_user_id` in the body is a selector;
		// server re-derives authority (self = anyone assignable, someone else = administrator only, D2).
		register_rest_route( $ns, '/crm-phones', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_phone_for_owner' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		// PHASE-0.48F T3-03 — transfer one phone without suspending its owner (R-ZP-OWNER).
		register_rest_route( $ns, '/crm-phones/(?P<inbox_id>\d+)/transfer-owner', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'transfer_phone_owner' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		// PHASE-0.53 N5 (S6/G6) — re-provision an `account_not_owned` phone, adopting the same inbox.
		register_rest_route( $ns, '/crm-phones/(?P<inbox_id>\d+)/recover', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'recover_phone' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		// PHASE-0.53 N4 (S3) — rename a phone's display label; same `phone.assign` gate as transfer.
		// PHASE-0.53 N5 (S5) — remove (disconnect) a phone; same `phone.assign` gate.
		register_rest_route( $ns, '/crm-phones/(?P<inbox_id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_phone' ),
				'permission_callback' => array( __CLASS__, 'can_use_crm' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_phone' ),
				'permission_callback' => array( __CLASS__, 'can_use_crm' ),
			),
		) );
		register_rest_route( $ns, '/reports/team-inbox/member/(?P<user_id>\d+)/portfolio', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_team_member_portfolio' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );

		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F F4 — team command
		// center. Compose-on-read (R-CRMX-10): no rollup table, straight SQL
		// over `bizcity_crm_conversations`/`bizcity_crm_messages` (already
		// installed) plus `wc_get_orders()` for the Woo-owned revenue side.
		register_rest_route( $ns, '/reports/team-inbox', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_team_inbox_dashboard' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		register_rest_route( $ns, '/reports/team-inbox/ask', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'ask_team_inbox' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		register_rest_route( $ns, '/reports/team-inbox/member/(?P<user_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_team_inbox_member' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
	}

	/**
	 * PHASE-0.56 I-2 — answer one of three fixed aggregate-only Team Ops questions.
	 *
	 * The question key is allowlisted and the prompt is built from the same
	 * team-ops-board aggregate response used by the UI. No message body, customer
	 * name, customer phone, provider id or channel credential is sent to the LLM.
	 */
	public static function ask_team_inbox( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'team.dashboard' );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$question_key = sanitize_key( (string) $req->get_param( 'question_key' ) );
		$questions = array(
			'overload' => 'Ai đang quá tải và nên chia bớt việc?',
			'falling_behind' => 'Kênh nào đang có nguy cơ chậm phản hồi?',
			'today_summary' => 'Hôm nay đội đang có điều gì cần trưởng nhóm xử lý?',
		);
		if ( ! isset( $questions[ $question_key ] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Câu hỏi không được hỗ trợ.', 'hint' => 'Chọn một trong ba câu hỏi có sẵn.', 'help_code' => 'invalid_param_generic' ), 400 );
		}

		$range = sanitize_key( (string) ( $req->get_param( 'range' ) ?: 'today' ) );
		if ( ! in_array( $range, array( 'today', '7d', '30d' ), true ) ) { $range = 'today'; }
		$team_id = max( 0, (int) $req->get_param( 'team_id' ) );
		$cache_key = 'team_ask_' . (int) get_current_blog_id() . '_' . $actor_id . '_' . $range . '_' . $team_id . '_' . $question_key;
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['answer'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'answer' => (string) $cached['answer'], 'cached' => true, 'question_key' => $question_key ), 200 );
		}

		$dashboard_request = new WP_REST_Request( 'GET', '/' . BIZCITY_CRM_REST_NS . '/reports/team-inbox' );
		$dashboard_request->set_param( 'range', $range );
		if ( $team_id > 0 ) { $dashboard_request->set_param( 'team_id', $team_id ); }
		$dashboard_response = self::get_team_inbox_dashboard( $dashboard_request );
		$dashboard = $dashboard_response instanceof WP_REST_Response ? $dashboard_response->get_data() : array();
		if ( empty( $dashboard['ok'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'team_data_unavailable', 'message' => 'Chưa tải được số liệu đội.', 'hint' => 'Làm mới bảng đội rồi thử lại.', 'help_code' => 'team_data_unavailable' ), 200 );
		}

		$aggregate = array(
			'range' => $dashboard['range'] ?? $range,
			'kpis' => $dashboard['kpis'] ?? array(),
			'insights' => $dashboard['insights'] ?? array(),
			'staff' => array_map( static function ( $row ) {
				return array(
					'user_id' => (int) ( $row['user_id'] ?? 0 ),
					'name' => (string) ( $row['display_name'] ?? '' ),
					'open' => (int) ( $row['open'] ?? 0 ),
					'breach' => (int) ( $row['breach'] ?? 0 ),
					'frt_minutes' => $row['frt_minutes'] ?? null,
					'reply_rate' => $row['reply_rate'] ?? null,
					'zalo_bot' => $row['zalo_bot'] ?? null,
				);
			}, (array) ( $dashboard['employees'] ?? array() ) ),
		);
		$llm_payload = wp_json_encode( $aggregate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! class_exists( 'BizCity_LLM_Client' ) || ! BizCity_LLM_Client::instance()->is_ready() ) {
			return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'code' => 'gateway_not_ready', 'message' => 'Trợ lý Twin chưa sẵn sàng.', 'hint' => 'Kết nối gói BizCity rồi thử lại.', 'help_code' => 'gateway_not_ready' ), 200 );
		}

		try {
			$result = BizCity_LLM_Client::instance()->chat(
				array(
					array( 'role' => 'system', 'content' => 'Bạn là trợ lý vận hành CRM. Chỉ dùng số liệu tổng hợp được cung cấp. Không suy đoán, không nhắc đến dữ liệu khách, số điện thoại hay nội dung tin. Trả lời tiếng Việt, tối đa 3 câu và nêu một việc trưởng nhóm nên làm.' ),
					array( 'role' => 'user', 'content' => $questions[ $question_key ] . "\nDữ liệu team-ops-board:\n" . $llm_payload ),
				),
				array( 'purpose' => 'crm_team_assistant', 'max_tokens' => 300, 'temperature' => 0.2 )
			);
		} catch ( Throwable $e ) {
			return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'code' => 'assistant_unavailable', 'message' => 'Trợ lý Twin tạm thời không trả lời được.', 'hint' => 'Dùng các gợi ý vận hành trên bảng đội hoặc thử lại sau.', 'help_code' => 'assistant_unavailable' ), 200 );
		}
		if ( empty( $result['success'] ) || '' === trim( (string) ( $result['message'] ?? '' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'code' => 'assistant_unavailable', 'message' => 'Trợ lý Twin tạm thời không trả lời được.', 'hint' => 'Dùng các gợi ý vận hành trên bảng đội hoặc thử lại sau.', 'help_code' => 'assistant_unavailable' ), 200 );
		}
		$answer = wp_strip_all_tags( (string) $result['message'] );
		set_transient( $cache_key, array( 'answer' => $answer ), 5 * MINUTE_IN_SECONDS );
		return new WP_REST_Response( array( 'ok' => true, 'answer' => $answer, 'cached' => false, 'question_key' => $question_key ), 200 );
	}

	/**
	 * Base gate for every route in this file: must at least be able to use the
	 * CRM Inbox at all (agent or above). The fine-grained rank/team/self check
	 * for the specific action + subject happens per-callback via
	 * `BizCity_CRM_Staff_Policy::can()` — this is only the "logged in and not a
	 * random subscriber" floor.
	 */
	/**
	 * [2026-09-19] PHASE-0.60 C60-A05 — delegates to the canonical `crm.inbox.read`
	 * action (see `class-leader-member-rest.php::can_use_crm()` for the same fix).
	 */
	public static function can_use_crm(): bool {
		return class_exists( 'BizCity_CRM_Authority' )
			? BizCity_CRM_Authority::can( 'crm.inbox.read' )['ok']
			: is_user_logged_in();
	}

	// ── GET /crm-staff ──────────────────────────────────────────────────

	public static function get_roster( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$team_filter = max( 0, (int) $req->get_param( 'team_id' ) );
		$q = sanitize_text_field( (string) ( $req->get_param( 'q' ) ?? '' ) );

		$visible = BizCity_CRM_Staff_Policy::visible_user_ids( $actor_id );
		$rows = array();
		if ( null === $visible ) {
			// Administrator — tenant-wide, CRM staff only (P-U-6).
			$rows = self::admin_staff_user_ids();
		} else {
			$rows = $visible;
		}

		$team_names = self::team_names();
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F — a non-admin only
		// ever needs their own team in a filter dropdown; the roster stays
		// team-scoped (R-CRMF-8), so the accompanying team catalog does too —
		// admin still gets every team.
		$teams_out = array();
		if ( null === $visible ) {
			foreach ( $team_names as $id => $name ) { $teams_out[] = array( 'id' => $id, 'name' => $name ); }
		} else {
			$own_team = BizCity_CRM_Staff_Policy::primary_team( $actor_id );
			if ( $own_team && isset( $team_names[ $own_team ] ) ) { $teams_out[] = array( 'id' => $own_team, 'name' => $team_names[ $own_team ] ); }
		}
		$out = array();
		foreach ( $rows as $user_id ) {
			if ( $team_filter > 0 && BizCity_CRM_Staff_Policy::primary_team( $user_id ) !== $team_filter ) { continue; }
			$shaped = self::shape_staff_row( $user_id, $team_names );
			if ( ! $shaped ) { continue; }
			if ( '' !== $q ) {
				$haystack = strtolower( $shaped['display_name'] . ' ' . $shaped['email'] . ' #' . $shaped['user_id'] );
				if ( false === strpos( $haystack, strtolower( $q ) ) ) { continue; }
			}
			$out[] = $shaped;
		}
		usort( $out, static function ( $a, $b ) {
			return ( $b['rank'] <=> $a['rank'] ) ?: strcasecmp( $a['display_name'], $b['display_name'] );
		} );
		// PHASE-0.50 (mockup) — `with` takes a comma list, e.g. `with=customers,zalo_bot`.
		$with = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', (string) ( $req->get_param( 'with' ) ?? '' ) ) ) ) );
		if ( in_array( 'customers', $with, true ) ) {
			$out = self::attach_customer_counts( $out );
		}
		if ( in_array( 'zalo_bot', $with, true ) ) {
			$out = self::attach_zalo_bot_linked( $out );
		}

		return new WP_REST_Response( array( 'ok' => true, 'staff' => array_values( $out ), 'actor' => self::shape_staff_row( $actor_id, $team_names ), 'teams' => $teams_out ), 200 );
	}

	/**
	 * PHASE-0.50 C-05 (R-LM-8) — `?with=zalo_bot`: whether each employee has a Zalo Bot bound to their
	 * `user_id` (either bind path — see `BizCity_Channel_User_Linker::zalo_bot_target_for_user()`).
	 * Boolean only: the chat id itself never leaves the server (R-LM-8.5).
	 */
	private static function attach_zalo_bot_linked( array $rows ): array {
		if ( empty( $rows ) || ! class_exists( 'BizCity_Channel_User_Linker' ) || ! method_exists( 'BizCity_Channel_User_Linker', 'zalo_bot_target_for_user' ) ) {
			foreach ( $rows as &$row ) { $row['zalo_bot_linked'] = null; }
			unset( $row );
			return $rows;
		}
		foreach ( $rows as &$row ) {
			$row['zalo_bot_linked'] = ! empty( BizCity_Channel_User_Linker::zalo_bot_target_for_user( (int) $row['user_id'] ) );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * PHASE-0.50 W1 (mockup) — `?with=customers`: distinct customers per employee (their own inbox
	 * scope, same set as the W1 `owner` filter) and per Zalo Personal phone. Counts only, no ids.
	 */
	private static function attach_customer_counts( array $rows ): array {
		global $wpdb;
		if ( empty( $rows ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return $rows; }
		$ci_t = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$phone_inbox_ids = array();
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['phones'] ?? array() ) as $phone ) {
				if ( (int) $phone['inbox_id'] > 0 ) { $phone_inbox_ids[] = (int) $phone['inbox_id']; }
			}
		}
		$per_inbox = array();
		$phone_inbox_ids = array_values( array_unique( $phone_inbox_ids ) );
		if ( ! empty( $phone_inbox_ids ) ) {
			$ph = implode( ',', array_fill( 0, count( $phone_inbox_ids ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT inbox_id, COUNT(DISTINCT contact_id) AS n FROM `{$ci_t}` WHERE inbox_id IN ({$ph}) GROUP BY inbox_id", $phone_inbox_ids ), ARRAY_A ) as $r ) {
				$per_inbox[ (int) $r['inbox_id'] ] = (int) $r['n'];
			}
		}
		foreach ( $rows as &$row ) {
			$inbox_ids = self::subject_inbox_ids( (int) $row['user_id'] );
			$row['customers'] = 0;
			if ( ! empty( $inbox_ids ) ) {
				$ph = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
				$row['customers'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contact_id) FROM `{$ci_t}` WHERE inbox_id IN ({$ph})", $inbox_ids ) );
			}
			foreach ( $row['phones'] as &$phone ) {
				$phone['customers'] = $per_inbox[ (int) $phone['inbox_id'] ] ?? 0;
			}
			unset( $phone );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * PHASE-0.48F P-U-6 — tenant-wide staff list for an administrator: users with a CRM role
	 * (team membership or `bizcity_crm_handle_inbox`), plus site administrators. Customers who
	 * registered a WordPress account (role `none`) never appear as staff. Suspended staff keep
	 * showing in the roster via their last membership lookup being inactive — role() returns none
	 * for them, so they are re-added from user meta.
	 *
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N1 — made public so
	 * `BizCity_CRM_REST_Controller::get_crm_inbox_user_groups()` can reuse the
	 * same D1 roster for `?include=staff` instead of re-deriving it.
	 *
	 * @return array<int,int>
	 */
	public static function admin_staff_user_ids(): array {
		$candidates = get_users( array( 'role__not_in' => array( 'subscriber' ), 'number' => 500, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID' ) ) );
		$out = array();
		foreach ( $candidates as $u ) {
			$uid = (int) $u->ID;
			// [2026-09-21 06:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.60-C02 — include network/site administrators in the CRM roster when a multisite blog has no local role row.
			$is_tenant_admin = ( function_exists( 'is_super_admin' ) && is_super_admin( $uid ) ) || user_can( $uid, 'manage_options' ) || user_can( $uid, 'manage_network' );
			if ( $is_tenant_admin || BizCity_CRM_Staff_Policy::ROLE_NONE !== BizCity_CRM_Staff_Policy::role( $uid ) || 'suspended' === get_user_meta( $uid, self::META_STATUS, true ) ) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	/**
	 * `team_id => name` for every active team. `BizCity_CRM_Team_Manager::list_teams()`
	 * has no capability check of its own — only the `GET /teams` REST route
	 * does (`can_manage_teams()`, admin-only since D6) — so calling the
	 * data-layer method directly here lets a supervisor/lead's roster show a
	 * team name without needing that admin-only route.
	 *
	 * @return array<int,string>
	 */
	private static function team_names(): array {
		if ( ! class_exists( 'BizCity_CRM_Team_Manager' ) ) { return array(); }
		$out = array();
		foreach ( BizCity_CRM_Team_Manager::list_teams() as $team ) {
			$out[ (int) ( $team['id'] ?? 0 ) ] = sanitize_text_field( (string) ( $team['name'] ?? '' ) );
		}
		return $out;
	}

	/**
	 * Bounded staff row — team role, phone count/session summary, suspend
	 * status. Session state is read straight off the already-synced local
	 * `bizcity_zalo_accounts.status` column (no per-account bridge call), so a
	 * roster of 30 stays cheap; see PHASE-0.48F §1.1/S2.
	 */
	private static function shape_staff_row( int $user_id, array $team_names = array() ): ?array {
		if ( ! BizCity_CRM_Staff_Policy::is_assignable_user( $user_id ) ) { return null; }
		$user = get_userdata( $user_id );
		if ( ! $user ) { return null; }
		$role = BizCity_CRM_Staff_Policy::role( $user_id );
		$phones = array();
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id ) as $account ) {
				$phones[] = array_merge( array(
					'id'       => (int) ( $account['id'] ?? 0 ),
					'inbox_id' => (int) ( $account['crm_inbox_id'] ?? 0 ),
					'label'    => sanitize_text_field( (string) ( $account['label'] ?? '' ) ),
				), self::phone_state( $account ) );
			}
		}
		$dead_count = 0;
		foreach ( $phones as $p ) { if ( ! empty( $p['dead'] ) ) { $dead_count++; } }
		$team_id = BizCity_CRM_Staff_Policy::primary_team( $user_id );
		return array(
			'user_id'      => $user_id,
			'display_name' => (string) $user->display_name,
			'email'        => (string) $user->user_email,
			'role'         => $role,
			'rank'         => BizCity_CRM_Staff_Policy::rank( $role ),
			'team_id'      => $team_id,
			'team_name'    => $team_id ? ( $team_names[ $team_id ] ?? '' ) : '',
			'suspended'    => 'suspended' === get_user_meta( $user_id, self::META_STATUS, true ),
			'phones'       => $phones,
			'phones_dead'  => $dead_count,
		);
	}

	// ── POST /crm-staff/lookup ──────────────────────────────────────────

	public static function lookup_email( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.create' );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$email = sanitize_email( strtolower( trim( (string) $req->get_param( 'email' ) ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Email chưa đúng định dạng.', 'hint' => 'Nhập email dạng ten@congty.vn.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$existing = get_user_by( 'email', $email );
		if ( ! $existing ) {
			return new WP_REST_Response( array( 'ok' => true, 'status' => 'new', 'email' => $email ), 200 );
		}
		$in_site = ! function_exists( 'is_user_member_of_blog' ) || is_user_member_of_blog( (int) $existing->ID, get_current_blog_id() );
		return new WP_REST_Response( array(
			'ok'           => true,
			'status'       => $in_site ? 'exists_in_site' : 'exists_in_network',
			'email'        => $email,
			'user_id'      => (int) $existing->ID,
			'display_name' => (string) $existing->display_name,
		), 200 );
	}

	// ── POST /crm-staff ─────────────────────────────────────────────────

	public static function create_staff( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.create' );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$client_request_id = sanitize_text_field( (string) ( $req->get_param( 'client_request_id' ) ?? '' ) );
		if ( '' !== $client_request_id ) {
			$idem_key = 'bzc_crm_staff_idem_' . $actor_id . '_' . md5( $client_request_id );
			$cached = get_transient( $idem_key );
			if ( false !== $cached ) { return new WP_REST_Response( $cached, 200 ); }
		}

		$rl_key = 'bzc_crm_staff_rl_' . $actor_id;
		$rl_count = (int) get_transient( $rl_key );
		if ( $rl_count >= self::RATE_LIMIT_MAX ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'rate_limited', 'message' => 'Đã tạo quá nhiều nhân viên trong 1 giờ.', 'hint' => 'Thử lại sau ít phút.', 'help_code' => 'invalid_param_generic' ), 429 );
		}

		$email = sanitize_email( strtolower( trim( (string) $req->get_param( 'email' ) ) ) );
		$display_name = sanitize_text_field( (string) ( $req->get_param( 'display_name' ) ?? '' ) );
		$team_id = max( 0, (int) $req->get_param( 'team_id' ) );
		$team_role = sanitize_key( (string) ( $req->get_param( 'team_role' ) ?? 'agent' ) );
		if ( ! in_array( $team_role, array( 'agent', 'lead', 'supervisor' ), true ) ) { $team_role = 'agent'; }

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Email chưa đúng định dạng.', 'hint' => 'Nhập email dạng ten@congty.vn.', 'help_code' => 'invalid_param_generic' ), 400 );
		}

		$actor_role = BizCity_CRM_Staff_Policy::role( $actor_id );
		$actor_team = BizCity_CRM_Staff_Policy::primary_team( $actor_id );
		if ( BizCity_CRM_Staff_Policy::ROLE_ADMIN !== $actor_role ) {
			// Non-admin: can only staff their own team, with a strictly lower rank than themselves.
			if ( null === $actor_team ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'different_team', 'message' => 'Bạn chưa thuộc team nào để thêm nhân viên.', 'hint' => 'Nhờ Admin gán bạn vào một team trước.', 'help_code' => 'member_not_manageable' ), 403 );
			}
			$team_id = $actor_team; // ignore a posted team_id from a non-admin; their own team is the only valid target.
			if ( BizCity_CRM_Staff_Policy::rank( $team_role ) >= BizCity_CRM_Staff_Policy::rank( $actor_role ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'subject_not_lower_rank', 'message' => 'Bạn không thể tạo nhân viên cùng cấp hoặc cao hơn mình.', 'hint' => 'Chọn vai trò thấp hơn ' . BizCity_CRM_Staff_Policy::label( $actor_role ) . '.', 'help_code' => 'member_not_manageable' ), 403 );
			}
		} elseif ( $team_id <= 0 ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chọn team cho nhân viên.', 'hint' => 'Chọn một team trong danh sách.', 'help_code' => 'invalid_param_generic' ), 400 );
		}

		$existing = get_user_by( 'email', $email );
		$status = 'created';
		if ( $existing ) {
			$subject_id = (int) $existing->ID;
			$subject_role = BizCity_CRM_Staff_Policy::role( $subject_id );
			// Onboarding an existing user into a team is a "join", not a
			// "manage an existing member" action, so `can()`'s team-compare
			// branch (built for an already-teamed subject) does not apply here.
			// The rank-below-actor rule above already bounds what role they can
			// receive; a user who already outranks the actor is refused instead.
			if ( 'none' !== $subject_role && BizCity_CRM_Staff_Policy::rank( $subject_role ) >= BizCity_CRM_Staff_Policy::rank( $actor_role ) && BizCity_CRM_Staff_Policy::ROLE_ADMIN !== $actor_role ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'subject_not_lower_rank', 'message' => 'Người này đã có vai trò cùng cấp hoặc cao hơn bạn.', 'hint' => 'Nhờ Admin xử lý trường hợp này.', 'help_code' => 'member_not_manageable' ), 403 );
			}
			$in_site = ! function_exists( 'is_user_member_of_blog' ) || is_user_member_of_blog( $subject_id, get_current_blog_id() );
			if ( ! $in_site ) {
				if ( is_multisite() && function_exists( 'add_user_to_blog' ) ) {
					$added = add_user_to_blog( get_current_blog_id(), $subject_id, BizCity_CRM_Capabilities::ROLE_STAFF );
					if ( is_wp_error( $added ) ) {
						return new WP_REST_Response( array( 'ok' => false, 'code' => 'staff_create_not_allowed', 'message' => 'Không thể thêm tài khoản này vào site.', 'hint' => $added->get_error_message(), 'help_code' => 'invalid_param_generic' ), 400 );
					}
				}
				// Non-multisite: any existing WP user is already "of" the single site.
			}
			$status = 'added_existing';
		} else {
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F F-UID-01f — WP's
			// `registration`/`add_new_users` site options gate the public signup
			// form and the wp-admin "Add New User" screen, not a plugin calling
			// `wp_insert_user()`/`add_user_to_blog()` directly; they are not the
			// right switch to check here, so this path relies solely on the
			// Staff_Policy rank gate above. What remains **unverified** on a real
			// multisite network is whether an org's own network policy (a
			// `user_register`/`wpmu_validate_user_signup` filter, a plugin, or a
			// hard network cap on total users) rejects this — `wp_insert_user()`
			// below still fails closed (returns `WP_Error`) if so.
			$login = self::unique_login_from_email( $email );
			$subject_id = wp_insert_user( array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => '' !== $display_name ? $display_name : $login,
				'role'         => BizCity_CRM_Capabilities::ROLE_STAFF,
			) );
			if ( is_wp_error( $subject_id ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'staff_create_not_allowed', 'message' => 'Không tạo được tài khoản.', 'hint' => $subject_id->get_error_message(), 'help_code' => 'invalid_param_generic' ), 400 );
			}
			$subject_id = (int) $subject_id;
			if ( is_multisite() && function_exists( 'add_user_to_blog' ) ) {
				add_user_to_blog( get_current_blog_id(), $subject_id, BizCity_CRM_Capabilities::ROLE_STAFF );
			}
			wp_new_user_notification( $subject_id, null, 'user' );
		}

		BizCity_CRM_Team_Manager::add_team_member( $team_id, $subject_id, $team_role );

		set_transient( $rl_key, $rl_count + 1, self::RATE_LIMIT_WINDOW );
		$result = array( 'ok' => true, 'status' => $status, 'user_id' => $subject_id, 'team_id' => $team_id, 'team_role' => $team_role );
		if ( '' !== $client_request_id ) { set_transient( $idem_key, $result, self::IDEMPOTENCY_TTL ); }
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log_created( 'crm_staff', $subject_id, array( 'team_id' => $team_id, 'team_role' => $team_role, 'status' => $status ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( $result, 200 );
	}

	private static function unique_login_from_email( string $email ): string {
		$base = sanitize_user( strtolower( substr( $email, 0, strpos( $email, '@' ) ?: strlen( $email ) ) ), true );
		$base = '' !== $base ? $base : 'nv';
		$login = $base;
		$suffix = 1;
		while ( username_exists( $login ) ) {
			$suffix++;
			$login = $base . $suffix;
		}
		return $login;
	}

	// ── PATCH /crm-staff/{id} ───────────────────────────────────────────

	public static function update_staff( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$subject_id = (int) $req['id'];
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.update_role', $subject_id );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$team_role = sanitize_key( (string) ( $req->get_param( 'team_role' ) ?? '' ) );
		if ( ! in_array( $team_role, array( 'agent', 'lead', 'supervisor' ), true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Vai trò không hợp lệ.', 'hint' => 'Chọn agent, lead hoặc supervisor.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$actor_role = BizCity_CRM_Staff_Policy::role( $actor_id );
		if ( BizCity_CRM_Staff_Policy::ROLE_ADMIN !== $actor_role && BizCity_CRM_Staff_Policy::rank( $team_role ) >= BizCity_CRM_Staff_Policy::rank( $actor_role ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'subject_not_lower_rank', 'message' => 'Không thể đặt vai trò cùng cấp hoặc cao hơn bạn.', 'hint' => 'Chọn vai trò thấp hơn ' . BizCity_CRM_Staff_Policy::label( $actor_role ) . '.', 'help_code' => 'member_not_manageable' ), 403 );
		}
		$team_id = BizCity_CRM_Staff_Policy::primary_team( $subject_id );
		if ( null === $team_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Nhân viên chưa thuộc team nào.', 'hint' => 'Thêm nhân viên vào team trước.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$before = array( 'team_role' => BizCity_CRM_Staff_Policy::role( $subject_id ) );
		$ok = BizCity_CRM_Team_Manager::add_team_member( $team_id, $subject_id, $team_role );
		if ( ! $ok ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'update_failed', 'message' => 'Không cập nhật được vai trò.', 'hint' => 'Thử lại sau.', 'help_code' => 'invalid_param_generic' ), 500 );
		}
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log_updated( 'crm_staff', $subject_id, $before, array( 'team_role' => $team_role ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'user_id' => $subject_id, 'team_id' => $team_id, 'team_role' => $team_role ), 200 );
	}

	/**
	 * PHASE-0.56 T-3 — move an employee to a DIFFERENT team, keeping their team role. `Staff_Policy`
	 * has no dedicated "move team" action (its rank/team model assumes the actor's manageable set is
	 * scoped to their OWN `primary_team()`), so the ACL here is composed from two checks that already
	 * exist: (1) the actor manages `$subject_id` today, via the same `staff.update_role` gate
	 * `update_staff()` uses; (2) the actor can actually REACH the destination team. Because a
	 * non-admin's `primary_team()` is always their own single team, (2) can only ever be true for an
	 * administrator — a supervisor cannot "reach into" a team they do not manage to place someone
	 * there, matching R-CRMF-3's team-scoped design rather than adding a new bypass.
	 */
	public static function move_staff_team( WP_REST_Request $req ) {
		$actor_id   = get_current_user_id();
		$subject_id = (int) $req['id'];
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.update_role', $subject_id );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$from_team_id = BizCity_CRM_Staff_Policy::primary_team( $subject_id );
		if ( null === $from_team_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Nhân viên chưa thuộc nhóm nào.', 'hint' => 'Thêm nhân viên vào nhóm trước.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$to_team_id = max( 0, (int) $req->get_param( 'team_id' ) );
		if ( $to_team_id <= 0 || $to_team_id === $from_team_id ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chọn một nhóm khác nhóm hiện tại.', 'hint' => '', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		if ( BizCity_CRM_Staff_Policy::ROLE_ADMIN !== BizCity_CRM_Staff_Policy::role( $actor_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'different_team', 'message' => 'Chỉ quản trị site mới chuyển nhân viên sang nhóm khác.', 'hint' => 'Nhờ quản trị site thực hiện.', 'help_code' => 'member_not_manageable' ), 403 );
		}
		if ( ! class_exists( 'BizCity_CRM_Team_Manager' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Module team chưa sẵn sàng.', 'hint' => '', 'help_code' => 'module_not_loaded' ), 503 );
		}
		$team_exists = false;
		foreach ( BizCity_CRM_Team_Manager::list_teams() as $t ) {
			if ( (int) ( $t['id'] ?? 0 ) === $to_team_id ) { $team_exists = true; break; }
		}
		if ( ! $team_exists ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Không tìm thấy nhóm.', 'hint' => '', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$subject_role = BizCity_CRM_Staff_Policy::role( $subject_id );
		$before = array( 'team_id' => $from_team_id );
		$ok = BizCity_CRM_Team_Manager::move_team_member( $from_team_id, $to_team_id, $subject_id, $subject_role );
		if ( ! $ok ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'update_failed', 'message' => 'Không chuyển được nhóm.', 'hint' => 'Thử lại sau.', 'help_code' => 'invalid_param_generic' ), 500 );
		}
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log_updated( 'crm_staff', $subject_id, $before, array( 'team_id' => $to_team_id ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'user_id' => $subject_id, 'from_team_id' => $from_team_id, 'to_team_id' => $to_team_id ), 200 );
	}

	// ── POST /crm-staff/{id}/suspend ────────────────────────────────────

	public static function suspend_staff( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$subject_id = (int) $req['id'];
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.suspend', $subject_id );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$reassign_to = max( 0, (int) $req->get_param( 'reassign_to' ) );
		if ( $reassign_to <= 0 || $reassign_to === $subject_id || ! BizCity_CRM_Staff_Policy::is_assignable_user( $reassign_to ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chọn người nhận hội thoại/SĐT hợp lệ.', 'hint' => 'Chọn một nhân viên khác trong danh sách.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$actor_visible = BizCity_CRM_Staff_Policy::visible_user_ids( $actor_id );
		if ( is_array( $actor_visible ) && ! in_array( $reassign_to, $actor_visible, true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'different_team', 'message' => 'Người nhận phải thuộc team bạn quản lý.', 'hint' => 'Chọn một nhân viên trong team.', 'help_code' => 'member_not_manageable' ), 403 );
		}

		$phones_moved = 0;
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $subject_id ) as $account ) {
				BizCity_Zalo_Mapping_Repo::save_account( array(
					'kind'              => (string) ( $account['kind'] ?? 'personal' ),
					'owner_user_id'     => $reassign_to,
					'label'             => (string) ( $account['label'] ?? '' ),
					'bridge_account_id' => (string) ( $account['bridge_account_id'] ?? '' ),
					'zalo_uid'          => (string) ( $account['zalo_uid'] ?? '' ),
					'zalo_oa_id'        => (string) ( $account['zalo_oa_id'] ?? '' ),
					'crm_inbox_id'      => (int) ( $account['crm_inbox_id'] ?? 0 ),
					'status'            => (string) ( $account['status'] ?? 'pending_qr' ),
				) );
				$phones_moved++;
			}
		}

		$conversations_moved = 0;
		if ( class_exists( 'BizCity_CRM_Repository' ) ) {
			foreach ( (array) BizCity_CRM_Repository::list_conversations( array( 'assignee_id' => $subject_id, 'limit' => 200 ) ) as $conv ) {
				$conv_id = (int) ( $conv['id'] ?? 0 );
				if ( $conv_id <= 0 ) { continue; }
				if ( BizCity_CRM_Repository::set_conversation_assignee( $conv_id, $reassign_to, $actor_id, array( 'reason' => 'staff_suspended' ) ) ) {
					$conversations_moved++;
				}
			}
		}

		foreach ( BizCity_CRM_Team_Manager::list_user_memberships( $subject_id ) as $membership ) {
			BizCity_CRM_Team_Manager::remove_team_member( (int) $membership['team_id'], $subject_id );
		}
		foreach ( BizCity_CRM_Team_Manager::list_user_inbox_ids( $subject_id ) as $inbox_id ) {
			BizCity_CRM_Team_Manager::remove_inbox_member( $inbox_id, $subject_id );
		}
		update_user_meta( $subject_id, self::META_STATUS, 'suspended' );
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $subject_id )->destroy_all();
		}
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_staff', $subject_id, 'status_changed', null, array( 'status' => 'suspended', 'reassigned_to' => $reassign_to, 'phones_moved' => $phones_moved, 'conversations_moved' => $conversations_moved ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'user_id' => $subject_id, 'reassigned_to' => $reassign_to, 'phones_moved' => $phones_moved, 'conversations_moved' => $conversations_moved ), 200 );
	}

	// ── POST /crm-staff/{id}/reactivate ─────────────────────────────────

	public static function reactivate_staff( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$subject_id = (int) $req['id'];
		// `can()`'s rank/team compare needs the subject to already resolve a
		// role/team; a fully-suspended user has neither (every membership row
		// is inactive), so gate reactivate on rank alone plus explicit
		// "not self" — same threshold as suspend, matching the doc's shared
		// `staff.suspend` action key.
		$actor_role = BizCity_CRM_Staff_Policy::role( $actor_id );
		if ( $subject_id === $actor_id || BizCity_CRM_Staff_Policy::rank( $actor_role ) < BizCity_CRM_Staff_Policy::MIN_RANK['staff.suspend'] ) {
			return BizCity_CRM_Staff_Policy::denied_response( array( 'code' => 'rank_insufficient', 'why' => 'Bạn không có quyền thao tác này.' ) );
		}
		$last = BizCity_CRM_Team_Manager::find_last_membership( $subject_id );
		if ( ! $last ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy team trước đó của nhân viên.', 'hint' => 'Thêm nhân viên vào một team thay vì kích hoạt lại.', 'help_code' => 'not_found' ), 404 );
		}
		if ( BizCity_CRM_Staff_Policy::ROLE_ADMIN !== $actor_role ) {
			$actor_team = BizCity_CRM_Staff_Policy::primary_team( $actor_id );
			if ( null === $actor_team || $actor_team !== (int) $last['team_id'] ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'different_team', 'message' => 'Nhân viên này không thuộc team bạn quản lý.', 'hint' => 'Nhờ Admin xử lý.', 'help_code' => 'member_not_manageable' ), 403 );
			}
		}
		BizCity_CRM_Team_Manager::add_team_member( (int) $last['team_id'], $subject_id, (string) $last['member_role'] );
		delete_user_meta( $subject_id, self::META_STATUS );
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_staff', $subject_id, 'status_changed', null, array( 'status' => 'active' ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'user_id' => $subject_id, 'team_id' => (int) $last['team_id'], 'team_role' => (string) $last['member_role'] ), 200 );
	}

	// ── POST /crm-staff/{id}/password-reset ─────────────────────────────

	public static function password_reset( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$subject_id = (int) $req['id'];
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.update_role', $subject_id );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		if ( ! get_userdata( $subject_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy nhân viên.', 'hint' => '', 'help_code' => 'not_found' ), 404 );
		}
		wp_new_user_notification( $subject_id, null, 'user' );
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_staff', $subject_id, 'updated', null, array( 'action' => 'password_reset_sent' ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'user_id' => $subject_id ), 200 );
	}

	// ── GET /crm-staff/{id}/workspace ────────────────────────────────────

	public static function get_workspace( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$subject_id = (int) $req['id'];
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.view_workspace', $subject_id );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$subject = get_userdata( $subject_id );
		if ( ! $subject ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy nhân viên.', 'hint' => '', 'help_code' => 'not_found' ), 404 );
		}
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F §4B.6 — force the
		// SUBJECT's own scope (force_user_scope=true), never the actor's
		// admin-widened one, so this endpoint returns exactly what the subject
		// themselves can see — nothing more, nothing less.
		$scope = class_exists( 'BizCity_CRM_Inbox_Access' )
			? BizCity_CRM_Inbox_Access::resolve_scope( $subject_id, 'be', true )
			: array( 'inbox_ids' => array() );

		self::record_workspace_read( $actor_id, $subject_id, 'workspace' );

		$actor = get_userdata( $actor_id );
		$inbox_ids = array_values( array_map( 'intval', (array) ( $scope['inbox_ids'] ?? array() ) ) );
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F §4B.6 — phones (with
		// their own `crm_inbox_id`) let the FE rail show "which of the subject's
		// SĐT is this" instead of a bare list of numeric inbox ids; still bounded
		// to phones whose inbox is in the subject's own resolved scope above.
		$phones = array();
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $subject_id ) as $account ) {
				$inbox_id = (int) ( $account['crm_inbox_id'] ?? 0 );
				if ( ! in_array( $inbox_id, $inbox_ids, true ) ) { continue; }
				$phones[] = array_merge( array(
					'inbox_id' => $inbox_id,
					'label'    => sanitize_text_field( (string) ( $account['label'] ?? '' ) ),
				), self::phone_state( $account ) );
			}
		}
		return new WP_REST_Response( array(
			'ok'      => true,
			'subject' => array(
				'user_id'      => $subject_id,
				'display_name' => (string) $subject->display_name,
				'role'         => BizCity_CRM_Staff_Policy::role( $subject_id ),
			),
			'actor' => array(
				'user_id'      => $actor_id,
				'display_name' => $actor ? (string) $actor->display_name : '',
				'role'         => BizCity_CRM_Staff_Policy::role( $actor_id ),
			),
			'inbox_ids' => $inbox_ids,
			'phones'    => $phones,
		), 200 );
	}

	/**
	 * [2026-09-18] PHASE-0.48F F-UID-05 / T3-05 — read audit when a manager looks
	 * into an employee's workspace (`#/workspace/:userId` or a `scope_user_id`
	 * conversation list). One audit row per (actor, subject, site-day); later
	 * reads that day are free. No conversation ids, no customer data.
	 * Self-view is not audited (it is the subject's own workspace).
	 *
	 * @return bool true when a new row was written.
	 */
	public static function record_workspace_read( int $actor_id, int $subject_id, string $source ): bool {
		if ( $actor_id <= 0 || $subject_id <= 0 || $actor_id === $subject_id ) { return false; }
		if ( ! class_exists( 'BizCity_CRM_Audit_Log' ) ) { return false; }
		$day = function_exists( 'wp_date' ) ? wp_date( 'Ymd' ) : gmdate( 'Ymd' );
		$key = 'bzc_ws_read_' . $actor_id . '_' . $subject_id . '_' . $day;
		if ( false !== get_transient( $key ) ) { return false; }
		$written = BizCity_CRM_Audit_Log::log(
			'crm_staff',
			$subject_id,
			'workspace_viewed',
			null,
			array( 'source' => sanitize_key( $source ), 'day' => $day ),
			array( 'user_id' => $actor_id, 'event_uuid' => 'workspace_read:' . $actor_id . ':' . $subject_id . ':' . $day )
		);
		if ( false === $written ) { return false; }
		set_transient( $key, 1, DAY_IN_SECONDS );
		return true;
	}

	// ── POST /crm-phones/{inbox_id}/qr, qr-reset, GET qr-status ─────────

	/**
	 * Resolve the Zalo Personal account behind a CRM `inbox_id` and check
	 * `BizCity_CRM_Staff_Policy::can( actor, $action, owner_user_id )` before
	 * any caller here touches the bridge. Returns the account row (with
	 * `owner_user_id`) on success, or an already-built denial `WP_REST_Response`.
	 *
	 * @return array{account: array}|WP_REST_Response
	 */
	private static function resolve_authorized_phone( int $actor_id, int $inbox_id, string $action ) {
		if ( $inbox_id <= 0 || ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy SĐT này.', 'hint' => '', 'help_code' => 'not_found' ), 404 );
		}
		$account = BizCity_Zalo_Mapping_Repo::find_account_by_crm_inbox_id( $inbox_id );
		if ( ! is_array( $account ) || 'personal' !== (string) ( $account['kind'] ?? '' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy SĐT Zalo Cá nhân này.', 'hint' => '', 'help_code' => 'not_found' ), 404 );
		}
		$owner_id = (int) ( $account['owner_user_id'] ?? 0 );
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, $action, $owner_id );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		return array( 'account' => $account, 'owner_user_id' => $owner_id );
	}

	// ── POST /crm-phones ─────────────────────────────────────────────────

	/**
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N2 (E4-03/G3) — create a new Zalo Personal
	 * number for an explicit owner, QR right after in the same sheet (S1). D2 (2026-09-18, user-chosen):
	 * adding a number **for yourself** is open to any assignable user; adding one **for someone else**
	 * is administrator-only — `phone.add_for_other` is deliberately left out of `Staff_Policy::MIN_RANK`,
	 * so `can()` fails closed for every team rank the same way an undeclared action always does
	 * (matches how `task.assign` etc. fail closed for an unknown action).
	 */
	public static function create_phone_for_owner( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$body = $req->get_json_params();
		$owner_user_id = max( 0, (int) ( $body['owner_user_id'] ?? 0 ) );
		if ( $owner_user_id <= 0 ) { $owner_user_id = $actor_id; }
		if ( ! BizCity_CRM_Staff_Policy::is_assignable_user( $owner_user_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Người phụ trách không hợp lệ.', 'hint' => 'Chọn một nhân viên của website này.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		if ( $owner_user_id !== $actor_id ) {
			$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'phone.add_for_other', $owner_user_id );
			if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		}
		if ( 'suspended' === get_user_meta( $owner_user_id, self::META_STATUS, true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Nhân viên đang ngưng hoạt động.', 'hint' => 'Kích hoạt lại nhân viên rồi thử lại.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		if ( rest_sanitize_boolean( $body['dry_run'] ?? false ) ) {
			if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) || ! method_exists( 'BizCity_Zalo_Bridge_REST', 'preflight_create_account_for_owner' ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'dry_run' => true, 'code' => 'module_not_loaded', 'message' => 'Module Zalo Personal chưa sẵn sàng.', 'hint' => 'Bật Zalo Personal rồi thử lại.', 'help_code' => 'module_not_loaded' ), 503 );
			}
			$preflight = BizCity_Zalo_Bridge_REST::preflight_create_account_for_owner( $req, $owner_user_id, true );
			if ( $preflight instanceof WP_REST_Response ) {
				$data = $preflight->get_data();
				$data['dry_run'] = true;
				return new WP_REST_Response( $data, $preflight->get_status() );
			}
			return new WP_REST_Response( array( 'ok' => true, 'dry_run' => true, 'owner_user_id' => $owner_user_id, 'message' => 'Có thể tạo SĐT cho người phụ trách này.', 'hint' => 'Tiếp tục tạo rồi quét QR.', 'help_code' => 'crm_phone_ready' ), 200 );
		}
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Module Zalo Personal chưa sẵn sàng.', 'hint' => 'Bật Zalo Personal rồi thử lại.', 'help_code' => 'module_not_loaded' ), 503 );
		}
		// `true` = personal_only (this route never provisions a Zalo OA account, R-ZONE/R-TWEB-14).
		return BizCity_Zalo_Bridge_REST::create_account_for_owner( $req, $owner_user_id, true, $owner_user_id !== $actor_id, $actor_id );
	}

	public static function start_phone_qr( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$resolved = self::resolve_authorized_phone( $actor_id, (int) $req['inbox_id'], 'phone.qr' );
		if ( $resolved instanceof WP_REST_Response ) { return $resolved; }
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Module Zalo Personal chưa sẵn sàng.', 'hint' => '', 'help_code' => 'module_not_loaded' ), 503 );
		}
		// `authorized_by_caller=true` — `resolve_authorized_phone()` already
		// ran `Staff_Policy::can()` above; this is the exact "already resolved
		// owner row, skip the self-only check" contract those `_for_owner`
		// methods document.
		return BizCity_Zalo_Bridge_REST::start_qr_for_owner( $resolved['account'], $resolved['owner_user_id'], true );
	}

	public static function reset_phone_qr( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$resolved = self::resolve_authorized_phone( $actor_id, (int) $req['inbox_id'], 'phone.qr' );
		if ( $resolved instanceof WP_REST_Response ) { return $resolved; }
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Module Zalo Personal chưa sẵn sàng.', 'hint' => '', 'help_code' => 'module_not_loaded' ), 503 );
		}
		return BizCity_Zalo_Bridge_REST::reset_qr_for_owner( $resolved['account'], $resolved['owner_user_id'], true );
	}

	public static function phone_qr_status( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		// Reading status is bounded by the same `phone.qr` rank/team rule as
		// starting/resetting it — there is no separate lower-bar "just look" action;
		// an agent already passes for their own phone via SELF_MIN_RANK.
		$resolved = self::resolve_authorized_phone( $actor_id, (int) $req['inbox_id'], 'phone.qr' );
		if ( $resolved instanceof WP_REST_Response ) { return $resolved; }
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Module Zalo Personal chưa sẵn sàng.', 'hint' => '', 'help_code' => 'module_not_loaded' ), 503 );
		}
		return BizCity_Zalo_Bridge_REST::qr_status_for_owner( $resolved['account'], $resolved['owner_user_id'], true );
	}

	public static function ping_phone( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$inbox_id = (int) $req['inbox_id'];
		$resolved = self::resolve_authorized_phone( $actor_id, $inbox_id, 'phone.qr' );
		if ( $resolved instanceof WP_REST_Response ) { return $resolved; }
		$lock = 'bizcity_crm_phone_ping_' . get_current_blog_id() . '_' . $inbox_id;
		if ( get_transient( $lock ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'rate_limited', 'message' => 'Vừa kiểm tra SĐT này.', 'hint' => 'Đợi vài giây rồi thử lại.', 'help_code' => 'rate_limited' ), 429 );
		}
		set_transient( $lock, 1, 10 );
		$started = microtime( true );
		$status_response = self::phone_qr_status( $req );
		$status = $status_response->get_data();
		$readiness = is_array( $status ) ? (array) ( $status['readiness'] ?? array() ) : array();
		$last_inbound = null;
		global $wpdb;
		$messages = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(m.created_at) FROM {$messages} m INNER JOIN {$conv} c ON c.id = m.conversation_id WHERE c.inbox_id = %d AND m.message_type = 'incoming'", $inbox_id ) );
		if ( $last ) { $last_inbound = $last; }
		$session_status = (string) ( $readiness['session_status'] ?? ( $status['status'] ?? 'unknown' ) );
		$bridge_status = (string) ( $readiness['bridge_health']['status'] ?? 'unknown' );
		$signal = 'down';
		if ( in_array( $session_status, array( 'connected', 'ready' ), true ) && 'healthy' === $bridge_status ) {
			$signal = ( microtime( true ) - $started ) < 0.5 ? 'good' : 'fair';
		} elseif ( 'degraded' === $bridge_status || 'connected' === $session_status ) { $signal = 'weak'; }
		return new WP_REST_Response( array(
			'ok' => true,
			'layers' => array(
				array( 'key' => 'gateway', 'ok' => true, 'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'status' => 'reachable', 'at' => gmdate( 'c' ) ),
				array( 'key' => 'hub', 'ok' => 'healthy' === $bridge_status, 'status' => $bridge_status, 'reason_bucket' => (string) ( $readiness['bridge_health']['code'] ?? '' ) ),
				array( 'key' => 'session', 'ok' => in_array( $session_status, array( 'connected', 'ready' ), true ), 'status' => $session_status ),
				array( 'key' => 'last_inbound', 'ok' => true, 'status' => $last_inbound, 'at' => $last_inbound ),
			),
			'signal' => $signal,
			'checked_at' => gmdate( 'c' ),
		), 200 );
	}

	/**
	 * PHASE-0.56 C-7 — bots on this site an actor with `staff.link_bot` rank may send a link code
	 * through. No subject: same "am I supervisor+ at all" floor `staff.link_bot`'s `MIN_RANK` already
	 * enforces, narrowed to the actor's own team happens per-employee at create time, not here.
	 */
	public static function list_zalo_bots( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.link_bot' );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return new WP_REST_Response( array( 'ok' => true, 'bots' => array() ), 200 );
		}
		$rows = $wpdb->get_results( "SELECT id, bot_name, status FROM {$table} ORDER BY id DESC", ARRAY_A );
		$bots = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$bots[] = array(
				'id'     => (int) $r['id'],
				'name'   => sanitize_text_field( (string) ( $r['bot_name'] ?? '' ) ),
				'active' => in_array( (string) ( $r['status'] ?? '' ), array( 'active', 'enabled', '1' ), true ),
			);
		}
		return new WP_REST_Response( array( 'ok' => true, 'bots' => $bots ), 200 );
	}

	/**
	 * PHASE-0.56 C-7 (D56-2) — issue a Zalo Bot `/link <nonce>` code ADDRESSED TO `$subject_id`
	 * (`class-user-linker.php::issue_twin_gpt_link_nonce()`, C-5), 24h TTL — longer than the
	 * 10-minute self-service TTL because the employee may not act on it right away. The code never
	 * leaves this response as anything more than the exact command/QR text the employee is meant to
	 * send to the bot themselves; no chat_id is ever returned (R-LM-8.5).
	 */
	public static function create_staff_bot_link( WP_REST_Request $req ) {
		$actor_id   = get_current_user_id();
		$subject_id = (int) $req['id'];
		if ( ! BizCity_CRM_Staff_Policy::is_assignable_user( $subject_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy nhân viên.', 'hint' => '', 'help_code' => 'not_found' ), 404 );
		}
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.link_bot', $subject_id );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Module Zalo Bot chưa sẵn sàng.', 'hint' => 'Bật plugin Zalo Bot rồi thử lại.', 'help_code' => 'module_not_loaded' ), 503 );
		}
		$bot_id = max( 0, (int) $req->get_param( 'bot_id' ) );
		global $wpdb;
		$bots_table = $wpdb->prefix . 'bizcity_zalo_bots';
		$bot = $bot_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT id, bot_name, oa_id, status FROM {$bots_table} WHERE id = %d LIMIT 1", $bot_id ), ARRAY_A ) : null;
		if ( ! $bot ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chưa chọn Zalo Bot hợp lệ.', 'hint' => 'Chọn một Zalo Bot của site.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$issued = BizCity_Zalobot_User_Linker::issue_twin_gpt_link_nonce( $subject_id, $bot_id, $actor_id, DAY_IN_SECONDS );
		if ( is_wp_error( $issued ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => (string) $issued->get_error_code(), 'message' => 'Không tạo được mã liên kết.', 'hint' => 'Thử lại sau ít phút.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$nonce   = (string) ( $issued['nonce'] ?? '' );
		$command = '/link ' . $nonce;
		// Same URL construction as the self-service `member_create_deep_link()` in
		// `bizcity-zalo-bot/includes/class-rest-api.php` — kept identical on purpose so the two flows
		// hand the employee the same kind of link regardless of who started it.
		$oa_id     = sanitize_text_field( (string) ( $bot['oa_id'] ?? '' ) );
		$chat_url  = $oa_id !== '' && preg_match( '#^https?://#i', $oa_id ) ? esc_url_raw( $oa_id ) : ( $oa_id !== '' ? 'https://zalo.me/' . rawurlencode( $oa_id ) : '' );
		$deep_link = $chat_url !== '' ? add_query_arg( 'text', $command, $chat_url ) : '';
		$expires_at = (int) ( $issued['expires_at'] ?? 0 );
		// Short-lived marker so `staff_bot_link_status()` can answer "pending" without knowing the
		// zalo_user_id yet (that only exists once the employee actually sends the command) — scoped to
		// this feature's own transient namespace, does not touch the nonce transient itself.
		set_transient( 'bizcity_crm_bot_link_pending_' . $subject_id, array( 'bot_id' => $bot_id, 'expires_at' => $expires_at ), max( 1, $expires_at - time() ) );
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_staff', $subject_id, 'zalo_bot_link_issued', array(), array( 'bot_id' => $bot_id ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array(
			'ok'         => true,
			'state'      => 'pending',
			'command'    => $command,
			'deep_link'  => $deep_link,
			'expires_at' => $expires_at,
		), 200 );
	}

	/**
	 * PHASE-0.56 C-7 — `pending|linked|expired` for the sheet's poll. `linked` reuses the exact same
	 * resolver `attach_zalo_bot_linked()` already trusts, so the roster and this sheet never disagree.
	 * Never returns a chat_id (R-LM-8.5).
	 */
	public static function staff_bot_link_status( WP_REST_Request $req ) {
		$actor_id   = get_current_user_id();
		$subject_id = (int) $req['id'];
		if ( ! BizCity_CRM_Staff_Policy::is_assignable_user( $subject_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy nhân viên.', 'hint' => '', 'help_code' => 'not_found' ), 404 );
		}
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.link_bot', $subject_id );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		$linked = class_exists( 'BizCity_Channel_User_Linker' ) && method_exists( 'BizCity_Channel_User_Linker', 'zalo_bot_target_for_user' )
			? ! empty( BizCity_Channel_User_Linker::zalo_bot_target_for_user( $subject_id ) )
			: false;
		if ( $linked ) {
			return new WP_REST_Response( array( 'ok' => true, 'state' => 'linked' ), 200 );
		}
		$pending = get_transient( 'bizcity_crm_bot_link_pending_' . $subject_id );
		if ( is_array( $pending ) && (int) ( $pending['expires_at'] ?? 0 ) > time() ) {
			return new WP_REST_Response( array( 'ok' => true, 'state' => 'pending', 'expires_at' => (int) $pending['expires_at'] ), 200 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'state' => 'expired' ), 200 );
	}

	// ── PHASE-0.50 W3 portfolio + W1 owner/stage contact sets ────────────

	const DORMANT_DAYS = 30;
	const PORTFOLIO_CONTACT_CAP = 5000;

	/** PHASE-0.50 W1 — phone chip selector check: is `$inbox_id` inside the subject's own inbox scope. */
	public static function subject_has_inbox( int $user_id, int $inbox_id ): bool {
		return $inbox_id > 0 && in_array( $inbox_id, self::subject_inbox_ids( $user_id ), true );
	}

	/** Inbox ids in the subject's OWN resolved scope (never the actor's widened one). */
	private static function subject_inbox_ids( int $user_id ): array {
		if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) { return array(); }
		$scope = BizCity_CRM_Inbox_Access::resolve_scope( $user_id, 'be', true );
		return array_values( array_filter( array_map( 'intval', (array) ( $scope['inbox_ids'] ?? array() ) ) ) );
	}

	/**
	 * Paid/created orders attributed to `$user_id` (D4 `_bizcity_crm_assignee_id`), grouped by CRM contact.
	 *
	 * @return array<int,array{orders:int,paid:int,revenue:float,last_order_ts:int}>
	 */
	/** True when the order's owner was inferred by the D4 backfill (current assignee), not stamped at order creation. */
	private static function is_estimated_attribution( $order ): bool {
		$outcome = method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_bizcity_crm_attribution' ) : '';
		$estimated = class_exists( 'BizCity_CRM_Attribution_Backfill' ) ? BizCity_CRM_Attribution_Backfill::OUTCOME_CURRENT : 'backfill_current';
		return $outcome === $estimated;
	}

	private static function attributed_orders_by_contact( int $user_id ): array {
		$out = array();
		if ( ! function_exists( 'wc_get_orders' ) ) { return $out; }
		$orders = wc_get_orders( array( 'limit' => 1000, 'return' => 'objects', 'meta_key' => '_bizcity_crm_assignee_id', 'meta_value' => $user_id ) );
		foreach ( (array) $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) { continue; }
			$contact_id = (int) $order->get_meta( '_bizcity_crm_contact_id' );
			if ( $contact_id <= 0 ) { continue; }
			if ( ! isset( $out[ $contact_id ] ) ) { $out[ $contact_id ] = array( 'orders' => 0, 'paid' => 0, 'revenue' => 0.0, 'revenue_estimated' => 0.0, 'last_order_ts' => 0 ); }
			$out[ $contact_id ]['orders']++;
			$created = $order->get_date_created();
			$ts = $created ? (int) $created->getTimestamp() : 0;
			$out[ $contact_id ]['last_order_ts'] = max( $out[ $contact_id ]['last_order_ts'], $ts );
			if ( method_exists( $order, 'is_paid' ) && $order->is_paid() ) {
				$out[ $contact_id ]['paid']++;
				// [2026-09-18] 0.48F §3.4 — D4 backfill ('backfill_current') is an estimate: never mix it into confirmed revenue.
				if ( self::is_estimated_attribution( $order ) ) { $out[ $contact_id ]['revenue_estimated'] += (float) $order->get_total(); } else { $out[ $contact_id ]['revenue'] += (float) $order->get_total(); }
			}
		}
		return $out;
	}

	/**
	 * Fact-based portfolio of one staff member. "Đang tư vấn" reads the unified pipeline resolver
	 * separately (see `owner_contact_ids( ..., 'consulting' )`, PHASE-0.52 P52-C-04); "có nhu cầu" still
	 * has no server fact and the UI shows "chưa có dữ liệu" for it.
	 *
	 * @return array{inbox_ids:int[],contact_ids:int[],orders:array,last_activity:array<int,int>}
	 */
	private static function portfolio_facts( int $user_id, int $only_inbox_id = 0 ): array {
		global $wpdb;
		$inbox_ids = self::subject_inbox_ids( $user_id );
		// PHASE-0.50 W1 — narrow to one phone of the subject; an inbox outside the subject's scope yields nothing.
		if ( $only_inbox_id > 0 ) { $inbox_ids = in_array( $only_inbox_id, $inbox_ids, true ) ? array( $only_inbox_id ) : array(); }
		$contact_ids = array();
		$last_activity = array();
		if ( ! empty( $inbox_ids ) ) {
			$ci_t = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
			$conv_t = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$ph = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ci.contact_id, MAX(c.last_activity_at) AS last_at
				 FROM `{$ci_t}` ci
				 LEFT JOIN `{$conv_t}` c ON c.contact_inbox_id = ci.id
				 WHERE ci.inbox_id IN ({$ph})
				 GROUP BY ci.contact_id
				 LIMIT " . self::PORTFOLIO_CONTACT_CAP,
				$inbox_ids
			), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$cid = (int) $row['contact_id'];
				if ( $cid <= 0 ) { continue; }
				$contact_ids[] = $cid;
				$last_activity[ $cid ] = $row['last_at'] ? (int) strtotime( (string) $row['last_at'] ) : 0;
			}
		}
		return array( 'inbox_ids' => $inbox_ids, 'contact_ids' => $contact_ids, 'orders' => self::attributed_orders_by_contact( $user_id ), 'last_activity' => $last_activity );
	}

	/**
	 * Contact ids behind one W3 bucket, for the W1 `owner`/`stage` filter. Caller must have run
	 * `Staff_Policy::can( actor, 'contact.view_by_owner', owner )` first.
	 * Stage: '' (all) | new | touched | buyer | repeat | dormant. `$inbox_id` narrows to one of the owner's phones.
	 *
	 * @return int[]
	 */
	public static function owner_contact_ids( int $owner_id, string $stage = '', int $days = 30, int $inbox_id = 0 ): array {
		global $wpdb;
		$facts = self::portfolio_facts( $owner_id, $inbox_id );
		$ids = $facts['contact_ids'];
		if ( '' === $stage || empty( $ids ) ) { return $ids; }
		$now_ts = current_time( 'timestamp' );
		if ( 'buyer' === $stage || 'repeat' === $stage ) {
			$min_paid = 'repeat' === $stage ? 2 : 1;
			return array_values( array_filter( $ids, static function ( $cid ) use ( $facts, $min_paid ) { return ( $facts['orders'][ $cid ]['paid'] ?? 0 ) >= $min_paid; } ) );
		}
		if ( 'dormant' === $stage ) {
			$cutoff = $now_ts - self::DORMANT_DAYS * DAY_IN_SECONDS;
			return array_values( array_filter( $ids, static function ( $cid ) use ( $facts, $cutoff ) {
				return ( $facts['orders'][ $cid ]['paid'] ?? 0 ) >= 1 && ( $facts['last_activity'][ $cid ] ?? 0 ) < $cutoff;
			} ) );
		}
		// PHASE-0.52 P52-C-04 — closes Q1 0.50: "consulting" reads the unified pipeline resolver (R-PIPE),
		// same source ("consult" = open opportunity stage qualification, or a manual move) as L1/M2.
		if ( 'consulting' === $stage ) {
			if ( ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) { return array(); }
			$rows = BizCity_CRM_Customer_Pipeline::rows( $ids );
			return array_values( array_filter( $ids, static function ( $cid ) use ( $rows ) {
				return 'consult' === (string) ( $rows[ $cid ]['stage'] ?? '' );
			} ) );
		}
		$from = gmdate( 'Y-m-d H:i:s', $now_ts - max( 1, $days ) * DAY_IN_SECONDS );
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		if ( 'new' === $stage ) {
			$ct_t = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$ct_t}` WHERE deleted_at IS NULL AND created_at >= %s AND id IN ({$ph})", array_merge( array( $from ), $ids ) ) ) );
		}
		if ( 'touched' === $stage ) {
			$ci_t = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
			$conv_t = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$msg_t = BizCity_CRM_DB_Installer_V2::tbl_messages();
			return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT ci.contact_id FROM `{$msg_t}` m
				 INNER JOIN `{$conv_t}` c ON c.id = m.conversation_id
				 INNER JOIN `{$ci_t}` ci ON ci.id = c.contact_inbox_id
				 WHERE m.message_type = 'outgoing' AND m.responder_user_id = %d AND m.created_at >= %s AND ci.contact_id IN ({$ph})",
				array_merge( array( $owner_id, $from ), $ids )
			) ) );
		}
		return $ids;
	}

	public static function get_team_member_portfolio( WP_REST_Request $req ) {
		global $wpdb;
		$actor_id = get_current_user_id();
		$subject_id = (int) $req['user_id'];
		if ( $subject_id !== $actor_id ) {
			$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'contact.view_by_owner', $subject_id );
			if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		}
		if ( ! BizCity_CRM_Staff_Policy::is_assignable_user( $subject_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy nhân viên.', 'hint' => '', 'help_code' => 'not_found' ), 404 );
		}
		$range_key = sanitize_key( (string) ( $req->get_param( 'range' ) ?? '30d' ) );
		$days = '7d' === $range_key ? 7 : ( 'today' === $range_key ? 1 : 30 );

		$facts = self::portfolio_facts( $subject_id );
		$ids = $facts['contact_ids'];
		$now_ts = current_time( 'timestamp' );
		$from = gmdate( 'Y-m-d H:i:s', $now_ts - $days * DAY_IN_SECONDS );

		// Customers per phone (inbox).
		$per_inbox = array();
		if ( ! empty( $facts['inbox_ids'] ) ) {
			$ci_t = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
			$ph = implode( ',', array_fill( 0, count( $facts['inbox_ids'] ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT inbox_id, COUNT(DISTINCT contact_id) AS n FROM `{$ci_t}` WHERE inbox_id IN ({$ph}) GROUP BY inbox_id", $facts['inbox_ids'] ), ARRAY_A ) as $r ) {
				$per_inbox[ (int) $r['inbox_id'] ] = (int) $r['n'];
			}
		}
		$phones = self::fetch_phone_summaries( array( $subject_id ) )[ $subject_id ]['phones'] ?? array();
		foreach ( $phones as &$phone ) { $phone['customers'] = $per_inbox[ $phone['inbox_id'] ] ?? 0; }
		unset( $phone );

		// New customers in range, by acquisition source family (text before ':').
		$new_total = 0;
		$sources = array();
		if ( ! empty( $ids ) ) {
			$ct_t = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT acquisition_source AS s, COUNT(*) AS n FROM `{$ct_t}` WHERE deleted_at IS NULL AND created_at >= %s AND id IN ({$ph}) GROUP BY acquisition_source", array_merge( array( $from ), $ids ) ), ARRAY_A ) as $r ) {
				$family = strtolower( strtok( (string) $r['s'], ':' ) ?: '' );
				$key = '' === $family ? 'unknown' : sanitize_key( $family );
				$sources[ $key ] = ( $sources[ $key ] ?? 0 ) + (int) $r['n'];
				$new_total += (int) $r['n'];
			}
		}
		arsort( $sources );

		$touched = count( self::owner_contact_ids( $subject_id, 'touched', $days ) );
		// PHASE-0.52 P52-C-04 — closes Q1 0.50 (§3.2): "consulting" now has a server fact, the unified
		// pipeline resolver's "consult" stage (opp qualification, or a manual move), same source L1/M2 use.
		$consulting = class_exists( 'BizCity_CRM_Customer_Pipeline' ) ? count( self::owner_contact_ids( $subject_id, 'consulting' ) ) : null;
		$buyers = 0; $repeat = 0; $dormant = 0; $ordered_in_range = 0; $revenue = 0.0; $revenue_estimated = 0.0;
		$dormant_cutoff = $now_ts - self::DORMANT_DAYS * DAY_IN_SECONDS;
		$in_scope = array_flip( $ids );
		foreach ( $facts['orders'] as $cid => $o ) {
			if ( ! isset( $in_scope[ $cid ] ) ) { continue; }
			if ( $o['paid'] >= 1 ) { $buyers++; $revenue += $o['revenue']; $revenue_estimated += $o['revenue_estimated'] ?? 0.0; }
			if ( $o['paid'] >= 2 ) { $repeat++; }
			if ( $o['paid'] >= 1 && ( $facts['last_activity'][ $cid ] ?? 0 ) < $dormant_cutoff ) { $dormant++; }
			if ( $o['last_order_ts'] >= $now_ts - $days * DAY_IN_SECONDS ) { $ordered_in_range++; }
		}

		// [2026-09-18] Master roadmap M3-03 — a rate travels with its sample (contract `rate_basis`, den ≥ MIN_SAMPLE_FOR_RATE);
		// under the threshold both are absent/null so the UI says "ít dữ liệu" instead of a misleading %.
		$funnel = array(
			'touched' => $touched,
			'ordered' => $ordered_in_range,
			'rate'    => $touched >= self::MIN_SAMPLE_FOR_RATE ? round( $ordered_in_range / max( 1, $touched ) * 100, 1 ) : null,
			'repeat'  => $repeat,
		);
		if ( null !== $funnel['rate'] ) {
			$funnel['rate_basis'] = array( 'num' => $ordered_in_range, 'den' => $touched );
		}

		return new WP_REST_Response( array(
			'ok'       => true,
			'contract' => 'staff-customer-portfolio',
			'version'  => '1.0.0',
			'employee' => array( 'user_id' => $subject_id, 'display_name' => (string) ( get_userdata( $subject_id )->display_name ?? '' ), 'role' => BizCity_CRM_Staff_Policy::role( $subject_id ) ),
			'as_of'    => current_time( 'mysql' ),
			'range'    => array( 'key' => 7 === $days ? '7d' : ( 1 === $days ? 'today' : '30d' ), 'days' => $days ),
			'capped'   => count( $ids ) >= self::PORTFOLIO_CONTACT_CAP,
			'phones'   => $phones,
			'customers_total' => count( $ids ),
			'buckets'  => array(
				array( 'key' => 'new', 'label' => 'Mới', 'value' => $new_total ),
				array( 'key' => 'consulting', 'label' => 'Đang tư vấn', 'value' => $consulting ),
				array( 'key' => 'buyer', 'label' => 'Đã mua', 'value' => $buyers ),
				array( 'key' => 'repeat', 'label' => 'Mua lại', 'value' => $repeat ),
				array( 'key' => 'dormant', 'label' => 'Nguội > ' . self::DORMANT_DAYS . ' ngày', 'value' => $dormant, 'warn' => $dormant > 0 ),
			),
			'sources'  => $sources,
			'funnel'   => $funnel,
			'revenue_attributed' => $revenue,
			'revenue_estimated'  => $revenue_estimated,
			'notes'    => array( 'attribution' => 'Doanh thu xác nhận: theo người phụ trách lúc tạo đơn. Doanh thu ước tính: đơn cũ được gán theo người phụ trách hiện tại (backfill), tính riêng.' ),
		), 200 );
	}

	// ── POST /crm-phones/{inbox_id}/transfer-owner ───────────────────────

	public static function transfer_phone_owner( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$inbox_id = (int) $req['inbox_id'];
		$to_user_id = max( 0, (int) $req->get_param( 'to_user_id' ) );
		$resolved = self::resolve_authorized_phone( $actor_id, $inbox_id, 'phone.assign' );
		if ( $resolved instanceof WP_REST_Response ) { return $resolved; }
		$from_user_id = (int) $resolved['owner_user_id'];
		if ( $to_user_id <= 0 || $to_user_id === $from_user_id || ! BizCity_CRM_Staff_Policy::is_assignable_user( $to_user_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Chọn người nhận SĐT hợp lệ.', 'hint' => 'Chọn một nhân viên khác người đang phụ trách.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		if ( 'suspended' === get_user_meta( $to_user_id, self::META_STATUS, true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Nhân viên nhận đang ngưng hoạt động.', 'hint' => 'Kích hoạt lại hoặc chọn người khác.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$to_decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'phone.assign', $to_user_id );
		if ( ! $to_decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $to_decision ); }

		$account = $resolved['account'];
		// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N2 (G4) — move the channel-gateway grant
		// BEFORE the mapping row, and abort on failure: `Staff_Policy` already authorized both sides
		// above, so this can only fail on quota or a data problem, and the mapping must never say
		// "owned by B" while the grant layer (used by /gpt/ and Context Bank) still says "A".
		if ( 'personal' === (string) ( $account['kind'] ?? '' ) ) {
			if ( ! class_exists( 'BizCity_Channel_User_Grant' ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Channel Gateway chưa sẵn sàng.', 'hint' => 'Bật Channel Gateway rồi thử lại.', 'help_code' => 'module_not_loaded' ), 503 );
			}
			$grant_result = BizCity_Channel_User_Grant::reassign_owner(
				'zalo_personal', (string) ( $account['bridge_account_id'] ?? '' ), $to_user_id, $actor_id, true,
				array( 'source' => 'crm_transfer_owner' )
			);
			if ( empty( $grant_result['ok'] ) ) {
				$reason = sanitize_key( (string) ( $grant_result['reason'] ?? 'grant_write_failed' ) );
				if ( 'personal_account_quota_reached' === $reason ) {
					$quota = (int) ( $grant_result['quota'] ?? 0 );
					return new WP_REST_Response( array( 'ok' => false, 'code' => $reason, 'message' => $quota > 0 ? sprintf( 'Người nhận đã dùng hết %d SĐT Zalo Cá nhân được phép.', $quota ) : 'Người nhận đã dùng hết số SĐT Zalo Cá nhân được phép.', 'hint' => 'Gỡ một SĐT của người nhận hoặc chọn người khác.', 'help_code' => $reason, 'quota' => $quota ), 200 );
				}
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'transfer_failed', 'message' => 'Không chuyển được quyền sở hữu SĐT.', 'hint' => 'Thử lại sau ít phút.', 'help_code' => 'transfer_failed', 'reason' => $reason ), 200 );
			}
		}
		BizCity_Zalo_Mapping_Repo::save_account( array(
			'kind'              => (string) ( $account['kind'] ?? 'personal' ),
			'owner_user_id'     => $to_user_id,
			'label'             => (string) ( $account['label'] ?? '' ),
			'bridge_account_id' => (string) ( $account['bridge_account_id'] ?? '' ),
			'zalo_uid'          => (string) ( $account['zalo_uid'] ?? '' ),
			'zalo_oa_id'        => (string) ( $account['zalo_oa_id'] ?? '' ),
			'crm_inbox_id'      => (int) ( $account['crm_inbox_id'] ?? 0 ),
			'status'            => (string) ( $account['status'] ?? 'pending_qr' ),
		) );

		// Open conversations follow the phone only when requested; default keeps current assignees.
		$conversations_moved = 0;
		if ( rest_sanitize_boolean( $req->get_param( 'move_open_conversations' ) ) && class_exists( 'BizCity_CRM_Repository' ) ) {
			foreach ( (array) BizCity_CRM_Repository::list_conversations( array( 'inbox_id' => $inbox_id, 'assignee_id' => $from_user_id, 'status' => 'open', 'limit' => 200 ) ) as $conv ) {
				$conv_id = (int) ( $conv['id'] ?? 0 );
				if ( $conv_id > 0 && BizCity_CRM_Repository::set_conversation_assignee( $conv_id, $to_user_id, $actor_id, array( 'reason' => 'phone_transferred' ) ) ) {
					$conversations_moved++;
				}
			}
		}
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_phone', $inbox_id, 'updated', array( 'owner_user_id' => $from_user_id ), array( 'owner_user_id' => $to_user_id, 'conversations_moved' => $conversations_moved ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'inbox_id' => $inbox_id, 'from_user_id' => $from_user_id, 'to_user_id' => $to_user_id, 'conversations_moved' => $conversations_moved ), 200 );
	}

	// ── PATCH /crm-phones/{inbox_id} ─────────────────────────────────────

	/**
	 * [2026-09-18] PHASE-0.53 N4 (S3) — rename a phone's display label. Same `phone.assign` gate as
	 * transfer (§3.0 S3 of the doc); updates both the account row (`bizcity_zalo_accounts.label`, via
	 * the same `save_account()` upsert `transfer_phone_owner()` uses) and the CRM inbox name the rail
	 * actually renders, since those two are only kept in sync at creation time otherwise.
	 */
	public static function update_phone( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$inbox_id = (int) $req['inbox_id'];
		$resolved = self::resolve_authorized_phone( $actor_id, $inbox_id, 'phone.assign' );
		if ( $resolved instanceof WP_REST_Response ) { return $resolved; }
		$body = $req->get_json_params();
		$label = trim( sanitize_text_field( (string) ( $body['label'] ?? '' ) ) );
		if ( '' === $label ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Nhập tên hiển thị.', 'hint' => '', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$label = mb_substr( $label, 0, 190 );
		$account = $resolved['account'];
		$before  = (string) ( $account['label'] ?? '' );
		BizCity_Zalo_Mapping_Repo::save_account( array(
			'kind'              => (string) ( $account['kind'] ?? 'personal' ),
			'owner_user_id'     => (int) ( $account['owner_user_id'] ?? 0 ),
			'label'             => $label,
			'bridge_account_id' => (string) ( $account['bridge_account_id'] ?? '' ),
			'zalo_uid'          => (string) ( $account['zalo_uid'] ?? '' ),
			'zalo_oa_id'        => (string) ( $account['zalo_oa_id'] ?? '' ),
			'crm_inbox_id'      => (int) ( $account['crm_inbox_id'] ?? 0 ),
			'status'            => (string) ( $account['status'] ?? 'pending_qr' ),
		) );
		global $wpdb;
		$inbox_prefix = 'oa' === (string) ( $account['kind'] ?? '' ) ? 'Zalo OA — ' : 'Zalo Cá nhân — ';
		$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_inboxes(), array( 'name' => $inbox_prefix . $label ), array( 'id' => $inbox_id ) );
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_phone', $inbox_id, 'updated', array( 'label' => $before ), array( 'label' => $label ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'inbox_id' => $inbox_id, 'label' => $label ), 200 );
	}

	// ── DELETE /crm-phones/{inbox_id} ────────────────────────────────────

	/**
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N4/N5 (S5, D5) — "Gỡ SĐT": `mode=disconnect`
	 * (default) and `mode=purge` (admin-only hard delete, added 2026-09-19).
	 *
	 * D5 resolved by investigation, not by choice: the mockup's "Ngắt kết nối … kết nối lại được sau"
	 * cannot be built as a pure local status flip — `BizCity_Zalo_Inbound_Emitter::emit()` routes an
	 * incoming message by `bridge_account_id`/`crm_inbox_id` alone, never checking `status`, and
	 * `handle_qr_status()` re-mirrors the Hub's real (still-connected) status over any local override
	 * on the very next poll. So "disconnect" here calls the same Hub delete + `revoked` local status
	 * `delete_account_for_owner()` already uses for `/gpt/` self-service (R-ZP-DUP DUP-11): history,
	 * inbox and conversations are kept, but the number cannot be QR-relogged-in again — reusing it
	 * needs a new phone entry (S1). The FE copy must say this plainly, not promise a reconnect this
	 * server cannot actually offer.
	 */
	public static function remove_phone( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$inbox_id = (int) $req['inbox_id'];
		$resolved = self::resolve_authorized_phone( $actor_id, $inbox_id, 'phone.assign' );
		if ( $resolved instanceof WP_REST_Response ) { return $resolved; }
		$account = $resolved['account'];
		$mode = sanitize_key( (string) ( $req->get_param( 'mode' ) ?: 'disconnect' ) );
		if ( 'purge' === $mode ) { return self::purge_phone( $req, $inbox_id, $account, $actor_id ); }
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Module Zalo Personal chưa sẵn sàng.', 'hint' => 'Bật Zalo Personal rồi thử lại.', 'help_code' => 'module_not_loaded' ), 503 );
		}
		$owner_id = (int) ( $account['owner_user_id'] ?? 0 );
		$result = BizCity_Zalo_Bridge_REST::delete_account_for_owner( $account, $owner_id, true );
		$data = $result->get_data();
		if ( empty( $data['ok'] ) ) { return $result; }
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_phone', $inbox_id, 'removed', array( 'status' => (string) ( $account['status'] ?? '' ) ), array( 'status' => 'revoked', 'mode' => 'disconnect' ), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array(
			'ok'       => true,
			'inbox_id' => $inbox_id,
			'code'     => 'revoked',
			'message'  => 'Đã gỡ SĐT khỏi CRM. Lịch sử và hội thoại được giữ nguyên.',
			'hint'     => 'Số này không quét QR lại được nữa — tạo SĐT mới nếu cần dùng lại số.',
		), 200 );
	}

	/**
	 * [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N4 (S5 `mode=purge`) — hard delete: the Hub
	 * session (if not already dead) is torn down first, then the CRM inbox and every conversation/
	 * message under it are gone for good (`BizCity_CRM_Repository::purge_managed_personal_inbox()`).
	 * Gated on top of `remove_phone()`'s own `phone.assign` check: admin-only (rule 5, doc §4) and a
	 * typed confirmation (`"XOA {inbox_id}"` — not the phone number itself, since `bizcity_zalo_
	 * accounts` has no phone column to confirm against, see §10.2's `display_phone` gap) so a stray
	 * click can never silently wipe a customer's history.
	 */
	private static function purge_phone( WP_REST_Request $req, int $inbox_id, array $account, int $actor_id ) {
		if ( ! ( ( function_exists( 'is_super_admin' ) && is_super_admin( $actor_id ) ) || current_user_can( 'manage_options' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'permission_denied', 'message' => 'Chỉ quản trị viên được xoá hẳn hộp thư.', 'hint' => '', 'help_code' => 'permission_denied' ), 403 );
		}
		$body = $req->get_json_params();
		$confirm = trim( (string) ( $body['confirm'] ?? '' ) );
		$expected = 'XOA ' . $inbox_id;
		if ( $confirm !== $expected ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'confirm_mismatch', 'message' => 'Gõ đúng "' . $expected . '" để xác nhận.', 'hint' => 'Không phân biệt gõ sai — phải khớp chính xác.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$dead_statuses = array( 'revoked', 'orphaned', 'deleted' );
		if ( ! in_array( (string) ( $account['status'] ?? '' ), $dead_statuses, true ) ) {
			if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Module Zalo Personal chưa sẵn sàng.', 'hint' => 'Bật Zalo Personal rồi thử lại.', 'help_code' => 'module_not_loaded' ), 503 );
			}
			$owner_id = (int) ( $account['owner_user_id'] ?? 0 );
			$revoke_result = BizCity_Zalo_Bridge_REST::delete_account_for_owner( $account, $owner_id, true );
			$revoke_data = $revoke_result->get_data();
			// Abort on a real teardown failure — never destroy local history while the Hub session
			// might still be alive and receiving messages nowhere will read again.
			if ( empty( $revoke_data['ok'] ) ) { return $revoke_result; }
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'purge_managed_personal_inbox' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Chưa xoá được hộp thư.', 'hint' => '', 'help_code' => 'module_not_loaded' ), 503 );
		}
		if ( ! BizCity_CRM_Repository::purge_managed_personal_inbox( $inbox_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'purge_failed', 'message' => 'Không xoá được hộp thư này.', 'hint' => 'Thử lại sau hoặc liên hệ kỹ thuật.', 'help_code' => 'invalid_param_generic' ), 500 );
		}
		// The account row itself is kept (permanent history, same as disconnect) but its
		// `crm_inbox_id` now points at a deleted inbox — clear it so diagnostics/Guru bindings
		// never match it against a dead id (same reasoning as recover's old-row cleanup, §10.6).
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && ! empty( $account['id'] ) ) {
			BizCity_Zalo_Mapping_Repo::update_account_status( (int) $account['id'], 'revoked', array( 'crm_inbox_id' => 0 ) );
		}
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			// entity_id kept for the audit trail even though the inbox row is now gone.
			BizCity_CRM_Audit_Log::log( 'crm_phone', $inbox_id, 'purged', array(), array(), array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array(
			'ok'      => true,
			'code'    => 'purged',
			'message' => 'Đã xoá hẳn hộp thư và toàn bộ hội thoại.',
		), 200 );
	}

	// ── POST /crm-phones/{inbox_id}/recover ──────────────────────────────

	/**
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N5 (S6/G6). Gated by `phone.recover`
	 * (supervisor+, no self-service — doc §3.3), NOT `phone.assign`: an employee never sees this
	 * even for their own dead number (server fail-closed the same way `phone.add_for_other` does).
	 */
	public static function recover_phone( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$inbox_id = (int) $req['inbox_id'];
		$resolved = self::resolve_authorized_phone( $actor_id, $inbox_id, 'phone.recover' );
		if ( $resolved instanceof WP_REST_Response ) { return $resolved; }
		$account = $resolved['account'];
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'Module Zalo Personal chưa sẵn sàng.', 'hint' => '', 'help_code' => 'module_not_loaded' ), 503 );
		}
		$result = BizCity_Zalo_Bridge_REST::recover_account_for_owner( $account, $inbox_id, $actor_id );
		$data = $result->get_data();
		if ( ! empty( $data['ok'] ) && class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_phone', $inbox_id, 'recovered', array( 'status' => (string) ( $account['status'] ?? '' ) ), array( 'status' => 'pending_qr' ), array( 'user_id' => $actor_id ) );
		}
		return $result;
	}

	// ── GET /reports/team-inbox, /reports/team-inbox/member/{id} ────────

	/** `today|7d|30d` → `{range, from, to}` in `current_time('mysql')` terms — the same clock every `created_at`/`updated_at` column in this plugin is written with. */
	private static function dashboard_range( string $range ): array {
		$now = current_time( 'mysql' );
		if ( '7d' === $range ) {
			return array( 'range' => '7d', 'from' => gmdate( 'Y-m-d H:i:s', strtotime( '-7 days', current_time( 'timestamp' ) ) ), 'to' => $now );
		}
		if ( '30d' === $range ) {
			return array( 'range' => '30d', 'from' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days', current_time( 'timestamp' ) ) ), 'to' => $now );
		}
		return array( 'range' => 'today', 'from' => current_time( 'Y-m-d' ) . ' 00:00:00', 'to' => $now );
	}

	private static function median( array $values ) {
		$values = array_values( array_filter( $values, static function ( $v ) { return null !== $v; } ) );
		$n = count( $values );
		if ( 0 === $n ) { return null; }
		sort( $values );
		$mid = intdiv( $n, 2 );
		return 1 === $n % 2 ? (float) $values[ $mid ] : ( ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2 );
	}

	/**
	 * The employee set a dashboard request may see: `Staff_Policy::visible_user_ids()`
	 * (self + team for supervisor/lead) or every assignable user for an admin
	 * — same eligibility rule as the staff roster (R-CRMF-8).
	 *
	 * @return array<int,int>
	 */
	private static function dashboard_scope( int $actor_id ): array {
		$visible = BizCity_CRM_Staff_Policy::visible_user_ids( $actor_id );
		if ( null !== $visible ) { return $visible; }
		return self::admin_staff_user_ids();
	}

	/**
	 * One query for every employee in scope: open/breach counts (a conversation
	 * "breaches" when it is open, the customer's message is the last one, and
	 * it has sat unanswered past `WAIT_MINUTES_BREACH`). Uses the same
	 * denormalized `last_message_id` join `BizCity_CRM_Repository::list_conversations()`
	 * already relies on — not the `waiting_since`/`first_reply_at` columns,
	 * which nothing in this codebase currently writes (verified: no `UPDATE`
	 * touches either column anywhere in this plugin), so they are always NULL
	 * and would silently report zero for everyone.
	 *
	 * @return array<int,array{open:int,breach:int}>
	 */
	private static function fetch_conversation_aggregates( array $user_ids ): array {
		global $wpdb;
		if ( empty( $user_ids ) ) { return array(); }
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$msg  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$owned_inboxes = self::owned_inbox_ids_by_user( $user_ids );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::WAIT_MINUTES_BREACH . ' minutes', current_time( 'timestamp' ) ) );
		$out = array();
		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;
			$inbox_ids = array_values( array_unique( array_map( 'intval', $owned_inboxes[ $uid ] ?? array() ) ) );
			$where = 'c.assignee_id = %d';
			$params = array( $uid );
			if ( ! empty( $inbox_ids ) ) {
				$where .= ' OR ((c.assignee_id IS NULL OR c.assignee_id = 0) AND c.inbox_id IN (' . implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) ) . '))';
				$params = array_merge( $params, $inbox_ids );
			}
			$sql = "SELECT COUNT(*) AS open_count,
					SUM(CASE WHEN m.message_type = 'incoming' AND m.created_at < %s THEN 1 ELSE 0 END) AS breach_count
				FROM `{$conv}` c LEFT JOIN `{$msg}` m ON m.id = c.last_message_id
				WHERE c.status = 'open' AND ({$where})";
			$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( array( $cutoff ), $params ) ), ARRAY_A );
			$out[ $uid ] = array( 'open' => (int) ( $row['open_count'] ?? 0 ), 'breach' => (int) ( $row['breach_count'] ?? 0 ) );
		}
		return $out;
	}

	private static function owned_inbox_ids_by_user( array $user_ids ): array {
		$out = array();
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) { return $out; }
		foreach ( $user_ids as $uid ) {
			$out[ (int) $uid ] = array();
			foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( (int) $uid ) as $account ) {
				$inbox_id = (int) ( $account['crm_inbox_id'] ?? 0 );
				if ( $inbox_id > 0 ) { $out[ (int) $uid ][] = $inbox_id; }
			}
		}
		return $out;
	}

	/**
	 * Per-employee, within `[$from, $to)`: conversations assigned to them created
	 * in range, how many they (the CURRENT assignee) replied to at least once,
	 * and each replied conversation's first-response seconds (assignee's first
	 * outgoing message minus the conversation's `created_at`) for a median.
	 *
	 * Known simplification: "replied by X" requires `responder_user_id = c.assignee_id`
	 * at read time — a conversation transferred after being answered by someone
	 * else is not credited to the person who actually answered it. Acceptable
	 * for a first slice; revisit if reassignment before this metric matters.
	 *
	 * @return array<int,array{assigned:int,replied:int,frt_seconds:array<int,float>}>
	 */
	private static function fetch_reply_aggregates( array $user_ids, string $from, string $to ): array {
		global $wpdb;
		$out = array();
		foreach ( $user_ids as $uid ) { $out[ $uid ] = array( 'assigned' => 0, 'replied' => 0, 'frt_seconds' => array() ); }
		if ( empty( $user_ids ) ) { return $out; }
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$msg  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$owned_inboxes = self::owned_inbox_ids_by_user( $user_ids );
		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;
			$inbox_ids = array_values( array_unique( array_map( 'intval', $owned_inboxes[ $uid ] ?? array() ) ) );
			$where = 'c.assignee_id = %d';
			$params = array( $uid );
			if ( ! empty( $inbox_ids ) ) {
				$where .= ' OR ((c.assignee_id IS NULL OR c.assignee_id = 0) AND c.inbox_id IN (' . implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) ) . '))';
				$params = array_merge( $params, $inbox_ids );
			}
			$assigned = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$conv}` c WHERE ({$where}) AND c.created_at BETWEEN %s AND %s", array_merge( $params, array( $from, $to ) ) ) );
			$out[ $uid ]['assigned'] = (int) $assigned;
			$reply_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT c.id AS conv_id, c.created_at AS conv_created_at, MIN(m.created_at) AS first_reply_at FROM `{$conv}` c INNER JOIN `{$msg}` m ON m.conversation_id = c.id AND m.message_type = 'outgoing' AND m.responder_user_id = %d WHERE ({$where}) AND c.created_at BETWEEN %s AND %s GROUP BY c.id",
				array_merge( array( $uid ), $params, array( $from, $to ) )
			), ARRAY_A );
			foreach ( is_array( $reply_rows ) ? $reply_rows : array() as $reply_row ) {
				$out[ $uid ]['replied']++;
				$created_ts = strtotime( (string) $reply_row['conv_created_at'] );
				$reply_ts = strtotime( (string) $reply_row['first_reply_at'] );
				if ( $created_ts && $reply_ts && $reply_ts >= $created_ts ) { $out[ $uid ]['frt_seconds'][] = (float) ( $reply_ts - $created_ts ); }
			}
		}
		return $out;
	}

	/**
	 * `online` (any outgoing message in the last 10 minutes) and `silent_minutes`
	 * (minutes since the employee's own last outgoing message, capped/bounded to
	 * a 2-day lookback so this stays a cheap, recent-activity-only scan).
	 *
	 * @return array<int,array{online:bool,silent_minutes:?int}>
	 */
	private static function fetch_activity_aggregates( array $user_ids ): array {
		global $wpdb;
		$out = array();
		foreach ( $user_ids as $uid ) { $out[ $uid ] = array( 'online' => false, 'silent_minutes' => null ); }
		if ( empty( $user_ids ) ) { return $out; }
		$msg = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$lookback = gmdate( 'Y-m-d H:i:s', strtotime( '-2 days', current_time( 'timestamp' ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT responder_user_id AS user_id, MAX(created_at) AS last_at FROM `{$msg}` WHERE responder_user_id IN ({$placeholders}) AND message_type = 'outgoing' AND created_at >= %s GROUP BY responder_user_id",
			array_merge( $user_ids, array( $lookback ) )
		), ARRAY_A );
		$now_ts = current_time( 'timestamp' );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$uid = (int) $row['user_id'];
			$last_ts = strtotime( (string) $row['last_at'] );
			if ( ! $last_ts ) { continue; }
			$minutes = max( 0, (int) round( ( $now_ts - $last_ts ) / 60 ) );
			$out[ $uid ] = array( 'online' => $minutes <= 10, 'silent_minutes' => $minutes );
		}
		return $out;
	}

	/**
	 * Woo-owned revenue per employee, attributed via `_bizcity_crm_assignee_id`
	 * (stamped by `class-order-adapter.php::create_order()`, §3.4). Uses
	 * `wc_get_orders()` — the same meta-key query pattern
	 * `list_orders_for_contact()` already uses for `_bizcity_crm_conversation_id`
	 * — never raw SQL against `wp_posts`/`wc_orders`, so this keeps working
	 * whether the site stores orders as posts or HPOS. Orders created before
	 * this attribution existed have no `_bizcity_crm_assignee_id` meta and are
	 * correctly excluded (not backfilled — see F-UID-04b).
	 *
	 * @return array<int,array{orders:int,paid:int,revenue:float}>
	 */
	private static function fetch_order_aggregates( array $user_ids, string $from, string $to ): array {
		$out = array();
		foreach ( $user_ids as $uid ) { $out[ $uid ] = array( 'orders' => 0, 'paid' => 0, 'revenue' => 0.0, 'revenue_estimated' => 0.0 ); }
		if ( empty( $user_ids ) || ! function_exists( 'wc_get_orders' ) ) { return $out; }
		foreach ( $user_ids as $uid ) {
			$orders = wc_get_orders( array(
				'limit'        => 500,
				'return'       => 'objects',
				'meta_key'     => '_bizcity_crm_assignee_id',
				'meta_value'   => $uid,
				'date_created' => strtotime( $from ) . '...' . strtotime( $to ),
			) );
			$orders_count = 0; $paid_count = 0; $revenue = 0.0; $revenue_estimated = 0.0;
			foreach ( (array) $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) { continue; }
				$orders_count++;
				if ( method_exists( $order, 'is_paid' ) && $order->is_paid() ) {
					$paid_count++;
					// [2026-09-18] 0.48F §3.4 — keep D4 backfill estimates out of confirmed revenue.
					if ( self::is_estimated_attribution( $order ) ) { $revenue_estimated += (float) $order->get_total(); } else { $revenue += (float) $order->get_total(); }
				}
			}
			$out[ $uid ] = array( 'orders' => $orders_count, 'paid' => $paid_count, 'revenue' => $revenue, 'revenue_estimated' => $revenue_estimated );
		}
		return $out;
	}

	private static function shape_dashboard_row( int $user_id, array $conv, array $reply, array $activity, array $orders, array $team_names ): array {
		$assigned = $reply['assigned'] ?? 0;
		$replied  = $reply['replied'] ?? 0;
		$rate = $assigned >= self::MIN_SAMPLE_FOR_RATE ? round( $replied / max( 1, $assigned ) * 100, 1 ) : null;
		$conv_to_order = $assigned >= self::MIN_SAMPLE_FOR_RATE ? round( ( $orders['orders'] ?? 0 ) / max( 1, $assigned ) * 100, 1 ) : null;
		$frt_median_seconds = self::median( $reply['frt_seconds'] ?? array() );
		$team_id = BizCity_CRM_Staff_Policy::primary_team( $user_id );
		$user = get_userdata( $user_id );
		return array(
			'user_id'         => $user_id,
			'display_name'    => $user ? (string) $user->display_name : ( '#' . $user_id ),
			'role'            => ( $role = BizCity_CRM_Staff_Policy::role( $user_id ) ),
			// PHASE-0.56 B-1 — server-owned label so every reader (dashboard table, tree view) shows the
			// same Vietnamese role name instead of each FE re-declaring its own `ROLE_LABEL` map.
			'role_label'      => BizCity_CRM_Staff_Policy::label( $role ),
			'team_id'         => $team_id,
			'team_name'       => $team_id ? ( $team_names[ $team_id ] ?? '' ) : '',
			'online'          => (bool) ( $activity['online'] ?? false ),
			'silent_minutes'  => $activity['silent_minutes'] ?? null,
			'silent_alert'    => ( $activity['silent_minutes'] ?? 0 ) >= self::SILENT_MINUTES_ALERT && ( $conv['open'] ?? 0 ) > 0,
			'open'            => (int) ( $conv['open'] ?? 0 ),
			'breach'          => (int) ( $conv['breach'] ?? 0 ),
			'assigned'        => $assigned,
			'replied'         => $replied,
			'reply_rate'      => $rate,
			'frt_minutes'     => null !== $frt_median_seconds ? round( $frt_median_seconds / 60, 1 ) : null,
			'orders'          => (int) ( $orders['orders'] ?? 0 ),
			'paid'            => (int) ( $orders['paid'] ?? 0 ),
			'conv_to_order'   => $conv_to_order,
			'revenue'         => (float) ( $orders['revenue'] ?? 0 ),
			'revenue_estimated' => (float) ( $orders['revenue_estimated'] ?? 0 ),
		);
	}

	/**
	 * [2026-09-18] R-ZP-DUP DUP-12 — one place that turns a local Zalo account row into the phone shape
	 * both the Staff roster and the Team Dashboard return, so the two tabs and the Inbox rail agree:
	 * a logged-out row whose Zalo login is connected on another row becomes `duplicate` (no QR offer),
	 * a deleted row is `revoked` (not dead, no QR offer). Twin detection runs once per request.
	 *
	 * @return array{status:string,dead:bool,can_relogin:bool,duplicate_of_inbox_id?:int,duplicate_of_label?:string}
	 */
	private static function phone_state( array $account ): array {
		static $twins = null;
		if ( null === $twins ) {
			$twins = ( class_exists( 'BizCity_Zalo_Duplicate_Guard' ) && method_exists( 'BizCity_Zalo_Duplicate_Guard', 'find_twins' ) && class_exists( 'BizCity_Zalo_Mapping_Repo' ) )
				? BizCity_Zalo_Duplicate_Guard::find_twins( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) ) )
				: array();
		}
		$status   = sanitize_key( (string) ( $account['status'] ?? 'unknown' ) );
		$inbox_id = (int) ( $account['crm_inbox_id'] ?? 0 );
		if ( $inbox_id > 0 && isset( $twins[ $inbox_id ] ) ) {
			return array(
				'status'                => 'duplicate',
				'dead'                  => true,
				'can_relogin'           => false,
				'duplicate_of_inbox_id' => (int) $twins[ $inbox_id ]['crm_inbox_id'],
				'duplicate_of_label'    => sanitize_text_field( (string) $twins[ $inbox_id ]['label'] ),
				'phone_key'             => self::phone_key( $account ),
				'can'                   => self::phone_capabilities( $account, $status ),
			);
		}
		$relogin_states = array( 'expired', 'logged_out', 'session_disconnected', 'superseded' );
		$dead = in_array( $status, $relogin_states, true );
		// [2026-09-19 Johnny Chu] PHASE-0.56 B-1 — `pending_qr` (account created, nobody has scanned yet)
		// is NOT a dead session (it must not count toward the "SĐT hết phiên" KPI/dashboard `dead_sessions`,
		// which stays keyed off `dead` below), but the rail/dashboard still needs a way to reopen the QR sheet
		// for it — matches the `showQrButton = i.can_relogin || session_state==='pending_qr'` client-side
		// patch already shipped in `ChannelSidebar.jsx` (0.53 §10.9); doing it here lets every reader
		// (dashboard, tree view, roster) agree without re-deriving the same OR client-side.
		$can_relogin = $dead || ( 'pending_qr' === $status );
		return array( 'status' => $status, 'dead' => $dead, 'can_relogin' => $can_relogin, 'phone_key' => self::phone_key( $account ), 'can' => self::phone_capabilities( $account, $status ) );
	}

	private static function phone_key( array $account ): string {
		$label = preg_replace( '/\D+/', '', (string) ( $account['label'] ?? '' ) );
		return (string) ( $label ?: $account['zalo_uid'] ?? $account['bridge_account_id'] ?? '' );
	}

	private static function phone_capabilities( array $account, string $status ): array {
		$owner = (int) ( $account['owner_user_id'] ?? 0 );
		$actor = get_current_user_id();
		$can_qr = BizCity_CRM_Staff_Policy::can( $actor, 'phone.qr', $owner )['ok'];
		$can_recover = BizCity_CRM_Staff_Policy::can( $actor, 'phone.recover', $owner )['ok'];
		$can_remove = BizCity_CRM_Staff_Policy::can( $actor, 'phone.assign', $owner )['ok'];
		return array( 'qr' => $can_qr, 'recover' => $can_recover, 'remove' => $can_remove, 'recreate' => $can_remove && in_array( $status, array( 'duplicate', 'account_not_owned' ), true ) );
	}

	/**
	 * Zalo Personal phones per employee with a `dead` flag, from the local synced status column
	 * (no bridge call). Labels only — the raw phone/uid never leaves the server.
	 *
	 * @return array<int,array{phones:array<int,array{inbox_id:int,label:string,status:string,dead:bool}>,dead:int}>
	 */
	private static function fetch_phone_summaries( array $user_ids ): array {
		$out = array();
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) { return $out; }
		foreach ( $user_ids as $uid ) {
			$phones = array();
			$dead = 0;
			foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( (int) $uid ) as $account ) {
				$state = self::phone_state( $account );
				if ( $state['dead'] ) { $dead++; }
				$phones[] = array_merge( array(
					'inbox_id' => (int) ( $account['crm_inbox_id'] ?? 0 ),
					'label'    => sanitize_text_field( (string) ( $account['label'] ?? '' ) ),
				), $state );
			}
			$out[ (int) $uid ] = array( 'phones' => $phones, 'dead' => $dead );
		}
		return $out;
	}

	/**
	 * T2-07 sparkline — conversations each employee answered per day (distinct conversations
	 * with ≥1 outgoing message by them), oldest → today, one query.
	 *
	 * @return array<int,array<int,int>>
	 */
	private static function fetch_daily_handled( array $user_ids, int $days ): array {
		global $wpdb;
		$out = array();
		if ( empty( $user_ids ) ) { return $out; }
		$msg = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$today_ts = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' );
		$from = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $days - 1 ) . ' days', $today_ts ) );
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT responder_user_id AS user_id, DATE(created_at) AS d, COUNT(DISTINCT conversation_id) AS c
			 FROM `{$msg}`
			 WHERE message_type = 'outgoing' AND responder_user_id IN ({$placeholders}) AND created_at >= %s
			 GROUP BY responder_user_id, DATE(created_at)",
			array_merge( array_map( 'intval', $user_ids ), array( $from ) )
		), ARRAY_A );
		$index = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$index[ gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 - $i ) . ' days', $today_ts ) ) ] = $i;
		}
		foreach ( $user_ids as $uid ) { $out[ (int) $uid ] = array_fill( 0, $days, 0 ); }
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$uid = (int) $row['user_id'];
			if ( isset( $index[ $row['d'] ], $out[ $uid ] ) ) { $out[ $uid ][ $index[ $row['d'] ] ] = (int) $row['c']; }
		}
		return $out;
	}

	public static function get_team_inbox_dashboard( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'team.dashboard' );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$range_key = sanitize_key( (string) ( $req->get_param( 'range' ) ?? 'today' ) );
		$range = self::dashboard_range( in_array( $range_key, array( 'today', '7d', '30d' ), true ) ? $range_key : 'today' );

		$user_ids   = self::dashboard_scope( $actor_id );
		$team_names = self::team_names();
		// T2-03 — team filter; a non-admin's scope is already their own team, so the filter only narrows.
		$team_filter = max( 0, (int) $req->get_param( 'team_id' ) );
		if ( $team_filter > 0 ) {
			$user_ids = array_values( array_filter( $user_ids, static function ( $uid ) use ( $team_filter ) {
				return BizCity_CRM_Staff_Policy::primary_team( (int) $uid ) === $team_filter;
			} ) );
		}
		$is_admin_scope = null === BizCity_CRM_Staff_Policy::visible_user_ids( $actor_id );
		$teams_out = array();
		foreach ( $team_names as $tid => $tname ) {
			if ( $is_admin_scope || BizCity_CRM_Staff_Policy::primary_team( $actor_id ) === (int) $tid ) {
				$teams_out[] = array( 'id' => (int) $tid, 'name' => $tname );
			}
		}
		$phones_by_user = self::fetch_phone_summaries( $user_ids );
		$spark_by_user  = self::fetch_daily_handled( $user_ids, 7 );
		$conv_agg   = self::fetch_conversation_aggregates( $user_ids );
		$reply_agg  = self::fetch_reply_aggregates( $user_ids, $range['from'], $range['to'] );
		$activity_agg = self::fetch_activity_aggregates( $user_ids );
		$order_agg  = self::fetch_order_aggregates( $user_ids, $range['from'], $range['to'] );

		$employees = array();
		foreach ( $user_ids as $uid ) {
			if ( ! BizCity_CRM_Staff_Policy::is_assignable_user( $uid ) ) { continue; }
			$row = self::shape_dashboard_row(
				$uid,
				$conv_agg[ $uid ] ?? array(),
				$reply_agg[ $uid ] ?? array(),
				$activity_agg[ $uid ] ?? array(),
				$order_agg[ $uid ] ?? array(),
				$team_names
			);
			$row['phones']      = $phones_by_user[ $uid ]['phones'] ?? array();
			$row['phones_dead'] = $phones_by_user[ $uid ]['dead'] ?? 0;
			$row['spark_7d']    = $spark_by_user[ $uid ] ?? array_fill( 0, 7, 0 );
			// PHASE-0.56 B-1 — one `can{}` hint per row so the FE knows which row-level actions to offer
			// (Bảng điều hành đội v2 §4.1/§4.7); the mutation endpoints re-check `Staff_Policy::can()`
			// themselves, this is UI hinting only, never trusted as the ACL (R-USER-INBOX-SPINE).
			$row['can'] = array(
				'add_phone'      => $uid === $actor_id || BizCity_CRM_Staff_Policy::can( $actor_id, 'phone.add_for_other', $uid )['ok'],
				'link_bot'       => BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.link_bot', $uid )['ok'],
				'transfer_phone' => $uid === $actor_id || BizCity_CRM_Staff_Policy::can( $actor_id, 'phone.assign', $uid )['ok'],
				'move_team'      => BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.update_role', $uid )['ok'],
			);
			$employees[] = $row;
		}
		// PHASE-0.56 B-1 — reuse the same helper `?with=zalo_bot` already attaches to the roster
		// (`get_roster()`), so the dashboard and the staff list never disagree on who is linked.
		$employees = self::attach_zalo_bot_linked( $employees );
		foreach ( $employees as &$row ) {
			$row['zalo_bot'] = null === $row['zalo_bot_linked'] ? null : ( $row['zalo_bot_linked'] ? 'linked' : 'none' );
		}
		unset( $row );

		// KPI aggregates + "Cần xử lý ngay" — built from the same rows, not a
		// second query, so the summary and the table can never disagree.
		$online = 0; $breach_total = 0; $orders_total = 0; $paid_total = 0; $revenue_total = 0.0; $revenue_estimated_total = 0.0; $assigned_total = 0;
		$all_frt = array();
		$actions = array();
		foreach ( $employees as $row ) {
			if ( $row['online'] ) { $online++; }
			$breach_total += $row['breach'];
			$orders_total += $row['orders'];
			$paid_total   += $row['paid'];
			$revenue_total += $row['revenue'];
			$revenue_estimated_total += $row['revenue_estimated'];
			$assigned_total += $row['assigned'];
			if ( null !== $row['frt_minutes'] ) { $all_frt[] = $row['frt_minutes'] * 60; }
			if ( $row['breach'] >= 4 ) {
				$actions[] = array( 'type' => 'breach', 'user_id' => $row['user_id'], 'display_name' => $row['display_name'], 'value' => $row['breach'], 'weight' => 100 + $row['breach'] );
			}
			if ( $row['silent_alert'] ) {
				$actions[] = array( 'type' => 'silent', 'user_id' => $row['user_id'], 'display_name' => $row['display_name'], 'value' => $row['silent_minutes'], 'weight' => 60 );
			}
		}
		$dead_sessions = 0;
		$no_channel = 0;
		$no_phone_names = array();
		$no_phone_ids   = array();
		$no_bot_names   = array();
		$no_bot_ids     = array();
		foreach ( $employees as $row ) {
			if ( empty( $row['phones'] ) ) {
				$no_channel++;
				if ( $row['can']['add_phone'] ) { $no_phone_names[] = $row['display_name']; $no_phone_ids[] = $row['user_id']; }
			}
			if ( ! empty( $row['phones'] ) && 'none' === $row['zalo_bot'] && $row['can']['link_bot'] ) {
				$no_bot_names[] = $row['display_name']; $no_bot_ids[] = $row['user_id'];
			}
			foreach ( $row['phones'] as $phone ) {
				if ( $phone['dead'] ) {
					$dead_sessions++;
					$actions[] = array( 'type' => 'duplicate' === $phone['status'] ? 'duplicate_phone' : 'dead_session', 'user_id' => $row['user_id'], 'display_name' => $row['display_name'], 'phone_label' => $phone['label'], 'inbox_id' => $phone['inbox_id'], 'status' => $phone['status'], 'duplicate_of_label' => (string) ( $phone['duplicate_of_label'] ?? '' ), 'weight' => 300 );
				} elseif ( 'pending_qr' === $phone['status'] ) {
					// PHASE-0.56 B-1 — a phone row created but never scanned; lower weight than a truly dead
					// session (it was never live, so no customer message is being missed yet), but still
					// worth a nudge since `dashboard_scope()` can't tell "just created" from "forgotten".
					$actions[] = array( 'type' => 'pending_qr', 'user_id' => $row['user_id'], 'display_name' => $row['display_name'], 'phone_label' => $phone['label'], 'inbox_id' => $phone['inbox_id'], 'status' => $phone['status'], 'weight' => 150 );
				}
			}
		}
		if ( $no_phone_names ) {
			$actions[] = array( 'type' => 'no_phone', 'value' => count( $no_phone_names ), 'display_name' => implode( ', ', $no_phone_names ), 'user_ids' => $no_phone_ids, 'weight' => 40 );
		}
		if ( $no_bot_names ) {
			$actions[] = array( 'type' => 'no_bot', 'value' => count( $no_bot_names ), 'display_name' => implode( ', ', $no_bot_names ), 'user_ids' => $no_bot_ids, 'weight' => 30 );
		}
		usort( $actions, static function ( $a, $b ) { return ( $b['weight'] ?? 0 ) <=> ( $a['weight'] ?? 0 ); } );
		$actions = array_slice( $actions, 0, 5 );

		usort( $employees, static function ( $a, $b ) {
			// Mockup §2.2 "cần chú ý": dead session > waiting past SLA > silent, then name.
			$score = static function ( $r ) { return ( $r['phones_dead'] > 0 ? 1000 : 0 ) + ( $r['silent_alert'] ? 100 : 0 ) + $r['breach']; };
			return ( $score( $b ) <=> $score( $a ) ) ?: strcasecmp( $a['display_name'], $b['display_name'] );
		} );
		$insights = class_exists( 'BizCity_CRM_Team_Insights' )
			? BizCity_CRM_Team_Insights::from_dashboard( array( 'employees' => $employees ) )
			: array();
		$sort = sanitize_key( (string) ( $req->get_param( 'sort' ) ?? 'attention' ) );
		if ( 'revenue' === $sort ) {
			usort( $employees, static function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
		} elseif ( 'conv' === $sort ) {
			usort( $employees, static function ( $a, $b ) { return ( $b['conv_to_order'] ?? -1 ) <=> ( $a['conv_to_order'] ?? -1 ); } );
		}

		return new WP_REST_Response( array(
			'ok'      => true,
			// PHASE-0.56 B-1 — bump so FE can tell whether `can{}`/`zalo_bot`/`role_label`/`no_channel` are
			// present without feature-sniffing individual fields.
			'version' => '1.1.0',
			'range' => $range,
			'kpis'  => array(
				'online'          => $online,
				'total_employees' => count( $employees ),
				'dead_sessions'   => $dead_sessions,
				'breach_total'    => $breach_total,
				'frt_median_minutes' => ( $median = self::median( $all_frt ) ) !== null ? round( $median / 60, 1 ) : null,
				'conv_to_order_pct' => $assigned_total >= self::MIN_SAMPLE_FOR_RATE ? round( $orders_total / max( 1, $assigned_total ) * 100, 1 ) : null,
				'orders_total'    => $orders_total,
				'paid_total'      => $paid_total,
				'revenue_total'   => $revenue_total,
				'revenue_estimated_total' => $revenue_estimated_total,
				'assigned_total'  => $assigned_total,
				'no_channel'      => $no_channel,
			),
			'thresholds' => array( 'wait_minutes' => self::WAIT_MINUTES_BREACH, 'silent_minutes' => self::SILENT_MINUTES_ALERT, 'min_sample' => self::MIN_SAMPLE_FOR_RATE ),
			'teams'     => $teams_out,
			'team_id'   => $team_filter,
			'actions'   => $actions,
			'insights'  => $insights,
			'employees' => array_values( $employees ),
		), 200 );
	}

	/**
	 * PHASE-0.56 G-1 (11.B2 §4.8) — the leader's 7-step onboarding checklist. Gathers facts (gateway key,
	 * team/staff counts, per-staff phone/bot coverage, invite/task usermeta+table checks) then hands them
	 * to {@see self::build_onboarding_steps()}, a pure function with no WP calls, so the step logic itself
	 * is unit-testable without a WP bootstrap (same reasoning as the B-2 `build_attention()` gap this
	 * deliberately does NOT repeat).
	 */
	public static function get_onboarding( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.onboarding' );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$has_gateway_key = class_exists( 'BizCity_LLM_Client' ) && method_exists( 'BizCity_LLM_Client', 'instance' )
			&& '' !== trim( (string) BizCity_LLM_Client::instance()->get_api_key() );

		$team_count  = count( self::team_names() );
		$staff_ids   = self::admin_staff_user_ids();
		$staff_count = count( $staff_ids );

		$staff_with_phone = 0;
		$staff_with_bot   = 0;
		if ( $staff_count > 0 ) {
			$phones_by_user = self::fetch_phone_summaries( $staff_ids );
			$bot_linkable   = class_exists( 'BizCity_Channel_User_Linker' ) && method_exists( 'BizCity_Channel_User_Linker', 'zalo_bot_target_for_user' );
			foreach ( $staff_ids as $uid ) {
				foreach ( (array) ( $phones_by_user[ $uid ]['phones'] ?? array() ) as $phone ) {
					if ( empty( $phone['dead'] ) && 'pending_qr' !== ( $phone['status'] ?? '' ) ) { $staff_with_phone++; break; }
				}
				if ( $bot_linkable && ! empty( BizCity_Channel_User_Linker::zalo_bot_target_for_user( (int) $uid ) ) ) { $staff_with_bot++; }
			}
		}

		$invited = '' !== (string) get_user_meta( $actor_id, self::META_ONBOARDING_INVITED_AT, true );

		$task_count = 0;
		if ( class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			global $wpdb;
			$tasks_t = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
			$task_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$tasks_t}` WHERE created_by = %d AND deleted_at IS NULL", $actor_id ) );
		}

		$onboarding = self::build_onboarding_steps( array(
			'has_gateway_key'  => $has_gateway_key,
			'team_count'       => $team_count,
			'staff_count'      => $staff_count,
			'staff_with_phone' => $staff_with_phone,
			'staff_with_bot'   => $staff_with_bot,
			'invited'          => $invited,
			'task_count'       => $task_count,
		) );

		return new WP_REST_Response( array_merge( array( 'ok' => true ), $onboarding ), 200 );
	}

	/**
	 * Pure step logic for {@see self::get_onboarding()} — every input is a primitive fact already
	 * resolved by the caller, so this never touches `$wpdb`/WP functions and can run in a plain PHP
	 * script (see `tests/unit/CrmStaffOnboardingTest.php`). Matches the `team-ops-board@1.0.0` contract's
	 * `onboarding{steps[{key,done,count?,total?}],next}` shape (docs/PHASE-0.56 §7, §11.A).
	 *
	 * `phone`/`bot` are only `done` once there is at least one staff member AND every one of them has
	 * coverage — an empty team is never reported as "done" for either (nothing to gate on yet, the
	 * `staff` step above it is still the blocker).
	 *
	 * @param array{has_gateway_key:bool,team_count:int,staff_count:int,staff_with_phone:int,staff_with_bot:int,invited:bool,task_count:int} $facts
	 * @return array{steps:array<int,array{key:string,done:bool,count?:int,total?:int}>,next:?string}
	 */
	public static function build_onboarding_steps( array $facts ): array {
		$team_count       = (int) ( $facts['team_count'] ?? 0 );
		$staff_count      = (int) ( $facts['staff_count'] ?? 0 );
		$staff_with_phone = (int) ( $facts['staff_with_phone'] ?? 0 );
		$staff_with_bot   = (int) ( $facts['staff_with_bot'] ?? 0 );
		$task_count       = (int) ( $facts['task_count'] ?? 0 );

		$steps = array(
			array( 'key' => 'plan', 'done' => ! empty( $facts['has_gateway_key'] ) ),
			array( 'key' => 'team', 'done' => $team_count >= 1, 'count' => $team_count, 'total' => 1 ),
			array( 'key' => 'staff', 'done' => $staff_count >= 1, 'count' => $staff_count, 'total' => 1 ),
			array( 'key' => 'phone', 'done' => $staff_count > 0 && $staff_with_phone === $staff_count, 'count' => $staff_with_phone, 'total' => $staff_count ),
			array( 'key' => 'bot', 'done' => $staff_count > 0 && $staff_with_bot === $staff_count, 'count' => $staff_with_bot, 'total' => $staff_count ),
			array( 'key' => 'invite', 'done' => ! empty( $facts['invited'] ) ),
			array( 'key' => 'task', 'done' => $task_count >= 1, 'count' => $task_count, 'total' => 1 ),
		);

		$next = null;
		foreach ( $steps as $step ) {
			if ( ! $step['done'] ) { $next = $step['key']; break; }
		}

		return array( 'steps' => $steps, 'next' => $next );
	}

	/**
	 * PHASE-0.56 G-2 — marks the `invite` onboarding step done and, when `send_via_bot` is truthy,
	 * actually delivers the invite text to every staff member's linked Zalo Bot chat (skipping the
	 * actor themselves and anyone with no linked bot — matches R-LM-8: this never sends anywhere but
	 * a chat the resolver already trusts, and never falls back to any other channel). Marking the step
	 * done does not require the send to succeed for anyone — "trưởng nhóm đã cố gắng mời" is the
	 * onboarding signal, not "mọi người đã nhận được tin".
	 */
	public static function post_onboarding_invite( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.onboarding' );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }

		$message = sanitize_textarea_field( (string) ( $req->get_param( 'message' ) ?? '' ) );
		if ( '' === $message ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Thiếu nội dung tin mời.', 'hint' => 'Viết vài dòng hướng dẫn cho nhân viên.', 'help_code' => 'invalid_param_generic' ), 400 );
		}
		$sent = 0;
		$skipped_no_bot = 0;
		if ( ! empty( $req->get_param( 'send_via_bot' ) ) && function_exists( 'bizcity_channel_send' ) && class_exists( 'BizCity_Channel_User_Linker' ) && method_exists( 'BizCity_Channel_User_Linker', 'zalo_bot_target_for_user' ) ) {
			$today = gmdate( 'Ymd' );
			foreach ( self::admin_staff_user_ids() as $uid ) {
				$uid = (int) $uid;
				if ( $uid === $actor_id ) { continue; }
				$target = BizCity_Channel_User_Linker::zalo_bot_target_for_user( $uid );
				$chat_id = (string) ( $target['chat_id'] ?? '' );
				if ( '' === $chat_id ) { $skipped_no_bot++; continue; }
				$result = bizcity_channel_send( $chat_id, $message, 'text', array(
					'source'           => 'crm.onboarding_invite',
					// Idempotent per (recipient, day) — re-clicking "Gửi qua Zalo Bot" the same day
					// (e.g. after adding a couple more staff) does not spam people already reached.
					'idempotency_key'  => 'onboarding_invite_' . $uid . '_' . $today,
				) );
				if ( ! empty( $result['sent'] ) ) { $sent++; }
			}
		}
		update_user_meta( $actor_id, self::META_ONBOARDING_INVITED_AT, current_time( 'mysql' ) );
		return new WP_REST_Response( array( 'ok' => true, 'sent' => $sent, 'skipped_no_bot' => $skipped_no_bot ), 200 );
	}

	public static function get_team_inbox_member( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$subject_id = (int) $req['user_id'];
		// Viewing one member's dashboard detail is bounded by the same rank/team
		// gate as the workspace viewer (`staff.view_workspace`) — except looking
		// at your OWN numbers, which is always allowed ("Việc của tôi", not a
		// manager action; `can()` would otherwise refuse it via SELF_FORBIDDEN).
		if ( $subject_id !== $actor_id ) {
			$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.view_workspace', $subject_id );
			if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		}
		if ( ! BizCity_CRM_Staff_Policy::is_assignable_user( $subject_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy nhân viên.', 'hint' => '', 'help_code' => 'not_found' ), 404 );
		}

		$range_key = sanitize_key( (string) ( $req->get_param( 'range' ) ?? 'today' ) );
		$range = self::dashboard_range( in_array( $range_key, array( 'today', '7d', '30d' ), true ) ? $range_key : 'today' );

		$ids = array( $subject_id );
		$team_names = self::team_names();
		$row = self::shape_dashboard_row(
			$subject_id,
			self::fetch_conversation_aggregates( $ids )[ $subject_id ] ?? array(),
			self::fetch_reply_aggregates( $ids, $range['from'], $range['to'] )[ $subject_id ] ?? array(),
			self::fetch_activity_aggregates( $ids )[ $subject_id ] ?? array(),
			self::fetch_order_aggregates( $ids, $range['from'], $range['to'] )[ $subject_id ] ?? array(),
			$team_names
		);

		// Funnel: only facts that exist — no invented "has need" stage.
		$funnel = array(
			array( 'key' => 'assigned', 'label' => 'Hội thoại mới', 'value' => $row['assigned'] ),
			array( 'key' => 'replied', 'label' => 'Đã phản hồi', 'value' => $row['replied'] ),
			array( 'key' => 'orders', 'label' => 'Tạo đơn', 'value' => $row['orders'] ),
			array( 'key' => 'paid', 'label' => 'Đã thanh toán', 'value' => $row['paid'] ),
		);

		// Hourly inbound/outbound only makes sense for a single day.
		$hourly = array();
		if ( 'today' === $range['range'] ) {
			global $wpdb;
			$msg = BizCity_CRM_DB_Installer_V2::tbl_messages();
			$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$owned_inboxes = array_values( array_unique( array_map( 'intval', self::owned_inbox_ids_by_user( array( $subject_id ) )[ $subject_id ] ?? array() ) ) );
			$ownership_sql = 'c.assignee_id = %d';
			$ownership_params = array( $subject_id );
			if ( ! empty( $owned_inboxes ) ) {
				$ownership_sql .= ' OR ((c.assignee_id IS NULL OR c.assignee_id = 0) AND c.inbox_id IN (' . implode( ',', array_fill( 0, count( $owned_inboxes ), '%d' ) ) . '))';
				$ownership_params = array_merge( $ownership_params, $owned_inboxes );
			}
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT HOUR(m.created_at) AS h,
					SUM(CASE WHEN m.message_type = 'incoming' THEN 1 ELSE 0 END) AS inbound,
					SUM(CASE WHEN m.message_type = 'outgoing' AND m.responder_user_id = %d THEN 1 ELSE 0 END) AS outbound
				 FROM `{$msg}` m
				 INNER JOIN `{$conv}` c ON c.id = m.conversation_id
				 WHERE ({$ownership_sql}) AND m.created_at BETWEEN %s AND %s
				 GROUP BY HOUR(m.created_at)",
				array_merge( array( $subject_id ), $ownership_params, array( $range['from'], $range['to'] ) )
			), ARRAY_A );
			$by_hour = array();
			foreach ( is_array( $rows ) ? $rows : array() as $r ) { $by_hour[ (int) $r['h'] ] = array( 'inbound' => (int) $r['inbound'], 'outbound' => (int) $r['outbound'] ); }
			for ( $h = 0; $h < 24; $h++ ) { $hourly[] = array( 'hour' => $h, 'inbound' => $by_hour[ $h ]['inbound'] ?? 0, 'outbound' => $by_hour[ $h ]['outbound'] ?? 0 ); }
		}

		$phone_summary = self::fetch_phone_summaries( $ids )[ $subject_id ] ?? array( 'phones' => array(), 'dead' => 0 );
		$row['phones']      = $phone_summary['phones'];
		$row['phones_dead'] = $phone_summary['dead'];
		$row['spark_7d']    = self::fetch_daily_handled( $ids, 7 )[ $subject_id ] ?? array_fill( 0, 7, 0 );

		// T5-05 — open conversations where the customer spoke last, longest wait first.
		global $wpdb;
		$conv_t = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$msg_t  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$ct_t   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$ci_t   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		// Contact is resolved through contact_inboxes, same join BizCity_CRM_Repository::list_conversations() uses.
		$owned_inboxes = array_values( array_unique( array_map( 'intval', self::owned_inbox_ids_by_user( array( $subject_id ) )[ $subject_id ] ?? array() ) ) );
		$attention_sql = 'c.assignee_id = %d';
		$attention_params = array( $subject_id );
		if ( ! empty( $owned_inboxes ) ) {
			$attention_sql .= ' OR ((c.assignee_id IS NULL OR c.assignee_id = 0) AND c.inbox_id IN (' . implode( ',', array_fill( 0, count( $owned_inboxes ), '%d' ) ) . '))';
			$attention_params = array_merge( $attention_params, $owned_inboxes );
		}
		$attention_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.inbox_id, ct.name AS contact_name, m.created_at AS waiting_since
			 FROM `{$conv_t}` c
			 INNER JOIN `{$msg_t}` m ON m.id = c.last_message_id AND m.message_type = 'incoming'
			 LEFT JOIN `{$ci_t}` ci ON ci.id = c.contact_inbox_id
			 LEFT JOIN `{$ct_t}` ct ON ct.id = ci.contact_id
				 WHERE c.status = 'open' AND ({$attention_sql})
			 ORDER BY m.created_at ASC
			 LIMIT 10",
			$attention_params
		), ARRAY_A );
		$now_ts = current_time( 'timestamp' );
		$attention = array();
		foreach ( is_array( $attention_rows ) ? $attention_rows : array() as $a ) {
			$since_ts = strtotime( (string) $a['waiting_since'] );
			$minutes = $since_ts ? max( 0, (int) round( ( $now_ts - $since_ts ) / 60 ) ) : null;
			$attention[] = array(
				'conversation_id' => (int) $a['id'],
				'inbox_id'        => (int) $a['inbox_id'],
				'contact_name'    => sanitize_text_field( (string) ( $a['contact_name'] ?? '' ) ),
				'waiting_minutes' => $minutes,
				'breach'          => null !== $minutes && $minutes >= self::WAIT_MINUTES_BREACH,
			);
		}

		return new WP_REST_Response( array(
			'ok'         => true,
			'range'      => $range,
			'employee'   => $row,
			'funnel'     => $funnel,
			'hourly'     => $hourly,
			'attention'  => $attention,
			'can'        => array(
				'view_workspace' => $subject_id !== $actor_id && BizCity_CRM_Staff_Policy::can( $actor_id, 'staff.view_workspace', $subject_id )['ok'],
				'phone_qr'       => BizCity_CRM_Staff_Policy::can( $actor_id, 'phone.qr', $subject_id )['ok'],
			),
			'thresholds' => array( 'wait_minutes' => self::WAIT_MINUTES_BREACH ),
			'scope_rule' => 'assignee_or_owned_unassigned',
			'generated_at' => current_time( 'c' ),
		), 200 );
	}

	// ── DELETE /inboxes/{id}/members/{user_id} ──────────────────────────

	public static function remove_inbox_member( WP_REST_Request $req ) {
		$actor_id = get_current_user_id();
		$inbox_id = (int) $req['id'];
		$subject_id = (int) $req['user_id'];
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'inbox.member', $subject_id );
		if ( ! $decision['ok'] ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) || ! BizCity_CRM_Inbox_Access::can_view_inbox( $inbox_id, $actor_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'different_team', 'message' => 'Inbox này không thuộc phạm vi của bạn.', 'hint' => '', 'help_code' => 'member_not_manageable' ), 403 );
		}
		$ok = BizCity_CRM_Team_Manager::remove_inbox_member( $inbox_id, $subject_id );
		if ( ! $ok ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'update_failed', 'message' => 'Không gỡ được nhân viên khỏi inbox.', 'hint' => 'Thử lại sau.', 'help_code' => 'invalid_param_generic' ), 500 );
		}
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_inbox_member', $subject_id, 'deleted', array( 'inbox_id' => $inbox_id ), null, array( 'user_id' => $actor_id ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'inbox_id' => $inbox_id, 'user_id' => $subject_id ), 200 );
	}
}
