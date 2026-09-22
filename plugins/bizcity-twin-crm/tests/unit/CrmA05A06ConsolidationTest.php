<?php
/**
 * PHASE-0.60 C60-A05/A06 — standalone regression test for the `can_use_crm()`
 * consolidation (3 near-duplicate definitions → `BizCity_CRM_Authority::can()`)
 * and the "is admin" consolidation (`BizCity_CRM_Inbox_Access::is_admin()`,
 * `BizCity_CRM_Capabilities::user_can_handle_inbox()` now both cover Super
 * Admin explicitly instead of only `manage_options`).
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

$GLOBALS['crm_super_admin_id'] = 1;   // network Super Admin, no local blog role
$GLOBALS['crm_local_admin_id'] = 2;   // per-blog administrator
$GLOBALS['crm_editor_id']      = 10;  // WP editor role (has bizcity_crm_handle_inbox via map())
$GLOBALS['crm_staff_id']       = 20;  // bizcity_crm_staff custom role (agent)
$GLOBALS['crm_subscriber_id']  = 30;  // no CRM access at all

function get_current_user_id() { return $GLOBALS['crm_current_user'] ?? 0; }
function get_current_blog_id() { return 1; }
function is_super_admin( $id = 0 ) { $id = $id ?: get_current_user_id(); return (int) $id === $GLOBALS['crm_super_admin_id']; }
function is_user_member_of_blog( $id, $blog ) { return (int) $id !== $GLOBALS['crm_subscriber_id']; }
function user_can( $id, $cap ) {
	$id = (int) $id;
	if ( $id === $GLOBALS['crm_local_admin_id'] && 'manage_options' === $cap ) { return true; }
	if ( 'bizcity_crm_handle_inbox' === $cap && in_array( $id, array( $GLOBALS['crm_editor_id'], $GLOBALS['crm_staff_id'] ), true ) ) { return true; }
	return false;
}
function current_user_can( $cap ) { return user_can( get_current_user_id(), $cap ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function apply_filters( $tag, $value ) { return $value; }
// [2026-09-20] class-capabilities.php now registers two file-scope `add_filter()`
// calls (TwinWeb access-bypass hooks, unrelated to A05/A06) that this standalone
// harness must stub like every other WP function it does not exercise.
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) { return true; }
class BizCity_CRM_Staff_Policy {
	public static function role( $id ) {
		$id = (int) $id;
		if ( $id === $GLOBALS['crm_super_admin_id'] || $id === $GLOBALS['crm_local_admin_id'] ) { return 'admin'; }
		if ( $id === $GLOBALS['crm_editor_id'] ) { return 'lead'; }
		if ( $id === $GLOBALS['crm_staff_id'] ) { return 'agent'; }
		return 'none';
	}
}

require dirname( __DIR__, 2 ) . '/includes/contracts/class-crm-actor.php';
require dirname( __DIR__, 2 ) . '/includes/contracts/class-crm-authority.php';
require dirname( __DIR__, 2 ) . '/includes/class-inbox-access.php';
require dirname( __DIR__, 2 ) . '/includes/class-capabilities.php';

$pass = 0; $fail = 0;
function crm_check( $label, $ok ) { global $pass, $fail; $ok ? $pass++ : ( $fail++ . print "FAIL: {$label}\n" ); }

// A06 — is_admin() must recognize Super Admin (no local blog role) same as a local administrator.
crm_check( 'is_admin: super admin (no local role)', BizCity_CRM_Inbox_Access::is_admin( $GLOBALS['crm_super_admin_id'] ) );
crm_check( 'is_admin: local administrator', BizCity_CRM_Inbox_Access::is_admin( $GLOBALS['crm_local_admin_id'] ) );
crm_check( 'is_admin: editor is not admin', ! BizCity_CRM_Inbox_Access::is_admin( $GLOBALS['crm_editor_id'] ) );
crm_check( 'is_admin: subscriber is not admin', ! BizCity_CRM_Inbox_Access::is_admin( $GLOBALS['crm_subscriber_id'] ) );

// A06 — user_can_handle_inbox() must recognize Super Admin same as manage_options.
crm_check( 'handle_inbox: super admin', BizCity_CRM_Capabilities::user_can_handle_inbox( $GLOBALS['crm_super_admin_id'] ) );
crm_check( 'handle_inbox: local administrator', BizCity_CRM_Capabilities::user_can_handle_inbox( $GLOBALS['crm_local_admin_id'] ) );
crm_check( 'handle_inbox: editor (has cap)', BizCity_CRM_Capabilities::user_can_handle_inbox( $GLOBALS['crm_editor_id'] ) );
crm_check( 'handle_inbox: subscriber denied', ! BizCity_CRM_Capabilities::user_can_handle_inbox( $GLOBALS['crm_subscriber_id'] ) );

// A05 — the consolidated `crm.inbox.read` action (what all 3 can_use_crm() now delegate to)
// must accept every CRM role and reject subscriber.
foreach ( array(
	'super admin' => $GLOBALS['crm_super_admin_id'],
	'local admin' => $GLOBALS['crm_local_admin_id'],
	'editor/lead' => $GLOBALS['crm_editor_id'],
	'staff/agent' => $GLOBALS['crm_staff_id'],
) as $label => $id ) {
	$GLOBALS['crm_current_user'] = $id;
	crm_check( "can_use_crm floor: {$label}", BizCity_CRM_Authority::can( 'crm.inbox.read' )['ok'] );
}
$GLOBALS['crm_current_user'] = $GLOBALS['crm_subscriber_id'];
crm_check( 'can_use_crm floor: subscriber denied', ! BizCity_CRM_Authority::can( 'crm.inbox.read' )['ok'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
