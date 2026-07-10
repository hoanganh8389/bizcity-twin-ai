<?php
/**
 * BizCoach Pro — Transit Timeline (Gantt) Renderer
 *
 * Powers the "professional" transit view for month/year/custom ranges using
 * freeastroapi's HIGH-PLAN endpoint:
 *
 *   POST /api/v1/western/transits/timeline   (via Astro_Client)
 *
 * Day/week ranges keep using the existing snapshot path in
 * astro-transit-report.php — timeline mode is for interval ("Gantt") views.
 *
 * Public flow:
 *   /my-transit-astrology/?id=&hash=&period=month
 *     → BizCoach_Pro_Transit_Public_Router::maybe_handle()
 *     → bccm_transit_report_handler()  (dispatcher in astro-transit-report.php)
 *     → bccm_transit_timeline_handler() (this file)
 *
 * Admin AJAX flow:
 *   /wp-admin/admin-ajax.php?action=bccm_transit_timeline&coachee_id=&period=&start=&end=&_wpnonce=
 *
 * @package BizCoach_Map
 * @since   0.36.x
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_bccm_transit_timeline', 'bccm_transit_timeline_handler' );

// ─────────────────────────────────────────────────────────────────
// Public entry point — dispatched both from AJAX and from the
// transit-report dispatcher (when period in month/year/custom).
// ─────────────────────────────────────────────────────────────────
if ( ! function_exists( 'bccm_transit_timeline_handler' ) ) {
function bccm_transit_timeline_handler() {
	$coachee_id = isset( $_GET['coachee_id'] ) ? (int) $_GET['coachee_id'] : 0;
	if ( $coachee_id <= 0 ) { wp_die( 'Missing coachee_id' ); }

	// Auth: public hash (transit chart_type) OR admin + nonce.
	$public_ok = function_exists( 'bcpro_astro_public_ctx_matches' )
		&& bcpro_astro_public_ctx_matches( $coachee_id, 'transit' );
	if ( ! $public_ok ) {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( 'Unauthorized' ); }
		check_ajax_referer( 'bccm_transit_report', '_wpnonce' );
	}

	$period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'month';
	if ( ! in_array( $period, array( 'month', 'year', 'custom' ), true ) ) {
		$period = 'month';
	}
	$start_in = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : '';
	$end_in   = isset( $_GET['end'] )   ? sanitize_text_field( wp_unslash( $_GET['end'] ) )   : '';

	$range = bccm_transit_timeline_compute_range( $period, $start_in, $end_in );
	if ( is_wp_error( $range ) ) { wp_die( esc_html( $range->get_error_message() ) ); }

	// Load coachee + natal.
	global $wpdb;
	$t = function_exists( 'bccm_tables' ) ? bccm_tables() : array( 'profiles' => $wpdb->prefix . 'bccm_coachees' );
	$coachee = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['profiles']} WHERE id = %d", $coachee_id ), ARRAY_A );
	if ( ! $coachee ) { wp_die( 'Coachee not found' ); }

	$user_id = (int) ( $coachee['user_id'] ?? 0 );
	$astro_row = null;
	// [2026-07-07 Johnny Chu] PHASE-FAA2-TL-PROFILE — choose natal row by coachee_id first
	// so month/year timeline follows selected profile, not just account-level user_id.
	$astro_row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d AND chart_type='western'",
		$coachee_id
	), ARRAY_A );
	if ( $user_id ) {
		$astro_row = $astro_row ?: $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='western' AND (summary IS NOT NULL OR traits IS NOT NULL)",
			$user_id
		), ARRAY_A );
	}
	if ( ! $astro_row || empty( $astro_row['traits'] ) ) {
		wp_die( 'Chưa có bản đồ Western Astrology cho hồ sơ này — hãy tạo natal chart trước.' );
	}
	$natal_traits = json_decode( $astro_row['traits'], true ) ?: array();
	$birth_data   = $natal_traits['birth_data'] ?? array();
	if ( empty( $birth_data['year'] ) ) { wp_die( 'Natal chart thiếu birth_data.' ); }

	$regenerate = ! empty( $_GET['regenerate'] );

	$result = bccm_transit_timeline_fetch_cached( $coachee_id, $astro_row, $birth_data, $range, $regenerate );

	if ( empty( $result['success'] ) ) {
		// Graceful fallback — set a global so the dispatcher (astro-transit-report.php) knows
		// to fall through to the DB-snapshot renderer and show a Pro upgrade notice.
		$msg = (string) ( $result['message'] ?? 'Không lấy được timeline transit.' );
		$GLOBALS['bccm_transit_tl_api_failed'] = $msg;
		return; // do NOT exit — let caller decide what to render next
	}

	bccm_transit_timeline_render_page( $coachee, $astro_row, $natal_traits, $period, $range, $result );
	exit;
}
}

/* =====================================================================
 * Range computation
 * ===================================================================== */

