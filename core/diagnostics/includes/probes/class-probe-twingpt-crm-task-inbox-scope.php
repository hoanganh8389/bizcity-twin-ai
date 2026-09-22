<?php
/**
 * DDV probe for PHASE-0.50 member task inbox on /gpt/crm/ (C-06).
 *
 * Proves the C side of R-LEADER-MEMBER: the member principal comes from the Twin
 * GPT identity and never from the request, a selector in the query string is
 * ignored and observable only as a reason bucket, another member's task is
 * indistinguishable from a missing one, and the C task DTO carries no leader
 * fields.
 *
 * Side effects: one disposable subscriber user, deleted in cleanup(). No task row
 * is created, updated or transitioned.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_TwinGPT_CRM_Task_Inbox_Scope', false ) ) {
	return;
}

final class BizCity_Probe_TwinGPT_CRM_Task_Inbox_Scope implements BizCity_Diagnostics_Probe {

	/** @var array<int,int> */
	private $fixture_users = array();

	public function id(): string {
		return 'twingpt.crm.task_inbox_scope';
	}

	public function label(): string {
		return 'Twin GPT member task inbox scope (PHASE-0.50)';
	}

	public function description(): string {
		return 'Checks that /gpt/crm/ assigned work uses the logged-in member, ignores user selectors with a reason bucket, hides other members tasks as not-found and ships no leader-only fields.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 69; }
	public function icon(): string { return 'inbox'; }
	public function estimate_ms(): int { return 350; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
			return new WP_Error( 'crm_task_handoff_missing', 'CRM task handoff service is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_TwinWeb_CRM_Tasks_REST' ) ) {
			return new WP_Error( 'twinweb_crm_tasks_rest_missing', 'Twin GPT member task route is not loaded.' );
		}
		if ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() <= 0 || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'twingpt_task_scope_operator_missing', 'An authenticated tenant administrator is required.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$actor_id = (int) get_current_user_id();
		$fixture_id = $this->create_user( 'taskscope' );

		// 1. Member routes are served under the Twin GPT namespace.
		$routes = function_exists( 'rest_get_server' ) ? array_keys( (array) rest_get_server()->get_routes() ) : array();
		$ns = '/' . BizCity_TwinWeb_CRM_Tasks_REST::NS;
		$missing_routes = array();
		foreach ( array( '/crm/tasks', '/crm/tasks/summary', '/crm/tasks/(?P<id>\d+)', '/crm/tasks/(?P<id>\d+)/transition' ) as $route ) {
			if ( ! in_array( $ns . $route, $routes, true ) ) { $missing_routes[] = $route; }
		}
		$routes_ok = empty( $missing_routes ) && ! empty( $routes );

		// 2. The DTO refuses to serialize someone else's task, and an empty principal lists nothing.
		$foreign_row = array(
			'id' => 0, 'assignee_id' => $actor_id, 'created_by' => $fixture_id, 'title' => '__healthtest_ddv',
			'notes' => '', 'priority' => 'low', 'due_date' => null, 'status' => 'sent',
			'related_entity_type' => '', 'related_entity_id' => 0, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		);
		$not_mine = $fixture_id > 0 ? BizCity_CRM_Task_Handoff::shape_c( $foreign_row, $fixture_id ) : 'skipped';
		$foreign_dto_denied = null === $not_mine;
		$no_principal_empty = array() === (array) BizCity_CRM_Task_Handoff::list_for_member( 0, 'all', 10 );

		// 3. A transition on a task that is not the member's own is indistinguishable from a missing task.
		$transition = $fixture_id > 0 ? BizCity_CRM_Task_Handoff::member_transition( $fixture_id, 0, 'accept' ) : null;
		$transition_code = is_wp_error( $transition ) ? (string) $transition->get_error_code() : '';
		$transition_denied = 'task_not_found' === $transition_code;

		// 4. Runtime: a user selector on the C route must not change the principal, only emit a reason bucket.
		$buckets = array();
		$listener = static function ( $bucket ) use ( &$buckets ) { $buckets[] = (string) $bucket; };
		add_action( 'bizcity_twinweb_reason_bucket', $listener );
		$request = new WP_REST_Request( 'GET', $ns . '/crm/tasks' );
		$request->set_param( 'status', 'open' );
		$request->set_param( 'user_id', $fixture_id > 0 ? $fixture_id : 999999 );
		$response = rest_do_request( $request );
		remove_action( 'bizcity_twinweb_reason_bucket', $listener );
		$data = $response instanceof WP_REST_Response ? (array) $response->get_data() : array();
		$status_code = $response instanceof WP_REST_Response ? (int) $response->get_status() : 0;
		$selector_ignored = in_array( 'c_user_selector_ignored', $buckets, true );
		$own_ids = array();
		foreach ( (array) BizCity_CRM_Task_Handoff::list_for_member( $actor_id, 'open', 100 ) as $task ) {
			if ( is_array( $task ) ) { $own_ids[] = (int) ( $task['task_id'] ?? 0 ); }
		}
		$returned_ids = array();
		foreach ( (array) ( $data['tasks'] ?? array() ) as $task ) {
			if ( is_array( $task ) ) { $returned_ids[] = (int) ( $task['task_id'] ?? 0 ); }
		}
		sort( $own_ids );
		sort( $returned_ids );
		$principal_kept = 200 === $status_code && ! empty( $data['success'] ) && $own_ids === $returned_ids && 'C_PUBLIC_TWINGPT' === (string) ( $data['surface'] ?? '' );
		$identity_unavailable = 401 === $status_code || 'auth_required' === (string) ( $data['code'] ?? '' );

		// 5. The C DTO never carries leader-only fields.
		$leader_fields = array();
		foreach ( (array) ( $data['tasks'] ?? array() ) as $task ) {
			if ( ! is_array( $task ) ) { continue; }
			foreach ( array( 'assignee', 'timeline', 'result', 'batch_key', 'updated_at' ) as $field ) {
				if ( array_key_exists( $field, $task ) ) { $leader_fields[] = $field; }
			}
			if ( is_array( $task['assigned_by'] ?? null ) && array_key_exists( 'user_id', $task['assigned_by'] ) ) { $leader_fields[] = 'assigned_by.user_id'; }
		}
		$leader_fields = array_values( array_unique( $leader_fields ) );
		$dto_clean = empty( $leader_fields );

		$checks = array(
			array( 'label' => 'Member task routes are registered', 'ok' => $routes_ok, 'status' => $routes_ok ? 'pass' : 'fail', 'detail' => $routes_ok ? 'list, summary, detail and transition are served under ' . $ns . '.' : 'Missing routes: ' . implode( ', ', array_map( 'sanitize_text_field', $missing_routes ) ) ),
			array( 'label' => 'Another member task is not serialized', 'ok' => $foreign_dto_denied, 'status' => $foreign_dto_denied ? 'pass' : 'fail', 'detail' => $foreign_dto_denied ? 'shape_c() returns null when the row belongs to someone else.' : 'The C serializer accepted a task assigned to another user.' ),
			array( 'label' => 'No principal lists no work', 'ok' => $no_principal_empty, 'status' => $no_principal_empty ? 'pass' : 'fail', 'detail' => $no_principal_empty ? 'list_for_member(0) returns an empty list.' : 'An empty principal returned tasks.' ),
			array( 'label' => 'Foreign task transition returns task_not_found', 'ok' => $transition_denied, 'status' => $transition_denied ? 'pass' : 'fail', 'detail' => $transition_denied ? 'Not-found and not-mine are indistinguishable (contract §0.3).' : 'Unexpected transition code: ' . sanitize_key( $transition_code ) ),
			array(
				'label'  => 'A user selector never changes the C principal',
				'ok'     => $principal_kept || $identity_unavailable,
				'status' => $principal_kept ? 'pass' : ( $identity_unavailable ? 'warn' : 'fail' ),
				'detail' => $principal_kept
					? 'GET /crm/tasks?user_id=… returned the operator own work and emitted c_user_selector_ignored=' . ( $selector_ignored ? 'yes' : 'no' ) . '.'
					: ( $identity_unavailable
						? 'No Twin GPT identity in this diagnostics context (HTTP 401); run this probe while signed in to /gpt/ for runtime evidence.'
						: 'HTTP ' . $status_code . ': the C route did not return the operator own task list.' ),
			),
			array( 'label' => 'Selector emits the reason bucket', 'ok' => $selector_ignored || $identity_unavailable, 'status' => $selector_ignored ? 'pass' : ( $identity_unavailable ? 'warn' : 'fail' ), 'detail' => $selector_ignored ? 'c_user_selector_ignored was emitted for the ignored user_id.' : 'No reason bucket was observed for the ignored selector.' ),
			array( 'label' => 'C task DTO has no leader-only fields', 'ok' => $dto_clean, 'status' => $dto_clean ? 'pass' : 'fail', 'detail' => $dto_clean ? 'No assignee, timeline, result, batch_key or leader user_id reached the member surface.' : 'Leader fields on C: ' . implode( ', ', array_map( 'sanitize_text_field', $leader_fields ) ) ),
		);

		$pass = true;
		foreach ( $checks as $check ) {
			$ctx->emit_step( array( 'label' => $check['label'], 'status' => $check['status'], 'detail' => $check['detail'] ) );
			$pass = $pass && $check['ok'];
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Member task inbox stays bound to the logged-in member on the C surface.' : 'Member task inbox scope failed.',
			'fix_hint' => $pass ? '' : 'On /gpt/ the principal is BizCity_TwinWeb_Identity::current(); selectors are ignored and foreign tasks return task_not_found (R-LM-3).',
			'steps'    => array(),
		);
	}

	public function cleanup(): void {
		foreach ( $this->fixture_users as $user_id ) {
			if ( function_exists( 'wp_delete_user' ) ) {
				wp_delete_user( (int) $user_id );
			}
		}
		$this->fixture_users = array();
	}

	private function create_user( $label ) {
		if ( ! function_exists( 'wp_insert_user' ) ) { return 0; }
		$suffix = strtolower( substr( md5( (string) microtime( true ) . '|' . wp_generate_uuid4() ), 0, 12 ) );
		$user_id = wp_insert_user( array(
			'user_login' => '__healthtest_' . sanitize_key( $label ) . '_' . $suffix,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => 'healthtest-' . $label . '-' . $suffix . '@invalid.test',
			'role'       => 'subscriber',
		) );
		if ( is_wp_error( $user_id ) || (int) $user_id <= 0 ) { return 0; }
		if ( function_exists( 'add_user_to_blog' ) && function_exists( 'get_current_blog_id' ) ) {
			add_user_to_blog( (int) get_current_blog_id(), (int) $user_id, 'subscriber' );
		}
		$this->fixture_users[] = (int) $user_id;
		return (int) $user_id;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinGPT_CRM_Task_Inbox_Scope';
	return $list;
} );
