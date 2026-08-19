<?php
/**
 * Deterministic resolver for media candidates carried by an inbound HIL turn.
 *
 * Candidates are derived from the canonical attachments[] payload. This class
 * does not persist selection state, fetch providers, or expose signed tokens.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Media_Candidate_Resolver' ) ) {
	return;
}

final class BizCity_TwinBrain_Media_Candidate_Resolver {

	/**
	 * Resolve safe candidates for one identity/session/message boundary.
	 *
	 * @param array $attachments Canonical attachments[] entries.
	 * @param array $context     identity_uuid, session_id, message_id, user_id, chat_id.
	 * @return array
	 */
	public static function resolve( array $attachments, array $context = array() ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-MEDIA — derive candidates with identity/session ownership checks and no provider call.
		$candidates = array();
		$context_identity = trim( (string) ( $context['identity_uuid'] ?? '' ) );
		$context_session = trim( (string) ( $context['session_id'] ?? '' ) );
		$context_message = trim( (string) ( $context['message_id'] ?? '' ) );
		$context_user = (int) ( $context['user_id'] ?? $context['wp_user_id'] ?? 0 );
		$context_chat = trim( (string) ( $context['chat_id'] ?? '' ) );
		foreach ( $attachments as $index => $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$attachment_id = trim( (string) ( $attachment['attachment_id'] ?? $attachment['id'] ?? 'att_' . ( $index + 1 ) ) );
			$kind = sanitize_key( (string) ( $attachment['kind'] ?? $attachment['type'] ?? '' ) );
			$message_id = trim( (string) ( $attachment['message_id'] ?? $attachment['mid'] ?? '' ) );
			$status = sanitize_key( (string) ( $attachment['status'] ?? 'available' ) );
			if ( ! in_array( $status, array( 'available', 'unsupported', 'expired', 'rejected' ), true ) ) {
				$status = 'rejected';
			}
			$ownership = self::ownership( $attachment, $context );
			if ( $ownership !== 'owned' ) {
				$status = 'rejected';
			}
			$kind_ok = in_array( $kind, array( 'image', 'file', 'document', 'video', 'audio' ), true );
			if ( ! $kind_ok && $status === 'available' ) {
				$status = 'unsupported';
			}
			$candidates[] = array(
				'attachment_id'  => sanitize_key( $attachment_id ),
				'message_id'     => sanitize_key( $message_id ),
				'kind'           => $kind !== '' ? $kind : 'unknown',
				'ownership_scope' => $ownership,
				'status'         => $status,
				'selected'       => false,
				'confirmed'      => false,
				'context_match'  => ( $context_message === '' || $message_id === '' || $message_id === $context_message )
					&& ( $context_chat === '' || (string) ( $attachment['chat_id'] ?? $context_chat ) === $context_chat ),
			);
			if ( $context_identity !== '' && isset( $attachment['identity_uuid'] ) && (string) $attachment['identity_uuid'] !== $context_identity ) {
				$candidates[ count( $candidates ) - 1 ]['status'] = 'rejected';
				$candidates[ count( $candidates ) - 1 ]['ownership_scope'] = 'foreign_identity';
			}
			if ( $context_session !== '' && isset( $attachment['session_id'] ) && (string) $attachment['session_id'] !== $context_session ) {
				$candidates[ count( $candidates ) - 1 ]['status'] = 'rejected';
				$candidates[ count( $candidates ) - 1 ]['ownership_scope'] = 'foreign_session';
			}
			if ( $context_user > 0 && isset( $attachment['wp_user_id'] ) && (int) $attachment['wp_user_id'] !== $context_user ) {
				$candidates[ count( $candidates ) - 1 ]['status'] = 'rejected';
				$candidates[ count( $candidates ) - 1 ]['ownership_scope'] = 'foreign_user';
			}
		}
		return $candidates;
	}

	/**
	 * Mark a candidate selected only after explicit confirmation.
	 *
	 * @param array  $candidates Resolver output.
	 * @param string $attachment_id Candidate id.
	 * @param bool   $confirmed Explicit user confirmation.
	 * @return array
	 */
	public static function select( array $candidates, string $attachment_id, bool $confirmed = false ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-MEDIA — selection never implies consent; confirmation is a separate required transition.
		$selected = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$is_match = (string) ( $candidate['attachment_id'] ?? '' ) === $attachment_id;
			if ( $is_match && (string) ( $candidate['status'] ?? '' ) === 'available' && ! empty( $candidate['context_match'] ) ) {
				$candidate['selected'] = true;
				$candidate['confirmed'] = $confirmed;
			}
			$selected[] = $candidate;
		}
		return $selected;
	}

	private static function ownership( array $attachment, array $context ): string {
		if ( strtolower( (string) ( $attachment['chat_kind'] ?? $context['chat_kind'] ?? 'private' ) ) === 'group' ) {
			return 'group_denied';
		}
		if ( ! empty( $attachment['ownership_scope'] ) ) {
			return sanitize_key( (string) $attachment['ownership_scope'] );
		}
		return 'owned';
	}
}
