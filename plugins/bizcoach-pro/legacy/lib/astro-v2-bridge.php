<?php
/**
 * BizCoach Pro — Astro V2 Bridge (Sprint H — 2026-05-16)
 *
 * Replaces the legacy `bccm_astro_fetch_full_chart()` / `bccm_vedic_fetch_full_chart()`
 * paths that hit `json.freeastrologyapi.com` (the old / heavily rate-limited host)
 * with the new `api.freeastroapi.com` v1/v2 providers exposed by the
 * BizCity LLM Router (`Astro_Provider_FAA_Western` / `Astro_Provider_FAA_Vedic`).
 *
 * Why: legacy admin "Generate chart" buttons were burning the 30/day quota of
 * the deprecated upstream and routinely returning HTTP 429. The new providers
 * have an 80/day quota, richer V2 normalized envelope, and ride the
 * BizCity_Astro_HTTP_Client breaker/backoff.
 *
 * Strategy: produce the SAME chart_data shape that
 * `bccm_astro_save_chart()` / `bccm_vedic_save_chart()` already consume
 * (`planets`, `houses`, `aspects`, `parsed`, `chart_url`, `birth_data`,
 * `fetched_at`), embedding BOTH the V2 keys (sign_en/norm_degree/full_degree/
 * house_number/is_retro) AND the legacy FAA keys (sign/normDegree/fullDegree/
 * house/isRetro) so the existing renderer in admin-user-profiles.php works
 * unchanged.
 *
 * @package BizCoach_Pro
 * @since   1.x — Sprint H
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( '_bcpro_astro_v2_dbg' ) ) :
/**
 * Append a tagged line to `wp-content/uploads/bcr-astro-debug.log` so we can
 * observe enrichment without depending on `WP_DEBUG_LOG`.
 */
function _bcpro_astro_v2_dbg( string $tag, $payload = '' ): void {
	$ud   = wp_upload_dir();
	$file = trailingslashit( $ud['basedir'] ) . 'bcr-astro-debug.log';
	$ts   = gmdate( 'Y-m-d H:i:s' );
	$body = is_scalar( $payload ) ? (string) $payload : wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
	if ( is_string( $body ) && strlen( $body ) > 2000 ) {
		$body = substr( $body, 0, 2000 ) . '…(+' . ( strlen( $body ) - 2000 ) . ' bytes)';
	}
	@file_put_contents( $file, "[$ts] [$tag] $body\n", FILE_APPEND | LOCK_EX );
}
endif;

if ( ! function_exists( '_bcpro_astro_v2_normalize_sign' ) ) :
/**
 * Upstream FAA sometimes returns 3-letter zodiac codes ("Cap", "Can", "Aqu")
 * instead of full English names. Map them to canonical full names so the
 * renderer's `bccm_zodiac_signs()` lookup (which keys on full name) succeeds.
 */
function _bcpro_astro_v2_normalize_sign( string $raw ): string {
	$raw = trim( $raw );
	if ( $raw === '' ) return '';
	$key = strtolower( substr( $raw, 0, 3 ) );
	static $map = array(
		'ari' => 'Aries',  'tau' => 'Taurus',   'gem' => 'Gemini',
		'can' => 'Cancer', 'leo' => 'Leo',      'vir' => 'Virgo',
		'lib' => 'Libra',  'sco' => 'Scorpio',  'sag' => 'Sagittarius',
		'cap' => 'Capricorn', 'aqu' => 'Aquarius', 'pis' => 'Pisces',
	);
	return $map[ $key ] ?? ucfirst( strtolower( $raw ) );
}
endif;

if ( ! function_exists( 'bcpro_astro_v2_available' ) ) :
/**
 * @return bool True if the FAA V2 western/vedic provider classes are loaded
 *              AND have an API key configured.
 */
function bcpro_astro_v2_available( string $system = 'western' ): bool {
	// [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — check faa2_western for western natal (MANDATORY)
	if ( $system === 'vedic' ) {
		$class = 'Astro_Provider_FAA_Vedic';
		$id    = 'faa_vedic';
	} else {
		// faa2_western is the MANDATORY natal provider (freeastrologyapi.com)
		$class = 'Astro_Provider_FAA2_Western';
		$id    = 'faa2_western';
	}
	if ( ! class_exists( $class ) || ! class_exists( 'BizCity_Astro_Router' ) ) {
		return false;
	}
	$p = BizCity_Astro_Router::get_provider( $id );
	return $p && method_exists( $p, 'is_ready' ) && $p->is_ready();
}
endif;

if ( ! function_exists( 'bcpro_astro_birth_to_v2_input' ) ) :
/**
 * Convert legacy birth_data (year/month/day/hour/minute/latitude/longitude/timezone)
 * into the canonical V2 provider input.
 *
 * @param array  $birth_data Legacy array.
 * @param string $tz_hint    Optional IANA tz name override (defaults to
 *                           'Asia/Ho_Chi_Minh' for tz=+7 in Vietnam, otherwise
 *                           'Etc/GMT±N' POSIX-style).
 * @return array
 */
