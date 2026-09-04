<?php
/**
 * Public seven-verb facade for Twin Plugin SDK extensions.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Contracts
 * @since 1.3.8
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Plugin_SDK' ) ) {
	final class BizCity_Twin_Plugin_SDK {

		public static function register_plugin( $module ): bool {
			return self::append_filter( 'bizcity_register_module', $module );
		}

		public static function register_tool( $tool ): bool {
			return self::append_filter( 'bizcity_twin_register_tool', $tool );
		}

		public static function register_skill( $skill ): bool {
			return self::append_filter( 'bizcity_twin_register_skill', $skill );
		}

		public static function register_source( $source ): bool {
			return self::append_filter( 'bizcity_twin_register_source', $source );
		}

		public static function register_event( $event_type, array $definition = array() ): bool {
			return class_exists( 'BizCity_Event_Registry' ) && BizCity_Event_Registry::register_event( $event_type, $definition );
		}

		public static function register_diagnostic( $probe ): bool {
			return self::append_filter( 'bizcity_diagnostics_register_probes', $probe );
		}

		public static function register_ui( array $definition ): bool {
			$registered = false;
			if ( isset( $definition['navigation'] ) && is_array( $definition['navigation'] ) && class_exists( 'BizCity_Admin_Navigation_Registry' ) ) {
				$registered = BizCity_Admin_Navigation_Registry::register_item( $definition['navigation'] ) || $registered;
			}
			if ( isset( $definition['output_renderer'] ) ) {
				$registered = self::append_filter( 'bizcity_twin_register_extension_capabilities', $definition['output_renderer'], 'output_renderers' ) || $registered;
			}
			return $registered;
		}

		/**
		 * @param string $filter
		 * @param mixed  $value
		 * @param string $group
		 * @return bool
		 */
		private static function append_filter( $filter, $value, $group = '' ): bool {
			if ( ! function_exists( 'add_filter' ) ) {
				return false;
			}
			add_filter( $filter, function ( $items ) use ( $value, $group ) {
				if ( 'output_renderers' === $group ) {
					if ( ! is_array( $items ) ) {
						$items = array();
					}
					if ( ! isset( $items[ $group ] ) || ! is_array( $items[ $group ] ) ) {
						$items[ $group ] = array();
					}
					$items[ $group ][] = $value;
					return $items;
				}
				if ( ! is_array( $items ) ) {
					$items = array();
				}
				$items[] = $value;
				return $items;
			}, 20, 1 );
			return true;
		}
	}
}