<?php
/**
 * BizCity Diagnostics — Headless CLI runner (Phase 0.99.8).
 *
 * Bootstraps WordPress, runs every registered probe, prints a summary, and
 * (optionally) emits JUnit XML so GitHub Actions / GitLab CI can annotate PRs.
 *
 * Usage
 * -----
 *   php bin/diagnostics-run.php
 *   php bin/diagnostics-run.php --junit=build/junit.xml
 *   php bin/diagnostics-run.php --filter=core.*
 *   php bin/diagnostics-run.php --skip-network
 *
 * Exit codes
 * ----------
 *   0  — all probes PASS (or PRECHECK-FAIL that are SKIP-eligible).
 *   1  — at least one probe FAIL.
 *   2  — bootstrap error (WP not found, no probes registered, etc.).
 *
 * Detection of WP root
 * --------------------
 *   1. `--wp-root=/path/to/wp` flag.
 *   2. `BIZCITY_WP_ROOT` env var.
 *   3. Walk up from this file until `wp-load.php` is found.
 *
 * @package BizCity_Twin_AI\Bin
 * @since   1.0.0  (Phase 0.99.8 — 2026-06-01)
 */

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "diagnostics-run.php must be run from CLI.\n" );
    exit( 2 );
}

/* ── Args ───────────────────────────────────────────────────────────── */
$opts = [
    'junit'        => '',
    'filter'       => '',
    'skip-network' => false,
    'verbose'      => false,
    'wp-root'      => '',
];
foreach ( array_slice( $argv, 1 ) as $a ) {
    if ( strpos( $a, '--' ) !== 0 ) { continue; }
    $kv = substr( $a, 2 );
    if ( strpos( $kv, '=' ) !== false ) {
        list( $k, $v ) = explode( '=', $kv, 2 );
        $opts[ $k ] = $v;
    } else {
        $opts[ $kv ] = true;
    }
}

/* ── Locate WP root ─────────────────────────────────────────────────── */
$wp_root = $opts['wp-root'] !== '' ? $opts['wp-root'] : ( getenv( 'BIZCITY_WP_ROOT' ) ?: '' );
if ( $wp_root === '' ) {
    $dir = __DIR__;
    while ( $dir !== dirname( $dir ) ) {
        if ( file_exists( $dir . '/wp-load.php' ) ) {
            $wp_root = $dir;
            break;
        }
        $dir = dirname( $dir );
    }
}
if ( $wp_root === '' || ! file_exists( $wp_root . '/wp-load.php' ) ) {
    fwrite( STDERR, "Cannot locate WP root. Use --wp-root=/path or set BIZCITY_WP_ROOT.\n" );
    exit( 2 );
}

/* ── Bootstrap WP ──────────────────────────────────────────────────── */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'cli.local';
$_SERVER['REQUEST_URI'] = '/';

if ( $opts['skip-network'] ) {
    define( 'BIZCITY_DIAGNOSTICS_MOCK', true );
}

require $wp_root . '/wp-load.php';

/* Elevate to admin so probe permission_callbacks pass. */
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
if ( ! empty( $admin ) ) {
    wp_set_current_user( (int) $admin[0]->ID );
}

/* ── Discover probes ───────────────────────────────────────────────── */
if ( ! class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ) {
    fwrite( STDERR, "BizCity_Diagnostics_Smoke_Runner not loaded. Is bizcity-twin-ai active?\n" );
    exit( 2 );
}

$catalog = BizCity_Diagnostics_Smoke_Runner::catalog();
if ( empty( $catalog ) ) {
    fwrite( STDERR, "No probes registered.\n" );
    exit( 2 );
}

$filter_glob = (string) $opts['filter'];
$ids         = [];
foreach ( $catalog as $id => $probe ) {
    if ( $filter_glob !== '' && ! fnmatch( $filter_glob, $id ) ) { continue; }
    $ids[] = $id;
}

if ( empty( $ids ) ) {
    fwrite( STDERR, "No probes match filter `{$filter_glob}`.\n" );
    exit( 2 );
}

