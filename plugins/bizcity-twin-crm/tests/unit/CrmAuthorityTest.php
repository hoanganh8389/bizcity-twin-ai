<?php
/** PHASE-0.60 C60-A03 — standalone persona matrix for CRM authority. */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
$GLOBALS['crm_test_users'] = array(
	1 => array( 'administrator' ), 10 => array( 'editor' ), 20 => array( 'bizcity_crm_staff' ), 30 => array( 'subscriber' ),
);
function get_current_user_id() { return $GLOBALS['crm_current_user'] ?? 0; }
function get_current_blog_id() { return 1; }
function is_super_admin( $id = 0 ) { return (int) $id === 1; }
function is_user_member_of_blog( $id, $blog ) { return (int) $id !== 30; }
function user_can( $id, $cap ) {
	$id = (int) $id;
	if ( 1 === $id && in_array( $cap, array( 'manage_options', 'manage_network' ), true ) ) return true;
	if ( 'bizcity_crm_handle_inbox' === $cap && in_array( $id, array( 10, 20 ), true ) ) return true;
	return false;
}
function current_user_can( $cap ) { return user_can( get_current_user_id(), $cap ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
class BizCity_CRM_Staff_Policy {
	public static function role( $id ) { return 10 === (int) $id ? 'lead' : ( 20 === (int) $id ? 'agent' : ( 1 === (int) $id ? 'admin' : 'none' ) ); }
	public static function can( $actor, $action, $subject = 0 ) { return array( 'ok' => 10 === (int) $actor && 'team.dashboard' === $action ); }
}
class BizCity_CRM_Capabilities { public static function user_can_handle_inbox( $id = 0 ) { return in_array( (int) $id, array( 1, 10, 20 ), true ); } }
require dirname( __DIR__, 2 ) . '/includes/contracts/class-crm-actor.php';
require dirname( __DIR__, 2 ) . '/includes/contracts/class-crm-authority.php';
$pass = 0; $fail = 0;
function crm_check( $label, $ok ) { global $pass, $fail; $ok ? $pass++ : ( $fail++ . print "FAIL: {$label}\n" ); }
foreach ( array( 1, 10, 20, 30 ) as $id ) {
	$GLOBALS['crm_current_user'] = $id;
	crm_check( "{$id} inbox open", (bool) BizCity_CRM_Authority::can( 'crm.inbox.open' )['ok'] === ( $id !== 30 ) );
}
$GLOBALS['crm_current_user'] = 10;
crm_check( 'lead can use AI', BizCity_CRM_Authority::can( 'crm.ai.use' )['ok'] );
crm_check( 'lead cannot manage settings', ! BizCity_CRM_Authority::can( 'crm.settings.manage' )['ok'] );
$GLOBALS['crm_current_user'] = 30;
crm_check( 'subscriber cannot use CRM', ! BizCity_CRM_Authority::can( 'crm.inbox.open' )['ok'] );
printf("\n%d passed, %d failed\n", $pass, $fail);
exit( $fail ? 1 : 0 );
