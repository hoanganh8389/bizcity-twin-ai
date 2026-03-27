<?php
/**
 * BizCoach Map — Intent Provider (v2 — Main Tool Pattern)
 *
 * Registers astrology/coaching "skills" with the Intent Engine:
 *   • MAIN tool:  bizcoach_consult (catch-all astrology Q&A)
 *   • Secondary:  create_natal_chart, create_transit_map (slash commands)
 *   • Context:    natal chart data, transit aspects, gen results
 *
 * @package BizCoach_Map
 * @since   0.2.0
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
     *  Goal Patterns (Router) — v2 Main Tool Pattern
     *
     *  1. NARROW: create_natal_chart (slash command /tao_ban_do_sao)
     *  2. NARROW: create_transit_map (slash command /tao_ban_do_van_hanh)
     *  3. BROAD:  bizcoach_consult   (MAIN — catch-all astrology/coaching)
     * ================================================================ */

    public function get_goal_patterns() {
        return [
            // ── SECONDARY: Tạo bản đồ sao natal (narrow) ──
            '/(?:tạo|làm|lập)\s*(?:bản đồ sao|natal chart|natal|bản đồ chiêm tinh)/ui' => [
                'goal'        => 'create_natal_chart',
                'label'       => 'Tạo bản đồ sao',
                'description' => 'User muốn tạo bản đồ sao cá nhân (natal chart). Ví dụ: "tạo bản đồ sao cho tôi", "làm natal chart".',
                'extract'     => [],
            ],

            // ── SECONDARY: Tạo bản đồ vận hạn transit (narrow) ──
            '/(?:tạo|làm|lập)\s*(?:bản đồ|biểu đồ)?\s*(?:vận hạn|transit)/ui' => [
                'goal'        => 'create_transit_map',
                'label'       => 'Tạo bản đồ vận hạn',
                'description' => 'User muốn tạo bản đồ vận hạn (transit chart). Ví dụ: "tạo bản đồ vận hạn", "làm transit cho tôi".',
                'extract'     => [],
            ],

            // ── MAIN: Tư vấn chiêm tinh (broad catch-all) ──
            '/chiêm tinh|tử vi|bói|hoa tinh|natal|transit|phong thủy|vận mệnh|vận hạn|bản đồ sao|sao hôm nay|hôm nay (?:tôi )?thế nào|thế nào hôm nay|dự báo vận|xem vận|ngày mai|tuần (?:này|sau|tới)|tháng (?:này|sau|tới)|năm (?:nay|tới|sau)|(?:thế nào|ra sao|như thế nào)/ui' => [
                'goal'        => 'bizcoach_consult',
                'label'       => 'Tư vấn chiêm tinh',
                'description' => 'User hỏi bất kỳ câu nào liên quan đến chiêm tinh, tử vi, vận mệnh, bản đồ sao, dự báo hàng ngày/tuần/tháng/năm, phong thủy. MAIN tool — catch-all.',
                'extract'     => [ 'prompt' ],
            ],
        ];
    }

    /* ================================================================
     *  Plans (Planner) — v2
     * ================================================================ */

    public function get_plans() {
        return [
            /* ── MAIN: Tư vấn chiêm tinh — nhận prompt + ảnh, gọi AI trực tiếp ── */
            'bizcoach_consult' => [
                'required_slots' => [
                    'prompt' => [
                        'type'   => 'text',
                        'prompt' => 'Bạn muốn hỏi gì về chiêm tinh, vận mệnh? ✨',
                    ],
                ],
                'optional_slots' => [
                    'prompt_images' => [
                        'type'   => 'image',
                        'prompt' => 'Ảnh bản đồ sao hoặc ảnh liên quan (nếu có)',
                    ],
                ],
                'tool'       => 'bizcoach_consult',
                'ai_compose' => true,
                'slot_order' => [ 'prompt' ],
            ],

            /* ── SECONDARY: Tạo bản đồ sao natal — collect slots rồi tạo ── */
            'create_natal_chart' => [
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

            /* ── SECONDARY: Tạo bản đồ vận hạn transit ── */
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
        ];
    }

    /* ================================================================
     *  Tools — v2
     * ================================================================ */

    public function get_tools() {
        return [
            /* ── MAIN tool: Tư vấn chiêm tinh — gọi AI trực tiếp ── */
            'bizcoach_consult' => [
                'schema' => [
                    'description'  => 'Tư vấn chiêm tinh, vận mệnh, dự báo — gọi AI với context natal+transit',
                    'input_fields' => [
                        'user_id'       => [ 'required' => true,  'type' => 'number' ],
                        'prompt'        => [ 'required' => true,  'type' => 'text' ],
                        'prompt_images' => [ 'required' => false, 'type' => 'image' ],
                    ],
                ],
                'callback' => [ $this, 'tool_bizcoach_consult' ],
            ],

            /* ── SECONDARY: Tạo natal chart (check existing + create) ── */
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

            /* ── SECONDARY: Tạo bản đồ vận hạn transit ── */
            'create_transit_map' => [
                'schema' => [
                    'description'  => 'Tạo bản đồ vận hạn transit dựa trên natal chart',
                    'input_fields' => [
                        'user_id'    => [ 'required' => true,  'type' => 'number' ],
                        'time_range' => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ $this, 'tool_create_transit_map' ],
            ],
        ];
    }

    /* ================================================================
     *  Context Building
     * ================================================================ */

    public function build_context( $goal, array $slots, $user_id, array $conversation ) {
        if ( ! $user_id ) {
            return '';
        }

        $parts = [];

        $natal_ctx = $this->get_natal_context( $user_id );
        if ( $natal_ctx ) {
            $parts[] = "=== BẢN ĐỒ SAO CÁ NHÂN (Natal Chart) ===\n" . $natal_ctx;
        }

        $message     = $conversation['last_message'] ?? '';
        $transit_ctx = $this->get_transit_context( $user_id, $message, $slots );
        if ( $transit_ctx ) {
            $parts[] = "=== DỰ BÁO TRANSIT HIỆN TẠI ===\n" . $transit_ctx;
        }

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
        return "Bạn là chuyên gia chiêm tinh và coaching cá nhân.\n"
             . "Khi trả lời, LUÔN tham chiếu dữ liệu natal chart + transit THỰC TẾ đã cung cấp.\n"
             . "Nêu TÊN SAO cụ thể + CUNG + GÓC CHIẾU khi phân tích.\n"
             . "Liên hệ trực tiếp với hồ sơ cá nhân và kết quả coaching của user.\n"
             . "Trả lời CHI TIẾT, tối thiểu 200 từ, có đánh số rõ ràng, giọng thân mật.\n"
             . "Kết hợp transit chiêm tinh + natal + kết quả coaching để đưa ra lời khuyên CỤ THỂ.\n"
             . "Nếu đề cập transit, phân tích: sao transit tạo góc chiếu mạnh nhất, thuận lợi vs thách thức, lời khuyên theo từng lĩnh vực.\n";
    }

    /* ================================================================
     *  MAIN Tool: bizcoach_consult
     *
     *  Nhận câu hỏi (text + ảnh tùy chọn) → build context natal/transit
     *  → gọi AI trực tiếp → trả kết quả complete.
     * ================================================================ */

    public function tool_bizcoach_consult( array $slots ) {
        $user_id = intval( $slots['user_id'] ?? 0 );
        if ( ! $user_id ) {
            return $this->err( 'Vui lòng đăng nhập để sử dụng dịch vụ chiêm tinh.' );
        }

        $prompt = trim( $slots['prompt'] ?? '' );
        $images = $slots['prompt_images'] ?? [];
        if ( is_string( $images ) && $images !== '' ) {
            $images = [ $images ];
        }

        if ( ! $prompt && empty( $images ) ) {
            return $this->err( 'Vui lòng nhập câu hỏi hoặc gửi ảnh liên quan đến chiêm tinh.' );
        }

        // ── 1. Build context ──
        $natal_ctx   = $this->get_natal_context( $user_id );
        $transit_ctx = $this->get_transit_context( $user_id, $prompt, $slots );
        $gen_ctx     = $this->get_gen_results_context( $user_id );

        // Auto-prefetch transit if user has natal but no transit
        if ( $natal_ctx && ! $transit_ctx ) {
            global $wpdb;
            $coachee_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}bccm_coachees WHERE user_id=%d ORDER BY updated_at DESC LIMIT 1",
                $user_id
            ) );
            if ( $coachee_id && function_exists( 'bccm_transit_prefetch_for_coachee' ) ) {
                bccm_transit_prefetch_for_coachee( $coachee_id, $user_id );
                $transit_ctx = $this->get_transit_context( $user_id, $prompt, $slots );
            }
        }

        // If no natal chart, suggest creating one but still try to answer
        $missing_note = '';
        if ( ! $natal_ctx ) {
            $missing_note = "\n\n💡 Bạn chưa có bản đồ sao. Hãy nói \"Tạo bản đồ sao\" để AI phân tích chính xác hơn!";
        }

        // ── 2. Call AI ──
        $ai_result = $this->ai_consult( $prompt, $images, $natal_ctx, $transit_ctx, $gen_ctx );

        if ( $ai_result ) {
            return [
                'success'  => true,
                'complete' => true,
                'message'  => $ai_result . $missing_note,
                'data'     => [
                    'natal_context'   => $natal_ctx ?: '',
                    'transit_context' => $transit_ctx ?: '',
                ],
            ];
        }

        // AI failed → fall back to ai_compose with context
        $context_msg = '';
        if ( $natal_ctx ) $context_msg .= $natal_ctx . "\n\n";
        if ( $transit_ctx ) $context_msg .= $transit_ctx . "\n\n";
        if ( $gen_ctx ) $context_msg .= $gen_ctx . "\n\n";

        return [
            'success'  => true,
            'complete' => false,
            'message'  => $context_msg ?: '',
            'data'     => [
                'natal_context'   => $natal_ctx ?: '',
                'transit_context' => $transit_ctx ?: '',
            ],
        ];
    }

    /**
     * Call AI to consult on astrology question.
     *
     * @param string $prompt      User question.
     * @param array  $images      Optional images (URLs).
     * @param string $natal_ctx   Natal chart context.
     * @param string $transit_ctx Transit context.
     * @param string $gen_ctx     Coaching gen results context.
     * @return string|false       AI response or false on failure.
     */
    private function ai_consult( $prompt, $images, $natal_ctx, $transit_ctx, $gen_ctx ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return false;
        }

        // Build system prompt
        $system = $this->get_system_instructions( 'bizcoach_consult' );

        if ( $natal_ctx ) {
            $system .= "\n\n=== BẢN ĐỒ SAO CÁ NHÂN (Natal Chart) ===\n" . $natal_ctx;
        }
        if ( $transit_ctx ) {
            $system .= "\n\n=== DỰ BÁO TRANSIT HIỆN TẠI ===\n" . $transit_ctx;
        }
        if ( $gen_ctx ) {
            $system .= "\n\n=== KẾT QUẢ PHÂN TÍCH COACHING ===\n" . $gen_ctx;
        }

        // Build user message
        $has_images = ! empty( $images );
        if ( $has_images ) {
            $user_content = [
                [ 'type' => 'text', 'text' => $prompt ?: 'Hãy phân tích ảnh bản đồ sao này.' ],
            ];
            foreach ( $images as $img_url ) {
                if ( is_string( $img_url ) && $img_url !== '' ) {
                    $user_content[] = [
                        'type'      => 'image_url',
                        'image_url' => [ 'url' => $img_url ],
                    ];
                }
            }
        } else {
            $user_content = $prompt;
        }

        $messages = [
            [ 'role' => 'system',  'content' => $system ],
            [ 'role' => 'user',    'content' => $user_content ],
        ];

        $options = [
            'purpose'    => $has_images ? 'vision' : 'chat',
            'max_tokens' => 4000,
        ];

        $result = bizcity_openrouter_chat( $messages, $options );

        if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
            return $result['message'];
        }

        return false;
    }

    /* ================================================================
     *  SECONDARY Tool: Tạo bản đồ sao natal (check existing + create)
     *  User: "Tạo bản đồ sao cho tôi"
     *  → Nếu đã có chart → trả link; nếu chưa → tạo từ slots đã gather
     * ================================================================ */

    public function tool_create_natal_chart( array $slots ) {
        $user_id = intval( $slots['user_id'] ?? 0 );
        if ( ! $user_id ) {
            return $this->err( 'Thiếu thông tin user. Vui lòng đăng nhập.' );
        }

        global $wpdb;
        $prefix  = $wpdb->prefix;
        $t_astro = $prefix . 'bccm_astro';

        // ── 0. Check if chart already exists → return it instead of re-creating ──
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
                             . "\n\nBạn có muốn tôi luận giải chi tiết không?",
                'data'     => [ 'natal_context' => $natal_ctx, 'already_exists' => true, 'profile_url' => $profile_url ],
            ];
        }

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
     *  SECONDARY Tool: Tạo bản đồ transit / vận hạn
     *  User: "Tạo bản đồ vận hạn cho tôi"
     *  → Kiểm tra natal chart → fetch transit → trả kết quả
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

        // Get coachee (cached 15 min)
        $cache_key = 'bccm_coachee_natal_' . intval( $user_id );
        $coachee = wp_cache_get( $cache_key, 'bccm_coachees' );
        if ( false === $coachee ) {
            $coachee = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$prefix}bccm_coachees WHERE user_id = %d ORDER BY updated_at DESC LIMIT 1",
                $user_id
            ), ARRAY_A );
            wp_cache_set( $cache_key, $coachee ?: array(), 'bccm_coachees', 900 );
        }

        if ( ! $coachee || empty( $coachee ) ) {
            return '';
        }

        // Get astro data (cached 15 min)
        $astro_cache_key = 'bccm_astro_' . intval( $user_id );
        $astro_row = wp_cache_get( $astro_cache_key, 'bccm_coachees' );
        if ( false === $astro_row ) {
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
            wp_cache_set( $astro_cache_key, $astro_row ?: array(), 'bccm_coachees', 900 );
        }

        if ( ! $astro_row || empty( $astro_row ) ) {
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

        // Get coachee_id (cached — same user/coachee as natal context)
        $cache_key = 'bccm_coachee_id_' . intval( $user_id );
        $coachee_id = wp_cache_get( $cache_key, 'bccm_coachees' );
        if ( false === $coachee_id ) {
            $coachee_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$prefix}bccm_coachees WHERE user_id = %d ORDER BY updated_at DESC LIMIT 1",
                $user_id
            ) );
            wp_cache_set( $cache_key, $coachee_id, 'bccm_coachees', 900 );
        }

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
