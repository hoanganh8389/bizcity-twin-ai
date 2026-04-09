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
 * Knowledge Provider Router — Smart Knowledge Mode Delegation
 *
 * Manages how knowledge AI plugins (Gemini, ChatGPT) are utilised alongside
 * the built-in Knowledge Pipeline.  The router registers as the "knowledge"
 * mode pipeline when at least ONE external provider is active.
 *
 * ┌─────────────────────────────────────────────────────────────────┐
 * │                     4 SCENARIO ROUTING                         │
 * ├─────────────────────────────────────────────────────────────────┤
 * │ S0  No plugin active    → built-in compose (Chat Gateway)      │
 * │ S1  1 plugin active     → use as EXPANSION (fuller answer)     │
 * │ S2  2 plugins, generic  → preferred answers + suggest other    │
 * │ S3  Explicit  mention   → direct exec via that provider,       │
 * │     + knowledge action    log to intent_conversations          │
 * └─────────────────────────────────────────────────────────────────┘
 *
 * Cách dùng trong chat:
 *   "AI là gì?"                          → S1/S2: preferred trả lời
 *   "dùng chatgpt viết kịch bản"         → S3: ChatGPT pipeline trực tiếp
 *   "gemini phân tích thị trường"         → S3: Gemini pipeline trực tiếp
 *   "blockchain là gì?"   (both active)  → S2: preferred + gợi ý plugin kia
 *
 * @package BizCity_Intent
 * @since   3.1.0  Initial version
 * @since   3.2.0  4-scenario routing, intent_conversations logging, provider suggestions
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ================================================================
 * Knowledge Provider Registry — Collects AI knowledge providers
 * ================================================================ */

class BizCity_Knowledge_Provider_Registry {

    /** @var self|null */
    private static $instance = null;

    /**
     * Registered providers.
     * @var array [ 'id' => [ 'pipeline' => BizCity_Mode_Pipeline, 'label' => string, 'priority' => int, 'patterns' => array ] ]
     */
    private $providers = [];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a knowledge AI provider.
     *
     * Called by each knowledge plugin during bizcity_mode_register_pipelines hook.
     *
     * @param string               $id       Provider slug: 'gemini', 'chatgpt', etc.
     * @param BizCity_Mode_Pipeline $pipeline The pipeline instance that handles processing.
     * @param array                $args {
     *   @type string $label    Human-readable name. Default: ucfirst($id).
     *   @type int    $priority Lower = higher priority as default. Default 10.
     *   @type array  $patterns Extra regex patterns for explicit provider mention detection.
     * }
     */
    public function register_provider( $id, BizCity_Mode_Pipeline $pipeline, $args = [] ) {
        $this->providers[ $id ] = [
            'pipeline' => $pipeline,
            'label'    => $args['label'] ?? ucfirst( $id ),
            'priority' => $args['priority'] ?? 10,
            'patterns' => $args['patterns'] ?? [],
        ];
    }

    /** @return array All registered providers. */
    public function get_providers() {
        return $this->providers;
    }

    /** @return array|null Provider data or null. */
    public function get( $id ) {
        return $this->providers[ $id ] ?? null;
    }

    /** @return bool True if at least one knowledge provider is registered. */
    public function has_any() {
        return ! empty( $this->providers );
    }

    /** @return int Number of registered providers. */
    public function count() {
        return count( $this->providers );
    }

    /** @return string[] Provider IDs. */
    public function get_ids() {
        return array_keys( $this->providers );
    }

    /**
     * Get the default/preferred provider based on settings and priority.
     *
     * Order of precedence:
     *   1. Site option: bizcity_knowledge_preferred_provider
     *   2. Lowest priority number (registration priority)
     *
     * @return string|null Provider ID or null if none registered.
     */
    public function get_preferred() {
        if ( empty( $this->providers ) ) {
            return null;
        }

        // Check site-wide preference
        $preferred = get_option( 'bizcity_knowledge_preferred_provider', '' );
        if ( $preferred && isset( $this->providers[ $preferred ] ) ) {
            return $preferred;
        }

        // Sort by priority (lower = more preferred)
        $sorted = $this->providers;
        uasort( $sorted, function ( $a, $b ) {
            return ( $a['priority'] ?? 10 ) - ( $b['priority'] ?? 10 );
        } );

        return array_key_first( $sorted );
    }
}