function bcpro_astro_birth_to_v2_input( array $birth_data, string $tz_hint = '' ): array {
	$lat = (float) ( $birth_data['latitude']  ?? $birth_data['lat'] ?? 0 );
	$lng = (float) ( $birth_data['longitude'] ?? $birth_data['lng'] ?? $birth_data['lon'] ?? 0 );
	$tz  = (float) ( $birth_data['timezone']  ?? $birth_data['tz']  ?? 7 );

	if ( $tz_hint !== '' ) {
		$tz_str = $tz_hint;
	} elseif ( abs( $tz - 7.0 ) < 0.01 ) {
		// Vietnam — preferred display name.
		$tz_str = 'Asia/Ho_Chi_Minh';
	} else {
		// POSIX Etc/GMT is sign-inverted: UTC+5 → 'Etc/GMT-5'.
		$abs    = (int) round( abs( $tz ) );
		$tz_str = 'Etc/GMT' . ( $tz >= 0 ? ( '-' . $abs ) : ( '+' . $abs ) );
	}

	return array(
		'name'         => (string) ( $birth_data['name'] ?? 'BizCity User' ),
		'year'         => (int) ( $birth_data['year']   ?? 1990 ),
		'month'        => (int) ( $birth_data['month']  ?? 1 ),
		'day'          => (int) ( $birth_data['day']    ?? 1 ),
		'hour'         => (int) ( $birth_data['hour']   ?? 12 ),
		'minute'       => (int) ( $birth_data['minute'] ?? 0 ),
		'lat'          => $lat,
		'lng'          => $lng,
		'tz_str'       => $tz_str,
		'time_known'   => true,
		'house_system' => 'P',
		'zodiac_type'  => 'tropical',
	);
}
endif;

if ( ! function_exists( '_bcpro_astro_v2_reshape_western_planets' ) ) :
/**
 * V2 planet rows → legacy-compatible array carrying BOTH key conventions.
 * Renderer reads: $positions[$pname] keyed by English name with
 *   sign / sign_en, normDegree / norm_degree, fullDegree / full_degree,
 *   house / house_number, isRetro / is_retro.
 */
function _bcpro_astro_v2_reshape_western_planets( array $v2_planets ): array {
	$by_name = array();
	foreach ( $v2_planets as $p ) {
		if ( ! is_array( $p ) ) continue;
		$name = (string) ( $p['name_en'] ?? $p['id'] ?? '' );
		if ( $name === '' ) continue;
		$sign_en_full = _bcpro_astro_v2_normalize_sign( (string) ( $p['sign_en'] ?? '' ) );
		$by_name[ $name ] = array(
			// V2 keys.
			'sign_en'         => $sign_en_full,
			'sign_vi'         => (string) ( $p['sign_vi']  ?? '' ),
			'sign_key'        => (string) ( $p['sign_key'] ?? '' ),
			'sign_degree'     => (float)  ( $p['sign_degree']     ?? 0 ),
			'norm_degree'     => (float)  ( $p['sign_degree']     ?? 0 ),
			'absolute_degree' => (float)  ( $p['absolute_degree'] ?? 0 ),
			'full_degree'     => (float)  ( $p['absolute_degree'] ?? 0 ),
			'house'           => (int)    ( $p['house'] ?? 0 ),
			'house_number'    => (int)    ( $p['house'] ?? 0 ),
			'is_retro'        => (bool)   ( $p['retrograde']    ?? false ),
			'retrograde'      => (bool)   ( $p['retrograde']    ?? false ),
			'speed'           => (float)  ( $p['speed']         ?? 0 ),
			// Legacy aliases (for any code path still reading these).
			'sign'            => $sign_en_full,
			'normDegree'      => (float)  ( $p['sign_degree']     ?? 0 ),
			'fullDegree'      => (float)  ( $p['absolute_degree'] ?? 0 ),
			'isRetro'         => ! empty( $p['retrograde'] ) ? 'true' : 'false',
			// Metadata.
			'planet_en'       => $name,
			'planet_vi'       => (string) ( $p['name_vi'] ?? '' ),
		);
	}
	return $by_name;
}
endif;

if ( ! function_exists( '_bcpro_astro_v2_reshape_houses' ) ) :
function _bcpro_astro_v2_reshape_houses( array $v2_houses ): array {
	$rows = array();
	foreach ( $v2_houses as $h ) {
		if ( ! is_array( $h ) ) continue;
		$num     = (int) ( $h['house'] ?? 0 );
		$sign_en = _bcpro_astro_v2_normalize_sign( (string) ( $h['sign_en'] ?? '' ) );
		// FAA upstream cusp degree may live under several keys depending on
		// API version: cusp_degree (V2 envelope), cusp (raw v1), abs_pos,
		// degree, absolute_degree, longitude.
		$deg = (float) ( $h['cusp_degree']
			?? $h['cusp']
			?? $h['abs_pos']
			?? $h['absolute_degree']
			?? $h['degree']
			?? $h['longitude']
			?? 0 );
		$rows[] = array(
			'House'       => $num,
			'house'       => $num,
			'sign'        => $sign_en,
			'sign_en'     => $sign_en,
			'sign_vi'     => (string) ( $h['sign_vi']  ?? '' ),
			'sign_key'    => (string) ( $h['sign_key'] ?? '' ),
			'degree'      => $deg,
			'normDegree'  => $deg,
			'norm_degree' => $deg,
			'full_degree' => $deg,
		);
	}
	return $rows;
}
endif;