if ( ! function_exists( 'bccm_transit_timeline_compute_range' ) ) {
function bccm_transit_timeline_compute_range( $period, $start_in = '', $end_in = '' ) {
	$today = current_time( 'Y-m-d' );

	if ( $period === 'month' ) {
		// Current calendar month (timeline endpoint requires same month for `month` mode).
		$start = date( 'Y-m-01', strtotime( $today ) );
		$end   = date( 'Y-m-t',  strtotime( $today ) );
		return array( 'mode' => 'month', 'range_start' => $start, 'range_end' => $end, 'period' => 'month' );
	}

	if ( $period === 'year' ) {
		// 12 months from today, slow long-cycle bodies only.
		$start = $today;
		$end   = date( 'Y-m-d', strtotime( $today . ' +365 days' ) );
		// Timeline endpoint caps year_slow at 366 days.
		return array( 'mode' => 'year_slow', 'range_start' => $start, 'range_end' => $end, 'period' => 'year' );
	}

	if ( $period === 'custom' ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_in ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_in ) ) {
			return new WP_Error( 'invalid_range', 'period=custom yêu cầu start=YYYY-MM-DD và end=YYYY-MM-DD.' );
		}
		$ts_s = strtotime( $start_in );
		$ts_e = strtotime( $end_in );
		if ( $ts_e < $ts_s ) { return new WP_Error( 'invalid_range', 'end phải sau start.' ); }
		$days = (int) round( ( $ts_e - $ts_s ) / DAY_IN_SECONDS );
		if ( $days > 366 ) { return new WP_Error( 'invalid_range', 'Khoảng tối đa 366 ngày.' ); }
		// Auto-pick mode: same month → month, else year_slow.
		$same_month = ( date( 'Y-m', $ts_s ) === date( 'Y-m', $ts_e ) );
		return array(
			'mode'        => $same_month ? 'month' : 'year_slow',
			'range_start' => $start_in,
			'range_end'   => $end_in,
			'period'      => 'custom',
		);
	}

	return new WP_Error( 'invalid_period', 'Unsupported period: ' . $period );
}
}

/* =====================================================================
 * Timeline fetch (with transient cache)
 * ===================================================================== */

if ( ! function_exists( 'bccm_transit_timeline_fetch_cached' ) ) {
function bccm_transit_timeline_fetch_cached( $coachee_id, $astro_row, $birth_data, $range, $force = false ) {
	$cache_key = 'bccm_transit_tl_' . md5( wp_json_encode( array(
		'cid'   => (int) $coachee_id,
		'mode'  => $range['mode'],
		'start' => $range['range_start'],
		'end'   => $range['range_end'],
	) ) );

	if ( ! $force ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['success'] ) ) {
			$cached['cache_hit'] = true;
			return $cached;
		}
	}

	$natal = array(
		'name'   => '',
		'year'   => (int) $birth_data['year'],
		'month'  => (int) $birth_data['month'],
		'day'    => (int) $birth_data['day'],
		'hour'   => (int) ( $birth_data['hour']   ?? 12 ),
		'minute' => (int) ( $birth_data['minute'] ?? 0 ),
		'lat'    => (float) ( $astro_row['latitude']  ?: ( $birth_data['lat']  ?? 21.0285 ) ),
		'lng'    => (float) ( $astro_row['longitude'] ?: ( $birth_data['lng']  ?? 105.8542 ) ),
		'city'   => (string) ( $birth_data['city'] ?? 'Hanoi' ),
		'tz_str' => (string) ( $birth_data['tz_str'] ?? 'Asia/Ho_Chi_Minh' ),
		'time_known' => ! empty( $birth_data['hour'] ) || isset( $birth_data['hour'] ),
	);

	$payload = array(
		'natal'          => $natal,
		'mode'           => $range['mode'],
		'range_start'    => $range['range_start'],
		'range_end'      => $range['range_end'],
		'include_houses' => true,
	);

	// In year_slow mode, FAA expects only medium+slow categories.
	if ( $range['mode'] === 'year_slow' ) {
		$payload['transit_categories'] = array( 'medium', 'slow' );
	}

	if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
		return array( 'success' => false, 'message' => 'Astro Client unavailable.' );
	}

	$resp = BizCoach_Pro_Astro_Client::transits_timeline_western( $payload );

	if ( empty( $resp['success'] ) ) {
		$env = (array) ( $resp['envelope'] ?? array() );
		// Surface BOTH the gateway error AND any upstream FAA detail (body_preview)
		// so we can see what FAA actually replied (often: plan-tier rejection or
		// payload validation error).
		$parts = array();
		$gw_status = (int) ( $resp['http']['status'] ?? 0 );
		if ( $gw_status ) { $parts[] = 'GW HTTP ' . $gw_status; }
		$gw_err = (string) ( $resp['error'] ?? '' );
		if ( $gw_err !== '' && $gw_err !== 'http_' . $gw_status ) { $parts[] = $gw_err; }
		$up_status = (int) ( $env['status'] ?? 0 );
		if ( $up_status ) { $parts[] = 'Upstream HTTP ' . $up_status; }
		$up_err_code = (string) ( $env['err_code'] ?? $env['code'] ?? '' );
		if ( $up_err_code !== '' ) { $parts[] = 'code=' . $up_err_code; }
		$up_msg = (string) ( $env['message'] ?? '' );
		if ( $up_msg !== '' && $up_msg !== $gw_err ) { $parts[] = $up_msg; }
		$body_preview = (string) ( $env['body_preview'] ?? '' );
		if ( $body_preview !== '' ) { $parts[] = 'body=' . $body_preview; }
		$msg = $parts ? implode( ' · ', $parts ) : 'unknown_error';
		return array( 'success' => false, 'message' => $msg, 'raw' => $env );
	}

	// Envelope returned by the Astro Client wraps the provider body in `envelope`.
	$env  = (array) ( $resp['envelope'] ?? array() );
	$root = $env;
	// Some routes return `{data: {...}}` shape — unwrap if so.
	if ( isset( $env['data'] ) && is_array( $env['data'] ) && ! isset( $env['transits'] ) ) {
		$root = $env['data'];
	}

	$transits = array();
	if ( isset( $root['transits'] ) ) {
		$transits = (array) $root['transits'];
	}

	$out = array(
		'success'    => true,
		'meta'       => (array) ( $root['meta']  ?? array() ),
		'input'      => (array) ( $root['input'] ?? array() ),
		'transits'   => array_values( $transits ),
		'count'      => count( $transits ),
		'fetched_at' => current_time( 'mysql' ),
	);

	set_transient( $cache_key, $out, 6 * HOUR_IN_SECONDS );
	return $out;
}
}

