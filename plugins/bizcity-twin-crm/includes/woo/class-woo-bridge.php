<?php
/**
 * BizCity CRM — WooCommerce Bridge Orchestrator (PHASE 0.35 M-CRM.M8.W1).
 *
 * Single load gate for all Woo↔CRM bridges. Sub-bridges live next to this
 * file under `includes/woo/`:
 *
 *   - class-woo-customer-bridge.php   wp_users ↔ bizcity_crm_contacts
 *   - class-woo-order-bridge.php      WC_Order create/list (was includes/class-order-adapter.php)
 *   - class-woo-invoice-bridge.php    WC_Order status ↔ crm_invoices
 *   - class-woo-reports-bridge.php    revenue/AOV/top-customers (cached)
 *
 * Every bridge MUST be safe to require even when WooCommerce is inactive
 * (the orchestrator only calls `boot()` when Woo is loaded). Sub-bridges
 * register their own hooks inside `boot()`.
 *
 * Action `bizcity_crm_woo_bridge_loaded` fires once after all sub-bridges
 * are booted (used by diagnostics + Reports cache warmup).
 *
 * @package BizCity_Twin_CRM\Woo
 * @since   1.11.0 (2026-05-13)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Woo_Bridge' ) ) { return; }

final class BizCity_CRM_Woo_Bridge {

	/** Sub-bridges that have booted (slug => class). @var array<string,string> */
	private static array $booted = array();

	public static function boot(): void {
		// Hard guard: WooCommerce must be active for any sub-bridge to be useful.
		if ( ! self::is_woo_active() ) {
			return;
		}

		$dir = __DIR__ . '/';

		// Required (always shipped). Each file is class_exists-guarded.
		require_once $dir . 'class-woo-customer-bridge.php';
		require_once $dir . 'class-woo-order-bridge.php';
		require_once $dir . 'class-woo-invoice-bridge.php';
		require_once $dir . 'class-woo-reports-bridge.php';

		if ( class_exists( 'BizCity_CRM_Woo_Customer_Bridge' ) ) {
			BizCity_CRM_Woo_Customer_Bridge::register();
			self::$booted['customer'] = 'BizCity_CRM_Woo_Customer_Bridge';
		}
		if ( class_exists( 'BizCity_CRM_Woo_Order_Bridge' ) ) {
			BizCity_CRM_Woo_Order_Bridge::register();
			self::$booted['order'] = 'BizCity_CRM_Woo_Order_Bridge';
		}
		if ( class_exists( 'BizCity_CRM_Woo_Invoice_Bridge' ) ) {
			BizCity_CRM_Woo_Invoice_Bridge::register();
			self::$booted['invoice'] = 'BizCity_CRM_Woo_Invoice_Bridge';
		}
		if ( class_exists( 'BizCity_CRM_Woo_Reports_Bridge' ) ) {
			BizCity_CRM_Woo_Reports_Bridge::register();
			self::$booted['reports'] = 'BizCity_CRM_Woo_Reports_Bridge';
		}

		do_action( 'bizcity_crm_woo_bridge_loaded', self::$booted );
	}

	public static function is_woo_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	public static function is_hpos_enabled(): bool {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}
		return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/** @return array<string,mixed> diagnostics snapshot */
	public static function status(): array {
		$wc_ver = '';
		if ( defined( 'WC_VERSION' ) ) { $wc_ver = (string) WC_VERSION; }
		return array(
			'wc_active'    => self::is_woo_active(),
			'wc_version'   => $wc_ver,
			'hpos'         => self::is_hpos_enabled(),
			'sub_bridges'  => array_values( self::$booted ),
			'auto_invoice' => (bool) get_option( 'bizcity_crm_woo_auto_invoice', false ),
		);
	}

	/** Build admin edit URL for a Woo order, HPOS-aware. */
	public static function order_admin_url( int $order_id ): string {
		if ( ! $order_id ) { return ''; }
		if ( self::is_hpos_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}
		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}
}
