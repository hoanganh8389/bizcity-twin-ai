<?php
/**
 * Recap templates — New Order (new_order)
 *
 * Được render bởi BizCity_CRM_Woo_Order_Recap_Notifier.
 * Trả về string đã substitute {{var}} sẵn (không có {{}} còn sót).
 *
 * Biến khả dụng (được pass qua $vars[]):
 *   order_number, order_total, currency, items_summary,
 *   shipping_name, shipping_phone, shipping_addr1, shipping_city,
 *   payment_method_title, payment_url, tracking_url, store_name
 *
 * @since PHASE-0.38.W2.2 (2026-06-07)
 */

// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: new_order × facebook

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
	$lines[] = "🛍️ *Đơn hàng mới #{$num}* — {$store}";
	$lines[] = '';
	if ( $name !== '' ) {
		$lines[] = "👤 Khách: {$name}";
	}
	if ( $items !== '' ) {
		$lines[] = '';
		$lines[] = "📦 Sản phẩm:";
		$lines[] = $items;
	}
	$lines[] = '';
	$lines[] = "💰 Tổng: {$total} {$cur}";

	if ( $pay !== '' ) {
		$lines[] = '';
		$lines[] = "💳 Thanh toán: {$pay}";
	}
	if ( $track !== '' ) {
		$lines[] = '';
		$lines[] = "📍 Theo dõi đơn: {$track}";
	}
	$lines[] = '';
	$lines[] = "✅ Cảm ơn bạn đã đặt hàng!";

	return implode( "\n", $lines );
};
