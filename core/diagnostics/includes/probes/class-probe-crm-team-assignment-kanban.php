<?php
/**
 * DDV probe for the PHASE-0.39F team assignment and Kanban integration.
 *
 * This probe is read-only. It verifies the loaded event/REST contracts and
 * does not create CRM fixtures or invoke a real assignment mutation.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-25
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_CRM_Team_Assignment_Kanban', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Team_Assignment_Kanban implements BizCity_Diagnostics_Probe {

	public static function register( $probes ) {
		if ( ! in_array( 'BizCity_Probe_CRM_Team_Assignment_Kanban', $probes, true ) ) {
			$probes[] = 'BizCity_Probe_CRM_Team_Assignment_Kanban';
		}
		return $probes;
	}

	public function id(): string { return 'modules.crm.team_assignment_kanban'; }
	public function label(): string { return 'CRM team assignment and Kanban integration'; }
	public function description(): string { return 'Kiểm tra F5 auto-assignment và F6 Conversation Board qua Disk, Loader và Runtime mà không ghi CRM fixture.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 64; }
	public function icon(): string { return 'kanban'; }
	public function estimate_ms(): int { return 500; }
	public function precondition() { return true; }
	public function cleanup(): void {}

	public function run( $ctx ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F5-F6-DDV — verify source, loader and runtime registration without mutation.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$backend_files = array(
			'plugins/bizcity-twin-crm/includes/class-assignment-manager.php',
			'plugins/bizcity-twin-crm/includes/class-kanban-manager.php',
			'plugins/bizcity-twin-crm/includes/class-repository.php',
			'plugins/bizcity-twin-crm/includes/class-rest-controller.php',
		);
		$frontend_files = array(
			'plugins/bizcity-twin-crm/frontend/src/components/ConversationBoard.jsx',
			'plugins/bizcity-twin-crm/frontend/src/redux/api/crmApi.js',
		);
		$missing_backend = array();
		foreach ( $backend_files as $relative ) {
			if ( ! is_readable( $root . $relative ) ) { $missing_backend[] = $relative; }
		}
		$steps[] = array(
			'layer' => 'Disk',
			'label' => 'F5/F6 backend artifacts exist',
			'status' => empty( $missing_backend ) ? 'pass' : 'fail',
			'detail' => empty( $missing_backend ) ? implode( ', ', $backend_files ) : implode( ', ', $missing_backend ),
		);
		if ( ! empty( $missing_backend ) ) {
			return array( 'status' => 'fail', 'summary' => 'CRM F5/F6 source artifacts are incomplete.', 'steps' => $steps );
		}

		$missing_frontend = array();
		foreach ( $frontend_files as $relative ) {
			if ( ! is_readable( $root . $relative ) ) { $missing_frontend[] = $relative; }
		}
		$steps[] = array(
			'layer' => 'Disk',
			'label' => 'F5/F6 frontend source artifacts',
			'status' => empty( $missing_frontend ) ? 'pass' : 'skip',
			'detail' => empty( $missing_frontend )
				? 'Development source files are present.'
				: 'Development source is absent; built CRM dist/runtime remains authoritative: ' . implode( ', ', $missing_frontend ),
		);

		$classes = array( 'BizCity_CRM_Assignment_Manager', 'BizCity_CRM_Kanban_Manager', 'BizCity_CRM_Repository', 'BizCity_CRM_REST_Controller' );
		$missing_classes = array();
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) { $missing_classes[] = $class; }
		}
		$hook = has_action( 'bizcity_crm_event_crm_conversation_opened', array( 'BizCity_CRM_Assignment_Manager', 'on_conversation_opened' ) );
		$loader_ok = empty( $missing_classes ) && false !== $hook;
		$steps[] = array(
			'layer' => 'Loader',
			'label' => 'F5 classes and new-conversation hook are loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? implode( ', ', $classes ) . '; hook priority ' . (int) $hook : implode( ', ', $missing_classes ) . ( false === $hook ? '; missing crm_conversation_opened hook' : '' ),
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM F5 assignment integration is not loaded.', 'steps' => $steps );
		}

		$assignment_api_ok = false;
		try {
			$assignee_method = new ReflectionMethod( 'BizCity_CRM_Repository', 'set_conversation_assignee' );
			$team_method = new ReflectionMethod( 'BizCity_CRM_Repository', 'set_conversation_team' );
			$assignment_api_ok = $assignee_method->getNumberOfParameters() >= 5 && $team_method->getNumberOfParameters() >= 4;
		} catch ( ReflectionException $e ) {
			$assignment_api_ok = false;
		}
		$steps[] = array(
			'layer' => 'Loader',
			'label' => 'Repository supports deferred assignment events',
			'status' => $assignment_api_ok ? 'pass' : 'fail',
			'detail' => $assignment_api_ok ? 'Assignment service can suppress mutation events until its transaction commits.' : 'Repository mutation methods lack the deferred-event contract.',
		);
		if ( ! $assignment_api_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM F5 deferred-event contract is not loaded.', 'steps' => $steps );
		}

		$routes = function_exists( 'rest_get_server' ) ? rest_get_server()->get_routes() : array();
		$route_names = array_keys( is_array( $routes ) ? $routes : array() );
		$required_routes = array( 'auto-assign', '/bizcity-crm/v1/boards/conversations', '/bizcity-crm/v1/boards/conversations/move' );
		$missing_routes = array();
		$auto_assign_route = false;
		foreach ( $route_names as $route_name ) {
			if ( preg_match( '#^/bizcity-crm/v1/conversations/.+/auto-assign$#', (string) $route_name ) ) { $auto_assign_route = true; break; }
		}
		if ( ! $auto_assign_route ) { $missing_routes[] = 'conversations/{id}/auto-assign'; }
		foreach ( array_slice( $required_routes, 1 ) as $required_route ) {
			if ( ! in_array( $required_route, $route_names, true ) ) { $missing_routes[] = $required_route; }
		}
		if ( ! in_array( '/bizcity-crm/v1/boards/order-care/move', $route_names, true ) ) { $missing_routes[] = '/bizcity-crm/v1/boards/order-care/move'; }
		$route_ok = empty( $missing_routes );
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'F5 auto-assign and F6 board routes are registered',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'conversations/{id}/auto-assign, ' . implode( ', ', array_slice( $required_routes, 1 ) ) . ', /bizcity-crm/v1/boards/order-care/move' : 'Missing: ' . implode( ', ', $missing_routes ),
		);

		$dry_outcome = BizCity_CRM_Assignment_Manager::assign_conversation( 0 );
		$outcome_ok = is_array( $dry_outcome )
			&& (string) ( $dry_outcome['outcome'] ?? '' ) === 'permanent_failed'
			&& (string) ( $dry_outcome['code'] ?? '' ) === 'invalid_conversation'
			&& isset( $dry_outcome['retryable'] );
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Assignment command returns normalized no-mutation outcome',
			'status' => $outcome_ok ? 'pass' : 'fail',
			'detail' => $outcome_ok ? 'invalid_conversation; no CRM row was touched' : 'Unexpected assignment outcome shape.',
		);

		$order_care_outcome = BizCity_CRM_Repository::move_order_care_object( 'invalid', 0, 'open', array(), (int) get_current_user_id() );
		$order_care_ok = is_array( $order_care_outcome )
			&& false === (bool) ( $order_care_outcome['success'] ?? true )
			&& 'invalid_order_care_object' === (string) ( $order_care_outcome['code'] ?? '' )
			&& false === (bool) ( $order_care_outcome['retryable'] ?? true );
		$steps[] = array(
			'layer' => 'Runtime',
			'label' => 'Order-care command rejects invalid object without mutation',
			'status' => $order_care_ok ? 'pass' : 'fail',
			'detail' => $order_care_ok ? 'invalid_order_care_object; no CRM row was touched' : 'Unexpected order-care command outcome shape.',
		);

		$status = $route_ok && $outcome_ok && $order_care_ok ? 'pass' : 'fail';
		return array(
			'status' => $status,
			'summary' => $status === 'pass' ? 'CRM F5/F6 integration contract passed without mutation.' : 'CRM F5/F6 integration has runtime gaps.',
			'fix_hint' => 'Load CRM bootstrap before running diagnostics and verify the bizcity-crm/v1 route registration.',
			'steps' => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_CRM_Team_Assignment_Kanban', 'register' ), 10, 1 );
