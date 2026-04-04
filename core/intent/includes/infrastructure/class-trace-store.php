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
 * BizCity Trace Store
 *
 * Persistent trace storage in database tables:
 *   bizcity_traces      — one row per user request / chat turn
 *   bizcity_trace_tasks — one row per pipeline step / tool execution
 *
 * Phase 1.7 Sprint 1 — replaces transient-only Execution Logger for persistence.
 * Transient logger remains active for realtime SSE; this class adds DB durability.
 *
 * @package BizCity_Intent
 * @since   4.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Trace_Store {

	/** @var BizCity_Trace_Store */
	private static $instance = null;

	/** @var string traces table */
	private $traces_table;

	/** @var string tasks table */
	private $tasks_table;

	/** @var bool tables verified */
	private $tables_ready = false;

	/** @var string current trace_id for this request */
	private static $current_trace_id = '';

	/** @var int current trace DB row id */
	private static $current_trace_row_id = 0;

	/** @var int task counter for ordering */
	private static $task_seq = 0;

	const DB_VERSION        = '1.0';
	const DB_VERSION_OPTION = 'bizcity_trace_store_db_ver';

	/* ================================================================
	 * SINGLETON
	 * ================================================================ */

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->traces_table = $wpdb->prefix . 'bizcity_traces';
		$this->tasks_table  = $wpdb->prefix . 'bizcity_trace_tasks';
	}

	/* ================================================================
	 * SCHEMA — CREATE TABLES
	 * ================================================================ */

	public function ensure_tables(): void {
		if ( $this->tables_ready ) {
			return;
		}

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			$this->tables_ready = true;
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// Table 1: bizcity_traces — one per chat turn / request
		$sql_traces = "CREATE TABLE `{$this->traces_table}` (
			`id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`trace_id`        VARCHAR(80)    NOT NULL,
			`session_id`      VARCHAR(120)   NOT NULL DEFAULT '',
			`conv_id`         VARCHAR(80)    DEFAULT '',
			`message_id`      VARCHAR(80)    DEFAULT '',
			`user_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`intent_key`      VARCHAR(120)   DEFAULT '',
			`title`           VARCHAR(255)   DEFAULT '',
			`status`          VARCHAR(30)    NOT NULL DEFAULT 'running',
			`mode`            VARCHAR(40)    DEFAULT '',
			`skill_key`       VARCHAR(120)   DEFAULT '',
			`tool_name`       VARCHAR(120)   DEFAULT '',
			`total_tasks`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`completed_tasks` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`total_ms`        INT UNSIGNED   NOT NULL DEFAULT 0,
			`input_tokens`    INT UNSIGNED   NOT NULL DEFAULT 0,
			`output_tokens`   INT UNSIGNED   NOT NULL DEFAULT 0,
			`meta_json`       LONGTEXT       NULL,
			`created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`ended_at`        DATETIME       NULL,
			PRIMARY KEY  (`id`),
			UNIQUE KEY `uniq_trace_id` (`trace_id`),
			KEY `idx_session` (`session_id`),
			KEY `idx_conv` (`conv_id`),
			KEY `idx_user_created` (`user_id`, `created_at`),
			KEY `idx_status` (`status`)
		) {$charset};";

		// Table 2: bizcity_trace_tasks — one per pipeline node / thinking step
		$sql_tasks = "CREATE TABLE `{$this->tasks_table}` (
			`id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`trace_id`        VARCHAR(80)    NOT NULL,
			`task_id`         VARCHAR(80)    NOT NULL,
			`seq`             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`step`            VARCHAR(60)    NOT NULL,
			`title`           VARCHAR(255)   NOT NULL DEFAULT '',
			`thinking`        TEXT           NULL,
			`tool_name`       VARCHAR(120)   DEFAULT '',
			`status`          VARCHAR(30)    NOT NULL DEFAULT 'running',
			`attempt`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
			`skill_resolve`   VARCHAR(255)   DEFAULT '',
			`context_summary` TEXT           NULL,
			`input_json`      LONGTEXT       NULL,
			`output_json`     LONGTEXT       NULL,
			`token_usage`     VARCHAR(120)   DEFAULT '',
			`duration_ms`     INT UNSIGNED   NOT NULL DEFAULT 0,
			`error_message`   TEXT           NULL,
			`created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (`id`),
			KEY `idx_trace_seq` (`trace_id`, `seq`),
			KEY `idx_step` (`step`),
			KEY `idx_tool` (`tool_name`),
			KEY `idx_status` (`status`)
		) {$charset};";

		dbDelta( $sql_traces );
		dbDelta( $sql_tasks );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		$this->tables_ready = true;
	}

	/* ================================================================
	 * TRACE LIFECYCLE
	 * ================================================================ */

	/**
	 * Begin a new trace (one per chat turn / request).
	 *
	 * @param array $context {session_id, conv_id, message_id, user_id, intent_key, title, mode, skill_key}
	 * @return string trace_id
	 */
	public function begin_trace( array $context = [] ): string {
		$this->ensure_tables();

		global $wpdb;

		$trace_id = 'trc_' . bin2hex( random_bytes( 8 ) );
		self::$current_trace_id = $trace_id;
		self::$task_seq = 0;

		$wpdb->insert( $this->traces_table, [
			'trace_id'   => $trace_id,
			'session_id' => $context['session_id'] ?? '',
			'conv_id'    => $context['conv_id']    ?? '',
			'message_id' => $context['message_id'] ?? '',
			'user_id'    => $context['user_id']    ?? get_current_user_id(),
			'intent_key' => $context['intent_key'] ?? '',
			'title'      => $context['title']      ?? '',
			'mode'       => $context['mode']       ?? '',
			'skill_key'  => $context['skill_key']  ?? '',
			'tool_name'  => $context['tool_name']  ?? '',
			'status'     => 'running',
			'created_at' => current_time( 'mysql' ),
		] );

		self::$current_trace_row_id = (int) $wpdb->insert_id;

		return $trace_id;
	}

	/**
	 * Complete current trace.
	 *
	 * @param string $status      'success' | 'partial' | 'error'
	 * @param array  $final_meta  token_usage, summary, etc.
	 * @param int    $total_ms    Total duration in ms
	 */
	public function end_trace( string $status = 'success', array $final_meta = [], int $total_ms = 0 ): void {
		if ( ! self::$current_trace_id ) {
			return;
		}

		global $wpdb;

		$wpdb->update(
			$this->traces_table,
			[
				'status'          => $status,
				'total_tasks'     => self::$task_seq,
				'completed_tasks' => self::$task_seq, // assume all done (errors counted separately)
				'total_ms'        => $total_ms,
				'input_tokens'    => $final_meta['input_tokens']  ?? 0,
				'output_tokens'   => $final_meta['output_tokens'] ?? 0,
				'meta_json'       => ! empty( $final_meta ) ? wp_json_encode( $final_meta, JSON_UNESCAPED_UNICODE ) : null,
				'ended_at'        => current_time( 'mysql' ),
			],
			[ 'trace_id' => self::$current_trace_id ]
		);
	}

	/**
	 * Get current trace_id.
	 */
	public static function current_trace_id(): string {
		return self::$current_trace_id;
	}

	/* ================================================================
	 * TASK WRITING — "INNER MONOLOGUE" STEPS
	 * ================================================================ */

	/**
	 * Record a thinking step (inner monologue).
	 *
	 * @param string $step      Step code (gateway_entry, mode_classified, skill_lookup, tool_invoke, etc.)
	 * @param string $thinking  Human-readable inner monologue text
	 * @param array  $meta      {tool_name, skill_resolve, context_summary, input, output, duration_ms, status, error}
	 * @return string task_id
	 */
	public function record_step( string $step, string $thinking, array $meta = [] ): string {
		if ( ! self::$current_trace_id ) {
			return '';
		}

		$this->ensure_tables();

		global $wpdb;

		self::$task_seq++;
		$task_id = self::$current_trace_id . '_' . self::$task_seq;

		$wpdb->insert( $this->tasks_table, [
			'trace_id'        => self::$current_trace_id,
			'task_id'         => $task_id,
			'seq'             => self::$task_seq,
			'step'            => $step,
			'title'           => mb_substr( $thinking, 0, 255 ),
			'thinking'        => $thinking,
			'tool_name'       => $meta['tool_name']       ?? '',
			'status'          => $meta['status']          ?? 'done',
			'attempt'         => $meta['attempt']         ?? 1,
			'skill_resolve'   => $meta['skill_resolve']   ?? '',
			'context_summary' => $meta['context_summary'] ?? '',
			'input_json'      => isset( $meta['input'] )  ? wp_json_encode( $meta['input'], JSON_UNESCAPED_UNICODE )  : null,
			'output_json'     => isset( $meta['output'] ) ? wp_json_encode( $meta['output'], JSON_UNESCAPED_UNICODE ) : null,
			'token_usage'     => $meta['token_usage']     ?? '',
			'duration_ms'     => $meta['duration_ms']     ?? 0,
			'error_message'   => $meta['error']           ?? null,
			'created_at'      => current_time( 'mysql' ),
		] );

		return $task_id;
	}

	/* ================================================================
	 * READ — for Working Panel / admin history
	 * ================================================================ */

	/**
	 * Get trace by trace_id.
	 */
	public function get_trace( string $trace_id ): ?array {
		$this->ensure_tables();
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$this->traces_table}` WHERE `trace_id` = %s", $trace_id ),
			ARRAY_A
		);
	}

	/**
	 * Get tasks for a trace.
	 */
	public function get_tasks( string $trace_id ): array {
		$this->ensure_tables();
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$this->tasks_table}` WHERE `trace_id` = %s ORDER BY `seq` ASC",
				$trace_id
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Get recent traces for a session.
	 */
	public function get_session_traces( string $session_id, int $limit = 20 ): array {
		$this->ensure_tables();
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$this->traces_table}` WHERE `session_id` = %s ORDER BY `created_at` DESC LIMIT %d",
				$session_id,
				$limit
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Get recent traces for a user.
	 */
	public function get_user_traces( int $user_id, int $limit = 20 ): array {
		$this->ensure_tables();
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$this->traces_table}` WHERE `user_id` = %d ORDER BY `created_at` DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		) ?: [];
	}

	/* ================================================================
	 * CLEANUP
	 * ================================================================ */

	/**
	 * Purge traces older than $days.
	 */
	public function purge( int $days = 90 ): int {
		$this->ensure_tables();
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// Delete tasks first (foreign key reference)
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$this->tasks_table}` WHERE `trace_id` IN (SELECT `trace_id` FROM `{$this->traces_table}` WHERE `created_at` < %s)",
			$cutoff
		) );

		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$this->traces_table}` WHERE `created_at` < %s",
			$cutoff
		) );
	}
}
