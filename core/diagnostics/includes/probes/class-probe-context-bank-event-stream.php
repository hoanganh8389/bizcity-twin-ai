<?php
/**
 * DDV probe for the selected Twin Event Stream Context Bank projection.
 *
 * Uses the canonical Event Bus, writes only an encrypted projection, checks
 * ledger admission/follow and replay idempotency, then tombstones the derived
 * projection while retaining the canonical Event Stream event.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Event_Stream', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Event_Stream implements BizCity_Diagnostics_Probe {

	const CONTRACT_ID = 'core.twin_core.context_bank_event';

	public function id(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1-DDV — expose the stable Event Stream projection probe identifier.
		return 'core.context_bank.event_stream';
	}
	public function label(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1-DDV — expose the Event Stream projection probe label.
		return 'Context Bank - Event Stream projection';
	}
	public function description(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1-DDV — describe the bounded canonical projection evidence.
		return 'Projects one allowlisted canonical Twin Event Stream event into encrypted Context Bank storage, verifies the pointer ledger and replay idempotency, then tombstones the derived projection.';
	}
	public function severity(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1-DDV — classify projection integrity as critical evidence.
		return 'critical';
	}
	public function order(): int {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1-DDV — keep the probe order stable in the diagnostics catalog.
		return 70;
	}
	public function icon(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1-DDV — expose the diagnostics catalog icon.
		return 'activity';
	}
	public function estimate_ms(): int {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1-DDV — declare the bounded runtime estimate.
		return 900;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) || ! class_exists( 'BizCity_Context_Bank_Event_Stream_Adapter' ) ) {
			return new WP_Error( 'event_stream_adapter_missing', 'Canonical Event Stream or Context Bank adapter is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) || ! class_exists( 'BizCity_File_Contract_Registry' ) ) {
			return new WP_Error( 'event_stream_projection_dependency_missing', 'Encrypted filestore, ledger or contract registry is not loaded.' );
		}
		if ( ! BizCity_File_Contract_Registry::has( self::CONTRACT_ID ) ) {
			return new WP_Error( 'event_stream_projection_contract_missing', 'Event Stream Context Bank contract is not registered.' );
		}
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( BizCity_Context_Bank_Ledger::table() ) ) {
			return new WP_Error( 'event_stream_ledger_not_provisioned', 'Context Bank ledger is not provisioned on the current tenant shard.' );
		}
		if ( function_exists( 'get_current_blog_id' ) && (int) get_current_blog_id() <= 0 ) {
			return new WP_Error( 'event_stream_tenant_missing', 'Current tenant is not resolved.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1 — prove canonical dispatch, encrypted projection, ledger follow, replay and derived tombstone cleanup.
		$steps = array();
		$pass = true;
		$blog_id = (int) get_current_blog_id();
		$user_id = (int) get_current_user_id();
		$flag_key = BizCity_Context_Bank_Event_Stream_Adapter::FEATURE_FLAG;
		$flag_missing = '__context_bank_event_stream_flag_missing__';
		$previous_flag = get_option( $flag_key, $flag_missing );
		$event_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sha1( uniqid( '', true ) );
		$record_id = 'event_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $event_uuid );
		$emit = function ( $label, $status, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( 'fail' === $status ) {
				$pass = false;
			}
		};

		try {
			update_option( $flag_key, true, false );
			$payload = array(
				'note_id' => 900000 + absint( substr( md5( $event_uuid ), 0, 6 ) ) % 9999,
				'message_id' => 'cb_probe_message_' . substr( md5( $event_uuid ), 0, 16 ),
				'mode' => 'manual',
				'identity_uuid' => 'cb_probe_identity_' . substr( md5( (string) $user_id ), 0, 16 ),
			);
			$dispatched = BizCity_Twin_Event_Bus::dispatch_v2( 'note_pinned', $payload, array(
				'event_uuid' => $event_uuid,
				'event_source' => 'notebook',
				'user_id' => $user_id,
				'blog_id' => $blog_id,
				'session_id' => 'cb_event_stream_probe',
			) );
			$emit( 'Runtime - canonical Event Bus dispatch', $dispatched === $event_uuid ? 'pass' : 'fail', $dispatched === $event_uuid ? 'note_pinned persisted with the requested Event Stream UUID.' : 'Event Bus returned an unexpected event UUID.' );

			$rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'record_id' => $record_id, 'source_contract_id' => self::CONTRACT_ID, 'blog_id' => $blog_id, 'limit' => 2 ) );
			$pointer_ok = isset( $rows[0] ) && (string) ( $rows[0]['source_record_id'] ?? '' ) === $event_uuid && (string) ( $rows[0]['record_kind'] ?? '' ) === 'event';
			$emit( 'Runtime - encrypted Event Stream projection admitted', $pointer_ok ? 'pass' : 'fail', $pointer_ok ? 'Pointer row links source_record_id to the canonical event UUID.' : 'Event projection pointer was not admitted or source identity mismatched.' );

			$follow = $pointer_ok ? BizCity_Context_Bank_Ledger::instance()->follow( $record_id, array( 'source_contract_id' => self::CONTRACT_ID, 'blog_id' => $blog_id ) ) : array();
			$follow_ok = is_array( $follow ) && ! empty( $follow['ok'] ) && ! empty( $follow['verified'] );
			$emit( 'Runtime - verified Event Stream pointer follow', $follow_ok ? 'pass' : 'fail', $follow_ok ? 'Encrypted projection receipt/hash/event identity verified.' : 'Projection pointer follow failed: ' . (string) ( $follow['reason'] ?? 'unknown' ) );

			$replayed = BizCity_Twin_Event_Bus::dispatch_v2( 'note_pinned', $payload, array(
				'event_uuid' => $event_uuid,
				'event_source' => 'notebook',
				'user_id' => $user_id,
				'blog_id' => $blog_id,
				'session_id' => 'cb_event_stream_probe',
			) );
			$replay_rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'record_id' => $record_id, 'source_contract_id' => self::CONTRACT_ID, 'blog_id' => $blog_id, 'limit' => 5 ) );
			$replay_ok = $replayed === $event_uuid && count( $replay_rows ) === 1;
			$emit( 'Runtime - Event Stream replay idempotency', $replay_ok ? 'pass' : 'fail', $replay_ok ? 'Same canonical event UUID keeps one Context Bank pointer row.' : 'Replay created a duplicate or returned a different event UUID.' );

			$tombstone = BizCity_Context_Bank_Event_Stream_Adapter::tombstone_projection( $event_uuid );
			$cleanup_ok = is_array( $tombstone ) && ! empty( $tombstone['ok'] );
			$emit( 'Runtime - derived projection tombstone', $cleanup_ok ? 'pass' : 'fail', $cleanup_ok ? 'Projection tombstone admitted; canonical Event Stream history remains untouched.' : 'Projection tombstone failed: ' . (string) ( $tombstone['reason'] ?? 'unknown' ) );
		} catch ( \Throwable $e ) {
			$emit( 'Runtime - Event Stream projection exception', 'fail', 'Canonical projection failed with a controlled exception.' );
		} finally {
			if ( $previous_flag === $flag_missing ) {
				delete_option( $flag_key );
			} else {
				update_option( $flag_key, $previous_flag, false );
			}
		}

		return array(
			'status' => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Allowlisted Event Stream event projected, followed, replayed idempotently and tombstoned.' : 'Event Stream Context Bank projection failed.',
			'fix_hint' => $pass ? '' : 'Check Event Bus persistence, Context Bank contract/ledger provisioning, feature flag and receipt pointer admission.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Event_Stream';
	return $list;
} );