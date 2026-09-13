<?php
/**
 * Read-only readiness report for explicitly named legacy tables.
 *
 * This command does not mark ready_to_drop and does not DROP/TRUNCATE tables.
 *
 * @package BizCity_Twin_AI\Bin
 * @since 2026-09-09 (PHASE-1.33B)
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "legacy-table-drop-readiness.php must be run from CLI.\n" );
	exit( 2 );
}

$options = array( 'wp-root' => '', 'host' => '', 'blog' => 0, 'user' => 0, 'tables' => '', 'confirm' => 'READINESS' );
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
	// [2026-09-09 09:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33B — keep drop readiness read-only and approval-bound.
	fwrite( STDERR, (string) $message . "\n" );
	exit( (int) $code );
};

if ( 'READINESS' !== (string) $options['confirm'] ) {
	$fail( 'Refusing readiness report: pass --confirm=READINESS.' );
}
if ( (string) $options['host'] === '' || preg_match( '/[^A-Za-z0-9.:-]/', (string) $options['host'] ) ) {
	$fail( 'Refusing readiness report: pass an explicit mapped --host.' );
}
if ( (int) $options['blog'] <= 0 || (int) $options['user'] <= 0 ) {
	$fail( 'Refusing readiness report: pass positive --blog and --user IDs.' );
}
$tables = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $options['tables'] ) ) ) );
if ( empty( $tables ) ) {
	$fail( 'Refusing readiness report: pass --tables=suffix1,suffix2.' );
}
$wp_root = (string) $options['wp-root'];
if ( $wp_root === '' ) {
	$wp_root = (string) ( getenv( 'BIZCITY_WP_ROOT' ) ?: '' );
}
if ( $wp_root === '' || ! is_file( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) || ! is_readable( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) ) {
	$fail( 'Cannot locate readable wp-load.php. Use --wp-root=/path/to/wordpress.' );
}
$_SERVER['HTTP_HOST'] = (string) $options['host'];
$_SERVER['SERVER_NAME'] = (string) $options['host'];
define( 'WP_USE_THEMES', false );
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';
if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( (int) $options['user'] );
}
if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
	$fail( 'Refusing readiness report: --user is not a tenant administrator.', 3 );
}

$plugin_root = dirname( __DIR__ );
$safe_loader = $plugin_root . '/core/helper/class-bizcity-safe-loader.php';
$registry_file = $plugin_root . '/core/diagnostics/includes/class-diagnostics-table-registry.php';
$policy_file = $plugin_root . '/core/helper/class-bizcity-legacy-table-policy.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
	require_once $safe_loader;
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! BizCity_Safe_Loader::require_file( $registry_file, 'legacy_table.readiness.registry' ) || ! BizCity_Safe_Loader::require_file( $policy_file, 'legacy_table.readiness.policy' ) ) {
	$fail( 'Legacy table registry or policy is unavailable.', 3 );
}

$catalog = array();
foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
	$name = sanitize_key( (string) ( $row['name'] ?? '' ) );
	if ( $name !== '' ) {
		$catalog[ $name ] = $row;
	}
}
global $wpdb;
$results = array();
foreach ( $tables as $suffix ) {
	$meta = isset( $catalog[ $suffix ] ) && is_array( $catalog[ $suffix ] ) ? $catalog[ $suffix ] : array();
	$scope = (string) ( $meta['prefix_scope'] ?? 'blog' );
	$physical = 'base' === $scope ? $wpdb->base_prefix . $suffix : $wpdb->prefix . $suffix;
	$exists = function_exists( 'bizcity_tbl_exists' ) ? bizcity_tbl_exists( $physical ) : false;
	$count = $exists ? $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $physical ) . '`' ) : null;
	$state = BizCity_Legacy_Table_Policy::get_record( $suffix );
	$zero = $exists && false !== $count && 0 === (int) $count;
	$ready = $zero && 'blog' === $scope && empty( $meta['quarantine_only'] ) && 'ready_to_drop' === (string) ( $state['state'] ?? '' ) && (string) ( $state['approval_ref'] ?? '' ) !== '';
	$results[] = array(
		'name' => $suffix,
		'physical_table' => $physical,
		'owner' => (string) ( $meta['module'] ?? $meta['owner'] ?? '' ),
		'prefix_scope' => $scope,
		'quarantine_only' => ! empty( $meta['quarantine_only'] ),
		'physical_exists' => $exists,
		'row_count' => false === $count ? null : ( null === $count ? null : (int) $count ),
		'lifecycle_state' => (string) ( $state['state'] ?? '' ),
		'approval_ref_present' => (string) ( $state['approval_ref'] ?? '' ) !== '',
		'ready_to_drop' => $ready,
		'next_step' => ! $exists ? 'absent_no_action' : ( ! $zero ? 'owner_parity_and_zero_row' : ( 'base' === $scope ? 'network_owner_signoff' : ( ! empty( $meta['quarantine_only'] ) ? 'quarantine_owner_signoff' : ( ! $ready ? 'mark_ready_to_drop_with_approval_ref' : 'eligible_for_separate_approved_drop' ) ) ) ),
		'mutation_performed' => false,
	);
}

echo wp_json_encode( array(
	'contract' => 'legacy-table-drop-readiness',
	'version' => '1',
	'host' => (string) $options['host'],
	'blog_id' => (int) $options['blog'],
	'mutation_performed' => false,
	'rows' => $results,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 0 );
