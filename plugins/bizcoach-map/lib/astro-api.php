<?php
/**
 * BizCoach Map – Astrology Shared Helpers & Loader
 *
 * File này chứa các hàm dùng chung (zodiac, aspect, pattern, chart builders)
 * và load 2 file API riêng biệt:
 *   - astro-api-free.php    (Western Astrology — Free Astrology API)
 *   - astro-api-vedic.php   (Indian/Vedic Astrology — Free Astrology API)
 *
 * @package BizCoach_Map
 * @since   0.1.0.17
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * ZODIAC HELPERS
 * =====================================================================*/

/**
 * Zodiac sign data – Vietnamese + English
 */
function bccm_zodiac_signs() {
    return [
        1  => ['en' => 'Aries',       'vi' => 'Bạch Dương',  'symbol' => '♈', 'element' => 'Lửa',   'modality' => 'Cardinal'],
        2  => ['en' => 'Taurus',      'vi' => 'Kim Ngưu',    'symbol' => '♉', 'element' => 'Đất',   'modality' => 'Fixed'],
        3  => ['en' => 'Gemini',      'vi' => 'Song Tử',     'symbol' => '♊', 'element' => 'Khí',   'modality' => 'Mutable'],
        4  => ['en' => 'Cancer',      'vi' => 'Cự Giải',     'symbol' => '♋', 'element' => 'Nước',  'modality' => 'Cardinal'],
        5  => ['en' => 'Leo',         'vi' => 'Sư Tử',       'symbol' => '♌', 'element' => 'Lửa',   'modality' => 'Fixed'],
        6  => ['en' => 'Virgo',       'vi' => 'Xử Nữ',       'symbol' => '♍', 'element' => 'Đất',   'modality' => 'Mutable'],
        7  => ['en' => 'Libra',       'vi' => 'Thiên Bình',  'symbol' => '♎', 'element' => 'Khí',   'modality' => 'Cardinal'],
        8  => ['en' => 'Scorpio',     'vi' => 'Bọ Cạp',      'symbol' => '♏', 'element' => 'Nước',  'modality' => 'Fixed'],
        9  => ['en' => 'Sagittarius', 'vi' => 'Nhân Mã',     'symbol' => '♐', 'element' => 'Lửa',   'modality' => 'Mutable'],
        10 => ['en' => 'Capricorn',   'vi' => 'Ma Kết',       'symbol' => '♑', 'element' => 'Đất',   'modality' => 'Cardinal'],
        11 => ['en' => 'Aquarius',    'vi' => 'Bảo Bình',    'symbol' => '♒', 'element' => 'Khí',   'modality' => 'Fixed'],
        12 => ['en' => 'Pisces',      'vi' => 'Song Ngư',    'symbol' => '♓', 'element' => 'Nước',  'modality' => 'Mutable'],
    ];
}

/**
 * Planet names Vietnamese
 */
function bccm_planet_names_vi() {
    return [
        'Sun'        => 'Mặt Trời',
        'Moon'       => 'Mặt Trăng',
        'Mercury'    => 'Sao Thủy',
        'Venus'      => 'Sao Kim',
        'Mars'       => 'Sao Hỏa',
        'Jupiter'    => 'Sao Mộc',
        'Saturn'     => 'Sao Thổ',
        'Uranus'     => 'Thiên Vương',
        'Neptune'    => 'Hải Vương',
        'Pluto'      => 'Diêm Vương',
        'Ascendant'  => 'Cung Mọc (ASC)',
        'Descendant' => 'Cung Lặn (DSC)',
        'MC'         => 'Thiên Đỉnh (MC)',
        'IC'         => 'Thiên Đáy (IC)',
        'Chiron'     => 'Chiron',
        'Lilith'     => 'Lilith',
        'True Node'  => 'Bắc Giao Điểm',
        'Mean Node'  => 'Bắc Giao Điểm (TB)',
        'Ceres'      => 'Ceres',
        'Vesta'      => 'Vesta',
        'Juno'       => 'Juno',
        'Pallas'     => 'Pallas',
    ];
}

/**
 * Aspect names Vietnamese
 */
function bccm_aspect_names_vi() {
    return [
        'Conjunction'    => 'Hợp (0°)',
        'Opposition'     => 'Đối (180°)',
        'Trine'          => 'Tam hợp (120°)',
        'Square'         => 'Vuông góc (90°)',
        'Sextile'        => 'Lục hợp (60°)',
        'Semi-Sextile'   => 'Bán lục hợp (30°)',
        'Quintile'       => 'Ngũ hợp (72°)',
        'Quincunx'       => 'Bất đồng vị (150°)',
        'Sesquiquadrate' => 'Bán tam vuông (135°)',
        'Septile'        => 'Thất phân (51.43°)',
        'Octile'         => 'Bát phân (45°)',
        'Novile'         => 'Cửu phân (40°)',
    ];
}

/**
 * Aspect theoretical angles for orb calculation
 */
function bccm_aspect_angles() {
    return [
        'Conjunction'    => 0,
        'Opposition'     => 180,
        'Trine'          => 120,
        'Square'         => 90,
        'Sextile'        => 60,
        'Semi-Sextile'   => 30,
        'Quintile'       => 72,
        'Quincunx'       => 150,
        'Sesquiquadrate' => 135,
        'Septile'        => 51.4286,
        'Octile'         => 45,
        'Novile'         => 40,
    ];
}

/**
 * Aspect symbols for display
 */
function bccm_aspect_symbols() {
    return [
        'Conjunction'    => '☌',
        'Opposition'     => '☍',
        'Trine'          => '△',
        'Square'         => '□',
        'Sextile'        => '⚹',
        'Semi-Sextile'   => '⚺',
        'Quintile'       => 'Q',
        'Quincunx'       => '⚻',
        'Sesquiquadrate' => '⚼',
        'Septile'        => 'S',
        'Octile'         => '∠',
        'Novile'         => 'N',
    ];
}

/**
 * Aspect colors for display
 */
function bccm_aspect_colors() {
    return [
        'Conjunction'    => '#fbbf24',
        'Opposition'     => '#ef4444',
        'Trine'          => '#22c55e',
        'Square'         => '#f97316',
        'Sextile'        => '#3b82f6',
        'Semi-Sextile'   => '#6ee7b7',
        'Quintile'       => '#a78bfa',
        'Quincunx'       => '#a855f7',
        'Sesquiquadrate' => '#fb923c',
        'Septile'        => '#14b8a6',
        'Octile'         => '#f472b6',
        'Novile'         => '#38bdf8',
    ];
}

/**
 * Convert decimal degrees to DMS (Degrees, Minutes, Seconds) format
 *
 * @param float  $decimal  Decimal degrees (e.g. 17.4456)
 * @param bool   $show_seconds  Whether to show seconds
 * @return string  Formatted as "17° 26' 44\""
 */
function bccm_astro_decimal_to_dms($decimal, $show_seconds = true) {
    $decimal = abs(floatval($decimal));
    $degrees = floor($decimal);
    $min_dec = ($decimal - $degrees) * 60;
    $minutes = floor($min_dec);
    $seconds = round(($min_dec - $minutes) * 60);

    if ($seconds == 60) {
        $seconds = 0;
        $minutes++;
    }
    if ($minutes == 60) {
        $minutes = 0;
        $degrees++;
    }

    if ($show_seconds) {
        return sprintf("%d° %02d' %02d\"", $degrees, $minutes, $seconds);
    }
    return sprintf("%d° %02d'", $degrees, $minutes);
}

/**
 * Calculate orb for an aspect between two planets using their fullDegree values
 *
 * @param float  $full_degree_1  fullDegree of planet 1
 * @param float  $full_degree_2  fullDegree of planet 2
 * @param string $aspect_type    Aspect name (e.g. 'Conjunction')
 * @return float|false  Orb in degrees or false if aspect type unknown
 */
function bccm_astro_calculate_orb($full_degree_1, $full_degree_2, $aspect_type) {
    $angles = bccm_aspect_angles();
    if (!isset($angles[$aspect_type])) return false;

    $diff = abs($full_degree_1 - $full_degree_2);
    if ($diff > 180) {
        $diff = 360 - $diff;
    }

    return round(abs($diff - $angles[$aspect_type]), 4);
}