printf( "Running %d probe(s)…\n\n", count( $ids ) );

/* ── Run probes ────────────────────────────────────────────────────── */
$start_all  = microtime( true );
$results    = [];
$total_pass = 0;
$total_fail = 0;
$total_skip = 0;

foreach ( $ids as $id ) {
    $t0  = microtime( true );
    $res = BizCity_Diagnostics_Smoke_Runner::run_probe( $id );
    $dur = (int) round( ( microtime( true ) - $t0 ) * 1000 );
    $res['duration_ms'] = $res['duration_ms'] ?? $dur;

    $status = (string) ( $res['status'] ?? 'fail' );
    $badge  = strtoupper( $status );
    if ( $status === 'pass' )                { $total_pass++; }
    elseif ( $status === 'precheck-fail' )   { $total_skip++; }
    else                                     { $total_fail++; }

    $line = sprintf( "[%-13s] %-50s %5dms", $badge, $id, (int) $res['duration_ms'] );
    if ( ! empty( $res['summary'] ) ) {
        $line .= ' · ' . substr( (string) $res['summary'], 0, 80 );
    }
    if ( $status === 'fail' && ! empty( $res['error'] ) ) {
        $line .= "\n      ↳ " . substr( (string) $res['error'], 0, 200 );
    }
    echo $line . "\n";

    $results[ $id ] = $res;
}

$dur_all = (int) round( ( microtime( true ) - $start_all ) * 1000 );

printf(
    "\nResult: %d pass · %d fail · %d skip · total %dms\n",
    $total_pass, $total_fail, $total_skip, $dur_all
);

/* ── JUnit XML ─────────────────────────────────────────────────────── */
if ( $opts['junit'] !== '' ) {
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= sprintf(
        '<testsuites name="bizcity-twin-ai-diagnostics" tests="%d" failures="%d" skipped="%d" time="%.3f">' . "\n",
        count( $results ), $total_fail, $total_skip, $dur_all / 1000
    );
    $xml .= sprintf(
        '  <testsuite name="diagnostics" tests="%d" failures="%d" skipped="%d" time="%.3f">' . "\n",
        count( $results ), $total_fail, $total_skip, $dur_all / 1000
    );
    foreach ( $results as $id => $res ) {
        $time = ( (int) ( $res['duration_ms'] ?? 0 ) ) / 1000;
        $xml .= sprintf(
            '    <testcase classname="bizcity.diagnostics" name="%s" time="%.3f">' . "\n",
            htmlspecialchars( $id, ENT_XML1 | ENT_COMPAT, 'UTF-8' ),
            $time
        );
        $st = (string) ( $res['status'] ?? 'fail' );
        if ( $st === 'fail' ) {
            $xml .= sprintf(
                '      <failure message="%s">%s</failure>' . "\n",
                htmlspecialchars( substr( (string) ( $res['error'] ?? 'fail' ), 0, 200 ), ENT_XML1 | ENT_COMPAT, 'UTF-8' ),
                htmlspecialchars( (string) ( $res['summary'] ?? '' ), ENT_XML1 | ENT_COMPAT, 'UTF-8' )
            );
        } elseif ( $st === 'precheck-fail' ) {
            $xml .= sprintf(
                '      <skipped message="%s"/>' . "\n",
                htmlspecialchars( substr( (string) ( $res['error'] ?? 'precondition' ), 0, 200 ), ENT_XML1 | ENT_COMPAT, 'UTF-8' )
            );
        }
        $xml .= '    </testcase>' . "\n";
    }
    $xml .= '  </testsuite>' . "\n";
    $xml .= '</testsuites>' . "\n";

    $out_dir = dirname( $opts['junit'] );
    if ( $out_dir !== '' && ! is_dir( $out_dir ) ) {
        @mkdir( $out_dir, 0755, true );
    }
    file_put_contents( $opts['junit'], $xml );
    printf( "JUnit XML written to %s\n", $opts['junit'] );
}

exit( $total_fail > 0 ? 1 : 0 );
