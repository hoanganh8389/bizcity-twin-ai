<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: delivered × webchat
return function ( array $v ): string {
	$num   = $v['order_number'] ?? '';
	$track = $v['tracking_url'] ?? '';
	$store = $v['store_name']   ?? 'Cửa hàng';
	$lines = array();
	$lines[] = "📬 Đơn #{$num} đã giao thành công — {$store}";
	$lines[] = "Cảm ơn bạn đã mua hàng! Hy vọng bạn hài lòng với sản phẩm.";
	if ( $track !== '' ) { $lines[] = "⭐ Đánh giá đơn hàng: {$track}"; }
	return implode( "\n", $lines );
};
