<?php
/**
 * Deterministic Context Bank KG candidate gate.
 *
 * This policy decides eligibility only. KG-Hub remains the sole semantic
 * extraction, passage and vector owner.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_KG_Candidate_Policy', false ) ) {
	return;
}

final class BizCity_Context_Bank_KG_Candidate_Policy {

	const VERSION = '1.0.0';

	/**
	 * Evaluate one metadata-only Context Bank record for KG promotion.
	 *
	 * @param array<string,mixed> $record Context Bank metadata.
	 * @param array<string,mixed> $context Server-owned evidence context.
	 * @return array<string,mixed>
	 */
	public static function evaluate( array $record, array $context = array() ) {
		// [2026-09-01 Johnny Chu] PHASE-CB6.2 — gate KG candidates deterministically before any KG-Hub call or semantic extraction.
		if ( empty( $context['authorized'] ) ) {
			return self::decision( false, 'authorization_denied' );
		}
		if ( (string) ( $record['source_contract_id'] ?? '' ) === '' ) {
			return self::decision( false, 'source_contract_missing' );
		}
		if ( (string) ( $record['lifecycle_status'] ?? 'active' ) !== 'active' ) {
			return self::decision( false, 'record_not_active' );
		}
		if ( ! empty( $record['superseded'] ) || (string) ( $record['kg_status'] ?? '' ) === 'superseded' ) {
			return self::decision( false, 'record_superseded' );
		}
		if ( ! empty( $record['pii_detected'] ) || ! empty( $context['pii_failed'] ) ) {
			return self::decision( false, 'pii_minimization_failed' );
		}
		if ( empty( $context['pointer_verified'] ) ) {
			return self::decision( false, 'evidence_unverified' );
		}
		$kind = sanitize_key( (string) ( $record['record_kind'] ?? '' ) );
		if ( in_array( $kind, array( 'event', 'memory', 'rule', 'relation' ), true ) ) {
			return self::decision( false, 'record_kind_not_candidate' );
		}
		if ( $kind !== 'rollup' ) {
			return self::decision( false, 'record_kind_invalid' );
		}
		if ( (string) ( $record['record_id'] ?? '' ) === '' || (string) ( $record['provenance_ref'] ?? '' ) === '' ) {
			return self::decision( false, 'provenance_missing' );
		}
		if ( (string) ( $record['rollup_version'] ?? '' ) === '' ) {
			return self::decision( false, 'rollup_version_missing' );
		}
		if ( empty( $record['evidence_refs'] ) || ! is_array( $record['evidence_refs'] ) ) {
			return self::decision( false, 'evidence_refs_missing' );
		}
		$confidence = isset( $record['confidence'] ) ? (float) $record['confidence'] : 0.0;
		$threshold = isset( $context['confidence_threshold'] ) ? (float) $context['confidence_threshold'] : 0.75;
		if ( $confidence < $threshold ) {
			return self::decision( false, 'confidence_below_threshold' );
		}
		if ( empty( $record['stable'] ) ) {
			return self::decision( false, 'rollup_not_stable' );
		}
		return self::decision( true, 'approved_summary_rollup' );
	}

	private static function decision( $candidate, $reason ) {
		// [2026-09-01 Johnny Chu] PHASE-CB6.2 — return one stable candidate verdict and owner marker for downstream KG-Hub handoff.
		return array( 'ok' => true, 'candidate' => (bool) $candidate, 'reason' => sanitize_key( (string) $reason ), 'policy_version' => self::VERSION, 'kg_owner' => 'kg-hub' );
	}
}