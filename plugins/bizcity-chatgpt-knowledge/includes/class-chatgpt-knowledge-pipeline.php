<?php
/**
 * ChatGPT Knowledge Pipeline — Override built-in Knowledge Pipeline.
 *
 * Replaces the default BizCity_Knowledge_Pipeline with a ChatGPT-powered
 * version that calls OpenAI GPT-4o models directly via OpenRouter.
 *
 * Return action=reply for direct response (bypasses Chat Gateway default model).
 * Falls back to action=compose if ChatGPT call fails.
 *
 * @package BizCity_ChatGPT_Knowledge
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_ChatGPT_Knowledge_Pipeline extends BizCity_Mode_Pipeline {

    /* ── Model constants ────────────────────────── */
    const MODEL_PRIMARY  = 'openai/gpt-4o';
    const MODEL_FALLBACK = 'openai/gpt-4o-mini';
    const TEMPERATURE    = 0.55;
    const MAX_TOKENS     = 8000;

    /* ── Available ChatGPT models ───────────────── */
    const MODELS = [
        'openai/gpt-4o'       => [ 'name' => 'GPT-4o',       'context' => '128K tokens', 'default' => true ],
        'openai/gpt-4o-mini'  => [ 'name' => 'GPT-4o Mini',  'context' => '128K tokens', 'default' => false ],
        'openai/gpt-4-turbo'  => [ 'name' => 'GPT-4 Turbo',  'context' => '128K tokens', 'default' => false ],
        'openai/gpt-4.1'      => [ 'name' => 'GPT-4.1',      'context' => '1M tokens',   'default' => false ],
        'openai/gpt-4.1-mini' => [ 'name' => 'GPT-4.1 Mini', 'context' => '1M tokens',   'default' => false ],
    ];

    /* ── Pipeline identification ────────────────── */

    public function get_mode() {
        return BizCity_Mode_Classifier::MODE_KNOWLEDGE;
    }

    public function get_label() {
        return 'ChatGPT Knowledge — Trả lời chi tiết (powered by OpenAI)';
    }

    /* ── Main processing ────────────────────────── */

    public function process( array $ctx ) {
        $message      = $ctx['message'] ?? '';
        $user_id      = intval( $ctx['user_id'] ?? 0 );
        $session_id   = $ctx['session_id'] ?? '';
        $character_id = intval( $ctx['character_id'] ?? 0 );
        $channel      = $ctx['channel'] ?? 'webchat';

        // Read admin settings
        $settings    = get_option( 'bzck_settings', [] );
        $model       = $settings['model']       ?? self::MODEL_PRIMARY;
        $temperature = $settings['temperature'] ?? self::TEMPERATURE;
        $max_tokens  = $settings['max_tokens']  ?? self::MAX_TOKENS;

        /* ── Build system prompt ── */
        $system_parts = [];
        $system_parts[] = $this->build_system_prompt( $ctx );

        // Profile context
        $platform_type = ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT';
        $profile_ctx   = $this->get_profile_context( $user_id, $session_id, $platform_type );
        if ( $profile_ctx ) {
            $system_parts[] = $profile_ctx;
        }

        // Knowledge RAG context
        if ( $character_id ) {
            $knowledge_ctx = $this->get_knowledge_context( $character_id, $message, [
                'max_tokens' => 4000,
            ] );
            if ( $knowledge_ctx ) {
                $system_parts[] = "## 📚 KIẾN THỨC LIÊN QUAN (từ Knowledge Base)\n\n" . $knowledge_ctx;
            }
        }

        // Agent knowledge context
        $agent_ctx = $this->get_agent_knowledge_context( $message );
        if ( $agent_ctx ) {
            $system_parts[] = $agent_ctx;
        }

        /* ── Build messages for ChatGPT ── */
        $system_prompt = implode( "\n\n---\n\n", array_filter( $system_parts ) );

        $messages = [
            [ 'role' => 'system',  'content' => $system_prompt ],
        ];

        // Conversation history (last 6 turns)
        $conversation = $ctx['conversation'] ?? [];
        if ( ! empty( $conversation ) ) {
            $history = array_slice( $conversation, -6 );
            foreach ( $history as $turn ) {
                $messages[] = [
                    'role'    => $turn['role'] ?? 'user',
                    'content' => $turn['content'] ?? $turn['message'] ?? '',
                ];
            }
        }

        $messages[] = [ 'role' => 'user', 'content' => $message ];

        /* ── Call ChatGPT via OpenRouter ── */
        $result = $this->call_chatgpt( $messages, $model, $temperature, $max_tokens );

        /* ── Log query to search_history ── */
        if ( $user_id ) {
            $this->log_knowledge_query( $user_id, $message, $result, $model );
        }

        /* ── Return result ── */
        if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
            return [
                'reply'               => $result['message'],
                'action'              => 'reply',
                'system_prompt_parts' => $system_parts,
                'memory'              => [
                    'type'    => 'fact',
                    'content' => mb_substr( $message, 0, 300 ),
                ],
                'meta'                => [
                    'pipeline'    => 'chatgpt-knowledge',
                    'model'       => $result['model'] ?? $model,
                    'tokens'      => $result['usage'] ?? [],
                    'temperature' => $temperature,
                    'provider'    => 'chatgpt',
                ],
            ];
        }

        // Fallback: compose
        return [
            'reply'               => '',
            'action'              => 'compose',
            'system_prompt_parts' => $system_parts,
            'memory'              => [
                'type'    => 'fact',
                'content' => mb_substr( $message, 0, 300 ),
            ],
            'meta'                => [
                'pipeline'       => 'chatgpt-knowledge',
                'fallback'       => true,
                'chatgpt_error'  => $result['error'] ?? 'Unknown error',
                'provider'       => 'chatgpt',
            ],
        ];
    }

    /* ── ChatGPT API call ───────────────────────── */

    private function call_chatgpt( array $messages, $model, $temperature, $max_tokens ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [
                'success' => false,
                'error'   => 'OpenRouter API not available',
                'message' => '',
            ];
        }

        // Stream-first: use streaming when SSE context is active (bizcity_intent_stream_chunk hook)
        $fn_exists = function_exists( 'bizcity_openrouter_chat_stream' );
        $has_hook  = has_action( 'bizcity_intent_stream_chunk' );
        error_log( '[BZCK_PIPELINE] v2 stream_detect: fn=' . (int) $fn_exists . ' hook=' . (int)(bool) $has_hook );

        if ( $fn_exists && $has_hook ) {
            $result = bizcity_openrouter_chat_stream( $messages, [
                'model'       => $model,
                'purpose'     => 'chatgpt-knowledge',
                'temperature' => floatval( $temperature ),
                'max_tokens'  => intval( $max_tokens ),
            ], function ( $delta, $full_text ) {
                do_action( 'bizcity_intent_stream_chunk', $delta, $full_text );
            } );

            // Fallback model if primary fails (non-streaming — rare error path)
            if ( empty( $result['success'] ) && $model === self::MODEL_PRIMARY ) {
                $result = bizcity_openrouter_chat_stream( $messages, [
                    'model'       => self::MODEL_FALLBACK,
                    'purpose'     => 'chatgpt-knowledge-fallback',
                    'temperature' => floatval( $temperature ),
                    'max_tokens'  => intval( $max_tokens ),
                ], function ( $delta, $full_text ) {
                    do_action( 'bizcity_intent_stream_chunk', $delta, $full_text );
                } );
            }

            return $result;
        }

        // Non-streaming fallback (batch, Zalo, Telegram)
        $result = bizcity_openrouter_chat( $messages, [
            'model'       => $model,
            'purpose'     => 'chatgpt-knowledge',
            'temperature' => floatval( $temperature ),
            'max_tokens'  => intval( $max_tokens ),
        ] );

        // Fallback model if primary fails
        if ( empty( $result['success'] ) && $model === self::MODEL_PRIMARY ) {
            $result = bizcity_openrouter_chat( $messages, [
                'model'       => self::MODEL_FALLBACK,
                'purpose'     => 'chatgpt-knowledge-fallback',
                'temperature' => floatval( $temperature ),
                'max_tokens'  => intval( $max_tokens ),
            ] );
        }

        return $result;
    }

    /* ── Logging ────────────────────────────────── */

    private function log_knowledge_query( $user_id, $message, $result, $model ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bzck_search_history';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return;
        }

        $wpdb->insert( $table, [
            'user_id'       => intval( $user_id ),
            'query_text'    => mb_substr( $message, 0, 500 ),
            'answer_text'   => mb_substr( $result['message'] ?? '', 0, 5000 ),
            'provider'      => 'chatgpt',
            'model_used'    => $result['model'] ?? $model,
            'tokens_prompt' => intval( $result['usage']['prompt_tokens'] ?? 0 ),
            'tokens_reply'  => intval( $result['usage']['completion_tokens'] ?? 0 ),
            'is_success'    => ! empty( $result['success'] ) ? 1 : 0,
            'source'        => 'pipeline',
            'created_at'    => current_time( 'mysql' ),
        ] );
    }

    /* ── Agent knowledge helper ─────────────────── */

    private function get_agent_knowledge_context( $message ) {
        if ( ! class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            return '';
        }

        $registry  = BizCity_Intent_Provider_Registry::instance();
        $providers = $registry->get_all();
        $contexts  = [];

        foreach ( $providers as $provider ) {
            $char_id = $provider->get_knowledge_character_id();
            if ( ! $char_id ) continue;

            $goals    = $provider->get_goal_patterns();
            $relevant = false;
            foreach ( $goals as $pattern => $config ) {
                if ( is_string( $pattern ) && @preg_match( $pattern, $message ) ) {
                    $relevant = true;
                    break;
                }
            }
            if ( ! $relevant ) continue;

            $ctx = $this->get_knowledge_context( $char_id, $message, [
                'max_tokens' => 1500,
            ] );
            if ( $ctx ) {
                $contexts[] = "### Agent: " . $provider->get_name() . "\n" . $ctx;
            }
        }

        return $contexts ? implode( "\n\n", $contexts ) : '';
    }

    /* ── System prompt ──────────────────────────── */

    protected function build_system_prompt( array $ctx ) {
        return <<<PROMPT
## CHẾ ĐỘ: CHATGPT KNOWLEDGE — TRẢ LỜI CHI TIẾT

Bạn là trợ lý kiến thức AI chuyên sâu, powered by ChatGPT (OpenAI).

### Nguyên tắc:
1. Trả lời **chi tiết, đầy đủ, có cấu trúc** — không vắn tắt
2. Dùng format: headings, bullet points, bảng, ví dụ cụ thể
3. Nếu có dữ liệu từ Knowledge Base → ưu tiên dùng, ghi nguồn
4. Nếu không chắc → nói rõ "Thông tin này có thể chưa chính xác"
5. Câu trả lời dài 500-2000 từ tùy độ phức tạp
6. Kết thúc bằng tóm tắt ngắn hoặc gợi ý tìm hiểu thêm

### Phong cách:
- Thân thiện, chuyên nghiệp, dễ hiểu
- Chia nhỏ thông tin phức tạp thành các phần rõ ràng
- Dùng emoji nhẹ nhàng để highlight (📌 💡 ⚠️ ✅)
- Trả lời bằng tiếng Việt trừ khi user dùng tiếng Anh
PROMPT;
    }
}
