<?php
/**
 * Phase 0.13 / Vòng 3 — Sprint 6 (Canvas Activation)
 * Twin Root triage harness — runs each fixture prompt through twin_root and
 * inspects which transfer_to_* tool the LLM called.
 *
 * Two ways to invoke:
 *
 * 1) WP-CLI (preferred — full bootstrap):
 *      wp eval-file wp-content/plugins/bizcity-twin-ai/tests/run-triage-test.php
 *
 * 2) Browser (admin-only, for the live site):
 *      /wp-admin/admin.php?page=bizcity-twin-triage-test
 *      (registered when WP_DEBUG is on; see admin hook below)
 *
 * Output: JSON report + plain-text summary printed to stdout / response body.
 *
 * NOTE: This calls the REAL LLM, so each run costs tokens. Default fixture
 * is 20 prompts ≈ 20 × triage call.
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow direct invocation from a thin admin endpoint while still requiring WP.
	exit;
}

if ( ! current_user_can( 'manage_options' ) && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) {
	wp_die( 'Triage test requires admin or WP-CLI.' );
}

if ( ! class_exists( 'BizCity_Twin_Agent_Registry' ) ) {
	echo "[ERROR] Twin runtime not loaded.\n";
	return;
}

$fixture = require __DIR__ . '/triage-fixture.php';
if ( ! is_array( $fixture ) || empty( $fixture ) ) {
	echo "[ERROR] Fixture empty.\n";
	return;
}

$registry = BizCity_Twin_Agent_Registry::instance();
if ( $registry->get( 'twin_root' ) === null ) {
	echo "[ERROR] twin_root agent not registered.\n";
	return;
}

// Map transfer_to_* tool name → expected agent key.
$tool_to_agent = [
	'transfer_to_mindmap' => 'mindmap',
	'transfer_to_image'   => 'image',
	'transfer_to_content' => 'content',
	'transfer_to_doc'     => 'doc',
];

$results = [];
$correct = 0;
$total   = count( $fixture );

echo "Running triage fixture: {$total} prompts\n";
echo str_repeat( '─', 80 ) . "\n";

foreach ( $fixture as $i => $case ) {
	$prompt   = (string) $case['prompt'];
	$expected = (string) $case['expect'];

	$session  = new BizCity_Twin_Rolling_Session();
	$runner   = new BizCity_Twin_Runner( $registry, $session );

	$started  = microtime( true );
	$state    = null;
	$picked   = null;
	$err      = null;

	try {
		$state = $runner->run( 'twin_root', $prompt, [] );
	} catch ( \Throwable $e ) {
		$err = $e->getMessage();
	}

	$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

	// Inspect the message log: find the FIRST assistant tool-call JSON.
	if ( $state instanceof BizCity_Twin_RunState ) {
		foreach ( $state->messages as $msg ) {
			if ( ! is_array( $msg ) ) continue;
			if ( ( $msg['role'] ?? '' ) !== 'assistant' ) continue;
			$content = (string) ( $msg['content'] ?? '' );
			$decoded = json_decode( $content, true );
			if ( is_array( $decoded ) && ! empty( $decoded['tool'] ) ) {
				$picked = (string) $decoded['tool'];
				break;
			}
		}
	}

	$picked_agent = $tool_to_agent[ $picked ?? '' ] ?? null;
	$ok           = ( $picked_agent === $expected );
	if ( $ok ) {
		$correct++;
	}

	$mark = $ok ? '✓' : '✗';
	printf(
		"%s [%2d/%2d] %4dms  expect=%-8s got=%-8s  %s\n",
		$mark,
		$i + 1,
		$total,
		$ms,
		$expected,
		( $picked_agent ?? '(none)' ),
		mb_strimwidth( $prompt, 0, 60, '…' )
	);

	$results[] = [
		'prompt'      => $prompt,
		'expected'    => $expected,
		'picked_tool' => $picked,
		'picked'      => $picked_agent,
		'ok'          => $ok,
		'ms'          => $ms,
		'error'       => $err,
		'run_status'  => $state instanceof BizCity_Twin_RunState ? $state->status : 'no_state',
	];
}

$pct = $total > 0 ? round( $correct / $total * 100, 1 ) : 0.0;

echo str_repeat( '─', 80 ) . "\n";
printf( "Result: %d/%d correct (%.1f%%)  — target ≥ 80%%\n", $correct, $total, $pct );

// Persist last report for admin view / retrospective.
update_option(
	'bizcity_twin_triage_last_report',
	[
		'ran_at'  => gmdate( 'c' ),
		'total'   => $total,
		'correct' => $correct,
		'pct'     => $pct,
		'cases'   => $results,
	],
	false
);

echo "Saved → wp_options.bizcity_twin_triage_last_report\n";
