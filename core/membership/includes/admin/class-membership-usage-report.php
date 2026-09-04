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
		$rows = class_exists( 'BizCity_LLM_Usage_File_Log' )
			? BizCity_LLM_Usage_File_Log::get_stats_by_user( (string) $period, array( 'blog_id' => (int) get_current_blog_id() ) )
			: array();
		$rows = array_slice( $rows, 0, max( 1, (int) $limit ) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$uid  = (int) ( $r['user_id'] ?? 0 );
			$user = get_userdata( $uid );
			$out[] = array(
				'user_id'      => $uid,
				'display_name' => $user ? $user->display_name : '#' . $uid,
				'email'        => $user ? $user->user_email    : '',
				'calls'        => (int) ( $r['total_calls'] ?? 0 ),
				'tokens'       => (int) ( $r['total_tokens'] ?? 0 ),
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
		$kg_t  = $wpdb->prefix . 'bizcity_kg_usage_log';

		// [2026-09-01 Johnny Chu] R-LLM-USAGE-FILESTORE — user detail reads the tenant-scoped JSONL usage contract.
		$usage_filters = array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => $uid );
		$svc_rows = class_exists( 'BizCity_LLM_Usage_File_Log' )
			? BizCity_LLM_Usage_File_Log::get_stats_by_service( (string) $period, $usage_filters )
			: array();

		$by_service = array();
		foreach ( (array) $svc_rows as $r ) {
			if ( (int) ( $r['total_calls'] ?? 0 ) <= 0 ) { continue; }
			$by_service[] = array(
				'service' => (string) ( $r['service'] ?? '' ),
				'calls'   => (int) ( $r['total_calls'] ?? 0 ),
				'tokens'  => (int) ( $r['total_tokens'] ?? 0 ),
			);
		}

		$usage_stats = class_exists( 'BizCity_LLM_Usage_File_Log' )
			? BizCity_LLM_Usage_File_Log::get_stats( (string) $period, $usage_filters )
			: array();
		$prompt     = (int) ( $usage_stats['total_prompt_tokens'] ?? 0 );
		$completion = (int) ( $usage_stats['total_completion_tokens'] ?? 0 );

		// [2026-08-28 Johnny Chu] PHASE-1.30-LIFECYCLE — keep admin report stable when the SQL structural KG ledger is blocked/missing.
		$kg_cost = 0.0;
		$kg_allowed = true;
		if ( class_exists( 'BizCity_Legacy_Table_Policy' ) ) {
			$kg_allowed = BizCity_Legacy_Table_Policy::allow_sql( $kg_t, 'read' );
		}
		if ( $kg_allowed && ( ! function_exists( 'bizcity_tbl_exists' ) || bizcity_tbl_exists( $kg_t ) ) ) {
			$kg_cost = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(cost_usd),0) FROM {$kg_t} WHERE user_id = %d AND day >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
				$uid, $days
			) );
		}

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
