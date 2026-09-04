<?php
/**
 * Request-local registry for validated Phase 0.41 extension manifests.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Contracts
 * @since 2026-09-02 (PHASE-0.41-CRM-ONE-BRAIN)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Framework_Manifest_Registry' ) ) {
	final class BizCity_Framework_Manifest_Registry {

		const CONTRACT = 'extension-manifest';
		const VERSION  = '1.0.0';

		private static $extensions = array();
		private static $channels   = array();
		private static $issues     = array();

		public static function register( array $manifest, $extension = null ) {
			// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — validate and register manifests without storage or provider side effects.
			$errors = self::validate( $manifest );
			if ( ! empty( $errors ) ) {
				self::$issues[] = array(
					'extension_id' => isset( $manifest['extension_id'] ) ? (string) $manifest['extension_id'] : '',
					'code'        => 'manifest_invalid',
					'reasons'     => $errors,
				);
				return false;
			}

			$extension_id = (string) $manifest['extension_id'];
			$signature    = self::signature( $manifest );
			if ( isset( self::$extensions[ $extension_id ] ) ) {
				if ( self::$extensions[ $extension_id ]['signature'] !== $signature ) {
					self::$issues[] = array(
						'extension_id' => $extension_id,
						'code'        => 'extension_conflict',
						'reasons'     => array( 'extension_id already owns a different manifest' ),
					);
					return false;
				}
				return self::handle( $extension_id );
			}

			foreach ( (array) $manifest['channels'] as $channel ) {
				$slug = (string) $channel['slug'];
				if ( isset( self::$channels[ $slug ] ) && self::$channels[ $slug ] !== $extension_id ) {
					self::$issues[] = array(
						'extension_id' => $extension_id,
						'code'        => 'channel_slug_conflict',
						'reasons'     => array( 'channel slug is already owned by another extension' ),
					);
					return false;
				}
			}

			self::$extensions[ $extension_id ] = array(
				'manifest'  => $manifest,
				'extension' => is_object( $extension ) ? $extension : null,
				'signature' => $signature,
			);
			foreach ( (array) $manifest['channels'] as $channel ) {
				self::$channels[ (string) $channel['slug'] ] = $extension_id;
			}
			return self::handle( $extension_id );
		}

		public static function get( $extension_id ) {
			return self::handle( (string) $extension_id );
		}

		public static function channel( $slug ) {
			// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — expose exact manifest channel descriptors without allowing label or substring inference.
			$slug = (string) $slug;
			if ( ! isset( self::$channels[ $slug ] ) ) {
				return false;
			}
			$extension_id = self::$channels[ $slug ];
			if ( ! isset( self::$extensions[ $extension_id ]['manifest']['channels'] ) ) {
				return false;
			}
			foreach ( self::$extensions[ $extension_id ]['manifest']['channels'] as $channel ) {
				if ( is_array( $channel ) && $slug === (string) ( $channel['slug'] ?? '' ) ) {
					return array_merge(
						$channel,
						array(
							'extension_id'      => $extension_id,
							'extension_version' => (string) self::$extensions[ $extension_id ]['manifest']['extension_version'],
							'log_contract'      => 'channel-diagnostics-record@1.x',
						)
					);
				}
			}
			return false;
		}

		public static function channels(): array {
			$channels = array();
			foreach ( array_keys( self::$channels ) as $slug ) {
				$descriptor = self::channel( $slug );
				if ( is_array( $descriptor ) ) {
					$channels[ $slug ] = $descriptor;
				}
			}
			return $channels;
		}

		public static function all(): array {
			$all = array();
			foreach ( self::$extensions as $extension_id => $entry ) {
				$all[ $extension_id ] = $entry['manifest'];
			}
			return $all;
		}

		public static function issues(): array {
			return self::$issues;
		}

		public static function reset(): void {
			self::$extensions = array();
			self::$channels   = array();
			self::$issues     = array();
		}

		private static function handle( $extension_id ) {
			if ( ! isset( self::$extensions[ $extension_id ] ) ) {
				return false;
			}
			return new BizCity_Framework_Handle(
				self::$extensions[ $extension_id ]['manifest'],
				self::$extensions[ $extension_id ]['extension']
			);
		}

		private static function signature( array $manifest ): string {
			$copy = $manifest;
			ksort( $copy );
			return hash( 'sha256', (string) json_encode( $copy ) );
		}

		private static function validate( array $manifest ): array {
			$errors = array();
			if ( self::CONTRACT !== (string) ( $manifest['contract'] ?? '' ) ) {
				$errors[] = 'contract';
			}
			if ( ! self::is_semver( (string) ( $manifest['version'] ?? '' ) ) || '1.0.0' !== (string) $manifest['version'] ) {
				$errors[] = 'version';
			}
			if ( ! preg_match( '/^[a-z][a-z0-9._-]{2,63}$/', (string) ( $manifest['extension_id'] ?? '' ) ) ) {
				$errors[] = 'extension_id';
			}
			if ( ! self::is_semver( (string) ( $manifest['extension_version'] ?? '' ) ) ) {
				$errors[] = 'extension_version';
			}
			if ( ! self::is_framework_range( (string) ( $manifest['requires_framework'] ?? '' ) ) ) {
				$errors[] = 'requires_framework';
			}
			$capabilities = isset( $manifest['capabilities'] ) && is_array( $manifest['capabilities'] ) ? $manifest['capabilities'] : array();
			if ( empty( $capabilities ) ) {
				$errors[] = 'capabilities_empty';
			}
			foreach ( $capabilities as $capability ) {
				if ( ! in_array( (string) $capability, self::supported_capabilities(), true ) ) {
					$errors[] = 'unsupported_capability:' . (string) $capability;
				}
			}
			$channels = isset( $manifest['channels'] ) && is_array( $manifest['channels'] ) ? $manifest['channels'] : array();
			if ( empty( $channels ) ) {
				$errors[] = 'channels_empty';
			}
			$slugs = array();
			foreach ( $channels as $channel ) {
				if ( ! is_array( $channel ) ) {
					$errors[] = 'channel_not_object';
					continue;
				}
				$slug = (string) ( $channel['slug'] ?? '' );
				if ( ! preg_match( '/^[a-z][a-z0-9._-]{2,63}$/', $slug ) || in_array( $slug, $slugs, true ) ) {
					$errors[] = 'channel_slug';
				}
				$slugs[] = $slug;
				if ( ! in_array( (string) ( $channel['zone'] ?? '' ), array( 'customer', 'admin' ), true ) ) {
					$errors[] = 'channel_zone';
				}
				if ( ! in_array( (string) ( $channel['account_scope'] ?? '' ), array( 'single', 'multiple' ), true ) ) {
					$errors[] = 'account_scope';
				}
				if ( ! in_array( (string) ( $channel['crm_policy'] ?? '' ), array( 'enabled', 'disabled', 'not_applicable' ), true ) ) {
					$errors[] = 'crm_policy';
				}
				if ( 'customer' === (string) ( $channel['zone'] ?? '' ) && ! array_intersect( array( 'gpt_member', 'gpt_guest' ), (array) ( $channel['surface_policy'] ?? array() ) ) ) {
					$errors[] = 'customer_surface';
				}
				if ( 'enabled' === (string) ( $channel['crm_policy'] ?? '' ) && 'none' === (string) ( $channel['context_policy'] ?? '' ) ) {
					$errors[] = 'crm_context';
				}
			}
			$diagnostics = isset( $manifest['diagnostics'] ) && is_array( $manifest['diagnostics'] ) ? $manifest['diagnostics'] : array();
			$required_evidence = isset( $diagnostics['requires'] ) && is_array( $diagnostics['requires'] ) ? $diagnostics['requires'] : array();
			foreach ( array( 'disk', 'loader', 'runtime' ) as $evidence ) {
				if ( ! in_array( $evidence, $required_evidence, true ) ) {
					$errors[] = 'diagnostics_' . $evidence;
				}
			}
			return array_values( array_unique( $errors ) );
		}

		private static function supported_capabilities(): array {
			return array( 'channel.inbound', 'channel.outbound', 'action.notify', 'context.admit', 'commerce.order.read', 'fulfillment.read' );
		}

		private static function is_semver( string $version ): bool {
			return (bool) preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version );
		}

		private static function is_framework_range( string $range ): bool {
			return (bool) preg_match( '/^(?:(?:\^|~|>=|>|<=|<|=)?[0-9]+\.[0-9]+(?:\.[0-9]+|\.x)?)(?:\s+(?:(?:\^|~|>=|>|<=|<|=)?[0-9]+\.[0-9]+(?:\.[0-9]+|\.x)?))*$/', trim( $range ) );
		}
	}
}