/* ================================================================
 * Knowledge Router Pipeline — 4-Scenario Smart Delegation
 *
 * Registers for MODE_KNOWLEDGE when ≥1 external provider active.
 *
 * S0  No plugin   → NOT reached (built-in stays)
 * S1  1 plugin    → expansion mode (fuller answer from that provider)
 * S2  2+ plugins  → preferred answers, then suggests the other(s)
 * S3  Explicit    → direct execution via named provider,
 *     mention       logged as tool turn in intent_conversations
 * ================================================================ */

class BizCity_Knowledge_Router_Pipeline extends BizCity_Mode_Pipeline {

    /** @var BizCity_Knowledge_Provider_Registry */
    private $provider_registry;

    /**
     * Built-in patterns to detect explicit provider mention in user message.
     * Each key is a provider slug, value is array of regex patterns.
     */
    private const PROVIDER_DETECT_PATTERNS = [
        'gemini'  => [
            '/\b(gemini|google\s*ai|google\s*gemini)\b/ui',
            '/\b(dùng|hỏi|dung|hoi|sử\s+dụng)\s+(gemini|google\s*ai)\b/ui',
            '/\b(gemini)\s*(ơi|cho|giúp|giup|trả\s*lời|cho\s*biết)\b/ui',
        ],
        'chatgpt' => [
            '/\b(chatgpt|chat\s*gpt|gpt[-\s]?4o?|openai)\b/ui',
            '/\b(dùng|hỏi|dung|hoi|sử\s+dụng)\s+(chatgpt|chat\s*gpt|gpt|openai)\b/ui',
            '/\b(chatgpt|gpt)\s*(ơi|cho|giúp|giup|trả\s*lời|cho\s*biết)\b/ui',
        ],
    ];

    /**
     * Knowledge action verbs — when paired with explicit provider name,
     * the message is treated as a direct function execution request (S3).
     */
    private const KNOWLEDGE_ACTION_PATTERNS = [
        '/\b(viết|viet|soạn|soan|tạo|tao|làm|lam)\s+(kịch\s*bản|kich\s*ban|bài|bai|nội\s*dung|noi\s*dung|content|script|outline)/ui',
        '/\b(phân\s*tích|phan\s*tich|analyze|analysis|đánh\s*giá|danh\s*gia)/ui',
        '/\b(tìm\s*hiểu|tim\s*hieu|nghiên\s*cứu|nghien\s*cuu|research)/ui',
        '/\b(giải\s*thích|giai\s*thich|explain|giảng\s*giải)/ui',
        '/\b(tóm\s*tắt|tom\s*tat|summarize|tổng\s*hợp|tong\s*hop)/ui',
        '/\b(so\s*sánh|so\s*sanh|compare|đối\s*chiếu)/ui',
        '/\b(lập\s*kế\s*hoạch|plan|chiến\s*lược|strategy|roadmap)/ui',
        '/\b(viết\s*code|viết\s*hàm|code|lập\s*trình)/ui',
        '/\b(dịch|translate|chuyển\s*ngữ)/ui',
        '/\b(review|kiểm\s*tra|check|rà\s*soát)/ui',
    ];

    public function __construct( BizCity_Knowledge_Provider_Registry $registry ) {
        $this->provider_registry = $registry;
    }

    public function get_mode() {
        return BizCity_Mode_Classifier::MODE_KNOWLEDGE;
    }

    public function get_label() {
        $ids = $this->provider_registry->get_ids();
        return 'Knowledge Router — ' . implode( ' + ', array_map( 'ucfirst', $ids ) );
    }

    /* ================================================================
     * Main routing — 4 scenarios
     * ================================================================ */

