<?php
/**
 * BizCoach Map – Vedic / Indian Astrology API Client
 *
 * Kết nối với Free Astrology API (https://freeastrologyapi.com)
 * Vedic Astrology endpoints: planets, horoscope-chart-url, navamsa-chart-info
 * Uses Lahiri ayanamsha (sidereal zodiac) instead of Tropical (western)
 *
 * @package BizCoach_Map
 * @since   0.1.0.17
 * @see     https://freeastrologyapi.com/api-docs/indian-vedic-astrology-api-docs
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * VEDIC ZODIAC SIGNS (Rashi)
 * =====================================================================*/

/**
 * Vedic zodiac signs (Rashi) — 1-indexed
 * Same order as Western but with Sanskrit/Hindi names
 */
function bccm_vedic_rashi_signs() {
    return [
        1  => ['en' => 'Aries',       'vi' => 'Dương Cưu',     'sanskrit' => 'Mesha',       'symbol' => '♈', 'element' => 'Lửa',  'lord' => 'Mars'],
        2  => ['en' => 'Taurus',      'vi' => 'Kim Ngưu',      'sanskrit' => 'Vrishabha',   'symbol' => '♉', 'element' => 'Đất',  'lord' => 'Venus'],
        3  => ['en' => 'Gemini',      'vi' => 'Song Tử',       'sanskrit' => 'Mithuna',     'symbol' => '♊', 'element' => 'Khí',  'lord' => 'Mercury'],
        4  => ['en' => 'Cancer',      'vi' => 'Cự Giải',       'sanskrit' => 'Karka',       'symbol' => '♋', 'element' => 'Nước', 'lord' => 'Moon'],
        5  => ['en' => 'Leo',         'vi' => 'Sư Tử',         'sanskrit' => 'Simha',       'symbol' => '♌', 'element' => 'Lửa',  'lord' => 'Sun'],
        6  => ['en' => 'Virgo',       'vi' => 'Xử Nữ',         'sanskrit' => 'Kanya',       'symbol' => '♍', 'element' => 'Đất',  'lord' => 'Mercury'],
        7  => ['en' => 'Libra',       'vi' => 'Thiên Bình',    'sanskrit' => 'Tula',        'symbol' => '♎', 'element' => 'Khí',  'lord' => 'Venus'],
        8  => ['en' => 'Scorpio',     'vi' => 'Bọ Cạp',       'sanskrit' => 'Vrishchika',  'symbol' => '♏', 'element' => 'Nước', 'lord' => 'Mars'],
        9  => ['en' => 'Sagittarius', 'vi' => 'Nhân Mã',      'sanskrit' => 'Dhanu',       'symbol' => '♐', 'element' => 'Lửa',  'lord' => 'Jupiter'],
        10 => ['en' => 'Capricorn',   'vi' => 'Ma Kết',       'sanskrit' => 'Makara',      'symbol' => '♑', 'element' => 'Đất',  'lord' => 'Saturn'],
        11 => ['en' => 'Aquarius',    'vi' => 'Bảo Bình',     'sanskrit' => 'Kumbha',      'symbol' => '♒', 'element' => 'Khí',  'lord' => 'Saturn'],
        12 => ['en' => 'Pisces',      'vi' => 'Song Ngư',      'sanskrit' => 'Meena',       'symbol' => '♓', 'element' => 'Nước', 'lord' => 'Jupiter'],
    ];
}

/**
 * Vedic planet names in Vietnamese
 */
function bccm_vedic_planet_names_vi() {
    return [
        'Sun'       => 'Mặt Trời (Surya)',
        'Moon'      => 'Mặt Trăng (Chandra)',
        'Mars'      => 'Sao Hỏa (Mangal)',
        'Mercury'   => 'Sao Thủy (Budha)',
        'Jupiter'   => 'Sao Mộc (Guru/Brihaspati)',
        'Venus'     => 'Sao Kim (Shukra)',
        'Saturn'    => 'Sao Thổ (Shani)',
        'Rahu'      => 'Rahu (La Hầu)',
        'Ketu'      => 'Ketu (Kế Đô)',
        'Uranus'    => 'Sao Thiên Vương (Uranus)',
        'Neptune'   => 'Sao Hải Vương (Neptune)',
        'Pluto'     => 'Sao Diêm Vương (Pluto)',
        'Ascendant' => 'Lagna (Cung Mọc)',
    ];
}

