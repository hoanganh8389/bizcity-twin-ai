<?php
/**
 * Intent Provider — AI Skill registration for BizCity {Name}.
 *
 * Connects this plugin to the Intent Engine (bizcity-intent).
 * Only loaded when Intent Engine is active (class_exists guard in bootstrap).
 *
 * @package BizCity_{Name}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_{Name}_Intent_Provider extends BizCity_Intent_Provider {

    /* ═══════════════════════════════════════════════
       IDENTITY (required)
       ═══════════════════════════════════════════════ */

    public function get_id() {
        return '{slug}';
    }

    public function get_name() {
        return 'BizCity {Name} — {Subtitle}';
    }

    /* ═══════════════════════════════════════════════
       GOAL PATTERNS — Needles for Router
       ═══════════════════════════════════════════════ */

    public function get_goal_patterns() {
        return array(
            '/{keyword1}|{keyword2}|{keyword3}|{keyword4}/ui' => array(
                'goal'    => '{slug}_reading',
                'label'   => '{Name} Reading',
                'extract' => array( 'question_focus' ),
            ),
            // Add more goals here...
        );
    }

    /* ═══════════════════════════════════════════════
       PLANS — Slot schema for Planner
       ═══════════════════════════════════════════════ */

    public function get_plans() {
        return array(
            '{slug}_reading' => array(
                'required_slots' => array(
                    'question_focus' => array(
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn hỏi về chủ đề gì?',
                        'choices' => $this->build_topic_choices(),
                    ),
                    'spread' => array(
                        'type'    => 'choice',
                        'prompt'  => 'Bạn muốn chọn bao nhiêu?',
                        'choices' => array(
                            '1' => '1 mục',
                            '3' => '3 mục (Quá khứ - Hiện tại - Tương lai)',
                        ),
                    ),
                ),
                'optional_slots' => array(),
                'tool'       => '{slug}_start_reading',
                'ai_compose' => true,
                'slot_order' => array( 'question_focus', 'spread' ),
            ),
        );
    }

    /* ═══════════════════════════════════════════════
       TOOLS — Execution callbacks
       ═══════════════════════════════════════════════ */

    public function get_tools() {
        return array(
            '{slug}_send_link' => array(
                'schema' => array(
                    'description'  => 'Gửi link {name} để bắt đầu phiên online',
                    'input_fields' => array(
                        'question_focus' => array( 'required' => true,  'type' => 'text' ),
                        'spread'         => array( 'required' => true,  'type' => 'text' ),
                    ),
                ),
                'callback' => array( $this, 'tool_start_reading' ),
            ),
        );
    }

    /**
     * Tool callback — Create reading link.
     *
     * @param array $slots
     * @return array
     */
    public function tool_start_reading( $slots ) {
        $focus  = isset( $slots['question_focus'] ) ? $slots['question_focus'] : '';
        $spread = isset( $slots['spread'] )         ? $slots['spread']         : '3';

        // Build link with focus as message context
        if ( function_exists( 'bz{prefix}_build_link' ) ) {
            $link = bz{prefix}_build_link( '', get_current_user_id(), '', 'WEBCHAT', 0, $focus );
        } else {
            $link = bz{prefix}_get_page_url();
        }

        return array(
            'success'  => true,
            'complete' => true,
            'message'  => "🔮 {Name} 🔮\n\n"
                        . "Chủ đề: {$focus}\n"
                        . "Số lượng: {$spread}\n\n"
                        . "👉 Truy cập link:\n{$link}\n\n"
                        . "Sau khi hoàn thành, kết quả sẽ gửi lại đây! ✨",
            'data'     => array(
                'link'   => $link,
                'focus'  => $focus,
                'spread' => $spread,
            ),
        );
    }

    /* ═══════════════════════════════════════════════
       KNOWLEDGE BINDING — Per-agent RAG context
       ═══════════════════════════════════════════════ */

    /**
     * Get the bizcity-knowledge character_id linked to this plugin.
     * Set via Admin → Settings → "Đào tạo kiến thức" tab.
     *
     * @return int  Character ID (0 = no knowledge binding).
     */
    public function get_knowledge_character_id() {
        return (int) get_option( 'bz{prefix}_knowledge_character_id', 0 );
    }

    /* ═══════════════════════════════════════════════
       CONTEXT — Domain knowledge for AI compose
       ═══════════════════════════════════════════════ */

    public function build_context( $goal, $slots, $user_id, $conversation ) {
        $parts = array();

        // 1. Topics data
        if ( function_exists( 'bz{prefix}_get_topics' ) ) {
            $topics = bz{prefix}_get_topics();
            $parts[] = '=== CHỦ ĐỀ CÓ SẴN ===' . "\n" . $this->format_topics( $topics );
        }

        // 2. Match user's chosen topic
        $focus = isset( $slots['question_focus'] ) ? $slots['question_focus'] : '';
        if ( $focus && function_exists( 'bz{prefix}_match_topic' ) ) {
            $matched = bz{prefix}_match_topic( $focus );
            if ( $matched ) {
                $parts[] = '=== CHỦ ĐỀ ĐƯỢC CHỌN ===' . "\n"
                         . 'Chủ đề: ' . $matched['value'] . "\n"
                         . 'Danh mục: ' . $matched['category'] . "\n"
                         . 'Câu hỏi gợi ý: ' . implode( ', ', $matched['questions'] );
            }
        }

        // 3. Page URL
        if ( function_exists( 'bz{prefix}_get_page_url' ) ) {
            $parts[] = '=== LINK {NAME} ===' . "\n" . bz{prefix}_get_page_url();
        }

        // 4. Knowledge RAG (auto-injected by Registry via get_knowledge_character_id())
        // → No manual call needed here. Registry calls build_knowledge_context() separately.

        return implode( "\n\n", array_filter( $parts ) );
    }

    /* ═══════════════════════════════════════════════
       SYSTEM INSTRUCTIONS — AI behavior guide
       ═══════════════════════════════════════════════ */

    public function get_system_instructions( $goal ) {
        return "Bạn là chuyên gia {domain} của BizCity.\n"
             . "Khi trả lời về {goal}, hãy:\n"
             . "1. Giới thiệu ngắn gọn về {domain}\n"
             . "2. Hướng dẫn user truy cập link để thực hiện\n"
             . "3. Phân tích kết quả dựa trên dữ liệu cung cấp\n"
             . "4. Đưa ra lời khuyên tích cực, xây dựng\n"
             . "5. Kết thúc bằng câu động viên\n"
             . "Giọng văn: Ấm áp, thân thiện, chuyên nghiệp.\n"
             . "Ngôn ngữ: Tiếng Việt, có thể dùng emoji.\n";
    }

    /* ═══════════════════════════════════════════════
       HELPERS (private)
       ═══════════════════════════════════════════════ */

    private function build_topic_choices() {
        $choices = array();
        if ( function_exists( 'bz{prefix}_get_topics' ) ) {
            foreach ( bz{prefix}_get_topics() as $topic ) {
                $key = $topic['value'];
                $choices[ $key ] = $topic['icon'] . ' ' . $topic['label'];
            }
        }
        return $choices;
    }

    private function format_topics( $topics ) {
        $lines = array();
        foreach ( $topics as $t ) {
            $lines[] = $t['icon'] . ' ' . $t['value'] . ' (' . $t['category'] . ')';
        }
        return implode( "\n", $lines );
    }
}
