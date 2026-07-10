<?php
/**
 * BizCity CRM — Email Send Log Repo (PHASE-CG-CF7-LOG)
 *
 * Writes one row per email send attempt (success / failed / skipped).
 * Used by EmailDispatcher and REST export endpoint.
 *
 * @package BizCity_Twin_CRM
 * @since   0.37.2
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — email send log repo

class BizCity_CRM_Email_Send_Log {
	/** @var bool|null */
	private static $table_exists_cache = null;

	private static function prefer_jsonl_as_source(): bool {
		// [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG — default source for Email Client history is JSONL
		return (bool) apply_filters( 'bizcity_crm_email_logs_prefer_jsonl', true );
	}

	private static function table(): string {
		return BizCity_CRM_DB_Installer_V2::tbl_email_send_logs();
	}

	private static function table_exists(): bool {
		if ( null !== self::$table_exists_cache ) {
			return (bool) self::$table_exists_cache;
		}
		global $wpdb;
		$tbl = self::table();
		// [2026-06-20 Johnny Chu] HOTFIX — cache table existence to avoid repeated SHOW TABLES calls
		self::$table_exists_cache = ( $wpdb->get_var( "SHOW TABLES LIKE '{$tbl}'" ) === $tbl ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (bool) self::$table_exists_cache;
	}

	/**
	 * Write one send-log row.
	 *
	 * @param array $data {
	 *   rule_id, rule_name, event_key, recipient_email, subject,
	 *   status ('sent'|'failed'|'skipped'), error_message,
	 *   smtp_source ('crm_gmail'|'wp_mail'), has_attachment, is_test
	 * }
	 */
	public static function write( array $data ): int {
		global $wpdb;
		$tbl = self::table();
		// Bail silently if table doesn't exist yet (pre-migration).
		if ( ! self::table_exists() ) {
			return 0;
		}
		$row = array(
			'rule_id'         => (int)    ( $data['rule_id']         ?? 0 ),
			'rule_name'       => (string) ( $data['rule_name']       ?? '' ),
			'event_key'       => (string) ( $data['event_key']       ?? '' ),
			'recipient_email' => (string) ( $data['recipient_email'] ?? '' ),
			'subject'         => (string) ( $data['subject']         ?? '' ),
			'status'          => (string) ( $data['status']          ?? 'sent' ),
			'error_message'   => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
			'smtp_source'     => (string) ( $data['smtp_source']     ?? '' ),
			'has_attachment'  => (int)    ( $data['has_attachment']  ?? 0 ),
			'is_test'         => (int)    ( $data['is_test']         ?? 0 ),
			'sent_at'         => current_time( 'mysql' ),
		);
		$ok = $wpdb->insert( $tbl, $row );
		if ( false === $ok ) {
			// [2026-06-20 Johnny Chu] HOTFIX — keep fail-open behavior but leave DB error breadcrumb for diagnostics
			error_log( '[bizcity-crm] email_send_log insert failed: ' . (string) $wpdb->last_error );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * List logs with optional filters + pagination.
	 *
	 * @param array $args {
	 *   status (string), rule_id (int), event_key (string),
	 *   date_from (Y-m-d), date_to (Y-m-d), is_test (0|1|-1 for all),
	 *   per_page (int), page (int)
	 * }
	 * @return array{ rows: array, total: int }
	 */
	public static function list_logs( array $args = array() ): array {
		// [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG — JSONL-first read path by default
		if ( self::prefer_jsonl_as_source() && class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return self::list_logs_from_file( $args );
		}

		global $wpdb;
		$tbl = self::table();
		if ( ! self::table_exists() ) {
			return array( 'rows' => array(), 'total' => 0 );
		}

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['rule_id'] ) ) {
			$where[]  = 'rule_id = %d';
			$params[] = (int) $args['rule_id'];
		}
		if ( ! empty( $args['event_key'] ) ) {
			$where[]  = 'event_key = %s';
			$params[] = $args['event_key'];
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
		$rows_sql  = "SELECT * FROM `{$tbl}` WHERE {$where_sql} ORDER BY sent_at DESC LIMIT %d OFFSET %d";

		if ( ! empty( $params ) ) {
			$p = array_merge( $params, $params, array( $per_page, $offset ) );
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
	 * Stats summary: total_sent, total_failed, by_rule[], by_event[], success_rate.
	 *
	 * @param string $period 'today'|'7d'|'30d'|'all'
	 */
	public static function get_stats( string $period = '7d' ): array {
		// [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG — JSONL-first stats by default
		if ( self::prefer_jsonl_as_source() && class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return self::get_stats_from_file( $period );
		}

		global $wpdb;
		$tbl = self::table();
		if ( ! self::table_exists() ) {
			return array( 'total_sent' => 0, 'total_failed' => 0, 'success_rate' => 0, 'by_rule' => array(), 'by_event' => array(), 'by_day' => array() );
		}

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
		$success_rate = $total_all > 0 ? round( $total_sent / $total_all * 100, 1 ) : 0;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_rule = $wpdb->get_results( "SELECT rule_id, rule_name,
			SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
			SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
			FROM `{$tbl}` WHERE is_test=0 {$date_filter}
			GROUP BY rule_id, rule_name ORDER BY sent DESC LIMIT 20", ARRAY_A );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_event = $wpdb->get_results( "SELECT event_key,
			SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
			SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
			FROM `{$tbl}` WHERE is_test=0 {$date_filter}
			GROUP BY event_key ORDER BY sent DESC", ARRAY_A );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_day = $wpdb->get_results( "SELECT DATE(sent_at) AS day,
			SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
			SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
			FROM `{$tbl}` WHERE is_test=0 {$date_filter}
			GROUP BY DATE(sent_at) ORDER BY day ASC LIMIT 30", ARRAY_A );

		return array(
			'total_sent'   => $total_sent,
			'total_failed' => $total_failed,
			'success_rate' => $success_rate,
			'by_rule'      => is_array( $by_rule )  ? $by_rule  : array(),
			'by_event'     => is_array( $by_event ) ? $by_event : array(),
			'by_day'       => is_array( $by_day )   ? $by_day   : array(),
		);
	}

	/** Export rows as CSV string. */
	public static function export_csv( array $args = array() ): string {
		$args['per_page'] = 5000;
		$args['page']     = 1;
		$data = self::list_logs( $args );
		$rows = $data['rows'];

		$cols = array( 'id', 'sent_at', 'rule_name', 'event_key', 'recipient_email', 'subject', 'status', 'error_message', 'smtp_source', 'has_attachment', 'is_test' );
		ob_start();
		$f = fopen( 'php://output', 'w' );
		fputcsv( $f, $cols );
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $cols as $c ) {
				$line[] = isset( $r[ $c ] ) ? $r[ $c ] : '';
			}
			fputcsv( $f, $line );
		}
		fclose( $f );
		return ob_get_clean();
	}

	/**
	 * Build paginated rows from JSONL channel logs (fallback path).
	 *
	 * @return array{rows:array,total:int}
	 */
	private static function list_logs_from_file( array $args = array() ): array {
		$rows = self::collect_file_rows( $args );
		$total = count( $rows );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset = ( $page - 1 ) * $per_page;
		return array(
			'rows'  => array_slice( $rows, $offset, $per_page ),
			'total' => $total,
		);
	}

	private static function get_stats_from_file( string $period = '7d' ): array {
		$rows = self::collect_file_rows( array( 'is_test' => 0 ) );
		$rows = self::filter_rows_by_period( $rows, $period );

		$total_sent = 0;
		$total_failed = 0;
		$by_rule_map = array();
		$by_event_map = array();
		$by_day_map = array();

		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( $status === 'sent' ) { $total_sent++; }
			if ( $status === 'failed' ) { $total_failed++; }

			$rule_id = (int) ( $row['rule_id'] ?? 0 );
			$rule_name = (string) ( $row['rule_name'] ?? '' );
			$rule_key = $rule_id . '|' . $rule_name;
			if ( ! isset( $by_rule_map[ $rule_key ] ) ) {
				$by_rule_map[ $rule_key ] = array(
					'rule_id' => $rule_id,
					'rule_name' => $rule_name,
					'sent' => 0,
					'failed' => 0,
				);
			}
			if ( $status === 'sent' ) { $by_rule_map[ $rule_key ]['sent']++; }
			if ( $status === 'failed' ) { $by_rule_map[ $rule_key ]['failed']++; }

			$event_key = (string) ( $row['event_key'] ?? '' );
			if ( $event_key === '' ) { $event_key = 'unknown'; }
			if ( ! isset( $by_event_map[ $event_key ] ) ) {
				$by_event_map[ $event_key ] = array( 'event_key' => $event_key, 'sent' => 0, 'failed' => 0 );
			}
			if ( $status === 'sent' ) { $by_event_map[ $event_key ]['sent']++; }
			if ( $status === 'failed' ) { $by_event_map[ $event_key ]['failed']++; }

			$day = substr( (string) ( $row['sent_at'] ?? '' ), 0, 10 );
			if ( $day === '' ) { $day = gmdate( 'Y-m-d' ); }
			if ( ! isset( $by_day_map[ $day ] ) ) {
				$by_day_map[ $day ] = array( 'day' => $day, 'sent' => 0, 'failed' => 0 );
			}
			if ( $status === 'sent' ) { $by_day_map[ $day ]['sent']++; }
			if ( $status === 'failed' ) { $by_day_map[ $day ]['failed']++; }
		}

		$total_all = $total_sent + $total_failed;
		$success_rate = $total_all > 0 ? round( $total_sent / $total_all * 100, 1 ) : 0;

		$by_rule = array_values( $by_rule_map );
		usort( $by_rule, static function ( $a, $b ) {
			$as = (int) ( $a['sent'] ?? 0 );
			$bs = (int) ( $b['sent'] ?? 0 );
			if ( $as === $bs ) { return 0; }
			return ( $as < $bs ) ? 1 : -1;
		} );
		$by_rule = array_slice( $by_rule, 0, 20 );

		$by_event = array_values( $by_event_map );
		usort( $by_event, static function ( $a, $b ) {
			$as = (int) ( $a['sent'] ?? 0 );
			$bs = (int) ( $b['sent'] ?? 0 );
			if ( $as === $bs ) { return 0; }
			return ( $as < $bs ) ? 1 : -1;
		} );

		$by_day = array_values( $by_day_map );
		usort( $by_day, static function ( $a, $b ) {
			return strcmp( (string) $a['day'], (string) $b['day'] );
		} );
		if ( count( $by_day ) > 30 ) {
			$by_day = array_slice( $by_day, -30 );
		}

		return array(
			'total_sent'   => $total_sent,
			'total_failed' => $total_failed,
			'success_rate' => $success_rate,
			'by_rule'      => $by_rule,
			'by_event'     => $by_event,
			'by_day'       => $by_day,
		);
	}

	/**
	 * Per-CF7-form campaign stats: one row per CF7 form that triggered emails.
	 * Each CF7 form is treated as an independent "campaign".
	 *
	 * [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG — CF7 campaign breakdown for email report
	 *
	 * @param string $period 'today'|'7d'|'30d'|'all'
	 * @return array{ forms: array, period: string }
	 */
	public static function get_cf7_campaign_stats( string $period = '7d' ): array {
		// Load CF7 form titles saved by Channel Gateway field-mapping config.
		$cf7_mappings = get_option( 'bizcity_cg_cf7_mappings', array() );
		if ( ! is_array( $cf7_mappings ) ) { $cf7_mappings = array(); }

		if ( self::prefer_jsonl_as_source() && class_exists( 'BizCity_Channel_File_Logger' ) ) {
			$rows = self::collect_file_rows( array( 'is_test' => 0 ) );
			$rows = self::filter_rows_by_period( $rows, $period );
		} else {
			$rows = self::collect_cf7_rows_from_db( $period );
		}

		$form_map = array();
		foreach ( $rows as $row ) {
			$event_key = (string) ( $row['event_key'] ?? '' );
			if ( strpos( $event_key, 'cf7_form' ) !== 0 ) { continue; }
			$status  = (string) ( $row['status']  ?? '' );
			$sent_at = (string) ( $row['sent_at'] ?? '' );

			if ( ! isset( $form_map[ $event_key ] ) ) {
				$form_id = 0;
				if ( $event_key !== 'cf7_form_submitted' ) {
					// e.g. 'cf7_form_6050' → form_id = 6050
					$parts   = explode( '_', $event_key );
					$form_id = (int) end( $parts );
				}
				$form_title = '';
				if ( $form_id > 0 ) {
					$form_title = (string) ( $cf7_mappings[ (string) $form_id ]['form_title'] ?? '' );
				}
				if ( $form_title === '' ) {
					$form_title = ( $event_key === 'cf7_form_submitted' )
						? 'Tất cả CF7 (Generic)'
						: ( $form_id > 0 ? 'Form #' . $form_id : $event_key );
				}
				$form_map[ $event_key ] = array(
					'event_key'    => $event_key,
					'form_id'      => $form_id,
					'form_title'   => $form_title,
					'sent'         => 0,
					'failed'       => 0,
					'last_sent_at' => '',
				);
			}

			if ( $status === 'sent' )   { $form_map[ $event_key ]['sent']++; }
			if ( $status === 'failed' ) { $form_map[ $event_key ]['failed']++; }
			if ( $sent_at > $form_map[ $event_key ]['last_sent_at'] ) {
				$form_map[ $event_key ]['last_sent_at'] = $sent_at;
			}
		}

		$forms = array_values( $form_map );
		foreach ( $forms as &$f ) {
			$total             = $f['sent'] + $f['failed'];
			$f['total']        = $total;
			$f['success_rate'] = $total > 0 ? round( $f['sent'] / $total * 100, 1 ) : 0.0;
		}
		unset( $f );
		usort( $forms, static function ( $a, $b ) {
			$as = (int) ( $a['sent'] ?? 0 );
			$bs = (int) ( $b['sent'] ?? 0 );
			if ( $as === $bs ) { return 0; }
			return ( $as < $bs ) ? 1 : -1;
		} );

		return array( 'forms' => $forms, 'period' => $period );
	}

	/**
	 * Collect CF7 send rows from DB (fallback when JSONL not available).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function collect_cf7_rows_from_db( string $period ): array {
		global $wpdb;
		$tbl = self::table();
		if ( ! self::table_exists() ) { return array(); }

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
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT event_key, status, sent_at FROM `{$tbl}`
			  WHERE is_test = 0 AND event_key LIKE 'cf7_form%' {$date_filter}
			  ORDER BY sent_at ASC",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	private static function collect_file_rows( array $args = array() ): array {
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return array();
		}

		$is_test = isset( $args['is_test'] ) ? (int) $args['is_test'] : -1;
		if ( $is_test === 1 ) {
			return array();
		}

		$status_filter = (string) ( $args['status'] ?? '' );
		$rule_id_filter = ! empty( $args['rule_id'] ) ? (int) $args['rule_id'] : 0;
		$event_key_filter = (string) ( $args['event_key'] ?? '' );
		$date_from = (string) ( $args['date_from'] ?? '' );
		$date_to = (string) ( $args['date_to'] ?? '' );

		$rows = array();
		$dates = BizCity_Channel_File_Logger::list_dates( BizCity_Channel_File_Logger::CH_EMAIL, 90 );
		$id = 1;

		foreach ( $dates as $date ) {
			$entries = BizCity_Channel_File_Logger::read( BizCity_Channel_File_Logger::CH_EMAIL, (string) $date, 0 );
			foreach ( $entries as $entry ) {
				$event = (string) ( $entry['event'] ?? '' );
				$status = '';
				if ( $event === 'send_ok' ) {
					$status = 'sent';
				} elseif ( $event === 'send_failed' ) {
					$status = 'failed';
				} elseif ( $event === 'send_skipped' ) {
					$status = 'skipped';
				} else {
					continue;
				}

				$ctx = is_array( $entry['ctx'] ?? null ) ? (array) $entry['ctx'] : array();
				$sent_at = (string) ( $entry['ts'] ?? '' );
				$day = substr( $sent_at, 0, 10 );

				if ( $status_filter !== '' && $status_filter !== $status ) {
					continue;
				}
				if ( $date_from !== '' && $day !== '' && $day < $date_from ) {
					continue;
				}
				if ( $date_to !== '' && $day !== '' && $day > $date_to ) {
					continue;
				}

				$rule_id = (int) ( $ctx['rule_id'] ?? 0 );
				if ( $rule_id_filter > 0 && $rule_id_filter !== $rule_id ) {
					continue;
				}

				$event_key = (string) ( $ctx['event_key'] ?? '' );
				if ( $event_key_filter !== '' && $event_key_filter !== $event_key ) {
					continue;
				}

				$rows[] = array(
					'id'              => $id++,
					'sent_at'         => $sent_at,
					'rule_id'         => $rule_id,
					'rule_name'       => (string) ( $ctx['rule_name'] ?? '' ),
					'event_key'       => $event_key,
					'recipient_email' => (string) ( $ctx['to'] ?? ( $ctx['recipient_email'] ?? '' ) ),
					'subject'         => (string) ( $ctx['subject'] ?? '' ),
					'status'          => $status,
					'error_message'   => (string) ( $ctx['error'] ?? '' ),
					'smtp_source'     => (string) ( $ctx['smtp_source'] ?? '' ),
					'has_attachment'  => ! empty( $ctx['has_attachment'] ) ? 1 : 0,
					'is_test'         => 0,
				);
			}
		}

		return $rows;
	}

	/**
	 * Filter already-normalized rows by period.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private static function filter_rows_by_period( array $rows, string $period ): array {
		if ( $period === 'all' ) {
			return $rows;
		}

		$start_ts = 0;
		if ( $period === 'today' ) {
			$start_ts = strtotime( gmdate( 'Y-m-d 00:00:00' ) );
		} elseif ( $period === '7d' ) {
			$start_ts = strtotime( '-7 days' );
		} elseif ( $period === '30d' ) {
			$start_ts = strtotime( '-30 days' );
		}

		if ( $start_ts <= 0 ) {
			return $rows;
		}

		$out = array();
		foreach ( $rows as $row ) {
			$ts = strtotime( (string) ( $row['sent_at'] ?? '' ) );
			if ( $ts >= $start_ts ) {
				$out[] = $row;
			}
		}
		return $out;
	}
}
