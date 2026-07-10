<?php
/**
 * BizCity Diagnostics — kg.skeleton probe (Consolidation M1).
 *
 * R-DDV — verify KG-Hub Notebook Skeleton foundation (PHASE-0-RULE-SKELETON):
 *
 *   Layer 1 · DISK + LOADER: required classes + table + 4 skeleton columns.
 *   Layer 2 · LOADER: BizCity_KG_Skeleton_Diagnostic callable.
 *   Layer 3 · RUNTIME: audit_blog() returns ok=true with status histogram.
 *
 * Replaces standalone admin page `tools.php?page=bizcity-kg-skeleton-diag`
 * (per DIAGNOSTIC-CONSOLIDATION-PLAN.md M1). Business logic stays in
 * `BizCity_KG_Skeleton_Diagnostic` (still callable via CLI / dispatcher).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Consolidation M1 (2026-06-02)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_KG_Skeleton', false ) ) {
	return;
}

final class BizCity_Probe_KG_Skeleton implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'kg.skeleton'; }
	public function label(): string       { return 'KG · Notebook Skeleton (PHASE-0-RULE-SKELETON)'; }
	public function description(): string {
		return 'Verify KG-Hub notebook skeleton foundation: classes loaded, kg_notebooks table + 4 skeleton columns present, audit_blog() returns ok=true.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 70; }
	public function icon(): string        { return 'screenoptions'; }
	public function estimate_ms(): int    { return 800; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_KG_Skeleton_Diagnostic' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_KG_Skeleton_Diagnostic chưa load — KG-Hub bootstrap?' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		// ── Layer 1+2 · LOADER · required classes ─────────────────────
		$required = array(
			'BizCity_KG_Database',
			'BizCity_KG_Skeleton_Adapter',
			'BizCity_KG_Skeleton_Service',
			'BizCity_KG_Skeleton_Prompt',
			'BizCity_KG_Skeleton_REST',
			'BizCity_KG_Skeleton_Diagnostic',
		);
		$missing = array();
		foreach ( $required as $cls ) {
			if ( ! class_exists( $cls ) ) { $missing[] = $cls; }
		}
		$steps[] = $s = array(
			'label'  => 'Loader · 6 skeleton classes',
			'status' => $missing ? 'fail' : 'pass',
			'detail' => $missing ? ( 'missing: ' . implode( ', ', $missing ) ) : 'all loaded',
		);
		$ctx->emit_step( $s );
		if ( $missing ) {
			return self::fail( $steps, 'KG skeleton classes missing.', 'class_missing',
				'Verify core/knowledge/kg-hub/skeleton/ bootstrap chạy.' );
		}

		// ── Layer 3 · RUNTIME · audit_blog() ──────────────────────────
		$diag  = BizCity_KG_Skeleton_Diagnostic::instance();
		$audit = $diag->audit_blog( 0 );
		// [2026-07-09 Johnny Chu] HOTFIX — foundation probe should fail only for
		// structural issues (schema/class/index), not transient failed/stuck backlog.
		$audit_is_array   = is_array( $audit );
		$reason           = $audit_is_array ? (string) ( $audit['reason'] ?? '' ) : 'invalid_payload';
		$missing_columns  = $audit_is_array ? (array) ( $audit['missing_columns'] ?? [] ) : [];
		$index_present    = $audit_is_array ? (bool) ( $audit['schema']['index_present'] ?? true ) : false;
		$table_missing    = $reason !== '' && strpos( $reason, 'table missing' ) !== false;
		$foundation_ok    = $audit_is_array && ! $table_missing && empty( $missing_columns ) && $index_present;

		$steps[] = $s = array(
			'label'  => 'Runtime · audit_blog structural checks',
			'status' => $foundation_ok ? 'pass' : 'fail',
			'detail' => $foundation_ok
				? 'schema/index/class checks pass · blog_id=' . ( $audit['blog_id'] ?? '?' )
				: ( 'reason=' . ( $reason !== '' ? $reason : 'n/a' ) ),
		);
		$ctx->emit_step( $s );
		if ( ! $foundation_ok ) {
			if ( ! empty( $missing_columns ) ) {
				$hint = 'Missing columns: ' . implode( ',', $missing_columns ) . ' — bump KG SCHEMA_VERSION + repair via BizCity Diagnostics.';
			} elseif ( ! $index_present ) {
				$hint = 'Index idx_nb_skeleton_status missing — run schema repair via BizCity Diagnostics.';
			} elseif ( $table_missing ) {
				$hint = 'kg_notebooks table missing — run wp bizcity diag repair.';
			} else {
				$hint = 'Run wp bizcity diag skeleton-audit cho chi tiết.';
			}
			return self::fail( $steps, 'KG skeleton audit FAIL.', 'audit_failed', $hint );
		}

		$failed_count = (int) ( $audit['failed']['count'] ?? 0 );
		$stuck_count  = (int) ( $audit['stuck']['count'] ?? 0 );
		$steps[] = $s = array(
			'label'  => 'Runtime · lifecycle backlog snapshot',
			'status' => 'pass',
			'detail' => sprintf(
				'failed=%d · stuck=%d%s',
				$failed_count,
				$stuck_count,
				( $failed_count + $stuck_count ) > 0 ? ' (non-blocking for foundation probe)' : ''
			),
		);
		$ctx->emit_step( $s );

		// ── Optional · totals summary (notebooks + status histogram) ──
		if ( is_array( $audit ) && isset( $audit['totals'] ) && is_array( $audit['totals'] ) ) {
			$h = array();
			foreach ( $audit['totals'] as $k => $v ) { $h[] = $k . '=' . (int) $v; }
			$steps[] = array(
				'label'  => 'Runtime · totals snapshot',
				'status' => 'pass',
				'detail' => $h ? implode( ' · ', $h ) : 'no rows',
			);
		}

		$summary = 'KG notebook skeleton foundation OK (classes + schema + audit).';
		if ( ( $failed_count + $stuck_count ) > 0 ) {
			$summary = sprintf(
				'KG notebook skeleton foundation OK; backlog: failed=%d, stuck=%d (see skeleton-audit for ops details).',
				$failed_count,
				$stuck_count
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => $summary,
			'steps'   => $steps,
		);
	}

	public function cleanup(): void { /* read-only probe */ }

	private static function fail( array $steps, string $summary, string $error, string $hint ): array {
		return array(
			'status'   => 'fail',
			'summary'  => $summary,
			'error'    => $error,
			'fix_hint' => $hint,
			'steps'    => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_KG_Skeleton';
	return $list;
} );
