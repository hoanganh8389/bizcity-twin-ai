<?php
/**
 * BizCity Objective Parser
 *
 * Lightweight parser to detect multi-objective execution requests from a
 * single user message (Option A). Uses Router goal patterns + connectors.
 *
 * @package BizCity_Intent
 * @since   4.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Objective_Parser {

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Parse message into objective list for Core Planner.
     *
     * @param string $message
     * @param array  $intent  Router intent output.
     * @return array {
     *   @type bool  $is_multi
     *   @type array $objectives Array of { text, tool_hint, confidence }
     *   @type array $segments
     * }
     */
    public function parse( string $message, array $intent ): array {
        $normalized = trim( preg_replace( '/\s+/u', ' ', $message ) );
        if ( $normalized === '' ) {
            return [ 'is_multi' => false, 'objectives' => [], 'segments' => [] ];
        }

        // Split by Vietnamese/English sequential connectors.
        $segments = preg_split(
            '/\s*(?:,\s*r[ồo]i|r[ồo]i|sau\s+đ[óo]|ti[ếe]p\s+theo|v[àa]|đ[ồo]ng\s+th[ờo]i|and\s+then|then|and)\s*/ui',
            $normalized,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ( count( $segments ) <= 1 ) {
            return [ 'is_multi' => false, 'objectives' => [], 'segments' => $segments ];
        }

        $router         = class_exists( 'BizCity_Intent_Router' ) ? BizCity_Intent_Router::instance() : null;
        $goal_patterns  = $router ? $router->get_goal_patterns() : [];
        $primary_goal   = $intent['goal'] ?? '';
        $objectives     = [];

        $intent_planner = class_exists( 'BizCity_Intent_Planner' ) ? BizCity_Intent_Planner::instance() : null;

        foreach ( $segments as $idx => $seg ) {
            $seg = trim( $seg );
            if ( $seg === '' ) {
                continue;
            }

            $goal_hint = '';
            // First segment inherits the already-classified primary goal.
            if ( $idx === 0 && $primary_goal ) {
                $goal_hint = $primary_goal;
            }

            // For remaining segments, match router provider patterns.
            if ( ! $goal_hint && ! empty( $goal_patterns ) ) {
                foreach ( $goal_patterns as $pattern => $cfg ) {
                    if ( ! is_string( $pattern ) || @preg_match( $pattern, $seg ) !== 1 ) {
                        continue;
                    }
                    $goal_hint = $cfg['goal'] ?? '';
                    if ( $goal_hint ) {
                        break;
                    }
                }
            }

            if ( $goal_hint ) {
                $tool_hint = $goal_hint;
                if ( $intent_planner ) {
                    $plan_def = $intent_planner->get_plan( $goal_hint );
                    if ( ! empty( $plan_def['tool'] ) ) {
                        $tool_hint = $plan_def['tool'];
                    }
                }

                $objectives[] = [
                    'text'       => $seg,
                    'tool_hint'  => $tool_hint,
                    'confidence' => $idx === 0 ? 0.9 : 0.8,
                ];
            }
        }

        // Deduplicate by tool_hint while preserving first appearance order.
        $dedup = [];
        $seen  = [];
        foreach ( $objectives as $obj ) {
            $hint = $obj['tool_hint'];
            if ( isset( $seen[ $hint ] ) ) {
                continue;
            }
            $seen[ $hint ] = true;
            $dedup[] = $obj;
        }

        return [
            'is_multi'   => count( $dedup ) > 1,
            'objectives' => $dedup,
            'segments'   => $segments,
        ];
    }
}
