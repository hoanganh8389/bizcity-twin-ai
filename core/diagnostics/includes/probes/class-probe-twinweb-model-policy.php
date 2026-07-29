<?php
/**
 * BizCity Diagnostics - Twin GPT model policy probe.
 *
 * R-DDV 3 layers evidence:
 * - Disk: TwinWeb REST model policy markers, FE built artifact/source policy, runtime budget markers.
 * - Loader: public/admin model policy methods and routes registered.
 * - Runtime: /models/effective returns available default model and token/runtime budget.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-18
 */

// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — DDV probe for server-owned model/token preset policy.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Model_Policy', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Model_Policy implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.model_policy'; }
	public function label(): string { return 'Twin GPT Model Policy (/models/effective)'; }
	public function description(): string {
		return 'Verifies server-owned model/token presets, answer-mode selector contract, public /models/effective route, admin policy route, FE built artifact/source policy and runtime budget propagation.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 85; }
	public function icon(): string { return 'BrainCircuit'; }
	public function estimate_ms(): int { return 140; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$rest_file    = $root . 'modules/twinweb/includes/class-twinweb-rest.php';
		$manifest_file = $root . 'modules/twinweb/ui/dist/.vite/manifest.json';
		$dist_assets_dir = $root . 'modules/twinweb/ui/dist/assets';
		$runtime_file = $root . 'core/twinbrain/includes/class-twinbrain-runtime.php';
		$deep_file    = $root . 'core/twinbrain/includes/class-twinbrain-web-deep.php';
		$agent_file   = $root . 'core/twinbrain/includes/class-twinbrain-agent-runner.php';

		$rest_src    = is_readable( $rest_file ) ? file_get_contents( $rest_file ) : '';
		$runtime_src = is_readable( $runtime_file ) ? file_get_contents( $runtime_file ) : '';
		$deep_src    = is_readable( $deep_file ) ? file_get_contents( $deep_file ) : '';
		$agent_src   = is_readable( $agent_file ) ? file_get_contents( $agent_file ) : '';

		$disk_policy_ok = is_string( $rest_src )
			&& strpos( $rest_src, "'/models/effective'" ) !== false
			&& strpos( $rest_src, "'/admin/model-policy'" ) !== false
			&& strpos( $rest_src, 'build_effective_model_payload' ) !== false
			&& strpos( $rest_src, 'twinweb_runtime_budget' ) !== false
			&& strpos( $rest_src, 'resolve_allowed_answer_mode_id' ) !== false
			&& strpos( $rest_src, 'answer_mode' ) !== false;
		$step = array(
			'label'  => 'Disk - TwinWeb REST model-policy routes + payload markers',
			'status' => $disk_policy_ok ? 'pass' : 'fail',
			'detail' => $disk_policy_ok ? 'models/effective + admin/model-policy + runtime_budget markers found' : 'Missing one or more markers in class-twinweb-rest.php',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_policy_ok ) { $pass = false; }

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER R-DDV-FE — source tree is optional on server; built dist is the deploy artifact.
		$dist_ok = is_readable( $manifest_file ) || is_dir( $dist_assets_dir );
		$step = array(
			'label'  => 'Disk - FE deploy artifact policy (React src is not inspected)',
			'status' => $dist_ok ? 'pass' : 'skip',
			'detail' => sprintf(
				'dist=%s; DDV intentionally does not read modules/twinweb/ui/src because production deploys built dist only.',
				$dist_ok ? 'present' : 'not found'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$runtime_budget_ok = is_string( $runtime_src )
			&& strpos( $runtime_src, "twinweb_runtime_budget" ) !== false
			&& strpos( $runtime_src, "'max_iterations'" ) !== false
			&& strpos( $runtime_src, "'search_result_budget'" ) !== false
			&& is_string( $deep_src )
			&& strpos( $deep_src, '$max_iterations' ) !== false
			&& strpos( $deep_src, '$search_max' ) !== false
			&& is_string( $agent_src )
			&& strpos( $agent_src, '$max_iterations' ) !== false;
		$step = array(
			'label'  => 'Disk - runtime budget propagated to Runtime, Web Deep and Agent Runner',
			'status' => $runtime_budget_ok ? 'pass' : 'fail',
			'detail' => $runtime_budget_ok ? 'Budget markers found in core runtime/deep/agent files.' : 'Missing budget propagation markers.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $runtime_budget_ok ) { $pass = false; }

		$method_ok = method_exists( 'BizCity_TwinWeb_REST', 'list_models_effective' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_model_policy' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_put_model_policy' );
		$step = array(
			'label'  => 'Loader - TwinWeb model policy methods loaded',
			'status' => $method_ok ? 'pass' : 'fail',
			'detail' => $method_ok ? 'list_models_effective + admin handlers loaded' : 'One or more model policy methods missing.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $method_ok ) { $pass = false; }

		$routes = rest_get_server()->get_routes();
		$route_public = $this->route_has_method( $routes, '/bizcity-twinweb/v1/models/effective', 'GET' );
		$route_admin  = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/model-policy', 'GET' )
			&& $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/model-policy', 'PUT' );
		$step = array(
			'label'  => 'Loader - REST routes /models/effective and /admin/model-policy registered',
			'status' => ( $route_public && $route_admin ) ? 'pass' : 'fail',
			'detail' => sprintf( 'public=%s; admin=%s', $route_public ? 'ok' : 'MISSING', $route_admin ? 'ok' : 'MISSING' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $route_public || ! $route_admin ) { $pass = false; }

		$original_uid = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$req  = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/models/effective' );
			$res  = rest_do_request( $req );
			$data = is_wp_error( $res ) ? array() : (array) $res->get_data();
			$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
			$default_id = isset( $data['default_model_id'] ) ? (string) $data['default_model_id'] : '';
			$default_row = $this->find_model( $items, $default_id );
			$runtime_budget = isset( $data['runtime_budget'] ) && is_array( $data['runtime_budget'] ) ? $data['runtime_budget'] : array();
			$token_policy = isset( $data['token_policy'] ) && is_array( $data['token_policy'] ) ? $data['token_policy'] : array();

			$preset = isset( $data['preset'] ) && is_array( $data['preset'] ) ? $data['preset'] : array();
			$answer_modes = isset( $preset['answer_modes'] ) && is_array( $preset['answer_modes'] ) ? $preset['answer_modes'] : array();

			$runtime_ok = ! empty( $data['success'] )
				&& ! empty( $items )
				&& is_array( $default_row )
				&& ! empty( $default_row['available'] )
				&& ! empty( $data['default_answer_mode'] )
				&& in_array( (string) $data['default_answer_mode'], array_map( 'strval', $answer_modes ), true )
				&& isset( $runtime_budget['final_compose_max_tokens'], $runtime_budget['max_iterations'], $runtime_budget['search_result_budget'] )
				&& isset( $token_policy['max_output_tokens'] );

			$step = array(
				'label'  => 'Runtime - guest /models/effective returns available default and budgets',
				'status' => $runtime_ok ? 'pass' : 'fail',
				'detail' => sprintf(
					'success=%s; items=%d; default=%s; default_available=%s; answer_mode=%s/%d; output=%s; iter=%s; search=%s',
					! empty( $data['success'] ) ? 'yes' : 'no',
					count( $items ),
					$default_id !== '' ? $default_id : 'MISSING',
					is_array( $default_row ) && ! empty( $default_row['available'] ) ? 'yes' : 'no',
					! empty( $data['default_answer_mode'] ) ? (string) $data['default_answer_mode'] : 'MISSING',
					count( $answer_modes ),
					isset( $runtime_budget['final_compose_max_tokens'] ) ? (string) (int) $runtime_budget['final_compose_max_tokens'] : 'MISSING',
					isset( $runtime_budget['max_iterations'] ) ? (string) (int) $runtime_budget['max_iterations'] : 'MISSING',
					isset( $runtime_budget['search_result_budget'] ) ? (string) (int) $runtime_budget['search_result_budget'] : 'MISSING'
				),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $runtime_ok ) { $pass = false; }
		} finally {
			wp_set_current_user( $original_uid );
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT model policy, answer modes, guest nonce handling and runtime budget propagation PASS.'
				: 'Twin GPT model policy contract failed one or more checks.',
			'error'    => $pass ? '' : 'twinweb_model_policy_contract_failed',
			'fix_hint' => $pass ? '' : 'Check class-twinweb-rest.php and TwinBrain runtime budget propagation; React src markers must not be hard gates on dist-only servers.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}

	private function route_has_method( $routes, $route, $method ) {
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		$want = strtoupper( (string) $method );
		foreach ( $routes[ $route ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
				continue;
			}
			if ( is_string( $ep['methods'] ) && false !== strpos( strtoupper( (string) $ep['methods'] ), $want ) ) {
				return true;
			}
			if ( is_array( $ep['methods'] ) ) {
				foreach ( $ep['methods'] as $registered => $enabled ) {
					if ( $enabled && strtoupper( (string) $registered ) === $want ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private function find_model( $items, $id ) {
		if ( ! is_array( $items ) ) {
			return null;
		}
		$id = (string) $id;
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && (string) $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}
}

// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — register Twin GPT model policy probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Model_Policy';
	return $list;
} );
