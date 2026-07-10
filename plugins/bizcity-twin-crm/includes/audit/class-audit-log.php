<?php
/**
 * BizCity CRM — Audit Log writer (M-CRM.M1.W3)
 *
 * Single responsibility: write one immutable row to bizcity_crm_audit_log.
 * Callers never query this class — use BizCity_CRM_Audit_Repository for reads.
 *
 * @since 1.17.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Audit_Log' ) ) :

class BizCity_CRM_Audit_Log {

	/**
	 * Write one audit entry.
	 *
	 * @param string     $entity_type  e.g. 'crm_lead', 'crm_opportunity', 'crm_contract', 'contact', 'crm_invoice'
	 * @param int        $entity_id    DB row id of the mutated entity.
	 * @param string     $action       'created' | 'updated' | 'deleted' | 'restored' | 'status_changed'
	 * @param array|null $before       Associative array of fields BEFORE the mutation (null for 'created').
	 * @param array|null $after        Associative array of fields AFTER the mutation (null for 'deleted').
	 * @param array      $opts         Optional: ['user_id', 'user_label', 'event_uuid']
	 *
	 * @return int|false Inserted row ID, or false on failure / table not ready.
	 */
	public static function log(
		string $entity_type,
		int    $entity_id,
		string $action,
		?array $before = null,
		?array $after  = null,
		array  $opts   = []
	) {
		global $wpdb;

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();

		// Guard: table may not exist on fresh installs before upgrade runs.
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return false;
		}

		$user_id    = isset( $opts['user_id'] )    ? (int) $opts['user_id']       : ( get_current_user_id() ?: null );
		$user_label = isset( $opts['user_label'] ) ? (string) $opts['user_label'] : self::resolve_user_label( $user_id );
		$event_uuid = isset( $opts['event_uuid'] ) ? (string) $opts['event_uuid'] : null;

		$inserted = $wpdb->insert(
			$tbl,
			array(
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'action'      => $action,
				'before_json' => $before !== null ? wp_json_encode( $before ) : null,
				'after_json'  => $after  !== null ? wp_json_encode( $after )  : null,
				'user_id'     => $user_id,
				'user_label'  => $user_label,
				'event_uuid'  => $event_uuid,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Convenience wrapper: log a 'created' event from a freshly-fetched row array.
	 * before = null, after = $row.
	 */
	public static function log_created( string $entity_type, int $entity_id, array $row, array $opts = [] ): void {
		// Strip internal timestamps from the logged snapshot to keep it clean.
		$snapshot = self::strip_timestamps( $row );
		self::log( $entity_type, $entity_id, 'created', null, $snapshot, $opts );
	}

	/**
	 * Convenience wrapper: log an 'updated' event given before/after row arrays.
	 * Only logs fields that actually changed (diff), reducing storage.
	 */
	public static function log_updated( string $entity_type, int $entity_id, array $before_row, array $after_row, array $opts = [] ): void {
		$diff_before = array();
		$diff_after  = array();

		foreach ( $after_row as $key => $new_val ) {
			$old_val = $before_row[ $key ] ?? null;
			// Compare as strings to catch type coercions from wpdb.
			if ( (string) $old_val !== (string) $new_val ) {
				$diff_before[ $key ] = $old_val;
				$diff_after[ $key ]  = $new_val;
			}
		}

		if ( empty( $diff_after ) ) {
			return; // Nothing changed — skip write.
		}

		self::log( $entity_type, $entity_id, 'updated', $diff_before, $diff_after, $opts );
	}

	/**
	 * Convenience wrapper: log a 'deleted' event (soft-delete).
	 * after = null.
	 */
	public static function log_deleted( string $entity_type, int $entity_id, array $opts = [] ): void {
		self::log( $entity_type, $entity_id, 'deleted', null, null, $opts );
	}

	/* ── private helpers ── */

	private static function resolve_user_label( ?int $user_id ): ?string {
		if ( ! $user_id ) {
			return null;
		}
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : null;
	}

	private static function strip_timestamps( array $row ): array {
		unset( $row['created_at'], $row['updated_at'], $row['deleted_at'] );
		return $row;
	}
}

endif; // class_exists BizCity_CRM_Audit_Log
