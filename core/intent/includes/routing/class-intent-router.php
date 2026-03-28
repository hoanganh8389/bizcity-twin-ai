<?php
/**
 * BizCity Intent — Intent Router
 *
 * Classifies each incoming message into an intent category:
 *   • small_talk     — casual greeting, chit-chat, general questions
 *   • new_goal       — user wants to start a task (create product, report, tarot, etc.)
 *   • continue_goal  — user is providing info for an active conversation goal
 *   • provide_input  — user is providing a specific input we asked for (image, choice, text)
 *   • end_conversation — user says thanks/bye/stop, closing the topic
 *
 * Uses a hybrid approach:
 *   1. Pattern matching for common/fast intents (no LLM cost)
 *   2. Conversation context analysis (has active goal? waiting for input?)
 *   3. LLM classification via bizcity_openrouter (purpose: 'router') for ambiguous cases
 *
 * @package BizCity_Intent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Router {

    /** @var self|null */
    private static $instance = null;

    /**
     * Known goal patterns — maps regex to goal identifiers.
     * Plugins can extend via `bizcity_intent_goal_patterns` filter.
     *
     * @var array
     */
    private $goal_patterns = [];

    /**
     * Whether patterns have been fully resolved (filters applied).
     *
     * @var bool
     */
    private $patterns_resolved = false;

    /**
     * End-conversation patterns.
     *
     * @var array
     */
    private $end_patterns = [];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init_patterns();
    }

    /**
     * Initialize built-in pattern sets.
     */
    private function init_patterns() {
        // ── Goal patterns: regex => [ goal_id, label, description, default_slots ] ──
        // PRIMARY PURPOSE (v3.5.2): Goal metadata (label, description, extract fields)
        // used by build_unified_schema_for_llm() and get_goal_slot_schema().
        // SECONDARY PURPOSE: Regex patterns are FALLBACK ONLY — used in Steps 3a-3e
        // when LLM is completely unavailable (no API key, network error).
        // All primary intent classification is LLM-based.
        $this->goal_patterns = [
            // Product management
            '/tạo sản phẩm|đăng sản phẩm|thêm sản phẩm|tao san pham/ui' => [
                'goal' => 'create_product', 'label' => 'Tạo sản phẩm',
                'description' => 'Tạo sản phẩm WooCommerce MỚI (áo, quần, giày, đồ ăn, dịch vụ...) với tên, giá, mô tả, ảnh',
                'extract' => [ 'title', 'price', 'description', 'image_url' ],
            ],

            // Reports & statistics
            '/(?:báo cáo|thống kê)(?!\s*(?:calo|dinh\s*d|calories|kcal|bữa|hàng\s*hóa|khách\s*hàng))|report|doanh thu|doanh số/ui' => [
                'goal' => 'report', 'label' => 'Báo cáo thống kê',
                'description' => 'Báo cáo doanh thu, thống kê bán hàng tổng quan',
                'extract' => [ 'report_type', 'date_range', 'metric' ],
            ],
            '/xuất nhập tồn|xnt|tồn kho|inventory/ui' => [
                'goal' => 'inventory_report', 'label' => 'Báo cáo xuất nhập tồn',
                'description' => 'Xem báo cáo xuất nhập tồn kho, số lượng hàng còn, hàng đã bán',
                'extract' => [ 'from_date', 'to_date', 'so_ngay' ],
            ],

            // Orders
            '/danh sách đơn hàng|đơn hàng|orders/ui' => [
                'goal' => 'list_orders', 'label' => 'Xem đơn hàng',
                'description' => 'Xem danh sách đơn hàng, tra cứu đơn hàng theo trạng thái/ngày',
                'extract' => [ 'date_range', 'status_filter' ],
            ],

            // FB / Social
            '/đăng facebook|đăng fb|post facebook|đăng bài facebook/ui' => [
                'goal' => 'post_facebook', 'label' => 'Đăng bài Facebook',
                'description' => 'Đăng bài lên trang Facebook (nội dung, ảnh, link)',
                'extract' => [ 'content', 'image_url', 'page_id' ],
            ],
            // write_article: use \b boundaries + negative lookahead to avoid
            // false positives like "giải bài tarot", "xem bài tarot", etc.
            '/(?:^|\b)(?:viết bài|viet bai|soạn bài|tạo bài viết)(?!\s+tarot)(?!\s+bói)\b|(?:^|\b)đăng bài(?!\s+tarot)(?!\s+bói)\b/ui' => [
                'goal' => 'write_article', 'label' => 'Viết bài',
                'description' => 'Viết bài blog/content MỚI và đăng lên WordPress',
                'extract' => [ 'topic', 'tone', 'length' ],
            ],

            // Tarot / Astrology
            '/chiêm tinh|tử vi|bói(?!\s*bài)|hoa tinh|natal|transit|phong thủy/ui' => [
                'goal' => 'astro_forecast', 'label' => 'Dự báo chiêm tinh',
                'description' => 'Xem tử vi, chiêm tinh, phong thủy, natal chart, transit',
                'extract' => [ 'forecast_type', 'time_range', 'focus_area' ],
            ],
            '/hôm nay thế nào|thế nào hôm nay|dự báo vận|xem vận|vận mệnh hôm nay|(?:ngày mai|tuần này|tuần sau|tháng này|tháng sau|năm tới|năm nay)\s*(?:thế nào|ra sao|như thế nào|vận thế nào)/ui' => [
                'goal' => 'daily_outlook', 'label' => 'Dự báo vận mệnh',
                'description' => 'Dự báo vận mệnh hôm nay/ngày mai/tuần này/tháng này',
                'extract' => [ 'time_range', 'focus_area' ],
            ],

            // Customer
            '/tìm khách hàng|khách hàng|customer|danh sách kh/ui' => [
                'goal' => 'find_customer', 'label' => 'Tìm khách hàng',
                'description' => 'Tìm kiếm/tra cứu khách hàng theo SĐT, tên, email',
                'extract' => [ 'search_term', 'phone', 'name' ],
            ],

            // Schedule
            '/nhắc việc|reminder|nhắc nhở|hẹn lịch|lên lịch/ui' => [
                'goal' => 'set_reminder', 'label' => 'Nhắc việc',
                'description' => 'Đặt nhắc việc, hẹn lịch, reminder vào thời gian cụ thể',
                'extract' => [ 'what', 'when', 'repeat' ],
            ],

            // Video creation (Kling)
            '/tạo video|làm video|quay video|video sản phẩm|video quảng cáo|tao video|video clip/ui' => [
                'goal' => 'create_video', 'label' => 'Tạo video',
                'description' => 'Tạo video sản phẩm, video quảng cáo, video clip từ ảnh/mô tả',
                'extract' => [ 'title', 'content', 'duration', 'image_url' ],
            ],

            // Edit product
            '/sửa sản phẩm|edit product|chỉnh sửa sp|cập nhật sp|update product/ui' => [
                'goal' => 'edit_product', 'label' => 'Sửa sản phẩm',
                'description' => 'Sửa/cập nhật thông tin sản phẩm WooCommerce đã có (tên, giá, mô tả, ảnh)',
                'extract' => [ 'product_id', 'field', 'new_value' ],
            ],

            // Customer stats
            '/thống kê khách hàng|top khách|khách hàng vip|customer stats/ui' => [
                'goal' => 'customer_stats', 'label' => 'Thống kê khách hàng',
                'description' => 'Thống kê top khách hàng mua nhiều nhất, khách VIP',
                'extract' => [ 'so_ngay', 'from_date', 'to_date' ],
            ],

            // Product stats
            '/thống kê hàng hóa|top sản phẩm|hàng bán chạy|product stats/ui' => [
                'goal' => 'product_stats', 'label' => 'Thống kê hàng hóa',
                'description' => 'Top sản phẩm bán chạy nhất, thống kê hàng hóa theo thời gian',
                'extract' => [ 'so_ngay', 'from_date', 'to_date' ],
            ],

            // Inventory journal
            '/nhật ký xuất nhập|nhật ký kho|nhat ky xnt|inventory journal/ui' => [
                'goal' => 'inventory_journal', 'label' => 'Nhật ký xuất nhập',
                'description' => 'Xem nhật ký xuất nhập kho theo thời gian',
                'extract' => [ 'from_date', 'to_date', 'so_ngay' ],
            ],

            // Warehouse receipt
            '/nhập kho|phiếu nhập|nhap kho|warehouse receipt/ui' => [
                'goal' => 'warehouse_receipt', 'label' => 'Nhập kho',
                'description' => 'Tạo phiếu nhập kho hàng hóa (tên SP, số lượng, giá mua)',
                'extract' => [ 'content' ],
            ],

            // Create order
            '/tạo đơn hàng|đơn hàng mới|tạo đơn|tao don/ui' => [
                'goal' => 'create_order', 'label' => 'Tạo đơn hàng',
                'description' => 'Tạo đơn hàng WooCommerce mới (khách hàng, SĐT, sản phẩm, địa chỉ)',
                'extract' => [ 'customer_name', 'products', 'phone' ],
            ],

            // Help / Guide
            '/hướng dẫn|hdsd|help|cách sử dụng|guide/ui' => [
                'goal' => 'help_guide', 'label' => 'Hướng dẫn sử dụng',
                'description' => 'Xem hướng dẫn sử dụng, FAQ, hỗ trợ tính năng',
                'extract' => [ 'topic' ],
            ],
        ];

        // NOTE: filters are applied lazily in resolve_patterns() so that
        // Provider patterns registered after Engine construction are included.

        // ── End patterns ──
        // These patterns detect when user wants to end/close the conversation.
        // Uses \b (word boundary) and negative lookaheads to avoid false triggers
        // like "thôi nào bắt đầu đi", "hủy nào bắt đầu", "xong chưa?" etc.
        //
        // Vietnamese continuation particles that NEGATE cancel intent:
        //   nào, đi, à, chưa, vậy, nhưng, mà, rồi + verb, được, thì, nha
        $this->end_patterns = [
            // Core closing phrases - can appear at start or mid-sentence
            '/\b(cảm ơn|cam on|thank|thanks)\s*(bạn|you|nhé|nhe|nhiều|lắm|ạ)?\s*$/ui',
            // "thôi", "dừng", "stop" etc. — ONLY when NOT followed by continuation words
            '/^(bye|tạm biệt|xong rồi|ok xong|done)\b(?!\s*(nào|đi|à|chưa|vậy|nhưng|mà|thì|nha|nhé))/ui',
            '/^(thôi|dừng|stop|hủy)\b(?!\s*(nào|đi|à|bỏ đi|vậy|nhưng|mà|thì|nha|nhé|ta|mình|bắt đầu|làm))/ui',
            '/^(đủ rồi|không cần nữa|ko cần|hết rồi)\b(?!\s*(nào|à|chưa|vậy|nhưng|mà))/ui',
            '/^(xong|ok rồi|tuyệt vời)\s*$/ui',
            // "ok" followed by closing: "ok cảm ơn", "ok bye", "ok thôi"
            '/^ok\s+(cảm ơn|cam on|thanks?|bye|thôi|xong)\s*$/ui',
            // Short gratitude at end of conversation
            '/^(cám ơn|cmon|thanks|thks|tks|ty)\s*$/ui',
        ];
    }

    /* ================================================================
     *  Main classification method
     * ================================================================ */

    /**
     * Classify a message in the context of an active conversation.
     *
     * @param string     $message       User's message text.
     * @param array|null $conversation  Active conversation data (from Conversation Manager).
     * @param array      $attachments   Images/files attached.
     * @return array {
     *   @type string $intent      'small_talk' | 'new_goal' | 'continue_goal' | 'provide_input' | 'end_conversation'
     *   @type string $goal        Goal identifier (for new_goal).
     *   @type string $goal_label  Human label.
     *   @type array  $entities    Extracted entities/slot values.
     *   @type float  $confidence  0.0 - 1.0
     *   @type string $method      'pattern' | 'context' | 'llm' (how it was classified)
     * }
     */
    public function classify( $message, $conversation = null, $attachments = [], $provider_hint = '', $mode_result = null, $context = [] ) {
        // Ensure provider patterns are merged (lazy resolution)
        $this->resolve_patterns();

        // ── Resolve market slug → provider ID (e.g. 'bizcity-tarot' → 'tarot') ──
        if ( $provider_hint && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $provider_hint = BizCity_Intent_Provider_Registry::instance()->resolve_slug( $provider_hint );
        }

        $result = [
            'intent'     => 'small_talk',
            'goal'       => '',
            'goal_label' => '',
            'entities'   => [],
            'confidence' => 0.5,
            'method'     => 'default',
        ];

        // ── Debug tracking for router console ──
        $debug = [
            'classify_step'          => 'default',
            'matched_pattern'        => '',
            'pattern_source'         => '',
            'active_goal'            => $conversation['goal'] ?? '',
            'active_goal_status'     => $conversation['status'] ?? '',
            'pattern_count'          => count( $this->goal_patterns ),
            'provider_pattern_count' => 0,
            'all_goal_candidates'    => [],
            'provider_hint'          => $provider_hint,
        ];

        $message_lower = mb_strtolower( trim( $message ), 'UTF-8' );
        $has_active_goal = $conversation
            && ! empty( $conversation['goal'] )
            && in_array( $conversation['status'], [ 'ACTIVE', 'WAITING_USER' ], true );

        // ── v4.2: Cross-provider @mention — disable WAITING_USER traps ──
        // When user explicitly @mentions a DIFFERENT provider than the active goal,
        // the @mention is AUTHORITATIVE. Disable $has_active_goal so that:
        // - Step 0.5 won't force provide_input for the wrong goal
        // - Step 2 won't override small_talk → provide_input for wrong goal
        // - Step 3a won't trap into WAITING_USER provide_input
        // The original $conversation is preserved for Engine Step 4b (goal switch).
        $is_cross_provider_mention = false;
        if ( $provider_hint && $has_active_goal && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $xp_registry = BizCity_Intent_Provider_Registry::instance();
            $xp_owner    = $xp_registry->get_provider_for_goal( $conversation['goal'] );
            if ( $xp_owner && $xp_owner->get_id() !== $provider_hint ) {
                $is_cross_provider_mention = true;
                $has_active_goal = false;
                $debug['cross_provider_mention'] = true;
                $debug['cross_provider_from']    = $xp_owner->get_id();
                $debug['cross_provider_to']      = $provider_hint;
                error_log( '[bizcity-router] v4.2 Cross-provider @mention: '
                    . $conversation['goal'] . ' (' . $xp_owner->get_id() . ') → @' . $provider_hint
                    . ' — WAITING_USER traps disabled' );
            }
        }

        // ══════════════════════════════════════════════════════════════════════
        //  CENTRALIZED WAITING_USER GUARD (v4.3.7)
        //
        //  When the conversation is WAITING_USER on the SAME provider/goal,
        //  ALL fast-path shortcuts (Step -1, Step 0, Step 0.5) would previously
        //  need individual guards to prevent returning new_goal (which resets
        //  slots and causes infinite loops).
        //
        //  This single guard runs BEFORE any Step and handles:
        //    - Slash command focus mode sending same tool_goal every message
        //    - @mention / provider_hint re-detecting the same single-goal provider
        //    - Unified LLM pre-classification returning new_goal for active goal
        //
        //  If activate: returns provide_input immediately (0 LLM cost).
        //  Cross-provider mentions are excluded ($is_cross_provider_mention).
        // ══════════════════════════════════════════════════════════════════════
        if ( $has_active_goal
             && ! $is_cross_provider_mention
             && $conversation['status'] === 'WAITING_USER'
        ) {
            // Check if any fast-path would route to the SAME goal as active
            $tool_goal_ctx   = $context['tool_goal'] ?? '';
            $same_goal_hint  = false;

            // (a) Slash command targets the same goal
            if ( $tool_goal_ctx && $tool_goal_ctx === $conversation['goal'] ) {
                $same_goal_hint = true;
            }

            // (b) Provider hint points to a single-goal provider → that goal = active goal
            if ( ! $same_goal_hint && $provider_hint && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
                $cg_registry = BizCity_Intent_Provider_Registry::instance();
                $cg_provider = $cg_registry->get( $provider_hint );
                if ( $cg_provider ) {
                    $cg_goals = [];
                    foreach ( $this->goal_patterns as $cfg ) {
                        $g = $cfg['goal'] ?? '';
                        if ( empty( $g ) ) continue;
                        $owner = $cg_registry->get_provider_for_goal( $g );
                        if ( $owner && $owner->get_id() === $provider_hint ) {
                            $cg_goals[] = $g;
                        }
                    }
                    if ( in_array( $conversation['goal'], $cg_goals, true ) ) {
                        $same_goal_hint = true;
                    }
                }
            }

            if ( $same_goal_hint ) {
                $result['intent']     = 'provide_input';
                $result['goal']       = $conversation['goal'];
                $result['goal_label'] = $conversation['goal_label'] ?? $conversation['goal'];
                $result['confidence'] = 0.95;
                $result['method']     = 'centralized_waiting_guard';
                $result['entities']   = [];

                // ── Handle image attachments when waiting for an image slot ──
                $awaiting_img_cg = ( $conversation['waiting_for'] ?? '' ) === 'image';
                if ( ! empty( $attachments ) && $awaiting_img_cg ) {
                    $result['entities']['_images']       = $attachments;
                    $result['entities']['_waiting_field'] = $conversation['waiting_field'];
                    $result['confidence'] = 0.95;
                    if ( ! empty( trim( $message ) ) ) {
                        $this->extract_text_entities_alongside_image( $result, $message, $conversation );
                    }
                } else {
                    $this->fill_waiting_field_entities( $result, $message, $conversation );
                    // v4.6.1: Also capture images when waiting for non-image slots.
                    // Plans like calo_log_meal accept both text AND image input.
                    if ( ! empty( $attachments ) ) {
                        $result['entities']['_images'] = $attachments;
                    }
                }

                $debug['classify_step'] = 'step_guard_waiting_user';
                $debug['waiting_field'] = $conversation['waiting_field'] ?? '';
                $debug['guard_trigger'] = $tool_goal_ctx ? 'tool_goal' : 'provider_hint';
                $result['_debug']       = $debug;
                return $result;
            }
        }

        // ── Step -1: / SLASH COMMAND — Direct tool targeting (skip all classification) ──
        // When user selects a tool via / command, tool_goal is set directly.
        // No LLM needed — 0 cost, instant routing to the exact goal.
        // @since v4.0.0 (Phase 13 — Dual Context Architecture)
        $tool_goal = $context['tool_goal'] ?? '';
        if ( $tool_goal ) {
            // Find the goal config in patterns
            $matched_config = null;
            foreach ( $this->goal_patterns as $cfg ) {
                if ( ( $cfg['goal'] ?? '' ) === $tool_goal ) {
                    $matched_config = $cfg;
                    break;
                }
            }

            if ( $matched_config ) {
                // ── v4.2.1: Same-goal WAITING_USER → provide_input (prevent slot reset loop) ──
                // When frontend focus mode sends tool_goal on every message but the
                // conversation already has the same goal in WAITING_USER, creating
                // new_goal would reset slots and loop "please provide X" endlessly.
                // Route as provide_input so the waited slot gets filled properly.
                if ( $has_active_goal
                     && $conversation['goal'] === $tool_goal
                     && $conversation['status'] === 'WAITING_USER'
                ) {
                    $result['intent']     = 'provide_input';
                    $result['goal']       = $tool_goal;
                    $result['goal_label'] = $conversation['goal_label'] ?? $matched_config['label'] ?? $tool_goal;
                    $result['confidence'] = 1.0;
                    $result['method']     = 'slash_command_direct';
                    $result['entities']   = [];

                    // ── Handle image attachments when waiting for an image slot ──
                    $awaiting_img_sc = ( $conversation['waiting_for'] ?? '' ) === 'image';
                    if ( ! empty( $attachments ) && $awaiting_img_sc ) {
                        $result['entities']['_images']       = $attachments;
                        $result['entities']['_waiting_field'] = $conversation['waiting_field'];
                        $result['confidence'] = 1.0;
                        if ( ! empty( trim( $message ) ) ) {
                            $this->extract_text_entities_alongside_image( $result, $message, $conversation );
                        }
                    } else {
                        // Fill the waited slot from user message
                        $this->fill_waiting_field_entities( $result, $message, $conversation );
                        // v4.6.1: Also capture images when waiting for non-image slots.
                        if ( ! empty( $attachments ) ) {
                            $result['entities']['_images'] = $attachments;
                        }
                    }
                    $debug['classify_step'] = 'step_neg1_slash_command_provide_input';
                    $debug['tool_goal']     = $tool_goal;
                    $debug['waiting_field'] = $conversation['waiting_field'] ?? '';
                    $result['_debug'] = $debug;
                    error_log( '[bizcity-router] Step -1: Slash command same-goal WAITING_USER → provide_input'
                             . ' | goal=' . $tool_goal
                             . ' | waiting_field=' . ( $conversation['waiting_field'] ?? '' ) );
                    return $result;
                }

                $result['intent']     = 'new_goal';
                $result['goal']       = $tool_goal;
                $result['goal_label'] = $matched_config['label'] ?? $tool_goal;
                $result['confidence'] = 1.0;
                $result['method']     = 'slash_command_direct';
                $result['entities']   = [ '_raw_message' => $message ];
                $debug['classify_step'] = 'step_neg1_slash_command';
                $debug['tool_goal']  = $tool_goal;
                $result['_debug'] = $debug;
                error_log( '[bizcity-router] Step -1: Slash command → goal=' . $tool_goal . ' (0 cost)' );
                return $result;
            } else {
                // tool_goal not found in patterns — log warning, fall through to normal classification
                error_log( '[bizcity-router] Step -1: Slash command tool_goal "' . $tool_goal . '" not found in goal_patterns — falling through' );
                $debug['slash_command_warning'] = 'tool_goal not found: ' . $tool_goal;
            }
        }

        // ── Step 0: @MENTION SHORTCUT — Skip LLM if provider_hint targets single-goal provider ──
        // When user uses @mention, if the targeted provider has exactly ONE goal,
        // we can skip LLM entirely (0 cost) and return new_goal directly.
        // If provider has multiple goals, we still need LLM but with bias.
        if ( $provider_hint && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $registry = BizCity_Intent_Provider_Registry::instance();
            $provider = $registry->get( $provider_hint );
            if ( $provider ) {
                $provider_goals = [];
                // Collect goals that belong to this provider
                foreach ( $this->goal_patterns as $config ) {
                    $goal = $config['goal'] ?? '';
                    if ( empty( $goal ) ) continue;
                    $owner = $registry->get_provider_for_goal( $goal );
                    if ( $owner && $owner->get_id() === $provider_hint ) {
                        $provider_goals[ $goal ] = $config;
                    }
                }

                if ( count( $provider_goals ) === 1 ) {
                    $goal_config  = reset( $provider_goals );
                    $single_goal  = $goal_config['goal'];

                    // ── v4.3.6: WAITING_USER guard — prevent slot-reset loop ──
                    // Same logic as Step -1 (slash command): if the conversation
                    // already has this goal in WAITING_USER, treat as provide_input
                    // so the user's reply fills the waited slot (or confirms execution).
                    if ( $has_active_goal
                         && $conversation['goal'] === $single_goal
                         && $conversation['status'] === 'WAITING_USER'
                    ) {
                        $result['intent']     = 'provide_input';
                        $result['goal']       = $single_goal;
                        $result['goal_label'] = $conversation['goal_label'] ?? $goal_config['label'] ?? $single_goal;
                        $result['confidence'] = 0.95;
                        $result['method']     = 'provider_hint_single_goal';
                        $result['entities']   = [];
                        $this->fill_waiting_field_entities( $result, $message, $conversation );
                        $debug['classify_step'] = 'step0_provider_hint_single_provide_input';
                        $debug['provider_hint_goals'] = array_keys( $provider_goals );
                        $debug['waiting_field'] = $conversation['waiting_field'] ?? '';
                        $result['_debug'] = $debug;
                        return $result;
                    }

                    // Single goal, no active WAITING_USER → skip LLM entirely (0 cost)
                    $result['intent']     = 'new_goal';
                    $result['goal']       = $single_goal;
                    $result['goal_label'] = $goal_config['label'] ?? $single_goal;
                    $result['confidence'] = 0.95;
                    $result['method']     = 'provider_hint_single_goal';
                    // Try to extract entities from message using slot names
                    $extract = $goal_config['extract'] ?? [];
                    if ( ! empty( $extract ) ) {
                        $result['entities'] = [ '_raw_message' => $message ];
                    }
                    $debug['classify_step'] = 'step0_provider_hint_single';
                    $debug['provider_hint_goals'] = array_keys( $provider_goals );
                    $result['_debug'] = $debug;
                    return $result;
                }

                // Multiple goals → store for LLM bias (handled in classify_with_llm_primary)
                $debug['provider_hint_goals'] = array_keys( $provider_goals );
            }
        }

        // ── Step 0.5: UNIFIED MODE+INTENT — Pre-classified by Mode Classifier (v3.5) ──
        // The Mode Classifier's single LLM call already identified intent + goal.
        // If the goal is valid in our registry → use it directly, skip Router LLM.
        // This saves one full LLM round-trip (~2-3s) for execution intents.
        // v3.7: Allow Step 0.5 even when provider_hint is set for multi-goal providers.
        // Step 0 only shortcuts for single-goal providers. Multi-goal providers
        // (e.g. tarot with tarot_reading + tarot_interpret) still need classification,
        // and the unified LLM result from Mode Classifier is the best source.
        if ( $mode_result
             && ! empty( $mode_result['meta']['intent_result'] )
             && ! empty( $mode_result['meta']['intent_result']['goal'] )
        ) {
            $pre       = $mode_result['meta']['intent_result'];
            $pre_goal  = $pre['goal'];
            $pre_conf  = floatval( $pre['confidence'] ?? 0 );

            // Validate goal exists in our registry (exact match first, then fuzzy)
            $goal_config = $this->find_goal_config( $pre_goal );
            if ( ! $goal_config ) {
                $fuzzy = $this->fuzzy_match_goal( $pre_goal );
                if ( $fuzzy ) {
                    $pre_goal    = $fuzzy['goal'];
                    $goal_config = $fuzzy;
                }
            }

            // ── Provider scope guard (v4.0): When @mention targets a specific provider,
            // reject unified LLM goals that belong to a DIFFERENT provider.
            // This prevents "xem cho tôi" @bizcoach → misrouting to knowledge/other plugin.
            if ( $goal_config && $provider_hint && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
                $goal_owner = BizCity_Intent_Provider_Registry::instance()->get_provider_for_goal( $pre_goal );
                if ( $goal_owner && $goal_owner->get_id() !== $provider_hint ) {
                    // Goal belongs to different provider → reject, fall through
                    error_log( '[bizcity-router] Step 0.5: Unified goal "' . $pre_goal . '" belongs to "' . $goal_owner->get_id() . '", not "' . $provider_hint . '" — rejected' );
                    $debug['step0_5_rejected_goal'] = $pre_goal;
                    $debug['step0_5_rejected_owner'] = $goal_owner->get_id();
                    $goal_config = null; // Force fall-through
                }
            }

            if ( $goal_config && $pre_conf >= 0.7 ) {
                $result['intent']     = $pre['intent'] ?: 'new_goal';
                $result['goal']       = $pre_goal;
                $result['goal_label'] = $pre['goal_label'] ?: ( $goal_config['label'] ?? $pre_goal );
                $result['entities']   = is_array( $pre['entities'] ?? null ) ? $pre['entities'] : [];
                $result['confidence'] = $pre_conf;
                $result['method']     = 'unified_llm';

                // ── Handle WAITING_USER + provide_input → slot filling ──
                if ( $result['intent'] === 'provide_input'
                     && $has_active_goal
                     && $conversation['status'] === 'WAITING_USER'
                ) {
                    $result['goal']       = $conversation['goal'];
                    $result['goal_label'] = $conversation['goal_label'];
                    if ( ! empty( $attachments ) && ( $conversation['waiting_for'] ?? '' ) === 'image' ) {
                        $result['entities']['_images']       = $attachments;
                        $result['entities']['_waiting_field'] = $conversation['waiting_field'];
                        $result['confidence'] = 0.95;
                        // Also extract text entities when user sends image + text (e.g. "giá 120k" + image)
                        if ( ! empty( trim( $message ) ) ) {
                            $this->extract_text_entities_alongside_image( $result, $message, $conversation );
                        }
                    } elseif ( ! empty( $conversation['waiting_field'] ) ) {
                        $this->fill_waiting_field_entities( $result, $message, $conversation );
                    }
                }

                // ── WAITING_USER + new_goal → user switching tasks ──
                if ( $result['intent'] === 'new_goal'
                     && $has_active_goal
                     && $conversation['status'] === 'WAITING_USER'
                ) {
                    $debug['cancelled_goal'] = $conversation['goal'];
                }

                // ── Tier 2: Entity extraction for new_goal with empty entities ──
                // When classification comes from regex_goal (Mode Classifier),
                // entities are always empty because regex only matches intent,
                // not slot values. Trigger LLM entity extraction so the
                // user's topic/params aren't lost (e.g. "Tạo mindmap về X"
                // should extract topic="X" instead of re-asking).
                $needs_tier2 = in_array( $result['intent'], [ 'new_goal', 'continue_goal' ], true )
                    && ! empty( $result['goal'] )
                    && ! empty( trim( $message ) )
                    && empty( array_filter( $result['entities'], function( $v, $k ) {
                           return $k !== '_images' && $k !== '_raw_message';
                       }, ARRAY_FILTER_USE_BOTH ) );

                if ( $needs_tier2 ) {
                    $slot_schema = $this->get_goal_slot_schema( $result['goal'] );
                    if ( $slot_schema ) {
                        $tier2 = $this->extract_entities_with_llm(
                            $message,
                            $result['goal'],
                            $result['goal_label'],
                            $slot_schema
                        );
                        if ( $tier2 && ! empty( $tier2['entities'] ) ) {
                            $result['entities'] = array_merge( $result['entities'], $tier2['entities'] );
                            $debug['tier2_extracted'] = array_keys( $tier2['entities'] );
                            if ( ! empty( $tier2['_llm_model'] ) ) {
                                $result['_llm_model'] = ( $result['_llm_model'] ?? '' )
                                    . ( ! empty( $result['_llm_model'] ) ? '+' : '' )
                                    . $tier2['_llm_model'];
                            }
                        }
                    }
                }

                // ── Attachment enrichment ──
                if ( ! empty( $attachments ) && empty( $result['entities']['_images'] ) ) {
                    $result['entities']['_images'] = $attachments;
                }

                $debug['classify_step'] = 'step0_5_unified_mode_intent';
                $debug['unified_goal']  = $pre_goal;
                $debug['unified_conf']  = $pre_conf;
                $debug['filled_slots']  = $pre['filled_slots'] ?? [];
                $debug['missing_slots'] = $pre['missing_slots'] ?? [];
                $result['_debug']       = $debug;
                $result['_llm_model']   = $result['_llm_model'] ?? $pre['_llm_model'] ?? '';

                // ── Cache the unified intent result (v3.5) ──
                if ( class_exists( 'BizCity_Intent_Classify_Cache' ) ) {
                    BizCity_Intent_Classify_Cache::instance()->set_intent(
                        $message, $conversation, $provider_hint,
                        $result, $pre['_llm_model'] ?? ''
                    );
                }

                return $result;
            }
            // Goal not found in registry → fall through to normal Router flow
        }

        // ── Step 1: Check end patterns (highest priority) ──
        if ( $this->matches_end( $message_lower ) ) {
            $result['intent']     = 'end_conversation';
            $result['confidence'] = 0.9;
            $result['method']     = 'pattern';
            $debug['classify_step'] = 'step1_end_pattern';
            $result['_debug'] = $debug;
            return $result;
        }

        // ══════════════════════════════════════════════════════════════════════
        //  Step 2: LLM-FIRST Classification
        //
        //  Instead of relying on fragile regex patterns, we send the message
        //  to a fast/cheap LLM with the full goal schema. The LLM determines:
        //    - intent type (new_goal / provide_input / small_talk / end)
        //    - which goal
        //    - extracted entities (slots)
        //
        //  This handles natural language that regex can never cover while
        //  also respecting active conversation context.
        // ══════════════════════════════════════════════════════════════════════

        // ── Cache lookup (v3.4) — skip LLM if identical message+context was classified before ──
        $intent_cache_hit = false;
        if ( class_exists( 'BizCity_Intent_Classify_Cache' ) ) {
            $cached_intent = BizCity_Intent_Classify_Cache::instance()->get_intent( $message, $conversation, $provider_hint );
            if ( $cached_intent && ( $cached_intent['confidence'] ?? 0 ) >= 0.7 ) {
                $llm_result       = $cached_intent;
                $intent_cache_hit = true;
                $debug['classify_step'] = 'step2_cache_hit';
            }
        }

        if ( ! $intent_cache_hit ) {
            $llm_result = $this->classify_with_llm_primary( $message, $conversation, $attachments, $provider_hint );
        }

        if ( $llm_result && $llm_result['confidence'] >= 0.7 ) {
            // LLM classification succeeded with good confidence
            $debug['classify_step'] = 'step2_llm_primary';
            $debug['llm_intent']    = $llm_result['intent'];
            $debug['llm_goal']      = $llm_result['goal'];
            $debug['llm_conf']      = $llm_result['confidence'];

            // ── v3.7 FIX: WAITING_USER + small_talk → override to provide_input ──
            // When conversation is WAITING_USER (bot asked a question), ANY user message
            // is a slot answer — even if LLM/cache thinks it's small_talk.
            // "tài chính", "3 lá", "đây" are slot values, not small talk.
            // The Step 3a fallback handles this but only for low-confidence results.
            // This fix ensures high-confidence cache/LLM results are also corrected.
            if ( $llm_result['intent'] === 'small_talk'
                 && $has_active_goal
                 && ( $conversation['status'] ?? '' ) === 'WAITING_USER'
            ) {
                $llm_result['intent']     = 'provide_input';
                $llm_result['goal']       = $conversation['goal'];
                $llm_result['goal_label'] = $conversation['goal_label'] ?? $conversation['goal'];
                $llm_result['confidence'] = max( $llm_result['confidence'], 0.85 );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[INTENT-ROUTER] v3.7 override: small_talk → provide_input (WAITING_USER for '
                             . $conversation['goal'] . ', waiting_field=' . ( $conversation['waiting_field'] ?? '?' ) . ')' );
                }
                $debug['llm_intent']    = 'provide_input';
                $debug['llm_override']  = 'waiting_user_small_talk_to_provide_input';
            }

            // ── If WAITING_USER + LLM says provide_input → handle slot filling ──
            // GUARD: If message is empty (image-only) and NOT waiting for image →
            //        don't treat as provide_input. Downgrade to small_talk so the image
            //        falls through to knowledge mode instead of auto-executing.
            //        Exception: slots with accept_image allow image-only input (v4.6.1).
            $msg_empty_llm    = empty( trim( $message ) );
            $awaiting_img_llm = ( $conversation['waiting_for'] ?? '' ) === 'image';
            $llm_accept_img   = false;
            if ( $msg_empty_llm && ! $awaiting_img_llm && ! empty( $attachments ) ) {
                $llm_cg_goal = $conversation['goal'] ?? '';
                if ( $llm_cg_goal && class_exists( 'BizCity_Intent_Planner' ) ) {
                    $llm_cg_plan = BizCity_Intent_Planner::instance()->get_plan( $llm_cg_goal );
                    if ( $llm_cg_plan ) {
                        $llm_cg_wf  = $conversation['waiting_field'] ?? '';
                        $llm_cg_all = array_merge( $llm_cg_plan['required_slots'] ?? [], $llm_cg_plan['optional_slots'] ?? [] );
                        if ( ! empty( $llm_cg_all[ $llm_cg_wf ]['accept_image'] ) ) {
                            $llm_accept_img = true;
                        }
                    }
                }
            }

            if ( $llm_result['intent'] === 'provide_input'
                && $has_active_goal
                && $conversation['status'] === 'WAITING_USER'
                && ! ( $msg_empty_llm && ! $awaiting_img_llm && ! $llm_accept_img && ! empty( $attachments ) )
            ) {
                $llm_result['goal']       = $conversation['goal'];
                $llm_result['goal_label'] = $conversation['goal_label'];

                // Attachment handling
                if ( ! empty( $attachments ) && $conversation['waiting_for'] === 'image' ) {
                    $llm_result['entities']['_images']       = $attachments;
                    $llm_result['entities']['_waiting_field'] = $conversation['waiting_field'];
                    $llm_result['confidence'] = 0.95;
                    // Also extract text entities when user sends image + text (e.g. "giá 120k" + image)
                    if ( ! empty( trim( $message ) ) ) {
                        $this->extract_text_entities_alongside_image( $llm_result, $message, $conversation );
                    }
                }
                // Slot-field mapping
                elseif ( ! empty( $conversation['waiting_field'] ) ) {
                    $this->fill_waiting_field_entities( $llm_result, $message, $conversation );
                    // v4.6.1: Also capture images when waiting for non-image slots.
                    if ( ! empty( $attachments ) ) {
                        $llm_result['entities']['_images'] = $attachments;
                    }
                }
            }

            // ── If WAITING_USER but LLM says new_goal → user is switching tasks ──
            if ( $llm_result['intent'] === 'new_goal'
                && $has_active_goal
                && $conversation['status'] === 'WAITING_USER'
            ) {
                $debug['cancelled_goal'] = $conversation['goal'];
            }

            // ── Attachment enrichment for new_goal/provide_input ──
            if ( ! empty( $attachments ) && empty( $llm_result['entities']['_images'] ) ) {
                $llm_result['entities']['_images'] = $attachments;
            }

            // ── Provider scope guard (v4.0): Reject cross-provider goals in Step 2 ──
            if ( $provider_hint
                 && $llm_result['intent'] === 'new_goal'
                 && ! empty( $llm_result['goal'] )
                 && class_exists( 'BizCity_Intent_Provider_Registry' )
            ) {
                $step2_owner = BizCity_Intent_Provider_Registry::instance()->get_provider_for_goal( $llm_result['goal'] );
                if ( $step2_owner && $step2_owner->get_id() !== $provider_hint ) {
                    $rejected_goal_step2 = $llm_result['goal'];
                    error_log( '[bizcity-router] Step 2: LLM goal "' . $rejected_goal_step2 . '" belongs to "' . $step2_owner->get_id() . '", not @' . $provider_hint . ' — downgrading to small_talk' );
                    $debug['step2_rejected_goal']  = $rejected_goal_step2;
                    $debug['step2_rejected_owner'] = $step2_owner->get_id();
                    $llm_result['intent']     = 'small_talk';
                    $llm_result['goal']       = '';
                    $llm_result['goal_label'] = '';
                    $llm_result['confidence'] = 0.5;
                    $llm_result['method']    .= '+provider_scope_rejected';
                }
            }

            $llm_result['_debug'] = $debug;

            // ── Store to cache (v3.4) — only for LLM results, not cache hits ──
            if ( ! $intent_cache_hit && class_exists( 'BizCity_Intent_Classify_Cache' ) ) {
                BizCity_Intent_Classify_Cache::instance()->set_intent(
                    $message, $conversation, $provider_hint,
                    $llm_result,
                    $llm_result['_llm_model'] ?? ''
                );
            }

            return $llm_result;
        }

        // ══════════════════════════════════════════════════════════════════════
        //  Step 3: FALLBACK — Pattern-based classification
        //
        //  If LLM is unavailable (no API key, network error, OpenRouter down)
        //  or returned low confidence, fall back to regex patterns.
        //  This ensures the system still works without LLM dependency.
        // ══════════════════════════════════════════════════════════════════════

        $debug['llm_fallback'] = true;
        $debug['llm_error']    = $llm_result['_llm_error'] ?? 'unavailable';

        // ── Step 3a: WAITING_USER fallback with pattern escape-hatch ──
        if ( $has_active_goal && $conversation['status'] === 'WAITING_USER' ) {

            // Check if message matches a DIFFERENT goal pattern
            $switch_goal    = null;
            $switch_pattern = '';
            foreach ( $this->goal_patterns as $pat => $cfg ) {
                if ( @preg_match( $pat, $message_lower ) && $cfg['goal'] !== $conversation['goal'] ) {
                    $switch_goal    = $cfg;
                    $switch_pattern = $pat;
                    break;
                }
            }

            // v3.6.5 → v3.6.6: Action-verb guard for goal switch during WAITING_USER.
            // "Dinh dưỡng quan trọng hơn thuốc" is a topic, not a new goal command.
            //
            // v3.6.6: Cross-provider bypass — if matched goal is from a DIFFERENT provider
            // than the current goal, allow escape without action verb.
            if ( $switch_goal ) {
                $cross_provider = false;
                if ( class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
                    $registry    = BizCity_Intent_Provider_Registry::instance();
                    $cur_owner   = $registry->get_provider_for_goal( $conversation['goal'] );
                    $match_owner = $registry->get_provider_for_goal( $switch_goal['goal'] );
                    $cur_pid     = $cur_owner ? $cur_owner->get_id() : '';
                    $match_pid   = $match_owner ? $match_owner->get_id() : '';
                    $cross_provider = ( $cur_pid && $match_pid && $cur_pid !== $match_pid );
                }

                if ( ! $cross_provider ) {
                    // Same provider or unknown → require action verb (strict guard)
                    $has_action_verb = preg_match(
                        '/^(t[ạa]o|xem|ghi|tra|[đd][ăa]ng|vi[ếe]t|g[ợo]i\s*[ýy]|ch[ụu]p|t[ìi]m|h[ỏo]i|l[àa]m|xo[áa]|s[ửu]a|c[ậa]p\s*nh[ậa]t|th[ốo]ng\s*k[êe]|b[áa]o\s*c[áa]o|log|post|create|search|delete|update|check|xem|m[ởo]|show|list|n[êe]n\s*[aă]n|[aă]n\s*g[ìi])\b/ui',
                        $message_lower
                    );
                    if ( ! $has_action_verb ) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[INTENT-ROUTER] Step3a regex escape BLOCKED — no action verb in "'
                                     . mb_substr( $message, 0, 80, 'UTF-8' ) . '" (matched '
                                     . ( $switch_goal['goal'] ?? '' ) . ' but likely provide_input for '
                                     . $conversation['goal'] . ')' );
                        }
                        $switch_goal = null; // Block escape — fall through to provide_input
                    }
                } else {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[INTENT-ROUTER] Step3a cross-provider escape ALLOWED — '
                                 . ( $switch_goal['goal'] ?? '' ) . ' vs current ' . $conversation['goal'] );
                    }
                }
            }

            if ( $switch_goal ) {
                $result['intent']     = 'new_goal';
                $result['goal']       = $switch_goal['goal'];
                $result['goal_label'] = $switch_goal['label'];
                $result['confidence'] = 0.9;
                $result['method']     = 'pattern';
                $result['entities']   = $this->extract_entities( $message, $switch_goal['extract'] ?? [] );
                $debug['classify_step']   = 'step3a_fallback_waiting_new_goal';
                $debug['matched_pattern'] = $switch_pattern;
                $debug['pattern_source']  = $this->detect_pattern_source( $switch_pattern );
                $debug['cancelled_goal']  = $conversation['goal'];
                $result['_debug'] = $debug;
                return $result;
            }

            // No pattern switch → provide_input for current goal
            //
            // GUARD: If message is empty (image-only) and we're NOT waiting specifically for
            // an image → don't auto-route as provide_input. Fall through to step 3b (attachment
            // handling) which has proper goal-pattern checks. This prevents stale WAITING_USER
            // conversations from consuming unrelated images.
            $msg_empty     = empty( trim( $message ) );
            $awaiting_img  = ( $conversation['waiting_for'] ?? '' ) === 'image';

            if ( $msg_empty && ! $awaiting_img && ! empty( $attachments ) ) {
                // v4.6.1: Check if current goal has a slot that accepts images.
                // If so, route as provide_input (don't drop to step 3b).
                $has_accept_image = false;
                $cg_goal = $conversation['goal'] ?? '';
                if ( $cg_goal && class_exists( 'BizCity_Intent_Planner' ) ) {
                    $cg_plan = BizCity_Intent_Planner::instance()->get_plan( $cg_goal );
                    if ( $cg_plan ) {
                        $cg_all_slots = array_merge( $cg_plan['required_slots'] ?? [], $cg_plan['optional_slots'] ?? [] );
                        // accept_image on the waiting field OR an image-type slot exists
                        $cg_wf = $conversation['waiting_field'] ?? '';
                        if ( ! empty( $cg_all_slots[ $cg_wf ]['accept_image'] ) ) {
                            $has_accept_image = true;
                        } elseif ( ! $has_accept_image ) {
                            foreach ( $cg_all_slots as $cg_cfg ) {
                                if ( ( $cg_cfg['type'] ?? '' ) === 'image' ) {
                                    $has_accept_image = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                if ( ! $has_accept_image ) {
                    $debug['classify_step'] = 'step3a_skip_image_only_not_waiting_image';
                    // Fall through to step 3b
                } else {
                    // Goal accepts image input → treat as provide_input with image
                    $result['intent']     = 'provide_input';
                    $result['goal']       = $conversation['goal'];
                    $result['goal_label'] = $conversation['goal_label'];
                    $result['confidence'] = 0.90;
                    $result['method']     = 'context+accept_image';
                    $result['entities']['_images'] = $attachments;
                    $debug['classify_step'] = 'step3a_image_only_accept_image';
                    $result['_debug'] = $debug;
                    return $result;
                }
            } else {
                $result['intent']     = 'provide_input';
                $result['goal']       = $conversation['goal'];
                $result['goal_label'] = $conversation['goal_label'];
                $result['confidence'] = 0.85;
                $result['method']     = 'context';
                $debug['classify_step'] = 'step3a_fallback_waiting_provide';

                if ( ! empty( $attachments ) && $awaiting_img ) {
                    $result['entities']['_images']       = $attachments;
                    $result['entities']['_waiting_field'] = $conversation['waiting_field'];
                    $result['confidence'] = 0.95;
                    // Also extract text entities when user sends image + text (e.g. "giá 120k" + image)
                    if ( ! empty( trim( $message ) ) ) {
                        $this->extract_text_entities_alongside_image( $result, $message, $conversation );
                    }
                    $result['_debug'] = $debug;
                    return $result;
                }

                if ( ! empty( $conversation['waiting_field'] ) ) {
                    $this->fill_waiting_field_entities( $result, $message, $conversation );
                    // v4.6.1: Also capture images when waiting for non-image slots.
                    if ( ! empty( $attachments ) ) {
                        $result['entities']['_images'] = $attachments;
                    }
                }

                $result['_debug'] = $debug;
                return $result;
            }
        }

        // ── Step 3b: Attachment handling (no LLM) ──
        if ( ! empty( $attachments ) ) {
            if ( $has_active_goal ) {
                $result['intent']     = 'provide_input';
                $result['goal']       = $conversation['goal'];
                $result['goal_label'] = $conversation['goal_label'];
                $result['entities']['_images'] = $attachments;
                $result['confidence'] = 0.85;
                $result['method']     = 'context';
                $debug['classify_step'] = 'step3b_fallback_attachment_active';
                $result['_debug'] = $debug;
                return $result;
            }

            // No active goal + image → check tarot/astro patterns
            foreach ( $this->goal_patterns as $pattern => $config ) {
                if ( in_array( $config['goal'], [ 'tarot_reading', 'tarot_interpret', 'astro_forecast', 'daily_outlook' ], true ) ) {
                    if ( preg_match( $pattern, $message_lower ) ) {
                        $result['intent']     = 'new_goal';
                        $result['goal']       = $config['goal'];
                        $result['goal_label'] = $config['label'];
                        $result['entities']['_images'] = $attachments;
                        $result['confidence'] = 0.9;
                        $result['method']     = 'pattern';
                        $debug['classify_step']   = 'step3b_fallback_attachment_pattern';
                        $debug['matched_pattern'] = $pattern;
                        $debug['pattern_source']  = $this->detect_pattern_source( $pattern );
                        $result['_debug'] = $debug;
                        return $result;
                    }
                }
            }

            // C4 Fix: Configurable image default (not hardcoded tarot_interpret)
            $image_default = get_option( 'bizcity_tcp_image_default_goal', 'tarot_interpret' );

            if ( $image_default === 'passthrough' ) {
                // No default → let Intent Engine handle image naturally
                $result['intent']     = 'chat';
                $result['goal']       = '';
                $result['confidence'] = 0.3;
                $result['method']     = 'context';
                $debug['classify_step'] = 'step3b_attachment_passthrough';
                $debug['attachment_count'] = count( $attachments );
                $result['_debug'] = $debug;
                return $result;
            }

            // Use configured default goal for image
            $goal_label_map = array(
                'tarot_interpret' => 'Giải bài Tarot',
                'image_describe'  => 'Mô tả hình ảnh',
                'image_analyze'   => 'Phân tích hình ảnh',
            );
            $result['intent']     = 'new_goal';
            $result['goal']       = $image_default;
            $result['goal_label'] = isset( $goal_label_map[ $image_default ] )
                ? $goal_label_map[ $image_default ]
                : $image_default;
            $result['entities']['card_images'] = $attachments;
            $result['confidence'] = 0.7;
            $result['method']     = 'context';
            $debug['classify_step'] = 'step3b_fallback_attachment_default';
            $debug['image_default_goal'] = $image_default;
            $result['_debug'] = $debug;
            return $result;
        }

        // ── Step 3c: Pattern-match for known goals ──
        $candidates = [];
        $matched_idx = -1;
        $idx = 0;
        foreach ( $this->goal_patterns as $pattern => $config ) {
            $is_match = (bool) @preg_match( $pattern, $message_lower );
            $source   = $this->detect_pattern_source( $pattern );
            $candidates[] = [
                'goal'    => $config['goal'] ?? '?',
                'source'  => $source,
                'matched' => $is_match,
                'pattern' => $pattern,
            ];
            if ( $is_match && $matched_idx === -1 ) {
                $matched_idx = $idx;
            }
            $idx++;
        }
        $debug['all_goal_candidates'] = $candidates;
        $debug['pattern_count'] = count( $this->goal_patterns );
        $debug['provider_pattern_count'] = count( array_filter( $candidates, function( $c ) {
            return $c['source'] !== 'built-in';
        } ) );

        if ( $matched_idx >= 0 ) {
            $matched_candidate = $candidates[ $matched_idx ];
            $matched_pattern   = $matched_candidate['pattern'];
            $config            = $this->goal_patterns[ $matched_pattern ];

            if ( $has_active_goal && $conversation['goal'] === $config['goal'] ) {
                $result['intent']     = 'continue_goal';
                $result['goal']       = $config['goal'];
                $result['goal_label'] = $config['label'];
                $result['confidence'] = 0.85;
                $result['method']     = 'pattern';
            } else {
                $result['intent']     = 'new_goal';
                $result['goal']       = $config['goal'];
                $result['goal_label'] = $config['label'];
                $result['confidence'] = 0.9;
                $result['method']     = 'pattern';
            }

            $debug['classify_step']   = 'step3c_fallback_pattern';
            $debug['matched_pattern'] = $matched_pattern;
            $debug['pattern_source']  = $matched_candidate['source'];
            $result['entities'] = $this->extract_entities( $message, $config['extract'] ?? [] );
            $result['_debug'] = $debug;
            return $result;
        }

        // ══════════════════════════════════════════════════════════════════════
        //  Step 3c.5: TOOL REGISTRY KEYWORD RESCUE (v4.3)
        //
        //  When both LLM (Step 2) and regex patterns (Step 3c) fail,
        //  search bizcity_tool_registry by keywords from the user's message.
        //  The tool_registry has: goal, title, goal_label, custom_hints,
        //  goal_description, plugin — a simple keyword search can rescue
        //  classification when the tool EXISTS in DB but has no loaded patterns.
        //
        //  This closes the gap where slash command search_tools() works but
        //  the classification pipeline doesn't use it.
        // ══════════════════════════════════════════════════════════════════════
        $registry_search_start = microtime( true );
        $db_match = $this->search_tool_registry_by_message( $message_lower );
        $registry_search_ms = round( ( microtime( true ) - $registry_search_start ) * 1000, 2 );

        // Always record search details in _debug for Intent Monitor pipe step
        $debug['registry_search'] = [
            'attempted'   => true,
            'search_ms'   => $registry_search_ms,
            'keywords'    => array_values( array_filter(
                preg_split( '/[\s,;.!?]+/u', $message_lower ),
                function( $w ) { return mb_strlen( $w, 'UTF-8' ) >= 2; }
            ) ),
            'best_match'  => $db_match,
            'threshold'   => 10,
            'outcome'     => $db_match
                ? ( $db_match['score'] >= 10 ? 'RESCUE' : 'low_score' )
                : 'no_match',
        ];

        if ( $db_match && $db_match['score'] >= 10 ) {
            // High-confidence DB match — route to that goal
            $result['intent']     = 'new_goal';
            $result['goal']       = $db_match['goal'];
            $result['goal_label'] = $db_match['goal_label'];
            $result['confidence'] = min( 0.85, 0.5 + $db_match['score'] * 0.02 );
            $result['method']     = 'tool_registry_keyword';
            $result['entities']   = [ '_raw_message' => $message ];
            $debug['classify_step']             = 'step3c5_tool_registry_rescue';
            $debug['registry_match_goal']       = $db_match['goal'];
            $debug['registry_match_score']      = $db_match['score'];
            $debug['registry_match_reason']     = $db_match['reason'];
            $debug['registry_match_plugin']     = $db_match['plugin'];
            $result['_debug'] = $debug;
            error_log( '[bizcity-router] Step 3c.5: Tool registry keyword rescue → goal=' . $db_match['goal']
                     . ' score=' . $db_match['score'] . ' reason=' . $db_match['reason'] );
            return $result;
        }
        if ( $db_match ) {
            $debug['registry_low_score'] = $db_match;
        }

        // ── Step 3d: Active goal continuation ──
        if ( $has_active_goal ) {
            $result['intent']     = 'continue_goal';
            $result['goal']       = $conversation['goal'];
            $result['goal_label'] = $conversation['goal_label'];
            $result['confidence'] = 0.6;
            $result['method']     = 'context';
            $debug['classify_step'] = 'step3d_fallback_continue';

            $goal_config = $this->find_goal_config( $conversation['goal'] );
            if ( $goal_config && ! empty( $goal_config['extract'] ) ) {
                $extracted = $this->extract_entities( $message, $goal_config['extract'] );
                if ( ! empty( $extracted ) ) {
                    $result['entities']   = $extracted;
                    $result['confidence'] = 0.75;
                }
            }

            $result['_debug'] = $debug;
            return $result;
        }

        // ── Step 3e: Default small_talk ──
        $debug['classify_step'] = 'step3e_fallback_small_talk';
        $result['_debug'] = $debug;
        return $result;
    }

    /* ================================================================
     *  LLM-based primary classification (2-tier v3.5.0)
     *
     *  Tier 1 — classify_with_llm_fast():
     *      Minimal prompt with goal names only (no slots).
     *      Returns: intent + goal + confidence.  ~100 max_tokens.
     *
     *  Tier 2 — extract_entities_with_llm():
     *      Called for new_goal AND continue_goal when message has content.
     *      Focused prompt with single-goal slot schema.
     *      Returns: entities dict.
     *
     *  v3.5.2: Regex-based entity extraction removed. All slot extraction
     *  is handled by LLM for better Vietnamese language understanding.
     * ================================================================ */

    /**
     * Build UNIFIED goal + tool schema for LLM prompt (v3.4.0).
     *
     * Merges goal_patterns (from Router) + Tool Registry (from DB) into ONE
     * compact manifest. Eliminates duplicate information between the two sources.
     *
     * Strategy:
     *   1. Start with Tool Index DB rows (authoritative: has required/optional slots)
     *   2. Enrich with goal_patterns description + label (Router context)
     *   3. Add goals that have NO matching tool (pure-pattern goals)
     *
     * Token savings: ~30-40% vs separate goal_schema + tool_manifest.
     *
     * @param int $max_length Max characters for the output.
     * @return string Compact unified schema.
     */
    public function build_unified_schema_for_llm( int $max_length = 1800 ): string {
        // Ensure provider patterns are merged (lazy resolution — needed when called externally)
        $this->resolve_patterns();

        // ── 1. Gather goal_patterns metadata (keyed by goal) ──
        $goal_meta = [];
        foreach ( $this->goal_patterns as $config ) {
            $goal = $config['goal'] ?? '';
            if ( empty( $goal ) || isset( $goal_meta[ $goal ] ) ) continue;
            $goal_meta[ $goal ] = [
                'label' => $config['label'] ?? $goal,
                'desc'  => $config['description'] ?? '',
                'slots' => $config['extract'] ?? [],
            ];
        }

        // ── 2. Gather Tool Index rows (from DB cache) ──
        $tool_rows = [];
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $tool_rows = BizCity_Intent_Tool_Index::instance()->get_all_active();
        }

        // ── 3. Build unified lines: tool-backed goals first ──
        $seen  = [];
        $lines = [];
        $idx   = 0;

        foreach ( $tool_rows as $row ) {
            $idx++;
            $tool_name = $row['tool_name'];
            $goal      = $row['goal'] ?: $tool_name;
            $plugin    = $row['plugin'] ?: 'core';
            $seen[ $goal ] = true;

            // Prefer custom_description > goal_patterns description > DB
            $meta  = $goal_meta[ $goal ] ?? [];
            $label = $meta['label'] ?? ( $row['goal_label'] ?: $row['title'] ?: $goal );
            $desc  = ! empty( $row['custom_description'] )
                ? $row['custom_description']
                : ( $meta['desc'] ?? ( $row['goal_description'] ?: '' ) );

            // Custom routing hints from Control Panel
            $hints = $row['custom_hints'] ?? '';

            // Required slots from DB (authoritative)
            $req = $this->parse_tool_slot_names( $row['required_slots'] ?? '' );
            $opt = $this->parse_tool_slot_names( $row['optional_slots'] ?? '' );

            $line = "{$idx}. {$goal}: {$label}";
            if ( $desc ) {
                $line .= ' — ' . mb_substr( $desc, 0, 80, 'UTF-8' );
            }
            if ( $hints ) {
                $line .= ' [khi nói: ' . mb_substr( $hints, 0, 50, 'UTF-8' ) . ']';
            }
            if ( $tool_name !== $goal ) {
                $line .= " [tool:{$tool_name}]";
            }
            $line .= " [{$plugin}]";
            if ( $req ) {
                $line .= ' | cần: ' . $req;
            }
            if ( $opt ) {
                $line .= ' | tùy chọn: ' . $opt;
            }
            $lines[] = $line;
        }

        // ── 4. Add pattern-only goals (no matching tool in DB) ──
        foreach ( $goal_meta as $goal => $meta ) {
            if ( isset( $seen[ $goal ] ) ) continue;
            $idx++;
            $line = "{$idx}. {$goal}: {$meta['label']}";
            if ( $meta['desc'] ) {
                $line .= ' — ' . mb_substr( $meta['desc'], 0, 80, 'UTF-8' );
            }
            if ( ! empty( $meta['slots'] ) ) {
                $line .= ' | slots: ' . implode( ', ', $meta['slots'] );
            }
            $lines[] = $line;
        }

        if ( empty( $lines ) ) {
            return '';
        }

        $header = "## GOALS & TOOLS ({$idx} mục)\n";
        $body   = implode( "\n", $lines );
        $text   = $header . $body;

        if ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
            $text = mb_substr( $text, 0, $max_length - 3, 'UTF-8' ) . '...';
        }

        return $text;
    }

    /**
     * Parse slot names from JSON-encoded slot config.
     *
     * @param string $json_slots  JSON string of slot configs.
     * @return string  Comma-separated slot names, or empty string.
     */
    private function parse_tool_slot_names( string $json_slots ): string {
        if ( empty( $json_slots ) ) {
            return '';
        }
        $decoded = json_decode( $json_slots, true );
        if ( ! is_array( $decoded ) || empty( $decoded ) ) {
            return '';
        }
        return implode( ', ', array_keys( $decoded ) );
    }

    /**
     * Parse slot names WITH type hints from JSON-encoded slot config.
     *
     * Returns format like "topic(text), diagram_type(choice:mindmap,flowchart,auto)"
     * which gives the LLM enough context to extract entities correctly.
     *
     * @param string $json_slots  JSON string of slot configs.
     * @return string  Comma-separated "name(type)" pairs, or empty string.
     */
    private function parse_tool_slot_names_with_types( string $json_slots ): string {
        if ( empty( $json_slots ) ) {
            return '';
        }
        $decoded = json_decode( $json_slots, true );
        if ( ! is_array( $decoded ) || empty( $decoded ) ) {
            return '';
        }
        $parts = [];
        foreach ( $decoded as $name => $config ) {
            if ( $name === '_meta' ) continue;
            $type = $config['type'] ?? 'text';
            $hint = $name . '(' . $type;
            // Add choices if present (for enum/choice types)
            if ( ! empty( $config['choices'] ) && is_array( $config['choices'] ) ) {
                $keys = array_keys( $config['choices'] );
                $hint .= ':' . implode( ',', array_slice( $keys, 0, 5 ) );
            }
            $hint .= ')';
            $parts[] = $hint;
        }
        return implode( ', ', $parts );
    }

    /**
     * Build FOCUSED schema for unified LLM — only top N tools with type hints.
     *
     * Uses regex pre-match to prioritize likely tools, then fills remaining
     * slots by DB priority. Includes slot type hints for better entity extraction.
     *
     * @param string|null $likely_goal  Goal ID from regex pre-match (bias).
     * @param int         $top_n        Max tools to include (configurable).
     * @param int         $max_length   Max characters for output.
     * @return string     Compact focused schema.
     */
    public function build_focused_schema_for_llm( ?string $likely_goal = null, int $top_n = 5, int $max_length = 2000 ): string {
        $this->resolve_patterns();

        // ── 1. Gather goal_patterns metadata ──
        $goal_meta = [];
        foreach ( $this->goal_patterns as $config ) {
            $goal = $config['goal'] ?? '';
            if ( empty( $goal ) || isset( $goal_meta[ $goal ] ) ) continue;
            $goal_meta[ $goal ] = [
                'label' => $config['label'] ?? $goal,
                'desc'  => $config['description'] ?? '',
                'slots' => $config['extract'] ?? [],
            ];
        }

        // ── 2. Gather Tool Index rows (sorted by priority) ──
        $tool_rows = [];
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $tool_rows = BizCity_Intent_Tool_Index::instance()->get_all_active();
        }

        // ── 3. Partition: likely_goal first, then rest by priority ──
        $likely_rows = [];
        $other_rows  = [];
        foreach ( $tool_rows as $row ) {
            $goal = $row['goal'] ?: $row['tool_name'];
            if ( $likely_goal && $goal === $likely_goal ) {
                $likely_rows[] = $row;
            } else {
                $other_rows[] = $row;
            }
        }
        // Sort others by priority (already sorted from DB, but ensure)
        usort( $other_rows, function( $a, $b ) {
            return ( (int)( $a['priority'] ?? 50 ) ) <=> ( (int)( $b['priority'] ?? 50 ) );
        });

        // Combine: likely first, then fill up to top_n
        $selected = array_merge( $likely_rows, $other_rows );

        // ── v3.8.2: Reserve 1 slot for regex-matched pattern-only goal ──
        // If likely_goal matched a regex pattern but has NO Tool Index DB entry,
        // it won't appear in $selected. Reserve 1 slot so the LLM can see it
        // (otherwise all top_n slots are consumed by DB tools and pattern-only
        // goals get 0 remaining capacity → LLM can't classify the message).
        $likely_in_db = ! empty( $likely_rows );
        $effective_limit = ( ! $likely_in_db && $likely_goal && count( $selected ) >= $top_n )
            ? $top_n - 1
            : $top_n;
        $selected = array_slice( $selected, 0, $effective_limit );

        // Also include any pattern-only goals not in DB (up to remaining capacity)
        $seen_goals = [];
        foreach ( $selected as $row ) {
            $seen_goals[ $row['goal'] ?: $row['tool_name'] ] = true;
        }

        $pattern_only = [];
        foreach ( $goal_meta as $goal => $meta ) {
            if ( isset( $seen_goals[ $goal ] ) ) continue;
            if ( $likely_goal && $goal === $likely_goal ) {
                // Likely goal not in DB but in patterns — add first
                array_unshift( $pattern_only, [ 'goal' => $goal, 'meta' => $meta ] );
            } else {
                $pattern_only[] = [ 'goal' => $goal, 'meta' => $meta ];
            }
        }

        // ── 4. Build lines with type hints ──
        $lines = [];
        $idx   = 0;
        $total_tools = count( $tool_rows ) + count( $pattern_only );

        foreach ( $selected as $row ) {
            $idx++;
            $goal      = $row['goal'] ?: $row['tool_name'];
            $meta      = $goal_meta[ $goal ] ?? [];
            $label     = $meta['label'] ?? ( $row['goal_label'] ?: $row['title'] ?: $goal );
            $desc      = ! empty( $row['custom_description'] )
                ? $row['custom_description']
                : ( $meta['desc'] ?? ( $row['goal_description'] ?: '' ) );
            $hints     = $row['custom_hints'] ?? '';

            // Slot names WITH type hints
            $req = $this->parse_tool_slot_names_with_types( $row['required_slots'] ?? '' );
            $opt = $this->parse_tool_slot_names_with_types( $row['optional_slots'] ?? '' );

            $star = ( $likely_goal && $goal === $likely_goal ) ? '★ ' : '';
            $line = "{$star}{$idx}. {$goal}: {$label}";
            if ( $desc ) {
                $line .= ' — ' . mb_substr( $desc, 0, 80, 'UTF-8' );
            }
            if ( $hints ) {
                $line .= ' [khi nói: ' . mb_substr( $hints, 0, 50, 'UTF-8' ) . ']';
            }
            if ( $req ) {
                $line .= ' | cần: ' . $req;
            }
            if ( $opt ) {
                $line .= ' | tùy chọn: ' . $opt;
            }
            $lines[] = $line;
        }

        // Add remaining pattern-only goals (up to top_n total)
        $remaining = $top_n - count( $lines );
        foreach ( array_slice( $pattern_only, 0, max( 0, $remaining ) ) as $po ) {
            $idx++;
            $goal = $po['goal'];
            $meta = $po['meta'];
            $star = ( $likely_goal && $goal === $likely_goal ) ? '★ ' : '';
            $line = "{$star}{$idx}. {$goal}: {$meta['label']}";
            if ( $meta['desc'] ) {
                $line .= ' — ' . mb_substr( $meta['desc'], 0, 80, 'UTF-8' );
            }
            if ( ! empty( $meta['slots'] ) ) {
                $line .= ' | slots: ' . implode( ', ', $meta['slots'] );
            }
            $lines[] = $line;
        }

        if ( empty( $lines ) ) return '';

        $note = ( $total_tools > $top_n )
            ? " — hiển thị top {$top_n}/{$total_tools}, ★=likely"
            : '';
        $header = "## GOALS & TOOLS ({$idx} mục{$note})\n";
        $text   = $header . implode( "\n", $lines );

        if ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
            $text = mb_substr( $text, 0, $max_length - 3, 'UTF-8' ) . '...';
        }

        return $text;
    }

    /**
     * Get the configured top_n_tools setting.
     *
     * @return int
     */
    public static function get_top_n_tools(): int {
        return max( 3, min( 50, (int) get_option( 'bizcity_tcp_top_n_tools', 10 ) ) );
    }

    /**
     * Build compact goal list for Tier 1 (fast) LLM classification.
     *
     * Priority-aware: reads from goal_patterns first, enriches from Tool Index DB.
     * DB rows with custom_description/custom_hints override provider descriptions.
     * Sorted by priority (lower = appears first in the LLM prompt).
     *
     * @param int $max_length Max characters for output.
     * @return string Compact goal list.
     */
    private function build_goal_list_compact( int $max_length = 1200 ): string {
        // ── 1. Gather goal_patterns metadata (keyed by goal) ──
        $goal_meta = [];
        foreach ( $this->goal_patterns as $config ) {
            $goal = $config['goal'] ?? '';
            if ( empty( $goal ) || isset( $goal_meta[ $goal ] ) ) continue;
            $goal_meta[ $goal ] = [
                'label'    => $config['label'] ?? $goal,
                'desc'     => $config['description'] ?? '',
                'hints'    => '',
                'priority' => 50,
            ];
        }

        // ── 2. Enrich from Tool Index DB (priority, custom desc, hints) ──
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            foreach ( BizCity_Intent_Tool_Index::instance()->get_all_active() as $row ) {
                $goal = $row['goal'] ?: $row['tool_name'];

                // Custom description overrides provider description
                $custom_desc  = ! empty( $row['custom_description'] ) ? $row['custom_description'] : '';
                $custom_hints = $row['custom_hints'] ?? '';
                $priority     = (int) ( $row['priority'] ?? 50 );

                if ( isset( $goal_meta[ $goal ] ) ) {
                    // Override with DB admin-editable fields
                    if ( $custom_desc ) {
                        $goal_meta[ $goal ]['desc'] = $custom_desc;
                    }
                    if ( $custom_hints ) {
                        $goal_meta[ $goal ]['hints'] = $custom_hints;
                    }
                    $goal_meta[ $goal ]['priority'] = $priority;
                } else {
                    // Goal only in DB, not in patterns
                    $goal_meta[ $goal ] = [
                        'label'    => $row['goal_label'] ?: $row['title'] ?: $goal,
                        'desc'     => $custom_desc ?: ( $row['goal_description'] ?: '' ),
                        'hints'    => $custom_hints,
                        'priority' => $priority,
                    ];
                }
            }
        }

        // ── 3. Sort by priority (lower = higher priority) ──
        uasort( $goal_meta, function( $a, $b ) {
            return ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 );
        } );

        // ── 4. Build lines ──
        $lines = [];
        $idx   = 0;
        foreach ( $goal_meta as $goal => $meta ) {
            $idx++;
            $line = "{$idx}. {$goal}: {$meta['label']}";
            if ( $meta['desc'] ) {
                $line .= ' — ' . mb_substr( $meta['desc'], 0, 60, 'UTF-8' );
            }
            // Append hints as routing keywords for LLM
            if ( ! empty( $meta['hints'] ) ) {
                $line .= ' [khi nói: ' . mb_substr( $meta['hints'], 0, 50, 'UTF-8' ) . ']';
            }
            $lines[] = $line;
        }

        if ( empty( $lines ) ) return '';

        $text = "GOALS ({$idx}):\n" . implode( "\n", $lines );
        if ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
            $text = mb_substr( $text, 0, $max_length - 3, 'UTF-8' ) . '...';
        }
        return $text;
    }

    /**
     * Public version of build_goal_list_compact for Tool Control Panel preview.
     *
     * @param int $max_length Max characters.
     * @return string
     */
    public function build_goal_list_compact_for_preview( int $max_length = 2000 ): string {
        $this->resolve_patterns();
        return $this->build_goal_list_compact( $max_length );
    }

    /**
     * Get slot schema for a single goal (for Tier 2 entity extraction).
     *
     * @param string $goal Goal ID.
     * @return string Slot description, or empty string.
     */
    private function get_goal_slot_schema( string $goal ): string {
        // Try goal_patterns first
        $config = $this->find_goal_config( $goal );
        $slots  = $config['extract'] ?? [];

        // Enrich from Tool Index DB (has required/optional distinction)
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $tool_rows = BizCity_Intent_Tool_Index::instance()->get_all_active();
            foreach ( $tool_rows as $row ) {
                $row_goal = $row['goal'] ?: $row['tool_name'];
                if ( $row_goal !== $goal ) continue;
                $req = $this->parse_tool_slot_names( $row['required_slots'] ?? '' );
                $opt = $this->parse_tool_slot_names( $row['optional_slots'] ?? '' );
                $parts = [];
                if ( $req ) $parts[] = "Cần: {$req}";
                if ( $opt ) $parts[] = "Tùy chọn: {$opt}";
                if ( $parts ) return implode( ' | ', $parts );
                break;
            }
        }

        // Enrich from Planner plan definitions (has type, label, prompt)
        if ( class_exists( 'BizCity_Intent_Planner' ) ) {
            $plan = BizCity_Intent_Planner::instance()->get_plan( $goal );
            if ( $plan ) {
                $parts = [];
                foreach ( [ 'required_slots', 'optional_slots' ] as $group ) {
                    $is_req = $group === 'required_slots';
                    foreach ( $plan[ $group ] ?? [] as $field => $cfg ) {
                        $type  = $cfg['type'] ?? 'text';
                        $label = $cfg['label'] ?? ( $cfg['prompt'] ?? $field );
                        $label = mb_substr( preg_replace( '/[\x{1F300}-\x{1FAFF}]+/u', '', $label ), 0, 60, 'UTF-8' );
                        $req_tag = $is_req ? 'bắt buộc' : 'tùy chọn';
                        $parts[] = "- {$field} ({$type}, {$req_tag}): {$label}";
                    }
                }
                if ( $parts ) return "Slots:\n" . implode( "\n", $parts );
            }
        }

        // Fallback to pattern extract fields
        if ( ! empty( $slots ) ) {
            return 'Slots: ' . implode( ', ', $slots );
        }

        return '';
    }

    /**
     * Detect post-tool satisfaction from user's message (regex, 0 LLM cost).
     *
     * Called when the previous conversation just completed a tool within 2 min.
     * Returns 'satisfied' if user acknowledges, 'retry' if user wants redo, null if no match.
     *
     * @since v4.0.0 Phase 13 — Dual Context Architecture
     *
     * @param string $message  User's message text.
     * @return string|null     'satisfied', 'retry', or null.
     */
    public function detect_post_tool_satisfaction( $message ) {
        $msg = mb_strtolower( trim( $message ), 'UTF-8' );

        // Satisfied patterns — user accepts the tool result
        $satisfied = [
            '/^(ok|ok[éeè]?|okay|okie)$/u',
            '/^(cảm ơn|cám ơn|thank|thanks|tks|tq)$/u',
            '/\b(tốt rồi|đúng rồi|xong rồi|ổn rồi|được rồi)\b/u',
            '/\b(hay lắm|tuyệt|perfect|great|good|nice)\b/u',
            '/^(👍|👌|✅|🙏|❤️)$/u',
        ];

        foreach ( $satisfied as $pattern ) {
            if ( preg_match( $pattern, $msg ) ) {
                return 'satisfied';
            }
        }

        // Retry patterns — user wants to redo/fix
        $retry = [
            '/\b(sai rồi|chưa đúng|không đúng|sai bét)\b/u',
            '/\b(làm lại|thử lại|retry|redo)\b/u',
            '/\b(chưa hài lòng|chưa ổn|chưa được)\b/u',
            '/\b(chỉnh lại|sửa lại|fix)\b/u',
        ];

        foreach ( $retry as $pattern ) {
            if ( preg_match( $pattern, $msg ) ) {
                return 'retry';
            }
        }

        return null;
    }

    /**
     * Primary LLM-based intent classifier — 2-tier architecture (v3.5.0).
     *
     * Tier 1: Fast classification → intent + goal + confidence (no entities).
     * Tier 2: Entity extraction → ONLY for new_goal, focused on that goal's slots.
     *
     * Non-new_goal intents skip Tier 2 entirely — entities are handled by
     * fill_waiting_field_entities() / extract_entities() regex in the caller.
     *
     * @param string     $message        User's message text.
     * @param array|null $conversation   Active conversation data.
     * @param array      $attachments    Attached files.
     * @param string     $provider_hint  Provider ID from @mention (bias classification).
     * @return array|null Classification result, or null if LLM unavailable.
     */
    private function classify_with_llm_primary( $message, $conversation = null, $attachments = [], $provider_hint = '' ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return null;
        }

        // ════════════════════════════════════════════════════════════════
        //  TIER 1 — Fast intent + goal classification
        // ════════════════════════════════════════════════════════════════
        $tier1 = $this->classify_with_llm_fast( $message, $conversation, $attachments, $provider_hint );

        if ( ! $tier1 || ! empty( $tier1['_llm_error'] ) ) {
            return $tier1; // LLM error — pass through to pattern fallback
        }

        // ════════════════════════════════════════════════════════════════
        //  TIER 2 — Entity extraction (for new_goal and continue_goal)
        //
        //  v3.5.2: Always use LLM for entity extraction. Regex heuristics
        //  removed — LLM handles all slot extraction with better accuracy.
        //  For small_talk / end_conversation → no entities needed.
        // ════════════════════════════════════════════════════════════════
        $tier2_entities = [];
        $tier2_ms       = 0;
        $tier2_model    = '';
        $tier2_tokens   = [];

        $needs_extraction = in_array( $tier1['intent'], [ 'new_goal', 'continue_goal' ], true )
            && ! empty( $tier1['goal'] )
            && ! empty( trim( $message ) );

        if ( $needs_extraction ) {
            $slot_schema = $this->get_goal_slot_schema( $tier1['goal'] );
            if ( $slot_schema ) {
                $tier2_result = $this->extract_entities_with_llm(
                    $message, $tier1['goal'], $tier1['goal_label'], $slot_schema
                );
                if ( $tier2_result && ! empty( $tier2_result['entities'] ) ) {
                    $tier2_entities = $tier2_result['entities'];
                    $tier2_ms       = $tier2_result['_llm_ms'] ?? 0;
                    $tier2_model    = $tier2_result['_llm_model'] ?? '';
                    $tier2_tokens   = $tier2_result['_llm_tokens'] ?? [];
                }
            }
        }

        // ── Combine Tier 1 + Tier 2 ──
        $total_ms = ( $tier1['_llm_ms'] ?? 0 ) + $tier2_ms;

        return [
            'intent'          => $tier1['intent'],
            'goal'            => $tier1['goal'],
            'goal_label'      => $tier1['goal_label'],
            'entities'        => $this->sanitize_llm_entities( $tier2_entities ),
            'confidence'      => $tier1['confidence'],
            'suggested_tools' => [],
            'missing_fields'  => [],
            'goal_objective'  => '',
            'method'          => 'llm',
            '_llm_ms'         => $total_ms,
            '_llm_model'      => $tier1['_llm_model'] . ( $tier2_model ? '+' . $tier2_model : '' ),
            '_llm_tokens'     => $tier1['_llm_tokens'],
            '_tier2_ms'       => $tier2_ms,
            '_tier2_tokens'   => $tier2_tokens,
        ];
    }

    /**
     * Tier 1: Fast LLM intent classification.
     *
     * Compact prompt with goal list only (no slots).
     * Returns 3 fields: intent, goal, confidence.
     * ~100 max_tokens, ~40% fewer input tokens vs old unified prompt.
     *
     * @param string     $message
     * @param array|null $conversation
     * @param array      $attachments
     * @param string     $provider_hint
     * @return array|null
     */
    private function classify_with_llm_fast( $message, $conversation = null, $attachments = [], $provider_hint = '' ) {
        // ── Build conversation context (minimal) ──
        $conv_context = '';
        if ( $conversation && ! empty( $conversation['goal'] ) ) {

            // ── v4.2: Cross-provider @mention — skip WAITING_USER context ──
            // When user @mentions a different provider, don't tell LLM about the
            // old goal's WAITING_USER state. This prevents LLM from classifying
            // as provide_input for the wrong goal.
            $skip_conv_ctx = false;
            if ( $provider_hint && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
                $conv_goal_owner = BizCity_Intent_Provider_Registry::instance()->get_provider_for_goal( $conversation['goal'] );
                if ( $conv_goal_owner && $conv_goal_owner->get_id() !== $provider_hint ) {
                    $skip_conv_ctx = true;
                }
            }

            if ( ! $skip_conv_ctx ) {
                $conv_context = "\nCONTEXT: goal={$conversation['goal']} status={$conversation['status']}"
                    . " waiting=" . ( $conversation['waiting_field'] ?? 'none' );

                // ── O3: Inject waiting field schema for WAITING_USER ──
                // When user is filling slots, tell LLM what field type + skip phrases
                // to help it correctly classify "bỏ qua" as provide_input, not new_goal.
                if ( ( $conversation['status'] ?? '' ) === 'WAITING_USER'
                    && ! empty( $conversation['waiting_field'] )
                ) {
                    $field_schema = $this->build_waiting_field_schema( $conversation );
                    if ( $field_schema ) {
                        $conv_context .= "\n" . $field_schema;
                    }
                }
            }
        }

        $has_images = ! empty( $attachments ) ? "\n[có ảnh đính kèm]" : '';

        // ── @mention provider hint ──
        $provider_hint_context = '';
        if ( $provider_hint && class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            $provider = BizCity_Intent_Provider_Registry::instance()->get( $provider_hint );
            if ( $provider ) {
                $provider_hint_context = "\n⚠️ Ưu tiên goal của agent \"{$provider->get_name()}\" (@{$provider_hint}).";
            }
        }

        // ── Compact goal list (no slots — Tier 1 doesn't need them) ──
        $goal_list = $this->build_goal_list_compact( 1200 );

        // ── System prompt — minimal, 3-field output ──
        $system = <<<PROMPT
Phân loại intent cho AI Agent BizCity. Trả JSON 3 trường.

INTENTS: new_goal | provide_input | continue_goal | small_talk | end_conversation

{$goal_list}

QUY TẮC:
1. Match goal → new_goal (kể cả đang có goal cũ)
2. WAITING_USER + trả lời → provide_input
3. WAITING_USER + yêu cầu MỚI → new_goal
4. confidence ≥ 0.8 khi chắc chắn
5. Nếu goal có [khi nói: ...] → khi user dùng từ khóa đó → ưu tiên goal này

JSON duy nhất, KHÔNG giải thích:
{"intent":"...","goal":"...","confidence":0.0}
PROMPT;

        $user_prompt = "Tin nhắn: \"{$message}\"{$has_images}{$conv_context}{$provider_hint_context}";

        // ── Call LLM ──
        $t_start = microtime( true );

        $result = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
            [
                'model'       => 'openai/gpt-5.2-codex',  // tool registry context
                'purpose'     => 'router',
                'temperature' => 0.05,
                'max_tokens'  => 100,
                'no_fallback' => false,
            ]
        );

        $t_ms = round( ( microtime( true ) - $t_start ) * 1000, 1 );

        if ( empty( $result['success'] ) || empty( $result['message'] ) ) {
            return [ '_llm_error' => $result['error'] ?? 'empty_response', '_llm_ms' => $t_ms ];
        }

        // ── Parse JSON ──
        $parsed = $this->parse_llm_json( $result['message'] );
        if ( ! $parsed || empty( $parsed['intent'] ) ) {
            return [ '_llm_error' => 'json_parse_failed', '_llm_raw' => $result['message'], '_llm_ms' => $t_ms ];
        }

        // Validate intent
        $valid_intents = [ 'small_talk', 'new_goal', 'continue_goal', 'end_conversation', 'provide_input' ];
        if ( ! in_array( $parsed['intent'], $valid_intents, true ) ) {
            $parsed['intent'] = 'small_talk';
        }

        // Validate goal exists
        $parsed_goal = $parsed['goal'] ?? '';
        $goal_label  = '';
        if ( $parsed_goal ) {
            $goal_config = $this->find_goal_config( $parsed_goal );
            if ( $goal_config ) {
                $goal_label = $goal_config['label'] ?? $parsed_goal;
            } else {
                // Try fuzzy match
                $best_match = $this->fuzzy_match_goal( $parsed_goal );
                if ( $best_match ) {
                    $parsed_goal = $best_match['goal'];
                    $goal_label  = $best_match['label'];
                } else {
                    $parsed['intent'] = 'small_talk';
                    $parsed_goal      = '';
                }
            }
        }

        return [
            'intent'      => $parsed['intent'],
            'goal'        => $parsed_goal,
            'goal_label'  => $goal_label,
            'confidence'  => floatval( $parsed['confidence'] ?? 0.7 ),
            '_llm_ms'     => $t_ms,
            '_llm_model'  => $result['model'] ?? '',
            '_llm_tokens' => $result['usage'] ?? [],
        ];
    }

    /**
     * Tier 2: LLM entity extraction for a specific goal.
     *
     * Called ONLY when Tier 1 returns new_goal AND regex extraction
     * didn't find enough entities. Focused prompt with single-goal slot schema.
     *
     * @param string $message    User's message.
     * @param string $goal       Goal ID.
     * @param string $goal_label Goal label.
     * @param string $slot_schema Slot description string.
     * @return array|null  ['entities' => [...], '_llm_ms' => float, ...] or null.
     */
    private function extract_entities_with_llm( string $message, string $goal, string $goal_label, string $slot_schema ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return null;
        }

        $system = <<<PROMPT
