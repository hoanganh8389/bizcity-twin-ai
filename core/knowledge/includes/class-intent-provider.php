<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Intent Provider — Đăng ký goals cho Intent Engine SDK
 * Register goals for Intent Engine SDK
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @since      3.0.0
 *
 * Goals:
 *   - train_knowledge   : Ingest file/URL/text → kiến thức / knowledge
 *   - search_knowledge  : Tìm kiến thức đa scope / Multi-scope search
 *   - manage_knowledge  : Xem / xóa / promote kiến thức / View/delete/promote
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! class_exists( 'BizCity_Intent_Provider' ) ) {
    return; // Intent Engine not active
}

class BizCity_Knowledge_Intent_Provider extends BizCity_Intent_Provider {

    /* ================================================================
     *  Provider identity
     * ================================================================ */

    public function get_id() {
        return 'knowledge-fabric';
    }

    public function get_name() {
        return 'BizCity Knowledge Fabric — Đào tạo & Tìm kiếm kiến thức';
    }

    /* ================================================================
     *  Goal patterns — Regex → goal mapping
     * ================================================================ */

    public function get_goal_patterns() {
        return array(

            /* ── train_knowledge ─────────────────────────── */
            '/(?:học|đào\s*tạo|huấn\s*luyện|train)\s*(?:file|tài\s*liệu|kiến\s*thức|từ\s*(?:file|ảnh|link))/ui' => array(
                'goal'        => 'train_knowledge',
                'label'       => 'Đào tạo kiến thức',
                'description' => 'Ingest file, URL, text hoặc FAQ vào knowledge base. User nói "học file này", "nhớ link đó", "đào tạo từ ảnh này".',
                'extract'     => array( 'source_type', 'scope', 'url', 'content' ),
            ),
            '/(?:nhớ|lưu|save|bookmark)\s*(?:link|url|trang\s*web|website|trang\s*này)/ui' => array(
                'goal'        => 'train_knowledge',
                'label'       => 'Lưu link làm kiến thức',
                'description' => 'User paste URL và muốn lưu lại dưới dạng kiến thức',
                'extract'     => array( 'url', 'scope' ),
            ),
            '/(?:nhớ|lưu)\s*(?:giúp|cho)\s*(?:tôi|mình|em)?\s*(?:file|tài\s*liệu|nội\s*dung|thông\s*tin)/ui' => array(
                'goal'        => 'train_knowledge',
                'label'       => 'Lưu nội dung',
                'description' => 'User muốn lưu text/thông tin vào kiến thức cá nhân',
                'extract'     => array( 'content', 'scope' ),
            ),

            /* ── search_knowledge ────────────────────────── */
            '/(?:tìm|search|tra\s*cứu)\s*(?:trong\s*)?(?:kiến\s*thức|knowledge|tài\s*liệu\s*(?:của\s*tôi)?)/ui' => array(
                'goal'        => 'search_knowledge',
                'label'       => 'Tìm kiến thức',
                'description' => 'Tìm kiếm trong knowledge base đa scope: user, project, session, agent',
                'extract'     => array( 'query' ),
            ),
            '/(?:có\s*tài\s*liệu|có\s*kiến\s*thức)\s*(?:nào|gì)\s*(?:về|liên\s*quan)/ui' => array(
                'goal'        => 'search_knowledge',
                'label'       => 'Tìm tài liệu liên quan',
                'description' => 'User hỏi "có tài liệu nào về X?"',
                'extract'     => array( 'query' ),
            ),

            /* ── manage_knowledge ────────────────────────── */
            '/(?:xem|danh\s*sách|list)\s*(?:kiến\s*thức|knowledge|tài\s*liệu)\s*(?:của\s*tôi)?/ui' => array(
                'goal'        => 'manage_knowledge',
                'label'       => 'Quản lý kiến thức',
                'description' => 'Xem danh sách, xóa, hoặc promote kiến thức',
                'extract'     => array( 'action', 'source_id', 'scope' ),
            ),
            '/(?:xóa|delete|remove)\s*(?:kiến\s*thức|tài\s*liệu|file đã lưu)/ui' => array(
                'goal'        => 'manage_knowledge',
                'label'       => 'Xóa kiến thức',
                'description' => 'User muốn xóa một source kiến thức',
                'extract'     => array( 'action', 'source_id' ),
            ),
            '/(?:lưu\s*vĩnh\s*viễn|promote|chuyển\s*(?:sang|thành)\s*(?:cá\s*nhân|personal))/ui' => array(
                'goal'        => 'manage_knowledge',
                'label'       => 'Promote kiến thức',
                'description' => 'Chuyển kiến thức từ session/project lên user scope (vĩnh viễn)',
                'extract'     => array( 'action', 'source_id', 'scope' ),
            ),
        );
    }

