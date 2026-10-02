<?php
/**
 * [2026-06-07 Johnny Chu] PHASE-3.5-WC — Admin-chat audit log writer.
 *
 * Records every action attempted or completed via an admin-chat grant.
 * Table: bizcity_crm_admin_chat_audit (schema v1.22.0, R-DCL).
 *
 * Usage (in skill execution context):
 *   BizCity_CRM_AdminChat_Audit::log([
 *     'user_id'     => get_current_user_id(),
 *     'chat_id'     => $ctx['trigger']['chat_id'] ?? '',
 *     'guru_id'     => $ctx['character_id'] ?? 0,
 *     'grant_id'    => $grant_id,  // optional
 *     'action'      => 'ingest_document',
 *     'status'      => 'success',
 *     'input_json'  => [ 'doc_id' => 123 ],  // sanitised — NO PII/tokens
 *     'result_json' => [ 'rows_inserted' => 5 ],
 *   ]);
 *
 * Security note (OWASP A09): never pass raw passwords, API keys, or LLM
 * full output in input_json / result_json. Sanitise before passing.
 *
 * @package BizCity_Twin_CRM
 * @since   1.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_CRM_AdminChat_Audit {

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_crm_admin_chat_audit';
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-3.5-WC — Write one audit row.
	 *
	 * @param array $args {
	 *   int    $user_id     WP user performing the action.
	 *   string $chat_id     Canonical chat_id.
	 *   int    $guru_id     Character/Guru ID context.
	 *   int    $grant_id    Optional grant row id.
	 *   string $action      Skill slug or action identifier.
	 *   string $status      attempted|success|denied|confirm_pending|confirm_expired.
	 *   mixed  $input_json  Sanitised input params (array or null).
	 *   mixed  $result_json Outcome summary (array or null).
	 * }
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function log( array $args ) {
		// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-16 B-5 (R-LEAN-4, R-LOG-HYBRID) — moved off
		// bizcity_crm_admin_chat_audit to the shared JSONL logger (contract core.twin_crm.admin_chat_audit).
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return false;
		}

		$status_allowed = array( 'attempted', 'success', 'denied', 'confirm_pending', 'confirm_expired' );
		$status         = in_array( $args['status'] ?? '', $status_allowed, true )
			? $args['status']
			: 'attempted';

		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			// Sanitise IP (OWASP A03 — do NOT store raw user-controlled header).
			$raw = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
			$ip  = filter_var( $raw, FILTER_VALIDATE_IP ) ? $raw : '';
		}

		$action = substr( sanitize_key( (string) ( $args['action'] ?? '' ) ), 0, 80 );
		$row    = array(
			'user_id'     => (int) ( $args['user_id'] ?? 0 ),
			'chat_id'     => (string) ( $args['chat_id'] ?? '' ),
			'guru_id'     => (int) ( $args['guru_id'] ?? 0 ),
			'grant_id'    => isset( $args['grant_id'] ) ? (int) $args['grant_id'] : null,
			'action'      => $action,
			'status'      => $status,
			'input_json'  => isset( $args['input_json'] ) ? (array) $args['input_json'] : null,
			'result_json' => isset( $args['result_json'] ) ? (array) $args['result_json'] : null,
			'ip'          => $ip,
			'created_at'  => current_time( 'mysql' ),
		);

		$ok = BizCity_JSONL_File_Logger::write_contract(
			'core.twin_crm.admin_chat_audit',
			'denied' === $status ? 'warn' : 'info',
			$action !== '' ? $action : 'admin_chat_action',
			$row['chat_id'] !== '' ? $row['chat_id'] : 'admin-chat',
			$row
		);

		return $ok ? 1 : false;
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-3.5-WC — Query audit rows.
	 *
	 * @param array $filters {
	 *   int    $user_id  Filter by WP user ID.
	 *   string $chat_id  Filter by chat_id.
	 *   int    $guru_id  Filter by guru/character ID.
	 *   string $action   Filter by action slug.
	 *   string $status   Filter by status.
	 *   int    $limit    Max rows (default 50).
	 *   int    $offset   Pagination offset (default 0).
	 * }
	 * @return array
	 */
	public static function find( array $filters = array() ) {
		// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-16 B-5 — reads the shared JSONL logger instead of
		// bizcity_crm_admin_chat_audit. No SQL OFFSET on JSONL; emulated by over-fetching and slicing (acceptable:
		// this endpoint was never exercised with real data — the writer's class name typo, fixed in the same
		// wave, meant the table was always empty).
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return array();
		}
		$limit  = max( 1, min( 200, (int) ( $filters['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

		$want = array();
		foreach ( array( 'user_id', 'guru_id' ) as $k ) {
			if ( ! empty( $filters[ $k ] ) ) {
				$want[ $k ] = (int) $filters[ $k ];
			}
		}
		if ( ! empty( $filters['chat_id'] ) ) {
			$want['chat_id'] = (string) $filters['chat_id'];
		}
		if ( ! empty( $filters['action'] ) ) {
			$want['action'] = sanitize_key( (string) $filters['action'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$want['status'] = sanitize_text_field( (string) $filters['status'] );
		}

		$rows = BizCity_JSONL_File_Logger::query_contract( 'core.twin_crm.admin_chat_audit', array(
			'days'   => 90,
			'limit'  => $limit + $offset,
			'filter' => static function ( $row ) use ( $want ) {
				$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				foreach ( $want as $k => $v ) {
					if ( (string) ( $ctx[ $k ] ?? '' ) !== (string) $v ) {
						return false;
					}
				}
				return true;
			},
		) );
		// Shape each row like the old SQL columns (flatten ctx, drop the JSONL envelope fields).
		$rows = array_map( static function ( $row ) {
			$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
			$ctx['created_at'] = $ctx['created_at'] ?? str_replace( array( 'T', 'Z' ), array( ' ', '' ), (string) ( $row['ts'] ?? '' ) );
			return $ctx;
		}, $rows );

		return $offset > 0 ? array_slice( $rows, $offset, $limit ) : array_slice( $rows, 0, $limit );
	}
}
