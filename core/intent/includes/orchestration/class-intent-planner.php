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
 * BizCity Intent — Flow Planner
 *
 * Given a classified intent + conversation state, determines the next action:
 *   • ask_user       — need more info from user (specify which field)
 *   • call_tool      — all slots filled, execute tool
 *   • compose_answer — delegate to AI brain for a natural response
 *   • complete       — goal is done, close conversation
 *   • passthrough    — no goal, just forward to AI for small talk
 *
 * Each goal type has a "plan" — an ordered list of required/optional slots
 * and the sequence of steps to execute.
 *
 * @package BizCity_Intent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Planner {

    /** @var self|null */
    private static $instance = null;

    /**
     * Goal plans — maps goal_id → plan config.
     *
     * Plan config:
     *   'required_slots'  => [ field_name => [ 'type', 'prompt_vi', ... ] ]
     *   'optional_slots'  => [ ... ]
     *   'tool'            => 'tool_name' (registered in Tool Registry)
     *   'ai_compose'      => true|false (use AI brain after tool execution)
     *   'slot_order'      => [ ... ] (order to ask for missing slots)
     *
     * @var array
     */
    private $plans = [];

    /**
     * Whether plans have been fully resolved (filters applied).
     *
     * @var bool
     */
    private $plans_resolved = false;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init_plans();
    }

    /**
     * Initialize built-in goal plans.
     */
    private function init_plans() {
        $this->plans = [

            // NOTE: tarot_reading & tarot_interpret plans are provided by
            // BizCity_Tarot_Intent_Provider (via bizcity_intent_plans filter).
            // Do NOT duplicate them here.

            // ── PLUGIN-OWNED PLANS (NOT defined here) ──────────────────────
            // The following goals are fully owned by plugins that register
            // plans via bizcity_intent_plans filter (array_merge overlay).
            // Removing built-in duplicates reduces ~200 lines of dead code.
            //
            // tool-content: write_article, write_seo_article, rewrite_article,
            //               translate_and_publish, schedule_post
            // tool-woo:     create_product, edit_product, create_order,
            //               find_customer, customer_stats, product_stats,
            //               inventory_report, warehouse_receipt, order_stats
            // ────────────────────────────────────────────────────────────────

            /* ── Daily Outlook ── */
            'daily_outlook' => [
                'required_slots' => [
                    'focus_area' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn biết dự báo về mảng nào? ✨',
                        'choices' => [
                            'tinh_cam'  => '💕 Tình cảm',
                            'su_nghiep' => '💼 Sự nghiệp',
                            'tai_chinh' => '💰 Tài chính',
                            'tong_quan' => '🌟 Tổng quan tất cả',
                        ],
                        'default' => 'tong_quan',
                    ],
                ],
                'optional_slots' => [
                    'time_range' => [
                        'type'    => 'choice',
                        'prompt'  => 'Khoảng thời gian nào? 📅',
                        'choices' => [
                            'today'      => 'Hôm nay',
                            'this_week'  => 'Tuần này',
                            'this_month' => 'Tháng này',
                        ],
                        'default' => 'today',
                    ],
                ],
                'tool'       => null,
                'ai_compose' => true,
                'slot_order' => [ 'focus_area', 'time_range' ],
            ],

            /* ── Astro Forecast ── */
            'astro_forecast' => [
                'required_slots' => [],
                'optional_slots' => [
                    'forecast_type' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn xem loại dự báo nào?',
                        'choices' => [
                            'natal'   => '🌟 Natal Chart (bản đồ sao)',
                            'transit' => '🔄 Transit (vận hành)',
                        ],
                    ],
                    'time_range' => [
                        'type'    => 'text',
                        'prompt'  => 'Khoảng thời gian nào bạn muốn xem?',
                    ],
                ],
                'tool'       => null,
                'ai_compose' => true,
                'slot_order' => [ 'forecast_type', 'time_range' ],
            ],

            /* ── Report ── */
            'report' => [
                'required_slots' => [
                    'report_type' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn xem báo cáo nào? 📊',
                        'choices' => [
                            'revenue'   => '💰 Doanh thu',
                            'orders'    => '📦 Đơn hàng',
                            'products'  => '📋 Sản phẩm',
                            'customers' => '👥 Khách hàng',
                        ],
                    ],
                ],
                'optional_slots' => [
                    'date_range' => [
                        'type'   => 'text',
                        'prompt' => 'Khoảng thời gian? (ví dụ: 7 ngày, tháng này)',
                    ],
                ],
                'tool'       => 'generate_report',
                'ai_compose' => true,
                'slot_order' => [ 'report_type', 'date_range' ],
            ],

            /* ── Post to Facebook ── */
            'post_facebook' => [
                'required_slots' => [
                    'content' => [
                        'type'   => 'text',
                        'prompt' => 'Nội dung bài đăng là gì? ✍️',
                    ],
                ],
                'optional_slots' => [
                    'image_url' => [
                        'type'   => 'image',
                        'prompt' => 'Có ảnh đính kèm không? 📸',
                    ],
                ],
                'tool'       => 'post_facebook',
                'ai_compose' => false,
                'slot_order' => [ 'content', 'image_url' ],
            ],

            /* ── Set Reminder ── */
            'set_reminder' => [
                'required_slots' => [
                    'what' => [
                        'type'   => 'text',
                        'prompt' => __( 'Nhắc việc gì? 🔔', 'bizcity-twin-ai' ),
                    ],
                    'when' => [
                        'type'   => 'text',
                        'prompt' => __( 'Khi nào? (ví dụ: 3 giờ chiều, ngày mai, ...)', 'bizcity-twin-ai' ),
                    ],
                ],
                'optional_slots' => [
                    'repeat' => [
                        'type'    => 'choice',
                        'prompt'  => __( 'Lặp lại?', 'bizcity-twin-ai' ),
                        'choices' => [
                            'once'  => __( 'Một lần', 'bizcity-twin-ai' ),
                            'daily' => __( 'Hàng ngày', 'bizcity-twin-ai' ),
                            'weekly'=> __( 'Hàng tuần', 'bizcity-twin-ai' ),
                        ],
                        'default' => 'once',
                    ],
                ],
                'tool'       => 'set_reminder',
                'ai_compose' => false,
                'slot_order' => [ 'what', 'when', 'repeat' ],
            ],

            /* ── List Orders ── */
            'list_orders' => [
                'required_slots' => [],
                'optional_slots' => [
                    'date_range' => [
                        'type'   => 'text',
                        'prompt' => 'Khoảng thời gian? (ví dụ: hôm nay, tuần này)',
                    ],
                    'status_filter' => [
                        'type'    => 'choice',
                        'prompt'  => 'Lọc theo trạng thái?',
                        'choices' => [
                            'all'        => 'Tất cả',
                            'pending'    => 'Chờ xử lý',
                            'processing' => 'Đang xử lý',
                            'completed'  => 'Hoàn thành',
                        ],
                        'default' => 'all',
                    ],
                ],
                'tool'       => 'list_orders',
                'ai_compose' => true,
                'slot_order' => [ 'date_range', 'status_filter' ],
            ],

            /* ── Create Video (Kling) ── */
            'create_video' => [
                'required_slots' => [
                    'content' => [
                        'type'   => 'text',
                        'prompt' => 'Mô tả nội dung video bạn muốn tạo? 🎬',
                    ],
                ],
                'optional_slots' => [
                    'title' => [
                        'type'   => 'text',
                        'prompt' => 'Tiêu đề video?',
                    ],
                    'duration' => [
                        'type'    => 'choice',
                        'prompt'  => 'Thời lượng video?',
                        'choices' => [
                            '5'  => '5 giây',
                            '10' => '10 giây',
                        ],
                        'default' => '5',
                    ],
                    'aspect_ratio' => [
                        'type'    => 'choice',
                        'prompt'  => 'Tỷ lệ khung hình?',
                        'choices' => [
                            '9:16' => '9:16 (TikTok/Reels)',
                            '16:9' => '16:9 (YouTube)',
                            '1:1'  => '1:1 (Vuông)',
                        ],
                        'default' => '9:16',
                    ],
                    'image_url' => [
                        'type'   => 'image',
                        'prompt' => 'Gửi ảnh tham chiếu nếu có 📸',
                    ],
                ],
                'tool'       => 'create_video',
                'ai_compose' => true,
                'slot_order' => [ 'content', 'title', 'duration', 'aspect_ratio', 'image_url' ],
            ],

            /* ── Inventory Journal ── */
            'inventory_journal' => [
                'required_slots' => [],
                'optional_slots' => [
                    'from_date' => [
                        'type'   => 'text',
                        'prompt' => 'Từ ngày nào?',
                    ],
                    'to_date' => [
                        'type'   => 'text',
                        'prompt' => 'Đến ngày nào?',
                    ],
                    'so_ngay' => [
                        'type'    => 'text',
                        'prompt'  => 'Xem trong bao nhiêu ngày?',
                        'default' => '7',
                    ],
                ],
                'tool'       => 'inventory_journal',
                'ai_compose' => true,
                'slot_order' => [ 'so_ngay', 'from_date', 'to_date' ],
            ],

            /* ── Help Guide ── */
            'help_guide' => [
                'required_slots' => [],
                'optional_slots' => [
                    'topic' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn cần hướng dẫn về chủ đề gì? 📖',
                        'choices' => [
                            'tat_ca'    => '📋 Tất cả',
                            'don_hang'  => '📦 Đơn hàng',
                            'san_pham'  => '🏷️ Sản phẩm',
                            'bao_cao'   => '📊 Báo cáo',
                            'facebook'  => '📘 Facebook',
                            'video'     => '🎬 Video',
                        ],
                        'default' => 'tat_ca',
                    ],
                ],
                'tool'       => 'help_guide',
                'ai_compose' => false,
                'slot_order' => [ 'topic' ],
            ],
        ];

        // NOTE: filters are applied lazily in resolve_plans() so that
        // Provider plans registered after Engine construction are included.
    }

    /* ================================================================
     *  Main planning method
     * ================================================================ */

    /**
     * Determine the next action for a conversation.
     *
     * @param array $conversation Normalized conversation data.
     * @param array $intent       Classification result from Router.
     * @return array {
     *   @type string $action         'ask_user' | 'call_tool' | 'compose_answer' | 'complete' | 'passthrough'
     *   @type string $ask_field      Field name to ask for (when action=ask_user).
     *   @type string $ask_prompt     Question to show user.
     *   @type string $ask_type       Field type: 'text' | 'image' | 'choice' | 'confirm'
     *   @type array  $ask_choices    Choices if ask_type=choice.
     *   @type string $tool_name      Tool to execute (when action=call_tool).
     *   @type array  $tool_slots     Complete slots for tool input.
     *   @type bool   $ai_compose     Whether to use AI brain for response.
     *   @type array  $missing_fields List of still-missing fields.
     * }
     */
    public function plan( array $conversation, array $intent ) {
        $result = [
            'action'         => 'passthrough',
            'ask_field'      => '',
            'ask_prompt'     => '',
            'ask_type'       => 'text',
            'ask_choices'    => [],
            'tool_name'      => '',
            'tool_slots'     => [],
            'ai_compose'     => true,
            'missing_fields' => [],
        ];

        $goal = $intent['goal'] ?? $conversation['goal'] ?? '';

        // ── No goal → passthrough to AI brain (small talk) ──
        if ( empty( $goal ) || $intent['intent'] === 'small_talk' ) {
            $result['action']     = 'passthrough';
            $result['ai_compose'] = true;
            return $result;
        }

        // ── End conversation ──
        if ( $intent['intent'] === 'end_conversation' ) {
            $result['action'] = 'complete';
            return $result;
        }

        // ── Get plan for this goal ──
        $plan = $this->get_plan( $goal );
        if ( ! $plan ) {
            // Unknown goal — let AI handle it
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-PLANNER] plan NOT FOUND for goal="' . $goal . '" → compose_answer'
                         . ' | plans_resolved=' . ( $this->plans_resolved ? 'true' : 'false' )
                         . ' | registered_plans=[' . implode( ',', array_keys( $this->plans ) ) . ']'
                         . ' | intent=' . ( $intent['intent'] ?? '?' )
                         . ' | conv_goal=' . ( $conversation['goal'] ?? '' ) );
            }
            $result['action']     = 'compose_answer';
            $result['ai_compose'] = true;
            return $result;
        }

        // ── Phase 1.2: Skill required_inputs merge ──
        // Promote skill's required_inputs to top of slot_order,
        // elevate optional→required if skill declares them.
        if ( ! empty( $intent['skill_match'] ) && ( $intent['skill_match']['archetype'] ?? 'A' ) !== 'A' ) {
            $sm = $intent['skill_match'];

            // Promote skill required_inputs to top of slot_order
            if ( ! empty( $sm['required_inputs'] ) && is_array( $sm['required_inputs'] ) ) {
                $plan['slot_order'] = array_unique(
                    array_merge( $sm['required_inputs'], $plan['slot_order'] ?? [] )
                );

                // Elevate optional→required if skill says so
                foreach ( $sm['required_inputs'] as $field ) {
                    if ( isset( $plan['optional_slots'][ $field ] ) ) {
                        $plan['required_slots'][ $field ] = $plan['optional_slots'][ $field ];
                        unset( $plan['optional_slots'][ $field ] );
                    }
                }
            }

            // Archetype C: set workflow execution mode + attach skill instructions
            if ( ( $sm['archetype'] ?? '' ) === 'C' ) {
                $plan['execution_mode']     = 'workflow';
                $plan['skill_instructions'] = $sm['skill']['content'] ?? '';
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-PLANNER] Phase 1.2: Skill merge applied'
                    . ' | archetype=' . ( $sm['archetype'] ?? '?' )
                    . ' | required_inputs=' . wp_json_encode( $sm['required_inputs'] ?? [] ) );
            }
        }

        // ── Merge current slots + newly extracted entities ──
        $current_slots = $conversation['slots'] ?? [];
        $new_entities  = $intent['entities'] ?? [];
        $merged_slots  = array_merge( $current_slots, $new_entities );

        // Handle array slots (e.g. card_images)
        foreach ( $plan['optional_slots'] as $field => $config ) {
            if ( ! empty( $config['is_array'] ) ) {
                $existing = $current_slots[ $field ] ?? [];
                $new_val  = $new_entities[ $field ] ?? [];
                if ( ! is_array( $existing ) ) {
                    $existing = [];
                }
                if ( ! is_array( $new_val ) ) {
                    $new_val = $new_val ? [ $new_val ] : [];
                }
                $merged_slots[ $field ] = array_merge( $existing, $new_val );
            }
        }

        // Handle _images → map to image slots
        if ( ! empty( $new_entities['_images'] ) ) {
            foreach ( array_merge( $plan['required_slots'], $plan['optional_slots'] ) as $field => $config ) {
                if ( ( $config['type'] ?? '' ) === 'image' ) {
                    if ( ! empty( $config['is_array'] ) ) {
                        $existing = $merged_slots[ $field ] ?? [];
                        if ( ! is_array( $existing ) ) {
                            $existing = [];
                        }
                        $merged_slots[ $field ] = array_merge( $existing, $new_entities['_images'] );
                    } else {
                        $merged_slots[ $field ] = $new_entities['_images'][0] ?? '';
                    }
                    break; // Fill first image slot found
                }
            }
        }

        // ── O4: Multi-slot scan — pre-fill skippable optionals from raw message (v3.6.1) ──
        // "Viết bài về AI, ảnh tự tạo nhé" → skip image_url BEFORE being asked.
        $raw_message = $intent['raw_message'] ?? '';
        if ( $raw_message !== '' && ! empty( $plan['optional_slots'] ) ) {
            foreach ( $plan['optional_slots'] as $field => $config ) {
                if ( ! $this->slot_filled( $merged_slots, $field ) ) {
                    if ( $this->message_implies_skip( $raw_message, $field, $config ) ) {
                        $merged_slots[ $field ] = $config['default'] ?? '';
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( "[INTENT-PLANNER] O4 multi-slot skip: field={$field} from message scan" );
                        }
                    }
                }
            }
        }

        // ── O5: Smart slot ordering — auto-skip secondary optionals when context is rich (v3.6.1) ──
        $primary_keys = [ 'topic', 'message', 'content', 'description', 'name' ];
        foreach ( $primary_keys as $pk ) {
            if ( isset( $merged_slots[ $pk ] ) && is_string( $merged_slots[ $pk ] ) && mb_strlen( $merged_slots[ $pk ], 'UTF-8' ) > 100 ) {
                $secondary_keys = [ 'tone', 'length', 'style', 'format' ];
                foreach ( $secondary_keys as $sk ) {
                    if ( isset( $plan['optional_slots'][ $sk ] ) && ! $this->slot_filled( $merged_slots, $sk ) ) {
                        $merged_slots[ $sk ] = $plan['optional_slots'][ $sk ]['default'] ?? '';
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( "[INTENT-PLANNER] O5 auto-skip secondary: {$sk} (primary {$pk} >100 chars)" );
                        }
                    }
                }
                break;
            }
        }

        // ── Check required slots ──
        $missing_required = [];
        foreach ( $plan['required_slots'] as $field => $config ) {
            if ( ! $this->slot_filled( $merged_slots, $field ) ) {
                $missing_required[] = $field;
            }
        }

        $result['tool_slots']     = $merged_slots;
        $result['missing_fields'] = $missing_required;

        // ── If required slots missing → batch-ask all missing at once ──
        if ( ! empty( $missing_required ) ) {
            // Use slot_order if defined, otherwise follow required order
            $ask_order = $plan['slot_order'] ?? array_keys( $plan['required_slots'] );

            // Sort missing fields by slot_order
            $ordered_missing = [];
            foreach ( $ask_order as $f ) {
                if ( in_array( $f, $missing_required, true ) ) {
                    $ordered_missing[] = $f;
                }
            }
            // Add any remaining that weren't in slot_order
            foreach ( $missing_required as $f ) {
                if ( ! in_array( $f, $ordered_missing, true ) ) {
                    $ordered_missing[] = $f;
                }
            }

            // Primary ask_field = first missing (for backward compat)
            $ask_field    = $ordered_missing[0];
            $field_config = $plan['required_slots'][ $ask_field ] ?? [];

            // ── Batch mode: If multiple missing required fields, present them all ──
            if ( count( $ordered_missing ) > 1 ) {
                $lines = [];
                $idx   = 1;
                foreach ( $ordered_missing as $f ) {
                    $fc     = $plan['required_slots'][ $f ] ?? [];
                    $prompt = $fc['prompt'] ?? ucfirst( str_replace( '_', ' ', $f ) );
                    $lines[] = "{$idx}. {$prompt}";
                    $idx++;
                }
                $batch_prompt = "Để thực hiện, mình cần các thông tin sau:\n\n"
                    . implode( "\n", $lines )
                    . "\n\nBạn có thể trả lời tất cả cùng lúc hoặc từng mục nhé! 😊";

                $result['action']        = 'ask_user';
                $result['ask_field']     = $ask_field;
                $result['ask_fields']    = $ordered_missing; // NEW: all missing fields
                $result['ask_prompt']    = $batch_prompt;
                $result['ask_type']      = 'text'; // batch always text
                $result['ask_choices']   = [];
                $result['batch_mode']    = true;   // NEW: signal batch ask
                $result['ai_compose']    = ! empty( $plan['ai_compose'] );
                $result['goal']          = $goal;

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[INTENT-PLANNER] batch-ask: missing=' . implode( ',', $ordered_missing ) );
                }
                return $result;
            }

            // Single required field missing → original behavior
            $result['action']      = 'ask_user';
            $result['ask_field']   = $ask_field;
            $result['ask_prompt']  = $field_config['prompt'] ?? "Vui lòng cung cấp: {$ask_field}";
            $result['ask_type']    = $field_config['type']   ?? 'text';
            $result['ask_choices'] = $field_config['choices'] ?? [];
            $result['ai_compose']  = ! empty( $plan['ai_compose'] );
            $result['goal']        = $goal;
            return $result;
        }

        // ── All required slots filled ──
        // Skip optional slot asking when in confirmation flow.
        // _awaiting_confirm means user already saw the slot summary and is
        // responding (e.g. "ok" or corrections). Re-asking optional slots
        // would create an infinite loop (planner returns ask_user instead
        // of call_tool, so the _awaiting_confirm check never runs).
        $awaiting_confirm = ! empty( $merged_slots['_awaiting_confirm'] );

        // Check if any optional slots in slot_order should be asked
        $slot_order = $plan['slot_order'] ?? [];
        foreach ( $slot_order as $field ) {
            if ( $awaiting_confirm ) break;
            // Skip required slots (already handled)
            if ( isset( $plan['required_slots'][ $field ] ) ) {
                continue;
            }
            // Check optional slots that have prompts and aren't filled yet
            if ( isset( $plan['optional_slots'][ $field ] ) ) {
                $config = $plan['optional_slots'][ $field ];

                // ── O2: Universal skip detection (v3.6.0) ──
                // If slot IS filled but value is a skip phrase → clear and fill default.
                // Works for ALL types: text ("bỏ qua"), image (no URL), choice ("skip").
                if ( $this->slot_filled( $merged_slots, $field ) ) {
                    if ( $this->is_skip_phrase( $merged_slots[ $field ], $config ) ) {
                        $merged_slots[ $field ] = $config['default'] ?? '';
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( "[INTENT-PLANNER] skip_on detected: field={$field} → default='" . ( $config['default'] ?? '' ) . "'" );
                        }
                        continue; // Move to next field in slot_order
                    }
                    continue; // Slot is validly filled → move on
                }

                // Only ask if: has prompt, not filled, and no waiting_field match (not just answered)
                if ( ! empty( $config['prompt'] ) ) {
                    // Check if this field was just answered with a "skip" phrase
                    $waiting_field = $conversation['waiting_field'] ?? '';
                    if ( $waiting_field !== $field ) {
                        // Not waiting for this field → ask for it
                        $result['action']      = 'ask_user';
                        $result['ask_field']   = $field;
                        $result['ask_prompt']  = $config['prompt'];
                        $result['ask_type']    = $config['type'] ?? 'text';
                        $result['ask_choices'] = $config['choices'] ?? [];
                        $result['ai_compose']  = ! empty( $plan['ai_compose'] );
                        $result['goal']        = $goal;
                        $result['tool_slots']  = $merged_slots;
                        return $result;
                    }
                    // else: waiting_field === field → user just answered/skipped → move on
                }
            }
        }

        // Fill defaults for optional slots that are empty
        foreach ( $plan['optional_slots'] as $field => $config ) {
            if ( ! $this->slot_filled( $merged_slots, $field ) && isset( $config['default'] ) ) {
                $merged_slots[ $field ] = $config['default'];
            }
        }
        $result['tool_slots'] = $merged_slots;

        // ── O9: Validate slot values BEFORE executing tool (v3.6.0) ──
        // Check required slots against type rules. Invalid → ask_user with error.
        if ( ! empty( $plan['tool'] ) ) {
            $all_slot_configs = array_merge( $plan['required_slots'], $plan['optional_slots'] );
            foreach ( $all_slot_configs as $field => $config ) {
                // Only validate filled slots (empty optionals are OK → already defaulted)
                if ( ! $this->slot_filled( $merged_slots, $field ) ) {
                    continue;
                }
                $validation_error = $this->validate_slot( $field, $merged_slots[ $field ], $config );
                if ( $validation_error ) {
                    $result['action']      = 'ask_user';
                    $result['ask_field']   = $field;
                    $result['ask_prompt']  = $validation_error;
                    $result['ask_type']    = $config['type'] ?? 'text';
                    $result['ask_choices'] = $config['choices'] ?? [];
                    $result['ai_compose']  = ! empty( $plan['ai_compose'] );
                    $result['goal']        = $goal;
                    $result['tool_slots']  = $merged_slots;
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( "[INTENT-PLANNER] slot validation failed: field={$field} error={$validation_error}" );
                    }
                    return $result;
                }
            }
        }

        // ── Execute tool or compose via AI ──
        if ( ! empty( $plan['tool'] ) ) {
            $result['action']     = 'call_tool';
            $result['tool_name']  = $plan['tool'];
            $result['ai_compose'] = ! empty( $plan['ai_compose'] );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-PLANNER] → call_tool: tool=' . $plan['tool']
                         . ' | goal=' . $goal
                         . ' | slots=' . wp_json_encode( $merged_slots, JSON_UNESCAPED_UNICODE ) );
            }
        } else {
            $result['action']     = 'compose_answer';
            $result['ai_compose'] = true;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-PLANNER] → compose_answer (no tool) | goal=' . $goal );
            }
        }

        return $result;
    }

    /* ================================================================
     *  Plan management
     * ================================================================ */

    /**
     * Get plan for a goal.
     *
     * @param string $goal
     * @return array|null
     */
    public function get_plan( $goal ) {
        $this->resolve_plans();
        // Strip followup: prefix so "followup:write_article" resolves to the "write_article" plan
        $lookup = preg_replace( '/^(followup:)+/', '', $goal );
        return $this->plans[ $lookup ] ?? $this->plans[ $goal ] ?? null;
    }

    /**
     * Register or override a plan.
     *
     * @param string $goal
     * @param array  $plan
     */
    public function register_plan( $goal, array $plan ) {
        $this->plans[ $goal ] = $plan;
    }

    /**
     * Get all plans.
     *
     * @return array
     */
    public function get_plans() {
        $this->resolve_plans();
        return $this->plans;
    }

    /**
     * Lazily apply the bizcity_intent_plans filter.
     *
     * Deferred so that Provider Registry's boot() (which adds the filter)
     * runs before the filter is actually evaluated. This solves the
     * timing issue where Engine construction triggers init_plans()
     * before providers have registered their plans.
     */
    private function resolve_plans() {
        if ( $this->plans_resolved ) {
            return;
        }
        $this->plans_resolved = true;
        $this->plans = apply_filters( 'bizcity_intent_plans', $this->plans );
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    /**
     * Check if a slot is considered "filled" (non-empty).
     *
     * @param array  $slots
     * @param string $field
     * @return bool
     */
    private function slot_filled( array $slots, $field ) {
        if ( ! array_key_exists( $field, $slots ) ) {
            return false;
        }
        $val = $slots[ $field ];
        if ( is_array( $val ) ) {
            return ! empty( $val );
        }
        return $val !== '' && $val !== null;
    }

    /**
     * Check if a slot value is a "skip" phrase (v3.6.0 — O2).
     *
     * Uses `skip_on` from config if available, otherwise falls back to
     * default skip phrases. Works for all slot types.
     *
     * @param mixed $value  The slot value to check.
     * @param array $config The field config (from plan required/optional slots).
     * @return bool True if the value matches a skip phrase.
     */
    private function is_skip_phrase( $value, array $config ): bool {
        if ( is_array( $value ) || ! is_string( $value ) ) {
            return false;
        }

        $value_lower = mb_strtolower( trim( $value ), 'UTF-8' );
        if ( $value_lower === '' ) {
            return false;
        }

        // Use explicit skip_on from config, or default skip phrases
        $skip_phrases = $config['skip_on'] ?? [
            'bỏ qua', 'skip', 'không', 'không cần', 'ko', 'ko cần',
            'auto', 'tự tạo', 'next', 'tiếp', 'tiếp tục',
            'không có', 'ko có', 'bỏ', 'thôi', 'qua',
        ];

        foreach ( $skip_phrases as $phrase ) {
            if ( mb_strtolower( trim( $phrase ), 'UTF-8' ) === $value_lower ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a user's raw message implies skipping a specific optional slot (v3.6.1 — O4).
     *
     * Scans the full message for contextual skip phrases related to the field type.
     * E.g. "ảnh tự tạo nhé" matches image type fields → skip.
     *
     * @param string $message  Raw user message.
     * @param string $field    Field name.
     * @param array  $config   Field config (type, label, skip_on).
     * @return bool True if the message implies skipping this field.
     */
    private function message_implies_skip( string $message, string $field, array $config ): bool {
        $msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
        if ( $msg_lower === '' ) {
            return false;
        }

        $type = $config['type'] ?? 'text';

        // Type-specific contextual skip patterns
        if ( $type === 'image' ) {
            $patterns = [
                'ảnh tự tạo', 'tự tạo ảnh', 'không cần ảnh', 'ko cần ảnh',
                'bỏ qua ảnh', 'skip ảnh', 'ảnh auto', 'không ảnh', 'ko ảnh',
                'ảnh bỏ qua', 'tự chọn ảnh', 'auto ảnh', 'hình tự tạo',
                'không cần hình', 'hình auto', 'bỏ qua hình',
            ];
            foreach ( $patterns as $p ) {
                if ( mb_strpos( $msg_lower, $p ) !== false ) {
                    return true;
                }
            }
        }

        // Generic: skip phrases combined with field name or label
        $field_label = mb_strtolower( $config['label'] ?? $field, 'UTF-8' );
        $field_alts  = [ $field_label, str_replace( '_', ' ', $field ) ];
        $skip_verbs  = [ 'bỏ qua', 'skip', 'không cần', 'ko cần', 'tự tạo', 'auto', 'bỏ' ];

        foreach ( $field_alts as $fl ) {
            foreach ( $skip_verbs as $verb ) {
                // "bỏ qua ảnh" or "ảnh tự tạo" patterns
                if ( mb_strpos( $msg_lower, $verb . ' ' . $fl ) !== false
                    || mb_strpos( $msg_lower, $fl . ' ' . $verb ) !== false
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate a slot value against its type rules (v3.6.0 — O9).
     *
     * Returns an error message string if invalid, or empty string if valid.
     * This prevents invalid data (price = "abc", image = "not-a-url") from
     * reaching tool callbacks, which would cause runtime failures.
     *
     * @param string $field  Field name.
     * @param mixed  $value  Field value.
     * @param array  $config Field config (type, choices, etc.).
     * @return string Error message if invalid, empty string if valid.
     */
    private function validate_slot( string $field, $value, array $config ): string {
        $type = $config['type'] ?? 'text';

        // Skip validation for empty values (optionals already defaulted)
        if ( $value === '' || $value === null ) {
            return '';
        }

        // Skip validation for arrays (e.g. card_images) — handled separately
        if ( is_array( $value ) ) {
            return '';
        }

        switch ( $type ) {
            case 'number':
                if ( ! is_numeric( $value ) ) {
                    $label = $config['prompt'] ?? $field;
                    return "⚠️ \"{$field}\" phải là một **số**. Bạn nhập \"{$value}\" — xin nhập lại (ví dụ: 150000):";
                }
                break;

            case 'image':
                // Image slots should be a valid URL or empty (already defaulted)
                if ( ! empty( $value ) && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
                    return "⚠️ Ảnh phải là **link URL** (bắt đầu bằng https://...). Gửi lại hoặc gõ \"bỏ qua\" nhé:";
                }
                break;

            case 'choice':
                if ( ! empty( $config['choices'] ) && is_array( $config['choices'] ) ) {
                    $value_lower = mb_strtolower( trim( $value ), 'UTF-8' );
                    // Check both key and display value
                    $found = false;
                    foreach ( $config['choices'] as $key => $display ) {
                        // Plain arrays (numeric auto-keys) → valid values are $display, not $key
                        $check_key = is_int( $key ) ? (string) $display : (string) $key;
                        if ( mb_strtolower( $check_key, 'UTF-8' ) === $value_lower
                            || mb_strtolower( (string) $display, 'UTF-8' ) === $value_lower
                        ) {
                            $found = true;
                            break;
                        }
                    }
                    if ( ! $found ) {
                        $options = implode( ', ', array_values( $config['choices'] ) );
                        return "⚠️ Vui lòng chọn một trong: {$options}";
                    }
                }
                break;

            case 'date':
                // Basic date validation — accept common formats
                if ( ! preg_match( '/\d{1,4}[-\/]\d{1,2}[-\/]\d{1,4}|\d{1,2}[-\/]\d{1,2}|\b\d{8}\b/u', $value )
                    && ! preg_match( '/hôm nay|hôm qua|tuần|tháng|ngày|today|yesterday|week|month/ui', $value )
                ) {
                    return "⚠️ Ngày không hợp lệ. Nhập theo dạng DD/MM/YYYY hoặc \"hôm nay\", \"tuần này\":";
                }
                break;

            case 'text':
            default:
                // Text fields: just ensure non-empty (already passed slot_filled)
                break;
        }

        return '';
    }
}
