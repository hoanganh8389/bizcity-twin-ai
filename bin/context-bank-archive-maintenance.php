<?php
/**
 * Context Bank archive maintenance planner.
 *
 * This entrypoint is intentionally plan-only. Production archive rewrites
 * require a durable migration journal and receipt/ledger remap owner.
 *
 * @package BizCity_Twin_AI\Bin
 * @since 2026-09-07 (PHASE-1.33A)
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "context-bank-archive-maintenance.php must be run from CLI.\n" );
	exit( 2 );
}

$options = array(
	'wp-root' => '',
	'host' => '',
	'user' => 0,
	'channel' => '',
	'account-id' => '',
	'peer-uid' => '',
	'limit' => 1000,
	'confirm' => '',
	'format' => 'json',
	'apply' => false,
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
	// [2026-09-07 12:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — fail closed for unsafe archive maintenance command arguments.
	fwrite( STDERR, (string) $message . "\n" );
	exit( (int) $code );
};

if ( (string) $options['confirm'] !== 'PLAN' ) {
	$fail( 'Refusing archive maintenance: pass --confirm=PLAN for a read-only plan.' );
}
if ( ! empty( $options['apply'] ) ) {
	$fail( 'Refusing archive maintenance: --apply is not implemented; no production rewrite is available.', 4 );
}
if ( (string) $options['host'] === '' || preg_match( '/[^A-Za-z0-9.:-]/', (string) $options['host'] ) ) {
	$fail( 'Refusing archive maintenance: pass an explicit mapped --host.' );
}
	$placeholder_fields = array( 'user', 'account-id', 'peer-uid' );
	foreach ( $placeholder_fields as $placeholder_field ) {
		if ( preg_match( '/^<[^>]+>$/', trim( (string) $options[ $placeholder_field ] ) ) ) {
			// [2026-09-07 12:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — explain shell placeholder misuse before numeric validation or WordPress bootstrap.
			$fail( 'Refusing archive maintenance: replace the ' . $placeholder_field . ' placeholder with a real value and remove angle brackets.' );
		}
	}
if ( (int) $options['user'] <= 0 ) {
	$fail( 'Refusing archive maintenance: pass an explicit tenant-admin --user ID.' );
}
if ( ! in_array( (string) $options['channel'], array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'email', 'instagram', 'whatsapp' ), true ) ) {
	$fail( 'Refusing archive maintenance: unsupported or missing --channel.' );
}
if ( (string) $options['account-id'] === '' || (string) $options['peer-uid'] === '' ) {
	$fail( 'Refusing archive maintenance: explicit account and peer identity are required.' );
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
	$fail( 'Refusing archive maintenance: --user is not a tenant administrator.', 3 );
}
if ( ! class_exists( 'BizCity_Channel_User_Grant', false ) ) {
	// [2026-09-07 12:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — load the canonical grant owner explicitly for CLI requests whose normal gateway bootstrap is lazy or stale.
	$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__ );
	$safe_loader = rtrim( $plugin_root, '/\\' ) . '/core/helper/class-bizcity-safe-loader.php';
	$grant_file = rtrim( $plugin_root, '/\\' ) . '/core/channel-gateway/includes/class-channel-user-grant.php';
	if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
		require_once $safe_loader;
	}
	if ( class_exists( 'BizCity_Safe_Loader', false ) && is_file( $grant_file ) && is_readable( $grant_file ) ) {
		BizCity_Safe_Loader::require_file( $grant_file, 'channel_gateway.user_grant.cli' );
	} elseif ( is_file( $grant_file ) && is_readable( $grant_file ) ) {
		require_once $grant_file;
	}
}
if ( ! class_exists( 'BizCity_Channel_User_Grant', false ) ) {
	$fail( 'Channel grant owner is unavailable on this deployment.', 3 );
}
if ( ! class_exists( 'BizCity_Channel_Conversation_Archive' ) || ! method_exists( 'BizCity_Channel_Conversation_Archive', 'plan_grant_key_rewrite' ) ) {
	$fail( 'Archive planner owner is unavailable on this deployment.', 3 );
}

$limit = (int) $options['limit'];
if ( $limit <= 0 ) {
	$fail( 'Refusing archive maintenance: --limit must be positive.' );
}
$scope_ref = substr( hash( 'sha256', (string) $options['channel'] . '|' . (string) $options['account-id'] . '|' . (string) $options['peer-uid'] ), 0, 12 );
$result = BizCity_Channel_Conversation_Archive::plan_grant_key_rewrite(
	(string) $options['channel'],
	(string) $options['account-id'],
	(string) $options['peer-uid'],
	array( 'BizCity_Channel_Conversation_Archive', 'rest_authorize_tenant_admin' ),
	$limit
);
$payload = array(
	'contract' => 'context-bank-archive-maintenance-plan',
	'version' => '1',
	'run_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cb-plan-', true ),
	'host' => (string) $options['host'],
	'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
	'channel' => sanitize_key( (string) ( $result['channel'] ?? $options['channel'] ) ),
	'account_scope_ref' => $scope_ref,
	'limit' => $limit,
	'mode' => 'plan_only',
	'status' => (string) ( $result['status'] ?? 'blocked' ),
	'reason' => (string) ( $result['reason'] ?? 'planner_unavailable' ),
	'rows_scanned' => (int) ( $result['rows_scanned'] ?? 0 ),
	'rows_rewritten' => (int) ( $result['rows_rewritten'] ?? 0 ),
	'grant_key_missing' => (int) ( $result['grant_key_missing'] ?? 0 ),
	'grant_key_mismatch' => (int) ( $result['grant_key_mismatch'] ?? 0 ),
	'malformed_rows' => (int) ( $result['malformed_rows'] ?? 0 ),
	'duplicate_events' => (int) ( $result['duplicate_events'] ?? 0 ),
	'legal_hold_files' => (int) ( $result['legal_hold_files'] ?? 0 ),
	'files_scanned' => (int) ( $result['files_scanned'] ?? 0 ),
	'source_bytes' => (int) ( $result['source_bytes'] ?? 0 ),
	'staged_bytes' => (int) ( $result['staged_bytes'] ?? 0 ),
	'coverage_complete' => ! empty( $result['coverage_complete'] ),
	'rollback_ready' => ! empty( $result['rollback_ready'] ),
	'mutation_performed' => false,
);

if ( strtolower( (string) $options['format'] ) === 'json' ) {
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
} else {
	echo sprintf( "status=%s reason=%s rows=%d missing=%d coverage_complete=%s mutation_performed=false\n", $payload['status'], $payload['reason'], $payload['rows_scanned'], $payload['grant_key_missing'], $payload['coverage_complete'] ? 'true' : 'false' );
}

exit( 'ready' === $payload['status'] ? 0 : 1 );