Trích xuất thông tin CỤ THỂ từ tin nhắn cho goal "{$goal}" ({$goal_label}).
{$slot_schema}

QUY TẮC QUAN TRỌNG:
- Chỉ trích xuất field có GIÁ TRỊ CỤ THỂ, RÕ RÀNG trong tin nhắn.
- KHÔNG bịa giá trị.
- KHÔNG dùng từ chỉ hành động/lệnh (viết, đăng, tạo, xem, tra, ghi, ghi nhật ký, log, post, ...) làm giá trị cho slot.
- Nếu tin nhắn chỉ là yêu cầu/lệnh mà KHÔNG chứa nội dung/chủ đề cụ thể → trả về {}
- "topic" phải là CHỦ ĐỀ THỰC SỰ (ví dụ: "AI", "marketing", "sức khỏe"), KHÔNG phải mô tả hành động.
- "food_input" phải là TÊN MÓN ĂN CỤ THỂ (ví dụ: "phở bò", "cơm tấm"), KHÔNG phải câu lệnh chứa từ "bữa ăn".

Ví dụ:
- "đăng bài viết nhé" → {} (chỉ là lệnh, không có topic cụ thể)
- "viết bài về AI" → {"topic":"AI"}
- "tạo mindmap về marketing" → {"topic":"marketing"}
- "đăng bài facebook" → {} (không có topic, "facebook" là nền tảng)
- "viết bài" → {} (chỉ là lệnh)
- "viết bài về xu hướng công nghệ 2025" → {"topic":"xu hướng công nghệ 2025"}
- "ghi nhật ký bữa ăn giúp tôi nhé" → {} (chỉ là lệnh, KHÔNG có tên món ăn)
- "ghi bữa ăn: phở bò, trà đá" → {"food_input":"phở bò, trà đá"}

