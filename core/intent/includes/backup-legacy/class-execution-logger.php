<?php
/**
 * BizCity Execution Logger
 *
 * Dedicated logging for pipeline and tool execution steps.
 * Separate from Router Log (routing decisions) — this tracks EXECUTION flow:
 *   - Pipeline lifecycle (start, step, complete)
 *   - Tool invocations and results
 *   - Goal updates and slot resolution
 *   - Error states and recovery
 *
 * @package BizCity_Intent
 * @since 3.2.0
 * @see ARCHITECTURE.md Section 15 — Roadmap Phase 10
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class BizCity_Execution_Logger
 *
 * Singleton — use BizCity_Execution_Logger::instance()
 *
 * Step types:
 *   - pipeline_start   : Pipeline begins (template, steps planned)
 *   - pipeline_step    : Individual step execution
 *   - pipeline_complete: Pipeline finished (success/partial/error)
 *   - tool_invoke      : Tool callback invoked
 *   - tool_result      : Tool returned result
 *   - slot_resolve     : $step[N].data.field resolution
 *   - goal_update      : Goal tracker status change
 *   - error            : Error occurred
 */
class BizCity_Execution_Logger {

    /** @var BizCity_Execution_Logger Singleton instance */
    private static $instance = null;

    /** @var string Current session ID for this request */
    private static $current_session_id = '';

    /** @var string Current pipeline ID (if any) */
    private static $current_pipeline_id = '';

    /** @var array In-memory log buffer for current request */
    private static $request_buffer = [];

    /** @var int Max logs per session */
    const MAX_LOGS = 100;

    /** @var int Transient TTL in seconds */
    const TTL = HOUR_IN_SECONDS * 2;

    /**
     * Get singleton instance
     *
     * @return BizCity_Execution_Logger
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — singleton
     */
    private function __construct() {
        // Flush buffer on shutdown
        add_action( 'shutdown', [ $this, 'flush_buffer' ] );
    }

    /* ================================================================
     * SESSION MANAGEMENT
     * ================================================================ */

    /**
     * Set current session for logging
     *
     * @param string $session_id Session ID (webchat, adminchat, etc.)
     */
    public static function set_session( $session_id ) {
        self::$current_session_id = $session_id;
    }

    /**
     * Get current session ID
     *
     * @return string
     */
    public static function get_session() {
        return self::$current_session_id ?: 'user_' . get_current_user_id();
    }

    /* ================================================================
     * PIPELINE TRACKING
     * ================================================================ */

    /**
     * Start pipeline tracking
     *
     * @param string $template  Pipeline template name
     * @param array  $steps     Planned steps
     * @param array  $context   Initial context (user_id, provider, etc.)
     * @return string Pipeline ID (for reference)
     */
    public static function pipeline_start( $template, $steps = [], $context = [] ) {
        $pipeline_id = 'pipe_' . uniqid();
        self::$current_pipeline_id = $pipeline_id;

        self::log( 'pipeline_start', [
            'pipeline_id' => $pipeline_id,
            'template'    => $template,
            'steps'       => array_map( function( $s ) {
                return is_array( $s ) ? ( $s['name'] ?? $s['tool'] ?? 'unknown' ) : $s;
            }, $steps ),
            'step_count'  => count( $steps ),
            'context'     => $context,
        ] );

        return $pipeline_id;
    }

    /**
     * Log pipeline step execution
     *
     * @param int    $step_index   Step index (0-based)
     * @param string $step_name    Step/tool name
     * @param array  $input        Input data for this step
     * @param string $status       'running' | 'waiting' | 'skipped'
     */
    public static function pipeline_step( $step_index, $step_name, $input = [], $status = 'running' ) {
        self::log( 'pipeline_step', [
            'pipeline_id' => self::$current_pipeline_id,
            'step_index'  => $step_index,
            'step_name'   => $step_name,
            'input'       => self::truncate_data( $input ),
            'status'      => $status,
        ] );
    }

    /**
     * Complete pipeline
     *
     * @param string $status      'success' | 'partial' | 'error'
     * @param array  $final_data  Final output data
     * @param float  $duration_ms Total duration in milliseconds
     */
    public static function pipeline_complete( $status, $final_data = [], $duration_ms = 0 ) {
        self::log( 'pipeline_complete', [
            'pipeline_id' => self::$current_pipeline_id,
            'status'      => $status,
            'final_data'  => self::truncate_data( $final_data ),
            'duration_ms' => $duration_ms,
        ] );

        self::$current_pipeline_id = '';
    }

    /* ================================================================
     * TOOL EXECUTION TRACKING
     * ================================================================ */

