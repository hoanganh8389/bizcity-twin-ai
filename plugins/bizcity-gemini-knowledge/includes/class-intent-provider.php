<?php
/**
 * Gemini Knowledge — Intent Provider.
 *
 * Registers as an intent provider so the knowledge agent can:
 *  - Provide profile context
 *  - Provide knowledge character bindings
 *  - Register knowledge-related goal patterns
 *
 * This provider does NOT register execution goals (no tools/plans).
 * It primarily exists to integrate with the knowledge pipeline and
 * provide supplementary context.
 *
 * @package BizCity_Gemini_Knowledge
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Gemini_Knowledge_Intent_Provider extends BizCity_Intent_Provider {

    public function get_id() {
        return 'gemini-knowledge';
    }

    public function get_name() {
        return 'Gemini Knowledge — Trợ lý Kiến thức AI';
    }

    /**
     * Knowledge character ID for RAG binding.
     * Returns 0 — the knowledge pipeline itself handles RAG via the main character.
     */
    public function get_knowledge_character_id() {
        return 0;
    }

    /**
     * No execution goals — knowledge is handled by the pipeline, not intent router.
     */
    public function get_goal_patterns() {
        return [
            '/h[ỏo]i\s*gemini|t[ìi]m\s*hi[ểe]u.*gemini|nghi[eê]n\s*c[ứu]u.*gemini'
            . '|h[ọo]c.*gemini|t[ổo]ng\s*h[ợo]p.*gemini|ph[aâ]n\s*t[íi]ch.*gemini'
            . '|ask\s*gemini|d[uù]ng\s*gemini|nh[ờo]\s*gemini|google\s*gemini'
            . '|gemini\s*gi[ảa]i\s*th[íi]ch|gemini\s*vi[ếe]t|gemini\s*t[ạa]o|gemini\s*gi[úu]p/ui' => [
                'goal'        => 'ask_gemini',
                'label'       => 'Hỏi Gemini',
                'description' => 'Dùng Google Gemini để hỏi, tìm hiểu, nghiên cứu, học tập, tổng hợp, phân tích',
                'extract'     => [ 'question' ],
            ],
        ];
    }

    public function get_plans() {
        return [
            'ask_gemini' => [
                'required_slots' => [
                    'question' => [
                        'type'   => 'text',
                        'label'  => 'Câu hỏi / chủ đề cần tìm hiểu cụ thể. KHÔNG phải câu lệnh.',
                        'prompt' => '❓ Bạn muốn hỏi Gemini điều gì? Gõ câu hỏi của bạn:',
                    ],
                ],
                'optional_slots' => [],
                'tool'       => 'ask_gemini',
                'ai_compose' => false,
                'slot_order' => [ 'question' ],
            ],
        ];
    }

    public function get_tools() {
        return [
            'ask_gemini' => [
                'schema' => [
                    'description'  => 'Hỏi Google Gemini — tìm hiểu, nghiên cứu, học tập, tổng hợp, phân tích',
                    'input_fields' => [
                        'question' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tools_Gemini_Knowledge', 'ask_gemini' ],
            ],
        ];
    }

    /**
     * Profile context for knowledge queries.
     * Returns user preferences and search history context.
     */
    public function get_profile_context( $user_id ) {
        if ( ! $user_id ) {
            return [
                'complete' => true,
                'context'  => '',
                'fallback' => '',
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bzgk_search_history';

        // Get user's recent search topics for context
        $recent_searches = [];
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
            $recent_searches = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT query_text FROM {$table}
                 WHERE user_id = %d
                 ORDER BY created_at DESC LIMIT 10",
                $user_id
            ) );
        }

        $ctx_parts = [];

        // User preferences
        $pref = get_user_meta( $user_id, 'bzgk_preferences', true );
        if ( $pref && is_array( $pref ) ) {
            if ( ! empty( $pref['preferred_language'] ) ) {
                $ctx_parts[] = "Ngôn ngữ ưa thích: {$pref['preferred_language']}";
            }
            if ( ! empty( $pref['expertise_level'] ) ) {
                $levels = [ 'beginner' => 'Cơ bản', 'intermediate' => 'Trung cấp', 'advanced' => 'Nâng cao' ];
                $ctx_parts[] = "Trình độ: " . ( $levels[ $pref['expertise_level'] ] ?? $pref['expertise_level'] );
            }
            if ( ! empty( $pref['interests'] ) ) {
                $ctx_parts[] = "Lĩnh vực quan tâm: {$pref['interests']}";
            }
        }

        // Recent search context
        if ( $recent_searches ) {
            $ctx_parts[] = "Chủ đề đã hỏi gần đây: " . implode( ', ', array_slice( $recent_searches, 0, 5 ) );
        }

        $context = $ctx_parts ? implode( "\n", $ctx_parts ) : '';

        return [
            'complete' => true,
            'context'  => $context,
            'fallback' => '',
        ];
    }

    public function build_context( $goal, array $slots, $user_id, array $conversation ) {
        return '';
    }

    public function get_system_instructions( $goal ) {
        return 'Bạn là trợ lý kiến thức AI chuyên sâu, powered by Gemini. Trả lời chi tiết, đầy đủ, có cấu trúc.';
    }

    public function get_owned_goals() {
        return [ 'ask_gemini' ];
    }
}
