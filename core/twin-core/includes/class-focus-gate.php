<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Focus Gate — Static Context Gate on Filter Chain
 *
 * Hooks at priority 1 on bizcity_chat_system_prompt.
 * Resolves focus profile BEFORE any context injector runs.
 * Other filters call BizCity_Focus_Gate::should_inject($layer) to decide.
 *
 * @package  BizCity_Twin_Core
 * @version  0.1.0
 * @since    2026-03-22
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Focus_Gate {

    /** @var array|null Resolved focus profile for current request */
    private static $focus_profile = null;

    /** @var array Astrology strong patterns (high confidence) */
    private static $astro_strong = array(
        '/vận mệnh|tử vi|lá số|chiêm tinh|horoscope|natal chart/ui',
        '/transit.*hành tinh|hành tinh.*transit/ui',
        '/xem sao|giải đoán|luận giải/ui',
        '/nhà (?:1[0-2]|[1-9])(?:\s|$)/ui',
    );

    /** @var array Astrology weak patterns (need context) */
    private static $astro_weak = array(
        '/bạch dương|kim ngưu|song tử|cự giải|sư tử|xử nữ/ui',
        '/thiên bình|bọ cạp|nhân mã|ma kết|bảo bình|song ngư/ui',
        '/cung [a-zA-ZÀ-ỹ]+/ui',
    );

    /**
     * Ensure focus profile is resolved BEFORE inline gate checks.
     *
     * Call this at the START of build_system_prompt() so that
     * should_inject() returns correct values for inline checks
     * (transit, astro_rules) which run BEFORE apply_filters.
     *
     * Safe to call multiple times — skips if already resolved.
     *
     * @param string $message  User message text
     * @param array  $args     Same args as filter callback (user_id, session_id, etc.)
     */
    public static function ensure_resolved( string $message = '', array $args = [] ): void {
        if ( self::$focus_profile !== null ) {
            return; // already resolved this request
        }
        self::resolve_profile( $message, $args );
    }

    /**
     * Filter callback at priority 1 on bizcity_chat_system_prompt.
     * Resolves focus profile BEFORE all other injectors.
     *
     * @param string $prompt Current system prompt
     * @param array  $args   Filter arguments
     * @return string Unchanged prompt (gate only observes)
     */
    public static function gate_context( $prompt, $args ) {
        if ( self::$focus_profile !== null ) {
            return $prompt; // already resolved via ensure_resolved()
        }
        $message = $args['message'] ?? '';
        self::resolve_profile( $message, $args );
        return $prompt; // pass-through — gate only resolves, does not modify
    }

    /**
     * Internal: resolve the focus profile from message + args.
     */
    private static function resolve_profile( string $message, array $args ): void {
        $mode    = $args['mode'] ?? '';
        $meta    = $args['meta'] ?? [];

        // If mode not passed (e.g. from Chat Gateway), classify on the fly
        if ( empty( $mode ) && class_exists( 'BizCity_Mode_Classifier' ) ) {
            $result = BizCity_Mode_Classifier::instance()->classify( $message );
            $mode   = $result['mode'] ?? 'ambiguous';
            $meta   = $result['meta'] ?? [];
        }

        self::$focus_profile = BizCity_Focus_Router::resolve( [
            'mode'           => $mode,
            'message'        => $message,
            'meta'           => $meta,
            'user_id'        => $args['user_id'] ?? 0,
            'session_id'     => $args['session_id'] ?? '',
            'project_id'     => $args['project_id'] ?? '',
            'platform_type'  => $args['platform_type'] ?? '',
            'has_images'     => ! empty( $args['images'] ),
            'routing_branch' => $args['routing_branch'] ?? '',
            'active_goal'    => $args['active_goal'] ?? '',
        ] );

        // ── Twin Trace: profile resolved → SSE → browser console ──
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            $source = empty( $args['mode'] ?? '' ) ? 'classifier' : 'args';
            BizCity_Twin_Trace::profile_resolved( $mode, $source, self::$focus_profile );
        }

        // Log for admin debug console
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'           => 'twin_focus_gate',
                'message'        => 'Focus profile resolved',
                'mode'           => $mode,
                'routing_branch' => $args['routing_branch'] ?? '',
                'platform_type'  => $args['platform_type'] ?? '',
                'focus_profile'  => self::$focus_profile,
                'file_line'      => 'class-focus-gate.php::resolve_profile',
            ], $args['session_id'] ?? '' );
        }
    }

    /**
     * Get the resolved focus profile.
     *
     * @return array|null
     */
    public static function get_focus_profile(): ?array {
        return self::$focus_profile;
    }

    /**
     * Check if a specific context layer should be injected.
     *
     * Returns true (inject) when:
     *   - Twin Core not active (no focus profile → backward compatible)
     *   - Layer value is truthy (true, 'light', 'relevant', 'sources', etc.)
     *
     * Returns false (skip) when:
     *   - Layer value is exactly false or empty
     *
     * @param string $layer Layer name (astro, transit, companion, etc.)
     * @return bool
     */
    public static function should_inject( string $layer ): bool {
        $fp = self::$focus_profile;
        if ( ! $fp ) {
            return true; // fallback: inject all = backward compatible behavior
        }
        if ( ! array_key_exists( $layer, $fp ) ) {
            $allowed = true; // unknown layer → inject (safe default)
        } else {
            $allowed = ! empty( $fp[ $layer ] ) && $fp[ $layer ] !== false;
        }

        // ── Twin Trace: gate decision → SSE → browser console ──
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            $reason = ! $fp ? 'no profile (fallback)'
                : ( ! array_key_exists( $layer, $fp ) ? 'unknown layer (fallback)'
                : ( $allowed ? 'profile allows' : 'profile blocks' ) );
            BizCity_Twin_Trace::gate( $layer, $allowed, $reason );
        }

        return $allowed;
    }

    /**
     * Get the memory mode from focus profile.
     *
     * @return string 'all' | 'relevant' | 'explicit'
     */
    public static function get_memory_mode(): string {
        $fp = self::$focus_profile;
        if ( ! $fp ) {
            return 'all';
        }
        return $fp['memory'] ?? 'all';
    }

    /**
     * Get the response rules type from focus profile.
     *
     * @return string 'general' | 'tool' | 'planner' | 'studio'
     */
    public static function get_response_rules(): string {
        $fp = self::$focus_profile;
        if ( ! $fp ) {
            return 'general';
        }
        return $fp['response_rules'] ?? 'general';
    }

    /**
     * Amend focus profile after intent engine provides the active goal.
     *
     * Call this AFTER ensure_resolved() when engine_result becomes available.
     * If the goal is an astro goal (bizcoach_consult, etc.), re-enables
     * astro/transit/coaching even when execution mode suppressed them.
     *
     * Safe to call multiple times — no-op if no profile or goal isn't astro.
     *
     * @param string $active_goal The goal from engine_result
     */
    public static function amend_for_goal( string $active_goal ): void {
        if ( self::$focus_profile === null || empty( $active_goal ) ) {
            return;
        }
        if ( class_exists( 'BizCity_Focus_Router' ) && BizCity_Focus_Router::is_astro_goal( $active_goal ) ) {
            self::$focus_profile['astro']    = true;
            self::$focus_profile['transit']  = true;
            self::$focus_profile['coaching'] = true;

            if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                BizCity_Twin_Trace::log( 'goal_amend', [
                    'goal'   => $active_goal,
                    'result' => 'astro+transit+coaching re-enabled',
                ] );
            }
        }
    }

    /**
     * Detect astrology content in user message.
     *
     * @param string $message User message text.
     * @return array { is_astrology: bool, confidence: float, method: string }
     */
    public static function detect_astrology( $message ) {
        if ( empty( $message ) ) {
            return array( 'is_astrology' => false, 'confidence' => 0.0, 'method' => 'empty' );
        }

        // Strong match → high confidence
        foreach ( self::$astro_strong as $p ) {
            if ( preg_match( $p, $message ) ) {
                return array( 'is_astrology' => true, 'confidence' => 0.95, 'method' => 'strong_pattern' );
            }
        }

        // Weak matches — count how many hit
        $weak_count = 0;
        foreach ( self::$astro_weak as $p ) {
            if ( preg_match( $p, $message ) ) {
                $weak_count++;
            }
        }

        if ( $weak_count >= 2 ) {
            return array( 'is_astrology' => true, 'confidence' => 0.75, 'method' => 'multi_weak' );
        }

        if ( $weak_count === 1 ) {
            return array( 'is_astrology' => false, 'confidence' => 0.40, 'method' => 'single_weak_defer' );
        }

        return array( 'is_astrology' => false, 'confidence' => 0.05, 'method' => 'no_match' );
    }

    /**
     * Reset focus profile (for testing or between requests).
     */
    public static function reset(): void {
        self::$focus_profile = null;
    }
}
