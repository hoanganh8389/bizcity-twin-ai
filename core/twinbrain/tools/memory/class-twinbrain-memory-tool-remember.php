<?php
/**
 * TwinBrain — Memory Tool · `memory_remember` (Wave 2.8 TBR.MEM-6 Mode 3).
 *
 * MemGPT-style function-call tool: LLM xuất `<tool name="memory_remember">{...}</tool>`
 * trong câu trả lời cuối → Memory_Tool_Dispatcher strip block, upsert vào
 * `bizcity_memory_users` (tier=explicit, score=80), insert citation
 * `[mem:U#<new_id>]` thay vào vị trí tool block để chip xuất hiện trong final answer.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain\Tools
 * @since      2026-05-24 (Wave 2.8 TBR.MEM-6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	require_once dirname( __DIR__, 3 ) . '/twin-core/includes/interface-twin-tool.php';
}

final class BizCity_TwinBrain_Memory_Tool_Remember implements BizCity_Twin_Tool {

	const TOOL_NAME = 'memory_remember';
	const SCORE     = 80;

	public function name(): string {
		return self::TOOL_NAME;
	}

	public function description(): string {
		return 'Lưu 1 fact / preference / identity / goal của user vào long-term memory. '
			. 'Dùng KHI user nói "hãy nhớ X", chia sẻ tên/sở thích/mục tiêu rõ ràng, hoặc bạn suy luận được điều đó hữu ích cho turn sau. '
			. 'KHÔNG dùng cho thông tin tạm thời, đã có trong memory recall, hoặc nhạy cảm (password, OTP, số thẻ).';
	}

	public function parameters_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'text'  => [
					'type'        => 'string',
					'description' => 'Nội dung memory cần lưu, viết ngôi thứ nhất hoặc mô tả ngắn (≤ 300 ký tự).',
				],
				'type'  => [
					'type'        => 'string',
					'enum'        => [ 'identity', 'preference', 'goal', 'pain', 'constraint', 'habit', 'relationship', 'fact', 'request' ],
					'default'     => 'fact',
					'description' => 'Loại memory.',
				],
				'score' => [
					'type'        => 'integer',
					'minimum'     => 50,
					'maximum'     => 100,
					'default'     => 80,
					'description' => 'Mức quan trọng 50-100. Cao hơn = ít bị decay.',
				],
			],
			'required'   => [ 'text' ],
		];
	}

	public function execute( array $args, array $context ): array {
		$text = trim( (string) ( $args['text'] ?? '' ) );
		if ( $text === '' || mb_strlen( $text ) < 4 ) {
			return [ 'ok' => false, 'error' => 'text_empty_or_too_short', 'summary' => '', 'result' => null ];
		}
		if ( mb_strlen( $text ) > 400 ) {
			$text = mb_substr( $text, 0, 400 );
		}

		$type  = (string) ( $args['type']  ?? 'fact' );
		$score = (int)    ( $args['score'] ?? self::SCORE );
		$score = max( 50, min( 100, $score ) );

		$user_id    = (int)    ( $context['user_id']    ?? get_current_user_id() );
		$session_id = (string) ( $context['session_id'] ?? '' );
		$trace_id   = (string) ( $context['trace_id']   ?? '' );

		if ( $user_id <= 0 && $session_id === '' ) {
			return [ 'ok' => false, 'error' => 'no_owner', 'summary' => '', 'result' => null ];
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return [ 'ok' => false, 'error' => 'class_missing', 'summary' => '', 'result' => null ];
		}

		$mem = BizCity_User_Memory::instance();
		$key = 'tool:' . md5( mb_strtolower( $type . '|' . $text ) );

		$res = $mem->upsert_public( [
			'user_id'     => $user_id,
			'session_id'  => $user_id > 0 ? '' : $session_id,
			'memory_tier' => 'explicit',
			'memory_type' => $type,
			'memory_key'  => $key,
			'memory_text' => $text,
			'score'       => $score,
			'metadata'    => wp_json_encode( [
				'source'   => 'twinbrain.tool.memory_remember',
				'trace_id' => $trace_id,
			] ),
		] );

		if ( ! $res ) {
			return [ 'ok' => false, 'error' => 'upsert_failed', 'summary' => '', 'result' => null ];
		}

		$new_id = $this->lookup_id( $user_id, $session_id, $key );

		return [
			'ok'           => true,
			'summary'      => sprintf( '🧠 Đã nhớ (%s, score=%d): %s', $type, $score, mb_substr( $text, 0, 120 ) ),
			'result'       => [
				'op'        => $res,
				'memory_id' => $new_id,
				'type'      => $type,
				'tier'      => 'explicit',
				'score'     => $score,
				'text'      => $text,
				'token'     => $new_id > 0 ? sprintf( '[mem:U#%d]', $new_id ) : '',
			],
			'citation_ids' => $new_id > 0 ? [ 'mem:U#' . $new_id ] : [],
		];
	}

	private function lookup_id( int $user_id, string $session_id, string $key ): int {
		global $wpdb;
		if ( ! class_exists( 'BizCity_User_Memory' ) ) return 0;
		$tbl = BizCity_User_Memory::table();
		$sid = $user_id > 0 ? '' : $session_id;
		$id  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE user_id=%d AND session_id=%s AND memory_key=%s LIMIT 1",
			$user_id,
			$sid,
			$key
		) );
		return $id;
	}
}
