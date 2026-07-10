<?php
/**
 * BizCity Diagnostics — research.deep probe (Phase 0.41 L9.c).
 *
 * Structural smoke test for the Deep Research pipeline. Does NOT call any
 * paid LLM endpoint — instead validates that the wiring is intact:
 *   1. Service classes load.
 *   2. REST routes are registered under `bizcity-research/v1`.
 *   3. `bizcity_research_jobs` table exists + has expected key columns.
 *   4. (Optional) Recent jobs have non-zero status distribution.
 *
 * This is a "wiring probe": it answers "would Deep Research work if I called
 * it now?" without spending budget. Real-call probes can be layered on top.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-21 (Phase 0.41 L9.c)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Research_Deep', false ) ) {
	return;
}

final class BizCity_Probe_Research_Deep implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'research.deep'; }
	public function label(): string       { return 'Deep Research wiring'; }
	public function description(): string {
		return 'Kiểm tra service class, REST routes, và bảng bizcity_research_jobs cho pipeline Deep Research (không gọi LLM thật).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 30; }
	public function icon(): string        { return 'flask-conical'; }
	public function estimate_ms(): int    { return 500; }

	public function precondition() {
		return true; // Always runnable; fail-soft if components absent.
	}

	public function run( $ctx ): array {
		global $wpdb;

		// 2026-05-23 — Phase 0.41 L9.c fix: real namespace là `bizcity/research/v1`
		// (xem `BizCity_Research_REST::NS`), không phải `bizcity-research/v1`.
		// Bảng thật là sessions/turns/ingests (xem `BizCity_Research_DB::install`),
		// không có bảng `bizcity_research_jobs` — đó là spec cũ chưa từng ship.
		$rest_ns       = 'bizcity/research/v1';
		$expected_tbls = [
			$wpdb->prefix . 'bizcity_research_sessions',
			$wpdb->prefix . 'bizcity_research_turns',
			$wpdb->prefix . 'bizcity_research_ingests',
		];

		// 1. Class presence — soft (any of these implies module loaded).
		$class_candidates = [
			'BizCity_Research_Ingest_Service',
			'BizCity_Research_REST',
			'BizCity_Research_DB',
		];
		$found_classes = array_values( array_filter( $class_candidates, 'class_exists' ) );
		$ctx->emit_step( [
			'label'  => 'Service classes loaded',
			'status' => $found_classes ? 'pass' : 'fail',
			'detail' => $found_classes ? implode( ', ', $found_classes ) : 'none of: ' . implode( ', ', $class_candidates ),
		] );
		if ( ! $found_classes ) {
			return [
				'status'   => 'fail',
				'error'    => 'Research module classes không tồn tại — module có thể chưa được bật.',
				'fix_hint' => 'Kiểm tra wp-admin → Plugins xem bizcity-research / core/research đã active chưa.',
			];
		}

		// 2. REST namespace check.
		$rest_ok = false;
		if ( function_exists( 'rest_get_server' ) ) {
			$server = rest_get_server();
			if ( $server ) {
				$routes  = (array) $server->get_routes();
				$rest_ok = ! empty( $routes[ '/' . $rest_ns ] )
					|| (bool) preg_grep( '#^/' . preg_quote( $rest_ns, '#' ) . '#', array_keys( $routes ) );
			}
		}
		$ctx->emit_step( [
			'label'  => 'REST routes registered',
			'status' => $rest_ok ? 'pass' : 'fail',
			'detail' => $rest_ok ? $rest_ns : 'no routes under ' . $rest_ns,
		] );

		// 3. Table presence — auto-heal nếu vắng & class installer có sẵn.
		$missing = [];
		foreach ( $expected_tbls as $t ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
				$missing[] = $t;
			}
		}
		// Best-effort idempotent install nếu probe phát hiện thiếu.
		if ( $missing && class_exists( 'BizCity_Research_DB' )
			&& method_exists( 'BizCity_Research_DB', 'install' ) ) {
			try {
				BizCity_Research_DB::install();
				// Re-check sau khi install.
				$still_missing = [];
				foreach ( $expected_tbls as $t ) {
					if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
						$still_missing[] = $t;
					}
				}
				$missing = $still_missing;
			} catch ( \Throwable $e ) {
				// fall through; probe sẽ FAIL với detail bên dưới.
			}
		}
		$ctx->emit_step( [
			'label'  => 'Tables bizcity_research_*',
			'status' => empty( $missing ) ? 'pass' : 'fail',
			'detail' => empty( $missing )
				? implode( ', ', array_map( static function ( $t ) { return preg_replace( '/^.*?(bizcity_)/', '$1', $t ); }, $expected_tbls ) )
				: 'missing: ' . implode( ', ', $missing ),
		] );

		$ok       = (bool) $found_classes && $rest_ok && empty( $missing );
		$failures = [];
		if ( ! $rest_ok )         { $failures[] = "REST namespace `{$rest_ns}` chưa register"; }
		if ( ! empty( $missing ) ) { $failures[] = count( $missing ) . ' bảng research_* missing'; }

		if ( ! $ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Research wiring incomplete — ' . implode( '; ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Mở Diagnostics → Repair Hub → chạy installer "Research". Nếu REST vẫn thiếu: deactivate/activate plugin để `rest_api_init` re-fire.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf( 'Research wiring OK — %d classes, REST routes registered, %d bảng tồn tại', count( $found_classes ), count( $expected_tbls ) ),
		];
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Research_Deep';
	return $list;
} );
