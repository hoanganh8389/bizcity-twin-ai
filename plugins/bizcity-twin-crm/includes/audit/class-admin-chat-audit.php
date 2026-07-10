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
		// [2026-06-07 Johnny Chu] PHASE-3.5-WC — insert audit row
		global $wpdb;

		$status_allowed = array( 'attempted', 'success', 'denied', 'confirm_pending', 'confirm_expired' );
		$status         = in_array( $args['status'] ?? '', $status_allowed, true )
			? $args['status']
			: 'attempted';

		$input  = isset( $args['input_json'] )  ? wp_json_encode( (array) $args['input_json'] )  : null;
		$result = isset( $args['result_json'] ) ? wp_json_encode( (array) $args['result_json'] ) : null;

		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			// Sanitise IP (OWASP A03 — do NOT store raw user-controlled header).
			$raw = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
			$ip  = filter_var( $raw, FILTER_VALIDATE_IP ) ? $raw : '';
		}

		$ok = $wpdb->insert(
			self::table(),
			array(
				'user_id'     => (int) ( $args['user_id'] ?? 0 ),
				'chat_id'     => (string) ( $args['chat_id'] ?? '' ),
				'guru_id'     => (int) ( $args['guru_id'] ?? 0 ),
				'grant_id'    => isset( $args['grant_id'] ) ? (int) $args['grant_id'] : null,
				'action'      => substr( sanitize_key( (string) ( $args['action'] ?? '' ) ), 0, 80 ),
				'status'      => $status,
				'input_json'  => $input,
				'result_json' => $result,
				'ip'          => $ip,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
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
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['chat_id'] ) ) {
			$where[]  = 'chat_id = %s';
			$params[] = (string) $filters['chat_id'];
		}
		if ( ! empty( $filters['guru_id'] ) ) {
			$where[]  = 'guru_id = %d';
			$params[] = (int) $filters['guru_id'];
		}
		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = sanitize_key( (string) $filters['action'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( (string) $filters['status'] );
		}

		$limit  = max( 1, min( 200, (int) ( $filters['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
