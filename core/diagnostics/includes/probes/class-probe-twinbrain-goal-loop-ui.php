<?php
/**
 * DDV probe for Twin Goal Loop G10 REST/UI contract.
 *
 * Production builds may ship only frontend dist artifacts, so React source
 * markers are optional evidence and never hard-gate the probe.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$interface_file = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $interface_file ) ) {
		require_once $interface_file;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinBrain_Goal_Loop_UI', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Goal_Loop_UI implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'twinbrain.goal_loop_ui'; }
	public function label(): string { return 'Twin Goal Loop G10 REST/UI'; }
	public function description(): string {
		return 'Checks identity-scoped Goal REST routes, Goal Loop UI deploy artifacts, and optional source markers without requiring React src on production.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 67; }
	public function icon(): string { return 'target'; }
	public function estimate_ms(): int { return 40; }

	public function precondition() {
		foreach ( array( 'BizCity_TwinBrain_Goal_Loop_State', 'BizCity_TwinBrain_Goal_Loop_Repository', 'BizCity_TwinBrain_Goal_Loop_REST' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'class_missing', $class . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — deterministic REST/UI DDV; no LLM, DB writes, or browser dependency.
		$steps = array();
		$pass = true;
		$root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/'
			: dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		$rest_file = $root . 'core/twinbrain/includes/class-twinbrain-goal-loop-rest.php';
		$twinchat_panel = $root . 'modules/twinchat/ui/src/components/GoalLoopPanel.tsx';
		$twinchat_chat_panel = $root . 'modules/twinchat/ui/src/components/ChatPanel.tsx';
		$twinchat_api = $root . 'modules/twinchat/ui/src/api/goalLoop.ts';
		$twinweb_panel = $root . 'modules/twinweb/ui/src/components/GoalLoopPanel.tsx';
		$twinweb_chat_page = $root . 'modules/twinweb/ui/src/pages/ChatPage.tsx';
		$twinweb_api = $root . 'modules/twinweb/ui/src/api/goalLoop.ts';
		$twinweb_modal = $root . 'modules/twinweb/ui/src/components/GoalStateModal.tsx';
		$twinchat_dist = $root . 'modules/twinchat/ui/dist';
		$twinweb_dist = $root . 'modules/twinweb/ui/dist';

		$rest_source = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$rest_disk_ok = $rest_source !== ''
			&& strpos( $rest_source, "'/goal/active'" ) !== false
			&& strpos( $rest_source, "'/goal/(?P<goal_id>" ) !== false
			&& strpos( $rest_source, 'can_transition' ) !== false
			&& strpos( $rest_source, 'identity_uuid' ) !== false;
		$pass = $this->step( $ctx, $steps, 'Disk: identity-scoped Goal REST contract', $rest_disk_ok, $rest_disk_ok ? 'Active/close routes and transition/identity guards found.' : $rest_file ) && $pass;

		$dist_ok = is_dir( $twinchat_dist ) || is_dir( $twinweb_dist );
		$pass = $this->step( $ctx, $steps, 'Disk: Goal UI deploy artifact policy', $dist_ok, sprintf( 'TwinChat dist=%s; TwinWeb dist=%s; React src is optional.', is_dir( $twinchat_dist ) ? 'present' : 'missing', is_dir( $twinweb_dist ) ? 'present' : 'missing' ) ) && $pass;

		$source_files = array( $twinchat_panel, $twinchat_chat_panel, $twinchat_api, $twinweb_panel, $twinweb_chat_page, $twinweb_api, $twinweb_modal );
		$source_present = true;
		foreach ( $source_files as $source_file ) {
			if ( ! is_readable( $source_file ) ) {
				$source_present = false;
				break;
			}
		}
		if ( $source_present ) {
			$source = implode( "\n", array_map( 'file_get_contents', $source_files ) );
			$source_markers_ok = strpos( $source, 'completion_score' ) !== false
				&& strpos( $source, 'next_best_action' ) !== false
				&& strpos( $source, 'gaps' ) !== false
				&& strpos( $source, 'GoalStateModal' ) !== false
				&& strpos( $source, 'navigator.clipboard.writeText' ) !== false
				&& strpos( $source, 'link.download' ) !== false
				&& strpos( $source, 'goal_state.v1' ) !== false
				&& strpos( $source, 'onCheckpointChoice' ) !== false
				&& substr_count( $source, 'getActiveGoal' ) >= 2
				&& strpos( $source, '/goal/active?session_id=' ) !== false;
			$this->step( $ctx, $steps, 'Disk: optional Goal UI source markers', $source_markers_ok, $source_markers_ok ? 'GoalLoopPanel/GoalStateModal markers found.' : 'Optional source markers drifted; dist/runtime remain authoritative.' );
		} else {
			$this->step( $ctx, $steps, 'Disk: optional Goal UI source markers', true, 'SKIP: React source is absent; valid for production dist-only deploy.' );
		}

		$method_ok = method_exists( 'BizCity_TwinBrain_Goal_Loop_REST', 'register_routes' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_REST', 'handle_active' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_REST', 'handle_close' );
		$pass = $this->step( $ctx, $steps, 'Loader: Goal REST methods', $method_ok, $method_ok ? 'register_routes/active/close methods loaded.' : 'Goal REST method missing.' ) && $pass;

		$routes = function_exists( 'rest_get_server' ) ? rest_get_server()->get_routes() : array();
		$route_ok = $this->route_has_method( $routes, '/bizcity-twinbrain/v1/goal/active', 'GET' )
			&& $this->route_has_suffix_method( $routes, '/goal/', '/close', 'POST' );
		$pass = $this->step( $ctx, $steps, 'Loader: Goal REST routes registered', $route_ok, $route_ok ? 'GET active + POST close routes registered.' : 'Goal REST route missing.' ) && $pass;

		$state_ok = method_exists( 'BizCity_TwinBrain_Goal_Loop_State', 'can_transition' )
			&& ! BizCity_TwinBrain_Goal_Loop_State::can_transition( 'completed', 'executing', array() );
		$pass = $this->step( $ctx, $steps, 'Runtime: terminal Goal transition remains locked', $state_ok, 'Completed goal cannot reopen through the UI contract.' ) && $pass;

		return array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Twin Goal Loop G10 REST/UI contract PASS.' : 'Twin Goal Loop G10 REST/UI contract failed.',
			'error' => $pass ? '' : 'twinbrain_goal_loop_ui_contract_failed',
			'fix_hint' => $pass ? '' : 'Check Goal REST registration, dist artifacts, and GoalLoopPanel/GoalStateModal wiring.',
			'steps' => $steps,
		);
	}

	private function step( $ctx, array &$steps, string $label, bool $passed, string $detail ): bool {
		$row = array( 'label' => $label, 'status' => $passed ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $row;
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( $row );
		}
		return $passed;
	}

	private function route_has_method( array $routes, string $route, string $method ): bool {
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		foreach ( $routes[ $route ] as $endpoint ) {
			$methods = is_array( $endpoint ) ? ( $endpoint['methods'] ?? array() ) : array();
			if ( is_string( $methods ) && false !== strpos( strtoupper( $methods ), strtoupper( $method ) ) ) {
				return true;
			}
			if ( is_array( $methods ) && ! empty( $methods[ strtoupper( $method ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	private function route_has_suffix_method( array $routes, string $prefix, string $suffix, string $method ): bool {
		$want = strtoupper( $method );
		foreach ( $routes as $route => $endpoints ) {
			if ( strpos( (string) $route, $prefix ) === false || substr( (string) $route, -strlen( $suffix ) ) !== $suffix || ! is_array( $endpoints ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				$methods = is_array( $endpoint ) ? ( $endpoint['methods'] ?? array() ) : array();
				if ( is_string( $methods ) && false !== strpos( strtoupper( $methods ), $want ) ) {
					return true;
				}
				if ( is_array( $methods ) && ! empty( $methods[ $want ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public function cleanup(): void {}
}

// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — register G10 REST/UI probe in the central Smoke Runner catalog.
add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinBrain_Goal_Loop_UI';
	return $probes;
} );
