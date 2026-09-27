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
 * BizCity Intent — minimal message router (R-INTENT-MIN).
 *
 * [2026-09-25 Claude Opus 5.5] CORE-REDUCTION WP-11 C5 — replaces the 149 KB Router, the 65 KB Mode_Classifier logic,
 * the Planner and the SQL classify cache. One pure function of the message:
 *
 *     route( $message, $context ) → { mode, intent, confidence, method, is_memory, reason, meta }
 *
 *   1. rules first (no cost, no SQL): empty, memory patterns, @/slash command, provider-declared goal patterns
 *      (filter `bizcity_intent_goal_patterns`, merged by the Provider Registry), multi-action commands, emotion /
 *      reflection / knowledge / execution cues;
 *   2. at most ONE LLM call (gateway `bizcity_openrouter_chat`) only when no rule reaches CONFIDENCE_THRESHOLD;
 *   3. otherwise `ambiguous`.
 *
 * It keeps NO conversation state: intent conversations are gone (D-26); CRM / Context Bank own history.
 * Mode values are unchanged (emotion | reflection | knowledge | execution | ambiguous), so Focus_Router needs no change.
 * The result keeps the keys of the old `BizCity_Mode_Classifier::classify()` (mode, confidence, method, is_memory, meta).
 *
 * @since 5.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Intent_Router', false ) ) {
    return;
}

final class BizCity_Intent_Router {

    const MODE_EMOTION    = 'emotion';
    const MODE_REFLECTION = 'reflection';
    const MODE_KNOWLEDGE  = 'knowledge';
    const MODE_EXECUTION  = 'execution';
    const MODE_AMBIGUOUS  = 'ambiguous';

    /** A rule / LLM answer below this confidence is not trusted. */
    const CONFIDENCE_THRESHOLD = 0.6;

    const VALID_MODES = [
        self::MODE_EMOTION,
        self::MODE_REFLECTION,
        self::MODE_KNOWLEDGE,
        self::MODE_EXECUTION,
        self::MODE_AMBIGUOUS,
    ];

    /** @var self|null */
    private static $instance = null;

    /** @var int Per-request KCI ratio set by the (legacy) chat gateway: 0 = full execution, 100 = knowledge only. */
    private static $kci_ratio = 80;

    /** @var bool @mention or /command overrides KCI=100 for this request. */
    private static $mention_override = false;

    /** @var array<string,float>|null memory / preference request patterns → confidence */
    private $memory_patterns = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param int $ratio 0–100 (0 = full execution, 100 = knowledge-only).
     */
    public static function set_kci_ratio( int $ratio ): void {
        self::$kci_ratio = max( 0, min( 100, $ratio ) );
    }

    public static function set_mention_override( bool $override ): void {
        self::$mention_override = $override;
    }

