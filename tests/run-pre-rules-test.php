<?php
/**
 * Phase 0.16 / Vòng 4 — Sprint 9
 * Pre-rules harness — runs each fixture row through BizCity_Intent_Pre_Rules
 * and reports pass/fail. NO LLM, no I/O — pure regex round-trip.
 *
 * Two ways to invoke:
 *
 *   1) WP-CLI:
 *        wp eval-file wp-content/plugins/bizcity-twin-ai/tests/run-pre-rules-test.php
 *
 *   2) Browser (admin-only):
 *        /wp-admin/admin.php?page=bizcity-intent-pre-rules-test
 *        (registered when WP_DEBUG is on; see tests/admin-test-pages.php)
 *
 * Target: 100% pass. Any failure means a regex was changed without updating
 * the fixture, OR the fixture's expectation drifted from current behaviour.
 *
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) {
	wp_die( 'Pre-rules test requires admin or WP-CLI.' );
}

if ( ! class_exists( 'BizCity_Intent_Pre_Rules' ) ) {
	echo "[ERROR] BizCity_Intent_Pre_Rules not loaded.\n";
	return;
}

$fixture = require __DIR__ . '/pre-rules-fixture.php';
if ( ! is_array( $fixture ) || empty( $fixture ) ) {
	echo "[ERROR] Fixture empty.\n";
	return;
}

$rules   = new BizCity_Intent_Pre_Rules();
$results = [];
$pass    = 0;
$total   = count( $fixture );

echo "Running pre-rules fixture: {$total} cases\n";
echo str_repeat( '─', 80 ) . "\n";

foreach ( $fixture as $i => $case ) {
	$msg                 = (string) $case['msg'];
	$expected_try_match  = $case['try_match']   ?? null; // 'help' | 'cancel' | null
	$expected_intent_kind = $case['intent_kind'] ?? null;

	$started = microtime( true );

	$try_match_resp = $rules->try_match( [ 'message' => $msg ], [] );
	$got_try_match  = null;
	if ( is_array( $try_match_resp ) ) {
		$got_try_match = (string) ( $try_match_resp['meta']['rule'] ?? 'matched' );
	}

	$got_intent_kind = $rules->detect_intent_kind( [ 'message' => $msg ] );

	$us = (int) round( ( microtime( true ) - $started ) * 1_000_000 );

	$try_ok    = ( $got_try_match === $expected_try_match );
	$intent_ok = ( $got_intent_kind === $expected_intent_kind );
	$ok        = $try_ok && $intent_ok;
	if ( $ok ) {
		$pass++;
	}

	$mark = $ok ? '✓' : '✗';
	printf(
		"%s [%2d/%2d] %4dµs  try=%-8s/%-8s  kind=%-9s/%-9s  %s\n",
		$mark,
		$i + 1,
		$total,
		$us,
		( $expected_try_match  ?? '(null)' ),
		( $got_try_match       ?? '(null)' ),
		( $expected_intent_kind ?? '(null)' ),
		( $got_intent_kind      ?? '(null)' ),
		mb_strimwidth( $msg, 0, 40, '…' )
	);

	$results[] = [
		'msg'                 => $msg,
		'expected_try_match'  => $expected_try_match,
		'got_try_match'       => $got_try_match,
		'expected_intent_kind' => $expected_intent_kind,
		'got_intent_kind'     => $got_intent_kind,
		'ok'                  => $ok,
		'us'                  => $us,
	];
}

$pct = $total > 0 ? round( $pass / $total * 100, 1 ) : 0.0;
echo str_repeat( '─', 80 ) . "\n";
printf( "Result: %d/%d pass (%.1f%%)  — target = 100%%\n", $pass, $total, $pct );

update_option(
	'bizcity_intent_pre_rules_last_report',
	[
		'ran_at' => gmdate( 'c' ),
		'total'  => $total,
		'pass'   => $pass,
		'pct'    => $pct,
		'cases'  => $results,
	],
	false
);

echo "Saved → wp_options.bizcity_intent_pre_rules_last_report\n";