/**
 * Vedic house meanings (Bhava)
 */
function bccm_vedic_house_meanings_vi() {
    return [
        1  => 'Nhà 1 (Tanu Bhava) — Bản thân, thể chất, tính cách',
        2  => 'Nhà 2 (Dhana Bhava) — Tài sản, gia đình, lời nói',
        3  => 'Nhà 3 (Sahaja Bhava) — Anh chị em, can đảm, giao tiếp',
        4  => 'Nhà 4 (Sukha Bhava) — Mẹ, nhà cửa, hạnh phúc nội tâm',
        5  => 'Nhà 5 (Putra Bhava) — Con cái, sáng tạo, trí tuệ, tình yêu',
        6  => 'Nhà 6 (Ripu Bhava) — Kẻ thù, bệnh tật, nợ nần, phục vụ',
        7  => 'Nhà 7 (Kalatra Bhava) — Hôn nhân, đối tác, quan hệ',
        8  => 'Nhà 8 (Ayu Bhava) — Chuyển hóa, bí ẩn, tuổi thọ, di sản',
        9  => 'Nhà 9 (Dharma Bhava) — Cha, may mắn, triết lý, tâm linh',
        10 => 'Nhà 10 (Karma Bhava) — Sự nghiệp, danh vọng, hành động',
        11 => 'Nhà 11 (Labha Bhava) — Thu nhập, ước mơ, bạn bè, mạng lưới',
        12 => 'Nhà 12 (Vyaya Bhava) — Giải thoát, tổn thất, ngoại quốc, tâm linh',
    ];
}

/* =====================================================================
 * VEDIC API CALLER (reuses same bccm_astro_api_call from astro-api-free.php)
 * =====================================================================*/

/**
 * Build Vedic payload — uses Lahiri ayanamsha (sidereal)
 */
function bccm_vedic_build_payload($birth_data) {
    return [
        'year'      => intval(isset($birth_data['year']) ? $birth_data['year'] : 1990),
        'month'     => intval(isset($birth_data['month']) ? $birth_data['month'] : 1),
        'date'      => intval(isset($birth_data['day']) ? $birth_data['day'] : 1),
        'hours'     => intval(isset($birth_data['hour']) ? $birth_data['hour'] : 12),
        'minutes'   => intval(isset($birth_data['minute']) ? $birth_data['minute'] : 0),
        'seconds'   => intval(isset($birth_data['second']) ? $birth_data['second'] : 0),
        'latitude'  => floatval(isset($birth_data['latitude']) ? $birth_data['latitude'] : 21.0285),
        'longitude' => floatval(isset($birth_data['longitude']) ? $birth_data['longitude'] : 105.8542),
        'timezone'  => floatval(isset($birth_data['timezone']) ? $birth_data['timezone'] : 7),
        'settings'  => [
            'observation_point' => 'topocentric',
            'ayanamsha'         => 'lahiri',
        ],
    ];
}

/* =====================================================================
 * VEDIC API ENDPOINT WRAPPERS
 * =====================================================================*/

/**
 * Get Vedic planet positions (Rashi chart)
 */
function bccm_vedic_get_planets($birth_data) {
    $payload = bccm_vedic_build_payload($birth_data);
    $result = bccm_astro_api_call('planets', $payload);

    if (is_wp_error($result)) return $result;

    // Vedic API returns output[0] (indexed) and output[1] (named keys)
    $output = isset($result['output']) ? $result['output'] : [];
    $planets_named = isset($output[1]) ? $output[1] : (isset($output[0]) ? $output[0] : []);

    return ['planets' => $planets_named, 'raw' => $result];
}