/**
 * Enrich aspects data with calculated orb from planet positions
 *
 * @param array $aspects    Raw aspects from API
 * @param array $positions  Parsed positions from bccm_astro_parse_planets()
 * @return array  Aspects enriched with 'orb', 'orb_dms', sorted by planet
 */
function bccm_astro_enrich_aspects($aspects, $positions) {
    $enriched = [];

    foreach ($aspects as $asp) {
        $p1   = is_array($asp['planet_1'] ?? null) ? ($asp['planet_1']['en'] ?? '') : ($asp['planet_1'] ?? '');
        $p2   = is_array($asp['planet_2'] ?? null) ? ($asp['planet_2']['en'] ?? '') : ($asp['planet_2'] ?? '');
        $type = is_array($asp['aspect'] ?? null)   ? ($asp['aspect']['en'] ?? '')   : ($asp['aspect'] ?? '');

        $orb = false;
        if (isset($positions[$p1]['full_degree']) && isset($positions[$p2]['full_degree'])) {
            $orb = bccm_astro_calculate_orb(
                $positions[$p1]['full_degree'],
                $positions[$p2]['full_degree'],
                $type
            );
        }

        $enriched[] = [
            'planet_1_en' => $p1,
            'planet_2_en' => $p2,
            'aspect_en'   => $type,
            'orb'         => $orb !== false ? $orb : null,
            'orb_dms'     => $orb !== false ? bccm_astro_decimal_to_dms($orb, true) : '—',
        ];
    }

    return $enriched;
}

/**
 * Group enriched aspects by planet_1
 *
 * @param array $enriched  From bccm_astro_enrich_aspects()
 * @return array  ['Sun' => [...aspects], 'Moon' => [...aspects], ...]
 */
function bccm_astro_group_aspects_by_planet($enriched) {
    $grouped = [];
    $planet_order = [
        'Sun', 'Moon', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn',
        'Uranus', 'Neptune', 'Pluto', 'Chiron', 'Lilith', 'True Node',
        'Mean Node', 'Ceres', 'Vesta', 'Juno', 'Pallas',
        'Ascendant', 'Descendant', 'MC', 'IC',
    ];

    // Initialize in order
    foreach ($planet_order as $pname) {
        $grouped[$pname] = [];
    }

    foreach ($enriched as $asp) {
        $p1 = $asp['planet_1_en'];
        if (!isset($grouped[$p1])) $grouped[$p1] = [];
        $grouped[$p1][] = $asp;
    }

    // Remove empty groups
    return array_filter($grouped);
}

/**
 * Determine which house a planet is in based on house cusps
 *
 * @param float $planet_degree  Planet fullDegree (0-360)
 * @param array $houses         Raw houses array from API
 * @return int  House number (1-12)
 */
function bccm_astro_planet_in_house($planet_degree, $houses) {
    if (empty($houses)) return 0;

    $cusps = [];
    foreach ($houses as $h) {
        $num = $h['House'] ?? ($h['house'] ?? 0);
        $deg = $h['degree'] ?? 0;
        if ($num > 0) $cusps[$num] = floatval($deg);
    }

    if (count($cusps) < 12) return 0;

    for ($i = 1; $i <= 12; $i++) {
        $next = ($i % 12) + 1;
        $cusp_start = $cusps[$i];
        $cusp_end   = $cusps[$next];

        if ($cusp_end > $cusp_start) {
            // Normal case
            if ($planet_degree >= $cusp_start && $planet_degree < $cusp_end) return $i;
        } else {
            // Wraps around 0°
            if ($planet_degree >= $cusp_start || $planet_degree < $cusp_end) return $i;
        }
    }

    return 1; // fallback
}

/**
 * House meanings Vietnamese
 */
function bccm_house_meanings_vi() {
    return [
        1  => 'Bản thân, ngoại hình, cá tính',
        2  => 'Tài chính, giá trị bản thân',
        3  => 'Giao tiếp, học tập, anh chị em',
        4  => 'Gia đình, nhà cửa, gốc rễ',
        5  => 'Sáng tạo, tình yêu, con cái',
        6  => 'Sức khỏe, công việc hàng ngày',
        7  => 'Đối tác, hôn nhân, hợp tác',
        8  => 'Chuyển hóa, tái sinh, bí ẩn',
        9  => 'Triết học, du lịch, giáo dục cao',
        10 => 'Sự nghiệp, danh tiếng, địa vị',
        11 => 'Bạn bè, cộng đồng, lý tưởng',
        12 => 'Tâm linh, tiềm thức, hy sinh',
    ];
}

/* =====================================================================
 * CHART PATTERNS DETECTION (Astro-Charts style)
 * =====================================================================*/

/**
 * Planet rulership mapping: which planet rules which sign
 */
function bccm_planet_rulerships() {
    return [
        'Sun'     => ['Leo'],
        'Moon'    => ['Cancer'],
        'Mercury' => ['Gemini', 'Virgo'],
        'Venus'   => ['Taurus', 'Libra'],
        'Mars'    => ['Aries', 'Scorpio'],
        'Jupiter' => ['Sagittarius', 'Pisces'],
        'Saturn'  => ['Capricorn', 'Aquarius'],
        'Uranus'  => ['Aquarius'],
        'Neptune' => ['Pisces'],
        'Pluto'   => ['Scorpio'],
    ];
}

/**
 * Detect aspect patterns in a natal chart
 *
 * Detects: Stellium, T-Square, Grand Trine, Grand Cross, Yod, Cradle,
 *          Multiple Planet Conjunction, Multiple Planet Opposition
 *
 * @param array $positions  Parsed positions (from bccm_astro_parse_planets)
 * @param array $aspects    Raw aspects from API
 * @return array  Array of detected patterns, each with 'type', 'type_vi', 'planets', 'description'
 */
