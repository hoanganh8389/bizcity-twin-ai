<?php
/**
 * Read-only inventory of the deprecated-table catalog for one tenant blog.
 *
 * This command never drops, truncates, repairs, provisions or mutates a table.
 * It reports physical existence, row count, lifecycle state and drop blockers.
 *
 * @package BizCity_Twin_AI\Bin
 * @since 2026-09-08 (PHASE-1.33B)
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "legacy-table-inventory.php must be run from CLI.\n" );
	exit( 2 );
}

$options = array( 'wp-root' => '', 'host' => '', 'blog' => 0, 'user' => 0, 'confirm' => '', 'format' => 'json' );
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
	// [2026-09-08 10:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.33B — keep legacy inventory read-only before any table-drop approval.
	fwrite( STDERR, (string) $message . "\n" );
	exit( (int) $code );
};

if ( (string) $options['confirm'] !== 'INVENTORY' ) {
	$fail( 'Refusing legacy inventory: pass --confirm=INVENTORY.' );
}
if ( (string) $options['host'] === '' || preg_match( '/[^A-Za-z0-9.:-]/', (string) $options['host'] ) ) {
	$fail( 'Refusing legacy inventory: pass an explicit mapped --host.' );
}
if ( (int) $options['blog'] <= 0 || (int) $options['user'] <= 0 ) {
	$fail( 'Refusing legacy inventory: pass positive --blog and --user IDs.' );
}
$wp_root = (string) $options['wp-root'];
if ( $wp_root === '' ) {
	$wp_root = (string) ( getenv( 'BIZCITY_WP_ROOT' ) ?: '' );
}
if ( $wp_root === '' || ! is_file( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) || ! is_readable( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) ) {
	$fail( 'Cannot locate readable wp-load.php. Use --wp-root=/path/to/wordpress.' );
}
if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
	$fail( 'Refusing legacy inventory inside Diagnostics CLI context.', 4 );
}

$_SERVER['HTTP_HOST'] = (string) $options['host'];
$_SERVER['SERVER_NAME'] = (string) $options['host'];
define( 'WP_USE_THEMES', false );
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';
if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( (int) $options['user'] );
}
if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
	$fail( 'Refusing legacy inventory: --user is not a tenant administrator.', 3 );
}

$plugin_root = dirname( __DIR__ );
$safe_loader = $plugin_root . '/core/helper/class-bizcity-safe-loader.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
	require_once $safe_loader;
}
$registry_file = $plugin_root . '/core/diagnostics/includes/class-diagnostics-table-registry.php';
$policy_file = $plugin_root . '/core/helper/class-bizcity-legacy-table-policy.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! BizCity_Safe_Loader::require_file( $registry_file, 'legacy_table.inventory.registry' ) || ! BizCity_Safe_Loader::require_file( $policy_file, 'legacy_table.inventory.policy' ) ) {
	$fail( 'Legacy table registry or policy is unavailable.', 3 );
}
if ( ! class_exists( 'BizCity_Diagnostics_Table_Registry' ) || ! class_exists( 'BizCity_Legacy_Table_Policy' ) ) {
	$fail( 'Legacy table registry or policy class is unavailable.', 3 );
}

$blog_id = (int) get_current_blog_id();
if ( $blog_id !== (int) $options['blog'] ) {
	$fail( 'Mapped host resolved a different blog than --blog; refusing cross-tenant inventory.', 3 );
}
global $wpdb;
$rows = array();
foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $catalog ) {
	$name = (string) ( $catalog['name'] ?? '' );
	if ( $name === '' || ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
		continue;
	}
	$prefix_scope = (string) ( $catalog['prefix_scope'] ?? 'blog' );
	$physical = ! empty( $catalog['raw'] ) || 'base' === $prefix_scope ? $wpdb->base_prefix . $name : $wpdb->prefix . $name;
	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $physical ) ) {
		continue;
	}
	$exists = function_exists( 'bizcity_tbl_exists' ) ? bizcity_tbl_exists( $physical ) : false;
	$count = null;
	$count_error = '';
	if ( $exists ) {
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $physical . '`' );
		$count_error = (string) ( $wpdb->last_error ?? '' );
		if ( false === $count ) {
			$count = null;
		}
	}
	$state = method_exists( 'BizCity_Legacy_Table_Policy', 'get_record' ) ? BizCity_Legacy_Table_Policy::get_record( $name ) : array();
	$zero_rows = $exists && null !== $count && 0 === (int) $count;
	$drop_candidate = $zero_rows && ! empty( $state['approval_ref'] ) && 'ready_to_drop' === (string) ( $state['state'] ?? '' ) && 'blog' === $prefix_scope && empty( $catalog['quarantine_only'] );
	$blocker = ! $exists ? 'absent' : ( $count_error !== '' ? 'count_failed' : ( 'base' === $prefix_scope ? 'base_prefix_owner_required' : ( ! $zero_rows ? 'non_empty' : ( ! empty( $catalog['quarantine_only'] ) ? 'quarantine_owner_signoff_required' : ( 'ready_to_drop' !== (string) ( $state['state'] ?? '' ) ? 'approval_state_not_ready' : ( empty( $state['approval_ref'] ) ? 'approval_reference_missing' : '' ) ) ) ) ) );
	// [2026-09-09 10:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33B — expose explicit zero-row status for the full read-only inventory.
	$row_status = ! $exists ? 'absent' : ( $count_error !== '' ? 'count_failed' : ( $zero_rows ? 'zero_row' : 'non_empty' ) );
	$rows[] = array(
		'name' => $name,
		'physical_table' => $physical,
		'prefix_scope' => $prefix_scope,
		'kind' => ! empty( $catalog['raw'] ) ? 'raw' : ( ! empty( $catalog['quarantine_only'] ) ? 'quarantine' : 'cataloged' ),
		'owner' => (string) ( $catalog['module'] ?? $catalog['owner'] ?? '' ),
		'reason' => (string) ( $catalog['reason'] ?? '' ),
		'replacement_status' => (string) ( $catalog['replacement_status'] ?? '' ),
		'physical_exists' => $exists,
		'row_count' => null === $count ? null : (int) $count,
		'zero_row' => $zero_rows,
		'row_status' => $row_status,
		'lifecycle_state' => (string) ( $state['state'] ?? '' ),
		'approval_ref_present' => ! empty( $state['approval_ref'] ),
		'drop_candidate' => $drop_candidate,
		'blocker' => $blocker,
		'next_owner_action' => next_owner_action( $catalog, $blocker, $exists, $count ),
	);
}

$result = array(
	'contract' => 'legacy-table-inventory',
	'version' => '1',
	'host' => (string) $options['host'],
	'blog_id' => $blog_id,
	'physical_database' => (string) ( $wpdb->dbname ?? '' ),
	'mutation_performed' => false,
	'counts' => array(
		'catalog_rows' => count( $rows ),
		'physical_present' => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['physical_exists'] ); } ) ),
		'zero_row' => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['physical_exists'] ) && 0 === (int) $row['row_count']; } ) ),
		'drop_candidates' => count( array_filter( $rows, static function ( $row ) { return ! empty( $row['drop_candidate'] ); } ) ),
	),
	'classification' => array(
		'by_blocker' => array(),
		'by_lifecycle' => array(),
		'quarantine' => 0,
		'base_prefix' => 0,
		'approval_ready_zero_row' => 0,
	),
	'rows' => $rows,
);
foreach ( $rows as $row ) {
	$blocker = (string) ( $row['blocker'] ?? '' );
	$lifecycle = (string) ( $row['lifecycle_state'] ?? '' );
	$result['classification']['by_blocker'][ $blocker ] = (int) ( $result['classification']['by_blocker'][ $blocker ] ?? 0 ) + 1;
	$result['classification']['by_lifecycle'][ $lifecycle ] = (int) ( $result['classification']['by_lifecycle'][ $lifecycle ] ?? 0 ) + 1;
	if ( 'quarantine' === (string) ( $row['kind'] ?? '' ) ) {
		$result['classification']['quarantine']++;
	}
	if ( 'base' === (string) ( $row['prefix_scope'] ?? '' ) ) {
		$result['classification']['base_prefix']++;
	}
	if ( ! empty( $row['drop_candidate'] ) ) {
		$result['classification']['approval_ready_zero_row']++;
	}
}
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 0 );

function next_owner_action( array $catalog, $blocker, $exists, $count ) {
	if ( ! $exists ) {
		return 'none_absent';
	}
	if ( 'base_prefix_owner_required' === $blocker ) {
		return 'network_owner_review';
	}
	if ( 'non_empty' === $blocker ) {
		if ( ! empty( $catalog['quarantine_only'] ) ) {
			return 'owner_parity_then_zero_growth';
		}
		if ( (string) ( $catalog['replacement_status'] ?? '' ) === 'active' ) {
			return 'replacement_parity_then_zero_row';
		}
		return 'owner_review_non_empty';
	}
	if ( 'approval_state_not_ready' === $blocker ) {
		return 'approval_ref_and_ready_to_drop';
	}
	if ( 'count_failed' === $blocker ) {
		return 'repair_inventory_query';
	}
	return 0 === (int) $count ? 'owner_review_zero_row' : 'owner_review';
}
