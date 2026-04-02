<?php
/**
 * BizCity Planner Variant Resolver — A/B/C Testing at Engine Decision Point
 *
 * Hooks into the Intent Engine at the Step 4.4 decision point to select
 * which planner variant should handle the request.
 *
 * Variants:
 *   A = Core Planner (default — regex objective parser → tier sort → scenario gen)
 *   B = Automation Provider (LLM-based workflow JSON with rich prompt)
 *   C = Hybrid (Core Planner for policy/dependency, Automation for JSON gen)
 *
 * Selection can be:
 *   - Auto (round-robin or weighted random)
 *   - Force via admin option `bizcity_planner_variant_force`
 *   - Force per-session via conversation meta
 *
 * Traces variant selection + result into conversation meta for measurement.
 *
 * Phase 1 Addendum — Issue 3: Variant Resolver
 *
 * @package BizCity_Intent
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Planner_Variant_Resolver {

    /** @var self|null */
    private static $instance = null;

    /** Variant identifiers. */
    const VARIANT_A = 'core_planner';
    const VARIANT_B = 'automation_provider';
    const VARIANT_C = 'hybrid';

    /** Admin option key for force override. */
    const OPTION_FORCE = 'bizcity_planner_variant_force';

    /** Default weights for auto selection (A:B:C). */
    const DEFAULT_WEIGHTS = [ 'core_planner' => 50, 'automation_provider' => 30, 'hybrid' => 20 ];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Resolve which planner variant to use.
     *
     * @param array $analysis  From BizCity_Objective_Understanding::analyze().
     * @param array $context   { user_id, session_id, conversation_id, channel }.
     * @return array {
     *   @type string $variant   One of VARIANT_A, VARIANT_B, VARIANT_C.
     *   @type string $reason    Why this variant was selected.
     *   @type string $method    'force_admin' | 'force_session' | 'auto'.
     * }
     */
    public function resolve( array $analysis, array $context = [] ): array {
        // 1. Check admin force (highest priority).
        $force = get_option( self::OPTION_FORCE, '' );
        if ( $force && in_array( $force, [ self::VARIANT_A, self::VARIANT_B, self::VARIANT_C ], true ) ) {
            return [
                'variant' => $force,
                'reason'  => 'Admin forced variant: ' . $force,
                'method'  => 'force_admin',
            ];
        }

        // 2. Check session-level force (via conversation slots).
        $session_force = $context['conversation_slots']['_planner_variant'] ?? '';
        if ( $session_force && in_array( $session_force, [ self::VARIANT_A, self::VARIANT_B, self::VARIANT_C ], true ) ) {
            return [
                'variant' => $session_force,
                'reason'  => 'Session forced variant: ' . $session_force,
                'method'  => 'force_session',
            ];
        }

        // 3. Check if automation provider is available.
        $has_automation = class_exists( 'WaicWorkflowExecuteAPI' );
        if ( ! $has_automation ) {
            return [
                'variant' => self::VARIANT_A,
                'reason'  => 'Automation provider not available, falling back to core.',
                'method'  => 'auto',
            ];
        }

        // 4. Auto selection: weighted random.
        $variant = $this->weighted_select();

        return [
            'variant' => $variant,
            'reason'  => 'Auto-selected by weighted random.',
            'method'  => 'auto',
        ];
    }

    /**
     * Weighted random selection based on configured weights.
     *
     * @return string Variant identifier.
     */
    private function weighted_select(): string {
        $weights = get_option( 'bizcity_planner_variant_weights', self::DEFAULT_WEIGHTS );
        if ( ! is_array( $weights ) || empty( $weights ) ) {
            $weights = self::DEFAULT_WEIGHTS;
        }

        $total = array_sum( $weights );
        if ( $total <= 0 ) {
            return self::VARIANT_A;
        }

        $rand = wp_rand( 1, $total );
        $cumulative = 0;
        foreach ( $weights as $variant => $weight ) {
            $cumulative += (int) $weight;
            if ( $rand <= $cumulative ) {
                return $variant;
            }
        }

        return self::VARIANT_A;
    }

    /**
     * Save variant trace into conversation meta for measurement.
     *
     * @param int    $conv_id
     * @param array  $variant_info  From resolve().
     * @param array  $plan_result   Plan execution result.
     * @param object $conversation_mgr  BizCity_Intent_Conversation instance.
     */
    public function trace_variant( $conv_id, array $variant_info, array $plan_result, $conversation_mgr ) {
        if ( ! $conv_id || ! $conversation_mgr || ! method_exists( $conversation_mgr, 'update_slots' ) ) {
            return;
        }

        $conversation_mgr->update_slots( $conv_id, [
            '_planner_variant_used'   => $variant_info['variant'],
            '_planner_variant_method' => $variant_info['method'],
            '_planner_variant_reason' => $variant_info['reason'],
            '_planner_variant_time'   => current_time( 'mysql' ),
            '_planner_plan_success'   => ! empty( $plan_result['success'] ) ? '1' : '0',
        ] );
    }

    /**
     * Get available variants for admin UI.
     *
     * @return array [ 'value' => 'label' ]
     */
    public static function get_variant_options(): array {
        return [
            ''                       => '🔄 Tự động (A/B/C weighted)',
            self::VARIANT_A          => '🅰️ Core Planner (regex → tier sort → scenario)',
            self::VARIANT_B          => '🅱️ Automation Provider (LLM workflow JSON)',
            self::VARIANT_C          => '🔀 Hybrid (Core policy + Automation JSON)',
        ];
    }
}
