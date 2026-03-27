<?php
/**
 * BizCity ChatGPT Knowledge — API Helper.
 *
 * Singleton class used for admin "Ask" page and shortcode.
 *
 * @package BizCity_ChatGPT_Knowledge
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_ChatGPT_Knowledge {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ask ChatGPT a question.
     *
     * @param string $question
     * @param array  $options {
     *   model, temperature, max_tokens, user_id,
     *   ai_context:      string  — Dual context from _meta (layers 2-5)
     *   personality_prefix: string — Personality cue injected into system prompt
     * }
     * @return array { success, answer, model, usage, error }
     */
    public function ask( $question, $options = [] ) {
        if ( empty( $question ) ) {
            return [ 'success' => false, 'error' => 'Empty question', 'answer' => '' ];
        }

        $settings    = $this->get_settings();
        $model       = $options['model']       ?? $settings['model']       ?? BizCity_ChatGPT_Knowledge_Pipeline::MODEL_PRIMARY;
        $temperature = $options['temperature'] ?? $settings['temperature'] ?? BizCity_ChatGPT_Knowledge_Pipeline::TEMPERATURE;
        $max_tokens  = $options['max_tokens']  ?? $settings['max_tokens']  ?? BizCity_ChatGPT_Knowledge_Pipeline::MAX_TOKENS;

        $system_prompt = "Bạn là trợ lý kiến thức AI chuyên sâu — ChatGPT Knowledge.\n"
            . "Trả lời chi tiết, đầy đủ, có cấu trúc.\n"
            . "Dùng headings, bullet points, ví dụ cụ thể.\n"
            . "Trả lời bằng tiếng Việt.";

        // Personality prefix — ngữ cảnh phong cách trả lời
        if ( ! empty( $options['personality_prefix'] ) ) {
            $system_prompt .= "\n\n" . $options['personality_prefix'];
        }

        // Dual context injection (Pattern A — append to system prompt)
        if ( ! empty( $options['ai_context'] ) ) {
            $system_prompt .= "\n\n" . $options['ai_context'];
        }

        // RAG context
        $rag_ctx = bzck_get_knowledge_context( $question, 3000 );
        if ( $rag_ctx ) {
            $system_prompt .= "\n\n## 📚 Kiến thức liên quan:\n" . $rag_ctx;
        }

        $messages = [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user',   'content' => $question ],
        ];

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [ 'success' => false, 'error' => 'OpenRouter API not available', 'answer' => '' ];
        }

        // Stream-first: use streaming when SSE context is active
        $stream_cb = $options['stream_callback'] ?? null;
        $fn_exists = function_exists( 'bizcity_openrouter_chat_stream' );
        $has_hook  = has_action( 'bizcity_intent_stream_chunk' );
        error_log( '[BZCK_ASK] v2 stream_detect: fn=' . (int) $fn_exists . ' hook=' . (int)(bool) $has_hook . ' cb=' . ( $stream_cb ? 1 : 0 ) );

        if ( ! $stream_cb && $fn_exists && $has_hook ) {
            $stream_cb = function ( $delta, $full_text ) {
                do_action( 'bizcity_intent_stream_chunk', $delta, $full_text );
            };
        }

        if ( $stream_cb && function_exists( 'bizcity_openrouter_chat_stream' ) ) {
            $result = bizcity_openrouter_chat_stream( $messages, [
                'model'       => $model,
                'purpose'     => 'chatgpt-knowledge-ask',
                'temperature' => floatval( $temperature ),
                'max_tokens'  => intval( $max_tokens ),
            ], $stream_cb );

            if ( empty( $result['success'] ) && $model === BizCity_ChatGPT_Knowledge_Pipeline::MODEL_PRIMARY ) {
                $result = bizcity_openrouter_chat_stream( $messages, [
                    'model'       => BizCity_ChatGPT_Knowledge_Pipeline::MODEL_FALLBACK,
                    'purpose'     => 'chatgpt-knowledge-ask-fallback',
                    'temperature' => floatval( $temperature ),
                    'max_tokens'  => intval( $max_tokens ),
                ], $stream_cb );
            }
        } else {
            $result = bizcity_openrouter_chat( $messages, [
                'model'       => $model,
                'purpose'     => 'chatgpt-knowledge-ask',
                'temperature' => floatval( $temperature ),
                'max_tokens'  => intval( $max_tokens ),
            ] );

            // Fallback
            if ( empty( $result['success'] ) && $model === BizCity_ChatGPT_Knowledge_Pipeline::MODEL_PRIMARY ) {
                $result = bizcity_openrouter_chat( $messages, [
                    'model'       => BizCity_ChatGPT_Knowledge_Pipeline::MODEL_FALLBACK,
                    'purpose'     => 'chatgpt-knowledge-ask-fallback',
                    'temperature' => floatval( $temperature ),
                    'max_tokens'  => intval( $max_tokens ),
                ] );
            }
        }

        // Log search
        $user_id = $options['user_id'] ?? get_current_user_id();
        if ( $user_id ) {
            $this->log_search( $user_id, $question, $result );
        }

        if ( ! empty( $result['success'] ) ) {
            return [
                'success' => true,
                'answer'  => $result['message'],
                'model'   => $result['model'] ?? $model,
                'usage'   => $result['usage'] ?? [],
            ];
        }

        return [
            'success' => false,
            'error'   => $result['error'] ?? 'ChatGPT API error',
            'answer'  => '',
        ];
    }

    private function log_search( $user_id, $query, $result ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bzck_search_history';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return;
        }

        $wpdb->insert( $table, [
            'user_id'       => intval( $user_id ),
            'query_text'    => mb_substr( $query, 0, 500 ),
            'answer_text'   => mb_substr( $result['message'] ?? '', 0, 5000 ),
            'provider'      => 'chatgpt',
            'model_used'    => $result['model'] ?? '',
            'tokens_prompt' => intval( $result['usage']['prompt_tokens'] ?? 0 ),
            'tokens_reply'  => intval( $result['usage']['completion_tokens'] ?? 0 ),
            'is_success'    => ! empty( $result['success'] ) ? 1 : 0,
            'source'        => 'admin_ask',
            'created_at'    => current_time( 'mysql' ),
        ] );
    }

    public function get_available_models() {
        return BizCity_ChatGPT_Knowledge_Pipeline::MODELS;
    }

    public function get_settings() {
        return get_option( 'bzck_settings', [
            'model'       => BizCity_ChatGPT_Knowledge_Pipeline::MODEL_PRIMARY,
            'temperature' => BizCity_ChatGPT_Knowledge_Pipeline::TEMPERATURE,
            'max_tokens'  => BizCity_ChatGPT_Knowledge_Pipeline::MAX_TOKENS,
        ] );
    }
}
