<?php
/**
 * CF7 ZNS Config — CRUD cho global eSMS credentials và per-form ZNS template config.
 *
 * Global settings (toàn site):
 *   Option: bizcity_cg_esms_zns_settings
 *   Shape:  { api_key: string, secret_key: string (encrypted), oa_id: string }
 *
 * Per-form config:
 *   Option: bizcity_cg_cf7_zns_configs (array keyed by form_id)
 *   Shape:  { form_id, enabled, temp_id, oa_id, sandbox, campaign_id, temp_vars[], updated_at }
 *
 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-CF7-ZNS (2026-06-25)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CF7_ZNS_Config' ) ) {
	return;
}

class BizCity_CF7_ZNS_Config {

	const GLOBAL_OPTION = 'bizcity_cg_esms_zns_settings';
	const FORMS_OPTION  = 'bizcity_cg_cf7_zns_configs';

	// ── Global eSMS Settings ─────────────────────────────────────────────────

	/**
	 * Get global eSMS credentials (secret_key decrypted).
	 *
	 * @return array { api_key: string, secret_key: string, oa_id: string }
	 */
	public static function get_global_settings() {
		$raw = get_option( self::GLOBAL_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'api_key'    => (string) ( $raw['api_key'] ?? '' ),
			'secret_key' => self::decrypt( (string) ( $raw['secret_key_enc'] ?? '' ) ),
			'oa_id'      => (string) ( $raw['oa_id'] ?? '' ),
		);
	}

	/**
	 * Save global eSMS credentials.
	 * secret_key is encrypted before storing.
	 *
	 * @param  array $data { api_key, secret_key, oa_id }
	 */
	public static function save_global_settings( array $data ) {
		$current = get_option( self::GLOBAL_OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$api_key    = sanitize_text_field( (string) ( $data['api_key'] ?? '' ) );
		$secret_key = sanitize_text_field( (string) ( $data['secret_key'] ?? '' ) );
		$oa_id      = sanitize_text_field( (string) ( $data['oa_id'] ?? '' ) );

		// Preserve encrypted secret if caller passes placeholder '***'
		$secret_key_enc = ( $secret_key && $secret_key !== '***' )
			? self::encrypt( $secret_key )
			: (string) ( $current['secret_key_enc'] ?? '' );

		update_option( self::GLOBAL_OPTION, array(
			'api_key'        => $api_key,
			'secret_key_enc' => $secret_key_enc,
			'oa_id'          => $oa_id,
			'updated_at'     => current_time( 'c' ),
		), false );
	}

	/**
	 * Check if global settings are fully configured.
	 *
	 * @return bool
	 */
	public static function is_globally_configured() {
		$s = get_option( self::GLOBAL_OPTION, array() );
		return ! empty( $s['api_key'] ) && ! empty( $s['secret_key_enc'] ) && ! empty( $s['oa_id'] );
	}

	/**
	 * Return global settings for REST response — secret_key masked.
	 *
	 * @return array
	 */
	public static function get_global_settings_safe() {
		$s = get_option( self::GLOBAL_OPTION, array() );
		return array(
			'api_key'        => (string) ( $s['api_key'] ?? '' ),
			'secret_key'     => ! empty( $s['secret_key_enc'] ) ? '***' : '',
			'oa_id'          => (string) ( $s['oa_id'] ?? '' ),
			'is_configured'  => self::is_globally_configured(),
			'updated_at'     => (string) ( $s['updated_at'] ?? '' ),
		);
	}

	// ── Per-form ZNS Config ──────────────────────────────────────────────────

	/**
	 * Get ZNS config for a single form.
	 *
	 * @param  int $form_id
	 * @return array
	 */
	public static function get_form_config( $form_id ) {
		$all = get_option( self::FORMS_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$key = (string) (int) $form_id;
		if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) {
			return array_merge( self::default_form_config( (int) $form_id ), $all[ $key ] );
		}
		return self::default_form_config( (int) $form_id );
	}

	/**
	 * Save ZNS config for a single form.
	 *
	 * @param  int   $form_id
	 * @param  array $data
	 */
	public static function save_form_config( $form_id, array $data ) {
		$all = get_option( self::FORMS_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$key          = (string) (int) $form_id;
		$temp_vars    = self::sanitize_temp_vars( $data['temp_vars'] ?? array() );
		$campaign_id  = substr( sanitize_text_field( (string) ( $data['campaign_id'] ?? '' ) ), 0, 254 );
		$oa_id        = sanitize_text_field( (string) ( $data['oa_id'] ?? '' ) );
		$temp_id      = sanitize_text_field( (string) ( $data['temp_id'] ?? '' ) );

		$all[ $key ] = array(
			'form_id'     => (int) $form_id,
			'enabled'     => ! empty( $data['enabled'] ),
			'temp_id'     => $temp_id,
			'oa_id'       => $oa_id,
			'sandbox'     => ! empty( $data['sandbox'] ),
			'campaign_id' => $campaign_id,
			'temp_vars'   => $temp_vars,
			'updated_at'  => current_time( 'c' ),
		);

		update_option( self::FORMS_OPTION, $all, false );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Default form config skeleton.
	 */
	private static function default_form_config( $form_id ) {
		return array(
			'form_id'     => (int) $form_id,
			'enabled'     => false,
			'temp_id'     => '',
			'oa_id'       => '',
			'sandbox'     => false,
			'campaign_id' => '',
			'temp_vars'   => array(),
			'updated_at'  => '',
		);
	}

	/**
	 * Sanitise temp_vars array.
	 * Each element: { var_name: string, source: 'mapped'|'literal', field?: string, value?: string }
	 *
	 * @param  mixed $raw
	 * @return array
	 */
	public static function sanitize_temp_vars( $raw ) {
		if ( ! is_array( $raw ) ) {
			error_log( '[bizcity-zns-config] sanitize_temp_vars: raw is not array, type=' . gettype( $raw ) );
			return array();
		}
		error_log( '[bizcity-zns-config] sanitize_temp_vars: raw count=' . count( $raw ) . ' raw=' . wp_json_encode( $raw ) );
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				error_log( '[bizcity-zns-config] sanitize_temp_vars: item not array type=' . gettype( $item ) );
				continue;
			}
			$var_name = sanitize_text_field( (string) ( $item['var_name'] ?? '' ) );
			error_log( '[bizcity-zns-config] sanitize_temp_vars: item var_name=' . $var_name . ' source=' . ( $item['source'] ?? 'n/a' ) );
			if ( empty( $var_name ) ) {
				continue;
			}
			$source = in_array( $item['source'] ?? '', array( 'mapped', 'literal' ), true )
				? (string) $item['source']
				: 'mapped';

			$entry = array(
				'var_name' => $var_name,
				'source'   => $source,
			);
			if ( $source === 'mapped' ) {
				// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — accept 'mapped_field' as alias for 'field'
				// MUST use !empty() not ?? — FE sends field='' (empty string, not null), so ?? never falls through.
				// Priority: field (if non-empty) → mapped_field (if non-empty) → ''
				$field_raw = (string) ( ! empty( $item['field'] ) ? $item['field'] : ( ! empty( $item['mapped_field'] ) ? $item['mapped_field'] : '' ) );
				$entry['mapped_field'] = sanitize_text_field( $field_raw );
				$entry['field']        = $entry['mapped_field']; // keep both for compat
			} else {
				// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS FIX — FE sends 'literal_value', not 'value'
				// Accept both keys for compat. Store as 'literal_value' to match FE state key.
				$lit_val = ! empty( $item['literal_value'] ) ? $item['literal_value'] : ( $item['value'] ?? '' );
				$entry['literal_value'] = sanitize_text_field( (string) $lit_val );
				$entry['value']         = $entry['literal_value']; // keep alias for sender compat
			}
			$out[] = $entry;
		}
		return $out;
	}

	// ── Encryption helpers (OWASP A02) ────────────────────────────────────────

	/**
	 * Encrypt a string using AUTH_KEY as encryption key.
	 * Returns base64-encoded "iv:ciphertext" or empty string on failure.
	 *
	 * @param  string $plain
	 * @return string
	 */
	private static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) || empty( $plain ) ) {
			return $plain; // fallback: store as-is if openssl unavailable
		}
		$key = substr( hash( 'sha256', AUTH_KEY, true ), 0, 32 );
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $enc ) {
			return '';
		}
		return base64_encode( $iv . $enc );
	}

	/**
	 * Decrypt a string encrypted by self::encrypt().
	 *
	 * @param  string $enc_b64
	 * @return string
	 */
	private static function decrypt( $enc_b64 ) {
		if ( ! function_exists( 'openssl_decrypt' ) || empty( $enc_b64 ) ) {
			return $enc_b64;
		}
		$raw = base64_decode( $enc_b64, true );
		if ( ! $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$key    = substr( hash( 'sha256', AUTH_KEY, true ), 0, 32 );
		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return ( false === $plain ) ? '' : $plain;
	}
}
