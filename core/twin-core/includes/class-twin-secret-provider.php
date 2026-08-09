<?php
/**
 * Central secret resolution boundary for Twin extensions and integrations.
 *
 * Secrets are resolved through an explicitly registered provider or a named
 * wp-config constant. Raw secret values are never written to audit evidence.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Secret_Provider' ) ) {
	final class BizCity_Twin_Secret_Provider {

		const RESOLVE_FILTER = 'bizcity_twin_resolve_secret';

		/**
		 * Resolve a manifest secret reference without exposing it to audit logs.
		 *
		 * @param string              $reference
		 * @param array<string,mixed> $context
		 * @return array<string,mixed>
		 */
		public static function resolve( $reference, array $context = array() ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — fail-closed secret boundary.
			$reference = (string) $reference;
			if ( ! preg_match( '/^[A-Z][A-Z0-9_]{2,127}$/', $reference ) ) {
				self::audit( 'secret_resolve_denied', $reference, $context, 'invalid_reference' );
				return self::denied( 'secret_reference_invalid' );
			}

			$value = null;
			if ( function_exists( 'apply_filters' ) ) {
				$value = apply_filters( self::RESOLVE_FILTER, null, $reference, $context );
			}
			if ( is_string( $value ) && '' !== $value ) {
				self::audit( 'secret_resolved', $reference, $context, 'provider' );
				return array(
					'ok'         => true,
					'value'      => $value,
					'source'     => 'provider',
					'expires_at' => time() + 300,
				);
			}

			if ( defined( $reference ) ) {
				$constant_value = constant( $reference );
				if ( is_string( $constant_value ) && '' !== $constant_value ) {
					self::audit( 'secret_resolved', $reference, $context, 'constant' );
					return array(
						'ok'         => true,
						'value'      => $constant_value,
						'source'     => 'constant',
						'expires_at' => time() + 300,
					);
				}
			}

			self::audit( 'secret_resolve_denied', $reference, $context, 'provider_unavailable' );
			return self::denied( 'secret_provider_unavailable' );
		}

		/**
		 * Decrypt a channel integration value through the central audit boundary.
		 *
		 * @param object              $integration
		 * @param string              $value
		 * @param array<string,mixed> $context
		 * @return string
		 */
		public static function decrypt_integration_value( $integration, $value, array $context = array() ) {
			if ( ! is_object( $integration ) || ! method_exists( $integration, 'decrypt_value' ) ) {
				return (string) $value;
			}
			$decrypted = (string) $integration->decrypt_value( (string) $value );
			self::audit( 'integration_secret_accessed', '', $context, 'encrypted_account' );
			return $decrypted;
		}

		private static function denied( $code ) {
			return array(
				'ok'      => false,
				'code'    => (string) $code,
				'message' => 'Secret provider is not available.',
			);
		}

		private static function audit( $event, $reference, array $context, $source ) {
			if ( ! class_exists( 'BizCity_Twin_Runtime_Audit' ) ) {
				return;
			}
			BizCity_Twin_Runtime_Audit::record( $event, array(
				'reference' => (string) $reference,
				'source'    => (string) $source,
				'trace_id'  => (string) ( $context['trace_id'] ?? '' ),
				'user_id'   => (int) ( $context['user_id'] ?? 0 ),
			) );
		}
	}
}
