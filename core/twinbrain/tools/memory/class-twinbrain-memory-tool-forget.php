<?php
/**
 * TwinBrain — Memory Tool · `memory_forget` (Wave 2.8 TBR.MEM-6 Mode 3).
 *
 * Xoá 1 row trong `bizcity_memory_users` mà LLM nhận thấy outdated / sai / user
 * yêu cầu quên. CHỈ delete row thuộc owner hiện tại (user_id hoặc session_id
 * match) — gate cứng trong execute(). Hỗ trợ 2 mode:
 *   - by `memory_id` (chính xác, lấy từ `[mem:U#<id>]` citation đang hiển thị)
 *   - by `match_text` (LIKE %text% trên owner's memories, xoá row đầu tiên)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain\Tools
 * @since      2026-05-24 (Wave 2.8 TBR.MEM-6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	require_once dirname( __DIR__, 3 ) . '/twin-core/includes/interface-twin-tool.php';
}

final class BizCity_TwinBrain_Memory_Tool_Forget implements BizCity_Twin_Tool {

	const TOOL_NAME = 'memory_forget';

	public function name(): string {
		return self::TOOL_NAME;
	}

	public function description(): string {
		return 'Xoá 1 memory đã lưu KHI user yêu cầu "quên X" / "không nhớ Y nữa" / memory đã outdated. '
			. 'Truyền `memory_id` nếu bạn thấy citation `[mem:U#<N>]` cụ thể, hoặc `match_text` để tìm gần đúng. '
			. 'Tool chỉ xoá memory của owner hiện tại — không thể xoá của user khác.';
	}

	public function parameters_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'memory_id'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID memory cụ thể (lấy từ token [mem:U#<id>]).',
				],
				'match_text' => [
					'type'        => 'string',
					'description' => 'Chuỗi LIKE match nếu không biết ID. Tối thiểu 4 ký tự.',
				],
				'reason'     => [
					'type'        => 'string',
					'description' => 'Lý do xoá (logging).',
				],
			],
			'anyOf'      => [
				[ 'required' => [ 'memory_id' ] ],
				[ 'required' => [ 'match_text' ] ],
			],
		];
	}

	public function execute( array $args, array $context ): array {
		$memory_id  = (int)    ( $args['memory_id']  ?? 0 );
		$match_text = trim( (string) ( $args['match_text'] ?? '' ) );
		$reason     = trim( (string) ( $args['reason']     ?? '' ) );

		$user_id    = (int)    ( $context['user_id']    ?? get_current_user_id() );
		$session_id = (string) ( $context['session_id'] ?? '' );

		if ( $user_id <= 0 && $session_id === '' ) {
			return [ 'ok' => false, 'error' => 'no_owner', 'summary' => '', 'result' => null ];
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return [ 'ok' => false, 'error' => 'class_missing', 'summary' => '', 'result' => null ];
		}

		global $wpdb;
		$tbl = BizCity_User_Memory::table();
		$sid = $user_id > 0 ? '' : $session_id;

		$row = null;
		if ( $memory_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, memory_text, memory_type, memory_tier FROM {$tbl} "
				. "WHERE id=%d AND user_id=%d AND session_id=%s LIMIT 1",
				$memory_id,
				$user_id,
				$sid
			), ARRAY_A );
		} elseif ( mb_strlen( $match_text ) >= 4 ) {
			$like = '%' . $wpdb->esc_like( $match_text ) . '%';
			$row  = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, memory_text, memory_type, memory_tier FROM {$tbl} "
				. "WHERE user_id=%d AND session_id=%s AND memory_text LIKE %s "
				. "ORDER BY score DESC, last_seen DESC LIMIT 1",
				$user_id,
				$sid,
				$like
			), ARRAY_A );
		} else {
			return [ 'ok' => false, 'error' => 'no_target', 'summary' => '', 'result' => null ];
		}

		if ( ! $row ) {
			return [
				'ok'      => false,
				'error'   => 'not_found_or_not_owner',
				'summary' => '',
				'result'  => null,
			];
		}

		$deleted = $wpdb->delete( $tbl, [
			'id'         => (int) $row['id'],
			'user_id'    => $user_id,
			'session_id' => $sid,
		], [ '%d', '%d', '%s' ] );

		if ( ! $deleted ) {
			return [ 'ok' => false, 'error' => 'delete_failed', 'summary' => '', 'result' => null ];
		}

		return [
			'ok'      => true,
			'summary' => sprintf( '🗑️ Đã quên memory #%d: %s', (int) $row['id'], mb_substr( (string) $row['memory_text'], 0, 100 ) ),
			'result'  => [
				'op'        => 'delete',
				'memory_id' => (int) $row['id'],
				'type'      => (string) $row['memory_type'],
				'tier'      => (string) $row['memory_tier'],
				'text'      => (string) $row['memory_text'],
				'reason'    => $reason,
			],
		];
	}
}
