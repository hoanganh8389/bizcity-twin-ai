<?php
/**
 * BizCity One-Shot Trigger — Ephemeral Pipeline Execution
 *
 * For scenario-from-chat workflows: no cron trigger needed.
 * Creates a one-shot ephemeral trigger that executes once and is consumed.
 *
 * Lifecycle: ready → running → completed | failed → consumed
 *
 * After terminal state (completed/failed): mark consumed, never re-run.
 * Task remains visible in builder for audit but status = "one-shot consumed".
 *
 * Phase 1 Addendum — Issue 2: One-Shot Trigger
 *
 * @package BizCity_Intent
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_One_Shot_Trigger {

    /** @var self|null */
    private static $instance = null;

    /** State constants. */
    const STATE_READY     = 'ready';
    const STATE_RUNNING   = 'running';
    const STATE_COMPLETED = 'completed';
    const STATE_FAILED    = 'failed';
    const STATE_CONSUMED  = 'consumed';

    /** DB table suffix. */
    const TABLE_SUFFIX = 'pipeline_oneshot';

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ensure the one-shot table exists.
     *
     * Called once on plugin activation or first use.
     */
    public static function maybe_create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'bizcity_' . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            return;
        }

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK to bizcity_tasks.id',
            pipeline_id VARCHAR(40) NOT NULL DEFAULT '',
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            state VARCHAR(20) NOT NULL DEFAULT 'ready',
            execution_id VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'Transient key from execute-api',
            trigger_data LONGTEXT COMMENT 'JSON trigger context',
            result_summary TEXT COMMENT 'JSON result summary',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_task_id (task_id),
            KEY idx_state (state),
            KEY idx_pipeline (pipeline_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Create a one-shot trigger for a draft task.
     *
     * Called after Core Planner generates a scenario and user confirms.
     *
     * @param int    $task_id       bizcity_tasks row ID.
     * @param string $pipeline_id   Pipeline identifier.
     * @param array  $trigger_data  { message, user_id, session_id, channel }.
     * @return int|false  Row ID or false on failure.
     */
    public function create( int $task_id, string $pipeline_id, array $trigger_data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_' . self::TABLE_SUFFIX;

        self::maybe_create_table();

        $inserted = $wpdb->insert( $table, [
            'task_id'      => $task_id,
            'pipeline_id'  => sanitize_text_field( $pipeline_id ),
            'session_id'   => sanitize_text_field( $trigger_data['session_id'] ?? '' ),
            'user_id'      => absint( $trigger_data['user_id'] ?? get_current_user_id() ),
            'state'        => self::STATE_READY,
            'trigger_data' => wp_json_encode( $trigger_data, JSON_UNESCAPED_UNICODE ),
            'created_at'   => current_time( 'mysql', true ),
        ], [ '%d', '%s', '%s', '%d', '%s', '%s', '%s' ] );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Start execution — transition ready → running.
     *
     * @param int    $id            Row ID.
     * @param string $execution_id  Transient key from execute-api.
     * @return bool
     */
    public function start( int $id, string $execution_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_' . self::TABLE_SUFFIX;

        $updated = $wpdb->update(
            $table,
            [
                'state'        => self::STATE_RUNNING,
                'execution_id' => sanitize_text_field( $execution_id ),
                'started_at'   => current_time( 'mysql', true ),
            ],
            [ 'id' => $id, 'state' => self::STATE_READY ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );

        return (bool) $updated;
    }

    /**
     * Complete execution — running → completed → consumed.
     *
     * @param int   $id
     * @param array $result_summary
     * @return bool
     */
    public function complete( int $id, array $result_summary = [] ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_' . self::TABLE_SUFFIX;

        $updated = $wpdb->update(
            $table,
            [
                'state'          => self::STATE_CONSUMED,
                'result_summary' => wp_json_encode( $result_summary, JSON_UNESCAPED_UNICODE ),
                'completed_at'   => current_time( 'mysql', true ),
            ],
            [ 'id' => $id, 'state' => self::STATE_RUNNING ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );

        // Also mark the task in bizcity_tasks as consumed.
        if ( $updated ) {
            $row = $this->get( $id );
            if ( $row && $row->task_id > 0 ) {
                $this->mark_task_consumed( (int) $row->task_id );
            }
        }

        return (bool) $updated;
    }

    /**
     * Fail execution — running → failed → consumed.
     *
     * @param int    $id
     * @param string $error_message
     * @return bool
     */
    public function fail( int $id, string $error_message = '' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_' . self::TABLE_SUFFIX;

        $updated = $wpdb->update(
            $table,
            [
                'state'          => self::STATE_CONSUMED,
                'result_summary' => wp_json_encode( [ 'error' => $error_message ], JSON_UNESCAPED_UNICODE ),
                'completed_at'   => current_time( 'mysql', true ),
            ],
            [ 'id' => $id, 'state' => self::STATE_RUNNING ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );

        if ( $updated ) {
            $row = $this->get( $id );
            if ( $row && $row->task_id > 0 ) {
                $this->mark_task_consumed( (int) $row->task_id );
            }
        }

        return (bool) $updated;
    }

    /**
     * Get a one-shot record.
     *
     * @param int $id
     * @return object|null
     */
    public function get( int $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_' . self::TABLE_SUFFIX;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    /**
     * Get the ready one-shot for a task (if any).
     *
     * @param int $task_id
     * @return object|null
     */
    public function get_ready_for_task( int $task_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_' . self::TABLE_SUFFIX;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE task_id = %d AND state = %s ORDER BY id DESC LIMIT 1",
            $task_id,
            self::STATE_READY
        ) );
    }

    /**
     * Mark a bizcity_tasks row as "one-shot consumed" so it won't be re-triggered.
     *
     * Task remains visible in builder for audit.
     *
     * @param int $task_id
     */
    private function mark_task_consumed( int $task_id ) {
        global $wpdb;
        $table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $wpdb->update(
            $table,
            [ 'mode' => 'one_shot_consumed' ],
            [ 'id' => $task_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }
}
