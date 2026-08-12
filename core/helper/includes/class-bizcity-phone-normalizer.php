<?php
/**
 * Canonical Vietnamese phone normalizer shared by CRM, Woo and loyalty.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Phone_Normalizer' ) ) {
	return;
}

final class BizCity_Phone_Normalizer implements BizCity_Phone_Normalizer_Interface {

	/**
	 * Normalize a Vietnamese phone number to a leading-zero digit string.
	 *
	 * @param string $raw Raw phone value.
	 * @return string Empty when the value is not phone-like.
	 */
	public static function normalize_vn( string $raw ): string {
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — canonical VN phone identity implementation.
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( ! is_string( $digits ) || '' === $digits ) {
			return '';
		}

		if ( strlen( $digits ) >= 11 && substr( $digits, 0, 2 ) === '84' ) {
			$digits = '0' . substr( $digits, 2 );
		}

		if ( strlen( $digits ) < 9 || strlen( $digits ) > 11 || '0' !== $digits[0] ) {
			return '';
		}

		return $digits;
	}
}
