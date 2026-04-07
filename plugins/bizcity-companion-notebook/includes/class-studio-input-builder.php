<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Studio Input Builder — Skeleton JSON chuẩn cho mọi Studio tool.
 *
 * Thay vì pass raw text, Builder gọi LLM phân tích 1 lần → trích xuất:
 *   - nucleus (hạt nhân xuyên suốt)
 *   - skeleton (cấu trúc phân cấp cha-con)
 *   - key_points, entities, timeline, decisions
 *
 * Skeleton được cache per project. Mọi tool (mindmap, slide, flashcard, report…)
 * đều nhận CÙNG skeleton JSON chuẩn — không cần phân tích lại.
 *
 * Cache tự invalidate khi note_count hoặc source_count thay đổi.
 */
class BCN_Studio_Input_Builder {

    const CACHE_PREFIX    = 'bcn_skeleton_';
    const SKELETON_VERSION = '1.0';

    /**
     * Build or retrieve cached Skeleton JSON for a project.
     *
     * @param string $project_id Project UUID.
     * @param array  $options    { force: bool }
     * @return array Skeleton JSON.
     */
    public static function build( $project_id, array $options = [] ) {
        $force = $options['force'] ?? false;
        $raw   = self::gather_raw( $project_id );

        if ( empty( $raw['text'] ) ) {
            return self::empty_skeleton( $project_id );
        }

        // Check cache — rebuild only when data changed.
        if ( ! $force ) {
            $cached = self::get_cached( $project_id );
            if ( $cached && self::is_cache_valid( $cached, $raw ) ) {
                return $cached;
            }
        }

        // Extract skeleton via LLM.
        $skeleton = self::extract_skeleton( $project_id, $raw );

        if ( is_wp_error( $skeleton ) ) {
            // Fallback: wrap raw text so tools can still run.
            return self::fallback_skeleton( $project_id, $raw );
        }

        // Always preserve raw source text so tools like landing page builder can
        // access the actual document content (not just the distilled skeleton).
        $skeleton['_raw_text'] = $raw['text'];

        self::save_cached( $project_id, $skeleton );
        return $skeleton;
    }

    // ── Data Gathering ──

    private static function gather_raw( $project_id ) {
        $sources = new BCN_Sources();
        $notes   = new BCN_Notes();

        $all_notes   = $notes->get_by_project( $project_id );
        $all_sources = $sources->get_by_project( $project_id );
        $sources_text = $sources->get_all_content( $project_id );

        // Sort: pinned first, then recent.
        usort( $all_notes, function ( $a, $b ) {
            $ap = ! empty( $a->is_pinned );
            $bp = ! empty( $b->is_pinned );
            if ( $ap !== $bp ) return $bp ? 1 : -1;
            return strtotime( $b->created_at ) - strtotime( $a->created_at );
        } );

        $parts = [];
        $notes_text_parts = [];
        foreach ( $all_notes as $n ) {
            $prefix = ! empty( $n->is_pinned ) ? '[📌] ' : '';
            $notes_text_parts[] = $prefix . ( $n->title ? "[{$n->title}] " : '' ) . $n->content;
        }
        if ( $notes_text_parts ) {
            $parts[] = "=== GHI CHÚ ===\n" . implode( "\n\n", $notes_text_parts );
        }
        if ( $sources_text ) {
            $parts[] = "=== NGUỒN TÀI LIỆU ===\n" . $sources_text;
        }

        // Fallback for webchat sessions: pull chat messages if no notes/sources.
        if ( empty( $parts ) && str_starts_with( $project_id, 'wcs_' ) ) {
            global $wpdb;
            $msg_table = $wpdb->prefix . 'bizcity_webchat_messages';
            $rows      = $wpdb->get_results( $wpdb->prepare(
                "SELECT message_from, message_text FROM {$msg_table}
                 WHERE session_id = %s AND status = 'visible'
                 ORDER BY id ASC LIMIT 100",
                $project_id
            ) );
            if ( $rows ) {
                $msg_parts = [];
                foreach ( $rows as $r ) {
                    $role = ( $r->message_from === 'user' ) ? 'User' : 'AI';
                    $msg_parts[] = "[{$role}] " . $r->message_text;
                }
                $parts[] = "=== HỘI THOẠI ===\n" . implode( "\n\n", $msg_parts );
            }
        }

        return [
            'text'         => implode( "\n\n", $parts ),
            'note_count'   => count( $all_notes ),
            'source_count' => count( $all_sources ),
            'pinned_count' => count( array_filter( $all_notes, fn( $n ) => ! empty( $n->is_pinned ) ) ),
        ];
    }

