<?php
/** PHASE-0.56 H-05 — grouped archive batch-read performance/contract assertions. */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
$archive_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bzc-h05-' . substr( md5( (string) microtime( true ) ), 0, 12 );
$root = $archive_root . DIRECTORY_SEPARATOR . 'bizcity-channel-conversations';
$account_key = 'a_' . str_repeat( 'a', 64 );
$peer_key = 'p_' . str_repeat( 'b', 64 );
$file = $root . DIRECTORY_SEPARATOR . 'zalo_personal' . DIRECTORY_SEPARATOR . $account_key . DIRECTORY_SEPARATOR . $peer_key;
@mkdir( $file, 0777, true );
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() { global $archive_root; return array( 'basedir' => $archive_root ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { return 'test-archive-key'; }
}
if ( ! class_exists( 'BizCity_Codec' ) ) {
	class BizCity_Codec {
		public static function decrypt_json_payload( $ciphertext, $key, $prefix, $context ) { return json_decode( base64_decode( $ciphertext ), true ); }
	}
}
require dirname( __DIR__, 4 ) . '/core/channel-gateway/includes/class-channel-conversation-archive.php';

$rows = array();
for ( $i = 1; $i <= 10000; $i++ ) {
	$entry = array( 'crm_message_id' => $i, 'event_type' => 'message', 'record_id' => 'crm_' . $i, 'event_uuid' => 'evt_' . $i, 'content_ciphertext' => base64_encode( json_encode( array( 'content' => 'message ' . $i, 'body' => '', 'content_type' => 'text' ) ) ) );
	$line = json_encode( $entry ) . "\n";
	$archive_file = $file . DIRECTORY_SEPARATOR . '2026-09.jsonl';
	clearstatcache( true, $archive_file );
	$offset = file_exists( $archive_file ) ? (int) filesize( $archive_file ) : 0;
	file_put_contents( $archive_file, $line, FILE_APPEND );
	if ( $i > 9950 ) { $rows[] = array( 'relative_file' => 'zalo_personal/' . $account_key . '/' . $peer_key . '/2026-09.jsonl', 'byte_offset' => $offset, 'line_bytes' => strlen( $line ), 'row_hash' => hash( 'sha256', $line ), 'content_hash' => hash( 'sha256', rtrim( $line, "\r\n" ) ), 'crm_message_id' => $i ); }
}
$started = microtime( true );
$result = BizCity_Channel_Conversation_Archive::read_batch( $rows, 800 );
$elapsed_ms = ( microtime( true ) - $started ) * 1000;
$pass = 0; $fail = 0;
function check_h05( string $label, bool $condition ): void { global $pass, $fail; if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }
check_h05( 'offset batch returns 50 items', 50 === count( $result['items'] ) );
check_h05( 'offset batch scans one file', 1 === (int) $result['files_scanned'] );
check_h05( 'offset batch has no partial result', empty( $result['partial'] ) );
if ( empty( $result['items'][0]['ok'] ) ) { echo 'offset_error=' . json_encode( $result['items'][0] ) . "\n"; }
check_h05( 'offset batch validates content', ! empty( $result['items'][0]['ok'] ) && 'message 9951' === $result['items'][0]['content'] );
check_h05( 'offset batch stays within target budget', $elapsed_ms < 800 );

$legacy = array();
for ( $i = 9951; $i <= 10000; $i++ ) { $legacy[] = array( 'relative_file' => 'zalo_personal/' . $account_key . '/' . $peer_key . '/2026-09.jsonl', 'crm_message_id' => $i ); }
$legacy_result = BizCity_Channel_Conversation_Archive::read_batch( $legacy, 800 );
check_h05( 'legacy batch scans one file', 1 === (int) $legacy_result['files_scanned'] );
check_h05( 'legacy batch returns 50 items', 50 === count( $legacy_result['items'] ) );
check_h05( 'legacy batch validates content', ! empty( $legacy_result['items'][0]['ok'] ) && 'message 9951' === $legacy_result['items'][0]['content'] );

printf( "\n%d passed, %d failed (offset batch %.1f ms)\n", $pass, $fail, $elapsed_ms );
@unlink( $file . DIRECTORY_SEPARATOR . '2026-09.jsonl' );
@rmdir( $file ); @rmdir( dirname( $file ) ); @rmdir( dirname( dirname( $file ) ) ); @rmdir( $root ); @rmdir( $archive_root );
exit( $fail > 0 ? 1 : 0 );
