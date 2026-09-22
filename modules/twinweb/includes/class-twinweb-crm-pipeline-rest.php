<?php
/**
 * Twin GPT — member customer pipeline REST (PHASE-0.52, R-PIPE-7, C surface `/gpt/`).
 *
 * The principal is always `BizCity_TwinWeb_Identity::current()`; `user_id|owner|member|uid` selectors are ignored.
 * Every customer is limited to the member's OWN inbox scope (`resolve_scope(uid,'c')`). No colleague names,
 * no ranking. Reads/writes go through the CRM owners {@see BizCity_CRM_Customer_Pipeline} and
 * {@see BizCity_CRM_Pipeline_Stage_Service}.
 *
 * Routes (namespace bizcity-twinweb/v1):
 *   GET  /crm/pipeline                              — "Khách của tôi" board        member-pipeline@1.0.0
 *   GET  /crm/pipeline/today                        — "Hôm nay" queue (5 groups)
 *   GET  /crm/pipeline/contacts/{id}                — stage detail (steps, next step, history — no colleague names)
 *   GET  /crm/pipeline/conversation/{id}            — same, resolved from one of my conversations (Inbox toolbar)
 *   POST /crm/pipeline/contacts/{id}/stage          — change stage / tick steps / log outcome   pipeline-stage-change@1.0.0
 *   GET  /crm/me/space                              — "Không gian của tôi"             member-space@1.0.0
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since PHASE-0.52 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_CRM_Pipeline_REST', false ) ) { return; }

class BizCity_TwinWeb_CRM_Pipeline_REST {

	const NS = 'bizcity-twinweb/v1';
	const IGNORED_SELECTORS = array( 'user_id', 'owner', 'owner_id', 'member', 'member_id', 'uid', 'assignee_id' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	public function register_routes() {
		$open = '__return_true';
		register_rest_route( self::NS, '/crm/pipeline', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_board' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/crm/pipeline/today', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_today' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/crm/pipeline/contacts/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_contact' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/crm/pipeline/conversation/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_by_conversation' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/crm/pipeline/contacts/(?P<id>\d+)/stage', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'post_stage' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/crm/me/space', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_space' ), 'permission_callback' => $open ) );
		// [2026-09-19] PHASE-0.55 A5 — numbers-only digest for automation to poll on its own schedule (D55-4, no fixed time in code).
		register_rest_route( self::NS, '/crm/me/work-digest', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_work_digest' ), 'permission_callback' => $open ) );
	}

	// ── handlers ─────────────────────────────────────────────────────────

	public function get_board( WP_REST_Request $request ) {
		$uid = $this->member_id( $request, 'crm/pipeline' );
		if ( $uid instanceof WP_REST_Response ) { return $uid; }
		$rows = BizCity_CRM_Customer_Pipeline::rows( BizCity_CRM_Customer_Pipeline::contact_ids_for_inboxes( BizCity_CRM_Customer_Pipeline::c_inbox_ids( $uid ) ) );
		$board = BizCity_CRM_Customer_Pipeline::board( $rows, array( 'with_owner' => false, 'sample' => 60, 'range_days' => 30 ) );
		unset( $board['matrix'] );
		foreach ( $board['columns'] as $i => $col ) { $board['columns'][ $i ]['cards'] = self::with_thread_refs( $col['cards'] ); }
		return rest_ensure_response( array_merge( array( 'success' => true, 'contract' => 'member-pipeline', 'version' => '1.0.0', 'surface' => 'C_PUBLIC_TWINGPT', 'as_of' => current_time( 'c' ) ), $board ) );
	}

	/**
	 * "Hôm nay": overdue → due today → assigned by the leader → new customers never contacted → coming up.
	 */
	public function get_today( WP_REST_Request $request ) {
		$uid = $this->member_id( $request, 'crm/pipeline/today' );
		if ( $uid instanceof WP_REST_Response ) { return $uid; }
		$groups = self::today_groups( $uid );
		return rest_ensure_response( array( 'success' => true, 'surface' => 'C_PUBLIC_TWINGPT', 'as_of' => current_time( 'c' ), 'groups' => $groups, 'outcomes' => $this->outcomes() ) );
	}

	/**
	 * Shared "Hôm nay" grouping — same grouping the FE board renders, reused by the
	 * Brain Chat `work_my_today` tool (PHASE-0.55 A2) so both surfaces read one rule.
	 *
	 * @return array{overdue:array,today:array,assigned:array,new:array,later:array}
	 */
	public static function today_groups( int $uid, int $per_group = 50 ): array {
		$rows = BizCity_CRM_Customer_Pipeline::rows( BizCity_CRM_Customer_Pipeline::contact_ids_for_inboxes( BizCity_CRM_Customer_Pipeline::c_inbox_ids( $uid ) ) );
		$today = current_time( 'Y-m-d' );
		$assigned = array();
		foreach ( class_exists( 'BizCity_CRM_Task_Handoff' ) ? BizCity_CRM_Task_Handoff::list_for_member( $uid, 'open', 200 ) : array() as $task ) {
			$cid = (int) ( $task['subjects'][0]['contact_id'] ?? 0 );
			if ( $cid > 0 && ! isset( $assigned[ $cid ] ) ) { $assigned[ $cid ] = $task; }
		}
		$groups = array( 'overdue' => array(), 'today' => array(), 'assigned' => array(), 'new' => array(), 'later' => array() );
		foreach ( $rows as $cid => $r ) {
			if ( in_array( $r['stage'], array( 'lost' ), true ) ) { continue; }
			$card = BizCity_CRM_Customer_Pipeline::card( $r, false );
			$card['task'] = isset( $assigned[ $cid ] ) ? $assigned[ $cid ] : null;
			if ( $r['overdue_tasks'] > 0 ) { $groups['overdue'][] = $card; }
			elseif ( $r['next_due'] && $r['next_due'] <= $today ) { $groups['today'][] = $card; }
			elseif ( isset( $assigned[ $cid ] ) ) { $groups['assigned'][] = $card; }
			elseif ( 'target' === $r['stage'] && 0 === (int) $r['first_out_ts'] ) { $groups['new'][] = $card; }
			elseif ( $r['next_due'] ) { $groups['later'][] = $card; }
		}
		foreach ( $groups as $key => $list ) {
			usort( $list, static function ( $a, $b ) { return ( $b['overdue_tasks'] <=> $a['overdue_tasks'] ) ?: ( $b['days'] <=> $a['days'] ); } );
			$groups[ $key ] = self::with_thread_refs( array_slice( $list, 0, $per_group ) );
		}
		return $groups;
	}

	public function get_contact( WP_REST_Request $request ) {
		$uid = $this->member_id( $request, 'crm/pipeline/contacts' );
		if ( $uid instanceof WP_REST_Response ) { return $uid; }
		return $this->detail_response( $uid, absint( $request->get_param( 'id' ) ) );
	}

	public function get_by_conversation( WP_REST_Request $request ) {
		$uid = $this->member_id( $request, 'crm/pipeline/conversation' );
		if ( $uid instanceof WP_REST_Response ) { return $uid; }
		global $wpdb;
		$conv_t = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ci_t = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT c.inbox_id, ci.contact_id FROM `{$conv_t}` c INNER JOIN `{$ci_t}` ci ON ci.id = c.contact_inbox_id WHERE c.id = %d", absint( $request->get_param( 'id' ) ) ), ARRAY_A );
		$inboxes = BizCity_CRM_Customer_Pipeline::c_inbox_ids( $uid );
		if ( ! $row || ! in_array( (int) $row['inbox_id'], $inboxes, true ) ) {
			return $this->error( 'contact_not_in_scope', 'Không tìm thấy khách trong kênh của bạn.', '', 404 );
		}
		return $this->detail_response( $uid, (int) $row['contact_id'] );
	}

	public function post_stage( WP_REST_Request $request ) {
		$uid = $this->member_id( $request, 'crm/pipeline/stage' );
		if ( $uid instanceof WP_REST_Response ) { return $uid; }
		$body = $request->get_json_params();
		$result = BizCity_CRM_Pipeline_Stage_Service::change( $uid, absint( $request->get_param( 'id' ) ), is_array( $body ) ? $body : array(), 'c' );
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return $this->error( $result->get_error_code(), $result->get_error_message(), (string) ( $data['hint'] ?? '' ), (int) ( $data['status'] ?? 400 ) );
		}
		if ( class_exists( 'BizCity_CRM_Pipeline_REST' ) ) { BizCity_CRM_Pipeline_REST::bust_board_cache(); }
		return rest_ensure_response( array_merge( array( 'success' => true, 'contract' => 'pipeline-stage-change', 'version' => '1.0.0', 'surface' => 'C_PUBLIC_TWINGPT' ), $result ) );
	}

	public function get_space( WP_REST_Request $request ) {
		$uid = $this->member_id( $request, 'crm/me/space' );
		if ( $uid instanceof WP_REST_Response ) { return $uid; }
		return rest_ensure_response( array_merge( array( 'success' => true, 'contract' => 'member-space', 'version' => '1.0.0', 'surface' => 'C_PUBLIC_TWINGPT', 'read_only' => false, 'goal_set_by_leader' => true ), BizCity_CRM_Customer_Pipeline::space( $uid ) ) );
	}

	/**
	 * "Tóm tắt việc của tôi" (§5.6, PHASE-0.55 A5) — numbers + one link only, no
	 * customer name/phone, no colleague data. Meant to be polled by the site's
	 * own automation on whatever schedule it picks (D55-4: no fixed hour/count
	 * baked in here) to build a morning digest or any other reminder.
	 */
	public function get_work_digest( WP_REST_Request $request ) {
		$uid = $this->member_id( $request, 'crm/me/work-digest' );
		if ( $uid instanceof WP_REST_Response ) { return $uid; }

		$groups = self::today_groups( $uid );
		$tasks_open = class_exists( 'BizCity_CRM_Task_Handoff' ) ? BizCity_CRM_Task_Handoff::list_for_member( $uid, 'open', 200 ) : array();
		$tasks_overdue = 0;
		foreach ( $tasks_open as $t ) { if ( ! empty( $t['overdue'] ) ) { $tasks_overdue++; } }
		$space = BizCity_CRM_Customer_Pipeline::space( $uid );
		$goal  = is_array( $space['goal'] ?? null ) ? $space['goal'] : null;

		return rest_ensure_response( array(
			'success'   => true,
			'contract'  => 'member-work-digest',
			'version'   => '1.0.0',
			'surface'   => 'C_PUBLIC_TWINGPT',
			'as_of'     => current_time( 'c' ),
			'tasks'     => array(
				'open'    => count( $tasks_open ),
				'overdue' => $tasks_overdue,
				'unseen'  => class_exists( 'BizCity_CRM_Task_Handoff' ) ? BizCity_CRM_Task_Handoff::count_unseen_for_member( $uid ) : 0,
			),
			'customers' => array(
				'due_today'              => count( $groups['today'] ?? array() ),
				'overdue'                => count( $groups['overdue'] ?? array() ),
				'conversations_awaiting' => self::conversations_awaiting_reply( $uid ),
			),
			'goal' => $goal ? array(
				'month'      => (string) $goal['month'],
				'won_target' => (int) $goal['won_target'],
				'won_month'  => (int) ( $space['won_month'] ?? 0 ),
			) : null,
			'link' => esc_url_raw( home_url( '/gpt/mytasks/' ) ),
		) );
	}

	/** Open conversations in the member's own inbox scope still awaiting a reply. */
	private static function conversations_awaiting_reply( int $uid ): int {
		if ( ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return 0; }
		$inbox_ids = BizCity_CRM_Customer_Pipeline::c_inbox_ids( $uid );
		if ( empty( $inbox_ids ) ) { return 0; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$placeholders = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$tbl}` WHERE status = 'open' AND unread_count > 0 AND inbox_id IN ({$placeholders})",
			$inbox_ids
		) );
	}

	// ── helpers ──────────────────────────────────────────────────────────

	private function detail_response( int $uid, int $contact_id ) {
		if ( ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, BizCity_CRM_Customer_Pipeline::c_inbox_ids( $uid ) ) ) {
			return $this->error( 'contact_not_in_scope', 'Không tìm thấy khách trong kênh của bạn.', '', 404 );
		}
		$detail = BizCity_CRM_Customer_Pipeline::detail( $contact_id, false );
		if ( ! $detail ) { return $this->error( 'contact_not_in_scope', 'Không tìm thấy khách.', '', 404 ); }
		return rest_ensure_response( array_merge( array( 'success' => true, 'surface' => 'C_PUBLIC_TWINGPT', 'as_of' => current_time( 'c' ), 'outcomes' => $this->outcomes() ), $detail ) );
	}

	/**
	 * Add `channel` + `ref` (the member's own inbox account) so "Mở hội thoại" can open
	 * /gpt/crm/?channel=&ref=&conversation_id=. Cards are already limited to the member's own inboxes.
	 */
	private static function with_thread_refs( array $cards ): array {
		global $wpdb;
		$ids = array();
		foreach ( $cards as $c ) { if ( ! empty( $c['inbox_id'] ) ) { $ids[ (int) $c['inbox_id'] ] = true; } }
		$map = array();
		if ( $ids ) {
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
			$keys = array_keys( $ids );
			$ph = implode( ',', array_fill( 0, count( $keys ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, channel_type, channel_ref_id FROM `{$tbl}` WHERE id IN ({$ph})", $keys ), ARRAY_A ) as $r ) {
				$map[ (int) $r['id'] ] = array( sanitize_key( (string) $r['channel_type'] ), (string) $r['channel_ref_id'] );
			}
		}
		foreach ( $cards as $i => $c ) {
			$ref = $map[ (int) ( $c['inbox_id'] ?? 0 ) ] ?? null;
			$cards[ $i ]['channel'] = $ref ? $ref[0] : null;
			$cards[ $i ]['ref'] = $ref ? $ref[1] : null;
		}
		return $cards;
	}

	private function outcomes(): array {
		$out = array();
		foreach ( BizCity_CRM_Pipeline_Stage_Service::OUTCOMES as $code => $o ) {
			$out[] = array( 'code' => $code, 'label' => BizCity_CRM_Pipeline_Stage_Service::outcome_label( $code ), 'to' => $o['to'], 'next' => $o['next'], 'days' => $o['days'] );
		}
		return $out;
	}

	/** @return int|WP_REST_Response */
	private function member_id( WP_REST_Request $request, string $route ) {
		$identity = class_exists( 'BizCity_TwinWeb_Identity' ) ? BizCity_TwinWeb_Identity::current() : array();
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		if ( ! empty( $identity['is_guest'] ) || $user_id <= 0 ) {
			return $this->error( 'auth_required', 'Bạn cần đăng nhập.', 'Đăng nhập vào Twin GPT rồi thử lại.', 401 );
		}
		if ( ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) || ! class_exists( 'BizCity_CRM_Pipeline_Stage_Service' ) ) {
			return $this->error( 'module_not_loaded', 'Pipeline khách chưa sẵn sàng.', 'Bật module CRM rồi tải lại trang.', 503 );
		}
		foreach ( self::IGNORED_SELECTORS as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				do_action( 'bizcity_twinweb_reason_bucket', 'c_user_selector_ignored', array( 'route' => $route ) );
				break;
			}
		}
		return $user_id;
	}

	private function error( $code, $message, $hint, $status ) {
		return new WP_REST_Response( array( 'success' => false, 'code' => (string) $code, 'message' => (string) $message, 'hint' => (string) $hint, 'help_code' => (string) $code ), (int) $status );
	}
}
