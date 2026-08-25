<?php
/**
 * BizCity CRM — Channel Registry.
 *
 * Read-only accessor over the `bizcity_crm_register_adapters` filter.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Channel_Registry {

	/** @var BizCity_CRM_Channel_Adapter[]|null */
	private static $cache = null;

	/** @return BizCity_CRM_Channel_Adapter[] keyed by code */
	public static function all(): array {
		if ( null === self::$cache ) {
			$adapters = apply_filters( 'bizcity_crm_register_adapters', array() );

			$out = array();
			if ( is_array( $adapters ) ) {
				foreach ( $adapters as $code => $adapter ) {
					if ( $adapter instanceof BizCity_CRM_Channel_Adapter ) {
						$out[ $adapter->code() ] = $adapter;
					}
				}
			}
			self::$cache = $out;
		}
		return self::$cache;
	}

	public static function get( string $code ): ?BizCity_CRM_Channel_Adapter {
		$all = self::all();
		return $all[ $code ] ?? null;
	}

	/** Return framework descriptors for the loaded adapter catalog. */
	public static function contract_catalog(): array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-FRAMEWORK — make zone, identity, storage and TwinBrain ownership inspectable by Diagnostics/UI.
		$out = array();
		foreach ( self::all() as $code => $adapter ) {
			$descriptor = class_exists( 'BizCity_CRM_Channel_Contract' )
				? BizCity_CRM_Channel_Contract::describe( $code )
				: array( 'code' => $code, 'zone' => 'unknown' );
			$out[ $code ] = array_merge( $descriptor, array(
				'adapter_class' => get_class( $adapter ),
				'capabilities'  => (array) $adapter->capabilities(),
				'normalize'     => method_exists( $adapter, 'normalize_inbound' ),
				'send'          => method_exists( $adapter, 'send' ),
			) );
		}
		return $out;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}
}
