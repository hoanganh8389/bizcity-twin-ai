<?php
/**
 * BizCoach Map — Intent Provider
 *
 * Registers astrology/coaching "skills" with the Intent Engine:
 *   • Goal patterns:  astro_forecast, daily_outlook
 *   • Plans:          slot schemas for the above goals
 *   • Tools:          generate_natal_report, get_transit_forecast
 *   • Context:        natal chart data, transit aspects, gen results
 *
 * @package BizCoach_Map
 * @since   0.1.0.36
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCoach_Intent_Provider extends BizCity_Intent_Provider {

    /* ── Identity ── */

    public function get_id() {
        return 'bizcoach';
    }

    public function get_name() {
        return 'BizCoach Map — Chiêm tinh & Coaching';
    }

    /* ================================================================
     *  Profile Context
     * ================================================================ */

    public function get_profile_page_url() {
        return home_url( '/chiem-tinh-profile/' );
    }

    public function get_profile_context( $user_id ) {
        if ( ! $user_id ) {
            return [
                'complete' => false,
                'context'  => '',
                'fallback' => 'Người dùng chưa đăng nhập. Hãy hướng dẫn họ đăng nhập để sử dụng dịch vụ chiêm tinh.',
            ];
        }

        global $wpdb;
        $t_astro = $wpdb->prefix . 'bccm_astro';

        // Check coachee profile
        $coachee = function_exists( 'bccm_get_or_create_user_coachee' )
            ? bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' )
            : null;

        $has_dob      = ! empty( $coachee['dob'] );
        $has_name     = ! empty( $coachee['full_name'] );
        $astro_row    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' LIMIT 1", $user_id
        ), ARRAY_A );
        $has_birth_time = ! empty( $astro_row['birth_time'] );
        $has_chart      = ! empty( $astro_row['summary'] );

        $complete = $has_dob && $has_birth_time;
        $profile_url = $this->get_profile_page_url();

        if ( ! $complete ) {
            $missing = [];
            if ( ! $has_dob )        $missing[] = 'ngày sinh';
            if ( ! $has_birth_time ) $missing[] = 'giờ sinh & nơi sinh';
            $fallback = sprintf(
                'Người dùng chưa khai báo %s. Hãy gợi ý họ nói "Tạo bản đồ sao" để khai báo thông tin qua chat, giúp AI đưa ra phân tích chính xác hơn.',
                implode( ', ', $missing )
            );
            return [
                'complete' => false,
                'context'  => $has_name ? "Tên: {$coachee['full_name']}" : '',
                'fallback' => $fallback,
            ];
        }

        // Build full context
        $ctx_parts = [];
        if ( $has_name ) $ctx_parts[] = "Tên: {$coachee['full_name']}";
        if ( $has_dob )  $ctx_parts[] = "Ngày sinh: {$coachee['dob']}";
        if ( $has_birth_time ) {
            $ctx_parts[] = "Giờ sinh: {$astro_row['birth_time']}";
            $ctx_parts[] = "Nơi sinh: " . ( $astro_row['birth_place'] ?? 'N/A' );
        }
        if ( $has_chart ) {
            $summary = json_decode( $astro_row['summary'], true );
            if ( ! empty( $summary['personality'] ) ) {
                $ctx_parts[] = "Tóm tắt tính cách: " . mb_strimwidth( $summary['personality'], 0, 300, '...' );
            }
        }

        return [
            'complete' => true,
            'context'  => implode( "\n", $ctx_parts ),
            'fallback' => '',
        ];
    }

    /* ================================================================
     *  Goal Patterns (Router)
     *
     *  These REPLACE the built-in astro/outlook patterns in the Router.
     *  The Registry merges them via `bizcity_intent_goal_patterns` filter
     *  (they overwrite by regex key if identical, or add if new).
     * ================================================================ */

    public function get_goal_patterns() {
        return [
            // ── HIGH PRIORITY: Specific daily transit/chart patterns ──
            '/(?:bản đồ sao|sao hôm nay|transit hôm nay|vận hạn hôm nay)\s*(?:hôm nay|ngày nay|hiện tại|bây giờ|lúc này)?/ui' => [
                'goal'        => 'daily_outlook', 
                'label'       => 'Bản đồ sao hôm nay',
                'description' => 'User muốn xem bản đồ sao hôm nay, vận hạn hiện tại cụ thể. Ví dụ: "bản đồ sao hôm nay", "sao hôm nay", "transit hôm nay".',
                'extract'     => [ 'time_range', 'focus_area' ],
            ],

            // ── Scenario 2: Tạo bản đồ vận hạn / transit ──
            // "Tạo bản đồ vận hạn", "tạo bản đồ transit", "làm transit cho tôi"
            '/(?:tạo|làm|lập)\s*(?:bản đồ|biểu đồ)?\s*(?:vận hạn|transit)/ui' => [
                'goal'        => 'create_transit_map',
                'label'       => 'Tạo bản đồ vận hạn',
                'description' => 'User muốn tạo/lập bản đồ vận hạn (transit chart) mới. Ví dụ: "tạo bản đồ vận hạn", "làm transit cho tôi", "lập biểu đồ transit".',
                'extract'     => [ 'time_range' ],
            ],

            // ── Scenario 3: Bản đồ sao hôm nay / transit hôm nay ── 
            '/(?:bản đồ sao|bản đồ|transit|vận hạn|sao)\s*(?:hôm nay|ngày nay|hiện tại|bây giờ|lúc này)/ui' => [
                'goal'        => 'daily_outlook',
                'label'       => 'Bản đồ sao hôm nay', 
                'description' => 'User muốn xem bản đồ sao/ transit hôm nay, vận hạn hiện tại. Ví dụ: "bản đồ sao hôm nay", "transit hôm nay", "vận hạn bây giờ".',
                'extract'     => [ 'time_range', 'focus_area' ],
            ],

            // ── Scenario 4: Xem vận hạn (tuần/tháng/năm) → auto-create if missing ──
            '/(?:xem|cho xem|check)\s*(?:vận hạn|transit|vận mệnh)\s*(?:tuần|tháng|năm|hôm nay|ngày mai)/ui' => [
                'goal'        => 'view_transit_forecast',
                'label'       => 'Xem vận hạn',
                'description' => 'User muốn xem dự báo vận hạn/transit theo khoảng thời gian (tuần/tháng/năm/hôm nay). Ví dụ: "xem vận hạn tháng này", "check transit tuần tới", "cho xem vận mệnh năm nay".',
                'extract'     => [ 'time_range', 'focus_area' ],
            ],

            // ── Scenario 5: "Hôm nay tôi thế nào?" / daily outlook → auto-create natal if missing ──
            '/hôm nay (?:tôi )?thế nào|thế nào hôm nay|dự báo vận|xem vận|vận mệnh hôm nay|(?:ngày mai|tuần này|tuần sau|tháng này|tháng sau|năm tới|năm nay)\s*(?:thế nào|ra sao|như thế nào|vận thế nào)/ui' => [
                'goal'        => 'daily_outlook',
                'label'       => 'Dự báo vận mệnh',
                'description' => 'User hỏi dự báo vận mệnh hàng ngày, vận thế hôm nay/ngày mai/tuần/tháng/năm. Ví dụ: "hôm nay tôi thế nào?", "tuần sau ra sao?", "dự báo vận mệnh tháng 3".',
                'extract'     => [ 'time_range', 'focus_area' ],
            ],
// ── Tạo bản đồ sao (natal chart) — slot collection via chat ──
            '/(?:tạo|làm|lập)\s*(?:bản đồ sao|natal chart|natal|bản đồ chiêm tinh)/ui' => [
                'goal'        => 'create_natal_chart',
                'label'       => 'Tạo bản đồ sao',
                'description' => 'User muốn tạo bản đồ sao cá nhân (natal chart) dựa trên ngày giờ nơi sinh. Ví dụ: "tạo bản đồ sao cho tôi", "làm natal chart", "lập bản đồ chiêm tinh".',
                'extract'     => [],
            ],

            // ── General astrology (broad catch) ──
            '/chiêm tinh|tử vi|bói|hoa tinh|natal|transit|phong thủy/ui' => [
                'goal'        => 'astro_forecast',
                'label'       => 'Dự báo chiêm tinh',
                'description' => 'User hỏi chung về chiêm tinh, tử vi, phong thủy, hoặc các chủ đề liên quan đến huyền học. Catch-all cho mọi câu hỏi chiêm tinh không thuộc goal cụ thể khác. Ví dụ: "xem tử vi", "hỏi phong thủy", "chiêm tinh học nói gì".',
                'extract'     => [ 'forecast_type', 'time_range', 'focus_area' ],
            ],
        ];
    }

    /* ================================================================
     *  Plans (Planner)
     * ================================================================ */

    public function get_plans() {
        return [
            /* ── Scenario 1: Tạo bản đồ transit ── */
            'create_transit_map' => [
                'required_slots' => [],
                'optional_slots' => [
                    'time_range' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn xem transit cho khoảng thời gian nào? 📅',
                        'choices' => [
                            'this_week'  => '📅 Tuần này',
                            'this_month' => '📅 Tháng này',
                            'this_year'  => '📅 Năm nay',
                        ],
                        'default' => 'this_month',
                    ],
                ],
                'tool'       => 'create_transit_map',
                'ai_compose' => false,
                'slot_order' => [ 'time_range' ],
            ],

            /* ── Scenario 2: Xem vận hạn → auto-create transit if missing ── */
            'view_transit_forecast' => [
                'required_slots' => [],
                'optional_slots' => [
                    'time_range' => [
                        'type'   => 'text',
                        'prompt' => 'Khoảng thời gian nào? (vd: tuần tới, tháng này)',
                    ],
                    'focus_area' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn xem mảng nào? ✨',
                        'choices' => [
                            'tong_quan' => '🌟 Tổng quan',
                            'tinh_cam'  => '💕 Tình cảm',
                            'su_nghiep' => '💼 Sự nghiệp',
                            'tai_chinh' => '💰 Tài chính',
                            'suc_khoe'  => '🏥 Sức khỏe',
                        ],
                        'default' => 'tong_quan',
                    ],
                ],
                'tool'       => 'view_transit_forecast',
                'ai_compose' => true,
                'slot_order' => [ 'time_range', 'focus_area' ],
            ],

            /* ── Scenario 3: Hôm nay tôi thế nào? → auto-create natal if missing ── */
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
                'tool'       => 'daily_outlook_check',
                'ai_compose' => true,
                'slot_order' => [ 'focus_area', 'time_range' ],
            ],

            /* ── Tạo bản đồ sao natal — pre-check (returns existing or switch_goal) ── */
            'create_natal_chart' => [
                'required_slots' => [],
                'optional_slots' => [],
                'tool'       => 'create_natal_chart_check',
                'ai_compose' => false,
                'slot_order' => [],
            ],

            /* ── Tạo bản đồ sao natal — conversational slot collection ── */
            'create_natal_chart_new' => [
                'required_slots' => [
                    'full_name' => [
                        'type'   => 'text',
                        'prompt' => 'Cho mình biết tên đầy đủ của bạn nhé:',
                    ],
                    'dob' => [
                        'type'   => 'text',
                        'prompt' => 'Ngày tháng năm sinh của bạn? (dd/mm/yyyy)',
                    ],
                    'birth_time' => [
                        'type'   => 'text',
                        'prompt' => 'Giờ sinh của bạn? (HH:MM, ví dụ 14:30)',
                    ],
                    'birth_place' => [
                        'type'   => 'text',
                        'prompt' => 'Bạn sinh ở đâu? (tên thành phố, ví dụ: Hà Nội, Sài Gòn)',
                    ],
                    'gender' => [
                        'type'    => 'choice',
                        'prompt'  => 'Giới tính của bạn:',
                        'choices' => [
                            'male'   => '👨 Nam',
                            'female' => '👩 Nữ',
                        ],
                    ],
                ],
                'optional_slots' => [],
                'tool'       => 'create_natal_chart',
                'ai_compose' => false,
                'slot_order' => [ 'full_name', 'dob', 'birth_time', 'birth_place', 'gender' ],
            ],

            /* ── General astro forecast (existing) ── */
            'astro_forecast' => [
                'required_slots' => [],
                'optional_slots' => [
                    'forecast_type' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn xem loại dự báo nào?',
                        'choices' => [
                            'natal'    => '🌟 Natal Chart (bản đồ sao)',
                            'transit'  => '🔄 Transit (vận hành)',
                        ],
                    ],
                    'time_range' => [
                        'type'   => 'text',
                        'prompt' => 'Khoảng thời gian nào bạn muốn xem?',
                    ],
                ],
                'tool'       => null,
                'ai_compose' => true,
                'slot_order' => [ 'forecast_type', 'time_range' ],
            ],
        ];
    }

    /* ================================================================
     *  Tools
     *
     *  These are callable actions. For astro/outlook goals, we use
     *  ai_compose (no tool execution needed). But we provide a utility
     *  tool that other plugins or future goals could invoke.
     * ================================================================ */

    public function get_tools() {
        return [
            'generate_natal_report' => [
                'schema' => [
                    'description'  => 'Tạo báo cáo chiêm tinh natal đầy đủ',
                    'input_fields' => [
                        'user_id' => [ 'required' => true, 'type' => 'number' ],
                    ],
                ],
                'callback' => [ $this, 'tool_generate_natal_report' ],
            ],
            'get_transit_forecast' => [
                'schema' => [
                    'description'  => 'Lấy dự báo transit chiêm tinh',
                    'input_fields' => [
                        'user_id'   => [ 'required' => true,  'type' => 'number' ],
                        'time_range' => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ $this, 'tool_get_transit_forecast' ],
            ],
            'create_natal_chart_check' => [
                'schema' => [
                    'description'  => 'Kiểm tra bản đồ sao đã tồn tại chưa — trả link nếu có, switch_goal nếu chưa',
                    'input_fields' => [
                        'user_id' => [ 'required' => true, 'type' => 'number' ],
                    ],
                ],
                'callback' => [ $this, 'tool_create_natal_chart_check' ],
            ],
            'create_natal_chart' => [
                'schema' => [
                    'description'  => 'Tạo bản đồ sao Natal Chart từ thông tin user cung cấp qua chat',
                    'input_fields' => [
                        'user_id'     => [ 'required' => true,  'type' => 'number' ],
                        'full_name'   => [ 'required' => true,  'type' => 'text' ],
                        'dob'         => [ 'required' => true,  'type' => 'text' ],
                        'birth_time'  => [ 'required' => true,  'type' => 'text' ],
                        'birth_place' => [ 'required' => true,  'type' => 'text' ],
                        'gender'      => [ 'required' => true,  'type' => 'text' ],
                    ],
                ],
                'callback' => [ $this, 'tool_create_natal_chart' ],
            ],
            'create_transit_map' => [
                'schema' => [
                    'description'  => 'Tạo bản đồ vận hạn transit dựa trên natal chart',
                    'input_fields' => [
                        'user_id'   => [ 'required' => true,  'type' => 'number' ],
                        'time_range' => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ $this, 'tool_create_transit_map' ],
            ],
            'daily_outlook_check' => [
                'schema' => [
                    'description'  => 'Kiểm tra dữ liệu để dự báo vận mệnh — tự tạo natal/transit nếu thiếu',
                    'input_fields' => [
                        'user_id'    => [ 'required' => true,  'type' => 'number' ],
                        'focus_area' => [ 'required' => false, 'type' => 'text' ],
                        'time_range' => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ $this, 'tool_daily_outlook_check' ],
            ],
            'view_transit_forecast' => [
                'schema' => [
                    'description'  => 'Xem vận hạn — tự tạo transit nếu chưa có',
                    'input_fields' => [
                        'user_id'    => [ 'required' => true,  'type' => 'number' ],
                        'time_range' => [ 'required' => false, 'type' => 'text' ],
                        'focus_area' => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ $this, 'tool_view_transit_forecast' ],
            ],

        ];
    }

    /* ================================================================
     *  Context Building
     *
     *  Injects natal chart + transit data + gen results into the AI
     *  system prompt when composing answers for astro/outlook goals.
     * ================================================================ */

    public function build_context( $goal, array $slots, $user_id, array $conversation ) {
        if ( ! $user_id ) {
            return '';
        }

        $parts = [];

        // 1. Natal chart context
        $natal_ctx = $this->get_natal_context( $user_id );
        if ( $natal_ctx ) {
            $parts[] = "=== BẢN ĐỒ SAO CÁ NHÂN (Natal Chart) ===\n" . $natal_ctx;
        }

        // 2. Transit context (if message or slots suggest a time range)
        $message    = $conversation['last_message'] ?? '';
        $transit_ctx = $this->get_transit_context( $user_id, $message, $slots );
        if ( $transit_ctx ) {
            $parts[] = "=== DỰ BÁO TRANSIT HIỆN TẠI ===\n" . $transit_ctx;
        }

        // 3. Gen results (coaching analysis: SWOT, numerology, etc.)
        $gen_ctx = $this->get_gen_results_context( $user_id );
        if ( $gen_ctx ) {
            $parts[] = "=== KẾT QUẢ PHÂN TÍCH COACHING ===\n" . $gen_ctx;
        }

        return implode( "\n\n", $parts );
    }

    /**
     * System instructions for astro/coaching goals.
     */
    public function get_system_instructions( $goal ) {
        $base = "Bạn là chuyên gia chiêm tinh và coaching cá nhân.\n";
        $base .= "Khi trả lời, LUÔN tham chiếu dữ liệu natal chart + transit THỰC TẾ đã cung cấp.\n";
        $base .= "Nêu TÊN SAO cụ thể + CUNG + GÓC CHIẾU khi phân tích.\n";
        $base .= "Liên hệ trực tiếp với hồ sơ cá nhân và kết quả coaching của user.\n";
        $base .= "Trả lời CHI TIẾT, tối thiểu 200 từ, có đánh số rõ ràng, giọng thân mật.\n";

        switch ( $goal ) {
            case 'daily_outlook':
                $base .= "\nĐây là dự báo vận mệnh — kết hợp transit chiêm tinh + natal + kết quả coaching để đưa ra lời khuyên CỤ THỂ cho ngày/tuần/tháng được hỏi.\n";
                $base .= "Nếu không có dữ liệu natal chart, hãy thông báo hệ thống đang tạo bản đồ sao và hỏi user có muốn chờ không.\n";
                break;

            case 'create_transit_map':
            case 'view_transit_forecast':
                $base .= "\nĐây là yêu cầu về transit/vận hạn. Khi có dữ liệu transit, phân tích:\n";
                $base .= "- Sao transit nào đang tạo góc chiếu mạnh nhất với natal chart\n";
                $base .= "- Thuận lợi vs thách thức chính trong khoảng thời gian này\n";
                $base .= "- Lời khuyên cụ thể theo từng lĩnh vực (sự nghiệp, tình cảm, tài chính, sức khỏe)\n";
                break;

            case 'create_natal_chart':
                $base .= "\nHệ thống đang tạo bản đồ sao natal. Khi nhận được dữ liệu, hãy tóm tắt:\n";
                $base .= "- Big 3 (Sun, Moon, Ascendant)\n";
                $base .= "- Các vị trí hành tinh quan trọng\n";
                $base .= "- Đặc điểm tính cách nổi bật\n";
                break;
        }

        return $base;
    }

    /* ================================================================
     *  Tool Callbacks
     * ================================================================ */

    /**
     * Tool: generate_natal_report
     */
    public function tool_generate_natal_report( array $slots ) {
        $user_id = intval( $slots['user_id'] ?? 0 );
        if ( ! $user_id ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => 'Thiếu user_id để tạo báo cáo natal.',
                'data'     => [],
            ];
        }

        $natal_ctx = $this->get_natal_context( $user_id );
        if ( ! $natal_ctx ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => 'Chưa có dữ liệu chiêm tinh cho user này. Hãy tạo bản đồ sao trước.',
                'data'     => [],
            ];
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "Đã lấy dữ liệu natal chart thành công.\n\n" . $natal_ctx,
            'data'     => [ 'natal_context' => $natal_ctx ],
        ];
    }

    /**
     * Tool: get_transit_forecast
     */
    public function tool_get_transit_forecast( array $slots ) {
        $user_id = intval( $slots['user_id'] ?? 0 );
        if ( ! $user_id ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => 'Thiếu user_id.',
                'data'     => [],
            ];
        }

        $time_range_str = $slots['time_range'] ?? 'tháng này';
        $transit_ctx    = $this->get_transit_context( $user_id, $time_range_str, $slots );

        if ( ! $transit_ctx ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => 'Chưa có dữ liệu transit. Hệ thống sẽ tự động fetch dữ liệu trong thời gian tới.',
                'data'     => [],
            ];
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $transit_ctx,
            'data'     => [ 'transit_context' => $transit_ctx ],
        ];
    }

    /* ================================================================
     *  Pre-check: Tạo bản đồ sao natal
     *  User: "Tạo bản đồ sao cho tôi"
     *  → Nếu đã có chart → trả link; nếu chưa → switch_goal sang
     *    create_natal_chart_new (slot collection flow)
     * ================================================================ */

    public function tool_create_natal_chart_check( array $slots ) {
        $user_id = intval( $slots['user_id'] ?? 0 );
        if ( ! $user_id ) {
            return $this->err( 'Thiếu thông tin user. Vui lòng đăng nhập.' );
        }

        global $wpdb;
        $t_astro = $wpdb->prefix . 'bccm_astro';

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, summary FROM $t_astro WHERE user_id=%d AND chart_type='western' AND summary IS NOT NULL AND summary != '' LIMIT 1",
            $user_id
        ), ARRAY_A );

        if ( $existing && ! empty( $existing['summary'] ) ) {
            $natal_ctx   = $this->get_natal_context( $user_id );
            $profile_url = $this->get_profile_page_url();
            return [
                'success'  => true,
                'complete' => true,
                'message'  => "Bạn đã có bản đồ sao rồi! 🌟\n\n" . $natal_ctx
                             . "\n\n📎 Xem chi tiết tại: " . $profile_url
                             . "\n\nBạn có muốn luận giải không?",
                'data'     => [ 'natal_context' => $natal_ctx, 'already_exists' => true, 'profile_url' => $profile_url ],
            ];
        }

        // No chart yet → switch to slot collection flow
        return [
            'success'     => true,
            'complete'    => false,
            'message'     => "Mình sẽ tạo bản đồ sao cho bạn nhé! ✨ Cho mình biết vài thông tin nha.",
            'data'        => [],
            'switch_goal' => 'create_natal_chart_new',
        ];
    }

    /* ================================================================
     *  Scenario 1: Tạo bản đồ sao natal (actual creation)
     *  Called after slot collection via create_natal_chart_new goal
     *  → Parse slots → geocode → save to DB → call API → return chart
     * ================================================================ */

    public function tool_create_natal_chart( array $slots ) {
        $user_id = intval( $slots['user_id'] ?? 0 );
        if ( ! $user_id ) {
            return $this->err( 'Thiếu thông tin user. Vui lòng đăng nhập.' );
        }

        global $wpdb;
        $prefix  = $wpdb->prefix;
        $t_astro = $prefix . 'bccm_astro';

        // ── 1. Collect slot data ──
        $full_name   = sanitize_text_field( $slots['full_name'] ?? '' );
        $dob_raw     = sanitize_text_field( $slots['dob'] ?? '' );
        $birth_time  = sanitize_text_field( $slots['birth_time'] ?? '' );
        $birth_place = sanitize_text_field( $slots['birth_place'] ?? '' );
        $gender      = sanitize_text_field( $slots['gender'] ?? 'male' );

        // Parse DOB
        $dob_parts = $this->parse_dob( $dob_raw );
        if ( ! $dob_parts ) {
            return $this->err( 'Ngày sinh không hợp lệ. Vui lòng nhập theo định dạng dd/mm/yyyy.' );
        }

        // Parse birth time
        $time_parts = explode( ':', $birth_time );
        $hour   = intval( $time_parts[0] ?? 12 );
        $minute = intval( $time_parts[1] ?? 0 );

        // Geocode birth place
        $geo = $this->simple_geocode( $birth_place );
        $latitude  = $geo['lat'];
        $longitude = $geo['lon'];
        $timezone  = $geo['tz'];

        // ── 2. Update coachee profile (full_name, dob, gender) ──
        $coachee = function_exists( 'bccm_get_or_create_user_coachee' )
            ? bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' )
            : null;

        if ( $coachee ) {
            $dob_sql = sprintf( '%04d-%02d-%02d', $dob_parts['year'], $dob_parts['month'], $dob_parts['day'] );
            $wpdb->update(
                $prefix . 'bccm_coachees',
                [
                    'full_name'  => $full_name,
                    'dob'        => $dob_sql,
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => $coachee['id'] ]
            );
        }

        // ── 3. Upsert astro row with birth data + geocoded coordinates ──
        $astro_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' LIMIT 1",
            $user_id
        ), ARRAY_A );

        $astro_data = [
            'birth_time'  => $birth_time,
            'birth_place' => $birth_place,
            'latitude'    => $latitude,
            'longitude'   => $longitude,
            'timezone'    => $timezone,
            'updated_at'  => current_time( 'mysql' ),
        ];

        if ( $astro_row ) {
            $wpdb->update( $t_astro, $astro_data, [ 'id' => $astro_row['id'] ] );
            $astro_id = $astro_row['id'];
        } else {
            $astro_data['user_id']    = $user_id;
            $astro_data['coachee_id'] = $coachee ? $coachee['id'] : 0;
            $astro_data['chart_type'] = 'western';
            $astro_data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $t_astro, $astro_data );
            $astro_id = $wpdb->insert_id;
        }

        // ── 4. Call astro API to create natal chart ──
        if ( ! function_exists( 'bccm_astro_fetch_full_chart' ) ) {
            return $this->err( 'Hệ thống chiêm tinh chưa sẵn sàng. Vui lòng thử lại sau.' );
        }

        $birth_data = [
            'year'      => $dob_parts['year'],
            'month'     => $dob_parts['month'],
            'day'       => $dob_parts['day'],
            'hour'      => $hour,
            'minute'    => $minute,
            'latitude'  => $latitude,
            'longitude' => $longitude,
            'timezone'  => $timezone,
        ];

        $chart_result = bccm_astro_fetch_full_chart( $birth_data );

        if ( is_wp_error( $chart_result ) || empty( $chart_result ) ) {
            return $this->err( 'Không thể tạo bản đồ sao lúc này. Vui lòng thử lại sau ít phút.' );
        }

        // ── 5. Save chart data to DB ──
        $update_data = [
            'summary'    => is_array( $chart_result['summary'] ?? null ) ? wp_json_encode( $chart_result['summary'] ) : ( $chart_result['summary'] ?? '' ),
            'traits'     => is_array( $chart_result['traits'] ?? null ) ? wp_json_encode( $chart_result['traits'] ) : ( $chart_result['traits'] ?? '' ),
            'updated_at' => current_time( 'mysql' ),
        ];

        if ( ! empty( $chart_result['positions'] ) ) {
            $update_data['positions'] = wp_json_encode( $chart_result['positions'] );
        }

        $wpdb->update( $t_astro, $update_data, [ 'id' => $astro_id ] );

        // ── 6. Trigger transit prefetch ──
        if ( function_exists( 'bccm_transit_prefetch_for_coachee' ) && $coachee ) {
            bccm_transit_prefetch_for_coachee( (int) $coachee['id'], $user_id );
        }

        $natal_ctx = $this->get_natal_context( $user_id );

        return [
            'success'   => true,
            'complete'  => true,
            'message'   => "🌟 Đã tạo bản đồ sao thành công cho {$full_name}!\n\n" . $natal_ctx
                         . "\n\nBạn có muốn tôi phân tích chi tiết hoặc tạo bản đồ transit không?",
            'data'      => [ 'natal_context' => $natal_ctx, 'chart_created' => true ],
            'follow_up' => 'Bạn có muốn tôi phân tích chi tiết bản đồ sao hoặc tạo bản đồ vận hạn transit không?',
        ];
    }

    /* ================================================================
     *  Scenario 2: Tạo bản đồ transit / vận hạn
     *  User: "Tạo bản đồ vận hạn cho tôi"
     *  → Kiểm tra natal chart → fetch transit → so sánh → trả kết quả
     * ================================================================ */

    public function tool_create_transit_map( array $slots ) {
        $user_id = intval( $slots['user_id'] ?? 0 );
        if ( ! $user_id ) {
            return $this->err( 'Thiếu thông tin user.' );
        }

        // Check natal chart first
        $natal_ctx = $this->get_natal_context( $user_id );
        if ( ! $natal_ctx ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => "Bạn chưa có bản đồ sao natal. Tôi cần tạo bản đồ sao trước, rồi mới tạo transit được.\nBạn có muốn tạo bản đồ sao trước không? 🌟",
                'data'     => [ 'missing_natal' => true ],
                'follow_up' => 'Tạo bản đồ sao cho tôi',
            ];
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $coachee_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$prefix}bccm_coachees WHERE user_id=%d ORDER BY updated_at DESC LIMIT 1",
            $user_id
        ) );

        if ( ! $coachee_id ) {
            return $this->err( 'Không tìm thấy hồ sơ.' );
        }

        // Trigger transit prefetch
        if ( function_exists( 'bccm_transit_prefetch_for_coachee' ) ) {
            bccm_transit_prefetch_for_coachee( $coachee_id, $user_id );
        }

        // Get transit context
        $time_range_str = $slots['time_range'] ?? 'this_month';
        $transit_ctx = $this->get_transit_context( $user_id, $time_range_str, $slots );

        if ( $transit_ctx ) {
            return [
                'success'  => true,
                'complete' => true,
                'message'  => "🔄 Bản đồ vận hạn transit đã sẵn sàng!\n\n" . $transit_ctx
                             . "\n\nBạn có muốn tôi phân tích chi tiết không?",
                'data'     => [ 'transit_context' => $transit_ctx, 'natal_context' => $natal_ctx ],
                'follow_up' => 'Phân tích chi tiết vận hạn cho tôi',
            ];
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "🔄 Hệ thống đang tính toán transit cho bạn. Dữ liệu sẽ sẵn sàng trong vài giây.\n\n"
                         . "Trong khi chờ, đây là thông tin bản đồ sao của bạn:\n" . $natal_ctx
                         . "\n\nHãy hỏi lại \"Xem vận hạn\" sau 1 phút nhé!",
            'data'     => [ 'natal_context' => $natal_ctx, 'transit_pending' => true ],
        ];
    }

    /* ================================================================
     *  Scenario 3: Xem vận hạn → auto-create transit nếu thiếu
     *  User: "Xem vận hạn tuần tới cho tôi"
     * ================================================================ */

    public function tool_view_transit_forecast( array $slots ) {
        $user_id = intval( $slots['user_id'] ?? 0 );
        if ( ! $user_id ) {
            return $this->err( 'Thiếu thông tin user.' );
        }

        // Check natal chart
        $natal_ctx = $this->get_natal_context( $user_id );
        if ( ! $natal_ctx ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => "Để xem vận hạn, bạn cần có bản đồ sao trước.\nBạn có muốn tạo bản đồ sao không? 🌟",
                'data'     => [ 'missing_natal' => true ],
                'follow_up' => 'Tạo bản đồ sao cho tôi',
            ];
        }

        global $wpdb;
        $prefix = $wpdb->prefix;
        $coachee_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$prefix}bccm_coachees WHERE user_id=%d ORDER BY updated_at DESC LIMIT 1",
            $user_id
        ) );

        // Try get existing transit context
        $time_range_str = $slots['time_range'] ?? '';
        $transit_ctx = $this->get_transit_context( $user_id, $time_range_str, $slots );

        if ( ! $transit_ctx && $coachee_id ) {
            // Auto-create transit
            if ( function_exists( 'bccm_transit_prefetch_for_coachee' ) ) {
                bccm_transit_prefetch_for_coachee( $coachee_id, $user_id );
                // Try again after prefetch
                $transit_ctx = $this->get_transit_context( $user_id, $time_range_str, $slots );
            }

            if ( ! $transit_ctx ) {
                return [
                    'success'  => true,
                    'complete' => true,
                    'message'  => "Chưa có dữ liệu transit. Hệ thống đang tạo bản đồ vận hạn cho bạn...\n\n"
                                 . "Đây là bản đồ sao hiện tại:\n" . $natal_ctx
                                 . "\n\nBạn có muốn tôi tạo bản đồ vận hạn transit ngay không?",
                    'data'     => [ 'natal_context' => $natal_ctx, 'transit_auto_creating' => true ],
                    'follow_up' => 'Tạo bản đồ vận hạn transit cho tôi',
                ];
            }
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $transit_ctx . "\n\n" . $natal_ctx,
            'data'     => [ 'transit_context' => $transit_ctx, 'natal_context' => $natal_ctx ],
        ];
    }

    /* ================================================================
     *  Scenario 4: Daily outlook → auto-create natal nếu thiếu
     *  User: "Hôm nay tôi thế nào?"
     * ================================================================ */

    public function tool_daily_outlook_check( array $slots ) {
        $user_id = intval( $slots['user_id'] ?? 0 );
        if ( ! $user_id ) {
            return $this->err( 'Vui lòng đăng nhập để xem dự báo.' );
        }

        global $wpdb;
        $prefix  = $wpdb->prefix;
        $t_astro = $prefix . 'bccm_astro';

        // Check if user has natal chart
        $natal_ctx = $this->get_natal_context( $user_id );

        if ( ! $natal_ctx ) {
            // Check if user has birth data but no chart yet
            $has_birth_data = $wpdb->get_var( $wpdb->prepare(
                "SELECT birth_time FROM $t_astro WHERE user_id=%d AND chart_type='western' AND birth_time IS NOT NULL AND birth_time != '' LIMIT 1",
                $user_id
            ) );

            if ( $has_birth_data ) {
                // Auto-create natal chart
                $auto_result = $this->tool_create_natal_chart( [ 'user_id' => $user_id ] );
                if ( ! empty( $auto_result['success'] ) ) {
                    $natal_ctx = $auto_result['data']['natal_context'] ?? '';
                }
            }

            if ( ! $natal_ctx ) {
                return [
                    'success'  => false,
                    'complete' => true,
                    'message'  => "Để xem vận mệnh hôm nay, tôi cần bản đồ sao của bạn.\n"
                                 . "Bạn hãy nói \"Tạo bản đồ sao\" để mình tạo nhé! 🌟",
                    'data'     => [ 'missing_natal' => true ],
                    'follow_up' => 'Tạo bản đồ sao cho tôi',
                ];
            }
        }

        // Get transit for today
        $transit_ctx = $this->get_transit_context( $user_id, 'hôm nay', $slots );

        // Auto-fetch transit if missing
        if ( ! $transit_ctx ) {
            $coachee_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$prefix}bccm_coachees WHERE user_id=%d ORDER BY updated_at DESC LIMIT 1",
                $user_id
            ) );
            if ( $coachee_id && function_exists( 'bccm_transit_prefetch_for_coachee' ) ) {
                bccm_transit_prefetch_for_coachee( $coachee_id, $user_id );
                $transit_ctx = $this->get_transit_context( $user_id, 'hôm nay', $slots );
            }
        }

        $focus = $slots['focus_area'] ?? 'tong_quan';
        $result_msg = "=== DỮ LIỆU CHO DỰ BÁO VẬN MỆNH ===\n";
        $result_msg .= "Lĩnh vực: " . $focus . "\n\n";
        $result_msg .= $natal_ctx;
        if ( $transit_ctx ) {
            $result_msg .= "\n\n" . $transit_ctx;
        } else {
            $result_msg .= "\n\n(Chưa có dữ liệu transit — phân tích dựa trên natal chart)";
        }

        // Gen results for extra context
        $gen_ctx = $this->get_gen_results_context( $user_id );
        if ( $gen_ctx ) {
            $result_msg .= "\n\n=== COACHING ===\n" . $gen_ctx;
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $result_msg,
            'data'     => [
                'natal_context'  => $natal_ctx,
                'transit_context' => $transit_ctx ?: '',
                'focus_area'     => $focus,
            ],
        ];
    }

    /* ================================================================
     *  Utility helpers
     * ================================================================ */

    /**
     * Build error response envelope.
     */
    private function err( $msg ) {
        return [
            'success'  => false,
            'complete' => true,
            'message'  => $msg,
            'data'     => [],
        ];
    }

    /**
     * Parse Vietnamese date format to year/month/day.
     * Supports: dd/mm/yyyy, dd-mm-yyyy, yyyy-mm-dd
     */
    private function parse_dob( $dob ) {
        $dob = trim( $dob );
        if ( ! $dob ) return null;

        // dd/mm/yyyy or dd-mm-yyyy
        if ( preg_match( '/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dob, $m ) ) {
            return [ 'day' => intval( $m[1] ), 'month' => intval( $m[2] ), 'year' => intval( $m[3] ) ];
        }
        // yyyy-mm-dd
        if ( preg_match( '/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dob, $m ) ) {
            return [ 'day' => intval( $m[3] ), 'month' => intval( $m[2] ), 'year' => intval( $m[1] ) ];
        }
        return null;
    }

    /**
     * Build birth_data array from astro row + coachee.
     */
    private function build_birth_data( $astro_row, $coachee ) {
        $dob_parts = $this->parse_dob( $coachee['dob'] ?? '' );
        $time_parts = explode( ':', $astro_row['birth_time'] ?? '12:00' );

        return [
            'year'      => $dob_parts['year'] ?? 1990,
            'month'     => $dob_parts['month'] ?? 1,
            'day'       => $dob_parts['day'] ?? 1,
            'hour'      => intval( $time_parts[0] ?? 12 ),
            'minute'    => intval( $time_parts[1] ?? 0 ),
            'latitude'  => floatval( $astro_row['latitude'] ?? 21.0278 ),
            'longitude' => floatval( $astro_row['longitude'] ?? 105.8342 ),
            'timezone'  => floatval( $astro_row['timezone'] ?? 7 ),
        ];
    }

    /**
     * Simple geocoding for common Vietnamese cities.
     * Returns ['lat' => float, 'lon' => float, 'tz' => float] or null.
     */
    private function simple_geocode( $place ) {
        $place = mb_strtolower( trim( $place ) );
        $cities = [
            'hà nội'       => [ 'lat' => 21.0278, 'lon' => 105.8342, 'tz' => 7 ],
            'hanoi'        => [ 'lat' => 21.0278, 'lon' => 105.8342, 'tz' => 7 ],
            'hồ chí minh'  => [ 'lat' => 10.8231, 'lon' => 106.6297, 'tz' => 7 ],
            'sài gòn'      => [ 'lat' => 10.8231, 'lon' => 106.6297, 'tz' => 7 ],
            'saigon'       => [ 'lat' => 10.8231, 'lon' => 106.6297, 'tz' => 7 ],
            'đà nẵng'      => [ 'lat' => 16.0544, 'lon' => 108.2022, 'tz' => 7 ],
            'hải phòng'    => [ 'lat' => 20.8449, 'lon' => 106.6881, 'tz' => 7 ],
            'cần thơ'      => [ 'lat' => 10.0452, 'lon' => 105.7469, 'tz' => 7 ],
            'huế'          => [ 'lat' => 16.4637, 'lon' => 107.5909, 'tz' => 7 ],
            'nha trang'    => [ 'lat' => 12.2388, 'lon' => 109.1967, 'tz' => 7 ],
            'đà lạt'       => [ 'lat' => 11.9404, 'lon' => 108.4583, 'tz' => 7 ],
            'vũng tàu'     => [ 'lat' => 10.346,  'lon' => 107.0843, 'tz' => 7 ],
            'biên hòa'     => [ 'lat' => 10.9478, 'lon' => 106.8246, 'tz' => 7 ],
            'buôn ma thuột' => [ 'lat' => 12.6667, 'lon' => 108.05,   'tz' => 7 ],
            'thái nguyên'  => [ 'lat' => 21.5928, 'lon' => 105.8442, 'tz' => 7 ],
            'nam định'     => [ 'lat' => 20.4388, 'lon' => 106.1621, 'tz' => 7 ],
            'vinh'         => [ 'lat' => 18.6796, 'lon' => 105.6813, 'tz' => 7 ],
            'quy nhơn'     => [ 'lat' => 13.776,  'lon' => 109.2237, 'tz' => 7 ],
        ];

        foreach ( $cities as $city_name => $coords ) {
            if ( strpos( $place, $city_name ) !== false ) {
                return $coords;
            }
        }

        // Default: Hanoi
        return [ 'lat' => 21.0278, 'lon' => 105.8342, 'tz' => 7 ];
    }

    /**
     * Get natal chart context for a user.
     *
     * @param int $user_id
     * @return string
     */
    private function get_natal_context( $user_id ) {
        if ( ! function_exists( 'bccm_llm_build_chart_context' ) ) {
            return '';
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Get coachee
        $coachee = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$prefix}bccm_coachees WHERE user_id = %d ORDER BY updated_at DESC LIMIT 1",
            $user_id
        ), ARRAY_A );

        if ( ! $coachee ) {
            return '';
        }

        // Get astro data
        $astro_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$prefix}bccm_astro WHERE user_id = %d AND chart_type = 'western' AND (summary IS NOT NULL OR traits IS NOT NULL) LIMIT 1",
            $user_id
        ), ARRAY_A );

        if ( ! $astro_row ) {
            $astro_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$prefix}bccm_astro WHERE coachee_id = %d AND chart_type = 'western' LIMIT 1",
                $coachee['id']
            ), ARRAY_A );
        }

        if ( ! $astro_row ) {
            return '';
        }

        return bccm_llm_build_chart_context( $astro_row, $coachee );
    }

    /**
     * Get transit context for a user.
     *
     * @param int    $user_id
     * @param string $message    User message (for intent detection).
     * @param array  $slots      Current conversation slots.
     * @return string
     */
    private function get_transit_context( $user_id, $message = '', $slots = [] ) {
        if ( ! function_exists( 'bccm_transit_build_context' ) ) {
            return '';
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Get coachee_id
        $coachee_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$prefix}bccm_coachees WHERE user_id = %d ORDER BY updated_at DESC LIMIT 1",
            $user_id
        ) );

        if ( ! $coachee_id ) {
            return '';
        }

        // Detect time range from message or slots
        $time_range = [];
        if ( $message && function_exists( 'bccm_transit_detect_intent' ) ) {
            $detected = bccm_transit_detect_intent( $message );
            if ( $detected ) {
                $time_range = $detected;
            }
        }

        // Fallback: use slot value
        if ( empty( $time_range ) && ! empty( $slots['time_range'] ) ) {
            $map = [
                'today'      => [ 'period' => 'day',   'days' => 1,  'label' => 'Hôm nay' ],
                'this_week'  => [ 'period' => 'week',  'days' => 7,  'label' => 'Tuần này' ],
                'this_month' => [ 'period' => 'month', 'days' => 30, 'label' => 'Tháng này' ],
            ];
            $time_range = $map[ $slots['time_range'] ] ?? [ 'period' => 'month', 'days' => 30, 'label' => '1 tháng tới' ];
        }

        // Default
        if ( empty( $time_range ) ) {
            $time_range = [ 'period' => 'month', 'days' => 30, 'label' => '1 tháng tới' ];
        }

        return bccm_transit_build_context( $coachee_id, $user_id, $time_range );
    }

    /**
     * Get coaching gen results as context text.
     *
     * @param int $user_id
     * @return string
     */
    private function get_gen_results_context( $user_id ) {
        if ( ! function_exists( 'bccm_get_gen_results' ) ) {
            return '';
        }

        global $wpdb;
        $coachee_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bccm_coachees WHERE user_id = %d ORDER BY updated_at DESC LIMIT 1",
            $user_id
        ) );

        if ( ! $coachee_id ) {
            return '';
        }

        $results = bccm_get_gen_results( $coachee_id );
        if ( empty( $results ) ) {
            return '';
        }

        $lines = [];
        foreach ( (array) $results as $row ) {
            $label = $row->gen_label ?? $row->gen_key ?? 'unknown';
            $data  = $row->result_json ?? '';
            if ( is_string( $data ) ) {
                $decoded = json_decode( $data, true );
                if ( $decoded ) {
                    // Summarize top-level keys
                    $keys = array_keys( $decoded );
                    $summary = implode( ', ', array_slice( $keys, 0, 5 ) );
                    $lines[] = "- [{$label}]: " . $summary;
                    // Include the first 500 chars of JSON for AI to reference
                    $lines[] = "  " . mb_substr( $data, 0, 500 );
                } else {
                    $lines[] = "- [{$label}]: " . mb_substr( $data, 0, 300 );
                }
            }
        }

        return implode( "\n", $lines );
    }
}
