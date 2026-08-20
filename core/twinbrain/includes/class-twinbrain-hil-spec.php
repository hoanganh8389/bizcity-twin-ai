<?php
/**
 * TwinBrain V5 — Human-in-the-loop specification contract.
 *
 * Pure normalization and validation for a compiled HIL spec. It does not call
 * an LLM, persist state, or execute a workflow.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-15
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_HIL_Spec' ) ) {
	return;
}

final class BizCity_TwinBrain_HIL_Spec {

	const VERSION = 'twin_hil.v1';

	/** @var string[] */
	const SLOT_TYPES = array(
		'entity', 'text', 'integer', 'number', 'phone', 'address', 'url',
		'image', 'file', 'choice', 'boolean', 'date', 'datetime',
	);

	/** @var string[] */
	const CONFIRMATION_MODES = array( 'never', 'required', 'required_if_inferred' );

	/**
	 * Normalize a spec into the stable v1 shape used by the compiler/UI.
	 *
	 * @param array $spec Raw compiled specification.
	 * @return array
	 */
	public static function normalize( array $spec ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-SPEC — normalize one bounded HIL contract without persistence or provider calls.
		$slots = array();
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$slot_id = strtolower( trim( (string) ( $slot['id'] ?? '' ) ) );
			$slot_id = preg_replace( '/[^a-z0-9_.-]+/i', '_', $slot_id );
			$validation = is_array( $slot['validation'] ?? null ) ? $slot['validation'] : array();
			// [2026-08-20 Johnny Chu] HOTFIX-HIL-OPTIONAL-KEY — normalize once
			// so missing confirmation keys never reach a direct array read.
			$confirmation = (string) ( $slot['confirmation'] ?? 'never' );
			$slots[] = array(
				'id'               => $slot_id,
				'label'            => self::text( $slot['label'] ?? $slot_id, 120 ),
				'type'             => strtolower( trim( (string) ( $slot['type'] ?? 'text' ) ) ),
				'required'         => ! empty( $slot['required'] ),
				'ask'              => self::text( $slot['ask'] ?? '', 300 ),
				'sources'          => self::string_list( $slot['sources'] ?? array(), 8 ),
				'choices'          => self::choice_map( $slot['choices'] ?? array() ),
				'validation'       => $validation,
				'confirmation'     => in_array( $confirmation, self::CONFIRMATION_MODES, true )
					? $confirmation
					: 'never',
				'redact_in_trace'  => ! empty( $slot['redact_in_trace'] ),
			);
		}

		$completion = is_array( $spec['completion'] ?? null ) ? $spec['completion'] : array();
		$limits = is_array( $spec['limits'] ?? null ) ? $spec['limits'] : array();
		$notice_policy = is_array( $spec['notice_policy'] ?? null ) ? $spec['notice_policy'] : array();

		return array(
			'spec_version' => (string) ( $spec['spec_version'] ?? self::VERSION ),
			'spec_id'      => self::id( $spec['spec_id'] ?? '' ),
			'trigger_id'   => self::id( $spec['trigger_id'] ?? '' ),
			'intent_id'    => self::dotted_id( $spec['intent_id'] ?? 'unknown.clarify' ),
			'goal_scope'   => in_array( (string) ( $spec['goal_scope'] ?? 'goal_case' ), array( 'goal_case', 'goal_owner', 'identity_global' ), true )
				? (string) $spec['goal_scope']
				: 'goal_case',
			'purpose'      => self::text( $spec['purpose'] ?? '', 500 ),
			'slots'        => array_slice( $slots, 0, 50 ),
			'completion'   => array(
				'condition'         => self::text( $completion['condition'] ?? 'all_required_valid_and_confirmed', 120 ),
				'final_confirmation'=> ! empty( $completion['final_confirmation'] ),
				'side_effect_gate'  => self::text( $completion['side_effect_gate'] ?? 'block_until_ready', 80 ),
			),
			'limits'       => array(
				'max_turns'    => max( 1, min( 100, (int) ( $limits['max_turns'] ?? 8 ) ) ),
				'ttl_seconds'  => max( 60, min( 86400, (int) ( $limits['ttl_seconds'] ?? HOUR_IN_SECONDS ) ) ),
				'on_timeout'   => in_array( (string) ( $limits['on_timeout'] ?? 'pause' ), array( 'pause', 'expire', 'fail' ), true )
					? (string) $limits['on_timeout']
					: 'pause',
			),
			'notice_policy' => array(
				'slot_progress' => ! array_key_exists( 'slot_progress', $notice_policy ) || ! empty( $notice_policy['slot_progress'] ),
				'waiting_user'  => ! array_key_exists( 'waiting_user', $notice_policy ) || ! empty( $notice_policy['waiting_user'] ),
				'ready'         => ! array_key_exists( 'ready', $notice_policy ) || ! empty( $notice_policy['ready'] ),
				'failed'        => ! array_key_exists( 'failed', $notice_policy ) || ! empty( $notice_policy['failed'] ),
			),
		);
	}

	/**
	 * Validate a normalized or raw HIL spec.
	 *
	 * @param array $spec Raw or normalized specification.
	 * @return array{valid:bool,spec:array,errors:string[],warnings:string[]}
	 */
	public static function validate( array $spec ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-SPEC — fail closed on ambiguous slot contracts before workflow execution can be enabled.
		$normalized = self::normalize( $spec );
		$errors = array();
		$warnings = array();
		if ( $normalized['spec_version'] !== self::VERSION ) {
			$errors[] = 'spec_version_unsupported';
		}
		if ( $normalized['spec_id'] === '' ) {
			$errors[] = 'spec_id_missing';
		}
		if ( $normalized['trigger_id'] === '' ) {
			$errors[] = 'trigger_id_missing';
		}
		if ( $normalized['intent_id'] === '' ) {
			$errors[] = 'intent_id_missing';
		}
		if ( empty( $normalized['slots'] ) ) {
			$errors[] = 'slots_missing';
		}

		$seen = array();
		$required_count = 0;
		$confirmation_required = false;
		$media_required = false;
		foreach ( $normalized['slots'] as $index => $slot ) {
			$id = (string) $slot['id'];
			$prefix = 'slots.' . $index;
			if ( $id === '' ) {
				$errors[] = $prefix . '.id_missing';
			} elseif ( isset( $seen[ $id ] ) ) {
				$errors[] = $prefix . '.duplicate_id';
			} else {
				$seen[ $id ] = true;
			}
			if ( ! in_array( $slot['type'], self::SLOT_TYPES, true ) ) {
				$errors[] = $prefix . '.type_unsupported';
			}
			if ( $slot['required'] ) {
				$required_count++;
				if ( in_array( $slot['type'], array( 'image', 'file' ), true ) ) {
					$media_required = true;
				}
				if ( $slot['ask'] === '' ) {
					$errors[] = $prefix . '.ask_missing';
				}
			}
			if ( (string) ( $slot['confirmation'] ?? 'never' ) !== 'never' ) {
				$confirmation_required = true;
			}
			if ( $slot['type'] === 'choice' && empty( $slot['choices'] ) ) {
				$errors[] = $prefix . '.choices_missing';
			}
			if ( ! is_array( $slot['validation'] ) ) {
				$errors[] = $prefix . '.validation_invalid';
			}
		}

		$has_side_effect = (string) $normalized['completion']['side_effect_gate'] === 'block_until_ready';
		if ( $media_required && empty( $normalized['completion']['final_confirmation'] ) ) {
			$errors[] = 'media_confirmation_required';
		}
		if ( $has_side_effect && ( $required_count === 0 || empty( $normalized['completion']['final_confirmation'] ) ) ) {
			$errors[] = 'side_effect_confirmation_required';
		}
		if ( $normalized['limits']['on_timeout'] === 'fail' ) {
			$warnings[] = 'timeout_fail_requires_user_safe_error';
		}
		if ( $required_count > 20 ) {
			$warnings[] = 'many_required_slots';
		}
		return array(
			'valid' => empty( $errors ),
			'spec' => $normalized,
			'errors' => array_values( array_unique( $errors ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}

	private static function id( $value ): string {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-SPEC — normalize bounded identifier fields consistently.
		return substr( preg_replace( '/[^a-z0-9_.-]+/i', '_', strtolower( trim( (string) $value ) ) ), 0, 120 );
	}

	private static function dotted_id( $value ): string {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-SPEC — preserve dotted intent taxonomy identifiers.
		return substr( preg_replace( '/[^a-z0-9._-]+/i', '', strtolower( trim( (string) $value ) ) ), 0, 120 );
	}

	private static function text( $value, int $max ): string {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-SPEC — bound compiler text before it reaches storage or UI.
		$value = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $value ) ) );
		return mb_substr( $value, 0, $max, 'UTF-8' );
	}

	private static function string_list( $values, int $max ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-SPEC — normalize source references without unbounded arrays.
		$out = array();
		foreach ( (array) $values as $value ) {
			$value = self::id( $value );
			if ( $value !== '' ) { $out[] = $value; }
		}
		return array_values( array_unique( array_slice( $out, 0, $max ) ) );
	}

	private static function choice_map( $choices ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-HIL-SPEC — keep choice maps deterministic and capped.
		$out = array();
		foreach ( (array) $choices as $key => $value ) {
			$key = self::id( is_int( $key ) ? $value : $key );
			$value = self::text( $value, 120 );
			if ( $key !== '' && $value !== '' ) { $out[ $key ] = $value; }
		}
		return array_slice( $out, 0, 50, true );
	}
}