<?php
/**
 * BizCity Focus Router — 6-Mode Focus Profile Resolver
 *
 * Determines which context layers should be injected based on:
 *   - Classified mode (emotion/reflection/knowledge/planning/execution/studio)
 *   - Topic detection (cheap regex, not LLM)
 *   - Platform type (ADMINCHAT, WEBCHAT, NOTEBOOK)
 *   - Routing branch (compose, tool, memory, etc.)
 *
 * Output: focus_profile array — boolean/enum/string per context layer.
 *
 * @package  BizCity_Twin_Core
 * @version  0.1.0
 * @since    2026-03-22
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Focus_Router {

    /**
     * Resolve focus profile for current request.
     *
     * @param array $params {
     *   mode:            string  (emotion|reflection|knowledge|planning|execution|studio|ambiguous)
     *   message:         string  User's current message
     *   meta:            array   From mode classifier
     *   user_id:         int
     *   session_id:      string
     *   project_id:      string
     *   platform_type:   string  (ADMINCHAT|WEBCHAT|NOTEBOOK)
     *   has_images:      bool
     *   routing_branch:  string  (compose|tool|memory|...)
     * }
     * @return array focus_profile
     */
    public static function resolve( array $params ): array {
        $mode     = $params['mode'] ?? 'ambiguous';
        $message  = $params['message'] ?? '';
        $branch   = $params['routing_branch'] ?? '';
        $platform = $params['platform_type'] ?? 'ADMINCHAT';
        $meta     = $params['meta'] ?? [];

        // Start with mode defaults (6 modes)
        $profile = self::get_mode_defaults( $mode );

        // Store original message in profile for downstream topic matching
        $profile['_message'] = $message;
        $profile['_mode']    = $mode;

        // Topic-based overrides for conditional layers
        if ( $profile['astro'] === 'topic' ) {
            $profile['astro'] = self::is_astro_topic( $message );
        }
        if ( $profile['transit'] === 'topic' ) {
            $profile['transit'] = self::is_astro_topic( $message );
        }
        if ( $profile['coaching'] === 'topic' ) {
            $profile['coaching'] = self::is_coaching_topic( $message );
        }

        // Execution: suppress everything non-essential
        if ( $mode === 'execution' || $branch === 'tool' ) {
            $profile['astro']         = false;
            $profile['transit']       = false;
            $profile['coaching']      = false;
            $profile['companion']     = false;
            $profile['cross_session'] = false;
            $profile['response_rules'] = 'tool';
            $profile['token_budget']  = 3000;
        }

        // Astro goal override: bizcoach-map goals NEED astro/transit even in execution mode
        $active_goal = $params['active_goal'] ?? $meta['goal'] ?? '';
        if ( $active_goal && self::is_astro_goal( $active_goal ) ) {
            $profile['astro']    = true;
            $profile['transit']  = true;
            $profile['coaching'] = true;
        }

        // NOTEBOOK platform
        if ( $platform === 'NOTEBOOK' ) {
            $profile['project']   = true;
            $profile['notes']     = true;
            $profile['companion'] = 'light';
            $profile['astro']     = false;
            $profile['transit']   = false;
        }

        // WEBCHAT platform — customer support widget, NO personal AI companion features
        if ( $platform === 'WEBCHAT' ) {
            $profile['astro']             = false;
            $profile['transit']           = false;
            $profile['coaching']          = false;
            $profile['companion']         = false;
            $profile['relationship']      = false;
            $profile['emotional_threads'] = false;
            $profile['cross_session']     = false;
            $profile['project']           = false;
            $profile['notes']             = false;
            $profile['open_loops']        = false;
            $profile['journeys']          = false;
            $profile['knowledge']           = true;
            $profile['notes']     = true;
            $profile['token_budget']      = 2000;
        }

        // ── Channel Role override (after platform blocks) ──
        $channel_role = $params['channel_role'] ?? [];
        if ( ! empty( $channel_role['focus_override'] ) && is_array( $channel_role['focus_override'] ) ) {
            foreach ( $channel_role['focus_override'] as $layer => $value ) {
                $profile[ $layer ] = $value;
            }
        }

        // ── Debug: log resolved profile for traceability ──
        error_log( sprintf(
            '[FocusRouter] resolve | platform=%s | mode=%s | branch=%s | knowledge=%s | notes=%s | astro=%s | transit=%s | coaching=%s | companion=%s | token_budget=%s',
            $platform,
            $mode,
            $branch ?: '(none)',
            var_export( $profile['knowledge'] ?? null, true ),
            var_export( $profile['notes'] ?? null, true ),
            var_export( $profile['astro'] ?? null, true ),
            var_export( $profile['transit'] ?? null, true ),
            var_export( $profile['coaching'] ?? null, true ),
            var_export( $profile['companion'] ?? null, true ),
            $profile['token_budget'] ?? '?'
        ) );

        // Memory save: suppress heavy context
        if ( $branch === 'memory' || ( $meta['is_memory'] ?? false ) ) {
            $profile['astro']        = false;
            $profile['transit']      = false;
            $profile['knowledge']    = false;
            $profile['token_budget'] = 2000;
        }

        return apply_filters( 'bizcity_twin_focus_profile', $profile, $params );
    }

    /**
     * Get default focus profile for a given mode.
     *
     * @param string $mode
     * @return array
     */
    private static function get_mode_defaults( string $mode ): array {
        $defaults = [
            'emotion' => [
                'identity'          => true,
                'relationship'      => true,
                'emotional_threads' => true,
                'astro'             => 'topic',
                'transit'           => false,
                'coaching'          => 'topic',
                'memory'            => 'relevant',
                'companion'         => true,
                'knowledge'         => false,
                'session'           => true,
                'cross_session'     => false,
                'project'           => false,
                'notes'             => false,
                'focus_current'     => false,
                'open_loops'        => false,
                'journeys'          => false,
                'response_rules'    => 'general',
                'token_budget'      => 4000,
            ],
            'reflection' => [
                'identity'          => true,
                'relationship'      => true,
                'emotional_threads' => true,
                'astro'             => 'topic',
                'transit'           => false,
                'coaching'          => 'topic',
                'memory'            => 'relevant',
                'companion'         => true,
                'knowledge'         => false,
                'session'           => true,
                'cross_session'     => true,
                'project'           => false,
                'notes'             => false,
                'focus_current'     => 'light',
                'open_loops'        => 'light',
                'journeys'          => true,
                'response_rules'    => 'general',
                'token_budget'      => 4000,
            ],
            'knowledge' => [
                'identity'          => 'light',
                'relationship'      => false,
                'emotional_threads' => false,
                'astro'             => 'topic',
                'transit'           => 'topic',
                'coaching'          => 'topic',
                'memory'            => 'relevant',
                'companion'         => 'light',
                'knowledge'         => true,
                'session'           => true,
                'cross_session'     => false,
                'project'           => true,
                'notes'             => 'light',
                'focus_current'     => false,
                'open_loops'        => false,
                'journeys'          => false,
                'response_rules'    => 'general',
                'token_budget'      => 6000,
            ],
            'planning' => [
                'identity'          => 'light',
                'relationship'      => false,
                'emotional_threads' => false,
                'astro'             => false,
                'transit'           => false,
                'coaching'          => false,
                'memory'            => 'relevant',
                'companion'         => false,
                'knowledge'         => 'if_needed',
                'session'           => 'compact',
                'cross_session'     => false,
                'project'           => true,
                'notes'             => false,
                'focus_current'     => true,
                'open_loops'        => true,
                'journeys'          => true,
                'response_rules'    => 'planner',
                'token_budget'      => 4000,
            ],
            'execution' => [
                'identity'          => 'minimal',
                'relationship'      => false,
                'emotional_threads' => false,
                'astro'             => false,
                'transit'           => false,
                'coaching'          => false,
                'memory'            => 'explicit',
                'companion'         => false,
                'knowledge'         => false,
                'session'           => 'compact',
                'cross_session'     => false,
                'project'           => 'if_needed',
                'notes'             => false,
                'focus_current'     => true,
                'open_loops'        => false,
                'journeys'          => false,
                'response_rules'    => 'tool',
                'token_budget'      => 3000,
            ],
            'studio' => [
                'identity'          => 'light',
                'relationship'      => false,
                'emotional_threads' => false,
                'astro'             => false,
                'transit'           => false,
                'coaching'          => false,
                'memory'            => 'relevant',
                'companion'         => false,
                'knowledge'         => 'sources',
                'session'           => false,
                'cross_session'     => false,
                'project'           => true,
                'notes'             => true,
                'focus_current'     => true,
                'open_loops'        => false,
                'journeys'          => 'alignment',
                'response_rules'    => 'studio',
                'token_budget'      => 5000,
            ],
        ];

        // Ambiguous → fallback to knowledge (safe default)
        return $defaults[ $mode ] ?? $defaults['knowledge'];
    }

    /**
     * Cheap regex — detect astro/tarot/numerology topic.
     *
     * @param string $message
     * @return bool
     */
    private static function is_astro_topic( string $message ): bool {
        // Chỉ detect astro khi message chứa DOMAIN KEYWORD rõ ràng.
        // KHÔNG dùng generic time/question patterns (hôm nay thế nào, ngày mai ra sao...)
        // vì chúng match MỌI câu hỏi cá nhân → inject astro context sai.
        $patterns = [
            '/chiêm tinh|cung .{2,10}(hoàng đạo|mặt trời|mặt trăng)/ui',
            '/tử vi|lá số|bản đồ sao|natal|transit|horoscope/ui',
            '/sao (mộc|thổ|hỏa|kim|thủy|thiên vương|hải vương)/ui',
            '/thần số học|numerology|con số chủ đạo/ui',
            '/tarot|bói bài|lá bài/ui',
            // Vận mệnh + lĩnh vực cụ thể (đã có domain anchor "vận mệnh/dự báo/vận hạn")
            '/(?:vận mệnh|dự báo vận|vận hạn).{0,30}(?:tài chính|sự nghiệp|tình cảm|sức khỏe|công việc|tình duyên|hôn nhân)/ui',
            '/phong thủy|xem vận|bói (?:toán|quẻ)|cung hoàng đạo/ui',
        ];
        foreach ( $patterns as $p ) {
            if ( preg_match( $p, $message ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cheap regex — detect coaching/mentoring topic.
     *
     * @param string $message
     * @return bool
     */
    private static function is_coaching_topic( string $message ): bool {
        $patterns = [
            '/tư vấn (cá nhân|coaching|mentoring)/ui',
            '/swot|phân tích (bản thân|cá nhân)/ui',
            '/mục tiêu (cá nhân|sống|sự nghiệp)/ui',
        ];
        foreach ( $patterns as $p ) {
            if ( preg_match( $p, $message ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an active goal belongs to bizcoach-map (needs astro/transit).
     *
     * @param string $goal
     * @return bool
     */
    public static function is_astro_goal( string $goal ): bool {
        $astro_goals = [
            'bizcoach_consult',
            'create_natal_chart',
            'create_transit_map',
            'tarot_reading',
        ];
        return in_array( $goal, $astro_goals, true );
    }
}
