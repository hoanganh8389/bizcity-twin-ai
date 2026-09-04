<?php
/**
 * Bounded Context Bank file-to-ledger reconciliation.
 *
 * One invocation owns one current-blog and contract batch. Checkpoints are
 * signed blog-local options and never contain business payloads.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Reconciler', false ) ) {
	return;
}

final class BizCity_Context_Bank_Reconciler {

	const CHECKPOINT_VERSION = 1;
	const CHECKPOINT_OPTION = 'bizcity_context_bank_reconcile_checkpoints';
	const DEFAULT_LIMIT = 50;
	const DEFAULT_MAX_MS = 500;

	/**
	 * Build a signed, tenant-bound checkpoint.
	 *
	 * @param string $run_id Stable reconciliation run identifier.
	 * @param string $contract_id Registered filestore contract.
	 * @param string $relative_file Bounded JSONL file name.
	 * @param int    $byte_offset Next unread byte offset.
	 * @param string $phase Reconciliation phase.
	 * @return array<string,mixed>
	 */
	public static function make_checkpoint( $run_id, $contract_id, $relative_file, $byte_offset = 0, $phase = 'file_to_ledger' ) {
		// [2026-09-01 Johnny Chu] CB3.4 — bind resume state to one tenant, contract catalog and file phase.
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$contract = self::contract( $contract_id );
		$payload = array(
			'v' => self::CHECKPOINT_VERSION,
			'run_id' => sanitize_key( (string) $run_id ),
			'blog_id' => $blog_id,
			'contract_id' => (string) $contract_id,
			'contract_hash' => self::contract_hash( $contract_id, $contract ),
			'relative_file' => (string) $relative_file,
			'byte_offset' => max( 0, (int) $byte_offset ),
			'phase' => sanitize_key( (string) $phase ),
			'updated_at' => gmdate( 'c' ),
		);
		$payload['signature'] = self::sign( $payload );
		return $payload;
	}

	/**
	 * Validate checkpoint scope and current contract version.
	 *
	 * @param array<string,mixed> $checkpoint
	 * @param string $contract_id
	 * @param string $relative_file
	 * @param string $phase
	 * @return array<string,mixed>
	 */
	public static function validate_checkpoint( array $checkpoint, $contract_id, $relative_file, $phase = 'file_to_ledger' ) {
		// [2026-09-01 Johnny Chu] CB3.4 — reject cross-tenant, tampered and stale catalog resume state before file access.
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$contract = self::contract( $contract_id );
		if ( ! $contract || $blog_id <= 0 ) {
			return array( 'ok' => false, 'reason' => 'tenant_or_contract_missing' );
		}
		$signature = (string) ( $checkpoint['signature'] ?? '' );
		$unsigned = $checkpoint;
		unset( $unsigned['signature'] );
		if ( $signature === '' || ! hash_equals( self::sign( $unsigned ), $signature ) ) {
			return array( 'ok' => false, 'reason' => 'checkpoint_signature_invalid' );
		}
		if ( (int) ( $checkpoint['v'] ?? 0 ) !== self::CHECKPOINT_VERSION
			|| (string) ( $checkpoint['run_id'] ?? '' ) === ''
			|| (int) ( $checkpoint['blog_id'] ?? 0 ) !== $blog_id
			|| (string) ( $checkpoint['contract_id'] ?? '' ) !== (string) $contract_id
			|| (string) ( $checkpoint['contract_hash'] ?? '' ) !== self::contract_hash( $contract_id, $contract )
			|| (string) ( $checkpoint['relative_file'] ?? '' ) !== (string) $relative_file
			|| (string) ( $checkpoint['phase'] ?? '' ) !== sanitize_key( (string) $phase )
			|| (int) ( $checkpoint['byte_offset'] ?? -1 ) < 0 ) {
			return array( 'ok' => false, 'reason' => 'checkpoint_scope_or_version_mismatch' );
		}
		return array( 'ok' => true, 'checkpoint' => $checkpoint );
	}

	/**
	 * Reconcile one bounded file page into the pointer-only ledger.
	 *
	 * @param string $run_id Stable run identifier.
	 * @param string $contract_id Registered filestore contract.
	 * @param string $relative_file JSONL file name.
	 * @param array<string,mixed> $checkpoint Prior checkpoint or empty.
	 * @param int $limit Maximum rows to inspect.
	 * @param int $max_ms Maximum file-read budget.
	 * @return array<string,mixed>
	 */
	public static function run_batch( $run_id, $contract_id, $relative_file, array $checkpoint = array(), $limit = self::DEFAULT_LIMIT, $max_ms = self::DEFAULT_MAX_MS ) {
		// [2026-09-01 Johnny Chu] CB3.4 — advance only after every valid row in the bounded page is admitted to the tenant ledger.
		// [2026-09-01 Johnny Chu] CB3.4 — refuse malformed run/file scope before creating blog-local resume state.
		$run_id = sanitize_key( (string) $run_id );
		$relative_file = (string) $relative_file;
		self::cron_note_event( 'reconcile_batch_started', array( 'contract_id' => sanitize_key( (string) $contract_id ), 'run_id' => $run_id ) );
		if ( $run_id === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}\.jsonl$/', $relative_file ) ) {
			self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => 'reconcile_scope_invalid' ) );
			return array( 'ok' => false, 'reason' => 'reconcile_scope_invalid', 'processed' => 0, 'next_checkpoint' => array() );
		}
		$contract = self::contract( $contract_id );
		if ( ! $contract || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => 'reconcile_dependency_missing' ) );
			return array( 'ok' => false, 'reason' => 'reconcile_dependency_missing', 'processed' => 0, 'next_checkpoint' => array() );
		}
		if ( empty( $checkpoint ) ) {
			$checkpoint = self::make_checkpoint( $run_id, $contract_id, $relative_file, 0, 'file_to_ledger' );
		} else {
			$valid = self::validate_checkpoint( $checkpoint, $contract_id, $relative_file, 'file_to_ledger' );
			if ( empty( $valid['ok'] ) ) {
				self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => sanitize_key( (string) ( $valid['reason'] ?? 'checkpoint_invalid' ) ) ) );
				return array( 'ok' => false, 'reason' => (string) ( $valid['reason'] ?? 'checkpoint_invalid' ), 'processed' => 0, 'next_checkpoint' => $checkpoint );
			}
		}
		try {
			$page = BizCity_Business_JSONL_File_Store::read_page( $contract_id, $relative_file, (int) $checkpoint['byte_offset'], $limit, $max_ms );
		} catch ( \Throwable $e ) {
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB3.4 — convert reader exceptions into a bounded failed batch without exposing path or payload details.
			self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => 'reconcile_reader_exception' ) );
			return array( 'ok' => false, 'reason' => 'reconcile_reader_exception', 'processed' => 0, 'next_checkpoint' => $checkpoint );
		}
		if ( empty( $page['ok'] ) ) {
			self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => sanitize_key( (string) ( $page['reason'] ?? 'page_read_failed' ) ) ) );
			return array( 'ok' => false, 'reason' => (string) ( $page['reason'] ?? 'page_read_failed' ), 'processed' => 0, 'next_checkpoint' => $checkpoint );
		}
		$processed = 0;
		$invalid = 0;
		foreach ( $page['rows'] as $row ) {
			if ( empty( $row['valid'] ) ) {
				// [2026-09-01 Johnny Chu] CB3.4 — do not advance past malformed source rows; repair/quarantine must own that decision.
				$invalid++;
				self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => sanitize_key( (string) ( $row['reason'] ?? 'source_row_invalid' ) ), 'processed' => $processed ) );
				return array( 'ok' => false, 'reason' => (string) ( $row['reason'] ?? 'source_row_invalid' ), 'processed' => $processed, 'invalid' => $invalid, 'next_checkpoint' => $checkpoint );
			}
			$record = is_array( $row['record'] ?? null ) ? $row['record'] : array();
			$reference = self::reference_from_record( $record, $row['receipt'] );
			try {
				$result = BizCity_Context_Bank_Ledger::instance()->record( $reference );
			} catch ( \Throwable $e ) {
				// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB3.4 — preserve the prior checkpoint when ledger admission throws.
				self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => 'reconcile_ledger_exception', 'processed' => $processed ) );
				return array( 'ok' => false, 'reason' => 'reconcile_ledger_exception', 'processed' => $processed, 'invalid' => $invalid, 'next_checkpoint' => $checkpoint );
			}
			if ( empty( $result['ok'] ) ) {
				self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => sanitize_key( (string) ( $result['reason'] ?? 'ledger_admission_failed' ) ), 'processed' => $processed ) );
				return array( 'ok' => false, 'reason' => (string) ( $result['reason'] ?? 'ledger_admission_failed' ), 'processed' => $processed, 'invalid' => $invalid, 'next_checkpoint' => $checkpoint );
			}
			$processed++;
		}
		$next_offset = (int) ( $page['next_offset'] ?? -1 );
		if ( $next_offset < (int) $checkpoint['byte_offset'] ) {
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB3.4 — refuse a regressed source cursor instead of overwriting resumable state.
			self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => 'source_cursor_regressed', 'processed' => $processed ) );
			return array( 'ok' => false, 'reason' => 'source_cursor_regressed', 'processed' => $processed, 'invalid' => $invalid, 'next_checkpoint' => $checkpoint );
		}
		$next = self::make_checkpoint( $run_id, $contract_id, $relative_file, $next_offset, 'file_to_ledger' );
		if ( ! self::save_checkpoint( $run_id, $next ) ) {
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB3.4 — keep the prior cursor resumable when checkpoint persistence fails after ledger admission.
			self::cron_note_event( 'reconcile_batch_failed', array( 'reason' => 'reconcile_checkpoint_persist_failed', 'processed' => $processed ) );
			return array( 'ok' => false, 'reason' => 'reconcile_checkpoint_persist_failed', 'processed' => $processed, 'invalid' => $invalid, 'next_checkpoint' => $checkpoint );
		}
		self::cron_note_event( 'reconcile_checkpoint_advanced', array( 'processed' => $processed, 'byte_offset' => $next_offset, 'eof' => ! empty( $page['eof'] ) ) );
		self::cron_note_event( 'reconcile_batch_completed', array( 'processed' => $processed, 'invalid' => $invalid, 'eof' => ! empty( $page['eof'] ) ) );
		return array( 'ok' => true, 'processed' => $processed, 'invalid' => $invalid, 'inspected' => (int) ( $page['inspected'] ?? count( $page['rows'] ) ), 'eof' => ! empty( $page['eof'] ), 'next_checkpoint' => $next );
	}

	private static function cron_note_event( $name, array $data = array() ) {
		// [2026-09-02 01:35 PM Johnny Chu - Chu Hoàng Anh] R-CRON-META — attach bounded reconcile lifecycle evidence when a parent cron run exists.
		if ( class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			BizCity_Cron_Manager::instance()->note_event( (string) $name, $data );
		}
	}

	/**
	 * Reconcile one existing ledger pointer against its canonical file.
	 *
	 * @param array<string,mixed> $pointer Ledger pointer metadata.
	 * @param bool                $quarantine Whether admin repair may mark a failed pointer.
	 * @return array<string,mixed>
	 */
	public static function reconcile_pointer( array $pointer, $quarantine = true ) {
		// [2026-09-01 Johnny Chu] PHASE-CB3.4 — reverse reconciliation keeps verified pointers and quarantines drift without deleting canonical files.
		if ( ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'action' => 'deferred', 'reason' => 'ledger_unavailable' );
		}
		$verified = BizCity_Context_Bank_Ledger::instance()->verify_pointer( $pointer );
		if ( ! empty( $verified['ok'] ) ) {
			return array( 'ok' => true, 'action' => 'keep', 'verified' => true, 'operation' => (string) ( $verified['operation'] ?? 'upsert' ) );
		}
		$reason = sanitize_key( (string) ( $verified['reason'] ?? 'pointer_verification_failed' ) );
		if ( ! $quarantine || ! class_exists( 'BizCity_Context_Bank_Access' ) || ! BizCity_Context_Bank_Access::is_admin_request() ) {
			return array( 'ok' => false, 'action' => 'quarantine_required', 'reason' => $reason );
		}
		$quarantined = BizCity_Context_Bank_Ledger::instance()->quarantine_pointer( $pointer, $reason );
		if ( empty( $quarantined['ok'] ) ) {
			return array( 'ok' => false, 'action' => 'quarantine_failed', 'reason' => (string) ( $quarantined['reason'] ?? 'quarantine_failed' ) );
		}
		return array( 'ok' => true, 'action' => 'quarantined', 'reason' => $reason );
	}

	public static function load_checkpoint( $run_id ) {
		// [2026-09-01 Johnny Chu] CB3.4 — keep resumable state blog-local through WordPress option scope.
		$all = get_option( self::CHECKPOINT_OPTION, array() );
		$key = sanitize_key( (string) $run_id );
		return is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
	}

	public static function clear_checkpoint( $run_id ) {
		$all = get_option( self::CHECKPOINT_OPTION, array() );
		$key = sanitize_key( (string) $run_id );
		if ( ! is_array( $all ) || ! isset( $all[ $key ] ) ) {
			return false;
		}
		unset( $all[ $key ] );
		return update_option( self::CHECKPOINT_OPTION, $all, false );
	}

	private static function save_checkpoint( $run_id, array $checkpoint ) {
		$all = get_option( self::CHECKPOINT_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ sanitize_key( (string) $run_id ) ] = $checkpoint;
		try {
			$updated = update_option( self::CHECKPOINT_OPTION, $all, false );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( $updated ) {
			return true;
		}
		// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB3.4 — treat an idempotent option write as persisted while surfacing a genuine storage failure.
		try {
			$stored = get_option( self::CHECKPOINT_OPTION, array() );
		} catch ( \Throwable $e ) {
			return false;
		}
		return is_array( $stored ) && isset( $stored[ sanitize_key( (string) $run_id ) ] ) && $stored[ sanitize_key( (string) $run_id ) ] === $checkpoint;
	}

	private static function reference_from_record( array $record, array $receipt ) {
		$allowed = array( 'record_kind', 'memory_class', 'parent_record_id', 'root_record_id', 'identity_uuid', 'user_id', 'contact_id', 'conversation_id', 'entity_type', 'entity_key', 'secondary_type', 'secondary_key', 'scope_key', 'case_id', 'goal_id', 'notebook_id', 'source_record_id', 'trace_id', 'valid_from', 'valid_to', 'rollup_window', 'rollup_version', 'kg_status', 'kg_source_id', 'kg_passage_id', 'provenance_ref', 'idempotency_key' );
		$reference = array( 'source_contract_id' => (string) $receipt['contract_id'], 'record_id' => (string) $receipt['record_id'], 'receipt' => $receipt );
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $record ) ) {
				$reference[ $field ] = $record[ $field ];
			}
		}
		if ( (string) $receipt['operation'] === 'delete' ) {
			$reference['operation'] = 'delete';
			$reference['lifecycle_status'] = 'deleted';
		}
		return $reference;
	}

	private static function contract( $contract_id ) {
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) ) {
			return null;
		}
		$contract = BizCity_File_Contract_Registry::get( $contract_id );
		return is_array( $contract ) ? $contract : null;
	}

	private static function contract_hash( $contract_id, $contract ) {
		return hash( 'sha256', (string) $contract_id . '|' . wp_json_encode( is_array( $contract ) ? array( 'schema_version' => (string) ( $contract['schema_version'] ?? '' ), 'retention_days' => (int) ( $contract['retention_days'] ?? 0 ), 'status' => (string) ( $contract['status'] ?? '' ) ) : array() ) );
	}

	private static function sign( array $payload ) {
		if ( ! class_exists( 'BizCity_Codec' ) || ! function_exists( 'wp_salt' ) ) {
			return '';
		}
		return BizCity_Codec::hmac_sha256( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ), wp_salt( 'auth' ) . '|context-bank-reconcile-checkpoint', false );
	}
}