if ( ! function_exists( '_bcpro_astro_v2_reshape_aspects' ) ) :
function _bcpro_astro_v2_reshape_aspects( array $v2_aspects ): array {
	$rows = array();
	foreach ( $v2_aspects as $a ) {
		if ( ! is_array( $a ) ) continue;
		// Capitalize lowercased planet keys ("sun" → "Sun") so they match the
		// renderer's planet_vi map which uses TitleCase English names.
		$p1 = ucfirst( strtolower( (string) ( $a['p1'] ?? $a['planet1'] ?? '' ) ) );
		$p2 = ucfirst( strtolower( (string) ( $a['p2'] ?? $a['planet2'] ?? '' ) ) );
		// Capitalize aspect type so legend lookup ("Square", "Trine") works.
		// [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — add $a['aspect'] fallback for FAA2 normalized format
		$type_raw = (string) ( $a['type_en'] ?? $a['type_key'] ?? $a['type'] ?? $a['aspect'] ?? '' );
		$type     = $type_raw !== '' ? ucfirst( strtolower( $type_raw ) ) : '';
		$orb = (float) ( $a['orb'] ?? $a['orbit'] ?? $a['delta'] ?? 0 );
		$rows[] = array(
			'planet_1'    => $p1,
			'planet_2'    => $p2,
			'planet_1_en' => $p1,
			'planet_2_en' => $p2,
			'aspect'      => $type,
			'aspect_en'   => $type,
			'aspect_vi'   => (string) ( $a['type_vi'] ?? '' ),
			'type'        => $type,
			'angle'       => (float)  ( $a['angle'] ?? $a['deg'] ?? 0 ),
			'orb'         => $orb,
			'is_major'    => (bool)   ( $a['is_major'] ?? true ),
		);
	}
	return $rows;
}
endif;

if ( ! function_exists( 'bccm_astro_fetch_full_chart_v2' ) ) :
/**
 * Western natal chart via api.freeastroapi.com (V2).
 *
 * Drop-in replacement for `bccm_astro_fetch_full_chart()` that bypasses the
 * legacy json.freeastrologyapi.com host entirely.
 *
 * @param array $birth_data year/month/day/hour/minute/latitude/longitude/timezone
 * @return array|WP_Error  Legacy-compatible chart_data array, ready for
 *                         bccm_astro_save_chart().
 */
