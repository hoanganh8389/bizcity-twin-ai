<?php
/**
 * TwinBrain Sheets — Cell Enricher (Wave 2.8e TBR.TOOL-S2).
 *
 * Port LangGraph 3-stage pipeline từ Tavily Sheets reference impl
 * (`plugins/bizcoach-pro-research/_library/tavily-sheets-main/backend/graph.py`)
 * sang PHP với gateway-only Tavily call (R-GW-1).
 *
 * Pipeline per-cell:
 *   1. build_query()  — ghép column_name + context cells của cùng row
 *                       (vd: "Founded year of Anthropic")
 *   2. tavily_search() — qua `BizCity_Research_Tool_Router::call('TavilySearch', …)`
 *                        (search_depth `basic` cho fast, `advanced` cho deep)
 *   3. llm_extract()  — call `BizCity_LLM_Client::chat()` purpose=`extract_minimal`
 *                       với prompt "trả về 1 giá trị duy nhất, không bịa".
 *   4. upsert_cell()  — INSERT/UPDATE `bizcity_sheet_cells` (UNIQUE row+col).
 *
 * Callback `$on_event(string $key, array $payload)` cho phép Runtime/Tool gửi
 * SSE event ngay khi mỗi cell xong (xem channel `bizcity_twin_event_stream`).
 *
 * Cost guardrails (per-tier defined in tool layer; class này chỉ enforce
 * per-call cap):
 *   - Max cells/turn:  cap qua arg, default 10.
 *   - Burst:           sequential (max_execution_time risk → caller batch).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain\Sheets
 * @since      Wave 2.8e (2026-05-24)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Sheet_Enricher {

	const DEFAULT_MAX_CELLS_PER_CALL = 10;
	const DEFAULT_MAX_SOURCES_PER_CELL = 4;

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/* =================================================================
	 *  Public — sheet lifecycle
	 * ================================================================ */

	/**
	 * Create a new sheet row + seed cells (context cells written with is_context=1).
	 *
	 * @param array $args { user_id, title, headers:string[], rows:array<array<string|null>>,
	 *                      research_mode?:'fast'|'deep', context_column?:int,
	 *                      target_columns?:int[], trace_id?:string }
	 * @return array { ok, sheet_id, row_count, col_count }
	 */
	public function create_sheet( array $args ): array {
		global $wpdb;

		$user_id        = (int) ( $args['user_id'] ?? get_current_user_id() );
		$title          = trim( (string) ( $args['title'] ?? '' ) );
		$headers        = array_values( (array) ( $args['headers'] ?? [] ) );
		$rows           = array_values( (array) ( $args['rows']    ?? [] ) );
		$research_mode  = in_array( ( $args['research_mode'] ?? 'fast' ), [ 'fast', 'deep' ], true )
		                  ? $args['research_mode'] : 'fast';
		$context_column = isset( $args['context_column'] ) ? (int) $args['context_column'] : 0;
		$trace_id       = (string) ( $args['trace_id'] ?? '' );

		if ( $user_id <= 0 )            return [ 'ok' => false, 'error' => 'user_id required' ];
		if ( empty( $headers ) )         return [ 'ok' => false, 'error' => 'headers required' ];
		if ( empty( $rows ) )            return [ 'ok' => false, 'error' => 'rows required' ];

		if ( $title === '' ) {
			$title = $this->derive_title( $headers, $rows, $context_column );
		}

		$col_count = count( $headers );
		$row_count = count( $rows );

		$sheets_table = BizCity_TwinBrain_Sheets_Installer::sheets_table();
		$cells_table  = BizCity_TwinBrain_Sheets_Installer::cells_table();

		$ok = $wpdb->insert( $sheets_table, [
			'blog_id'        => get_current_blog_id(),
			'user_id'        => $user_id,
			'title'          => mb_substr( $title, 0, 255 ),
			'headers_json'   => wp_json_encode( $headers, JSON_UNESCAPED_UNICODE ),
			'research_mode'  => $research_mode,
			'status'         => 'draft',
			'row_count'      => $row_count,
			'col_count'      => $col_count,
			'cell_count'     => $row_count * $col_count,
			'trace_id'       => $trace_id,
			'metadata'       => wp_json_encode( [
				'context_column' => $context_column,
				'target_columns' => array_values( (array) ( $args['target_columns'] ?? [] ) ),
			], JSON_UNESCAPED_UNICODE ),
		], [ '%d','%d','%s','%s','%s','%s','%d','%d','%d','%s','%s' ] );

		if ( ! $ok ) {
			return [ 'ok' => false, 'error' => 'sheet insert failed: ' . $wpdb->last_error ];
		}
		$sheet_id = (int) $wpdb->insert_id;

		// Seed cells.
		foreach ( $rows as $r_idx => $row ) {
			foreach ( $headers as $c_idx => $col_name ) {
				$val = isset( $row[ $c_idx ] ) ? (string) $row[ $c_idx ] : '';
				$wpdb->insert( $cells_table, [
					'sheet_id'    => $sheet_id,
					'blog_id'     => get_current_blog_id(),
					'user_id'     => $user_id,
					'row_idx'     => $r_idx,
					'col_idx'     => $c_idx,
					'column_name' => mb_substr( $col_name, 0, 191 ),
					'value'       => $val,
					'is_context'  => ( $val !== '' ) ? 1 : 0,
					'status'      => ( $val !== '' ) ? 'enriched' : 'empty',
				], [ '%d','%d','%d','%d','%d','%s','%s','%d','%s' ] );
			}
		}

		return [ 'ok' => true, 'sheet_id' => $sheet_id, 'row_count' => $row_count, 'col_count' => $col_count ];
	}

	/**
	 * Enrich up to N empty cells of a sheet.
	 *
	 * @param int   $sheet_id
	 * @param array $ctx { user_id, max_cells?, on_event?:callable, trace_id?:string }
	 * @return array { ok, sheet_id, enriched, errors, total_cost_cents, total_tokens, sources, ms }
	 */
	public function enrich_sheet( int $sheet_id, array $ctx = [] ): array {
		global $wpdb;
		$t0 = microtime( true );

		$user_id   = (int) ( $ctx['user_id'] ?? get_current_user_id() );
		$max_cells = max( 1, (int) ( $ctx['max_cells'] ?? self::DEFAULT_MAX_CELLS_PER_CALL ) );
		$on_event  = $ctx['on_event'] ?? null;
		$trace_id  = (string) ( $ctx['trace_id'] ?? '' );

		$sheet = $this->get_sheet( $sheet_id, $user_id );
		if ( ! $sheet ) {
			return [ 'ok' => false, 'error' => 'sheet not found / not owner' ];
		}

		$headers = json_decode( (string) $sheet->headers_json, true );
		if ( ! is_array( $headers ) || empty( $headers ) ) {
			return [ 'ok' => false, 'error' => 'headers_json invalid' ];
		}

		$mode = (string) $sheet->research_mode;
		$cells_table = BizCity_TwinBrain_Sheets_Installer::cells_table();

		// Pick next batch of empty cells (deterministic row-major order).
		$empty = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, row_idx, col_idx, column_name FROM {$cells_table}
			 WHERE sheet_id = %d AND status = 'empty'
			 ORDER BY row_idx ASC, col_idx ASC
			 LIMIT %d",
			$sheet_id, $max_cells
		) );

		if ( empty( $empty ) ) {
			return [
				'ok'         => true,
				'sheet_id'   => $sheet_id,
				'enriched'   => 0,
				'errors'     => [],
				'sources'    => 0,
				'ms'         => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'note'       => 'no empty cells',
			];
		}

		$this->update_sheet_status( $sheet_id, 'enriching' );
		$this->emit( $on_event, 'sheet_enrich_start', [
			'trace_id'  => $trace_id,
			'sheet_id'  => $sheet_id,
			'cells'     => count( $empty ),
			'mode'      => $mode,
		] );

		$enriched = 0;
		$errors   = [];
		$cost_cents  = 0;
		$total_tokens = 0;
		$sources_count = 0;

		foreach ( $empty as $cell ) {
			$r_idx = (int) $cell->row_idx;
			$c_idx = (int) $cell->col_idx;
			$col_name = (string) $cell->column_name;

			// Collect context row (other cells of same row).
			$context_cells = $this->get_row_context( $sheet_id, $r_idx, $c_idx );

			$this->emit( $on_event, 'sheet_cell_enriching', [
				'trace_id' => $trace_id, 'sheet_id' => $sheet_id,
				'row' => $r_idx, 'col' => $c_idx, 'column_name' => $col_name,
			] );

			$cell_t0 = microtime( true );
			$res = $this->enrich_cell( [
				'column_name'   => $col_name,
				'context_cells' => $context_cells,
				'research_mode' => $mode,
			] );
			$ms = (int) ( ( microtime( true ) - $cell_t0 ) * 1000 );

			if ( $res['ok'] ) {
				$wpdb->update( $cells_table, [
					'value'             => (string) $res['value'],
					'sources_json'      => wp_json_encode( $res['sources'], JSON_UNESCAPED_UNICODE ),
					'enrichment_trace'  => (string) $res['trace'],
					'query_used'        => (string) $res['query'],
					'tavily_cost_cents' => (int) $res['cost_cents'],
					'llm_tokens'        => (int) $res['tokens'],
					'duration_ms'       => $ms,
					'status'            => 'enriched',
				], [ 'id' => (int) $cell->id ],
				   [ '%s','%s','%s','%s','%d','%d','%d','%s' ],
				   [ '%d' ] );

				$enriched++;
				$cost_cents += (int) $res['cost_cents'];
				$total_tokens += (int) $res['tokens'];
				$sources_count += count( (array) $res['sources'] );

				$this->emit( $on_event, 'sheet_cell_enriched', [
					'trace_id' => $trace_id, 'sheet_id' => $sheet_id,
					'row' => $r_idx, 'col' => $c_idx, 'column_name' => $col_name,
					'value' => $res['value'], 'sources' => $res['sources'],
					'cost_cents' => (int) $res['cost_cents'], 'tokens' => (int) $res['tokens'],
					'ms' => $ms,
					'citation' => sprintf( '[sheet:S#%d/r%dc%d]', $sheet_id, $r_idx, $c_idx ),
				] );
			} else {
				$errors[] = sprintf( 'r%dc%d: %s', $r_idx, $c_idx, $res['error'] );
				$wpdb->update( $cells_table, [
					'status'      => 'error',
					'last_error'  => mb_substr( (string) $res['error'], 0, 500 ),
					'duration_ms' => $ms,
				], [ 'id' => (int) $cell->id ], [ '%s','%s','%d' ], [ '%d' ] );
				$this->emit( $on_event, 'sheet_cell_error', [
					'trace_id' => $trace_id, 'sheet_id' => $sheet_id,
					'row' => $r_idx, 'col' => $c_idx, 'error' => $res['error'], 'ms' => $ms,
				] );
			}
		}

		// Update sheet aggregates.
		$total_ms = (int) ( ( microtime( true ) - $t0 ) * 1000 );
		$still_empty = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$cells_table} WHERE sheet_id = %d AND status = 'empty'",
			$sheet_id
		) );
		$final_status = ( $still_empty > 0 ) ? 'enriching' : ( count( $errors ) > 0 ? 'error' : 'complete' );

		$wpdb->query( $wpdb->prepare(
			"UPDATE " . BizCity_TwinBrain_Sheets_Installer::sheets_table() . "
			 SET status = %s,
			     tavily_cost_cents = tavily_cost_cents + %d,
			     total_tokens = total_tokens + %d,
			     source_count = source_count + %d,
			     last_error = %s,
			     updated_at = NOW()
			 WHERE id = %d",
			$final_status, $cost_cents, $total_tokens, $sources_count,
			empty( $errors ) ? '' : implode( ' | ', array_slice( $errors, 0, 3 ) ),
			$sheet_id
		) );

		$this->emit( $on_event, 'sheet_enrich_done', [
			'trace_id'     => $trace_id, 'sheet_id' => $sheet_id,
			'enriched'     => $enriched,
			'errors'       => count( $errors ),
			'still_empty'  => $still_empty,
			'sources'      => $sources_count,
			'cost_cents'   => $cost_cents,
			'tokens'       => $total_tokens,
			'ms'           => $total_ms,
			'status'       => $final_status,
		] );

		return [
			'ok'               => true,
			'sheet_id'         => $sheet_id,
			'enriched'         => $enriched,
			'errors'           => $errors,
			'still_empty'      => $still_empty,
			'sources'          => $sources_count,
			'total_cost_cents' => $cost_cents,
			'total_tokens'     => $total_tokens,
			'ms'               => $total_ms,
			'status'           => $final_status,
		];
	}

	/* =================================================================
	 *  Public — per-cell enrichment (stateless, no DB write)
	 * ================================================================ */

	/**
	 * Stage 1+2+3 pipeline for ONE cell.
	 *
	 * @param array $args { column_name, context_cells:array<{name,value}>, research_mode }
	 * @return array { ok, value, sources, query, trace, cost_cents, tokens, error? }
	 */
	public function enrich_cell( array $args ): array {
		$col_name      = trim( (string) ( $args['column_name'] ?? '' ) );
		$context_cells = (array)         ( $args['context_cells'] ?? [] );
		$mode          = in_array( ( $args['research_mode'] ?? 'fast' ), [ 'fast', 'deep' ], true )
		                 ? $args['research_mode'] : 'fast';

		if ( $col_name === '' ) {
			return [ 'ok' => false, 'error' => 'column_name empty' ];
		}

		// Stage 1 — query build.
		$query = $this->build_query( $col_name, $context_cells );

		// Stage 2 — Tavily search (gateway-only).
		$search = $this->tavily_search( $query, $mode );
		if ( ! $search['ok'] ) {
			return [
				'ok'    => false,
				'error' => 'tavily: ' . $search['error'],
				'query' => $query,
			];
		}
		$results = (array) $search['results'];
		if ( empty( $results ) ) {
			return [
				'ok'    => false,
				'error' => 'tavily: no results',
				'query' => $query,
			];
		}

		// Stage 3 — LLM extract minimal answer.
		$extract = $this->llm_extract( $col_name, $context_cells, $results );
		if ( ! $extract['ok'] ) {
			return [
				'ok'    => false,
				'error' => 'llm: ' . $extract['error'],
				'query' => $query,
			];
		}

		// Pack sources (cap to N most relevant).
		$sources = [];
		foreach ( array_slice( $results, 0, self::DEFAULT_MAX_SOURCES_PER_CELL ) as $r ) {
			$sources[] = [
				'title'   => (string) ( $r['title']   ?? '' ),
				'url'     => (string) ( $r['url']     ?? '' ),
				'snippet' => mb_substr( (string) ( $r['content'] ?? $r['snippet'] ?? '' ), 0, 220 ),
				'score'   => isset( $r['score'] ) ? (float) $r['score'] : null,
			];
		}

		return [
			'ok'         => true,
			'value'      => (string) $extract['value'],
			'sources'    => $sources,
			'query'      => $query,
			'trace'      => sprintf( 'tavily=%d srcs · llm=%s · mode=%s',
			                  count( $results ), $extract['model'] ?? '?', $mode ),
			'cost_cents' => $mode === 'deep' ? 2 : 1,
			'tokens'     => (int) $extract['tokens'],
		];
	}

	/* =================================================================
	 *  Helpers
	 * ================================================================ */

	private function build_query( string $col_name, array $context_cells ): string {
		$context_str = '';
		foreach ( $context_cells as $cell ) {
			$name = trim( (string) ( $cell['name']  ?? '' ) );
			$val  = trim( (string) ( $cell['value'] ?? '' ) );
			if ( $name === '' || $val === '' ) continue;
			$context_str .= ' · ' . $name . '=' . $val;
		}
		$context_str = trim( $context_str, ' ·' );
		if ( $context_str === '' ) {
			return $col_name;
		}
		return sprintf( '%s of %s', $col_name, $context_str );
	}

	private function tavily_search( string $query, string $mode ): array {
		if ( ! class_exists( 'BizCity_Research_Tool_Router' ) ) {
			return [ 'ok' => false, 'error' => 'router missing', 'results' => [] ];
		}
		$res = BizCity_Research_Tool_Router::call( 'TavilySearch', [
			'query'        => $query,
			'search_depth' => $mode === 'deep' ? 'advanced' : 'basic',
			'max_results'  => $mode === 'deep' ? 6 : 4,
		] );
		if ( empty( $res['success'] ) ) {
			return [ 'ok' => false, 'error' => (string) ( $res['error'] ?? 'unknown' ), 'results' => [] ];
		}
		$raw = $res['results'];
		// Result shape from BizCity_Search_Client::search() — usually
		// `{results: [{title,url,content,score}, ...], answer?, ...}` OR
		// directly array of items. Normalize.
		$items = [];
		if ( is_array( $raw ) ) {
			if ( isset( $raw['results'] ) && is_array( $raw['results'] ) ) {
				$items = $raw['results'];
			} elseif ( isset( $raw[0] ) ) {
				$items = $raw;
			}
		}
		return [ 'ok' => true, 'results' => $items, 'raw' => $raw ];
	}

	private function llm_extract( string $col_name, array $context_cells, array $results ): array {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return [ 'ok' => false, 'error' => 'llm client missing' ];
		}

		// Pack top sources as plain-text bullets.
		$src_bullets = [];
		foreach ( array_slice( $results, 0, 5 ) as $i => $r ) {
			$snip = mb_substr( (string) ( $r['content'] ?? $r['snippet'] ?? '' ), 0, 400 );
			$src_bullets[] = sprintf( "[%d] %s — %s\n%s",
				$i + 1,
				(string) ( $r['title'] ?? '' ),
				(string) ( $r['url']   ?? '' ),
				$snip );
		}
		$sources_block = implode( "\n\n", $src_bullets );

		$ctx_summary = '';
		foreach ( $context_cells as $cell ) {
			$n = trim( (string) ( $cell['name']  ?? '' ) );
			$v = trim( (string) ( $cell['value'] ?? '' ) );
			if ( $n === '' || $v === '' ) continue;
			$ctx_summary .= sprintf( "- %s: %s\n", $n, $v );
		}

		$system = "Bạn là một extractor cô đọng. Đọc các nguồn web và trả lời ĐÚNG 1 giá trị duy nhất cho cột yêu cầu. KHÔNG bịa. Nếu không có thông tin, trả 'N/A'. KHÔNG viết câu, chỉ 1 cụm từ ngắn (≤ 80 ký tự).";
		$user   = "## Cột cần fill\n{$col_name}\n\n";
		if ( $ctx_summary !== '' ) {
			$user .= "## Context cùng row\n{$ctx_summary}\n";
		}
		$user .= "## Nguồn web\n{$sources_block}\n\n## Yêu cầu\nTrả về 1 cụm từ ngắn cho '{$col_name}'. Không giải thích, không nguồn, không markdown.";

		$resp = BizCity_LLM_Client::instance()->chat( [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $user   ],
		], [
			'purpose'     => 'extract_minimal',
			'temperature' => 0.1,
			'max_tokens'  => 100,
		] );

		if ( empty( $resp['success'] ) ) {
			return [ 'ok' => false, 'error' => (string) ( $resp['error'] ?? 'unknown' ) ];
		}

		$value = trim( (string) ( $resp['message'] ?? '' ) );
		// Strip code fences / quotes / trailing punctuation.
		$value = preg_replace( '/^```[a-z]*\n?|\n?```$/i', '', $value );
		$value = trim( $value, " \t\n\r\0\x0B\"'.," );
		$value = mb_substr( $value, 0, 200 );

		$tokens = (int) ( $resp['usage']['total_tokens'] ?? 0 );
		return [ 'ok' => true, 'value' => $value, 'tokens' => $tokens, 'model' => (string) ( $resp['model'] ?? '' ) ];
	}

	private function get_row_context( int $sheet_id, int $row_idx, int $exclude_col_idx ): array {
		global $wpdb;
		$cells_table = BizCity_TwinBrain_Sheets_Installer::cells_table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT col_idx, column_name, value, status FROM {$cells_table}
			 WHERE sheet_id = %d AND row_idx = %d AND col_idx <> %d
			   AND value <> ''
			 ORDER BY col_idx ASC",
			$sheet_id, $row_idx, $exclude_col_idx
		) );
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [ 'name' => (string) $r->column_name, 'value' => (string) $r->value ];
		}
		return $out;
	}

	private function get_sheet( int $sheet_id, int $user_id ) {
		global $wpdb;
		$sheets_table = BizCity_TwinBrain_Sheets_Installer::sheets_table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$sheets_table} WHERE id = %d AND user_id = %d AND blog_id = %d",
			$sheet_id, $user_id, get_current_blog_id()
		) );
	}

	private function update_sheet_status( int $sheet_id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			BizCity_TwinBrain_Sheets_Installer::sheets_table(),
			[ 'status' => $status ],
			[ 'id' => $sheet_id ],
			[ '%s' ], [ '%d' ]
		);
	}

	private function derive_title( array $headers, array $rows, int $context_col ): string {
		$primary = '';
		if ( isset( $rows[0][ $context_col ] ) && $rows[0][ $context_col ] !== '' ) {
			$primary = (string) $rows[0][ $context_col ];
		} elseif ( isset( $rows[0] ) ) {
			foreach ( $rows[0] as $v ) {
				if ( (string) $v !== '' ) { $primary = (string) $v; break; }
			}
		}
		$cols = count( $headers );
		$rws  = count( $rows );
		return sprintf( '%s · %dx%d sheet', $primary !== '' ? $primary : 'Untitled', $rws, $cols );
	}

	private function emit( $cb, string $key, array $payload ): void {
		if ( is_callable( $cb ) ) {
			try {
				call_user_func( $cb, $key, $payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[sheet_enricher][emit] ' . $e->getMessage() );
				}
			}
		}
	}
}
