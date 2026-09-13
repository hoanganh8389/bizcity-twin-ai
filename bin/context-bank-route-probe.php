<?php
/**
 * Context Bank single-host/single-blog route evidence probe.
 *
 * Run once per mapped host. This command is read-only and never provisions or
 * mutates Context Bank state.
 *
 * @package BizCity_Twin_AI\Bin
 * @since 2026-09-08 (PHASE-1.33B)
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "context-bank-route-probe.php must be run from CLI.\n" );
	exit( 2 );
}

$options = array( 'wp-root' => '', 'host' => '', 'blog' => 0, 'user' => 0, 'confirm' => '' );
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( strpos( $argument, '--' ) !== 0 || strpos( $argument, '=' ) === false ) {
		continue;
	}
	list( $key, $value ) = explode( '=', substr( $argument, 2 ), 2 );
	if ( array_key_exists( $key, $options ) ) {
		$options[ $key ] = $value;
	}
}

$fail = static function ( $message, $code = 2 ) {
	// [2026-09-08 12:40 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33B — keep per-host route evidence read-only and fail closed.
	fwrite( STDERR, (string) $message . "\n" );
	exit( (int) $code );
};

if ( (string) $options['confirm'] !== 'ROUTE' ) {
	$fail( 'Refusing route probe: pass --confirm=ROUTE.' );
}
if ( (string) $options['host'] === '' || preg_match( '/[^A-Za-z0-9.:-]/', (string) $options['host'] ) ) {
	$fail( 'Refusing route probe: pass an explicit mapped --host.' );
}
if ( (int) $options['blog'] <= 0 || (int) $options['user'] <= 0 ) {
	$fail( 'Refusing route probe: pass positive --blog and --user IDs.' );
}
$wp_root = (string) $options['wp-root'];
if ( $wp_root === '' ) {
	$wp_root = (string) ( getenv( 'BIZCITY_WP_ROOT' ) ?: '' );
}
if ( $wp_root === '' || ! is_file( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) || ! is_readable( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) ) {
	$fail( 'Cannot locate readable wp-load.php. Use --wp-root=/path/to/wordpress.' );
}
if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
	$fail( 'Refusing route probe inside Diagnostics CLI context.', 4 );
}

$_SERVER['HTTP_HOST'] = (string) $options['host'];
$_SERVER['SERVER_NAME'] = (string) $options['host'];
define( 'WP_USE_THEMES', false );
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( (int) $options['user'] );
}
if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
	$fail( 'Refusing route probe: --user is not a tenant administrator.', 3 );
}

$plugin_root = dirname( __DIR__ );
$safe_loader = $plugin_root . '/core/helper/class-bizcity-safe-loader.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
	require_once $safe_loader;
}
$context_bootstrap = $plugin_root . '/core/context-bank/bootstrap.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! is_file( $context_bootstrap ) || ! is_readable( $context_bootstrap ) || ! BizCity_Safe_Loader::require_file( $context_bootstrap, 'context_bank.route_probe' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
	$fail( 'Context Bank route owner is unavailable.', 3 );
}

$route = BizCity_Context_Bank_Ledger::route_evidence();
global $wpdb;
$physical_identity = (string) ( $route['physical_db'] ?? $route['database'] ?? $route['dbname'] ?? ( $wpdb->dbname ?? '' ) );
$expected_shard = '';
$blogs_table = isset( $wpdb->base_prefix ) ? $wpdb->base_prefix . 'blogs' : '';
if ( $blogs_table !== '' && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
	$expected_shard = (string) $wpdb->get_var( $wpdb->prepare( "SELECT bizname FROM {$blogs_table} WHERE blog_id=%d LIMIT 1", (int) $options['blog'] ) );
}
$route_shard = (string) ( $route['bizname'] ?? $route['shard'] ?? ( property_exists( $wpdb, 'current_bizname' ) ? $wpdb->current_bizname : '' ) );
$normalize_shard = static function ( $value ) {
	$value = strtolower( trim( (string) $value ) );
	return preg_replace( '/^slave_/', 'slave', $value );
};
$shard_mismatch = $expected_shard !== '' && $route_shard !== '' && $normalize_shard( $expected_shard ) !== $normalize_shard( $route_shard );
$table = BizCity_Context_Bank_Ledger::table();
$table_ok = function_exists( 'bizcity_tbl_exists' ) ? bizcity_tbl_exists( $table ) : ( class_exists( 'BizCity_Table_Metadata' ) && BizCity_Table_Metadata::table_exists( $table ) );
$result = array(
	'contract' => 'context-bank-route-probe',
	'version' => '1',
	'host' => (string) $options['host'],
	'blog_id' => (int) $options['blog'],
	'domain' => function_exists( 'get_blog_details' ) && get_blog_details( (int) $options['blog'] ) ? (string) get_blog_details( (int) $options['blog'] )->domain : '',
	'route_ok' => ! empty( $route['ok'] ),
	'route_reason' => (string) ( $route['reason'] ?? '' ),
	'expected_shard' => $expected_shard,
	'route_shard' => $route_shard,
	'shard_mismatch' => $shard_mismatch,
	'ledger_table' => $table_ok,
	'physical_fingerprint' => $physical_identity !== '' ? hash( 'sha256', $physical_identity ) : '',
	'mutation_performed' => false,
);
$result['status'] = $result['route_ok'] && ! $shard_mismatch && $result['ledger_table'] && $result['physical_fingerprint'] !== '' ? 'pass' : 'fail';
$result['reason'] = $result['status'] === 'pass' ? '' : ( $shard_mismatch ? 'route_shard_mismatch' : ( $result['route_reason'] !== '' ? $result['route_reason'] : ( ! $result['ledger_table'] ? 'ledger_not_provisioned' : 'physical_route_unavailable' ) ) );
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $result['status'] === 'pass' ? 0 : 1 );
