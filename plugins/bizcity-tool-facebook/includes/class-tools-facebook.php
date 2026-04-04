<?php
/**
 * BizCity Tool Facebook — Tool Callbacks
 *
 * Self-contained AI content generation & Facebook posting.
 * No dependency on bizcity-admin-hook/flows/bizgpt_facebook.php.
 *
 * Tools:
 *   - create_facebook_post: AI viết nội dung từ chủ đề → đăng lên Page(s)
 *   - post_facebook: Pipeline tool — nhận content sẵn → đăng trực tiếp
 *   - list_facebook_posts: Xem danh sách bài đã đăng
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Facebook {

    /** Tone → AI system prompt segment */
    const TONE_MAP = [
        'engaging'     => 'Viết lôi cuốn, viral, nhiều emoji, kêu gọi tương tác mạnh.',
        'professional' => 'Viết chuyên nghiệp, đáng tin cậy, dẫn chứng rõ ràng.',
        'friendly'     => 'Viết thân thiện, gần gũi như bạn bè, nhẹ nhàng.',
        'promotional'  => 'Viết dạng khuyến mãi, nhấn mạnh ưu đãi, tạo urgency.',
        'storytelling' => 'Viết kể chuyện hấp dẫn, có mở đầu - cao trào - kết thúc.',
    ];

    private static function log( string $msg, $data = null ): void {
        $entry = '[BizCity Tool Facebook] ' . $msg;
        if ( $data !== null ) {
            $entry .= ' | ' . ( is_string( $data ) ? $data : wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        }
        error_log( $entry );
    }

    /* ══════════════════════════════════════════════════════
     *  PRIMARY TOOL: create_facebook_post
     *  AI generates title + content from topic, then posts
     * ══════════════════════════════════════════════════════ */
    public static function create_facebook_post( array $slots ): array {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $topic     = sanitize_textarea_field( $slots['topic'] ?? '' );
        $image_url = esc_url_raw( $slots['image_url'] ?? '' );
        $tone      = sanitize_text_field( $slots['tone'] ?? 'engaging' );
        $page_id   = sanitize_text_field( $slots['page_id'] ?? '' );
        $page_ids  = $slots['page_ids'] ?? array();
        $session_id = $slots['session_id'] ?? $slots['chat_id'] ?? '';
        $meta      = $slots['_meta'] ?? [];

        if ( empty( $topic ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '📝 Bạn muốn đăng bài về chủ đề gì? Mô tả càng chi tiết (tone, đối tượng, CTA) thì bài càng hay!',
                'missing_fields' => [ 'topic' ],
                'data'           => [],
            ];
        }

        // ── Start Job Trace (4-5 steps) ──
        $has_image_input = ! empty( $image_url );
        $step_map = [ 'T1' => 'AI tạo nội dung bài' ];
        if ( ! $has_image_input ) {
            $step_map['T2'] = 'Tạo ảnh bìa';
        }
        $step_map['T3'] = 'Lưu bài WordPress';
        $step_map['T4'] = 'Gắn ảnh thumbnail';
        $step_map['T5'] = 'Đăng lên Facebook Page';

        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: 'cli', 'create_facebook_post', $step_map );
        }

        // Validate image_url extension if provided
        if ( ! empty( $image_url ) ) {
            $parsed_path = wp_parse_url( $image_url, PHP_URL_PATH );
            $ext = strtolower( pathinfo( $parsed_path ?: '', PATHINFO_EXTENSION ) );
            $valid_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' );
            if ( $ext && ! in_array( $ext, $valid_ext, true ) ) {
                $image_url = '';
            }
        }

        // ── T2: Generate image if not provided ──
        if ( empty( $image_url ) && function_exists( 'twf_generate_image_url' ) ) {
            if ( $trace ) $trace->step( 'T2', 'running' );
            $image_url = twf_generate_image_url( $topic );
            if ( $trace ) $trace->step( 'T2', $image_url ? 'done' : 'skipped', [ 'image_url' => $image_url ?: '(none)' ] );
        }

        // ── T1: AI generate title + content ──
        if ( $trace ) $trace->step( 'T1', 'running' );
        $ai_result = self::ai_generate_content( $topic, $tone, $meta );
        $ai_title   = $ai_result['title'] ?? 'Bài Facebook mới';
        $ai_content = $ai_result['content'] ?? $topic;

        $plain_title   = function_exists( 'bztfb_clean_plain_text' ) ? bztfb_clean_plain_text( $ai_title ) : wp_strip_all_tags( $ai_title );
        $plain_content = function_exists( 'bztfb_clean_plain_text' ) ? bztfb_clean_plain_text( $ai_content ) : wp_strip_all_tags( $ai_content );
        if ( $trace ) $trace->step( 'T1', 'done', [ 'title' => $plain_title ] );

        // ── T3: Save as WordPress post (biz_facebook) ──
        if ( $trace ) $trace->step( 'T3', 'running' );
        $post_id = wp_insert_post( array(
            'post_title'   => $plain_title,
            'post_content' => $plain_content,
            'post_type'    => 'biz_facebook',
            'post_status'  => 'publish',
            'post_author'  => $user_id ?: 1,
        ) );

        if ( is_wp_error( $post_id ) ) {
            self::log( 'WP post creation failed', $post_id->get_error_message() );
            if ( $trace ) $trace->fail( 'WP post creation failed: ' . $post_id->get_error_message() );
            return [
                'success' => false, 'complete' => true,
                'message' => '❌ Lỗi tạo bài WordPress: ' . $post_id->get_error_message(),
                'data'    => [],
            ];
        }
        if ( $trace ) $trace->step( 'T3', 'done', [ 'post_id' => $post_id ] );

        // ── T4: Attach image as thumbnail ──
        if ( $post_id && $image_url ) {
            if ( $trace ) $trace->step( 'T4', 'running' );
            self::attach_image_to_post( $post_id, $image_url );
            if ( $trace ) $trace->step( 'T4', 'done', [ 'image_url' => $image_url ] );
        } else {
            if ( $trace && isset( $step_map['T4'] ) ) $trace->step( 'T4', 'skipped' );
        }

        // ── T5: Post to Facebook Page(s) ──
        if ( $trace ) $trace->step( 'T5', 'running' );
        $fb_results = self::post_to_facebook_pages( $plain_title, $plain_content, $image_url, $page_id, $page_ids, (int) $user_id );
        if ( $trace ) $trace->step( 'T5', 'done', [ 'fb_count' => count( $fb_results ) ] );

        // Step 5: Update job record if job_id provided
        if ( ! empty( $slots['job_id'] ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'bztfb_jobs';
            $wpdb->update( $table, array(
                'ai_title'    => $plain_title,
                'ai_content'  => $plain_content,
                'wp_post_id'  => $post_id,
                'fb_post_ids' => wp_json_encode( $fb_results ),
                'status'      => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ), array( 'id' => (int) $slots['job_id'] ) );
        }

        // ── Mark trace complete ──
        if ( $trace ) $trace->complete( [ 'post_id' => $post_id, 'fb_count' => count( $fb_results ) ] );

        // Build response message
        $msg  = "✅ **Đã đăng bài Facebook thành công!**\n\n";
        $msg .= "📝 **Tiêu đề:** {$plain_title}\n";
        $msg .= "🔗 **WordPress:** " . get_permalink( $post_id ) . "\n";
        if ( $fb_results ) {
            foreach ( $fb_results as $fb ) {
                if ( ! empty( $fb['link'] ) ) {
                    $msg .= "📣 **Facebook:** {$fb['link']}\n";
                }
            }
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => [
                'wp_post_id'  => $post_id,
                'title'       => $plain_title,
                'content'     => $plain_content,
                'image_url'   => $image_url,
                'url'         => get_permalink( $post_id ),
                'fb_post_ids' => $fb_results,
                'platform'    => 'facebook',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════
     *  PIPELINE TOOL: post_facebook
     *  Posts pre-made content to Facebook (no AI generation)
     * ══════════════════════════════════════════════════════ */
    public static function post_facebook( array $slots ): array {
        $chat_id   = $slots['chat_id']   ?? '';
        $session_id = $slots['session_id'] ?? $chat_id;
        $image_url = $slots['image_url'] ?? '';
        $page_id   = $slots['page_id']   ?? '';
        $page_ids  = $slots['page_ids']  ?? array();
        $user_id   = $slots['user_id']   ?? get_current_user_id();

        $message = $slots['message'] ?? '';
        if ( is_string( $message ) ) {
            $message = array( 'text' => $message );
        }

        $pipeline_content = $slots['content'] ?? '';
        $pipeline_title   = $slots['title']   ?? '';
        $pipeline_url     = $slots['url']     ?? '';

        // Use message text if available, otherwise use pipeline content
        $msg_text = $message['text'] ?? $message['caption'] ?? '';
        $text = ! empty( $msg_text ) ? $msg_text : $pipeline_content;

        if ( empty( $text ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❌ Cần cung cấp nội dung bài để đăng Facebook.',
                'data'           => [],
                'missing_fields' => [ 'message' ],
            ];
        }

        // ── Start Job Trace ──
        $trace = null;
        $step_map = [
            'T1' => 'Lưu bài WordPress',
            'T2' => 'Gắn ảnh thumbnail',
            'T3' => 'Đăng lên Facebook Page',
        ];
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start( $session_id ?: 'cli', 'post_facebook', $step_map );
        }

        if ( $pipeline_url ) {
            $text .= "\n\n🔗 {$pipeline_url}";
        }

        $title = $pipeline_title ?: wp_trim_words( $text, 10 );

        // Clean text
        $plain_title   = function_exists( 'bztfb_clean_plain_text' ) ? bztfb_clean_plain_text( $title ) : wp_strip_all_tags( $title );
        $plain_content = function_exists( 'bztfb_clean_plain_text' ) ? bztfb_clean_plain_text( $text ) : wp_strip_all_tags( $text );

        // ── T1: Save as WordPress post ──
        if ( $trace ) $trace->step( 'T1', 'running' );
        $post_id = wp_insert_post( array(
            'post_title'   => $plain_title,
            'post_content' => $plain_content,
            'post_type'    => 'biz_facebook',
            'post_status'  => 'publish',
            'post_author'  => $user_id ?: 1,
        ) );

        if ( is_wp_error( $post_id ) ) {
            self::log( 'WP post creation failed', $post_id->get_error_message() );
            if ( $trace ) $trace->fail( 'WP post failed: ' . $post_id->get_error_message() );
        } else {
            if ( $trace ) $trace->step( 'T1', 'done', [ 'post_id' => $post_id ] );

            // ── T2: Attach image ──
            if ( $post_id && $image_url ) {
                if ( $trace ) $trace->step( 'T2', 'running' );
                self::attach_image_to_post( $post_id, $image_url );
                if ( $trace ) $trace->step( 'T2', 'done' );
            } else {
                if ( $trace ) $trace->step( 'T2', 'skipped' );
            }
        }

        // ── T3: Post to Facebook Page(s) ──
        if ( $trace ) $trace->step( 'T3', 'running' );
        $fb_results = self::post_to_facebook_pages( $plain_title, $plain_content, $image_url, $page_id, $page_ids, (int) $user_id );
        if ( $trace ) $trace->step( 'T3', 'done', [ 'fb_count' => count( $fb_results ) ] );

        // Update job record if provided
        if ( ! empty( $slots['job_id'] ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'bztfb_jobs';
            $wpdb->update( $table, array(
                'wp_post_id'   => $post_id ?: null,
                'fb_post_ids'  => wp_json_encode( $fb_results ),
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ), array( 'id' => (int) $slots['job_id'] ) );
        }

        // ── Mark trace complete ──
        if ( $trace ) $trace->complete( [ 'post_id' => $post_id ?: null, 'fb_count' => count( $fb_results ) ] );

        // Build response message
        $msg = "✅ Đã đăng bài lên Facebook Page.\n";
        if ( $post_id && ! is_wp_error( $post_id ) ) {
            $msg .= "🔗 WordPress: " . get_permalink( $post_id ) . "\n";
        }
        if ( $fb_results ) {
            foreach ( $fb_results as $fb ) {
                if ( ! empty( $fb['link'] ) ) {
                    $msg .= "📣 Facebook: {$fb['link']}\n";
                }
            }
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => [
                'type'        => 'post',
                'title'       => $plain_title,
                'content'     => $plain_content,
                'wp_post_id'  => $post_id ?: null,
                'image_url'   => $image_url,
                'platform'    => 'facebook',
                'fb_post_ids' => $fb_results,
                'meta'        => [ 'page_id' => $page_id, 'source_url' => $pipeline_url ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════
     *  LIST TOOL: list_facebook_posts
     * ══════════════════════════════════════════════════════ */
    public static function list_facebook_posts( array $slots ): array {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $limit   = (int) ( $slots['limit'] ?? 10 );
        $limit   = max( 1, min( 50, $limit ) );

        $args = array(
            'post_type'      => 'biz_facebook',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'publish',
        );
        if ( $user_id ) {
            $args['author'] = $user_id;
        }

        $posts = get_posts( $args );
        $items = array();
        foreach ( $posts as $post ) {
            $items[] = array(
                'id'    => $post->ID,
                'title' => $post->post_title,
                'url'   => get_permalink( $post ),
                'date'  => $post->post_date,
                'thumb' => get_the_post_thumbnail_url( $post->ID, 'thumbnail' ) ?: '',
            );
        }

        if ( empty( $items ) ) {
            return [
                'success' => true, 'complete' => true,
                'message' => 'Chưa có bài Facebook nào được tạo.',
                'data'    => [],
            ];
        }

        $msg = "📋 **Bài Facebook gần đây ({$limit} bài):**\n\n";
        foreach ( $items as $i => $item ) {
            $msg .= ( $i + 1 ) . ". [{$item['title']}]({$item['url']}) — {$item['date']}\n";
        }

        return [
            'success' => true, 'complete' => true,
            'message' => $msg,
            'data'    => $items,
        ];
    }

    /* ══════════════════════════════════════════════════════
     *  HELPERS
     * ══════════════════════════════════════════════════════ */

    /**
     * AI generate content for preview only (public wrapper).
     * Returns { title, content } — no WP post, no FB posting.
     */
    public static function ai_generate_preview( string $topic, string $tone = 'engaging' ): array {
        return self::ai_generate_content( $topic, $tone );
    }

    /**
     * AI generate Facebook title + content from topic.
     */
    private static function ai_generate_content( string $topic, string $tone = 'engaging', array $meta = [] ): array {
        $tone_instruction = self::TONE_MAP[ $tone ] ?? self::TONE_MAP['engaging'];

        // ── Phase 1.1h: Resolve skill context (R3 compliance) ──
        $skill_hint = '';
        $skill = $meta['_skill'] ?? null;
        if ( ! $skill && class_exists( 'BizCity_Tool_Run' ) ) {
            $skill = BizCity_Tool_Run::resolve_skill( 'create_facebook_post', get_current_user_id() );
        }
        if ( $skill && ! empty( $skill['content'] ) ) {
            $skill_hint = "\n\n[Skill Context — Hướng dẫn thương hiệu]\n" . $skill['content'];
        }

        $ai_prompt = <<<PROMPT
Bạn là chuyên gia nội dung Facebook Marketing. Viết một bài post Facebook tiếng Việt, hấp dẫn, không quá 300 chữ, chủ đề:
{$topic}

Yêu cầu:
- Sinh tiêu đề ngắn gọn, sáng tạo (max 90 ký tự).
- {$tone_instruction}
- Nội dung có emoji phù hợp, lời kêu gọi hành động ở cuối.
- Có 5 hashtag tóm tắt ở dưới cùng.
- Trả về đúng JSON (không có text nào khác ngoài JSON):
{
  "title": "...",
  "content": "..."
}
PROMPT;

        // Build messages array — system message carries skill context
        $sys_msg = 'Bạn là chuyên gia Facebook Marketing. Chỉ trả JSON, không giải thích.';
        if ( $skill_hint ) {
            $sys_msg .= $skill_hint;
        }

        // Unified wrapper — routes through gateway on client sites, direct on hub
        if ( function_exists( 'bizcity_openrouter_chat' ) ) {
            $ai_result = bizcity_openrouter_chat( [
                [ 'role' => 'system', 'content' => $sys_msg ],
                [ 'role' => 'user',   'content' => $ai_prompt ],
            ], [ 'temperature' => 0.75, 'max_tokens' => 2000 ] );

            $json_str = $ai_result['message'] ?? '';
            if ( ( $pos = strpos( $json_str, '{' ) ) !== false ) $json_str = substr( $json_str, $pos );
            if ( ( $pos = strrpos( $json_str, '}' ) ) !== false ) $json_str = substr( $json_str, 0, $pos + 1 );
            $parsed = json_decode( $json_str, true );

            return array(
                'title'   => $parsed['title'] ?? 'Bài Facebook mới',
                'content' => $parsed['content'] ?? $topic,
            );
        }

        // No wrapper available — standalone without any bizcity LLM plugin
        self::log( 'No bizcity_openrouter_chat() available — AI content generation skipped' );
        return array( 'title' => 'Bài Facebook mới', 'content' => $topic );
    }

    /**
     * Attach external image to WordPress post as featured image.
     */
    private static function attach_image_to_post( int $post_id, string $image_url ): void {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            self::log( 'Image download failed', $tmp->get_error_message() );
            return;
        }

        $url_path = wp_parse_url( $image_url, PHP_URL_PATH );
        $file = array(
            'name'     => basename( $url_path ?: 'image.jpg' ),
            'type'     => 'image/jpeg',
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize( $tmp ),
        );

        $attach_id = media_handle_sideload( $file, $post_id );
        if ( ! is_wp_error( $attach_id ) ) {
            set_post_thumbnail( $post_id, $attach_id );
        }
        @unlink( $tmp );
    }

    /**
     * Get the assigned page data for a user.
     * Uses own bztfb_pages table (standalone — no bizcity-facebook-bot dependency).
     * Returns { page_id, page_name, page_access_token, ig_account_id, category } or null.
     */
    public static function get_user_page( int $user_id = 0 ): ?array {
        if ( ! $user_id ) $user_id = get_current_user_id();
        if ( ! $user_id ) return null;

        $page_id = get_user_meta( $user_id, 'bztfb_user_page', true );
        if ( empty( $page_id ) ) return null;

        if ( class_exists( 'BizCity_FB_Database' ) ) {
            return BizCity_FB_Database::get_page( $page_id );
        }

        return null;
    }

    /**
     * Post content to Facebook Page(s) via BizCity_FB_Graph_API.
     *
     * Resolution order:
     *   1. Explicit $single_page_id or $page_ids from caller
     *   2. User's assigned page (user_meta: bztfb_user_page)
     *   3. All active site pages (bztfb_pages table)
     *
     * @return array  Array of { page_id, post_id, link } for each successful post.
     */
    private static function post_to_facebook_pages( string $title, string $content, string $image_url = '', string $single_page_id = '', array $page_ids = array(), int $user_id = 0 ): array {

        if ( ! class_exists( 'BizCity_FB_Database' ) || ! class_exists( 'BizCity_FB_Graph_API' ) ) {
            self::log( 'BizCity_FB_Database or BizCity_FB_Graph_API not available' );
            return array();
        }

        // Get all active pages from own DB
        $all_pages = BizCity_FB_Database::get_active_pages();
        if ( ! is_array( $all_pages ) ) $all_pages = array();

        // Filter to requested page(s)
        if ( ! empty( $single_page_id ) ) {
            $all_pages = array_filter( $all_pages, fn( $p ) => ( $p['page_id'] ?? '' ) === $single_page_id );
        } elseif ( ! empty( $page_ids ) ) {
            $all_pages = array_filter( $all_pages, fn( $p ) => in_array( $p['page_id'] ?? '', $page_ids, true ) );
        } else {
            // No explicit page requested — use user's assigned page if set
            $user_page = self::get_user_page( $user_id ?: get_current_user_id() );
            if ( $user_page ) {
                $all_pages = array( $user_page );
            }
        }

        if ( empty( $all_pages ) ) {
            self::log( 'FB page resolution: NO PAGES FOUND', array(
                'single_page_id' => $single_page_id,
                'page_ids'       => $page_ids,
                'user_id'        => $user_id ?: get_current_user_id(),
            ) );
            return array();
        }

        $results = array();
        $caption = "{$title}\n\n{$content}";

        foreach ( $all_pages as $page ) {
            $page_id    = $page['page_id']           ?? ( $page['id'] ?? '' );
            $page_token = $page['page_access_token'] ?? ( $page['access_token'] ?? '' );

            if ( empty( $page_id ) || empty( $page_token ) ) continue;

            $api    = new BizCity_FB_Graph_API( $page_token, $page_id );
            $result = ! empty( $image_url )
                ? $api->post_photo( $image_url, $caption )
                : $api->post_text( $caption );

            if ( $result['success'] ?? false ) {
                $fb_post_id = $result['post_id'] ?? '';
                $link       = $fb_post_id ? "https://www.facebook.com/{$page_id}/posts/{$fb_post_id}" : '';

                $results[] = array(
                    'page_id' => $page_id,
                    'post_id' => $fb_post_id,
                    'link'    => $link,
                );

                // Log to own DB
                BizCity_FB_Database::log_post( [
                    'page_id'    => $page_id,
                    'fb_post_id' => $fb_post_id,
                    'content'    => $caption,
                    'image_url'  => $image_url,
                    'post_url'   => $link,
                    'status'     => 'published',
                ] );

                self::log( "Posted to page {$page_id}", $fb_post_id );
            } else {
                self::log( "FB post failed for page {$page_id}", $result['error'] ?? 'unknown error' );
            }
        }

        return $results;
    }
}
