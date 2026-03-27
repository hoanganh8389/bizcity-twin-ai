<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Emotional Memory — Stores emotional memory types (milestones, patterns, bond)
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
 * Memory types (bizcity_memory_users table):
 *   - emotional_milestone : memorable emotional moments
 *   - emotional_pattern   : recurring emotional tendencies
 *   - energizer           : things that energise the user
 *   - drainer             : things that drain / upset the user
 *   - bond_preference     : how the user likes to relate (formal/casual/…)
 *   - bond_score          : current relationship depth 1-10 (single row, updated)
 *
 * Lifecycle hooks:
 *   bizcity_chat_after_response  → auto_extract_from_turn()  (pri 10)
 *   bizcity_session_end          → end_of_session_sweep()    (pri 10)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Emotional_Memory {

    /* ── Singleton ─────────────────────────────────────────── */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ── Memory-type constants ──────────────────────────────── */
    const TYPE_MILESTONE   = 'emotional_milestone';
    const TYPE_PATTERN     = 'emotional_pattern';
    const TYPE_ENERGIZER   = 'energizer';
    const TYPE_DRAINER     = 'drainer';
    const TYPE_BOND_PREF   = 'bond_preference';
    const TYPE_BOND_SCORE  = 'bond_score';

    // Companion types we own (used for filtering / bulk-delete)
    const COMPANION_TYPES  = [
        'emotional_milestone',
        'emotional_pattern',
        'energizer',
        'drainer',
        'bond_preference',
        'bond_score',
    ];

    /* ── Thresholds ─────────────────────────────────────────── */
    const MIN_INTENSITY_TO_TRACK   = 2;   // 0-5 scale; only track if >= 2
    const DEFAULT_SCORE_EMOTIONAL  = 70;  // higher importance than extracted facts
    const DEFAULT_SCORE_BOND       = 80;

    /* ── Constructor: register hooks ───────────────────────── */
    private function __construct() {
        // Auto-extract after each AI response turn
        add_action( 'bizcity_chat_after_response', [ $this, 'auto_extract_from_turn' ],   10, 3 );

        // End-of-session sweep (if plugin fires this action)
        add_action( 'bizcity_session_end',         [ $this, 'end_of_session_sweep' ],      10, 2 );
    }

    /* ================================================================
     * SAVE — upsert a single emotional memory row
     *
     * @param array $args {
     *   user_id      int
     *   session_id   string
     *   memory_type  string  one of self::TYPE_*
     *   memory_key   string  unique slug
     *   memory_text  string  human-readable description
     *   score        int     0-100 importance
     *   metadata     array   extra JSON data (e.g. intensity, topic, valence)
     * }
     * @return bool
     * ================================================================ */
    public function save_emotional( $args = [] ) {
        if ( ! class_exists( 'BizCity_User_Memory' ) ) {
            return false;
        }

        $args = wp_parse_args( $args, [
            'user_id'     => 0,
            'session_id'  => '',
            'memory_type' => self::TYPE_PATTERN,
            'memory_key'  => '',
            'memory_text' => '',
            'score'       => self::DEFAULT_SCORE_EMOTIONAL,
            'metadata'    => [],
        ] );

        if ( empty( $args['memory_text'] ) ) {
            return false;
        }

        if ( empty( $args['memory_key'] ) ) {
            $args['memory_key'] = $args['memory_type'] . ':' . md5( mb_strtolower( trim( $args['memory_text'] ) ) );
        }

        return BizCity_User_Memory::instance()->upsert_public( [
            'user_id'     => (int) $args['user_id'],
            'session_id'  => (string) $args['session_id'],
            'memory_tier' => 'extracted',
            'memory_type' => $args['memory_type'],
            'memory_key'  => $args['memory_key'],
            'memory_text' => $args['memory_text'],
            'score'       => (int) $args['score'],
            'metadata'    => wp_json_encode( $args['metadata'] ),
        ] );
    }

    /* ================================================================
     * GET — fetch emotional memories for a user
     *
     * @param int    $user_id
     * @param string $session_id
     * @param string $type       empty = all companion types
     * @param int    $limit
     * @return array
     * ================================================================ */
    public function get_emotional( $user_id, $session_id = '', $type = '', $limit = 20 ) {
        if ( ! class_exists( 'BizCity_User_Memory' ) ) {
            return [];
        }

        $mem = BizCity_User_Memory::instance();

        if ( ! empty( $type ) ) {
            return $mem->get_memories( [
                'user_id'     => $user_id,
                'session_id'  => $session_id,
                'memory_type' => $type,
                'limit'       => $limit,
                'order_by'    => 'score',
            ] );
        }

        // Merge all companion types
        $results = [];
        foreach ( self::COMPANION_TYPES as $t ) {
            $rows = $mem->get_memories( [
                'user_id'     => $user_id,
                'session_id'  => $session_id,
                'memory_type' => $t,
                'limit'       => 10,
                'order_by'    => 'score',
            ] );
            foreach ( $rows as $row ) {
                $results[] = $row;
            }
        }

        // Sort by score desc
        usort( $results, function ( $a, $b ) {
            return (int) $b->score - (int) $a->score;
        } );

        return array_slice( $results, 0, $limit );
    }

    /* ================================================================
     * AUTO-EXTRACT — called after every AI response turn
     *
     * Fires on: bizcity_chat_after_response($user_message, $ai_response, $ctx)
     * Only extracts if emotional intensity detected >= MIN_INTENSITY_TO_TRACK.
     *
     * @param string $user_message
     * @param string $ai_response
     * @param array  $ctx  { user_id, session_id, mode, ... }
     * ================================================================ */
    public function auto_extract_from_turn( $user_message, $ai_response, $ctx = [] ) {
        $user_id    = intval( isset( $ctx['user_id'] ) ? $ctx['user_id'] : 0 );
        $session_id = isset( $ctx['session_id'] ) ? $ctx['session_id'] : '';

        if ( ! $user_id && empty( $session_id ) ) {
            return;
        }

        // Fast intensity screen — look for strong emotional signals before calling LLM
        $intensity = $this->estimate_intensity( $user_message );
        if ( $intensity < self::MIN_INTENSITY_TO_TRACK ) {
            return;
        }

        // Call LLM extractor
        $extractions = $this->extract_emotional_llm( $user_message, $ai_response, $ctx );
        if ( empty( $extractions ) ) {
            return;
        }

        foreach ( $extractions as $ex ) {
            if ( empty( $ex['type'] ) || empty( $ex['text'] ) ) {
                continue;
            }

            if ( ! in_array( $ex['type'], self::COMPANION_TYPES, true ) ) {
                continue;
            }

            $this->save_emotional( [
                'user_id'     => $user_id,
                'session_id'  => $session_id,
                'memory_type' => $ex['type'],
                'memory_key'  => isset( $ex['key'] ) ? $ex['key'] : '',
                'memory_text' => $ex['text'],
                'score'       => intval( isset( $ex['score'] ) ? $ex['score'] : self::DEFAULT_SCORE_EMOTIONAL ),
                'metadata'    => [
                    'intensity' => $intensity,
                    'valence'   => isset( $ex['valence'] ) ? $ex['valence'] : 'neutral',
                    'source'    => 'auto_extract',
                ],
            ] );
        }

        // Auto-adjust bond score after significant turn
        if ( $intensity >= 3 ) {
            $this->adjust_bond_score( $user_id, $session_id, $intensity >= 4 ? 1 : 0 );
        }

        // Auto-open emotional threads for significant milestones/patterns
        if ( $intensity >= 3 && class_exists( 'BizCity_Emotional_Thread_Tracker' ) ) {
            $tracker = BizCity_Emotional_Thread_Tracker::instance();
            foreach ( $extractions as $ex ) {
                if ( empty( $ex['type'] ) || empty( $ex['key'] ) ) {
                    continue;
                }
                // Only open threads for milestone/pattern with negative or neutral valence
                $threadable = array( 'emotional_milestone', 'emotional_pattern', 'drainer' );
                if ( ! in_array( $ex['type'], $threadable, true ) ) {
                    continue;
                }
                $valence = isset( $ex['valence'] ) ? $ex['valence'] : 'neutral';
                if ( $valence === 'pos' ) {
                    continue; // Positive emotions don't need follow-up threads
                }
                $tracker->open_thread( $user_id, $session_id, $ex['key'], array(
                    'intensity'       => $intensity,
                    'valence'         => $valence,
                    'description'     => isset( $ex['text'] ) ? $ex['text'] : $ex['key'],
                    'follow_up_hours' => $intensity >= 4 ? 24 : 48,
                ) );
            }
        }
    }

    /* ================================================================
     * END-OF-SESSION SWEEP — batch extract at session close
     *
     * Fires on: bizcity_session_end($user_id, $session_id)
     * ================================================================ */
    public function end_of_session_sweep( $user_id, $session_id ) {
        if ( ! class_exists( 'BizCity_User_Memory' ) ) {
            return;
        }
        // Delegate to existing build_from_history to extract standard memory types.
        // Emotional extraction overlaps — run only if we haven't run this session yet.
        $already_run = get_transient( 'bizcity_emotional_sweep_' . md5( $session_id ) );
        if ( $already_run ) {
            return;
        }

        BizCity_User_Memory::instance()->build_from_history( [
            'user_id'    => $user_id,
            'session_id' => $session_id,
            'limit'      => 50,
        ] );

        set_transient( 'bizcity_emotional_sweep_' . md5( $session_id ), 1, HOUR_IN_SECONDS * 2 );
    }

    /* ================================================================
     * BOND SCORE — get / update
     *
     * Bond score is stored as a single row:
     *   memory_type = 'bond_score', memory_key = 'bond_score:{user_id}'
     *   memory_text = "Bond depth: {score}/10 — {label}"
     *   score       = (int) bond * 10  (so DB score 0-100 maps to bond 0-10)
     *
     * @param int    $user_id
     * @param string $session_id
     * @return int  1-10
     * ================================================================ */
    public function get_bond_score( $user_id, $session_id = '' ) {
        if ( ! class_exists( 'BizCity_User_Memory' ) ) {
            return 1;
        }

        $rows = BizCity_User_Memory::instance()->get_memories( [
            'user_id'     => $user_id,
            'session_id'  => '',   // bond is global across sessions
            'memory_type' => self::TYPE_BOND_SCORE,
            'limit'       => 1,
        ] );

        if ( empty( $rows ) ) {
            return 1;
        }

        $raw = (int) $rows[0]->score;
        $bond = max( 1, min( 10, (int) round( $raw / 10 ) ) );
        return $bond;
    }

    /**
     * Adjust bond score by +delta (can be negative).
     * Clamped to 1-10.
     *
     * @param int    $user_id
     * @param string $session_id
     * @param int    $delta  default +1
     */
    public function adjust_bond_score( $user_id, $session_id = '', $delta = 1 ) {
        $current = $this->get_bond_score( $user_id );
        $new_bond = max( 1, min( 10, $current + $delta ) );
        $this->set_bond_score( $user_id, $session_id, $new_bond );
    }

    /**
     * Set bond score explicitly.
     *
     * @param int    $user_id
     * @param string $session_id
     * @param int    $bond  1-10
     */
    public function set_bond_score( $user_id, $session_id, $bond ) {
        $bond   = max( 1, min( 10, (int) $bond ) );
        $labels = [
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
        $label = isset( $labels[ $bond ] ) ? $labels[ $bond ] : 'Bạn bè';

        $this->save_emotional( [
            'user_id'     => $user_id,
            'session_id'  => '',  // global
            'memory_type' => self::TYPE_BOND_SCORE,
            'memory_key'  => 'bond_score:' . $user_id,
            'memory_text' => "Độ gắn kết: {$bond}/10 — {$label}",
            'score'       => $bond * 10,
            'metadata'    => [ 'bond' => $bond, 'label' => $label ],
        ] );
    }

    /* ================================================================
     * HELPERS
     * ================================================================ */

    /**
     * Fast keyword scan to estimate emotional intensity (0-5) without LLM.
     * Used as a quick gate before calling the LLM extractor.
     * Public so intent_stream can call it before building the system prompt
     * (needed to pass intensity + empathy_flag into bizcity_chat_system_prompt $args).
     *
     * @param string $text
     * @return int  0-5
     */
    public function estimate_intensity( $text ) {
        $result = $this->estimate_emotion( $text );
        return $result['intensity'];
    }

    /**
     * Structured emotion estimation — returns intensity + valence + emotion name.
     * Zero-cost keyword scan (no LLM). Call this instead of estimate_intensity()
     * when you need the full emotion struct.
     *
     * Handles negation ("không buồn"), amplifiers ("rất", "cực kỳ", "quá"),
     * and differentiates positive vs negative valence.
     *
     * @param string $text
     * @return array {
     *   intensity    int    0-5,
     *   valence      string 'pos'|'neg'|'neutral',
     *   emotion      string Named emotion (e.g. 'sad', 'happy', 'angry', 'anxious'),
     *   empathy_level string 'none'|'low'|'medium'|'high'|'critical'
     * }
     */
    public function estimate_emotion( $text ) {
        $text_lower = mb_strtolower( $text, 'UTF-8' );

        $default = array(
            'intensity'     => 1,
            'valence'       => 'neutral',
            'emotion'       => 'none',
            'empathy_level' => 'none',
        );

        // ── Negation patterns (check before main scan) ────────────
        $negations = array( 'không ', 'chẳng ', 'đâu có ', 'nào có ', 'không hề ', 'chưa ' );
        $has_negation = false;
        foreach ( $negations as $neg ) {
            if ( mb_strpos( $text_lower, $neg ) !== false ) {
                $has_negation = true;
                break;
            }
        }

        // ── Level 5 — extreme (critical) ────────────
        $l5_neg = array(
            'tuyệt vọng'           => 'despair',
            'suy sụp'              => 'collapse',
            'khóc'                 => 'crying',
            'không thể chịu được'  => 'unbearable',
            'muốn bỏ cuộc'         => 'give_up',
            'burnout'              => 'burnout',
            'kiệt sức hoàn toàn'   => 'exhausted',
            'muốn chết'            => 'self_harm',
            'không muốn sống'      => 'self_harm',
        );
        foreach ( $l5_neg as $kw => $emotion ) {
            if ( mb_strpos( $text_lower, $kw ) !== false ) {
                // Negated extreme emotions → moderate relief
                if ( $has_negation && mb_strpos( $text_lower, 'không ' . $kw ) !== false ) {
                    return array( 'intensity' => 3, 'valence' => 'pos', 'emotion' => 'relief', 'empathy_level' => 'medium' );
                }
                return array( 'intensity' => 5, 'valence' => 'neg', 'emotion' => $emotion, 'empathy_level' => 'critical' );
            }
        }

        // ── Level 4 — POSITIVE extreme ────────────
        $l4_pos = array(
            'cực kỳ vui'    => 'ecstatic',
            'hạnh phúc nhất' => 'happiest',
            'vui sướng'     => 'overjoyed',
            'phấn khích'    => 'thrilled',
            'tuyệt vời'    => 'wonderful',
        );
        foreach ( $l4_pos as $kw => $emotion ) {
            if ( mb_strpos( $text_lower, $kw ) !== false ) {
                if ( $has_negation && mb_strpos( $text_lower, 'không ' . $kw ) !== false ) {
                    return array( 'intensity' => 2, 'valence' => 'neg', 'emotion' => 'disappointed', 'empathy_level' => 'low' );
                }
                return array( 'intensity' => 4, 'valence' => 'pos', 'emotion' => $emotion, 'empathy_level' => 'medium' );
            }
        }

        // ── Level 4 — NEGATIVE extreme ────────────
        $l4_neg = array(
            'tức giận'       => 'angry',
            'rất buồn'       => 'very_sad',
            'lo lắng quá'    => 'anxious',
            'áp lực lớn'     => 'pressured',
            'sợ hãi'         => 'fearful',
            'giận dữ'        => 'furious',
            'thất vọng nặng' => 'deeply_disappointed',
        );
        foreach ( $l4_neg as $kw => $emotion ) {
            if ( mb_strpos( $text_lower, $kw ) !== false ) {
                if ( $has_negation && mb_strpos( $text_lower, 'không ' . $kw ) !== false ) {
                    return array( 'intensity' => 2, 'valence' => 'pos', 'emotion' => 'relief', 'empathy_level' => 'low' );
                }
                return array( 'intensity' => 4, 'valence' => 'neg', 'emotion' => $emotion, 'empathy_level' => 'high' );
            }
        }

        // ── Level 3 — POSITIVE moderate ────────────
        $l3_pos = array(
            'vui'       => 'happy',
            'hào hứng'  => 'excited',
            'thích'     => 'like',
            'xúc động'  => 'moved',
            'tự hào'    => 'proud',
            'hài lòng'  => 'satisfied',
            'phấn khởi' => 'enthusiastic',
        );
        // ── Level 3 — NEGATIVE moderate ────────────
        $l3_neg = array(
            'buồn'  => 'sad',
            'lo'    => 'worried',
            'tức'   => 'irritated',
            'stress' => 'stressed',
            'mệt'  => 'tired',
            'chán'  => 'bored',
            'ghét'  => 'hate',
            'ngại'  => 'hesitant',
            'thất vọng' => 'disappointed',
        );

        // Check positive L3 first
        foreach ( $l3_pos as $kw => $emotion ) {
            if ( mb_strpos( $text_lower, $kw ) !== false ) {
                if ( $has_negation && mb_strpos( $text_lower, 'không ' . $kw ) !== false ) {
                    return array( 'intensity' => 2, 'valence' => 'neg', 'emotion' => 'unhappy', 'empathy_level' => 'low' );
                }
                return array( 'intensity' => 3, 'valence' => 'pos', 'emotion' => $emotion, 'empathy_level' => 'low' );
            }
        }

        // Then negative L3
        foreach ( $l3_neg as $kw => $emotion ) {
            if ( mb_strpos( $text_lower, $kw ) !== false ) {
                if ( $has_negation && mb_strpos( $text_lower, 'không ' . $kw ) !== false ) {
                    return array( 'intensity' => 2, 'valence' => 'pos', 'emotion' => 'relief', 'empathy_level' => 'low' );
                }
                return array( 'intensity' => 3, 'valence' => 'neg', 'emotion' => $emotion, 'empathy_level' => 'medium' );
            }
        }

        // ── Level 2 — mild / neutral signals ────────────
        $l2 = array(
            'okay'        => 'neutral',
            'ổn'          => 'neutral',
            'bình thường' => 'neutral',
            'không sao'   => 'neutral',
            'tạm được'    => 'neutral',
            'hơi'         => 'neutral',
            'một chút'    => 'neutral',
        );
        foreach ( $l2 as $kw => $emotion ) {
            if ( mb_strpos( $text_lower, $kw ) !== false ) {
                return array( 'intensity' => 2, 'valence' => 'neutral', 'emotion' => $emotion, 'empathy_level' => 'low' );
            }
        }

        return $default;
    }

    /**
     * Extract emotional signals from a turn using LLM.
     * Returns array of { type, key, text, score, valence } objects.
     *
     * @param string $user_message
     * @param string $ai_response
     * @param array  $ctx
     * @return array
     */
    private function extract_emotional_llm( $user_message, $ai_response, $ctx = [] ) {
        if ( ! class_exists( 'BizCity_OpenRouter_API' ) && ! class_exists( 'BizCity_Chat_API' ) ) {
            return [];
        }

        $system_prompt = 'Bạn là AI phân tích cảm xúc. Trả về JSON array, mỗi object có:'
            . ' type (emotional_milestone|emotional_pattern|energizer|drainer|bond_preference),'
            . ' key (slug duy nhất), text (mô tả ngắn tiếng Việt), score (0-100), valence (pos|neg|neutral).'
            . ' Chỉ trả về JSON, không giải thích. Nếu không thấy tín hiệu cảm xúc đáng kể, trả về [].';

        $user_prompt = "## Tin nhắn user:\n{$user_message}\n\n## Phản hồi AI:\n{$ai_response}";

        try {
            $response = '';

            if ( class_exists( 'BizCity_OpenRouter_API' ) ) {
                $api      = BizCity_OpenRouter_API::instance();
                $response = $api->chat_completion( [
                    [ 'role' => 'system', 'content' => $system_prompt ],
                    [ 'role' => 'user',   'content' => $user_prompt ],
                ], [
                    'model'       => 'google/gemini-flash-1.5-8b',
                    'max_tokens'  => 400,
                    'temperature' => 0.1,
                ] );
            } elseif ( class_exists( 'BizCity_Chat_API' ) ) {
                $api      = BizCity_Chat_API::instance();
                $response = $api->complete( $system_prompt, $user_prompt, [ 'max_tokens' => 400, 'temperature' => 0.1 ] );
            }

            if ( empty( $response ) ) {
                return [];
            }

            // Strip possible markdown code fences
            $json   = preg_replace( '/^```[a-z]*\s*/i', '', trim( $response ) );
            $json   = preg_replace( '/\s*```$/i', '', $json );
            $parsed = json_decode( $json, true );

            if ( ! is_array( $parsed ) ) {
                return [];
            }

            return $parsed;

        } catch ( Exception $e ) {
            error_log( '[BizCity_Emotional_Memory] LLM extract error: ' . $e->getMessage() );
            return [];
        }
    }
}
