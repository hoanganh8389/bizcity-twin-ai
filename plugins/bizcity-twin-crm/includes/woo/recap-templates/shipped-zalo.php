<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: shipped × zalo
return function ( array $v ): string {
	$num      = $v['order_number']      ?? '';
	$tracking = $v['tracking_number']   ?? '';
	$provider = $v['shipping_provider'] ?? '';
	$track    = $v['tracking_url']      ?? '';
	$store    = $v['store_name']        ?? 'Cua hang';
	$lines    = array();
	$lines[]  = "DA GIAO VAN CHUYEN — Don #" . $num . " — " . $store;
	if ( $tracking !== '' ) {
		$prov_str = $provider !== '' ? ' (' . $provider . ')' : '';
		$lines[] = "Ma van don: " . $tracking . $prov_str;
	}
	if ( $track !== '' ) { $lines[] = "Theo doi lo hang: " . $track; }
	$lines[] = "Don se den trong 1-3 ngay lam viec.";
	return implode( "\n", $lines );
};
