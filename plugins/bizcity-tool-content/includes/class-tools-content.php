<?php
/**
 * BizCity Tool Content — Self-contained Tool Callbacks
 *
 * Kiến trúc "Developer-packaged Pipeline":
 *
 *   1. Intent Provider khai báo goal_patterns + required_slots
 *      → Intent Engine nhận diện goal → Planner hỏi user nếu thiếu fields
 *      → Khi đủ slots → call_tool
 *
 *   2. Tool callback (hàm này) thực thi toàn bộ pipeline nội bộ:
 *      Viết bài → Tạo ảnh → Đăng bài
 *      Mỗi step được track qua BizCity_Job_Trace → SSE status events
 *
 *   3. KHÔNG cần executor/preflight/planner pipeline bên ngoài.
 *      Developer đóng gói sẵn toàn bộ logic.
 *
 * @package BizCity_Tool_Content
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Content {

    /**
     * Resolve skill content from _meta or fallback BizCity_Tool_Run.
     *
     * Phase 1.1h: Composite tools now consume skill context (R3 compliance).
     *
     * @param array  $meta     _meta from slots.
     * @param string $tool_id  Tool name for fallback resolve.
     * @param int    $user_id  User ID (0 = global).
     * @return string Skill content to inject into system prompt (empty if none).
     */
    private static function resolve_skill_content( array $meta, string $tool_id, int $user_id = 0 ): string {
        // Primary: skill already resolved by BizCity_Tool_Run::execute()
        $skill = $meta['_skill'] ?? null;
        if ( $skill && ! empty( $skill['content'] ) ) {
            return $skill['content'];
        }

        // Fallback: direct call (AJAX, CLI) — resolve ourselves
        if ( class_exists( 'BizCity_Tool_Run' ) ) {
            $resolved = BizCity_Tool_Run::resolve_skill( $tool_id, $user_id ?: get_current_user_id() );
            if ( $resolved && ! empty( $resolved['content'] ) ) {
                return $resolved['content'];
            }
        }

        return '';
    }

    /**
     * write_article — Self-contained 3-step pipeline.
     *
     * Flow:
     *   T1: AI viết nội dung bài (title + content + excerpt)
     *   T2: Tạo/lấy ảnh bìa (skip nếu user đã cung cấp)
     *   T3: Đăng bài lên WordPress (create post + set featured image)
     *
     * Mỗi step cập nhật trạng thái qua BizCity_Job_Trace → SSE status events
     * → Frontend typing indicator hiện tiến trình real-time.
     *
     * @param array $slots {
     *   message   - Chủ đề / nội dung yêu cầu
     *   image_url - URL ảnh bìa (optional: skip step T2 nếu có)
     *   title     - Tiêu đề gợi ý (optional)
     *   session_id - Chat session (auto-injected by intent engine)
     * }
     * @return array Tool Output Envelope
     */
    public static function write_article( array $slots ): array {
        // ── Normalize input ──
        $text       = self::extract_text( $slots );
        $image_url  = $slots['image_url'] ?? '';
        if ( is_array( $image_url ) ) {
            $image_url = $image_url[0] ?? '';
        }
        $title_hint = $slots['title']     ?? '';
        $session_id = $slots['session_id'] ?? '';
        $chat_id    = $slots['chat_id']   ?? '';
        $meta       = $slots['_meta']      ?? [];
        $ai_context = $meta['_context']    ?? '';

        if ( empty( $text ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❌ Cần cung cấp chủ đề hoặc nội dung để viết bài.',
                'data'           => [],
                'missing_fields' => [ 'topic' ],
            ];
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) && ! function_exists( 'ai_generate_content' ) ) {
            return [
                'success'  => false,
                'complete' => false,
                'message'  => '❌ Module AI chưa sẵn sàng (cần OpenRouter hoặc bizcity-admin-hook).',
                'data'     => [],
            ];
        }
        if ( ! function_exists( 'twf_wp_create_post' ) ) {
            return [
                'success'  => false,
                'complete' => false,
                'message'  => '❌ Module viết bài chưa được load (bizcity-admin-hook required).',
                'data'     => [],
            ];
        }

        // ── Start Job Trace (3 steps) ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $has_image = ! empty( $image_url ) && ( function_exists( 'twf_is_valid_image_url' ) ? twf_is_valid_image_url( $image_url ) : false );
            $steps = [
                'T1' => 'Viết nội dung bài',
            ];
            if ( ! $has_image ) {
                $steps['T2'] = 'Tạo ảnh bìa';
            }
            $steps['T3'] = 'Đăng bài lên WordPress';

            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'write_article', $steps );
        }

        // ══════════════════════════════════════════════════════
        //  T1: AI viết nội dung bài
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T1', 'running' );

        // ── Build structured prompt for high-quality HTML article ──
        $tone_hint = ! empty( $slots['tone'] ) ? $slots['tone'] : 'thân thiện, gần gũi';
        $article_prompt = "Viết bài blog tiếng Việt hoàn chỉnh, ít nhất 700 từ, tone {$tone_hint}.\n"
            . "Chủ đề: {$text}\n\n"
            . "YÊU CẦU BẮT BUỘC:\n"
            . "- Tiêu đề PHẢI liên quan trực tiếp đến chủ đề, hấp dẫn, dưới 80 ký tự\n"
            . "- Mở bài cuốn hút, kết bài có CTA (lời kêu gọi hành động)\n"
            . "- Chia đoạn rõ ràng, mỗi đoạn 2-3 câu, dễ đọc\n"
            . "- Dùng thẻ HTML: <h2>, <h3> cho heading, <strong>, <em>, <mark> cho nhấn mạnh\n"
            . "- TUYỆT ĐỐI KHÔNG dùng markdown (**, ##, -, *). Chỉ HTML thuần\n"
            . "- Nội dung sinh động, có ví dụ thực tế, mẹo hữu ích\n\n"
            . "Trả về ĐÚNG JSON (không giải thích thêm):\n"
            . "{\n"
            . "  \"title\": \"Tiêu đề bài viết\",\n"
            . "  \"content\": \"Nội dung HTML hoàn chỉnh\"\n"
            . "}";

        $sys_prompt = 'Bạn là nhà sáng tạo nội dung blog chuyên nghiệp. Viết bài sinh động, giàu cảm xúc, thân thiện người đọc. Chỉ trả JSON, không giải thích.';
        if ( $ai_context ) {
            $sys_prompt .= "\n\n" . $ai_context;
        }

        // ── Phase 1.1h: Inject skill context (R3 compliance) ──
        $skill_content = self::resolve_skill_content( $meta, 'write_article' );
        if ( $skill_content ) {
            $sys_prompt .= "\n\n[Skill Context — Hướng dẫn chuyên môn]\n" . $skill_content;
        }

        // ── Prefer bizcity_openrouter_chat for structured output ──
        $post_title   = '';
        $post_content = '';
        $post_category = '';

        if ( function_exists( 'bizcity_openrouter_chat' ) ) {
            $ai_result = bizcity_openrouter_chat( [
                [ 'role' => 'system', 'content' => $sys_prompt ],
                [ 'role' => 'user',   'content' => $article_prompt ],
            ], [ 'temperature' => 0.75, 'max_tokens' => 4000 ] );

            $raw    = $ai_result['message'] ?? '';
            $parsed = self::parse_json_response( $raw );

            $post_title   = $parsed['title']    ?? '';
            $post_content = $parsed['content']   ?? '';
            $post_category = $parsed['category'] ?? '';
        }

        // Fallback to legacy ai_generate_content if OpenRouter unavailable
        if ( empty( $post_content ) && function_exists( 'ai_generate_content' ) ) {
            $legacy_input = $text;
            if ( $ai_context ) {
                $legacy_input = "[Ngữ cảnh hội thoại]\n{$ai_context}\n\n[Yêu cầu]\n{$text}";
            }
            $fields        = ai_generate_content( $legacy_input );
            $post_title    = $fields['title']    ?? '';
            $post_content  = $fields['content']  ?? '';
            $post_category = $fields['category'] ?? '';
        }

        // Apply title hint from user if available
        if ( $title_hint ) {
            $post_title = $title_hint;
        }

        // Fallback: generate title from content/topic
        if ( empty( $post_title ) ) {
            $post_title = function_exists( 'twf_generate_title_from_content' )
                ? twf_generate_title_from_content( $post_content ?: $text )
                : wp_trim_words( $text, 10 );
        }
        if ( empty( $post_content ) ) {
            $post_content = $text;
        }

        // Clean content (normalize newlines, remove artifacts)
        if ( function_exists( 'twf_clean_post_content' ) ) {
            $post_content = twf_clean_post_content( $post_content );
        }

        if ( $trace ) $trace->step( 'T1', 'done', [
            'title'    => $post_title,
            'content'  => mb_substr( $post_content, 0, 200 ), // summary only
            'category' => $post_category,
        ] );

        // ══════════════════════════════════════════════════════
        //  T2: Tạo ảnh bìa (skip nếu đã có)
        // ══════════════════════════════════════════════════════
        $has_valid_image = ! empty( $image_url )
            && ( function_exists( 'twf_is_valid_image_url' ) ? twf_is_valid_image_url( $image_url ) : true );

        if ( ! $has_valid_image ) {
            if ( $trace && isset( $trace->get_steps()['T2'] ) ) $trace->step( 'T2', 'running' );

            if ( function_exists( 'twf_generate_image_url' ) ) {
                $image_url = twf_generate_image_url( $post_title );
            }

            if ( $trace && isset( $trace->get_steps()['T2'] ) ) {
                $trace->step( 'T2', $image_url ? 'done' : 'skipped', [ 'image_url' => $image_url ?: '(none)' ] );
            }
        }

        // ══════════════════════════════════════════════════════
        //  T3: Đăng bài lên WordPress
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T3', 'running' );

        $post_id = twf_wp_create_post( $post_title, $post_content, $image_url );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            if ( $trace ) $trace->step( 'T3', 'failed', [], 'wp_insert_post failed' );
            if ( $trace ) $trace->fail( 'Không thể tạo bài viết' );
            return [
                'success'  => false,
                'complete' => false,
                'message'  => '❌ Không thể tạo bài viết. Kiểm tra quyền WordPress.',
                'data'     => [],
            ];
        }

        $permalink = get_permalink( $post_id );
        $edit_url  = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

        if ( $trace ) $trace->step( 'T3', 'done', [
            'post_id'   => $post_id,
            'permalink' => $permalink,
        ] );

        // ── Mark trace complete ──
        if ( $trace ) $trace->complete( [
            'post_id'   => $post_id,
            'permalink' => $permalink,
        ] );

        // ── Notify Telegram if applicable ──
        if ( $chat_id && function_exists( 'twf_telegram_send_message' ) ) {
            twf_telegram_send_message( $chat_id, "✅ Bài đã đăng: {$post_title}\n🔗 {$permalink}" );
        }

        // ── Standard Tool Output Envelope ──
        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã đăng bài: **{$post_title}**\n🔗 {$permalink}\n✏️ [Chỉnh sửa]({$edit_url})",
            'data'     => [
                'id'        => $post_id,
                'type'      => 'article',
                'title'     => $post_title,
                'content'   => $post_content,
                'excerpt'   => wp_trim_words( $post_content, 30 ),
                'url'       => $permalink,
                'image_url' => $image_url,
                'edit_url'  => $edit_url,
                'platform'  => 'wordpress',
                'trace_id'  => $trace ? $trace->get_trace_id() : '',
                'meta'      => [ 'category' => $post_category ],
            ],
        ];
    }

    /**
     * Extract text content from various input formats.
     *
     * Supports: plain string, Telegram message array, pipeline content.
     *
     * @param array $slots
     * @return string
     */
    private static function extract_text( array $slots ): string {
        // Priority: topic (new slot name) → message (legacy) → content (pipeline)
        $candidates = [ 'topic', 'message', 'content' ];

        foreach ( $candidates as $key ) {
            $val = $slots[ $key ] ?? '';
            if ( is_string( $val ) && $val !== '' ) {
                return trim( $val );
            }
            if ( is_array( $val ) ) {
                $text = $val['text'] ?? $val['caption'] ?? '';
                if ( $text ) return trim( $text );
            }
        }

        return '';
    }

    /* ══════════════════════════════════════════════════════════
     *  write_seo_article — SEO-optimized article pipeline
     * ══════════════════════════════════════════════════════════ */

    /**
     * write_seo_article — 3-step pipeline with SEO focus.
     *
     * T1: AI tạo outline + nội dung chuẩn SEO (H2/H3 headings, meta desc, focus keyword)
     * T2: Tạo ảnh bìa
     * T3: Đăng bài + cập nhật SEO meta
     *
     * @param array $slots { topic, focus_keyword, tone, length, image_url, session_id }
     * @return array Tool Output Envelope
     */
    public static function write_seo_article( array $slots ): array {
        $topic         = self::extract_text( $slots );
        $focus_keyword = $slots['focus_keyword'] ?? '';
        $tone          = $slots['tone']          ?? 'professional';
        $length        = $slots['length']        ?? 'long';
        $image_url     = $slots['image_url']     ?? '';
        if ( is_array( $image_url ) ) {
            $image_url = $image_url[0] ?? '';
        }
        $session_id    = $slots['session_id']    ?? '';
        $chat_id       = $slots['chat_id']       ?? '';
        $meta          = $slots['_meta']          ?? [];
        $ai_context    = $meta['_context']        ?? '';

        if ( empty( $topic ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Cần cung cấp chủ đề để viết bài SEO.',
                'data' => [], 'missing_fields' => [ 'topic' ],
            ];
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module AI (OpenRouter) chưa sẵn sàng.', 'data' => [],
            ];
        }
        if ( ! function_exists( 'twf_wp_create_post' ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module viết bài chưa được load (bizcity-admin-hook required).', 'data' => [],
            ];
        }

        // ── Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $steps = [ 'T1' => 'Viết bài chuẩn SEO' ];
            if ( empty( $image_url ) ) $steps['T2'] = 'Tạo ảnh bìa';
            $steps['T3'] = 'Đăng bài lên WordPress';
            $trace = BizCity_Job_Trace::start( $session_id ?: $chat_id ?: 'cli', 'write_seo_article', $steps );
        }

        // ── T1: AI viết bài SEO ──
        if ( $trace ) $trace->step( 'T1', 'running' );

        $kw_hint = $focus_keyword ? "Focus keyword: {$focus_keyword}. " : '';
        $len_map = [ 'short' => '500-700', 'medium' => '800-1200', 'long' => '1500-2500' ];
        $word_range = $len_map[ $length ] ?? '1000-1500';

        $seo_prompt = "Viết bài blog chuẩn SEO tiếng Việt ({$word_range} từ), tone {$tone}.\n"
            . "{$kw_hint}\n"
            . "Chủ đề: {$topic}\n\n"
            . "YÊU CẦU:\n"
            . "- Tiêu đề H1 chứa keyword, dưới 60 ký tự\n"
            . "- Mở bài hấp dẫn, kết luận CTA\n"
            . "- Sử dụng H2/H3 headings phân cấp rõ ràng (dùng thẻ HTML <h2>, <h3>)\n"
            . "- Đoạn ngắn 2-3 câu, dễ đọc\n"
            . "- Dùng <strong>, <em> nhấn mạnh. KHÔNG dùng markdown\n"
            . "- Meta description dưới 155 ký tự\n\n"
            . "Trả về JSON:\n"
            . "{\n"
            . "  \"title\": \"Tiêu đề SEO\",\n"
            . "  \"content\": \"Nội dung HTML\",\n"
            . "  \"meta_description\": \"Meta mô tả\",\n"
            . "  \"focus_keyword\": \"từ khóa chính\",\n"
            . "  \"tags\": [\"tag1\", \"tag2\"]\n"
            . "}";

        $sys_seo = 'Bạn là chuyên gia SEO content writer. Chỉ trả JSON, không giải thích.';
        if ( $ai_context ) {
            $sys_seo .= "\n\n" . $ai_context;
        }

        // ── Phase 1.1h: Inject skill context (R3 compliance) ──
        $skill_content = self::resolve_skill_content( $meta, 'write_seo_article' );
        if ( $skill_content ) {
            $sys_seo .= "\n\n[Skill Context — Hướng dẫn chuyên môn]\n" . $skill_content;
        }

        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys_seo ],
            [ 'role' => 'user',   'content' => $seo_prompt ],
        ], [ 'temperature' => 0.7, 'max_tokens' => 4000 ] );

        $raw = $ai_result['message'] ?? '';
        $parsed = self::parse_json_response( $raw );

        $post_title      = $parsed['title']            ?? wp_trim_words( $topic, 10 );
        $post_content    = $parsed['content']           ?? '';
        $meta_desc       = $parsed['meta_description']  ?? '';
        $final_keyword   = $parsed['focus_keyword']     ?? $focus_keyword;
        $tags            = $parsed['tags']              ?? [];

        if ( empty( $post_content ) ) {
            if ( $trace ) $trace->fail( 'AI không trả nội dung' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ AI không tạo được nội dung. Thử lại với chủ đề rõ ràng hơn.', 'data' => [],
            ];
        }

        if ( function_exists( 'twf_clean_post_content' ) ) {
            $post_content = twf_clean_post_content( $post_content );
        }

        if ( $trace ) $trace->step( 'T1', 'done', [ 'title' => $post_title ] );

        // ── T2: Ảnh bìa ──
        $has_image = ! empty( $image_url );
        if ( ! $has_image ) {
            if ( $trace && isset( $trace->get_steps()['T2'] ) ) $trace->step( 'T2', 'running' );
            if ( function_exists( 'twf_generate_image_url' ) ) {
                $image_url = twf_generate_image_url( $post_title );
            }
            if ( $trace && isset( $trace->get_steps()['T2'] ) ) {
                $trace->step( 'T2', $image_url ? 'done' : 'skipped', [ 'image_url' => $image_url ?: '(none)' ] );
            }
        }

        // ── T3: Đăng bài ──
        if ( $trace ) $trace->step( 'T3', 'running' );

        $post_id = twf_wp_create_post( $post_title, $post_content, $image_url );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            if ( $trace ) $trace->fail( 'wp_insert_post failed' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Không thể tạo bài viết.', 'data' => [],
            ];
        }

        // SEO meta (Yoast / RankMath compatible)
        if ( $meta_desc )     update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
        if ( $final_keyword ) update_post_meta( $post_id, '_yoast_wpseo_focuskw', $final_keyword );
        if ( ! empty( $tags ) ) wp_set_post_tags( $post_id, $tags, true );

        $permalink = get_permalink( $post_id );
        $edit_url  = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

        if ( $trace ) $trace->step( 'T3', 'done', [ 'post_id' => $post_id ] );
        if ( $trace ) $trace->complete( [ 'post_id' => $post_id, 'permalink' => $permalink ] );

        return [
            'success' => true, 'complete' => true,
            'message' => "✅ Đã đăng bài SEO: **{$post_title}**\n🔗 {$permalink}\n✏️ [Chỉnh sửa]({$edit_url})\n🔑 Keyword: {$final_keyword}",
            'data' => [
                'id' => $post_id, 'type' => 'article', 'title' => $post_title,
                'url' => $permalink, 'edit_url' => $edit_url,
                'meta_description' => $meta_desc, 'focus_keyword' => $final_keyword,
                'image_url' => $image_url, 'platform' => 'wordpress',
                'trace_id' => $trace ? $trace->get_trace_id() : '',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  rewrite_article — Viết lại bài viết đã có
     * ══════════════════════════════════════════════════════════ */

    /**
     * rewrite_article — 3-step pipeline.
     *
     * T1: Tìm bài viết gốc (by ID, slug, or keyword search)
     * T2: AI viết lại nội dung
     * T3: Cập nhật bài viết
     *
     * @param array $slots { post_id, slug, instruction, tone, session_id }
     * @return array Tool Output Envelope
     */
    public static function rewrite_article( array $slots ): array {
        $post_ref    = $slots['post_id']     ?? '';
        $instruction = $slots['instruction'] ?? '';
        $tone        = $slots['tone']        ?? 'professional';
        $session_id  = $slots['session_id']  ?? '';
        $chat_id     = $slots['chat_id']     ?? '';
        $meta        = $slots['_meta']        ?? [];
        $ai_context  = $meta['_context']      ?? '';

        if ( empty( $post_ref ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Cần ID, slug hoặc tiêu đề bài viết muốn viết lại.',
                'data' => [], 'missing_fields' => [ 'post_id' ],
            ];
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module AI (OpenRouter) chưa sẵn sàng.', 'data' => [],
            ];
        }

        // ── Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start(
                $session_id ?: $chat_id ?: 'cli', 'rewrite_article',
                [ 'T1' => 'Tìm bài viết gốc', 'T2' => 'Viết lại nội dung', 'T3' => 'Cập nhật bài viết' ]
            );
        }

        // ── T1: Tìm bài viết ──
        if ( $trace ) $trace->step( 'T1', 'running' );

        $post = self::find_post( $post_ref );

        if ( ! $post ) {
            if ( $trace ) $trace->fail( 'Không tìm thấy bài viết' );
            return [
                'success' => false, 'complete' => false,
                'message' => "❌ Không tìm thấy bài viết: \"{$post_ref}\". Hãy cho mình ID, slug hoặc từ khóa trong tiêu đề.",
                'data' => [], 'missing_fields' => [ 'post_id' ],
            ];
        }

        $original_title   = $post->post_title;
        $original_content = $post->post_content;

        if ( $trace ) $trace->step( 'T1', 'done', [ 'post_id' => $post->ID, 'title' => $original_title ] );

        // ── T2: AI viết lại ──
        if ( $trace ) $trace->step( 'T2', 'running' );

        $rewrite_prompt = "Viết lại bài viết sau bằng tiếng Việt, tone {$tone}.\n";
        if ( $instruction ) {
            $rewrite_prompt .= "Yêu cầu đặc biệt: {$instruction}\n";
        }
        $rewrite_prompt .= "\nGiữ ý chính, cải thiện:\n"
            . "- Văn phong mượt mà, hấp dẫn hơn\n"
            . "- Cấu trúc heading H2/H3 rõ ràng (dùng HTML, không markdown)\n"
            . "- Thêm <strong>, <em> nhấn mạnh điểm quan trọng\n"
            . "- Kết bài có CTA\n\n"
            . "Tiêu đề gốc: {$original_title}\n"
            . "Nội dung gốc:\n" . mb_substr( wp_strip_all_tags( $original_content ), 0, 3000 ) . "\n\n"
            . "Trả về JSON: {\"title\": \"...\", \"content\": \"...\"}";

        $sys_rewrite = 'Bạn là content writer chuyên nghiệp. Chỉ trả JSON.';
        if ( $ai_context ) {
            $sys_rewrite .= "\n\n" . $ai_context;
        }

        // ── Phase 1.1h: Inject skill context (R3 compliance) ──
        $skill_content = self::resolve_skill_content( $meta, 'rewrite_article' );
        if ( $skill_content ) {
            $sys_rewrite .= "\n\n[Skill Context — Hướng dẫn chuyên môn]\n" . $skill_content;
        }

        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys_rewrite ],
            [ 'role' => 'user',   'content' => $rewrite_prompt ],
        ], [ 'temperature' => 0.7, 'max_tokens' => 4000 ] );

        $parsed = self::parse_json_response( $ai_result['message'] ?? '' );
        $new_title   = $parsed['title']   ?? $original_title;
        $new_content = $parsed['content'] ?? '';

        if ( empty( $new_content ) ) {
            if ( $trace ) $trace->fail( 'AI không trả nội dung' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ AI không tạo được nội dung mới. Thử lại.', 'data' => [],
            ];
        }

        if ( function_exists( 'twf_clean_post_content' ) ) {
            $new_content = twf_clean_post_content( $new_content );
        }

        if ( $trace ) $trace->step( 'T2', 'done', [ 'new_title' => $new_title ] );

        // ── T3: Cập nhật bài ──
        if ( $trace ) $trace->step( 'T3', 'running' );

        $updated = wp_update_post( [
            'ID'           => $post->ID,
            'post_title'   => sanitize_text_field( $new_title ),
            'post_content' => wp_kses_post( $new_content ),
        ] );

        if ( ! $updated || is_wp_error( $updated ) ) {
            if ( $trace ) $trace->fail( 'wp_update_post failed' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Không thể cập nhật bài viết.', 'data' => [],
            ];
        }

        $permalink = get_permalink( $post->ID );
        $edit_url  = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );

        if ( $trace ) $trace->step( 'T3', 'done', [ 'post_id' => $post->ID ] );
        if ( $trace ) $trace->complete( [ 'post_id' => $post->ID, 'permalink' => $permalink ] );

        return [
            'success' => true, 'complete' => true,
            'message' => "✅ Đã viết lại bài: **{$new_title}**\n🔗 {$permalink}\n✏️ [Chỉnh sửa]({$edit_url})",
            'data' => [
                'id' => $post->ID, 'type' => 'article', 'title' => $new_title,
                'url' => $permalink, 'edit_url' => $edit_url,
                'original_title' => $original_title, 'platform' => 'wordpress',
                'trace_id' => $trace ? $trace->get_trace_id() : '',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  translate_and_publish — Dịch & đăng bài mới
     * ══════════════════════════════════════════════════════════ */

    /**
     * translate_and_publish — 3-step pipeline.
     *
     * T1: Tìm bài viết gốc
     * T2: AI dịch sang ngôn ngữ đích
     * T3: Đăng bài dịch thành bài mới
     *
     * @param array $slots { post_id, slug, target_lang, tone, session_id }
     * @return array Tool Output Envelope
     */
    public static function translate_and_publish( array $slots ): array {
        $post_ref    = $slots['post_id']     ?? '';
        $target_lang = $slots['target_lang'] ?? 'en';
        $tone        = $slots['tone']        ?? 'natural';
        $session_id  = $slots['session_id']  ?? '';
        $chat_id     = $slots['chat_id']     ?? '';
        $meta        = $slots['_meta']        ?? [];
        $ai_context  = $meta['_context']      ?? '';

        $lang_names = [
            'en' => 'tiếng Anh', 'ja' => 'tiếng Nhật', 'ko' => 'tiếng Hàn',
            'zh' => 'tiếng Trung', 'th' => 'tiếng Thái', 'vi' => 'tiếng Việt',
            'fr' => 'tiếng Pháp', 'de' => 'tiếng Đức', 'es' => 'tiếng Tây Ban Nha',
        ];
        $lang_label = $lang_names[ $target_lang ] ?? $target_lang;

        if ( empty( $post_ref ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Cần ID, slug hoặc tiêu đề bài muốn dịch.',
                'data' => [], 'missing_fields' => [ 'post_id' ],
            ];
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module AI (OpenRouter) chưa sẵn sàng.', 'data' => [],
            ];
        }

        // ── Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start(
                $session_id ?: $chat_id ?: 'cli', 'translate_and_publish',
                [ 'T1' => 'Tìm bài viết gốc', 'T2' => "Dịch sang {$lang_label}", 'T3' => 'Đăng bài dịch' ]
            );
        }

        // ── T1: Tìm bài viết ──
        if ( $trace ) $trace->step( 'T1', 'running' );

        $post = self::find_post( $post_ref );

        if ( ! $post ) {
            if ( $trace ) $trace->fail( 'Không tìm thấy bài viết' );
            return [
                'success' => false, 'complete' => false,
                'message' => "❌ Không tìm thấy bài viết: \"{$post_ref}\".",
                'data' => [], 'missing_fields' => [ 'post_id' ],
            ];
        }

        if ( $trace ) $trace->step( 'T1', 'done', [ 'post_id' => $post->ID, 'title' => $post->post_title ] );

        // ── T2: AI dịch ──
        if ( $trace ) $trace->step( 'T2', 'running' );

        $translate_prompt = "Dịch bài viết sau sang {$lang_label}, tone {$tone}.\n"
            . "Giữ nguyên cấu trúc HTML (headings, bold, italic). Không dùng markdown.\n"
            . "Dịch tự nhiên, không dịch máy.\n\n"
            . "Tiêu đề: {$post->post_title}\n"
            . "Nội dung:\n" . mb_substr( $post->post_content, 0, 4000 ) . "\n\n"
            . "Trả về JSON: {\"title\": \"...\", \"content\": \"...\"}";

        $sys_translate = 'Bạn là dịch giả chuyên nghiệp. Chỉ trả JSON.';
        if ( $ai_context ) {
            $sys_translate .= "\n\n" . $ai_context;
        }

        // ── Phase 1.1h: Inject skill context (R3 compliance) ──
        $skill_content = self::resolve_skill_content( $meta, 'translate_and_publish' );
        if ( $skill_content ) {
            $sys_translate .= "\n\n[Skill Context — Hướng dẫn chuyên môn]\n" . $skill_content;
        }

        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys_translate ],
            [ 'role' => 'user',   'content' => $translate_prompt ],
        ], [ 'temperature' => 0.5, 'max_tokens' => 4000 ] );

        $parsed = self::parse_json_response( $ai_result['message'] ?? '' );
        $translated_title   = $parsed['title']   ?? '';
        $translated_content = $parsed['content'] ?? '';

        if ( empty( $translated_content ) ) {
            if ( $trace ) $trace->fail( 'AI không trả nội dung dịch' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ AI không dịch được. Thử lại.', 'data' => [],
            ];
        }

        if ( function_exists( 'twf_clean_post_content' ) ) {
            $translated_content = twf_clean_post_content( $translated_content );
        }

        if ( $trace ) $trace->step( 'T2', 'done', [ 'translated_title' => $translated_title ] );

        // ── T3: Đăng bài dịch mới ──
        if ( $trace ) $trace->step( 'T3', 'running' );

        $image_url = '';
        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( $thumb_id ) {
            $image_url = wp_get_attachment_url( $thumb_id );
        }

        $new_post_id = wp_insert_post( [
            'post_title'   => sanitize_text_field( $translated_title ),
            'post_content' => wp_kses_post( $translated_content ),
            'post_status'  => 'publish',
            'post_author'  => $post->post_author,
        ] );

        if ( ! $new_post_id || is_wp_error( $new_post_id ) ) {
            if ( $trace ) $trace->fail( 'wp_insert_post failed' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Không thể đăng bài dịch.', 'data' => [],
            ];
        }

        // Copy featured image
        if ( $thumb_id ) {
            set_post_thumbnail( $new_post_id, $thumb_id );
        }

        // Link gốc
        update_post_meta( $new_post_id, '_translated_from', $post->ID );
        update_post_meta( $new_post_id, '_translated_lang', $target_lang );

        $permalink = get_permalink( $new_post_id );
        $edit_url  = admin_url( 'post.php?post=' . $new_post_id . '&action=edit' );

        if ( $trace ) $trace->step( 'T3', 'done', [ 'post_id' => $new_post_id ] );
        if ( $trace ) $trace->complete( [ 'post_id' => $new_post_id, 'permalink' => $permalink ] );

        return [
            'success' => true, 'complete' => true,
            'message' => "✅ Đã dịch & đăng bài {$lang_label}: **{$translated_title}**\n🔗 {$permalink}\n✏️ [Chỉnh sửa]({$edit_url})\n📄 Bài gốc: {$post->post_title}",
            'data' => [
                'id' => $new_post_id, 'type' => 'article', 'title' => $translated_title,
                'url' => $permalink, 'edit_url' => $edit_url,
                'source_post_id' => $post->ID, 'target_lang' => $target_lang,
                'image_url' => $image_url, 'platform' => 'wordpress',
                'trace_id' => $trace ? $trace->get_trace_id() : '',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  Helper: find_post — Tìm bài viết bằng ID, slug, hoặc keyword
     * ══════════════════════════════════════════════════════════ */

    /**
     * Find a post by numeric ID, slug, or keyword search.
     *
     * @param string $ref  Post ID, slug, or keyword.
     * @return \WP_Post|null
     */
    private static function find_post( string $ref ): ?\WP_Post {
        $ref = trim( $ref );
        if ( empty( $ref ) ) return null;

        // 1. Numeric ID
        if ( is_numeric( $ref ) ) {
            $post = get_post( (int) $ref );
            if ( $post && $post->post_type === 'post' ) return $post;
        }

        // 2. Slug
        $by_slug = get_page_by_path( $ref, OBJECT, 'post' );
        if ( $by_slug ) return $by_slug;

        // 3. Keyword search (title match)
        $found = get_posts( [
            'post_type'      => 'post',
            'post_status'    => [ 'publish', 'draft', 'private' ],
            's'              => $ref,
            'posts_per_page' => 1,
            'orderby'        => 'relevance',
        ] );
        if ( ! empty( $found ) ) return $found[0];

        // 4. "mới nhất" / "gần nhất" / "latest"
        if ( preg_match( '/mới nhất|gần nhất|latest|last|cuối/ui', $ref ) ) {
            $latest = get_posts( [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ] );
            if ( ! empty( $latest ) ) return $latest[0];
        }

        return null;
    }

    /* ══════════════════════════════════════════════════════════
     *  Helper: parse_json_response — Extract JSON from AI text
     * ══════════════════════════════════════════════════════════ */

    /**
     * Parse JSON from AI response (handles code fences, partial JSON).
     *
     * @param string $raw
     * @return array
     */
    private static function parse_json_response( string $raw ): array {
        if ( empty( $raw ) ) return [];

        // Strip code fences
        $clean = trim( $raw );
        $clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
        $clean = preg_replace( '/```\s*$/', '', $clean );

        // Try direct decode
        $parsed = json_decode( $clean, true );
        if ( is_array( $parsed ) ) return $parsed;

        // Extract first JSON block
        if ( preg_match( '/\{[\s\S]*\}/', $clean, $m ) ) {
            $parsed = json_decode( $m[0], true );
            if ( is_array( $parsed ) ) return $parsed;
        }

        // Fallback: use bizcity-admin-hook parser
        if ( function_exists( 'twf_parse_post_fields_from_ai' ) ) {
            return twf_parse_post_fields_from_ai( $raw );
        }

        return [];
    }

    /**
     * schedule_post — Self-contained 2-step pipeline.
     *
     * T1: AI phân tích yêu cầu (trích xuất title, content, datetime)
     * T2: Tạo scheduled post
     *
     * @param array $slots {
     *   topic      - Chủ đề / nội dung yêu cầu
     *   datetime   - Thời gian đăng YYYY-MM-DD HH:mm (optional, AI phân tích từ message)
     *   title      - Tiêu đề bài (optional)
     *   content    - Nội dung bài (optional)
     *   session_id - Chat session (auto-injected)
     * }
     * @return array Tool Output Envelope
     */
    public static function schedule_post( array $slots ): array {
        $chat_id    = $slots['chat_id']   ?? '';
        $datetime   = $slots['datetime']  ?? '';
        $session_id = $slots['session_id'] ?? '';
        $meta       = $slots['_meta']      ?? [];
        $ai_context = $meta['_context']    ?? '';

        $text = self::extract_text( $slots );
        $pipeline_title   = $slots['title']   ?? '';
        $pipeline_content = $slots['content'] ?? '';

        if ( empty( $text ) && empty( $pipeline_title ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❌ Vui lòng mô tả nội dung bài và thời gian muốn đăng.',
                'data'           => [],
                'missing_fields' => [ 'topic' ],
            ];
        }

        if ( ! function_exists( 'twf_parse_schedule_post_ai' ) ) {
            return [
                'success'  => false,
                'complete' => false,
                'message'  => '❌ Module lên lịch chưa được load (bizcity-admin-hook required).',
                'data'     => [],
            ];
        }

        // ── Start Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start(
                $session_id ?: $chat_id ?: 'cli',
                'schedule_post',
                [ 'T1' => 'Phân tích yêu cầu', 'T2' => 'Lên lịch đăng bài' ]
            );
        }

        // ── T1: Parse ──
        if ( $trace ) $trace->step( 'T1', 'running' );

        $parse_input = $text ?: $pipeline_title;
        if ( $pipeline_content ) {
            $parse_input .= "\nNội dung: " . substr( $pipeline_content, 0, 500 );
        }

        $schedule      = twf_parse_schedule_post_ai( $parse_input );
        $post_title    = $schedule['post_title']    ?? $pipeline_title  ?? wp_trim_words( $text ?: $pipeline_title, 10 );
        $post_content  = $schedule['post_content']  ?? $pipeline_content ?? $text;
        $post_datetime = $datetime ?: ( $schedule['post_datetime'] ?? '' );
        $image_url     = $schedule['post_image_url'] ?? '';

        if ( $trace ) $trace->step( 'T1', 'done', [ 'post_datetime' => $post_datetime ] );

        if ( empty( $post_datetime ) ) {
            if ( $trace ) $trace->fail( 'Missing datetime' );
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❌ Chưa xác định được thời gian đăng bài. Vui lòng chỉ rõ giờ/ngày.',
                'data'           => [],
                'missing_fields' => [ 'datetime' ],
            ];
        }

        // ── T2: Create scheduled post ──
        if ( $trace ) $trace->step( 'T2', 'running' );

        $post_id = wp_insert_post( [
            'post_title'    => sanitize_text_field( $post_title ),
            'post_content'  => wp_kses_post( $post_content ),
            'post_status'   => 'future',
            'post_date'     => date( 'Y-m-d H:i:s', strtotime( $post_datetime ) ),
            'post_date_gmt' => get_gmt_from_date( $post_datetime ),
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            if ( $trace ) $trace->step( 'T2', 'failed', [], 'wp_insert_post failed' );
            if ( $trace ) $trace->fail( 'Không thể tạo bài viết' );
            return [
                'success'  => false,
                'complete' => false,
                'message'  => '❌ Không thể lên lịch bài viết.',
                'data'     => [],
            ];
        }

        if ( $image_url && function_exists( 'twf_upload_image_to_media_library' ) ) {
            $att_id = twf_upload_image_to_media_library( $image_url, $post_title );
            if ( $att_id ) set_post_thumbnail( $post_id, $att_id );
        }

        $edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

        if ( $trace ) $trace->step( 'T2', 'done', [ 'post_id' => $post_id ] );
        if ( $trace ) $trace->complete( [ 'post_id' => $post_id ] );

        if ( $chat_id && function_exists( 'twf_telegram_send_message' ) ) {
            twf_telegram_send_message( $chat_id, "📅 Đã lên lịch đăng bài:\n📌 {$post_title}\n🕐 {$post_datetime}\n✏️ {$edit_url}" );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "📅 Đã lên lịch đăng bài: **{$post_title}** vào {$post_datetime}\n✏️ [Chỉnh sửa]({$edit_url})",
            'data'     => [
                'id'        => $post_id,
                'type'      => 'article',
                'title'     => $post_title,
                'content'   => $post_content,
                'url'       => get_permalink( $post_id ),
                'image_url' => $image_url,
                'edit_url'  => $edit_url,
                'platform'  => 'wordpress',
                'trace_id'  => $trace ? $trace->get_trace_id() : '',
                'meta'      => [ 'scheduled_at' => $post_datetime, 'status' => 'future' ],
            ],
        ];
    }
}