function bccm_detect_chart_patterns($positions, $aspects) {
    if (empty($positions) || empty($aspects)) return [];

    $enriched = bccm_astro_enrich_aspects($aspects, $positions);
    $patterns = [];

    // Build adjacency map: aspect_type => [p1|p2, ...]
    $aspect_map = []; // "p1|p2" => type
    $planet_aspects = []; // planet => [type => [connected_planets]]
    foreach ($enriched as $asp) {
        $p1 = $asp['planet_1_en'];
        $p2 = $asp['planet_2_en'];
        $type = $asp['aspect_en'];
        $orb = $asp['orb'] ?? 99;
        // Only use aspects with reasonable orb for patterns
        if ($orb > 10) continue;

        $key = $p1 < $p2 ? "$p1|$p2" : "$p2|$p1";
        $aspect_map[$key] = ['type' => $type, 'orb' => $orb, 'p1' => $p1, 'p2' => $p2];

        if (!isset($planet_aspects[$p1][$type])) $planet_aspects[$p1][$type] = [];
        $planet_aspects[$p1][$type][] = $p2;

        if (!isset($planet_aspects[$p2][$type])) $planet_aspects[$p2][$type] = [];
        $planet_aspects[$p2][$type][] = $p1;
    }

    // Helper: check if two planets have a specific aspect
    $has_aspect = function($p1, $p2, $type) use ($aspect_map) {
        $key = $p1 < $p2 ? "$p1|$p2" : "$p2|$p1";
        return isset($aspect_map[$key]) && $aspect_map[$key]['type'] === $type;
    };

    $inner_planets = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Chiron','True Node','Ascendant','MC'];
    $available = array_filter($inner_planets, function($p) use ($positions) {
        return isset($positions[$p]);
    });

    // ── 1. STELLIUM (3+ planets in the same sign) ──
    $by_sign = [];
    foreach ($available as $p) {
        if (in_array($p, ['Ascendant','MC'])) continue;
        $sign = $positions[$p]['sign_en'] ?? '';
        if ($sign) $by_sign[$sign][] = $p;
    }
    foreach ($by_sign as $sign => $planets) {
        if (count($planets) >= 3) {
            $signs = bccm_zodiac_signs();
            $sign_vi = '';
            foreach ($signs as $s) {
                if ($s['en'] === $sign) { $sign_vi = $s['vi']; break; }
            }
            $patterns[] = [
                'type'    => 'Stellium',
                'type_vi' => 'Tập trung sao (Stellium)',
                'planets' => $planets,
                'description' => count($planets) . ' hành tinh tập trung trong cung ' . $sign_vi . ' (' . $sign . ')',
                'icon'    => '⭐',
            ];
        }
    }

    // ── 2. T-SQUARE (2 planets in Opposition, both Square to a 3rd) ──
    foreach ($aspect_map as $key => $data) {
        if ($data['type'] !== 'Opposition') continue;
        $p1 = $data['p1'];
        $p2 = $data['p2'];
        foreach ($available as $p3) {
            if ($p3 === $p1 || $p3 === $p2) continue;
            if ($has_aspect($p1, $p3, 'Square') && $has_aspect($p2, $p3, 'Square')) {
                // Check for duplicate (same 3 planets)
                $trio = [$p1, $p2, $p3];
                sort($trio);
                $trio_key = implode('|', $trio);
                $already = false;
                foreach ($patterns as $pat) {
                    if ($pat['type'] === 'T-Square') {
                        $existing = $pat['planets'];
                        sort($existing);
                        if (implode('|', $existing) === $trio_key) { $already = true; break; }
                    }
                }
                if (!$already) {
                    $patterns[] = [
                        'type'    => 'T-Square',
                        'type_vi' => 'Tam Giác Vuông (T-Square)',
                        'planets' => $trio,
                        'description' => 'Đối lập + Vuông góc tạo áp lực phát triển mạnh mẽ',
                        'icon'    => '🔺',
                    ];
                }
            }
        }
    }

    // ── 3. GRAND TRINE (3 planets each in Trine to the other 2) ──
    $avail_list = array_values($available);
    $n = count($avail_list);
    for ($i = 0; $i < $n - 2; $i++) {
        for ($j = $i + 1; $j < $n - 1; $j++) {
            for ($k = $j + 1; $k < $n; $k++) {
                $a = $avail_list[$i]; $b = $avail_list[$j]; $c = $avail_list[$k];
                if ($has_aspect($a, $b, 'Trine') && $has_aspect($b, $c, 'Trine') && $has_aspect($a, $c, 'Trine')) {
                    // Determine element
                    $el_a = bccm_zodiac_signs()[$positions[$a]['sign_number'] ?? 0]['element'] ?? '';
                    $patterns[] = [
                        'type'    => 'Grand Trine',
                        'type_vi' => 'Đại Tam Hợp (Grand Trine)',
                        'planets' => [$a, $b, $c],
                        'description' => 'Tam giác hài hòa trong nguyên tố ' . $el_a . ' — tài năng tự nhiên',
                        'icon'    => '🔱',
                    ];
                }
            }
        }
    }

    // ── 4. GRAND CROSS (4 planets: 2 pairs of Opposition, all Square to adjacent) ──
    foreach ($aspect_map as $key1 => $d1) {
        if ($d1['type'] !== 'Opposition') continue;
        foreach ($aspect_map as $key2 => $d2) {
            if ($key2 <= $key1) continue;
            if ($d2['type'] !== 'Opposition') continue;
            $p = [$d1['p1'], $d1['p2'], $d2['p1'], $d2['p2']];
            if (count(array_unique($p)) !== 4) continue;
            // Check all 4 squares
            if ($has_aspect($d1['p1'], $d2['p1'], 'Square') && $has_aspect($d1['p1'], $d2['p2'], 'Square') &&
                $has_aspect($d1['p2'], $d2['p1'], 'Square') && $has_aspect($d1['p2'], $d2['p2'], 'Square')) {
                sort($p);
                $already = false;
                foreach ($patterns as $pat) {
                    if ($pat['type'] === 'Grand Cross') {
                        $ex = $pat['planets']; sort($ex);
                        if (implode('|', $ex) === implode('|', $p)) { $already = true; break; }
                    }
                }
                if (!$already) {
                    $patterns[] = [
                        'type'    => 'Grand Cross',
                        'type_vi' => 'Đại Thập Giá (Grand Cross)',
                        'planets' => $p,
                        'description' => '4 hành tinh tạo thành thập giá — thử thách và sức mạnh phi thường',
                        'icon'    => '✝️',
                    ];
                }
            }
        }
    }

    // ── 5. YOD (Finger of God: 2 planets Sextile, both Quincunx to a 3rd) ──
    foreach ($aspect_map as $key => $data) {
        if ($data['type'] !== 'Sextile') continue;
        $p1 = $data['p1'];
        $p2 = $data['p2'];
        foreach ($available as $p3) {
            if ($p3 === $p1 || $p3 === $p2) continue;
            if ($has_aspect($p1, $p3, 'Quincunx') && $has_aspect($p2, $p3, 'Quincunx')) {
                $trio = [$p1, $p2, $p3];
                sort($trio);
                $trio_key = implode('|', $trio);
                $already = false;
                foreach ($patterns as $pat) {
                    if ($pat['type'] === 'Yod') {
                        $ex = $pat['planets']; sort($ex);
                        if (implode('|', $ex) === $trio_key) { $already = true; break; }
                    }
                }
                if (!$already) {
                    $planet_vi = bccm_planet_names_vi();
                    $apex_vi = $planet_vi[$p3] ?? $p3;
                    $patterns[] = [
                        'type'    => 'Yod',
                        'type_vi' => 'Ngón Tay Chúa (Yod)',
                        'planets' => $trio,
                        'description' => 'Sứ mệnh đặc biệt qua ' . $apex_vi . ' — điều chỉnh và chuyển hóa',
                        'icon'    => '☝️',
                    ];
                }
            }
        }
    }

    // ── 6. CRADLE (Sextile-Trine-Sextile-Opposition forming a cradle) ──
    // Look for 4+ planets connected by alternating sextiles and trines with an opposition base
    foreach ($aspect_map as $key => $data) {
        if ($data['type'] !== 'Opposition') continue;
        $p1 = $data['p1'];
        $p2 = $data['p2'];
        // Find planets that sextile one end and trine the other
        $cradle_members = [$p1, $p2];
        foreach ($available as $p3) {
            if ($p3 === $p1 || $p3 === $p2) continue;
            if (($has_aspect($p1, $p3, 'Sextile') && $has_aspect($p2, $p3, 'Trine')) ||
                ($has_aspect($p1, $p3, 'Trine') && $has_aspect($p2, $p3, 'Sextile'))) {
                $cradle_members[] = $p3;
            }
        }
        if (count($cradle_members) >= 4) {
            sort($cradle_members);
            $cradle_key = implode('|', $cradle_members);
            $already = false;
            foreach ($patterns as $pat) {
                if ($pat['type'] === 'Cradle') {
                    $ex = $pat['planets']; sort($ex);
                    if (implode('|', $ex) === $cradle_key) { $already = true; break; }
                }
            }
            if (!$already) {
                $patterns[] = [
                    'type'    => 'Cradle',
                    'type_vi' => 'Nôi Nang (Cradle)',
                    'planets' => $cradle_members,
                    'description' => count($cradle_members) . ' hành tinh tạo cấu trúc bảo vệ — tài năng bẩm sinh',
                    'icon'    => '🌙',
                ];
            }
        }
    }

    // ── 7. MULTIPLE CONJUNCTIONS (3+ planets within ~10°) ──
    foreach ($aspect_map as $key => $data) {
        if ($data['type'] !== 'Conjunction') continue;
        $p1 = $data['p1'];
        $p2 = $data['p2'];
        // Find more planets conjunct to either
        $cluster = [$p1, $p2];
        foreach ($available as $p3) {
            if (in_array($p3, $cluster)) continue;
            foreach ($cluster as $existing) {
                if ($has_aspect($p3, $existing, 'Conjunction')) {
                    $cluster[] = $p3;
                    break;
                }
            }
        }
        if (count($cluster) >= 3) {
            $cluster = array_unique($cluster);
            sort($cluster);
            $cluster_key = implode('|', $cluster);
            $already = false;
            foreach ($patterns as $pat) {
                if ($pat['type'] === 'Multiple Conjunction') {
                    $ex = $pat['planets']; sort($ex);
                    if (implode('|', $ex) === $cluster_key) { $already = true; break; }
                }
            }
            if (!$already) {
                $patterns[] = [
                    'type'    => 'Multiple Conjunction',
                    'type_vi' => 'Đa Hợp (Multiple Conjunction)',
                    'planets' => array_values($cluster),
                    'description' => count($cluster) . ' hành tinh hợp nhau — năng lượng tập trung mạnh mẽ',
                    'icon'    => '🔮',
                ];
            }
        }
    }

    // ── 8. MULTIPLE OPPOSITION (3+ planets in opposition cluster) ──
    foreach ($aspect_map as $key => $data) {
        if ($data['type'] !== 'Opposition') continue;
        $p1 = $data['p1'];
        $p2 = $data['p2'];
        $cluster = [$p1, $p2];
        foreach ($available as $p3) {
            if (in_array($p3, $cluster)) continue;
            foreach ($cluster as $existing) {
                if ($has_aspect($p3, $existing, 'Opposition') || $has_aspect($p3, $existing, 'Conjunction')) {
                    $cluster[] = $p3;
                    break;
                }
            }
        }
        if (count($cluster) >= 3) {
            $cluster = array_unique($cluster);
            sort($cluster);
            $cluster_key = implode('|', $cluster);
            $already = false;
            foreach ($patterns as $pat) {
                if ($pat['type'] === 'Multiple Opposition') {
                    $ex = $pat['planets']; sort($ex);
                    if (implode('|', $ex) === $cluster_key) { $already = true; break; }
                }
            }
            if (!$already) {
                $patterns[] = [
                    'type'    => 'Multiple Opposition',
                    'type_vi' => 'Đa Đối (Multiple Opposition)',
                    'planets' => array_values($cluster),
                    'description' => count($cluster) . ' hành tinh trong trục đối lập — cần cân bằng',
                    'icon'    => '⚖️',
                ];
            }
        }
    }

    return $patterns;
}

