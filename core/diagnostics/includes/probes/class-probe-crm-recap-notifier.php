<?php
/**
 * Probe: CRM · Order Recap Notifier (Phase 0.38 W2).
 *
 * 3-layer DDV:
 *   Disk    — class-woo-order-recap-notifier.php exists + no BOM.
 *   Loader  — BizCity_CRM_Woo_Order_Recap_Notifier class loaded.
 *   Runtime — bizcity_crm_order_recap_log table exists + INSERT/SELECT/DELETE round-trip.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.38.W2 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_CRM_Recap_Notifier' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W2 — DDV probe: Order Recap Notifier
final class BizCity_Probe_CRM_Recap_Notifier implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'crm.order.recap-notifier'; }
	public function label(): string       { return 'CRM · Order Recap Notifier (W2)'; }
	public function description(): string { return 'Kiểm tra Recap Notifier: file + class (Disk/Loader) · bizcity_crm_order_recap_log INSERT/SELECT/DELETE (Runtime).'; }
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 40; }
	public function icon(): string        { return 'bell'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition(): bool  { return function_exists( 'wc_get_order' ); }

	// [2026-06-07 Johnny Chu] PHP74-COMPAT — remove array type hint on $ctx; interface declares run($ctx) untyped
	public function run( $ctx ): array {
		$rows = array();
		$pass = true;
		$plugin_root = dirname( __DIR__, 3 );
		$woo_dir     = dirname( $plugin_root ) . '/plugins/bizcity-twin-crm/includes/woo/';

		// ── Disk ─────────────────────────────────────────────────────────────
		$file   = $woo_dir . 'class-woo-order-recap-notifier.php';
		$exists = file_exists( $file );
		$nobom  = $exists && ( file_get_contents( $file, false, null, 0, 3 ) !== "\xEF\xBB\xBF" );
		$rows[] = array( 'layer' => 'Disk', 'check' => 'class-woo-order-recap-notifier.php (no BOM)', 'status' => ( $exists && $nobom ) ? 'PASS' : 'FAIL', 'detail' => $exists ? ( $nobom ? 'OK' : 'BOM detected.' ) : 'File missing.' );
		if ( ! $exists || ! $nobom ) { $pass = false; }

		// ── Loader ───────────────────────────────────────────────────────────
		$class_ok = class_exists( 'BizCity_CRM_Woo_Order_Recap_Notifier' );
		$rows[] = array( 'layer' => 'Loader', 'check' => 'BizCity_CRM_Woo_Order_Recap_Notifier loaded', 'status' => $class_ok ? 'PASS' : 'FAIL', 'detail' => $class_ok ? 'Class in memory.' : 'Missing — check bizcity-twin-crm bootstrap.' );
		if ( ! $class_ok ) { $pass = false; }

		// ── Runtime ──────────────────────────────────────────────────────────
		global $wpdb;
		$tbl        = $wpdb->prefix . 'bizcity_crm_order_recap_log';
		$tbl_exists = ( bizcity_tbl_exists( $tbl ) ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$rows[] = array( 'layer' => 'Runtime', 'check' => "Table {$tbl} exists", 'status' => $tbl_exists ? 'PASS' : 'FAIL', 'detail' => $tbl_exists ? 'Present.' : 'Missing — run migrate_phase_045().' );
		if ( ! $tbl_exists ) {
			$pass = false;
		} else {
			$wpdb->insert( $tbl, array( 'order_id' => 0, 'recap_type' => '__probe__', 'platform' => 'PROBE', 'chat_id' => '__probe__', 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
			$probe_id = $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
			$found    = $probe_id > 0 && (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE id=%d AND recap_type='__probe__'", $probe_id ) ) === $probe_id;
			if ( $probe_id > 0 ) { $wpdb->delete( $tbl, array( 'id' => $probe_id ), array( '%d' ) ); }
			$rows[] = array( 'layer' => 'Runtime', 'check' => 'recap_log INSERT/SELECT/DELETE', 'status' => $found ? 'PASS' : 'FAIL', 'detail' => $found ? "Probe row #{$probe_id} ok." : 'Round-trip failed.' );
			if ( ! $found ) { $pass = false; }
		}

		return array( 'status' => $pass ? 'PASS' : 'FAIL', 'rows' => $rows );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Recap_Notifier';
	return $list;
} );