    /* ================================================================
     * route()
     *
     * @param string $message
     * @param array  $context {
     *   @type array $attachments  Images / file URLs.
     *   @type bool  $allow_llm    Default true. false = rules only (no network).
     * }
     * @return array {
     *   @type string $mode        One of VALID_MODES.
     *   @type string $intent      Provider goal id when a provider pattern matched, else ''.
     *   @type float  $confidence  0.0 – 1.0
     *   @type string $method      empty | rule | provider_pattern | llm | fallback
     *   @type bool   $is_memory   The user asks the bot to remember / adopt a preference.
     *   @type string $reason      Short machine-readable reason.
     *   @type array  $meta        Extras (intent_result for provider goals, kci flags).
     * }
     * ================================================================ */
    public function route( $message, array $context = [] ): array {
        $message = is_string( $message ) ? trim( $message ) : '';
        $result  = [
            'mode'       => self::MODE_AMBIGUOUS,
            'intent'     => '',
            'confidence' => 0.0,
            'method'     => 'empty',
            'is_memory'  => false,
            'reason'     => 'empty_message',
            'meta'       => [],
        ];
        if ( $message === '' ) {
            return $result;
        }

        $lower      = mb_strtolower( $message, 'UTF-8' );
        $attachment = ! empty( $context['attachments'] );
        $is_memory  = $this->is_memory_request( $lower, $memory_conf );
        $result['is_memory'] = $is_memory;

        // 1. File attached together with a "remember this" request → knowledge.
        if ( $attachment && $is_memory ) {
            return $this->finish( $result, self::MODE_KNOWLEDGE, 0.90, 'rule', 'memory_with_attachment' );
        }

        // 2. @mention / slash command → execution (also lifts the KCI=100 lock for this request).
        $is_command = (bool) preg_match( '/^\s*[\/@][\p{L}\d_\-]{2,}/u', $message );
        if ( $is_command ) {
            $result['meta']['command'] = true;
            return $this->finish( $result, self::MODE_EXECUTION, 0.95, 'rule', 'command_prefix', true );
        }

        // 3. Provider-declared goal patterns (Provider Registry merges them through the filter).
        $goal = $this->match_provider_goal( $lower );
        if ( $goal ) {
            $result['intent'] = $goal['goal'];
            $result['meta']['intent_result'] = [
                'intent'        => 'new_goal',
                'goal'          => $goal['goal'],
                'goal_label'    => $goal['label'],
                'entities'      => [],
                'filled_slots'  => [],
                'missing_slots' => $goal['extract'],
                'confidence'    => 0.9,
            ];
            return $this->finish( $result, self::MODE_EXECUTION, 0.90, 'provider_pattern', 'provider_goal_pattern' );
        }

        // 4. Memory / preference requests stay in the knowledge pipeline (is_memory carries the intent).
        if ( $is_memory ) {
            return $this->finish( $result, self::MODE_KNOWLEDGE, min( 0.85, max( 0.7, $memory_conf ) ), 'rule', 'memory_pattern' );
        }

        // 5. Cue rules, strongest first.
        $len = mb_strlen( $lower, 'UTF-8' );
        if ( $len > 15 && preg_match( $this->multi_action_pattern(), $lower ) ) {
            return $this->finish( $result, self::MODE_EXECUTION, 0.85, 'rule', 'multi_action_command' );
        }
        if ( $len <= 200 && preg_match( $this->emotion_pattern(), $lower ) && ! preg_match( $this->command_verb_pattern(), $lower ) ) {
            return $this->finish( $result, self::MODE_EMOTION, 0.75, 'rule', 'emotion_cue' );
        }
        if ( preg_match( $this->reflection_pattern(), $lower ) ) {
            return $this->finish( $result, self::MODE_REFLECTION, 0.70, 'rule', 'reflection_cue' );
        }
        if ( preg_match( $this->command_verb_pattern(), $lower ) ) {
            return $this->finish( $result, self::MODE_EXECUTION, 0.72, 'rule', 'command_verb' );
        }
        if ( preg_match( $this->knowledge_pattern(), $lower ) || substr( $message, -1 ) === '?' ) {
            return $this->finish( $result, self::MODE_KNOWLEDGE, 0.70, 'rule', 'question_cue' );
        }

        // 6. Nothing reached the threshold → at most one LLM call.
        $allow_llm = ! array_key_exists( 'allow_llm', $context ) || $context['allow_llm'];
        if ( $allow_llm && $len >= 3 && function_exists( 'bizcity_openrouter_chat' )
            && apply_filters( 'bizcity_intent_router_llm_enabled', true, $message, $context ) ) {
            $llm = $this->classify_with_llm( $message );
            if ( $llm && $llm['confidence'] >= self::CONFIDENCE_THRESHOLD ) {
                return $this->finish( $result, $llm['mode'], $llm['confidence'], 'llm', 'llm_classified' );
            }
            $result['meta']['llm_low_confidence'] = true;
            return $this->finish( $result, self::MODE_AMBIGUOUS, $llm ? $llm['confidence'] : 0.4, 'fallback', $llm ? 'llm_below_threshold' : 'llm_unavailable' );
        }

        return $this->finish( $result, self::MODE_AMBIGUOUS, 0.4, 'fallback', 'no_rule_matched' );
    }

    /**
     * First http(s) URL in the text, or false. (Used by user-memory to crawl-and-remember.)
     *
     * @param string $text
     * @return string|false
     */
    public function extract_url( $text ) {
        if ( is_string( $text ) && preg_match( '/(https?:\/\/[^\s<>"\']+)/ui', $text, $m ) ) {
            return $m[1];
        }
        return false;
    }

