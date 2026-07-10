<?php
/**
 * Probe: CRM · Shipping Tracker (Phase 0.38 W4).
 *
 * 3-layer DDV:
 *   Disk    — class-shipping-tracker.php exists + no BOM.
 *   Loader  — BizCity_CRM_Shipping_Tracker class loaded.
 *   Runtime — cron hook scheduled + bizcity_crm_shipment_status_log table exists.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.38.W4 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_CRM_Shipping_Tracker' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W4 — DDV probe: Shipping Tracker cron + table
final class BizCity_Probe_CRM_Shipping_Tracker implements BizCity_Diagnostics_Probe {

	const EXPECTED_HOOK = 'bizcity_crm_shipping_tracker_run';

	public function id(): string          { return 'crm.order.shipping-tracker'; }
	public function label(): string       { return 'CRM · Shipping Tracker Cron (W4)'; }
	public function description(): string { return 'Kiểm tra W4: class-shipping-tracker.php (Disk) · class loaded (Loader) · cron scheduled + bizcity_crm_shipment_status_log (Runtime).'; }
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 42; }
	public function icon(): string        { return 'truck'; }
	public function estimate_ms(): int    { return 150; }

	public function precondition(): bool  { return function_exists( 'wc_get_orders' ); }

	// [2026-06-07 Johnny Chu] PHP74-COMPAT — remove array type hint on $ctx; interface declares run($ctx) untyped
	public function run( $ctx ): array {
		$rows = array();
		$pass = true;
		$plugin_root = dirname( __DIR__, 3 );
		$woo_dir     = dirname( $plugin_root ) . '/plugins/bizcity-twin-crm/includes/woo/';

		// ── Disk ─────────────────────────────────────────────────────────────
		$file   = $woo_dir . 'class-shipping-tracker.php';
		$exists = file_exists( $file );
		$nobom  = $exists && ( file_get_contents( $file, false, null, 0, 3 ) !== "\xEF\xBB\xBF" );
		$rows[] = array( 'layer' => 'Disk', 'check' => 'class-shipping-tracker.php (no BOM)', 'status' => ( $exists && $nobom ) ? 'PASS' : 'FAIL', 'detail' => $exists ? ( $nobom ? 'OK' : 'BOM.' ) : 'Missing.' );
		if ( ! $exists || ! $nobom ) { $pass = false; }

		// ── Loader ───────────────────────────────────────────────────────────
		$class_ok = class_exists( 'BizCity_CRM_Shipping_Tracker' );
		$rows[] = array( 'layer' => 'Loader', 'check' => 'BizCity_CRM_Shipping_Tracker loaded', 'status' => $class_ok ? 'PASS' : 'FAIL', 'detail' => $class_ok ? 'Class in memory.' : 'Missing — check bizcity-twin-crm bootstrap.' );
		if ( ! $class_ok ) { $pass = false; }

		// Verify HOOK constant matches expected.
		$hook_ok = $class_ok && ( BizCity_CRM_Shipping_Tracker::HOOK === self::EXPECTED_HOOK );
		$rows[] = array( 'layer' => 'Loader', 'check' => 'HOOK constant = ' . self::EXPECTED_HOOK, 'status' => $hook_ok ? 'PASS' : ( $class_ok ? 'FAIL' : 'SKIP' ), 'detail' => $class_ok ? ( $hook_ok ? 'Correct.' : 'Mismatch: ' . BizCity_CRM_Shipping_Tracker::HOOK ) : 'Class not loaded.' );

		// ── Runtime ──────────────────────────────────────────────────────────
		// Cron scheduled.
		$cron_hook      = $class_ok ? BizCity_CRM_Shipping_Tracker::HOOK : self::EXPECTED_HOOK;
		$cron_scheduled = (bool) wp_next_scheduled( $cron_hook );
		$rows[] = array( 'layer' => 'Runtime', 'check' => "{$cron_hook} cron scheduled", 'status' => $cron_scheduled ? 'PASS' : 'FAIL', 'detail' => $cron_scheduled ? 'Scheduled (next run: ' . date( 'H:i:s', (int) wp_next_scheduled( $cron_hook ) ) . ').' : 'Not scheduled — activate plugin or wait for init.' );
		if ( ! $cron_scheduled ) { $pass = false; }

		// Table exists.
		global $wpdb;
		$tbl        = $wpdb->prefix . 'bizcity_crm_shipment_status_log';
		$tbl_exists = ( bizcity_tbl_exists( $tbl ) ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$rows[] = array( 'layer' => 'Runtime', 'check' => "Table {$tbl} exists", 'status' => $tbl_exists ? 'PASS' : 'FAIL', 'detail' => $tbl_exists ? 'Present.' : 'Missing — run migrate_phase_045().' );
		if ( ! $tbl_exists ) { $pass = false; }

		return array( 'status' => $pass ? 'PASS' : 'FAIL', 'rows' => $rows );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Shipping_Tracker';
	return $list;
} );
