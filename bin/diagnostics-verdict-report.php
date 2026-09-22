<?php
/**
 * Read a diagnostics-verdict JSON capture and print a compact report.
 *
 * Works without WordPress. Tolerates a stdout capture that has chatter in
 * front of the JSON document (recovers the last complete verdict) and reports
 * whether the capture was clean, which is itself evidence for R-CLI-CONTRACTS
 * ("exactly one JSON document on stdout").
 *
 * Usage:
 *   php bin/diagnostics-verdict-report.php <capture.json> [report|run_id|complete|clean|verdict]
 *
 *   report    (default) summary line, missing fix_hint list, every non-pass probe
 *             with its non-pass steps and fix_hint
 *   run_id    print only the run_id (empty when none)
 *   complete  print 1 when coverage.complete is true, else 0
 *   clean     print 1 when the whole capture is one JSON document, else 0
 *   verdict   print the verdict string (pass|fail|...) or nothing
 *
 * Exit codes: 0 verdict found · 1 no verdict in the capture · 2 usage error.
 *
 * @package BizCity_Twin_AI\Bin
 */

// [2026-09-18 10:57 AM Johnny Chu - Chu Hoàng Anh] R-CLI-CONTRACTS — repo-owned verdict reader so operators never paste parsing code (tab-completion and heredoc paste mangled every inline version).
if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

$file = isset( $argv[1] ) ? (string) $argv[1] : '';
$mode = isset( $argv[2] ) ? (string) $argv[2] : 'report';
if ( $file === '' || ! in_array( $mode, array( 'report', 'run_id', 'complete', 'clean', 'verdict' ), true ) ) {
	fwrite( STDERR, "Usage: php bin/diagnostics-verdict-report.php <capture.json> [report|run_id|complete|clean|verdict]\n" );
	exit( 2 );
}

$raw   = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
$doc   = null;
$clean = false;
if ( $raw !== '' ) {
	$doc   = json_decode( $raw, true );
	$clean = is_array( $doc ) && isset( $doc['verdict'] );
	if ( ! $clean ) {
		$doc = null;
		if ( preg_match_all( '/(?:^|\n)\{/', $raw, $matches, PREG_OFFSET_CAPTURE ) ) {
			// The verdict is the last complete document; try each line-start "{" from the end.
			foreach ( array_reverse( $matches[0] ) as $hit ) {
				$candidate = json_decode( ltrim( substr( $raw, (int) $hit[1] ) ), true );
				if ( is_array( $candidate ) && isset( $candidate['verdict'] ) ) {
					$doc = $candidate;
					break;
				}
			}
		}
	}
}

if ( 'run_id' === $mode ) {
	echo is_array( $doc ) ? (string) ( $doc['run_id'] ?? '' ) : '';
	exit( is_array( $doc ) ? 0 : 1 );
}
if ( 'complete' === $mode ) {
	echo ( is_array( $doc ) && ! empty( $doc['coverage']['complete'] ) ) ? '1' : '0';
	exit( is_array( $doc ) ? 0 : 1 );
}
if ( 'verdict' === $mode ) {
	echo is_array( $doc ) ? (string) ( $doc['verdict'] ?? '' ) : '';
	exit( is_array( $doc ) ? 0 : 1 );
}
if ( 'clean' === $mode ) {
	echo $clean ? '1' : '0';
	exit( is_array( $doc ) ? 0 : 1 );
}

if ( ! is_array( $doc ) ) {
	echo 'no verdict JSON in ', $file, " (see the matching .err file)\n";
	exit( 1 );
}

echo 'stdout_clean=', $clean ? 'yes' : 'NO (' . strlen( $raw ) . ' bytes; verdict recovered from the tail)', "\n";
echo 'verdict=', (string) ( $doc['verdict'] ?? '?' ),
	' counts=', json_encode( $doc['counts'] ?? null ),
	' complete=', json_encode( $doc['coverage']['complete'] ?? null ),
	' deferred=', json_encode( $doc['coverage']['deferred'] ?? null ),
	' blog_id=', (string) ( $doc['environment']['blog_id'] ?? '?' ),
	' run_id=', (string) ( $doc['run_id'] ?? '' ), "\n";
if ( ! empty( $doc['error'] ) ) {
	echo 'error: ', (string) $doc['error'], "\n";
}
echo 'missing_fix_hint: ', json_encode( $doc['evidence_audit']['missing_probe_ids'] ?? array() ), "\n";

foreach ( (array) ( $doc['results'] ?? array() ) as $key => $result ) {
	if ( ! is_array( $result ) || ( $result['status'] ?? '' ) === 'pass' ) {
		continue;
	}
	$fix = (string) ( $result['fix_hint'] ?? '' );
	echo "\n# ", (string) ( $result['id'] ?? $key ), ' [', (string) ( $result['status'] ?? '?' ), '] ', (string) ( $result['error'] ?? '' ), "\n";
	echo '  ', (string) ( $result['summary'] ?? '' ), "\n";
	echo '  fix: ', $fix !== '' ? $fix : '-', "\n";
	foreach ( (array) ( $result['steps'] ?? array() ) as $step ) {
		if ( is_array( $step ) && strtolower( (string) ( $step['status'] ?? '' ) ) !== 'pass' ) {
			echo '   - [', (string) ( $step['status'] ?? '?' ), '] ', (string) ( $step['label'] ?? '' ), ': ', (string) ( $step['detail'] ?? '' ), "\n";
		}
	}
}
exit( 0 );
