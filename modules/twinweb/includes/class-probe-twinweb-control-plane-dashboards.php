<?php
/**
 * BizCity Diagnostics — modules.twin_gpt.control_plane_dashboards probe.
 *
 * R-DDV: 3-layer evidence for Twin GPT Control Plane dashboard endpoints.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-18 (PHASE-TWINWEB-CP)
 */

// [2026-07-18 Johnny Chu] PHASE-TWINWEB-CP — DDV probe for live Control Plane dashboard endpoints.
defined( 'ABSPATH' ) || exit;

$bizcity_twinweb_plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR
	: dirname( __DIR__, 3 ) . '/';
$bizcity_twinweb_probe_iface = $bizcity_twinweb_plugin_root . 'core/diagnostics/includes/interface-diagnostics-probe.php';
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $bizcity_twinweb_probe_iface ) ) {
	require_once $bizcity_twinweb_probe_iface;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Control_Plane_Dashboards', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Control_Plane_Dashboards implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.control_plane_dashboards'; }
	public function label(): string { return 'Twin GPT · Control Plane Dashboards'; }
	public function description(): string {
		return 'Disk / Loader / Runtime: /admin/astro, /admin/automation, /admin/commerce, /admin/appearance, /admin/grounding, /admin/model-policy and membership plans-sheet payload contracts.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 89; }
	public function icon(): string { return 'layout-dashboard'; }
	public function estimate_ms(): int { return 35; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$rest_file = __DIR__ . '/class-twinweb-rest.php';
		$membership_rest_file = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'core/membership/includes/class-membership-rest.php'
			: dirname( __DIR__, 3 ) . '/core/membership/includes/class-membership-rest.php';
		$tabs_file = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'core/channel-gateway/frontend/src/routes/platform/twinweb/TwinWebControlPlaneTabs.jsx'
			: dirname( __DIR__, 3 ) . '/core/channel-gateway/frontend/src/routes/platform/twinweb/TwinWebControlPlaneTabs.jsx';
		$cg_root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'core/channel-gateway/frontend/'
			: dirname( __DIR__, 3 ) . '/core/channel-gateway/frontend/';
		$cg_dist_ok = is_readable( $cg_root . 'dist/.vite/manifest.json' ) || is_dir( $cg_root . 'dist/assets' );
		$rest_src = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$membership_src = is_readable( $membership_rest_file ) ? (string) file_get_contents( $membership_rest_file ) : '';
		$tabs_src = is_readable( $tabs_file ) ? (string) file_get_contents( $tabs_file ) : '';

		// [2026-07-18 Johnny Chu] SPRINT-28 DDV-FIX — accept current route/component markers instead of quote-style stale markers.
		$tabs_source_present = '' !== $tabs_src;
		$disk_markers = array(
			'route_astro'       => false !== strpos( $rest_src, '/admin/astro' ),
			'route_automation'  => false !== strpos( $rest_src, '/admin/automation' ),
			'route_commerce'    => false !== strpos( $rest_src, '/admin/commerce' ),
			'route_appearance'  => false !== strpos( $rest_src, '/admin/appearance' ),
			// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-CP — include runtime grounding tab in Control Plane DDV coverage.
			'route_grounding'   => false !== strpos( $rest_src, '/admin/grounding' ),
			// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — include model policy tab and effective model contract.
			'route_model_policy'=> false !== strpos( $rest_src, '/admin/model-policy' ) && false !== strpos( $rest_src, '/models/effective' ),
			// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-CP — include dedicated Plans & Capabilities tab and membership plan sheet endpoint.
			'route_plans_sheet' => false !== strpos( $membership_src, '/admin/plans-sheet' ),
			'component_astro'   => ! $tabs_source_present || false !== strpos( $tabs_src, 'TwinWebAstro' ),
			'component_auto'    => ! $tabs_source_present || false !== strpos( $tabs_src, 'TwinWebAutomation' ),
			'component_commerce'=> ! $tabs_source_present || false !== strpos( $tabs_src, 'TwinWebCommerce' ),
			'component_appear'  => ! $tabs_source_present || false !== strpos( $tabs_src, 'TwinWebAppearance' ),
			'component_ground'  => ! $tabs_source_present || false !== strpos( $tabs_src, 'TwinWebGrounding' ),
			'component_models'  => ! $tabs_source_present || false !== strpos( $tabs_src, 'TwinWebModelPolicy' ),
			'component_plans'   => ! $tabs_source_present || false !== strpos( $tabs_src, 'TwinWebPlansCapabilities' ),
		);
		// [2026-07-18 Johnny Chu] SPRINT-30 DDV-FE-DIST — server deployments may omit React source; route/runtime checks remain authoritative.
		$disk_ok = '' !== $rest_src && ( $tabs_source_present || $cg_dist_ok || ! is_readable( $tabs_file ) ) && ! in_array( false, $disk_markers, true );
		$step = array(
			'label'  => 'Disk · dashboard REST + FE tab markers',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? ( $tabs_source_present ? 'REST routes and FE tab component markers found for Astro, Automation, Commerce, Appearance, Grounding, Models and Plans.' : 'React source is absent on this deployment; REST route markers are present and runtime dashboard payload checks validate the Control Plane contract.' )
				: 'Missing markers: ' . implode( ', ', array_keys( array_filter( $disk_markers, static function ( $ok ) { return ! $ok; } ) ) ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_ok ) { $pass = false; }

		$class_ok = class_exists( 'BizCity_TwinWeb_REST' );
		$methods_ok = $class_ok
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_astro' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_automation' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_commerce' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_appearance' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_grounding' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_put_grounding' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_model_policy' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_put_model_policy' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'list_models_effective' );
		$plans_methods_ok = class_exists( 'BizCity_Membership_REST' )
			&& method_exists( 'BizCity_Membership_REST', 'admin_get_plans_sheet' )
			&& method_exists( 'BizCity_Membership_REST', 'admin_put_plans_sheet' );
		$routes = class_exists( 'WP_REST_Server' ) ? rest_get_server()->get_routes() : array();
		$route_astro = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/astro', 'GET' );
		$route_auto  = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/automation', 'GET' );
		$route_com   = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/commerce', 'GET' );
		$route_app   = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/appearance', 'GET' );
		$route_ground_get = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/grounding', 'GET' );
		$route_ground_put = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/grounding', 'PUT' );
		$route_model_get = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/model-policy', 'GET' );
		$route_model_put = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/model-policy', 'PUT' );
		$route_models_effective = $this->route_has_method( $routes, '/bizcity-twinweb/v1/models/effective', 'GET' );
		$route_plans_get = $this->route_has_method( $routes, '/bizcity-membership/v1/admin/plans-sheet', 'GET' );
		$route_plans_put = $this->route_has_method( $routes, '/bizcity-membership/v1/admin/plans-sheet', 'PUT' );
		$loader_ok = $methods_ok && $plans_methods_ok && $route_astro && $route_auto && $route_com && $route_app && $route_ground_get && $route_ground_put && $route_model_get && $route_model_put && $route_models_effective && $route_plans_get && $route_plans_put;
		$step = array(
			'label'  => 'Loader · dashboard methods + routes',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'methods=%s ; plans_methods=%s ; astro=%s ; automation=%s ; commerce=%s ; appearance=%s ; grounding_get=%s ; grounding_put=%s ; model_get=%s ; model_put=%s ; models_effective=%s ; plans_get=%s ; plans_put=%s',
				$methods_ok ? 'ok' : 'missing',
				$plans_methods_ok ? 'ok' : 'missing',
				$route_astro ? 'ok' : 'missing',
				$route_auto ? 'ok' : 'missing',
				$route_com ? 'ok' : 'missing',
				$route_app ? 'ok' : 'missing',
				$route_ground_get ? 'ok' : 'missing',
				$route_ground_put ? 'ok' : 'missing',
				$route_model_get ? 'ok' : 'missing',
				$route_model_put ? 'ok' : 'missing',
				$route_models_effective ? 'ok' : 'missing',
				$route_plans_get ? 'ok' : 'missing',
				$route_plans_put ? 'ok' : 'missing'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_ok ) { $pass = false; }

		$runtime_ok = false;
		$runtime_detail = 'Skipped: REST runtime unavailable.';
		if ( $class_ok && class_exists( 'WP_REST_Request' ) ) {
			try {
				$rest = BizCity_TwinWeb_REST::instance();
				$astro = $this->response_data( $rest->admin_get_astro( new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/astro' ) ) );
				$auto_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/automation' );
				$auto_req->set_param( 'limit', 5 );
				$automation = $this->response_data( $rest->admin_get_automation( $auto_req ) );
				$commerce_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/commerce' );
				$commerce_req->set_param( 'limit', 5 );
				$commerce = $this->response_data( $rest->admin_get_commerce( $commerce_req ) );
				$appearance = $this->response_data( $rest->admin_get_appearance( new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/appearance' ) ) );
				$grounding = $this->response_data( $rest->admin_get_grounding( new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/grounding' ) ) );
				$model_policy = $this->response_data( $rest->admin_get_model_policy( new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/model-policy' ) ) );
				$models_effective = $this->response_data( $rest->list_models_effective( new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/models/effective' ) ) );
				$plans = class_exists( 'BizCity_Membership_REST' )
					? $this->response_data( BizCity_Membership_REST::admin_get_plans_sheet( new WP_REST_Request( 'GET', '/bizcity-membership/v1/admin/plans-sheet' ) ) )
					: array();

				$runtime_ok = ! empty( $astro['success'] )
					&& isset( $astro['summary'], $astro['tables'] )
					&& ! empty( $automation['success'] )
					&& isset( $automation['summary'], $automation['status_counts'], $automation['recent_runs'] )
					&& ! empty( $commerce['success'] )
					&& isset( $commerce['capacity'], $commerce['queue']['summary'], $commerce['queue']['summary']['refunded'], $commerce['queue']['summary']['cancelled'] )
					&& ! empty( $appearance['success'] )
					&& isset( $appearance['catalog'], $appearance['policy'], $appearance['cp_ver'] )
					&& ! empty( $grounding['success'] )
					&& isset( $grounding['policy']['profile'], $grounding['policy']['citation_mode'], $grounding['policy']['override_guru'] )
					&& ! empty( $model_policy['success'] )
					&& isset( $model_policy['policy']['catalog'], $model_policy['policy']['presets'], $model_policy['cp_ver'] )
					&& ! empty( $models_effective['success'] )
					&& isset( $models_effective['items'], $models_effective['default_model_id'], $models_effective['runtime_budget'] )
					&& ! empty( $plans['success'] )
					&& isset( $plans['plans'], $plans['woo_offer_summary'] )
					&& is_array( $plans['plans'] );
				$runtime_detail = $runtime_ok
					? 'All dashboard payloads expose expected summary/table/queue/policy shapes, including Woo reversal buckets, Grounding runtime policy, Models policy and Plans sheet.'
					: 'One or more dashboard payloads missing expected keys.';
			} catch ( Throwable $e ) {
				$runtime_detail = 'Exception while reading dashboard payloads: ' . $e->getMessage();
			}
		}

		$step = array(
			'label'  => 'Runtime · dashboard payload shape checks',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $runtime_ok ) { $pass = false; }

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Twin GPT Control Plane dashboards are registered and return expected payload shapes.' : 'Twin GPT Control Plane dashboard contract is incomplete.',
			'error'    => $pass ? '' : 'twinweb_control_plane_dashboards_failed',
			'fix_hint' => $pass ? '' : 'Check admin dashboard routes/handlers and Commerce queue summary keys.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe; no artifacts to clean up.
	}

	private function response_data( $response ) {
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			$data = $response->get_data();
			return is_array( $data ) ? $data : array();
		}
		if ( is_array( $response ) ) {
			return $response;
		}
		return array();
	}

	private function route_has_method( array $routes, $route, $method ) {
		if ( empty( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		$method = strtoupper( (string) $method );
		foreach ( $routes[ $route ] as $handler ) {
			$methods = isset( $handler['methods'] ) ? $handler['methods'] : array();
			if ( is_string( $methods ) ) {
				$methods = array( $methods => true );
			}
			if ( isset( $methods[ $method ] ) || in_array( $method, (array) $methods, true ) ) {
				return true;
			}
		}
		return false;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ) {
	if ( class_exists( 'BizCity_Probe_TwinWeb_Control_Plane_Dashboards', false ) ) {
		$probes[] = new BizCity_Probe_TwinWeb_Control_Plane_Dashboards();
	}
	return $probes;
} );