/* =====================================================================
 * Gantt SVG renderer
 * ===================================================================== */

if ( ! function_exists( 'bccm_transit_timeline_render_gantt' ) ) {
function bccm_transit_timeline_render_gantt( array $transits, $range_start, $range_end, $mode ) {
	$ts_start = strtotime( $range_start );
	$ts_end   = strtotime( $range_end );
	if ( $ts_end <= $ts_start ) { return '<p>Khoảng thời gian không hợp lệ.</p>'; }
	$total_days = max( 1, (int) round( ( $ts_end - $ts_start ) / DAY_IN_SECONDS ) + 1 );

	// Group transits by row_key (transit__aspect__natal).
	$rows = array();
	foreach ( $transits as $tr ) {
		$key = $tr['row_key'] ?? ( ( $tr['transit_planet'] ?? '?' ) . '__' . ( $tr['aspect_type'] ?? '?' ) . '__' . ( $tr['natal_point'] ?? '?' ) );
		if ( ! isset( $rows[ $key ] ) ) {
			$rows[ $key ] = array(
				'label'    => $tr['label'] ?? $tr['short_symbol_label'] ?? $key,
				'category' => $tr['category'] ?? 'medium',
				'bars'     => array(),
			);
		}
		$rows[ $key ]['bars'][] = $tr;
	}

	// Sort: slow → medium → fast, then alphabetic.
	$cat_order = array( 'slow' => 0, 'medium' => 1, 'fast' => 2 );
	uasort( $rows, function ( $a, $b ) use ( $cat_order ) {
		$oa = $cat_order[ $a['category'] ] ?? 9;
		$ob = $cat_order[ $b['category'] ] ?? 9;
		if ( $oa !== $ob ) { return $oa - $ob; }
		return strcmp( $a['label'], $b['label'] );
	} );

	if ( empty( $rows ) ) {
		return '<div class="no-transits">Không có transit nào nằm trong khoảng đã chọn.</div>';
	}

	// Layout.
	$row_h     = 26;
	$label_w   = 240;
	$chart_w   = 720;
	$header_h  = 36;
	$padding_v = 8;
	$svg_w     = $label_w + $chart_w + 12;
	$svg_h     = $header_h + count( $rows ) * $row_h + $padding_v * 2;

	$day_w = $chart_w / $total_days;

	$cat_color = array(
		'slow'   => '#7c3aed',
		'medium' => '#0ea5e9',
		'fast'   => '#f59e0b',
	);

	$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $svg_w . ' ' . $svg_h . '" '
		. 'width="100%" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;font-family:Segoe UI,Tahoma,sans-serif;">';

	// Header strip (date labels).
	$svg .= '<rect x="0" y="0" width="' . $svg_w . '" height="' . $header_h . '" fill="#0f172a"/>';
	$svg .= '<text x="12" y="' . ( $header_h - 12 ) . '" fill="#cbd5e1" font-size="11" font-weight="600">Transit</text>';

	// Date ticks — every ~7 days or every month for year_slow.
	$tick_step_days = ( $mode === 'year_slow' ) ? 30 : 7;
	for ( $d = 0; $d <= $total_days; $d += $tick_step_days ) {
		$x = $label_w + $d * $day_w;
		$svg .= '<line x1="' . $x . '" y1="' . $header_h . '" x2="' . $x . '" y2="' . $svg_h . '" stroke="#f1f5f9" stroke-width="1"/>';
		$tick_ts = $ts_start + $d * DAY_IN_SECONDS;
		$label = ( $mode === 'year_slow' ) ? date( 'M y', $tick_ts ) : date( 'd/m', $tick_ts );
		$svg .= '<text x="' . ( $x + 3 ) . '" y="' . ( $header_h - 6 ) . '" fill="#94a3b8" font-size="10">' . esc_html( $label ) . '</text>';
	}

	// Today line.
	$today_ts = strtotime( current_time( 'Y-m-d' ) );
	if ( $today_ts >= $ts_start && $today_ts <= $ts_end ) {
		$today_offset = ( $today_ts - $ts_start ) / DAY_IN_SECONDS;
		$x = $label_w + $today_offset * $day_w;
		$svg .= '<line x1="' . $x . '" y1="' . $header_h . '" x2="' . $x . '" y2="' . $svg_h . '" stroke="#ef4444" stroke-width="2" stroke-dasharray="4,3"/>';
		$svg .= '<text x="' . ( $x + 4 ) . '" y="' . ( $header_h + 12 ) . '" fill="#ef4444" font-size="10" font-weight="700">Hôm nay</text>';
	}

	// Rows.
	$y = $header_h + $padding_v;
	foreach ( $rows as $row ) {
		// Row background.
		$svg .= '<rect x="0" y="' . $y . '" width="' . $svg_w . '" height="' . $row_h . '" fill="#fafbfd"/>';
		// Label.
		$lbl = $row['label'];
		if ( strlen( $lbl ) > 36 ) { $lbl = substr( $lbl, 0, 33 ) . '…'; }
		$svg .= '<text x="12" y="' . ( $y + $row_h / 2 + 4 ) . '" fill="#1e293b" font-size="11" font-weight="600">'
			. esc_html( $lbl ) . '</text>';

		$color = $cat_color[ $row['category'] ] ?? '#64748b';

		foreach ( $row['bars'] as $bar ) {
			// Use visible window when available (clipped to requested month/year).
			if ( isset( $bar['visible_start_day'], $bar['visible_end_day'] ) && $mode === 'month' ) {
				$bar_start_offset = max( 0, (int) $bar['visible_start_day'] - 1 );
				$bar_end_offset   = min( $total_days - 1, (int) $bar['visible_end_day'] - 1 );
			} else {
				$bs = strtotime( $bar['start_datetime'] ?? $range_start );
				$be = strtotime( $bar['end_datetime']   ?? $range_end );
				$bar_start_offset = max( 0, (int) round( ( max( $bs, $ts_start ) - $ts_start ) / DAY_IN_SECONDS ) );
				$bar_end_offset   = min( $total_days - 1, (int) round( ( min( $be, $ts_end ) - $ts_start ) / DAY_IN_SECONDS ) );
			}
			$bar_x = $label_w + $bar_start_offset * $day_w;
			$bar_w = max( 4, ( $bar_end_offset - $bar_start_offset + 1 ) * $day_w );
			$bar_y = $y + 6;
			$bar_h = $row_h - 12;

			$is_retro = ( ( $bar['pass_type'] ?? 'direct' ) === 'retrograde' );
			$fill = $is_retro
				? 'url(#stripe-' . esc_attr( $row['category'] ) . ')'
				: $color;
			$svg .= '<rect x="' . $bar_x . '" y="' . $bar_y . '" width="' . $bar_w . '" height="' . $bar_h . '" '
				. 'fill="' . $fill . '" stroke="' . $color . '" stroke-width="1" rx="4"><title>'
				. esc_html( $bar['label'] ?? '' ) . ' · ' . esc_html( $bar['pass_type'] ?? 'direct' )
				. ' · ' . esc_html( $bar['start_datetime'] ?? '' ) . ' → ' . esc_html( $bar['end_datetime'] ?? '' )
				. '</title></rect>';

			// Exact-hit markers.
			$exacts = ! empty( $bar['exact_hits_in_month'] )
				? (array) $bar['exact_hits_in_month']
				: (array) ( $bar['exact_datetimes'] ?? array() );
			foreach ( $exacts as $ex_iso ) {
				$ex_ts = strtotime( $ex_iso );
				if ( $ex_ts < $ts_start || $ex_ts > $ts_end ) { continue; }
				$ex_offset = ( $ex_ts - $ts_start ) / DAY_IN_SECONDS;
				$ex_x = $label_w + $ex_offset * $day_w;
				$svg .= '<circle cx="' . $ex_x . '" cy="' . ( $bar_y + $bar_h / 2 ) . '" r="4" '
					. 'fill="#fff" stroke="' . $color . '" stroke-width="2"><title>EXACT '
					. esc_html( $ex_iso ) . '</title></circle>';
			}
		}

		$y += $row_h;
	}

	// Diagonal stripe patterns for retrograde bars.
	$svg .= '<defs>';
	foreach ( $cat_color as $cat => $c ) {
		$svg .= '<pattern id="stripe-' . $cat . '" patternUnits="userSpaceOnUse" width="6" height="6" patternTransform="rotate(45)">'
			. '<rect width="6" height="6" fill="' . $c . '" opacity="0.45"/>'
			. '<line x1="0" y1="0" x2="0" y2="6" stroke="' . $c . '" stroke-width="3"/>'
			. '</pattern>';
	}
	$svg .= '</defs>';

	$svg .= '</svg>';
	return $svg;
}
}

