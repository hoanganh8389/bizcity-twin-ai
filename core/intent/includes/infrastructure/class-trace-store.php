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

	/** @var string traces table (one per chat turn) */
	private $traces_table;

	/** @var string tasks table — thinking steps + SSE event replay */
	private $tasks_table;

	/** @var string runs table — Phase 0.13 RunState persistence */
	private $runs_table;

	/** @var string resume_signals table — HIL decisions inbox */
	private $resume_signals_table;

	/** @var bool tables verified */
	private $tables_ready = false;

	/** @var string current trace_id for this request */
	private static $current_trace_id = '';

	/** @var int current trace DB row id */
	private static $current_trace_row_id = 0;

	/** @var int task counter for ordering */
	private static $task_seq = 0;

	const DB_VERSION        = '2.1';  // 2.1: renamed runs→bizcity_twin_runs, signals→bizcity_twin_hil (avoid schema collision)
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
		$this->traces_table        = $wpdb->prefix . 'bizcity_traces';
		$this->tasks_table         = $wpdb->prefix . 'bizcity_trace_tasks';
		$this->runs_table          = $wpdb->prefix . 'bizcity_twin_runs';
		$this->resume_signals_table = $wpdb->prefix . 'bizcity_twin_hil';
	}

	/* ================================================================
	 * SCHEMA — CREATE TABLES
	 * ================================================================ */

	public function ensure_tables(): void {
		// Always refresh runs/HIL table names via Installer (handles multisite prefix correctly).
		if ( class_exists( 'BizCity_Twin_DB_Installer' ) ) {
			$this->runs_table           = BizCity_Twin_DB_Installer::runs_table();
			$this->resume_signals_table = BizCity_Twin_DB_Installer::hil_table();
		} else {
			global $wpdb;
			$this->runs_table           = $wpdb->prefix . 'bizcity_twin_runs';
			$this->resume_signals_table = $wpdb->prefix . 'bizcity_twin_hil';
		}

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

		// runs + HIL tables are now managed by BizCity_Twin_DB_Installer (core/runtime).
		// Call installer here as fallback in case runtime bootstrap hasn't run yet.
		if ( class_exists( 'BizCity_Twin_DB_Installer' ) ) {
			BizCity_Twin_DB_Installer::maybe_install();
		}

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

		// Phase 0.12 Wave B+ — mirror to Twin Event Stream (additive, non-blocking).
		self::emit_event_v2( 'turn_start', [
			'mode'       => $context['mode']       ?? 'chat',
			'intent_key' => $context['intent_key'] ?? '',
			'skill_key'  => $context['skill_key']  ?? '',
			'tool_name'  => $context['tool_name']  ?? '',
			'title'      => $context['title']      ?? '',
		], [
			'trace_id'        => $trace_id,
			'session_id'      => $context['session_id']      ?? '',
			'conversation_id' => $context['conv_id']         ?? null,
			'user_id'         => (int) ( $context['user_id'] ?? get_current_user_id() ),
			'event_source'    => 'twinchat',
		] );

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

		// Phase 0.12 Wave B+ — mirror to Twin Event Stream (additive, non-blocking).
		self::emit_event_v2( 'turn_complete', [
			'success'       => ( $status === 'success' ),
			'status'        => $status,
			'duration_ms'   => $total_ms,
			'input_tokens'  => (int) ( $final_meta['input_tokens']  ?? 0 ),
			'output_tokens' => (int) ( $final_meta['output_tokens'] ?? 0 ),
			'task_count'    => self::$task_seq,
		], [
			'trace_id'     => self::$current_trace_id,
			'event_source' => 'twinchat',
		] );
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

		// Phase 0.12 Wave B+ — mirror to Twin Event Stream (additive, non-blocking).
		self::emit_event_v2( 'decision', [
			'stage'       => $step,
			'thinking'    => $thinking,
			'tool_name'   => $meta['tool_name']     ?? '',
			'status'      => $meta['status']        ?? 'done',
			'attempt'     => (int) ( $meta['attempt'] ?? 1 ),
			'duration_ms' => (int) ( $meta['duration_ms'] ?? 0 ),
			'task_id'     => $task_id,
			'seq'         => self::$task_seq,
			'token_usage' => $meta['token_usage']   ?? '',
			'error'       => $meta['error']         ?? null,
		], [
			'trace_id'     => self::$current_trace_id,
			'event_source' => 'twinchat',
		] );

		return $task_id;
	}

	/* ================================================================
	 * PHASE 0.12 — TWIN EVENT STREAM BRIDGE
	 * ================================================================ */

	/**
	 * Mirror a trace lifecycle event into `bizcity_twin_event_stream`.
	 *
	 * Additive bridge — never throws into the legacy trace path. Silent
	 * fallback if Event_Bus is not loaded (e.g. early boot, CLI, tests).
	 *
	 * @param string $event_type One of BizCity_Twin_Event_Taxonomy::* constants.
	 * @param array  $payload    Event payload.
	 * @param array  $opts       {trace_id, session_id, conversation_id, user_id, event_source}
	 */
	private static function emit_event_v2( string $event_type, array $payload, array $opts = [] ): void {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return;
		}
		try {
			BizCity_Twin_Event_Bus::dispatch_v2( $event_type, $payload, $opts );
		} catch ( \Throwable $e ) {
			// Never break the legacy trace path on stream-side failures.
			error_log( '[Trace_Store] event_v2 emit failed (' . $event_type . '): ' . $e->getMessage() );
		}
	}

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

	/* ================================================================
	 * PHASE 0.13 — RUN STATE MANAGEMENT
	 *
	 * These methods manage bizcity_trace_runs (RunState persistence).
	 * Column mapping vs Phase 0.13 spec:
	 *   spec bizcity_runs     → bizcity_trace_runs  (same data, different prefix)
	 *   spec bizcity_run_events → bizcity_trace_tasks (reuse: trace_id=run_id, step=event_type, output_json=payload)
	 * ================================================================ */

	/**
	 * Create a new run record.
	 *
	 * @param string $run_id          Unique run ID (run_<uuid>)
	 * @param string $conversation_id Conversation this run belongs to
	 * @param string $agent_name      Agent being run
	 * @param int    $user_id
	 * @param string $state_json      Serialized RunState (BizCity_Twin_RunState::to_string())
	 * @param array  $opts            {trace_id, parent_run_id, context_snapshot}
	 * @return bool
	 */
	public function create_run(
		string $run_id,
		string $conversation_id,
		string $agent_name,
		int $user_id,
		string $state_json,
		array $opts = []
	): bool {
		$this->ensure_tables();
		global $wpdb;

		$result = $wpdb->insert( $this->runs_table, [
			'run_id'           => $run_id,
			'conversation_id'  => $conversation_id,
			'agent_name'       => $agent_name,
			'user_id'          => $user_id,
			'status'           => 'running',
			'state_json'       => $state_json,
			'interruptions'    => null,
			'context_snapshot' => isset( $opts['context_snapshot'] ) ? wp_json_encode( $opts['context_snapshot'], JSON_UNESCAPED_UNICODE ) : null,
			'parent_run_id'    => $opts['parent_run_id'] ?? null,
			'trace_id'         => $opts['trace_id'] ?? self::$current_trace_id,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		] );

		return $result !== false;
	}

	/**
	 * Load a run by run_id.
	 *
	 * @param string $run_id
	 * @return array|null Row as associative array, or null if not found.
	 */
	public function load_run( string $run_id ): ?array {
		$this->ensure_tables();
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$this->runs_table}` WHERE `run_id` = %s",
				$run_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Persist updated RunState after each turn.
	 *
	 * @param string $run_id
	 * @param string $state_json  New serialized state
	 * @param string $status      running|paused_hil|completed|failed
	 * @param array  $interruptions  Current interruptions array
	 */
	public function save_run_state(
		string $run_id,
		string $state_json,
		string $status,
		array $interruptions = []
	): void {
		$this->ensure_tables();
		global $wpdb;

		$wpdb->update(
			$this->runs_table,
			[
				'status'        => $status,
				'state_json'    => $state_json,
				'interruptions' => ! empty( $interruptions ) ? wp_json_encode( $interruptions, JSON_UNESCAPED_UNICODE ) : null,
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'run_id' => $run_id ]
		);
	}

	/**
	 * Record an SSE event for replay.
	 *
	 * Reuses bizcity_trace_tasks columns:
	 *   trace_id   = run_id
	 *   seq        = event sequence number
	 *   step       = event_type  (node_start|node_end|tool_call|state_diff|interrupt|final)
	 *   output_json = event payload JSON
	 *
	 * @param string $run_id
	 * @param int    $seq
	 * @param string $event_type
	 * @param array  $payload
	 */
	public function append_run_event(
		string $run_id,
		int $seq,
		string $event_type,
		array $payload
	): void {
		$this->ensure_tables();
		global $wpdb;

		$wpdb->insert( $this->tasks_table, [
			'trace_id'   => $run_id,
			'task_id'    => $run_id . '_e' . $seq,
			'seq'        => $seq,
			'step'       => $event_type,
			'title'      => $event_type,
			'status'     => 'done',
			'output_json' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'created_at' => current_time( 'mysql' ),
		] );
	}

	/**
	 * Get SSE events for a run (for replay from last_seq).
	 *
	 * @param string $run_id
	 * @param int    $last_seq  Return events with seq > last_seq
	 * @return array
	 */
	public function get_run_events( string $run_id, int $last_seq = 0 ): array {
		$this->ensure_tables();
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `seq`, `step` AS `event_type`, `output_json` AS `payload`, `created_at`
				FROM `{$this->tasks_table}`
				WHERE `trace_id` = %s AND `seq` > %d
				ORDER BY `seq` ASC",
				$run_id,
				$last_seq
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Write a HIL decision (approve/reject) to the resume_signals inbox.
	 *
	 * Called when user clicks Approve/Reject in <TwinApprovalCards/>.
	 * Runner polls this table when resuming a paused_hil run.
	 *
	 * @param string $run_id
	 * @param string $call_id    Tool call ID being decided
	 * @param string $decision   'approved' | 'rejected'
	 * @param string $reason     Optional rejection reason
	 * @param int    $decided_by User ID
	 */
	public function write_decision(
		string $run_id,
		string $call_id,
		string $decision,
		string $reason = '',
		int $decided_by = 0
	): bool {
		$this->ensure_tables();
		global $wpdb;

		// decision values whitelist
		if ( ! in_array( $decision, [ 'approved', 'rejected' ], true ) ) {
			return false;
		}

		$result = $wpdb->replace( $this->resume_signals_table, [
			'run_id'     => $run_id,
			'call_id'    => $call_id,
			'decision'   => $decision,
			'reason'     => $reason ?: null,
			'decided_by' => $decided_by ?: get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
		] );

		return $result !== false;
	}

	/**
	 * Fetch all decisions for a run (called by Runner on resume).
	 *
	 * @param string $run_id
	 * @return array  [ call_id => [ decision, reason ], ... ]
	 */
	public function get_decisions( string $run_id ): array {
		$this->ensure_tables();
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `call_id`, `decision`, `reason` FROM `{$this->resume_signals_table}` WHERE `run_id` = %s",
				$run_id
			),
			ARRAY_A
		) ?: [];

		$out = [];
		foreach ( $rows as $row ) {
			$out[ $row['call_id'] ] = [
				'decision' => $row['decision'],
				'reason'   => $row['reason'],
			];
		}
		return $out;
	}

	/**
	 * Get paused runs waiting for HIL decision (for admin Working Panel).
	 *
	 * @param int $user_id
	 * @return array
	 */
	public function get_paused_runs( int $user_id = 0 ): array {
		$this->ensure_tables();
		global $wpdb;

		$where = $user_id > 0
			? $wpdb->prepare( 'WHERE `status` = %s AND `user_id` = %d', 'paused_hil', $user_id )
			: $wpdb->prepare( 'WHERE `status` = %s', 'paused_hil' );

		return $wpdb->get_results(
			"SELECT `run_id`, `conversation_id`, `agent_name`, `user_id`, `interruptions`, `created_at`, `updated_at`
			FROM `{$this->runs_table}` {$where}
			ORDER BY `updated_at` DESC LIMIT 50",
			ARRAY_A
		) ?: [];
	}

	/**
	 * Expose table names for external classes (Runner, REST controller).
	 */
	public function runs_table(): string          { return $this->runs_table; }
	public function resume_signals_table(): string { return $this->resume_signals_table; }

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
