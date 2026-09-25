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
 * BizCity Intent Clarify Gate
 *
 * Decides whether the current user prompt is clear enough to continue into
 * knowledge/execution routing. If not clear, returns a deterministic
 * clarification prompt so the user can choose a direction.
 *
 * @package BizCity_Intent
 * @since   4.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Clarify_Gate {

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check clarity at mode-level (before intent router step 3).
     *
     * @param string $message
     * @param array  $mode_result
     * @param array  $conversation
     * @return array { should_clarify, prompt, reason, score }
     */
    public function assess_mode( string $message, array $mode_result, array $conversation = [] ): array {
        $status       = $conversation['status'] ?? '';
        $has_goal      = ! empty( $conversation['goal'] );
        $waiting_field = $conversation['waiting_field'] ?? '';

        // Do not interrupt existing HIL flows.
        if ( $status === 'WAITING_USER' || $has_goal || $waiting_field ) {
            return [ 'should_clarify' => false, 'prompt' => '', 'reason' => 'active_flow', 'score' => 1.0 ];
        }

        $trimmed    = trim( $message );
        $msg_length = mb_strlen( $trimmed, 'UTF-8' );
        if ( $msg_length < 2 ) {
            return [
                'should_clarify' => true,
                'prompt'         => 'Bạn muốn mình hỗ trợ theo hướng nào?\n\n1) Tìm hiểu thông tin\n2) Thực thi một việc cụ thể\n\nBạn trả lời 1 hoặc 2 nhé.',
                'reason'         => 'too_short',
                'score'          => 0.2,
            ];
        }

        $mode       = $mode_result['mode'] ?? 'ambiguous';
        $confidence = (float) ( $mode_result['confidence'] ?? 0.0 );

        // v4.3.7: Messages >5 chars should go to the main LLM, not get trapped
        // in a clarify loop. The Chat Gateway LLM handles real messages far better
        // than a binary "1 or 2" choice prompt. Only clarify very short fragments.
        if ( $msg_length > 5 ) {
            return [ 'should_clarify' => false, 'prompt' => '', 'reason' => 'substantive_msg', 'score' => max( 0.3, $confidence ) ];
        }

        // Only clarify when BOTH ambiguous AND low confidence.
        // High-confidence ambiguous (e.g. "hello bạn" → conf=1.0) means the LLM
        // is SURE it's casual/social — let the ambiguous pipeline handle it naturally.
        // Previously used OR which trapped ALL ambiguous messages including greetings.
        if ( $mode === 'ambiguous' && $confidence < 0.62 ) {
            $prompt = "Mình cần làm rõ trước khi xử lý để tránh sai ý.\n\n"
                . "Bạn muốn:\n"
                . "1) Tìm hiểu/tham khảo thông tin\n"
                . "2) Thực thi một hành động cụ thể\n\n"
                . "Nếu chọn 2, bạn ghi rõ mục tiêu + kết quả mong muốn (ví dụ: viết bài 700 chữ và đăng Facebook).";

            return [
                'should_clarify' => true,
                'prompt'         => $prompt,
                'reason'         => 'mode_ambiguous',
                'score'          => max( 0.2, $confidence ),
            ];
        }

        return [ 'should_clarify' => false, 'prompt' => '', 'reason' => 'clear', 'score' => $confidence ];
    }

    /**
     * Check clarity at intent-level (after router step 3).
     *
     * @param string $message
     * @param array  $intent
     * @param array  $conversation
     * @return array { should_clarify, prompt, reason }
     */
    public function assess_intent( string $message, array $intent, array $conversation = [] ): array {
        $status = $conversation['status'] ?? '';
        if ( $status === 'WAITING_USER' ) {
            return [ 'should_clarify' => false, 'prompt' => '', 'reason' => 'waiting_user' ];
        }

        $intent_name = $intent['intent'] ?? '';
        $goal        = $intent['goal'] ?? '';

        // Already clear intent path.
        if ( in_array( $intent_name, [ 'new_goal', 'provide_input', 'continue_goal', 'end_conversation' ], true ) && $goal ) {
            return [ 'should_clarify' => false, 'prompt' => '', 'reason' => 'goal_resolved' ];
        }

        // Router unresolved -> ask the user to clarify intent direction.
        $prompt = "Mình chưa xác định rõ bạn muốn *tìm hiểu* hay *thực thi*.\n\n"
            . "Bạn trả lời theo mẫu giúp mình:\n"
            . "- Tìm hiểu: 'Tìm hiểu về ...'\n"
            . "- Thực thi: 'Thực hiện ...' + thông tin đầu vào cần thiết";

        return [
            'should_clarify' => true,
            'prompt'         => $prompt,
            'reason'         => 'intent_unresolved',
        ];
    }

    /**
     * Resolve user's answer for clarify prompt.
     *
     * @param string $message
     * @return array {
     *   @type bool   $resolved
     *   @type string $choice       knowledge|execution|unknown
     *   @type string $forced_mode  knowledge|execution|''
     *   @type string $retry_prompt
     * }
     */
    public function resolve_reply( string $message ): array {
        $raw = trim( $message );
        $msg = mb_strtolower( $raw, 'UTF-8' );

        // Numeric choices from clarify prompt.
        if ( preg_match( '/^(?:1|1\.|1\)|m[ộo]t)$/u', $msg ) ) {
            return [
                'resolved'     => true,
                'choice'       => 'knowledge',
                'forced_mode'  => 'knowledge',
                'retry_prompt' => '',
            ];
        }
        if ( preg_match( '/^(?:2|2\.|2\)|hai)$/u', $msg ) ) {
            return [
                'resolved'     => true,
                'choice'       => 'execution',
                'forced_mode'  => 'execution',
                'retry_prompt' => '',
            ];
        }

        // Semantic choices.
        if ( preg_match( '/\b(t[ìi]m\s*hi[ểe]u|tham\s*k[hảa]o|gi[ảa]i\s*th[íi]ch|th[ôo]ng\s*tin|h[ỏo]i\s*đ[áa]p)\b/u', $msg ) ) {
            return [
                'resolved'     => true,
                'choice'       => 'knowledge',
                'forced_mode'  => 'knowledge',
                'retry_prompt' => '',
            ];
        }
        if ( preg_match( '/\b(th[ựu]c\s*thi|l[àa]m\s*gi[úu]p|t[ạa]o|đ[ăa]ng|g[ửu]i|ch[ạa]y|tri[ểe]n\s*khai|th[ựu]c\s*hi[ệe]n)\b/u', $msg ) ) {
            return [
                'resolved'     => true,
                'choice'       => 'execution',
                'forced_mode'  => 'execution',
                'retry_prompt' => '',
            ];
        }

        return [
            'resolved'     => false,
            'choice'       => 'unknown',
            'forced_mode'  => '',
            'retry_prompt' => "Mình chưa chắc bạn chọn hướng nào.\n\nTrả lời giúp mình:\n1) Tìm hiểu thông tin\n2) Thực thi một việc cụ thể\n\nBạn có thể trả lời 1 hoặc 2.",
        ];
    }
}