Trả JSON entities duy nhất.
PROMPT;

        $t_start = microtime( true );

        $result = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $message ],
            ],
            [
                'model'       => 'openai/gpt-5.2-codex',  // tool registry context
                'purpose'     => 'router',
                'temperature' => 0.05,
                'max_tokens'  => 200,
                'no_fallback' => false,
            ]
        );

        $t_ms = round( ( microtime( true ) - $t_start ) * 1000, 1 );

        if ( empty( $result['success'] ) || empty( $result['message'] ) ) {
            return null;
        }

        $parsed = $this->parse_llm_json( $result['message'] );
        if ( ! is_array( $parsed ) ) {
            return null;
        }

        return [
            'entities'    => $this->sanitize_llm_entities( $parsed ),
            '_llm_ms'     => $t_ms,
            '_llm_model'  => $result['model'] ?? '',
            '_llm_tokens' => $result['usage'] ?? [],
        ];
    }

    /**
     * Parse JSON from LLM response (strip markdown fences, find first { ... }).
     *
     * @param string $raw LLM response text.
     * @return array|null Parsed JSON, or null on failure.
     */
    private function parse_llm_json( string $raw ) {
        $json = trim( $raw );
        $json = preg_replace( '/^```(?:json)?\s*/i', '', $json );
        $json = preg_replace( '/\s*```$/', '', $json );

        if ( ( $pos = strpos( $json, '{' ) ) !== false ) {
            $json = substr( $json, $pos );
        }
        if ( ( $pos = strrpos( $json, '}' ) ) !== false ) {
            $json = substr( $json, 0, $pos + 1 );
        }

        $parsed = json_decode( $json, true );
        return is_array( $parsed ) ? $parsed : null;
    }

    /**
     * Fuzzy-match a goal ID that the LLM may have slightly mis-named.
     *
     * @param string $goal_guess  The goal ID from LLM.
     * @return array|null         Matching config, or null.
     */
    private function fuzzy_match_goal( $goal_guess ) {
        $guess_lower = mb_strtolower( $goal_guess, 'UTF-8' );
        $best        = null;
        $best_score  = 0;

        foreach ( $this->goal_patterns as $config ) {
            $g = $config['goal'] ?? '';
            if ( empty( $g ) ) continue;

            // Exact substring match
            if ( strpos( $guess_lower, mb_strtolower( $g, 'UTF-8' ) ) !== false
                || strpos( mb_strtolower( $g, 'UTF-8' ), $guess_lower ) !== false
            ) {
                return $config;
            }

            // Levenshtein similarity
            $dist  = levenshtein( $guess_lower, mb_strtolower( $g, 'UTF-8' ) );
            $max_l = max( strlen( $guess_lower ), strlen( $g ) );
            $score = $max_l > 0 ? 1 - ( $dist / $max_l ) : 0;

            if ( $score > $best_score && $score > 0.6 ) {
                $best_score = $score;
                $best       = $config;
            }
        }

        return $best;
    }

    /**
     * Extract text entities alongside an image attachment.
     *
     * When user sends image + text together (e.g. "giá 120k" with a product image),
     * the image is already stored in _images. This method additionally parses the
     * text to extract other slot values (price, name, description, etc.) from the
     * plan schema so they don't get lost.
     *
     * @param array  &$result       Classification result (modified in-place).
     * @param string $message       User's raw text (non-empty).
     * @param array  $conversation  Active conversation data.
     */
    private function extract_text_entities_alongside_image( array &$result, $message, $conversation ) {
        $goal = $conversation['goal'] ?? '';
        if ( empty( $goal ) ) return;

        $planner = BizCity_Intent_Planner::instance();
        $plan    = $planner->get_plan( $goal );
        if ( ! $plan ) return;

        $all_slots = array_merge(
            $plan['required_slots'] ?? [],
            $plan['optional_slots'] ?? []
        );

        $current_slots = json_decode( $conversation['slots_json'] ?? '{}', true ) ?: [];
        $image_field   = $conversation['waiting_field'] ?? '';

        // Try to match text against unfilled non-image slots
        foreach ( $all_slots as $field => $config ) {
            if ( $field === $image_field ) continue;                   // Already handled by _images
            if ( $field === '_meta' || $field === 'message' ) continue;
            if ( ( $config['type'] ?? '' ) === 'image' ) continue;    // Another image slot
            if ( ! empty( $current_slots[ $field ] ) ) continue;      // Already filled

            // Price extraction: look for numeric patterns with currency markers
            // Only extract for explicitly price-named fields to avoid filling unrelated number slots
            $is_price_field = in_array( $field, [ 'price', 'gia', 'giá', 'cost', 'amount', 'regular_price', 'sale_price' ], true );
            if ( $is_price_field || ( $config['type'] ?? '' ) === 'number' ) {
                if ( preg_match( '/(\d[\d.,]*)\s*(k|K|đ|d|vnđ|vnd|nghìn|ngàn|triệu|tr)?/u', $message, $m ) ) {
                    $num = str_replace( [ '.', ',' ], '', $m[1] );
                    $suffix = mb_strtolower( $m[2] ?? '', 'UTF-8' );
                    if ( in_array( $suffix, [ 'k', 'nghìn', 'ngàn' ], true ) ) {
                        $num = intval( $num ) * 1000;
                    } elseif ( in_array( $suffix, [ 'triệu', 'tr' ], true ) ) {
                        $num = intval( $num ) * 1000000;
                    }
                    $result['entities'][ $field ] = (string) $num;
                    // Price-named fields take priority; for generic number fields,
                    // only fill if no price field was already matched above.
                    if ( $is_price_field ) break;
                    continue;
                }
            }

            // Generic text slot: if only one unfilled text slot left, assign the text
            // (Don't auto-fill if there are multiple unfilled text slots — ambiguous)
        }

        // If message contains text beyond just a number, check for name/description hints
        $text_cleaned = preg_replace( '/\d[\d.,]*\s*(k|K|đ|d|vnđ|vnd|nghìn|ngàn|triệu|tr)?/u', '', $message );
        $text_cleaned = trim( $text_cleaned );
        if ( mb_strlen( $text_cleaned, 'UTF-8' ) >= 3 ) {
            // Look for unfilled name/title/description slots
            foreach ( $all_slots as $field => $config ) {
                if ( $field === $image_field ) continue;
                if ( ( $config['type'] ?? '' ) === 'image' ) continue;
                if ( ( $config['type'] ?? '' ) === 'number' ) continue;
                if ( ! empty( $current_slots[ $field ] ) ) continue;
                if ( isset( $result['entities'][ $field ] ) ) continue; // Already extracted above

                if ( in_array( $field, [ 'name', 'title', 'tên', 'product_name', 'description', 'mô_tả' ], true ) ) {
                    $result['entities'][ $field ] = $text_cleaned;
                    break; // Only fill the first matching text slot
                }
            }
        }
    }

    /**
     * Fill waiting-field entities from user's message (slot extraction).
     *
     * v3.8: Now uses LLM Slot Bridge for structured types (choice, number, date)
     * and multi-slot extraction. Falls back to simple text mapping for plain text
     * fields and image URL handling.
     *
     * @param array  &$result       Classification result (modified in-place).
     * @param string $message       User's raw message.
     * @param array  $conversation  Active conversation data.
     */
    private function fill_waiting_field_entities( array &$result, $message, $conversation ) {
        $waiting_field  = $conversation['waiting_field'] ?? '';
        if ( empty( $waiting_field ) ) return;

        // GUARD: Don't map empty message to a text slot — prevents image-only messages
        // from overwriting slots with empty strings and triggering premature tool execution.
        if ( empty( trim( $message ) ) ) return;

        // ── image_url special handling (regex, not LLM) ──
        if ( $waiting_field === 'image_url' ) {
            if ( preg_match( '/https?:\/\/\S+/u', $message, $url_m ) ) {
                $result['entities'][ $waiting_field ] = rtrim( $url_m[0], '.,;)' );
                $before_url = preg_replace( '/https?:\/\/\S+/us', '', $message );
                $before_url = preg_replace( '/\b(hình ảnh|ảnh|image|đây|link|url)\b/ui', '', $before_url );
                $before_url = preg_replace( '/\b(chủ đề|chủ đề là|chủ đề về|viết về|về|topic)\b/ui', '', $before_url );
                $cleaned    = trim( preg_replace( '/[,;:.!?\s]+$/u', '', trim( $before_url ) ) );
                if ( mb_strlen( $cleaned, 'UTF-8' ) >= 5 ) {
                    $result['entities']['topic'] = $cleaned;
                }
            } else {
                $result['entities']['_waiting_field'] = $waiting_field;
                return;
            }
            $result['entities']['_waiting_field'] = $waiting_field;
            return;
        }

        // ── GUARD: System fields (starting with _) — simple text mapping only ──
        // Internal fields like _confirm_execute are NOT plan slots.
        // They must bypass LLM Slot Bridge entirely — the Planner / Engine
        // will interpret the raw value (e.g. "ok" for confirmation).
        if ( str_starts_with( $waiting_field, '_' ) ) {
            $result['entities'][ $waiting_field ]  = $message;
            $result['entities']['_waiting_field'] = $waiting_field;
            return;
        }

        // ── v3.8 LLM Slot Bridge: structured type extraction ──
        // For choice/number/date slots, or when multiple slots are still missing,
        // use LLM to intelligently extract + normalize slot values.
        $goal = $conversation['goal'] ?? '';
        $plan = $goal ? BizCity_Intent_Planner::instance()->get_plan( $goal ) : null;

        if ( $plan && function_exists( 'bizcity_openrouter_chat' ) ) {
            $all_slots    = array_merge( $plan['required_slots'] ?? [], $plan['optional_slots'] ?? [] );
            $field_config = $all_slots[ $waiting_field ] ?? [];
            $field_type   = $field_config['type'] ?? 'text';

            // Determine if LLM extraction is warranted:
            // 1. Structured types (choice, number, date) always benefit from LLM
            // 2. Multiple missing slots → LLM can multi-slot extract from one message
            $current_slots  = $conversation['slots'] ?? [];
            $missing_count  = 0;
            foreach ( $all_slots as $f => $cfg ) {
                if ( ( $cfg['type'] ?? '' ) === 'image' ) continue;
                $val = $current_slots[ $f ] ?? '';
                if ( is_array( $val ) ? empty( $val ) : ( $val === '' || $val === null ) ) {
                    $missing_count++;
                }
            }

            $needs_llm = in_array( $field_type, [ 'choice', 'number', 'date' ], true )
                         || $missing_count > 1;

            if ( $needs_llm ) {
                $llm_extract = $this->extract_provide_input_with_llm(
                    $message, $conversation, $plan, $waiting_field
                );

                if ( $llm_extract ) {
                    if ( ! empty( $llm_extract['understood'] ) && ! empty( $llm_extract['slots'] ) ) {
                        // LLM successfully extracted slots
                        foreach ( $llm_extract['slots'] as $slot_name => $slot_val ) {
                            // Only fill slots that exist in the plan schema
                            if ( isset( $all_slots[ $slot_name ] ) && $slot_val !== '' && $slot_val !== null ) {
                                $result['entities'][ $slot_name ] = $slot_val;
                            }
                        }
                        // Ensure the primary waiting_field is marked
                        $result['entities']['_waiting_field'] = $waiting_field;

                        // Clear retry counter on success
                        $result['entities']['_slot_retry_count'] = 0;

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[INTENT-ROUTER] v3.8 LLM Slot Bridge: extracted='
                                     . implode( ',', array_keys( $llm_extract['slots'] ) )
                                     . ' | field=' . $waiting_field
                                     . ' | goal=' . $goal );
                        }
                        return;
                    }

                    // LLM could NOT understand the user's answer
                    if ( empty( $llm_extract['understood'] ) ) {
                        $result['entities']['_waiting_field']           = $waiting_field;
                        $result['entities']['_slot_extract_failed']     = true;
                        $result['entities']['_slot_extract_clarification'] = $llm_extract['clarification'] ?? '';

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[INTENT-ROUTER] v3.8 LLM Slot Bridge FAILED: field='
                                     . $waiting_field . ' | goal=' . $goal
                                     . ' | clarification=' . ( $llm_extract['clarification'] ?? 'none' ) );
                        }
                        return;
                    }
                }
                // LLM call itself failed → fall through to simple mapping
            }
        }

        // ── Fallback: simple text mapping (original behavior) ──
        // v4.7.1: Type guard — reject raw message that doesn't match expected field type.
        // When LLM is unavailable (no API key, network error), the fallback path still
        // needs to validate structured types to prevent garbage data in slots.
        if ( $plan ) {
            $all_slots_fb  = array_merge( $plan['required_slots'] ?? [], $plan['optional_slots'] ?? [] );
            $fb_config     = $all_slots_fb[ $waiting_field ] ?? [];
            $fb_type       = $fb_config['type'] ?? 'text';

            if ( $fb_type === 'number' && ! preg_match( '/\d/', $message ) ) {
                // Message contains no digits — can't be a valid number
                $result['entities']['_waiting_field']           = $waiting_field;
                $result['entities']['_slot_extract_failed']     = true;
                $result['entities']['_slot_extract_clarification'] = $fb_config['prompt']
                    ?? "Vui lòng nhập một số cho {$waiting_field}.";
                return;
            }
            if ( $fb_type === 'choice' && ! empty( $fb_config['choices'] ) ) {
                // Choice type without LLM — mark as failed, let retry ask user
                $options = implode( ', ', array_values( $fb_config['choices'] ) );
                $result['entities']['_waiting_field']           = $waiting_field;
                $result['entities']['_slot_extract_failed']     = true;
                $result['entities']['_slot_extract_clarification'] = "Vui lòng chọn: {$options}";
                return;
            }
        }

        $value_to_store = $message;

        if ( in_array( $waiting_field, [ 'topic', 'content', 'what', 'title' ], true ) ) {
            if ( preg_match( '/https?:\/\/\S+/u', $message, $url_m ) ) {
                $result['entities']['image_url'] = rtrim( $url_m[0], '.,;)' );
                $topic_text = preg_replace( '/https?:\/\/\S+/us', '', $message );
                $topic_text = preg_replace( '/\b(hình ảnh|ảnh|image|đây|link|url)\b/ui', '', $topic_text );
                $topic_text = preg_replace( '/\b(chủ đề|chủ đề là|chủ đề về|viết về|về|topic)\b/ui', '', $topic_text );
                $topic_text = trim( preg_replace( '/[,;:.!?\s]+$/u', '', trim( $topic_text ) ) );
                $value_to_store = $topic_text ?: $message;
            }
        }

        $result['entities'][ $waiting_field ]  = $value_to_store;
        $result['entities']['_waiting_field'] = $waiting_field;
    }

    /**
     * LLM Slot Bridge — extract slot values from user message during HIL provide_input (v3.8).
     *
     * Lightweight LLM call (~100-200ms) that bridges user's natural language answer
     * with the plugin's structured slot requirements:
     *   - Fuzzy-matches choice values ("tài chính" → key "tai_chinh")
     *   - Normalizes number/date formats
     *   - Multi-slot extraction ("tài chính, 3 lá" → question_focus + spread)
     *   - Uses conversation turn history for context
     *
     * @param string $message       User's raw message.
     * @param array  $conversation  Active conversation data (goal, slots, conv_id).
     * @param array  $plan          Goal plan (required_slots, optional_slots).
     * @param string $waiting_field The specific field being asked.
     * @return array|null {
     *   @type array  $slots          Extracted {field: value} pairs.
     *   @type bool   $understood     Whether LLM understood the user's answer.
     *   @type string $clarification  Clarification message if not understood.
     * } or null on LLM failure.
     */
    private function extract_provide_input_with_llm( $message, $conversation, $plan, $waiting_field ) {
        $goal       = $conversation['goal'] ?? '';
        $goal_label = $conversation['goal_label'] ?? $goal;
        $conv_id    = $conversation['conversation_id'] ?? '';

        // ── Build slot schema context ──
        $all_slots     = array_merge( $plan['required_slots'] ?? [], $plan['optional_slots'] ?? [] );
        $current_slots = $conversation['slots'] ?? [];
        $waiting_config = $all_slots[ $waiting_field ] ?? [];

        $schema_lines = [];
        foreach ( $all_slots as $field => $config ) {
            if ( ( $config['type'] ?? '' ) === 'image' ) continue; // Skip image slots (handled by regex)
            $type     = $config['type'] ?? 'text';
            $filled   = isset( $current_slots[ $field ] ) && $current_slots[ $field ] !== '' && $current_slots[ $field ] !== null;
            $is_req   = isset( $plan['required_slots'][ $field ] );
            $status   = $filled ? 'FILLED("' . mb_substr( (string) $current_slots[ $field ], 0, 50, 'UTF-8' ) . '")' : 'MISSING';
            $marker   = ( $field === $waiting_field ) ? ' ← ĐANG HỎI' : '';
            $line     = "- {$field}: type={$type}, " . ( $is_req ? 'required' : 'optional' ) . ", {$status}{$marker}";

            if ( ! empty( $config['choices'] ) && is_array( $config['choices'] ) ) {
                $choices_str = [];
                foreach ( $config['choices'] as $key => $label ) {
                    // Plain arrays (numeric auto-keys) → use value as key
                    $display_key = is_int( $key ) ? $label : $key;
                    $choices_str[] = "{$display_key}=\"" . strip_tags( $label ) . '"';
                }
                $line .= "\n  choices: [" . implode( ', ', $choices_str ) . ']';
            }
            $schema_lines[] = $line;
        }
        $schema_text = implode( "\n", $schema_lines );

        // ── Fetch recent conversation turns for context ──
        $turns_context = '';
        if ( $conv_id && class_exists( 'BizCity_Intent_Conversation' ) ) {
            $turns = BizCity_Intent_Conversation::instance()->get_turns( $conv_id, 6 );
            if ( ! empty( $turns ) ) {
                $turn_lines = [];
                foreach ( array_slice( $turns, -6 ) as $turn ) {
                    $role = $turn['role'] === 'user' ? 'User' : 'Bot';
                    $text = mb_substr( $turn['content'] ?? '', 0, 200, 'UTF-8' );
                    if ( $text !== '' ) {
                        $turn_lines[] = "{$role}: {$text}";
                    }
                }
                $turns_context = implode( "\n", $turn_lines );
            }
        }

        // ── Build LLM prompt ──
        $system = <<<PROMPT
Bạn là slot extraction engine cho goal "{$goal_label}".
Phân tích tin nhắn user và extract giá trị cho các slot.

SLOT SCHEMA:
{$schema_text}

QUY TẮC:
1. Với choice type: MAP user text về đúng KEY (ví dụ "tài chính" → "tai_chinh", "3 lá" → "3").
   Fuzzy match: loại bỏ emoji, dấu, viết hoa/thường, gần nghĩa đều match.
2. Nếu user trả lời nhiều slot cùng lúc → extract TẤT CẢ (ví dụ "tài chính, 3 lá" → 2 slots).
3. Chỉ extract slot có giá trị RÕ RÀNG trong tin nhắn. KHÔNG bịa.
4. Nếu user muốn SỬA/THAY ĐỔI slot đã FILLED (ví dụ "sửa chủ đề thành X", "đổi thành Y") → extract giá trị MỚI cho slot đó, sẽ override giá trị cũ.
5. Nếu không hiểu user muốn trả lời gì → understood=false + viết clarification ngắn gọn.
6. Trả JSON duy nhất, KHÔNG text khác.

ĐỊNH DẠNG:
{"slots": {"field_name": "value"}, "understood": true}
hoặc:
{"slots": {}, "understood": false, "clarification": "Câu hỏi gợi ý ngắn gọn"}
PROMPT;

        $user_content = $message;
        if ( $turns_context ) {
            $user_content = "[Lịch sử hội thoại gần đây]\n{$turns_context}\n\n[Tin nhắn hiện tại]\n{$message}";
        }

        $t_start = microtime( true );

        $ai = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user_content ],
            ],
            [
                'purpose'     => 'slot_extract',
                'temperature' => 0.05,
                'max_tokens'  => 250,
                'no_fallback' => false,
            ]
        );

        $t_ms = round( ( microtime( true ) - $t_start ) * 1000, 1 );

        if ( empty( $ai['success'] ) || empty( $ai['message'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-ROUTER] v3.8 LLM Slot Bridge: LLM call FAILED | goal=' . $goal . ' | ms=' . $t_ms );
            }
            return null;
        }

        $parsed = $this->parse_llm_json( $ai['message'] );

        if ( ! is_array( $parsed ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[INTENT-ROUTER] v3.8 LLM Slot Bridge: JSON parse FAILED | raw=' . mb_substr( $ai['message'], 0, 200, 'UTF-8' ) );
            }
            return null;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[INTENT-ROUTER] v3.8 LLM Slot Bridge: OK | ms=' . $t_ms
                     . ' | model=' . ( $ai['model'] ?? '' )
                     . ' | understood=' . ( ! empty( $parsed['understood'] ) ? 'true' : 'false' )
                     . ' | slots=' . wp_json_encode( $parsed['slots'] ?? [], JSON_UNESCAPED_UNICODE ) );
        }

        return [
            'slots'         => is_array( $parsed['slots'] ?? null ) ? $parsed['slots'] : [],
            'understood'    => ! empty( $parsed['understood'] ),
            'clarification' => $parsed['clarification'] ?? '',
            '_llm_ms'       => $t_ms,
            '_llm_model'    => $ai['model'] ?? '',
        ];
    }

    /**
     * Strip LLM-hallucinated entity values.
     *
     * The LLM knows about attached images via the system prompt hint
     * ("Tin nhắn có đính kèm ảnh") but does NOT have the actual URL —
     * it often generates placeholder text like "(ảnh đính kèm)".
     * That placeholder would overwrite the real URL from _images.
     *
     * @param array $entities Raw entities from LLM JSON.
     * @return array Cleaned entities.
     */
    private function sanitize_llm_entities( array $entities ): array {
        // image_url must be a valid URL; strip text placeholders
        if ( ! empty( $entities['image_url'] )
            && is_string( $entities['image_url'] )
            && ! filter_var( $entities['image_url'], FILTER_VALIDATE_URL )
        ) {
            unset( $entities['image_url'] );
        }
        return $entities;
    }

    /* ================================================================
     *  Pattern helpers
     * ================================================================ */

    /**
     * Check if text matches end-of-conversation patterns.
     *
     * @param string $text Lowercase text.
     * @return bool
     */
    private function matches_end( $text ) {
        foreach ( $this->end_patterns as $pattern ) {
            if ( preg_match( $pattern, $text ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Entity extraction from text — DEPRECATED in v3.5.2.
     *
     * Regex-based entity extraction has been replaced by LLM-based
     * extraction (extract_entities_with_llm) for all slot types.
     * This method is kept as a stub for backward compatibility
     * but always returns an empty array.
     *
     * @deprecated 3.5.2 Use LLM-based extraction instead.
     * @param string $text
     * @param array  $fields Fields to look for.
     * @return array Always returns empty array.
     */
    private function extract_entities( $text, array $fields ) {
        // v3.5.2: All entity extraction handled by LLM.
        // Regex heuristics removed for better accuracy with Vietnamese text.
        return [];
    }

    /**
     * Get all resolved goal patterns (built-in + provider).
     *
     * Triggers pattern resolution if not yet done. Used by Mode Classifier
     * for regex goal pre-matching (v3.6.4).
     *
     * @return array  Pattern array: regex => { goal, label, extract, ... }
     */
    public function get_goal_patterns() {
        $this->resolve_patterns();
        return $this->goal_patterns;
    }

    /**
     * Lazily apply the bizcity_intent_goal_patterns filter.
     *
     * Deferred so that Provider Registry's boot() (which adds the filter)
     * runs before the filter is actually evaluated.
     */
    private function resolve_patterns() {
        if ( $this->patterns_resolved ) {
            return;
        }
        $this->patterns_resolved = true;
        $this->goal_patterns = apply_filters( 'bizcity_intent_goal_patterns', $this->goal_patterns );
    }

    /**
     * Build a compact schema description for the waiting field.
     *
     * Used by classify_with_llm_fast() to help LLM understand what the user
     * is being asked for. This makes "bỏ qua" / "skip" correctly classify
     * as provide_input instead of new_goal.
     *
     * @param array $conversation Active conversation data.
     * @return string Schema hint, e.g. "FIELD: image_url (image) — skip: bỏ qua, skip, không"
     */
    private function build_waiting_field_schema( array $conversation ): string {
        $goal  = $conversation['goal'] ?? '';
        $field = $conversation['waiting_field'] ?? '';
        if ( ! $goal || ! $field ) {
            return '';
        }

        // Get the plan from Planner
        if ( ! class_exists( 'BizCity_Intent_Planner' ) ) {
            return '';
        }
        $plan = BizCity_Intent_Planner::instance()->get_plan( $goal );
        if ( ! $plan ) {
            return '';
        }

        // Find field config in required or optional slots
        $config = $plan['required_slots'][ $field ]
            ?? $plan['optional_slots'][ $field ]
            ?? null;

        if ( ! $config ) {
            return "FIELD: {$field} (text) — user đang trả lời field này, \"bỏ qua\"/\"skip\" = provide_input";
        }

        $type    = $config['type'] ?? 'text';
        $parts   = [ "FIELD: {$field} ({$type})" ];

        // Add choices if present
        if ( ! empty( $config['choices'] ) && is_array( $config['choices'] ) ) {
            $choice_keys = array_keys( $config['choices'] );
            $parts[]     = 'choices: ' . implode( ', ', array_slice( $choice_keys, 0, 6 ) );
        }

        // Add skip_on hint
        $skip_on = $config['skip_on'] ?? [];
        if ( empty( $skip_on ) && isset( $plan['optional_slots'][ $field ] ) ) {
            // Default skip phrases for optional fields
            $skip_on = [ 'bỏ qua', 'skip', 'không', 'không cần', 'auto', 'tự tạo', 'next', 'tiếp' ];
        }
        if ( $skip_on ) {
            $parts[] = 'skip: ' . implode( ', ', array_slice( $skip_on, 0, 5 ) );
        }

        $parts[] = '→ Nếu user trả lời hoặc skip → provide_input (KHÔNG phải new_goal)';

        return implode( ' — ', $parts );
    }

    /**
     * Find the goal config (extract fields, label) for a given goal ID.
     *
     * @param string $goal Goal identifier.
     * @return array|null  Config from goal_patterns, or null.
     */
    private function find_goal_config( $goal ) {
        $this->resolve_patterns();
        foreach ( $this->goal_patterns as $config ) {
            if ( ( $config['goal'] ?? '' ) === $goal ) {
                return $config;
            }
        }
        return null;
    }

    /**
     * Detect whether a pattern regex came from built-in or a provider plugin.
     *
     * Compares the pattern against init_patterns() originals.
     * Anything not in the original set is assumed to come from a provider.
     *
     * @param string $pattern Regex pattern key.
     * @return string  'built-in' or provider slug (from _provider_source meta).
     */
    private function detect_pattern_source( $pattern ) {
        // Check if a _provider_source was set by the registry merge
        $config = $this->goal_patterns[ $pattern ] ?? [];
        if ( ! empty( $config['_provider_source'] ) ) {
            return $config['_provider_source'];
        }

        // Static list of built-in goal IDs (from init_patterns)
        static $built_in_goals = [
            'create_product', 'report', 'inventory_report', 'list_orders',
            'post_facebook', 'write_article', 'astro_forecast', 'daily_outlook',
            'find_customer', 'set_reminder', 'create_video', 'edit_product',
            'customer_stats', 'product_stats', 'inventory_journal',
            'warehouse_receipt', 'create_order', 'help_guide',
        ];

        $goal = $config['goal'] ?? '';
        return in_array( $goal, $built_in_goals, true ) ? 'built-in' : 'provider';
    }

    /* ================================================================
     *  Tool Registry Keyword Search (v4.3)
     *
     *  Searches bizcity_tool_registry by keywords extracted from the
     *  user's message. Scoring:
     *    - goal exact match:           20 pts
     *    - goal contains keyword:      15 pts
     *    - title contains keyword:     15 pts
     *    - goal_label contains:        12 pts
     *    - custom_hints contains:      10 pts
     *    - plugin name match:          10 pts
     *    - goal_description word:       2 pts (max 6)
     *    - custom_description word:     1 pt  (max 5)
     *
     *  Returns the best match or null.
     * ================================================================ */

    /**
     * Search tool_registry by keywords from user message.
     *
     * @param string $message_lower  Lowercased, trimmed user message.
     * @return array|null  { goal, goal_label, plugin, score, reason } or null.
     */
    private function search_tool_registry_by_message( string $message_lower ): ?array {
        if ( ! class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            return null;
        }

        $tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
        if ( empty( $tools ) ) {
            return null;
        }

        // Extract meaningful keywords (>= 2 chars, skip common Vietnamese stop words)
        $words = array_filter(
            preg_split( '/[\s,;.!?]+/u', $message_lower ),
            function( $w ) {
                return mb_strlen( $w, 'UTF-8' ) >= 2
                    && ! in_array( $w, [
                        'cho', 'tôi', 'mình', 'giúp', 'với', 'nhé', 'nha', 'đi',
                        'xin', 'vui', 'lòng', 'được', 'không', 'hay', 'hoặc',
                        'này', 'kia', 'đó', 'ấy', 'thì', 'là', 'của', 'và',
                        'có', 'cái', 'một', 'các', 'những', 'bạn', 'em', 'anh',
                    ], true );
            }
        );

        if ( empty( $words ) ) {
            return null;
        }

        $best = null;
        $best_score = 0;

        foreach ( $tools as $row ) {
            $score  = 0;
            $reason = '';

            $goal_lower  = mb_strtolower( $row['goal'] ?? '', 'UTF-8' );
            $title_lower = mb_strtolower( $row['title'] ?? '', 'UTF-8' );
            $label_lower = mb_strtolower( $row['goal_label'] ?? '', 'UTF-8' );
            $hints_lower = mb_strtolower( $row['custom_hints'] ?? '', 'UTF-8' );
            $gdesc_lower = mb_strtolower( $row['goal_description'] ?? '', 'UTF-8' );
            $cdesc_lower = mb_strtolower( $row['custom_description'] ?? '', 'UTF-8' );
            $plugin_lower = mb_strtolower( $row['plugin'] ?? '', 'UTF-8' );

            foreach ( $words as $kw ) {
                // 1. goal exact match (highest)
                if ( $goal_lower === $kw ) {
                    $score += 20;
                    $reason = $reason ?: 'goal_exact:' . $row['goal'];
                } elseif ( $goal_lower && mb_strpos( $goal_lower, $kw ) !== false ) {
                    $score += 15;
                    $reason = $reason ?: 'goal_contains:' . $row['goal'];
                }

                // 2. title
                if ( $title_lower && mb_strpos( $title_lower, $kw ) !== false ) {
                    $score += 15;
                    $reason = $reason ?: 'title:' . $row['title'];
                }

                // 3. goal_label
                if ( $label_lower && mb_strpos( $label_lower, $kw ) !== false ) {
                    $score += 12;
                    $reason = $reason ?: 'label:' . $row['goal_label'];
                }

                // 4. custom_hints (admin-curated)
                if ( $hints_lower && mb_strpos( $hints_lower, $kw ) !== false ) {
                    $score += 10;
                    $reason = $reason ?: 'hints:' . mb_substr( $row['custom_hints'], 0, 30, 'UTF-8' );
                }

                // 5. plugin name
                if ( $plugin_lower && mb_strpos( $plugin_lower, $kw ) !== false ) {
                    $score += 10;
                    $reason = $reason ?: 'plugin:' . $row['plugin'];
                }

                // 6. goal_description (lower weight)
                if ( $gdesc_lower && mb_strpos( $gdesc_lower, $kw ) !== false ) {
                    $score += 2;
                    $reason = $reason ?: 'gdesc';
                }

                // 7. custom_description (lowest)
                if ( $cdesc_lower && mb_strpos( $cdesc_lower, $kw ) !== false ) {
                    $score += 1;
                    $reason = $reason ?: 'cdesc';
                }
            }

            if ( $score > $best_score ) {
                $best_score = $score;
                $best = [
                    'goal'       => $row['goal'] ?: $row['tool_name'],
                    'goal_label' => $row['goal_label'] ?: $row['title'] ?: $row['tool_name'],
                    'plugin'     => $row['plugin'] ?? '',
                    'score'      => $score,
                    'reason'     => $reason,
                ];
            }
        }

        return $best;
    }
}
