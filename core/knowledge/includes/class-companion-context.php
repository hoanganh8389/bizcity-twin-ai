<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Companion Context — Layer 1.7: Relationship Context Injection
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @version    1.0.0
 * @since      2026-03-03
 *
 * Injects Relationship Context into the system prompt at priority 97,
 * sitting between BizCoach Context (95) and User Memory (99).
 *
 * What it injects:
 *   - Bond Depth Score (1-10) and label
 *   - Preferred xưng hô (bond_preference memories)
 *   - Open / Recurring emotional threads (with follow-up flag)
 *   - Recent emotional milestones & energisers (positive reinforcement cues)
 *   - Companion tone directive based on bond tier
 *
 * Hooks:
 *   bizcity_chat_system_prompt  pri 97  (2 args: $prompt, $args)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Companion_Context {

    /* ── Singleton ─────────────────────────────────────────── */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'bizcity_chat_system_prompt', [ $this, 'inject_companion_context' ], 97, 2 );
    }

    /* ================================================================
     * MAIN FILTER — inject relationship context at Layer 1.7
     *
     * @param string $prompt
     * @param array  $args  { user_id, session_id, mode, ... }
     * @return string
     * ================================================================ */
    public function inject_companion_context( $prompt, $args = [] ) {
        $t0 = microtime( true );

        // ── Twin Focus Gate: skip companion when mode doesn't need it ──
        if ( class_exists( 'BizCity_Focus_Gate' ) && ! BizCity_Focus_Gate::should_inject( 'companion' ) ) {
            if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                BizCity_Twin_Trace::layer( 'companion', false, round( ( microtime( true ) - $t0 ) * 1000, 2 ) );
            }
            return $prompt;
        }

        $user_id    = intval( isset( $args['user_id'] ) ? $args['user_id'] : get_current_user_id() );
        $session_id = isset( $args['session_id'] ) ? $args['session_id'] : '';

        if ( ! $user_id && empty( $session_id ) ) {
            if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                BizCity_Twin_Trace::layer( 'companion', false, round( ( microtime( true ) - $t0 ) * 1000, 2 ) );
            }
            return $prompt;
        }

        $ctx = $this->build_relationship_context( $user_id, $session_id, $args );
        if ( empty( $ctx ) ) {
            if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                BizCity_Twin_Trace::layer( 'companion', false, round( ( microtime( true ) - $t0 ) * 1000, 2 ) );
            }
            return $prompt;
        }

        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::layer( 'companion', true, round( ( microtime( true ) - $t0 ) * 1000, 2 ), mb_strlen( $ctx, 'UTF-8' ) );
        }

        return $prompt . $ctx;
    }

    /* ================================================================
     * BUILD RELATIONSHIP CONTEXT
     *
     * @param int    $user_id
     * @param string $session_id
     * @return string  Formatted context block or ''
     * ================================================================ */
    public function build_relationship_context( $user_id, $session_id = '', $args = [] ) {
        $parts = [];

        /* ── Current emotional state (from filter args) ── */
        $cur_valence = isset( $args['valence'] ) ? $args['valence'] : 'neutral';
        $cur_emotion = isset( $args['emotion'] ) ? $args['emotion'] : 'none';
        $cur_empathy_level = isset( $args['empathy_level'] ) ? $args['empathy_level'] : 'none';

        if ( $cur_emotion !== 'none' ) {
            $valence_vi = $cur_valence === 'pos' ? 'tích cực' : ( $cur_valence === 'neg' ? 'tiêu cực' : 'trung tính' );
            $parts[] = "Cảm xúc hiện tại: **{$cur_emotion}** ({$valence_vi}), mức empathy: {$cur_empathy_level}";
        }

        /* ── Bond score ── */
        $bond = 1;
        if ( class_exists( 'BizCity_Emotional_Memory' ) ) {
            $bond = BizCity_Emotional_Memory::instance()->get_bond_score( $user_id );
        }

        $bond_label = $this->bond_label( $bond );
        $parts[]    = "Độ gắn kết: **{$bond}/10** ({$bond_label})";

        /* ── Bond preferences (xưng hô) ── */
        if ( class_exists( 'BizCity_Emotional_Memory' ) ) {
            $prefs = BizCity_Emotional_Memory::instance()->get_emotional(
                $user_id, '', BizCity_Emotional_Memory::TYPE_BOND_PREF, 3
            );
            if ( ! empty( $prefs ) ) {
                $pref_lines = [];
                foreach ( $prefs as $p ) {
                    $pref_lines[] = '- ' . $p->memory_text;
                }
                $parts[] = "Sở thích quan hệ:\n" . implode( "\n", $pref_lines );
            }
        }

        /* ── Tone directive by bond tier ── */
        $tone    = $this->bond_tone_directive( $bond );
        $parts[] = "Giọng điệu phù hợp: {$tone}";

        /* ── Open emotional threads ── */
        if ( class_exists( 'BizCity_Emotional_Thread_Tracker' ) ) {
            $tracker = BizCity_Emotional_Thread_Tracker::instance();
            $threads = $tracker->get_open_threads( $user_id, 4 );
            $due     = $tracker->get_followup_due( $user_id );

            if ( ! empty( $threads ) ) {
                $thread_lines = [];
                foreach ( $threads as $t ) {
                    $flag  = '';
                    $t_topic = isset( $t['topic'] ) ? $t['topic'] : '';
                    if ( empty( $t_topic ) ) {
                        continue;
                    }
                    // Check if this thread is due for follow-up
                    foreach ( $due as $d ) {
                        $d_topic = isset( $d['topic'] ) ? $d['topic'] : '';
                        if ( ! empty( $d_topic ) && $d_topic === $t_topic ) {
                            $flag = ' ← [CẦN HỎI THĂM]';
                            break;
                        }
                    }
                    $t_status = isset( $t['status'] ) ? $t['status'] : 'open';
                    $status_vi = $t_status === 'recurring' ? 'tái diễn' : 'đang mở';
                    $t_desc = isset( $t['description'] ) ? $t['description'] : $t_topic;
                    $thread_lines[] = "- [{$status_vi}] {$t_topic}: {$t_desc}{$flag}";
                }
                $parts[] = "Threads cảm xúc đang mở:\n" . implode( "\n", $thread_lines );

                if ( ! empty( $due ) ) {
                    $parts[] = "⚠️ Có " . count( $due ) . " thread cần hỏi thăm — hãy tự nhiên nhắc đến nếu phù hợp với ngữ cảnh.";
                }
            }
        }

        /* ── Recent emotional milestones ── */
        if ( class_exists( 'BizCity_Emotional_Memory' ) ) {
            $milestones = BizCity_Emotional_Memory::instance()->get_emotional(
                $user_id, '', BizCity_Emotional_Memory::TYPE_MILESTONE, 3
            );
            if ( ! empty( $milestones ) ) {
                $m_lines = [];
                foreach ( $milestones as $m ) {
                    $m_lines[] = '- ' . $m->memory_text;
                }
                $parts[] = "Khoảnh khắc đáng nhớ:\n" . implode( "\n", $m_lines );
            }
        }

        if ( empty( $parts ) ) {
            return '';
        }

        $output  = "\n\n---\n\n";
        $output .= "## 💛 RELATIONSHIP CONTEXT — LAYER 1.7 (Priority 97)\n\n";
        $output .= implode( "\n\n", $parts );
        $output .= "\n\n";
        $output .= "**Hướng dẫn**: Sử dụng thông tin trên để điều chỉnh *tone* và *kết nối cảm xúc* trong phản hồi. ";
        $output .= "Không liệt kê dữ liệu này trực tiếp ra — hãy để nó ảnh hưởng tự nhiên vào cách bạn nói chuyện.\n";

        return $output;
    }

    /* ================================================================
     * HELPERS
     * ================================================================ */

    /**
     * Bond tier label in Vietnamese.
     *
     * @param int $bond  1-10
     * @return string
     */
    private function bond_label( $bond ) {
        $map = [
            1  => 'Mới gặp',
            2  => 'Quen mặt',
            3  => 'Thân thiện',
            4  => 'Bạn bè',
            5  => 'Khá thân',
            6  => 'Thân thiết',
            7  => 'Rất thân',
            8  => 'Bạn thân',
            9  => 'Tâm giao',
            10 => 'Tri kỷ',
        ];
        $bkey = max( 1, min( 10, (int) $bond ) );
        return isset( $map[ $bkey ] ) ? $map[ $bkey ] : 'Bạn bè';
    }

    /**
     * Tone directive string based on bond score tier.
     *
     * Tier 1-2 : lịch sự, giữ khoảng cách vừa phải
     * Tier 3-4 : thân thiện, gần gũi, nhẹ nhàng
     * Tier 5-6 : cởi mở, hài hước nhẹ, có thể đùa bỡn vừa phải
     * Tier 7-8 : thân mật, dùng ngôn ngữ bạn bè, bày tỏ quan tâm thật sự
     * Tier 9-10: tri kỷ, hoàn toàn tự nhiên, có thể chia sẻ cảm nhận của AI
     *
     * @param int $bond
     * @return string
     */
    private function bond_tone_directive( $bond ) {
        if ( $bond <= 2 ) {
            return 'Lịch sự, chuyên nghiệp, giữ khoảng cách vừa phải. Không quá thân mật.';
        }
        if ( $bond <= 4 ) {
            return 'Thân thiện, nhẹ nhàng, gần gũi. Có thể thêm quan tâm nhỏ.';
        }
        if ( $bond <= 6 ) {
            return 'Cởi mở, vui vẻ, có thể dùng ngôn ngữ bạn bè. Thỉnh thoảng đùa nhẹ nếu phù hợp.';
        }
        if ( $bond <= 8 ) {
            return 'Thân mật, bày tỏ quan tâm thật sự, dùng ngôn ngữ tự nhiên như bạn thân.';
        }
        return 'Tri kỷ — hoàn toàn tự nhiên, có thể chia sẻ cảm nhận, suy nghĩ của AI. Đặt câu hỏi sâu hơn. ⛔ DÙ THÂN MẬT, bạn vẫn là AI Trợ lý — KHÔNG BAO GIỜ tự xưng bằng tên user hay nói như thể bạn LÀ user.';
    }
}