    /**
     * @param array $attachments
     * @return bool True when an attachment is a document (not an image).
     */
    public function has_file_upload( $attachments ) {
        foreach ( (array) $attachments as $att ) {
            if ( is_string( $att ) && preg_match( '/\.(pdf|csv|xlsx?|doc|docx|json|txt)$/i', $att ) ) {
                return true;
            }
        }
        return false;
    }

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

    /* ── internals ─────────────────────────────────────────────── */

    /**
     * Fill the result and apply the KCI lock (execution disabled for this request unless @/command override).
     */
    private function finish( array $result, string $mode, float $confidence, string $method, string $reason, bool $override = false ): array {
        if ( self::MODE_EXECUTION === $mode && 100 === self::$kci_ratio && ! self::$mention_override && ! $override ) {
            $result['meta']['kci_locked'] = true;
            $result['meta']['original_mode'] = $mode;
            $mode       = self::MODE_KNOWLEDGE;
            $confidence = min( $confidence, 0.8 );
            $reason     = $reason . '+kci_knowledge_only';
        }
        $result['mode']       = $mode;
        $result['confidence'] = round( $confidence, 2 );
        $result['method']     = $method;
        $result['reason']     = $reason;
        return $result;
    }

    /**
     * @param string     $lower
     * @param float|null $confidence Highest matching pattern weight (by ref).
     */
    private function is_memory_request( string $lower, &$confidence = null ): bool {
        $confidence = 0.0;
        if ( null === $this->memory_patterns ) {
            $this->memory_patterns = (array) apply_filters( 'bizcity_mode_memory_patterns', $this->default_memory_patterns() );
        }
        foreach ( $this->memory_patterns as $pattern => $weight ) {
            if ( is_string( $pattern ) && @preg_match( $pattern, $lower ) ) {
                $confidence = max( $confidence, (float) $weight );
            }
        }
        return $confidence > 0.0;
    }

    /**
     * @return array|null { goal, label, extract }
     */
    private function match_provider_goal( string $lower ) {
        $patterns = apply_filters( 'bizcity_intent_goal_patterns', [] );
        if ( ! is_array( $patterns ) ) {
            return null;
        }
        foreach ( $patterns as $regex => $cfg ) {
            if ( ! is_string( $regex ) || ! is_array( $cfg ) || empty( $cfg['goal'] ) ) {
                continue;
            }
            if ( @preg_match( $regex, $lower ) ) {
                return [
                    'goal'    => (string) $cfg['goal'],
                    'label'   => (string) ( $cfg['label'] ?? $cfg['goal'] ),
                    'extract' => (array) ( $cfg['extract'] ?? [] ),
                ];
            }
        }
        return null;
    }

