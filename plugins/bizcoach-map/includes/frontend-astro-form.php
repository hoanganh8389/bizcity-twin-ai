<?php
/**
 * BizCoach Map – Frontend Astrology Form & Shortcode
 *
 * Shortcode: [bccm_astro_form]
 * - Form khai báo ngày/giờ/nơi sinh
 * - Gọi Free Astrology API để lấy bản đồ sao
 * - Lưu transient trước đăng ký, user_meta sau đăng ký
 * - Hiển thị kết quả bản đồ chiêm tinh trực tiếp
 *
 * Flow:
 *   1. User nhập form → AJAX submit
 *   2. Server gọi API → lưu transient + cookie
 *   3. Hiển thị kết quả bản đồ
 *   4. Button "Đăng ký tài khoản" → redirect sang WooCommerce register
 *   5. After register hook → lấy transient → lưu user_meta + tạo coachee profile
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * SHORTCODE: [bccm_astro_form]
 * =====================================================================*/
add_shortcode('bccm_astro_form', 'bccm_astro_form_shortcode');

function bccm_astro_form_shortcode($atts) {
    $atts = shortcode_atts([
        'redirect'     => '',       // URL redirect after form (mặc định: trang hiện tại)
        'show_register'=> 'yes',    // Hiện button đăng ký
        'coach_type'   => '',       // Pre-select coach type
    ], $atts, 'bccm_astro_form');

    ob_start();
    include BCCM_DIR . 'templates/frontend-astro-form.php';
    return ob_get_clean();
}

/* =====================================================================
 * AJAX HANDLER: Generate natal chart
 * =====================================================================*/
add_action('wp_ajax_bccm_astro_generate',        'bccm_ajax_astro_generate');
add_action('wp_ajax_nopriv_bccm_astro_generate', 'bccm_ajax_astro_generate');

function bccm_ajax_astro_generate() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['_nonce'] ?? '', 'bccm_astro_form')) {
        wp_send_json_error(['message' => 'Nonce không hợp lệ.']);
    }

    // Parse form data
    $name   = sanitize_text_field($_POST['natal_name'] ?? '');
    $gender = sanitize_text_field($_POST['natal_gender'] ?? 'Nam');
    $day    = intval($_POST['natal_day'] ?? 0);
    $month  = intval($_POST['natal_month'] ?? 0);
    $year   = intval($_POST['natal_year'] ?? 0);
    $hour   = intval($_POST['natal_hour'] ?? 0);
    $minute = intval($_POST['natal_minute'] ?? 0);
    $ampm   = sanitize_text_field($_POST['amorpm'] ?? 'AM');
    $tz_raw = floatval($_POST['timezone'] ?? -7);
    $address     = sanitize_text_field($_POST['address'] ?? '');
    $lat         = floatval($_POST['lat'] ?? 21.0285);
    $lon         = floatval($_POST['lon'] ?? 105.8542);

    // Validate
    if (empty($name) || !$day || !$month || !$year) {
        wp_send_json_error(['message' => 'Vui lòng nhập đầy đủ họ tên và ngày tháng năm sinh.']);
    }

    // Convert AM/PM to 24h
    if ($ampm === 'PM' && $hour < 12) $hour += 12;
    if ($ampm === 'AM' && $hour === 12) $hour = 0;

    // AstroMemo timezone format: negative = positive GMT
    // Free Astrology API: timezone = offset in hours (e.g. 5.5 for GMT+5:30, 7 for GMT+7)
    $timezone = abs($tz_raw);

    $birth_data = [
        'name'        => $name,
        'gender'      => $gender,
        'year'        => $year,
        'month'       => $month,
        'day'         => $day,
        'hour'        => $hour,
        'minute'      => $minute,
        'second'      => 0,
        'latitude'    => $lat,
        'longitude'   => $lon,
        'timezone'    => $timezone,
        'birth_place' => $address,
        'birth_time'  => sprintf('%02d:%02d', $hour, $minute),
    ];

    // Call API
    $chart_data = bccm_astro_fetch_full_chart($birth_data);

    if (is_wp_error($chart_data)) {
        wp_send_json_error([
            'message' => 'Lỗi khi gọi API chiêm tinh: ' . $chart_data->get_error_message(),
        ]);
    }

    // Merge birth_data into chart_data
    $chart_data['birth_data'] = $birth_data;

    // Save to transient (for pre-registration flow)
    $session_key = bccm_astro_save_transient('', $chart_data);

    // If user is logged in, also save to user_meta and coachee profile
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        bccm_astro_save_to_user_meta($user_id, $chart_data);

        // Try to find/create coachee profile
        if (function_exists('bccm_get_or_create_user_coachee')) {
            $coachee = bccm_get_or_create_user_coachee($user_id, 'WEBCHAT', 'astro_coach');
            if ($coachee) {
                $coachee_id = intval($coachee['id']);
                
                // Save Western chart
                bccm_astro_save_chart($coachee_id, $chart_data, $birth_data);
            }
        }
    }

    // Build response HTML
    $html = bccm_astro_render_result($chart_data, $birth_data);

    wp_send_json_success([
        'message'     => 'Bản đồ chiêm tinh đã được tạo thành công!',
        'session_key' => $session_key,
        'chart_url'   => $chart_data['chart_url'] ?? '',
        'sun_sign'    => $chart_data['parsed']['sun_sign'] ?? '',
        'moon_sign'   => $chart_data['parsed']['moon_sign'] ?? '',
        'asc_sign'    => $chart_data['parsed']['ascendant_sign'] ?? '',
        'html'        => $html,
        'is_logged_in'=> is_user_logged_in(),
    ]);
}

