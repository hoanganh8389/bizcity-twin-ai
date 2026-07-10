<?php
/**
 * BizCity CRM — Customer Source registry.
 *
 * Thin accessor over the `bizcity_crm_register_customer_sources` filter.
 * Mirrors the channel-adapter registry pattern (class-channel-registry.php)
 * so 3rd-party plugins extend the Sales Pipeline by hooking one filter:
 *
 *   add_filter( 'bizcity_crm_register_customer_sources', function( $sources ) {
 *       $sources['my_crm'] = new My_CRM_Source();
 *       return $sources;
 *   } );
 *
 * @package BizCity_Twin_CRM
 * @since   1.16.0
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Customer_Source_Registry {

	/** @var BizCity_CRM_Customer_Source[]|null */
	private static $cache = null;

	/** @return BizCity_CRM_Customer_Source[] keyed by code */
	public static function all(): array {
		if ( null === self::$cache ) {
			$sources = apply_filters( 'bizcity_crm_register_customer_sources', array() );
			$out     = array();
			if ( is_array( $sources ) ) {
				foreach ( $sources as $src ) {
					if ( $src instanceof BizCity_CRM_Customer_Source ) {
						$out[ $src->code() ] = $src;
					}
				}
			}
			self::$cache = $out;
		}
		return self::$cache;
	}

	public static function get( string $code ): ?BizCity_CRM_Customer_Source {
		$all = self::all();
		return $all[ $code ] ?? null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}
}
