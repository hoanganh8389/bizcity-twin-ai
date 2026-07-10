<?php
/**
 * BizCity Membership — Admin per-user usage trace (read-only).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-07
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// [2026-06-07 Johnny Chu] PHASE-C C-3 — Admin usage trace (per-user, read-only).
class BizCity_Membership_Usage_Report {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Top N users by calls in a period.
	 *
	 * @param string $period  '7d' | '30d' | '90d'
	 * @param int    $limit
	 * @return array  [ { user_id, display_name, email, calls, tokens } ]
	 */
	public function top_users( $period = '7d', $limit = 20 ) {
		$days = $this->period_days( $period );
		global $wpdb;
		$t = $wpdb->prefix . 'bizcity_llm_usage';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id,
				COUNT(*) AS calls,
				COALESCE(SUM(tokens_prompt + tokens_completion),0) AS tokens
			 FROM {$t}
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP BY user_id
			 ORDER BY calls DESC
			 LIMIT %d",
			$days, (int) $limit
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$uid  = (int) $r['user_id'];
			$user = get_userdata( $uid );
			$out[] = array(
				'user_id'      => $uid,
				'display_name' => $user ? $user->display_name : '#' . $uid,
				'email'        => $user ? $user->user_email    : '',
				'calls'        => (int) $r['calls'],
				'tokens'       => (int) $r['tokens'],
			);
		}
		return $out;
	}

	/**
	 * Detailed usage for one user in a period.
	 *
	 * @param int    $user_id
	 * @param string $period  '7d' | '30d' | '90d'
	 * @return array  { user{}, by_service[], tokens{}, kg_cost_usd, feature_snapshot{} }
	 */
	public function user_detail( $user_id, $period = '30d' ) {
		$uid  = (int) $user_id;
		$days = $this->period_days( $period );
		$wp_user = get_userdata( $uid );

		$user_info = array(
			'user_id'      => $uid,
			'display_name' => $wp_user ? $wp_user->display_name : '#' . $uid,
			'email'        => $wp_user ? $wp_user->user_email    : '',
		);

		global $wpdb;
		$llm_t = $wpdb->prefix . 'bizcity_llm_usage';
		$kg_t  = $wpdb->prefix . 'bizcity_kg_usage_log';

		// By service
		$svc_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT service,
				COUNT(*) AS calls,
				COALESCE(SUM(tokens_prompt + tokens_completion),0) AS tokens
			 FROM {$llm_t}
			 WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP BY service
			 ORDER BY calls DESC",
			$uid, $days
		), ARRAY_A );

		$by_service = array();
		foreach ( (array) $svc_rows as $r ) {
			$by_service[] = array(
				'service' => (string) $r['service'],
				'calls'   => (int)    $r['calls'],
				'tokens'  => (int)    $r['tokens'],
			);
		}

		// Token totals
		$tok = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COALESCE(SUM(tokens_prompt),0)     AS prompt,
				COALESCE(SUM(tokens_completion),0) AS completion
			 FROM {$llm_t}
			 WHERE user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
			$uid, $days
		), ARRAY_A );
		$prompt     = isset( $tok['prompt'] )     ? (int) $tok['prompt']     : 0;
		$completion = isset( $tok['completion'] ) ? (int) $tok['completion'] : 0;

		// KG cost
		$kg_cost = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(cost_usd),0) FROM {$kg_t} WHERE user_id = %d AND day >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
			$uid, $days
		) );

		// Feature snapshot (today, from membership usage)
		$feat = array();
		if ( class_exists( 'BizCity_Membership_Usage' ) ) {
			$feat = BizCity_Membership_Usage::instance()->snapshot( $uid );
		}

		return array(
			'user'             => $user_info,
			'by_service'       => $by_service,
			'tokens'           => array(
				'prompt'     => $prompt,
				'completion' => $completion,
				'total'      => $prompt + $completion,
			),
			'kg_cost_usd'      => $kg_cost,
			'feature_snapshot' => $feat,
		);
	}

	/* ── Private ──────────────────────────────────────────────────────── */

	/**
	 * @param string $period
	 * @return int
	 */
	private function period_days( $period ) {
		switch ( $period ) {
			case '7d':  return 7;
			case '90d': return 90;
			default:    return 30;
		}
	}
}
