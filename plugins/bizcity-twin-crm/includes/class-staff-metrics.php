<?php
/**
 * PHASE-0.50 C-04 (part 2) — per-employee additive metrics (§5.4) as daily rollups.
 *
 * Only ADDITIVE facts are rolled up, because a daily bucket can be summed over any
 * range without double counting:
 *
 *   orders_attributed    paid CRM order, attribution assignee_at_create | creator_fallback | backfill_event
 *   orders_estimated     paid CRM order, attribution backfill_current (shown as "ước tính", never merged)
 *                        (count = orders, sum_value = revenue)
 *   tasks_assigned       leader handoff created for the employee
 *   tasks_done           handoff completed by the employee
 *   tasks_done_with_due  … of which had a due date
 *   tasks_done_on_time   … and were completed on or before it
 *
 * Distinct-customer metrics (khách của NV, đã chạm, mua lại, nguội) are NOT additive
 * across days and stay compose-on-read in the portfolio (§12 W3).
 *
 * Facts are written through {@see BizCity_CRM_Reporting_Rollup::record_fact()} and are
 * idempotent per order / task, so the live hooks and the backfill can overlap safely.
 * Content-free: ids, dates and amounts only.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.50 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Staff_Metrics', false ) ) {
	return;
}

final class BizCity_CRM_Staff_Metrics {

	const CONTRACT = 'staff-metrics';
	const VERSION  = '1.0.0';
	const CURSOR_OPTION   = 'bizcity_crm_staff_metrics_backfill_cursor';
	const MIN_RATE_SAMPLE = 5; // §5.4 "Việc đúng hạn": mẫu ≥ 5.
	const MAX_BATCH       = 200;

	const METRICS = array( 'orders_attributed', 'orders_estimated', 'tasks_assigned', 'tasks_done', 'tasks_done_with_due', 'tasks_done_on_time' );

	public static function register(): void {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 20, 4 );
		add_action( 'bizcity_crm_task_handoff_transition', array( __CLASS__, 'on_task_transition' ), 10, 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'bizcity crm-staff-metrics-backfill', array( __CLASS__, 'cli' ) );
		}
	}

	// ── Pure rules (unit-tested) ─────────────────────────────────────────

	/** Which order metric an attribution counts toward; '' = not an employee order. */
	public static function order_metric( string $attribution ): string {
		if ( in_array( $attribution, array( 'assignee_at_create', 'creator_fallback', 'backfill_event' ), true ) ) { return 'orders_attributed'; }
		if ( 'backfill_current' === $attribution ) { return 'orders_estimated'; }
		return '';
	}

	/**
	 * Metrics produced by one task transition.
	 *
	 * @param string      $to         new status
	 * @param string|null $due_date   `Y-m-d` or null
	 * @param string      $done_date  local `Y-m-d` of completion
	 * @return array<int,string>
	 */
	public static function task_metrics( string $to, $due_date, string $done_date ): array {
		if ( 'sent' === $to ) { return array( 'tasks_assigned' ); }
		if ( 'done' !== $to ) { return array(); }
		$out = array( 'tasks_done' );
		$due = is_string( $due_date ) ? substr( $due_date, 0, 10 ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) && '0000-00-00' !== $due ) {
			$out[] = 'tasks_done_with_due';
			if ( '' !== $done_date && strcmp( substr( $done_date, 0, 10 ), $due ) <= 0 ) { $out[] = 'tasks_done_on_time'; }
		}
		return $out;
	}

	/**
	 * Totals from `metric => {count, sum}` rows. Missing metric = 0 (the rollup exists for the range);
	 * the on-time rate is published only with its basis and a sample ≥ MIN_RATE_SAMPLE.
	 *
	 * @param array<string,array{count:int,sum:float}> $by_metric
	 */
	public static function summarize( array $by_metric ): array {
		$get = static function ( string $metric, string $field ) use ( $by_metric ) {
			return $by_metric[ $metric ][ $field ] ?? 0;
		};
		$with_due = (int) $get( 'tasks_done_with_due', 'count' );
		$on_time  = (int) $get( 'tasks_done_on_time', 'count' );
		return array(
			'orders'           => array( 'count' => (int) $get( 'orders_attributed', 'count' ), 'revenue' => (float) $get( 'orders_attributed', 'sum' ) ),
			'orders_estimated' => array( 'count' => (int) $get( 'orders_estimated', 'count' ), 'revenue' => (float) $get( 'orders_estimated', 'sum' ) ),
			'tasks'            => array(
				'assigned'      => (int) $get( 'tasks_assigned', 'count' ),
				'done'          => (int) $get( 'tasks_done', 'count' ),
				'done_with_due' => $with_due,
				'done_on_time'  => $on_time,
				'on_time_rate'  => $with_due >= self::MIN_RATE_SAMPLE ? array( 'num' => $on_time, 'den' => $with_due, 'value' => round( $on_time / $with_due * 100, 1 ) ) : null,
			),
		);
	}

	// ── Live projection ──────────────────────────────────────────────────

	public static function on_order_status_changed( $order_id, $from, $to, $order = null ): void {
		if ( ! in_array( (string) $to, array( 'processing', 'completed' ), true ) ) { return; }
		$order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null );
		if ( is_object( $order ) ) { self::record_order( $order ); }
	}

	public static function on_task_transition( $task_id, $from, $to, $user_id, $context = array() ): void {
		$context = is_array( $context ) ? $context : array();
		$at_local = (string) ( $context['at'] ?? current_time( 'mysql' ) );
		self::record_task( (int) $task_id, (int) $user_id, (string) $to, $context['due_date'] ?? null, $at_local );
	}

	/** @return int facts newly recorded (0 when not an employee order or already counted). */
	private static function record_order( $order ): int {
		if ( ! method_exists( $order, 'get_meta' ) || ! class_exists( 'BizCity_CRM_Reporting_Rollup' ) ) { return 0; }
		$conversation_id = (int) $order->get_meta( '_bizcity_crm_conversation_id' );
		$assignee_id = (int) $order->get_meta( '_bizcity_crm_assignee_id' );
		$metric = self::order_metric( (string) $order->get_meta( '_bizcity_crm_attribution' ) );
		if ( $conversation_id <= 0 || $assignee_id <= 0 || '' === $metric ) { return 0; }
		$date = null;
		foreach ( array( 'get_date_paid', 'get_date_completed', 'get_date_created' ) as $getter ) {
			if ( method_exists( $order, $getter ) && $order->$getter() ) { $date = $order->$getter(); break; }
		}
		$at_gmt = $date ? gmdate( 'Y-m-d H:i:s', (int) $date->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' );
		$total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
		return BizCity_CRM_Reporting_Rollup::record_fact( $metric, $assignee_id, $at_gmt, max( 0.0, $total ), 'order|' . (int) $order->get_id(), $conversation_id ) ? 1 : 0;
	}

	private static function record_task( int $task_id, int $user_id, string $to, $due_date, string $at_local ): int {
		if ( $task_id <= 0 || $user_id <= 0 || ! class_exists( 'BizCity_CRM_Reporting_Rollup' ) ) { return 0; }
		$at_gmt = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $at_local ) : $at_local;
		$recorded = 0;
		foreach ( self::task_metrics( $to, $due_date, $at_local ) as $metric ) {
			$recorded += BizCity_CRM_Reporting_Rollup::record_fact( $metric, $user_id, $at_gmt, 1.0, 'task|' . $task_id ) ? 1 : 0;
		}
		return $recorded;
	}

	// ── Reader ───────────────────────────────────────────────────────────

	/** Sum one employee's rollups over a local-date window. */
	public static function totals( int $user_id, string $from, string $to ): array {
		global $wpdb;
		$by_metric = array();
		if ( $user_id > 0 && class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			$table = BizCity_CRM_DB_Installer_V2::tbl_reporting_rollups();
			$ph = implode( ',', array_fill( 0, count( self::METRICS ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT metric, SUM(count) AS c, SUM(sum_value) AS s FROM `{$table}` WHERE dimension_type = 'user' AND dimension_id = %d AND bucket_date BETWEEN %s AND %s AND metric IN ({$ph}) GROUP BY metric",
				array_merge( array( $user_id, $from, $to ), self::METRICS )
			), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$by_metric[ (string) $row['metric'] ] = array( 'count' => (int) $row['c'], 'sum' => (float) $row['s'] );
			}
		}
		return self::summarize( $by_metric );
	}

	public static function register_routes(): void {
		$ns = defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';
		register_rest_route( $ns, '/reports/staff-metrics/(?P<user_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_get' ),
			'permission_callback' => static function () { return is_user_logged_in(); },
			'args'                => array( 'range' => array( 'type' => 'string', 'default' => '30d', 'enum' => array( 'today', '7d', '30d' ) ) ),
		) );
		register_rest_route( $ns, '/reports/staff-metrics/backfill', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_backfill' ),
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'args'                => array( 'limit' => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => self::MAX_BATCH ) ),
		) );
	}

	public static function rest_get( WP_REST_Request $request ) {
		$actor_id   = (int) get_current_user_id();
		$subject_id = (int) $request['user_id'];
		if ( $subject_id !== $actor_id && class_exists( 'BizCity_CRM_Staff_Policy' ) ) {
			// Same gate as the W3 portfolio: another employee's numbers need contact.view_by_owner.
			$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'contact.view_by_owner', $subject_id );
			if ( empty( $decision['ok'] ) ) { return BizCity_CRM_Staff_Policy::denied_response( $decision ); }
		}
		$range = sanitize_key( (string) $request->get_param( 'range' ) );
		$days = '7d' === $range ? 7 : ( 'today' === $range ? 1 : 30 );
		$to_ts = current_time( 'timestamp' );
		$from = gmdate( 'Y-m-d', $to_ts - ( $days - 1 ) * DAY_IN_SECONDS );
		$to = gmdate( 'Y-m-d', $to_ts );
		return new WP_REST_Response( array_merge( array(
			'ok'       => true,
			'contract' => self::CONTRACT,
			'version'  => self::VERSION,
			'user_id'  => $subject_id,
			'range'    => array( 'key' => 1 === $days ? 'today' : $days . 'd', 'days' => $days, 'from' => $from, 'to' => $to ),
			'as_of'    => current_time( 'c' ),
			'source'   => 'rollup',
			'notes'    => array(
				'coverage' => 'Tính từ khi bật rollup hoặc chạy backfill; khách không trùng (đã chạm, mua lại, nguội) xem ở Danh mục khách.',
				'on_time'  => 'Tỉ lệ đúng hạn = việc xong đúng hạn / việc xong có hạn; ẩn khi dưới ' . self::MIN_RATE_SAMPLE . ' việc.',
			),
		), self::totals( $subject_id, $from, $to ) ), 200 );
	}

	// ── Backfill (idempotent; resumes from a cursor) ─────────────────────

	/** @return array{ok:bool,orders_recorded:int,tasks_recorded:int,remaining:bool,code?:string} */
	public static function backfill_batch( int $limit = 100 ): array {
		$limit = max( 1, min( self::MAX_BATCH, $limit ) );
		$out = array( 'ok' => true, 'orders_recorded' => 0, 'tasks_recorded' => 0, 'remaining' => false );
		if ( ! class_exists( 'BizCity_CRM_Reporting_Rollup' ) || ! method_exists( 'BizCity_CRM_Reporting_Rollup', 'record_fact' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array_merge( $out, array( 'ok' => false, 'code' => 'module_not_loaded' ) );
		}
		$cursor = get_option( self::CURSOR_OPTION, array() );
		$cursor = array( 'orders_page' => max( 1, (int) ( $cursor['orders_page'] ?? 1 ) ), 'task_id' => max( 0, (int) ( $cursor['task_id'] ?? 0 ) ), 'orders_done' => ! empty( $cursor['orders_done'] ) );

		if ( ! $cursor['orders_done'] && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array(
				'limit'      => $limit,
				'paged'      => $cursor['orders_page'],
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'return'     => 'objects',
				'type'       => 'shop_order',
				'status'     => array( 'wc-processing', 'wc-completed' ),
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key' => '_bizcity_crm_conversation_id', 'compare' => 'EXISTS' ),
					array( 'key' => '_bizcity_crm_attribution', 'compare' => 'EXISTS' ),
				),
			) );
			$orders = is_array( $orders ) ? $orders : array();
			foreach ( $orders as $order ) {
				if ( is_object( $order ) ) { $out['orders_recorded'] += self::record_order( $order ); }
			}
			if ( count( $orders ) < $limit ) { $cursor['orders_done'] = true; } else { $cursor['orders_page']++; }
		} else {
			$cursor['orders_done'] = true;
		}

		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, assignee_id, due_date, status, completed, completed_at, created_at FROM `{$tbl}`
			 WHERE deleted_at IS NULL AND created_by IS NOT NULL AND assignee_id IS NOT NULL AND created_by <> assignee_id AND id > %d
			 ORDER BY id ASC LIMIT %d",
			$cursor['task_id'], $limit
		), ARRAY_A );
		foreach ( $rows as $row ) {
			$task_id = (int) $row['id'];
			$assignee = (int) $row['assignee_id'];
			$out['tasks_recorded'] += self::record_task( $task_id, $assignee, 'sent', null, (string) $row['created_at'] );
			$is_done = ( 'done' === (string) $row['status'] || 1 === (int) $row['completed'] ) && ! empty( $row['completed_at'] );
			if ( $is_done ) {
				$out['tasks_recorded'] += self::record_task( $task_id, $assignee, 'done', $row['due_date'], (string) $row['completed_at'] );
			}
			$cursor['task_id'] = $task_id;
		}
		$tasks_remaining = count( $rows ) >= $limit;

		$out['remaining'] = ! $cursor['orders_done'] || $tasks_remaining;
		update_option( self::CURSOR_OPTION, $cursor, false );
		return $out;
	}

	public static function rest_backfill( WP_REST_Request $request ) {
		$result = self::backfill_batch( (int) $request->get_param( 'limit' ) );
		if ( empty( $result['ok'] ) ) {
			return new WP_REST_Response( array_merge( $result, array( 'message' => 'Rollup báo cáo CRM chưa sẵn sàng.', 'hint' => 'Cập nhật plugin CRM rồi chạy lại.', 'help_code' => 'module_not_loaded' ) ), 503 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Backfill per-employee order/task rollups from existing data. Safe to re-run.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Rows per batch (1–200). Default 100.
	 *
	 * [--restart]
	 * : Reset the cursor and scan from the beginning (facts stay deduplicated).
	 */
	public static function cli( $args, $assoc ): void {
		if ( isset( $assoc['restart'] ) ) { delete_option( self::CURSOR_OPTION ); }
		$batch = max( 1, min( self::MAX_BATCH, (int) ( $assoc['batch'] ?? 100 ) ) );
		$orders = 0; $tasks = 0;
		for ( $i = 0; $i < 500; $i++ ) {
			$result = self::backfill_batch( $batch );
			if ( empty( $result['ok'] ) ) { \WP_CLI::error( 'Rollup unavailable: ' . ( $result['code'] ?? 'unknown' ) ); }
			$orders += (int) $result['orders_recorded'];
			$tasks  += (int) $result['tasks_recorded'];
			if ( empty( $result['remaining'] ) ) { break; }
		}
		\WP_CLI::success( sprintf( 'Recorded %d order facts and %d task facts (already-counted rows were skipped).', $orders, $tasks ) );
	}
}
