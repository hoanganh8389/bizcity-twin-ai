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
 * BizCity Step Executor — Admin-AJAX Step-by-Step Pipeline Runner
 *
 * Instead of fire-and-forget after confirm, this class manages a step-by-step
 * execution session controlled by the chat working panel via admin-ajax.
 *
 * Lifecycle mirrors execute-api.php:
 *   running → waiting (HIL/missing input) → resume → completed | failed
 *
 * Each step can:
 *   - Pause for missing input (HIL)
 *   - Retry on error
 *   - Skip (user choice)
 *   - Cancel pipeline
 *
 * Phase 1 Addendum — Issue 1: Working Panel Execution
 *
 * @package BizCity_Intent
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Step_Executor {

    /** @var self|null */
    private static $instance = null;

    /** Transient TTL: 1 hour. */
    const TRANSIENT_TTL = 3600;

    /** Execution state statuses. */
    const STATUS_RUNNING   = 'running';
    const STATUS_WAITING   = 'waiting';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /** Step-level statuses. */
    const STEP_PENDING   = 'pending';
    const STEP_RUNNING   = 'running';
    const STEP_WAITING   = 'waiting';
    const STEP_COMPLETED = 'completed';
    const STEP_FAILED    = 'failed';
    const STEP_SKIPPED   = 'skipped';

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_bizc_pipeline_start',     [ $this, 'ajax_start' ] );
        add_action( 'wp_ajax_bizc_pipeline_poll',      [ $this, 'ajax_poll' ] );
        add_action( 'wp_ajax_bizc_pipeline_step_action', [ $this, 'ajax_step_action' ] );
        add_action( 'wp_ajax_bizc_pipeline_resume',     [ $this, 'ajax_resume' ] );
    }

    /**
     * AJAX: Start pipeline execution from a confirmed plan.
     *
     * POST params: task_id, nonce.
     */
    public function ajax_start() {
        check_ajax_referer( 'bizc_pipeline_nonce', 'nonce' );

        $task_id = absint( $_POST['task_id'] ?? 0 );
        if ( ! $task_id ) {
            wp_send_json_error( [ 'message' => 'Missing task_id.' ] );
            return;
        }

        $state = $this->create_execution( $task_id );
        if ( ! $state ) {
            wp_send_json_error( [ 'message' => 'Could not load task or create execution.' ] );
            return;
        }

        // Start executing the first step.
        $state = $this->advance( $state );
        $this->save_state( $state );

        // Register one-shot trigger.
        if ( class_exists( 'BizCity_One_Shot_Trigger' ) ) {
            $oneshot = BizCity_One_Shot_Trigger::instance();
            $os_id   = $oneshot->create( $task_id, $state['pipeline_id'], [
                'user_id'    => get_current_user_id(),
                'session_id' => $state['session_id'] ?? '',
            ] );
            if ( $os_id ) {
                $oneshot->start( $os_id, $state['execution_id'] );
                $state['oneshot_id'] = $os_id;
                $this->save_state( $state );
            }
        }

        wp_send_json_success( $this->build_poll_response( $state ) );
    }

    /**
     * AJAX: Poll current execution state.
     *
     * POST params: execution_id, nonce.
     * Optional: pipeline_id — if execution_id transient is lost,
     * attempts to check resume eligibility from DB.
     */
    public function ajax_poll() {
        check_ajax_referer( 'bizc_pipeline_nonce', 'nonce' );

        $exec_id = sanitize_text_field( $_POST['execution_id'] ?? '' );
        $state   = $this->get_state( $exec_id );

        if ( ! $state ) {
            // S4: Check if pipeline can be resumed from DB
            $pipeline_id = sanitize_text_field( $_POST['pipeline_id'] ?? '' );
            if ( $pipeline_id && class_exists( 'BizCity_Pipeline_Resume' ) ) {
                $check = BizCity_Pipeline_Resume::can_resume( $pipeline_id );
                if ( $check['can_resume'] && ! $check['has_transient'] ) {
                    wp_send_json_error( [
                        'message'      => 'Execution expired — resume available.',
                        'can_resume'   => true,
                        'pipeline_id'  => $pipeline_id,
                    ] );
                    return;
                }
            }
            wp_send_json_error( [ 'message' => 'Execution not found.' ] );
            return;
        }

        // If running, try to advance to next step.
        if ( $state['status'] === self::STATUS_RUNNING ) {
            $state = $this->advance( $state );
            $this->save_state( $state );
        }

        wp_send_json_success( $this->build_poll_response( $state ) );
    }

    /**
     * AJAX: User action on a step (resume with input, retry, skip, cancel).
     *
     * POST params: execution_id, step_index, action (resume|retry|skip|cancel), input_data, nonce.
     */
    public function ajax_step_action() {
        check_ajax_referer( 'bizc_pipeline_nonce', 'nonce' );

        $exec_id    = sanitize_text_field( $_POST['execution_id'] ?? '' );
        $step_index = absint( $_POST['step_index'] ?? 0 );
        $action     = sanitize_text_field( $_POST['action'] ?? '' );
        $input_data = json_decode( stripslashes( $_POST['input_data'] ?? '{}' ), true );

        $state = $this->get_state( $exec_id );
        if ( ! $state ) {
            wp_send_json_error( [ 'message' => 'Execution not found.' ] );
            return;
        }

        switch ( $action ) {
            case 'resume':
                // Merge user-provided input and resume the waiting step.
                if ( isset( $state['steps'][ $step_index ] ) ) {
                    $state['steps'][ $step_index ]['user_input'] = $input_data;
                    $state['steps'][ $step_index ]['status']     = self::STEP_PENDING;
                    $state['status'] = self::STATUS_RUNNING;
                }
                break;

            case 'retry':
                if ( isset( $state['steps'][ $step_index ] ) ) {
                    $state['steps'][ $step_index ]['status'] = self::STEP_PENDING;
                    $state['steps'][ $step_index ]['error']  = '';
                    $state['status'] = self::STATUS_RUNNING;
                }
                break;

            case 'skip':
                if ( isset( $state['steps'][ $step_index ] ) ) {
                    $state['steps'][ $step_index ]['status'] = self::STEP_SKIPPED;
                    $state['status'] = self::STATUS_RUNNING;
                }
                break;

            case 'cancel':
                $state['status'] = self::STATUS_CANCELLED;
                // Mark remaining pending steps as cancelled.
                foreach ( $state['steps'] as &$step ) {
                    if ( $step['status'] === self::STEP_PENDING ) {
                        $step['status'] = self::STEP_SKIPPED;
                    }
                }
                unset( $step );
                break;

            default:
                wp_send_json_error( [ 'message' => 'Unknown action: ' . $action ] );
                return;
        }

        // Continue execution after action.
        if ( $state['status'] === self::STATUS_RUNNING ) {
            $state = $this->advance( $state );
        }

        $this->save_state( $state );

        // Finalize one-shot trigger on terminal state.
        $this->finalize_oneshot( $state );

        wp_send_json_success( $this->build_poll_response( $state ) );
    }

    /**
     * Create execution state from a task.
     *
     * @param int $task_id
     * @return array|null
     */
    private function create_execution( int $task_id ) {
        global $wpdb;
        $table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';

        $task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $task_id ) );
        if ( ! $task ) {
            return null;
        }

        $params   = json_decode( $task->params ?? '{}', true );
        $nodes    = $params['nodes'] ?? [];
        $edges    = $params['edges'] ?? [];
        $settings = $params['settings'] ?? [];

        // Build steps from action nodes (skip trigger).
        $steps = [];
        $step_idx = 0;
        foreach ( $nodes as $node ) {
            if ( ( $node['type'] ?? '' ) === 'trigger' ) {
                continue;
            }
            $steps[] = [
                'step_index'  => $step_idx,
                'node_id'     => $node['id'] ?? '',
                'tool'        => $node['data']['settings']['tool_id'] ?? $node['data']['code'] ?? '',
                'label'       => $node['data']['label'] ?? '',
                'settings'    => $node['data']['settings'] ?? [],
                'status'      => self::STEP_PENDING,
                'result'      => null,
                'error'       => '',
                'user_input'  => [],
                'started_at'  => null,
                'completed_at'=> null,
            ];
            $step_idx++;
        }

        $exec_id = 'bizc_exec_' . $task_id . '_' . time();

        return [
            'execution_id' => $exec_id,
            'task_id'      => $task_id,
            'pipeline_id'  => $settings['pipeline_id'] ?? '',
            'session_id'   => $params['meta']['session_id'] ?? '',
            'status'       => self::STATUS_RUNNING,
            'steps'        => $steps,
            'nodes'        => $nodes,
            'edges'        => $edges,
            'variables'    => [],
            'logs'         => [],
            'started_at'   => current_time( 'mysql' ),
            'completed_at' => null,
            'oneshot_id'   => 0,
        ];
    }

    /**
     * Advance execution: find next pending step and execute it.
     *
     * @param array $state
     * @return array Updated state.
     */
    private function advance( array $state ): array {
        if ( $state['status'] !== self::STATUS_RUNNING ) {
            return $state;
        }

        foreach ( $state['steps'] as $i => &$step ) {
            if ( $step['status'] !== self::STEP_PENDING ) {
                continue;
            }

            // Execute this step.
            $step['status']     = self::STEP_RUNNING;
            $step['started_at'] = current_time( 'mysql' );
            $state['logs'][]    = sprintf( '[%s] Step %d: %s — started', current_time( 'H:i:s' ), $i, $step['tool'] );

            // Phase 1.2: Fire SSE event — node started
            do_action( 'bizcity_pipeline_node_event', [
                'pipeline_id' => $state['execution_id'] ?? '',
                'node_id'     => $step['node_id'] ?? $i,
                'event'       => 'started',
                'tool'        => $step['tool'] ?? '',
                'log_line'    => sprintf( 'Step %d: %s — started', $i, $step['tool'] ),
            ] );

            $result = $this->execute_step( $step, $state );

            if ( ! empty( $result['waiting'] ) ) {
                // HIL: missing input — pause pipeline.
                $step['status']         = self::STEP_WAITING;
                $step['error']          = $result['message'] ?? 'Waiting for user input.';
                $step['missing_fields'] = $result['missing_fields'] ?? [];
                $state['status']        = self::STATUS_WAITING;
                $state['logs'][]        = sprintf( '[%s] Step %d: WAITING — %s', current_time( 'H:i:s' ), $i, $step['error'] );

                // Phase 1.2: Fire SSE event — node waiting
                do_action( 'bizcity_pipeline_node_event', [
                    'pipeline_id' => $state['execution_id'] ?? '',
                    'node_id'     => $step['node_id'] ?? $i,
                    'event'       => 'waiting',
                    'tool'        => $step['tool'] ?? '',
                    'log_line'    => sprintf( 'Step %d: WAITING — %s', $i, $step['error'] ),
                ] );

                break;
            }

            if ( empty( $result['success'] ) ) {
                // Error — pause for user decision (retry/skip/cancel).
                $step['status']      = self::STEP_FAILED;
                $step['error']       = $result['message'] ?? 'Unknown error.';
                $step['completed_at'] = current_time( 'mysql' );
                $state['status']     = self::STATUS_WAITING;
                $state['logs'][]     = sprintf( '[%s] Step %d: FAILED — %s', current_time( 'H:i:s' ), $i, $step['error'] );

                // Phase 1.2: Fire SSE event — node failed
                do_action( 'bizcity_pipeline_node_event', [
                    'pipeline_id'   => $state['execution_id'] ?? '',
                    'node_id'       => $step['node_id'] ?? $i,
                    'event'         => 'failed',
                    'tool'          => $step['tool'] ?? '',
                    'error_message' => $step['error'],
                    'log_line'      => sprintf( 'Step %d: FAILED — %s', $i, $step['error'] ),
                ] );

                break;
            }

            // Success.
            $step['status']       = self::STEP_COMPLETED;
            $step['result']       = $result['data'] ?? [];
            $step['completed_at'] = current_time( 'mysql' );
            $state['variables'][ $step['node_id'] ] = $step['result'];
            $state['logs'][] = sprintf( '[%s] Step %d: %s — completed', current_time( 'H:i:s' ), $i, $step['tool'] );

            // Phase 1.2: Fire SSE event — node completed
            $duration_ms = 0;
            if ( ! empty( $step['started_at'] ) ) {
                $duration_ms = (int) ( ( microtime( true ) - strtotime( $step['started_at'] ) ) * 1000 );
            }
            $preview = '';
            if ( is_array( $step['result'] ) ) {
                $preview = wp_json_encode( $step['result'] );
                if ( strlen( $preview ) > 120 ) {
                    $preview = mb_substr( $preview, 0, 117 ) . '...';
                }
            }
            do_action( 'bizcity_pipeline_node_event', [
                'pipeline_id'    => $state['execution_id'] ?? '',
                'node_id'        => $step['node_id'] ?? $i,
                'event'          => 'completed',
                'tool'           => $step['tool'] ?? '',
                'duration_ms'    => $duration_ms,
                'output_preview' => $preview,
                'log_line'       => sprintf( 'Step %d: %s — completed', $i, $step['tool'] ),
            ] );

            // Continue with the next step (don't break — advance immediately).
            // But save intermediate state for poll visibility.
            $this->save_state( $state );
        }
        unset( $step );

        // Check if all steps done.
        $all_done = true;
        foreach ( $state['steps'] as $step ) {
            if ( in_array( $step['status'], [ self::STEP_PENDING, self::STEP_RUNNING, self::STEP_WAITING ], true ) ) {
                $all_done = false;
                break;
            }
        }

        if ( $all_done && $state['status'] === self::STATUS_RUNNING ) {
            $has_failure = false;
            foreach ( $state['steps'] as $step ) {
                if ( $step['status'] === self::STEP_FAILED ) {
                    $has_failure = true;
                    break;
                }
            }
            $state['status']       = $has_failure ? self::STATUS_FAILED : self::STATUS_COMPLETED;
            $state['completed_at'] = current_time( 'mysql' );

            // Phase 1.2: Fire SSE event — pipeline done
            do_action( 'bizcity_pipeline_node_event', [
                'pipeline_id' => $state['execution_id'] ?? '',
                'node_id'     => '_pipeline',
                'event'       => 'pipeline_done',
                'log_line'    => 'Pipeline ' . ( $has_failure ? 'failed' : 'completed' ),
            ] );

            // ── Phase 1.6 B3: Fire pipeline_completed for session memory spec ──
            // De-escalates session mode: pipeline → chat
            do_action( 'bizcity_pipeline_completed', (int) $state['task_id'], $state );
        }

        return $state;
    }

    /**
     * Execute a single step using the existing execute-api infrastructure.
     *
     * @param array $step
     * @param array $state
     * @return array { success, data, message, waiting?, missing_fields? }
     */
    private function execute_step( array $step, array $state ): array {
        $tools = class_exists( 'BizCity_Intent_Tools' ) ? BizCity_Intent_Tools::instance() : null;
        if ( ! $tools ) {
            return [ 'success' => false, 'message' => 'Tool registry unavailable.' ];
        }

        $tool_name = $step['tool'];
        if ( ! $tools->has( $tool_name ) ) {
            return [ 'success' => false, 'message' => 'Tool not found: ' . $tool_name ];
        }

        // Build slots: merge settings input, user_input, and variables from previous steps.
        $slots = $this->resolve_step_slots( $step, $state );

        // Validate required fields.
        $schema  = $tools->get_schema( $tool_name );
        $missing = [];
        foreach ( ( $schema['input_fields'] ?? [] ) as $field => $cfg ) {
            if ( ! empty( $cfg['required'] ) && empty( $slots[ $field ] ) ) {
                $missing[] = $field;
            }
        }

        if ( ! empty( $missing ) ) {
            return [
                'success'        => false,
                'waiting'        => true,
                'message'        => 'Thiếu thông tin: ' . implode( ', ', $missing ),
                'missing_fields' => $missing,
            ];
        }

        // Execute via tool registry.
        $result = $tools->execute( $tool_name, $slots );

        return $result;
    }

    /**
     * Resolve input slots for a step from template variables and user input.
     *
     * @param array $step
     * @param array $state
     * @return array
     */
    private function resolve_step_slots( array $step, array $state ): array {
        $slots = [];

        // Start with settings input_json.
        $input_json_raw = $step['settings']['input_json'] ?? '';
        if ( $input_json_raw && is_string( $input_json_raw ) ) {
            $decoded = json_decode( $input_json_raw, true );
            if ( is_array( $decoded ) ) {
                $slots = $decoded;
            }
        }

        // Replace {{node-step-N.field}} templates with actual values from previous steps.
        foreach ( $slots as $key => &$value ) {
            if ( is_string( $value ) && preg_match_all( '/\{\{([^}]+)\}\}/', $value, $matches ) ) {
                foreach ( $matches[1] as $ref ) {
                    $parts = explode( '.', $ref, 2 );
                    $node_id = $parts[0] ?? '';
                    $field   = $parts[1] ?? '';
                    if ( isset( $state['variables'][ $node_id ][ $field ] ) ) {
                        $value = str_replace( '{{' . $ref . '}}', $state['variables'][ $node_id ][ $field ], $value );
                    }
                }
            }
        }
        unset( $value );

        // Merge user-provided input (HIL resume).
        if ( ! empty( $step['user_input'] ) && is_array( $step['user_input'] ) ) {
            $slots = array_merge( $slots, $step['user_input'] );
        }

        return $slots;
    }

    /**
     * Finalize one-shot trigger when execution reaches terminal state.
     *
     * @param array $state
     */
    private function finalize_oneshot( array $state ) {
        if ( empty( $state['oneshot_id'] ) || ! class_exists( 'BizCity_One_Shot_Trigger' ) ) {
            return;
        }

        $oneshot = BizCity_One_Shot_Trigger::instance();

        if ( $state['status'] === self::STATUS_COMPLETED ) {
            $oneshot->complete( (int) $state['oneshot_id'], [
                'steps_completed' => count( array_filter( $state['steps'], function ( $s ) {
                    return $s['status'] === self::STEP_COMPLETED;
                } ) ),
            ] );
        } elseif ( in_array( $state['status'], [ self::STATUS_FAILED, self::STATUS_CANCELLED ], true ) ) {
            $oneshot->fail( (int) $state['oneshot_id'], 'Pipeline ' . $state['status'] );
        }
    }

    /**
     * Build the poll response for frontend.
     *
     * @param array $state
     * @return array
     */
    private function build_poll_response( array $state ): array {
        $steps_summary = [];
        foreach ( $state['steps'] as $step ) {
            $steps_summary[] = [
                'step_index'     => $step['step_index'],
                'tool'           => $step['tool'],
                'label'          => $step['label'],
                'status'         => $step['status'],
                'error'          => $step['error'],
                'missing_fields' => $step['missing_fields'] ?? [],
                'result_preview' => ! empty( $step['result']['message'] )
                    ? mb_substr( $step['result']['message'], 0, 200 )
                    : '',
            ];
        }

        return [
            'execution_id' => $state['execution_id'],
            'task_id'      => $state['task_id'],
            'status'       => $state['status'],
            'steps'        => $steps_summary,
            'logs'         => array_slice( $state['logs'], -20 ),
            'started_at'   => $state['started_at'],
            'completed_at' => $state['completed_at'],
        ];
    }

    /**
     * AJAX: Resume pipeline from DB when transient is lost.
     *
     * POST params: pipeline_id, nonce.
     * S4: Pipeline Resume + Contract validation.
     */
    public function ajax_resume() {
        check_ajax_referer( 'bizc_pipeline_nonce', 'nonce' );

        $pipeline_id = sanitize_text_field( $_POST['pipeline_id'] ?? '' );
        if ( empty( $pipeline_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing pipeline_id.' ] );
            return;
        }

        if ( ! class_exists( 'BizCity_Pipeline_Resume' ) ) {
            wp_send_json_error( [ 'message' => 'Pipeline Resume module not loaded.' ] );
            return;
        }

        // Validate contract first
        $contract = BizCity_Pipeline_Resume::validate_contract( $pipeline_id );
        if ( ! $contract['ready'] ) {
            wp_send_json_error( [
                'message'    => $contract['error'],
                'status'     => $contract['status'],
                'mismatches' => $contract['mismatches'],
            ] );
            return;
        }

        // Execute resume
        $user_id    = get_current_user_id();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $channel    = sanitize_text_field( $_POST['channel'] ?? 'webchat' );

        $result = BizCity_Pipeline_Resume::resume( $pipeline_id, $user_id, $session_id, $channel );

        if ( $result['success'] ) {
            wp_send_json_success( [
                'execution_id' => $result['execution_id'],
                'status'       => $result['status'],
                'message'      => 'Pipeline resumed from DB.',
            ] );
        } else {
            wp_send_json_error( [
                'message' => $result['error'],
                'status'  => $result['status'],
            ] );
        }
    }

    // ── State persistence via transients ──

    private function save_state( array $state ) {
        set_transient( $state['execution_id'], $state, self::TRANSIENT_TTL );
    }

    private function get_state( string $exec_id ) {
        if ( empty( $exec_id ) ) {
            return null;
        }
        return get_transient( $exec_id ) ?: null;
    }
}
