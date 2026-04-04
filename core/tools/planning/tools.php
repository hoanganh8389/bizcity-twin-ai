<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Tools
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Planning Tools — Callback implementations.
 *
 * Wraps existing Knowledge Intent Provider + BizCity_Knowledge_Fabric
 * and adds build_workflow for multi-step planning.
 *
 * @package  BizCity_Tools\Planning
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ══════════════════════════════════════════════════════════════════════
 * build_workflow — LLM-driven plan decomposition
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_build_workflow( array $slots ): array {
	$goal      = $slots['goal'] ?? '';
	$context   = $slots['context'] ?? '';
	$max_steps = min( 10, max( 1, (int) ( $slots['max_steps'] ?? 5 ) ) );

	if ( empty( $goal ) ) {
		return [
			'success'  => false,
			'complete' => false,
			'message'  => 'Vui lòng mô tả mục tiêu cần đạt.',
			'missing_fields' => [ 'goal' ],
		];
	}

	// Build list of available tools for the planner
	$available = $slots['available_tools'] ?? '';
	if ( empty( $available ) && class_exists( 'BizCity_Intent_Tools' ) ) {
		$all_tools = BizCity_Intent_Tools::instance()->list_all();
		$tool_list = [];
		foreach ( $all_tools as $name => $schema ) {
			$desc = $schema['description'] ?? $name;
			$tool_list[] = "- {$name}: {$desc}";
		}
		$available = implode( "\n", $tool_list );
	}

	$prompt = "Bạn là AI planner. Phân tích mục tiêu sau và tạo kế hoạch thực thi (workflow plan).\n\n"
	        . "MỤC TIÊU: {$goal}\n";

	if ( $context ) {
		$prompt .= "NGỮ CẢNH: {$context}\n";
	}

	$prompt .= "\nCÁC TOOL CÓ SẴN:\n{$available}\n\n"
	         . "Trả về JSON theo format:\n"
	         . '{"plan_name":"...","steps":[{"step":1,"tool":"tool_name","input":{...},"depends_on":[],"description":"..."}],"total_steps":N}' . "\n"
	         . "Tối đa {$max_steps} bước. Nếu cần nhiều hơn, gộp bước liên quan.\n"
	         . "Trả lời CHỈI JSON, không có text khác.";

	if ( ! function_exists( 'bizcity_llm_chat' ) ) {
		return [
			'success' => false,
			'message' => 'LLM provider not available.',
		];
	}

	$result = bizcity_llm_chat(
		[
			[ 'role' => 'system', 'content' => 'You are a task planner AI. Respond in valid JSON only.' ],
			[ 'role' => 'user',   'content' => $prompt ],
		],
		[ 'purpose' => 'planning', 'max_tokens' => 2048, 'temperature' => 0.3 ]
	);

	if ( empty( $result['success'] ) ) {
		return [
			'success' => false,
			'message' => 'Không thể tạo kế hoạch: ' . ( $result['error'] ?? 'unknown' ),
		];
	}

	$parsed = BizCity_Content_Engine::parse_json_response( $result['message'] );

	return [
		'success'  => true,
		'complete' => true,
		'message'  => sprintf( 'Đã tạo kế hoạch "%s" với %d bước.', $parsed['plan_name'] ?? $goal, count( $parsed['steps'] ?? [] ) ),
		'data'     => [
			'type'       => 'workflow_plan',
			'plan_name'  => $parsed['plan_name'] ?? $goal,
			'steps'      => $parsed['steps'] ?? [],
			'total_steps' => (int) ( $parsed['total_steps'] ?? count( $parsed['steps'] ?? [] ) ),
		],
	];
}

