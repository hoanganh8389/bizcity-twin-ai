<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Twin Suggest — Conversation Summary + Follow-up Question Suggestions
 *
 * Builds 2 follow-up question suggestions based on:
 *   1. Historical memory (episodic + rolling memories) — deepen past context
 *   2. Current session context — deepen current conversation
 *
 * Injected as a system prompt section replacing the old tool suggestion block.
 * The AI is instructed to end every response with exactly 2 short questions
 * that guide the user to explore deeper.
 *
 * @package  BizCity_Twin_Core
 * @version  0.1.0
 * @since    2026-03-22
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Suggest {

    /**
     * Build the suggest prompt section.
     *
     * Called from Chat Gateway at step 7.5 (replacing tool manifest).
     * Gathers signals from memory + session, then produces a system prompt
     * instruction telling the AI HOW to generate 2 follow-up suggestions.
     *
     * @param array $args {
     *   user_id:        int
     *   session_id:     string
     *   message:        string   Current user message
     *   mode:           string   Focus mode (emotion|knowledge|planning|...)
     *   engine_result:  array    Intent engine result with conversation_id, meta, etc.
     * }
     * @return string System prompt section (empty string if nothing to suggest)
     */
    public static function build( array $args ): string {
        $user_id       = (int) ( $args['user_id'] ?? 0 );
        $session_id    = $args['session_id'] ?? '';
        $message       = $args['message'] ?? '';
        $mode          = $args['mode'] ?? '';
        $engine_result = $args['engine_result'] ?? [];

        // ── 1. Gather historical memory signals ──
        $memory_hints = self::gather_memory_hints( $user_id, $session_id );

        // ── 2. Gather current session signals ──
        $session_hints = self::gather_session_hints( $user_id, $session_id, $engine_result );

        // ── 3. Build the prompt instruction ──
        return self::compose_prompt( $message, $mode, $memory_hints, $session_hints );
    }

    /**
     * Gather signals from episodic + rolling memory + user memory.
     *
     * Returns a compact summary of what we know about the user's history,
     * which the AI will use to craft the FIRST follow-up question.
     *
     * @param int    $user_id
     * @param string $session_id
     * @return array { topics: string[], goals: string[], patterns: string[] }
     */
    private static function gather_memory_hints( int $user_id, string $session_id ): array {
        $hints = [
            'topics'   => [],
            'goals'    => [],
            'patterns' => [],
        ];

        if ( ! $user_id && empty( $session_id ) ) {
            return $hints;
        }

        // ── Episodic memory: recurring themes, habits, past goals ──
        if ( class_exists( 'BizCity_Episodic_Memory' ) ) {
            $episodic = BizCity_Episodic_Memory::instance();
            $habits   = $episodic->get_habits( $user_id );
            foreach ( array_slice( $habits, 0, 3 ) as $h ) {
                $hints['patterns'][] = $h->event_text;
            }
        }

        // ── Rolling memory: active goals, recent completions ──
        if ( class_exists( 'BizCity_Rolling_Memory' ) ) {
            $rolling = BizCity_Rolling_Memory::instance();
            $active  = $rolling->get_active_for_user( $user_id, $session_id );
            foreach ( array_slice( $active, 0, 3 ) as $r ) {
                $label = $r->goal_label ?: $r->goal;
                if ( $label ) {
                    $hints['goals'][] = $label . ( $r->user_goal_score > 0 ? " ({$r->user_goal_score}%)" : '' );
                }
            }

            $completed = $rolling->get_recently_completed( $user_id, 60 );
            foreach ( array_slice( $completed, 0, 2 ) as $c ) {
                $status = $c->status === 'completed' ? '✅' : '❌';
                $hints['goals'][] = "{$status} " . ( $c->goal_label ?: $c->goal );
            }
        }

        // ── User memory: key identity/goal/pain points ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $mem      = BizCity_User_Memory::instance();
            $memories = $mem->get_memories( [
                'user_id'    => $user_id,
                'session_id' => $session_id,
                'limit'      => 5,
                'order_by'   => 'score',
            ] );
            foreach ( $memories as $m ) {
                if ( in_array( $m->memory_type, [ 'goal', 'pain', 'request' ], true ) ) {
                    $hints['topics'][] = "[{$m->memory_type}] {$m->memory_text}";
                }
            }
        }

        return $hints;
    }

    /**
     * Gather signals from the current session / conversation context.
     *
     * Returns information about what's happening RIGHT NOW,
     * which the AI will use to craft the SECOND follow-up question.
     *
     * @param int    $user_id
     * @param string $session_id
     * @param array  $engine_result
     * @return array { current_goal: string, open_slots: string[], session_title: string, turn_count: int }
     */
    private static function gather_session_hints( int $user_id, string $session_id, array $engine_result ): array {
        $hints = [
            'current_goal'  => '',
            'open_slots'    => [],
            'session_title' => '',
            'turn_count'    => 0,
        ];

        // ── From engine result: current intent goal + slots ──
        $conv_id = $engine_result['conversation_id'] ?? '';
        if ( $conv_id && class_exists( 'BizCity_Intent_Database' ) ) {
            $db   = BizCity_Intent_Database::instance();
            $conv = $db->get_conversation( $conv_id );
            if ( $conv ) {
                $hints['current_goal'] = $conv->goal_label ?? $conv->goal ?? '';
                $hints['turn_count']   = (int) ( $conv->turn_count ?? 0 );

                // Open slots = things AI still needs to ask about
                $slots = json_decode( $conv->slots ?? '{}', true );
                if ( is_array( $slots ) ) {
                    foreach ( $slots as $name => $val ) {
                        if ( empty( $val ) || $val === null ) {
                            $hints['open_slots'][] = $name;
                        }
                    }
                }
            }
        }

        // ── Session title from webchat ──
        if ( $session_id && class_exists( 'BizCity_Webchat_Database' ) ) {
            $wdb     = BizCity_Webchat_Database::instance();
            $session = $wdb->get_session( $session_id );
            if ( $session ) {
                $hints['session_title'] = $session->title ?? '';
            }
        }

        return $hints;
    }

    /**
     * Compose the system prompt instruction from gathered hints.
     *
     * @param string $message        Current user message
     * @param string $mode           Focus mode
     * @param array  $memory_hints   From gather_memory_hints()
     * @param array  $session_hints  From gather_session_hints()
     * @return string
     */
    private static function compose_prompt( string $message, string $mode, array $memory_hints, array $session_hints ): string {
        $prompt = "\n\n## 💡 GỢI Ý TIẾP TỤC HỘI THOẠI (BẮT BUỘC)\n\n";
        $prompt .= "Sau mỗi câu trả lời, bạn PHẢI kết thúc bằng đúng **2 câu hỏi gợi mở** để Chủ Nhân tiếp tục hội thoại.\n";
        $prompt .= "Các câu hỏi phải ngắn gọn (≤ 20 từ), cụ thể, và giúp Chủ Nhân đào sâu vào vấn đề.\n\n";

        // ── Suggest source 1: Historical memory ──
        $prompt .= "### 📌 Câu hỏi 1 — Dựa trên KÝ ỨC & LỊCH SỬ:\n";
        $has_memory = ! empty( $memory_hints['goals'] )
                   || ! empty( $memory_hints['patterns'] )
                   || ! empty( $memory_hints['topics'] );

        if ( $has_memory ) {
            $prompt .= "Sử dụng thông tin sau để gợi ý câu hỏi liên kết quá khứ → hiện tại:\n";

            if ( ! empty( $memory_hints['goals'] ) ) {
                $prompt .= "- Mục tiêu gần đây: " . implode( ' | ', array_slice( $memory_hints['goals'], 0, 3 ) ) . "\n";
            }
            if ( ! empty( $memory_hints['patterns'] ) ) {
                $prompt .= "- Thói quen: " . implode( ' | ', array_slice( $memory_hints['patterns'], 0, 3 ) ) . "\n";
            }
            if ( ! empty( $memory_hints['topics'] ) ) {
                $prompt .= "- Quan tâm: " . implode( ' | ', array_slice( $memory_hints['topics'], 0, 3 ) ) . "\n";
            }
            $prompt .= "→ Hỏi 1 câu kết nối nội dung hiện tại với ký ức/mục tiêu cũ.\n";
        } else {
            $prompt .= "Chưa có nhiều ký ức → Hỏi 1 câu khám phá: sở thích, mục tiêu, hoặc thách thức hiện tại của Chủ Nhân.\n";
        }

        // ── Suggest source 2: Current session ──
        $prompt .= "\n### 💬 Câu hỏi 2 — Dựa trên PHIÊN CHAT HIỆN TẠI:\n";

        if ( ! empty( $session_hints['current_goal'] ) ) {
            $prompt .= "- Mục tiêu hiện tại: {$session_hints['current_goal']}\n";
        }
        if ( ! empty( $session_hints['open_slots'] ) ) {
            $prompt .= "- Thông tin còn thiếu: " . implode( ', ', array_slice( $session_hints['open_slots'], 0, 3 ) ) . "\n";
        }
        if ( ! empty( $session_hints['session_title'] ) ) {
            $prompt .= "- Chủ đề phiên: {$session_hints['session_title']}\n";
        }

        $prompt .= "→ Hỏi 1 câu đào sâu vào chủ đề đang thảo luận hoặc gợi ý góc nhìn mới.\n";

        // ── Mode-specific guidance ──
        $prompt .= "\n### 🎯 Phong cách gợi ý theo chế độ:\n";
        switch ( $mode ) {
            case 'emotion':
                $prompt .= "Chế độ Cảm xúc → Câu hỏi nhẹ nhàng, đồng cảm, khuyến khích chia sẻ sâu hơn.\n";
                break;
            case 'reflection':
                $prompt .= "Chế độ Suy ngẫm → Câu hỏi giúp nhìn nhận lại, rút ra bài học.\n";
                break;
            case 'planning':
                $prompt .= "Chế độ Lên kế hoạch → Câu hỏi về bước tiếp theo, ưu tiên, timeline.\n";
                break;
            case 'execution':
                $prompt .= "Chế độ Thực thi → Câu hỏi ngắn gọn, xác nhận kết quả hoặc bước tiếp.\n";
                break;
            case 'studio':
                $prompt .= "Chế độ Sáng tạo → Câu hỏi mở rộng ý tưởng, thử nghiệm.\n";
                break;
            default:
                $prompt .= "Câu hỏi tự nhiên, giúp khám phá sâu hơn vào chủ đề.\n";
                break;
        }

        $prompt .= "\n### ⚠️ QUY TẮC GỢI Ý:\n";
        $prompt .= "- LUÔN gợi ý đúng 2 câu, đặt cuối câu trả lời.\n";
        $prompt .= "- Format: `💡 **Gợi ý:** Câu hỏi 1? | Câu hỏi 2?`\n";
        $prompt .= "- KHÔNG gợi ý công cụ. KHÔNG gợi ý chung chung kiểu \"bạn muốn biết thêm gì?\".\n";
        $prompt .= "- Câu hỏi phải CỤ THỂ dựa trên nội dung vừa trả lời + dữ liệu ở trên.\n";

        // Trace
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::log( 'suggest_build', [
                'mode'         => $mode,
                'has_memory'   => $has_memory,
                'goals_count'  => count( $memory_hints['goals'] ),
                'current_goal' => $session_hints['current_goal'],
                'open_slots'   => count( $session_hints['open_slots'] ),
            ] );
        }

        return $prompt;
    }
}