    /* ================================================================
     *  Plans — Goal → slot schema
     * ================================================================ */

    public function get_plans() {
        return array(

            'train_knowledge' => array(
                'required_slots' => array(
                    'source_type' => array(
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn đào tạo từ loại nào? (file / url / text)',
                        'choices' => array(
                            'file' => 'File (PDF, Excel, CSV, TXT...)',
                            'url'  => 'URL (link website)',
                            'text' => 'Text (gõ trực tiếp)',
                        ),
                    ),
                ),
                'optional_slots' => array(
                    'scope' => array(
                        'type'    => 'choice',
                        'prompt'  => 'Lưu kiến thức ở phạm vi nào? (Mặc định: cá nhân)',
                        'choices' => array(
                            'user'    => '👤 Cá nhân (vĩnh viễn)',
                            'project' => '📁 Dự án (chỉ project này)',
                            'session' => '💬 Phiên chat (tạm thời)',
                        ),
                        'default' => 'user',
                    ),
                    'url' => array(
                        'type'    => 'text',
                        'prompt'  => 'Gửi link URL cần lưu kiến thức:',
                        'default' => '',
                    ),
                    'content' => array(
                        'type'    => 'text',
                        'prompt'  => 'Nhập nội dung kiến thức:',
                        'default' => '',
                    ),
                    'source_name' => array(
                        'type'    => 'text',
                        'prompt'  => 'Đặt tên cho kiến thức này (tùy chọn):',
                        'default' => '',
                    ),
                ),
                'tool'       => 'knowledge_train',
                'ai_compose' => false,
                'slot_order' => array( 'source_type' ),
            ),

            'search_knowledge' => array(
                'required_slots' => array(
                    'query' => array(
                        'type'   => 'text',
                        'prompt' => 'Bạn muốn tìm kiến thức về chủ đề gì?',
                    ),
                ),
                'optional_slots' => array(),
                'tool'       => 'knowledge_search',
                'ai_compose' => true,
                'slot_order' => array( 'query' ),
            ),

            'manage_knowledge' => array(
                'required_slots' => array(
                    'action' => array(
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn làm gì với kiến thức?',
                        'choices' => array(
                            'list'    => '📋 Xem danh sách',
                            'delete'  => '🗑️ Xóa',
                            'promote' => '⬆️ Chuyển lên cá nhân (vĩnh viễn)',
                        ),
                    ),
                ),
                'optional_slots' => array(
                    'source_id' => array(
                        'type'   => 'number',
                        'prompt' => 'ID của kiến thức cần thao tác:',
                    ),
                    'scope' => array(
                        'type'    => 'choice',
                        'prompt'  => 'Lọc theo phạm vi:',
                        'choices' => array(
                            'user'    => '👤 Cá nhân',
                            'project' => '📁 Dự án',
                            'session' => '💬 Phiên chat',
                        ),
                        'default' => '',
                    ),
                ),
                'tool'       => 'knowledge_manage',
                'ai_compose' => false,
                'slot_order' => array( 'action' ),
            ),
        );
    }

    /* ================================================================
     *  Tools — Tool name → config + callback
     * ================================================================ */

    public function get_tools() {
        return array(

            'knowledge_train' => array(
                'schema' => array(
                    'description'  => 'Đào tạo kiến thức từ file, URL hoặc text vào Knowledge Fabric',
                    'input_fields' => array(
                        'source_type' => array( 'required' => true,  'type' => 'text' ),
                        'scope'       => array( 'required' => false, 'type' => 'text' ),
                        'url'         => array( 'required' => false, 'type' => 'text' ),
                        'content'     => array( 'required' => false, 'type' => 'text' ),
                        'source_name' => array( 'required' => false, 'type' => 'text' ),
                    ),
                ),
                'callback' => array( $this, 'tool_train' ),
            ),

            'knowledge_search' => array(
                'schema' => array(
                    'description'  => 'Tìm kiến thức đa scope trong Knowledge Fabric',
                    'input_fields' => array(
                        'query' => array( 'required' => true, 'type' => 'text' ),
                    ),
                ),
                'callback' => array( $this, 'tool_search' ),
            ),

            'knowledge_manage' => array(
                'schema' => array(
                    'description'  => 'Quản lý kiến thức: xem danh sách, xóa, promote scope',
                    'input_fields' => array(
                        'action'    => array( 'required' => true,  'type' => 'text' ),
                        'source_id' => array( 'required' => false, 'type' => 'number' ),
                        'scope'     => array( 'required' => false, 'type' => 'text' ),
                    ),
                ),
                'callback' => array( $this, 'tool_manage' ),
            ),
        );
    }

