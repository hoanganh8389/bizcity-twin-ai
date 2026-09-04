<?php
/**
 * Bounded, authorized Context Bank metadata search owner.
 *
 * Search starts with the tenant ledger and follows only a capped number of
 * verified pointers. Payload bodies remain owned by their canonical sources.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Search', false ) ) {
	return;
}

final class BizCity_Context_Bank_Search {

	const DEFAULT_POINTER_LIMIT = 20;
	const DEFAULT_MAX_MS = 200;

	/**
	 * Search ledger metadata and verify a bounded pointer sample.
	 *
	 * @param array<string,mixed> $filters Typed ledger filters.
	 * @param string              $cursor Signed ledger cursor.
	 * @param int                 $pointer_limit Maximum pointers to verify.
	 * @param int                 $max_ms Overall verification budget.
	 * @return array<string,mixed>
	 */
	public static function search( array $filters = array(), $cursor = '', $pointer_limit = self::DEFAULT_POINTER_LIMIT, $max_ms = self::DEFAULT_MAX_MS ) {
		// [2026-09-01 Johnny Chu] PHASE-CB6.1 — centralize authorized metadata search before any bounded pointer follow.
		$started = microtime( true );
		$pointer_limit = max( 0, min( 100, (int) $pointer_limit ) );
		$max_ms = max( 1, min( 2000, (int) $max_ms ) );
		if ( ! class_exists( 'BizCity_Context_Bank_Access' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return self::failure( 'search_dependency_missing' );
		}
		$scope = BizCity_Context_Bank_Access::scope_filters( $filters );
		if ( empty( $scope['ok'] ) ) {
			return self::failure( (string) ( $scope['reason'] ?? 'context_bank_search_denied' ) );
		}
		$result = BizCity_Context_Bank_Ledger::instance()->query( $scope['filters'], (string) $cursor );
		if ( empty( $result['ok'] ) ) {
			return self::failure( (string) ( $result['reason'] ?? 'ledger_query_failed' ) );
		}
		$rows = array();
		$owner_records = array();
		$verified_count = 0;
		$failure_buckets = array();
		foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
			if ( ( microtime( true ) - $started ) * 1000 > $max_ms ) {
				break;
			}
			$verified = array( 'ok' => false, 'reason' => 'pointer_follow_budget_deferred' );
			if ( $verified_count < $pointer_limit ) {
				$verified = BizCity_Context_Bank_Ledger::instance()->follow( (string) ( $row['record_id'] ?? '' ), $scope['filters'] );
				$verified_count++;
			}
			if ( empty( $verified['ok'] ) ) {
				$reason = sanitize_key( (string) ( $verified['reason'] ?? 'pointer_verification_failed' ) );
				$failure_buckets[ $reason ] = (int) ( $failure_buckets[ $reason ] ?? 0 ) + 1;
			}
			if ( ! empty( $verified['owner'] ) && is_array( $verified['record'] ?? null ) ) {
				// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — expose bounded canonical-owner records only after pointer verification and authorization.
				$owner_records[] = array(
					'record_id' => (string) ( $row['record_id'] ?? '' ),
					'source_contract_id' => (string) ( $row['source_contract_id'] ?? '' ),
					'owner' => (string) $verified['owner'],
					'record' => $verified['record'],
				);
			}
			$rows[] = self::metadata_row( $row, ! empty( $verified['ok'] ), (string) ( $verified['reason'] ?? '' ) );
		}
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		$incomplete = count( $rows ) < count( (array) ( $result['rows'] ?? array() ) ) || ! empty( $failure_buckets );
		return array(
			'ok' => true,
			'rows' => $rows,
			'owner_records' => $owner_records,
			'next_cursor' => (string) ( $result['next_cursor'] ?? '' ),
			'truncated' => ! empty( $result['truncated'] ),
			'matched_count' => count( $rows ),
			'returned_count' => count( $rows ),
			'pointer_follows' => $verified_count,
			'pointer_follow_limit' => $pointer_limit,
			'budget_ms' => $max_ms,
			'duration_ms' => $elapsed_ms,
			'incomplete' => $incomplete,
			'degraded' => ! empty( $failure_buckets ),
			'reason_bucket' => ! empty( $failure_buckets ) ? (string) key( $failure_buckets ) : '',
			'failure_buckets' => $failure_buckets,
			'scope' => (string) $scope['scope'],
		);
	}

	private static function metadata_row( array $row, $verified, $verification_reason ) {
		// [2026-09-01 Johnny Chu] PHASE-CB6.1 — expose bounded correlation metadata while omitting filesystem pointers, hashes and payload fields.
		$allowed = array( 'id', 'blog_id', 'site_id', 'record_id', 'event_uuid', 'source_contract_id', 'contract_version', 'schema_version', 'record_kind', 'parent_record_id', 'root_record_id', 'identity_uuid', 'wp_user_id', 'contact_id', 'conversation_id', 'entity_type', 'entity_key', 'secondary_type', 'secondary_key', 'scope_key', 'case_id', 'goal_id', 'notebook_id', 'source_record_id', 'trace_id', 'occurred_at', 'ingested_at', 'valid_from', 'valid_to', 'rollup_window', 'rollup_version', 'operation', 'lifecycle_status', 'kg_status', 'kg_source_id', 'kg_passage_id', 'provenance_ref', 'indexed_at', 'created_at', 'updated_at' );
		$out = array();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$out[ $field ] = $row[ $field ];
			}
		}
		$out['verified'] = (bool) $verified;
		$out['verification_reason'] = sanitize_key( (string) $verification_reason );
		return $out;
	}

	private static function failure( $reason ) {
		return array( 'ok' => false, 'rows' => array(), 'owner_records' => array(), 'next_cursor' => '', 'truncated' => false, 'matched_count' => 0, 'returned_count' => 0, 'pointer_follows' => 0, 'incomplete' => true, 'degraded' => true, 'reason_bucket' => sanitize_key( (string) $reason ) );
	}
}