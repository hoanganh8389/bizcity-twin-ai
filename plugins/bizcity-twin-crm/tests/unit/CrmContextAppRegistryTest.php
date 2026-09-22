<?php
/** PHASE-0.63B WP-C — standalone registry/resolver acceptance. */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
$GLOBALS['test_filters'] = array();
function add_filter( $hook, $callback ) { $GLOBALS['test_filters'][ $hook ][] = $callback; }
function apply_filters( $hook, $value ) { foreach ( $GLOBALS['test_filters'][ $hook ] ?? array() as $callback ) { $value = $callback( $value ); } return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function current_user_can( $cap ) { return 'crm.inbox.read' === $cap; }
function get_current_blog_id() { return 1; }
function get_option( $key, $default = false ) { return $default; }

require dirname( __DIR__, 2 ) . '/includes/context/class-context-app-registry.php';
require dirname( __DIR__, 2 ) . '/includes/context/class-context-resolver.php';

$pass = 0; $fail = 0;
function check_context( $label, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }

$apps = array();
foreach ( array( 'crm', 'orders', 'debt', 'documents', 'extra1', 'extra2', 'extra3' ) as $index => $key ) {
	$apps[ $key ] = array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => $key, 'label' => $key,
		'entity' => 'Document', 'provider' => '', 'applies_to' => array(
			'subject_roles' => array( '*' ), 'pipeline_kinds' => array(), 'capability' => 'crm.inbox.read', 'channels' => array( '*' ),
		), 'surfaces' => array( 'b2' ), 'position' => $index,
		'render' => array( 'type' => 'component', 'id' => ucfirst( $key ) . 'Panel' ), 'rest' => array( '/' . $key ),
	);
}
add_filter( 'bizcity_crm_register_context_apps', static function ( $existing ) use ( $apps ) { return $apps; } );

$catalog = BizCity_CRM_Context_App_Registry::all();
check_context( 'registry returns seven code-owned apps', 7 === count( $catalog ) );
check_context( 'registry rejects no valid descriptors', array() === BizCity_CRM_Context_App_Registry::registration_issues() );
$result = BizCity_CRM_Context_Resolver::for_conversation( array( 'surface' => 'b2', 'subject_roles' => array( 'customer' ), 'limit' => 6 ) );
check_context( 'resolver returns six visible apps', 6 === count( $result['apps'] ) );
check_context( 'resolver exposes one more app', 1 === count( $result['more'] ) );
check_context( 'resolver marks limit reason', 'limit_reached' === $result['rejected']['extra3'] );
check_context( 'resolver is deterministic', $result === BizCity_CRM_Context_Resolver::for_conversation( array( 'surface' => 'b2', 'subject_roles' => array( 'customer' ), 'limit' => 6 ) ) );

BizCity_CRM_Context_App_Registry::flush_cache();
$GLOBALS['test_filters']['bizcity_crm_register_context_apps'][0] = static function ( $existing ) use ( $apps ) {
	$apps['crm/inbox'] = $apps['crm'];
	$apps['crm/inbox']['key'] = 'crm/inbox';
	return $apps;
};
check_context( 'invalid dotted key is reported and excluded', count( BizCity_CRM_Context_App_Registry::all() ) === 7 );
check_context( 'invalid dotted key has registration issue', count( BizCity_CRM_Context_App_Registry::registration_issues() ) === 1 );

BizCity_CRM_Context_App_Registry::flush_cache();
$GLOBALS['test_filters']['bizcity_crm_register_context_apps'][0] = static function ( $existing ) use ( $apps ) {
	$apps['bad'] = $apps['crm'];
	$apps['bad']['key'] = 'bad';
	$apps['bad']['applies_to']['capability'] = 'crm.inbox.read';
	$apps['bad']['rest'] = array( 'https://external.example.invalid/widget' );
	return $apps;
};
check_context( 'invalid REST target is excluded', count( BizCity_CRM_Context_App_Registry::all() ) === 7 );
check_context( 'invalid REST target has registration issue', count( BizCity_CRM_Context_App_Registry::registration_issues() ) === 1 );

BizCity_CRM_Context_App_Registry::flush_cache();
$scoped_apps = array(
	'customer' => array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'customer', 'label' => 'Customer',
		'entity' => 'Person', 'provider' => '', 'applies_to' => array(
			'subject_roles' => array( 'customer' ), 'pipeline_kinds' => array(), 'capability' => 'crm.inbox.read', 'channels' => array( '*' ),
		), 'surfaces' => array( 'b2' ), 'position' => 30,
		'render' => array( 'type' => 'component', 'id' => 'CustomerPanel' ), 'rest' => array( '/customers' ),
	),
	'service' => array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'service', 'label' => 'Service',
		'entity' => 'Work', 'provider' => 'field-service', 'applies_to' => array(
			'subject_roles' => array( 'customer' ), 'pipeline_kinds' => array( 'service' ), 'capability' => 'crm.inbox.read', 'channels' => array( 'zalo_personal' ),
		), 'surfaces' => array( 'b2' ), 'position' => 20,
		'render' => array( 'type' => 'component', 'id' => 'ServicePanel' ), 'rest' => array( '/service' ),
	),
	'admin' => array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'admin', 'label' => 'Admin',
		'entity' => 'Work', 'provider' => '', 'applies_to' => array(
			'subject_roles' => array( '*' ), 'pipeline_kinds' => array(), 'capability' => 'crm.admin.only', 'channels' => array( '*' ),
		), 'surfaces' => array( 'b2' ), 'position' => 10,
		'render' => array( 'type' => 'component', 'id' => 'AdminPanel' ), 'rest' => array( '/admin-context' ),
	),
);
add_filter( 'bizcity_crm_register_context_apps', static function ( $existing ) use ( $scoped_apps ) { return $scoped_apps; } );
$customer_result = BizCity_CRM_Context_Resolver::for_conversation( array(
	'surface' => 'b2', 'subject_roles' => array( 'customer' ), 'pipeline_kind' => 'service', 'channel' => 'zalo_personal',
	'capabilities' => array( 'crm.inbox.read' ), 'definition_tools' => array( 'service', 'customer' ), 'limit' => 6,
) );
check_context( 'resolver keeps role/kind/channel matching apps', count( $customer_result['apps'] ) === 2 );
check_context( 'definition tools prioritize service app', $customer_result['apps'][0]['key'] === 'service' );
check_context( 'capability denial is reported', 'capability_denied' === $customer_result['rejected']['admin'] );

$wrong_role_result = BizCity_CRM_Context_Resolver::for_conversation( array(
	'surface' => 'b2', 'subject_roles' => array( 'supplier' ), 'pipeline_kind' => 'sales', 'channel' => 'email',
	'capabilities' => array( 'crm.inbox.read' ), 'limit' => 6,
) );
check_context( 'role/kind/channel mismatch is reported', 'subject_role_mismatch' === $wrong_role_result['rejected']['customer'] );
check_context( 'service app is rejected for wrong role', 'subject_role_mismatch' === $wrong_role_result['rejected']['service'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
