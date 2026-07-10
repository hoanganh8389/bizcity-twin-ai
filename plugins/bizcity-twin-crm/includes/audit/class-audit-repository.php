<?php
/**
 * BizCity CRM — Audit Log repository (M-CRM.M1.W3)
 *
 * Read-only queries against bizcity_crm_audit_log.
 * Used by REST GET /audit endpoint.
 *
 * @since 1.17.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Audit_Repository' ) ) :

class BizCity_CRM_Audit_Repository {

	/**
	 * Fetch audit entries for a specific entity, newest-first.
	 *
	 * @param string   $entity_type  e.g. 'crm_lead', 'crm_opportunity'
	 * @param int      $entity_id    Row ID of the entity.
	 * @param int      $limit        Max rows (default 50, hard-cap 200).
	 * @param int      $offset       Cursor offset for pagination.
	 *
	 * @return array{entries: list<array>, total: int}
	 */
	public static function find_by_entity( string $entity_type, int $entity_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		$limit = max( 1, min( $limit, 200 ) );

		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return array( 'entries' => array(), 'total' => 0 );
		}

		$where = $wpdb->prepare( 'entity_type = %s AND entity_id = %d', $entity_type, $entity_id );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tbl}` WHERE {$where}" );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, entity_type, entity_id, action, before_json, after_json,
				        user_id, user_label, event_uuid, created_at
				   FROM `{$tbl}`
				  WHERE {$where}
				  ORDER BY created_at DESC, id DESC
				  LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array(
			'entries' => array_map( array( __CLASS__, 'shape_entry' ), $rows ?: array() ),
			'total'   => $total,
		);
	}

	/**
	 * Fetch audit entries across all entities for a given user.
	 *
	 * @param int $user_id   WP user ID.
	 * @param int $limit     Max rows.
	 * @param int $offset    Cursor.
	 *
	 * @return array{entries: list<array>, total: int}
	 */
	public static function find_by_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		$limit = max( 1, min( $limit, 200 ) );

		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return array( 'entries' => array(), 'total' => 0 );
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$tbl}` WHERE user_id = %d", $user_id ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, entity_type, entity_id, action, before_json, after_json,
				        user_id, user_label, event_uuid, created_at
				   FROM `{$tbl}`
				  WHERE user_id = %d
				  ORDER BY created_at DESC, id DESC
				  LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array(
			'entries' => array_map( array( __CLASS__, 'shape_entry' ), $rows ?: array() ),
			'total'   => $total,
		);
	}

	/**
	 * Decode JSON fields + normalise output for REST / FE consumption.
	 *
	 * @param array $row Raw DB row.
	 * @return array
	 */
	public static function shape_entry( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'entity_type' => $row['entity_type'],
			'entity_id'   => (int) $row['entity_id'],
			'action'      => $row['action'],
			'before'      => $row['before_json'] ? json_decode( $row['before_json'], true ) : null,
			'after'       => $row['after_json']  ? json_decode( $row['after_json'],  true ) : null,
			'user_id'     => $row['user_id']     ? (int) $row['user_id'] : null,
			'user_label'  => $row['user_label']  ?: null,
			'event_uuid'  => $row['event_uuid']  ?: null,
			'created_at'  => $row['created_at'],
		);
	}
}

endif; // class_exists BizCity_CRM_Audit_Repository
