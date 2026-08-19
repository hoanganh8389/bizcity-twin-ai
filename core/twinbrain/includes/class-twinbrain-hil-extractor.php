<?php
/**
 * Deterministic, non-LLM slot value extraction for HIL Instance turns.
 *
 * Pure functions only — no provider calls, no persistence. LLM-assisted
 * extraction is a future improvement; this class covers the bounded types
 * that can be parsed reliably with regex/lookup rules.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_HIL_Extractor {

	const MAX_TEXT_LEN = 500;

	/**
	 * @param string $type Slot type from BizCity_TwinBrain_HIL_Spec::SLOT_TYPES.
	 * @param string $reply Raw user reply for the current turn.
	 * @param array  $slot  Normalized slot definition (for choices, etc).
	 * @return string|null Extracted value, or null when the reply cannot satisfy the slot.
	 */
	public static function extract( string $type, string $reply, array $slot = array() ) {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — bounded deterministic extraction; unknown/ambiguous input fails closed to null (reask).
		$reply = trim( wp_strip_all_tags( $reply ) );
		if ( $reply === '' ) {
			return null;
		}
		$value = null;
		switch ( $type ) {
			case 'phone':
				$value = self::extract_phone( $reply );
				break;
			case 'integer':
				$value = self::extract_integer( $reply );
				break;
			case 'number':
				$value = self::extract_number( $reply );
				break;
			case 'boolean':
				$value = self::extract_boolean( $reply );
				break;
			case 'choice':
				$value = self::extract_choice( $reply, (array) ( $slot['choices'] ?? array() ) );
				break;
			case 'url':
				$value = self::extract_url( $reply );
				break;
			case 'date':
			case 'datetime':
				$value = self::extract_date( $reply );
				break;
			case 'text':
			case 'address':
			case 'entity':
			case 'image':
			case 'file':
			default:
				$value = mb_substr( $reply, 0, self::MAX_TEXT_LEN, 'UTF-8' );
				break;
		}
		return $value !== null && self::passes_validation( $value, $slot ) ? $value : null;
	}

	private static function passes_validation( string $value, array $slot ): bool {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — enforce compiled slot constraints before a value becomes durable state.
		$rules = is_array( $slot['validation'] ?? null ) ? $slot['validation'] : array();
		$length = mb_strlen( $value, 'UTF-8' );
		if ( isset( $rules['min_length'] ) && $length < max( 0, (int) $rules['min_length'] ) ) {
			return false;
		}
		if ( isset( $rules['max_length'] ) && $length > max( 0, (int) $rules['max_length'] ) ) {
			return false;
		}
		if ( isset( $rules['min'] ) && is_numeric( $value ) && (float) $value < (float) $rules['min'] ) {
			return false;
		}
		if ( isset( $rules['max'] ) && is_numeric( $value ) && (float) $value > (float) $rules['max'] ) {
			return false;
		}
		return true;
	}

	private static function extract_phone( string $reply ) {
		$digits = preg_replace( '/[^0-9]/', '', $reply );
		return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 12 ) ? $digits : null;
	}

	private static function extract_integer( string $reply ) {
		return preg_match( '/-?\d+/', $reply, $m ) ? $m[0] : null;
	}

	private static function extract_number( string $reply ) {
		return preg_match( '/-?\d+(\.\d+)?/', $reply, $m ) ? $m[0] : null;
	}

	private static function extract_boolean( string $reply ) {
		$normalized = strtolower( trim( $reply ) );
		$yes = array( 'có', 'co', 'yes', 'ok', 'đồng ý', 'dong y', 'đúng', 'dung', 'xác nhận', 'xac nhan' );
		$no  = array( 'không', 'khong', 'no', 'huỷ', 'huy', 'hủy', 'sai' );
		foreach ( $yes as $word ) {
			if ( $normalized === $word || strpos( $normalized, $word ) === 0 ) {
				return '1';
			}
		}
		foreach ( $no as $word ) {
			if ( $normalized === $word || strpos( $normalized, $word ) === 0 ) {
				return '0';
			}
		}
		return null;
	}

	private static function extract_choice( string $reply, array $choices ) {
		$normalized = mb_strtolower( trim( $reply ), 'UTF-8' );
		foreach ( $choices as $key => $label ) {
			$key_norm   = mb_strtolower( (string) $key, 'UTF-8' );
			$label_norm = mb_strtolower( (string) $label, 'UTF-8' );
			if ( $normalized === $key_norm || $normalized === $label_norm
				|| ( $label_norm !== '' && strpos( $normalized, $label_norm ) !== false )
				|| ( $key_norm !== '' && strpos( $normalized, $key_norm ) !== false )
			) {
				return (string) $key;
			}
		}
		return null;
	}

	private static function extract_url( string $reply ) {
		return filter_var( $reply, FILTER_VALIDATE_URL ) ? $reply : null;
	}

	private static function extract_date( string $reply ) {
		if ( preg_match( '/\b(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})\b/', $reply, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
		}
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — no confident date pattern; fall back to raw bounded text so a human/LLM step can review later.
		return mb_substr( $reply, 0, self::MAX_TEXT_LEN, 'UTF-8' );
	}
}