function bccm_astro_fetch_full_chart_v2( array $birth_data ) {
	// [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — natal MANDATORY via faa2_western (freeastrologyapi.com)
	if ( ! bcpro_astro_v2_available( 'western' ) ) {
		return new WP_Error( 'v2_unavailable', 'FAA2 Western provider not loaded or missing API key (bcr_astro_faa2_key). Configure in Network Admin → Settings → Astrology Gateway.' );
	}

	// Natal (planets/houses/aspects): faa2_western — MANDATORY
	// Chart SVG + transit enrichment: faa_western — supplement (null-safe)
	$faa2_provider = BizCity_Astro_Router::get_provider( 'faa2_western' );
	$provider      = BizCity_Astro_Router::get_provider( 'faa_western' );
	$input         = bcpro_astro_birth_to_v2_input( $birth_data );
	_bcpro_astro_v2_dbg( 'bridge.entry', array(
		'fn'             => 'bccm_astro_fetch_full_chart_v2',
		'natal_provider' => $faa2_provider ? get_class( $faa2_provider ) : 'NULL',
		'svg_provider'   => $provider      ? get_class( $provider )      : 'NULL',
		'has_chart_svg'  => $provider && method_exists( $provider, 'chart_svg' ),
		'has_transits'   => $provider && method_exists( $provider, 'transits' ),
		'has_tr_chart'   => $provider && method_exists( $provider, 'transit_chart_svg' ),
		'coachee_id'     => (int) ( $birth_data['coachee_id'] ?? $birth_data['user_id'] ?? 0 ),
	) );
	$env = $faa2_provider->natal( $input );
	_bcpro_astro_v2_dbg( 'bridge.natal', array(
		'success' => (int) ( $env['success'] ?? 0 ),
		'planets' => count( (array) ( $env['planets'] ?? array() ) ),
		'houses'  => count( (array) ( $env['houses']  ?? array() ) ),
		'aspects' => count( (array) ( $env['aspects'] ?? array() ) ),
		'angles'  => array_keys( (array) ( $env['angles'] ?? array() ) ),
		'error'   => (string) ( $env['error'] ?? '' ),
	) );

	if ( empty( $env['success'] ) ) {
		$msg = (string) ( $env['error'] ?? $env['message'] ?? 'FAA2 Western natal call failed' );
		return new WP_Error( 'faa2_western_failed', $msg, $env );
	}

	$planets_by_name = _bcpro_astro_v2_reshape_western_planets( (array) ( $env['planets'] ?? array() ) );
	$houses_rows     = _bcpro_astro_v2_reshape_houses( (array) ( $env['houses']  ?? array() ) );
	$aspects_rows    = _bcpro_astro_v2_reshape_aspects( (array) ( $env['aspects'] ?? array() ) );

	// Inject Ascendant / MC / IC / DC pseudo-planet rows from $env['angles'] so
	// the renderer's big-3 lookup ($positions['Ascendant']) finds a sign +
	// degree even though FAA returns angles in a separate envelope key.
	$angles_env = (array) ( $env['angles'] ?? array() );
	$angle_map  = array(
		'Ascendant'  => 'asc',
		'Midheaven'  => 'mc',
		'IC'         => 'ic',
		'Descendant' => 'dsc',
	);
	foreach ( $angle_map as $disp_name => $key ) {
		$row = (array) ( $angles_env[ $key ] ?? array() );
		if ( empty( $row ) ) continue;
		$sign_en = _bcpro_astro_v2_normalize_sign( (string) ( $row['sign_en'] ?? $row['sign'] ?? '' ) );
		if ( $sign_en === '' ) continue;
		$abs = (float) ( $row['absolute_degree'] ?? $row['abs_pos'] ?? $row['longitude'] ?? 0 );
		$sd  = (float) ( $row['sign_degree']     ?? $row['norm_degree'] ?? 0 );
		$planets_by_name[ $disp_name ] = array(
			'sign_en'         => $sign_en,
			'sign_vi'         => (string) ( $row['sign_vi']  ?? '' ),
			'sign_key'        => (string) ( $row['sign_key'] ?? '' ),
			'sign_degree'     => $sd,
			'norm_degree'     => $sd,
			'absolute_degree' => $abs,
			'full_degree'     => $abs,
			'house'           => 0,
			'house_number'    => 0,
			'is_retro'        => false,
			'retrograde'      => false,
			'speed'           => 0,
			'sign'            => $sign_en,
			'normDegree'      => $sd,
			'fullDegree'      => $abs,
			'isRetro'         => 'false',
			'planet_en'       => $disp_name,
			'planet_vi'       => (string) ( $row['name_vi'] ?? '' ),
			'_is_angle'       => true,
		);
	}

	// [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — FAA2 natal has no angles{} block.
	// Inject Ascendant (house 1 cusp) and Midheaven (house 10 cusp) from houses_rows
	// so the renderer's Ascendant lookup and big3 asc_sign work correctly.
	if ( empty( $planets_by_name['Ascendant'] ) && ! empty( $houses_rows ) ) {
		$_house_angle_map = array(
			1  => 'Ascendant',
			10 => 'Midheaven',
			4  => 'IC',
			7  => 'Descendant',
		);
		foreach ( $houses_rows as $_hr ) {
			$_hnum = (int) ( $_hr['house'] ?? 0 );
			if ( ! isset( $_house_angle_map[ $_hnum ] ) ) { continue; }
			$_dispname = $_house_angle_map[ $_hnum ];
			if ( ! empty( $planets_by_name[ $_dispname ] ) ) { continue; }
			$_sign_en2 = _bcpro_astro_v2_normalize_sign( (string) ( $_hr['sign_en'] ?? '' ) );
			if ( $_sign_en2 === '' ) { continue; }
			$_cusp2 = (float) ( $_hr['cusp_degree'] ?? 0 );
			$_norm2 = (float) ( $_hr['norm_degree']  ?? fmod( $_cusp2, 30 ) );
			$planets_by_name[ $_dispname ] = array(
				'sign_en'         => $_sign_en2,
				'sign_vi'         => (string) ( $_hr['sign_vi']  ?? '' ),
				'sign_key'        => (string) ( $_hr['sign_key'] ?? '' ),
				'sign_degree'     => $_norm2,
				'norm_degree'     => $_norm2,
				'absolute_degree' => $_cusp2,
				'full_degree'     => $_cusp2,
				'house'           => 0,
				'house_number'    => 0,
				'is_retro'        => false,
				'retrograde'      => false,
				'speed'           => 0,
				'sign'            => $_sign_en2,
				'normDegree'      => $_norm2,
				'fullDegree'      => $_cusp2,
				'isRetro'         => 'false',
				'planet_en'       => $_dispname,
				'planet_vi'       => '',
				'_is_angle'       => true,
				'_from_faa2_house'=> true,
			);
		}
	}

	// `parsed` block — mirrors bccm_astro_parse_planets() shape.
	$big3      = (array) ( $env['big3']  ?? array() );
	$sun_sign  = _bcpro_astro_v2_normalize_sign( (string) ( $big3['sun']['sign_en']  ?? ( $planets_by_name['Sun']['sign_en']  ?? '' ) ) );
	$moon_sign = _bcpro_astro_v2_normalize_sign( (string) ( $big3['moon']['sign_en'] ?? ( $planets_by_name['Moon']['sign_en'] ?? '' ) ) );
	$asc_sign  = '';
	if ( ! empty( $env['angles']['asc']['sign_en'] ) ) {
		$asc_sign = _bcpro_astro_v2_normalize_sign( (string) $env['angles']['asc']['sign_en'] );
	} elseif ( ! empty( $planets_by_name['Ascendant']['sign_en'] ) ) {
		$asc_sign = (string) $planets_by_name['Ascendant']['sign_en'];
	}

	$parsed = array(
		'sun_sign'       => $sun_sign,
		'moon_sign'      => $moon_sign,
		'ascendant_sign' => $asc_sign,
		'positions'      => $planets_by_name,
	);

	// ─── Enrichment pass: chart SVG + transits + bi-wheel SVG ────────────
	// All non-fatal: a failed enrichment must NOT discard the natal payload.
	$coachee_id = (int) ( $birth_data['coachee_id'] ?? $birth_data['user_id'] ?? 0 );

	$chart_url        = '';
	$chart_svg_inline = '';
	// [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — SVG from faa_western (supplement; null-safe)
	$svg_res = ( $provider && method_exists( $provider, 'chart_svg' ) )
		? $provider->chart_svg( array_merge( $input, array(
			'format'     => 'svg',
			'theme_type' => 'light',
			'size'       => 800,
		) ) )
		: array( 'success' => false, 'message' => 'faa_western provider not available for SVG' );
	_bcpro_astro_v2_dbg( 'bridge.chart_svg', array(
		'coachee_id'   => $coachee_id,
		'success'      => (int) ( $svg_res['success'] ?? 0 ),
		'bytes'        => (int) ( $svg_res['bytes']   ?? 0 ),
		'content_type' => (string) ( $svg_res['content_type'] ?? '' ),
		'has_svg'      => ! empty( $svg_res['svg'] ),
		'code'         => (string) ( $svg_res['code']     ?? '' ),
		'message'      => (string) ( $svg_res['message']  ?? $svg_res['error'] ?? '' ),
		'status'       => (int)    ( $svg_res['status']   ?? 0 ),
		'err_code'     => (string) ( $svg_res['err_code'] ?? '' ),
		'body_preview' => (string) ( $svg_res['body_preview'] ?? '' ),
	) );
	if ( ! empty( $svg_res['success'] ) && ! empty( $svg_res['svg'] ) ) {
		$saved = bccm_astro_save_svg_file( $coachee_id, 'natal', (string) $svg_res['svg'] );
		if ( is_wp_error( $saved ) ) {
			_bcpro_astro_v2_dbg( 'bridge.chart_svg.save_err', $saved->get_error_message() );
		} else {
			$chart_url        = (string) $saved;
			$chart_svg_inline = (string) $svg_res['svg'];
			_bcpro_astro_v2_dbg( 'bridge.chart_svg.saved', $chart_url );
		}
	}

	// [2026-07-09 Johnny Chu] PHASE-FAA2-FE — FAA2 natal-wheel-chart (dark theme S3 SVG URL)
	// Try faa2_western.natal_wheel_chart() as primary source (BEFORE AstroViet fallback).
	// Only runs if the legacy faa_western chart_svg failed (no overwrite of good existing SVG).
	if ( empty( $chart_url ) && $faa2_provider && method_exists( $faa2_provider, 'natal_wheel_chart' ) ) {
		$_faa2_wheel = $faa2_provider->natal_wheel_chart( $input );
		_bcpro_astro_v2_dbg( 'bridge.faa2_wheel_chart', array(
			'coachee_id' => $coachee_id,
			'success'    => (int) ( $_faa2_wheel['success'] ?? 0 ),
			'url'        => (string) ( $_faa2_wheel['url']     ?? '' ),
			'message'    => (string) ( $_faa2_wheel['message'] ?? '' ),
		) );
		if ( ! empty( $_faa2_wheel['success'] ) && ! empty( $_faa2_wheel['url'] ) ) {
			$chart_url = (string) $_faa2_wheel['url'];
		}
	}

	// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — astroviet.com fallback URL
	// If SVG save failed (e.g. upload dir not writable on sub-site), build
	// the chart URL from planet positions using the AstroViet external renderer.
	// This requires no local file writes and works on any host.
	if ( empty( $chart_url ) && function_exists( 'bccm_build_astroviet_wheel_url' ) && ! empty( $planets_by_name ) ) {
		$_av_name       = (string) ( $birth_data['name'] ?? '' );
		$_av_birth      = array_merge( $birth_data, [
			'birth_place' => (string) ( $birth_data['birth_place'] ?? '' ),
			'latitude'    => (float)  ( $birth_data['latitude']    ?? 21.0285 ),
			'longitude'   => (float)  ( $birth_data['longitude']   ?? 105.8542 ),
		] );
		$_av_url = bccm_build_astroviet_wheel_url(
			$planets_by_name,
			$env['houses'] ?? array(),
			$_av_name,
			$_av_birth
		);
		if ( $_av_url ) {
			$chart_url = $_av_url;
			_bcpro_astro_v2_dbg( 'bridge.chart_svg.astroviet', $chart_url );
		}
	}

	$transits_data = array();
	// [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — faa_western supplement; null-safe
	if ( $provider && method_exists( $provider, 'transits' ) ) {
		$tr_env = $provider->transits( $input );
		_bcpro_astro_v2_dbg( 'bridge.transits', array(
			'coachee_id'   => $coachee_id,
			'success'      => (int) ( $tr_env['success'] ?? 0 ),
			'planets'      => count( (array) ( $tr_env['planets'] ?? array() ) ),
			'aspects'      => count( (array) ( $tr_env['aspects'] ?? array() ) ),
			'code'         => (string) ( $tr_env['code']     ?? '' ),
			'message'      => (string) ( $tr_env['message']  ?? $tr_env['error'] ?? '' ),
			'status'       => (int)    ( $tr_env['status']   ?? 0 ),
			'err_code'     => (string) ( $tr_env['err_code'] ?? '' ),
			'body_preview' => (string) ( $tr_env['body_preview'] ?? '' ),
		) );
		if ( ! empty( $tr_env['success'] ) ) {
			$transits_data = array(
				'planets'        => (array) ( $tr_env['planets']        ?? array() ),
				'aspects'        => (array) ( $tr_env['aspects']        ?? array() ),
				'angles'         => (array) ( $tr_env['angles']         ?? array() ),
				'summary'        => (array) ( $tr_env['summary']        ?? array() ),
				'interpretation' => (array) ( $tr_env['interpretation'] ?? array() ),
				'transit_date'   => (string) ( $tr_env['transit_date']  ?? gmdate( 'Y-m-d\TH:i' ) ),
				'_source'        => 'faa_western_transits_v2',
			);
		}
	}

	$transit_chart_url        = '';
	$transit_chart_svg_inline = '';
	// [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — faa_western supplement; null-safe
	if ( $provider && method_exists( $provider, 'transit_chart_svg' ) ) {
		$tr_svg = $provider->transit_chart_svg( array(
			'natal'                => $input,
			'current_city'         => (string) ( $birth_data['current_city'] ?? 'Hanoi' ),
			'transit_date'         => gmdate( 'Y-m-d\TH:i' ),
			'tz_str'               => 'Asia/Ho_Chi_Minh',
			'show_inter_aspects'   => true,
			'show_natal_aspects'   => false,
			'show_transit_aspects' => false,
			'natal_planet_color'   => '#1565C0',
			'transit_planet_color' => '#C62828',
			'format'               => 'svg',
			'theme_type'           => 'light',
			'size'                 => 800,
		) );
		_bcpro_astro_v2_dbg( 'bridge.transit_chart_svg', array(
			'coachee_id'   => $coachee_id,
			'success'      => (int) ( $tr_svg['success'] ?? 0 ),
			'bytes'        => (int) ( $tr_svg['bytes']   ?? 0 ),
			'content_type' => (string) ( $tr_svg['content_type'] ?? '' ),
			'has_svg'      => ! empty( $tr_svg['svg'] ),
			'code'         => (string) ( $tr_svg['code']     ?? '' ),
			'message'      => (string) ( $tr_svg['message']  ?? $tr_svg['error'] ?? '' ),
			'status'       => (int)    ( $tr_svg['status']   ?? 0 ),
			'err_code'     => (string) ( $tr_svg['err_code'] ?? '' ),
			'body_preview' => (string) ( $tr_svg['body_preview'] ?? '' ),
		) );
		if ( ! empty( $tr_svg['success'] ) && ! empty( $tr_svg['svg'] ) ) {
			$saved = bccm_astro_save_svg_file( $coachee_id, 'transit', (string) $tr_svg['svg'] );
			if ( is_wp_error( $saved ) ) {
				_bcpro_astro_v2_dbg( 'bridge.transit_chart_svg.save_err', $saved->get_error_message() );
			} else {
				$transit_chart_url        = (string) $saved;
				$transit_chart_svg_inline = (string) $tr_svg['svg'];
				_bcpro_astro_v2_dbg( 'bridge.transit_chart_svg.saved', $transit_chart_url );
			}
		}
	}

	_bcpro_astro_v2_dbg( 'bridge.done', array(
		'coachee_id'        => $coachee_id,
		'chart_url'         => $chart_url,
		'transit_chart_url' => $transit_chart_url,
		'transits_aspects'  => count( (array) ( $transits_data['aspects'] ?? array() ) ),
		'planets_total'     => count( $planets_by_name ),
	) );

	return array(
		'birth_data'        => $birth_data,
		'planets'           => $planets_by_name,
		'houses'            => $houses_rows,
		'aspects'           => $aspects_rows,
		'chart_url'         => $chart_url,
		'chart_svg'         => $chart_svg_inline,
		'transits'          => $transits_data,
		'transit_chart_url' => $transit_chart_url,
		'transit_chart_svg' => $transit_chart_svg_inline,
		'parsed'            => $parsed,
		'angles'            => $angles_env,
		'big3'              => $big3,
		'fetched_at'        => current_time( 'mysql' ),
		'_source'           => 'faa_western_v2',
		'_raw_hash'         => (string) ( $env['raw_hash'] ?? '' ),
	);
}
endif;