    /* ================================================================
     *  Tool Callbacks
     * ================================================================ */

    /**
     * Tool: knowledge_train — Ingest file/URL/text.
     *
     * @param array $slots
     * @return array  Tool output envelope
     */
    public function tool_train( $slots ) {
        $meta        = isset( $slots['_meta'] ) ? $slots['_meta'] : array();
        $user_id     = isset( $meta['user_id'] ) ? (int) $meta['user_id'] : get_current_user_id();
        $session_id  = isset( $meta['session_id'] ) ? $meta['session_id'] : '';
        $project_id  = isset( $meta['project_id'] ) ? (int) $meta['project_id'] : 0;

        $source_type = isset( $slots['source_type'] ) ? $slots['source_type'] : 'text';
        $scope       = isset( $slots['scope'] ) ? $slots['scope'] : 'user';
        $url         = isset( $slots['url'] ) ? $slots['url'] : '';
        $content     = isset( $slots['content'] ) ? $slots['content'] : '';
        $source_name = isset( $slots['source_name'] ) ? $slots['source_name'] : '';

        // Auto-detect source_type from context
        if ( $source_type === 'text' && ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
            $source_type = 'url';
        }

        // Handle image/file attachments from _meta
        $attachment_id = 0;
        if ( $source_type === 'file' && ! empty( $meta['images'] ) ) {
            // images are Media URLs — resolve to attachment_id
            $images = is_array( $meta['images'] ) ? $meta['images'] : array( $meta['images'] );
            if ( ! empty( $images[0] ) ) {
                $attachment_id = attachment_url_to_postid( $images[0] );
            }
        }

        $fabric = BizCity_Knowledge_Fabric::instance();

        $params = array(
            'source_type'   => $source_type,
            'scope'         => $scope,
            'user_id'       => $user_id,
            'project_id'    => $project_id > 0 ? $project_id : null,
            'session_id'    => $scope === 'session' ? $session_id : '',
            'character_id'  => 0,
            'url'           => $url,
            'content'       => $content,
            'attachment_id' => $attachment_id,
            'source_name'   => $source_name,
        );

        $result = $fabric->ingest( $params );

        if ( is_wp_error( $result ) ) {
            return array(
                'success'  => false,
                'complete' => false,
                'message'  => '❌ Lỗi đào tạo kiến thức: ' . $result->get_error_message(),
                'data'     => array(),
            );
        }

        $scope_labels = array(
            'user'    => '👤 Cá nhân',
            'project' => '📁 Dự án',
            'session' => '💬 Phiên chat',
            'agent'   => '🤖 Agent',
        );
        $scope_label = isset( $scope_labels[ $result['scope'] ] ) ? $scope_labels[ $result['scope'] ] : $result['scope'];

        return array(
            'success'  => true,
            'complete' => true,
            'message'  => sprintf(
                "✅ Đã đào tạo kiến thức thành công!\n\n" .
                "📄 **%s**\n" .
                "📦 %d chunks — %s tokens\n" .
                "🏷️ Phạm vi: %s\n" .
                "🔑 ID: #%d",
                $result['source_name'],
                $result['chunks_count'],
                number_format( $result['total_tokens'] ),
                $scope_label,
                $result['source_id']
            ),
            'data' => array(
                'id'   => $result['source_id'],
                'type' => 'knowledge_source',
            ),
        );
    }

