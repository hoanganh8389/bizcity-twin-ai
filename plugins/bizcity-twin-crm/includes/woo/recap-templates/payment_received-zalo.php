<?php
// [2026-06-07 Johnny Chu] PHASE-0.38.W2.2 — recap template: payment_received × zalo
return function ( array $v ): string {
	$num   = $v['order_number'] ?? '';
	$total = $v['order_total']  ?? '0';
	$cur   = $v['currency']     ?? 'VND';
	$track = $v['tracking_url'] ?? '';
	$store = $v['store_name']   ?? 'Cua hang';
	$lines = array();
	$lines[] = "THANH TOAN THANH CONG — " . $store;
	$lines[] = "Don hang #" . $num . " da nhan " . $total . ' ' . $cur;
	$lines[] = "Chung toi dang chuan bi hang.";
	if ( $track !== '' ) { $lines[] = "Theo doi don: " . $track; }
	return implode( "\n", $lines );
};
