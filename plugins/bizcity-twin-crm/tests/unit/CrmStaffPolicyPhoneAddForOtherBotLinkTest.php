<?php
/**
 * PHASE-0.56 C-3 — standalone assertion test for the two new `BizCity_CRM_Staff_Policy` actions,
 * `phone.add_for_other` (D56-1) and `staff.link_bot` (D56-2). No WordPress bootstrap, no PHPUnit —
 * this checkout has neither `vendor/` nor a `tests/` harness (verified: `composer.json`/`vendor/bin/
 * phpunit` do not exist here), so this mirrors the "standalone script, minimal WP stubs" pattern this
 * project has used before for the exact same class (0.48F: "30/30 assertions PASS — no WP bootstrap").
 *
 * Run: `php tests/unit/CrmStaffPolicyPhoneAddForOtherBotLinkTest.php` from the plugin root.
 *
 * @package BizCity_Twin_CRM
 */

// ── Minimal WP/team-manager stubs — just enough for BizCity_CRM_Staff_Policy::role()/can() ────────
$GLOBALS['__users'] = array(
	1  => array( 'roles' => array( 'administrator' ) ), // admin
	10 => array( 'roles' => array( 'editor' ) ),         // supervisor, team A
	20 => array( 'roles' => array( 'editor' ) ),         // lead, team A
	30 => array( 'roles' => array( 'editor' ) ),         // agent, team A
	40 => array( 'roles' => array( 'editor' ) ),         // agent, team B
	50 => array( 'roles' => array( 'editor' ) ),         // supervisor, team B
);
$GLOBALS['__memberships'] = array(
	10 => array( array( 'team_id' => 1, 'member_role' => 'supervisor' ) ),
	20 => array( array( 'team_id' => 1, 'member_role' => 'lead' ) ),
	30 => array( array( 'team_id' => 1, 'member_role' => 'agent' ) ),
	40 => array( array( 'team_id' => 2, 'member_role' => 'agent' ) ),
	50 => array( array( 'team_id' => 2, 'member_role' => 'supervisor' ) ),
);

function user_can( $user_id, $cap ) {
	if ( 'manage_options' !== $cap ) { return false; }
	return isset( $GLOBALS['__users'][ $user_id ] ) && in_array( 'administrator', $GLOBALS['__users'][ $user_id ]['roles'], true );
}
function get_userdata( $user_id ) {
	if ( ! isset( $GLOBALS['__users'][ $user_id ] ) ) { return false; }
	return (object) array( 'roles' => $GLOBALS['__users'][ $user_id ]['roles'] );
}
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function get_current_blog_id() { return 1; }
function is_user_member_of_blog( $u, $b ) { return true; }

class BizCity_CRM_Team_Manager {
	public static function list_user_memberships( int $user_id ): array {
		return $GLOBALS['__memberships'][ $user_id ] ?? array();
	}
}
class BizCity_CRM_Capabilities {
	public static function user_can_handle_inbox( int $user_id ): bool { return isset( $GLOBALS['__users'][ $user_id ] ); }
}

// `class-staff-policy.php` starts with `defined( 'ABSPATH' ) || exit;` (every plugin file does) —
// without this, `exit` fires silently the moment `require` runs and the whole script stops with no
// output and exit code 0, which looks exactly like "test file did nothing" rather than a real failure.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
require __DIR__ . '/../../includes/class-staff-policy.php';

// ── Assertions ──────────────────────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function check( string $label, bool $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
}

$P = 'BizCity_CRM_Staff_Policy';

// phone.add_for_other — D56-1: supervisor+ within the SAME team, never cross-team, never on a peer/higher rank.
$r = $P::can( 10, 'phone.add_for_other', 30 ); // supervisor(A) -> agent(A)
check( 'supervisor adds phone for own-team agent: ok', $r['ok'] );

$r = $P::can( 10, 'phone.add_for_other', 20 ); // supervisor(A) -> lead(A)
check( 'supervisor adds phone for own-team lead: ok', $r['ok'] );

$r = $P::can( 10, 'phone.add_for_other', 40 ); // supervisor(A) -> agent(B), cross-team
check( 'supervisor adds phone for OTHER team agent: denied different_team', ! $r['ok'] && 'different_team' === $r['code'] );

$r = $P::can( 10, 'phone.add_for_other', 50 ); // supervisor(A) -> supervisor(B): cross-team AND peer rank
check( 'supervisor adds phone for cross-team supervisor: denied (different_team wins)', ! $r['ok'] && 'different_team' === $r['code'] );

$r = $P::can( 20, 'phone.add_for_other', 30 ); // lead(A) -> agent(A): below the rank-3 floor
check( 'lead adds phone for own-team agent: denied rank_insufficient', ! $r['ok'] && 'rank_insufficient' === $r['code'] );

$r = $P::can( 30, 'phone.add_for_other', 20 ); // agent(A) -> lead(A)
check( 'agent adds phone for own-team lead: denied rank_insufficient', ! $r['ok'] && 'rank_insufficient' === $r['code'] );

$r = $P::can( 1, 'phone.add_for_other', 30 ); // admin -> anyone
check( 'admin adds phone for any employee: ok', $r['ok'] );

// staff.link_bot — D56-2: same floor as phone.add_for_other; explicitly SELF_FORBIDDEN (self-linking
// is the separate `/gpt/` self-service flow, never routed through this policy).
$r = $P::can( 10, 'staff.link_bot', 30 );
check( 'supervisor links bot for own-team agent: ok', $r['ok'] );

$r = $P::can( 10, 'staff.link_bot', 40 );
check( 'supervisor links bot for other-team agent: denied different_team', ! $r['ok'] && 'different_team' === $r['code'] );

$r = $P::can( 20, 'staff.link_bot', 30 ); // lead below floor
check( 'lead links bot for own-team agent: denied rank_insufficient', ! $r['ok'] && 'rank_insufficient' === $r['code'] );

$r = $P::can( 10, 'staff.link_bot', 10 ); // self
check( 'supervisor links bot for THEMSELVES: denied self_not_allowed', ! $r['ok'] && 'self_not_allowed' === $r['code'] );

$r = $P::can( 1, 'staff.link_bot', 1 ); // admin, self
check( 'admin links bot for THEMSELVES: denied self_not_allowed (SELF_FORBIDDEN applies to admin too)', ! $r['ok'] && 'self_not_allowed' === $r['code'] );

$r = $P::can( 1, 'staff.link_bot', 30 );
check( 'admin links bot for any employee: ok', $r['ok'] );

// Regression guard — untouched actions keep their pre-0.56 shape.
check( 'MIN_RANK unchanged for phone.assign', 3 === $P::MIN_RANK['phone.assign'] );
check( 'SELF_MIN_RANK unchanged for phone.assign (self-service still rank 1)', 1 === $P::SELF_MIN_RANK['phone.assign'] );
check( 'phone.add_for_other has NO SELF_MIN_RANK entry (self-add bypasses can() entirely, see create_phone_for_owner())', ! array_key_exists( 'phone.add_for_other', $P::SELF_MIN_RANK ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