    /**
     * Tool: knowledge_search — Search multi-scope.
     *
     * @param array $slots
     * @return array  Tool output envelope
     */
    public function tool_search( $slots ) {
        $meta       = isset( $slots['_meta'] ) ? $slots['_meta'] : array();
        $user_id    = isset( $meta['user_id'] ) ? (int) $meta['user_id'] : get_current_user_id();
        $session_id = isset( $meta['session_id'] ) ? $meta['session_id'] : '';
        $project_id = isset( $meta['project_id'] ) ? (int) $meta['project_id'] : 0;
        $char_id    = isset( $meta['character_id'] ) ? (int) $meta['character_id'] : 0;
        $query      = isset( $slots['query'] ) ? $slots['query'] : '';

        if ( empty( $query ) ) {
            return array(
                'success'  => false,
                'complete' => false,
                'message'  => 'Vui lòng cung cấp từ khóa tìm kiếm.',
                'data'     => array(),
                'missing_fields' => array( 'query' ),
            );
        }

        $fabric  = BizCity_Knowledge_Fabric::instance();
        $results = $fabric->search_multi_scope( $query, array(
            'user_id'      => $user_id,
            'character_id' => $char_id,
            'project_id'   => $project_id > 0 ? $project_id : null,
            'session_id'   => $session_id,
            'max_results'  => 8,
        ) );

        if ( empty( $results ) ) {
            return array(
                'success'  => true,
                'complete' => true,
                'message'  => "🔍 Không tìm thấy kiến thức nào liên quan đến \"{$query}\".\n\nBạn có thể đào tạo kiến thức mới bằng cách nói: \"học file này\" hoặc \"nhớ link đó\".",
                'data'     => array(),
            );
        }

        $scope_icons = array( 'user' => '👤', 'project' => '📁', 'session' => '💬', 'agent' => '🤖' );
        $lines = array();
        foreach ( $results as $i => $r ) {
            $icon    = isset( $scope_icons[ $r['scope'] ] ) ? $scope_icons[ $r['scope'] ] : '📄';
            $excerpt = mb_substr( $r['content'], 0, 120, 'UTF-8' );
            $score   = isset( $r['score'] ) ? round( $r['score'] * 100 ) . '%' : '';
            $lines[] = sprintf( "%d. %s [%s] **%s** (%s)\n   %s...", $i + 1, $icon, ucfirst( $r['scope'] ), $r['source_name'], $score, $excerpt );
        }

        return array(
            'success'  => true,
            'complete' => true,
            'message'  => sprintf(
                "📚 Tìm thấy **%d kết quả** cho \"%s\":\n\n%s",
                count( $results ),
                $query,
                implode( "\n\n", $lines )
            ),
            'data' => array(
                'results' => $results,
                'type'    => 'knowledge_search_results',
            ),
        );
    }

