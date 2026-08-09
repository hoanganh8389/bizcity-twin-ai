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
 * BizCity Intent — Pipeline Logger
 *
 * Structured logging for every step of the intent pipeline.
 * Stores pipeline evidence in JSONL for monitoring and debugging. The legacy
 * `bizcity_intent_logs` table is retired and drained only for old rows.
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

    const RETENTION_HOOK  = 'bizcity_intent_logs_retention';
    const RETENTION_DAYS  = 7; // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep intent step logs for one week.
    const RETENTION_BATCH = 500;

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
     * [2026-08-01 Johnny Chu] PHASE-1.24-LOG-RETENTION — register bounded pipeline-log cleanup.
     */
    public static function register_retention_cron(): void {
        if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
            return;
        }
        BizCity_Cron_Manager::instance()->register( array(
            'id'          => 'core.intent.logs_retention',
            'hook'        => self::RETENTION_HOOK,
            'interval'    => 'daily',
            'owner'       => 'core/intent',
            'description' => 'Bounded retention sweep for the intent pipeline step trace log.',
            'retention'   => self::RETENTION_DAYS,
        ) );
    }

    /**
     * [2026-08-01 Johnny Chu] PHASE-1.24-LOG-RETENTION — delete old rows only from the scheduled cron context.
     */
    public static function gc_logs(): void {
        global $wpdb;
        $deleted = 0; // [2026-08-01 Johnny Chu] PHASE-1.29-LOG-ORPHAN — delete-only drain; no SQL writer/reader.
        $table = $wpdb->prefix . 'bizcity_intent_logs';
        if ( $wpdb && ( ! function_exists( 'bizcity_tbl_exists' ) || bizcity_tbl_exists( $table ) ) ) {
            $result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < ( CURRENT_TIMESTAMP - INTERVAL %d DAY ) ORDER BY id ASC LIMIT %d", self::RETENTION_DAYS, self::RETENTION_BATCH ) );
            $deleted = false === $result ? 0 : (int) $result;
        }
        if ( class_exists( 'BizCity_Cron_Manager' ) ) {
            $cron = BizCity_Cron_Manager::instance();
            $cron->note( array( 'counters' => array( 'intent_logs_retention_deleted' => $deleted ) ) );
            $cron->note_event( 'intent_logs_retention', array( 'deleted' => $deleted, 'retention_days' => self::RETENTION_DAYS ) );
        }
    }

    /**
     * Public accessor for the fully-qualified logs table name.
     * Used by Intent_Monitor and other admin views that need direct SQL.
     */
    public function get_table_name(): string {
        return $this->table;
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

        // [2026-08-01 Johnny Chu] PHASE-1.29-LOG-ORPHAN — SQL trace INSERT
        // path removed; JSONL is the only pipeline evidence store.

        // [2026-08-01 Johnny Chu] PHASE-1.24-LOG-JSONL — Phase A dual-write mirror; best-effort, never blocks the pipeline.
        if ( class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'write' ) ) {
            BizCity_JSONL_File_Logger::write(
                'bizcity-intent-logs',
                'pipeline-trace',
                $level,
                $step,
                'Intent pipeline step: ' . $step,
                array(
                    'trace_id'        => $trace_id,
                    'conversation_id' => $conversation_id,
                    'turn_index'      => $turn_index,
                    'step_index'      => $this->step_index,
                    'duration_ms'     => $elapsed_ms,
                    'user_id'         => $user_id,
                    'channel'         => $channel,
                    'data'            => $data,
                )
            );
        }

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
        if ( ! $this->sql_log_available() ) {
            return $this->get_jsonl_rows( array( 'trace_id' => (string) $trace_id ), 1000 );
        }
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
        if ( ! $this->sql_log_available() ) {
            $rows = $this->get_jsonl_rows( array( 'conversation_id' => (string) $conversation_id ), 10000 );
            $traces = array();
            foreach ( $rows as $row ) {
                $trace_id = (string) ( $row['trace_id'] ?? '' );
                if ( $trace_id === '' ) { continue; }
                if ( ! isset( $traces[ $trace_id ] ) ) {
                    $traces[ $trace_id ] = array(
                        'trace_id' => $trace_id,
                        'started_at' => (string) ( $row['created_at'] ?? '' ),
                        'ended_at' => (string) ( $row['created_at'] ?? '' ),
                        'step_count' => 0,
                        'total_ms' => 0,
                        'steps' => array(),
                    );
                }
                $traces[ $trace_id ]['step_count']++;
                $traces[ $trace_id ]['ended_at'] = (string) ( $row['created_at'] ?? '' );
                $traces[ $trace_id ]['total_ms'] = max( $traces[ $trace_id ]['total_ms'], (float) ( $row['duration_ms'] ?? 0 ) );
                $traces[ $trace_id ]['steps'][] = (string) ( $row['step'] ?? '' );
            }
            return array_slice( array_values( $traces ), 0, max( 1, (int) $limit ) );
        }
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
        if ( ! $this->sql_log_available() ) {
            return array_slice( $this->get_jsonl_rows( $filters, min( 10000, max( 1, (int) $limit + (int) $offset ) ) ), (int) $offset, (int) $limit );
        }
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
        if ( ! $this->sql_log_available() ) {
            $rows = $this->get_jsonl_rows( array(), 10000 );
            $per_day = array();
            $step_dist = array();
            $errors = 0;
            $total_duration = 0;
            foreach ( $rows as $row ) {
                $day = substr( (string) ( $row['created_at'] ?? '' ), 0, 10 );
                $step = (string) ( $row['step'] ?? '' );
                if ( $day !== '' ) { $per_day[ $day ] = ( $per_day[ $day ] ?? 0 ) + 1; }
                if ( $step !== '' ) { $step_dist[ $step ] = ( $step_dist[ $step ] ?? 0 ) + 1; }
                if ( (string) ( $row['level'] ?? '' ) === 'error' ) { $errors++; }
                $total_duration += (float) ( $row['duration_ms'] ?? 0 );
            }
            arsort( $step_dist );
            krsort( $per_day );
            $step_rows = array();
            foreach ( $step_dist as $step => $count ) { $step_rows[] = array( 'step' => $step, 'cnt' => $count ); }
            $day_rows = array();
            foreach ( $per_day as $day => $count ) { $day_rows[] = array( 'day' => $day, 'traces' => $count ); }
            return array(
                'period_days'     => (int) $days,
                'total_traces'    => count( $rows ),
                'per_day'         => $day_rows,
                'step_dist'       => $step_rows,
                'avg_duration_ms' => count( $rows ) > 0 ? round( $total_duration / count( $rows ), 2 ) : 0,
                'errors'          => $errors,
                'top_goals'       => array(),
                'top_tools'       => array(),
            );
        }
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

    private function sql_log_available(): bool {
        // [2026-08-01 Johnny Chu] PHASE-1.29-LOG-ORPHAN — legacy Monitor
        // methods no longer read the deprecated table; Data Browser/JSONL is canonical.
        return false;
    }

    private function get_jsonl_rows( array $filters = array(), $limit = 1000 ): array {
        if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! method_exists( 'BizCity_JSONL_File_Logger', 'query' ) ) {
            return array();
        }
        $rows = BizCity_JSONL_File_Logger::query( 'bizcity-intent-logs', 'pipeline-trace', array(
            'days' => 7,
            'limit' => min( 10000, max( 1, (int) $limit ) ),
            'filter' => function ( $raw ) use ( $filters ) {
                $ctx = is_array( $raw['ctx'] ?? null ) ? $raw['ctx'] : array();
                foreach ( array( 'trace_id', 'conversation_id', 'user_id', 'channel', 'level', 'step' ) as $key ) {
                    if ( ! isset( $filters[ $key ] ) || $filters[ $key ] === '' ) { continue; }
                    $actual = $key === 'level' || $key === 'step' ? (string) ( $raw[ $key === 'step' ? 'event' : 'level' ] ?? '' ) : (string) ( $ctx[ $key ] ?? '' );
                    if ( (string) $filters[ $key ] !== $actual ) { return false; }
                }
                return true;
            },
        ) );
        $out = array();
        foreach ( (array) $rows as $raw ) {
            $ctx = is_array( $raw['ctx'] ?? null ) ? $raw['ctx'] : array();
            $key = (string) ( $raw['ts'] ?? '' ) . '|' . (string) ( $ctx['trace_id'] ?? '' ) . '|' . (string) ( $raw['event'] ?? '' );
            $out[] = array_merge( array(
                'id' => absint( crc32( $key ) ),
                'trace_id' => (string) ( $ctx['trace_id'] ?? '' ),
                'conversation_id' => (string) ( $ctx['conversation_id'] ?? '' ),
                'turn_index' => (int) ( $ctx['turn_index'] ?? 0 ),
                'step' => (string) ( $raw['event'] ?? '' ),
                'step_index' => (int) ( $ctx['step_index'] ?? 0 ),
                'duration_ms' => (float) ( $ctx['duration_ms'] ?? 0 ),
                'level' => (string) ( $raw['level'] ?? 'info' ),
                'user_id' => (int) ( $ctx['user_id'] ?? 0 ),
                'channel' => (string) ( $ctx['channel'] ?? '' ),
                'created_at' => (string) ( $raw['ts'] ?? '' ),
                'data_json' => wp_json_encode( $ctx['data'] ?? array() ),
            ), $ctx );
        }
        return $out;
    }

    /**
     * Clean up old logs.
     *
     * @param int $days  Delete logs older than this many days.
     * @return int Rows deleted.
     */
    public function cleanup( $days = 7 ) {
        unset( $days );
        // [2026-08-01 Johnny Chu] PHASE-1.29-LOG-ORPHAN — JSONL retention owns cleanup.
        return 0;
    }
}
