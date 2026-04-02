<?php
/**
 * BizCity Execution Planner — Pluggable Plan Builder with Provider Adapters
 *
 * Separated from Objective Understanding: this class takes structured intents
 * and builds an executable plan. Supports provider-specific adapters so that
 * e.g. the automation provider can override plan building for workflow domain.
 *
 * Phase 1 Addendum — Execution Planning layer
 *
 * @package BizCity_Intent
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Execution_Planner {

    /** @var self|null */
    private static $instance = null;

    /** @var BizCity_Planner_Adapter_Interface[] Registered provider adapters (id => adapter). */
    private $adapters = [];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Allow providers to register their own planner adapters.
        add_action( 'bizcity_intent_register_planner_adapters', [ $this, 'collect_adapters' ], 10 );
    }

    /**
     * Register a planner adapter.
     *
     * @param string                          $id
     * @param BizCity_Planner_Adapter_Interface $adapter
     */
    public function register_adapter( string $id, $adapter ) {
        $this->adapters[ $id ] = $adapter;
    }

    /**
     * Fire the collection hook so providers can register adapters.
     */
    public function collect_adapters() {
        // Providers hook into 'bizcity_register_planner_adapter' to call register_adapter().
        do_action( 'bizcity_register_planner_adapter', $this );
    }

    /**
     * Build an execution plan from analysis result.
     *
     * @param array $analysis  Output from BizCity_Objective_Understanding::analyze().
     * @param array $context   { user_id, session_id, conversation_slots, channel, message }.
     * @return array {
     *   @type string $pipeline_id
     *   @type string $mode          'package' | 'multi_step' | 'single'
     *   @type string $adapter_used  Which adapter built the plan (or 'core').
     *   @type array  $steps         Ordered steps.
     *   @type int    $step_count
     *   @type array  $analysis      The full analysis object (for trace).
     * }
     */
    public function build_plan( array $analysis, array $context = [] ): array {
        $intents      = $analysis['intents'] ?? [];
        $dependencies = $analysis['dependencies'] ?? [];

        if ( empty( $intents ) ) {
            return [
                'pipeline_id'  => 'pipe_' . wp_generate_password( 8, false ),
                'mode'         => 'single',
                'adapter_used' => 'core',
                'steps'        => [],
                'step_count'   => 0,
                'analysis'     => $analysis,
            ];
        }

        // ── Check if a registered adapter wants to handle this plan ──
        $adapter = $this->find_adapter( $intents, $context );
        if ( $adapter ) {
            $plan = $adapter->build_plan( $intents, $dependencies, $context );
            if ( ! empty( $plan ) ) {
                $plan['analysis']     = $analysis;
                $plan['adapter_used'] = $adapter->get_id();
                return $plan;
            }
        }

        // ── Default: delegate to Core Planner (existing logic) ──
        $core = BizCity_Core_Planner::instance();

        // Convert intents back to the objectives format Core Planner expects.
        $objectives = array_map( function ( $obj ) {
            return [
                'text'       => $obj['text'],
                'tool_hint'  => $obj['tool_hint'],
                'confidence' => $obj['confidence'],
            ];
        }, $intents );

        $plan = $core->build_plan( $objectives, $context );
        $plan['analysis']     = $analysis;
        $plan['adapter_used'] = 'core';

        return $plan;
    }

    /**
     * Find the best adapter for the given intents.
     *
     * Each adapter declares which tool patterns it handles via can_handle().
     *
     * @param array $intents
     * @param array $context
     * @return BizCity_Planner_Adapter_Interface|null
     */
    private function find_adapter( array $intents, array $context ) {
        // Fire collection if not yet done.
        if ( empty( $this->adapters ) ) {
            do_action( 'bizcity_register_planner_adapter', $this );
        }

        foreach ( $this->adapters as $id => $adapter ) {
            if ( $adapter->can_handle( $intents, $context ) ) {
                return $adapter;
            }
        }
        return null;
    }
}

/**
 * Interface for provider-specific planner adapters.
 *
 * Implement this in your provider to override the default Core Planner
 * plan building for your domain (e.g. automation workflow generation).
 *
 * @since 4.2.0
 */
interface BizCity_Planner_Adapter_Interface {

    /** Unique adapter identifier. */
    public function get_id(): string;

    /**
     * Whether this adapter wants to handle the given intents.
     *
     * @param array[] $intents  From Objective Understanding.
     * @param array   $context
     * @return bool
     */
    public function can_handle( array $intents, array $context ): bool;

    /**
     * Build the plan.
     *
     * @param array[] $intents
     * @param array[] $dependencies
     * @param array   $context
     * @return array|null  Plan structure or null to fall back to core.
     */
    public function build_plan( array $intents, array $dependencies, array $context ): ?array;
}
