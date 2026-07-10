<?php
/**
 * BizCoach Pro — Per-user usage aggregation (read-only).
 *
 * @package BizCoachPro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// [2026-06-07 Johnny Chu] PHASE-C C-BE-2 — per-user usage report class.
class BizCoach_Pro_Usage_Report {

	/**
	 * Build a full usage summary for one user.
	 *
	 * @param int    $user_id
	 * @param string $range  '7d' | '30d' | '90d'
	 * @return array
	 */
	public static function summary( $user_id, $range = '30d' ) {
		$uid = (int) $user_id;
		if ( $uid <= 0 ) {
			return array( 'success' => false, '_degraded' => true, 'message' => 'Invalid user.' );
		}

		// 5-minute transient cache per user+range.
		$cache_key = 'bcpro_usage_' . $uid . '_' . $range;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$days = self::range_to_days( $range );

		$result = array(
			'success' => true,
			'range'   => $range,
			'today'   => array(
				'by_feature' => self::feature_snapshot( $uid ),
				'tokens'     => self::token_totals_today( $uid ),
				'cost_usd'   => self::kg_cost_today( $uid ),
				'by_service' => self::service_breakdown_today( $uid ),
			),
			'history' => self::daily_history( $uid, $days ),
			'plan'    => self::plan_slug( $uid ),
		);

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/* ── Private helpers ───────────────────────────────────────────────── */

	/**
	 * Feature quota snapshot (today) for a single user.
	 *
	 * @param int $uid
	 * @return array  feature => { used, limit, remaining }
	 */
	private static function feature_snapshot( $uid ) {
		if ( ! class_exists( 'BizCity_Membership_Usage' ) ) {
			return array();
		}
		return BizCity_Membership_Usage::instance()->snapshot( $uid );
	}

	/**
	 * Token totals from bizcity_llm_usage for today (per user).
	 *
	 * @param int $uid
	 * @return array { prompt, completion, total }
	 */
	private static function token_totals_today( $uid ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_llm_usage';
		$today = gmdate( 'Y-m-d' );
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COALESCE(SUM(tokens_prompt),0)     AS prompt,
				COALESCE(SUM(tokens_completion),0) AS completion,
				COUNT(*)                           AS calls
			 FROM {$table}
			 WHERE user_id = %d AND DATE(created_at) = %s",
			$uid, $today
		), ARRAY_A );
		$prompt     = isset( $row['prompt'] )     ? (int) $row['prompt']     : 0;
		$completion = isset( $row['completion'] ) ? (int) $row['completion'] : 0;
		return array(
			'prompt'     => $prompt,
			'completion' => $completion,
			'total'      => $prompt + $completion,
			'calls'      => isset( $row['calls'] ) ? (int) $row['calls'] : 0,
		);
	}

	/**
	 * KG cost for today (per user) — direct SQL on bizcity_kg_usage_log.
	 *
	 * @param int $uid
	 * @return float  spent_usd
	 */
	private static function kg_cost_today( $uid ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_kg_usage_log';
		$today = gmdate( 'Y-m-d' );
		$val   = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(cost_usd),0) FROM {$table} WHERE user_id = %d AND day = %s",
			$uid, $today
		) );
		return (float) $val;
	}

	/**
	 * Per-service call + token breakdown for today.
	 *
	 * @param int $uid
	 * @return array  service => { calls, tokens }
	 */
	private static function service_breakdown_today( $uid ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'bizcity_llm_usage';
		$today    = gmdate( 'Y-m-d' );
		$services = array( 'llm', 'embedding', 'search', 'video', 'image', 'astro', 'market', 'tools' );
		$out      = array();
		foreach ( $services as $svc ) {
			$out[ $svc ] = array( 'calls' => 0, 'tokens' => 0 );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT service,
				COUNT(*) AS calls,
				COALESCE(SUM(tokens_prompt + tokens_completion),0) AS tokens
			 FROM {$table}
			 WHERE user_id = %d AND DATE(created_at) = %s
			 GROUP BY service",
			$uid, $today
		), ARRAY_A );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$svc = (string) $r['service'];
				$out[ $svc ] = array(
					'calls'  => (int) $r['calls'],
					'tokens' => (int) $r['tokens'],
				);
			}
		}
		return $out;
	}

	/**
	 * Daily history (per user) for N days.
	 *
	 * @param int $uid
	 * @param int $days
	 * @return array  [ { date, calls, tokens, cost_usd } ]
	 */
	private static function daily_history( $uid, $days ) {
		global $wpdb;
		$llm_table = $wpdb->prefix . 'bizcity_llm_usage';
		$kg_table  = $wpdb->prefix . 'bizcity_kg_usage_log';

		// LLM daily
		$llm_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS dt,
				COUNT(*) AS calls,
				COALESCE(SUM(tokens_prompt + tokens_completion),0) AS tokens
			 FROM {$llm_table}
			 WHERE user_id = %d AND created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			 GROUP BY dt
			 ORDER BY dt ASC",
			$uid, $days
		), ARRAY_A );

		// KG daily cost
		$kg_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT day AS dt, COALESCE(SUM(cost_usd),0) AS cost_usd
			 FROM {$kg_table}
			 WHERE user_id = %d AND day >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			 GROUP BY day
			 ORDER BY day ASC",
			$uid, $days
		), ARRAY_A );

		// Merge by date
		$by_date = array();
		if ( is_array( $llm_rows ) ) {
			foreach ( $llm_rows as $r ) {
				$dt = (string) $r['dt'];
				$by_date[ $dt ] = array(
					'date'     => $dt,
					'calls'    => (int) $r['calls'],
					'tokens'   => (int) $r['tokens'],
					'cost_usd' => 0.0,
				);
			}
		}
		if ( is_array( $kg_rows ) ) {
			foreach ( $kg_rows as $r ) {
				$dt = (string) $r['dt'];
				if ( isset( $by_date[ $dt ] ) ) {
					$by_date[ $dt ]['cost_usd'] = (float) $r['cost_usd'];
				} else {
					$by_date[ $dt ] = array(
						'date'     => $dt,
						'calls'    => 0,
						'tokens'   => 0,
						'cost_usd' => (float) $r['cost_usd'],
					);
				}
			}
		}

		ksort( $by_date );
		return array_values( $by_date );
	}

	/**
	 * Plan slug for user.
	 *
	 * @param int $uid
	 * @return string
	 */
	private static function plan_slug( $uid ) {
		if ( ! class_exists( 'BizCity_Membership_Entitlement' ) ) {
			return 'free';
		}
		$ent = BizCity_Membership_Entitlement::instance()->for_user( $uid );
		return isset( $ent['user_plan'] ) ? (string) $ent['user_plan'] : 'free';
	}

	/**
	 * Convert range string to number of days.
	 *
	 * @param string $range
	 * @return int
	 */
	private static function range_to_days( $range ) {
		switch ( $range ) {
			case '7d':  return 7;
			case '90d': return 90;
			default:    return 30;
		}
	}
}
