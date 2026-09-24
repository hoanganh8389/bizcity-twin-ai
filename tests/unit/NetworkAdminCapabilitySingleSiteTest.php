<?php
/**
 * HOTFIX-SINGLE-SITE-MENU-CAP (2026-09-24) — standalone regression test.
 *
 * On single-site WordPress is_super_admin() is true for every user with
 * delete_users, but nobody holds manage_network. menu_cap() used to return
 * manage_network for them, locking the administrator out of every Twin AI
 * menu (Channel Gateway, CRM…). See
 * docs/audits/SINGLE-SITE-ADMIN-MENU-CAP-REGRESSION-2026-09-24.md.
 *
 * Run: php tests/unit/NetworkAdminCapabilitySingleSiteTest.php
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

$GLOBALS['nac_multisite']    = false;
$GLOBALS['nac_current_user'] = 0;
// 1 = administrator / network super admin, 2 = custom role with delete_users but no manage_options.
$GLOBALS['nac_super_admins'] = array( 1 );

function is_multisite() { return $GLOBALS['nac_multisite']; }
function get_current_user_id() { return $GLOBALS['nac_current_user']; }
function is_super_admin( $user_id = false ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( $GLOBALS['nac_multisite'] ) {
		return in_array( $user_id, $GLOBALS['nac_super_admins'], true );
	}
	return in_array( $user_id, array( 1, 2 ), true ); // WP core: single-site = has delete_users.
}
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) { return true; }

require dirname( __DIR__, 2 ) . '/core/runtime/class-network-admin-capability.php';

$pass = 0; $fail = 0;
function nac_check( $label, $ok ) { global $pass, $fail; $ok ? $pass++ : ( $fail++ . print "FAIL: {$label}\n" ); }
function nac_user( $id ) { $u = new stdClass(); $u->ID = $id; return $u; }

// ── Single-site ──
$GLOBALS['nac_multisite'] = false;
$GLOBALS['nac_current_user'] = 1;
nac_check( 'single-site admin gets manage_options menu cap', 'manage_options' === BizCity_Network_Admin_Capability::menu_cap() );
nac_check( 'single-site admin is not a network super admin', ! BizCity_Network_Admin_Capability::is_network_super_admin() );
$caps = BizCity_Network_Admin_Capability::filter_user_has_cap( array( 'delete_users' => true ), array(), array(), nac_user( 2 ) );
nac_check( 'single-site delete_users role is NOT escalated to manage_options', empty( $caps['manage_options'] ) );

// ── Multisite ──
$GLOBALS['nac_multisite'] = true;
$GLOBALS['nac_current_user'] = 1;
nac_check( 'multisite super admin gets manage_network menu cap', 'manage_network' === BizCity_Network_Admin_Capability::menu_cap() );
$caps = BizCity_Network_Admin_Capability::filter_user_has_cap( array(), array(), array(), nac_user( 1 ) );
nac_check( 'multisite super admin without local role gets manage_options', ! empty( $caps['manage_options'] ) );

$GLOBALS['nac_current_user'] = 2;
nac_check( 'multisite non-super-admin gets manage_options menu cap', 'manage_options' === BizCity_Network_Admin_Capability::menu_cap() );
$caps = BizCity_Network_Admin_Capability::filter_user_has_cap( array(), array(), array(), nac_user( 2 ) );
nac_check( 'multisite non-super-admin is not granted manage_options', empty( $caps['manage_options'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