if ( ! function_exists( 'bccm_astro_save_svg_file' ) ) :
/**
 * Save an SVG document into `uploads/bizcoach-astro-charts/`.
 * Filename: `{coachee_id}_{kind}.svg`. Returns URL or WP_Error.
 *
 * UTF-8 no-BOM is enforced via raw byte write (`file_put_contents`),
 * mirroring the user-memory rule for PHP files.
 */
function bccm_astro_save_svg_file( int $coachee_id, string $kind, string $svg ) {
	$svg = trim( $svg );
	if ( $svg === '' ) {
		return new WP_Error( 'svg_empty', 'Empty SVG payload' );
	}
	// Strip any UTF-8 BOM that may have crept in from upstream.
	if ( substr( $svg, 0, 3 ) === "\xEF\xBB\xBF" ) {
		$svg = substr( $svg, 3 );
	}
	$kind = preg_replace( '/[^a-z0-9_-]/i', '', $kind );
	if ( $kind === '' ) $kind = 'chart';

	$ud  = wp_upload_dir();
	$dir = trailingslashit( $ud['basedir'] ) . 'bizcoach-astro-charts';
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'mkdir_failed', 'Cannot create uploads dir: ' . $dir );
	}
	$id   = $coachee_id > 0 ? $coachee_id : 0;
	$name = sprintf( '%d_%s.svg', $id, $kind );
	$file = $dir . '/' . $name;
	$ok   = @file_put_contents( $file, $svg, LOCK_EX );
	if ( $ok === false ) {
		return new WP_Error( 'write_failed', 'file_put_contents failed: ' . $file );
	}
	return trailingslashit( $ud['baseurl'] ) . 'bizcoach-astro-charts/' . $name;
}
endif;

