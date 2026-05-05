<?php
/**
 * Bizcity Twin AI — TwinChat Studio Input Builder
 *
 * Phase 0.7 Wave C — Port of BCN_Studio_Input_Builder, scoped to TwinChat
 * notebooks. Reads sources from KG-Hub central layer + pinned memory notes,
 * extracts a normalized Skeleton JSON (single-shot LLM), and enriches with a
 * Graph-RAG subgraph (top entities + relations + passages from KG_Retriever).
 *
 * The skeleton is the SINGLE INTERFACE between TwinChat Studio and any
 * registered tool callback (bzdoc bridge, BCN content tools, etc.). All tools
 * receive the same shape so adding a new content-creator plugin is a one-line
 * registry add().
 *
 * Cache layer: reuses `bizcity_webchat_project_skeletons` table that already
 * exists from companion-notebook (DB-managed via BCN_Schema_Extend). Cache key
 * = "tc_{notebook_id}" — separate from BCN project ids.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Studio
 * @since 0.7.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Studio_Input_Builder {

	const SKELETON_VERSION = '1.0';
	const PROJECT_PREFIX   = 'tc_';

	/** Limit raw text fed to LLM extractor. */
	const MAX_RAW_CHARS = 50000;

	/** Build (or retrieve cached) skeleton for a TwinChat notebook. */
	public static function build( $notebook_id, array $options = [] ) {
		$notebook_id = (int) $notebook_id;
		$force       = ! empty( $options['force'] );
		$source_ids  = isset( $options['source_ids'] ) && is_array( $options['source_ids'] )
			? array_values( array_filter( array_map( 'intval', $options['source_ids'] ) ) )
			: [];

		$raw = self::gather_raw( $notebook_id, $source_ids );
		if ( empty( $raw['text'] ) ) {
			return self::empty_skeleton( $notebook_id );
		}

		// Cache (only valid when no source filter — full-notebook skeleton).
		if ( ! $force && empty( $source_ids ) ) {
			$cached = self::get_cached( $notebook_id );
			if ( $cached && self::is_cache_valid( $cached, $raw ) ) {
				return $cached;
			}
		}

		$skeleton = self::extract_skeleton( $notebook_id, $raw );
		if ( is_wp_error( $skeleton ) ) {
			$skeleton = self::fallback_skeleton( $notebook_id, $raw );
		}

		// Always preserve raw text for tools (bzdoc bridge fallback path).
		$skeleton['_raw_text']  = $raw['text'];
		$skeleton['project_id'] = self::project_id( $notebook_id );

		// PHASE 0.7 — Graph-RAG enrichment. Pull top entities/relations/passages
		// from KG so tool callbacks can render graph-grounded artifacts.
		$skeleton['_kg_subgraph'] = self::enrich_with_graph( $notebook_id, $skeleton );

		if ( empty( $source_ids ) ) {
			self::save_cached( $notebook_id, $skeleton );
		}

		return $skeleton;
	}

	/* ───────────────────────── Data Gathering ────────────────────────── */

	/**
	 * Gather raw notes + sources for a TwinChat notebook.
	 *
	 * Sources resolved via KG-Hub central layer (`bizcity_kg_sources`); falls
	 * back to legacy `bizcity_webchat_sources` when KG-Hub is empty or absent.
	 * Notes from `bizcity_memory_notes` filtered to TwinChat project prefix and
	 * note types relevant for docgen (pinned chat, manual, research_auto).
	 */
	private static function gather_raw( $notebook_id, array $source_ids = [] ) {
		global $wpdb;

		$project_id = self::project_id( $notebook_id );

		// ── Notes (memory) ──
		$notes_text_parts = [];
		$note_count       = 0;
		$notes_table      = $wpdb->prefix . 'bizcity_memory_notes';
		$notes_exists     = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $notes_table ) ) === $notes_table;
		if ( $notes_exists ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, content, note_type, is_starred, created_at
				 FROM {$notes_table}
				 WHERE project_id = %s
				   AND note_type IN ('chat_pinned','manual','research_auto')
				 ORDER BY is_starred DESC, created_at DESC
				 LIMIT 200",
				$project_id
			) );
			foreach ( $rows ?: [] as $n ) {
				$prefix = ! empty( $n->is_starred ) ? '[📌] ' : '';
				$head   = $n->title ? "[{$n->title}] " : '';
				$notes_text_parts[] = $prefix . $head . (string) $n->content;
				$note_count++;
			}
		}

		// ── Sources ──
		$sources_text_parts = [];
		$source_count       = 0;

		// Try KG-Hub central layer first.
		$kg_rows = self::query_kg_sources( $notebook_id, $source_ids );
		if ( is_array( $kg_rows ) && ! empty( $kg_rows ) ) {
			foreach ( $kg_rows as $r ) {
				$title   = (string) ( $r['title'] ?? '' );
				$content = self::load_source_text( (int) ( $r['kg_source_id'] ?? 0 ), (int) ( $r['origin_id'] ?? 0 ) );
				if ( $content === '' ) continue;
				$sources_text_parts[] = "<<SOURCE id=\"{$r['kg_source_id']}\" title=\"" . self::sanitize_attr( $title ) . "\">>\n" . $content;
				$source_count++;
			}
		}

		// Fallback: legacy webchat_sources direct read.
		if ( empty( $sources_text_parts ) ) {
			$wcs = $wpdb->prefix . 'bizcity_webchat_sources';
			$wcs_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wcs ) ) === $wcs;
			if ( $wcs_exists ) {
				$where  = "WHERE project_id = %s AND status <> 'deleted'";
				$params = [ (string) $notebook_id ];
				if ( ! empty( $source_ids ) ) {
					$ph     = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
					$where .= " AND id IN ({$ph})";
					$params = array_merge( $params, $source_ids );
				}
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, title, content_text FROM {$wcs} {$where} ORDER BY created_at DESC LIMIT 50",
					$params
				) );
				foreach ( $rows ?: [] as $r ) {
					if ( ! $r->content_text ) continue;
					$sources_text_parts[] = "<<SOURCE id=\"{$r->id}\" title=\"" . self::sanitize_attr( $r->title ) . "\">>\n" . (string) $r->content_text;
					$source_count++;
				}
			}
		}

		$parts = [];
		if ( $notes_text_parts ) {
			$parts[] = "=== GHI CHÚ ĐÃ GHIM (notebook #{$notebook_id}) ===\n" . implode( "\n\n", $notes_text_parts );
		}
		if ( $sources_text_parts ) {
			$parts[] = "=== NGUỒN TÀI LIỆU ===\n" . implode( "\n\n---\n\n", $sources_text_parts );
		}

		return [
			'text'         => implode( "\n\n", $parts ),
			'note_count'   => $note_count,
			'source_count' => $source_count,
		];
	}

	/** Read kg_sources rows for this notebook via KG facade. */
	private static function query_kg_sources( $notebook_id, array $source_ids = [] ) {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return null;
		global $wpdb;

		$tbl = BizCity_KG_Database::instance()->tbl_sources();
		$where  = "WHERE origin_plugin = %s AND scope_type = %s AND scope_id = %s AND status <> 'deleted'";
		$params = [ 'twinchat', 'notebook', (string) $notebook_id ];

		if ( ! empty( $source_ids ) ) {
			// source_ids in TwinChat FE refer to the legacy bizcity_webchat_sources.id,
			// which the KG dual-write copies into kg_sources.origin_id.
			$ph     = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
			$where .= " AND origin_id IN ({$ph})";
			$params = array_merge( $params, $source_ids );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id AS kg_source_id, origin_id, title FROM {$tbl} {$where} ORDER BY created_at DESC LIMIT 50",
			$params
		), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Concatenate kg_source_chunks content for a kg_source_id; falls back to
	 * legacy `webchat_sources.content_text` via origin_id.
	 */
	private static function load_source_text( $kg_source_id, $origin_id ) {
		global $wpdb;

		if ( $kg_source_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
			$chunks_tbl = BizCity_KG_Database::instance()->tbl_source_chunks();
			$rows = $wpdb->get_col( $wpdb->prepare(
				"SELECT content FROM {$chunks_tbl} WHERE source_id = %d ORDER BY chunk_index ASC LIMIT 200",
				$kg_source_id
			) );
			if ( $rows ) {
				return implode( "\n\n", array_filter( array_map( 'strval', $rows ) ) );
			}
		}

		if ( $origin_id > 0 ) {
			$wcs = $wpdb->prefix . 'bizcity_webchat_sources';
			$txt = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT content_text FROM {$wcs} WHERE id = %d LIMIT 1",
				$origin_id
			) );
			return $txt;
		}

		return '';
	}

	/* ─────────────────── LLM Skeleton Extraction ─────────────────────── */

	private static function extract_skeleton( $notebook_id, array $raw ) {
		// Prefer Smart Gateway client (PHASE-0-RULE-BRAIN-UNIFICATION C2).
		$messages = [
			[ 'role' => 'system', 'content' => self::skeleton_prompt() ],
			[ 'role' => 'user',   'content' => mb_substr( $raw['text'], 0, self::MAX_RAW_CHARS ) ],
		];

		$reply_text = '';
		if ( class_exists( 'BizCity_LLM_Client' ) && method_exists( 'BizCity_LLM_Client', 'instance' ) ) {
			// chat() là instance method (xài $this) — phải gọi qua singleton.
			// Signature: chat( array $messages, array $options = [] )
			$resp = BizCity_LLM_Client::instance()->chat( $messages, [ 'purpose' => 'json', 'response_format' => 'json' ] );
			if ( is_wp_error( $resp ) ) return $resp;
			if ( is_array( $resp ) && empty( $resp['success'] ) ) {
				return new WP_Error( 'llm_failed', (string) ( $resp['error'] ?? $resp['message'] ?? 'LLM error' ) );
			}
			$reply_text = is_array( $resp )
				? (string) ( $resp['message'] ?? $resp['content'] ?? '' )
				: (string) $resp;
		} elseif ( function_exists( 'bizcity_openrouter_chat' ) ) {
			$resp = bizcity_openrouter_chat( $messages );
			if ( is_wp_error( $resp ) ) return $resp;
			$reply_text = (string) ( $resp['message'] ?? '' );
		} else {
			return new WP_Error( 'no_llm', 'No LLM client available for skeleton extraction.' );
		}

		$json = self::parse_json_response( $reply_text );
		if ( ! $json ) {
			return new WP_Error( 'bad_json', 'Skeleton LLM response was not valid JSON.' );
		}

		return [
			'version' => self::SKELETON_VERSION,
			'nucleus' => [
				'title'  => (string) ( $json['nucleus']['title']  ?? '' ),
				'thesis' => (string) ( $json['nucleus']['thesis'] ?? '' ),
				'domain' => (string) ( $json['nucleus']['domain'] ?? 'general' ),
			],
			'skeleton'       => isset( $json['skeleton'] )       && is_array( $json['skeleton'] )       ? $json['skeleton']       : [],
			'key_points'     => isset( $json['key_points'] )     && is_array( $json['key_points'] )     ? $json['key_points']     : [],
			'entities'       => isset( $json['entities'] )       && is_array( $json['entities'] )       ? $json['entities']       : [],
			'timeline'       => isset( $json['timeline'] )       && is_array( $json['timeline'] )       ? $json['timeline']       : [],
			'decisions'      => isset( $json['decisions'] )      && is_array( $json['decisions'] )      ? $json['decisions']      : [],
			'open_questions' => isset( $json['open_questions'] ) && is_array( $json['open_questions'] ) ? $json['open_questions'] : [],
			'meta' => [
				'notebook_id'  => (int) $notebook_id,
				'note_count'   => (int) $raw['note_count'],
				'source_count' => (int) $raw['source_count'],
				'timestamp'    => current_time( 'mysql' ),
			],
		];
	}

	private static function skeleton_prompt() {
		return <<<'PROMPT'
Bạn là chuyên gia phân tích tài liệu. Phân tích toàn bộ nội dung và trích xuất "xương sống" (skeleton) chuẩn.

NHIỆM VỤ:
1. Xác định HẠT NHÂN XUYÊN SUỐT (nucleus) — chủ đề chính, luận điểm chính, mạch chuyện chính
2. Từ hạt nhân đó, lập CẤU TRÚC PHÂN CẤP (skeleton) — các nhánh mở rộng quan hệ cha-con, giống mindmap
3. Trích xuất: key_points, entities, timeline, decisions, open_questions

Trả về JSON thuần (KHÔNG markdown, KHÔNG ```json) theo format:
{
  "nucleus": {
    "title": "Chủ đề/luận điểm chính",
    "thesis": "Mô tả hạt nhân xuyên suốt trong 1-2 câu",
    "domain": "technology|education|business|narrative|research|general"
  },
  "skeleton": [
    { "label": "Nhánh chính", "summary": "Tóm tắt 1 câu", "importance": 90,
      "children": [ { "label": "Nhánh con", "summary": "...", "importance": 70, "children": [] } ] }
  ],
  "key_points": ["Điểm chính 1", "Điểm chính 2"],
  "entities": [
    {"name": "Tên", "type": "person|org|concept|system|place", "role": "Vai trò trong tài liệu"}
  ],
  "timeline": [
    {"order": 1, "label": "Sự kiện/giai đoạn", "description": "Mô tả"}
  ],
  "decisions": ["Quyết định/kết luận đã đưa ra"],
  "open_questions": ["Câu hỏi/vấn đề chưa giải quyết"]
}

QUY TẮC:
- skeleton tối đa 3 cấp sâu, mỗi cấp tối đa 7 nhánh
- importance: 1-100 (100 = quan trọng nhất)
- entities ≤ 15
- timeline chỉ khi có dữ liệu thời gian rõ ràng, nếu không thì []
- Luôn có ít nhất 1 nucleus, 1 skeleton node, 3 key_points
- Trả về JSON THUẦN, không có text nào khác
PROMPT;
	}

	private static function parse_json_response( $response ) {
		$text = trim( (string) $response );
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $text, $m ) ) {
			$text = trim( $m[1] );
		}
		$json = json_decode( $text, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $json ) ) return $json;
		return null;
	}

	/* ───────────────────── Graph-RAG Enrichment ──────────────────────── */

	/**
	 * Pull a focused subgraph (entities, relations, passages) so tool
	 * callbacks can render graph-grounded artifacts. Skeleton's nucleus
	 * thesis is used as the seed query.
	 */
	private static function enrich_with_graph( $notebook_id, array $skeleton ) {
		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			return [ 'available' => false, 'reason' => 'retriever_missing' ];
		}

		$query = trim( (string) ( $skeleton['nucleus']['thesis'] ?? '' ) );
		if ( $query === '' ) {
			$query = (string) ( $skeleton['nucleus']['title'] ?? '' );
		}
		if ( $query === '' ) {
			return [ 'available' => false, 'reason' => 'no_seed_query' ];
		}

		try {
			$result = BizCity_KG_Retriever::instance()->ask( (int) $notebook_id, $query, [
				'seed_entities'  => 6,
				'seed_relations' => 24,
				'rerank_top_k'   => 8,
				'expand_hops'    => 1,
				'answer'         => false,
			] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat Studio] graph enrichment failed: ' . $e->getMessage() );
			return [ 'available' => false, 'reason' => 'exception' ];
		}

		$passages = [];
		foreach ( ( $result['passages'] ?? [] ) as $p ) {
			$passages[] = [
				'id'        => (int) ( $p['id'] ?? 0 ),
				'source_id' => (int) ( $p['source_id'] ?? 0 ),
				'content'   => mb_substr( (string) ( $p['content'] ?? '' ), 0, 800 ),
			];
		}

		return [
			'available'   => true,
			'seed_query'  => $query,
			'entities'    => $result['retrieval_detail']['entity_texts']    ?? [],
			'relations'   => $result['rerank_result']['selected_relation_texts']
			                 ?? ( $result['retrieval_detail']['relation_texts'] ?? [] ),
			'passages'    => $passages,
			'subgraph'    => [
				'node_count' => isset( $result['subgraph']['nodes'] ) ? count( $result['subgraph']['nodes'] ) : 0,
				'link_count' => isset( $result['subgraph']['links'] ) ? count( $result['subgraph']['links'] ) : 0,
			],
		];
	}

	/* ─────────────────────────── Cache ───────────────────────────────── */

	public static function get_cached( $notebook_id ) {
		$tbl = self::skeleton_table();
		if ( ! $tbl ) return null;
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT skeleton_json, status FROM {$tbl} WHERE project_id = %s LIMIT 1",
			self::project_id( $notebook_id )
		) );
		if ( ! $row || empty( $row->skeleton_json ) || $row->status !== 'ready' ) return null;
		$data = json_decode( $row->skeleton_json, true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) return null;
		return $data;
	}

	private static function save_cached( $notebook_id, array $skeleton ) {
		$tbl = self::skeleton_table();
		if ( ! $tbl ) return;
		global $wpdb;

		$pid  = self::project_id( $notebook_id );
		$json = wp_json_encode( $skeleton, JSON_UNESCAPED_UNICODE );

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE project_id = %s LIMIT 1",
			$pid
		) );

		$now = current_time( 'mysql' );
		$row = [
			'project_id'    => $pid,
			'skeleton_json' => $json,
			'note_count'    => (int) ( $skeleton['meta']['note_count']   ?? 0 ),
			'source_count'  => (int) ( $skeleton['meta']['source_count'] ?? 0 ),
			'version'       => self::SKELETON_VERSION,
			'status'        => 'ready',
			'generated_at'  => $now,
		];

		if ( $existing ) {
			$wpdb->update( $tbl, $row, [ 'project_id' => $pid ] );
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $tbl, $row );
		}
	}

	public static function invalidate( $notebook_id ) {
		$tbl = self::skeleton_table();
		if ( ! $tbl ) return;
		global $wpdb;
		$wpdb->update( $tbl, [ 'status' => 'stale' ], [ 'project_id' => self::project_id( $notebook_id ) ] );
	}

	private static function is_cache_valid( array $cached, array $raw ) {
		return ( $cached['meta']['source_count'] ?? -1 ) === (int) $raw['source_count']
			&& ( $cached['meta']['note_count']   ?? -1 ) === (int) $raw['note_count'];
	}

	/* ────────────────────────── Helpers ──────────────────────────────── */

	public static function project_id( $notebook_id ) {
		return self::PROJECT_PREFIX . (int) $notebook_id;
	}

	private static function skeleton_table() {
		if ( class_exists( 'BCN_Schema_Extend' ) ) {
			return BCN_Schema_Extend::table_project_skeletons();
		}
		global $wpdb;
		// Hard-coded fallback name (table is created by companion-notebook plugin).
		$tbl = $wpdb->prefix . 'bizcity_webchat_project_skeletons';
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ? $tbl : null;
	}

	private static function sanitize_attr( $s ) {
		return str_replace( [ '"', "\n", "\r" ], [ "'", ' ', ' ' ], (string) $s );
	}

	private static function empty_skeleton( $notebook_id ) {
		return [
			'version' => self::SKELETON_VERSION,
			'project_id' => self::project_id( $notebook_id ),
			'nucleus' => [ 'title' => '', 'thesis' => '', 'domain' => 'general' ],
			'skeleton' => [], 'key_points' => [], 'entities' => [],
			'timeline' => [], 'decisions' => [], 'open_questions' => [],
			'_raw_text' => '', '_kg_subgraph' => [ 'available' => false, 'reason' => 'empty' ],
			'meta' => [
				'notebook_id'  => (int) $notebook_id,
				'note_count'   => 0,
				'source_count' => 0,
				'timestamp'    => current_time( 'mysql' ),
				'is_empty'     => true,
			],
		];
	}

	private static function fallback_skeleton( $notebook_id, array $raw ) {
		$head = mb_substr( (string) $raw['text'], 0, 200 );
		return [
			'version' => self::SKELETON_VERSION,
			'project_id' => self::project_id( $notebook_id ),
			'nucleus' => [ 'title' => 'Tài liệu Notebook #' . (int) $notebook_id, 'thesis' => $head, 'domain' => 'general' ],
			'skeleton' => [ [ 'label' => 'Toàn bộ nội dung', 'summary' => $head, 'importance' => 80, 'children' => [] ] ],
			'key_points' => [ $head ], 'entities' => [], 'timeline' => [],
			'decisions' => [], 'open_questions' => [],
			'meta' => [
				'notebook_id'  => (int) $notebook_id,
				'note_count'   => (int) $raw['note_count'],
				'source_count' => (int) $raw['source_count'],
				'timestamp'    => current_time( 'mysql' ),
				'is_fallback'  => true,
			],
		];
	}
}
