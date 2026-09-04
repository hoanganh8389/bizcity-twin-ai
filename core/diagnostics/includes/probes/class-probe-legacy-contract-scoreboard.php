<?php
/**
 * Framework-contract scoreboard for the legacy-table migration wave.
 *
 * This probe scores each deprecated catalog row against the replacement mode
 * declared by Diagnostics. It does not turn a partial score into migration
 * completion: SQL-writer retirement, observation, approval, shard coverage and
 * zero-row DROP remain separate lifecycle gates.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-29
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Legacy_Contract_Scoreboard', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_Contract_Scoreboard implements BizCity_Diagnostics_Probe {

	const MAX_SCORE = 100;

	public function id(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - expose the mode-aware contract scoreboard as a stable probe.
		return 'core.legacy_table.contract_scoreboard';
	}

	public function label(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - identify the legacy contract readiness scoreboard.
		return 'Legacy tables - framework contract scoreboard';
	}

	public function description(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - document mode-specific scoring and the non-completion semantics.
		return 'Scores every deprecated table on catalog metadata, canonical owner contract, runtime replacement evidence and basic read/search/index evidence without treating a partial score as migration completion.';
	}

	public function severity(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - incomplete framework contract readiness is a critical migration signal.
		return 'critical';
	}

	public function order(): int {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - run after replacement, owner and pointer probes in the core batch.
		return 99;
	}

	public function icon(): string {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - provide the diagnostics scoreboard icon.
		return 'gauge';
	}

	public function estimate_ms(): int {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - declare bounded catalog scoring cost.
		return 120;
	}

	public function precondition() {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - require the replacement catalog and persisted probe result reader.
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			return new WP_Error( 'table_registry_missing', 'Diagnostics table registry is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ) {
			return new WP_Error( 'smoke_runner_missing', 'Diagnostics smoke runner is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - score the current-blog legacy replacement catalog by its declared storage mode.
		$steps = array();
		$catalog_rows = BizCity_Diagnostics_Table_Registry::deprecated_tables();
		$rows_by_name = array();
		foreach ( $catalog_rows as $catalog_row ) {
			if ( is_array( $catalog_row ) && ! empty( $catalog_row['name'] ) ) {
				$rows_by_name[ (string) $catalog_row['name'] ] = $catalog_row;
			}
		}
		$rows = array_values( $rows_by_name );
		$last_results = BizCity_Diagnostics_Smoke_Runner::get_last_results();
		$evidence_now = time();
		$score_total = 0;
		$score_max = 0;
		$complete_rows = 0;
		$mode_counts = array();
		$mode_scores = array();
		$incomplete = array();
		$score_rows = array();

		$emit = function ( $label, $status, $detail ) use ( $ctx, &$steps ) {
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		};

		if ( empty( $rows ) ) {
			$emit( 'Catalog - deprecated replacement rows', 'fail', 'No deprecated replacement rows were returned.' );
			return array( 'status' => 'fail', 'summary' => 'Legacy contract scoreboard has no catalog rows.', 'steps' => $steps );
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$name = (string) $row['name'];
			$spec = is_array( $row['jsonl_replacement'] ?? null ) ? $row['jsonl_replacement'] : array();
			$mode = (string) ( $spec['mode'] ?? 'retire_only' );
			$mode_counts[ $mode ] = (int) ( $mode_counts[ $mode ] ?? 0 ) + 1;
			$decision = $this->decision_profile( $spec, $mode );

			$catalog_ok = $this->catalog_metadata_ok( $row, $spec, $mode );
			$owner_ok = $this->owner_contract_ok( $spec, $mode );
			$probe_id = (string) ( $spec['probe_id'] ?? '' );
			$runtime_ok = $this->probe_passed( $last_results, $probe_id );
			$runtime_fresh = $this->probe_fresh( $last_results, $probe_id, $evidence_now );
			$basic_ok = $this->basic_evidence_ok( $last_results, $mode, $probe_id );
			$context_bank_ok = $this->context_bank_adapter_ok( $spec, $mode );
			// [2026-09-01 Johnny Chu] PHASE-CB4.4 — filestore memory is not complete until its Context Bank reference adapter is registered and evidenced.
			$row_score = ( $catalog_ok ? 20 : 0 ) + ( $owner_ok ? 20 : 0 ) + ( $runtime_ok && $runtime_fresh ? 20 : 0 ) + ( $basic_ok ? 20 : 0 ) + ( $context_bank_ok ? 20 : 0 );
			$score_total += $row_score;
			$score_max += self::MAX_SCORE;
			$mode_scores[ $mode ] = isset( $mode_scores[ $mode ] ) ? $mode_scores[ $mode ] + $row_score : $row_score;
			$score_rows[] = array(
				'name'            => $name,
				'mode'            => $mode,
				'probe_id'        => $probe_id,
				'data_role'       => $decision['data_role'],
				'criticality'     => $decision['criticality'],
				'capacity_profile'=> $decision['capacity_profile'],
				'crud_shape'      => $decision['crud_shape'],
				'contract_equivalent' => $decision['contract_equivalent'],
				'decision_source' => $decision['source'],
				'score'           => $row_score,
				'catalog'         => $catalog_ok,
				'owner_contract'  => $owner_ok,
				'runtime_probe'   => $runtime_ok && $runtime_fresh,
				'evidence_ts'     => isset( $last_results[ $probe_id ]['ts'] ) ? (int) $last_results[ $probe_id ]['ts'] : 0,
				'basic_evidence'  => $basic_ok,
				'context_bank_adapter' => $context_bank_ok,
				'context_bank_status' => (string) ( $spec['context_bank_adapter'] ?? ( $mode === 'filestore' ? 'missing' : 'not_required' ) ),
			);
			if ( $row_score === self::MAX_SCORE ) {
				$complete_rows++;
			} else {
				$incomplete[] = array(
					'name'   => $name,
					'mode'   => $mode,
					'score'  => $row_score,
					'failed' => array_values( array_filter( array(
						'catalog' => ! $catalog_ok ? 'catalog_metadata' : '',
						'owner'   => ! $owner_ok ? 'owner_contract' : '',
						'runtime' => ! ( $runtime_ok && $runtime_fresh ) ? 'runtime_probe_or_stale' : '',
						'basic'   => ! $basic_ok ? 'basic_read_search_index' : '',
						'context_bank' => ! $context_bank_ok ? 'context_bank_adapter_or_ledger' : '',
					) ) ),
				);
			}
		}

		$score_percent = $score_max > 0 ? round( ( $score_total / $score_max ) * 100, 2 ) : 0;
		$emit(
			'Runtime - framework contract score',
			$score_percent >= 100 ? 'pass' : 'warn',
			sprintf( '%d/%d points (%s%%); complete rows=%d/%d unique rows (raw=%d); modes=%s', $score_total, $score_max, $score_percent, $complete_rows, count( $rows ), count( $catalog_rows ), wp_json_encode( $mode_counts ) )
		);
		$emit(
			'Runtime - lifecycle completion remains separate',
			'pass',
			'Contract score does not close SQL-writer stop, zero-growth, target-shard, approval/ready_to_drop or zero-row DROP gates.'
		);

		$status = $score_percent >= 100 ? 'pass' : 'warn';
		$incomplete_summary = array();
		foreach ( array_slice( $incomplete, 0, 8 ) as $incomplete_row ) {
			$incomplete_summary[] = (string) ( $incomplete_row['name'] ?? 'unknown' ) . ':' . implode( ',', (array) ( $incomplete_row['failed'] ?? array() ) );
		}
		return array(
			'status'          => $status,
			'summary'         => sprintf( 'Framework contract catalog scoreboard: %s%% (%d/%d points), %d/%d catalog rows complete%s.', $score_percent, $score_total, $score_max, $complete_rows, count( $rows ), empty( $incomplete_summary ) ? '' : '; incomplete=' . implode( ' | ', $incomplete_summary ) ),
			'fix_hint'        => empty( $incomplete ) ? '' : 'Close the failed dimensions per row: register the mode-appropriate owner contract, run the owner probe, and pass basic read/search/index evidence before stopping SQL writers.',
			'score'           => $score_total,
			'max_score'       => $score_max,
			'score_percent'   => $score_percent,
			'complete_rows'    => $complete_rows,
			'total_rows'      => count( $rows ),
			'mode_counts'     => $mode_counts,
			'mode_scores'     => $mode_scores,
			'catalog_rows_raw'=> count( $catalog_rows ),
			'score_rows'      => $score_rows,
			'incomplete_rows' => $incomplete,
			'steps'           => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV - scoreboard is read-only and creates no durable fixture.
	}

	private function catalog_metadata_ok( array $row, array $spec, $mode ) {
		$required = array( 'name', 'reason', 'module', 'feature' );
		foreach ( $required as $field ) {
			if ( ! isset( $row[ $field ] ) || (string) $row[ $field ] === '' ) {
				return false;
			}
		}
		if ( (string) ( $spec['label'] ?? '' ) === '' || (string) ( $spec['probe_id'] ?? '' ) === '' ) {
			return false;
		}
		if ( $mode !== 'retire_only' && (string) ( $spec['writer'] ?? '' ) === '' ) {
			return false;
		}
		if ( $mode === 'retire_only' && (string) ( $spec['writer'] ?? '' ) !== '' ) {
			return false;
		}
		return in_array( $mode, array( 'jsonl', 'filestore', 'event_stream', 'repository', 'sql_structural', 'retire_only' ), true );
	}

	private function context_bank_adapter_ok( array $spec, $mode ) {
		if ( $mode !== 'filestore' ) {
			return true;
		}
		return (string) ( $spec['context_bank_adapter'] ?? '' ) === 'registered'
			&& (string) ( $spec['context_bank_ledger'] ?? '' ) === 'bizcity_context_bank'
			&& (string) ( $spec['context_bank_probe_id'] ?? '' ) !== '';
	}

	private function decision_profile( array $spec, $mode ) {
		$profiles = array(
			'jsonl' => array( 'data_role' => 'operational_trace', 'criticality' => 'C_supporting', 'capacity_profile' => 'per_request_append_bounded_search', 'crud_shape' => 'append_query_read_pointer_search', 'contract_equivalent' => 'BizCity_Log_Contract_Registry' ),
			'filestore' => array( 'data_role' => 'business_record', 'criticality' => 'B_important', 'capacity_profile' => 'folded_upsert_owner_scoped', 'crud_shape' => 'upsert_tombstone_query', 'contract_equivalent' => 'BizCity_File_Contract_Registry' ),
			'event_stream' => array( 'data_role' => 'event_timeline', 'criticality' => 'B_important', 'capacity_profile' => 'append_ordered_cross_surface', 'crud_shape' => 'append_replay_projection', 'contract_equivalent' => 'BizCity_Twin_Event_Bus + Event Stream schema' ),
			'repository' => array( 'data_role' => 'core_state', 'criticality' => 'B_important', 'capacity_profile' => 'typed_owner_scoped_crud', 'crud_shape' => 'repository_crud', 'contract_equivalent' => 'Canonical owner repository/API' ),
			'sql_structural' => array( 'data_role' => 'usage_telemetry', 'criticality' => 'A_critical', 'capacity_profile' => 'atomic_indexed_query', 'crud_shape' => 'atomic_upsert_aggregate', 'contract_equivalent' => 'Typed SQL structural owner' ),
			'retire_only' => array( 'data_role' => 'derived_projection', 'criticality' => 'D_rebuildable', 'capacity_profile' => 'no_active_writer', 'crud_shape' => 'empty_degraded_zero_row_drop', 'contract_equivalent' => 'BizCity_Legacy_Table_Policy' ),
		);
		$profile = isset( $profiles[ $mode ] ) ? $profiles[ $mode ] : $profiles['retire_only'];
		$profile['source'] = empty( $spec['data_role'] ) && empty( $spec['criticality'] ) && empty( $spec['capacity_profile'] ) && empty( $spec['crud_shape'] )
			? 'mode_default_requires_explicit_decision_record'
			: 'catalog_decision_record';
		if ( ! empty( $spec['data_role'] ) ) {
			$profile['data_role'] = (string) $spec['data_role'];
		}
		if ( ! empty( $spec['criticality'] ) ) {
			$profile['criticality'] = (string) $spec['criticality'];
		}
		if ( ! empty( $spec['capacity_profile'] ) ) {
			$profile['capacity_profile'] = (string) $spec['capacity_profile'];
		}
		if ( ! empty( $spec['crud_shape'] ) ) {
			$profile['crud_shape'] = (string) $spec['crud_shape'];
		}
		if ( ! empty( $spec['contract_equivalent'] ) ) {
			$profile['contract_equivalent'] = (string) $spec['contract_equivalent'];
		}
		return $profile;
	}

	private function owner_contract_ok( array $spec, $mode ) {
		if ( $mode === 'jsonl' ) {
			$contract_id = (string) ( $spec['contract_id'] ?? '' );
			$contract = $contract_id !== '' && class_exists( 'BizCity_Log_Contract_Registry' ) ? BizCity_Log_Contract_Registry::get( $contract_id ) : null;
			// [2026-09-02 10:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — score each JSONL owner against its declared blog/network/global scope instead of assuming every contract is blog-scoped.
			$expected_scope = (string) ( $spec['storage_scope'] ?? 'blog' );
			return is_array( $contract )
				&& (string) ( $contract['owner_module'] ?? '' ) !== ''
				&& (string) ( $contract['jsonl_folder'] ?? '' ) === (string) ( $spec['folder'] ?? '' )
				&& (string) ( $contract['jsonl_module'] ?? '' ) === (string) ( $spec['module'] ?? '' )
				&& (int) ( $contract['retention_days'] ?? 0 ) > 0
				&& in_array( $expected_scope, array( 'blog', 'network', 'global' ), true )
				&& (string) ( $contract['storage_scope'] ?? '' ) === $expected_scope
				&& ! empty( $contract['indexed'] );
		}
		if ( $mode === 'filestore' ) {
			$contract_id = (string) ( $spec['contract_id'] ?? '' );
			$contract = $contract_id !== '' && class_exists( 'BizCity_File_Contract_Registry' ) ? BizCity_File_Contract_Registry::get( $contract_id ) : null;
			return is_array( $contract )
				&& (string) ( $contract['owner_module'] ?? '' ) !== ''
				&& (string) ( $contract['folder'] ?? '' ) === (string) ( $spec['folder'] ?? '' )
				&& (string) ( $contract['module'] ?? '' ) === (string) ( $spec['module'] ?? '' )
				&& (string) ( $contract['record_key'] ?? '' ) === 'record_id'
				&& (int) ( $contract['retention_days'] ?? 0 ) > 0
				&& (string) ( $contract['storage_scope'] ?? '' ) === 'blog';
		}
		if ( $mode === 'retire_only' ) {
			// [2026-09-02 11:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — accept a dedicated retire-only owner probe such as Zalo memory removal while keeping the policy contract and no-writer requirement.
			return (string) ( $spec['writer'] ?? '' ) === ''
				&& (string) ( $spec['probe_id'] ?? '' ) !== '';
		}
		// Non-JSONL replacements use their declared owner/probe as a contract-equivalent.
		return (string) ( $spec['writer'] ?? '' ) !== '' && (string) ( $spec['probe_id'] ?? '' ) !== '';
	}

	private function probe_passed( array $last_results, $probe_id ) {
		return $probe_id !== '' && isset( $last_results[ $probe_id ]['status'] ) && strtolower( (string) $last_results[ $probe_id ]['status'] ) === 'pass';
	}

	private function probe_fresh( array $last_results, $probe_id, $now ) {
		if ( $probe_id === '' || ! isset( $last_results[ $probe_id ]['ts'] ) ) {
			return false;
		}
		return (int) $last_results[ $probe_id ]['ts'] >= (int) $now - DAY_IN_SECONDS;
	}

	private function basic_evidence_ok( array $last_results, $mode, $probe_id ) {
		$index_ok = $this->probe_passed( $last_results, 'core.helper.log_index' )
			&& $this->probe_passed( $last_results, 'core.helper.jsonl_search_query_index_parity' );
		if ( $mode === 'jsonl' ) {
			return $index_ok && $this->probe_passed( $last_results, 'core.legacy_table.jsonl_source_parity' );
		}
		if ( $mode === 'filestore' ) {
			return $this->probe_passed( $last_results, $probe_id );
		}
		return $this->probe_passed( $last_results, $probe_id );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_Contract_Scoreboard';
	return $list;
} );