if ( ! function_exists( '_bcpro_astro_v2_reshape_vedic_planets' ) ) :
/** V2 vedic planet rows → legacy-compatible by-name map. */
function _bcpro_astro_v2_reshape_vedic_planets( array $v2_planets ): array {
	$by_name = array();
	foreach ( $v2_planets as $p ) {
		if ( ! is_array( $p ) ) continue;
		$name = (string) ( $p['name_en'] ?? $p['id'] ?? '' );
		if ( $name === '' ) continue;
		$sign_en_full = _bcpro_astro_v2_normalize_sign( (string) ( $p['sign_en'] ?? '' ) );
		$by_name[ $name ] = array(
			'sign_en'         => $sign_en_full,
			'sign_vi'         => (string) ( $p['sign_vi']  ?? '' ),
			'sign_key'        => (string) ( $p['sign_key'] ?? '' ),
			'sign_lord'       => (string) ( $p['sign_lord'] ?? '' ),
			'sign_degree'     => (float)  ( $p['sign_degree']     ?? 0 ),
			'norm_degree'     => (float)  ( $p['sign_degree']     ?? 0 ),
			'absolute_degree' => (float)  ( $p['absolute_degree'] ?? 0 ),
			'full_degree'     => (float)  ( $p['absolute_degree'] ?? 0 ),
			'house'           => (int)    ( $p['house'] ?? 0 ),
			'house_number'    => (int)    ( $p['house'] ?? 0 ),
			'nakshatra'       => (string) ( $p['nakshatra']      ?? '' ),
			'nakshatra_lord'  => (string) ( $p['nakshatra_lord'] ?? '' ),
			'nakshatra_pada'  => (int)    ( $p['nakshatra_pada'] ?? 0 ),
			'dignity'         => (string) ( $p['dignity']        ?? '' ),
			'is_retro'        => (bool)   ( $p['retrograde']     ?? false ),
			'retrograde'      => (bool)   ( $p['retrograde']     ?? false ),
			'speed'           => (float)  ( $p['speed']          ?? 0 ),
			// Legacy aliases.
			'sign'            => $sign_en_full,
			'normDegree'      => (float)  ( $p['sign_degree']     ?? 0 ),
			'fullDegree'      => (float)  ( $p['absolute_degree'] ?? 0 ),
			'isRetro'         => ! empty( $p['retrograde'] ) ? 'true' : 'false',
			'planet_en'       => $name,
			'planet_vi'       => (string) ( $p['name_vi'] ?? '' ),
		);
	}
	return $by_name;
}
endif;

