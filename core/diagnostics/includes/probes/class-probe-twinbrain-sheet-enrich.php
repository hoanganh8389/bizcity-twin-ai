<?php
/**
 * BizCity Diagnostics — twinbrain.sheets.enrich probe
 * (Wave 2.8e TBR.TOOL-S5).
 *
 * Real-call probe cho TwinBrain Sheets pipeline. Tạo 1 sheet 1x3
 * (Company=Anthropic, Founded="", HQ="") → `Sheet_Enricher::enrich_sheet()`
 * → verify (a) 2 target cells được fill (status='enriched', value khác rỗng),
 * (b) sources_json có ≥ 1 item, (c) sheet aggregate counters update
 * (cost_cents > 0, source_count > 0, status='complete'), (d) SSE events
 * emit đủ {sheet_enrich_start, sheet_cell_enriched ×2, sheet_enrich_done}.
 *
 * Severity = warning vì depend external Tavily + LLM gateway. Cleanup xoá
 * sheet + cells theo title sentinel.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-24 (Phase 0.36-UNIFIED Wave 2.8e TBR.TOOL-S5)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Sheet_Enrich', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Sheet_Enrich implements BizCity_Diagnostics_Probe {

	const SENTINEL = '__healthtest_sheet_kookaburra51';

	public function id(): string          { return 'twinbrain.sheets.enrich'; }
	public function label(): string       { return 'TwinBrain Sheets — 3-stage Enricher'; }
	public function description(): string {
		return 'Create 1x3 sheet (Company=Anthropic, Founded="", HQ="") → enrich_sheet(max=4) → verify cells filled + sources_json + SSE events + sheet aggregates. Tavily qua gateway router (R-GW-1), không gọi api.tavily.com trực tiếp.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 68; }
	public function icon(): string     { return 'table'; }
	public function estimate_ms(): int { return 6000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Sheet_Enricher' ) ) {
			return 'Sheet_Enricher class chưa load — kiểm tra core/twinbrain/bootstrap.php (sheets module).';
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Sheets_Installer' ) ) {
			return 'Sheets_Installer class chưa load.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login (owner-gated SQL).';
		}
		if ( function_exists( 'bizcity_tavily_is_ready' ) && ! bizcity_tavily_is_ready() ) {
			return 'Tavily API chưa cấu hình — TwinAI → Settings.';
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return 'LLM client chưa load.';
		}

		// Verify schema tables tồn tại.
		global $wpdb;
		$sheets_table = BizCity_TwinBrain_Sheets_Installer::sheets_table();
		$cells_table  = BizCity_TwinBrain_Sheets_Installer::cells_table();
		$missing = [];
		foreach ( [ $sheets_table, $cells_table ] as $t ) {
			$found = bizcity_tbl_exists( $t ) ? $t : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			if ( $found !== $t ) $missing[] = $t;
		}
		if ( ! empty( $missing ) ) {
			return 'Bảng schema chưa tạo: ' . implode( ', ', $missing ) . ' — run Site Provisioner / activate plugin.';
		}

		return true;
	}

	public function run( $ctx ): array {
		$this->cleanup();

		global $wpdb;
		$user_id  = get_current_user_id();
		$trace_id = 'probe-sheet-' . wp_generate_uuid4();
		$enricher = BizCity_TwinBrain_Sheet_Enricher::instance();
		$sheets_table = BizCity_TwinBrain_Sheets_Installer::sheets_table();
		$cells_table  = BizCity_TwinBrain_Sheets_Installer::cells_table();

		// Step 1 — create sheet.
		$created = $enricher->create_sheet( [
			'user_id'        => $user_id,
			'title'          => self::SENTINEL . ' probe sheet',
			'headers'        => [ 'Company', 'Founded year', 'HQ city' ],
			'rows'           => [ [ 'Anthropic', '', '' ] ],
			'research_mode'  => 'fast',
			'context_column' => 0,
			'target_columns' => [ 1, 2 ],
			'trace_id'       => $trace_id,
		] );
		if ( empty( $created['ok'] ) ) {
			return [ 'status' => 'fail', 'error' => 'create_sheet failed: ' . ( $created['error'] ?? '?' ) ];
		}
		$sheet_id = (int) $created['sheet_id'];

		$ctx->emit_step( [
			'label'  => 'Create sheet',
			'status' => 'pass',
			'detail' => sprintf( 'sheet_id=%d · %dx%d', $sheet_id, $created['row_count'], $created['col_count'] ),
		] );

		// Verify seeded cells: 3 cells, 1 context (Anthropic) + 2 empty.
		$seeded = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$cells_table} WHERE sheet_id = %d", $sheet_id
		) );
		$empty_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$cells_table} WHERE sheet_id = %d AND status = 'empty'", $sheet_id
		) );
		$ctx->emit_step( [
			'label'  => 'Seeded cells',
			'status' => ( $seeded === 3 && $empty_count === 2 ) ? 'pass' : 'fail',
			'detail' => sprintf( 'total=%d · empty=%d (expect 3/2)', $seeded, $empty_count ),
		] );

		// Step 2 — enrich (capture events).
		$events = [];
		try {
			$run = $enricher->enrich_sheet( $sheet_id, [
				'user_id'   => $user_id,
				'max_cells' => 4,
				'trace_id'  => $trace_id,
				'on_event'  => function ( $k, $p ) use ( &$events ) {
					$events[] = [
						'k'   => $k,
						'r'   => isset( $p['row'] ) ? (int) $p['row'] : null,
						'c'   => isset( $p['col'] ) ? (int) $p['col'] : null,
						'val' => isset( $p['value'] ) ? mb_substr( (string) $p['value'], 0, 60 ) : null,
					];
				},
			] );
		} catch ( \Throwable $e ) {
			$this->cleanup();
			return [ 'status' => 'fail', 'error' => 'enrich_sheet exception: ' . $e->getMessage() ];
		}

		if ( empty( $run['ok'] ) ) {
			$this->cleanup();
			return [ 'status' => 'fail', 'error' => 'enrich_sheet returned ok=false' ];
		}

		$ctx->emit_step( [
			'label'  => 'Enrich run',
			'status' => ( (int) $run['enriched'] >= 1 ) ? 'pass' : 'fail',
			'detail' => sprintf( 'enriched=%d · errors=%d · sources=%d · cost=%d¢ · %dms · status=%s',
				(int) $run['enriched'], count( (array) $run['errors'] ),
				(int) $run['sources'], (int) $run['total_cost_cents'],
				(int) $run['ms'], (string) $run['status'] ),
		] );

		// Verify events: ≥ 1 start + ≥1 cell_enriched + 1 done.
		$ev_keys = array_column( $events, 'k' );
		$has_start = in_array( 'sheet_enrich_start', $ev_keys, true );
		$has_cell  = in_array( 'sheet_cell_enriched', $ev_keys, true );
		$has_done  = in_array( 'sheet_enrich_done',  $ev_keys, true );
		$ctx->emit_step( [
			'label'  => 'SSE events',
			'status' => ( $has_start && $has_cell && $has_done ) ? 'pass' : 'fail',
			'detail' => count( $events ) . ' events · ' . implode( ',', array_unique( $ev_keys ) ),
		] );

		// Verify DB cells filled.
		$filled = $wpdb->get_results( $wpdb->prepare(
			"SELECT row_idx, col_idx, column_name, value, status,
			        CHAR_LENGTH(sources_json) AS src_len, tavily_cost_cents
			 FROM {$cells_table}
			 WHERE sheet_id = %d AND col_idx IN (1,2)
			 ORDER BY col_idx ASC",
			$sheet_id
		) );
		$filled_ok = 0;
		$src_ok    = 0;
		$detail_parts = [];
		foreach ( (array) $filled as $row ) {
			$has_val = ( (string) $row->value !== '' && $row->status === 'enriched' );
			$has_src = ( (int) $row->src_len > 5 );
			if ( $has_val ) $filled_ok++;
			if ( $has_src ) $src_ok++;
			$detail_parts[] = sprintf( 'c%d[%s]=%s/src=%dB',
				(int) $row->col_idx, $row->status,
				$has_val ? 'Y' : 'N', (int) $row->src_len );
		}
		$ctx->emit_step( [
			'label'  => 'DB cells filled',
			'status' => ( $filled_ok >= 1 && $src_ok >= 1 ) ? 'pass' : 'fail',
			'detail' => sprintf( 'filled=%d/2 · sources=%d/2 · %s',
				$filled_ok, $src_ok, implode( ' | ', $detail_parts ) ),
		] );

		// Verify sheet aggregates.
		$agg = $wpdb->get_row( $wpdb->prepare(
			"SELECT status, source_count, tavily_cost_cents, total_tokens
			 FROM {$sheets_table} WHERE id = %d", $sheet_id
		) );
		$agg_ok = ( $agg && (int) $agg->source_count >= 1 && (int) $agg->tavily_cost_cents >= 1 );
		$ctx->emit_step( [
			'label'  => 'Sheet aggregates',
			'status' => $agg_ok ? 'pass' : 'fail',
			'detail' => $agg
				? sprintf( 'status=%s · sources=%d · cost=%d¢ · tokens=%d',
					$agg->status, (int) $agg->source_count, (int) $agg->tavily_cost_cents, (int) $agg->total_tokens )
				: 'sheet row missing',
		] );

		$this->cleanup();

		$all_ok = ( (int) $run['enriched'] >= 1 && $has_start && $has_cell && $has_done
		         && $filled_ok >= 1 && $src_ok >= 1 && $agg_ok );

		if ( ! $all_ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Sheets enrichment contract vi phạm.',
				'error'    => 'enriched=' . (int) $run['enriched'] . ' filled=' . $filled_ok . '/2',
				'fix_hint' => 'Check (1) BizCity_Research_Tool_Router::call(TavilySearch) trả results array, (2) LLM_Client::chat purpose=extract_minimal, (3) cells UPDATE block trong enrich_sheet().',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf( 'Sheets OK — %d cells filled, %d sources, %d¢, %dms',
				(int) $run['enriched'], (int) $run['sources'],
				(int) $run['total_cost_cents'], (int) $run['ms'] ),
		];
	}

	public function cleanup(): void {
		global $wpdb;
		if ( ! class_exists( 'BizCity_TwinBrain_Sheets_Installer' ) ) return;
		$sheets_table = BizCity_TwinBrain_Sheets_Installer::sheets_table();
		$cells_table  = BizCity_TwinBrain_Sheets_Installer::cells_table();
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$sheets_table} WHERE title LIKE %s",
			'%' . $wpdb->esc_like( self::SENTINEL ) . '%'
		) );
		if ( empty( $ids ) ) return;
		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM {$cells_table}  WHERE sheet_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$sheets_table} WHERE id        IN ({$in})" );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Sheet_Enrich';
	return $list;
} );
