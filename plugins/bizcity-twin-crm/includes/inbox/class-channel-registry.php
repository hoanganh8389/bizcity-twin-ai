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

	/** @var array<string,BizCity_CRM_Channel_Adapter[]> */
	private static $cache = array();

	/** @return BizCity_CRM_Channel_Adapter[] keyed by code */
	public static function all(): array {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		global $wpdb;
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$cache_key = $blog_id . ':' . $database;
		if ( ! isset( self::$cache[ $cache_key ] ) ) {
			$adapters = apply_filters( 'bizcity_crm_register_adapters', array() );

			$out = array();
			if ( is_array( $adapters ) ) {
				foreach ( $adapters as $code => $adapter ) {
					if ( $adapter instanceof BizCity_CRM_Channel_Adapter ) {
						$registered_code = sanitize_key( (string) $code );
						$adapter_code = sanitize_key( (string) $adapter->code() );
						// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-CONTRACT - reject registry aliases that can silently relabel a channel across zones.
						if ( $registered_code === '' || $registered_code !== $adapter_code ) {
							continue;
						}
						$out[ $adapter_code ] = $adapter;
					}
				}
			}
			self::$cache[ $cache_key ] = $out;
		}
		return self::$cache[ $cache_key ];
	}

	public static function get( string $code ): ?BizCity_CRM_Channel_Adapter {
		$code = sanitize_key( $code );
		$all = self::all();
		return $all[ $code ] ?? null;
	}

	public static function adapter_for( string $code ): ?BizCity_CRM_Channel_Adapter {
		// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-CONTRACT - preserve the canonical adapter_for caller API through the same validated registry.
		return self::get( $code );
	}

	/** Return invalid filter registrations instead of hiding them in the catalog. */
	public static function registration_issues(): array {
		$issues = array();
		$adapters = apply_filters( 'bizcity_crm_register_adapters', array() );
		if ( ! is_array( $adapters ) ) {
			return array( 'adapter_filter_not_array' );
		}
		foreach ( $adapters as $code => $adapter ) {
			if ( ! $adapter instanceof BizCity_CRM_Channel_Adapter ) {
				$issues[] = sanitize_key( (string) $code ) . ':invalid_adapter';
				continue;
			}
			$registered_code = sanitize_key( (string) $code );
			$adapter_code = sanitize_key( (string) $adapter->code() );
			if ( $registered_code === '' || $registered_code !== $adapter_code ) {
				$issues[] = $registered_code . ':code_mismatch:' . $adapter_code;
			}
		}
		return $issues;
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
		self::$cache = array();
	}
}
