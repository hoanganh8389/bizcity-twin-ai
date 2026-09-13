<?php
/**
 * Context Bank mapped/two-shard capture canary.
 *
 * This command closes the CB3/G1 precondition for one explicit target blog
 * and partition. It never guesses a blog, account or peer and never enables
 * downstream rollups, KG, MPR or UI flags.
 *
 * @package BizCity_Twin_AI\Bin
 * @since 2026-09-08 (PHASE-1.33B)
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "context-bank-capture-canary.php must be run from CLI.\n" );
	exit( 2 );
}

$options = array(
	'wp-root' => '',
	'host' => '',
	'blog-a' => 0,
	'blog-b' => 0,
	'target-blog' => 0,
	'user' => 0,
	'channel' => '',
	'account-id' => '',
	'peer-uid' => '',
	'limit' => 1000,
	'confirm' => '',
	'enable' => false,
);
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( strpos( $argument, '--' ) !== 0 ) {
		continue;
	}
	$key_value = substr( $argument, 2 );
	if ( strpos( $key_value, '=' ) !== false ) {
		list( $key, $value ) = explode( '=', $key_value, 2 );
		if ( array_key_exists( $key, $options ) ) {
			$options[ $key ] = $value;
		}
	} elseif ( array_key_exists( $key_value, $options ) ) {
		$options[ $key_value ] = true;
	}
}

$fail = static function ( $message, $code = 2 ) {
	// [2026-09-08 12:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33B — fail closed before any canary flag mutation.
	fwrite( STDERR, (string) $message . "\n" );
	exit( (int) $code );
};

if ( (string) $options['confirm'] !== 'CANARY' ) {
	$fail( 'Refusing capture canary: pass --confirm=CANARY.' );
}
if ( (string) $options['host'] === '' || preg_match( '/[^A-Za-z0-9.:-]/', (string) $options['host'] ) ) {
	$fail( 'Refusing capture canary: pass an explicit mapped --host.' );
}
foreach ( array( 'blog-a', 'blog-b', 'target-blog', 'user' ) as $numeric_field ) {
	if ( (int) $options[ $numeric_field ] <= 0 ) {
		$fail( 'Refusing capture canary: pass a positive --' . $numeric_field . '.' );
	}
}
if ( (int) $options['blog-a'] === (int) $options['blog-b'] ) {
	$fail( 'Refusing capture canary: --blog-a and --blog-b must be distinct.' );
}
if ( ! in_array( (string) $options['channel'], array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'email', 'instagram', 'whatsapp' ), true ) ) {
	$fail( 'Refusing capture canary: unsupported or missing --channel.' );
}
foreach ( array( 'account-id', 'peer-uid' ) as $identity_field ) {
	if ( trim( (string) $options[ $identity_field ] ) === '' || preg_match( '/^<[^>]+>$/', trim( (string) $options[ $identity_field ] ) ) ) {
		$fail( 'Refusing capture canary: replace --' . $identity_field . ' with a real server-resolved value.' );
	}
}

$wp_root = (string) $options['wp-root'];
if ( $wp_root === '' ) {
	$wp_root = (string) ( getenv( 'BIZCITY_WP_ROOT' ) ?: '' );
}
if ( $wp_root === '' || ! is_file( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) || ! is_readable( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) ) {
	$fail( 'Cannot locate readable wp-load.php. Use --wp-root=/path/to/wordpress.' );
}
if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
	$fail( 'Refusing capture canary inside Diagnostics CLI context.', 4 );
}

$_SERVER['HTTP_HOST'] = (string) $options['host'];
$_SERVER['SERVER_NAME'] = (string) $options['host'];
define( 'WP_USE_THEMES', false );
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';

if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( (int) $options['user'] );
}
if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
	$fail( 'Refusing capture canary: --user is not a tenant administrator.', 3 );
}

$plugin_root = dirname( __DIR__ );
$safe_loader = $plugin_root . '/core/helper/class-bizcity-safe-loader.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
	require_once $safe_loader;
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$fail( 'Safe Loader is unavailable on this deployment.', 3 );
}
$load = static function ( $path, $label ) {
	return is_file( $path ) && is_readable( $path ) && BizCity_Safe_Loader::require_file( $path, $label );
};
$load( $plugin_root . '/core/context-bank/bootstrap.php', 'context_bank.capture_canary' );
$load( $plugin_root . '/core/channel-gateway/bootstrap.php', 'channel_gateway.capture_canary' );
if ( ! class_exists( 'BizCity_Context_Bank_Ledger' ) || ! class_exists( 'BizCity_Channel_Conversation_Archive' ) || ! class_exists( 'BizCity_Channel_User_Grant' ) ) {
	$fail( 'Context Bank, archive or channel grant owner is unavailable.', 3 );
}

$original_blog = (int) get_current_blog_id();
$flag_keys = array(
	'bizcity_context_bank_capture_enabled',
	'bizcity_context_bank_channel_capture_enabled',
	'bizcity_context_bank_rollups_enabled',
	'bizcity_context_bank_kg_bridge_enabled',
	'bizcity_context_bank_mpr_enabled',
	'bizcity_context_bank_ledger_enabled',
	'bizcity_context_bank_ui_enabled',
);
$snapshot = array();
$scope_ref = substr( hash( 'sha256', (string) $options['channel'] . '|' . (string) $options['account-id'] . '|' . (string) $options['peer-uid'] ), 0, 12 );
$result = array(
	'contract' => 'context-bank-capture-canary',
	'version' => '1',
	'run_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cb-canary-', true ),
	'host' => (string) $options['host'],
	'blogs' => array(),
	'target_blog' => (int) $options['target-blog'],
	'channel' => sanitize_key( (string) $options['channel'] ),
	'partition_scope_ref' => $scope_ref,
	'mode' => ! empty( $options['enable'] ) ? 'enable_with_rollback' : 'preflight_only',
	'status' => 'blocked',
	'reason' => '',
	'capture_flag' => 'bizcity_context_bank_channel_capture_enabled',
	'flags_before' => array(),
	'flags_after' => array(),
	'mutation_performed' => false,
	'rollback_performed' => false,
);
$restore = static function () use ( &$snapshot ) {
	foreach ( $snapshot as $flag_key => $value ) {
		if ( false === $value ) {
			delete_option( $flag_key );
		} else {
			update_option( $flag_key, $value, false );
		}
	}
};

try {
	$contexts = array();
	foreach ( array( 'a' => (int) $options['blog-a'], 'b' => (int) $options['blog-b'] ) as $label => $blog_id ) {
		$switched = $blog_id !== (int) get_current_blog_id() ? switch_to_blog( $blog_id ) : false;
		if ( ! $switched && $blog_id !== (int) get_current_blog_id() ) {
			throw new RuntimeException( 'target_blog_switch_failed_' . $label );
		}
		$route = BizCity_Context_Bank_Ledger::route_evidence();
		global $wpdb;
		$physical_identity = (string) ( $route['physical_db'] ?? $route['database'] ?? $route['dbname'] ?? ( $wpdb->dbname ?? '' ) );
		if ( $physical_identity === '' || empty( $route['ok'] ) ) {
			throw new RuntimeException( 'physical_route_unverified_' . $label );
		}
		$table = BizCity_Context_Bank_Ledger::table();
		$table_ok = function_exists( 'bizcity_tbl_exists' ) ? bizcity_tbl_exists( $table ) : ( class_exists( 'BizCity_Table_Metadata' ) && BizCity_Table_Metadata::table_exists( $table ) );
		if ( ! $table_ok ) {
			throw new RuntimeException( 'ledger_not_provisioned_' . $label );
		}
		$contexts[ $label ] = array( 'blog_id' => $blog_id, 'route_ok' => true, 'ledger_table' => true, 'physical_fingerprint' => hash( 'sha256', $physical_identity ) );
		$result['blogs'][ $label ] = $contexts[ $label ];
		if ( $switched ) {
			restore_current_blog();
		}
	}
	if ( $contexts['a']['physical_fingerprint'] === $contexts['b']['physical_fingerprint'] ) {
		throw new RuntimeException( 'two_distinct_physical_shards_required' );
	}
	$target_blog = (int) $options['target-blog'];
	$switched = $target_blog !== (int) get_current_blog_id() ? switch_to_blog( $target_blog ) : false;
	if ( ! $switched && $target_blog !== (int) get_current_blog_id() ) {
		throw new RuntimeException( 'target_blog_switch_failed' );
	}
	$snapshot = array();
	foreach ( $flag_keys as $flag_key ) {
		$snapshot[ $flag_key ] = get_option( $flag_key, false );
	}
	$result['flags_before'] = array_map( 'boolval', $snapshot );
	$result['flags_after'] = array_map( 'boolval', $snapshot );
	$plan = BizCity_Channel_Conversation_Archive::plan_grant_key_rewrite(
		(string) $options['channel'],
		(string) $options['account-id'],
		(string) $options['peer-uid'],
		array( 'BizCity_Channel_Conversation_Archive', 'rest_authorize_tenant_admin' ),
		max( 1, min( 1000, (int) $options['limit'] ) )
	);
	$result['inventory'] = array(
		'status' => (string) ( $plan['status'] ?? 'blocked' ),
		'reason' => (string) ( $plan['reason'] ?? 'planner_unavailable' ),
		'rows_scanned' => (int) ( $plan['rows_scanned'] ?? 0 ),
		'grant_key_missing' => (int) ( $plan['grant_key_missing'] ?? 0 ),
		'grant_key_mismatch' => (int) ( $plan['grant_key_mismatch'] ?? 0 ),
		'malformed_rows' => (int) ( $plan['malformed_rows'] ?? 0 ),
		'duplicate_events' => (int) ( $plan['duplicate_events'] ?? 0 ),
		'legal_hold_files' => (int) ( $plan['legal_hold_files'] ?? 0 ),
		'coverage_complete' => ! empty( $plan['coverage_complete'] ),
	);
	$inventory_ok = ! empty( $plan['ok'] ) && 'ready' === (string) ( $plan['status'] ?? '' ) && ! empty( $plan['coverage_complete'] ) && 0 === (int) ( $plan['grant_key_missing'] ?? -1 ) && 0 === (int) ( $plan['grant_key_mismatch'] ?? -1 ) && 0 === (int) ( $plan['malformed_rows'] ?? -1 ) && 0 === (int) ( $plan['duplicate_events'] ?? -1 ) && 0 === (int) ( $plan['legal_hold_files'] ?? -1 );
	if ( ! $inventory_ok ) {
		throw new RuntimeException( 'partition_inventory_not_ready' );
	}
	if ( ! empty( $options['enable'] ) ) {
		// [2026-09-08 12:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33B — never enable a production canary for an empty or guessed archive scope.
		if ( (int) ( $plan['rows_scanned'] ?? 0 ) <= 0 ) {
			throw new RuntimeException( 'real_partition_required' );
		}
		update_option( 'bizcity_context_bank_channel_capture_enabled', true, false );
		$result['mutation_performed'] = true;
		$result['flags_after']['bizcity_context_bank_channel_capture_enabled'] = true;
		if ( ! get_option( 'bizcity_context_bank_channel_capture_enabled', false ) ) {
			throw new RuntimeException( 'capture_flag_readback_failed' );
		}
	}
	$result['status'] = 'pass';
	$result['reason'] = ! empty( $options['enable'] ) ? 'capture_canary_enabled' : 'preflight_pass';
	if ( $switched ) {
		restore_current_blog();
	}
} catch ( Throwable $error ) {
	$restore();
	$result['flags_after'] = array_map( 'boolval', $snapshot );
	$result['mutation_performed'] = false;
	$result['rollback_performed'] = ! empty( $options['enable'] );
	$result['reason'] = sanitize_key( (string) $error->getMessage() );
	$result['status'] = 'rolled_back';
	while ( get_current_blog_id() !== $original_blog && ! empty( $GLOBALS['_wp_switched_stack'] ) ) {
		restore_current_blog();
	}
}

if ( get_current_blog_id() !== $original_blog ) {
	while ( get_current_blog_id() !== $original_blog && ! empty( $GLOBALS['_wp_switched_stack'] ) ) {
		restore_current_blog();
	}
}
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( 'pass' === $result['status'] ? 0 : 1 );