/* =====================================================================
 * SPECIAL FEATURES ANALYSIS (Astro-Charts style)
 * =====================================================================*/

/**
 * Analyze special features of a natal chart
 *
 * Detects: dominant element/mode/sign, moon phase, rising planet,
 *          planets in own sign (rulership), quadrant distribution, most frequent aspect
 *
 * @param array $positions   Parsed positions
 * @param array $aspects     Raw aspects from API
 * @param array $houses_raw  Raw houses array
 * @param array $birth_data  Birth data (for moon phase)
 * @return array  Array of special features, each with 'icon', 'text', 'text_vi'
 */
function bccm_analyze_special_features($positions, $aspects, $houses_raw = [], $birth_data = []) {
    if (empty($positions)) return [];

    $features = [];
    $signs = bccm_zodiac_signs();
    $planet_vi = bccm_planet_names_vi();
    $inner = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Ascendant','MC'];

    // ── 1. MOON PHASE ──
    if (isset($positions['Sun']['full_degree']) && isset($positions['Moon']['full_degree'])) {
        $sun_d = $positions['Sun']['full_degree'];
        $moon_d = $positions['Moon']['full_degree'];
        $angle = fmod($moon_d - $sun_d + 360, 360);

        $phases = [
            [0,    45,  '🌑', 'Trăng mới (New Moon)',              'Mặt trăng trong pha Trăng Mới — khởi đầu, tiềm năng'],
            [45,   90,  '🌒', 'Trăng lưỡi liềm đầu (Waxing Crescent)', 'Mặt trăng đang tăng dần — nỗ lực và xây dựng'],
            [90,   135, '🌓', 'Trăng bán nguyệt đầu (First Quarter)',  'Mặt trăng ở pha Bán nguyệt — hành động quyết liệt'],
            [135,  180, '🌔', 'Trăng khuyết đầu (Waxing Gibbous)',     'Mặt trăng gần tròn — hoàn thiện và phân tích'],
            [180,  225, '🌕', 'Trăng tròn (Full Moon)',            'Mặt trăng tròn — nhận thức đầy đủ, soi sáng'],
            [225,  270, '🌖', 'Trăng khuyết cuối (Waning Gibbous)',    'Mặt trăng đang giảm — chia sẻ và truyền đạt'],
            [270,  315, '🌗', 'Trăng bán nguyệt cuối (Last Quarter)',  'Mặt trăng bán nguyệt — giải phóng và chuyển hóa'],
            [315,  360, '🌘', 'Trăng lưỡi liềm cuối (Waning Crescent)', 'Mặt trăng sắp tối — buông bỏ, chuẩn bị chu kỳ mới'],
        ];

        foreach ($phases as $ph) {
            if ($angle >= $ph[0] && $angle < $ph[1]) {
                $features[] = ['icon' => $ph[2], 'text' => $ph[3], 'text_vi' => $ph[4]];
                break;
            }
        }
    }

    // ── 2. DOMINANT ELEMENT among inner planets ──
    $elements = ['Lửa' => 0, 'Đất' => 0, 'Khí' => 0, 'Nước' => 0];
    $element_icons = ['Lửa' => '🔥', 'Đất' => '🌍', 'Khí' => '💨', 'Nước' => '💧'];
    foreach ($inner as $pname) {
        if (!isset($positions[$pname])) continue;
        $sn = $positions[$pname]['sign_number'] ?? 0;
        $el = $signs[$sn]['element'] ?? '';
        if (isset($elements[$el])) $elements[$el]++;
    }
    arsort($elements);
    $dom_el = array_key_first($elements);
    $dom_el_count = $elements[$dom_el];
    if ($dom_el_count >= 3) {
        $features[] = [
            'icon'    => $element_icons[$dom_el] ?? '🌟',
            'text'    => "Nguyên tố $dom_el chiếm ưu thế ($dom_el_count hành tinh nội)",
            'text_vi' => "Nguyên tố $dom_el chiếm ưu thế trong các hành tinh nội",
        ];
    }

    // ── 3. DOMINANT MODALITY among inner planets ──
    $modes = ['Cardinal' => 0, 'Fixed' => 0, 'Mutable' => 0];
    $mode_vi = ['Cardinal' => 'Khởi Xướng', 'Fixed' => 'Cố Định', 'Mutable' => 'Linh Hoạt'];
    $mode_icons = ['Cardinal' => '🚀', 'Fixed' => '🏔️', 'Mutable' => '🔄'];
    foreach ($inner as $pname) {
        if (!isset($positions[$pname])) continue;
        $sn = $positions[$pname]['sign_number'] ?? 0;
        $mod = $signs[$sn]['modality'] ?? '';
        if (isset($modes[$mod])) $modes[$mod]++;
    }
    arsort($modes);
    $dom_mode = array_key_first($modes);
    $dom_mode_count = $modes[$dom_mode];
    if ($dom_mode_count >= 3) {
        $features[] = [
            'icon'    => $mode_icons[$dom_mode] ?? '📊',
            'text'    => "Tính chất " . $mode_vi[$dom_mode] . " ($dom_mode) chiếm ưu thế ($dom_mode_count hành tinh nội)",
            'text_vi' => "Tính chất " . $mode_vi[$dom_mode] . " chiếm ưu thế trong các hành tinh nội",
        ];
    }

    // ── 4. DOMINANT SIGN (3+ inner planets in same sign) ──
    $sign_count = [];
    foreach ($inner as $pname) {
        if (!isset($positions[$pname])) continue;
        $sign = $positions[$pname]['sign_en'] ?? '';
        if ($sign) {
            if (!isset($sign_count[$sign])) $sign_count[$sign] = 0;
            $sign_count[$sign]++;
        }
    }
    arsort($sign_count);
    foreach ($sign_count as $sign => $cnt) {
        if ($cnt >= 3) {
            $sign_vi = '';
            $sign_sym = '';
            foreach ($signs as $s) {
                if ($s['en'] === $sign) { $sign_vi = $s['vi']; $sign_sym = $s['symbol']; break; }
            }
            $features[] = [
                'icon'    => $sign_sym ?: '♈',
                'text'    => "Cung $sign_vi ($sign) chứa phần lớn hành tinh nội ($cnt hành tinh)",
                'text_vi' => "Cung $sign_vi chứa phần lớn các hành tinh nội",
            ];
            break;
        }
    }

    // ── 5. QUADRANT ANALYSIS ──
    if (!empty($houses_raw)) {
        $quads = [1 => 0, 2 => 0, 3 => 0, 4 => 0]; // Q1=H1-3, Q2=H4-6, Q3=H7-9, Q4=H10-12
        $quad_names = [
            1 => ['Dưới Trái (Bản thân)',  'Phát triển cá nhân'],
            2 => ['Dưới Phải (Tài nguyên)', 'Gia đình & tài chính'],
            3 => ['Trên Phải (Quan hệ)',    'Quan hệ & xã hội'],
            4 => ['Trên Trái (Sự nghiệp)',  'Sự nghiệp & cộng đồng'],
        ];
        $quad_empty = [];
        $check_planets = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto'];
        foreach ($check_planets as $pname) {
            if (!isset($positions[$pname])) continue;
            $h = bccm_astro_planet_in_house($positions[$pname]['full_degree'], $houses_raw);
            if ($h >= 1 && $h <= 3) $quads[1]++;
            elseif ($h >= 4 && $h <= 6) $quads[2]++;
            elseif ($h >= 7 && $h <= 9) $quads[3]++;
            elseif ($h >= 10 && $h <= 12) $quads[4]++;
        }

        // Find dominant quadrant
        arsort($quads);
        $dom_q = array_key_first($quads);
        $dom_q_count = $quads[$dom_q];
        if ($dom_q_count >= 4) {
            $features[] = [
                'icon' => '📐',
                'text' => 'Đa số hành tinh tập trung ở Phần tư ' . $quad_names[$dom_q][0],
                'text_vi' => $quad_names[$dom_q][1] . ' — trọng tâm của cuộc sống',
            ];
        }

        // Find empty quadrants
        foreach ($quads as $q => $cnt) {
            if ($cnt === 0) {
                $features[] = [
                    'icon' => '⬜',
                    'text' => 'Phần tư ' . $quad_names[$q][0] . ' trống',
                    'text_vi' => 'Phần tư ' . $quad_names[$q][0] . ' không có hành tinh',
                ];
            }
        }
    }

    // ── 6. RISING PLANET (planet near ASC, within same sign or house 1) ──
    if (isset($positions['Ascendant'])) {
        $asc_deg = $positions['Ascendant']['full_degree'];
        $rising = [];
        foreach (['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn'] as $pname) {
            if (!isset($positions[$pname])) continue;
            $diff = abs($positions[$pname]['full_degree'] - $asc_deg);
            if ($diff > 180) $diff = 360 - $diff;
            if ($diff <= 10) {
                $rising[] = $planet_vi[$pname] ?? $pname;
            }
        }
        if (!empty($rising)) {
            $features[] = [
                'icon' => '🌅',
                'text' => implode(', ', $rising) . ' đang mọc (gần ASC)',
                'text_vi' => implode(', ', $rising) . ' gần Cung Mọc — ảnh hưởng mạnh đến hình ảnh bên ngoài',
            ];
        }
    }

    // ── 7. PLANETS IN OWN SIGN (Rulership / Dignity) ──
    $rulerships = bccm_planet_rulerships();
    $in_own_sign = [];
    foreach ($rulerships as $pname => $ruled_signs) {
        if (!isset($positions[$pname])) continue;
        $sign = $positions[$pname]['sign_en'] ?? '';
        if (in_array($sign, $ruled_signs)) {
            $pvi = $planet_vi[$pname] ?? $pname;
            $svi = '';
            foreach ($signs as $s) {
                if ($s['en'] === $sign) { $svi = $s['vi']; break; }
            }
            $in_own_sign[] = "$pvi trong $svi";
        }
    }
    if (!empty($in_own_sign)) {
        $features[] = [
            'icon' => '👑',
            'text' => implode(', ', $in_own_sign) . ' ở cung chủ quản',
            'text_vi' => implode('; ', $in_own_sign) . ' — hành tinh phát huy nguyên lực tối đa',
        ];
    }

    // ── 8. MOST FREQUENT ASPECT type ──
    if (!empty($aspects)) {
        $enriched = bccm_astro_enrich_aspects($aspects, $positions);
        $aspect_stats = [];
        foreach ($enriched as $asp) {
            $type = $asp['aspect_en'];
            if (!isset($aspect_stats[$type])) $aspect_stats[$type] = 0;
            $aspect_stats[$type]++;
        }
        arsort($aspect_stats);
        $top_aspect = array_key_first($aspect_stats);
        $top_count = $aspect_stats[$top_aspect];
        if ($top_count >= 3) {
            $aspect_vi = bccm_aspect_names_vi();
            $aspect_symbols = bccm_aspect_symbols();
            $sym = $aspect_symbols[$top_aspect] ?? '';
            $features[] = [
                'icon' => $sym ?: '🔗',
                'text' => ($aspect_vi[$top_aspect] ?? $top_aspect) . ' xuất hiện nhiều nhất — ' . $top_count . ' lần',
                'text_vi' => 'Góc chiếu ' . ($aspect_vi[$top_aspect] ?? $top_aspect) . ' chiếm ưu thế (' . $top_count . ' lần)',
            ];
        }
    }

    // ── 9. HEMISPHERE (Eastern/Western, Northern/Southern) ──
    if (!empty($houses_raw)) {
        $east = 0; $west = 0; // East = H10-3 (left), West = H4-9 (right)
        $north = 0; $south = 0; // North = H1-6 (below horizon), South = H7-12 (above)
        $check = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto'];
        foreach ($check as $pname) {
            if (!isset($positions[$pname])) continue;
            $h = bccm_astro_planet_in_house($positions[$pname]['full_degree'], $houses_raw);
            if (in_array($h, [10,11,12,1,2,3])) $east++; else $west++;
            if ($h >= 1 && $h <= 6) $north++; else $south++;
        }
        if ($east >= 7) {
            $features[] = [
                'icon' => '⬅️',
                'text' => 'Hành tinh tập trung ở nửa Đông (trái)',
                'text_vi' => 'Bạn chủ động tạo dựng vận mệnh — ít phụ thuộc người khác',
            ];
        } elseif ($west >= 7) {
            $features[] = [
                'icon' => '➡️',
                'text' => 'Hành tinh tập trung ở nửa Tây (phải)',
                'text_vi' => 'Bạn phát triển qua các mối quan hệ và sự hợp tác',
            ];
        }
        if ($south >= 7) {
            $features[] = [
                'icon' => '⬆️',
                'text' => 'Hành tinh tập trung ở nửa trên (trên đường chân trời)',
                'text_vi' => 'Bạn hướng đến sự nghiệp công khai và thế giới bên ngoài',
            ];
        } elseif ($north >= 7) {
            $features[] = [
                'icon' => '⬇️',
                'text' => 'Hành tinh tập trung ở nửa dưới (dưới đường chân trời)',
                'text_vi' => 'Bạn hướng nội, tập trung vào gia đình và đời sống cá nhân',
            ];
        }
    }

    return $features;
}

