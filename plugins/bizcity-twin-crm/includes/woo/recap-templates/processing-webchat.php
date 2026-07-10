<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: processing × webchat
return function ( array $v ): string {
	$num   = $v['order_number'] ?? '';
	$track = $v['tracking_url'] ?? '';
	$store = $v['store_name']   ?? 'Cửa hàng';
	$lines = array();
	$lines[] = "⚙️ Đơn #{$num} đang được xử lý — {$store}";
	$lines[] = "Chúng tôi đang đóng gói hàng và bàn giao cho đơn vị vận chuyển.";
	if ( $track !== '' ) { $lines[] = "📍 Theo dõi: {$track}"; }
	return implode( "\n", $lines );
};
