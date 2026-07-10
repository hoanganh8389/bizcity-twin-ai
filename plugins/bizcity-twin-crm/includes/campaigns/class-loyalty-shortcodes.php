<?php
/**
 * BizCity CRM — Loyalty bridge shortcodes (PHASE 0.35 M6.W4 add-on).
 *
 * Provides `[kiem_tra_diem]` and `[doi_diem]` so flow rules / message
 * scripts running through M2 ActionRunner (sub-action `do_shortcode`) keep
 * working after the bizgpt-custom-flows plugin is retired. The contract is
 * the **legacy contract** — return:
 *
 *     wp_json_encode([
 *       'success' => bool,
 *       'msgs'    => array<string>,  // each msg = one Messenger bubble
 *     ]);
 *
 * so existing parsers / chat templates ("Kịch bản mẫu trong ảnh") work
 * unchanged.
 *
 * Coexistence rules:
 *   - We register only when the shortcode is NOT already taken (legacy
 *     bizgpt plugin still active). This guarantees zero collision and an
 *     additive rollout — the moment legacy is deactivated, our copy takes
 *     over with the SAME tag name.
 *   - Where shortcode `client_id` is needed, we read `get_transient('hook_data')`
 *     first (legacy front-end stamps it before processing). When absent
 *     (e.g. in CRM REST flows or tests), callers can pass `client_id="..."`
 *     directly via the shortcode attribute.
 *
 * Point sources, in order of precedence:
 *   1. `bizgpt_custom_flows` log table `wp_gpt_logs` (column `so_diem_thay_doi`)
 *      keyed by client_id — matches legacy SHORTCODE behavior verbatim.
 *   2. `wp_user_points` minus `wp_user_points_exchange` keyed by client_id
 *      (function `get_total_points_by_client_id_user_point` in legacy plugin).
 *   3. `get_total_points_by_phone()` (user-points plugin) when phone provided.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Loyalty_Shortcodes {

	public static function register(): void {
		// Defer to legacy if already registered — guarantees no collision.
		if ( ! shortcode_exists( 'kiem_tra_diem' ) ) {
			add_shortcode( 'kiem_tra_diem', array( __CLASS__, 'sc_kiem_tra_diem' ) );
		}
		if ( ! shortcode_exists( 'doi_diem' ) ) {
			add_shortcode( 'doi_diem', array( __CLASS__, 'sc_doi_diem' ) );
		}
	}

	/* ============================================================
	 * [kiem_tra_diem phone="..." client_id="..."]
	 * ============================================================ */

	public static function sc_kiem_tra_diem( $atts ): string {
		$atts = shortcode_atts( array(
			'phone'     => '',
			'client_id' => '',
		), is_array( $atts ) ? $atts : array(), 'kiem_tra_diem' );

		$phone     = sanitize_text_field( (string) $atts['phone'] );
		$client_id = sanitize_text_field( (string) $atts['client_id'] );
		if ( $client_id === '' ) {
			$hook_data = get_transient( 'hook_data' );
			if ( is_array( $hook_data ) && ! empty( $hook_data['client_id'] ) ) {
				$client_id = (string) $hook_data['client_id'];
			}
		}

		$msgs    = array();
		$success = false;
		$total   = self::lookup_points( $client_id, $phone );

		$domain  = self::current_host();
		if ( $total === null || (int) $total <= 0 ) {
			$key = $client_id !== '' ? $client_id : ( $phone !== '' ? $phone : 'của bạn' );
			$msgs[] = "Không tìm thấy thông tin điểm cho mã khách hàng: {$key}. Hãy truy cập: https://{$domain}/tich-diem/?client_id={$client_id} . Kéo xuống phía dưới, điền số điện thoại vào ô tra cứu điểm.";
		} else {
			$key = $client_id !== '' ? $client_id : $phone;
			$msgs[] = "Số điểm hiện tại của {$key} trên hệ thống {$domain} là: {$total} điểm.<br>* Để đổi điểm vui lòng nhắn ĐỔI ĐIỂM<br>* Để tích điểm vui lòng nhắn TÍCH ĐIỂM. Để kiểm tra điểm chính xác hơn, hãy truy cập: https://{$domain}/tich-diem/?client_id={$client_id} . Kéo xuống phía dưới, điền số điện thoại vào ô tra cứu điểm.";
			$success = true;
		}

		return (string) wp_json_encode( array(
			'success' => $success,
			'msgs'    => $msgs,
		) );
	}

	/* ============================================================
	 * [doi_diem client_id="..." page="tich-diem"]
	 * ============================================================ */

	public static function sc_doi_diem( $atts ): string {
		$atts = shortcode_atts( array(
			'client_id' => '',
			'page'      => 'tich-diem',
		), is_array( $atts ) ? $atts : array(), 'doi_diem' );

		$client_id = sanitize_text_field( (string) $atts['client_id'] );
		$slug      = sanitize_title( (string) $atts['page'] ) ?: 'tich-diem';
		if ( $client_id === '' ) {
			$hook_data = get_transient( 'hook_data' );
			if ( is_array( $hook_data ) && ! empty( $hook_data['client_id'] ) ) {
				$client_id = (string) $hook_data['client_id'];
			}
		}

		$domain = self::current_host();
		$link   = "https://{$domain}/{$slug}/?client_id=" . rawurlencode( $client_id );

		$msgs   = array();
		$msgs[] = "Để đổi điểm, vui lòng truy cập: {$link} . Kéo xuống phía dưới, chọn phần thưởng và xác nhận đổi điểm.";

		return (string) wp_json_encode( array(
			'success' => true,
			'msgs'    => $msgs,
		) );
	}

	/* ============================================================
	 * Internal — point lookup chain
	 * ============================================================ */

	/**
	 * Returns total points (int) or NULL when no source produced a value.
	 */
	public static function lookup_points( string $client_id, string $phone = '' ): ?int {
		global $wpdb;

		// 1) gpt_logs SUM(so_diem_thay_doi) — legacy primary path.
		if ( $client_id !== '' ) {
			$logs_tbl = $wpdb->prefix . 'gpt_logs';
			// table_exists guard so we don't WP_Error in clean dev installs.
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_tbl ) );
			if ( $exists ) {
				$sum = $wpdb->get_var( $wpdb->prepare(
					"SELECT IFNULL(SUM(so_diem_thay_doi),0) FROM {$logs_tbl} WHERE client_id = %s",
					$client_id
				) );
				if ( $sum !== null && (int) $sum > 0 ) {
					return (int) $sum;
				}
			}
		}

		// 2) user_points − user_points_exchange via legacy helper.
		if ( $client_id !== '' && function_exists( 'get_total_points_by_client_id_user_point' ) ) {
			$val = get_total_points_by_client_id_user_point( $client_id );
			if ( $val !== null && (int) $val > 0 ) {
				return (int) $val;
			}
		}

		// 3) Inline fallback — mirror the helper above so we don't need that plugin file.
		if ( $client_id !== '' ) {
			$ups = $wpdb->prefix . 'user_points';
			$exc = $wpdb->prefix . 'user_points_exchange';
			$ups_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ups ) );
			if ( $ups_exists ) {
				$earned = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT IFNULL(SUM(user_points),0) FROM {$ups} WHERE client_id = %s",
					$client_id
				) );
				$spent = 0;
				$exc_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $exc ) );
				if ( $exc_exists ) {
					$spent = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT IFNULL(SUM(points),0) FROM {$exc} WHERE client_id = %s",
						$client_id
					) );
				}
				$diff = $earned - $spent;
				if ( $diff > 0 ) { return $diff; }
			}
		}

		// 4) phone path — only if legacy `get_total_points_by_phone` is loaded.
		if ( $phone !== '' && function_exists( 'get_total_points_by_phone' ) ) {
			$val = get_total_points_by_phone( $phone );
			if ( $val !== null && $val !== false && (int) $val > 0 ) {
				return (int) $val;
			}
		}

		return null;
	}

	private static function current_host(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		if ( $host === '' ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		return $host;
	}
}
