<?php
/**
 * BizCity CRM — durable identity conflict queue.
 *
 * Cache Contract (R-CACHE):
 *   group: bzcric
 *   keys: open_{blog_id}_{limit}, item_{blog_id}_{id}
 *   invalidations: enqueue flushes the group; resolution API will flush it.
 *
 * The queue stores internal IDs and hashes only. Raw email, phone and checkout
 * payloads must never be persisted by this class.
 *
 * @package BizCity_Twin_CRM\Woo
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Identity_Conflict_Queue' ) ) { return; }

final class BizCity_CRM_Identity_Conflict_Queue {

	const CACHE_GROUP = 'bzcric';
	const TABLE_SUFFIX = 'bizcity_crm_identity_conflicts';
	const DEFAULT_LEASE_SECONDS = 300;
	const MAX_RETRY_DELAY_SECONDS = 3600;

	public static function register(): void {
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — persist every identity conflict after the runtime guard.
		add_action( 'bizcity_crm_contact_identity_conflict', array( __CLASS__, 'capture' ), 10, 1 );
	}

	/**
	 * Persist a sanitized conflict envelope idempotently.
	 *
	 * @param array $event Conflict payload containing internal IDs only.
	 * @return int Queue row ID, or 0 when persistence is unavailable/failed.
	 */
	public static function capture( array $event ): int {
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — hash and deduplicate conflict evidence without raw PII.
		$source_type = sanitize_key( (string) ( $event['source'] ?? $event['source_type'] ?? '' ) );
		$source_id   = absint( $event['order_id'] ?? $event['source_id'] ?? 0 );
		$wp_user_id  = absint( $event['wp_user_id'] ?? 0 );
		$blog_id     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$reason      = sanitize_key( (string) ( $event['reason'] ?? 'identity_conflict' ) );
		$contact_ids = self::candidate_ids( $event );
		$dedupe_key  = hash( 'sha256', wp_json_encode( array( $blog_id, $source_type, $source_id, $wp_user_id, $reason, $contact_ids ) ) );
		$payload_hash = hash( 'sha256', wp_json_encode( array(
			'source'      => $source_type,
			'source_id'   => $source_id,
			'wp_user_id'  => $wp_user_id,
			'blog_id'     => $blog_id,
			'reason'      => $reason,
			'contact_ids' => $contact_ids,
		) ) );

		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_identity_conflicts();
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — fail closed when tenant schema is not provisioned yet.
		if ( ! self::table_ready( $table ) ) { return 0; }
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE dedupe_key=%s LIMIT 1", $dedupe_key ) );
		if ( $existing > 0 ) {
			return $existing;
		}

		$now = current_time( 'mysql' );
		$inserted = $wpdb->insert(
			$table,
			array(
				'source_type'      => $source_type,
				'source_id'        => $source_id > 0 ? $source_id : null,
				'wp_user_id'       => $wp_user_id > 0 ? $wp_user_id : null,
				'blog_id'          => $blog_id,
				'reason_code'      => $reason,
				'contact_ids_json' => wp_json_encode( $contact_ids ),
				'dedupe_key'       => $dedupe_key,
				'payload_hash'     => $payload_hash,
				'status'           => 'open',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			// A concurrent writer may have won the unique dedupe race.
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE dedupe_key=%s LIMIT 1", $dedupe_key ) );
			return $existing;
		}

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
		$row_id = (int) $wpdb->insert_id;
		self::record_audit( $row_id, 'captured', '', 'open', (string) ( $event['reason'] ?? 'identity_conflict' ), array( 'source_type' => $source_type, 'candidate_count' => count( $contact_ids ) ) );
		return $row_id;
	}

	/**
	 * Return open/claimed conflicts for the current blog.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_open( int $limit = 50 ): array {
		$result = self::list_paginated( array( 'page' => 1, 'per_page' => $limit, 'status' => array( 'open', 'claimed' ) ) );
		return (array) ( $result['items'] ?? array() );
	}

	/**
	 * Paginated/filterable queue listing for admin operations.
	 *
	 * @param array $args page, per_page, status, source_type, reason, search.
	 * @return array<string,mixed>
	 */
	public static function list_paginated( array $args = array() ): array {
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 10, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$allowed_statuses = array( 'open', 'claimed', 'resolved', 'rejected', 'ignored' );
		$raw_status = $args['status'] ?? '';
		$status = is_array( $raw_status ) ? array_values( array_filter( array_map( 'sanitize_key', $raw_status ) ) ) : sanitize_key( (string) $raw_status );
		$source_type = sanitize_key( (string) ( $args['source_type'] ?? '' ) );
		$reason = sanitize_key( (string) ( $args['reason'] ?? '' ) );
		$search = trim( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$cache_key = 'page_' . $blog_id . '_' . $page . '_' . $per_page . '_' . md5( wp_json_encode( array( $status, $source_type, $reason, $search ) ) );
		$cached = self::cache_get( $cache_key );
		if ( is_array( $cached ) ) { return $cached; }

		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_identity_conflicts();
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — return an empty admin queue until schema provisioning completes.
		if ( ! self::table_ready( $table ) ) { return array( 'items' => array(), 'page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0 ); }
		$where = array( 'blog_id = %d' );
		$params = array( $blog_id );
		if ( is_array( $status ) ) {
			$status = array_values( array_intersect( $status, $allowed_statuses ) );
			if ( ! empty( $status ) ) { $where[] = 'status IN (' . implode( ', ', array_fill( 0, count( $status ), '%s' ) ) . ')'; $params = array_merge( $params, $status ); }
		} elseif ( $status !== '' && in_array( $status, $allowed_statuses, true ) ) { $where[] = 'status = %s'; $params[] = $status; }
		if ( $source_type !== '' ) { $where[] = 'source_type = %s'; $params[] = $source_type; }
		if ( $reason !== '' ) { $where[] = 'reason_code = %s'; $params[] = $reason; }
		if ( $search !== '' && ctype_digit( $search ) ) { $where[] = '(id = %d OR source_id = %d OR wp_user_id = %d)'; $params[] = (int) $search; $params[] = (int) $search; $params[] = (int) $search; }
		$where_sql = implode( ' AND ', $where );
		$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $params );
		$total = (int) $wpdb->get_var( $count_sql );
		$offset = ( $page - 1 ) * $per_page;
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_type, source_id, wp_user_id, blog_id, reason_code, contact_ids_json, status, retry_count, last_error, claimed_by, claimed_at, retry_after, created_at, updated_at FROM `{$table}` WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
			$list_params
		), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$row['id'] = (int) $row['id'];
			$row['source_id'] = (int) $row['source_id'];
			$row['wp_user_id'] = (int) $row['wp_user_id'];
			$row['blog_id'] = (int) $row['blog_id'];
			$row['claimed_by'] = (int) $row['claimed_by'];
			$row['contact_ids'] = json_decode( (string) $row['contact_ids_json'], true );
			$row['contact_ids'] = is_array( $row['contact_ids'] ) ? array_map( 'intval', $row['contact_ids'] ) : array();
			unset( $row['contact_ids_json'] );
		}
		unset( $row );
		$result = array( 'items' => $rows, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0, 'filters' => array( 'status' => $status, 'source_type' => $source_type, 'reason' => $reason, 'search' => $search ) );
		self::cache_set( $cache_key, $result );
		return $result;
	}

	/**
	 * Atomically claim the oldest eligible conflict for a maintenance worker.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function claim_next( int $worker_id, int $lease_seconds = self::DEFAULT_LEASE_SECONDS ): ?array {
		if ( $worker_id <= 0 ) { return null; }
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_identity_conflicts();
		if ( ! self::table_ready( $table ) ) { return null; }
		$lease_seconds = max( 30, min( 3600, $lease_seconds ) );
		$now = current_time( 'mysql' );
		$expired = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $lease_seconds );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE blog_id=%d AND (status='open' OR (status='claimed' AND claimed_at IS NOT NULL AND claimed_at < %s)) AND (retry_after IS NULL OR retry_after <= %s) ORDER BY created_at ASC LIMIT 1 FOR UPDATE",
				(int) get_current_blog_id(),
				$expired,
				$now
			), ARRAY_A );
			if ( ! $row ) {
				$wpdb->query( 'COMMIT' );
				return null;
			}
			$updated = $wpdb->update(
				$table,
				array( 'status' => 'claimed', 'claimed_by' => $worker_id, 'claimed_at' => $now, 'updated_at' => $now ),
				array( 'id' => (int) $row['id'], 'blog_id' => (int) get_current_blog_id() ),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d', '%d' )
			);
			if ( false === $updated ) { throw new RuntimeException( 'identity_conflict_claim_failed' ); }
			$wpdb->query( 'COMMIT' );
			self::flush_cache();
			self::record_audit( (int) $row['id'], 'claimed', 'open', 'claimed', 'claim', array( 'worker_id' => $worker_id ) );
			return self::get( (int) $row['id'] );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}
	}

	/** @return array<string,mixed>|null */
	public static function get( int $id ): ?array {
		if ( $id <= 0 ) { return null; }
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_identity_conflicts();
		if ( ! self::table_ready( $table ) ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id=%d AND blog_id=%d LIMIT 1", $id, (int) get_current_blog_id() ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** Requeue a claimed conflict with exponential backoff. */
	public static function retry( int $id, string $error, int $delay_seconds = 0 ): bool {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_identity_conflicts();
		if ( ! self::table_ready( $table ) ) { return false; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT retry_count, status FROM `{$table}` WHERE id=%d AND blog_id=%d LIMIT 1", $id, (int) get_current_blog_id() ), ARRAY_A );
		if ( ! $row || ! in_array( (string) $row['status'], array( 'open', 'claimed' ), true ) ) { return false; }
		$count = (int) $row['retry_count'] + 1;
		$delay = $delay_seconds > 0 ? $delay_seconds : min( self::MAX_RETRY_DELAY_SECONDS, 30 * ( 2 ** min( 8, $count - 1 ) ) );
		$now = current_time( 'mysql' );
		$retry_after = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay );
		$updated = $wpdb->update( $table, array( 'status' => 'open', 'retry_count' => $count, 'last_error' => sanitize_text_field( $error ) ?: null, 'claimed_by' => null, 'claimed_at' => null, 'retry_after' => $retry_after, 'updated_at' => $now ), array( 'id' => $id, 'blog_id' => (int) get_current_blog_id() ), array( '%s', '%d', '%s', '%d', '%s', '%s', '%s' ), array( '%d', '%d' ) );
		if ( false === $updated ) { return false; }
		self::flush_cache();
		self::record_audit( $id, 'retried', (string) $row['status'], 'open', $error, array( 'retry_count' => $count, 'delay_seconds' => $delay ) );
		return true;
	}

	/**
	 * Resolve one conflict without merging Contacts.
	 *
	 * @param int    $id              Queue row ID.
	 * @param int    $contact_id      Must be one of the recorded candidates.
	 * @param string $resolution_note Operator reason bucket/note.
	 * @return bool
	 */
	public static function resolve( int $id, int $contact_id, string $resolution_note ): bool {
		return self::transition( $id, 'resolved', $contact_id, $resolution_note );
	}

	/**
	 * Reject one conflict without changing Contact records.
	 *
	 * @return bool
	 */
	public static function reject( int $id, string $resolution_note ): bool {
		return self::transition( $id, 'rejected', 0, $resolution_note );
	}

	private static function transition( int $id, string $status, int $contact_id, string $resolution_note ): bool {
		if ( $id <= 0 || ! in_array( $status, array( 'resolved', 'rejected' ), true ) ) { return false; }
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_identity_conflicts();
		if ( ! self::table_ready( $table ) ) { return false; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, contact_ids_json, status FROM `{$table}` WHERE id=%d AND blog_id=%d LIMIT 1", $id, (int) get_current_blog_id() ), ARRAY_A );
		if ( ! $row || ! in_array( (string) $row['status'], array( 'open', 'claimed' ), true ) ) { return false; }
		$candidates = json_decode( (string) $row['contact_ids_json'], true );
		$candidates = is_array( $candidates ) ? array_map( 'absint', $candidates ) : array();
		if ( 'resolved' === $status && ! in_array( $contact_id, $candidates, true ) ) { return false; }
		$now = current_time( 'mysql' );
		$updated = $wpdb->update(
			$table,
			array(
				'status'                => $status,
				'resolution_contact_id' => $contact_id > 0 ? $contact_id : null,
				'resolution_reason'     => sanitize_text_field( $resolution_note ) ?: null,
				'actor_user_id'         => get_current_user_id() ?: null,
				'claimed_by'            => null,
				'claimed_at'            => null,
				'retry_after'           => null,
				'updated_at'            => $now,
				'resolved_at'           => $now,
			),
			array( 'id' => $id, 'blog_id' => (int) get_current_blog_id() ),
			array( '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);
		if ( false === $updated ) { return false; }
		self::flush_cache();
		self::record_audit( $id, $status, (string) $row['status'], $status, $resolution_note, array( 'resolution_contact_id' => $contact_id ) );
		do_action( 'bizcity_crm_identity_conflict_resolved', $id, $status, $contact_id );
		return true;
	}

	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'source_id', 'wp_user_id', 'blog_id', 'retry_count', 'claimed_by' ) as $key ) {
			if ( isset( $row[ $key ] ) ) { $row[ $key ] = (int) $row[ $key ]; }
		}
		$row['contact_ids'] = json_decode( (string) ( $row['contact_ids_json'] ?? '' ), true );
		$row['contact_ids'] = is_array( $row['contact_ids'] ) ? array_map( 'intval', $row['contact_ids'] ) : array();
		unset( $row['contact_ids_json'] );
		return $row;
	}

	private static function flush_cache(): void {
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::CACHE_GROUP ); }
	}

	/** @return array<int,array<string,mixed>> */
	public static function audit_history( int $conflict_id, int $limit = 100 ): array {
		$limit = max( 1, min( 200, $limit ) );
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_identity_conflict_audit();
		if ( ! self::table_ready( $table ) || $conflict_id <= 0 ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, conflict_id, event_type, from_status, to_status, actor_user_id, reason, meta_json, created_at FROM `{$table}` WHERE conflict_id=%d AND blog_id=%d ORDER BY id DESC LIMIT %d", $conflict_id, (int) get_current_blog_id(), $limit ), ARRAY_A );
		foreach ( (array) $rows as &$row ) {
			$row['id'] = (int) $row['id'];
			$row['conflict_id'] = (int) $row['conflict_id'];
			$row['actor_user_id'] = (int) $row['actor_user_id'];
			$row['meta'] = json_decode( (string) $row['meta_json'], true );
			$row['meta'] = is_array( $row['meta'] ) ? $row['meta'] : array();
			unset( $row['meta_json'] );
		}
		return (array) $rows;
	}

	private static function record_audit( int $conflict_id, string $event_type, string $from_status, string $to_status, string $reason, array $meta = array() ): void {
		if ( $conflict_id <= 0 ) { return; }
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_identity_conflict_audit();
		if ( ! self::table_ready( $table ) ) { return; }
		$wpdb->insert( $table, array( 'conflict_id' => $conflict_id, 'blog_id' => (int) get_current_blog_id(), 'event_type' => sanitize_key( $event_type ), 'from_status' => $from_status !== '' ? sanitize_key( $from_status ) : null, 'to_status' => $to_status !== '' ? sanitize_key( $to_status ) : null, 'actor_user_id' => get_current_user_id() ?: null, 'reason' => sanitize_text_field( $reason ) ?: null, 'meta_json' => wp_json_encode( $meta ), 'created_at' => current_time( 'mysql' ) ), array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
	}

	private static function candidate_ids( array $event ): array {
		$ids = isset( $event['contact_ids'] ) && is_array( $event['contact_ids'] ) ? $event['contact_ids'] : array();
		foreach ( array( 'email_contact_id', 'phone_contact_id' ) as $key ) {
			if ( ! empty( $event[ $key ] ) ) { $ids[] = $event[ $key ]; }
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private static function table_ready( string $table ): bool {
		return class_exists( 'BizCity_CRM_DB_Installer_V2' )
			&& method_exists( 'BizCity_CRM_DB_Installer_V2', 'table_exists' )
			&& BizCity_CRM_DB_Installer_V2::table_exists( $table );
	}

	private static function cache_get( string $key ) {
		return class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
	}

	private static function cache_set( string $key, $value ): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $value, BizCity_Cache::TTL_SHORT );
		}
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzcric', 'modules.twin-crm', array(
		'open_{blog_id}_{limit}' => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Open identity conflicts for current blog' ),
		'page_{blog_id}_{args_hash}' => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Paginated/filterable identity conflict queue result' ),
		'item_{blog_id}_{id}'    => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Identity conflict detail by blog and ID' ),
		'audit_{blog_id}_{id}'   => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Identity conflict append-only audit history' ),
	) );
}
