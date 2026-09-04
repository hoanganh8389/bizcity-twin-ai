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
 *   php bin/diagnostics-run.php --host=example.com --filter=core.memory.filestore_parity
 *   php bin/diagnostics-run.php --filter=core.legacy_table.*,core.memory.*
 *   php bin/diagnostics-run.php --host=example.com --skip-provision --filter=core.memory.filestore_parity
 *   php bin/diagnostics-run.php --host=example.com --isolated-mu --filter=core.memory.filestore_parity
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
    'skip-provision' => false,
    'isolated-mu' => false,
    'verbose'      => false,
    'wp-root'      => '',
    'host'         => '',
	'batch'        => '',
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

$machine_output = ! empty( $opts['json'] )
    || ( isset( $opts['format'] ) && strtolower( (string) $opts['format'] ) === 'json' );
$machine_output_emitted = false;
// [2026-08-29 Johnny Chu] PHASE-1.31-S1.4 — keep progress diagnostics on stderr so machine mode has exactly one JSON document on stdout.
$write_progress = static function ( $text ) use ( &$machine_output ) {
    if ( $machine_output ) {
        fwrite( STDERR, (string) $text );
    } else {
        echo (string) $text;
    }
};
if ( $machine_output ) {
    ob_start();
    register_shutdown_function( static function () use ( &$machine_output_emitted ) {
        if ( $machine_output_emitted ) {
            return;
        }
        $error = error_get_last();
        $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_USER_ERROR );
        if ( ! is_array( $error ) || ! in_array( (int) ( $error['type'] ?? 0 ), $fatal_types, true ) ) {
            return;
        }
        $captured = ob_get_clean();
        if ( trim( (string) $captured ) !== '' ) {
            fwrite( STDERR, (string) $captured );
        }
        $machine_output_emitted = true;
        $payload = array(
            'contract' => 'diagnostics-verdict',
            'version'  => '1',
            'command'  => 'diagnostics',
            'counts'   => array( 'pass' => 0, 'warn' => 0, 'fail' => 1, 'skip' => 0 ),
            'verdict'  => 'fail',
            'error'    => 'diagnostics_bootstrap_fatal',
            'detail'   => substr( preg_replace( '/\s+/', ' ', (string) ( $error['message'] ?? 'Bootstrap fatal error.' ) ), 0, 500 ),
            'results'  => array(),
        );
        echo function_exists( 'wp_json_encode' )
            ? wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
            : json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        echo "\n";
    } );
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

// [2026-09-02  Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DEPLOY — detect stale helper artifacts before wp-load.php can fatal on a missing registry class.
$plugin_root = dirname( __DIR__ );
$helper_bootstrap_file = $plugin_root . '/core/helper/bootstrap.php';
$file_registry_file = $plugin_root . '/core/helper/class-bizcity-file-contract-registry.php';
$helper_source = is_file( $helper_bootstrap_file ) && is_readable( $helper_bootstrap_file )
    ? (string) file_get_contents( $helper_bootstrap_file )
    : '';
$helper_preflight_ok = $helper_source !== ''
    && is_file( $file_registry_file )
    && is_readable( $file_registry_file )
    && strpos( $helper_source, "class-bizcity-file-contract-registry.php" ) !== false
    && strpos( $helper_source, "if ( class_exists( 'BizCity_File_Contract_Registry' ) )" ) !== false;
