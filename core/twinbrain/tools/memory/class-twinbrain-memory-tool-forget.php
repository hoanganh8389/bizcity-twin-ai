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

final class BizCity_TwinBrain_Memory_Tool_Forget implements BizCity_Twin_Tool, BizCity_Tool_Interface {

	const TOOL_NAME = 'memory_forget';

	public function name(): string {
		return self::TOOL_NAME;
	}

	public function id() {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — expose the stable typed Tool identifier.
		return $this->name();
	}

	public function label() {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — expose the typed Tool display label.
		return 'Forget memory';
	}

	public function schema() {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — adapt the existing parameter schema to the public Tool envelope.
		return array( 'name' => $this->id(), 'description' => $this->description(), 'parameters' => $this->parameters_schema() );
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

		// [2026-07-28 Johnny Chu] R-CH-IDMEM — resolve the same owner scope used by every memory reader/writer.
		$scope = class_exists( 'BizCity_Memory_Identity_Scope' )
			? BizCity_Memory_Identity_Scope::resolve( $context )
			: array( 'user_id' => (int) ( $context['user_id'] ?? get_current_user_id() ), 'session_id' => (string) ( $context['session_id'] ?? '' ), 'identity_uuid' => (string) ( $context['identity_uuid'] ?? '' ), 'identity_verified' => false, 'identity_is_stable' => false );
		$user_id       = (int) $scope['user_id'];
		$session_id    = (string) $scope['session_id'];
		$identity_uuid = (string) $scope['identity_uuid'];
		if ( $identity_uuid === '' && $user_id <= 0 ) {
			return [ 'ok' => false, 'error' => 'no_owner', 'summary' => '', 'result' => null ];
		}
		if ( $identity_uuid !== '' && ( empty( $scope['identity_verified'] ) || empty( $scope['identity_is_stable'] ) ) ) {
			return [ 'ok' => false, 'error' => 'identity_unverified', 'summary' => '', 'result' => null ];
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return [ 'ok' => false, 'error' => 'class_missing', 'summary' => '', 'result' => null ];
		}

		global $wpdb;
		$tbl = BizCity_User_Memory::table();
		$sid = $user_id > 0 ? '' : $session_id;
		$owner_where  = array();
		$owner_params = array();
		$owner_scope  = array( 'user_id' => $user_id, 'identity_uuid' => $identity_uuid );
		if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
			if ( ! BizCity_Memory_Identity_Scope::append_read_scope( $owner_where, $owner_params, $owner_scope ) ) {
				return [ 'ok' => false, 'error' => 'no_owner', 'summary' => '', 'result' => null ];
			}
		} else {
			$owner_where[]  = 'identity_uuid = %s AND user_id = %d';
			$owner_params[] = '';
			$owner_params[] = $user_id;
		}
		$owner_where[]  = 'session_id = %s';
		$owner_params[] = $sid;

		$row = null;
		if ( $memory_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, memory_text, memory_type, memory_tier FROM {$tbl} "
				. "WHERE id=%d AND " . implode( ' AND ', $owner_where ) . " LIMIT 1",
				array_merge( array( $memory_id ), $owner_params )
			), ARRAY_A );
		} elseif ( mb_strlen( $match_text ) >= 4 ) {
			$like = '%' . $wpdb->esc_like( $match_text ) . '%';
			$row  = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, memory_text, memory_type, memory_tier FROM {$tbl} "
				. "WHERE " . implode( ' AND ', $owner_where ) . " AND memory_text LIKE %s "
				. "ORDER BY score DESC, last_seen DESC LIMIT 1",
				array_merge( $owner_params, array( $like ) )
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

		$delete_params = array_merge( array( (int) $row['id'] ), $owner_params );
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$tbl} WHERE id = %d AND " . implode( ' AND ', $owner_where ),
			$delete_params
		) );

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

	public function run( array $args, array $context = array() ) {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — preserve one execution owner while exposing the typed result envelope.
		$result = $this->execute( $args, $context );
		return array(
			'success' => ! empty( $result['ok'] ),
			'result'  => $result['result'] ?? null,
			'summary' => $result['summary'] ?? '',
			'error'   => $result['error'] ?? null,
		);
	}
}
