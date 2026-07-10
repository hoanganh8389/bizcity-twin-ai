<?php
/**
 * BizCity CRM — CF7 Referral Tracker
 *
 * [2026-07-02 Johnny Chu] PHASE-0.47 W2 — inject bzref_tracker.js on ALL public pages
 * so every CF7 form (regardless of channel-gateway config) captures first-touch
 * UTM + referrer into cookie `_bzref`, then writes it to hidden input `_bzref_data`
 * on form submit. PHP reads `_bzref_data` from CF7 $posted and merges into
 * source_meta_json of the unified submission row.
 *
 * PHP also exposes sanitize() helper used by class-lead-source-cf7.php.
 *
 * @package BizCity\CRM\LeadCapture
 * @since   PHASE-0.47 W2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BizCity_CRM_CF7_Referral_Tracker {

	/** Option key used to enable/disable tracker (defaults enabled). */
	const OPTION_ENABLED = 'bizcity_crm_bzref_tracker_enabled';

	/** Allowed keys for cookie sanitisation — OWASP A03. */
	const ALLOWED_KEYS = array(
		'first_touch_url',
		'first_touch_at',
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_content',
		'utm_term',
		'referrer',
		'channel_detected',
		'session_id',
		'affiliate_code',
		'affiliate_member_id',
		'form_page_url',
		'form_submitted_at',
	);

	/** Valid channel_detected values. */
	const VALID_CHANNELS = array( 'zns', 'ladi', 'zalo', 'facebook', 'telegram', 'affiliate', 'direct', 'organic' );

	public static function register(): void {
		// Inject only on public (non-admin, non-REST) requests
		if ( is_admin() ) { return; }
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	public static function enqueue(): void {
		// Respect manual disable via option
		if ( get_option( self::OPTION_ENABLED, '1' ) === '0' ) { return; }
		$url = defined( 'BIZCITY_CRM_URL' ) ? BIZCITY_CRM_URL : plugins_url( '', dirname( __DIR__ ) . '/bizcity-twin-crm.php' );
		wp_enqueue_script(
			'bizcity-crm-bzref-tracker',
			trailingslashit( $url ) . 'assets/js/bzref_tracker.js',
			array(),
			defined( 'BIZCITY_CRM_VERSION' ) ? BIZCITY_CRM_VERSION : '1.0.0',
			true // defer — load in footer
		);
	}

	/**
	 * Sanitise raw `_bzref_data` JSON string from CF7 $posted.
	 * Returns a clean array with only whitelisted keys, or empty array on failure.
	 *
	 * OWASP A03 — never pass raw user input through without sanitisation.
	 *
	 * @param string $raw  Raw JSON string from $posted['_bzref_data'].
	 * @return array       Sanitised referral data, or [].
	 */
	public static function sanitize( string $raw ): array {
		if ( $raw === '' ) { return array(); }
		// Limit size — prevent oversized payloads
		if ( strlen( $raw ) > 4096 ) { return array(); }
		$decoded = json_decode( stripslashes( $raw ), true );
		if ( ! is_array( $decoded ) ) { return array(); }

		$out = array();
		foreach ( self::ALLOWED_KEYS as $k ) {
			if ( isset( $decoded[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( (string) $decoded[ $k ] );
			}
		}

		// Normalise channel_detected to known values only
		if ( isset( $out['channel_detected'] ) && ! in_array( $out['channel_detected'], self::VALID_CHANNELS, true ) ) {
			$out['channel_detected'] = 'organic';
		}

		// Strip any URL that doesn't start with http/https
		foreach ( array( 'first_touch_url', 'referrer', 'form_page_url' ) as $url_key ) {
			if ( isset( $out[ $url_key ] ) ) {
				$url_val = esc_url_raw( $out[ $url_key ] );
				$out[ $url_key ] = ( strpos( $url_val, 'http' ) === 0 ) ? $url_val : '';
			}
		}

		return $out;
	}
}