/**
 * Get Vedic Navamsa chart info (D9 divisional chart)
 */
function bccm_vedic_get_navamsa($birth_data) {
    $payload = bccm_vedic_build_payload($birth_data);
    $result = bccm_astro_api_call('navamsa-chart-info', $payload);

    if (is_wp_error($result)) return $result;

    $output = isset($result['output']) ? $result['output'] : [];
    return ['navamsa' => isset($output[1]) ? $output[1] : (isset($output[0]) ? $output[0] : []), 'raw' => $result];
}

/**
 * Get Vedic horoscope chart image URL (Rashi chart)
 */
function bccm_vedic_get_chart_url($birth_data) {
    $payload = bccm_vedic_build_payload($birth_data);
    // Vedic chart URL uses 'config' key same as planets
    $payload['config'] = $payload['settings'];
    unset($payload['settings']);

    $result = bccm_astro_api_call('horoscope-chart-url', $payload, 60);

    if (is_wp_error($result)) {
        error_log('[BCCM Vedic] Chart URL API error: ' . $result->get_error_message());
        return $result;
    }

    $chart_url = '';
    if (isset($result['output'])) {
        $chart_url = $result['output'];
    } elseif (isset($result['chart_url'])) {
        $chart_url = $result['chart_url'];
    } elseif (isset($result['url'])) {
        $chart_url = $result['url'];
    }
    
    error_log('[BCCM Vedic] Chart URL result: ' . print_r($result, true));
    error_log('[BCCM Vedic] Final chart_url: ' . $chart_url);

    return ['chart_url' => $chart_url, 'raw' => $result];
}

/**
 * Get Vedic Navamsa chart image URL (D9)
 */
function bccm_vedic_get_navamsa_chart_url($birth_data) {
    $payload = bccm_vedic_build_payload($birth_data);
    $payload['config'] = $payload['settings'];
    unset($payload['settings']);

    $result = bccm_astro_api_call('navamsa-chart-url', $payload, 60);

    if (is_wp_error($result)) {
        error_log('[BCCM Vedic] Navamsa Chart URL API error: ' . $result->get_error_message());
        return $result;
    }

    $chart_url = '';
    if (isset($result['output'])) {
        $chart_url = $result['output'];
    } elseif (isset($result['chart_url'])) {
        $chart_url = $result['chart_url'];
    } elseif (isset($result['url'])) {
        $chart_url = $result['url'];
    }
    
    error_log('[BCCM Vedic] Navamsa Chart URL result: ' . print_r($result, true));
    error_log('[BCCM Vedic] Final navamsa_chart_url: ' . $chart_url);

    return ['chart_url' => $chart_url, 'raw' => $result];
}

/* =====================================================================
 * VEDIC PARSER
 * =====================================================================*/

/**
 * Parse Vedic planet data into structured format
 *
 * @param array $planets  Named keys from API output[1]
 * @return array  Parsed planet data
 */
