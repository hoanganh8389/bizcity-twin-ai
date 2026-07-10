<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: delivered × zalo
return function ( array $v ): string {
	$num   = $v['order_number'] ?? '';
	$track = $v['tracking_url'] ?? '';
	$store = $v['store_name']   ?? 'Cua hang';
	$lines = array();
	$lines[] = "DA GIAO THANH CONG — Don #" . $num . " — " . $store;
	$lines[] = "Cam on ban da mua hang tai " . $store . ". Hy vong ban hai long!";
	if ( $track !== '' ) { $lines[] = "Danh gia don hang: " . $track; }
	return implode( "\n", $lines );
};
