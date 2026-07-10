<?php
/**
 * Recap template — New Order × WebChat
 *
 * WebChat hỗ trợ HTML nhẹ (bold, br). Dùng plain-text an toàn để tương thích
 * mọi theme; WebChat renderer tự wrap <br>.
 *
 * @since PHASE-0.38.W2.2 (2026-06-07)
 */

// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: new_order × webchat

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
	$lines[] = "🛍️ Đơn hàng mới #{$num} — {$store}";
	if ( $name !== '' ) {
		$lines[] = "👤 Khách: {$name}";
	}
	if ( $items !== '' ) {
		$lines[] = "📦 Sản phẩm:\n" . $items;
	}
	$lines[] = "💰 Tổng thanh toán: {$total} {$cur}";
	if ( $pay !== '' ) {
		$lines[] = "💳 Link thanh toán: {$pay}";
	}
	if ( $track !== '' ) {
		$lines[] = "📍 Theo dõi đơn hàng: {$track}";
	}
	$lines[] = "✅ Cảm ơn bạn! Chúng tôi sẽ xử lý ngay.";

	return implode( "\n", $lines );
};
