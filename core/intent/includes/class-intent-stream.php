<?php
/**
 * BizCity Intent — Stream Adapter
 *
 * Two output adapters consuming the same engine:
 *
 *   1. SSE Adapter  (webchat / admin dashboard)
 *      → Streams chunks directly to browser via Server-Sent Events.
 *      → Endpoint: wp_ajax_bizcity_chat_stream / wp_ajax_nopriv_bizcity_chat_stream
 *
 *   2. Batch Adapter  (Zalo / Telegram / Facebook hooks)
 *      → Accumulates full response internally, then sends 1–2 messages via channel API.
 *      → Called programmatically from hook handlers.
 *
 * Both use BizCity_OpenRouter::chat_stream() under the hood.
 *
 * @package BizCity_Intent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Stream {

    /** @var self|null */
    private static $instance = null;

    /** @var array|null Tool suggestion data from build_llm_messages for SSE done event */
    private $_suggest_tool_data = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // SSE endpoint for webchat / admin
        add_action( 'wp_ajax_bizcity_chat_stream',        [ $this, 'handle_sse' ] );
        add_action( 'wp_ajax_nopriv_bizcity_chat_stream', [ $this, 'handle_sse' ] );
    }

    /* ================================================================
     *  SSE Adapter — Stream to browser
     * ================================================================ */

    /**
     * Handle SSE (Server-Sent Events) stream request.
     *
     * Client opens:
     *   const source = new EventSource(ajaxurl + '?action=bizcity_chat_stream&...')
     *   // or POST via fetch with ReadableStream
     *
     * Server sends:
     *   data: {"type":"chunk","delta":"text...","full":"accumulated text..."}\n\n
     *   data: {"type":"done","message":"full text","meta":{...}}\n\n
     */
    public function handle_sse() {
        // Disable output buffering for real-time streaming
        $this->prepare_stream_headers();

        // ── Hook: send thinking status to client via SSE ──
        $self = $this;
        $already_streamed = false;
        add_action( 'bizcity_intent_status', function ( $status_text ) use ( $self ) {
            $self->send_sse_event( 'status', [
                'text' => $status_text,
            ] );
        }, 10, 1 );

        // ── Hook: allow pipelines/tools to stream chunks directly via SSE ──
        // Plugins call: do_action('bizcity_intent_stream_chunk', $delta, $full_text)
        // when using bizcity_openrouter_chat_stream() for real-time token delivery.
        $streamed_full_text = '';
        add_action( 'bizcity_intent_stream_chunk', function ( $delta, $full_text ) use ( $self, &$already_streamed, &$streamed_full_text ) {
            $already_streamed = true;
            $streamed_full_text = $full_text;
            $self->send_sse_event( 'chunk', [
                'delta' => $delta,
                'full'  => $full_text,
            ] );
        }, 10, 2 );

        // ── Hook: forward pipeline logger entries to client via SSE ──
        add_action( 'bizcity_intent_pipeline_log', function ( $step, $data, $level, $elapsed_ms ) use ( $self ) {
            // Skip noisy trace_begin/trace_end and chunk events to reduce bandwidth
            if ( in_array( $step, [ 'trace_begin' ], true ) ) return;
            $self->send_sse_event( 'log', [
                'step'    => $step,
                'level'   => $level,
                'ms'      => $elapsed_ms,
                'data'    => $data,
            ] );
        }, 10, 4 );

        // Parse request
        $message      = sanitize_textarea_field( $_REQUEST['message'] ?? '' );
        $character_id = intval( $_REQUEST['character_id'] ?? 0 );
        $session_id   = sanitize_text_field( $_REQUEST['session_id'] ?? '' );

        // ── Concurrent request lock (per session) ──
        // Prevent processing duplicate requests when user double-clicks
        // or sends rapidly. Uses a short transient lock (15s) keyed on
        // session + message hash. If the same message arrives while
        // the previous one is still processing, reject it immediately.
        if ( $session_id && $message ) {
            $lock_key = 'bizc_stream_lock_' . md5( $session_id . '|' . $message );
            if ( get_transient( $lock_key ) ) {
                $this->send_sse_event( 'error', [ 'message' => 'Tin nhắn đang được xử lý, vui lòng đợi.' ] );
                $this->send_sse_done();
                exit;
            }
            set_transient( $lock_key, true, 15 );
        }

        // ── API key guard — bail early with helpful message if not configured ──
        if ( class_exists( 'BizCity_LLM_Client' ) && ! BizCity_LLM_Client::instance()->is_ready() ) {
            $settings_url = admin_url( 'admin.php?page=bizcity-llm' );
            $create_url   = 'https://bizcity.vn/my-account/';
            $message_text = "⚠️ **Chưa kết nối API BizCity**\n\n"
                . "Bot chưa có API key để xử lý tin nhắn của bạn.\n\n"
                . "**Cách kết nối:**\n"
                . "1. Truy cập [bizcity.vn/my-account/]({$create_url}) để lấy API key miễn phí\n"
                . "2. Dán key vào trang [LLM Settings]({$settings_url})\n\n"
                . "_Sau khi lưu, bot sẽ hoạt động ngay lập tức._";

            $this->send_sse_event( 'done', [
                'message'         => $message_text,
                'conversation_id' => '',
                'action'          => 'passthrough',
                'provider'        => '',
                'goal'            => '',
                'goal_label'      => '',
                'focus_mode'      => '',
                'meta'            => [ 'no_api_key' => true ],
            ] );
            exit;
        }

        $images       = [];

        if ( ! empty( $_REQUEST['images'] ) ) {
            $raw_images = json_decode( stripslashes( $_REQUEST['images'] ?? '[]' ), true ) ?: [];
            // Convert base64 images to Media Library URLs
            if ( function_exists( 'bizcity_convert_images_to_media_urls' ) ) {
                $images = bizcity_convert_images_to_media_urls( $raw_images );
            } else {
                $images = $raw_images;
            }
        } elseif ( ! empty( $_REQUEST['image_data'] ) ) {
            // Webchat JS sends a single base64 data-URL via 'image_data'.
            // Wrap it in an array and run through the same conversion pipeline.
            $raw_images = [ wp_unslash( $_REQUEST['image_data'] ) ];
            if ( function_exists( 'bizcity_convert_images_to_media_urls' ) ) {
                $images = bizcity_convert_images_to_media_urls( $raw_images );
            } else {
                $images = $raw_images;
            }
        }

        $platform_type = sanitize_text_field( $_REQUEST['platform_type'] ?? 'WEBCHAT' );
        $user_id       = get_current_user_id();
        $provider_hint = sanitize_text_field( $_REQUEST['provider_hint'] ?? '' );
        // Resolve market slug → provider ID (e.g. 'bizcity-tarot' → 'tarot')
        if ( $provider_hint && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $provider_hint = BizCity_Intent_Provider_Registry::instance()->resolve_slug( $provider_hint );
        }
        $tool_goal     = sanitize_text_field( $_REQUEST['tool_goal'] ?? '' ); // Slash command: direct tool goal

        // ── Logic 2: Parse /tool_name from message text ──
        // If message starts with /some_slug, extract the tool goal from it.
        // This is the universal mechanism — works across all channels (Telegram, Zalo, REST, etc.)
        // and serves as self-documenting history. FormData tool_goal takes precedence.
        if ( ! $tool_goal && preg_match( '/^\/([a-z0-9_]+)(?:\s+(.*))?$/si', $message, $slash_match ) ) {
            $slash_slug    = strtolower( $slash_match[1] );
            $slash_message = trim( $slash_match[2] ?? '' );

            // L2-a: Primary — check bizcity_tool_registry DB (handles tool_name ≠ goal)
            global $wpdb;
            $reg_table = $wpdb->prefix . 'bizcity_tool_registry';
            $reg_row   = $wpdb->get_row( $wpdb->prepare(
                "SELECT goal FROM {$reg_table} WHERE active = 1 AND ( tool_name = %s OR goal = %s ) LIMIT 1",
                $slash_slug, $slash_slug
            ), ARRAY_A );

            if ( $reg_row && ! empty( $reg_row['goal'] ) ) {
                $tool_goal = $reg_row['goal'];
                $message   = $slash_message;
                error_log( '[bizcity-intent-stream] Logic 2: /slash → tool_goal=' . $tool_goal . ' (registry, slug=' . $slash_slug . ')' );
            }
            // L2-b: Fallback — check goal_patterns (pattern-only goals without DB entry)
            elseif ( class_exists( 'BizCity_Intent_Router' ) ) {
                foreach ( BizCity_Intent_Router::instance()->get_goal_patterns() as $cfg ) {
                    if ( ( $cfg['goal'] ?? '' ) === $slash_slug ) {
                        $tool_goal = $cfg['goal'];
                        $message   = $slash_message;
                        error_log( '[bizcity-intent-stream] Logic 2: /slash → tool_goal=' . $tool_goal . ' (patterns)' );
                        break;
                    }
                }
            }
            // If neither matched, leave message as-is (user typed /hello casually)
        }

        // Nonce check for admin channels — accept all known nonce actions
        if ( in_array( $platform_type, [ 'ADMINCHAT', 'ADMIN' ], true ) ) {
            $nonce = $_REQUEST['_wpnonce'] ?? $_REQUEST['nonce'] ?? '';
            $valid = wp_verify_nonce( $nonce, 'bizcity_webchat' )
                  || wp_verify_nonce( $nonce, 'bizcity_admin_chat' )
                  || wp_verify_nonce( $nonce, 'bizcity_chat' );
            if ( ! $valid ) {
                error_log( '[bizcity-intent-stream] Invalid nonce for ' . $platform_type . ' | nonce=' . $nonce . ' | user=' . $user_id );
                $this->send_sse_event( 'error', [ 'message' => 'Invalid nonce' ] );
                $this->send_sse_done();
                exit;
            }
        }

        if ( empty( $message ) && empty( $images ) ) {
            $this->send_sse_event( 'error', [ 'message' => 'Tin nhắn trống' ] );
            $this->send_sse_done();
            exit;
        }

        // ── Log user message to webchat_messages (unified history) ──
        // Skip if BCN (Notebook) already saved the user message to avoid duplicates.
        $bcn_user_msg_id = absint( $_REQUEST['_bcn_user_msg_id'] ?? 0 );
        if ( ! $bcn_user_msg_id ) {
            $user        = wp_get_current_user();
            $client_name = $user->ID ? ( $user->display_name ?: $user->user_login ) : 'Guest';
            $prompt_message_id = uniqid( 'intent_' );
            $this->log_webchat_message( [
                'session_id'    => $session_id,
                'user_id'       => $user_id,
                'client_name'   => $client_name,
                'message_id'    => $prompt_message_id,
                'message_text'  => $message ?: '[Image]',
                'message_from'  => 'user',
                'message_type'  => ! empty( $images ) ? 'image' : 'text',
                'attachments'   => $images,
                'platform_type' => $platform_type,
            ] );
        }

        // ── Early workflow trigger: fire BEFORE intent processing ──
        // If an automation workflow handles this message, skip intent engine entirely.
        // WEBCHAT: skip workflow triggers — WEBCHAT must always go through knowledge pipeline.
        if ( function_exists( 'bizcity_gateway_fire_trigger' ) && $platform_type !== 'WEBCHAT' ) {
            $GLOBALS['waic_twf_process_flow_handled'] = false;
            bizcity_gateway_fire_trigger( [
                'platform'     => strtolower( $platform_type ),
                'session_id'   => $session_id,
                'user_id'      => (string) $user_id,
                'text'         => $message,
                'message_text' => $message,
                'message_id'   => $prompt_message_id,
                'display_name' => $client_name,
                'client_name'  => $client_name,
                'image_url'    => ! empty( $images[0] ) ? ( is_string( $images[0] ) ? $images[0] : ( $images[0]['url'] ?? '' ) ) : '',
                'attachments'  => $images,
            ] );
            if ( ! empty( $GLOBALS['waic_twf_process_flow_handled'] ) ) {
                error_log( '[bizcity-intent-stream] Workflow handled message — skipping intent engine' );
                // Use the streamed text if tool already streamed via SSE chunks
                $done_msg = $already_streamed && $streamed_full_text !== ''
                    ? $streamed_full_text
                    : '✅ Đã xử lý bởi Workflow Automation.';
                $this->send_sse_event( 'done', [
                    'message'         => $done_msg,
                    'conversation_id' => '',
                    'action'          => 'workflow',
                    'focus_mode'      => 'none',
                ] );
                $this->send_sse_done();
                exit;
            }
        }

        // ── Pre-process: check if executor bridge needs to intercept ──
        // Preflight + Planner plugins removed — only executor bridge may intercept
        // for async workflow tools (video, SEO multi-step, etc.)
        $pre_ctx = [
            'message'       => $message,
            'session_id'    => $session_id,
            'user_id'       => $user_id,
            'platform_type' => $platform_type,
            'images'        => $images,
            'character_id'  => $character_id,
        ];
        $pre_response = null;

        // Executor partial plan (mirrors bizcity_chat_pre_ai_response @3)
        if ( class_exists( 'BizCity_Intent_Bridge' ) ) {
            $pre_response = BizCity_Intent_Bridge::instance()->intercept_executor_reply( $pre_response, $pre_ctx );
        }

        if ( is_array( $pre_response ) && ! empty( $pre_response['message'] ) ) {
            error_log( '[bizcity-intent-stream] Pre-process intercepted: ' . mb_substr( $pre_response['message'], 0, 80 ) );
            $this->send_sse_event( 'chunk', [
                'delta' => $pre_response['message'],
                'full'  => $pre_response['message'],
            ] );
            $this->send_sse_event( 'done', [
                'message'         => $pre_response['message'],
                'conversation_id' => '',
                'action'          => 'complete',
                'focus_mode'      => 'none',
            ] );
            exit;
        }

        // ── Capability query: "bạn có thể làm gì?" → list active tools ──
        if ( $this->is_capability_query( $message ) ) {
            $cap_reply = $this->build_capability_response();
            $bot_db_id = $this->log_webchat_message( [
                'session_id'    => $session_id,
                'user_id'       => 0,
                'client_name'   => 'AI Assistant',
                'message_id'    => uniqid( 'intent_caps_' ),
                'message_text'  => $cap_reply,
                'message_from'  => 'bot',
                'message_type'  => 'text',
                'platform_type' => $platform_type,
            ] );
            $this->send_sse_event( 'chunk', [
                'delta' => $cap_reply,
                'full'  => $cap_reply,
            ] );
            $this->send_sse_event( 'done', [
                'message'        => $cap_reply,
                'conversation_id' => '',
                'action'         => 'reply',
                'focus_mode'     => 'none',
                'bot_message_id' => $bot_db_id,
            ] );
            exit;
        }

        // ── Process through Intent Engine (get context + slots) ──
        // Send keepalive comment to prevent proxy from closing idle SSE connection
        // during the potentially slow intent classification call.
        echo ": keepalive\n\n";
        if ( ob_get_level() > 0 ) { ob_flush(); }
        flush();

        $intent_conv_id_hint = sanitize_text_field( $_REQUEST['intent_conversation_id'] ?? '' );
        $engine_result = bizcity_intent_process( [
            'message'                 => $message,
            'session_id'              => $session_id,
            'user_id'                 => $user_id,
            'channel'                 => $this->platform_to_channel( $platform_type ),
            'character_id'            => $character_id,
            'images'                  => $images,
            'message_id'              => $prompt_message_id,
            'provider_hint'           => $provider_hint,
            'tool_goal'               => $tool_goal, // Slash command: skip classification, use this goal directly
            'intent_conversation_id'  => $intent_conv_id_hint, // Frontend hint: active intent conversation
        ] );

        // ═══ EXTRACT UNIFIED TRACKING FIELDS FROM ENGINE RESULT ═══
        // These are propagated to every SSE done event + log_webchat_message call
        // so the frontend can display plugin badges and the DB has full traceability.
        $intent_conv_id = $engine_result['conversation_id'] ?? '';
        $intent_goal    = $engine_result['goal'] ?? '';
        $intent_label   = $engine_result['goal_label'] ?? '';

        // Resolve plugin_slug: knowledge pipeline meta → engine goal → provider_hint
        $intent_plugin_slug = '';
        if ( ! empty( $engine_result['meta']['pipeline']['provider'] ) ) {
            $intent_plugin_slug = $engine_result['meta']['pipeline']['provider'];
        } elseif ( ! empty( $engine_result['meta']['provider_id'] ) ) {
            $intent_plugin_slug = $engine_result['meta']['provider_id'];
        } elseif ( $intent_goal ) {
            // Map goal → provider: ask registry for the owning plugin
            if ( class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
                $owner = BizCity_Intent_Provider_Registry::instance()->get_provider_for_goal( $intent_goal );
                if ( $owner ) {
                    $intent_plugin_slug = $owner->get_id();
                }
            }
        }
        if ( ! $intent_plugin_slug && $provider_hint ) {
            $intent_plugin_slug = $provider_hint;
        }

        // ═══ RETROACTIVELY UPDATE USER MESSAGE with tracking fields ═══
        // The user message was logged BEFORE engine processing (L121).
        // Now that we have intent_conversation_id + plugin_slug, stamp the row
        // so both user AND bot messages are scoped to the same intent conversation.
        $intent_tool_name = sanitize_text_field( $_REQUEST['tool_name'] ?? '' );
        if ( ! $intent_tool_name && ! empty( $engine_result['meta']['tool_name'] ) ) {
            $intent_tool_name = $engine_result['meta']['tool_name'];
        }
        if ( $intent_conv_id || $intent_plugin_slug || $intent_tool_name ) {
            if ( class_exists( 'BizCity_WebChat_Database' ) ) {
                BizCity_WebChat_Database::instance()->update_message_tracking( $prompt_message_id, [
                    'intent_conversation_id' => $intent_conv_id,
                    'plugin_slug'            => $intent_plugin_slug,
                    'tool_name'              => $intent_tool_name,
                ] );
            }
        }

        // ═══ COMPUTE FOCUS MODE for frontend plugin context lifecycle ═══
        // 'active'    → inside HIL loop (ask_user) — enter/keep plugin focus banner
        // 'completed' → goal finished (complete) — auto-exit plugin focus banner
        // 'none'      → no goal context — no focus UI
        $engine_action = $engine_result['action'] ?? '';
        if ( $engine_action === 'ask_user' ) {
            $focus_mode = 'active';
        } elseif ( $engine_action === 'complete' ) {
            $focus_mode = 'completed';
        } elseif ( ! empty( $intent_goal ) && ! empty( $intent_plugin_slug ) ) {
            $focus_mode = 'active';
        } else {
            $focus_mode = 'none';
        }

        // If engine handled it directly (ask_user only → send as-is)
        if ( ( $engine_result['action'] ?? '' ) === 'ask_user' ) {
            // Log bot reply FIRST so syncLastMsgId() picks up the DB id
            // before the frontend starts polling (prevents duplicate display).
            $bot_db_id = $this->log_webchat_message( [
                'session_id'              => $session_id,
                'user_id'                 => 0,
                'client_name'             => 'AI Assistant',
                'message_id'              => uniqid( 'intent_bot_' ),
                'message_text'            => $engine_result['reply'],
                'message_from'            => 'bot',
                'message_type'            => 'text',
                'platform_type'           => $platform_type,
                'plugin_slug'             => $intent_plugin_slug,
                'tool_name'               => $intent_tool_name,
                'intent_conversation_id'  => $intent_conv_id,
                'meta'                    => [
                    'character_id'           => $character_id,
                    'via'                    => 'intent_ask_user',
                    'intent_conversation_id' => $intent_conv_id,
                    'goal'                   => $intent_goal,
                    'plugin_slug'            => $intent_plugin_slug,
                ],
            ] );

            $this->send_sse_event( 'chunk', [
                'delta' => $engine_result['reply'],
                'full'  => $engine_result['reply'],
            ] );
            $this->send_sse_event( 'done', [
                'message'          => $engine_result['reply'],
                'conversation_id'  => $intent_conv_id,
                'action'           => $engine_result['action'] ?? '',
                'goal'             => $intent_goal,
                'goal_label'       => $intent_label,
                'plugin_slug'      => $intent_plugin_slug,
                'tool_name'        => $intent_tool_name,
                'bot_message_id'   => $bot_db_id,
                'focus_mode'       => $focus_mode,
            ] );
            $this->fire_chat_processed( $message, $engine_result['reply'], [
                'platform_type' => $platform_type,
                'session_id'    => $session_id,
                'character_id'  => $character_id,
                'user_id'       => $user_id,
                'images'        => $images,
                'plugin_slug'   => $intent_plugin_slug,
            ] );
            exit;
        }

        // ── Tool completion → send tool's reply directly (contains URLs, structured content) ──
        // Also handles executor ack (contains trace_id) — must NOT be rephrased by LLM.
        $is_tool_complete = ( $engine_result['action'] ?? '' ) === 'complete'
            && ! empty( $engine_result['meta']['tool_name'] );
        $is_executor_ack  = ( $engine_result['action'] ?? '' ) === 'complete'
            && ! empty( $engine_result['meta']['executor_trace_id'] );

        if ( $is_tool_complete || $is_executor_ack ) {
            $bot_db_id = $this->log_webchat_message( [
                'session_id'              => $session_id,
                'user_id'                 => 0,
                'client_name'             => 'AI Assistant',
                'message_id'              => uniqid( 'intent_tool_' ),
                'message_text'            => $engine_result['reply'],
                'message_from'            => 'bot',
                'message_type'            => 'text',
                'platform_type'           => $platform_type,
                'plugin_slug'             => $intent_plugin_slug,
                'tool_name'               => $engine_result['meta']['tool_name'] ?? $intent_tool_name,
                'intent_conversation_id'  => $intent_conv_id,
                'meta'                    => [
                    'character_id'           => $character_id,
                    'via'                    => $is_executor_ack ? 'executor_ack' : 'intent_tool_complete',
                    'tool_name'              => $engine_result['meta']['tool_name'] ?? '',
                    'executor_trace_id'      => $engine_result['meta']['executor_trace_id'] ?? '',
                    'intent_conversation_id' => $intent_conv_id,
                    'goal'                   => $intent_goal,
                    'plugin_slug'            => $intent_plugin_slug,
                ],
            ] );

            // Skip chunk if tool already streamed via bizcity_intent_stream_chunk hook
            if ( ! $already_streamed ) {
                $this->send_sse_event( 'chunk', [
                    'delta' => $engine_result['reply'],
                    'full'  => $engine_result['reply'],
                ] );
            }
            $this->send_sse_event( 'done', [
                'message'          => $engine_result['reply'],
                'conversation_id'  => $intent_conv_id,
                'action'           => $engine_result['action'] ?? '',
                'goal'             => $intent_goal,
                'goal_label'       => $intent_label,
                'plugin_slug'      => $intent_plugin_slug,
                'tool_name'        => $engine_result['meta']['tool_name'] ?? $intent_tool_name,
                'bot_message_id'   => $bot_db_id,
                'focus_mode'       => $focus_mode,
            ] );
            $this->fire_chat_processed( $message, $engine_result['reply'], [
                'platform_type' => $platform_type,
                'session_id'    => $session_id,
                'character_id'  => $character_id,
                'user_id'       => $user_id,
                'images'        => $images,
                'plugin_slug'   => $intent_plugin_slug,
            ] );
            exit;
        }

        // ── Completion messages → route through AI brain to apply memory override (xưng hô, phong cách) ──
        if ( ( $engine_result['action'] ?? '' ) === 'complete' ) {
            $engine_result['meta']['completion_hint'] = $engine_result['reply'];
        }

        // ── Pipeline direct reply (action='reply') → send as-is ──
        // Any mode pipeline (emotion safety, knowledge, etc.) can produce a complete answer.
        // Do NOT re-process through LLM — send directly to frontend.
        if ( ( $engine_result['action'] ?? '' ) === 'reply' && ! empty( $engine_result['reply'] ) ) {
            $pipeline_meta   = $engine_result['meta']['pipeline'] ?? [];
            $knowledge_prov  = $pipeline_meta['provider'] ?? $intent_plugin_slug;
            $knowledge_label = $pipeline_meta['provider_label'] ?? $intent_label;

            $bot_db_id = $this->log_webchat_message( [
                'session_id'              => $session_id,
                'user_id'                 => 0,
                'client_name'             => 'AI Assistant',
                'message_id'              => uniqid( 'intent_knowledge_' ),
                'message_text'            => $engine_result['reply'],
                'message_from'            => 'bot',
                'message_type'            => 'text',
                'platform_type'           => $platform_type,
                'plugin_slug'             => $knowledge_prov,
                'intent_conversation_id'  => $intent_conv_id,
                'meta'                    => [
                    'character_id'           => $character_id,
                    'via'                    => 'knowledge_pipeline_reply',
                    'knowledge_provider'     => $knowledge_prov,
                    'plugin_slug'            => $knowledge_prov,
                    'intent_conversation_id' => $intent_conv_id,
                    'goal'                   => $intent_goal,
                ],
            ] );

            // Skip chunk if pipeline already streamed via bizcity_intent_stream_chunk hook
            if ( ! $already_streamed ) {
                $this->send_sse_event( 'chunk', [
                    'delta' => $engine_result['reply'],
                    'full'  => $engine_result['reply'],
                ] );
            }
            $this->send_sse_event( 'done', [
                'message'          => $engine_result['reply'],
                'conversation_id'  => $intent_conv_id,
                'action'           => 'reply',
                'goal'             => $intent_goal,
                'goal_label'       => $knowledge_label,
                'plugin_slug'      => $knowledge_prov,
                'tool_name'        => $intent_tool_name,
                'bot_message_id'   => $bot_db_id,
                'focus_mode'       => $focus_mode,
            ] );
            $this->fire_chat_processed( $message, $engine_result['reply'], [
                'platform_type' => $platform_type,
                'session_id'    => $session_id,
                'character_id'  => $character_id,
                'user_id'       => $user_id,
                'images'        => $images,
                'plugin_slug'   => $knowledge_prov,
            ] );
            exit;
        }

        // ── Need AI response → stream it ──
        $this->stream_ai_response( $message, $character_id, $session_id, $images, $user_id, $platform_type, $engine_result );

        exit;
    }

    /**
     * Stream AI response via SSE using OpenRouter streaming.
     *
     * @param string $message
     * @param int    $character_id
     * @param string $session_id
     * @param array  $images
     * @param int    $user_id
     * @param string $platform_type
     * @param array  $engine_result
     */
    private function stream_ai_response( $message, $character_id, $session_id, $images, $user_id, $platform_type, $engine_result ) {
        // ═══ Extract unified tracking fields (same logic as handle_sse) ═══
        $sr_conv_id     = $engine_result['conversation_id'] ?? '';
        $sr_goal        = $engine_result['goal'] ?? '';
        $sr_goal_label  = $engine_result['goal_label'] ?? '';
        $sr_tool_name   = $engine_result['meta']['tool_name'] ?? ( sanitize_text_field( $_REQUEST['tool_name'] ?? '' ) );
        $sr_plugin_slug = '';
        if ( ! empty( $engine_result['meta']['pipeline']['provider'] ) ) {
            $sr_plugin_slug = $engine_result['meta']['pipeline']['provider'];
        } elseif ( ! empty( $engine_result['meta']['provider_id'] ) ) {
            $sr_plugin_slug = $engine_result['meta']['provider_id'];
        } elseif ( $sr_goal && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $owner = BizCity_Intent_Provider_Registry::instance()->get_provider_for_goal( $sr_goal );
            if ( $owner ) $sr_plugin_slug = $owner->get_id();
        }

        // ═══ COMPUTE FOCUS MODE (same logic as handle_sse) ═══
        $engine_action = $engine_result['action'] ?? '';
        if ( $engine_action === 'ask_user' ) {
            $sr_focus_mode = 'active';
        } elseif ( $engine_action === 'complete' ) {
            $sr_focus_mode = 'completed';
        } elseif ( ! empty( $sr_goal ) && ! empty( $sr_plugin_slug ) ) {
            $sr_focus_mode = 'active';
        } else {
            $sr_focus_mode = 'none';
        }

        // Build messages using the Chat Gateway brain
        if ( ! class_exists( 'BizCity_Chat_Gateway' ) ) {
            error_log( '[bizcity-intent-stream] BizCity_Chat_Gateway class not found' );
            $this->send_sse_event( 'error', [ 'message' => 'Chat Gateway chưa sẵn sàng.' ] );
            $this->send_sse_done();
            return;
        }

        // Resolve the best streaming function — prefer bizcity_llm_chat_stream (routes through LLM Router)
        $stream_fn = null;
        if ( function_exists( 'bizcity_llm_chat_stream' ) ) {
            $stream_fn = 'bizcity_llm_chat_stream';
        } elseif ( function_exists( 'bizcity_openrouter_chat_stream' ) ) {
            $stream_fn = 'bizcity_openrouter_chat_stream';
        }

        if ( ! $stream_fn ) {

            // Final fallback: non-streaming batch via Chat Gateway
            error_log( '[bizcity-intent-stream] No streaming function available, falling back to batch' );
            $gateway = BizCity_Chat_Gateway::instance();
            try {
                $reply_data = $gateway->get_ai_response( $character_id, $message, $images, $session_id, '[]', $user_id, $platform_type );

                // Persist the reply (skip if 'complete' — engine already added turn)
                $conv_id = $engine_result['conversation_id'] ?? '';
                if ( $conv_id && ! empty( $reply_data['message'] ) && ( $engine_result['action'] ?? '' ) !== 'complete' ) {
                    BizCity_Intent_Conversation::instance()->add_turn( $conv_id, 'assistant', $reply_data['message'], [
                        'meta' => [
                            'provider' => $reply_data['provider'] ?? '',
                            'model'    => $reply_data['model'] ?? '',
                            'via'      => 'sse_fallback',
                        ],
                    ] );
                }

                // Log bot reply to webchat_messages FIRST (before done event)
                $bot_db_id = 0;
                if ( ! empty( $reply_data['message'] ) ) {
                    $bot_db_id = $this->log_webchat_message( [
                        'session_id'              => $session_id,
                        'user_id'                 => 0,
                        'client_name'             => $reply_data['character_name'] ?? 'AI Assistant',
                        'message_id'              => uniqid( 'intent_bot_' ),
                        'message_text'            => $reply_data['message'],
                        'message_from'            => 'bot',
                        'message_type'            => 'text',
                        'platform_type'           => $platform_type,
                        'plugin_slug'             => $sr_plugin_slug,
                        'intent_conversation_id'  => $sr_conv_id,
                        'meta'          => [
                            'intent_conversation_id' => $sr_conv_id,
                            'goal'                   => $sr_goal,
                            'plugin_slug'            => $sr_plugin_slug,
                            'provider'     => $reply_data['provider'] ?? '',
                            'model'        => $reply_data['model'] ?? '',
                            'character_id' => $character_id,
                            'via'          => 'intent_sse_fallback',
                        ],
                    ] );
                }

                $this->send_sse_event( 'chunk', [
                    'delta' => $reply_data['message'],
                    'full'  => $reply_data['message'],
                ] );
                $this->send_sse_event( 'done', [
                    'message'          => $reply_data['message'],
                    'conversation_id'  => $engine_result['conversation_id'] ?? '',
                    'provider'         => $reply_data['provider'] ?? '',
                    'model'            => $reply_data['model'] ?? '',
                    'bot_message_id'   => $bot_db_id,
                    'goal'             => $sr_goal,
                    'goal_label'       => $sr_goal_label,
                    'plugin_slug'      => $sr_plugin_slug,
                    'tool_name'        => $sr_tool_name,
                    'focus_mode'       => $sr_focus_mode,
                ] );
            } catch ( \Exception $e ) {
                error_log( '[bizcity-intent-stream] Fallback error: ' . $e->getMessage() );
                $this->send_sse_event( 'error', [ 'message' => $e->getMessage() ] );
            }
            $this->send_sse_done();
            return;
        }

        // ── Build the message array that the Gateway normally builds ──
        // We'll replicate the key parts of get_ai_response for streaming
        $messages = $this->build_llm_messages( $message, $character_id, $session_id, $images, $user_id, $platform_type, $engine_result );
        $model_options = $this->get_model_options( $character_id );

        // Stream!
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[bizcity-intent-stream] Starting SSE stream | model=' . ( $model_options['model'] ?? 'default' ) . ' | msgs=' . count( $messages ) );
        }
        $self = $this;
        $result = $stream_fn(
            $messages,
            $model_options,
            function ( $delta, $full_text ) use ( $self ) {
                $self->send_sse_event( 'chunk', [
                    'delta' => $delta,
                    'full'  => $full_text,
                ] );
            }
        );

        // ── Persist the AI reply as a turn so history is loadable ──
        // Skip if 'complete' action — engine already persisted a turn; AI just personalizes the SSE output
        $bot_reply = $result['message'] ?? '';
        $conv_id   = $engine_result['conversation_id'] ?? '';
        if ( $conv_id && $bot_reply && ( $engine_result['action'] ?? '' ) !== 'complete' ) {
            BizCity_Intent_Conversation::instance()->add_turn( $conv_id, 'assistant', $bot_reply, [
                'meta' => [
                    'provider' => $result['provider'] ?? 'openrouter',
                    'model'    => $result['model'] ?? '',
                    'via'      => 'sse_stream',
                ],
            ] );
        }

        if ( empty( $result['success'] ) ) {
            $err = $result['error'] ?? 'unknown';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[bizcity-intent-stream] Stream completed with error: ' . $err );
            }
            // HTTP 403 = invalid/missing API key — surface a helpful message instead of silence
            if ( strpos( $err, '403' ) !== false || strpos( $err, '401' ) !== false ) {
                $settings_url = admin_url( 'admin.php?page=bizcity-llm' );
                $create_url   = 'https://bizcity.vn/my-account/';
                $bot_reply = "⚠️ **Không thể kết nối đến API BizCity (lỗi xác thực)**\n\n"
                    . "API key hiện tại không hợp lệ hoặc chưa được cấu hình.\n\n"
                    . "**Cách khắc phục:**\n"
                    . "1. Truy cập [bizcity.vn/my-account/]({$create_url}) để lấy API key\n"
                    . "2. Cập nhật key tại trang [LLM Settings]({$settings_url})";
            }
        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[bizcity-intent-stream] Stream OK | reply_len=' . strlen( $bot_reply ) . ' | model=' . ( $result['model'] ?? '' ) );
            }
        }

        // ── Log bot reply to webchat_messages (unified history) ──
        $bot_db_id = 0;
        if ( $bot_reply ) {
            $char_name = 'AI Assistant';
            if ( $character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
                $char = BizCity_Knowledge_Database::instance()->get_character( $character_id );
                if ( $char ) $char_name = $char->name;
            }
            $bot_db_id = $this->log_webchat_message( [
                'session_id'              => $session_id,
                'user_id'                 => 0,
                'client_name'             => $char_name,
                'message_id'              => uniqid( 'intent_bot_' ),
                'message_text'            => $bot_reply,
                'message_from'            => 'bot',
                'message_type'            => 'text',
                'platform_type'           => $platform_type,
                'plugin_slug'             => $sr_plugin_slug,
                'tool_name'               => $sr_tool_name,
                'intent_conversation_id'  => $sr_conv_id,
                'meta'          => [
                    'intent_conversation_id' => $sr_conv_id,
                    'goal'                   => $sr_goal,
                    'plugin_slug'            => $sr_plugin_slug,
                    'provider'     => $result['provider'] ?? 'openrouter',
                    'model'        => $result['model'] ?? '',
                    'character_id' => $character_id,
                    'via'          => 'intent_sse_stream',
                ],
            ] );
        }

        // Send completion event
        $done_payload = [
            'message'          => $bot_reply ?: ( $result['message'] ?? '' ),
            'conversation_id'  => $engine_result['conversation_id'] ?? '',
            'action'           => $engine_result['action'] ?? '',
            'provider'         => $result['provider'] ?? 'openrouter',
            'model'            => $result['model'] ?? '',
            'usage'            => $result['usage'] ?? [],
            'success'          => $result['success'] ?? false,
            'bot_message_id'   => $bot_db_id,
            'goal'             => $sr_goal,
            'goal_label'       => $sr_goal_label,
            'plugin_slug'      => $sr_plugin_slug,
            'tool_name'        => $sr_tool_name,
            'focus_mode'       => $sr_focus_mode,
        ];
        // Pass BCN user message DB id back so Notebook frontend can replace temp id
        $bcn_user_msg_id = absint( $_REQUEST['_bcn_user_msg_id'] ?? 0 );
        if ( $bcn_user_msg_id ) {
            $done_payload['_bcn_user_msg_id'] = $bcn_user_msg_id;
        }
        // v4.3.1→v5.0: suggest_tool disabled — no longer pushing tool activation cards
        // if ( $this->_suggest_tool_data ) {
        //     $done_payload['suggest_tool'] = $this->_suggest_tool_data;
        //     $this->_suggest_tool_data = null;
        // }
        // v4.3.5→v5.0: suggest_tools disabled — no longer pushing tool suggestions
        // if ( ! empty( $engine_result['meta']['suggest_tools'] ) ) {
        //     $done_payload['suggest_tools'] = $engine_result['meta']['suggest_tools'];
        // }
        $this->send_sse_event( 'done', $done_payload );

        // ── Fire post-response hook — enables Emotional Memory extraction + bond scoring ──
        if ( $bot_reply ) {
            do_action( 'bizcity_chat_after_response', $message, $bot_reply, [
                'user_id'      => $user_id,
                'session_id'   => $session_id,
                'character_id' => $character_id,
                'platform'     => $platform_type,
                'via'          => 'intent_sse_stream',
            ] );
        }

        // ── Fire automation trigger bridge ──
        $this->fire_chat_processed( $message, $bot_reply ?: '', [
            'platform_type' => $platform_type,
            'session_id'    => $session_id,
            'character_id'  => $character_id,
            'user_id'       => $user_id,
            'images'        => $images,
            'provider'      => $result['provider'] ?? 'openrouter',
            'model'         => $result['model'] ?? '',
            'plugin_slug'   => $engine_result['meta']['pipeline']['provider'] ?? '',
        ] );

        $this->send_sse_done();
    }

    /* ================================================================
     *  Batch Adapter — For hook channels (Zalo/Telegram/FB)
     * ================================================================ */

    /**
     * Process a message and return the complete response (no streaming to client).
     * Internally uses streaming to build the response faster.
     *
     * @param string $message
     * @param int    $character_id
     * @param string $session_id
     * @param array  $images
     * @param int    $user_id
     * @param string $platform_type
     * @param array  $engine_result
     * @return array {
     *   @type string $message  Full response text.
     *   @type string $provider
     *   @type string $model
     *   @type array  $usage
     * }
     */
    public function batch_response( $message, $character_id, $session_id, $images = [], $user_id = 0, $platform_type = 'ZALO', $engine_result = [] ) {
        // ── Tool completion / executor ack → return reply directly (URLs, structured content, trace ack) ──
        $is_tool_complete = ( $engine_result['action'] ?? '' ) === 'complete'
            && ! empty( $engine_result['meta']['tool_name'] );
        $is_executor_ack  = ( $engine_result['action'] ?? '' ) === 'complete'
            && ! empty( $engine_result['meta']['executor_trace_id'] );

        if ( $is_tool_complete || $is_executor_ack ) {
            return [
                'message'  => $engine_result['reply'],
                'provider' => $is_executor_ack ? 'executor' : 'intent_tool',
                'model'    => '',
                'usage'    => [],
            ];
        }

        // ── Completion messages → add hint for AI to personalize with memory override ──
        if ( ( $engine_result['action'] ?? '' ) === 'complete' && ! empty( $engine_result['reply'] ) ) {
            $engine_result['meta']['completion_hint'] = $engine_result['reply'];
        }

        // If no streaming available, fall back
        $batch_stream_fn = function_exists( 'bizcity_llm_chat_stream' ) ? 'bizcity_llm_chat_stream'
            : ( function_exists( 'bizcity_openrouter_chat_stream' ) ? 'bizcity_openrouter_chat_stream' : null );
        if ( ! $batch_stream_fn ) {
            if ( class_exists( 'BizCity_Chat_Gateway' ) ) {
                return BizCity_Chat_Gateway::instance()->get_ai_response(
                    $character_id, $message, $images, $session_id, '[]', $user_id, $platform_type
                );
            }
            return [ 'message' => 'AI gateway chưa sẵn sàng.', 'provider' => '', 'model' => '' ];
        }

        $messages      = $this->build_llm_messages( $message, $character_id, $session_id, $images, $user_id, $platform_type, $engine_result );
        $model_options = $this->get_model_options( $character_id );

        // Stream internally but don't output — just accumulate
        $result = $batch_stream_fn( $messages, $model_options, null );

        // ── Persist the AI reply as a turn (skip if 'complete' — engine already added turn) ──
        $bot_reply = $result['message'] ?? '';
        $conv_id   = $engine_result['conversation_id'] ?? '';
        if ( $conv_id && $bot_reply && ( $engine_result['action'] ?? '' ) !== 'complete' ) {
            BizCity_Intent_Conversation::instance()->add_turn( $conv_id, 'assistant', $bot_reply, [
                'meta' => [
                    'provider' => $result['provider'] ?? 'openrouter',
                    'model'    => $result['model'] ?? '',
                    'via'      => 'batch',
                ],
            ] );
        }

        // ── Fire post-response hook — enables Emotional Memory extraction + bond scoring ──
        if ( $bot_reply ) {
            do_action( 'bizcity_chat_after_response', $message, $bot_reply, [
                'user_id'      => $user_id,
                'session_id'   => $session_id,
                'character_id' => $character_id,
                'platform'     => $platform_type,
                'via'          => 'intent_batch',
            ] );
        }

        return [
            'message'  => $result['message'] ?? '',
            'provider' => $result['provider'] ?? 'openrouter',
            'model'    => $result['model'] ?? '',
            'usage'    => $result['usage'] ?? [],
        ];
    }

    /* ================================================================
     *  Message building (delegates to Chat Gateway for system prompt)
     * ================================================================ */

    /**
     * Build the LLM messages array.
     * Reuses Chat Gateway's context building logic.
     *
     * @param string $message
     * @param int    $character_id
     * @param string $session_id
     * @param array  $images
     * @param int    $user_id
     * @param string $platform_type
     * @param array  $engine_result
     * @return array OpenAI-format messages.
     */
    private function build_llm_messages( $message, $character_id, $session_id, $images, $user_id, $platform_type, $engine_result = [] ) {

        // ── TWIN CONTEXT RESOLVER: single-call delegation ──
        if ( defined( 'BIZCITY_TWIN_RESOLVER_ENABLED' ) && BIZCITY_TWIN_RESOLVER_ENABLED
             && class_exists( 'BizCity_Twin_Context_Resolver' ) ) {

            $system_content = BizCity_Twin_Context_Resolver::build_system_prompt( 'chat', [
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'message'       => $message,
                'character_id'  => $character_id,
                'platform_type' => $platform_type,
                'images'        => $images,
                'via'           => 'intent_stream',
                'engine_result' => $engine_result,
            ] );

            // Completion hint — AI personalizes completion message using memory
            if ( ! empty( $engine_result['meta']['completion_hint'] ) ) {
                $hint_label = $engine_result['goal_label'] ?? $engine_result['goal'] ?? '';
                $system_content .= "\n\n# 🎯 NHIỆM VỤ HIỆN TẠI — TRẢ LỜI XÁC NHẬN HOÀN THÀNH:\n";
                $system_content .= "User vừa hoàn thành một tác vụ" . ( $hint_label ? " (\"" . $hint_label . "\")" : '' ) . ".\n";
                $system_content .= "Hãy tạo lời xác nhận hoàn thành NGẮN GỌN (1-2 câu).\n";
                $system_content .= "BẮT BUỘC sử dụng đúng cách xưng hô và phong cách từ KÝ ỨC USER ở trên.\n";
                $system_content .= "Bắt đầu bằng ✅, giữ thông điệp tích cực, thân thiện.\n";
                $system_content .= "KHÔNG dùng 'bạn' nếu user đã dặn cách xưng hô khác.\n";
            }

            $openai_messages = [ [ 'role' => 'system', 'content' => $system_content ] ];

            // Conversation history (from Intent conversation turns)
            if ( ! empty( $engine_result['conversation_id'] ) ) {
                $conv_mgr = BizCity_Intent_Conversation::instance();
                $turns    = $conv_mgr->get_turns( $engine_result['conversation_id'], 10 );
                $last_user_idx = null;
                foreach ( $turns as $i => $turn ) {
                    if ( $turn['role'] === 'user' ) {
                        $last_user_idx = $i;
                    }
                }
                foreach ( $turns as $i => $turn ) {
                    if ( $turn['role'] === 'system' || $turn['role'] === 'tool' ) {
                        continue;
                    }
                    if ( $i === $last_user_idx ) {
                        continue;
                    }
                    $turn_attachments = $turn['attachments'] ?? [];
                    if ( $turn['role'] === 'user' && ! empty( $turn_attachments ) ) {
                        $parts   = [];
                        $parts[] = [ 'type' => 'text', 'text' => $turn['content'] ?: '[Image]' ];
                        foreach ( $turn_attachments as $att ) {
                            $att_url = is_string( $att ) ? $att : ( $att['url'] ?? $att['data'] ?? '' );
                            if ( $att_url ) {
                                $parts[] = [ 'type' => 'image_url', 'image_url' => [ 'url' => $att_url, 'detail' => 'auto' ] ];
                            }
                        }
                        $openai_messages[] = [ 'role' => 'user', 'content' => $parts ];
                    } else {
                        $openai_messages[] = [
                            'role'    => $turn['role'] === 'user' ? 'user' : 'assistant',
                            'content' => $turn['content'],
                        ];
                    }
                }
            }

            // Current user message (with vision support)
            if ( ! empty( $images ) ) {
                $content   = [];
                $content[] = [ 'type' => 'text', 'text' => $message ?: 'Hãy mô tả hoặc phân tích hình ảnh này.' ];
                foreach ( $images as $img ) {
                    $url = is_string( $img ) ? $img : ( $img['url'] ?? $img['data'] ?? '' );
                    if ( $url ) {
                        $content[] = [ 'type' => 'image_url', 'image_url' => [ 'url' => $url, 'detail' => 'auto' ] ];
                    }
                }
                $openai_messages[] = [ 'role' => 'user', 'content' => $content ];
            } else {
                $openai_messages[] = [ 'role' => 'user', 'content' => $message ];
            }

            return $openai_messages;
        }

        // ── LEGACY FALLBACK — context definitions consolidated in Twin Context Resolver ──
        $system_content = "Bạn là trợ lý Team Leader AI cá nhân. Trả lời bằng tiếng Việt, tự nhiên.";
        $pre_mode = $engine_result['meta']['mode'] ?? 'knowledge';
        $routing_branch = $engine_result['meta']['routing_branch'] ?? 'knowledge';
        $system_content = apply_filters( 'bizcity_chat_system_prompt', $system_content, [
            'character_id' => $character_id, 'message' => $message,
            'user_id' => $user_id, 'session_id' => $session_id,
            'platform_type' => $platform_type, 'via' => 'intent_stream',
            'mode' => $pre_mode, 'routing_branch' => $routing_branch,
        ] );
        $openai_messages = [ [ 'role' => 'system', 'content' => $system_content ] ];
        if ( ! empty( $engine_result['conversation_id'] ) ) {
            $conv_mgr = BizCity_Intent_Conversation::instance();
            $turns = $conv_mgr->get_turns( $engine_result['conversation_id'], 10 );
            $last_user_idx = null;
            foreach ( $turns as $i => $turn ) { if ( $turn['role'] === 'user' ) { $last_user_idx = $i; } }
            foreach ( $turns as $i => $turn ) {
                if ( $turn['role'] === 'system' || $turn['role'] === 'tool' || $i === $last_user_idx ) { continue; }
                $openai_messages[] = [ 'role' => $turn['role'] === 'user' ? 'user' : 'assistant', 'content' => $turn['content'] ];
            }
        }
        if ( ! empty( $images ) ) {
            $content = [ [ 'type' => 'text', 'text' => $message ?: 'Hãy mô tả hoặc phân tích hình ảnh này.' ] ];
            foreach ( $images as $img ) {
                $url = is_string( $img ) ? $img : ( $img['url'] ?? $img['data'] ?? '' );
                if ( $url ) { $content[] = [ 'type' => 'image_url', 'image_url' => [ 'url' => $url, 'detail' => 'auto' ] ]; }
            }
            $openai_messages[] = [ 'role' => 'user', 'content' => $content ];
        } else {
            $openai_messages[] = [ 'role' => 'user', 'content' => $message ];
        }
        return $openai_messages;

        // @codeCoverageIgnoreStart — Legacy inline context building (unreachable)
        $system_content = '';
        $openai_messages = [];

        // ── Character ──
        $character = null;
        if ( $character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $db        = BizCity_Knowledge_Database::instance();
            $character = $db->get_character( $character_id );
        }

        if ( $character && ! empty( $character->system_prompt ) ) {
            $system_content = $character->system_prompt;
        }

        // ══════════════════════════════════════════════════════════════════
        // 🧠 LAYER 0: USER MEMORY — ƯU TIÊN SỐ 1, INJECT TRƯỚC TẤT CẢ
        // Ghi nhớ cách xưng hô, tên gọi, sở thích — ghi đè mọi pipeline khác
        // ══════════════════════════════════════════════════════════════════
        $memory_context = '';
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $mem = BizCity_User_Memory::instance();
            // Logged-in → query by user_id only (global). Anonymous → by session_id.
            $q_uid = $user_id > 0 ? $user_id : 0;
            $q_sid = $user_id > 0 ? ''       : $session_id;
            $memory_context = $mem->build_memory_context( $q_uid, $q_sid, $session_id );
        }
        if ( ! empty( $memory_context ) ) {
            $system_content .= $memory_context;
        }

        // ── Profile + Transit context — build & inject directly into base prompt ──
        $bizcoach_profile = '';
        $bizcoach_transit = '';

        // ── TWIN CONTEXT RESOLVER (Sprint 0B): single-call delegation ──
        $twin_resolver_used = false;
        if ( defined( 'BIZCITY_TWIN_RESOLVER_ENABLED' ) && BIZCITY_TWIN_RESOLVER_ENABLED
             && class_exists( 'BizCity_Twin_Context_Resolver' ) ) {
            $twin = BizCity_Twin_Context_Resolver::for_chat( [
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'message'       => $message,
                'platform_type' => $platform_type,
                'images'        => $images,
                'engine_result' => $engine_result,
            ] );
            $bizcoach_profile = $twin['profile_context'] ?? '';
            $bizcoach_transit = $twin['transit_context'] ?? '';
            $twin_resolver_used = true;
        }

        if ( ! $twin_resolver_used && class_exists( 'BizCity_Profile_Context' ) ) {
            $profile_ctx = BizCity_Profile_Context::instance();
            $bizcoach_profile = $profile_ctx->build_user_context( $user_id, $session_id, $platform_type );

            // ── TWIN CORE: Ensure focus profile resolved BEFORE inline gate checks ──
            if ( class_exists( 'BizCity_Focus_Gate' ) ) {
                BizCity_Focus_Gate::ensure_resolved( $message, [
                    'user_id'       => $user_id,
                    'session_id'    => $session_id,
                    'platform_type' => $platform_type,
                    'images'        => $images,
                    'mode'          => $engine_result['meta']['mode'] ?? '',
                    'active_goal'   => $engine_result['goal'] ?? '',
                ] );
                // Amend for goal if profile was already resolved without goal info
                BizCity_Focus_Gate::amend_for_goal( $engine_result['goal'] ?? '' );
            }

            // Transit context — Focus Gate gated (Sprint 0A)
            $twin_build_transit = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'transit' );
            if ( $twin_build_transit ) {
                $active_goal_for_transit = $engine_result['goal'] ?? '';
                $bizcoach_transit = $profile_ctx->build_transit_context( $message, $user_id, $session_id, $platform_type, $active_goal_for_transit );
                if ( empty( $bizcoach_transit ) && ! empty( $images ) ) {
                    $bizcoach_transit = $profile_ctx->build_transit_context( 'chiêm tinh tháng này', $user_id, $session_id, $platform_type, $active_goal_for_transit );
                }
            }
        }
        // Direct injection into system_content (not via filter)
        if ( ! empty( $bizcoach_profile ) ) {
            $system_content .= "\n\n---\n\n" . $bizcoach_profile;
        }
        if ( ! empty( $bizcoach_transit ) ) {
            $system_content .= "\n\n---\n\n" . $bizcoach_transit;
        }

        // ── Provider Profile Context — inject per-plugin profile data ──
        // Each intent provider can declare get_profile_context() with user-specific
        // data (preferences, birth info, etc.) to personalize AI responses.
        $provider_profiles = [];     // collected for logging
        $provider_profile_ms = 0;
        if ( class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $pp_start  = microtime( true );
            $registry  = BizCity_Intent_Provider_Registry::instance();
            $active_goal = $engine_result['goal'] ?? '';

            foreach ( $registry->get_all() as $provider ) {
                // Only inject profile for the active goal's provider (or all if no goal yet)
                if ( $active_goal && ! $provider->owns_goal( $active_goal ) ) {
                    continue;
                }
                $profile = $provider->get_profile_context( $user_id );
                $pid     = $provider->get_id();
                $provider_profiles[ $pid ] = [
                    'complete' => $profile['complete'] ?? false,
                    'ctx_len'  => mb_strlen( $profile['context'] ?? '', 'UTF-8' ),
                    'has_fb'   => ! empty( $profile['fallback'] ),
                    'url'      => $provider->get_profile_page_url(),
                ];
                if ( ! empty( $profile['context'] ) ) {
                    $system_content .= "\n\n---\n\n### 👤 Hồ sơ người dùng (" . $provider->get_name() . "):\n" . $profile['context'];
                }
                if ( ! $profile['complete'] && ! empty( $profile['fallback'] ) ) {
                    $system_content .= "\n\n⚠️ " . $profile['fallback'];
                }
            }
            $provider_profile_ms = round( ( microtime( true ) - $pp_start ) * 1000, 2 );

            // ── Log provider_profile_build step ──
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                $pp_pipeline = [];
                foreach ( $provider_profiles as $pid => $pp ) {
                    $status = $pp['complete'] ? '✅' : ( $pp['has_fb'] ? '⚠️fallback' : '—' );
                    $pp_pipeline[] = "{$pid}: {$status} ({$pp['ctx_len']} chars)";
                }
                BizCity_User_Memory::log_router_event( [
                    'step'             => 'provider_profile_build',
                    'message'          => 'get_profile_context() per active provider',
                    'mode'             => 'provider_profile',
                    'functions_called' => 'BizCity_Intent_Provider::get_profile_context()',
                    'pipeline'         => $pp_pipeline,
                    'file_line'        => 'class-intent-stream.php::provider_profile_build',
                    'active_goal'      => $active_goal ?: '(none — all providers)',
                    'providers_count'  => count( $provider_profiles ),
                    'providers'        => $provider_profiles,
                    'total_chars'      => array_sum( array_column( $provider_profiles, 'ctx_len' ) ),
                    'profile_ms'       => $provider_profile_ms,
                ], $session_id );
            }
        }

        // ── Knowledge context ──
        if ( class_exists( 'BizCity_Knowledge_Context_API' ) ) {
            $ctx = BizCity_Knowledge_Context_API::instance()->build_context( $character_id, $message, [
                'max_tokens' => 3000,
                'include_vision' => ! empty( $images ),
                'images' => $images,
            ] );
            $knowledge = $ctx['context'] ?? '';
            if ( $knowledge ) {
                $system_content .= "\n\n---\n\n## Kiến thức tham khảo:\n" . $knowledge;
            }
        }

        // ── Conversation context (rolling summary + slots) ──
        if ( ! empty( $engine_result['conversation_id'] ) ) {
            $conv = $engine_result;
            if ( ! empty( $conv['rolling_summary'] ) ) {
                $system_content .= "\n\n---\n\n### 🧵 Tóm tắt hội thoại hiện tại:\n" . $conv['rolling_summary'];
            }
            if ( ! empty( $conv['goal'] ) ) {
                $system_content .= "\n\n### 🎯 Mục tiêu hiện tại: " . ( $conv['goal_label'] ?? $conv['goal'] );
                if ( ! empty( $conv['slots'] ) ) {
                    $system_content .= "\nThông tin đã thu thập: " . wp_json_encode( $conv['slots'], JSON_UNESCAPED_UNICODE );
                }
            }
        }

        // ── Knowledge Pipeline Expansion — Hybrid C ──
        // When the knowledge pipeline (Gemini/ChatGPT) already produced a direct answer (action=reply),
        // inject it as context so the Team Leader LLM can reference it after the marketplace redirect.
        $has_knowledge_expansion = ( isset( $engine_result['action'] ) && $engine_result['action'] === 'reply' && ! empty( $engine_result['reply'] ) );
        if ( $has_knowledge_expansion ) {
            $system_content .= "\n\n---\n\n## 🔍 KIẾN THỨC MỞ RỘNG (trả lời từ AI kiến thức nội bộ):\n";
            $system_content .= $engine_result['reply'];
            $system_content .= "\n";
        }

        // ── Base instruction — Team Leader role ──
        $role_block  = "\n\n## 🧑‍💼 VAI TRÒ CỦA BẠN:\n";
        $role_block .= "Bạn là **Trợ lý Team Leader cá nhân** của Chủ Nhân (người đang trò chuyện).\n";
        $role_block .= "- Bạn điều phối, tư vấn và hỗ trợ Chủ Nhân quản lý công việc, cuộc sống.\n";
        $role_block .= "- Trong hệ thống BizCity còn có NHIỀU AI Agent chuyên biệt khác (viết nội dung, chiêm tinh, marketing, kế toán, thiết kế, lập trình...) có thể giúp Chủ Nhân thực thi công việc cụ thể.\n";
        if ( $has_knowledge_expansion ) {
            $role_block .= "- 🔍 **QUAN TRỌNG**: Trong prompt có phần **KIẾN THỨC MỞ RỘNG** — đây là câu trả lời chính xác từ AI kiến thức nội bộ. HÃY SỬ DỤNG kiến thức này làm NỘI DUNG CHÍNH cho câu trả lời của bạn.\n";
            $role_block .= "- KHÔNG gợi ý Chủ Nhân vào Chợ AI Agent. KHÔNG nói 'mình chưa có trợ lý chuyên về...'. Kiến thức đã có sẵn.\n";
            $role_block .= "- Trình bày lại KIẾN THỨC MỞ RỘNG một cách tự nhiên, thân thiện, có cá nhân hóa theo phong cách Team Leader.\n";
        } else {
            // ── v4.2: Answer-first approach with conversation context ──
            // Instead of redirecting to marketplace, pull intent conversation
            // messages from webchat_messages as context and answer directly.
            // Mention that deeper/specialized analysis is possible with marketplace tools.
            $intent_conv_ctx = '';
            $ic_id = $engine_result['conversation_id'] ?? '';
            if ( $ic_id && class_exists( 'BizCity_WebChat_Database' ) ) {
                $wc_db   = BizCity_WebChat_Database::instance();
                $ic_msgs = $wc_db->get_recent_messages_by_intent_conversation( $ic_id, $session_id, 15 );
                if ( ! empty( $ic_msgs ) ) {
                    $ic_lines = [];
                    foreach ( $ic_msgs as $ic_row ) {
                        $ic_who  = ( $ic_row->message_from === 'user' ) ? 'Chủ Nhân (User)' : 'AI Trợ lý (Bạn)';
                        $ic_text = mb_substr( $ic_row->message_text, 0, 500, 'UTF-8' );
                        $ic_lines[] = "- {$ic_who}: {$ic_text}";
                    }
                    $intent_conv_ctx = implode( "\n", $ic_lines );
                }
            }

            if ( ! empty( $intent_conv_ctx ) ) {
                $system_content .= "\n\n---\n\n## 💬 NGỮ CẢNH HỘI THOẠI HIỆN TẠI:\n";
                $system_content .= "_(Chủ Nhân = Người dùng đang nói chuyện. AI Trợ lý = Bạn, chatbot.)_\n";
                $system_content .= $intent_conv_ctx . "\n";
            }

            $role_block .= "- HÃY TRẢ LỜI dựa trên hiểu biết và ngữ cảnh hội thoại hiện có. KHÔNG từ chối trả lời. KHÔNG nói 'mình chưa có trợ lý chuyên về...'.\n";
            $role_block .= "- Luôn ưu tiên GIẢI THÍCH và TRẢ LỜI thay vì chuyển hướng.\n";
            $role_block .= "- KHÔNG gợi ý công cụ, KHÔNG gợi ý Chợ AI Agent. Chỉ tập trung trả lời câu hỏi.\n";
        }

        if ( empty( trim( $system_content ) ) ) {
            $system_content = "Bạn là trợ lý Team Leader AI cá nhân. Trả lời bằng tiếng Việt, tự nhiên, giàu cảm xúc.";
        }
        $system_content .= $role_block;

        $system_content .= "\n### ⛔ RANH GIỚI VAI TRÒ BẮT BUỘC:\n";
        $system_content .= "- Bạn là AI Trợ lý. Chủ Nhân là NGƯỜI DÙNG đang nhắn tin cho bạn.\n";
        $system_content .= "- KHÔNG BAO GIỜ tự xưng bằng tên Chủ Nhân (VD: không nói \"Chu đây!\", không nói \"Anh Chu đẹp trai đây\").\n";
        $system_content .= "- KHÔNG nhập vai thành Chủ Nhân. KHÔNG nói như thể BẠN là người dùng.\n";
        $system_content .= "- Khi xưng hô 'mày tao': Chủ Nhân xưng 'tao', gọi AI là 'mày'. AI KHÔNG xưng 'tao' — AI xưng phù hợp với vai trợ lý.\n\n";

        $system_content .= "\n### 🗣️ Ngôn ngữ: Trả lời bằng tiếng Việt, thân thiện, tự nhiên.\n";

        // ══════════════════════════════════════════════════════════════════
        // 🎯 PROVIDER SYSTEM INSTRUCTIONS — Inject domain-specific AI instructions
        // from the intent provider that owns the current goal (e.g. BizCoach astro).
        // These are computed by the engine in compose_answer flow.
        // ══════════════════════════════════════════════════════════════════
        if ( ! empty( $engine_result['meta']['system_instructions'] ) ) {
            $system_content .= "\n\n" . $engine_result['meta']['system_instructions'];
        }
        if ( ! empty( $engine_result['meta']['provider_context'] ) ) {
            $system_content .= "\n\n" . $engine_result['meta']['provider_context'];
        }

        // ── Log context assembly BEFORE filters — architecture step tracking ──
        $has_conversation    = ! empty( $engine_result['conversation_id'] );
        $knowledge_built     = ( strpos( $system_content, 'Kiến thức tham khảo' ) !== false )
                            || ( strpos( $system_content, 'KIẾN THỨC MỞ RỘNG' ) !== false );
        $has_knowledge_expansion_log = isset( $has_knowledge_expansion ) && $has_knowledge_expansion;
        $has_provider_profile = ! empty( $provider_profiles ) && array_sum( array_column( $provider_profiles, 'ctx_len' ) ) > 0;
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'context_build',
                'message'          => mb_substr( $message, 0, 120, 'UTF-8' ),
                'mode'             => 'intent_stream',
                'functions_called' => 'build_llm_messages() [LEGACY — chưa delegate build_system_prompt]',
                'pipeline'         => [
                    '0:Character'        . ( $character                       ? ' ✓' : ' —' ),
                    '1:Memory'           . ( ! empty( $memory_context )       ? ' ✓' : ' —' ),
                    '2:Profile'          . ( ! empty( $bizcoach_profile )     ? ' ✓' : ' —' ),
                    '3:Transit'          . ( ! empty( $bizcoach_transit )     ? ' ✓' : ' —' ),
                    '3.5:ProviderProfile'. ( $has_provider_profile            ? ' ✓' : ' —' ),
                    '4:Knowledge'        . ( $knowledge_built                 ? ' ✓' : ' —' ),
                    '4.5:KnowledgeExp'   . ( $has_knowledge_expansion_log     ? ' ✓ HybridC' : ' —' ),
                    '5:Conversation'     . ( $has_conversation                ? ' ✓' : ' —' ),
                    '6:Rules —',
                    '7:Role ✓',
                    '→ 8:Filters',
                    '→ 9:EndReminder',
                ],
                'file_line'        => 'class-intent-stream.php::build_llm_messages',
                'via'              => 'intent_stream',
                'context_length'   => mb_strlen( $system_content, 'UTF-8' ),
                'has_memory'       => ! empty( $memory_context ),
                'has_profile'      => ! empty( $bizcoach_profile ),
                'has_transit'      => ! empty( $bizcoach_transit ),
                'has_provider_profile' => $has_provider_profile,
                'has_knowledge'    => $knowledge_built,
                'has_knowledge_expansion' => $has_knowledge_expansion_log,
                'has_conversation' => $has_conversation,
                'provider_profile_ms' => $provider_profile_ms,
                'missing_vs_arch'  => array_filter( [
                    ( ! empty( $bizcoach_profile ) && empty( $bizcoach_transit ) ) ? '3:Transit MISSING' : null,
                    '6:Rules MISSING — intent_stream thiếu astro grounding/tarot fusion/response depth',
                ] ),
            ], $session_id );
        }

        // ══════════════════════════════════════════════════════════════════
        // 🔴 CRITICAL: Apply all registered filters — this enables:
        //    • Memory injection (priority 99) — BizCity_User_Memory
        //    • BizCoach profile/transit (priority 95) — added above
        //    • Context Builder layers (priority 90) — BizCity_Context_Builder
        //    • Companion Context / Layer 1.7 (priority 97) — BizCity_Companion_Context
        //    • Response Texture Engine (priority 48) — BizCity_Response_Texture_Engine
        //    • Mode pipelines (priority 45) — Intent Engine
        //    • Provider context (priority 50) — Intent Providers
        // ══════════════════════════════════════════════════════════════════

        // ── Pre-compute intensity + mode + routing_branch for Texture Engine & Companion Context ──
        // estimate_emotion() is a fast keyword scan (0 LLM cost).
        // Returns { intensity, valence, emotion, empathy_level }.
        // Texture Engine (pri 48) and Companion Context (pri 97) both read these from $args.
        $pre_intensity    = 1;
        $pre_empathy      = false;
        $pre_valence      = 'neutral';
        $pre_emotion_name = 'none';
        $pre_empathy_level = 'none';
        $pre_mode         = isset( $engine_result['meta']['mode'] ) ? $engine_result['meta']['mode'] : 'knowledge';
        $routing_branch   = isset( $engine_result['meta']['routing_branch'] ) ? $engine_result['meta']['routing_branch'] : 'knowledge';
        if ( class_exists( 'BizCity_Emotional_Memory' ) ) {
            $emotion_struct = BizCity_Emotional_Memory::instance()->estimate_emotion( $message );
            $pre_intensity    = $emotion_struct['intensity'];
            $pre_valence      = $emotion_struct['valence'];
            $pre_emotion_name = $emotion_struct['emotion'];
            $pre_empathy_level = $emotion_struct['empathy_level'];
            // empathy_flag = true when message has significant emotional charge AND mode is empathy-adjacent
            $pre_empathy   = ( $pre_intensity >= 3 )
                          && in_array( $pre_mode, [ 'emotion', 'reflection' ], true );
        }

        // ── Phase 13: Activate Tool Context mode for slash command / execution ──
        // When user selected a tool via / command, use compact Tool Context (~800 chars)
        // instead of full 6-layer Emotion Context (~3000+ chars) for faster LLM response.
        $engine_method = $engine_result['meta']['method'] ?? '';
        if ( $engine_method === 'slash_command_direct' && class_exists( 'BizCity_Context_Builder' ) ) {
            BizCity_Context_Builder::instance()->set_tool_context_mode( true );
        }

        $system_content = apply_filters( 'bizcity_chat_system_prompt', $system_content, [
            'character_id'   => $character_id,
            'message'        => $message,
            'user_id'        => $user_id,
            'session_id'     => $session_id,
            'platform_type'  => $platform_type,
            'via'            => 'intent_stream',
            'mode'           => $pre_mode,
            'intensity'      => $pre_intensity,
            'valence'        => $pre_valence,
            'emotion'        => $pre_emotion_name,
            'empathy_level'  => $pre_empathy_level,
            'empathy_flag'   => $pre_empathy,
            'routing_branch' => $routing_branch,
        ] );

        // ── Log final system prompt for debugging ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $prompt_len   = mb_strlen( $system_content, 'UTF-8' );
            $word_count   = str_word_count( strip_tags( $system_content ) );
            // Extract a meaningful preview: first 500 chars + last 500 chars
            $preview_head = mb_substr( $system_content, 0, 500, 'UTF-8' );
            $preview_tail = $prompt_len > 1000 ? mb_substr( $system_content, -500, 500, 'UTF-8' ) : '';
            BizCity_User_Memory::log_router_event( [
                'step'             => 'final_prompt',
                'message'          => 'System prompt built for LLM (via intent_stream)',
                'mode'             => 'debug',
                'functions_called' => 'build_llm_messages() + apply_filters()',
                'file_line'        => 'class-intent-stream.php::build_llm_messages',
                'prompt_length'    => $prompt_len,
                'word_count'       => $word_count,
                'has_bizcoach'     => ( strpos( $system_content, 'TRANSIT CHIÊM TINH' ) !== false || strpos( $system_content, 'BẢN ĐỒ SAO' ) !== false || strpos( $system_content, 'BIZCOACH CONTEXT' ) !== false || ! empty( $engine_result['meta']['system_instructions'] ) ),
                'has_memory'       => ( strpos( $system_content, 'KÝ ỨC USER' ) !== false || strpos( $system_content, 'USER MEMORY' ) !== false ),
                'has_context_chain'=> ( strpos( $system_content, 'CONTEXT CHAIN' ) !== false || strpos( $system_content, 'PHIÊN CHAT' ) !== false ),
                'has_provider_profile' => ( strpos( $system_content, 'Hồ sơ người dùng' ) !== false ),
                // Phase 4.5: Companion Intelligence visibility
                'has_relationship_ctx' => ( strpos( $system_content, 'RELATIONSHIP CONTEXT' ) !== false || strpos( $system_content, '💛' ) !== false ),
                'has_response_texture' => ( strpos( $system_content, 'RESPONSE TEXTURE' ) !== false || strpos( $system_content, '🎨' ) !== false ),
                // Phase 4: Branch-specific texture detection
                'has_branch_texture' => ( strpos( $system_content, '**BRANCH:' ) !== false ),
                'routing_branch'   => $routing_branch,
                'pre_intensity'    => $pre_intensity,
                'pre_empathy'      => $pre_empathy,
                'prompt_head'      => $preview_head,
                'prompt_tail'      => $preview_tail,
                'full_prompt'      => $system_content,
            ], $session_id );
        }

        // ══════════════════════════════════════════════════════════════════
        // 🔴 CRITICAL END REMINDER — positioned LAST in system prompt
        // This is closest to user message → highest attention from LLM
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
        if ( ! empty( $bizcoach_profile ) ) {
            $end_reminder .= "\n## ✅ BẠN ĐÃ CÓ HỒ SƠ CHỦ NHÂN:\n";
            $end_reminder .= "- Hồ sơ người dùng đã được cung cấp ở phần trên của prompt này.\n";
            $end_reminder .= "- HÃY sử dụng hồ sơ để cá nhân hóa câu trả lời.\n";
            $end_reminder .= "- HÃY gọi người dùng bằng TÊN (nếu có trong hồ sơ).\n";
        }
        if ( ! $has_knowledge_expansion ) {
            // ── v3.8.2: When provider system instructions exist (compose_answer from
            // an active goal like tarot_interpret), the AI already has domain-specific
            // instructions. Suppress the "Chợ AI Agent" fallback — the provider IS
            // the specialist. Without this, the generic fallback template overrides
            // the provider instructions and the LLM says "chưa có trợ lý".
            $has_provider_instructions = ! empty( $engine_result['meta']['system_instructions'] );

            // ── v4.3: Tool Registry Verification — before using "chưa có trợ lý"
            // fallback template, check if the user's request matches an EXISTING tool
            // in bizcity_tool_registry. If it does, don't tell the user the tool doesn't
            // exist — instead inform LLM the tool IS available and guide the user to use it.
            // v4.3.4: Reuse Router's search result when available (same data, better scoring).
            $matching_tool_in_registry = null;
            $router_reg = $engine_result['meta']['_router_registry_search'] ?? null;
            if ( ! $has_provider_instructions && $router_reg && ! empty( $router_reg['best_match'] ) ) {
                // Router already found a match (may be below its threshold=10, but still valid for suggest)
                $best = $router_reg['best_match'];
                if ( ( $best['score'] ?? 0 ) >= 5 && class_exists( 'BizCity_Intent_Tool_Index' ) ) {
                    $all_tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
                    foreach ( $all_tools as $t_row ) {
                        if ( ( $t_row['goal'] ?? '' ) === $best['goal'] ) {
                            $matching_tool_in_registry = $t_row;
                            break;
                        }
                    }
                }
            } elseif ( ! $has_provider_instructions && ! $router_reg && class_exists( 'BizCity_Intent_Tool_Index' ) ) {
                // Router didn't run registry search (e.g. LLM/regex found a goal) → fallback keyword scan
                $msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
                $msg_words = array_filter(
                    preg_split( '/[\s,;.!?]+/u', $msg_lower ),
                    function( $w ) { return mb_strlen( $w, 'UTF-8' ) >= 2; }
                );
                if ( ! empty( $msg_words ) ) {
                    $all_tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
                    foreach ( $all_tools as $t_row ) {
                        $search_fields = mb_strtolower(
                            ( $t_row['goal'] ?? '' ) . ' ' . ( $t_row['title'] ?? '' ) . ' '
                            . ( $t_row['goal_label'] ?? '' ) . ' ' . ( $t_row['custom_hints'] ?? '' ) . ' '
                            . ( $t_row['goal_description'] ?? '' ) . ' ' . ( $t_row['plugin'] ?? '' ),
                            'UTF-8'
                        );
                        foreach ( $msg_words as $kw ) {
                            if ( mb_strpos( $search_fields, $kw ) !== false && mb_strlen( $kw, 'UTF-8' ) >= 3 ) {
                                $matching_tool_in_registry = $t_row;
                                break 2;
                            }
                        }
                    }
                }
            }

            // ── v4.3: Log tool_registry_verify pipe step for Intent Monitor ──
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                $verify_outcome = 'skip';
                $verify_detail  = '';
                if ( $has_provider_instructions ) {
                    $verify_outcome = 'provider_mode';
                    $verify_detail  = 'Provider instructions active — no fallback needed';
                } elseif ( $matching_tool_in_registry ) {
                    $match_label = $matching_tool_in_registry['goal_label']
                        ?: $matching_tool_in_registry['title']
                        ?: $matching_tool_in_registry['tool_name'];
                    $verify_outcome = 'TOOL_EXISTS';
                    $verify_detail  = $match_label . ' (plugin: ' . ( $matching_tool_in_registry['plugin'] ?? '' ) . ')';
                } else {
                    $verify_outcome = 'no_match';
                    $verify_detail  = 'No tool found → using "chưa có trợ lý" fallback template';
                }
                BizCity_User_Memory::log_router_event( [
                    'step'             => 'tool_registry_verify',
                    'message'          => mb_substr( $message, 0, 120, 'UTF-8' ),
                    'mode'             => 'stream → end_reminder',
                    'method'           => 'keyword_scan',
                    'functions_called' => 'BizCity_Intent_Tool_Index::get_all_active() → keyword match',
                    'pipeline'         => [
                        'check_provider_instructions',
                        'extract_keywords',
                        'scan_tool_registry',
                        'outcome:' . $verify_outcome,
                    ],
                    'response_preview' => $verify_outcome . ( $verify_detail ? ' → ' . $verify_detail : '' ),
                    'outcome'          => $verify_outcome,
                    'matched_tool'     => $matching_tool_in_registry ? [
                        'goal'       => $matching_tool_in_registry['goal'] ?? '',
                        'goal_label' => $matching_tool_in_registry['goal_label'] ?? '',
                        'plugin'     => $matching_tool_in_registry['plugin'] ?? '',
                        'title'      => $matching_tool_in_registry['title'] ?? '',
                    ] : null,
                    'file_line'        => 'class-intent-stream.php::end_reminder_tool_verify',
                ], $session_id );
            }

            if ( $has_provider_instructions ) {
                $end_reminder .= "\n## 📋 HƯỚNG DẪN:\n";
                $end_reminder .= "→ BẠN ĐANG TRONG CHẾ ĐỘ CHUYÊN GIA — hãy thực hiện ĐÚNG vai trò chuyên gia đã được giao ở phần trên.\n";
                $end_reminder .= "→ KHÔNG gợi ý Chợ AI Agent. KHÔNG nói 'mình chưa có trợ lý'. Chức năng ĐÃ CÓ SẴN.\n";
            } elseif ( $matching_tool_in_registry ) {
                // v4.3→v5.0: Tool EXISTS in registry but classification failed.
                // Instead of suggesting the tool, answer the question and add follow-up questions.
                $end_reminder .= "\n## 📋 HƯỚNG DẪN:\n";
                $end_reminder .= "→ TUYỆT ĐỐI KHÔNG nói 'mình chưa có trợ lý chuyên về...'.\n";
                $end_reminder .= "→ HÃY TRẢ LỜI câu hỏi dựa trên hiểu biết của bạn.\n";
                $end_reminder .= "→ KHÔNG gợi ý công cụ, KHÔNG gợi ý Chợ AI Agent, KHÔNG hỏi 'Bạn có muốn dùng công cụ X không?'.\n";
                $end_reminder .= "→ Cuối câu trả lời, hãy đặt 1-2 câu hỏi gợi mở để Chủ Nhân đào sâu thêm vào vấn đề đang thảo luận.\n";

                error_log( '[bizcity-intent-stream] v5.0: Tool registry match — suppressed tool suggestion, using follow-up questions instead' );
            } else {
                $end_reminder .= "\n## 📋 MẪU TRẢ LỜI FALLBACK — khi chức năng CHƯA CÓ trên hệ thống:\n";
                $end_reminder .= "Ví dụ: nghe nhạc, phát nhạc, xem phim, đặt hàng, chuyển khoản, gọi điện, tra thời tiết, giá cổ phiếu, tìm đường, đặt lịch, gửi email, quản lý kho, thiết kế ảnh...\n";
                $end_reminder .= "→ Trả lời ĐÚNG mẫu sau (thay [chức năng] bằng tên chức năng user yêu cầu):\n";
                $end_reminder .= "  'Hiện tại mình chưa có trợ lý chuyên về [chức năng]. Nhưng bạn có thể vào **Chợ AI Agent** của BizCity để chọn một trợ lý phù hợp — sau khi kích hoạt, mình sẽ phối hợp với Agent đó để giúp bạn thực hiện công việc này! 🚀'\n";
                $end_reminder .= "→ KHÔNG nói 'không có quyền', KHÔNG nói 'liên hệ hỗ trợ', KHÔNG đổ lỗi cho người dùng.\n";
                $end_reminder .= "→ Luôn thể hiện tinh thần: 'Mình là Team Leader của bạn — việc gì cũng có cách giải quyết!'\n";
            }
        } else {
            $end_reminder .= "\n## 📋 HƯỚNG DẪN TRẢ LỜI VỚI KIẾN THỨC MỞ RỘNG:\n";
            $end_reminder .= "→ BẮT BUỘC sử dụng nội dung từ phần **🔍 KIẾN THỨC MỞ RỘNG** ở trên làm câu trả lời CHÍNH.\n";
            $end_reminder .= "→ Trình bày lại tự nhiên, có cảm xúc, phù hợp phong cách Team Leader.\n";
            $end_reminder .= "→ KHÔNG gợi ý Chợ AI Agent. KHÔNG nói 'mình chưa có trợ lý'. Kiến thức ĐÃ CÓ.\n";
        }
        $system_content .= $end_reminder;

        // ── v5.0: Twin Suggest — follow-up questions based on memory + session context ──
        if ( class_exists( 'BizCity_Twin_Suggest' ) && ! $has_provider_instructions ) {
            $twin_mode = $engine_result['meta']['focus_mode'] ?? $engine_result['mode'] ?? '';
            $twin_suggest_block = BizCity_Twin_Suggest::build( [
                'user_id'       => $user_id,
                'session_id'    => $session_id,
                'message'       => $message,
                'mode'          => $twin_mode,
                'engine_result' => $engine_result,
            ] );
            if ( ! empty( $twin_suggest_block ) ) {
                $system_content .= "\n\n" . $twin_suggest_block;
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // 🎯 COMPLETION HINT — AI personalizes completion message using memory
        // When a tool/task is completed, instead of hardcoded "Cảm ơn bạn",
        // AI generates a response that respects user memory (xưng hô, phong cách)
        // ══════════════════════════════════════════════════════════════════
        if ( ! empty( $engine_result['meta']['completion_hint'] ) ) {
            $hint_label = $engine_result['goal_label'] ?? $engine_result['goal'] ?? '';
            $system_content .= "\n\n# 🎯 NHIỆM VỤ HIỆN TẠI — TRẢ LỜI XÁC NHẬN HOÀN THÀNH:\n";
            $system_content .= "User vừa hoàn thành một tác vụ" . ( $hint_label ? " (\"" . $hint_label . "\")" : '' ) . ".\n";
            $system_content .= "Hãy tạo lời xác nhận hoàn thành NGẮN GỌN (1-2 câu).\n";
            $system_content .= "BẮT BUỘC sử dụng đúng cách xưng hô và phong cách từ KÝ ỨC USER ở trên.\n";
            $system_content .= "Bắt đầu bằng ✅, giữ thông điệp tích cực, thân thiện.\n";
            $system_content .= "KHÔNG dùng 'bạn' nếu user đã dặn cách xưng hô khác (ví dụ: anh/em).\n";
        }

        $openai_messages[] = [ 'role' => 'system', 'content' => $system_content ];

        // ── Conversation history ──
        if ( ! empty( $engine_result['conversation_id'] ) ) {
            $conv_mgr = BizCity_Intent_Conversation::instance();
            $turns    = $conv_mgr->get_turns( $engine_result['conversation_id'], 10 );

            // Find the last user turn index — this is the CURRENT message
            // being processed. It will be re-added below with proper
            // image/multimodal formatting, so skip it to avoid duplication.
            $last_user_idx = null;
            foreach ( $turns as $i => $turn ) {
                if ( $turn['role'] === 'user' ) {
                    $last_user_idx = $i;
                }
            }

            foreach ( $turns as $i => $turn ) {
                if ( $turn['role'] === 'system' || $turn['role'] === 'tool' ) {
                    continue;
                }
                if ( $i === $last_user_idx ) {
                    continue; // Current message — added below with images
                }

                // ── v3.7: Include image attachments in historical user turns ──
                // Previously only text was included, so the LLM couldn't see
                // images sent in earlier turns (e.g. tarot card photos).
                $turn_attachments = $turn['attachments'] ?? [];
                if ( $turn['role'] === 'user' && ! empty( $turn_attachments ) ) {
                    $parts = [];
                    $parts[] = [ 'type' => 'text', 'text' => $turn['content'] ?: '[Image]' ];
                    foreach ( $turn_attachments as $att ) {
                        $att_url = is_string( $att ) ? $att : ( $att['url'] ?? $att['data'] ?? '' );
                        if ( $att_url ) {
                            $parts[] = [ 'type' => 'image_url', 'image_url' => [ 'url' => $att_url, 'detail' => 'auto' ] ];
                        }
                    }
                    $openai_messages[] = [ 'role' => 'user', 'content' => $parts ];
                } else {
                    $openai_messages[] = [
                        'role'    => $turn['role'] === 'user' ? 'user' : 'assistant',
                        'content' => $turn['content'],
                    ];
                }
            }
        }

        // ── Current user message ──
        $supports_vision = true; // Assume true — model registry will validate
        if ( ! empty( $images ) && $supports_vision ) {
            $content   = [];
            $content[] = [ 'type' => 'text', 'text' => $message ?: 'Hãy mô tả hoặc phân tích hình ảnh này.' ];
            foreach ( $images as $img ) {
                $url = is_string( $img ) ? $img : ( $img['url'] ?? $img['data'] ?? '' );
                if ( $url ) {
                    $content[] = [ 'type' => 'image_url', 'image_url' => [ 'url' => $url, 'detail' => 'auto' ] ];
                }
            }
            $openai_messages[] = [ 'role' => 'user', 'content' => $content ];
        } else {
            $openai_messages[] = [ 'role' => 'user', 'content' => $message ];
        }

        return $openai_messages;
    }

    /**
     * Get model options for streaming based on character config.
     *
     * @param int $character_id
     * @return array
     */
    private function get_model_options( $character_id ) {
        $options = [
            'purpose'     => 'chat',
            'temperature' => 0.7,
            'max_tokens'  => 3000,
        ];

        if ( $character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $character = BizCity_Knowledge_Database::instance()->get_character( $character_id );
            if ( $character ) {
                if ( ! empty( $character->model_id ) ) {
                    $options['model'] = $character->model_id;
                }
                if ( isset( $character->creativity_level ) ) {
                    $options['temperature'] = floatval( $character->creativity_level );
                }
                if ( ! empty( $character->max_tokens ) ) {
                    $options['max_tokens'] = intval( $character->max_tokens );
                }
            }
        }

        return $options;
    }

    /* ================================================================
     *  OpenAI Direct Streaming — fallback when OpenRouter is unavailable
     *
     *  Uses cURL + SSE to stream from api.openai.com directly,
     *  with the site's twf_openai_api_key.
     * ================================================================ */

    /**
     * Stream a chat completion directly from OpenAI API.
     *
     * Mirrors the pattern used in BizCity_OpenRouter::chat_stream() but
     * targets the OpenAI endpoint instead.
     *
     * @param array  $messages      OpenAI-format messages.
     * @param array  $model_options { model, temperature, max_tokens }
     * @param string $api_key       OpenAI API key (twf_openai_api_key).
     * @return array { success, message, model, provider, usage, error }
     */
    private function openai_direct_stream( array $messages, array $model_options, string $api_key ): array {
        $model = $model_options['model'] ?? get_option( 'openai_model', 'gpt-4o-mini' );

        // OpenRouter model IDs have a prefix (e.g. 'openai/gpt-4o') → strip for direct OpenAI calls
        if ( strpos( $model, '/' ) !== false ) {
            $parts = explode( '/', $model );
            $model = end( $parts );
        }

        $base_result = [
            'success'  => false,
            'message'  => '',
            'model'    => $model,
            'provider' => 'openai',
            'usage'    => [],
            'error'    => '',
        ];

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => floatval( $model_options['temperature'] ?? 0.7 ),
            'max_tokens'  => intval( $model_options['max_tokens'] ?? 3000 ),
            'stream'      => true,
            'stream_options' => [ 'include_usage' => true ],
        ];

        $full_text = '';
        $usage     = [];
        $buffer    = '';
        $self      = $this;

        $ch = curl_init( 'https://api.openai.com/v1/chat/completions' );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$full_text, &$usage, &$buffer, $self ) {
                $buffer .= $data;

                // Process complete SSE lines from buffer
                while ( ( $nl = strpos( $buffer, "\n" ) ) !== false ) {
                    $line   = trim( substr( $buffer, 0, $nl ) );
                    $buffer = substr( $buffer, $nl + 1 );

                    if ( $line === '' || strpos( $line, 'data: ' ) !== 0 ) {
                        continue;
                    }
                    $json_str = substr( $line, 6 ); // strip "data: "
                    if ( $json_str === '[DONE]' ) {
                        continue;
                    }
                    $chunk = json_decode( $json_str, true );
                    if ( ! is_array( $chunk ) ) {
                        continue;
                    }

                    // Stream error from API
                    if ( isset( $chunk['error'] ) ) {
                        error_log( '[bizcity-intent-stream] OpenAI stream error: ' . wp_json_encode( $chunk['error'] ) );
                        continue;
                    }

                    $delta = $chunk['choices'][0]['delta']['content'] ?? '';
                    if ( $delta !== '' ) {
                        $full_text .= $delta;
                        $self->send_sse_event( 'chunk', [
                            'delta' => $delta,
                            'full'  => $full_text,
                        ] );
                    }

                    if ( isset( $chunk['usage'] ) ) {
                        $usage = $chunk['usage'];
                    }
                }
                return strlen( $data );
            },
        ] );

        $ok        = curl_exec( $ch );
        $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_err  = curl_error( $ch );
        curl_close( $ch );

        if ( ! $ok || $http_code < 200 || $http_code >= 300 ) {
            $base_result['error'] = $curl_err ?: "HTTP {$http_code}";
            error_log( '[bizcity-intent-stream] OpenAI direct cURL error: ' . $base_result['error'] );
            if ( ! empty( $full_text ) ) {
                // Partial success — return what we got
                $base_result['success'] = true;
                $base_result['message'] = $full_text;
                $base_result['usage']   = $usage;
            }
            return $base_result;
        }

        $base_result['success'] = true;
        $base_result['message'] = $full_text;
        $base_result['usage']   = $usage;
        return $base_result;
    }

    /* ================================================================
     *  Automation Bridge — fire bizcity_chat_message_processed
     *
     *  The SSE handler exits before Chat Gateway (priority 20) runs,
     *  so bizcity_chat_message_processed is never fired from the
     *  streaming path. This helper bridges intent SSE → automation
     *  triggers (waic_twf_process_flow) via the existing gateway
     *  bridge in bizcity-admin-hook-zalo/gateway-functions.php.
     * ================================================================ */

    /**
     * Fire bizcity_chat_message_processed for automation trigger bridge.
     *
     * @param string $user_message  Original user message.
     * @param string $bot_reply     Bot reply text.
     * @param array  $context       Contextual data (session_id, user_id, platform_type, character_id, images, plugin_slug).
     */
    private function fire_chat_processed( $user_message, $bot_reply, array $context ) {
        // Use output buffering to prevent stray output from corrupting SSE stream
        ob_start();
        do_action( 'bizcity_chat_message_processed', [
            'platform_type' => $context['platform_type'] ?? 'WEBCHAT',
            'session_id'    => $context['session_id'] ?? '',
            'character_id'  => $context['character_id'] ?? 0,
            'user_id'       => $context['user_id'] ?? 0,
            'user_message'  => $user_message,
            'bot_reply'     => $bot_reply,
            'images'        => $context['images'] ?? [],
            'provider'      => $context['provider'] ?? 'intent',
            'model'         => $context['model'] ?? '',
            'plugin_slug'   => $context['plugin_slug'] ?? '',
        ] );
        ob_end_clean();
    }

    /* ================================================================
     *  SSE Helpers
     * ================================================================ */

    /**
     * Prepare HTTP headers for SSE streaming.
     */
    private function prepare_stream_headers() {
        // ── Disable PHP output compression (gzip) that would buffer everything ──
        @ini_set( 'zlib.output_compression', 'Off' );
        @ini_set( 'implicit_flush', 1 );

        // Disable all output buffering
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // Prevent WordPress from buffering
        if ( ! defined( 'DOING_AJAX' ) ) {
            define( 'DOING_AJAX', true );
        }

        header( 'Content-Type: text/event-stream; charset=UTF-8' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );   // Nginx proxy
        header( 'Content-Encoding: none' );   // Prevent Apache/LiteSpeed mod_deflate
        header( 'Access-Control-Allow-Origin: *' );

        // Prevent PHP timeout
        set_time_limit( 120 );

        // MUST be true — if false, PHP aborts mid-curl-callback when proxy
        // closes the idle connection, causing "Failure writing output to
        // destination, passed N returned 0".
        ignore_user_abort( true );

        // Suppress display_errors during SSE — stray warning output (e.g.
        // session_start from 3rd-party plugins) corrupts the event-stream
        // format and makes the browser EventSource disconnect.
        @ini_set( 'display_errors', '0' );

        // Apache/LiteSpeed: disable mod_deflate / mod_gzip
        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv( 'no-gzip', '1' );
        }

        // ── Flush proxy buffers ──
        // LiteSpeed / FastCGI proxies buffer 4–8 KB before forwarding to client.
        // Send a large SSE comment to fill that buffer and force the first flush,
        // so subsequent smaller events stream through immediately.
        echo ': stream-start' . str_repeat( ' ', 8192 ) . "\n\n";
        if ( ob_get_level() > 0 ) { ob_flush(); }
        flush();
    }

    /**
     * Send an SSE event.
     *
     * @param string $event Event name ('chunk', 'done', 'error').
     * @param array  $data  Data payload.
     */
    public function send_sse_event( $event, array $data ) {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        $payload = "event: {$event}\ndata: {$json}\n\n";
        echo $payload;

        if ( ob_get_level() > 0 ) {
            ob_flush();
        }
        flush();

        // Debug trace for first few chunks + done/error events
        static $send_count = 0;
        $send_count++;
        if ( $send_count <= 3 || $event === 'done' || $event === 'error' ) {
            error_log( sprintf( '[bizcity-sse] #%d event=%s bytes=%d data=%s',
                $send_count, $event, strlen( $payload ), mb_substr( $json, 0, 200 ) ) );
        }
    }

    /**
     * Send SSE stream end signal.
     */
    private function send_sse_done() {
        echo "event: close\n";
        echo "data: {}\n\n";

        if ( ob_get_level() > 0 ) {
            ob_flush();
        }
        flush();
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    /**
     * Map platform_type to channel name.
     *
     * @param string $platform_type
     * @return string
     */
    private function platform_to_channel( $platform_type ) {
        $map = [
            'WEBCHAT'       => 'webchat',
            'ADMINCHAT'     => 'adminchat',
            'ZALO_BOT'      => 'zalo',
            'ZALO_PERSONAL' => 'zalo',
            'TELEGRAM'      => 'telegram',
            'FACEBOOK'      => 'facebook',
        ];
        return $map[ strtoupper( $platform_type ) ] ?? 'webchat';
    }

    /**
     * Log a message to bizcity_webchat_messages (unified chat history).
     *
     * Delegates to BizCity_WebChat_Database::log_message() if available,
     * otherwise falls back to BizCity_Chat_Gateway::log_message().
     *
     * @param array $data {
     *   @type string $session_id
     *   @type int    $user_id
     *   @type string $client_name
     *   @type string $message_id
     *   @type string $message_text
     *   @type string $message_from   'user' | 'bot'
     *   @type string $message_type   'text' | 'image'
     *   @type array  $attachments
     *   @type string $platform_type
     *   @type array  $meta
     * }
     */
    private function log_webchat_message( $data ) {
        // Passthrough project_id from NOTEBOOK platform if available in request
        if ( empty( $data['project_id'] ) && ! empty( $_REQUEST['notebook_project_id'] ) ) {
            $data['project_id'] = sanitize_text_field( $_REQUEST['notebook_project_id'] );
        }
        try {
            if ( class_exists( 'BizCity_WebChat_Database' ) ) {
                $result = BizCity_WebChat_Database::instance()->log_message( $data );
                // Return the DB row ID if the logger provides it
                if ( is_numeric( $result ) ) {
                    return (int) $result;
                }
                // Fallback: fetch the last inserted ID
                global $wpdb;
                return (int) $wpdb->insert_id;
            }

            // Fallback: direct insert
            global $wpdb;
            $table = $wpdb->prefix . 'bizcity_webchat_messages';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
                return 0;
            }
            $wpdb->insert( $table, [
                'conversation_id'        => 0,
                'session_id'             => $data['session_id'] ?? '',
                'user_id'                => $data['user_id'] ?? 0,
                'client_name'            => $data['client_name'] ?? '',
                'message_id'             => $data['message_id'] ?? '',
                'message_text'           => $data['message_text'] ?? '',
                'message_from'           => $data['message_from'] ?? 'user',
                'message_type'           => $data['message_type'] ?? 'text',
                'plugin_slug'            => $data['plugin_slug'] ?? '',
                'intent_conversation_id' => $data['intent_conversation_id'] ?? '',
                'attachments'            => is_array( $data['attachments'] ?? null ) ? wp_json_encode( $data['attachments'] ) : '',
                'platform_type'          => $data['platform_type'] ?? 'WEBCHAT',
                'project_id'             => $data['project_id'] ?? '',
                'meta'                   => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : '',
            ] );
            return (int) $wpdb->insert_id;
        } catch ( \Exception $e ) {
            error_log( '[bizcity-intent-stream] log_webchat_message error: ' . $e->getMessage() );
            return 0;
        }
    }

    /**
     * Check if the user message is a capability/help query.
     */
    private function is_capability_query( string $message ): bool {
        $normalized = mb_strtolower( trim( $message ) );

        $patterns = [
            // Vietnamese
            'bạn (có thể|biết) làm (gì|những gì)',
            'bạn làm được (gì|những gì)',
            'có (những )?công cụ (gì|nào)',
            'có (những )?tính năng (gì|nào)',
            'danh sách (công cụ|tính năng|chức năng)',
            'giúp (tôi|mình) (được )?(gì|những gì)',
            'menu',
            // English
            'what can you do',
            'what tools',
            'list (tools|capabilities|features)',
            'help me with what',
            'show (tools|capabilities|features)',
        ];

        $regex = '/(' . implode( '|', $patterns ) . ')/u';
        return (bool) preg_match( $regex, $normalized );
    }

    /**
     * Build HTML response listing active tools as clickable pill buttons.
     */
    private function build_capability_response(): string {
        $tools = [];
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
        }

        if ( empty( $tools ) ) {
            return 'Hiện tại chưa có công cụ nào được kích hoạt. Bạn có thể trò chuyện bình thường với tôi!';
        }

        $colors = [ '#4A90D9', '#D94A7A', '#4AD99B', '#D9A84A', '#9B4AD9', '#4AD9D9', '#D95A4A', '#7AD94A' ];
        $html   = '<div class="bizc-tool-caps">';
        $html  .= '<p>Tôi có thể hỗ trợ bạn với các công cụ sau:</p>';

        foreach ( $tools as $i => $tool ) {
            $goal  = esc_attr( $tool['goal'] ?? $tool['tool_key'] ?? '' );
            $label = esc_html( $tool['goal_label'] ?? $tool['tool_name'] ?? $goal );
            $color = $colors[ $i % count( $colors ) ];
            $html .= sprintf(
                '<button class="bizc-tool-cap-btn" data-goal="%s" style="--btn-color:%s">%s</button>',
                $goal,
                $color,
                $label
            );
        }

        $html .= '</div>';
        $html .= '<p>Bấm vào công cụ bạn muốn sử dụng, hoặc mô tả yêu cầu bằng lời!</p>';
        return $html;
    }
}
