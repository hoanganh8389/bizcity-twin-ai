<?php
/**
 * Metadata-only evidence envelope for governed business actions.
 *
 * Before/after payloads are hashed and never persisted by this helper. The
 * canonical business owner remains responsible for the actual mutation and
 * Context Bank order-lifecycle event.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-09-13 (PHASE-0.41-W8.6)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Action_Evidence', false ) ) {
	return;
}

final class BizCity_Twin_Action_Evidence {

	const CONTRACT = 'business-action-evidence';
	const VERSION  = '1.0.0';

	/**
	 * Build a metadata-only before/after envelope.
	 *
	 * @param array<string,mixed> $action Action correlation and resource data.
	 * @param mixed               $before Canonical owner state before mutation.
	 * @param mixed               $after Canonical owner state after mutation.
	 * @param array<string,mixed> $context Evidence metadata.
	 * @return array<string,mixed>
	 */
	public static function build( array $action, $before, $after, array $context = array() ): array {
		// [2026-09-13 01:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.6 — build redacted before/after action evidence without persisting business payloads.
		$before_hash = self::hash_state( $before );
		$after_hash  = self::hash_state( $after );
		$resource    = is_array( $action['resource'] ?? null ) ? $action['resource'] : array();

		return array(
			'contract'          => self::CONTRACT,
			'version'           => self::VERSION,
			'trace_id'          => self::clean_identifier( $action['trace_id'] ?? '' ),
			'idempotency_key'   => self::clean_identifier( $action['idempotency_key'] ?? '' ),
			'action'            => self::clean_identifier( $action['action'] ?? '' ),
			'resource_type'     => sanitize_key( (string) ( $resource['type'] ?? $action['resource_type'] ?? '' ) ),
			'resource_ref'      => self::clean_identifier( $resource['ref'] ?? $action['resource_ref'] ?? '' ),
			'owner'             => sanitize_key( (string) ( $context['owner'] ?? 'canonical_business_owner' ) ),
			'outcome'           => sanitize_key( (string) ( $context['outcome'] ?? 'pending' ) ),
			'before_state_hash' => $before_hash,
			'after_state_hash'  => $after_hash,
			'changed'           => $before_hash !== $after_hash,
			'order_event_type'  => sanitize_key( (string) ( $context['order_event_type'] ?? '' ) ),
			'context_status'    => sanitize_key( (string) ( $context['context_status'] ?? 'pending' ) ),
			'occurred_at'       => self::clean_identifier( $context['occurred_at'] ?? gmdate( 'c' ) ),
		);
	}

	/**
	 * Send an already-built envelope to the existing metadata-only audit sink.
	 *
	 * @param array<string,mixed> $evidence Evidence envelope from build().
	 * @return void
	 */
	public static function record( array $evidence ): void {
		// [2026-09-13 01:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.6 — reuse the existing runtime audit owner for action evidence.
		if ( ! class_exists( 'BizCity_Twin_Runtime_Audit' ) ) {
			return;
		}
		$metadata = array();
		foreach ( $evidence as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$metadata[ sanitize_key( (string) $key ) ] = $value;
			}
		}
		BizCity_Twin_Runtime_Audit::record( 'business_action_evidence', $metadata );
	}

	private static function hash_state( $state ): string {
		$normalized = self::normalize_state( $state );
		return hash( 'sha256', (string) wp_json_encode( $normalized ) );
	}

	private static function normalize_state( $state ) {
		if ( is_array( $state ) ) {
			if ( array_keys( $state ) !== range( 0, count( $state ) - 1 ) ) {
				ksort( $state );
			}
			foreach ( $state as $key => $value ) {
				$state[ $key ] = self::normalize_state( $value );
			}
			return $state;
		}
		if ( is_object( $state ) ) {
			return self::normalize_state( get_object_vars( $state ) );
		}
		if ( is_scalar( $state ) || null === $state ) {
			return $state;
		}
		return (string) $state;
	}

	private static function clean_identifier( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return substr( $value, 0, 160 );
	}
}