    /**
     * Log tool invocation (before execution)
     *
     * @param string $tool_name Tool identifier
     * @param array  $params    Input parameters
     * @param string $source    'built_in' | 'plugin' | 'provider'
     * @return string Invocation ID for pairing with result
     */
    public static function tool_invoke( $tool_name, $params = [], $source = 'built_in' ) {
        $invoke_id = 'inv_' . substr( uniqid(), -6 );

        self::log( 'tool_invoke', [
            'invoke_id'   => $invoke_id,
            'pipeline_id' => self::$current_pipeline_id,
            'tool_name'   => $tool_name,
            'params'      => self::truncate_data( $params ),
            'source'      => $source,
        ] );

        return $invoke_id;
    }

    /**
     * Log tool result (after execution)
     *
     * @param string $invoke_id   From tool_invoke()
     * @param string $tool_name   Tool identifier
     * @param array  $result      Tool output envelope {success, data, message, ...}
     * @param float  $duration_ms Execution time in ms
     */
    public static function tool_result( $invoke_id, $tool_name, $result = [], $duration_ms = 0 ) {
        $success = isset( $result['success'] ) ? $result['success'] : null;
        $complete = isset( $result['complete'] ) ? $result['complete'] : null;

        self::log( 'tool_result', [
            'invoke_id'   => $invoke_id,
            'pipeline_id' => self::$current_pipeline_id,
            'tool_name'   => $tool_name,
            'success'     => $success,
            'complete'    => $complete,
            'has_data'    => ! empty( $result['data'] ),
            'data_type'   => isset( $result['data']['type'] ) ? $result['data']['type'] : null,
            'data_id'     => isset( $result['data']['id'] ) ? $result['data']['id'] : null,
            'message'     => isset( $result['message'] ) ? mb_substr( $result['message'], 0, 200 ) : '',
            'duration_ms' => $duration_ms,
        ] );
    }

    /* ================================================================
     * SLOT & GOAL TRACKING
     * ================================================================ */

    /**
     * Log slot resolution (pipeline variable interpolation)
     *
     * @param string $expression  e.g., "$step[0].data.image_url"
     * @param mixed  $resolved    Resolved value
     * @param bool   $found       Whether resolution succeeded
     */
    public static function slot_resolve( $expression, $resolved, $found = true ) {
        self::log( 'slot_resolve', [
            'pipeline_id' => self::$current_pipeline_id,
            'expression'  => $expression,
            'resolved'    => self::truncate_data( $resolved ),
            'found'       => $found,
        ] );
    }

    /**
     * Log goal tracker update
     *
     * @param string $goal_id      Goal identifier
     * @param string $status       'IN_PROGRESS' | 'WAITING_USER' | 'DONE' | 'ABANDONED'
     * @param array  $missing_info Array of missing fields
     * @param string $next_action  Recommended next action
     */
    public static function goal_update( $goal_id, $status, $missing_info = [], $next_action = '' ) {
        self::log( 'goal_update', [
            'pipeline_id'  => self::$current_pipeline_id,
            'goal_id'      => $goal_id,
            'status'       => $status,
            'missing_info' => $missing_info,
            'next_action'  => $next_action,
        ] );
    }

    /* ================================================================
     * ERROR LOGGING
     * ================================================================ */

    /**
     * Log error during execution
     *
     * @param string $error_type  'tool_error' | 'pipeline_error' | 'validation_error'
     * @param string $message     Error message
     * @param array  $context     Additional context
     */
    public static function error( $error_type, $message, $context = [] ) {
        self::log( 'error', [
            'pipeline_id' => self::$current_pipeline_id,
            'error_type'  => $error_type,
            'message'     => $message,
            'context'     => self::truncate_data( $context ),
        ] );
    }

    /* ================================================================
     * CORE LOG METHOD
     * ================================================================ */

    /**
     * Write log entry
     *
     * @param string $step Event type
     * @param array  $data Event data
     */
    public static function log( $step, $data = [] ) {
        $entry = array_merge( [
            'step'       => $step,
            'timestamp'  => current_time( 'mysql' ),
            'microtime'  => microtime( true ),
            'session_id' => self::get_session(),
            'user_id'    => get_current_user_id(),
        ], $data );

        // Add to request buffer
        self::$request_buffer[] = $entry;

        // Also fire action for real-time consumers
        do_action( 'bizcity_execution_log', $entry );
    }

    /**
     * Flush buffer to transient (called on shutdown)
     */
    public function flush_buffer() {
        if ( empty( self::$request_buffer ) ) {
            return;
        }

        $session_id = self::get_session();
        $log_key = 'bizcity_exec_log_' . $session_id;

        error_log( '[Execution-Logger] flush_buffer: session=' . $session_id . ' | entries=' . count( self::$request_buffer ) );

        $existing = get_transient( $log_key );
        if ( ! $existing || ! is_array( $existing ) ) {
            $existing = [];
        }

        // Prepend new entries (newest first)
        $merged = array_merge( self::$request_buffer, $existing );

        // Keep max logs
        $merged = array_slice( $merged, 0, self::MAX_LOGS );

        set_transient( $log_key, $merged, self::TTL );

        error_log( '[Execution-Logger] saved to transient: ' . $log_key . ' | total=' . count( $merged ) );

        self::$request_buffer = [];
    }

