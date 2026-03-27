<?php
/**
 * BizCoach Map – Prokerala Astrology API v2 Client
 *
 * OAuth2 authenticated client for Prokerala API v2
 * Endpoints: natal-planet-position, chart, report/personal-reading/instant
 *
 * @package BizCoach_Map
 * @since   0.1.0.15
 * @see     https://api.prokerala.com/docs
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * CONSTANTS
 * =====================================================================*/
define('BCCM_PROKERALA_API_BASE', 'https://api.prokerala.com/');
define('BCCM_PROKERALA_TOKEN_URL', 'https://api.prokerala.com/token');

/* =====================================================================
 * CREDENTIALS & AUTH
 * =====================================================================*/

/**
 * Get Prokerala API credentials
 * @return array ['client_id' => '', 'client_secret' => '']
 */
function bccm_get_prokerala_credentials() {
    return [
        'client_id'     => get_option('bccm_prokerala_client_id', ''),
        'client_secret' => get_option('bccm_prokerala_client_secret', ''),
    ];
}

/**
 * Get Prokerala OAuth2 access token (cached in transient)
 *
 * @return string|WP_Error  Access token or error
 */
function bccm_prokerala_get_token() {
    // Check transient cache first
    $cached = get_transient('bccm_prokerala_token');
    if (!empty($cached)) {
        return $cached;
    }

    $creds = bccm_get_prokerala_credentials();
    if (empty($creds['client_id']) || empty($creds['client_secret'])) {
        return new WP_Error('no_prokerala_creds', 'Chưa cấu hình Prokerala Client ID / Secret. Vào Settings → Astrology để nhập.');
    }

    $response = wp_remote_post(BCCM_PROKERALA_TOKEN_URL, [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body'    => [
            'grant_type'    => 'client_credentials',
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('[BCCM Prokerala] Token error: ' . $response->get_error_message());
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200 || empty($body['access_token'])) {
        $msg = $body['errors'][0]['detail'] ?? $body['message'] ?? "HTTP $code";
        error_log("[BCCM Prokerala] Token failed ($code): $msg");
        return new WP_Error('prokerala_auth_failed', "Prokerala OAuth2 lỗi: $msg");
    }

    $token   = $body['access_token'];
    $expires = intval($body['expires_in'] ?? 3600) - 60; // Cache with 60s margin

    set_transient('bccm_prokerala_token', $token, max($expires, 300));

    return $token;
}

/* =====================================================================
 * API CALLERS (GET & POST)
 * =====================================================================*/

/**
 * Call Prokerala API (authenticated GET request)
 *
 * @param string $endpoint  E.g. 'v2/astrology/natal-chart'
 * @param array  $params    Query parameters
 * @param int    $timeout   Seconds
 * @return array|string|WP_Error  Decoded JSON or SVG string or error
 */
function bccm_prokerala_api_call($endpoint, $params = [], $timeout = 30) {
    $token = bccm_prokerala_get_token();
    if (is_wp_error($token)) return $token;

    $url = BCCM_PROKERALA_API_BASE . ltrim($endpoint, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $response = wp_remote_get($url, [
        'timeout' => $timeout,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('[BCCM Prokerala] API error: ' . $response->get_error_message());
        return $response;
    }

    $code        = wp_remote_retrieve_response_code($response);
    $body        = wp_remote_retrieve_body($response);
    $contentType = wp_remote_retrieve_header($response, 'content-type');

    // If SVG is returned directly
    if (strpos($contentType, 'image/svg') !== false) {
        if ($code === 200) return ['chart_svg' => $body];
        return new WP_Error('prokerala_svg_error', "Prokerala SVG error: HTTP $code");
    }

    $data = json_decode($body, true);

    if ($code === 401) {
        // Token expired – clear cache and retry once
        delete_transient('bccm_prokerala_token');
        $token = bccm_prokerala_get_token();
        if (is_wp_error($token)) return $token;

        $response = wp_remote_get($url, [
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $contentType = wp_remote_retrieve_header($response, 'content-type');

        if (strpos($contentType, 'image/svg') !== false && $code === 200) {
            return ['chart_svg' => $body];
        }
        $data = json_decode($body, true);
    }

    if ($code !== 200) {
        $msg = $data['errors'][0]['detail'] ?? $data['message'] ?? "HTTP $code";
        error_log("[BCCM Prokerala] API error ($code): $msg");
        return new WP_Error('prokerala_api_error', "Prokerala API lỗi: $msg", ['http_code' => $code]);
    }

    if (!is_array($data)) {
        return new WP_Error('prokerala_invalid_response', 'Prokerala: Dữ liệu trả về không hợp lệ.');
    }

    return $data;
}

/**
 * POST request to Prokerala API (for report endpoints)
 *
 * @param string $endpoint  E.g. 'v2/report/personal-reading/instant'
 * @param array  $body      JSON body
 * @param int    $timeout   Seconds
 * @param string $accept    Accept header ('application/json' or 'application/pdf')
 * @return array|string|WP_Error  JSON data, raw body (PDF), or error
 */
function bccm_prokerala_api_post($endpoint, $body = [], $timeout = 60, $accept = 'application/pdf') {
    $token = bccm_prokerala_get_token();
    if (is_wp_error($token)) return $token;

    $url = BCCM_PROKERALA_API_BASE . ltrim($endpoint, '/');

    $response = wp_remote_post($url, [
        'timeout' => $timeout,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => $accept,
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        error_log('[BCCM Prokerala POST] API error: ' . $response->get_error_message());
        return $response;
    }

    $code        = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);
    $contentType = wp_remote_retrieve_header($response, 'content-type');

    // Handle 401 – token expired
    if ($code === 401) {
        delete_transient('bccm_prokerala_token');
        $token = bccm_prokerala_get_token();
        if (is_wp_error($token)) return $token;

        $response = wp_remote_post($url, [
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => $accept,
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) return $response;
        $code        = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $contentType = wp_remote_retrieve_header($response, 'content-type');
    }

    if ($code !== 200) {
        $data = json_decode($raw_body, true);
        $msg = $data['errors'][0]['detail'] ?? $data['message'] ?? "HTTP $code";
        error_log("[BCCM Prokerala POST] ($code): $msg");
        return new WP_Error('prokerala_post_error', "Prokerala API lỗi: $msg", ['http_code' => $code]);
    }

    // PDF response → return raw bytes
    if (strpos($contentType, 'application/pdf') !== false) {
        return $raw_body;
    }

    // JSON response
    $data = json_decode($raw_body, true);
    return is_array($data) ? $data : $raw_body;
}

/* =====================================================================
 * HELPERS
 * =====================================================================*/

/**
 * Build ISO 8601 datetime string from birth_data
 *
 * @param array $birth_data  Keys: year, month, day, hour, minute, second, timezone
 * @return string  e.g. "1989-03-08T08:05:00+07:00"
 */
function bccm_prokerala_build_datetime($birth_data) {
    $y = intval($birth_data['year'] ?? 1990);
    $m = intval($birth_data['month'] ?? 1);
    $d = intval($birth_data['day'] ?? 1);
    $h = intval($birth_data['hour'] ?? 12);
    $i = intval($birth_data['minute'] ?? 0);
    $s = intval($birth_data['second'] ?? 0);
    $tz = floatval($birth_data['timezone'] ?? 7);

    // Build timezone offset string (+07:00, -05:30, etc.)
    $tz_sign = $tz >= 0 ? '+' : '-';
    $tz_abs  = abs($tz);
    $tz_h    = intval($tz_abs);
    $tz_m    = intval(($tz_abs - $tz_h) * 60);
    $tz_str  = sprintf('%s%02d:%02d', $tz_sign, $tz_h, $tz_m);

    return sprintf('%04d-%02d-%02dT%02d:%02d:%02d%s', $y, $m, $d, $h, $i, $s, $tz_str);
}

/* =====================================================================
 * ENDPOINT WRAPPERS
 * =====================================================================*/

/**
 * Fetch natal chart from Prokerala API v2
 *
 * @param array $birth_data  Keys: year, month, day, hour, minute, second, latitude, longitude, timezone
 * @param array $options     Optional overrides: chart_type, chart_style, ayanamsa
 * @return array|WP_Error    ['chart_svg' => '...SVG content...'] or decoded JSON data
 */
function bccm_prokerala_get_natal_chart($birth_data, $options = []) {
    $datetime    = bccm_prokerala_build_datetime($birth_data);
    $lat         = floatval($birth_data['latitude'] ?? 21.0285);
    $lng         = floatval($birth_data['longitude'] ?? 105.8542);
    $coordinates = "$lat,$lng";

    $params = [
        'profile' => [
            'datetime'    => $datetime,
            'coordinates' => $coordinates,
        ],
        'ayanamsa'     => $options['ayanamsa'] ?? 0,       // 0 = None (Tropical/Western), match Free API & AstroViet
        'house_system' => $options['house_system'] ?? 'placidus', // Placidus to match Free API & AstroViet
    ];

    // Optional chart customization
    if (!empty($options['chart_type'])) {
        $params['chart_type'] = $options['chart_type']; // rasi, north-indian, south-indian, east-indian
    }
    if (!empty($options['chart_style'])) {
        $params['chart_style'] = $options['chart_style'];
    }

    return bccm_prokerala_api_call('v2/astrology/natal-chart', $params);
}

/**
 * Fetch natal chart SVG and return clean SVG content
 * Tries Prokerala API; returns SVG string or WP_Error
 *
 * @param array $birth_data
 * @return string|WP_Error  SVG content string or error
 */
function bccm_prokerala_fetch_chart_svg($birth_data) {
    $result = bccm_prokerala_get_natal_chart($birth_data);
    if (is_wp_error($result)) return $result;

    // Response may be SVG directly or JSON with chart data
    if (!empty($result['chart_svg'])) {
        return $result['chart_svg'];
    }

    // If JSON response, look for chart_url or svg field
    if (!empty($result['data']['chart_url'])) {
        // Fetch the SVG from the URL
        $svg_response = wp_remote_get($result['data']['chart_url'], ['timeout' => 15]);
        if (!is_wp_error($svg_response) && wp_remote_retrieve_response_code($svg_response) === 200) {
            return wp_remote_retrieve_body($svg_response);
        }
    }

    // Return the full data for display
    return $result;
}

/**
 * Fetch natal planet positions from Prokerala (Western astrology)
 * Endpoint: v2/astrology/natal-planet-position
 *
 * @param array $birth_data  Keys: year, month, day, hour, minute, second, latitude, longitude, timezone
 * @return array|WP_Error    API response data
 */
function bccm_prokerala_get_natal_positions($birth_data) {
    $datetime    = bccm_prokerala_build_datetime($birth_data);
    $lat         = floatval($birth_data['latitude'] ?? 21.0285);
    $lng         = floatval($birth_data['longitude'] ?? 105.8542);
    $coordinates = "$lat,$lng";

    $params = [
        'profile' => [
            'datetime'    => $datetime,
            'coordinates' => $coordinates,
        ],
        'ayanamsa'     => 0, // Tropical (Western)
        'house_system' => 'placidus',
        'orb'          => 'default',
        'birth_time_rectification' => 'flat-chart',
    ];

    return bccm_prokerala_api_call('v2/astrology/natal-planet-position', $params);
}

/**
 * Fetch chart SVG from Prokerala (Western style)
 * Endpoint: v2/astrology/chart
 *
 * @param array $birth_data
 * @param array $options     chart_type, chart_style overrides
 * @return string|WP_Error   SVG content
 */
function bccm_prokerala_get_chart_image($birth_data, $options = []) {
    $datetime    = bccm_prokerala_build_datetime($birth_data);
    $lat         = floatval($birth_data['latitude'] ?? 21.0285);
    $lng         = floatval($birth_data['longitude'] ?? 105.8542);
    $coordinates = "$lat,$lng";

    $params = [
        'profile' => [
            'datetime'    => $datetime,
            'coordinates' => $coordinates,
        ],
        'ayanamsa'     => 0,
        'house_system' => $options['house_system'] ?? 'placidus',
        'chart_type'   => $options['chart_type'] ?? 'zodiac',
        'chart_style'  => $options['chart_style'] ?? 'north-indian',
    ];

    $result = bccm_prokerala_api_call('v2/astrology/chart', $params);
    if (is_wp_error($result)) return $result;

    // May return SVG inline or JSON with chart_url
    if (!empty($result['chart_svg'])) {
        return $result['chart_svg'];
    }
    if (!empty($result['data']['chart_url'])) {
        $svg_resp = wp_remote_get($result['data']['chart_url'], ['timeout' => 15]);
        if (!is_wp_error($svg_resp) && wp_remote_retrieve_response_code($svg_resp) === 200) {
            return wp_remote_retrieve_body($svg_resp);
        }
    }
    // If we got image data directly (SVG string in response)
    if (is_string($result)) return $result;

    return new WP_Error('prokerala_chart_empty', 'Không nhận được biểu đồ SVG từ Prokerala.');
}

/* =====================================================================
 * PARSER – Convert Prokerala response to standard format
 * =====================================================================*/

/**
 * Parse Prokerala natal-planet-position response into our standard format
 * Compatible with bccm_astro_parse_planets() output
 *
 * @param array $api_data  Raw API response from bccm_prokerala_get_natal_positions()
 * @return array  Same structure as bccm_astro_parse_planets() output
 */
function bccm_prokerala_parse_positions($api_data) {
    $signs    = bccm_zodiac_signs();
    $vi_names = bccm_planet_names_vi();

    $result = [
        'sun_sign'       => '',
        'moon_sign'      => '',
        'ascendant_sign' => '',
        'positions'      => [],
    ];

    // Prokerala response can have different structures
    $planet_list = $api_data['data']['planet_positions']
                ?? $api_data['data']['natal_planet_positions']
                ?? $api_data['data']['planets']
                ?? $api_data['output']
                ?? [];

    // Map Prokerala planet IDs to English names
    $pk_id_map = [
        0 => 'Sun', 1 => 'Moon', 2 => 'Mercury', 3 => 'Venus', 4 => 'Mars',
        5 => 'Jupiter', 6 => 'Saturn', 7 => 'Uranus', 8 => 'Neptune', 9 => 'Pluto',
        101 => 'Ascendant', 102 => 'MC',
        10 => 'True Node', 11 => 'Chiron', 12 => 'Lilith',
    ];

    foreach ($planet_list as $p) {
        // Determine planet name
        $name = '';
        if (!empty($p['name'])) {
            $name = $p['name'];
        } elseif (isset($p['id']) && isset($pk_id_map[$p['id']])) {
            $name = $pk_id_map[$p['id']];
        } elseif (!empty($p['planet']['en'])) {
            $name = $p['planet']['en'];
        }
        if (!$name) continue;

        // Normalize common name variants
        $name_map = [
            'Rahu' => 'True Node', 'North Node' => 'True Node',
            'Ketu' => 'Descendant', 'South Node' => 'Descendant',
            'Asc'  => 'Ascendant', 'Midheaven' => 'MC',
        ];
        $name = $name_map[$name] ?? $name;

        // Determine sign
        $sign_name = $p['sign']['name'] ?? $p['zodiac_sign']['name'] ?? $p['zodiac_sign']['name']['en'] ?? '';
        $sign_id   = $p['sign']['id'] ?? $p['zodiac_sign']['number'] ?? $p['zodiac_sign']['id'] ?? 0;

        // If sign_id not found, try to resolve from sign name
        if (!$sign_id && $sign_name) {
            foreach ($signs as $sid => $s) {
                if (strtolower($s['en']) === strtolower($sign_name)) { $sign_id = $sid; break; }
            }
        }

        $sign_vi     = $signs[$sign_id]['vi'] ?? $sign_name;
        $sign_symbol = $signs[$sign_id]['symbol'] ?? '';
        $sign_en     = $signs[$sign_id]['en'] ?? $sign_name;

        // Degree: Prokerala may use 'longitude', 'full_degree', 'fullDegree', 'degree'
        $full_degree = floatval($p['longitude'] ?? $p['full_degree'] ?? $p['fullDegree'] ?? 0);
        $norm_degree = floatval($p['degree'] ?? $p['normDegree'] ?? $p['norm_degree'] ?? fmod($full_degree, 30));
        $is_retro    = (bool)($p['is_retrograde'] ?? $p['isRetro'] ?? $p['is_retro'] ?? false);
        if (is_string($is_retro)) $is_retro = strtolower($is_retro) === 'true';

        $house_num = intval($p['house'] ?? $p['house_id'] ?? 0);

        $entry = [
            'planet_en'   => $name,
            'planet_vi'   => $vi_names[$name] ?? $name,
            'sign_en'     => $sign_en,
            'sign_vi'     => $sign_vi,
            'sign_symbol' => $sign_symbol,
            'sign_number' => $sign_id,
            'full_degree' => $full_degree,
            'norm_degree' => $norm_degree,
            'is_retro'    => $is_retro,
            'house'       => $house_num,
            'source'      => 'prokerala',
        ];

        $result['positions'][$name] = $entry;

        if ($name === 'Sun')       $result['sun_sign']       = $sign_en;
        if ($name === 'Moon')      $result['moon_sign']      = $sign_en;
        if ($name === 'Ascendant') $result['ascendant_sign'] = $sign_en;
    }

    // Also parse house cusps
    $houses_list = $api_data['data']['house_cusps']
                ?? $api_data['data']['house_positions']
                ?? $api_data['data']['houses']
                ?? [];
    $result['houses_raw'] = [];

    foreach ($houses_list as $h) {
        $house_num = intval($h['id'] ?? $h['House'] ?? $h['house'] ?? 0);
        if ($house_num < 1) continue;

        $h_sign_id = $h['sign']['id'] ?? $h['zodiac_sign']['number'] ?? $h['zodiac_sign']['id'] ?? 0;
        $h_sign_name = $h['sign']['name'] ?? $h['zodiac_sign']['name'] ?? $h['zodiac_sign']['name']['en'] ?? '';
        if (!$h_sign_id && $h_sign_name) {
            foreach ($signs as $sid => $s) {
                if (strtolower($s['en']) === strtolower($h_sign_name)) { $h_sign_id = $sid; break; }
            }
        }

        $h_full_degree = floatval($h['longitude'] ?? $h['full_degree'] ?? $h['fullDegree'] ?? 0);
        $h_norm_degree = floatval($h['degree'] ?? $h['normDegree'] ?? fmod($h_full_degree, 30));

        $result['houses_raw'][] = [
            'House'       => $house_num,
            'zodiac_sign' => ['number' => $h_sign_id, 'name' => ['en' => $signs[$h_sign_id]['en'] ?? $h_sign_name]],
            'fullDegree'  => $h_full_degree,
            'normDegree'  => $h_norm_degree,
        ];
    }

    // Parse aspects if available
    $aspects_list = $api_data['data']['aspects']
                 ?? $api_data['data']['natal_aspects']
                 ?? [];
    $result['aspects_raw'] = [];

    $aspect_name_map = [
        'conjunction' => 'Conjunction', 'opposition' => 'Opposition',
        'trine' => 'Trine', 'square' => 'Square', 'sextile' => 'Sextile',
        'semi-sextile' => 'Semi-Sextile', 'quincunx' => 'Quincunx',
        'quintile' => 'Quintile', 'sesquiquadrate' => 'Sesquiquadrate',
        'septile' => 'Septile', 'octile' => 'Octile', 'novile' => 'Novile',
        'semisextile' => 'Semi-Sextile', 'sesquisquare' => 'Sesquiquadrate',
        'semi_sextile' => 'Semi-Sextile', 'semi_square' => 'Octile',
    ];

    foreach ($aspects_list as $asp) {
        $p1_name = $asp['planet1']['name'] ?? $asp['aspecting_planet']['name'] ?? $asp['planet_1']['en'] ?? '';
        $p2_name = $asp['planet2']['name'] ?? $asp['aspected_planet']['name'] ?? $asp['planet_2']['en'] ?? '';
        $asp_name = $asp['aspect']['name'] ?? $asp['aspect_name'] ?? $asp['type'] ?? '';
        $orb_val = floatval($asp['orb'] ?? $asp['orb_value'] ?? 0);

        // Normalize aspect name
        $asp_normalized = $aspect_name_map[strtolower(trim($asp_name))] ?? ucfirst($asp_name);

        if ($p1_name && $p2_name && $asp_normalized) {
            $result['aspects_raw'][] = [
                'aspecting_planet' => ['name' => $p1_name, 'en' => $p1_name],
                'aspected_planet'  => ['name' => $p2_name, 'en' => $p2_name],
                'type'             => $asp_normalized,
                'orb'              => $orb_val,
                'source'           => 'prokerala',
            ];
        }
    }

    return $result;
}

/* =====================================================================
 * ALL-IN-ONE: FETCH COMPLETE PROKERALA NATAL CHART
 * =====================================================================*/

/**
 * Fetch complete Prokerala natal chart (planet positions + chart SVG + optional PDF)
 * This is the Prokerala equivalent of bccm_astro_fetch_full_chart()
 *
 * @param array $birth_data  Keys: year, month, day, hour, minute, second, latitude, longitude, timezone
 * @return array|WP_Error    Complete Prokerala chart data
 */
function bccm_prokerala_fetch_full_chart($birth_data) {
    // Step 1: Natal planet positions (planets, houses, aspects in JSON)
    $natal_result = bccm_prokerala_get_natal_positions($birth_data);
    if (is_wp_error($natal_result)) {
        error_log('[BCCM Prokerala Full] natal-planet-position failed: ' . $natal_result->get_error_message());
        // Try basic natal-chart endpoint as fallback
        $natal_result = bccm_prokerala_get_natal_chart($birth_data);
        if (is_wp_error($natal_result)) return $natal_result;
    }

    // Step 2: Parse into standard format
    $parsed = bccm_prokerala_parse_positions($natal_result);

    // Step 3: Chart SVG
    $chart_svg = '';
    $svg_result = bccm_prokerala_get_chart_image($birth_data);
    if (!is_wp_error($svg_result) && is_string($svg_result) && !empty($svg_result)) {
        $chart_svg = $svg_result;
    } elseif (is_wp_error($svg_result)) {
        error_log('[BCCM Prokerala Full] chart SVG failed: ' . $svg_result->get_error_message());
        // Fallback: try fetch_chart_svg (existing function)
        $svg_fallback = bccm_prokerala_fetch_chart_svg($birth_data);
        if (!is_wp_error($svg_fallback) && is_string($svg_fallback)) {
            $chart_svg = $svg_fallback;
        }
    }

    return [
        'birth_data'     => $birth_data,
        'raw_response'   => $natal_result,
        'positions'      => $parsed['positions'],
        'houses_raw'     => $parsed['houses_raw'],
        'aspects_raw'    => $parsed['aspects_raw'],
        'sun_sign'       => $parsed['sun_sign'],
        'moon_sign'      => $parsed['moon_sign'],
        'ascendant_sign' => $parsed['ascendant_sign'],
        'chart_svg'      => $chart_svg,
        'parsed'         => $parsed,
        'fetched_at'     => current_time('mysql'),
        'source'         => 'prokerala',
    ];
}

/* =====================================================================
 * SAVE PROKERALA CHART DATA
 * =====================================================================*/

/**
 * Save Prokerala chart data to bccm_astro table (prokerala_* columns)
 *
 * @param int   $coachee_id
 * @param array $chart_data  From bccm_prokerala_fetch_full_chart()
 * @param array $birth_input Original form input
 * @return bool
 */
function bccm_prokerala_save_chart($coachee_id, $chart_data, $birth_input = []) {
    global $wpdb;
    $t_astro = $wpdb->prefix . 'bccm_astro';
    $t       = bccm_tables();
    $now     = current_time('mysql');

    // Build Prokerala summary
    $pk_summary = [
        'sun_sign'       => $chart_data['sun_sign'] ?? '',
        'moon_sign'      => $chart_data['moon_sign'] ?? '',
        'ascendant_sign' => $chart_data['ascendant_sign'] ?? '',
        'fetched_at'     => $chart_data['fetched_at'] ?? $now,
        'source'         => 'prokerala',
    ];

    // Build Prokerala traits (full data)
    $pk_traits = [
        'positions'  => $chart_data['positions'] ?? [],
        'houses'     => $chart_data['houses_raw'] ?? [],
        'aspects'    => $chart_data['aspects_raw'] ?? [],
        'birth_data' => $chart_data['birth_data'] ?? $birth_input,
        'source'     => 'prokerala',
    ];

    $prokerala_svg = $chart_data['chart_svg'] ?? '';

    // Check if row exists
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_astro WHERE coachee_id=%d", $coachee_id));

    $update_data = [
        'prokerala_summary' => wp_json_encode($pk_summary, JSON_UNESCAPED_UNICODE),
        'prokerala_traits'  => wp_json_encode($pk_traits, JSON_UNESCAPED_UNICODE),
        'updated_at'        => $now,
    ];
    if (!empty($prokerala_svg)) {
        $update_data['prokerala_chart'] = $prokerala_svg;
    }

    // Also save birth data if not already present
    if (!empty($birth_input)) {
        $update_data['birth_place'] = sanitize_text_field($birth_input['birth_place'] ?? '');
        $update_data['birth_time']  = sanitize_text_field($birth_input['birth_time'] ?? '');
        $update_data['latitude']    = floatval($birth_input['latitude'] ?? 0);
        $update_data['longitude']   = floatval($birth_input['longitude'] ?? 0);
        $update_data['timezone']    = floatval($birth_input['timezone'] ?? 7);
    }

    if ($existing) {
        $wpdb->update($t_astro, $update_data, ['coachee_id' => $coachee_id]);
    } else {
        $insert_data = array_merge($update_data, [
            'coachee_id' => $coachee_id,
            'created_at' => $now,
        ]);
        $wpdb->insert($t_astro, $insert_data);
    }

    // Update coachee zodiac sign from Prokerala if not already set
    $sun_sign = strtolower($pk_summary['sun_sign'] ?? '');
    if ($sun_sign) {
        $current_zodiac = $wpdb->get_var($wpdb->prepare(
            "SELECT zodiac_sign FROM {$t['profiles']} WHERE id=%d", $coachee_id
        ));
        if (empty($current_zodiac)) {
            $wpdb->update($t['profiles'], [
                'zodiac_sign' => $sun_sign,
                'updated_at'  => $now,
            ], ['id' => $coachee_id]);
        }
    }

    return true;
}

/* =====================================================================
 * PROKERALA NATAL REPORT (63+ page PDF)
 * =====================================================================*/

/**
 * Generate Prokerala Basic Natal Report PDF
 * Calls: POST v2/report/personal-reading/instant
 * Module: basic-natal-report (Western, 63+ pages)
 *
 * @param array  $birth_data    Keys: year, month, day, hour, minute, second, latitude, longitude, timezone
 * @param array  $coachee_info  Keys: full_name, birth_place, gender
 * @param array  $options       brand_name, report_name overrides
 * @return string|WP_Error      Raw PDF bytes or error
 */
function bccm_prokerala_generate_natal_report($birth_data, $coachee_info = [], $options = []) {
    $datetime    = bccm_prokerala_build_datetime($birth_data);
    $lat         = floatval($birth_data['latitude'] ?? 21.0285);
    $lng         = floatval($birth_data['longitude'] ?? 105.8542);
    $coordinates = "$lat,$lng";

    // Parse name
    $full_name = $coachee_info['full_name'] ?? 'User';
    $name_parts = explode(' ', trim($full_name), 3);
    $first_name  = $name_parts[0] ?? 'User';
    $middle_name = count($name_parts) > 2 ? $name_parts[1] : '';
    $last_name   = count($name_parts) > 2 ? $name_parts[2] : ($name_parts[1] ?? '');

    $gender = strtolower($coachee_info['gender'] ?? 'male');
    if (!in_array($gender, ['male', 'female'])) $gender = 'male';

    $body = [
        'input' => [
            'first_name'  => $first_name,
            'middle_name' => $middle_name,
            'last_name'   => $last_name,
            'gender'      => $gender,
            'datetime'    => $datetime,
            'coordinates' => $coordinates,
            'place'       => $coachee_info['birth_place'] ?? ($birth_data['birth_place'] ?? 'Vietnam'),
        ],
        'options' => [
            'report' => [
                'brand_name' => $options['brand_name'] ?? 'BizCoach Map',
                'name'       => $options['report_name'] ?? 'Basic Natal Report',
                'caption'    => $options['caption'] ?? 'Generated by BizCoach Map',
                'la'         => $options['language'] ?? 'en',
            ],
            'template' => [
                'style'  => $options['style'] ?? 'basic',
                'footer' => $options['footer'] ?? 'prokerala',
            ],
            'modules' => [
                [
                    'name' => 'basic-natal-report',
                    'options' => [
                        'chart_style'  => $options['chart_style'] ?? 'north-indian',
                        'chart_type'   => $options['chart_type'] ?? 'zodiac',
                        'house_system' => 0, // Placidus
                    ],
                ],
            ],
        ],
    ];

    return bccm_prokerala_api_post('v2/report/personal-reading/instant', $body, 120, 'application/pdf');
}

/* =====================================================================
 * AJAX: Prokerala Natal Report PDF proxy
 * =====================================================================*/
add_action('wp_ajax_bccm_prokerala_natal_pdf', 'bccm_prokerala_natal_pdf_handler');

function bccm_prokerala_natal_pdf_handler() {
    if (!current_user_can('edit_posts')) {
        wp_die('Unauthorized');
    }
    check_ajax_referer('bccm_prokerala_natal_pdf', '_wpnonce');

    $coachee_id = intval($_GET['coachee_id'] ?? 0);
    if (!$coachee_id) wp_die('Missing coachee_id');

    global $wpdb;
    $t = bccm_tables();
    $coachee = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
    if (!$coachee) wp_die('Coachee not found');

    $astro_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d", $coachee_id), ARRAY_A);
    if (!$astro_row) wp_die('No astro data');

    // Get birth data from traits (prefer Prokerala traits, fallback to Free API traits)
    $pk_traits = !empty($astro_row['prokerala_traits']) ? json_decode($astro_row['prokerala_traits'], true) : [];
    $fa_traits = !empty($astro_row['traits']) ? json_decode($astro_row['traits'], true) : [];
    $birth_data = $pk_traits['birth_data'] ?? $fa_traits['birth_data'] ?? [];

    if (empty($birth_data)) {
        wp_die('No birth data found');
    }

    $birth_data['birth_place'] = $astro_row['birth_place'] ?? '';

    $pdf = bccm_prokerala_generate_natal_report($birth_data, [
        'full_name'   => $coachee['full_name'] ?? 'User',
        'birth_place' => $astro_row['birth_place'] ?? 'Vietnam',
        'gender'      => $coachee['baby_gender'] ?? 'male',
    ]);

    if (is_wp_error($pdf)) {
        wp_die('Prokerala Report Error: ' . $pdf->get_error_message());
    }

    if (is_string($pdf) && strlen($pdf) > 100) {
        $filename = sanitize_file_name('natal-report-' . ($coachee['full_name'] ?? 'chart') . '.pdf');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    wp_die('Không nhận được PDF từ Prokerala API.');
}
