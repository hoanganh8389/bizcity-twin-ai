<?php
/**
 * BizCity Intent — Meta Mode Classifier (Tầng 1) — LLM-First v3
 *
 * Phân loại tin nhắn vào 1 trong 4 nhóm (mode) trước khi đi vào pipeline:
 *
 *   1. emotion    — Tâm sự, cảm xúc (Empathy Mode)
 *   2. reflection — Kể chuyện, chia sẻ trải nghiệm (Reflective Mode)
 *   3. knowledge  — Hỏi đáp thông tin, tìm hiểu (Knowledge Mode)
 *   4. execution  — Thực thi hành động cụ thể (Executor Mode)
 *
 * LLM-First approach (v3 — 2026-03):
 *   - Context checks (WAITING_USER, short msg) → early return (0 cost)
 *   - Memory detection: regex + LLM fast check
 *   - LLM classification bằng natural language (fast/cheap model)
 *   - Pattern fallback CHỈ khi LLM unavailable/fail
 *   - Confidence threshold: < 0.6 → fallback = ambiguous (safe mode)
 *
 * @package BizCity_Intent
 * @since   3.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Mode_Classifier {

    /** @var self|null */
    private static $instance = null;

    /** Valid modes */
    const MODE_EMOTION    = 'emotion';
    const MODE_REFLECTION = 'reflection';
    const MODE_KNOWLEDGE  = 'knowledge';
    const MODE_EXECUTION  = 'execution';
    const MODE_AMBIGUOUS  = 'ambiguous';

    /** Confidence threshold — below this → ambiguous mode */
    const CONFIDENCE_THRESHOLD = 0.6;

    /** All valid modes — 5 active: emotion, reflection, knowledge, execution, ambiguous */
    const VALID_MODES = [
        self::MODE_EMOTION,
        self::MODE_REFLECTION,
        self::MODE_KNOWLEDGE,
        self::MODE_EXECUTION,
        self::MODE_AMBIGUOUS,
    ];

    /**
     * Memory request patterns — user asking to learn/remember something.
     * (Kept as regex because memory detection must be fast & zero-cost)
     * @var array
     */
    private $memory_patterns = [];

    /** @var int  Per-session KCI ratio, set by Chat Gateway before classify(). */
    private static $kci_ratio = 80;

    /** @var bool  True when @mention or /command overrides KCI=100 for this request. */
    private static $mention_override = false;

    /**
     * Set KCI ratio for current request (called by Chat Gateway).
     * @param int $ratio 0-100 (0=full execution, 100=knowledge-only)
     */
    public static function set_kci_ratio( int $ratio ): void {
        self::$kci_ratio = max( 0, min( 100, $ratio ) );
        error_log( '[KCI-TRACE] classifier: kci=' . self::$kci_ratio . ', exec_ratio=' . ( 100 - self::$kci_ratio ) );
    }

    /**
     * Set mention override flag (called by Chat Gateway when @/ detected at KCI=100).
     */
    public static function set_mention_override( bool $override ): void {
        self::$mention_override = $override;
    }

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init_patterns();
    }

    /**
     * Initialize patterns — only memory patterns kept.
     * Mode classification is now fully LLM-based (v3).
     */
    private function init_patterns() {
        // ── Memory patterns (kept: fast regex, 0 cost, specialized use) ──
        $this->memory_patterns = [
            // Explicit memory commands
            '/(nhớ|ghi\s+nhớ|remember|lưu|save|học|learn|memorize)\s+(rằng|là|điều\s+này|cái\s+này|thông\s+tin|cho\s+tôi|cho\s+em|giúp)/ui' => 0.88,
            '/(hãy\s+nhớ|hãy\s+ghi\s+nhớ|hãy\s+lưu|hãy\s+học)/ui' => 0.90,
            '/(ghi\s+nhớ\s+giúp|lưu\s+giúp|nhớ\s+giúp)/ui' => 0.88,
            '/(nhớ\s+nhé|nhớ\s+nha|nhớ\s+cho|nhớ\s+hen|nhớ\s+nghen)/ui' => 0.82,
            // Communication preferences
            '/(hãy\s+)(xưng|gọi|dùng)\s+(hô|tôi|em|anh|chị|mình|là|bằng)/ui' => 0.88,
            '/(gọi\s+tôi|gọi\s+em|gọi\s+anh|gọi\s+chị|gọi\s+mình)\s+(là|bằng)/ui' => 0.88,
            '/(xưng\s+hô)\s+(anh\s+em|chị\s+em|bạn\s+bè|mày\s+tao|anh|em|chị|mình)/ui' => 0.88,
            // Self-introduction
            '/(tên\s+tôi|tên\s+em|tên\s+mình|tên\s+anh)\s+(là|:)\s*/ui' => 0.88,
            '/(tôi|em|mình|anh)\s+(tên\s+là|tên)\s+/ui' => 0.85,
            '/(tôi|em|mình)\s+\d+\s*tuổi/ui' => 0.85,
            '/(sinh\s+nhật|ngày\s+sinh|năm\s+sinh)\s+(tôi|em|mình|của)/ui' => 0.85,
            // Temporal preferences
            '/(từ\s+giờ|từ\s+nay|từ\s+bây\s+giờ|từ\s+đây)\s+/ui' => 0.82,
            '/^luôn\s+(luôn\s+)?/ui' => 0.78,
            // Style preferences
            '/(hãy\s+)(trả\s+lời|nói|viết|chat|phản\s+hồi)\s+(ngắn|dài|chi\s+tiết|đơn\s+giản|chuyên\s+nghiệp|vui|hài|nghiêm|formal|bằng)/ui' => 0.85,
            '/(trả\s+lời|nói|viết|chat|dùng)\s+(bằng\s+)?(tiếng\s+)(Anh|Việt|Nhật|Hàn|Trung|Pháp|Đức|Tây\s+Ban\s+Nha)/ui' => 0.88,
            '/(tôi|em|mình)\s+(muốn|cần|mong)\s+(bạn|bot|AI|anh|chị)\s+(nhớ|ghi|lưu|gọi|xưng|nói|trả\s+lời)/ui' => 0.85,

            // ── Implicit preference / response-rule patterns (§29) ──
            '/(hãy|bạn\s+cần|bạn\s+phải|bạn\s+nên)\s+(trả\s+lời|phản\s+hồi|đưa\s+ra|giải\s+thích|viết)\s+/ui' => 0.82,
            '/(từ\s+giờ|luôn\s+luôn|mỗi\s+lần|bao\s+giờ\s+cũng)\s+(hãy|phải|nên|cần)/ui' => 0.85,
            '/(đừng|không\s+được|cấm|đừng\s+bao\s+giờ)\s+(gọi|nói|viết|trả\s+lời|dùng)/ui' => 0.85,
            '/(khi\s+tôi|nếu\s+tôi)\s+(hỏi|yêu\s+cầu).{3,},?\s*(hãy|thì)/ui' => 0.80,
            '/(format|định\s+dạng|output)\s+(dạng|kiểu|json|bảng|bullet)/ui' => 0.82,
        ];

        $this->memory_patterns = apply_filters( 'bizcity_mode_memory_patterns', $this->memory_patterns );
    }

    /* ================================================================
     * Main classification method
     *
     * @param string $message       User's message text.
     * @param array  $conversation  Active conversation data (for context).
     * @param array  $attachments   Images/files attached.
     * @return array {
     *   @type string  $mode        One of the 6 modes.
     *   @type float   $confidence  0.0 - 1.0
     *   @type string  $method      'pattern' | 'llm' | 'fallback'
     *   @type bool    $is_memory   True if user is requesting to learn/remember something.
     *   @type array   $meta        Additional metadata.
     * }
     * ================================================================ */
    public function classify( $message, $conversation = null, $attachments = [] ) {
        $result = [
            'mode'       => self::MODE_AMBIGUOUS, // Default when uncertain
            'confidence' => 0.5,
            'method'     => 'default',
            'is_memory'  => false,
            'meta'       => [],
        ];

        $message_lower = mb_strtolower( trim( $message ), 'UTF-8' );
        $msg_length    = mb_strlen( $message_lower, 'UTF-8' );

        // ── Quick check: is this a memory/learn request? ──
        // Phase 1: Regex patterns (fast, free)
        $result['is_memory'] = $this->is_memory_request( $message_lower );

        // Phase 2: Unified LLM handles remaining memory detection (v3.5.2)
        // The unified LLM prompt (BƯỚC 3) includes is_memory classification,
        // so the separate might_be_memory() heuristic + check_memory_with_llm()
        // calls are no longer needed. Memory will be detected by the unified LLM
        // which runs later in this method.

        // ── Quick check: if there are file attachments with learn intent → memory ──
        if ( ! empty( $attachments ) && $result['is_memory'] ) {
            $result['mode']       = self::MODE_KNOWLEDGE;
            $result['confidence'] = 0.90;
            $result['method']     = 'pattern';
            return $result;
        }

        // ── Quick check: if conversation is in WAITING_USER for execution → stay execution ──
        // BUT: if is_memory=true → don't hijack; let memory pipeline handle it
        // GUARD: if message is empty (image-only) and NOT specifically waiting for image → don't force execution
        //        because the user may just be sending an image casually, not answering a text slot.
        $is_waiting_for_image = ( $conversation['waiting_for'] ?? '' ) === 'image';
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[MODE-CLASSIFIER] WAITING_USER check: has_conv=' . ( $conversation ? 'Y' : 'N' )
                     . ' | goal=' . ( $conversation['goal'] ?? '' )
                     . ' | status=' . ( $conversation['status'] ?? '' )
                     . ' | waiting_for=' . ( $conversation['waiting_for'] ?? '' )
                     . ' | is_memory=' . ( $result['is_memory'] ? 'Y' : 'N' )
                     . ' | msg=' . mb_substr( $message, 0, 50, 'UTF-8' ) );
        }
        if ( $conversation
             && ! empty( $conversation['goal'] )
             && ( $conversation['status'] ?? '' ) === 'WAITING_USER'
             && ! $result['is_memory']
             && ( $msg_length > 0 || $is_waiting_for_image ) ) {

            // ── v3.6.4 → v3.6.5: New-goal escape — conservative regex check ──
            // If user says "Tạo sản phẩm" while write_article is WAITING_USER,
            // don't force provide_input — let it route to create_product instead.
            //
            // v3.6.5 FIX: The regex escape was TOO aggressive. When the user
            // provides a topic like "Dinh dưỡng quan trọng hơn thuốc" as input
            // for write_article, the word "dinh dưỡng" matched the calo_suggest
            // pattern → incorrectly escaped to calo agent.
            //
            // NEW RULE: Regex escape only triggers when the message also contains
            // an ACTION VERB (command word like tạo/xem/ghi/gợi ý/tra/đăng/viết...)
            // This distinguishes "gợi ý bữa ăn dinh dưỡng" (new command)
            // from "dinh dưỡng quan trọng hơn thuốc" (topic input).
            $different_goal  = null;
            $raw_regex_match = null; // v3.8.2: track raw match for knowledge-escape
            if ( $msg_length > 2 && class_exists( 'BizCity_Intent_Router' ) ) {
                $raw_regex_match = $this->match_goal_by_regex( $message_lower );
                $different_goal  = $raw_regex_match;
                if ( $different_goal && $different_goal['goal'] === $conversation['goal'] ) {
                    $different_goal = null; // Same goal → still provide_input
                }

                // v3.6.5 → v3.6.6: Action-verb guard for new-goal escape during WAITING_USER.
                // Pure topic text (e.g. "dinh dưỡng quan trọng hơn thuốc") should
                // NOT trigger escape — it's likely the user answering the current slot.
                //
                // v3.6.6 FIX: Cross-provider bypass. When the matched goal belongs to
                // a DIFFERENT provider than the current goal (e.g. tarot_interpret vs
                // view_transit_forecast), the pattern match alone is a strong signal.
                // Action-verb guard only applies within the SAME provider (vocabulary overlap).
                if ( $different_goal ) {
                    $cross_provider = false;
                    if ( class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
                        $registry    = BizCity_Intent_Provider_Registry::instance();
                        $cur_owner   = $registry->get_provider_for_goal( $conversation['goal'] );
                        $match_owner = $registry->get_provider_for_goal( $different_goal['goal'] );
                        $cur_pid     = $cur_owner ? $cur_owner->get_id() : '';
                        $match_pid   = $match_owner ? $match_owner->get_id() : '';
                        $cross_provider = ( $cur_pid && $match_pid && $cur_pid !== $match_pid );
                    }

                    if ( ! $cross_provider ) {
                        // Same provider or unknown → require action verb (strict guard)
                        $has_action_verb = preg_match(
                            '/^(t[ạa]o|xem|ghi|tra|[đd][ăa]ng|vi[ếe]t|g[ợo]i\s*[ýy]|ch[ụu]p|t[ìi]m|h[ỏo]i|l[àa]m|xo[áa]|s[ửu]a|c[ậa]p\s*nh[ậa]t|th[ốo]ng\s*k[êe]|b[áa]o\s*c[áa]o|log|post|create|search|delete|update|check|xem|m[ởo]|show|list|n[êe]n\s*[aă]n|[aă]n\s*g[ìi])\b/ui',
                            $message_lower
                        );
                        if ( ! $has_action_verb ) {
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( '[MODE-CLASSIFIER] WAITING_USER regex escape BLOCKED — no action verb in "'
                                         . mb_substr( $message, 0, 80, 'UTF-8' ) . '" (matched '
                                         . $different_goal['goal'] . ' but likely topic input for '
                                         . $conversation['goal'] . ')' );
                            }
                            $different_goal = null; // Block escape — treat as provide_input
                        }
                    } else {
                        // Cross-provider match → allow escape without action verb
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[MODE-CLASSIFIER] WAITING_USER cross-provider escape ALLOWED — '
                                     . $different_goal['goal'] . ' (provider: ' . $match_pid . ') vs current '
                                     . $conversation['goal'] . ' (provider: ' . $cur_pid . ')' );
                        }
                    }
                }
            }

            if ( $different_goal ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[MODE-CLASSIFIER] → EXECUTION (WAITING_USER new_goal escape: '
                             . $conversation['goal'] . ' → ' . $different_goal['goal'] . ')' );
                }
                $result['mode']       = self::MODE_EXECUTION;
                $result['confidence'] = 0.95;
                $result['method']     = 'regex_goal';
                $result['meta']['intent_result'] = [
                    'intent'       => 'new_goal',
                    'goal'         => $different_goal['goal'],
                    'goal_label'   => $different_goal['label'],
                    'entities'     => [],
                    'filled_slots' => [],
                    'missing_slots'=> $different_goal['extract'] ?? [],
                    'confidence'   => 0.95,
                ];
                return $result;
            }

            // ── v3.8.2: Knowledge-inquiry escape from WAITING_USER ──
            // When NO goal regex matched at all (raw_regex_match is null), the
            // message isn't related to ANY execution goal. If it also looks like
            // a knowledge/inquiry request (long + contains question/learning
            // patterns), the user is ignoring the WAITING_USER prompt and asking
            // something completely new. Don't blindly force provide_input —
            // fall through to LLM classification so it can route correctly
            // (e.g. knowledge mode for "tìm hiểu lịch sử việt nam").
            $skip_provide_input = false;
            if ( ! $raw_regex_match && $msg_length > 15 ) {
                $is_knowledge_inquiry = preg_match(
                    '/(?:^(?:tìm\s*hiểu|cho\s*(?:mình\s*)?biết|tra\s*cứu|hỏi\s*(?:về|gì|thử)|nói\s*(?:về|cho)|kể\s*(?:về|cho)|giải\s*thích\s*(?:về|cho|giúp|giùm))|(?:là\s*(?:gì|sao)|thế\s*nào|như\s*nào|tại\s*sao|vì\s*sao|bao\s*nhiêu)\s*(?:\?|nhé|nhỉ|vậy|nào|$)|giúp\s*(?:mình|tôi|em)\s*(?:tìm|hỏi|tra|xem|biết|hiểu))/ui',
                    $message_lower
                );
                if ( $is_knowledge_inquiry ) {
                    $skip_provide_input = true;
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[MODE-CLASSIFIER] WAITING_USER knowledge-escape: "'
                                 . mb_substr( $message, 0, 60, 'UTF-8' )
                                 . '" → no goal regex + inquiry signal → fall through to LLM' );
                    }
                }
            }

            // ── v4.7.1: Off-topic / greeting escape from WAITING_USER ──
            // Short greetings ("hi", "chào"), meta-questions ("chức năng này là gì?",
            // "cái này làm gì?"), or clearly unrelated messages should NOT be
            // force-fed into the current slot. Instead, let LLM classify so the
            // system can respond naturally before resuming the HIL flow.
            if ( ! $skip_provide_input && ! $raw_regex_match ) {
                $is_off_topic = preg_match(
                    '/^(hi|hello|hey|chào|xin chào|alo|ê|ơi|bạn ơi)\s*[!?.]*$/ui',
                    $message_lower
                );
                if ( ! $is_off_topic ) {
                    $is_off_topic = preg_match(
                        '/(?:chức năng|tính năng|cái này|nó|tool|công cụ)\s*(?:này\s*)?(?:là gì|làm gì|có tác dụng gì|hoạt động|dùng để|để làm gì|giúp gì)/ui',
                        $message_lower
                    );
                }
                if ( $is_off_topic ) {
                    $skip_provide_input = true;
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[MODE-CLASSIFIER] WAITING_USER off-topic-escape: "'
                                 . mb_substr( $message, 0, 60, 'UTF-8' )
                                 . '" → greeting/meta-question → fall through to LLM' );
                    }
                }
            }

            if ( ! $skip_provide_input ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[MODE-CLASSIFIER] → EXECUTION (WAITING_USER override)' );
                }
                $result['mode']       = self::MODE_EXECUTION;
                $result['confidence'] = 0.90;
                $result['method']     = 'context';

                // ── Pre-populate intent_result for Router Step 0.5 (v3.5.1) ──
                // When WAITING_USER, user's message is input for the current goal.
                // Pass provide_input so Router skips its LLM call entirely,
                // preventing mis-classification to a different goal (e.g. ask_chatgpt).
                $result['meta']['intent_result'] = [
                    'intent'     => 'provide_input',
                    'goal'       => $conversation['goal'],
                    'goal_label' => $conversation['goal_label'] ?? $conversation['goal'],
                    'entities'   => [],
                    'confidence' => 0.92,
                ];

                return $result;
            }

            // v3.8.2: Knowledge-inquiry detected → fall through to LLM classification below
        }

        // ── v3.5.2: Removed msg_length < 5 auto-knowledge shortcut.
        // Short messages like "ok", "dc", "rồi" can be valid provide_input
        // responses when conversation is in WAITING_USER. Let LLM classify.

        // ── v3.6.4 → v3.8.0: Regex goal-pattern pre-check — BIAS HINT (not bypass) ──
        // If the message matches a registered goal pattern, DON'T bypass LLM.
        // Instead, pass the likely_goal as a HINT to the unified LLM call.
        // This ensures entity extraction happens in the same call.
        $regex_likely_goal = null;
        if ( $msg_length > 0 && class_exists( 'BizCity_Intent_Router' ) ) {
            $regex_match = $this->match_goal_by_regex( $message_lower );
            if ( $regex_match ) {
                $regex_likely_goal = $regex_match;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[MODE-CLASSIFIER] regex pre-match → bias hint: ' . $regex_match['goal'] );
                }
            }
        }

        // ── LLM-First classification (v3) ──
        // LLM handles ALL mode classification — no regex pattern scoring.
        // Context (active goal, conversation status) is passed to LLM for better understanding.
        if ( $msg_length > 0 ) {

            // ── Cache lookup (v3.4) — skip LLM if identical message+context was classified before ──
            if ( class_exists( 'BizCity_Intent_Classify_Cache' ) ) {
                $cached_mode = BizCity_Intent_Classify_Cache::instance()->get_mode( $message, $conversation );
                if ( $cached_mode && $cached_mode['confidence'] >= self::CONFIDENCE_THRESHOLD ) {
                    $result['mode']       = $cached_mode['mode'];
                    $result['confidence'] = $cached_mode['confidence'];
                    $result['method']     = 'cache';
                    $result['meta']       = [ 'cache_hit' => true ];

                    if ( ! $result['is_memory'] && ! empty( $cached_mode['is_memory'] ) ) {
                        $result['is_memory'] = true;
                        $result['meta']['memory_method'] = 'cache';
                    }

                    if ( $result['confidence'] < self::CONFIDENCE_THRESHOLD ) {
                        $result['mode']                  = self::MODE_AMBIGUOUS;
                        $result['meta']['original_mode']  = $cached_mode['mode'];
                        $result['meta']['fallback']       = true;
                    }

                    // ── v3.8.2: Regex override for cached non-execution results ──
                    // Stale cache entries (from before schema/pattern fixes) may have
                    // stored mode=knowledge for messages that regex now matches as
                    // execution goals. Apply the same regex-authoritative override.
                    if ( $regex_likely_goal && $result['mode'] !== self::MODE_EXECUTION ) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[MODE-CLASSIFIER] v3.8.2 cache-override: cached mode='
                                     . $result['mode'] . ' → execution, goal=' . $regex_likely_goal['goal'] );
                        }
                        $result['mode']       = self::MODE_EXECUTION;
                        $result['confidence'] = max( $result['confidence'], 0.90 );
                        $result['method']     = 'cache+regex_override';
                        $result['meta']['intent_result'] = [
                            'intent'        => 'new_goal',
                            'goal'          => $regex_likely_goal['goal'],
                            'goal_label'    => $regex_likely_goal['label'],
                            'entities'      => [],
                            'filled_slots'  => [],
                            'missing_slots' => $regex_likely_goal['extract'] ?? [],
                            'confidence'    => 0.93,
                            '_regex_override' => true,
                            '_cache_override' => true,
                        ];
                    }

                    return $result;
                }
            }

            do_action( 'bizcity_intent_status', '💡 Đang xác định loại hội thoại...' );
            $llm_result = $this->classify_with_llm( $message, $conversation, $regex_likely_goal );
            if ( $llm_result ) {
                $result['mode']       = $llm_result['mode'];
                $result['confidence'] = $llm_result['confidence'];
                $result['method']     = 'llm';
                $result['meta']       = $llm_result['meta'] ?? [];

                // If LLM detected memory that regex missed → set is_memory
                if ( ! $result['is_memory'] && ! empty( $llm_result['is_memory'] ) ) {
                    $result['is_memory'] = true;
                    $result['meta']['memory_method'] = 'llm_combined';
                }

                // If confidence below threshold → fallback to ambiguous (safe)
                if ( $result['confidence'] < self::CONFIDENCE_THRESHOLD ) {
                    $result['mode']                  = self::MODE_AMBIGUOUS;
                    $result['meta']['original_mode']  = $llm_result['mode'];
                    $result['meta']['fallback']       = true;
                }

                // ── Pass through intent pre-classification (unified LLM v3.5) ──
                // When mode=execution and LLM identified a specific goal,
                // carry it in meta so Router can skip its own LLM call.
                if ( ! empty( $llm_result['intent_result'] ) ) {
                    $result['meta']['intent_result'] = $llm_result['intent_result'];
                }

                // ── v3.8.1: Regex-authoritative override (post-LLM correction) ──
                // Regex patterns are developer-curated for specific phrases.
                // If a regex pattern deterministically matched a goal and the LLM
                // returned a DIFFERENT goal, cross-validate: does the message also
                // match the LLM goal's pattern? If YES → ambiguous, let LLM decide.
                // If NO → LLM hallucinated (e.g. "bốc bài nhé" → check_synastry),
                // override with the regex match.
                if ( $regex_likely_goal
                     && $result['mode'] === self::MODE_EXECUTION
                     && ! empty( $result['meta']['intent_result']['goal'] )
                     && $result['meta']['intent_result']['goal'] !== $regex_likely_goal['goal']
                ) {
                    $llm_goal = $result['meta']['intent_result']['goal'];
                    // Tri-state check: 'match' | 'no_match' | 'no_patterns'
                    $llm_pattern_status = $this->check_goal_pattern_match( $message_lower, $llm_goal );

                    if ( $llm_pattern_status === 'no_match' ) {
                        // LLM goal has registered patterns but NONE match this message
                        // → LLM hallucinated → override with regex match
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[MODE-CLASSIFIER] regex-override: LLM goal='
                                     . $llm_goal . ' (has patterns, none match)'
                                     . ' → regex goal=' . $regex_likely_goal['goal']
                                     . ' | msg="' . mb_substr( $message, 0, 60, 'UTF-8' ) . '"' );
                        }
                        $result['meta']['intent_result']['goal']            = $regex_likely_goal['goal'];
                        $result['meta']['intent_result']['goal_label']      = $regex_likely_goal['label'];
                        $result['meta']['intent_result']['intent']          = 'new_goal';
                        $result['meta']['intent_result']['confidence']      = max(
                            $result['meta']['intent_result']['confidence'] ?? 0,
                            0.93
                        );
                        // Reset entities/slots — they were extracted for the wrong goal
                        $result['meta']['intent_result']['entities']        = [];
                        $result['meta']['intent_result']['filled_slots']    = [];
                        $result['meta']['intent_result']['missing_slots']   = $regex_likely_goal['extract'] ?? [];
                        $result['meta']['intent_result']['_regex_override'] = true;
                    } elseif ( $llm_pattern_status === 'no_patterns' ) {
                        // LLM goal is DB-only (no regex patterns registered)
                        // → could be a valid admin-defined tool, don't override blindly
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[MODE-CLASSIFIER] regex vs LLM: regex='
                                     . $regex_likely_goal['goal'] . ' LLM=' . $llm_goal
                                     . ' (DB-only goal, no patterns) → trust LLM' );
                        }
                    } else {
                        // 'match' — LLM goal also has a matching pattern for this message
                        // → ambiguous, trust LLM's contextual understanding
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[MODE-CLASSIFIER] regex vs LLM ambiguous: regex='
                                     . $regex_likely_goal['goal'] . ' LLM=' . $llm_goal
                                     . ' (both have pattern match) → trust LLM' );
                        }
                    }
                }

                // ── v3.8.2: Regex-authoritative MODE-level override ──
                // If regex pre-match identified an execution goal but LLM classified
                // as non-execution mode (knowledge/emotion/reflection), the LLM missed
                // the execution intent (e.g. pattern-only goal wasn't in focused schema,
                // or LLM didn't recognise the Vietnamese phrasing as a tool action).
                // Override mode to execution + create intent_result from regex.
                if ( $regex_likely_goal && $result['mode'] !== self::MODE_EXECUTION ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[MODE-CLASSIFIER] v3.8.2 mode-override: LLM mode='
                                 . $result['mode'] . ' → execution, goal=' . $regex_likely_goal['goal']
                                 . ' | msg="' . mb_substr( $message, 0, 60, 'UTF-8' ) . '"' );
                    }
                    $result['mode']       = self::MODE_EXECUTION;
                    $result['confidence'] = max( $result['confidence'], 0.90 );
                    $result['meta']['intent_result'] = [
                        'intent'        => 'new_goal',
                        'goal'          => $regex_likely_goal['goal'],
                        'goal_label'    => $regex_likely_goal['label'],
                        'entities'      => [],
                        'filled_slots'  => [],
                        'missing_slots' => $regex_likely_goal['extract'] ?? [],
                        'confidence'    => 0.93,
                        '_llm_model'    => $result['meta']['llm_model'] ?? '',
                        '_regex_override' => true,
                    ];
                    $result['meta']['original_llm_mode'] = $llm_result['mode'] ?? 'unknown';
                }

                // ── v3.9.0: Prompt Context File Logger ──
                // Logs full prompt + context + result to file for debugging.
                $this->log_prompt_context(
                    $message, $conversation, $regex_likely_goal,
                    $llm_result, $result
                );

                // ── Store mode to cache (v3.4) ──
                if ( class_exists( 'BizCity_Intent_Classify_Cache' ) ) {
                    $cache = BizCity_Intent_Classify_Cache::instance();

                    $cache->set_mode(
                        $message, $conversation,
                        [
                            'mode'       => $result['mode'],
                            'confidence' => $result['confidence'],
                            'is_memory'  => $result['is_memory'],
                        ],
                        $llm_result['meta']['llm_model'] ?? ''
                    );

                    // ── Also cache intent from unified LLM (v3.5) ──
                    // When Mode Classifier identified goal, cache it so future
                    // identical messages skip BOTH Mode + Router LLM calls.
                    // v3.8.1: Use $result['meta']['intent_result'] (may be regex-overridden)
                    // instead of raw $llm_result to cache the CORRECTED goal.
                    $cached_ir = $result['meta']['intent_result'] ?? [];
                    if ( ! empty( $cached_ir['goal'] ) ) {
                        $cache->set_intent(
                            $message, $conversation, '', // no provider_hint here
                            [
                                'intent'     => $cached_ir['intent'],
                                'goal'       => $cached_ir['goal'],
                                'goal_label' => $cached_ir['goal_label'],
                                'entities'   => $cached_ir['entities'] ?? [],
                                'confidence' => $cached_ir['confidence'],
                            ],
                            $llm_result['meta']['llm_model'] ?? ''
                        );
                    }
                }

                return $result;
            }
            // LLM unavailable → fall through to default
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[MODE-CLASSIFIER] LLM unavailable, falling back to ambiguous' );
            }
        }

        // ── Default: ambiguous mode (safe fallback) ──
        $result['mode']       = self::MODE_AMBIGUOUS;
        $result['confidence'] = 0.50;
        $result['method']     = 'fallback';
        return $result;
    }

    /* ================================================================
     * Check if message is a memory/learn request
     *
     * @param string $text Lowercase message text.
     * @return bool
     * ================================================================ */
    private function is_memory_request( $text ) {
        foreach ( $this->memory_patterns as $pattern => $confidence ) {
            if ( preg_match( $pattern, $text ) ) {
                return true;
            }
        }
        return false;
    }

    /* ================================================================
     * Lightweight heuristic: could this message be a memory/preference request?
     *
     * DEPRECATED in v3.5.2 — Unified LLM (BƯỚC 3 in classify_with_llm_unified)
     * now handles memory detection natively. The 24 Vietnamese heuristic patterns
     * previously here caused both false positives and false negatives.
     *
     * @deprecated 3.5.2 Memory detection handled by unified LLM.
     * @param string $text Lowercase message text.
     * @return bool  Always returns false.
     * ================================================================ */
    private function might_be_memory( $text ) {
        return false;
    }

    /* ================================================================
     * Fast LLM check: is this message a memory/preference request?
     *
     * DEPRECATED in v3.5.2 — Memory detection is now handled by the
     * unified LLM (BƯỚC 3 in classify_with_llm). The separate LLM call
     * was redundant and added ~1s latency.
     *
     * @deprecated 3.5.2 Memory detection handled by unified LLM.
     * @param string $message Original message text.
     * @return bool  Always returns false.
     * ================================================================ */
    private function check_memory_with_llm( $message ) {
        return false;
    }

    /* ================================================================
     * Check if message contains a URL (potential crawl request)
     *
     * @param string $text Message text.
     * @return string|false  URL found, or false.
     * ================================================================ */
    public function extract_url( $text ) {
        if ( preg_match( '/(https?:\/\/[^\s<>"\']+)/ui', $text, $m ) ) {
            return $m[1];
        }
        return false;
    }

    /* ================================================================
     * Check if message has file upload intent
     *
     * @param array $attachments
     * @return bool
     * ================================================================ */
    public function has_file_upload( $attachments ) {
        if ( empty( $attachments ) ) {
            return false;
        }
        foreach ( $attachments as $att ) {
            if ( is_string( $att ) ) {
                // Check if it's a file URL (not an image)
                if ( preg_match( '/\.(pdf|csv|xlsx?|doc|docx|json|txt)$/i', $att ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /* ================================================================
     * LLM-based UNIFIED mode + intent classification (v3.5)
     *
     * Single LLM call that classifies BOTH:
     *   - Mode (emotion/reflection/knowledge/execution)
     *   - Intent+Goal (when mode=execution)
     *   - Entity extraction (slot values from message)
     *
     * v3.8.0: Uses focused schema (top N tools with type hints) + regex bias.
     * Eliminates separate Router Tier 1 + Tier 2 calls — single unified call.
     *
     * @param string     $message
     * @param array|null $conversation
     * @param array|null $regex_likely_goal  { goal, label, extract } from regex pre-match.
     * @return array|null { mode, confidence, is_memory, meta, intent_result }
     * ================================================================ */
    private function classify_with_llm( $message, $conversation = null, $regex_likely_goal = null ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return null;
        }

        // ── Conversation context (for WAITING_USER / active goal awareness) ──
        $conv_context = '';
        if ( $conversation && ! empty( $conversation['goal'] ) ) {
            $conv_context = "\n\nCONTEXT hiện tại:"
                . "\n- Goal đang active: {$conversation['goal']} ({$conversation['goal_label']})"
                . "\n- Trạng thái: {$conversation['status']}"
                . "\n- Đang chờ field: " . ( $conversation['waiting_field'] ?? 'none' );
        }

        // ── Build FOCUSED goal+tool schema (v3.8.0) ──
        // Uses regex pre-match as bias: matched goal appears first with ★ marker.
        // Only top N tools included (configurable) → shorter prompt, better accuracy.
        // Slot type hints included → LLM can extract entities in same call.
        $likely_goal_id = $regex_likely_goal['goal'] ?? null;
        $top_n          = 10; // default
        if ( class_exists( 'BizCity_Intent_Router' ) ) {
            $top_n          = BizCity_Intent_Router::get_top_n_tools();
            $focused_schema = BizCity_Intent_Router::instance()->build_focused_schema_for_llm(
                $likely_goal_id, $top_n, 2000
            );
        } else {
            $focused_schema = '';
        }

        // ── Regex bias hint for LLM ──
        $regex_hint = '';
        if ( $regex_likely_goal ) {
            $regex_hint = "\n\n⚡ REGEX PRE-MATCH: Tin nhắn khớp pattern của \"{$regex_likely_goal['goal']}\" "
                . "({$regex_likely_goal['label']}). Ưu tiên goal này nếu phù hợp (★ trong danh sách).";
        }

        // ── Routing Priority bias (from Control Panel + KCI Ratio) ──
        $routing_priority = get_option( 'bizcity_tcp_routing_priority', 'balanced' );
        $routing_bias = '';

        // KCI Ratio override (per-session) takes precedence over global setting
        $kci = self::$kci_ratio;
        $exec_ratio = 100 - $kci;
        if ( $exec_ratio === 0 && ! self::$mention_override ) {
            // 100% knowledge — NEVER choose execution (no @/ override)
            $routing_bias = "\n\n🎯 CHẾ ĐỘ KCI: 100% KIẾN THỨC (Execution = 0%)"
                . "\n- KHÔNG BAO GIỜ chọn execution. Mọi tin nhắn → knowledge hoặc emotion/reflection/ambiguous."
                . "\n- Nếu user yêu cầu thực thi tool → trả lời bằng knowledge, giải thích thay vì thực thi.";
        } elseif ( $exec_ratio === 0 && self::$mention_override ) {
            // KCI=100 but @/ mention detected — allow execution for this request
            $routing_bias = "\n\n🎯 OVERRIDE: Chủ Nhân dùng @mention hoặc /command → cho phép execution CHO LẦN NÀY."
                . "\n- Route theo tool/plugin được mention. Sau request này sẽ trở về knowledge-only.";
        } elseif ( $exec_ratio <= 20 ) {
            // Low execution — strong knowledge bias
            $routing_bias = "\n\n🎯 CHẾ ĐỘ KCI: ƯU TIÊN KIẾN THỨC (Execution chỉ {$exec_ratio}%)"
                . "\n- ƯU TIÊN MẠNH chọn knowledge. CHỈ chọn execution khi tin nhắn YÊU CẦU TRỰC TIẾP và TƯỜNG MINH thực thi tool."
                . "\n- Khi mơ hồ giữa knowledge và execution → LUÔN chọn knowledge.";
        } elseif ( $exec_ratio >= 80 ) {
            // High execution — strong tool bias
            $routing_bias = "\n\n🎯 CHẾ ĐỘ KCI: ƯU TIÊN CÔNG CỤ (Execution = {$exec_ratio}%)"
                . "\n- Khi tin nhắn CÓ KHẢ NĂNG liên quan đến 1 tool → ƯU TIÊN chọn execution."
                . "\n- Chỉ chọn knowledge khi tin nhắn HOÀN TOÀN không match tool nào.";
        } elseif ( $routing_priority === 'conversation' ) {
            $routing_bias = "\n\n🎯 CHẾ ĐỘ ƯU TIÊN: TRÒ CHUYỆN"
                . "\n- Khi tin nhắn MƠ HỒ → ƯU TIÊN chọn ambiguous."
                . "\n- CHỈ chọn execution khi tin nhắn RÕ RÀNG yêu cầu thực thi tool cụ thể (có động từ hành động + đối tượng rõ)."
                . "\n- Ví dụ: 'buồn quá' → emotion (KHÔNG phải execution), 'hôm nay trời đẹp nhỉ' → reflection.";
        } elseif ( $routing_priority === 'tools' ) {
            $routing_bias = "\n\n🎯 CHẾ ĐỘ ƯU TIÊN: CÔNG CỤ"
                . "\n- Khi tin nhắn CÓ KHẢ NĂNG liên quan đến 1 tool → ƯU TIÊN chọn execution."
                . "\n- Chỉ chọn emotion khi tin nhắn THUẦN tâm sự cảm xúc, không có hành động nào khả thi."
                . "\n- Ví dụ: 'viết gì đó cho marketing' → execution, 'cho tôi ý tưởng' → execution (nếu có tool phù hợp).";
        }
        // balanced + kci 50 → no bias, LLM decides naturally
        error_log( '[KCI-TRACE] bias: kci=' . $kci . ', exec_ratio=' . $exec_ratio
            . ', mention_override=' . ( self::$mention_override ? 'true' : 'false' )
            . ', routing_priority=' . $routing_priority
            . ', bias_type=' . ( $routing_bias ? mb_substr( str_replace( "\n", ' ', $routing_bias ), 0, 80 ) : 'none/balanced' ) );

        $prompt = <<<PROMPT
Bạn là AI Team Leader & Thư ký công việc cho hệ thống BizCity. Phân tích tin nhắn → trả JSON.

## BƯỚC 1 — XÁC ĐỊNH MODE (chọn 1):
1. emotion — THUẦN tâm sự, cảm xúc, than phiền, chia sẻ cảm giác. KHÔNG có yêu cầu hành động nào.
2. reflection — Kể chuyện, chia sẻ trải nghiệm, sự kiện đã xảy ra (hiếm)
3. knowledge — Hỏi đáp, nghiên cứu, phân tích, tư vấn, lập kế hoạch, viết code, brainstorm. Bất kỳ tin nhắn nào CÓ CHỦ ĐỀ CỤ THỂ mà KHÔNG map trực tiếp vào tool.
4. execution — THỰC THI hành động cụ thể bằng 1 trong các tool bên dưới. Phải match CHÍNH XÁC 1 tool.
5. ambiguous — CHỈ cho tin nhắn 1-3 từ MƠ HỒ thuần xã giao: "hi", "ok", "ờ", "hmm", "chào". KHÔNG dùng cho câu có nội dung.

PHÂN BỔ ƯU TIÊN (AI Team Leader style):
- ~60% knowledge: Câu hỏi, tư vấn, phân tích, brainstorm, lập kế hoạch, viết code, nghiên cứu → knowledge
- ~30% execution: Yêu cầu hành động RÕ RÀNG match tool cụ thể (tạo, xóa, gửi, bói, báo cáo, xem...) → execution
- ~10% emotion + ambiguous + reflection: Chỉ khi KHÔNG CÓ nội dung cụ thể hoặc THUẦN cảm xúc

QUAN TRỌNG:
- Tin nhắn có CHỦ ĐỀ (dù chưa phải câu hỏi rõ) → knowledge (KHÔNG phải ambiguous)
  VD: "marketing", "ý tưởng kinh doanh", "capacitor react" → knowledge
- Tin nhắn hỏi ý kiến, tư vấn, so sánh → knowledge (KHÔNG phải execution)
  VD: "nên dùng React hay Vue?", "tư vấn chiến lược Q2" → knowledge
- Tin nhắn yêu cầu LÀM gì đó + match tool → execution
  VD: "viết bài về marketing" (match tool-content) → execution
- CHỈ dùng ambiguous cho tin nhắn THẬT SỰ mơ hồ 1-3 từ xã giao thuần
{$routing_bias}

🔮 NHẬN DIỆN CÂU HỎI CHIÊM TINH & DỰ BÁO TƯƠNG LAI (ĐỘ NHẠY CAO — trust cao):
User có bản đồ sao cá nhân (natal chart) trong hệ thống. Khi user hỏi về TƯƠNG LAI, VẬN MỆNH, DỰ BÁO → mode=execution, goal="bizcoach_consult", confidence ≥ 0.85.
Các dấu hiệu (dù KHÔNG nói rõ "chiêm tinh"):
- Hỏi về ngày/tuần/tháng/năm sắp tới: "ngày mai thế nào", "tuần này ra sao", "tháng sau có gì"
- Hỏi vận mệnh/tương lai cá nhân: "tình hình tài chính tương lai", "sự nghiệp sắp tới", "tình cảm năm nay"
- Hỏi tình hình hiện tại kiểu dự báo: "hôm nay tôi thế nào", "hôm nay nên làm gì", "hôm nay có thuận lợi không"
- Hỏi trực tiếp: chiêm tinh, tử vi, bói, tarot, vận hạn, bản đồ sao, transit, natal
- Hỏi "tôi nên..." kèm thời gian tương lai hoặc chủ đề cá nhân (tài chính, tình cảm, sức khỏe, sự nghiệp)
→ Đây là DỰ BÁO CÁ NHÂN cần bản đồ sao → execution + bizcoach_consult.
⚠️ PHÂN BIỆT: "ngày mai thời tiết thế nào" = knowledge (tra cứu). "ngày mai tôi thế nào" = execution (dự báo cá nhân).

NẾU mode=knowledge → xác định knowledge_type (chọn 1):
- research: Nghiên cứu sâu, phân tích chi tiết, giải thích khái niệm, hướng dẫn kỹ thuật
- advisor: Tư vấn, đề xuất phương án, so sánh lựa chọn, lập kế hoạch, brainstorm
- lookup: Tra cứu ngắn, số liệu, sự kiện cụ thể, câu trả lời 1-2 dòng

NẾU mode=knowledge VÀ confidence 0.5-0.7 (gần ranh giới execution) → điền suggested_tool = tên tool gợi ý (nếu có).

{$focused_schema}

## BƯỚC 2 — NẾU mode=execution → XÁC ĐỊNH INTENT + GOAL + ENTITY EXTRACTION:

INTENTS:
- new_goal: Bắt đầu nhiệm vụ mới (map vào 1 goal cụ thể)
- provide_input: Trả lời/cung cấp thông tin cho goal đang chờ (WAITING_USER)
- continue_goal: Bổ sung thông tin cho goal đang active
- (Nếu mode KHÁC execution → intent="" goal="")

QUY TẮC:
1. Nếu goal có [khi nói: ...] → khi user dùng từ khóa đó → ưu tiên goal này
2. WAITING_USER + tin nhắn = câu trả lời → provide_input (giữ goal cũ)
3. WAITING_USER + yêu cầu MỚI khác hẳn → new_goal
4. confidence: 0.0-1.0, ≥ 0.8 khi chắc chắn
5. ★ marked goal = regex pre-matched → ưu tiên cao nếu ngữ cảnh phù hợp

## BƯỚC 2b — ENTITY/SLOT EXTRACTION (CHỈ khi mode=execution):
Dựa vào "cần" và "tùy chọn" của goal đã chọn (chú ý type: text, number, choice, image...):
- **entities**: chỉ chứa giá trị THỰC SỰ có trong tin nhắn. KHÔNG đoán, KHÔNG bịa.
  VD: "viết bài về marketing" → entities={"topic":"marketing"}
  VD: "tạo mindmap về ý tưởng kinh doanh sữa bột trên tiktok" → entities={"topic":"ý tưởng kinh doanh sữa bột trên tiktok"}
  VD: "đăng bài giúp mình" → entities={} (KHÔNG có topic)
- **filled_slots**: mảng tên field đã extract được giá trị thực.
- **missing_slots**: mảng tên field BẮT BUỘC (cần) mà CHƯA có giá trị.

QUAN TRỌNG:
- Câu lệnh/politeness KHÔNG PHẢI là slot (VD: "giúp mình nhé" ≠ topic)
- Chỉ fill slot khi tin nhắn THẬT SỰ chứa nội dung cụ thể cho slot đó
- Lấy TOÀN BỘ phần nội dung dài cho slot text (không cắt ngắn)

## BƯỚC 3 — MEMORY CHECK + BUILT-IN FUNCTION:
is_memory = true nếu yêu cầu ghi nhớ/thiết lập preference/quy tắc.
memory_type = loại memory (6 loại):
  - "save_fact": ghi nhớ thông tin cá nhân ("tên tôi là X", "tôi 30 tuổi")
  - "set_response_rule": quy tắc phản hồi ("trả lời ngắn gọn", "bạn cần viết chi tiết hơn")
  - "set_communication_style": xưng hô, ngôn ngữ, giọng ("xưng hô anh em", "nói tiếng Anh")
  - "pin_context": ghim ngữ cảnh liên tục ("tôi đang làm dự án ABC")
  - "set_output_format": định dạng output ("format dạng json", "luôn trả lời bằng bảng")
  - "set_focus_topic": chủ đề quan tâm ("từ giờ tập trung vào marketing")
built_in_function = hàm hệ thống cần gọi (nếu có):
  - "save_user_memory": lưu bất kỳ memory nào ở trên
  - "forget_memory": xoá/quên ("quên đi tên tôi", "xóa thông tin X")
  - "list_memories": xem lại ký ức ("bạn nhớ gì về tôi?")
  - "end_conversation": kết thúc hội thoại ("thôi", "tạm biệt", "bye")
  - "explain_last": giải thích câu trả lời trước ("tại sao bạn nói vậy?", "giải thích lại")
  - "summarize_session": tóm tắt phiên chat ("tóm tắt cuộc trò chuyện")
  - "": không phải built-in function
VD: "hãy xưng hô anh em" → is_memory=true, memory_type="set_communication_style", built_in_function="save_user_memory"
VD: "bạn nhớ gì về tôi?" → is_memory=false, memory_type="", built_in_function="list_memories"
VD: "tạm biệt" → is_memory=false, memory_type="", built_in_function="end_conversation"
{$regex_hint}{$conv_context}

Tin nhắn: "{$message}"

Trả lời CHÍNH XÁC 1 JSON, KHÔNG giải thích:
{"mode":"...","confidence":0.0,"is_memory":false,"memory_type":"","built_in_function":"","knowledge_type":"","suggested_tool":"","intent":"","goal":"","goal_label":"","entities":{},"filled_slots":[],"missing_slots":[]}
PROMPT;

        $result = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => 'Fast unified message classifier. Respond ONLY with valid JSON. No explanation.' ],
                [ 'role' => 'user',   'content' => $prompt ],
            ],
            [
                'purpose'     => 'router',
                'temperature' => 0.05,
                'max_tokens'  => 300,
                'no_fallback' => false,
            ]
        );

        if ( empty( $result['success'] ) || empty( $result['message'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[MODE-CLASSIFIER] classify_with_llm FAIL — '
                    . 'model=' . ( $result['model'] ?? '?' )
                    . ' error=' . ( $result['error'] ?? 'empty success/message' ) );
            }
            return null;
        }

        // Parse JSON
        $json = trim( $result['message'] );
        $json = preg_replace( '/^```(?:json)?\s*/i', '', $json );
        $json = preg_replace( '/\s*```$/', '', $json );

        if ( ( $pos = strpos( $json, '{' ) ) !== false ) {
            $json = substr( $json, $pos );
        }
        if ( ( $pos = strrpos( $json, '}' ) ) !== false ) {
            $json = substr( $json, 0, $pos + 1 );
        }

        $parsed = json_decode( $json, true );

        if ( ! is_array( $parsed ) || empty( $parsed['mode'] ) ) {
            return null;
        }

        $mode = $parsed['mode'];
        if ( ! in_array( $mode, self::VALID_MODES, true ) ) {
            $mode = self::MODE_AMBIGUOUS;
        }

        $confidence = floatval( $parsed['confidence'] ?? 0.7 );
        $llm_model  = $result['model'] ?? '';

        // ── Extract knowledge_type for sub-mode routing ──
        $knowledge_type  = '';
        $suggested_tool  = '';
        if ( $mode === self::MODE_KNOWLEDGE ) {
            $kt = $parsed['knowledge_type'] ?? '';
            if ( in_array( $kt, [ 'research', 'advisor', 'lookup' ], true ) ) {
                $knowledge_type = $kt;
            } else {
                $knowledge_type = 'research'; // default sub-mode
            }
            // Sprint 1C: Extract suggested_tool (borderline knowledge/execution)
            $st = $parsed['suggested_tool'] ?? '';
            if ( is_string( $st ) && $st !== '' ) {
                $suggested_tool = $st;
            }
        }

        // ── Build intent_result for execution mode (passed to Router) ──
        $intent_result = null;
        if ( $mode === self::MODE_EXECUTION ) {
            $parsed_goal  = $parsed['goal'] ?? '';
            $parsed_intent = $parsed['intent'] ?? '';

            // Only pass intent_result if we have a specific goal
            if ( $parsed_goal ) {
                // Validate intent type
                $valid_intents = [ 'new_goal', 'provide_input', 'continue_goal' ];
                if ( ! in_array( $parsed_intent, $valid_intents, true ) ) {
                    $parsed_intent = 'new_goal';
                }

                $intent_result = [
                    'intent'       => $parsed_intent,
                    'goal'         => $parsed_goal,
                    'goal_label'   => $parsed['goal_label'] ?? '',
                    'entities'     => is_array( $parsed['entities'] ?? null ) ? $parsed['entities'] : [],
                    'filled_slots' => is_array( $parsed['filled_slots'] ?? null ) ? $parsed['filled_slots'] : [],
                    'missing_slots'=> is_array( $parsed['missing_slots'] ?? null ) ? $parsed['missing_slots'] : [],
                    'confidence'   => $confidence,
                    '_llm_model'   => $llm_model,
                ];
            }
        }

        return [
            'mode'       => $mode,
            'confidence' => $confidence,
            'is_memory'  => ! empty( $parsed['is_memory'] ),
            'meta'       => [
                'llm_tokens'       => $result['usage'] ?? [],
                'llm_model'        => $llm_model,
                'knowledge_type'   => $knowledge_type,
                'suggested_tool'   => $suggested_tool,
                'memory_type'      => $parsed['memory_type'] ?? '',
                'built_in_function'=> $parsed['built_in_function'] ?? '',
            ],
            'intent_result' => $intent_result,
            '_debug'         => [
                'llm_prompt'       => $prompt,
                'llm_raw_response' => $result['message'] ?? '',
                'focused_schema'   => $focused_schema,
                'llm_parsed'       => $parsed,
            ],
        ];
    }

    /* ================================================================
     * Utility: get human-readable mode label
     *
     * @param string $mode
     * @return string
     * ================================================================ */
    public static function get_mode_label( $mode ) {
        $labels = [
            self::MODE_EMOTION    => 'Empathy Mode — Tâm sự & cảm xúc',
            self::MODE_REFLECTION => 'Reflective Mode — Kể chuyện & chia sẻ',
            self::MODE_KNOWLEDGE  => 'Knowledge Mode — Hỏi đáp & kiến thức',
            self::MODE_EXECUTION  => 'Executor Mode — Thực thi hành động',
            self::MODE_AMBIGUOUS  => 'Ambiguous Mode — Chưa rõ ý định',
        ];
        return $labels[ $mode ] ?? 'Unknown Mode';
    }

    /* ================================================================
     * Prompt Context File Logger — writes classification debug data
     * to a file for post-mortem analysis (v3.9.0).
     *
     * @param string     $message
     * @param array|null $conversation
     * @param array|null $regex_likely_goal
     * @param array      $llm_result       Raw classify_with_llm() return.
     * @param array      $final_result     Final classify() result (after overrides).
     * ================================================================ */
    private function log_prompt_context( $message, $conversation, $regex_likely_goal, $llm_result, $final_result ) {
        if ( ! class_exists( 'BizCity_Prompt_Context_Logger' ) ) return;

        $logger = BizCity_Prompt_Context_Logger::instance();

        // Begin trace if not started (standalone classify calls outside Engine)
        if ( ! $logger->has_trace() ) {
            $trace_id = '';
            if ( class_exists( 'BizCity_Intent_Logger' ) ) {
                $trace_id = BizCity_Intent_Logger::instance()->get_trace_id() ?: '';
            }
            $logger->begin_trace( get_current_user_id(), $trace_id );
        }

        $debug = $llm_result['_debug'] ?? [];

        $logger->log_classification(
            $message,
            $conversation,
            $regex_likely_goal,
            $debug['focused_schema'] ?? '',
            $debug['llm_prompt'] ?? '',
            $debug['llm_raw_response'] ?? '',
            $debug['llm_parsed'] ?? null,
            $final_result,
            [
                'llm_model'         => $llm_result['meta']['llm_model'] ?? '',
                'original_llm_mode' => $final_result['meta']['original_llm_mode'] ?? null,
            ]
        );
    }

    /* ================================================================
     * Regex goal-pattern pre-match (v3.6.4)
     *
     * Checks if the message matches any registered goal pattern from
     * the Intent Router (built-in + provider patterns). This runs
     * BEFORE the LLM call to catch deterministic execution requests
     * that the LLM might mis-classify with low confidence.
     *
     * @param string $message_lower Lowercase message text.
     * @return array|null  { goal, label, extract } or null if no match.
     * ================================================================ */
    private function match_goal_by_regex( $message_lower ) {
        static $patterns = null;

        if ( $patterns === null ) {
            $patterns = [];
            if ( class_exists( 'BizCity_Intent_Router' ) ) {
                $raw = BizCity_Intent_Router::instance()->get_goal_patterns();

                foreach ( $raw as $regex => $meta ) {
                    if ( ! is_string( $regex ) || ! is_array( $meta ) || empty( $meta['goal'] ) ) {
                        continue;
                    }
                    $patterns[] = [
                        'regex'   => $regex,
                        'goal'    => $meta['goal'],
                        'label'   => $meta['label'] ?? $meta['goal'],
                        'extract' => $meta['extract'] ?? [],
                    ];
                }
            }
        }

        foreach ( $patterns as $p ) {
            if ( @preg_match( $p['regex'], $message_lower ) ) {
                return $p;
            }
        }

        return null;
    }

    /* ================================================================
     * Tri-state check: does the message match any registered pattern
     * for a SPECIFIC goal?
     *
     * Returns one of three values:
     *   'match'       — Goal has pattern(s) AND at least one matches this message.
     *   'no_match'    — Goal has pattern(s) but NONE match this message.
     *   'no_patterns' — Goal has NO registered patterns (DB-only / admin-defined tool).
     *
     * Used by the regex-override logic (v3.8.1) to cross-validate the
     * LLM's chosen goal. Override only happens on 'no_match' (hallucination).
     * 'no_patterns' means the goal is from DB Tool Index only — LLM may be
     * correct based on tool description, so we don't override.
     *
     * @param string $message_lower Lowercase message text.
     * @param string $target_goal   Goal ID to check patterns for.
     * @return string  'match' | 'no_match' | 'no_patterns'
     * ================================================================ */
    private function check_goal_pattern_match( $message_lower, $target_goal ) {
        if ( ! class_exists( 'BizCity_Intent_Router' ) ) {
            return 'no_patterns';
        }
        $all = BizCity_Intent_Router::instance()->get_goal_patterns();
        $has_any_pattern = false;
        foreach ( $all as $regex => $meta ) {
            if ( ! is_string( $regex ) || ( $meta['goal'] ?? '' ) !== $target_goal ) {
                continue;
            }
            $has_any_pattern = true;
            if ( @preg_match( $regex, $message_lower ) ) {
                return 'match';
            }
        }
        return $has_any_pattern ? 'no_match' : 'no_patterns';
    }
}