/* =====================================================================
 * Aspect symbol/color helpers (local fallbacks)
 * ===================================================================== */

if ( ! function_exists( 'bccm_transit_tl_aspect_meta' ) ) {
function bccm_transit_tl_aspect_meta( $aspect_type ) {
	$map = array(
		'conjunction' => array( 'sym' => '☌', 'vi' => 'Hợp', 'color' => '#475569', 'nature' => 'neutral' ),
		'opposition'  => array( 'sym' => '☍', 'vi' => 'Đối', 'color' => '#dc2626', 'nature' => 'challenging' ),
		'square'      => array( 'sym' => '□', 'vi' => 'Vuông', 'color' => '#ea580c', 'nature' => 'challenging' ),
		'trine'       => array( 'sym' => '△', 'vi' => 'Tam hợp', 'color' => '#16a34a', 'nature' => 'harmonious' ),
		'sextile'     => array( 'sym' => '⚹', 'vi' => 'Lục hợp', 'color' => '#0ea5e9', 'nature' => 'harmonious' ),
		'quincunx'    => array( 'sym' => '⚻', 'vi' => 'Bán nghịch', 'color' => '#a855f7', 'nature' => 'neutral' ),
	);
	$key = strtolower( (string) $aspect_type );
	return $map[ $key ] ?? array( 'sym' => '?', 'vi' => ucfirst( $key ), 'color' => '#64748b', 'nature' => 'neutral' );
}
}

