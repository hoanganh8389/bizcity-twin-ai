<?php
/**
 * BizCity Twin AI — Opt-in Admin Navigation Registry.
 *
 * Stores validated navigation metadata from module/plugin providers. WordPress
 * menu registration remains owned by the central admin-menu adapter during the
 * Phase 1.26 migration window.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Contracts
 * @since 2026-08-11 (PHASE-1.26-CONTRACT)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Admin_Navigation_Registry' ) ) {

	final class BizCity_Admin_Navigation_Registry {

		/** @var array<string,BizCity_Admin_Navigation_Provider_Interface> */
		private static $providers = array();

		/** @var array<string,array<string,mixed>> */
		private static $items = array();

		/** @var array<int,string> */
		private static $errors = array();

		/** @var array<string,array<int,string>> */
		private static $slots = array(
			'settings'    => array( 'settings.api', 'settings.chatbot', 'settings.templates', 'settings.sync', 'settings.integrations' ),
			'workspace'   => array( 'workspace.chat', 'workspace.profile', 'workspace.knowledge', 'workspace.crm', 'workspace.channels', 'workspace.automation', 'workspace.studio', 'workspace.account', 'workspace.extensions' ),
			'diagnostics' => array( 'diagnostics.runtime', 'diagnostics.logs', 'diagnostics.schema', 'diagnostics.probes', 'diagnostics.extensions' ),
		);

		/**
		 * Register one opt-in provider. Safe for file-scope provider declarations.
		 *
		 * @param mixed $provider
		 * @return bool
		 */
		public static function register_provider( $provider ): bool {
			if ( ! $provider instanceof BizCity_Admin_Navigation_Provider_Interface ) {
				self::$errors[] = 'provider_not_implemented';
				return false;
			}

			// [2026-08-12 Johnny Chu] PHASE-1.26-CONTRACT — preserve dot notation allowed by the public provider-id contract.
			$id = strtolower( trim( (string) $provider->navigation_id() ) );
			if ( '' === $id || ! preg_match( '/^[a-z][a-z0-9._-]{2,80}$/', $id ) ) {
				self::$errors[] = 'provider_id_missing';
				return false;
			}
			if ( isset( self::$providers[ $id ] ) ) {
				self::$errors[] = 'provider_duplicate:' . $id;
				return false;
			}

			$items = $provider->navigation_items();
			if ( ! is_array( $items ) ) {
				self::$errors[] = 'provider_items_invalid:' . $id;
				return false;
			}

			$validated = array();
			foreach ( $items as $item ) {
				$normalized = self::validate_item( $item );
				if ( false === $normalized ) {
					self::$errors[] = 'navigation_item_invalid:' . $id;
					return false;
				}
				$key = $normalized['parent'] . ':' . $normalized['slug'];
				if ( isset( self::$items[ $key ] ) || isset( $validated[ $key ] ) ) {
					self::$errors[] = 'navigation_duplicate:' . $key;
					return false;
				}
				$validated[ $key ] = $normalized;
			}

			self::$providers[ $id ] = $provider;
			foreach ( $validated as $key => $item ) {
				self::$items[ $key ] = $item;
			}
			return true;
		}

		/**
		 * Register one item for adapters that do not need a provider object yet.
		 *
		 * @param array<string,mixed> $item
		 * @return bool
		 */
		public static function register_item( array $item ): bool {
			$normalized = self::validate_item( $item );
			if ( false === $normalized ) {
				self::$errors[] = 'navigation_item_invalid';
				return false;
			}
			$key = $normalized['parent'] . ':' . $normalized['slug'];
			if ( isset( self::$items[ $key ] ) ) {
				self::$errors[] = 'navigation_duplicate:' . $key;
				return false;
			}
			self::$items[ $key ] = $normalized;
			return true;
		}

		/** @return array<string,array<string,mixed>> */
		public static function all(): array {
			return self::$items;
		}

		/** @return array<string,BizCity_Admin_Navigation_Provider_Interface> */
		public static function providers(): array {
			return self::$providers;
		}

		/** @return array<int,string> */
		public static function errors(): array {
			return self::$errors;
		}

		/** @return array<string,array<int,string>> */
		public static function slots(): array {
			return self::$slots;
		}

		/** @return string Default destination for a new bundle/extension item. */
		public static function default_extension_slot(): string {
			return 'workspace.extensions';
		}

		/**
		 * @param mixed $item
		 * @return array<string,mixed>|false
		 */
		private static function validate_item( $item ) {
			if ( ! is_array( $item ) ) {
				return false;
			}
			$required = array( 'id', 'slug', 'label', 'group', 'slot', 'parent', 'capability', 'position', 'scope', 'surface', 'renderer', 'visible', 'origin' );
			foreach ( $required as $key ) {
				if ( ! array_key_exists( $key, $item ) ) {
					return false;
				}
			}
			if ( ! in_array( (string) $item['group'], array( 'settings', 'workspace', 'diagnostics' ), true ) ) {
				return false;
			}
			$group = (string) $item['group'];
			$slot  = (string) $item['slot'];
			if ( ! in_array( $slot, self::$slots[ $group ], true ) ) {
				return false;
			}
			if ( ! in_array( (string) $item['scope'], array( 'site', 'network', 'both' ), true ) ) {
				return false;
			}
			if ( ! in_array( (string) $item['surface'], array( 'admin_shell', 'admin_page', 'diagnostics' ), true ) ) {
				return false;
			}
			if ( ! is_int( $item['position'] ) || $item['position'] < 0 ) {
				return false;
			}
			if ( ! is_bool( $item['visible'] ) ) {
				return false;
			}
			foreach ( array( 'id', 'slug', 'label', 'group', 'slot', 'parent', 'capability', 'renderer' ) as $key ) {
				if ( ! is_string( $item[ $key ] ) || '' === $item[ $key ] ) {
					return false;
				}
			}
			foreach ( array( 'aliases', 'legacy_parents' ) as $key ) {
				if ( isset( $item[ $key ] ) && ! is_array( $item[ $key ] ) ) {
					return false;
				}
			}
			if ( isset( $item['origin'] ) && ! in_array( (string) $item['origin'], array( 'core', 'bundle', 'extension' ), true ) ) {
				return false;
			}
			return $item;
		}
	}
}