/* ══════════════════════════════════════════════════════════════════════
 * knowledge_train — Wraps Knowledge Intent Provider::tool_train()
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_knowledge_train( array $slots ): array {
	// Delegate to existing Knowledge Intent Provider if available
	if ( class_exists( 'BizCity_Knowledge_Intent_Provider' ) ) {
		$provider = new BizCity_Knowledge_Intent_Provider();
		return $provider->tool_train( $slots );
	}

	// Direct implementation via Knowledge Database
	if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
		return [ 'success' => false, 'message' => 'Knowledge system not available.' ];
	}

	$db          = BizCity_Knowledge_Database::instance();
	$source_type = $slots['source_type'] ?? 'text';
	$source_name = $slots['source_name'] ?? 'Untitled';
	$content     = $slots['content'] ?? '';
	$url         = $slots['url'] ?? '';
	$scope       = $slots['scope'] ?? 'user';
	$user_id     = $slots['_meta']['user_id'] ?? get_current_user_id();

	// Need a character_id — get or create user's default
	$char_id = 0;
	if ( method_exists( $db, 'get_or_create_provider_character' ) ) {
		$char_id = $db->get_or_create_provider_character( 'user_' . $user_id, 'User Knowledge' );
	}

	$source_data = [
		'character_id' => $char_id,
		'source_type'  => $source_type,
		'source_name'  => $source_name,
		'content'      => $content,
		'source_url'   => $url,
		'user_id'      => $user_id,
		'scope'        => $scope,
		'status'       => 'pending',
	];

	$source_id = $db->create_knowledge_source( $source_data );

	if ( is_wp_error( $source_id ) ) {
		return [ 'success' => false, 'message' => $source_id->get_error_message() ];
	}

	// Trigger embedding pipeline if available
	do_action( 'bizcity_knowledge_source_ingest', $source_id, $source_data );

	return [
		'success'  => true,
		'complete' => true,
		'message'  => "Đã thêm nguồn kiến thức \"{$source_name}\" (#{$source_id}). Hệ thống đang xử lý embedding.",
		'data'     => [
			'type'      => 'knowledge_trained',
			'source_id' => $source_id,
			'status'    => 'pending',
		],
	];
}

/* ══════════════════════════════════════════════════════════════════════
 * knowledge_search — Wraps Knowledge Intent Provider::tool_search()
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_knowledge_search( array $slots ): array {
	// Delegate to existing provider
	if ( class_exists( 'BizCity_Knowledge_Intent_Provider' ) ) {
		$provider = new BizCity_Knowledge_Intent_Provider();
		return $provider->tool_search( $slots );
	}

	// Direct fallback: search via Knowledge Fabric
	if ( class_exists( 'BizCity_Knowledge_Fabric' ) ) {
		$fabric  = BizCity_Knowledge_Fabric::instance();
		$results = $fabric->search( $slots['query'] ?? '', [
			'scope'       => $slots['scope'] ?? 'all',
			'max_results' => min( 20, (int) ( $slots['max_results'] ?? 5 ) ),
			'user_id'     => $slots['_meta']['user_id'] ?? get_current_user_id(),
		] );

		return [
			'success'  => true,
			'complete' => true,
			'message'  => sprintf( 'Tìm thấy %d kết quả.', count( $results ) ),
			'data'     => [ 'type' => 'knowledge_search', 'results' => $results ],
		];
	}

	return [ 'success' => false, 'message' => 'Knowledge search system not available.' ];
}

/* ══════════════════════════════════════════════════════════════════════
 * knowledge_manage — List / delete / promote knowledge sources
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_knowledge_manage( array $slots ): array {
	// Delegate to existing provider
	if ( class_exists( 'BizCity_Knowledge_Intent_Provider' ) ) {
		$provider = new BizCity_Knowledge_Intent_Provider();
		return $provider->tool_manage( $slots );
	}

	if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
		return [ 'success' => false, 'message' => 'Knowledge system not available.' ];
	}

	$db     = BizCity_Knowledge_Database::instance();
	$action = $slots['action'] ?? 'list';

	switch ( $action ) {
		case 'list':
			$user_id = $slots['_meta']['user_id'] ?? get_current_user_id();
			// Get user's default character
			$char_id = 0;
			if ( method_exists( $db, 'get_or_create_provider_character' ) ) {
				$char_id = $db->get_or_create_provider_character( 'user_' . $user_id, 'User Knowledge' );
			}
			$sources = $db->get_knowledge_sources( $char_id );
			return [
				'success'  => true,
				'complete' => true,
				'message'  => sprintf( 'Có %d nguồn kiến thức.', count( $sources ) ),
				'data'     => [ 'type' => 'knowledge_list', 'sources' => $sources ],
			];

		case 'delete':
			$source_id = (int) ( $slots['source_id'] ?? 0 );
			if ( ! $source_id ) {
				return [ 'success' => false, 'complete' => false, 'message' => 'Cần source_id để xóa.', 'missing_fields' => [ 'source_id' ] ];
			}
			$ok = $db->delete_source_and_chunks( $source_id );
			return [
				'success'  => $ok,
				'complete' => true,
				'message'  => $ok ? "Đã xóa nguồn kiến thức #{$source_id}." : 'Không thể xóa.',
			];

		case 'promote':
			$source_id = (int) ( $slots['source_id'] ?? 0 );
			$new_scope = $slots['scope'] ?? 'user';
			if ( ! $source_id ) {
				return [ 'success' => false, 'complete' => false, 'message' => 'Cần source_id.', 'missing_fields' => [ 'source_id' ] ];
			}
			$ok = $db->update_source( $source_id, [ 'scope' => $new_scope ] );
			return [
				'success'  => (bool) $ok,
				'complete' => true,
				'message'  => "Đã chuyển scope nguồn #{$source_id} thành \"{$new_scope}\".",
			];

		default:
			return [ 'success' => false, 'message' => "Unknown action: {$action}" ];
	}
}
