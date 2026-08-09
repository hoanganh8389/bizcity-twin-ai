<?php
/**
 * DDV probe for the core production security/reliability boundary.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Framework_Production_Contract', false ) ) {
	return;
}

final class BizCity_Probe_Framework_Production_Contract implements BizCity_Diagnostics_Probe {
	public function id(): string { return 'core.framework.production_contract'; }
	public function label(): string { return 'Framework production security and reliability contract'; }
	public function description(): string { return 'Kiểm tra central secret, SLO, reliable HTTP và mutation guard qua Disk/Loader/Runtime.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 18; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 500; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-07-30 Johnny Chu] PHASE-1.22-DDV — prove the shared production boundary at runtime.
		$steps = array();
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$files = array(
			'core/twin-core/includes/class-twin-secret-provider.php',
			'core/twin-core/includes/class-twin-slo-store.php',
			'core/twin-core/includes/class-twin-reliable-http.php',
			'core/twin-core/includes/class-twin-mutation-guard.php',
		);
		$missing = array();
		foreach ( $files as $relative ) {
			if ( ! is_readable( $root . $relative ) ) {
				$missing[] = $relative;
			}
		}
		$steps[] = array(
			'label'  => 'Disk — production boundary classes exist',
			'status' => empty( $missing ) ? 'pass' : 'fail',
			'detail' => empty( $missing ) ? implode( ', ', $files ) : implode( ', ', $missing ),
		);
		if ( ! empty( $missing ) ) {
			return array( 'status' => 'fail', 'summary' => 'Production boundary class is missing.', 'steps' => $steps );
		}

		$classes = array( 'BizCity_Twin_Secret_Provider', 'BizCity_Twin_SLO_Store', 'BizCity_Twin_Reliable_HTTP', 'BizCity_Twin_Mutation_Guard', 'BizCity_Twin_Capability_Consent' );
		$missing_classes = array();
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing_classes[] = $class;
			}
		}
		$steps[] = array(
			'label'  => 'Loader — central production classes loaded',
			'status' => empty( $missing_classes ) ? 'pass' : 'fail',
			'detail' => empty( $missing_classes ) ? implode( ', ', $classes ) : implode( ', ', $missing_classes ),
		);
		if ( ! empty( $missing_classes ) ) {
			return array( 'status' => 'fail', 'summary' => 'Production boundary class is not loaded.', 'steps' => $steps );
		}

		$invalid = BizCity_Twin_Secret_Provider::resolve( 'not-a-secret-reference' );
		$secret_ok = 'secret_reference_invalid' === (string) ( $invalid['code'] ?? '' );
		$steps[] = array(
			'label'  => 'Runtime — invalid secret reference fails closed',
			'status' => $secret_ok ? 'pass' : 'fail',
			'detail' => (string) ( $invalid['code'] ?? '' ),
		);

		$mutation = BizCity_Twin_Mutation_Guard::validate(
			array(
				'contract'        => 'mutation-contract',
				'version'         => '1.0.0',
				'trace_id'        => 'probe-framework-production',
				'idempotency_key' => 'probe-framework-production',
				'action'          => 'create',
				'resource'        => array( 'type' => 'probe', 'scope' => 'probe' ),
			),
			array( 'permissions' => array( 'content.write' ) )
		);
		$mutation_ok = ! empty( $mutation['allowed'] );
		$steps[] = array(
			'label'  => 'Runtime — valid mutation contract is authorized',
			'status' => $mutation_ok ? 'pass' : 'fail',
			'detail' => (string) ( $mutation['code'] ?? '' ),
		);

		$consent_ok = false;
		if ( class_exists( 'BizCity_Twin_Capability_Consent' ) && function_exists( 'get_current_user_id' ) && function_exists( 'wp_set_current_user' ) ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — prove direct consent mutation fails closed for non-admin runtime callers.
			$probe_manifest = array(
				'id'            => 'probe.framework.production',
				'name'          => 'Framework production probe',
				'schema_version'=> '1.0',
				'version'       => '1.0.0',
				'permissions'   => array( 'kg.read' ),
				'approval_gates'=> array(),
			);
			$registered = BizCity_Twin_Capability_Consent::register_manifest( $probe_manifest );
			$admin_id   = get_current_user_id();
			if ( $registered ) {
				wp_set_current_user( 0 );
				$consent_ok = false === BizCity_Twin_Capability_Consent::grant( 'probe.framework.production', 'kg.read', 'tenant' );
				wp_set_current_user( $admin_id );
				if ( $admin_id > 0 ) {
					BizCity_Twin_Capability_Consent::revoke( 'probe.framework.production', 'kg.read' );
				}
			}
		}
		$steps[] = array(
			'label'  => 'Runtime — non-admin consent mutation fails closed',
			'status' => $consent_ok ? 'pass' : 'fail',
		);

		$scope_ok = false;
		if ( class_exists( 'BizCity_Twin_Capability_Guard' ) && function_exists( 'add_filter' ) ) {
			$scope_filter = function ( $permission, $tool_name, $tool ) {
				return 'probe.framework.user_scope' === $tool_name ? 'memory.read' : $permission;
			};
			add_filter( 'bizcity_twin_tool_required_permission', $scope_filter, 10, 3 );
			$scope_decision = BizCity_Twin_Capability_Guard::authorize(
				'probe.framework.user_scope',
				null,
				array(
					'permissions' => array( 'memory.read' ),
					'scope_level' => 'user',
					'user_id'     => 0,
				)
			);
			remove_filter( 'bizcity_twin_tool_required_permission', $scope_filter, 10 );
			$scope_ok = 'scope_mismatch' === (string) ( $scope_decision['code'] ?? '' );
		}
		$steps[] = array(
			'label'  => 'Runtime — user-scoped capability requires verified user identity',
			'status' => $scope_ok ? 'pass' : 'fail',
		);

		$http_ok = false;
		$http_args = array();
		$mock = function ( $pre, $args ) use ( &$http_args ) {
			$http_args = is_array( $args ) ? $args : array();
			return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		};
		if ( function_exists( 'add_filter' ) && function_exists( 'remove_filter' ) ) {
			add_filter( 'pre_http_request', $mock, 10, 3 );
			$response = BizCity_Twin_Reliable_HTTP::request( 'probe.framework.production', 'https://framework-production.invalid/health', array( 'method' => 'GET' ) );
			remove_filter( 'pre_http_request', $mock, 10 );
			$http_ok = ! is_wp_error( $response )
				&& 200 === (int) wp_remote_retrieve_response_code( $response )
				&& ! empty( $http_args['headers']['X-Trace-Id'] )
				&& ! empty( $http_args['headers']['X-Idempotency-Key'] );
		}
		$steps[] = array(
			'label'  => 'Runtime — reliable HTTP preserves response and trace/idempotency headers',
			'status' => $http_ok ? 'pass' : 'fail',
		);

		$status = $secret_ok && $mutation_ok && $consent_ok && $scope_ok && $http_ok ? 'pass' : 'fail';
		return array(
			'status'  => $status,
			'summary' => $status === 'pass' ? 'Core production security and reliability contract passed.' : 'Core production contract has runtime failures.',
			'fix_hint' => 'Load the central Twin security/reliability classes before module consumers and rerun this probe.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Framework_Production_Contract';
	return $probes;
} );
