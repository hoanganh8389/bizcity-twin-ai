<?php
/**
 * Probe: CRM · Order Fulfillment Hub W2-W4 (Phase 0.38).
 *
 * 3-layer DDV diagnostic covering:
 *   W2 — Recap Notifier: class loaded + bizcity_crm_order_recap_log table exists + INSERT/SELECT round-trip.
 *   W3 — Public Token: encode/decode round-trip + REST route registered + rewrite rule tag exists.
 *   W4 — Shipping Tracker: class loaded + cron hook scheduled + bizcity_crm_shipment_status_log table exists.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.38.W2-W4 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_CRM_Order_Fulfillment' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W2-W4 — DDV probe: Notifier + Token + Tracker
final class BizCity_Probe_CRM_Order_Fulfillment implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'crm.order.fulfillment-hub'; }
	public function label(): string       { return 'CRM · Order Fulfillment Hub (W2-W4)'; }
	public function description(): string { return 'Kiểm tra Phase 0.38 W2-W4: Recap Notifier (class+table) · Public Token (encode/decode) · REST /order-tracking/ · Shipping Tracker (cron+table).'; }
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 39; }
	public function icon(): string        { return 'package'; }
	public function estimate_ms(): int    { return 600; }

	public function precondition(): bool {
		return function_exists( 'wc_create_order' );
	}

	// [2026-06-08 Johnny Chu] HOTFIX — remove array type hint to match interface run($ctx): array.
	public function run( $ctx ): array {
		$rows = array();
		$pass = true;
		global $wpdb;

		// ── W2: RECAP NOTIFIER ────────────────────────────────────────────────

		// Disk: notifier file.
		$plugin_root   = dirname( __DIR__, 3 ); // core/diagnostics/includes/probes → core → plugin root
		$crm_woo_dir   = dirname( $plugin_root ) . '/plugins/bizcity-twin-crm/includes/woo/';
		$notifier_file = $crm_woo_dir . 'class-woo-order-recap-notifier.php';
		$disk_ok       = file_exists( $notifier_file );
		$bom_ok        = $disk_ok && ( file_get_contents( $notifier_file, false, null, 0, 3 ) !== "\xEF\xBB\xBF" );

		$rows[] = array(
			'layer'  => 'Disk',
			'check'  => 'class-woo-order-recap-notifier.php exists (no BOM)',
			'status' => ( $disk_ok && $bom_ok ) ? 'PASS' : 'FAIL',
			'detail' => $disk_ok ? ( $bom_ok ? 'OK' : 'BOM detected.' ) : 'File not found.',
		);
		if ( ! $disk_ok || ! $bom_ok ) { $pass = false; }

		// Loader: class.
		$notifier_ok = class_exists( 'BizCity_CRM_Woo_Order_Recap_Notifier' );
		$rows[] = array(
			'layer'  => 'Loader',
			'check'  => 'BizCity_CRM_Woo_Order_Recap_Notifier loaded',
			'status' => $notifier_ok ? 'PASS' : 'FAIL',
			'detail' => $notifier_ok ? 'Class in memory.' : 'Class missing — check bizcity-twin-crm/bootstrap.php.',
		);
		if ( ! $notifier_ok ) { $pass = false; }

		// Runtime: recap_log table exists + INSERT + SELECT + DELETE round-trip.
		$recap_tbl  = $wpdb->prefix . 'bizcity_crm_order_recap_log';
		$tbl_exists = ( bizcity_tbl_exists( $recap_tbl ) ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$rows[] = array(
			'layer'  => 'Runtime',
			'check'  => "Table {$recap_tbl} exists",
			'status' => $tbl_exists ? 'PASS' : 'FAIL',
			'detail' => $tbl_exists ? 'Table present.' : 'Table missing — run migrate_phase_045().',
		);
		if ( ! $tbl_exists ) {
			$pass = false;
		} else {
			// INSERT probe row.
			$inserted = $wpdb->insert( $recap_tbl, array(
				'order_id'   => 0,
				'recap_type' => '__probe__',
				'platform'   => 'PROBE',
				'chat_id'    => '__probe__',
				'status'     => 'sent',
				'sent_at'    => current_time( 'mysql', true ),
			), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
			$probe_id = $inserted ? (int) $wpdb->insert_id : 0;
			$found    = $probe_id > 0 && (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `{$recap_tbl}` WHERE id = %d AND recap_type = '__probe__'", $probe_id
			) ) === $probe_id;
			if ( $probe_id > 0 ) {
				$wpdb->delete( $recap_tbl, array( 'id' => $probe_id ), array( '%d' ) );
			}
			$rows[] = array(
				'layer'  => 'Runtime',
				'check'  => 'recap_log INSERT/SELECT/DELETE round-trip',
				'status' => $found ? 'PASS' : 'FAIL',
				'detail' => $found ? "Probe row #{$probe_id} inserted + deleted." : 'Round-trip failed.',
			);
			if ( ! $found ) { $pass = false; }
		}

		// ── W3: PUBLIC TOKEN ──────────────────────────────────────────────────

		// Disk: token file.
		$token_file = $crm_woo_dir . 'class-order-public-token.php';
		$tdisk_ok   = file_exists( $token_file );
		$tbom_ok    = $tdisk_ok && ( file_get_contents( $token_file, false, null, 0, 3 ) !== "\xEF\xBB\xBF" );
		$rows[] = array(
			'layer'  => 'Disk',
			'check'  => 'class-order-public-token.php exists (no BOM)',
			'status' => ( $tdisk_ok && $tbom_ok ) ? 'PASS' : 'FAIL',
			'detail' => $tdisk_ok ? ( $tbom_ok ? 'OK' : 'BOM detected.' ) : 'File not found.',
		);
		if ( ! $tdisk_ok || ! $tbom_ok ) { $pass = false; }

		// Loader: class.
		$token_class_ok = class_exists( 'BizCity_CRM_Order_Public_Token' );
		$rows[] = array(
			'layer'  => 'Loader',
			'check'  => 'BizCity_CRM_Order_Public_Token loaded',
			'status' => $token_class_ok ? 'PASS' : 'FAIL',
			'detail' => $token_class_ok ? 'Class in memory.' : 'Class missing.',
		);
		if ( ! $token_class_ok ) { $pass = false; }

		// Runtime: encode → decode round-trip.
		if ( $token_class_ok ) {
			$test_order_id  = 9999991;
			$encoded        = BizCity_CRM_Order_Public_Token::encode( $test_order_id );
			$verify_ok      = BizCity_CRM_Order_Public_Token::verify( $encoded, $test_order_id );
			$verify_fail    = ! BizCity_CRM_Order_Public_Token::verify( $encoded, $test_order_id + 1 );
			$rt_ok          = $verify_ok && $verify_fail && strlen( $encoded ) === 16;
			$rows[] = array(
				'layer'  => 'Runtime',
				'check'  => 'Public token encode/verify round-trip (order_id=9999991)',
				'status' => $rt_ok ? 'PASS' : 'FAIL',
				'detail' => $rt_ok
					? "Token: '{$encoded}' (len=16), verify(correct)=true, verify(wrong)=false."
					: "Token: '{$encoded}' (len=" . strlen( $encoded ) . "), verify_ok={$verify_ok}, verify_tamper_rejected={$verify_fail}.",
			);
			if ( ! $rt_ok ) { $pass = false; }
		} else {
			$rows[] = array( 'layer' => 'Runtime', 'check' => 'Public token round-trip', 'status' => 'SKIP', 'detail' => 'Class not loaded.' );
		}

		// Loader: REST route registered.
		$rest_routes   = rest_get_server()->get_routes();
		$tracking_route = '/bizcity-channel/v1/order-tracking/(?P<token>[A-Za-z0-9]{8,32})';
		$rest_ok        = isset( $rest_routes[ $tracking_route ] );
		$rows[] = array(
			'layer'  => 'Loader',
			'check'  => 'REST route /bizcity-channel/v1/order-tracking/{token} registered',
			'status' => $rest_ok ? 'PASS' : 'FAIL',
			'detail' => $rest_ok ? 'Route found in WP REST server.' : 'Route missing — check channel-gateway/bootstrap.php + BizCity_CG_Order_Tracking_REST::init().',
		);
		if ( ! $rest_ok ) { $pass = false; }

		// Loader: rewrite tag registered.
		global $wp_rewrite;
		$rewrite_ok = false;
		if ( isset( $wp_rewrite->extra_permastructs ) ) {
			$rewrite_ok = isset( $wp_rewrite->extra_permastructs['bizcity_order_token'] );
		}
		// Also accept if query var is registered (WP may not init rewrite on REST requests).
		if ( ! $rewrite_ok ) {
			global $wp;
			$rewrite_ok = in_array( 'bizcity_order_token', (array) $wp->public_query_vars, true );
		}
		$rows[] = array(
			'layer'  => 'Loader',
			'check'  => 'Rewrite tag bizcity_order_token registered',
			'status' => $rewrite_ok ? 'PASS' : 'SKIP',
			'detail' => $rewrite_ok ? 'Tag registered.' : 'Tag not yet registered (normal on REST requests — flush permalinks to confirm).',
		);

		// ── W4: SHIPPING TRACKER ──────────────────────────────────────────────

		// Disk: tracker file.
		$tracker_file = $crm_woo_dir . 'class-shipping-tracker.php';
		$sdisk_ok     = file_exists( $tracker_file );
		$sbom_ok      = $sdisk_ok && ( file_get_contents( $tracker_file, false, null, 0, 3 ) !== "\xEF\xBB\xBF" );
		$rows[] = array(
			'layer'  => 'Disk',
			'check'  => 'class-shipping-tracker.php exists (no BOM)',
			'status' => ( $sdisk_ok && $sbom_ok ) ? 'PASS' : 'FAIL',
			'detail' => $sdisk_ok ? ( $sbom_ok ? 'OK' : 'BOM detected.' ) : 'File not found.',
		);
		if ( ! $sdisk_ok || ! $sbom_ok ) { $pass = false; }

		// Loader: class.
		$tracker_class_ok = class_exists( 'BizCity_CRM_Shipping_Tracker' );
		$rows[] = array(
			'layer'  => 'Loader',
			'check'  => 'BizCity_CRM_Shipping_Tracker loaded',
			'status' => $tracker_class_ok ? 'PASS' : 'FAIL',
			'detail' => $tracker_class_ok ? 'Class in memory.' : 'Class missing.',
		);
		if ( ! $tracker_class_ok ) { $pass = false; }

		// Runtime: cron hook scheduled + shipment_status_log table exists.
		$tracker_hook   = defined( 'BizCity_CRM_Shipping_Tracker::HOOK' ) || $tracker_class_ok
			? BizCity_CRM_Shipping_Tracker::HOOK
			: 'bizcity_crm_shipping_tracker_run';
		$cron_scheduled = (bool) wp_next_scheduled( $tracker_hook );
		$rows[] = array(
			'layer'  => 'Runtime',
			'check'  => 'bizcity_crm_shipping_tracker_run cron scheduled',
			'status' => $cron_scheduled ? 'PASS' : 'FAIL',
			'detail' => $cron_scheduled ? 'Cron event found in WP schedule.' : 'Not scheduled — check BizCity_CRM_Shipping_Tracker::register_job().',
		);
		if ( ! $cron_scheduled ) { $pass = false; }

		$ship_tbl   = $wpdb->prefix . 'bizcity_crm_shipment_status_log';
		$ship_exists = ( bizcity_tbl_exists( $ship_tbl ) ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$rows[] = array(
			'layer'  => 'Runtime',
			'check'  => "Table {$ship_tbl} exists",
			'status' => $ship_exists ? 'PASS' : 'FAIL',
			'detail' => $ship_exists ? 'Table present.' : 'Table missing — run migrate_phase_045().',
		);
		if ( ! $ship_exists ) { $pass = false; }

		return array(
			'status' => $pass ? 'PASS' : 'FAIL',
			'rows'   => $rows,
		);
	}

	public function cleanup(): void {}
}

// Register probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Order_Fulfillment';
	return $list;
} );