if ( ! function_exists( '_bcpro_astro_v2_reshape_vedic_houses' ) ) :
function _bcpro_astro_v2_reshape_vedic_houses( array $v2_houses ): array {
	$rows = array();
	foreach ( $v2_houses as $h ) {
		if ( ! is_array( $h ) ) continue;
		$num     = (int) ( $h['house'] ?? 0 );
		$sign_en = _bcpro_astro_v2_normalize_sign( (string) ( $h['sign_en'] ?? '' ) );
		$deg = (float) ( $h['cusp']
			?? $h['cusp_degree']
			?? $h['abs_pos']
			?? $h['absolute_degree']
			?? $h['degree']
			?? $h['longitude']
			?? 0 );
		$rows[] = array(
			'House'       => $num,
			'house'       => $num,
			'sign'        => $sign_en,
			'sign_en'     => $sign_en,
			'sign_vi'     => (string) ( $h['sign_vi']  ?? '' ),
			'sign_lord'   => (string) ( $h['sign_lord'] ?? '' ),
			'degree'      => $deg,
			'normDegree'  => $deg,
			'norm_degree' => $deg,
			'full_degree' => $deg,
		);
	}
	return $rows;
}
endif;

if ( ! function_exists( 'bccm_vedic_fetch_full_chart_v2' ) ) :
/**
 * Vedic natal chart via api.freeastroapi.com (V2).
 *
 * Drop-in replacement for `bccm_vedic_fetch_full_chart()`.
 *
 * @param array $birth_data
 * @return array|WP_Error
 */
