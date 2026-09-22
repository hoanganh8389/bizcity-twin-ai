<?php
/** PHASE-0.56 H-04 — hashed archive partition regression assertions. */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
$archive_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bzc-h04-' . substr( md5( (string) microtime( true ) ), 0, 12 );
@mkdir( $archive_root . DIRECTORY_SEPARATOR . 'bizcity-channel-conversations', 0777, true );
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		global $archive_root;
		return array( 'basedir' => $archive_root );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
require dirname( __DIR__, 4 ) . '/core/channel-gateway/includes/class-channel-conversation-archive.php';

$pass = 0; $fail = 0;
function check_h04( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; }
}

$account_key = 'a_' . str_repeat( 'a', 64 );
$peer_key = 'p_' . str_repeat( 'b', 64 );
$path = BizCity_Channel_Conversation_Archive::archive_file_from_keys( 'zalo_personal', $account_key, $peer_key, '2026-09' );
check_h04( 'valid hashed path resolves', '' !== $path );
check_h04( 'path keeps channel', false !== strpos( $path, DIRECTORY_SEPARATOR . 'zalo_personal' . DIRECTORY_SEPARATOR ) );
check_h04( 'path uses stored account key', false !== strpos( $path, DIRECTORY_SEPARATOR . $account_key . DIRECTORY_SEPARATOR ) );
check_h04( 'path uses stored peer key', false !== strpos( $path, DIRECTORY_SEPARATOR . $peer_key . DIRECTORY_SEPARATOR ) );
check_h04( 'path uses stored month', substr( $path, -strlen( '2026-09.jsonl' ) ) === '2026-09.jsonl' );
check_h04( 'raw account identity is not accepted', '' === BizCity_Channel_Conversation_Archive::archive_file_from_keys( 'zalo_personal', 'raw-account', $peer_key, '2026-09' ) );
check_h04( 'raw peer identity is not accepted', '' === BizCity_Channel_Conversation_Archive::archive_file_from_keys( 'zalo_personal', $account_key, 'raw-peer', '2026-09' ) );
check_h04( 'invalid month is rejected', '' === BizCity_Channel_Conversation_Archive::archive_file_from_keys( 'zalo_personal', $account_key, $peer_key, '2026-9' ) );
check_h04( 'channel alias remains supported', false !== strpos( BizCity_Channel_Conversation_Archive::archive_file_from_keys( 'web_widget', $account_key, $peer_key, '2026-09' ), DIRECTORY_SEPARATOR . 'webchat' . DIRECTORY_SEPARATOR ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
@rmdir( $archive_root . DIRECTORY_SEPARATOR . 'bizcity-channel-conversations' );
@rmdir( $archive_root );
exit( $fail > 0 ? 1 : 0 );
