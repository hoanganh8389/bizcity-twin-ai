<?php

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'is_multisite' ) ) {
    function is_multisite() {
        return ! empty( $GLOBALS['bizcity_framework_cli_test_multisite'] );
    }
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
    function get_current_blog_id() {
        return (int) ( $GLOBALS['bizcity_framework_cli_test_blog_id'] ?? 1 );
    }
}
if ( ! function_exists( 'get_site' ) ) {
    function get_site( $blog_id ) {
        return 7 === (int) $blog_id ? (object) array( 'blog_id' => 7 ) : false;
    }
}
if ( ! function_exists( 'switch_to_blog' ) ) {
    function switch_to_blog( $blog_id ) {
        $GLOBALS['bizcity_framework_cli_test_blog_id'] = (int) $blog_id;
        return true;
    }
}
if ( ! function_exists( 'restore_current_blog' ) ) {
    function restore_current_blog() {
        $GLOBALS['bizcity_framework_cli_test_blog_id'] = 1;
        return true;
    }
}

require_once dirname( __DIR__, 2 ) . '/core/cli/class-bizcity-framework-cli.php';
require_once dirname( __DIR__, 2 ) . '/core/diagnostics/includes/class-diagnostics-smoke-runner.php';

final class FrameworkCliTest extends TestCase {

    public function test_json_flags_are_recognized(): void {
        // [2026-08-28 Johnny Chu] PHASE-1.31 — cover machine-readable CLI flags.
        $this->assertTrue( BizCity_Framework_CLI::wants_json( array( 'json' => true ) ) );
        $this->assertTrue( BizCity_Framework_CLI::wants_json( array( 'format' => 'json' ) ) );
        $this->assertFalse( BizCity_Framework_CLI::wants_json( array( 'format' => 'table' ) ) );
    }

    public function test_strict_promotes_warning_to_failure_exit(): void {
        // [2026-08-28 Johnny Chu] PHASE-1.31 — cover shared verdict exit policy.
        $warning = BizCity_Framework_CLI::aggregate( array( array( 'id' => 'warning', 'status' => 'warn' ) ) );

        $this->assertSame( 'warn', $warning['verdict'] );
        $this->assertSame( 0, BizCity_Framework_CLI::exit_code( $warning, array() ) );
        $this->assertSame( 1, BizCity_Framework_CLI::exit_code( $warning, array( 'strict' => true ) ) );
    }

    public function test_aggregate_normalizes_statuses_and_counts_failures(): void {
        // [2026-08-28 Johnny Chu] PHASE-1.31 — keep legacy probe statuses in one verdict contract.
        $payload = BizCity_Framework_CLI::aggregate( array(
            array( 'id' => 'pass', 'status' => 'pass' ),
            array( 'id' => 'skipped', 'status' => 'skipped' ),
            array( 'id' => 'precheck', 'status' => 'precheck-fail' ),
            array( 'id' => 'unknown', 'status' => 'unexpected' ),
        ) );

        $this->assertSame( array( 'pass' => 1, 'warn' => 0, 'fail' => 1, 'skip' => 2 ), $payload['counts'] );
        $this->assertSame( 'fail', $payload['verdict'] );
    }

    public function test_all_skipped_results_remain_skip(): void {
        // [2026-08-28 Johnny Chu] PHASE-1.31 — prevent skipped runtime evidence becoming PASS.
        $payload = BizCity_Framework_CLI::aggregate( array( array( 'id' => 'unavailable', 'status' => 'skip' ) ) );

        $this->assertSame( 'skip', $payload['verdict'] );
        $this->assertSame( 0, BizCity_Framework_CLI::exit_code( $payload, array() ) );
    }

    public function test_actionable_evidence_counts_only_failures_and_warnings(): void {
        // [2026-08-29 Johnny Chu] PHASE-1.32-S1 — keep fix_hint coverage measurable and exclude intentional precondition skips.
        $audit = BizCity_Diagnostics_Smoke_Runner::audit_actionable_evidence( array(
            array( 'id' => 'fail_with_hint', 'status' => 'fail', 'fix_hint' => 'Rerun after repair.' ),
            array( 'id' => 'warn_without_hint', 'status' => 'warn', 'fix_hint' => '' ),
            array( 'id' => 'precondition_skip', 'status' => 'precheck-fail' ),
        ) );

        $this->assertSame( 2, $audit['actionable_total'] );
        $this->assertSame( 1, $audit['with_fix_hint'] );
        $this->assertSame( 1, $audit['missing_fix_hint'] );
        $this->assertSame( 50.0, $audit['coverage_percent'] );
        $this->assertSame( array( 'warn_without_hint' ), $audit['missing_probe_ids'] );
    }

    public function test_rerun_metadata_is_narrow_and_rejects_unsafe_probe_ids(): void {
        // [2026-08-29 Johnny Chu] PHASE-1.32-S3.4 — keep failure triage commands bounded to registered probe-shaped IDs.
        $rerun = BizCity_Diagnostics_Smoke_Runner::rerun_metadata( 'core.module-registry' );

        $this->assertSame( 'php bin/diagnostics-run.php --filter=core.module-registry --format=json', $rerun['probe'] );
        $this->assertSame( 'php bin/diagnostics-run.php --batch=health --format=json', $rerun['batch'] );
        $this->assertSame( 'wp bizcity probe --id=core.module-registry --format=json', $rerun['wp_cli'] );
        $this->assertSame( array( 'probe' => '', 'batch' => '', 'wp_cli' => '' ), BizCity_Diagnostics_Smoke_Runner::rerun_metadata( 'bad;rm -rf' ) );
    }

    public function test_explicit_blog_switch_restores_original_context(): void {
        // [2026-08-28 Johnny Chu] PHASE-1.31 — verify explicit multisite CLI scope handling.
        $GLOBALS['bizcity_framework_cli_test_multisite'] = true;
        $GLOBALS['bizcity_framework_cli_test_blog_id'] = 1;

        try {
            $origin = BizCity_Framework_CLI::switch_blog( array( 'blog' => '7' ) );

            $this->assertSame( 1, $origin );
            $this->assertSame( 7, get_current_blog_id() );

            BizCity_Framework_CLI::restore_blog( $origin );
            $this->assertSame( 1, get_current_blog_id() );
        } finally {
            $GLOBALS['bizcity_framework_cli_test_multisite'] = false;
            $GLOBALS['bizcity_framework_cli_test_blog_id'] = 1;
        }
    }
}