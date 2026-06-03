<?php
/**
 * TwinBrain Sheets — Tool · `sheet_enrich` (Wave 2.8e TBR.TOOL-S3).
 *
 * Function-call tool (R-SKILL-N1, text-based pattern giống MEM-6) cho phép
 * LLM emit:
 *   <tool name="sheet_enrich">{
 *     "title": "Top YC W26 startups",
 *     "headers": ["Company","Founded","HQ","Sector"],
 *     "rows": [ ["Anthropic","","",""], ["OpenAI","","",""] ],
 *     "research_mode": "fast",
 *     "max_cells": 10
 *   }</tool>
 *
 * → `BizCity_TwinBrain_Sheet_Enricher::create_sheet()` + `enrich_sheet()` →
 * trả `{sheet_id, enriched, citation_ids:[ '[sheet:S#42]' ]}` cho dispatcher
 * insert citation chip vào câu trả lời cuối.
 *
 * Tool_class: `producer` (output là sheet artifact owner-scoped).
 *
 * Cost guardrail per-tool-call:
 *   - max_cells/turn: 10 (hard cap, args.max_cells bị clamp).
 *   - require Tavily ready (`bizcity_tavily_is_ready()`).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain\Sheets
 * @since      Wave 2.8e (2026-05-24)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	require_once dirname( __DIR__, 3 ) . '/twin-core/includes/interface-twin-tool.php';
}

final class BizCity_TwinBrain_Sheet_Tool_Enrich implements BizCity_Twin_Tool {

	const TOOL_NAME = 'sheet_enrich';
	const HARD_CAP_CELLS_PER_CALL = 10;
	const HARD_CAP_ROWS = 20;
	const HARD_CAP_COLS = 8;

	public function name(): string { return self::TOOL_NAME; }

	public function description(): string {
		return 'Tạo / mở rộng 1 spreadsheet artifact tự fill bằng web search (Tavily) + LLM extract. '
		     . 'Dùng KHI user yêu cầu "lập bảng so sánh", "so sánh N công ty/sản phẩm", "enrich danh sách", '
		     . '"điền các thông tin còn thiếu cho danh sách". '
		     . 'Truyền `headers` (cột) + `rows` (mỗi row là array string, "" = ô trống cần fill). '
		     . 'Tool sẽ tự fill các ô trống theo thứ tự row-major, max 10 cells/turn. '
		     . 'Return: sheet_id + citation token `[sheet:S#<id>]` để user click mở drawer xem chi tiết.';
	}

	public function parameters_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'title' => [
					'type'        => 'string',
					'description' => 'Tên sheet ngắn gọn (auto-derive nếu rỗng).',
				],
				'headers' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Tên các cột (≤ 8 cột). Cột đầu thường là entity name (context column).',
				],
				'rows' => [
					'type'        => 'array',
					'description' => 'Array 2D: mỗi row là array string cùng số phần tử với headers. Ô "" sẽ được auto-fill. Cap ≤ 20 row.',
					'items'       => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
				],
				'research_mode' => [
					'type'        => 'string',
					'enum'        => [ 'fast', 'deep' ],
					'default'     => 'fast',
					'description' => 'fast = Tavily basic depth + 4 results · deep = advanced + 6 results (cost cao gấp 2).',
				],
				'max_cells' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 10,
					'description' => 'Số cell tối đa enrich trong call này (hard cap 10).',
				],
				'sheet_id' => [
					'type'        => 'integer',
					'description' => 'Optional — tiếp tục enrich sheet đã có (cùng owner). Nếu set, các field headers/rows bị ignore.',
				],
			],
			'required' => [],
		];
	}

	public function execute( array $args, array $context ): array {
		$user_id    = (int)    ( $context['user_id']  ?? get_current_user_id() );
		$session_id = (string) ( $context['session_id'] ?? '' );
		$trace_id   = (string) ( $context['trace_id'] ?? '' );

		if ( $user_id <= 0 ) {
			return [ 'ok' => false, 'error' => 'no_owner', 'summary' => '', 'result' => null ];
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Sheet_Enricher' )
		  || ! class_exists( 'BizCity_TwinBrain_Sheets_Installer' ) ) {
			return [ 'ok' => false, 'error' => 'enricher_missing', 'summary' => '', 'result' => null ];
		}
		if ( function_exists( 'bizcity_tavily_is_ready' ) && ! bizcity_tavily_is_ready() ) {
			return [ 'ok' => false, 'error' => 'tavily_not_configured', 'summary' => 'Cần cấu hình Tavily API key trong WP Admin → Twin → Settings', 'result' => null ];
		}

		$mode      = in_array( ( $args['research_mode'] ?? 'fast' ), [ 'fast', 'deep' ], true ) ? $args['research_mode'] : 'fast';
		$max_cells = max( 1, min( self::HARD_CAP_CELLS_PER_CALL, (int) ( $args['max_cells'] ?? self::HARD_CAP_CELLS_PER_CALL ) ) );

		$sheet_id = isset( $args['sheet_id'] ) ? (int) $args['sheet_id'] : 0;

		$enricher = BizCity_TwinBrain_Sheet_Enricher::instance();

		// Branch A — continue existing sheet.
		if ( $sheet_id > 0 ) {
			$run = $enricher->enrich_sheet( $sheet_id, [
				'user_id'   => $user_id,
				'max_cells' => $max_cells,
				'trace_id'  => $trace_id,
			] );
			return $this->format_response( $run, $sheet_id, /*created=*/false );
		}

		// Branch B — create + first enrichment batch.
		$headers = array_values( (array) ( $args['headers'] ?? [] ) );
		$rows    = array_values( (array) ( $args['rows']    ?? [] ) );

		if ( empty( $headers ) || count( $headers ) > self::HARD_CAP_COLS ) {
			return [ 'ok' => false, 'error' => 'headers must be 1..' . self::HARD_CAP_COLS . ' items', 'summary' => '', 'result' => null ];
		}
		if ( empty( $rows ) || count( $rows ) > self::HARD_CAP_ROWS ) {
			return [ 'ok' => false, 'error' => 'rows must be 1..' . self::HARD_CAP_ROWS . ' items', 'summary' => '', 'result' => null ];
		}

		// Normalize rows: pad/truncate to header count, force strings.
		$col_count = count( $headers );
		foreach ( $rows as &$r ) {
			$r = array_values( (array) $r );
			while ( count( $r ) < $col_count ) $r[] = '';
			$r = array_slice( $r, 0, $col_count );
			foreach ( $r as &$cell ) { $cell = (string) $cell; }
			unset( $cell );
		}
		unset( $r );

		$created = $enricher->create_sheet( [
			'user_id'        => $user_id,
			'title'          => (string) ( $args['title'] ?? '' ),
			'headers'        => $headers,
			'rows'           => $rows,
			'research_mode'  => $mode,
			'context_column' => isset( $args['context_column'] ) ? (int) $args['context_column'] : 0,
			'target_columns' => array_values( (array) ( $args['target_columns'] ?? [] ) ),
			'trace_id'       => $trace_id,
		] );
		if ( empty( $created['ok'] ) ) {
			return [ 'ok' => false, 'error' => (string) ( $created['error'] ?? 'create failed' ), 'summary' => '', 'result' => null ];
		}
		$sheet_id = (int) $created['sheet_id'];

		$run = $enricher->enrich_sheet( $sheet_id, [
			'user_id'   => $user_id,
			'max_cells' => $max_cells,
			'trace_id'  => $trace_id,
		] );
		return $this->format_response( $run, $sheet_id, /*created=*/true );
	}

	private function format_response( array $run, int $sheet_id, bool $created ): array {
		if ( empty( $run['ok'] ) ) {
			return [
				'ok'      => false,
				'error'   => (string) ( $run['error'] ?? 'enrich failed' ),
				'summary' => '',
				'result'  => [ 'sheet_id' => $sheet_id ],
			];
		}
		$enriched     = (int) ( $run['enriched']         ?? 0 );
		$still_empty  = (int) ( $run['still_empty']      ?? 0 );
		$sources      = (int) ( $run['sources']          ?? 0 );
		$cost_cents   = (int) ( $run['total_cost_cents'] ?? 0 );
		$status       = (string) ( $run['status']         ?? 'enriching' );

		$token = sprintf( '[sheet:S#%d]', $sheet_id );
		$verb  = $created ? 'Tạo sheet' : 'Enrich thêm';

		$summary = sprintf(
			'%s #%d · %d cell mới · %d sources · còn %d cell trống · status=%s',
			$verb, $sheet_id, $enriched, $sources, $still_empty, $status
		);

		return [
			'ok'           => true,
			'summary'      => $summary,
			'result'       => [
				'sheet_id'      => $sheet_id,
				'created'       => $created,
				'enriched'      => $enriched,
				'still_empty'   => $still_empty,
				'sources'       => $sources,
				'cost_cents'    => $cost_cents,
				'status'        => $status,
				'token'         => $token,
				'ms'            => (int) ( $run['ms'] ?? 0 ),
			],
			'citation_ids' => [ $token ],
		];
	}
}
