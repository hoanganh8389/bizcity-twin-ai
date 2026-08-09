<?php
/**
 * Read-only DDV for the seven-day operational log retention contract.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
}

if ( class_exists( 'BizCity_Probe_Log_Retention', false ) ) {
	return;
}

final class BizCity_Probe_Log_Retention implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.log.retention'; }
	public function label(): string { return 'Logs · seven-day retention'; }
	public function description(): string { return 'Kiểm tra policy giữ log operational khoảng 7 ngày và các owner GC.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 52; }
	public function icon(): string { return 'History'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		$checks = array(
			'jsonl'     => array( 'BizCity_JSONL_File_Logger', 'RETENTION_DAYS' ),
			'channel'   => array( 'BizCity_CG_Debug_Logger', 'RETENTION_DAYS' ),
			'intent'    => array( 'BizCity_Intent_Logger', 'RETENTION_DAYS' ),
			'prompt'    => array( 'BizCity_Intent_Database', 'PROMPT_LOGS_RETENTION_DAYS' ),
			'memory'    => array( 'BizCity_Memory_Log', 'RETENTION_DAYS' ),
			'mcp'       => array( 'BizCity_MCP_Installer', 'AUDIT_RETENTION_DAYS' ),
			'skill'     => array( 'BizCity_Skill_Database', 'RETENTION_DAYS' ),
			'twin_state'=> array( 'BizCity_Twin_State_Schema', 'CONTEXT_RETENTION_DAYS' ),
			'kg_cleanup'=> array( 'BizCity_KG_Cleanup_Service', 'AUDIT_RETENTION_DAYS' ),
			'automation'=> array( 'BizCity_Automation_Repo_Runs', 'LOG_RETENTION_DAYS' ),
		);
		$steps = array();
		$ok = true;
		foreach ( $checks as $name => $check ) {
			$class = $check[0];
			$const = $check[1];
			$loaded = class_exists( $class );
			$value = $loaded && defined( $class . '::' . $const ) ? (int) constant( $class . '::' . $const ) : 0;
			$pass = $loaded && $value === 7;
			$ok = $ok && ( ! $loaded || $pass );
			$steps[] = array(
				'label' => $name . ' retention',
				'status' => $pass ? 'pass' : ( $loaded ? 'fail' : 'skip' ),
				'detail' => $loaded ? $class . '::' . $const . '=' . $value . ' days.' : $class . ' is not loaded in this runtime.',
			);
		}

		$jsonl_sweep = class_exists( 'BizCity_JSONL_File_Logger' )
			&& method_exists( 'BizCity_JSONL_File_Logger', 'purge_folder_older_than' );
		$channel_sweep = class_exists( 'BizCity_Channel_File_Logger' )
			&& method_exists( 'BizCity_Channel_File_Logger', 'purge_older_than' );
		$steps[] = array(
			'label' => 'shared JSONL sweep owner',
			'status' => $jsonl_sweep ? 'pass' : 'fail',
			'detail' => $jsonl_sweep ? 'Shared JSONL folder purge is available.' : 'Shared JSONL purge owner is unavailable.',
		);
		$steps[] = array(
			'label' => 'channel JSONL sweep owner',
			'status' => $channel_sweep ? 'pass' : 'fail',
			'detail' => $channel_sweep ? 'Channel JSONL purge is available.' : 'Channel JSONL purge owner is unavailable.',
		);
		$ok = $ok && $jsonl_sweep && $channel_sweep;

		$orphan_names = array( 'bizcity_intent_logs', 'bizcity_intent_prompt_logs', 'bizcity_memory_logs', 'bizcity_mcp_audit_log' );
		$orphan_rows = class_exists( 'BizCity_Diagnostics_Table_Registry' )
			? BizCity_Diagnostics_Table_Registry::deprecated_tables()
			: array();
		$orphan_ok = true;
		foreach ( $orphan_names as $name ) {
			$matches = array_values( array_filter( $orphan_rows, static function ( $row ) use ( $name ) {
				return is_array( $row ) && (string) ( $row['name'] ?? '' ) === $name;
			} ) );
			$orphan_ok = $orphan_ok && count( $matches ) === 1 && empty( $matches[0]['quarantine_only'] );
		}
		$steps[] = array(
			'label' => 'deprecated_tables orphan catalog',
			'status' => $orphan_ok ? 'pass' : 'fail',
			'detail' => $orphan_ok ? 'Four retired SQL log tables are non-quarantine orphan candidates.' : 'Retired SQL log catalog is missing or still quarantine-only.',
		);
		$ok = $ok && $orphan_ok;

		return array(
			'status' => $ok ? 'pass' : 'fail',
			'summary' => $ok ? 'Operational log retention is configured for seven days.' : 'One or more operational log retention owners are not configured for seven days.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Log_Retention';
	return $list;
} );
