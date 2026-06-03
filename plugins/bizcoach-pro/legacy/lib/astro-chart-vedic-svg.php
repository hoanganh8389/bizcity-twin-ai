<?php
/**
 * Vedic Chart SVG Generator (North Indian Style)
 *
 * Generates SVG for Vedic/Jyotish natal chart in North Indian diamond format
 *
 * @package BizCoach_Map
 */

if (!defined('ABSPATH')) exit;

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
    $height = 850;
    $center_x = $width / 2;
    $center_y = 420;
    $diamond_size = 320;

    // North Indian house layout (counter-clockwise from ASC at 1st house)
    // ASC is always in house 1 (East position - right middle)
    $house_coords = [
        1  => [$center_x + $diamond_size/2, $center_y, 'middle', 'start'],      // East (right)
        2  => [$center_x + $diamond_size/4, $center_y - $diamond_size/4, 'middle', 'middle'], // NE-upper
        3  => [$center_x, $center_y - $diamond_size/2, 'middle', 'end'],        // North (top)
        4  => [$center_x - $diamond_size/4, $center_y - $diamond_size/4, 'middle', 'middle'], // NW-upper
        5  => [$center_x - $diamond_size/2, $center_y, 'end', 'middle'],        // West (left)
        6  => [$center_x - $diamond_size/4, $center_y + $diamond_size/4, 'middle', 'middle'], // SW-lower
        7  => [$center_x, $center_y + $diamond_size/2, 'middle', 'start'],      // South (bottom)
        8  => [$center_x + $diamond_size/4, $center_y + $diamond_size/4, 'middle', 'middle'], // SE-lower
        9  => [$center_x + $diamond_size/2 * 0.85, $center_y - $diamond_size/2 * 0.3, 'start', 'middle'], // NE-side
        10 => [$center_x - $diamond_size/2 * 0.3, $center_y - $diamond_size/2 * 0.85, 'middle', 'end'],   // NW-side
        11 => [$center_x - $diamond_size/2 * 0.85, $center_y + $diamond_size/2 * 0.3, 'end', 'middle'],   // SW-side
        12 => [$center_x + $diamond_size/2 * 0.3, $center_y + $diamond_size/2 * 0.85, 'middle', 'start'], // SE-side
    ];

    // Get ascendant sign to determine house rotation
    $asc_sign = 1; // Default Aries
    if (isset($positions['Ascendant']) && isset($positions['Ascendant']['sign_number'])) {
        $asc_sign = intval($positions['Ascendant']['sign_number']);
    }

    // Group planets by house (based on sign they're in)
    $houses_with_planets = [];
    foreach ($positions as $planet_name => $planet_data) {
        if ($planet_name === 'Ascendant') continue; // Skip ASC for planet placement
        
        $planet_sign = isset($planet_data['sign_number']) ? intval($planet_data['sign_number']) : 0;
        if ($planet_sign < 1 || $planet_sign > 12) continue;
        
        // Calculate which house this sign maps to
        $house_num = (($planet_sign - $asc_sign + 12) % 12) + 1;
        
        if (!isset($houses_with_planets[$house_num])) {
            $houses_with_planets[$house_num] = [];
        }
        
        $houses_with_planets[$house_num][] = [
            'name' => $planet_name,
            'symbol' => bccm_vedic_planet_symbol($planet_name),
            'degree' => isset($planet_data['norm_degree']) ? floatval($planet_data['norm_degree']) : 0,
            'is_retro' => isset($planet_data['is_retro']) ? $planet_data['is_retro'] : false,
            'sign_symbol' => isset($planet_data['sign_symbol']) ? $planet_data['sign_symbol'] : '',
        ];
    }

    // Rashi signs for house labels
    $rashi_signs = bccm_vedic_rashi_signs();
    
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
        $rashi_info = isset($rashi_signs[$rashi_num]) ? $rashi_signs[$rashi_num] : ['symbol' => '?', 'en' => ''];
        
        // House number with rashi symbol
        $svg .= '<text x="' . $x . '" y="' . $y . '" text-anchor="' . $anchor . '" dominant-baseline="' . $baseline . '" font-size="11" fill="#9ca3af" opacity="0.7">';
        $svg .= $house_num . ' ' . esc_html($rashi_info['symbol']);
        $svg .= '</text>';
        
        // ASC marker for house 1
        if ($house_num === 1) {
            $svg .= '<text x="' . ($x + 15) . '" y="' . ($y - 10) . '" text-anchor="start" font-size="12" font-weight="700" fill="#7c3aed">ASC</text>';
        }
        
        // Planets in this house
        if (isset($houses_with_planets[$house_num])) {
            $offset_y = ($baseline === 'start') ? 18 : (($baseline === 'end') ? -15 : 15);
            $planet_y = $y + $offset_y;
            
            foreach ($houses_with_planets[$house_num] as $idx => $planet) {
                $planet_text = $planet['symbol'];
                if ($planet['is_retro']) $planet_text .= 'ℝ';
                
                $svg .= '<text x="' . $x . '" y="' . $planet_y . '" text-anchor="' . $anchor . '" dominant-baseline="middle" font-size="16" font-weight="600" fill="#1e293b">';
                $svg .= esc_html($planet_text);
                $svg .= '</text>';
                
                $planet_y += 20;
            }
        }
    }
    $svg .= '</g>';

    // Legend
    $legend_y = $center_y + $diamond_size/2 + 60;
    $svg .= '<g id="legend">';
    $svg .= '<text x="' . $center_x . '" y="' . $legend_y . '" text-anchor="middle" font-size="11" fill="#6b7280">Lahiri Ayanamsha (Sidereal) • North Indian Style</text>';
    $svg .= '<text x="' . $center_x . '" y="' . ($legend_y + 18) . '" text-anchor="middle" font-size="10" fill="#9ca3af">Houses rotate based on Ascendant sign • Planets placed by sign position</text>';
    $svg .= '</g>';

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
