<?php
/**
 * TwinBrain Conversation Router — lightweight pre-runtime routing.
 *
 * Classifies obvious conversational, automation-help, vertical, and notebook
 * requests before the full Brain pipeline. It never executes a workflow.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Conversation_Router {

	// [2026-08-01 Johnny Chu] PHASE-TWIN-VERTICAL-CHAT — specialized skeleton/vertical pre-routing is pending product validation; keep full Notebook Selector search authoritative for now.
	const SPECIALIZED_ROUTING_ENABLED = false;

	const CACHE_TTL = 120;

	const VERTICAL_CATALOG = array(
		'astro'    => array( 'label' => 'Chiêm tinh / lá số', 'keywords' => array( 'tử vi', 'chiêm tinh', 'lá số', 'cung hoàng đạo' ) ),
		'quick'    => array( 'label' => 'Tra cứu nhanh trên web', 'keywords' => array( 'tìm kiếm', 'tra cứu', 'tin mới', 'giá hiện tại' ) ),
		'deep'     => array( 'label' => 'Nghiên cứu sâu', 'keywords' => array( 'nghiên cứu sâu', 'phân tích sâu', 'đào sâu' ) ),
		'social'   => array( 'label' => 'Nghiên cứu mạng xã hội', 'keywords' => array( 'mạng xã hội', 'facebook trend', 'tiktok trend' ) ),
		'company'  => array( 'label' => 'Tra cứu doanh nghiệp', 'keywords' => array( 'công ty', 'doanh nghiệp', 'mã số thuế' ) ),
		'med'      => array( 'label' => 'Sức khỏe / y tế', 'keywords' => array( 'triệu chứng', 'bệnh gì', 'thuốc gì' ) ),
		'scholar'  => array( 'label' => 'Học thuật', 'keywords' => array( 'nghiên cứu khoa học', 'bài paper', 'học thuật' ) ),
		'nutri'    => array( 'label' => 'Dinh dưỡng', 'keywords' => array( 'dinh dưỡng', 'thực đơn', 'calo' ) ),
		'law'      => array( 'label' => 'Pháp luật', 'keywords' => array( 'pháp luật', 'luật mới', 'nghị định' ) ),
		'tax'      => array( 'label' => 'Thuế', 'keywords' => array( 'thuế', 'gtgt', 'tncn' ) ),
		'gov'      => array( 'label' => 'Thủ tục hành chính', 'keywords' => array( 'thủ tục hành chính', 'hồ sơ', 'giấy tờ' ) ),
		'products' => array( 'label' => 'Sản phẩm / dịch vụ nội bộ', 'keywords' => array( 'sản phẩm', 'báo giá', 'tồn kho' ) ),
	);

	/**
	 * @return array<string,mixed>
	 */
	public static function route( string $prompt, int $user_id = 0, array $opts = array() ): array {
		$text = trim( $prompt );
		$base = array(
			'route'                         => 'casual',
			'confidence'                    => 0.0,
			'needs_confirm'                 => false,
			'candidate_notebook_ids'        => array(),
			'candidate_notebook_titles'     => array(),
			'candidate_vertical'            => '',
			'web_mode'                      => 'off',
			'force_notebooks'               => array(),
			'automation_help'               => false,
			'reason'                        => '',
		);

		if ( self::is_casual( $text ) ) {
			$base['confidence'] = 1.0;
			$base['reason']     = 'casual_fast_path';
			return self::finalize( $base, $opts );
		}

		if ( self::is_automation_help( $text ) ) {
			$base['route']           = 'automation_help';
			$base['confidence']      = 0.96;
			$base['automation_help'] = true;
			$base['reason']          = 'automation_help_keywords';
			return self::finalize( $base, $opts );
		}

		// [2026-08-01 Johnny Chu] PHASE-TWIN-VERTICAL-CHAT — do not short-circuit the full Notebook Selector with skeleton/vertical matching yet.
		// Pending: validate cross-channel specialized routing after full-notebook search,
		// evidence ranking, and Goal Loop ownership are stable. Keep these methods below
		// as research-ready code, but leave them disabled by the explicit feature gate.
		if ( self::SPECIALIZED_ROUTING_ENABLED ) {
			$vertical = self::match_vertical( $text );
			if ( $vertical ) {
				$base['route']              = 'vertical';
				$base['confidence']         = $vertical['confidence'];
				$base['candidate_vertical'] = $vertical['id'];
				$base['web_mode']           = $vertical['id'];
				$base['needs_confirm']      = $vertical['confidence'] < 0.9;
				$base['reason']             = 'vertical_keyword_match';
				return self::finalize( $base, $opts );
			}

			$notebooks = self::match_notebooks( $text, $user_id, (int) ( $opts['guru_id'] ?? 0 ) );
			if ( ! empty( $notebooks ) ) {
				$top = $notebooks[0];
				$base['route']                     = 'notebook';
				$base['confidence']                = (float) $top['confidence'];
				$base['candidate_notebook_ids']    = array_column( $notebooks, 'id' );
				$base['candidate_notebook_titles'] = array_column( $notebooks, 'name' );
				$base['needs_confirm']             = count( $notebooks ) > 1 || $top['confidence'] < 0.9;
				$base['reason']                    = 'notebook_skeleton_match';
				if ( ! $base['needs_confirm'] ) {
					$base['force_notebooks'] = array( (int) $top['id'] );
				}
				return self::finalize( $base, $opts );
			}

			$llm_decision = self::classify_with_llm( $text, $user_id, (int) ( $opts['guru_id'] ?? 0 ) );
			if ( is_array( $llm_decision ) ) {
				return self::finalize( $llm_decision, $opts );
			}
		}

		// [2026-08-01 Johnny Chu] PHASE-TWIN-VERTICAL-CHAT — neutral confidence prevents callers from switching to k=1/chat mode while full Notebook search runs.
		$base['confidence'] = 0.5;
		$base['reason']     = 'full_notebook_search_deferred_specialized_routing';
		return self::finalize( $base, $opts );
	}

	/**
	 * Emit a compact route event without persisting the user's full prompt.
	 */
	private static function finalize( array $decision, array $opts ): array {
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) && method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch_v2( 'conversation_route_decided', array(
					'trace_id'                => (string) ( $opts['trace_id'] ?? 'conversation-route-' . wp_generate_uuid4() ),
					'route'                   => (string) ( $decision['route'] ?? 'casual' ),
					'confidence'              => (float) ( $decision['confidence'] ?? 0 ),
					'needs_confirm'           => ! empty( $decision['needs_confirm'] ),
					'candidate_notebook_ids'  => array_values( array_map( 'intval', (array) ( $decision['candidate_notebook_ids'] ?? array() ) ) ),
					'candidate_vertical'      => (string) ( $decision['candidate_vertical'] ?? '' ),
					'web_mode'                => (string) ( $decision['web_mode'] ?? 'off' ),
					'reason'                  => (string) ( $decision['reason'] ?? '' ),
				), array(
					'event_source' => sanitize_key( (string) ( $opts['surface'] ?? 'twinbrain' ) ) === 'twinweb' ? 'webchat' : 'twinbrain',
					'trace_id' => (string) ( $opts['trace_id'] ?? '' ),
					'session_id' => (string) ( $opts['session_id'] ?? '' ),
					'user_id' => (int) ( $opts['user_id'] ?? 0 ),
				) );
			} catch ( \Throwable $e ) {
				// Routing must never block the conversational fallback.
			}
		}
		return $decision;
	}

	private static function is_casual( string $text ): bool {
		$normalized = strtolower( remove_accents( $text ) );
		$normalized = trim( preg_replace( '/\s+/', ' ', $normalized ) );
		if ( $normalized === '' || mb_strlen( $normalized ) > 32 ) {
			return false;
		}
		return in_array( $normalized, array( 'alo', 'hello', 'hi', 'hey', 'xin chao', 'chao', 'ok', 'okay', 'cam on', 'thanks', '👍', '❤️' ), true );
	}

	private static function is_automation_help( string $text ): bool {
		$normalized = strtolower( remove_accents( $text ) );
		$signals = array( 'huong dan dung automation', 'huong dan toi dung cac kich ban', 'kich ban tu dong', 'workflow nao', 'danh sach kich ban', 'cach dung automation' );
		foreach ( $signals as $signal ) {
			if ( strpos( $normalized, $signal ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{id:string,confidence:float}|null
	 */
	private static function match_vertical( string $text ): ?array {
		$normalized = strtolower( remove_accents( $text ) );
		$best = null;
		foreach ( self::VERTICAL_CATALOG as $id => $catalog ) {
			foreach ( $catalog['keywords'] as $keyword ) {
				$needle = strtolower( remove_accents( $keyword ) );
				if ( strpos( $normalized, $needle ) === false ) {
					continue;
				}
				$confidence = mb_strlen( $needle ) >= 10 ? 0.95 : 0.86;
				if ( ! $best || $confidence > $best['confidence'] ) {
					$best = array( 'id' => $id, 'confidence' => $confidence );
				}
			}
		}
		return $best;
	}

	/**
	 * Match only the compact skeleton fields, never notebook passages.
	 *
	 * @return array<int,array{id:int,name:string,confidence:float}>
	 */
	private static function match_notebooks( string $text, int $user_id, int $guru_id ): array {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$rows = self::load_skeleton_rows( $user_id, $guru_id );

		$query_tokens = self::tokens( $text );
		$matches = array();
		foreach ( $rows as $row ) {
			$skeleton = json_decode( (string) ( $row['skeleton_json'] ?? '' ), true );
			if ( ! is_array( $skeleton ) ) {
				continue;
			}
			$haystack = array( (string) ( $row['name'] ?? '' ) );
			$nucleus = is_array( $skeleton['nucleus'] ?? null ) ? $skeleton['nucleus'] : array();
			$haystack[] = (string) ( $nucleus['title'] ?? '' );
			$haystack[] = (string) ( $nucleus['thesis'] ?? '' );
			$haystack = array_merge( $haystack, (array) ( $skeleton['key_points'] ?? array() ), (array) ( $skeleton['entities'] ?? array() ) );
			$hay_tokens = self::tokens( implode( ' ', $haystack ) );
			$overlap = count( array_intersect( $query_tokens, $hay_tokens ) );
			if ( $overlap < 2 ) {
				continue;
			}
			$confidence = min( 0.96, 0.55 + ( $overlap * 0.08 ) );
			$matches[] = array( 'id' => (int) $row['id'], 'name' => (string) $row['name'], 'confidence' => $confidence );
		}
		usort( $matches, static function ( $left, $right ) {
			return $right['confidence'] <=> $left['confidence'];
		} );
		return array_slice( $matches, 0, 3 );
	}

	/**
	 * Run one small JSON-only classifier for prompts that heuristic routing
	 * cannot place. Gateway failure returns null and preserves Brain fallback.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function classify_with_llm( string $prompt, int $user_id, int $guru_id ): ?array {
		if ( ! apply_filters( 'bizcity_twinbrain_conversation_router_llm_enabled', true, $prompt, $user_id ) ) {
			return null;
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return null;
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return null;
		}

		$notebook_lines = array();
		foreach ( array_slice( self::load_skeleton_rows( $user_id, $guru_id ), 0, 30 ) as $row ) {
			$skeleton = json_decode( (string) ( $row['skeleton_json'] ?? '' ), true );
			$nucleus  = is_array( $skeleton['nucleus'] ?? null ) ? $skeleton['nucleus'] : array();
			$thesis   = sanitize_text_field( (string) ( $nucleus['thesis'] ?? '' ) );
			if ( $thesis === '' ) {
				continue;
			}
			$notebook_lines[] = sprintf( '#%d %s — %s', (int) $row['id'], sanitize_text_field( (string) $row['name'] ), $thesis );
		}

		$vertical_lines = array();
		foreach ( self::VERTICAL_CATALOG as $id => $catalog ) {
			$vertical_lines[] = $id . ': ' . $catalog['label'] . ' (' . implode( ', ', $catalog['keywords'] ) . ')';
		}
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'Bạn là bộ phân loại ý định cho TwinBrain. Chỉ trả về JSON hợp lệ, không markdown. route chỉ được là casual, notebook, vertical hoặc automation_help. Chỉ chọn notebook_id trong danh sách được cấp. Không thực thi workflow. needs_confirm=true nếu không chắc chắn hoặc route chuyên biệt cần user xác nhận. Schema: {"route":"...","confidence":0.0,"needs_confirm":true,"notebook_ids":[],"vertical":"","reason":"..."}.',
			),
			array(
				'role'    => 'user',
				'content' => "Câu hỏi: {$prompt}\n\nVERTICAL:\n" . implode( "\n", $vertical_lines ) . "\n\nNOTEBOOK SKELETON ĐƯỢC PHÉP DÙNG:\n" . ( empty( $notebook_lines ) ? '(không có)' : implode( "\n", $notebook_lines ) ),
			),
		);
		$response = $client->chat( $messages, array(
			'purpose'     => 'router',
			'temperature' => 0.1,
			'max_tokens'  => 350,
			'timeout'     => 15,
			'no_fallback' => true,
		) );
		if ( empty( $response['success'] ) || empty( $response['message'] ) ) {
			return null;
		}

		$message = trim( (string) $response['message'] );
		$message = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $message );
		$start = strpos( $message, '{' );
		$end   = strrpos( $message, '}' );
		if ( $start === false || $end === false || $end <= $start ) {
			return null;
		}
		$decoded = json_decode( substr( $message, $start, $end - $start + 1 ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$route = sanitize_key( (string) ( $decoded['route'] ?? '' ) );
		if ( ! in_array( $route, array( 'casual', 'notebook', 'vertical', 'automation_help' ), true ) ) {
			return null;
		}
		$confidence = max( 0.0, min( 1.0, (float) ( $decoded['confidence'] ?? 0.5 ) ) );
		$decision = array(
			'route'                     => $route,
			'confidence'                => $confidence,
			'needs_confirm'             => ! empty( $decoded['needs_confirm'] ) || $confidence < 0.9,
			'candidate_notebook_ids'    => array(),
			'candidate_notebook_titles' => array(),
			'candidate_vertical'        => '',
			'web_mode'                  => 'off',
			'force_notebooks'           => array(),
			'automation_help'           => $route === 'automation_help',
			'reason'                   => 'llm_router:' . sanitize_key( (string) ( $decoded['reason'] ?? 'classified' ) ),
		);

		if ( $route === 'vertical' ) {
			$vertical = sanitize_key( (string) ( $decoded['vertical'] ?? '' ) );
			if ( ! isset( self::VERTICAL_CATALOG[ $vertical ] ) ) {
				return null;
			}
			$decision['candidate_vertical'] = $vertical;
			$decision['web_mode']           = $vertical;
		}
		if ( $route === 'notebook' ) {
			$allowed = array();
			foreach ( self::load_skeleton_rows( $user_id, $guru_id ) as $row ) {
				$allowed[ (int) $row['id'] ] = (string) $row['name'];
			}
			foreach ( (array) ( $decoded['notebook_ids'] ?? array() ) as $notebook_id ) {
				$notebook_id = (int) $notebook_id;
				if ( isset( $allowed[ $notebook_id ] ) ) {
					$decision['candidate_notebook_ids'][]    = $notebook_id;
					$decision['candidate_notebook_titles'][] = $allowed[ $notebook_id ];
				}
			}
			if ( empty( $decision['candidate_notebook_ids'] ) ) {
				return null;
			}
			if ( ! $decision['needs_confirm'] ) {
				$decision['force_notebooks'] = array( $decision['candidate_notebook_ids'][0] );
			}
		}
		return $decision;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function load_skeleton_rows( int $user_id, int $guru_id ): array {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$cache_key = 'bizcity_twinbrain_skeleton_catalog_' . get_current_blog_id() . '_' . $user_id . '_' . $guru_id;
		$rows = get_transient( $cache_key );
		if ( is_array( $rows ) ) {
			return $rows;
		}
		global $wpdb;
		$table = BizCity_KG_Database::instance()->tbl_notebooks();
		$where = "((owner_id = %d AND owner_id <> 0) OR (owner_id = 0 AND notebook_scope IN ('business_kb','guru_kb')))";
		$params = array( $user_id );
		if ( $guru_id > 0 ) {
			$where .= ' AND (character_id = %d OR character_id IS NULL)';
			$params[] = $guru_id;
		}
		$sql   = "SELECT id, name, skeleton_json FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT 100";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
		$rows  = is_array( $rows ) ? $rows : array();
		set_transient( $cache_key, $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * @return array<int,string>
	 */
	private static function tokens( string $text ): array {
		$text = strtolower( remove_accents( $text ) );
		preg_match_all( '/[a-z0-9]{3,}/', $text, $matches );
		$stop = array( 'nhung', 'trong', 'cho', 'cua', 'mot', 'the', 'cac', 'nay', 'vay', 'voi', 'from', 'the' );
		return array_values( array_diff( array_unique( $matches[0] ?? array() ), $stop ) );
	}
}
