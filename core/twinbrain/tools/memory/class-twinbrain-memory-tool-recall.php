<?php
/**
 * TwinBrain — Memory Tool · `memory_recall` (Wave 2.8 TBR.MEM-6 Mode 3).
 *
 * Read-only — LLM truy vấn lại bank memory mid-turn khi cảm thấy block recall
 * ban đầu (Layer 0.5) thiếu thông tin. Reuse `Memory_Recall::collect()` với
 * prompt tuỳ chỉnh để re-rank theo query mới. KHÔNG tính vào cost guardrail
 * 3 tool/turn (read-only).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain\Tools
 * @since      2026-05-24 (Wave 2.8 TBR.MEM-6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	require_once dirname( __DIR__, 3 ) . '/twin-core/includes/interface-twin-tool.php';
}

final class BizCity_TwinBrain_Memory_Tool_Recall implements BizCity_Twin_Tool {

	const TOOL_NAME   = 'memory_recall';
	const DEFAULT_TOP = 10;
	const MAX_TOP     = 30;

	public function name(): string {
		return self::TOOL_NAME;
	}

	public function description(): string {
		return 'Truy vấn lại long-term memory của user với query cụ thể KHI bạn nghĩ memory block ban đầu thiếu thông tin liên quan. '
			. 'Read-only — không thay đổi DB. Kết quả trả về dạng danh sách `[mem:U#<id>] text`, hãy echo token nếu dùng để trích.';
	}

	public function parameters_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'query' => [
					'type'        => 'string',
					'description' => 'Câu truy vấn keyword để re-rank memory.',
				],
				'top_k' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_TOP,
					'default'     => self::DEFAULT_TOP,
					'description' => 'Số memory tối đa trả về.',
				],
			],
			'required'   => [ 'query' ],
		];
	}

	public function execute( array $args, array $context ): array {
		$query = trim( (string) ( $args['query'] ?? '' ) );
		if ( $query === '' || mb_strlen( $query ) < 2 ) {
			return [ 'ok' => false, 'error' => 'query_empty', 'summary' => '', 'result' => null ];
		}
		$top_k = (int) ( $args['top_k'] ?? self::DEFAULT_TOP );
		$top_k = max( 1, min( self::MAX_TOP, $top_k ) );

		$user_id = (int) ( $context['user_id'] ?? get_current_user_id() );
		if ( $user_id <= 0 ) {
			return [ 'ok' => false, 'error' => 'no_owner', 'summary' => '', 'result' => null ];
		}

		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Recall' ) ) {
			return [ 'ok' => false, 'error' => 'recall_unavailable', 'summary' => '', 'result' => null ];
		}

		try {
			$recall = BizCity_TwinBrain_Memory_Recall::instance()->collect(
				$user_id,
				$query,
				[
					'tier_a_cap' => $top_k,
					'tier_b_cap' => $top_k,
					'tier_c_cap' => min( 5, $top_k ),
					'tier_d_cap' => min( 5, $top_k ),
				]
			);
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'recall_exception: ' . $e->getMessage(), 'summary' => '', 'result' => null ];
		}

		$citations = (array) ( $recall['citations'] ?? [] );
		$citations = array_slice( $citations, 0, $top_k );
		$cids      = array_map( static function ( $c ) {
			return (string) ( $c['token'] ?? '' );
		}, $citations );
		$cids      = array_values( array_filter( $cids ) );

		return [
			'ok'           => true,
			'summary'      => sprintf( '🔍 Recall query="%s" → %d memory', mb_substr( $query, 0, 80 ), count( $citations ) ),
			'result'       => [
				'query'      => $query,
				'count'      => count( $citations ),
				'memories'   => $citations,
				'block'      => (string) ( $recall['block'] ?? '' ),
				'counts'     => (array)  ( $recall['counts'] ?? [] ),
				'latency_ms' => (int)    ( $recall['latency_ms'] ?? 0 ),
			],
			'citation_ids' => $cids,
		];
	}
}
