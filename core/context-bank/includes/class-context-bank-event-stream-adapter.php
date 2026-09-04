<?php
/**
 * Project selected canonical Twin Event Stream events into Context Bank.
 *
 * Event Stream remains the ordered canonical owner. Context Bank stores an
 * encrypted business projection and a verified pointer only.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Event_Stream_Adapter', false ) ) {
	return;
}

final class BizCity_Context_Bank_Event_Stream_Adapter {

	const CONTRACT_ID = 'core.twin_core.context_bank_event';
	const FEATURE_FLAG = 'bizcity_context_bank_capture_enabled';

	private static $booted = false;

	/**
	 * @return array<int,string>
	 */
	public static function allowlist() {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1 — keep the reusable Event Stream projection allowlist explicit and reviewable.
		return array( 'note_pinned', 'twin_goal_opened', 'twin_goal_progressed', 'twin_goal_closed' );
	}

	public static function boot() {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1 — attach one synchronous projector to the canonical Event Stream hook.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'bizcity_twin_event_v2', array( __CLASS__, 'project' ), 20, 1 );
	}

	/**
	 * Project one persisted event after the canonical Event Stream insert.
	 *
	 * @param array<string,mixed> $event Persisted Event Stream envelope.
	 * @return array<string,mixed>
	 */
	public static function project( array $event ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1 — project only approved reusable events and keep capture disabled until a canary enables the flag.
		$event_type = sanitize_key( (string) ( $event['event_type'] ?? '' ) );
		if ( ! in_array( $event_type, self::allowlist(), true ) ) {
			return array( 'ok' => true, 'projected' => false, 'reason' => 'event_not_allowlisted' );
		}
		if ( ! self::capture_enabled() ) {
			return array( 'ok' => true, 'projected' => false, 'reason' => 'capture_disabled' );
		}
		$event_uuid = (string) ( $event['event_uuid'] ?? $event['event_id'] ?? '' );
		$blog_id = (int) ( $event['blog_id'] ?? ( $event['tenant']['blog_id'] ?? 0 ) );
		$current_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( $event_uuid === '' || $blog_id <= 0 || $blog_id !== $current_blog_id ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'event_tenant_scope_invalid' );
		}
		if ( ! self::load_runtime() || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'context_bank_runtime_unavailable' );
		}
		$table = BizCity_Context_Bank_Ledger::table();
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $table ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'context_bank_ledger_not_provisioned' );
		}
		$record_id = 'event_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $event_uuid );
		$existing = BizCity_Context_Bank_Ledger::instance()->find( array( 'record_id' => $record_id, 'source_contract_id' => self::CONTRACT_ID, 'blog_id' => $blog_id, 'limit' => 1 ) );
		if ( ! empty( $existing[0] ) ) {
			// [2026-09-01 Johnny Chu] PHASE-CB4.1 — compare the canonical Event Stream UUID through source_record_id; the filestore receipt has its own line UUID.
			if ( (string) ( $existing[0]['source_record_id'] ?? '' ) === $event_uuid ) {
				return array( 'ok' => true, 'projected' => true, 'replayed' => true, 'record_id' => $record_id );
			}
			return array( 'ok' => false, 'projected' => false, 'reason' => 'event_pointer_conflict' );
		}
		$payload = is_array( $event['payload'] ?? null ) ? $event['payload'] : array();
		$identity_uuid = (string) ( $payload['identity_uuid'] ?? $event['identity_uuid'] ?? '' );
		$record = array(
			'record_id' => $record_id,
			'event_uuid' => $event_uuid,
			'event_type' => $event_type,
			'event_source' => (string) ( $event['event_source'] ?? 'system' ),
			'trace_id' => (string) ( $event['trace_id'] ?? '' ),
			'parent_event_uuid' => (string) ( $event['parent_event_uuid'] ?? '' ),
			'blog_id' => $blog_id,
			'user_id' => (int) ( $event['user_id'] ?? ( $event['tenant']['user_id'] ?? 0 ) ),
			'conversation_id' => (int) ( $event['conversation_id'] ?? 0 ),
			'session_id' => (string) ( $event['session_id'] ?? '' ),
			'identity_uuid' => $identity_uuid,
			'event_occurred_at' => (string) ( $event['occurred_at'] ?? $event['created_at'] ?? '' ),
			'payload' => $payload,
		);
		$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::CONTRACT_ID, $record, 'upsert' );
		if ( ! is_array( $receipt ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'event_filestore_write_failed' );
		}
		$reference = array(
			'source_contract_id' => self::CONTRACT_ID,
			'record_id' => $record_id,
			'record_kind' => 'event',
			'event_uuid' => $event_uuid,
			'identity_uuid' => $identity_uuid,
			'user_id' => (int) ( $record['user_id'] ?? 0 ),
			'conversation_id' => (int) ( $record['conversation_id'] ?? 0 ),
			'entity_type' => $event_type,
			'entity_key' => $event_uuid,
			'scope_key' => $identity_uuid,
			'source_record_id' => $event_uuid,
			'trace_id' => (string) ( $event['trace_id'] ?? '' ),
			'provenance_ref' => 'twin-event:' . $event_uuid,
			'kg_status' => 'not_candidate',
			'receipt' => $receipt,
		);
		$admission = BizCity_Context_Bank_Ledger::instance()->record( $reference );
		if ( empty( $admission['ok'] ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'ledger_degraded', 'ledger_reason' => (string) ( $admission['reason'] ?? 'ledger_admission_failed' ) );
		}
		return array( 'ok' => true, 'projected' => true, 'replayed' => ! empty( $admission['replayed'] ), 'record_id' => $record_id, 'ledger_id' => (int) ( $admission['ledger_id'] ?? 0 ) );
	}

	/**
	 * Append a tombstone for one synthetic or retired Event Stream projection.
	 *
	 * @param string $event_uuid Canonical Event Stream UUID.
	 * @return array<string,mixed>
	 */
	public static function tombstone_projection( $event_uuid ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1 — remove only the derived projection through a receipt-bearing tombstone; retain canonical Event Stream history.
		$event_uuid = (string) $event_uuid;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( $event_uuid === '' || $blog_id <= 0 || ! self::load_runtime() ) {
			return array( 'ok' => false, 'reason' => 'event_projection_cleanup_unavailable' );
		}
		$record_id = 'event_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $event_uuid );
		$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::CONTRACT_ID, array( 'record_id' => $record_id, 'source_record_id' => $event_uuid ), 'delete' );
		if ( ! is_array( $receipt ) ) {
			return array( 'ok' => false, 'reason' => 'event_projection_tombstone_write_failed' );
		}
		$admission = BizCity_Context_Bank_Ledger::instance()->record( array(
			'source_contract_id' => self::CONTRACT_ID,
			'record_id' => $record_id,
			'record_kind' => 'event',
			'event_uuid' => $event_uuid,
			'source_record_id' => $event_uuid,
			'blog_id' => $blog_id,
			'operation' => 'delete',
			'lifecycle_status' => 'deleted',
			'kg_status' => 'not_candidate',
			'provenance_ref' => 'twin-event:' . $event_uuid,
			'receipt' => $receipt,
		) );
		return ! empty( $admission['ok'] ) ? array( 'ok' => true, 'record_id' => $record_id ) : array( 'ok' => false, 'reason' => (string) ( $admission['reason'] ?? 'event_projection_tombstone_admission_failed' ) );
	}

	private static function capture_enabled() {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1 — keep Context Bank Event Stream capture explicitly feature-gated.
		return function_exists( 'get_option' ) && (bool) get_option( self::FEATURE_FLAG, false );
	}

	private static function load_runtime() {
		// [2026-09-01 Johnny Chu] PHASE-CB4.1 — load the Context Bank runtime only at the event projection boundary.
		if ( class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return true;
		}
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 3 ) . '/';
		$bootstrap = rtrim( $root, '/\\' ) . '/core/context-bank/bootstrap.php';
		if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! is_file( $bootstrap ) || ! is_readable( $bootstrap ) ) {
			return false;
		}
		try {
			BizCity_Safe_Loader::require_file( $bootstrap, 'context_bank.event_stream_adapter' );
		} catch ( \Throwable $e ) {
			return false;
		}
		return class_exists( 'BizCity_Context_Bank_Ledger' );
	}
}