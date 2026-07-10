<?php
/**
 * Probe: CRM · Order Public Tracking (Phase 0.38 W3).
 *
 * 3-layer DDV:
 *   Disk    — class-order-public-token.php + class-order-public-controller.php exist + no BOM.
 *   Loader  — BizCity_CRM_Order_Public_Token class + REST route registered + controller class.
 *   Runtime — token encode/verify round-trip: verify(correct)=true, verify(tampered)=false, len=16.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.38.W3 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_CRM_Public_Tracking' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W3 — DDV probe: Public Tracking Page token + REST + rewrite
final class BizCity_Probe_CRM_Public_Tracking implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'crm.order.public-tracking'; }
	public function label(): string       { return 'CRM · Order Public Tracking Page (W3)'; }
	public function description(): string { return 'Kiểm tra W3: token codec (Disk/Loader) · encode/verify round-trip (Runtime) · REST /bizcity-channel/v1/order-tracking/{token} registered.'; }
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 41; }
	public function icon(): string        { return 'link'; }
	public function estimate_ms(): int    { return 200; }

	public function precondition(): bool  { return true; }

	// [2026-06-07 Johnny Chu] PHP74-COMPAT — remove array type hint on $ctx; interface declares run($ctx) untyped
	public function run( $ctx ): array {
		$rows = array();
		$pass = true;
		$plugin_root = dirname( __DIR__, 3 );
		$woo_dir     = dirname( $plugin_root ) . '/plugins/bizcity-twin-crm/includes/woo/';

		// ── Disk ─────────────────────────────────────────────────────────────
		foreach ( array( 'class-order-public-token.php', 'class-order-public-controller.php' ) as $fname ) {
			$file   = $woo_dir . $fname;
			$exists = file_exists( $file );
			$nobom  = $exists && ( file_get_contents( $file, false, null, 0, 3 ) !== "\xEF\xBB\xBF" );
			$rows[] = array( 'layer' => 'Disk', 'check' => "{$fname} (no BOM)", 'status' => ( $exists && $nobom ) ? 'PASS' : 'FAIL', 'detail' => $exists ? ( $nobom ? 'OK' : 'BOM.' ) : 'Missing.' );
			if ( ! $exists || ! $nobom ) { $pass = false; }
		}

		// ── Loader ───────────────────────────────────────────────────────────
		$token_ok  = class_exists( 'BizCity_CRM_Order_Public_Token' );
		$ctrl_ok   = class_exists( 'BizCity_CRM_Order_Public_Controller' );
		$rows[] = array( 'layer' => 'Loader', 'check' => 'BizCity_CRM_Order_Public_Token loaded', 'status' => $token_ok ? 'PASS' : 'FAIL', 'detail' => $token_ok ? 'OK' : 'Missing.' );
		$rows[] = array( 'layer' => 'Loader', 'check' => 'BizCity_CRM_Order_Public_Controller loaded', 'status' => $ctrl_ok ? 'PASS' : 'FAIL', 'detail' => $ctrl_ok ? 'OK' : 'Missing.' );
		if ( ! $token_ok ) { $pass = false; }

		// REST route check.
		$routes     = rest_get_server()->get_routes();
		$route_key  = '/bizcity-channel/v1/order-tracking/(?P<token>[A-Za-z0-9]{8,32})';
		$rest_ok    = isset( $routes[ $route_key ] );
		$rows[] = array( 'layer' => 'Loader', 'check' => 'REST /bizcity-channel/v1/order-tracking/{token} registered', 'status' => $rest_ok ? 'PASS' : 'FAIL', 'detail' => $rest_ok ? 'Route found.' : 'Missing — check class-cg-order-tracking-rest.php.' );
		if ( ! $rest_ok ) { $pass = false; }

		// CSAT REST route.
		$csat_key  = '/bizcity-channel/v1/order-tracking/(?P<token>[A-Za-z0-9]{8,32})/csat';
		$csat_ok   = isset( $routes[ $csat_key ] );
		$rows[] = array( 'layer' => 'Loader', 'check' => 'REST .../csat POST registered', 'status' => $csat_ok ? 'PASS' : 'FAIL', 'detail' => $csat_ok ? 'Route found.' : 'Missing.' );
		if ( ! $csat_ok ) { $pass = false; }

		// ── Runtime: token encode/verify ─────────────────────────────────────
		if ( $token_ok ) {
			$oid    = 9999992;
			$tok    = BizCity_CRM_Order_Public_Token::encode( $oid );
			$v_ok   = BizCity_CRM_Order_Public_Token::verify( $tok, $oid );
			$v_fail = ! BizCity_CRM_Order_Public_Token::verify( $tok, $oid + 1 );
			$len_ok = strlen( $tok ) === 16;
			$rt_ok  = $v_ok && $v_fail && $len_ok;
			$rows[] = array(
				'layer'  => 'Runtime',
				'check'  => 'Token encode+verify round-trip (order_id=9999992)',
				'status' => $rt_ok ? 'PASS' : 'FAIL',
				'detail' => $rt_ok
					? "Token '{$tok}' (len=16): verify OK, tamper rejected."
					: "Token '{$tok}' len=" . strlen( $tok ) . " verify_ok={$v_ok} tamper_rejected={$v_fail}.",
			);
			if ( ! $rt_ok ) { $pass = false; }
		} else {
			$rows[] = array( 'layer' => 'Runtime', 'check' => 'Token round-trip', 'status' => 'SKIP', 'detail' => 'Class not loaded.' );
		}

		// bizcity_crm_order_csat table.
		global $wpdb;
		$csat_tbl    = $wpdb->prefix . 'bizcity_crm_order_csat';
		$csat_exists = ( bizcity_tbl_exists( $csat_tbl ) ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$rows[] = array( 'layer' => 'Runtime', 'check' => "Table {$csat_tbl} exists", 'status' => $csat_exists ? 'PASS' : 'FAIL', 'detail' => $csat_exists ? 'Present.' : 'Missing — run migrate_phase_045().' );
		if ( ! $csat_exists ) { $pass = false; }

		return array( 'status' => $pass ? 'PASS' : 'FAIL', 'rows' => $rows );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Public_Tracking';
	return $list;
} );
