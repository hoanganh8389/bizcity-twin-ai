<?php
/**
 * ChatGPT Knowledge — Intent Provider.
 *
 * @package BizCity_ChatGPT_Knowledge
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_ChatGPT_Knowledge_Intent_Provider extends BizCity_Intent_Provider {

    public function get_id() {
        return 'chatgpt-knowledge';
    }

    public function get_name() {
        return 'ChatGPT Knowledge — Trợ lý Kiến thức AI';
    }

    public function get_knowledge_character_id() {
        return 0;
    }

    public function get_goal_patterns() {
        return [
            '/h[ỏo]i\s*chatgpt|t[ìi]m\s*hi[ểe]u.*chatgpt|nghi[eê]n\s*c[ứu]u.*chatgpt'
            . '|h[ọo]c.*chatgpt|t[ổo]ng\s*h[ợo]p.*chatgpt|ph[aâ]n\s*t[íi]ch.*chatgpt'
            . '|ask\s*chatgpt|d[uù]ng\s*chatgpt|nh[ờo]\s*chatgpt'
            . '|chatgpt\s*gi[ảa]i\s*th[íi]ch|chatgpt\s*vi[ếe]t|chatgpt\s*t[ạa]o|chatgpt\s*gi[úu]p'
            . '|h[ỏo]i\s*gpt|d[uù]ng\s*gpt|nh[ờo]\s*gpt/ui' => [
                'goal'        => 'ask_chatgpt',
                'label'       => 'Hỏi ChatGPT',
                'description' => 'Dùng ChatGPT (OpenAI) để hỏi, tìm hiểu, nghiên cứu, học tập, tổng hợp, phân tích',
                'extract'     => [ 'question' ],
            ],
        ];
    }

    public function get_plans() {
        return [
            'ask_chatgpt' => [
                'required_slots' => [
                    'question' => [
                        'type'   => 'text',
                        'label'  => 'Câu hỏi / chủ đề cần tìm hiểu cụ thể. KHÔNG phải câu lệnh.',
                        'prompt' => '❓ Bạn muốn hỏi ChatGPT điều gì? Gõ câu hỏi của bạn:',
                    ],
                ],
                'optional_slots' => [],
                'tool'       => 'ask_chatgpt',
                'ai_compose' => false,
                'slot_order' => [ 'question' ],
            ],
        ];
    }

    public function get_tools() {
        return [
            'ask_chatgpt' => [
                'schema' => [
                    'description'  => 'Hỏi ChatGPT (OpenAI) — tìm hiểu, nghiên cứu, học tập, tổng hợp, phân tích',
                    'input_fields' => [
                        'question' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tools_ChatGPT_Knowledge', 'ask_chatgpt' ],
            ],
        ];
    }

    public function get_profile_context( $user_id ) {
        if ( ! $user_id ) {
            return [
                'complete' => true,
                'context'  => '',
                'fallback' => '',
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bzck_search_history';

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

        $pref = get_user_meta( $user_id, 'bzck_preferences', true );
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
        return 'Bạn là trợ lý kiến thức AI chuyên sâu, powered by ChatGPT. Trả lời chi tiết, đầy đủ, có cấu trúc.';
    }

    public function get_owned_goals() {
        return [ 'ask_chatgpt' ];
    }
}
