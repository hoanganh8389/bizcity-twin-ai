<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent — Main Engine (Orchestrator)
 *
 * @deprecated Since Phase 1.11 — Shell Engine (đang active 100%) bypass toàn bộ logic trong file này.
 *   - Shell intercept tại process() line ~135: `BizCity_Intent_Engine_Shell::should_handle()` return true.
 *   - Smart Classifier (2-mode: single|multi) thay thế legacy 5-mode classifier.
 *   - 6488 dòng code sau line 135 là DEAD CODE khi Shell ON.
 *   - File này GIỮ LẠI vì class `BizCity_Intent_Engine` vẫn là entry point (process() delegate to Shell).
 *   - KHÔNG sửa logic trong file này. Mọi thay đổi → class-intent-engine-shell.php.
 *
 * The central coordinator that ties all components together:
 *   Conversation Manager ↔ Intent Router ↔ Flow Planner ↔ Tool Registry ↔ Stream Adapter
 *
 * Processing flow:
 *   1. Receive message (from any channel)
 *   2. Get/create conversation (Conversation Manager)
 *   3. Classify intent (Intent Router)
 *   4. Determine next action (Flow Planner)
 *   5. Execute action:
 *      a. ask_user → prompt user for missing slot and set WAITING_USER
 *      b. call_tool → execute tool, then compose response
 *      c. compose_answer → delegate to AI brain (Chat Gateway)
 *      d. complete → close conversation gracefully
 *      e. passthrough → small talk, forward to AI brain
 *   6. Update conversation state
 *   7. Return response
 *
 * Hooks into class-chat-gateway.php via `bizcity_chat_pre_ai_response` filter.
 *
 * @package BizCity_Intent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Engine {

    /** @var self|null */
    private static $instance = null;

    /** @var BizCity_Intent_Conversation */
    private $conversation_mgr;

    /** @var BizCity_Intent_Router */
    private $router;

    /** @var BizCity_Intent_Planner */
    private $planner;

    /** @var BizCity_Intent_Tools */
    private $tools;

    /** @var BizCity_Intent_Stream */
    private $stream;

    /** @var BizCity_Intent_Logger */
    private $logger;

    /** @var BizCity_Mode_Classifier */
    private $mode_classifier;

    /** @var BizCity_Mode_Pipeline_Registry */
    private $pipelines;

    /** @var BizCity_Intent_Clarify_Gate|null */
    private $clarify_gate;

    /** @var BizCity_Objective_Parser|null */
    private $objective_parser;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->conversation_mgr  = BizCity_Intent_Conversation::instance();
        $this->router            = BizCity_Intent_Router::instance();
        $this->planner           = BizCity_Intent_Planner::instance();
        $this->tools             = BizCity_Intent_Tools::instance();
        $this->stream            = BizCity_Intent_Stream::instance();
        $this->logger            = BizCity_Intent_Logger::instance();
        $this->mode_classifier   = BizCity_Mode_Classifier::instance();
        $this->pipelines         = BizCity_Mode_Pipeline_Registry::instance();
        $this->clarify_gate      = class_exists( 'BizCity_Intent_Clarify_Gate' ) ? BizCity_Intent_Clarify_Gate::instance() : null;
        $this->objective_parser  = class_exists( 'BizCity_Objective_Parser' ) ? BizCity_Objective_Parser::instance() : null;

        // Hook into Chat Gateway's pre-AI filter (priority 5 = before tarot at 10)
        add_filter( 'bizcity_chat_pre_ai_response', [ $this, 'intercept_chat' ], 5, 2 );

        // Hook: after message processed → update summary
        add_action( 'bizcity_chat_message_processed', [ $this, 'post_process' ], 10, 1 );

        // Periodic cleanup of expired conversations
        add_action( 'wp_loaded', [ $this, 'maybe_cleanup' ] );

        // AJAX endpoints for dashboard conversation list/turns
        $this->register_ajax_endpoints();
    }

    /* ================================================================
     *  Main entry point — process a message
     * ================================================================ */

    /**
     * Process a message through the full intent pipeline.
     *
     * @param array $params {
     *   @type string $message        User message text.
     *   @type string $session_id     Session identifier.
     *   @type int    $user_id        WordPress user ID (0 = guest).
     *   @type string $channel        'webchat' | 'adminchat' | 'zalo' | 'telegram' | 'facebook'
     *   @type int    $character_id   AI character ID.
     *   @type array  $images         Attached images.
     *   @type array  $extra          Extra context.
     * }
     * @return array {
     *   @type string $reply
     *   @type string $action         Engine action: chat | ask_user | call_tool | complete | passthrough
     *   @type string $conversation_id
     *   @type string $goal
     *   @type string $goal_label
     *   @type string $status
     *   @type array  $slots
     *   @type array  $meta
     * }
     */
    public function process( array $params ) {
        // ── Phase 1.11 S4: Shell Engine feature flag ──
        if ( class_exists( 'BizCity_Intent_Engine_Shell' ) && BizCity_Intent_Engine_Shell::should_handle() ) {
            return BizCity_Intent_Engine_Shell::instance()->process( $params );
        }

        // ── Phase 1.11 S6: Shadow mode — run shell in background and log comparison ──
        $shadow_enabled = (bool) get_option( 'bizcity_shell_shadow', false );
        $shell_class_ok = class_exists( 'BizCity_Intent_Engine_Shell' );
        if ( $shadow_enabled || $shell_class_ok ) {
            error_log( '[Shell:Shadow-Check] shadow_opt=' . ( $shadow_enabled ? 'ON' : 'OFF' ) . ', class_exists=' . ( $shell_class_ok ? 'YES' : 'NO' ) );
        }
        if ( $shadow_enabled && $shell_class_ok ) {
            $this->run_shadow_comparison( $params );
        }

        $message      = $params['message']      ?? '';
        $session_id   = $params['session_id']   ?? '';
        $user_id      = intval( $params['user_id'] ?? 0 );
        $channel      = $params['channel']      ?? 'webchat';
        $character_id = intval( $params['character_id'] ?? 0 );
        $images       = $params['images']       ?? [];
        $message_id   = $params['message_id']   ?? '';
        $provider_hint = $params['provider_hint'] ?? '';
        $selected_skill = $params['selected_skill'] ?? '';
        $skill_path     = $params['skill_path']     ?? '';
        $slash_command  = $params['slash_command']  ?? '';

        // ── Resolve market slug → provider ID (e.g. 'bizcity-tarot' → 'tarot') ──
        if ( $provider_hint && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $provider_hint = BizCity_Intent_Provider_Registry::instance()->resolve_slug( $provider_hint );
        }

        $result = [
            'reply'           => '',
            'action'          => 'passthrough',
            'conversation_id' => '',
            'channel'         => $channel,
            'goal'            => '',
            'goal_label'      => '',
            'status'          => 'ACTIVE',
            'slots'           => [],
            'rolling_summary' => '',
            'meta'            => [],
        ];

        // ── Begin pipeline trace ──
        $this->logger->begin_trace( '', 0, $user_id, $channel );
        $this->logger->log( 'input', [
            'message'      => mb_substr( $message, 0, 200 ),
            'channel'      => $channel,
            'user_id'      => $user_id,
            'has_images'   => count( $images ),
        ] );

        // ── Begin prompt context file logger (v3.9.0) ──
        if ( class_exists( 'BizCity_Prompt_Context_Logger' ) ) {
            BizCity_Prompt_Context_Logger::instance()->begin_trace(
                $user_id,
                $this->logger->get_trace_id()
            );
        }

        // ── Initialize Execution Logger session ──
        BizCity_Execution_Logger::set_session( $session_id ?: 'user_' . $user_id );

        // ── Step 1: Get/create conversation ──
        $conversation = $this->conversation_mgr->get_or_create(
            $user_id, $channel, $session_id, $character_id
        );

        $conv_id = $conversation['conversation_id'];
        $result['conversation_id'] = $conv_id;

        // ── Step 1.4: IMAGE URL DETECTION ──
        // When user sends a message containing image URLs (e.g. "https://example.com/photo.jpg")
        // but no file attachments, extract URLs into $images so the attachment-first flow can
        // handle them identically to uploaded images. This covers:
        //   - Direct image links pasted in chat
        //   - WordPress media URLs
        //   - CDN image URLs
        // Only activates when $images is empty (no file attachments already provided).
        if ( empty( $images ) ) {
            $url_extract = $this->extract_image_urls_from_text( $message );
            if ( ! empty( $url_extract['urls'] ) ) {
                $images             = $url_extract['urls'];
                $message            = $url_extract['remaining_text'];
                $params['images']   = $images;
                $params['message']  = $message;

                $this->logger->log( 'image_url_extract', [
                    'extracted_count' => count( $images ),
                    'urls'            => array_map( function( $u ) { return mb_substr( $u, 0, 120, 'UTF-8' ); }, $images ),
                    'remaining_text'  => mb_substr( $message, 0, 100, 'UTF-8' ),
                ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );
            }
        }

        // ── Step 1.5: ATTACHMENT-FIRST FLOW ──
        // When user sends images/files BEFORE any goal is established:
        //   A) Buffer attachments → ask what to do → WAITING_USER
        //   B) On next text message with buffered attachments → inject into $images
        $has_active_goal_s1  = ! empty( $conversation['goal'] );
        $is_waiting_user_s1  = ( $conversation['status'] ?? '' ) === 'WAITING_USER';
        $current_slots_s1    = json_decode( $conversation['slots_json'] ?? '{}', true ) ?: [];
        $pending_attachments = $current_slots_s1['_pending_attachments'] ?? [];
        $msg_trimmed_s1      = trim( $message );

        // Extra guard: skip buffer when already inside an active intent (HIL focus mode).
        // Covers cases where $conversation['goal'] may be empty but the frontend
        // already knows there's an active intent conversation or tool selection.
        $tool_goal_hint    = $params['tool_goal']               ?? '';
        $intent_conv_hint  = $params['intent_conversation_id']  ?? '';
        $has_hil_context   = $has_active_goal_s1
                           || ! empty( $tool_goal_hint )
                           || ! empty( $intent_conv_hint );

        // Step 1.5A: Image arrives, no active goal, text is minimal → buffer attachment
        // Also handles additional images sent while already waiting from a previous buffer.
        $is_pending_buffer = $is_waiting_user_s1 && ! empty( $pending_attachments );
        if ( ! empty( $images )
             && ! $has_hil_context
             && ( ! $is_waiting_user_s1 || $is_pending_buffer )
             && mb_strlen( $msg_trimmed_s1, 'UTF-8' ) < 10  // Short text like "giá 120k" alone isn't a goal
        ) {
            // Buffer the attachment URLs in conversation slots
            $buffered = array_merge( $pending_attachments, $images );
            $slot_update = [ '_pending_attachments' => $buffered ];

            // Store short text alongside if present (e.g. "giá 120k")
            if ( ! empty( $msg_trimmed_s1 ) ) {
                $prev_text = $current_slots_s1['_pending_text'] ?? '';
                $slot_update['_pending_text'] = $prev_text
                    ? $prev_text . ' ' . $msg_trimmed_s1
                    : $msg_trimmed_s1;
            }

            $this->conversation_mgr->update_slots( $conv_id, $slot_update );
            $this->conversation_mgr->set_waiting( $conv_id, 'text', '' );
            $this->conversation_mgr->increment_turn( $conv_id );

            $total = count( $buffered );
            $type_label = 'ảnh';
            $reply = "📎 Đã nhận {$total} {$type_label}! Bạn muốn làm gì với " . ( $total > 1 ? 'chúng' : 'nó' ) . "?\n"
                   . "💡 Ví dụ: \"tạo sản phẩm\", \"viết bài\", \"đăng bài\"...";

            $this->logger->log( 'attachment_buffer', [
                'buffered_count' => count( $buffered ),
                'has_text'       => ! empty( $msg_trimmed_s1 ),
                'text_snippet'   => mb_substr( $msg_trimmed_s1, 0, 50, 'UTF-8' ),
            ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

            $result['reply']  = $reply;
            $result['action'] = 'ask_user';
            $result['status'] = 'WAITING_USER';

            do_action( 'bizcity_intent_processed', $result, $params );
            return $result;
        }

        // Step 1.5B: Text message arrives, buffered attachments exist → inject into $images
        if ( ! empty( $pending_attachments ) && ! empty( $msg_trimmed_s1 ) ) {
            $images = array_merge( $images, $pending_attachments );
            $params['images'] = $images;

            // Also recover any buffered text (e.g. "giá 120k" from previous image message)
            $pending_text = $current_slots_s1['_pending_text'] ?? '';
            if ( ! empty( $pending_text ) && mb_strpos( $message, $pending_text ) === false ) {
                $message = $pending_text . ' ' . $message;
                $params['message'] = $message;
            }

            // Clear buffer — resume normal flow
            $this->conversation_mgr->resume( $conv_id );
            $this->conversation_mgr->update_slots( $conv_id, [
                '_pending_attachments' => null,
                '_pending_text'        => null,
            ] );

            $this->logger->log( 'attachment_inject', [
                'injected_count' => count( $pending_attachments ),
                'pending_text'   => $pending_text,
                'merged_message' => mb_substr( $message, 0, 100, 'UTF-8' ),
            ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

            // Refresh conversation after clearing buffer
            $conversation = $this->conversation_mgr->get_active( $user_id, $channel, $session_id );
            if ( ! $conversation ) {
                $conversation = $this->conversation_mgr->get_or_create(
                    $user_id, $channel, $session_id, $character_id
                );
            }
        }

        // ── Step 1.5C: IMAGE + TEXT SMART ROUTING ──
        // When image + meaningful text arrives with NO active goal, the engine
        // doesn't know if this is execution or question mode yet.
        // Strategy: force question/knowledge mode so AI answers immediately
        // using image + text, then suggest matching tools from registry.
        // Context uses session_id scope (not intent_conversation_id).
        // @since v4.3.5
        $has_active_goal_s15c = ! empty( $conversation['goal'] );
        if ( ! empty( $images )
             && mb_strlen( $msg_trimmed_s1, 'UTF-8' ) >= 10
             && ! $has_active_goal_s15c
             && ! $has_hil_context
        ) {
            // Store images in session-level slot for later tool execution
            $this->conversation_mgr->update_slots( $conv_id, [
                '_session_pending_images' => $images,
            ] );

            // Tool suggestion disabled — was often inaccurate.
            // Kept image buffering above so images flow to knowledge pipeline.
            /*
            $matched_tools = $this->find_matching_tools_for_suggest( $message );

            if ( ! empty( $matched_tools ) ) {
                $suggest_lines = [];
                foreach ( $matched_tools as $idx => $mt ) {
                    $suggest_lines[] = ( $idx + 1 ) . '. ' . $mt['tool_name'] . ': ' . $mt['label'];
                }
                $suggest_block = implode( "\n", $suggest_lines );

                $params['_image_text_suggest']       = true;
                $params['_image_text_matched_tools'] = $matched_tools;

                $this->conversation_mgr->update_slots( $conv_id, [
                    '_image_text_suggested_tools' => $matched_tools,
                ] );

                $this->logger->log( 'image_text_suggest', [
                    'image_count'    => count( $images ),
                    'message_len'    => mb_strlen( $message, 'UTF-8' ),
                    'matched_tools'  => array_column( $matched_tools, 'tool_name' ),
                ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );
            }

            $result['meta']['image_text_suggest'] = ! empty( $matched_tools );
            $result['meta']['suggest_tools']      = array_column( $matched_tools ?? [], 'tool_name' );
            */
        }

        // ── Step 1.6: POST-TOOL SATISFACTION DETECTION ──
        // When the previous conversation just completed a tool (within 2 min)
        // and the current conversation has NO active goal, check if user is
        // giving satisfaction feedback (e.g. "ok cảm ơn" or "sai rồi").
        // 0 LLM cost — regex-based detection in the Router.
        // @since v4.0.0 (Phase 13 — Dual Context Architecture)
        $has_active_goal_s16 = ! empty( $conversation['goal'] );
        if ( ! $has_active_goal_s16 ) {
            $recently_completed = $this->conversation_mgr->find_recently_completed( $user_id, $channel, $session_id );
            if ( $recently_completed ) {
                $satisfaction = $this->router->detect_post_tool_satisfaction( $message );
                if ( $satisfaction === 'satisfied' ) {
                    $completed_goal = $recently_completed['goal_label'] ?: $recently_completed['goal'];
                    $result['reply']  = '😊 Vui vì đã giúp được bạn! Cần gì thêm cứ nói nhé.';
                    $result['action'] = 'post_tool_satisfied';
                    $result['status'] = 'COMPLETED';
                    $result['goal']   = $recently_completed['goal'];
                    $result['meta']['post_tool']       = 'satisfied';
                    $result['meta']['completed_goal']   = $completed_goal;
                    $result['meta']['method']           = 'post_tool_satisfaction';

                    $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                    $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
                    $this->conversation_mgr->complete( $conv_id, 'Post-tool: user satisfied with ' . $completed_goal );

                    $this->logger->log( 'post_tool_satisfaction', [
                        'type'           => 'satisfied',
                        'completed_goal' => $completed_goal,
                        'message'        => mb_substr( $message, 0, 100, 'UTF-8' ),
                    ], $conv_id, 0, $user_id, $channel );

                    error_log( '[INTENT-ENGINE] Step 1.6: Post-tool satisfied → "' . $completed_goal . '" (0 LLM cost)' );
                    do_action( 'bizcity_intent_processed', $result, $params );
                    return $result;
                }

                if ( $satisfaction === 'retry' ) {
                    $completed_goal  = $recently_completed['goal_label'] ?: $recently_completed['goal'];
                    $prev_goal       = $recently_completed['goal'];
                    $prev_slots      = $recently_completed['slots'] ?? [];

                    // Re-open: set goal on current conversation so Planner picks it up
                    // Strip any existing leading emoji to avoid duplication (🔗 🔗 ...)
                    $clean_retry_label = preg_replace( '/^[\x{1F300}-\x{1F9FF}\x{2600}-\x{27BF}]+\s*/u', '', $completed_goal );
                    $this->conversation_mgr->set_goal( $conv_id, $prev_goal, $clean_retry_label );
                    if ( ! empty( $prev_slots ) ) {
                        $this->conversation_mgr->update_slots( $conv_id, $prev_slots );
                    }
                    $conversation['goal']       = $prev_goal;
                    $conversation['goal_label'] = $completed_goal;
                    $conversation['slots']      = $prev_slots;

                    $result['reply']  = '🔄 Để mình làm lại nhé! Bạn muốn thay đổi gì so với lần trước?';
                    $result['action'] = 'ask_user';
                    $result['status'] = 'WAITING_USER';
                    $result['goal']   = $prev_goal;
                    $result['meta']['post_tool']       = 'retry';
                    $result['meta']['completed_goal']   = $completed_goal;
                    $result['meta']['method']           = 'post_tool_satisfaction';

                    $this->conversation_mgr->set_waiting( $conv_id, 'text', 'message' );
                    $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                    $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );

                    $this->logger->log( 'post_tool_satisfaction', [
                        'type'           => 'retry',
                        'completed_goal' => $completed_goal,
                        'prev_slots'     => array_keys( $prev_slots ),
                        'message'        => mb_substr( $message, 0, 100, 'UTF-8' ),
                    ], $conv_id, 0, $user_id, $channel );

                    error_log( '[INTENT-ENGINE] Step 1.6: Post-tool retry → "' . $completed_goal . '" (re-opened)' );
                    do_action( 'bizcity_intent_processed', $result, $params );
                    return $result;
                }

                // ── Step 1.6C: POST-TOOL FOLLOW-UP → force knowledge mode ──
                // satisfaction === null → not a simple feedback message.
                // User is asking a follow-up question related to the just-completed tool.
                // Instead of letting the blank conversation re-classify (and likely pick the wrong tool),
                // force knowledge mode with the completed conversation context injected
                // so Chat Gateway can synthesize a deep, contextual answer.
                // @since v4.4.0 — Post-tool follow-up routing
                if ( $satisfaction === null ) {

                    // ── Escape hatch 0: slash command / tool_goal / selected_skill always wins ──
                    // If gateway already parsed a slash command (tool_goal_hint) OR
                    // the user explicitly selected a /skill from the UI (selected_skill),
                    // skip followup entirely — let Step 1.8+ handle execution.
                    // @since v4.6.3 — Slash commands must bypass post-tool followup
                    // @since v4.6.5 — selected_skill also bypasses (fix: /contentagentic stuck in 1.6C)
                    if ( ! empty( $tool_goal_hint ) || ! empty( $selected_skill ) ) {
                        error_log( '[INTENT-ENGINE] Step 1.6C: slash tool_goal="' . $tool_goal_hint
                            . '" selected_skill="' . $selected_skill
                            . '" → skip followup, normal classification' );
                        // Fall through — do NOT return
                    } else {

                    // ── Escape hatch 1: if the message matches ANY goal's
                    //    trigger patterns (same or different), let normal classification
                    //    handle it instead of forcing knowledge mode.
                    //    User saying "đăng bài viết" after a completed write_article
                    //    should start a fresh write_article, not a knowledge followup.
                    // @since v4.6.2 — Prevent followup intercepting new goals
                    $msg_lower_s16c  = mb_strtolower( trim( $message ), 'UTF-8' );
                    $goal_patterns   = $this->router->get_goal_patterns();
                    $new_goal_match  = null;
                    foreach ( $goal_patterns as $regex => $pcfg ) {
                        if ( @preg_match( $regex, $msg_lower_s16c ) ) {
                            $new_goal_match = $pcfg['goal'] ?? null;
                            break;
                        }
                    }
                    if ( $new_goal_match ) {
                        $this->logger->log( 'post_tool_new_goal_escape', [
                            'completed_goal' => $recently_completed['goal'],
                            'new_goal'       => $new_goal_match,
                            'message'        => mb_substr( $message, 0, 100, 'UTF-8' ),
                        ], $conv_id, 0, $user_id, $channel );
                        error_log( '[INTENT-ENGINE] Step 1.6C: Goal pattern matched "'
                            . $new_goal_match . '" → skip followup, normal classification' );
                        // Fall through — do NOT return; let Steps 2+ classify normally
                    } else {

                    $completed_goal   = $recently_completed['goal_label'] ?: $recently_completed['goal'];
                    $completed_summary = $recently_completed['rolling_summary'] ?? '';

                    // Unwrap nested followup: prefixes to get the original base goal
                    $base_goal = preg_replace( '/^(followup:)+/', '', $recently_completed['goal'] );

                    // Build context from the completed conversation's recent turns
                    $completed_conv_id = $recently_completed['conversation_id'] ?? '';
                    $completed_turns   = [];
                    if ( $completed_conv_id ) {
                        $completed_turns = $this->conversation_mgr->get_turns( $completed_conv_id, 10 );
                    }

                    $turns_text = '';
                    foreach ( $completed_turns as $turn ) {
                        $role    = ( $turn['role'] === 'user' ) ? 'User' : 'AI';
                        $content = mb_substr( $turn['content'] ?? '', 0, 500, 'UTF-8' );
                        $turns_text .= "{$role}: {$content}\n";
                    }

                    // Inject completed context into system prompt for Chat Gateway
                    $post_tool_context = "## BỐI CẢNH CÔNG CỤ VỪA HOÀN THÀNH\n"
                        . "Công cụ: {$completed_goal}\n";
                    if ( $completed_summary ) {
                        $post_tool_context .= "Tóm tắt: {$completed_summary}\n";
                    }
                    if ( $turns_text ) {
                        $post_tool_context .= "Lịch sử hội thoại:\n{$turns_text}\n";
                    }
                    $post_tool_context .= "Người dùng đang hỏi thêm về kết quả trên. "
                        . "Hãy trả lời dựa trên ngữ cảnh hội thoại trước đó, KHÔNG gọi công cụ mới.";

                    add_filter( 'bizcity_chat_system_prompt', function ( $prompt ) use ( $post_tool_context ) {
                        return $prompt . "\n\n" . $post_tool_context;
                    }, 42 );

                    // Store current user message as a turn
                    $this->conversation_mgr->add_turn( $conv_id, 'user', $message );

                    // Tag conversation with the completed goal for traceability
                    if ( empty( $conversation['goal'] ) ) {
                        // Strip ALL leading emojis + spaces to avoid duplication
                        $clean_label = preg_replace( '/^[\x{1F300}-\x{1F9FF}\x{2600}-\x{27BF}\s]+/u', '', $completed_goal );
                        $this->conversation_mgr->set_goal( $conv_id, 'followup:' . $base_goal, $clean_label );
                    }

                    $result['action'] = 'passthrough';
                    $result['status'] = 'ACTIVE';
                    $result['goal']   = 'followup:' . $base_goal;
                    $result['rolling_summary'] = $completed_summary;
                    $result['meta']['mode']               = 'knowledge';
                    $result['meta']['confidence']          = 0.85;
                    $result['meta']['objectives']          = [ mb_substr( $message, 0, 120, 'UTF-8' ) ];
                    $result['meta']['post_tool']           = 'followup';
                    $result['meta']['completed_goal']      = $completed_goal;
                    $result['meta']['method']              = 'post_tool_followup';
                    $result['meta']['completed_conv_id']   = $completed_conv_id;
                    $result['meta']['trace_id']            = $this->logger->get_trace_id();

                    $this->logger->log( 'post_tool_followup', [
                        'type'           => 'followup_to_knowledge',
                        'completed_goal' => $completed_goal,
                        'turns_injected' => count( $completed_turns ),
                        'message'        => mb_substr( $message, 0, 100, 'UTF-8' ),
                    ], $conv_id, 0, $user_id, $channel );

                    error_log( '[INTENT-ENGINE] Step 1.6C: Post-tool follow-up → knowledge mode'
                        . ' | completed_goal=' . $completed_goal
                        . ' | turns=' . count( $completed_turns ) );

                    $this->logger->end_trace( $result );
                    do_action( 'bizcity_intent_processed', $result, $params );
                    return $result;

                    } // end else (no new-goal escape)
                    } // end else (no slash tool_goal escape)
                }
            }
        }

        // ── Step 1.6D: SKILL CONTINUATION — re-inject skill context on follow-up turns ──
        // When conversation has an active skill:* goal (set by Step 2.4.10),
        // subsequent messages ("ok", "triển khai", "làm đi") should keep the skill
        // context injected into the LLM prompt. Without this, turn 2+ loses skill context
        // and the LLM responds generically ("bạn muốn tìm hiểu hay thực thi?").
        // Self-sufficient: extracts slug from goal, re-fetches from SkillManager.
        // @since v4.6.5 — Multi-turn skill persistence
        $conv_goal_s16d = $conversation['goal'] ?? '';
        if ( strpos( $conv_goal_s16d, 'skill:' ) === 0
             && empty( $selected_skill )       // Not a NEW /skill selection
             && empty( $slash_command )         // Not a NEW /command
             && empty( $tool_goal_hint )        // Not a NEW /tool_goal
             && class_exists( 'BizCity_Skill_Manager' )
        ) {
            $skill_slug_s16d = substr( $conv_goal_s16d, 6 ); // strip "skill:"

            // ── Execution escape hatch ──
            // If user's message contains strong execution intent (tạo workflow, chạy pipeline, etc.),
            // break out of skill continuation → let normal intent pipeline handle it.
            // This allows "tạo workflow đăng bài lên web rồi đăng facebook" to reach Router+Planner.
            $exec_escape_patterns = [
                'tạo workflow', 'tạo kịch bản', 'chạy pipeline', 'tạo pipeline',
                'tạo luồng', 'tạo automation', 'chạy workflow', 'tạo flow',
            ];
            $exec_escaped = false;
            $msg_lower = mb_strtolower( $message, 'UTF-8' );
            foreach ( $exec_escape_patterns as $ep ) {
                if ( mb_strpos( $msg_lower, $ep ) !== false ) {
                    $exec_escaped = true;
                    break;
                }
            }
            if ( $exec_escaped ) {
                // Clear skill goal so intent engine treats this as a fresh execution request
                $this->conversation_mgr->set_goal( $conv_id, '', '' );
                error_log( '[INTENT-ENGINE] Step 1.6D: Execution escape | skill=' . $skill_slug_s16d
                    . ' | msg=' . mb_substr( $message, 0, 80, 'UTF-8' )
                    . ' → falling through to intent pipeline' );
                // Fall through — do NOT return. Let mode classifier + Router handle this.
                // Keep skill_slug in meta so Router can reference the context if needed.
                $result['meta']['_pre_skill_slug']    = $skill_slug_s16d;
                $result['meta']['_pre_skill_escaped']  = true;
                goto step_1_7_after_skill;
            }

            // Try stored slots first, fallback to fresh SkillManager lookup
            $conv_slots_s16d = json_decode( $conversation['slots_json'] ?? '{}', true ) ?: [];
            $skill_title_s16d   = $conv_slots_s16d['_active_skill_title'] ?? '';
            $skill_content_s16d = $conv_slots_s16d['_active_skill_content'] ?? '';

            if ( empty( $skill_content_s16d ) ) {
                $fresh_match = \BizCity_Skill_Manager::instance()->find_matching( [
                    'slash_command' => $skill_slug_s16d,
                    'message'       => '',
                    'limit'         => 1,
                ] );
                if ( ! empty( $fresh_match ) ) {
                    $skill_content_s16d = $fresh_match[0]['content'] ?? '';
                    $skill_title_s16d   = $fresh_match[0]['frontmatter']['title'] ?? $skill_slug_s16d;

                    // Backfill slots for future turns
                    $this->conversation_mgr->update_slots( $conv_id, [
                        '_active_skill_slug'    => $skill_slug_s16d,
                        '_active_skill_title'   => $skill_title_s16d,
                        '_active_skill_content' => mb_substr( $skill_content_s16d, 0, 4000, 'UTF-8' ),
                    ] );
                }
            }

            if ( ! empty( $skill_content_s16d ) ) {
                if ( ! $skill_title_s16d ) {
                    $skill_title_s16d = $skill_slug_s16d;
                }

                // Use turn_count from DB (incremented by add_turn each request)
                $turn_count = (int) ( $conversation['turn_count'] ?? 0 );

                // Build prompt: skill content FIRST, then STRONG directive AFTER
                // (LLMs tend to follow the LAST instruction — put directives at bottom)
                $skill_prompt_s16d = "\n\n## 📘 KỸ NĂNG: " . $skill_title_s16d . "\n"
                    . mb_substr( $skill_content_s16d, 0, 4000, 'UTF-8' ) . "\n\n"
                    . "---\n"
                    . "### ⚠️ PHASE THỰC HIỆN (turn " . ( $turn_count + 1 ) . ")\n"
                    . "Đây là TURN TIẾP THEO trong skill /" . $skill_slug_s16d . ". "
                    . "User đã cung cấp thông tin ở các lượt trước (xem conversation history).\n"
                    . "**BẮT BUỘC**:\n"
                    . "1. Đọc lại TOÀN BỘ conversation history để tổng hợp thông tin user đã cung cấp\n"
                    . "2. Nếu user cung cấp thông tin mới → ghi nhận và tiếp tục\n"
                    . "3. Nếu ĐÃ ĐỦ thông tin hoặc user xác nhận (ok, làm đi, triển, v.v.) → BẮT ĐẦU THỰC HIỆN NGAY, KHÔNG hỏi thêm\n"
                    . "4. **TUYỆT ĐỐI KHÔNG lặp lại câu hỏi mà user đã trả lời**\n"
                    . "User vừa nói: \"" . mb_substr( $message, 0, 120, 'UTF-8' ) . "\"\n";

                add_filter( 'bizcity_chat_system_prompt', function ( $prompt ) use ( $skill_prompt_s16d ) {
                    return $prompt . $skill_prompt_s16d;
                }, 44 );

                $result['meta']['_injected_skill_context'] = mb_substr( $skill_content_s16d, 0, 4000, 'UTF-8' );
                $result['meta']['selected_skill']          = $skill_slug_s16d;
                // Pass slash_command so inject_skill_context (priority 93) can also match
                $result['meta']['slash_command']            = '/' . $skill_slug_s16d;

                $this->conversation_mgr->add_turn( $conv_id, 'user', $message );

                $result['action'] = 'compose_answer';
                $result['status'] = 'ACTIVE';
                $result['goal']   = $conv_goal_s16d;
                $result['meta']['mode']        = 'knowledge';
                $result['meta']['method']      = 'skill_cont/' . mb_substr( $skill_slug_s16d, 0, 15, 'UTF-8' );
                $result['meta']['archetype']   = 'A';
                $result['meta']['skill_title'] = $skill_title_s16d;

                error_log( '[INTENT-ENGINE] Step 1.6D: Skill continuation | skill=' . $skill_slug_s16d
                    . ' | turn=' . ( $turn_count + 1 )
                    . ' | user_msg=' . mb_substr( $message, 0, 50, 'UTF-8' )
                    . ' | source=' . ( ! empty( $conv_slots_s16d['_active_skill_content'] ) ? 'slots' : 'manager' ) );

                $this->logger->end_trace( $result );
                do_action( 'bizcity_intent_processed', $result, $params );
                return $result;
            }

            // Skill not found in manager either — fall through to normal pipeline
            error_log( '[INTENT-ENGINE] Step 1.6D: goal=' . $conv_goal_s16d
                . ' but skill "' . $skill_slug_s16d . '" not found — falling through' );
        }

        step_1_7_after_skill:  // label for Step 1.6D execution escape hatch (goto)

        // ── Step 1.6E: DIRECT SKILL ACTIVATION — early return BEFORE mode classifier ──
        // When user explicitly selects a /skill (UI picker or typed), resolve it immediately.
        // This runs BEFORE the Mode Classifier LLM call (~3s), eliminating:
        //   - Unnecessary LLM cost for obvious /skill commands
        //   - Clarify Gate (Step 2.4.5) blocking archetype A/B skills
        //   - Mode classifier misclassifying "làm thôi" as execution → 0 objectives → WAITING_USER
        // Archetype A/B → compose_answer (inject skill content, let LLM write)
        // Archetype D   → pipeline_queued (fire pipeline action)
        // @since v4.6.6 — Supersedes Step 2.4.10 for first-turn skill activation
        // @since v4.6.7 — Also catches tool_goal_hint (manual /contentagentic typing → gateway
        //   score < 30 → tool_goal instead of selected_skill). SkillManager lookup validates.
        $effective_slash_s16e = $slash_command ?: $selected_skill;

        // When gateway couldn't resolve /xyz as a skill (score < 30), it becomes tool_goal.
        // Try it as a skill slug too — SkillManager will validate.
        if ( ! $effective_slash_s16e && ! empty( $tool_goal_hint )
             && preg_match( '/^[a-z][a-z0-9_-]*$/i', $tool_goal_hint )
        ) {
            $effective_slash_s16e = $tool_goal_hint;
        }

        if ( $effective_slash_s16e && class_exists( 'BizCity_Skill_Manager' ) ) {
            $skill_match_s16e = $this->early_skill_lookup( $message, [ 'mode' => 'execution' ], $effective_slash_s16e );

            // Fallback: direct SkillManager lookup if early_skill_lookup failed
            if ( ! $skill_match_s16e ) {
                $direct_matches = \BizCity_Skill_Manager::instance()->find_matching( [
                    'slash_command' => $effective_slash_s16e,
                    'message'       => $message,
                    'limit'         => 1,
                ] );
                if ( ! empty( $direct_matches ) ) {
                    $dm = $direct_matches[0];
                    $archetype_s16e = 'A';
                    if ( class_exists( 'BizCity_Skill_Context' ) ) {
                        $archetype_s16e = \BizCity_Skill_Context::detect_archetype( $dm['frontmatter'] ?? [] );
                    }
                    $skill_match_s16e = [
                        'skill'     => $dm,
                        'archetype' => $archetype_s16e,
                    ];
                }
            }

            if ( $skill_match_s16e ) {
                $arch_s16e = $skill_match_s16e['archetype'] ?? 'A';

                // Upgrade A/B → D if body has @tool_refs or ≥2 numbered steps
                // (same logic as inject_skill_context — RecipeParser body scan)
                if ( in_array( $arch_s16e, [ 'A', 'B' ], true )
                     && class_exists( 'BizCity_Skill_Recipe_Parser' )
                ) {
                    $body_s16e   = $skill_match_s16e['skill']['content'] ?? '';
                    $fm_s16e     = $skill_match_s16e['skill']['frontmatter'] ?? [];
                    $parsed_s16e = \BizCity_Skill_Recipe_Parser::instance()->parse( $body_s16e, $fm_s16e );
                    if ( $parsed_s16e['strategy'] === 'guided' ) {
                        $arch_s16e = 'D';
                        $skill_match_s16e['archetype']                = 'D';
                        $skill_match_s16e['skill']['body_steps']      = $parsed_s16e['steps'];
                        $skill_match_s16e['skill']['body_tool_refs']  = $parsed_s16e['tool_refs'];
                        $skill_match_s16e['skill']['body_guardrails'] = $parsed_s16e['guardrails'];
                        error_log( '[INTENT-ENGINE] Step 1.6E: RecipeParser upgraded '
                            . $effective_slash_s16e . ' from A/B → D (guided)' );
                    }
                }

                // Signal to SkillContext filter (priority 93) — Step 1.6E is handling this skill.
                // Prevents dual-path: 1.6E routes A→compose_answer while SkillContext independently fires C→pipeline.
                $GLOBALS['_bizcity_s16e_handled_skill'] = $effective_slash_s16e;

                // ── Archetype D → fire pipeline (same as Step 2.4.9) ──
                if ( $arch_s16e === 'D' ) {
                    do_action( 'bizcity_skill_trigger_pipeline', $skill_match_s16e['skill'], [
                        'message' => $message, 'mode' => 'execution', 'engine_result' => $result,
                    ] );
                    $d_title = $skill_match_s16e['skill']['frontmatter']['title'] ?? $effective_slash_s16e;
                    $result['reply']  = '📋 Đang tạo kịch bản từ skill **' . $d_title . '**...';
                    $result['action'] = 'pipeline_queued';
                    $result['goal']   = 'skill:' . $effective_slash_s16e;
                    $result['meta']['mode']      = 'execution';
                    $result['meta']['method']    = 'skill_direct/' . mb_substr( $effective_slash_s16e, 0, 15, 'UTF-8' );
                    $result['meta']['archetype'] = 'D';

                    $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                    $this->conversation_mgr->set_goal( $conv_id, 'skill:' . $effective_slash_s16e, '📋 ' . $d_title );
                    error_log( '[INTENT-ENGINE] Step 1.6E: Archetype D → pipeline_queued | skill=' . $effective_slash_s16e );
                    $this->logger->end_trace( $result );
                    do_action( 'bizcity_intent_processed', $result, $params );
                    return $result;
                }

                // ── Step 1.6E-b: Execution-intent safety net ──
                // When Archetype A/B but user message has strong execution verbs,
                // inject skill context but fall through to mode classifier (Step 1.8+)
                // instead of short-circuiting to compose_answer.
                // This handles: "/contentcongnghe đăng bài viết về ..." → should be execution, not knowledge.
                // @since v4.6.8
                $exec_patterns_s16e = '/\b(đăng\s*(bài|lên)|viết\s*bài\s*lên|publish|post\s+to|tạo\s*bài|xuất\s*bản|write.*(article|post)|tạo\s*(sản phẩm|product)|đặt\s*hàng|gửi\s*(email|mail|tin)|send\s)/iu';
                $has_exec_intent_s16e = (bool) preg_match( $exec_patterns_s16e, $message );

                if ( $has_exec_intent_s16e ) {
                    // Still inject skill context for the mode classifier / pipeline to use
                    $skill_content_s16e_fb = $skill_match_s16e['skill']['content'] ?? '';
                    $skill_title_s16e_fb   = $skill_match_s16e['skill']['frontmatter']['title']
                        ?? $effective_slash_s16e ?? 'Skill';

                    if ( $skill_content_s16e_fb ) {
                        $skill_prompt_s16e_fb = "\n\n## 📘 KỸ NĂNG: " . $skill_title_s16e_fb . "\n"
                            . "Người dùng đã chọn kỹ năng /" . $effective_slash_s16e . ". "
                            . "Hãy tuân thủ các hướng dẫn bên dưới.\n\n"
                            . mb_substr( $skill_content_s16e_fb, 0, 4000, 'UTF-8' );

                        add_filter( 'bizcity_chat_system_prompt', function ( $prompt ) use ( $skill_prompt_s16e_fb ) {
                            return $prompt . $skill_prompt_s16e_fb;
                        }, 44 );

                        $result['meta']['_injected_skill_context'] = mb_substr( $skill_content_s16e_fb, 0, 4000, 'UTF-8' );
                        $result['meta']['selected_skill']          = $effective_slash_s16e;
                        $result['meta']['slash_command']            = '/' . $effective_slash_s16e;
                    }

                    error_log( '[INTENT-ENGINE] Step 1.6E-b: Archetype ' . $arch_s16e
                        . ' + execution intent detected → falling through to mode classifier'
                        . ' | skill=' . $effective_slash_s16e . ' | message=' . mb_substr( $message, 0, 60, 'UTF-8' ) );

                    // Fall through — do NOT return. Mode classifier will detect execution intent.
                }

                // ── Archetype A/B → compose_answer (inject skill prompt) ──
                // Only when NO execution intent was detected (otherwise we fell through above)
                if ( ! $has_exec_intent_s16e ) {

                $skill_content_s16e = $skill_match_s16e['skill']['content'] ?? '';
                $skill_title_s16e   = $skill_match_s16e['skill']['frontmatter']['title']
                    ?? $effective_slash_s16e ?? 'Skill';

                if ( $skill_content_s16e ) {
                    $skill_prompt_s16e = "\n\n## 📘 KỸ NĂNG: " . $skill_title_s16e . "\n"
                        . "Người dùng đã chọn kỹ năng /" . $effective_slash_s16e . ". "
                        . "Hãy tuân thủ các hướng dẫn bên dưới và trả lời dựa trên nội dung này.\n"
                        . "**GHI NHỚ**: Không hỏi lại \"bạn muốn tìm hiểu hay thực thi\" — hãy THỰC HIỆN theo skill.\n\n"
                        . mb_substr( $skill_content_s16e, 0, 4000, 'UTF-8' );

                    add_filter( 'bizcity_chat_system_prompt', function ( $prompt ) use ( $skill_prompt_s16e ) {
                        return $prompt . $skill_prompt_s16e;
                    }, 44 );

                    $result['meta']['_injected_skill_context'] = mb_substr( $skill_content_s16e, 0, 4000, 'UTF-8' );
                    $result['meta']['selected_skill']          = $effective_slash_s16e;
                    $result['meta']['slash_command']            = '/' . $effective_slash_s16e;
                }

                $result['action'] = 'compose_answer';
                $result['status'] = 'ACTIVE';
                $result['goal']   = 'skill:' . $effective_slash_s16e;
                $result['meta']['mode']        = 'knowledge';
                $result['meta']['method']      = 'skill_direct/' . mb_substr( $effective_slash_s16e, 0, 15, 'UTF-8' );
                $result['meta']['archetype']   = $arch_s16e;
                $result['meta']['skill_title'] = $skill_title_s16e;

                $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                $this->conversation_mgr->set_goal( $conv_id, 'skill:' . $effective_slash_s16e, '📘 ' . $skill_title_s16e );
                $this->conversation_mgr->update_slots( $conv_id, [
                    '_active_skill_slug'    => $effective_slash_s16e,
                    '_active_skill_title'   => $skill_title_s16e,
                    '_active_skill_content' => mb_substr( $skill_content_s16e, 0, 4000, 'UTF-8' ),
                ] );

                error_log( '[INTENT-ENGINE] Step 1.6E: Archetype ' . $arch_s16e
                    . ' skill → compose_answer | skill=' . $effective_slash_s16e
                    . ' | title=' . $skill_title_s16e
                    . ' | content_len=' . strlen( $skill_content_s16e ) );

                $this->logger->end_trace( $result );
                do_action( 'bizcity_intent_processed', $result, $params );
                return $result;
                } // end if ( ! $has_exec_intent_s16e )
            }

            // Skill not found — fall through to mode classifier
            error_log( '[INTENT-ENGINE] Step 1.6E: slash=' . $effective_slash_s16e . ' → no skill found, falling through' );
        }

        // ── Step 1.7: TOOL SUGGEST CONFIRMATION ──
        // Disabled along with Step 1.5C tool suggestions (inaccurate matching).
        /*
        if ( ! $has_active_goal_s16 && empty( $images ) ) {
            $slots_s17 = json_decode( $conversation['slots_json'] ?? '{}', true ) ?: [];
            $suggested_tools_s17 = $slots_s17['_image_text_suggested_tools'] ?? [];
            $pending_images_s17  = $slots_s17['_session_pending_images'] ?? [];

            if ( ! empty( $suggested_tools_s17 ) && ! empty( $pending_images_s17 ) ) {
                $confirmed_tool = $this->detect_tool_suggest_confirm( $message, $suggested_tools_s17 );

                if ( $confirmed_tool ) {
                    // Recover images from session buffer
                    $images           = $pending_images_s17;
                    $params['images'] = $images;

                    // Set tool_goal hint → Router slash-command shortcut (0 LLM cost)
                    $params['tool_goal']        = $confirmed_tool['goal'];
                    $params['_suggest_confirm'] = true;

                    // Clear session buffers
                    $this->conversation_mgr->update_slots( $conv_id, [
                        '_image_text_suggested_tools' => null,
                        '_session_pending_images'     => null,
                    ] );

                    $this->logger->log( 'tool_suggest_confirm', [
                        'tool_name'      => $confirmed_tool['tool_name'],
                        'goal'           => $confirmed_tool['goal'],
                        'label'          => $confirmed_tool['label'],
                        'image_count'    => count( $images ),
                        'message'        => mb_substr( $message, 0, 100, 'UTF-8' ),
                    ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

                    error_log( '[INTENT-ENGINE] Step 1.7: Tool suggest confirmed → goal='
                             . $confirmed_tool['goal'] . ' | tool=' . $confirmed_tool['tool_name']
                             . ' | images=' . count( $images ) );
                }
            }
        }
        */

        // ── Feed conversation context into Context Builder (5-Layer Chain) ──
        $ctx_builder = BizCity_Context_Builder::instance();
        $ctx_builder->reset();
        $ctx_builder->set_user( $user_id, $session_id );
        $ctx_builder->set_conversation_context( $conv_id, $conversation );

        // ── Step 1.9: CLARIFY ANSWER RESOLUTION ──
        // If we are waiting for clarify intent, resolve the user's choice first
        // (knowledge vs execution) before running mode classifier.
        if ( $this->clarify_gate
             && ( $conversation['status'] ?? '' ) === 'WAITING_USER'
             && ( $conversation['waiting_for'] ?? '' ) === 'clarify'
             && ( $conversation['waiting_field'] ?? '' ) === '_clarify_intent'
        ) {
            $clarify_reply = $this->clarify_gate->resolve_reply( $message );

            if ( empty( $clarify_reply['resolved'] ) ) {
                // ── v4.3.7: Escape hatch — substantive messages bypass clarify loop ──
                // If user sends anything beyond a numeric/short choice answer,
                // they're ignoring the clarify prompt and typing a real question.
                // Abandon clarify state and let Mode Classifier handle it normally.
                // Threshold >3: "1","2","1.","2)","ok" (≤3) stay in loop;
                // "một","hai" (=3) stay; everything else escapes.
                $trimmed_msg = trim( $message );
                $msg_len     = mb_strlen( $trimmed_msg, 'UTF-8' );
                if ( $msg_len > 3 ) {
                    $this->conversation_mgr->resume( $conv_id );
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[INTENT-ENGINE] Step 1.9: Clarify escape — substantive message ('
                                 . $msg_len . ' chars), abandoning clarify loop.' );
                    }
                    // Fall through to Step 2 (Mode Classification)
                } else {
                    $this->conversation_mgr->set_waiting( $conv_id, 'clarify', '_clarify_intent' );
                    $result['reply']  = $clarify_reply['retry_prompt'];
                    $result['action'] = 'ask_user';
                    $result['status'] = 'WAITING_USER';
                    $result['meta']['clarify_reason']  = 'clarify_reply_unknown';
                    $result['meta']['mode']            = 'clarify_pending';
                    $result['meta']['mode_confidence'] = 0;

                    $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'], [
                        'meta' => [ 'ask_field' => '_clarify_intent', 'ask_type' => 'clarify' ],
                    ] );

                    $this->logger->end_trace( $result );
                    do_action( 'bizcity_intent_processed', $result, $params );
                    return $result;
                }
            }

            // Clarify resolved -> resume and store selected direction.
            $this->conversation_mgr->resume( $conv_id );
            $this->conversation_mgr->update_slots( $conv_id, [
                '_clarify_choice' => $clarify_reply['choice'] ?? '',
            ] );

            $params['_clarify_forced_mode'] = $clarify_reply['forced_mode'] ?? '';
            $conversation = $this->conversation_mgr->get_active( $user_id, $channel, $session_id ) ?: $conversation;
        }

        // ── Step 1.9c: MULTI-OBJECTIVE PRE-CONFIRM RESOLUTION ── (Phase 1.1 v1.5)
        // When engine asked for missing slots before generating workflow (Step 4.4 pre-confirm),
        // user's reply fills the missing fields → mark confirmed → re-enter Step 4.4.
        if ( ( $conversation['status'] ?? '' ) === 'WAITING_USER'
             && ( $conversation['waiting_field'] ?? '' ) === '_multi_preconfirm'
        ) {
            $current_slots_pc   = json_decode( $conversation['slots_json'] ?? '{}', true ) ?: [];
            $preconfirm_state   = $current_slots_pc['_multi_preconfirm_state'] ?? '';
            $trimmed_msg_pc     = mb_strtolower( trim( $message ), 'UTF-8' );

            // Check for cancel
            $is_cancel = (bool) preg_match(
                '/^(hủy|huỷ|không|ko|thôi|bỏ|cancel|no|hủy đi|thôi đi|bỏ đi|dừng)$/u',
                $trimmed_msg_pc
            );

            if ( $is_cancel ) {
                $this->conversation_mgr->resume( $conv_id );
                $this->conversation_mgr->update_slots( $conv_id, [
                    '_multi_preconfirm_state'    => '',
                    '_multi_preconfirm_analysis' => '',
                    '_preconfirm_content'        => '',
                ] );
                $this->conversation_mgr->complete( $conv_id, 'User cancelled multi-preconfirm.' );

                $result['reply']  = '👌 Đã hủy. Bạn cần gì khác thì nói mình nhé!';
                $result['action'] = 'complete';
                $result['status'] = 'COMPLETED';
                $result['meta']['preconfirm_cancelled'] = true;

                $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
                $this->logger->end_trace( $result );
                do_action( 'bizcity_intent_processed', $result, $params );
                return $result;
            }

            // ── Content-First Resume (Phase 1.1 v1.6) ──
            // When state = asking_content → user's reply IS the content.
            // Save as _preconfirm_content, mark content_provided, re-enter pipeline.
            if ( $preconfirm_state === 'asking_content' ) {
                $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                $this->conversation_mgr->resume( $conv_id );
                $this->conversation_mgr->update_slots( $conv_id, [
                    '_preconfirm_content'       => trim( $message ),
                    '_multi_preconfirm_state'   => 'content_provided',
                ] );
                $conversation = $this->conversation_mgr->get_active( $user_id, $channel, $session_id ) ?: $conversation;
                // Fall through to normal pipeline → Step 4.4 content gate sees content_provided
            } else {
                // Legacy: "chạy ngay" accept or other text
                $is_run_now = (bool) preg_match(
                    '/^(ok|oke|okie|được|chạy|chạy ngay|chạy đi|làm đi|go|yes|1|✅)$/u',
                    $trimmed_msg_pc
                );

                if ( $is_run_now ) {
                    $this->conversation_mgr->resume( $conv_id );
                    $this->conversation_mgr->update_slots( $conv_id, [
                        '_multi_preconfirm_state' => 'confirmed',
                    ] );
                    $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                    $conversation = $this->conversation_mgr->get_active( $user_id, $channel, $session_id ) ?: $conversation;
                } else {
                    // General text reply → treat as content
                    $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                    $this->conversation_mgr->resume( $conv_id );
                    $this->conversation_mgr->update_slots( $conv_id, [
                        '_preconfirm_content'     => trim( $message ),
                        '_multi_preconfirm_state' => 'content_provided',
                    ] );
                    $conversation = $this->conversation_mgr->get_active( $user_id, $channel, $session_id ) ?: $conversation;
                }
            }
        }

        // ── Step 1.9d: PIPELINE RESUME / CANCEL / RETRY DETECTION ──
        // Phase 1.1d: Detect commands to resume a paused pipeline, retry a failed step,
        // skip/cancel a step, or cancel the entire pipeline.
        // Must run BEFORE Step 1.9b (Plan Confirm) and Step 2 (Mode Classification).
        // Guard: skip if user is in plan-confirm conversation (prevent conflict with 1.9b).
        $in_plan_confirm = ( ( $conversation['status'] ?? '' ) === 'WAITING_USER'
            && ! empty( $conversation['mode'] )
            && strpos( $conversation['mode'], '_confirm_plan_builder' ) !== false );

        if ( class_exists( 'BizCity_Intent_Todos' ) && ! $in_plan_confirm ) {
            $trimmed_msg_1d = mb_strtolower( trim( $message ), 'UTF-8' );

            // Pattern: resume pipeline — "tiếp tục", "tiếp tục kế hoạch", "resume", "chạy tiếp"
            $is_resume = (bool) preg_match(
                '/^(tiếp tục|tiep tuc|tiếp tục kế hoạch|chạy tiếp|resume|continue|làm tiếp|tiếp)(\s+(kế hoạch|pipeline|plan))?$/u',
                $trimmed_msg_1d
            );
            // Pattern: retry failed — "thử lại", "retry", "chạy lại"
            $is_retry = (bool) preg_match(
                '/^(thử lại|thu lai|retry|chạy lại|làm lại|thử lại bước)(\s+\d+)?$/u',
                $trimmed_msg_1d
            );
            // Pattern: skip step — "bỏ qua", "skip", "bỏ qua bước"
            $is_skip = (bool) preg_match(
                '/^(bỏ qua|bo qua|skip|bỏ qua bước|skip step)(\s+\d+)?$/u',
                $trimmed_msg_1d
            );
            // Pattern: cancel entire pipeline — "hủy kế hoạch", "dừng pipeline", "cancel pipeline"
            $is_cancel_pipeline = (bool) preg_match(
                '/^(hủy kế hoạch|hủy pipeline|dừng kế hoạch|dừng pipeline|cancel pipeline|cancel plan|hủy toàn bộ)$/u',
                $trimmed_msg_1d
            );

            if ( $is_resume || $is_retry || $is_skip || $is_cancel_pipeline ) {
                $active_pipe = BizCity_Intent_Todos::find_active_pipeline( $user_id );

                if ( $active_pipe ) {
                    $pipe_id    = $active_pipe['pipeline_id'];
                    $pipe_reply = '';
                    $pipe_done  = false;

                    if ( $is_resume ) {
                        // ── Resume: find execution_id → call waic_execute_workflow_resume ──
                        $pipe_reply = $this->handle_pipeline_resume( $active_pipe, $user_id, $session_id, $channel );
                        $pipe_done  = true;

                    } elseif ( $is_retry ) {
                        // ── Retry: find FAILED step → reset → resume ──
                        $failed = BizCity_Intent_Todos::find_failed_step( $pipe_id );
                        if ( $failed ) {
                            BizCity_Intent_Todos::reset_step_for_retry( $pipe_id, (int) $failed['step_index'] );
                            // Also reset any SKIPPED downstream steps
                            BizCity_Intent_Todos::reset_skipped_from( $pipe_id, (int) $failed['step_index'] + 1 );
                            error_log( '[INTENT-ENGINE] Step 1.9d: Retry step ' . $failed['step_index'] . ' (' . $failed['tool_name'] . ') in pipeline=' . $pipe_id );

                            // Resume the pipeline to re-execute the reset step
                            $pipe_reply = "🔄 Đang thử lại bước **" . ( $failed['label'] ?: $failed['tool_name'] ) . "**...\n\n";
                            $resume_reply = $this->handle_pipeline_resume( $active_pipe, $user_id, $session_id, $channel );
                            $pipe_reply .= $resume_reply;
                        } else {
                            $pipe_reply = "✅ Không có bước nào thất bại trong kế hoạch hiện tại.\n\n"
                                        . BizCity_Intent_Todos::get_formatted_message( $pipe_id );
                        }
                        $pipe_done = true;

                    } elseif ( $is_skip ) {
                        // ── Skip: cancel current waiting/active step + skip dependents ──
                        $step_idx = (int) $active_pipe['next_step_index'];
                        $skip_result = BizCity_Intent_Todos::cancel_step( $pipe_id, $step_idx, true );
                        error_log( '[INTENT-ENGINE] Step 1.9d: Skip step ' . $step_idx . ' in pipeline=' . $pipe_id
                                 . ' → cancelled=' . $skip_result['cancelled'] . ' skipped=' . $skip_result['skipped'] );

                        $pipe_reply = "⏭️ Đã bỏ qua bước **" . ( $active_pipe['next_label'] ?: $active_pipe['next_tool'] ) . "**";
                        if ( $skip_result['skipped'] > 0 ) {
                            $pipe_reply .= " (+{$skip_result['skipped']} bước phụ thuộc)";
                        }
                        $pipe_reply .= "\n\n";

                        // Check if pipeline has more steps → auto-resume
                        $progress = BizCity_Intent_Todos::get_progress( $pipe_id );
                        if ( $progress['pending'] > 0 ) {
                            $pipe_reply .= "▶️ Tiếp tục với bước tiếp theo...\n\n";
                            $resume_reply = $this->handle_pipeline_resume( $active_pipe, $user_id, $session_id, $channel );
                            $pipe_reply .= $resume_reply;
                        } else {
                            $pipe_reply .= BizCity_Intent_Todos::get_formatted_message( $pipe_id );
                        }
                        $pipe_done = true;

                    } elseif ( $is_cancel_pipeline ) {
                        // ── Cancel entire pipeline ──
                        $all_todos = BizCity_Intent_Todos::get_pipeline_todos( $pipe_id );
                        $cancelled_count = 0;
                        global $wpdb;
                        $todo_table = BizCity_Intent_Database::instance()->todos_table();
                        foreach ( $all_todos as $todo ) {
                            if ( in_array( $todo['status'], [ 'PENDING', 'WAITING_USER', 'ACTIVE', 'IN_PROGRESS' ], true ) ) {
                                $wpdb->update( $todo_table, [ 'status' => 'CANCELLED' ], [ 'id' => $todo['id'] ] );
                                $cancelled_count++;
                            }
                        }
                        error_log( '[INTENT-ENGINE] Step 1.9d: Cancel pipeline=' . $pipe_id . ' cancelled_steps=' . $cancelled_count );

                        $pipe_reply = "❌ Đã hủy kế hoạch ({$cancelled_count} bước).\n\n"
                                    . BizCity_Intent_Todos::get_formatted_message( $pipe_id )
                                    . "\n\nBạn cần gì khác thì nói mình nhé!";
                        $pipe_done = true;
                    }

                    if ( $pipe_done && $pipe_reply ) {
                        // Complete any active conversation
                        if ( $conv_id ) {
                            $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                            $this->conversation_mgr->add_turn( $conv_id, 'assistant', $pipe_reply );
                        }

                        $result['reply']  = $pipe_reply;
                        $result['action'] = 'pipeline_command';
                        $result['status'] = 'COMPLETED';
                        $result['meta']['pipeline_id']      = $pipe_id;
                        $result['meta']['pipeline_command']  = $is_resume ? 'resume' : ( $is_retry ? 'retry' : ( $is_skip ? 'skip' : 'cancel' ) );

                        $this->logger->end_trace( $result );
                        do_action( 'bizcity_intent_processed', $result, $params );
                        return $result;
                    }
                }
                // No active pipeline found → fall through to normal flow
            }
        }

        // ── Step 1.9b: PLAN BUILDER CONFIRM RESOLUTION ──
        // When engine presented a multi-objective plan link (Step 4.4) and user responds,
        // detect accept/reject before running the full pipeline again.
        // Accept  → remind user to open builder link, complete conversation.
        // Reject  → delete draft task, complete conversation.
        // Unknown → treat as new topic, complete plan conversation so normal pipeline picks up.
        if ( ( $conversation['status'] ?? '' ) === 'WAITING_USER'
             && ( $conversation['waiting_field'] ?? '' ) === '_confirm_plan_builder'
        ) {
            $current_slots_plan = json_decode( $conversation['slots_json'] ?? '{}', true ) ?: [];
            $plan_task_id       = $current_slots_plan['_plan_task_id'] ?? '';
            $plan_link          = $current_slots_plan['_plan_link'] ?? '';
            $trimmed_msg        = mb_strtolower( trim( $message ), 'UTF-8' );

            // Fast accept patterns (Vietnamese + common)
            $is_accept = (bool) preg_match(
                '/^(ok|oke|okie|được|đồng ý|chạy|thực hiện|tiếp tục|yes|chạy đi|làm đi|go|duyệt|xác nhận|1)$/u',
                $trimmed_msg
            );
            // Fast reject patterns
            $is_reject = (bool) preg_match(
                '/^(hủy|huỷ|không|ko|thôi|bỏ|cancel|no|hủy đi|thôi đi|bỏ đi|dừng|2)$/u',
                $trimmed_msg
            );

            $this->conversation_mgr->resume( $conv_id );
            $this->conversation_mgr->update_slots( $conv_id, [
                '_awaiting_plan_confirm' => '',
            ] );

            if ( $is_accept ) {
                // User accepted plan → auto-execute pipeline (Phase 1.1 G10)
                $auto_exec_result = $this->auto_execute_plan_task( (int) $plan_task_id, $user_id, $session_id, $channel, (int) $conv_id );

                if ( $auto_exec_result['success'] ) {
                    $reply = "✅ Đang chạy kế hoạch tự động!\n"
                           . "📋 Pipeline đã bắt đầu — bạn sẽ nhận tin nhắn cập nhật cho mỗi bước.\n\n"
                           . "👉 Xem chi tiết: [{$plan_link}]({$plan_link})";
                } else {
                    // Fallback: auto-execute failed → user opens builder manually
                    $reply = "⚠️ Không tự động chạy được — " . ( $auto_exec_result['error'] ?? 'lỗi hệ thống' ) . "\n\n"
                           . "👉 Bạn mở link bên dưới để chạy thủ công:\n[{$plan_link}]({$plan_link})";
                }

                $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $reply );
                $this->conversation_mgr->complete( $conv_id, 'User accepted multi-plan → auto-execute.' );

                $result['reply']  = $reply;
                $result['action'] = 'complete';
                $result['status'] = 'COMPLETED';
                $result['meta']['plan_confirmed']    = true;
                $result['meta']['plan_link']         = $plan_link;
                $result['meta']['auto_executed']      = $auto_exec_result['success'];
                $result['meta']['execution_id']       = $auto_exec_result['execution_id'] ?? '';

                $this->logger->log( 'plan_confirm_accept', [
                    'task_id'       => $plan_task_id,
                    'plan_link'     => $plan_link,
                    'auto_executed' => $auto_exec_result['success'],
                    'execution_id'  => $auto_exec_result['execution_id'] ?? '',
                ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

                $this->logger->end_trace( $result );
                do_action( 'bizcity_intent_processed', $result, $params );
                return $result;
            }

            if ( $is_reject ) {
                // User rejected plan — delete draft task if possible
                if ( $plan_task_id ) {
                    global $wpdb;
                    $task_table = $wpdb->prefix . 'bizcity_tasks';
                    $wpdb->delete( $task_table, [ 'id' => (int) $plan_task_id ], [ '%d' ] );
                }

                $reply = '👌 Đã hủy kế hoạch. Bạn cần gì khác thì nói mình nhé!';

                $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $reply );
                $this->conversation_mgr->complete( $conv_id, 'User rejected multi-plan.' );

                $result['reply']  = $reply;
                $result['action'] = 'complete';
                $result['status'] = 'COMPLETED';
                $result['meta']['plan_rejected'] = true;

                $this->logger->log( 'plan_confirm_reject', [
                    'task_id' => $plan_task_id,
                ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

                $this->logger->end_trace( $result );
                do_action( 'bizcity_intent_processed', $result, $params );
                return $result;
            }

            // Unknown response → complete plan conversation, let message continue as new intent
            $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
            $this->conversation_mgr->complete( $conv_id, 'User switched topic during plan confirm.' );

            $this->logger->log( 'plan_confirm_new_topic', [
                'task_id' => $plan_task_id,
                'message' => mb_substr( $message, 0, 100, 'UTF-8' ),
            ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

            // Create fresh conversation for the new intent
            $conversation = $this->conversation_mgr->get_or_create(
                $user_id, $channel, $session_id, $character_id
            );
            $conv_id = $conversation['conversation_id'];
            $result['conversation_id'] = $conv_id;
            // Fall through to Step 2 with fresh conversation
        }

        // ── Step 2: META MODE CLASSIFICATION (Tầng 1) ──
        // Classify into 5 modes: emotion, reflection, knowledge, execution, ambiguous
        // v4.3.4: Skip "Đang phân tích..." status flash for WAITING_USER — Mode Classifier
        // already returns early (method=context, ~20ms) without LLM call.
        // Skipping the status avoids a confusing "thinking" flash for simple slot input.
        if ( ! $is_waiting_user_s1 || ! $has_active_goal_s1 ) {
            do_action( 'bizcity_intent_status', '🤔 Đang phân tích...' );
        }
        $mode_start = microtime( true );
        $mode_result = $this->mode_classifier->classify( $message, $conversation, $images );

        // Clarify gate selected explicit direction in previous turn.
        if ( ! empty( $params['_clarify_forced_mode'] ) ) {
            if ( $params['_clarify_forced_mode'] === 'execution' ) {
                $mode_result['mode'] = BizCity_Mode_Classifier::MODE_EXECUTION;
            } elseif ( $params['_clarify_forced_mode'] === 'knowledge' ) {
                $mode_result['mode'] = BizCity_Mode_Classifier::MODE_KNOWLEDGE;
            }
            $mode_result['confidence'] = 0.99;
            $mode_result['method'] = 'clarify_forced_mode';
        }

        // Allow external code to override the classified mode (e.g. force knowledge for NOTEBOOK).
        $mode_result = apply_filters( 'bizcity_intent_mode_result', $mode_result, $params );

        // ── Step 1.5C override: force question mode when image+text smart routing active ──
        // Step 1.5C detected image + meaningful text with no goal and stored
        // _session_pending_images. Force knowledge mode so AI answers first,
        // then tool suggestions in system prompt guide the LLM to offer options.
        // v4.3.6 SMART BYPASS: If message matches a registered provider pattern
        // (e.g. "tạo video", "log bữa ăn"), skip override → let Router handle as
        // execution. Prevents forced knowledge mode for unambiguous tool commands
        // that happen to include an image attachment.
        if ( ! empty( $params['_image_text_suggest'] ) ) {
            $skip_override = false;

            // Quick pattern scan against all registered goal patterns
            $all_patterns = $this->router->get_goal_patterns();
            foreach ( $all_patterns as $pattern => $cfg ) {
                if ( @preg_match( $pattern, $message ) ) {
                    $skip_override  = true;
                    $matched_goal   = $cfg['goal'] ?? '';
                    $matched_source = $cfg['_provider_source'] ?? 'core';

                    $this->logger->log( 'image_text_suggest_bypass', [
                        'reason'  => 'pattern_match',
                        'pattern' => $pattern,
                        'goal'    => $matched_goal,
                        'source'  => $matched_source,
                    ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );
                    break;
                }
            }

            if ( ! $skip_override ) {
                $mode_result['mode']       = BizCity_Mode_Classifier::MODE_KNOWLEDGE;
                $mode_result['method']     = 'image_text_suggest_override';
                $mode_result['confidence'] = 0.9;
            } else {
                // Pattern matched — let normal execution flow handle it.
                // Keep _session_pending_images stored (Step 4.6 will recover them
                // if the Router opens a new goal that has an image slot).
                // Force mode to execution so Router→Planner→Tool pipeline runs.
                $mode_result['mode']       = BizCity_Mode_Classifier::MODE_EXECUTION;
                $mode_result['method']     = 'image_text_pattern_bypass';
                $mode_result['confidence'] = 0.95;
            }
        }

        // ── Step 1.7 override: force execution mode when user confirmed tool suggestion ──
        // Step 1.7 set tool_goal and recovered images. Force execution so the
        // pipeline reaches Step 3 (Router) where tool_goal shortcut fires.
        if ( ! empty( $params['_suggest_confirm'] ) ) {
            $mode_result['mode']       = BizCity_Mode_Classifier::MODE_EXECUTION;
            $mode_result['method']     = 'tool_suggest_confirm';
            $mode_result['confidence'] = 0.95;
        }

        // ── Step 1.8 override: force execution when slash command / tool_goal is set ──
        // When Intent Stream parses /write_article (or frontend sends tool_goal),
        // force execution mode so pipeline reaches Step 3 (Router) where tool_goal
        // shortcut fires. Without this, Mode Classifier may return knowledge and
        // the Router (Step 3) is NEVER reached — tool_goal is silently lost.
        if ( ! empty( $tool_goal_hint )
             && $mode_result['mode'] !== BizCity_Mode_Classifier::MODE_EXECUTION
        ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-ENGINE] Step 1.8: tool_goal override → execution'
                         . ' | tool_goal=' . $tool_goal_hint
                         . ' | original_mode=' . $mode_result['mode'] );
            }
            $mode_result['mode']       = BizCity_Mode_Classifier::MODE_EXECUTION;
            $mode_result['confidence'] = 0.95;
            $mode_result['method']     = $mode_result['method'] . '+tool_goal_override(' . $tool_goal_hint . ')';
        }

        // ── Step 1.8b: force execution when /skill is explicitly selected ──
        // Phase 1.7: When user selects a skill via / dropdown or types /skill_slug,
        // force execution mode so the pipeline reaches Step 2.4.8 (Early Skill Lookup)
        // and Step 3 (Router) where skill context is injected.
        if ( ! empty( $selected_skill )
             && empty( $tool_goal_hint )
             && $mode_result['mode'] !== BizCity_Mode_Classifier::MODE_EXECUTION
        ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-ENGINE] Step 1.8b: /skill override → execution'
                         . ' | selected_skill=' . $selected_skill
                         . ' | original_mode=' . $mode_result['mode'] );
            }
            $mode_result['mode']       = BizCity_Mode_Classifier::MODE_EXECUTION;
            $mode_result['confidence'] = 0.90;
            $mode_result['method']     = $mode_result['method'] . '+skill_override(/' . $selected_skill . ')';
        }

        // ── Step 2.0: @MENTION PROVIDER HINT — Conditional execution mode ──
        // When user explicitly targets an agent via @mention, we PREFER execution
        // mode UNLESS the user's message signals emotional distress (safety-first).
        // C3 Fix: emotion_critical / emotion_high bypass safety routing.
        if ( $provider_hint ) {
            $registry = class_exists( 'BizCity_Intent_Provider_Registry' )
                      ? BizCity_Intent_Provider_Registry::instance() : null;
            $hinted_provider = $registry ? $registry->get( $provider_hint ) : null;

            if ( $hinted_provider ) {
                $original_mode       = $mode_result['mode'];
                $original_confidence = $mode_result['confidence'];
                $original_method     = $mode_result['method'];

                // Safety gate: check emotional intensity BEFORE overriding mode
                $hint_intensity = 1;
                if ( class_exists( 'BizCity_Emotional_Memory' ) ) {
                    $hint_intensity = BizCity_Emotional_Memory::instance()->estimate_intensity( $message );
                }

                $mode_result['meta']['provider_hint'] = array(
                    'provider_id'         => $provider_hint,
                    'provider_name'       => $hinted_provider->get_name(),
                    'original_mode'       => $original_mode,
                    'original_confidence' => $original_confidence,
                    'original_method'     => $original_method,
                    'intensity_at_hint'   => $hint_intensity,
                );

                // C3: emotion_critical (intensity >= 5) → NEVER override, safety first
                if ( $original_mode === BizCity_Mode_Classifier::MODE_EMOTION && $hint_intensity >= 5 ) {
                    // Keep emotion mode — Safety Guard + hotline will trigger
                    $mode_result['method'] = $original_method . '+provider_hint_blocked(safety:intensity=' . $hint_intensity . ')';

                    $this->logger->log( 'provider_hint_safety_block', array(
                        'provider_hint' => $provider_hint,
                        'intensity'     => $hint_intensity,
                        'reason'        => 'emotion_critical — safety routing preserved',
                    ) );

                // C3: emotion_high (intensity 3-4) → emotion FIRST, store hint for after
                } elseif ( $original_mode === BizCity_Mode_Classifier::MODE_EMOTION && $hint_intensity >= 3 ) {
                    // Keep emotion mode but store provider_hint for post-emotion follow-up
                    $mode_result['meta']['provider_hint']['deferred'] = true;
                    $mode_result['method'] = $original_method . '+provider_hint_deferred(emotion_high:intensity=' . $hint_intensity . ')';

                    $this->logger->log( 'provider_hint_deferred', array(
                        'provider_hint' => $provider_hint,
                        'intensity'     => $hint_intensity,
                        'reason'        => 'emotion_high — empathy first, tool deferred',
                    ) );

                } else {
                    // Safe to override → force execution mode
                    $mode_result['mode']       = BizCity_Mode_Classifier::MODE_EXECUTION;
                    $mode_result['confidence'] = 0.95;
                    $mode_result['method']     = $original_method . '+provider_hint(@' . $provider_hint . ')';

                    $this->logger->log( 'provider_hint_override', array(
                        'provider_hint' => $provider_hint,
                        'provider_name' => $hinted_provider->get_name(),
                        'original_mode' => $original_mode,
                    ) );

                    // ── v4.2: Clear cross-provider tainted intent_result ──
                    // Mode Classifier may have hardcoded provide_input for the ACTIVE
                    // goal (from a different provider) during WAITING_USER.
                    // This tainted result would bypass Router LLM via Step 0.5.
                    // Clear it so Router classifies freshly with the correct provider.
                    if ( ! empty( $mode_result['meta']['intent_result'] ) ) {
                        $ir_goal  = $mode_result['meta']['intent_result']['goal'] ?? '';
                        if ( $ir_goal && $registry ) {
                            $ir_owner = $registry->get_provider_for_goal( $ir_goal );
                            if ( $ir_owner && $ir_owner->get_id() !== $provider_hint ) {
                                $tainted = $ir_goal;
                                unset( $mode_result['meta']['intent_result'] );
                                error_log( '[INTENT-ENGINE] Step 2.0 v4.2: Cleared cross-provider tainted intent_result'
                                    . ' (goal=' . $tainted . ' belongs to ' . $ir_owner->get_id()
                                    . ', not @' . $provider_hint . ')' );
                            }
                        }
                    }
                }
            }
        }

        // ── Step 2.1: (Removed in v3 — LLM-first mode classification) ──
        // Previously: regex-based provider goal pre-check that scanned all registered
        // goal patterns to override mode → execution. Removed because LLM mode classifier
        // now handles this natively with better accuracy and no keyword confusion.

        // ── Step 1.9c override: force execution when returning from content pre-confirm ──
        // Step 1.9c set _multi_preconfirm_state = 'content_provided' after user provided
        // content. resume() changed WAITING_USER → ACTIVE, so Mode Classifier ran full LLM
        // and may misclassify the content text as knowledge/emotion. Force execution so
        // pipeline reaches Step 4.4 Content Gate which handles content_provided state.
        $preconfirm_override_state = ( json_decode( $conversation['slots_json'] ?? '{}', true ) ?: [] )['_multi_preconfirm_state'] ?? '';
        if ( in_array( $preconfirm_override_state, [ 'content_provided', 'confirmed' ], true )
             && $mode_result['mode'] !== BizCity_Mode_Classifier::MODE_EXECUTION
        ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-ENGINE] Step 1.9c override: preconfirm_state=' . $preconfirm_override_state
                         . ' → forced execution (was: ' . $mode_result['mode'] . ')' );
            }
            $mode_result['mode']       = BizCity_Mode_Classifier::MODE_EXECUTION;
            $mode_result['confidence'] = 0.95;
            $mode_result['method']     = $mode_result['method'] . '+preconfirm_execution_override';
        }

        $this->logger->log( 'mode_classify', [
            'mode'       => $mode_result['mode'],
            'confidence' => $mode_result['confidence'],
            'method'     => $mode_result['method'],
            'is_memory'  => $mode_result['is_memory'],
            'mode_ms'    => round( ( microtime( true ) - $mode_start ) * 1000, 2 ),
        ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

        $result['meta']['mode']            = $mode_result['mode'];
        $result['meta']['mode_confidence'] = $mode_result['confidence'];
        $result['meta']['mode_method']     = $mode_result['method'];
        $result['meta']['is_memory']       = $mode_result['is_memory'];

        // ── Log for admin AJAX Console ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $mode_log = [
                'step'             => 'mode_classify',
                'message'          => mb_substr( $message, 0, 200, 'UTF-8' ),
                'mode'             => $mode_result['mode'],
                'confidence'       => $mode_result['confidence'],
                'method'           => $mode_result['method'],
                'is_memory'        => $mode_result['is_memory'],
                'functions_called' => 'mode_classifier->classify()',
                'pipeline'         => [ 'memory_regex', 'context_check', 'unified_llm', $mode_result['method'] ],
                'prompt_preview'   => $mode_result['llm_prompt'] ?? '',
                'response_preview' => 'mode=' . $mode_result['mode'] . ' conf=' . $mode_result['confidence']
                    . ( ! empty( $mode_result['meta']['intent_result']['goal'] ) ? ' goal=' . $mode_result['meta']['intent_result']['goal'] : '' ),
                'mode_ms'          => round( ( microtime( true ) - $mode_start ) * 1000, 2 ),
            ];
            BizCity_User_Memory::log_router_event( $mode_log, $session_id );
        }

        // ── Step 2.1: SLOT ANALYSIS — Unified LLM slot visibility (v3.5.2) ──
        // When Mode Classifier's unified LLM identified a goal with slots,
        // cross-reference against the tool schema and log as a separate
        // console step for debugging filled/missing slot correctness.
        $slot_start    = microtime( true );
        $slot_analysis = BizCity_Slot_Analysis::instance()->analyze( $mode_result, $message, $conversation );
        $slot_ms       = round( ( microtime( true ) - $slot_start ) * 1000, 2 );

        if ( $slot_analysis['has_analysis'] ) {
            $this->logger->log( 'slot_analyze', [
                'goal'           => $slot_analysis['goal'],
                'intent'         => $slot_analysis['intent'],
                'filled_slots'   => $slot_analysis['filled_slots'],
                'missing_slots'  => $slot_analysis['missing_slots'],
                'entities'       => $slot_analysis['entities'],
                'fill_ratio'     => $slot_analysis['fill_ratio'],
                'status'         => $slot_analysis['status'],
                'total_required' => $slot_analysis['total_required'],
                'slot_ms'        => $slot_ms,
            ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

            // ── Log for admin AJAX Console ──
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                BizCity_User_Memory::log_router_event( [
                    'step'             => 'slot_analyze',
                    'message'          => mb_substr( $message, 0, 200, 'UTF-8' ),
                    'mode'             => 'execution → ' . $slot_analysis['goal'],
                    'confidence'       => $mode_result['confidence'],
                    'method'           => 'unified_llm_slots',
                    'functions_called' => 'slot_analysis->analyze()',
                    'pipeline'         => [ 'llm_slots', 'schema_validate', 'cross_reference' ],
                    'filled_slots'     => $slot_analysis['filled_slots'],
                    'missing_slots'    => $slot_analysis['missing_slots'],
                    'entities'         => $slot_analysis['entities'],
                    'fill_ratio'       => $slot_analysis['fill_ratio'],
                    'total_required'   => $slot_analysis['total_required'],
                    'status'           => $slot_analysis['status'],
                    'response_preview' => $slot_analysis['summary'],
                    'slot_ms'          => $slot_ms,
                ], $session_id );
            }

            $result['meta']['slot_analysis'] = [
                'filled_slots'   => $slot_analysis['filled_slots'],
                'missing_slots'  => $slot_analysis['missing_slots'],
                'fill_ratio'     => $slot_analysis['fill_ratio'],
                'status'         => $slot_analysis['status'],
                'total_required' => $slot_analysis['total_required'],
            ];
        }

        // ── Step 2.1.5: INTENSITY DETECTION — empathy routing decision ──
        // Fast keyword-based intensity estimation (0 LLM cost).
        // 6 routing branches determined by mode × intensity threshold:
        //   Branch 1: execution           → tool/pipeline execution
        //   Branch 2: knowledge           → RAG lookup + informational response
        //   Branch 3: reflection          → coaching/mirror questions
        //   Branch 4: emotion (low)       → casual empathy, acknowledge feelings
        //   Branch 5: emotion (high)      → deep empathy, prioritize emotional support
        //   Branch 6: emotion (critical)  → safety check, potential hotline suggestion
        $intensity_start = microtime( true );
        $intensity       = 1;
        $empathy_flag    = false;
        $routing_branch  = 'knowledge'; // default

        if ( class_exists( 'BizCity_Emotional_Memory' ) ) {
            $intensity = BizCity_Emotional_Memory::instance()->estimate_intensity( $message );
        }

        // Determine routing branch based on mode + intensity
        $mode_val = $mode_result['mode'];
        if ( $mode_val === BizCity_Mode_Classifier::MODE_EXECUTION ) {
            $routing_branch = 'execution';
        } elseif ( $mode_val === BizCity_Mode_Classifier::MODE_KNOWLEDGE ) {
            $routing_branch = 'knowledge';
        } elseif ( $mode_val === BizCity_Mode_Classifier::MODE_REFLECTION ) {
            $routing_branch = 'reflection';
        } elseif ( $mode_val === BizCity_Mode_Classifier::MODE_AMBIGUOUS ) {
            $routing_branch = 'ambiguous';
        } elseif ( $mode_val === BizCity_Mode_Classifier::MODE_EMOTION ) {
            if ( $intensity >= 5 ) {
                $routing_branch = 'emotion_critical';
                $empathy_flag   = true;
            } elseif ( $intensity >= 3 ) {
                $routing_branch = 'emotion_high';
                $empathy_flag   = true;
            } else {
                $routing_branch = 'emotion_low';
            }
        } else {
            // Fallback for other modes
            $routing_branch = $mode_val;
        }

        // Store in result meta for downstream (intent_stream, texture engine)
        $result['meta']['intensity']      = $intensity;
        $result['meta']['empathy_flag']   = $empathy_flag;
        $result['meta']['routing_branch'] = $routing_branch;

        // Log intensity detection for debug console
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'intensity_detect',
                'message'          => mb_substr( $message, 0, 200, 'UTF-8' ),
                'mode'             => $mode_val,
                'intensity'        => $intensity,
                'empathy_flag'     => $empathy_flag,
                'routing_branch'   => $routing_branch,
                'functions_called' => 'emotional_memory->estimate_intensity()',
                'pipeline'         => [ 'keyword_scan', 'mode_cross', 'branch_select' ],
                'response_preview' => "intensity={$intensity} empathy=" . ( $empathy_flag ? 'true' : 'false' ) . " branch={$routing_branch}",
                'intensity_ms'     => round( ( microtime( true ) - $intensity_start ) * 1000, 2 ),
            ], $session_id );
        }

        // ── Step 2.2: FORECAST GOAL ABANDON — emotion/topic shift detection ──
        // When mode=execution via CONTEXT (driven by an active WAITING_USER forecast goal),
        // check if the message is actually answering the forecast slot prompt.
        // If not — abandon stale goal, reset to emotion mode so the user is heard properly.
        //
        // Trigger condition:
        //   (a) mode = execution AND method contains 'context' (not driven by pattern match)
        //   (b) active goal is a forecast type: daily_outlook | astro_forecast
        //   (c) conversation status = WAITING_USER
        //   (d) message does NOT match any valid slot answer for that goal
        $forecast_goals = [ 'daily_outlook', 'astro_forecast' ];
        $is_stale_forecast = (
            $mode_result['mode']                    === BizCity_Mode_Classifier::MODE_EXECUTION
            && strpos( $mode_result['method'], 'context' ) !== false
            && ! empty( $conversation['goal'] )
            && in_array( $conversation['goal'], $forecast_goals, true )
            && ( $conversation['status'] ?? '' ) === 'WAITING_USER'
        );
        if ( $is_stale_forecast ) {
            // Valid slot answers for both forecast goals
            // Must stay in sync with bccm_transit_detect_intent() time patterns
            $forecast_slot_answers = [
                // daily_outlook focus_area choices
                'Tình cảm', 'Sự nghiệp', 'Tài chính', 'Tổng quan',
                'tinh_cam', 'su_nghiep', 'tai_chinh', 'tong_quan',
                // astro_forecast type choices
                'natal', 'transit',
                // Day patterns
                'hôm nay', 'ngày mai', 'ngày tới', 'ngày kế', 'tomorrow', 'today',
                'sáng mai', 'chiều mai', 'tối mai', 'đêm nay', '24 giờ tới',
                // Week patterns
                'tuần này', 'tuần tới', 'tuần sau', 'tuần kế', '7 ngày tới',
                'trong tuần', 'cuối tuần',
                // Month patterns
                'tháng này', 'tháng tới', 'tháng sau', '30 ngày tới', 'trong tháng',
                // Year patterns
                'năm nay', 'năm tới', 'năm sau', 'trong năm', '12 tháng tới',
                // Life topic triggers (user providing focus area as free text)
                'tài chính', 'sự nghiệp', 'tình cảm', 'sức khỏe', 'tổng quan',
                'công việc', 'tiền', 'tình yêu', 'gia đình', 'học tập',
                // Generic time references
                'toàn bộ', 'tất cả', 'mọi mặt', 'tổng thể',
            ];
            $msg_lower          = mb_strtolower( $message, 'UTF-8' );
            $is_slot_answer     = false;
            foreach ( $forecast_slot_answers as $slot_val ) {
                if ( strpos( $msg_lower, mb_strtolower( $slot_val, 'UTF-8' ) ) !== false ) {
                    $is_slot_answer = true;
                    break;
                }
            }

            if ( ! $is_slot_answer ) {
                // Abandon stale forecast goal — user has changed topic
                $abandoned_goal = $conversation['goal'];
                $this->conversation_mgr->complete( $conv_id, 'Abandoned: user shifted topic away from ' . $abandoned_goal );

                // Recreate fresh conversation without goal context
                $conversation = $this->conversation_mgr->get_or_create(
                    $user_id, $channel, $session_id, $character_id
                );
                $conv_id                   = $conversation['conversation_id'];
                $result['conversation_id'] = $conv_id;

                // Reset mode to emotion so the message is handled with empathy
                $mode_result['mode']   = BizCity_Mode_Classifier::MODE_EMOTION;
                $mode_result['method'] = 'goal_abandon_override';
                unset( $mode_result['meta']['provider_override'] );

                // Log the override for debug console
                if ( class_exists( 'BizCity_User_Memory' ) ) {
                    BizCity_User_Memory::log_router_event( [
                        'step'             => 'goal_abandon',
                        'message'          => mb_substr( $message, 0, 200, 'UTF-8' ),
                        'mode'             => 'goal_abandon',
                        'functions_called' => 'conversation_mgr->complete() + mode_reset',
                        'pipeline'         => [ 'forecast_slot_check', 'no_match', 'abandon+emotion' ],
                        'file_line'        => 'class-intent-engine.php::step2_2',
                        'abandoned_goal'   => $abandoned_goal,
                        'new_mode'         => BizCity_Mode_Classifier::MODE_EMOTION,
                        'reason'           => 'Message does not match any valid slot answer for forecast goal',
                    ], $session_id );
                }
            }
        }

        // ── Step 2.2.5: Expose mode in result meta so downstream (intent_stream, context builder)
        //    can pass intensity + empathy_flag into bizcity_chat_system_prompt $args.
        //    Must be set AFTER Step 2.2 which may override mode_result['mode'].
        $result['meta']['mode']            = $mode_result['mode'];
        $result['meta']['mode_method']     = $mode_result['method'];
        $result['meta']['mode_confidence'] = $mode_result['confidence'];

        // ── Step 2.3: MEMORY SAVE (priority — before any pipeline) ──
        // If mode classifier detected is_memory=true, save memory NOW so that
        // downstream pipeline (Chat Gateway) will see updated memory in context.
        // We do NOT early-exit — let AI compose a natural response with fresh memory.

        // ── GUARD: Don't intercept as memory when conversation is WAITING_USER ──
        // When the user is mid-flow providing slot input (e.g. topic for write_article),
        // the execution pipeline must handle the message — not memory save.
        // Otherwise the slot value is never stored and the HIL flow breaks.
        if ( ! empty( $mode_result['is_memory'] )
             && ( $conversation['status'] ?? '' ) === 'WAITING_USER'
             && ! empty( $conversation['waiting_field'] )
             && ! empty( $conversation['goal'] )
        ) {
            $mode_result['is_memory'] = false;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-ENGINE] Step 2.3: Suppressed is_memory — conversation is WAITING_USER'
                         . ' | goal=' . $conversation['goal']
                         . ' | waiting_field=' . $conversation['waiting_field'] );
            }
        }

        if ( ! empty( $mode_result['is_memory'] ) && ! empty( $message ) ) {
            do_action( 'bizcity_intent_status', '🧠 Đang ghi nhớ...' );
            // Save memory to DB immediately
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                BizCity_User_Memory::instance()->handle_explicit_memory(
                    [],
                    [
                        'mode_result' => $mode_result,
                        'user_id'     => $user_id,
                        'session_id'  => $session_id,
                        'message'     => $message,
                    ]
                );
            }

            // Close any incorrectly-started set_reminder conversation
            if ( ! empty( $conversation['goal'] ) && $conversation['goal'] === 'set_reminder' ) {
                $this->conversation_mgr->complete( $conv_id, 'Rerouted to user memory save.' );
                // Re-create clean conversation so downstream doesn't see stale goal
                $conversation = $this->conversation_mgr->get_or_create(
                    $user_id, $channel, $session_id, $character_id
                );
                $conv_id = $conversation['conversation_id'];
                $result['conversation_id'] = $conv_id;
            }

            $result['meta']['memory_saved'] = true;

            // Inject a hint into system prompt so AI acknowledges the memory save naturally
            add_filter( 'bizcity_chat_system_prompt', function ( $prompt ) use ( $message ) {
                $prompt .= "\n\n### 🧠 GHI NHỚ VỪA CẬP NHẬT:\n";
                $prompt .= "Người dùng VỪA yêu cầu ghi nhớ thông tin mới: \"{$message}\"\n";
                $prompt .= "Thông tin này ĐÃ ĐƯỢC LƯU vào ký ức dài hạn.\n";
                $prompt .= "Hãy XÁC NHẬN ngắn gọn rằng bạn đã ghi nhớ, sau đó trả lời tự nhiên.\n";
                $prompt .= "Nếu thông tin mới MÂU THUẪN với ký ức cũ (ví dụ: tên cũ khác tên mới), hãy ưu tiên thông tin MỚI.\n";
                return $prompt;
            }, 40 );

            // ── Step 2.3.1: DUAL CHECK — Does a provider goal also match? ──
            // If mode was execution AND a registered provider goal pattern matches,
            // keep execution mode so the intent router can also execute the tool.
            // Example: "anh vừa ăn bát phở bò, ghi vào cho anh nhé"
            //   → Memory saved ✓ (above)
            //   → Intent Router detects calo_log_meal → tool executes ✓
            // This enables "plan + execute" behavior: memory save + tool execution.
            $has_provider_goal_match = false;
            $matched_provider_goal   = '';
            if ( $mode_result['mode'] === BizCity_Mode_Classifier::MODE_EXECUTION ) {
                $goal_patterns = $this->router->get_goal_patterns();
                foreach ( $goal_patterns as $pattern => $config ) {
                    // Only check provider patterns (has _provider_source tag)
                    if ( is_string( $pattern ) && ! empty( $config['_provider_source'] ) && @preg_match( $pattern, $message ) ) {
                        $has_provider_goal_match = true;
                        $matched_provider_goal   = $config['goal'] ?? '';
                        break;
                    }
                }
            }

            // Log for admin AJAX Console
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                $memory_pipeline = $has_provider_goal_match
                    ? [ 'mode_classify', 'memory_save', 'intent_router (dual)' ]
                    : [ 'mode_classify', 'memory_save', 'passthrough_to_ai' ];
                $memory_preview = $has_provider_goal_match
                    ? '→ memory saved + continue to intent router (goal=' . $matched_provider_goal . ')'
                    : '→ passthrough (AI sẽ compose với memory mới)';

                BizCity_User_Memory::log_router_event( [
                    'step'             => 'memory_save',
                    'message'          => mb_substr( $message, 0, 200, 'UTF-8' ),
                    'mode'             => 'memory',
                    'confidence'       => $mode_result['confidence'],
                    'method'           => 'is_memory_flag',
                    'functions_called' => 'handle_explicit_memory()',
                    'pipeline'         => $memory_pipeline,
                    'response_preview' => $memory_preview,
                    'has_provider_goal_match' => $has_provider_goal_match,
                    'matched_provider_goal'   => $matched_provider_goal,
                ], $session_id );
            }

            // Clear is_memory so handle_explicit_memory hook won't double-save
            $mode_result['is_memory'] = false;

            if ( $has_provider_goal_match ) {
                // ── DUAL MODE: Memory + Execution ──
                // Memory is already saved above. Keep mode as execution so the
                // intent router (Step 3) can detect the provider goal and execute
                // the tool (e.g., calo_log_meal). The memory hint in the system
                // prompt will still be present so the AI compose response will
                // acknowledge both the memory save and the tool result.
                $mode_result['method'] = $mode_result['method'] . '+memory_dual';
                $result['meta']['memory_dual_execute'] = true;
                $result['meta']['memory_matched_goal'] = $matched_provider_goal;
                // Fall through to Step 2.5 check → mode is execution → skip → reach Step 3 (Intent Router)
            } else {
                // ── MEMORY ONLY: No provider goal match ──
                // Force mode to knowledge so it goes through passthrough → Chat Gateway
                // instead of entering execution pipeline (which would misroute to set_reminder)
                $mode_result['mode']   = BizCity_Mode_Classifier::MODE_KNOWLEDGE;
                $mode_result['method'] = 'memory_override';
                // Fall through to Step 2.5 (knowledge pipeline → compose → Chat Gateway)
                // Chat Gateway will build AI response with the JUST-SAVED memory
            }
        }

        // ── Step 2.4: BUILT-IN FUNCTION DISPATCH (§29 Priority Functions) ──
        // If LLM classified a built_in_function (e.g., list_memories, end_conversation,
        // explain_last, summarize_session), dispatch it before the Intent Router.
        // save_user_memory is already handled above by is_memory; this enriches the prompt hint.
        $built_in_fn = $mode_result['meta']['built_in_function'] ?? '';
        if ( $built_in_fn !== '' && class_exists( 'BizCity_Priority_Functions' ) ) {
            $fn_ctx = [
                'message'      => $message,
                'user_id'      => $user_id,
                'session_id'   => $session_id,
                'conversation' => $conversation,
                'mode_result'  => $mode_result,
                'channel'      => $channel,
                'character_id' => $character_id,
            ];
            $fn_result = BizCity_Priority_Functions::dispatch( $built_in_fn, $fn_ctx );

            if ( $fn_result ) {
                $result['meta']['built_in_function'] = $built_in_fn;
                $result['meta']['built_in_tier']     = $fn_result['tier'] ?? null;

                // Inject prompt hint so AI compose knows about the function result
                if ( ! empty( $fn_result['prompt_hint'] ) ) {
                    $fn_hint = $fn_result['prompt_hint'];
                    add_filter( 'bizcity_chat_system_prompt', function ( $prompt ) use ( $fn_hint ) {
                        $prompt .= "\n\n### ⚡ BUILT-IN FUNCTION:\n" . $fn_hint . "\n";
                        return $prompt;
                    }, 41 );
                }

                // If function is fully handled and not continuing to router → compose & return
                if ( ! empty( $fn_result['handled'] ) && empty( $fn_result['continue_to_router'] ) ) {
                    // Force mode to knowledge for compose pipeline
                    $mode_result['mode']   = BizCity_Mode_Classifier::MODE_KNOWLEDGE;
                    $mode_result['method'] = 'built_in_function:' . $built_in_fn;
                    // Fall through to Step 2.5 (knowledge pipeline → compose → Chat Gateway)
                }
            }
        }

        // ── Step 2.4.5: CLARIFY GATE (mode-level) ──
        // If prompt is not clear enough yet, ask clarification BEFORE deciding
        // knowledge/execution path. This prevents premature routing.
        // v4.3.7: Skip clarify when classifier used fallback (LLM unavailable) —
        // better to let the main Chat Gateway LLM handle it than trap user in
        // a clarification loop when the ROUTER LLM is temporarily down.
        $is_classifier_fallback = ( $mode_result['method'] ?? '' ) === 'fallback';
        if ( $this->clarify_gate && ! $is_classifier_fallback ) {
            $clarify = $this->clarify_gate->assess_mode( $message, $mode_result, $conversation );
            if ( ! empty( $clarify['should_clarify'] ) ) {
                $this->conversation_mgr->set_waiting( $conv_id, 'clarify', '_clarify_intent' );
                $result['reply']   = $clarify['prompt'];
                $result['action']  = 'ask_user';
                $result['status']  = 'WAITING_USER';
                $result['meta']['clarify_reason'] = $clarify['reason'] ?? 'mode_unclear';
                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'], [
                    'meta' => [ 'ask_field' => '_clarify_intent', 'ask_type' => 'clarify' ],
                ] );
                $this->logger->end_trace( $result );
                do_action( 'bizcity_intent_processed', $result, $params );
                return $result;
            }
        }

        // ── Step 2.4.8: EARLY SKILL LOOKUP (Phase 1.2 + Phase 1.7) ──
        // When /skill_slug or selected_skill is provided, do a direct skill lookup.
        // Otherwise, check if a skill with archetype B/C matches with HIGH confidence.
        // If so, override mode to execution so the Intent Router can handle it.
        // @since v4.6.5 — Also try tool_goal_hint as skill slug when it doesn't match
        //   any goal_pattern (e.g. user typed /contentagentic manually, gateway failed
        //   skill score check → became tool_goal, but Intent Router also doesn't know it).
        $effective_slash = $slash_command ?: $selected_skill;
        $skill_match = $this->early_skill_lookup( $message, $mode_result, $effective_slash );

        // Phase 1.7: When /skill explicitly selected → force HIGH confidence + execution mode
        if ( ! $skill_match && $effective_slash ) {
            // Slash command didn't match via scoring — try direct name/key lookup
            if ( class_exists( 'BizCity_Skill_Manager' ) ) {
                $direct_match = \BizCity_Skill_Manager::instance()->find_matching( [
                    'slash_command' => $effective_slash,
                    'message'       => $message,
                    'limit'         => 1,
                ] );
                if ( ! empty( $direct_match ) ) {
                    $skill_match = \BizCity_Skill_Manager::instance()->find_matching_enriched( [
                        'slash_command' => $effective_slash,
                        'message'       => $message,
                        'limit'         => 1,
                    ] );
                }
            }
        }

        // Phase 1.10: Fallback — tool_goal_hint that doesn't match any goal_pattern
        // may actually be a skill slug (gateway's skill score check was < 30).
        // Try skill lookup with tool_goal_hint before letting it reach the Router.
        if ( ! $skill_match && ! $effective_slash && ! empty( $tool_goal_hint ) ) {
            $goal_patterns = $this->router->get_goal_patterns();
            $tool_goal_lower = mb_strtolower( $tool_goal_hint, 'UTF-8' );
            $is_known_goal = false;
            foreach ( $goal_patterns as $regex => $pcfg ) {
                if ( ( $pcfg['goal'] ?? '' ) === $tool_goal_lower || @preg_match( $regex, $tool_goal_lower ) ) {
                    $is_known_goal = true;
                    break;
                }
            }

            if ( ! $is_known_goal && class_exists( 'BizCity_Skill_Manager' ) ) {
                $skill_match = \BizCity_Skill_Manager::instance()->find_matching_enriched( [
                    'slash_command' => $tool_goal_hint,
                    'message'       => $message,
                    'limit'         => 1,
                ] );

                if ( $skill_match ) {
                    $effective_slash = $tool_goal_hint;
                    error_log( '[INTENT-ENGINE] Step 2.4.8: tool_goal "' . $tool_goal_hint
                        . '" not in goal_patterns → found as skill (archetype='
                        . $skill_match['archetype'] . ' score=' . $skill_match['score'] . ')' );
                }
            }
        }

        // When skill is explicitly selected via / UI, boost confidence to 'high'
        if ( $skill_match && $effective_slash ) {
            $skill_match['confidence'] = 'high';
            $skill_match['reasons'][]  = 'explicit_slash:/' . $effective_slash;
        }

        // Inject skill content into context for LLM (Phase 1.7 FIX-D6)
        if ( $skill_match ) {
            $skill_content = $skill_match['skill']['content'] ?? '';
            if ( $skill_content ) {
                $result['meta']['_injected_skill_context'] = mb_substr( $skill_content, 0, 4000, 'UTF-8' );
            }
            $result['meta']['selected_skill'] = $effective_slash;
            $result['meta']['skill_path']     = $skill_path;
        }

        if ( $skill_match ) {
            $result['meta']['skill_match'] = [
                'archetype'    => $skill_match['archetype'],
                'confidence'   => $skill_match['confidence'],
                'score'        => $skill_match['score'],
                'primary_tool' => $skill_match['primary_tool'],
                'reasons'      => $skill_match['reasons'],
            ];

            // HIGH confidence skill with tools → force execution mode
            if ( $skill_match['confidence'] === 'high'
                 && in_array( $skill_match['archetype'], [ 'B', 'C', 'D' ], true )
                 && $mode_result['mode'] !== BizCity_Mode_Classifier::MODE_EXECUTION
            ) {
                $mode_result['mode']   = BizCity_Mode_Classifier::MODE_EXECUTION;
                $mode_result['method'] = ( $mode_result['method'] ?? '' ) . '+skill_override';
                $result['meta']['mode']        = $mode_result['mode'];
                $result['meta']['mode_method'] = $mode_result['method'];

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[INTENT-ENGINE] Step 2.4.8: Skill override → execution'
                        . ' | archetype=' . $skill_match['archetype']
                        . ' | skill=' . ( $skill_match['skill']['frontmatter']['title'] ?? '?' )
                        . ' | score=' . $skill_match['score'] );
                }

                // Phase 1.2 trace
                if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                    BizCity_Twin_Trace::log( 'skill_override', [
                        'archetype'    => $skill_match['archetype'],
                        'confidence'   => $skill_match['confidence'],
                        'score'        => $skill_match['score'],
                        'primary_tool' => $skill_match['primary_tool'],
                        'skill_title'  => $skill_match['skill']['frontmatter']['title'] ?? '',
                        'original_mode'=> ( $mode_result['method'] ?? '' ),
                    ] );
                }
            }

            // ── Step 2.4.9: ARCHETYPE D — pipeline short-circuit ──
            // Archetype D (agentic pipeline) has explicit steps[]. Instead of
            // falling through to the LLM compose path, we:
            // 1. Fire bizcity_skill_trigger_pipeline → Bridge queues transient
            // 2. Return pipeline_queued reply so Gateway streams it and exits
            // 3. Pipeline processes on shutdown → Messenger sends builder link
            // @since v4.6.5 — Archetype D pipeline early return
            if ( $skill_match['archetype'] === 'D' && $skill_match['confidence'] === 'high' ) {
                $skill_data = $skill_match['skill'] ?? [];
                $skill_data['archetype'] = 'D';
                $skill_data['score']     = $skill_match['score'];
                $skill_data['reasons']   = $skill_match['reasons'];

                // Fire pipeline action → Bridge queues immediately
                do_action( 'bizcity_skill_trigger_pipeline', $skill_data, [
                    'mode'          => $mode_result['mode'],
                    'message'       => $message,
                    'session_id'    => $session_id,
                    'channel'       => $channel,
                    'engine_result' => $result,
                ] );

                $skill_title_d = $skill_match['skill']['frontmatter']['title']
                    ?? $effective_slash ?? 'Unnamed Skill';

                $result['reply']  = '📋 Đã nhận yêu cầu! Đang tạo kịch bản cho kỹ năng **'
                    . esc_html( $skill_title_d ) . '**...\n\n'
                    . '⏳ Pipeline sẽ được tạo trong giây lát. Bạn sẽ nhận được link xem kế hoạch ngay sau đó.';
                $result['action'] = 'pipeline_queued';
                $result['status'] = 'PIPELINE_QUEUED';
                $result['goal']   = 'skill_pipeline:' . ( $effective_slash ?: 'D' );
                $result['meta']['mode']       = $mode_result['mode'];
                $result['meta']['method']     = ( $mode_result['method'] ?? '' ) . '+archetype_D_pipeline';
                $result['meta']['archetype']  = 'D';
                $result['meta']['skill_title'] = $skill_title_d;

                $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
                if ( empty( $conversation['goal'] ) ) {
                    $this->conversation_mgr->set_goal( $conv_id, $result['goal'], '📋 ' . $skill_title_d );
                }

                error_log( '[INTENT-ENGINE] Step 2.4.9: Archetype D → pipeline_queued'
                    . ' | skill=' . $skill_title_d
                    . ' | score=' . $skill_match['score'] );

                if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                    BizCity_Twin_Trace::log( 'archetype_d_pipeline', [
                        'skill_title' => $skill_title_d,
                        'archetype'   => 'D',
                        'score'       => $skill_match['score'],
                        'reasons'     => $skill_match['reasons'],
                    ] );
                }

                $this->logger->end_trace( $result );
                do_action( 'bizcity_intent_processed', $result, $params );
                return $result;
            }

            // Log for admin console
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                BizCity_User_Memory::log_router_event( [
                    'step'             => 'early_skill_lookup',
                    'message'          => mb_substr( $message, 0, 200, 'UTF-8' ),
                    'mode'             => $mode_result['mode'],
                    'archetype'        => $skill_match['archetype'],
                    'confidence'       => $skill_match['confidence'],
                    'score'            => $skill_match['score'],
                    'primary_tool'     => $skill_match['primary_tool'],
                    'reasons'          => $skill_match['reasons'],
                    'functions_called' => 'SkillManager->find_matching_enriched()',
                    'pipeline'         => [ 'skill_scan', 'archetype_detect', 'confidence_tier' ],
                    'skill_title'      => $skill_match['skill']['frontmatter']['title'] ?? '',
                ], $session_id );
            }
        }

        // ── Step 2.4.10: ARCHETYPE A/B SKILL — compose with skill prompt ──
        // When user explicitly selects a /skill that is archetype A (knowledge-only)
        // or B (single-tool), DON'T enter execution pipeline (zero objectives → clarify loop).
        // Instead, inject skill content into system prompt and return passthrough
        // so the LLM uses the skill instructions as context.
        // @since v4.6.5 — Skill-as-prompt for non-pipeline archetypes
        if ( $skill_match
             && $effective_slash
             && in_array( $skill_match['archetype'], [ 'A', 'B' ], true )
        ) {
            $skill_content_raw = $skill_match['skill']['content'] ?? '';
            $skill_title_ab    = $skill_match['skill']['frontmatter']['title']
                ?? $effective_slash ?? 'Unnamed Skill';

            if ( $skill_content_raw ) {
                $skill_prompt = "\n\n## 📘 KỸ NĂNG: " . $skill_title_ab . "\n"
                    . "Người dùng đã chọn kỹ năng /" . $effective_slash . ". "
                    . "Hãy tuân thủ các hướng dẫn bên dưới và trả lời dựa trên nội dung này.\n"
                    . "**GHI NHỚ**: Không hỏi lại \"bạn muốn tìm hiểu hay thực thi\" — hãy THỰC HIỆN theo skill.\n\n"
                    . mb_substr( $skill_content_raw, 0, 4000, 'UTF-8' );

                add_filter( 'bizcity_chat_system_prompt', function ( $prompt ) use ( $skill_prompt ) {
                    return $prompt . $skill_prompt;
                }, 44 );
            }

            // Revert mode to knowledge so Gateway goes to LLM compose
            $mode_result['mode']   = BizCity_Mode_Classifier::MODE_KNOWLEDGE;
            $mode_result['method'] = ( $mode_result['method'] ?? '' ) . '+skill_compose(/' . $effective_slash . ')';

            $result['action'] = 'compose_answer';
            $result['status'] = 'ACTIVE';
            $result['goal']   = 'skill:' . $effective_slash;
            $result['meta']['mode']        = $mode_result['mode'];
            $result['meta']['mode_method'] = $mode_result['method'];
            $result['meta']['archetype']   = $skill_match['archetype'];
            $result['meta']['skill_title'] = $skill_title_ab;

            $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
            if ( empty( $conversation['goal'] ) ) {
                $this->conversation_mgr->set_goal( $conv_id, 'skill:' . $effective_slash, '📘 ' . $skill_title_ab );
            }

            // Persist skill data in slots so Step 1.6D can re-inject on subsequent turns
            $this->conversation_mgr->update_slots( $conv_id, [
                '_active_skill_slug'    => $effective_slash,
                '_active_skill_title'   => $skill_title_ab,
                '_active_skill_content' => mb_substr( $skill_content_raw, 0, 4000, 'UTF-8' ),
            ] );

            error_log( '[INTENT-ENGINE] Step 2.4.10: Archetype ' . $skill_match['archetype']
                . ' skill → compose_answer | skill=' . $skill_title_ab
                . ' | content_len=' . strlen( $skill_content_raw ) );

            if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                BizCity_Twin_Trace::log( 'skill_compose', [
                    'archetype'    => $skill_match['archetype'],
                    'skill_title'  => $skill_title_ab,
                    'content_len'  => strlen( $skill_content_raw ),
                ] );
            }

            $this->logger->end_trace( $result );
            do_action( 'bizcity_intent_processed', $result, $params );
            return $result;
        }

        // ── Step 2.4.11: MULTI-ACTION EXECUTION OVERRIDE ──
        // When Mode Classifier (or its cache) returned non-execution mode, but the
        // message matches 2+ DISTINCT registered goal patterns → force execution.
        // Without this, multi-action commands like "đăng bài lên web, đăng facebook"
        // get routed to the knowledge pipeline and never reach the Intent Router
        // (Step 3) nor Objective Understanding (Step 4.4).
        // @since v4.9.3 — Multi-action pattern guard before Step 2.5
        if ( $mode_result['mode'] !== BizCity_Mode_Classifier::MODE_EXECUTION
             && ! empty( $message )
        ) {
            $msg_lower_2411    = mb_strtolower( trim( $message ), 'UTF-8' );
            $patterns_2411     = $this->router->get_goal_patterns();
            $matched_goals_2411 = [];

            foreach ( $patterns_2411 as $pat_2411 => $cfg_2411 ) {
                if ( ! is_string( $pat_2411 ) || empty( $cfg_2411['goal'] ) ) {
                    continue;
                }
                if ( @preg_match( $pat_2411, $msg_lower_2411 ) ) {
                    // Respect negative gate
                    if ( ! empty( $cfg_2411['negative'] ) && @preg_match( $cfg_2411['negative'], $msg_lower_2411 ) ) {
                        continue;
                    }
                    $goal_2411 = $cfg_2411['goal'];
                    // Deduplicate — only count distinct goals
                    if ( ! isset( $matched_goals_2411[ $goal_2411 ] ) ) {
                        $matched_goals_2411[ $goal_2411 ] = $cfg_2411;
                    }
                }
            }

            // 2+ distinct goals matched → clearly a multi-action execution message
            if ( count( $matched_goals_2411 ) >= 2 ) {
                $original_mode_2411 = $mode_result['mode'];
                $mode_result['mode']       = BizCity_Mode_Classifier::MODE_EXECUTION;
                $mode_result['confidence'] = max( (float) ( $mode_result['confidence'] ?? 0 ), 0.85 );
                $mode_result['method']     = ( $mode_result['method'] ?? '' )
                    . '+multi_action_override(' . implode( ',', array_keys( $matched_goals_2411 ) ) . ')';

                $result['meta']['mode']        = $mode_result['mode'];
                $result['meta']['mode_method'] = $mode_result['method'];

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[INTENT-ENGINE] Step 2.4.11: Multi-action override → execution'
                        . ' | goals=' . implode( ',', array_keys( $matched_goals_2411 ) )
                        . ' | original_mode=' . $original_mode_2411
                        . ' | msg=' . mb_substr( $message, 0, 80, 'UTF-8' ) );
                }
            }
        }

        // ── Step 2.5: NON-EXECUTION MODE PIPELINE ──
        // If mode is NOT execution → delegate to the appropriate pipeline
        // These pipelines handle emotion, reflection, knowledge, ambiguous without intent extraction
        if ( $mode_result['mode'] !== BizCity_Mode_Classifier::MODE_EXECUTION ) {
            $pipeline = $this->pipelines->get( $mode_result['mode'] );

            if ( $pipeline ) {
                $pipeline_ctx = [
                    'message'      => $message,
                    'session_id'   => $session_id,
                    'user_id'      => $user_id,
                    'channel'      => $channel,
                    'character_id' => $character_id,
                    'images'       => $images,
                    'message_id'   => $message_id,
                    'conversation' => $conversation,
                    'mode_result'  => $mode_result,
                    'extra'        => $params['extra'] ?? [],
                ];

                do_action( 'bizcity_intent_status', '💭 Đang xử lý ' . BizCity_Mode_Classifier::get_mode_label( $mode_result['mode'] ) . '...' );
            $pipeline_result = $pipeline->process( $pipeline_ctx );

                // ── Log pipeline process for admin AJAX Console ──
                if ( class_exists( 'BizCity_User_Memory' ) ) {
                    $pipeline_log = [
                        'step'             => 'pipeline_process',
                        'message'          => mb_substr( $message, 0, 200, 'UTF-8' ),
                        'mode'             => $mode_result['mode'],
                        'confidence'       => $mode_result['confidence'],
                        'method'           => $mode_result['method'],
                        'functions_called' => get_class( $pipeline ) . '->process()',
                        'pipeline'         => [ 'mode_classify', get_class( $pipeline ), $pipeline_result['action'] ?? 'reply' ],
                        'file_line'        => 'class-intent-engine.php:~L908',
                        'response_preview' => mb_substr( $pipeline_result['reply'] ?? '(compose → Chat Gateway)', 0, 200, 'UTF-8' ),
                    ];
                    BizCity_User_Memory::log_router_event( $pipeline_log, $session_id );
                }

                // Fire action for User Memory and other handlers
                do_action( 'bizcity_intent_mode_processed', $pipeline_result, $pipeline_ctx );

                // If pipeline has a direct reply → return it
                if ( ! empty( $pipeline_result['reply'] ) ) {
                    $result['reply']  = $pipeline_result['reply'];
                    $result['action'] = 'reply';
                    $result['meta']['pipeline'] = $pipeline_result['meta'] ?? [];

                    // ── Set lightweight goal so non-execution pipeline results
                    //    appear in the sidebar "Nhiệm vụ" task list ──
                    $meta = $pipeline_result['meta'] ?? [];
                    if ( ! empty( $meta['provider'] ) ) {
                        // Knowledge provider pipeline (Gemini / ChatGPT / Router)
                        $mode_goal  = 'knowledge:' . $meta['provider'];
                        $mode_label = '📚 ' . ( $meta['provider_label'] ?? ucfirst( $meta['provider'] ) );
                    } else {
                        // Other non-execution pipelines (emotion, reflection)
                        $mode_goal  = 'mode:' . $mode_result['mode'];
                        $mode_label = BizCity_Mode_Classifier::get_mode_label( $mode_result['mode'] );
                    }
                    // Only set goal if conversation has no goal yet (don't overwrite execution goals)
                    if ( empty( $conversation['goal'] ) ) {
                        $this->conversation_mgr->set_goal( $conv_id, $mode_goal, $mode_label );
                    }

                    $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
                    $this->logger->end_trace( $result );
                    do_action( 'bizcity_intent_processed', $result, $params );
                    return $result;
                }

                // Pipeline says "compose" → needs AI but with enriched system prompt
                if ( ( $pipeline_result['action'] ?? '' ) === 'compose' ) {
                    $result['reply']  = ''; // Signal: needs AI compose
                    $result['action'] = 'passthrough';
                    $result['meta']['pipeline']            = $pipeline_result['meta'] ?? [];
                    $result['meta']['system_prompt_parts']  = $pipeline_result['system_prompt_parts'] ?? [];
                    $result['meta']['mode_temperature']     = $pipeline_result['meta']['temperature'] ?? null;

                    // Inject system prompt parts via filter
                    $extra_prompts = $pipeline_result['system_prompt_parts'] ?? [];
                    $mode_temp     = $pipeline_result['meta']['temperature'] ?? null;
                    if ( ! empty( $extra_prompts ) ) {
                        add_filter( 'bizcity_chat_system_prompt', function ( $prompt ) use ( $extra_prompts ) {
                            return $prompt . "\n\n" . implode( "\n\n", $extra_prompts );
                        }, 45 );
                    }
                    if ( $mode_temp !== null ) {
                        add_filter( 'bizcity_chat_ai_temperature', function () use ( $mode_temp ) {
                            return $mode_temp;
                        }, 10 );
                    }

                    $result['rolling_summary'] = $conversation['rolling_summary'] ?? '';
                    $result['meta']['trace_id'] = $this->logger->get_trace_id();
                    $this->logger->end_trace( $result );
                    do_action( 'bizcity_intent_processed', $result, $params );
                    return $result;
                }
            }
        }

        // ── Step 2.9: SKIP ROUTER for content pre-confirm resume (v4.9.1) ──
        // When HIL Content-First flow is active (state=content_provided or confirmed),
        // the user's message is CONTENT for the existing multi-objective workflow, NOT
        // a new command. Running Router on content text causes misclassification
        // (e.g. "Xây dựng bản sao song sinh..." → mindmap instead of write_article content).
        // Construct synthetic intent to re-enter Step 4.4 with existing goal.
        $preconfirm_skip_router = false;
        $preconfirm_slots_29    = json_decode( $conversation['slots_json'] ?? '{}', true ) ?: [];
        $preconfirm_state_29    = $preconfirm_slots_29['_multi_preconfirm_state'] ?? '';

        if ( in_array( $preconfirm_state_29, [ 'content_provided', 'confirmed' ], true )
             && ! empty( $conversation['goal'] )
        ) {
            $preconfirm_skip_router = true;
            $intent = [
                'intent'          => 'new_goal',
                'goal'            => $conversation['goal'],
                'goal_label'      => $conversation['goal_label'] ?? $conversation['goal'],
                'confidence'      => 0.99,
                'method'          => 'preconfirm_resume_synthetic',
                'entities'        => $conversation['slots'] ?? [],
                'suggested_tools' => [],
                'missing_fields'  => [],
                'goal_objective'  => '',
            ];

            $this->logger->log( 'classify', [
                'intent'          => $intent['intent'],
                'goal'            => $intent['goal'],
                'confidence'      => $intent['confidence'],
                'method'          => $intent['method'],
                'preconfirm_state' => $preconfirm_state_29,
                'skipped_router'  => true,
            ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-ENGINE] Step 2.9: Skipped Router — preconfirm_state='
                    . $preconfirm_state_29 . ' → synthetic new_goal:' . $conversation['goal'] );
            }

            // Set result meta so downstream steps see intent metadata
            $result['meta']['intent']          = $intent['intent'];
            $result['meta']['confidence']      = $intent['confidence'];
            $result['meta']['method']          = $intent['method'];
            $result['meta']['suggested_tools'] = [];
            $result['meta']['missing_fields']  = [];
            $result['meta']['goal_objective']  = '';
        }

        if ( ! $preconfirm_skip_router ) {
        // ── Step 3: INTENT CLASSIFICATION (Tầng 2 — Only for Execution mode) ──
        // Pass mode_result so Router can use unified LLM result (v3.5 — skip Router LLM when mode classifier already identified goal)
        do_action( 'bizcity_intent_status', '⚡ Đang nhận diện hành động...' );
        $classify_start = microtime( true );
        $intent = $this->router->classify( $message, $conversation, $images, $provider_hint, $mode_result, [
            'tool_goal'   => $params['tool_goal'] ?? '',
            'skill_match' => $skill_match,
        ] );
        $this->logger->log( 'classify', [
            'intent'          => $intent['intent'],
            'goal'            => $intent['goal'] ?? '',
            'confidence'      => $intent['confidence'] ?? 0,
            'method'          => $intent['method'] ?? '',
            'entities'        => $intent['entities'] ?? [],
            'suggested_tools' => $intent['suggested_tools'] ?? [],
            'missing_fields'  => $intent['missing_fields'] ?? [],
            'goal_objective'  => $intent['goal_objective'] ?? '',
            'classify_ms'     => round( ( microtime( true ) - $classify_start ) * 1000, 2 ),
        ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

        $result['meta']['intent']          = $intent['intent'];
        $result['meta']['confidence']      = $intent['confidence'];
        $result['meta']['method']          = $intent['method'];
        $result['meta']['suggested_tools'] = $intent['suggested_tools'] ?? [];
        $result['meta']['missing_fields']  = $intent['missing_fields'] ?? [];
        $result['meta']['goal_objective']  = $intent['goal_objective'] ?? '';

        // ── Step 3.1.5: MULTI-ACTION GOAL RESCUE ──
        // When Router returned no goal (mode=execution but intent unresolved),
        // check if the message matches any registered goal_pattern.
        // Multi-action messages like "đăng bài lên web, đăng facebook" confuse
        // the LLM (expects exactly 1 tool match), but regex patterns can still
        // match individual segments. Use the first match as the primary goal —
        // Step 4.4 (Objective Understanding) will decompose multi-objective.
        // This prevents the Clarify Gate from trapping multi-action execution requests.
        // @since v4.9.2 — Multi-action goal rescue before Clarify Gate
        $intent_goal_s315    = $intent['goal'] ?? '';
        $intent_name_s315    = $intent['intent'] ?? '';
        $needs_rescue_s315   = empty( $intent_goal_s315 )
            || ! in_array( $intent_name_s315, [ 'new_goal', 'provide_input', 'continue_goal', 'end_conversation' ], true );

        if ( $needs_rescue_s315 && ! empty( $message ) ) {
            $message_lower_s315  = mb_strtolower( trim( $message ), 'UTF-8' );
            $goal_patterns_s315  = $this->router->get_goal_patterns();
            $rescue_candidates   = [];

            // Specificity tiers — same as Router Step 3c
            $specificity_conf_s315 = [
                'exact'  => 0.95,
                'narrow' => 0.90,
                'broad'  => 0.65,
            ];

            foreach ( $goal_patterns_s315 as $pat_s315 => $cfg_s315 ) {
                if ( ! is_string( $pat_s315 ) || empty( $cfg_s315['goal'] ) ) {
                    continue;
                }
                if ( @preg_match( $pat_s315, $message_lower_s315, $m_s315 ) ) {
                    // Respect negative gate
                    if ( ! empty( $cfg_s315['negative'] ) && @preg_match( $cfg_s315['negative'], $message_lower_s315 ) ) {
                        continue;
                    }
                    // Domain keywords gate
                    if ( ! empty( $cfg_s315['domain_keywords'] ) && is_array( $cfg_s315['domain_keywords'] ) ) {
                        $has_kw_s315 = false;
                        foreach ( $cfg_s315['domain_keywords'] as $kw_s315 ) {
                            if ( mb_stripos( $message_lower_s315, $kw_s315 ) !== false ) {
                                $has_kw_s315 = true;
                                break;
                            }
                        }
                        if ( ! $has_kw_s315 ) {
                            continue; // Skip candidates gated by domain keywords not present
                        }
                    }
                    $tier_s315  = $cfg_s315['specificity'] ?? 'broad';
                    $score_s315 = $specificity_conf_s315[ $tier_s315 ] ?? 0.65;
                    // Bonus: content_production family > distribution family (prefer write_article over post_facebook)
                    if ( in_array( $cfg_s315['goal'], [ 'write_article', 'generate_blog_content' ], true ) ) {
                        $score_s315 += 0.05;
                    }
                    $cfg_s315['_rescue_score'] = $score_s315;
                    $rescue_candidates[] = $cfg_s315;
                }
            }

            // Sort by score DESC — pick highest specificity as primary goal
            usort( $rescue_candidates, function( $a, $b ) {
                return ( $b['_rescue_score'] ?? 0 ) <=> ( $a['_rescue_score'] ?? 0 );
            } );

            if ( ! empty( $rescue_candidates ) ) {
                $rescue_goal  = $rescue_candidates[0]['goal'];
                $rescue_label = $rescue_candidates[0]['label'] ?? $rescue_goal;

                $intent['intent']     = 'new_goal';
                $intent['goal']       = $rescue_goal;
                $intent['goal_label'] = $rescue_label;
                $intent['confidence'] = max( (float) ( $intent['confidence'] ?? 0 ), 0.80 );
                $intent['method']     = ( $intent['method'] ?? '' ) . '+multi_action_rescue';
                if ( empty( $intent['entities'] ) || ! is_array( $intent['entities'] ) ) {
                    $intent['entities'] = [ '_raw_message' => $message ];
                }

                $result['meta']['intent']     = $intent['intent'];
                $result['meta']['confidence'] = $intent['confidence'];
                $result['meta']['method']     = $intent['method'];

                error_log( '[INTENT-ENGINE] Step 3.1.5: Multi-action goal rescue → '
                    . $rescue_goal . ' (' . count( $rescue_candidates ) . ' candidates)'
                    . ' | msg=' . mb_substr( $message, 0, 80, 'UTF-8' ) );
            }
        }

        // ── Step 3.2: CLARIFY GATE (intent-level) ──
        // Router ran, but intent still unresolved: require user clarification before
        // moving to goal handling/planning.
        if ( $this->clarify_gate ) {
            $clarify_intent = $this->clarify_gate->assess_intent( $message, $intent, $conversation );
            if ( ! empty( $clarify_intent['should_clarify'] ) ) {
                $this->conversation_mgr->set_waiting( $conv_id, 'clarify', '_clarify_intent' );
                $result['reply']   = $clarify_intent['prompt'];
                $result['action']  = 'ask_user';
                $result['status']  = 'WAITING_USER';
                $result['meta']['clarify_reason'] = $clarify_intent['reason'] ?? 'intent_unresolved';
                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'], [
                    'meta' => [ 'ask_field' => '_clarify_intent', 'ask_type' => 'clarify' ],
                ] );
                $this->logger->end_trace( $result );
                do_action( 'bizcity_intent_processed', $result, $params );
                return $result;
            }
        }

        // ── Log intent classification for admin AJAX Console ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $router_debug = $intent['_debug'] ?? [];
            $registry     = class_exists( 'BizCity_Intent_Provider_Registry' )
                          ? BizCity_Intent_Provider_Registry::instance() : null;

            $log_event = [
                'step'             => 'intent_classify',
                'message'          => mb_substr( $message, 0, 200, 'UTF-8' ),
                'mode'             => 'execution → ' . ( $intent['intent'] ?? '?' ),
                'confidence'       => $intent['confidence'] ?? 0,
                'method'           => $intent['method'] ?? '',
                'functions_called' => 'router->classify()',
                'pipeline'         => [ 'unified_check', 'cache_check', $intent['method'] ?? 'llm' ],
                'prompt_preview'   => $intent['llm_prompt'] ?? '',
                'response_preview' => 'intent=' . ( $intent['intent'] ?? '?' ) . ' goal=' . ( $intent['goal'] ?? '' ),
                'classify_ms'      => round( ( microtime( true ) - $classify_start ) * 1000, 2 ),
            ];

            // ── Router debug details for console ──
            if ( ! empty( $router_debug['classify_step'] ) ) {
                $log_event['classify_step']   = $router_debug['classify_step'];
            }
            if ( ! empty( $router_debug['matched_pattern'] ) ) {
                $log_event['matched_pattern'] = $router_debug['matched_pattern'];
                $log_event['pattern_source']  = $router_debug['pattern_source'] ?? '';
            }
            if ( ! empty( $router_debug['active_goal'] ) ) {
                $log_event['active_goal']        = $router_debug['active_goal'];
                $log_event['active_goal_status'] = $router_debug['active_goal_status'] ?? '';
            }
            if ( isset( $router_debug['pattern_count'] ) ) {
                $log_event['pattern_count']          = $router_debug['pattern_count'];
                $log_event['provider_pattern_count'] = $router_debug['provider_pattern_count'] ?? 0;
            }
            // Include candidate list (trimmed for transient size)
            if ( ! empty( $router_debug['all_goal_candidates'] ) ) {
                $candidates_trimmed = array_map( function( $c ) {
                    return [
                        'goal'    => $c['goal'],
                        'source'  => $c['source'] ?? '',
                        'matched' => $c['matched'],
                    ];
                }, $router_debug['all_goal_candidates'] );
                // Only include first 30 to keep transient size reasonable
                $log_event['all_goal_candidates'] = array_slice( $candidates_trimmed, 0, 30 );
            }
            // Registered providers & goal map
            if ( $registry ) {
                $log_event['registered_providers'] = array_keys( $registry->get_all() );
                $log_event['goal_map'] = [];
                foreach ( $registry->get_all() as $provider ) {
                    foreach ( $provider->get_owned_goals() as $goal ) {
                        $log_event['goal_map'][ $goal ] = $provider->get_id();
                    }
                }
            }

            BizCity_User_Memory::log_router_event( $log_event, $session_id );

            // ── v4.3: Separate pipe step for Tool Registry Search ──
            // When the Router ran Step 3c.5 (tool_registry keyword rescue),
            // emit a dedicated log entry so admins can see the search details
            // as a distinct step in the pipeline console.
            $reg_search = $router_debug['registry_search'] ?? null;
            if ( $reg_search && $reg_search['attempted'] ) {
                $reg_match  = $reg_search['best_match'];
                $reg_outcome = $reg_search['outcome'] ?? 'unknown';
                $reg_preview = $reg_outcome;
                if ( $reg_match ) {
                    $reg_preview .= ' → ' . $reg_match['goal'] . ' (score=' . $reg_match['score']
                                  . ' reason=' . ( $reg_match['reason'] ?? '' ) . ')';
                }
                BizCity_User_Memory::log_router_event( [
                    'step'             => 'tool_registry_search',
                    'message'          => mb_substr( $message, 0, 200, 'UTF-8' ),
                    'mode'             => 'classify → step3c.5',
                    'confidence'       => $reg_match ? min( 0.85, 0.5 + $reg_match['score'] * 0.02 ) : 0,
                    'method'           => 'keyword_search',
                    'functions_called' => 'router->search_tool_registry_by_message()',
                    'pipeline'         => [
                        'extract_keywords(' . count( $reg_search['keywords'] ?? [] ) . ')',
                        'scan_tool_registry',
                        'score_matches',
                        'threshold_check(≥' . ( $reg_search['threshold'] ?? 10 ) . ')',
                        'outcome:' . $reg_outcome,
                    ],
                    'response_preview' => $reg_preview,
                    'keywords'         => $reg_search['keywords'] ?? [],
                    'best_match'       => $reg_match,
                    'threshold'        => $reg_search['threshold'] ?? 10,
                    'outcome'          => $reg_outcome,
                    'registry_ms'      => $reg_search['search_ms'] ?? 0,
                    'file_line'        => 'class-intent-router.php::step3c5_tool_registry_rescue',
                ], $session_id );
            }
        }

        // v4.3.4: Pass Router's registry search result to downstream layers (Stream/Gateway)
        // so they can skip their own weaker keyword scan when Router already searched.
        if ( ! empty( $router_debug['registry_search'] ) ) {
            $result['meta']['_router_registry_search'] = $router_debug['registry_search'];
        }

        // ── Step 3.5: SLOT CROSS-VALIDATION — Tier 1 LLM priority over Tier 2 (v4.8.0) ──
        // v4.8.0 REDESIGN: The root cause of entity hallucination was Tier 2 (extraction-only LLM)
        // overriding Tier 1 (unified LLM with full context). This is now fixed at the source:
        //   - Router Step 0.5 only triggers Tier 2 when entities are empty due to regex_goal
        //     (regex can't extract entities, so LLM extraction is justified).
        //   - When unified LLM explicitly concluded "no entities", Tier 2 is SKIPPED.
        //
        // The regex filler-word heuristic (v3.6.3→v4.7.1) is REMOVED — it was a brittle
        // workaround that required maintaining a growing word list and could never match
        // the LLM's contextual understanding.
        //
        // Remaining safety: If Slot Analysis (Tier 1) flagged ALL required slots as missing
        // AND Router still has entities (from regex_goal Tier 2), log for monitoring.
        // This should be rare after the Router fix.
        if ( ! empty( $slot_analysis['has_analysis'] )
             && $slot_analysis['status'] === 'empty'
             && ! empty( $slot_analysis['missing_slots'] )
             && ! empty( $intent['entities'] )
             && in_array( $intent['intent'] ?? '', [ 'new_goal', 'continue_goal' ], true )
        ) {
            // Log conflict for monitoring — Tier 2 extracted entities that Tier 1 didn't see.
            // This should only happen for regex_goal paths (legitimate Tier 2 extraction).
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $tier2_keys = array_keys( array_filter( $intent['entities'], function( $v, $k ) {
                    return $k !== '_images' && $k !== '_raw_message' && $k !== 'message' && ! empty( $v );
                }, ARRAY_FILTER_USE_BOTH ) );
                if ( ! empty( $tier2_keys ) ) {
                    error_log( '[INTENT-ENGINE] Step 3.5 monitoring: Tier 1 says empty, Router has entities ['
                             . implode( ',', $tier2_keys ) . '] — method=' . ( $intent['method'] ?? '?' )
                             . ' | msg="' . mb_substr( $message, 0, 80, 'UTF-8' ) . '"' );
                }
            }
        }

        // ── Step 3.6: PROVIDER SCOPE GUARD — prevent @mention cross-provider misrouting (v4.1) ──
        // Handles two scenarios:
        //   A) No active goal: Router returned wrong provider's goal or small_talk
        //   B) Cross-provider: Active goal from provider A, user @mentioned provider B
        // In both cases → try Pattern Rescue first, then tool list or upgrade.
        if ( $provider_hint
             && class_exists( 'BizCity_Intent_Provider_Registry' )
        ) {
            $hint_registry = BizCity_Intent_Provider_Registry::instance();
            $hint_provider = $hint_registry->get( $provider_hint );

            if ( $hint_provider ) {
                $intent_goal       = $intent['goal'] ?? '';
                $intent_type       = $intent['intent'] ?? '';
                $conv_goal         = $conversation['goal'] ?? '';
                $needs_tool_prompt = false;

                // ── Detect cross-provider switch ──
                // User @mentioned provider B but active conversation belongs to provider A
                $is_cross_provider = false;
                if ( ! empty( $conv_goal ) ) {
                    $active_owner = $hint_registry->get_provider_for_goal( $conv_goal );
                    $is_cross_provider = ! $active_owner || $active_owner->get_id() !== $provider_hint;
                }

                if ( $is_cross_provider ) {
                    // User explicitly wants a different provider → always re-route
                    $needs_tool_prompt = true;

                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[INTENT-ENGINE] Step 3.6: Cross-provider detected: active_goal='
                            . $conv_goal . ' belongs to ' . ( $active_owner ? $active_owner->get_id() : '?' )
                            . ' but @mention=' . $provider_hint );
                    }
                } elseif ( empty( $conv_goal ) ) {
                    // ── Original logic: No active goal ──
                    if ( $intent_type === 'new_goal' && ! empty( $intent_goal ) ) {
                        $goal_owner = $hint_registry->get_provider_for_goal( $intent_goal );
                        if ( $goal_owner && $goal_owner->get_id() !== $provider_hint ) {
                            $needs_tool_prompt = true; // Goal from wrong provider
                        }
                    } elseif ( in_array( $intent_type, [ 'small_talk', '' ], true ) ) {
                        $needs_tool_prompt = true;
                    }
                }
                // else: active goal is from the SAME @mentioned provider → no intervention

                if ( $needs_tool_prompt ) {
                    // ── RESCUE: Try provider's own regex patterns before showing tool list ──
                    $message_lower_sg = mb_strtolower( trim( $message ), 'UTF-8' );
                    $rescued_goal = null;
                    foreach ( $hint_provider->get_goal_patterns() as $pattern => $cfg ) {
                        if ( @preg_match( $pattern, $message_lower_sg ) ) {
                            $rescued_goal = $cfg;
                            break;
                        }
                    }

                    if ( $rescued_goal && ! empty( $rescued_goal['goal'] ) ) {
                        // Pattern matched a provider goal — override to new_goal
                        $intent['intent']     = 'new_goal';
                        $intent['goal']       = $rescued_goal['goal'];
                        $intent['goal_label'] = $rescued_goal['label'] ?? $rescued_goal['goal'];
                        $intent['confidence'] = 0.9;
                        $intent['method']     = ( $intent['method'] ?? '' ) . '+provider_pattern_rescue';
                        $needs_tool_prompt = false;

                        error_log( '[INTENT-ENGINE] Step 3.6: Provider pattern rescue → goal=' . $rescued_goal['goal'] . ' for @' . $provider_hint );
                    }
                }

                // ── Cross-provider upgrade: Router found correct provider but as provide_input ──
                // When no pattern rescue happened but Router already identified the @mentioned
                // provider's goal (just classified as provide_input instead of new_goal),
                // upgrade to new_goal so Step 4b properly switches the conversation.
                if ( $is_cross_provider && $needs_tool_prompt && ! empty( $intent_goal ) ) {
                    $intent_owner = $hint_registry->get_provider_for_goal( $intent_goal );
                    if ( $intent_owner && $intent_owner->get_id() === $provider_hint ) {
                        $intent['intent'] = 'new_goal';
                        $intent['method'] = ( $intent['method'] ?? '' ) . '+cross_provider_upgrade';
                        $needs_tool_prompt = false;

                        error_log( '[INTENT-ENGINE] Step 3.6: Cross-provider upgrade → new_goal for ' . $intent_goal );
                    }
                }

                // ── If still needs tool prompt, check anti-loop: don't repeat tool list ──
                if ( $needs_tool_prompt ) {
                    // Collect the provider's goals for tool listing
                    $provider_goals = [];
                    foreach ( $hint_provider->get_goal_patterns() as $pattern => $cfg ) {
                        $g = $cfg['goal'] ?? '';
                        if ( $g && ! isset( $provider_goals[ $g ] ) ) {
                            $provider_goals[ $g ] = $cfg['label'] ?? $g;
                        }
                    }

                    if ( ! empty( $provider_goals ) ) {
                        // Anti-loop: If conversation is already WAITING_USER with empty goal
                        // (tool list was shown previously), don't repeat.
                        $already_showed_list = ( $conversation['status'] ?? '' ) === 'WAITING_USER'
                            && empty( $conversation['goal'] );

                        if ( $already_showed_list ) {
                            $fallback_goal = null;
                            foreach ( $provider_goals as $g_key => $g_label ) {
                                $fallback_goal = [ 'goal' => $g_key, 'label' => $g_label ];
                            }
                            if ( $fallback_goal ) {
                                $intent['intent']     = 'new_goal';
                                $intent['goal']       = $fallback_goal['goal'];
                                $intent['goal_label'] = $fallback_goal['label'];
                                $intent['confidence'] = 0.75;
                                $intent['method']     = ( $intent['method'] ?? '' ) . '+anti_loop_fallback';
                                $intent['entities']   = array_merge( $intent['entities'] ?? [], [ '_raw_message' => $message ] );

                                error_log( '[INTENT-ENGINE] Step 3.6: Anti-loop fallback → goal=' . $fallback_goal['goal'] . ' for @' . $provider_hint );
                            }
                        } else {
                            // ── Smart prediction: predict which tool the user likely wants ──
                            $predicted_goal    = null;
                            $predicted_label   = null;
                            $prediction_method = '';

                            // Logic 1: Router auto-intent already matched one of this provider's goals
                            if ( ! empty( $intent_goal ) && isset( $provider_goals[ $intent_goal ] ) ) {
                                $predicted_goal    = $intent_goal;
                                $predicted_label   = $provider_goals[ $intent_goal ];
                                $prediction_method = 'auto_intent';
                            }

                            // Logic 2: Keyword match from user message against provider goal keys + labels
                            if ( ! $predicted_goal ) {
                                $msg_lower_pred  = mb_strtolower( trim( $message ), 'UTF-8' );
                                $best_pred_score = 0;
                                foreach ( $provider_goals as $g_key => $g_label ) {
                                    $score       = 0;
                                    $label_lower = mb_strtolower( $g_label, 'UTF-8' );
                                    $goal_lower  = mb_strtolower( $g_key, 'UTF-8' );
                                    $goal_words  = array_filter(
                                        preg_split( '/[\s_\-]+/u', $goal_lower . ' ' . $label_lower ),
                                        fn( $w ) => mb_strlen( $w, 'UTF-8' ) >= 2
                                    );
                                    foreach ( $goal_words as $gw ) {
                                        if ( mb_strpos( $msg_lower_pred, $gw ) !== false ) {
                                            $score += 10;
                                        }
                                    }
                                    if ( $score > $best_pred_score ) {
                                        $best_pred_score = $score;
                                        $predicted_goal  = $g_key;
                                        $predicted_label = $g_label;
                                        $prediction_method = 'keyword_match';
                                    }
                                }
                                if ( $best_pred_score < 10 ) {
                                    $predicted_goal  = null;
                                    $predicted_label = null;
                                }
                            }

                            // Logic 3: Only 1 tool → predict it directly
                            if ( ! $predicted_goal && count( $provider_goals ) === 1 ) {
                                $predicted_goal    = array_key_first( $provider_goals );
                                $predicted_label   = reset( $provider_goals );
                                $prediction_method = 'single_tool';
                            }

                            $provider_name = $hint_provider->get_name();

                            // ── Build reply based on prediction ──
                            if ( $predicted_goal ) {
                                $other_goals = array_diff_key( $provider_goals, [ $predicted_goal => true ] );
                                if ( empty( $other_goals ) ) {
                                    // Only 1 tool → confirm directly, no listing
                                    $result['reply'] = "Mình sẽ dùng **{$predicted_label}** để giúp bạn nhé? Trả lời \"chạy\" để bắt đầu 🚀";
                                } else {
                                    // Multiple tools → highlight predicted + list alternatives
                                    $other_list = '';
                                    $i = 1;
                                    foreach ( $other_goals as $og_key => $og_label ) {
                                        $other_list .= "{$i}. {$og_label}\n";
                                        $i++;
                                    }
                                    $result['reply'] = "Mình sẽ dùng **{$predicted_label}** để giúp bạn nhé?\n\nNgoài ra, trợ lý **{$provider_name}** còn có thể:\n{$other_list}\nTrả lời \"chạy\" để bắt đầu hoặc chọn công cụ khác 💡";
                                }

                                // Set predicted goal on conversation so next turn ("chạy") flows naturally
                                $result['goal']       = $predicted_goal;
                                $result['goal_label'] = $predicted_label;
                                $result['meta']['predicted_tool']      = $predicted_goal;
                                $result['meta']['predicted_label']     = $predicted_label;
                                $result['meta']['prediction_method']   = $prediction_method;
                                $this->conversation_mgr->set_goal( $conv_id, $predicted_goal, $predicted_label );
                            } else {
                                // No prediction → list all tools
                                $tool_list = '';
                                $i = 1;
                                foreach ( $provider_goals as $g_key => $g_label ) {
                                    $tool_list .= "{$i}. {$g_label}\n";
                                    $i++;
                                }
                                $result['reply'] = "**{$provider_name}** có thể giúp bạn:\n\n{$tool_list}\nBạn muốn sử dụng công cụ nào? 💡";
                                $result['goal']  = '';
                            }

                            $result['action'] = 'ask_user';
                            $result['status'] = 'WAITING_USER';
                            $result['meta']['post_tool']     = 'provider_tool_list';
                            $result['meta']['provider_hint'] = $provider_hint;
                            $result['meta']['method']        = 'provider_scope_guard';

                            $this->conversation_mgr->set_waiting( $conv_id, 'text', '' );
                            $this->conversation_mgr->add_turn( $conv_id, 'user', $message );
                            $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );

                            $this->logger->log( 'provider_scope_guard', [
                                'provider_hint'     => $provider_hint,
                                'rejected_goal'     => $intent_goal,
                                'rejected_intent'   => $intent_type,
                                'is_cross_provider' => $is_cross_provider,
                                'active_goal'       => $conv_goal,
                                'available_goals'   => array_keys( $provider_goals ),
                                'predicted_tool'    => $predicted_goal,
                                'prediction_method' => $prediction_method,
                                'message'           => mb_substr( $message, 0, 100, 'UTF-8' ),
                            ], $conv_id, 0, $user_id, $channel );

                            error_log( '[INTENT-ENGINE] Step 3.6: Provider scope guard → '
                                . ( $predicted_goal
                                    ? 'predicted=' . $predicted_goal . ' (' . $prediction_method . ')'
                                    : 'listed ' . count( $provider_goals ) . ' tools' )
                                . ' for @' . $provider_hint );
                            do_action( 'bizcity_intent_processed', $result, $params );
                            return $result;
                        }
                    }
                }
            }
        }
        } // End: if ( ! $preconfirm_skip_router ) — Steps 3 → 3.6

        // ── Step 4: Handle intent ──

        // Track confirm-pending state across provide_input → call_tool path.
        // When the previous turn showed a confirm card (_awaiting_confirm=1)
        // and user responds, provide_input fires first. The call_tool case
        // needs to know it should check for confirmation, not show a new card.
        $confirm_pending = false;

        // 4a. End conversation
        if ( $intent['intent'] === 'end_conversation' ) {
            // Record user's goodbye turn before completing
            $this->conversation_mgr->add_turn( $conv_id, 'user', $message, [
                'attachments' => $images,
                'intent'      => 'end_conversation',
                'meta'        => [
                    'confidence' => $intent['confidence'] ?? 0,
                    'method'     => $intent['method'] ?? '',
                ],
            ] );
            $this->conversation_mgr->complete( $conv_id, 'User ended conversation.' );
            $result['action'] = 'complete';
            $result['status'] = 'COMPLETED';
            $result['reply']  = $this->get_goodbye_message( $conversation );
            $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
            return $result;
        }

        // 4b. New goal → update conversation
        if ( $intent['intent'] === 'new_goal' && ! empty( $intent['goal'] ) ) {
            $goal_label = $intent['goal_label'] ?? $intent['goal'];
            do_action( 'bizcity_intent_status', '🎯 Cần thực hiện: ' . $goal_label );

            // ── Auto-fill 'message' from user's raw input (v3.5.1, v4.7.1 fix) ──
            // Many tool plans use {{slots.message}} as the primary instruction.
            // The user's natural language text IS the message.
            // v4.7.1: BUT only if the classifier actually extracted meaningful entities.
            // If entities are empty (classifier found no real content), don't fill 'message'
            // with the raw command — it would poison the Content Confidence Gate.
            // Example: "đăng bài lên web rồi đăng facebook" has NO content, only a command.
            $has_classifier_entities = false;
            foreach ( [ 'topic', 'content', 'description', 'subject', 'query', 'question' ] as $_ck ) {
                if ( ! empty( $intent['entities'][ $_ck ] ) ) {
                    $has_classifier_entities = true;
                    break;
                }
            }
            if ( empty( $intent['entities']['message'] ) && ! empty( $message ) && $has_classifier_entities ) {
                $intent['entities']['message'] = $message;
            }

            // ── Auto-map message → plan's first required text slot (v4.0.1) ──
            // When user selects a tool chip and types a message, the message is the primary input.
            // But plans may use different slot names: 'question', 'query', 'topic', etc.
            // Map 'message' → the first unfilled required text-type slot in the plan.
            //
            // v4.4.0: SESSION CONTEXT ENRICHMENT
            // When the message is just a navigation command (e.g. "ok, dùng công cụ X đi")
            // without substantive content, enrich the slot with session history so the tool
            // understands the user's REAL intent from earlier in the session.
            $auto_plan = $this->planner->get_plan( $intent['goal'] );
            if ( $auto_plan && ! empty( $message ) ) {
                // Detect if message is a tool-navigation command with no real content
                $enriched_message = $this->enrich_slot_from_session( $message, $session_id, $conv_id );

                foreach ( $auto_plan['required_slots'] ?? [] as $slot_name => $slot_cfg ) {
                    if ( $slot_name === 'message' ) continue; // Already handled above
                    $slot_type = $slot_cfg['type'] ?? 'text';
                    if ( $slot_type !== 'text' || ! empty( $intent['entities'][ $slot_name ] ) ) continue;

                    // ── v4.6.1: no_auto_map slots — try trigger-strip extraction ──
                    // Slots with no_auto_map should NOT receive the raw command message.
                    // But if the message contains substantive data BEYOND the trigger
                    // (e.g. "ghi bữa ăn phở bò" → "phở bò"), extract it.
                    if ( ! empty( $slot_cfg['no_auto_map'] ) ) {
                        $extracted = $this->extract_content_after_trigger( $message, $intent );
                        if ( $extracted !== '' ) {
                            $intent['entities'][ $slot_name ] = $extracted;
                        }
                        // Skip — planner will ask if extracted is empty
                        break;
                    }

                    $intent['entities'][ $slot_name ] = $enriched_message;
                    break; // Only fill the first unfilled text slot
                }
            }

            // ── Slot extraction is handled by unified LLM (v3.5.1) ──
            // The unified LLM prompt extracts entities, filled_slots, missing_slots
            // directly from the message. No regex/word-count heuristics needed.
            // The Planner will loop and ask the user for any missing required slots.
            // If there's already an active goal (same OR different), close it and start fresh.
            // Rationale: new_goal means the user is issuing a new request — stale slots from
            // a previous conversation of the same goal must NOT bleed into the new one.
            // (e.g. user posts article A → then says "đăng bài" again → should NOT inherit topic A)
            //
            // ── v4.9.1 Fix 2: Guard against closing conversation during pre-confirm resume ──
            // When _multi_preconfirm_state is content_provided/confirmed, the conversation
            // holds critical state (_preconfirm_content, _multi_preconfirm_analysis).
            // Do NOT close+create — keep the same conversation to preserve preconfirm slots.
            $preconfirm_state_4b = ( $conversation['slots'] ?? [] )['_multi_preconfirm_state'] ?? '';
            $is_preconfirm_resume = in_array( $preconfirm_state_4b, [ 'content_provided', 'confirmed' ], true )
                                 && $conversation['goal'] === $intent['goal'];

            if ( ! empty( $conversation['goal'] ) && ! $is_preconfirm_resume ) {
                // ── O6: Cross-goal slot inheritance (v3.6.2) ──
                // When switching from goal A → goal B, check for overlapping slot names
                // in the new plan schema. Inherit values that exist in old slots AND are
                // defined in the new plan (same field name + compatible type).
                $prev_goal  = $conversation['goal'];
                $prev_slots = $conversation['slots'] ?? [];

                $this->conversation_mgr->complete( $conv_id, 'New request for goal: ' . $intent['goal'] );

                // Determine inherited slots: prev_slots ∩ new_plan_fields
                $inherited_slots = [];
                if ( $prev_goal !== $intent['goal'] && ! empty( $prev_slots ) ) {
                    $new_plan = $this->planner->get_plan( $intent['goal'] );
                    if ( $new_plan ) {
                        $new_fields = array_merge(
                            $new_plan['required_slots'] ?? [],
                            $new_plan['optional_slots'] ?? []
                        );
                        foreach ( $new_fields as $field => $cfg ) {
                            if ( $field === 'message' || $field === '_meta' ) continue;
                            $old_val = $prev_slots[ $field ] ?? '';
                            if ( is_string( $old_val ) && $old_val !== '' ) {
                                // v4.3.4: Cross-goal type validation — don't inherit
                                // incompatible types (e.g. text into number slot).
                                $new_type = $cfg['type'] ?? 'text';
                                if ( $new_type === 'number' && ! is_numeric( $old_val ) ) continue;
                                if ( $new_type === 'image' && ! filter_var( $old_val, FILTER_VALIDATE_URL ) ) continue;
                                if ( ( $new_type === 'choice' || $new_type === 'select' ) && ! empty( $cfg['options'] ) ) {
                                    $valid_options = array_map( 'mb_strtolower', array_keys( $cfg['options'] ) );
                                    if ( ! in_array( mb_strtolower( $old_val, 'UTF-8' ), $valid_options, true ) ) continue;
                                }
                                $inherited_slots[ $field ] = $old_val;
                            }
                        }
                    }
                }

                // Merge: LLM-extracted entities take priority, then inherited, then empty
                    $merged_entities = array_merge( $inherited_slots, $intent['entities'] ?? [] );
                    // Bổ sung plugin_slug, tool_name, client_name vào slots
                    $provider = class_exists('BizCity_Intent_Provider_Registry') && !empty($intent['goal'])
                        ? BizCity_Intent_Provider_Registry::instance()->get_provider_for_goal($intent['goal']) : null;
                    if ($provider) {
                        $merged_entities['plugin_slug'] = $provider->get_id();
                        $merged_entities['client_name'] = $provider->get_name();
                    }
                    if (!empty($plan_result['tool_name'])) {
                        $merged_entities['tool_name'] = $plan_result['tool_name'];
                    }

                // ── v4.3.6: Map _images → named image slot (cross-goal path) ──
                if ( ! empty( $merged_entities['_images'] ) ) {
                    $new_plan_img = $this->planner->get_plan( $intent['goal'] );
                    if ( $new_plan_img ) {
                        $all_img_slots = array_merge(
                            $new_plan_img['required_slots'] ?? [],
                            $new_plan_img['optional_slots'] ?? []
                        );
                        foreach ( $all_img_slots as $f_name => $f_cfg ) {
                            if ( ( $f_cfg['type'] ?? '' ) === 'image' && empty( $merged_entities[ $f_name ] ) ) {
                                $img_arr = $merged_entities['_images'];
                                $merged_entities[ $f_name ] = count( $img_arr ) === 1
                                    ? $img_arr[0]
                                    : implode( ',', $img_arr );
                                break;
                            }
                        }
                    }
                    unset( $merged_entities['_images'] );
                }

                // Create fresh conversation for the new request
                $conversation = $this->conversation_mgr->create(
                    $user_id, $channel, $session_id, $character_id,
                    $intent['goal'], $merged_entities
                );
                $conv_id = $conversation['conversation_id'];
                $result['conversation_id'] = $conv_id;

                // Log inheritance for debugging
                if ( ! empty( $inherited_slots ) ) {
                    $this->logger->log( 'cross_goal_inherit', [
                        'from_goal'       => $prev_goal,
                        'to_goal'         => $intent['goal'],
                        'inherited_slots' => array_keys( $inherited_slots ),
                    ], $conv_id, 0, $user_id, $channel );
                }
            } else {
                // No existing conversation — set goal normally
                $this->conversation_mgr->set_goal( $conv_id, $intent['goal'], $intent['goal_label'] );

                // ── v4.3.6: Map _images → first image-type slot in plan ──
                // When new_goal arrives with image attachments (e.g. user says
                // "tạo video từ ảnh này" + image), the router stores images as
                // _images entity. Map it to the plan's named image slot so the
                // Planner sees a filled slot, not an opaque _images array.
                if ( ! empty( $intent['entities']['_images'] ) ) {
                    $new_plan = $this->planner->get_plan( $intent['goal'] );
                    if ( $new_plan ) {
                        $all_slots = array_merge(
                            $new_plan['required_slots'] ?? [],
                            $new_plan['optional_slots'] ?? []
                        );
                        foreach ( $all_slots as $f_name => $f_cfg ) {
                            if ( ( $f_cfg['type'] ?? '' ) === 'image' && empty( $intent['entities'][ $f_name ] ) ) {
                                $img_arr = $intent['entities']['_images'];
                                $intent['entities'][ $f_name ] = count( $img_arr ) === 1
                                    ? $img_arr[0]
                                    : implode( ',', $img_arr );
                                break;
                            }
                        }
                    }
                    unset( $intent['entities']['_images'] );
                }

                if ( ! empty( $intent['entities'] ) ) {
                    // Bổ sung plugin_slug, tool_name, client_name khi update_slots
                    $slot_update = $intent['entities'];
                    $provider = class_exists('BizCity_Intent_Provider_Registry') && !empty($intent['goal'])
                        ? BizCity_Intent_Provider_Registry::instance()->get_provider_for_goal($intent['goal']) : null;
                    if ($provider) {
                        $slot_update['plugin_slug'] = $provider->get_id();
                        $slot_update['client_name'] = $provider->get_name();
                    }
                    if (!empty($plan_result['tool_name'])) {
                        $slot_update['tool_name'] = $plan_result['tool_name'];
                    }
                    $this->conversation_mgr->update_slots( $conv_id, $slot_update );
                }
                $conversation['goal']       = $intent['goal'];
                $conversation['goal_label'] = $intent['goal_label'];
                $conversation['slots']      = $intent['entities']; // Fresh — NOT array_merge!
            }

            // ── v4.3.6: Clear _session_pending_images after new_goal consumed them ──
            // Step 1.5C stores images in session slots. Now that we've mapped them
            // into the goal's named image slot, clear the buffer to prevent re-inject.
            if ( ! empty( $images ) ) {
                $this->conversation_mgr->update_slots( $conv_id, [
                    '_session_pending_images' => null,
                ] );
            }

            // ── Phase 1.6 B1: Fire goal_detected for session memory spec ──
            // Escalates session mode: chat → goal
            do_action( 'bizcity_goal_detected', $session_id, $intent['goal'], [
                'label'    => $intent['goal_label'] ?? $intent['goal'],
                'method'   => $intent['method'] ?? '',
                'entities' => $intent['entities'] ?? [],
                'conv_id'  => $conv_id,
            ] );
        }

        // 4c. Provide input → fill slots
        if ( $intent['intent'] === 'provide_input' ) {
            do_action( 'bizcity_intent_status', '📝 Đang cập nhật thông tin...' );
            // Preserve the waiting_field BEFORE resume() clears it.
            // Planner uses this to detect optional-slot skips: if the user
            // just answered (or skipped) an optional field, the Planner
            // won't re-ask even when the slot is still empty.
            $prev_waiting_field = $conversation['waiting_field'] ?? '';

            // Detect confirm-pending: user is responding to a confirm card
            if ( $prev_waiting_field === '_confirm_execute' ) {
                $confirm_pending = true;
            }

            // ── v3.8 LLM Slot Bridge: retry/cancel handling ──
            // If Router's LLM Slot Bridge could NOT understand the user's answer,
            // handle retry (max 1) or suggest cancellation.
            if ( ! empty( $intent['entities']['_slot_extract_failed'] ) ) {
                $current_retries = (int) ( $conversation['slots']['_slot_retry_count'] ?? 0 );
                $clarification   = $intent['entities']['_slot_extract_clarification'] ?? '';

                // Resolve field config for both retry and cancel paths
                $goal_plan    = $this->planner->get_plan( $conversation['goal'] );
                $all_slots    = $goal_plan ? array_merge( $goal_plan['required_slots'] ?? [], $goal_plan['optional_slots'] ?? [] ) : [];
                $field_config = $all_slots[ $prev_waiting_field ] ?? [];

                if ( $current_retries < 3 ) {
                    // ── Retry (max 3 attempts): Ask user to clarify ──
                    $this->conversation_mgr->update_slots( $conv_id, [
                        '_slot_retry_count' => $current_retries + 1,
                    ] );

                    if ( ! $clarification ) {
                        $clarification = 'Mình chưa hiểu rõ câu trả lời.';
                        if ( ! empty( $field_config['choices'] ) ) {
                            $options = implode( ', ', array_values( $field_config['choices'] ) );
                            $clarification .= " Bạn vui lòng chọn một trong: {$options}";
                        } else {
                            $clarification .= ' ' . ( $field_config['prompt'] ?? "Vui lòng cung cấp: {$prev_waiting_field}" );
                        }
                    }

                    // Re-set WAITING_USER for same field
                    $this->conversation_mgr->set_waiting(
                        $conv_id,
                        $field_config['type'] ?? 'text',
                        $prev_waiting_field
                    );

                    $retry_msg = "⚠️ {$clarification}";
                    $this->conversation_mgr->add_turn( $conv_id, 'assistant', $retry_msg );

                    $result['reply']           = $retry_msg;
                    $result['status']          = 'WAITING_USER';
                    $result['action']          = 'ask_user';
                    $result['goal']            = $conversation['goal'];
                    $result['goal_label']      = $conversation['goal_label'] ?? '';
                    $result['conversation_id'] = $conv_id;
                    $result['focus_mode']      = 'active';

                    $this->logger->log( 'slot_bridge_retry', [
                        'field'         => $prev_waiting_field,
                        'retry_count'   => $current_retries + 1,
                        'clarification' => mb_substr( $clarification, 0, 200, 'UTF-8' ),
                    ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[INTENT-ENGINE] v3.8 Slot Bridge retry #' . ( $current_retries + 1 )
                                 . ' | field=' . $prev_waiting_field . ' | goal=' . $conversation['goal'] );
                    }
                    return $result;
                }

                // ── Retry exhausted (3 attempts): auto-complete + apologize ──
                $this->conversation_mgr->update_slots( $conv_id, [ '_slot_retry_count' => 0 ] );

                $cancel_msg = "Mình chưa hiểu rõ, xin lỗi bạn 🙏 Bạn có thể thử lại từ đầu hoặc nhắn cụ thể hơn nhé.";

                // Auto-complete: end the conversation so user can start fresh
                $this->conversation_mgr->complete( $conv_id, 'Slot retry exhausted (3 attempts)' );

                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $cancel_msg );

                $result['reply']           = $cancel_msg;
                $result['status']          = 'COMPLETED';
                $result['action']          = 'complete';
                $result['goal']            = $conversation['goal'];
                $result['goal_label']      = $conversation['goal_label'] ?? '';
                $result['conversation_id'] = $conv_id;

                $this->logger->log( 'slot_bridge_cancel_suggest', [
                    'field'       => $prev_waiting_field,
                    'retry_count' => $current_retries,
                ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[INTENT-ENGINE] v3.8 Slot Bridge retries EXHAUSTED → cancel suggestion'
                             . ' | field=' . $prev_waiting_field . ' | goal=' . $conversation['goal'] );
                }
                return $result;
            }

            $this->conversation_mgr->resume( $conv_id );

            // ── v3.8: Clear retry counter on successful provide_input ──
            if ( isset( $conversation['slots']['_slot_retry_count'] )
                 && (int) $conversation['slots']['_slot_retry_count'] > 0
            ) {
                $this->conversation_mgr->update_slots( $conv_id, [ '_slot_retry_count' => 0 ] );
            }

            if ( ! empty( $intent['entities'] ) ) {
                // Handle _images separately — store as STRING for single-image slots.
                if ( ! empty( $intent['entities']['_images'] ) ) {
                    $image_urls = $intent['entities']['_images'];
                    $field      = $intent['entities']['_waiting_field'] ?? '';

                    // Resolve target image field from plan
                    $goal_plan = $this->planner->get_plan( $conversation['goal'] );
                    if ( $goal_plan ) {
                        $all_slots_for_img = array_merge( $goal_plan['required_slots'] ?? [], $goal_plan['optional_slots'] ?? [] );
                        // If _waiting_field is empty OR points to a non-image slot,
                        // resolve to the first type:'image' slot in the plan.
                        $field_type = ( $all_slots_for_img[ $field ]['type'] ?? '' );
                        if ( ! $field || $field_type !== 'image' ) {
                            foreach ( $all_slots_for_img as $f => $cfg ) {
                                if ( ( $cfg['type'] ?? '' ) === 'image' ) {
                                    $field = $f;
                                    break;
                                }
                            }
                        }
                    }

                    if ( $field ) {
                        // Check plan config: array slot → append, single slot → first URL as string
                        $all_plan_slots = $goal_plan
                            ? array_merge( $goal_plan['required_slots'] ?? [], $goal_plan['optional_slots'] ?? [] )
                            : [];
                        $field_cfg = $all_plan_slots[ $field ] ?? [];

                        if ( ! empty( $field_cfg['is_array'] ) ) {
                            $this->conversation_mgr->append_slot( $conv_id, $field, $image_urls );
                        } else {
                            // Single image slot → store first URL as plain string
                            $this->conversation_mgr->update_slots( $conv_id, [ $field => $image_urls[0] ?? '' ] );
                        }

                        // ── v4.6.1: accept_image — auto-fill text slot when image substitutes ──
                        // When a required text slot (e.g. food_input) has accept_image=true,
                        // sending an image alone satisfies the slot requirement.
                        // The tool callback uses the image for AI analysis instead of text.
                        if ( $goal_plan && ! empty( $prev_waiting_field ) ) {
                            $waiting_cfg = $all_plan_slots[ $prev_waiting_field ] ?? [];
                            $waiting_val = $conversation['slots'][ $prev_waiting_field ] ?? '';
                            if ( ! empty( $waiting_cfg['accept_image'] )
                                 && ( $waiting_val === '' || $waiting_val === null )
                            ) {
                                $this->conversation_mgr->update_slots( $conv_id, [
                                    $prev_waiting_field => '[phân tích từ ảnh]',
                                ] );
                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                    error_log( '[INTENT-ENGINE] accept_image: auto-filled '
                                             . $prev_waiting_field . ' ← "[phân tích từ ảnh]" | image→' . $field );
                                }
                            }
                        }
                    } elseif ( $goal_plan && ! empty( $prev_waiting_field ) ) {
                        // No image slot in plan, but waiting text slot accepts images
                        $all_plan_slots = array_merge(
                            $goal_plan['required_slots'] ?? [],
                            $goal_plan['optional_slots'] ?? []
                        );
                        $waiting_cfg = $all_plan_slots[ $prev_waiting_field ] ?? [];
                        if ( ! empty( $waiting_cfg['accept_image'] ) ) {
                            $this->conversation_mgr->update_slots( $conv_id, [
                                $prev_waiting_field => $image_urls[0] ?? '',
                            ] );
                        }
                    }
                    unset( $intent['entities']['_images'], $intent['entities']['_waiting_field'] );
                }

                // Map remaining entities to slots
                $slot_updates = $intent['entities'];
                unset( $slot_updates['_waiting_field'], $slot_updates['_slot_extract_failed'],
                       $slot_updates['_slot_extract_clarification'], $slot_updates['_slot_retry_count'] );
                if ( ! empty( $slot_updates ) ) {
                    $this->conversation_mgr->update_slots( $conv_id, $slot_updates );
                }

                // Refresh conversation data
                $conversation = $this->conversation_mgr->get_active( $user_id, $channel, $session_id );
                if ( ! $conversation ) {
                    $conversation = [
                        'conversation_id' => $conv_id,
                        'goal'            => $intent['goal'] ?? '',
                        'goal_label'      => $intent['goal_label'] ?? '',
                        'status'          => 'ACTIVE',
                        'slots'           => [],
                    ];
                }
            }

            // Re-inject the pre-resume waiting_field so the Planner can
            // detect that the user just answered (or skipped) this field
            // and won't re-ask for it in the optional-slot loop.
            if ( $prev_waiting_field ) {
                $conversation['waiting_field'] = $prev_waiting_field;
            }
        }

        // 4d. Continue goal → merge any new entities
        if ( $intent['intent'] === 'continue_goal' && ! empty( $intent['entities'] ) ) {
            $this->conversation_mgr->update_slots( $conv_id, $intent['entities'] );
            $conversation['slots'] = array_merge( $conversation['slots'], $intent['entities'] );
        }

        // ── Step 4.4: Unified Pipeline — Objective Understanding → Variant Resolution → Execution Planning ──
        // Two clear layers: (1) understand WHAT the user wants, (2) decide HOW to execute it.
        // When confidence < threshold → clarify first. Supports provider-specific planner adapters.
        if ( $intent['intent'] === 'new_goal'
             && ! empty( $intent['goal'] )
             && class_exists( 'BizCity_Objective_Understanding' )
             && class_exists( 'BizCity_Core_Planner' )
        ) {
            $understanding = BizCity_Objective_Understanding::instance();
            $plan_context  = [
                'user_id'            => $user_id,
                'session_id'         => $session_id,
                'conversation_id'    => $conv_id,
                'conversation_slots' => $conversation['slots'] ?? [],
                'channel'            => $channel,
                'message'            => $message,
                'rolling_summary'    => $conversation['rolling_summary'] ?? '',
                'goal'               => $intent['goal'] ?? '',
            ];

            // Layer 1: Objective Understanding — structured analysis.
            // ── v4.9.1 Fix 3: Use cached analysis during pre-confirm resume ──
            // When returning from content pre-confirm (content_provided/confirmed),
            // the ORIGINAL multi-objective analysis is stored in _multi_preconfirm_analysis.
            // Re-analyzing the content text ("Xây dựng bản sao...") would produce wrong
            // single-goal analysis. Load cached version instead.
            $preconfirm_state_44 = ( $conversation['slots'] ?? [] )['_multi_preconfirm_state'] ?? '';
            $cached_analysis_json = $conversation['slots']['_multi_preconfirm_analysis'] ?? '';

            if ( in_array( $preconfirm_state_44, [ 'content_provided', 'confirmed' ], true )
                 && $cached_analysis_json !== ''
            ) {
                $analysis = json_decode( $cached_analysis_json, true );
                if ( ! $analysis || empty( $analysis['intents'] ) ) {
                    // Fallback: corrupted cache → re-analyze
                    $analysis = $understanding->analyze( $message, $intent, $plan_context );
                } else {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[INTENT-ENGINE] Step 4.4 Fix 3: Loaded cached analysis — '
                            . count( $analysis['intents'] ) . ' intents, is_multi=' . ( $analysis['is_multi'] ? 'true' : 'false' ) );
                    }
                }
            } else {
                $analysis = $understanding->analyze( $message, $intent, $plan_context );
            }

            // ── Populate meta.objectives from OU analysis for trace visibility ──
            // @since v4.6.3 — Gateway trace reads meta.objectives to display objectives_detected + multi_goal_decision.
            $result['meta']['objectives'] = array_map( function ( $obj ) {
                return $obj['text'] ?? '';
            }, $analysis['intents'] ?? [] );
            $result['meta']['is_multi_objective']     = ! empty( $analysis['is_multi'] );
            $result['meta']['objectives_confidence']  = $analysis['confidence'] ?? 0;

            // Only build workflow for multi-objective requests (2+ intents).
            // Single-goal requests fall through to the normal Planner → slot-fill → confirm → execute path.
            if ( ! empty( $analysis['is_multi'] ) && count( $analysis['intents'] ?? [] ) >= 2 ) {

                // Gate: if confidence too low, force clarification before building any plan.
                if ( ! empty( $analysis['needs_clarify'] ) ) {
                    $clarify_msg = $analysis['clarify_reason'] ?: 'Bạn có thể mô tả rõ hơn mong muốn?';
                    $this->conversation_mgr->set_waiting( $conv_id, 'confirm', '_clarify_objective' );
                    $this->conversation_mgr->update_slots( $conv_id, [
                        '_awaiting_clarify' => '1',
                        '_analysis_cache'   => wp_json_encode( $analysis, JSON_UNESCAPED_UNICODE ),
                    ] );

                    $result['reply']  = "❓ " . $clarify_msg;
                    $result['action'] = 'ask_user';
                    $result['status'] = 'WAITING_USER';
                    $result['meta']['needs_clarify']  = true;
                    $result['meta']['analysis']       = $analysis;

                    $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'], [
                        'meta' => [ 'ask_field' => '_clarify_objective', 'ask_type' => 'text' ],
                    ] );
                    $this->logger->end_trace( $result );
                    do_action( 'bizcity_intent_processed', $result, $params );
                    return $result;
                }

                // Layer 1.5: Variant Resolution — decide which planner handles execution.
                $variant_info = [ 'variant' => 'core_planner', 'reason' => 'default', 'method' => 'auto' ];
                if ( class_exists( 'BizCity_Planner_Variant_Resolver' ) ) {
                    $resolver     = BizCity_Planner_Variant_Resolver::instance();
                    $variant_info = $resolver->resolve( $analysis, $plan_context );
                }

                // Layer 2: Execution Planning — build the plan using selected variant.
                // But first: Content-First Pre-Confirm (Phase 1.1 v1.6 §17.5)
                // RULE: When multi-goal detected, check if meaningful CONTENT exists
                // from classifier entities. If only goals without content (low confidence)
                // → ALWAYS fallback to ask for specific content before generating workflow.
                // When re-prompted with good content → prompt carries content desires + goal objectives.
                $preconfirm_state = $conversation['slots']['_multi_preconfirm_state'] ?? '';

                if ( $preconfirm_state !== 'confirmed' ) {

                    // ── Content Confidence Gate ──
                    // Classifier entities contain extracted topic/content from the message.
                    // If entities are empty or only have generic values → content is insufficient.
                    // v4.7.1: REMOVED 'message' from content_keys — 'message' is auto-filled
                    // with raw command text at Step 4b, so it always has a value even when
                    // there is NO real content (e.g. "đăng bài lên web rồi đăng facebook").
                    // Only check keys that are explicitly extracted by the classifier LLM.
                    $classifier_entities = $intent['entities'] ?? [];
                    $content_keys        = [ 'topic', 'content', 'description', 'subject' ];
                    $has_content         = false;
                    $content_value       = '';

                    foreach ( $content_keys as $ck ) {
                        $val = trim( $classifier_entities[ $ck ] ?? '' );
                        if ( $val !== '' ) {
                            $has_content   = true;
                            $content_value = $val;
                            break;
                        }
                    }

                    // Also check pre-confirm slot from previous ask round
                    $preconfirm_content = trim( $conversation['slots']['_preconfirm_content'] ?? '' );
                    if ( $preconfirm_content !== '' ) {
                        $has_content   = true;
                        $content_value = $preconfirm_content;
                    }

                    // ── v4.9.5: Topic-Confirm Gate REMOVED ──
                    // Previously asked "Nội dung cụ thể là gì?" when no content detected.
                    // Now: skip content ask → go straight to plan builder confirm.
                    // User sees the builder link, can fill content there or run as-is.
                    // If no content, use the raw message as topic fallback.
                    if ( ! $has_content ) {
                        $content_value = $message; // Use raw message as topic fallback
                        $has_content   = true;

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[INTENT-ENGINE] Step 4.4: no explicit content → using raw message as topic fallback'
                                . ' | tools=' . implode( ',', array_column( $analysis['intents'], 'tool_hint' ) ) );
                        }
                    }

                    // ── Content available → inject into analysis for plan builder ──
                    if ( $has_content ) {
                        // Store content in analysis so Scenario Generator can reference it
                        $analysis['_content_value'] = $content_value;
                        $analysis['_content_source'] = $preconfirm_content ? 'user_reply' : 'classifier_entity';
                    }

                    // All checks passed → mark confirmed, continue to build_plan
                    $this->conversation_mgr->update_slots( $conv_id, [
                        '_multi_preconfirm_state' => 'confirmed',
                    ] );
                }

                // Inject content and classifier entities into analysis for plan builder
                $preconfirm_content_final = trim( $conversation['slots']['_preconfirm_content'] ?? '' );
                $classifier_entities_final = $intent['entities'] ?? [];
                $content_for_plan = $preconfirm_content_final ?: ( $classifier_entities_final['topic'] ?? '' );

                if ( $content_for_plan ) {
                    $analysis['_content_value']  = $content_for_plan;
                    $analysis['_content_source'] = $preconfirm_content_final ? 'user_reply' : 'classifier_entity';
                    $analysis['_entities']       = $classifier_entities_final;
                }

                foreach ( $analysis['intents'] as $idx => &$obj_ref ) {
                    $tool_hint = $obj_ref['tool_hint'] ?? '';
                    // Inject content as topic/message for each intent's filled_slots
                    if ( $content_for_plan ) {
                        $primary_keys = [ 'topic', 'message', 'content', 'description' ];
                        foreach ( $primary_keys as $pk ) {
                            if ( isset( $obj_ref['input_fields'][ $pk ] ) && empty( $obj_ref['filled_slots'][ $pk ] ) ) {
                                $obj_ref['filled_slots'][ $pk ] = $content_for_plan;
                                break;
                            }
                        }
                    }
                }
                unset( $obj_ref );

                $pipeline_plan = null;
                if ( class_exists( 'BizCity_Execution_Planner' ) ) {
                    $exec_planner  = BizCity_Execution_Planner::instance();
                    $pipeline_plan = $exec_planner->build_plan( $analysis, $plan_context );
                } else {
                    // Fallback to legacy Core Planner.
                    $core_planner  = BizCity_Core_Planner::instance();
                    $objectives    = array_map( function ( $obj ) {
                        return [
                            'text'       => $obj['text'],
                            'tool_hint'  => $obj['tool_hint'],
                            'confidence' => $obj['confidence'],
                        ];
                    }, $analysis['intents'] );
                    $pipeline_plan = $core_planner->build_plan( $objectives, $plan_context );
                }

                if ( $pipeline_plan && ! empty( $pipeline_plan['steps'] ) ) {
                    // Inject content value into plan for Scenario Generator verify-content node
                    if ( ! empty( $analysis['_content_value'] ) ) {
                        $pipeline_plan['_content_value'] = $analysis['_content_value'];
                    }

                    // Generate scenario → save draft task → get builder link.
                    $core_planner = BizCity_Core_Planner::instance();
                    $plan_result  = $core_planner->execute_plan( $pipeline_plan, [
                        'user_id'    => $user_id,
                        'session_id' => $session_id,
                        'channel'    => $channel,
                        'message'    => $message,
                    ] );

                    if ( ! empty( $plan_result['success'] ) && ! empty( $plan_result['plan_link'] ) ) {
                        $summary = $core_planner->format_plan_summary( $pipeline_plan, $plan_result['plan_link'] );
                        $reply = $summary;

                        // Create one-shot trigger for this task.
                        $task_id = (int) ( $plan_result['task_id'] ?? 0 );
                        if ( class_exists( 'BizCity_One_Shot_Trigger' ) && $task_id > 0 ) {
                            BizCity_One_Shot_Trigger::instance()->create( $task_id, $pipeline_plan['pipeline_id'] ?? '', [
                                'user_id'    => $user_id,
                                'session_id' => $session_id,
                                'message'    => $message,
                                'channel'    => $channel,
                            ] );
                        }

                        // ── Phase 1.1 G10: Auto-execute DISABLED ──
                        // Always show builder link + iframe so user can review and click "Chạy" manually.
                        // The auto_execute_plan_task path is preserved at L731 (WAITING_USER confirm).
                        // $current_preconfirm check removed — user always sees the builder first.

                        $this->conversation_mgr->set_waiting( $conv_id, 'confirm', '_confirm_plan_builder' );
                        $this->conversation_mgr->update_slots( $conv_id, [
                            '_awaiting_plan_confirm' => '1',
                            '_plan_task_id'          => (string) $task_id,
                            '_plan_link'             => $plan_result['plan_link'],
                        ] );

                        // Trace variant selection.
                        if ( class_exists( 'BizCity_Planner_Variant_Resolver' ) ) {
                            BizCity_Planner_Variant_Resolver::instance()->trace_variant(
                                $conv_id, $variant_info, $plan_result, $this->conversation_mgr
                            );
                        }

                        $result['reply']   = $reply;
                        $result['action']  = 'ask_user';
                        $result['status']  = 'WAITING_USER';
                        $result['meta']['multi_objective']  = true;
                        $result['meta']['plan_task_id']     = $task_id;
                        $result['meta']['plan_link']        = $plan_result['plan_link'];
                        $result['meta']['variant']          = $variant_info['variant'];
                        $result['meta']['analysis']         = [
                            'intents_count'  => count( $analysis['intents'] ),
                            'dependencies'   => count( $analysis['dependencies'] ),
                            'confidence'     => $analysis['confidence'],
                            'adapter_used'   => $pipeline_plan['adapter_used'] ?? 'core',
                        ];

                        $this->conversation_mgr->add_turn( $conv_id, 'assistant', $reply, [
                            'meta' => [ 'ask_field' => '_confirm_plan_builder', 'ask_type' => 'confirm' ],
                        ] );

                        $this->logger->log( 'multi_plan_generated', [
                            'goal'        => $intent['goal'],
                            'task_id'     => $task_id,
                            'plan_link'   => $plan_result['plan_link'],
                            'mode'        => $pipeline_plan['mode'] ?? '',
                            'step_count'  => $pipeline_plan['step_count'] ?? 0,
                            'variant'     => $variant_info['variant'],
                            'adapter'     => $pipeline_plan['adapter_used'] ?? 'core',
                            'confidence'  => $analysis['confidence'],
                            'deps_count'  => count( $analysis['dependencies'] ),
                        ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

                        $this->logger->end_trace( $result );
                        do_action( 'bizcity_intent_processed', $result, $params );
                        return $result;
                    } else {
                        // Plan execution failed (no plan_link) → log and fall through to single-tool
                        error_log( '[INTENT-ENGINE] Step 4.4: Multi-goal plan EXECUTE failed'
                            . ' | intents=' . count( $analysis['intents'] )
                            . ' | plan_mode=' . ( $pipeline_plan['mode'] ?? '' )
                            . ' | plan_steps=' . ( $pipeline_plan['step_count'] ?? 0 )
                            . ' | error=' . wp_json_encode( $plan_result['error'] ?? 'no plan_link', JSON_UNESCAPED_UNICODE ) );
                    }
                } else {
                    // Multi-goal detected but plan building returned empty steps
                    $intent_tools = array_map( function ( $obj ) {
                        return $obj['tool_hint'] ?? '?';
                    }, $analysis['intents'] );
                    error_log( '[INTENT-ENGINE] Step 4.4: Multi-goal detected ('
                        . count( $analysis['intents'] ) . ' intents) but plan has NO steps → fallback single-tool'
                        . ' | tools=' . implode( ',', $intent_tools )
                        . ' | plan_mode=' . ( $pipeline_plan['mode'] ?? 'null' ) );
                }
            }
        }

        // ── Step 4.5: Record user turn (enriched with classify data) ──
        // Deferred from Step 2 so it lands in the CORRECT conversation_id
        // and carries intent / slots_delta / meta for full data linkage.
        $turn_slots = $intent['entities'] ?? [];
        unset( $turn_slots['_waiting_field'], $turn_slots['_images'],
               $turn_slots['_slot_extract_failed'], $turn_slots['_slot_extract_clarification'],
               $turn_slots['_slot_retry_count'] );
        $this->conversation_mgr->add_turn( $conv_id, 'user', $message, [
            'attachments' => $images,
            'intent'      => ! empty( $intent['goal'] ) ? $intent['goal'] : $intent['intent'],
            'slots_delta' => $turn_slots,
            'meta'        => array_merge([
                'intent_type' => $intent['intent'],
                'confidence'  => $intent['confidence'] ?? 0,
                'method'      => $intent['method'] ?? '',
            ],
                // Bổ sung plugin_slug, tool_name, client_name vào meta turn
                (class_exists('BizCity_Intent_Provider_Registry') && !empty($intent['goal'])
                    ? (function() use ($intent) {
                        $provider = BizCity_Intent_Provider_Registry::instance()->get_provider_for_goal($intent['goal']);
                        $meta = [];
                        if ($provider) {
                            $meta['plugin_slug'] = $provider->get_id();
                            $meta['client_name'] = $provider->get_name();
                        }
                        return $meta;
                    })() : []),
                (!empty($plan_result['tool_name']) ? ['tool_name' => $plan_result['tool_name']] : [])
            ),
        ] );

        // ── Step 4.6: Recover session-pending images (Step 1.5C) ──
        // When images were stored via image+text smart routing and the user
        // subsequently picked a tool, inject stored images into the active
        // conversation so the planner can auto-fill image slots.
        $current_slots_s46 = json_decode( $conversation['slots_json'] ?? '{}', true ) ?: [];
        if ( ! empty( $current_slots_s46['_session_pending_images'] ) && empty( $images ) ) {
            $recovered_images = $current_slots_s46['_session_pending_images'];
            $images           = array_merge( $images, $recovered_images );
            $params['images'] = $images;

            // Clear the session buffer
            $this->conversation_mgr->update_slots( $conv_id, [
                '_session_pending_images' => null,
            ] );

            // Auto-fill any 'image' type slot with the recovered images
            $current_goal = $conversation['goal'] ?? '';
            if ( $current_goal ) {
                $plan_def = $this->planner->get_plan( $current_goal );
                if ( $plan_def ) {
                    $all_fields = array_merge(
                        $plan_def['required_slots'] ?? [],
                        $plan_def['optional_slots'] ?? []
                    );
                    foreach ( $all_fields as $field_name => $field_cfg ) {
                        $field_type = $field_cfg['type'] ?? 'text';
                        if ( $field_type === 'image' ) {
                            $existing_val = $conversation['slots'][ $field_name ] ?? '';
                            if ( empty( $existing_val ) ) {
                                $img_val = count( $recovered_images ) === 1
                                    ? $recovered_images[0]
                                    : implode( ',', $recovered_images );
                                $this->conversation_mgr->update_slots( $conv_id, [
                                    $field_name => $img_val,
                                ] );
                                $conversation['slots'][ $field_name ] = $img_val;
                                $intent['entities'][ $field_name ]    = $img_val;
                            }
                            break; // Fill only the first image slot
                        }
                    }
                }
            }

            $this->logger->log( 'session_image_recover', [
                'recovered_count' => count( $recovered_images ),
                'goal'            => $current_goal,
            ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );
        }

        // ── Step 5: Plan next action ──
        do_action( 'bizcity_intent_status', '📋 Đang lên kế hoạch thực thi...' );
        $plan_start  = microtime( true );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[INTENT-ENGINE] Step 5 → planner->plan()'
                     . ' | conv_goal=' . ( $conversation['goal'] ?? '' )
                     . ' | intent=' . ( $intent['intent'] ?? '' )
                     . ' | intent_goal=' . ( $intent['goal'] ?? '' )
                     . ' | entities=' . wp_json_encode( $intent['entities'] ?? [], JSON_UNESCAPED_UNICODE ) );
        }
        // ── O4: Pass raw message to planner for multi-slot scan (v3.6.1) ──
        $intent['raw_message'] = $message;

        // ── Phase 1.2: Pass skill_match to planner for required_inputs merge ──
        if ( ! empty( $skill_match ) ) {
            $intent['skill_match'] = $skill_match;
        }

        $plan_result = $this->planner->plan( $conversation, $intent );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[INTENT-ENGINE] Step 5 result → action=' . ( $plan_result['action'] ?? '?' )
                     . ' | tool_name=' . ( $plan_result['tool_name'] ?? '' )
                     . ' | slot_count=' . count( $plan_result['tool_slots'] ?? [] ) );
        }
        $this->logger->log( 'plan', [
            'action'     => $plan_result['action'],
            'tool_name'  => $plan_result['tool_name'] ?? '',
            'ask_field'  => $plan_result['ask_field'] ?? '',
            'slot_count' => count( $plan_result['tool_slots'] ?? [] ),
            'plan_ms'    => round( ( microtime( true ) - $plan_start ) * 1000, 2 ),
        ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

        $result['goal']       = $conversation['goal'];
        $result['goal_label'] = $conversation['goal_label'] ?? '';
        $result['slots']      = $plan_result['tool_slots'];
        $result['action']     = $plan_result['action'];

        // ── Ensure tool_name is always available in meta (for message tracking) ──
        $resolved_tool_name = $plan_result['tool_name'] ?? '';
        if ( ! $resolved_tool_name && ! empty( $conversation['goal'] ) ) {
            $goal_plan_meta = $this->planner->get_plan( $conversation['goal'] );
            $resolved_tool_name = $goal_plan_meta['tool'] ?? '';
        }
        if ( $resolved_tool_name ) {
            $result['meta']['tool_name'] = $resolved_tool_name;
        }

        // ── Step 6: Execute planned action ──
        switch ( $plan_result['action'] ) {

            case 'ask_user':
                do_action( 'bizcity_intent_status', '❓ Cần thu thập thêm thông tin...' );
                // Ask user for missing field — use LLM for natural wording when ai_compose is enabled
                $prompt = $this->format_ask_prompt( $plan_result, $message, $conversation );
                $this->conversation_mgr->set_waiting(
                    $conv_id,
                    $plan_result['ask_type'],
                    $plan_result['ask_field']
                );
                // Update slots with what we have so far
                if ( ! empty( $plan_result['tool_slots'] ) ) {
                    $this->conversation_mgr->update_slots( $conv_id, $plan_result['tool_slots'] );
                }
                $result['reply']  = $prompt;
                $result['status'] = 'WAITING_USER';

                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $prompt, [
                    'meta' => [ 'ask_field' => $plan_result['ask_field'], 'ask_type' => $plan_result['ask_type'] ],
                ] );
                break;

            case 'call_tool':
                // ── Pre-flight confirmation: show slots summary and ask user to confirm ──
                // v4.7.0: Uses BizCity_Confirm_Analyzer (LLM-based) instead of binary regex.
                // Supports 5 intents: accept, accept_modify, modify, reject, new_goal.
                $awaiting_confirm = $confirm_pending || ! empty( $conversation['slots']['_awaiting_confirm'] );

                if ( $awaiting_confirm ) {
                    $confirm_analysis = BizCity_Confirm_Analyzer::instance()->analyze(
                        $message, $conversation, $this->planner
                    );
                    $confirm_intent = $confirm_analysis['intent'];

                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[INTENT-ENGINE] Confirm analysis: intent=' . $confirm_intent
                                 . ' | method=' . $confirm_analysis['method']
                                 . ' | updates=' . wp_json_encode( $confirm_analysis['slot_updates'], JSON_UNESCAPED_UNICODE ) );
                    }

                    // Clear _awaiting_confirm for all paths
                    $this->conversation_mgr->update_slots( $conv_id, [ '_awaiting_confirm' => '' ] );

                    if ( $confirm_intent === BizCity_Confirm_Analyzer::INTENT_ACCEPT ) {
                        // ── Simple accept → fall through to execution ──
                        // (nothing else needed)

                    } elseif ( $confirm_intent === BizCity_Confirm_Analyzer::INTENT_ACCEPT_MODIFY ) {
                        // ── Accept with enrichment → apply slot changes, then execute ──
                        if ( ! empty( $confirm_analysis['slot_updates'] ) ) {
                            $this->conversation_mgr->update_slots( $conv_id, $confirm_analysis['slot_updates'] );
                        }
                        // Refresh conversation + re-plan so $plan_result has updated slot values
                        $conversation = $this->conversation_mgr->get_active( $user_id, $channel, $session_id );
                        if ( ! $conversation ) break;
                        $plan_retry = $this->planner->plan( $conversation, $intent );
                        if ( $plan_retry['action'] === 'call_tool' ) {
                            $plan_result = $plan_retry;
                        }
                        // Fall through to execution

                    } elseif ( $confirm_intent === BizCity_Confirm_Analyzer::INTENT_MODIFY ) {
                        // ── Modify only → apply changes, re-show confirm ──
                        if ( ! empty( $confirm_analysis['slot_updates'] ) ) {
                            $this->conversation_mgr->update_slots( $conv_id, $confirm_analysis['slot_updates'] );
                        }
                        $conversation = $this->conversation_mgr->get_active( $user_id, $channel, $session_id );
                        if ( ! $conversation ) break;
                        $plan_retry = $this->planner->plan( $conversation, $intent );
                        if ( $plan_retry['action'] === 'ask_user' ) {
                            $prompt_retry = $this->format_ask_prompt( $plan_retry, $message, $conversation );
                            $this->conversation_mgr->set_waiting( $conv_id, $plan_retry['ask_type'], $plan_retry['ask_field'] );
                            $result['reply']  = $prompt_retry;
                            $result['status'] = 'WAITING_USER';
                            $result['action'] = 'ask_user';
                            $this->conversation_mgr->add_turn( $conv_id, 'assistant', $prompt_retry );
                            break;
                        }
                        // Still call_tool → re-show confirmation with updated slots
                        $plan_result = $plan_retry;
                        $confirm_prompt = $this->build_confirm_prompt( $plan_result, $conversation );
                        $this->conversation_mgr->update_slots( $conv_id, [ '_awaiting_confirm' => '1' ] );
                        $this->conversation_mgr->set_waiting( $conv_id, 'confirm', '_confirm_execute' );
                        $result['reply']  = $confirm_prompt;
                        $result['status'] = 'WAITING_USER';
                        $result['action'] = 'ask_user';
                        $this->conversation_mgr->add_turn( $conv_id, 'assistant', $confirm_prompt, [
                            'meta' => [ 'ask_field' => '_confirm_execute', 'ask_type' => 'confirm' ],
                        ] );
                        break;

                    } elseif ( $confirm_intent === BizCity_Confirm_Analyzer::INTENT_REJECT ) {
                        // ── Reject → complete conversation ──
                        $this->conversation_mgr->complete( $conv_id, 'User rejected confirmation.' );
                        $result['reply']  = '👌 Đã hủy thực hiện. Bạn cần gì khác thì nói mình nhé!';
                        $result['action'] = 'complete';
                        $result['status'] = 'COMPLETED';
                        $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
                        break;

                    } else {
                        // ── new_goal / unknown → treat as reject + redirect ──
                        $this->conversation_mgr->complete( $conv_id, 'User switched topic during confirm.' );
                        $result['reply']  = '👌 Mình đã hủy yêu cầu trước. Bạn vui lòng nhắn lại để mình hỗ trợ nhé!';
                        $result['action'] = 'complete';
                        $result['status'] = 'COMPLETED';
                        $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
                        break;
                    }
                }

                if ( ! $awaiting_confirm ) {
                    // ── Sprint 1B: Auto-execute trusted tools (skip confirm) ──
                    // Tools with auto_execute=true skip confirm when ALL conditions met:
                    //   1. Tool declares auto_execute: true
                    //   2. All required slots filled (or have defaults)
                    //   3. Classifier confidence ≥ 0.85
                    //   4. No slot has type 'image' (needs visual confirm)
                    $tool_name_for_check = $plan_result['tool_name'] ?? '';
                    $should_auto_exec    = false;
                    if ( $this->tools->is_auto_execute( $tool_name_for_check ) ) {
                        $check_schema    = $this->tools->get_schema( $tool_name_for_check );
                        $check_slots     = $plan_result['tool_slots'] ?? [];
                        $mode_confidence = (float) ( $mode_result['confidence'] ?? 0 );
                        $has_image_slot  = false;
                        $missing_req     = false;

                        foreach ( $check_schema['input_fields'] ?? [] as $f => $cfg ) {
                            if ( ( $cfg['type'] ?? '' ) === 'image' && ! empty( $check_slots[ $f ] ) ) {
                                $has_image_slot = true;
                            }
                            if ( ! empty( $cfg['required'] ) ) {
                                $val = $check_slots[ $f ] ?? ( $cfg['default'] ?? null );
                                if ( $val === null || $val === '' ) {
                                    $missing_req = true;
                                }
                            }
                        }

                        if ( $mode_confidence >= 0.85 && ! $has_image_slot && ! $missing_req ) {
                            $should_auto_exec = true;
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( "[INTENT-ENGINE] Auto-execute: tool={$tool_name_for_check} confidence={$mode_confidence}" );
                            }
                        }
                    }

                    if ( ! $should_auto_exec ) {
                    // Check if there are meaningful user-provided slots to confirm.
                    // Skip confirmation for trivial/auto-filled tools (no visible slots).
                    $has_visible_slots = false;
                    foreach ( $plan_result['tool_slots'] ?? [] as $field => $value ) {
                        if ( $value === '' || $value === null ) continue;
                        if ( str_starts_with( $field, '_' ) ) continue;
                        if ( in_array( $field, [ 'session_id', 'user_id', 'platform' ], true ) ) continue;
                        $has_visible_slots = true;
                        break;
                    }

                    if ( $has_visible_slots ) {
                        do_action( 'bizcity_intent_status', '✅ Kiểm tra thông tin trước khi thực thi...' );
                        // First time reaching call_tool — show confirmation prompt
                        $confirm_prompt = $this->build_confirm_prompt( $plan_result, $conversation );
                        $this->conversation_mgr->update_slots( $conv_id, [ '_awaiting_confirm' => '1' ] );
                        $this->conversation_mgr->set_waiting( $conv_id, 'confirm', '_confirm_execute' );
                        if ( ! empty( $plan_result['tool_slots'] ) ) {
                            $this->conversation_mgr->update_slots( $conv_id, $plan_result['tool_slots'] );
                        }
                        $result['reply']  = $confirm_prompt;
                        $result['status'] = 'WAITING_USER';
                        $result['action'] = 'ask_user';
                        $this->conversation_mgr->add_turn( $conv_id, 'assistant', $confirm_prompt, [
                            'meta' => [ 'ask_field' => '_confirm_execute', 'ask_type' => 'confirm' ],
                        ] );

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[INTENT-ENGINE] Pre-flight confirm: tool=' . $plan_result['tool_name'] . ' | goal=' . ( $conversation['goal'] ?? '' ) );
                        }
                        break;
                    }
                    // No visible slots → skip confirmation, fall through to execution
                    } // end if ( ! $should_auto_exec )
                }

                // ── User confirmed — proceed with execution ──
                do_action( 'bizcity_intent_status', '🚀 Xác nhận thành công, đang thực thi...' );

                // ── Slot fill rate tracking (v3.6.2) ──
                $fill_plan = $this->planner->get_plan( $conversation['goal'] );
                if ( $fill_plan ) {
                    $all_plan_fields = array_merge(
                        array_keys( $fill_plan['required_slots'] ?? [] ),
                        array_keys( $fill_plan['optional_slots'] ?? [] )
                    );
                    $total_fields = count( $all_plan_fields );
                    $filled_count = 0;
                    $current_s    = $conversation['slots'] ?? [];
                    foreach ( $all_plan_fields as $f ) {
                        if ( $f === '_meta' || $f === 'message' ) continue;
                        $v = $current_s[ $f ] ?? '';
                        if ( is_string( $v ) && $v !== '' ) $filled_count++;
                    }
                    $this->logger->log( 'slot_fill_rate', [
                        'goal'         => $conversation['goal'],
                        'tool_name'    => $plan_result['tool_name'],
                        'total_slots'  => $total_fields,
                        'filled_count' => $filled_count,
                        'fill_rate'    => $total_fields > 0 ? round( $filled_count / $total_fields * 100 ) : 100,
                        'turns_taken'  => (int) ( $conversation['turn_count'] ?? 0 ),
                    ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );
                }

                // ── Fire execution detected hook — bizcity-executor may intercept async ──
                $tool_slots = $plan_result['tool_slots'];
                unset( $GLOBALS['bizcity_executor_claimed'] ); // reset before dispatch
                $exec_context = [
                    'conv_id'       => $conv_id,
                    'session_id'    => $session_id,
                    'user_id'       => $user_id,
                    'channel'       => $channel,
                    'character_id'  => $character_id,
                    'blog_id'       => get_current_blog_id(),
                    'actor_user_id' => $user_id,
                    'message'       => $message,
                    'message_id'    => $message_id,
                    'tool_slots'    => $tool_slots,
                ];
                do_action( 'bizcity_intent_execution_detected', $plan_result, $exec_context );

                // ── Executor claimed this job → async workflow dispatch ──
                if ( ! empty( $GLOBALS['bizcity_executor_claimed'] ) ) {
                    $claimed      = $GLOBALS['bizcity_executor_claimed'];
                    $trace_id_ack = $claimed['trace_id'] ?? '';
                    $task_count   = $claimed['task_count'] ?? 0;
                    $wf_title     = $claimed['title'] ?? ( $conversation['goal_label'] ?? $plan_result['tool_name'] );
                    $claim_status = $claimed['status'] ?? 'running';

                    unset( $GLOBALS['bizcity_executor_claimed'] );

                    $result['reply']  = "⏳ Đã nhận nhiệm vụ: **{$wf_title}** ({$task_count} bước). Em đang xử lý, bạn chờ chút nhé!";
                    $result['action'] = 'complete';
                    $result['status'] = 'COMPLETED';
                    $result['meta']['executor_trace_id'] = $trace_id_ack;
                    $result['meta']['tool_name']         = $plan_result['tool_name'];

                    $this->conversation_mgr->complete( $conv_id );
                    $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
                    break;
                }

                do_action( 'bizcity_intent_status', '⚙️ Đang thực thi: ' . ( $conversation['goal_label'] ?? $plan_result['tool_name'] ) );

                // ── Phase 1.1e: Unified execution via BizCity_Tool_Run ──
                $tool_result = BizCity_Tool_Run::execute(
                    $plan_result['tool_name'],
                    $tool_slots,
                    [
                        'session_id'   => $session_id,
                        'user_id'      => $user_id,
                        'channel'      => $channel,
                        'conv_id'      => $conv_id,
                        'goal'         => $conversation['goal'] ?? '',
                        'goal_label'   => $conversation['goal_label'] ?? '',
                        'character_id' => $character_id,
                        'message_id'   => $message_id,
                        'caller'       => 'intent_engine',
                    ]
                );
                $tool_duration = $tool_result['duration_ms'] ?? 0;

                $this->logger->log( 'execute_tool', [
                    'tool_name' => $plan_result['tool_name'],
                    'success'   => ! empty( $tool_result['success'] ),
                    'has_msg'   => ! empty( $tool_result['message'] ),
                    'missing'   => $tool_result['missing_fields'] ?? [],
                    'verified'  => $tool_result['verified'] ?? false,
                    'skill'     => ! empty( $tool_result['skill'] ) ? $tool_result['skill']['title'] : null,
                    'tool_ms'   => $tool_duration,
                ], $conv_id, $conversation['turn_count'] ?? 0, $user_id, $channel );

                // Tool reports missing fields → ask with emotional smoothing
                if ( ! empty( $tool_result['missing_fields'] ) ) {
                    $missing_field = $tool_result['missing_fields'][0];
                    $plan = $this->planner->get_plan( $conversation['goal'] );
                    $field_config = [];
                    if ( $plan ) {
                        $field_config = $plan['required_slots'][ $missing_field ]
                                     ?? $plan['optional_slots'][ $missing_field ]
                                     ?? [];
                    }

                    $raw_prompt = $field_config['prompt'] ?? "Vui lòng cung cấp: {$missing_field}";

                    // ── O8: Context-aware re-ask — enrich prompt with filled slots info (v3.6.1) ──
                    // When tool returns missing_fields, include what user already provided
                    // so the re-ask feels contextual: "Bạn đã cho mình biết topic là 'AI'... còn thiếu ảnh"
                    $current_slots = $conversation['slots'] ?? [];
                    $filled_summary_parts = [];
                    if ( $plan ) {
                        $all_cfg = array_merge( $plan['required_slots'] ?? [], $plan['optional_slots'] ?? [] );
                        foreach ( $all_cfg as $cf => $cc ) {
                            if ( $cf === $missing_field || $cf === 'message' || $cf === '_meta' ) continue;
                            $v = $current_slots[ $cf ] ?? '';
                            if ( is_string( $v ) && $v !== '' ) {
                                $label = $cc['label'] ?? $cf;
                                $v_short = mb_strlen( $v, 'UTF-8' ) > 30 ? mb_substr( $v, 0, 27, 'UTF-8' ) . '...' : $v;
                                $filled_summary_parts[] = "{$label}: \"{$v_short}\"";
                            }
                        }
                    }
                    if ( ! empty( $filled_summary_parts ) ) {
                        $raw_prompt .= "\n\n(Đã có: " . implode( ', ', $filled_summary_parts ) . ")";
                    }

                    // ── Emotional Smoothing: ALWAYS wrap tool prompts with context ──
                    // Tool prompts ("Tên sản phẩm là gì?") can feel jarring even when
                    // current message intensity is low, because the conversation MAY have
                    // prior emotional context that was acknowledged by the AI.
                    // Cost: ~100-200ms (fast LLM) — acceptable for much better UX.
                    $intensity    = $result['meta']['intensity'] ?? 1;
                    $empathy_flag = $result['meta']['empathy_flag'] ?? false;
                    $mode         = $result['meta']['mode'] ?? 'execution';

                    $smoothed_prompt = $raw_prompt;
                    if ( function_exists( 'bizcity_openrouter_chat' ) ) {
                        $smoothed = $this->smooth_tool_ask_prompt(
                            $raw_prompt,
                            $missing_field,
                            $message,
                            $session_id,
                            $intensity,
                            $mode,
                            $conv_id
                        );
                        if ( $smoothed ) {
                            $smoothed_prompt = $smoothed;
                        }
                    }

                    $this->conversation_mgr->set_waiting( $conv_id, $field_config['type'] ?? 'text', $missing_field );
                    $result['reply']  = $smoothed_prompt;
                    $result['action'] = 'ask_user';
                    $result['status'] = 'WAITING_USER';
                    $this->conversation_mgr->add_turn( $conv_id, 'assistant', $smoothed_prompt );
                    break;
                }

                // Record tool execution
                $this->conversation_mgr->add_turn( $conv_id, 'tool', $tool_result['message'] ?? '', [
                    'tool_calls' => [ [ 'name' => $plan_result['tool_name'], 'result' => $tool_result ] ],
                ] );

                // ── Goal Switch: tool requests switching to a different goal (v3.6.6) ──
                // Example: tarot_reading tool returns switch_goal='tarot_interpret'
                // when user chooses "interpret" path instead of "link" path.
                // Engine updates the conversation goal and sets WAITING_USER for the
                // first slot in the new plan's slot_order.
                if ( ! empty( $tool_result['switch_goal'] ) ) {
                    $new_goal = $tool_result['switch_goal'];
                    $new_plan = $this->planner->get_plan( $new_goal );

                    if ( $new_plan ) {
                        // Look up the new goal's label from goal patterns
                        $new_goal_label = $new_goal;
                        if ( class_exists( 'BizCity_Intent_Router' ) ) {
                            $all_patterns = BizCity_Intent_Router::instance()->get_goal_patterns();
                            foreach ( $all_patterns as $pat_cfg ) {
                                if ( ( $pat_cfg['goal'] ?? '' ) === $new_goal ) {
                                    $new_goal_label = $pat_cfg['label'] ?? $new_goal;
                                    break;
                                }
                            }
                        }

                        // Update conversation to new goal + clear old slots
                        $this->conversation_mgr->set_goal( $conv_id, $new_goal, $new_goal_label );
                        // Reset slots for the new goal (update_slots merges, so we need
                        // to overwrite with an empty JSON object via the DB layer directly)
                        $this->conversation_mgr->update_slots( $conv_id, [
                            '_switched_from' => $conversation['goal'] ?? '',
                        ] );

                        // Find first slot to wait for in new plan's slot_order
                        $new_slot_order = $new_plan['slot_order'] ?? [];
                        $all_new_slots  = array_merge(
                            $new_plan['required_slots'] ?? [],
                            $new_plan['optional_slots'] ?? []
                        );
                        $wait_field = '';
                        $wait_type  = 'text';
                        foreach ( $new_slot_order as $sf ) {
                            if ( isset( $all_new_slots[ $sf ] ) ) {
                                $wait_field = $sf;
                                $wait_type  = $all_new_slots[ $sf ]['type'] ?? 'text';
                                break;
                            }
                        }

                        if ( $wait_field ) {
                            $this->conversation_mgr->set_waiting( $conv_id, $wait_type, $wait_field );
                        }

                        $result['reply']  = $tool_result['message'] ?? '';
                        $result['action'] = 'ask_user';
                        $result['status'] = 'WAITING_USER';
                        $result['meta']['switch_goal'] = $new_goal;
                        $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( "[INTENT-ENGINE] switch_goal: {$conversation['goal']} → {$new_goal}"
                                     . " | wait_field={$wait_field} | wait_type={$wait_type}" );
                        }
                        break;
                    }
                }

                // ── Completion logic ──
                // Tools signal completion via 'complete' key.
                // Default: success → complete (prevents conversation loop).
                // Tools can set 'complete' => false to keep conversation open.
                $tool_complete = isset( $tool_result['complete'] )
                    ? (bool) $tool_result['complete']
                    : ! empty( $tool_result['success'] );

                // Filter: plugins can override completion decision per tool
                $tool_complete = apply_filters(
                    'bizcity_intent_tool_complete',
                    $tool_complete,
                    $plan_result['tool_name'],
                    $tool_result,
                    $plan_result
                );

                if ( $tool_complete ) {
                    // Tool signals work is done → complete conversation
                    $result['reply']  = $tool_result['message'] ?? '✅ Đã thực hiện xong.';
                    $result['action'] = 'complete';
                    $result['status'] = 'COMPLETED';
                    $result['meta']['tool_name'] = $plan_result['tool_name'];
                    $this->conversation_mgr->complete( $conv_id );
                } elseif ( $plan_result['ai_compose'] ) {
                    // Tool returned data for AI to compose response.
                    // complete=false + ai_compose=true → gateway generates reply
                    // (message may be empty — tool delegates full composition to LLM)
                    $result['reply'] = $tool_result['message'] ?? '';
                    $result['meta']['tool_result'] = $tool_result;
                    $result['action'] = 'compose_answer';
                } else {
                    // Fallback: complete and return message
                    $result['reply']  = $tool_result['message'] ?? '✅ Đã thực hiện xong.';
                    $result['action'] = 'complete';
                    $result['status'] = 'COMPLETED';
                    $result['meta']['tool_name'] = $plan_result['tool_name'];
                    $this->conversation_mgr->complete( $conv_id );
                }

                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
                break;

            case 'compose_answer':
            case 'passthrough':
                do_action( 'bizcity_intent_status', '💬 Đang soạn câu trả lời...' );
                // These will be handled by the AI brain (Chat Gateway)
                // The reply will be filled by the caller or by the stream adapter
                $result['reply']  = ''; // Signal to caller: needs AI
                $result['action'] = $plan_result['action'];

                // Build domain context from the owning provider
                if ( $plan_result['action'] === 'compose_answer' && ! empty( $conversation['goal'] ) ) {
                    $provider_context = apply_filters(
                        'bizcity_intent_compose_context',
                        '',
                        $conversation['goal'],
                        $conversation,
                        $user_id
                    );
                    if ( $provider_context ) {
                        $result['meta']['provider_context'] = $provider_context;
                    }

                    // System instructions from provider
                    $registry = BizCity_Intent_Provider_Registry::instance();
                    $provider = $registry->get_provider_for_goal( $conversation['goal'] );
                    if ( $provider ) {
                        $sys_instr = $provider->get_system_instructions( $conversation['goal'] );
                        if ( $sys_instr ) {
                            $result['meta']['system_instructions'] = $sys_instr;
                        }
                        $result['meta']['provider_id']   = $provider->get_id();
                        $result['meta']['provider_name'] = $provider->get_name();
                    }
                }

                // Update slots in DB
                if ( ! empty( $plan_result['tool_slots'] ) ) {
                    $this->conversation_mgr->update_slots( $conv_id, $plan_result['tool_slots'] );
                }
                break;

            case 'complete':
                do_action( 'bizcity_intent_status', '✔️ Hoàn thành!' );
                $this->conversation_mgr->complete( $conv_id );
                $result['reply']  = $this->get_goodbye_message( $conversation );
                $result['status'] = 'COMPLETED';
                $this->conversation_mgr->add_turn( $conv_id, 'assistant', $result['reply'] );
                break;
        }

        $result['rolling_summary'] = $conversation['rolling_summary'] ?? '';
        $result['meta']['trace_id'] = $this->logger->get_trace_id();

        // ═══ UNIVERSAL PROVIDER_ID RESOLUTION ═══
        // Ensure meta.provider_id is always set when we have a goal,
        // regardless of which action branch was taken (ask_user, complete, call_tool, etc.)
        // This enables consistent plugin_slug tracking in SSE done events + webchat_messages.
        if ( empty( $result['meta']['provider_id'] ) && ! empty( $result['goal'] ) && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $goal_provider = BizCity_Intent_Provider_Registry::instance()->get_provider_for_goal( $result['goal'] );
            if ( $goal_provider ) {
                $result['meta']['provider_id']   = $goal_provider->get_id();
                $result['meta']['provider_name'] = $goal_provider->get_name();
            }
        }

        // ── End pipeline trace ──
        $this->logger->end_trace( $result );

        // Fire action for other plugins
        do_action( 'bizcity_intent_processed', $result, $params );

        return $result;
    }

    /* ================================================================
     *  Chat Gateway integration
     * ================================================================ */

    /**
     * Filter: bizcity_chat_pre_ai_response
     *
     * Intercepts messages before they reach the AI brain.
     * If the intent engine determines the message needs special handling
     * (goal management, tool call, ask_user), it returns a reply directly.
     * Otherwise returns null to let the gateway proceed normally.
     *
     * @param mixed $pre_reply Current pre-reply value (null = not handled).
     * @param array $context   Message context from Chat Gateway.
     * @return array|null
     */
    public function intercept_chat( $pre_reply, $context ) {
        // If already handled by another filter, skip
        if ( is_array( $pre_reply ) && ! empty( $pre_reply['message'] ) ) {
            return $pre_reply;
        }

        $message        = $context['message']        ?? '';
        $session_id     = $context['session_id']     ?? '';
        $user_id        = intval( $context['user_id'] ?? 0 );
        $character_id   = intval( $context['character_id'] ?? 0 );
        $platform_type  = $context['platform_type']  ?? 'WEBCHAT';
        $images         = $context['images']         ?? [];
        $provider_hint  = $context['provider_hint']  ?? '';  // v3.2.0: @ mention hint
        $plugin_slug    = $context['plugin_slug']    ?? '';  // v3.2.0: @ mention plugin
        $routing_mode   = $context['routing_mode']   ?? 'automatic';
        $tool_goal      = $context['tool_goal']      ?? '';  // v4.0: tool chip / slash command goal
        $tool_name      = $context['tool_name']      ?? '';  // v4.0: tool function name

        // Map platform to channel
        $channel_map = [
            'WEBCHAT'   => 'webchat',
            'ADMINCHAT' => 'adminchat',
        ];
        $channel = $channel_map[ $platform_type ] ?? 'webchat';

        // Process through engine
        $result = $this->process( [
            'message'       => $message,
            'session_id'    => $session_id,
            'user_id'       => $user_id,
            'channel'       => $channel,
            'character_id'  => $character_id,
            'images'        => $images,
            'provider_hint' => $provider_hint ?: $plugin_slug,  // v3.2.0: pass @ mention as hint
            'tool_goal'     => $tool_goal,   // v4.0: tool chip direct routing
            'tool_name'     => $tool_name,   // v4.0: tool function name
        ] );

        // If engine handled it completely (has a reply)
        if ( ! empty( $result['reply'] ) ) {
            // Compute focus_mode for frontend plugin context lifecycle
            $action = $result['action'] ?? '';
            if ( $action === 'ask_user' ) {
                $fm = 'active';
            } elseif ( $action === 'complete' ) {
                $fm = 'completed';
            } elseif ( ! empty( $result['goal'] ) && ! empty( $plugin_slug ) ) {
                $fm = 'active';
            } else {
                $fm = 'none';
            }

            return [
                'message'         => $result['reply'],
                'action'          => $result['action'],
                'conversation_id' => $result['conversation_id'],
                'goal'            => $result['goal'],
                'goal_label'      => $result['goal_label'] ?? '',
                'slots'           => $result['slots'],
                'plugin_slug'     => $plugin_slug,  // v3.2.0: echo back for DB + frontend badge
                'focus_mode'      => $fm,            // v3.3.0: HIL focus mode lifecycle signal
            ];
        }

        // Engine says "passthrough" or "compose_answer" → let gateway handle AI
        // But we still enrich the context with conversation data
        // This is done by adding conversation info that the gateway can use
        if ( ! empty( $result['conversation_id'] ) && ! empty( $result['goal'] ) ) {
            // Store in a global so gateway can access it
            $GLOBALS['bizcity_intent_context'] = $result;

            // Inject provider context into gateway's system prompt via filter
            if ( ! empty( $result['meta']['provider_context'] ) || ! empty( $result['meta']['system_instructions'] ) ) {
                add_filter( 'bizcity_chat_system_prompt', function ( $prompt ) use ( $result ) {
                    if ( ! empty( $result['meta']['system_instructions'] ) ) {
                        $prompt .= "\n\n" . $result['meta']['system_instructions'];
                    }
                    if ( ! empty( $result['meta']['provider_context'] ) ) {
                        $prompt .= "\n\n" . $result['meta']['provider_context'];
                    }
                    return $prompt;
                }, 50 );
            }
        }

        return null; // Let gateway proceed with AI
    }

    /**
     * Post-process: after Chat Gateway sends a response.
     * Update the conversation with the AI's reply.
     *
     * @param array $data
     */
    public function post_process( $data ) {
        if ( empty( $GLOBALS['bizcity_intent_context'] ) ) {
            return;
        }

        $intent_ctx = $GLOBALS['bizcity_intent_context'];
        $conv_id    = $intent_ctx['conversation_id'] ?? '';
        $bot_reply  = $data['bot_reply'] ?? '';

        if ( $conv_id && $bot_reply ) {
            // Record AI's response as a turn
            $this->conversation_mgr->add_turn( $conv_id, 'assistant', $bot_reply, [
                'meta' => [
                    'provider' => $data['provider'] ?? '',
                    'model'    => $data['model'] ?? '',
                ],
            ] );

            // Update rolling summary periodically (every 5 turns)
            $conv = $this->conversation_mgr->get_active(
                intval( $data['user_id'] ?? 0 ),
                $intent_ctx['channel'] ?? 'webchat',
                $data['session_id'] ?? ''
            );

            if ( $conv && $conv['turn_count'] > 0 && $conv['turn_count'] % 5 === 0 ) {
                $this->update_rolling_summary( $conv );
            }
        }

        // Auto-title webchat session after first bot reply
        $session_id = $data['session_id'] ?? '';
        if ( $session_id && strpos( $session_id, 'wcs_' ) === 0 ) {
            $this->maybe_auto_title_session( $session_id );
        }

        // Clear global
        unset( $GLOBALS['bizcity_intent_context'] );
    }

    /* ================================================================
     *  Summary management
     * ================================================================ */

    /**
     * Use LLM to condense conversation into a rolling summary.
     *
     * @param array $conversation
     */
    private function update_rolling_summary( array $conversation ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return;
        }

        $turns = $this->conversation_mgr->get_turns( $conversation['conversation_id'], 20 );
        if ( count( $turns ) < 3 ) {
            return;
        }

        $history = '';
        foreach ( $turns as $t ) {
            $role = $t['role'] === 'user' ? 'User' : 'Bot';
            $history .= "{$role}: {$t['content']}\n";
        }

        $prompt = "Tóm tắt ngắn gọn (3-5 câu, tiếng Việt) cuộc hội thoại sau. "
                . "Nêu rõ: mục tiêu chính, thông tin đã thu thập, và việc gì còn dang dở.\n\n"
                . "Goal: {$conversation['goal']}\n"
                . "Slots: " . wp_json_encode( $conversation['slots'], JSON_UNESCAPED_UNICODE ) . "\n\n"
                . "Hội thoại:\n{$history}\n\nTóm tắt:";

        $result = bizcity_openrouter_chat(
            [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
            [
                'purpose'     => 'fast',
                'temperature' => 0.3,
                'max_tokens'  => 300,
            ]
        );

        if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
            $this->conversation_mgr->update_summary(
                $conversation['conversation_id'],
                $result['message']
            );
        }
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    /**
     * Format the ask_user prompt (with choices if applicable).
     * When ai_compose is enabled AND an LLM is available, compose a
     * natural-sounding question using the static prompt as guidance,
     * enriched with conversation context so the question feels
     * seamless and human.
     *
     * @param array  $plan_result
    /* ================================================================
     *  Sprint 1D: Centralized WAITING_USER state resolver
     *
     *  Encapsulates the common patterns for detecting and resolving
     *  WAITING_USER states. Individual guard locations can delegate here.
     *  Calling convention: call once early in process(), store result.
     * ================================================================ */

    /**
     * Check if conversation is in WAITING_USER state and determine
     * what action to take for the current message.
     *
     * @param array  $conversation Current conversation data.
     * @param string $message      User's message.
     * @return array|null  Null if not waiting. Otherwise:
     *   [
     *     'is_waiting'     => bool,
     *     'waiting_for'    => string,  // slot type: text, confirm, choice, image
     *     'waiting_field'  => string,  // which field is being waited for
     *     'has_active_goal'=> bool,
     *     'retry_count'    => int,     // current _slot_retry_count
     *     'is_confirm'     => bool,    // waiting for confirm card response
     *   ]
     */
    public function resolve_waiting_state( array $conversation ) {
        $status   = $conversation['status'] ?? '';
        $goal     = $conversation['goal'] ?? '';
        $wf       = $conversation['waiting_field'] ?? '';
        $wtype    = $conversation['waiting_for'] ?? '';
        $slots    = $conversation['slots'] ?? [];

        if ( $status !== 'WAITING_USER' ) {
            return null;
        }

        return [
            'is_waiting'      => true,
            'waiting_for'     => $wtype,
            'waiting_field'   => $wf,
            'has_active_goal' => ! empty( $goal ),
            'retry_count'     => (int) ( $slots['_slot_retry_count'] ?? 0 ),
            'is_confirm'      => $wf === '_confirm_execute',
        ];
    }

    /**
     * @param string $user_message  The user message that triggered this ask.
     * @param array  $conversation  Current conversation data (goal, slots, etc.).
     * @return string
     */
    private function format_ask_prompt( array $plan_result, $user_message = '', $conversation = [] ) {
        $static_prompt = $plan_result['ask_prompt'] ?? 'Vui lòng cung cấp thêm thông tin.';

        // Build choice list if applicable
        $choices_text = '';
        if ( ! empty( $plan_result['ask_choices'] ) && $plan_result['ask_type'] === 'choice' ) {
            $choices_text .= "\n";
            $i = 1;
            foreach ( $plan_result['ask_choices'] as $key => $label ) {
                $choices_text .= "\n{$i}. {$label}";
                $i++;
            }
        }

        // If ai_compose is enabled, try to compose a natural question via LLM
        $ai_compose = $plan_result['ai_compose'] ?? false;
        if ( $ai_compose && function_exists( 'bizcity_openrouter_chat' ) && ! empty( $user_message ) ) {
            $natural = $this->compose_natural_ask_prompt(
                $static_prompt,
                $choices_text,
                $user_message,
                $conversation,
                $plan_result
            );
            if ( $natural ) {
                return $natural;
            }
        }

        // Fallback: static prompt + choices
        return $static_prompt . $choices_text;
    }

    /**
     * Build pre-flight confirmation prompt showing filled slots summary.
     *
     * Displays what the engine has collected so far and asks the user
     * to confirm before executing the tool.
     *
     * @param array $plan_result   Plan result from Planner (tool_name, tool_slots).
     * @param array $conversation  Current conversation data.
     * @return string              Confirmation message with slots summary.
     */
    private function build_confirm_prompt( array $plan_result, array $conversation ): string {
        $goal_label = $conversation['goal_label'] ?? ( $plan_result['goal'] ?? '' );
        $tool_name  = $plan_result['tool_name'] ?? '';
        $tool_slots = $plan_result['tool_slots'] ?? [];

        // Get slot definitions from the plan for descriptions
        $plan = $this->planner->get_plan( $conversation['goal'] ?? '' );
        $all_slot_defs = [];
        if ( $plan ) {
            $all_slot_defs = array_merge( $plan['required_slots'] ?? [], $plan['optional_slots'] ?? [] );
        }

        // Build numbered list of filled slots
        $lines = [];
        $idx   = 1;
        foreach ( $tool_slots as $field => $value ) {
            if ( $value === '' || $value === null ) continue;
            // Skip internal/meta fields
            if ( str_starts_with( $field, '_' ) ) continue;
            if ( in_array( $field, [ 'session_id', 'user_id', 'platform', 'client_name', 'plugin_slug', 'tool_name' ], true ) ) continue;
            // Only show slots that are defined in the plan (skip stray LLM-injected fields)
            if ( ! empty( $all_slot_defs ) && ! array_key_exists( $field, $all_slot_defs ) ) continue;

            $desc = $all_slot_defs[ $field ]['prompt'] ?? $field;
            // Truncate long prompt text — use first sentence or 40 chars
            $desc = mb_substr( preg_replace( '/[?？✨📦💕💼💰🌟📅🎯]+$/u', '', $desc ), 0, 40, 'UTF-8' );

            if ( is_array( $value ) ) {
                $display = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
            } else {
                $str_val = (string) $value;
                // Keep URLs intact so images/videos/links can be previewed
                $is_url  = preg_match( '#^https?://#i', $str_val );
                $display = ( ! $is_url && mb_strlen( $str_val ) > 60 )
                    ? mb_substr( $str_val, 0, 57, 'UTF-8' ) . '...'
                    : $str_val;
            }

            $lines[] = "{$idx}. **{$field}**: {$display}";
            $idx++;
        }

        $summary = implode( "\n", $lines );

        $header = "📋 **{$goal_label}**\n\nMình đã ghi nhận các thông tin sau:";
        $footer = "\n\n🟢 Gõ **OK** để thực hiện, hoặc bổ sung/chỉnh sửa thêm.";

        return $header . "\n" . $summary . $footer;
    }

    /**
     * Parse slot corrections from user's confirmation response via LLM.
     *
     * When user responds to confirm card with corrections like
     * "sửa lại chủ đề là dinh dưỡng chữa lành", this method uses LLM
     * to extract which slot(s) to update and their new values.
     *
     * @param string $message       User's message (the correction text).
     * @param array  $conversation  Current conversation data.
     * @return array Corrected slot key-value pairs, empty if no corrections parsed.
     */
    private function parse_confirm_correction( $message, $conversation ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [];
        }

        $goal      = $conversation['goal'] ?? '';
        $plan      = $goal ? $this->planner->get_plan( $goal ) : null;
        if ( ! $plan ) {
            return [];
        }

        $all_slots     = array_merge( $plan['required_slots'] ?? [], $plan['optional_slots'] ?? [] );
        $current_slots = $conversation['slots'] ?? [];

        // Build schema showing current values
        $schema_lines = [];
        foreach ( $all_slots as $field => $config ) {
            $type   = $config['type'] ?? 'text';
            $value  = $current_slots[ $field ] ?? '';
            $display = is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : mb_substr( (string) $value, 0, 80, 'UTF-8' );
            $line   = "- {$field}: type={$type}, current_value=\"{$display}\"";
            if ( ! empty( $config['choices'] ) && is_array( $config['choices'] ) ) {
                $choices_str = [];
                foreach ( $config['choices'] as $key => $label ) {
                    $display_key   = is_int( $key ) ? $label : $key;
                    $choices_str[] = "{$display_key}=\"" . strip_tags( $label ) . '"';
                }
                $line .= "\n  choices: [" . implode( ', ', $choices_str ) . ']';
            }
            $schema_lines[] = $line;
        }
        $schema_text = implode( "\n", $schema_lines );

        $system = <<<PROMPT