    public function process( array $ctx ) {
        $message   = $ctx['message'] ?? '';
        $providers = $this->provider_registry->get_providers();

        // ── S0: no providers → built-in compose fallback
        if ( empty( $providers ) ) {
            return $this->fallback_compose( $ctx );
        }

        // ── Detect explicit provider mention
        $explicit_id = $this->detect_explicit_provider( $message );

        /* ── S3: EXPLICIT MENTION + KNOWLEDGE ACTION ──
         * "dùng chatgpt viết kịch bản...", "gemini phân tích..."
         * → Direct execution via provider pipeline
         * → Log as tool turn in intent_conversations
         */
        if ( $explicit_id && isset( $providers[ $explicit_id ] ) ) {
            $has_action = $this->has_knowledge_action( $message );

            $result = $providers[ $explicit_id ]['pipeline']->process( $ctx );
            $result = $this->enrich_meta( $result, $explicit_id, $providers, true );

            // Log as tool-like execution in intent_conversations
            $this->log_provider_execution( $explicit_id, $ctx, $result, $has_action );

            $this->log_routing( 'S3:EXPLICIT', $explicit_id, true, $providers, $message );
            return $result;
        }

        // ── S3b: Explicit mention but provider NOT active
        if ( $explicit_id && ! isset( $providers[ $explicit_id ] ) ) {
            $fallback_id = $this->provider_registry->get_preferred();
            $result      = $providers[ $fallback_id ]['pipeline']->process( $ctx );
            $result      = $this->enrich_meta( $result, $fallback_id, $providers, false );

            // Prepend note about unavailable provider
            $note = sprintf(
                '⚡ **%s** chưa được kích hoạt trên hệ thống. Đang sử dụng **%s** để trả lời.',
                ucfirst( $explicit_id ),
                $providers[ $fallback_id ]['label'] ?? ucfirst( $fallback_id )
            );
            $result['reply'] = $note . "\n\n" . ( $result['reply'] ?? '' );

            $this->log_routing( 'S3b:UNAVAIL', $fallback_id, false, $providers, $message );
            return $result;
        }

        /* ── S2: MULTIPLE PROVIDERS, NO EXPLICIT MENTION ──
         * "blockchain là gì?", "tư vấn chế độ ăn"
         * → Preferred provider answers
         * → Append suggestion about available alternatives
         */
        if ( count( $providers ) >= 2 ) {
            $preferred_id = $this->provider_registry->get_preferred();
            $result       = $providers[ $preferred_id ]['pipeline']->process( $ctx );
            $result       = $this->enrich_meta( $result, $preferred_id, $providers, false );

            // Build and append provider suggestion
            $other_ids  = array_diff( array_keys( $providers ), [ $preferred_id ] );
            $suggestion = $this->build_provider_suggestion( $preferred_id, $other_ids, $providers );

            if ( ! empty( $result['reply'] ) && $suggestion ) {
                $result['reply'] .= "\n\n" . $suggestion;
            }
            $result['meta']['suggestion_shown'] = true;

            $this->log_routing( 'S2:MULTI+SUGGEST', $preferred_id, false, $providers, $message );
            return $result;
        }

        /* ── S1: SINGLE PROVIDER, NO EXPLICIT MENTION ──
         * Only one knowledge plugin is active → use it as expansion
         * Gives a fuller answer than the built-in compose mode.
         */
        $single_id = array_key_first( $providers );
        $result    = $providers[ $single_id ]['pipeline']->process( $ctx );
        $result    = $this->enrich_meta( $result, $single_id, $providers, false );
        $result['meta']['expansion'] = true;

        $this->log_routing( 'S1:EXPANSION', $single_id, false, $providers, $message );
        return $result;
    }

    /* ================================================================
     * Detect explicit provider mention
     * ================================================================ */

    private function detect_explicit_provider( $message ) {
        if ( empty( $message ) ) {
            return null;
        }

        // 1. Check registered providers' custom patterns
        foreach ( $this->provider_registry->get_providers() as $id => $data ) {
            if ( ! empty( $data['patterns'] ) ) {
                foreach ( $data['patterns'] as $pattern ) {
                    if ( is_string( $pattern ) && @preg_match( $pattern, $message ) ) {
                        return $id;
                    }
                }
            }
        }

        // 2. Built-in provider detection patterns
        foreach ( self::PROVIDER_DETECT_PATTERNS as $provider_id => $patterns ) {
            foreach ( $patterns as $pattern ) {
                if ( preg_match( $pattern, $message ) ) {
                    return $provider_id;
                }
            }
        }

        return null;
    }

    /* ================================================================
     * Check if message contains a knowledge action verb
     * (viết, phân tích, tìm hiểu, soạn, tóm tắt, etc.)
     * ================================================================ */

    private function has_knowledge_action( $message ) {
        foreach ( self::KNOWLEDGE_ACTION_PATTERNS as $pattern ) {
            if ( preg_match( $pattern, $message ) ) {
                return true;
            }
        }
        return false;
    }

