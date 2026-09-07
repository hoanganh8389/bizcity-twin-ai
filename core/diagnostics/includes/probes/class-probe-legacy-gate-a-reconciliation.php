<?php
/**
 * Gate A reconciliation for the 29-entry legacy maturity manifest.
 *
 * This probe is read-only. It compares the logical manifest with the active
 * Diagnostics catalog, checks mode-appropriate owner contracts, and verifies
 * exact physical candidates through the canonical metadata helper.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-07
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Legacy_Gate_A_Reconciliation', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_Gate_A_Reconciliation implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — expose the stable Gate A reconciliation probe ID.
		return 'core.legacy_table.gate_a_reconciliation';
	}

	public function label(): string {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — label ownership and physical reconciliation evidence.
		return 'Legacy tables - Gate A ownership and physical reconciliation';
	}

	public function description(): string {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — describe the read-only reconciliation boundary.
		return 'Reconciles the 29-entry legacy manifest with the Diagnostics catalog, mode-appropriate owner contracts, Context Bank memory adapters and exact current-shard physical candidates without mutation.';
	}

	public function severity(): string {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — keep unresolved ownership as a critical diagnostics finding.
		return 'critical';
	}

	public function order(): int {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — run reconciliation after catalog and replacement evidence probes.
		return 98;
	}

	public function icon(): string {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — identify the reconciliation result in Diagnostics.
		return 'list-checks';
	}

	public function estimate_ms(): int {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — declare bounded read-only catalog and metadata cost.
		return 180;
	}

	public function precondition() {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — require the canonical registry and manifest before runtime checks.
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			return new WP_Error( 'table_registry_missing', 'Diagnostics table registry is not loaded.' );
		}
		if ( ! defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
			return new WP_Error( 'plugin_root_missing', 'BizCity Twin AI root is not defined.' );
		}
		$manifest = BIZCITY_TWIN_AI_DIR . 'docs/contracts/LEGACY-29-CONTEXT-BANK-MANIFEST-v1.json';
		if ( ! is_file( $manifest ) || ! is_readable( $manifest ) ) {
			return new WP_Error( 'manifest_unreadable', 'The 29-entry legacy manifest is not readable.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — reconcile logical ownership and exact physical candidates without mutation.
		$steps = array();
		$manifest_path = BIZCITY_TWIN_AI_DIR . 'docs/contracts/LEGACY-29-CONTEXT-BANK-MANIFEST-v1.json';
		$manifest_source = (string) file_get_contents( $manifest_path );
		$manifest = json_decode( $manifest_source, true );
		$entries = is_array( $manifest ) && is_array( $manifest['entries'] ?? null ) ? $manifest['entries'] : array();
		$catalog = BizCity_Diagnostics_Table_Registry::deprecated_tables();
		$catalog_by_name = array();
		foreach ( $catalog as $row ) {
			if ( is_array( $row ) && ! empty( $row['name'] ) ) {
				$catalog_by_name[ (string) $row['name'] ] = $row;
			}
		}

		$emit = function ( $label, $status, $detail ) use ( $ctx, &$steps ) {
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		};

		$manifest_ok = is_array( $manifest ) && count( $entries ) === 29;
		$emit(
			'Disk - logical legacy manifest',
			$manifest_ok ? 'pass' : 'fail',
			$manifest_ok ? 'LEGACY-29 manifest is readable with exactly 29 logical entries.' : 'Manifest is unreadable, invalid JSON, or does not contain exactly 29 entries.'
		);
		$loader_ok = class_exists( 'BizCity_Diagnostics_Table_Registry' );
		$emit(
			'Loader - Diagnostics catalog owner',
			$loader_ok ? 'pass' : 'fail',
			$loader_ok ? 'Active deprecated-table catalog is loaded.' : 'Active deprecated-table catalog is unavailable.'
		);

		$missing_catalog = array();
		$seen_physical = array();
		$duplicate_manifest = array();
		$missing_decision = array();
		$unresolved_owner = array();
		$missing_contract = array();
		$missing_context_bank = array();
		$structural_unclassified = array();
		$mode_mismatch = array();
		$physical_unreconciled = array();
		$physical_checked = 0;
		$physical_present = 0;
		$physical_absent = 0;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				$missing_decision[] = 'invalid_entry';
				continue;
			}
			$manifest_id = (string) ( $entry['id'] ?? 'unknown' );
			$physical_name = (string) ( $entry['physical_table'] ?? '' );
			if ( $physical_name === '' ) {
				$duplicate_manifest[ $manifest_id ] = array( $manifest_id );
			} elseif ( isset( $seen_physical[ $physical_name ] ) ) {
				if ( ! isset( $duplicate_manifest[ $physical_name ] ) ) {
					$duplicate_manifest[ $physical_name ] = array( $seen_physical[ $physical_name ] );
				}
				$duplicate_manifest[ $physical_name ][] = $manifest_id;
			} else {
				$seen_physical[ $physical_name ] = $manifest_id;
			}
			$storage_target = (string) ( $entry['storage_target'] ?? '' );
			$owner = (string) ( $entry['canonical_owner'] ?? '' );
			$probe_id = (string) ( $entry['probe_id'] ?? '' );
			if ( $storage_target === '' || $probe_id === '' || $owner === '' ) {
				$missing_decision[] = $manifest_id;
			}
			if ( $owner === '' || strtolower( $owner ) === 'unresolved' ) {
				$unresolved_owner[] = $manifest_id . ':' . $physical_name;
			}
			if ( empty( $entry['retention_policy'] ) || empty( $entry['retention_owner'] ) || empty( $entry['rollback_owner'] ) ) {
				$missing_decision[] = $manifest_id . ':retention_or_rollback';
			}
			$manifest_contract_id = (string) ( $entry['contract_id'] ?? '' );
			if ( in_array( $storage_target, array( 'jsonl', 'filestore' ), true ) ) {
				$manifest_contract = null;
				if ( $storage_target === 'jsonl' && class_exists( 'BizCity_Log_Contract_Registry' ) && $manifest_contract_id !== '' ) {
					$manifest_contract = BizCity_Log_Contract_Registry::get( $manifest_contract_id );
				}
				if ( $storage_target === 'filestore' && class_exists( 'BizCity_File_Contract_Registry' ) && $manifest_contract_id !== '' ) {
					$manifest_contract = BizCity_File_Contract_Registry::get( $manifest_contract_id );
				}
				if ( ! is_array( $manifest_contract ) ) {
					$missing_contract[] = $manifest_id . ':' . $physical_name;
				}
			}
			if ( (string) ( $entry['data_role'] ?? '' ) === 'business_record' && ! in_array( $storage_target, array( 'jsonl', 'filestore' ), true ) ) {
				$missing_contract[] = $manifest_id . ':business_record_requires_filestore';
			}
			$catalog_row = isset( $catalog_by_name[ $physical_name ] ) ? $catalog_by_name[ $physical_name ] : null;
			if ( ! is_array( $catalog_row ) ) {
				$missing_catalog[] = $manifest_id . ':' . $physical_name;
				if ( (string) ( $entry['kind'] ?? '' ) === 'unreconciled_alias' ) {
					$physical_unreconciled[] = $manifest_id . ':' . $physical_name;
				}
				continue;
			}

			$spec = is_array( $catalog_row['jsonl_replacement'] ?? null ) ? $catalog_row['jsonl_replacement'] : array();
			$mode = (string) ( $spec['mode'] ?? '' );
			$contract_id = (string) ( $entry['contract_id'] ?? $spec['contract_id'] ?? '' );
			$manifest_target = (string) ( $entry['storage_target'] ?? '' );
			if ( $manifest_target === 'filestore' && $mode !== 'filestore' ) {
				$mode_mismatch[] = $manifest_id . ':manifest=filestore/catalog=' . ( $mode !== '' ? $mode : 'missing' );
			}
			if ( $manifest_target === 'repository' && $mode === 'filestore' ) {
				$mode_mismatch[] = $manifest_id . ':manifest=repository/catalog=filestore';
			}
			$data_role = (string) ( $entry['data_role'] ?? '' );
			if ( $mode === 'filestore' && in_array( (string) ( $entry['context_bank_role'] ?? '' ), array( 'memory', 'memory_or_rule_reference' ), true ) ) {
				$adapter = (string) ( $spec['context_bank_adapter'] ?? $catalog_row['context_bank_adapter'] ?? '' );
				$ledger = (string) ( $spec['context_bank_ledger'] ?? $catalog_row['context_bank_ledger'] ?? '' );
				$adapter_probe = (string) ( $spec['context_bank_probe_id'] ?? $catalog_row['context_bank_probe_id'] ?? '' );
				if ( $adapter !== 'registered' || $ledger !== 'bizcity_context_bank' || $adapter_probe === '' ) {
					$missing_context_bank[] = $manifest_id . ':' . $physical_name;
				}
			}
			if ( in_array( $data_role, array( 'event_timeline', 'relationship_edge', 'usage_telemetry' ), true ) && $mode === '' ) {
				$structural_unclassified[] = $manifest_id . ':' . $physical_name;
			}

			$physical_checked++;
			$prefix_scope = (string) ( $catalog_row['prefix_scope'] ?? 'blog' );
			if ( function_exists( 'bizcity_tbl_exists' ) ) {
				global $wpdb;
				$physical = ( $prefix_scope === 'base' ? $wpdb->base_prefix : $wpdb->prefix ) . $physical_name;
				$exists = (bool) bizcity_tbl_exists( $physical );
				if ( $exists ) {
					$physical_present++;
				} else {
					$physical_absent++;
				}
				if ( (string) ( $entry['kind'] ?? '' ) === 'unreconciled_alias' ) {
					$physical_unreconciled[] = $manifest_id . ':' . $physical_name;
				}
			}
		}

		$owner_ok = empty( $missing_decision ) && empty( $unresolved_owner ) && empty( $mode_mismatch );
		$contract_ok = empty( $missing_contract );
		$memory_ok = empty( $missing_context_bank );
		$physical_ok = empty( $missing_catalog ) && empty( $duplicate_manifest ) && empty( $physical_unreconciled );
		$structural_ok = empty( $structural_unclassified );
		$emit( 'Runtime - owner/storage/retention/rollback metadata', $owner_ok ? 'pass' : 'fail', $owner_ok ? 'All logical entries have owner and decision metadata.' : $this->summarise( 'Missing owner/decision metadata', array_merge( $missing_decision, $unresolved_owner, $mode_mismatch ) ) );
		$emit( 'Runtime - mode-appropriate File Contract coverage', $contract_ok ? 'pass' : 'fail', $contract_ok ? 'All JSONL/filestore and business-record rows resolve to a registered contract.' : $this->summarise( 'File Contract gaps', $missing_contract ) );
		$emit( 'Runtime - Context Bank memory adapters', $memory_ok ? 'pass' : 'fail', $memory_ok ? 'All memory filestore rows declare a registered Context Bank adapter.' : $this->summarise( 'Context Bank adapter gaps', $missing_context_bank ) );
		$emit( 'Runtime - exact physical/catalog reconciliation', $physical_ok ? 'pass' : 'fail', $physical_ok ? sprintf( 'Checked %d logical candidates; current shard reports %d present and %d absent.', $physical_checked, $physical_present, $physical_absent ) : $this->summarise( 'Physical reconciliation gaps', array_merge( $missing_catalog, $physical_unreconciled ) ) );
		$emit( 'Runtime - structural SQL classification', $structural_ok ? 'pass' : 'fail', $structural_ok ? 'Structural/event/relationship rows have explicit mode metadata.' : $this->summarise( 'Unclassified structural rows', $structural_unclassified ) );

		$failures = array();
		if ( ! $manifest_ok ) { $failures[] = 'manifest'; }
		if ( ! $owner_ok ) { $failures[] = 'owner_metadata'; }
		if ( ! $contract_ok ) { $failures[] = 'file_contract'; }
		if ( ! $memory_ok ) { $failures[] = 'context_bank'; }
		if ( ! $physical_ok ) { $failures[] = 'physical_reconciliation'; }
		if ( ! $structural_ok ) { $failures[] = 'structural_sql'; }
		return array(
			'status' => empty( $failures ) ? 'pass' : 'fail',
			'summary' => empty( $failures ) ? 'Gate A reconciliation passed for the 29-entry manifest.' : 'Gate A reconciliation remains blocked: ' . implode( ', ', $failures ) . '.',
			'fix_hint' => empty( $failures ) ? '' : 'Complete the listed owner, contract, adapter, physical and structural evidence per manifest row; do not infer DROP readiness from this probe.',
			'audit_report_path' => 'wp-content/bps-backup/logs-legacy-table/legacy_07_09_2026.jsonl',
			'counts' => array(
				'manifest_entries' => count( $entries ),
				'catalog_rows' => count( $catalog ),
				'missing_catalog' => count( $missing_catalog ),
				'missing_decision' => count( $missing_decision ),
				'unresolved_owner' => count( $unresolved_owner ),
				'mode_mismatch' => count( $mode_mismatch ),
				'missing_contract' => count( $missing_contract ),
				'missing_context_bank' => count( $missing_context_bank ),
				'physical_checked' => $physical_checked,
				'physical_present' => $physical_present,
				'physical_absent' => $physical_absent,
				'physical_unreconciled' => count( $physical_unreconciled ),
				'structural_unclassified' => count( $structural_unclassified ),
			),
			'rows' => array(
				'missing_catalog' => $missing_catalog,
				'missing_decision' => $missing_decision,
				'unresolved_owner' => $unresolved_owner,
				'mode_mismatch' => $mode_mismatch,
				'missing_contract' => $missing_contract,
				'missing_context_bank' => $missing_context_bank,
				'physical_unreconciled' => $physical_unreconciled,
				'structural_unclassified' => $structural_unclassified,
			),
			'steps' => $steps,
		);
	}

	private function summarise( $label, array $rows ): string {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — bound row-level blocker output for diagnostics responses.
		return $label . ': ' . ( empty( $rows ) ? 'none' : implode( ', ', array_slice( $rows, 0, 12 ) ) ) . ( count( $rows ) > 12 ? ' ...' : '' );
	}

	public function cleanup(): void {
		// [2026-09-07 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-GATE-A — keep the reconciliation probe mutation-free.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_Gate_A_Reconciliation';
	return $list;
} );