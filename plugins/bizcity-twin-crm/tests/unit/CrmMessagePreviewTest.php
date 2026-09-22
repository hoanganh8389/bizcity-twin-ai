<?php
/** PHASE-0.56 H-02 — standalone bounded CRM message preview assertions. */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
}
require __DIR__ . '/../../includes/class-repository.php';

$pass = 0; $fail = 0;
function check_preview( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; }
}

check_preview( 'collapses whitespace', 'Xin chao khach' === BizCity_CRM_Repository::make_content_preview( " Xin\nchao   khach " ) );
check_preview( 'strips markup', 'Bao gia 100k' === BizCity_CRM_Repository::make_content_preview( '<b>Bao gia</b> 100k' ) );
check_preview( 'image content type placeholder', '[Ảnh]' === BizCity_CRM_Repository::make_content_preview( '', 'image' ) );
check_preview( 'attachment image placeholder', '[Ảnh]' === BizCity_CRM_Repository::make_content_preview( '', 'text', array( array( 'file_type' => 'image' ) ) ) );
check_preview( 'attachment file placeholder', '[Tệp]' === BizCity_CRM_Repository::make_content_preview( '', 'text', array( array( 'file_type' => 'file' ) ) ) );
check_preview( 'sticker placeholder', '[Sticker]' === BizCity_CRM_Repository::make_content_preview( '', 'sticker' ) );
check_preview( 'audio placeholder', '[Audio]' === BizCity_CRM_Repository::make_content_preview( '', 'audio' ) );
check_preview( 'video placeholder', '[Video]' === BizCity_CRM_Repository::make_content_preview( '', 'video' ) );
$long = str_repeat( 'x', 400 );
check_preview( 'bounded to 255 characters', 255 === strlen( BizCity_CRM_Repository::make_content_preview( $long ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
