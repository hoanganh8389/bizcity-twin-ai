<?php
/**
 * BizCity Diagnostics — upload.learning probe (Phase 0.41 L9.c).
 *
 * Wiring probe for the TwinChat Upload → Learning pipeline. Verifies:
 *   1. Sources service class loaded.
 *   2. `bizcity_webchat_sources` table exists.
 *   3. Learning queue table exists (`bizcity_learning_jobs`).
 *   4. Cron schedule for learning sweep is registered.
 *
 * Does NOT enqueue a real learning job (would consume embedding tokens).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-21 (Phase 0.41 L9.c)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Upload_Learning implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'upload.learning'; }
	public function label(): string       { return 'Upload → Learning'; }
	public function description(): string {
		return 'Kiểm tra service class, bảng webchat_sources + learning_jobs, và cron sweep cho pipeline upload → embedding.';
	}
	public function severity(): string    { return 'info'; }
	public function order(): int          { return 50; }
	public function icon(): string        { return 'upload-cloud'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		global $wpdb;

		// 1. Classes.
		$candidates = [
			'BizCity_TwinChat_Sources_Service',
			'BizCity_TwinChat_Sources_Database',
			'BizCity_TwinChat_Learning_Pipeline',
		];
		$found = array_values( array_filter( $candidates, 'class_exists' ) );
		$ctx->emit_step( [
			'label'  => 'Service classes',
			'status' => $found ? 'pass' : 'fail',
			'detail' => $found ? implode( ', ', $found ) : 'none',
		] );

		// 2. Tables.
		// 2026-05-29: schema renamed v1.2.0 — legacy `bizcity_learning_jobs` is now
		// `bizcity_kg_learning_jobs` (unified KG naming). Accept either for backward compat.
		$webchat        = $wpdb->prefix . 'bizcity_webchat_sources';
		$learning_v2    = $wpdb->prefix . 'bizcity_kg_learning_jobs';
		$learning_v1    = $wpdb->prefix . 'bizcity_learning_jobs';
		$webchat_ok     = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $webchat ) );
		$learning_v2_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $learning_v2 ) );
		$learning_v1_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $learning_v1 ) );
		$learning_ok    = $learning_v2_ok || $learning_v1_ok;
		$learning_name  = $learning_v2_ok ? $learning_v2 : ( $learning_v1_ok ? $learning_v1 : '(none)' );
		$ctx->emit_step( [
			'label'  => 'webchat_sources table',
			'status' => $webchat_ok ? 'pass' : 'fail',
			'detail' => $webchat_ok ? $webchat : 'missing',
		] );
		$ctx->emit_step( [
			'label'  => 'learning_jobs table (v1 or v2)',
			'status' => $learning_ok ? 'pass' : 'fail',
			'detail' => $learning_ok ? $learning_name : 'missing both ' . $learning_v2 . ' and ' . $learning_v1,
		] );

		// 3. Cron schedule (sweep).
		// 2026-05-29: hook renamed v1.2.0 — `bizcity_kg_learning_sweep` is the canonical
		// name. Older `bizcity_twinchat_learning_sweep` / `bizcity_learning_sweep` accepted
		// for backward compat.
		$cron_hooks = [
			'bizcity_kg_learning_sweep',
			'bizcity_twinchat_learning_sweep',
			'bizcity_learning_sweep',
		];
		$cron_ok = false;
		$cron_hit = '';
		foreach ( $cron_hooks as $h ) {
			if ( wp_next_scheduled( $h ) ) {
				$cron_ok  = true;
				$cron_hit = $h;
				break;
			}
		}
		$ctx->emit_step( [
			'label'  => 'Cron sweep scheduled',
			'status' => $cron_ok ? 'pass' : 'fail',
			'detail' => $cron_ok ? $cron_hit : 'no learning sweep cron hook',
		] );

		$ok = $found && $webchat_ok && $learning_ok && $cron_ok;
		if ( ! $ok ) {
			$failures = [];
			if ( ! $found )       { $failures[] = 'classes missing'; }
			if ( ! $webchat_ok )  { $failures[] = 'webchat_sources missing'; }
			if ( ! $learning_ok ) { $failures[] = 'learning_jobs missing'; }
			if ( ! $cron_ok )     { $failures[] = 'cron sweep chưa schedule'; }
			return [
				'status'   => 'fail',
				'summary'  => 'Upload pipeline incomplete — ' . implode( '; ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Mở Diagnostics → Provisioner và chạy installer cho twinchat-sources + learning.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf( 'Upload→Learning OK — %d classes, 2 tables, cron %s', count( $found ), $cron_hit ),
		];
	}

	public function cleanup(): void {
		// Read-only.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Upload_Learning';
	return $list;
} );