    /**
     * Tool: knowledge_manage — List / Delete / Promote.
     *
     * @param array $slots
     * @return array  Tool output envelope
     */
    public function tool_manage( $slots ) {
        $meta    = isset( $slots['_meta'] ) ? $slots['_meta'] : array();
        $user_id = isset( $meta['user_id'] ) ? (int) $meta['user_id'] : get_current_user_id();
        $action  = isset( $slots['action'] ) ? $slots['action'] : 'list';

        $fabric = BizCity_Knowledge_Fabric::instance();

        switch ( $action ) {

            /* ── LIST ── */
            case 'list':
                $scope   = isset( $slots['scope'] ) ? $slots['scope'] : null;
                $sources = $fabric->get_user_sources( $user_id, $scope );

                if ( empty( $sources ) ) {
                    return array(
                        'success'  => true,
                        'complete' => true,
                        'message'  => '📭 Bạn chưa có kiến thức nào.' . ( $scope ? " (scope: {$scope})" : '' ) . "\n\nBắt đầu bằng cách nói: \"học file này\" hoặc \"nhớ link đó\".",
                        'data'     => array(),
                    );
                }

                $scope_icons = array( 'user' => '👤', 'project' => '📁', 'session' => '💬', 'agent' => '🤖' );
                $lines = array();
                foreach ( $sources as $s ) {
                    $icon    = isset( $scope_icons[ $s->scope ] ) ? $scope_icons[ $s->scope ] : '📄';
                    $lines[] = sprintf(
                        "#%d %s **%s** — %s (%d chunks) — %s",
                        $s->id,
                        $icon,
                        $s->source_name,
                        $s->source_type,
                        $s->chunks_count,
                        $s->created_at
                    );
                }

                return array(
                    'success'  => true,
                    'complete' => true,
                    'message'  => sprintf( "📚 **Kiến thức của bạn** (%d mục):\n\n%s", count( $sources ), implode( "\n", $lines ) ),
                    'data'     => array( 'sources' => $sources, 'type' => 'knowledge_list' ),
                );

            /* ── DELETE ── */
            case 'delete':
                $source_id = isset( $slots['source_id'] ) ? (int) $slots['source_id'] : 0;
                if ( ! $source_id ) {
                    return array(
                        'success'  => false,
                        'complete' => false,
                        'message'  => 'Cần cung cấp ID kiến thức cần xóa. Gõ "xem kiến thức" để xem danh sách.',
                        'data'     => array(),
                        'missing_fields' => array( 'source_id' ),
                    );
                }

                $del = $fabric->delete_source( $source_id, $user_id );
                if ( is_wp_error( $del ) ) {
                    return array(
                        'success'  => false,
                        'complete' => false,
                        'message'  => '❌ ' . $del->get_error_message(),
                        'data'     => array(),
                    );
                }

                return array(
                    'success'  => true,
                    'complete' => true,
                    'message'  => "🗑️ Đã xóa kiến thức #" . $source_id . " thành công.",
                    'data'     => array( 'deleted_id' => $source_id ),
                );

            /* ── PROMOTE ── */
            case 'promote':
                $source_id = isset( $slots['source_id'] ) ? (int) $slots['source_id'] : 0;
                if ( ! $source_id ) {
                    return array(
                        'success'  => false,
                        'complete' => false,
                        'message'  => 'Cần cung cấp ID kiến thức cần chuyển. Gõ "xem kiến thức" để xem danh sách.',
                        'data'     => array(),
                        'missing_fields' => array( 'source_id' ),
                    );
                }

                $promote = $fabric->promote_scope( $source_id, 'user', array( 'user_id' => $user_id ) );
                if ( is_wp_error( $promote ) ) {
                    return array(
                        'success'  => false,
                        'complete' => false,
                        'message'  => '❌ ' . $promote->get_error_message(),
                        'data'     => array(),
                    );
                }

                return array(
                    'success'  => true,
                    'complete' => true,
                    'message'  => "⬆️ Đã chuyển kiến thức #" . $source_id . " lên **👤 Cá nhân** (vĩnh viễn).",
                    'data'     => array( 'promoted_id' => $source_id ),
                );

            default:
                return array(
                    'success'  => false,
                    'complete' => false,
                    'message'  => 'Hành động không hợp lệ: ' . $action,
                    'data'     => array(),
                );
        }
    }

    /* ================================================================
     *  Context & Instructions
     * ================================================================ */

    /**
     * Build context for knowledge goals.
     *
     * @param string $goal
     * @param array  $slots
     * @param int    $user_id
     * @param mixed  $conversation
     * @return string
     */
    public function build_context( $goal, $slots, $user_id, $conversation ) {
        if ( ! class_exists( 'BizCity_Knowledge_Fabric' ) ) {
            return '';
        }

        $fabric = BizCity_Knowledge_Fabric::instance();
        $db     = BizCity_Knowledge_Database::instance();
        $counts = $db->count_user_sources( $user_id );

        $total = $counts['user'] + $counts['project'] + $counts['session'];
        if ( $total === 0 ) {
            return "User chưa có kiến thức cá nhân nào.";
        }

        return sprintf(
            "User có %d nguồn kiến thức: 👤 %d cá nhân, 📁 %d dự án, 💬 %d phiên.",
            $total,
            $counts['user'],
            $counts['project'],
            $counts['session']
        );
    }

    /**
     * System instructions for LLM when processing knowledge goals.
     *
     * @param string $goal
     * @return string
     */
    public function get_system_instructions( $goal ) {
        switch ( $goal ) {
            case 'train_knowledge':
                return "User muốn đào tạo kiến thức. Giúp user xác định loại nguồn (file/url/text) và thực hiện ingest.\n" .
                       "Nếu user gửi kèm file → source_type=file. Nếu paste URL → source_type=url.\n" .
                       "Mặc định scope=user (kiến thức cá nhân vĩnh viễn).";

            case 'search_knowledge':
                return "User muốn tìm kiến thức đã lưu. Giúp trích xuất từ khóa tìm kiếm.\n" .
                       "Kết quả sẽ được search qua nhiều scope: session > project > user > agent.";

            case 'manage_knowledge':
                return "User muốn quản lý kiến thức: xem danh sách, xóa, hoặc promote lên scope cao hơn.\n" .
                       "Nếu user nói 'lưu vĩnh viễn' → action=promote, chuyển từ session lên user.";

            default:
                return '';
        }
    }
}
