<?php
/**
 * Diagnostics Orphan Cleaner — quarantine review for deprecated tables.
 *
 * Safety policy (simplified per operator request 2026-05-21):
 *   1. ONLY considers tables in the curated `deprecated_tables()` list.
 *   2. Empty-table guard: a table is dropped ONLY if `COUNT(*) = 0`. Tables
 *      with ANY rows are SKIPPED (operator must export/migrate first).
 *   3. Quarantine guard: entries marked `quarantine_only` are tracked and
 *      remain blocked until the central policy records ready_to_drop plus an
 *      approval reference; the owning migration must sign off first.
 *   4. Per-shard scope: only acts on the CURRENT site's `$wpdb`. In multisite
 *      each subsite must be visited (each shard has its own DB).
 *   5. Capability gate: only triggered while rendering an admin page that
 *      requires `manage_options` (the Tools → BizCity Diagnostics page).
 *   6. Throttle: runs at most once per hour per blog (transient guard) to
 *      avoid hammering on every admin reload.
 *   7. Audit log: every run is appended to option `bizcity_diagnostics_orphan_log`
 *      (capped at 50 entries).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Orphan_Cleaner {

	const LOG_OPTION       = 'bizcity_diagnostics_orphan_log';
	const LOG_MAX          = 50;
	const THROTTLE_TRANSIENT = 'bizcity_diagnostics_orphan_last_run';
	const THROTTLE_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Inspect each deprecated table on the current shard.
	 *
	 * @return array<int,array{
	 *   name:string, physical:string, reason:string,
	 *   module:string, feature:string, purpose:string, class:string,
	 *   related_tables:array<int,string>, orphan_gate:string,
	 *   exists:bool, rows:int, size_human:string,
	 *   safe_to_drop:bool, skip_reason:string, policy_state:string,
	 *   approval_ref:string, jsonl_replacement:array,
	 *   replacement_status:string, replacement_mode:string, replacement_detail:string,
	 *   replacement_dates:array<int,string>
	 * }>
	 */
	public static function preview(): array {
		global $wpdb;
		$out      = [];
		$prefix   = $wpdb->prefix;
		$entries  = BizCity_Diagnostics_Table_Registry::deprecated_tables();
		$last_probe_results = class_exists( 'BizCity_Diagnostics_Smoke_Runner' )
			? BizCity_Diagnostics_Smoke_Runner::get_last_results()
			: array();
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - replacement DONE also requires the matching table to pass the SQL CRUD-stop observation probe.
		$crud_stop_checks = array();
		if ( isset( $last_probe_results['core.legacy_table.crud_stop']['table_checks'] )
			&& is_array( $last_probe_results['core.legacy_table.crud_stop']['table_checks'] ) ) {
			foreach ( $last_probe_results['core.legacy_table.crud_stop']['table_checks'] as $crud_check ) {
				if ( is_array( $crud_check ) && ! empty( $crud_check['table'] ) ) {
					$crud_stop_checks[ (string) $crud_check['table'] ] = $crud_check;
				}
			}
		}
		// [2026-08-27 Johnny Chu] PHASE-1.30-ABSENT-EVIDENCE — absent rows are green only after lifecycle and caller probes pass.
		$catalog_audit_pass = isset( $last_probe_results['core.legacy_table.lifecycle']['status'] )
			&& strtolower( (string) $last_probe_results['core.legacy_table.lifecycle']['status'] ) === 'pass';
		$caller_audit_pass = isset( $last_probe_results['core.legacy_table.callers']['status'] )
			&& strtolower( (string) $last_probe_results['core.legacy_table.callers']['status'] ) === 'pass';

		$prev_suppress = $wpdb->suppress_errors( true );

		foreach ( $entries as $e ) {
			$physical = ! empty( $e['raw'] )
				? $e['name']
				: ( ( ( $e['prefix_scope'] ?? 'blog' ) === 'base' ) ? $wpdb->base_prefix : $prefix ) . $e['name'];

			// [2026-07-31 Johnny Chu] R-SHOW-TABLES — quarantine preview uses the cached metadata helper and fails closed if unavailable.
			$exists = function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $physical );

			$rows = 0;
			$size_human = '—';
			$safe = false;
			$skip = '';
			$policy_state = class_exists( 'BizCity_Legacy_Table_Policy' )
				? BizCity_Legacy_Table_Policy::get_state( $e['name'] )
				: 'quarantine';
			$approval_ref = class_exists( 'BizCity_Legacy_Table_Policy' )
				? BizCity_Legacy_Table_Policy::get_record( $e['name'] )['approval_ref']
				: '';
			$quarantine_only = ! empty( $e['quarantine_only'] );
			$absent_verified = ! $exists && ! $quarantine_only && $catalog_audit_pass && $caller_audit_pass;
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — carry dependency/gate hints from deprecated registry into preview rows.
			$related_tables = array_values( array_filter( array_map( 'strval', is_array( $e['related_tables'] ?? null ) ? $e['related_tables'] : array() ) ) );
			$orphan_gate    = (string) ( $e['orphan_gate'] ?? '' );
			$jsonl_replacement = is_array( $e['jsonl_replacement'] ?? null ) ? $e['jsonl_replacement'] : array();
			$replacement_mode = (string) ( $jsonl_replacement['mode'] ?? 'retire_only' );
			$replacement_status = $replacement_mode === 'jsonl' ? 'pending' : 'not_applicable';
			$replacement_detail = (string) ( $jsonl_replacement['label'] ?? 'No replacement log; retire after zero-row audit' );
			$crud_stop_check = isset( $crud_stop_checks[ (string) $e['name'] ] ) ? $crud_stop_checks[ (string) $e['name'] ] : null;
			$crud_stop_status = is_array( $crud_stop_check ) ? strtolower( (string) ( $crud_stop_check['status'] ?? '' ) ) : '';
			$crud_stop_ready = $crud_stop_status === 'pass';
			$replacement_dates  = array();
			if ( $replacement_mode === 'jsonl' ) {
				$contract_id = (string) ( $jsonl_replacement['contract_id'] ?? '' );
				$contract = ( $contract_id !== '' && class_exists( 'BizCity_Log_Contract_Registry' ) )
					? BizCity_Log_Contract_Registry::get( $contract_id )
					: null;
				if ( ! is_array( $contract ) ) {
					$replacement_detail = 'PENDING — Log Contract Registry entry is not registered.';
				} elseif ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
					$replacement_detail = 'PENDING — canonical JSONL logger is not loaded.';
				} else {
					$replacement_dates = BizCity_JSONL_File_Logger::list_dates(
						(string) ( $contract['jsonl_folder'] ?? '' ),
						(string) ( $contract['jsonl_module'] ?? '' ),
						30
					);
					$probe_id = (string) ( $jsonl_replacement['probe_id'] ?? '' );
					$last_results = class_exists( 'BizCity_Diagnostics_Smoke_Runner' )
						? BizCity_Diagnostics_Smoke_Runner::get_last_results()
						: array();
					$probe_status = $probe_id !== '' && isset( $last_results[ $probe_id ]['status'] )
						? strtolower( (string) $last_results[ $probe_id ]['status'] )
						: '';
					if ( $probe_status === 'pass' ) {
						$replacement_status = 'pass';
						$replacement_detail = 'PASS — current-blog JSONL parity probe ' . $probe_id . ' passed; canonical evidence file is registered.';
					} elseif ( ! empty( $replacement_dates ) ) {
						$replacement_status = 'pass';
						$replacement_detail = 'PASS — registered contract and JSONL evidence found.';
					} else {
						$replacement_detail = 'PENDING — contract exists, but no JSONL evidence file was found in the active window.';
					}
				}
			} elseif ( $replacement_mode === 'filestore' ) {
				// [2026-08-29 Johnny Chu] PHASE-1.30-DIAGNOSTICS — encrypted business filestore is JSONL-backed but has a separate contract registry and evidence path.
				$contract_id = (string) ( $jsonl_replacement['contract_id'] ?? '' );
				$contract = ( $contract_id !== '' && class_exists( 'BizCity_File_Contract_Registry' ) )
					? BizCity_File_Contract_Registry::get( $contract_id )
					: null;
				if ( ! is_array( $contract ) ) {
					$replacement_detail = 'PENDING — business File Contract Registry entry is not registered.';
				} else {
					$upload = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
					$base = (string) ( $upload['basedir'] ?? '' );
					$folder = (string) ( $contract['folder'] ?? '' );
					$module = (string) ( $contract['module'] ?? '' );
					$dir = $base !== '' && $folder !== '' && $module !== ''
						? trailingslashit( $base ) . $folder . DIRECTORY_SEPARATOR . $module
						: '';
					$files = $dir !== '' ? glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' ) : array();
					$probe_id = (string) ( $jsonl_replacement['probe_id'] ?? '' );
					$last_results = class_exists( 'BizCity_Diagnostics_Smoke_Runner' )
						? BizCity_Diagnostics_Smoke_Runner::get_last_results()
						: array();
					$probe_status = $probe_id !== '' && isset( $last_results[ $probe_id ]['status'] )
						? strtolower( (string) $last_results[ $probe_id ]['status'] )
						: '';
					if ( $probe_status === 'pass' ) {
						$replacement_status = 'pass';
						$replacement_detail = 'PASS — current-blog encrypted filestore parity probe ' . $probe_id . ' passed.';
					} elseif ( is_array( $files ) && ! empty( $files ) ) {
						$replacement_status = 'pass';
						$replacement_detail = 'PASS — registered encrypted business contract and JSONL evidence found.';
					} else {
						$replacement_detail = 'PENDING — contract exists, but no encrypted JSONL evidence file was found in the active window.';
					}
				}
			} elseif ( in_array( $replacement_mode, array( 'repository', 'event_stream', 'sql_structural' ), true ) ) {
				// [2026-08-29 Johnny Chu] PHASE-1.30-DIAGNOSTICS — non-JSONL replacements reach DONE only from their owner probe's persisted PASS result.
				$probe_id = (string) ( $jsonl_replacement['probe_id'] ?? '' );
				$last_results = class_exists( 'BizCity_Diagnostics_Smoke_Runner' )
					? BizCity_Diagnostics_Smoke_Runner::get_last_results()
					: array();
				$probe_status = $probe_id !== '' && isset( $last_results[ $probe_id ]['status'] )
					? strtolower( (string) $last_results[ $probe_id ]['status'] )
					: '';
				if ( $probe_status === 'pass' ) {
					$replacement_status = 'pass';
					$replacement_detail = 'PASS — owner replacement probe ' . $probe_id . ' passed on the current blog/shard.';
				} elseif ( $probe_id !== '' && $probe_status !== '' ) {
					$replacement_detail = 'PENDING — owner replacement probe ' . $probe_id . ' status is ' . $probe_status . '.';
				} elseif ( $probe_id !== '' ) {
					$replacement_detail = 'PENDING — run owner replacement probe ' . $probe_id . ' on the current blog/shard.';
				}
			} elseif ( $replacement_mode === 'retire_only' ) {
				// [2026-08-29 Johnny Chu] PHASE-1.30-DIAGNOSTICS — dead legacy schemas are complete only after lifecycle and active-caller audits pass; they do not receive a fabricated JSONL target.
				$last_results = class_exists( 'BizCity_Diagnostics_Smoke_Runner' )
					? BizCity_Diagnostics_Smoke_Runner::get_last_results()
					: array();
				$lifecycle_status = strtolower( (string) ( $last_results['core.legacy_table.lifecycle']['status'] ?? '' ) );
				$caller_status = strtolower( (string) ( $last_results['core.legacy_table.callers']['status'] ?? '' ) );
				if ( $lifecycle_status === 'pass' && $caller_status === 'pass' ) {
					$replacement_status = 'pass';
					$replacement_detail = 'PASS — lifecycle and active-caller audits confirm retire-only handling; no replacement store is claimed.';
				} else {
					$replacement_detail = 'PENDING — lifecycle and active-caller audits must both pass before retire-only handling is complete.';
				}
			}

			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - never display replacement PASS while the same table has pending/failed SQL-stop evidence.
			if ( in_array( $replacement_mode, array( 'jsonl', 'filestore', 'repository', 'event_stream' ), true )
				&& is_array( $crud_stop_check )
				&& ! $crud_stop_ready
				&& $replacement_status === 'pass' ) {
				$replacement_status = 'pending';
				$replacement_detail = 'PENDING — replacement parity exists, but SQL CRUD-stop evidence is ' . ( $crud_stop_status !== '' ? $crud_stop_status : 'unavailable' ) . '.';
			}

			if ( $exists ) {
				$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$physical}`" );
				$info = $wpdb->get_row( $wpdb->prepare(
					"SELECT (DATA_LENGTH + INDEX_LENGTH) AS sz
					 FROM information_schema.TABLES
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
					$physical
				), ARRAY_A );
				$size_human = $info ? size_format( (int) $info['sz'], 2 ) : '—';

				if ( $quarantine_only && $policy_state !== 'ready_to_drop' ) {
					$skip = 'QUARANTINE ONLY — owner migration/sign-off required before drop';
					// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — expose explicit migration gate to prevent premature SQL drops.
					if ( $orphan_gate !== '' ) {
						$skip .= ' · gate: ' . $orphan_gate;
					}
				} elseif ( $rows === 0 && class_exists( 'BizCity_Legacy_Table_Policy' ) && BizCity_Legacy_Table_Policy::can_drop( $e['name'] ) ) {
					$safe = true;
				} elseif ( $rows === 0 ) {
					$skip = 'FLAG REQUIRED — mark ready_to_drop with an approval reference before DROP';
				} else {
					$skip = sprintf( 'HAS %d ROWS — export/migrate before drop', $rows );
				}
			} else {
				$skip = $absent_verified
					? 'ABSENT — lifecycle + active caller audit PASS; no DROP needed'
					: ( $quarantine_only ? 'ABSENT — quarantine owner/parity gate remains open' : 'ABSENT — run lifecycle and active caller probes before classifying as unused' );
			}

			$out[] = [
				'name'         => $e['name'],
				'physical'     => $physical,
				'reason'       => $e['reason'],
				'module'       => (string) ( $e['module']  ?? 'deprecated' ),
				'feature'      => (string) ( $e['feature'] ?? 'legacy cleanup' ),
				'purpose'      => (string) ( $e['purpose'] ?? '' ),
				'class'        => (string) ( $e['class']   ?? '' ),
				'related_tables' => $related_tables,
				'orphan_gate'    => $orphan_gate,
				'exists'       => $exists,
				'rows'         => $rows,
				'size_human'   => $size_human,
				'safe_to_drop' => $safe,
				'skip_reason'  => $skip,
				'quarantine_only' => $quarantine_only,
				'policy_state' => $policy_state,
				'approval_ref' => $approval_ref,
				'absent_verified' => $absent_verified,
				'catalog_audit_pass' => $catalog_audit_pass,
				'caller_audit_pass' => $caller_audit_pass,
				'jsonl_replacement' => $jsonl_replacement,
				'replacement_status' => $replacement_status,
				'replacement_mode' => $replacement_mode,
				'replacement_detail' => $replacement_detail,
				'replacement_dates' => $replacement_dates,
				'crud_stop_status' => $crud_stop_status,
				'crud_stop_ready' => $crud_stop_ready,
				'crud_stop_blockers' => is_array( $crud_stop_check ) && is_array( $crud_stop_check['blockers'] ?? null ) ? $crud_stop_check['blockers'] : array(),
			];
		}

		$wpdb->suppress_errors( $prev_suppress );
		return $out;
	}

	/**
	 * Auto-drop ALL empty deprecated tables on the current shard.
	 * Tables with rows > 0 are skipped. No dry-run, no constant gate.
	 *
	 * @param bool $force Bypass the per-hour throttle.
	 * @return array{
	 *   blog_id:int, prefix:string, throttled:bool,
	 *   actions:array<int,array{name:string,physical:string,action:string,detail:string}>
	 * }
	 */
	public static function auto_drop( bool $force = false ): array {
		global $wpdb;

		$result = [
			'blog_id'   => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'prefix'    => $wpdb->prefix,
			'throttled' => false,
			'actions'   => [],
		];

		// Throttle: skip if ran recently on this blog.
		if ( ! $force && get_transient( self::THROTTLE_TRANSIENT ) ) {
			$result['throttled'] = true;
			return $result;
		}

		$preview = self::preview();
		foreach ( $preview as $row ) {
			$action = 'skipped';
			$detail = '';

			if ( ! $row['exists'] ) {
				$action = 'noop';
				$detail = 'already absent';
			} elseif ( ! $row['safe_to_drop'] ) {
				$action = 'skipped';
				$detail = $row['skip_reason'];
			} else {
				$ok = $wpdb->query( "DROP TABLE IF EXISTS `{$row['physical']}`" );
				if ( $ok === false ) {
					$action = 'error';
					$detail = $wpdb->last_error ?: 'unknown';
				} else {
					$action = 'dropped';
					$detail = sprintf( 'DROP TABLE OK (was %s, 0 rows)', $row['size_human'] );
					// [2026-08-26 Johnny Chu] R-METADATA-CACHE — invalidate the cached table presence after a successful quarantine cleanup.
					if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
						bizcity_tbl_invalidate( $row['physical'] );
					}
					if ( class_exists( 'BizCity_Legacy_Table_Policy' ) ) {
						BizCity_Legacy_Table_Policy::mark_dropped( $row['name'] );
					}
				}
			}

			$result['actions'][] = [
				'name'     => $row['name'],
				'physical' => $row['physical'],
				'action'   => $action,
				'detail'   => $detail,
			];
		}

		set_transient( self::THROTTLE_TRANSIENT, time(), self::THROTTLE_SECONDS );
		self::append_log( $result );
		return $result;
	}

	/** Append a compact log entry (capped). */
	private static function append_log( array $result ): void {
		$log = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		$log[] = [
			'ts'      => gmdate( 'c' ),
			'user'    => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'blog_id' => $result['blog_id'],
			'prefix'  => $result['prefix'],
			'summary' => self::summarise( $result['actions'] ),
		];
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	private static function summarise( array $actions ): array {
		$counts = [ 'dropped' => 0, 'skipped' => 0, 'noop' => 0, 'error' => 0 ];
		foreach ( $actions as $a ) {
			$k = $a['action'] ?? 'skipped';
			if ( isset( $counts[ $k ] ) ) {
				$counts[ $k ]++;
			}
		}
		return $counts;
	}

	/** Recent log entries (newest last). */
	public static function get_log(): array {
		$log = get_option( self::LOG_OPTION, [] );
		return is_array( $log ) ? $log : [];
	}
}
