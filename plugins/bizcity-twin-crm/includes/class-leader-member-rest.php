<?php
/**
 * BizCity CRM — Leader work handoff REST (PHASE-0.50 W4, R-LEADER-MEMBER R-LM-5).
 *
 * Namespace: bizcity-crm/v1 — surface `B2_ADMIN_CRM` (`/twin/?plugin=crm`, `/crm/`).
 *
 * Routes:
 *   POST /crm-tasks/handoff                 — give work to a managed member (1..200 contacts, one conversation or none)
 *   GET  /crm-tasks/board                   — handoff tasks the actor created or whose assignee they manage
 *   POST /crm-tasks/{id}/handoff-action     — cancel | reassign | reopen
 *
 * The member side (`/gpt/crm/`) lives in modules/twinweb and calls the same
 * {@see BizCity_CRM_Task_Handoff} service with the current user only.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.50 2026-09-17
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Leader_Member_REST' ) ) { return; }

final class BizCity_CRM_Leader_Member_REST {

	public static function register_routes(): void {
		if ( ! class_exists( 'BizCity_CRM_Task_Handoff' ) || ! class_exists( 'BizCity_CRM_Staff_Policy' ) ) { return; }
		$ns = BIZCITY_CRM_REST_NS;

		register_rest_route( $ns, '/crm-tasks/handoff', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_handoff' ),
			'permission_callback' => array( __CLASS__, 'can_lead' ),
		) );
		// PHASE-0.52 §16.2 gap — L2 "Các đợt đã giao" needs to read past batches back after a
		// page reload; before this it only had the in-session list from the POST response.
		register_rest_route( $ns, '/crm-tasks/handoff/batches', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_handoff_batches' ),
			'permission_callback' => array( __CLASS__, 'can_lead' ),
			'args'                => array(
				'limit' => array( 'type' => 'integer' ),
			),
		) );
		// [2026-09-19 Johnny Chu] PHASE-0.55-A4 — expose the shared playbook catalog instead of duplicating it in leader JSX.
		register_rest_route( $ns, '/crm-tasks/playbooks', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_playbooks' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
		) );
		register_rest_route( $ns, '/crm-tasks/board', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_board' ),
			'permission_callback' => array( __CLASS__, 'can_use_crm' ),
			'args'                => array(
				'member_id'  => array( 'type' => 'integer' ),
				'contact_id' => array( 'type' => 'integer' ),
				'status'     => array( 'type' => 'string' ),
				'due'        => array( 'type' => 'string' ),
				'team_id'    => array( 'type' => 'integer' ),
			),
		) );
		register_rest_route( $ns, '/crm-tasks/(?P<id>\d+)/handoff-action', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_handoff_action' ),
			'permission_callback' => array( __CLASS__, 'can_lead' ),
		) );
		// PHASE-0.50 W1/W2 — move customers to another employee (§4.4, R-ZP-OWNER).
		if ( class_exists( 'BizCity_CRM_Contact_Transfer' ) ) {
			register_rest_route( $ns, '/crm-contacts/transfer-owner', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_contact_transfer' ),
				'permission_callback' => array( __CLASS__, 'can_lead' ),
			) );
		}
	}

	// ── POST /crm-contacts/transfer-owner ───────────────────────────────

	public static function post_contact_transfer( WP_REST_Request $req ) {
		$body = self::json_body( $req );
		$result = BizCity_CRM_Contact_Transfer::transfer( (int) get_current_user_id(), array(
			'client_request_id'   => (string) ( $body['client_request_id'] ?? '' ),
			'to_user_id'          => (int) ( $body['to_user_id'] ?? 0 ),
			'contact_ids'         => (array) ( $body['contact_ids'] ?? array() ),
			'out_of_scope_policy' => (string) ( $body['out_of_scope_policy'] ?? 'reject' ),
		) );
		if ( is_wp_error( $result ) ) { return self::error_response( $result ); }
		return new WP_REST_Response( array_merge( array( 'surface' => 'B2_ADMIN_CRM' ), $result ), 200 );
	}

	/**
	 * Logged-in CRM user (agent or above). Fine-grained checks happen per call.
	 *
	 * [2026-09-19] PHASE-0.60 C60-A05 — delegates to the canonical `crm.inbox.read`
	 * action instead of re-deriving admin/rank here (was one of 3 near-duplicate
	 * `can_use_crm()` definitions across `class-pipeline-rest.php`/`class-staff-rest.php`).
	 */
	public static function can_use_crm(): bool {
		return class_exists( 'BizCity_CRM_Authority' )
			? BizCity_CRM_Authority::can( 'crm.inbox.read' )['ok']
			: is_user_logged_in();
	}

	/** Lead or above — the only ranks that can hand work to someone else. */
	public static function can_lead(): bool {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) { return false; }
		return BizCity_CRM_Staff_Policy::rank( BizCity_CRM_Staff_Policy::role( $user_id ) ) >= 2;
	}

	// ── POST /crm-tasks/handoff ─────────────────────────────────────────

	public static function post_handoff( WP_REST_Request $req ) {
		$body = self::json_body( $req );
		// PHASE-0.50 C-05 (mockup §3.4 "☐ Nhắn Zalo Bot nội bộ") — the leader's per-handoff choice ANDs
		// with the site-wide `bizcity_crm_task_handoff_zalo_bot` master switch (N-08); unchecked here
		// means silent even when the site has the feature on. Implemented as a request-scoped filter on
		// the notifier's own `enabled()` hook so `BizCity_CRM_Task_Handoff::create()` and the notifier
		// stay untouched — neither owns this file, and the handoff-assigned hook signature stays fixed.
		$notify_wanted = ! empty( $body['notify_zalo_bot'] );
		$gate = static function ( $enabled, $assignee_id ) use ( $notify_wanted ) { return $enabled && $notify_wanted; };
		$notify_class = class_exists( 'BizCity_CRM_Task_Handoff_Notify' ) ? 'BizCity_CRM_Task_Handoff_Notify' : '';
		if ( $notify_class ) { add_filter( $notify_class::OPTION_ENABLED, $gate, 20, 2 ); }
		$result = BizCity_CRM_Task_Handoff::create( (int) get_current_user_id(), array(
			'client_request_id'   => (string) ( $body['client_request_id'] ?? '' ),
			'assignee_user_id'    => (int) ( $body['assignee_user_id'] ?? 0 ),
			'title'               => (string) ( $body['title'] ?? '' ),
			'instructions'        => (string) ( $body['instructions'] ?? '' ),
			'priority'            => (string) ( $body['priority'] ?? 'medium' ),
			'due_date'            => (string) ( $body['due_date'] ?? ( $body['due_at'] ?? '' ) ),
			'contact_ids'         => (array) ( $body['contact_ids'] ?? array() ),
			'conversation_id'     => (int) ( $body['conversation_id'] ?? 0 ),
			'out_of_scope_policy' => (string) ( $body['out_of_scope_policy'] ?? 'reject' ),
			'playbook'            => (string) ( $body['playbook'] ?? '' ),
		) );
		if ( $notify_class ) { remove_filter( $notify_class::OPTION_ENABLED, $gate, 20 ); }
		if ( is_wp_error( $result ) ) { return self::error_response( $result ); }
		return new WP_REST_Response( array_merge( array( 'ok' => true, 'surface' => 'B2_ADMIN_CRM' ), $result ), empty( $result['duplicate'] ) ? 201 : 200 );
	}

	// ── GET /crm-tasks/handoff/batches ──────────────────────────────────

	public static function get_handoff_batches( WP_REST_Request $req ) {
		$actor_id = (int) get_current_user_id();
		$limit    = (int) $req->get_param( 'limit' ) ?: 20;
		return new WP_REST_Response( array(
			'ok'       => true,
			'contract' => BizCity_CRM_Task_Handoff::CONTRACT . '@' . BizCity_CRM_Task_Handoff::VERSION,
			'surface'  => 'B2_ADMIN_CRM',
			'batches'  => BizCity_CRM_Task_Handoff::list_batches( $actor_id, $limit ),
		), 200 );
	}

	public static function get_playbooks( WP_REST_Request $req ) {
		return new WP_REST_Response( array(
			'ok'        => true,
			'contract'  => BizCity_CRM_Task_Handoff::CONTRACT . '@' . BizCity_CRM_Task_Handoff::VERSION,
			'surface'   => 'B2_ADMIN_CRM',
			'playbooks' => array_values( BizCity_CRM_Task_Handoff::playbooks() ),
		), 200 );
	}

	// ── GET /crm-tasks/board ────────────────────────────────────────────

	public static function get_board( WP_REST_Request $req ) {
		$actor_id  = (int) get_current_user_id();
		$member_id = (int) $req->get_param( 'member_id' );
		// `member_id` is a selector: it must be the actor or someone the actor manages.
		if ( $member_id > 0 && $member_id !== $actor_id ) {
			$visible = BizCity_CRM_Staff_Policy::visible_user_ids( $actor_id );
			if ( null !== $visible && ! in_array( $member_id, array_map( 'intval', $visible ), true ) ) {
				return self::error_response( new WP_Error( 'member_not_manageable', 'Bạn không quản lý nhân viên này.', array( 'status' => 403 ) ) );
			}
		}
		$contact_id = (int) $req->get_param( 'contact_id' );
		if ( $contact_id > 0 ) {
			$allowed = BizCity_CRM_Inbox_Access::allowed_inbox_ids( $actor_id );
			if ( null !== $allowed && ! BizCity_CRM_Task_Handoff::contact_in_inboxes( $contact_id, (array) $allowed ) ) {
				return self::error_response( new WP_Error( 'contact_not_in_scope', 'Không tìm thấy khách.', array( 'status' => 404 ) ) );
			}
		}
		$board = BizCity_CRM_Task_Handoff::board( $actor_id, array(
			'member_id'  => $member_id,
			'contact_id' => $contact_id,
			'status'     => (string) $req->get_param( 'status' ),
			'due'        => (string) $req->get_param( 'due' ),
			'team_id'    => (int) $req->get_param( 'team_id' ),
		) );
		return new WP_REST_Response( array_merge( array(
			'ok'       => true,
			'contract' => BizCity_CRM_Task_Handoff::CONTRACT . '@' . BizCity_CRM_Task_Handoff::VERSION,
			'surface'  => 'B2_ADMIN_CRM',
			'as_of'    => current_time( 'c' ),
			'can_assign' => self::can_lead(),
		), $board ), 200 );
	}

	// ── POST /crm-tasks/{id}/handoff-action ─────────────────────────────

	public static function post_handoff_action( WP_REST_Request $req ) {
		$body = self::json_body( $req );
		$action = sanitize_key( (string) ( $body['action'] ?? '' ) );
		// [2026-09-19] PHASE-0.55 A6 — 'review' packs verdict+comment into the shared $reason
		// param as "verdict|comment" (no schema change to leader_action()'s positional args).
		$reason = 'review' === $action
			? sanitize_key( (string) ( $body['verdict'] ?? '' ) ) . '|' . (string) ( $body['comment'] ?? '' )
			: (string) ( $body['reason'] ?? '' );
		$result = BizCity_CRM_Task_Handoff::leader_action(
			(int) get_current_user_id(),
			(int) $req['id'],
			$action,
			(int) ( $body['assignee_user_id'] ?? 0 ),
			$reason
		);
		if ( is_wp_error( $result ) ) { return self::error_response( $result ); }
		return new WP_REST_Response( array( 'ok' => true, 'task' => $result ), 200 );
	}

	// ── helpers ─────────────────────────────────────────────────────────

	private static function json_body( WP_REST_Request $req ): array {
		$body = $req->get_json_params();
		return is_array( $body ) ? $body : (array) $req->get_body_params();
	}

	/** R-ERROR-UX envelope, same shape as {@see BizCity_CRM_Staff_Policy::denied_response()}. */
	private static function error_response( WP_Error $error ): WP_REST_Response {
		$data = (array) $error->get_error_data();
		$payload = array(
			'ok'        => false,
			'code'      => $error->get_error_code(),
			'message'   => $error->get_error_message(),
			'hint'      => (string) ( $data['hint'] ?? '' ),
			'help_code' => (string) ( $data['help_code'] ?? $error->get_error_code() ),
		);
		if ( isset( $data['stripped'] ) ) { $payload['stripped'] = $data['stripped']; }
		return new WP_REST_Response( $payload, (int) ( $data['status'] ?? 400 ) );
	}
}
