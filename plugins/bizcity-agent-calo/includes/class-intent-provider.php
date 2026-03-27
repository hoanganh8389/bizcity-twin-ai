<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Calo_Intent_Provider extends BizCity_Intent_Provider {

    public function get_id() { return 'calo'; }
    public function get_name() { return 'BizCity Calo – Nhật ký Bữa ăn AI'; }

    /* ── Profile Context (injected into AI system prompt) ── */
    public function get_profile_context( $user_id ) {
        if ( ! $user_id ) {
            return array(
                'complete' => false,
                'context'  => '',
                'fallback' => 'Người dùng chưa đăng nhập. Hãy hướng dẫn họ đăng nhập để sử dụng nhật ký dinh dưỡng.',
            );
        }

        $profile = bzcalo_get_or_create_profile( $user_id );

        // Check if profile has essential data
        $has_weight = ! empty( $profile['weight_kg'] ) && (float) $profile['weight_kg'] > 0;
        $has_height = ! empty( $profile['height_cm'] ) && (float) $profile['height_cm'] > 0;
        $has_target = ! empty( $profile['daily_calo_target'] ) && (int) $profile['daily_calo_target'] > 0;
        $complete   = $has_weight && $has_height;

        $page_url = bzcalo_get_page_url();

        if ( ! $complete ) {
            $missing = array();
            if ( ! $has_weight ) $missing[] = 'cân nặng';
            if ( ! $has_height ) $missing[] = 'chiều cao';
            $fallback = sprintf(
                'Người dùng chưa khai báo %s trong hồ sơ dinh dưỡng. Hãy nhắc họ truy cập %s để cập nhật hồ sơ sức khỏe, để AI có thể tính calo chính xác.',
                implode( ', ', $missing ),
                $page_url
            );
            // Still return partial context if available
            $ctx = $this->build_profile_text( $profile, $user_id );
            return array(
                'complete' => false,
                'context'  => $ctx,
                'fallback' => $fallback,
            );
        }

        $ctx = $this->build_profile_text( $profile, $user_id );
        return array(
            'complete' => true,
            'context'  => $ctx,
            'fallback' => '',
        );
    }

    /**
     * Build human-readable profile text for AI context
     */
    private function build_profile_text( $profile, $user_id ) {
        $parts = array();

        // Basic info
        $name   = $profile['full_name'] ?: 'Chưa rõ';
        $gender = $profile['gender'] ?? 'other';
        $gender_label = array( 'male' => 'Nam', 'female' => 'Nữ', 'other' => 'Khác' );
        $parts[] = "Tên: {$name} | Giới tính: " . ( $gender_label[ $gender ] ?? 'Khác' );

        if ( ! empty( $profile['dob'] ) ) {
            $age = (int) date_diff( date_create( $profile['dob'] ), date_create( 'today' ) )->y;
            $parts[] = "Ngày sinh: {$profile['dob']} ({$age} tuổi)";
        }

        // Body metrics
        $metrics = array();
        if ( ! empty( $profile['height_cm'] ) )   $metrics[] = "Chiều cao: {$profile['height_cm']}cm";
        if ( ! empty( $profile['weight_kg'] ) )    $metrics[] = "Cân nặng: {$profile['weight_kg']}kg";
        if ( ! empty( $profile['target_weight'] ) ) $metrics[] = "Mục tiêu: {$profile['target_weight']}kg";
        if ( $metrics ) $parts[] = implode( ' | ', $metrics );

        // Goal & target
        $goal_labels = array(
            'lose'     => 'Giảm cân',
            'gain'     => 'Tăng cân',
            'maintain' => 'Duy trì',
        );
        $goal = $goal_labels[ $profile['goal'] ?? '' ] ?? ( $profile['goal'] ?: 'Duy trì' );
        $target_cal = (int) ( $profile['daily_calo_target'] ?? 2000 );
        $parts[] = "Mục tiêu: {$goal} | Calo/ngày: {$target_cal} kcal";

        // Activity level
        $act_labels = array(
            'sedentary' => 'Ít vận động',
            'light'     => 'Nhẹ',
            'moderate'  => 'Trung bình',
            'active'    => 'Năng động',
            'very_active' => 'Rất năng động',
        );
        $act = $act_labels[ $profile['activity_level'] ?? '' ] ?? ( $profile['activity_level'] ?: 'Trung bình' );
        $parts[] = "Vận động: {$act}";

        // Allergies / medical
        if ( ! empty( $profile['allergies'] ) ) {
            $parts[] = "Dị ứng: {$profile['allergies']}";
        }
        if ( ! empty( $profile['medical_notes'] ) ) {
            $parts[] = "Ghi chú y tế: {$profile['medical_notes']}";
        }

        // Today's stats
        global $wpdb;
        $t    = bzcalo_tables();
        $date = current_time( 'Y-m-d' );
        $today = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date = %s",
            $user_id, $date
        ), ARRAY_A );

        if ( $today && (float) $today['total_calories'] > 0 ) {
            $cal = round( (float) $today['total_calories'] );
            $remaining = max( 0, $target_cal - $cal );
            $parts[] = "Hôm nay: {$cal}/{$target_cal} kcal ({$today['meals_count']} bữa) | Còn lại: {$remaining} kcal";
            $parts[] = "Protein: {$today['total_protein']}g | Carbs: {$today['total_carbs']}g | Fat: {$today['total_fat']}g";
        } else {
            $parts[] = "Hôm nay: chưa ghi bữa ăn nào";
        }

        return implode( "\n", array_filter( $parts ) );
    }

    /* ── Goals (keyword patterns) ── */
    public function get_goal_patterns() {
        return array(
            /* Phân tích dinh dưỡng & ghi nhật ký bữa ăn */
            '/ghi\s*b[ữứ]a\s*[aă]n|log\s*meal|nh[ậa]t\s*k[ýy]\s*[aă]n|ghi\s*nh[ậa]n\s*b[ữứ]a'
            . '|v[ừư]a\s*[aă]n|t[ôo]i\s*[aă]n|m[ìi]nh\s*[aă]n|[đd][ãa]\s*[aă]n'
            . '|[aă]n\s*s[áa]ng|[aă]n\s*tr[ưu]a|[aă]n\s*t[ốo]i|[aă]n\s*v[ặa]t'
            . '|b[ữứ]a\s*s[áa]ng|b[ữứ]a\s*tr[ưu]a|b[ữứ]a\s*t[ốo]i'
            . '|ghi\s*nh[ớo]\s*b[ữứ]a|l[ưu]u\s*b[ữứ]a'
            . '|[aă]n\s*c[ơo]m|[aă]n\s*ph[ởo]|[aă]n\s*b[ún]n|[aă]n\s*m[ìi]'
            . '|[aă]n\s*ch[áa]o|[aă]n\s*b[áa]nh'
            . '|ch[ụu]p\s*[aả]nh\s*b[ữứ]a|ch[ụu]p\s*[aả]nh\s*[aă]n|photo\s*meal|[aả]nh\s*th[ứu]c\s*[aă]n'
            . '|g[ửư]i\s*[aả]nh\s*[aă]n|nh[ậa]n\s*di[ệe]n\s*[aă]n'
            . '|ph[âa]n\s*t[íi]ch\s*dinh\s*d[ưu][ỡo]ng|t[íi]nh\s*calo'
            . '|th[êe]m\s*b[ữứ]a\s*[aă]n|add\s*meal/ui' => array(
                'goal'        => 'calo_log_meal',
                'label'       => 'Phân tích & ghi nhật ký bữa ăn',
                'description' => 'Phân tích dinh dưỡng món ăn (từ mô tả hoặc ảnh), tính calo và lưu vào nhật ký hàng ngày',
                'extract'     => array( 'food_input' ),
            ),
        );
    }

    /* ── Plans ── */
    public function get_plans() {
        return array(
            'calo_log_meal' => array(
                'required_slots' => array(
                    'food_input' => array(
                        'type'         => 'text',
                        'label'        => 'Tên/mô tả món ăn cụ thể (VD: phở bò, cơm tấm sườn bì, 2 quả trứng luộc). KHÔNG phải câu lệnh/yêu cầu.',
                        'prompt'       => '🍽️ Bạn đã ăn gì? Mô tả món ăn hoặc gửi ảnh bữa ăn nhé!',
                        'no_auto_map'  => true,
                        'accept_image' => true,
                    ),
                ),
                'optional_slots' => array(
                    'photo_url' => array(
                        'type'   => 'image',
                        'prompt' => '📸 Gửi ảnh bữa ăn (không bắt buộc):',
                    ),
                ),
                'tool'       => 'calo_analyze_food',
                'ai_compose' => true,
                'slot_order' => array( 'food_input' ),
            ),
        );
    }

    /* ── Tools ── */
    public function get_tools() {
        return array(
            'calo_analyze_food' => array(
                'schema' => array(
                    'description'  => 'Phân tích dinh dưỡng món ăn (ảnh hoặc mô tả) và lưu vào nhật ký bữa ăn',
                    'input_fields' => array(
                        'food_input' => array( 'required' => true,  'type' => 'text' ),
                        'photo_url'  => array( 'required' => false, 'type' => 'image' ),
                    ),
                ),
                'callback' => array( $this, 'tool_analyze_food' ),
            ),
        );
    }

    /* ======== Tool Callback ======== */

    /**
     * Unified tool: analyze food nutrition (photo or text) → save to diary → return daily summary.
     */
    public function tool_analyze_food( $slots ) {
        $food_input = $slots['food_input'] ?? '';
        $photo_url  = $slots['photo_url'] ?? '';
        $user_id    = get_current_user_id();
        if ( ! $user_id ) return array( 'success' => false, 'message' => 'Bạn cần đăng nhập.' );

        $profile   = bzcalo_get_or_create_profile( $user_id );
        $target    = (int) ( $profile['daily_calo_target'] ?? 2000 );

        // Detect meal type from food_input + original trigger message (may contain "trưa", "sáng", etc.)
        $raw_msg   = $slots['_raw_message'] ?? $slots['message'] ?? '';
        $meal_type = $this->auto_detect_meal_type( $food_input . ' ' . $raw_msg );

        // Analyze: photo takes priority, fallback to text
        if ( ! empty( $photo_url ) ) {
            $ai_result = $this->ai_analyze_photo( $photo_url );
            if ( is_wp_error( $ai_result ) && ! empty( $food_input ) ) {
                $ai_result = $this->ai_estimate_meal( $food_input );
            } elseif ( is_wp_error( $ai_result ) ) {
                return array( 'success' => false, 'message' => 'Lỗi phân tích ảnh: ' . $ai_result->get_error_message() );
            }
            $source = 'photo';
        } else {
            $ai_result = $this->ai_estimate_meal( $food_input );
            $source = 'chat';
        }

        // Save to meals table
        global $wpdb;
        $t    = bzcalo_tables();
        $date = current_time( 'Y-m-d' );

        $wpdb->insert( $t['meals'], array(
            'user_id'        => $user_id,
            'meal_type'      => $meal_type,
            'meal_date'      => $date,
            'meal_time'      => current_time( 'H:i:s' ),
            'description'    => sanitize_text_field( $food_input ?: ( $ai_result['description'] ?? 'Bữa ăn' ) ),
            'photo_url'      => ! empty( $photo_url ) ? esc_url_raw( $photo_url ) : '',
            'ai_analysis'    => wp_json_encode( $ai_result, JSON_UNESCAPED_UNICODE ),
            'items_json'     => wp_json_encode( $ai_result['items'] ?? array(), JSON_UNESCAPED_UNICODE ),
            'total_calories' => $ai_result['total_calories'] ?? 0,
            'total_protein'  => $ai_result['total_protein'] ?? 0,
            'total_carbs'    => $ai_result['total_carbs'] ?? 0,
            'total_fat'      => $ai_result['total_fat'] ?? 0,
            'total_fiber'    => $ai_result['total_fiber'] ?? 0,
            'source'         => $source,
            'platform'       => 'WEBCHAT',
        ) );
        bzcalo_recalc_daily_stats( $user_id, $date );

        // Get today totals
        $today = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date = %s",
            $user_id, $date
        ), ARRAY_A );

        $cal_today  = (float) ( $today['total_calories'] ?? 0 );
        $remaining  = max( 0, $target - $cal_today );
        $meal_types = array( 'breakfast' => '🌅 Sáng', 'lunch' => '☀️ Trưa', 'dinner' => '🌙 Tối', 'snack' => '🍪 Ăn vặt' );
        $type_label = $meal_types[ $meal_type ] ?? $meal_type;

        $items_text = '';
        if ( ! empty( $ai_result['items'] ) ) {
            foreach ( $ai_result['items'] as $item ) {
                $items_text .= "  • " . ( $item['name'] ?? '?' ) . " — " . ( $item['calories'] ?? 0 ) . " kcal\n";
            }
        }

        $msg = "✅ Đã ghi bữa {$type_label}\n\n"
             . "🍽️ " . ( $food_input ?: ( $ai_result['description'] ?? '' ) ) . "\n\n"
             . "📋 Chi tiết:\n{$items_text}\n"
             . "🔥 Calo bữa này: " . round( $ai_result['total_calories'] ?? 0 ) . " kcal\n"
             . "🥩 Protein: " . round( $ai_result['total_protein'] ?? 0 ) . "g | "
             . "🍞 Carbs: " . round( $ai_result['total_carbs'] ?? 0 ) . "g | "
             . "🧈 Fat: " . round( $ai_result['total_fat'] ?? 0 ) . "g\n\n"
             . "📊 Tổng hôm nay: " . round( $cal_today ) . " / {$target} kcal\n"
             . "🎯 Còn lại: {$remaining} kcal";

        return array(
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => array(
                'meal_id'     => $wpdb->insert_id,
                'calories'    => $ai_result['total_calories'] ?? 0,
                'today_total' => $cal_today,
                'target'      => $target,
                'remaining'   => $remaining,
            ),
        );
    }

    /* ======== Meal Type Auto-Detection ======== */

    /**
     * Auto-detect meal type from description keywords + current time.
     * Keywords in description take priority; falls back to time-based detection.
     */
    private function auto_detect_meal_type( $description = '' ) {
        $desc = mb_strtolower( $description );

        // Keyword-based detection
        if ( preg_match( '/b[ữứ]a\s*s[áa]ng|[aă]n\s*s[áa]ng|breakfast|sáng\s*nay/ui', $desc ) ) return 'breakfast';
        if ( preg_match( '/b[ữứ]a\s*tr[ưu]a|[aă]n\s*tr[ưu]a|lunch|trưa\s*nay/ui', $desc ) ) return 'lunch';
        if ( preg_match( '/b[ữứ]a\s*t[ốo]i|[aă]n\s*t[ốo]i|dinner|t[ốo]i\s*nay/ui', $desc ) ) return 'dinner';
        if ( preg_match( '/[aă]n\s*v[ặa]t|snack|nh[ẹè]|tr[àa]\s*chi[ềe]u|l[óo]t\s*d[ạa]/ui', $desc ) ) return 'snack';

        // Time-based fallback
        $hour = (int) current_time( 'G' );
        if ( $hour < 10 ) return 'breakfast';
        if ( $hour < 14 ) return 'lunch';
        if ( $hour < 17 ) return 'snack';
        return 'dinner';
    }

    /* ======== AI Helpers ======== */

    /**
     * AI estimate calo from text meal description
     */
    private function ai_estimate_meal( $description ) {
        $system = "Bạn là chuyên gia dinh dưỡng. Phân tích bữa ăn và ước tính calo.\n"
                . "CHỈ trả về JSON hợp lệ (RFC8259), tiếng Việt:\n"
                . "{\n"
                . "  \"items\": [{\"name\": \"tên món\", \"serving\": \"khẩu phần\", \"calories\": 0, \"protein\": 0, \"carbs\": 0, \"fat\": 0, \"fiber\": 0}],\n"
                . "  \"total_calories\": 0, \"total_protein\": 0, \"total_carbs\": 0, \"total_fat\": 0, \"total_fiber\": 0,\n"
                . "  \"description\": \"mô tả ngắn bữa ăn\",\n"
                . "  \"health_note\": \"nhận xét dinh dưỡng ngắn gọn\"\n"
                . "}";

        if ( function_exists( 'bizcity_openrouter_chat' ) ) {
            $ai = bizcity_openrouter_chat( array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user',   'content' => "Phân tích bữa ăn: {$description}" ),
            ), array( 'purpose' => 'chat' ) );

            if ( $ai['success'] && ! empty( $ai['message'] ) ) {
                $parsed = $this->parse_json_response( $ai['message'] );
                if ( $parsed ) return $parsed;
            }
        }

        // Fallback: rough estimate from food DB
        return $this->fallback_estimate( $description );
    }

    /**
     * AI Vision analyze photo
     */
    private function ai_analyze_photo( $photo_url ) {
        $system = "Bạn là chuyên gia dinh dưỡng. Phân tích ảnh bữa ăn và ước tính calo.\n"
                . "CHỈ trả về JSON hợp lệ (RFC8259), tiếng Việt:\n"
                . "{\n"
                . "  \"items\": [{\"name\": \"tên món\", \"serving\": \"khẩu phần\", \"calories\": 0, \"protein\": 0, \"carbs\": 0, \"fat\": 0}],\n"
                . "  \"total_calories\": 0, \"total_protein\": 0, \"total_carbs\": 0, \"total_fat\": 0, \"total_fiber\": 0,\n"
                . "  \"description\": \"mô tả bữa ăn trong ảnh\",\n"
                . "  \"health_note\": \"nhận xét\"\n"
                . "}";

        if ( function_exists( 'bizcity_openrouter_chat' ) ) {
            $ai = bizcity_openrouter_chat( array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => array(
                    array( 'type' => 'text', 'text' => 'Phân tích ảnh bữa ăn này:' ),
                    array( 'type' => 'image_url', 'image_url' => array( 'url' => $photo_url ) ),
                ) ),
            ), array( 'model' => 'google/gemini-2.0-flash-001', 'purpose' => 'vision' ) );

            if ( $ai['success'] && ! empty( $ai['message'] ) ) {
                $parsed = $this->parse_json_response( $ai['message'] );
                if ( $parsed ) return $parsed;
            }
            return new \WP_Error( 'ai_error', $ai['error'] ?: 'AI Vision không phản hồi.' );
        }

        return new \WP_Error( 'no_api', 'AI Vision API chưa sẵn sàng (OpenRouter chưa kích hoạt).' );
    }

    /**
     * Fallback estimate from local foods DB
     */
    private function fallback_estimate( $description ) {
        global $wpdb;
        $t     = bzcalo_tables();
        $foods = $wpdb->get_results( "SELECT * FROM {$t['foods']}", ARRAY_A );

        $items = array();
        $total = array( 'cal' => 0, 'pro' => 0, 'carb' => 0, 'fat' => 0, 'fib' => 0 );
        $desc_lower = mb_strtolower( $description );

        foreach ( $foods as $f ) {
            if ( mb_strpos( $desc_lower, mb_strtolower( $f['name_vi'] ) ) !== false
                || mb_strpos( $desc_lower, mb_strtolower( $f['name_en'] ) ) !== false ) {
                $items[] = array(
                    'name'     => $f['name_vi'],
                    'serving'  => $f['serving_size'],
                    'calories' => (float) $f['calories'],
                    'protein'  => (float) $f['protein_g'],
                    'carbs'    => (float) $f['carbs_g'],
                    'fat'      => (float) $f['fat_g'],
                    'fiber'    => (float) $f['fiber_g'],
                );
                $total['cal']  += (float) $f['calories'];
                $total['pro']  += (float) $f['protein_g'];
                $total['carb'] += (float) $f['carbs_g'];
                $total['fat']  += (float) $f['fat_g'];
                $total['fib']  += (float) $f['fiber_g'];
            }
        }

        // If nothing matched, estimate ~400 kcal
        if ( empty( $items ) ) {
            $items[] = array( 'name' => $description, 'serving' => '1 phần', 'calories' => 400, 'protein' => 15, 'carbs' => 50, 'fat' => 12, 'fiber' => 2 );
            $total   = array( 'cal' => 400, 'pro' => 15, 'carb' => 50, 'fat' => 12, 'fib' => 2 );
        }

        return array(
            'items'          => $items,
            'total_calories' => $total['cal'],
            'total_protein'  => $total['pro'],
            'total_carbs'    => $total['carb'],
            'total_fat'      => $total['fat'],
            'total_fiber'    => $total['fib'],
            'description'    => $description,
            'health_note'    => 'Ước tính từ cơ sở dữ liệu. Gửi ảnh để phân tích chính xác hơn.',
        );
    }

    private function parse_json_response( $raw ) {
        if ( ! is_string( $raw ) || $raw === '' ) return null;
        $s = trim( $raw );
        if ( preg_match( '/^```(?:json)?\s*/i', $s ) ) {
            $s = preg_replace( '/^```(?:json)?\s*/i', '', $s );
            $s = preg_replace( '/```\s*$/', '', $s );
        }
        $data = json_decode( trim( $s ), true );
        return is_array( $data ) ? $data : null;
    }

    /* ======== Context & System Instructions ======== */

    public function get_knowledge_character_id() {
        return (int) get_option( 'bzcalo_knowledge_character_id', 0 );
    }

    public function build_context( $goal, $slots, $user_id, $conversation ) {
        $parts = array();

        if ( $user_id ) {
            $profile = bzcalo_get_or_create_profile( $user_id );
            $goal_labels = array( 'lose' => 'Giảm cân', 'gain' => 'Tăng cân', 'maintain' => 'Duy trì' );
            $act_labels  = array( 'sedentary' => 'Ít vận động', 'light' => 'Nhẹ', 'moderate' => 'Trung bình', 'active' => 'Năng động', 'very_active' => 'Rất năng động' );

            $profile_text  = "Tên: {$profile['full_name']}\n";
            $profile_text .= "Giới tính: {$profile['gender']}";
            if ( ! empty( $profile['dob'] ) ) {
                $age = (int) date_diff( date_create( $profile['dob'] ), date_create( 'today' ) )->y;
                $profile_text .= " | Tuổi: {$age}";
            }
            $profile_text .= "\nChiều cao: {$profile['height_cm']}cm | Cân nặng: {$profile['weight_kg']}kg";
            if ( ! empty( $profile['target_weight'] ) ) $profile_text .= " | Mục tiêu cân: {$profile['target_weight']}kg";
            $profile_text .= "\nMục tiêu: " . ( $goal_labels[ $profile['goal'] ?? '' ] ?? 'Duy trì' );
            $profile_text .= " | Calo/ngày: " . ( $profile['daily_calo_target'] ?? 2000 ) . " kcal";
            $profile_text .= "\nVận động: " . ( $act_labels[ $profile['activity_level'] ?? '' ] ?? 'Trung bình' );
            if ( ! empty( $profile['allergies'] ) ) $profile_text .= "\nDị ứng: {$profile['allergies']}";
            if ( ! empty( $profile['medical_notes'] ) ) $profile_text .= "\nGhi chú y tế: {$profile['medical_notes']}";
            $parts[] = "=== HỒ SƠ DINH DƯỠNG ===\n" . $profile_text;

            global $wpdb;
            $t      = bzcalo_tables();
            $date   = current_time( 'Y-m-d' );
            $target = (int) ( $profile['daily_calo_target'] ?? 2000 );

            $today = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$t['daily_stats']} WHERE user_id = %d AND stat_date = %s",
                $user_id, $date
            ), ARRAY_A );

            $cal = $today ? (float) $today['total_calories'] : 0;
            $remaining = max( 0, $target - $cal );

            $stats_text  = "Đã ăn: " . round( $cal ) . " / {$target} kcal | Còn lại: {$remaining} kcal\n";
            $stats_text .= "Protein: " . round( $today['total_protein'] ?? 0 ) . "g";
            $stats_text .= " | Carbs: " . round( $today['total_carbs'] ?? 0 ) . "g";
            $stats_text .= " | Fat: " . round( $today['total_fat'] ?? 0 ) . "g";
            $stats_text .= "\nSố bữa: " . ( $today['meals_count'] ?? 0 );
            $parts[] = "=== THỐNG KÊ HÔM NAY ===\n" . $stats_text;

            // Include today's meal list for AI context
            $meals = $wpdb->get_results( $wpdb->prepare(
                "SELECT meal_type, description, total_calories, meal_time
                 FROM {$t['meals']} WHERE user_id = %d AND meal_date = %s ORDER BY meal_time ASC",
                $user_id, $date
            ), ARRAY_A );

            $meal_list = '';
            $type_icons = array( 'breakfast' => '🌅', 'lunch' => '☀️', 'dinner' => '🌙', 'snack' => '🍪' );
            foreach ( $meals as $m ) {
                $icon = $type_icons[ $m['meal_type'] ] ?? '🍽️';
                $meal_list .= "{$icon} " . substr( $m['meal_time'], 0, 5 ) . " — " . $m['description']
                            . " (" . round( $m['total_calories'] ) . " kcal)\n";
            }
            if ( $meal_list ) {
                $parts[] = "=== CÁC BỮA ĂN HÔM NAY ===\n" . $meal_list;
            }
        }

        $parts[] = "=== LINK ỨNG DỤNG ===\n" . bzcalo_get_page_url();

        return implode( "\n\n", array_filter( $parts ) );
    }

    public function get_system_instructions( $goal ) {
        return "Bạn là CaloCoach — trợ lý dinh dưỡng AI thông minh của BizCity.\n"
             . "Ngôn ngữ: Tiếng Việt. Đơn vị: kcal, gram. Giọng thân thiện, dùng emoji.\n\n"
             . "NHIỆM VỤ CHÍNH: Phân tích dinh dưỡng món ăn và ghi nhật ký bữa ăn hàng ngày.\n\n"
             . "NGUYÊN TẮC:\n"
             . "1. Khi user mô tả bữa ăn hoặc gửi ảnh → phân tích dinh dưỡng, tính calo, lưu nhật ký\n"
             . "2. Luôn hiển thị tổng calo hôm nay và phần còn lại so với mục tiêu\n"
             . "3. Đưa ra nhận xét dinh dưỡng ngắn gọn, tích cực sau mỗi bữa\n"
             . "4. Khuyến khích thói quen ăn uống lành mạnh, cân đối macro\n"
             . "5. Tôn trọng dị ứng và ghi chú y tế nếu có trong hồ sơ\n"
             . "6. Dùng emoji, giọng thân thiện, động viên nhẹ nhàng\n";
    }
}
