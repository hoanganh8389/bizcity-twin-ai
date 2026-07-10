<?php
/**
 * BizCity CRM — Woo Order Bridge (PHASE 0.35 M-CRM.M8.W1).
 *
 * Thin facade over the existing `includes/class-order-adapter.php` (which
 * carries the concrete {@see BizCity_CRM_Order_Adapter_Woo_Bank_QR}
 * implementation + Registry). This file is the **canonical entry-point
 * for the Woo Bridge orchestrator** (see {@see BizCity_CRM_Woo_Bridge}).
 *
 * Why a facade and not a rename?
 *   The order-adapter class name + interface are already public API
 *   referenced from REST handlers and FE Order tab. A rename would
 *   break BC; instead the orchestrator boots through this facade and
 *   the legacy file's `plugins_loaded@30` self-boot remains as a
 *   safety net (Registry::boot() is idempotent — sees `$adapters[]`
 *   already populated and skips re-register).
 *
 * Future cleanup (M-CRM.M8.W7): move the 540-line implementation into
 * this directory and reduce `includes/class-order-adapter.php` to a
 * deprecated shim.
 *
 * @package BizCity_Twin_CRM\Woo
 * @since   1.11.0 (2026-05-13)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Woo_Order_Bridge' ) ) { return; }

// Pull in the existing implementation. Path is two levels up from
// includes/woo/ → includes/class-order-adapter.php.
require_once dirname( __DIR__ ) . '/class-order-adapter.php';

final class BizCity_CRM_Woo_Order_Bridge {

	private static bool $booted = false;

	/**
	 * Boot the Woo order adapter registry. Idempotent: the legacy file's
	 * `plugins_loaded@30` hook also calls Registry::boot() — running twice
	 * is harmless because `register()` keys by slug.
	 */
	public static function register(): void {
		if ( self::$booted ) { return; }
		if ( class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) ) {
			BizCity_CRM_Order_Adapter_Registry::boot();
		}
		self::$booted = true;
	}

	/** @return BizCity_CRM_Order_Adapter_Interface|null */
	public static function default_adapter() {
		if ( ! class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) ) { return null; }
		return BizCity_CRM_Order_Adapter_Registry::default_adapter();
	}
}
