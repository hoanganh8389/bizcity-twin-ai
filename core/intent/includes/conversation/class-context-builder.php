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
 * BizCity Context Builder — 6-Layer Context Priority Chain (Dual Context)
 *
 * Assembles the LLM system prompt from 6 layers in strict priority order:
 *
 *   Layer 1: User Memory            — long-term memories (explicit + extracted)         [HIGHEST]
 *   Layer 2: Intent Conversation    — current sub-task context (goal, slots, summary)
 *   Layer 3: Webchat Session        — short-term: recent messages in current session
 *   Layer 4: Cross-Session          — long-term: recent session titles/summaries
 *   Layer 5: Project                — project-level context (related sessions, goals)
 *   Layer 6: Plugin Context         — Provider/Character/Knowledge (RAG, system prompt) [BASE]
 *
 * Architecture:
 *   - Each chat session = 1 webchat_conversation (wcs_* session_id)
 *   - Within each session, Intent Engine detects smaller intent_conversations (sub-tasks)
 *   - Layer 3 = SHORT-TERM context: recent messages in the current wcs_* session
 *   - Layer 4 = LONG-TERM context: titles/summaries of other recent sessions
 *   - This dual approach keeps conversations seamless while detecting sub-tasks
 *
 * This class hooks into `bizcity_chat_system_prompt` at priority 90 to inject
 * Layers 2-5. Layer 1 (User Memory) remains at priority 99.
 * Layer 6 (Plugin Context) is already the base prompt built by prepare_llm_call().
 *
 * @package BizCity_Intent
 * @since   3.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Context_Builder {

    /** @var self|null */
    private static $instance = null;

    /** Context layer constants */
    const LAYER_PLUGIN_CONTEXT      = 6;  // Base — built by prepare_llm_call + providers (pri 50)
    const LAYER_PROJECT             = 5;  // Project-level context (webchat sessions in project)
    const LAYER_CROSS_SESSION       = 4;  // Long-term: recent session titles/summaries
    const LAYER_WEBCHAT_SESSION     = 3;  // Short-term: recent messages in current session
    const LAYER_INTENT_CONV         = 2;  // Current intent conversation sub-task
    const LAYER_USER_MEMORY         = 1;  // User memory — handled by class-user-memory.php (pri 99)
    const LAYER_NOTEBOOK_SKELETON   = 7;  // Notebook Research Notes — keyword-matched notes context (hooked via bcn_build_skeleton_context)

    /** @var array Collected context parts per layer */
    private $layers = [];

    /** @var string|null Current intent conversation ID */
    private $current_conv_id = null;

    /** @var string|null Current project ID (from webchat session) */
    private $current_project_id = null;

    /** @var int Current user ID */
    private $user_id = 0;

    /** @var string Current session ID (e.g. wcs_xxxx) */
    private $session_id = '';

    /** @var object|null Cached webchat session row */
    private $wc_session = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Hook at priority 90 — after providers (50) but before user memory (99)
        add_filter( 'bizcity_chat_system_prompt', [ $this, 'inject_context_layers' ], 90, 2 );

        // Listen for conversation context from Intent Engine
        add_action( 'bizcity_intent_processed', [ $this, 'capture_intent_context' ], 5, 2 );
    }

    /**
     * Reset layers for a new request.
     */
    public function reset() {
        $this->layers             = [];
        $this->current_conv_id    = null;
        $this->current_project_id = null;
        $this->user_id            = 0;
        $this->session_id         = '';
        $this->wc_session         = null;
    }

    /**
     * Set current intent conversation context (called by Intent Engine during processing).
     *
     * @param string $conv_id    Intent conversation UUID.
     * @param array  $conv_data  Conversation row data (goal, goal_label, slots, rolling_summary, project_id).
     */
    public function set_conversation_context( $conv_id, array $conv_data ) {
        $this->current_conv_id = $conv_id;

        // Build Layer 2: Intent Conversation context (sub-task within the session)
        $parts = [];

        $goal_label = $conv_data['goal_label'] ?? '';
        $goal       = $conv_data['goal'] ?? '';
        if ( $goal_label || $goal ) {
            $parts[] = '🎯 Mục tiêu hiện tại: ' . ( $goal_label ?: $goal );
        }

        $status = $conv_data['status'] ?? '';
        if ( $status ) {
            $status_labels = [
                'ACTIVE'       => 'đang xử lý',
                'WAITING_USER' => 'đang chờ user trả lời',
                'COMPLETED'    => 'đã hoàn thành',
            ];
            $parts[] = 'Trạng thái: ' . ( $status_labels[ $status ] ?? $status );
        }

        // Rolling summary = condensed history of this intent conversation
        $summary = $conv_data['rolling_summary'] ?? '';
        if ( $summary && strlen( $summary ) > 10 ) {
            $parts[] = "Tóm tắt sub-task:\n" . $summary;
        }

        // Slots = structured data collected so far
        $slots = $conv_data['slots_json'] ?? '';
        if ( is_string( $slots ) ) {
            $slots = json_decode( $slots, true );
        }
        if ( ! empty( $slots ) ) {
            $slot_lines = [];
            foreach ( $slots as $k => $v ) {
                if ( strpos( $k, '_' ) === 0 ) continue; // skip internal keys
                $slot_lines[] = "  - {$k}: " . ( is_array( $v ) ? json_encode( $v, JSON_UNESCAPED_UNICODE ) : $v );
            }
            if ( $slot_lines ) {
                $parts[] = "Dữ liệu đã thu thập:\n" . implode( "\n", $slot_lines );
            }
        }

        // Open loops = pending sub-tasks
        $open_loops = $conv_data['open_loops'] ?? '';
        if ( is_string( $open_loops ) ) {
            $open_loops = json_decode( $open_loops, true );
        }
        if ( ! empty( $open_loops ) && is_array( $open_loops ) ) {
            $parts[] = "Công việc đang chờ:\n" . implode( "\n", array_map( function( $l ) {
                return "  - " . ( is_string( $l ) ? $l : json_encode( $l, JSON_UNESCAPED_UNICODE ) );
            }, $open_loops ) );
        }

        if ( ! empty( $parts ) ) {
            $this->layers[ self::LAYER_INTENT_CONV ] = "## 📋 SUB-TASK HIỆN TẠI (Intent Conversation)\n" . implode( "\n", $parts );
        }
    }

    /**
     * Set user and session for context retrieval.
     *
     * @param int    $user_id
     * @param string $session_id  The wcs_* session ID string.
     */
    public function set_user( $user_id, $session_id = '' ) {
        $this->user_id    = (int) $user_id;
        $this->session_id = $session_id;
    }

    /**
     * Resolve and cache the current webchat session row.
     *
     * @return object|null
     */
    private function resolve_wc_session() {
        if ( $this->wc_session !== null ) {
            return $this->wc_session;
        }

        if ( empty( $this->session_id ) || ! class_exists( 'BizCity_WebChat_Database' ) ) {
            return null;
        }

        $wc_db = BizCity_WebChat_Database::instance();
        $this->wc_session = $wc_db->get_session_by_session_id( $this->session_id );

        // Capture project_id from webchat session (overrides intent conv project_id)
        if ( $this->wc_session && ! empty( $this->wc_session->project_id ) ) {
            $this->current_project_id = $this->wc_session->project_id;
        }

        return $this->wc_session;
    }

    /**
     * Build Layer 3: Current Webchat Session — SHORT-TERM context.
     *
     * Retrieves recent messages from the current wcs_* session to provide
     * immediate conversational context. This helps the LLM understand what
     * has been discussed in THIS session without relying only on intent
     * conversation summaries (which may lag behind).
     */
    private function build_session_context() {
        if ( ! $this->user_id || ! class_exists( 'BizCity_WebChat_Database' ) ) return;

        $session = $this->resolve_wc_session();
        if ( ! $session ) return;

        $wc_db = BizCity_WebChat_Database::instance();
        
        // V3: Try to get session from new table first
        $session_v3 = null;
        if ( method_exists( $wc_db, 'get_session_v3_by_session_id' ) ) {
            $session_v3 = $wc_db->get_session_v3_by_session_id( $this->session_id );
        }
        
        // Use V3 session if available, fallback to legacy
        $session_pk = $session_v3 ? (int) $session_v3->id : (int) $session->id;
        $session_title = $session_v3 ? $session_v3->title : ( $session->title ?? '' );
        
        // Get rolling summary if available (V3 feature)
        $rolling_summary = '';
        if ( $session_v3 && ! empty( $session_v3->rolling_summary ) ) {
            $rolling_summary = $session_v3->rolling_summary;
        }
        
        // Recent Window: last 6 messages + rolling summary = đủ context, token nhẹ
        $messages = $wc_db->get_recent_messages_for_context( $session_pk, 6 );

        if ( empty( $messages ) ) return;

        // Build a condensed transcript of recent messages
        $lines = [];
        foreach ( $messages as $msg ) {
            $role = ( $msg->message_from === 'user' ) ? 'User' : 'Bot';
            $text = trim( $msg->message_text );

            // Truncate long messages for context efficiency
            if ( mb_strlen( $text ) > 150 ) {
                $text = mb_substr( $text, 0, 147 ) . '...';
            }

            $lines[] = "  {$role}: {$text}";
        }

        $title_fmt = ! empty( $session_title ) ? " — «{$session_title}»" : '';

        $context_parts = [];
        $context_parts[] = "## 💬 PHIÊN CHAT HIỆN TẠI{$title_fmt}";
        
        // Include rolling summary if available (condensed history)
        if ( ! empty( $rolling_summary ) ) {
            $context_parts[] = "📝 Tóm tắt phiên:
" . $rolling_summary;
        }
        
        $context_parts[] = "Lịch sử tin nhắn gần đây trong phiên này (mới nhất ở dưới):";
        $context_parts[] = implode( "\n", $lines );
        $context_parts[] = "(Đây là ngữ cảnh ngắn hạn — dùng để hiểu đúng câu hỏi hiện tại)";
        
        $this->layers[ self::LAYER_WEBCHAT_SESSION ] = implode( "\n", $context_parts );
    }

    /**
     * Build Layer 4: Cross-Session — LONG-TERM context.
     *
     * Retrieves titles/summaries of recent webchat sessions (excluding current)
     * to keep conversations seamless across sessions. The user may reference
     * topics from previous sessions.
     */
    private function build_cross_session_context() {
        if ( ! $this->user_id || ! class_exists( 'BizCity_WebChat_Database' ) ) return;

        $wc_db   = BizCity_WebChat_Database::instance();
        $session = $this->resolve_wc_session();

        // V3: Use new sessions table if available
        $sessions = [];
        if ( method_exists( $wc_db, 'get_sessions_v3_for_user' ) ) {
            $sessions = $wc_db->get_sessions_v3_for_user( $this->user_id, 'ADMINCHAT', 8 );
        }
        if ( empty( $sessions ) ) {
            $sessions = $wc_db->get_sessions_for_user( $this->user_id, 'ADMINCHAT', 8 );
        }
        if ( empty( $sessions ) ) return;

        $current_id = $session ? (int) $session->id : 0;
        $recent     = [];

        foreach ( $sessions as $s ) {
            if ( (int) $s->id === $current_id ) continue; // skip current session

            $title = $s->title ?? '';
            if ( empty( $title ) ) {
                // Fallback: first user message
                $title = $wc_db->get_first_user_message_in_session( (int) $s->id );
            }
            if ( empty( $title ) ) continue;

            if ( mb_strlen( $title ) > 60 ) {
                $title = mb_substr( $title, 0, 57 ) . '...';
            }

            // How long ago
            $ago = '';
            if ( ! empty( $s->started_at ) ) {
                $diff = time() - strtotime( $s->started_at );
                if ( $diff < 3600 ) {
                    $ago = round( $diff / 60 ) . ' phút trước';
                } elseif ( $diff < 86400 ) {
                    $ago = round( $diff / 3600 ) . ' giờ trước';
                } else {
                    $ago = round( $diff / 86400 ) . ' ngày trước';
                }
            }

            $time_suffix = $ago ? " ({$ago})" : '';
            $recent[] = "  💬 {$title}{$time_suffix}";

            if ( count( $recent ) >= 5 ) break;
        }

        if ( ! empty( $recent ) ) {
            $this->layers[ self::LAYER_CROSS_SESSION ] =
                "## 🕐 CÁC PHIÊN CHAT GẦN ĐÂY\n" .
                "User đã trao đổi các chủ đề này ở những phiên trước:\n" .
                implode( "\n", $recent ) . "\n" .
                "(Tham khảo để hiểu context dài hạn, giữ tính liền mạch giữa các phiên)";
        }
    }

    /**
     * Build Layer 5: Project context.
     *
     * If the current webchat session belongs to a project, include
     * project-level info: project name, character binding, rolling summaries
     * from all sessions in the project.
     *
     * V3: Uses bizcity_webchat_projects table with character_id binding.
     */
    private function build_project_context() {
        if ( empty( $this->current_project_id ) || ! $this->user_id ) return;

        $wc_db = class_exists( 'BizCity_WebChat_Database' ) ? BizCity_WebChat_Database::instance() : null;
        
        // V3: Try to get project from new table
        $project = null;
        if ( $wc_db && method_exists( $wc_db, 'get_project_by_uuid' ) ) {
            $project = $wc_db->get_project_by_uuid( $this->current_project_id );
        }
        
        // Fallback to user_meta (legacy)
        if ( ! $project ) {
            $projects = get_user_meta( $this->user_id, 'bizcity_projects', true );
            if ( is_array( $projects ) ) {
                foreach ( $projects as $p ) {
                    if ( $p['id'] === $this->current_project_id ) {
                        $project = (object) $p;
                        break;
                    }
                }
            }
        }
        if ( ! $project ) return;

        $parts = [];
        $project_name = is_object($project) ? ($project->name ?? '') : ($project['name'] ?? '');
        $parts[] = "📁 Dự án: **" . ( $project_name ?: 'Unknown' ) . "**";
        
        // V3: Get character context if bound
        $char_id = is_object($project) ? ($project->character_id ?? 0) : 0;
        if ( $char_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $kb = BizCity_Knowledge_Database::instance();
            $char = $kb->get_character( $char_id );
            if ( $char ) {
                $parts[] = "🎭 Agent chuyên biệt: **" . $char->name . "**";
                if ( ! empty( $char->description ) ) {
                    $parts[] = "Mô tả: " . mb_substr( $char->description, 0, 150 );
                }
            }
        }
        
        // V3: Get project context from database method
        if ( $wc_db && method_exists( $wc_db, 'get_project_context' ) ) {
            $proj_ctx = $wc_db->get_project_context( $this->current_project_id );
            
            // Project memory (rolling summaries from all sessions)
            if ( ! empty( $proj_ctx['project_memory'] ) ) {
                $parts[] = "\n📚 Tri thức dự án (từ các phiên trước):";
                // Truncate if too long
                $mem = $proj_ctx['project_memory'];
                if ( mb_strlen( $mem ) > 800 ) {
                    $mem = mb_substr( $mem, 0, 797 ) . '...';
                }
                $parts[] = $mem;
            }
        }

        // Get webchat sessions in this project
        if ( $wc_db ) {
            $proj_sessions = [];
            if ( method_exists( $wc_db, 'get_sessions_by_project' ) ) {
                $proj_sessions = $wc_db->get_sessions_by_project( $this->current_project_id, 15 );
            } elseif ( method_exists( $wc_db, 'get_sessions_for_user' ) ) {
                $proj_sessions = $wc_db->get_sessions_for_user( $this->user_id, 'ADMINCHAT', 20, $this->current_project_id );
            }

            if ( ! empty( $proj_sessions ) ) {
                $session     = $this->resolve_wc_session();
                $current_sid = $session ? $session->session_id : '';
                $task_lines  = [];

                foreach ( $proj_sessions as $s ) {
                    $title = $s->title ?? '';
                    // V3: Use last_message_preview as fallback
                    if ( empty( $title ) && ! empty( $s->last_message_preview ) ) {
                        $title = mb_substr( $s->last_message_preview, 0, 50 );
                    }
                    if ( empty( $title ) && $wc_db ) {
                        $title = $wc_db->get_first_user_message_in_session( (int) $s->id );
                    }
                    if ( empty( $title ) ) continue;

                    if ( mb_strlen( $title ) > 50 ) {
                        $title = mb_substr( $title, 0, 47 ) . '...';
                    }

                    $is_current = ( $s->session_id === $current_sid ) ? ' ← (đang hoạt động)' : '';
                    $task_lines[] = "  📝 {$title}{$is_current}";
                }

                if ( $task_lines ) {
                    $parts[] = "\nCác phiên chat trong dự án (" . count( $task_lines ) . "):";
                    $parts   = array_merge( $parts, $task_lines );
                }
            }
        }

        $parts[] = "(Khi trả lời, nhận thức context dự án này để đưa ra gợi ý phù hợp)";

        $this->layers[ self::LAYER_PROJECT ] = "## 📁 NGỮ CẢNH DỰ ÁN (Project)\n" . implode( "\n", $parts );
    }

    /**
     * Main filter callback: inject context layers into system prompt.
     *
     * Called at priority 90 on `bizcity_chat_system_prompt`.
     * Layer 6 (Plugin Context) is already the base $prompt.
     * Layer 1 (User Memory) is injected separately at priority 99.
     *
     * @param string $prompt  The assembled system prompt so far.
     * @param array  $args    Filter args: character_id, message, user_id, session_id, platform_type.
     * @return string
     */
    public function inject_context_layers( $prompt, $args = [] ) {
        // Start timing
        $chain_start = microtime( true );

        // Determine user from filter args if not already set
        if ( ! $this->user_id && ! empty( $args['user_id'] ) ) {
            $this->user_id    = (int) $args['user_id'];
            $this->session_id = $args['session_id'] ?? '';
        }

        // ── TOOL CONTEXT MODE (Phase 13): compact context for execution ──
        if ( $this->use_tool_context ) {
            $tool_ctx = $this->build_tool_execution_context( 800 );
            if ( ! empty( $tool_ctx ) ) {
                $prompt .= "\n\n" . $tool_ctx;
            }

            // Log compact context
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                BizCity_User_Memory::log_router_event( [
                    'step'             => 'context_chain_tool',
                    'message'          => 'Tool Context (compact, pri 90)',
                    'mode'             => 'tool_context',
                    'functions_called' => 'BizCity_Context_Builder::inject_context_layers() [tool_mode]',
                    'context_length'   => mb_strlen( $tool_ctx, 'UTF-8' ),
                    'chain_ms'         => round( ( microtime( true ) - $chain_start ) * 1000, 2 ),
                ], $this->session_id );
            }

            // Reset flag after use
            $this->use_tool_context = false;
            return $prompt;
        }

        // ── EMOTION CONTEXT (default): full 6-layer context chain ──
        // ── Twin Focus Gate: skip layers that mode doesn't need ──
        $twin_gate_active = class_exists( 'BizCity_Focus_Gate' );

        // Build dynamic layers (3, 4, 5, 7) — gated by focus profile
        $t_session = microtime( true );
        if ( ! $twin_gate_active || BizCity_Focus_Gate::should_inject( 'session' ) ) {
            $this->build_session_context();
        }
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::layer( 'session', ! empty( $this->layers[ self::LAYER_WEBCHAT_SESSION ] ?? '' ), round( ( microtime( true ) - $t_session ) * 1000, 2 ) );
        }

        $t_cross = microtime( true );
        if ( ! $twin_gate_active || BizCity_Focus_Gate::should_inject( 'cross_session' ) ) {
            $this->build_cross_session_context();
        }
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::layer( 'cross_session', ! empty( $this->layers[ self::LAYER_CROSS_SESSION ] ?? '' ), round( ( microtime( true ) - $t_cross ) * 1000, 2 ) );
        }

        $t_project = microtime( true );
        if ( ! $twin_gate_active || BizCity_Focus_Gate::should_inject( 'project' ) ) {
            $this->build_project_context();
        }
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::layer( 'project', ! empty( $this->layers[ self::LAYER_PROJECT ] ?? '' ), round( ( microtime( true ) - $t_project ) * 1000, 2 ) );
        }

        $this->build_notebook_skeleton_context(); // Layer 7 — requires project_id resolved above

        // ── Rolling Memory + Episodic Memory injection ──
        $rolling_ctx  = '';
        $episodic_ctx = '';

        if ( $this->user_id && class_exists( 'BizCity_Rolling_Memory' ) ) {
            $rolling_ctx = BizCity_Rolling_Memory::instance()->build_context(
                $this->user_id,
                $this->session_id,
                $this->current_conv_id ?: ''
            );
        }

        if ( $this->user_id && class_exists( 'BizCity_Episodic_Memory' ) ) {
            $current_goal = '';
            if ( ! empty( $this->layers[ self::LAYER_INTENT_CONV ] ) && $this->current_conv_id ) {
                $db   = BizCity_Intent_Database::instance();
                $conv = $db->get_conversation( $this->current_conv_id );
                $current_goal = $conv ? ( $conv->goal ?? '' ) : '';
            }
            $episodic_ctx = BizCity_Episodic_Memory::instance()->build_context(
                $this->user_id,
                $current_goal
            );
        }

        // ── Selected Sources injection (Sprint 3 — user-selected sources) ──
        $selected_sources_ctx = '';
        $selected_source_ids = $args['selected_source_ids'] ?? [];
        if ( ! empty( $selected_source_ids ) && $this->user_id ) {
            global $wpdb;
            $table    = $wpdb->prefix . 'bizcity_webchat_sources';
            $safe_ids = array_map( 'absint', array_slice( $selected_source_ids, 0, 10 ) );
            $placeholders = implode( ',', array_fill( 0, count( $safe_ids ), '%d' ) );
            $sources  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, source_type, title, url, content FROM {$table} WHERE id IN ({$placeholders}) AND user_id = %d",
                    array_merge( $safe_ids, [ $this->user_id ] )
                )
            );
            if ( $sources ) {
                $parts = [ '📎 Nguồn tài liệu người dùng đã chọn (ưu tiên sử dụng):' ];
                foreach ( $sources as $src ) {
                    $label = $src->title ?: ( $src->url ?: "Source #{$src->id}" );
                    $snippet = '';
                    if ( ! empty( $src->content ) ) {
                        $snippet = mb_substr( $src->content, 0, 600, 'UTF-8' );
                        if ( mb_strlen( $src->content, 'UTF-8' ) > 600 ) {
                            $snippet .= '…';
                        }
                    }
                    $parts[] = "- [{$src->source_type}] {$label}" . ( $snippet ? "\n  " . $snippet : '' );
                }
                $selected_sources_ctx = implode( "\n", $parts );
            }
        }

        // Assemble: Layer 7 (Notebook Skeleton) → Layer 5 (Project) → Layer 4 (Cross-Session) → Layer 3 (Session) → Layer 2 (Intent)
        // Lower number = higher priority = appended LAST (closest to user message)
        $injection = '';
        $ordered_layers = [
            self::LAYER_NOTEBOOK_SKELETON, // 7 — outermost (project knowledge digest)
            self::LAYER_PROJECT,           // 5
            self::LAYER_CROSS_SESSION,     // 4
            self::LAYER_WEBCHAT_SESSION,   // 3
            self::LAYER_INTENT_CONV,       // 2
        ];

        foreach ( $ordered_layers as $layer ) {
            if ( ! empty( $this->layers[ $layer ] ) ) {
                $injection .= "\n\n" . $this->layers[ $layer ];
            }
        }

        // Inject Selected Sources (high priority — before Episodic/Rolling)
        if ( ! empty( $selected_sources_ctx ) ) {
            $injection .= "\n\n" . $selected_sources_ctx;
        }

        // Inject Episodic Memory (between Cross-Session and Intent)
        if ( ! empty( $episodic_ctx ) ) {
            $injection .= "\n\n" . $episodic_ctx;
        }

        // Inject Rolling Memory (closest to Intent — high priority)
        if ( ! empty( $rolling_ctx ) ) {
            $injection .= "\n\n" . $rolling_ctx;
        }

        if ( ! empty( $injection ) ) {
            $prompt .= "\n\n" .
                "# ══════════════════════════════════════════════\n" .
                "# 🧠 CONTEXT CHAIN (9-Layer Memory Architecture)\n" .
                "# Priority: UserMem > Rolling > Episodic > ResearchMem > Intent > Session > Cross > Project > Notes > Plugin\n" .
                "# Ngữ cảnh ngắn hạn + dài hạn + rolling tracking + episodic events + notebook research notes\n" .
                "# ══════════════════════════════════════════════" .
                $injection;
        }

        // ── Log 6-Layer Context Chain for Router Console ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'context_chain',
                'message'          => '9-Layer Memory Architecture (pri 90)',
                'mode'             => 'context_builder',
                'functions_called' => 'BizCity_Context_Builder::inject_context_layers()',
                'pipeline'         => [ 'L1:Memory(99)', 'ResearchMem(92)', 'Rolling', 'Episodic', 'L2:Intent', 'L3:Session', 'L4:Cross', 'L5:Project', 'L7:Notes', 'L6:Plugin(base)' ],
                'file_line'        => 'class-context-builder.php::inject_context_layers()',
                'user_id'          => $this->user_id,
                'session_id'       => $this->session_id,
                'project_id'       => $this->current_project_id,
                'conv_id'          => $this->current_conv_id,
                'context_length'   => mb_strlen( $injection, 'UTF-8' ),
                'has_intent'           => ! empty( $this->layers[ self::LAYER_INTENT_CONV ] ),
                'has_session'          => ! empty( $this->layers[ self::LAYER_WEBCHAT_SESSION ] ),
                'has_cross'            => ! empty( $this->layers[ self::LAYER_CROSS_SESSION ] ),
                'has_project'          => ! empty( $this->layers[ self::LAYER_PROJECT ] ),
                'has_notebook_notes' => ! empty( $this->layers[ self::LAYER_NOTEBOOK_SKELETON ] ),
                'has_rolling'      => ! empty( $rolling_ctx ),
                'has_episodic'     => ! empty( $episodic_ctx ),
                'layers_preview'   => array_map( function( $v ) { return mb_substr( $v, 0, 100, 'UTF-8' ); }, $this->layers ),
                'chain_ms'         => round( ( microtime( true ) - $chain_start ) * 1000, 2 ),
            ], $this->session_id );
        }

        return $prompt;
    }

    /**
     * Capture conversation context from Intent Engine after processing.
     * This is called via `bizcity_intent_processed` action.
     *
     * @param array $result  Engine result.
     * @param array $params  Original params.
     */
    public function capture_intent_context( $result, $params ) {
        // If the engine has an active conversation, capture it
        if ( ! empty( $result['conversation_id'] ) ) {
            $db   = BizCity_Intent_Database::instance();
            $conv = $db->get_conversation( $result['conversation_id'] );
            if ( $conv ) {
                $this->set_conversation_context( $result['conversation_id'], (array) $conv );
            }
        }
    }

    /** @var bool When true, inject_context_layers uses compact Tool Context instead of full 6-layer */
    private $use_tool_context = false;

    /**
     * Build Layer 7: Notebook Project Skeleton.
     *
     * Calls filter `bcn_build_skeleton_context` with the current project_id.
     * The bizcity-companion-notebook plugin listens to this filter and
     * returns a compact text summary of the project's skeleton JSON.
     * Zero-cost when notebook plugin is not active (filter returns '').
     */
    private function build_notebook_skeleton_context() {
        if ( empty( $this->current_project_id ) ) return;

        /**
         * Filter: bcn_build_skeleton_context
         *
         * @param string $context    Empty string by default.
         * @param string $project_id The current webchat project UUID.
         * @return string  Compact skeleton context (max ~2000 chars) or ''.
         */
        $context = (string) apply_filters( 'bcn_build_skeleton_context', '', $this->current_project_id );
        if ( ! empty( $context ) ) {
            $this->layers[ self::LAYER_NOTEBOOK_SKELETON ] = $context;
        }
    }

    /**
     * Switch to compact Tool Context mode for execution.
     * When enabled, inject_context_layers() will output only L2 (Intent Conv) + compact L3 (Session).
     * Call this BEFORE the LLM call for execution mode.
     *
     * @param bool $enabled
     * @since 4.0.0
     */
    public function set_tool_context_mode( bool $enabled = true ) {
        $this->use_tool_context = $enabled;
    }

    /**
     * Check if currently in Tool Context mode.
     *
     * @return bool
     * @since 4.0.0
     */
    public function is_tool_context_mode(): bool {
        return $this->use_tool_context;
    }

    /**
     * Build compact Tool Execution Context (Phase 13 — Dual Context Architecture).
     *
     * Only includes L2 (Intent Conversation) + compact L3 (last 3 messages from session).
     * Target: ~800 chars vs ~3000+ chars full 6-layer context.
     * Used when mode=execution and tool is confirmed.
     *
     * @param int $max_length  Max characters (default 800).
     * @return string  Compact context string.
     * @since 4.0.0
     */
    public function build_tool_execution_context( int $max_length = 800 ): string {
        $parts = [];
        $total = 0;

        // L2: Intent Conversation (current sub-task — most important for tool execution)
        if ( ! empty( $this->layers[ self::LAYER_INTENT_CONV ] ) ) {
            $text = $this->layers[ self::LAYER_INTENT_CONV ];
            if ( mb_strlen( $text, 'UTF-8' ) > 500 ) {
                $text = mb_substr( $text, 0, 497, 'UTF-8' ) . '...';
            }
            $parts[] = $text;
            $total += mb_strlen( $text, 'UTF-8' );
        }

        // L3: Compact session context (last 3 messages only)
        if ( $this->user_id && class_exists( 'BizCity_WebChat_Database' ) ) {
            $session = $this->resolve_wc_session();
            if ( $session ) {
                $wc_db = BizCity_WebChat_Database::instance();
                $recent = [];
                if ( method_exists( $wc_db, 'get_messages_by_session_id' ) ) {
                    $recent = $wc_db->get_messages_by_session_id( $session->session_id, 3 );
                }
                if ( ! empty( $recent ) ) {
                    $msg_lines = [];
                    foreach ( $recent as $msg ) {
                        $from = ( $msg->message_from === 'user' ) ? 'User' : 'Bot';
                        $text = mb_substr( $msg->message_text ?? '', 0, 100, 'UTF-8' );
                        $msg_lines[] = "  {$from}: {$text}";
                    }
                    $remaining = $max_length - $total - 50;
                    if ( $remaining > 100 ) {
                        $session_text = "## 💬 PHIÊN GẦN NHẤT\n" . implode( "\n", $msg_lines );
                        if ( mb_strlen( $session_text, 'UTF-8' ) > $remaining ) {
                            $session_text = mb_substr( $session_text, 0, $remaining - 3, 'UTF-8' ) . '...';
                        }
                        $parts[] = $session_text;
                    }
                }
            }
        }

        if ( empty( $parts ) ) return '';

        return "# 🔧 TOOL CONTEXT (compact)\n" . implode( "\n\n", $parts );
    }

    /**
     * Build a compact context string for tool execution.
     *
     * When a tool calls an external LLM (e.g. bizcity_openrouter_chat) to generate
     * content, it needs conversational context so the output matches the user's intent.
     * This method exports the 6-layer dual context as a condensed text block that tools
     * can inject into their LLM system prompts.
     *
     * Usage in tool callbacks:
     *   $context = $slots['_meta']['_context'] ?? '';
     *   $messages[] = [ 'role' => 'system', 'content' => $base_prompt . "\n\n" . $context ];
     *
     * Returns empty string when no meaningful context is available.
     *
     * @param int $max_length  Max characters for the context string (default 1200).
     * @return string
     * @since 3.1.0
     */
    public function build_tool_context( int $max_length = 1200 ): string {
        // ── Twin Focus Gate: respect mode profile for tool context too ──
        // Execution mode: session=compact, cross_session=false, project=if_needed
        $twin_gate = class_exists( 'BizCity_Focus_Gate' );

        // Session context — always build (execution mode allows compact)
        if ( ! $twin_gate || BizCity_Focus_Gate::should_inject( 'session' ) ) {
            $this->build_session_context();
        }
        // Cross-session — skip in execution mode (false by default)
        if ( ! $twin_gate || BizCity_Focus_Gate::should_inject( 'cross_session' ) ) {
            $this->build_cross_session_context();
        }
        // Project context — build when allowed (execution: if_needed → truthy)
        if ( ! $twin_gate || BizCity_Focus_Gate::should_inject( 'project' ) ) {
            $this->build_project_context();
        }

        // Layer priority: Intent (2) > Session (3) > Cross-Session (4) > Project (5)
        // Layer 1 (Memory) is handled separately by User Memory class.
        // Layer 6 (Plugin) is the base prompt — not included here.
        $ordered = [
            self::LAYER_INTENT_CONV,
            self::LAYER_WEBCHAT_SESSION,
            self::LAYER_CROSS_SESSION,
            self::LAYER_PROJECT,
        ];

        $parts = [];
        $total = 0;
        foreach ( $ordered as $layer ) {
            if ( empty( $this->layers[ $layer ] ) ) continue;

            $text = $this->layers[ $layer ];
            $remaining = $max_length - $total;
            if ( $remaining <= 50 ) break;

            if ( mb_strlen( $text, 'UTF-8' ) > $remaining ) {
                $text = mb_substr( $text, 0, $remaining - 3, 'UTF-8' ) . '...';
            }
            $parts[] = $text;
            $total += mb_strlen( $text, 'UTF-8' ) + 2; // +2 for \n\n separator
        }

        if ( empty( $parts ) ) return '';

        return "# NGỮ CẢNH HỘI THOẠI (Dual Context)\n" . implode( "\n\n", $parts );
    }

    /**
     * Get a summary of all context layers for debugging/logging.
     *
     * @return array
     */
    public function get_debug_info() {
        return [
            'layers'     => array_map( function( $v ) { return mb_substr( $v, 0, 200, 'UTF-8' ); }, $this->layers ),
            'conv_id'    => $this->current_conv_id,
            'project_id' => $this->current_project_id,
            'user_id'    => $this->user_id,
            'session_id' => $this->session_id,
            'wc_session' => $this->wc_session ? (int) $this->wc_session->id : null,
        ];
    }
}
