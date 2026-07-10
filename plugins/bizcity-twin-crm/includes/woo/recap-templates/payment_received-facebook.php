<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: payment_received × facebook
return function ( array $v ): string {
	$num   = $v['order_number'] ?? '';
	$total = $v['order_total']  ?? '0';
	$cur   = $v['currency']     ?? 'VND';
	$track = $v['tracking_url'] ?? '';
	$store = $v['store_name']   ?? 'Cửa hàng';
	$lines = array();
	$lines[] = "✅ *Thanh toán thành công* — {$store}";
	$lines[] = "Đơn hàng #{$num} đã nhận được {$total} {$cur}.";
	$lines[] = "Chúng tôi đang chuẩn bị hàng cho bạn!";
	if ( $track !== '' ) { $lines[] = "📍 Theo dõi: {$track}"; }
	return implode( "\n", $lines );
};
