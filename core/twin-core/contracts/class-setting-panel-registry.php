<?php
/**
 * BizCity Twin AI - Setting Panel metadata registry.
 *
 * The registry owns normalized discovery metadata only. It does not register
 * WordPress menus, resolve renderers, query storage or make provider calls.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Contracts
 * @since 2026-09-13
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Setting_Panel_Registry' ) ) {

	final class BizCity_Setting_Panel_Registry {

		const CONTRACT = 'setting-panel-registration';
		const VERSION  = '1.0.0';

		private static $items = array();
		private static $errors = array();
		private static $renderer_owners = array();

		/** @var string[] */
		private static $destinations = array(
			'workspace',
			'settings',
			'control-panel',
			'channel-settings',
			'crm-inbox',
			'plugins-store',
		);

		/** @var string[] */
		private static $origins = array( 'core', 'module', 'bundle', 'extension', 'legacy_adapter' );

		/** @var string[] */
		private static $scopes = array( 'site', 'network', 'user', 'site_user' );

		/** @var string[] */
		private static $surfaces = array( 'admin_shell', 'admin_page', 'network_admin', 'external' );

		/**
		 * Register one native or adapted metadata item.
		 *
		 * @param array<string,mixed> $item
		 * @return bool
		 */
		public static function register_item( array $item ): bool {
			// [2026-09-13 09:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — register metadata without loading renderers or querying runtime state.
			$normalized = self::normalize( $item );
			if ( false === $normalized ) {
				return false;
			}

			$id = $normalized['id'];
			if ( isset( self::$items[ $id ] ) ) {
				self::$errors[] = 'duplicate_id:' . $id;
				return false;
			}

			$renderer_id = $normalized['renderer']['id'];
			if ( isset( self::$renderer_owners[ $renderer_id ] )
				&& 'legacy_adapter' !== $normalized['origin']
				&& 'legacy_adapter' !== self::$renderer_owners[ $renderer_id ]['origin'] ) {
				self::$errors[] = 'renderer_collision:' . $renderer_id;
				return false;
			}

			self::$items[ $id ] = $normalized;
			self::$renderer_owners[ $renderer_id ] = $normalized;
			return true;
		}

		/**
		 * Adapt one existing admin-navigation item without broadening its scope.
		 *
		 * @param array<string,mixed> $item
		 * @param string               $migration_owner
		 * @param string               $sunset_after
		 * @return bool
		 */
		public static function register_legacy_navigation( array $item, $migration_owner, $sunset_after ): bool {
			// [2026-09-13 09:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — adapt legacy navigation as explicit migration debt.
			$destination = self::destination_for_navigation( $item );
			$renderer_id = isset( $item['renderer'] ) ? (string) $item['renderer'] : '';
			if ( '' === $renderer_id ) {
				self::$errors[] = 'legacy_renderer_missing';
				return false;
			}

			$legacy = array(
				'contract'         => self::CONTRACT,
				'version'          => self::VERSION,
				'id'               => 'legacy.' . sanitize_key( (string) $item['id'] ),
				'owner'            => (string) $migration_owner,
				'origin'           => 'legacy_adapter',
				'destination'      => $destination,
				'group'            => isset( $item['slot'] ) ? sanitize_key( (string) $item['slot'] ) : 'legacy',
				'label_key'        => 'legacy.' . sanitize_key( (string) $item['id'] ) . '.label',
				'icon'             => isset( $item['icon'] ) ? (string) $item['icon'] : 'cil-puzzle',
				'capability'       => isset( $item['capability'] ) ? (string) $item['capability'] : 'read',
				'scope'            => isset( $item['scope'] ) ? (string) $item['scope'] : 'site',
				'surface'          => isset( $item['surface'] ) ? (string) $item['surface'] : 'admin_page',
				'renderer'         => array(
					'type'           => 'deep_link',
					'id'             => $renderer_id,
					'canonical_slug' => isset( $item['slug'] ) ? (string) $item['slug'] : '',
				),
				'availability'     => array( 'policy' => 'registered-owner' ),
				'position'         => isset( $item['position'] ) ? (int) $item['position'] : 9000,
				'aliases'          => isset( $item['aliases'] ) && is_array( $item['aliases'] ) ? $item['aliases'] : array(),
				'native_contract'  => false,
				'migration_owner'  => (string) $migration_owner,
				'sunset_after'     => (string) $sunset_after,
			);

			if ( 'channel-settings' === $destination && isset( $item['zone'] ) ) {
				$legacy['zone'] = (string) $item['zone'];
			}
			return self::register_item( $legacy );
		}

		/**
		 * Return normalized entries in deterministic display order.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function all(): array {
			// [2026-09-13 09:32 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — sort only in memory for deterministic registry output.
			$items = array_values( self::$items );
			usort( $items, static function ( $left, $right ) {
				$position_compare = (int) $left['position'] <=> (int) $right['position'];
				return 0 !== $position_compare ? $position_compare : strcmp( $left['id'], $right['id'] );
			} );
			return $items;
		}

		/**
		 * @return array<int,string>
		 */
		public static function errors(): array {
			return self::$errors;
		}

		/**
		 * @return array<string,mixed>|null
		 */
		public static function get( $id ) {
			// [2026-09-13 09:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — preserve contract dot-notation IDs during lookup.
			$id = strtolower( trim( (string) $id ) );
			if ( ! preg_match( '/^[a-z][a-z0-9._-]{2,100}$/', $id ) ) {
				return null;
			}
			return isset( self::$items[ $id ] ) ? self::$items[ $id ] : null;
		}

		/**
		 * @param array<string,mixed> $item
		 * @return array<string,mixed>|false
		 */
		private static function normalize( array $item ) {
			// [2026-09-13 09:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — enforce contract enums before admission.
			$required = array( 'id', 'owner', 'origin', 'destination', 'group', 'label_key', 'icon', 'capability', 'scope', 'surface', 'renderer', 'availability', 'position' );
			foreach ( $required as $key ) {
				if ( ! array_key_exists( $key, $item ) ) {
					self::$errors[] = 'missing:' . $key;
					return false;
				}
			}
			if ( isset( $item['contract'] ) && self::CONTRACT !== (string) $item['contract'] ) {
				self::$errors[] = 'contract_invalid';
				return false;
			}
			if ( ! is_string( $item['id'] ) || ! preg_match( '/^[a-z][a-z0-9._-]{2,100}$/', $item['id'] ) ) {
				self::$errors[] = 'id_invalid';
				return false;
			}
			if ( ! in_array( (string) $item['origin'], self::$origins, true ) || ! in_array( (string) $item['destination'], self::$destinations, true ) ) {
				self::$errors[] = 'enum_invalid:' . $item['id'];
				return false;
			}
			if ( ! in_array( (string) $item['scope'], self::$scopes, true ) || ! in_array( (string) $item['surface'], self::$surfaces, true ) ) {
				self::$errors[] = 'scope_or_surface_invalid:' . $item['id'];
				return false;
			}
			if ( ! is_int( $item['position'] ) || $item['position'] < 0 || $item['position'] > 9999 ) {
				self::$errors[] = 'position_invalid:' . $item['id'];
				return false;
			}
			if ( ! is_array( $item['renderer'] ) || empty( $item['renderer']['id'] ) || empty( $item['renderer']['type'] ) ) {
				self::$errors[] = 'renderer_invalid:' . $item['id'];
				return false;
			}
			if ( ! is_array( $item['availability'] ) || empty( $item['availability']['policy'] ) ) {
				self::$errors[] = 'availability_invalid:' . $item['id'];
				return false;
			}
			if ( 'external' === $item['renderer']['type'] ) {
				$url = isset( $item['renderer']['target_url'] ) ? (string) $item['renderer']['target_url'] : '';
				if ( 0 !== strpos( strtolower( $url ), 'https://' ) ) {
					self::$errors[] = 'external_url_invalid:' . $item['id'];
					return false;
				}
			}
			if ( 'channel-settings' === $item['destination'] && empty( $item['zone'] ) ) {
				self::$errors[] = 'channel_zone_missing:' . $item['id'];
				return false;
			}
			return $item;
		}

		private static function destination_for_navigation( array $item ): string {
			$slot = isset( $item['slot'] ) ? (string) $item['slot'] : '';
			if ( 0 === strpos( $slot, 'workspace.channels' ) ) {
				return 'channel-settings';
			}
			if ( 0 === strpos( $slot, 'workspace.crm' ) ) {
				return 'crm-inbox';
			}
			if ( 0 === strpos( $slot, 'workspace.extensions' ) ) {
				return 'plugins-store';
			}
			if ( 0 === strpos( $slot, 'workspace.' ) ) {
				return 'workspace';
			}
			if ( 0 === strpos( $slot, 'diagnostics.' ) ) {
				return 'settings';
			}
			return 'settings';
		}
	}
}
