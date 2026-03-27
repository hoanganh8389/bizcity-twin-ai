<?php
/**
 * BizCoach Map – Frontend Shortcode & My-Account Integration (WEBCHAT)
 *
 * Shortcodes:
 *   [bccm_my_lifemap]  — Form tạo/sửa hồ sơ cá nhân + xem Life Map
 *   [bccm_my_profile]  — Form tạo/sửa hồ sơ cá nhân (nhỏ gọn)
 *
 * WooCommerce My Account:
 *   /my-account/life-map/  — Tab "Bản đồ cá nhân" trong My Account
 *
 * Platform type = WEBCHAT → trợ lý AI phục vụ user frontend.
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * 1. SHORTCODE: [bccm_my_lifemap]
 *    Full profile form + Life Map view cho user đã đăng nhập.
 * =====================================================================*/
add_shortcode('bccm_my_lifemap', function ($atts) {
  if (!is_user_logged_in()) {
    return '<div class="bccm-notice bccm-notice-info">
      <p>Vui lòng <a href="' . esc_url(wp_login_url(get_permalink())) . '">đăng nhập</a> để xem bản đồ cá nhân.</p>
    </div>';
  }

  $atts = shortcode_atts([
    'coach_type' => 'mental_coach',
    'show_map'   => 'yes',
  ], $atts, 'bccm_my_lifemap');

  $user_id = get_current_user_id();

  ob_start();
  bccm_render_frontend_profile_form($user_id, $atts['coach_type'], $atts['show_map'] === 'yes');
  return ob_get_clean();
});

/* =====================================================================
 * 2. SHORTCODE: [bccm_my_profile]
 *    Nhỏ gọn hơn, chỉ form cập nhật hồ sơ (không hiển thị map).
 * =====================================================================*/
add_shortcode('bccm_my_profile', function ($atts) {
  if (!is_user_logged_in()) {
    return '<div class="bccm-notice bccm-notice-info">
      <p>Vui lòng <a href="' . esc_url(wp_login_url(get_permalink())) . '">đăng nhập</a> để cập nhật hồ sơ.</p>
    </div>';
  }

  $atts = shortcode_atts([
    'coach_type' => 'mental_coach',
  ], $atts, 'bccm_my_profile');

  $user_id = get_current_user_id();

  ob_start();
  bccm_render_frontend_profile_form($user_id, $atts['coach_type'], false);
  return ob_get_clean();
});

/* =====================================================================
 * 3. WOOCOMMERCE MY-ACCOUNT INTEGRATION
 *    Endpoint: /my-account/life-map/
 * =====================================================================*/

// 3a. Register endpoint
add_action('init', function () {
  add_rewrite_endpoint('life-map', EP_ROOT | EP_PAGES);
});

// 3b. Add tab to My Account menu - insert after dashboard (priority 10)
add_filter('woocommerce_account_menu_items', function ($items) {
  // Insert life-map right after dashboard
  $new_items = [];
  foreach ($items as $key => $label) {
    $new_items[$key] = $label;
    if ($key === 'dashboard') {
      $new_items['life-map'] = '🗺️ Bản đồ cá nhân';
    }
  }
  // If dashboard key not found, prepend
  if (!isset($new_items['life-map'])) {
    $new_items = array_merge(['life-map' => '🗺️ Bản đồ cá nhân'], $new_items);
  }
  return $new_items;
}, 10);

// 3c. Enqueue CSS for life-map endpoint (phải chạy trước khi render content)
add_action('wp_enqueue_scripts', function () {
  // Check if we're on my-account page AND life-map endpoint
  if (is_account_page()) {
    global $wp;
    if (isset($wp->query_vars['life-map'])) {
      wp_enqueue_style('bccm-frontend-profile', BCCM_URL . 'assets/css/frontend-profile.css', [], BCCM_VERSION);
      wp_enqueue_style('bccm-natal-public', BCCM_URL . 'assets/css/natal-chart-public.css', [], BCCM_VERSION);
    }
  }
}, 100); // Priority cao để chắc chắn query_vars đã được set

// 3d. Render content for the endpoint
add_action('woocommerce_account_life-map_endpoint', function () {
  if (!is_user_logged_in()) return;
  
  // Force inline CSS để chắc chắn style được load (vì wp_enqueue_scripts có thể chạy quá sớm)
  echo '<style id="bccm-inline-styles">';
  
  // Frontend profile CSS
  $css_file1 = BCCM_DIR . 'assets/css/frontend-profile.css';
  if (file_exists($css_file1)) {
    echo file_get_contents($css_file1);
  }
  
  // Natal chart public CSS
  $css_file2 = BCCM_DIR . 'assets/css/natal-chart-public.css';
  if (file_exists($css_file2)) {
    echo file_get_contents($css_file2);
  }
  
  echo '</style>';
  
  $user_id = get_current_user_id();
  bccm_render_frontend_profile_form($user_id, 'mental_coach', true);
});

