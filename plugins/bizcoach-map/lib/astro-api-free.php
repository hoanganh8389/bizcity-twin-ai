<?php
/**
 * BizCoach Map – Free Astrology API Client
 *
 * Kết nối với Free Astrology API (https://freeastrologyapi.com)
 * Endpoints: western/planets, western/houses, western/aspects, western/natal-wheel-chart
 *
 * @package BizCoach_Map
 * @since   0.1.0.15
 * @see     https://freeastrologyapi.com
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * CONSTANTS
 * =====================================================================*/
define('BCCM_ASTRO_API_BASE', 'https://json.freeastrologyapi.com');

/* =====================================================================
 * API KEY MANAGEMENT
 * =====================================================================*/

/**
 * Get the Free Astrology API key from settings
 *
 * Priority:
 *   1. Site option  bccm_astro_api_key        (per-site override)
 *   2. Network option bccm_network_astro_api_key (network-wide, Multisite)
 *   3. PHP constant BCCM_ASTRO_API_KEY         (wp-config.php)
 */
function bccm_get_astro_api_key() {
    // 1. Site-level key (highest priority)
    $key = get_option('bccm_astro_api_key', '');

    // 2. Network-level key (multisite fallback)
    if (empty($key) && is_multisite()) {
        $key = get_site_option('bccm_network_astro_api_key', '');
    }

    // 3. PHP constant fallback
    if (empty($key) && defined('BCCM_ASTRO_API_KEY')) {
        $key = BCCM_ASTRO_API_KEY;
    }

    return $key;
}

/* =====================================================================
 * CORE API CALLER
 * =====================================================================*/

/**
 * Call Free Astrology API
 *
 * @param string $endpoint  E.g. 'western/planets'
 * @param array  $payload   Request body
 * @param int    $timeout   Seconds
 * @return array|WP_Error   Decoded JSON or error
 */