    // ── LLM Skeleton Extraction ──

    private static function extract_skeleton( $project_id, array $raw ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return new WP_Error( 'no_llm', 'OpenRouter not available' );
        }

        $response = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => self::skeleton_prompt() ],
            [ 'role' => 'user',   'content' => mb_substr( $raw['text'], 0, 50000 ) ],
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $json = self::parse_json_response( $response['message'] ?? '' );
        if ( ! $json ) {
            return new WP_Error( 'parse_error', 'Cannot parse skeleton JSON from LLM' );
        }

        return [
            'version'        => self::SKELETON_VERSION,
            'project_id'     => $project_id,
            'generated_at'   => gmdate( 'c' ),
            'nucleus'        => $json['nucleus'] ?? [ 'title' => '', 'thesis' => '', 'domain' => 'general' ],
            'skeleton'       => $json['skeleton'] ?? [],
            'key_points'     => $json['key_points'] ?? [],
            'entities'       => $json['entities'] ?? [],
            'timeline'       => $json['timeline'] ?? [],
            'decisions'      => $json['decisions'] ?? [],
            'open_questions' => $json['open_questions'] ?? [],
            'meta'           => [
                'source_count'      => $raw['source_count'],
                'note_count'        => $raw['note_count'],
                'pinned_note_count' => $raw['pinned_count'],
                'timestamp'         => gmdate( 'c' ),
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
    {
      "id": "1",
      "label": "Nhánh chính 1",
      "summary": "Tóm tắt ngắn",
      "importance": 90,
      "children": [
        {
          "id": "1.1",
          "label": "Nhánh phụ",
          "summary": "...",
          "importance": 70,
          "children": []
        }
      ]
    }
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
- Nếu nội dung ngắn: skeleton có thể chỉ 1-2 nhánh
- entities ≤ 15, chỉ liệt kê đáng kể
- timeline chỉ khi có dữ liệu thời gian rõ ràng, nếu không thì []
- Luôn có ít nhất 1 nucleus, 1 skeleton node, 3 key_points
- Trả về JSON THUẦN, không có text nào khác
PROMPT;
    }

    private static function parse_json_response( $response ) {
        $text = trim( $response );

        // Strip markdown code fences if present.
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $text, $m ) ) {
            $text = trim( $m[1] );
        }

        $json = json_decode( $text, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $json ) ) {
            return $json;
        }

        return null;
    }

    // ── Cache (DB-backed since v3.0.0) ──

    /**
     * Retrieve cached skeleton from DB.
     *
     * Returns the skeleton only when status = 'ready'.
     * Returns null if no skeleton or status = 'stale'.
     */
    public static function get_cached( $project_id ) {
        global $wpdb;
        $table = BCN_Schema_Extend::table_project_skeletons();
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE project_id = %s AND status = 'ready' LIMIT 1",
            $project_id
        ) );
        if ( ! $row || empty( $row->skeleton_json ) ) return null;
        $data = json_decode( $row->skeleton_json, true );
        if ( ! is_array( $data ) || empty( $data['version'] ) ) return null;
        return $data;
    }

    /**
     * Save or update skeleton in DB.
     */
    private static function save_cached( $project_id, array $skeleton ) {
        global $wpdb;
        $table = BCN_Schema_Extend::table_project_skeletons();
        $json  = wp_json_encode( $skeleton, JSON_UNESCAPED_UNICODE );

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE project_id = %s LIMIT 1",
            $project_id
        ) );

        if ( $existing_id ) {
            $wpdb->update( $table, [
                'skeleton_json' => $json,
                'note_count'   => $skeleton['meta']['note_count'] ?? 0,
                'source_count' => $skeleton['meta']['source_count'] ?? 0,
                'version'      => self::SKELETON_VERSION,
                'status'       => 'ready',
                'generated_at' => current_time( 'mysql' ),
            ], [ 'project_id' => $project_id ] );
        } else {
            $wpdb->insert( $table, [
                'project_id'    => $project_id,
                'skeleton_json' => $json,
                'note_count'    => $skeleton['meta']['note_count'] ?? 0,
                'source_count'  => $skeleton['meta']['source_count'] ?? 0,
                'version'       => self::SKELETON_VERSION,
                'status'        => 'ready',
                'generated_at'  => current_time( 'mysql' ),
                'created_at'    => current_time( 'mysql' ),
            ] );
        }
    }

    /**
     * Mark skeleton as stale (call when notes/sources change).
     * Keeps the DB row for context continuity — will be regenerated on next build().
     */
    public static function invalidate( $project_id ) {
        global $wpdb;
        $table = BCN_Schema_Extend::table_project_skeletons();
        $wpdb->update( $table, [ 'status' => 'stale' ], [ 'project_id' => $project_id ] );
    }

    private static function is_cache_valid( array $cached, array $raw ) {
        return ( $cached['meta']['source_count'] ?? -1 ) === $raw['source_count']
            && ( $cached['meta']['note_count'] ?? -1 )   === $raw['note_count'];
    }

    // ── Skeleton → Text ──

    /**
     * Build compact context summary for injection into AI context chain (Layer 7).
     *
     * Kept under $max_chars to stay token-efficient.
     * Highest-priority info (thesis, key_points, structure) appears first.
     *
     * @param array $skeleton  Skeleton JSON array from get_cached() or build().
     * @param int   $max_chars Maximum character length.
     * @return string
     */
    public static function to_context_summary( array $skeleton, int $max_chars = 2000 ): string {
        if ( empty( $skeleton ) ) return '';

        $parts  = [];
        $gen_at = ! empty( $skeleton['meta']['timestamp'] )
            ? date_i18n( 'd/m/Y H:i', strtotime( $skeleton['meta']['timestamp'] ) )
            : '';
        $parts[] = '## 📚 NOTEBOOK PROJECT SKELETON' . ( $gen_at ? " ({$gen_at})" : '' );
        $parts[] = '(Tri thức dự án đã được cô đọng — dùng để hiểu đúng ngữ cảnh Project của user)';

        if ( ! empty( $skeleton['nucleus']['title'] ) ) {
            $parts[] = '**Chủ đề:** ' . $skeleton['nucleus']['title'];
            if ( ! empty( $skeleton['nucleus']['thesis'] ) ) {
                $parts[] = '**Luận điểm:** ' . $skeleton['nucleus']['thesis'];
            }
        }

        if ( ! empty( $skeleton['key_points'] ) ) {
            $parts[] = "\n**Điểm chính:**";
            foreach ( array_slice( $skeleton['key_points'], 0, 5 ) as $pt ) {
                $parts[] = '- ' . $pt;
            }
        }

        if ( ! empty( $skeleton['skeleton'] ) ) {
            $parts[] = "\n**Cấu trúc:**";
            foreach ( array_slice( $skeleton['skeleton'], 0, 4 ) as $node ) {
                $label   = $node['label'] ?? '';
                $summary = $node['summary'] ?? '';
                $parts[] = "• {$label}" . ( $summary ? ": {$summary}" : '' );
            }
        }

        if ( ! empty( $skeleton['decisions'] ) ) {
            $parts[] = "\n**Quyết định chính:**";
            foreach ( array_slice( $skeleton['decisions'], 0, 3 ) as $d ) {
                $parts[] = "✓ {$d}";
            }
        }

        if ( ! empty( $skeleton['open_questions'] ) ) {
            $parts[] = "\n**Câu hỏi mở:**";
            foreach ( array_slice( $skeleton['open_questions'], 0, 2 ) as $q ) {
                $parts[] = "? {$q}";
            }
        }

        return mb_substr( implode( "\n", $parts ), 0, $max_chars, 'UTF-8' );
    }

    /**
     * Convert Skeleton JSON to structured text for LLM consumption.
     */
    public static function to_text( array $skeleton ) {
        $parts = [];

        // Nucleus
        if ( ! empty( $skeleton['nucleus']['title'] ) ) {
            $parts[] = "=== HẠT NHÂN CHÍNH ===";
            $parts[] = "Chủ đề: " . $skeleton['nucleus']['title'];
            if ( ! empty( $skeleton['nucleus']['thesis'] ) ) {
                $parts[] = "Luận điểm: " . $skeleton['nucleus']['thesis'];
            }
        }

        // Skeleton tree
        if ( ! empty( $skeleton['skeleton'] ) ) {
            $parts[] = "\n=== CẤU TRÚC PHÂN CẤP ===";
            foreach ( $skeleton['skeleton'] as $node ) {
                $parts[] = self::render_node( $node, 0 );
            }
        }

        // Key points
        if ( ! empty( $skeleton['key_points'] ) ) {
            $parts[] = "\n=== ĐIỂM CHÍNH ===";
            foreach ( $skeleton['key_points'] as $i => $point ) {
                $parts[] = ( $i + 1 ) . ". " . $point;
            }
        }

        // Entities
        if ( ! empty( $skeleton['entities'] ) ) {
            $parts[] = "\n=== THỰC THỂ CHÍNH ===";
            foreach ( $skeleton['entities'] as $e ) {
                $role = $e['role'] ?? '';
                $parts[] = "- {$e['name']} ({$e['type']})" . ( $role ? ": {$role}" : '' );
            }
        }

        // Timeline
        if ( ! empty( $skeleton['timeline'] ) ) {
            $parts[] = "\n=== DÒNG THỜI GIAN ===";
            foreach ( $skeleton['timeline'] as $t ) {
                $parts[] = "{$t['order']}. {$t['label']}: {$t['description']}";
            }
        }

        // Decisions
        if ( ! empty( $skeleton['decisions'] ) ) {
            $parts[] = "\n=== QUYẾT ĐỊNH ===";
            foreach ( $skeleton['decisions'] as $d ) {
                $parts[] = "• " . $d;
            }
        }

        // Open questions
        if ( ! empty( $skeleton['open_questions'] ) ) {
            $parts[] = "\n=== CÂU HỎI MỞ ===";
            foreach ( $skeleton['open_questions'] as $q ) {
                $parts[] = "? " . $q;
            }
        }

        // Fallback: raw text if skeleton extraction failed
        if ( empty( $parts ) && ! empty( $skeleton['_raw_text'] ) ) {
            return $skeleton['_raw_text'];
        }

        // Always append raw source text when available so tools receive full document context.
        // Even a well-structured skeleton loses specifics during LLM distillation.
        if ( ! empty( $skeleton['_raw_text'] ) && $skeleton['_raw_text'] ) {
            $parts[] = "\n=== NỘI DUNG NGUỒN TÀI LIỆU ===";
            $parts[] = mb_substr( $skeleton['_raw_text'], 0, 20000 );
        }

        return implode( "\n", $parts );
    }

    private static function render_node( array $node, int $depth ) {
        $indent  = str_repeat( '  ', $depth );
        $bullet  = $depth === 0 ? '■' : ( $depth === 1 ? '├' : '└' );
        $line    = "{$indent}{$bullet} {$node['label']}";
        if ( ! empty( $node['summary'] ) ) {
            $line .= " — {$node['summary']}";
        }
        $lines = [ $line ];
        foreach ( $node['children'] ?? [] as $child ) {
            $lines[] = self::render_node( $child, $depth + 1 );
        }
        return implode( "\n", $lines );
    }

    // ── Fallback / Empty ──

    private static function empty_skeleton( $project_id ) {
        return [
            'version'        => self::SKELETON_VERSION,
            'project_id'     => $project_id,
            'generated_at'   => gmdate( 'c' ),
            'nucleus'        => [ 'title' => '', 'thesis' => '', 'domain' => 'general' ],
            'skeleton'       => [],
            'key_points'     => [],
            'entities'       => [],
            'timeline'       => [],
            'decisions'      => [],
            'open_questions' => [],
            'meta'           => [
                'source_count'      => 0,
                'note_count'        => 0,
                'pinned_note_count' => 0,
                'timestamp'         => gmdate( 'c' ),
            ],
        ];
    }

    private static function fallback_skeleton( $project_id, array $raw ) {
        return [
            'version'        => self::SKELETON_VERSION,
            'project_id'     => $project_id,
            'generated_at'   => gmdate( 'c' ),
            'nucleus'        => [ 'title' => 'Tài liệu dự án', 'thesis' => '', 'domain' => 'general' ],
            'skeleton'       => [],
            'key_points'     => [],
            'entities'       => [],
            'timeline'       => [],
            'decisions'      => [],
            'open_questions' => [],
            '_raw_text'      => $raw['text'],
            'meta'           => [
                'source_count'      => $raw['source_count'],
                'note_count'        => $raw['note_count'],
                'pinned_note_count' => $raw['pinned_count'],
                'timestamp'         => gmdate( 'c' ),
                'fallback'          => true,
            ],
        ];
    }
}
