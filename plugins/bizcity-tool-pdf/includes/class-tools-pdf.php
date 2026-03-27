<?php
/**
 * BizCity Tool PDF — Tool Callbacks + AJAX Handlers
 *
 * Generates a print-ready, A4-formatted HTML document from any
 * content type (academic, report, technical, business) using
 * Claude Sonnet 4.5 at 16 000 max_tokens.
 *
 * Architecture mirrors bizcity-tool-landing:
 *   1. Intent Provider registers goal_patterns + slots
 *   2. Tool callback AI-generates HTML → stores as CPT bz_pdf
 *   3. AJAX endpoints serve the view-page SPA
 *   4. Notebook Studio registers via bcn_register_notebook_tools hook
 *
 * @package BizCity_Tool_PDF
 * @since   1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_PDF {

    /* ════════════════════════════════════════════════════════════
     *  Bootstrap
     * ════════════════════════════════════════════════════════════ */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );

        $actions = [ 'generate', 'generate_status', 'save', 'list', 'get', 'delete', 'update' ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_bztool_pdf_{$a}", [ __CLASS__, "ajax_{$a}" ] );
        }
    }

    /* ════════════════════════════════════════════════════════════
     *  CPT: bz_pdf
     * ════════════════════════════════════════════════════════════ */
    public static function register_post_type() {
        register_post_type( 'bz_pdf', [
            'labels'          => [ 'name' => 'PDF Documents', 'singular_name' => 'PDF Document' ],
            'public'          => false,
            'show_ui'         => false,
            'supports'        => [ 'title', 'editor', 'author' ],
            'capability_type' => 'post',
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  Notebook Tool Callback — create_pdf_document
     *
     *  @param array $slots { topic, doc_type, session_id, chat_id, _meta }
     *  @return array Tool Output Envelope
     * ══════════════════════════════════════════════════════════════ */
    public static function create_pdf_document( array $slots ): array {
        $meta       = $slots['_meta']    ?? [];
        $ai_context = $meta['_context']  ?? '';
        $topic      = self::extract_text( $slots );
        $doc_type   = $slots['doc_type'] ?? 'auto';
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']    ?? '';
        $user_id    = get_current_user_id();

        if ( empty( $topic ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Cần mô tả nội dung tài liệu bạn muốn tạo.',
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
                'create_pdf_document',
                [ 'T1' => 'AI tạo tài liệu HTML', 'T2' => 'Lưu tài liệu' ]
            );
        }

        if ( $trace ) $trace->step( 'T1', 'running' );

        error_log( sprintf(
            '[BizCity PDF] create_pdf_document() topic_len=%d doc_type=%s | PREVIEW: %s',
            strlen( $topic ), $doc_type, mb_substr( $topic, 0, 400 )
        ) );

        $result = self::ai_generate_pdf_html( $topic, $doc_type, $ai_context );

        if ( is_wp_error( $result ) ) {
            if ( $trace ) $trace->fail( $result->get_error_message() );
            return [ 'success' => false, 'complete' => false, 'message' => '❌ ' . $result->get_error_message(), 'data' => [] ];
        }

        $title = $result['title']       ?? wp_trim_words( $topic, 8 );
        $html  = $result['html']        ?? '';
        $desc  = $result['description'] ?? wp_trim_words( $topic, 20 );

        if ( $trace ) $trace->step( 'T1', 'done', [ 'title' => $title ] );
        if ( $trace ) $trace->step( 'T2', 'running' );

        $post_id = wp_insert_post( [
            'post_type'    => 'bz_pdf',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => sanitize_textarea_field( $desc ),
            'post_status'  => 'publish',
            'post_author'  => $user_id ?: 1,
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            if ( $trace ) $trace->fail( 'Không thể lưu tài liệu' );
            return [ 'success' => false, 'complete' => false, 'message' => '❌ Lỗi lưu tài liệu vào database.', 'data' => [] ];
        }

        update_post_meta( $post_id, '_bz_pdf_html',  $html );
        update_post_meta( $post_id, '_bz_doc_type',  $doc_type );
        update_post_meta( $post_id, '_bz_prompt',    $topic );

        $view_url  = home_url( '/tool-pdf/?id=' . $post_id );
        $print_url = home_url( '/tool-pdf/print/?id=' . $post_id );

        if ( $trace ) $trace->step( 'T2', 'done', [ 'post_id' => $post_id ] );
        if ( $trace ) $trace->complete( [ 'post_id' => $post_id, 'view_url' => $view_url ] );

        if ( $chat_id && function_exists( 'twf_telegram_send_message' ) ) {
            twf_telegram_send_message( $chat_id, "✅ Đã tạo tài liệu PDF: {$title}\n🔗 {$view_url}" );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã tạo tài liệu: **{$title}**\n"
                . "📄 [Xem & In PDF]({$view_url})",
            'data' => [
                'id'          => $post_id,
                'type'        => 'pdf',
                'title'       => $title,
                'doc_type'    => $doc_type,
                'description' => $desc,
                'url'         => $view_url,
                'print_url'   => $print_url,
                'trace_id'    => $trace ? $trace->get_trace_id() : '',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  AI: Generate print-ready HTML document
     * ══════════════════════════════════════════════════════════════ */
    private static function ai_generate_pdf_html( string $topic, string $doc_type = 'auto', string $ai_context = '' ) {

        $doc_labels = [
            'report'    => 'Báo cáo / Phân tích',
            'academic'  => 'Tài liệu học thuật / Giáo trình',
            'technical' => 'Tài liệu kỹ thuật / Kiến trúc hệ thống',
            'proposal'  => 'Đề xuất / Dự án',
            'summary'   => 'Tóm tắt điều hành / Executive Summary',
            'guide'     => 'Hướng dẫn / Manual',
            'auto'      => 'AI tự xác định phù hợp nội dung',
        ];
        $doc_hint = 'Loại tài liệu: ' . ( $doc_labels[ $doc_type ] ?? $doc_labels['auto'] );

        $sys = <<<'SYSPDF'
Bạn là chuyên gia thiết kế tài liệu kỹ thuật số chuẩn in ấn. Tạo HTML+CSS single-file, đẹp khi xem trên trình duyệt và in sạch thành PDF A4.

═══ NGUYÊN TẮC ═══
1. Single-file HTML — CSS trong <style>, KHÔNG CDN ngoài trừ Google Fonts
2. Google Fonts: "Inter" + "Source Serif 4" (hoặc "IBM Plex Sans" cho technical)
3. KHÔNG dùng ảnh background hay placeholder — tài liệu phải in được
4. Dùng emoji/icon unicode thay cho ảnh
5. Nội dung tiếng Việt, chuyên nghiệp, đầy đủ

═══ CSS BẮT BUỘC ═══
Đầu <style> phải có:

/* Screen */
:root {
  --blue: #1e3a5f; --accent: #2563EB; --text: #1f2937;
  --muted: #6b7280; --border: #e5e7eb; --bg: #f8fafc;
  --surface: #ffffff;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text);
       font-size: 14px; line-height: 1.7; }
.doc-wrap { max-width: 900px; margin: 0 auto; background: var(--surface);
            box-shadow: 0 0 40px rgba(0,0,0,.08); min-height: 100vh; padding: 40px 60px; }
h1 { font-size: 2em; font-weight: 800; color: var(--blue); line-height: 1.2; margin-bottom: .4em; }
h2 { font-size: 1.35em; font-weight: 700; color: var(--blue); margin: 2em 0 .6em;
     padding-bottom: .3em; border-bottom: 2px solid var(--accent); }
h3 { font-size: 1.1em; font-weight: 600; color: var(--blue); margin: 1.4em 0 .4em; }
p { margin-bottom: 1em; }
ul, ol { margin: .6em 0 1em 1.4em; }
li { margin-bottom: .35em; }
strong { font-weight: 700; }
blockquote { border-left: 4px solid var(--accent); padding: 12px 20px;
             background: #EFF6FF; border-radius: 0 8px 8px 0; margin: 1em 0;
             font-style: italic; color: #1e40af; }
.highlight { background: #EFF6FF; border-left: 4px solid var(--accent);
             padding: 14px 18px; border-radius: 0 8px 8px 0; margin: 1em 0; }
.card-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr));
             gap: 16px; margin: 1.2em 0; }
.card { background: #F9FAFB; border: 1px solid var(--border); border-radius: 12px;
        padding: 16px; transition: box-shadow .2s; }
.card:hover { box-shadow: 0 4px 16px rgba(37,99,235,.12); }
.card-icon { font-size: 1.8em; margin-bottom: 8px; }
.card-title { font-weight: 700; color: var(--blue); margin-bottom: 6px; }
.meta-row { display: flex; gap: 24px; flex-wrap: wrap; color: var(--muted);
            font-size: .85em; margin: .6em 0 2em; }
.meta-row span { display: flex; align-items: center; gap: 5px; }
.toc { background: #F0F9FF; border: 1px solid #BAE6FD; border-radius: 12px;
       padding: 20px 28px; margin: 1.5em 0 2.5em; }
.toc h2 { border: none; margin-top: 0; font-size: 1em; }
.toc ol { margin-left: 1.2em; }
.toc a { color: var(--accent); text-decoration: none; }
.toc a:hover { text-decoration: underline; }
table { width: 100%; border-collapse: collapse; margin: 1em 0; font-size: .9em; }
th { background: var(--blue); color: #fff; padding: 10px 14px; text-align: left; }
td { padding: 9px 14px; border-bottom: 1px solid var(--border); }
tr:nth-child(even) td { background: #F9FAFB; }
.section-divider { border: none; border-top: 1px solid var(--border); margin: 2.5em 0; }
.print-btn {
  position: fixed; bottom: 28px; right: 28px; z-index: 100;
  background: linear-gradient(135deg,#1e3a5f,#2563EB); color: #fff;
  border: none; border-radius: 50px; padding: 14px 28px;
  font-size: 15px; font-weight: 700; cursor: pointer;
  box-shadow: 0 8px 24px rgba(37,99,235,.4);
  display: flex; align-items: center; gap: 8px;
  transition: transform .15s, box-shadow .15s;
}
.print-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(37,99,235,.5); }
.doc-header { border-bottom: 3px solid var(--blue); padding-bottom: 24px; margin-bottom: 32px; }
.doc-footer-bar {
  margin-top: 4em; border-top: 1px solid var(--border);
  padding-top: 16px; color: var(--muted); font-size: .82em;
  display: flex; justify-content: space-between;
}

/* Print */
@page { size: A4; margin: 18mm 22mm 20mm 22mm; }
@media print {
  .print-btn, .no-print { display: none !important; }
  body { background: #fff; font-size: 11pt; }
  .doc-wrap { max-width: 100%; box-shadow: none; padding: 0; }
  h1 { font-size: 20pt; }
  h2 { font-size: 14pt; }
  h3 { font-size: 12pt; }
  h1, h2, h3 { page-break-after: avoid; }
  p, li { orphans: 3; widows: 3; }
  table, .card-grid { page-break-inside: avoid; }
  .page-break { page-break-before: always; }
  blockquote, .highlight { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  a { color: #000; }
  a[href^="http"]::after { content: " <" attr(href) ">"; font-size: .75em; color: #555; }
}

SYSPDF;

        $sys .= <<<'SYSSTRUCT'
═══ CẤU TRÚC TÀI LIỆU (BẮT BUỘC) ═══

1. HEADER TÀI LIỆU <div class="doc-header">
   - Tiêu đề H1 to, đậm
   - Meta row: 📅 Ngày · ✍️ Tác giả/Nguồn · 📋 Loại tài liệu · 🏷 Tags
   - Tóm tắt 2-3 câu (abstract)

2. MỤC LỤC <div class="toc"> (nếu nội dung > 3 section)
   - Anchor links xuống từng section

3. OVERVIEW / TỔNG QUAN
   - Card grid (3-4 cards) tóm tắt điểm chính

4. CÁC SECTION CHÍNH (2-6 sections, dùng <section id="...">)
   - H2 heading với ID để TOC anchor
   - Nội dung chi tiết: paragraphs, lists, highlights, tables, blockquotes
   - Mỗi section có ít nhất 1 element visual (highlight box / table / card)

5. KẾT LUẬN / TỔNG KẾT
   - Card grid kết quả / insight / action items

6. FOOTER
   <div class="doc-footer-bar">
     - Bên trái: tên tài liệu + năm
     - Bên phải: nguồn/tác giả

7. SCRIPT in ấn (cuối body):
   <script>
   document.querySelector('.print-btn')
     .addEventListener('click', function(){ window.print(); });
   </script>

═══ QUY TẮC FORMAT ═══
- Tiếng Việt, nội dung đầy đủ, không tóm tắt quá mức
- Dùng <strong> cho keyword, <code> cho thuật ngữ kỹ thuật
- KHÔNG dùng Tailwind hay CSS framework ngoài — chỉ custom CSS đã viết ở trên
- Đảm bảo HTML đóng thẻ sạch sẽ
- Nút in PHẢI có class="print-btn" và type="button"

═══ FORMAT OUTPUT ═══
Chỉ trả HTML thuần, bắt đầu <!DOCTYPE html>, kết thúc </html>.
KHÔNG JSON, KHÔNG markdown fence, KHÔNG giải thích.
SYSSTRUCT;

        if ( $ai_context ) {
            $sys .= "\n\n═══ NGỮ CẢNH DỰ ÁN ═══\n" . $ai_context;
        }

        $prompt = "{$doc_hint}\n\nNội dung tài liệu:\n{$topic}\n\n"
            . "Tạo tài liệu HTML hoàn chỉnh, chuẩn in A4, từ nội dung trên. Chỉ xuất HTML.";

        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys ],
            [ 'role' => 'user',   'content' => $prompt ],
        ], [
            'model'       => 'anthropic/claude-sonnet-4-5',
            'purpose'     => 'pdf_document',
            'temperature' => 0.6,
            'max_tokens'  => 16000,
            'timeout'     => 180,
        ] );

        if ( empty( $ai_result['success'] ) || empty( $ai_result['message'] ) ) {
            return new WP_Error( 'api_failed', 'AI API lỗi: ' . ( $ai_result['error'] ?? 'no response' ) );
        }

        $html = trim( $ai_result['message'] );
        $html = preg_replace( '/^```(?:html)?\s*/i', '', $html );
        $html = preg_replace( '/```\s*$/', '', trim( $html ) );

        if ( stripos( $html, '<html' ) === false ) {
            return new WP_Error( 'ai_failed', 'AI không trả về HTML hợp lệ. Preview: ' . substr( $html, 0, 200 ) );
        }

        $title = wp_trim_words( $topic, 8 );
        if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
            $title = trim( html_entity_decode( $m[1], ENT_QUOTES ) );
        }
        $description = wp_trim_words( $topic, 30 );
        if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/i', $html, $md ) ) {
            $description = trim( html_entity_decode( $md[1], ENT_QUOTES ) );
        }

        return [ 'title' => $title, 'html' => $html, 'description' => $description ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: generate (async background like landing page)
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_generate() {
        check_ajax_referer( 'bztool_pdf', 'nonce' );

        $prompt   = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
        $doc_type = sanitize_text_field( $_POST['doc_type'] ?? 'auto' );

        if ( empty( $prompt ) ) {
            wp_send_json_error( [ 'message' => 'Cần nhập mô tả nội dung tài liệu.' ] );
        }
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            wp_send_json_error( [ 'message' => 'AI chưa sẵn sàng (OpenRouter).' ] );
        }

        // Store job in transient; actual processing below (same-request, fastcgi_finish_request pattern)
        $job_id   = bin2hex( random_bytes( 16 ) );
        $user_id  = get_current_user_id();
        $job_data = [ 'status' => 'processing', 'job_id' => $job_id, 'started' => time() ];
        set_transient( 'bz_pdf_job_' . $job_id, $job_data, 600 );

        wp_send_json_success( [ 'job_id' => $job_id, 'status' => 'processing' ] );

        // Close HTTP connection and continue processing in background
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        }

        $result = self::ai_generate_pdf_html( $prompt, $doc_type );

        if ( is_wp_error( $result ) ) {
            set_transient( 'bz_pdf_job_' . $job_id,
                [ 'status' => 'failed', 'message' => $result->get_error_message() ], 300 );
            return;
        }

        $post_id = wp_insert_post( [
            'post_type'    => 'bz_pdf',
            'post_title'   => sanitize_text_field( $result['title'] ),
            'post_content' => sanitize_textarea_field( $result['description'] ),
            'post_status'  => 'publish',
            'post_author'  => $user_id ?: 1,
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            set_transient( 'bz_pdf_job_' . $job_id,
                [ 'status' => 'failed', 'message' => 'Lỗi lưu vào database.' ], 300 );
            return;
        }

        update_post_meta( $post_id, '_bz_pdf_html', $result['html'] );
        update_post_meta( $post_id, '_bz_doc_type', $doc_type );
        update_post_meta( $post_id, '_bz_prompt',   $prompt );

        set_transient( 'bz_pdf_job_' . $job_id, [
            'status'   => 'completed',
            'post_id'  => $post_id,
            'title'    => $result['title'],
            'doc_type' => $doc_type,
            'url'      => home_url( '/tool-pdf/?id=' . $post_id ),
        ], 300 );
    }

    /* ── Poll job status ─────────────────────────────────────────── */
    public static function ajax_generate_status() {
        check_ajax_referer( 'bztool_pdf', 'nonce' );
        $job_id = sanitize_text_field( $_POST['job_id'] ?? '' );
        if ( ! $job_id ) { wp_send_json_error( [ 'message' => 'Missing job_id.' ] ); }
        $job = get_transient( 'bz_pdf_job_' . $job_id );
        if ( ! $job ) { wp_send_json_error( [ 'message' => 'Job not found.' ] ); }
        wp_send_json_success( $job );
    }

    /* ── Save (create / update) ─────────────────────────────────── */
    public static function ajax_save() {
        check_ajax_referer( 'bztool_pdf', 'nonce' );

        $title    = sanitize_text_field( $_POST['title'] ?? '' );
        $html     = wp_unslash( $_POST['html'] ?? '' );
        $doc_type = sanitize_text_field( $_POST['doc_type'] ?? 'auto' );
        $prompt   = sanitize_textarea_field( $_POST['prompt'] ?? '' );
        $desc     = sanitize_textarea_field( $_POST['description'] ?? '' );
        $post_id  = intval( $_POST['post_id'] ?? 0 );

        if ( empty( $html ) ) { wp_send_json_error( [ 'message' => 'Nội dung HTML trống.' ] ); }

        if ( $post_id > 0 ) {
            $existing = get_post( $post_id );
            if ( ! $existing || $existing->post_type !== 'bz_pdf'
                 || ( (int) $existing->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
                wp_send_json_error( [ 'message' => 'Không tìm thấy hoặc không có quyền.' ] );
            }
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ?: $existing->post_title ] );
        } else {
            $post_id = wp_insert_post( [
                'post_type'    => 'bz_pdf',
                'post_title'   => $title ?: ( 'Tài liệu ' . wp_date( 'd/m/Y H:i' ) ),
                'post_content' => $desc ?: $prompt,
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id(),
            ] );
            if ( ! $post_id || is_wp_error( $post_id ) ) {
                wp_send_json_error( [ 'message' => 'Lỗi lưu vào database.' ] );
            }
        }

        update_post_meta( $post_id, '_bz_pdf_html', $html );
        update_post_meta( $post_id, '_bz_doc_type', $doc_type );
        if ( $prompt ) update_post_meta( $post_id, '_bz_prompt', $prompt );

        wp_send_json_success( [
            'post_id' => $post_id,
            'url'     => home_url( '/tool-pdf/?id=' . $post_id ),
        ] );
    }

    /* ── List ───────────────────────────────────────────────────── */
    public static function ajax_list() {
        check_ajax_referer( 'bztool_pdf', 'nonce' );

        $paged = max( 1, intval( $_POST['page'] ?? 1 ) );
        $query = new WP_Query( [
            'post_type'      => 'bz_pdf',
            'post_status'    => 'publish',
            'posts_per_page' => 12,
            'paged'          => $paged,
            'author'         => get_current_user_id(),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $items = [];
        foreach ( $query->posts as $post ) {
            $items[] = [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'desc'     => wp_trim_words( $post->post_content, 20 ),
                'doc_type' => get_post_meta( $post->ID, '_bz_doc_type', true ) ?: 'auto',
                'date'     => get_the_date( 'd/m/Y H:i', $post->ID ),
                'url'      => home_url( '/tool-pdf/?id=' . $post->ID ),
            ];
        }
        wp_send_json_success( [ 'items' => $items, 'total' => $query->found_posts, 'pages' => $query->max_num_pages ] );
    }

    /* ── Get single ─────────────────────────────────────────────── */
    public static function ajax_get() {
        check_ajax_referer( 'bztool_pdf', 'nonce' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $post    = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || $post->post_type !== 'bz_pdf' ) {
            wp_send_json_error( [ 'message' => 'Không tìm thấy.' ] );
        }
        wp_send_json_success( [
            'id'       => $post->ID,
            'title'    => $post->post_title,
            'html'     => get_post_meta( $post->ID, '_bz_pdf_html', true ),
            'doc_type' => get_post_meta( $post->ID, '_bz_doc_type', true ),
            'prompt'   => get_post_meta( $post->ID, '_bz_prompt', true ),
            'date'     => get_the_date( 'd/m/Y H:i', $post->ID ),
            'url'      => home_url( '/tool-pdf/?id=' . $post->ID ),
        ] );
    }

    /* ── Delete ─────────────────────────────────────────────────── */
    public static function ajax_delete() {
        check_ajax_referer( 'bztool_pdf', 'nonce' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $post    = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || $post->post_type !== 'bz_pdf'
             || ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => 'Không tìm thấy hoặc không có quyền.' ] );
        }
        wp_delete_post( $post_id, true );
        wp_send_json_success( [ 'deleted' => $post_id ] );
    }

    /* ── Update (title only) ─────────────────────────────────────── */
    public static function ajax_update() {
        check_ajax_referer( 'bztool_pdf', 'nonce' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $title   = sanitize_text_field( $_POST['title'] ?? '' );
        $post    = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || $post->post_type !== 'bz_pdf'
             || ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => 'Không tìm thấy hoặc không có quyền.' ] );
        }
        if ( $title ) { wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] ); }
        wp_send_json_success( [ 'updated' => $post_id ] );
    }

    /* ── Helpers ─────────────────────────────────────────────────── */
    private static function extract_text( array $slots ): string {
        foreach ( [ 'topic', 'message', 'content' ] as $key ) {
            $val = $slots[ $key ] ?? '';
            if ( is_string( $val ) && $val !== '' ) return trim( $val );
            if ( is_array( $val ) ) {
                $t = $val['text'] ?? $val['caption'] ?? '';
                if ( $t ) return trim( $t );
            }
        }
        return '';
    }
}

BizCity_Tool_PDF::init();