    /* ================================================================
     * Log execution as a tool turn in intent_conversations
     *
     * Records the provider call in the same conversation thread that the
     * Intent Engine is using.  This makes the execution visible in:
     *   - Intent Monitor admin panel
     *   - Conversation history / turns audit
     *   - Context chain for follow-up messages
     * ================================================================ */

    private function log_provider_execution( $provider_id, array $ctx, array $result, $has_action ) {
        if ( ! class_exists( 'BizCity_Intent_Conversation' ) ) {
            return;
        }

        $conv_mgr   = BizCity_Intent_Conversation::instance();
        $user_id    = intval( $ctx['user_id'] ?? 0 );
        $channel    = $ctx['channel'] ?? 'webchat';
        $session_id = $ctx['session_id'] ?? '';
        $char_id    = intval( $ctx['character_id'] ?? 0 );

        // Get active conversation (don't create — the Intent Engine already did)
        $conversation = $conv_mgr->get_active( $user_id, $channel, $session_id, $char_id );
        if ( empty( $conversation['conversation_id'] ) ) {
            return;
        }
        $conv_id = $conversation['conversation_id'];

        $provider_label = $this->provider_registry->get( $provider_id )['label'] ?? ucfirst( $provider_id );
        $model_used     = $result['meta']['model'] ?? 'unknown';
        $tokens         = $result['meta']['tokens'] ?? [];
        $reply_len      = mb_strlen( $result['reply'] ?? '', 'UTF-8' );

        // Record tool turn — role='tool' with the provider as tool_name
        $conv_mgr->add_turn( $conv_id, 'tool', $result['reply'] ?? '', [
            'intent'     => 'knowledge_' . $provider_id,
            'tool_calls' => [[
                'name'   => 'knowledge_provider_' . $provider_id,
                'result' => [
                    'success'        => ! empty( $result['reply'] ),
                    'provider'       => $provider_id,
                    'provider_label' => $provider_label,
                    'model'          => $model_used,
                    'tokens'         => $tokens,
                    'reply_length'   => $reply_len,
                    'has_action'     => $has_action,
                    'action_type'    => $has_action ? 'direct_execution' : 'knowledge_query',
                ],
            ]],
            'meta' => [
                'pipeline'    => 'knowledge-router-s3',
                'provider'    => $provider_id,
                'model'       => $model_used,
                'explicit'    => true,
                'has_action'  => $has_action,
            ],
        ] );

        // Set lightweight goal if conversation has no goal yet
        if ( empty( $conversation['goal'] ) ) {
            $conv_mgr->set_goal(
                $conv_id,
                'knowledge:' . $provider_id,
                '📚 ' . $provider_label
            );
        }

        // Log router event for admin console
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'knowledge_provider_exec',
                'message'          => mb_substr( $ctx['message'] ?? '', 0, 120, 'UTF-8' ),
                'mode'             => 'knowledge',
                'functions_called' => 'knowledge_provider_' . $provider_id . '()',
                'provider'         => $provider_id,
                'provider_label'   => $provider_label,
                'model'            => $model_used,
                'has_action'       => $has_action,
                'reply_length'     => $reply_len,
                'tokens'           => $tokens,
                'file_line'        => 'class-knowledge-router.php::log_provider_execution',
            ], $ctx['session_id'] ?? '' );
        }
    }

    /* ================================================================
     * Build provider suggestion message
     *
     * Shown when multiple providers are active but user didn't
     * explicitly mention one.  Offers to expand with other providers.
     * ================================================================ */

    private function build_provider_suggestion( $used_id, array $other_ids, array $providers ) {
        if ( empty( $other_ids ) ) {
            return '';
        }

        $used_label = $providers[ $used_id ]['label'] ?? ucfirst( $used_id );
        $others     = [];
        foreach ( $other_ids as $oid ) {
            $others[] = '**' . ( $providers[ $oid ]['label'] ?? ucfirst( $oid ) ) . '**';
        }
        $other_str = implode( ' hoặc ', $others );

        // Suggest explicit command syntax
        $example_id    = reset( $other_ids );
        $example_label = strtolower( $example_id );

        return sprintf(
            "---\n💡 *Câu trả lời trên sử dụng %s, để xem thêm %s góc nhìn khác, *\n" .
            "*Gõ:* `dùng %s [câu hỏi]` *để chuyển sang provider khác.*",
            $used_label,
            $other_str,
            $example_label
        );
    }

    /* ================================================================
     * Enrich result meta with routing info
     * ================================================================ */

    private function enrich_meta( array $result, $target_id, array $providers, $is_explicit ) {
        $result['meta'] = array_merge( $result['meta'] ?? [], [
            'knowledge_router'    => true,
            'provider'            => $target_id,
            'provider_label'      => $providers[ $target_id ]['label'] ?? ucfirst( $target_id ),
            'explicit_mention'    => $is_explicit,
            'available_providers' => array_keys( $providers ),
            'preferred_provider'  => $this->provider_registry->get_preferred(),
            'total_providers'     => count( $providers ),
        ] );
        return $result;
    }

    /* ================================================================
     * Logging helper
     * ================================================================ */

    private function log_routing( $scenario, $target_id, $is_explicit, array $providers, $message ) {
        error_log( sprintf(
            '[KNOWLEDGE-ROUTER] %s target=%s explicit=%s available=[%s] preferred=%s msg="%s"',
            $scenario,
            $target_id,
            $is_explicit ? 'Y' : 'N',
            implode( ',', array_keys( $providers ) ),
            $this->provider_registry->get_preferred(),
            mb_substr( $message, 0, 80, 'UTF-8' )
        ) );
    }

    /* ================================================================
     * Fallback compose (S0 safety — no providers at all)
     * ================================================================ */

    private function fallback_compose( array $ctx ) {
        $message = $ctx['message'] ?? '';

        $system_parts   = [];
        $system_parts[] = <<<PROMPT
## CHẾ ĐỘ: KNOWLEDGE MODE — HỎI ĐÁP & KIẾN THỨC (Default)

Người dùng đang tìm hiểu thông tin, hỏi đáp.
Không có AI knowledge provider riêng (Gemini / ChatGPT) nào active.
Sử dụng model mặc định của Chat Gateway.

### Nguyên tắc:
1. Trả lời **chính xác**, dựa trên kiến thức hiện có
2. Ngắn gọn, trực tiếp, dễ hiểu
3. Nếu không chắc → nói rõ "Mình không chắc về điều này"
4. Dùng format: bullet points, headings khi cần
5. Kết thúc bằng "Bạn muốn tìm hiểu thêm gì không?" nếu phù hợp
PROMPT;

        // Profile context
        $user_id    = intval( $ctx['user_id'] ?? 0 );
        $session_id = $ctx['session_id'] ?? '';
        $channel    = $ctx['channel'] ?? 'webchat';
        $platform   = ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT';

        $profile_ctx = $this->get_profile_context( $user_id, $session_id, $platform );
        if ( $profile_ctx ) {
            $system_parts[] = $profile_ctx;
        }

        // Character knowledge (RAG)
        $character_id = intval( $ctx['character_id'] ?? 0 );
        if ( $character_id ) {
            $knowledge_ctx = $this->get_knowledge_context( $character_id, $message, [
                'max_tokens' => 3000,
            ] );
            if ( $knowledge_ctx ) {
                $system_parts[] = "## 📚 KIẾN THỨC LIÊN QUAN\n\n" . $knowledge_ctx;
            }
        }

        return [
            'reply'               => '',
            'action'              => 'compose',
            'system_prompt_parts' => $system_parts,
            'memory'              => [
                'type'    => 'fact',
                'content' => mb_substr( $message, 0, 300 ),
            ],
            'meta'                => [
                'pipeline'            => 'knowledge-router-fallback',
                'temperature'         => 0.4,
                'knowledge_router'    => true,
                'provider'            => 'built-in',
                'available_providers' => [],
                'total_providers'     => 0,
            ],
        ];
    }
}

/* ================================================================
 * DEPRECATED (v3.9.0 — 2026-03-11)
 *
 * Knowledge Router + Provider Registry are NO LONGER active.
 * Gemini / ChatGPT now register as execution tools (Intent Providers)
 * alongside 1000+ other plugins — no special knowledge routing needed.
 *
 * Built-in BizCity_Knowledge_Pipeline (class-mode-pipeline.php)
 * handles all knowledge-mode messages via Chat Gateway / OpenRouter.
 *
 * Classes above are kept for reference only. Auto-registration hook
 * has been removed so the Router never overrides the built-in pipeline.
 * ================================================================ */