function bccm_astro_api_call($endpoint, $payload, $timeout = 30) {
    $api_key = bccm_get_astro_api_key();
    if (empty($api_key)) {
        return new WP_Error('no_api_key', 'Chưa cấu hình API key cho Free Astrology API. Vào Settings → Astrology để nhập.');
    }

    $url = BCCM_ASTRO_API_BASE . '/' . ltrim($endpoint, '/');

    $response = wp_remote_post($url, [
        'timeout' => $timeout,
        'headers' => [
            'Content-Type' => 'application/json',
            'x-api-key'    => $api_key,
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        error_log('[BCCM Astro API] Error: ' . $response->get_error_message());
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code !== 200) {
        $msg = $data['message'] ?? $data['error'] ?? "HTTP $code";
        error_log("[BCCM Astro API] HTTP $code: $msg");
        return new WP_Error('api_error', "Astrology API lỗi: $msg", ['http_code' => $code]);
    }

    if (!is_array($data)) {
        return new WP_Error('invalid_response', 'Dữ liệu trả về không hợp lệ.');
    }

    return $data;
}

/**
 * Build standard payload for astrology API
 *
 * @param array $birth_data  Keys: year, month, day, hour, minute, latitude, longitude, timezone
 * @param array $extra_config  Additional config overrides
 * @return array
 */
function bccm_astro_build_payload($birth_data, $extra_config = []) {
    $payload = [
        'year'      => intval($birth_data['year'] ?? 1990),
        'month'     => intval($birth_data['month'] ?? 1),
        'date'      => intval($birth_data['day'] ?? 1),
        'hours'     => intval($birth_data['hour'] ?? 12),
        'minutes'   => intval($birth_data['minute'] ?? 0),
        'seconds'   => intval($birth_data['second'] ?? 0),
        'latitude'  => floatval($birth_data['latitude'] ?? 21.0285),
        'longitude' => floatval($birth_data['longitude'] ?? 105.8542),
        'timezone'  => floatval($birth_data['timezone'] ?? 7),
        'config'    => array_merge([
            'observation_point' => 'topocentric',
            'ayanamsha'         => 'tropical',
            'language'          => 'en',
        ], $extra_config),
    ];

    return $payload;
}

/* =====================================================================
 * API ENDPOINT WRAPPERS
 * =====================================================================*/

/**
 * Get planets data
 *
 * @param array $birth_data
 * @return array|WP_Error   ['output' => [...planets...]]
 */
function bccm_astro_get_planets($birth_data) {
    $payload = bccm_astro_build_payload($birth_data);
    $result = bccm_astro_api_call('western/planets', $payload);

    if (is_wp_error($result)) return $result;

    // Cache planet data and extract key info
    $planets = $result['output'] ?? [];
    return ['planets' => $planets, 'raw' => $result];
}

/**
 * Get houses data
 *
 * @param array $birth_data
 * @param string $house_system  Placidus, Koch, Whole Signs, etc.
 * @return array|WP_Error
 */
function bccm_astro_get_houses($birth_data, $house_system = 'Placidus') {
    $payload = bccm_astro_build_payload($birth_data, [
        'house_system' => $house_system,
    ]);
    $result = bccm_astro_api_call('western/houses', $payload);

    if (is_wp_error($result)) return $result;

    return ['houses' => $result['output'] ?? [], 'raw' => $result];
}

/**
 * Get aspects data
 *
 * @param array $birth_data
 * @return array|WP_Error
 */
function bccm_astro_get_aspects($birth_data) {
    $payload = bccm_astro_build_payload($birth_data, [
        'allowed_aspects' => [
            'Conjunction', 'Opposition', 'Trine', 'Square', 'Sextile',
            'Semi-Sextile', 'Quintile', 'Quincunx', 'Sesquiquadrate',
            'Septile', 'Octile', 'Novile',
        ],
        'orb_values' => [
            'Conjunction'    => 8,
            'Opposition'     => 8,
            'Square'         => 7,
            'Trine'          => 8,
            'Sextile'        => 6,
            'Semi-Sextile'   => 3,
            'Quintile'       => 2,
            'Quincunx'       => 5,
            'Sesquiquadrate' => 3,
            'Septile'        => 2,
            'Octile'         => 2,
            'Novile'         => 2,
        ],
    ]);
    $result = bccm_astro_api_call('western/aspects', $payload);

    if (is_wp_error($result)) return $result;

    return ['aspects' => $result['output'] ?? [], 'raw' => $result];
}

/**
 * Get natal wheel chart SVG URL
 *
 * @param array $birth_data
 * @param array $chart_colors  Optional color overrides
 * @return array|WP_Error   ['chart_url' => '...svg']
 */
function bccm_astro_get_natal_wheel($birth_data, $chart_colors = []) {
    // ── AstroViet-matching style: light background, traditional aspect colors ──
    $default_colors = [
        'zodiac_sign_background_color' => '#4a4a6a',  // Muted purple-gray (similar to AstroViet outer ring)
        'chart_background_color'       => '#FFFFFF',  // White background like AstroViet
        'zodiac_signs_text_color'      => '#FFFFFF',  // White text on zodiac ring
        'dotted_line_color'            => '#c0c0c0',  // Light gray grid lines like AstroViet
        'planets_icon_color'           => '#000000',  // Black planet symbols like AstroViet
    ];

    $payload = bccm_astro_build_payload($birth_data, [
        'house_system'       => 'Placidus',
        'exclude_planets'    => [],
        // Traditional 5 major aspects only — matching AstroViet style
        'allowed_aspects'    => [
            'Conjunction', 'Opposition', 'Trine', 'Square', 'Sextile',
        ],
        // Traditional astrology colors: red (hard), blue (soft), green (trine)
        'aspect_line_colors' => [
            'Conjunction' => '#ff0000',   // Red — like AstroViet
            'Opposition'  => '#ff0000',   // Red — hard aspect
            'Square'      => '#ff0000',   // Red — hard aspect
            'Trine'       => '#0000ff',   // Blue — soft aspect like AstroViet
            'Sextile'     => '#0000ff',   // Blue — soft aspect
        ],
        'wheel_chart_colors' => array_merge($default_colors, $chart_colors),
        'orb_values' => [
            'Conjunction' => 8,
            'Opposition'  => 8,
            'Square'      => 7,
            'Trine'       => 8,
            'Sextile'     => 6,
        ],
    ]);

    $result = bccm_astro_api_call('western/natal-wheel-chart', $payload, 60);

    if (is_wp_error($result)) return $result;

    return ['chart_url' => $result['output'] ?? '', 'raw' => $result];
}

/* =====================================================================
 * ALL-IN-ONE: FETCH COMPLETE NATAL CHART
 * =====================================================================*/

/**
 * Fetch complete natal chart data (planets + houses + aspects + wheel SVG)
 *
 * @param array $birth_data  Keys: year, month, day, hour, minute, latitude, longitude, timezone
 * @return array|WP_Error    Complete chart data
 */
function bccm_astro_fetch_full_chart($birth_data) {
    // Step 1: Planets
    $planets_result = bccm_astro_get_planets($birth_data);
    if (is_wp_error($planets_result)) return $planets_result;

    // Step 2: Houses
    $houses_result = bccm_astro_get_houses($birth_data);
    if (is_wp_error($houses_result)) return $houses_result;

    // Step 3: Aspects
    $aspects_result = bccm_astro_get_aspects($birth_data);
    if (is_wp_error($aspects_result)) return $aspects_result;

    // Step 4: Natal wheel chart SVG (Free API)
    $wheel_result = bccm_astro_get_natal_wheel($birth_data);
    if (is_wp_error($wheel_result)) {
        // Non-fatal: wheel chart is optional
        error_log('[BCCM Astro] Wheel chart failed: ' . $wheel_result->get_error_message());
        $wheel_result = ['chart_url' => ''];
    }

    // Parse key positions
    $parsed = bccm_astro_parse_planets($planets_result['planets'] ?? []);

    return [
        'birth_data'     => $birth_data,
        'planets'        => $planets_result['planets'] ?? [],
        'houses'         => $houses_result['houses'] ?? [],
        'aspects'        => $aspects_result['aspects'] ?? [],
        'chart_url'      => $wheel_result['chart_url'] ?? '',
        'parsed'         => $parsed,
        'fetched_at'     => current_time('mysql'),
    ];
}

/* =====================================================================
 * PARSERS & INTERPRETERS
 * =====================================================================*/

/**
 * Parse planets array into structured data
 *
 * @param array $planets  Raw API planets output
 * @return array  Structured planet positions
 */
function bccm_astro_parse_planets($planets) {
    $signs = bccm_zodiac_signs();
    $result = [
        'sun_sign'       => '',
        'moon_sign'      => '',
        'ascendant_sign' => '',
        'positions'      => [],
    ];

    foreach ($planets as $planet) {
        $name = $planet['planet']['en'] ?? '';
        $sign_num = $planet['zodiac_sign']['number'] ?? 0;
        $sign_name = $planet['zodiac_sign']['name']['en'] ?? '';
        $sign_vi = $signs[$sign_num]['vi'] ?? $sign_name;
        $symbol = $signs[$sign_num]['symbol'] ?? '';

        $entry = [
            'planet_en'   => $name,
            'planet_vi'   => bccm_planet_names_vi()[$name] ?? $name,
            'sign_en'     => $sign_name,
            'sign_vi'     => $sign_vi,
            'sign_symbol' => $symbol,
            'sign_number' => $sign_num,
            'full_degree' => floatval($planet['fullDegree'] ?? 0),
            'norm_degree' => floatval($planet['normDegree'] ?? 0),
            'is_retro'    => strtolower($planet['isRetro'] ?? 'false') === 'true',
        ];

        $result['positions'][$name] = $entry;

        if ($name === 'Sun')       $result['sun_sign']       = $sign_name;
        if ($name === 'Moon')      $result['moon_sign']      = $sign_name;
        if ($name === 'Ascendant') $result['ascendant_sign'] = $sign_name;
    }

    return $result;
}

/**
 * Get Sun sign from a DOB (basic calculation, no API needed)
 * Fallback when API is not available
 *
 * @param string $dob  Date of birth (Y-m-d)
 * @return array ['en' => '...', 'vi' => '...', 'symbol' => '...']
 */
function bccm_astro_sun_sign_from_dob($dob) {
    $ts = strtotime($dob);
    if (!$ts) return ['en' => '', 'vi' => '', 'symbol' => ''];

    $m = intval(date('n', $ts));
    $d = intval(date('j', $ts));

    $ranges = [
        [3, 21, 4, 19, 1],   // Aries
        [4, 20, 5, 20, 2],   // Taurus
        [5, 21, 6, 20, 3],   // Gemini
        [6, 21, 7, 22, 4],   // Cancer
        [7, 23, 8, 22, 5],   // Leo
        [8, 23, 9, 22, 6],   // Virgo
        [9, 23, 10, 22, 7],  // Libra
        [10, 23, 11, 21, 8], // Scorpio
        [11, 22, 12, 21, 9], // Sagittarius
        [12, 22, 1, 19, 10], // Capricorn
        [1, 20, 2, 18, 11],  // Aquarius
        [2, 19, 3, 20, 12],  // Pisces
    ];

    $sign_num = 0;
    foreach ($ranges as $r) {
        if (($m === $r[0] && $d >= $r[1]) || ($m === $r[2] && $d <= $r[3])) {
            $sign_num = $r[4];
            break;
        }
    }

    $signs = bccm_zodiac_signs();
    if (isset($signs[$sign_num])) {
        return $signs[$sign_num];
    }

    return ['en' => 'Unknown', 'vi' => 'Chưa xác định', 'symbol' => '?'];
}

/* =====================================================================
 * SAVE / LOAD CHART DATA (DB + USER META)
 * =====================================================================*/

/**
 * Save chart data to bccm_astro table and coachee profile
 *
 * @param int   $coachee_id
 * @param array $chart_data  From bccm_astro_fetch_full_chart()
 * @param array $birth_input Original form input
 * @return bool
 */
function bccm_astro_save_chart($coachee_id, $chart_data, $birth_input = [], $passed_user_id = null) {
    global $wpdb;
    $t_astro = $wpdb->prefix . 'bccm_astro';
    $t       = bccm_tables();

    $parsed = $chart_data['parsed'] ?? [];

    // Build summary
    $summary = [
        'sun_sign'       => $parsed['sun_sign'] ?? '',
        'moon_sign'      => $parsed['moon_sign'] ?? '',
        'ascendant_sign' => $parsed['ascendant_sign'] ?? '',
        'chart_url'      => $chart_data['chart_url'] ?? '',
        'fetched_at'     => $chart_data['fetched_at'] ?? current_time('mysql'),
    ];

    // Build traits (full data)
    $traits = [
        'planets'    => $chart_data['planets'] ?? [],
        'houses'     => $chart_data['houses'] ?? [],
        'aspects'    => $chart_data['aspects'] ?? [],
        'positions'  => $parsed['positions'] ?? [],
        'birth_data' => $chart_data['birth_data'] ?? $birth_input,
    ];

    $now = current_time('mysql');

    // Use passed user_id if available, otherwise resolve from coachee profile
    $user_id = $passed_user_id ?: $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$t['profiles']} WHERE id=%d", $coachee_id));

    // Check existing by user_id first (to share astro data across platforms), then by coachee_id
    $chart_type = 'western';
    $existing = null;
    $existing_row = null;
    
    // Priority 1: Check by user_id (if user is logged in)
    if ($user_id) {
        $existing_row = $wpdb->get_row($wpdb->prepare("SELECT id, coachee_id FROM $t_astro WHERE user_id=%d AND chart_type=%s", $user_id, $chart_type), ARRAY_A);
        if ($existing_row) {
            $existing = $existing_row['id'];
        }
    }
    
    // Priority 2: Check by coachee_id (for guests or new records)
    if (!$existing) {
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_astro WHERE coachee_id=%d AND chart_type=%s", $coachee_id, $chart_type));
    }

    if ($existing) {
        $update_data = [
            'user_id'     => $user_id ?: null,
            'birth_place' => sanitize_text_field($birth_input['birth_place'] ?? ''),
            'birth_time'  => sanitize_text_field($birth_input['birth_time'] ?? ''),
            'latitude'    => floatval($birth_input['latitude'] ?? 0),
            'longitude'   => floatval($birth_input['longitude'] ?? 0),
            'timezone'    => floatval($birth_input['timezone'] ?? 7),
            'summary'     => wp_json_encode($summary, JSON_UNESCAPED_UNICODE),
            'traits'      => wp_json_encode($traits, JSON_UNESCAPED_UNICODE),
            'chart_svg'   => $chart_data['chart_url'] ?? '',
            'updated_at'  => $now,
        ];
        // Update by id for accuracy
        $wpdb->update($t_astro, $update_data, ['id' => $existing]);
    } else {
        $insert_data = [
            'coachee_id'  => $coachee_id,
            'user_id'     => $user_id ?: null,
            'chart_type'  => $chart_type,
            'birth_place' => sanitize_text_field($birth_input['birth_place'] ?? ''),
            'birth_time'  => sanitize_text_field($birth_input['birth_time'] ?? ''),
            'latitude'    => floatval($birth_input['latitude'] ?? 0),
            'longitude'   => floatval($birth_input['longitude'] ?? 0),
            'timezone'    => floatval($birth_input['timezone'] ?? 7),
            'summary'     => wp_json_encode($summary, JSON_UNESCAPED_UNICODE),
            'traits'      => wp_json_encode($traits, JSON_UNESCAPED_UNICODE),
            'chart_svg'   => $chart_data['chart_url'] ?? '',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        $wpdb->insert($t_astro, $insert_data);
    }

    // Update coachee profile with zodiac sign
    $sun_sign = strtolower($parsed['sun_sign'] ?? '');
    if ($sun_sign) {
        $wpdb->update($t['profiles'], [
            'zodiac_sign' => $sun_sign,
            'updated_at'  => $now,
        ], ['id' => $coachee_id]);
    }

    // Schedule background transit pre-fetch (stores today/+7/+30/+90/+365 snapshots in DB,
    // so AI can answer transit questions without calling the external API at chat time).
    // Always reschedule on chart create/update so data stays fresh.
    $prefetch_user_id = $user_id ? (int) $user_id : 0;
    $existing_cron = wp_next_scheduled('bccm_transit_prefetch_cron', [$coachee_id, $prefetch_user_id]);
    if ($existing_cron) {
        wp_unschedule_event($existing_cron, 'bccm_transit_prefetch_cron', [$coachee_id, $prefetch_user_id]);
    }
    wp_schedule_single_event(time() + 30, 'bccm_transit_prefetch_cron', [$coachee_id, $prefetch_user_id]);

    return true;
}

/**
 * Save astro data to user_meta (for pre-registration flow)
 *
 * @param int   $user_id
 * @param array $astro_data  Form input + chart data
 */
function bccm_astro_save_to_user_meta($user_id, $astro_data) {
    update_user_meta($user_id, 'bccm_astro_birth_data', $astro_data['birth_data'] ?? $astro_data);
    if (!empty($astro_data['parsed'])) {
        update_user_meta($user_id, 'bccm_astro_chart_parsed', $astro_data['parsed']);
    }
    if (!empty($astro_data['chart_url'])) {
        update_user_meta($user_id, 'bccm_astro_chart_url', $astro_data['chart_url']);
    }
    // Full chart JSON
    update_user_meta($user_id, 'bccm_astro_full_chart', $astro_data);
}

/**
 * Load astro data from user_meta
 *
 * @param int $user_id
 * @return array|null
 */
function bccm_astro_load_from_user_meta($user_id) {
    $full = get_user_meta($user_id, 'bccm_astro_full_chart', true);
    if (!empty($full) && is_array($full)) return $full;

    $birth = get_user_meta($user_id, 'bccm_astro_birth_data', true);
    if (!empty($birth) && is_array($birth)) return ['birth_data' => $birth];

    return null;
}

/* =====================================================================
 * TRANSIENT-BASED PRE-REGISTRATION FLOW
 * =====================================================================*/

/**
 * Save astro form data to transient (before user registers)
 *
 * @param string $session_key  Unique session identifier
 * @param array  $form_data    Form input data
 * @return string  The session key
 */
function bccm_astro_save_transient($session_key, $form_data) {
    if (empty($session_key)) {
        $session_key = 'bccm_astro_' . wp_generate_uuid4();
    }

    set_transient($session_key, $form_data, 2 * HOUR_IN_SECONDS);

    // Also set a cookie so we can retrieve after registration
    if (!headers_sent()) {
        setcookie('bccm_astro_session', $session_key, time() + 7200, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }

    return $session_key;
}

/**
 * Load astro form data from transient
 *
 * @param string $session_key  If empty, tries cookie
 * @return array|null
 */
function bccm_astro_load_transient($session_key = '') {
    if (empty($session_key) && !empty($_COOKIE['bccm_astro_session'])) {
        $session_key = sanitize_text_field($_COOKIE['bccm_astro_session']);
    }

    if (empty($session_key)) return null;

    $data = get_transient($session_key);
    return is_array($data) ? $data : null;
}

/**
 * Clear astro transient and cookie after successful save
 *
 * @param string $session_key
 */
function bccm_astro_clear_transient($session_key = '') {
    if (empty($session_key) && !empty($_COOKIE['bccm_astro_session'])) {
        $session_key = sanitize_text_field($_COOKIE['bccm_astro_session']);
    }

    if ($session_key) {
        delete_transient($session_key);
    }

    if (!headers_sent()) {
        setcookie('bccm_astro_session', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }
}

/* =====================================================================
 * AI PROMPT BUILDER – Chiêm tinh cho coaching
 * =====================================================================*/

/**
 * Build AI prompt context from astrology data
 * Dùng để AI agents tạo coaching plan dựa trên bản đồ chiêm tinh
 *
 * @param array $chart_data  Full chart data
 * @param string $coach_type  biz_coach, mental_coach, etc.
 * @return string  Prompt context
 */
function bccm_astro_build_coaching_prompt($chart_data, $coach_type = 'biz_coach') {
    $parsed = $chart_data['parsed'] ?? [];
    $positions = $parsed['positions'] ?? [];
    $aspects = $chart_data['aspects'] ?? [];

    $signs = bccm_zodiac_signs();
    $planet_vi = bccm_planet_names_vi();

    // Build planet positions text
    $planets_text = '';
    $key_planets = ['Sun', 'Moon', 'Ascendant', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn'];
    foreach ($key_planets as $pname) {
        if (isset($positions[$pname])) {
            $p = $positions[$pname];
            $retro = $p['is_retro'] ? ' (nghịch hành)' : '';
            $planets_text .= "- {$p['planet_vi']}: {$p['sign_vi']} {$p['sign_symbol']}{$retro} ({$p['norm_degree']}°)\n";
        }
    }

    // Build key aspects text
    $aspects_text = '';
    $aspect_vi = bccm_aspect_names_vi();
    $count = 0;
    // Enrich aspects with calculated orb
    $enriched_prompt = !empty($positions) ? bccm_astro_enrich_aspects($aspects, $positions) : [];
    if (!empty($enriched_prompt)) {
      foreach ($enriched_prompt as $asp_e) {
        if ($count >= 15) break;
        $p1_vi = $planet_vi[$asp_e['planet_1_en']] ?? $asp_e['planet_1_en'];
        $p2_vi = $planet_vi[$asp_e['planet_2_en']] ?? $asp_e['planet_2_en'];
        $type_vi = $aspect_vi[$asp_e['aspect_en']] ?? $asp_e['aspect_en'];
        $orb_str = $asp_e['orb'] !== null ? number_format($asp_e['orb'], 2) : '?';
        $aspects_text .= "- {$p1_vi} {$type_vi} {$p2_vi} (orb: {$orb_str}°)\n";
        $count++;
      }
    } else {
      foreach ($aspects as $asp) {
        if ($count >= 10) break;
        $p1   = is_array($asp['planet_1'] ?? null) ? ($asp['planet_1']['en'] ?? '') : ($asp['planet_1'] ?? '');
        $p2   = is_array($asp['planet_2'] ?? null) ? ($asp['planet_2']['en'] ?? '') : ($asp['planet_2'] ?? '');
        $type = is_array($asp['aspect'] ?? null)   ? ($asp['aspect']['en'] ?? '')   : ($asp['aspect'] ?? '');
        $p1_vi = $planet_vi[$p1] ?? $p1;
        $p2_vi = $planet_vi[$p2] ?? $p2;
        $type_vi = $aspect_vi[$type] ?? $type;
        $aspects_text .= "- {$p1_vi} {$type_vi} {$p2_vi}\n";
        $count++;
      }
    }

    $sun_vi = $signs[array_search($parsed['sun_sign'] ?? '', array_column($signs, 'en')) + 1]['vi'] ?? $parsed['sun_sign'] ?? '';
    $moon_vi = $signs[array_search($parsed['moon_sign'] ?? '', array_column($signs, 'en')) + 1]['vi'] ?? $parsed['moon_sign'] ?? '';
    $asc_vi = $signs[array_search($parsed['ascendant_sign'] ?? '', array_column($signs, 'en')) + 1]['vi'] ?? $parsed['ascendant_sign'] ?? '';

    $coach_labels = [
        'biz_coach'     => 'kinh doanh, khởi nghiệp',
        'mental_coach'  => 'tâm lý, phát triển bản thân',
        'tiktok_coach'  => 'sáng tạo nội dung TikTok',
        'health_coach'  => 'sức khỏe, dinh dưỡng',
        'baby_coach'    => 'nuôi dạy con',
        'astro_coach'   => 'chiêm tinh, tâm linh',
        'tarot_coach'   => 'tarot, tâm linh',
        'edu_coach'     => 'giáo dục, học tập',
        'marketing_coach' => 'marketing, truyền thông',
        'action_coach'  => 'hành động, kỷ luật',
    ];
    $coach_context = $coach_labels[$coach_type] ?? 'phát triển bản thân';

    $prompt = <<<PROMPT
## BẢN ĐỒ CHIÊM TINH CÁ NHÂN

**Tam giác chiêm tinh (Big 3):**
- Mặt Trời: {$sun_vi} → Bản ngã, ý chí, mục đích sống
- Mặt Trăng: {$moon_vi} → Cảm xúc, nhu cầu nội tâm
- Cung Mọc: {$asc_vi} → Hình ảnh bên ngoài, cách tiếp cận cuộc sống

**Vị trí các hành tinh:**
{$planets_text}

**Các góc chiếu quan trọng:**
{$aspects_text}

**Ngữ cảnh coaching:** Lĩnh vực {$coach_context}

Dựa trên bản đồ chiêm tinh trên, hãy phân tích điểm mạnh, điểm yếu, cơ hội phát triển và thách thức cần vượt qua trong lĩnh vực {$coach_context}. Đưa ra lời khuyên cụ thể, thực tế và có thể hành động ngay.
PROMPT;

    return $prompt;
}

/* =====================================================================
 * PDF EXPORT – Print-ready Natal Chart Page
 * =====================================================================*/
add_action('wp_ajax_bccm_natal_pdf', 'bccm_natal_pdf_handler');

function bccm_natal_pdf_handler() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_ajax_referer('bccm_natal_pdf', '_wpnonce');

    $coachee_id = intval($_GET['coachee_id'] ?? 0);
    if (!$coachee_id) wp_die('Missing coachee_id');

    global $wpdb;
    $t = bccm_tables();
    $coachee = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
    if (!$coachee) wp_die('Coachee not found');

    $astro_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d", $coachee_id), ARRAY_A);
    if (!$astro_row) wp_die('No astro data');

    $summary = json_decode($astro_row['summary'] ?? '{}', true) ?: [];
    $traits  = json_decode($astro_row['traits'] ?? '{}', true) ?: [];
    $positions = $traits['positions'] ?? [];
    $houses_data = $traits['houses'] ?? [];
    $aspects = $traits['aspects'] ?? [];
    $birth_data = $traits['birth_data'] ?? [];
    $signs = bccm_zodiac_signs();
    $planet_vi = bccm_planet_names_vi();
    $aspect_vi = bccm_aspect_names_vi();
    $aspect_symbols_map = bccm_aspect_symbols();
    $aspect_colors_map = bccm_aspect_colors();
    $house_meanings = bccm_house_meanings_vi();

    // Parse houses raw
    $houses_raw = [];
    if (!empty($houses_data)) {
      if (isset($houses_data[0]['House']) || isset($houses_data[0]['house'])) {
        $houses_raw = $houses_data;
      } elseif (isset($houses_data['Houses'])) {
        $houses_raw = $houses_data['Houses'];
      }
    }

    // Enrich aspects
    $enriched = bccm_astro_enrich_aspects($aspects, $positions);
    $grouped  = bccm_astro_group_aspects_by_planet($enriched);

    $find_sign = function($name) use ($signs) {
      foreach ($signs as $s) {
        if (strtolower($s['en'] ?? '') === strtolower($name)) return $s;
      }
      return ['vi' => $name, 'symbol' => '?', 'en' => $name, 'element' => ''];
    };

    $planet_symbols = [
      'Sun' => '☉', 'Moon' => '☽', 'Mercury' => '☿', 'Venus' => '♀', 'Mars' => '♂',
      'Jupiter' => '♃', 'Saturn' => '♄', 'Uranus' => '♅', 'Neptune' => '♆', 'Pluto' => '♇',
      'Chiron' => '⚷', 'Lilith' => '⚸', 'True Node' => '☊', 'Mean Node' => '☊',
      'Ascendant' => 'ASC', 'Descendant' => 'DSC', 'MC' => 'MC', 'IC' => 'IC',
      'Ceres' => '⚳', 'Vesta' => '⚶', 'Juno' => '⚵', 'Pallas' => '⚴',
    ];

    $planet_order = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Chiron','Lilith','True Node','Mean Node','Ascendant','Descendant','MC','IC','Ceres','Vesta','Juno','Pallas'];

    $chart_url = $astro_row['chart_svg'] ?? $summary['chart_url'] ?? '';

    // Build DOB display
    $dob_display = '';
    if (!empty($birth_data['day']) && !empty($birth_data['month']) && !empty($birth_data['year'])) {
      $dob_display = sprintf('%02d/%02d/%04d', $birth_data['day'], $birth_data['month'], $birth_data['year']);
    } elseif (!empty($coachee['dob'])) {
      $dob_display = date('d/m/Y', strtotime($coachee['dob']));
    }

    // Render print-ready HTML
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Bản Đồ Sao - <?php echo esc_html($coachee['full_name'] ?? 'Natal Chart'); ?></title>
<style>
@page { size: A4; margin: 15mm 12mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 11px; color: #1a1a2e; line-height: 1.5; background: #fff; }
.page { max-width: 210mm; margin: 0 auto; padding: 8mm; }
.header { text-align: center; border-bottom: 3px solid #6366f1; padding-bottom: 10px; margin-bottom: 14px; }
.header h1 { font-size: 22px; color: #1a1a2e; margin-bottom: 4px; }
.header .subtitle { font-size: 12px; color: #6b7280; }
.header .meta { display: flex; justify-content: center; gap: 20px; margin-top: 6px; font-size: 11px; color: #4b5563; }
.big3 { display: flex; gap: 8px; margin: 12px 0; }
.big3-card { flex: 1; text-align: center; padding: 12px 8px; border-radius: 10px; color: #fff; }
.big3-card.sun { background: linear-gradient(135deg, #1a1a2e, #2d1b69); }
.big3-card.moon { background: linear-gradient(135deg, #1a2e2e, #1b4d69); }
.big3-card.asc { background: linear-gradient(135deg, #2e1a2e, #691b4d); }
.big3-card .label { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
.big3-card .symbol { font-size: 26px; margin: 4px 0; }
.big3-card .name { font-size: 15px; font-weight: 700; color: #fbbf24; }
.big3-card .sub { font-size: 10px; color: #94a3b8; }
.big3-card .desc { font-size: 9px; color: #6ee7b7; margin-top: 3px; }
.chart-img { text-align: center; margin: 14px 0; }
.chart-img img { max-width: 380px; border-radius: 10px; }
.section { margin-top: 14px; page-break-inside: avoid; }
.section h2 { font-size: 14px; font-weight: 700; color: #1e293b; border-bottom: 2px solid #e5e7eb; padding-bottom: 4px; margin-bottom: 8px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 10.5px; }
th { background: #f1f5f9; color: #334155; font-weight: 600; text-align: left; padding: 5px 6px; border-bottom: 2px solid #e2e8f0; }
td { padding: 4px 6px; border-bottom: 1px solid #f1f5f9; }
tr:nth-child(even) td { background: #fafbfc; }
.mono { font-family: 'Courier New', monospace; font-size: 10px; }
.retro { color: #ef4444; font-weight: 700; }
.house-angular td { background: #f0f4ff !important; font-weight: 500; }
.aspect-group-header td { background: #f1f5f9 !important; font-weight: 700; padding-top: 8px; border-top: 2px solid #e2e8f0; }
.aspect-legend { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; font-size: 9px; }
.aspect-legend span { display: inline-flex; align-items: center; gap: 2px; }
.orb-exact { color: #059669; font-weight: 700; }
.orb-close { color: #2563eb; }
.stats { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.stats span { display: inline-flex; align-items: center; gap: 2px; padding: 2px 8px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 99px; font-size: 9px; }
.separator td { background: #e5e7eb !important; height: 1px; padding: 0; }
.footer { margin-top: 16px; text-align: center; color: #9ca3af; font-size: 9px; border-top: 1px solid #e5e7eb; padding-top: 8px; }
.bccm-aspect-grid-wrap { overflow-x: auto; margin: 0 auto; }
.bccm-aspect-grid { border-collapse: collapse; margin: 0 auto; font-size: 12px; }
.bccm-aspect-grid th { width: 26px; height: 26px; text-align: center; font-size: 13px; font-weight: 600; color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; padding: 2px; }
.bccm-aspect-grid td { width: 26px; height: 26px; text-align: center; font-size: 12px; font-weight: 700; border: 1px solid #e2e8f0; padding: 1px; }
.bccm-aspect-grid td.bccm-grid-empty { background: #f1f5f9; }
.bccm-aspect-grid td.bccm-grid-none::after { content: '·'; color: #d1d5db; }
.pdf-patterns-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
.pdf-pattern-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; }
.pdf-pattern-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.pdf-pattern-icon { font-size: 22px; }
.pdf-pattern-type { font-weight: 700; font-size: 12px; color: #1e293b; }
.pdf-pattern-planet { font-size: 10px; color: #475569; padding: 1px 6px; background: #eef2ff; border-radius: 4px; display: inline-block; margin: 1px 0; }
.pdf-pattern-desc { font-size: 9.5px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 6px; margin-top: 4px; line-height: 1.4; }
.pdf-special-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.pdf-special-card { display: flex; align-items: flex-start; gap: 8px; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
.pdf-special-icon { font-size: 20px; flex-shrink: 0; }
.pdf-special-main { margin: 0; font-weight: 600; font-size: 11px; color: #1e293b; }
.pdf-special-sub { margin: 2px 0 0; font-size: 10px; color: #64748b; }
@media print {
  body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  .no-print { display: none !important; }
}
</style>
</head>
<body>
<div class="no-print" style="text-align:center;padding:12px;background:#f0f4ff;border-bottom:2px solid #6366f1">
  <button onclick="window.print()" style="padding:10px 28px;background:#6366f1;color:#fff;border:none;border-radius:8px;font-size:14px;cursor:pointer;font-weight:600">🖨️ In / Lưu PDF (Ctrl+P)</button>
  <span style="margin-left:12px;color:#6b7280;font-size:13px">Chọn "Save as PDF" trong hộp thoại in để lưu file PDF</span>
</div>

<div class="page">
  <!-- HEADER -->
  <div class="header">
    <h1>🌟 Bản Đồ Sao Cá Nhân</h1>
    <div class="subtitle">Natal Chart — BizCoach Map</div>
    <div class="meta">
      <?php if (!empty($coachee['full_name'])): ?><span>👤 <?php echo esc_html($coachee['full_name']); ?></span><?php endif; ?>
      <?php if ($dob_display): ?><span>📅 <?php echo esc_html($dob_display); ?></span><?php endif; ?>
      <?php if (!empty($astro_row['birth_time'])): ?><span>🕐 <?php echo esc_html($astro_row['birth_time']); ?></span><?php endif; ?>
      <?php if (!empty($astro_row['birth_place'])): ?><span>📍 <?php echo esc_html($astro_row['birth_place']); ?></span><?php endif; ?>
    </div>
  </div>

  <!-- BIG 3 -->
  <?php
  $sun = $summary['sun_sign'] ?? '';
  $moon = $summary['moon_sign'] ?? '';
  $asc = $summary['ascendant_sign'] ?? '';
  if ($sun || $moon || $asc):
    $big3 = [
      ['label' => '☀️ Mặt Trời (Sun)', 'sign' => $sun, 'cls' => 'sun', 'desc' => 'Bản ngã, ý chí, mục đích sống'],
      ['label' => '🌙 Mặt Trăng (Moon)', 'sign' => $moon, 'cls' => 'moon', 'desc' => 'Cảm xúc, nhu cầu nội tâm'],
      ['label' => '⬆️ Cung Mọc (ASC)', 'sign' => $asc, 'cls' => 'asc', 'desc' => 'Ấn tượng đầu tiên, vẻ ngoài'],
    ];
  ?>
  <div class="big3">
    <?php foreach ($big3 as $b):
      $info = $find_sign($b['sign']);
    ?>
    <div class="big3-card <?php echo $b['cls']; ?>">
      <div class="label"><?php echo $b['label']; ?></div>
      <div class="symbol"><?php echo esc_html($info['symbol'] ?? '?'); ?></div>
      <div class="name"><?php echo esc_html($info['vi'] ?? $b['sign']); ?></div>
      <div class="sub"><?php echo esc_html($info['en'] ?? ''); ?></div>
      <div class="desc"><?php echo esc_html($b['desc']); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- NATAL WHEEL -->
  <?php if ($chart_url): ?>
  <div class="chart-img">
    <img src="<?php echo esc_url($chart_url); ?>" alt="Natal Wheel Chart"/>
    <div style="font-size:9px;color:#9ca3af;margin-top:4px">Natal Wheel Chart — Hệ thống Placidus (Free Astrology API)</div>
  </div>
  <?php endif; ?>

  <!-- ASTROVIET CHARTS -->
  <?php
  $astroviet_wheel_url = bccm_build_astroviet_wheel_url($positions, $houses_raw, $coachee['full_name'] ?? '', array_merge($birth_data, [
    'birth_place' => $astro_row['birth_place'] ?? '',
    'latitude'    => $astro_row['latitude'] ?? ($birth_data['latitude'] ?? 0),
    'longitude'   => $astro_row['longitude'] ?? ($birth_data['longitude'] ?? 0),
  ]));
  $astroviet_grid_url = bccm_build_astroviet_aspect_grid_url($positions, $houses_raw, $birth_data);
  $native_grid = bccm_render_aspect_grid_html($positions, $aspects);
  ?>
  <?php if ($astroviet_wheel_url || $astroviet_grid_url): ?>
  <div class="section" style="text-align:center">
    <h2>🗺️ Bản Đồ AstroViet</h2>
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
      <?php if ($astroviet_wheel_url): ?>
      <div style=";text-align:center">
        <img src="<?php echo esc_url($astroviet_wheel_url); ?>" alt="AstroViet Natal Wheel" style="max-width:100%;border-radius:8px"/>
        <div style="font-size:9px;color:#9ca3af;margin-top:4px">Natal Wheel — AstroViet</div>
      </div>
      <?php endif; ?>
      <?php if ($astroviet_grid_url): ?>
      <div style="text-align:center">
        <img src="<?php echo esc_url($astroviet_grid_url); ?>" alt="AstroViet Aspect Grid" style="max-width:100%;border-radius:8px"/>
        <div style="font-size:9px;color:#9ca3af;margin-top:4px">Aspect Grid — AstroViet</div>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($native_grid): ?>
    <div style="margin-top:14px">
      <h3 style="font-size:12px;margin-bottom:6px;color:#334155">📊 Lưới Góc Chiếu</h3>
      <?php echo $native_grid; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- PLANETS TABLE -->
  <?php if (!empty($positions)): ?>
  <div class="section">
    <h2>🪐 Vị Trí Các Hành Tinh</h2>
    <table>
      <thead><tr><th>Hành tinh</th><th>Cung</th><th>Vị trí</th><th>Nhà</th><th>Nghịch hành</th></tr></thead>
      <tbody>
      <?php foreach ($planet_order as $pname):
        if (!isset($positions[$pname])) continue;
        $p = $positions[$pname];
        $sym = $planet_symbols[$pname] ?? '';
        $dms = bccm_astro_decimal_to_dms($p['norm_degree'] ?? 0);
        $house_num = '';
        if (!empty($houses_raw) && !in_array($pname, ['Ascendant','Descendant','MC','IC'])) {
          $h = bccm_astro_planet_in_house($p['full_degree'] ?? 0, $houses_raw);
          $house_num = $h > 0 ? $h : '';
        }
      ?>
      <tr>
        <td><?php echo $sym; ?> <strong><?php echo esc_html($p['planet_vi'] ?? $pname); ?></strong></td>
        <td><?php echo esc_html(($p['sign_symbol'] ?? '') . ' ' . ($p['sign_vi'] ?? '')); ?></td>
        <td class="mono"><?php echo esc_html($dms); ?></td>
        <td style="text-align:center;color:#6366f1;font-weight:600"><?php echo $house_num ?: '—'; ?></td>
        <td style="text-align:center"><?php echo ($p['is_retro'] ? '<span class="retro">℞</span>' : '—'); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- HOUSES TABLE -->
  <?php if (!empty($houses_raw)): ?>
  <div class="section">
    <h2>🏛️ 12 Cung Nhà (Hệ thống Placidus)</h2>
    <table>
      <thead><tr><th style="width:10%">Nhà</th><th style="width:20%">Cung</th><th style="width:25%">Đỉnh cung</th><th>Ý nghĩa</th></tr></thead>
      <tbody>
      <?php foreach ($houses_raw as $h):
        $num = $h['House'] ?? ($h['house'] ?? 0);
        if ($num < 1) continue;
        $sign_num = $h['zodiac_sign']['number'] ?? 0;
        $sign_vi_h = $signs[$sign_num]['vi'] ?? '';
        $symbol_h = $signs[$sign_num]['symbol'] ?? '';
        $norm_deg = $h['normDegree'] ?? ($h['degree'] ?? 0);
        $dms = bccm_astro_decimal_to_dms($norm_deg);
        $meaning = $house_meanings[$num] ?? '';
        $angular = in_array($num, [1,4,7,10]);
      ?>
      <tr<?php echo $angular ? ' class="house-angular"' : ''; ?>>
        <td style="text-align:center;font-weight:700;color:#6366f1"><?php echo intval($num); ?></td>
        <td><?php echo esc_html($symbol_h . ' ' . $sign_vi_h); ?></td>
        <td class="mono"><?php echo esc_html($dms); ?></td>
        <td style="color:#6b7280;font-size:10px"><?php echo esc_html($meaning); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ASPECTS TABLE -->
  <?php if (!empty($enriched)): ?>
  <div class="section" style="page-break-before:auto">
    <h2>🔗 Góc Chiếu Giữa Các Hành Tinh (<?php echo count($enriched); ?> aspects)</h2>
    <div class="aspect-legend">
      <?php foreach ($aspect_vi as $aen => $avi):
        $c = $aspect_colors_map[$aen] ?? '#888';
        $s = $aspect_symbols_map[$aen] ?? '';
      ?>
      <span><span style="color:<?php echo $c; ?>;font-weight:700"><?php echo $s; ?></span> <?php echo esc_html($avi); ?></span>
      <?php endforeach; ?>
    </div>
    <table>
      <thead><tr><th>Hành tinh 1</th><th style="width:6%"></th><th>Góc chiếu</th><th>Hành tinh 2</th><th>Orb</th></tr></thead>
      <tbody>
      <?php foreach ($grouped as $planet_key => $planet_aspects):
        $pvi = $planet_vi[$planet_key] ?? $planet_key;
      ?>
      <tr class="aspect-group-header"><td colspan="5"><?php echo esc_html($pvi); ?> (<?php echo count($planet_aspects); ?>)</td></tr>
      <?php foreach ($planet_aspects as $asp):
        $type_en = $asp['aspect_en'];
        $type_vi_a = $aspect_vi[$type_en] ?? $type_en;
        $p2_vi_a = $planet_vi[$asp['planet_2_en']] ?? $asp['planet_2_en'];
        $sym_a = $aspect_symbols_map[$type_en] ?? '';
        $color_a = $aspect_colors_map[$type_en] ?? '#888';
        $orb_val = $asp['orb'];
        $orb_display = $orb_val !== null ? bccm_astro_decimal_to_dms($orb_val, true) : '—';
        $orb_cls = '';
        if ($orb_val !== null && $orb_val < 1) $orb_cls = 'orb-exact';
        elseif ($orb_val !== null && $orb_val < 3) $orb_cls = 'orb-close';
      ?>
      <tr>
        <td style="padding-left:16px;color:#6b7280"><?php echo esc_html($planet_vi[$asp['planet_1_en']] ?? $asp['planet_1_en']); ?></td>
        <td style="text-align:center;color:<?php echo $color_a; ?>"><?php echo $sym_a; ?></td>
        <td style="color:<?php echo $color_a; ?>;font-weight:500"><?php echo esc_html($type_vi_a); ?></td>
        <td><?php echo esc_html($p2_vi_a); ?></td>
        <td class="mono <?php echo $orb_cls; ?>"><?php echo esc_html($orb_display); ?></td>
      </tr>
      <?php endforeach; endforeach; ?>
      </tbody>
    </table>

    <!-- Statistics -->
    <div class="stats">
      <?php
      $stats = [];
      foreach ($enriched as $asp_s) {
        $type_s = $asp_s['aspect_en'];
        if (!isset($stats[$type_s])) $stats[$type_s] = 0;
        $stats[$type_s]++;
      }
      foreach ($stats as $type_s => $count_s):
        $c = $aspect_colors_map[$type_s] ?? '#888';
        $s = $aspect_symbols_map[$type_s] ?? '';
      ?>
      <span><span style="color:<?php echo $c; ?>;font-weight:700"><?php echo $s; ?></span> <?php echo esc_html($aspect_vi[$type_s] ?? $type_s); ?> <strong><?php echo $count_s; ?></strong></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- CHART PATTERNS -->
  <?php if (!empty($aspects) && !empty($positions)):
    $chart_patterns_pdf = bccm_detect_chart_patterns($positions, $aspects);
    if (!empty($chart_patterns_pdf)):
  ?>
  <div class="section">
    <h2>🔷 Mô Hình Bản Đồ (Chart Patterns)</h2>
    <div class="pdf-patterns-grid">
      <?php foreach ($chart_patterns_pdf as $pattern):
        $planet_list_pdf = [];
        foreach ($pattern['planets'] as $pn) {
          $pvi_pdf = $planet_vi[$pn] ?? $pn;
          $sign_vi_pdf = $positions[$pn]['sign_vi'] ?? '';
          $norm_deg_pdf = $positions[$pn]['norm_degree'] ?? 0;
          $planet_list_pdf[] = "$pvi_pdf trong " . floor($norm_deg_pdf) . "° $sign_vi_pdf";
        }
      ?>
      <div class="pdf-pattern-card">
        <div class="pdf-pattern-header">
          <span class="pdf-pattern-icon"><?php echo $pattern['icon'] ?? '🔷'; ?></span>
          <span class="pdf-pattern-type"><?php echo esc_html($pattern['type_vi']); ?></span>
        </div>
        <div>
          <?php foreach ($planet_list_pdf as $pl): ?>
          <div class="pdf-pattern-planet"><?php echo esc_html($pl); ?></div>
          <?php endforeach; ?>
        </div>
        <div class="pdf-pattern-desc"><?php echo esc_html($pattern['description']); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; endif; ?>

  <!-- SPECIAL FEATURES -->
  <?php if (!empty($positions)):
    $special_features_pdf = bccm_analyze_special_features($positions, $aspects ?? [], $houses_raw, $birth_data);
    if (!empty($special_features_pdf)):
  ?>
  <div class="section">
    <h2>✨ Đặc Điểm Nổi Bật (Special Features)</h2>
    <div class="pdf-special-grid">
      <?php foreach ($special_features_pdf as $feature): ?>
      <div class="pdf-special-card">
        <div class="pdf-special-icon"><?php echo $feature['icon'] ?? '✨'; ?></div>
        <div>
          <p class="pdf-special-main"><?php echo esc_html($feature['text']); ?></p>
          <?php if (!empty($feature['text_vi']) && $feature['text_vi'] !== $feature['text']): ?>
          <p class="pdf-special-sub"><?php echo esc_html($feature['text_vi']); ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; endif; ?>

  <!-- FOOTER -->
  <div class="footer">
    Bản đồ sao được tạo bởi BizCoach Map | Dữ liệu từ Free Astrology API | Hệ thống Placidus — Tropical<br/>
    Ngày tạo: <?php echo esc_html($summary['fetched_at'] ?? current_time('d/m/Y H:i')); ?> | © <?php echo date('Y'); ?> BizCoach Map
  </div>
</div>

<script>
// Auto-trigger print dialog after 500ms
setTimeout(function(){ /* window.print(); */ }, 500);
</script>
</body>
</html>
    <?php
    exit;
}
