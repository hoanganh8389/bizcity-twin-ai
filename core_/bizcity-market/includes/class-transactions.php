<?php
/**
 * Class Bizcity_Transactions
 * Lấy danh sách giao dịch theo hub_blog_id hiện tại
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Bizcity_Transactions {
	public static function get_transactions_for_current_hub() {
		global $wpdb;
		$hub_blog_id = get_current_blog_id();
		$table = BizCity_Market_DB::t_events(); // Đổi tên bảng phù hợp
		$sql = $wpdb->prepare(
			"SELECT * FROM $table WHERE hub_blog_id = %d ORDER BY id DESC",
			$hub_blog_id
		);
		return $wpdb->get_results($sql);
	}
}
