<?php
/**
 * BizCity Diagnostics — Smoke Runner orchestrator (Phase 0.41 L9.a T2).
 *
 * Discovers probes via the `bizcity_diagnostics_register_probes` filter,
 * provides the catalog to the FE wizard, runs individual probes inside a
 * lightweight context that captures sub-steps, and routes cleanup.
 *
 * Probes register through the filter:
 *   add_filter( 'bizcity_diagnostics_register_probes', function( array $list ) {
 *       $list[] = 'BizCity_Probe_KG_Seeding';      // FQCN
 *       $list[] = new My_Custom_Probe();           // or instance
 *       return $list;
 *   } );
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21 (Phase 0.41 L9.a)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/interface-diagnostics-probe.php';

final class BizCity_Diagnostics_Smoke_Runner {

	/** @var array<string,BizCity_Diagnostics_Probe>|null memoized catalog */
	private static $catalog = null;
	/** @var int Catalog generation after the last probe discovery. */
	private static $catalog_generation = -1;

	/**
	 * Per-blog hard cap for one full run() — guards against runaway probes.
	 * Individual probes still have their own estimate_ms() budget.
	 */
	private const RUN_BUDGET_SECONDS = 30;

	/** @var array<int,string> Probes that orchestrate nested CLI processes and belong to direct checks. */
	private const RUN_ALL_EXCLUDED_IDS = array( 'core.framework.cli_verdict_parity' );

	/** @var array<int,string> Stable batch names used by CLI and nightly orchestration. */
	private const BATCH_NAMES = array( 'health', 'schema', 'core', 'legacy', 'channel', 'knowledge', 'twinweb', 'external', 'direct' );

	/** Focused storage-migration probes used to refresh the deprecated-table catalog. */
	private const LEGACY_BATCH_IDS = array(
		'core.legacy_table.install_prevention',
		'core.legacy_table.lifecycle',
		'core.legacy_table.callers',
		'core.legacy_table.state_machine',
		'core.legacy_table.uninstall_matrix',
		'core.legacy_table.drop_zero_row_guard',
		'core.legacy_table.multisite_scope',
		'core.legacy_table.approved_drop',
		'core.legacy_table.jsonl_source_parity',
		'core.legacy_table.owner_parity',
		// [2026-09-02 09:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — persist WebChat owner results before CRUD-stop evaluates the retired projection rows.
		'core.webchat.sql_lifecycle',
		'core.webchat.tool_registry_parity',
		'core.legacy_table.crud_stop',
		'core.legacy_table.contract_scoreboard',
		'core.memory.filestore_parity',
		'core.memory.intent_filestore_parity',
		'core.memory.notes_filestore_parity',
		'core.context_bank.ledger',
		'core.skills.usage_parity',
		'core.bizcity_llm.usage_ledger_parity',
		'core.knowledge.kg_usage_ledger_parity',
		'core.helper.jsonl_search_query_index_parity',
		'core.helper.log_index',
		'core.helper.table_metadata',
	);

	/** @var array<int,string> Batches required for a normal release aggregate. */
	private const REQUIRED_BATCH_NAMES = array( 'health', 'schema', 'core', 'channel', 'knowledge', 'twinweb', 'external' );

	/** @var string Per-blog option holding resumable diagnostics checkpoints. */
	private const CHECKPOINTS_OPT = 'bizcity_diag_checkpoints';

	/**
	 * Build (or return memoized) catalog of probes registered via filter.
	 *
	 * @return array<string,BizCity_Diagnostics_Probe> keyed by id().
	 */
	public static function catalog(): array {
		// [2026-08-09 Johnny Chu] R-PERF-LOADER-A - flush queued probes only
		// when the Smoke Runner is actually asked for its catalog.
		if ( function_exists( 'bizcity_diagnostics_load_probes_once' ) ) {
			bizcity_diagnostics_load_probes_once();
		}
		$current_generation = (int) ( $GLOBALS['bizcity_diagnostics_probe_generation'] ?? 0 );
		if ( self::$catalog !== null && self::$catalog_generation === $current_generation ) {
			return self::$catalog;
		}

		$raw = apply_filters( 'bizcity_diagnostics_register_probes', [] );
		$out = [];

		if ( is_array( $raw ) ) {
			foreach ( $raw as $entry ) {
				$probe = null;
				if ( is_object( $entry ) && $entry instanceof BizCity_Diagnostics_Probe ) {
					$probe = $entry;
				} elseif ( is_string( $entry ) && class_exists( $entry ) ) {
					$obj = new $entry();
					if ( $obj instanceof BizCity_Diagnostics_Probe ) {
						$probe = $obj;
					}
				}
				if ( $probe ) {
					$out[ $probe->id() ] = $probe;
				}
			}
		}

		// Sort by order() ascending, then id() for stability.
		uasort( $out, function ( $a, $b ) {
			$cmp = $a->order() <=> $b->order();
			return $cmp !== 0 ? $cmp : strcmp( $a->id(), $b->id() );
		} );

		self::$catalog_generation = $current_generation;
		return self::$catalog = $out;
	}

	/** Force re-discovery (after dynamic registration in tests). */
	public static function flush(): void {
		self::$catalog = null;
	}

	/**
	 * Phase 0.41 L9.f (2026-05-22) — Per-probe last-result history.
	 *
	 * Option key (per-blog via blog-specific option storage). Map shape:
	 *   [ '<probe_id>' => {status, summary, error, fix_hint, duration_ms, ts} ]
	 * Cap: max 64 entries (LRU by ts). Persisted by `run_probe()` after every
	 * single invocation (UI Run button, Run-all, REST, FE Wizard) so the
	 * admin page + FE modal can show "last passed 5m ago" without re-running.
	 */
	const LAST_RESULTS_OPT = 'bizcity_diag_probe_last_results';
	const LAST_RESULTS_CAP = 64;

	/**
	 * Return the persisted last-result map (keyed by probe id).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_last_results(): array {
		$raw = get_option( self::LAST_RESULTS_OPT, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Persist one probe envelope into the last-result map. Strips heavy
	 * `steps`/`artifacts` to keep the option row tiny.
	 *
	 * @param string               $id
	 * @param array<string,mixed>  $res run_probe() return
	 */
	private static function record_last_result( string $id, array $res ): void {
		$map = self::get_last_results();
		$map[ $id ] = [
			'status'      => (string) ( $res['status'] ?? 'fail' ),
			'summary'     => isset( $res['summary'] )  ? (string) $res['summary']  : '',
			'error'       => isset( $res['error'] )    ? (string) $res['error']    : '',
			'fix_hint'    => isset( $res['fix_hint'] ) ? (string) $res['fix_hint'] : '',
			'duration_ms' => (int) ( $res['duration_ms'] ?? 0 ),
			'steps_count' => isset( $res['steps'] ) && is_array( $res['steps'] ) ? count( $res['steps'] ) : 0,
			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — retain lightweight scoreboard metrics without persisting per-row diagnostic payloads.
			'score'       => isset( $res['score'] ) ? (int) $res['score'] : null,
			'max_score'   => isset( $res['max_score'] ) ? (int) $res['max_score'] : null,
			'score_percent' => isset( $res['score_percent'] ) ? (float) $res['score_percent'] : null,
			'complete_rows' => isset( $res['complete_rows'] ) ? (int) $res['complete_rows'] : null,
			'total_rows'  => isset( $res['total_rows'] ) ? (int) $res['total_rows'] : null,
			'mode_counts' => isset( $res['mode_counts'] ) && is_array( $res['mode_counts'] ) ? $res['mode_counts'] : array(),
			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — retain compact per-table CRUD-stop evidence so the catalog can render current DONE/PENDING state after the probe request ends.
			'table_checks' => self::compact_crud_stop_checks( $id, $res ),
			'ts'          => time(),
		];
		// LRU cap by ts.
		if ( count( $map ) > self::LAST_RESULTS_CAP ) {
			uasort( $map, function ( $a, $b ) { return (int) ( $b['ts'] ?? 0 ) <=> (int) ( $a['ts'] ?? 0 ); } );
			$map = array_slice( $map, 0, self::LAST_RESULTS_CAP, true );
		}
		update_option( self::LAST_RESULTS_OPT, $map, false );
	}

	/**
	 * Keep only the bounded CRUD-stop fields needed by the admin catalog.
	 *
	 * @param string              $id
	 * @param array<string,mixed> $res
	 * @return array<int,array<string,mixed>>
	 */
	private static function compact_crud_stop_checks( string $id, array $res ): array {
		if ( $id !== 'core.legacy_table.crud_stop' || ! isset( $res['table_checks'] ) || ! is_array( $res['table_checks'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $res['table_checks'] as $check ) {
			if ( ! is_array( $check ) || empty( $check['table'] ) ) {
				continue;
			}
			$out[] = array(
				'table'                      => (string) $check['table'],
				'status'                     => (string) ( $check['status'] ?? '' ),
				// [2026-09-01 Johnny Chu] PHASE-1.30-DDV — retain mode and owner-probe evidence so persisted CRUD-stop rows explain contract blockers.
				'requested_mode'             => (string) ( $check['requested_mode'] ?? '' ),
				'writer_zero'                => ! empty( $check['writer_zero'] ),
				'reader_zero'                => ! empty( $check['reader_zero'] ),
				'fallback_blocked'           => ! empty( $check['fallback_blocked'] ),
				'install_blocked'            => ! empty( $check['install_blocked'] ),
				'runtime_mutations_zero'     => ! empty( $check['runtime_mutations_zero'] ),
				'replacement_contract_ready' => ! empty( $check['replacement_contract_ready'] ),
				'replacement_contract_required' => ! empty( $check['replacement_contract_required'] ),
				'owner_probe_id'             => (string) ( $check['owner_probe_id'] ?? '' ),
				'owner_probe_ready'          => ! empty( $check['owner_probe_ready'] ),
				'blockers'                   => is_array( $check['blockers'] ?? null ) ? array_values( $check['blockers'] ) : array(),
			);
		}
		return $out;
	}

	/** Clear all persisted probe results (admin-tool / dev reset). */
	public static function clear_last_results(): void {
		delete_option( self::LAST_RESULTS_OPT );
	}

	/**
	 * Public-shape catalog for REST — no closures, no objects.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function describe_catalog(): array {
		$list = [];
		foreach ( self::catalog() as $p ) {
			$list[] = [
				'id'          => $p->id(),
				'label'       => $p->label(),
				'description' => $p->description(),
				'severity'    => $p->severity(),
				'order'       => $p->order(),
				'icon'        => $p->icon(),
				'estimate_ms' => $p->estimate_ms(),
				'batch'       => self::batch_for_probe( $p->id() ),
				'aggregate_safe' => ! in_array( $p->id(), self::RUN_ALL_EXCLUDED_IDS, true ),
				// [2026-08-29 Johnny Chu] PHASE-1.31-S2.1 — expose stable network/admin execution flags to catalog consumers.
				'network_required' => self::execution_metadata( $p->id() )['network_required'],
				'admin_required'   => self::execution_metadata( $p->id() )['admin_required'],
			];
		}
		return $list;
	}

	/**
	 * Return the stable batch catalog used by CLI/nightly orchestration.
	 *
	 * @return array<string,array{probe_ids:array<int,string>,count:int}>
	 */
	public static function batches(): array {
		$out = array();
		foreach ( self::BATCH_NAMES as $batch ) {
			$ids = self::batch_ids( $batch );
			$out[ $batch ] = array(
				'probe_ids'  => $ids,
				'count'      => count( $ids ),
				'batch_hash' => self::batch_hash( $batch ),
			);
		}
		return $out;
	}

	/**
	 * Return a stable hash for the current catalog and execution metadata.
	 *
	 * @return string
	 */
	public static function catalog_hash(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.31-S2.2 — hash catalog identity and execution metadata for batch consistency checks.
		$rows = array();
		foreach ( self::catalog() as $id => $probe ) {
			$rows[] = array(
				'id'             => (string) $id,
				'batch'          => self::batch_for_probe( (string) $id ),
				'aggregate_safe' => ! in_array( $id, self::RUN_ALL_EXCLUDED_IDS, true ),
				'estimate_ms'    => (int) $probe->estimate_ms(),
				'severity'       => (string) $probe->severity(),
				'order'          => (int) $probe->order(),
			);
		}
		return hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Return a stable hash for one named batch.
	 *
	 * @param string $batch
	 * @return string
	 */
	public static function batch_hash( string $batch ): string {
		// [2026-08-29 Johnny Chu] PHASE-1.31-S2.2 — hash the deterministic probe order for one batch.
		$batch = sanitize_key( $batch );
		return hash( 'sha256', $batch . "\n" . implode( "\n", self::batch_ids( $batch ) ) );
	}

	/**
	 * Return one persisted checkpoint for the current blog.
	 *
	 * @param string $run_id
	 * @return array<string,mixed>
	 */
	public static function get_checkpoint( string $run_id, string $batch = '' ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.31-S2.3 — read the current blog's resumable diagnostics state.
		$all = get_option( self::CHECKPOINTS_OPT, array() );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$run_id = sanitize_key( $run_id );
		$batch  = sanitize_key( $batch );
		$key    = $batch !== '' ? self::checkpoint_key( $run_id, $batch ) : $run_id;
		if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) {
			return $all[ $key ];
		}
		// Backward compatibility for checkpoints written before batch-aware keys.
		if ( $batch !== '' && isset( $all[ $run_id ] ) && is_array( $all[ $run_id ] ) && (string) ( $all[ $run_id ]['batch'] ?? '' ) === $batch ) {
			return $all[ $run_id ];
		}
		return array();
	}

	/**
	 * Remove one persisted checkpoint for the current blog.
	 *
	 * @param string $run_id
	 * @return bool
	 */
	public static function clear_checkpoint( string $run_id, string $batch = '' ): bool {
		// [2026-08-29 Johnny Chu] PHASE-1.31-S2.3 — remove one completed or abandoned checkpoint.
		$all = get_option( self::CHECKPOINTS_OPT, array() );
		$key = self::checkpoint_key( $run_id, $batch );
		if ( ! is_array( $all ) || ! isset( $all[ $key ] ) ) {
			return false;
		}
		unset( $all[ $key ] );
		return update_option( self::CHECKPOINTS_OPT, $all, false );
	}

	/**
	 * Return all batch checkpoints belonging to one run.
	 *
	 * @param string $run_id
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_checkpoints( string $run_id ): array {
		$run_id = sanitize_key( $run_id );
		$all    = get_option( self::CHECKPOINTS_OPT, array() );
		$out    = array();
		if ( ! is_array( $all ) ) {
			return $out;
		}
		foreach ( $all as $key => $checkpoint ) {
			if ( ! is_array( $checkpoint ) || (string) ( $checkpoint['run_id'] ?? '' ) !== $run_id ) {
				continue;
			}
			$batch = sanitize_key( (string) ( $checkpoint['batch'] ?? '' ) );
			if ( $batch !== '' ) {
				$out[ $batch ] = $checkpoint;
			}
		}
		return $out;
	}

	/**
	 * Persist a checkpoint atomically at the WordPress option boundary.
	 *
	 * @param array<string,mixed> $checkpoint
	 * @return void
	 */
	private static function save_checkpoint( array $checkpoint ): void {
		// [2026-08-29 Johnny Chu] PHASE-1.31-S2.3 — replace one per-blog checkpoint at the option persistence boundary.
		$run_id = sanitize_key( (string) ( $checkpoint['run_id'] ?? '' ) );
		if ( $run_id === '' ) {
			return;
		}
		$all = get_option( self::CHECKPOINTS_OPT, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ self::checkpoint_key( $run_id, (string) ( $checkpoint['batch'] ?? '' ) ) ] = $checkpoint;
		update_option( self::CHECKPOINTS_OPT, $all, false );
	}

	/**
	 * Build the per-run/per-batch option key.
	 *
	 * @param string $run_id
	 * @param string $batch
	 * @return string
	 */
	private static function checkpoint_key( string $run_id, string $batch = '' ): string {
		$run_id = sanitize_key( $run_id );
		$batch  = sanitize_key( $batch );
		return $batch !== '' ? $run_id . '::' . $batch : $run_id;
	}

	/**
	 * Return deterministic probe IDs for one batch.
	 *
	 * @param string $batch
	 * @return array<int,string>
	 */
	public static function batch_ids( string $batch ): array {
		$batch = sanitize_key( $batch );
		if ( $batch === 'all' || $batch === '' ) {
			return array_keys( self::catalog() );
		}
		if ( ! in_array( $batch, self::BATCH_NAMES, true ) ) {
			return array();
		}
		if ( $batch === 'legacy' ) {
			$catalog = self::catalog();
			return array_values( array_filter( self::LEGACY_BATCH_IDS, function ( $id ) use ( $catalog ) {
				return isset( $catalog[ $id ] );
			} ) );
		}
		$ids = array();
		foreach ( self::catalog() as $id => $_probe ) {
			if ( self::batch_for_probe( $id ) === $batch ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Classify a probe using stable ID ownership conventions. Existing probes
	 * remain compatible because this is metadata, not a required interface method.
	 */
	public static function batch_for_probe( string $id ): string {
		if ( in_array( $id, self::RUN_ALL_EXCLUDED_IDS, true ) ) {
			return 'direct';
		}
		if ( in_array( $id, self::LEGACY_BATCH_IDS, true ) ) {
			return 'legacy';
		}
		$health_ids = array(
			'core.module-registry',
			'core.loader.registration_integrity',
			'core.loader.ownership',
			'core.helper.error_ux',
			'core.wp_hook.callback_integrity',
		);
		if ( in_array( $id, $health_ids, true ) ) {
			return 'health';
		}
		if ( strpos( $id, 'schema' ) !== false || $id === 'schema.inventory' ) {
			return 'schema';
		}
		if ( strpos( $id, 'twinweb' ) !== false || strpos( $id, 'twin_gpt' ) !== false ) {
			return 'twinweb';
		}
		if ( strpos( $id, 'channel' ) !== false || strpos( $id, 'crm.' ) === 0 || strpos( $id, 'cg.' ) === 0 || strpos( $id, 'zalo' ) !== false ) {
			return 'channel';
		}
		if ( strpos( $id, 'kg.' ) === 0 || strpos( $id, 'knowledge' ) !== false || strpos( $id, 'vector' ) !== false || strpos( $id, 'upload.' ) === 0 ) {
			return 'knowledge';
		}
		if ( strpos( $id, 'web.' ) === 0 || strpos( $id, 'external' ) !== false || strpos( $id, 'google' ) !== false || strpos( $id, 'piapi' ) !== false || strpos( $id, 'account.' ) === 0 ) {
			return 'external';
		}
		return 'core';
	}

	/**
	 * Return stable execution flags for catalog consumers.
	 *
	 * @param string $id
	 * @return array{network_required:bool,admin_required:bool}
	 */
	public static function execution_metadata( string $id ): array {
		$network_required = self::batch_for_probe( $id ) === 'external'
			|| strpos( $id, 'web.' ) === 0
			|| strpos( $id, 'search' ) !== false
			|| strpos( $id, 'google' ) !== false;
		return array(
			'network_required' => $network_required,
			'admin_required'   => true,
		);
	}

	/**
	 * Audit actionable evidence in a result set. PASS and intentional SKIP
	 * results do not require a remediation hint; FAIL/WARN results do.
	 *
	 * @param array<int|string,array<string,mixed>> $results
	 * @return array{total:int,actionable_total:int,with_fix_hint:int,missing_fix_hint:int,coverage_percent:float,missing_probe_ids:array<int,string>}
	 */
	public static function audit_actionable_evidence( array $results ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.32-S1 — measure remediation guidance coverage instead of assuming every probe has actionable evidence.
		$actionable_total = 0;
		$with_fix_hint    = 0;
		$missing_ids      = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$status = strtolower( (string) ( $result['status'] ?? '' ) );
			if ( ! in_array( $status, array( 'fail', 'warn' ), true ) ) {
				continue;
			}
			$actionable_total++;
			if ( trim( (string) ( $result['fix_hint'] ?? '' ) ) !== '' ) {
				$with_fix_hint++;
			} else {
				$missing_ids[] = (string) ( $result['id'] ?? 'unknown' );
			}
		}
		return array(
			'total'            => count( $results ),
			'actionable_total' => $actionable_total,
			'with_fix_hint'    => $with_fix_hint,
			'missing_fix_hint' => $actionable_total - $with_fix_hint,
			'coverage_percent' => $actionable_total > 0 ? round( ( $with_fix_hint / $actionable_total ) * 100, 2 ) : 100.0,
			'missing_probe_ids' => $missing_ids,
		);
	}

	/**
	 * Return bounded rerun commands for a registered probe.
	 *
	 * @param string $id
	 * @return array{probe:string,batch:string,wp_cli:string}
	 */
	public static function rerun_metadata( string $id ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.32-S3.4 — make failure triage directly rerunnable without workspace-wide rediscovery.
		if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $id ) ) {
			return array( 'probe' => '', 'batch' => '', 'wp_cli' => '' );
		}
		$batch = self::batch_for_probe( $id );
		return array(
			'probe'  => 'php bin/diagnostics-run.php --filter=' . $id . ' --format=json',
			'batch'  => 'php bin/diagnostics-run.php --batch=' . $batch . ' --format=json',
			'wp_cli' => 'wp bizcity probe --id=' . $id . ' --format=json',
		);
	}

	/**
	 * Execute one probe by id and return its result envelope.
	 *
	 * @param string $id
	 * @return array{status:string,id:string,duration_ms:int,summary?:string,error?:string,fix_hint?:string,steps?:array,artifacts?:array}
	 */
	public static function run_probe( string $id, array $options = array() ): array {
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $id ] ) ) {
			return [
				'id'          => $id,
				'status'      => 'fail',
				'error'       => 'Unknown probe id.',
				'duration_ms' => 0,
			];
		}
		$probe = $catalog[ $id ];
		if ( self::execution_metadata( $id )['admin_required'] && function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			// [2026-08-29 Johnny Chu] PHASE-1.31-S2.6 — classify a missing diagnostics admin context without executing probe code.
			$result = array(
				'id'          => $id,
				'status'      => 'precheck-fail',
				'skip_reason' => 'admin_required_skip',
				'error'       => 'admin_required_skip',
				'duration_ms' => 0,
			);
			$result['rerun'] = self::rerun_metadata( $id );
			self::record_last_result( $id, $result );
			return $result;
		}
		if ( ! empty( $options['skip_network'] ) && self::execution_metadata( $id )['network_required'] ) {
			// [2026-08-29 Johnny Chu] PHASE-1.31-S2.6 — classify intentional network suppression without executing the probe.
			$result = array(
				'id'          => $id,
				'status'      => 'precheck-fail',
				'skip_reason' => 'network_skip',
				'error'       => 'network_skip',
				'duration_ms' => 0,
			);
			$result['rerun'] = self::rerun_metadata( $id );
			self::record_last_result( $id, $result );
			return $result;
		}
		global $wpdb;
		$probe_query_start = ( isset( $wpdb ) && isset( $wpdb->queries ) && is_array( $wpdb->queries ) )
			? count( $wpdb->queries )
			: null;

		// Precondition gate.
		$pc = $probe->precondition();
		// [2026-08-21 Johnny Chu] R-DDV-PRECONDITION-CONTRACT — legacy probes
		// return a string for an intentional skip; do not execute run() unless
		// precondition() explicitly returns true.
		if ( true !== $pc ) {
			$error = is_wp_error( $pc )
				? $pc->get_error_message()
				: ( is_scalar( $pc ) && (string) $pc !== '' ? (string) $pc : 'Precondition did not pass.' );
			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — overwrite stale PASS evidence when the current probe precondition is unavailable or fails.
			$result = [
				'id'          => $id,
				'status'      => 'precheck-fail',
				'skip_reason' => 'precondition_skip',
				'error'       => $error,
				'duration_ms' => 0,
			];
			$result['rerun'] = self::rerun_metadata( $id );
			self::record_last_result( $id, $result );
			return $result;
		}

		// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — give runtime caller probes a request-local query baseline so Diagnostics introspection executed before the probe is not misclassified as active business SQL.
		if ( ! array_key_exists( 'query_start', $options ) ) {
			$options['query_start'] = $probe_query_start;
		}
		$ctx   = new BizCity_Diagnostics_Probe_Context( $options );
		$start = microtime( true );
		$res   = [];
		try {
			$res = $probe->run( $ctx );
			if ( ! is_array( $res ) ) {
				$res = [ 'status' => 'fail', 'error' => 'Probe returned non-array.' ];
			} elseif ( ! isset( $res['status'] ) && ! empty( $res ) && isset( $res[0] ) && is_array( $res[0] ) && array_key_exists( 'status', $res[0] ) ) {
				// Bare-steps return shape: probe returned a numeric-indexed list of step entries
				// (each having a 'status' key). Auto-wrap to proper envelope: overall 'pass' iff
				// no step status is FAIL (case-insensitive). Preserves legacy probes that never
				// learned the envelope contract.
				$failed_steps = [];
				foreach ( $res as $step ) {
					if ( ! is_array( $step ) ) { continue; }
					if ( strtolower( (string) ( $step['status'] ?? '' ) ) === 'fail' ) {
						$failed_steps[] = (string) ( $step['label'] ?? '(unlabeled step)' )
							. ( isset( $step['detail'] ) ? ' — ' . (string) $step['detail'] : '' );
					}
				}
				$res = [
					'status'  => empty( $failed_steps ) ? 'pass' : 'fail',
					'steps'   => $res,
					'summary' => empty( $failed_steps )
						? 'All ' . count( $res ) . ' steps passed.'
						: count( $failed_steps ) . ' step(s) failed: ' . implode( ' | ', $failed_steps ),
				];
			}
		} catch ( \Throwable $e ) {
			$res = [
				'status' => 'fail',
				'error'  => $e->getMessage(),
			];
			if ( class_exists( 'BizCity_Error_Reporter' ) ) {
				BizCity_Error_Reporter::record( [
					'code'    => 'probe_exception',
					'module'  => 'diagnostics/smoke',
					'title'   => sprintf( 'Probe %s threw exception', $id ),
					'detail'  => $e->getMessage(),
					'context' => [ 'probe_id' => $id, 'file' => $e->getFile(), 'line' => $e->getLine() ],
					'source'  => 'be',
				] );
			}
		}

		// Always try to cleanup, even on fail.
		try {
			$probe->cleanup();
		} catch ( \Throwable $e ) {
			// Cleanup failure is logged but does not flip status.
			if ( class_exists( 'BizCity_Error_Reporter' ) ) {
				BizCity_Error_Reporter::record( [
					'code'    => 'probe_cleanup_failed',
					'module'  => 'diagnostics/smoke',
					'title'   => sprintf( 'Probe %s cleanup failed', $id ),
					'detail'  => $e->getMessage(),
					'context' => [ 'probe_id' => $id ],
					'source'  => 'be',
				] );
			}
		}

		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		// Merge runner-emitted steps if probe didn't provide its own.
		if ( empty( $res['steps'] ) && $ctx->steps ) {
			$res['steps'] = $ctx->steps;
		}

		$res['id']          = $id;
		$res['duration_ms'] = $duration;
		if ( ! isset( $res['status'] ) ) {
			$res['status'] = 'fail';
		}
		// [2026-08-02 Johnny Chu] HOTFIX — canonicalize probe status at the runner boundary so admin, REST, and persisted results count PASS consistently.
		$res['status'] = strtolower( trim( (string) $res['status'] ) );
		$res['rerun']   = self::rerun_metadata( $id );

		// Phase 0.41 L9.f — persist per-probe last result (lightweight).
		self::record_last_result( $id, $res );

		return $res;
	}

	/**
	 * Run every probe sequentially. Stops if cumulative time would exceed
	 * RUN_BUDGET_SECONDS. Returns an aggregate envelope used by the
	 * "Run all" button in the wizard.
	 *
	 * @return array{started_at:string,duration_ms:int,results:array}
	 */
	public static function run_all( array $options = array() ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.31-S2.3 — persist each probe outcome so a bounded process can resume without rerunning completed probes.
		$started          = microtime( true );
		$catalog          = self::catalog();
		$requested_batch  = isset( $options['batch'] ) ? sanitize_key( (string) $options['batch'] ) : '';
		$catalog_ids      = $requested_batch !== '' ? self::batch_ids( $requested_batch ) : array_keys( $catalog );
		$catalog_hash     = self::catalog_hash();
		$batch_name       = $requested_batch !== '' ? $requested_batch : 'all';
		$batch_hash       = self::batch_hash( $batch_name );
		$resume_id        = isset( $options['resume'] ) ? sanitize_key( (string) $options['resume'] ) : '';
		$run_id           = isset( $options['run_id'] ) ? sanitize_key( (string) $options['run_id'] ) : '';
		$checkpoint       = array();
		$results_by_id    = array();
		$cursor           = 0;

		if ( $requested_batch !== '' && empty( $catalog_ids ) ) {
			return array(
				'run_id'       => $run_id,
				'batch'        => $batch_name,
				'catalog_hash' => $catalog_hash,
				'batch_hash'   => $batch_hash,
				'error'        => 'Unknown or empty diagnostics batch.',
				'coverage'     => array( 'catalog_total' => count( $catalog ), 'selected_total' => 0, 'executed' => 0, 'allowed_skipped' => 0, 'deferred' => 0, 'complete' => false ),
				'results'      => array(),
			);
		}

		if ( $resume_id !== '' ) {
			$checkpoint = self::get_checkpoint( $resume_id, $batch_name );
			if ( empty( $checkpoint ) || (string) ( $checkpoint['catalog_hash'] ?? '' ) !== $catalog_hash || (string) ( $checkpoint['batch_hash'] ?? '' ) !== $batch_hash ) {
				return array(
					'run_id'       => $resume_id,
					'batch'        => $batch_name,
					'catalog_hash' => $catalog_hash,
					'batch_hash'   => $batch_hash,
					'error'        => 'Diagnostics checkpoint is missing or catalog hash changed.',
					'coverage'     => array( 'catalog_total' => count( $catalog ), 'selected_total' => count( $catalog_ids ), 'executed' => 0, 'allowed_skipped' => 0, 'deferred' => count( $catalog_ids ), 'complete' => false ),
					'results'      => array(),
				);
			}
			$run_id        = $resume_id;
			$cursor        = max( 0, min( count( $catalog_ids ), (int) ( $checkpoint['cursor'] ?? 0 ) ) );
			$results_by_id = isset( $checkpoint['results'] ) && is_array( $checkpoint['results'] ) ? $checkpoint['results'] : array();
			for ( $index = $cursor; $index < count( $catalog_ids ); $index++ ) {
				unset( $results_by_id[ $catalog_ids[ $index ] ] );
			}
		} else {
			if ( $run_id === '' ) {
				try {
					$run_id = sanitize_key( 'diag_' . gmdate( 'YmdHis' ) . '_' . bin2hex( random_bytes( 4 ) ) );
				} catch ( \Throwable $e ) {
					$run_id = sanitize_key( 'diag_' . gmdate( 'YmdHis' ) );
				}
			}
			$checkpoint = array(
				'run_id'       => $run_id,
				'catalog_hash' => $catalog_hash,
				'batch'        => $batch_name,
				'batch_hash'   => $batch_hash,
				'cursor'       => 0,
				'results'      => array(),
				'status'       => 'running',
				'updated_at'   => gmdate( 'c' ),
			);
			self::save_checkpoint( $checkpoint );
		}
		// [2026-08-29 Johnny Chu] PHASE-1.32-DIAGNOSTICS-STREAM — announce the resumable batch before the first probe starts.
		if ( isset( $options['progress_callback'] ) && is_callable( $options['progress_callback'] ) ) {
			call_user_func( $options['progress_callback'], array(
				'event'  => 'started',
				'run_id' => $run_id,
				'batch'  => $batch_name,
				'cursor' => $cursor,
				'total'  => count( $catalog_ids ),
			) );
		}

		$executed = 0;
		for ( $index = $cursor; $index < count( $catalog_ids ); $index++ ) {
			$id = $catalog_ids[ $index ];
			if ( ! isset( $catalog[ $id ] ) ) {
				$cursor = $index + 1;
				continue;
			}
			if ( ( microtime( true ) - $started ) > self::RUN_BUDGET_SECONDS ) {
				for ( $deferred_index = $index; $deferred_index < count( $catalog_ids ); $deferred_index++ ) {
					$deferred_id = $catalog_ids[ $deferred_index ];
					$results_by_id[ $deferred_id ] = array( 'id' => $deferred_id, 'status' => 'skipped', 'skip_reason' => 'budget_deferred', 'error' => 'budget_deferred', 'duration_ms' => 0, 'rerun' => self::rerun_metadata( $deferred_id ) );
					if ( isset( $options['progress_callback'] ) && is_callable( $options['progress_callback'] ) ) {
						call_user_func( $options['progress_callback'], array(
							'event'    => 'deferred',
							'run_id'   => $run_id,
							'batch'    => $batch_name,
							'index'    => $deferred_index + 1,
							'total'    => count( $catalog_ids ),
							'probe_id' => $deferred_id,
						) );
					}
				}
				$cursor = $index;
				break;
			}
			if ( in_array( $id, self::RUN_ALL_EXCLUDED_IDS, true ) ) {
				$results_by_id[ $id ] = array( 'id' => $id, 'status' => 'skipped', 'skip_reason' => 'direct_only_skip', 'error' => 'direct_only_skip', 'duration_ms' => 0, 'rerun' => self::rerun_metadata( $id ) );
			} else {
				$results_by_id[ $id ] = self::run_probe( $id, $options );
				$executed++;
			}
			$cursor = $index + 1;
			$checkpoint['cursor']     = $cursor;
			$checkpoint['results']    = $results_by_id;
			$checkpoint['updated_at'] = gmdate( 'c' );
			self::save_checkpoint( $checkpoint );
			// [2026-08-29 Johnny Chu] PHASE-1.32-DIAGNOSTICS-STREAM — expose each completed probe and its bounded per-row evidence before continuing.
			if ( isset( $options['progress_callback'] ) && is_callable( $options['progress_callback'] ) ) {
				call_user_func( $options['progress_callback'], array(
					'event'       => 'probe',
					'run_id'      => $run_id,
					'batch'       => $batch_name,
					'index'       => $cursor,
					'total'       => count( $catalog_ids ),
					'probe_id'    => $id,
					'result'      => $results_by_id[ $id ],
				) );
			}
		}

		$results = array();
		foreach ( $catalog_ids as $id ) {
			if ( isset( $results_by_id[ $id ] ) ) {
				$results[] = $results_by_id[ $id ];
			}
		}
		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		$pass = 0; $warn = 0; $fail = 0; $skip = 0; $deferred = 0; $allowed_skipped = 0; $skip_reasons = array();
		foreach ( $results as $result ) {
			$status = (string) ( $result['status'] ?? '' );
			if ( $status === 'pass' ) { $pass++; }
			elseif ( $status === 'warn' ) { $warn++; }
			elseif ( $status === 'fail' ) { $fail++; }
			else {
				$skip++;
				$reason = (string) ( $result['skip_reason'] ?? $result['error'] ?? 'precondition_skip' );
				$skip_reasons[ $reason ] = isset( $skip_reasons[ $reason ] ) ? $skip_reasons[ $reason ] + 1 : 1;
				if ( $reason === 'budget_deferred' ) { $deferred++; }
				else { $allowed_skipped++; }
			}
		}
		$complete = $cursor >= count( $catalog_ids ) && $deferred === 0;
		$checkpoint['cursor']     = $cursor;
		$checkpoint['results']    = $results_by_id;
		$checkpoint['status']     = $complete ? 'complete' : 'deferred';
		$checkpoint['updated_at'] = gmdate( 'c' );
		self::save_checkpoint( $checkpoint );
		if ( isset( $options['progress_callback'] ) && is_callable( $options['progress_callback'] ) ) {
			call_user_func( $options['progress_callback'], array(
				'event'  => 'finished',
				'run_id' => $run_id,
				'batch'  => $batch_name,
				'cursor' => $cursor,
				'total'  => count( $catalog_ids ),
				'status' => $complete ? 'complete' : 'deferred',
			) );
		}
		update_option( 'bizcity_diag_last_smoke', array( 'ts' => time(), 'pass' => $pass, 'warn' => $warn, 'fail' => $fail, 'skipped' => $skip, 'duration_ms' => $duration_ms ), false );

		return array(
			'run_id'       => $run_id,
			'started_at'   => gmdate( 'c', (int) $started ),
			// [2026-08-29 Johnny Chu] PHASE-1.32-S3.3 — retain bounded runtime identity without credentials, SQL, or private payloads.
			'environment'  => array( 'php' => PHP_VERSION, 'wordpress' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '', 'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ),
			'duration_ms'  => $duration_ms,
			'batch'        => $batch_name,
			'catalog_hash' => $catalog_hash,
			'batch_hash'   => $batch_hash,
			'coverage'     => array( 'catalog_total' => count( $catalog ), 'selected_total' => count( $catalog_ids ), 'executed' => $executed, 'allowed_skipped' => $allowed_skipped, 'deferred' => $deferred, 'skip_reasons' => $skip_reasons, 'complete' => $complete ),
			'counts'       => array( 'pass' => $pass, 'warn' => $warn, 'fail' => $fail, 'skip' => $skip ),
			'evidence_audit' => self::audit_actionable_evidence( $results ),
			'results'      => $results,
		);
	}

	/**
	 * Aggregate required batch checkpoints without executing any probe.
	 *
	 * @param string             $run_id
	 * @param array<int,string>  $required_batches
	 * @return array<string,mixed>
	 */
	public static function aggregate_checkpoints( string $run_id, array $required_batches = array() ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.31-S2.5 — aggregate only persisted batch evidence; never rerun a probe during release evaluation.
		$run_id = sanitize_key( $run_id );
		if ( empty( $required_batches ) ) {
			$required_batches = self::REQUIRED_BATCH_NAMES;
		}
		$required_batches = array_values( array_filter( array_map( 'sanitize_key', $required_batches ), function ( $batch ) {
			return in_array( $batch, self::REQUIRED_BATCH_NAMES, true );
		} ) );
		$catalog_hash = self::catalog_hash();
		$checkpoints  = self::get_checkpoints( $run_id );
		$results      = array();
		$batch_report = array();
		$complete     = true;
		$deferred     = 0;
		$allowed_skip = 0;
		$skip_reasons = array();

		foreach ( $required_batches as $batch ) {
			$expected_ids = self::batch_ids( $batch );
			$checkpoint   = isset( $checkpoints[ $batch ] ) ? $checkpoints[ $batch ] : array();
			$hash_ok      = ! empty( $checkpoint )
				&& (string) ( $checkpoint['catalog_hash'] ?? '' ) === $catalog_hash
				&& (string) ( $checkpoint['batch_hash'] ?? '' ) === self::batch_hash( $batch );
			$cursor       = (int) ( $checkpoint['cursor'] ?? 0 );
			$batch_done   = $hash_ok && (string) ( $checkpoint['status'] ?? '' ) === 'complete'
				&& $cursor >= count( $expected_ids );
			if ( ! $batch_done ) {
				$complete = false;
			}
			$batch_deferred = 0;
			$batch_results  = isset( $checkpoint['results'] ) && is_array( $checkpoint['results'] ) ? $checkpoint['results'] : array();
			foreach ( $batch_results as $probe_id => $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}
				$result['id'] = (string) ( $result['id'] ?? $probe_id );
				if ( empty( $result['rerun'] ) ) {
					$result['rerun'] = self::rerun_metadata( $result['id'] );
				}
				$results[ $result['id'] ] = $result;
				$reason = (string) ( $result['skip_reason'] ?? $result['error'] ?? 'precondition_skip' );
				if ( $reason !== '' ) {
					$skip_reasons[ $reason ] = isset( $skip_reasons[ $reason ] ) ? $skip_reasons[ $reason ] + 1 : 1;
				}
				if ( $reason === 'budget_deferred' ) {
					$deferred++;
					$batch_deferred++;
				} elseif ( in_array( (string) ( $result['status'] ?? '' ), array( 'precheck-fail', 'skipped' ), true ) ) {
					$allowed_skip++;
				}
			}
			if ( ! $batch_done && $batch_deferred === 0 ) {
				$missing = max( 0, count( $expected_ids ) - count( $batch_results ) );
				$deferred += $missing;
			}
			$batch_report[ $batch ] = array(
				'expected'  => count( $expected_ids ),
				'executed'  => count( $batch_results ),
				'cursor'    => $cursor,
				'complete'  => $batch_done,
				'hash_match' => $hash_ok,
				'deferred'  => $batch_deferred,
			);
		}

		$counts = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0 );
		foreach ( $results as $result ) {
			$status = strtolower( (string) ( $result['status'] ?? 'fail' ) );
			if ( $status === 'precheck-fail' || $status === 'skipped' ) {
				$status = 'skip';
			}
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			} else {
				$counts['fail']++;
			}
		}
		$payload = array(
			'contract'       => 'diagnostics-verdict',
			'version'        => '1',
			'command'        => 'diagnostics aggregate',
			'run_id'         => $run_id,
			// [2026-08-29 Johnny Chu] PHASE-1.32-S3.3 — carry safe environment identity into the read-only aggregate artifact.
			'environment'    => array( 'php' => PHP_VERSION, 'wordpress' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '', 'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ),
			'catalog_hash'   => $catalog_hash,
			'batches'        => $batch_report,
			'counts'         => $counts,
			'coverage'       => array(
				'catalog_total'   => count( self::catalog() ),
				'required_batches' => count( $required_batches ),
				'completed_batches' => count( array_filter( $batch_report, function ( $row ) { return ! empty( $row['complete'] ); } ) ),
				'executed'        => $counts['pass'] + $counts['warn'] + $counts['fail'],
				'allowed_skipped' => $allowed_skip,
				'deferred'        => $deferred,
				'skip_reasons'    => $skip_reasons,
				'complete'        => $complete && $deferred === 0,
			),
			'evidence_audit' => self::audit_actionable_evidence( $results ),
			'results'        => array_values( $results ),
		);
		$payload['verdict'] = ! empty( $counts['fail'] ) ? 'fail' : ( $payload['coverage']['complete'] ? ( ! empty( $counts['warn'] ) ? 'warn' : 'pass' ) : 'skip' );
		return $payload;
	}

	/**
	 * Phase 0.41 L9.b+ — Auto-Fix-All orchestrator.
	 *
	 * Idempotent + additive-only remediation sweep:
	 *   1. Run every registered installer (Site_Provisioner::run_all(force=true)).
	 *   2. Flush caches.
	 *   3. JSON-declared Auto-Create cho mọi row missing/drift.
	 *   4. **Per-row class fallback** — với mỗi row còn missing mà registry có
	 *      `class` field, thử gọi installer method chuẩn (install / maybe_install /
	 *      maybe_create_tables / ensure_table / ensure_tables_exist / install_tables /
	 *      create_tables / maybe_install_inbox). Hữu ích khi class tồn tại lúc
	 *      inspection nhưng chưa đăng ký qua `bizcity_register_installers` filter
	 *      (timing / module load order).
	 *   5. Phân loại "unfixable" cho rows còn lại (orphan: không JSON, không class).
	 *
	 * @return array{
	 *   installer_results:array, auto_create_results:array, class_fallback_results:array,
	 *   unfixable:array, before:int, after:int, took_ms:int
	 * }
	 */
	public static function auto_fix_all(): array {
		$started = microtime( true );

		// Count missing before.
		$before_missing = 0;
		if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			foreach ( BizCity_Diagnostics_Table_Inspector::inspect_all() as $r ) {
				if ( empty( $r['exists'] ) ) { $before_missing++; }
			}
		}

		// 1. Installers.
		$installer_results = class_exists( 'BizCity_Site_Provisioner' )
			? BizCity_Site_Provisioner::run_all( true )
			: [];

		// 2. Flush caches.
		if ( class_exists( 'BizCity_Diagnostics_Installer_Resolver' ) ) {
			BizCity_Diagnostics_Installer_Resolver::flush();
		}

		// 3. JSON-declared auto-create for still-missing / drift rows.
		$auto_create_results = [];
		$json_tables = class_exists( 'BizCity_Diagnostics_Changelog_Loader' )
			? BizCity_Diagnostics_Changelog_Loader::tables()
			: [];
		if ( $json_tables && class_exists( 'BizCity_Diagnostics_Auto_Create' ) && class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			foreach ( BizCity_Diagnostics_Table_Inspector::inspect_all() as $r ) {
				$suffix = $r['name'] ?? '';
				if ( ! isset( $json_tables[ $suffix ] ) ) { continue; }

				$needs = empty( $r['exists'] );
				if ( ! $needs && class_exists( 'BizCity_Diagnostics_Column_Inspector' ) ) {
					$diff = BizCity_Diagnostics_Column_Inspector::diff( $r );
					$needs = ( $diff['status'] ?? '' ) === 'drift';
				}
				if ( ! $needs ) { continue; }

				$auto_create_results[ $suffix ] = BizCity_Diagnostics_Auto_Create::run( $suffix );
			}
		}

		// 4. Per-row class fallback for rows still missing.
		$class_fallback_results = [];
		$unfixable              = [];
		if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			$candidate_methods = [
				'install', 'maybe_install', 'maybe_create_tables', 'create_tables',
				'ensure_table', 'ensure_tables_exist', 'install_tables', 'maybe_install_inbox',
			];
			foreach ( BizCity_Diagnostics_Table_Inspector::inspect_all() as $r ) {
				if ( ! empty( $r['exists'] ) ) { continue; }

				$suffix    = (string) ( $r['name'] ?? '' );
				$class     = (string) ( $r['class'] ?? '' );
				$has_json  = $suffix !== '' && isset( $json_tables[ $suffix ] );
				$ran       = false;

				if ( $class !== '' && class_exists( $class ) ) {
					// Clear the version option so `maybe_install()` doesn't bail early
					// when the option value already matches SCHEMA_VERSION but the table
					// was dropped / never actually created (option set, table missing).
					if ( defined( "{$class}::OPTION_VERSION" ) ) {
						delete_option( constant( "{$class}::OPTION_VERSION" ) );
					}

					foreach ( $candidate_methods as $m ) {
						if ( ! method_exists( $class, $m ) ) { continue; }
						try {
							call_user_func( [ $class, $m ] );
							$class_fallback_results[ $suffix ] = [
								'class'  => $class,
								'method' => $m,
								'status' => 'invoked',
							];
							$ran = true;
							break; // first matching method wins; idempotent
						} catch ( \Throwable $e ) {
							$class_fallback_results[ $suffix ] = [
								'class'  => $class,
								'method' => $m,
								'status' => 'error',
								'error'  => $e->getMessage(),
							];
							$ran = true;
							break;
						}
					}
				}

				if ( ! $ran && ! $has_json ) {
					$unfixable[] = [
						'physical' => (string) ( $r['physical'] ?? $suffix ),
						'owner'    => (string) ( $r['owner'] ?? '' ),
						'class'    => $class,
						'hint'     => $class !== ''
							? 'class exists but no recognised installer method'
							: 'orphan registry row: add CREATE TABLE / JSON changelog OR remove from registry',
					];
				}
			}
		}

		// Count after (re-flush schema cache by re-inspect).
		$after_missing = 0;
		if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			// force fresh schema snapshot
			if ( property_exists( 'BizCity_Diagnostics_Table_Inspector', 'schema_cache' ) ) {
				$ref = new \ReflectionClass( 'BizCity_Diagnostics_Table_Inspector' );
				if ( $ref->hasProperty( 'schema_cache' ) ) {
					$p = $ref->getProperty( 'schema_cache' );
					$p->setAccessible( true );
					$p->setValue( null, null );
				}
			}
			foreach ( BizCity_Diagnostics_Table_Inspector::inspect_all() as $r ) {
				if ( empty( $r['exists'] ) ) { $after_missing++; }
			}
		}

		$took_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		// Audit.
		if ( class_exists( 'BizCity_Error_Reporter' ) ) {
			BizCity_Error_Reporter::record( [
				'code'    => 'auto_fix_all_run',
				'module'  => 'core/diagnostics',
				'title'   => 'Auto-Fix-All sweep',
				'detail'  => sprintf( 'Missing tables: %d → %d in %dms', $before_missing, $after_missing, $took_ms ),
				'context' => [
					'before_missing'         => $before_missing,
					'after_missing'          => $after_missing,
					'auto_create_keys'       => array_keys( $auto_create_results ),
					'class_fallback_keys'    => array_keys( $class_fallback_results ),
					'unfixable_count'        => count( $unfixable ),
					'installer_count'        => is_array( $installer_results ) ? count( $installer_results ) : 0,
				],
			] );
		}

		return [
			'installer_results'      => $installer_results,
			'auto_create_results'    => $auto_create_results,
			'class_fallback_results' => $class_fallback_results,
			'unfixable'              => $unfixable,
			'before'                 => $before_missing,
			'after'                  => $after_missing,
			'took_ms'                => $took_ms,
		];
	}
}

/**
 * Lightweight context passed to every probe's run(). Probes call
 * $ctx->emit_step() to push live progress that the REST layer can later
 * stream (Phase 0.41 L9.b will upgrade to SSE; today we just return the
 * accumulated array at the end).
 */
final class BizCity_Diagnostics_Probe_Context {

	/** @var array<int,array{label:string,status:string,detail?:string}> */
	public $steps = [];

	/** @var bool */
	private $abort = false;

	/** @var array<string,mixed> */
	private $options = array();

	public function __construct( array $options = array() ) {
		$this->options = $options;
	}

	public function option( string $key, $default = null ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
	}

	public function emit_step( array $step ): void {
		$this->steps[] = [
			'label'  => isset( $step['label'] ) ? (string) $step['label'] : '',
			'status' => isset( $step['status'] ) ? (string) $step['status'] : 'pass',
			'detail' => isset( $step['detail'] ) ? (string) $step['detail'] : null,
		];
	}

	public function should_abort(): bool {
		return $this->abort;
	}

	public function abort(): void {
		$this->abort = true;
	}
}
