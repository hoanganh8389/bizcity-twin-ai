<?php
/**
 * DDV probe for complete WebChat session-state SQL retirement.
 *
 * Uses disposable encrypted business records. Message SQL remains outside this
 * probe because webchat_messages is a separate canonical owner.
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

if ( class_exists( 'BizCity_Probe_WebChat_Session_Filestore', false ) ) {
	return;
}

final class BizCity_Probe_WebChat_Session_Filestore implements BizCity_Diagnostics_Probe {

	const CONTRACT = 'modules.webchat.session_state';

	/** @var array<int,string> */
	private $record_ids = array();

	public function id(): string {
		return 'core.webchat.session_filestore_parity';
	}

	public function label(): string {
		return 'WebChat session-state filestore parity';
	}

	public function description(): string {
		return 'Checks session CRUD, list/count, project/status updates, tombstones, platform isolation and absence of session-table SQL.';
	}

	public function severity(): string {
		return 'blocking';
	}

	public function order(): int {
		return 81;
	}

	public function icon(): string {
		return 'Database';
	}

	public function estimate_ms(): int {
		return 220;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_WebChat_Session_State' ) ) {
			return new WP_Error( 'session_state_owner_missing', 'BizCity_WebChat_Session_State is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return new WP_Error( 'filestore_classes_missing', 'Filestore contract/store classes are not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — exercise the complete message-independent session-state contract with disposable records.
		$steps = array();
		$pass = true;
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

		$contract_ok = BizCity_File_Contract_Registry::has( self::CONTRACT );
		$add_step(
			'Disk/Loader - session-state contract registered',
			$contract_ok,
			$contract_ok ? self::CONTRACT . ' is registered.' : self::CONTRACT . ' is missing.'
		);
		if ( ! $contract_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Session-state contract is missing.', 'steps' => $steps );
		}

		$store = BizCity_WebChat_Session_State::instance();
		$user_id = (int) get_current_user_id();
		$session_id = 'diag_session_state_' . wp_generate_uuid4();
		$admin_session_id = $session_id . '_admin';
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — measure only SQL emitted by this fixture, excluding bootstrap history.
		global $wpdb;
		$query_baseline = isset( $wpdb->queries ) && is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		$created = $store->create_for_session( $session_id, $user_id, 'Diagnostics', 'State parity', 'WEBCHAT', array( 'project_id' => 'diag_project' ) );
		$state = $store->get_by_session( $session_id, 'WEBCHAT' );
		$this->record_ids[] = $state ? (string) $state->record_id : '';
		$crud_ok = ! empty( $created['id'] ) && $created['session_id'] === $session_id && $state
			&& (int) $state->id === (int) $created['id']
			&& 'diag_project' === (string) $state->project_id
			&& 'WEBCHAT' === (string) $state->platform_type;
		$add_step(
			'Runtime - create/read session state',
			$crud_ok,
			$crud_ok ? 'Session state was created and read from the encrypted filestore under the original session identity.' : 'Session state create/read compatibility contract failed.'
		);

		$update_ok = $state && $store->update( $state->id, array(
			'title' => 'Updated state',
			'project_id' => 'diag_project_2',
			'status' => 'closed',
			'rolling_summary' => 'summary',
			'context_tokens' => 42,
		) );
		$updated = $store->get_by_session( $session_id, 'WEBCHAT' );
		$update_ok = $update_ok && $updated && 'Updated state' === $updated->title
			&& 'diag_project_2' === (string) $updated->project_id
			&& 'closed' === (string) $updated->status
			&& 42 === (int) $updated->context_tokens;
		$add_step(
			'Runtime - update title/project/status/summary state',
			$update_ok,
			$update_ok ? 'Session lifecycle fields fold into the encrypted state record.' : 'Session lifecycle update did not round-trip through filestore.'
		);

		$admin_created = $store->create_for_session( $admin_session_id, $user_id, 'Diagnostics', 'ADMINCHAT', 'Admin state', array( 'project_id' => 'diag_project' ) );
		$admin_state = $store->get_by_session( $admin_session_id, 'ADMINCHAT' );
		$this->record_ids[] = $admin_state ? (string) $admin_state->record_id : '';
		$webchat_rows = $store->list_for_user( $user_id, 'WEBCHAT', 50, null, 'all' );
		$admin_rows = $store->list_for_user( $user_id, 'ADMINCHAT', 50, null, 'all' );
		$project_rows = $store->list_by_project( 'diag_project', 50 );
		$isolation_ok = ! empty( $admin_created['id'] ) && $admin_state
			&& count( $webchat_rows ) >= 1 && count( $admin_rows ) >= 1
			&& count( $project_rows ) >= 1
			&& 'ADMINCHAT' === (string) $admin_state->platform_type
			&& 'WEBCHAT' === (string) $webchat_rows[0]->platform_type;
		$add_step(
			'Runtime - list/count/project and platform isolation',
			$isolation_ok,
			$isolation_ok ? 'User, project and platform filters operate on folded session-state records.' : wp_json_encode( array( 'admin_created' => $admin_created, 'admin_state_platform' => $admin_state ? $admin_state->platform_type : '', 'webchat_count' => count( $webchat_rows ), 'admin_count' => count( $admin_rows ), 'project_count' => count( $project_rows ), 'webchat_platforms' => array_map( function ( $row ) { return $row->platform_type; }, $webchat_rows ), 'project_ids' => array_map( function ( $row ) { return $row->project_id; }, $project_rows ) ) )
		);

		$delete_ok = $state && $store->delete( $state->id ) && null === $store->get_by_session( $session_id, 'WEBCHAT' );
		$add_step(
			'Runtime - delete uses filestore tombstone',
			$delete_ok,
			$delete_ok ? 'Delete writes a tombstone and removes the folded session state.' : 'Session-state tombstone/delete parity failed.'
		);

		$session_sql_hits = 0;
		if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
			foreach ( array_slice( $wpdb->queries, $query_baseline ) as $query ) {
				if ( false !== stripos( (string) ( $query[0] ?? '' ), 'bizcity_webchat_sessions' ) ) {
					$session_sql_hits++;
				}
			}
		}
		$add_step(
			'Runtime - no session-table SQL during parity',
			0 === $session_sql_hits,
			0 === $session_sql_hits ? 'Session CRUD/list/update/delete issued no SQL against bizcity_webchat_sessions.' : 'Session-table SQL was observed during the session-state parity run.'
		);

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'WebChat session state is filestore-owned with no session-table SQL in the parity run.' : 'WebChat session-state filestore parity failed.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		if ( ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return;
		}
		foreach ( $this->record_ids as $record_id ) {
			if ( $record_id !== '' ) {
				BizCity_Business_JSONL_File_Store::delete( self::CONTRACT, $record_id, array( 'blog_id' => get_current_blog_id() ) );
			}
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_WebChat_Session_Filestore';
	return $list;
} );
