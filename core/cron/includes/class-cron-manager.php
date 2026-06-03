<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_Cron_Manager — Phase 1 (Registry + Observability).
 *
 *   - register()       : declare a job (idempotent; UNIQUE on job_id).
 *   - all()            : list registered jobs + computed health (next/last run).
 *   - record_runs(*)   : internal hooks that wrap real handlers to log start/end.
 *   - gc_runs()        : retention sweep (runs nightly via own hook).
 *
 * IMPORTANT: this class does NOT call `wp_schedule_event` for you when
 * `enabled=false`, but for normal jobs it DOES — replacing the per-module
 * boilerplate. It also keeps the original hook name verbatim so existing
 * action listeners keep working without modification.
 *
 * Anti-patterns enforced: see core/cron/PHASE-CRON.md §6.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Cron_Manager {

	const TABLE_REGISTRY = 'bizcity_cron_registry';
	const TABLE_RUNS     = 'bizcity_cron_runs';
	const TABLE_RETRIES  = 'bizcity_cron_retries';

	const DB_VERSION        = '1.2.0';
	const DB_VERSION_OPTION = 'bizcity_cron_db_version';

	/** Internal nightly GC hook. */
	const GC_HOOK = 'bizcity_cron_runs_gc';

	/** Retry dispatch hook (every 5 min). */
	const RETRY_HOOK = 'bizcity_cron_retry_dispatch';

	private static ?self $instance = null;

	/** @var array<string, array> in-memory copy of registered jobs (job_id => row). */
	private array $jobs = array();

	/** @var array<string, int> active run ids keyed by job_id (for the wrap_end callback). */
	private array $active_runs = array();

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Self-GC: keep runs table small.
		add_action( self::GC_HOOK, [ $this, 'gc_runs' ] );
		add_action( 'init', [ $this, 'ensure_gc_scheduled' ] );

		// Retry dispatcher (Phase 2).
		add_action( self::RETRY_HOOK, [ $this, 'dispatch_retries' ] );
		add_action( 'init', [ $this, 'ensure_retry_scheduled' ] );
	}

	public function ensure_gc_scheduled(): void {
		if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::GC_HOOK );
		}
	}

	public function ensure_retry_scheduled(): void {
		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			$interval = 'bizcity_5min';
			// Fallback: hourly if 5min interval not registered yet.
			$schedules = wp_get_schedules();
			if ( ! isset( $schedules[ $interval ] ) ) {
				$interval = 'hourly';
			}
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, $interval, self::RETRY_HOOK );
		}
	}

	/**
	 * Provisioner-friendly installer entry. Creates / heals both cron tables
	 * via the JSON changelog auto-create pipeline, then bumps the version
	 * option so the diagnostics page shows green.
	 *
	 * Registered via `bizcity_register_installers` filter (installer id `cron`).
	 */
	public static function maybe_install(): void {
		if ( ! class_exists( 'BizCity_Diagnostics_Auto_Create' ) ) {
			return; // diagnostics not loaded — soft-skip; will heal next pageload.
		}
		BizCity_Diagnostics_Auto_Create::run( self::TABLE_REGISTRY );
		BizCity_Diagnostics_Auto_Create::run( self::TABLE_RUNS );
		BizCity_Diagnostics_Auto_Create::run( self::TABLE_RETRIES );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Register a cron job. Idempotent — calling twice with same job_id updates the row.
	 *
	 * @param array{
	 *   id:string, hook:string, interval:string,
	 *   owner?:string, description?:string,
	 *   singleton?:bool, enabled?:bool, retention?:int
	 * } $spec
	 * @return bool true on success / refresh, false on hard error.
	 */
	public function register( array $spec ): bool {
		$job_id   = (string) ( $spec['id']       ?? '' );
		$hook     = (string) ( $spec['hook']     ?? '' );
		$interval = (string) ( $spec['interval'] ?? '' );
		if ( $job_id === '' || $hook === '' || $interval === '' ) {
			return false;
		}

		$row = array(
			'job_id'         => $job_id,
			'hook'           => $hook,
			'interval_key'   => $interval,
			'owner'          => (string) ( $spec['owner']       ?? '' ),
			'description'    => (string) ( $spec['description'] ?? '' ),
			'singleton'      => ! empty( $spec['singleton'] ?? true )  ? 1 : 0,
			'enabled'        => ! empty( $spec['enabled']   ?? true )  ? 1 : 0,
			'retention_days' => (int)    ( $spec['retention']   ?? 7 ),
		);

		$this->jobs[ $job_id ] = $row + array( 'registered_at' => time() );

		// Persist (best-effort; if table missing diagnostics auto-create will heal it).
		$this->upsert_registry( $row );

		// Backward-compat: only auto-schedule if enabled + singleton AND not in adopt mode.
		$adopt_only = ! empty( $spec['adopt_only'] );
		if ( ! $adopt_only && $row['enabled'] && $row['singleton'] && ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 5, $interval, $hook );
		}

		// Wire trace hooks (priority 1 = before, PHP_INT_MAX = after).
		// Guard so multiple register() calls don't stack listeners.
		$marker_pre  = '_bizcity_cron_pre_'  . md5( $job_id );
		$marker_post = '_bizcity_cron_post_' . md5( $job_id );
		if ( ! has_action( $hook, [ $this, $marker_pre ] ) ) {
			// Closures preserve $job_id so we can find the run record back.
			add_action( $hook, function () use ( $job_id ) {
				$this->wrap_start( $job_id );
			}, 1 );
			add_action( $hook, function () use ( $job_id ) {
				$this->wrap_end( $job_id, null );
			}, PHP_INT_MAX );
		}

		return true;
	}

	/**
	 * List jobs + computed health for the diagnostics page / MCP tool.
	 *
	 * @return array<int, array>
	 */
	public function all(): array {
		// Lazy discovery: any bizcity_* hook scheduled by other code paths gets
		// adopted into the registry so the diagnostics page shows the full picture.
		$this->discover_legacy_crons();

		$out = array();
		foreach ( $this->jobs as $job_id => $row ) {
			$next = wp_next_scheduled( $row['hook'] );
			$last = $this->last_run( $job_id );
			$out[] = array(
				'job_id'        => $job_id,
				'hook'          => $row['hook'],
				'interval_key'  => $row['interval_key'],
				'owner'         => $row['owner'],
				'description'   => $row['description'],
				'enabled'       => (bool) $row['enabled'],
				'next_run_at'   => $next ? (int) $next : 0,
				'last_run_at'   => $last['started_at_ts'] ?? 0,
				'last_status'   => $last['status']        ?? '',
				'last_duration' => $last['duration_ms']   ?? null,
				'last_error'    => $last['error']         ?? '',
			);
		}
		return $out;
	}

	/**
	 * Adopt a hook already scheduled by external code (does NOT call
	 * wp_schedule_event). Use when migrating legacy crons that still own their
	 * own scheduling lifecycle but need meta/trace + admin visibility.
	 */
	public function adopt( string $job_id, string $hook, string $interval, string $owner = '', string $description = '' ): bool {
		return $this->register( array(
			'id'          => $job_id,
			'hook'        => $hook,
			'interval'    => $interval,
			'owner'       => $owner ?: 'legacy',
			'description' => $description,
			'adopt_only'  => true,
		) );
	}

	/**
	 * Scan WP cron array, auto-adopt any bizcity-namespaced hook that isn't
	 * already in the registry. Runs at most once per request.
	 */
	private function discover_legacy_crons(): void {
		static $done = false;
		if ( $done ) { return; }
		$done = true;

		if ( ! function_exists( '_get_cron_array' ) ) { return; }
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) { return; }

		// Build hook → interval map + already-adopted hook set.
		$known_hooks = array();
		foreach ( $this->jobs as $j ) {
			$known_hooks[ $j['hook'] ] = true;
		}

		$prefixes = array( 'bizcity_', 'biz_', 'twf_', 'twinchat_', 'waic_', 'wai_', 'bzgoogle_' );

		foreach ( $cron as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) { continue; }
			foreach ( $hooks as $hook => $events ) {
				if ( isset( $known_hooks[ $hook ] ) ) { continue; }
				$match = false;
				foreach ( $prefixes as $p ) {
					if ( str_starts_with( $hook, $p ) ) { $match = true; break; }
				}
				if ( ! $match ) { continue; }
				if ( ! is_array( $events ) ) { continue; }
				// Take the first event to read schedule.
				$ev = reset( $events );
				$interval = is_array( $ev ) && ! empty( $ev['schedule'] ) ? (string) $ev['schedule'] : 'hourly';
				$this->adopt(
					'auto.' . $hook,
					$hook,
					$interval,
					'discovered',
					'Auto-discovered legacy cron (not yet migrated to BizCity_Cron_Manager).'
				);
				$known_hooks[ $hook ] = true;
			}
		}
	}

	/* ───────────── internal ───────────── */

	private function upsert_registry( array $row ): void {
		global $wpdb;
		if ( ! $wpdb ) { return; }
		$t = $wpdb->prefix . self::TABLE_REGISTRY;
		// Suppress errors when table missing on first activation — auto-create will fix.
		$wpdb->suppress_errors( true );
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE job_id=%s LIMIT 1", $row['job_id'] ) );
		// Explicit format arrays prevent wpdb from issuing SHOW FULL COLUMNS to detect types.
		$fmt = [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' ]; // job_id,hook,interval_key,owner,description,singleton,enabled,retention_days
		if ( $exists ) {
			$wpdb->update( $t, $row, array( 'id' => $exists ), $fmt, [ '%d' ] );
		} else {
			$wpdb->insert( $t, $row, $fmt );
		}
		$wpdb->suppress_errors( false );
	}

	private function wrap_start( string $job_id ): void {
		global $wpdb;
		if ( ! $wpdb ) { return; }
		$t = $wpdb->prefix . self::TABLE_RUNS;
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( $t, array(
			'job_id'     => $job_id,
			'started_at' => current_time( 'mysql', true ),
			'status'     => 'running',
		) );
		if ( $ok ) {
			$this->active_runs[ $job_id ] = (int) $wpdb->insert_id;
		}
		$wpdb->suppress_errors( false );
	}

	private function wrap_end( string $job_id, ?\Throwable $err ): void {
		global $wpdb;
		if ( ! $wpdb || empty( $this->active_runs[ $job_id ] ) ) { return; }
		$run_id = (int) $this->active_runs[ $job_id ];
		unset( $this->active_runs[ $job_id ] );

		$t = $wpdb->prefix . self::TABLE_RUNS;
		$wpdb->suppress_errors( true );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT started_at FROM {$t} WHERE id=%d", $run_id ), ARRAY_A );
		$duration_ms = null;
		if ( $row && ! empty( $row['started_at'] ) ) {
			$dt = strtotime( $row['started_at'] . ' UTC' );
			if ( $dt ) {
				$duration_ms = max( 0, (int) ( ( microtime( true ) - $dt ) * 1000 ) );
			}
		}
		$wpdb->update( $t, array(
			'ended_at'    => current_time( 'mysql', true ),
			'duration_ms' => $duration_ms,
			'status'      => $err ? 'error' : 'ok',
			'error'       => $err ? $err->getMessage() : null,
			'trace'       => $err ? mb_substr( (string) $err->getTraceAsString(), 0, 4000 ) : null,
		), array( 'id' => $run_id ) );
		$wpdb->suppress_errors( false );
	}

	/**
	 * Run $fn inside a synthetic cron run so handlers can use note() /
	 * note_event() even outside a real cron tick (admin QA harness / Lab).
	 *
	 * Writes a row to bizcity_cron_runs with the given $job_id (recommended
	 * prefix: 'lab.*') and returns the row id. Captures thrown exceptions but
	 * RE-THROWS them after marking the run as error (caller can decide).
	 *
	 * @param string   $job_id Synthetic job identifier (e.g. 'lab.automation.fire-now').
	 * @param callable $fn     Closure executed inside the run window.
	 * @return int Run row id (0 on DB failure).
	 */
	public function with_synthetic_run( string $job_id, callable $fn ): int {
		$this->wrap_start( $job_id );
		$run_id = (int) ( $this->active_runs[ $job_id ] ?? 0 );
		$err    = null;
		try {
			$fn();
		} catch ( \Throwable $e ) {
			$err = $e;
		}
		$this->wrap_end( $job_id, $err );
		if ( $err ) { throw $err; }
		return $run_id;
	}

	/**
	 * Return the active run id for the currently-executing job (if any). Useful
	 * for handlers / hook subscribers wanting to attach meta to the parent cron
	 * run row (R-CRON-META). Returns 0 outside a wrap_start/wrap_end window.
	 */
	public function current_run_id( ?string $job_id = null ): int {
		if ( $job_id !== null ) {
			return (int) ( $this->active_runs[ $job_id ] ?? 0 );
		}
		if ( empty( $this->active_runs ) ) { return 0; }
		// Return the most recently started (end of array).
		$ids = array_values( $this->active_runs );
		return (int) end( $ids );
	}

	/**
	 * Merge a structured JSON patch into the current run's meta column.
	 *
	 * R-CRON-META: every cron job MUST persist enough JSON evidence per run so
	 * that post-mortem analysis is possible (which FB page failed, which event
	 * id, Graph error code, token expired vs timeout vs missing perm, partial
	 * batch counts, etc.).
	 *
	 * Silent no-op when called outside an active run (safe to call from hooks
	 * that may fire both inside and outside cron context).
	 *
	 * @param array       $patch Top-level fields to deep-merge.
	 * @param string|null $job_id Optional explicit job id when multiple runs are nested.
	 */
	public function note( array $patch, ?string $job_id = null ): void {
		$run_id = $this->current_run_id( $job_id );
		if ( ! $run_id ) { return; }
		$this->merge_meta( $run_id, $patch );
	}

	/**
	 * Append a timeline entry into meta.events[]. Same scope rules as note().
	 *
	 * @param string $name  Event name (e.g. 'graph_call', 'token_missing', 'item_done').
	 * @param array  $data  Arbitrary JSON-serialisable payload.
	 * @param string|null $job_id Optional explicit job id.
	 */
	public function note_event( string $name, array $data = array(), ?string $job_id = null ): void {
		$run_id = $this->current_run_id( $job_id );
		if ( ! $run_id ) { return; }
		$entry = array(
			'ts'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'name' => $name,
		);
		if ( $data ) { $entry['data'] = $data; }
		$this->merge_meta( $run_id, array( '__append_event' => $entry ) );
	}

	private function merge_meta( int $run_id, array $patch ): void {
		global $wpdb;
		if ( ! $wpdb || $run_id <= 0 ) { return; }
		$t = $wpdb->prefix . self::TABLE_RUNS;
		$wpdb->suppress_errors( true );
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$t} WHERE id=%d", $run_id ) );
		$current = array();
		if ( $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) { $current = $decoded; }
		}
		// Handle special __append_event marker.
		if ( isset( $patch['__append_event'] ) ) {
			$evt = $patch['__append_event'];
			unset( $patch['__append_event'] );
			if ( ! isset( $current['events'] ) || ! is_array( $current['events'] ) ) {
				$current['events'] = array();
			}
			$current['events'][] = $evt;
			// Cap events array to prevent unbounded growth.
			if ( count( $current['events'] ) > 200 ) {
				$current['events'] = array_slice( $current['events'], -200 );
			}
		}
		if ( $patch ) {
			$current = $this->deep_merge( $current, $patch );
		}
		$encoded = wp_json_encode( $current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) { $encoded = '{}'; }
		// Hard cap at 256 KB to avoid bloating MySQL rows.
		if ( strlen( $encoded ) > 262144 ) {
			$encoded = wp_json_encode( array( '_truncated' => true, 'size' => strlen( $encoded ) ) );
		}
		$wpdb->update( $t, array( 'meta' => $encoded ), array( 'id' => $run_id ) );
		$wpdb->suppress_errors( false );
	}

	private function deep_merge( array $base, array $patch ): array {
		foreach ( $patch as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] )
				&& ! $this->is_list( $v ) && ! $this->is_list( $base[ $k ] ) ) {
				$base[ $k ] = $this->deep_merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	private function is_list( array $a ): bool {
		if ( $a === array() ) { return true; }
		return array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * Fetch latest run row for a job (used by all() + probe).
	 *
	 * @return array{started_at_ts:int,status:string,duration_ms:?int,error:string}|array{}
	 */
	public function last_run( string $job_id ): array {
		global $wpdb;
		if ( ! $wpdb ) { return array(); }
		$t = $wpdb->prefix . self::TABLE_RUNS;
		$wpdb->suppress_errors( true );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT started_at, status, duration_ms, error FROM {$t} WHERE job_id=%s ORDER BY id DESC LIMIT 1",
			$job_id
		), ARRAY_A );
		$wpdb->suppress_errors( false );
		if ( ! $row ) { return array(); }
		$ts = strtotime( ( (string) $row['started_at'] ) . ' UTC' );
		return array(
			'started_at_ts' => $ts ? (int) $ts : 0,
			'status'        => (string) $row['status'],
			'duration_ms'   => is_null( $row['duration_ms'] ) ? null : (int) $row['duration_ms'],
			'error'         => (string) ( $row['error'] ?? '' ),
		);
	}

	/**
	 * Delete runs older than retention_days per job. Runs nightly via GC_HOOK.
	 */
	public function gc_runs(): void {
		global $wpdb;
		if ( ! $wpdb ) { return; }
		$tr = $wpdb->prefix . self::TABLE_REGISTRY;
		$tu = $wpdb->prefix . self::TABLE_RUNS;
		$wpdb->suppress_errors( true );
		$rows = (array) $wpdb->get_results( "SELECT job_id, retention_days FROM {$tr}", ARRAY_A );
		foreach ( $rows as $r ) {
			$days = max( 1, (int) ( $r['retention_days'] ?? 7 ) );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$tu} WHERE job_id=%s AND started_at < (UTC_TIMESTAMP() - INTERVAL %d DAY)",
				(string) $r['job_id'], $days
			) );
		}
		$wpdb->suppress_errors( false );
	}

	/* ───────────── Phase 2: run-now, retry queue ───────────── */

	/**
	 * Immediately invoke a registered job's hook (synchronous). Used by admin
	 * "Run Now" button and the cron.run_one MCP tool. Returns ok/error info.
	 *
	 * @return array{ok:bool,job_id:string,duration_ms:int,error:string}
	 */
	public function run_now( string $job_id ): array {
		$t0 = microtime( true );
		if ( ! isset( $this->jobs[ $job_id ] ) ) {
			return [ 'ok' => false, 'job_id' => $job_id, 'duration_ms' => 0, 'error' => 'unknown_job' ];
		}
		$hook = (string) $this->jobs[ $job_id ]['hook'];
		try {
			do_action( $hook );
			return [
				'ok'          => true,
				'job_id'      => $job_id,
				'duration_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'error'       => '',
			];
		} catch ( \Throwable $e ) {
			$this->enqueue_retry( $job_id, $e->getMessage() );
			return [
				'ok'          => false,
				'job_id'      => $job_id,
				'duration_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'error'       => $e->getMessage(),
			];
		}
	}

	/**
	 * Push a failed job into the retry queue with exponential backoff:
	 *   attempt 1 → +1 min · attempt 2 → +5 min · attempt 3 → +30 min · attempt ≥4 → dead.
	 */
	public function enqueue_retry( string $job_id, string $error = '' ): bool {
		global $wpdb;
		if ( ! $wpdb || ! isset( $this->jobs[ $job_id ] ) ) { return false; }
		$t = $wpdb->prefix . self::TABLE_RETRIES;
		$wpdb->suppress_errors( true );

		// Find existing live retry row (status='pending') for this job to bump attempt.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, attempt FROM {$t} WHERE job_id=%s AND status='pending' ORDER BY id DESC LIMIT 1",
			$job_id
		), ARRAY_A );

		$attempt = $existing ? ( (int) $existing['attempt'] + 1 ) : 1;
		$delays  = [ 1 => 60, 2 => 300, 3 => 1800 ]; // seconds
		if ( $attempt > 3 ) {
			$status   = 'dead';
			$next_run = current_time( 'mysql', true );
		} else {
			$status   = 'pending';
			$next_run = gmdate( 'Y-m-d H:i:s', time() + $delays[ $attempt ] );
		}

		$row = [
			'job_id'       => $job_id,
			'attempt'      => $attempt,
			'status'       => $status,
			'next_run_at'  => $next_run,
			'last_error'   => mb_substr( $error, 0, 2000 ),
			'updated_at'   => current_time( 'mysql', true ),
		];
		if ( $existing ) {
			$wpdb->update( $t, $row, [ 'id' => (int) $existing['id'] ] );
		} else {
			$row['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( $t, $row );
		}
		$wpdb->suppress_errors( false );
		return true;
	}

	/**
	 * Cron hook callback — process due retries (status=pending AND next_run_at <= now).
	 */
	public function dispatch_retries(): void {
		global $wpdb;
		if ( ! $wpdb ) { return; }
		$t = $wpdb->prefix . self::TABLE_RETRIES;
		$wpdb->suppress_errors( true );
		$rows = (array) $wpdb->get_results(
			"SELECT id, job_id FROM {$t} WHERE status='pending' AND next_run_at <= UTC_TIMESTAMP() LIMIT 20",
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$job_id = (string) $r['job_id'];
			$res    = $this->run_now( $job_id );
			if ( $res['ok'] ) {
				$wpdb->update( $t,
					[ 'status' => 'done', 'updated_at' => current_time( 'mysql', true ) ],
					[ 'id' => (int) $r['id'] ]
				);
			}
			// On failure, run_now() already called enqueue_retry() which bumped attempt.
		}
		$wpdb->suppress_errors( false );
	}

	/**
	 * List recent runs for a job (admin UI + MCP).
	 *
	 * @return array<int,array>
	 */
	public function recent_runs( string $job_id, int $limit = 20 ): array {
		global $wpdb;
		if ( ! $wpdb ) { return []; }
		$t = $wpdb->prefix . self::TABLE_RUNS;
		$wpdb->suppress_errors( true );
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, started_at, ended_at, duration_ms, status, error, meta FROM {$t} WHERE job_id=%s ORDER BY id DESC LIMIT %d",
			$job_id, max( 1, min( 100, $limit ) )
		), ARRAY_A );
		$wpdb->suppress_errors( false );
		return $rows;
	}
}
