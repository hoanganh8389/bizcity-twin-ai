<?php
/**
 * Opt-in registry for public content-level extension contracts.
 *
 * Legacy array providers remain supported. New extensions may register typed
 * providers through bizcity_twin_register_extension_capabilities.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Content_Registry' ) ) {
	final class BizCity_Twin_Content_Registry {

		/** @var array<string,array<string,object>> */
		private static $providers = array();

		/** @var bool */
		private static $loaded = false;

		/**
		 * Load extension providers once.
		 *
		 * @return void
		 */
		public static function boot() {
			if ( self::$loaded ) {
				return;
			}
			self::$loaded = true;
			$groups = apply_filters( 'bizcity_twin_register_extension_capabilities', array() );
			if ( ! is_array( $groups ) ) {
				return;
			}
			foreach ( $groups as $kind => $providers ) {
				if ( ! is_array( $providers ) ) {
					continue;
				}
				foreach ( $providers as $provider ) {
					self::register( $kind, $provider );
				}
			}
		}

		/**
		 * Register a typed provider after interface validation.
		 *
		 * @param string  $kind
		 * @param object  $provider
		 * @return bool
		 */
		public static function register( $kind, $provider ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SDK — typed content provider discovery.
			if ( ! is_object( $provider ) || ! self::matches_contract( $kind, $provider ) ) {
				return false;
			}
			$id = self::provider_id( $kind, $provider );
			if ( '' === $id ) {
				return false;
			}
			if ( ! isset( self::$providers[ $kind ] ) ) {
				self::$providers[ $kind ] = array();
			}
			self::$providers[ $kind ][ $id ] = $provider;
			return true;
		}

		/**
		 * @param string $kind
		 * @return array<string,object>
		 */
		public static function all( $kind ) {
			self::boot();
			return isset( self::$providers[ $kind ] ) ? self::$providers[ $kind ] : array();
		}

		/**
		 * @param string $kind
		 * @param string $id
		 * @return object|null
		 */
		public static function get( $kind, $id ) {
			$providers = self::all( $kind );
			return isset( $providers[ $id ] ) ? $providers[ $id ] : null;
		}

		private static function provider_id( $kind, $provider ) {
			$method = 'id';
			if ( 'workflow_blocks' === $kind ) {
				$method = 'node_id';
			}
			return method_exists( $provider, $method ) ? (string) $provider->{$method}() : '';
		}

		private static function matches_contract( $kind, $provider ) {
			$interfaces = array(
				'tools'              => 'BizCity_Tool_Interface',
				'agents'             => 'BizCity_Agent_Interface',
				'skills'             => 'BizCity_Skill_Interface',
				'channels'           => 'BizCity_Channel_Adapter_Interface',
				'kg_source_adapters' => 'BizCity_KG_Source_Adapter_Interface',
				'workflow_blocks'    => 'BizCity_Workflow_Block_Interface',
				'personas'           => 'BizCity_Persona_Provider_Interface',
				'output_renderers'   => 'BizCity_Output_Renderer_Interface',
			);
			return isset( $interfaces[ $kind ] ) && interface_exists( $interfaces[ $kind ] ) && $provider instanceof $interfaces[ $kind ];
		}
	}
}
