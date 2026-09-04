<?php
/**
 * Public event/source declarations for Twin Plugin SDK extensions.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Event_Stream
 * @since 1.3.8
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Event_Registry' ) ) {
	final class BizCity_Event_Registry {

		/** @var array<string,array<string,mixed>> */
		private static $events = array();

		/** @var array<string,array<string,mixed>> */
		private static $sources = array();

		/** @var array<int,string> */
		private static $errors = array();

		/**
		 * Register an existing canonical event for an extension.
		 *
		 * New event types still require the R-EVT-2 taxonomy/schema process; this
		 * registry only records declarations that pass the existing whitelist.
		 *
		 * @param string               $event_type
		 * @param array<string,mixed>  $definition
		 * @return bool
		 */
		public static function register_event( $event_type, array $definition = array() ): bool {
			$event_type = strtolower( trim( (string) $event_type ) );
			if ( '' === $event_type || ! class_exists( 'BizCity_Twin_Event_Taxonomy' ) || ! in_array( $event_type, BizCity_Twin_Event_Taxonomy::all(), true ) ) {
				self::$errors[] = 'event_not_whitelisted:' . $event_type;
				return false;
			}

			$source = isset( $definition['source'] ) ? strtolower( trim( (string) $definition['source'] ) ) : 'tool';
			if ( ! in_array( $source, BizCity_Twin_Event_Taxonomy::allowed_sources(), true ) ) {
				self::$errors[] = 'event_source_not_allowed:' . $source;
				return false;
			}
			if ( isset( self::$events[ $event_type ] ) ) {
				self::$errors[] = 'event_duplicate:' . $event_type;
				return false;
			}

			self::$events[ $event_type ] = array(
				'event_type'  => $event_type,
				'source'      => $source,
				'owner'       => isset( $definition['owner'] ) ? (string) $definition['owner'] : '',
				'description' => isset( $definition['description'] ) ? (string) $definition['description'] : '',
			);
			return true;
		}

		/**
		 * Register an extension source and its canonical event declarations.
		 *
		 * @param string              $source_id
		 * @param array<string,mixed> $definition
		 * @return bool
		 */
		public static function register_source( $source_id, array $definition = array() ): bool {
			$source_id = strtolower( trim( (string) $source_id ) );
			if ( '' === $source_id || ! preg_match( '/^[a-z][a-z0-9._-]{2,80}$/', $source_id ) ) {
				self::$errors[] = 'source_id_invalid';
				return false;
			}
			if ( isset( self::$sources[ $source_id ] ) ) {
				self::$errors[] = 'source_duplicate:' . $source_id;
				return false;
			}

			$event_types = isset( $definition['events'] ) && is_array( $definition['events'] ) ? $definition['events'] : array();
			foreach ( $event_types as $event_type ) {
				if ( ! class_exists( 'BizCity_Twin_Event_Taxonomy' ) || ! in_array( (string) $event_type, BizCity_Twin_Event_Taxonomy::all(), true ) ) {
					self::$errors[] = 'source_event_not_whitelisted:' . (string) $event_type;
					return false;
				}
			}

			self::$sources[ $source_id ] = array(
				'source_id'  => $source_id,
				'owner'      => isset( $definition['owner'] ) ? (string) $definition['owner'] : '',
				'events'     => array_values( array_map( 'strval', $event_types ) ),
				'description'=> isset( $definition['description'] ) ? (string) $definition['description'] : '',
			);
			return true;
		}

		/** @return array<string,array<string,mixed>> */
		public static function events(): array {
			return self::$events;
		}

		/** @return array<string,array<string,mixed>> */
		public static function sources(): array {
			return self::$sources;
		}

		/** @return array<int,string> */
		public static function errors(): array {
			return self::$errors;
		}
	}
}