function bccm_vedic_fetch_full_chart_v2( array $birth_data ) {
	if ( ! bcpro_astro_v2_available( 'vedic' ) ) {
		return new WP_Error( 'v2_unavailable', 'FAA V2 Vedic provider not loaded or missing API key. Configure in Network Admin → Settings → Astrology Gateway.' );
	}

	$provider = BizCity_Astro_Router::get_provider( 'faa_vedic' );
	$input    = bcpro_astro_birth_to_v2_input( $birth_data );
	_bcpro_astro_v2_dbg( 'vedic.entry', array(
		'provider'   => get_class( $provider ),
		'coachee_id' => (int) ( $birth_data['coachee_id'] ?? $birth_data['user_id'] ?? 0 ),
		'lat'        => $input['lat']    ?? null,
		'lng'        => $input['lng']    ?? null,
		'tz_str'     => $input['tz_str'] ?? null,
	) );
	$env      = $provider->calculate( $input );
	_bcpro_astro_v2_dbg( 'vedic.calculate', array(
		'success'      => (int) ( $env['success'] ?? 0 ),
		'planets'      => count( (array) ( $env['planets'] ?? array() ) ),
		'houses'       => count( (array) ( $env['houses']  ?? array() ) ),
		'has_lagna'    => ! empty( $env['lagna'] ),
		'has_dasha'    => ! empty( $env['dasha'] ),
		'yogas'        => count( (array) ( $env['yogas'] ?? array() ) ),
		'has_panchang' => ! empty( $env['panchang'] ),
		'vargas'       => array_keys( (array) ( $env['vargas'] ?? array() ) ),
		'code'         => (string) ( $env['code']     ?? '' ),
		'message'      => (string) ( $env['message']  ?? $env['error'] ?? '' ),
		'status'       => (int)    ( $env['status']   ?? 0 ),
		'err_code'     => (string) ( $env['err_code'] ?? '' ),
		'body_preview' => (string) ( $env['body_preview'] ?? '' ),
	) );

	if ( empty( $env['success'] ) ) {
		$msg = (string) ( $env['message'] ?? $env['error'] ?? 'FAA Vedic calculate call failed' );
		return new WP_Error( 'faa_vedic_failed', $msg, $env );
	}

	$planets_by_name = _bcpro_astro_v2_reshape_vedic_planets( (array) ( $env['planets'] ?? array() ) );
	$houses_rows     = _bcpro_astro_v2_reshape_vedic_houses( (array) ( $env['houses']  ?? array() ) );

	$sun_sign  = (string) ( $planets_by_name['Sun']['sign_en']  ?? '' );
	$moon_sign = (string) ( $planets_by_name['Moon']['sign_en'] ?? '' );
	$asc_sign  = _bcpro_astro_v2_normalize_sign( (string) ( $env['lagna']['sign_en'] ?? ( $planets_by_name['Ascendant']['sign_en'] ?? '' ) ) );

	// Inject Lagna as an Ascendant pseudo-planet so the renderer's BIG-3 and
	// houses table can resolve "Ascendant" identically to Western.
	if ( ! empty( $env['lagna'] ) && empty( $planets_by_name['Ascendant'] ) ) {
		$lagna = (array) $env['lagna'];
		$planets_by_name['Ascendant'] = array(
			'sign_en'         => $asc_sign,
			'sign_vi'         => (string) ( $lagna['sign_vi']  ?? '' ),
			'sign_key'        => (string) ( $lagna['sign_key'] ?? '' ),
			'sign_degree'     => (float)  ( $lagna['absolute_degree'] ?? 0 ) - 30 * floor( ( $lagna['absolute_degree'] ?? 0 ) / 30 ),
			'norm_degree'     => (float)  ( $lagna['absolute_degree'] ?? 0 ) - 30 * floor( ( $lagna['absolute_degree'] ?? 0 ) / 30 ),
			'absolute_degree' => (float)  ( $lagna['absolute_degree'] ?? 0 ),
			'full_degree'     => (float)  ( $lagna['absolute_degree'] ?? 0 ),
			'house'           => 1,
			'house_number'    => 1,
			'is_retro'        => false,
			'retrograde'      => false,
			'speed'           => 0,
			'sign'            => $asc_sign,
			'normDegree'      => (float)  ( $lagna['absolute_degree'] ?? 0 ) - 30 * floor( ( $lagna['absolute_degree'] ?? 0 ) / 30 ),
			'fullDegree'      => (float)  ( $lagna['absolute_degree'] ?? 0 ),
			'isRetro'         => 'false',
			'planet_en'       => 'Ascendant',
			'planet_vi'       => 'Lagna',
			'nakshatra'       => (string) ( $lagna['nakshatra']      ?? '' ),
			'nakshatra_lord'  => (string) ( $lagna['nakshatra_lord'] ?? '' ),
			'_is_angle'       => true,
		);
	}

	$parsed = array(
		'sun_sign'       => $sun_sign,
		'moon_sign'      => $moon_sign,
		'ascendant_sign' => $asc_sign,
		'positions'      => $planets_by_name,
	);

	// ─── Enrichment: Vedic SVG via Western sidereal (Lahiri) ──────────
	// FAA does not expose a dedicated /vedic/chart SVG endpoint, so we
	// reuse /api/v1/natal/chart/ with zodiac_type=sidereal +
	// sidereal_ayanamsa=lahiri + whole_sign houses to produce a
	// visually-Vedic wheel.
	$coachee_id       = (int) ( $birth_data['coachee_id'] ?? $birth_data['user_id'] ?? 0 );
	$chart_url        = '';
	$chart_svg_inline = '';
	if ( bcpro_astro_v2_available( 'western' ) ) {
		$west = BizCity_Astro_Router::get_provider( 'faa_western' );
		if ( $west && method_exists( $west, 'chart_svg' ) ) {
			$svg_res = $west->chart_svg( array_merge( $input, array(
				'zodiac_type'       => 'sidereal',
				'sidereal_ayanamsa' => 'lahiri',
				'house_system'      => 'W', // Whole-sign (Vedic default)
				'format'            => 'svg',
				'theme_type'        => 'light',
				'size'              => 800,
			) ) );
			_bcpro_astro_v2_dbg( 'vedic.chart_svg', array(
				'coachee_id'   => $coachee_id,
				'success'      => (int) ( $svg_res['success'] ?? 0 ),
				'bytes'        => (int) ( $svg_res['bytes']   ?? 0 ),
				'has_svg'      => ! empty( $svg_res['svg'] ),
				'message'      => (string) ( $svg_res['message']  ?? $svg_res['error'] ?? '' ),
				'status'       => (int)    ( $svg_res['status']   ?? 0 ),
				'body_preview' => (string) ( $svg_res['body_preview'] ?? '' ),
			) );
			if ( ! empty( $svg_res['success'] ) && ! empty( $svg_res['svg'] ) ) {
				$saved = bccm_astro_save_svg_file( $coachee_id, 'vedic', (string) $svg_res['svg'] );
				if ( ! is_wp_error( $saved ) ) {
					$chart_url        = (string) $saved;
					$chart_svg_inline = (string) $svg_res['svg'];
				}
			}
		}
	}

	_bcpro_astro_v2_dbg( 'vedic.done', array(
		'coachee_id'    => $coachee_id,
		'chart_url'     => $chart_url,
		'planets_total' => count( $planets_by_name ),
		'has_dasha'     => ! empty( $env['dasha'] ),
		'yogas'         => count( (array) ( $env['yogas'] ?? array() ) ),
	) );

	return array(
		'birth_data'        => $birth_data,
		'planets'           => $planets_by_name,
		'houses'            => $houses_rows,
		'navamsa'           => (array) ( ( $env['vargas']['D9']['planets'] ?? array() ) ),
		'vargas'            => (array) ( $env['vargas']       ?? array() ),
		'lagna'             => (array) ( $env['lagna']        ?? array() ),
		'dasha'             => (array) ( $env['dasha']        ?? array() ),
		'yogas'             => (array) ( $env['yogas']        ?? array() ),
		'panchang'          => (array) ( $env['panchang']     ?? array() ),
		'shadbala'          => (array) ( $env['shadbala']     ?? array() ),
		'ashtakavarga'      => (array) ( $env['ashtakavarga'] ?? array() ),
		'aspects'           => (array) ( $env['aspects']      ?? array() ),
		'chart_url'         => $chart_url,
		'chart_svg'         => $chart_svg_inline,
		'navamsa_chart_url' => '',
		'parsed'            => $parsed,
		'fetched_at'        => current_time( 'mysql' ),
		'_source'           => 'faa_vedic_v2',
		'_raw_hash'         => (string) ( $env['raw_hash'] ?? '' ),
	);
}
endif;
