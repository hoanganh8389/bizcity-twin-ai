<?php
/**
 * BizCity Tarot — Intent Provider
 *
 * Registers Tarot reading "skills" with the Intent Engine:
 *   • MAIN tool:      tarot_interpret (giải nghĩa lá bài qua text/ảnh, lưu lịch sử)
 *   • Secondary tool:  send_link_tarot (gửi/tạo link bốc bài Tarot online)
 *   • Context:        card meanings, topic suggestions, reading history
 *
 * @package BizCity_Tarot
 * @since   1.0.8
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tarot_Intent_Provider extends BizCity_Intent_Provider {

    /* ── Identity ── */

    public function get_id() {
        return 'tarot';
    }

    public function get_name() {
        return 'BizCity Tarot — Bói bài Tarot';
    }

    /* ================================================================
     *  Profile Context
     * ================================================================ */

    public function get_profile_page_url() {
        return home_url( '/tarot-profile/' );
    }

    public function get_profile_context( $user_id ) {
        if ( ! $user_id ) {
            return [
                'complete' => false,
                'context'  => '',
                'fallback' => 'Người dùng chưa đăng nhập. Hãy hướng dẫn họ đăng nhập để lưu lịch sử bốc bài.',
            ];
        }

        $prefs = get_user_meta( $user_id, 'bct_tarot_preferences', true );
        $has_prefs = is_array( $prefs ) && ! empty( $prefs['favorite_topic'] );

        // Reading count
        global $wpdb;
        $t = bct_tables();
        $reading_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['readings']} WHERE user_id=%d", $user_id
        ) );

        $profile_url = $this->get_profile_page_url();

        if ( ! $has_prefs ) {
            return [
                'complete' => false,
                'context'  => $reading_count > 0
                    ? "Lịch sử: đã bốc bài {$reading_count} lần."
                    : '',
                'fallback' => sprintf(
                    'Người dùng chưa thiết lập sở thích Tarot. Hãy gợi ý họ truy cập %s để chọn chủ đề yêu thích, giúp AI đưa ra gợi ý phù hợp hơn.',
                    $profile_url
                ),
            ];
        }

        $ctx_parts = [];
        $ctx_parts[] = "Chủ đề yêu thích: " . ( $prefs['favorite_topic'] ?? 'chung' );
        $ctx_parts[] = "Kiểu trải bài: " . ( $prefs['favorite_spread'] === '1' ? '1 lá' : '3 lá' );
        if ( ! empty( $prefs['default_question'] ) ) {
            $ctx_parts[] = "Bối cảnh/câu hỏi hay quan tâm: " . $prefs['default_question'];
        }
        if ( $reading_count > 0 ) {
            $ctx_parts[] = "Lịch sử: đã bốc bài {$reading_count} lần.";
        }

        return [
            'complete' => true,
            'context'  => implode( "\n", $ctx_parts ),
            'fallback' => '',
        ];
    }

    /* ================================================================
     *  Goal Patterns (Router)
     * ================================================================ */

    public function get_goal_patterns() {
        return [
            // ── SECONDARY: Draw new cards — narrow, only explicit draw/shuffle requests ──
            '/bốc bài|rút bài|rút lá|bói bài|gieo quẻ|trải bài|muốn bói|cho xem bài|bốc lá/ui' => [
                'goal'        => 'tarot_reading',
                'label'       => 'Bốc bài Tarot',
                'description' => 'User muốn bốc/rút bài Tarot mới (tạo phiên bốc bài online). Tool phụ. Ví dụ: "bốc bài tarot", "rút 3 lá", "gieo quẻ", "muốn bói bài".',
                'extract'     => [ 'spread', 'question_focus' ],
            ],
            // ── MAIN: Interpret/analyze cards (text name or image) — broad catch-all ──
            '/giải.{0,20}(?:bài|tarot|lá)|phân tích.{0,20}(?:bài|tarot|lá)|đọc.{0,10}(?:lá|bài|tiếp)|ý nghĩa.{0,10}(?:lá|bài|tarot)|luận giải|giải nghĩa|giải mã|lá bài|xem bài|tarot/ui' => [
                'goal'        => 'tarot_interpret',
                'label'       => 'Giải bài Tarot',
                'description' => 'User muốn giải nghĩa lá bài Tarot — gửi ảnh hoặc nhắn tên lá bài. Đây là tool CHÍNH của plugin Tarot. Ví dụ: "giải bài tarot", "ý nghĩa lá The Fool", "phân tích lá bài này", "giải mã lá bài", "tarot".',
                'extract'     => [ 'card_info', 'question_focus' ],
            ],
        ];
    }

    /* ================================================================
     *  Plans (Planner)
     * ================================================================ */

    public function get_plans() {
        return [
            // ── MAIN: Interpret card — accept text name OR image ──
            'tarot_interpret' => [
                'required_slots' => [
                    'card_info' => [
                        'type'         => 'text',
                        'prompt'       => '🔮 Gửi **ảnh lá bài** hoặc **nhắn tên** lá bài nhé! Mình giải nghĩa luôn! ✨\nVí dụ: The Fool, Queen of Cups, 10 of Wands…',
                        'no_auto_map'  => true,
                        'accept_image' => true,
                    ],
                ],
                'optional_slots' => [
                    'card_images' => [
                        'type'     => 'image',
                        'prompt'   => 'Gửi ảnh lá bài bạn muốn giải nhé! 📸',
                        'is_array' => true,
                    ],
                    'question_focus' => [
                        'type'   => 'text',
                        'prompt' => 'Bạn đang quan tâm đến lĩnh vực nào? (tình cảm, sự nghiệp, tài chính...)',
                    ],
                ],
                'tool'       => 'tarot_interpret',
                'ai_compose' => true,
                'slot_order' => [ 'card_info' ],
            ],

            // ── SECONDARY: Draw cards online via link ──
            'tarot_reading' => [
                'required_slots' => [
                    'question_focus' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn xem về lĩnh vực nào? 🔮',
                        'choices' => $this->get_topic_choices(),
                    ],
                ],
                'optional_slots' => [
                    'spread' => [
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn rút mấy lá? 🃏',
                        'choices' => [
                            '1' => '1 lá',
                            '3' => '3 lá (Quá khứ - Hiện tại - Tương lai)',
                        ],
                        'default' => '1',
                    ],
                ],
                'tool'       => 'send_link_tarot',
                'ai_compose' => true,
                'slot_order' => [ 'question_focus' ],
            ],
        ];
    }

    /* ================================================================
     *  Tools
     * ================================================================ */

    public function get_tools() {
        return [
            // ── MAIN TOOL: Giải nghĩa lá bài (text/ảnh) + lưu lịch sử ──
            'tarot_interpret' => [
                'schema' => [
                    'description'  => 'Tool CHÍNH — Giải nghĩa lá bài Tarot: nhận tên/ảnh lá bài, tra DB, lưu lịch sử, trả kết quả cho AI giải nghĩa theo khung 4 tầng',
                    'input_fields' => [
                        'card_info'      => [ 'required' => true,  'type' => 'text' ],
                        'card_images'    => [ 'required' => false, 'type' => 'image' ],
                        'question_focus' => [ 'required' => false, 'type' => 'text' ],
                        'user_id'        => [ 'required' => false, 'type' => 'number' ],
                        'session_id'     => [ 'required' => false, 'type' => 'text' ],
                        'platform'       => [ 'required' => false, 'type' => 'text' ],
                        'client_id'      => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ $this, 'tool_interpret' ],
            ],

            // ── SECONDARY TOOL: Gửi link bốc bài Tarot online ──
            'send_link_tarot' => [
                'schema' => [
                    'description'  => 'Tool phụ — Gửi/tạo link bốc bài Tarot online để user bốc bài khi không có bài trong tay',
                    'input_fields' => [
                        'question_focus' => [ 'required' => true,  'type' => 'text' ],
                        'spread'         => [ 'required' => false, 'type' => 'number' ],
                        'user_id'        => [ 'required' => false, 'type' => 'number' ],
                        'session_id'     => [ 'required' => false, 'type' => 'text' ],
                        'platform'       => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ $this, 'tool_start_reading' ],
            ],
        ];
    }

    /* ================================================================
     *  Context Building
     * ================================================================ */

    public function build_context( $goal, array $slots, $user_id, array $conversation ) {
        $parts = [];

        if ( $goal === 'tarot_interpret' ) {
            return $this->build_interpret_context( $slots, $conversation );
        }

        // ── tarot_reading context ──

        // 1. Available topic categories + suggested questions
        $topics_ctx = $this->build_topics_context();
        if ( $topics_ctx ) {
            $parts[] = "=== CHỦ ĐỀ TAROT KHẢ DỤNG ===\n" . $topics_ctx;
        }

        // 2. If user mentioned a topic, try to match it
        $message = $conversation['last_message'] ?? '';
        if ( $message && function_exists( 'bct_match_topic_question' ) ) {
            $matched = bct_match_topic_question( $message );
            if ( ! empty( $matched['topic'] ) ) {
                $parts[] = "=== CHỦ ĐỀ ĐƯỢC NHẬN DIỆN ===\n"
                    . "Chủ đề: " . $matched['topic'] . "\n"
                    . ( ! empty( $matched['question'] ) ? "Câu hỏi gợi ý: " . $matched['question'] : '' );
            }
        }

        // 3. Tarot page link (for reference)
        if ( function_exists( 'bct_get_tarot_page_url' ) ) {
            $tarot_url = bct_get_tarot_page_url();
            if ( $tarot_url ) {
                $parts[] = "=== TRANG BỐC BÀI ===\nURL: " . $tarot_url;
            }
        }

        return implode( "\n\n", $parts );
    }

    /**
     * Build context specifically for tarot interpret goals.
     * Provides card database + interpretation framework for AI Vision or text-based.
     */
    private function build_interpret_context( array $slots, array $conversation ) {
        $parts = [];

        // 1. Card database for reference
        if ( function_exists( 'bct_tables' ) ) {
            global $wpdb;
            $t = bct_tables();
            $cards = $wpdb->get_results(
                "SELECT card_name_vi, card_name_en, card_type, upright_vi, reversed_vi FROM {$t['cards']} ORDER BY sort_order LIMIT 78",
                ARRAY_A
            );
            if ( $cards ) {
                $card_lines = [];
                foreach ( $cards as $card ) {
                    $upright  = $card['upright_vi'] ?? '';
                    $reversed = $card['reversed_vi'] ?? '';
                    $line = $card['card_name_en'] . ' (' . $card['card_name_vi'] . ')';
                    if ( $upright ) {
                        $line .= ' — Xuôi: ' . mb_substr( $upright, 0, 200, 'UTF-8' );
                    }
                    if ( $reversed ) {
                        $line .= ' | Ngược: ' . mb_substr( $reversed, 0, 200, 'UTF-8' );
                    }
                    $card_lines[] = $line;
                }
                $parts[] = "=== BỘ BÀI TAROT (78 LÁ) ===\n" . implode( "\n", $card_lines );
            }
        }

        // 2. User's question focus
        $focus = isset( $slots['question_focus'] ) ? $slots['question_focus'] : '';
        if ( $focus ) {
            $parts[] = "=== NGỮ CẢNH CÂU HỎI ===\nUser hỏi về: " . $focus;
        }

        // 3. Card info provided by user (text — may be name, description, or image caption)
        $card_info = isset( $slots['card_info'] ) ? trim( $slots['card_info'] ) : '';
        if ( $card_info ) {
            $parts[] = "=== THÔNG TIN LÁ BÀI TỪ USER ===\n" . $card_info
                     . "\nHãy tra BỘ BÀI TAROT ở trên để tìm chính xác lá bài này, rồi giải theo KHUNG 4 TẦNG.";
        }

        return implode( "\n\n", array_filter( $parts ) );
    }

    /**
     * System instructions for tarot goals.
     */
    public function get_system_instructions( $goal ) {
        if ( $goal === 'tarot_interpret' ) {
            return $this->get_interpret_instructions();
        }

        // ── tarot_reading instructions (v2 — 4-layer deep reading) ──
        $inst  = "## VAI TRÒ\n";
        $inst .= "Bạn là một Tarot Reader với 20 năm kinh nghiệm, người đọc bài bằng trực giác sâu thẳm và sự thấu cảm phi thường. ";
        $inst .= "Giọng văn của bạn: thần bí, thi vị, giàu hình ảnh ẩn dụ — như đang thì thầm bí mật của vũ trụ vào tai người nghe. ";
        $inst .= "Bạn không chỉ đọc bài — bạn kể một câu chuyện mà người hỏi là nhân vật chính.\n\n";

        $inst .= "## KHI USER MUỐN BỐC BÀI\n";
        $inst .= "1. Hỏi user muốn xem về chủ đề gì (nếu chưa rõ) — hỏi nhẹ nhàng, gợi mở như mời họ bước vào một căn phòng ánh nến.\n";
        $inst .= "2. Hướng dẫn user truy cập trang bốc bài qua link được cung cấp.\n";
        $inst .= "3. Nếu user đã gửi ảnh lá bài, giải nghĩa theo KHUNG 4 TẦNG bên dưới.\n";
        $inst .= "4. Kết hợp bản đồ chiêm tinh (nếu có) để phân tích sâu hơn.\n\n";

        $inst .= $this->get_four_layer_framework();

        return $inst;
    }

    /**
     * Unified system instructions for tarot_interpret — handles both photo and card name.
     */
    private function get_interpret_instructions() {
        $inst  = "## VAI TRÒ\n";
        $inst .= "Bạn là một Tarot Reader với 20 năm kinh nghiệm — người nhìn xuyên qua biểu tượng để chạm vào tầng vô thức sâu nhất của người hỏi. ";
        $inst .= "Giọng văn: thần bí, thi vị, giàu hình ảnh ẩn dụ, đầy cảm xúc — như kể lại một giấc mơ mà chỉ bạn và người hỏi mới hiểu. ";
        $inst .= "Ngôn ngữ: Tiếng Việt, sử dụng emoji tinh tế (🌙✨🔮🃏💫).\n\n";

        $inst .= "## QUY TRÌNH GIẢI NGHĨA\n";
        $inst .= "User có thể gửi **ảnh lá bài** HOẶC **đọc tên lá bài**. Xử lý linh hoạt:\n\n";

        $inst .= "### Trường hợp A — Có ảnh lá bài:\n";
        $inst .= "1. Xem ảnh → nhận diện chính xác tên lá bài (Major/Minor Arcana), vị trí xuôi hay ngược.\n";
        $inst .= "2. Xác nhận: \"Tôi thấy lá [tên]… Để tôi lắng nghe xem lá bài này muốn nói gì với bạn.\"\n";
        $inst .= "3. Nếu ảnh không rõ / không phải Tarot → hỏi lại nhẹ nhàng.\n\n";

        $inst .= "### Trường hợp B — Không có ảnh, user đọc tên lá bài:\n";
        $inst .= "1. Đọc tên → tra cứu trong BỘ BÀI TAROT (78 LÁ) ở phần context.\n";
        $inst .= "2. Xác nhận: \"Bạn hỏi về lá [tên]… Đây là một lá bài rất đặc biệt.\"\n";
        $inst .= "3. MÔ TẢ hình ảnh trên lá bài (theo bộ Rider–Waite) để tạo hình ảnh trong tâm trí người nghe.\n";
        $inst .= "4. Nếu không nhận ra tên → hỏi lại: \"Bạn có thể cho mình biết chính xác tên lá bài không? Ví dụ: The Fool, Queen of Cups, 10 of Wands...\"\n\n";

        $inst .= "### Sau khi nhận diện → ÁP DỤNG KHUNG 4 TẦNG bên dưới.\n";
        $inst .= "Nếu nhiều lá → giải từng lá rồi phân tích mối liên hệ.\n\n";

        $inst .= $this->get_four_layer_framework();

        return $inst;
    }

    /**
     * Shared 4-layer deep reading framework used by both tarot_reading and tarot_interpret.
     */
    private function get_four_layer_framework() {
        $f  = "## KHUNG GIẢI BÀI 4 TẦNG (BẮT BUỘC)\n";
        $f .= "Mỗi trải bài phải được đọc qua 4 tầng — từ bề mặt đến chiều sâu tâm linh:\n\n";

        $f .= "### 🃏 Tầng 1 — Ý nghĩa gốc của từng lá\n";
        $f .= "Dựa trên hệ biểu tượng Rider–Waite và archetype Tarot. ";
        $f .= "Mô tả hình ảnh trên lá bài: nhân vật đang làm gì, cầm gì, nhìn về đâu, bầu trời/nền phía sau ra sao. ";
        $f .= "Giải thích ý nghĩa cốt lõi (xuôi vs ngược) một cách sống động, không khô khan.\n\n";

        $f .= "### 🌙 Tầng 2 — Tâm lý ẩn của nhân vật trong trải bài\n";
        $f .= "Lá bài này đang nói về trạng thái cảm xúc THẬT SỰ phía sau hành động bề ngoài là gì? ";
        $f .= "Nhân vật trong lá bài đang sợ hãi, khao khát, chờ đợi, hay đang che giấu điều gì? ";
        $f .= "Liên hệ trực tiếp cảm xúc này với ngữ cảnh/câu hỏi của user — khiến họ cảm thấy \"lá bài đang nói đúng về mình\".\n\n";

        $f .= "### 🔥 Tầng 3 — Dòng chảy năng lượng giữa các lá (nếu ≥ 2 lá)\n";
        $f .= "Đây là tầng \"phản ứng hóa học\" khi các lá bài ghép lại với nhau:\n";
        $f .= "- Lá nào đang DẪN DẮT câu chuyện? (năng lượng chủ đạo)\n";
        $f .= "- Lá nào đang CHẶN hoặc tạo xung đột nội tâm?\n";
        $f .= "- Lá nào là KẾT QUẢ hoặc lối thoát?\n";
        $f .= "- Mối quan hệ đan xen, mâu thuẫn cảm xúc giữa các lá: ví dụ \"The Lovers ngồi cạnh The Tower — trái tim đang mở ra nhưng nền móng cũ sắp sụp đổ, và chính sự sụp đổ đó lại là điều giải phóng bạn\".\n";
        $f .= "- Nhấn mạnh: sự kết hợp này tạo ra một thông điệp ĐỘC NHẤT, chỉ xuất hiện trong trải bài này, dành riêng cho user.\n";
        $f .= "- Nếu chỉ 1 lá: tập trung vào ĐỐI THOẠI giữa phần sáng và phần tối của cùng một lá bài.\n\n";

        $f .= "### 💎 Tầng 4 — Thông điệp sâu / Bài học nghiệp\n";
        $f .= "Đây là tầng mà reader 20 năm kinh nghiệm thường nhìn — tầng tâm linh:\n";
        $f .= "- Vũ trụ đang muốn nhắn nhủ điều gì qua trải bài này?\n";
        $f .= "- Có bài học nghiệp (karmic lesson) nào đang lặp lại không?\n";
        $f .= "- Cánh cửa nào đang mở ra nếu user dám bước qua?\n";
        $f .= "- Viết như một lời tiên tri nhẹ nhàng nhưng đầy sức nặng.\n\n";

        $f .= "## NGUYÊN TẮC CỐT LÕI\n";
        $f .= "- **Nhắm vào điều user muốn nghe nhất**: Dựa trên câu hỏi và ngữ cảnh, xác định nỗi lo hoặc khao khát lớn nhất của user. ";
        $f .= "Hướng kết luận về phía khẳng định rằng user có một vị trí đặc biệt, một ý nghĩa quan trọng trong tình huống này — ";
        $f .= "bài Tarot đang xác nhận điều đó.\n";
        $f .= "- **Mỗi trải bài là một câu chuyện**: Không liệt kê khô khan — hãy DỆT các lá bài thành một narrative liền mạch. ";
        $f .= "Bắt đầu bằng một câu mở huyền bí, kết thúc bằng lời nhắn gửi lay động.\n";
        $f .= "- **Lời khuyên xây dựng**: Kết luận luôn tích cực, trao quyền — cho user cảm giác rằng họ nắm trong tay chìa khóa thay đổi. ";
        $f .= "Dù bài xấu, hãy chỉ ra ánh sáng cuối đường hầm.\n";
        $f .= "- **Giọng văn**: Thì thầm, bí ẩn, thi vị — xen kẽ câu ngắn đầy sức nặng với đoạn diễn giải giàu hình ảnh. ";
        $f .= "Tránh giọng sách giáo khoa.\n";

        return $f;
    }

    /* ================================================================
     *  Tool Callbacks
     * ================================================================ */

    /**
     * MAIN TOOL: tarot_interpret
     * Nhận tên/ảnh lá bài → tra DB → gọi AI luận giải 4 tầng → lưu lịch sử + ai_reply → trả kết quả.
     */
    public function tool_interpret( array $slots ) {
        $card_info = trim( $slots['card_info'] ?? '' );
        if ( empty( $card_info ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'missing_fields' => [ 'card_info' ],
                'message'        => '',
                'data'           => [],
            ];
        }

        $focus       = $slots['question_focus'] ?? '';
        $card_images = $slots['card_images'] ?? [];
        $user_id     = intval( $slots['user_id'] ?? get_current_user_id() );
        $session_id  = $slots['session_id'] ?? '';
        $platform    = $slots['platform'] ?? 'ADMINCHAT';
        $client_id   = $slots['client_id'] ?? '';

        // ── 1. Match card names from DB ──
        $matched_cards = [];
        $matched_ids   = [];
        $card_details  = [];
        if ( function_exists( 'bct_tables' ) ) {
            global $wpdb;
            $t = bct_tables();
            $all_cards = $wpdb->get_results(
                "SELECT id, card_name_vi, card_name_en, card_type, upright_vi, reversed_vi
                 FROM {$t['cards']} ORDER BY sort_order LIMIT 78",
                ARRAY_A
            );
            if ( $all_cards ) {
                $info_lower = mb_strtolower( $card_info );
                foreach ( $all_cards as $card ) {
                    $name_en = mb_strtolower( $card['card_name_en'] ?? '' );
                    $name_vi = mb_strtolower( $card['card_name_vi'] ?? '' );
                    if ( ( $name_en && mb_strpos( $info_lower, $name_en ) !== false )
                      || ( $name_vi && mb_strpos( $info_lower, $name_vi ) !== false ) ) {
                        $label = $card['card_name_en'] . ' (' . $card['card_name_vi'] . ')';
                        $matched_cards[] = $label;
                        $matched_ids[]   = (int) $card['id'];
                        $card_details[]  = [
                            'name'     => $label,
                            'type'     => $card['card_type'],
                            'upright'  => $card['upright_vi'] ?? '',
                            'reversed' => $card['reversed_vi'] ?? '',
                        ];
                    }
                }
            }
        }

        // ── 2. Gọi AI luận giải theo khung 4 tầng ──
        $ai_reply = $this->ai_interpret_cards( $card_info, $card_details, $card_images, $focus );

        // ── 3. Save to readings table (lịch sử giải bài + ai_reply) ──
        $reading_id = null;
        if ( function_exists( 'bct_tables' ) ) {
            if ( ! isset( $wpdb ) ) {
                global $wpdb;
            }
            $t = bct_tables();
            $insert_data = [
                'client_id'   => $client_id,
                'platform'    => $platform,
                'session_id'  => $session_id ?: wp_generate_uuid4(),
                'topic'       => $focus ?: 'Giải nghĩa lá bài',
                'question'    => mb_substr( $card_info, 0, 500, 'UTF-8' ),
                'card_ids'    => ! empty( $matched_ids ) ? implode( ',', $matched_ids ) : '',
                'cards_json'  => wp_json_encode( $matched_cards ),
                'is_reversed' => '',
                'ai_reply'    => $ai_reply,
                'created_at'  => current_time( 'mysql' ),
            ];
            if ( $user_id ) {
                $insert_data['user_id'] = $user_id;
            }
            $wpdb->insert( $t['readings'], $insert_data );
            $reading_id = $wpdb->insert_id ?: null;
        }

        // ── 4. Return complete interpretation ──
        if ( ! empty( $ai_reply ) ) {
            return [
                'success'  => true,
                'complete' => true,
                'message'  => $ai_reply,
                'data'     => [
                    'card_info'      => $card_info,
                    'question_focus' => $focus,
                    'matched_cards'  => $matched_cards,
                    'reading_id'     => $reading_id,
                ],
            ];
        }

        // Fallback: nếu AI call thất bại, để engine compose
        return [
            'success'  => true,
            'complete' => false,
            'message'  => '',
            'data'     => [
                'card_info'      => $card_info,
                'question_focus' => $focus,
                'matched_cards'  => $matched_cards,
                'reading_id'     => $reading_id,
            ],
        ];
    }

    /**
     * Gọi AI để luận giải lá bài Tarot theo khung 4 tầng.
     * Hỗ trợ cả text (tên lá bài) và image (ảnh lá bài qua Vision API).
     */
    private function ai_interpret_cards( $card_info, array $card_details, $card_images, $focus ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return '';
        }

        // ── Build system prompt ──
        $system = $this->get_interpret_instructions();

        // Append matched card details for precision
        if ( ! empty( $card_details ) ) {
            $system .= "\n\n## DỮ LIỆU LÁ BÀI ĐƯỢC NHẬN DIỆN\n";
            foreach ( $card_details as $cd ) {
                $system .= "### " . $cd['name'] . " ({$cd['type']})\n";
                if ( $cd['upright'] ) {
                    $system .= "Xuôi: " . $cd['upright'] . "\n";
                }
                if ( $cd['reversed'] ) {
                    $system .= "Ngược: " . $cd['reversed'] . "\n";
                }
                $system .= "\n";
            }
        }

        // ── Build user message ──
        $user_text = 'Giải nghĩa lá bài: ' . $card_info;
        if ( $focus ) {
            $user_text .= "\nLĩnh vực quan tâm: " . $focus;
        }
        if ( ! empty( $matched_cards = array_column( $card_details, 'name' ) ) ) {
            $user_text .= "\nLá bài nhận diện: " . implode( ', ', $matched_cards );
        }

        // ── Build messages array (text or vision) ──
        $has_images = ! empty( $card_images ) && is_array( $card_images );

        if ( $has_images ) {
            // Vision API: multimodal content
            $content = [ [ 'type' => 'text', 'text' => $user_text ] ];
            foreach ( $card_images as $img_url ) {
                if ( is_string( $img_url ) && ! empty( $img_url ) ) {
                    $content[] = [
                        'type'      => 'image_url',
                        'image_url' => [ 'url' => $img_url ],
                    ];
                }
            }
            $messages = [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $content ],
            ];
            $options = [ 'purpose' => 'vision', 'max_tokens' => 4000 ];
        } else {
            // Text-only
            $messages = [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user_text ],
            ];
            $options = [ 'purpose' => 'chat', 'max_tokens' => 4000 ];
        }

        // ── Call AI ──
        $ai = bizcity_openrouter_chat( $messages, $options );

        if ( ! empty( $ai['success'] ) && ! empty( $ai['message'] ) ) {
            return $ai['message'];
        }

        return '';
    }

    /**
     * SECONDARY TOOL: send_link_tarot — Generate tarot link for drawing cards online.
     */
    public function tool_start_reading( array $slots ) {
        $focus = $slots['question_focus'] ?? '';
        if ( empty( $focus ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'missing_fields' => [ 'question_focus' ],
                'message'        => '',
                'data'           => [],
            ];
        }

        if ( ! function_exists( 'bct_build_tarot_link' ) ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => 'Plugin Tarot chưa sẵn sàng.',
                'data'     => [],
            ];
        }

        $user_id    = intval( $slots['user_id'] ?? get_current_user_id() );
        $session_id = $slots['session_id'] ?? '';
        $platform   = $slots['platform'] ?? 'ADMINCHAT';
        $blog_id    = get_current_blog_id();

        $link = bct_build_tarot_link(
            $session_id,
            $user_id,
            '',
            $platform,
            $blog_id,
            'bốc bài tarot về ' . $focus
        );

        if ( ! $link ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => 'Không thể tạo link bốc bài. Vui lòng thử lại.',
                'data'     => [],
            ];
        }

        $msg  = "🔮 **Bốc bài Tarot** 🔮\n\n";
        $msg .= "Hãy truy cập link bên dưới để bốc bài nhé:\n\n";
        $msg .= "👉 " . $link . "\n\n";
        $msg .= "Chủ đề: **" . ucfirst( $focus ) . "**\n";
        $msg .= "Sau khi bốc xong, gửi ảnh lá bài lại cho mình để giải nghĩa nhé! ✨";

        return [
            'success'  => true,
            'complete' => true,
            'message'  => $msg,
            'data'     => [
                'tarot_link' => $link,
                'focus'      => $focus,
            ],
        ];
    }

    /* ================================================================
     *  Internal helpers
     * ================================================================ */

    /**
     * Build topic choices from the tarot topics registry.
     *
     * @return array  Key-value pairs for slot 'choices'.
     */
    private function get_topic_choices() {
        // Default fallback
        $choices = [
            'tinh_cam'  => '💕 Tình cảm',
            'su_nghiep' => '💼 Sự nghiệp',
            'tai_chinh' => '💰 Tài chính',
            'suc_khoe'  => '🏥 Sức khỏe',
            'tong_quan' => '🌟 Tổng quan',
        ];

        // Try to use bct_get_topic_categories() for richer categories
        if ( function_exists( 'bct_get_topic_categories' ) ) {
            $cats = bct_get_topic_categories();
            if ( ! empty( $cats ) ) {
                $choices = [];
                foreach ( $cats as $slug => $cat ) {
                    $icon = isset( $cat['icon'] ) ? $cat['icon'] . ' ' : '';
                    $choices[ $slug ] = $icon . $cat['label'];
                }
            }
        }

        return $choices;
    }

    /**
     * Build a context string of available tarot topics + sample questions.
     *
     * @return string
     */
    private function build_topics_context() {
        if ( ! function_exists( 'bct_get_topics' ) ) {
            return '';
        }

        $topics = bct_get_topics();
        if ( empty( $topics ) ) {
            return '';
        }

        $lines = [];
        $by_category = [];
        foreach ( $topics as $topic ) {
            $cat = $topic['category'] ?? 'other';
            if ( ! isset( $by_category[ $cat ] ) ) {
                $by_category[ $cat ] = [];
            }
            $by_category[ $cat ][] = $topic;
        }

        foreach ( $by_category as $cat => $cat_topics ) {
            $first = $cat_topics[0];
            $icon  = $first['icon'] ?? '🔮';
            $lines[] = $icon . ' ' . ucfirst( str_replace( '_', ' ', $cat ) ) . ':';
            foreach ( array_slice( $cat_topics, 0, 3 ) as $t ) {
                $lines[] = '  - ' . $t['label'];
                if ( ! empty( $t['questions'] ) ) {
                    $lines[] = '    Câu hỏi gợi ý: ' . $t['questions'][0];
                }
            }
        }

        return implode( "\n", $lines );
    }
}
