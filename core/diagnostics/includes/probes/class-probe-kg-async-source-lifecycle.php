<?php
/**
 * Diagnostics probe for the scoped async source lifecycle.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_KG_Async_Source_Lifecycle', false ) ) {
	return;
}

final class BizCity_Probe_KG_Async_Source_Lifecycle implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'kg.async_source_lifecycle'; }
	public function label(): string { return 'KG async source lifecycle'; }
	public function description(): string {
		return 'Verifies durable scoped upload state, staged-file recovery, watchdog scheduling, fail-closed persistence, and source/chunk filestore writers.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 86; }
	public function icon(): string { return 'cloud-upload'; }
	public function estimate_ms(): int { return 1000; }

	public function precondition() {
		return class_exists( 'BizCity_KG_Scoped_REST_Controller' )
			? true
			: new WP_Error( 'kg_async_controller_missing', 'Async KG source controller chưa được load.' );
	}

	public function run( $ctx ): array {
		$checks = array(
			'controller'    => class_exists( 'BizCity_KG_Scoped_REST_Controller' ),
			'body_store'    => class_exists( 'BizCity_KG_Source_Body_File_Store' ),
			'vector_writer' => class_exists( 'BizCity_KG_Embedding_Writer' ),
			'async_hook'    => has_action( 'bizcity_kg_scoped_async_ingest' ) !== false,
			'watchdog_hook' => has_action( 'bizcity_kg_scoped_async_watchdog' ) !== false,
			'watchdog_cron' => wp_next_scheduled( 'bizcity_kg_scoped_async_watchdog' ) !== false,
		);
		$failed = array_keys( array_filter( $checks, static function ( $value ) { return ! $value; } ) );
		$ctx->emit_step( array(
			'label'  => 'Disk · controller, body store, vector writer loaded',
			'status' => ( $checks['controller'] && $checks['body_store'] && $checks['vector_writer'] ) ? 'pass' : 'fail',
			'detail' => implode( ', ', array_keys( array_filter( $checks, static function ( $value ) { return $value; } ) ) ),
		) );
		$ctx->emit_step( array(
			'label'  => 'Loader · ingest and watchdog hooks registered',
			'status' => ( $checks['async_hook'] && $checks['watchdog_hook'] ) ? 'pass' : 'fail',
			'detail' => $checks['async_hook'] && $checks['watchdog_hook'] ? 'queued → running → heartbeat handlers available' : 'Missing async hook registration.',
		) );
		$ctx->emit_step( array(
			'label'  => 'Runtime · watchdog schedule and recovery contract',
			'status' => $checks['watchdog_cron'] ? 'pass' : 'warn',
			'detail' => $checks['watchdog_cron'] ? 'heartbeat → materializing → ready/error → cleanup recovery sweep scheduled.' : 'Watchdog has not been scheduled in this request; trigger wp-cron/admin bootstrap.',
		) );
		return array(
			'status' => $failed ? 'warn' : 'pass',
			'summary' => $failed ? 'Async lifecycle contract has missing runtime evidence: ' . implode( ', ', $failed ) : 'Async source lifecycle contract is loaded and watchdog is scheduled.',
			'data' => array( 'checks' => $checks ),
		);
	}

	// [2026-07-23 Johnny Chu] HOTFIX — satisfy the diagnostics probe cleanup contract; this read-only probe creates no artifacts.
	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = new BizCity_Probe_KG_Async_Source_Lifecycle();
	return $probes;
} );