/* =====================================================================
 * ASTROVIET CHART INTEGRATION
 * =====================================================================*/

/**
 * Build AstroViet natal wheel chart URL
 *
 * Generates a URL to https://astroviet.com/chart/natal_wheel.php
 * that renders a professional natal wheel image from our planet/house data.
 *
 * @param array  $positions   Parsed positions array (from bccm_astro_parse_planets)
 * @param array  $houses_raw  Raw houses array from API
 * @param string $name        Coachee display name
 * @param array  $birth_data  Keys: day, month, year, hour, minute, birth_place, latitude, longitude
 * @return string  Full URL to the natal wheel chart image
 */
function bccm_build_astroviet_wheel_url($positions, $houses_raw, $name = '', $birth_data = []) {
    if (empty($positions) || empty($houses_raw)) return '';

    // ── Map planet fullDegrees to AstroViet p1 array (31 elements) ──
    // Indices 0-14: planets, 15-26: house cusps 1-12, 27: house 1 repeat, 28-30: extras
    $planet_map = [
        0  => 'Sun',
        1  => 'Moon',
        2  => 'Mercury',
        3  => 'Venus',
        4  => 'Mars',
        5  => 'Jupiter',
        6  => 'Saturn',
        7  => 'Uranus',
        8  => 'Neptune',
        9  => 'Pluto',
        10 => 'Chiron',
        11 => 'Lilith',
        12 => 'True Node',
    ];

    $p1 = [];
    foreach ($planet_map as $idx => $pname) {
        $p1[$idx] = isset($positions[$pname]) ? round($positions[$pname]['full_degree'] ?? 0, 2) : 0;
    }

    // Index 13: Part of Fortune = ASC + Moon - Sun (mod 360)
    $asc_deg  = $positions['Ascendant']['full_degree'] ?? 0;
    $moon_deg = $positions['Moon']['full_degree'] ?? 0;
    $sun_deg  = $positions['Sun']['full_degree'] ?? 0;
    $pof = fmod($asc_deg + $moon_deg - $sun_deg + 360, 360);
    $p1[13] = round($pof, 2);

    // Index 14: Vertex (set to 180 if not available)
    $p1[14] = 180.00;

    // ── House cusps ──
    $cusps = [];
    foreach ($houses_raw as $h) {
        $num = $h['House'] ?? ($h['house'] ?? 0);
        $deg = $h['degree'] ?? 0;
        if ($num >= 1 && $num <= 12) {
            $cusps[$num] = round(floatval($deg), 2);
        }
    }
    if (count($cusps) < 12) return '';

    // p1 indices 15-26: house cusps 1-12
    for ($i = 1; $i <= 12; $i++) {
        $p1[14 + $i] = $cusps[$i] ?? 0;
    }
    // Index 27: house 1 repeat (closing the circle)
    $p1[27] = $cusps[1] ?? 0;
    // Indices 28-30: MC, MC-0.18, Vertex
    $p1[28] = $cusps[10] ?? 0;
    $p1[29] = round(($cusps[10] ?? 0) - 0.18, 2);
    $p1[30] = 180.00;

    // ── hc1: house cusps array (14 elements, 1-indexed) ──
    $hc1 = [];
    for ($i = 1; $i <= 12; $i++) {
        $hc1[$i] = $cusps[$i] ?? 0;
    }
    $hc1[13] = $cusps[1] ?? 0;  // closing
    $hc1[14] = $cusps[10] ?? 0; // MC

    // ── hpos: which house each planet is in (15 elements) ──
    $hpos = [];
    for ($i = 0; $i <= 14; $i++) {
        $hpos[$i] = bccm_astro_planet_in_house($p1[$i], $houses_raw);
    }

    // ── rx1: retrograde flags string ──
    $rx1 = '';
    foreach ($planet_map as $idx => $pname) {
        $is_retro = isset($positions[$pname]) ? ($positions[$pname]['is_retro'] ?? false) : false;
        $rx1 .= $is_retro ? 'R' : ' ';
    }

    // ── Labels ──
    $day  = intval($birth_data['day'] ?? 0);
    $mon  = intval($birth_data['month'] ?? 0);
    $year = intval($birth_data['year'] ?? 0);
    $hour = intval($birth_data['hour'] ?? 0);
    $min  = intval($birth_data['minute'] ?? 0);
    $lat  = floatval($birth_data['latitude'] ?? 21.0285);
    $lng  = floatval($birth_data['longitude'] ?? 105.8542);
    $place = $birth_data['birth_place'] ?? '';

    $l1 = $name ?: 'Natal Chart';
    $l2 = sprintf('%02d/%02d/%04d %02d:%02d', $day, $mon, $year, $hour, $min);
    $l3 = $place ?: 'Việt Nam';
    $l4 = sprintf('%s, %s', number_format($lat, 4), number_format($lng, 4));

    // ── Build URL ──
    $params = [
        'p1'   => serialize($p1),
        'hc1'  => serialize($hc1),
        'hpos' => serialize($hpos),
        'rx1'  => $rx1,
        'l1'   => $l1,
        'l2'   => $l2,
        'l3'   => $l3,
        'l4'   => $l4,
        'ubt1' => '0',
    ];

    return 'https://astroviet.com/chart/natal_wheel.php?' . http_build_query($params);
}

