<?php
/**
 * Project CRM pipeline lifecycle events into the Context Bank pointer ledger.
 *
 * CRM opportunities and the pipeline deadline queue remain canonical. This
 * adapter stores a receipt-backed encrypted lifecycle record and admits only
 * a pointer/correlation projection; it never copies messages, notes or PII.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_CRM_Pipeline_Adapter', false ) ) {
	return;
}

final class BizCity_Context_Bank_CRM_Pipeline_Adapter {

	const CONTRACT_ID  = 'core.context_bank.crm_pipeline_lifecycle';
	const FEATURE_FLAG = 'bizcity_context_bank_pipeline_capture_enabled';

	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'bizcity_crm_event_crm_pipeline_lifecycle', array( __CLASS__, 'project' ), 20, 1 );
	}

	/**
	 * Project one pointer-safe pipeline lifecycle event.
	 *
	 * @param array<string,mixed> $event Enriched CRM event payload.
	 * @return array<string,mixed>
	 */
	public static function project( array $event ): array {
		if ( ! self::capture_enabled() ) {
			return array( 'ok' => true, 'projected' => false, 'reason' => 'capture_disabled' );
		}
		$event_uuid = (string) ( $event['event_uuid'] ?? '' );
		$blog_id    = (int) ( $event['blog_id'] ?? get_current_blog_id() );
		$run_id     = (int) ( $event['run_id'] ?? 0 );
		$contact_id = (int) ( $event['contact_id'] ?? 0 );
		$kind       = self::token( $event['pipeline_kind'] ?? '' );
		$stage      = self::text( $event['stage_key'] ?? $event['stage'] ?? '', 64 );
		$event_name = self::token( $event['event'] ?? '' );
		$occurred   = (string) ( $event['occurred_at'] ?? '' );

		if ( '' === $event_uuid || $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() || $run_id <= 0 || '' === $kind || '' === $event_name ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'pipeline_event_scope_invalid' );
		}
		if ( ! self::load_runtime() || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'context_bank_runtime_unavailable' );
		}
		$record_id = 'pipeline_run_' . $run_id . '_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $event_uuid );
		$ledger = BizCity_Context_Bank_Ledger::instance();
		$existing = $ledger->find( array(
			'blog_id' => $blog_id,
			'source_contract_id' => self::CONTRACT_ID,
			'record_id' => $record_id,
			'limit' => 1,
		) );
		if ( ! empty( $existing[0] ) ) {
			return array( 'ok' => true, 'projected' => true, 'replayed' => true, 'record_id' => $record_id );
		}

		$record = array(
			'record_id' => $record_id,
			'event_uuid' => $event_uuid,
			'blog_id' => $blog_id,
			'run_id' => $run_id,
			'contact_id' => max( 0, $contact_id ),
			'pipeline_kind' => $kind,
			'stage' => $stage,
			'event' => $event_name,
			'occurred_at' => $occurred,
		);
		$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::CONTRACT_ID, $record, 'upsert' );
		if ( ! is_array( $receipt ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'pipeline_filestore_write_failed' );
		}
		$admission = $ledger->record( array(
			'source_contract_id' => self::CONTRACT_ID,
			'record_id' => $record_id,
			'record_kind' => 'event',
			'event_uuid' => $event_uuid,
			'source_record_id' => $event_uuid,
			'blog_id' => $blog_id,
			'contact_id' => max( 0, $contact_id ),
			'entity_type' => 'pipeline_run',
			'entity_key' => (string) $run_id,
			'scope_key' => 'pipeline:' . $kind . ':' . $run_id,
			'provenance_ref' => 'crm-pipeline:' . $event_uuid,
			'kg_status' => 'not_candidate',
			'receipt' => $receipt,
		) );
		if ( empty( $admission['ok'] ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'ledger_degraded', 'ledger_reason' => (string) ( $admission['reason'] ?? 'ledger_admission_failed' ) );
		}
		return array( 'ok' => true, 'projected' => true, 'record_id' => $record_id, 'ledger_id' => (int) ( $admission['ledger_id'] ?? 0 ) );
	}

	private static function capture_enabled(): bool {
		return function_exists( 'get_option' ) && (bool) get_option( self::FEATURE_FLAG, false );
	}

	private static function load_runtime(): bool {
		if ( class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return true;
		}
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 3 ) . '/';
		$bootstrap = rtrim( $root, '/\\' ) . '/core/context-bank/bootstrap.php';
		if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! is_file( $bootstrap ) || ! is_readable( $bootstrap ) ) {
			return false;
		}
		try {
			BizCity_Safe_Loader::require_file( $bootstrap, 'context_bank.crm_pipeline_adapter' );
		} catch ( \Throwable $e ) {
			return false;
		}
		return class_exists( 'BizCity_Context_Bank_Ledger' );
	}

	private static function token( $value ): string {
		return sanitize_key( (string) $value );
	}

	private static function text( $value, int $limit ): string {
		return substr( sanitize_text_field( (string) $value ), 0, $limit );
	}
}
