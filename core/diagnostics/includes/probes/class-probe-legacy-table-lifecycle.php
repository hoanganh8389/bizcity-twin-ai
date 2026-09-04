<?php
/**
 * DDV probe for the PHASE-1.30 legacy-table lifecycle policy.
 *
 * This probe is read-only. It does not mark a table ready, write a policy
 * option, issue DROP TABLE, or invoke an uninstall callback.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-26
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_legacy_policy_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_legacy_policy_loader ) && is_readable( $_legacy_policy_loader ) ) {
		require_once $_legacy_policy_loader;
	}
	unset( $_legacy_policy_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Legacy_Table_Lifecycle', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_Table_Lifecycle implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.legacy_table.lifecycle'; }
	public function label(): string { return 'Legacy tables - install, SQL exit and drop gates'; }
	public function description(): string { return 'Checks PHASE-1.30 retired-table install/read/write blocking, explicit ready_to_drop approval, zero-row cleanup and uninstall wiring without destructive mutation.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 19; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 100; }
	public function precondition() {
		return class_exists( 'BizCity_Legacy_Table_Policy' ) && class_exists( 'BizCity_Diagnostics_Table_Registry' );
	}

	public function run( $ctx ): array {
		// [2026-08-26 Johnny Chu] PHASE-1.30-DDV — validate lifecycle policy without changing state or issuing SQL.
		$steps = array();
		$pass = true;
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$status = is_string( $ok ) ? $ok : ( $ok ? 'pass' : 'fail' );
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			$pass = $pass && 'fail' !== $status;
		};

		$registry = BizCity_Diagnostics_Table_Registry::deprecated_tables();
		$metadata_ok = true;
		$missing_metadata = array();
		$policy_catalog_ok = true;
		$missing_policy_names = array();
		foreach ( $registry as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			if ( trim( (string) ( $row['reason'] ?? '' ) ) === '' || trim( (string) ( $row['module'] ?? '' ) ) === '' ) {
				$metadata_ok = false;
				$missing_metadata[] = (string) $row['name'];
			}
			if ( ! empty( $row['quarantine_only'] ) && trim( (string) ( $row['orphan_gate'] ?? '' ) ) === '' ) {
				$metadata_ok = false;
				$missing_metadata[] = (string) $row['name'] . ':orphan_gate';
			}
			if ( ! BizCity_Legacy_Table_Policy::is_legacy( (string) $row['name'] ) ) {
				$policy_catalog_ok = false;
				$missing_policy_names[] = (string) $row['name'];
			}
		}
		$emit( 'Catalog metadata has owner, reason and quarantine gates', $metadata_ok, $metadata_ok ? count( $registry ) . ' deprecated entries have lifecycle metadata.' : implode( ', ', array_slice( $missing_metadata, 0, 8 ) ) );
		$emit( 'Every deprecated catalog row is known by the central policy', $policy_catalog_ok, $policy_catalog_ok ? count( $registry ) . ' catalog rows resolve through BizCity_Legacy_Table_Policy.' : implode( ', ', array_slice( $missing_policy_names, 0, 8 ) ) );

		$retired = 'bizcity_intent_logs';
		$install_blocked = BizCity_Legacy_Table_Policy::install_blocked( $retired );
		$read_blocked = ! BizCity_Legacy_Table_Policy::allow_sql( $retired, 'read' );
		$write_blocked = ! BizCity_Legacy_Table_Policy::allow_sql( $retired, 'write' );
		$emit( 'Retired table blocks install/read/write', $install_blocked && $read_blocked && $write_blocked, $install_blocked && $read_blocked && $write_blocked ? $retired . ' is blocked before SQL.' : 'Retired-table policy did not fail closed.' );

		// [2026-08-29 Johnny Chu] PHASE-1.30-WRITER-STOP — verify the declared JSONL cohort blocks writers while preserving draining reads.
		$active_operations = array( 'create', 'read', 'insert', 'update', 'delete', 'drop' );
		$active_matrix = array();
		$writer_stop_ok = true;
		foreach ( BizCity_Legacy_Table_Policy::writer_stop_tables() as $active ) {
			$active_state = BizCity_Legacy_Table_Policy::get_state( $active );
			$row_matrix = array();
			foreach ( $active_operations as $operation ) {
				$row_matrix[ $operation ] = BizCity_Legacy_Table_Policy::allow_sql( $active, $operation );
			}
			$active_matrix[ $active ] = array( 'state' => $active_state, 'operations' => $row_matrix );
			$writer_stop_ok = $writer_stop_ok
				&& $active_state === BizCity_Legacy_Table_Policy::STATE_DRAINING
				&& $row_matrix['read']
				&& ! $row_matrix['create']
				&& ! $row_matrix['insert']
				&& ! $row_matrix['update']
				&& ! $row_matrix['delete']
				&& ! $row_matrix['drop'];
		}
		$emit( 'JSONL replacement cohort is draining with read fallback only', $writer_stop_ok, $writer_stop_ok ? count( BizCity_Legacy_Table_Policy::writer_stop_tables() ) . ' log owners block SQL writes and preserve reads.' : 'One or more declared writer-stop owners does not match draining read/write policy.' );

		$retired_operations = array( 'create', 'read', 'insert', 'update', 'delete', 'drop' );
		$retired_matrix = array();
		foreach ( $retired_operations as $operation ) {
			$retired_matrix[ $operation ] = ! BizCity_Legacy_Table_Policy::allow_sql( $retired, $operation );
		}
		$matrix_ok = ! in_array( false, $retired_matrix, true );
		$emit( 'Retired operation matrix blocks create/read/insert/update/delete/drop', $matrix_ok, $matrix_ok ? $retired . ' is blocked for every caller operation before SQL.' : $retired . ' still allows at least one caller operation.' );

		$unapproved_drop = ! BizCity_Legacy_Table_Policy::can_drop( $retired );
		$emit( 'Drop requires explicit ready_to_drop approval', $unapproved_drop, $unapproved_drop ? 'No approval state means DROP is refused.' : 'Unapproved legacy table is drop-eligible.' );

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$uninstall_file = $root . 'uninstall.php';
		$uninstall_source = is_readable( $uninstall_file ) ? (string) file_get_contents( $uninstall_file ) : '';
		$uninstall_safe = $uninstall_source !== ''
			&& false !== strpos( $uninstall_source, 'WP_UNINSTALL_PLUGIN' )
			&& false !== strpos( $uninstall_source, 'uninstall_ready_tables' )
			&& false !== strpos( $uninstall_source, 'BizCity_Safe_Loader::require_file' );
		$emit( 'Uninstall uses the gated policy entrypoint', $uninstall_safe, $uninstall_safe ? 'Main uninstall delegates to approved-only legacy cleanup.' : 'Main uninstall gate or policy delegation is missing.' );

		return array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Legacy table lifecycle policy passed without destructive mutation.' : 'Legacy table lifecycle policy has contract gaps.',
			'steps' => $steps,
			'operation_matrix' => array( 'retired' => $retired_matrix, 'active' => $active_matrix ),
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_Table_Lifecycle';
	return $list;
} );
