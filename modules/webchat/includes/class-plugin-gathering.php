<?php
/**
 * Bizcity Twin AI — Plugin Gathering
 * Thu thập dữ liệu plugin qua @ mention / Collect plugin data via @ mention slot filling
 *
 * Khi user @ mention một plugin, hệ thống tạo plugin_context riêng biệt,
 * tra cứu Tool Registry để lấy schema, rồi fill missing fields qua từng lượt chat.
 *
 * When user @ mentions a plugin, system creates a separate plugin_context,
 * queries Tool Registry for schema, then fills missing fields through chat turns.
 *
 * ┌──────────────────────────────────────────────────────────────┐
 * │  PLUGIN GATHERING ≠ INTENT ENGINE                            │
 * │                                                              │
 * │  Intent Engine:                                              │
 * │    - Auto-detect mode/goal bằng LLM (3-call hoặc unified)   │
 * │    - Lưu vào intent_conversations table                      │
 * │    - Phù hợp cho tin nhắn tự nhiên, không gắn plugin        │
 * │                                                              │
 * │  Plugin Gathering:                                           │
 * │    - Explicit: user chọn plugin qua @ mention                │
 * │    - Lưu vào webchat_messages (plugin_slug column)           │
 * │    - Tra cứu Tool Registry → biết required/optional slots    │
 * │    - Gather data từng lượt, fill JSON slots                  │
 * │    - Khi required slots đầy → kick start tool execution      │
 * │    - Query WHERE session_id AND plugin_slug cho consistency  │
 * └──────────────────────────────────────────────────────────────┘
 *
 * @package BizCity_Bot_WebChat
 * @version 1.0.0
 * @since Schema 3.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Plugin_Gathering' ) ) :

class BizCity_Plugin_Gathering {

    /** @var self|null */
    private static $instance = null;

    /** @var string Transient prefix for gathering state */
    const TRANSIENT_PREFIX = 'bizc_pg_';

    /** @var int Gathering session timeout (30 minutes) */
    const GATHER_TIMEOUT = 1800;

    /**
     * Singleton accessor.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook into chat pre-AI filter at priority 2 (BEFORE Intent Engine @5)
        // Only intercepts when routing_mode=manual + plugin_slug set
        add_filter( 'bizcity_chat_pre_ai_response', [ $this, 'intercept_manual_routing' ], 2, 2 );
    }

    /* ================================================================
     * 0. FILTER HOOK — bizcity_chat_pre_ai_response (priority 2)
     * ================================================================
     *
     * Chạy TRƯỚC Intent Engine (priority 5).
     *
     * 2 trường hợp intercept:
     *   A) routing_mode=manual + plugin_slug → user vừa @ mention!
     *   B) routing_mode=automatic + gathering state tồn tại → user đang
     *      trả lời missing fields (không cần @ mention lại)
     *
     * Nếu không match → return null → Intent Engine xử lý bình thường.
     */

    /**
     * Filter callback: intercept manual routing khi user @ mention plugin.
     *
     * @param mixed $pre_reply  null nếu chưa ai handle, array nếu đã handle
     * @param array $context    Context từ Chat Gateway
     * @return mixed            null hoặc array ['message' => ...]
     */
    public function intercept_manual_routing( $pre_reply, $context ) {
        // Already handled by another filter → skip
        if ( is_array( $pre_reply ) && ! empty( $pre_reply['message'] ) ) {
            return $pre_reply;
        }

        $plugin_slug   = $context['plugin_slug']   ?? '';
        $routing_mode  = $context['routing_mode']   ?? 'automatic';
        $session_id    = $context['session_id']     ?? '';
        $message       = $context['message']        ?? '';
        $user_id       = intval( $context['user_id'] ?? 0 );
        $platform_type = $context['platform_type']  ?? 'WEBCHAT';
        $images        = $context['images']         ?? [];

        // ── Case A: Explicit @ mention (manual routing) ──
        if ( $routing_mode === 'manual' && $plugin_slug && $session_id ) {
            error_log( "[PluginGathering] Manual routing: plugin={$plugin_slug}, session={$session_id}" );

            $result = $this->process( $session_id, $plugin_slug, $message, [
                'user_id'       => $user_id,
                'channel'       => strtolower( $platform_type ),
                'images'        => $images,
                'message_id'    => uniqid( 'pg_' ),
            ] );

            return $this->format_filter_response( $result, $plugin_slug );
        }

        // ── Case B: Auto-continue — active gathering state nhưng user không @ mention lại ──
        // C1 Fix: Check if message is likely off-topic before intercepting.
        // If off-topic → return null → Intent Engine handles emotion/knowledge/reflection.
        if ( $routing_mode === 'automatic' && $session_id ) {
            // Check if there's an active gathering state for this session
            $last_plugin = $this->get_last_active_plugin( $session_id );
            if ( $last_plugin ) {
                $state = $this->get_state( $session_id, $last_plugin );
                if ( $state && $state['status'] === 'gathering' ) {

                    // C1: Off-topic detection — check if message relates to gathering goal
                    $relevance = $this->assess_message_relevance( $message, $state );

                    if ( $relevance === 'off_topic' ) {
                        // Message is clearly off-topic → let Intent Engine handle
                        error_log( "[PluginGathering] Off-topic detected, releasing to Intent Engine: '{$message}'" );
                        return null;
                    }

                    if ( $relevance === 'ambiguous' ) {
                        // Not sure → ask user to confirm
                        error_log( "[PluginGathering] Ambiguous message, asking user: '{$message}'" );
                        $goal_label = $state['goal_label'] ?? $state['goal'];
                        $confirm_msg = "Bạn đang trong tác vụ **{$goal_label}** (plugin {$last_plugin}).\n\n"
                            . "Bạn muốn:\n"
                            . "1️⃣ Tiếp tục — trả lời cho tác vụ hiện tại\n"
                            . "2️⃣ Hủy tác vụ — chuyển sang chủ đề mới\n\n"
                            . "_Gõ \"tiếp tục\" hoặc \"hủy\" để chọn._";
                        return array(
                            'message'     => $confirm_msg,
                            'action'      => 'gathering_confirm',
                            'goal'        => $state['goal'] ?? '',
                            'goal_label'  => $goal_label,
                            'plugin_slug' => $last_plugin,
                        );
                    }

                    // relevance === 'related' → proceed with normal gathering
                    error_log( "[PluginGathering] Auto-continue: plugin={$last_plugin}, session={$session_id}" );

                    $result = $this->continue_gathering( $session_id, $last_plugin, $message, [
                        'user_id'    => $user_id,
                        'channel'    => strtolower( $platform_type ),
                        'images'     => $images,
                        'message_id' => uniqid( 'pg_' ),
                    ] );

                    return $this->format_filter_response( $result, $last_plugin );
                }
            }
        }

        // Không match → let Intent Engine handle
        return null;
    }

    /**
     * Format gathering result → bizcity_chat_pre_ai_response format.
     *
     * @param array  $result       Gathering result envelope
     * @param string $plugin_slug  Plugin slug for badge
     * @return array|null          Filter response or null
     */
    private function format_filter_response( $result, $plugin_slug ) {
        if ( empty( $result ) ) return null;

        $status = $result['status'] ?? '';

        // ── Execute: kick start tool ──
        if ( $status === 'execute' ) {
            return $this->execute_tool( $result );
        }

        // ── Gathering / Ask / Select / Error / Cancelled ──
        if ( ! empty( $result['message'] ) ) {
            $response = [
                'message'     => $result['message'],
                'action'      => $result['action'] ?? 'plugin_gathering',
                'goal'        => $result['goal'] ?? '',
                'goal_label'  => $result['goal_label'] ?? '',
                'plugin_slug' => $plugin_slug,
            ];

            // Add progress info for frontend badge
            if ( ! empty( $result['progress'] ) ) {
                $response['message'] .= "\n\n_📊 {$result['progress']}_";
            }

            return $response;
        }

        return null;
    }

    /**
     * Execute tool via Intent Engine's tool execution system.
     *
     * @param array $result  Execution-ready envelope from prepare_execution()
     * @return array         Filter response with tool output
     */
    private function execute_tool( $result ) {
        $tool_name = $result['tool_name'] ?? '';
        $callback  = $result['callback'] ?? '';
        $slots     = $result['slots'] ?? [];

        error_log( "[PluginGathering] Executing tool: {$tool_name} | callback: {$callback}" );
        error_log( "[PluginGathering] Slots: " . wp_json_encode( $slots, JSON_UNESCAPED_UNICODE ) );

        // Try to execute via Intent Engine's tool system
        if ( class_exists( 'BizCity_Intent_Tools' ) ) {
            $tools = BizCity_Intent_Tools::instance();
            $tool_result = $tools->execute( $tool_name, $slots );

            if ( $tool_result && ! empty( $tool_result['message'] ) ) {
                return [
                    'message'         => $tool_result['message'],
                    'action'          => 'tool_complete',
                    'goal'            => $result['goal'] ?? '',
                    'goal_label'      => $result['goal_label'] ?? '',
                    'plugin_slug'     => $result['plugin_slug'] ?? '',
                    'conversation_id' => $tool_result['conversation_id'] ?? '',
                ];
            }

            // Tool returned error
            if ( $tool_result && ! empty( $tool_result['error'] ) ) {
                return [
                    'message'     => '❌ ' . $tool_result['error'],
                    'action'      => 'tool_error',
                    'goal'        => $result['goal'] ?? '',
                    'plugin_slug' => $result['plugin_slug'] ?? '',
                ];
            }
        }

        // Fallback: try direct callback if available
        if ( $callback && is_callable( $callback ) ) {
            try {
                $output = call_user_func( $callback, $slots );
                if ( is_array( $output ) && ! empty( $output['message'] ) ) {
                    return [
                        'message'     => $output['message'],
                        'action'      => 'tool_complete',
                        'goal'        => $result['goal'] ?? '',
                        'plugin_slug' => $result['plugin_slug'] ?? '',
                    ];
                }
            } catch ( \Exception $e ) {
                error_log( "[PluginGathering] Callback error: " . $e->getMessage() );
                return [
                    'message'     => '❌ Lỗi thực thi: ' . $e->getMessage(),
                    'action'      => 'tool_error',
                    'plugin_slug' => $result['plugin_slug'] ?? '',
                ];
            }
        }

        // No execution path available
        return [
            'message'     => "⚠️ Tool **{$tool_name}** không tìm thấy callback. Data đã thu thập:\n```json\n" .
                             wp_json_encode( $slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n```",
            'action'      => 'tool_not_found',
            'plugin_slug' => $result['plugin_slug'] ?? '',
        ];
    }

    /* ================================================================
     * 1. TOOL REGISTRY LOOKUP
     * ================================================================ */

    /**
     * Tìm tất cả tools thuộc 1 plugin trong Tool Registry.
     *
     * @param string $plugin_slug  VD: 'bizcity-chatgpt-knowledge', 'bizcity-tool-content'
     * @return array  Array of tool rows (from bizcity_tool_registry)
     */
    public function get_tools_for_plugin( $plugin_slug ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_tool_registry';

        // Kiểm tra bảng tồn tại
        if ( ! $this->table_exists( $table ) ) {
            return [];
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT tool_key, tool_name, plugin, title, description, goal, goal_label,
                    goal_description, required_slots, optional_slots, input_schema, callback, active
             FROM {$table}
             WHERE plugin = %s AND active = 1
             ORDER BY priority ASC, tool_name ASC",
            $plugin_slug
        ), ARRAY_A );
    }

    /**
     * Tìm 1 tool cụ thể theo goal (ưu tiên plugin_slug nếu có).
     *
     * @param string $goal         VD: 'ask_chatgpt', 'write_article'
     * @param string $plugin_slug  Optional — filter by plugin
     * @return array|null          Tool row or null
     */
    public function get_tool_by_goal( $goal, $plugin_slug = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_tool_registry';

        if ( ! $this->table_exists( $table ) ) {
            return null;
        }

        $where = "goal = %s AND active = 1";
        $params = [ $goal ];

        if ( $plugin_slug ) {
            $where .= " AND plugin = %s";
            $params[] = $plugin_slug;
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY priority ASC LIMIT 1",
            ...$params
        ), ARRAY_A );
    }

    /**
     * Parse required_slots + optional_slots từ Tool Registry row.
     *
     * @param array $tool  Tool row từ get_tools_for_plugin() hoặc get_tool_by_goal()
     * @return array       [ 'required' => [...], 'optional' => [...], 'input_schema' => [...] ]
     */
    public function parse_tool_schema( $tool ) {
        $required = json_decode( $tool['required_slots'] ?? '[]', true ) ?: [];
        $optional = json_decode( $tool['optional_slots'] ?? '[]', true ) ?: [];
        $input_schema = json_decode( $tool['input_schema'] ?? '{}', true ) ?: [];

        return [
            'required'     => $required,
            'optional'     => $optional,
            'input_schema' => $input_schema,
            'tool_name'    => $tool['tool_name'] ?? '',
            'goal'         => $tool['goal'] ?? '',
            'goal_label'   => $tool['goal_label'] ?? '',
            'plugin'       => $tool['plugin'] ?? '',
            'callback'     => $tool['callback'] ?? '',
        ];
    }

    /* ================================================================
     * 2. GATHERING STATE MANAGEMENT
     * ================================================================
     *
     * State lưu trong wp_options (transient) theo key:
     *   bizc_pg_{md5(session_id + plugin_slug)}
     *
     * Format:
     * {
     *   "session_id": "wcs_xxx",
     *   "plugin_slug": "bizcity-tool-content",
     *   "goal": "write_article",
     *   "tool_name": "write_article",
     *   "required_fields": ["topic", "keywords"],
     *   "optional_fields": ["tone", "length"],
     *   "filled_slots": {"topic": "marketing AI"},
     *   "missing_required": ["keywords"],
     *   "missing_optional": ["tone", "length"],
     *   "status": "gathering",        // gathering | ready | executed | expired
     *   "created_at": 1741500000,
     *   "updated_at": 1741500120,
     *   "turn_count": 3
     * }
     */

    /**
     * Generate transient key cho gathering state.
     */
    private function state_key( $session_id, $plugin_slug ) {
        return self::TRANSIENT_PREFIX . md5( $session_id . '|' . $plugin_slug );
    }

    /**
     * Lấy gathering state hiện tại.
     *
     * @param string $session_id
     * @param string $plugin_slug
     * @return array|null  State array hoặc null nếu chưa có
     */
    public function get_state( $session_id, $plugin_slug ) {
        $key = $this->state_key( $session_id, $plugin_slug );
        $state = get_transient( $key );

        if ( ! $state || ! is_array( $state ) ) {
            return null;
        }

        // Check expiry
        if ( ( time() - ( $state['updated_at'] ?? 0 ) ) > self::GATHER_TIMEOUT ) {
            delete_transient( $key );
            return null;
        }

        return $state;
    }

    /**
     * Lưu gathering state.
     */
    public function save_state( $session_id, $plugin_slug, array $state ) {
        $key = $this->state_key( $session_id, $plugin_slug );
        $state['updated_at'] = time();
        set_transient( $key, $state, self::GATHER_TIMEOUT );
        return true;
    }

    /**
     * Xóa gathering state (sau khi execute xong hoặc user cancel).
     */
    public function clear_state( $session_id, $plugin_slug ) {
        $key = $this->state_key( $session_id, $plugin_slug );
        delete_transient( $key );
    }

    /* ================================================================
     * 3. CORE GATHERING LOGIC
     * ================================================================ */

    /**
     * Khởi tạo gathering session khi user @ mention plugin lần đầu.
     *
     * Flow:
     *   1. Tra Tool Registry → lấy tools của plugin
     *   2. Nếu plugin có 1 tool → auto-select
     *   3. Nếu plugin có nhiều tools → chọn tool phù hợp nhất (LLM hoặc keyword)
     *   4. Parse schema → tạo state với required/optional fields
     *   5. Kiểm tra message ban đầu có entities nào extract được không
     *   6. Return state + ask prompt cho missing fields
     *
     * @param string $session_id     Session identifier
     * @param string $plugin_slug    Plugin được @ mention
     * @param string $message        Tin nhắn user gửi kèm @ mention
     * @param array  $context        Context bổ sung (user_id, channel, images...)
     * @return array                 Gathering result envelope
     */
    public function init_gathering( $session_id, $plugin_slug, $message, $context = [] ) {
        $tools = $this->get_tools_for_plugin( $plugin_slug );

        if ( empty( $tools ) ) {
            return [
                'status'  => 'error',
                'message' => "Plugin \"{$plugin_slug}\" không có tool nào trong Tool Registry.",
            ];
        }

        // Select tool: single → auto, multiple → match by message content
        if ( count( $tools ) === 1 ) {
            $selected_tool = $tools[0];
        } else {
            $selected_tool = $this->match_tool_from_message( $tools, $message );
        }

        if ( ! $selected_tool ) {
            // Không match được tool → liệt kê tools cho user chọn
            return $this->build_tool_selection_prompt( $tools, $plugin_slug );
        }

        $schema = $this->parse_tool_schema( $selected_tool );

        // Extract entities from initial message
        $extracted = $this->extract_entities_from_message( $message, $schema );

        // Build initial state
        $filled_slots = $extracted;
        $required_fields = $this->get_field_names( $schema['required'] );
        $optional_fields = $this->get_field_names( $schema['optional'] );

        $missing_required = [];
        foreach ( $required_fields as $field ) {
            if ( ! $this->slot_filled( $filled_slots, $field ) ) {
                $missing_required[] = $field;
            }
        }

        $missing_optional = [];
        foreach ( $optional_fields as $field ) {
            if ( ! $this->slot_filled( $filled_slots, $field ) ) {
                $missing_optional[] = $field;
            }
        }

        $state = [
            'session_id'       => $session_id,
            'plugin_slug'      => $plugin_slug,
            'goal'             => $schema['goal'],
            'goal_label'       => $schema['goal_label'],
            'tool_name'        => $schema['tool_name'],
            'callback'         => $schema['callback'],
            'required_fields'  => $required_fields,
            'optional_fields'  => $optional_fields,
            'input_schema'     => $schema['input_schema'],
            'filled_slots'     => $filled_slots,
            'missing_required' => $missing_required,
            'missing_optional' => $missing_optional,
            'status'           => empty( $missing_required ) ? 'ready' : 'gathering',
            'created_at'       => time(),
            'updated_at'       => time(),
            'turn_count'       => 1,
        ];

        $this->save_state( $session_id, $plugin_slug, $state );

        // If all required filled → ready to execute
        if ( empty( $missing_required ) ) {
            return $this->prepare_execution( $state, $context );
        }

        // Ask for missing fields
        return $this->build_ask_prompt( $state );
    }

    /**
     * Xử lý tin nhắn tiếp theo trong gathering flow.
     * User gửi data để fill missing fields.
     *
     * @param string $session_id
     * @param string $plugin_slug
     * @param string $message       User reply
     * @param array  $context       Context bổ sung
     * @return array                Gathering result envelope
     */
    public function continue_gathering( $session_id, $plugin_slug, $message, $context = [] ) {
        $state = $this->get_state( $session_id, $plugin_slug );

        if ( ! $state ) {
            // State expired hoặc chưa init → init lại
            return $this->init_gathering( $session_id, $plugin_slug, $message, $context );
        }

        if ( $state['status'] === 'executed' ) {
            // Đã execute xong → init flow mới
            $this->clear_state( $session_id, $plugin_slug );
            return $this->init_gathering( $session_id, $plugin_slug, $message, $context );
        }

        // Check cancel keywords
        if ( $this->is_cancel_message( $message ) ) {
            $this->clear_state( $session_id, $plugin_slug );
            return [
                'status'  => 'cancelled',
                'message' => "Đã hủy tác vụ \"{$state['goal_label']}\" cho plugin {$plugin_slug}.",
            ];
        }

        // Check skip keywords for optional fields
        $is_skip = $this->is_skip_message( $message );

        // Extract from message based on current missing fields
        $schema_subset = [
            'required'     => $this->filter_schema_by_fields( $state['input_schema'], $state['missing_required'] ),
            'optional'     => $this->filter_schema_by_fields( $state['input_schema'], $state['missing_optional'] ),
            'input_schema' => $state['input_schema'],
        ];

        if ( $is_skip ) {
            // Skip current optional field → move to next or execute
            if ( ! empty( $state['missing_optional'] ) ) {
                array_shift( $state['missing_optional'] );
            }
            $extracted = [];
        } else {
            $extracted = $this->extract_entities_from_message( $message, $schema_subset );

            // C2 Fix: Validate type before blind assignment to first missing field
            if ( empty( $extracted ) && ! empty( $state['missing_required'] ) ) {
                $first_field  = $state['missing_required'][0];
                $field_config = isset( $state['input_schema'][ $first_field ] )
                    ? $state['input_schema'][ $first_field ]
                    : array();
                $field_type   = isset( $field_config['type'] ) ? $field_config['type'] : 'text';

                if ( $this->message_matches_field_type( trim( $message ), $field_type, $field_config ) ) {
                    $extracted[ $first_field ] = trim( $message );
                } else {
                    // Type mismatch → re-ask with clear type hint
                    $label     = isset( $field_config['label'] ) ? $field_config['label'] : $first_field;
                    $type_hint = $this->get_type_hint_text( $field_type, $field_config );
                    $state['turn_count']++;
                    $this->save_state( $session_id, $plugin_slug, $state );

                    return array(
                        'status'           => 'gathering',
                        'action'           => 'ask_user',
                        'message'          => "Hmm, mình cần **{$label}** {$type_hint}. Bạn nhập lại giúp mình nhé! 🙏",
                        'plugin_slug'      => $state['plugin_slug'],
                        'goal'             => $state['goal'],
                        'goal_label'       => $state['goal_label'],
                        'filled_slots'     => $state['filled_slots'],
                        'missing_required' => $state['missing_required'],
                        'missing_optional' => $state['missing_optional'],
                        'turn_count'       => $state['turn_count'],
                        '_type_validation' => 'mismatch',
                        '_expected_type'   => $field_type,
                    );
                }
            } elseif ( empty( $extracted ) && ! empty( $state['missing_optional'] ) ) {
                $first_field  = $state['missing_optional'][0];
                $field_config = isset( $state['input_schema'][ $first_field ] )
                    ? $state['input_schema'][ $first_field ]
                    : array();
                $field_type   = isset( $field_config['type'] ) ? $field_config['type'] : 'text';

                if ( $this->message_matches_field_type( trim( $message ), $field_type, $field_config ) ) {
                    $extracted[ $first_field ] = trim( $message );
                } else {
                    // Optional field type mismatch → skip this field silently
                    array_shift( $state['missing_optional'] );
                }
            }
        }

        // Merge extracted into filled slots
        $state['filled_slots'] = array_merge( $state['filled_slots'], $extracted );

        // Recalculate missing fields
        $state['missing_required'] = [];
        foreach ( $state['required_fields'] as $field ) {
            if ( ! $this->slot_filled( $state['filled_slots'], $field ) ) {
                $state['missing_required'][] = $field;
            }
        }

        $state['missing_optional'] = [];
        foreach ( $state['optional_fields'] as $field ) {
            if ( ! $this->slot_filled( $state['filled_slots'], $field ) ) {
                $state['missing_optional'][] = $field;
            }
        }

        $state['turn_count']++;
        $state['status'] = empty( $state['missing_required'] ) ? 'ready' : 'gathering';

        $this->save_state( $session_id, $plugin_slug, $state );

        // Ready to execute?
        if ( $state['status'] === 'ready' ) {
            return $this->prepare_execution( $state, $context );
        }

        // Still gathering → ask for next missing field
        return $this->build_ask_prompt( $state );
    }

    /**
     * Main entry point: process message trong plugin context.
     * Tự động detect init vs continue.
     *
     * @param string $session_id
     * @param string $plugin_slug
     * @param string $message
     * @param array  $context
     * @return array  Gathering result envelope
     */
    public function process( $session_id, $plugin_slug, $message, $context = [] ) {
        $existing_state = $this->get_state( $session_id, $plugin_slug );

        if ( $existing_state && $existing_state['status'] === 'gathering' ) {
            return $this->continue_gathering( $session_id, $plugin_slug, $message, $context );
        }

        return $this->init_gathering( $session_id, $plugin_slug, $message, $context );
    }

    /* ================================================================
     * 4. EXECUTION BRIDGE
     * ================================================================ */

    /**
     * Chuẩn bị execution: all required slots filled → kick start tool.
     *
     * @param array $state    Gathering state
     * @param array $context  Extra context (user_id, channel, etc.)
     * @return array          Result envelope
     */
    private function prepare_execution( $state, $context = [] ) {
        $state['status'] = 'executed';
        $this->save_state( $state['session_id'], $state['plugin_slug'], $state );

        // Build slots for tool execution
        $slots = $state['filled_slots'];

        // Inject _meta context (compatible with Intent Engine's _meta pattern)
        $slots['_meta'] = [
            'session_id'    => $state['session_id'],
            'plugin_slug'   => $state['plugin_slug'],
            'gathering_mode'=> true,
            'channel'       => $context['channel'] ?? 'adminchat',
            'user_id'       => $context['user_id'] ?? 0,
            'message_id'    => $context['message_id'] ?? '',
            'blog_id'       => get_current_blog_id(),
        ];

        // Inject session_id and chat_id (standard slots for tools)
        $slots['session_id'] = $state['session_id'];
        $slots['chat_id']    = $context['message_id'] ?? '';

        return [
            'status'      => 'execute',
            'action'      => 'call_tool',
            'tool_name'   => $state['tool_name'],
            'goal'        => $state['goal'],
            'goal_label'  => $state['goal_label'],
            'plugin_slug' => $state['plugin_slug'],
            'callback'    => $state['callback'],
            'slots'       => $slots,
            'turn_count'  => $state['turn_count'],
        ];
    }

    /* ================================================================
     * 5. MESSAGE ANALYSIS & ENTITY EXTRACTION
     * ================================================================ */

    /**
     * Match tool phù hợp nhất từ message content.
     * Dùng keyword matching trên goal_description + title.
     *
     * @param array  $tools    Array of tool rows
     * @param string $message  User message
     * @return array|null      Matched tool or null
     */
    private function match_tool_from_message( $tools, $message ) {
        $msg_lower = mb_strtolower( $message, 'UTF-8' );
        $best_tool = null;
        $best_score = 0;

        foreach ( $tools as $tool ) {
            $score = 0;
            $tool_name = mb_strtolower( $tool['tool_name'] ?? '', 'UTF-8' );
            $title = mb_strtolower( $tool['title'] ?? '', 'UTF-8' );
            $desc = mb_strtolower( $tool['description'] ?? '', 'UTF-8' );
            $goal = mb_strtolower( $tool['goal'] ?? '', 'UTF-8' );
            $goal_desc = mb_strtolower( $tool['goal_description'] ?? '', 'UTF-8' );

            // Direct tool_name match
            if ( mb_strpos( $msg_lower, $tool_name ) !== false ) {
                $score += 10;
            }

            // Goal label match
            if ( mb_strpos( $msg_lower, mb_strtolower( $tool['goal_label'] ?? '', 'UTF-8' ) ) !== false ) {
                $score += 8;
            }

            // Keywords from title & description
            $keywords = array_filter( array_unique( array_merge(
                explode( ' ', $title ),
                explode( ' ', $goal_desc )
            ) ), function( $w ) { return mb_strlen( $w ) > 2; } );

            foreach ( $keywords as $kw ) {
                if ( mb_strpos( $msg_lower, $kw ) !== false ) {
                    $score += 1;
                }
            }

            if ( $score > $best_score ) {
                $best_score = $score;
                $best_tool = $tool;
            }
        }

        return $best_tool;
    }

    /**
     * Build prompt liệt kê tools cho user chọn (khi plugin có nhiều tools).
     */
    private function build_tool_selection_prompt( $tools, $plugin_slug ) {
        $lines = [ "Plugin **{$plugin_slug}** có " . count( $tools ) . " công cụ:" ];
        $i = 1;
        foreach ( $tools as $tool ) {
            $label = $tool['goal_label'] ?? $tool['title'] ?? $tool['tool_name'];
            $desc = $tool['description'] ?? $tool['goal_description'] ?? '';
            $lines[] = "{$i}. **{$label}** — {$desc}";
            $i++;
        }
        $lines[] = "\nBạn muốn dùng công cụ nào? Gõ số hoặc mô tả nhu cầu.";

        return [
            'status'  => 'select_tool',
            'message' => implode( "\n", $lines ),
            'tools'   => array_map( function( $t ) {
                return [
                    'tool_name'  => $t['tool_name'],
                    'goal'       => $t['goal'],
                    'goal_label' => $t['goal_label'] ?? $t['title'],
                ];
            }, $tools ),
        ];
    }

    /**
     * Extract entities từ message dựa trên input_schema.
     * Simple extraction: look for known patterns (numbers, URLs, etc.)
     *
     * @param string $message
     * @param array  $schema   Schema with 'required', 'optional', 'input_schema'
     * @return array           Extracted key-value pairs
     */
    private function extract_entities_from_message( $message, $schema ) {
        $extracted = [];
        $input_fields = $schema['input_schema'] ?? [];

        if ( empty( $input_fields ) || ! is_array( $input_fields ) ) {
            // Fallback: put whole message as first required field
            return [ '_raw_message' => trim( $message ) ];
        }

        $msg_trimmed = trim( $message );

        foreach ( $input_fields as $field_name => $field_config ) {
            if ( ! is_array( $field_config ) ) continue;

            $type = $field_config['type'] ?? 'text';

            switch ( $type ) {
                case 'number':
                case 'integer':
                    if ( preg_match( '/(\d[\d,\.]*)\s*(?:k|đ|vnđ|vnd|nghìn|triệu)?/iu', $msg_trimmed, $m ) ) {
                        $val = str_replace( [ ',', '.' ], '', $m[1] );
                        if ( stripos( $m[0], 'k' ) !== false || stripos( $m[0], 'nghìn' ) !== false ) {
                            $val *= 1000;
                        } elseif ( stripos( $m[0], 'triệu' ) !== false ) {
                            $val *= 1000000;
                        }
                        $extracted[ $field_name ] = intval( $val );
                    }
                    break;

                case 'url':
                case 'image':
                    if ( preg_match( '/(https?:\/\/[^\s]+)/i', $msg_trimmed, $m ) ) {
                        $extracted[ $field_name ] = $m[1];
                    }
                    break;

                case 'choice':
                    $choices = $field_config['choices'] ?? [];
                    foreach ( $choices as $choice ) {
                        if ( mb_stripos( $msg_trimmed, $choice ) !== false ) {
                            $extracted[ $field_name ] = $choice;
                            break;
                        }
                    }
                    break;

                case 'text':
                default:
                    // For text fields, assign the whole message if it's the primary field
                    if ( in_array( $field_name, [ 'topic', 'question', 'prompt', 'text', 'query', 'content' ], true ) ) {
                        $extracted[ $field_name ] = $msg_trimmed;
                    }
                    break;
            }
        }

        return $extracted;
    }

    /**
     * Build ask prompt cho missing fields.
     *
     * @param array $state  Gathering state
     * @return array        Result envelope with ask prompt
     */
    private function build_ask_prompt( $state ) {
        $missing = $state['missing_required'];
        $input_schema = $state['input_schema'] ?? [];

        if ( count( $missing ) > 1 ) {
            // Batch ask: liệt kê tất cả missing required fields
            $lines = [ "Để thực hiện **{$state['goal_label']}**, mình cần thêm:" ];
            $i = 1;
            foreach ( $missing as $field ) {
                $config = $input_schema[ $field ] ?? [];
                $label = $config['label'] ?? $config['prompt'] ?? $field;
                $type_hint = '';
                if ( ! empty( $config['type'] ) && $config['type'] !== 'text' ) {
                    $type_hint = " ({$config['type']})";
                }
                if ( ! empty( $config['choices'] ) ) {
                    $type_hint = ' (' . implode( ', ', $config['choices'] ) . ')';
                }
                $lines[] = "{$i}. **{$label}**{$type_hint}";
                $i++;
            }
            $lines[] = "\nGửi tất cả thông tin hoặc từng mục một nhé! 📝";
            $prompt = implode( "\n", $lines );
        } else {
            // Single ask
            $field = $missing[0];
            $config = $input_schema[ $field ] ?? [];
            $prompt = $config['prompt'] ?? "Vui lòng cung cấp: **{$field}**";
            if ( ! empty( $config['choices'] ) ) {
                $prompt .= "\nLựa chọn: " . implode( ', ', $config['choices'] );
            }
        }

        // Progress indicator
        $total = count( $state['required_fields'] );
        $filled = $total - count( $missing );
        $progress = $total > 0 ? "({$filled}/{$total} trường đã có)" : '';

        return [
            'status'           => 'gathering',
            'action'           => 'ask_user',
            'message'          => $prompt,
            'plugin_slug'      => $state['plugin_slug'],
            'goal'             => $state['goal'],
            'goal_label'       => $state['goal_label'],
            'filled_slots'     => $state['filled_slots'],
            'missing_required' => $state['missing_required'],
            'missing_optional' => $state['missing_optional'],
            'progress'         => $progress,
            'turn_count'       => $state['turn_count'],
        ];
    }

    /* ================================================================
     * 6. DB QUERY — INTENT CONVERSATION SCOPED + FALLBACK
     * ================================================================ */

    /**
     * Lấy messages trong phạm vi HIL loop (intent_conversation_id).
     * Ưu tiên intent_conversation_id → fallback session_id + plugin_slug.
     *
     * Đây là cơ chế focus: chỉ lấy messages thuộc cùng 1 goal execution,
     * không lẫn với messages từ goal khác trong cùng session.
     *
     * @param string $session_id
     * @param string $plugin_slug
     * @param int    $limit
     * @param string $intent_conversation_id  Optional UUID from intent_conversations.
     * @return array  Array of message objects
     */
    public function get_plugin_messages( $session_id, $plugin_slug, $limit = 50, $intent_conversation_id = '' ) {
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            $wc_db = BizCity_WebChat_Database::instance();

            // Primary: narrow scope by intent_conversation_id (HIL focus)
            if ( ! empty( $intent_conversation_id ) ) {
                $msgs = $wc_db->get_messages_by_intent_conversation_id( $intent_conversation_id, $limit );
                if ( ! empty( $msgs ) ) {
                    return $msgs;
                }
            }

            // Fallback: session + plugin_slug (pre-migration or no intent_conversation_id)
            return $wc_db->get_messages_by_session_and_plugin( $session_id, $plugin_slug, $limit );
        }

        // Fallback direct query
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        // Try intent_conversation_id first
        if ( ! empty( $intent_conversation_id ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts
                 FROM {$table}
                 WHERE intent_conversation_id = %s
                 ORDER BY id ASC
                 LIMIT %d",
                $intent_conversation_id,
                $limit
            ) );
            if ( ! empty( $rows ) ) {
                return $rows;
            }
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts
             FROM {$table}
             WHERE session_id = %s AND plugin_slug = %s
             ORDER BY id ASC
             LIMIT %d",
            $session_id,
            $plugin_slug,
            $limit
        ) );
    }

    /**
     * Lấy plugin_slug cuối cùng active trong session.
     * Dùng để maintain context continuity khi user không @ mention lại.
     *
     * @param string $session_id
     * @return string|null
     */
    public function get_last_active_plugin( $session_id ) {
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            return BizCity_WebChat_Database::instance()
                ->get_last_plugin_slug_in_session( $session_id );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT plugin_slug
             FROM {$table}
             WHERE session_id = %s AND plugin_slug != ''
             ORDER BY id DESC LIMIT 1",
            $session_id
        ) );
    }

    /**
     * Build conversation context từ plugin messages.
     * Dùng cho AI khi cần biết lịch sử trao đổi trong plugin context.
     *
     * @param string $session_id
     * @param string $plugin_slug
     * @param int    $max_messages
     * @return string  Condensed conversation text
     */
    public function build_plugin_context( $session_id, $plugin_slug, $max_messages = 12 ) {
        $messages = $this->get_plugin_messages( $session_id, $plugin_slug, $max_messages );

        if ( empty( $messages ) ) {
            return '';
        }

        $lines = [];
        foreach ( $messages as $msg ) {
            $from = $msg->message_from === 'user' ? 'User' : 'Bot';
            $text = mb_substr( $msg->message_text ?? '', 0, 200, 'UTF-8' );
            $lines[] = "[{$from}] {$text}";
        }

        return "## Plugin Context ({$plugin_slug})\n" . implode( "\n", $lines );
    }

    /* ================================================================
     * 7. HELPERS
     * ================================================================ */

    /**
     * Extract field names từ required/optional slots array.
     * Handles both { "field": {config} } và ["field1", "field2"] formats.
     */
    private function get_field_names( $slots ) {
        if ( empty( $slots ) ) return [];

        // Associative array: { "topic": {"type": "text", ...} }
        if ( is_array( $slots ) && ! isset( $slots[0] ) ) {
            return array_keys( $slots );
        }

        // Sequential array: ["topic", "keywords"]
        return array_values( $slots );
    }

    /**
     * Check if a slot is considered filled.
     */
    private function slot_filled( $slots, $field ) {
        if ( ! array_key_exists( $field, $slots ) ) return false;
        $val = $slots[ $field ];
        if ( is_array( $val ) ) return ! empty( $val );
        return $val !== '' && $val !== null;
    }

    /**
     * Check if message is a cancel command.
     */
    private function is_cancel_message( $message ) {
        $cancel_phrases = [ 'hủy', 'cancel', 'thôi', 'dừng', 'stop', 'thoát', 'exit', 'không làm nữa' ];
        $msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
        foreach ( $cancel_phrases as $phrase ) {
            if ( $msg_lower === $phrase || mb_strpos( $msg_lower, $phrase ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if message is a skip command.
     */
    private function is_skip_message( $message ) {
        $skip_phrases = [ 'bỏ qua', 'skip', 'bỏ', 'không cần', 'next', 'tiếp' ];
        $msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
        foreach ( $skip_phrases as $phrase ) {
            if ( $msg_lower === $phrase || mb_strpos( $msg_lower, $phrase ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filter input_schema to only include specific field names.
     */
    private function filter_schema_by_fields( $input_schema, $field_names ) {
        if ( ! is_array( $input_schema ) || empty( $field_names ) ) return [];

        $filtered = [];
        foreach ( $field_names as $field ) {
            if ( isset( $input_schema[ $field ] ) ) {
                $filtered[ $field ] = $input_schema[ $field ];
            }
        }
        return $filtered;
    }

    /* ================================================================
     * 7. OFF-TOPIC DETECTION (C1 Fix)
     * ================================================================ */

    /**
     * Assess if a user message is related to the current gathering goal.
     *
     * Uses layered heuristics (fast → slow):
     *   1. Cancel/skip/confirm keywords → 'related' (handled downstream)
     *   2. Emotional distress detection → 'off_topic' (let Intent Engine route safely)
     *   3. Short factual answer → 'related' (likely answering a field question)
     *   4. Question about the system/meta → 'off_topic'
     *   5. Type-matched answer → 'related' (message matches expected field type)
     *   6. Long unrelated text → 'ambiguous'
     *
     * @param string $message  User message
     * @param array  $state    Gathering state with goal, missing_required, input_schema
     * @return string          'related' | 'off_topic' | 'ambiguous'
     */
    private function assess_message_relevance( $message, $state ) {
        $msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
        $msg_len   = mb_strlen( $msg_lower );

        // 1. Cancel/skip phrases → 'related' (continue_gathering handles them)
        if ( $this->is_cancel_message( $message ) || $this->is_skip_message( $message ) ) {
            return 'related';
        }

        // 1b. Confirm phrases (from ambiguous prompt) → 'related'
        $confirm_phrases = array( 'tiếp tục', 'tiep tuc', 'continue', 'tiếp', '1' );
        foreach ( $confirm_phrases as $phrase ) {
            if ( $msg_lower === $phrase ) {
                return 'related';
            }
        }

        // 2. Emotional distress → 'off_topic' (safety-first: let Intent Engine handle)
        $emotion_signals = array(
            'buồn', 'khóc', 'chết', 'tự tử', 'muốn chết', 'chán', 'sợ', 'lo lắng',
            'stress', 'depressed', 'sad', 'cry', 'anxious', 'tuyệt vọng', 'cô đơn',
            'đau khổ', 'mệt mỏi', 'kiệt sức', 'ghét', 'tức giận', 'bực',
        );
        foreach ( $emotion_signals as $signal ) {
            if ( mb_strpos( $msg_lower, $signal ) !== false ) {
                return 'off_topic';
            }
        }

        // 3. Short answer (≤ 80 chars) → likely answering a field question
        if ( $msg_len <= 80 ) {
            // But check if it's a meta-question first
            $meta_patterns = array( 'chức năng', 'làm gì', 'là gì', 'thế nào', 'giải thích', 'help', 'hướng dẫn' );
            foreach ( $meta_patterns as $pattern ) {
                if ( mb_strpos( $msg_lower, $pattern ) !== false ) {
                    return 'off_topic';
                }
            }
            return 'related';
        }

        // 4. Long meta-questions about the system → 'off_topic'
        $meta_long_patterns = array(
            'plugin này', 'tool này', 'bot này', 'assistant', 'trợ lý',
            'đang làm gì', 'hoạt động', 'tại sao', 'vì sao',
        );
        $meta_count = 0;
        foreach ( $meta_long_patterns as $pattern ) {
            if ( mb_strpos( $msg_lower, $pattern ) !== false ) {
                $meta_count++;
            }
        }
        if ( $meta_count >= 2 ) {
            return 'off_topic';
        }

        // 5. Type match check — does the message look like a valid value for a missing field?
        $missing = $state['missing_required'];
        if ( empty( $missing ) ) {
            $missing = $state['missing_optional'];
        }
        $schema = $state['input_schema'] ?? array();
        foreach ( $missing as $field ) {
            if ( ! isset( $schema[ $field ] ) ) continue;
            $type = $schema[ $field ]['type'] ?? 'text';
            if ( $this->message_matches_field_type( $msg_lower, $type, $schema[ $field ] ) ) {
                return 'related';
            }
        }

        // 6. Long text (> 80 chars) with no type match → ambiguous
        if ( $msg_len > 120 ) {
            return 'ambiguous';
        }

        // Default: short-to-medium message, no clear off-topic signal → related
        return 'related';
    }

    /**
     * Get human-readable type hint text for re-ask prompts.
     *
     * @param string $type         Field type
     * @param array  $field_config Field configuration from input_schema
     * @return string              Type hint text in Vietnamese
     */
    private function get_type_hint_text( $type, $field_config = array() ) {
        switch ( $type ) {
            case 'number':
            case 'integer':
                return '(một con số)';

            case 'url':
                return '(một đường link URL)';

            case 'image':
                return '(link hình ảnh URL)';

            case 'date':
                return '(ngày tháng, ví dụ: 2025-01-15)';

            case 'email':
                return '(email, ví dụ: user@example.com)';

            case 'choice':
            case 'select':
                $choices = isset( $field_config['choices'] ) ? $field_config['choices'] : array();
                if ( ! empty( $choices ) ) {
                    return '(chọn: ' . implode( ', ', $choices ) . ')';
                }
                return '(chọn một trong các tùy chọn)';

            default:
                return '';
        }
    }

    /**
     * Check if message content matches the expected field type.
     *
     * @param string $message_lower  Lowercased message
     * @param string $type           Field type: text, number, url, choice, etc.
     * @param array  $field_config   Field configuration from input_schema
     * @return bool
     */
    private function message_matches_field_type( $message_lower, $type, $field_config ) {
        switch ( $type ) {
            case 'number':
            case 'integer':
                return (bool) preg_match( '/\d+/', $message_lower );

            case 'url':
            case 'image':
                return (bool) preg_match( '/https?:\/\//i', $message_lower );

            case 'choice':
                $choices = $field_config['choices'] ?? array();
                foreach ( $choices as $choice ) {
                    if ( mb_stripos( $message_lower, mb_strtolower( $choice, 'UTF-8' ) ) !== false ) {
                        return true;
                    }
                }
                return false;

            case 'date':
                return (bool) preg_match( '/(\d{1,4}[-\/]\d{1,2}[-\/]\d{1,4}|hôm nay|ngày mai|hôm qua|mai|mốt)/iu', $message_lower );

            case 'text':
            default:
                // Text type always matches (user could be providing any text)
                return true;
        }
    }

    /* ================================================================
     * 8. TOOL SUGGESTION — Auto-suggest @tool when routing is ambiguous
     * ================================================================ */

    /**
     * Suggest a tool for a message using keyword matching.
     * Returns suggestion prompt for user to confirm, or null if no good match.
     *
     * Called by Intent Engine or Gateway when routing_priority = 'balanced'
     * and the message seems execution-like but no @mention was used.
     *
     * @param string $message      User message
     * @param string $session_id   Session for context
     * @return array|null          Suggestion envelope or null
     */
    public function suggest_tool_for_message( $message, $session_id = '' ) {
        global $wpdb;

        // Read routing priority setting
        $routing_priority = get_option( 'bizcity_tcp_routing_priority', 'balanced' );

        // In 'conversation' mode, don't suggest tools unless very confident
        if ( $routing_priority === 'conversation' ) {
            return null;
        }

        // Query active tools
        $table = $wpdb->prefix . 'bizcity_tool_registry';
        if ( ! $this->table_exists( $table ) ) {
            return null;
        }

        $tools = $wpdb->get_results(
            "SELECT tool_name, title, goal, goal_label, goal_description, plugin, description
             FROM {$table}
             WHERE active = 1
             ORDER BY priority ASC
             LIMIT 50",
            ARRAY_A
        );

        if ( empty( $tools ) ) {
            return null;
        }

        $best_tool  = null;
        $best_score = 0;
        $min_score  = ( $routing_priority === 'tools' ) ? 3 : 5;

        // Keyword matching same pattern as match_tool_from_message but against all tools
        $msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );

        foreach ( $tools as $tool ) {
            $score = 0;
            $tool_name = mb_strtolower( $tool['tool_name'] ?? '', 'UTF-8' );
            $goal_label = mb_strtolower( $tool['goal_label'] ?? '', 'UTF-8' );
            $goal_desc = mb_strtolower( $tool['goal_description'] ?? '', 'UTF-8' );
            $title = mb_strtolower( $tool['title'] ?? '', 'UTF-8' );

            if ( $tool_name && mb_strpos( $msg_lower, $tool_name ) !== false ) {
                $score += 10;
            }
            if ( $goal_label && mb_strpos( $msg_lower, $goal_label ) !== false ) {
                $score += 8;
            }

            $keywords = array_filter( array_unique( array_merge(
                explode( ' ', $title ),
                explode( ' ', $goal_desc )
            ) ), function( $w ) { return mb_strlen( $w ) > 2; } );

            foreach ( $keywords as $kw ) {
                if ( mb_strpos( $msg_lower, $kw ) !== false ) {
                    $score += 1;
                }
            }

            if ( $score > $best_score ) {
                $best_score = $score;
                $best_tool  = $tool;
            }
        }

        if ( ! $best_tool || $best_score < $min_score ) {
            return null;
        }

        // Build suggestion prompt
        $slug = $best_tool['plugin'] ?? '';
        $label = $best_tool['goal_label'] ?? $best_tool['title'] ?? $best_tool['goal'];

        return array(
            'status'      => 'tool_suggestion',
            'message'     => "Có vẻ bạn muốn sử dụng **{$label}**? "
                . "Gõ `@{$slug}` để xác nhận, hoặc tiếp tục trò chuyện bình thường.",
            'plugin_slug' => $slug,
            'tool_name'   => $best_tool['tool_name'],
            'goal'        => $best_tool['goal'],
            'goal_label'  => $label,
            'score'       => $best_score,
        );
    }

    /**
     * Check nếu bảng DB tồn tại.
     */
    private function table_exists( $table ) {
        global $wpdb;
        return $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
    }

    /**
     * Debug: dump state for logging.
     */
    public function debug_state( $session_id, $plugin_slug ) {
        $state = $this->get_state( $session_id, $plugin_slug );
        if ( ! $state ) return '[no gathering state]';

        return wp_json_encode( [
            'plugin'    => $state['plugin_slug'],
            'goal'      => $state['goal'],
            'status'    => $state['status'],
            'filled'    => array_keys( $state['filled_slots'] ),
            'missing_r' => $state['missing_required'],
            'missing_o' => $state['missing_optional'],
            'turns'     => $state['turn_count'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
    }
}

endif; // class_exists
