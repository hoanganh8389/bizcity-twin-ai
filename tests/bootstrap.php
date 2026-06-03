<?php
/**
 * PHPUnit bootstrap for bizcity-twin-ai unit suite.
 *
 * Pure-helper unit tests do NOT need a WordPress runtime. We only autoload
 * Composer + selected helper files that are framework-pure (no WP core deps
 * outside of stub functions defined here).
 *
 * For integration testing (REST, hooks, schema), use `php bin/diagnostics-run.php`
 * which boots a real WP install — that path is wired into CI separately.
 *
 * @package BizCity_Twin_AI
 */

if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "PHPUnit suite requires PHP 7.4+. Got " . PHP_VERSION . PHP_EOL );
    exit( 1 );
}

$root = dirname( __DIR__ );

// Composer autoload (PSR-4 + classmap for contracts).
$autoload = $root . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
    fwrite( STDERR, "vendor/autoload.php missing — run `composer install` first.\n" );
    exit( 1 );
}
require $autoload;

// Minimal WP function stubs so framework-pure helpers can be exercised
// without booting WordPress. Extend ONLY when a stubbed function is
// genuinely required by a unit under test.
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        return $value;
    }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $tag /*, ...$args */ ) { /* noop */ }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $cb, $priority = 10, $accepted = 1 ) { return true; }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $cb, $priority = 10, $accepted = 1 ) { return true; }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}
if ( ! defined( 'WP_DEBUG' ) ) {
    define( 'WP_DEBUG', false );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}
