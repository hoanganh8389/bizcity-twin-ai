<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Chat Gateway — Unified entry point for all chat interfaces
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Single entry point for ALL chat interfaces:
 *   • WEBCHAT   — front-end widgets, shortcodes, embed (public)
 *   • ADMINCHAT — admin dashboard, knowledge chat, floating widget (admin-only)
 *
 * Replaces the previous split architecture where:
 *   - bizcity-bot-webchat/bootstrap.php had its own send/history endpoints
 *   - bizcity-knowledge/class-admin-chat.php had separate admin endpoints
 *
 * All AI processing goes through one pipeline:
 *   Context API (embeddings + quick knowledge + intent tag routing) → keyword search → LLM
 *
 * Designed for easy extension to automation triggers.
 *
 * @package BizCity_Knowledge
 * @since   1.3.0
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Chat_Gateway {

    /* ─── Singleton ─── */
    private static $instance = null;

    /** @var int|null  Override max_tokens for current request (e.g. WEBCHAT = 500). */
    private $max_tokens_override = null;

    /** @var int  KCI Ratio for current request (0-100, default 80). */
    private $current_kci_ratio = 80;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ─── Constructor ─── */
    public function __construct() {
        // ── Unified AJAX endpoints (admin-only with nonce) ──
        add_action('wp_ajax_bizcity_chat_send',    [$this, 'ajax_send']);
        add_action('wp_ajax_bizcity_chat_history', [$this, 'ajax_history']);
        add_action('wp_ajax_bizcity_chat_clear',   [$this, 'ajax_clear']);
        add_action('wp_ajax_bizcity_chat_set_kci_ratio', [$this, 'ajax_set_kci_ratio']);

        // ── SSE stream endpoint (single stream entrypoint) ──
        add_action('wp_ajax_bizcity_chat_stream',        [$this, 'ajax_stream'], 20);
        add_action('wp_ajax_nopriv_bizcity_chat_stream', [$this, 'ajax_stream'], 20);

        // ── Public (nopriv) endpoints for WEBCHAT ──
        add_action('wp_ajax_nopriv_bizcity_chat_send',    [$this, 'ajax_send']);
        add_action('wp_ajax_nopriv_bizcity_chat_history', [$this, 'ajax_history']);
        add_action('wp_ajax_nopriv_bizcity_chat_clear',   [$this, 'ajax_clear']);

        // ── Backward-compat: keep old action names working (redirect) ──
        // Admin chat (knowledge chat + floating widget)
        add_action('wp_ajax_bizcity_admin_chat_send',    [$this, 'ajax_send']);
        add_action('wp_ajax_bizcity_admin_chat_history', [$this, 'ajax_history']);
        add_action('wp_ajax_bizcity_admin_chat_clear',   [$this, 'ajax_clear']);

        // Webchat (shortcode + widget-float)
        add_action('wp_ajax_bizcity_webchat_send_message',        [$this, 'ajax_send']);
        add_action('wp_ajax_nopriv_bizcity_webchat_send_message', [$this, 'ajax_send']);
        add_action('wp_ajax_bizcity_webchat_send',                [$this, 'ajax_send']);
        add_action('wp_ajax_nopriv_bizcity_webchat_send',         [$this, 'ajax_send']);
        add_action('wp_ajax_bizcity_webchat_history',             [$this, 'ajax_history']);
        add_action('wp_ajax_nopriv_bizcity_webchat_history',      [$this, 'ajax_history']);

        // ── Localize JS vars for admin ──
        add_action('admin_enqueue_scripts', [$this, 'localize_admin_vars'], 99);
    }

    /* ================================================================
     * Localize JS vars — admin pages
     * ================================================================ */
    public function localize_admin_vars() {
        if (!is_admin()) return;

        $character_id = $this->get_default_character_id();
        $characters   = [];

        if (class_exists('BizCity_Knowledge_Database')) {
            $db = BizCity_Knowledge_Database::instance();
            $chars_raw = $db->get_characters(['status' => 'active', 'limit' => 100]);
            foreach ($chars_raw as $ch) {
                $characters[] = [
                    'id'     => (int) $ch->id,
                    'name'   => $ch->name,
                    'avatar' => $ch->avatar ?: '',
                    'model'  => $ch->model_id ?: 'GPT-4o-mini',
                ];
            }
        }

        $character = null;
        if ($character_id && class_exists('BizCity_Knowledge_Database')) {
            $character = BizCity_Knowledge_Database::instance()->get_character($character_id);
        }

        $data = [
            'ajaxurl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('bizcity_chat'),
            'session_id'     => $this->get_session_id('ADMINCHAT'),
            'user_id'        => get_current_user_id(),
            'character_id'   => $character_id,
            'character_name' => $character ? $character->name : 'AI Assistant',
            'characters'     => $characters,
            'platform_type'  => 'ADMINCHAT',
            'chat_page_url'  => admin_url('admin.php?page=bizcity-knowledge-chat'),
            // Actions — JS should use these instead of hardcoded strings
            'action_send'    => 'bizcity_chat_send',
            'action_history' => 'bizcity_chat_history',
            'action_clear'   => 'bizcity_chat_clear',
        ];

        // Expose as bizcity_chat_vars (new) + bizcity_admin_chat_vars (backward compat)
        wp_localize_script('jquery', 'bizcity_chat_vars', $data);
        wp_localize_script('jquery', 'bizcity_admin_chat_vars', $data);
    }

    /* ================================================================
     * AJAX: Set KCI Ratio for a session
     *
     * Admin-only. Updates the knowledge↔execution ratio for a session.
     * POST params: session_id, kci_ratio (0-100), _wpnonce
     * ================================================================ */
    public function ajax_set_kci_ratio() {
        if ( ! $this->verify_nonce() ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
            exit;
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
            exit;
        }

        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $kci_ratio  = intval( $_POST['kci_ratio'] ?? 80 );
        $kci_ratio  = max( 0, min( 100, $kci_ratio ) );

        if ( empty( $session_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
            exit;
        }

        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            wp_send_json_error( [ 'message' => 'Database not available' ] );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';
        $updated = $wpdb->update(
            $table,
            [ 'kci_ratio' => $kci_ratio ],
            [ 'session_id' => $session_id ],
            [ '%d' ],
            [ '%s' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => 'Failed to update kci_ratio' ] );
            exit;
        }

        wp_send_json_success( [
            'session_id' => $session_id,
            'kci_ratio'  => $kci_ratio,
        ] );
    }

    /* ================================================================
     * AJAX: Send message
     *
     * Accepts both WEBCHAT and ADMINCHAT platform types.
     * Response format is unified:
     *   { success: true, data: { reply, message, provider, model, usage, vision_used } }
     *
     * `reply` = short alias for `message` (backward compat with webchat JS)
     * ================================================================ */
    public function ajax_send() {
        // Determine platform type from request
        $platform_type = $this->detect_platform_type();

        // Permission check based on platform
        if ($platform_type === 'ADMINCHAT') {
            // Verify nonce (accept both old and new nonce names)
            if (!$this->verify_nonce()) {
                wp_send_json_error(['message' => 'Invalid nonce']);
                exit;
            }
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'Permission denied']);
                exit;
            }
        }
        // WEBCHAT: no nonce/auth required (public chatbot)

        // Parse input
        $message      = sanitize_textarea_field($_POST['message'] ?? '');
        $character_id = intval($_POST['character_id'] ?? 0);
        $session_id   = sanitize_text_field($_POST['session_id'] ?? '');

        // ── Concurrent request lock (per session + message) ──
        // Prevents duplicate processing when user clicks send rapidly.
        if ( $session_id && $message ) {
            $lock_key = 'bizc_send_lock_' . md5( $session_id . '|' . $message );
            if ( get_transient( $lock_key ) ) {
                wp_send_json_error( [ 'message' => 'Tin nhắn đang được xử lý, vui lòng đợi.' ] );
                exit;
            }
            set_transient( $lock_key, true, 15 );
        }

        $images       = [];
        
        // ═══ PLUGIN SLUG PARSING FOR @ MENTIONS ═══
        // Parse plugin_slug from manual routing (@ mention selection)
        $plugin_slug  = sanitize_text_field($_POST['plugin_slug'] ?? '');
        $routing_mode = sanitize_text_field($_POST['routing_mode'] ?? 'automatic');
        $provider_hint = sanitize_text_field($_POST['provider_hint'] ?? '');
        $tool_goal     = sanitize_text_field($_POST['tool_goal'] ?? '');
        $tool_name     = sanitize_text_field($_POST['tool_name'] ?? '');
        
        // Debug log for plugin routing
        if ($plugin_slug) {
            error_log("[ChatGateway] Plugin routing: slug={$plugin_slug}, mode={$routing_mode}, hint={$provider_hint}");
        }

        // Accept images in multiple formats
        if (!empty($_POST['images'])) {
            $raw_images = json_decode(stripslashes($_POST['images'] ?? '[]'), true) ?: [];
            // Convert base64 images to Media Library URLs
            if ( function_exists( 'bizcity_convert_images_to_media_urls' ) ) {
                $images = bizcity_convert_images_to_media_urls( $raw_images );
            } else {
                $images = $raw_images;
            }
        }
        if (!empty($_POST['image_data'])) {
            // Single base64 from old widget format - convert to Media
            $single_img = $_POST['image_data'];
            if ( function_exists( 'bizcity_save_base64_to_media' ) && strpos( $single_img, 'data:image/' ) === 0 ) {
                $media = bizcity_save_base64_to_media( $single_img );
                if ( ! is_wp_error( $media ) ) {
                    $images[] = $media['url'];
                }
            } else {
                $images[] = $single_img;
            }
        }

        $history_json = stripslashes($_POST['history'] ?? '[]');

        $post_char_id = $character_id; // preserve what JS sent
        if (!$character_id) {
            $character_id = $this->get_default_character_id();
        }
        error_log( sprintf(
            '[WEBCHAT-TRACE] character resolve: POST=%d | resolved=%d | option=%s | platform=%s',
            $post_char_id,
            $character_id,
            get_option( 'bizcity_webchat_default_character_id', '(unset)' ),
            $platform_type
        ) );

        if (!$message && empty($images)) {
            wp_send_json_error(['message' => 'Tin nhắn trống']);
            exit;
        }

        if (!$session_id) {
            $session_id = $this->get_session_id($platform_type);
        }

        $user_id     = get_current_user_id();
        $user        = wp_get_current_user();
        $client_name = $user->ID ? ($user->display_name ?: $user->user_login) : 'Guest';

        /* ── KCI Ratio: load per-session Knowledge↔Execution ratio ── */
        $kci_ratio = 80; // default
        if ( $session_id && class_exists( 'BizCity_WebChat_Database' ) ) {
            $session_obj = BizCity_WebChat_Database::instance()->get_session_v3_by_session_id( $session_id );
            if ( $session_obj && isset( $session_obj->kci_ratio ) ) {
                $kci_ratio = (int) $session_obj->kci_ratio;
            }
        }
        // WEBCHAT always locked at 100 (knowledge-only)
        if ( $platform_type === 'WEBCHAT' ) {
            $kci_ratio = 100;
        }
        error_log( "[KCI-TRACE] load: session={$session_id}, kci={$kci_ratio}, platform={$platform_type}" );
        
        // Store for later trace emission
        $this->current_kci_ratio = $kci_ratio;

        /* ── @/ Mention Override: allow execution even when KCI=100 ── */
        $mention_override = false;
        if ( $kci_ratio === 100 && $platform_type !== 'WEBCHAT' ) {
            if ( ! empty( $plugin_slug ) ) {
                $mention_override = true;
            }
            if ( preg_match( '/^\s*\/\w+/', $message ) ) {
                $mention_override = true;
            }
            if ( preg_match( '/@[\w-]+/', $message ) ) {
                $mention_override = true;
            }
            if ( $mention_override ) {
                $kci_ratio = 50; // Temporary balanced mode for this request
                error_log( "[KCI-TRACE] mention_override: kci 100→50 | plugin_slug={$plugin_slug} | msg=" . mb_substr( $message, 0, 60 ) );
            }
        }
        error_log( "[KCI-TRACE] mention_check: override=" . ( $mention_override ? 'true' : 'false' ) . ", effective_kci={$kci_ratio}" );

        // Make kci_ratio available to Mode Classifier
        if ( class_exists( 'BizCity_Mode_Classifier' ) ) {
            BizCity_Mode_Classifier::set_kci_ratio( $kci_ratio );
            if ( $mention_override ) {
                BizCity_Mode_Classifier::set_mention_override( true );
            }
        }
        $this->current_kci_ratio = $kci_ratio;

        /* ── Log user message (with plugin_slug if @ mentioned) ── */
        $this->log_message([
            'session_id'    => $session_id,
            'user_id'       => $user_id,
            'client_name'   => $client_name,
            'message_id'    => uniqid('chat_'),
            'message_text'  => $message ?: '[Image]',
            'message_from'  => 'user',
            'message_type'  => !empty($images) ? 'image' : 'text',
            'attachments'   => $images,
            'platform_type' => $platform_type,
            'plugin_slug'   => $plugin_slug, // @ mention plugin routing
        ]);

        /* ── Get AI response (single pipeline) ── */

        /* ── WEBCHAT frontend widget: knowledge-only mode ──
           Skip intent engine / plugin gathering / execution interceptors.
           Limit output tokens to 500 (customer support, not deep analysis). */
        if ( $platform_type === 'WEBCHAT' ) {
            $this->max_tokens_override = 500;
        }

        /* ── Pre-AI filter: allow plugins to intercept and return a custom reply ──
           Return an array ['message' => '...'] to short-circuit AI call.
           SKIPPED for WEBCHAT — frontend widget must not trigger execution. */
        $pre_reply = null;
        if ( $platform_type !== 'WEBCHAT' ) {
            $pre_reply = apply_filters('bizcity_chat_pre_ai_response', null, [
                'message'       => $message,
                'character_id'  => $character_id,
                'session_id'    => $session_id,
                'user_id'       => $user_id,
                'platform_type' => $platform_type,
                'images'        => $images,
                'plugin_slug'   => $plugin_slug,      // @ mention plugin routing
                'provider_hint' => $provider_hint,    // Intent engine bias hint
                'routing_mode'  => $routing_mode,     // manual / automatic
                'tool_goal'     => $tool_goal,         // Slash command / tool chip goal
                'tool_name'     => $tool_name,         // Tool function name
                'kci_ratio'     => $kci_ratio,         // KCI Ratio per-session
            ]);
        }
        if (is_array($pre_reply) && !empty($pre_reply['message'])) {
            $reply_payload = [
                'reply'       => $pre_reply['message'],
                'message'     => $pre_reply['message'],
                'plugin_slug' => $pre_reply['plugin_slug'] ?? $plugin_slug, // Prefer filter's slug (auto-continue)
            ];
            // Pass through intent engine metadata
            if ( ! empty( $pre_reply['conversation_id'] ) ) {
                $reply_payload['conversation_id'] = $pre_reply['conversation_id'];
            }
            if ( ! empty( $pre_reply['action'] ) ) {
                $reply_payload['action'] = $pre_reply['action'];
            }
            if ( ! empty( $pre_reply['goal'] ) ) {
                $reply_payload['goal'] = $pre_reply['goal'];
            }
            if ( ! empty( $pre_reply['goal_label'] ) ) {
                $reply_payload['goal_label'] = $pre_reply['goal_label'];
            }
            if ( ! empty( $pre_reply['focus_mode'] ) ) {
                $reply_payload['focus_mode'] = $pre_reply['focus_mode'];
            }

            // Fire action for automation triggers (same as normal path)
            do_action('bizcity_chat_message_processed', [
                'platform_type' => $platform_type,
                'session_id'    => $session_id,
                'character_id'  => $character_id,
                'user_id'       => $user_id,
                'user_message'  => $message,
                'bot_reply'     => $pre_reply['message'],
                'images'        => $images,
                'goal'          => $pre_reply['goal'] ?? '',
                'goal_label'    => $pre_reply['goal_label'] ?? '',
            ]);

            /* ── Log bot reply for pre_reply path (plugin gathering / intent engine) ── */
            $effective_slug = $pre_reply['plugin_slug'] ?? $plugin_slug;
            $bot_msg_id = uniqid('intent_bot_');
            $this->log_message([
                'session_id'    => $session_id,
                'user_id'       => 0,
                'client_name'   => 'AI Assistant',
                'message_id'    => $bot_msg_id,
                'message_text'  => $pre_reply['message'],
                'message_from'  => 'bot',
                'message_type'  => 'text',
                'platform_type' => $platform_type,
                'plugin_slug'   => $effective_slug,
                'meta'          => [
                    'character_id' => $character_id,
                    'via'          => $pre_reply['action'] ?? 'pre_ai_filter',
                    'goal'         => $pre_reply['goal'] ?? '',
                    'plugin_slug'  => $effective_slug,
                ],
            ]);
            $reply_payload['bot_message_id'] = $bot_msg_id;

            wp_send_json_success( $reply_payload );
            exit;
        }

        try {
            $reply_data = $this->get_ai_response($character_id, $message, $images, $session_id, $history_json, $user_id, $platform_type);
        } catch (Exception $e) {
            error_log('[ChatGateway] Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Có lỗi xảy ra: ' . $e->getMessage()]);
            exit;
        }

        /* ── Log bot reply (with plugin_slug if @ mentioned) ── */
        $this->log_message([
            'session_id'    => $session_id,
            'user_id'       => 0,
            'client_name'   => $reply_data['character_name'] ?? 'AI Assistant',
            'message_id'    => uniqid('chat_bot_'),
            'message_text'  => $reply_data['message'],
            'message_from'  => 'bot',
            'message_type'  => 'text',
            'platform_type' => $platform_type,
            'plugin_slug'   => $plugin_slug, // @ mention plugin routing (inherited from user request)
            'meta'          => [
                'provider'     => $reply_data['provider'] ?? '',
                'model'        => $reply_data['model'] ?? '',
                'usage'        => $reply_data['usage'] ?? [],
                'vision_used'  => $reply_data['vision_used'] ?? false,
                'character_id' => $character_id,
                'plugin_slug'  => $plugin_slug, // Also store in meta for debugging
                'routing_mode' => $routing_mode,
            ],
        ]);

        /* ── Fire action for automation triggers ── */
        do_action('bizcity_chat_message_processed', [
            'platform_type' => $platform_type,
            'session_id'    => $session_id,
            'character_id'  => $character_id,
            'user_id'       => $user_id,
            'user_message'  => $message,
            'bot_reply'     => $reply_data['message'],
            'images'        => $images,
            'provider'      => $reply_data['provider'] ?? '',
            'model'         => $reply_data['model'] ?? '',
            'plugin_slug'   => $plugin_slug, // For automation logic
        ]);

        wp_send_json_success([
            // `reply` for backward compat with webchat JS (response.data.reply)
            'reply'       => $reply_data['message'],
            // `message` for admin chat JS (response.data.message)
            'message'     => $reply_data['message'],
            'provider'    => $reply_data['provider'] ?? '',
            'model'       => $reply_data['model'] ?? '',
            'usage'       => $reply_data['usage'] ?? [],
            'vision_used' => $reply_data['vision_used'] ?? false,
            'plugin_slug' => $plugin_slug, // Echo back for frontend badge
            'focus_mode'  => 'none',       // Normal AI path — no HIL focus
        ]);
        exit;
    }

    /* ================================================================
     * AJAX: Get history
     * ================================================================ */
    public function ajax_history() {
        $platform_type = $this->detect_platform_type();

        if ($platform_type === 'ADMINCHAT') {
            if (!$this->verify_nonce()) {
                wp_send_json_error(['message' => 'Invalid nonce']);
            }
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'Permission denied']);
            }
        }

        $session_id   = sanitize_text_field($_POST['session_id'] ?? '');
        $character_id = intval($_POST['character_id'] ?? 0);
        $limit        = intval($_POST['limit'] ?? 50);

        if (!$session_id) {
            $session_id = $this->get_session_id($platform_type);
        }

        $history = $this->get_history($session_id, $platform_type, $limit);

        wp_send_json_success($history);
    }

    /* ================================================================
     * AJAX: Clear history
     * ================================================================ */
    public function ajax_clear() {
        $platform_type = $this->detect_platform_type();

        if ($platform_type === 'ADMINCHAT') {
            if (!$this->verify_nonce()) {
                wp_send_json_error(['message' => 'Invalid nonce']);
            }
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'Permission denied']);
            }
        }

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        if (!$session_id) {
            $session_id = $this->get_session_id($platform_type);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $wpdb->delete($table, [
                'session_id'    => $session_id,
                'platform_type' => $platform_type,
            ]);
        }

        $conv_table = $wpdb->prefix . 'bizcity_webchat_conversations';
        if ($wpdb->get_var("SHOW TABLES LIKE '$conv_table'") === $conv_table) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$conv_table} SET status = 'closed', ended_at = NOW() WHERE session_id = %s AND platform_type = %s",
                $session_id, $platform_type
            ));
        }

        wp_send_json_success(['cleared' => true]);
    }

    /* ================================================================
     * build_system_prompt() — SINGLE SOURCE OF TRUTH
     *
     * Unified prompt assembly pipeline called by:
     *   - prepare_llm_call()                      (non-streaming fallback)
     *   - BizCity_Intent_Stream::build_llm_messages() (SSE streaming)
     *
     * Pipeline order:
     *   0. Character system_prompt (base persona)
     *   1. 🧠 User Memory (xưng hô, tên gọi — ưu tiên số 1)
     *   2. 👤 Profile Context (Hồ Sơ Chủ Nhân)
     *   3. ⭐ Transit Context (vị trí sao hiện tại)
     *   4. 📚 Knowledge Context (embeddings + keyword search)
     *   4c.🏷️ Intent Tag Routing (cross-character knowledge by tag match)
     *   5. 🧵 Conversation Context (rolling summary, goal, slots)
     *   6. 📏 Response Rules (astro grounding, tarot fusion)
     *   7. 🧑‍💼 Role Block (Team Leader identity)
     *   8. 🔌 Filters (mode pipelines, context builder)
     *   9. ⚠️ End Reminder (blacklist + fallback template)
     *
     * @param array $args
     * @return array {
     *   system_content, character, profile_context, transit_context,
     *   knowledge_context, memory_context, effective_platform
     * }
     *
     * ───────────────────────────────────────────────────────────────────────
     * 7-LAYER DUAL CONTEXT CHAIN IMPLEMENTATION:
     *
     * Base Build (this method, with timing metrics):
     *   Step 0: Character Persona (base)
     *   Step 1: User Memory (Layer 1) — also marks already_injected for pri 99
     *   Steps 2-3: BizCoach Profile/Transit (Layer 1.5) — timing needed
     *   Step 4: Knowledge RAG (Layer 6)
     *
     * Filter Chain (applied after via bizcity_chat_system_prompt):
     *   pri 90: Context Builder — Layers 2,3,4,5 (Intent/Session/Cross-Session/Project)
     *   pri 97: Companion Context — Layer 1.7 (Relationship/Emotion)
     *   pri 99: User Memory FALLBACK — Layer 1 (skipped if already_injected)
     *
     * NOTE: Step 5 (Conversation Context) đã loại bỏ để tránh duplicate với
     * Layer 2 trong Context Builder. Intent conversation (goal, slots, summary)
     * giờ chỉ inject qua BizCity_Context_Builder::inject_context_layers() pri 90.
     * ───────────────────────────────────────────────────────────────────────
     * ================================================================ */
    public function build_system_prompt( $args = [] ) {
        $args = wp_parse_args( $args, [
            'message'        => '',
            'character_id'   => 0,
            'session_id'     => '',
            'user_id'        => 0,
            'platform_type'  => '',
            'images'         => [],
            'via'            => 'chat_gateway',
            'engine_result'  => [],
        ] );

        $message        = $args['message'];
        $character_id   = (int) $args['character_id'];
        $session_id     = $args['session_id'];
        $user_id        = (int) $args['user_id'];
        $platform_type  = $args['platform_type'];
        $images         = $args['images'];
        $via            = $args['via'];
        $engine_result  = $args['engine_result'];
        $build_start    = microtime( true );

        // ── TWIN CONTEXT RESOLVER: single-call delegation ──
        $effective_platform = $platform_type ?: $this->detect_platform_type();
        if ( defined( 'BIZCITY_TWIN_RESOLVER_ENABLED' ) && BIZCITY_TWIN_RESOLVER_ENABLED
             && class_exists( 'BizCity_Twin_Context_Resolver' ) ) {
            return BizCity_Twin_Context_Resolver::build_prompt_bundle( 'chat', [
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'message'       => $message,
                'character_id'  => $character_id,
                'platform_type' => $effective_platform,
                'images'        => $images,
                'via'           => $via,
                'engine_result' => $engine_result,
            ] );
        }

        // ── LEGACY FALLBACK — context definitions consolidated in Twin Context Resolver ──
        $character = $character_id && class_exists( 'BizCity_Knowledge_Database' )
            ? BizCity_Knowledge_Database::instance()->get_character( $character_id ) : null;
        $base = ( $character && ! empty( $character->system_prompt ) )
            ? $character->system_prompt
            : "Bạn là Trợ lý Team Leader AI cá nhân của BizCity. Trả lời bằng tiếng Việt.";
        $base = apply_filters( 'bizcity_chat_system_prompt', $base, [
            'character_id'  => $character_id, 'message' => $message,
            'user_id'       => $user_id, 'session_id' => $session_id,
            'platform_type' => $effective_platform, 'via' => $via,
        ] );
        return [
            'system_content'     => $base,
            'character'          => $character,
            'profile_context'    => '',
            'transit_context'    => '',
            'knowledge_context'  => '',
            'memory_context'     => '',
            'effective_platform' => $effective_platform,
        ];

        // @codeCoverageIgnoreStart — Legacy inline context building (unreachable)
        // All definitions (response rules, role block, end reminder, tool manifest,
        // tool registry) now in BizCity_Twin_Context_Resolver::build_system_prompt().
        $timing         = [];  // Per-step timing breakdown

        $system_content = '';

        // ── TWIN CORE: Ensure focus profile resolved BEFORE inline gate checks ──
        $effective_platform = $platform_type ?: $this->detect_platform_type();
        if ( class_exists( 'BizCity_Focus_Gate' ) ) {
            BizCity_Focus_Gate::ensure_resolved( $message, [
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'platform_type' => $effective_platform,
                'images'        => $images,
                'via'           => $via,
                'mode'          => $engine_result['meta']['mode'] ?? '',
                'active_goal'   => $engine_result['goal'] ?? '',
            ] );
            // Amend for goal if profile was already resolved without goal info
            BizCity_Focus_Gate::amend_for_goal( $engine_result['goal'] ?? '' );
        }

        // ── Twin Trace: build_system_prompt start ──
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::log( 'prompt_start', [
                'via'      => $via,
                'platform' => $effective_platform,
                'user_id'  => $user_id,
            ] );
        }

        // ── 0. CHARACTER BASE PERSONA ──
        $character = null;
        if ( $character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $character = BizCity_Knowledge_Database::instance()->get_character( $character_id );
        }
        if ( $character && ! empty( $character->system_prompt ) ) {
            $system_content = $character->system_prompt;
        }

        // ── 1. 🧠 USER MEMORY — ưu tiên số 1 ──
        $t0 = microtime( true );
        $memory_context = '';
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $mem   = BizCity_User_Memory::instance();
            $q_uid = $user_id > 0 ? $user_id : 0;
            $q_sid = $user_id > 0 ? ''       : $session_id;
            $memory_context = $mem->build_memory_context( $q_uid, $q_sid, $session_id );
        }
        $timing['1:Memory'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );
        if ( ! empty( $memory_context ) ) {
            $system_content .= $memory_context;
        }

        // ── 2. 👤 PROFILE CONTEXT  +  3. ⭐ TRANSIT CONTEXT ──
        $profile_context = '';
        $transit_context = '';
        if ( class_exists( 'BizCity_Profile_Context' ) ) {
            $uid_profile      = $user_id ? $user_id : get_current_user_id();
            $profile_ctx_inst = BizCity_Profile_Context::instance();

            $t0 = microtime( true );
            $profile_context = $profile_ctx_inst->build_user_context(
                $uid_profile, $session_id, $effective_platform, [ 'coach_type' => '' ]
            );
            $timing['2:Profile'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );
            back_trace( 'INFO', "Profile context built for user_id={$uid_profile}: " . substr( $profile_context, 0, 200 ) . '...' );

            $t0 = microtime( true );
            // ── Twin Focus Gate: skip transit when mode doesn't need it ──
            $twin_build_transit = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'transit' );
            if ( $twin_build_transit ) {
                $transit_context = $profile_ctx_inst->build_transit_context(
                    $message, $uid_profile, $session_id, $effective_platform
                );
                if ( empty( $transit_context ) && ! empty( $images ) ) {
                    $transit_context = $profile_ctx_inst->build_transit_context(
                        'chiêm tinh tháng này', $uid_profile, $session_id, $effective_platform
                    );
                }
            }
            $timing['3:Transit'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );

            // ── Twin Trace: transit layer ──
            if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                BizCity_Twin_Trace::layer( 'transit', $twin_build_transit && ! empty( $transit_context ), $timing['3:Transit'] );
            }
            back_trace( 'INFO', "Transit context built for user_id={$uid_profile}: " . substr( $transit_context, 0, 200 ) . '...' );
        }
        if ( ! empty( $profile_context ) ) {
            $system_content .= "\n\n---\n\n" . $profile_context;
        }
        if ( ! empty( $transit_context ) ) {
            $system_content .= "\n\n---\n\n" . $transit_context;
        }

        // ── 4. 📚 KNOWLEDGE CONTEXT ──
        $t0 = microtime( true );
        $knowledge_context = '';
        if ( class_exists( 'BizCity_Knowledge_Context_API' ) ) {
            $ctx = BizCity_Knowledge_Context_API::instance()->build_context( $character_id, $message, [
                'max_tokens'     => 3000,
                'include_vision' => ! empty( $images ),
                'images'         => $images,
            ] );
            $knowledge_context = $ctx['context'] ?? '';
        }
        $timing['4a:ContextAPI'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );

        $t0 = microtime( true );
        if ( $character_id && function_exists( 'bizcity_knowledge_search_character' ) ) {
            $kw_ctx = bizcity_knowledge_search_character( $message, $character_id );
            if ( ! empty( $kw_ctx ) ) {
                if ( ! empty( $knowledge_context ) ) {
                    if ( strpos( $knowledge_context, $kw_ctx ) === false ) {
                        $knowledge_context .= "\n\n---\n\n### Kiến thức bổ sung (keyword search):\n" . $kw_ctx;
                    }
                } else {
                    $knowledge_context = $kw_ctx;
                }
            }
        }
        $timing['4b:KeywordSearch'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );
        if ( ! empty( $knowledge_context ) ) {
            $system_content .= "\n\n---\n\n## Kiến thức tham khảo:\n" . $knowledge_context;
        }

        // ── 5. 🧵 CONVERSATION CONTEXT ──
        // NOTE: Đã loại bỏ injection trực tiếp ở đây.
        // Intent conversation context (goal, slots, rolling_summary) giờ được inject
        // qua BizCity_Context_Builder::inject_context_layers() tại filter pri 90.
        // Điều này tránh duplicate và đảm bảo Layer 2 trong 7-Layer Dual Context Chain
        // là nguồn duy nhất cho conversation context.
        // Session context (emotion, mạch hội thoại) vẫn được duy trì qua session_id.

        // ── 6. 📏 RESPONSE RULES ──
        // ── Twin Focus Gate: only inject astro response rules when mode needs astro ──
        $twin_astro_rules = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'astro' );

        // ── Twin Trace: astro rules layer ──
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::layer( 'astro_rules', $twin_astro_rules );
        }
        $system_content .= "\n\n---\n\n## QUY TẮC TRẢ LỜI (BẮT BUỘC — ƯU TIÊN CAO NHẤT):\n";

        if ( ! empty( $profile_context ) ) {
            $system_content .= "### 📌 Nhận diện người dùng:\n";
            $system_content .= "1. Bạn ĐÃ BIẾT người đang trò chuyện thông qua Hồ Sơ Chủ Nhân ở trên. ";
            $system_content .= "Khi họ hỏi \"tôi là ai\", \"bạn biết tôi không\", hãy trả lời TỰ TIN dựa trên hồ sơ.\n";
            $system_content .= "2. Luôn gọi người dùng bằng TÊN khi có thể.\n\n";

            if ( $twin_astro_rules ) {
                $system_content .= "### 🔒 NỀN TẢNG TRẢ LỜI — LUÔN BÁM THEO DỮ LIỆU:\n";
                $system_content .= "🔴 **QUY TẮC CỐT LÕI**: Mọi câu trả lời về cuộc sống, tương lai, tính cách, sự nghiệp, tài chính, tình cảm, hôn nhân, sức khỏe ĐỀU PHẢI dựa trên:\n";
                $system_content .= "   a) **Bản đồ chiêm tinh natal** — đã có trong Hồ Sơ Chủ Nhân\n";
                $system_content .= "   b) **Kết quả luận giải (gen_results)** — SWOT, thần số học, ngũ hành\n";
                $system_content .= "   c) **Câu trả lời coaching (answer_json)** — thông tin user tự khai\n";
                if ( ! empty( $transit_context ) ) {
                    $system_content .= "   d) **Dữ liệu Transit chiêm tinh** — vị trí THỰC TẾ các sao\n";
                }
                $system_content .= "\n🚫 **CẤM**: KHÔNG bịa đặt vị trí sao, góc chiếu. KHÔNG trả lời chung chung thiếu dữ liệu.\n\n";

                $system_content .= "✅ **YÊU CẦU BẮT BUỘC khi trả lời về tương lai/dự báo**:\n";
                $system_content .= "   - Luôn nhắc TÊN SAO + CUNG + GÓC CHIẾU\n";
                $system_content .= "   - Liên hệ trực tiếp với natal chart và gen_results\n";
                $system_content .= "   - Tham chiếu answer_json khi liên quan\n";
                if ( ! empty( $transit_context ) ) {
                    $system_content .= "   - Sử dụng DỮ LIỆU TRANSIT THỰC TẾ đã cung cấp\n";
                }
                $system_content .= "\n";
            }
        }

        if ( ! empty( $transit_context ) && $twin_astro_rules ) {
            $system_content .= "### ⭐ ĐẶC BIỆT — DỮ LIỆU TRANSIT:\n";
            $system_content .= "Dữ liệu transit THỰC TẾ đã cung cấp. Bạn PHẢI:\n";
            $system_content .= "- Phân tích dựa HOÀN TOÀN trên transit thực tế + natal chart\n";
            $system_content .= "- Giải thích: sao transit nào, cung nào, góc chiếu gì\n";
            $system_content .= "- Liên hệ gen_results và answer_json để cá nhân hóa\n\n";
        }

        if ( ! empty( $images ) && ! empty( $profile_context ) && $twin_astro_rules ) {
            $system_content .= "### 🃏 KHI USER GỬI ẢNH LÁ BÀI / HÌNH ẢNH:\n";
            $system_content .= "PHẢI trả lời: 1) Nhận diện ảnh → 2) Ý nghĩa phổ quát → 3) Chiếu lên natal chart → ";
            $system_content .= ! empty( $transit_context )
                ? "4) Transit hiện tại → 5) Lời khuyên cá nhân hóa.\n"
                : "4) Lời khuyên cá nhân hóa.\n";
            $system_content .= "⛔ NGHIÊM CẤM trả lời chung chung không nhắc natal chart.\n\n";
        }

        if ( ! empty( $knowledge_context ) ) {
            $system_content .= "### 📚 Kiến thức: Ưu tiên kiến thức tham khảo. Nếu không có, dùng hiểu biết chung.\n";
        }

        // Response depth
        $astro_tarot_intent = ! empty( $transit_context )
            || ! empty( $images )
            || (bool) preg_match(
                '/chiêm tinh|natal|transit|tarot|lá bài|bói|tử vi|phong thủy|'
                . 'hôm nay thế nào|ngày mai|tuần tới|tháng này|tháng sau|năm tới|'
                . 'dự báo|xu hướng|tính cách|mệnh|nghiệp|'
                . 'tình duyên|sự nghiệp|tài chính|sức khỏe|hôn nhân|tương lai/ui',
                $message
            );

        if ( $astro_tarot_intent ) {
            $system_content .= "### 📏 ĐỘ DÀI TRẢ LỜI (BẮT BUỘC):\n";
            $system_content .= "Chủ đề chiêm tinh/tarot/dự báo → ĐẦY ĐỦ, CỤ THỂ (200–400 từ):\n";
            $system_content .= "1. Phân tích có đánh số. 2. TÊN SAO + CUNG + GÓC CHIẾU. 3. 2–3 lời khuyên. 4. Giọng thân mật.\n";
            $system_content .= "🚫 KHÔNG vắn tắt 1–2 câu.\n\n";
        } else {
            $system_content .= "### 🗨️ Phong cách: Rõ ràng, đầy đủ. Đơn giản → ngắn; phân tích → chiết lọc.\n\n";
        }

        $system_content .= "### 🗣️ Ngôn ngữ: Trả lời bằng tiếng Việt, thân thiện, tự nhiên, giàu cảm xúc.\n";

        // ── 7. 🧑‍💼 ROLE BLOCK ──
        // ── Role block — CHỈ mô tả vai trò, KHÔNG nhắc Chợ (đã có ở END REMINDER) ──
        $role_block  = "\n\n## 🧑‍💼 VAI TRÒ CỦA BẠN:\n";
        $role_block .= "Bạn là **Trợ lý Team Leader cá nhân** của Chủ Nhân (người đang trò chuyện).\n";
        $role_block .= "- Điều phối, tư vấn và hỗ trợ Chủ Nhân quản lý công việc, cuộc sống.\n";
        $role_block .= "- Hệ thống BizCity có NHIỀU AI Agent chuyên biệt khác có thể giúp thực thi công việc.\n";
        $role_block .= "\n### ⛔ RANH GIỚI VAI TRÒ BẮT BUỘC:\n";
        $role_block .= "- Bạn là AI Trợ lý. Chủ Nhân là NGƯỜI DÙNG đang nhắn tin cho bạn.\n";
        $role_block .= "- KHÔNG BAO GIỜ tự xưng bằng tên Chủ Nhân (VD: không nói \"Chu đây!\", không nói \"Anh Chu đẹp trai đây\").\n";
        $role_block .= "- KHÔNG nhập vai thành Chủ Nhân. KHÔNG nói như thể BẠN là người dùng.\n";
        $role_block .= "- Khi xưng hô 'mày tao': Chủ Nhân xưng 'tao', gọi AI là 'mày'. AI KHÔNG xưng 'tao' — AI xưng phù hợp với vai trợ lý.\n";
        $system_content .= $role_block;

        // ── 7.5. 🔧 TOOL MANIFEST — Self-awareness (passive only) ──
        // Tool registry is injected so AI can answer "bạn có công cụ gì?" when asked.
        // AI must NEVER proactively suggest tools — Twin Suggest handles follow-up suggestions.
        $tool_manifest_prompt = '';
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $tool_manifest_prompt = BizCity_Intent_Tool_Index::instance()->build_tools_context( 1500 );
        }
        if ( ! empty( $tool_manifest_prompt ) ) {
            $system_content .= "\n\n" . $tool_manifest_prompt;
            $system_content .= "\n\n**LƯU Ý CÔNG CỤ**: Chỉ liệt kê công cụ khi Chủ Nhân HỎI TRỰC TIẾP 'bạn có công cụ gì' hoặc tương tự. KHÔNG tự gợi ý công cụ trong câu trả lời bình thường.";
            $system_content .= "\nKhi được hỏi trực tiếp: nêu TÊN + MÔ TẢ ngắn gọn, đừng nói chung chung.";
        }

        // ── 7.6. 💡 TWIN SUGGEST — Follow-up question suggestions ──
        // Replaces old proactive tool suggestion with conversation-aware follow-up questions.
        $suggest_prompt = '';
        if ( class_exists( 'BizCity_Twin_Suggest' ) ) {
            $current_mode = $engine_result['meta']['mode'] ?? '';
            $suggest_prompt = BizCity_Twin_Suggest::build( [
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'message'       => $message,
                'mode'          => $current_mode,
                'engine_result' => $engine_result,
            ] );
            if ( ! empty( $suggest_prompt ) ) {
                $system_content .= $suggest_prompt;
            }
        }

        if ( empty( trim( $system_content ) ) ) {
            $system_content = "Bạn là Trợ lý Team Leader AI cá nhân của BizCity. Trả lời đầy đủ, chi tiết bằng tiếng Việt.";
        }

        // ── 8. 🔌 FILTERS ──
        $has_conversation = ! empty( $engine_result['conversation_id'] );
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'context_build',
                'message'          => mb_substr( $message, 0, 120, 'UTF-8' ),
                'mode'             => 'build_system_prompt',
                'functions_called' => 'build_system_prompt()',
                'pipeline'         => [
                    '0:Character'    . ( $character                     ? ' ✓' : ' —' ),
                    '1:Memory'       . ( ! empty( $memory_context )     ? ' ✓' : ' —' ),
                    '2:Profile'      . ( ! empty( $profile_context )    ? ' ✓' : ' —' ),
                    '3:Transit'      . ( ! empty( $transit_context )    ? ' ✓' : ' —' ),
                    '4:Knowledge'    . ( ! empty( $knowledge_context )  ? ' ✓' : ' —' ),
                    '4c:IntentTag'   . ( ! empty( $ctx['metadata']['has_intent_tag'] ?? false ) ? ' ✓' : ' —' ),
                    '5:Conversation' . ( $has_conversation              ? ' ✓' : ' —' ),
                    '6:Rules ✓',
                    '7:Role ✓',
                    '7.5:Tools'      . ( ! empty( $tool_manifest_prompt ) ? ' ✓' : ' —' ),
                    '7.6:Suggest'    . ( ! empty( $suggest_prompt )       ? ' ✓' : ' —' ),
                    '→ 8:Filters',
                    '→ 9:EndReminder',
                ],
                'file_line'        => 'class-chat-gateway.php::build_system_prompt',
                'via'              => $via,
                'context_length'   => mb_strlen( $system_content, 'UTF-8' ),
                'has_memory'       => ! empty( $memory_context ),
                'has_profile'      => ! empty( $profile_context ),
                'has_transit'      => ! empty( $transit_context ),
                'has_knowledge'    => ! empty( $knowledge_context ),
                'has_conversation' => $has_conversation,
                'build_ms'         => round( ( microtime( true ) - $build_start ) * 1000, 2 ),
                'timing_breakdown' => $timing,
                'slowest_step'     => ! empty( $timing ) ? array_search( max( $timing ), $timing ) . ' (' . max( $timing ) . 'ms)' : '',
            ], $session_id );
        }

        $system_content = apply_filters( 'bizcity_chat_system_prompt', $system_content, [
            'character_id'  => $character_id,
            'message'       => $message,
            'user_id'       => $user_id,
            'session_id'    => $session_id,
            'platform_type' => $effective_platform,
            'via'           => $via,
        ] );

        // final_prompt log
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $prompt_len   = mb_strlen( $system_content, 'UTF-8' );
            $preview_head = mb_substr( $system_content, 0, 500, 'UTF-8' );
            $preview_tail = $prompt_len > 1000 ? mb_substr( $system_content, -500, 500, 'UTF-8' ) : '';
            BizCity_User_Memory::log_router_event( [
                'step'             => 'final_prompt',
                'message'          => "System prompt built (via {$via})",
                'mode'             => 'debug',
                'functions_called' => 'build_system_prompt() + apply_filters()',
                'file_line'        => 'class-chat-gateway.php::build_system_prompt',
                'via'              => $via,
                'prompt_length'    => $prompt_len,
                'word_count'       => str_word_count( strip_tags( $system_content ) ),
                'has_memory'       => ( strpos( $system_content, 'KÝ ỨC USER' ) !== false ),
                'has_bizcoach'     => ( strpos( $system_content, 'BIZCOACH CONTEXT' ) !== false ),
                'prompt_head'      => $preview_head,
                'prompt_tail'      => $preview_tail,
                'full_prompt'      => $system_content,
            ], $session_id );
        }

        // ── Twin Trace: prompt summary → SSE → browser console ──
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            $total_ms = round( ( microtime( true ) - $build_start ) * 1000, 2 );
            BizCity_Twin_Trace::prompt_summary( [
                'character' => ! empty( $character ) ? 1 : 0,
                'memory'    => ! empty( $memory_context ) ? mb_strlen( $memory_context, 'UTF-8' ) : 0,
                'profile'   => ! empty( $profile_context ) ? mb_strlen( $profile_context, 'UTF-8' ) : 0,
                'transit'   => ! empty( $transit_context ) ? mb_strlen( $transit_context, 'UTF-8' ) : 0,
                'knowledge' => ! empty( $knowledge_context ) ? mb_strlen( $knowledge_context, 'UTF-8' ) : 0,
                'tools'     => ! empty( $tool_manifest_prompt ) ? mb_strlen( $tool_manifest_prompt, 'UTF-8' ) : 0,
                'suggest'   => ! empty( $suggest_prompt ) ? mb_strlen( $suggest_prompt, 'UTF-8' ) : 0,
            ], mb_strlen( $system_content, 'UTF-8' ), $total_ms );
        }

        // ── 9. ⚠️ END REMINDER ──
        $end_reminder  = "\n\n# ⚠️ NHẮC NHỞ QUAN TRỌNG (BẮT BUỘC ĐỌC TRƯỚC KHI TRẢ LỜI):\n";
        $end_reminder .= "\n## 🚫 DANH SÁCH CÂU BỊ CẤM:\n";
        $end_reminder .= "- 'tôi không có quyền truy cập thông tin cá nhân'\n";
        $end_reminder .= "- 'tôi không có quyền truy cập vào thông tin cá nhân hoặc hồ sơ'\n";
        $end_reminder .= "- 'hãy liên hệ bộ phận hỗ trợ'\n";
        $end_reminder .= "- 'tôi không biết thông tin về bạn'\n";
        $end_reminder .= "- 'tôi không có khả năng truy cập'\n";
        $end_reminder .= "- 'tôi là AI nên không thể truy cập'\n";
        $end_reminder .= "- 'liên hệ email/hotline/admin để được hỗ trợ'\n";
        $end_reminder .= "Nếu sắp nói giống các câu trên → DỪNG LẠI và dùng mẫu fallback.\n";
        if ( ! empty( $profile_context ) ) {
            $end_reminder .= "\n## ✅ BẠN ĐÃ CÓ HỒ SƠ CHỦ NHÂN:\n";
            $end_reminder .= "- HÃY sử dụng hồ sơ để cá nhân hóa câu trả lời.\n";
            $end_reminder .= "- HÃY gọi người dùng bằng TÊN.\n";
        }

        // ── v4.3: Tool Registry Verification — check if the user's request
        // matches an EXISTING tool before using "chưa có trợ lý" template.
        $gw_matching_tool = null;
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $gw_msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
            $gw_msg_words = array_filter(
                preg_split( '/[\s,;.!?]+/u', $gw_msg_lower ),
                function( $w ) { return mb_strlen( $w, 'UTF-8' ) >= 2; }
            );
            if ( ! empty( $gw_msg_words ) ) {
                $gw_tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
                foreach ( $gw_tools as $gw_row ) {
                    $gw_fields = mb_strtolower(
                        ( $gw_row['goal'] ?? '' ) . ' ' . ( $gw_row['title'] ?? '' ) . ' '
                        . ( $gw_row['goal_label'] ?? '' ) . ' ' . ( $gw_row['custom_hints'] ?? '' ) . ' '
                        . ( $gw_row['goal_description'] ?? '' ) . ' ' . ( $gw_row['plugin'] ?? '' ),
                        'UTF-8'
                    );
                    foreach ( $gw_msg_words as $gw_kw ) {
                        if ( mb_strpos( $gw_fields, $gw_kw ) !== false && mb_strlen( $gw_kw, 'UTF-8' ) >= 3 ) {
                            $gw_matching_tool = $gw_row;
                            break 2;
                        }
                    }
                }
            }
        }

        // ── v4.3: Log tool_registry_verify pipe step (build_system_prompt path) ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $gw_verify_outcome = $gw_matching_tool ? 'TOOL_EXISTS' : 'no_match';
            $gw_verify_detail  = '';
            if ( $gw_matching_tool ) {
                $gw_verify_detail = ( $gw_matching_tool['goal_label'] ?: $gw_matching_tool['title'] ?: $gw_matching_tool['tool_name'] )
                    . ' (plugin: ' . ( $gw_matching_tool['plugin'] ?? '' ) . ')';
            } else {
                $gw_verify_detail = 'No tool found → using fallback template';
            }
            BizCity_User_Memory::log_router_event( [
                'step'             => 'tool_registry_verify',
                'message'          => mb_substr( $message, 0, 120, 'UTF-8' ),
                'mode'             => 'gateway → end_reminder',
                'method'           => 'keyword_scan',
                'functions_called' => 'BizCity_Intent_Tool_Index::get_all_active() → keyword match',
                'pipeline'         => [ 'extract_keywords', 'scan_tool_registry', 'outcome:' . $gw_verify_outcome ],
                'response_preview' => $gw_verify_outcome . ' → ' . $gw_verify_detail,
                'outcome'          => $gw_verify_outcome,
                'matched_tool'     => $gw_matching_tool ? [
                    'goal'       => $gw_matching_tool['goal'] ?? '',
                    'goal_label' => $gw_matching_tool['goal_label'] ?? '',
                    'plugin'     => $gw_matching_tool['plugin'] ?? '',
                    'title'      => $gw_matching_tool['title'] ?? '',
                ] : null,
                'file_line'        => 'class-chat-gateway.php::build_system_prompt_tool_verify',
            ], $session_id );
        }

        if ( $gw_matching_tool ) {
            $end_reminder .= "\n## 📋 HƯỚNG DẪN:\n";
            $end_reminder .= "→ TUYỆT ĐỐI KHÔNG nói 'mình chưa có trợ lý chuyên về...'.\n";
            $end_reminder .= "→ HÃY TRẢ LỜI câu hỏi dựa trên hiểu biết của bạn.\n";
            $end_reminder .= "→ KHÔNG gợi ý công cụ, KHÔNG gợi ý Chợ AI Agent, KHÔNG hỏi 'Bạn có muốn dùng công cụ X không?'.\n";
            $end_reminder .= "→ Cuối câu trả lời, hãy đặt 1-2 câu hỏi gợi mở để Chủ Nhân đào sâu thêm vào vấn đề đang thảo luận.\n";
        } else {
            $end_reminder .= "\n## 📋 MẪU FALLBACK — khi chức năng CHƯA CÓ:\n";
            $end_reminder .= "Ví dụ: nghe nhạc, xem phim, đặt hàng, chuyển khoản, tra thời tiết, giá cổ phiếu, tìm đường...\n";
            $end_reminder .= "→ 'Hiện tại mình chưa có trợ lý chuyên về [chức năng]. Bạn có thể vào **Chợ AI Agent** để chọn trợ lý phù hợp — mình sẽ phối hợp giúp bạn! 🚀'\n";
            $end_reminder .= "→ KHÔNG nói 'không có quyền', KHÔNG nói 'liên hệ hỗ trợ'.\n";
            $end_reminder .= "→ Tinh thần: 'Mình là Team Leader của bạn — việc gì cũng có cách giải quyết!'\n";
        }
        $system_content .= $end_reminder;

        return [
            'system_content'     => $system_content,
            'character'          => $character,
            'profile_context'    => $profile_context,
            'transit_context'    => $transit_context,
            'knowledge_context'  => $knowledge_context,
            'memory_context'     => $memory_context,
            'effective_platform' => $effective_platform,
        ];
    }

    /* ================================================================
     * Core: Prepare LLM call
     *
     * Delegates to build_system_prompt() for context assembly,
     * then adds conversation history + current message.
     *
     * On early error: returns ['error' => $result_with_message]
     * ================================================================ */
    public function prepare_llm_call($character_id, $message, $images = [], $session_id = '', $history_json = '[]', $wp_user_id = 0, $platform_type_hint = '') {
        $result = [
            'message'        => '',
            'character_name' => 'AI Assistant',
            'provider'       => '',
            'model'          => '',
            'usage'          => [],
            'vision_used'    => false,
        ];

        // Get character
        if (!class_exists('BizCity_Knowledge_Database')) {
            $result['message'] = 'Hệ thống Knowledge chưa sẵn sàng.';
            return ['error' => $result];
        }

        $db = BizCity_Knowledge_Database::instance();
        $character = $character_id ? $db->get_character($character_id) : null;

        if ($character) {
            $result['character_name'] = $character->name;
        }

        // ── Step 0: Profile Context (user identity — highest priority) ──
        $context_start = microtime( true );
        $timing = [];  // Per-step timing breakdown
        $profile_context = '';
        $transit_context = '';
        $effective_platform = $platform_type_hint ?: $this->detect_platform_type();

        // ── TWIN CONTEXT RESOLVER: full system prompt delegation ──
        $__resolver_defined = defined( 'BIZCITY_TWIN_RESOLVER_ENABLED' );
        $__resolver_flag    = $__resolver_defined ? BIZCITY_TWIN_RESOLVER_ENABLED : false;
        $__resolver_class   = class_exists( 'BizCity_Twin_Context_Resolver' );
        error_log( sprintf(
            '[WEBCHAT-TRACE] prepare_llm_call: platform=%s | RESOLVER_ENABLED=%s | class_exists=%s | session=%s',
            $effective_platform,
            $__resolver_defined ? ( $__resolver_flag ? 'true' : 'false' ) : 'UNDEFINED',
            $__resolver_class ? 'yes' : 'no',
            $session_id
        ) );
        if ( $__resolver_defined && $__resolver_flag && $__resolver_class ) {

            $system_content = BizCity_Twin_Context_Resolver::build_system_prompt( 'chat', [
                'user_id'       => $wp_user_id ?: get_current_user_id(),
                'session_id'    => $session_id,
                'message'       => $message,
                'character_id'  => $character_id,
                'platform_type' => $effective_platform,
                'images'        => $images,
                'via'           => 'prepare_llm_call',
                'kci_ratio'        => $this->current_kci_ratio ?? 80,
                'mention_override'  => $mention_override ?? false,
            ] );

            // Detect model + vision support
            $model_id = ( $character && ! empty( $character->model_id ) ) ? $character->model_id : '';
            $supports_vision = true;
            if ( ! empty( $model_id ) ) {
                if ( class_exists( 'BizCity_Knowledge_Context_API' ) ) {
                    $supports_vision = BizCity_Knowledge_Context_API::instance()->model_supports_vision( $model_id );
                } elseif ( class_exists( 'BizCity_OpenRouter_Models' ) ) {
                    $supports_vision = BizCity_OpenRouter_Models::supports_vision( $model_id );
                }
            }

            // Build messages array
            $openai_messages = [ [ 'role' => 'system', 'content' => $system_content ] ];

            // History from DB
            $hist_platform = $effective_platform ?: $this->detect_platform_type();
            if ( strpos( $session_id, 'zalobot_' ) === 0 ) {
                $hist_platform = 'ZALO_BOT';
            } elseif ( strpos( $session_id, 'zalo_' ) === 0 ) {
                $hist_platform = 'ZALO_PERSONAL';
            } elseif ( strpos( $session_id, 'telegram_' ) === 0 ) {
                $hist_platform = 'TELEGRAM';
            } elseif ( strpos( $session_id, 'adminchat_' ) === 0 ) {
                $hist_platform = 'ADMINCHAT';
            }
            $db_history = $this->get_history( $session_id, $hist_platform, 10 );
            foreach ( $db_history as $msg ) {
                $role = ( $msg['from'] === 'user' ) ? 'user' : 'assistant';
                $openai_messages[] = [ 'role' => $role, 'content' => $msg['msg'] ];
            }

            // Current message (with images if vision supported)
            if ( ! empty( $images ) && $supports_vision ) {
                $content = [];
                $content[] = [ 'type' => 'text', 'text' => $message ?: 'Hãy mô tả hoặc phân tích hình ảnh này.' ];
                foreach ( $images as $img ) {
                    $url = is_string( $img ) ? $img : ( $img['url'] ?? $img['data'] ?? '' );
                    if ( $url ) {
                        $content[] = [ 'type' => 'image_url', 'image_url' => [ 'url' => $url, 'detail' => 'auto' ] ];
                    }
                }
                $openai_messages[] = [ 'role' => 'user', 'content' => $content ];
                $result['vision_used'] = true;
            } else {
                $openai_messages[] = [ 'role' => 'user', 'content' => $message ];
            }

            return [
                'messages'    => $openai_messages,
                'character'   => $character,
                'model_id'    => $model_id,
                'result_base' => $result,
            ];
        }

        // ── LEGACY FALLBACK — context definitions consolidated in Twin Context Resolver ──
        $model_id = ( $character && ! empty( $character->model_id ) ) ? $character->model_id : '';
        $supports_vision = true;
        if ( ! empty( $model_id ) ) {
            if ( class_exists( 'BizCity_Knowledge_Context_API' ) ) {
                $supports_vision = BizCity_Knowledge_Context_API::instance()->model_supports_vision( $model_id );
            } elseif ( class_exists( 'BizCity_OpenRouter_Models' ) ) {
                $supports_vision = BizCity_OpenRouter_Models::supports_vision( $model_id );
            }
        }
        $system_content = ( $character && ! empty( $character->system_prompt ) )
            ? $character->system_prompt
            : ( $effective_platform === 'WEBCHAT'
                ? 'Bạn là Trợ lý AI hỗ trợ khách hàng của ' . get_bloginfo( 'name' ) . '. Trả lời thân thiện, ngắn gọn bằng tiếng Việt.'
                : 'Bạn là Trợ lý Team Leader AI cá nhân của BizCity. Trả lời bằng tiếng Việt.' );
        $system_content = apply_filters( 'bizcity_chat_system_prompt', $system_content, [
            'character_id'  => $character_id, 'message' => $message,
            'user_id'       => $wp_user_id, 'session_id' => $session_id,
            'platform_type' => $effective_platform,
        ] );
        $openai_messages = [ [ 'role' => 'system', 'content' => $system_content ] ];
        $hist_platform = $effective_platform ?: $this->detect_platform_type();
        if ( strpos( $session_id, 'zalobot_' ) === 0 ) { $hist_platform = 'ZALO_BOT'; }
        elseif ( strpos( $session_id, 'zalo_' ) === 0 ) { $hist_platform = 'ZALO_PERSONAL'; }
        elseif ( strpos( $session_id, 'telegram_' ) === 0 ) { $hist_platform = 'TELEGRAM'; }
        elseif ( strpos( $session_id, 'adminchat_' ) === 0 ) { $hist_platform = 'ADMINCHAT'; }
        $db_history = $this->get_history( $session_id, $hist_platform, 10 );
        foreach ( $db_history as $msg ) {
            $role = ( $msg['from'] === 'user' ) ? 'user' : 'assistant';
            $openai_messages[] = [ 'role' => $role, 'content' => $msg['msg'] ];
        }
        if ( ! empty( $images ) && $supports_vision ) {
            $content = [ [ 'type' => 'text', 'text' => $message ?: 'Hãy mô tả hoặc phân tích hình ảnh này.' ] ];
            foreach ( $images as $img ) {
                $url = is_string( $img ) ? $img : ( $img['url'] ?? $img['data'] ?? '' );
                if ( $url ) { $content[] = [ 'type' => 'image_url', 'image_url' => [ 'url' => $url, 'detail' => 'auto' ] ]; }
            }
            $openai_messages[] = [ 'role' => 'user', 'content' => $content ];
            $result['vision_used'] = true;
        } else {
            $openai_messages[] = [ 'role' => 'user', 'content' => $message ];
        }
        return [
            'messages'    => $openai_messages,
            'character'   => $character,
            'model_id'    => $model_id,
            'result_base' => $result,
        ];

        // @codeCoverageIgnoreStart — Legacy inline context building (unreachable)
        if ( class_exists('BizCity_Profile_Context') ) {
            $user_id_for_profile = $wp_user_id ? (int) $wp_user_id : get_current_user_id();
            $profile_ctx_instance = BizCity_Profile_Context::instance();

            $t0 = microtime( true );
            $profile_context = $profile_ctx_instance->build_user_context(
                $user_id_for_profile,
                $session_id,
                $effective_platform,
                ['coach_type' => '']
            );
            $timing['2:Profile'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );
            back_trace('INFO', "Profile context built for user_id={$user_id_for_profile}: " . substr($profile_context, 0, 200) . '...');

            // ── TWIN CORE: Ensure focus profile resolved BEFORE inline gate checks ──
            if ( class_exists( 'BizCity_Focus_Gate' ) ) {
                BizCity_Focus_Gate::ensure_resolved( $message, [
                    'user_id'       => $wp_user_id,
                    'session_id'    => $session_id,
                    'platform_type' => $effective_platform,
                    'images'        => $images,
                ] );
            }

            // ── Step 0b: Transit Context — Focus Gate gated (Sprint 0A) ──
            $t0 = microtime( true );
            $twin_build_transit = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'transit' );
            $transit_context = '';
            if ( $twin_build_transit ) {
                $transit_context = $profile_ctx_instance->build_transit_context(
                    $message,
                    $user_id_for_profile,
                    $session_id,
                    $effective_platform
                );
                // Force today's transit when user sends a vision image (Tarot/photo analysis)
                // — message text alone may not trigger intent detection
                if (empty($transit_context) && !empty($images)) {
                    $transit_context = $profile_ctx_instance->build_transit_context(
                        'chiêm tinh tháng này', // synthetic trigger → month period snapshot
                        $user_id_for_profile,
                        $session_id,
                        $effective_platform
                    );
                }
            }
            $timing['3:Transit'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );
            back_trace('INFO', "Transit context built for user_id={$user_id_for_profile} (gated={$twin_build_transit}): " . substr($transit_context, 0, 200) . '...');
        }

        // ── Step 1: Context API (embeddings + quick knowledge + vision) ──
        $t0 = microtime( true );
        $knowledge_context = '';
        if (class_exists('BizCity_Knowledge_Context_API')) {
            $context_api = BizCity_Knowledge_Context_API::instance();
            $ctx = $context_api->build_context($character_id, $message, [
                'max_tokens'     => 3000,
                'include_vision' => !empty($images),
                'images'         => $images,
            ]);
            $knowledge_context = $ctx['context'] ?? '';
        }
        $timing['4a:ContextAPI'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );

        // ── Step 2: Keyword search (bizcity_knowledge_search_character) ──
        $t0 = microtime( true );
        if ($character_id && function_exists('bizcity_knowledge_search_character')) {
            $char_keyword_ctx = bizcity_knowledge_search_character($message, $character_id);
            if (!empty($char_keyword_ctx)) {
                if (!empty($knowledge_context)) {
                    if (strpos($knowledge_context, $char_keyword_ctx) === false) {
                        $knowledge_context .= "\n\n---\n\n### Kiến thức bổ sung (keyword search):\n" . $char_keyword_ctx;
                    }
                } else {
                    $knowledge_context = $char_keyword_ctx;
                }
            }
        }
        $timing['4b:KeywordSearch'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );

        // ── Step 3: Build LLM messages ──
        $model_id = ($character && !empty($character->model_id)) ? $character->model_id : '';
        $supports_vision = false;
        if (class_exists('BizCity_Knowledge_Context_API') && !empty($model_id)) {
            $supports_vision = BizCity_Knowledge_Context_API::instance()->model_supports_vision($model_id);
        } elseif (!empty($model_id) && class_exists('BizCity_OpenRouter_Models')) {
            // Use the network model registry as an additional/fallback vision check
            $supports_vision = BizCity_OpenRouter_Models::supports_vision($model_id);
        } elseif (empty($model_id)) {
            $supports_vision = true; // gpt-4o-mini supports vision
        }

        $openai_messages = [];

        // System prompt
        $system_content = '';
        if ($character && !empty($character->system_prompt)) {
            $system_content = $character->system_prompt;
        }

        // ══════════════════════════════════════════════════════════════════
        // 🧠 LAYER 0: USER MEMORY — ƯU TIÊN SỐ 1, INJECT TRƯỚC TẤT CẢ
        // Ghi nhớ cách xưng hô, tên gọi, sở thích — ghi đè mọi pipeline khác
        // ══════════════════════════════════════════════════════════════════
        $t0 = microtime( true );
        $memory_context = '';
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $mem = BizCity_User_Memory::instance();
            $q_uid = $wp_user_id > 0 ? (int) $wp_user_id : 0;
            $q_sid = $wp_user_id > 0 ? ''                 : $session_id;
            $memory_context = $mem->build_memory_context( $q_uid, $q_sid, $session_id );
        }
        $timing['1:Memory'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );
        if ( ! empty( $memory_context ) ) {
            $system_content .= $memory_context;
        }

        // ── Profile & Transit injection — directly into base prompt ──
        if (!empty($profile_context)) {
            $system_content .= "\n\n---\n\n" . $profile_context;
        }
        if (!empty($transit_context)) {
            $system_content .= "\n\n---\n\n" . $transit_context;
        }

        if (!empty($knowledge_context)) {
            $system_content .= "\n\n---\n\n## Kiến thức tham khảo:\n" . $knowledge_context;
        }

        // Final behavioral instruction — MUST be at the end of system prompt
        $system_content .= "\n\n---\n\n## QUY TẮC TRẢ LỜI (BẮT BUỘC — ƯU TIÊN CAO NHẤT):\n";

        if (!empty($profile_context)) {
            $system_content .= "### 📌 Nhận diện người dùng:\n";
            $system_content .= "1. Bạn ĐÃ BIẾT người đang trò chuyện thông qua Hồ Sơ Chủ Nhân ở trên. ";
            $system_content .= "Khi họ hỏi \"tôi là ai\", \"bạn biết tôi không\", hãy trả lời TỰ TIN dựa trên hồ sơ (ví dụ: \"Dạ, bạn là [tên], ...\").\n";
            $system_content .= "2. Luôn gọi người dùng bằng TÊN khi có thể, thể hiện sự thân thiện và cá nhân hóa.\n\n";

            // CORE GROUNDING RULES — always active when profile data exists
            $system_content .= "### 🔒 NỀN TẢNG TRẢ LỜI — LUÔN BÁM THEO DỮ LIỆU:\n";
            $system_content .= "🔴 **QUY TẮC CỐT LÕI**: Mọi câu trả lời về cuộc sống, tương lai, tính cách, sự nghiệp, tài chính, tình cảm, hôn nhân, sức khỏe, tiền bạc, tinh duyên, ngày mai, tuần tới, tháng tới, năm tới ĐỀU PHẢI dựa trên:\n";
            $system_content .= "   a) **Bản đồ chiêm tinh natal** (vị trí các sao lúc sinh) — đã có trong Hồ Sơ Chủ Nhân\n";
            $system_content .= "   b) **Kết quả luận giải (gen_results)** — phân tích SWOT, thần số học, ngũ hành... đã có trong Hồ Sơ\n";
            $system_content .= "   c) **Câu trả lời coaching (answer_json)** — thông tin user tự khai trong các bước tư vấn\n";
            if (!empty($transit_context)) {
                $system_content .= "   d) **Dữ liệu Transit chiêm tinh** — vị trí THỰC TẾ các sao trên bầu trời đã được cung cấp ở trên\n";
            }
            $system_content .= "\n";
            $system_content .= "🚫 **CẤM TUYỆT ĐỐI**:\n";
            $system_content .= "   - KHÔNG được bịa đặt vị trí sao, góc chiếu, hay dữ liệu chiêm tinh không có trong hồ sơ\n";
            $system_content .= "   - KHÔNG được trả lời chung chung mà không tham chiếu dữ liệu cụ thể từ hồ sơ của user\n\n";

            $system_content .= "✅ **YÊU CẦU BẮT BUỘC khi trả lời về tương lai/dự báo/xu hướng/chủ đề cuộc sống**:\n";
            $system_content .= "   - Luôn nhắc TÊN SAO cụ thể + CUNG + GÓC CHIẾU khi phân tích\n";
            $system_content .= "   - Liên hệ trực tiếp với natal chart và gen_results của user\n";
            $system_content .= "   - Tham chiếu các câu trả lời coaching (answer_json) khi liên quan đến chủ đề hỏi\n";
            if (!empty($transit_context)) {
                $system_content .= "   - Sử dụng DỮ LIỆU TRANSIT THỰC TẾ đã cung cấp — nêu rõ sao nào đang ở cung nào, tạo góc chiếu gì với natal\n";
            }
            $system_content .= "\n";
        }

        if (!empty($transit_context)) {
            $system_content .= "### ⭐ ĐẶC BIỆT — DỮ LIỆU TRANSIT:\n";
            $system_content .= "Dữ liệu transit chiêm tinh THỰC TẾ đã được cung cấp phía trên. Bạn PHẢI:\n";
            $system_content .= "- Phân tích dựa HOÀN TOÀN trên vị trí transit thực tế + natal chart\n";
            $system_content .= "- Giải thích cụ thể: sao transit nào, ở cung nào, tạo góc chiếu gì, ảnh hưởng gì\n";
            $system_content .= "- Liên hệ với gen_results và answer_json để cá nhân hóa dự báo\n";
            $system_content .= "- Ưu tiên phân tích transit thực tế; nếu user muốn bốc bài Tarot, có thể kết hợp giải nghĩa lá bài + chiêm tinh cùng nhau\n\n";
        }

        // ── Mandatory Tarot + Astrology fusion block (when image + profile + transit present) ──
        if (!empty($images) && !empty($profile_context)) {
            $system_content .= "### 🃏 HƯỚNG DẪN BẮT BUỘC KHI USER GỬI ẢNH LÁ BÀI / HÌNH ẢNH:\n";
            $system_content .= "Bạn đang nhận được MỘT ẢNH từ user đồng thời có đầy đủ HỒ SƠ CHIÊM TINH cá nhân. ";
            $system_content .= "PHẢI trả lời theo cấu trúc sau — KHÔNG ĐƯỢC bỏ qua bất kỳ bước nào:\n\n";
            $system_content .= "**Bước 1 — Nhận diện ảnh:** Xác định đây là lá bài Tarot nào / hình ảnh gì.\n";
            $system_content .= "**Bước 2 — Ý nghĩa phổ quát:** Giải nghĩa ý nghĩa chuẩn của lá bài/hình ảnh đó (1-2 câu ngắn gọn).\n";
            $system_content .= "**Bước 3 — Chiếu lên Bản đồ Sao của " . ($wp_user_id ? 'user' : 'bạn') . ":** BẮT BUỘC liên hệ ý nghĩa lá bài với natal chart cụ thể — nêu TÊN SAO + CUNG nào trong natal của user cộng hưởng với thông điệp lá bài.\n";
            if (!empty($transit_context)) {
                $system_content .= "**Bước 4 — Transit hiện tại (hôm nay):** BẮT BUỘC chỉ ra VỊ TRÍ SAO TRANSIT THỰC TẾ đang tương tác như thế nào với natal chart — tăng cường hay thách thức thông điệp lá bài.\n";
                $system_content .= "**Bước 5 — Lời khuyên cá nhân hóa:** Dựa trên natal + transit + ý nghĩa lá bài, đưa ra 1-2 hành động cụ thể phù hợp với user này.\n\n";
            } else {
                $system_content .= "**Bước 4 — Lời khuyên cá nhân hóa:** Dựa trên natal chart + ý nghĩa lá bài, đưa ra 1-2 hành động cụ thể phù hợp với user này.\n\n";
            }
            $system_content .= "⛔ NGHIÊM CẤM trả lời chung chung không nhắc tới natal chart cụ thể của user.\n";
            $system_content .= "⛔ NGHIÊM CẤM bỏ qua dữ liệu hồ sơ đã cung cấp trong system prompt.\n\n";
        }

        if (!empty($knowledge_context)) {
            $system_content .= "### 📚 Kiến thức: Ưu tiên sử dụng kiến thức tham khảo để trả lời chính xác. Nếu không có trong kiến thức, trả lời dựa trên hiểu biết chung.\n";
        }

        // ── Response depth rule — detect chiêm tinh / tarot / transit / dự báo intent ──
        $astro_tarot_intent = !empty($transit_context)
            || !empty($images)
            || (bool) preg_match(
                '/chiêm tinh|hoa tinh|natal|transit|tarot|lá bài|bói|tử vi|phong thủy|'
                . 'hôm nay thế nào|ngày mai|tuần sau|tuần tới|tháng này|tháng sau|'
                . 'năm tới|dự báo|xu hướng|tính cách|phân tích|mệnh|nghiệp|'
                . 'tình duyên|sự nghiệp|tài chính|sức khỏe|hôn nhân|tương lai/ui',
                $message
            );

        if ($astro_tarot_intent) {
            $system_content .= "### 📏 ĐỘ DÀI & CẤU TRÚC TRẢ LỜI (BẮT BUỘC):\n";
            $system_content .= "🔴 Đây là chủ đề chiêm tinh / tarot / dự báo. Bạn PHẢI trả lời ĐẦY ĐỦ, CỤ THỂ, DÀI (tối thiểu 200–400 từ):\n";
            $system_content .= "1. Phân tích từng mục theo danh sách có đánh số (1. / 2. / 3. ...).\n";
            $system_content .= "2. Mỗi mục nêu TÊN SAO + CUNG + GÓC CHIẾU + ảnh hưởng cụ thể với người dùng NÀY.\n";
            $system_content .= "3. Cuối cùng: Đưa ra 2–3 lời khuyên hành động cụ thể (NÊN làm gì, TRÁNH gì, TẬN DỤNG gì).\n";
            $system_content .= "4. Giọng văn thân mật, hồi hộp, có cảm xúc — như một người bạn thân đang chia sẻ.\n";
            $system_content .= "🚫 TUYỆT ĐỐI KHÔNG trả lời vắn tắt 1–2 câu, không dùng bullet points chung chung thiếu dữ liệu natal.\n\n";
        } else {
            $system_content .= "### 🗨️ Phong cách trả lời:\n";
            $system_content .= "Trả lời rõ ràng, đầy đủ (không ngắn cụt). Câu hỏi đơn giản → ngắn gọn; câu hỏi cần phân tích → trả lời chiết lọc đầy đủ.\n\n";
        }

        $system_content .= "### 🗣️ Ngôn ngữ: Trả lời bằng tiếng Việt, thân thiện, tự nhiên, giàu cảm xúc.\n";

        // ── Team Leader role definition ──
        // ── Role block — CHỈ mô tả vai trò, KHÔNG nhắc Chợ (đã có ở END REMINDER) ──
        $role_block  = "\n\n## 🧑‍💼 VAI TRÒ CỦA BẠN:\n";
        $role_block .= "Bạn là **Trợ lý Team Leader cá nhân** của Chủ Nhân (người đang trò chuyện).\n";
        $role_block .= "- Bạn điều phối, tư vấn và hỗ trợ Chủ Nhân quản lý công việc, cuộc sống.\n";
        $role_block .= "- Trong hệ thống BizCity còn có NHIỀU AI Agent chuyên biệt khác (viết nội dung, chiêm tinh, marketing, kế toán, thiết kế, lập trình...) có thể giúp Chủ Nhân thực thi công việc cụ thể.\n";
        $role_block .= "\n### ⛔ RANH GIỚI VAI TRÒ BẮT BUỘC:\n";
        $role_block .= "- Bạn là AI Trợ lý. Chủ Nhân là NGƯỜI DÙNG đang nhắn tin cho bạn.\n";
        $role_block .= "- KHÔNG BAO GIỜ tự xưng bằng tên Chủ Nhân (VD: không nói \"Chu đây!\", không nói \"Anh Chu đẹp trai đây\").\n";
        $role_block .= "- KHÔNG nhập vai thành Chủ Nhân. KHÔNG nói như thể BẠN là người dùng.\n";
        $role_block .= "- Khi xưng hô 'mày tao': Chủ Nhân xưng 'tao', gọi AI là 'mày'. AI KHÔNG xưng 'tao' — AI xưng phù hợp với vai trợ lý.\n";
        $system_content .= $role_block;

        if (empty(trim($system_content))) {
            $system_content = "Bạn là Trợ lý Team Leader AI cá nhân của BizCity. Trả lời đầy đủ, chi tiết, chính xác bằng tiếng Việt. Với câu hỏi về chiêm tinh/tarot/dự báo, hãy phân tích chi tiết từng mục có đánh số rõ ràng.";
        }

        // ── Inject long-term memory from bizcity_memory_users (unified, all channels) ──
        /**
         * Memory injection logic:
         * - Check if BizCity_User_Memory class exists (memory module active)
         * - Determine user ID for memory retrieval (explicit $wp_user_id or current user)
         * - Build memory context string using build_memory_context() method
         * - Append memory context to system prompt if not empty
         * - Log memory retrieval details for debugging and analytics
         */
        // ── Log context assembly for admin AJAX Console ──
        // ⚠️ LEGACY PATH — prepare_llm_call does its own prompt assembly.
        // TODO: Replace with $this->build_system_prompt() call to unify pipeline.
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'context_build',
                'message'          => mb_substr( $message, 0, 120, 'UTF-8' ),
                'mode'             => 'prepare_llm_call',
                'functions_called' => 'prepare_llm_call() [LEGACY — chưa delegate build_system_prompt]',
                'pipeline'         => [
                    '0:Character'    . ( $character                     ? ' ✓' : ' —' ),
                    '1:Memory'       . ( ! empty( $memory_context )     ? ' ✓' : ' —' ),
                    '2:Profile'      . ( ! empty( $profile_context )    ? ' ✓' : ' —' ),
                    '3:Transit'      . ( ! empty( $transit_context )    ? ' ✓' : ' —' ),
                    '4:Knowledge'    . ( ! empty( $knowledge_context )  ? ' ✓' : ' —' ),
                    '4c:IntentTag'   . ( ! empty( $ctx['metadata']['has_intent_tag'] ?? false ) ? ' ✓' : ' —' ),
                    '5:Conversation —',
                    '6:Rules ✓',
                    '7:Role ✓',
                    '→ 8:Filters',
                    '→ 9:EndReminder',
                ],
                'file_line'        => 'class-chat-gateway.php::prepare_llm_call',
                'context_length'   => mb_strlen( $system_content, 'UTF-8' ),
                'has_memory'       => ! empty( $memory_context ),
                'has_profile'      => ! empty( $profile_context ),
                'has_transit'      => ! empty( $transit_context ),
                'has_knowledge'    => ! empty( $knowledge_context ),
                'has_conversation' => false,
                'context_ms'       => round( ( microtime( true ) - $context_start ) * 1000, 2 ),
                'timing_breakdown' => $timing,
                'slowest_step'     => ! empty( $timing ) ? array_search( max( $timing ), $timing ) . ' (' . max( $timing ) . 'ms)' : '',
            ], $session_id );
        }

        /**
         * Filter: bizcity_chat_system_prompt
         * Allows plugins (including Intent Provider architecture) to inject
         * additional domain context, system instructions, or behavioural rules
         * into the system prompt BEFORE it is sent to the LLM.
         *
         * @since 1.3.1
         * @param string $system_content  The assembled system prompt.
         * @param array  $args            Contextual data: character_id, message, user_id, session_id, platform_type.
         */

        // ── BizCoach Profile/Transit injection at priority 95 — TEMPORARILY DISABLED ──
        // TODO: Re-enable when pipeline context chain is stable
        // Profile/Transit context is still built in Step 0 above and injected into base prompt
        /* DISABLED — BizCoach filter injection paused for pipeline stability
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'bizcoach_precheck',
                'message'          => 'Pre-filter check for BizCoach context',
                'mode'             => 'debug',
                'has_profile'      => ! empty( $profile_context ),
                'has_transit'      => ! empty( $transit_context ),
                'profile_length'   => mb_strlen( $profile_context ?? '', 'UTF-8' ),
                'transit_length'   => mb_strlen( $transit_context ?? '', 'UTF-8' ),
                'will_inject'      => ( ! empty( $profile_context ) || ! empty( $transit_context ) ),
            ], $session_id );
        }
        if ( ! empty( $profile_context ) || ! empty( $transit_context ) ) {
            $bizcoach_profile  = $profile_context;
            $bizcoach_transit  = $transit_context;
            $bizcoach_sess_id  = $session_id;
            add_filter( 'bizcity_chat_system_prompt', function( $prompt ) use ( $bizcoach_profile, $bizcoach_transit, $bizcoach_sess_id ) {
                // ... filter body ...
                return $prompt . $injection;
            }, 95, 1 );
        }
        */

        $system_content = apply_filters( 'bizcity_chat_system_prompt', $system_content, array(
            'character_id'  => $character_id,
            'message'       => $message,
            'user_id'       => $wp_user_id,
            'session_id'    => $session_id,
            'platform_type' => $effective_platform,
        ) );

        // ── Log final system prompt for debugging ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $prompt_len   = mb_strlen( $system_content, 'UTF-8' );
            $word_count   = str_word_count( strip_tags( $system_content ) );
            $preview_head = mb_substr( $system_content, 0, 500, 'UTF-8' );
            $preview_tail = $prompt_len > 1000 ? mb_substr( $system_content, -500, 500, 'UTF-8' ) : '';
            BizCity_User_Memory::log_router_event( [
                'step'             => 'final_prompt',
                'message'          => 'System prompt built for LLM (via chat_gateway)',
                'mode'             => 'debug',
                'functions_called' => 'prepare_llm_call() + apply_filters()',
                'file_line'        => 'class-chat-gateway.php::prepare_llm_call',
                'prompt_length'    => $prompt_len,
                'word_count'       => $word_count,
                'has_bizcoach'     => ( strpos( $system_content, 'BIZCOACH CONTEXT' ) !== false ),
                'has_memory'       => ( strpos( $system_content, 'KÝ ỨC USER' ) !== false || strpos( $system_content, 'USER MEMORY' ) !== false ),
                'has_context_chain'=> ( strpos( $system_content, 'CONTEXT CHAIN' ) !== false || strpos( $system_content, 'PHIÊN CHAT' ) !== false ),
                'prompt_head'      => $preview_head,
                'prompt_tail'      => $preview_tail,
                'full_prompt'      => $system_content,
            ], $session_id );
        }

        // ══════════════════════════════════════════════════════════════════
        // 🔴 CRITICAL END REMINDER — positioned LAST in system prompt
        // ══════════════════════════════════════════════════════════════════
        $end_reminder  = "\n\n# ⚠️ NHẮC NHỞ QUAN TRỌNG (BẮT BUỘC ĐỌC TRƯỚC KHI TRẢ LỜI):\n";
        $end_reminder .= "\n## 🚫 DANH SÁCH CÂU BỊ CẤM — KHÔNG BAO GIỜ ĐƯỢC NÓI:\n";
        $end_reminder .= "- 'tôi không có quyền truy cập thông tin cá nhân'\n";
        $end_reminder .= "- 'tôi không có quyền truy cập vào thông tin cá nhân hoặc hồ sơ'\n";
        $end_reminder .= "- 'hãy liên hệ bộ phận hỗ trợ'\n";
        $end_reminder .= "- 'tôi không biết thông tin về bạn'\n";
        $end_reminder .= "- 'tôi không có khả năng truy cập'\n";
        $end_reminder .= "- 'tôi là AI nên không thể truy cập'\n";
        $end_reminder .= "- 'liên hệ email/hotline/admin để được hỗ trợ'\n";
        $end_reminder .= "- Bất kỳ biến thể nào của các câu trên\n";
        $end_reminder .= "Nếu bạn sắp nói bất kỳ câu nào giống như trên → DỪNG LẠI và dùng mẫu fallback bên dưới.\n";
        if ( ! empty( $profile_context ) ) {
            $end_reminder .= "\n## ✅ BẠN ĐÃ CÓ HỒ SƠ CHỦ NHÂN:\n";
            $end_reminder .= "- Hồ sơ người dùng đã được cung cấp ở phần trên của prompt này.\n";
            $end_reminder .= "- HÃY sử dụng hồ sơ để cá nhân hóa câu trả lời.\n";
            $end_reminder .= "- HÃY gọi người dùng bằng TÊN (nếu có trong hồ sơ).\n";
        }

        // ── v4.3: Tool Registry Verification (prepare_llm_call path) ──
        $llm_matching_tool = null;
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $llm_msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
            $llm_msg_words = array_filter(
                preg_split( '/[\s,;.!?]+/u', $llm_msg_lower ),
                function( $w ) { return mb_strlen( $w, 'UTF-8' ) >= 2; }
            );
            if ( ! empty( $llm_msg_words ) ) {
                $llm_tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
                foreach ( $llm_tools as $llm_row ) {
                    $llm_fields = mb_strtolower(
                        ( $llm_row['goal'] ?? '' ) . ' ' . ( $llm_row['title'] ?? '' ) . ' '
                        . ( $llm_row['goal_label'] ?? '' ) . ' ' . ( $llm_row['custom_hints'] ?? '' ) . ' '
                        . ( $llm_row['goal_description'] ?? '' ) . ' ' . ( $llm_row['plugin'] ?? '' ),
                        'UTF-8'
                    );
                    foreach ( $llm_msg_words as $llm_kw ) {
                        if ( mb_strpos( $llm_fields, $llm_kw ) !== false && mb_strlen( $llm_kw, 'UTF-8' ) >= 3 ) {
                            $llm_matching_tool = $llm_row;
                            break 2;
                        }
                    }
                }
            }
        }

        // ── v4.3: Log tool_registry_verify pipe step (prepare_llm_call path) ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $llm_v_outcome = $llm_matching_tool ? 'TOOL_EXISTS' : 'no_match';
            $llm_v_detail  = '';
            if ( $llm_matching_tool ) {
                $llm_v_detail = ( $llm_matching_tool['goal_label'] ?: $llm_matching_tool['title'] ?: $llm_matching_tool['tool_name'] )
                    . ' (plugin: ' . ( $llm_matching_tool['plugin'] ?? '' ) . ')';
            } else {
                $llm_v_detail = 'No tool found → using fallback template';
            }
            BizCity_User_Memory::log_router_event( [
                'step'             => 'tool_registry_verify',
                'message'          => mb_substr( $message, 0, 120, 'UTF-8' ),
                'mode'             => 'gateway → prepare_llm_call → end_reminder',
                'method'           => 'keyword_scan',
                'functions_called' => 'BizCity_Intent_Tool_Index::get_all_active() → keyword match',
                'pipeline'         => [ 'extract_keywords', 'scan_tool_registry', 'outcome:' . $llm_v_outcome ],
                'response_preview' => $llm_v_outcome . ' → ' . $llm_v_detail,
                'outcome'          => $llm_v_outcome,
                'matched_tool'     => $llm_matching_tool ? [
                    'goal'       => $llm_matching_tool['goal'] ?? '',
                    'goal_label' => $llm_matching_tool['goal_label'] ?? '',
                    'plugin'     => $llm_matching_tool['plugin'] ?? '',
                    'title'      => $llm_matching_tool['title'] ?? '',
                ] : null,
                'file_line'        => 'class-chat-gateway.php::prepare_llm_call_tool_verify',
            ], $session_id );
        }

        if ( $llm_matching_tool ) {
            $end_reminder .= "\n## 📋 HƯỚNG DẪN:\n";
            $end_reminder .= "→ TUYỆT ĐỐI KHÔNG nói 'mình chưa có trợ lý chuyên về...'.\n";
            $end_reminder .= "→ HÃY TRẢ LỜI câu hỏi dựa trên hiểu biết của bạn.\n";
            $end_reminder .= "→ KHÔNG gợi ý công cụ, KHÔNG gợi ý Chợ AI Agent, KHÔNG hỏi 'Bạn có muốn dùng công cụ X không?'.\n";
            $end_reminder .= "→ Cuối câu trả lời, hãy đặt 1-2 câu hỏi gợi mở để Chủ Nhân đào sâu thêm vào vấn đề đang thảo luận.\n";
        } else {
            $end_reminder .= "\n## 📋 MẪU TRẢ LỜI FALLBACK — khi chức năng CHƯA CÓ trên hệ thống:\n";
            $end_reminder .= "Ví dụ: nghe nhạc, phát nhạc, xem phim, đặt hàng, chuyển khoản, gọi điện, tra thời tiết, giá cổ phiếu, tìm đường, đặt lịch, gửi email, quản lý kho, thiết kế ảnh...\n";
            $end_reminder .= "→ Trả lời ĐÚNG mẫu sau (thay [chức năng] bằng tên chức năng user yêu cầu):\n";
            $end_reminder .= "  'Hiện tại mình chưa có trợ lý chuyên về [chức năng]. Nhưng bạn có thể vào **Chợ AI Agent** của BizCity để chọn một trợ lý phù hợp — sau khi kích hoạt, mình sẽ phối hợp với Agent đó để giúp bạn thực hiện công việc này! 🚀'\n";
            $end_reminder .= "→ KHÔNG nói 'không có quyền', KHÔNG nói 'liên hệ hỗ trợ', KHÔNG đổ lỗi cho người dùng.\n";
            $end_reminder .= "→ Luôn thể hiện tinh thần: 'Mình là Team Leader của bạn — việc gì cũng có cách giải quyết!'\n";
        }
        $system_content .= $end_reminder;

        $openai_messages[] = ['role' => 'system', 'content' => $system_content];

        // History from DB — infer platform from session_id prefix
        $hist_platform = $effective_platform ?: $this->detect_platform_type();
        if (strpos($session_id, 'zalobot_') === 0) {
            $hist_platform = 'ZALO_BOT';
        } elseif (strpos($session_id, 'zalo_') === 0) {
            $hist_platform = 'ZALO_PERSONAL';
        } elseif (strpos($session_id, 'telegram_') === 0) {
            $hist_platform = 'TELEGRAM';
        } elseif (strpos($session_id, 'adminchat_') === 0) {
            $hist_platform = 'ADMINCHAT';
        }
        $db_history = $this->get_history($session_id, $hist_platform, 10);
        foreach ($db_history as $msg) {
            $role = ($msg['from'] === 'user') ? 'user' : 'assistant';
            $openai_messages[] = ['role' => $role, 'content' => $msg['msg']];
        }

        // Current message (with images if vision supported)
        if (!empty($images) && $supports_vision) {
            $content = [];
            $content[] = ['type' => 'text', 'text' => $message ?: 'Hãy mô tả hoặc phân tích hình ảnh này.'];
            foreach ($images as $img) {
                $url = is_string($img) ? $img : ($img['url'] ?? $img['data'] ?? '');
                if ($url) {
                    $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => 'auto']];
                }
            }
            $openai_messages[] = ['role' => 'user', 'content' => $content];
            $result['vision_used'] = true;
        } else {
            $openai_messages[] = ['role' => 'user', 'content' => $message];
        }

        // ── Return prepared data for caller (get_ai_response / ajax_stream) ──
        return [
            'messages'    => $openai_messages,
            'character'   => $character,
            'model_id'    => $model_id,
            'result_base' => $result,
        ];
    }

    /* ================================================================
     * Core: Get AI response — THE single pipeline (public API)
     *
     * 1. Context API (embeddings + quick knowledge + vision)
     * 2. Keyword search (bizcity_knowledge_search_character)
     * 3. Build prompt + history
     * 4. Call LLM (OpenRouter or OpenAI)
     *
     * Public method so external plugins can call it directly.
     * ================================================================ */
    public function get_ai_response($character_id, $message, $images = [], $session_id = '', $history_json = '[]', $wp_user_id = 0, $platform_type_hint = '') {
        // ── Smart Gateway: delegate to server if enabled ──
        if ( defined( 'BIZCITY_SMART_GATEWAY_ENABLED' ) && BIZCITY_SMART_GATEWAY_ENABLED && class_exists( 'BizCity_Smart_Gateway' ) ) {
            $platform_type = $platform_type_hint ?: $this->detect_platform_type();
            $runtime_user_id = $wp_user_id ?: get_current_user_id();

            $smart  = new BizCity_Smart_Gateway();
            $params = [
                'message'       => $message,
                'user_id'       => $runtime_user_id,
                'session_id'    => $session_id,
                'character_id'  => $character_id,
                'platform_type' => $platform_type,
                'images'        => $images,
                'kci_ratio'     => $this->current_kci_ratio ?? 80,
            ];

            // ── Local Intent first (same policy as stream path) ──
            // Keep HIL/slot/pre-confirm/tool execution local. Only compose branches
            // are handed off to Smart Gateway with client_engine_result.
            if ( class_exists( 'BizCity_Intent_Engine' ) ) {
                $channel_map = [ 'WEBCHAT' => 'webchat', 'ADMINCHAT' => 'adminchat' ];
                $intent_result = BizCity_Intent_Engine::instance()->process( [
                    'message'       => $message,
                    'session_id'    => $session_id,
                    'user_id'       => $runtime_user_id,
                    'channel'       => $channel_map[ $platform_type ] ?? 'webchat',
                    'character_id'  => $character_id,
                    'images'        => $images,
                ] );

                $intent_action = $intent_result['action'] ?? 'passthrough';
                if ( ! empty( $intent_result['reply'] ) && ! in_array( $intent_action, [ 'passthrough', 'compose_answer' ], true ) ) {
                    return [
                        'message'       => $intent_result['reply'],
                        'provider'      => 'local-intent',
                        'model'         => '',
                        'usage'         => [],
                        'engine_result' => $intent_result,
                        'suggestions'   => [],
                    ];
                }

                $params['client_engine_result'] = $this->build_client_engine_result( $intent_result );
            }

            $result = $smart->resolve( $params );
            if ( ! empty( $result['success'] ) ) {
                return [
                    'message'       => $result['response'] ?? '',
                    'provider'      => 'smart-gateway',
                    'model'         => $result['usage']['model'] ?? '',
                    'usage'         => $result['usage'] ?? [],
                    'engine_result' => $result['engine_result'] ?? [],
                    'suggestions'   => $result['suggestions'] ?? [],
                ];
            }
            // Server failed — return error (no local fallback)
            error_log( '[SmartGateway] Server failed: ' . ( $result['error'] ?? 'unknown' ) );
            return [
                'message'  => 'Xin lỗi, hệ thống AI tạm thời không khả dụng. Vui lòng thử lại sau.',
                'provider' => 'smart-gateway',
                'model'    => '',
                'usage'    => [],
            ];
        }

        $prepared = $this->prepare_llm_call($character_id, $message, $images, $session_id, $history_json, $wp_user_id, $platform_type_hint);

        // Early error (e.g. Knowledge DB not ready)
        if (isset($prepared['error'])) {
            return $prepared['error'];
        }

        $character       = $prepared['character'];
        $openai_messages = $prepared['messages'];
        $result          = $prepared['result_base'];

        // ── Step 4: Call LLM ──
        if ($character && !empty($character->model_id)) {
            $reply_data = $this->call_openrouter($character, $openai_messages);
        } else {
            $reply_data = $this->call_openai($openai_messages);
        }

        $result['message']  = $reply_data['message'] ?? 'Xin lỗi, không nhận được phản hồi.';
        $result['provider'] = $reply_data['provider'] ?? '';
        $result['model']    = $reply_data['model'] ?? '';
        $result['usage']    = $reply_data['usage'] ?? [];

        return $result;
    }

    /* ================================================================
     * AJAX: SSE Stream — character-by-character streaming for admin chat
     *
     * Reuses the same context-building pipeline (prepare_llm_call)
     * but streams via bizcity_openrouter_chat_stream() + SSE events.
     *
     * Registered at priority 20 so bizcity-intent (priority 10) takes
     * precedence when active. If intent handles & exits, this never runs.
     * ================================================================ */
    public function ajax_stream() {
        // ── Platform detection + permission check ──
        $platform_type = $this->detect_platform_type();

        // WEBCHAT frontend widget: limit output tokens
        if ( $platform_type === 'WEBCHAT' ) {
            $this->max_tokens_override = 500;
        }

        if ($platform_type === 'ADMINCHAT') {
            if (!$this->verify_nonce()) {
                error_log('[chat-gateway-stream] Invalid nonce for ADMINCHAT | user=' . get_current_user_id() . ' | nonce_wpnonce=' . ($_POST['_wpnonce'] ?? '') . ' | nonce=' . ($_POST['nonce'] ?? ''));
                $this->send_stream_error('Invalid nonce');
                return;
            }
            if (!current_user_can('edit_posts')) {
                error_log('[chat-gateway-stream] Permission denied | user=' . get_current_user_id());
                $this->send_stream_error('Permission denied');
                return;
            }
        }

        // ── Parse input (same as ajax_send) ──
        $message      = sanitize_textarea_field($_POST['message'] ?? '');
        $character_id = intval($_POST['character_id'] ?? 0);
        $session_id   = sanitize_text_field($_POST['session_id'] ?? '');
        $plugin_slug  = sanitize_text_field($_POST['plugin_slug'] ?? '');
        $routing_mode = sanitize_text_field($_POST['routing_mode'] ?? 'automatic');
        $provider_hint = sanitize_text_field($_POST['provider_hint'] ?? '');
        $tool_goal     = sanitize_text_field($_POST['tool_goal'] ?? '');
        $tool_name     = sanitize_text_field($_POST['tool_name'] ?? '');
        $images       = [];
        if (!empty($_POST['images'])) {
            $raw_images = json_decode(stripslashes($_POST['images'] ?? '[]'), true) ?: [];
            // Convert base64 images to Media Library URLs
            if ( function_exists( 'bizcity_convert_images_to_media_urls' ) ) {
                $images = bizcity_convert_images_to_media_urls( $raw_images );
            } else {
                $images = $raw_images;
            }
        }
        if (!empty($_POST['image_data'])) {
            // Single base64 from old widget format - convert to Media
            $single_img = $_POST['image_data'];
            if ( function_exists( 'bizcity_save_base64_to_media' ) && strpos( $single_img, 'data:image/' ) === 0 ) {
                $media = bizcity_save_base64_to_media( $single_img );
                if ( ! is_wp_error( $media ) ) {
                    $images[] = $media['url'];
                }
            } else {
                $images[] = $single_img;
            }
        }

        // Accept /slash command even when frontend does not send explicit tool_goal.
        if ( ! $tool_goal && preg_match( '/^\/([a-z0-9_]+)(?:\s+(.*))?$/si', $message, $slash_match ) ) {
            $tool_goal = strtolower( $slash_match[1] );
            $message   = trim( $slash_match[2] ?? '' );
            error_log( '[chat-gateway-stream] slash_detected | tool_goal=' . $tool_goal . ' | message_len=' . mb_strlen( $message, 'UTF-8' ) );
        }

        if ( ! $provider_hint && $plugin_slug && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $provider_hint = BizCity_Intent_Provider_Registry::instance()->resolve_slug( $plugin_slug );
        }
        $history_json = stripslashes($_POST['history'] ?? '[]');
        if (!$character_id) {
            $character_id = $this->get_default_character_id();
        }
        if (!$message && empty($images)) {
            $this->send_stream_error('Tin nhắn trống');
            return;
        }
        if (!$session_id) {
            $session_id = $this->get_session_id($platform_type);
        }

        $user_id     = get_current_user_id();
        $user        = wp_get_current_user();
        $client_name = $user->ID ? ($user->display_name ?: $user->user_login) : 'Guest';
        
        // Store KCI for trace emission
        $kci_ratio = 80;
        if ( $session_id && class_exists( 'BizCity_WebChat_Database' ) ) {
            $session_obj = BizCity_WebChat_Database::instance()->get_session_v3_by_session_id( $session_id );
            if ( $session_obj && isset( $session_obj->kci_ratio ) ) {
                $kci_ratio = (int) $session_obj->kci_ratio;
            }
        }
        if ( $platform_type === 'WEBCHAT' ) {
            $kci_ratio = 100;
        }
        $this->current_kci_ratio = $kci_ratio;

        // ── Log user message ──
        $this->log_message([
            'session_id'    => $session_id,
            'user_id'       => $user_id,
            'client_name'   => $client_name,
            'message_id'    => uniqid('chat_'),
            'message_text'  => $message ?: '[Image]',
            'message_from'  => 'user',
            'message_type'  => !empty($images) ? 'image' : 'text',
            'attachments'   => $images,
            'platform_type' => $platform_type,
        ]);

        // ── Smart Gateway: stream from server if enabled ──
        if ( defined( 'BIZCITY_SMART_GATEWAY_ENABLED' ) && BIZCITY_SMART_GATEWAY_ENABLED && class_exists( 'BizCity_Smart_Gateway' ) ) {
            $this->ensure_stream_headers();
            $this->emit_trace( 'gateway_entry', [
                'branch'        => 'smart_gateway',
                'platform_type' => $platform_type,
                'session_id'    => $session_id,
                'character_id'  => (int) $character_id,
                'has_images'    => ! empty( $images ),
                'message_len'   => mb_strlen( (string) $message, 'UTF-8' ),
                'tool_goal'     => $tool_goal,
                'provider_hint' => $provider_hint,
                'routing_mode'  => $routing_mode,
            ] );
            
            // ── Emit KCI application trace ──
            $this->emit_trace( 'kci_ratio_applied', [
                'kci_ratio'     => (int) $this->current_kci_ratio,
                'exec_ratio'    => 100 - (int) $this->current_kci_ratio,
                'platform_type' => $platform_type,
                'session_id'    => $session_id,
            ] );

            $smart  = new BizCity_Smart_Gateway();
            $params = [
                'message'       => $message,
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'character_id'  => $character_id,
                'platform_type' => $platform_type,
                'images'        => $images,
                'kci_ratio'     => $this->current_kci_ratio ?? 80,
                'plugin_slug'   => $plugin_slug,
                'provider_hint' => $provider_hint,
                'routing_mode'  => $routing_mode,
                'tool_goal'     => $tool_goal,
                'tool_name'     => $tool_name,
            ];

            // ── Local Intent Engine: classify locally before sending to server ──
            if ( class_exists( 'BizCity_Intent_Engine' ) ) {
                $this->emit_trace( 'local_intent_start', [
                    'branch'   => 'local_intent',
                    'session'  => $session_id,
                    'platform' => $platform_type,
                    'tool_goal' => $tool_goal,
                    'provider_hint' => $provider_hint,
                ] );

                $channel_map_sg = [ 'WEBCHAT' => 'webchat', 'ADMINCHAT' => 'adminchat' ];
                $intent_result  = BizCity_Intent_Engine::instance()->process( [
                    'message'       => $message,
                    'session_id'    => $session_id,
                    'user_id'       => $user_id,
                    'channel'       => $channel_map_sg[ $platform_type ] ?? 'webchat',
                    'character_id'  => $character_id,
                    'images'        => $images,
                    'plugin_slug'   => $plugin_slug,
                    'provider_hint' => $provider_hint,
                    'routing_mode'  => $routing_mode,
                    'tool_goal'     => $tool_goal,
                    'tool_name'     => $tool_name,
                ] );
                $intent_action = $intent_result['action'] ?? 'passthrough';
                $slot_progress = $this->summarize_intent_slot_progress( $intent_result );
                
                // Emit mode classification trace
                $mode_classifier = $intent_result['meta']['mode'] ?? '';
                $confidence = $intent_result['meta']['confidence'] ?? 0;
                $objectives = $intent_result['meta']['objectives'] ?? [];
                if ( is_string( $objectives ) ) {
                    $objectives = json_decode( $objectives, true ) ?: [];
                }
                $this->emit_trace( 'mode_classified', [
                    'mode'       => $mode_classifier,
                    'confidence' => (float) $confidence,
                    'objectives_count' => count( (array) $objectives ),
                    'primary_objective' => $objectives[0] ?? '',
                    'multi_goal_detected' => count( (array) $objectives ) > 1,
                ] );

                $this->emit_trace( 'objectives_detected', [
                    'objectives_count'  => count( (array) $objectives ),
                    'primary_objective' => $objectives[0] ?? '',
                    'objectives'        => array_slice( (array) $objectives, 0, 5 ),
                ] );

                $this->emit_trace( 'multi_goal_decision', [
                    'decision'         => count( (array) $objectives ) > 1 ? 'multi' : 'single',
                    'objectives_count' => count( (array) $objectives ),
                ] );

                $this->emit_trace( 'slot_progress', [
                    'slot_progress' => $slot_progress,
                ] );

                $this->emit_trace( 'local_intent_result', [
                    'branch'        => 'local_intent',
                    'mode'          => $intent_result['meta']['mode'] ?? '',
                    'intent'        => $intent_result['meta']['intent'] ?? '',
                    'action'        => $intent_action,
                    'goal'          => $intent_result['goal'] ?? '',
                    'status'        => $intent_result['status'] ?? '',
                    'method'        => $intent_result['meta']['method'] ?? '',
                    'slot_progress' => $slot_progress,
                ] );

                // If Intent Engine handled fully (ask_user, call_tool, complete) → stream reply directly
                if ( ! empty( $intent_result['reply'] ) && ! in_array( $intent_action, [ 'passthrough', 'compose_answer' ], true ) ) {
                    $this->send_stream_event( 'engine', [
                        'mode'          => $intent_result['meta']['mode'] ?? '',
                        'intent'        => $intent_result['meta']['intent'] ?? '',
                        'action'        => $intent_action,
                        'goal'          => $intent_result['goal'] ?? '',
                        'status'        => $intent_result['status'] ?? '',
                        'method'        => $intent_result['meta']['method'] ?? '',
                        'slot_progress' => $slot_progress,
                        'via'           => 'local_intent_engine',
                    ] );

                    $this->emit_trace( 'local_intent_terminal', [
                        'branch'        => 'local_intent',
                        'action'        => $intent_action,
                        'goal'          => $intent_result['goal'] ?? '',
                        'status'        => $intent_result['status'] ?? '',
                        'slot_progress' => $slot_progress,
                    ] );

                    $this->send_stream_event( 'chunk', [
                        'delta' => $intent_result['reply'],
                        'full'  => $intent_result['reply'],
                    ] );
                    $this->send_stream_event( 'done', [
                        'message'       => $intent_result['reply'],
                        'provider'      => 'local-intent',
                        'engine_result' => $intent_result,
                    ] );
                    $this->send_stream_close();

                    $this->log_message( [
                        'session_id'    => $session_id,
                        'user_id'       => 0,
                        'client_name'   => 'AI',
                        'message_id'    => uniqid( 'chat_bot_' ),
                        'message_text'  => $intent_result['reply'],
                        'message_from'  => 'bot',
                        'message_type'  => 'text',
                        'platform_type' => $platform_type,
                        'meta'          => [
                            'provider'     => 'local-intent',
                            'character_id' => $character_id,
                            'action'       => $intent_action,
                            'goal'         => $intent_result['goal'] ?? '',
                        ],
                    ] );
                    exit;
                }

                // Passthrough / compose_answer → send client engine result to server
                $params['client_engine_result'] = $this->build_client_engine_result( $intent_result );

                if ( $tool_goal && empty( $params['client_engine_result']['goal'] ) ) {
                    $params['client_engine_result']['goal'] = $tool_goal;
                }

                $this->emit_trace( 'local_intent_handoff', [
                    'branch'        => 'smart_gateway',
                    'reason'        => 'compose_required',
                    'action'        => $intent_action,
                    'goal'          => $intent_result['goal'] ?? '',
                    'status'        => $intent_result['status'] ?? '',
                    'slot_progress' => $slot_progress,
                ] );
            } else {
                $this->emit_trace( 'local_intent_unavailable', [
                    'branch' => 'smart_gateway',
                    'reason' => 'BizCity_Intent_Engine class missing',
                ], 'warn' );
            }

            $this->send_stream_event( 'status', [ 'text' => 'Dang ket noi Smart Gateway...' ] );
            $this->emit_trace( 'smart_gateway_connect', [
                'branch'     => 'smart_gateway',
                'session_id' => $session_id,
                'has_client_engine_result' => ! empty( $params['client_engine_result'] ),
            ] );

            $self = $this;
            $sg_result = $smart->resolve_stream( $params, function ( $delta, $full ) use ( $self ) {
                $self->send_stream_event( 'chunk', [ 'delta' => $delta, 'full' => $full ] );
            }, function ( $event, $payload ) use ( $self ) {
                if ( ! is_array( $payload ) ) {
                    $payload = [ 'value' => $payload ];
                }

                // Add unified branch marker for frontend realtime console.
                if ( ! isset( $payload['branch'] ) ) {
                    $payload['branch'] = 'smart_gateway';
                }

                switch ( $event ) {
                    case 'status':
                    case 'trace':
                    case 'engine':
                    case 'tool_call':
                    case 'error':
                    case 'done_meta':
                        $self->send_stream_event( $event, $payload );
                        break;
                }
            } );

            if ( empty( $sg_result['success'] ) ) {
                $this->emit_trace( 'smart_gateway_error', [
                    'branch' => 'smart_gateway',
                    'error'  => $sg_result['error'] ?? 'unknown',
                    'debug'  => $sg_result['debug'] ?? [],
                ], 'error' );
                error_log( '[chat-gateway-stream] Smart Gateway failed | error=' . ( $sg_result['error'] ?? '(unknown)' ) . ' | debug=' . wp_json_encode( $sg_result['debug'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                $this->send_stream_event( 'error', [
                    'message' => $sg_result['error'] ?? 'Smart Gateway stream failed',
                    'debug'   => $sg_result['debug'] ?? [],
                ] );
                $this->send_stream_close();
                return;
            }

            $bot_reply = $sg_result['response'] ?? '';
            $this->emit_trace( 'smart_gateway_done', [
                'branch'   => 'smart_gateway',
                'provider' => 'smart-gateway',
                'model'    => $sg_result['usage']['model'] ?? '',
                'action'   => $sg_result['engine_result']['action'] ?? '',
                'goal'     => $sg_result['engine_result']['goal'] ?? '',
                'reply_len'=> mb_strlen( (string) $bot_reply, 'UTF-8' ),
            ] );
            $this->send_stream_event( 'done', [
                'message'  => $bot_reply,
                'provider' => 'smart-gateway',
                'model'    => $sg_result['usage']['model'] ?? '',
                'engine_result' => $sg_result['engine_result'] ?? [],
                'focus_profile' => $sg_result['focus_profile'] ?? [],
                'tool_call'     => $sg_result['tool_call'] ?? [],
                'debug'         => $sg_result['debug'] ?? [],
            ] );
            $this->send_stream_close();

            // Log bot reply
            $this->log_message( [
                'session_id'    => $session_id,
                'user_id'       => 0,
                'client_name'   => 'AI',
                'message_id'    => uniqid( 'chat_bot_' ),
                'message_text'  => $bot_reply,
                'message_from'  => 'bot',
                'message_type'  => 'text',
                'platform_type' => $platform_type,
                'meta'          => [
                    'provider'     => 'smart-gateway',
                    'model'        => $sg_result['usage']['model'] ?? '',
                    'character_id' => $character_id,
                    'via'          => 'smart_gateway_stream',
                ],
            ] );

            do_action( 'bizcity_chat_message_processed', [
                'platform_type' => $platform_type,
                'session_id'    => $session_id,
                'character_id'  => $character_id,
                'user_id'       => $user_id,
                'user_message'  => $message,
                'bot_reply'     => $bot_reply,
                'images'        => $images,
                'provider'      => 'smart-gateway',
                'model'         => $sg_result['usage']['model'] ?? '',
            ] );

            exit;
        }

        // ── Prepare LLM messages (context + history + system prompt) ──
        $prepared = $this->prepare_llm_call($character_id, $message, $images, $session_id, $history_json, $user_id, $platform_type);

        if (isset($prepared['error'])) {
            error_log('[chat-gateway-stream] prepare_llm_call error: ' . ($prepared['error']['message'] ?? 'unknown'));
            $this->send_stream_error($prepared['error']['message'] ?? 'Lỗi hệ thống');
            return;
        }

        $openai_messages = $prepared['messages'];
        $character       = $prepared['character'];
        $result_base     = $prepared['result_base'];

        // ── Set SSE headers ──
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('Access-Control-Allow-Origin: *');
        set_time_limit(120);
        ignore_user_abort(false);

        // ── Check streaming availability ──
        $stream_fn = function_exists( 'bizcity_llm_chat_stream' ) ? 'bizcity_llm_chat_stream'
            : ( function_exists( 'bizcity_openrouter_chat_stream' ) ? 'bizcity_openrouter_chat_stream' : null );

        if ( ! $stream_fn ) {
            // Fallback: non-streaming → single chunk
            $reply_data = ($character && !empty($character->model_id))
                ? $this->call_openrouter($character, $openai_messages)
                : $this->call_openai($openai_messages);

            $bot_reply = $reply_data['message'] ?? '';
            $this->send_stream_event('chunk', ['delta' => $bot_reply, 'full' => $bot_reply]);
            $this->send_stream_event('done', [
                'message'  => $bot_reply,
                'provider' => $reply_data['provider'] ?? '',
                'model'    => $reply_data['model'] ?? '',
            ]);
            $this->send_stream_close();

            // Log bot reply
            $this->log_message([
                'session_id'    => $session_id,
                'user_id'       => 0,
                'client_name'   => $result_base['character_name'],
                'message_id'    => uniqid('chat_bot_'),
                'message_text'  => $bot_reply,
                'message_from'  => 'bot',
                'message_type'  => 'text',
                'platform_type' => $platform_type,
                'meta'          => [
                    'provider'     => $reply_data['provider'] ?? '',
                    'model'        => $reply_data['model'] ?? '',
                    'character_id' => $character_id,
                    'via'          => 'sse_fallback',
                ],
            ]);

            // Fire action for automation triggers
            do_action('bizcity_chat_message_processed', [
                'platform_type' => $platform_type,
                'session_id'    => $session_id,
                'character_id'  => $character_id,
                'user_id'       => $user_id,
                'user_message'  => $message,
                'bot_reply'     => $bot_reply,
                'images'        => $images,
                'provider'      => $reply_data['provider'] ?? '',
                'model'         => $reply_data['model'] ?? '',
            ]);

            exit;
        }

        // ── Stream via LLM Gateway ──
        error_log('[chat-gateway-stream] Starting SSE | platform=' . $platform_type . ' | char=' . $character_id . ' | msgs=' . count($openai_messages));
        $model_options = [
            'purpose'     => 'chat',
            'max_tokens'  => $this->max_tokens_override ?: 3000,
            'temperature' => ($character && isset($character->creativity_level))
                ? floatval($character->creativity_level)
                : 0.7,
        ];
        if ($character && !empty($character->model_id)) {
            $model_options['model'] = $character->model_id;
        }

        $self = $this;
        $stream_result = $stream_fn(
            $openai_messages,
            $model_options,
            function ($delta, $full_text) use ($self) {
                $self->send_stream_event('chunk', [
                    'delta' => $delta,
                    'full'  => $full_text,
                ]);
            }
        );

        $bot_reply = $stream_result['message'] ?? '';
        if (empty($stream_result['success'])) {
            error_log('[chat-gateway-stream] Stream error: ' . ($stream_result['error'] ?? 'unknown'));
        } else {
            error_log('[chat-gateway-stream] Stream OK | reply_len=' . strlen($bot_reply) . ' | model=' . ($stream_result['model'] ?? ''));
        }

        // ── Send done event ──
        $this->send_stream_event('done', [
            'message'  => $bot_reply,
            'provider' => $stream_result['provider'] ?? 'openrouter',
            'model'    => $stream_result['model'] ?? '',
        ]);
        $this->send_stream_close();

        // ── Log bot reply ──
        $this->log_message([
            'session_id'    => $session_id,
            'user_id'       => 0,
            'client_name'   => $result_base['character_name'],
            'message_id'    => uniqid('chat_bot_'),
            'message_text'  => $bot_reply,
            'message_from'  => 'bot',
            'message_type'  => 'text',
            'platform_type' => $platform_type,
            'meta'          => [
                'provider'     => $stream_result['provider'] ?? 'openrouter',
                'model'        => $stream_result['model'] ?? '',
                'character_id' => $character_id,
                'via'          => 'sse_stream',
            ],
        ]);

        // ── Fire action for automation triggers (same as ajax_send) ──
        do_action('bizcity_chat_message_processed', [
            'platform_type' => $platform_type,
            'session_id'    => $session_id,
            'character_id'  => $character_id,
            'user_id'       => $user_id,
            'user_message'  => $message,
            'bot_reply'     => $bot_reply,
            'images'        => $images,
            'provider'      => $stream_result['provider'] ?? 'openrouter',
            'model'         => $stream_result['model'] ?? '',
        ]);

        exit;
    }

    /* ─── SSE helpers ─── */
    private function ensure_stream_headers() {
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        if ( ! headers_sent() ) {
            header( 'Content-Type: text/event-stream; charset=UTF-8' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'Connection: keep-alive' );
            header( 'X-Accel-Buffering: no' );
            header( 'Access-Control-Allow-Origin: *' );
        } else {
            error_log( '[chat-gateway-stream] Headers already sent before SSE stream.' );
        }

        set_time_limit( 120 );
        ignore_user_abort( false );
    }

    private function send_stream_event($event, $data) {
        echo "event: {$event}\n";
        echo 'data: ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    /**
     * Emit unified trace marker for realtime frontend console + debugging.
     */
    private function emit_trace( string $stage, array $data = [], string $level = 'info' ): void {
        $payload = [
            'stage' => $stage,
            'level' => $level,
            'ts'    => gmdate( 'c' ),
            'data'  => $data,
        ];
        $this->send_stream_event( 'trace', $payload );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[CHAT-GATEWAY-TRACE] ' . $stage . ' | ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        }
    }

    /**
     * Normalize local intent result into slot/HIL progress for trace + UI console.
     */
    private function summarize_intent_slot_progress( array $intent_result ): array {
        $meta          = $intent_result['meta'] ?? [];
        $slot_analysis = is_array( $meta['slot_analysis'] ?? null ) ? $meta['slot_analysis'] : [];

        $filled = $slot_analysis['filled_slots'] ?? [];
        $missing = $slot_analysis['missing_slots'] ?? ( $meta['missing_fields'] ?? [] );
        $fill_ratio = isset( $slot_analysis['fill_ratio'] ) ? (float) $slot_analysis['fill_ratio'] : null;

        return [
            'filled_slots'   => is_array( $filled ) ? array_values( $filled ) : [],
            'missing_slots'  => is_array( $missing ) ? array_values( $missing ) : [],
            'fill_ratio'     => $fill_ratio,
            'status'         => $slot_analysis['status'] ?? ( $intent_result['status'] ?? '' ),
            'total_required' => isset( $slot_analysis['total_required'] ) ? (int) $slot_analysis['total_required'] : null,
        ];
    }

    /**
     * Build normalized client_engine_result for Smart Gateway passthrough.
     */
    private function build_client_engine_result( array $intent_result ): array {
        return [
            'mode'            => $intent_result['meta']['mode'] ?? '',
            'intent'          => $intent_result['meta']['intent'] ?? '',
            'action'          => $intent_result['action'] ?? 'passthrough',
            'goal'            => $intent_result['goal'] ?? '',
            'goal_label'      => $intent_result['goal_label'] ?? '',
            'slots'           => $intent_result['slots'] ?? [],
            'conversation_id' => $intent_result['conversation_id'] ?? '',
            'status'          => $intent_result['status'] ?? '',
            'method'          => $intent_result['meta']['method'] ?? '',
            'missing_fields'  => $intent_result['meta']['missing_fields'] ?? [],
            'slot_analysis'   => $intent_result['meta']['slot_analysis'] ?? [],
            'slot_progress'   => $this->summarize_intent_slot_progress( $intent_result ),
        ];
    }

    private function send_stream_close() {
        echo "event: close\ndata: {}\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }
    private function send_stream_error($msg) {
        $this->ensure_stream_headers();
        $this->send_stream_event('error', ['message' => $msg]);
        $this->send_stream_close();
        exit;
    }

    /* ================================================================
     * LLM: Call OpenAI
     *
     * Routes through bizcity_llm_chat() which respects the configured
     * gateway mode and always logs through the LLM Router.
     * ================================================================ */
    private function call_openai($messages) {
        $max_tokens = $this->max_tokens_override ?: 3000;

        if ( function_exists( 'bizcity_llm_chat' ) ) {
            $result = bizcity_llm_chat( $messages, [
                'purpose'    => 'chat',
                'max_tokens' => $max_tokens,
            ] );
            if ( ! empty( $result['success'] ) ) {
                return $result;
            }
            // Log but don't silently swallow the error
            error_log( '[ChatGateway] bizcity_llm_chat error: ' . ( $result['error'] ?? 'unknown' ) );
            return [
                'message'  => $result['message'] ?? ( $result['error'] ?? 'Lỗi kết nối AI Gateway. Vui lòng thử lại.' ),
                'provider' => $result['provider'] ?? 'gateway',
                'model'    => $result['model'] ?? '',
                'usage'    => $result['usage'] ?? [],
            ];
        }

        return [ 'message' => 'Hệ thống chưa cấu hình AI Gateway.', 'provider' => 'none' ];
    }

    /* ================================================================
     * LLM: Call OpenRouter
     *
     * Routes through bizcity_llm_chat() which respects the configured
     * gateway mode and always logs through the LLM Router.
     * Falls back to call_openai() only when the gateway is unavailable.
     * ================================================================ */
    private function call_openrouter($character, $messages) {
        $max_tokens = $this->max_tokens_override ?: 3000;

        if ( function_exists( 'bizcity_llm_chat' ) ) {
            $result = bizcity_llm_chat( $messages, [
                'model'       => $character->model_id ?? '',
                'temperature' => floatval( $character->creativity_level ?? 0.7 ),
                'max_tokens'  => $max_tokens,
                'purpose'     => 'chat',
            ] );
            if ( ! empty( $result['success'] ) ) {
                return $result;
            }
            // Gateway error → don't silently fallback to direct calls
            error_log( '[ChatGateway] bizcity_llm_chat error: ' . ( $result['error'] ?? 'unknown' ) );
            return [
                'message'  => $result['message'] ?? ( $result['error'] ?? 'Lỗi kết nối AI Gateway. Vui lòng thử lại.' ),
                'provider' => $result['provider'] ?? 'gateway',
                'model'    => $result['model'] ?? ( $character->model_id ?? '' ),
                'usage'    => $result['usage'] ?? [],
            ];
        }

        // bizcity_llm_chat() not available → fallback to call_openai
        return $this->call_openai( $messages );
    }

    /* ================================================================
     * Helpers
     * ================================================================ */

    /**
     * Detect platform type from request context
     */
    private function detect_platform_type() {
        // Explicit from POST
        if (!empty($_POST['platform_type'])) {
            $pt = strtoupper(sanitize_text_field($_POST['platform_type']));
            if (in_array($pt, ['ADMINCHAT', 'WEBCHAT'])) {
                return $pt;
            }
        }

        // Infer from AJAX action name
        $action = $_POST['action'] ?? '';
        if (strpos($action, 'admin_chat') !== false) {
            return 'ADMINCHAT';
        }

        // Infer from context: admin request = ADMINCHAT
        if (is_admin() || (defined('DOING_AJAX') && current_user_can('edit_posts') && strpos($action, 'bizcity_chat_') === 0)) {
            // For the new unified endpoint, check if there's a platform_type or default by login state
            if (!empty($_POST['platform_type'])) {
                return strtoupper(sanitize_text_field($_POST['platform_type']));
            }
            // Default for logged-in admin using new endpoint
            if (current_user_can('edit_posts')) {
                return 'ADMINCHAT';
            }
        }

        return 'WEBCHAT';
    }

    /**
     * Verify nonce — accepts multiple nonce field names for backward compat
     */
    private function verify_nonce() {
        $nonce = $_POST['nonce'] ?? $_POST['_wpnonce'] ?? '';
        if (!$nonce) return false;

        // Accept any of the known nonce actions
        return wp_verify_nonce($nonce, 'bizcity_chat')
            || wp_verify_nonce($nonce, 'bizcity_admin_chat')
            || wp_verify_nonce($nonce, 'bizcity_webchat');
    }

    /**
     * Get session ID
     */
    private function get_session_id($platform_type = 'WEBCHAT') {
        if ($platform_type === 'ADMINCHAT') {
            return 'adminchat_' . get_current_blog_id() . '_' . get_current_user_id();
        }
        // WEBCHAT: from cookie or generate
        $session_id = $_COOKIE['bizcity_session_id'] ?? '';
        if (empty($session_id)) {
            $session_id = 'sess_' . wp_generate_uuid4();
        }
        return $session_id;
    }

    /**
     * Get default character ID
     */
    private function get_default_character_id() {
        $cid = intval(get_option('bizcity_webchat_default_character_id', 0));

        if (!$cid) {
            $opts = get_option('pmfacebook_options', []);
            $cid  = isset($opts['default_character_id']) ? intval($opts['default_character_id']) : 0;
        }

        if (!$cid && class_exists('BizCity_Knowledge_Database')) {
            $db   = BizCity_Knowledge_Database::instance();
            $chars = $db->get_characters(['status' => 'active', 'limit' => 1]);
            if (!empty($chars)) {
                $cid = $chars[0]->id;
            }
        }

        return $cid;
    }

    /**
     * Log message to bizcity_webchat_messages (with plugin_slug support)
     */
    private function log_message($data) {
        if (class_exists('BizCity_WebChat_Database')) {
            BizCity_WebChat_Database::instance()->log_message($data);
            return;
        }

        // Fallback: direct insert
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }

        $wpdb->insert($table, [
            'conversation_id' => 0,
            'session_id'      => $data['session_id'] ?? '',
            'user_id'         => $data['user_id'] ?? 0,
            'client_name'     => $data['client_name'] ?? '',
            'message_id'      => $data['message_id'] ?? '',
            'message_text'    => $data['message_text'] ?? '',
            'message_from'    => $data['message_from'] ?? 'user',
            'message_type'    => $data['message_type'] ?? 'text',
            'plugin_slug'     => $data['plugin_slug'] ?? '',  // @ mention plugin routing
            'attachments'     => is_array($data['attachments'] ?? null) ? wp_json_encode($data['attachments']) : '',
            'platform_type'   => $data['platform_type'] ?? 'WEBCHAT',
            'meta'            => isset($data['meta']) ? wp_json_encode($data['meta']) : '',
        ]);

        // Fire hook for global logger (bizcity-bot-agent)
        do_action('bizcity_webchat_message_saved', array_merge($data, [
            'blog_id' => get_current_blog_id(),
        ]));
    }

    /**
     * Get conversation history
     */
    private function get_history($session_id, $platform_type = 'ADMINCHAT', $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE session_id = %s AND platform_type = %s
             ORDER BY id ASC
             LIMIT %d",
            $session_id,
            $platform_type,
            $limit
        ));

        $history = [];
        foreach ($rows as $row) {
            $meta = $row->meta ? json_decode($row->meta, true) : [];
            $attachments = $row->attachments ? json_decode($row->attachments, true) : [];

            $images = [];
            if (is_array($attachments)) {
                foreach ($attachments as $att) {
                    if (is_string($att) && $att !== '') {
                        $images[] = $att;
                    } elseif (is_array($att)) {
                        $url = $att['url'] ?? $att['data'] ?? '';
                        if ($url) $images[] = $url;
                    }
                }
            }

            $history[] = [
                'id'          => $row->id,
                'message_id'  => $row->message_id,
                'msg'         => $row->message_text,
                'from'        => $row->message_from,
                'client_name' => $row->client_name,
                'attachments' => $attachments,
                'images'      => $images,
                'time'        => $row->created_at,
                'meta'        => $meta,
            ];
        }

        return $history;
    }
}
