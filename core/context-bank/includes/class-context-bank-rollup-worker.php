<?php
/**
 * Resumable Context Bank rollup worker.
 *
 * Lease/checkpoint state is tenant-scoped SQL metadata. Rollup payloads remain
 * encrypted business records and are admitted through the pointer ledger.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Rollup_Worker', false ) ) {
	return;
}

final class BizCity_Context_Bank_Rollup_Worker {

	const TABLE_BASE = 'bizcity_context_bank_rollup_state';
	const MODULE_ID = 'core.context-bank';
	const DB_VERSION = '1.3.0';
	const DB_VERSION_OPTION = 'bizcity_context_bank_schema_version';
	const ROLLUP_CONTRACT_ID = 'core.context_bank.rollup';
	const DEFAULT_BATCH_SIZE = 100;
	const DEFAULT_LEASE_SECONDS = 300;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	public static function ensure_schema() {
		// [2026-09-02 Johnny Chu] PHASE-CB5.1 — provision lease/checkpoint metadata only through the central additive schema owner.
		if ( ! class_exists( 'BizCity_Diagnostics_Auto_Create' ) || ! method_exists( 'BizCity_Diagnostics_Auto_Create', 'run' ) ) {
			return array( 'ok' => false, 'reason' => 'diagnostics_auto_create_unavailable' );
		}
		$result = BizCity_Diagnostics_Auto_Create::run( self::TABLE_BASE );
		if ( is_array( $result ) && ! empty( $result['ok'] ) && function_exists( 'update_option' ) ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
		return is_array( $result ) ? $result : array( 'ok' => false, 'reason' => 'provisioning_unknown' );
	}

	/**
	 * Acquire one tenant/rollup/dimension lease.
	 *
	 * @param string $rollup_id Registered rollup ID.
	 * @param string $dimension_key Canonical dimension key.
	 * @param int    $lease_seconds Lease duration.
	 * @return array<string,mixed>
	 */
	public static function acquire_lease( $rollup_id, $dimension_key, $lease_seconds = self::DEFAULT_LEASE_SECONDS ) {
		// [2026-09-02 Johnny Chu] PHASE-CB5.1 — claim only one tenant-scoped dimension at a time and never claim work in diagnostics CLI.
		if ( self::diagnostics_blocked() ) {
			return array( 'ok' => false, 'reason' => 'diagnostics_cli_isolated' );
		}
		global $wpdb;
		$blog_id = (int) get_current_blog_id();
		$rollup_id = sanitize_key( (string) $rollup_id );
		$dimension_key = sanitize_text_field( (string) $dimension_key );
		$lease_seconds = max( 30, min( 3600, (int) $lease_seconds ) );
		if ( $blog_id <= 0 || $rollup_id === '' || $dimension_key === '' ) {
			return array( 'ok' => false, 'reason' => 'lease_identity_invalid' );
		}
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( self::table() ) ) {
			return array( 'ok' => false, 'reason' => 'rollup_state_not_provisioned' );
		}
		$token = hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );
		$sql = 'UPDATE ' . self::table() . ' SET lock_token = %s, lock_expires_at = %s, updated_at = %s WHERE blog_id = %d AND rollup_id = %s AND dimension_key = %s AND (lock_token = %s OR lock_expires_at IS NULL OR lock_expires_at < UTC_TIMESTAMP())';
		$updated = $wpdb->query( $wpdb->prepare( $sql, $token, $expires, gmdate( 'Y-m-d H:i:s' ), $blog_id, $rollup_id, $dimension_key, '' ) );
		if ( 1 === (int) $updated ) {
			return array( 'ok' => true, 'token' => $token, 'expires_at' => $expires, 'blog_id' => $blog_id, 'rollup_id' => $rollup_id, 'dimension_key' => $dimension_key );
		}
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (blog_id, rollup_id, dimension_key, lock_token, lock_expires_at) VALUES (%d, %s, %s, %s, %s)', $blog_id, $rollup_id, $dimension_key, $token, $expires ) );
		if ( false !== $inserted ) {
			return array( 'ok' => true, 'token' => $token, 'expires_at' => $expires, 'blog_id' => $blog_id, 'rollup_id' => $rollup_id, 'dimension_key' => $dimension_key );
		}
		$updated = $wpdb->query( $wpdb->prepare( $sql, $token, $expires, gmdate( 'Y-m-d H:i:s' ), $blog_id, $rollup_id, $dimension_key, '' ) );
		return 1 === (int) $updated
			? array( 'ok' => true, 'token' => $token, 'expires_at' => $expires, 'blog_id' => $blog_id, 'rollup_id' => $rollup_id, 'dimension_key' => $dimension_key )
			: array( 'ok' => false, 'reason' => 'rollup_lease_busy' );
	}

	/**
	 * Release a lease owned by this worker.
	 *
	 * @param string $rollup_id Registered rollup ID.
	 * @param string $dimension_key Canonical dimension key.
	 * @param string $token Lease token.
	 * @return array<string,mixed>
	 */
	public static function release_lease( $rollup_id, $dimension_key, $token ) {
		// [2026-09-02 Johnny Chu] PHASE-CB5.1 — release only the exact lease token so a late worker cannot unlock a newer owner.
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET lock_token = %s, lock_expires_at = NULL, updated_at = %s WHERE blog_id = %d AND rollup_id = %s AND dimension_key = %s AND lock_token = %s', '', gmdate( 'Y-m-d H:i:s' ), (int) get_current_blog_id(), sanitize_key( (string) $rollup_id ), sanitize_text_field( (string) $dimension_key ), (string) $token ) );
		return false === $result ? array( 'ok' => false, 'reason' => 'rollup_lease_release_failed' ) : array( 'ok' => true, 'released' => (int) $result );
	}

	/**
	 * Read the last successful checkpoint.
	 *
	 * @param string $rollup_id Registered rollup ID.
	 * @param string $dimension_key Canonical dimension key.
	 * @return array<string,mixed>
	 */
	public static function checkpoint( $rollup_id, $dimension_key ) {
		// [2026-09-02 Johnny Chu] PHASE-CB5.1 — expose resumable metadata without returning any rollup payload.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT checkpoint_record_id, checkpoint_occurred_at, processed_count, last_output_hash, last_error, dirty_since, dirty_record_id, superseded_record_id FROM ' . self::table() . ' WHERE blog_id = %d AND rollup_id = %s AND dimension_key = %s LIMIT 1', (int) get_current_blog_id(), sanitize_key( (string) $rollup_id ), sanitize_text_field( (string) $dimension_key ) ), ARRAY_A );
		return is_array( $row ) ? $row : array( 'checkpoint_record_id' => '', 'checkpoint_occurred_at' => '', 'processed_count' => 0, 'last_output_hash' => '', 'last_error' => '', 'dirty_since' => '', 'dirty_record_id' => '', 'superseded_record_id' => '' );
	}

	public static function mark_dirty( $rollup_id, $dimension_key, $occurred_at, $record_id = '', $superseded_record_id = '' ) {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB5.1 — reopen one tenant rollup dimension from an explicit late event and preserve the superseded derived state reference.
		global $wpdb;
		if ( self::diagnostics_blocked() ) {
			return array( 'ok' => false, 'reason' => 'diagnostics_cli_isolated' );
		}
		$blog_id = (int) get_current_blog_id();
		$rollup_id = sanitize_key( (string) $rollup_id );
		$dimension_key = sanitize_text_field( (string) $dimension_key );
		$occurred_at = self::mysql_datetime( $occurred_at );
		$record_id = sanitize_text_field( (string) $record_id );
		$superseded_record_id = sanitize_text_field( (string) $superseded_record_id );
		if ( $blog_id <= 0 || $rollup_id === '' || $dimension_key === '' || $occurred_at === '' ) {
			return array( 'ok' => false, 'reason' => 'rollup_dirty_identity_invalid' );
		}
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET dirty_record_id = IF(dirty_since IS NULL OR dirty_since > %s, %s, dirty_record_id), dirty_since = IF(dirty_since IS NULL OR dirty_since > %s, %s, dirty_since), superseded_record_id = %s, last_error = %s, updated_at = %s WHERE blog_id = %d AND rollup_id = %s AND dimension_key = %s', $occurred_at, $record_id, $occurred_at, $occurred_at, $superseded_record_id, 'rollup_dirty', gmdate( 'Y-m-d H:i:s' ), $blog_id, $rollup_id, $dimension_key ) );
		if ( false === $updated ) {
			return array( 'ok' => false, 'reason' => 'rollup_dirty_update_failed' );
		}
		if ( 0 === (int) $updated ) {
			return array( 'ok' => false, 'reason' => 'rollup_dimension_not_found' );
		}
		return array( 'ok' => true, 'dirty_since' => $occurred_at, 'record_id' => $record_id, 'superseded_record_id' => $superseded_record_id );
	}

	/**
	 * Process one bounded rollup batch.
	 *
	 * @param string              $rollup_id Registered rollup ID.
	 * @param string              $dimension_key Canonical dimension key.
	 * @param array<string,mixed> $filters Optional typed ledger filters.
	 * @return array<string,mixed>
	 */
	public static function process( $rollup_id, $dimension_key, array $filters = array() ) {
		// [2026-09-02 Johnny Chu] PHASE-CB5.1 — defend the worker entry before DB mutation, file write or downstream promotion.
		if ( self::diagnostics_blocked() ) {
			return array( 'ok' => false, 'processed' => false, 'reason' => 'diagnostics_cli_isolated' );
		}
		if ( ! self::capture_enabled() ) {
			self::cron_note_event( 'rollup_skipped', array( 'rollup_id' => sanitize_key( (string) $rollup_id ), 'reason' => 'rollups_disabled' ) );
			return array( 'ok' => true, 'processed' => false, 'reason' => 'rollups_disabled' );
		}
		$rollup_id = sanitize_key( (string) $rollup_id );
		$dimension_key = sanitize_text_field( (string) $dimension_key );
		if ( ! class_exists( 'BizCity_Context_Bank_Rollup_Registry' ) || ! is_array( BizCity_Context_Bank_Rollup_Registry::get( $rollup_id ) ) ) {
			return array( 'ok' => false, 'processed' => false, 'reason' => 'rollup_not_registered' );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Ledger' ) || ! class_exists( 'BizCity_Context_Bank_Rollup_Engine' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return array( 'ok' => false, 'processed' => false, 'reason' => 'rollup_worker_dependency_missing' );
		}
		$dimension_validation = self::validate_dimension_filters( $rollup_id, $filters );
		if ( empty( $dimension_validation['ok'] ) ) {
			return array( 'ok' => false, 'processed' => false, 'reason' => (string) ( $dimension_validation['reason'] ?? 'rollup_dimension_filter_invalid' ) );
		}
		$lease = self::acquire_lease( $rollup_id, $dimension_key );
		if ( empty( $lease['ok'] ) ) {
			self::cron_note_event( 'rollup_lease_failed', array( 'rollup_id' => $rollup_id, 'reason' => (string) ( $lease['reason'] ?? 'rollup_lease_busy' ) ) );
			return array( 'ok' => false, 'processed' => false, 'reason' => (string) ( $lease['reason'] ?? 'rollup_lease_busy' ) );
		}
		self::cron_note_event( 'rollup_batch_started', array( 'rollup_id' => $rollup_id, 'reopened' => false ) );
		try {
			$checkpoint = self::checkpoint( $rollup_id, $dimension_key );
			$filters['record_kind'] = isset( $filters['record_kind'] ) ? $filters['record_kind'] : 'event';
			$filters['limit'] = max( 1, min( self::DEFAULT_BATCH_SIZE, (int) ( $filters['limit'] ?? self::DEFAULT_BATCH_SIZE ) ) );
			$filters['order'] = 'asc';
			$is_rebuild = (string) ( $checkpoint['dirty_since'] ?? '' ) !== '';
			if ( ! $is_rebuild && (string) ( $checkpoint['checkpoint_occurred_at'] ?? '' ) !== '' ) {
				$filters['after_occurred_at'] = (string) $checkpoint['checkpoint_occurred_at'];
				$filters['after_record_id'] = (string) ( $checkpoint['checkpoint_record_id'] ?? '' );
			}
			$result = BizCity_Context_Bank_Ledger::instance()->query( $filters );
			if ( empty( $result['ok'] ) ) {
				self::mark_error( $lease, (string) ( $result['reason'] ?? 'ledger_query_failed' ) );
				return array( 'ok' => false, 'processed' => false, 'reason' => 'rollup_ledger_query_failed' );
			}
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5.1 — rebuild dirty dimensions from canonical evidence instead of reapplying the superseded checkpoint cursor.
			$records = self::after_checkpoint( (array) ( $result['rows'] ?? array() ), $checkpoint, $is_rebuild );
			if ( empty( $records ) ) {
				self::release_lease( $rollup_id, $dimension_key, (string) $lease['token'] );
				self::cron_note_event( 'rollup_checkpoint_current', array( 'rollup_id' => $rollup_id ) );
				return array( 'ok' => true, 'processed' => false, 'reason' => 'rollup_checkpoint_current', 'checkpoint' => $checkpoint );
			}
			$reduced = BizCity_Context_Bank_Rollup_Engine::reduce( $rollup_id, $records, array(
				'dimension_key' => $dimension_key,
				(string) $filters['entity_type'] => (string) $filters['entity_key'],
				(string) ( $filters['secondary_type'] ?? '' ) => (string) ( $filters['secondary_key'] ?? '' ),
			) );
			if ( empty( $reduced['ok'] ) || ! is_array( $reduced['result'] ?? null ) ) {
				self::mark_error( $lease, 'rollup_reduce_failed' );
				return array( 'ok' => false, 'processed' => false, 'reason' => 'rollup_reduce_failed' );
			}
			$output = $reduced['result'];
			$state_error = sanitize_key( (string) ( $output['state']['status'] ?? '' ) );
			if ( in_array( $state_error, array( 'invalid_quantity', 'identity_conflict', 'product_conflict', 'warehouse_conflict' ), true ) ) {
				// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — refuse checkpoint advancement for invalid or cross-dimension derived state.
				self::mark_error( $lease, $state_error );
				self::cron_note_event( 'rollup_failed', array( 'rollup_id' => $rollup_id, 'reason' => $state_error ) );
				return array( 'ok' => false, 'processed' => false, 'reason' => $state_error, 'error_buckets' => (array) ( $output['state']['error_buckets'] ?? array() ) );
			}
			$record_id = self::output_record_id( $rollup_id, $dimension_key, (string) $output['output_hash'] );
			$superseded_record_id = $is_rebuild ? (string) ( $checkpoint['superseded_record_id'] ?? '' ) : '';
			if ( $superseded_record_id === '' && $is_rebuild && (string) ( $checkpoint['last_output_hash'] ?? '' ) !== '' ) {
				$superseded_record_id = self::output_record_id( $rollup_id, $dimension_key, (string) $checkpoint['last_output_hash'] );
			}
			$last_record = end( $records );
			$rollup_record = array( 'record_id' => $record_id, 'rollup_id' => $rollup_id, 'dimension_key' => $dimension_key, 'summary' => $output['state'], 'state' => $output['state'], 'evidence_refs' => $output['evidence_refs'], 'input_count' => (int) $output['input_count'], 'rollup_version' => (string) $output['rollup_version'], 'output_hash' => (string) $output['output_hash'], 'superseded_record_id' => $superseded_record_id, 'occurred_at' => (string) ( $last_record['occurred_at'] ?? gmdate( 'c' ) ) );
			$existing_rollup = BizCity_Context_Bank_Ledger::instance()->find( array( 'blog_id' => (int) get_current_blog_id(), 'source_contract_id' => self::ROLLUP_CONTRACT_ID, 'record_id' => $record_id, 'lifecycle_status' => 'active', 'limit' => 1 ) );
			$existing_pointer = is_array( $existing_rollup ) && ! empty( $existing_rollup[0] ) && is_array( $existing_rollup[0] ) ? $existing_rollup[0] : array();
			$receipt = false;
			$admission = array();
			if ( ! empty( $existing_pointer ) && ! empty( BizCity_Context_Bank_Ledger::instance()->verify_pointer( $existing_pointer )['ok'] ) ) {
				// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB5.1 — reuse durable output already admitted before an interrupted checkpoint instead of appending a second receipt.
				$admission = array( 'ok' => true, 'replayed' => true );
				self::cron_note_event( 'rollup_output_reused', array( 'rollup_id' => $rollup_id, 'replayed' => true ) );
			} else {
				$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::ROLLUP_CONTRACT_ID, $rollup_record, 'upsert' );
				if ( ! is_array( $receipt ) ) {
					self::mark_error( $lease, 'rollup_filestore_write_failed' );
					self::cron_note_event( 'rollup_failed', array( 'rollup_id' => $rollup_id, 'reason' => 'rollup_filestore_write_failed' ) );
					return array( 'ok' => false, 'processed' => false, 'reason' => 'rollup_filestore_write_failed' );
				}
				$admission = BizCity_Context_Bank_Ledger::instance()->record( array( 'source_contract_id' => self::ROLLUP_CONTRACT_ID, 'record_id' => $record_id, 'record_kind' => 'rollup', 'event_uuid' => (string) $receipt['event_uuid'], 'source_record_id' => (string) $output['output_hash'], 'parent_record_id' => $superseded_record_id, 'entity_type' => $rollup_id, 'entity_key' => $dimension_key, 'scope_key' => $dimension_key, 'rollup_window' => (string) ( $output['rollup_id'] ?? $rollup_id ), 'rollup_version' => (string) ( $output['rollup_version'] ?? '' ), 'provenance_ref' => 'context-rollup:' . $record_id, 'kg_status' => 'not_candidate', 'receipt' => $receipt ) );
			}
			if ( empty( $admission['ok'] ) ) {
				self::mark_error( $lease, 'rollup_ledger_admission_failed' );
				self::cron_note_event( 'rollup_failed', array( 'rollup_id' => $rollup_id, 'reason' => 'rollup_ledger_admission_failed' ) );
				return array( 'ok' => false, 'processed' => false, 'reason' => 'rollup_ledger_admission_failed' );
			}
			$checkpoint_allowed = apply_filters( 'bizcity_context_bank_rollup_before_checkpoint', true, $rollup_id, $dimension_key, $record_id, (string) $output['output_hash'] );
			if ( ! $checkpoint_allowed ) {
				self::mark_error( $lease, 'rollup_checkpoint_deferred' );
				self::cron_note_event( 'rollup_checkpoint_deferred', array( 'rollup_id' => $rollup_id, 'reopened' => $is_rebuild, 'output_admitted' => true ) );
				return array( 'ok' => false, 'processed' => false, 'interrupted' => true, 'reason' => 'rollup_checkpoint_deferred', 'record_id' => $record_id, 'output_hash' => (string) $output['output_hash'], 'superseded_record_id' => $superseded_record_id );
			}
			$last = end( $records );
			$checkpoint_ok = self::persist_checkpoint( $lease, (string) ( $last['record_id'] ?? '' ), (string) ( $last['occurred_at'] ?? '' ), count( $records ), (string) $output['output_hash'] );
			if ( empty( $checkpoint_ok['ok'] ) ) {
				self::cron_note_event( 'rollup_failed', array( 'rollup_id' => $rollup_id, 'reason' => 'rollup_checkpoint_persist_failed' ) );
				return array( 'ok' => false, 'processed' => false, 'reason' => 'rollup_checkpoint_persist_failed' );
			}
			self::cron_note_event( 'rollup_checkpoint_persisted', array( 'rollup_id' => $rollup_id, 'processed_count' => count( $records ), 'reopened' => $is_rebuild ) );
			return array( 'ok' => true, 'processed' => true, 'record_id' => $record_id, 'input_count' => count( $records ), 'output_hash' => (string) $output['output_hash'], 'superseded_record_id' => $superseded_record_id, 'reopened' => $is_rebuild, 'replayed_output' => ! empty( $admission['replayed'] ), 'checkpoint' => $checkpoint_ok );
		} finally {
			self::release_lease( $rollup_id, $dimension_key, (string) ( $lease['token'] ?? '' ) );
		}
	}

	/**
	 * Validate the dimension tuple required by each registered rollup family.
	 *
	 * @param string              $rollup_id Registered rollup identifier.
	 * @param array<string,mixed> $filters Typed dimension filters.
	 * @return array<string,mixed>
	 */
	public static function validate_dimension_filters( $rollup_id, array $filters = array() ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — bind each worker to its declared identity/entity dimensions before lease or aggregation.
		$rollup_id = sanitize_key( (string) $rollup_id );
		$entity_type = sanitize_key( (string) ( $filters['entity_type'] ?? '' ) );
		$entity_key = sanitize_text_field( (string) ( $filters['entity_key'] ?? '' ) );
		$secondary_type = sanitize_key( (string) ( $filters['secondary_type'] ?? '' ) );
		$secondary_key = sanitize_text_field( (string) ( $filters['secondary_key'] ?? '' ) );
		if ( $entity_type === '' || $entity_key === '' ) {
			return array( 'ok' => false, 'reason' => 'rollup_dimension_filter_required' );
		}
		$required = array(
			'conversation_state' => array( 'entity_type' => 'conversation' ),
			'customer_product_affinity' => array( 'entity_type' => 'identity', 'secondary_type' => 'product' ),
			'sku_inventory' => array( 'entity_type' => 'sku', 'secondary_type' => 'warehouse' ),
			'order_lifecycle' => array( 'entity_type' => 'order' ),
		);
		if ( ! isset( $required[ $rollup_id ] ) ) {
			return array( 'ok' => false, 'reason' => 'rollup_not_registered' );
		}
		$expected = $required[ $rollup_id ];
		if ( $entity_type !== $expected['entity_type'] ) {
			return array( 'ok' => false, 'reason' => 'rollup_entity_type_mismatch' );
		}
		if ( isset( $expected['secondary_type'] ) && ( $secondary_type !== $expected['secondary_type'] || $secondary_key === '' ) ) {
			return array( 'ok' => false, 'reason' => 'rollup_secondary_dimension_required' );
		}
		return array( 'ok' => true, 'entity_type' => $entity_type, 'entity_key' => $entity_key, 'secondary_type' => $secondary_type, 'secondary_key' => $secondary_key );
	}

	private static function persist_checkpoint( array $lease, $record_id, $occurred_at, $processed_count, $output_hash ) {
		global $wpdb;
		$sql = 'UPDATE ' . self::table() . ' SET checkpoint_record_id = %s, checkpoint_occurred_at = %s, processed_count = processed_count + %d, last_output_hash = %s, last_error = %s, dirty_since = NULL, dirty_record_id = %s, superseded_record_id = %s, updated_at = %s WHERE blog_id = %d AND rollup_id = %s AND dimension_key = %s AND lock_token = %s';
		$result = $wpdb->query( $wpdb->prepare( $sql, (string) $record_id, (string) $occurred_at, max( 0, (int) $processed_count ), (string) $output_hash, '', '', '', gmdate( 'Y-m-d H:i:s' ), (int) $lease['blog_id'], (string) $lease['rollup_id'], (string) $lease['dimension_key'], (string) $lease['token'] ) );
		return false === $result ? array( 'ok' => false, 'reason' => 'checkpoint_update_failed' ) : array( 'ok' => true, 'updated' => (int) $result, 'record_id' => (string) $record_id, 'occurred_at' => (string) $occurred_at );
	}

	private static function output_record_id( $rollup_id, $dimension_key, $output_hash ) {
		return 'rollup_' . sanitize_key( (string) $rollup_id ) . '_' . substr( hash( 'sha256', (string) get_current_blog_id() . '|' . sanitize_text_field( (string) $dimension_key ) . '|' . (string) $output_hash ), 0, 32 );
	}

	private static function mysql_datetime( $value ) {
		$time = strtotime( (string) $value );
		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : '';
	}

	private static function cron_note_event( $name, array $data = array() ) {
		if ( class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			BizCity_Cron_Manager::instance()->note_event( (string) $name, $data );
		}
	}

	private static function mark_error( array $lease, $reason ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET last_error = %s, updated_at = %s WHERE blog_id = %d AND rollup_id = %s AND dimension_key = %s AND lock_token = %s', sanitize_key( (string) $reason ), gmdate( 'Y-m-d H:i:s' ), (int) $lease['blog_id'], (string) $lease['rollup_id'], (string) $lease['dimension_key'], (string) $lease['token'] ) );
	}

	private static function after_checkpoint( array $records, array $checkpoint, $ignore_checkpoint = false ) {
		$checkpoint_time = (string) ( $checkpoint['checkpoint_occurred_at'] ?? '' );
		$checkpoint_id = (string) ( $checkpoint['checkpoint_record_id'] ?? '' );
		$out = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$occurred_at = (string) ( $record['occurred_at'] ?? '' );
			$record_id = (string) ( $record['record_id'] ?? '' );
			if ( ! $ignore_checkpoint && $checkpoint_time !== '' && ( $occurred_at < $checkpoint_time || ( $occurred_at === $checkpoint_time && $record_id <= $checkpoint_id ) ) ) {
				continue;
			}
			$out[] = $record;
		}
		usort( $out, function ( $left, $right ) {
			$compare = strcmp( (string) ( $left['occurred_at'] ?? '' ), (string) ( $right['occurred_at'] ?? '' ) );
			return 0 !== $compare ? $compare : strcmp( (string) ( $left['record_id'] ?? '' ), (string) ( $right['record_id'] ?? '' ) );
		} );
		return array_slice( $out, 0, self::DEFAULT_BATCH_SIZE );
	}

	private static function capture_enabled() {
		// [2026-09-02 Johnny Chu] PHASE-CB5.1 — keep durable rollup writes closed until the tenant canary explicitly enables them.
		return function_exists( 'get_option' ) && (bool) get_option( 'bizcity_context_bank_rollups_enabled', false );
	}

	private static function diagnostics_blocked() {
		// [2026-09-02 Johnny Chu] PHASE-CB5.1 — isolate both scheduled and direct worker entry during diagnostics CLI.
		return defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
	}
}

if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register( BizCity_Context_Bank_Rollup_Worker::TABLE_BASE, BizCity_Context_Bank_Rollup_Worker::MODULE_ID, BizCity_Context_Bank_Rollup_Worker::DB_VERSION, BizCity_Context_Bank_Rollup_Worker::DB_VERSION_OPTION, array( 'BizCity_Context_Bank_Rollup_Worker', 'ensure_schema' ) );
}