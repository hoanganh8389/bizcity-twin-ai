<?php
/**
 * Recap template — New Order × Zalo OA
 *
 * Zalo không hỗ trợ markdown bold (*text*). Dùng text thuần + emoji.
 * Giới hạn 2000 ký tự — nên giữ ngắn gọn.
 *
 * @since PHASE-0.38.W2.2 (2026-06-07)
 */

// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: new_order × zalo

return function ( array $v ): string {
	$items = $v['items_summary'] ?? '';
	$total = $v['order_total']   ?? '0';
	$cur   = $v['currency']      ?? 'VND';
	$num   = $v['order_number']  ?? '';
	$name  = $v['shipping_name'] ?? '';
	$pay   = $v['payment_url']   ?? '';
	$track = $v['tracking_url']  ?? '';
	$store = $v['store_name']    ?? 'Cửa hàng';

	$lines = array();
	$lines[] = "DON HANG MOI #" . $num . " — " . $store;
	$lines[] = str_repeat( '-', 30 );
	if ( $name !== '' ) {
		$lines[] = "Khach: " . $name;
	}
	if ( $items !== '' ) {
		$lines[] = 'San pham:';
		$lines[] = $items;
	}
	$lines[] = "Tong: " . $total . ' ' . $cur;

	if ( $pay !== '' ) {
		$lines[] = 'Thanh toan: ' . $pay;
	}
	if ( $track !== '' ) {
		$lines[] = 'Theo doi don: ' . $track;
	}
	$lines[] = 'Cam on ban da dat hang!';

	return implode( "\n", $lines );
};
