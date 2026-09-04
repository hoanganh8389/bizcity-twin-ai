<?php
/**
 * Internal worker for the H4 exact-key concurrency diagnostics probe.
 */

if ( $argc < 4 ) {
	exit( 2 );
}

$wp_load = (string) $argv[1];
$record_json = base64_decode( (string) $argv[2], true );
$worker_host = isset( $argv[3] ) ? preg_replace( '/[^A-Za-z0-9.:-]/', '', (string) $argv[3] ) : '';
if ( ! is_file( $wp_load ) || ! is_readable( $wp_load ) || ! is_string( $record_json ) ) {
	exit( 2 );
}

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = $worker_host !== '' ? $worker_host : ( getenv( 'HTTP_HOST' ) ?: 'localhost' );
if ( ! defined( 'BIZCITY_DIAGNOSTICS_CLI' ) ) {
	define( 'BIZCITY_DIAGNOSTICS_CLI', true );
}
require $wp_load;

if ( ! class_exists( 'BizCity_Router_License_Ledger' ) ) {
	exit( 3 );
}

$record = json_decode( $record_json, true );
if ( ! is_array( $record ) ) {
	exit( 2 );
}
$result = BizCity_Router_License_Ledger::append_grant_with_period_lock( $record );
$receipt = is_array( $result ) ? array(
	'success'         => ! empty( $result['success'] ),
	'id'              => absint( $result['id'] ?? 0 ),
	'replayed'        => ! empty( $result['replayed'] ),
	'key_id'          => absint( $record['key_id'] ?? 0 ),
	'idempotency_key' => sanitize_text_field( (string) ( $record['idempotency_key'] ?? '' ) ),
) : array( 'success' => false, 'key_id' => absint( $record['key_id'] ?? 0 ) );
echo '__BIZCITY_H4__' . base64_encode( wp_json_encode( $receipt ) );