function bccm_vedic_parse_planets($planets) {
    $rashi = bccm_vedic_rashi_signs();
    $planet_vi = bccm_vedic_planet_names_vi();

    $result = [
        'sun_sign'       => '',
        'moon_sign'      => '',
        'ascendant_sign' => '',
        'positions'      => [],
    ];

    foreach ($planets as $name => $data) {
        if (!is_array($data)) continue;

        $sign_num = intval(isset($data['current_sign']) ? $data['current_sign'] : 0);
        $sign_info = isset($rashi[$sign_num]) ? $rashi[$sign_num] : ['en' => 'Unknown', 'vi' => '?', 'symbol' => '?', 'sanskrit' => '?'];

        $entry = [
            'planet_en'    => $name,
            'planet_vi'    => isset($planet_vi[$name]) ? $planet_vi[$name] : $name,
            'sign_en'      => isset($sign_info['en']) ? $sign_info['en'] : '',
            'sign_vi'      => isset($sign_info['vi']) ? $sign_info['vi'] : '',
            'sign_symbol'  => isset($sign_info['symbol']) ? $sign_info['symbol'] : '',
            'sign_number'  => $sign_num,
            'sign_sanskrit'=> isset($sign_info['sanskrit']) ? $sign_info['sanskrit'] : '',
            'sign_lord'    => isset($sign_info['lord']) ? $sign_info['lord'] : '',
            'full_degree'  => floatval(isset($data['fullDegree']) ? $data['fullDegree'] : 0),
            'norm_degree'  => floatval(isset($data['normDegree']) ? $data['normDegree'] : 0),
            'is_retro'     => strtolower(isset($data['isRetro']) ? $data['isRetro'] : 'false') === 'true',
        ];

        $result['positions'][$name] = $entry;

        if ($name === 'Sun')       $result['sun_sign']       = $sign_info['en'];
        if ($name === 'Moon')      $result['moon_sign']      = $sign_info['en'];
        if ($name === 'Ascendant') $result['ascendant_sign'] = $sign_info['en'];
    }

    return $result;
}

/* =====================================================================
 * ALL-IN-ONE: FETCH COMPLETE VEDIC NATAL CHART
 * =====================================================================*/

/**
 * Fetch complete Vedic natal chart data
 *
 * @param array $birth_data
 * @return array|WP_Error  Complete Vedic chart data
 */
function bccm_vedic_fetch_full_chart($birth_data) {
    // Step 1: Vedic Planets (Rashi chart)
    $planets_result = bccm_vedic_get_planets($birth_data);
    if (is_wp_error($planets_result)) return $planets_result;

    // Step 2: Navamsa chart (D9) — important for marriage/dharma
    $navamsa_result = bccm_vedic_get_navamsa($birth_data);
    if (is_wp_error($navamsa_result)) {
        error_log('[BCCM Vedic] Navamsa failed: ' . $navamsa_result->get_error_message());
        $navamsa_result = ['navamsa' => []];
    }

    // Step 3: Rashi chart image URL
    $chart_result = bccm_vedic_get_chart_url($birth_data);
    if (is_wp_error($chart_result)) {
        error_log('[BCCM Vedic] Chart URL failed: ' . $chart_result->get_error_message());
        $chart_result = ['chart_url' => ''];
    }

    // Step 4: Navamsa chart image URL (optional)
    $navamsa_chart = bccm_vedic_get_navamsa_chart_url($birth_data);
    if (is_wp_error($navamsa_chart)) {
        $navamsa_chart = ['chart_url' => ''];
    }

    // Parse planets
    $parsed = bccm_vedic_parse_planets(isset($planets_result['planets']) ? $planets_result['planets'] : []);

    return [
        'birth_data'         => $birth_data,
        'planets'            => isset($planets_result['planets']) ? $planets_result['planets'] : [],
        'navamsa'            => isset($navamsa_result['navamsa']) ? $navamsa_result['navamsa'] : [],
        'chart_url'          => isset($chart_result['chart_url']) ? $chart_result['chart_url'] : '',
        'navamsa_chart_url'  => isset($navamsa_chart['chart_url']) ? $navamsa_chart['chart_url'] : '',
        'parsed'             => $parsed,
        'fetched_at'         => current_time('mysql'),
    ];
}

/* =====================================================================
 * SAVE VEDIC CHART DATA TO DB
 * =====================================================================*/

/**
 * Save Vedic chart data to bccm_astro table (chart_type = 'vedic')
 *
 * @param int   $coachee_id
 * @param array $chart_data  From bccm_vedic_fetch_full_chart()
 * @param array $birth_input Original form input
 * @return bool
 */
