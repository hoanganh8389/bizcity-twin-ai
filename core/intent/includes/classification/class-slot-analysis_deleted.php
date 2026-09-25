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
 * BizCity Slot Analysis — Post-classification slot visibility step.
 *
 * Runs AFTER Mode Classifier (which includes unified LLM classification),
 * BEFORE Router intent classification. This makes filled_slots / missing_slots
 * visible as a separate pipeline step in the Working Console for debugging.
 *
 * Responsibilities:
 *   1. Extract filled_slots + missing_slots from Mode Classifier's unified LLM result.
 *   2. Cross-reference against the actual tool schema (required_slots, optional_slots).
 *   3. Compute task completion metrics: fill_ratio, missing_count.
 *   4. Log as step 'slot_analyze' in the admin AJAX Console.
 *
 * Data flow:
 *   mode_classify → slot_analyze → intensity_detect → intent_classify → plan → execute
 *
 * @package BizCity_Intent
 * @since   3.5.2
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Slot_Analysis {

    /** @var self|null */
    private static $instance = null;

    /**
     * Singleton accessor.
     *
     * @return self
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Analyze slot completeness based on unified LLM classification result.
     *
     * Called from Engine after mode_classify, before intent_classify.
     * Only meaningful when mode=execution and a goal was identified.
     *
     * @param array       $mode_result   Mode classifier result (with meta.intent_result).
     * @param string      $message       The user's message.
     * @param array|null  $conversation  Active conversation data.
     * @return array {
     *     @type bool   $has_analysis   Whether slot analysis was performed.
     *     @type string $goal           Identified goal (or empty).
     *     @type string $goal_label     Human-readable goal label.
     *     @type string $intent         Intent type (new_goal, provide_input, etc).
     *     @type array  $entities       Extracted entity values from LLM.
     *     @type array  $filled_slots   Names of slots that have values.
     *     @type array  $missing_slots  Names of required slots still missing.
     *     @type array  $schema         Goal's full slot schema {required: [], optional: []}.
     *     @type int    $total_required Total number of required slots for this goal.
     *     @type int    $filled_count   Number of filled slots (required or optional).
     *     @type int    $missing_count  Number of missing REQUIRED slots.
     *     @type float  $fill_ratio     Ratio of filled required slots (0.0 - 1.0).
     *     @type string $status         'complete' | 'partial' | 'empty' | 'no_goal'.
     *     @type string $summary        Human-readable summary line.
     * }
     */
    public function analyze( array $mode_result, string $message, ?array $conversation = null ): array {
        $empty = [
            'has_analysis'   => false,
            'goal'           => '',
            'goal_label'     => '',
            'intent'         => '',
            'entities'       => [],
            'filled_slots'   => [],
            'missing_slots'  => [],
            'schema'         => [ 'required' => [], 'optional' => [] ],
            'total_required' => 0,
            'filled_count'   => 0,
            'missing_count'  => 0,
            'fill_ratio'     => 0.0,
            'status'         => 'no_goal',
            'summary'        => 'No execution goal identified',
        ];

        // ── Only analyze when mode = execution with intent_result ──
        $intent_result = $mode_result['meta']['intent_result'] ?? null;
        if ( empty( $intent_result ) || empty( $intent_result['goal'] ) ) {
            return $empty;
        }

        $goal       = $intent_result['goal'];
        $goal_label = $intent_result['goal_label'] ?? $goal;
        $intent     = $intent_result['intent'] ?? 'new_goal';
        $entities   = is_array( $intent_result['entities'] ?? null ) ? $intent_result['entities'] : [];

        // LLM-reported slots
        $llm_filled  = is_array( $intent_result['filled_slots'] ?? null ) ? $intent_result['filled_slots'] : [];
        $llm_missing = is_array( $intent_result['missing_slots'] ?? null ) ? $intent_result['missing_slots'] : [];

        // ── Fetch authoritative schema from Tool Index DB ──
        $schema = $this->get_goal_schema( $goal );

        // ── Cross-reference: validate LLM slots against actual schema ──
        $required_names = array_keys( $schema['required'] );
        $optional_names = array_keys( $schema['optional'] );
        $all_slot_names = array_merge( $required_names, $optional_names );

        // Validated filled: must be in schema AND have entity value
        $validated_filled = [];
        foreach ( $llm_filled as $slot_name ) {
            if ( in_array( $slot_name, $all_slot_names, true ) && ! empty( $entities[ $slot_name ] ) ) {
                $validated_filled[] = $slot_name;
            }
        }

        // Also check entities that LLM didn't list in filled_slots but DID extract
        foreach ( $entities as $key => $val ) {
            if ( str_starts_with( $key, '_' ) ) continue; // skip internal keys
            if ( in_array( $key, $all_slot_names, true ) && ! empty( $val ) && ! in_array( $key, $validated_filled, true ) ) {
                $validated_filled[] = $key;
            }
        }

        // Validated missing: required slots not filled
        $validated_missing = array_diff( $required_names, $validated_filled );

        // ── Compute metrics ──
        $total_required = count( $required_names );
        $filled_count   = count( $validated_filled );
        $missing_count  = count( $validated_missing );

        $fill_ratio = $total_required > 0
            ? round( ( $total_required - $missing_count ) / $total_required, 2 )
            : 1.0;

        // Status
        if ( $total_required === 0 || $missing_count === 0 ) {
            $status = 'complete';
        } elseif ( $filled_count > 0 ) {
            $status = 'partial';
        } else {
            $status = 'empty';
        }

        // Summary line
        $summary = sprintf(
            '%s → %s | filled: %s | missing: %s | %d/%d required (%.0f%%)',
            $intent,
            $goal,
            $validated_filled ? implode( ', ', $validated_filled ) : '(none)',
            $validated_missing ? implode( ', ', array_values( $validated_missing ) ) : '(none)',
            $total_required - $missing_count,
            $total_required,
            $fill_ratio * 100
        );

        return [
            'has_analysis'   => true,
            'goal'           => $goal,
            'goal_label'     => $goal_label,
            'intent'         => $intent,
            'entities'       => $entities,
            'filled_slots'   => $validated_filled,
            'missing_slots'  => array_values( $validated_missing ),
            'schema'         => $schema,
            'total_required' => $total_required,
            'filled_count'   => $filled_count,
            'missing_count'  => $missing_count,
            'fill_ratio'     => $fill_ratio,
            'status'         => $status,
            'summary'        => $summary,
        ];
    }

    /**
     * Get the authoritative slot schema for a goal from Tool Index DB.
     *
     * @param string $goal Goal ID.
     * @return array{ required: array<string, array>, optional: array<string, array> }
     */
    private function get_goal_schema( string $goal ): array {
        $schema = [ 'required' => [], 'optional' => [] ];

        // ── Primary: Tool Index DB (authoritative source) ──
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $tool_rows = BizCity_Intent_Tool_Index::instance()->get_all_active();
            foreach ( $tool_rows as $row ) {
                $row_goal = $row['goal'] ?: $row['tool_name'];
                if ( $row_goal !== $goal ) continue;

                $req = json_decode( $row['required_slots'] ?? '{}', true );
                $opt = json_decode( $row['optional_slots'] ?? '{}', true );
                if ( is_array( $req ) ) $schema['required'] = $req;
                if ( is_array( $opt ) ) $schema['optional'] = $opt;
                return $schema;
            }
        }

        // ── Fallback: goal_patterns extract fields ──
        if ( class_exists( 'BizCity_Intent_Router' ) ) {
            $router = BizCity_Intent_Router::instance();
            $patterns = $router->get_goal_patterns();
            foreach ( $patterns as $config ) {
                if ( ( $config['goal'] ?? '' ) === $goal && ! empty( $config['extract'] ) ) {
                    foreach ( $config['extract'] as $field ) {
                        $schema['required'][ $field ] = [ 'type' => 'string' ];
                    }
                    break;
                }
            }
        }

        return $schema;
    }
}