/* =====================================================================
 * AJAX HANDLER: Geo lookup (proxy for privacy)
 * =====================================================================*/
add_action('wp_ajax_bccm_geo_lookup',        'bccm_ajax_geo_lookup');
add_action('wp_ajax_nopriv_bccm_geo_lookup', 'bccm_ajax_geo_lookup');

function bccm_ajax_geo_lookup() {
    $address = sanitize_text_field($_POST['address'] ?? '');
    if (empty($address)) {
        wp_send_json_error(['message' => 'Địa chỉ trống.']);
    }

    // Use Nominatim (OpenStreetMap) for geocoding – free, no API key needed
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'      => $address,
        'format' => 'json',
        'limit'  => 5,
    ]);

    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => ['User-Agent' => 'BizCoachMap/1.0'],
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Lỗi kết nối.']);
    }

    $body = wp_remote_retrieve_body($response);
    $results = json_decode($body, true);

    if (!is_array($results) || empty($results)) {
        wp_send_json_error(['message' => 'Không tìm thấy địa chỉ.']);
    }

    $places = [];
    foreach ($results as $r) {
        $places[] = [
            'display_name' => $r['display_name'] ?? '',
            'lat'          => floatval($r['lat'] ?? 0),
            'lon'          => floatval($r['lon'] ?? 0),
        ];
    }

    wp_send_json_success(['places' => $places]);
}

/* =====================================================================
 * RENDER RESULT HTML
 * =====================================================================*/

/**
 * Render natal chart result as HTML
 *
 * @param array $chart_data  Full chart data
 * @param array $birth_data  Original form input
 * @return string HTML
 */