    /**
     * One compact gateway call: mode + confidence only.
     *
     * @return array|null { mode, confidence }
     */
    private function classify_with_llm( string $message ) {
        $prompt = "Classify the user's message into exactly one mode:\n"
            . "- emotion: venting feelings, seeking comfort\n"
            . "- reflection: sharing a story, thoughts or a recap\n"
            . "- knowledge: asking a question or wanting an explanation\n"
            . "- execution: asking the assistant to DO something (create, send, post, look up, schedule...)\n"
            . "- ambiguous: cannot tell\n"
            . "Message: \"" . mb_substr( $message, 0, 500, 'UTF-8' ) . "\"\n"
            . 'Reply with ONE JSON object only: {"mode":"...","confidence":0.0}';
        $reply = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => 'Message mode classifier. Respond ONLY with valid JSON.' ],
                [ 'role' => 'user',   'content' => $prompt ],
            ],
            [ 'purpose' => 'router', 'temperature' => 0.05, 'max_tokens' => 60, 'no_fallback' => false ]
        );
        if ( ! is_array( $reply ) || empty( $reply['success'] ) || empty( $reply['message'] ) ) {
            return null;
        }
        $json = trim( (string) $reply['message'] );
        if ( preg_match( '/\{.*\}/s', $json, $m ) ) {
            $json = $m[0];
        }
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || empty( $data['mode'] ) || ! in_array( $data['mode'], self::VALID_MODES, true ) ) {
            return null;
        }
        return [
            'mode'       => (string) $data['mode'],
            'confidence' => max( 0.0, min( 1.0, (float) ( $data['confidence'] ?? 0.0 ) ) ),
        ];
    }

    /* ── patterns (Vietnamese + English) ───────────────────────── */

    private function multi_action_pattern(): string {
        return '/\b(?:đăng|viết|tạo|gửi|ghi|làm|post|publish|log|check|tra|tìm|xem|mở)\b'
            . '.*(?:sau\s*đó|rồi|xong|tiếp\s*theo|tiếp\s*tục|và)\s*,?\s*'
            . '(?:đăng|viết|tạo|gửi|ghi|làm|post|publish|log|check|tra|tìm|xem|mở)\b/ui';
    }

    /**
     * A command: the message OPENS with an action verb, or carries an explicit polite request anywhere
     * ("… hãy gửi …", "vui lòng tạo …", "giúp tôi đăng …", "please send …").
     */
    private function command_verb_pattern(): string {
        $polite = '(?:hãy|vui\s+lòng|làm\s+ơn|giúp\s+(?:tôi|mình|em)|please)';
        return '/(?:^\s*(?:' . $polite . '\s+)?|\b' . $polite . '\s+)'
            . '(?:tạo|đăng|gửi|xóa|xoá|thêm|đặt|lên\s+lịch|tìm\s+kiếm|tìm|mở|tra\s+cứu|thống\s+kê|báo\s+cáo|xuất|nhập|cập\s+nhật|'
            . 'create|send|post|publish|delete|add|schedule|search|find|open|export|update)\b/ui';
    }

    private function emotion_pattern(): string {
        return '/(?:buồn|chán|mệt\s*mỏi|mệt\s+quá|stress|căng\s+thẳng|cô\s+đơn|lo\s+lắng|áp\s+lực|tủi|thất\s+vọng|'
            . 'nhớ\s+(?:anh|em|chị|bạn|người\s+yêu)|khóc|tức\s+giận|bực\s+mình|sợ\s+quá|'
            . 'sad|tired|lonely|anxious|depressed|upset|frustrated)/ui';
    }

    private function reflection_pattern(): string {
        return '/(?:nhìn\s+lại|suy\s+ngẫm|tổng\s+kết\s+(?:tuần|tháng|năm|ngày)|mình\s+đã\s+học\s+được|hôm\s+nay\s+(?:mình|tôi|em)\s+(?:đã|vừa)|'
            . 'kể\s+(?:cho|với)\s+(?:bạn|anh|em)|reflect(?:ion)?\s+on|looking\s+back)/ui';
    }

    private function knowledge_pattern(): string {
        return '/(?:là\s+gì|là\s+ai|tại\s+sao|vì\s+sao|như\s+thế\s+nào|thế\s+nào\s+là|làm\s+sao|bao\s+nhiêu|khi\s+nào|ở\s+đâu|'
            . 'giải\s+thích|cho\s+(?:tôi|mình|em)\s+biết|so\s+sánh|khác\s+nhau|có\s+nên|'
            . '^(?:what|why|how|when|where|who|which|explain|is|are|can|does|do)\b)/ui';
    }

    private function default_memory_patterns(): array {
        return [
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
            // Implicit preference / response-rule patterns
            '/(hãy|bạn\s+cần|bạn\s+phải|bạn\s+nên)\s+(trả\s+lời|phản\s+hồi|đưa\s+ra|giải\s+thích|viết)\s+/ui' => 0.82,
            '/(từ\s+giờ|luôn\s+luôn|mỗi\s+lần|bao\s+giờ\s+cũng)\s+(hãy|phải|nên|cần)/ui' => 0.85,
            '/(đừng|không\s+được|cấm|đừng\s+bao\s+giờ)\s+(gọi|nói|viết|trả\s+lời|dùng)/ui' => 0.85,
            '/(khi\s+tôi|nếu\s+tôi)\s+(hỏi|yêu\s+cầu).{3,},?\s*(hãy|thì)/ui' => 0.80,
            '/(format|định\s+dạng|output)\s+(dạng|kiểu|json|bảng|bullet)/ui' => 0.82,
        ];
    }
}
