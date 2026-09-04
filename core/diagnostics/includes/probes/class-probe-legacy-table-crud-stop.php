<?php
/**
 * DDV probe for the requested SQL CRUD-stop candidate tables.
 *
 * This is an evidence probe only. It never disables a writer, changes a
 * lifecycle state, writes fixture data, or drops a table. Static evidence is
 * active PHP only; runtime evidence is limited to the current request query
 * capture supplied by SAVEQUERIES/$wpdb->queries.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-29
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Legacy_Table_CRUD_Stop', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_Table_CRUD_Stop implements BizCity_Diagnostics_Probe {

	/**
	 * Replacement targets requested by the storage review, excluding KG usage
	 * and Hub LLM usage. KG mentions is included as a dead-table retire-only
	 * target so the requested cohort gets one complete audit pass.
	 */
	private $targets = array(
		// [2026-09-01 Johnny Chu] PHASE-1.30-ZALO-MEMORY-REMOVE — retired Zalo memory is covered by lifecycle/caller probes, not active CRUD-stop targets.
		'bizcity_intent_logs',
		'bizcity_intent_prompt_logs',
		'bizcity_memory_logs',
		'bizcity_mcp_audit_log',
		'bizcity_kg_source_progress_log',
		'bizcity_llm_usage_clients',
		'bizcity_automation_logs',
		'bizcity_facebook_bot_logs',
		'bizcity_zalo_bot_logs',
		'bizcity_google_usage_logs',
		'bizcity_kg_cleanup_log',
		'bizcity_skill_logs',
		'bizcity_twin_context_logs',
		'bizcity_memory_users',
		'bizcity_memory_episodic',
		'bizcity_memory_rolling',
		'bizcity_memory_session',
		'bizcity_memory_notes',
		'bizcity_cg_flows',
		'bizcity_webchat_projects',
		'bizcity_webchat_tasks',
		'bizcity_webchat_task_steps',
		// [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — audit dead KG mentions together with the other requested SQL tables.
		'bizcity_kg_mentions',
		'bizcity_webchat_tools',
	);

	public function id(): string {
		return 'core.legacy_table.crud_stop';
	}

	public function label(): string {
		return 'Legacy candidates - SQL CRUD stop evidence';
	}

	public function description(): string {
		return 'Audits 24 requested legacy replacement targets independently for active readers, writers, lifecycle fallback blocking, install blocking, and current-request SQL mutations.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 25;
	}

	public function icon(): string {
		return 'shield-off';
	}

	public function estimate_ms(): int {
		return 500;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			return new WP_Error( 'table_registry_missing', 'Diagnostics table registry is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Legacy_Table_Policy' ) ) {
			return new WP_Error( 'legacy_policy_missing', 'Legacy table lifecycle policy is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT - emit independent writer/reader/fallback and mutation evidence for 24 candidate tables.
		$observation_started_at = gmdate( 'c' );
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$catalog = $this->catalog_by_name();
		$references = array();
		foreach ( $this->targets as $table ) {
			$references[ $table ] = array();
		}
		$files_checked = 0;
		$this->scan_directory( $root, $root, $references, $files_checked );

		$runtime = $this->runtime_queries( $ctx->option( 'query_start', null ) );
		$runtime_available = null !== $runtime;
		$checks = array();
		$steps = array();
		$all_pass = $runtime_available;

		foreach ( $this->targets as $table ) {
			$row = isset( $catalog[ $table ] ) ? $catalog[ $table ] : array();
			$source_refs = isset( $references[ $table ] ) ? $references[ $table ] : array();
			$source_writers = array();
			$source_readers = array();
			$source_schema = array();
			$source_indeterminate = array();
			foreach ( $source_refs as $reference ) {
				// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - keep schema DDL separate from data CRUD so writer=0 means no row mutation.
				if ( in_array( $reference['operation'], array( 'insert', 'update', 'delete', 'replace', 'load', 'truncate' ), true ) ) {
					$source_writers[] = $reference['evidence'];
				} elseif ( 'select' === $reference['operation'] ) {
					$source_readers[] = $reference['evidence'];
				} elseif ( in_array( $reference['operation'], array( 'dbDelta', 'create_table', 'alter_table', 'drop_table' ), true ) ) {
					$source_schema[] = $reference['evidence'];
				} elseif ( 'indeterminate' === $reference['operation'] ) {
					$source_indeterminate[] = $reference['evidence'];
				}
			}
			$source_writers = array_values( array_unique( $source_writers ) );
			$source_readers = array_values( array_unique( $source_readers ) );
			$source_schema = array_values( array_unique( $source_schema ) );
			$source_indeterminate = array_values( array_unique( $source_indeterminate ) );

			$policy_read_blocked = ! BizCity_Legacy_Table_Policy::allow_sql( $table, 'read' );
			$policy_write_blocked = ! BizCity_Legacy_Table_Policy::allow_sql( $table, 'write' );
			$install_blocked = BizCity_Legacy_Table_Policy::install_blocked( $table );
			$local_write_blocked = false;
			if ( 'bizcity_webchat_tasks' === $table || 'bizcity_webchat_task_steps' === $table ) {
				$local_write_blocked = class_exists( 'BizCity_WebChat_Database' )
					&& BizCity_WebChat_Database::table_write_blocked( $table );
			}
			$fallback_write_blocked = $policy_write_blocked || $local_write_blocked;
			$fallback_read_blocked = $policy_read_blocked;
			// [2026-08-29 Johnny Chu] PHASE-1.30-WRITER-STOP — writer cutover is independently observable while read fallback remains available during draining.
			$writer_stop = $install_blocked && $fallback_write_blocked;
			$replacement = isset( $row['jsonl_replacement'] ) && is_array( $row['jsonl_replacement'] ) ? $row['jsonl_replacement'] : array();
			$declared_mode = (string) ( $replacement['mode'] ?? '' );
			$contract_id = (string) ( $replacement['contract_id'] ?? '' );
			$filestore_contract_ready = $declared_mode === 'filestore'
				&& $contract_id !== ''
				&& class_exists( 'BizCity_File_Contract_Registry' )
				&& BizCity_File_Contract_Registry::has( $contract_id );
			$jsonl_contract_ready = $declared_mode === 'jsonl'
				&& $contract_id !== ''
				&& class_exists( 'BizCity_Log_Contract_Registry' )
				&& BizCity_Log_Contract_Registry::get( $contract_id ) !== null;
			$replacement_contract_required = in_array( $declared_mode, array( 'jsonl', 'filestore' ), true );
			$owner_probe_id = (string) ( $replacement['probe_id'] ?? '' );
			$owner_probe_ready = false;
			if ( in_array( $declared_mode, array( 'repository', 'event_stream' ), true )
				&& $owner_probe_id !== ''
				&& class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ) {
				$last_results = BizCity_Diagnostics_Smoke_Runner::get_last_results();
				$owner_probe_ready = isset( $last_results[ $owner_probe_id ] )
					&& is_array( $last_results[ $owner_probe_id ] )
					&& (string) ( $last_results[ $owner_probe_id ]['status'] ?? '' ) === 'pass';
			}
			// [2026-09-01 Johnny Chu] PHASE-1.30-DDV — repository/event-stream replacements require persisted PASS evidence from their owner probe; mode labels alone are not parity evidence.
			$replacement_contract_ready = $replacement_contract_required
				? ( $jsonl_contract_ready || $filestore_contract_ready )
				: ( in_array( $declared_mode, array( 'repository', 'event_stream' ), true )
					? $owner_probe_ready
					: in_array( $declared_mode, array( 'sql_structural', 'retire_only' ), true ) );
			$runtime_row = isset( $runtime[ $table ] ) ? $runtime[ $table ] : array( 'reads' => 0, 'writes' => 0, 'mutations' => array() );
			$runtime_mutation_count = count( $runtime_row['mutations'] );
			$static_writer_zero = empty( $source_writers ) && empty( $source_indeterminate );
			$static_reader_zero = empty( $source_readers ) && empty( $source_indeterminate );
			$runtime_writer_zero = $runtime_available && 0 === (int) $runtime_row['writes'];
			$runtime_reader_zero = $runtime_available && 0 === (int) $runtime_row['reads'];
			$writer_zero = $static_writer_zero && $runtime_writer_zero;
			$reader_zero = $static_reader_zero && $runtime_reader_zero;
			$fallback_blocked = $fallback_read_blocked && $fallback_write_blocked;
			$observation_ok = $runtime_available && 0 === $runtime_mutation_count;
			$sql_stop_blocked = $install_blocked && $fallback_blocked;
			$writer_stop_status = $runtime_available && $observation_ok && $replacement_contract_ready && $writer_stop ? 'pass' : ( $runtime_available ? 'fail' : 'deferred' );
			$status = $observation_ok && $replacement_contract_ready && ( $writer_zero || $fallback_write_blocked ) && ( $reader_zero || $fallback_read_blocked ) && $install_blocked ? 'pass' : ( $runtime_available ? 'fail' : 'deferred' );
			$blockers = array();
			if ( ! $static_writer_zero && ! $fallback_write_blocked ) {
				$blockers[] = 'active writer references remain and lifecycle does not block SQL writes';
			}
			if ( ! $static_reader_zero && ! $fallback_read_blocked ) {
				$blockers[] = 'active reader references remain and lifecycle does not block SQL reads';
			}
			if ( ! empty( $source_indeterminate ) && ! $fallback_blocked ) {
				$blockers[] = 'dynamic/ambiguous table references remain; SQL operation could not be proven statically';
			}
			if ( ! $observation_ok ) {
				$blockers[] = $runtime_available ? 'current observation window contains SQL mutation(s)' : 'SAVEQUERIES/$wpdb->queries unavailable';
			}
			if ( ! $install_blocked ) {
				$blockers[] = 'lifecycle still permits installation/DDL';
			}
			if ( ! $replacement_contract_ready ) {
				if ( $replacement_contract_required ) {
					$blockers[] = $contract_id !== '' ? 'declared replacement contract is not registered/loaded' : 'no replacement contract is declared';
				} elseif ( in_array( $declared_mode, array( 'repository', 'event_stream' ), true ) ) {
					$blockers[] = $owner_probe_id !== '' ? ( 'owner replacement probe has no persisted PASS evidence: ' . $owner_probe_id ) : 'replacement owner probe is not declared';
				} else {
					$blockers[] = $declared_mode !== '' ? ( 'unsupported replacement mode for this probe: ' . $declared_mode ) : 'missing replacement mode declaration';
				}
			}
			if ( ! empty( $source_schema ) ) {
				$blockers[] = 'schema references remain';
			}
			if ( 'deferred' === $status ) {
				$blockers[] = 'runtime mutation zero cannot be proven in this request';
			}
			$check = array(
				'table'                    => $table,
				'declared_mode'            => isset( $replacement['mode'] ) ? (string) $replacement['mode'] : 'unknown',
				'requested_mode'           => $declared_mode,
				'replacement_contract_id'  => $contract_id,
				'replacement_contract_ready' => $replacement_contract_ready,
				'replacement_contract_required' => $replacement_contract_required,
				'owner_probe_id'           => $owner_probe_id,
				'owner_probe_ready'        => $owner_probe_ready,
				'jsonl_contract_ready'     => $jsonl_contract_ready,
				'filestore_contract_ready' => $filestore_contract_ready,
				'policy_state'             => BizCity_Legacy_Table_Policy::get_state( $table ),
				'static_writer_zero'       => $static_writer_zero,
				'static_reader_zero'       => $static_reader_zero,
				'writer_zero'              => $writer_zero,
				'reader_zero'              => $reader_zero,
				'fallback_blocked'         => $fallback_blocked,
				'writer_stop'              => $writer_stop,
				'writer_stop_status'       => $writer_stop_status,
				'sql_stop_blocked'         => $sql_stop_blocked,
				'install_blocked'          => $install_blocked,
				'fallback_read_blocked'    => $fallback_read_blocked,
				'fallback_write_blocked'   => $fallback_write_blocked,
				'policy_read_blocked'      => $policy_read_blocked,
				'policy_write_blocked'     => $policy_write_blocked,
				'local_write_blocked'      => $local_write_blocked,
				'static_writer_refs'       => $source_writers,
				'static_reader_refs'       => $source_readers,
				'static_schema_refs'       => $source_schema,
				'static_indeterminate_refs' => $source_indeterminate,
				'runtime_reads'            => (int) $runtime_row['reads'],
				'runtime_writes'           => (int) $runtime_row['writes'],
				'runtime_mutations'        => $runtime_row['mutations'],
				'runtime_mutations_zero'   => $observation_ok,
				'observation_window'       => array(
					'scope'           => 'request_local_query_delta',
					'started_at'      => $observation_started_at,
					'ended_at'        => gmdate( 'c' ),
					'query_start'     => $ctx->option( 'query_start', null ),
					'query_end'       => $runtime_available ? $runtime['_query_end'] : null,
					'query_capture'   => $runtime_available ? 'SAVEQUERIES' : 'unavailable',
				),
				'status'                   => $status,
				'blockers'                => $blockers,
			);
			$checks[] = $check;
			$all_pass = $all_pass && 'pass' === $status;
			$detail = $status . ' | writer_stop_status=' . $writer_stop_status . ' writer_zero=' . ( $writer_zero ? 'true' : 'false' )
				. ' reader_zero=' . ( $reader_zero ? 'true' : 'false' )
				. ' writer_stop=' . ( $writer_stop ? 'true' : 'false' )
				. ' fallback_blocked=' . ( $fallback_blocked ? 'true' : 'false' )
				. ' mutations=' . $runtime_mutation_count;
			if ( ! empty( $blockers ) ) {
				$detail .= ' | ' . implode( '; ', array_slice( $blockers, 0, 2 ) );
			}
			$step = array( 'label' => 'CRUD stop: ' . $table, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		$runtime_mutations_clean = $runtime_available;
		foreach ( $checks as $completed_check ) {
			if ( empty( $completed_check['runtime_mutations_zero'] ) ) {
				$runtime_mutations_clean = false;
				break;
			}
		}
		$runtime_step = array(
			'label'  => 'Runtime observation window',
			// [2026-08-29 Johnny Chu] PHASE-1.32-DIAGNOSTICS-STREAM - observation status reflects SQL mutation capture only, not unrelated lifecycle blockers.
			'status' => $runtime_available ? ( $runtime_mutations_clean ? 'pass' : 'fail' ) : 'deferred',
			'detail' => $runtime_available
				? 'Captured query delta from query_start=' . (string) $ctx->option( 'query_start', 0 ) . ' to query_end=' . (string) $runtime['_query_end'] . '; mutation SQL is classified per target.'
				: 'SAVEQUERIES/$wpdb->queries is unavailable; no runtime zero-mutation claim is made.',
		);
		$steps[] = $runtime_step;
		$ctx->emit_step( $runtime_step );

		return array(
			'status'              => $all_pass ? 'pass' : ( $runtime_available ? 'fail' : 'deferred' ),
			'summary'             => $all_pass
				? 'All 24 requested replacement targets have independent CRUD-stop evidence for this request-local observation window.'
				: 'CRUD-stop evidence is incomplete; inspect each table row and its blockers before changing storage ownership.',
			'error'               => $all_pass ? '' : 'One or more tables still have active callers, an unblocked fallback, a mutation query, or unavailable runtime capture.',
			'fix_hint'            => 'Migrate active readers/writers, block lifecycle fallback only after parity evidence, then rerun this probe with SAVEQUERIES enabled.',
			'files_checked'       => $files_checked,
			'observation_available'=> $runtime_available,
			'observation_query_start' => $ctx->option( 'query_start', null ),
			'observation_query_end'   => $runtime_available ? $runtime['_query_end'] : null,
			'table_checks'         => $checks,
			'steps'               => $steps,
		);
	}

	private function catalog_by_name() {
		$catalog = array();
		foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
			if ( is_array( $row ) && ! empty( $row['name'] ) ) {
				$catalog[ (string) $row['name'] ] = $row;
			}
		}
		return $catalog;
	}

	private function scan_directory( $directory, $root, array &$references, &$files_checked ) {
		$items = @scandir( $directory );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' || in_array( $item, array( '_archived', '_library', 'vendor', 'node_modules', 'docs', 'changelog', 'diagnostics', 'build', 'tests', 'tools', 'scaffold' ), true ) ) {
				continue;
			}
			$path = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->scan_directory( $path, $root, $references, $files_checked );
				continue;
			}
			if ( substr( $item, -4 ) !== '.php' || ! is_readable( $path ) ) {
				continue;
			}
			$files_checked++;
			$code = $this->without_comments( (string) file_get_contents( $path ) );
			$scopes = $this->find_function_scopes( $code );
			foreach ( $references as $table => &$table_refs ) {
				$offset = 0;
				while ( false !== ( $offset = strpos( $code, $table, $offset ) ) ) {
					$operations = $this->operations_for_reference( $code, $offset, $scopes );
					if ( ! empty( $operations ) ) {
						$relative = ltrim( str_replace( array( $root, '\\' ), array( '', '/' ), $path ), '/' );
						$line = 1 + substr_count( substr( $code, 0, $offset ), "\n" );
						foreach ( $operations as $operation ) {
							$table_refs[] = array(
								'operation' => $operation,
								'evidence'  => $relative . ':' . $line . ' [' . $operation . ']',
							);
						}
					}
					$offset += strlen( $table );
				}
			}
			unset( $table_refs );
		}
	}

	private function operations_for_reference( $code, $offset, array $scopes ) {
		$string_operations = $this->string_operations( $code, $offset );
		if ( false !== $string_operations ) {
			return ! empty( $string_operations ) ? $string_operations : array( 'indeterminate' );
		}
		foreach ( $scopes as $scope ) {
			if ( $offset >= $scope['start'] && $offset <= $scope['end'] ) {
				return $this->find_sql_operations( substr( $code, $scope['start'], $scope['end'] - $scope['start'] ) );
			}
		}
		return array();
	}

	private function string_operations( $code, $offset ) {
		$cursor = 0;
		foreach ( token_get_all( $code ) as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			$start = $cursor;
			$cursor += strlen( $text );
			if ( $offset < $start || $offset >= $cursor ) {
				continue;
			}
			return is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0]
				? $this->find_sql_operations( $text )
				: array();
		}
		return false;
	}

	private function find_function_scopes( $code ) {
		$scopes = array();
		$offset = 0;
		$pending = false;
		$function_start = 0;
		$braces = array();
		foreach ( token_get_all( $code ) as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			$start = $offset;
			$offset += strlen( $text );
			if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
				$pending = true;
				$function_start = $start;
				continue;
			}
			if ( '{' === $text ) {
				$braces[] = array( 'function' => $pending, 'start' => $function_start );
				$pending = false;
				continue;
			}
			if ( '}' === $text && ! empty( $braces ) ) {
				$brace = array_pop( $braces );
				if ( ! empty( $brace['function'] ) ) {
					$scopes[] = array( 'start' => $brace['start'], 'end' => $offset );
				}
			}
		}
		return $scopes;
	}

	private function find_sql_operations( $source ) {
		$operations = array();
		$patterns = array(
			'create_table' => '/CREATE\s+TABLE/i',
			'dbDelta'     => '/dbDelta\s*\(/i',
			'insert'      => '/INSERT\s+INTO/i',
			'update'      => '/UPDATE\s+/i',
			'delete'      => '/DELETE\s+FROM/i',
			'replace'     => '/REPLACE\s+INTO/i',
			'load'        => '/\bLOAD\s+DATA/i',
			'truncate'    => '/TRUNCATE\s+TABLE/i',
			'alter_table' => '/ALTER\s+TABLE/i',
			'drop_table'  => '/DROP\s+TABLE/i',
			'select'      => '/\bSELECT\b/i',
		);
		foreach ( $patterns as $operation => $pattern ) {
			if ( preg_match( $pattern, $source ) ) {
				$operations[] = $operation;
			}
		}
		return $operations;
	}

	private function runtime_queries( $query_start ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! isset( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
			return null;
		}
		$start = is_numeric( $query_start ) && (int) $query_start >= 0 ? (int) $query_start : 0;
		$rows = array_slice( $wpdb->queries, $start );
		$out = array( '_query_end' => count( $wpdb->queries ) );
		foreach ( $this->targets as $table ) {
			$out[ $table ] = array( 'reads' => 0, 'writes' => 0, 'mutations' => array() );
		}
		$names = array();
		foreach ( $this->targets as $table ) {
			$names[ $table ] = array( $table, (string) $wpdb->prefix . $table );
			if ( ! empty( $wpdb->base_prefix ) ) {
				$names[ $table ][] = (string) $wpdb->base_prefix . $table;
			}
		}
		foreach ( $rows as $query_row ) {
			$query = is_array( $query_row ) ? (string) ( $query_row[0] ?? '' ) : (string) $query_row;
			$operation = $this->query_operation( $query );
			if ( '' === $operation ) {
				continue;
			}
			foreach ( $this->targets as $table ) {
				if ( ! $this->query_mentions( $query, $names[ $table ] ) ) {
					continue;
				}
				if ( 'read' === $operation ) {
					$out[ $table ]['reads']++;
				} elseif ( 'write' === $operation ) {
					$out[ $table ]['writes']++;
					$out[ $table ]['mutations'][] = $this->redact_query( $query, $operation );
				}
			}
		}
		return $out;
	}

	private function query_operation( $query ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|LOAD|TRUNCATE)\b/i', $query ) ) { return 'write'; }
		if ( preg_match( '/^\s*(SELECT|WITH|SHOW|DESCRIBE|EXPLAIN)\b/i', $query ) ) { return 'read'; }
		return '';
	}

	private function query_mentions( $query, array $names ) {
		foreach ( $names as $name ) {
			if ( '' !== $name && false !== stripos( $query, $name ) ) {
				return true;
			}
		}
		return false;
	}

	private function redact_query( $query, $operation ) {
		return strtoupper( $operation ) . ' query observed for candidate table';
	}

	private function without_comments( $source ) {
		$out = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}
		return $out;
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_Table_CRUD_Stop';
	return $list;
} );
