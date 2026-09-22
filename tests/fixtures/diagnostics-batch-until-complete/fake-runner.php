<?php
/**
 * Fake diagnostics runner for tests/fixtures/diagnostics-batch-until-complete/run.sh.
 *
 * Pass 1 (no --resume): SSE chatter in front of the JSON, coverage incomplete.
 * Resume pass: one clean JSON document, coverage complete; the verdict comes
 * from FAKE_FINAL (pass|fail, default pass). Arguments are echoed to stderr so
 * the test can assert what the orchestrator forwarded.
 */

// [2026-09-18 10:57 AM Johnny Chu - Chu Hoàng Anh] R-CLI-CONTRACTS — fixture double for bin/diagnostics-batch-until-complete.sh.
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}
$args   = array_slice( $argv, 1 );
$resume = '';
foreach ( $args as $arg ) {
	if ( strpos( $arg, '--resume=' ) === 0 ) {
		$resume = substr( $arg, 9 );
	}
}
fwrite( STDERR, 'ARGS ' . implode( ' ', $args ) . "\n" );
fwrite( STDERR, 'ENV BIZCITY_ZCA_BRIDGE_ROOT=' . (string) getenv( 'BIZCITY_ZCA_BRIDGE_ROOT' ) . "\n" );

if ( $resume === '' ) {
	echo "event: debug\ndata: {\"type\":\"debug\",\"step\":\"twin:event:decision\"}\n\n";
	echo json_encode( array(
		'contract' => 'diagnostics-verdict',
		'verdict'  => 'fail',
		'run_id'   => 'diag_fixture',
		'counts'   => array( 'pass' => 1, 'fail' => 0, 'skip' => 1 ),
		'coverage' => array( 'complete' => false, 'deferred' => 1 ),
		'environment' => array( 'blog_id' => 7 ),
		'results'  => array(
			array( 'id' => 'fixture.one', 'status' => 'pass' ),
			array( 'id' => 'fixture.two', 'status' => 'skipped', 'error' => 'budget_deferred' ),
		),
	), JSON_PRETTY_PRINT ), "\n";
	exit( 1 );
}

$final_pass = getenv( 'FAKE_FINAL' ) !== 'fail';
echo json_encode( array(
	'contract' => 'diagnostics-verdict',
	'verdict'  => $final_pass ? 'pass' : 'fail',
	'run_id'   => $resume,
	'counts'   => array( 'pass' => $final_pass ? 2 : 1, 'fail' => $final_pass ? 0 : 1, 'skip' => 0 ),
	'coverage' => array( 'complete' => true, 'deferred' => 0 ),
	'environment' => array( 'blog_id' => 7 ),
	'evidence_audit' => array( 'missing_probe_ids' => array() ),
	'results'  => array(
		array( 'id' => 'fixture.one', 'status' => 'pass' ),
		$final_pass
			? array( 'id' => 'fixture.two', 'status' => 'pass' )
			: array( 'id' => 'fixture.two', 'status' => 'fail', 'error' => 'fixture_error', 'summary' => 'Fixture failure.', 'fix_hint' => 'Fix the fixture.', 'steps' => array( array( 'label' => 'Runtime', 'status' => 'fail', 'detail' => 'broken on purpose' ) ) ),
	),
), JSON_PRETTY_PRINT ), "\n";
exit( $final_pass ? 0 : 1 );
