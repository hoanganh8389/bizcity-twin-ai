<?php
/**
 * DDV probe for PHASE-0.50 leader/member workspace (C-06).
 *
 * Proves the B2 side of R-LEADER-MEMBER on a live site: the Staff_Policy actions
 * exist and fail closed, the handoff service refuses an assignment the actor may
 * not make (and writes nothing when it refuses), the four leader/member routes
 * are registered, the two serializers stay separate, and the public contract
 * schemas/fixtures are on disk.
 *
 * Side effects: one disposable subscriber user, deleted in cleanup(). No task,
 * contact, conversation or Woo row is created.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Leader_Member_Workspace', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Leader_Member_Workspace implements BizCity_Diagnostics_Probe {

	/** @var array<int,int> */
	private $fixture_users = array();

	public function id(): string {
		return 'core.crm.leader_member_workspace';
	}

	public function label(): string {
		return 'CRM leader/member workspace (PHASE-0.50)';
	}

	public function description(): string {
		return 'Checks Staff_Policy leader actions, handoff refusal without writes, W1–W4 route registration, B2/C serializer separation and the public contract schemas.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 68; }
	public function icon(): string { return 'users'; }
	public function estimate_ms(): int { return 400; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Staff_Policy' ) ) {
			return new WP_Error( 'crm_staff_policy_missing', 'CRM staff policy is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
			return new WP_Error( 'crm_task_handoff_missing', 'CRM task handoff service is not loaded.' );
		}
		if ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() <= 0 || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'crm_leader_member_operator_missing', 'An authenticated tenant administrator is required.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$actor_id = (int) get_current_user_id();

		// 1. Policy vocabulary — the two PHASE-0.50 actions exist with a lead+ floor and self-assign is refused.
		$min_rank = (array) BizCity_CRM_Staff_Policy::MIN_RANK;
		$actions_declared = 2 === (int) ( $min_rank['task.assign'] ?? 0 ) && 2 === (int) ( $min_rank['contact.view_by_owner'] ?? 0 );
		$self_forbidden = in_array( 'task.assign', (array) BizCity_CRM_Staff_Policy::SELF_FORBIDDEN, true );

		// 2. Fail-closed: a user with no CRM role manages nobody, and an undeclared action is admin-only.
		$fixture_id = $this->create_user( 'leadermember' );
		$stranger_denied = $fixture_id > 0 && ! BizCity_CRM_Staff_Policy::can( $fixture_id, 'task.assign', $actor_id )['ok'];
		$stranger_view_denied = $fixture_id > 0 && ! BizCity_CRM_Staff_Policy::can( $fixture_id, 'contact.view_by_owner', $actor_id )['ok'];
		$unknown_action_denied = $fixture_id > 0 && ! BizCity_CRM_Staff_Policy::can( $fixture_id, 'task.nonexistent_action', $actor_id )['ok'];
		$roster_empty = $fixture_id > 0 && array() === (array) BizCity_CRM_Staff_Policy::manageable_user_ids( $fixture_id );

		// 3. The handoff service refuses that same assignment AND writes no row (R-LM-5).
		$tasks_table = class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? BizCity_CRM_DB_Installer_V2::tbl_crm_tasks() : '';
		$before = $tasks_table !== '' ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tasks_table}`" ) : -1;
		$refusal = $fixture_id > 0 ? BizCity_CRM_Task_Handoff::create( $fixture_id, array(
			'assignee_user_id' => $actor_id,
			'title'            => '__healthtest_ddv_handoff',
			'instructions'     => 'DDV probe: must be refused before any write.',
			'priority'         => 'low',
		) ) : null;
		$refusal_code = is_array( $refusal ) ? (string) ( $refusal['code'] ?? '' ) : ( is_wp_error( $refusal ) ? $refusal->get_error_code() : '' );
		$after = $tasks_table !== '' ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tasks_table}`" ) : -2;
		$refusal_ok = 'member_not_manageable' === $refusal_code && $before === $after && $before >= 0;

		// 4. Routes for W1/W2/W3/W4.
		$routes = function_exists( 'rest_get_server' ) ? array_keys( (array) rest_get_server()->get_routes() ) : array();
		$crm_ns = defined( 'BIZCITY_CRM_REST_NS' ) ? (string) BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';
		$expected_routes = array(
			'/' . $crm_ns . '/crm-tasks/handoff',
			'/' . $crm_ns . '/crm-tasks/board',
			'/' . $crm_ns . '/crm-contacts/(?P<id>\d+)/team-360',
			'/' . $crm_ns . '/reports/team-inbox/member/(?P<user_id>\d+)/portfolio',
		);
		$missing_routes = array();
		foreach ( $expected_routes as $route ) {
			if ( ! in_array( $route, $routes, true ) ) { $missing_routes[] = $route; }
		}
		$routes_ok = empty( $missing_routes ) && ! empty( $routes );

		// 5. Two serializers, and the C route never calls the admin CRM REST controller (R-TWEB §6.3).
		$base = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$c_rest = $base . '/modules/twinweb/includes/class-twinweb-crm-tasks-rest.php';
		$c_source = is_readable( $c_rest ) ? (string) file_get_contents( $c_rest ) : '';
		$serializers_ok = method_exists( 'BizCity_CRM_Task_Handoff', 'shape_b2' ) && method_exists( 'BizCity_CRM_Task_Handoff', 'shape_c' );
		$c_isolated = '' !== $c_source && false === strpos( $c_source, 'BizCity_CRM_REST_Controller' ) && false === strpos( $c_source, 'BizCity_CRM_Staff_REST' );

		// 6. Public contract schemas + fixtures on disk (C-01).
		$schema_root = $base . '/core/twin-core/contracts/schema/public/v1';
		$catalog_path = $schema_root . '/contract-catalog.json';
		$catalog = is_readable( $catalog_path ) ? json_decode( (string) file_get_contents( $catalog_path ), true ) : null;
		$catalog_ids = array();
		foreach ( (array) ( is_array( $catalog ) ? ( $catalog['contracts'] ?? array() ) : array() ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'] ) ) { $catalog_ids[] = (string) $entry['id']; }
		}
		$contracts_missing = array();
		foreach ( array( 'leader-task-handoff', 'customer-360-team-view', 'member-customer-360', 'staff-customer-portfolio' ) as $contract_id ) {
			if ( ! in_array( $contract_id, $catalog_ids, true )
				|| ! is_readable( $schema_root . '/' . $contract_id . '.schema.json' )
				|| ! is_readable( $schema_root . '/fixtures/' . $contract_id . '.valid.json' )
				|| ! is_readable( $schema_root . '/fixtures/' . $contract_id . '.invalid.json' ) ) {
				$contracts_missing[] = $contract_id;
			}
		}
		$contracts_ok = empty( $contracts_missing );

		$checks = array(
			array( 'label' => 'Staff_Policy declares task.assign and contact.view_by_owner at lead+', 'ok' => $actions_declared, 'detail' => $actions_declared ? 'Both PHASE-0.50 actions require rank 2 for another employee.' : 'MIN_RANK is missing one of the leader/member actions.' ),
			array( 'label' => 'Assigning work to yourself is refused by policy', 'ok' => $self_forbidden, 'detail' => $self_forbidden ? 'task.assign is in SELF_FORBIDDEN; self-assignment is handled by the task route, not this policy.' : 'task.assign is not self-forbidden.' ),
			array( 'label' => 'A user outside the team manages nobody', 'ok' => $stranger_denied && $stranger_view_denied && $roster_empty, 'detail' => $stranger_denied && $stranger_view_denied && $roster_empty ? 'A disposable user can neither assign work nor read another owner’s contacts, and has an empty roster.' : 'A user with no CRM team role was not fully denied.' ),
			array( 'label' => 'An undeclared action fails closed', 'ok' => $unknown_action_denied, 'detail' => $unknown_action_denied ? 'An action missing from MIN_RANK is refused for non-admins.' : 'An undeclared action was allowed.' ),
			array( 'label' => 'Refused handoff writes nothing', 'ok' => $refusal_ok, 'detail' => $refusal_ok ? 'create() returned member_not_manageable and the task table row count did not change.' : 'Refusal code=' . sanitize_key( $refusal_code ) . ', rows before=' . $before . ', after=' . $after ),
			array( 'label' => 'W1–W4 routes are registered', 'ok' => $routes_ok, 'detail' => $routes_ok ? 'handoff, board, team-360 and member portfolio routes are served.' : 'Missing routes: ' . implode( ', ', array_map( 'sanitize_text_field', $missing_routes ) ) ),
			array( 'label' => 'B2 and C use separate serializers', 'ok' => $serializers_ok, 'detail' => $serializers_ok ? 'shape_b2() and shape_c() both exist on the handoff service.' : 'One of the two task serializers is missing.' ),
			array( 'label' => 'C task route does not call the admin CRM REST controller', 'ok' => $c_isolated, 'detail' => $c_isolated ? 'The /gpt/ task route references no admin CRM REST class.' : 'The C route file references an admin CRM REST controller or could not be read.' ),
			array( 'label' => 'Leader/member contracts are published', 'ok' => $contracts_ok, 'detail' => $contracts_ok ? 'Four schemas with valid/invalid fixtures are registered in the public catalog.' : 'Missing contract assets: ' . implode( ', ', array_map( 'sanitize_text_field', $contracts_missing ) ) ),
		);

		$pass = true;
		foreach ( $checks as $check ) {
			$ctx->emit_step( array( 'label' => $check['label'], 'status' => $check['ok'] ? 'pass' : 'fail', 'detail' => $check['detail'] ) );
			$pass = $pass && $check['ok'];
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Leader/member workspace policy, routes, serializers and contracts are in place.' : 'Leader/member workspace ownership checks failed.',
			'fix_hint' => $pass ? '' : 'Every route that touches another user_id must go through Staff_Policy::can() and refuse before writing (R-LEADER-MEMBER).',
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
	$list[] = 'BizCity_Probe_CRM_Leader_Member_Workspace';
	return $list;
} );
