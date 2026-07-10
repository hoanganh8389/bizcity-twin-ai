<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: shipped × facebook
return function ( array $v ): string {
	$num      = $v['order_number']    ?? '';
	$tracking = $v['tracking_number'] ?? '';
	$provider = $v['shipping_provider'] ?? '';
	$track    = $v['tracking_url']    ?? '';
	$store    = $v['store_name']      ?? 'Cửa hàng';
	$lines    = array();
	$lines[]  = "🚚 *Đơn hàng #{$num} đã giao cho vận chuyển!* — {$store}";
	if ( $tracking !== '' ) {
		$prov_str = $provider !== '' ? " ({$provider})" : '';
		$lines[] = "Mã vận đơn: {$tracking}{$prov_str}";
	}
	if ( $track !== '' ) { $lines[] = "📍 Theo dõi lô hàng: {$track}"; }
	$lines[] = "Đơn hàng sẽ đến trong 1–3 ngày làm việc.";
	return implode( "\n", $lines );
};