function bccm_astro_render_result($chart_data, $birth_data) {
    $parsed = $chart_data['parsed'] ?? [];
    $positions = $parsed['positions'] ?? [];
    $aspects = $chart_data['aspects'] ?? [];
    $chart_url = $chart_data['chart_url'] ?? '';

    $signs = bccm_zodiac_signs();
    $planet_vi = bccm_planet_names_vi();
    $aspect_vi = bccm_aspect_names_vi();
    $house_meanings = bccm_house_meanings_vi();

    $name = esc_html($birth_data['name'] ?? 'Chủ nhân');
    $dob_str = sprintf('%02d/%02d/%04d', $birth_data['day'] ?? 0, $birth_data['month'] ?? 0, $birth_data['year'] ?? 0);
    $time_str = sprintf('%02d:%02d', $birth_data['hour'] ?? 0, $birth_data['minute'] ?? 0);
    $place = esc_html($birth_data['birth_place'] ?? '');

    // Sun sign info
    $sun = $positions['Sun'] ?? [];
    $moon = $positions['Moon'] ?? [];
    $asc = $positions['Ascendant'] ?? [];

    ob_start();
    ?>
    <div class="bccm-astro-result" id="bccm-astro-result">
        <!-- HERO -->
        <div class="bccm-astro-hero">
            <h2>🌟 Bản Đồ Chiêm Tinh của <?php echo $name; ?></h2>
            <p class="bccm-astro-sub">Sinh ngày <?php echo $dob_str; ?> lúc <?php echo $time_str; ?> tại <?php echo $place; ?></p>
        </div>

        <!-- BIG 3 -->
        <div class="bccm-big3">
            <div class="bccm-big3-card">
                <div class="bccm-big3-icon">☀️</div>
                <div class="bccm-big3-label">Mặt Trời</div>
                <div class="bccm-big3-sign"><?php echo esc_html($sun['sign_vi'] ?? '—'); ?> <?php echo $sun['sign_symbol'] ?? ''; ?></div>
                <div class="bccm-big3-desc">Bản ngã & ý chí</div>
            </div>
            <div class="bccm-big3-card">
                <div class="bccm-big3-icon">🌙</div>
                <div class="bccm-big3-label">Mặt Trăng</div>
                <div class="bccm-big3-sign"><?php echo esc_html($moon['sign_vi'] ?? '—'); ?> <?php echo $moon['sign_symbol'] ?? ''; ?></div>
                <div class="bccm-big3-desc">Cảm xúc & nội tâm</div>
            </div>
            <div class="bccm-big3-card">
                <div class="bccm-big3-icon">⬆️</div>
                <div class="bccm-big3-label">Cung Mọc</div>
                <div class="bccm-big3-sign"><?php echo esc_html($asc['sign_vi'] ?? '—'); ?> <?php echo $asc['sign_symbol'] ?? ''; ?></div>
                <div class="bccm-big3-desc">Hình ảnh bên ngoài</div>
            </div>
        </div>

        <!-- NATAL WHEEL CHART -->
        <?php if (!empty($chart_url)): ?>
        <div class="bccm-wheel-chart">
            <h3>🔮 Bản Đồ Sao Natal</h3>
            <div class="bccm-wheel-img">
                <img src="<?php echo esc_url($chart_url); ?>" alt="Natal Wheel Chart" loading="lazy" />
            </div>
        </div>
        <?php endif; ?>

        <!-- ASTROVIET CHARTS -->
        <?php
        $houses_raw_av = [];
        if (!empty($chart_data['houses'])) {
          $hd_av = $chart_data['houses'];
          if (isset($hd_av[0]['House']) || isset($hd_av[0]['house'])) $houses_raw_av = $hd_av;
          elseif (isset($hd_av['Houses'])) $houses_raw_av = $hd_av['Houses'];
        }
        $astroviet_wheel = '';
        $astroviet_grid = '';
        $native_grid_fe = '';
        if (!empty($positions) && !empty($houses_raw_av)) {
          $astroviet_wheel = bccm_build_astroviet_wheel_url($positions, $houses_raw_av, esc_html($birth_data['name'] ?? ''), $birth_data);
          $astroviet_grid = bccm_build_astroviet_aspect_grid_url($positions, $houses_raw_av, $birth_data);
          $native_grid_fe = bccm_render_aspect_grid_html($positions, $aspects);
        }
        ?>
        <?php if ($astroviet_wheel || $astroviet_grid || $native_grid_fe): ?>
        <div class="bccm-astroviet-section">
            <h3>🗺️ Bản Đồ Chi Tiết</h3>
            <div class="bccm-astroviet-grid">
              <?php if ($astroviet_wheel): ?>
              <div class="bccm-astroviet-item">
                <img src="<?php echo esc_url($astroviet_wheel); ?>" alt="Natal Wheel" loading="lazy" />
                <p class="bccm-chart-caption">Natal Wheel — AstroViet</p>
              </div>
              <?php endif; ?>
              <?php if ($native_grid_fe): ?>
              <div class="bccm-astroviet-item">
                <?php echo $native_grid_fe; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php if ($astroviet_grid): ?>
            <div style="text-align:center;margin-top:12px">
              <img src="<?php echo esc_url($astroviet_grid); ?>" alt="Aspect Grid" loading="lazy" style="max-width:100%;border-radius:8px" />
              <p class="bccm-chart-caption">Aspect Grid — AstroViet</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- PLANET POSITIONS TABLE -->
        <div class="bccm-planets-table">
            <h3>🪐 Vị Trí Các Hành Tinh</h3>
            <table>
                <thead>
                    <tr>
                        <th>Hành tinh</th>
                        <th>Chòm sao</th>
                        <th>Vị trí</th>
                        <th>Nhà</th>
                        <th>Nghịch hành</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $main_planets = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Ascendant','MC','Chiron','True Node'];
                    $planet_symbols_fe = [
                      'Sun' => '☉', 'Moon' => '☽', 'Mercury' => '☿', 'Venus' => '♀', 'Mars' => '♂',
                      'Jupiter' => '♃', 'Saturn' => '♄', 'Uranus' => '♅', 'Neptune' => '♆', 'Pluto' => '♇',
                      'Chiron' => '⚷', 'True Node' => '☊', 'Ascendant' => 'ASC', 'MC' => 'MC',
                    ];
                    $houses_raw_fe = [];
                    if (!empty($chart_data['houses'])) {
                      $hd = $chart_data['houses'];
                      if (isset($hd[0]['House']) || isset($hd[0]['house'])) $houses_raw_fe = $hd;
                      elseif (isset($hd['Houses'])) $houses_raw_fe = $hd['Houses'];
                    }
                    foreach ($main_planets as $pname):
                        if (!isset($positions[$pname])) continue;
                        $p = $positions[$pname];
                        $dms = bccm_astro_decimal_to_dms($p['norm_degree'] ?? 0);
                        $psym = $planet_symbols_fe[$pname] ?? '';
                        $house_num = '';
                        if (!empty($houses_raw_fe) && !in_array($pname, ['Ascendant','MC'])) {
                          $h = bccm_astro_planet_in_house($p['full_degree'] ?? 0, $houses_raw_fe);
                          $house_num = $h > 0 ? $h : '';
                        }
                    ?>
                    <tr>
                        <td><span style="font-size:15px;margin-right:4px"><?php echo $psym; ?></span><strong><?php echo esc_html($p['planet_vi']); ?></strong></td>
                        <td><?php echo $p['sign_symbol']; ?> <?php echo esc_html($p['sign_vi']); ?></td>
                        <td style="font-family:monospace;font-size:12px"><?php echo esc_html($dms); ?></td>
                        <td style="text-align:center;color:#6366f1;font-weight:600"><?php echo $house_num ?: '—'; ?></td>
                        <td><?php echo $p['is_retro'] ? '<span style="color:#ef4444;font-weight:700">℞</span>' : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ASPECTS TABLE -->
        <?php if (!empty($aspects)):
            $enriched_fe = bccm_astro_enrich_aspects($aspects, $positions);
            $grouped_fe  = bccm_astro_group_aspects_by_planet($enriched_fe);
            $aspect_symbols_fe = bccm_aspect_symbols();
            $aspect_colors_fe  = bccm_aspect_colors();
        ?>
        <div class="bccm-aspects-table">
            <h3>🔗 Các Góc Chiếu (<?php echo count($enriched_fe); ?> aspects)</h3>

            <!-- Legend -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;padding:8px;background:#f9fafb;border-radius:8px;font-size:10px">
              <?php foreach ($aspect_vi as $aen => $avi):
                $c = $aspect_colors_fe[$aen] ?? '#888';
                $s = $aspect_symbols_fe[$aen] ?? '';
              ?>
              <span style="display:inline-flex;align-items:center;gap:2px"><span style="color:<?php echo $c; ?>;font-weight:700"><?php echo $s; ?></span> <?php echo esc_html($avi); ?></span>
              <?php endforeach; ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Hành tinh 1</th>
                        <th style="width:6%"></th>
                        <th>Góc chiếu</th>
                        <th>Hành tinh 2</th>
                        <th>Orb</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped_fe as $planet_key => $planet_aspects):
                        $pvi = $planet_vi[$planet_key] ?? $planet_key;
                    ?>
                    <tr style="background:#f1f5f9"><td colspan="5"><strong><?php echo esc_html($pvi); ?></strong> <span style="color:#9ca3af;font-weight:400">(<?php echo count($planet_aspects); ?>)</span></td></tr>
                    <?php foreach ($planet_aspects as $asp):
                        $type_en = $asp['aspect_en'];
                        $type_vi_a = $aspect_vi[$type_en] ?? $type_en;
                        $p2_vi_a = $planet_vi[$asp['planet_2_en']] ?? $asp['planet_2_en'];
                        $sym_a = $aspect_symbols_fe[$type_en] ?? '';
                        $color_a = $aspect_colors_fe[$type_en] ?? '#888';
                        $orb_val = $asp['orb'];
                        $orb_display = $orb_val !== null ? bccm_astro_decimal_to_dms($orb_val, true) : '—';
                        $orb_style = '';
                        if ($orb_val !== null && $orb_val < 1) $orb_style = 'color:#059669;font-weight:700';
                        elseif ($orb_val !== null && $orb_val < 3) $orb_style = 'color:#2563eb';
                    ?>
                    <tr>
                        <td style="padding-left:16px;color:#6b7280"><?php echo esc_html($planet_vi[$asp['planet_1_en']] ?? $asp['planet_1_en']); ?></td>
                        <td style="text-align:center;font-size:15px;color:<?php echo $color_a; ?>"><?php echo $sym_a; ?></td>
                        <td style="color:<?php echo $color_a; ?>;font-weight:500"><?php echo esc_html($type_vi_a); ?></td>
                        <td><?php echo esc_html($p2_vi_a); ?></td>
                        <td style="font-family:monospace;font-size:11px;<?php echo $orb_style; ?>"><?php echo esc_html($orb_display); ?></td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- HOUSES -->
        <?php if (!empty($chart_data['houses'])): ?>
        <div class="bccm-houses-table">
            <h3>🏠 12 Cung Địa Bàn (Placidus)</h3>
            <div class="bccm-houses-grid">
                <?php
                $houses_fe = $chart_data['houses'];
                if (isset($houses_fe['Houses'])) $houses_fe = $houses_fe['Houses'];
                foreach ($houses_fe as $house):
                    $num = $house['House'] ?? $house['house'] ?? $house['number'] ?? 0;
                    $sign_num = $house['zodiac_sign']['number'] ?? 0;
                    $sign_vi = $signs[$sign_num]['vi'] ?? '';
                    $symbol = $signs[$sign_num]['symbol'] ?? '';
                    $norm_deg = $house['normDegree'] ?? ($house['degree'] ?? 0);
                    $dms_h = bccm_astro_decimal_to_dms($norm_deg, false);
                    $meaning = $house_meanings[$num] ?? '';
                    $angular = in_array($num, [1,4,7,10]);
                ?>
                <div class="bccm-house-card<?php echo $angular ? ' bccm-house-angular' : ''; ?>">
                    <div class="bccm-house-num">Cung <?php echo $num; ?></div>
                    <div class="bccm-house-sign"><?php echo $symbol; ?> <?php echo esc_html($sign_vi); ?></div>
                    <div style="font-family:monospace;font-size:11px;color:#6366f1"><?php echo esc_html($dms_h); ?></div>
                    <div class="bccm-house-meaning"><?php echo esc_html($meaning); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- CHART PATTERNS -->
        <?php
        if (!empty($aspects) && !empty($positions)):
            $chart_patterns_fe = bccm_detect_chart_patterns($positions, $aspects);
            if (!empty($chart_patterns_fe)):
        ?>
        <div class="bccm-patterns-section">
            <h3>🔷 Mô Hình Bản Đồ <small>(Chart Patterns)</small></h3>
            <p class="bccm-section-desc">Các mô hình hình học đặc biệt giữa các hành tinh, cho thấy cấu trúc tổng thể của bản đồ.</p>
            <div class="bccm-patterns-grid">
                <?php foreach ($chart_patterns_fe as $pattern):
                    $planet_vi_fe = bccm_planet_names_vi();
                    $planet_list_fe = [];
                    foreach ($pattern['planets'] as $pn) {
                        $pvi = $planet_vi_fe[$pn] ?? $pn;
                        $sign_vi_p = $positions[$pn]['sign_vi'] ?? '';
                        $norm_deg_p = $positions[$pn]['norm_degree'] ?? 0;
                        $deg_str = floor($norm_deg_p) . '°';
                        $planet_list_fe[] = "$pvi trong $deg_str $sign_vi_p";
                    }
                ?>
                <div class="bccm-pattern-card">
                    <div class="bccm-pattern-header">
                        <span class="bccm-pattern-icon"><?php echo $pattern['icon'] ?? '🔷'; ?></span>
                        <span class="bccm-pattern-type"><?php echo esc_html($pattern['type_vi']); ?></span>
                    </div>
                    <div class="bccm-pattern-planets">
                        <?php foreach ($planet_list_fe as $pl): ?>
                        <div class="bccm-pattern-planet"><?php echo esc_html($pl); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="bccm-pattern-desc"><?php echo esc_html($pattern['description']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; endif; ?>

        <!-- SPECIAL FEATURES -->
        <?php
        if (!empty($positions)):
            $special_features_fe = bccm_analyze_special_features(
                $positions,
                $aspects ?? [],
                $houses_raw_fe ?? [],
                $birth_data ?? []
            );
            if (!empty($special_features_fe)):
        ?>
        <div class="bccm-special-section">
            <h3>✨ Đặc Điểm Nổi Bật <small>(Special Features)</small></h3>
            <p class="bccm-section-desc">Phân tích tổng quan về các đặc điểm nổi bật trong bản đồ sao.</p>
            <div class="bccm-special-grid">
                <?php foreach ($special_features_fe as $feature): ?>
                <div class="bccm-special-card">
                    <div class="bccm-special-icon"><?php echo $feature['icon'] ?? '✨'; ?></div>
                    <div class="bccm-special-text">
                        <p class="bccm-special-main"><?php echo esc_html($feature['text']); ?></p>
                        <?php if (!empty($feature['text_vi']) && $feature['text_vi'] !== $feature['text']): ?>
                        <p class="bccm-special-sub"><?php echo esc_html($feature['text_vi']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; endif; ?>

    </div>
    <?php
    return ob_get_clean();
}

/* =====================================================================
 * REGISTRATION BRIDGE: After WooCommerce registration
 * =====================================================================*/
add_action('woocommerce_created_customer', 'bccm_astro_after_registration', 30, 1);
add_action('user_register', 'bccm_astro_after_registration', 30, 1);

function bccm_astro_after_registration($user_id) {
    // Try to load astro data from transient
    $astro_data = bccm_astro_load_transient();

    if (empty($astro_data)) return;

    // Save to user_meta
    bccm_astro_save_to_user_meta($user_id, $astro_data);

    // Create coachee profile if bizcoach-map is active
    if (function_exists('bccm_get_or_create_user_coachee')) {
        $birth_data = $astro_data['birth_data'] ?? [];
        $name = $birth_data['name'] ?? '';
        $gender = $birth_data['gender'] ?? '';

        // Determine coach type from astro
        $coach_type = 'astro_coach';

        $coachee = bccm_get_or_create_user_coachee($user_id, 'WEBCHAT', $coach_type);
        if ($coachee) {
            $coachee_id = intval($coachee['id']);

            // Update profile with birth data
            global $wpdb;
            $t = bccm_tables();
            $update_data = [
                'updated_at' => current_time('mysql'),
            ];

            if (!empty($name))   $update_data['full_name'] = $name;
            if (!empty($birth_data['day']) && !empty($birth_data['month']) && !empty($birth_data['year'])) {
                $update_data['dob'] = sprintf('%04d-%02d-%02d', $birth_data['year'], $birth_data['month'], $birth_data['day']);
            }

            $wpdb->update($t['profiles'], $update_data, ['id' => $coachee_id]);

            // Save chart data
            if (!empty($astro_data['planets'])) {
                bccm_astro_save_chart($coachee_id, $astro_data, $birth_data);
            }
        }
    }

    // Clear transient
    bccm_astro_clear_transient();

    error_log("[BCCM Astro] Saved astro data for new user #$user_id");
}

/* =====================================================================
 * SITE CREATION BRIDGE: After new multisite blog created
 * =====================================================================*/
add_action('wp_initialize_site', 'bccm_astro_after_site_creation', 100, 1);

function bccm_astro_after_site_creation($new_site) {
    $blog_id = $new_site->blog_id ?? 0;
    if (!$blog_id) return;

    // Get the admin user for this site
    $user_id = get_current_user_id();
    if (!$user_id) return;

    // Load astro data from user_meta
    $astro_data = bccm_astro_load_from_user_meta($user_id);
    if (empty($astro_data)) return;

    // Switch to new site and create coachee profile
    switch_to_blog($blog_id);

    if (function_exists('bccm_install_tables')) {
        bccm_install_tables();
    }

    if (function_exists('bccm_get_or_create_user_coachee')) {
        $coachee = bccm_get_or_create_user_coachee($user_id, 'WEBCHAT', 'astro_coach');
        if ($coachee && !empty($astro_data['planets'])) {
            $birth_data = $astro_data['birth_data'] ?? [];
            bccm_astro_save_chart(intval($coachee['id']), $astro_data, $birth_data);
        }
    }

    restore_current_blog();

    error_log("[BCCM Astro] Copied astro data to new site #$blog_id for user #$user_id");
}

/* =====================================================================
 * ENQUEUE FRONTEND ASSETS
 * =====================================================================*/
add_action('wp_enqueue_scripts', 'bccm_astro_enqueue_frontend', 20);

function bccm_astro_enqueue_frontend() {
    // Only load on pages with our shortcode
    global $post;
    if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'bccm_astro_form')) {
        return;
    }

    wp_enqueue_style('bccm-astro-form', BCCM_URL . 'assets/css/astro-form.css', [], BCCM_VERSION);
    wp_enqueue_script('jquery');
}
