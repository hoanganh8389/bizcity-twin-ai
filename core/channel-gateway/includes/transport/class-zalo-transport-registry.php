<?php
/**
 * Registry for Zalo Personal transports (LC-2).
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Transport_Registry', false ) ) {
	return;
}

final class BizCity_Zalo_Transport_Registry {

	/** @var array<string,BizCity_Zalo_Transport>|null */
	private static $registry = null;

	public static function boot(): void {
		if ( null !== self::$registry ) {
			return;
		}
		$default = array();
		if ( class_exists( 'BizCity_Zalo_Transport_Bridge_Legacy' ) ) {
			$default['zca']      = new BizCity_Zalo_Transport_Bridge_Legacy( 'zca' );
			$default['zalo_hub'] = new BizCity_Zalo_Transport_Bridge_Legacy( 'zalo_hub' );
		}
		$all = function_exists( 'apply_filters' ) ? apply_filters( 'bizcity_zalo_transports', $default ) : $default;
		self::$registry = array();
		foreach ( is_array( $all ) ? $all : array() as $id => $transport ) {
			if ( $transport instanceof BizCity_Zalo_Transport ) {
				self::$registry[ (string) $id ] = $transport;
			}
		}
	}

	public static function get( string $transport_id ): ?BizCity_Zalo_Transport {
		self::boot();
		return self::$registry[ $transport_id ] ?? null;
	}

	public static function for_account( string $bridge_account_id ): ?BizCity_Zalo_Transport {
		if ( '' === trim( $bridge_account_id ) || ! class_exists( 'BizCity_Zalo_Account_Flags' ) ) {
			return null;
		}
		$provider = (string) BizCity_Zalo_Account_Flags::provider( $bridge_account_id );
		return self::get( $provider );
	}

	public static function ids(): array {
		self::boot();
		return array_keys( self::$registry );
	}

	public static function reset(): void {
		self::$registry = null;
	}
}
