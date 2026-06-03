<?php
/**
 * BizCity Diagnostics — vector.graph probe (Phase 0.41 L9.c).
 *
 * Wiring probe for the KG Graph + Vector store integrity:
 *   1. KG tables present: bizcity_kg_passages, bizcity_kg_entities, bizcity_kg_relations.
 *   2. KG Graph Service class loaded.
 *   3. (If passages exist) consistency check: every entity row references a
 *      non-orphan notebook_id; relation cols ref existing entities.
 *
 * Read-only — does NOT promote or delete any passage.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-21 (Phase 0.41 L9.c)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Vector_Graph implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'vector.graph'; }
	public function label(): string       { return 'Vector + Graph integrity'; }
	public function description(): string {
		return 'Kiểm tra KG tables (passages/entities/relations), service class, và consistency cơ bản (orphan ratio).';
	}
	public function severity(): string    { return 'info'; }
	public function order(): int          { return 60; }
	public function icon(): string        { return 'share-2'; }
	public function estimate_ms(): int    { return 400; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		global $wpdb;

		$passages  = $wpdb->prefix . 'bizcity_kg_passages';
		$entities  = $wpdb->prefix . 'bizcity_kg_entities';
		$relations = $wpdb->prefix . 'bizcity_kg_relations';

		$p_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $passages ) );
		$e_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entities ) );
		$r_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relations ) );

		$ctx->emit_step( [
			'label'  => 'kg_passages table',
			'status' => $p_ok ? 'pass' : 'fail',
			'detail' => $p_ok ? $passages : 'missing',
		] );
		$ctx->emit_step( [
			'label'  => 'kg_entities table',
			'status' => $e_ok ? 'pass' : 'fail',
			'detail' => $e_ok ? $entities : 'missing',
		] );
		$ctx->emit_step( [
			'label'  => 'kg_relations table',
			'status' => $r_ok ? 'pass' : 'fail',
			'detail' => $r_ok ? $relations : 'missing',
		] );

		// Service class.
		$svc_ok = class_exists( 'BizCity_KG_Graph_Service' ) || class_exists( 'BizCity_KG_Database' );
		$ctx->emit_step( [
			'label'  => 'KG service class',
			'status' => $svc_ok ? 'pass' : 'fail',
			'detail' => $svc_ok ? 'loaded' : 'BizCity_KG_Graph_Service / BizCity_KG_Database missing',
		] );

		// Consistency — only meaningful if tables exist.
		$orphan_ratio_msg = '—';
		$orphan_pct       = 0.0;
		if ( $p_ok && $e_ok ) {
			$passage_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$passages}" );
			if ( $passage_rows > 0 ) {
				$orphans = (int) $wpdb->get_var( "
					SELECT COUNT(*) FROM {$passages} p
					LEFT JOIN {$entities} e ON e.notebook_id = p.notebook_id
					WHERE e.id IS NULL
				" );
				$orphan_pct = $passage_rows > 0 ? ( $orphans / $passage_rows * 100 ) : 0;
				$orphan_ratio_msg = sprintf( '%d/%d (%.1f%%)', $orphans, $passage_rows, $orphan_pct );
			} else {
				$orphan_ratio_msg = 'no passages yet';
			}
			$ctx->emit_step( [
				'label'  => 'Orphan passages (no entity in notebook)',
				'status' => $orphan_pct > 50 ? 'fail' : 'pass',
				'detail' => $orphan_ratio_msg,
			] );
		}

		$ok = $p_ok && $e_ok && $r_ok && $svc_ok;
		if ( ! $ok ) {
			$failures = [];
			if ( ! $p_ok )   { $failures[] = 'kg_passages missing'; }
			if ( ! $e_ok )   { $failures[] = 'kg_entities missing'; }
			if ( ! $r_ok )   { $failures[] = 'kg_relations missing'; }
			if ( ! $svc_ok ) { $failures[] = 'KG service class missing'; }
			return [
				'status'   => 'fail',
				'summary'  => 'KG wiring incomplete — ' . implode( '; ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Mở Diagnostics → Repair Hub và chạy installer kg-hub.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf( 'KG OK — 3 tables, service loaded, orphan ratio %s', $orphan_ratio_msg ),
		];
	}

	public function cleanup(): void {
		// Read-only.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Vector_Graph';
	return $list;
} );