// 3d. Endpoint title
add_filter('the_title', function ($title) {
  global $wp_query;
  if (is_main_query() && in_the_loop() && isset($wp_query->query_vars['life-map'])) {
    return 'Bản đồ cá nhân';
  }
  return $title;
});

/* =====================================================================
 * 4. RENDER FUNCTION: Frontend Profile Form
 * =====================================================================*/
function bccm_render_frontend_profile_form($user_id, $default_type = 'mental_coach', $show_map = true) {
  global $wpdb;
  $t = bccm_tables();

  // Render Progress Tracker at top
  if (function_exists('bccm_render_myaccount_progress_tracker')) {
    bccm_render_myaccount_progress_tracker();
  }

  // Load or create coachee
  $coachee = bccm_get_or_create_user_coachee($user_id, 'WEBCHAT', $default_type);
  #if (!$coachee) {
  #  echo '<div class="bccm-notice bccm-notice-error"><p>Không thể tạo hồ sơ.</p></div>';
  #  return;
  #}

  $coachee        = bccm_hydrate_extra_fields($coachee);
  $coachee_id    = (int)$coachee['id'];
  $selected_type = $coachee['coach_type'] ?: $default_type;
  $questions     = bccm_get_questions_for($selected_type);
  $answers       = json_decode($coachee['answer_json'] ?? '[]', true) ?: [];

  // Load astro birth data - USE user_id for cross-platform consistency
  $t_astro = $wpdb->prefix . 'bccm_astro';
  $astro_row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' ORDER BY id DESC LIMIT 1", $user_id
  ), ARRAY_A);
  if (!$astro_row) {
    $astro_row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic' ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
  }
  // Note: user_id is the canonical key — coachee_id fallback removed (old data should have been migrated)

  // Handle form submission
  $is_save = !empty($_POST['bccm_frontend_save']) || !empty($_POST['bccm_regenerate_chart']);
  if ($is_save && wp_verify_nonce($_POST['_bccm_fe_nonce'] ?? '', 'bccm_frontend_profile')) {
    $data = [
      'coach_type'    => $selected_type,
      'full_name'     => sanitize_text_field($_POST['full_name'] ?? ''),
      'phone'         => sanitize_text_field($_POST['phone'] ?? ''),
      'address'       => sanitize_text_field($_POST['address'] ?? ''),
      'dob'           => sanitize_text_field($_POST['dob'] ?? ''),
      'user_id'       => $user_id,
      'platform_type' => 'WEBCHAT',
    ];

    // Type-specific fields
    $type_fields = bccm_fields_for_type($selected_type);
    foreach ($type_fields as $key => $cfg) {
      if (!array_key_exists($key, $_POST)) continue;
      $val  = $_POST[$key];
      $type = $cfg['type'] ?? 'text';
      if ($type === 'number') {
        $data[$key] = is_numeric($val) ? (float)$val : null;
      } else {
        $data[$key] = sanitize_text_field($val);
      }
    }

    $coachee_id = bccm_upsert_profile($data, $coachee_id);

    // ── Save astro birth data to bccm_astro table ──
    $birth_place = sanitize_text_field($_POST['birth_place'] ?? '');
    $birth_time  = sanitize_text_field($_POST['birth_time'] ?? '');
    $latitude    = floatval($_POST['astro_latitude'] ?? 0);
    $longitude   = floatval($_POST['astro_longitude'] ?? 0);
    $timezone    = floatval($_POST['astro_timezone'] ?? 7);

    if ($birth_place || $birth_time) {
      $t_astro = $wpdb->prefix . 'bccm_astro';
      // Check by user_id first for cross-platform consistency
      $existing_astro = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $t_astro WHERE user_id=%d AND chart_type='western'", $user_id
      ));
      $astro_row_data = [
        'coachee_id'  => $coachee_id,
        'user_id'     => $user_id,
        'chart_type'  => 'western',
        'birth_place' => $birth_place,
        'birth_time'  => $birth_time,
        'latitude'    => $latitude,
        'longitude'   => $longitude,
        'timezone'    => $timezone,
        'updated_at'  => current_time('mysql'),
      ];
      if ($existing_astro) {
        $wpdb->update($t_astro, $astro_row_data, ['id' => $existing_astro]);
      } else {
        $astro_row_data['created_at'] = current_time('mysql');
        $wpdb->insert($t_astro, $astro_row_data);
      }
      // Refresh astro_row by user_id
      $astro_row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' ORDER BY id DESC LIMIT 1", $user_id
      ), ARRAY_A);

      // ── Auto-generate natal charts if we have all required data ──
      $dob_val = sanitize_text_field($_POST['dob'] ?? '');
      $dob_parts = explode('-', $dob_val);
      $time_parts = explode(':', $birth_time);
      
      $birth_data_ready = (count($dob_parts) === 3 && count($time_parts) >= 2 && $latitude && $longitude);
      
      if ($birth_data_ready) {
        $birth_data = [
          'day'       => intval($dob_parts[2]),
          'month'     => intval($dob_parts[1]),
          'year'      => intval($dob_parts[0]),
          'hour'      => intval($time_parts[0]),
          'minute'    => intval($time_parts[1] ?? 0),
          'latitude'  => $latitude,
          'longitude' => $longitude,
          'timezone'  => $timezone,
        ];
        $birth_input = array_merge($birth_data, [
          'birth_place' => $birth_place,
          'birth_time'  => $birth_time,
        ]);

        // Check if we already have chart data (summary not null)
        $has_chart = !empty($astro_row['summary']);
        $force_regenerate = !empty($_POST['bccm_regenerate_chart']);
        
        // Only call API if chart doesn't exist yet OR user explicitly requested regeneration
        if ((!$has_chart || $force_regenerate) && function_exists('bccm_astro_fetch_full_chart')) {
          $errors = [];
          $success = [];

          // Western Astrology API
          $chart_result = bccm_astro_fetch_full_chart($birth_data);
          if (is_wp_error($chart_result)) {
            $errors[] = 'Western: ' . $chart_result->get_error_message();
          } else {
            bccm_astro_save_chart($coachee_id, $chart_result, $birth_input, $user_id);
            $success[] = 'Western Astrology';
          }

          // Vedic / Indian Astrology API
          if (function_exists('bccm_vedic_fetch_full_chart')) {
            $vedic_result = bccm_vedic_fetch_full_chart($birth_data);
            if (is_wp_error($vedic_result)) {
              $errors[] = 'Vedic: ' . $vedic_result->get_error_message();
            } else {
              bccm_vedic_save_chart($coachee_id, $vedic_result, $birth_input, $user_id);
              $success[] = 'Vedic Astrology';
            }
          }

          if (!empty($success)) {
            echo '<div class="bccm-notice bccm-notice-success"><p>🌟 Đã tạo bản đồ sao: ' . esc_html(implode(', ', $success)) . '</p></div>';
          }
          if (!empty($errors)) {
            echo '<div class="bccm-notice bccm-notice-error"><p>⚠️ Lỗi API: ' . esc_html(implode(' | ', $errors)) . '</p></div>';
          }

          // Refresh astro data after API call by user_id
          $astro_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' ORDER BY id DESC LIMIT 1", $user_id
          ), ARRAY_A);
        }
      }
    }

    // Save answers (legacy - not used in frontend)
    $answers_raw = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
    $answers_arr = [];
    for ($i = 0; $i < 20; $i++) {
      $answers_arr[$i] = sanitize_text_field($answers_raw[$i] ?? '');
    }
    $wpdb->update($t['profiles'], [
      'answer_json' => wp_json_encode($answers_arr, JSON_UNESCAPED_UNICODE),
    ], ['id' => $coachee_id]);

    // Refresh data
    $coachee  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
    $coachee  = bccm_hydrate_extra_fields($coachee);
    $answers  = $answers_arr;

    echo '<div class="bccm-notice bccm-notice-success"><p>✅ Đã cập nhật hồ sơ thành công!</p></div>';
  }

  $v = function($k) use($coachee) { return esc_attr($coachee[$k] ?? ''); };

  // Enqueue frontend CSS
  wp_enqueue_style('bccm-frontend-profile', BCCM_URL . 'assets/css/frontend-profile.css', [], BCCM_VERSION);
  ?>

  <div class="bccm-fe-wrap">

    <!-- PROFILE FORM -->
    <div class="bccm-fe-section">
      <h2 class="bccm-fe-title">📋 Hồ sơ cá nhân</h2>

      <form method="post" class="bccm-fe-form">
        <?php wp_nonce_field('bccm_frontend_profile', '_bccm_fe_nonce'); ?>

        <div class="bccm-fe-grid">
          <div class="bccm-fe-field">
            <label>Họ tên *</label>
            <input type="text" name="full_name" value="<?php echo $v('full_name'); ?>" required/>
          </div>
          <div class="bccm-fe-field">
            <label>Điện thoại</label>
            <input type="tel" name="phone" value="<?php echo $v('phone'); ?>"/>
          </div>
          <div class="bccm-fe-field">
            <label>Địa chỉ</label>
            <input type="text" name="address" value="<?php echo $v('address'); ?>"/>
          </div>
          <div class="bccm-fe-field">
            <label>Ngày sinh</label>
            <input type="date" name="dob" value="<?php echo $v('dob'); ?>"/>
          </div>
        </div>

        <!-- ASTROLOGY BIRTH DATA -->
        <h3 class="bccm-fe-subtitle">🌟 Thông tin Chiêm tinh</h3>
        <p class="bccm-fe-desc">Điền thông tin sinh để tạo bản đồ sao cá nhân chính xác.</p>
        <div class="bccm-fe-grid">
          <div class="bccm-fe-field">
            <label>Nơi sinh *</label>
            <div style="display:flex;gap:8px">
              <input type="text" name="birth_place" id="bccm_birth_place" value="<?php echo esc_attr($astro_row['birth_place'] ?? ''); ?>" placeholder="Thành phố, Quốc gia" style="flex:1"/>
              <button type="button" class="bccm-fe-btn bccm-fe-btn-secondary" id="bccm_geo_lookup_btn" style="white-space:nowrap">
                📍 Tìm tọa độ
              </button>
            </div>
            <span id="bccm_geo_status" class="bccm-fe-hint"></span>
          </div>
          <div class="bccm-fe-field">
            <label>Giờ sinh *</label>
            <input type="text" name="birth_time" value="<?php echo esc_attr($astro_row['birth_time'] ?? ''); ?>" placeholder="HH:MM (24h, VD: 14:30)"/>
            <span class="bccm-fe-hint">Nếu không biết chính xác, nhập khoảng 12:00</span>
          </div>
          <div class="bccm-fe-field">
            <label>Vĩ độ (Latitude)</label>
            <input type="number" step="any" name="astro_latitude" id="bccm_astro_lat" value="<?php echo esc_attr($astro_row['latitude'] ?? ''); ?>" placeholder="VD: 21.0285"/>
          </div>
          <div class="bccm-fe-field">
            <label>Kinh độ (Longitude)</label>
            <input type="number" step="any" name="astro_longitude" id="bccm_astro_lng" value="<?php echo esc_attr($astro_row['longitude'] ?? ''); ?>" placeholder="VD: 105.8542"/>
          </div>
          <div class="bccm-fe-field">
            <label>Múi giờ (Timezone)</label>
            <select name="astro_timezone" id="bccm_astro_tz">
              <?php
              $current_tz = $astro_row['timezone'] ?? 7;
              for ($tz = -12; $tz <= 14; $tz++) {
                $label = ($tz >= 0 ? '+' : '') . $tz;
                printf('<option value="%s"%s>UTC%s</option>', $tz, selected($current_tz, $tz, false), $label);
              }
              ?>
            </select>
            <span class="bccm-fe-hint">Việt Nam = UTC+7</span>
          </div>

          <?php
          // Type-specific fields (hidden - not needed for astrology frontend)
          // Commenting out to keep form simple
          /*
          $type_fields = bccm_fields_for_type($selected_type);
          foreach ($type_fields as $key => $cfg):
            $label = $cfg['label'] ?? $key;
            $ftype = $cfg['type'] ?? 'text';
            $val   = $coachee[$key] ?? '';
            $ph    = $cfg['placeholder'] ?? '';
          ?>
          <div class="bccm-fe-field">
            <label><?php echo esc_html($label); ?></label>
            <?php if ($ftype === 'select'): ?>
              <select name="<?php echo esc_attr($key); ?>">
                <?php foreach (($cfg['options'] ?? []) as $optVal => $optLabel): ?>
                  <option value="<?php echo esc_attr($optVal); ?>" <?php selected($val, $optVal); ?>><?php echo esc_html($optLabel); ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="<?php echo esc_attr($ftype); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($val); ?>" placeholder="<?php echo esc_attr($ph); ?>"/>
            <?php endif; ?>
          </div>
          <?php endforeach;
          */
          ?>
        </div>

        <div class="bccm-fe-actions">
          <button type="submit" name="bccm_frontend_save" value="1" class="bccm-fe-btn bccm-fe-btn-primary">
            💾 Lưu hồ sơ
          </button>
          <?php
          // Show regenerate button if chart data already exists - USE user_id
          $existing_chart = $wpdb->get_var($wpdb->prepare(
            "SELECT summary FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='western' AND summary IS NOT NULL LIMIT 1", $user_id
          ));

          if (!empty($existing_chart)):
          ?>
          <button type="submit" name="bccm_regenerate_chart" value="1" class="bccm-fe-btn bccm-fe-btn-secondary" onclick="return confirm('Đồng ý tạo lại bản đồ sao? Dữ liệu cũ sẽ bị ghi đè.');">
            🔄 Tạo lại bản đồ sao
          </button>
          <?php else: ?>
          <button type="submit" name="bccm_regenerate_chart" value="1" class="bccm-fe-btn" style="background:#7c3aed;color:#fff">
            🌟 Tạo bản đồ sao
          </button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <?php
    // ══════════════ NATAL CHART DISPLAY ══════════════
    // USE user_id for cross-platform consistency
    $t_astro = $wpdb->prefix . 'bccm_astro';
    $astro_western = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
    $astro_vedic = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic' AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
    
    // Note: user_id is the canonical key — coachee_id fallback removed

    $astro_summary = !empty($astro_western['summary']) ? json_decode($astro_western['summary'], true) : [];
    $astro_traits  = !empty($astro_western['traits'])  ? json_decode($astro_western['traits'], true) : [];
    $vedic_summary = !empty($astro_vedic['summary']) ? json_decode($astro_vedic['summary'], true) : [];
    $has_western   = !empty($astro_summary) || !empty($astro_traits);
    $has_vedic     = !empty($vedic_summary);
    $has_astro     = $has_western || $has_vedic;
    ?>

    <?php if ($has_astro): ?>
    <!-- NATAL CHART SECTION -->
    <div class="bccm-fe-section" style="background:linear-gradient(135deg,#0f0a1e,#1e1b4b);border:2px solid #7c3aed">
      <h2 class="bccm-fe-title" style="color:#e2e8f0;margin-bottom:16px">🌟 Bản Đồ Sao Cá Nhân</h2>

      <?php
      // Big 3 cards
      $sun_sign = $astro_summary['sun_sign'] ?? $vedic_summary['sun_sign'] ?? '';
      $moon_sign = $astro_summary['moon_sign'] ?? $vedic_summary['moon_sign'] ?? '';
      $asc_sign = $astro_summary['ascendant_sign'] ?? $vedic_summary['ascendant_sign'] ?? '';
      $signs = function_exists('bccm_zodiac_signs') ? bccm_zodiac_signs() : [];
      $find_sign = function($name) use ($signs) {
        foreach ($signs as $s) {
          if (strtolower($s['en'] ?? '') === strtolower($name)) return $s;
        }
        return ['vi' => $name, 'symbol' => '?', 'en' => $name];
      };
      ?>

      <?php if ($sun_sign || $moon_sign || $asc_sign): ?>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px">
        <?php if ($sun_sign): $si = $find_sign($sun_sign); ?>
        <div style="background:linear-gradient(135deg,#f59e0b33,#ef444433);border:1px solid #f59e0b;border-radius:16px;padding:20px;text-align:center">
          <div style="font-size:32px;margin-bottom:8px">☀️</div>
          <div style="font-size:11px;color:#fcd34d;text-transform:uppercase;letter-spacing:1px">Mặt Trời</div>
          <div style="font-size:28px;margin:8px 0"><?php echo esc_html($si['symbol']); ?></div>
          <div style="font-size:16px;font-weight:700;color:#fff"><?php echo esc_html($si['vi']); ?></div>
          <div style="font-size:12px;color:#94a3b8"><?php echo esc_html($si['en']); ?></div>
        </div>
        <?php endif; ?>

        <?php if ($moon_sign): $mi = $find_sign($moon_sign); ?>
        <div style="background:linear-gradient(135deg,#a78bfa33,#7c3aed33);border:1px solid #a78bfa;border-radius:16px;padding:20px;text-align:center">
          <div style="font-size:32px;margin-bottom:8px">🌙</div>
          <div style="font-size:11px;color:#c4b5fd;text-transform:uppercase;letter-spacing:1px">Mặt Trăng</div>
          <div style="font-size:28px;margin:8px 0"><?php echo esc_html($mi['symbol']); ?></div>
          <div style="font-size:16px;font-weight:700;color:#fff"><?php echo esc_html($mi['vi']); ?></div>
          <div style="font-size:12px;color:#94a3b8"><?php echo esc_html($mi['en']); ?></div>
        </div>
        <?php endif; ?>

        <?php if ($asc_sign): $ai = $find_sign($asc_sign); ?>
        <div style="background:linear-gradient(135deg,#22d3ee33,#06b6d433);border:1px solid #22d3ee;border-radius:16px;padding:20px;text-align:center">
          <div style="font-size:32px;margin-bottom:8px">⬆️</div>
          <div style="font-size:11px;color:#67e8f9;text-transform:uppercase;letter-spacing:1px">Cung Mọc</div>
          <div style="font-size:28px;margin:8px 0"><?php echo esc_html($ai['symbol']); ?></div>
          <div style="font-size:16px;font-weight:700;color:#fff"><?php echo esc_html($ai['vi']); ?></div>
          <div style="font-size:12px;color:#94a3b8"><?php echo esc_html($ai['en']); ?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Action Buttons -->
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
        <?php
        // Use user_id to get URL (finds coachee_id with actual astro data)
        $natal_url = bccm_get_natal_chart_url_by_user($user_id);
        if (!$natal_url) $natal_url = bccm_get_natal_chart_public_url($coachee_id);
        ?>
        <a href="<?php echo esc_url($natal_url); ?>" target="_blank" class="bccm-fe-btn" style="background:#3b82f6;color:#fff">
          🔗 Xem bản đồ sao đầy đủ
        </a>
        <a href="<?php echo esc_url($natal_url . '&export_pdf=1'); ?>" target="_blank" class="bccm-fe-btn" style="background:#7c3aed;color:#fff">
          📥 Tải PDF bản đồ sao
        </a>
      </div>

      <!-- Chart Info -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:12px">
        <?php if ($has_western): ?>
        <span style="background:#3b82f633;color:#93c5fd;padding:4px 12px;border-radius:999px">● Western Astrology</span>
        <?php endif; ?>
        <?php if ($has_vedic): ?>
        <span style="background:#7c3aed33;color:#c4b5fd;padding:4px 12px;border-radius:999px">● Vedic Astrology</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- CTA: TẠO AI AGENT -->
    <div class="bccm-fe-section" style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:2px solid #f59e0b;position:relative;overflow:hidden">
      <div style="position:absolute;top:-20px;right:-20px;font-size:80px;opacity:0.15">🤖</div>
      <h2 class="bccm-fe-title" style="color:#92400e;margin-bottom:12px">🚀 Tạo AI Agent Đồng Hành 24/7</h2>
      <p style="color:#78350f;font-size:14px;line-height:1.7;margin-bottom:16px">
        <strong>Bạn đã có bản đồ sao!</strong> Bây giờ hãy tạo AI Agent riêng để được:
      </p>
      <ul style="color:#78350f;font-size:14px;line-height:2;margin:0 0 20px;padding-left:24px">
        <li>🎯 <strong>Nhắc nhở công danh, sự nghiệp</strong> - AI phân tích cơ hội & thách thức theo bản đồ sao</li>
        <li>💕 <strong>Tư vấn tình duyên, hôn nhân</strong> - Xem kim tinh, mặt trăng tương tác</li>
        <li>📅 <strong>Điềm báo chiêm tinh hàng ngày</strong> - Cập nhật transit & năng lượng mỗi ngày</li>
        <li>🧠 <strong>Đồng hành sức khỏe tinh thần</strong> - AI hiểu tính cách bạn qua Big 3</li>
      </ul>
      <a href="https://bizcity.vn/dung-thu-mien-phi/" class="bccm-fe-btn bccm-fe-btn-primary" style="font-size:16px;padding:16px 32px">
        ⚡ Tạo AI Agent miễn phí ngay →
      </a>
      <p style="margin-top:12px;font-size:12px;color:#a16207">
        💡 Hoàn toàn miễn phí! AI Agent sẽ lưu bản đồ sao của bạn và đồng hành hàng ngày.
      </p>
    </div>

    <?php if ($show_map): ?>
    <!-- LIFE MAP PREVIEW -->
    <?php
    $life_map  = json_decode($coachee['mental_json'] ?? '[]', true) ?: [];
    $health    = json_decode($coachee['health_json'] ?? '[]', true) ?: [];
    $milestone = json_decode($coachee['bizcoach_json'] ?? '[]', true) ?: [];
    $has_any   = !empty($life_map) || !empty($health) || !empty($milestone);
    ?>

    <?php if ($has_any): ?>
    <div class="bccm-fe-section bccm-fe-map-section">
      <h2 class="bccm-fe-title">🗺️ Bản đồ cuộc đời của bạn</h2>

      <?php if (!empty($life_map)): ?>
      <!-- Identity -->
      <?php if (!empty($life_map['identity'])): ?>
      <div class="bccm-fe-card">
        <h3>🪪 Identity</h3>
        <div class="bccm-fe-identity-grid">
          <?php if (!empty($life_map['identity']['life_stage'])): ?>
          <div class="bccm-fe-badge">📍 <?php echo esc_html($life_map['identity']['life_stage']); ?></div>
          <?php endif; ?>
          <?php if (!empty($life_map['identity']['core_values'])):
            foreach ($life_map['identity']['core_values'] as $cv): ?>
            <div class="bccm-fe-badge bccm-fe-badge-accent">⭐ <?php echo esc_html($cv); ?></div>
          <?php endforeach; endif; ?>
          <?php if (!empty($life_map['identity']['personality_type'])): ?>
          <div class="bccm-fe-badge bccm-fe-badge-info">🧬 <?php echo esc_html($life_map['identity']['personality_type']); ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Life Dimensions -->
      <?php if (!empty($life_map['life_dimensions'])): ?>
      <div class="bccm-fe-card">
        <h3>📊 6 Chiều cuộc sống</h3>
        <div class="bccm-fe-dims">
          <?php foreach ($life_map['life_dimensions'] as $dim):
            $score = intval($dim['score'] ?? 0);
            $color = $score >= 7 ? '#10b981' : ($score >= 4 ? '#f59e0b' : '#ef4444');
          ?>
          <div class="bccm-fe-dim">
            <div class="bccm-fe-dim-header">
              <span><?php echo esc_html($dim['area'] ?? ''); ?></span>
              <span style="color:<?php echo $color; ?>;font-weight:700"><?php echo $score; ?>/10</span>
            </div>
            <div class="bccm-fe-progress-track">
              <div class="bccm-fe-progress-fill" style="width:<?php echo ($score * 10); ?>%;background:<?php echo $color; ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Pain Points -->
      <?php if (!empty($life_map['pain_points'])): ?>
      <div class="bccm-fe-card">
        <h3>🔥 Nỗi đau cần giải quyết</h3>
        <?php foreach ($life_map['pain_points'] as $pp):
          $impact = intval($pp['impact_level'] ?? 0);
          $severity = $impact >= 8 ? 'high' : ($impact >= 5 ? 'medium' : 'low');
        ?>
        <div class="bccm-fe-pain bccm-fe-pain-<?php echo $severity; ?>">
          <div class="bccm-fe-pain-header">
            <strong><?php echo esc_html($pp['area'] ?? ''); ?></strong>
            <span class="bccm-fe-pain-impact"><?php echo $impact; ?>/10</span>
          </div>
          <p><?php echo esc_html($pp['description'] ?? ''); ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Motivations -->
      <?php if (!empty($life_map['motivations'])): ?>
      <div class="bccm-fe-card">
        <h3>🚀 Động lực thúc đẩy</h3>
        <div class="bccm-fe-motivations">
          <?php foreach ($life_map['motivations'] as $m): ?>
          <div class="bccm-fe-motivation-item">
            <span class="bccm-fe-motivation-type"><?php echo esc_html($m['type'] ?? ''); ?></span>
            <span><?php echo esc_html($m['description'] ?? ''); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Life Vision -->
      <?php if (!empty($life_map['life_vision'])): ?>
      <div class="bccm-fe-card">
        <h3>🎯 Tầm nhìn cuộc đời</h3>
        <div class="bccm-fe-vision-grid">
          <?php foreach ($life_map['life_vision'] as $lv): ?>
          <div class="bccm-fe-vision-item">
            <div class="bccm-fe-vision-period"><?php echo esc_html($lv['period'] ?? ''); ?></div>
            <ul>
              <?php foreach (($lv['goals'] ?? []) as $goal): ?>
              <li><?php echo esc_html($goal); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php endif; // end life_map ?>

      <!-- Coaching Strategy -->
      <?php if (!empty($life_map['coaching_strategy'])): ?>
      <div class="bccm-fe-card bccm-fe-card-highlight">
        <h3>🤖 Chiến lược đồng hành AI</h3>
        <?php $cs = $life_map['coaching_strategy']; ?>
        <p><strong>Giọng điệu:</strong> <?php echo esc_html($cs['tone'] ?? ''); ?></p>
        <p><strong>Check-in:</strong> <?php echo esc_html($cs['check_in_frequency'] ?? ''); ?></p>
        <?php if (!empty($cs['focus_areas'])): ?>
        <p><strong>Lĩnh vực tập trung:</strong> <?php echo esc_html(implode(', ', $cs['focus_areas'])); ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Public map link -->
      <?php if (!empty($coachee['public_url'])): ?>
      <div style="text-align:center;margin-top:20px">
        <a href="<?php echo esc_url($coachee['public_url']); ?>" class="bccm-fe-btn bccm-fe-btn-primary" target="_blank">
          🌐 Xem bản đồ Public (PDF)
        </a>
      </div>
      <?php endif; ?>

      <!-- Natal Chart Link -->
      <?php
      // Check if natal chart exists for this user
      $t_astro = $wpdb->prefix . 'bccm_astro';
      $has_natal = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t_astro WHERE user_id=%d AND (summary IS NOT NULL OR traits IS NOT NULL)", $user_id
      ));
      if ($has_natal > 0):
        // Use user_id to get URL (finds coachee_id with actual astro data)
        $natal_url = bccm_get_natal_chart_url_by_user($user_id);
        if (!$natal_url) $natal_url = bccm_get_natal_chart_public_url($coachee_id);
      ?>
      <div class="bccm-fe-card" style="margin-top:20px;background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:2px solid #7dd3fc">
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
          <div style="flex:1;min-width:200px">
            <h3 style="margin:0 0 8px;color:#0c4a6e">🌟 Bản Đồ Sao Cá Nhân</h3>
            <p style="margin:0;font-size:13px;color:#075985">Xem bản đồ chiêm tinh đầy đủ (Western + Vedic Astrology). Có thể tải PDF và chia sẻ.</p>
          </div>
          <div style="display:flex;gap:8px">
            <a href="<?php echo esc_url($natal_url); ?>" target="_blank" class="bccm-fe-btn" style="background:#0284c7;color:#fff">
              🔗 Xem bản đồ sao
            </a>
            <a href="<?php echo esc_url($natal_url . '&export_pdf=1'); ?>" target="_blank" class="bccm-fe-btn" style="background:#0ea5e9;color:#fff">
              📥 Tải PDF
            </a>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="bccm-fe-section">
      <div class="bccm-fe-empty-map">
        <div class="bccm-fe-empty-icon">🗺️</div>
        <h3>Chưa có bản đồ cuộc đời</h3>
        <p>Hãy điền đầy đủ thông tin và trả lời 20 câu hỏi phía trên.<br/>
        Admin sẽ tạo bản đồ AI cho bạn trong thời gian sớm nhất.</p>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; // end show_map ?>

  </div>

  <!-- Geo Lookup JavaScript -->
  <script>
  (function(){
    var btn = document.getElementById('bccm_geo_lookup_btn');
    if (!btn) return;
    
    btn.addEventListener('click', function(e){
      e.preventDefault();
      var placeInput = document.getElementById('bccm_birth_place');
      var latInput = document.getElementById('bccm_astro_lat');
      var lngInput = document.getElementById('bccm_astro_lng');
      var tzInput = document.getElementById('bccm_astro_tz');
      var status = document.getElementById('bccm_geo_status');
      
      var place = placeInput ? placeInput.value.trim() : '';
      if (!place) {
        if (status) status.textContent = '⚠️ Vui lòng nhập nơi sinh trước';
        return;
      }
      
      if (status) status.textContent = '🔍 Đang tìm...';
      btn.disabled = true;
      
      // Use Nominatim (OpenStreetMap) for geocoding
      var url = 'https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(place);
      
      fetch(url, {
        headers: {
          'Accept': 'application/json',
          'User-Agent': 'BizCoach-Map/1.0'
        }
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        btn.disabled = false;
        if (data && data.length > 0) {
          var loc = data[0];
          if (latInput) latInput.value = parseFloat(loc.lat).toFixed(6);
          if (lngInput) lngInput.value = parseFloat(loc.lon).toFixed(6);
          
          // Estimate timezone from longitude (rough: 15° = 1 hour)
          var tzEstimate = Math.round(parseFloat(loc.lon) / 15);
          if (tzInput) tzInput.value = tzEstimate;
          
          if (status) status.textContent = '✅ Đã tìm thấy: ' + loc.display_name.substring(0, 60) + '...';
        } else {
          if (status) status.textContent = '❌ Không tìm thấy địa điểm';
        }
      })
      .catch(function(err) {
        btn.disabled = false;
        if (status) status.textContent = '❌ Lỗi: ' + err.message;
      });
    });
  })();
  </script>

  <?php
}

/* =====================================================================
 * 5. FLUSH REWRITE on activation (for my-account endpoint)
 * =====================================================================*/
add_action('bccm_after_activate', function () {
  add_rewrite_endpoint('life-map', EP_ROOT | EP_PAGES);
  flush_rewrite_rules();
});
