<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: payment_received × webchat
return function ( array $v ): string {
	$num   = $v['order_number'] ?? '';
	$total = $v['order_total']  ?? '0';
	$cur   = $v['currency']     ?? 'VND';
	$track = $v['tracking_url'] ?? '';
	$store = $v['store_name']   ?? 'Cửa hàng';
	$lines = array();
	$lines[] = "✅ Thanh toán thành công — {$store}";
	$lines[] = "Đơn #${num} đã nhận được {$total} {$cur}. Hàng đang được chuẩn bị!";
	if ( $track !== '' ) { $lines[] = "📍 Theo dõi đơn: {$track}"; }
	return implode( "\n", $lines );
};
