<?php
/**
 * BizCity Membership — Revenue aggregation (read-only).
 *
 * Reads only bizcity_member_payments — AI token/cost are NEVER included
 * in revenue figures (money separation rule, PHASE-C).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-07
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// [2026-06-07 Johnny Chu] PHASE-C C-3 — Revenue report (read-only, payments table only).
class BizCity_Membership_Revenue_Report {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Headline KPIs.
	 *
	 * @return array { today_usd, week_usd, month_usd, net_month_usd, mrr_usd, arr_usd, paying_members, completed_count, refunded_month_usd, all_time_usd }
	 */
	public function headline() {
		$cached = get_transient( 'bizm_revenue_headline' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$t = $wpdb->prefix . 'bizcity_member_payments';

		$today  = gmdate( 'Y-m-d' );
		$w_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
		$m_start = gmdate( 'Y-m-01' );

		$today_usd  = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE status='completed' AND DATE(paid_at)=%s", $today
		) );
		$week_usd   = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE status='completed' AND paid_at>=%s", $w_start
		) );
		$month_usd  = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE status='completed' AND paid_at>=%s", $m_start
		) );
		$ref_month  = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE status='refunded' AND paid_at>=%s", $m_start
		) );
		$completed  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE status='completed' AND paid_at>=%s", $m_start
		) );
		$all_time   = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE status='completed'"
		);

		// Paying members (active subscriptions)
		$sub_t   = $wpdb->prefix . 'bizcity_member_subscriptions';
		$paying  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$sub_t} WHERE status=%s", 'active' )
		);

		// MRR — sum of plan prices * active subscriptions
		$mrr = $this->calc_mrr();

		$result = array(
			'today_usd'          => $today_usd,
			'week_usd'           => $week_usd,
			'month_usd'          => $month_usd,
			'net_month_usd'      => $month_usd - $ref_month,
			'mrr_usd'            => $mrr,
			'arr_usd'            => $mrr * 12,
			'paying_members'     => $paying,
			'completed_count'    => $completed,
			'refunded_month_usd' => $ref_month,
			'all_time_usd'       => $all_time,
		);

		set_transient( 'bizm_revenue_headline', $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Daily revenue series.
	 *
	 * @param int $days
	 * @return array  [ { date, usd, count } ]
	 */
	public function daily_series( $days = 30 ) {
		$cached = get_transient( 'bizm_revenue_daily_' . $days );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$t   = $wpdb->prefix . 'bizcity_member_payments';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(paid_at) AS dt,
				COALESCE(SUM(amount),0) AS usd,
				COUNT(*) AS cnt
			 FROM {$t}
			 WHERE status='completed' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			 GROUP BY dt
			 ORDER BY dt ASC",
			$days
		), ARRAY_A );

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[] = array(
					'date'  => (string) $r['dt'],
					'usd'   => (float)  $r['usd'],
					'count' => (int)    $r['cnt'],
				);
			}
		}

		set_transient( 'bizm_revenue_daily_' . $days, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Revenue grouped by plan for a given start date (YYYY-MM-DD).
	 *
	 * @param string $since  e.g. date('Y-m-01')
	 * @return array  [ { plan_slug, usd, count, members } ]
	 */
	public function by_plan( $since ) {
		global $wpdb;
		$t    = $wpdb->prefix . 'bizcity_member_payments';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT plan_slug,
				COALESCE(SUM(amount),0) AS usd,
				COUNT(*) AS cnt,
				COUNT(DISTINCT user_id) AS members
			 FROM {$t}
			 WHERE status='completed' AND paid_at >= %s
			 GROUP BY plan_slug
			 ORDER BY usd DESC",
			$since
		), ARRAY_A );

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[] = array(
					'plan_slug' => (string) $r['plan_slug'],
					'usd'       => (float)  $r['usd'],
					'count'     => (int)    $r['cnt'],
					'members'   => (int)    $r['members'],
				);
			}
		}
		return $out;
	}

	/**
	 * [2026-07-17 Johnny Chu] MBR-EXPIRY — active subscription expiry cohorts.
	 *
	 * Counts distinct active members whose expiration_date falls within
	 * 7/14/30/60/90-day windows from now.
	 *
	 * @return array {7d:int,14d:int,30d:int,60d:int,90d:int}
	 */
	public function expiry_cohorts() {
		$cached = get_transient( 'bizm_membership_expiry_cohorts' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$sub_t = $wpdb->prefix . 'bizcity_member_subscriptions';

		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			$table_exists = (bool) bizcity_tbl_exists( $sub_t );
		} else {
			$table_exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$sub_t
				)
			);
		}

		$empty = array(
			'7d'  => 0,
			'14d' => 0,
			'30d' => 0,
			'60d' => 0,
			'90d' => 0,
		);
		if ( ! $table_exists ) {
			set_transient( 'bizm_membership_expiry_cohorts', $empty, 5 * MINUTE_IN_SECONDS );
			return $empty;
		}

		$now_ts   = (int) current_time( 'timestamp' );
		$now_sql  = current_time( 'mysql' );
		$max_sql  = gmdate( 'Y-m-d H:i:s', strtotime( '+90 days', $now_ts ) );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, expiration_date
				 FROM {$sub_t}
				 WHERE status = %s
				   AND expiration_date IS NOT NULL
				   AND expiration_date <> ''
				   AND expiration_date >= %s
				   AND expiration_date <= %s",
				'active',
				$now_sql,
				$max_sql
			),
			ARRAY_A
		);

		// Keep one nearest expiry per user to avoid double-counting in edge cases.
		$nearest_by_user = array();
		foreach ( (array) $rows as $row ) {
			$uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			$exp = isset( $row['expiration_date'] ) ? (string) $row['expiration_date'] : '';
			if ( $uid <= 0 || $exp === '' ) {
				continue;
			}
			$ts = strtotime( $exp );
			if ( ! $ts || $ts < $now_ts ) {
				continue;
			}
			if ( ! isset( $nearest_by_user[ $uid ] ) || $ts < $nearest_by_user[ $uid ] ) {
				$nearest_by_user[ $uid ] = $ts;
			}
		}

		$out = $empty;
		foreach ( $nearest_by_user as $ts ) {
			$days_left = (int) ceil( ( $ts - $now_ts ) / DAY_IN_SECONDS );
			if ( $days_left < 0 ) {
				continue;
			}
			if ( $days_left <= 7 ) {
				$out['7d']++;
			}
			if ( $days_left <= 14 ) {
				$out['14d']++;
			}
			if ( $days_left <= 30 ) {
				$out['30d']++;
			}
			if ( $days_left <= 60 ) {
				$out['60d']++;
			}
			if ( $days_left <= 90 ) {
				$out['90d']++;
			}
		}

		set_transient( 'bizm_membership_expiry_cohorts', $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/* ── Private ──────────────────────────────────────────────────────── */

	/**
	 * Calculate MRR from active subscriptions * plan price.
	 *
	 * @return float
	 */
	private function calc_mrr() {
		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return 0.0;
		}
		global $wpdb;
		$sub_t   = $wpdb->prefix . 'bizcity_member_subscriptions';
		$plans   = BizCity_Membership_Plan_Registry::instance()->all();
		$plan_by = array();
		foreach ( (array) $plans as $p ) {
			$slug = isset( $p['slug'] ) ? $p['slug'] : '';
			if ( $slug !== '' ) {
				$plan_by[ $slug ] = isset( $p['price_usd'] ) ? (float) $p['price_usd'] : 0.0;
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT plan_slug, COUNT(DISTINCT user_id) AS c FROM {$sub_t} WHERE status=%s GROUP BY plan_slug", 'active' ),
			ARRAY_A
		);

		$mrr = 0.0;
		foreach ( (array) $rows as $r ) {
			$slug = (string) $r['plan_slug'];
			$cnt  = (int)    $r['c'];
			$price = isset( $plan_by[ $slug ] ) ? $plan_by[ $slug ] : 0.0;
			$mrr  += $price * $cnt;
		}
		return $mrr;
	}
}
