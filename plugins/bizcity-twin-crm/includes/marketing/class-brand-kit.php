<?php
/**
 * BizCity CRM — Brand Kit (PHASE 0.35 M6.W19).
 *
 * Persists site-wide branding metadata (logo, colors, font, hotline) used to
 * theme generated marketing assets. Stored in `wp_options` (single row) so
 * NO new DB table is needed; this respects the "rare migration" policy in
 * PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W19)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Brand_Kit {

	const OPTION_KEY = 'bizcity_crm_brand_kit';

	/** Default values used when admin has not configured the kit yet. */
	const DEFAULTS = array(
		'brand_name'      => '',
		'logo_url'        => '',
		'primary_color'   => '#2563eb',
		'secondary_color' => '#1e40af',
		'font_family'     => 'sans-serif',
		'hotline'         => '',
	);

	/**
	 * Read the current brand kit, merging stored values over defaults.
	 *
	 * @return array { brand_name, logo_url, primary_color, secondary_color, font_family, hotline }
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) { $stored = array(); }
		$out = array_merge( self::DEFAULTS, $stored );

		// Fall back to site defaults when admin left fields empty.
		if ( $out['brand_name'] === '' ) { $out['brand_name'] = (string) get_bloginfo( 'name' ); }
		if ( $out['logo_url']   === '' ) {
			$site_icon = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url() : '';
			$out['logo_url'] = $site_icon;
		}

		return $out;
	}

	/**
	 * Persist a (partial) update to the brand kit. Unknown keys are dropped.
	 * Emits `bizcity_crm_brand_kit_updated` so cache listeners can flush.
	 *
	 * @param array $patch
	 * @return array effective kit after update
	 */
	public static function update( array $patch ): array {
		$current = self::get();
		$next    = $current;
		foreach ( self::DEFAULTS as $k => $_def ) {
			if ( ! array_key_exists( $k, $patch ) ) { continue; }
			$v = $patch[ $k ];
			if ( $k === 'primary_color' || $k === 'secondary_color' ) {
				$v = self::sanitize_color( (string) $v ) ?: $current[ $k ];
			} elseif ( $k === 'logo_url' ) {
				$v = esc_url_raw( (string) $v );
			} else {
				$v = sanitize_text_field( (string) $v );
			}
			$next[ $k ] = $v;
		}
		update_option( self::OPTION_KEY, $next, false );

		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_brand_kit_updated', array(
				'changed_keys' => array_keys( $patch ),
				'hash'         => self::hash( $next ),
			) );
		}
		return $next;
	}

	/**
	 * Deterministic sha1 hash used to invalidate cached assets when the kit
	 * changes. Same kit → same hash → cached file reusable.
	 */
	public static function hash( ?array $kit = null ): string {
		$kit = $kit ?: self::get();
		ksort( $kit );
		return sha1( wp_json_encode( $kit ) );
	}

	/** Validate `#rrggbb` / `#rgb`. Returns normalized lower-case or empty on fail. */
	private static function sanitize_color( string $v ): string {
		$v = strtolower( trim( $v ) );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v ) ) { return $v; }
		return '';
	}
}
