<?php
/**
 * Context Bank pointer/correlation ledger.
 *
 * The ledger stores verified metadata only. Payloads remain in the registered
 * encrypted business filestore or canonical Event Stream owner.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Ledger', false ) ) {
	return;
}

final class BizCity_Context_Bank_Ledger {

	const TABLE_BASE        = 'bizcity_context_bank';
	const MODULE_ID         = 'core.context-bank';
	const DB_VERSION        = '1.3.0'; // [2026-09-02 02:55 PM Johnny Chu - Chu Hoàng Anh] R-DCL — share the module-level stamp with the latest rollup-state schema and prevent Provisioner downgrade.
	const DB_VERSION_OPTION = 'bizcity_context_bank_schema_version';
	const CACHE_GROUP       = 'context_bank_ledger';
	const CACHE_TTL         = 60;

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'bizcity_context_bank_reference_write', array( $this, 'on_reference_write' ), 20, 1 );
		add_action( 'bizcity_context_bank_reference_delete', array( $this, 'on_reference_delete' ), 20, 1 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	/**
	 * Return current tenant route evidence without exposing connection details.
	 *
	 * @return array<string,mixed>
	 */
	public static function route_evidence() {
		global $wpdb;

		// [2026-09-01 Johnny Chu] R-MSDB-CB3 — require the canonical router's verified route before tenant SQL.
		if ( ! is_object( $wpdb ) ) {
			return array( 'ok' => false, 'reason' => 'tenant_db_unavailable' );
		}
		if ( class_exists( 'BizCity_WPDB_Router', false ) && $wpdb instanceof BizCity_WPDB_Router ) {
			if ( ! method_exists( $wpdb, 'biz_get_context_route_evidence' ) ) {
				return array( 'ok' => false, 'reason' => 'route_evidence_unavailable' );
			}
			$evidence = $wpdb->biz_get_context_route_evidence( (int) get_current_blog_id() );
			return is_array( $evidence ) ? $evidence : array( 'ok' => false, 'reason' => 'route_evidence_invalid' );
		}

		return array(
			'ok' => true,
			'mode' => 'standalone',
			'blog_id' => (int) get_current_blog_id(),
			'keymeta_verified' => false,
		);
	}

	private static function require_route() {
		$evidence = self::route_evidence();
		return ! empty( $evidence['ok'] ) ? true : (string) ( $evidence['reason'] ?? 'tenant_route_refused' );
	}

	public static function ensure_schema() {
		$route = self::require_route();
		if ( true !== $route ) {
			return array( 'ok' => false, 'reason' => $route );
		}
		if ( ! class_exists( 'BizCity_Diagnostics_Auto_Create' ) || ! method_exists( 'BizCity_Diagnostics_Auto_Create', 'run' ) ) {
			return array( 'ok' => false, 'reason' => 'diagnostics_auto_create_unavailable' );
		}
		$result = BizCity_Diagnostics_Auto_Create::run( self::TABLE_BASE );
		if ( is_array( $result ) && ! empty( $result['ok'] ) ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
			self::invalidate_cache();
		}
		return is_array( $result ) ? $result : array( 'ok' => false, 'reason' => 'provisioning_unknown' );
	}

	public function on_reference_write( $reference ) {
		$this->record( is_array( $reference ) ? $reference : array() );
	}

	public function on_reference_delete( $reference ) {
		$reference = is_array( $reference ) ? $reference : array();
		$source_contract_id = (string) ( $reference['source_contract_id'] ?? '' );
		$record_id = (string) ( $reference['record_id'] ?? '' );
		if ( $source_contract_id === '' || $record_id === '' ) {
			return array( 'ok' => false, 'reason' => 'delete_reference_missing_pointer' );
		}
		return $this->record( array_merge( $reference, array(
			'source_contract_id' => $source_contract_id,
			'record_id' => $record_id,
			'operation' => 'delete',
			'lifecycle_status' => 'deleted',
		) ) );
	}

	public function record( array $reference ) {
		global $wpdb;

		$route = self::require_route();
		if ( true !== $route ) {
			return array( 'ok' => false, 'reason' => $route );
		}

		$blog_id = (int) get_current_blog_id();
		$receipt = isset( $reference['receipt'] ) && is_array( $reference['receipt'] ) ? $reference['receipt'] : array();
		$contract_id = (string) ( $reference['source_contract_id'] ?? $receipt['contract_id'] ?? '' );
		$record_id = (string) ( $reference['record_id'] ?? $receipt['record_id'] ?? '' );
		if ( $blog_id <= 0 || $contract_id === '' || $record_id === '' ) {
			return array( 'ok' => false, 'reason' => 'reference_identity_missing' );
		}
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) || ! BizCity_File_Contract_Registry::has( $contract_id ) ) {
			return array( 'ok' => false, 'reason' => 'source_contract_unregistered' );
		}
		if ( ! $this->receipt_is_valid( $receipt, $contract_id, $record_id, $blog_id ) ) {
			return array( 'ok' => false, 'reason' => 'file_receipt_invalid' );
		}

		$kind = sanitize_key( (string) ( $reference['record_kind'] ?? ( $reference['memory_class'] ?? 'memory' ) ) );
		if ( ! in_array( $kind, array( 'event', 'rollup', 'memory', 'rule', 'relation' ), true ) ) {
			return array( 'ok' => false, 'reason' => 'record_kind_invalid' );
		}
		$operation = (string) ( $reference['operation'] ?? $receipt['operation'] ?? 'upsert' ) === 'delete' ? 'delete' : 'upsert';
		$status = sanitize_key( (string) ( $reference['lifecycle_status'] ?? ( $operation === 'delete' ? 'deleted' : 'active' ) ) );
		$kg_status = sanitize_key( (string) ( $reference['kg_status'] ?? 'not_candidate' ) );
		$contract = BizCity_File_Contract_Registry::get( $contract_id );
		$occurred_at = $this->mysql_datetime( (string) ( $receipt['occurred_at'] ?? '' ) );
		$ingested_at = gmdate( 'Y-m-d H:i:s' );
		$identity_uuid = (string) ( $reference['identity_uuid'] ?? '' );
		$scope_key = (string) ( $reference['scope_key'] ?? $identity_uuid );
		$existing_rows = $this->find( array(
			'blog_id' => $blog_id,
			'source_contract_id' => $contract_id,
			'record_id' => $record_id,
			'limit' => 1,
		) );
		$existing = isset( $existing_rows[0] ) && is_array( $existing_rows[0] ) ? $existing_rows[0] : array();
		if ( ! empty( $existing ) && (string) ( $existing['event_uuid'] ?? '' ) === (string) $receipt['event_uuid'] ) {
			$pointer_same = (string) ( $existing['relative_file'] ?? '' ) === (string) $receipt['relative_file']
				&& (int) ( $existing['byte_offset'] ?? -1 ) === (int) $receipt['byte_offset']
				&& (string) ( $existing['row_hash'] ?? '' ) === (string) $receipt['row_hash']
				&& (string) ( $existing['content_hash'] ?? '' ) === (string) $receipt['content_hash'];
			if ( $pointer_same ) {
				return array( 'ok' => true, 'replayed' => true, 'ledger_id' => (int) ( $existing['id'] ?? 0 ), 'operation' => $operation );
			}
			return array( 'ok' => false, 'reason' => 'pointer_conflict' );
		}

		$data = array(
			'blog_id' => $blog_id,
			'site_id' => $blog_id,
			'record_id' => $record_id,
			'event_uuid' => (string) $receipt['event_uuid'],
			'source_contract_id' => $contract_id,
			'contract_version' => (string) ( $contract['schema_version'] ?? '1.0.0' ),
			'schema_version' => (string) ( $contract['schema_version'] ?? '1.0.0' ),
			'record_kind' => $kind,
			'parent_record_id' => (string) ( $reference['parent_record_id'] ?? '' ),
			'root_record_id' => (string) ( $reference['root_record_id'] ?? $record_id ),
			'identity_uuid' => $identity_uuid,
			'wp_user_id' => (int) ( $reference['user_id'] ?? 0 ),
			'contact_id' => (int) ( $reference['contact_id'] ?? 0 ),
			'conversation_id' => (int) ( $reference['conversation_id'] ?? 0 ),
			'entity_type' => (string) ( $reference['entity_type'] ?? '' ),
			'entity_key' => (string) ( $reference['entity_key'] ?? '' ),
			'secondary_type' => (string) ( $reference['secondary_type'] ?? '' ),
			'secondary_key' => (string) ( $reference['secondary_key'] ?? '' ),
			'scope_key' => $scope_key,
			'case_id' => (string) ( $reference['case_id'] ?? '' ),
			'goal_id' => (string) ( $reference['goal_id'] ?? '' ),
			'notebook_id' => (int) ( $reference['notebook_id'] ?? 0 ),
			'source_record_id' => (string) ( $reference['source_record_id'] ?? $record_id ),
			'trace_id' => (string) ( $reference['trace_id'] ?? '' ),
			'occurred_at' => $occurred_at,
			'ingested_at' => $ingested_at,
			'valid_from' => $this->mysql_nullable_datetime( $reference['valid_from'] ?? '' ),
			'valid_to' => $this->mysql_nullable_datetime( $reference['valid_to'] ?? '' ),
			'rollup_window' => (string) ( $reference['rollup_window'] ?? '' ),
			'rollup_version' => (string) ( $reference['rollup_version'] ?? '' ),
			'relative_file' => (string) $receipt['relative_file'],
			'byte_offset' => (int) $receipt['byte_offset'],
			'row_hash' => (string) $receipt['row_hash'],
			'content_hash' => (string) $receipt['content_hash'],
			'operation' => $operation,
			'lifecycle_status' => $status,
			'kg_status' => $kg_status,
			'kg_source_id' => (int) ( $reference['kg_source_id'] ?? 0 ),
			'kg_passage_id' => (int) ( $reference['kg_passage_id'] ?? 0 ),
			'provenance_ref' => (string) ( $reference['provenance_ref'] ?? '' ),
			'idempotency_key' => (string) ( $reference['idempotency_key'] ?? $record_id ),
			'indexed_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		$columns = array_keys( $data );
		$values = array_values( $data );
		$integer_fields = array( 'blog_id', 'site_id', 'wp_user_id', 'contact_id', 'conversation_id', 'notebook_id', 'byte_offset', 'kg_source_id', 'kg_passage_id' );
		$placeholders = array();
		$typed_values = array();
		foreach ( $values as $index => $value ) {
			$field = $columns[ $index ];
			if ( $value === null ) {
				$placeholders[] = 'NULL';
				continue;
			}
			$is_integer = in_array( $field, $integer_fields, true );
			$placeholders[] = $is_integer ? '%d' : '%s';
			$typed_values[] = $is_integer ? (int) $value : (string) $value;
		}
		$updates = array();
		foreach ( $columns as $column ) {
			if ( in_array( $column, array( 'blog_id', 'site_id', 'record_id', 'source_contract_id' ), true ) ) {
				continue;
			}
			$updates[] = "`{$column}` = VALUES(`{$column}`)";
		}
		$sql = 'INSERT INTO ' . self::table() . ' (`' . implode( '`, `', $columns ) . '`) VALUES (' . implode( ', ', $placeholders ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
		$result = $wpdb->query( $wpdb->prepare( $sql, $typed_values ) );
		if ( false === $result ) {
			return array( 'ok' => false, 'reason' => 'ledger_degraded' );
		}
		self::invalidate_cache();
		return array( 'ok' => true, 'ledger_id' => (int) $wpdb->insert_id, 'operation' => $operation );
	}

	public function find( array $filters = array() ) {
		global $wpdb;

		$route = self::require_route();
		if ( true !== $route ) {
			return array();
		}
		$blog_id = (int) ( $filters['blog_id'] ?? get_current_blog_id() );
		if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() ) {
			return array();
		}
		// [2026-09-01 Johnny Chu] CB3.3 — isolate ledger metadata cache by tenant and routed database.
		$cache_key = 'find_' . md5( wp_json_encode( array(
			'blog_id' => $blog_id,
			'db' => (string) ( $wpdb->dbname ?? '' ),
			'filters' => $filters,
		) ) );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}
		$where = array( 'blog_id = %d' );
		$params = array( $blog_id );
		foreach ( array( 'record_id', 'source_contract_id', 'identity_uuid', 'scope_key', 'trace_id', 'entity_type', 'entity_key', 'lifecycle_status', 'record_kind' ) as $field ) {
			if ( isset( $filters[ $field ] ) && (string) $filters[ $field ] !== '' ) {
				$where[] = "{$field} = %s";
				$params[] = (string) $filters[ $field ];
			}
		}
		if ( isset( $filters['wp_user_id'] ) && (int) $filters['wp_user_id'] > 0 ) {
			$where[] = 'wp_user_id = %d';
			$params[] = (int) $filters['wp_user_id'];
		}
		$limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$params[] = $limit;
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $rows, self::CACHE_TTL );
		}
		return $rows;
	}

	/**
	 * Attach canonical KG provenance IDs to one existing pointer.
	 *
	 * @param array<string,mixed> $pointer Existing ledger pointer identity.
	 * @param int                 $kg_source_id KG-Hub source ID.
	 * @param int                 $kg_passage_id KG-Hub passage ID.
	 * @param string              $provenance_ref Stable promotion reference.
	 * @return array<string,mixed>
	 */
	public function update_kg_reference( array $pointer, $kg_source_id, $kg_passage_id, $provenance_ref = '' ) {
		global $wpdb;

		$route = self::require_route();
		if ( true !== $route ) {
			return array( 'ok' => false, 'reason' => $route );
		}
		$blog_id = (int) get_current_blog_id();
		$contract_id = (string) ( $pointer['source_contract_id'] ?? '' );
		$record_id = (string) ( $pointer['record_id'] ?? '' );
		$event_uuid = (string) ( $pointer['event_uuid'] ?? '' );
		$kg_source_id = (int) $kg_source_id;
		$kg_passage_id = (int) $kg_passage_id;
		if ( $blog_id <= 0 || $contract_id === '' || $record_id === '' || $event_uuid === '' || $kg_source_id <= 0 || $kg_passage_id <= 0 ) {
			return array( 'ok' => false, 'reason' => 'kg_reference_identity_invalid' );
		}
		$existing = $this->find( array(
			'blog_id' => $blog_id,
			'source_contract_id' => $contract_id,
			'record_id' => $record_id,
			'limit' => 1,
		) );
		if ( empty( $existing[0] ) || (string) ( $existing[0]['event_uuid'] ?? '' ) !== $event_uuid ) {
			return array( 'ok' => false, 'reason' => 'kg_reference_pointer_not_found' );
		}
		$data = array(
			'kg_status' => 'promoted',
			'kg_source_id' => $kg_source_id,
			'kg_passage_id' => $kg_passage_id,
			'provenance_ref' => (string) $provenance_ref,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$result = $wpdb->update(
			self::table(),
			$data,
			array( 'blog_id' => $blog_id, 'source_contract_id' => $contract_id, 'record_id' => $record_id, 'event_uuid' => $event_uuid ),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d', '%s', '%s', '%s' )
		);
		if ( false === $result ) {
			return array( 'ok' => false, 'reason' => 'kg_reference_update_failed' );
		}
		self::invalidate_cache();
		return array( 'ok' => true, 'updated' => (int) $result, 'kg_source_id' => $kg_source_id, 'kg_passage_id' => $kg_passage_id );
	}

	/**
	 * Mark the KG state of one existing pointer without changing its source receipt.
	 *
	 * @param array<string,mixed> $pointer Existing ledger pointer identity.
	 * @param string              $kg_status Bounded KG state.
	 * @param string              $reason Reconciliation reason bucket.
	 * @return array<string,mixed>
	 */
	public function mark_kg_status( array $pointer, $kg_status, $reason = '' ) {
		// [2026-09-02 05:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-CB6.4 — mark derived KG provenance stale through the tenant ledger owner without mutating canonical KG rows directly.
		global $wpdb;
		$route = self::require_route();
		if ( true !== $route ) {
			return array( 'ok' => false, 'reason' => $route );
		}
		$blog_id = (int) get_current_blog_id();
		$contract_id = (string) ( $pointer['source_contract_id'] ?? '' );
		$record_id = (string) ( $pointer['record_id'] ?? '' );
		$event_uuid = (string) ( $pointer['event_uuid'] ?? '' );
		$kg_status = sanitize_key( (string) $kg_status );
		if ( $blog_id <= 0 || $contract_id === '' || $record_id === '' || $event_uuid === '' || ! in_array( $kg_status, array( 'pending', 'promoted', 'stale', 'not_candidate' ), true ) ) {
			return array( 'ok' => false, 'reason' => 'kg_status_identity_invalid' );
		}
		$existing = $this->find( array( 'blog_id' => $blog_id, 'source_contract_id' => $contract_id, 'record_id' => $record_id, 'limit' => 1 ) );
		if ( empty( $existing[0] ) || (string) ( $existing[0]['event_uuid'] ?? '' ) !== $event_uuid ) {
			return array( 'ok' => false, 'reason' => 'kg_status_pointer_not_found' );
		}
		$updated = $wpdb->update(
			self::table(),
			array( 'kg_status' => $kg_status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'blog_id' => $blog_id, 'source_contract_id' => $contract_id, 'record_id' => $record_id, 'event_uuid' => $event_uuid ),
			array( '%s', '%s' ),
			array( '%d', '%s', '%s', '%s' )
		);
		if ( false === $updated ) {
			return array( 'ok' => false, 'reason' => 'kg_status_update_failed' );
		}
		self::invalidate_cache();
		return array( 'ok' => true, 'kg_status' => $kg_status, 'reason' => sanitize_key( (string) $reason ) );
	}

	/**
	 * Query ledger metadata with bounded typed filters and opaque pagination.
	 *
	 * @param array<string,mixed> $filters Supported ledger metadata filters.
	 * @param string              $cursor Signed cursor from a previous page.
	 * @return array<string,mixed>
	 */
	public function query( array $filters = array(), $cursor = '' ) {
		global $wpdb;

		$route = self::require_route();
		if ( true !== $route ) {
			return array( 'ok' => false, 'reason' => $route, 'rows' => array(), 'next_cursor' => '' );
		}
		$blog_id = (int) get_current_blog_id();
		if ( isset( $filters['blog_id'] ) && (int) $filters['blog_id'] !== $blog_id ) {
			return array( 'ok' => false, 'reason' => 'tenant_scope_denied', 'rows' => array(), 'next_cursor' => '' );
		}
		if ( $blog_id <= 0 ) {
			return array( 'ok' => false, 'reason' => 'tenant_context_missing', 'rows' => array(), 'next_cursor' => '' );
		}
		$limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$normalized = array();
		$string_fields = array( 'source_contract_id', 'record_id', 'record_kind', 'identity_uuid', 'scope_key', 'entity_type', 'entity_key', 'secondary_type', 'secondary_key', 'case_id', 'goal_id', 'lifecycle_status', 'kg_status', 'trace_id' );
		foreach ( $string_fields as $field ) {
			$value = sanitize_text_field( (string) ( $filters[ $field ] ?? '' ) );
			if ( $value !== '' ) {
				$normalized[ $field ] = $value;
			}
		}
		$contract_ids = array();
		foreach ( (array) ( $filters['source_contract_ids'] ?? array() ) as $contract_id ) {
			$contract_id = sanitize_text_field( (string) $contract_id );
			if ( $contract_id !== '' ) {
				$contract_ids[] = $contract_id;
			}
		}
		if ( ! empty( $contract_ids ) ) {
			$normalized['source_contract_ids'] = array_values( array_unique( $contract_ids ) );
		}
		foreach ( array( 'wp_user_id', 'contact_id', 'conversation_id', 'notebook_id' ) as $field ) {
			$value = absint( $filters[ $field ] ?? 0 );
			if ( $value > 0 ) {
				$normalized[ $field ] = $value;
			}
		}
		foreach ( array( 'date_from', 'date_to' ) as $field ) {
			$value = sanitize_text_field( (string) ( $filters[ $field ] ?? '' ) );
			if ( $value !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $value ) ) {
				$normalized[ $field ] = $value;
			}
		}
		// [2026-09-02 Johnny Chu] PHASE-CB5.1 — keep the worker's chronological cursor separate from persisted ledger predicates.
		$after_occurred_at = sanitize_text_field( (string) ( $filters['after_occurred_at'] ?? '' ) );
		$after_record_id = sanitize_text_field( (string) ( $filters['after_record_id'] ?? '' ) );
		$ascending = isset( $filters['order'] ) && strtolower( (string) $filters['order'] ) === 'asc';
		if ( $after_occurred_at !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $after_occurred_at ) ) {
			$normalized['after_occurred_at'] = $after_occurred_at;
		}
		if ( $after_record_id !== '' ) {
			$normalized['after_record_id'] = $after_record_id;
		}
		$normalized['order'] = $ascending ? 'asc' : 'desc';
		$cursor_payload = array();
		if ( (string) $cursor !== '' ) {
			$cursor_payload = $this->decode_cursor( (string) $cursor );
			if ( ! is_array( $cursor_payload )
				|| (int) ( $cursor_payload['blog_id'] ?? 0 ) !== $blog_id
				|| (string) ( $cursor_payload['db'] ?? '' ) !== (string) ( $wpdb->dbname ?? '' )
				|| (string) ( $cursor_payload['filters_hash'] ?? '' ) !== md5( wp_json_encode( $normalized ) ) ) {
				return array( 'ok' => false, 'reason' => 'cursor_invalid', 'rows' => array(), 'next_cursor' => '' );
			}
		}

		$cache_key = 'query_' . md5( wp_json_encode( array(
			'blog_id' => $blog_id,
			'db' => (string) ( $wpdb->dbname ?? '' ),
			'filters' => $normalized,
			'cursor' => $cursor_payload,
		) ) );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array( 'ok' => true, 'rows' => array(), 'next_cursor' => '' );
			}
		}

		$where = array( 'blog_id = %d' );
		$params = array( $blog_id );
		$integer_fields = array( 'wp_user_id', 'contact_id', 'conversation_id', 'notebook_id' );
		foreach ( $normalized as $field => $value ) {
			if ( in_array( $field, array( 'after_occurred_at', 'after_record_id', 'order' ), true ) ) {
				continue;
			}
			if ( $field === 'source_contract_ids' ) {
				$placeholders = implode( ', ', array_fill( 0, count( $value ), '%s' ) );
				$where[] = 'source_contract_id IN (' . $placeholders . ')';
				foreach ( $value as $contract_id ) {
					$params[] = (string) $contract_id;
				}
				continue;
			}
			if ( in_array( $field, array( 'date_from', 'date_to' ), true ) ) {
				$where[] = 'occurred_at ' . ( $field === 'date_from' ? '>= %s' : '<= %s' );
				$params[] = strlen( $value ) === 10 ? $value . ( $field === 'date_from' ? ' 00:00:00' : ' 23:59:59' ) : $value;
				continue;
			}
			$where[] = "`{$field}` = " . ( in_array( $field, $integer_fields, true ) ? '%d' : '%s' );
			$params[] = in_array( $field, $integer_fields, true ) ? (int) $value : (string) $value;
		}
		if ( ! empty( $cursor_payload ) ) {
			$where[] = '(occurred_at < %s OR (occurred_at = %s AND id < %d))';
			$params[] = (string) $cursor_payload['occurred_at'];
			$params[] = (string) $cursor_payload['occurred_at'];
			$params[] = (int) $cursor_payload['id'];
		}
		if ( $ascending && $after_occurred_at !== '' ) {
			$where[] = '(occurred_at > %s OR (occurred_at = %s AND record_id > %s))';
			$params[] = strlen( $after_occurred_at ) === 10 ? $after_occurred_at . ' 00:00:00' : $after_occurred_at;
			$params[] = strlen( $after_occurred_at ) === 10 ? $after_occurred_at . ' 00:00:00' : $after_occurred_at;
			$params[] = $after_record_id;
		}
		$columns = 'id, blog_id, site_id, record_id, event_uuid, source_contract_id, contract_version, schema_version, record_kind, parent_record_id, root_record_id, identity_uuid, wp_user_id, contact_id, conversation_id, entity_type, entity_key, secondary_type, secondary_key, scope_key, case_id, goal_id, notebook_id, source_record_id, trace_id, occurred_at, ingested_at, valid_from, valid_to, rollup_window, rollup_version, relative_file, byte_offset, row_hash, content_hash, operation, lifecycle_status, kg_status, kg_source_id, kg_passage_id, provenance_ref, idempotency_key, indexed_at, created_at, updated_at';
		$params[] = $limit + 1;
		$order_sql = $ascending ? 'occurred_at ASC, record_id ASC' : 'occurred_at DESC, id DESC';
		$sql = 'SELECT ' . $columns . ' FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY ' . $order_sql . ' LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		$next_cursor = '';
		if ( count( $rows ) > $limit ) {
			array_pop( $rows );
			$last = end( $rows );
			$next_cursor = $this->encode_cursor( array(
				'v' => 1,
				'blog_id' => $blog_id,
				'db' => (string) ( $wpdb->dbname ?? '' ),
				'filters_hash' => md5( wp_json_encode( $normalized ) ),
				'occurred_at' => (string) ( $last['occurred_at'] ?? '' ),
				'id' => (int) ( $last['id'] ?? 0 ),
			) );
		}
		$result = array( 'ok' => true, 'rows' => $rows, 'next_cursor' => $next_cursor, 'truncated' => $next_cursor !== '' );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, self::CACHE_TTL );
		}
		return $result;
	}

	/**
	 * Run an EXPLAIN for one approved ledger predicate shape.
	 *
	 * @param array<string,mixed> $filters Typed ledger filters only.
	 * @return array<string,mixed>
	 */
	public function explain( array $filters = array() ) {
		global $wpdb;

		$route = self::require_route();
		if ( true !== $route ) {
			return array( 'ok' => false, 'reason' => $route, 'rows' => 0 );
		}
		$blog_id = (int) get_current_blog_id();
		if ( $blog_id <= 0 || ( isset( $filters['blog_id'] ) && (int) $filters['blog_id'] !== $blog_id ) ) {
			return array( 'ok' => false, 'reason' => 'tenant_scope_denied', 'rows' => 0 );
		}

		$allowed_string_fields = array( 'source_contract_id', 'record_id', 'record_kind', 'identity_uuid', 'scope_key', 'entity_type', 'entity_key', 'secondary_type', 'secondary_key', 'case_id', 'goal_id', 'lifecycle_status', 'kg_status', 'trace_id' );
		$where = array( 'blog_id = %d' );
		$params = array( $blog_id );
		foreach ( $allowed_string_fields as $field ) {
			$value = sanitize_text_field( (string) ( $filters[ $field ] ?? '' ) );
			if ( $value !== '' ) {
				$where[] = "`{$field}` = %s";
				$params[] = $value;
			}
		}
		foreach ( array( 'wp_user_id', 'contact_id', 'conversation_id', 'notebook_id' ) as $field ) {
			$value = absint( $filters[ $field ] ?? 0 );
			if ( $value > 0 ) {
				$where[] = "`{$field}` = %d";
				$params[] = $value;
			}
		}
		foreach ( array( 'date_from', 'date_to' ) as $field ) {
			$value = sanitize_text_field( (string) ( $filters[ $field ] ?? '' ) );
			if ( $value !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $value ) ) {
				$where[] = 'occurred_at ' . ( $field === 'date_from' ? '>= %s' : '<= %s' );
				$params[] = strlen( $value ) === 10 ? $value . ( $field === 'date_from' ? ' 00:00:00' : ' 23:59:59' ) : $value;
			}
		}
		$limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$params[] = $limit;
		$sql = 'EXPLAIN SELECT id, occurred_at, record_kind, identity_uuid, entity_type, entity_key, secondary_type, secondary_key FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY occurred_at DESC, id DESC LIMIT %d';
		$plan = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $plan ) || empty( $plan ) ) {
			return array( 'ok' => false, 'reason' => 'explain_failed', 'rows' => 0 );
		}
		return array( 'ok' => true, 'rows' => count( $plan ) );
	}

	private function encode_cursor( array $payload ) {
		if ( ! class_exists( 'BizCity_Codec' ) || ! function_exists( 'wp_salt' ) ) {
			return '';
		}
		$body = BizCity_Codec::json_base64url_encode( $payload );
		$signature = BizCity_Codec::hmac_sha256( $body, wp_salt( 'auth' ) . '|context-bank-cursor', false );
		return $body . '.' . $signature;
	}

	private function decode_cursor( $cursor ) {
		if ( ! class_exists( 'BizCity_Codec' ) || ! function_exists( 'wp_salt' ) ) {
			return array();
		}
		$parts = explode( '.', (string) $cursor, 2 );
		if ( count( $parts ) !== 2 || ! preg_match( '/^[a-f0-9]{64}$/i', $parts[1] ) ) {
			return array();
		}
		$expected = BizCity_Codec::hmac_sha256( $parts[0], wp_salt( 'auth' ) . '|context-bank-cursor', false );
		if ( ! hash_equals( strtolower( $expected ), strtolower( $parts[1] ) ) ) {
			return array();
		}
		$payload = BizCity_Codec::json_base64url_decode( $parts[0] );
		return is_array( $payload ) && (int) ( $payload['v'] ?? 0 ) === 1 ? $payload : array();
	}

	public function verify_pointer( array $pointer ) {
		// [2026-09-02 Johnny Chu] PHASE-CB4.2 — map the ledger's canonical source_contract_id to the archive reader's receipt contract_id at the follow boundary.
		if ( (string) ( $pointer['source_contract_id'] ?? '' ) === 'core.channel_gateway.context_corpus' && empty( $pointer['contract_id'] ) ) {
			$pointer['contract_id'] = (string) $pointer['source_contract_id'];
		}
		$required = array( 'source_contract_id', 'record_id', 'relative_file', 'byte_offset', 'row_hash' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $pointer ) || (string) $pointer[ $field ] === '' ) {
				return array( 'ok' => false, 'reason' => 'pointer_field_missing' );
			}
		}
		if ( strpos( (string) $pointer['relative_file'], '..' ) !== false || strpos( (string) $pointer['relative_file'], '\\' ) !== false ) {
			return array( 'ok' => false, 'reason' => 'pointer_path_invalid' );
		}
		if ( ! class_exists( 'BizCity_Business_JSONL_File_Store' ) && ! ( (string) $pointer['source_contract_id'] === 'core.channel_gateway.context_corpus' && class_exists( 'BizCity_Channel_Conversation_Archive' ) ) ) {
			return array( 'ok' => false, 'reason' => 'filestore_reader_unavailable' );
		}
		$followed = ( (string) $pointer['source_contract_id'] === 'core.channel_gateway.context_corpus' && class_exists( 'BizCity_Channel_Conversation_Archive' ) )
			? BizCity_Channel_Conversation_Archive::read_receipt( $pointer )
			: BizCity_Business_JSONL_File_Store::read_receipt( (string) $pointer['source_contract_id'], $pointer );
		if ( ! is_array( $followed ) || empty( $followed['ok'] ) ) {
			return array( 'ok' => false, 'reason' => is_array( $followed ) ? (string) ( $followed['reason'] ?? 'pointer_verification_failed' ) : 'pointer_verification_failed' );
		}
		return array( 'ok' => true, 'verified' => true, 'operation' => (string) ( $followed['operation'] ?? 'upsert' ) );
	}

	public function follow( $record_id, array $filters = array() ) {
		$filters['record_id'] = (string) $record_id;
		$rows = $this->find( $filters );
		if ( empty( $rows[0] ) ) {
			return array( 'ok' => false, 'reason' => 'pointer_not_found' );
		}
		$pointer = $rows[0];
		if ( ! class_exists( 'BizCity_Context_Bank_Access' ) ) {
			return array( 'ok' => false, 'reason' => 'authorization_unavailable' );
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — recheck tenant, capability and pointer owner immediately before file follow.
		$authorized = BizCity_Context_Bank_Access::authorize_pointer( $pointer );
		if ( empty( $authorized['ok'] ) ) {
			return $authorized;
		}
		$verified = $this->verify_pointer( $pointer );
		if ( empty( $verified['ok'] ) ) {
			return $verified;
		}
		if ( (string) ( $pointer['source_contract_id'] ?? '' ) === 'core.skills.rule_reference' ) {
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — route Skill body retrieval through the authorized canonical owner after receipt verification.
			if ( ! class_exists( 'BizCity_Context_Bank_Rule_Reference_Adapter' ) || ! method_exists( 'BizCity_Context_Bank_Rule_Reference_Adapter', 'follow_skill' ) ) {
				return array( 'ok' => false, 'reason' => 'skill_owner_follow_unavailable' );
			}
			$owner = BizCity_Context_Bank_Rule_Reference_Adapter::follow_skill( $pointer );
			if ( empty( $owner['ok'] ) ) {
				return $owner;
			}
			return array_merge( array( 'ok' => true, 'pointer' => $pointer, 'verified' => true, 'operation' => $verified['operation'] ), $owner );
		}
		return array( 'ok' => true, 'pointer' => $pointer, 'verified' => true, 'operation' => $verified['operation'] );
	}

	/**
	 * Mark one exact pointer as quarantined after file verification fails.
	 *
	 * @param array<string,mixed> $pointer Existing ledger pointer.
	 * @param string              $reason Stable verification failure bucket.
	 * @return array<string,mixed>
	 */
	public function quarantine_pointer( array $pointer, $reason ) {
		global $wpdb;

		$route = self::require_route();
		if ( true !== $route ) {
			return array( 'ok' => false, 'reason' => $route );
		}

		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — quarantine is an explicit admin-scoped repair action, never an implicit read fallback.
		if ( ! class_exists( 'BizCity_Context_Bank_Access' ) || ! BizCity_Context_Bank_Access::is_admin_request() ) {
			return array( 'ok' => false, 'reason' => 'quarantine_admin_required' );
		}
		$blog_id = (int) get_current_blog_id();
		$source_contract_id = (string) ( $pointer['source_contract_id'] ?? '' );
		$record_id = (string) ( $pointer['record_id'] ?? '' );
		$event_uuid = (string) ( $pointer['event_uuid'] ?? '' );
		if ( $blog_id <= 0 || $source_contract_id === '' || $record_id === '' || $event_uuid === '' || ! class_exists( 'BizCity_File_Contract_Registry' ) || ! BizCity_File_Contract_Registry::has( $source_contract_id ) ) {
			return array( 'ok' => false, 'reason' => 'quarantine_pointer_invalid' );
		}
		$reason = sanitize_key( (string) $reason );
		if ( $reason === '' ) {
			return array( 'ok' => false, 'reason' => 'quarantine_reason_missing' );
		}
		$sql = 'UPDATE ' . self::table() . ' SET lifecycle_status = %s WHERE blog_id = %d AND source_contract_id = %s AND record_id = %s AND event_uuid = %s';
		$result = $wpdb->query( $wpdb->prepare( $sql, 'quarantined', $blog_id, $source_contract_id, $record_id, $event_uuid ) );
		if ( false === $result ) {
			return array( 'ok' => false, 'reason' => 'quarantine_write_failed' );
		}
		self::invalidate_cache();
		return array( 'ok' => true, 'action' => 'quarantined', 'reason' => $reason );
	}

	/**
	 * Remove one pointer only after its receipt-bearing tombstone is verified.
	 * Canonical files are never deleted by this method.
	 *
	 * @param array<string,mixed> $pointer Existing tombstone pointer.
	 * @param string              $reason Approved repair/rollback reason.
	 * @return array<string,mixed>
	 */
	public function remove_tombstoned_pointer( array $pointer, $reason ) {
		global $wpdb;

		$route = self::require_route();
		if ( true !== $route ) {
			return array( 'ok' => false, 'reason' => $route );
		}

		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — enforce tombstone-before-pointer-removal and keep canonical file state intact.
		if ( ! class_exists( 'BizCity_Context_Bank_Access' ) || ! BizCity_Context_Bank_Access::is_admin_request() ) {
			return array( 'ok' => false, 'reason' => 'pointer_removal_admin_required' );
		}
		if ( (string) ( $pointer['operation'] ?? '' ) !== 'delete' || (string) ( $pointer['lifecycle_status'] ?? '' ) !== 'deleted' ) {
			return array( 'ok' => false, 'reason' => 'tombstone_required_before_pointer_removal' );
		}
		$verified = $this->verify_pointer( $pointer );
		if ( empty( $verified['ok'] ) || (string) ( $verified['operation'] ?? '' ) !== 'delete' ) {
			return array( 'ok' => false, 'reason' => 'tombstone_verification_failed' );
		}
		$reason = sanitize_key( (string) $reason );
		if ( $reason === '' ) {
			return array( 'ok' => false, 'reason' => 'pointer_removal_reason_missing' );
		}
		$blog_id = (int) get_current_blog_id();
		$sql = 'DELETE FROM ' . self::table() . ' WHERE blog_id = %d AND source_contract_id = %s AND record_id = %s AND event_uuid = %s AND operation = %s AND lifecycle_status = %s';
		$result = $wpdb->query( $wpdb->prepare( $sql, $blog_id, (string) $pointer['source_contract_id'], (string) $pointer['record_id'], (string) $pointer['event_uuid'], 'delete', 'deleted' ) );
		if ( false === $result ) {
			return array( 'ok' => false, 'reason' => 'pointer_removal_failed' );
		}
		self::invalidate_cache();
		return array( 'ok' => true, 'action' => 'removed', 'reason' => $reason );
	}

	private function receipt_is_valid( array $receipt, $contract_id, $record_id, $blog_id ) {
		$required = array( 'contract_id', 'record_id', 'event_uuid', 'relative_file', 'byte_offset', 'row_hash', 'content_hash', 'occurred_at', 'operation', 'blog_id' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $receipt ) || (string) $receipt[ $field ] === '' ) {
				return false;
			}
		}
		return (string) $receipt['contract_id'] === $contract_id
			&& (string) $receipt['record_id'] === $record_id
			&& (int) $receipt['blog_id'] === $blog_id
			&& (int) $receipt['byte_offset'] >= 0
			&& preg_match( '/^[a-f0-9]{64}$/i', (string) $receipt['row_hash'] )
			&& preg_match( '/^[a-f0-9]{64}$/i', (string) $receipt['content_hash'] );
	}

	private function mysql_datetime( $value ) {
		$time = strtotime( (string) $value );
		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function mysql_nullable_datetime( $value ) {
		$value = trim( (string) $value );
		return $value === '' ? null : $this->mysql_datetime( $value );
	}

	private static function invalidate_cache() {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}
}

if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		BizCity_Context_Bank_Ledger::TABLE_BASE,
		BizCity_Context_Bank_Ledger::MODULE_ID,
		BizCity_Context_Bank_Ledger::DB_VERSION,
		BizCity_Context_Bank_Ledger::DB_VERSION_OPTION,
		array( 'BizCity_Context_Bank_Ledger', 'ensure_schema' )
	);
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register(
		BizCity_Context_Bank_Ledger::CACHE_GROUP,
		BizCity_Context_Bank_Ledger::MODULE_ID,
		array(
			'find_{filters_hash}' => array( 'ttl' => BizCity_Context_Bank_Ledger::CACHE_TTL, 'desc' => 'Tenant-scoped Context Bank pointer metadata' ),
			'query_{filters_hash_cursor}' => array( 'ttl' => BizCity_Context_Bank_Ledger::CACHE_TTL, 'desc' => 'Bounded tenant-scoped ledger metadata query' ),
		)
	);
}