if ( ! $helper_preflight_ok ) {
    $preflight_payload = array(
        'contract' => 'diagnostics-verdict',
        'version'  => '1',
        'command'  => 'diagnostics',
        'counts'   => array( 'pass' => 0, 'warn' => 0, 'fail' => 1, 'skip' => 0 ),
        'verdict'  => 'fail',
        'error'    => 'diagnostics_deployment_preflight',
        'detail'   => 'Helper contract-registry artifact or guarded registration marker is missing.',
        'results'  => array(),
    );
    if ( $machine_output ) {
        $captured_output = ob_get_clean();
        if ( trim( (string) $captured_output ) !== '' ) {
            fwrite( STDERR, (string) $captured_output );
        }
        $machine_output_emitted = true;
        echo json_encode( $preflight_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        echo "\n";
    } else {
        fwrite( STDERR, "Diagnostics deployment preflight failed: helper contract-registry artifact or guard marker is missing.\n" );
    }
    exit( 2 );
}

/* ── Bootstrap WP ──────────────────────────────────────────────────── */
define( 'WP_USE_THEMES', false );
$diagnostics_host = trim( (string) ( $opts['host'] !== '' ? $opts['host'] : ( getenv( 'BIZCITY_DIAGNOSTICS_HOST' ) ?: '' ) ) );
if ( $diagnostics_host !== '' ) {
    // [2026-08-28 Johnny Chu] R-MSDB / PHASE-1.30-DDV — use an operator-supplied mapped domain so CLI resolves the real tenant/shard instead of guessing.
    $diagnostics_host = preg_replace( '/^https?:\/\//i', '', $diagnostics_host );
    $diagnostics_host = trim( $diagnostics_host, " \t\n\r\0\x0B/" );
    if ( $diagnostics_host === '' || preg_match( '/[\s\/]/', $diagnostics_host ) ) {
        fwrite( STDERR, "Invalid diagnostics host. Use --host=example.com or BIZCITY_DIAGNOSTICS_HOST.\n" );
        exit( 2 );
    }
    $_SERVER['HTTP_HOST']   = $diagnostics_host;
    $_SERVER['SERVER_NAME'] = $diagnostics_host;
} else {
    // [2026-08-28 Johnny Chu] R-MSDB — hostless diagnostics remain hostless; db-init may use only its explicitly safe CLI behavior.
    unset( $_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME'] );
}

// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — local WP 6.4.2 lacks the Script Modules API used by the installed WooCommerce build; keep diagnostics asset-free and avoid masking runtime probe results.
$script_modules_file = $wp_root . '/wp-includes/script-modules.php';
if ( ! is_file( $script_modules_file ) ) {
    if ( ! function_exists( 'wp_register_script_module' ) ) {
        function wp_register_script_module( $id, $src, $deps = array(), $version = false, $args = array() ) { return true; }
    }
    if ( ! function_exists( 'wp_deregister_script_module' ) ) {
        function wp_deregister_script_module( $id ) { return true; }
    }
    if ( ! function_exists( 'wp_enqueue_script_module' ) ) {
        function wp_enqueue_script_module( $id ) { return true; }
    }
}
unset( $script_modules_file );

if ( ! empty( $opts['isolated-mu'] ) ) {
    // [2026-08-28 Johnny Chu] PHASE-1.30-DDV — isolate diagnostics from duplicate legacy MU compatibility loaders without changing production plugin state.
    $isolated_mu_dir = $wp_root . '/wp-content/mu-plugins-cli-empty';
    if ( ! is_dir( $isolated_mu_dir ) ) {
        fwrite( STDERR, "Isolated MU directory not found: {$isolated_mu_dir}\n" );
        exit( 2 );
    }
    if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
        define( 'WPMU_PLUGIN_DIR', $isolated_mu_dir );
    }
    unset( $isolated_mu_dir );
}
// [2026-08-19 Johnny Chu] HOTFIX-DIAGNOSTICS-CLI-LIFECYCLE - model the
// backend request context used by diagnostics so gated modules and schema
// installers see the same surface as the REST diagnostics endpoint.
$_SERVER['REQUEST_URI'] = '/wp-json/bizcity-diagnostics/v1/';
if ( ! defined( 'REST_REQUEST' ) ) {
    define( 'REST_REQUEST', true );
}
// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — enable request-local SQL observation before WordPress creates $wpdb so CRUD-stop probes can prove mutation deltas.
if ( ! defined( 'SAVEQUERIES' ) ) {
    define( 'SAVEQUERIES', true );
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

// [2026-09-02 10:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — complete the recovered CLI module boot so WebChat owner probes see Timeline and tool-registry runtime contracts.
if ( class_exists( 'BizCity_Twin_AI', false ) && is_callable( array( 'BizCity_Twin_AI', 'boot' ) ) ) {
    BizCity_Twin_AI::boot();
}

// [2026-08-19 Johnny Chu] HOTFIX-DIAGNOSTICS-CLI-LIFECYCLE - wp-load.php
// stops before WordPress's normal init phase; run it once after recovery.
if ( function_exists( 'did_action' ) && function_exists( 'do_action' ) && ! did_action( 'init' ) ) {
    do_action( 'init' );
}

// [2026-08-21 Johnny Chu] DIAGNOSTICS-CLI-SCHEMA-ORCHESTRATION — use the
// canonical Site Provisioner registry so headless diagnostics provisions every
// registered tenant schema, not only the six installers historically listed
// here. Do not fire admin_init wholesale: unrelated callbacks can redirect or
// exit in a CLI request.
if ( class_exists( 'BizCity_Site_Provisioner', false ) && empty( $opts['skip-provision'] ) && empty( $opts['aggregate'] ) && empty( $opts['list-batches'] ) ) {
    // [2026-08-21 Johnny Chu] DIAGNOSTICS-CLI-RECOVERY — plugin recovery can happen after plugins_loaded, so register the default installer filter explicitly.
    if ( function_exists( 'bizcity_register_default_installers' ) ) {
        bizcity_register_default_installers();
    }
    // [2026-08-26 Johnny Chu] DIAGNOSTICS-SCHEMA-EVIDENCE — expose bounded installer outcomes so missing-table cascades identify the provisioning boundary without logging SQL.
    $provision_results = BizCity_Site_Provisioner::run_all( true );
    $write_progress( 'Provisioner: ' . count( $provision_results ) . " installer(s)\n" );
    foreach ( $provision_results as $provision_row ) {
        if ( ! is_array( $provision_row ) ) {
            continue;
        }
        $write_progress( sprintf(
            "  - %s: action=%s ver_before=%s ver_after=%s\n",
            (string) ( $provision_row['id'] ?? 'unknown' ),
            (string) ( $provision_row['action'] ?? 'unknown' ),
            (string) ( $provision_row['ver_before'] ?? '' ),
            (string) ( $provision_row['ver_after'] ?? '' )
        ) );
        if ( (string) ( $provision_row['action'] ?? '' ) === 'error' && ! empty( $provision_row['detail'] ) ) {
            $write_progress( '    error: ' . substr( preg_replace( '/\s+/', ' ', (string) $provision_row['detail'] ), 0, 300 ) . "\n" );
        }
    }
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

$aggregate_id = sanitize_key( (string) ( $opts['aggregate'] ?? '' ) );
if ( $aggregate_id !== '' ) {
    // [2026-08-29 Johnny Chu] PHASE-1.31-S2.5 — aggregate checkpoints read-only; do not run probes, provisioners, or migrations.
    $aggregate_payload = BizCity_Diagnostics_Smoke_Runner::aggregate_checkpoints( $aggregate_id );
    if ( ! empty( $opts['require-complete'] ) && empty( $aggregate_payload['coverage']['complete'] ) ) {
        $aggregate_payload['verdict'] = 'fail';
        $aggregate_payload['error'] = 'coverage_incomplete';
    }
    if ( $machine_output ) {
        $captured_output = ob_get_clean();
        if ( trim( (string) $captured_output ) !== '' ) {
            fwrite( STDERR, (string) $captured_output );
        }
        $machine_output_emitted = true;
        echo function_exists( 'wp_json_encode' )
            ? wp_json_encode( $aggregate_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
            : json_encode( $aggregate_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        echo "\n";
        exit( (string) ( $aggregate_payload['verdict'] ?? '' ) === 'fail' ? 1 : 0 );
    }
    $write_progress( sprintf( "Aggregate %s: %s\n", $aggregate_id, strtoupper( (string) ( $aggregate_payload['verdict'] ?? 'skip' ) ) ) );
    exit( (string) ( $aggregate_payload['verdict'] ?? '' ) === 'fail' ? 1 : 0 );
}

$requested_batch = sanitize_key( (string) ( $opts['batch'] ?? '' ) );
$requested_resume = sanitize_key( (string) ( $opts['resume'] ?? '' ) );
// [2026-08-29 Johnny Chu] PHASE-1.31-S2.3 — a resume cursor applies to an unfiltered batch run only.
if ( $requested_resume !== '' && (string) $opts['filter'] !== '' ) {
    fwrite( STDERR, "Resume requires a batch run without --filter.\n" );
    exit( 2 );
}
$selected_batch_ids = $requested_batch !== ''
    ? BizCity_Diagnostics_Smoke_Runner::batch_ids( $requested_batch )
    : array_keys( $catalog );
if ( $requested_batch !== '' && empty( $selected_batch_ids ) ) {
    fwrite( STDERR, "Unknown or empty diagnostics batch `{$requested_batch}`.\n" );
    exit( 2 );
}

if ( ! empty( $opts['list-batches'] ) ) {
    // [2026-08-29 Johnny Chu] PHASE-1.31-S2.2 — expose batch hashes without executing probes.
    $batch_payload = array(
        'contract'     => 'diagnostics-verdict',
        'version'      => '1',
        'command'      => 'probe',
        'catalog_hash' => BizCity_Diagnostics_Smoke_Runner::catalog_hash(),
        'batches'      => BizCity_Diagnostics_Smoke_Runner::batches(),
    );
    if ( $machine_output ) {
        $captured_output = ob_get_clean();
        if ( trim( (string) $captured_output ) !== '' ) {
            fwrite( STDERR, (string) $captured_output );
        }
        $machine_output_emitted = true;
        echo function_exists( 'wp_json_encode' )
            ? wp_json_encode( $batch_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
            : json_encode( $batch_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        echo "\n";
        exit( 0 );
    }
    foreach ( $batch_payload['batches'] as $batch_name => $batch_data ) {
        $write_progress( sprintf( "%s\t%d\t%s\n", $batch_name, $batch_data['count'], $batch_data['batch_hash'] ) );
    }
    exit( 0 );
}

$filter_glob = (string) $opts['filter'];
$filter_globs = array();
// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — allow CI profiles to select multiple probe families without running production-only probes on a clean fixture.
foreach ( explode( ',', $filter_glob ) as $filter_part ) {
    $filter_part = trim( $filter_part );
    if ( $filter_part !== '' ) {
        $filter_globs[] = $filter_part;
    }
}
// [2026-09-02 04:20 PM Johnny Chu - Chu Hoàng Anh] R-DDV — reject an exact probe ID silently omitted by an explicitly selected batch and report its canonical rerun batch.
$batch_mismatches = array();
if ( $requested_batch !== '' && ! empty( $filter_globs ) ) {
    foreach ( $filter_globs as $filter_part ) {
        if ( isset( $catalog[ $filter_part ] ) && ! in_array( $filter_part, $selected_batch_ids, true ) ) {
            $batch_mismatches[] = array(
                'probe_id' => $filter_part,
                'requested_batch' => $requested_batch,
                'canonical_batch' => BizCity_Diagnostics_Smoke_Runner::batch_for_probe( $filter_part ),
            );
        }
    }
}
if ( ! empty( $batch_mismatches ) ) {
    $mismatch_payload = array(
        'contract' => 'diagnostics-verdict',
        'version' => '1',
        'command' => 'diagnostics',
        'counts' => array( 'pass' => 0, 'warn' => 0, 'fail' => 1, 'skip' => 0 ),
        'verdict' => 'fail',
        'error' => 'diagnostics_filter_batch_mismatch',
        'detail' => 'One or more exact probe IDs belong to a different canonical batch.',
        'requested_batch' => $requested_batch,
        'mismatches' => $batch_mismatches,
        'results' => array(),
    );
    if ( $machine_output ) {
        $captured_output = ob_get_clean();
        if ( trim( (string) $captured_output ) !== '' ) {
            fwrite( STDERR, (string) $captured_output );
        }
        $machine_output_emitted = true;
        echo function_exists( 'wp_json_encode' )
            ? wp_json_encode( $mismatch_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
            : json_encode( $mismatch_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        echo "\n";
    } else {
        foreach ( $batch_mismatches as $mismatch ) {
            fwrite( STDERR, sprintf( "Probe %s belongs to batch %s; rerun with --batch=%s.\n", $mismatch['probe_id'], $mismatch['canonical_batch'], $mismatch['canonical_batch'] ) );
        }
    }
    exit( 2 );
}
$ids         = [];
foreach ( $catalog as $id => $probe ) {
    if ( ! in_array( $id, $selected_batch_ids, true ) ) { continue; }
    if ( ! empty( $filter_globs ) ) {
        $matched = false;
        foreach ( $filter_globs as $filter_pattern ) {
            if ( fnmatch( $filter_pattern, $id ) ) {
                $matched = true;
                break;
            }
        }
        if ( ! $matched ) { continue; }
    }
    $ids[] = $id;
}

if ( empty( $ids ) ) {
    fwrite( STDERR, "No probes match filter `{$filter_glob}`.\n" );
    exit( 2 );
}

$write_progress( sprintf( "Running %d probe(s)…\n\n", count( $ids ) ) );

/* ── Run probes ────────────────────────────────────────────────────── */
$start_all  = microtime( true );
$results    = [];
$total_pass = 0;
$total_warn = 0;
$total_fail = 0;
$total_skip = 0;
$total_deferred = 0;

if ( empty( $filter_globs ) ) {
    // [2026-08-29 Johnny Chu] PHASE-1.31-S2.3 — delegate unfiltered runs to the checkpoint-aware canonical runner.
    $run_options = array();
    if ( $requested_batch !== '' ) {
        $run_options['batch'] = $requested_batch;
    }
    if ( isset( $opts['resume'] ) && (string) $opts['resume'] !== '' ) {
        $run_options['resume'] = (string) $opts['resume'];
    }
    if ( isset( $opts['run-id'] ) && (string) $opts['run-id'] !== '' ) {
        $run_options['run_id'] = (string) $opts['run-id'];
    }
    // [2026-08-29 Johnny Chu] PHASE-1.32-DIAGNOSTICS-STREAM — stream each probe and CRUD-stop row so VPS operators can see progress and resume safely.
    $streamed_progress = true;
    $run_options['progress_callback'] = static function ( $event ) use ( $write_progress ) {
        $event_type = (string) ( $event['event'] ?? '' );
        if ( $event_type === 'started' ) {
            $write_progress( sprintf(
                "Batch %s started · run_id=%s · resume_cursor=%d/%d\n",
                (string) ( $event['batch'] ?? '' ),
                (string) ( $event['run_id'] ?? '' ),
                (int) ( $event['cursor'] ?? 0 ),
                (int) ( $event['total'] ?? 0 )
            ) );
            return;
        }
        if ( $event_type === 'probe' ) {
            $result = is_array( $event['result'] ?? null ) ? $event['result'] : array();
            $status = strtoupper( (string) ( $result['status'] ?? 'fail' ) );
            $write_progress( sprintf(
                "[%s] %d/%d %s %dms\n",
                $status,
                (int) ( $event['index'] ?? 0 ),
                (int) ( $event['total'] ?? 0 ),
                (string) ( $event['probe_id'] ?? '' ),
                (int) ( $result['duration_ms'] ?? 0 )
            ) );
            $is_crud_stop = (string) ( $event['probe_id'] ?? '' ) === 'core.legacy_table.crud_stop';
            foreach ( $is_crud_stop ? (array) ( $result['table_checks'] ?? array() ) : array() as $check ) {
                if ( ! is_array( $check ) ) {
                    continue;
                }
                $blockers = is_array( $check['blockers'] ?? null ) ? implode( '; ', array_slice( $check['blockers'], 0, 2 ) ) : '';
                $write_progress( sprintf(
                    "  - row %-38s %-8s writer_zero=%s reader_zero=%s fallback_blocked=%s mutations_zero=%s%s\n",
                    (string) ( $check['table'] ?? '' ),
                    strtoupper( (string) ( $check['status'] ?? 'unknown' ) ),
                    ! empty( $check['writer_zero'] ) ? 'yes' : 'no',
                    ! empty( $check['reader_zero'] ) ? 'yes' : 'no',
                    ! empty( $check['fallback_blocked'] ) ? 'yes' : 'no',
                    ! empty( $check['runtime_mutations_zero'] ) ? 'yes' : 'no',
                    $blockers !== '' ? ' · ' . $blockers : ''
                ) );
            }
            if ( function_exists( 'flush' ) ) {
                @flush();
            }
            return;
        }
        if ( $event_type === 'deferred' ) {
            $write_progress( sprintf(
                "[DEFERRED] %d/%d %s · rerun with --resume=%s\n",
                (int) ( $event['index'] ?? 0 ),
                (int) ( $event['total'] ?? 0 ),
                (string) ( $event['probe_id'] ?? '' ),
                (string) ( $event['run_id'] ?? '' )
            ) );
            if ( function_exists( 'flush' ) ) {
                @flush();
            }
            return;
        }
        if ( $event_type === 'finished' ) {
            $write_progress( sprintf(
                "Batch %s finished · run_id=%s · checkpoint=%s\n",
                (string) ( $event['batch'] ?? '' ),
                (string) ( $event['run_id'] ?? '' ),
                (string) ( $event['status'] ?? 'unknown' )
            ) );
            if ( function_exists( 'flush' ) ) {
                @flush();
            }
        }
    };
    $run_meta = BizCity_Diagnostics_Smoke_Runner::run_all( $run_options );
    $results = isset( $run_meta['results'] ) && is_array( $run_meta['results'] ) ? $run_meta['results'] : array();
    $run_counts = isset( $run_meta['counts'] ) && is_array( $run_meta['counts'] ) ? $run_meta['counts'] : array();
    if ( ! empty( $run_counts ) ) {
        $total_pass = (int) ( $run_counts['pass'] ?? 0 );
        $total_warn = (int) ( $run_counts['warn'] ?? 0 );
        $total_fail = (int) ( $run_counts['fail'] ?? 0 );
        $total_skip = (int) ( $run_counts['skip'] ?? 0 );
        $total_deferred = isset( $run_meta['coverage']['deferred'] ) ? (int) $run_meta['coverage']['deferred'] : 0;
    } else {
    foreach ( $results as $res ) {
        $status = (string) ( $res['status'] ?? 'fail' );
        if ( $status === 'pass' ) { $total_pass++; }
        elseif ( $status === 'warn' ) { $total_warn++; }
        elseif ( $status === 'fail' ) { $total_fail++; }
        else {
            $total_skip++;
            if ( (string) ( $res['error'] ?? '' ) === 'budget_deferred' ) { $total_deferred++; }
        }
        if ( empty( $streamed_progress ) ) {
            $write_progress( sprintf( "[%-13s] %-50s %5dms\n", strtoupper( $status ), (string) ( $res['id'] ?? '' ), (int) ( $res['duration_ms'] ?? 0 ) ) );
        }
    }
    }
} else {
foreach ( $ids as $id ) {
    $t0  = microtime( true );
    $res = BizCity_Diagnostics_Smoke_Runner::run_probe( $id );
    $dur = (int) round( ( microtime( true ) - $t0 ) * 1000 );
    $res['duration_ms'] = $res['duration_ms'] ?? $dur;

    $status = (string) ( $res['status'] ?? 'fail' );
    $badge  = strtoupper( $status );
    if ( $status === 'pass' )                { $total_pass++; }
    elseif ( $status === 'warn' )            { $total_warn++; }
    elseif ( $status === 'precheck-fail' || $status === 'skipped' || $status === 'skip' ) { $total_skip++; if ( strpos( (string) ( $res['error'] ?? '' ), 'budget' ) !== false ) { $total_deferred++; } }
    else                                     { $total_fail++; }

    $line = sprintf( "[%-13s] %-50s %5dms", $badge, $id, (int) $res['duration_ms'] );
    if ( ! empty( $res['summary'] ) ) {
        // [2026-08-21 Johnny Chu] DIAGNOSTICS-CLI-EVIDENCE — keep probe failure details visible in CI logs.
        $line .= ' · ' . substr( (string) $res['summary'], 0, 240 );
    }
    if ( $status === 'precheck-fail' && ! empty( $res['error'] ) ) {
        // [2026-08-28 Johnny Chu] PHASE-1.30-DDV — expose the skip reason so CLI evidence distinguishes unavailable topology/dependency from a passing probe.
        $line .= "\n      ↳ precondition: " . substr( (string) $res['error'], 0, 500 );
    }
    if ( $status === 'fail' && ! empty( $res['error'] ) ) {
        // [2026-08-21 Johnny Chu] DIAGNOSTICS-CLI-EVIDENCE — preserve enough structured error detail for multi-step probes.
        $line .= "\n      ↳ " . substr( (string) $res['error'], 0, 500 );
    }
    $write_progress( $line . "\n" );
    // [2026-08-29 Johnny Chu] PHASE-1.32-DIAGNOSTICS-STREAM — filtered probe runs also expose every sub-step and CRUD row immediately.
    foreach ( (array) ( $res['steps'] ?? array() ) as $step ) {
        if ( ! is_array( $step ) ) {
            continue;
        }
        $step_detail = isset( $step['detail'] ) ? ' · ' . substr( (string) $step['detail'], 0, 500 ) : '';
        $write_progress( sprintf(
            "  ↳ [%s] %s%s\n",
            strtoupper( (string) ( $step['status'] ?? 'unknown' ) ),
            (string) ( $step['label'] ?? 'step' ),
            $step_detail
        ) );
        if ( function_exists( 'flush' ) ) {
            @flush();
        }
    }

    $results[ $id ] = $res;
}
}

$dur_all = (int) round( ( microtime( true ) - $start_all ) * 1000 );

$write_progress( sprintf(
    "\nResult: %d pass · %d fail · %d skip · total %dms\n",
    $total_pass, $total_fail, $total_skip, $dur_all
) );
if ( isset( $run_meta['coverage'] ) && empty( $run_meta['coverage']['complete'] ) && ! empty( $run_meta['run_id'] ) ) {
    // [2026-08-29 Johnny Chu] PHASE-1.32-DIAGNOSTICS-STREAM — expose the exact checkpoint resume command when a bounded batch stops before completion.
    $resume_command = 'php bin/diagnostics-run.php --batch=' . (string) ( $run_meta['batch'] ?? $requested_batch ) . ' --resume=' . (string) $run_meta['run_id'] . ' --skip-network --skip-provision';
    $write_progress( "Resume command: {$resume_command}\n" );
}
if ( $total_fail > 0 ) {
    // [2026-08-26 Johnny Chu] DIAGNOSTICS-CLI-EVIDENCE — list failed probe IDs and bounded errors directly in CI output.
    $write_progress( "Failed probes:\n" );
    foreach ( $results as $failed_id => $failed_result ) {
        if ( (string) ( $failed_result['status'] ?? '' ) !== 'fail' ) {
            continue;
        }
        $failed_detail = (string) ( $failed_result['error'] ?? $failed_result['summary'] ?? 'no detail' );
        $write_progress( ' - ' . $failed_id . ': ' . substr( $failed_detail, 0, 500 ) . "\n" );
    }
}

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
            // [2026-08-26 Johnny Chu] DIAGNOSTICS-JUNIT-EVIDENCE — preserve failed sub-step labels/details in CI artifacts, not only the aggregate summary.
            $failed_steps = array();
            foreach ( (array) ( $res['steps'] ?? array() ) as $step ) {
                if ( ! is_array( $step ) || strtolower( (string) ( $step['status'] ?? '' ) ) !== 'fail' ) {
                    continue;
                }
                $failed_steps[] = (string) ( $step['label'] ?? 'failed_step' )
                    . ( isset( $step['detail'] ) ? ': ' . (string) $step['detail'] : '' );
                if ( count( $failed_steps ) >= 12 ) {
                    break;
                }
            }
            $failure_body = (string) ( $res['summary'] ?? '' );
            if ( ! empty( $failed_steps ) ) {
                $failure_body .= ' | Failed steps: ' . implode( ' || ', $failed_steps );
            }
            $xml .= sprintf(
                '      <failure message="%s">%s</failure>' . "\n",
                htmlspecialchars( substr( (string) ( $res['error'] ?? 'fail' ), 0, 500 ), ENT_XML1 | ENT_COMPAT, 'UTF-8' ),
                htmlspecialchars( substr( $failure_body, 0, 4000 ), ENT_XML1 | ENT_COMPAT, 'UTF-8' )
            );
        } elseif ( $st === 'precheck-fail' || $st === 'skipped' || $st === 'skip' ) {
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
    $write_progress( sprintf( "JUnit XML written to %s\n", $opts['junit'] ) );
}

if ( $machine_output ) {
    $captured_output = ob_get_clean();
    if ( trim( $captured_output ) !== '' ) {
        // [2026-08-29 Johnny Chu] PHASE-1.31-S1.4 — keep bootstrap/probe chatter out of machine-readable stdout while preserving it for CI diagnostics.
        fwrite( STDERR, $captured_output );
    }
    $machine_output_emitted = true;
    $machine_payload = array(
        'contract'   => 'diagnostics-verdict',
        'version'    => '1',
        'command'    => 'diagnostics',
		'batch'      => $requested_batch !== '' ? $requested_batch : 'all',
        'run_id'      => isset( $run_meta['run_id'] ) ? (string) $run_meta['run_id'] : '',
        // [2026-08-29 Johnny Chu] PHASE-1.32-S3.3 — preserve only bounded environment identity in machine artifacts.
        'environment' => isset( $run_meta['environment'] ) ? $run_meta['environment'] : array( 'php' => PHP_VERSION, 'wordpress' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '', 'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ),
        'catalog_hash' => isset( $run_meta['catalog_hash'] ) ? (string) $run_meta['catalog_hash'] : BizCity_Diagnostics_Smoke_Runner::catalog_hash(),
        'batch_hash'   => isset( $run_meta['batch_hash'] ) ? (string) $run_meta['batch_hash'] : ( $requested_batch !== '' ? BizCity_Diagnostics_Smoke_Runner::batch_hash( $requested_batch ) : BizCity_Diagnostics_Smoke_Runner::batch_hash( 'all' ) ),
        // [2026-08-29 Johnny Chu] PHASE-1.32-S1 — expose fix_hint coverage for actionable failures and warnings.
        'evidence_audit' => isset( $run_meta['evidence_audit'] ) ? $run_meta['evidence_audit'] : BizCity_Diagnostics_Smoke_Runner::audit_actionable_evidence( $results ),
        'counts'     => array(
            'pass' => $total_pass,
            'warn' => $total_warn,
            'fail' => $total_fail,
            'skip' => $total_skip,
        ),
        'verdict'    => $total_fail > 0 ? 'fail' : ( $total_warn > 0 ? 'warn' : ( $total_pass > 0 ? 'pass' : 'skip' ) ),
        'duration_ms'=> $dur_all,
        'coverage'    => isset( $run_meta['coverage'] ) ? $run_meta['coverage'] : array(
            'catalog_total'   => count( $catalog ),
            'selected_total'  => count( $ids ),
            'executed'        => $total_pass + $total_warn + $total_fail,
            'allowed_skipped' => $total_skip - $total_deferred,
            'deferred'        => $total_deferred,
            'complete'        => false,
        ),
        'results'    => $results,
    );
    if ( ! empty( $run_meta['error'] ) ) {
        $machine_payload['verdict'] = 'fail';
        $machine_payload['error'] = (string) $run_meta['error'];
    }
    if ( ! empty( $opts['require-complete'] ) && empty( $machine_payload['coverage']['complete'] ) ) {
        // [2026-08-29 Johnny Chu] PHASE-1.31-S2.5 — fail machine mode when the release caller requires complete coverage.
        $machine_payload['verdict'] = 'fail';
        $machine_payload['error'] = 'coverage_incomplete';
    }
    echo function_exists( 'wp_json_encode' )
        ? wp_json_encode( $machine_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        : json_encode( $machine_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    echo "\n";
}

$coverage_complete = isset( $run_meta['coverage']['complete'] )
    ? ! empty( $run_meta['coverage']['complete'] )
    : false;
exit( ! empty( $run_meta['error'] ) || ( ! empty( $opts['require-complete'] ) && ! $coverage_complete ) || $total_fail > 0 ? 1 : 0 );
