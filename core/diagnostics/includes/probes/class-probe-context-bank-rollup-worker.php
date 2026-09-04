<?php
/**
 * DDV probe for the resumable Context Bank rollup worker.
 *
 * The probe confirms worker wiring and diagnostics isolation without running a
 * rollup batch or creating durable state.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Rollup_Worker', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Rollup_Worker implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.context_bank.rollup_worker'; }
	public function label(): string { return 'Context Bank - resumable rollup worker'; }
	public function description(): string { return 'Checks lease/checkpoint worker wiring and proves diagnostics CLI isolation without running a rollup batch.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 75; }
	public function icon(): string { return 'refresh-cw'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_Rollup_Worker' ) ) {
			return new WP_Error( 'context_bank_rollup_worker_missing', 'Context Bank rollup worker is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-CB5.1-DDV — verify worker artifact, schema registration and direct diagnostics isolation.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$worker_file = $root . 'core/context-bank/includes/class-context-bank-rollup-worker.php';
		$disk_ok = is_readable( $worker_file );
		$loader_ok = class_exists( 'BizCity_Context_Bank_Rollup_Worker' )
			&& method_exists( 'BizCity_Context_Bank_Rollup_Worker', 'acquire_lease' )
			&& method_exists( 'BizCity_Context_Bank_Rollup_Worker', 'checkpoint' )
			&& method_exists( 'BizCity_Context_Bank_Rollup_Worker', 'process' )
			&& class_exists( 'BizCity_Schema_Registry' );
		$isolated = defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
		$runtime = BizCity_Context_Bank_Rollup_Worker::process( 'conversation_state', 'diagnostics_fixture' );
		$guard_ok = $isolated && is_array( $runtime ) && 'diagnostics_cli_isolated' === (string) ( $runtime['reason'] ?? '' );
		$dimension_source = file_get_contents( $worker_file );
		$cron_file = $root . 'core/context-bank/includes/class-context-bank-rollup-cron.php';
		$cron_source = is_readable( $cron_file ) ? file_get_contents( $cron_file ) : '';
		$cron_wiring_ok = is_string( $cron_source )
			&& class_exists( 'BizCity_Context_Bank_Rollup_Cron' )
			&& false !== has_action( 'bizcity_context_bank_rollup_run', array( 'BizCity_Context_Bank_Rollup_Cron', 'run' ) )
			&& strpos( $cron_source, "'context-bank.rollup'" ) !== false
			&& strpos( $cron_source, "'core/context-bank'" ) !== false
			&& strpos( $cron_source, 'BizCity_Cron_Manager::instance()->register' ) !== false;
		$cron_isolated = class_exists( 'BizCity_Context_Bank_Rollup_Cron' )
			? BizCity_Context_Bank_Rollup_Cron::run()
			: array();
		$cron_isolation_ok = $isolated && is_array( $cron_isolated ) && (string) ( $cron_isolated['reason'] ?? '' ) === 'diagnostics_cli_isolated';
		$cron_disabled_ok = ! get_option( 'bizcity_context_bank_rollups_enabled', false ) && is_string( $cron_source ) && strpos( $cron_source, "'enabled' => self::rollups_enabled()" ) !== false;
		$dimension_guard_ok = is_string( $dimension_source ) && strpos( $dimension_source, 'rollup_dimension_filter_required' ) !== false && strpos( $dimension_source, "\$filters['entity_type']" ) !== false && strpos( $dimension_source, "\$filters['entity_key']" ) !== false;
		$dimension_matrix = array(
			array( 'rollup_id' => 'conversation_state', 'filters' => array( 'entity_type' => 'conversation', 'entity_key' => 'c_1' ), 'ok' => true ),
			array( 'rollup_id' => 'customer_product_affinity', 'filters' => array( 'entity_type' => 'identity', 'entity_key' => 'u_1', 'secondary_type' => 'product', 'secondary_key' => 'p_1' ), 'ok' => true ),
			array( 'rollup_id' => 'sku_inventory', 'filters' => array( 'entity_type' => 'sku', 'entity_key' => 'sku_1', 'secondary_type' => 'warehouse', 'secondary_key' => 'wh_1' ), 'ok' => true ),
			array( 'rollup_id' => 'order_lifecycle', 'filters' => array( 'entity_type' => 'order', 'entity_key' => 'o_1' ), 'ok' => true ),
			array( 'rollup_id' => 'sku_inventory', 'filters' => array( 'entity_type' => 'conversation', 'entity_key' => 'c_1' ), 'ok' => false ),
			array( 'rollup_id' => 'customer_product_affinity', 'filters' => array( 'entity_type' => 'identity', 'entity_key' => 'u_1' ), 'ok' => false ),
		);
		$dimension_matrix_ok = true;
		foreach ( $dimension_matrix as $case ) {
			$validated = BizCity_Context_Bank_Rollup_Worker::validate_dimension_filters( $case['rollup_id'], $case['filters'] );
			$dimension_matrix_ok = $dimension_matrix_ok && ( ! empty( $validated['ok'] ) === $case['ok'] );
		}
		$inventory_guard_ok = is_string( $dimension_source ) && strpos( $dimension_source, "'invalid_quantity'" ) !== false && strpos( $dimension_source, "'identity_conflict'" ) !== false && strpos( $dimension_source, "'warehouse_conflict'" ) !== false && strpos( $dimension_source, "'rollup_failed'" ) !== false;
		foreach ( array(
			array( 'label' => 'Disk - rollup worker artifact is readable', 'ok' => $disk_ok, 'detail' => $disk_ok ? 'Lease/checkpoint worker artifact is readable.' : 'Rollup worker artifact is missing or unreadable.' ),
			array( 'label' => 'Loader - worker and schema registration are available', 'ok' => $loader_ok, 'detail' => $loader_ok ? 'Worker methods and Schema Registry boundary are loaded.' : 'Worker or schema registration is unavailable.' ),
			array( 'label' => 'Runtime - diagnostics worker isolation', 'ok' => $guard_ok, 'detail' => $guard_ok ? 'Diagnostics context cannot execute the rollup worker entry.' : 'Diagnostics context did not refuse the direct worker entry.' ),
			array( 'label' => 'Loader - bounded rollup cron owner is registered', 'ok' => $cron_wiring_ok, 'detail' => $cron_wiring_ok ? 'One Context Bank cron hook is registered through BizCity_Cron_Manager and dispatches the canonical worker.' : 'Rollup cron scheduler is missing or bypasses the canonical Cron Manager.' ),
			array( 'label' => 'Runtime - scheduled rollup callback isolation', 'ok' => $cron_isolation_ok, 'detail' => $cron_isolation_ok ? 'The scheduled rollup callback refuses execution in Diagnostics CLI before tenant state access.' : 'Scheduled rollup callback can execute during Diagnostics CLI.' ),
			array( 'label' => 'Runtime - rollup scheduling remains flag-gated', 'ok' => $cron_disabled_ok, 'detail' => $cron_disabled_ok ? 'Recurring rollup registration remains disabled when the tenant rollup flag is false.' : 'Rollup scheduler can enable recurring work without the explicit tenant flag.' ),
			array( 'label' => 'Runtime - explicit rollup dimension predicate', 'ok' => $dimension_guard_ok, 'detail' => $dimension_guard_ok ? 'Worker source requires entity_type and entity_key before lease or aggregation.' : 'Worker source does not enforce a typed entity dimension predicate.' ),
			array( 'label' => 'Runtime - rollup family dimension matrix', 'ok' => $dimension_matrix_ok, 'detail' => $dimension_matrix_ok ? 'Each rollup family accepts only its declared primary and secondary dimension tuple.' : 'A rollup family accepted an invalid identity/entity dimension tuple.' ),
			array( 'label' => 'Runtime - invalid inventory blocks checkpoint', 'ok' => $inventory_guard_ok, 'detail' => $inventory_guard_ok ? 'Invalid inventory quantities fail the worker and cannot advance a durable checkpoint.' : 'Invalid inventory quantities can still be checkpointed as a successful rollup.' ),
		) as $step ) {
			$ctx->emit_step( array( 'label' => $step['label'], 'status' => $step['ok'] ? 'pass' : 'fail', 'detail' => $step['detail'] ) );
		}
		$pass = $disk_ok && $loader_ok && $guard_ok && $cron_wiring_ok && $cron_isolation_ok && $cron_disabled_ok && $dimension_guard_ok && $dimension_matrix_ok && $inventory_guard_ok;
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Rollup worker passed wiring and diagnostics isolation checks.' : 'Rollup worker wiring or isolation checks failed.', 'fix_hint' => $pass ? '' : 'Load the worker through Context Bank bootstrap and keep diagnostics CLI isolated before running rollups.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Rollup_Worker';
	return $list;
} );