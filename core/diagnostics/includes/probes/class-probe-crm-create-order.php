<?php
/**
 * Probe: CRM · Create Woo Order (Phase 0.38 Order Fulfillment Hub).
 *
 * 3-layer DDV diagnostic:
 *   Disk   — file exists + no BOM.
 *   Loader — class loaded + WooCommerce active.
 *   Runtime— synthetic wc_create_order() → verify order_id + inbound meta → delete.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.38.W1.6 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_CRM_Create_Woo_Order' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W1.6 — probe DDV cho action.create_woo_order
final class BizCity_Probe_CRM_Create_Woo_Order implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'crm.order.create-action'; }
	public function label(): string       { return 'CRM · Create Woo Order (Automation Block)'; }
	public function description(): string { return 'Kiểm tra action.create_woo_order: file tồn tại (Disk) → class load + WooCommerce active (Loader) → synthetic order create/delete (Runtime).'; }
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 38; }
	public function icon(): string        { return 'shopping-cart'; }
	public function estimate_ms(): int    { return 800; }

	public function precondition(): bool {
		// Require WooCommerce.
		return function_exists( 'wc_create_order' );
	}

	// [2026-06-07 Johnny Chu] PHP74-COMPAT — remove array type hint on $ctx; interface declares run($ctx) untyped
	public function run( $ctx ): array {
		$rows    = array();
		$pass    = true;

		// ── LAYER 1: DISK ─────────────────────────────────────────────────────
		$block_file = trailingslashit( WP_PLUGIN_DIR ) . 'bizcity-twin-ai/core/automation/includes/blocks/actions/class-action-create-woo-order.php';
		// Adjust path: this probe lives inside bizcity-twin-ai plugin tree.
		$plugin_base = dirname( __DIR__, 3 ); // core/diagnostics/includes/probes → core → plugin root
		$block_file  = $plugin_base . '/automation/includes/blocks/actions/class-action-create-woo-order.php';

		$disk_ok = file_exists( $block_file );
		$bom_ok  = true;
		if ( $disk_ok ) {
			$bytes = file_get_contents( $block_file, false, null, 0, 3 );
			$bom_ok = ( $bytes !== "\xEF\xBB\xBF" );
		}

		$rows[] = array(
			'layer'  => 'Disk',
			'check'  => 'class-action-create-woo-order.php exists (no BOM)',
			'status' => ( $disk_ok && $bom_ok ) ? 'PASS' : 'FAIL',
			'detail' => $disk_ok
				? ( $bom_ok ? 'File exists, UTF-8 no BOM.' : 'BOM detected — PHP file corrupted.' )
				: 'File not found: ' . $block_file,
		);
		if ( ! $disk_ok || ! $bom_ok ) { $pass = false; }

		// ── LAYER 2: LOADER ───────────────────────────────────────────────────
		$class_ok = class_exists( 'BizCity_Automation_Action_Create_Woo_Order' );
		$woo_ok   = function_exists( 'wc_create_order' );

		$rows[] = array(
			'layer'  => 'Loader',
			'check'  => 'BizCity_Automation_Action_Create_Woo_Order loaded',
			'status' => $class_ok ? 'PASS' : 'FAIL',
			'detail' => $class_ok ? 'Class available in memory.' : 'Class not loaded — check automation/bootstrap.php require_once.',
		);
		$rows[] = array(
			'layer'  => 'Loader',
			'check'  => 'WooCommerce active (wc_create_order exists)',
			'status' => $woo_ok ? 'PASS' : 'SKIP',
			'detail' => $woo_ok ? 'WooCommerce active.' : 'WooCommerce not active — Runtime layer skipped.',
		);
		if ( ! $class_ok ) { $pass = false; }

		// ── LAYER 3: RUNTIME ──────────────────────────────────────────────────
		if ( ! $class_ok || ! $woo_ok ) {
			$rows[] = array(
				'layer'  => 'Runtime',
				'check'  => 'Synthetic order create + delete',
				'status' => 'SKIP',
				'detail' => 'Skipped: class or WooCommerce not loaded.',
			);
		} else {
			$runtime_ok = false;
			$rt_detail  = '';
			$test_oid   = 0;
			try {
				// Synthetic dispatch context.
				$ctx_synth = array(
					'trigger' => array(
						'inbound' => array(
							'platform'   => 'WEBCHAT',
							'chat_id'    => '__probe_healthtest__',
							'user_id'    => '0',
							'account_id' => '0',
							'message_id' => '__probe__',
							'raw_text'   => '[probe] create_woo_order synthetic',
						),
					),
				);
				// Use a simple free product or create a custom-line item.
				// We'll use items_json with a custom line (no real product) so the probe
				// works even without catalog data.
				$data_synth = array(
					'items_json'     => wp_json_encode( array( array( 'sku' => '__bizcity_probe__', 'qty' => 1, 'price_override' => 0.01 ) ) ),
					'shipping_name'  => '__BizcityProbe__',
					'shipping_phone' => '',
					'shipping_addr1' => 'Probe Street',
					'shipping_city'  => 'TestCity',
					'payment_method' => 'cod',
					'note'           => '__probe order — auto-delete__',
					'auto_recap'     => false,
				);

				$block  = new BizCity_Automation_Action_Create_Woo_Order();
				$result = $block->execute( $ctx_synth, $data_synth );

				if ( is_wp_error( $result ) ) {
					$rt_detail  = 'WP_Error: ' . $result->get_error_message();
				} elseif ( is_array( $result ) && isset( $result['order_id'] ) && (int) $result['order_id'] > 0 ) {
					$test_oid = (int) $result['order_id'];
					$order    = wc_get_order( $test_oid );

					if ( $order ) {
						// Verify inbound meta.
						$inb_plat = $order->get_meta( '_bizcity_inbound_platform', true );
						if ( $inb_plat === 'WEBCHAT' ) {
							$runtime_ok = true;
							$rt_detail  = "Order #{$test_oid} created, inbound_platform=WEBCHAT confirmed.";
						} else {
							$rt_detail = "Order #{$test_oid} created but _bizcity_inbound_platform not set (got: '{$inb_plat}').";
						}

						// Delete test order.
						$order->delete( true );
						$rt_detail .= ' [probe order deleted]';
					} else {
						$rt_detail = "wc_get_order({$test_oid}) returned false after creation.";
					}
				} else {
					$rt_detail = 'execute() returned unexpected: ' . wp_json_encode( $result );
				}
			} catch ( Exception $e ) {
				$rt_detail = 'Exception: ' . $e->getMessage();
			}

			$rows[] = array(
				'layer'  => 'Runtime',
				'check'  => 'Synthetic order create (wc_create_order + inbound meta)',
				'status' => $runtime_ok ? 'PASS' : 'FAIL',
				'detail' => $rt_detail,
			);
			if ( ! $runtime_ok ) { $pass = false; }
		}

		return array(
			'status' => $pass ? 'PASS' : 'FAIL',
			'rows'   => $rows,
		);
	}

	public function cleanup(): void {
		// No persistent state to clean up beyond the self-deleting probe order.
	}
}

// Register probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Create_Woo_Order';
	return $list;
} );
