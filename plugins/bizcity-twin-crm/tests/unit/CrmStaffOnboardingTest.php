<?php
/**
 * PHASE-0.56 G-1 — standalone assertion test for `BizCity_CRM_Staff_REST::build_onboarding_steps()`,
 * the pure step logic behind `GET /crm-staff/onboarding` (§4.8's 7-step checklist). Same "no WP
 * bootstrap" pattern as `CrmStaffPolicyPhoneAddForOtherBotLinkTest.php` — this checkout has no
 * PHPUnit harness (see PHASE-0.56 §12.1).
 *
 * Run: `php tests/unit/CrmStaffOnboardingTest.php` from the plugin root.
 *
 * @package BizCity_Twin_CRM
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }

// `class-staff-rest.php` pulls in a large surface (Repository, Team_Manager, Audit_Log, …) that this
// standalone script has no reason to load just to exercise one pure static method. Rather than stub
// the whole file's dependency graph, copy the method's exact body into a same-named free function via
// reflection would be overkill too — instead, declare the minimal class shape PHP needs to call it:
// the real file guards with `if ( class_exists(...) ) { return; }`, so loading the real file is safe
// AS LONG AS its own dependencies are stubbed enough to parse-and-declare (PHP does not execute a
// class body's unrelated methods just because the file is included). `class-staff-rest.php` itself
// has no top-level side effects outside its class body, so a direct `require` is safe here — verified
// by reading the file: everything past the `defined('ABSPATH')` guard is inside the class braces.
require __DIR__ . '/../../includes/class-staff-rest.php';

$pass = 0; $fail = 0;
function check( string $label, bool $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
}

$S = 'BizCity_CRM_Staff_REST';
function steps_by_key( array $result ): array {
	$out = array();
	foreach ( $result['steps'] as $s ) { $out[ $s['key'] ] = $s; }
	return $out;
}

// Brand-new tenant: nothing done yet — every step false, `next` is the very first one.
$r = $S::build_onboarding_steps( array( 'has_gateway_key' => false, 'team_count' => 0, 'staff_count' => 0, 'staff_with_phone' => 0, 'staff_with_bot' => 0, 'invited' => false, 'task_count' => 0 ) );
$byKey = steps_by_key( $r );
check( 'fresh tenant: plan not done', ! $byKey['plan']['done'] );
check( 'fresh tenant: team not done', ! $byKey['team']['done'] );
check( 'fresh tenant: staff not done', ! $byKey['staff']['done'] );
check( 'fresh tenant: phone not done (no staff to cover)', ! $byKey['phone']['done'] );
check( 'fresh tenant: bot not done (no staff to cover)', ! $byKey['bot']['done'] );
check( 'fresh tenant: next is plan (first incomplete step)', 'plan' === $r['next'] );

// Gateway key present, one team, one staff member with phone but no bot yet.
$r = $S::build_onboarding_steps( array( 'has_gateway_key' => true, 'team_count' => 1, 'staff_count' => 1, 'staff_with_phone' => 1, 'staff_with_bot' => 0, 'invited' => false, 'task_count' => 0 ) );
$byKey = steps_by_key( $r );
check( 'plan/team/staff/phone done, bot not', $byKey['plan']['done'] && $byKey['team']['done'] && $byKey['staff']['done'] && $byKey['phone']['done'] && ! $byKey['bot']['done'] );
check( 'next is bot (first incomplete step after phone)', 'bot' === $r['next'] );
check( 'phone count/total reflects coverage', 1 === $byKey['phone']['count'] && 1 === $byKey['phone']['total'] );

// Partial coverage: 2 staff, only 1 has a phone — phone step must NOT be done (it requires EVERY
// staff member covered, not just "at least one" — mirrors §4.8's "mọi nhân viên đã liên kết").
$r = $S::build_onboarding_steps( array( 'has_gateway_key' => true, 'team_count' => 1, 'staff_count' => 2, 'staff_with_phone' => 1, 'staff_with_bot' => 2, 'invited' => false, 'task_count' => 0 ) );
$byKey = steps_by_key( $r );
check( 'partial phone coverage (1/2): phone not done', ! $byKey['phone']['done'] );
check( 'full bot coverage (2/2): bot done', $byKey['bot']['done'] );
check( 'next is phone (blocks before invite/task even though bot is ahead numerically)', 'phone' === $r['next'] );

// Every step done — `next` is null (nothing left to nudge the leader toward).
$r = $S::build_onboarding_steps( array( 'has_gateway_key' => true, 'team_count' => 2, 'staff_count' => 3, 'staff_with_phone' => 3, 'staff_with_bot' => 3, 'invited' => true, 'task_count' => 5 ) );
check( 'everything done: next is null', null === $r['next'] );
check( 'everything done: 7 steps returned', 7 === count( $r['steps'] ) );

// Order is fixed and matches §4.8's table (plan, team, staff, phone, bot, invite, task) — the FE
// checklist renders in array order, so a silently reordered array would visually shuffle the list.
$order = array_column( ( $S::build_onboarding_steps( array() ) )['steps'], 'key' );
check( 'step order matches §4.8', array( 'plan', 'team', 'staff', 'phone', 'bot', 'invite', 'task' ) === $order );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
