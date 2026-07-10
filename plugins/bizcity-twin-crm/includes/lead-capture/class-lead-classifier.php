<?php
/**
 * PHASE 0.37.2 — Lead Classifier (rule-based).
 *
 * Mặc định:
 *   - cf7:*       → status=new, rating=warm  (đã chủ động liên hệ qua form)
 *   - webchat     → status=new, rating=warm
 *   - comment     → status=new, rating=cold
 *   - bizcity_capture_lead default → giữ nguyên engine ('new', không rating)
 *
 * Keyword boost (case-insensitive trong message):
 *   - "báo giá|quote|pricing|mua|order|đặt hàng"  → rating=hot, status=qualified
 *   - "demo|trial|tư vấn|consult"                 → rating=warm, status=contacted
 *   - "khiếu nại|complain|hủy|cancel|refund"      → rating=cold, status=unqualified
 *
 * Mở rộng: `add_filter( 'bizcity_crm_lead_classify_rules', fn($rules) => ... )`.
 *
 * @package BizCity\CRM\LeadCapture
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BizCity_CRM_Lead_Classifier {

	/**
	 * @param array  $row    Lead row (incluants 'message' nếu có).
	 * @param string $source Source code.
	 * @return array{status:string,rating:string}
	 */
	public static function apply( array $row, string $source ): array {
		$out = array( 'status' => '', 'rating' => '' );

		// Defaults by source bucket.
		if ( strpos( $source, 'cf7' ) === 0 || strpos( $source, 'wpforms' ) === 0 || strpos( $source, 'form' ) === 0 ) {
			$out = array( 'status' => 'new', 'rating' => 'warm' );
		} elseif ( $source === 'webchat' || strpos( $source, 'chat' ) !== false ) {
			$out = array( 'status' => 'new', 'rating' => 'warm' );
		} elseif ( $source === 'comment' || strpos( $source, 'comment' ) === 0 ) {
			$out = array( 'status' => 'new', 'rating' => 'cold' );
		}

		// Keyword boost from message.
		$msg = strtolower( (string) ( $row['message'] ?? $row['notes'] ?? '' ) );
		if ( $msg !== '' ) {
			foreach ( self::rules() as $rule ) {
				if ( ! empty( $rule['keywords'] ) && self::match_any( $msg, $rule['keywords'] ) ) {
					if ( ! empty( $rule['status'] ) ) { $out['status'] = (string) $rule['status']; }
					if ( ! empty( $rule['rating'] ) ) { $out['rating'] = (string) $rule['rating']; }
				}
			}
		}

		return apply_filters( 'bizcity_crm_lead_classified', $out, $row, $source );
	}

	private static function rules(): array {
		$defaults = array(
			array(
				'name'     => 'hot_buyer_intent',
				'keywords' => array( 'báo giá', 'quote', 'pricing', 'mua', 'order', 'đặt hàng', 'thanh toán', 'checkout' ),
				'status'   => 'qualified',
				'rating'   => 'hot',
			),
			array(
				'name'     => 'demo_or_consult',
				'keywords' => array( 'demo', 'trial', 'dùng thử', 'tư vấn', 'consult', 'meeting' ),
				'status'   => 'contacted',
				'rating'   => 'warm',
			),
			array(
				'name'     => 'unqualified',
				'keywords' => array( 'khiếu nại', 'complain', 'hủy', 'cancel', 'refund', 'phàn nàn' ),
				'status'   => 'unqualified',
				'rating'   => 'cold',
			),
		);
		return (array) apply_filters( 'bizcity_crm_lead_classify_rules', $defaults );
	}

	private static function match_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $n ) {
			$n = strtolower( trim( (string) $n ) );
			if ( $n !== '' && strpos( $haystack, $n ) !== false ) { return true; }
		}
		return false;
	}
}
