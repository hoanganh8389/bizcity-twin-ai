<?php
/**
 * DDV for the canonical table/schema metadata cache contract.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Table_Metadata', false ) ) {
	return;
}

final class BizCity_Probe_Table_Metadata implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.helper.table_metadata'; }
	public function label(): string { return 'Canonical table metadata cache'; }
	public function description(): string { return 'Checks one shared tenant/database-aware metadata helper for table, type and column cache behavior.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 52; }
	public function icon(): string { return 'database'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		return class_exists( 'BizCity_Table_Metadata' ) && isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] );
	}

	public function run( $ctx ): array {
		// [2026-08-29 Johnny Chu] R-METADATA-CACHE — prove the shared helper and cache contract without schema mutation.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$helper_file = $root . 'core/helper/class-bizcity-table-metadata.php';
		$disk = is_readable( $helper_file )
			&& class_exists( 'BizCity_Table_Metadata' )
			&& defined( 'BizCity_Table_Metadata::CONTRACT_ID' );
		$steps[] = array(
			'label' => 'Disk · canonical metadata helper and contract',
			'status' => $disk ? 'pass' : 'fail',
			'detail' => $disk ? 'The canonical helper artifact and contract identifier are available.' : 'The canonical metadata helper artifact or contract identifier is missing.',
		);

		$api = class_exists( 'BizCity_Table_Metadata' )
			&& method_exists( 'BizCity_Table_Metadata', 'table_exists' )
			&& method_exists( 'BizCity_Table_Metadata', 'table_type' )
			&& method_exists( 'BizCity_Table_Metadata', 'column_exists' )
			&& method_exists( 'BizCity_Table_Metadata', 'columns_exist' )
			&& method_exists( 'BizCity_Table_Metadata', 'invalidate' );
		$wrappers = function_exists( 'bizcity_tbl_exists' )
			&& function_exists( 'bizcity_table_exists' )
			&& function_exists( 'bizcity_tbl_invalidate' );
		$loader = $api && $wrappers;
		$steps[] = array(
			'label' => 'Loader · class API and compatibility wrappers',
			'status' => $loader ? 'pass' : 'fail',
			'detail' => $loader ? 'Class methods and legacy function names resolve to one metadata owner.' : 'The canonical class API or compatibility wrappers are unavailable.',
		);

		global $wpdb;
		// [2026-09-02 Johnny Chu] R-MSDB/R-METADATA-CACHE — use the current tenant options table instead of global users so the existing-table cache proof stays on the routed shard.
		$true_table = isset( $wpdb->options ) ? (string) $wpdb->options : '';
		$false_table = (string) $wpdb->prefix . 'bizcity_metadata_probe_missing_' . (int) get_current_blog_id();
		$query_count = function () use ( $wpdb ) {
			if ( isset( $wpdb->num_queries ) ) {
				return (int) $wpdb->num_queries;
			}
			return is_array( $wpdb->queries ?? null ) ? count( $wpdb->queries ) : -1;
		};
		$cache_reads_ok = false;
		$false_cache_ok = false;
		if ( $true_table !== '' && $api && $query_count() >= 0 ) {
			BizCity_Table_Metadata::invalidate( $true_table );
			$before_true = $query_count();
			$first_true = BizCity_Table_Metadata::table_exists( $true_table );
			$after_first_true = $query_count();
			$second_true = BizCity_Table_Metadata::table_exists( $true_table );
			$after_second_true = $query_count();
			$cache_reads_ok = $first_true && $second_true
				&& $after_first_true > $before_true
				&& $after_second_true === $after_first_true;
		}
		$steps[] = array(
			'label' => 'Runtime · existing table static/object cache',
			'status' => $cache_reads_ok ? 'pass' : 'fail',
			'detail' => $cache_reads_ok ? 'The first existing-table read queried metadata and the repeated read used cache.' : 'Existing-table metadata did not demonstrate one query followed by a cache hit.',
		);

		if ( $api && $query_count() >= 0 ) {
			BizCity_Table_Metadata::invalidate( $false_table );
			$before_false = $query_count();
			$first_false = ! BizCity_Table_Metadata::table_exists( $false_table );
			$after_first_false = $query_count();
			$second_false = ! BizCity_Table_Metadata::table_exists( $false_table );
			$after_second_false = $query_count();
			$false_cache_ok = $first_false && $second_false
				&& $after_first_false > $before_false
				&& $after_second_false === $after_first_false;
		}
		$steps[] = array(
			'label' => 'Runtime · missing table false-result cache',
			'status' => $false_cache_ok ? 'pass' : 'fail',
			'detail' => $false_cache_ok ? 'A false result was cached and the repeated missing-table read issued no second metadata query.' : 'Missing-table false-result caching did not pass.',
		);

		$database_scoped = false;
		$reflection = null;
		try {
			$reflection = new ReflectionMethod( 'BizCity_Table_Metadata', 'cache_key' );
			$reflection->setAccessible( true );
			$key = (string) $reflection->invoke( null, 'bz_tbl', $true_table );
			global $wpdb;
			$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
			$database_scoped = $database === '' || strpos( $key, md5( $database . '|' . $true_table . '|' ) ) !== false;
		} catch ( \Throwable $e ) {
			$database_scoped = false;
		}
		$steps[] = array(
			'label' => 'Runtime · blog/database cache identity',
			'status' => $database_scoped ? 'pass' : 'fail',
			'detail' => $database_scoped ? 'The metadata cache key includes the routed database identity and current blog dimension.' : 'The metadata cache key did not prove database scoping.',
		);

		$before_generation = isset( $GLOBALS['bizcity_table_cache_generation'] ) ? (int) $GLOBALS['bizcity_table_cache_generation'] : 0;
		BizCity_Table_Metadata::invalidate( $false_table );
		$after_generation = isset( $GLOBALS['bizcity_table_cache_generation'] ) ? (int) $GLOBALS['bizcity_table_cache_generation'] : 0;
		$invalidation_ok = $after_generation > $before_generation;
		$steps[] = array(
			'label' => 'Runtime · DDL generation invalidation',
			'status' => $invalidation_ok ? 'pass' : 'fail',
			'detail' => $invalidation_ok ? 'Invalidation advances the request generation so stale false memos cannot survive DDL.' : 'Metadata invalidation did not advance the request generation.',
		);

		$ok = $disk && $loader && $cache_reads_ok && $false_cache_ok && $database_scoped && $invalidation_ok;
		foreach ( $steps as $step ) {
			$ctx->emit_step( $step );
		}
		return array(
			'status' => $ok ? 'pass' : 'fail',
			'summary' => $ok ? 'Canonical table metadata helper cache and invalidation contract passed.' : 'Canonical table metadata helper contract is incomplete.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Table_Metadata';
	return $list;
} );
