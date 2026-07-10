<?php
/**
 * CF7 Channel — Submissions Log
 *
 * CRUD for `bizcity_cf7_submissions`.
 * R-CACHE: group `cf7_sub`, TTL 300s, flush on write.
 *
 * @package BizCity_Channel_Gateway
 * @since   2026-06-13
 *
 * Cache Contract
 * @group   cf7_sub
 * @keys    list_{form_id}_{page}, count_{form_id}, global_list_{page}, detail_{id}
 * @ttl     300
 * @flush   insert, update_crm_result, delete
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_CF7_Submissions_Log {

	const CACHE_GROUP = 'cf7_sub';
	const CACHE_TTL   = 300;

	// ── Write ─────────────────────────────────────────────────────────────

	/**
	 * Insert a new submission row.
	 *
	 * @param  array $data {form_id, form_title, raw_data, mapped_data, email, phone, source_url, user_agent, ip_address}
	 * @return int  Inserted row ID (0 on failure).
	 */
	public static function insert( array $data ): int {
		// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — insert submission + flush cache
		global $wpdb;
		$wpdb->insert(
			BizCity_CF7_Installer::table(),
			array(
				'form_id'      => (int) $data['form_id'],
				'form_title'   => sanitize_text_field( $data['form_title'] ?? '' ),
				'raw_data'     => wp_json_encode( $data['raw_data'] ?? array() ),
				'mapped_data'  => isset( $data['mapped_data'] ) ? wp_json_encode( $data['mapped_data'] ) : null,
				'email'        => sanitize_email( $data['email'] ?? '' ) ?: null,
				'phone'        => isset( $data['phone'] ) ? substr( sanitize_text_field( $data['phone'] ), 0, 32 ) : null,
				'source_url'   => isset( $data['source_url'] ) ? esc_url_raw( $data['source_url'] ) : null,
				'user_agent'   => isset( $data['user_agent'] ) ? substr( sanitize_text_field( $data['user_agent'] ), 0, 255 ) : null,
				'ip_address'   => isset( $data['ip_address'] ) ? substr( sanitize_text_field( $data['ip_address'] ), 0, 45 ) : null,
				'submitted_at' => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
		return $id;
	}

	/**
	 * Update CRM result after upsert attempt.
	 *
	 * @param int   $id
	 * @param array $crm {action, contact_id, error}
	 */
	public static function update_crm_result( int $id, array $crm ): void {
		if ( ! $id ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			BizCity_CF7_Installer::table(),
			array(
				'crm_contact_id' => isset( $crm['contact_id'] ) ? (int) $crm['contact_id'] : null,
				'crm_action'     => sanitize_text_field( $crm['action'] ?? 'error' ),
				'crm_error'      => isset( $crm['error'] ) ? substr( sanitize_text_field( $crm['error'] ), 0, 500 ) : null,
			),
			array( 'id' => $id )
		);
		BizCity_Cache::flush_group( self::CACHE_GROUP );
	}

	// ── Read ──────────────────────────────────────────────────────────────

	/**
	 * Paginated list for one form.
	 */
	public static function get_list( int $form_id, int $page = 1, int $per = 20 ): array {
		$cache_key = "list_{$form_id}_{$page}_{$per}";
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$t      = BizCity_CF7_Installer::table();
		$offset = ( $page - 1 ) * $per;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$t}` WHERE form_id = %d ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
				$form_id,
				$per,
				$offset
			)
		);
		$result = is_array( $rows ) ? $rows : array();
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Count submissions for one form.
	 */
	public static function count( int $form_id ): int {
		$cache_key = "count_{$form_id}";
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		global $wpdb;
		$t   = BizCity_CF7_Installer::table();
		$cnt = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE form_id = %d", $form_id )
		);
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $cnt, self::CACHE_TTL );
		return $cnt;
	}

	/**
	 * Global paginated log (all forms), with optional filters.
	 *
	 * @param array $args {form_id, crm_action, from, to, page, per}
	 */
	public static function get_global_list( array $args = array() ): array {
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per     = min( 100, max( 1, (int) ( $args['per'] ?? 20 ) ) );
		$form_id = isset( $args['form_id'] ) ? (int) $args['form_id'] : 0;
		$action  = sanitize_text_field( $args['crm_action'] ?? '' );
		$from    = sanitize_text_field( $args['from'] ?? '' );
		$to      = sanitize_text_field( $args['to'] ?? '' );

		$cache_key = 'global_' . md5( wp_json_encode( array( $form_id, $action, $from, $to, $page, $per ) ) );
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$t      = BizCity_CF7_Installer::table();
		$where  = array( '1=1' );
		$params = array();

		if ( $form_id ) {
			$where[]  = 'form_id = %d';
			$params[] = $form_id;
		}
		if ( $action ) {
			$where[]  = 'crm_action = %s';
			$params[] = $action;
		}
		if ( $from ) {
			$where[]  = 'submitted_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ( $to ) {
			$where[]  = 'submitted_at <= %s';
			$params[] = $to . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per;
		$params[]  = $per;
		$params[]  = $offset;

		$sql  = "SELECT * FROM `{$t}` WHERE {$where_sql} ORDER BY submitted_at DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$count_sql   = "SELECT COUNT(*) FROM `{$t}` WHERE {$where_sql}";
		$count_params = array_slice( $params, 0, -2 );
		$total        = (int) ( $count_params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) ) : $wpdb->get_var( $count_sql ) );

		$result = array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => $per > 0 ? (int) ceil( $total / $per ) : 1,
		);
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Single submission detail.
	 */
	public static function get_one( int $id ) {
		$cache_key = "detail_{$id}";
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$t   = BizCity_CF7_Installer::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE id = %d", $id ) );
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $row, self::CACHE_TTL );
		return $row;
	}

	// ── Analytics ─────────────────────────────────────────────────────────

	/**
	 * Aggregate stats for the Dashboard.
	 * Returns totals, by_date (last N days), by_form.
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — dashboard analytics
	 *
	 * @param  array $args { days: int (1-365), form_id: int }
	 * @return array { totals, by_date, by_form }
	 */
	public static function get_stats( array $args = array() ) {
		$days    = max( 1, min( 365, (int) ( $args['days'] ?? 30 ) ) );
		$form_id = (int) ( $args['form_id'] ?? 0 );

		$cache_key = 'stats_' . md5( wp_json_encode( array( $days, $form_id ) ) );
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) { return $cached; }

		global $wpdb;
		$t = BizCity_CF7_Installer::table();

		// WHERE clause (no date filter — totals are all-time)
		$where_parts  = array();
		$where_params = array();
		if ( $form_id ) {
			$where_parts[]  = 'form_id = %d';
			$where_params[] = $form_id;
		}
		$where = $where_parts ? ( 'WHERE ' . implode( ' AND ', $where_parts ) ) : '';

		// ── All-time totals ────────────────────────────────────────────────
		$sql         = "SELECT crm_action, COUNT(*) as cnt FROM `{$t}` {$where} GROUP BY crm_action";
		$totals_raw  = $where_params ? $wpdb->get_results( $wpdb->prepare( $sql, $where_params ) ) : $wpdb->get_results( $sql );
		$totals      = array( 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0 );
		foreach ( (array) $totals_raw as $r ) {
			$totals['total'] += (int) $r->cnt;
			$a = (string) $r->crm_action;
			if ( isset( $totals[ $a ] ) ) { $totals[ $a ] = (int) $r->cnt; }
		}

		// ── by_date (last N days) ──────────────────────────────────────────
		$from_dt          = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
		$d_parts          = $where_parts;
		$d_params         = $where_params;
		$d_parts[]        = 'submitted_at >= %s';
		$d_params[]       = $from_dt . ' 00:00:00';
		$d_where          = 'WHERE ' . implode( ' AND ', $d_parts );
		$dsql             = "SELECT DATE(submitted_at) as d, crm_action, COUNT(*) as cnt FROM `{$t}` {$d_where} GROUP BY DATE(submitted_at), crm_action ORDER BY d ASC";
		$by_date_raw      = $wpdb->get_results( $wpdb->prepare( $dsql, $d_params ) );
		$date_map         = array();
		foreach ( (array) $by_date_raw as $r ) {
			$d = (string) $r->d;
			if ( ! isset( $date_map[ $d ] ) ) {
				$date_map[ $d ] = array( 'date' => $d, 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0 );
			}
			$date_map[ $d ]['total'] += (int) $r->cnt;
			$a = (string) $r->crm_action;
			if ( isset( $date_map[ $d ][ $a ] ) ) { $date_map[ $d ][ $a ] = (int) $r->cnt; }
		}
		$by_date = array_values( $date_map );

		// ── by_form ────────────────────────────────────────────────────────
		$fsql        = "SELECT form_id, form_title, crm_action, COUNT(*) as cnt FROM `{$t}` {$where} GROUP BY form_id, form_title, crm_action";
		$by_form_raw = $where_params ? $wpdb->get_results( $wpdb->prepare( $fsql, $where_params ) ) : $wpdb->get_results( $fsql );
		$form_map    = array();
		foreach ( (array) $by_form_raw as $r ) {
			$fid = (int) $r->form_id;
			if ( ! isset( $form_map[ $fid ] ) ) {
				$form_map[ $fid ] = array( 'form_id' => $fid, 'form_title' => (string) $r->form_title, 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0 );
			}
			$form_map[ $fid ]['total'] += (int) $r->cnt;
			$a = (string) $r->crm_action;
			if ( isset( $form_map[ $fid ][ $a ] ) ) { $form_map[ $fid ][ $a ] = (int) $r->cnt; }
		}
		$by_form = array_values( $form_map );

		$result = array( 'totals' => $totals, 'by_date' => $by_date, 'by_form' => $by_form );
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, 120 ); // 2 min TTL
		return $result;
	}

	/**
	 * Fetch all rows matching filters for CSV export (max 5000).
	 *
	 * [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — export
	 *
	 * @param  array $args { form_id, crm_action, from, to }
	 * @return array
	 */
	public static function export_all( array $args = array() ) {
		$form_id = (int) ( $args['form_id'] ?? 0 );
		$action  = sanitize_text_field( $args['crm_action'] ?? '' );
		$from    = sanitize_text_field( $args['from'] ?? '' );
		$to      = sanitize_text_field( $args['to'] ?? '' );

		global $wpdb;
		$t      = BizCity_CF7_Installer::table();
		$where  = array( '1=1' );
		$params = array();
		if ( $form_id ) { $where[] = 'form_id = %d'; $params[] = $form_id; }
		if ( $action )  { $where[] = 'crm_action = %s'; $params[] = $action; }
		if ( $from )    { $where[] = 'submitted_at >= %s'; $params[] = $from . ' 00:00:00'; }
		if ( $to )      { $where[] = 'submitted_at <= %s'; $params[] = $to . ' 23:59:59'; }

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM `{$t}` WHERE {$where_sql} ORDER BY submitted_at DESC LIMIT 5000";
		$rows      = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		return is_array( $rows ) ? $rows : array();
	}
}
