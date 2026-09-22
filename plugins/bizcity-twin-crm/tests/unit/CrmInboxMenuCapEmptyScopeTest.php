<?php
/**
 * PHASE-0.60 §8-Q3 / C60-A04b — standalone regression test.
 *
 * Before this change, `BizCity_CRM_Capabilities::inbox_menu_cap()` fell back to
 * `manage_options` for a staff member with an empty Inbox scope, which hid the
 * CRM Inbox menu entry entirely for a brand-new employee who has not yet
 * self-connected a Zalo Cá nhân account. The fix: the menu capability no longer
 * looks at scope at all — an empty scope is the "chưa được gán" state the page
 * itself renders (self-connect flow), not a reason to hide the page.
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

$GLOBALS['crm_admin_id']      = 1; // manage_options
$GLOBALS['crm_staff_id']      = 20; // bizcity_crm_handle_inbox, empty scope
$GLOBALS['crm_subscriber_id'] = 30; // no CRM cap at all

function get_current_user_id() { return $GLOBALS['crm_current_user'] ?? 0; }
function is_super_admin( $id = 0 ) { return false; }
function user_can( $id, $cap ) {
	$id = (int) $id;
	if ( $id === $GLOBALS['crm_admin_id'] && 'manage_options' === $cap ) { return true; }
	if ( 'bizcity_crm_handle_inbox' === $cap && $id === $GLOBALS['crm_staff_id'] ) { return true; }
	return false;
}
function apply_filters( $tag, $value ) { return $value; }
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) { return true; }

require dirname( __DIR__, 2 ) . '/includes/class-capabilities.php';

$pass = 0; $fail = 0;
function crm_check( $label, $ok ) { global $pass, $fail; $ok ? $pass++ : ( $fail++ . print "FAIL: {$label}\n" ); }

crm_check( 'admin gets manage_options', 'manage_options' === BizCity_CRM_Capabilities::inbox_menu_cap( $GLOBALS['crm_admin_id'] ) );
crm_check(
	'staff with empty scope still gets the handle-inbox capability, NOT manage_options (§8-Q3)',
	'bizcity_crm_handle_inbox' === BizCity_CRM_Capabilities::inbox_menu_cap( $GLOBALS['crm_staff_id'] )
);
crm_check( 'subscriber with no CRM cap falls back to manage_options (stays hidden from them)', 'manage_options' === BizCity_CRM_Capabilities::inbox_menu_cap( $GLOBALS['crm_subscriber_id'] ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) { exit( 1 ); }
