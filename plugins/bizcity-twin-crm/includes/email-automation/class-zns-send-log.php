<?php
/**
 * BizCity CRM — ZNS Send Log Repo (PHASE-CG-CF7-ZNS)
 *
 * Writes one row per ZNS (Zalo Notification Service) send attempt
 * triggered from CF7 form submissions via the eSMS API.
 *
 * Mirrors the structure of BizCity_CRM_Email_Send_Log.
 *
 * @package BizCity_Twin_CRM
 * @since   1.30.0
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — ZNS send log repo

class BizCity_CRM_ZNS_Send_Log {

	/** @var bool|null */
	private static $table_exists_cache = null;

	private static function table(): string {
		return BizCity_CRM_DB_Installer_V2::tbl_zns_send_logs();
	}

	private static function table_exists(): bool {
		if ( null !== self::$table_exists_cache ) {
			return (bool) self::$table_exists_cache;
		}
		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — use information_schema per R-SHOW-TABLES (CẤM SHOW TABLES)
		global $wpdb;
		$tbl     = self::table();
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $tbl );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$tbl
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		self::$table_exists_cache = (bool) $present;
		return self::$table_exists_cache;
	}

	/**
	 * Write one ZNS send-log row.
	 *
	 * @param array $data {
	 *   form_id      (int),   form_title (string),
	 *   phone        (string, masked OK),
	 *   temp_id      (string),
	 *   oa_id        (string),
	 *   status       ('sent'|'failed'|'skipped'),
	 *   esms_code    (string),
	 *   sms_id       (string),
	 *   error_message(string),
	 *   temp_data    (array|null — JSON-encoded before write),
	 *   is_test      (0|1),
	 * }
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public static function write( array $data ): int {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$tbl = self::table();
		$row = array(
			'form_id'       => (int)    ( $data['form_id']       ?? 0 ),
			'form_title'    => (string) ( $data['form_title']    ?? '' ),
			'phone'         => (string) ( $data['phone']         ?? '' ),
			'temp_id'       => (string) ( $data['temp_id']       ?? '' ),
			'oa_id'         => (string) ( $data['oa_id']         ?? '' ),
			'status'        => (string) ( $data['status']        ?? 'sent' ),
			'esms_code'     => (string) ( $data['esms_code']     ?? '' ),
			'sms_id'        => (string) ( $data['sms_id']        ?? '' ),
			'error_message' => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
			'temp_data'     => isset( $data['temp_data'] ) ? wp_json_encode( $data['temp_data'] ) : null,
			'is_test'       => (int) ( $data['is_test'] ?? 0 ),
			'sent_at'       => current_time( 'mysql' ),
		);
		$ok = $wpdb->insert( $tbl, $row );
		if ( false === $ok ) {
			error_log( '[bizcity-crm] zns_send_log insert failed: ' . (string) $wpdb->last_error );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * List ZNS logs with filters + pagination.
	 *
	 * @param array $args {
	 *   status (string), form_id (int), date_from (Y-m-d), date_to (Y-m-d),
	 *   is_test (0|1|-1 for all), per_page (int), page (int)
	 * }
	 * @return array{ rows: array, total: int }
	 */
	public static function list_logs( array $args = array() ): array {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$tbl    = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$params[] = (int) $args['form_id'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'sent_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'sent_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}
		if ( isset( $args['is_test'] ) && (int) $args['is_test'] >= 0 ) {
			$where[]  = 'is_test = %d';
			$params[] = (int) $args['is_test'];
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset    = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM `{$tbl}` WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows_sql  = "SELECT id, form_id, form_title, phone, temp_id, oa_id, status, esms_code, sms_id, error_message, is_test, sent_at FROM `{$tbl}` WHERE {$where_sql} ORDER BY sent_at DESC LIMIT %d OFFSET %d";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore
		} else {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, $per_page, $offset ), ARRAY_A );
		}

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Stats summary: total_sent, total_failed, success_rate, by_day[], by_form[].
	 *
	 * @param string $period 'today'|'7d'|'30d'|'all'
	 */
	public static function get_stats( string $period = '7d' ): array {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return array(
				'total_sent'   => 0,
				'total_failed' => 0,
				'success_rate' => 0,
				'by_day'       => array(),
				'by_form'      => array(),
			);
		}
		$tbl  = self::table();
		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — date filter for stats period
		$date_filter = '';
		switch ( $period ) {
			case 'today':
				$date_filter = "AND DATE(sent_at) = CURDATE()";
				break;
			case '7d':
				$date_filter = "AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
				break;
			case '30d':
				$date_filter = "AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
				break;
			default:
				$date_filter = '';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$totals = $wpdb->get_row( "SELECT
			SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS total_sent,
			SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS total_failed,
			COUNT(*) AS total_all
			FROM `{$tbl}` WHERE is_test=0 {$date_filter}", ARRAY_A );

		$total_sent   = (int) ( $totals['total_sent']   ?? 0 );
		$total_failed = (int) ( $totals['total_failed'] ?? 0 );
		$total_all    = (int) ( $totals['total_all']    ?? 0 );
		$success_rate = $total_all > 0 ? round( $total_sent / $total_all * 100, 1 ) : 0.0;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_form = $wpdb->get_results( "SELECT form_id, form_title,
			SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
			SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
			FROM `{$tbl}` WHERE is_test=0 {$date_filter}
			GROUP BY form_id, form_title ORDER BY sent DESC LIMIT 20", ARRAY_A );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_day_raw = $wpdb->get_results( "SELECT DATE(sent_at) AS day,
			SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
			SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
			FROM `{$tbl}` WHERE is_test=0 {$date_filter}
			GROUP BY DATE(sent_at) ORDER BY day ASC", ARRAY_A );

		$by_day = array();
		foreach ( ( is_array( $by_day_raw ) ? $by_day_raw : array() ) as $r ) {
			$by_day[] = array(
				'day'    => $r['day'],
				'sent'   => (int) $r['sent'],
				'failed' => (int) $r['failed'],
			);
		}

		return array(
			'total_sent'   => $total_sent,
			'total_failed' => $total_failed,
			'success_rate' => $success_rate,
			'by_day'       => $by_day,
			'by_form'      => is_array( $by_form ) ? array_map( function ( $r ) {
				return array(
					'form_id'    => (int) $r['form_id'],
					'form_title' => $r['form_title'],
					'sent'       => (int) $r['sent'],
					'failed'     => (int) $r['failed'],
				);
			}, $by_form ) : array(),
		);
	}
}
