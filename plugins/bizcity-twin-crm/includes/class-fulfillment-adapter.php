<?php
/**
 * Provider-neutral fulfillment adapter contract.
 *
 * The registry deliberately has no built-in provider. Local Woo tracking is a
 * read-only compatibility source, not a fulfillment adapter. A provider must
 * register an implementation before quote/create/track/ETA is exposed.
 *
 * @package Bizcity_Twin_CRM
 * @since 2026-09-13 (PHASE-0.41-W8.3)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Fulfillment_Adapter_Registry', false ) ) {
	return;
}

interface BizCity_CRM_Fulfillment_Adapter_Interface {
	public function slug(): string;
	public function label(): string;
	public function is_available(): bool;
	/** @return string[] */
	public function capabilities(): array;
	/** @return array */
	public function quote( array $payload ): array;
	/** @return array */
	public function create( array $payload ): array;
	/** @return array */
	public function track( array $payload ): array;
	/** @return array */
	public function eta( array $payload ): array;
}

final class BizCity_CRM_Fulfillment_Adapter_Registry {

	/** @var array<string,BizCity_CRM_Fulfillment_Adapter_Interface> */
	private static $adapters = array();

	public static function register( BizCity_CRM_Fulfillment_Adapter_Interface $adapter ): void {
		// [2026-09-13 11:15 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.3 — reject duplicate fulfillment owner slugs instead of silently replacing an adapter.
		$slug = sanitize_key( $adapter->slug() );
		if ( $slug === '' || isset( self::$adapters[ $slug ] ) ) {
			return;
		}
		self::$adapters[ $slug ] = $adapter;
	}

	public static function get( string $slug ): ?BizCity_CRM_Fulfillment_Adapter_Interface {
		return self::$adapters[ sanitize_key( $slug ) ] ?? null;
	}

	/** @return BizCity_CRM_Fulfillment_Adapter_Interface[] */
	public static function available(): array {
		$available = array();
		foreach ( self::$adapters as $adapter ) {
			if ( $adapter->is_available() ) { $available[] = $adapter; }
		}
		return $available;
	}

	public static function default_adapter(): ?BizCity_CRM_Fulfillment_Adapter_Interface {
		$slug = (string) apply_filters( 'bizcity_crm_default_fulfillment_adapter', '' );
		if ( $slug !== '' ) {
			$adapter = self::get( $slug );
			if ( $adapter && $adapter->is_available() ) { return $adapter; }
		}
		$available = self::available();
		return $available[0] ?? null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function catalog(): array {
		$out = array();
		foreach ( self::$adapters as $adapter ) {
			$out[] = array(
				'slug' => sanitize_key( $adapter->slug() ),
				'label' => sanitize_text_field( $adapter->label() ),
				'available' => (bool) $adapter->is_available(),
				'capabilities' => array_values( array_map( 'sanitize_key', $adapter->capabilities() ) ),
			);
		}
		return $out;
	}
}

add_action( 'bizcity_crm_register_fulfillment_adapters', array( 'BizCity_CRM_Fulfillment_Adapter_Registry', 'register' ), 10, 1 );