function bccm_vedic_save_chart($coachee_id, $chart_data, $birth_input = [], $passed_user_id = null) {
    global $wpdb;
    $t_astro = $wpdb->prefix . 'bccm_astro';
    $t       = bccm_tables();

    $parsed = isset($chart_data['parsed']) ? $chart_data['parsed'] : [];

    // Build summary
    $summary = [
        'sun_sign'       => isset($parsed['sun_sign']) ? $parsed['sun_sign'] : '',
        'moon_sign'      => isset($parsed['moon_sign']) ? $parsed['moon_sign'] : '',
        'ascendant_sign' => isset($parsed['ascendant_sign']) ? $parsed['ascendant_sign'] : '',
        'chart_url'      => isset($chart_data['chart_url']) ? $chart_data['chart_url'] : '',
        'navamsa_chart_url' => isset($chart_data['navamsa_chart_url']) ? $chart_data['navamsa_chart_url'] : '',
        'fetched_at'     => isset($chart_data['fetched_at']) ? $chart_data['fetched_at'] : current_time('mysql'),
        'system'         => 'Vedic (Lahiri Ayanamsha)',
    ];

    // Build traits (full data)
    $traits = [
        'planets'    => isset($chart_data['planets']) ? $chart_data['planets'] : [],
        'navamsa'    => isset($chart_data['navamsa']) ? $chart_data['navamsa'] : [],
        'positions'  => isset($parsed['positions']) ? $parsed['positions'] : [],
        'birth_data' => isset($chart_data['birth_data']) ? $chart_data['birth_data'] : $birth_input,
    ];

    $now = current_time('mysql');
    $chart_type = 'vedic';

    // Use passed user_id if available, otherwise resolve from coachee profile
    $user_id = $passed_user_id ?: $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$t['profiles']} WHERE id=%d", $coachee_id));

    // Check existing by user_id first (to share astro data across platforms), then by coachee_id
    $existing = null;
    
    // Priority 1: Check by user_id (if user is logged in)
    if ($user_id) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_astro WHERE user_id=%d AND chart_type=%s", $user_id, $chart_type
        ));
    }
    
    // Priority 2: Check by coachee_id (for guests or new records)
    if (!$existing) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_astro WHERE coachee_id=%d AND chart_type=%s", $coachee_id, $chart_type
        ));
    }

    if ($existing) {
        $wpdb->update($t_astro, [
            'user_id'     => $user_id ? $user_id : null,
            'birth_place' => sanitize_text_field(isset($birth_input['birth_place']) ? $birth_input['birth_place'] : ''),
            'birth_time'  => sanitize_text_field(isset($birth_input['birth_time']) ? $birth_input['birth_time'] : ''),
            'latitude'    => floatval(isset($birth_input['latitude']) ? $birth_input['latitude'] : 0),
            'longitude'   => floatval(isset($birth_input['longitude']) ? $birth_input['longitude'] : 0),
            'timezone'    => floatval(isset($birth_input['timezone']) ? $birth_input['timezone'] : 7),
            'summary'     => wp_json_encode($summary, JSON_UNESCAPED_UNICODE),
            'traits'      => wp_json_encode($traits, JSON_UNESCAPED_UNICODE),
            'chart_svg'   => isset($chart_data['chart_url']) ? $chart_data['chart_url'] : '',
            'updated_at'  => $now,
        ], ['id' => $existing]);
    } else {
        $wpdb->insert($t_astro, [
            'coachee_id'  => $coachee_id,
            'user_id'     => $user_id ? $user_id : null,
            'chart_type'  => $chart_type,
            'birth_place' => sanitize_text_field(isset($birth_input['birth_place']) ? $birth_input['birth_place'] : ''),
            'birth_time'  => sanitize_text_field(isset($birth_input['birth_time']) ? $birth_input['birth_time'] : ''),
            'latitude'    => floatval(isset($birth_input['latitude']) ? $birth_input['latitude'] : 0),
            'longitude'   => floatval(isset($birth_input['longitude']) ? $birth_input['longitude'] : 0),
            'timezone'    => floatval(isset($birth_input['timezone']) ? $birth_input['timezone'] : 7),
            'summary'     => wp_json_encode($summary, JSON_UNESCAPED_UNICODE),
            'traits'      => wp_json_encode($traits, JSON_UNESCAPED_UNICODE),
            'chart_svg'   => isset($chart_data['chart_url']) ? $chart_data['chart_url'] : '',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    return true;
}

/* =====================================================================
 * VEDIC CHART CONTEXT FOR LLM
 * =====================================================================*/

/**
 * Build Vedic chart context string for AI prompt
 *
 * @param array $astro_row  Row from bccm_astro (chart_type = 'vedic')
 * @param array $coachee    Coachee profile
 * @return string
 */
function bccm_vedic_build_chart_context($astro_row, $coachee) {
    $summary   = json_decode(isset($astro_row['summary']) ? $astro_row['summary'] : '{}', true);
    $summary   = $summary ? $summary : [];
    $traits    = json_decode(isset($astro_row['traits']) ? $astro_row['traits'] : '{}', true);
    $traits    = $traits ? $traits : [];
    $positions = isset($traits['positions']) ? $traits['positions'] : [];
    $navamsa   = isset($traits['navamsa']) ? $traits['navamsa'] : [];
    $birth     = isset($traits['birth_data']) ? $traits['birth_data'] : [];
    $rashi     = bccm_vedic_rashi_signs();
    $planet_vi = bccm_vedic_planet_names_vi();
    $house_meanings = bccm_vedic_house_meanings_vi();

    $name = isset($coachee['full_name']) ? $coachee['full_name'] : 'Người dùng';
    $dob  = '';
    if (!empty($birth['day']) && !empty($birth['month']) && !empty($birth['year'])) {
        $dob = sprintf('%02d/%02d/%04d', $birth['day'], $birth['month'], $birth['year']);
    } elseif (!empty($coachee['dob'])) {
        $dob = date('d/m/Y', strtotime($coachee['dob']));
    }

    $ctx  = "=== THÔNG TIN CÁ NHÂN ===\n";
    $ctx .= "Họ tên: $name\nNgày sinh: $dob\nGiờ sinh: " . (isset($astro_row['birth_time']) ? $astro_row['birth_time'] : '') . "\nNơi sinh: " . (isset($astro_row['birth_place']) ? $astro_row['birth_place'] : '') . "\n";
    $ctx .= "Hệ thống: Vedic / Jyotish (Sidereal — Lahiri Ayanamsha)\n\n";

    $planet_order = ['Ascendant', 'Sun', 'Moon', 'Mars', 'Mercury', 'Jupiter', 'Venus', 'Saturn', 'Rahu', 'Ketu'];
    $planet_syms  = ['Sun'=>'☉','Moon'=>'☽','Ascendant'=>'Lagna','Mars'=>'♂','Mercury'=>'☿','Jupiter'=>'♃','Venus'=>'♀','Saturn'=>'♄','Rahu'=>'☊','Ketu'=>'☋'];

    $ctx .= "=== VỊ TRÍ CÁC HÀNH TINH (Graha) — Rashi Chart ===\n";
    foreach ($planet_order as $pn) {
        if (!isset($positions[$pn])) continue;
        $p = $positions[$pn];
        $sym = isset($planet_syms[$pn]) ? $planet_syms[$pn] : $pn;
        $retro = (isset($p['is_retro']) && $p['is_retro']) ? ' [NGHỊCH HÀNH ℞ — Vakri]' : '';
        $lord = !empty($p['sign_lord']) ? " (Chủ cung: {$p['sign_lord']})" : '';
        $dms = bccm_astro_decimal_to_dms(isset($p['norm_degree']) ? $p['norm_degree'] : 0);
        $ctx .= "- $sym {$p['planet_vi']}: {$p['sign_symbol']} {$p['sign_vi']} ({$p['sign_sanskrit']}) — $dms$lord$retro\n";
    }
    $ctx .= "\n";

    // Navamsa positions (D9) if available
    if (!empty($navamsa)) {
        $ctx .= "=== NAVAMSA CHART (D9) — Bản Đồ Hôn Nhân & Dharma ===\n";
        foreach ($planet_order as $pn) {
            if (!isset($navamsa[$pn])) continue;
            $nav = $navamsa[$pn];
            $sign_num = intval(isset($nav['current_sign']) ? $nav['current_sign'] : 0);
            $sign_info = isset($rashi[$sign_num]) ? $rashi[$sign_num] : ['vi' => '?', 'symbol' => '?', 'sanskrit' => '?'];
            $ctx .= "- {" . (isset($planet_vi[$pn]) ? $planet_vi[$pn] : $pn) . "}: {$sign_info['symbol']} {$sign_info['vi']} ({$sign_info['sanskrit']})\n";
        }
        $ctx .= "\n";
    }

    // Yogas and special combinations
    $ctx .= "=== ĐẶC ĐIỂM NỔI BẬT ===\n";

    // Check for Gajakesari Yoga (Jupiter aspects Moon or Moon-Jupiter in kendra)
    $moon_sign = isset($positions['Moon']['sign_number']) ? $positions['Moon']['sign_number'] : 0;
    $jupiter_sign = isset($positions['Jupiter']['sign_number']) ? $positions['Jupiter']['sign_number'] : 0;
    if ($moon_sign && $jupiter_sign) {
        $diff = abs($moon_sign - $jupiter_sign);
        if (in_array($diff, [0, 3, 6, 9]) || in_array(12 - $diff, [0, 3, 6, 9])) {
            $ctx .= "- 🕉️ Gajakesari Yoga: Moon và Jupiter trong tương quan Kendra → trí tuệ, danh vọng, thịnh vượng\n";
        }
    }

    // Check for Rahu-Ketu axis
    $rahu_sign = isset($positions['Rahu']['sign_number']) ? $positions['Rahu']['sign_number'] : 0;
    $ketu_sign = isset($positions['Ketu']['sign_number']) ? $positions['Ketu']['sign_number'] : 0;
    if ($rahu_sign && $ketu_sign) {
        $rahu_info = isset($rashi[$rahu_sign]) ? $rashi[$rahu_sign] : ['vi' => '?'];
        $ketu_info = isset($rashi[$ketu_sign]) ? $rashi[$ketu_sign] : ['vi' => '?'];
        $ctx .= "- ☊☋ Trục Rahu-Ketu: Rahu tại {$rahu_info['vi']} / Ketu tại {$ketu_info['vi']} — hướng nghiệp lực & karma\n";
    }

    // Ascendant lord placement
    $asc_sign = isset($positions['Ascendant']['sign_number']) ? $positions['Ascendant']['sign_number'] : 0;
    $asc_lord_name = isset($rashi[$asc_sign]['lord']) ? $rashi[$asc_sign]['lord'] : '';
    if ($asc_lord_name && isset($positions[$asc_lord_name])) {
        $lord_sign_vi = isset($positions[$asc_lord_name]['sign_vi']) ? $positions[$asc_lord_name]['sign_vi'] : '?';
        $ctx .= "- 🏠 Lagna Lord ({$asc_lord_name}) đặt tại {$lord_sign_vi} — ảnh hưởng mạnh đến tính cách và cuộc sống\n";
    }

    $ctx .= "\n";

    // Element balance
    $elements = ['Lửa' => 0, 'Đất' => 0, 'Khí' => 0, 'Nước' => 0];
    foreach (['Sun','Moon','Mars','Mercury','Jupiter','Venus','Saturn'] as $pn) {
        if (!isset($positions[$pn])) continue;
        $sn = isset($positions[$pn]['sign_number']) ? $positions[$pn]['sign_number'] : 0;
        $elem = isset($rashi[$sn]['element']) ? $rashi[$sn]['element'] : '';
        if (isset($rashi[$sn]) && isset($elements[$elem])) {
            $elements[$elem]++;
        }
    }
    $ctx .= "=== CÂN BẰNG NGUYÊN TỐ ===\n";
    foreach ($elements as $el => $c) $ctx .= "- $el: $c hành tinh\n";

    return $ctx;
}
