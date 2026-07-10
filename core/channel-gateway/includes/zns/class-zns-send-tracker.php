<?php
/**
 * ZNS Send Tracker — Ghi log gửi, thống kê, và đọc JSONL file logs.
 *
 * Sử dụng bảng hiện có `bizcity_crm_zns_send_logs` (từ bizcity-twin-crm).
 * Self-heal thêm cột mới nếu thiếu: rule_id, event_key, source_object_id, source_object_type.
 *
 * Cache Contract
 * @group  zns_sends
 * @keys   stats_{period}, sends_{page}_{hash}
 * @ttl    BizCity_Cache::TTL_SHORT (60s)
 * @flush  record()
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-ZNS-AUTO (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_ZNS_Send_Tracker' ) ) {
	return;
}

class BizCity_ZNS_Send_Tracker {

	const CACHE_GROUP = 'zns_sends';

	/**
	 * Tên bảng ZNS send logs (dùng bảng của bizcity-twin-crm).
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_crm_zns_send_logs';
	}

	/**
	 * Kiểm tra bảng tồn tại (information_schema, R-SHOW-TABLES).
	 *
	 * @return bool
	 */
	public static function table_exists() {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — information_schema dual cache
		static $cache = array();
		$tbl = self::table();
		if ( isset( $cache[ $tbl ] ) ) {
			return $cache[ $tbl ];
		}
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $tbl );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$tbl
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$cache[ $tbl ] = (bool) $present;
		return $cache[ $tbl ];
	}

	/**
	 * Self-heal: thêm các cột mới nếu bảng tồn tại nhưng chưa có cột.
	 * Chỉ ADD COLUMN (idempotent), không DROP/MODIFY.
	 *
	 * @return void
	 */
	public static function maybe_add_columns() {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — self-heal columns
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		if ( ! self::table_exists() ) {
			return;
		}
		global $wpdb;
		$tbl = self::table();
		$existing = $wpdb->get_col( "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tbl}'" );
		$new_cols = array(
			'rule_id'            => "ALTER TABLE `{$tbl}` ADD COLUMN `rule_id` BIGINT UNSIGNED NULL DEFAULT NULL",
			'event_key'          => "ALTER TABLE `{$tbl}` ADD COLUMN `event_key` VARCHAR(100) NULL DEFAULT NULL",
			'source_object_id'   => "ALTER TABLE `{$tbl}` ADD COLUMN `source_object_id` BIGINT UNSIGNED NULL DEFAULT NULL",
			'source_object_type' => "ALTER TABLE `{$tbl}` ADD COLUMN `source_object_type` VARCHAR(32) NULL DEFAULT NULL",
		);
		foreach ( $new_cols as $col => $sql ) {
			if ( ! in_array( $col, $existing, true ) ) {
				$wpdb->query( $sql );
			}
		}
	}

	/**
	 * Ghi 1 send attempt vào DB.
	 *
	 * @param  array $data {
	 *   rule_id, event_key, phone (masked), temp_id, oa_id, esms_code,
	 *   sms_id, error_msg, success, sandbox, source_object_id, source_object_type
	 * }
	 * @return int Insert ID.
	 */
	public static function record( array $data ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — record send + flush cache
		if ( ! self::table_exists() ) {
			return 0;
		}
		self::maybe_add_columns();
		global $wpdb;
		$insert = array(
			'form_id'            => 0, // N/A for event-driven rules
			'form_title'         => '', // N/A
			'phone'              => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
			'temp_id'            => sanitize_text_field( (string) ( $data['temp_id'] ?? '' ) ),
			'oa_id'              => sanitize_text_field( (string) ( $data['oa_id'] ?? '' ) ),
			'status'             => ! empty( $data['success'] ) ? 'sent' : 'failed',
			'esms_code'          => sanitize_text_field( (string) ( $data['esms_code'] ?? '' ) ),
			'sms_id'             => sanitize_text_field( (string) ( $data['sms_id'] ?? '' ) ),
			'error_message'      => ! empty( $data['error_msg'] ) ? substr( (string) $data['error_msg'], 0, 255 ) : null,
			'temp_data'          => null,
			'is_test'            => ! empty( $data['sandbox'] ) ? 1 : 0,
			'sent_at'            => current_time( 'mysql', true ),
		);
		// Add self-heal columns if they exist
		if ( isset( $data['rule_id'] ) ) {
			$insert['rule_id'] = (int) $data['rule_id'];
		}
		if ( isset( $data['event_key'] ) ) {
			$insert['event_key'] = sanitize_key( (string) $data['event_key'] );
		}
		if ( isset( $data['source_object_id'] ) ) {
			$insert['source_object_id'] = (int) $data['source_object_id'];
		}
		if ( isset( $data['source_object_type'] ) ) {
			$insert['source_object_type'] = sanitize_text_field( (string) $data['source_object_type'] );
		}
		$wpdb->insert( self::table(), $insert );
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
		return $id;
	}

	/**
	 * Thống kê dashboard.
	 *
	 * @param  string $period  '7d', '30d', '90d'
	 * @return array { total, success, failed, success_rate, by_event[], by_day[], top_errors[] }
	 */
	public static function get_stats( $period = '30d' ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — stats với cache TTL_SHORT
		if ( ! self::table_exists() ) {
			return array( 'total' => 0, 'success' => 0, 'failed' => 0, 'success_rate' => 0.0 );
		}
		$cache_key = 'stats_' . sanitize_key( $period );
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$days     = self::period_to_days( $period );
		$since    = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );
		$tbl      = self::table();
		$has_event_col = self::column_exists( 'event_key' );

		// Totals
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
				        SUM(status = 'sent') AS success,
				        SUM(status = 'failed') AS failed
				 FROM {$tbl}
				 WHERE sent_at >= %s",
				$since
			),
			ARRAY_A
		);
		$total   = (int) ( $row['total'] ?? 0 );
		$success = (int) ( $row['success'] ?? 0 );
		$failed  = (int) ( $row['failed'] ?? 0 );

		// By event
		$by_event = array();
		if ( $has_event_col ) {
			$by_event = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT event_key,
					        COUNT(*) AS total,
					        SUM(status = 'sent') AS success
					 FROM {$tbl}
					 WHERE sent_at >= %s AND event_key IS NOT NULL
					 GROUP BY event_key
					 ORDER BY total DESC
					 LIMIT 20",
					$since
				),
				ARRAY_A
			) ?: array();
		}

		// By day (last N days)
		$by_day = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(sent_at) AS date,
				        COUNT(*) AS total,
				        SUM(status = 'sent') AS success
				 FROM {$tbl}
				 WHERE sent_at >= %s
				 GROUP BY DATE(sent_at)
				 ORDER BY date ASC
				 LIMIT 90",
				$since
			),
			ARRAY_A
		) ?: array();

		// Top errors
		$top_errors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT esms_code, COUNT(*) AS count
				 FROM {$tbl}
				 WHERE sent_at >= %s AND status = 'failed' AND esms_code != ''
				 GROUP BY esms_code
				 ORDER BY count DESC
				 LIMIT 10",
				$since
			),
			ARRAY_A
		) ?: array();

		$result = array(
			'period'        => $period,
			'total'         => $total,
			'success'       => $success,
			'failed'        => $failed,
			'success_rate'  => $total > 0 ? round( ( $success / $total ) * 100, 1 ) : 0.0,
			'by_event'      => $by_event,
			'by_day'        => $by_day,
			'top_errors'    => $top_errors,
		);
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, BizCity_Cache::TTL_SHORT );
		return $result;
	}

	/**
	 * Danh sách gửi phân trang.
	 *
	 * @param  array $filters { event_key, rule_id, success, date_from, date_to }
	 * @param  int   $page
	 * @param  int   $per
	 * @return array { items[], total, page, per_page }
	 */
	public static function get_list( array $filters = array(), $page = 1, $per = 50 ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — paginated list
		if ( ! self::table_exists() ) {
			return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => $per );
		}
		global $wpdb;
		$tbl    = self::table();
		$wheres = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['event_key'] ) && self::column_exists( 'event_key' ) ) {
			$wheres[] = 'event_key = %s';
			$params[] = sanitize_key( $filters['event_key'] );
		}
		if ( isset( $filters['rule_id'] ) && $filters['rule_id'] && self::column_exists( 'rule_id' ) ) {
			$wheres[] = 'rule_id = %d';
			$params[] = (int) $filters['rule_id'];
		}
		if ( isset( $filters['success'] ) && $filters['success'] !== '' ) {
			$wheres[] = 'status = %s';
			$params[] = $filters['success'] ? 'sent' : 'failed';
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$wheres[] = 'sent_at >= %s';
			$params[] = sanitize_text_field( $filters['date_from'] ) . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$wheres[] = 'sent_at <= %s';
			$params[] = sanitize_text_field( $filters['date_to'] ) . ' 23:59:59';
		}

		$where = implode( ' AND ', $wheres );
		$offset = ( (int) $page - 1 ) * (int) $per;

		if ( ! empty( $params ) ) {
			$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE {$where}", $params );
			$list_sql  = $wpdb->prepare( "SELECT * FROM {$tbl} WHERE {$where} ORDER BY sent_at DESC LIMIT %d OFFSET %d", array_merge( $params, array( (int) $per, $offset ) ) );
		} else {
			$count_sql = "SELECT COUNT(*) FROM {$tbl}";
			$list_sql  = $wpdb->prepare( "SELECT * FROM {$tbl} ORDER BY sent_at DESC LIMIT %d OFFSET %d", array( (int) $per, $offset ) );
		}

		$total = (int) $wpdb->get_var( $count_sql );
		$items = $wpdb->get_results( $list_sql, ARRAY_A ) ?: array();

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => (int) $page,
			'per_page' => (int) $per,
		);
	}

	/**
	 * Export tất cả rows theo filters (raw array để caller format CSV/xlsx).
	 *
	 * @param  array  $filters
	 * @return array
	 */
	public static function export( array $filters = array() ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — export all for CSV/xlsx
		$result = self::get_list( $filters, 1, 10000 );
		return $result['items'];
	}

	/**
	 * Đọc JSONL file logs (multisite-aware, per-day).
	 *
	 * @param  string $date   Format Y-m-d. Mặc định: hôm nay.
	 * @param  int    $limit  Số dòng tối đa.
	 * @param  string $level  Filter level: 'info', 'warn', 'error', '' = tất cả.
	 * @param  string $event  Filter event name (contains match).
	 * @return array { entries[], date, file_exists, total }
	 */
	public static function read_file_logs( $date = '', $limit = 500, $level = '', $event = '' ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — JSONL file reader
		$date    = $date ? preg_replace( '/[^0-9\-]/', '', $date ) : gmdate( 'Y-m-d' );
		$upload  = wp_upload_dir();
		$dir     = trailingslashit( $upload['basedir'] ) . 'bizcity-channel-logs/zalo_oa/';
		$file    = $dir . $date . '.jsonl';

		if ( ! file_exists( $file ) ) {
			return array( 'entries' => array(), 'date' => $date, 'file_exists' => false, 'total' => 0 );
		}

		$lines   = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$entries = array();
		$count   = 0;

		foreach ( (array) $lines as $line ) {
			$entry = json_decode( $line, true );
			if ( ! is_array( $entry ) ) {
				continue;
			}
			// Filter level
			if ( $level && strtolower( $entry['level'] ?? '' ) !== strtolower( $level ) ) {
				continue;
			}
			// Filter event (contains)
			if ( $event && strpos( (string) ( $entry['event'] ?? '' ), $event ) === false ) {
				continue;
			}
			$entries[] = $entry;
			$count++;
			if ( $count >= $limit ) {
				break;
			}
		}

		return array(
			'entries'     => $entries,
			'date'        => $date,
			'file_exists' => true,
			'total'       => count( $lines ),
		);
	}

	/**
	 * Danh sách các ngày có file log.
	 *
	 * @return array  Danh sách date string 'Y-m-d', mới nhất trước.
	 */
	public static function get_log_dates() {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — list log file dates
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'bizcity-channel-logs/zalo_oa/';
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = glob( $dir . '*.jsonl' );
		if ( empty( $files ) ) {
			return array();
		}
		$dates = array();
		foreach ( $files as $f ) {
			$base = basename( $f, '.jsonl' );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $base ) ) {
				$dates[] = $base;
			}
		}
		rsort( $dates );
		return array_slice( $dates, 0, 90 );
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * @param  string $period
	 * @return int
	 */
	private static function period_to_days( $period ) {
		$map = array( '7d' => 7, '30d' => 30, '90d' => 90 );
		return isset( $map[ $period ] ) ? $map[ $period ] : 30;
	}

	/**
	 * Kiểm tra cột có tồn tại trong bảng.
	 *
	 * @param  string $col
	 * @return bool
	 */
	private static function column_exists( $col ) {
		static $cols = null;
		if ( null === $cols ) {
			global $wpdb;
			$tbl  = self::table();
			$cols = $wpdb->get_col(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tbl}'"
			);
			$cols = $cols ?: array();
		}
		return in_array( $col, $cols, true );
	}
}

// ── R-CACHE Registry ──────────────────────────────────────────────────────────
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'zns_sends', 'modules.zns-automation', array(
		'stats_{period}'        => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Dashboard stats for period' ),
		'sends_{page}_{hash}'   => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Paginated send list' ),
	) );
}