Bạn là slot correction engine. User vừa xem bảng xác nhận thông tin và muốn SỬA/THAY ĐỔI một số slot.

SLOT SCHEMA (giá trị hiện tại):
{$schema_text}

QUY TẮC:
1. Phân tích tin nhắn user để xác định slot nào cần SỬA và giá trị MỚI.
2. Chỉ extract slot được đề cập RÕ RÀNG. KHÔNG thay đổi slot user không nhắc.
3. Với choice type: MAP user text về đúng KEY.
4. Trả JSON duy nhất: {"slots": {"field_name": "new_value"}, "understood": true}
5. Nếu không hiểu user muốn sửa gì: {"slots": {}, "understood": false}
PROMPT;

        $ai = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $message ],
            ],
            [
                'purpose'     => 'slot_extract',
                'temperature' => 0.05,
                'max_tokens'  => 200,
                'no_fallback' => false,
            ]
        );

        if ( empty( $ai['success'] ) || empty( $ai['message'] ) ) {
            return [];
        }

        // Parse JSON from response
        $raw = $ai['message'];
        if ( preg_match( '/\{[^{}]*"slots"\s*:\s*\{[^}]*\}[^}]*\}/us', $raw, $m ) ) {
            $parsed = json_decode( $m[0], true );
        } else {
            $parsed = json_decode( $raw, true );
        }

        if ( ! is_array( $parsed ) || empty( $parsed['understood'] ) || empty( $parsed['slots'] ) ) {
            return [];
        }

        // Validate: only return slots that exist in the plan
        $corrections = [];
        foreach ( $parsed['slots'] as $field => $value ) {
            if ( isset( $all_slots[ $field ] ) && $value !== '' && $value !== null ) {
                $corrections[ $field ] = $value;
            }
        }

        return $corrections;
    }

    /**
     * Compose a natural, conversational ask_user prompt via fast LLM.
     *
     * Instead of returning a static prompt like "Bạn đã ăn gì?", this method
     * uses a lightweight LLM call to generate a warm, contextual question
     * that incorporates the conversation history and user's intent.
     *
     * @param string $static_prompt   The original static slot prompt.
     * @param string $choices_text    Formatted choices (if any).
     * @param string $user_message    The user's last message.
     * @param array  $conversation    Conversation data.
     * @param array  $plan_result     Plan result with goal/field info.
     * @return string|null            Natural prompt, or null on failure (falls back to static).
     */
    private function compose_natural_ask_prompt( $static_prompt, $choices_text, $user_message, $conversation, $plan_result ) {
        $goal_label = $conversation['goal_label'] ?? ( $plan_result['goal'] ?? '' );
        $ask_field  = $plan_result['ask_field'] ?? '';

        // Get provider system instructions for tone/persona
        $sys_instr = '';
        $goal = $conversation['goal'] ?? ( $plan_result['goal'] ?? '' );
        if ( $goal ) {
            $registry = BizCity_Intent_Provider_Registry::instance();
            $provider = $registry->get_provider_for_goal( $goal );
            if ( $provider ) {
                $sys_instr = $provider->get_system_instructions( $goal );
            }
        }

        // Build filled slots summary for acknowledgment
        $filled_summary = '';
        $tool_slots = $plan_result['tool_slots'] ?? [];
        if ( ! empty( $tool_slots ) ) {
            $filled_items = [];
            foreach ( $tool_slots as $f => $v ) {
                if ( $v === '' || $v === null || str_starts_with( $f, '_' ) ) continue;
                if ( in_array( $f, [ 'session_id', 'user_id', 'platform' ], true ) ) continue;
                $display = is_array( $v ) ? wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) : mb_substr( (string) $v, 0, 60, 'UTF-8' );
                $filled_items[] = "{$f}={$display}";
            }
            if ( $filled_items ) {
                $filled_summary = implode( ', ', $filled_items );
            }
        }

        // Build a mini system prompt for the fast LLM
        $system = "Bạn là trợ lý AI thân thiện, tiếng Việt. "
                . "Nhiệm vụ: Viết 1 câu xác nhận ngắn thông tin vừa nhận + hỏi thông tin tiếp theo.\n\n"
                . "QUY TẮC:\n"
                . "- Viết dạng hội thoại tự nhiên (không phải form/template cứng)\n"
                . "- Tối đa 2-3 câu, ngắn gọn, dùng emoji phù hợp\n"
                . "- CÂU ĐẦU: Xác nhận/ghi nhận thông tin user vừa cung cấp (ví dụ: 'OK, chủ đề là X nhé!')\n"
                . "- CÂU SAU: Hỏi thông tin tiếp theo cần thiết\n"
                . "- CHỈ trả về câu trả lời, KHÔNG giải thích thêm\n";

        if ( $sys_instr ) {
            // Extract just the persona/tone info (first 200 chars)
            $persona = mb_substr( $sys_instr, 0, 200, 'UTF-8' );
            $system .= "\nPhong cách: {$persona}\n";
        }

        $user_prompt = "User đã nói: \"{$user_message}\"\n"
                     . "Mục tiêu: {$goal_label}\n";
        if ( $filled_summary ) {
            $user_prompt .= "Thông tin đã thu thập: {$filled_summary}\n";
        }
        $user_prompt .= "Cần hỏi tiếp: {$ask_field}\n"
                     . "Gợi ý câu hỏi gốc: {$static_prompt}";

        if ( $choices_text ) {
            $user_prompt .= "\nCác lựa chọn: {$choices_text}";
        }

        // Use a fast, cheap model — avoid thinking models (2.5-pro) whose
        // reasoning tokens consume the max_tokens budget and truncate output.
        $ai = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
            [
                'model'      => 'google/gemini-2.5-flash',
                'purpose'    => 'planner',
                'max_tokens' => 500,
            ]
        );

        if ( ! empty( $ai['success'] ) && ! empty( $ai['message'] ) ) {
            $natural = trim( $ai['message'] );
            // Sanity check: must be reasonable length
            if ( mb_strlen( $natural ) > 10 && mb_strlen( $natural ) < 500 ) {
                return $natural;
            }
        }

        return null; // Fallback to static
    }

    /**
     * Smooth tool-driven ask prompt with emotional context.
     *
     * When tools return missing_fields prompts (like "Tên sản phẩm là gì?"),
     * this function wraps them with emotional acknowledgment so the question
     * doesn't feel jarring after emotional conversation context.
     *
     * Example:
     *   - User said: "mãi ko đóng gói được hệ thống" (frustrated)
     *   - Raw prompt: "Tên sản phẩm là gì? 📦"
     *   - Smoothed: "Mình hiểu, việc đóng gói hệ thống đôi khi rất áp lực.
     *     Để mình hỗ trợ bạn tốt hơn, bạn có thể cho mình biết tên sản phẩm không? 📦"
     *
     * @param string $raw_prompt     Original tool prompt (e.g. "Tên sản phẩm là gì?")
     * @param string $missing_field  Field name being requested
     * @param string $user_message   User's last message (for context)
     * @param array  $conversation   Conversation data with recent turns
     * @param int    $intensity      Emotional intensity level (1-5)
     * @param string $mode           Current mode (execution, emotion, etc.)
     * @return string|null           Smoothed prompt, or null on failure
     */
    private function smooth_tool_ask_prompt( $raw_prompt, $missing_field, $user_message, $session_id, $intensity, $mode, $intent_conversation_id = '' ) {
        // Get recent WEBCHAT messages scoped to the intent conversation (HIL focus)
        // Falls back to session-wide if intent_conversation_id is empty (pre-migration)
        $recent_context = '';
        
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            $wc_db = BizCity_WebChat_Database::instance();
            $messages = $wc_db->get_recent_messages_by_intent_conversation( $intent_conversation_id, $session_id, 6 );
            foreach ( $messages as $msg ) {
                $role = ( $msg->message_from === 'user' ) ? 'User' : 'AI';
                $text = trim( $msg->message_text );
                if ( $text ) {
                    $recent_context .= "{$role}: \"" . mb_substr( $text, 0, 150, 'UTF-8' ) . "\"\n";
                }
            }
        }

        // Determine emotional tone instruction based on intensity + context
        $tone_instruction = '';
        if ( $intensity >= 4 ) {
            $tone_instruction = "User đang RẤT áp lực/khó khăn. Hãy thể hiện sự đồng cảm sâu sắc trước, sau đó mới hỏi.\n";
        } elseif ( $intensity >= 3 ) {
            $tone_instruction = "User có vẻ đang gặp khó khăn. Hãy nhẹ nhàng thừa nhận điều đó rồi hỏi.\n";
        } elseif ( $intensity >= 2 ) {
            $tone_instruction = "User đang làm việc/bận rộn. Hãy giữ câu hỏi tự nhiên, không quá máy móc.\n";
        } else {
            // intensity=1 but check if prior context has emotional exchange
            // Keywords suggesting prior empathetic AI response
            $empathy_markers = [ 'hiểu', 'nặng', 'áp lực', 'khó khăn', 'cảm giác', 'chia sẻ', 'đồng cảm' ];
            foreach ( $empathy_markers as $marker ) {
                if ( mb_stripos( $recent_context, $marker ) !== false ) {
                    $tone_instruction = "Trước đó AI đã thể hiện sự đồng cảm. Hãy giữ mạch nhẹ nhàng khi hỏi tiếp.\n";
                    break;
                }
            }
        }

        // Build system prompt
        $system = "Bạn là trợ lý AI thân thiện, tiếng Việt.\n"
                . "Nhiệm vụ: Viết lại câu hỏi dưới đây sao cho TỰ NHIÊN hơn, GIỮ được mạch cảm xúc với đoạn hội thoại trước.\n\n"
                . "QUY TẮC:\n"
                . "- Viết ngắn gọn (2-3 câu tối đa)\n"
                . "- Nếu user đang chia sẻ khó khăn → thừa nhận ngắn gọn rồi mới hỏi\n"
                . "- KHÔNG nói dài dòng, KHÔNG giải thích quá nhiều\n"
                . "- VẪN phải hỏi thông tin cần thiết (không bỏ qua câu hỏi)\n"
                . "- Giữ emoji nếu có trong câu gốc\n"
                . "- CHỈ trả về câu hỏi đã rewrite, không giải thích\n\n"
                . $tone_instruction;

        $user_prompt = "## Ngữ cảnh gần đây:\n{$recent_context}\n"
                     . "## User vừa nói:\n\"{$user_message}\"\n\n"
                     . "## Câu hỏi gốc (cần rewrite):\n\"{$raw_prompt}\"\n\n"
                     . "## Thông tin cần hỏi: {$missing_field}\n\n"
                     . "Hãy viết lại câu hỏi trên sao cho tự nhiên, giữ mạch cảm xúc với context:";

        // Fast LLM call — avoid thinking models whose reasoning tokens eat max_tokens
        $ai = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
            [
                'model'      => 'google/gemini-2.5-flash',
                'purpose'    => 'planner',
                'max_tokens' => 500,
            ]
        );

        if ( ! empty( $ai['success'] ) && ! empty( $ai['message'] ) ) {
            $smoothed = trim( $ai['message'] );
            // Remove any quotes if LLM wrapped in quotes
            $smoothed = trim( $smoothed, '"\'""' );
            // Sanity check
            if ( mb_strlen( $smoothed ) > 10 && mb_strlen( $smoothed ) < 500 ) {
                // Log for debug
                if ( class_exists( 'BizCity_User_Memory' ) ) {
                    BizCity_User_Memory::log_router_event( [
                        'step'             => 'emotional_smooth',
                        'message'          => mb_substr( $user_message, 0, 100, 'UTF-8' ),
                        'mode'             => $mode,
                        'intensity'        => $intensity,
                        'raw_prompt'       => $raw_prompt,
                        'smoothed_prompt'  => $smoothed,
                        'context_preview'  => mb_substr( $recent_context, 0, 200, 'UTF-8' ),
                        'functions_called' => 'smooth_tool_ask_prompt()',
                        'response_preview' => 'raw=' . mb_strlen( $raw_prompt ) . 'chars → smoothed=' . mb_strlen( $smoothed ) . 'chars',
                    ], $session_id );
                }
                return $smoothed;
            }
        }

        // Log failure for debugging
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'emotional_smooth_fail',
                'message'          => mb_substr( $user_message, 0, 100, 'UTF-8' ),
                'mode'             => $mode,
                'intensity'        => $intensity,
                'raw_prompt'       => $raw_prompt,
                'context_len'      => mb_strlen( $recent_context ),
                'llm_success'      => ! empty( $ai['success'] ),
                'llm_error'        => $ai['error'] ?? '',
                'functions_called' => 'smooth_tool_ask_prompt() → FAIL',
                'response_preview' => 'fallback to raw prompt',
            ], $session_id );
        }

        return null; // Fall back to raw prompt
    }

    /**
     * Enrich slot value from session context when user message is just a command.
     *
     * When user says "ok, dùng công cụ X đi" — that's a navigation command, not
     * content for the tool. This method detects such commands and pulls the REAL
     * user intent from earlier session messages.
     *
     * @since v4.4.0
     *
     * @param string $message     Current user message.
     * @param string $session_id  Webchat session UUID.
     * @param string $conv_id     Current intent conversation ID.
     * @return string  Enriched message (with session context) or original message.
     */
    private function enrich_slot_from_session( $message, $session_id, $conv_id ) {
        // Quick check: is this message substantive or just a navigation command?
        // Navigation commands: short messages with tool-selection keywords but no real question/content.
        $msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
        $msg_len   = mb_strlen( $msg_lower, 'UTF-8' );

        // If message is long enough (>60 chars) → likely contains real content, use as-is
        if ( $msg_len > 60 ) {
            return $message;
        }

        // Detect tool-navigation patterns
        $is_command = (bool) preg_match(
            '/^(ok|okay|okie|ừ|ờ|được|rồi|vâng|yes|yep|dạ|oke|oki)[,.\s]*/ui',
            $msg_lower
        ) && preg_match(
            '/\b(dùng|sử dụng|dùng công cụ|dùng tool|chuyển sang|mở|gọi|chạy|thử|cho tôi|chọn|đi|nhé)\b/ui',
            $msg_lower
        );

        if ( ! $is_command ) {
            return $message;
        }

        // ── Strategy 1: Rolling Memory — preferred source ──
        if ( class_exists( 'BizCity_Rolling_Memory' ) ) {
            $user_id = get_current_user_id();
            if ( $user_id ) {
                $rm_intent = BizCity_Rolling_Memory::instance()->get_recent_user_intent( $user_id, $session_id );
                if ( ! empty( $rm_intent ) ) {
                    error_log( '[INTENT-ENGINE] enrich_slot_from_session: command="'
                        . mb_substr( $message, 0, 50, 'UTF-8' ) . '" → enriched from Rolling Memory' );
                    return $rm_intent;
                }
            }
        }

        // ── Strategy 2: Fallback to raw webchat messages ──
        if ( ! class_exists( 'BizCity_WebChat_Database' ) || empty( $session_id ) ) {
            return $message;
        }

        $wc_db = BizCity_WebChat_Database::instance();

        // Get recent user messages from this session (excluding the current command)
        $recent = $wc_db->get_recent_messages_by_intent_conversation( '', $session_id, 10 );
        if ( empty( $recent ) || ! is_array( $recent ) ) {
            return $message;
        }

        // Find substantive user messages (the original request + refinements)
        $user_intent_parts = [];
        foreach ( $recent as $msg ) {
            $from = $msg->message_from ?? ( $msg->from ?? '' );
            $text = trim( $msg->message_text ?? ( $msg->text ?? '' ) );

            if ( $from !== 'user' || empty( $text ) ) continue;
            if ( $text === $message ) continue; // Skip current message

            // Skip short acknowledgments
            if ( mb_strlen( $text, 'UTF-8' ) < 5 ) continue;
            if ( preg_match( '/^(ok|ừ|vâng|dạ|được|rồi|yes|👍|👌)$/ui', trim( $text ) ) ) continue;

            $user_intent_parts[] = $text;
        }

        if ( empty( $user_intent_parts ) ) {
            return $message;
        }

        // Build enriched slot: combine original intent with session context
        // Take at most 3 user messages for context
        $context_parts = array_slice( $user_intent_parts, 0, 3 );
        $enriched = implode( "\n", $context_parts );

        error_log( '[INTENT-ENGINE] enrich_slot_from_session: command="'
            . mb_substr( $message, 0, 50, 'UTF-8' ) . '" → enriched with '
            . count( $context_parts ) . ' session message(s) (fallback)' );

        return $enriched;
    }

    /**
     * Extract substantive content after stripping the goal trigger pattern.
     *
     * When a message like "ghi bữa ăn phở bò" matches goal pattern "ghi bữa ăn",
     * this strips the trigger portion and returns the remaining content ("phở bò").
     * Returns empty string if only trigger/filler words remain (e.g. "ghi bữa ăn nhé").
     *
     * @since v4.6.1
     * @param string $message Raw user message.
     * @param array  $intent  Classified intent with _debug data.
     * @return string Extracted content or '' if nothing substantive.
     */
    private function extract_content_after_trigger( $message, $intent ) {
        $matched_pattern = $intent['_debug']['matched_pattern'] ?? '';
        if ( empty( $matched_pattern ) ) {
            return '';
        }

        // Strip the goal trigger pattern from the message
        $stripped = @preg_replace( $matched_pattern, '', $message );
        if ( $stripped === null || $stripped === $message ) {
            return '';
        }

        // Remove common Vietnamese filler/transition/action words
        // v4.7.1: Added action words (lên, rồi, web, facebook, sang, qua, xong)
        // to prevent command text "lên web rồi đăng facebook" from surviving as content
        $stripped = preg_replace(
            '/\b(nhé|nha|đi|ạ|nhá|ha|hé|hen|nào|với|hãy|giúp|hộ|luôn|liền|lên|rồi|xong|sang|qua|cho|từ|vào|ra|thêm|web|website|facebook|fb|zalo|tiktok|instagram|email|đăng|viết|tạo|gửi|chạy|làm|bài|bản)\b/ui',
            '', $stripped
        );
        $stripped = trim( preg_replace( '/\s+/u', ' ', $stripped ) );

        // Minimum 4 chars to be considered substantive content
        // (prevents single leftover words like "rồi" from passing)
        if ( mb_strlen( $stripped, 'UTF-8' ) < 4 ) {
            return '';
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[INTENT-ENGINE] extract_content_after_trigger: "'
                     . mb_substr( $message, 0, 60, 'UTF-8' ) . '" → "' . $stripped . '"' );
        }

        return $stripped;
    }

    /**
     * Generate a goodbye message based on conversation context.
     *
     * @param array $conversation
     * @return string
     */
    private function get_goodbye_message( array $conversation ) {
        $goal = $conversation['goal'] ?? '';

        if ( ! empty( $goal ) ) {
            $label = $conversation['goal_label'] ?? $goal;
            return "✅ Đã hoàn thành \"{$label}\". Cảm ơn bạn! Nếu cần gì thêm, hãy nhắn cho tôi nhé! 😊";
        }

        return 'Cảm ơn bạn đã trò chuyện! Hẹn gặp lại! 👋';
    }

    /**
     * Periodically clean up expired conversations.
     * Runs max once per hour via transient lock.
     */
    public function maybe_cleanup() {
        $lock_key = 'bizcity_intent_cleanup_lock';
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

        BizCity_Intent_Database::instance()->expire_stale();
    }

    /* ================================================================
     *  AJAX endpoints — conversation list & turns for dashboard
     * ================================================================ */

    /**
     * Boot AJAX hooks for conversation listing.
     * Called from constructor.
     */
    private function register_ajax_endpoints() {
        add_action( 'wp_ajax_bizcity_intent_conversations', [ $this, 'ajax_list_conversations' ] );
        add_action( 'wp_ajax_bizcity_intent_turns',         [ $this, 'ajax_get_turns' ] );
        add_action( 'wp_ajax_bizcity_intent_close_all',     [ $this, 'ajax_close_all' ] );
        add_action( 'wp_ajax_bizcity_intent_cancel',        [ $this, 'ajax_cancel_conversation' ] );
        add_action( 'wp_ajax_bizcity_intent_complete',      [ $this, 'ajax_complete_conversation' ] );

        // Project / Webchat session endpoints are handled by BizCity_WebChat_Ajax_Handlers
        // (bizcity-bot-webchat plugin). Only register project_move_conv here as it's
        // specific to the intent engine.
        add_action( 'wp_ajax_bizcity_project_move_conv', [ $this, 'ajax_project_move_conv' ] );
    }

    /**
     * AJAX: list conversations for current user.
     */
    public function ajax_list_conversations() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $user_id    = get_current_user_id();
        $channel    = sanitize_text_field( $_REQUEST['channel'] ?? 'adminchat' );
        // Note: session_id NOT required when user_id > 0 — list ALL user's intents across sessions
        $session_id = '';
        $project_id = isset( $_REQUEST['project_id'] ) ? sanitize_text_field( $_REQUEST['project_id'] ) : null;

        $db   = BizCity_Intent_Database::instance();
        $rows = $db->get_conversations_for_user( $user_id, $channel, $session_id, 30, $project_id );

        $convs = [];
        foreach ( $rows as $row ) {
            // Skip conversations without goals (chitchat/knowledge mode)
            if ( empty( $row->goal ) ) {
                continue;
            }

            // Use goal_label first, fallback to goal
            $title = ! empty( $row->goal_label ) ? $row->goal_label : $row->goal;
            
            // Truncate
            if ( mb_strlen( $title ) > 50 ) {
                $title = mb_substr( $title, 0, 47 ) . '...';
            }

            $convs[] = [
                'id'         => $row->conversation_id,
                'session_id' => $row->session_id ?? '',
                'title'      => $title,
                'goal'       => $row->goal,
                'status'     => $row->status,
                'turns'      => (int) $row->turn_count,
                'project_id' => $row->project_id ?? '',
                'created'    => $row->created_at,
                'updated'    => $row->last_activity_at,
            ];
        }

        wp_send_json_success( $convs );
    }

    /**
     * AJAX: get turns for a specific conversation.
     */
    public function ajax_get_turns() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $conversation_id = sanitize_text_field( $_REQUEST['conversation_id'] ?? '' );
        if ( empty( $conversation_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing conversation_id' ] );
        }

        // Security: verify user owns this conversation
        $db   = BizCity_Intent_Database::instance();
        $conv = $db->get_conversation( $conversation_id );
        if ( ! $conv ) {
            wp_send_json_error( [ 'message' => 'Conversation not found' ] );
        }

        $user_id = get_current_user_id();
        if ( (int) $conv->user_id !== $user_id && $user_id > 0 ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
        }

        $turns = $this->conversation_mgr->get_turns( $conversation_id, 100 );

        // Return conversation meta + turns
        wp_send_json_success( [
            'conversation_id' => $conversation_id,
            'goal'            => $conv->goal,
            'goal_label'      => $conv->goal_label,
            'status'          => $conv->status,
            'turns'           => $turns,
        ] );
    }

    /**
     * AJAX: close all active conversations for current user.
     */
    public function ajax_close_all() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $user_id    = get_current_user_id();
        $channel    = sanitize_text_field( $_REQUEST['channel'] ?? 'adminchat' );
        $session_id = sanitize_text_field( $_REQUEST['session_id'] ?? '' );

        $db    = BizCity_Intent_Database::instance();
        $count = $db->close_all_for_user( $user_id, $channel, $session_id );

        wp_send_json_success( [ 'closed' => $count ] );
    }

    /**
     * AJAX: Cancel a specific intent conversation (user clicked X / close button).
     * Marks the conversation as CANCELLED so HIL loop exits and
     * "Nhiệm vụ" column reflects user-initiated abort.
     */
    public function ajax_cancel_conversation() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        $valid = wp_verify_nonce( $nonce, 'bizcity_webchat' )
              || wp_verify_nonce( $nonce, 'bizcity_admin_chat' )
              || wp_verify_nonce( $nonce, 'bizcity_chat' );
        if ( ! $valid ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $conversation_id = sanitize_text_field( $_REQUEST['conversation_id'] ?? '' );
        if ( empty( $conversation_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing conversation_id' ] );
        }

        $this->conversation_mgr->cancel( $conversation_id, 'user_cancel' );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[INTENT-ENGINE] User cancelled conversation: ' . $conversation_id );
        }

        wp_send_json_success( [
            'cancelled'       => true,
            'conversation_id' => $conversation_id,
        ] );
    }

    /**
     * AJAX: Mark a specific intent conversation as COMPLETED (user clicked ✓ button).
     */
    public function ajax_complete_conversation() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        $valid = wp_verify_nonce( $nonce, 'bizcity_webchat' )
              || wp_verify_nonce( $nonce, 'bizcity_admin_chat' )
              || wp_verify_nonce( $nonce, 'bizcity_chat' );
        if ( ! $valid ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $conversation_id = sanitize_text_field( $_REQUEST['conversation_id'] ?? '' );
        if ( empty( $conversation_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing conversation_id' ] );
        }

        $this->conversation_mgr->complete( $conversation_id, 'User marked as completed' );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[INTENT-ENGINE] User completed conversation: ' . $conversation_id );
        }

        wp_send_json_success( [
            'completed'       => true,
            'conversation_id' => $conversation_id,
        ] );
    }

    /* ================================================================
     *  AJAX endpoints — Projects (ChatGPT-style folders)
     * ================================================================ */

    /**
     * Get the user meta key for projects storage.
     */
    private function _projects_meta_key() {
        return 'bizcity_projects';
    }

    /**
     * Ensure V3 webchat tables exist (projects, sessions).
     * This is a fallback in case migration didn't run.
     */
    private function ensure_v3_tables( $wc_db ) {
        static $checked = false;
        if ( $checked ) return;
        $checked = true;

        global $wpdb;
        $projects_table = $wpdb->prefix . 'bizcity_webchat_projects';
        $sessions_table = $wpdb->prefix . 'bizcity_webchat_sessions';

        // Quick check — if either V3 table doesn't exist, run create_tables()
        $projects_exists = $wpdb->get_var( "SHOW TABLES LIKE '$projects_table'" );
        $sessions_exists = $wpdb->get_var( "SHOW TABLES LIKE '$sessions_table'" );

        if ( $projects_exists !== $projects_table || $sessions_exists !== $sessions_table ) {
            if ( method_exists( $wc_db, 'create_tables' ) ) {
                $wc_db->create_tables();
            }
        }
    }

    /**
     * AJAX: list all projects for current user.
     */
    public function ajax_project_list() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $user_id  = get_current_user_id();
        $projects = get_user_meta( $user_id, $this->_projects_meta_key(), true );
        if ( ! is_array( $projects ) ) {
            $projects = [];
        }

        // Count webchat sessions per project
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            $wc_db = BizCity_WebChat_Database::instance();
            foreach ( $projects as &$p ) {
                $rows = $wc_db->get_sessions_for_user( $user_id, 'ADMINCHAT', 100, $p['id'] );
                $p['conv_count'] = count( $rows );
            }
            unset( $p );
        }

        wp_send_json_success( $projects );
    }

    /**
     * AJAX: create a new project.
     */
    public function ajax_project_create() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $user_id = get_current_user_id();
        $name    = sanitize_text_field( $_REQUEST['name'] ?? '' );
        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Project name is required' ] );
        }

        $projects = get_user_meta( $user_id, $this->_projects_meta_key(), true );
        if ( ! is_array( $projects ) ) {
            $projects = [];
        }

        $new_project = [
            'id'      => 'proj_' . wp_generate_uuid4(),
            'name'    => $name,
            'icon'    => sanitize_text_field( $_REQUEST['icon'] ?? '📁' ),
            'created' => current_time( 'mysql' ),
        ];

        $projects[] = $new_project;
        update_user_meta( $user_id, $this->_projects_meta_key(), $projects );

        wp_send_json_success( $new_project );
    }

    /**
     * AJAX: rename a project.
     */
    public function ajax_project_rename() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $user_id    = get_current_user_id();
        $project_id = sanitize_text_field( $_REQUEST['project_id'] ?? '' );
        $new_name   = sanitize_text_field( $_REQUEST['name'] ?? '' );

        if ( empty( $project_id ) || empty( $new_name ) ) {
            wp_send_json_error( [ 'message' => 'Missing parameters' ] );
        }

        $projects = get_user_meta( $user_id, $this->_projects_meta_key(), true );
        if ( ! is_array( $projects ) ) {
            wp_send_json_error( [ 'message' => 'No projects found' ] );
        }

        $found = false;
        foreach ( $projects as &$p ) {
            if ( $p['id'] === $project_id ) {
                $p['name'] = $new_name;
                if ( isset( $_REQUEST['icon'] ) ) {
                    $p['icon'] = sanitize_text_field( $_REQUEST['icon'] );
                }
                $found = true;
                break;
            }
        }
        unset( $p );

        if ( ! $found ) {
            wp_send_json_error( [ 'message' => 'Project not found' ] );
        }

        update_user_meta( $user_id, $this->_projects_meta_key(), $projects );
        wp_send_json_success( [ 'ok' => true ] );
    }

    /**
     * AJAX: delete a project (conversations become unassigned).
     */
    public function ajax_project_delete() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $user_id    = get_current_user_id();
        $project_id = sanitize_text_field( $_REQUEST['project_id'] ?? '' );

        if ( empty( $project_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing project_id' ] );
        }

        $projects = get_user_meta( $user_id, $this->_projects_meta_key(), true );
        if ( ! is_array( $projects ) ) {
            wp_send_json_error( [ 'message' => 'No projects found' ] );
        }

        $projects = array_values( array_filter( $projects, function( $p ) use ( $project_id ) {
            return $p['id'] !== $project_id;
        } ) );

        update_user_meta( $user_id, $this->_projects_meta_key(), $projects );

        // Unassign webchat sessions from deleted project
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'bizcity_webchat_conversations';
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET project_id = '' WHERE project_id = %s AND user_id = %d",
                $project_id,
                $user_id
            ) );
        }

        wp_send_json_success( [ 'ok' => true ] );
    }

    /**
     * AJAX: move a conversation (legacy) into/out of a project.
     */
    public function ajax_project_move_conv() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $conversation_id = sanitize_text_field( $_REQUEST['conversation_id'] ?? '' );
        $project_id      = sanitize_text_field( $_REQUEST['project_id'] ?? '' );

        if ( empty( $conversation_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing conversation_id' ] );
        }

        // Security: verify user owns this conversation
        $db   = BizCity_Intent_Database::instance();
        $conv = $db->get_conversation( $conversation_id );
        if ( ! $conv || (int) $conv->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
        }

        $db->update_conversation_project( $conversation_id, $project_id );
        wp_send_json_success( [ 'ok' => true ] );
    }

    /* ================================================================
     *  AJAX endpoints — Webchat Sessions (v3.0.0)
     * ================================================================ */

    /**
     * AJAX: list webchat sessions for current user.
     */
    public function ajax_webchat_sessions() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $user_id    = get_current_user_id();
        $project_id = isset( $_REQUEST['project_id'] ) ? sanitize_text_field( $_REQUEST['project_id'] ) : null;

        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            wp_send_json_success( [] );
        }

        $wc_db = BizCity_WebChat_Database::instance();

        // Ensure V3 tables exist before querying
        $this->ensure_v3_tables( $wc_db );

        // V3: Use get_sessions_v3_for_user if available
        if ( method_exists( $wc_db, 'get_sessions_v3_for_user' ) ) {
            $rows = $wc_db->get_sessions_v3_for_user( $user_id, 'ADMINCHAT', 50, $project_id );
        } else {
            $rows = $wc_db->get_sessions_for_user( $user_id, 'ADMINCHAT', 30, $project_id );
        }

        $sessions = [];
        foreach ( $rows as $row ) {
            $title = $row->title;
            if ( empty( $title ) ) {
                $title = method_exists( $wc_db, 'get_first_user_message_in_session' )
                    ? $wc_db->get_first_user_message_in_session( (int) $row->id )
                    : '';
            }
            if ( empty( $title ) ) {
                $title = 'Hội thoại mới';
            }
            if ( mb_strlen( $title ) > 60 ) {
                $title = mb_substr( $title, 0, 57 ) . '...';
            }

            $sessions[] = [
                'id'            => (int) $row->id,
                'session_id'    => $row->session_id,
                'title'         => $title,
                'project_id'    => $row->project_id ?? '',
                'message_count' => (int) ( $row->message_count ?? 0 ),
                'last_message'  => $row->last_message_preview ?? '',
                'status'        => $row->status,
                'started_at'    => $row->started_at,
                'last_activity' => $row->last_message_at ?? $row->started_at,
            ];
        }

        // Log to Router Console
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'session_list',
                'message'          => 'Loaded webchat sessions',
                'mode'             => 'webchat_crud',
                'functions_called' => 'ajax_webchat_sessions()',
                'file_line'        => 'class-intent-engine.php',
                'user_id'          => $user_id,
                'project_filter'   => $project_id ?: '(all)',
                'sessions_count'   => count( $sessions ),
                'status'           => 'success',
            ] );
        }

        wp_send_json_success( $sessions );
    }

    /**
     * AJAX: create a new webchat session.
     */
    public function ajax_webchat_session_create() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            wp_send_json_error( [ 'message' => 'WebChat DB not available' ] );
        }

        $user_id    = get_current_user_id();
        $user       = wp_get_current_user();
        $name       = $user->display_name ?: $user->user_login;
        $project_id = sanitize_text_field( $_POST['project_id'] ?? '' );
        $title      = sanitize_text_field( $_POST['title'] ?? '' );

        $wc_db = BizCity_WebChat_Database::instance();

        // Ensure V3 tables exist before inserting
        $this->ensure_v3_tables( $wc_db );

        // V3: Use create_session_v3 if available (supports multiple sessions per user)
        if ( method_exists( $wc_db, 'create_session_v3' ) ) {
            $session = $wc_db->create_session_v3( $user_id, $name, 'ADMINCHAT', $title, [
                'project_id' => $project_id,
            ] );
        } else {
            $session = $wc_db->create_session( $user_id, $name, 'ADMINCHAT', $title );
        }

        // Log to Router Console
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'session_create',
                'message'          => 'New webchat session created',
                'mode'             => 'webchat_crud',
                'functions_called' => 'ajax_webchat_session_create()',
                'file_line'        => 'class-intent-engine.php',
                'user_id'          => $user_id,
                'session_pk'       => $session['id'] ?? 0,
                'session_uuid'     => $session['session_id'] ?? '',
                'title'            => $title ?: '(auto)',
                'project_id'       => $project_id ?: '(none)',
                'status'           => 'success',
            ], $session['session_id'] ?? '' );
        }

        wp_send_json_success( $session );
    }

    /**
     * AJAX: rename a webchat session.
     */
    public function ajax_webchat_session_rename() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $session_pk = intval( $_REQUEST['session_id'] ?? 0 );
        $title      = sanitize_text_field( $_REQUEST['title'] ?? '' );

        if ( ! $session_pk || ! $title ) {
            wp_send_json_error( [ 'message' => 'Missing params' ] );
        }

        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            wp_send_json_error( [ 'message' => 'WebChat DB not available' ] );
        }

        $wc_db = BizCity_WebChat_Database::instance();

        // V3: Use get_session_v3 if available
        $row = method_exists( $wc_db, 'get_session_v3' ) ? $wc_db->get_session_v3( $session_pk ) : $wc_db->get_session( $session_pk );
        if ( ! $row || (int) $row->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
        }

        $old_title = $row->title ?? '';

        // V3: Use update_session_v3 if available
        if ( method_exists( $wc_db, 'update_session_v3' ) ) {
            $wc_db->update_session_v3( $session_pk, [ 'title' => $title, 'title_generated' => 0 ] );
        } else {
            $wc_db->update_session_title( $session_pk, $title );
        }

        // Log to Router Console
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'session_rename',
                'message'          => 'Session renamed',
                'mode'             => 'webchat_crud',
                'functions_called' => 'ajax_webchat_session_rename()',
                'file_line'        => 'class-intent-engine.php',
                'session_pk'       => $session_pk,
                'session_uuid'     => $row->session_id ?? '',
                'old_title'        => $old_title,
                'new_title'        => $title,
                'status'           => 'success',
            ], $row->session_id ?? '' );
        }

        wp_send_json_success( [ 'ok' => true ] );
    }

    /**
     * AJAX: delete a webchat session.
     */
    public function ajax_webchat_session_delete() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $session_pk = intval( $_REQUEST['session_id'] ?? 0 );
        if ( ! $session_pk ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
        }

        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            wp_send_json_error( [ 'message' => 'WebChat DB not available' ] );
        }

        $wc_db = BizCity_WebChat_Database::instance();

        // V3: Use get_session_v3 if available
        $row = method_exists( $wc_db, 'get_session_v3' ) ? $wc_db->get_session_v3( $session_pk ) : $wc_db->get_session( $session_pk );
        if ( ! $row || (int) $row->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
        }

        $session_uuid  = $row->session_id ?? '';
        $session_title = $row->title ?? '';

        // V3: Use delete_session_v3 if available
        if ( method_exists( $wc_db, 'delete_session_v3' ) ) {
            $wc_db->delete_session_v3( $session_pk );
        } else {
            $wc_db->delete_session( $session_pk );
        }

        // Log to Router Console
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'session_delete',
                'message'          => 'Session deleted',
                'mode'             => 'webchat_crud',
                'functions_called' => 'ajax_webchat_session_delete()',
                'file_line'        => 'class-intent-engine.php',
                'session_pk'       => $session_pk,
                'session_uuid'     => $session_uuid,
                'session_title'    => $session_title,
                'status'           => 'success',
            ], $session_uuid );
        }

        wp_send_json_success( [ 'ok' => true ] );
    }

    /**
     * AJAX: get messages for a webchat session.
     */
    public function ajax_webchat_session_messages() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $session_pk = intval( $_REQUEST['session_id'] ?? 0 );
        if ( ! $session_pk ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
        }

        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            wp_send_json_error( [ 'message' => 'WebChat DB not available' ] );
        }

        $wc_db = BizCity_WebChat_Database::instance();

        // V3: Use get_session_v3 if available
        $row = method_exists( $wc_db, 'get_session_v3' ) ? $wc_db->get_session_v3( $session_pk ) : $wc_db->get_session( $session_pk );
        if ( ! $row || (int) $row->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
        }

        $msgs = $wc_db->get_messages_by_conversation_id( $session_pk, 100 );

        $result = [];
        foreach ( $msgs as $m ) {
            $result[] = [
                'id'          => (int) $m->id,
                'from'        => $m->message_from,
                'text'        => $m->message_text,
                'type'        => $m->message_type,
                'attachments' => $m->attachments ? json_decode( $m->attachments, true ) : [],
                'created_at'  => $m->created_at,
            ];
        }

        wp_send_json_success( [
            'session_id' => $row->session_id,
            'title'      => $row->title,
            'messages'   => $result,
        ] );
    }

    /**
     * AJAX: move a webchat session into/out of a project.
     */
    public function ajax_webchat_session_move() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $session_pk = intval( $_REQUEST['session_id'] ?? 0 );
        $project_id = sanitize_text_field( $_REQUEST['project_id'] ?? '' );

        if ( ! $session_pk ) {
            wp_send_json_error( [ 'message' => 'Missing session_id' ] );
        }

        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            wp_send_json_error( [ 'message' => 'WebChat DB not available' ] );
        }

        $wc_db = BizCity_WebChat_Database::instance();

        // V3: Use get_session_v3 if available
        $row = method_exists( $wc_db, 'get_session_v3' ) ? $wc_db->get_session_v3( $session_pk ) : $wc_db->get_session( $session_pk );
        if ( ! $row || (int) $row->user_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
        }

        $old_project_id = $row->project_id ?? '';

        // V3: Use move_session_to_project if available
        if ( method_exists( $wc_db, 'move_session_to_project' ) ) {
            $wc_db->move_session_to_project( $session_pk, $project_id );
        } else {
            $wc_db->update_session_project( $session_pk, $project_id );
        }

        // Log to Router Console
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'session_move',
                'message'          => 'Session moved to project',
                'mode'             => 'webchat_crud',
                'functions_called' => 'ajax_webchat_session_move()',
                'file_line'        => 'class-intent-engine.php',
                'session_pk'       => $session_pk,
                'session_uuid'     => $row->session_id ?? '',
                'session_title'    => $row->title ?? '',
                'from_project'     => $old_project_id ?: '(root)',
                'to_project'       => $project_id ?: '(root)',
                'status'           => 'success',
            ], $row->session_id ?? '' );
        }

        wp_send_json_success( [ 'ok' => true ] );
    }

    /**
     * AJAX: close all active webchat sessions.
     */
    public function ajax_webchat_close_all() {
        $nonce = $_REQUEST['_wpnonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'bizcity_webchat' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $user_id = get_current_user_id();

        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            $count = BizCity_WebChat_Database::instance()->close_all_sessions( $user_id, 'ADMINCHAT' );
            wp_send_json_success( [ 'closed' => $count ] );
        }

        wp_send_json_success( [ 'closed' => 0 ] );
    }

    /* ================================================================
     *  Auto-title generation for webchat sessions
     * ================================================================ */

    /**
     * Generate a concise title from the user's first message.
     * Called after first bot reply in a new session.
     *
     * @param string $session_id  The wcs_* session ID.
     */
    public function maybe_auto_title_session( $session_id ) {
        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) return;

        $wc_db = BizCity_WebChat_Database::instance();
        $conv  = $wc_db->get_conversation_by_session( $session_id );
        if ( ! $conv ) return;

        // Skip if already titled or not a wcs_ session
        if ( ! empty( $conv->title ) ) return;
        if ( strpos( $conv->session_id, 'wcs_' ) !== 0 ) return;

        // Get first user message
        $first_msg = $wc_db->get_first_user_message_in_session( (int) $conv->id );
        if ( empty( $first_msg ) ) return;

        // Try LLM-based title (fast, cheap — use smallest model)
        $title = $this->generate_session_title_llm( $first_msg );

        // Fallback: truncate first message
        if ( empty( $title ) ) {
            $title = mb_substr( $first_msg, 0, 50, 'UTF-8' );
            if ( mb_strlen( $first_msg, 'UTF-8' ) > 50 ) {
                $title .= '...';
            }
        }

        $wc_db->update_session_title( (int) $conv->id, $title );
    }

    /**
     * Use LLM to generate a short title from a user message.
     *
     * @param string $message
     * @return string Title or empty on failure.
     */
    private function generate_session_title_llm( $message ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return '';
        }

        try {
            $response = bizcity_openrouter_chat( [
                'model'       => 'google/gemini-2.5-flash',
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'Tạo tiêu đề ngắn (tối đa 8 từ, tiếng Việt) tóm tắt nội dung tin nhắn. Chỉ trả về tiêu đề, không giải thích.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => mb_substr( $message, 0, 300, 'UTF-8' ),
                    ],
                ],
                'max_tokens'  => 30,
                'temperature' => 0.3,
            ] );

            $title = trim( $response['choices'][0]['message']['content'] ?? '' );
            // Remove quotes if wrapped
            $title = trim( $title, '"\'' );
            if ( mb_strlen( $title ) > 60 ) {
                $title = mb_substr( $title, 0, 57 ) . '...';
            }
            return $title;
        } catch ( \Exception $e ) {
            return '';
        }
    }

    /* ================================================================
     *  Public accessors for sub-components
     * ================================================================ */

    /**
     * @return BizCity_Intent_Conversation
     */
    public function conversation() {
        return $this->conversation_mgr;
    }

    /**
     * @return BizCity_Intent_Router
     */
    public function router() {
        return $this->router;
    }

    /**
     * @return BizCity_Intent_Planner
     */
    public function planner() {
        return $this->planner;
    }

    /**
     * @return BizCity_Intent_Tools
     */
    public function tools() {
        return $this->tools;
    }

    /**
     * @return BizCity_Intent_Stream
     */
    public function stream() {
        return $this->stream;
    }

    /**
     * @return BizCity_Mode_Classifier
     */
    public function mode_classifier() {
        return $this->mode_classifier;
    }

    /**
     * @return BizCity_Mode_Pipeline_Registry
     */
    public function pipelines() {
        return $this->pipelines;
    }

    /* ================================================================
     *  Tool suggest confirmation detector (Step 1.7)
     * ================================================================ */

    /**
     * Detect if user's message confirms a previously-suggested tool.
     *
     * Two detection paths:
     * 1. Specific: user mentions a tool name or label → return that tool.
     * 2. Generic: short affirmative message → return top-scored tool.
     *
     * @param string $message          User's current message.
     * @param array  $suggested_tools  Array from _image_text_suggested_tools slot.
     * @return array|false  Matched tool array {tool_name, goal, label, ...} or false.
     * @since v4.3.7
     */
    private function detect_tool_suggest_confirm( $message, $suggested_tools ) {
        $msg     = mb_strtolower( trim( $message ), 'UTF-8' );
        $msg_len = mb_strlen( $msg, 'UTF-8' );

        // Path 1: User mentions a specific tool name or label
        foreach ( $suggested_tools as $tool ) {
            $tool_name_lower = mb_strtolower( $tool['tool_name'] ?? '', 'UTF-8' );
            $label_lower     = mb_strtolower( $tool['label'] ?? '', 'UTF-8' );

            if ( $tool_name_lower && mb_strpos( $msg, $tool_name_lower ) !== false ) {
                return $tool;
            }
            // Label match: only for labels ≥ 4 chars to avoid false positives
            if ( $label_lower && mb_strlen( $label_lower, 'UTF-8' ) >= 4
                 && mb_strpos( $msg, $label_lower ) !== false ) {
                return $tool;
            }
        }

        // Path 2: Generic confirmation — short affirmative message
        if ( $msg_len <= 40 ) {
            $confirm_patterns = [
                '/^(ok|oke|okie|okay|ờ|ừ|uh|uhm|vâng|dạ)\b/ui',
                '/^(có|yes|yeah|yep|yup|sure|right)\b/ui',
                '/^(được|đồng ý|chấp nhận|xác nhận|confirm)\b/ui',
                '/\b(làm đi|tạo đi|chạy đi|thực hiện|bắt đầu|dùng|sử dụng)\b/ui',
                '/\b(làm nhé|tạo nhé|chạy nhé|dùng nhé)\b/ui',
                '/\b(let\'?s go|go|do it|make it|run it|start)\b/ui',
                '/\b(tiếp|tiếp tục|continue|proceed)\b/ui',
            ];

            foreach ( $confirm_patterns as $pattern ) {
                if ( preg_match( $pattern, $msg ) ) {
                    return $suggested_tools[0]; // Top-scored tool
                }
            }
        }

        return false;
    }

    /* ================================================================
     *  Tool suggestion helper (Step 1.5C)
     * ================================================================ */

    /**
     * Find tools from registry that may match the user's text.
     *
     * Uses keyword search against tool goal_label, description, and custom_hints.
     * Returns up to 3 most relevant tools — zero LLM cost.
     *
     * @since v4.3.5
     * @param string $text User message text.
     * @return array [ { tool_name, goal, label, description, plugin, score } ]
     */
    private function find_matching_tools_for_suggest( $text ) {
        if ( ! class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            return [];
        }

        $all_tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
        if ( empty( $all_tools ) ) {
            return [];
        }

        $text_lower = mb_strtolower( $text, 'UTF-8' );
        $scored     = [];

        foreach ( $all_tools as $row ) {
            $score       = 0;
            $goal        = $row['goal'] ?: $row['tool_name'];
            $label       = mb_strtolower( $row['goal_label'] ?: $row['title'] ?: '', 'UTF-8' );
            $desc        = mb_strtolower( $row['goal_description'] ?: $row['description'] ?: '', 'UTF-8' );
            $hints       = mb_strtolower( $row['custom_hints'] ?? '', 'UTF-8' );
            $custom_desc = mb_strtolower( $row['custom_description'] ?? '', 'UTF-8' );
            $tags        = mb_strtolower( $row['intent_tags'] ?? '', 'UTF-8' );

            // Score: keyword overlap between user text and tool metadata
            $search_corpus = $label . ' ' . $desc . ' ' . $hints . ' ' . $custom_desc . ' ' . $tags;

            // Split user text into significant words (>= 2 chars)
            $words = preg_split( '/[\s,.\-!?]+/u', $text_lower );
            $words = array_filter( $words, function( $w ) { return mb_strlen( $w, 'UTF-8' ) >= 2; } );

            foreach ( $words as $word ) {
                if ( mb_strpos( $search_corpus, $word ) !== false ) {
                    $score += 1;
                    // Bonus for hint match (admin-tuned routing keywords)
                    if ( $hints && mb_strpos( $hints, $word ) !== false ) {
                        $score += 2;
                    }
                    // Bonus for label match
                    if ( mb_strpos( $label, $word ) !== false ) {
                        $score += 1;
                    }
                }
            }

            if ( $score > 0 ) {
                $scored[] = [
                    'tool_name'   => $row['tool_name'],
                    'goal'        => $goal,
                    'label'       => $row['goal_label'] ?: $row['title'] ?: $row['tool_name'],
                    'description' => BizCity_Intent_Tool_Index::instance()->get_effective_description( $row ),
                    'plugin'      => $row['plugin'] ?? '',
                    'score'       => $score,
                ];
            }
        }

        // Sort by score descending, take top 3
        usort( $scored, function( $a, $b ) { return $b['score'] <=> $a['score']; } );
        return array_slice( $scored, 0, 3 );
    }

    /* ================================================================
     *  Image URL extraction helper
     * ================================================================ */

    /**
     * Extract image URLs from plain text message.
     *
     * Detects URLs ending in common image extensions (.jpg, .png, .webp, etc.)
     * and WordPress/CDN media patterns. Returns extracted URLs and the remaining
     * text with URLs stripped out.
     *
     * This is NOT an LLM task — it's deterministic URL pattern matching.
     * The LLM handles intent classification; this method only extracts URLs.
     *
     * @param string $text User's raw message.
     * @return array {
     *   @type string[] $urls            Extracted image URLs.
     *   @type string   $remaining_text  Message with image URLs removed.
     * }
     */
    private function extract_image_urls_from_text( $text ) {
        $urls = [];

        // Pattern 1: URLs with explicit image extensions
        // Covers: .jpg, .jpeg, .png, .gif, .webp, .bmp, .svg, .avif, .tiff
        // Also handles query strings (?w=800&h=600) and fragments (#section)
        $ext_pattern = '/https?:\/\/[^\s<>"\']+';
        $ext_pattern .= '\.(?:jpe?g|png|gif|webp|bmp|svg|avif|tiff?)';
        $ext_pattern .= '(?:[?#][^\s<>"\']*)?' ; // optional query/fragment
        $ext_pattern .= '/ui';

        if ( preg_match_all( $ext_pattern, $text, $matches ) ) {
            foreach ( $matches[0] as $url ) {
                $urls[] = rtrim( $url, '.,;)]}' );
            }
        }

        // Pattern 2: WordPress media library URLs (wp-content/uploads/...)
        // These always have image extensions so Pattern 1 covers them,
        // but catch any edge cases with encoded extensions
        $wp_pattern = '/https?:\/\/[^\s<>"\']+';
        $wp_pattern .= 'wp-content\/uploads\/[^\s<>"\']+';
        $wp_pattern .= '/ui';

        if ( preg_match_all( $wp_pattern, $text, $wp_matches ) ) {
            foreach ( $wp_matches[0] as $url ) {
                $clean = rtrim( $url, '.,;)]}' );
                if ( ! in_array( $clean, $urls, true ) ) {
                    $urls[] = $clean;
                }
            }
        }

        // No image URLs found
        if ( empty( $urls ) ) {
            return [
                'urls'           => [],
                'remaining_text' => $text,
            ];
        }

        // Strip extracted URLs from message text
        $remaining = $text;
        foreach ( $urls as $url ) {
            $remaining = str_replace( $url, '', $remaining );
        }

        // Clean up leftover whitespace and common filler words around image links
        $remaining = preg_replace( '/\b(ảnh|hình ảnh|hình|image|link ảnh|đây|nè|này)\b/ui', '', $remaining );
        $remaining = preg_replace( '/\s{2,}/', ' ', trim( $remaining ) );
        $remaining = preg_replace( '/^[,;:.!?\-\s]+|[,;:.!?\-\s]+$/u', '', $remaining );

        return [
            'urls'           => $urls,
            'remaining_text' => $remaining,
        ];
    }

    /**
     * Auto-execute a plan task without requiring user to open builder.
     *
     * Loads the saved scenario from bizcity_tasks, creates an execution state,
     * and runs all nodes via the workflow executor.
     *
     * Phase 1.1 — G10: Auto-execute after plan confirm.
     *
     * @param int    $task_id    Row ID in bizcity_tasks.
     * @param int    $user_id    Owner user ID.
     * @param string $session_id Chat session ID.
     * @param string $channel    Channel (adminchat, webchat, etc.).
     * @param int    $conv_id    Intent conversation ID.
     * @return array { success: bool, execution_id: string, error: string }
     */
    private function auto_execute_plan_task( int $task_id, int $user_id, string $session_id, string $channel, int $conv_id ): array {
        if ( $task_id <= 0 ) {
            return [ 'success' => false, 'execution_id' => '', 'error' => 'Invalid task ID' ];
        }

        global $wpdb;
        $table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';
        $task  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $task_id ) );

        if ( ! $task || empty( $task->params ) ) {
            return [ 'success' => false, 'execution_id' => '', 'error' => 'Task not found or empty' ];
        }

        $params = json_decode( $task->params, true );
        if ( ! is_array( $params ) || empty( $params['nodes'] ) ) {
            return [ 'success' => false, 'execution_id' => '', 'error' => 'Invalid scenario JSON' ];
        }

        $nodes    = $params['nodes'];
        $edges    = $params['edges'] ?? [];
        $settings = $params['settings'] ?? [];

        $pipeline_id  = $settings['pipeline_id'] ?? ( 'pipe_' . $task_id . '_' . time() );
        $execution_id = 'waic_exec_' . $task_id . '_' . time();

        // Build node_step_map (node_id → step_index for action nodes)
        $node_step_map = [];
        $step_idx = 0;
        foreach ( $nodes as $node ) {
            if ( ( $node['type'] ?? '' ) !== 'trigger' ) {
                $node_step_map[ $node['id'] ] = $step_idx;
                $step_idx++;
            }
        }

        // Create execution state (same structure as WaicWorkflowExecuteAPI)
        $execution_state = [
            'execution_id'             => $execution_id,
            'task_id'                  => $task_id,
            'status'                   => 'running',
            'mode'                     => 'test',
            'started_at'               => current_time( 'mysql' ),
            'current_node'             => null,
            'nodes'                    => $nodes,
            'edges'                    => $edges,
            'test_data'                => [],
            'node_status'              => [],
            'variables'                => [],
            'logs'                     => [],
            'error'                    => null,
            'visited_nodes'            => [],
            'pipeline_id'              => $pipeline_id,
            'user_id'                  => $user_id,
            'session_id'               => $session_id,
            'channel'                  => $channel,
            'intent_conversation_id'   => (string) $conv_id,
            'node_step_map'            => $node_step_map,
        ];

        set_transient( $execution_id, $execution_state, 3600 );
        update_option( 'waic_active_execution_' . $task_id, $execution_id );

        // Start one-shot trigger if available
        if ( class_exists( 'BizCity_One_Shot_Trigger' ) ) {
            $oneshot = BizCity_One_Shot_Trigger::instance();
            global $wpdb;
            $os_table = $wpdb->prefix . 'bizcity_pipeline_oneshot';
            $os_row   = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$os_table} WHERE task_id = %d AND state = 'ready' ORDER BY id DESC LIMIT 1",
                $task_id
            ) );
            if ( $os_row ) {
                $oneshot->start( (int) $os_row->id, $execution_id );
            }
        }

        // Execute via WaicWorkflowExecuteAPI background method
        if ( class_exists( 'WaicWorkflowExecuteAPI' ) ) {
            $api = WaicWorkflowExecuteAPI::getInstance();
            $api->executeWorkflowBackground( $execution_id );

            return [
                'success'      => true,
                'execution_id' => $execution_id,
                'error'        => '',
            ];
        }

        return [ 'success' => false, 'execution_id' => $execution_id, 'error' => 'Workflow executor not available' ];
    }

    /**
     * Handle pipeline resume — find execution state and trigger workflow continuation.
     *
     * Phase 1.1d: Resume mechanism. Works in 3 strategies:
     *  1. Transient still alive → call waic_execute_workflow_resume() directly
     *  2. Transient expired but task_id known → rebuild execution state from task + todos → re-execute
     *  3. No task_id → report status only
     *
     * @param array  $active_pipe Result from BizCity_Intent_Todos::find_active_pipeline().
     * @param int    $user_id     Current user.
     * @param string $session_id  Chat session.
     * @param string $channel     Channel.
     * @return string Reply message to send to user.
     */
    private function handle_pipeline_resume( array $active_pipe, int $user_id, string $session_id, string $channel ): string {
        $pipe_id = $active_pipe['pipeline_id'];
        $task_id = ! empty( $active_pipe['task_id'] ) ? (int) $active_pipe['task_id'] : 0;

        error_log( '[INTENT-ENGINE] handle_pipeline_resume: pipeline=' . $pipe_id . ' task_id=' . $task_id );

        // Strategy 1: Find active execution_id from option and check transient
        if ( $task_id > 0 ) {
            $execution_id = get_option( 'waic_active_execution_' . $task_id, '' );

            if ( $execution_id ) {
                $exec_state = get_transient( $execution_id );

                if ( is_array( $exec_state ) ) {
                    if ( ( $exec_state['status'] ?? '' ) === 'waiting' ) {
                        // Transient alive, pipeline is WAITING → resume directly
                        error_log( '[INTENT-ENGINE] Resume: transient alive, status=waiting, calling waic_execute_workflow_resume' );

                        if ( function_exists( 'waic_execute_workflow_resume' ) ) {
                            $resumed = waic_execute_workflow_resume( $execution_id );
                            if ( $resumed ) {
                                return "▶️ Đang tiếp tục kế hoạch từ bước **"
                                     . ( $active_pipe['next_label'] ?: $active_pipe['next_tool'] ) . "**...\n\n"
                                     . "📋 " . BizCity_Intent_Todos::get_formatted_message( $pipe_id );
                            }
                        }
                        return "⚠️ Không thể resume workflow — executor không sẵn sàng.\n\n"
                             . BizCity_Intent_Todos::get_formatted_message( $pipe_id );

                    } elseif ( ( $exec_state['status'] ?? '' ) === 'running' ) {
                        // Already running
                        return "⏳ Pipeline đang chạy — vui lòng chờ...\n\n"
                             . BizCity_Intent_Todos::get_formatted_message( $pipe_id );
                    }
                    // Status = completed/error but todos say otherwise → fall through to strategy 2
                }

                // Transient expired → try strategy 2
                error_log( '[INTENT-ENGINE] Resume: transient expired for execution_id=' . $execution_id );
            }
        }

        // Strategy 2: Transient expired — cannot blindly re-execute entire workflow
        // (would duplicate already-completed steps). Reset the stuck step and advise user.
        if ( $task_id > 0 ) {
            error_log( '[INTENT-ENGINE] Resume: transient expired for task_id=' . $task_id . ' — resetting stuck step' );

            // Reset the next pending/waiting step so it can be picked up by a new plan execution
            $next_step = (int) ( $active_pipe['next_step_index'] ?? 0 );
            $next_status = $active_pipe['next_status'] ?? '';

            if ( in_array( $next_status, [ 'WAITING_USER', 'IN_PROGRESS', 'ACTIVE' ], true ) ) {
                BizCity_Intent_Todos::update_status(
                    $pipe_id,
                    $active_pipe['next_tool'] ?? '',
                    'PENDING',
                    [ 'node_id' => $active_pipe['next_node_id'] ?? '' ]
                );
            }

            // Clean up stale execution reference
            delete_option( 'waic_active_execution_' . $task_id );

            return "⏰ Phiên thực thi trước đã hết hạn. Bước **"
                 . ( $active_pipe['next_label'] ?: $active_pipe['next_tool'] )
                 . "** đã được đặt lại.\n\n"
                 . "💡 Hãy ra lệnh lại để hệ thống thực hiện bước này (VD: _\"viết bài ...\"_)\n\n"
                 . BizCity_Intent_Todos::get_formatted_message( $pipe_id );
        }

        // Strategy 3: No task_id — report status only
        return "📋 Tìm thấy kế hoạch đang dở nhưng không có task_id để resume tự động.\n\n"
             . BizCity_Intent_Todos::get_formatted_message( $pipe_id )
             . "\n\nBạn có thể tạo kế hoạch mới nếu cần.";
    }

    /* ================================================================
     *  Early Skill Lookup (Phase 1.2 — Archetype Detection)
     * ================================================================ */

    /**
     * Perform early skill lookup before intent routing.
     *
     * Finds matching skill, detects archetype (A/B/C), assigns confidence tier.
     * HIGH confidence + archetype B/C can override mode to execution.
     *
     * @param string $message      User message.
     * @param array  $mode_result  Mode classification result.
     * @param string $slash_command Detected slash command (if any).
     * @return array|null  Enriched skill match or null if no match.
     */
    private function early_skill_lookup( string $message, array $mode_result, string $slash_command = '' ): ?array {
        if ( ! class_exists( 'BizCity_Skill_Manager' ) ) {
            return null;
        }

        $mgr = BizCity_Skill_Manager::instance();

        return $mgr->find_matching_enriched( [
            'mode'          => $mode_result['mode'] ?? '',
            'message'       => $message,
            'slash_command' => $slash_command,
            'limit'         => 1,
        ] );
    }

    /* ================================================================
     *  Phase 1.11 S6 — Shadow Comparison
     *
     *  Run Shell Engine in parallel (fire-and-forget) when legacy
     *  is handling traffic. Log both results for comparison.
     *  Enable: wp_option `bizcity_shell_shadow` = 1
     * ================================================================ */

    /**
     * Run shell engine in shadow mode and log comparison.
     *
     * Catches ALL exceptions to prevent shadow from affecting production.
     *
     * @param array $params Same params as process().
     */
    private function run_shadow_comparison( array $params ): void {
        try {
            $t0 = microtime( true );
            $shell_result = BizCity_Intent_Engine_Shell::instance()->process( $params );
            $shell_ms = round( ( microtime( true ) - $t0 ) * 1000 );

            // Log shadow result for comparison (non-blocking)
            error_log( sprintf(
                '[Shell:Shadow] user=%d, channel=%s, action=%s, goal=%s, elapsed=%dms, msg=%s',
                intval( $params['user_id'] ?? 0 ),
                $params['channel'] ?? 'webchat',
                $shell_result['action'] ?? '?',
                $shell_result['goal'] ?? '',
                $shell_ms,
                mb_substr( $params['message'] ?? '', 0, 80, 'UTF-8' )
            ) );

            // Store shadow result for comparison dashboard (transient, 5 min TTL)
            $user_id = intval( $params['user_id'] ?? 0 );
            set_transient(
                'bizcity_shadow_' . $user_id . '_' . substr( md5( $params['message'] ?? '' ), 0, 8 ),
                [
                    'shell_action'  => $shell_result['action'] ?? '',
                    'shell_goal'    => $shell_result['goal'] ?? '',
                    'shell_ms'      => $shell_ms,
                    'shell_meta'    => $shell_result['meta'] ?? [],
                    'timestamp'     => time(),
                ],
                300
            );
        } catch ( \Throwable $e ) {
            error_log( '[Shell:Shadow] Error: ' . $e->getMessage() );
        }
    }
}