/**
 * Build AstroViet aspect grid chart URL
 *
 * @param array  $positions   Parsed positions array
 * @param array  $houses_raw  Raw houses array from API
 * @param array  $birth_data  Keys: day, month, year, hour, minute
 * @return string  Full URL to the aspect grid chart image
 */
function bccm_build_astroviet_aspect_grid_url($positions, $houses_raw, $birth_data = []) {
    if (empty($positions) || empty($houses_raw)) return '';

    // Reuse the wheel URL builder for shared parameters
    $planet_map = [
        0 => 'Sun', 1 => 'Moon', 2 => 'Mercury', 3 => 'Venus', 4 => 'Mars',
        5 => 'Jupiter', 6 => 'Saturn', 7 => 'Uranus', 8 => 'Neptune', 9 => 'Pluto',
        10 => 'Chiron', 11 => 'Lilith', 12 => 'True Node',
    ];

    $p1 = [];
    foreach ($planet_map as $idx => $pname) {
        $p1[$idx] = isset($positions[$pname]) ? round($positions[$pname]['full_degree'] ?? 0, 2) : 0;
    }

    $asc_deg = $positions['Ascendant']['full_degree'] ?? 0;
    $moon_deg = $positions['Moon']['full_degree'] ?? 0;
    $sun_deg = $positions['Sun']['full_degree'] ?? 0;
    $pof = fmod($asc_deg + $moon_deg - $sun_deg + 360, 360);
    $p1[13] = round($pof, 2);
    $p1[14] = 180.00;

    $cusps = [];
    foreach ($houses_raw as $h) {
        $num = $h['House'] ?? ($h['house'] ?? 0);
        $deg = $h['degree'] ?? 0;
        if ($num >= 1 && $num <= 12) {
            $cusps[$num] = round(floatval($deg), 2);
        }
    }
    if (count($cusps) < 12) return '';

    for ($i = 1; $i <= 12; $i++) {
        $p1[14 + $i] = $cusps[$i] ?? 0;
    }
    $p1[27] = $cusps[1] ?? 0;
    $p1[28] = $cusps[10] ?? 0;
    $p1[29] = round(($cusps[10] ?? 0) - 0.18, 2);
    $p1[30] = 180.00;

    // rx1
    $rx1 = '';
    foreach ($planet_map as $idx => $pname) {
        $is_retro = isset($positions[$pname]) ? ($positions[$pname]['is_retro'] ?? false) : false;
        $rx1 .= $is_retro ? 'R' : ' ';
    }

    $day  = intval($birth_data['day'] ?? 0);
    $mon  = intval($birth_data['month'] ?? 0);
    $year = intval($birth_data['year'] ?? 0);

    $params = [
        'p1'    => serialize($p1),
        'p2'    => serialize($p1), // natal = same as p1
        'rx1'   => $rx1,
        'rx2'   => $rx1, // natal = same as rx1
        'day'   => $day,
        'month' => $mon,
        'year'  => $year,
    ];

    return 'https://astroviet.com/chart/natal_aspect_grid.php?' . http_build_query($params);
}

/**
 * Render native HTML aspect grid (diagonal matrix)
 *
 * Creates a professional triangle grid showing aspects between all planet pairs,
 * similar to AstroViet's natal_aspect_grid but as pure HTML/CSS.
 *
 * @param array $positions  Parsed positions array
 * @param array $aspects    Raw aspects array from API
 * @return string  HTML table
 */
