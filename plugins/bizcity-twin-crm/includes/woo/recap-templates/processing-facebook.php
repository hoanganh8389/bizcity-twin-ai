<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: processing × facebook
return function ( array $v ): string {
	$num   = $v['order_number'] ?? '';
	$track = $v['tracking_url'] ?? '';
	$store = $v['store_name']   ?? 'Cửa hàng';
	$lines = array();
	$lines[] = "⚙️ *Đang xử lý đơn hàng #{$num}* — {$store}";
	$lines[] = "Đơn hàng của bạn đang được đóng gói và chuẩn bị giao.";
	if ( $track !== '' ) { $lines[] = "📍 Theo dõi: {$track}"; }
	return implode( "\n", $lines );
};