    /* ================================================================
     * READ / RETRIEVE LOGS
     * ================================================================ */

    /**
     * Get logs for a session
     *
     * @param string $session_id Session ID (optional, uses current)
     * @param array  $filters    { step: string[], pipeline_id: string, since: timestamp }
     * @return array
     */
    public static function get_logs( $session_id = '', $filters = [] ) {
        if ( ! $session_id ) {
            $session_id = self::get_session();
        }

        $log_key = 'bizcity_exec_log_' . $session_id;
        $logs = get_transient( $log_key );

        if ( ! $logs || ! is_array( $logs ) ) {
            return [];
        }

        // Apply filters
        if ( ! empty( $filters['step'] ) ) {
            $steps = (array) $filters['step'];
            $logs = array_filter( $logs, function( $log ) use ( $steps ) {
                return in_array( $log['step'], $steps, true );
            } );
        }

        if ( ! empty( $filters['pipeline_id'] ) ) {
            $pid = $filters['pipeline_id'];
            $logs = array_filter( $logs, function( $log ) use ( $pid ) {
                return isset( $log['pipeline_id'] ) && $log['pipeline_id'] === $pid;
            } );
        }

        if ( ! empty( $filters['since'] ) ) {
            $since = $filters['since'];
            $logs = array_filter( $logs, function( $log ) use ( $since ) {
                return $log['microtime'] > $since;
            } );
        }

        return array_values( $logs );
    }

    /**
     * Get pipeline trace (all logs for a specific pipeline)
     *
     * @param string $pipeline_id Pipeline ID
     * @param string $session_id  Session ID (optional)
     * @return array Logs filtered by pipeline, ordered chronologically
     */
    public static function get_pipeline_trace( $pipeline_id, $session_id = '' ) {
        $logs = self::get_logs( $session_id, [ 'pipeline_id' => $pipeline_id ] );

        // Sort chronologically (oldest first for trace)
        usort( $logs, function( $a, $b ) {
            return $a['microtime'] - $b['microtime'];
        } );

        return $logs;
    }

    /**
     * Clear logs for a session
     *
     * @param string $session_id Session ID
     */
    public static function clear_logs( $session_id = '' ) {
        if ( ! $session_id ) {
            $session_id = self::get_session();
        }

        delete_transient( 'bizcity_exec_log_' . $session_id );
    }

    /* ================================================================
     * HELPERS
     * ================================================================ */

    /**
     * Truncate data to prevent huge log entries
     *
     * @param mixed $data Data to truncate
     * @param int   $max_length Max string length
     * @return mixed Truncated data
     */
    private static function truncate_data( $data, $max_length = 500 ) {
        if ( is_string( $data ) ) {
            return mb_strlen( $data ) > $max_length
                ? mb_substr( $data, 0, $max_length ) . '...'
                : $data;
        }

        if ( is_array( $data ) ) {
            // For arrays, JSON encode and check size
            $json = wp_json_encode( $data );
            if ( strlen( $json ) > 1000 ) {
                return [
                    '_truncated' => true,
                    '_keys'      => array_keys( $data ),
                    '_size'      => strlen( $json ),
                ];
            }
            return $data;
        }

        return $data;
    }

    /**
     * Get summary stats for dashboard
     *
     * @param string $session_id Session ID
     * @return array
     */
    public static function get_stats( $session_id = '' ) {
        $logs = self::get_logs( $session_id );

        $stats = [
            'total_logs'       => count( $logs ),
            'pipelines'        => [],
            'tools_invoked'    => 0,
            'tools_succeeded'  => 0,
            'tools_failed'     => 0,
            'errors'           => 0,
            'goals_tracked'    => 0,
        ];

        foreach ( $logs as $log ) {
            switch ( $log['step'] ) {
                case 'pipeline_start':
                    $stats['pipelines'][] = [
                        'id'       => $log['pipeline_id'],
                        'template' => $log['template'],
                    ];
                    break;
                case 'tool_invoke':
                    $stats['tools_invoked']++;
                    break;
                case 'tool_result':
                    if ( $log['success'] === true ) {
                        $stats['tools_succeeded']++;
                    } elseif ( $log['success'] === false ) {
                        $stats['tools_failed']++;
                    }
                    break;
                case 'error':
                    $stats['errors']++;
                    break;
                case 'goal_update':
                    $stats['goals_tracked']++;
                    break;
            }
        }

        return $stats;
    }
}

// Initialize singleton
BizCity_Execution_Logger::instance();
