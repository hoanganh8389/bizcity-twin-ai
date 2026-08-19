<?php
/**
 * Deterministic state contract for one bounded HIL Instance.
 *
 * This class does not call LLMs or persist data. It normalizes slot-collection
 * state and guards lifecycle transitions before event-sourced persistence.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_HIL_State {

	const ACTIVE_STATUSES    = array( 'collecting', 'confirming' );
	const RESUMABLE_STATUSES = array( 'blocked' );
	const TERMINAL_STATUSES  = array( 'ready', 'expired', 'failed', 'cancelled' );

	const CLOSURE_READY     = 'all_required_slots_confirmed';
	const CLOSURE_TIMEOUT   = 'max_turns_exceeded';
	const CLOSURE_FAILED    = 'runtime_timeout';
	const CLOSURE_CANCELLED = 'user_cancelled';

	/**
	 * @return array<string,mixed>
	 */
	public static function normalize( array $state ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — normalize one bounded HIL Instance snapshot without persistence.
		$status = sanitize_key( (string) ( $state['status'] ?? 'collecting' ) );
		if ( ! self::is_status( $status ) ) {
			$status = 'collecting';
		}
		$slot_values = array();
		foreach ( (array) ( $state['slot_values'] ?? array() ) as $slot_id => $value ) {
			$slot_id = self::sanitize_slot_id( (string) $slot_id );
			if ( $slot_id === '' ) {
				continue;
			}
			$slot_values[ $slot_id ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
		return array(
			'hil_id'        => self::sanitize_id( (string) ( $state['hil_id'] ?? '' ), 'hil' ),
			'spec_id'       => sanitize_text_field( (string) ( $state['spec_id'] ?? '' ) ),
			'trigger_id'    => sanitize_text_field( (string) ( $state['trigger_id'] ?? '' ) ),
			'goal_id'       => sanitize_text_field( (string) ( $state['goal_id'] ?? '' ) ),
			'blog_id'       => max( 0, (int) ( $state['blog_id'] ?? get_current_blog_id() ) ),
			'identity_uuid' => strtolower( sanitize_text_field( (string) ( $state['identity_uuid'] ?? '' ) ) ),
			'session_id'    => sanitize_text_field( (string) ( $state['session_id'] ?? '' ) ),
			'slot_values'   => $slot_values,
			'pending_slot_id' => self::sanitize_slot_id( (string) ( $state['pending_slot_id'] ?? '' ) ),
			'media_candidate_id' => self::sanitize_slot_id( (string) ( $state['media_candidate_id'] ?? '' ) ),
			'media_candidate_confirmed' => ! empty( $state['media_candidate_confirmed'] ),
			'confirmed'     => ! empty( $state['confirmed'] ),
			'turn_count'    => max( 0, min( 1000, (int) ( $state['turn_count'] ?? 0 ) ) ),
			'status'        => $status,
			'closure_reason'=> sanitize_key( (string) ( $state['closure_reason'] ?? '' ) ),
			'expires_at'    => sanitize_text_field( (string) ( $state['expires_at'] ?? '' ) ),
			'updated_at'    => sanitize_text_field( (string) ( $state['updated_at'] ?? '' ) ),
		);
	}

	public static function can_transition( string $from, string $to ): bool {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — fail closed for unsupported or post-terminal transitions.
		$from = sanitize_key( $from );
		$to   = sanitize_key( $to );
		if ( ! self::is_status( $from ) || ! self::is_status( $to ) ) {
			return false;
		}
		if ( $from === $to ) {
			return true;
		}
		if ( in_array( $from, self::TERMINAL_STATUSES, true ) ) {
			return false;
		}
		$allowed = array(
			'collecting' => array( 'confirming', 'ready', 'blocked', 'expired', 'cancelled' ),
			'confirming' => array( 'collecting', 'ready', 'blocked', 'expired', 'cancelled' ),
			'blocked'    => array( 'collecting', 'confirming', 'ready', 'expired', 'cancelled' ),
		);
		return in_array( $to, $allowed[ $from ] ?? array(), true );
	}

	/**
	 * A side-effect node may run only when the instance is ready AND, if the
	 * spec requires a final confirmation, the user has explicitly confirmed.
	 */
	public static function is_side_effect_ready( array $state, array $spec ): bool {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — never let a side effect run before ready + confirmed when required.
		$state = self::normalize( $state );
		if ( $state['status'] !== 'ready' || self::next_pending_slot( $state, $spec ) !== '' ) {
			return false;
		}
		$requires_confirmation = ! empty( $spec['completion']['final_confirmation'] );
		$media_confirmed = true;
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			if ( ! empty( $slot['required'] ) && in_array( (string) ( $slot['type'] ?? '' ), array( 'image', 'file' ), true ) ) {
				$media_confirmed = ! empty( $state['media_candidate_id'] ) && ! empty( $state['media_candidate_confirmed'] );
				break;
			}
		}
		return ( ! $requires_confirmation || ! empty( $state['confirmed'] ) ) && $media_confirmed;
	}

	/**
	 * First unfilled required slot id in declared order, or '' when none remain.
	 */
	public static function next_pending_slot( array $state, array $spec ): string {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — bounded, deterministic slot ordering; no LLM re-ranking.
		$state = self::normalize( $state );
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			$slot_id = self::sanitize_slot_id( (string) ( $slot['id'] ?? '' ) );
			if ( $slot_id === '' || empty( $slot['required'] ) ) {
				continue;
			}
			if ( ! array_key_exists( $slot_id, $state['slot_values'] ) || trim( (string) $state['slot_values'][ $slot_id ] ) === '' ) {
				return $slot_id;
			}
		}
		return '';
	}

	public static function is_terminal( string $status ): bool {
		return in_array( sanitize_key( $status ), self::TERMINAL_STATUSES, true );
	}

	private static function is_status( string $status ): bool {
		return in_array( $status, array_merge( self::ACTIVE_STATUSES, self::RESUMABLE_STATUSES, self::TERMINAL_STATUSES ), true );
	}

	private static function sanitize_id( string $value, string $prefix ): string {
		$value = strtolower( trim( $value ) );
		if ( $value === '' ) {
			return '';
		}
		if ( strpos( $value, $prefix . '_' ) !== 0 ) {
			$value = $prefix . '_' . $value;
		}
		return substr( preg_replace( '/[^a-z0-9_-]+/', '_', $value ), 0, 120 );
	}

	private static function sanitize_slot_id( string $value ): string {
		return substr( preg_replace( '/[^a-z0-9_.-]+/i', '_', strtolower( trim( $value ) ) ), 0, 120 );
	}
}
