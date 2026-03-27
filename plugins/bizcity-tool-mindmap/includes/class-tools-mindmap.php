<?php
/**
 * BizCity Tool Mindmap — Tool Callbacks + AJAX Handlers
 *
 * Kiến trúc "Developer-packaged Pipeline":
 *   1. Intent Provider khai báo goal_patterns + required_slots
 *   2. Tool callback tạo Mermaid syntax bằng AI → lưu thành CPT bz_mindmap
 *   3. AJAX endpoints phục vụ trang views SPA (generate, save, list, get, delete, update)
 *
 * @package BizCity_Tool_Mindmap
 * @since   1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Mindmap {

    /* ════════════════════════════════════════════════════════════
     *  Bootstrap
     * ════════════════════════════════════════════════════════════ */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );

        // AJAX handlers cho view page SPA
        $actions = [ 'generate', 'save', 'list', 'get', 'delete', 'update', 'upload_media' ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_bztool_mm_{$a}", [ __CLASS__, "ajax_{$a}" ] );
        }
    }

    /* ════════════════════════════════════════════════════════════
     *  Custom Post Type: bz_mindmap
     * ════════════════════════════════════════════════════════════ */
    public static function register_post_type() {
        register_post_type( 'bz_mindmap', [
            'labels' => [
                'name'          => 'Mindmaps',
                'singular_name' => 'Mindmap',
            ],
            'public'          => false,
            'show_ui'         => false,
            'supports'        => [ 'title', 'editor', 'author' ],
            'capability_type' => 'post',
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  Intent Engine Tool Callback — create_diagram
     *
     *  Flow:
     *    T1: AI phân tích prompt → tạo Mermaid code
     *    T2: Lưu vào CPT bz_mindmap + post_meta
     *
     *  @param array $slots { topic, diagram_type, session_id, chat_id, _meta }
     *  @return array Tool Output Envelope
     * ══════════════════════════════════════════════════════════════ */
    public static function create_diagram( array $slots ): array {
        $meta       = $slots['_meta']    ?? [];
        $ai_context = $meta['_context']  ?? '';
        $topic      = self::extract_text( $slots );
        $diagram_type = $slots['diagram_type'] ?? 'auto';
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']    ?? '';
        $user_id    = get_current_user_id();

        if ( empty( $topic ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Cần mô tả nội dung sơ đồ bạn muốn vẽ.',
                'data'    => [], 'missing_fields' => [ 'topic' ],
            ];
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module AI (OpenRouter) chưa sẵn sàng.',
                'data'    => [],
            ];
        }

        // ── Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start(
                $session_id ?: $chat_id ?: 'cli',
                'create_diagram',
                [
                    'T1' => 'AI phân tích & tạo Mermaid code',
                    'T2' => 'Lưu sơ đồ',
                ]
            );
        }

        // ══════════════════════════════════════════════════════
        //  T1: AI generate Mermaid
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T1', 'running' );

        $result = self::ai_generate_mermaid( $topic, $diagram_type, $ai_context );

        if ( is_wp_error( $result ) ) {
            if ( $trace ) $trace->fail( $result->get_error_message() );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ ' . $result->get_error_message(),
                'data'    => [],
            ];
        }

        $title   = $result['title']   ?? wp_trim_words( $topic, 8 );
        $type    = $result['type']     ?? 'flowchart';
        $mermaid = $result['mermaid']  ?? '';
        $desc    = $result['description'] ?? $topic;

        if ( $trace ) $trace->step( 'T1', 'done', [ 'type' => $type, 'title' => $title ] );

        // ══════════════════════════════════════════════════════
        //  T2: Save to bz_mindmap CPT
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T2', 'running' );

        $post_id = wp_insert_post( [
            'post_type'    => 'bz_mindmap',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => sanitize_textarea_field( $desc ),
            'post_status'  => 'publish',
            'post_author'  => $user_id ?: 1,
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            if ( $trace ) $trace->step( 'T2', 'failed' );
            if ( $trace ) $trace->fail( 'Không thể lưu sơ đồ' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Lỗi khi lưu sơ đồ vào database.',
                'data'    => [],
            ];
        }

        update_post_meta( $post_id, '_bz_mermaid_code', $mermaid );
        update_post_meta( $post_id, '_bz_mermaid_type', $type );
        update_post_meta( $post_id, '_bz_prompt', $topic );

        $view_url = home_url( '/tool-mindmap/?id=' . $post_id );

        if ( $trace ) $trace->step( 'T2', 'done', [ 'post_id' => $post_id ] );
        if ( $trace ) $trace->complete( [ 'post_id' => $post_id, 'view_url' => $view_url ] );

        // ── Notify Telegram nếu từ Telegram ──
        if ( $chat_id && function_exists( 'twf_telegram_send_message' ) ) {
            twf_telegram_send_message( $chat_id, "✅ Đã tạo sơ đồ: {$title}\n📊 Loại: {$type}\n🔗 {$view_url}" );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã tạo sơ đồ: **{$title}**\n"
                . "📊 Loại: {$type}\n"
                . "🔗 [Xem & Chỉnh sửa]({$view_url})\n\n"
                . "```mermaid\n{$mermaid}\n```",
            'data' => [
                'id'           => $post_id,
                'type'         => 'diagram',
                'diagram_type' => $type,
                'title'        => $title,
                'mermaid'      => $mermaid,
                'description'  => $desc,
                'url'          => $view_url,
                'trace_id'     => $trace ? $trace->get_trace_id() : '',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  AI: Generate Mermaid code
     * ══════════════════════════════════════════════════════════════ */
    private static function ai_generate_mermaid( string $topic, string $type = 'auto', string $ai_context = '' ) {
        // Normalize type aliases from UI chips
        if ( $type === 'state' || $type === 'stateDiagram' ) {
            $type = 'stateDiagram-v2';
        }

        // Default to graph TD — best for top-down hierarchy trees
        $default_type = 'graph TD';
        $type_hint = ( $type !== 'auto' && $type !== '' )
            ? "Loại sơ đồ yêu cầu: {$type}. "
            : 'Mặc định dùng loại: graph TD (cây phân cấp từ trên xuống dưới). ';

        $sys = "Bạn là chuyên gia tạo sơ đồ Mermaid. Tạo sơ đồ chính xác, đẹp, chi tiết.\n"
            . "Các loại hỗ trợ: graph TD (mặc định), stateDiagram-v2, mindmap, flowchart, sequenceDiagram, classDiagram, gantt, pie, erDiagram.\n\n"
            . "═══ graph TD — MẶC ĐỊNH (cây phân cấp từ trên xuống) ═══\n"
            . "Dùng cho: mindmap nội dung, cây kiến thức, phân tích khái niệm, phân cấp chủ đề.\n"
            . "Render theo chiều DỌC từ trên xuống dưới, lớn → nhỏ dần.\n"
            . "VÍ DỤ CHUẨN:\n"
            . "graph TD\n"
            . "    Root[\"Chủ đề gốc\"]\n"
            . "    Root --> A[\"Nhánh A\"]\n"
            . "    Root --> B[\"Nhánh B\"]\n"
            . "    Root --> C[\"Nhánh C\"]\n"
            . "    A --> A1[\"Con A-1\"]\n"
            . "    A --> A2[\"Con A-2\"]\n"
            . "    A2 --> A2a[\"Chi tiết A-2a\"]\n"
            . "    A2 --> A2b[\"Chi tiết A-2b\"]\n"
            . "    B --> B1[\"Con B-1\"]\n"
            . "    B --> B2[\"Con B-2\"]\n"
            . "    C --> C1[\"Con C-1\"]\n"
            . "    C --> C2[\"Con C-2\"]\n\n"
            . "QUY TẮC graph TD — BẮT BUỘC:\n"
            . "1. Dòng 1: graph TD (không thêm gì)\n"
            . "2. Node ID: Latin không dấu, không space, UNIQUE (vd: Root, branchA, item2a)\n"
            . "3. Node label: đặt TRONG dấu nháy kép bên trong [] — vd: A[\"Tiêu đề nhánh\"]\n"
            . "4. Label được dùng tiếng Việt, dấu gạch ngang -, dấu phẩy, chữ hoa tự do\n"
            . "5. Quan hệ cha → con: ParentId --> ChildId[\"Label con\"]\n"
            . "6. KHÔNG có label trên mũi tên (không dùng -->|text|) — cây thuần túy\n"
            . "7. Mỗi node/label tối đa 50 ký tự\n"
            . "8. Ít nhất 15-25 nodes tổng cộng, tối thiểu 3 cấp sâu\n"
            . "9. Labels tiếng Việt (trừ khi user yêu cầu tiếng Anh)\n"
            . "10. Khai báo node gốc TRƯỚC, rồi mới khai báo -->\n\n"
            . "═══ stateDiagram-v2 (chỉ khi user yêu cầu STATE MACHINE / luồng trạng thái) ═══\n"
            . "Dùng [*] -->, state Id { }, StateId: Label. Cho phép --> giữa các state.\n\n"
            . "═══ mindmap (chỉ khi user yêu cầu tường minh 'mindmap') ═══\n"
            . "Cú pháp: mindmap / root((label)) / nodes thụt lề 2 spaces.\n"
            . "⚠️ Text node KHÔNG được chứa: - + * # > | ^ ~ \\ : () [] {}\n\n"
            . "Chỉ trả JSON, không giải thích.";

        if ( $ai_context ) {
            $sys .= "\n\n" . $ai_context;
        }

        // For 'auto', push AI toward mindmap by setting it in the JSON schema
        $effective_type = ( $type === 'auto' || $type === '' ) ? $default_type : $type;

        $prompt = "{$type_hint}Yêu cầu: {$topic}\n\n"
            . "Trả về ĐÚNG JSON:\n"
            . "{\n"
            . "  \"title\": \"Tiêu đề sơ đồ (ngắn gọn, dưới 60 ký tự)\",\n"
            . "  \"type\": \"{$effective_type}\",\n"
            . "  \"mermaid\": \"Mermaid syntax hoàn chỉnh\",\n"
            . "  \"description\": \"Mô tả ngắn về sơ đồ (1-2 câu)\"\n"
            . "}";

        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys ],
            [ 'role' => 'user',   'content' => $prompt ],
        ], [
            'model'       => 'google/gemini-2.0-flash-001',
            'purpose'     => 'mindmap',
            'temperature' => 0.7,
            'max_tokens'  => 4000,
        ] );

        $parsed = self::parse_json_response( $ai_result['message'] ?? '' );

        if ( empty( $parsed['mermaid'] ) ) {
            return new WP_Error( 'ai_failed', 'AI không tạo được sơ đồ Mermaid. Thử mô tả rõ hơn nhé.' );
        }

        // Only sanitize classic mindmap syntax — stateDiagram-v2 uses quoted labels (safe).
        if ( ! empty( $parsed['mermaid'] ) && str_starts_with( ltrim( $parsed['mermaid'] ), 'mindmap' ) ) {
            $parsed['mermaid'] = self::sanitize_mermaid_mindmap( $parsed['mermaid'] );
        }

        return $parsed;
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Generate Mermaid from prompt (View page SPA)
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_generate() {
        check_ajax_referer( 'bztool_mindmap', 'nonce' );

        $prompt = sanitize_textarea_field( $_POST['prompt'] ?? '' );
        $type   = sanitize_text_field( $_POST['type'] ?? 'auto' );

        if ( empty( $prompt ) ) {
            wp_send_json_error( [ 'message' => 'Cần nhập mô tả sơ đồ.' ] );
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            wp_send_json_error( [ 'message' => 'AI chưa sẵn sàng (OpenRouter).' ] );
        }

        $result = self::ai_generate_mermaid( $prompt, $type );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Save mindmap (create or update)
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_save() {
        check_ajax_referer( 'bztool_mindmap', 'nonce' );

        $title   = sanitize_text_field( $_POST['title'] ?? '' );
        $mermaid = wp_unslash( $_POST['mermaid'] ?? '' );
        $type    = sanitize_text_field( $_POST['type'] ?? 'flowchart' );
        $prompt  = sanitize_textarea_field( $_POST['prompt'] ?? '' );
        $desc    = sanitize_textarea_field( $_POST['description'] ?? '' );
        $post_id = intval( $_POST['post_id'] ?? 0 );

        if ( empty( $mermaid ) ) {
            wp_send_json_error( [ 'message' => 'Mermaid code trống.' ] );
        }

        if ( $post_id > 0 ) {
            // Update existing
            $existing = get_post( $post_id );
            if ( ! $existing || $existing->post_type !== 'bz_mindmap'
                 || ( (int) $existing->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
                wp_send_json_error( [ 'message' => 'Không tìm thấy hoặc không có quyền.' ] );
            }
            wp_update_post( [
                'ID'           => $post_id,
                'post_title'   => $title ?: $existing->post_title,
                'post_content' => $desc  ?: $existing->post_content,
            ] );
        } else {
            // Create new
            $post_id = wp_insert_post( [
                'post_type'    => 'bz_mindmap',
                'post_title'   => $title ?: ( 'Sơ đồ ' . wp_date( 'd/m/Y H:i' ) ),
                'post_content' => $desc ?: $prompt,
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id(),
            ] );

            if ( ! $post_id || is_wp_error( $post_id ) ) {
                wp_send_json_error( [ 'message' => 'Lỗi lưu vào database.' ] );
            }
        }

        update_post_meta( $post_id, '_bz_mermaid_code', $mermaid );
        update_post_meta( $post_id, '_bz_mermaid_type', $type );
        if ( $prompt ) {
            update_post_meta( $post_id, '_bz_prompt', $prompt );
        }

        wp_send_json_success( [
            'post_id' => $post_id,
            'title'   => get_the_title( $post_id ),
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: List mindmaps (paginated)
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_list() {
        check_ajax_referer( 'bztool_mindmap', 'nonce' );

        $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page = 20;

        $q = new WP_Query( [
            'post_type'      => 'bz_mindmap',
            'post_status'    => 'publish',
            'author'         => get_current_user_id(),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $items = [];
        foreach ( $q->posts as $p ) {
            $items[] = [
                'id'          => $p->ID,
                'title'       => $p->post_title,
                'description' => wp_trim_words( $p->post_content, 20 ),
                'type'        => get_post_meta( $p->ID, '_bz_mermaid_type', true ) ?: 'flowchart',
                'date'        => get_the_date( 'd/m/Y H:i', $p ),
                'prompt'      => get_post_meta( $p->ID, '_bz_prompt', true ),
            ];
        }

        wp_send_json_success( [
            'items' => $items,
            'total' => $q->found_posts,
            'pages' => $q->max_num_pages,
            'page'  => $page,
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Get single mindmap
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_get() {
        check_ajax_referer( 'bztool_mindmap', 'nonce' );

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'bz_mindmap' ) {
            wp_send_json_error( [ 'message' => 'Không tìm thấy sơ đồ.' ] );
        }

        if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền truy cập.' ] );
        }

        wp_send_json_success( [
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'description' => $post->post_content,
            'mermaid'     => get_post_meta( $post->ID, '_bz_mermaid_code', true ),
            'type'        => get_post_meta( $post->ID, '_bz_mermaid_type', true ),
            'prompt'      => get_post_meta( $post->ID, '_bz_prompt', true ),
            'date'        => get_the_date( 'd/m/Y H:i', $post ),
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Delete mindmap
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_delete() {
        check_ajax_referer( 'bztool_mindmap', 'nonce' );

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'bz_mindmap'
             || ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền xóa.' ] );
        }

        wp_delete_post( $post_id, true );
        wp_send_json_success( [ 'deleted' => $post_id ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Update mermaid code / title
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_update() {
        check_ajax_referer( 'bztool_mindmap', 'nonce' );

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $mermaid = wp_unslash( $_POST['mermaid'] ?? '' );
        $title   = sanitize_text_field( $_POST['title'] ?? '' );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'bz_mindmap'
             || ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền chỉnh sửa.' ] );
        }

        if ( $title ) {
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
        }
        if ( $mermaid ) {
            update_post_meta( $post_id, '_bz_mermaid_code', $mermaid );
        }

        wp_send_json_success( [ 'post_id' => $post_id ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Upload PNG to WordPress Media Library (R2)
     *
     *  Receives base64-encoded PNG → wp_upload_bits → wp_insert_attachment.
     *  Optionally links to a bz_mindmap post via _bz_mindmap_attachment meta.
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_upload_media() {
        check_ajax_referer( 'bztool_mindmap', 'nonce' );

        $base64  = $_POST['image_data'] ?? '';   // data:image/png;base64,... or raw base64
        $title   = sanitize_text_field( $_POST['title'] ?? 'Mindmap' );
        $post_id = intval( $_POST['post_id'] ?? 0 );

        if ( empty( $base64 ) ) {
            wp_send_json_error( [ 'message' => 'Không có dữ liệu ảnh.' ] );
        }

        // Strip data URI prefix if present
        if ( strpos( $base64, ',') !== false ) {
            $base64 = substr( $base64, strpos( $base64, ',' ) + 1 );
        }

        $decoded = base64_decode( $base64, true );
        if ( ! $decoded || strlen( $decoded ) < 100 ) {
            wp_send_json_error( [ 'message' => 'Dữ liệu ảnh không hợp lệ.' ] );
        }

        // Generate unique filename
        $filename = sanitize_file_name( $title ) . '-' . time() . '.png';

        // Upload via WP (respects S3/R2 offload if configured)
        $upload = wp_upload_bits( $filename, null, $decoded );

        if ( ! empty( $upload['error'] ) ) {
            wp_send_json_error( [ 'message' => 'Lỗi upload: ' . $upload['error'] ] );
        }

        // Create attachment post
        $filetype = wp_check_filetype( $upload['file'] );
        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => $filetype['type'] ?: 'image/png',
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $upload['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => 'Lỗi tạo attachment.' ] );
        }

        // Generate attachment metadata (thumbnail, sizes…)
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        // Link to bz_mindmap post if provided
        if ( $post_id > 0 ) {
            update_post_meta( $post_id, '_bz_mindmap_attachment', $attachment_id );
        }

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => $upload['url'],
            'filename'      => $filename,
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  Helpers
     * ══════════════════════════════════════════════════════════════ */

    /**
     * Extract text from various input formats.
     */
    private static function extract_text( array $slots ): string {
        foreach ( [ 'topic', 'message', 'content' ] as $key ) {
            $val = $slots[ $key ] ?? '';
            if ( is_string( $val ) && $val !== '' ) return trim( $val );
            if ( is_array( $val ) ) {
                $text = $val['text'] ?? $val['caption'] ?? '';
                if ( $text ) return trim( $text );
            }
        }
        return '';
    }

    /**
     * Parse JSON from AI response (handles fences, partial JSON).
     */
    private static function parse_json_response( string $raw ): array {
        if ( empty( $raw ) ) return [];

        $clean = trim( $raw );
        $clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
        $clean = preg_replace( '/```\s*$/', '', $clean );

        $parsed = json_decode( $clean, true );
        if ( is_array( $parsed ) ) return $parsed;

        if ( preg_match( '/\{[\s\S]*\}/', $clean, $m ) ) {
            $parsed = json_decode( $m[0], true );
            if ( is_array( $parsed ) ) return $parsed;
        }

        return [];
    }

    /**
     * Sanitize Mermaid mindmap code — strip characters that break the parser.
     *
     * Mermaid mindmap SPACELIST error is caused by:
     *   - " - " (space-hyphen-space) inside node text → parser reads as list
     *   - sequences of dashes like "---" or "─────" → separator token
     *   - special chars: : :: ( ) [ ] { } # > | ^ ~ * +
     *
     * Only applies line-by-line to non-keyword lines so structure is preserved.
     */
    private static function sanitize_mermaid_mindmap( string $code ): string {
        // Keywords that must not be touched.
        $keywords = [ 'mindmap', 'root', 'flowchart', 'graph', 'sequenceDiagram',
                      'classDiagram', 'stateDiagram', 'erDiagram', 'gantt', 'pie' ];

        $lines  = explode( "\n", $code );
        $result = [];

        foreach ( $lines as $line ) {
            $trimmed = ltrim( $line );

            // Skip blank lines and keyword lines.
            if ( $trimmed === '' ) { $result[] = $line; continue; }
            $is_keyword = false;
            foreach ( $keywords as $kw ) {
                if ( stripos( $trimmed, $kw ) === 0 ) { $is_keyword = true; break; }
            }
            if ( $is_keyword ) { $result[] = $line; continue; }

            // Preserve leading whitespace, sanitize only the text part.
            $indent = strlen( $line ) - strlen( $trimmed );
            $pad    = str_repeat( ' ', $indent );
            $text   = $trimmed;

            // Replace " - " and trailing/leading " -" with " · " (middle dot).
            $text = preg_replace( '/\s+-\s+/', ' · ', $text );
            $text = preg_replace( '/^-\s+/', '', $text );      // leading "- "
            $text = preg_replace( '/\s+-$/', '', $text );      // trailing " -"

            // Remove sequences of 2+ dashes or unicode dashes.
            $text = preg_replace( '/\-{2,}/', '', $text );
            $text = preg_replace( '/[─━\-]{2,}/', '', $text );

            // Remove characters that break Mermaid mindmap parser.
            // Keep alphanumeric, Vietnamese, spaces, single dot, slash, underscore, &, comma, parens only on root.
            $text = preg_replace( '/[:#>|^~*+\\\\]/', '', $text );

            // Collapse multiple spaces.
            $text = preg_replace( '/  +/', ' ', trim( $text ) );

            $result[] = $pad . $text;
        }

        return implode( "\n", $result );
    }
}

BizCity_Tool_Mindmap::init();