if ( ! function_exists( 'bccm_transit_tl_planet_vi' ) ) {
function bccm_transit_tl_planet_vi( $key ) {
	$map = array(
		'sun' => 'Mặt Trời', 'moon' => 'Mặt Trăng', 'mercury' => 'Thuỷ Tinh',
		'venus' => 'Kim Tinh', 'mars' => 'Hoả Tinh', 'jupiter' => 'Mộc Tinh',
		'saturn' => 'Thổ Tinh', 'uranus' => 'Thiên Vương', 'neptune' => 'Hải Vương',
		'pluto' => 'Diêm Vương', 'chiron' => 'Chiron',
		'north_node' => 'Bắc Lưu', 'south_node' => 'Nam Lưu', 'true_node' => 'Bắc Lưu (true)',
		'mean_node' => 'Bắc Lưu (mean)', 'lilith' => 'Lilith', 'true_lilith' => 'Lilith (true)',
		'ascendant' => 'Asc', 'midheaven' => 'MC', 'descendant' => 'Desc', 'ic' => 'IC',
	);
	$k = strtolower( (string) $key );
	return $map[ $k ] ?? ucfirst( $k );
}
}

/* =====================================================================
 * Full page render
 * ===================================================================== */

if ( ! function_exists( 'bccm_transit_timeline_render_page' ) ) {
function bccm_transit_timeline_render_page( $coachee, $astro_row, $natal_traits, $period, $range, $result ) {
	$transits = (array) $result['transits'];
	$meta     = (array) $result['meta'];
	$input    = (array) $result['input'];

	$name_esc = esc_html( $coachee['full_name'] ?? '—' );
	$birth_data = $natal_traits['birth_data'] ?? array();
	$dob = '';
	if ( ! empty( $birth_data['day'] ) ) {
		$dob = sprintf( '%02d/%02d/%04d', (int) $birth_data['day'], (int) $birth_data['month'], (int) $birth_data['year'] );
	}

	$period_labels = array(
		'month'  => 'Transit Tháng (' . date( 'm/Y', strtotime( $range['range_start'] ) ) . ')',
		'year'   => 'Transit 12 Tháng Tới (' . date( 'd/m/Y', strtotime( $range['range_start'] ) ) . ' → ' . date( 'd/m/Y', strtotime( $range['range_end'] ) ) . ')',
		'custom' => 'Transit Tuỳ Chỉnh (' . date( 'd/m/Y', strtotime( $range['range_start'] ) ) . ' → ' . date( 'd/m/Y', strtotime( $range['range_end'] ) ) . ')',
	);
	$period_label = $period_labels[ $period ] ?? 'Transit Timeline';

	// Period nav links (works for both admin nonce + public hash flows).
	$nav = bccm_transit_timeline_build_nav( (int) $coachee['id'], $period );

	// Buckets for the summary tables.
	$slow_set     = array( 'jupiter', 'saturn', 'uranus', 'neptune', 'pluto' );
	$slow_bars    = array();
	$exact_events = array();
	foreach ( $transits as $tr ) {
		if ( in_array( strtolower( $tr['transit_planet'] ?? '' ), $slow_set, true ) ) {
			$slow_bars[] = $tr;
		}
		$exacts = ! empty( $tr['exact_hits_in_month'] )
			? (array) $tr['exact_hits_in_month']
			: (array) ( $tr['exact_datetimes'] ?? array() );
		foreach ( $exacts as $ex ) {
			$ex_ts = strtotime( $ex );
			if ( $ex_ts < strtotime( $range['range_start'] ) || $ex_ts > strtotime( $range['range_end'] ) ) { continue; }
			$exact_events[] = array(
				'when'    => $ex,
				'ts'      => $ex_ts,
				'label'   => $tr['label'] ?? '',
				'aspect'  => $tr['aspect_type'] ?? '',
				'transit' => $tr['transit_planet'] ?? '',
				'natal'   => $tr['natal_point'] ?? '',
				'cat'     => $tr['category'] ?? 'medium',
			);
		}
	}
	usort( $exact_events, function ( $a, $b ) { return $a['ts'] - $b['ts']; } );

	while ( ob_get_level() > 0 ) { @ob_end_clean(); }
	header( 'Content-Type: text/html; charset=UTF-8' );
	?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Transit Timeline — <?php echo $name_esc; ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 13px; color:#1a1a2e; line-height:1.6; background:#f0f4ff; }
.page { max-width: 1080px; margin: 0 auto; background:#fff; min-height:100vh; }
.toolbar { padding:14px 20px; background:linear-gradient(135deg,#0f172a,#1e1b4b); color:#fff; }
.toolbar .row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:center; }
.toolbar a, .toolbar button { padding:8px 16px; color:#fff; border:none; border-radius:8px; font-size:12px; cursor:pointer; font-weight:600; text-decoration:none; display:inline-block; transition: all .15s; }
.toolbar .nav-day    { background:#06b6d4; }
.toolbar .nav-week   { background:#3b82f6; }
.toolbar .nav-month  { background:#8b5cf6; }
.toolbar .nav-year   { background:#059669; }
.toolbar .nav-active { box-shadow: 0 0 0 3px rgba(255,255,255,.5); transform: scale(1.05); }
.toolbar .hint { color:#94a3b8; font-size:11px; margin-top:8px; text-align:center; }
.toolbar form { display:inline-flex; gap:6px; align-items:center; background:rgba(255,255,255,.06); padding:6px 10px; border-radius:8px; }
.toolbar input[type=date] { padding:5px 8px; border-radius:6px; border:1px solid #475569; background:#0f172a; color:#fff; font-size:12px; }
.toolbar button.go { background:#fbbf24; color:#0f172a; }

.cover { background:linear-gradient(135deg,#0c0a1d,#1e1b4b,#312e81); color:#fff; padding:36px 30px; text-align:center; }
.cover h1 { font-size:26px; font-weight:800; color:#a78bfa; margin-bottom:4px; }
.cover .sub { color:#94a3b8; letter-spacing:2px; font-size:11px; text-transform:uppercase; }
.cover .name { font-size:20px; font-weight:700; color:#e0e7ff; margin-top:14px; }
.cover .meta { font-size:12px; color:#818cf8; }
.cover .badge { display:inline-block; background:linear-gradient(135deg,#7c3aed,#6366f1); padding:8px 22px; border-radius:99px; font-size:14px; font-weight:700; color:#fbbf24; margin-top:14px; letter-spacing:1px; }

.content { padding:24px 30px 60px; }
.section { margin-bottom:32px; }
.section h2 { font-size:17px; font-weight:700; color:#1e293b; border-bottom:2px solid #e2e8f0; padding-bottom:8px; margin-bottom:14px; display:flex; gap:8px; align-items:center; }
.legend { display:flex; gap:14px; flex-wrap:wrap; font-size:11px; color:#475569; margin-bottom:10px; }
.legend .chip { display:inline-flex; align-items:center; gap:6px; }
.legend .dot { width:12px; height:12px; border-radius:3px; display:inline-block; }
.legend .dot.fast { background:#f59e0b; }
.legend .dot.medium { background:#0ea5e9; }
.legend .dot.slow { background:#7c3aed; }
.legend .dot.retro { background: repeating-linear-gradient(45deg,#64748b 0 4px,#fff 4px 8px); border:1px solid #64748b; }

table { width:100%; border-collapse:collapse; font-size:12px; }
th, td { padding:8px 10px; text-align:left; border-bottom:1px solid #e2e8f0; }
th { background:#f1f5f9; color:#475569; font-weight:700; }
tr:hover td { background:#fafbfd; }
.cat-slow { color:#7c3aed; font-weight:700; }
.cat-medium { color:#0ea5e9; font-weight:700; }
.cat-fast { color:#f59e0b; font-weight:700; }

.summary-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px,1fr)); gap:12px; margin-bottom:18px; }
.summary-card { background:#fafbfd; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; }
.summary-card .num { font-size:22px; font-weight:800; color:#1e1b4b; }
.summary-card .label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:1px; }

.ai-section { background:linear-gradient(135deg,#fefce8,#fef9c3); border:1px solid #fde047; border-radius:12px; padding:16px 20px; margin-top:16px; }
.ai-section h3 { color:#854d0e; font-size:15px; margin-bottom:8px; }
.ai-section .loading { color:#a16207; font-style:italic; }
.ai-section .content-inner { color:#451a03; line-height:1.7; }

.footer-note { text-align:center; color:#94a3b8; font-size:11px; padding:14px; border-top:1px solid #e2e8f0; }

@media (max-width:720px) {
	.cover h1 { font-size:22px; }
	.content { padding:18px 14px 40px; }
	th, td { padding:6px 6px; font-size:11px; }
}
</style>
</head>
<body>
<div class="page">

<div class="toolbar">
	<div class="row">
		<?php foreach ( $nav as $n ) : ?>
			<a class="nav-<?php echo esc_attr( $n['key'] ); ?> <?php echo $n['active'] ? 'nav-active' : ''; ?>"
			   href="<?php echo esc_url( $n['url'] ); ?>"><?php echo esc_html( $n['label'] ); ?></a>
		<?php endforeach; ?>

		<?php if ( $period === 'custom' || true ) :
			// Always show custom-range form. Embed hash if available (public ctx).
			$ctx = $GLOBALS['bcpro_public_astro_ctx'] ?? array();
			$hash = $ctx['hash'] ?? '';
		?>
			<form method="GET" action="<?php echo esc_url( bccm_transit_tl_base_url( (int) $coachee['id'] ) ); ?>">
				<?php if ( $hash !== '' ) : ?>
					<input type="hidden" name="id" value="<?php echo (int) $coachee['id']; ?>">
					<input type="hidden" name="hash" value="<?php echo esc_attr( $hash ); ?>">
				<?php else : ?>
					<input type="hidden" name="action" value="bccm_transit_timeline">
					<input type="hidden" name="coachee_id" value="<?php echo (int) $coachee['id']; ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'bccm_transit_report' ) ); ?>">
				<?php endif; ?>
				<input type="hidden" name="period" value="custom">
				<input type="date" name="start" value="<?php echo esc_attr( $range['range_start'] ); ?>">
				<input type="date" name="end"   value="<?php echo esc_attr( $range['range_end']   ); ?>">
				<button type="submit" class="go">Xem</button>
			</form>
		<?php endif; ?>
	</div>
	<div class="hint">
		Period: <strong><?php echo esc_html( $range['range_start'] . ' → ' . $range['range_end'] ); ?></strong>
		· Mode: <code><?php echo esc_html( $range['mode'] ); ?></code>
		· <?php echo (int) $result['count']; ?> transit intervals
		<?php if ( ! empty( $result['cache_hit'] ) ) : ?> · <span style="color:#fbbf24;">cached</span><?php endif; ?>
	</div>
</div>

<div class="cover">
	<div class="sub">BizCoach Pro · Astrology Transit</div>
	<h1><?php echo esc_html( $period_label ); ?></h1>
	<div class="name"><?php echo $name_esc; ?></div>
	<?php if ( $dob ) : ?><div class="meta">Sinh: <?php echo esc_html( $dob ); ?></div><?php endif; ?>
	<div class="badge">📊 <?php echo (int) $result['count']; ?> tương tác · <?php echo count( $exact_events ); ?> exact hits</div>
</div>

<div class="content">

	<div class="section">
		<h2>📌 Tổng quan</h2>
		<div class="summary-grid">
			<div class="summary-card"><div class="num"><?php echo (int) $result['count']; ?></div><div class="label">Tổng tương tác</div></div>
			<div class="summary-card"><div class="num"><?php echo count( $slow_bars ); ?></div><div class="label">Slow planets (Jup→Plu)</div></div>
			<div class="summary-card"><div class="num"><?php echo count( $exact_events ); ?></div><div class="label">Exact hits</div></div>
			<div class="summary-card"><div class="num"><?php
				$retro = array_filter( $transits, function ( $t ) { return ( $t['pass_type'] ?? '' ) === 'retrograde'; } );
				echo count( $retro );
			?></div><div class="label">Pha nghịch hành</div></div>
		</div>
	</div>

	<div class="section">
		<h2>📈 Gantt Timeline</h2>
		<div class="legend">
			<span class="chip"><span class="dot fast"></span> Fast (Mặt Trời/Trăng/Thuỷ/Kim/Hoả)</span>
			<span class="chip"><span class="dot medium"></span> Medium (Mộc/Chiron)</span>
			<span class="chip"><span class="dot slow"></span> Slow (Thổ/Thiên/Hải/Diêm/Lưu)</span>
			<span class="chip"><span class="dot retro"></span> Pha nghịch hành (kẻ chéo)</span>
			<span class="chip">⚪ Exact hit</span>
		</div>
		<?php echo bccm_transit_timeline_render_gantt( $transits, $range['range_start'], $range['range_end'], $range['mode'] ); ?>
	</div>

	<?php if ( ! empty( $exact_events ) ) : ?>
	<div class="section">
		<h2>⏰ Lịch các điểm chính xác (exact hits)</h2>
		<table>
			<thead>
				<tr><th>Ngày</th><th>Loại</th><th>Tương tác</th><th>Category</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $exact_events as $ev ) :
					$am = bccm_transit_tl_aspect_meta( $ev['aspect'] );
				?>
				<tr>
					<td><?php echo esc_html( date( 'd/m/Y H:i', $ev['ts'] ) ); ?></td>
					<td style="color:<?php echo esc_attr( $am['color'] ); ?>;font-weight:700;"><?php echo esc_html( $am['sym'] . ' ' . $am['vi'] ); ?></td>
					<td><?php echo esc_html(
						bccm_transit_tl_planet_vi( $ev['transit'] ) . ' (T) ' . $am['sym'] . ' ' . bccm_transit_tl_planet_vi( $ev['natal'] ) . ' (N)'
					); ?></td>
					<td class="cat-<?php echo esc_attr( $ev['cat'] ); ?>"><?php echo esc_html( ucfirst( $ev['cat'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $slow_bars ) ) : ?>
	<div class="section">
		<h2>🪐 Slow Planets — Tâm điểm dài hạn</h2>
		<p style="color:#64748b;font-size:12px;margin-bottom:10px;">
			Mộc Tinh, Thổ Tinh, Thiên Vương, Hải Vương, Diêm Vương và Lưu thần — tác động dài hạn (tuần → năm).
		</p>
		<table>
			<thead>
				<tr><th>Tương tác</th><th>Bắt đầu</th><th>Kết thúc</th><th>Thời lượng</th><th>Pha</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $slow_bars as $sb ) :
					$am = bccm_transit_tl_aspect_meta( $sb['aspect_type'] ?? '' );
					$pass = $sb['pass_type'] ?? 'direct';
				?>
				<tr>
					<td><span style="color:<?php echo esc_attr( $am['color'] ); ?>;font-weight:700;"><?php echo esc_html( $am['sym'] ); ?></span> <?php echo esc_html( $sb['label'] ?? '' ); ?></td>
					<td><?php echo esc_html( date( 'd/m/Y', strtotime( $sb['start_datetime'] ?? '' ) ) ); ?></td>
					<td><?php echo esc_html( date( 'd/m/Y', strtotime( $sb['end_datetime']   ?? '' ) ) ); ?></td>
					<td><?php echo esc_html( number_format( (float) ( $sb['duration_days'] ?? 0 ), 1 ) ); ?> ngày</td>
					<td><?php echo $pass === 'retrograde' ? '<span style="color:#dc2626;font-weight:700;">℞ Nghịch hành</span>' : 'Thuận hành'; ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

	<?php
	// AI luận giải — lazy-load qua action 'bccm_llm_section'.
	// Tận dụng hook đã có ở astro-report-llm.php.
	$ai_payload = array(
		'coachee_id'  => (int) $coachee['id'],
		'chart_type'  => 'transit',
		'period'      => $period,
		'range_start' => $range['range_start'],
		'range_end'   => $range['range_end'],
		'transit_count' => (int) $result['count'],
		'exact_hits'  => count( $exact_events ),
	);
	?>
	<div class="section">
		<h2>🤖 Luận giải AI</h2>
		<p style="color:#64748b;font-size:12px;margin-bottom:14px;">
			AI phân tích các tương tác transit nổi bật trong khoảng thời gian này — theo từng chủ đề (tài chính/sự nghiệp/tình cảm/sức khỏe).
		</p>
		<?php do_action( 'bccm_transit_ai_sections', $ai_payload, $transits, $exact_events ); ?>
	</div>

</div>

<div class="footer-note">
	BizCoach Pro · Transit Timeline · Generated <?php echo esc_html( current_time( 'd/m/Y H:i' ) ); ?>
	<?php if ( ! empty( $meta['generated_at'] ) ) : ?> · API <?php echo esc_html( $meta['generated_at'] ); ?><?php endif; ?>
</div>

</div>
</body>
</html>
	<?php
}
}

/* =====================================================================
 * Nav helpers
 * ===================================================================== */

if ( ! function_exists( 'bccm_transit_tl_base_url' ) ) {
function bccm_transit_tl_base_url( $coachee_id ) {
	// Public URL if hash context present, else admin-ajax fallback.
	$ctx = $GLOBALS['bcpro_public_astro_ctx'] ?? array();
	if ( ! empty( $ctx['hash'] ) && function_exists( 'bcpro_get_transit_public_url' ) ) {
		return home_url( '/my-transit-astrology/' );
	}
	return admin_url( 'admin-ajax.php' );
}
}

if ( ! function_exists( 'bccm_transit_timeline_build_nav' ) ) {
function bccm_transit_timeline_build_nav( $coachee_id, $current_period ) {
	$periods = array(
		'day'   => '🌅 Hôm nay',
		'week'  => '📅 Tuần',
		'month' => '🗓️ Tháng',
		'year'  => '📊 12 Tháng',
	);

	$ctx = $GLOBALS['bcpro_public_astro_ctx'] ?? array();
	$hash = $ctx['hash'] ?? '';
	$use_public = ( $hash !== '' && function_exists( 'bcpro_get_transit_public_url' ) );

	$out = array();
	foreach ( $periods as $p => $label ) {
		if ( $use_public ) {
			$url = bcpro_get_transit_public_url( $coachee_id, $p );
		} else {
			// Day/week use legacy bccm_transit_report; month/year/custom hit bccm_transit_timeline.
			$action = in_array( $p, array( 'day', 'week' ), true ) ? 'bccm_transit_report' : 'bccm_transit_timeline';
			$url = add_query_arg( array(
				'action'     => $action,
				'coachee_id' => $coachee_id,
				'period'     => $p,
				'_wpnonce'   => wp_create_nonce( 'bccm_transit_report' ),
			), admin_url( 'admin-ajax.php' ) );
		}
		$out[] = array(
			'key'    => $p,
			'label'  => $label,
			'url'    => $url,
			'active' => ( $p === $current_period ),
		);
	}
	return $out;
}
}
