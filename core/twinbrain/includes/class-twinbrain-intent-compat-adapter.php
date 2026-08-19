<?php
/**
 * Deterministic Intent compatibility adapter for TwinBrain MPR V5 migration.
 *
 * This bridge exposes a stable, provider-free compatibility envelope for legacy
 * Slot Analysis / Clarify Gate / Confirm Analyzer / Memory Spec consumers while
 * keeping Goal Loop as the only source of truth.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-19
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Intent_Compat_Adapter', false ) ) {
	return;
}

final class BizCity_TwinBrain_Intent_Compat_Adapter {
	const VERSION = 'twin_intent_compat.v1';

	/**
	 * Build one deterministic compatibility envelope from the Prompt Intent.
	 *
	 * @param array  $prompt_intent Canonical Prompt Intent (MPR V5 triage output).
	 * @param string $message       Current user message.
	 * @param array  $context       Optional Goal/Session context.
	 * @return array<string,mixed>
	 */
	public static function build_from_prompt_intent( array $prompt_intent, string $message = '', array $context = array() ): array {
		// [2026-08-19 Johnny Chu] MPR-V5.10-COMPAT — derive deterministic compatibility fields without calling any provider or writing state.
		$slot_analysis = self::slot_analysis_from_prompt_intent( $prompt_intent );
		$clarify_gate = self::clarify_gate_from_prompt_intent( $prompt_intent, $message );
		$confirm = self::confirm_intent_from_message( $message );
		$memory_spec = self::memory_spec_from_context( $context );

		return array(
			'compat_version'   => self::VERSION,
			'intent_id'        => (string) ( $prompt_intent['intent_id'] ?? 'unknown.clarify' ),
			'route'            => (string) ( $prompt_intent['route'] ?? 'mpr' ),
			'slot_analysis'    => $slot_analysis,
			'clarify_gate'     => $clarify_gate,
			'confirm_analyzer' => $confirm,
			'memory_spec'      => $memory_spec,
		);
	}

	private static function slot_analysis_from_prompt_intent( array $prompt_intent ): array {
		$entities = is_array( $prompt_intent['entities'] ?? null ) ? (array) $prompt_intent['entities'] : array();
		$required_inputs = is_array( $prompt_intent['required_inputs'] ?? null ) ? (array) $prompt_intent['required_inputs'] : array();
		$filled_slots = array();
		foreach ( $entities as $key => $value ) {
			if ( ! is_string( $key ) || $key === '' ) {
				continue;
			}
			if ( strpos( $key, '_' ) === 0 ) {
				continue;
			}
			if ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					$filled_slots[] = $key;
				}
				continue;
			}
			if ( trim( (string) $value ) !== '' ) {
				$filled_slots[] = $key;
			}
		}
		$filled_slots = array_values( array_unique( $filled_slots ) );

		$normalized_required = array();
		foreach ( $required_inputs as $required ) {
			if ( is_array( $required ) ) {
				$required = (string) ( $required['id'] ?? '' );
			}
			$required = sanitize_key( (string) $required );
			if ( $required !== '' ) {
				$normalized_required[] = $required;
			}
		}
		$normalized_required = array_values( array_unique( $normalized_required ) );

		$missing = array();
		foreach ( $normalized_required as $required_key ) {
			if ( ! in_array( $required_key, $filled_slots, true ) ) {
				$missing[] = $required_key;
			}
		}

		$status = 'complete';
		if ( ! empty( $missing ) && ! empty( $filled_slots ) ) {
			$status = 'partial';
		} elseif ( ! empty( $missing ) ) {
			$status = 'empty';
		}

		return array(
			'filled_slots'   => $filled_slots,
			'missing_slots'  => array_values( $missing ),
			'filled_count'   => count( $filled_slots ),
			'missing_count'  => count( $missing ),
			'total_required' => count( $normalized_required ),
			'status'         => $status,
		);
	}

	private static function clarify_gate_from_prompt_intent( array $prompt_intent, string $message ): array {
		$route = sanitize_key( (string) ( $prompt_intent['route'] ?? 'mpr' ) );
		$interaction_mode = sanitize_key( (string) ( $prompt_intent['interaction_mode'] ?? 'clarify' ) );
		$confidence = (float) ( $prompt_intent['confidence'] ?? 0.0 );
		$requires_goal = ! empty( $prompt_intent['requires_goal'] );
		$trimmed = trim( $message );

		$should_clarify = false;
		$reason = 'clear';
		if ( $route === 'ambiguous' ) {
			$should_clarify = true;
			$reason = 'route_ambiguous';
		} elseif ( $interaction_mode === 'clarify' && $requires_goal ) {
			$should_clarify = true;
			$reason = 'goal_requires_clarify';
		} elseif ( $confidence < 0.55 && $trimmed !== '' ) {
			$should_clarify = true;
			$reason = 'low_confidence';
		}

		return array(
			'should_clarify' => $should_clarify,
			'reason'         => $reason,
			'score'          => round( max( 0.0, min( 1.0, $confidence ) ), 2 ),
		);
	}

	private static function confirm_intent_from_message( string $message ): array {
		$normalized = trim( $message );
		if ( function_exists( 'remove_accents' ) ) {
			$normalized = remove_accents( $normalized );
		}
		if ( function_exists( 'mb_strtolower' ) ) {
			$normalized = mb_strtolower( $normalized, 'UTF-8' );
		} else {
			$normalized = strtolower( $normalized );
		}
		$normalized = trim( preg_replace( '/\s+/u', ' ', $normalized ) );

		if ( $normalized === '' ) {
			return array( 'intent' => 'modify', 'method' => 'compat_regex', 'slot_updates' => array() );
		}
		if ( preg_match( '/\b(huy|huy bo|thoi|dung|cancel|stop|khong lam)\b/u', $normalized ) ) {
			return array( 'intent' => 'reject', 'method' => 'compat_regex', 'slot_updates' => array() );
		}
		if ( preg_match( '/\b(chuyen sang|de tai khac|lam cai khac|new goal)\b/u', $normalized ) ) {
			return array( 'intent' => 'new_goal', 'method' => 'compat_regex', 'slot_updates' => array() );
		}
		$has_accept = preg_match( '/\b(ok|oke|dong y|duoc|xac nhan|confirm|tiep tuc|yes)\b/u', $normalized ) === 1;
		$has_modify = preg_match( '/\b(sua|doi|them|bo sung|chinh|cap nhat)\b/u', $normalized ) === 1;
		if ( $has_accept && $has_modify ) {
			return array( 'intent' => 'accept_modify', 'method' => 'compat_regex', 'slot_updates' => array() );
		}
		if ( $has_accept ) {
			return array( 'intent' => 'accept', 'method' => 'compat_regex', 'slot_updates' => array() );
		}
		if ( $has_modify ) {
			return array( 'intent' => 'modify', 'method' => 'compat_regex', 'slot_updates' => array() );
		}
		return array( 'intent' => 'modify', 'method' => 'compat_regex', 'slot_updates' => array() );
	}

	private static function memory_spec_from_context( array $context ): array {
		$goal_state = is_array( $context['goal_loop_state'] ?? null ) ? (array) $context['goal_loop_state'] : array();
		$open_loops = array();
		foreach ( (array) ( $goal_state['open_loops'] ?? array() ) as $loop ) {
			$label = trim( (string) $loop );
			if ( $label !== '' ) {
				$open_loops[] = $label;
			}
		}
		$open_loops = array_slice( array_values( array_unique( $open_loops ) ), 0, 5 );

		$required_inputs = array();
		foreach ( (array) ( $goal_state['required_inputs'] ?? array() ) as $required ) {
			if ( is_array( $required ) ) {
				$required = (string) ( $required['id'] ?? '' );
			}
			$required = sanitize_key( (string) $required );
			if ( $required !== '' ) {
				$required_inputs[] = $required;
			}
		}
		$required_inputs = array_values( array_unique( $required_inputs ) );

		return array(
			'scope'        => (string) ( $context['memory_scope'] ?? $goal_state['memory_scope'] ?? 'session' ),
			'session_id'   => (string) ( $context['session_id'] ?? $goal_state['session_id'] ?? '' ),
			'focus'        => (string) ( $goal_state['primary_goal'] ?? '' ),
			'open_loops'   => $open_loops,
			'next_actions' => array_slice( $required_inputs, 0, 5 ),
			'pipeline_ref' => (string) ( $goal_state['goal_id'] ?? '' ),
		);
	}
}
