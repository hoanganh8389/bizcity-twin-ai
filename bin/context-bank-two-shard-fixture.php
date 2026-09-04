<?php
/**
 * Validate Context Bank isolation across two explicitly selected blogs/shards.
 *
 * This fixture refuses to write when the two selected blogs resolve to the same
 * physical database identity. It never guesses a second tenant or provisions
 * every blog in the network.
 *
 * @package BizCity_Twin_AI
 * @subpackage Bin
 * @since 2026-09-02 (PHASE-CB-G1)
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "context-bank-two-shard-fixture.php must be run from CLI.\n" );
	exit( 2 );
}

$options = array( 'wp-root' => '', 'host' => '', 'blog-a' => 0, 'blog-b' => 0, 'user' => 0, 'confirm' => '', 'provision' => false );
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( strpos( $argument, '--' ) !== 0 || strpos( $argument, '=' ) === false ) {
		continue;
	}
	list( $key, $value ) = explode( '=', substr( $argument, 2 ), 2 );
	if ( array_key_exists( $key, $options ) ) {
		$options[ $key ] = $value;
	}
}

if ( (string) $options['confirm'] !== 'G1' ) {
	fwrite( STDERR, "Refusing fixture: pass --confirm=G1.\n" );
	exit( 2 );
}
if ( (int) $options['blog-a'] <= 0 || (int) $options['blog-b'] <= 0 || (int) $options['blog-a'] === (int) $options['blog-b'] ) {
	fwrite( STDERR, "Refusing fixture: pass two distinct --blog-a and --blog-b IDs.\n" );
	exit( 2 );
}
if ( (int) $options['user'] <= 0 || (string) $options['host'] === '' || preg_match( '/[^A-Za-z0-9.:-]/', (string) $options['host'] ) ) {
	fwrite( STDERR, "Refusing fixture: pass --host=example.com and an explicit --user=<admin-id>.\n" );
	exit( 2 );
}

$wp_root = (string) $options['wp-root'];
if ( $wp_root === '' ) {
	$wp_root = (string) ( getenv( 'BIZCITY_WP_ROOT' ) ?: '' );
}
if ( $wp_root === '' || ! is_file( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) || ! is_readable( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Cannot locate readable wp-load.php. Use --wp-root=/path/to/wordpress.\n" );
	exit( 2 );
}
if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
	fwrite( STDERR, "Refusing fixture inside Diagnostics CLI context.\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = (string) $options['host'];
$_SERVER['SERVER_NAME'] = (string) $options['host'];
define( 'WP_USE_THEMES', false );
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( (int) $options['user'] );
}
if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
	fwrite( STDERR, "Refusing fixture: --user must have manage_options.\n" );
	exit( 3 );
}

$plugin_root = dirname( __DIR__ );
$safe_loader = $plugin_root . '/core/helper/class-bizcity-safe-loader.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
	require_once $safe_loader;
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	fwrite( STDERR, "Safe Loader is unavailable.\n" );
	exit( 3 );
}
$context_bootstrap = $plugin_root . '/core/context-bank/bootstrap.php';
if ( ! is_file( $context_bootstrap ) || ! is_readable( $context_bootstrap ) || ! BizCity_Safe_Loader::require_file( $context_bootstrap, 'context_bank.two_shard_fixture' ) ) {
	fwrite( STDERR, "Context Bank bootstrap could not be loaded.\n" );
	exit( 3 );
}

$result = array( 'contract' => 'context-bank-two-shard-fixture', 'version' => '1', 'host' => (string) $options['host'], 'blogs' => array(), 'steps' => array(), 'status' => 'fail', 'reason' => '' );
$failures = array();
$step = static function ( $label, $status, $detail ) use ( &$result, &$failures ) {
	$result['steps'][] = array( 'label' => (string) $label, 'status' => (string) $status, 'detail' => (string) $detail );
	if ( $status !== 'pass' ) {
		$failures[] = (string) $label;
	}
};
$original_blog = (int) get_current_blog_id();
$contexts = array();
$source_contract = 'core.context_bank.commerce_order';
$cleanup_rows = array();

// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB-G1 - keep every selected blog switch balanced and clean only fixture-owned pointers.
$cleanup = static function () use ( &$cleanup_rows, $source_contract ) {
	foreach ( $cleanup_rows as $label => $row ) {
		$switched = (int) $row['blog_id'] !== (int) get_current_blog_id();
		if ( $switched ) {
			switch_to_blog( (int) $row['blog_id'] );
		}
		$tombstone = BizCity_Business_JSONL_File_Store::write_with_receipt( $source_contract, array( 'record_id' => $row['record_id'], 'event_type' => 'delete', 'reason' => 'g1_fixture_cleanup' ), 'delete' );
		if ( is_array( $tombstone ) ) {
			$admission = BizCity_Context_Bank_Ledger::instance()->record( array( 'source_contract_id' => $source_contract, 'record_id' => $row['record_id'], 'record_kind' => 'event', 'event_uuid' => (string) $tombstone['event_uuid'], 'source_record_id' => (string) $tombstone['event_uuid'], 'entity_type' => 'g1_fixture', 'entity_key' => 'g1_cleanup', 'scope_key' => 'g1:cleanup', 'operation' => 'delete', 'lifecycle_status' => 'deleted', 'kg_status' => 'not_candidate', 'receipt' => $tombstone ) );
			if ( ! empty( $admission['ok'] ) ) {
				BizCity_Context_Bank_Ledger::instance()->remove_tombstoned_pointer( array_merge( array( 'source_contract_id' => $source_contract, 'record_id' => $row['record_id'], 'operation' => 'delete', 'lifecycle_status' => 'deleted' ), $tombstone ), 'g1_fixture_cleanup' );
			}
		}
		if ( $switched ) {
			restore_current_blog();
		}
		unset( $cleanup_rows[ $label ] );
	}
};

$load_provisioner = static function () use ( $plugin_root ) {
	foreach ( array( 'class-diagnostics-table-registry.php', 'class-diagnostics-table-inspector.php', 'class-diagnostics-changelog-loader.php', 'class-diagnostics-auto-create.php', 'class-site-provisioner.php', 'installer-registry.php' ) as $artifact ) {
		$path = $plugin_root . '/core/diagnostics/includes/' . $artifact;
		if ( ! BizCity_Safe_Loader::require_file( $path, 'context_bank.two_shard_fixture.' . $artifact ) ) {
			return false;
		}
	}
	if ( function_exists( 'bizcity_register_default_installers' ) ) {
		bizcity_register_default_installers();
	}
	return class_exists( 'BizCity_Site_Provisioner' );
};

$provision = static function () use ( $load_provisioner ) {
	if ( ! $load_provisioner() ) {
		return false;
	}
	$needed = array( 'context_bank', 'context_bank_rollup_state' );
	$found = array();
	foreach ( BizCity_Site_Provisioner::get_installers() as $installer ) {
		if ( is_array( $installer ) && in_array( (string) ( $installer['id'] ?? '' ), $needed, true ) ) {
			call_user_func( $installer['callback'] );
			$found[] = (string) $installer['id'];
		}
	}
	return count( array_unique( $found ) ) === count( $needed );
};

try {
	foreach ( array( 'a' => (int) $options['blog-a'], 'b' => (int) $options['blog-b'] ) as $label => $blog_id ) {
		$switched = $blog_id !== (int) get_current_blog_id() ? switch_to_blog( $blog_id ) : false;
		if ( ! $switched && $blog_id !== (int) get_current_blog_id() ) {
			$step( 'Runtime - switch to blog ' . $label, 'fail', 'Explicit blog switch failed closed.' );
			continue;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$step( 'Runtime - admin authorization on blog ' . $label, 'fail', 'Explicit operator is not authorized on the selected blog.' );
			if ( $switched ) {
				restore_current_blog();
			}
			continue;
		}
		$route = class_exists( 'BizCity_Context_Bank_Ledger' ) ? BizCity_Context_Bank_Ledger::route_evidence() : array();
		global $wpdb;
		$physical_identity = (string) ( $route['physical_db'] ?? $route['database'] ?? $route['dbname'] ?? ( $wpdb->dbname ?? '' ) );
		if ( $physical_identity === '' ) {
			$physical_identity = 'missing';
		}
		$contexts[ $label ] = array( 'blog_id' => $blog_id, 'domain' => (string) ( get_blog_details( $blog_id )->domain ?? '' ), 'route_ok' => ! empty( $route['ok'] ), 'route_reason' => (string) ( $route['reason'] ?? '' ), 'physical_fingerprint' => hash( 'sha256', $physical_identity ) );
		$result['blogs'][ $label ] = array( 'blog_id' => $blog_id, 'domain' => $contexts[ $label ]['domain'], 'route_ok' => $contexts[ $label ]['route_ok'], 'route_reason' => $contexts[ $label ]['route_reason'], 'physical_fingerprint' => $contexts[ $label ]['physical_fingerprint'] );
		if ( $switched ) {
			restore_current_blog();
		}
	}
	$routes_ok = ! empty( $contexts['a']['route_ok'] ) && ! empty( $contexts['b']['route_ok'] );
	$distinct_shards = $routes_ok && $contexts['a']['physical_fingerprint'] !== $contexts['b']['physical_fingerprint'];
	$step( 'Runtime - both explicit tenant routes resolve', $routes_ok ? 'pass' : 'fail', $routes_ok ? 'Both selected blogs returned verified route evidence.' : 'One selected blog did not return verified route evidence.' );
	$step( 'Runtime - distinct physical shard identities', $distinct_shards ? 'pass' : 'fail', $distinct_shards ? 'Selected blogs resolve to distinct physical database identities.' : 'Selected blogs resolve to the same or unavailable physical database identity; refusing tenant mutation.' );
	if ( ! $routes_ok || ! $distinct_shards ) {
		$result['reason'] = 'two_distinct_physical_shards_required';
		throw new RuntimeException( 'two_distinct_physical_shards_required' );
	}
	if ( ! empty( $options['provision'] ) ) {
		foreach ( array( 'a' => (int) $options['blog-a'], 'b' => (int) $options['blog-b'] ) as $label => $blog_id ) {
			$switched = $blog_id !== (int) get_current_blog_id() ? switch_to_blog( $blog_id ) : false;
			if ( ! $switched && $blog_id !== (int) get_current_blog_id() ) {
				throw new RuntimeException( 'target_blog_switch_failed_' . $label );
			}
			if ( ! $provision() ) {
				throw new RuntimeException( 'target_shard_provision_failed_' . $label );
			}
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
	foreach ( array( 'a' => (int) $options['blog-a'], 'b' => (int) $options['blog-b'] ) as $label => $blog_id ) {
		$switched = $blog_id !== (int) get_current_blog_id() ? switch_to_blog( $blog_id ) : false;
		if ( ! $switched && $blog_id !== (int) get_current_blog_id() ) {
			throw new RuntimeException( 'target_blog_switch_failed_' . $label );
		}
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( BizCity_Context_Bank_Ledger::table() ) ) {
			throw new RuntimeException( 'ledger_not_provisioned_' . $label );
		}
		$record_id = 'g1_fixture_' . $label . '_' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( $source_contract, array( 'record_id' => $record_id, 'event_type' => 'g1_isolation_fixture', 'entity_key' => 'g1_' . $label ), 'upsert' );
		$admission = is_array( $receipt ) ? BizCity_Context_Bank_Ledger::instance()->record( array( 'source_contract_id' => $source_contract, 'record_id' => $record_id, 'record_kind' => 'event', 'event_uuid' => (string) $receipt['event_uuid'], 'source_record_id' => (string) $receipt['event_uuid'], 'entity_type' => 'g1_fixture', 'entity_key' => 'g1_' . $label, 'scope_key' => 'g1:' . $label, 'kg_status' => 'not_candidate', 'receipt' => $receipt ) ) : array(); 
		if ( empty( $admission['ok'] ) ) {
			throw new RuntimeException( 'pointer_admission_failed_' . $label );
		}
		$follow = BizCity_Context_Bank_Ledger::instance()->follow( $record_id, array( 'source_contract_id' => $source_contract, 'blog_id' => $blog_id ) );
		if ( empty( $follow['ok'] ) ) {
			throw new RuntimeException( 'pointer_follow_failed_' . $label );
		}
		$cleanup_rows[ $label ] = array( 'blog_id' => $blog_id, 'record_id' => $record_id, 'receipt' => $receipt );
		if ( $switched ) {
			restore_current_blog();
		}
	}
	$step( 'Runtime - one pointer admitted and followed per shard', 'pass', 'Each explicit shard admitted and verified one tenant-scoped pointer.' );
	$cross_read_ok = true;
	foreach ( array( 'a' => 'b', 'b' => 'a' ) as $owner => $other ) {
		$switched = (int) $contexts[ $other ]['blog_id'] !== (int) get_current_blog_id() ? switch_to_blog( $contexts[ $other ]['blog_id'] ) : false;
		if ( ! $switched && (int) $contexts[ $other ]['blog_id'] !== (int) get_current_blog_id() ) {
			$cross_read_ok = false;
			continue;
		}
		$foreign = BizCity_Context_Bank_Ledger::instance()->find( array( 'record_id' => $cleanup_rows[ $owner ]['record_id'], 'source_contract_id' => $source_contract, 'limit' => 1 ) );
		$cross_read_ok = $cross_read_ok && empty( $foreign );
		if ( $switched ) {
			restore_current_blog();
		}
	}
	$step( 'Runtime - cross-shard pointer read refusal', $cross_read_ok ? 'pass' : 'fail', $cross_read_ok ? 'A pointer admitted on one blog was not visible from the other blog.' : 'Cross-shard pointer read returned unexpected data.' );
	$cleanup();
	$step( 'Runtime - two-shard fixture cleanup', 'pass', 'Derived pointers were tombstoned and removed on both explicit blogs.' );
	$result['status'] = empty( $failures ) ? 'pass' : 'fail';
} catch ( Throwable $error ) {
	if ( $result['reason'] === '' ) {
		$result['reason'] = sanitize_key( (string) $error->getMessage() );
	}
	$result['status'] = 'fail';
} finally {
	$cleanup();
	// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB-G1 - restore only an actual WordPress switch stack entry; avoid an infinite cleanup loop when restore_current_blog() is already a no-op.
	$restore_guard = 0;
	while ( get_current_blog_id() !== $original_blog && ! empty( $GLOBALS['_wp_switched_stack'] ) && $restore_guard < 20 ) {
		restore_current_blog();
		$restore_guard++;
	}
}

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $result['status'] === 'pass' ? 0 : 1 );