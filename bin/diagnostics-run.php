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
 *   php bin/diagnostics-run.php --codec
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

// [2026-08-19 Johnny Chu] HOTFIX-DIAGNOSTICS-CLI-CONTEXT - direct PHP CLI
// needs an explicit loader context, but must not impersonate the WP-CLI class.
if ( ! defined( 'BIZCITY_DIAGNOSTICS_CLI' ) ) {
    define( 'BIZCITY_DIAGNOSTICS_CLI', true );
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

// [2026-08-20 Johnny Chu] CODEC-CORE-DDV — provide a focused CLI gate for the
// shared codec probe so codec migrations can be checked without run-all noise.
if ( ! empty( $opts['codec'] ) ) {
    $opts['filter'] = 'core.helper.codec_standard';
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
// [2026-08-19 Johnny Chu] HOTFIX-DIAGNOSTICS-CLI-LIFECYCLE - model the
// backend request context used by diagnostics so gated modules and schema
// installers see the same surface as the REST diagnostics endpoint.
$_SERVER['REQUEST_URI'] = '/wp-json/bizcity-diagnostics/v1/';
if ( ! defined( 'REST_REQUEST' ) ) {
    define( 'REST_REQUEST', true );
}

if ( $opts['skip-network'] ) {
    define( 'BIZCITY_DIAGNOSTICS_MOCK', true );
}

require $wp_root . '/wp-load.php';

/* Elevate to admin so probe permission_callbacks pass. */
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
if ( ! empty( $admin ) ) {
    wp_set_current_user( (int) $admin[0]->ID );
}

// [2026-08-19 Johnny Chu] HOTFIX - headless WP-CLI may finish wp-load without
// the plugin diagnostic bootstrap; recover through the guarded plugin entrypoint.
if ( ! class_exists( 'BizCity_Diagnostics_Smoke_Runner', false ) ) {
    $plugin_entry = dirname( __DIR__ ) . '/bizcity-twin-ai.php';
    if ( is_readable( $plugin_entry ) ) {
        require_once $plugin_entry;
    }
}
if ( ! class_exists( 'BizCity_Diagnostics_Smoke_Runner', false ) ) {
    $diagnostics_bootstrap = dirname( __DIR__ ) . '/core/diagnostics/bootstrap.php';
    if ( is_readable( $diagnostics_bootstrap ) ) {
        require_once $diagnostics_bootstrap;
    }
}
if ( ! class_exists( 'BizCity_Diagnostics_Smoke_Runner', false ) ) {
    // [2026-08-19 Johnny Chu] HOTFIX - recover the CLI contract when a prior
    // bootstrap defined BIZCITY_DIAGNOSTICS_LOADED before loading the runner.
    $probe_interface = dirname( __DIR__ ) . '/core/diagnostics/includes/interface-diagnostics-probe.php';
    $smoke_runner    = dirname( __DIR__ ) . '/core/diagnostics/includes/class-diagnostics-smoke-runner.php';
    if ( is_readable( $probe_interface ) ) {
        require_once $probe_interface;
    }
    if ( is_readable( $smoke_runner ) ) {
        require_once $smoke_runner;
    }
}

// [2026-08-19 Johnny Chu] HOTFIX-DIAGNOSTICS-CLI-LIFECYCLE - wp-load.php
// stops before WordPress's normal init phase; run it once after recovery.
if ( function_exists( 'did_action' ) && function_exists( 'do_action' ) && ! did_action( 'init' ) ) {
    do_action( 'init' );
}

// [2026-08-19 Johnny Chu] HOTFIX-DIAGNOSTICS-CLI-SCHEMA - several installers
// (channel bindings, identity hub, channel-user-linker, scheduler, automation)
// only self-heal on 'admin_init' / 'current_screen', hooks that never fire in
// a headless run. Firing `do_action('admin_init')` wholesale is unsafe here —
// unrelated admin_init callbacks (e.g. dashboard redirect guards) call
// wp_redirect()+exit() when $_GET['page'] is unset, which is always true in
// CLI and would kill this script mid-run. Call only the known-safe static
// installers directly instead, BEFORE probes run, so schema exists up front.
if ( class_exists( 'BizCity_Channel_Messages', false ) ) {
    BizCity_Channel_Messages::maybe_install();
}
if ( class_exists( 'BizCity_Channel_Binding', false ) ) {
    BizCity_Channel_Binding::maybe_install();
}
if ( class_exists( 'BizCity_Identity_Hub', false ) ) {
    BizCity_Identity_Hub::maybe_install();
}
if ( class_exists( 'BizCity_Channel_User_Linker', false ) ) {
    BizCity_Channel_User_Linker::maybe_install();
}
if ( class_exists( 'BizCity_Scheduler_Manager', false ) ) {
    BizCity_Scheduler_Manager::instance()->ensure_schema();
}
if ( class_exists( 'BizCity_Automation_Installer', false ) ) {
    BizCity_Automation_Installer::ensure();
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
        // [2026-08-21 Johnny Chu] DIAGNOSTICS-CLI-EVIDENCE — keep probe failure details visible in CI logs.
        $line .= ' · ' . substr( (string) $res['summary'], 0, 240 );
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