function bccm_render_aspect_grid_html($positions, $aspects) {
    if (empty($positions) || empty($aspects)) return '';

    $grid_planets = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Chiron','True Node'];
    $planet_symbols = [
        'Sun' => '☉', 'Moon' => '☽', 'Mercury' => '☿', 'Venus' => '♀', 'Mars' => '♂',
        'Jupiter' => '♃', 'Saturn' => '♄', 'Uranus' => '♅', 'Neptune' => '♆', 'Pluto' => '♇',
        'Chiron' => '⚷', 'True Node' => '☊',
    ];
    $aspect_symbols = bccm_aspect_symbols();
    $aspect_colors  = bccm_aspect_colors();

    // Filter to only planets that exist in positions
    $grid_planets = array_filter($grid_planets, function($p) use ($positions) {
        return isset($positions[$p]);
    });
    $grid_planets = array_values($grid_planets);
    $count = count($grid_planets);
    if ($count < 2) return '';

    // Build aspect lookup: "planet1|planet2" => aspect data
    $aspect_lookup = [];
    $enriched = bccm_astro_enrich_aspects($aspects, $positions);
    foreach ($enriched as $asp) {
        $p1 = $asp['planet_1_en'];
        $p2 = $asp['planet_2_en'];
        $aspect_lookup["$p1|$p2"] = $asp;
        $aspect_lookup["$p2|$p1"] = $asp;
    }

    $html = '<div class="bccm-aspect-grid-wrap">';
    $html .= '<table class="bccm-aspect-grid">';

    // Header row with planet symbols
    $html .= '<thead><tr><th></th>';
    for ($c = 0; $c < $count - 1; $c++) {
        $sym = $planet_symbols[$grid_planets[$c]] ?? substr($grid_planets[$c], 0, 2);
        $html .= '<th title="' . esc_attr($grid_planets[$c]) . '">' . $sym . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    // Rows: start from planet index 1  (row 0 = Moon, comparing with Sun, etc.)
    for ($r = 1; $r < $count; $r++) {
        $row_sym = $planet_symbols[$grid_planets[$r]] ?? substr($grid_planets[$r], 0, 2);
        $html .= '<tr>';
        $html .= '<th title="' . esc_attr($grid_planets[$r]) . '">' . $row_sym . '</th>';

        for ($c = 0; $c < $count - 1; $c++) {
            if ($c >= $r) {
                // Upper triangle: blank (no cell)
                $html .= '<td class="bccm-grid-empty"></td>';
            } else {
                // Lower triangle: show aspect
                $p1 = $grid_planets[$c];
                $p2 = $grid_planets[$r];
                $key = "$p1|$p2";
                if (isset($aspect_lookup[$key])) {
                    $asp = $aspect_lookup[$key];
                    $type = $asp['aspect_en'];
                    $sym = $aspect_symbols[$type] ?? '•';
                    $color = $aspect_colors[$type] ?? '#888';
                    $orb = $asp['orb'] !== null ? number_format($asp['orb'], 1) . '°' : '';
                    $title = ($bccm_aspect_names_vi ?? bccm_aspect_names_vi())[$type] ?? $type;
                    $title .= $orb ? " (orb: $orb)" : '';
                    $html .= '<td class="bccm-grid-aspect" title="' . esc_attr($p1 . ' ' . $type . ' ' . $p2 . ($orb ? " — orb: $orb" : '')) . '" style="color:' . $color . '">' . $sym . '</td>';
                } else {
                    $html .= '<td class="bccm-grid-none"></td>';
                }
            }
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    $html .= '</div>';

    return $html;
}

/* =====================================================================
 * VEDIC CHART SVG GENERATOR (North Indian Style)
 * =====================================================================*/

/**
 * Build North Indian style Vedic chart SVG
 *
 * @param array $positions  Vedic planet positions
 * @param string $coachee_name  Person's name
 * @param array $birth_info  Birth data for header
 * @return string  SVG markup
 */
function bccm_build_vedic_north_indian_chart_svg($positions, $coachee_name = '', $birth_info = []) {
    $width = 800;
    $height = 1000; // Increased for debug info
    $center_x = $width / 2;
    $center_y = 420;
    $diamond_size = 320;

    // North Indian house layout (counter-clockwise from ASC at top-center)
    // Traditional layout: House 1 at TOP CENTER, then rotate counter-clockwise
    $house_coords = [
        1  => [$center_x, $center_y - $diamond_size/2, 'middle', 'end'],        // Top center (North)
        2  => [$center_x - $diamond_size/4, $center_y - $diamond_size/4, 'middle', 'middle'], // NW-upper
        3  => [$center_x - $diamond_size/2, $center_y, 'end', 'middle'],        // West (left)
        4  => [$center_x - $diamond_size/4, $center_y + $diamond_size/4, 'middle', 'middle'], // SW-lower
        5  => [$center_x, $center_y + $diamond_size/2, 'middle', 'start'],      // South (bottom)
        6  => [$center_x + $diamond_size/4, $center_y + $diamond_size/4, 'middle', 'middle'], // SE-lower
        7  => [$center_x + $diamond_size/2, $center_y, 'start', 'middle'],      // East (right)
        8  => [$center_x + $diamond_size/4, $center_y - $diamond_size/4, 'middle', 'middle'], // NE-upper
        9  => [$center_x - $diamond_size/2 * 0.3, $center_y - $diamond_size/2 * 0.85, 'middle', 'end'],   // NW-side (top-left)
        10 => [$center_x - $diamond_size/2 * 0.85, $center_y - $diamond_size/2 * 0.3, 'end', 'middle'],   // West-upper
        11 => [$center_x - $diamond_size/2 * 0.85, $center_y + $diamond_size/2 * 0.3, 'end', 'middle'],   // West-lower
        12 => [$center_x + $diamond_size/2 * 0.85, $center_y - $diamond_size/2 * 0.3, 'start', 'middle'], // East-upper
    ];

    // Get ascendant sign to determine house rotation
    $asc_sign = 1; // Default Aries
    if (isset($positions['Ascendant']) && isset($positions['Ascendant']['sign_number'])) {
        $asc_sign = intval($positions['Ascendant']['sign_number']);
    }
    
    // Debug info array
    $debug_info = [];
    $debug_info[] = 'ASC Sign: ' . $asc_sign . ' (' . ($rashi_signs[$asc_sign]['en'] ?? '?') . ')';

    // Group planets by house (based on sign they're in)
    $houses_with_planets = [];
    foreach ($positions as $planet_name => $planet_data) {
        if ($planet_name === 'Ascendant') continue; // Skip ASC for planet placement
        
        $planet_sign = isset($planet_data['sign_number']) ? intval($planet_data['sign_number']) : 0;
        if ($planet_sign < 1 || $planet_sign > 12) continue;
        
        // In Vedic North Indian chart: House 1 contains ASC sign, others follow
        // Calculate which house this planet's sign falls into
        $house_num = (($planet_sign - $asc_sign) % 12) + 1;
        if ($house_num < 1) $house_num += 12;
        
        // Debug: log planet placement
        $debug_info[] = $planet_name . ': Sign=' . $planet_sign . ' → House=' . $house_num;
        
        if (!isset($houses_with_planets[$house_num])) {
            $houses_with_planets[$house_num] = [];
        }
        
        // Get planet Vietnamese name
        $planet_vi_names = function_exists('bccm_vedic_planet_names_vi') ? bccm_vedic_planet_names_vi() : [];
        $planet_vi = isset($planet_vi_names[$planet_name]) ? $planet_vi_names[$planet_name] : $planet_name;
        
        $houses_with_planets[$house_num][] = [
            'name' => $planet_name,
            'name_vi' => $planet_vi,
            'symbol' => bccm_vedic_planet_symbol($planet_name),
            'degree' => isset($planet_data['norm_degree']) ? floatval($planet_data['norm_degree']) : 0,
            'is_retro' => isset($planet_data['is_retro']) ? $planet_data['is_retro'] : false,
            'sign_symbol' => isset($planet_data['sign_symbol']) ? $planet_data['sign_symbol'] : '',
        ];
    }

    // Rashi signs for house labels
    $rashi_signs = bccm_vedic_rashi_signs();
    
    // Update debug info with rashi name
    $debug_info[0] = 'ASC Sign: ' . $asc_sign . ' (' . ($rashi_signs[$asc_sign]['en'] ?? '?') . ')';
    
    // Start SVG
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" style="background:#fefefe;font-family:\'Segoe UI\',Arial,sans-serif">';
    
    // Title
    $svg .= '<text x="' . $center_x . '" y="40" text-anchor="middle" font-size="24" font-weight="700" fill="#7c3aed">Vedic Natal Chart (North Indian)</text>';
    if ($coachee_name) {
        $svg .= '<text x="' . $center_x . '" y="70" text-anchor="middle" font-size="16" fill="#6b7280">' . esc_html($coachee_name) . '</text>';
    }
    
    // Birth info
    $info_y = 95;
    if (!empty($birth_info['dob'])) {
        $svg .= '<text x="' . $center_x . '" y="' . $info_y . '" text-anchor="middle" font-size="13" fill="#9ca3af">📅 ' . esc_html($birth_info['dob']) . '</text>';
        $info_y += 20;
    }
    if (!empty($birth_info['time'])) {
        $svg .= '<text x="' . $center_x . '" y="' . $info_y . '" text-anchor="middle" font-size="13" fill="#9ca3af">🕐 ' . esc_html($birth_info['time']) . '</text>';
        $info_y += 20;
    }
    if (!empty($birth_info['place'])) {
        $svg .= '<text x="' . $center_x . '" y="' . $info_y . '" text-anchor="middle" font-size="13" fill="#9ca3af">📍 ' . esc_html($birth_info['place']) . '</text>';
    }

    // Draw diamond (main structure)
    $svg .= '<g id="diamond-structure">';
    
    // Outer diamond
    $svg .= '<path d="M ' . $center_x . ' ' . ($center_y - $diamond_size/2) . ' ';
    $svg .= 'L ' . ($center_x + $diamond_size/2) . ' ' . $center_y . ' ';
    $svg .= 'L ' . $center_x . ' ' . ($center_y + $diamond_size/2) . ' ';
    $svg .= 'L ' . ($center_x - $diamond_size/2) . ' ' . $center_y . ' Z" ';
    $svg .= 'fill="none" stroke="#7c3aed" stroke-width="3"/>';
    
    // Inner cross lines
    $svg .= '<line x1="' . $center_x . '" y1="' . ($center_y - $diamond_size/2) . '" x2="' . $center_x . '" y2="' . ($center_y + $diamond_size/2) . '" stroke="#d8b4fe" stroke-width="1.5"/>';
    $svg .= '<line x1="' . ($center_x - $diamond_size/2) . '" y1="' . $center_y . '" x2="' . ($center_x + $diamond_size/2) . '" y2="' . $center_y . '" stroke="#d8b4fe" stroke-width="1.5"/>';
    
    // Diagonal lines for 12 divisions
    $svg .= '<line x1="' . $center_x . '" y1="' . ($center_y - $diamond_size/2) . '" x2="' . ($center_x + $diamond_size/2) . '" y2="' . $center_y . '" stroke="#e9d5ff" stroke-width="1"/>';
    $svg .= '<line x1="' . ($center_x + $diamond_size/2) . '" y1="' . $center_y . '" x2="' . $center_x . '" y2="' . ($center_y + $diamond_size/2) . '" stroke="#e9d5ff" stroke-width="1"/>';
    $svg .= '<line x1="' . $center_x . '" y1="' . ($center_y + $diamond_size/2) . '" x2="' . ($center_x - $diamond_size/2) . '" y2="' . $center_y . '" stroke="#e9d5ff" stroke-width="1"/>';
    $svg .= '<line x1="' . ($center_x - $diamond_size/2) . '" y1="' . $center_y . '" x2="' . $center_x . '" y2="' . ($center_y - $diamond_size/2) . '" stroke="#e9d5ff" stroke-width="1"/>';
    
    $svg .= '</g>';

    // Draw house numbers and planets
    $svg .= '<g id="houses-and-planets">';
    foreach ($house_coords as $house_num => $coords) {
        list($x, $y, $anchor, $baseline) = $coords;
        
        // Calculate which rashi sign is in this house
        $rashi_num = (($house_num - 1 + $asc_sign - 1) % 12) + 1;
        $rashi_info = isset($rashi_signs[$rashi_num]) ? $rashi_signs[$rashi_num] : ['symbol' => '?', 'en' => '', 'sanskrit' => ''];
        
        // House number with rashi info (show English name for clarity)
        $house_label = $house_num;
        $rashi_label = isset($rashi_info['en']) ? substr($rashi_info['en'], 0, 3) : '?'; // First 3 chars
        
        $svg .= '<text x="' . $x . '" y="' . $y . '" text-anchor="' . $anchor . '" dominant-baseline="' . $baseline . '" font-size="10" fill="#9ca3af" opacity="0.8">';
        $svg .= $house_label . ':' . esc_html($rashi_label);
        $svg .= '</text>';
        
        // ASC marker for house 1 (top center)
        if ($house_num === 1) {
            $svg .= '<text x="' . $x . '" y="' . ($y + 15) . '" text-anchor="middle" font-size="11" font-weight="700" fill="#7c3aed">ASC</text>';
        }
        
        // Planets in this house
        if (isset($houses_with_planets[$house_num])) {
            // Adjust offset based on text baseline (more space for name + degree)
            $offset_y = 30;
            if ($baseline === 'end') $offset_y = -30;
            elseif ($baseline === 'middle') $offset_y = 35;
            
            $planet_y = $y + $offset_y;
            
            foreach ($houses_with_planets[$house_num] as $idx => $planet) {
                // Use short name instead of just symbol for clarity
                $short_names = [
                    'Sun' => 'Su', 'Moon' => 'Mo', 'Mars' => 'Ma', 'Mercury' => 'Me',
                    'Jupiter' => 'Jp', 'Venus' => 'Ve', 'Saturn' => 'Sa',
                    'Rahu' => 'Ra', 'Ketu' => 'Ke',
                ];
                $planet_display = isset($short_names[$planet['name']]) ? $short_names[$planet['name']] : substr($planet['name'], 0, 2);
                
                // Add degree display (like "17°13'")
                $degree = $planet['degree'];
                $deg_int = floor($degree);
                $min_int = floor(($degree - $deg_int) * 60);
                $degree_display = $deg_int . '°' . $min_int . "'";
                
                if ($planet['is_retro']) $planet_display .= 'R';
                
                // Determine color based on planet
                $planet_colors = [
                    'Sun' => '#dc2626', 'Moon' => '#6b7280', 'Mars' => '#ef4444', 'Mercury' => '#10b981',
                    'Jupiter' => '#f97316', 'Venus' => '#ec4899', 'Saturn' => '#475569',
                    'Rahu' => '#6366f1', 'Ketu' => '#8b5cf6',
                ];
                $color = isset($planet_colors[$planet['name']]) ? $planet_colors[$planet['name']] : '#dc2626';
                
                // Planet abbreviation (larger text)
                $svg .= '<text x="' . $x . '" y="' . $planet_y . '" text-anchor="' . $anchor . '" dominant-baseline="middle" font-size="14" font-weight="700" fill="' . $color . '">';
                $svg .= esc_html($planet_display);
                $svg .= '</text>';
                
                // Degree below planet name (smaller text)
                $svg .= '<text x="' . $x . '" y="' . ($planet_y + 12) . '" text-anchor="' . $anchor . '" dominant-baseline="middle" font-size="9" fill="' . $color . '">';
                $svg .= esc_html($degree_display);
                $svg .= '</text>';
                
                // Move to next planet (larger spacing for name + degree)
                $planet_y += 26;
            }
        }
    }
    $svg .= '</g>';

    // Legend
    $legend_y = $center_y + $diamond_size/2 + 60;
    $svg .= '<g id="legend">';
    $svg .= '<text x="' . $center_x . '" y="' . $legend_y . '" text-anchor="middle" font-size="11" fill="#6b7280">Lahiri Ayanamsha (Sidereal) • North Indian Style</text>';
    $svg .= '<text x="' . $center_x . '" y="' . ($legend_y + 18) . '" text-anchor="middle" font-size="10" fill="#9ca3af">Houses rotate based on Ascendant sign • Planets placed by sign position</text>';
    
    // Planet abbreviations legend
    $legend_y += 40;
    $planet_legend = [
        ['Su', 'Sun', '#dc2626'], ['Mo', 'Moon', '#6b7280'], ['Ma', 'Mars', '#ef4444'], 
        ['Me', 'Mercury', '#10b981'], ['Jp', 'Jupiter', '#f97316'], ['Ve', 'Venus', '#ec4899'],
        ['Sa', 'Saturn', '#475569'], ['Ra', 'Rahu', '#6366f1'], ['Ke', 'Ketu', '#8b5cf6'],
    ];
    $legend_x_start = $center_x - 180;
    $legend_x = $legend_x_start;
    foreach ($planet_legend as $idx => $pl) {
        $svg .= '<text x="' . $legend_x . '" y="' . $legend_y . '" text-anchor="start" font-size="10" fill="#6b7280">';
        $svg .= '<tspan font-weight="700" fill="' . $pl[2] . '">' . esc_html($pl[0]) . '</tspan>';
        $svg .= '=' . esc_html($pl[1]);
        $svg .= '</text>';
        $legend_x += 50;
        if ($idx === 4) { // New row after 5 items
            $legend_x = $legend_x_start;
            $legend_y += 15;
        }
    }
    
    $svg .= '</g>';
    
    // Debug info panel
    

    $svg .= '</svg>';

    return $svg;
}

/**
 * Get Vedic planet symbols
 */
function bccm_vedic_planet_symbol($planet_name) {
    $symbols = [
        'Sun'     => '☉',
        'Moon'    => '☽',
        'Mars'    => '♂',
        'Mercury' => '☿',
        'Jupiter' => '♃',
        'Venus'   => '♀',
        'Saturn'  => '♄',
        'Rahu'    => '☊',
        'Ketu'    => '☋',
    ];
    return isset($symbols[$planet_name]) ? $symbols[$planet_name] : substr($planet_name, 0, 2);
}

/* =====================================================================
 * LOAD API-SPECIFIC FILES
 * =====================================================================*/
require_once __DIR__ . '/astro-api-free.php';
require_once __DIR__ . '/astro-api-vedic.php';
require_once __DIR__ . '/astro-report-llm.php';
require_once __DIR__ . '/astro-transit.php';
require_once __DIR__ . '/astro-transit-report.php';
