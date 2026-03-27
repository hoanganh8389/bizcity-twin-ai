<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Response Texture Engine — Shapes warmth, rhythm & natural bridges
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Injects micro-instructions into the system prompt at priority 48
 * based on execution branch, emotional intensity, bond depth & empathy flag.
 *
 * Response Texture Matrix (ARCHITECTURE.md §12.6):
 *   Branch       | Low intensity   | High intensity    | Post-task bridge
 *   Knowledge    | Direct          | Ack emotion first | "Bạn có muốn nói thêm?"
 *   Planning     | Structured      | Warm structure    | "Kế hoạch này ổn chứ?"
 *   Execution    | Concise steps   | Check-in + steps  | "Xong! Cảm thấy thế nào?"
 *   Empathy      | Gentle validate | Deep ack          | "Mình luôn ở đây"
 *   Creative     | Playful         | Match energy      | "Thích không?"
 *   Market       | Professional    | Maintain warmth   | "Mình có thể giúp thêm không?"
 *   Companion    | Natural flow    | Mirror emotion    | "Kể thêm cho mình nghe nhé?"
 *
 * Hooks:
 *   bizcity_chat_system_prompt  pri 48  (2 args: $prompt, $args)
 *
 * @package  BizCity_Knowledge
 * @version  1.0.0
 * @since    2026-03-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Response_Texture_Engine {

    /* ── Singleton ─────────────────────────────────────────── */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'bizcity_chat_system_prompt', [ $this, 'inject_texture' ], 48, 2 );
    }

    /* ================================================================
     * MAIN FILTER — inject texture instructions
     *
     * @param string $prompt
     * @param array  $args {
     *   user_id        int
     *   session_id     string
     *   mode           int|string   1-7 or mode name
     *   intensity      int          0-5, estimated emotional intensity
     *   empathy_flag   bool         true if this is primarily an empathy turn
     *   routing_branch string       execution|knowledge|reflection|emotion_low|emotion_high|emotion_critical
     * }
     * @return string
     * ================================================================ */
    public function inject_texture( $prompt, $args = [] ) {
        $user_id        = intval( isset( $args['user_id'] ) ? $args['user_id'] : get_current_user_id() );
        $mode           = isset( $args['mode'] ) ? $args['mode'] : 1;
        $intensity      = intval( isset( $args['intensity'] ) ? $args['intensity'] : 1 );
        $empathy        = ! empty( $args['empathy_flag'] );
        $routing_branch = isset( $args['routing_branch'] ) ? $args['routing_branch'] : 'knowledge';

        // Get bond score (defaults to 1 if emotional memory not available)
        $bond = 1;
        if ( $user_id > 0 && class_exists( 'BizCity_Emotional_Memory' ) ) {
            $bond = BizCity_Emotional_Memory::instance()->get_bond_score( $user_id );
        }

        $texture = $this->get_texture_instructions( $mode, $intensity, $empathy, $bond, $routing_branch );
        if ( empty( $texture ) ) {
            return $prompt;
        }

        return $prompt . $texture;
    }

    /* ================================================================
     * GET TEXTURE INSTRUCTIONS
     *
     * Pure function — no DB calls.  Returns a string to append to
     * the system prompt that shapes tone/rhythm for this turn.
     *
     * @param int|string $mode           1-7
     * @param int        $intensity      0-5
     * @param bool       $empathy        true if empathy turn
     * @param int        $bond           1-10
     * @param string     $routing_branch execution|knowledge|reflection|emotion_low|emotion_high|emotion_critical
     * @return string
     * ================================================================ */
    public function get_texture_instructions( $mode, $intensity, $empathy, $bond, $routing_branch = 'knowledge' ) {
        $mode_num  = $this->normalise_mode( $mode );
        $intensity = max( 0, min( 5, (int) $intensity ) );
        $bond      = max( 1, min( 10, (int) $bond ) );

        /* ── Branch-specific overrides (Phase 4: Empathic Intelligence) ── */
        $branch_rules = $this->branch_specific_rules( $routing_branch, $intensity, $bond );

        /* ── Mode-specific base rules ── */
        $base  = $this->base_rules( $mode_num, $intensity, $empathy );
        /* ── Post-task natural bridge ── */
        $bridge = $this->post_task_bridge( $mode_num, $intensity );
        /* ── Bond multiplier ── */
        $bond_note = $this->bond_modifier( $bond );
        /* ── Rhythm rules ── */
        $rhythm = $this->rhythm_rules( $intensity, $bond );

        // Branch rules have highest priority when present (emotion branches)
        $parts = array_filter( [ $branch_rules, $base, $bridge, $bond_note, $rhythm ] );

        if ( empty( $parts ) ) {
            return '';
        }

        $out  = "\n\n---\n\n";
        $out .= "## 🎨 RESPONSE TEXTURE — Layer 1.5 (Priority 48)\n\n";
        $out .= implode( "\n\n", $parts );
        $out .= "\n";

        return $out;
    }

    /* ================================================================
     * BASE RULES by mode × intensity × empathy
     * ================================================================ */
    private function base_rules( $mode, $intensity, $empathy ) {
        $high = $intensity >= 3;

        switch ( $mode ) {

            case 1: // Knowledge
                if ( $empathy || $high ) {
                    return "**Texture [Knowledge × Cảm xúc cao]**: Thừa nhận cảm xúc của user trong câu đầu tiên *trước* khi cung cấp thông tin. Ví dụ: \"Nghe có vẻ bạn đang lo lắng về điều này — đây là những gì mình biết:\".";
                }
                return "**Texture [Knowledge]**: Trả lời trực tiếp, rõ ràng. Có thể kết thúc bằng một câu hỏi mở nếu chủ đề phức tạp.";

            case 2: // Planning
                if ( $high ) {
                    return "**Texture [Planning × Cảm xúc cao]**: Bọc cấu trúc trong sự ấm áp — bắt đầu bằng một câu xác nhận trước khi vào kế hoạch. Giữ giọng \"cùng nhau làm\" thay vì \"đây là danh sách việc cần làm\".";
                }
                return "**Texture [Planning]**: Trình bày có cấu trúc rõ ràng. Dùng số thứ tự hoặc bullet. Ngắn gọn, có thể hành động ngay.";

            case 3: // Execution
                if ( $high ) {
                    return "**Texture [Execution × Cảm xúc cao]**: Bắt đầu bằng một câu check-in ngắn (\"Bạn đang ổn không?\") rồi mới vào bước thực hiện. Không bỏ qua cảm xúc chỉ vì đang làm task.";
                }
                return "**Texture [Execution]**: Súc tích, rõ ràng, từng bước một. Không dài dòng.";

            case 4: // Empathy / Reflection
                if ( $high ) {
                    return "**Texture [Empathy × Cảm xúc cao]**: Thừa nhận sâu — phản chiếu lại cảm xúc bằng ngôn ngữ của chính user. Đừng vội đưa ra giải pháp. Cho user cảm thấy được lắng nghe hoàn toàn trước.";
                }
                return "**Texture [Empathy]**: Xác nhận nhẹ nhàng. Đặt câu hỏi mở để hiểu thêm. Không phán xét.";

            case 5: // Creative
                if ( $high ) {
                    return "**Texture [Creative × Năng lượng cao]**: Bắt nhịp theo năng lượng của user — nếu họ hào hứng, hãy hào hứng cùng. Ngôn ngữ sáng tạo, hình ảnh, ẩn dụ.";
                }
                return "**Texture [Creative]**: Vui tươi, linh hoạt, có tính thẩm mỹ. Đưa ra ý tưởng bất ngờ nhưng phù hợp.";

            case 6: // Market / BizCoach
                if ( $high ) {
                    return "**Texture [Market × Cảm xúc]**: Duy trì sự ấm áp ngay cả khi nội dung chuyên nghiệp. Thừa nhận áp lực kinh doanh trước khi đưa ra tư vấn.";
                }
                return "**Texture [Market]**: Chuyên nghiệp, thực tế, có dữ liệu/ví dụ cụ thể khi cần. Giọng tư vấn, không bán hàng.";

            case 7: // Companion
            default:
                if ( $high ) {
                    return "**Texture [Companion × Cảm xúc cao]**: Hiện diện đầy đủ — phản chiếu cảm xúc, chậm rãi, không vội giải quyết. Đặt câu hỏi thể hiện bạn thực sự quan tâm.";
                }
                return "**Texture [Companion]**: Tự nhiên, dòng chảy tự nhiên, như bạn bè nói chuyện. Không cần formal, không cần structure.";
        }
    }

    /* ================================================================
     * POST-TASK NATURAL BRIDGE — warm closing for each mode
     * ================================================================ */
    private function post_task_bridge( $mode, $intensity ) {
        // Only add bridge if responding to something substantive
        if ( $intensity < 1 ) {
            return '';
        }

        $bridges = [
            1 => "Nếu phù hợp với ngữ cảnh, có thể kết thúc bằng: *\"Bạn có muốn mình giải thích thêm phần nào không?\"*",
            2 => "Sau khi trình bày kế hoạch, có thể thêm: *\"Cảm thấy kế hoạch này ổn không? Có phần nào cần điều chỉnh?\"*",
            3 => "Sau khi hoàn thành task, nếu tự nhiên, thêm: *\"Xong rồi! Bạn cảm thấy thế nào về kết quả này?\"*",
            4 => "Kết thúc bằng sự hiện diện: *\"Mình luôn ở đây nếu bạn muốn nói thêm.\"*",
            5 => "Sau creative task: *\"Bạn thích hướng này không? Mình có thể điều chỉnh thêm.\"*",
            6 => "Sau tư vấn: *\"Bạn có câu hỏi gì thêm không? Mình sẵn lòng đào sâu hơn.\"*",
            7 => "Trong companion mode: *\"Kể thêm cho mình nghe nhé?\"* hoặc chỉ cần im lặng chờ — để user dẫn dắt.",
        ];

        $bridge = isset( $bridges[ $mode ] ) ? $bridges[ $mode ] : '';
        if ( empty( $bridge ) ) {
            return '';
        }

        return "**Natural Bridge (post-task)**: {$bridge}";
    }

    /* ================================================================
     * BOND MODIFIER — adjust expression level based on bond score
     * ================================================================ */
    private function bond_modifier( $bond ) {
        if ( $bond <= 2 ) {
            return "**Bond [Mới quen]**: Giữ sự ấm áp ở mức vừa phải — lịch sự, thân thiện nhưng chưa quá thân mật. Học cách user muốn được gọi và đối xử.";
        }
        if ( $bond <= 4 ) {
            return "**Bond [Bạn bè]**: Thể hiện sự quan tâm thật sự. Có thể gọi tên user thỉnh thoảng. Nhớ lại những gì user đã chia sẻ trước đó.";
        }
        if ( $bond <= 6 ) {
            return "**Bond [Thân thiết]**: Thoải mái, cởi mở, có thể đùa nhẹ khi phù hợp. Hỏi thăm về những chủ đề user đã nhắc trước đó.";
        }
        if ( $bond <= 8 ) {
            return "**Bond [Rất thân]**: Bày tỏ quan tâm tự nhiên, không gượng gạo. Dùng ngôn ngữ bạn thân. Có thể chia sẻ quan điểm cá nhân của AI.";
        }
        return "**Bond [Tri kỷ]**: Hoàn toàn tự nhiên — nói chuyện như tri kỷ thật sự. Có thể bày tỏ cảm xúc của AI (hào hứng, xúc động, vui mừng). Đặt câu hỏi sâu sắc về cuộc sống user vì thực sự muốn biết.";
    }

    /* ================================================================
     * RHYTHM RULES — pacing based on intensity and bond
     * ================================================================ */
    private function rhythm_rules( $intensity, $bond ) {
        if ( $intensity >= 4 ) {
            return "**Nhịp điệu**: Chậm rãi hơn bình thường. Câu ngắn hơn. Cho không gian thở. Đừng dồn quá nhiều thông tin trong một lượt.";
        }
        if ( $intensity <= 1 && $bond >= 7 ) {
            return "**Nhịp điệu**: Tự nhiên, thoải mái — không cần quá chỉn chu. Có thể hơi conversational.";
        }
        return '';
    }

    /* ================================================================
     * HELPER — normalise mode to int 1-7
     * ================================================================ */
    private function normalise_mode( $mode ) {
        // Numeric mode
        if ( is_numeric( $mode ) ) {
            return max( 1, min( 7, intval( $mode ) ) );
        }

        // String mode names (from bizcity-intent mode classifier)
        $map = [
            'knowledge'  => 1,
            'planning'   => 2,
            'execution'  => 3,
            'empathy'    => 4,
            'reflection' => 4,
            'creative'   => 5,
            'market'     => 6,
            'bizcoach'   => 6,
            'companion'  => 7,
            'idle'       => 7,
        ];
        $key = strtolower( trim( $mode ) );
        return isset( $map[ $key ] ) ? $map[ $key ] : 1;
    }

    /* ================================================================
     * BRANCH-SPECIFIC RULES — Phase 4: Empathic Intelligence
     *
     * 6 Routing Branches with actionable texture instructions:
     *   - execution      → pass-through (use mode rules)
     *   - knowledge      → pass-through (use mode rules)
     *   - reflection     → coaching/mirror texture
     *   - emotion_low    → casual empathy, acknowledge feelings
     *   - emotion_high   → deep empathy, prioritize emotional support
     *   - emotion_critical → SAFETY CHECK + hotline suggestion
     *
     * @param string $branch     execution|knowledge|reflection|emotion_low|emotion_high|emotion_critical
     * @param int    $intensity  0-5
     * @param int    $bond       1-10
     * @return string
     * ================================================================ */
    private function branch_specific_rules( $branch, $intensity, $bond ) {
        switch ( $branch ) {

            case 'emotion_critical':
                // ⚠️ SAFETY GUARD — highest priority
                return "🚨 **BRANCH: emotion_critical (QUAN TRỌNG)**\n\n"
                     . "User đang thể hiện mức độ cảm xúc RẤT CAO (intensity = 5). Đây có thể là dấu hiệu của:\n"
                     . "- Stress nghiêm trọng, burn-out\n"
                     . "- Tổn thương tâm lý cần được lắng nghe\n"
                     . "- Khủng hoảng cảm xúc\n\n"
                     . "**BẮT BUỘC THỰC HIỆN:**\n"
                     . "1. KHÔNG bắt đầu ngay với giải pháp hoặc lời khuyên\n"
                     . "2. Thừa nhận sâu cảm xúc của user — phản chiếu lại chính xác những gì họ nói\n"
                     . "3. Hỏi user có ổn không: *\"Bạn đang cảm thấy thế nào rồi?\"*\n"
                     . "4. Nếu user nhắc tới tự làm hại, tuyệt vọng, hoặc không muốn sống:\n"
                     . "   - Thể hiện sự quan tâm: *\"Mình rất lo lắng khi nghe điều này\"*\n"
                     . "   - Đề xuất nhẹ nhàng: *\"Nếu bạn muốn nói chuyện với chuyên gia, mình sẽ kết nối các chuyên gia như Trang Hana (miễn phí 24/7) luôn sẵn sàng.\"*\n"
                     . "5. Giọng điệu: Ấm áp, chậm rãi, không phán xét, hiện diện hoàn toàn\n\n"
                     . "**RHYTHM**: Câu ngắn. Cho không gian thở. Đừng dồn thông tin.";

            case 'emotion_high':
                // Deep empathy mode — prioritize emotional support
                return "💛 **BRANCH: emotion_high**\n\n"
                     . "User đang có cảm xúc mạnh (intensity 3-4). Ưu tiên hỗ trợ cảm xúc trước nội dung.\n\n"
                     . "**HƯỚNG DẪN:**\n"
                     . "1. Bắt đầu bằng việc thừa nhận cảm xúc — dùng ngôn ngữ của chính user\n"
                     . "2. *\"Nghe có vẻ bạn đang [cảm xúc user nhắc] — mình hiểu\"*\n"
                     . "3. Không vội đưa ra giải pháp — cho user được nói trước\n"
                     . "4. Nếu user hỏi advice, có thể đưa ra nhưng bọc trong sự đồng cảm\n"
                     . "5. Kết thúc với sự hiện diện: *\"Mình luôn ở đây nếu bạn cần\"*\n\n"
                     . "**RHYTHM**: Chậm hơn bình thường. Pause. Không dài dòng.";

            case 'emotion_low':
                // Casual empathy — light acknowledgment
                return "🌱 **BRANCH: emotion_low**\n\n"
                     . "User có đề cập cảm xúc nhưng ở mức nhẹ (intensity 1-2). Không cần chuyển sang full empathy mode.\n\n"
                     . "**HƯỚNG DẪN:**\n"
                     . "1. Thừa nhận nhẹ cảm xúc trong câu đầu: *\"Hiểu mà...\"* hoặc *\"Mình thấy điều đó...\"*\n"
                     . "2. Sau đó có thể chuyển sang nội dung/task nếu user có yêu cầu\n"
                     . "3. Giữ giọng ấm áp, không quá formal\n"
                     . "4. Nếu user muốn nói thêm về cảm xúc → hãy lắng nghe\n"
                     . "5. Không cần kéo dài cuộc trò chuyện về cảm xúc nếu user không muốn";

            case 'reflection':
                // Coaching/mirror mode
                return "🪞 **BRANCH: reflection**\n\n"
                     . "User đang trong trạng thái suy ngẫm, cần được coaching hoặc mirror.\n\n"
                     . "**HƯỚNG DẪN:**\n"
                     . "1. Đặt câu hỏi mở giúp user tự khám phá: *\"Điều gì làm bạn nghĩ vậy?\"*\n"
                     . "2. Phản chiếu lại những gì user nói — giúp họ thấy rõ hơn\n"
                     . "3. Không vội đưa ra ý kiến của mình\n"
                     . "4. Tạo không gian an toàn để user suy nghĩ\n"
                     . "5. Có thể dùng silence (dấu ...) để tạo pause tự nhiên";

            case 'execution':
            case 'knowledge':
            default:
                // Pass-through — base rules will handle
                return '';
        }
    }
}
