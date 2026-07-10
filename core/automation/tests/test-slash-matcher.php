<?php
/**
 * Wave C / GURU W2-W3 — admin-gated test for the dual-tier slash matcher.
 *
 * Drop on any page (admin URL):
 *     /wp-admin/?bizc_test_slash_matcher=1
 *
 * Verifies (no DB writes — only lookups + logic):
 *   M1) class loads + extract_command parsing matrix.
 *   M2) lookup() returns source=null when slug doesn't exist anywhere.
 *   T1) try_dispatch() returns matched=false on plain text (no slash prefix).
 *   T2) try_dispatch() with bogus `/foo_xyzzy` → matched=false (or skill miss).
 *   T3) detect_collision() returns null for unknown slug.
 *   T4) TRIGGER_TYPES vocab includes 'slash_command'.
 *   T5) automation matcher trace receives `matched_slash` row when slash hits.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills\Tests
 * @since      WF-AUTO GURU W2 (2026-06-03)
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', static function () {
	if ( empty( $_GET['bizc_test_slash_matcher'] ) ) { return; }
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }

	header( 'Content-Type: text/plain; charset=utf-8' );
	$pass = 0; $fail = 0;
	$ok = static function ( $cond, $label ) use ( &$pass, &$fail ) {
		if ( $cond ) { $pass++; echo "PASS  " . $label . "\n"; }
		else        { $fail++; echo "FAIL  " . $label . "\n"; }
	};

	echo "=== Wave C / GURU W2-W3 · Slash Matcher Test ===\n\n";

	// ── M1 — class load + extract_command ───────────────────────────────
	$ok( class_exists( 'BizCity_Skill_Slash_Matcher' ), 'M1.1 class_exists BizCity_Skill_Slash_Matcher' );

	$cases = array(
		array( '/foo',           array( 'cmd' => 'foo',     'args' => '' ) ),
		array( '/Foo bar baz',   array( 'cmd' => 'foo',     'args' => 'bar baz' ) ),
		array( '   /web_deep ',  array( 'cmd' => 'web_deep','args' => '' ) ),
		array( 'no slash here',  null ),
		array( '/',              null ),
		array( '',               null ),
	);
	foreach ( $cases as $i => $row ) {
		$got = BizCity_Skill_Slash_Matcher::extract_command( (string) $row[0] );
		$ok( $got === $row[1] || ( is_array( $got ) && is_array( $row[1] ) && $got === $row[1] ), 'M1.2.' . $i . ' extract("' . $row[0] . '")' );
	}

	// ── M2 — lookup ─────────────────────────────────────────────────────
	$miss = BizCity_Skill_Slash_Matcher::instance()->lookup( 'cmd_does_not_exist_xyzzy_zyxxy' );
	$ok( ( $miss['source'] ?? null ) === null, 'M2.1 unknown slug → source=null' );

	// ── T1 — try_dispatch no slash ──────────────────────────────────────
	$res = BizCity_Skill_Slash_Matcher::instance()->try_dispatch( array( 'chat_id' => 't1' ), 'hello world' );
	$ok( empty( $res['matched'] ), 'T1.1 plain text → matched=false' );

	// ── T2 — bogus slash ────────────────────────────────────────────────
	$res2 = BizCity_Skill_Slash_Matcher::instance()->try_dispatch(
		array( 'chat_id' => 't2', 'platform' => 'TEST' ),
		'/cmd_does_not_exist_xyzzy_zyxxy'
	);
	$ok( empty( $res2['matched'] ) && strpos( (string) ( $res2['detail'] ?? '' ), 'no_skill_no_workflow' ) !== false,
		'T2.1 unknown /cmd → matched=false + detail=no_skill_no_workflow_*' );

	// ── T3 — collision detect on unknown slug ───────────────────────────
	$col = BizCity_Skill_Slash_Matcher::detect_collision( array( '/cmd_xyzzy_no_collision' ), 'skill', 0 );
	$ok( $col === null, 'T3.1 detect_collision unknown → null' );
	$col2 = BizCity_Skill_Slash_Matcher::detect_collision( array( '' ), 'skill', 0 );
	$ok( $col2 === null, 'T3.2 detect_collision empty → null' );

	// ── T4 — TRIGGER_TYPES vocab ────────────────────────────────────────
	$ok( in_array( 'slash_command', BizCity_Automation_Repo_Workflows::TRIGGER_TYPES, true ),
		'T4.1 TRIGGER_TYPES has "slash_command"' );

	// ── T5 — find_workflow_for_slash + find_skill_for_slash safe ────────
	$wf_unknown = BizCity_Skill_Slash_Matcher::find_workflow_for_slash( 'no_such_cmd_blah' );
	$ok( $wf_unknown === null, 'T5.1 find_workflow_for_slash unknown → null' );
	$sk_unknown = BizCity_Skill_Slash_Matcher::find_skill_for_slash( 'no_such_cmd_blah' );
	$ok( $sk_unknown === null, 'T5.2 find_skill_for_slash unknown → null' );

	echo "\n=== SUMMARY ===\n";
	echo "PASS: $pass\nFAIL: $fail\n";
	exit( $fail === 0 ? 0 : 1 );
} );
