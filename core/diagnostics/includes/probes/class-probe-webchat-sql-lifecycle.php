<?php
/**
 * DDV probe for WebChat SQL lifecycle ownership and quarantine boundaries.
 *
 * This probe is read-only with respect to production data. Blocked legacy
 * methods return safe values before any write query is reached.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_WebChat_SQL_Lifecycle', false ) ) {
	return;
}

final class BizCity_Probe_WebChat_SQL_Lifecycle implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.webchat.sql_lifecycle';
	}

	public function label(): string {
		return 'WebChat SQL lifecycle and quarantine';
	}

	public function description(): string {
		return 'Checks retained core message ownership, active Twin state tables, and retired boundaries for legacy WebChat projections.';
	}

	public function severity(): string {
		return 'blocking';
	}

	public function order(): int {
		return 78;
	}

	public function icon(): string {
		return 'Database';
	}

	public function estimate_ms(): int {
		return 120;
	}

	public function precondition() {
		return class_exists( 'BizCity_WebChat_Database' )
			? true
			: new WP_Error( 'webchat_database_missing', 'BizCity_WebChat_Database is not loaded.' );
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$add_step = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array(
				'label'  => $label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $detail,
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		// Disk/Loader: the policy owner must expose the explicit lifecycle API.
		$policy_api_ok = method_exists( 'BizCity_WebChat_Database', 'table_policy' )
			&& method_exists( 'BizCity_WebChat_Database', 'table_write_blocked' )
			&& method_exists( 'BizCity_WebChat_Database', 'table_exists_for_policy' )
			&& method_exists( 'BizCity_WebChat_Database', 'create_messages_table' );
		$add_step(
			'Disk/Loader - WebChat lifecycle policy API',
			$policy_api_ok,
			$policy_api_ok ? 'WebChat database exposes core-active, retired, and policy-aware compatibility methods.' : 'WebChat lifecycle policy API is incomplete.'
		);

		if ( ! $policy_api_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'WebChat lifecycle policy API is incomplete.',
				'steps'   => $steps,
			);
		}

		$manifest_path = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'modules/webchat/module.json'
			: dirname( __DIR__, 4 ) . '/modules/webchat/module.json';
		$manifest = is_readable( $manifest_path ) ? json_decode( (string) file_get_contents( $manifest_path ), true ) : null;
		$manifest_policy = is_array( $manifest ) && is_array( $manifest['db_table_lifecycles'] ?? null )
			? $manifest['db_table_lifecycles']
			: array();
		$expected_policy = array(
			'bizcity_webchat_messages'   => 'core_active',
			'bizcity_webchat_sessions'   => 'quarantine',
			'bizcity_webchat_conversations' => 'quarantine',
			'bizcity_webchat_projects'   => 'retired',
			'bizcity_webchat_tasks'      => 'retired',
			'bizcity_webchat_task_steps' => 'retired',
			'bizcity_memory_session'     => 'retired',
		);
		$manifest_policy_ok = $manifest_policy === $expected_policy;
		$add_step(
			'Disk - manifest/runtime lifecycle parity',
			$manifest_policy_ok,
			$manifest_policy_ok ? 'module.json lifecycle map matches the runtime quarantine policy.' : 'module.json lifecycle map does not match the expected policy.'
		);

		$core_message = BizCity_WebChat_Database::table_policy( 'bizcity_webchat_messages' ) === 'core_active';
		$projects_retired = BizCity_WebChat_Database::table_policy( 'bizcity_webchat_projects' ) === 'retired';
		$tasks_retired = BizCity_WebChat_Database::table_policy( 'bizcity_webchat_tasks' ) === 'retired';
		$steps_retired = BizCity_WebChat_Database::table_policy( 'bizcity_webchat_task_steps' ) === 'retired';
		$memory_blocked = BizCity_WebChat_Database::table_write_blocked( 'bizcity_memory_session' );
		$add_step(
			'Runtime - retained message and retired projection policy',
			$core_message && $projects_retired && $tasks_retired && $steps_retired && $memory_blocked,
			wp_json_encode( array(
				'messages'    => BizCity_WebChat_Database::table_policy( 'bizcity_webchat_messages' ),
				'projects'    => BizCity_WebChat_Database::table_policy( 'bizcity_webchat_projects' ),
				'tasks'       => BizCity_WebChat_Database::table_policy( 'bizcity_webchat_tasks' ),
				'task_steps'  => BizCity_WebChat_Database::table_policy( 'bizcity_webchat_task_steps' ),
				'memory'      => BizCity_WebChat_Database::table_policy( 'bizcity_memory_session' ),
			) )
		);

		$core_state_ok = false;
		if ( class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			$rows = BizCity_Diagnostics_Table_Registry::get_tables();
			$by_name = array();
			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) && isset( $row['name'] ) ) {
					$by_name[ (string) $row['name'] ] = $row;
				}
			}
			$core_state_ok = isset( $by_name['bizcity_twin_prompt_specs']['lifecycle'] )
				&& $by_name['bizcity_twin_prompt_specs']['lifecycle'] === 'core_active'
				&& isset( $by_name['bizcity_twin_milestones']['lifecycle'] )
				&& $by_name['bizcity_twin_milestones']['lifecycle'] === 'core_active';
		}
		$add_step(
			'Runtime - Twin prompt/milestone core-active registry',
			$core_state_ok,
			$core_state_ok ? 'twin_prompt_specs and twin_milestones remain active core state.' : 'Core prompt/milestone lifecycle metadata is missing or not core-active.'
		);

		$db = BizCity_WebChat_Database::instance();
		$task_result = $db->create_task( array( 'task_id' => 'diag_quarantine_task' ) );
		$step_result = $db->add_task_step( array( 'step_id' => 'diag_quarantine_step' ) );
		$blocked_api_ok = $task_result === '' && $step_result === '';
		$add_step(
			'Runtime - retired task writes are blocked',
			$blocked_api_ok,
			$blocked_api_ok ? 'create_task/add_task_step returned safe empty IDs without a write.' : 'A quarantined task API did not return its safe blocked value.'
		);

		$memory_ok = true;
		$memory_detail = 'Legacy session memory class is not loaded; no legacy write path was exercised.';
		if ( class_exists( 'BizCity_WebChat_Memory' ) && method_exists( 'BizCity_WebChat_Memory', 'build_from_messages' ) ) {
			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — WebChat session memory is filestore-first; quarantine evidence must prove the canonical contract is available instead of requiring the obsolete hard-block response.
			$memory_reflection = new ReflectionMethod( 'BizCity_WebChat_Memory', 'is_filestore_available' );
			$memory_reflection->setAccessible( true );
			$filestore_available = (bool) $memory_reflection->invoke( null );
			$memory_result = BizCity_WebChat_Memory::build_from_messages( array( 'session_id' => 'diag_quarantine_session' ) );
			$memory_ok = $filestore_available && is_array( $memory_result ) && ( ( $memory_result['ok'] ?? false ) || ( $memory_result['reason'] ?? '' ) === 'legacy_memory_quarantined' );
			$memory_detail = $memory_ok
				? 'Canonical encrypted session-memory filestore is available; no legacy SQL write is required for an empty diagnostic input.'
				: 'Session-memory filestore contract is unavailable or builder returned an invalid safe contract.';
		}
		$add_step( 'Runtime - session memory uses canonical filestore path', $memory_ok, $memory_detail );

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'WebChat message retention and retired projection contract passed.' : 'WebChat SQL lifecycle contract failed.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_WebChat_SQL_Lifecycle';
	return $list;
} );
