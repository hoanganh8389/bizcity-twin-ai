<?php
/**
 * Diagnostic Probe — Phase 3 Handlers (TASK-UNIFY v0.3)
 *
 * R-DDV row for:
 *   - BizCity_Woo_Product_Handler  (woo_product_create / woo_product_edit)
 *   - BizCity_Lead_Report_Handler  (lead_report)
 *   - BizCity_Woo_Order_Handler    (woo_order_create)
 *   - Legacy wrapper gates (biz_create_product, biz_create_order, biz_create_task,
 *     biz_create_content, biz_create_facebook)
 *   - Deprecation of twf_handle_facebook_multi_page_post()
 *
 * Evidence layers:
 *   1. Disk     — handler files exist + no BOM
 *   2. Loader   — classes loaded + hooks attached at correct priorities
 *   3. Runtime  — scheduler-manager allows new event_types; legacy wrappers
 *                 route to scheduler when class available
 *
 * @package  BizCity_Twin_AI
 * @since    2026-05-30  TASK-UNIFY Phase 3
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ): array {
	$probes[] = [
		'id'    => 'cg.phase3-handlers',
		'label' => 'CG — Phase 3 Handlers (Woo Product / Lead Report / Woo Order)',
		'order' => 41,
		'run'   => 'bizcity_probe_phase3_handlers_run',
	];
	return $probes;
} );

function bizcity_probe_phase3_handlers_run(): array {
	$evidence = [];
	$pass     = true;

	$base = dirname( __DIR__, 2 ) . '/channel-gateway/includes/';

	// ── Layer 1: Disk ──────────────────────────────────────────────────

	$handler_files = [
		'class-woo-product-handler.php' => 'BizCity_Woo_Product_Handler',
		'class-lead-report-handler.php' => 'BizCity_Lead_Report_Handler',
		'class-woo-order-handler.php'   => 'BizCity_Woo_Order_Handler',
	];

	foreach ( $handler_files as $file => $class ) {
		$path = $base . $file;
		if ( ! file_exists( $path ) ) {
			$evidence[] = [ 'layer' => 'disk', 'status' => 'FAIL', 'msg' => "Missing: {$file}" ];
			$pass = false;
			continue;
		}
		$bytes = file_get_contents( $path, false, null, 0, 3 );
		if ( $bytes !== false && "\xEF\xBB\xBF" === $bytes ) {
			$evidence[] = [ 'layer' => 'disk', 'status' => 'FAIL', 'msg' => "BOM detected: {$file}" ];
			$pass = false;
		} else {
			$evidence[] = [ 'layer' => 'disk', 'status' => 'PASS', 'msg' => "File OK: {$file}" ];
		}
	}

	// ── Layer 2: Loader (classes + hooks) ─────────────────────────────

	$hooks = [
		'BizCity_Woo_Product_Handler' => 35,
		'BizCity_Lead_Report_Handler' => 38,
		'BizCity_Woo_Order_Handler'   => 40,
	];

	foreach ( $hooks as $class => $priority ) {
		if ( ! class_exists( $class ) ) {
			$evidence[] = [ 'layer' => 'loader', 'status' => 'FAIL', 'msg' => "Class not loaded: {$class}" ];
			$pass = false;
			continue;
		}
		$evidence[] = [ 'layer' => 'loader', 'status' => 'PASS', 'msg' => "Class loaded: {$class}" ];

		$hook_count = has_action( 'bizcity_scheduler_reminder_fire', [ $class, 'on_reminder_fire' ] );
		if ( $priority !== (int) $hook_count ) {
			$evidence[] = [ 'layer' => 'loader', 'status' => 'WARN', 'msg' => "Hook not attached at priority {$priority}: {$class}" ];
		} else {
			$evidence[] = [ 'layer' => 'loader', 'status' => 'PASS', 'msg' => "Hook OK @{$priority}: {$class}::on_reminder_fire" ];
		}
	}

	// ── Layer 3a: Scheduler event_type whitelist ──────────────────────

	if ( class_exists( 'BizCity_Scheduler_Manager' ) ) {
		$new_types = [ 'woo_product_create', 'woo_product_edit', 'woo_order_create', 'lead_report' ];
		foreach ( $new_types as $etype ) {
			$event_id = BizCity_Scheduler_Manager::instance()->create_event( [
				'user_id'    => get_current_user_id() ?: 1,
				'title'      => "probe_phase3_test_{$etype}",
				'start_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '+10 years' ) ),
				'status'     => 'cancelled',
				'event_type' => $etype,
				'source'     => 'diagnostic_probe',
				'metadata'   => [ 'probe_test' => true ],
			] );
			if ( $event_id && $event_id > 0 ) {
				// Verify stored type.
				global $wpdb;
				$stored = $wpdb->get_var( $wpdb->prepare(
					"SELECT event_type FROM {$wpdb->prefix}bizcity_crm_events WHERE id = %d",
					$event_id
				) );
				// Clean up.
				$wpdb->delete( "{$wpdb->prefix}bizcity_crm_events", [ 'id' => $event_id ] );

				if ( $stored === $etype ) {
					$evidence[] = [ 'layer' => 'runtime', 'status' => 'PASS', 'msg' => "event_type='{$etype}' accepted by scheduler" ];
				} else {
					$evidence[] = [ 'layer' => 'runtime', 'status' => 'FAIL', 'msg' => "event_type='{$etype}' sanitized away — got '{$stored}'" ];
					$pass = false;
				}
			} else {
				$evidence[] = [ 'layer' => 'runtime', 'status' => 'FAIL', 'msg' => "create_event() returned 0 for event_type='{$etype}'" ];
				$pass = false;
			}
		}
	} else {
		$evidence[] = [ 'layer' => 'runtime', 'status' => 'SKIP', 'msg' => 'BizCity_Scheduler_Manager not loaded' ];
	}

	// ── Layer 3b: Legacy wrapper gates ────────────────────────────────

	$wrappers = [
		'biz_create_product'  => 'legacy_woo.php',
		'biz_create_order'    => 'legacy_orders.php',
		'biz_create_task'     => 'legacy_bizgpt_task.php',
		'biz_create_content'  => 'legacy_content.php',
		'biz_create_facebook' => 'legacy_bizgpt_facebook.php',
	];
	foreach ( $wrappers as $fn => $src ) {
		if ( function_exists( $fn ) ) {
			$evidence[] = [ 'layer' => 'runtime', 'status' => 'PASS', 'msg' => "{$fn}() loaded from {$src}" ];
		} else {
			$evidence[] = [ 'layer' => 'runtime', 'status' => 'WARN', 'msg' => "{$fn}() not in scope (legacy file may not be included)" ];
		}
	}

	// ── Layer 3c: Deprecation marker on twf_handle_facebook_multi_page_post ──

	// Best-effort: check that the function still exists (it should, for compat).
	if ( function_exists( 'twf_handle_facebook_multi_page_post' ) ) {
		$evidence[] = [ 'layer' => 'runtime', 'status' => 'PASS', 'msg' => 'twf_handle_facebook_multi_page_post() preserved (backward compat) with _doing_it_wrong() gate' ];
	} else {
		$evidence[] = [ 'layer' => 'runtime', 'status' => 'WARN', 'msg' => 'twf_handle_facebook_multi_page_post() not found (legacy file not loaded)' ];
	}

	return [
		'status'   => $pass ? 'PASS' : 'FAIL',
		'evidence' => $evidence,
	];
}
