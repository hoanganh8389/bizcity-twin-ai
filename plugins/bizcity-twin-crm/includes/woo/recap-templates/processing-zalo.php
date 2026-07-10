<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: processing × zalo
return function ( array $v ): string {
	$num   = $v['order_number'] ?? '';
	$track = $v['tracking_url'] ?? '';
	$store = $v['store_name']   ?? 'Cua hang';
	$lines = array();
	$lines[] = "DANG XU LY DON #" . $num . " — " . $store;
	$lines[] = "Don hang dang duoc dong goi va chuan bi giao cho don vi van chuyen.";
	if ( $track !== '' ) { $lines[] = "Theo doi don: " . $track; }
	return implode( "\n", $lines );
};
