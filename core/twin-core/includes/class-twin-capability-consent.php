<?php
/**
 * Persistent capability consent registry and WordPress admin surface.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Capability_Consent' ) ) {
	final class BizCity_Twin_Capability_Consent {

		const OPTION_NAME = 'bizcity_twin_capability_grants';
		const PAGE_SLUG   = 'bizcity-twin-capability-consent';

		/** @var array<string,array<string,mixed>> */
		private static $manifests = array();

		/** @var bool */
		private static $booted = false;

		/** @var array<string,array<string,array<string,mixed>>>|null */
		private static $grants = null;

		/**
		 * Register the consent page hooks once.
		 *
		 * @return void
		 */
		public static function boot() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;
			if ( function_exists( 'is_admin' ) && is_admin() ) {
				add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
				add_action( 'admin_post_bizcity_twin_save_capability_consent', array( __CLASS__, 'save' ) );
			}
		}

		/**
		 * Register one extension manifest for consent review.
		 *
		 * @param array<string,mixed> $manifest
		 * @return bool
		 */
		public static function register_manifest( array $manifest ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — expose declared permissions to the consent boundary.
			$extension_id = isset( $manifest['id'] ) ? (string) $manifest['id'] : '';
			$permissions  = isset( $manifest['permissions'] ) && is_array( $manifest['permissions'] ) ? $manifest['permissions'] : array();
			if ( ! self::manifest_is_valid( $manifest, $extension_id, $permissions ) ) {
				return false;
			}
			$manifest['permissions'] = array_values( array_unique( array_map( 'strval', $permissions ) ) );
			self::$manifests[ $extension_id ] = $manifest;
			return true;
		}

		private static function manifest_is_valid( array $manifest, $extension_id, array $permissions ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — reject malformed runtime manifests before consent or execution.
			if ( '' === $extension_id || ! preg_match( '/^[a-z0-9][a-z0-9._-]{2,119}$/', $extension_id ) ) {
				return false;
			}
			if ( '1.0' !== (string) ( $manifest['schema_version'] ?? '' ) || ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ( $manifest['version'] ?? '' ) ) ) {
				return false;
			}
			foreach ( $permissions as $permission ) {
				if ( ! is_string( $permission ) || ! preg_match( '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,4}$/', $permission ) ) {
					return false;
				}
			}
			$sensitive = array(
				'content.publish'          => 'publish_content',
				'channel.zalo.send'        => 'send_message',
				'channel.telegram.send'    => 'send_message',
				'woocommerce.order.create' => 'create_order',
				'memory.delete'            => 'delete_data',
				'payment.execute'          => 'execute_payment',
			);
			$approval_gates = isset( $manifest['approval_gates'] ) && is_array( $manifest['approval_gates'] ) ? $manifest['approval_gates'] : array();
			foreach ( $permissions as $permission ) {
				if ( isset( $sensitive[ $permission ] ) && ! in_array( $sensitive[ $permission ], $approval_gates, true ) ) {
					return false;
				}
			}
			$security = isset( $manifest['security'] ) && is_array( $manifest['security'] ) ? $manifest['security'] : array();
			if ( isset( $security['secret_refs'] ) && is_array( $security['secret_refs'] ) ) {
				foreach ( $security['secret_refs'] as $secret_ref ) {
					if ( ! is_string( $secret_ref ) || ! preg_match( '/^[A-Z][A-Z0-9_]{2,64}$/', $secret_ref ) ) {
						return false;
					}
				}
			}
			return ! empty( $permissions );
		}

		/**
		 * @return array<string,array<string,mixed>>
		 */
		public static function manifests() {
			return self::$manifests;
		}

		public static function has_manifest( $extension_id ) {
			return isset( self::$manifests[ (string) $extension_id ] );
		}

			/**
			 * Return manifest-declared security policy for an extension.
			 *
			 * @param string $extension_id
			 * @return array<string,mixed>
			 */
			public static function security_for( $extension_id ) {
				$manifest = isset( self::$manifests[ (string) $extension_id ] ) ? self::$manifests[ (string) $extension_id ] : array();
				return isset( $manifest['security'] ) && is_array( $manifest['security'] ) ? $manifest['security'] : array();
			}

		/**
		 * Return consented permissions valid for the current context.
		 *
		 * @param string              $extension_id
		 * @param array<string,mixed> $context
		 * @return string[]
		 */
		public static function permissions_for( $extension_id, array $context = array() ) {
			$grants = self::all_grants();
			$items  = isset( $grants[ $extension_id ] ) && is_array( $grants[ $extension_id ] ) ? $grants[ $extension_id ] : array();
			$user_id = (int) ( $context['user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );
			$out = array();
			foreach ( $items as $permission => $grant ) {
				if ( ! is_array( $grant ) || ! empty( $grant['revoked_at'] ) ) {
					continue;
				}
				$scope_level = (string) ( $grant['scope_level'] ?? 'site' );
				if ( 'user' === $scope_level && (int) ( $grant['user_id'] ?? 0 ) !== $user_id ) {
					continue;
				}
				$out[] = (string) $permission;
			}
			return array_values( array_unique( $out ) );
		}

		/**
		 * @param string              $extension_id
		 * @param string              $permission
		 * @param array<string,mixed> $context
		 * @return bool
		 */
		public static function has( $extension_id, $permission, array $context = array() ) {
			return in_array( (string) $permission, self::permissions_for( (string) $extension_id, $context ), true );
		}

		/**
		 * Grant one manifest-declared permission.
		 *
		 * @param string $extension_id
		 * @param string $permission
		 * @param string $scope_level
		 * @param int    $user_id
		 * @return bool
		 */
		public static function grant( $extension_id, $permission, $scope_level = 'site', $user_id = 0 ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — fail closed when direct callers lack admin consent authority.
			if ( ! self::can_manage_consent() ) {
				return false;
			}
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — persist explicit admin consent per site.
			$manifest_permissions = isset( self::$manifests[ $extension_id ]['permissions'] ) ? self::$manifests[ $extension_id ]['permissions'] : array();
			if ( ! in_array( (string) $permission, $manifest_permissions, true ) || ! in_array( $scope_level, array( 'tenant', 'site', 'user' ), true ) ) {
				return false;
			}
			$grants = self::all_grants();
			if ( ! isset( $grants[ $extension_id ] ) || ! is_array( $grants[ $extension_id ] ) ) {
				$grants[ $extension_id ] = array();
			}
			$grants[ $extension_id ][ $permission ] = array(
				'scope_level' => (string) $scope_level,
				'user_id'     => 'user' === $scope_level ? (int) $user_id : 0,
				'granted_by'  => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'granted_at'  => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'c' ),
			);
			return self::save_grants( $grants );
		}

		/**
		 * @param string $extension_id
		 * @param string $permission
		 * @return bool
		 */
		public static function revoke( $extension_id, $permission ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — fail closed when direct callers lack admin consent authority.
			if ( ! self::can_manage_consent() ) {
				return false;
			}
			$grants = self::all_grants();
			if ( isset( $grants[ $extension_id ][ $permission ] ) ) {
				unset( $grants[ $extension_id ][ $permission ] );
				return self::save_grants( $grants );
			}
			return true;
		}

		public static function register_page() {
			add_management_page( 'Twin Capability Consent', 'Twin Permissions', 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
		}

		public static function render() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to manage Twin capabilities.', 'bizcity-twin-ai' ) );
			}
			$grants = self::all_grants();
			echo '<div class="wrap"><h1>' . esc_html__( 'Twin Permissions', 'bizcity-twin-ai' ) . '</h1>';
			echo '<p>' . esc_html__( 'Review the permissions requested by installed extensions. Saving this form replaces the selected grants for each extension.', 'bizcity-twin-ai' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="bizcity_twin_save_capability_consent">';
			wp_nonce_field( 'bizcity_twin_capability_consent' );
			foreach ( self::$manifests as $extension_id => $manifest ) {
				$granted = self::permissions_for( $extension_id, array( 'user_id' => get_current_user_id() ) );
				echo '<fieldset style="max-width:760px;margin:20px 0;padding:16px;border:1px solid #ccd0d4"><legend><strong>' . esc_html( (string) ( $manifest['name'] ?? $extension_id ) ) . '</strong> <code>' . esc_html( $extension_id ) . '</code></legend>';
				foreach ( $manifest['permissions'] as $permission ) {
					$scope_level = self::manifest_scope( $manifest, $permission );
					echo '<label style="display:block;margin:8px 0"><input type="checkbox" name="consent[' . esc_attr( $extension_id ) . '][]" value="' . esc_attr( $permission ) . '"' . checked( in_array( $permission, $granted, true ), true, false ) . '> ' . esc_html( $permission ) . ' <code>(' . esc_html( $scope_level ) . ')</code></label>';
				}
				echo '</fieldset>';
			}
			submit_button( __( 'Save consent', 'bizcity-twin-ai' ) );
			echo '</form></div>';
		}

		public static function save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to manage Twin capabilities.', 'bizcity-twin-ai' ) );
			}
			check_admin_referer( 'bizcity_twin_capability_consent' );
			$submitted = isset( $_POST['consent'] ) && is_array( $_POST['consent'] ) ? wp_unslash( $_POST['consent'] ) : array();
			foreach ( self::$manifests as $extension_id => $manifest ) {
				$selected = isset( $submitted[ $extension_id ] ) && is_array( $submitted[ $extension_id ] ) ? array_map( 'sanitize_text_field', $submitted[ $extension_id ] ) : array();
				foreach ( $manifest['permissions'] as $permission ) {
					if ( in_array( $permission, $selected, true ) ) {
						self::grant( $extension_id, $permission, self::manifest_scope( $manifest, $permission ), get_current_user_id() );
					} else {
						self::revoke( $extension_id, $permission );
					}
				}
			}
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => '1' ), admin_url( 'tools.php' ) ) );
			exit;
		}

		/** @return array<string,array<string,mixed>> */
		private static function all_grants() {
			if ( null !== self::$grants ) {
				return self::$grants;
			}
			$value = function_exists( 'get_option' ) ? get_option( self::OPTION_NAME, array() ) : array();
			self::$grants = is_array( $value ) ? $value : array();
			return self::$grants;
		}

		private static function save_grants( array $grants ) {
			self::$grants = $grants;
			return function_exists( 'update_option' ) ? (bool) update_option( self::OPTION_NAME, $grants, false ) : false;
		}

		private static function can_manage_consent() {
			return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		}

		private static function manifest_scope( array $manifest, $permission ) {
			$bindings = isset( $manifest['scope_bindings'] ) && is_array( $manifest['scope_bindings'] ) ? $manifest['scope_bindings'] : array();
			foreach ( $bindings as $binding ) {
				if ( is_array( $binding ) && (string) ( $binding['permission'] ?? '' ) === (string) $permission ) {
					return in_array( $binding['scope_level'] ?? '', array( 'tenant', 'site', 'user' ), true ) ? (string) $binding['scope_level'] : 'site';
				}
			}
			return 'site';
		}
	}
}