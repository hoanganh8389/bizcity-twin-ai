<?php
/**
 * Internal worker for the JSONL idempotency diagnostics probe.
 *
 * This entrypoint is invoked only by the focused diagnostics probe. It defines
 * the diagnostics isolation context before loading WordPress.
 *
 * @package BizCity_Twin_AI
 */

// [2026-09-02 Johnny Chu] PHASE-1.30-G2 - isolate the two-process fixture in a guarded canonical worker entrypoint.
if ( $argc < 6 ) {
	exit( 2 );
}

$wp_load = (string) $argv[1];
$contract_id = (string) $argv[2];
$folder = (string) $argv[3];
$module = (string) $argv[4];
$record_json = base64_decode( (string) $argv[5], true );
// [2026-09-02 Johnny Chu] PHASE-1.30-G2 - filter the host with native PHP because WordPress sanitizers are unavailable before wp-load.php.
$worker_host = isset( $argv[6] ) ? preg_replace( '/[^A-Za-z0-9.:-]/', '', (string) $argv[6] ) : '';
if ( ! is_file( $wp_load ) || ! is_readable( $wp_load ) || ! is_string( $record_json ) ) {
	exit( 2 );
}

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = $worker_host !== '' ? $worker_host : ( getenv( 'HTTP_HOST' ) ?: 'localhost' );
if ( ! defined( 'BIZCITY_DIAGNOSTICS_CLI' ) ) {
	define( 'BIZCITY_DIAGNOSTICS_CLI', true );
}
require $wp_load;

if ( ! class_exists( 'BizCity_Log_Contract_Registry' ) || ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
	exit( 3 );
}

$record = json_decode( $record_json, true );
if ( ! is_array( $record ) ) {
	exit( 2 );
}
BizCity_Log_Contract_Registry::register( $contract_id, array(
	'owner_module' => 'core/diagnostics',
	'label' => 'G2 JSONL idempotency probe',
	'jsonl_folder' => $folder,
	'jsonl_module' => $module,
	'retention_days' => 1,
	'indexed' => true,
) );
$result = BizCity_JSONL_File_Logger::write_contract_record( $contract_id, $record );
echo '__BIZCITY_G2__' . base64_encode( wp_json_encode( is_array( $result ) ? $result : array() ) );
