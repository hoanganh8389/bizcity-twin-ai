<?php
/**
 * Production framework smoke checks for a booted WordPress installation.
 *
 * Run with:
 *   wp eval-file bin/framework-smoke.php --user=1
 *
 * @package BizCity_Twin_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "framework-smoke.php must run inside WordPress.\n" );
	exit( 2 );
}

// [2026-07-30 Johnny Chu] PHASE-1.22-PROD — executable WordPress framework smoke gate.
$failures = array();
$assert   = function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = (string) $message;
	}
};

$required_classes = array(
	'BizCity_Twin_Content_Registry',
	'BizCity_Twin_Capability_Consent',
	'BizCity_Twin_Capability_Guard',
	'BizCity_Twin_Security_Policy',
	'BizCity_Twin_Secret_Provider',
	'BizCity_Twin_SLO_Store',
	'BizCity_Twin_Runtime_Reliability',
	'BizCity_Twin_Reliable_HTTP',
	'BizCity_Twin_Mutation_Guard',
	'BizCity_Twin_Tool_Registry',
);
foreach ( $required_classes as $class_name ) {
	$assert( class_exists( $class_name ), 'Required framework class is not loaded: ' . $class_name );
}

$reference_plugin = dirname( __DIR__ ) . '/examples/bizcity-reference-plugin/bizcity-reference-plugin.php';
$assert( is_readable( $reference_plugin ), 'Reference extension is not readable.' );
if ( is_readable( $reference_plugin ) && class_exists( 'BizCity_Tool_Interface' ) ) {
	require_once $reference_plugin;
}

$assert(
	class_exists( 'BizCity_Twin_Capability_Consent' )
	&& BizCity_Twin_Capability_Consent::has_manifest( 'bizcity.reference' ),
	'Reference manifest was not registered at runtime.'
);

$groups = array(
	'tools',
	'skills',
	'agents',
	'channels',
	'kg_source_adapters',
	'workflow_blocks',
	'personas',
	'output_renderers',
);
foreach ( $groups as $group ) {
	$providers = class_exists( 'BizCity_Twin_Content_Registry' )
		? BizCity_Twin_Content_Registry::all( $group )
		: array();
	$assert( count( $providers ) >= 1, 'Reference provider missing from capability group: ' . $group );
}

if ( class_exists( 'BizCity_Twin_Content_Registry' ) ) {
	$assert(
		false === BizCity_Twin_Content_Registry::register( 'tools', new stdClass() ),
		'Invalid typed provider was accepted by the content registry.'
	);
}

if ( class_exists( 'BizCity_Twin_Capability_Consent' ) ) {
	if ( function_exists( 'get_current_user_id' ) && function_exists( 'wp_set_current_user' ) ) {
		$framework_smoke_admin_id = get_current_user_id();
		wp_set_current_user( 0 );
		$assert(
			false === BizCity_Twin_Capability_Consent::grant( 'bizcity.reference', 'kg.read', 'tenant' ),
			'Non-admin caller could mutate capability consent.'
		);
		wp_set_current_user( $framework_smoke_admin_id );
	}
	$assert(
		BizCity_Twin_Capability_Consent::grant( 'bizcity.reference', 'kg.read', 'tenant' ),
		'Could not persist a reference permission grant.'
	);
	$assert(
		BizCity_Twin_Capability_Consent::has( 'bizcity.reference', 'kg.read', array( 'user_id' => 0 ) ),
		'Persisted reference permission grant was not readable.'
	);
	$assert(
		BizCity_Twin_Capability_Consent::revoke( 'bizcity.reference', 'kg.read' ),
		'Could not revoke the reference permission grant.'
	);
	$assert(
		! BizCity_Twin_Capability_Consent::has( 'bizcity.reference', 'kg.read', array( 'user_id' => 0 ) ),
		'Revoked reference permission remained active.'
	);
}

if ( class_exists( 'BizCity_Twin_Runtime_Reliability' ) ) {
	$policy = BizCity_Twin_Runtime_Reliability::instance()->policy();
	$assert( ! empty( $policy['idempotency']['required'] ), 'Runtime policy does not require idempotency.' );
	$assert( ! empty( $policy['dead_letter']['enabled'] ), 'Runtime policy does not enable dead-letter handling.' );
	$assert( ! empty( $policy['trace']['propagate_headers'] ), 'Runtime policy has no trace headers.' );
}

if ( class_exists( 'BizCity_Twin_Secret_Provider' ) ) {
	$invalid_secret = BizCity_Twin_Secret_Provider::resolve( 'not-a-secret-ref' );
	$assert( 'secret_reference_invalid' === (string) ( $invalid_secret['code'] ?? '' ), 'Invalid secret reference was not rejected.' );

	$missing_secret = BizCity_Twin_Secret_Provider::resolve( 'BIZCITY_FRAMEWORK_SMOKE_MISSING' );
	$assert( 'secret_provider_unavailable' === (string) ( $missing_secret['code'] ?? '' ), 'Unavailable secret provider did not fail closed.' );

	if ( ! defined( 'BIZCITY_FRAMEWORK_SMOKE_SECRET' ) ) {
		define( 'BIZCITY_FRAMEWORK_SMOKE_SECRET', 'framework-smoke-only' );
	}
	$constant_secret = BizCity_Twin_Secret_Provider::resolve( 'BIZCITY_FRAMEWORK_SMOKE_SECRET' );
	$assert( ! empty( $constant_secret['ok'] ) && 'framework-smoke-only' === (string) ( $constant_secret['value'] ?? '' ), 'Explicit secret constant fallback did not resolve.' );
	if ( function_exists( 'wp_upload_dir' ) ) {
		$upload = wp_upload_dir();
		$audit_file = trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . 'bizcity-runtime-audit/' . gmdate( 'Y-m-d' ) . '.jsonl';
		$audit_body = is_readable( $audit_file ) ? (string) file_get_contents( $audit_file ) : '';
		$assert( false === strpos( $audit_body, 'framework-smoke-only' ), 'Secret value leaked into runtime audit evidence.' );
	}
}

if ( class_exists( 'BizCity_Twin_SLO_Store' ) && class_exists( 'BizCity_Twin_Runtime_Reliability' ) ) {
	BizCity_Twin_Runtime_Reliability::instance()->record_outcome(
		'framework_smoke',
		array( 'trace_id' => 'framework-smoke-slo-v1', 'secret' => 'must-not-persist' ),
		array( 'ok' => true, 'code' => 'ok' ),
		1,
		microtime( true ) - 0.001
	);
	$slo = BizCity_Twin_SLO_Store::summary( 'framework_smoke', 1 );
	$assert( (int) ( $slo['total'] ?? 0 ) >= 1, 'Persistent SLO store did not record an outcome.' );
	$assert( (int) ( $slo['success'] ?? 0 ) >= 1, 'Persistent SLO store did not record a successful outcome.' );
}

if ( class_exists( 'BizCity_Twin_Reliable_HTTP' ) && function_exists( 'add_filter' ) ) {
	$observed_http_args = array();
	$mock_http = function ( $pre, $args ) use ( &$observed_http_args ) {
		$observed_http_args = is_array( $args ) ? $args : array();
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	};
	add_filter( 'pre_http_request', $mock_http, 10, 3 );
	$mock_response = BizCity_Twin_Reliable_HTTP::request(
		'framework_smoke_http',
		'https://framework-smoke.invalid/health',
		array( 'method' => 'GET' )
	);
	remove_filter( 'pre_http_request', $mock_http, 10 );
	$assert( ! is_wp_error( $mock_response ) && 200 === (int) wp_remote_retrieve_response_code( $mock_response ), 'Reliable HTTP adapter did not preserve a successful response.' );
	$assert( ! empty( $observed_http_args['headers']['X-Trace-Id'] ), 'Reliable HTTP adapter omitted X-Trace-Id.' );
	$assert( ! empty( $observed_http_args['headers']['X-Idempotency-Key'] ), 'Reliable HTTP adapter omitted X-Idempotency-Key.' );
}

if ( class_exists( 'BizCity_Twin_Mutation_Guard' ) ) {
	$mutation = array(
		'contract'        => 'mutation-contract',
		'version'         => '1.0.0',
		'trace_id'        => 'framework-smoke-mutation-v1',
		'idempotency_key' => 'framework-smoke-mutation-v1',
		'action'          => 'create',
		'resource'        => array( 'type' => 'workflow', 'scope' => 'workflow:0' ),
	);
	$denied_mutation = BizCity_Twin_Mutation_Guard::validate( $mutation, array( 'permissions' => array() ) );
	$assert( 'permission_denied' === (string) ( $denied_mutation['code'] ?? '' ), 'Mutation guard did not reject a missing permission.' );
	$allowed_mutation = BizCity_Twin_Mutation_Guard::validate( $mutation, array( 'permissions' => array( 'content.write' ) ) );
	$assert( ! empty( $allowed_mutation['allowed'] ), 'Mutation guard rejected a valid create contract.' );
	$finance_mutation = $mutation;
	$finance_mutation['resource']['type'] = 'finance_entry';
	$finance_denied = BizCity_Twin_Mutation_Guard::validate( $finance_mutation, array( 'permissions' => array( 'content.write' ) ) );
	$finance_allowed = BizCity_Twin_Mutation_Guard::validate( $finance_mutation, array( 'permissions' => array( 'finance.write' ) ) );
	$assert( 'permission_denied' === (string) ( $finance_denied['code'] ?? '' ), 'Finance mutation accepted the broad content.write permission.' );
	$assert( ! empty( $finance_allowed['allowed'] ), 'Finance mutation rejected its dedicated finance.write permission.' );
}

if ( class_exists( 'BizCity_Twin_Capability_Guard' ) && function_exists( 'add_filter' ) ) {
	$user_scope_permission = function ( $permission, $tool_name, $tool ) {
		return '__framework_smoke_user_scope' === $tool_name ? 'memory.read' : $permission;
	};
	add_filter( 'bizcity_twin_tool_required_permission', $user_scope_permission, 10, 3 );
	$user_scope_decision = BizCity_Twin_Capability_Guard::authorize(
		'__framework_smoke_user_scope',
		null,
		array(
			'permissions'  => array( 'memory.read' ),
			'scope_level'  => 'user',
			'user_id'      => 0,
		)
	);
	remove_filter( 'bizcity_twin_tool_required_permission', $user_scope_permission, 10 );
	$assert( 'scope_mismatch' === (string) ( $user_scope_decision['code'] ?? '' ), 'User-scoped capability was authorized without a valid user identity.' );
}

if ( class_exists( 'BizCity_Twin_Capability_Consent' ) ) {
	$invalid_manifest = BizCity_Twin_Capability_Consent::register_manifest( array(
		'id'            => 'framework.invalid',
		'name'          => 'Invalid framework smoke manifest',
		'version'       => '1.0.0',
		'permissions'   => array( 'content.publish' ),
		'approval_gates'=> array(),
	) );
	$assert( false === $invalid_manifest, 'Runtime consent accepted a manifest without the required approval gate.' );
}

if ( class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
	$missing = BizCity_Twin_Tool_Registry::instance()->execute(
		'__framework_smoke_missing_tool',
		array(),
		array( 'idempotency_key' => 'framework-smoke-missing-tool-v1' )
	);
	$assert( 'tool_not_found' === (string) ( $missing['code'] ?? '' ), 'Tool boundary did not return tool_not_found.' );
	$assert( ! empty( $missing['trace_id'] ), 'Tool boundary omitted trace_id on an early error.' );
	$assert( ! empty( $missing['idempotency_key'] ), 'Tool boundary omitted idempotency_key on an early error.' );
}

if ( ! empty( $failures ) ) {
	echo "FRAMEWORK SMOKE FAIL\n";
	foreach ( $failures as $failure ) {
		echo " - " . $failure . "\n";
	}
	exit( 1 );
}

echo "FRAMEWORK SMOKE PASS\n";
