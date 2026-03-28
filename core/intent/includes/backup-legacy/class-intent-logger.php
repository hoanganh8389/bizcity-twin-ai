<?php
/**
 * BizCity Intent — Pipeline Logger
 *
 * Structured logging for every step of the intent pipeline.
 * Stores logs in the `bizcity_intent_logs` table for monitoring and debugging.
 *
 * Each pipeline run produces a "trace" — a sequence of log entries
 * for a single turn (classify → plan → execute → respond).
 *
 * @package BizCity_Intent
 * @since   1.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Logger {

    /** @var self|null */
    private static $instance = null;

    /** @var wpdb */
    private $wpdb;

    /** @var string */
    private $table;

    /** @var string Current trace ID (one per process() call) */
    private $trace_id = '';

    /** @var float Trace start time (microtime) */
    private $trace_start = 0;

    /** @var int Step counter within a trace */
    private $step_index = 0;

    /** @var bool Whether logging is enabled */
    private $enabled = true;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'bizcity_intent_logs';

        // Allow disabling via constant
        if ( defined( 'BIZCITY_INTENT_LOG_DISABLED' ) && BIZCITY_INTENT_LOG_DISABLED ) {
            $this->enabled = false;
        }
    }

    /**
     * Create the logs table.
     * Called from Database::maybe_create_tables().
     */
    public function maybe_create_table() {
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trace_id VARCHAR(64) NOT NULL,
            conversation_id VARCHAR(64) DEFAULT '',
            turn_index INT UNSIGNED DEFAULT 0,
            step VARCHAR(50) NOT NULL,
            step_index INT UNSIGNED DEFAULT 0,
            data_json LONGTEXT,
            duration_ms DECIMAL(10,2) DEFAULT 0,
            level VARCHAR(10) DEFAULT 'info',
            user_id BIGINT UNSIGNED DEFAULT 0,
            channel VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            KEY idx_trace (trace_id),
            KEY idx_conv (conversation_id),
            KEY idx_step (step),
            KEY idx_level (level),
            KEY idx_created (created_at),
            KEY idx_user_created (user_id, created_at)
        ) {$charset};";

        $this->wpdb->query( $sql );
    }

    /* ================================================================
     *  Trace lifecycle
     * ================================================================ */

    /**
     * Begin a new trace for a pipeline run.
     *
     * @param string $conversation_id
     * @param int    $turn_index
     * @param int    $user_id
     * @param string $channel
     * @return string trace_id
     */
    public function begin_trace( $conversation_id = '', $turn_index = 0, $user_id = 0, $channel = '' ) {
        $this->trace_id    = 'trace_' . substr( md5( uniqid( '', true ) ), 0, 16 );
        $this->trace_start = microtime( true );
        $this->step_index  = 0;

        $this->log( 'trace_begin', [
            'conversation_id' => $conversation_id,
            'turn_index'      => $turn_index,
            'user_id'         => $user_id,
            'channel'         => $channel,
        ], $conversation_id, $turn_index, $user_id, $channel );

        return $this->trace_id;
    }

    /**
     * End the current trace.
     *
     * @param array $result Final result summary.
     */
    public function end_trace( array $result = [] ) {
        $total_ms = round( ( microtime( true ) - $this->trace_start ) * 1000, 2 );

        $this->log( 'trace_end', [
            'total_duration_ms' => $total_ms,
            'action'            => $result['action'] ?? '',
            'goal'              => $result['goal'] ?? '',
            'status'            => $result['status'] ?? '',
            'has_reply'         => ! empty( $result['reply'] ),
        ], $result['conversation_id'] ?? '', 0, 0, $result['channel'] ?? '' );

        $this->trace_id = '';
    }

    /**
     * Get current trace ID.
     *
     * @return string
     */
    public function get_trace_id() {
        return $this->trace_id;
    }

    /* ================================================================
     *  Logging
     * ================================================================ */

    /**
     * Log a pipeline step.
     *
     * @param string $step             Step name: classify, plan, execute_tool, ask_user, compose, complete, etc.
     * @param array  $data             Structured data for this step.
     * @param string $conversation_id  (optional) Override.
     * @param int    $turn_index       (optional) Override.
     * @param int    $user_id          (optional) Override.
     * @param string $channel          (optional) Override.
     * @param string $level            'info', 'warn', 'error'
     */
    public function log( $step, array $data = [], $conversation_id = '', $turn_index = 0, $user_id = 0, $channel = '', $level = 'info' ) {
        if ( ! $this->enabled ) {
            return;
        }

        $this->step_index++;

        $trace_id = $this->trace_id ?: 'notrace_' . substr( md5( microtime( true ) ), 0, 10 );

        $elapsed_ms = 0;
        if ( $this->trace_start > 0 ) {
            $elapsed_ms = round( ( microtime( true ) - $this->trace_start ) * 1000, 2 );
        }

        $this->wpdb->insert( $this->table, [
            'trace_id'        => $trace_id,
            'conversation_id' => $conversation_id,
            'turn_index'      => $turn_index,
            'step'            => $step,
            'step_index'      => $this->step_index,
            'data_json'       => wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'duration_ms'     => $elapsed_ms,
            'level'           => $level,
            'user_id'         => $user_id,
            'channel'         => $channel,
        ] );

        // Forward pipeline log to SSE stream (if active)
        do_action( 'bizcity_intent_pipeline_log', $step, $data, $level, $elapsed_ms );
    }

    /**
     * Shortcut: log a warning.
     */
    public function warn( $step, array $data = [], $conversation_id = '' ) {
        $this->log( $step, $data, $conversation_id, 0, 0, '', 'warn' );
    }

    /**
     * Shortcut: log an error.
     */
    public function error( $step, array $data = [], $conversation_id = '' ) {
        $this->log( $step, $data, $conversation_id, 0, 0, '', 'error' );
    }

    /* ================================================================
     *  Query methods (for Monitor / Debug)
     * ================================================================ */

    /**
     * Get a full trace by trace_id.
     *
     * @param string $trace_id
     * @return array
     */
    public function get_trace( $trace_id ) {
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE trace_id = %s
             ORDER BY step_index ASC",
            $trace_id
        ), ARRAY_A );
    }

    /**
     * Get all traces for a conversation.
     *
     * @param string $conversation_id
     * @param int    $limit
     * @return array
     */
    public function get_traces_for_conversation( $conversation_id, $limit = 50 ) {
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT trace_id, 
                    MIN(created_at) AS started_at,
                    MAX(created_at) AS ended_at,
                    COUNT(*) AS step_count,
                    MAX(duration_ms) AS total_ms,
                    GROUP_CONCAT(DISTINCT step ORDER BY step_index) AS steps
             FROM {$this->table}
             WHERE conversation_id = %s
             GROUP BY trace_id
             ORDER BY started_at DESC
             LIMIT %d",
            $conversation_id, $limit
        ), ARRAY_A );
    }

    /**
     * Get recent logs (all or filtered).
     *
     * @param array $filters  Optional: level, step, user_id, channel, conversation_id, from, to.
     * @param int   $limit
     * @param int   $offset
     * @return array
     */
    public function get_recent( array $filters = [], $limit = 100, $offset = 0 ) {
        $where_parts = [];
        $params      = [];

        if ( ! empty( $filters['level'] ) ) {
            $where_parts[] = 'level = %s';
            $params[]      = $filters['level'];
        }
        if ( ! empty( $filters['step'] ) ) {
            $where_parts[] = 'step = %s';
            $params[]      = $filters['step'];
        }
        if ( ! empty( $filters['user_id'] ) ) {
            $where_parts[] = 'user_id = %d';
            $params[]      = intval( $filters['user_id'] );
        }
        if ( ! empty( $filters['channel'] ) ) {
            $where_parts[] = 'channel = %s';
            $params[]      = $filters['channel'];
        }
        if ( ! empty( $filters['conversation_id'] ) ) {
            $where_parts[] = 'conversation_id = %s';
            $params[]      = $filters['conversation_id'];
        }
        if ( ! empty( $filters['from'] ) ) {
            $where_parts[] = 'created_at >= %s';
            $params[]      = $filters['from'];
        }
        if ( ! empty( $filters['to'] ) ) {
            $where_parts[] = 'created_at <= %s';
            $params[]      = $filters['to'];
        }

        $where = ! empty( $where_parts ) ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

        $sql = "SELECT * FROM {$this->table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );
    }

    /**
     * Get aggregated stats for the dashboard.
     *
     * @param int $days  Number of days to look back.
     * @return array
     */
    public function get_stats( $days = 7 ) {
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // Total traces
        $total_traces = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT trace_id) FROM {$this->table} WHERE created_at >= %s",
            $since
        ) );

        // Traces per day
        $per_day = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT DATE(created_at) AS day, COUNT(DISTINCT trace_id) AS traces
             FROM {$this->table}
             WHERE created_at >= %s AND step = 'trace_begin'
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            $since
        ), ARRAY_A );

        // Step distribution
        $step_dist = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT step, COUNT(*) AS cnt
             FROM {$this->table}
             WHERE created_at >= %s AND step NOT IN ('trace_begin','trace_end')
             GROUP BY step
             ORDER BY cnt DESC",
            $since
        ), ARRAY_A );

        // Average pipeline duration (from trace_end entries)
        $avg_duration = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT AVG(CAST(
                JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.total_duration_ms'))
                AS DECIMAL(10,2)
             ))
             FROM {$this->table}
             WHERE step = 'trace_end' AND created_at >= %s",
            $since
        ) );

        // Error count
        $errors = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE level = 'error' AND created_at >= %s",
            $since
        ) );

        // Top goals (from classify step)
        $top_goals = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.goal')) AS goal,
                    COUNT(*) AS cnt
             FROM {$this->table}
             WHERE step = 'classify' AND created_at >= %s
                   AND JSON_EXTRACT(data_json, '$.goal') IS NOT NULL
             GROUP BY goal
             ORDER BY cnt DESC
             LIMIT 10",
            $since
        ), ARRAY_A );

        // Top tools (from execute_tool step)
        $top_tools = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.tool_name')) AS tool_name,
                    COUNT(*) AS cnt,
                    SUM(CASE WHEN JSON_EXTRACT(data_json, '$.success') = true THEN 1 ELSE 0 END) AS success_cnt
             FROM {$this->table}
             WHERE step = 'execute_tool' AND created_at >= %s
                   AND JSON_EXTRACT(data_json, '$.tool_name') IS NOT NULL
             GROUP BY tool_name
             ORDER BY cnt DESC
             LIMIT 10",
            $since
        ), ARRAY_A );

        return [
            'period_days'    => $days,
            'total_traces'   => $total_traces,
            'per_day'        => $per_day,
            'step_dist'      => $step_dist,
            'avg_duration_ms'=> round( floatval( $avg_duration ), 2 ),
            'errors'         => $errors,
            'top_goals'      => $top_goals,
            'top_tools'      => $top_tools,
        ];
    }

    /**
     * Export logs as a structured JSON array.
     *
     * @param array  $filters  Same as get_recent().
     * @param int    $limit
     * @param string $format   'flat' (rows) or 'grouped' (by trace_id).
     * @return array
     */
    public function export_json( array $filters = [], $limit = 1000, $format = 'flat' ) {
        $rows = $this->get_recent( $filters, $limit );

        // Decode data_json in each row for clean JSON output
        foreach ( $rows as &$row ) {
            if ( isset( $row['data_json'] ) ) {
                $decoded = json_decode( $row['data_json'], true );
                $row['data'] = is_array( $decoded ) ? $decoded : [];
                unset( $row['data_json'] );
            }
        }
        unset( $row );

        if ( 'grouped' === $format ) {
            $grouped = [];
            foreach ( $rows as $row ) {
                $tid = $row['trace_id'] ?? 'unknown';
                if ( ! isset( $grouped[ $tid ] ) ) {
                    $grouped[ $tid ] = [
                        'trace_id' => $tid,
                        'steps'    => [],
                    ];
                }
                $grouped[ $tid ]['steps'][] = $row;
            }
            return array_values( $grouped );
        }

        return $rows;
    }

    /**
     * Clean up old logs.
     *
     * @param int $days  Delete logs older than this many days.
     * @return int Rows deleted.
     */
    public function cleanup( $days = 30 ) {
        return (int) $this->wpdb->query( $this->wpdb->prepare(
            "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }
}
