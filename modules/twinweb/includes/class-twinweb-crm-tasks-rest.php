<?php
/**
 * Twin GPT — member "Việc được giao" REST (PHASE-0.50 W5, R-LEADER-MEMBER R-LM-3/R-LM-5).
 *
 * Surface `C_PUBLIC_TWINGPT` (`/gpt/crm/`). The principal is always
 * `BizCity_TwinWeb_Identity::current()`; any `user_id|owner|member|uid` in the
 * request is ignored. Work is created by leaders on B2 (`/crm/`, `/twin/`) and
 * stored by the CRM owner {@see BizCity_CRM_Task_Handoff}; this file only exposes
 * the member projection and the member transitions.
 *
 * Routes (namespace bizcity-twinweb/v1):
 *   GET  /crm/tasks                      — my assigned work (status=open|today|overdue|done|all, contact_id)
 *   GET  /crm/tasks/summary              — badge counts
 *   GET  /crm/tasks/{id}                 — one task (marks it seen)
 *   POST /crm/tasks/{id}/transition      — { action: accept|start|complete|return, reason_code?, note? }
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since PHASE-0.50 2026-09-17
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_CRM_Tasks_REST', false ) ) { return; }

class BizCity_TwinWeb_CRM_Tasks_REST {

	const NS = 'bizcity-twinweb/v1';

	const ACTION_TO_STATUS = array(
		'accept'   => 'accepted',
		'start'    => 'in_progress',
		'complete' => 'done',
		'return'   => 'returned',
	);

	/** Browser selectors that must never change the principal on C. */
	const IGNORED_SELECTORS = array( 'user_id', 'owner', 'owner_id', 'member', 'member_id', 'uid', 'assignee_id' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		register_rest_route( self::NS, '/crm/tasks', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_tasks' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'status'     => array( 'type' => 'string', 'default' => 'open', 'sanitize_callback' => 'sanitize_key' ),
				'contact_id' => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );
		register_rest_route( self::NS, '/crm/tasks/summary', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_summary' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/crm/tasks/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_task' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/crm/tasks/(?P<id>\d+)/transition', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'post_transition' ),
			'permission_callback' => '__return_true',
		) );
	}

	// ── handlers ─────────────────────────────────────────────────────────

	public function get_tasks( WP_REST_Request $request ) {
		$member_id = $this->member_id( $request );
		if ( $member_id instanceof WP_REST_Response ) { return $member_id; }
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! in_array( $status, array( 'open', 'today', 'overdue', 'done', 'all' ), true ) ) { $status = 'open'; }
		$tasks = BizCity_CRM_Task_Handoff::list_for_member( $member_id, $status, 100, absint( $request->get_param( 'contact_id' ) ) );
		return rest_ensure_response( array(
			'success'  => true,
			'contract' => BizCity_CRM_Task_Handoff::CONTRACT . '@' . BizCity_CRM_Task_Handoff::VERSION,
			'surface'  => 'C_PUBLIC_TWINGPT',
			'as_of'    => current_time( 'c' ),
			'status'   => $status,
			'tasks'    => array_values( array_filter( $tasks ) ),
			'unseen'   => BizCity_CRM_Task_Handoff::count_unseen_for_member( $member_id ),
		) );
	}

	public function get_summary( WP_REST_Request $request ) {
		$member_id = $this->member_id( $request );
		if ( $member_id instanceof WP_REST_Response ) { return $member_id; }
		$open    = BizCity_CRM_Task_Handoff::list_for_member( $member_id, 'open', 200 );
		$overdue = 0;
		foreach ( $open as $task ) { if ( ! empty( $task['overdue'] ) ) { $overdue++; } }
		return rest_ensure_response( array(
			'success' => true,
			'surface' => 'C_PUBLIC_TWINGPT',
			'open'    => count( $open ),
			'overdue' => $overdue,
			'unseen'  => BizCity_CRM_Task_Handoff::count_unseen_for_member( $member_id ),
		) );
	}

	public function get_task( WP_REST_Request $request ) {
		$member_id = $this->member_id( $request );
		if ( $member_id instanceof WP_REST_Response ) { return $member_id; }
		$task_id = absint( $request->get_param( 'id' ) );
		$row = BizCity_CRM_Task_Handoff::get_row( $task_id );
		// Not-found and not-mine are the same answer (no enumeration).
		if ( ! $row || (int) $row['assignee_id'] !== $member_id || (int) $row['created_by'] === $member_id ) {
			return $this->error( 'task_not_found', 'Không tìm thấy việc.', 'Việc có thể đã bị huỷ hoặc chuyển cho người khác.', 404 );
		}
		BizCity_CRM_Task_Handoff::mark_seen( $member_id, $task_id );
		return rest_ensure_response( array(
			'success' => true,
			'surface' => 'C_PUBLIC_TWINGPT',
			'task'    => BizCity_CRM_Task_Handoff::shape_c( BizCity_CRM_Task_Handoff::get_row( $task_id ), $member_id ),
		) );
	}

	public function post_transition( WP_REST_Request $request ) {
		$member_id = $this->member_id( $request );
		if ( $member_id instanceof WP_REST_Response ) { return $member_id; }
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$action = sanitize_key( (string) ( $body['action'] ?? '' ) );
		if ( ! isset( self::ACTION_TO_STATUS[ $action ] ) ) {
			return $this->error( 'invalid_param', 'Thao tác không hợp lệ.', 'Chọn nhận việc, bắt đầu, hoàn thành hoặc trả lại.', 422 );
		}
		$result = BizCity_CRM_Task_Handoff::member_transition(
			$member_id,
			absint( $request->get_param( 'id' ) ),
			self::ACTION_TO_STATUS[ $action ],
			(string) ( $body['reason_code'] ?? '' ),
			(string) ( $body['note'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return $this->error( $result->get_error_code(), $result->get_error_message(), '', (int) ( $data['status'] ?? 400 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'surface' => 'C_PUBLIC_TWINGPT', 'task' => $result ) );
	}

	// ── helpers ──────────────────────────────────────────────────────────

	/**
	 * Canonical member principal, or an error response. Never reads a user id from the request.
	 *
	 * @return int|WP_REST_Response
	 */
	private function member_id( WP_REST_Request $request ) {
		$identity = class_exists( 'BizCity_TwinWeb_Identity' ) ? BizCity_TwinWeb_Identity::current() : array();
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		if ( ! empty( $identity['is_guest'] ) || $user_id <= 0 ) {
			return $this->error( 'auth_required', 'Bạn cần đăng nhập để xem việc được giao.', 'Đăng nhập vào Twin GPT rồi thử lại.', 401 );
		}
		if ( ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
			return $this->error( 'module_not_loaded', 'Việc được giao chưa sẵn sàng.', 'Bật module CRM rồi tải lại trang.', 503 );
		}
		foreach ( self::IGNORED_SELECTORS as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				/** R-LEADER-MEMBER R-LM-3: a C selector never changes the principal; observable as a reason bucket only. */
				do_action( 'bizcity_twinweb_reason_bucket', 'c_user_selector_ignored', array( 'route' => 'crm/tasks' ) );
				break;
			}
		}
		return $user_id;
	}

	private function error( $code, $message, $hint, $status ) {
		return new WP_REST_Response( array(
			'success'   => false,
			'code'      => (string) $code,
			'message'   => (string) $message,
			'hint'      => (string) $hint,
			'help_code' => (string) $code,
		), (int) $status );
	}
}
