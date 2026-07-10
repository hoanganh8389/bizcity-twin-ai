<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: shipped × webchat
return function ( array $v ): string {
	$num      = $v['order_number']      ?? '';
	$tracking = $v['tracking_number']   ?? '';
	$provider = $v['shipping_provider'] ?? '';
	$track    = $v['tracking_url']      ?? '';
	$store    = $v['store_name']        ?? 'Cửa hàng';
	$lines    = array();
	$lines[]  = "🚚 Đơn #{$num} đã giao vận chuyển — {$store}";
	if ( $tracking !== '' ) {
		$prov_str = $provider !== '' ? " ({$provider})" : '';
		$lines[] = "Mã vận đơn: {$tracking}{$prov_str}";
	}
	if ( $track !== '' ) { $lines[] = "📍 Theo dõi: {$track}"; }
	$lines[] = "Hàng sẽ đến trong 1–3 ngày làm việc.";
	return implode( "\n", $lines );
};
