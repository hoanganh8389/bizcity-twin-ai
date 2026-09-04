<?php
/**
 * Project CRM conversation archive receipts into the Context Bank ledger.
 *
 * The encrypted conversation archive remains canonical. This adapter stores
 * only a pointer and bounded correlation metadata in Context Bank.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Channel_Archive_Adapter', false ) ) {
	return;
}

final class BizCity_Context_Bank_Channel_Archive_Adapter {

	const CONTRACT_ID = 'core.channel_gateway.context_corpus';
	const FEATURE_FLAG = 'bizcity_context_bank_channel_capture_enabled';

	private static $booted = false;

	public static function boot() {
		// [2026-09-01 Johnny Chu] PHASE-CB4.2 — attach one archive receipt listener without changing the CRM archive owner.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'bizcity_channel_archive_written', array( __CLASS__, 'project' ), 20, 1 );
	}

	public static function project( $payload ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.2 — admit only verified Zone 1 archive pointers and never copy encrypted message content.
		if ( ! self::capture_enabled() || ! is_array( $payload ) || ! is_array( $payload['entry'] ?? null ) || ! is_array( $payload['receipt'] ?? null ) ) {
			return array( 'ok' => true, 'projected' => false, 'reason' => 'capture_disabled_or_shape_invalid' );
		}
		$entry = $payload['entry'];
		$receipt = $payload['receipt'];
		$channel = sanitize_key( (string) ( $entry['channel'] ?? '' ) );
		if ( ! in_array( $channel, array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'email', 'instagram', 'whatsapp' ), true ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'channel_zone_not_supported' );
		}
		// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — treat the legacy Messenger archive folder as a compatibility alias of canonical facebook, never as a new CRM channel.
		$policy_channel = 'messenger' === $channel ? 'facebook' : $channel;
		$account_key = (string) ( $entry['account_key'] ?? $receipt['account_key'] ?? '' );
		$peer_key = (string) ( $entry['peer_key'] ?? $receipt['peer_key'] ?? '' );
		$record_id = (string) ( $receipt['record_id'] ?? '' );
		$event_uuid = (string) ( $receipt['event_uuid'] ?? '' );
		if ( ! preg_match( '/^a_[a-f0-9]{64}$/i', $account_key ) || ! preg_match( '/^p_[a-f0-9]{64}$/i', $peer_key ) || $record_id === '' || $event_uuid === '' || (int) ( $entry['conversation_id'] ?? 0 ) <= 0 ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'channel_archive_identity_missing' );
		}
		// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — require the manifest registry itself before admitting a channel pointer; an absent registry is not an authorization signal.
		if ( ! class_exists( 'BizCity_Framework_Manifest_Registry' ) || ! is_array( BizCity_Framework_Manifest_Registry::channel( $policy_channel ) ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'channel_manifest_not_registered' );
		}
		if ( ! class_exists( 'BizCity_Channel_Conversation_Archive' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'channel_archive_adapter_dependency_missing' );
		}
		$verified = BizCity_Channel_Conversation_Archive::read_receipt( $receipt );
		if ( empty( $verified['ok'] ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => (string) ( $verified['reason'] ?? 'archive_pointer_verification_failed' ) );
		}
		if ( (string) ( $verified['operation'] ?? 'upsert' ) === 'delete' ) {
			$blog_id = (int) ( $receipt['blog_id'] ?? 0 );
			if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() ) {
				// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — tombstones retain the same physical-tenant boundary as upserts.
				return array( 'ok' => false, 'projected' => false, 'reason' => 'channel_archive_tenant_scope_invalid' );
			}
			$reference = array(
				'source_contract_id' => self::CONTRACT_ID,
				'record_id' => (string) ( $receipt['record_id'] ?? '' ),
				'record_kind' => 'event',
				'event_uuid' => (string) ( $receipt['event_uuid'] ?? '' ),
				'source_record_id' => (string) ( $receipt['event_uuid'] ?? '' ),
				'blog_id' => $blog_id,
				'identity_uuid' => $policy_channel . ':' . $account_key . ':' . $peer_key,
				'entity_type' => 'channel_account',
				'entity_key' => $policy_channel . ':' . $account_key,
				'secondary_type' => 'conversation',
				'secondary_key' => (string) ( $entry['conversation_id'] ?? '' ),
				'scope_key' => $peer_key,
				'conversation_id' => (int) ( $entry['conversation_id'] ?? 0 ),
				'operation' => 'delete',
				'lifecycle_status' => 'deleted',
				'kg_status' => 'not_candidate',
				'receipt' => $receipt,
			);
			$admission = BizCity_Context_Bank_Ledger::instance()->record( $reference );
			return ! empty( $admission['ok'] ) ? array( 'ok' => true, 'projected' => true, 'tombstone' => true, 'record_id' => (string) $receipt['record_id'] ) : array( 'ok' => false, 'projected' => false, 'reason' => (string) ( $admission['reason'] ?? 'channel_archive_tombstone_failed' ) );
		}
		$blog_id = (int) ( $receipt['blog_id'] ?? 0 );
		if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'channel_archive_tenant_scope_invalid' );
		}
		if ( ! self::load_runtime() ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'context_bank_runtime_unavailable' );
		}
		$existing = BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => self::CONTRACT_ID, 'record_id' => $record_id, 'blog_id' => $blog_id, 'limit' => 1 ) );
		if ( ! empty( $existing[0] ) ) {
			return (string) ( $existing[0]['source_record_id'] ?? '' ) === (string) ( $receipt['event_uuid'] ?? '' )
				? array( 'ok' => true, 'projected' => true, 'replayed' => true, 'record_id' => $record_id )
				: array( 'ok' => false, 'projected' => false, 'reason' => 'channel_archive_pointer_conflict' );
		}
		$reference = array(
			'source_contract_id' => self::CONTRACT_ID,
			'record_id' => $record_id,
			'record_kind' => 'event',
			'event_uuid' => $event_uuid,
			'source_record_id' => $event_uuid,
			'identity_uuid' => $policy_channel . ':' . $account_key . ':' . $peer_key,
			'entity_type' => 'channel_account',
			'entity_key' => $policy_channel . ':' . $account_key,
			'secondary_type' => 'conversation',
			'secondary_key' => (string) ( $entry['conversation_id'] ?? '' ),
			'scope_key' => (string) ( $entry['peer_key'] ?? '' ),
			'conversation_id' => (int) ( $entry['conversation_id'] ?? 0 ),
			'trace_id' => (string) ( $entry['trace_id'] ?? '' ),
			'provenance_ref' => 'crm-archive:' . $event_uuid,
			'kg_status' => 'not_candidate',
			'receipt' => $receipt,
		);
		$admission = BizCity_Context_Bank_Ledger::instance()->record( $reference );
		return ! empty( $admission['ok'] ) ? array( 'ok' => true, 'projected' => true, 'record_id' => $record_id, 'replayed' => ! empty( $admission['replayed'] ) ) : array( 'ok' => false, 'projected' => false, 'reason' => (string) ( $admission['reason'] ?? 'channel_archive_ledger_admission_failed' ) );
	}

	private static function capture_enabled() {
		// [2026-09-01 Johnny Chu] PHASE-CB4.2 — keep channel corpus projection disabled until account authorization and shard evidence pass.
		return function_exists( 'get_option' ) && (bool) get_option( self::FEATURE_FLAG, false );
	}

	private static function load_runtime() {
		// [2026-09-01 Johnny Chu] PHASE-CB4.2 — lazy-load the ledger on CRM requests without preloading Context Bank on unrelated frontend traffic.
		if ( class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return true;
		}
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 3 ) . '/';
		$bootstrap = rtrim( $root, '/\\' ) . '/core/context-bank/bootstrap.php';
		if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! is_file( $bootstrap ) || ! is_readable( $bootstrap ) ) {
			return false;
		}
		try {
			BizCity_Safe_Loader::require_file( $bootstrap, 'context_bank.channel_archive_adapter' );
		} catch ( \Throwable $e ) {
			return false;
		}
		return class_exists( 'BizCity_Context_Bank_Ledger' );
	}
}