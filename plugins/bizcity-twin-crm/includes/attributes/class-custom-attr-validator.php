<?php
/**
 * BizCity CRM — Custom Attribute Validator (PHASE 0.35 M3.W3).
 *
 * Validates a value against a definition row. Supported display_type:
 *   text · textarea · number · checkbox · date · list · link · regex
 *
 * Boundary R-PAR-9: REST writers (PUT /contacts/{id}) MUST run every
 * `additional_attributes[$key]` through here before persisting.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M3.W3
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Custom_Attribute_Validator {

	/**
	 * Validate ONE value against a definition row.
	 *
	 * @param array $def Definition row (from list_custom_attribute_defs()).
	 * @param mixed $value Value being saved.
	 * @return true|WP_Error
	 */
	public static function validate( array $def, $value ) {
		$type = (string) ( $def['display_type'] ?? 'text' );
		$key  = (string) ( $def['attribute_key'] ?? '?' );

		// Empty / null is allowed (use default if any) — let writer fall back.
		if ( $value === null || $value === '' ) {
			return true;
		}

		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'link':
				if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
					return self::err( $key, 'must_be_string' );
				}
				if ( $type === 'link' && ! wp_http_validate_url( (string) $value ) ) {
					return self::err( $key, 'invalid_url' );
				}
				return true;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return self::err( $key, 'must_be_number' );
				}
				return true;

			case 'checkbox':
				if ( ! is_bool( $value ) && ! in_array( $value, array( 0, 1, '0', '1', 'true', 'false', 'yes', 'no' ), true ) ) {
					return self::err( $key, 'must_be_boolean' );
				}
				return true;

			case 'date':
				$ts = strtotime( (string) $value );
				if ( $ts === false ) {
					return self::err( $key, 'invalid_date' );
				}
				return true;

			case 'list':
				$opts = self::decode_options( $def );
				if ( ! in_array( (string) $value, array_map( 'strval', $opts ), true ) ) {
					return self::err( $key, 'value_not_in_options' );
				}
				return true;

			case 'regex':
				$pattern = (string) ( $def['regex_pattern'] ?? '' );
				if ( $pattern === '' ) { return true; } // no constraint defined
				$delim = '/' . str_replace( '/', '\/', $pattern ) . '/u';
				if ( @preg_match( $delim, (string) $value ) !== 1 ) {
					return self::err( $key, 'regex_no_match' );
				}
				return true;
		}
		return self::err( $key, 'unknown_display_type:' . $type );
	}

	/**
	 * Cast a value to its canonical PHP type per definition (after validation).
	 */
	public static function coerce( array $def, $value ) {
		$type = (string) ( $def['display_type'] ?? 'text' );
		switch ( $type ) {
			case 'number':
				return is_numeric( $value ) ? ( strpos( (string) $value, '.' ) !== false ? (float) $value : (int) $value ) : $value;
			case 'checkbox':
				if ( is_bool( $value ) ) { return $value; }
				return in_array( $value, array( 1, '1', 'true', 'yes' ), true );
			case 'date':
				$ts = strtotime( (string) $value );
				return $ts ? gmdate( 'Y-m-d', $ts ) : $value;
			default:
				return is_string( $value ) ? trim( $value ) : $value;
		}
	}

	/**
	 * Validate a whole map of attributes against a target ('contact' or 'conversation').
	 *
	 * @param array  $attrs  key => value
	 * @param string $target
	 * @return array{ok:bool, errors:array<string,string>, coerced:array}
	 */
	public static function validate_bag( array $attrs, string $target = 'contact' ): array {
		$defs   = BizCity_CRM_Repository::list_custom_attribute_defs( array( 'target' => $target ) );
		$by_key = array();
		foreach ( $defs as $d ) { $by_key[ (string) $d['attribute_key'] ] = $d; }

		$errors  = array();
		$coerced = array();
		foreach ( $attrs as $k => $v ) {
			if ( ! isset( $by_key[ $k ] ) ) {
				// Permissive: unknown attrs pass through as-is (does not break legacy callers).
				$coerced[ $k ] = $v;
				continue;
			}
			$res = self::validate( $by_key[ $k ], $v );
			if ( is_wp_error( $res ) ) {
				$errors[ $k ] = $res->get_error_message();
				continue;
			}
			$coerced[ $k ] = self::coerce( $by_key[ $k ], $v );
		}
		return array(
			'ok'      => empty( $errors ),
			'errors'  => $errors,
			'coerced' => $coerced,
		);
	}

	private static function decode_options( array $def ): array {
		$raw = $def['options_json'] ?? '';
		if ( is_array( $raw ) ) { return $raw; }
		if ( ! is_string( $raw ) || $raw === '' ) { return array(); }
		$d = json_decode( $raw, true );
		return is_array( $d ) ? $d : array();
	}

	private static function err( string $key, string $code ): WP_Error {
		return new WP_Error( 'custom_attr_invalid', sprintf( '%s: %s', $key, $code ), array( 'attribute_key' => $key, 'reason' => $code ) );
	}
}
