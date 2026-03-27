<?php
/**
 * BizCity Tool Landing Page — Tool Callbacks + AJAX Handlers
 *
 * Kiến trúc "Developer-packaged Pipeline":
 *   1. Intent Provider khai báo goal_patterns + required_slots
 *   2. Tool callback tạo HTML landing page bằng AI → lưu thành CPT bz_landing
 *   3. AJAX endpoints phục vụ trang views SPA (generate, save, list, get, delete, update, upload_media)
 *
 * @package BizCity_Tool_Landing
 * @since   1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Landing {

    /* ════════════════════════════════════════════════════════════
     *  Bootstrap
     * ════════════════════════════════════════════════════════════ */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );

        // AJAX handlers cho view page SPA
        $actions = [ 'generate', 'generate_status', 'save', 'list', 'get', 'delete', 'update', 'upload_media' ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_bztool_lp_{$a}", [ __CLASS__, "ajax_{$a}" ] );
        }
    }

    /* ════════════════════════════════════════════════════════════
     *  Custom Post Type: bz_landing
     * ════════════════════════════════════════════════════════════ */
    public static function register_post_type() {
        register_post_type( 'bz_landing', [
            'labels' => [
                'name'          => 'Landing Pages',
                'singular_name' => 'Landing Page',
            ],
            'public'          => false,
            'show_ui'         => false,
            'supports'        => [ 'title', 'editor', 'author' ],
            'capability_type' => 'post',
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  Intent Engine Tool Callback — create_landing
     *
     *  Flow:
     *    T1: AI phân tích prompt → tạo HTML landing page
     *    T2: Lưu vào CPT bz_landing + post_meta
     *
     *  @param array $slots { topic, product_type, template, session_id, chat_id, _meta }
     *  @return array Tool Output Envelope
     * ══════════════════════════════════════════════════════════════ */
    public static function create_landing( array $slots ): array {
        $meta         = $slots['_meta']    ?? [];
        $ai_context   = $meta['_context']  ?? '';
        $topic        = self::extract_text( $slots );
        $product_type = $slots['product_type'] ?? 'other';
        $template     = $slots['template']     ?? 'hero-cta';
        $session_id   = $slots['session_id']   ?? '';
        $chat_id      = $slots['chat_id']      ?? '';
        $user_id      = get_current_user_id();

        if ( empty( $topic ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Cần mô tả sản phẩm/dịch vụ bạn muốn tạo landing page.',
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
                'create_landing',
                [
                    'T1' => 'AI thiết kế landing page HTML',
                    'T2' => 'Lưu landing page',
                ]
            );
        }

        // ══════════════════════════════════════════════════════
        //  T1: AI generate Landing Page HTML
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T1', 'running' );

        // ── Intent Classification (fast, cheap LLM) ──
        $intent = self::classify_content_intent( $topic );

        // Debug trace — visible in WP debug log (wp-content/debug.log).
        error_log( sprintf(
            '[BizCity Landing] create_landing() fired. topic_len=%d, product_type=%s, template=%s, intent=%s | TOPIC_PREVIEW: %s',
            strlen( $topic ),
            $product_type,
            $template,
            $intent,
            mb_substr( $topic, 0, 600 )
        ) );

        $result = self::ai_generate_landing( $topic, $product_type, $template, $ai_context, $intent );

        if ( is_wp_error( $result ) ) {
            if ( $trace ) $trace->fail( $result->get_error_message() );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ ' . $result->get_error_message(),
                'data'    => [],
            ];
        }

        $title = $result['title'] ?? wp_trim_words( $topic, 8 );
        $html  = $result['html']  ?? '';
        $desc  = $result['description'] ?? $topic;

        if ( $trace ) $trace->step( 'T1', 'done', [ 'title' => $title ] );

        // ══════════════════════════════════════════════════════
        //  T2: Save to bz_landing CPT
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T2', 'running' );

        $post_id = wp_insert_post( [
            'post_type'    => 'bz_landing',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => sanitize_textarea_field( $desc ),
            'post_status'  => 'publish',
            'post_author'  => $user_id ?: 1,
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            if ( $trace ) $trace->step( 'T2', 'failed' );
            if ( $trace ) $trace->fail( 'Không thể lưu landing page' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Lỗi khi lưu landing page vào database.',
                'data'    => [],
            ];
        }

        update_post_meta( $post_id, '_bz_landing_html', $html );
        update_post_meta( $post_id, '_bz_landing_type', $product_type );
        update_post_meta( $post_id, '_bz_landing_template', $template );
        update_post_meta( $post_id, '_bz_prompt', $topic );

        // ── Nếu user có quyền publish_pages → tạo thêm WP Page thật ──
        $wp_page_id  = null;
        $wp_page_url = null;
        if ( current_user_can( 'publish_pages' ) || current_user_can( 'manage_options' ) ) {
            $wp_result   = self::create_wp_page( $title, $html, $desc, $topic );
            $wp_page_id  = is_wp_error( $wp_result ) ? null : $wp_result['page_id'];
            $wp_page_url = is_wp_error( $wp_result ) ? null : $wp_result['url'];
            if ( $wp_page_id ) {
                update_post_meta( $post_id, '_bz_linked_page_id', $wp_page_id );
            }
        }

        $view_url = $wp_page_url ?: home_url( '/tool-landing/?id=' . $post_id );

        if ( $trace ) $trace->step( 'T2', 'done', [ 'post_id' => $post_id ] );
        if ( $trace ) $trace->complete( [ 'post_id' => $post_id, 'view_url' => $view_url ] );

        // ── Notify Telegram nếu từ Telegram ──
        if ( $chat_id && function_exists( 'twf_telegram_send_message' ) ) {
            twf_telegram_send_message( $chat_id, "✅ Đã tạo landing page: {$title}\n🚀 Loại: {$product_type}\n🔗 {$view_url}" );
        }

        $page_note = $wp_page_url
            ? "📄 Đã tạo **WordPress Page** thật — có thể chỉnh sửa bằng Flatsome Builder."
            : "📄 HTML preview tại link trên (chưa có quyền tạo page WordPress).";

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã tạo landing page: **{$title}**\n"
                . "🚀 Loại: {$product_type}\n"
                . "🔗 [Xem & Chỉnh sửa]({$view_url})\n\n"
                . $page_note,
            'data' => [
                'id'             => $post_id,
                'type'           => 'landing_page',
                'product_type'   => $product_type,
                'template'       => $template,
                'title'          => $title,
                'description'    => $desc,
                'url'            => $view_url,
                'wp_page_id'     => $wp_page_id,
                'wp_page_url'    => $wp_page_url,
                'source_chars'   => strlen( $topic ),
                'topic_preview'  => mb_substr( $topic, 0, 300 ) . ( strlen( $topic ) > 300 ? '…' : '' ),
                'trace_id'       => $trace ? $trace->get_trace_id() : '',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  Create Real WordPress Page (with optional CF7 form)
     *
     *  - Inserts a page post type with the landing HTML as content
     *  - If Contact Form 7 is active, creates a CF7 form and injects
     *    its shortcode in place of the first <form> in the HTML
     *  - If Flatsome is active, the page is immediately editable in UX Builder
     *
     *  @return array|WP_Error { page_id, url, cf7_form_id }
     * ══════════════════════════════════════════════════════════════ */
    private static function create_wp_page( string $title, string $html, string $desc, string $prompt ) {
        $user_id = get_current_user_id() ?: 1;

        // ── Optionally create CF7 form ──
        $cf7_form_id = null;
        if ( function_exists( 'wpcf7' ) || class_exists( 'WPCF7_ContactForm' ) ) {
            $cf7_form_id = self::create_cf7_form( $title );
        }

        // ── Extract body + head styles; wrap in Gutenberg raw HTML block ──
        $page_content = self::extract_body_content( $html );

        // ── Append CF7 shortcode OUTSIDE the wp:html block so it is never
        //    trapped inside unclosed pricing/grid divs from the AI output ──
        if ( $cf7_form_id && ! is_wp_error( $cf7_form_id ) ) {
            $page_content .= "\n\n<!-- Contact Form -->\n[contact-form-7 id=\"{$cf7_form_id}\" title=\"{$title}\"]\n\n";
        }

        $page_id = wp_insert_post( [
            'post_type'    => 'page',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => $page_content,
            'post_excerpt' => '',
            'post_status'  => 'publish',
            'post_author'  => $user_id,
            // Use blank/full-width template if available
            'page_template' => self::detect_blank_template(),
        ] );

        if ( ! $page_id || is_wp_error( $page_id ) ) {
            return new WP_Error( 'page_create_failed', 'Không thể tạo WordPress page.' );
        }

        // ── Store original HTML and prompt for reference ──
        update_post_meta( $page_id, '_bz_landing_html', $html );
        update_post_meta( $page_id, '_bz_prompt', $prompt );
        if ( $cf7_form_id ) {
            update_post_meta( $page_id, '_bz_cf7_form_id', $cf7_form_id );
        }

        // ── Flatsome UX Builder: mark as custom layout so UX Builder activates ──
        if ( self::is_flatsome_active() ) {
            update_post_meta( $page_id, '_flatsome_layout', 'default' );
        }

        return [
            'page_id'    => $page_id,
            'url'        => get_permalink( $page_id ),
            'cf7_form_id' => $cf7_form_id,
        ];
    }

    /**
     * Extract <body>…</body> content from a full HTML document.
     * Also salvages <style> blocks and CDN <script> tags from <head> so
     * custom CSS (gradient-bg, etc.) and Tailwind CDN are not lost.
     * Wraps the result in a Gutenberg raw HTML block so WordPress does
     * not run wpautop() on the markup.
     */
    private static function extract_body_content( string $html ): string {
        $prepend = '';

        // Pull <style> + CDN <script> tags from <head>
        if ( preg_match( '/<head[^>]*>(.*?)<\/head>/si', $html, $head_m ) ) {
            $head = $head_m[1];
            // Inline <style> blocks
            if ( preg_match_all( '/<style[^>]*>.*?<\/style>/si', $head, $sm ) ) {
                $prepend .= implode( "\n", $sm[0] ) . "\n";
            }
            // External CDN <script> tags (Tailwind, etc.) — skip inline scripts
            if ( preg_match_all( '/<script[^>]+src=["\'][^"\'>]+["\'][^>]*><\/script>/si', $head, $scm ) ) {
                $prepend .= implode( "\n", $scm[0] ) . "\n";
            }
        }

        $body = $html; // fallback: no <body> tags
        if ( preg_match( '/<body[^>]*>(.*)<\/body>/si', $html, $m ) ) {
            $body = trim( $m[1] );
        }

        $content = $prepend . $body;

        // Wrap in Gutenberg raw HTML block → bypasses wpautop completely
        return "<!-- wp:html -->\n" . $content . "\n<!-- /wp:html -->";
    }

    /**
     * Create a Contact Form 7 contact form programmatically.
     * Returns the post_id of the new form, or null if CF7 not available.
     */
    private static function create_cf7_form( string $title ): ?int {
        if ( ! class_exists( 'WPCF7_ContactForm' ) ) return null;

        $form_title = sanitize_text_field( $title ) . ' — Liên hệ';
        $form_content = '<p>' . "\n"
            . '    <label> Họ và tên<br>' . "\n"
            . '        [text* your-name autocomplete:name] </label>' . "\n"
            . '</p>' . "\n\n"
            . '<p>' . "\n"
            . '    <label> Email<br>' . "\n"
            . '        [email* your-email autocomplete:email] </label>' . "\n"
            . '</p>' . "\n\n"
            . '<p>' . "\n"
            . '    <label> Số điện thoại<br>' . "\n"
            . '        [tel your-phone autocomplete:tel] </label>' . "\n"
            . '</p>' . "\n\n"
            . '<p>' . "\n"
            . '    <label> Tin nhắn<br>' . "\n"
            . '        [textarea your-message] </label>' . "\n"
            . '</p>' . "\n\n"
            . '<p>[submit "Gửi thông tin"]</p>';

        $mail_body = "Từ: [your-name] <[your-email]>\nSĐT: [your-phone]\n\n[your-message]\n\n--\nGửi từ: [_site_title] ([_site_url])";

        $args = [
            'title'           => $form_title,
            'form'            => $form_content,
            'mail'            => [
                'subject'     => '[' . get_bloginfo('name') . '] ' . $form_title,
                'sender'      => '[your-name] <[your-email]>',
                'body'        => $mail_body,
                'recipient'   => get_option('admin_email'),
                'additional_headers' => 'Reply-To: [your-email]',
                'attachments' => '',
                'use_html'    => false,
                'exclude_blank' => false,
            ],
        ];

        $form = WPCF7_ContactForm::get_template( $args );
        $form->save();
        return $form->id();
    }

    /**
     * Detect a blank/full-width page template filename from the active theme.
     */
    private static function detect_blank_template(): string {
        $candidates = [
            'page-blank-landingpage.php',   // Flatsome: No Header / No Footer — ideal for landing pages
            'template-blank.php',
            'templates/template-blank.php',
            'page-blank.php',
            'page-fullwidth.php',
            'page-full-width.php',
            'template-fullwidth.php',
        ];
        $theme_dir = get_template_directory();
        foreach ( $candidates as $tpl ) {
            if ( file_exists( $theme_dir . '/' . $tpl ) ) return $tpl;
        }
        return ''; // default page template
    }

    /**
     * Check if Flatsome theme is active.
     */
    private static function is_flatsome_active(): bool {
        $theme = wp_get_theme();
        return in_array( strtolower( $theme->get_template() ), [ 'flatsome', 'flatsome-child' ], true );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AI: Generate Landing Page HTML
     *
     *  Uses Claude Sonnet 4.6 for high-quality HTML+CSS output.
     *  System prompt incorporates the full Landing Page Design System.
     * ══════════════════════════════════════════════════════════════ */
    private static function ai_generate_landing( string $topic, string $product_type = 'other', string $template = 'hero-cta', string $ai_context = '', string $intent = 'business' ) {

        // ── Academic / Report / Technical → non-sales template ──
        if ( in_array( $intent, [ 'academic', 'report', 'technical' ], true ) ) {
            return self::ai_generate_academic_page( $topic, $intent, $ai_context );
        }

        $color_palettes = self::get_color_palette( $product_type );
        $template_hint  = self::get_template_hint( $template );

        $sys = <<<'SYSTEM'
Bạn là chuyên gia thiết kế landing page chuyển đổi cao. Bạn tạo HTML+CSS single-file hoàn chỉnh, chuyên nghiệp, responsive, tối ưu conversion.

═══ NGUYÊN TẮC THIẾT KẾ ═══
1. Mobile-first responsive (320px → 1440px)
2. Single-file: HTML + CSS inline (<style> trong <head>) — KHÔNG dùng file CSS/JS ngoài trừ CDN
3. Sử dụng Tailwind CSS CDN: <script src="https://cdn.tailwindcss.com"></script>
4. Font: Google Fonts CDN (chọn font phù hợp ngành)
5. Hình ảnh: dùng placeholder URL https://placehold.co/{width}x{height}/{bg_hex}/{text_hex}?text={text} — LUÔN có text mô tả
6. Icon: dùng emoji hoặc SVG inline

═══ CẤU TRÚC LANDING PAGE ═══
Mỗi landing page PHẢI có các section sau (theo thứ tự):

1. NAVBAR — Logo + menu anchor links + CTA button (sticky top)
2. HERO — Headline (H1), sub-headline, CTA button chính, hero image/visual
3. SOCIAL PROOF — Logo partners, số liệu thống kê, badges tin cậy
4. FEATURES/BENEFITS — Grid 3-4 cột, icon + title + description
5. HOW IT WORKS — Steps 1-2-3, timeline hoặc process flow
6. TESTIMONIALS — Customer quotes + avatar + name + role, carousel hoặc grid
7. PRICING — 2-3 tiers, highlight popular plan, CTA mỗi plan
8. FAQ — Accordion expandable (pure CSS hoặc minimal JS)
9. FINAL CTA — Urgency message, form hoặc button lớn
10. FOOTER — Links, contact info, social media, copyright

═══ COPYWRITING FORMULAS ═══
- Headline: Dùng PAS (Problem-Agitate-Solve) hoặc AIDA
- CTA buttons: Action verb + Benefit (e.g., "Bắt đầu miễn phí", "Nhận ưu đãi ngay")
- Testimonials: Cụ thể, có số liệu, tên thật
- Urgency: Countdown, limited spots, bonus hết hạn

═══ CSS TECHNIQUES ═══
- Glass-morphism: backdrop-filter: blur(16px); background: rgba(255,255,255,.08)
- Gradient text: background-clip: text; -webkit-background-clip: text; color: transparent
- Smooth scroll: html { scroll-behavior: smooth }
- Hover transitions: transition: all 0.3s ease
- Box shadows: Layered cho depth
- Animation: @keyframes cho hero elements (fadeIn, slideUp)
- Responsive grid: Tailwind grid + custom breakpoints

═══ CHẤT LƯỢNG ═══
- Giống trang thật của agency chuyên nghiệp, KHÔNG giống template rẻ tiền
- Spacing chuẩn: section padding 80-120px vertical
- Typography hierarchy rõ ràng: H1 > H2 > H3 > p
- Color contrast đủ cho accessibility (WCAG AA)
- Loading performance: Tailwind CDN + Google Fonts + inline CSS only
- Chú ý chi tiết: border-radius, line-height, letter-spacing
- Interactive elements: hover states, focus styles

═══ FAQ SECTION ═══
Sử dụng phương pháp PURE CSS (<details>/<summary>) cho accordion.
- Dùng thẻ <details> và <summary> cho mỗi câu hỏi
- Styling <summary> với cursor pointer, padding, border-bottom
- Nội dung trả lời nằm trong <p> bên trong <details>
- KHÔNG cần JavaScript cho phần FAQ

═══ QUAN TRỌNG — CONTRAST & TEXT COLOR ═══
Thêm vào đầu <style> tag BẮT BUỘC:
  body { color: #1f2937 !important; background: #ffffff; font-family: inherit; }
  body * { color: inherit; }
- Gradient text (background-clip:text; color:transparent) CHỈ được dùng trong H1/H2/H3, KHÔNG BAO GIỜ áp dụng lên body text, paragraph, li, a.
- Section body text: luôn set color tường minh (dark section → color:#fff; light section → color:#1f2937).
- Không dùng color:inherit cho text trực tiếp — set explicit.

═══ QUAN TRỌNG — FORM ISOLATION ═══
- KHÔNG đặt form hay shortcode contact trong section pricing.
- Phần cuối body phải đóng tất cả </div> và </section> sạch sẽ trước </body>.
- Thêm <!-- CONTACT_FORM --> comment ngay trước </body> nếu muốn đánh dấu vị trí.

═══ FORMAT OUTPUT ═══
Chỉ trả về HTML code thuần túy, bắt đầu bằng <!DOCTYPE html> và kết thúc bằng </html>.
KHÔNG bọc trong JSON, KHÔNG giải thích, KHÔNG dùng code fence markdown.
Chỉ xuất code HTML, không có gì khác.

QUAN TRỌNG:
- Phải là single-file HTML hoàn chỉnh (<!DOCTYPE html> đến </html>)
- Tất cả CSS trong <style> tag hoặc Tailwind classes
- Nội dung tiếng Việt, chuyên nghiệp
- KHÔNG link tới file ngoài (trừ CDN: Tailwind, Google Fonts, placeholder images)
SYSTEM;

        // Thêm color palette hint
        if ( $color_palettes ) {
            $sys .= "\n\n═══ BẢNG MÀU ĐỀ XUẤT ═══\n" . $color_palettes;
        }

        // Thêm template pattern hint
        if ( $template_hint ) {
            $sys .= "\n\n═══ CONVERSION PATTERN ═══\n" . $template_hint;
        }

        if ( $ai_context ) {
            $sys .= "\n\n" . $ai_context;
        }

        $type_label = self::get_product_type_label( $product_type );
        $prompt = "Loại sản phẩm: {$type_label}\n"
            . "Conversion pattern: {$template}\n\n"
            . "Yêu cầu:\n{$topic}\n\n"
            . "Tạo landing page HTML hoàn chỉnh, chuyên nghiệp như agency thiết kế.\n"
            . "Chỉ xuất HTML code, bắt đầu bằng <!DOCTYPE html>, không có gì khác.";

        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys ],
            [ 'role' => 'user',   'content' => $prompt ],
        ], [
            'model'       => 'anthropic/claude-sonnet-4-5',
            'purpose'     => 'landing_page',
            'temperature' => 0.7,
            'max_tokens'  => 16000,
            'timeout'     => 180,
        ] );

        if ( empty( $ai_result['success'] ) || empty( $ai_result['message'] ) ) {
            $err = $ai_result['error'] ?? 'API không trả về kết quả.';
            return new WP_Error( 'api_failed', 'AI API lỗi: ' . $err );
        }

        // Strip any accidental code fences
        $html = trim( $ai_result['message'] );
        $html = preg_replace( '/^```(?:html)?\s*/i', '', $html );
        $html = preg_replace( '/```\s*$/', '', trim( $html ) );

        if ( stripos( $html, '<html' ) === false ) {
            $raw_preview = substr( $html, 0, 300 );
            return new WP_Error( 'ai_failed', 'AI không trả về HTML hợp lệ. Raw: ' . $raw_preview );
        }

        // Extract title from <title> tag
        $title = wp_trim_words( $topic, 8 );
        if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
            $title = trim( html_entity_decode( $m[1], ENT_QUOTES ) );
        }

        // Extract meta description
        $description = $topic;
        if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/i', $html, $md ) ) {
            $description = trim( html_entity_decode( $md[1], ENT_QUOTES ) );
        }

        return [
            'title'       => $title,
            'html'        => $html,
            'description' => $description,
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  Color Palettes by Product Type
     * ══════════════════════════════════════════════════════════════ */
    private static function get_color_palette( string $type ): string {
        $palettes = [
            'saas'        => "Primary: #6366F1 (Indigo) | Accent: #06B6D4 (Cyan) | Dark: #1E1B4B | Light: #EEF2FF\nFont: Inter hoặc Plus Jakarta Sans",
            'education'   => "Primary: #2563EB (Blue) | Accent: #F59E0B (Amber) | Dark: #1E3A5F | Light: #EFF6FF\nFont: Nunito hoặc Source Sans Pro",
            'health'      => "Primary: #059669 (Emerald) | Accent: #14B8A6 (Teal) | Dark: #064E3B | Light: #ECFDF5\nFont: DM Sans hoặc Lato",
            'beauty'      => "Primary: #DB2777 (Pink) | Accent: #A855F7 (Purple) | Dark: #701A75 | Light: #FDF2F8\nFont: Playfair Display + Lato",
            'fnb'         => "Primary: #EA580C (Orange) | Accent: #DC2626 (Red) | Dark: #431407 | Light: #FFF7ED\nFont: Poppins hoặc Outfit",
            'finance'     => "Primary: #1D4ED8 (Blue) | Accent: #059669 (Green) | Dark: #1E293B | Light: #F1F5F9\nFont: IBM Plex Sans hoặc Inter",
            'real-estate' => "Primary: #B45309 (Amber Dark) | Accent: #0D9488 (Teal) | Dark: #292524 | Light: #FFFBEB\nFont: Cormorant Garamond + Montserrat",
            'event'       => "Primary: #7C3AED (Violet) | Accent: #EC4899 (Pink) | Dark: #2E1065 | Light: #F5F3FF\nFont: Space Grotesk hoặc Clash Display",
            'app'         => "Primary: #3B82F6 (Blue) | Accent: #8B5CF6 (Violet) | Dark: #0F172A | Light: #F8FAFC\nFont: Inter hoặc SF Pro Display alternative",
            'service'     => "Primary: #0891B2 (Cyan) | Accent: #6366F1 (Indigo) | Dark: #164E63 | Light: #ECFEFF\nFont: Plus Jakarta Sans hoặc Manrope",
            'ecommerce'   => "Primary: #DC2626 (Red) | Accent: #F59E0B (Amber) | Dark: #1C1917 | Light: #FEF2F2\nFont: Outfit hoặc Work Sans",
            'consulting'  => "Primary: #0F766E (Teal Dark) | Accent: #1D4ED8 (Blue) | Dark: #1E293B | Light: #F0FDFA\nFont: Libre Baskerville + Inter",
            'nonprofit'   => "Primary: #16A34A (Green) | Accent: #2563EB (Blue) | Dark: #14532D | Light: #F0FDF4\nFont: Merriweather + Open Sans",
            'portfolio'   => "Primary: #18181B (Black) | Accent: #A855F7 (Purple) | Dark: #09090B | Light: #FAFAFA\nFont: Space Mono + Inter",
        ];
        return $palettes[ $type ] ?? "Tự chọn bảng màu phù hợp với ngành và sản phẩm.";
    }

    /* ══════════════════════════════════════════════════════════════
     *  Template/Conversion Pattern Hints
     * ══════════════════════════════════════════════════════════════ */
    private static function get_template_hint( string $template ): string {
        $hints = [
            'hero-cta'            => "Hero-CTA Trực tiếp: Hero section lớn chiếm full viewport, headline ngắn gọn mạnh mẽ, 1 CTA button nổi bật, video/ảnh minh họa lớn. Phù hợp sản phẩm đã có thương hiệu, người dùng biết mình cần gì.",
            'problem-solution'    => "Problem-Solution: Mở đầu bằng paint point (nỗi đau), agitate (tăng đau), solution (giải pháp). Dùng ảnh before/after, comparison table. CTA sau khi show đủ giá trị.",
            'feature-benefit'     => "Feature-Benefit Matrix: Grid features lớn, mỗi feature kèm benefit cụ thể + icon/illustration. Tabs hoặc toggle để group features. Interactive hover effects.",
            'testimonial-proof'   => "Testimonial-Proof First: Mở đầu bằng social proof (số lượng user, logo brands, rating). Hero thay bằng carousel testimonial. Content chứng minh trước, bán sau.",
            'pricing-comparison'  => "Pricing-Comparison Focus: Hero ngắn → nhảy thẳng pricing table. So sánh plans chi tiết. Toggle monthly/yearly. Highlight popular plan. Money-back guarantee badge.",
            'countdown-urgency'   => "Countdown-Urgency: Banner countdown timer (CSS animation), limited spots, early-bird pricing, bonus hết hạn. Scarcity + Urgency xuyên suốt. Sticky CTA bar.",
            'storytelling'        => "Storytelling Journey: Kể câu chuyện founder/customer xuyên suốt page. Sections flow như chapters. Emotional imagery, quote highlights. CTA tự nhiên cuối story.",
            'quiz-funnel'         => "Quiz-Funnel Entry: Mở bằng quiz/assessment tương tác. Kết quả quiz → personalized offer. Multi-step form visual. Progress bar. Tailored CTA dựa trên answers.",
            'video-showcase'      => "Video-Showcase: Hero là video player/thumbnail lớn. Video testimonials, demo walkthrough, behind-the-scenes. Mỗi section có optional video. Minimal text, max visual.",
            'minimal-zen'         => "Minimal-Zen: Whitespace rộng, typography-focused. Max 3 màu. Ảnh full-width alternating sides. Single column flow. Elegant, luxury feel. Micro-interactions on scroll.",
        ];
        return $hints[ $template ] ?? '';
    }

    /* ══════════════════════════════════════════════════════════════
     *  Product Type Labels (Vietnamese)
     * ══════════════════════════════════════════════════════════════ */
    private static function get_product_type_label( string $type ): string {
        $labels = [
            'saas'        => 'Phần mềm / SaaS',
            'education'   => 'Giáo dục / Khóa học',
            'health'      => 'Sức khỏe / Y tế',
            'beauty'      => 'Làm đẹp / Mỹ phẩm',
            'fnb'         => 'Thực phẩm & Đồ uống',
            'finance'     => 'Tài chính / Bảo hiểm',
            'real-estate' => 'Bất động sản',
            'event'       => 'Sự kiện / Hội thảo',
            'app'         => 'Ứng dụng di động',
            'service'     => 'Dịch vụ',
            'ecommerce'   => 'Thương mại điện tử',
            'consulting'  => 'Tư vấn',
            'nonprofit'   => 'Phi lợi nhuận',
            'portfolio'   => 'Portfolio / Cá nhân',
            'other'       => 'Khác (AI tự nhận diện)',
        ];
        return $labels[ $type ] ?? $type;
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Generate Landing Page (ASYNC — avoids Cloudflare 524)
     *
     *  Flow: Return job_id immediately → process AI in background
     *  → frontend polls ajax_generate_status for result.
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_generate() {
        check_ajax_referer( 'bztool_landing', 'nonce' );

        $prompt       = sanitize_textarea_field( $_POST['prompt'] ?? '' );
        $product_type = sanitize_text_field( $_POST['product_type'] ?? 'other' );
        $template     = sanitize_text_field( $_POST['template'] ?? 'hero-cta' );

        if ( empty( $prompt ) ) {
            wp_send_json_error( [ 'message' => 'Cần nhập mô tả sản phẩm/dịch vụ.' ] );
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            wp_send_json_error( [ 'message' => 'AI chưa sẵn sàng (OpenRouter).' ] );
        }

        // Create async job
        $job_id = 'lp_' . bin2hex( random_bytes( 8 ) );

        set_transient( 'bztool_lp_job_' . $job_id, [
            'status'     => 'processing',
            'started_at' => time(),
        ], 600 );

        // Send response to browser immediately, then continue processing
        ob_end_clean();
        header( 'Content-Type: application/json; charset=UTF-8' );
        $payload = wp_json_encode( [ 'success' => true, 'data' => [ 'job_id' => $job_id ] ] );
        header( 'Content-Length: ' . strlen( $payload ) );
        echo $payload;

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } else {
            if ( ob_get_level() ) ob_end_flush();
            flush();
        }

        // Background processing
        ignore_user_abort( true );
        set_time_limit( 300 );

        $result = self::ai_generate_landing( $prompt, $product_type, $template );

        if ( is_wp_error( $result ) ) {
            set_transient( 'bztool_lp_job_' . $job_id, [
                'status' => 'failed',
                'error'  => $result->get_error_message(),
            ], 600 );
        } else {
            set_transient( 'bztool_lp_job_' . $job_id, [
                'status' => 'completed',
                'data'   => $result,
            ], 600 );
        }

        exit;
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Poll job status for async generate
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_generate_status() {
        check_ajax_referer( 'bztool_landing', 'nonce' );

        $job_id = sanitize_text_field( $_POST['job_id'] ?? '' );
        if ( empty( $job_id ) ) {
            wp_send_json_error( [ 'message' => 'Job ID thiếu.' ] );
        }

        $job = get_transient( 'bztool_lp_job_' . $job_id );
        if ( ! $job ) {
            wp_send_json_error( [ 'message' => 'Job không tìm thấy hoặc đã hết hạn.', 'status' => 'not_found' ] );
        }

        if ( $job['status'] === 'processing' ) {
            $elapsed = time() - ( $job['started_at'] ?? time() );
            wp_send_json_success( [ 'status' => 'processing', 'elapsed' => $elapsed ] );
        }

        if ( $job['status'] === 'failed' ) {
            delete_transient( 'bztool_lp_job_' . $job_id );
            wp_send_json_error( [ 'message' => $job['error'] ?? 'Lỗi tạo landing page.', 'status' => 'failed' ] );
        }

        // completed
        $data = $job['data'] ?? [];
        delete_transient( 'bztool_lp_job_' . $job_id );
        wp_send_json_success( array_merge( [ 'status' => 'completed' ], $data ) );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Save landing page (create or update)
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_save() {
        check_ajax_referer( 'bztool_landing', 'nonce' );

        $title        = sanitize_text_field( $_POST['title'] ?? '' );
        $html         = wp_unslash( $_POST['html'] ?? '' );
        $product_type = sanitize_text_field( $_POST['product_type'] ?? 'other' );
        $template     = sanitize_text_field( $_POST['template'] ?? 'hero-cta' );
        $prompt       = sanitize_textarea_field( $_POST['prompt'] ?? '' );
        $desc         = sanitize_textarea_field( $_POST['description'] ?? '' );
        $post_id      = intval( $_POST['post_id'] ?? 0 );

        if ( empty( $html ) ) {
            wp_send_json_error( [ 'message' => 'HTML code trống.' ] );
        }

        if ( $post_id > 0 ) {
            $existing = get_post( $post_id );
            if ( ! $existing || $existing->post_type !== 'bz_landing'
                 || ( (int) $existing->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
                wp_send_json_error( [ 'message' => 'Không tìm thấy hoặc không có quyền.' ] );
            }
            wp_update_post( [
                'ID'           => $post_id,
                'post_title'   => $title ?: $existing->post_title,
                'post_content' => $desc  ?: $existing->post_content,
            ] );
        } else {
            $post_id = wp_insert_post( [
                'post_type'    => 'bz_landing',
                'post_title'   => $title ?: ( 'Landing Page ' . wp_date( 'd/m/Y H:i' ) ),
                'post_content' => $desc ?: $prompt,
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id(),
            ] );

            if ( ! $post_id || is_wp_error( $post_id ) ) {
                wp_send_json_error( [ 'message' => 'Lỗi lưu vào database.' ] );
            }
        }

        update_post_meta( $post_id, '_bz_landing_html', $html );
        update_post_meta( $post_id, '_bz_landing_type', $product_type );
        update_post_meta( $post_id, '_bz_landing_template', $template );
        if ( $prompt ) {
            update_post_meta( $post_id, '_bz_prompt', $prompt );
        }

        wp_send_json_success( [
            'post_id' => $post_id,
            'title'   => get_the_title( $post_id ),
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: List landing pages (paginated)
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_list() {
        check_ajax_referer( 'bztool_landing', 'nonce' );

        $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page = 20;

        $q = new WP_Query( [
            'post_type'      => 'bz_landing',
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
                'id'           => $p->ID,
                'title'        => $p->post_title,
                'description'  => wp_trim_words( $p->post_content, 20 ),
                'product_type' => get_post_meta( $p->ID, '_bz_landing_type', true ) ?: 'other',
                'template'     => get_post_meta( $p->ID, '_bz_landing_template', true ) ?: 'hero-cta',
                'date'         => get_the_date( 'd/m/Y H:i', $p ),
                'prompt'       => get_post_meta( $p->ID, '_bz_prompt', true ),
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
     *  AJAX: Get single landing page
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_get() {
        check_ajax_referer( 'bztool_landing', 'nonce' );

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'bz_landing' ) {
            wp_send_json_error( [ 'message' => 'Không tìm thấy landing page.' ] );
        }

        if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền truy cập.' ] );
        }

        wp_send_json_success( [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'description'  => $post->post_content,
            'html'         => get_post_meta( $post->ID, '_bz_landing_html', true ),
            'product_type' => get_post_meta( $post->ID, '_bz_landing_type', true ),
            'template'     => get_post_meta( $post->ID, '_bz_landing_template', true ),
            'prompt'       => get_post_meta( $post->ID, '_bz_prompt', true ),
            'date'         => get_the_date( 'd/m/Y H:i', $post ),
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Delete landing page
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_delete() {
        check_ajax_referer( 'bztool_landing', 'nonce' );

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'bz_landing'
             || ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền xóa.' ] );
        }

        wp_delete_post( $post_id, true );
        wp_send_json_success( [ 'deleted' => $post_id ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Update HTML / title
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_update() {
        check_ajax_referer( 'bztool_landing', 'nonce' );

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $html    = wp_unslash( $_POST['html'] ?? '' );
        $title   = sanitize_text_field( $_POST['title'] ?? '' );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'bz_landing'
             || ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền chỉnh sửa.' ] );
        }

        if ( $title ) {
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
        }
        if ( $html ) {
            update_post_meta( $post_id, '_bz_landing_html', $html );
        }

        wp_send_json_success( [ 'post_id' => $post_id ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Upload image to WordPress Media Library
     *
     *  Supports both base64 data URI and file upload via $_FILES.
     *  Returns attachment_id + url for use in landing page images.
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_upload_media() {
        check_ajax_referer( 'bztool_landing', 'nonce' );

        // Method 1: File upload via $_FILES
        if ( ! empty( $_FILES['file'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $attachment_id = media_handle_upload( 'file', 0 );

            if ( is_wp_error( $attachment_id ) ) {
                wp_send_json_error( [ 'message' => 'Lỗi upload: ' . $attachment_id->get_error_message() ] );
            }

            wp_send_json_success( [
                'attachment_id' => $attachment_id,
                'url'           => wp_get_attachment_url( $attachment_id ),
                'filename'      => basename( get_attached_file( $attachment_id ) ),
            ] );
            return;
        }

        // Method 2: Base64 data URI
        $base64  = $_POST['image_data'] ?? '';
        $title   = sanitize_text_field( $_POST['title'] ?? 'Landing Page Image' );
        $post_id = intval( $_POST['post_id'] ?? 0 );

        if ( empty( $base64 ) ) {
            wp_send_json_error( [ 'message' => 'Không có dữ liệu ảnh.' ] );
        }

        // Detect MIME type from data URI
        $mime = 'image/png';
        $ext  = 'png';
        if ( preg_match( '/^data:(image\/\w+);base64,/', $base64, $mime_match ) ) {
            $mime = $mime_match[1];
            $ext_map = [ 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif' ];
            $ext = $ext_map[ $mime ] ?? 'png';
        }

        if ( strpos( $base64, ',' ) !== false ) {
            $base64 = substr( $base64, strpos( $base64, ',' ) + 1 );
        }

        $decoded = base64_decode( $base64, true );
        if ( ! $decoded || strlen( $decoded ) < 100 ) {
            wp_send_json_error( [ 'message' => 'Dữ liệu ảnh không hợp lệ.' ] );
        }

        $filename = sanitize_file_name( $title ) . '-' . time() . '.' . $ext;
        $upload   = wp_upload_bits( $filename, null, $decoded );

        if ( ! empty( $upload['error'] ) ) {
            wp_send_json_error( [ 'message' => 'Lỗi upload: ' . $upload['error'] ] );
        }

        $filetype      = wp_check_filetype( $upload['file'] );
        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => $filetype['type'] ?: $mime,
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $upload['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => 'Lỗi tạo attachment.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        if ( $post_id > 0 ) {
            $existing = get_post_meta( $post_id, '_bz_landing_images', true ) ?: [];
            $existing[] = $attachment_id;
            update_post_meta( $post_id, '_bz_landing_images', $existing );
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

    /* ══════════════════════════════════════════════════════════════
     *  Academic / Report / Technical Page Generator
     *
     *  Generates a clean, content-focused HTML page with NO pricing,
     *  NO testimonials — suited for knowledge-sharing, reports, research.
     * ══════════════════════════════════════════════════════════════ */
    private static function ai_generate_academic_page( string $topic, string $intent = 'academic', string $ai_context = '' ) {
        $intent_label = [
            'academic'  => 'Trang trình bày học thuật / giáo trình / kiến thức',
            'report'    => 'Báo cáo / Phân tích / Đánh giá',
            'technical' => 'Tài liệu kỹ thuật / Kiến trúc hệ thống / Dev docs',
        ][ $intent ] ?? 'Trang nội dung chuyên môn';

        $sys = <<<'ACADEMIC'
Bạn là chuyên gia thiết kế trang trình bày nội dung học thuật và báo cáo chuyên nghiệp.
Tạo HTML+CSS single-file, sạch sẽ, dễ đọc, typography-first.

═══ NGUYÊN TẮC ═══
1. Mobile-first responsive (320px–1440px)
2. Single-file: HTML + CSS trong <style>
3. Tailwind CSS CDN: <script src="https://cdn.tailwindcss.com"></script>
4. Google Fonts: Inter hoặc Source Serif 4 (học thuật) hoặc IBM Plex Sans (technical)
5. Hình ảnh: https://placehold.co/{w}x{h}/3B5BDB/ffffff?text={text}
6. KHÔNG có section Pricing, KHÔNG Testimonials, KHÔNG countdown urgency

═══ PHONG CÁCH ═══
- Clean editorial, whitespace nhiều, dễ đọc như Medium / GitBook / Notion
- Primary color: #3B5BDB (academic blue) | Accent: #339AF0 | Dark: #1C2340
- Background: #ffffff (main), #F8F9FA (sections xen kẽ)
- Heading: font-weight 800, color #1C2340
- Body text: color #374151, line-height 1.75, font-size 1.05rem
- Highlight box: background #EDF2FF, border-left 4px solid #3B5BDB
- Code/kỹ thuật: background #1e1e2e, color #cdd6f4, font Fira Code

═══ CẤU TRÚC BẮT BUỘC ═══
1. HEADER — Logo nhỏ + tên tài liệu + breadcrumb/tag loại
2. HERO / ABSTRACT — Tiêu đề lớn, tóm tắt 2-3 câu, metadata (tác giả/nguồn/ngày)
3. TABLE OF CONTENTS — Mục lục anchor links, grid 2 cột
4. KEY CONCEPTS / OVERVIEW — 3-6 cards concept chính với icon emoji
5. SECTION ANALYSIS (2-4 sections) — Nội dung phân tích chi tiết từng chủ đề:
   - Mỗi section: H2 heading, text + highlight box + optional image 2 cột
   - Dùng blockquote cho trích dẫn quan trọng
   - Dùng ordered list cho quy trình / steps
6. KEY FINDINGS / CONCLUSIONS — Card grid kết quả/insight chính
7. THANK YOU / SHARE — Minimal CTA (Lưu tài liệu, Chia sẻ, Đọc thêm)
8. FOOTER — Source credit, năm, logo nhỏ. Không có links mua bán.

═══ CSS BẮT BUỘC trong <style> ═══
body { color: #374151 !important; background: #ffffff; }
body * { color: inherit; }
.academic-hero, .academic-toc { background: linear-gradient(135deg,#EDF2FF,#ffffff); }
.highlight-box { background:#EDF2FF; border-left:4px solid #3B5BDB; padding:16px 20px; border-radius:0 12px 12px 0; margin:16px 0; }
.concept-card { background:#fff; border:1px solid #E9ECEF; border-radius:16px; padding:24px; transition:box-shadow .2s; }
.concept-card:hover { box-shadow:0 4px 20px rgba(59,91,219,.12); }
Gradient text CHỈ cho H1: .grad-title { background:linear-gradient(135deg,#3B5BDB,#339AF0); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }

═══ FORMAT OUTPUT ═══
Chỉ trả HTML thuần, bắt đầu <!DOCTYPE html>, kết thúc </html>.
KHÔNG JSON, KHÔNG markdown fence, KHÔNG giải thích.
ACADEMIC;

        if ( $ai_context ) {
            $sys .= "\n\n═══ NGỮ CẢNH DỰ ÁN ═══\n" . $ai_context;
        }

        $prompt = "Loại trang: {$intent_label}\n\nNội dung:\n{$topic}\n\n"
            . "Tạo trang HTML học thuật hoàn chỉnh từ nội dung trên. Chỉ xuất HTML.";

        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys ],
            [ 'role' => 'user',   'content' => $prompt ],
        ], [
            'model'       => 'anthropic/claude-sonnet-4-5',
            'purpose'     => 'academic_page',
            'temperature' => 0.65,
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
            return new WP_Error( 'ai_failed', 'AI không trả về HTML hợp lệ.' );
        }

        $title = wp_trim_words( $topic, 8 );
        if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
            $title = trim( html_entity_decode( $m[1], ENT_QUOTES ) );
        }
        $description = $topic;
        if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/i', $html, $md ) ) {
            $description = trim( html_entity_decode( $md[1], ENT_QUOTES ) );
        }

        return [ 'title' => $title, 'html' => $html, 'description' => $description ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  Intent Classifier — cheap/fast LLM, runs before page generation
     *  Returns: 'business' | 'academic' | 'report' | 'technical'
     * ══════════════════════════════════════════════════════════════ */
    private static function classify_content_intent( string $topic ): string {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) return 'business';

        $sys = "You are a content intent classifier. Analyze text, return ONLY JSON.\n"
            . "Categories:\n"
            . "- business: product/service marketing, SaaS, ecommerce, sales, events, promotions, pricing\n"
            . "- academic: research, educational content, tutorials, architecture docs, learning materials, scientific analysis, training materials\n"
            . "- report: analysis reports, data reports, project reviews, case studies, audits, BI summaries, corporate reviews\n"
            . "- technical: developer docs, API docs, code architecture, system design, tech specs\n"
            . "Return ONLY: {\"intent\":\"business|academic|report|technical\",\"confidence\":0.9}\n"
            . "No explanation.";

        $result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys ],
            [ 'role' => 'user',   'content' => "Classify:\n\n" . mb_substr( $topic, 0, 3000 ) ],
        ], [
            'model'       => 'openai/gpt-4o-mini',
            'purpose'     => 'classify',
            'temperature' => 0.1,
            'max_tokens'  => 60,
            'timeout'     => 15,
        ] );

        $parsed = self::parse_json_response( $result['message'] ?? '' );
        $intent = $parsed['intent'] ?? 'business';
        error_log( '[BizCity Landing] classify_intent=' . $intent . ' conf=' . ( $parsed['confidence'] ?? '?' ) );
        return in_array( $intent, [ 'academic', 'report', 'technical', 'business' ], true ) ? $intent : 'business';
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
}

BizCity_Tool_Landing::init();
