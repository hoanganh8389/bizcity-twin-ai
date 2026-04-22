<?php
/**
 * BizCoach Map – Admin Self-Profile (Step 1: Hồ sơ & Chiêm tinh)
 *
 * Bước 1 trong workflow 4 bước:
 *   Step 1: Hồ sơ cá nhân + Chiêm tinh (trang này)
 *   Step 2: Chọn Coach Template + Câu hỏi bổ sung
 *   Step 3: Tạo Character (bizcity-knowledge)
 *   Step 4: Success Plan + Gán Character đồng hành
 *
 * Astrology là NỀN TẢNG cho TẤT CẢ coach types.
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * ADMIN MENU: Bước 1 – Hồ sơ & Chiêm tinh
 * =====================================================================*/
add_action('admin_menu', function () {
  add_submenu_page(
    'bccm_user_profiles',
    'Bước 1: Hồ sơ & Chiêm tinh',
    'Bước 1: Hồ sơ & Chiêm tinh',
    'edit_posts',
    'bccm_my_profile',
    'bccm_admin_my_profile',
    11
  );
}, 11);

/* =====================================================================
 * HELPER: Lấy hoặc tạo coachee profile cho user_id
 * =====================================================================*/
function bccm_get_or_create_user_coachee($user_id, $platform = 'WEBCHAT', $coach_type = 'mental_coach') {
  global $wpdb;
  $t = bccm_tables();

  if ( bccm_profiles_support_platform_type() ) {
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE user_id = %d AND platform_type = %s ORDER BY id DESC LIMIT 1",
      $user_id, $platform
    ), ARRAY_A);
  } else {
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
      $user_id
    ), ARRAY_A);
  }

  if ($row) return $row;

  // Auto-create from WP user data
  $user = get_userdata($user_id);
  if (!$user) return null;

  $insert_data = [
    'user_id'    => $user_id,
    'coach_type' => $coach_type,
    'full_name'  => $user->display_name ?: $user->user_login,
    'phone'      => get_user_meta($user_id, 'billing_phone', true) ?: '',
    'created_at' => current_time('mysql'),
    'updated_at' => current_time('mysql'),
  ];
  if ( bccm_profiles_support_platform_type() ) {
    $insert_data['platform_type'] = $platform;
  }

  $wpdb->insert($t['profiles'], $insert_data);

  $new_id = $wpdb->insert_id;
  return $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$t['profiles']} WHERE id = %d", $new_id
  ), ARRAY_A);
}

/* =====================================================================
 * ADMIN PAGE: Bước 1 – Hồ sơ cá nhân + Chiêm tinh
 * =====================================================================*/
function bccm_admin_my_profile() {
  if (!current_user_can('edit_posts')) return;

  global $wpdb;
  $t = bccm_tables();
  $user_id = get_current_user_id();

  // Lấy hoặc tạo hồ sơ ADMINCHAT
  $coachee = bccm_get_or_create_user_coachee($user_id, 'ADMINCHAT', 'mental_coach');
  if (!$coachee) {
    #echo '<div class="wrap bccm-wrap"><h1>Lỗi</h1><p>Không thể tạo hồ sơ.</p></div>';
    #return;
  }

  $coachee_id    = (int)$coachee['id'];

  // Astro data (Western + Vedic separate rows)
  // Query by user_id to share astro data across all platforms (WEBCHAT, ADMINCHAT, etc.)
  $t_astro   = $wpdb->prefix . 'bccm_astro';
  $astro_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western'", $user_id), ARRAY_A);
  $vedic_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic'", $user_id), ARRAY_A);
  // For birth data, use whichever row exists
  if (!$astro_row) $astro_row = $vedic_row;

  /* ==================== HANDLE POST ACTIONS ==================== */
  if (!empty($_POST['bccm_action']) && check_admin_referer('bccm_step1_profile')) {

    $data = [
      'full_name' => sanitize_text_field($_POST['full_name'] ?? ''),
      'phone'     => sanitize_text_field($_POST['phone'] ?? ''),
      'address'   => sanitize_text_field($_POST['address'] ?? ''),
      'dob'       => sanitize_text_field($_POST['dob'] ?? ''),
      'user_id'   => $user_id,
    ];
    if ( bccm_profiles_support_platform_type() ) {
      $data['platform_type'] = 'ADMINCHAT';
    }

    $coachee_id = bccm_upsert_profile($data, $coachee_id);

    // ── Save astro birth data to bccm_astro table ──
    $birth_place = sanitize_text_field($_POST['birth_place'] ?? '');
    $birth_time  = sanitize_text_field($_POST['birth_time'] ?? '');
    $latitude    = floatval($_POST['astro_latitude'] ?? 0);
    $longitude   = floatval($_POST['astro_longitude'] ?? 0);
    $timezone    = floatval($_POST['astro_timezone'] ?? 7);

    if ($birth_place || $birth_time) {
      // Update birth data on all chart_type rows for this user (by user_id to share across platforms)
      $existing_astro = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='western'", $user_id
      ));
      $astro_row_data = [
        'birth_place' => $birth_place,
        'birth_time'  => $birth_time,
        'latitude'    => $latitude,
        'longitude'   => $longitude,
        'timezone'    => $timezone,
        'updated_at'  => current_time('mysql'),
      ];
      if ($existing_astro) {
        $wpdb->update($wpdb->prefix . 'bccm_astro', $astro_row_data, ['user_id' => $user_id, 'chart_type' => 'western']);
      } else {
        $astro_row_data['coachee_id'] = $coachee_id;
        $astro_row_data['user_id']    = $user_id;
        $astro_row_data['chart_type'] = 'western';
        $astro_row_data['created_at'] = current_time('mysql');
        $wpdb->insert($wpdb->prefix . 'bccm_astro', $astro_row_data);
      }
      // Also update vedic row birth data if it exists
      $existing_vedic = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='vedic'", $user_id
      ));
      if ($existing_vedic) {
        $wpdb->update($wpdb->prefix . 'bccm_astro', $astro_row_data, ['user_id' => $user_id, 'chart_type' => 'vedic']);
      }
    }

    // ── Zodiac sign fallback from DOB ──
    $dob_val = sanitize_text_field($_POST['dob'] ?? '');
    if ($dob_val && empty($coachee['zodiac_sign'])) {
      $sun = bccm_astro_sun_sign_from_dob($dob_val);
      if (!empty($sun['en'])) {
        $wpdb->update($t['profiles'], [
          'zodiac_sign' => strtolower($sun['en']),
        ], ['id' => $coachee_id]);
      }
    }

    // Run generator based on action
    $action = sanitize_text_field($_POST['bccm_action']);

    // ── Helper: build birth_data array from form inputs ──
    $dob_parts = explode('-', $dob_val);
    $birth_data_ready = false;
    $birth_data = [];
    if (count($dob_parts) === 3 && $birth_time) {
      $time_parts = explode(':', $birth_time);
      $birth_data = [
        'year'      => intval($dob_parts[0]),
        'month'     => intval($dob_parts[1]),
        'day'       => intval($dob_parts[2]),
        'hour'      => intval($time_parts[0] ?? 12),
        'minute'    => intval($time_parts[1] ?? 0),
        'second'    => 0,
        'latitude'  => $latitude ?: 21.0285,
        'longitude' => $longitude ?: 105.8542,
        'timezone'  => $timezone ?: 7,
      ];
      $birth_data_ready = true;
    }

    if ($action === 'gen_astro_chart' || $action === 'gen_free_chart') {
      // ── Freeze Astrology API ──
      if ($birth_data_ready) {
        $chart_result = bccm_astro_fetch_full_chart($birth_data);
        if (is_wp_error($chart_result)) {
          echo '<div class="error"><p>❌ Lỗi Freeze Astrology API: ' . esc_html($chart_result->get_error_message()) . '</p></div>';
        } else {
          $birth_input = array_merge($birth_data, [
            'birth_place' => $birth_place,
            'birth_time'  => $birth_time,
          ]);
          bccm_astro_save_chart($coachee_id, $chart_result, $birth_input, $user_id);
          echo '<div class="updated"><p>✅ Đã tạo bản đồ chiêm tinh (Freeze Astrology API) thành công!</p></div>';
        }
      } else {
        echo '<div class="error"><p>⚠️ Cần nhập đầy đủ Ngày sinh và Giờ sinh (HH:MM) để tạo bản đồ chiêm tinh.</p></div>';
      }

    } elseif ($action === 'gen_vedic_chart') {
      // ── Vedic / Indian Astrology API ──
      if ($birth_data_ready) {
        $vedic_result = bccm_vedic_fetch_full_chart($birth_data);
        if (is_wp_error($vedic_result)) {
          echo '<div class="error"><p>❌ Lỗi Vedic Astrology API: ' . esc_html($vedic_result->get_error_message()) . '</p></div>';
        } else {
          $birth_input = array_merge($birth_data, [
            'birth_place' => $birth_place,
            'birth_time'  => $birth_time,
          ]);
          bccm_vedic_save_chart($coachee_id, $vedic_result, $birth_input, $user_id);
          echo '<div class="updated"><p>✅ Đã tạo bản đồ chiêm tinh Vedic (Indian Astrology) thành công!</p></div>';
        }
      } else {
        echo '<div class="error"><p>⚠️ Cần nhập đầy đủ Ngày sinh và Giờ sinh (HH:MM) để tạo bản đồ chiêm tinh.</p></div>';
      }

    } elseif ($action === 'gen_both_charts') {
      // ── Generate BOTH charts simultaneously ──
      if ($birth_data_ready) {
        $birth_input = array_merge($birth_data, [
          'birth_place' => $birth_place,
          'birth_time'  => $birth_time,
        ]);
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
        $vedic_result = bccm_vedic_fetch_full_chart($birth_data);
        if (is_wp_error($vedic_result)) {
          $errors[] = 'Vedic: ' . $vedic_result->get_error_message();
        } else {
          bccm_vedic_save_chart($coachee_id, $vedic_result, $birth_input, $user_id);
          $success[] = 'Vedic Astrology';
        }

        if (!empty($success)) {
          echo '<div class="updated"><p>✅ Đã tạo bản đồ: ' . esc_html(implode(', ', $success)) . '</p></div>';
        }
        if (!empty($errors)) {
          echo '<div class="error"><p>⚠️ Lỗi: ' . esc_html(implode(' | ', $errors)) . '</p></div>';
        }
      } else {
        echo '<div class="error"><p>⚠️ Cần nhập đầy đủ Ngày sinh và Giờ sinh (HH:MM) để tạo bản đồ chiêm tinh.</p></div>';
      }

    } else {
      echo '<div class="updated"><p>✅ Đã lưu hồ sơ cá nhân.</p></div>';
    }

    // Refresh data
    $coachee  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
    $astro_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='western'", $user_id), ARRAY_A);
    $vedic_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='vedic'", $user_id), ARRAY_A);
  }

  $v = function($k) use($coachee) { return esc_attr($coachee[$k] ?? ''); };

  /* ==================== RENDER UI ==================== */
  echo '<div class="wrap bccm-wrap">';
  bccm_render_workflow_steps();
  echo '<h1>🌟 Bước 1: Hồ sơ cá nhân & Chiêm tinh</h1>';
  echo '<p class="description">Chiêm tinh là nền tảng cho tất cả Coach Templates. Hãy hoàn thành hồ sơ và tạo bản đồ chiêm tinh trước khi chọn Coach Template ở Bước 2.</p>';

  echo '<form method="post">';
  wp_nonce_field('bccm_step1_profile');
  echo '<table class="form-table">';

  // ── Personal info ──
  echo '<tr><th colspan="2"><h3 style="margin:0">👤 Thông tin cá nhân</h3></th></tr>';
  echo '<tr><th>Họ tên</th><td><input name="full_name" value="' . $v('full_name') . '" class="regular-text"/></td></tr>';
  echo '<tr><th>Điện thoại</th><td><input name="phone" value="' . $v('phone') . '" class="regular-text"/></td></tr>';
  echo '<tr><th>Địa chỉ</th><td><input name="address" value="' . $v('address') . '" class="regular-text"/></td></tr>';
  echo '<tr><th>Ngày sinh</th><td><input type="date" name="dob" value="' . $v('dob') . '"/></td></tr>';

  // ── Astro birth data (universal for ALL coach types) ──
  echo '<tr><th colspan="2"><h3 style="margin:0">🌟 Chiêm tinh (Astrology) — Nền tảng tất cả Coach</h3></th></tr>';
  echo '<tr><th>Nơi sinh</th><td>';
  echo '<input name="birth_place" id="bccm_birth_place" value="' . esc_attr($astro_row['birth_place'] ?? '') . '" class="regular-text" placeholder="Thành phố, quốc gia"/>';
  echo ' <button type="button" class="button button-small" id="bccm_geo_lookup_btn">📍 Tìm tọa độ</button>';
  echo '<span id="bccm_geo_status" style="margin-left:8px;color:#666;font-size:12px"></span>';
  echo '</td></tr>';
  echo '<tr><th>Giờ sinh</th><td><input name="birth_time" value="' . esc_attr($astro_row['birth_time'] ?? '') . '" class="regular-text" placeholder="HH:MM (24h, VD: 14:30)"/></td></tr>';
  echo '<tr><th>Tọa độ</th><td>';
  echo '<input type="number" step="any" name="astro_latitude" id="bccm_astro_lat" value="' . esc_attr($astro_row['latitude'] ?? '') . '" placeholder="Latitude" style="width:130px"/> ';
  echo '<input type="number" step="any" name="astro_longitude" id="bccm_astro_lng" value="' . esc_attr($astro_row['longitude'] ?? '') . '" placeholder="Longitude" style="width:130px"/> ';
  echo '<select name="astro_timezone" id="bccm_astro_tz" style="width:130px">';
  $current_tz = $astro_row['timezone'] ?? 7;
  for ($tz = -12; $tz <= 14; $tz++) {
    $label = ($tz >= 0 ? '+' : '') . $tz;
    printf('<option value="%s"%s>UTC%s</option>', $tz, selected($current_tz, $tz, false), $label);
  }
  echo '</select>';
  echo '<p class="description">Nhập nơi sinh rồi bấm "Tìm tọa độ", hoặc nhập thủ công.</p>';
  echo '</td></tr>';

  // Zodiac sign (read-only, auto-detected)
  $zodiac = $coachee['zodiac_sign'] ?? '';
  if ($zodiac) {
    $signs = bccm_zodiac_signs();
    $sign_info = null;
    foreach ($signs as $s) {
      if (strtolower($s['en'] ?? '') === strtolower($zodiac)) { $sign_info = $s; break; }
    }
    $zodiac_display = $sign_info ? ($sign_info['symbol'] . ' ' . $sign_info['vi'] . ' (' . $sign_info['en'] . ')') : $zodiac;
    echo '<tr><th>Cung hoàng đạo</th><td><strong>' . esc_html($zodiac_display) . '</strong> <span class="description">(tự nhận diện từ ngày sinh)</span></td></tr>';
  }

  echo '</table>';

  // Action buttons — Dual chart generation (Western + Vedic)
  echo '<p style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center">';
  echo '<button class="button button-primary" name="bccm_action" value="save_only">💾 Lưu hồ sơ</button>';
  echo '<span style="border-left:2px solid #e5e7eb;height:28px;margin:0 4px"></span>';
  echo '<button class="button" name="bccm_action" value="gen_free_chart" style="background:#3b82f6;color:#fff;border-color:#2563eb" title="Tạo bản đồ Western Astrology">🌟 Tạo theo Western Astrology</button>';
  echo '<button class="button" name="bccm_action" value="gen_vedic_chart" style="background:#7c3aed;color:#fff;border-color:#6d28d9" title="Tạo bản đồ Vedic/Indian Astrology">🕉️ Tạo theo Vedic Astrology</button>';
  echo '<button class="button" name="bccm_action" value="gen_both_charts" style="background:#059669;color:#fff;border-color:#047857" title="Tạo cả 2 bản đồ song song">⚡ Tạo cả 2 bản đồ</button>';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_step2_coach_template')) . '" class="button button-hero" style="margin-left:auto">Bước 2: Chọn Coach Template →</a>';
  echo '</p>';
  echo '<p class="description" style="margin-top:4px;color:#6b7280">💡 Tạo 2 bộ bản đồ chiêm tinh (Western + Vedic) để AI Agent có cái nhìn đa chiều và hiểu bạn toàn diện hơn.</p>';

  echo '</form>';

  /* ==================== ASTRO RESULTS — PARALLEL DISPLAY ==================== */
  $astro_summary = !empty($astro_row['summary']) ? json_decode($astro_row['summary'], true) : [];
  $astro_traits  = !empty($astro_row['traits'])  ? json_decode($astro_row['traits'], true) : [];
  $vedic_summary = !empty($vedic_row['summary']) ? json_decode($vedic_row['summary'], true) : [];
  $vedic_traits  = !empty($vedic_row['traits'])  ? json_decode($vedic_row['traits'], true) : [];
  $has_western   = !empty($astro_summary) || !empty($astro_traits);
  $has_vedic     = !empty($vedic_summary) || !empty($vedic_traits);

  if ($has_western || $has_vedic) {
    echo '<div class="bccm-natal-report" style="margin-top:24px">';

    // ══════════════ HEADER ══════════════
    $birth_data_display = $astro_traits['birth_data'] ?? $vedic_traits['birth_data'] ?? [];
    echo '<div class="bccm-natal-header">';
    echo '<h2 style="margin:0 0 6px;font-size:22px;color:#1a1a2e">🌟 Bản Đồ Sao Cá Nhân <small style="font-weight:400;color:#6b7280;font-size:13px">(Natal Chart — Western + Vedic)</small></h2>';
    if (!empty($birth_data_display) || !empty($astro_row['birth_place'])) {
      echo '<div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px;color:#4b5563;margin-top:4px">';
      if (!empty($coachee['full_name'])) echo '<span>👤 ' . esc_html($coachee['full_name']) . '</span>';
      $dob_display = '';
      if (!empty($birth_data_display['day']) && !empty($birth_data_display['month']) && !empty($birth_data_display['year'])) {
        $dob_display = sprintf('%02d/%02d/%04d', $birth_data_display['day'], $birth_data_display['month'], $birth_data_display['year']);
      } elseif (!empty($coachee['dob'])) {
        $dob_display = date('d/m/Y', strtotime($coachee['dob']));
      }
      if ($dob_display) echo '<span>📅 ' . esc_html($dob_display) . '</span>';
      if (!empty($astro_row['birth_time'])) echo '<span>🕐 ' . esc_html($astro_row['birth_time']) . '</span>';
      if (!empty($astro_row['birth_place'])) echo '<span>📍 ' . esc_html($astro_row['birth_place']) . '</span>';
      echo '</div>';
    }

    // Toolbar: export PDFs + AI reports
    echo '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
 
    // Public Natal Chart button (first, before AI reports)
    if ($has_western || $has_vedic) {
      $natal_url_toolbar = bccm_get_natal_chart_url_by_user($user_id);
      if (!$natal_url_toolbar) {
        $natal_url_toolbar = bccm_get_natal_chart_public_url($coachee_id);
      }
      echo '<a href="' . esc_url($natal_url_toolbar) . '" target="_blank" class="button" style="background:#10b981;color:#fff;border-color:#059669">🌟 Xem Bản Đồ Sao</a>';
      echo '<span style="border-left:2px solid #e5e7eb;height:24px;margin:0 6px"></span>';
    }

    if ($has_western) {
      echo '<a href="' . esc_url(admin_url('admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&_wpnonce=' . wp_create_nonce('bccm_natal_report_full'))) . '" target="_blank" class="button" style="background:#3b82f6;color:#fff;border-color:#2563eb">🤖 Luận Giải AI — Western</a>';
      echo '<a href="' . esc_url(admin_url('admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&regenerate=1&_wpnonce=' . wp_create_nonce('bccm_natal_report_full'))) . '" target="_blank" class="button" style="background:#f59e0b;color:#fff;border-color:#d97706" title="Tạo lại báo cáo Western (xóa cache)">🔄 Tạo lại Western</a>';
    }
    if ($has_vedic) {
      echo '<a href="' . esc_url(admin_url('admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=vedic&_wpnonce=' . wp_create_nonce('bccm_natal_report_full'))) . '" target="_blank" class="button" style="background:#7c3aed;color:#fff;border-color:#6d28d9">🕉️ Luận Giải AI — Vedic</a>';
    }

    // ── Transit Report buttons (tuần tới, tháng tới, năm tới) ──
    if ($has_western) {
      $transit_nonce = wp_create_nonce('bccm_transit_report');
      echo '<span style="border-left:2px solid #e5e7eb;height:24px;margin:0 6px"></span>';
      echo '<a href="' . esc_url(admin_url('admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=week&_wpnonce=' . $transit_nonce)) . '" target="_blank" class="button" style="background:#0ea5e9;color:#fff;border-color:#0284c7" title="Transit: vị trí sao dịch chuyển so с natal 7 ngày tới">🔮 Transit Tuần tới</a>';
      echo '<a href="' . esc_url(admin_url('admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=month&_wpnonce=' . $transit_nonce)) . '" target="_blank" class="button" style="background:#8b5cf6;color:#fff;border-color:#7c3aed" title="Transit: vị trí sao dịch chuyển so với natal 30 ngày tới">🔮 Transit Tháng tới</a>';
      echo '<a href="' . esc_url(admin_url('admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=year&_wpnonce=' . $transit_nonce)) . '" target="_blank" class="button" style="background:#059669;color:#fff;border-color:#047857" title="Transit: vị trí sao dịch chuyển so với natal 12 tháng tới">🔮 Transit Năm tới</a>';
    }

    echo '<span style="font-size:11px;color:#9ca3af;margin-left:8px">Sources: ';
    if ($has_western) echo '<span style="color:#3b82f6">● Western Astrology</span> ';
    if ($has_vedic) echo '<span style="color:#7c3aed">● Vedic Astrology</span> ';
    echo '<span style="color:#f59e0b">● AI Report (GPT)</span>';
    if ($has_western) echo ' <span style="color:#0ea5e9">● Transit</span>';
    echo '</span>';
    echo '</div>';

    // ── Standalone Natal Chart Link (Public/Shareable) ──
    if ($has_western || $has_vedic) {
      // Use user_id to get URL (finds coachee_id that has actual astro data)
      $natal_url = bccm_get_natal_chart_url_by_user($user_id);
      if (!$natal_url) {
        // Fallback to coachee_id if no user-based URL found
        $natal_url = bccm_get_natal_chart_public_url($coachee_id);
      }
      echo '<div style="margin-top:12px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px">';
      echo '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">';
      echo '<strong style="color:#166534;font-size:13px">🌐 Bản đồ sao độc lập:</strong>';
      echo '<input type="text" readonly value="' . esc_attr($natal_url) . '" style="flex:1;min-width:300px;padding:6px 10px;border:1px solid #86efac;border-radius:4px;font-size:12px;font-family:monospace;background:#fff"/>';
      echo '<a href="' . esc_url($natal_url) . '" target="_blank" class="button" style="background:#10b981;color:#fff;border-color:#059669">🔗 Xem bản đồ công khai</a>';
      echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(\'' . esc_js($natal_url) . '\'); this.textContent=\'✅ Đã copy!\'">📋 Copy link</button>';
      echo '</div>';
      echo '<p style="margin:8px 0 0;font-size:11px;color:#15803d">💡 Link này có thể chia sẻ công khai, không cần đăng nhập. User có thể xem và tải PDF bản đồ sao.</p>';
      echo '</div>';
    }

    echo '</div>';

    // ══════════════ BIG 3 CARDS (Side by side comparison) ══════════════
    $sun_free = $astro_summary['sun_sign'] ?? '';
    $moon_free = $astro_summary['moon_sign'] ?? '';
    $asc_free = $astro_summary['ascendant_sign'] ?? '';
    $sun_vedic = $vedic_summary['sun_sign'] ?? '';
    $moon_vedic = $vedic_summary['moon_sign'] ?? '';
    $asc_vedic = $vedic_summary['ascendant_sign'] ?? '';

    // Use whichever is available for display, prefer Western as primary
    $sun = $sun_free ?: $sun_vedic;
    $moon = $moon_free ?: $moon_vedic;
    $asc = $asc_free ?: $asc_vedic;

    if ($sun || $moon || $asc) {
      $signs = bccm_zodiac_signs();
      $find_sign = function($name) use ($signs) {
        foreach ($signs as $s) {
          if (strtolower($s['en'] ?? '') === strtolower($name)) return $s;
        }
        return ['vi' => $name, 'symbol' => '?', 'en' => $name, 'element' => ''];
      };

      echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:16px 0 20px">';
      $big3_items = [
        ['☀️ Mặt Trời (Sun)',     $sun,  'Bản ngã, ý chí, mục đích sống',          ['#1a1a2e','#2d1b69']],
        ['🌙 Mặt Trăng (Moon)',   $moon, 'Cảm xúc, nhu cầu nội tâm, bản năng',     ['#1a2e2e','#1b4d69']],
        ['⬆️ Cung Mọc (ASC)',     $asc,  'Ấn tượng đầu tiên, vẻ ngoài, tiếp cận',  ['#2e1a2e','#691b4d']],
      ];
      foreach ($big3_items as $item) {
        $info = $find_sign($item[1]);
        echo '<div style="background:linear-gradient(135deg,' . $item[3][0] . ',' . $item[3][1] . ');color:#fff;padding:18px;border-radius:12px;text-align:center;box-shadow:0 4px 12px rgba(0,0,0,0.15)">';
        echo '<div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px">' . $item[0] . '</div>';
        echo '<div style="font-size:32px;margin:8px 0">' . esc_html($info['symbol'] ?? '?') . '</div>';
        echo '<div style="font-size:18px;font-weight:700;color:#fbbf24">' . esc_html($info['vi'] ?? $item[1]) . '</div>';
        echo '<div style="font-size:12px;color:#94a3b8;margin-top:2px">' . esc_html($info['en'] ?? '') . '</div>';
        echo '<div style="font-size:11px;color:#6ee7b7;margin-top:6px">' . esc_html($item[2]) . '</div>';
        echo '</div>';
      }
      echo '</div>';
    }

    // ══════════════ DUAL CHART DISPLAY — Side by side ══════════════
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:20px 0">';

    // ── LEFT: Western Astrology Chart ──
    echo '<div class="postbox" style="margin:0"><div class="inside">';
    echo '<h3 style="margin-top:0;color:#3b82f6">🌟 Western Astrology <small style="font-weight:400;color:#888">(Tropical — Placidus)</small></h3>';
    if ($has_western) {
      $chart_url = $astro_row['chart_svg'] ?? $astro_summary['chart_url'] ?? '';
      if ($chart_url) {
        echo '<div style="text-align:center;margin:12px 0">';
        echo '<img src="' . esc_url($chart_url) . '" alt="Western Natal Wheel Chart" style="max-width:100%;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,0.15)"/>';
        echo '<p class="description" style="margin-top:6px">Natal Wheel — Placidus (Western Astrology)</p>';
        echo '</div>';
      }
      echo '<p style="color:#22c55e;font-weight:600">✅ Dữ liệu đã có</p>';
      echo '<p class="description">Fetched: ' . esc_html($astro_summary['fetched_at'] ?? '—') . '</p>';
      // LLM report status
      $has_llm_western = !empty($astro_row['llm_report']);
      if ($has_llm_western) {
        $llm_data = json_decode($astro_row['llm_report'], true);
        $gen_count = is_array($llm_data['sections'] ?? null) ? count(array_filter($llm_data['sections'])) : 0;
        echo '<p style="color:#6366f1;font-size:12px">🤖 AI Report: ' . $gen_count . '/10 chương | ' . esc_html($llm_data['generated'] ?? '—') . '</p>';
      } else {
        echo '<p style="color:#9ca3af;font-size:12px">🤖 AI Report: Chưa tạo</p>';
      }
    } else {
      echo '<div style="padding:30px;text-align:center;color:#9ca3af;background:#f8fafc;border-radius:8px;margin:12px 0">';
      echo '<p style="font-size:32px;margin:0">🌟</p>';
      echo '<p style="margin:8px 0 0">Chưa tạo bản đồ Western Astrology</p>';
      echo '<p class="description">Bấm nút "🌟 Western Astrology" để tạo</p>';
      echo '</div>';
    }
    echo '</div></div>';
     // ══════════════ ASTROVIET CHARTS (from Free API data) ══════════════
    $positions = $astro_traits['positions'] ?? [];
    $houses_data = $astro_traits['houses'] ?? [];
    $houses_raw = [];
    if (!empty($houses_data)) {
      if (isset($houses_data[0]['House']) || isset($houses_data[0]['house'])) {
        $houses_raw = $houses_data;
      } elseif (isset($houses_data['Houses'])) {
        $houses_raw = $houses_data['Houses'];
      }
    }
    // ── RIGHT: Vedic / Indian Astrology Chart ──
    echo '<div class="postbox" style="margin:0"><div class="inside">';
    echo '<h3 style="margin-top:0;color:#7c3aed">🕉️ Natal Wheel Astrology <small style="font-weight:400;color:#888">(Sidereal — Indian)</small></h3>';
    #if ($has_vedic) {
      // Generate Vedic chart SVG from positions data (API doesn't provide chart images)
      $birth_data = is_array( $birth_data ?? null ) ? $birth_data : [];
      $astroviet_wheel_url = bccm_build_astroviet_wheel_url($positions, $houses_raw, $coachee_name, array_merge($birth_data, [
        'birth_place' => $astro_row['birth_place'] ?? '',
        'latitude'    => $astro_row['latitude'] ?? ($birth_data['latitude'] ?? 0),
        'longitude'   => $astro_row['longitude'] ?? ($birth_data['longitude'] ?? 0),
      ]));
      $astroviet_grid_url = bccm_build_astroviet_aspect_grid_url($positions, $houses_raw, $birth_data);
      if ($astroviet_wheel_url) {
          echo '<div class="bccm-astroviet-chart-item">';
          echo '<h4 style="margin:0 0 8px;font-size:14px;color:#1e293b">🔮 Natal Wheel Chart</h4>';
          echo '<div style="text-align:center">';
          echo '<img src="' . esc_url($astroviet_wheel_url) . '" alt="AstroViet Natal Wheel" style="max-width:100%;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)" loading="lazy"/>';
          echo '</div>';
          echo '</div>';
        }
      
      echo '<p style="color:#22c55e;font-weight:600">✅ Dữ liệu đã có</p>';
      echo '<p class="description">Fetched: ' . esc_html($vedic_summary['fetched_at'] ?? '—') . '</p>';
      // LLM report status
      $has_llm_vedic = !empty($vedic_row['llm_report']);
      if ($has_llm_vedic) {
        $llm_data = json_decode($vedic_row['llm_report'], true);
        $gen_count = is_array($llm_data['sections'] ?? null) ? count(array_filter($llm_data['sections'])) : 0;
        echo '<p style="color:#6366f1;font-size:12px">🕉️ AI Report: ' . $gen_count . '/10 chương | ' . esc_html($llm_data['generated'] ?? '—') . '</p>';
      } else {
        echo '<p style="color:#9ca3af;font-size:12px">🕉️ AI Report: Chưa tạo</p>';
      }
    

    echo '</div></div>';

    echo '</div>'; // grid

   

    // Also get Vedic positions & houses for comparison
    $vedic_positions  = $vedic_traits['positions'] ?? [];
    $vedic_houses_raw = $vedic_traits['houses'] ?? [];

    if (!empty($positions) && !empty($houses_raw)) {
      $birth_data = $astro_traits['birth_data'] ?? [];
      $coachee_name = $coachee['full_name'] ?? '';

      // Build AstroViet URLs
      
      // Native HTML aspect grid
      $aspects_raw = $astro_traits['aspects'] ?? [];
      $native_grid_html = bccm_render_aspect_grid_html($positions, $aspects_raw);

      if ($astroviet_wheel_url || $astroviet_grid_url || $native_grid_html) {
        echo '<div class="postbox" style="margin-top:16px"><div class="inside">';
        echo '<h3 style="margin-top:0">🗺️ Lưới Góc Chiếu (Aspect Grid) <small style="font-weight:400;color:#888">(AstroViet)</small></h3>';

        echo '<div class="bccm-astroviet-charts" style="display:flex;gap:20px;flex-wrap:wrap">';

        // AstroViet Natal Wheel
       

        // Aspect Grid (AstroViet image + Native HTML)
        echo '<div class="bccm-astroviet-chart-item">';


        if ($astroviet_grid_url) {
          echo '<div style="text-align:center">';
          echo '<img src="' . esc_url($astroviet_grid_url) . '" alt="AstroViet Aspect Grid" style="max-width:100%;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)" loading="lazy"/>';
          #echo '<p class="description" style="margin-top:6px;font-size:11px">Aspect Grid — AstroViet</p>';
          echo '</div>';
        }

        echo '</div>'; // .bccm-astroviet-chart-item
        echo '</div>'; // .bccm-astroviet-charts

        echo '</div></div>'; // .postbox
      }
    }

    if (!empty($positions)) {
      echo '<div class="postbox" style="margin-top:16px"><div class="inside">';
      echo '<h3 style="margin-top:0">🪐 Vị Trí Các Hành Tinh</h3>';
      echo '<table class="widefat bccm-natal-table"><thead><tr>';
      echo '<th style="width:28%">Hành tinh</th>';
      echo '<th style="width:18%">Cung</th>';
      echo '<th style="width:22%">Vị trí</th>';
      echo '<th style="width:12%">Nhà</th>';
      echo '<th style="width:10%">Nghịch hành</th>';
      echo '</tr></thead><tbody>';

      $planet_order = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Chiron','Lilith','True Node','Mean Node','Ascendant','Descendant','MC','IC','Ceres','Vesta','Juno','Pallas'];
      $planet_symbols = [
        'Sun' => '☉', 'Moon' => '☽', 'Mercury' => '☿', 'Venus' => '♀', 'Mars' => '♂',
        'Jupiter' => '♃', 'Saturn' => '♄', 'Uranus' => '♅', 'Neptune' => '♆', 'Pluto' => '♇',
        'Chiron' => '⚷', 'Lilith' => '⚸', 'True Node' => '☊', 'Mean Node' => '☊',
        'Ascendant' => 'ASC', 'Descendant' => 'DSC', 'MC' => 'MC', 'IC' => 'IC',
        'Ceres' => '⚳', 'Vesta' => '⚶', 'Juno' => '⚵', 'Pallas' => '⚴',
      ];

      $row_idx = 0;
      foreach ($planet_order as $pname) {
        if (!isset($positions[$pname])) continue;
        $p = $positions[$pname];
        $symbol = $planet_symbols[$pname] ?? '';
        $dms = bccm_astro_decimal_to_dms($p['norm_degree'] ?? 0);
        $house_num = '';
        if (!empty($houses_raw) && !in_array($pname, ['Ascendant','Descendant','MC','IC'])) {
          $h = bccm_astro_planet_in_house($p['full_degree'] ?? 0, $houses_raw);
          $house_num = $h > 0 ? $h : '';
        }
        $row_class = ($row_idx % 2 === 0) ? 'bccm-row-even' : 'bccm-row-odd';
        // Separator before outer planets
        if ($pname === 'Uranus' && $row_idx > 0) {
          echo '<tr class="bccm-table-separator"><td colspan="5" style="background:#e5e7eb;height:2px;padding:0"></td></tr>';
        }
        // Separator before asteroids
        if ($pname === 'Chiron' && $row_idx > 0) {
          echo '<tr class="bccm-table-separator"><td colspan="5" style="background:#e5e7eb;height:2px;padding:0"></td></tr>';
        }
        // Separator before angles
        if ($pname === 'Ascendant' && $row_idx > 0) {
          echo '<tr class="bccm-table-separator"><td colspan="5" style="background:#e5e7eb;height:2px;padding:0"></td></tr>';
        }

        echo '<tr class="' . $row_class . '">';
        echo '<td><span style="font-size:16px;margin-right:6px;vertical-align:middle">' . $symbol . '</span><strong>' . esc_html($p['planet_vi'] ?? $pname) . '</strong></td>';
        echo '<td><span style="font-size:16px;vertical-align:middle">' . esc_html($p['sign_symbol'] ?? '') . '</span> ' . esc_html($p['sign_vi'] ?? '') . '</td>';
        echo '<td style="font-family:\'Courier New\',monospace;font-size:13px">' . esc_html($dms) . '</td>';
        echo '<td style="text-align:center;font-weight:600;color:#6366f1">' . ($house_num ? esc_html($house_num) : '—') . '</td>';
        echo '<td style="text-align:center">' . ($p['is_retro'] ? '<span style="color:#ef4444;font-weight:700" title="Nghịch hành">℞</span>' : '<span style="color:#d1d5db">—</span>') . '</td>';
        echo '</tr>';
        $row_idx++;
      }
      echo '</tbody></table>';
      echo '<p class="description" style="margin-top:4px;font-size:11px;color:#3b82f6">Nguồn: Freeze Astrology API — freeastrologyapi.com</p>';
      echo '</div></div>';
    }


    // ══════════════ HOUSES TABLE (Professional) ══════════════
    if (!empty($houses_raw)) {
      $signs = bccm_zodiac_signs();
      $house_meanings = function_exists('bccm_house_meanings_vi') ? bccm_house_meanings_vi() : [];

      echo '<div class="postbox" style="margin-top:16px"><div class="inside">';
      echo '<h3 style="margin-top:0">🏛️ Vị Trí 12 Cung Nhà <small style="font-weight:400;color:#888">(Hệ thống Placidus)</small></h3>';
      echo '<table class="widefat bccm-natal-table"><thead><tr>';
      echo '<th style="width:12%">Nhà</th>';
      echo '<th style="width:18%">Cung</th>';
      echo '<th style="width:22%">Đỉnh cung</th>';
      echo '<th>Ý nghĩa</th>';
      echo '</tr></thead><tbody>';

      foreach ($houses_raw as $h) {
        $num = $h['House'] ?? ($h['house'] ?? 0);
        if ($num < 1) continue;
        $sign_num = $h['zodiac_sign']['number'] ?? 0;
        $sign_vi = $signs[$sign_num]['vi'] ?? '';
        $symbol = $signs[$sign_num]['symbol'] ?? '';
        $norm_deg = $h['normDegree'] ?? ($h['degree'] ?? 0);
        $dms = bccm_astro_decimal_to_dms($norm_deg);
        $meaning = $house_meanings[$num] ?? '';

        $angular = in_array($num, [1,4,7,10]);
        $row_style = $angular ? 'background:#f0f4ff;font-weight:500' : '';

        echo '<tr style="' . $row_style . '">';
        echo '<td style="text-align:center"><strong style="color:#6366f1;font-size:15px">' . intval($num) . '</strong></td>';
        echo '<td><span style="font-size:16px;vertical-align:middle">' . esc_html($symbol) . '</span> ' . esc_html($sign_vi) . '</td>';
        echo '<td style="font-family:\'Courier New\',monospace;font-size:13px">' . esc_html($dms) . '</td>';
        echo '<td style="color:#6b7280;font-size:12px">' . esc_html($meaning) . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
      echo '<p class="description" style="margin-top:4px;font-size:11px;color:#3b82f6">Nguồn: Freeze Astrology API — freeastrologyapi.com</p>';
      echo '</div></div>';
    }


    // ══════════════ ASPECTS TABLE (Grouped, with Orb) ══════════════
    $aspects = $astro_traits['aspects'] ?? [];
    if (!empty($aspects) && !empty($positions)) {
      $enriched = bccm_astro_enrich_aspects($aspects, $positions);
      $grouped  = bccm_astro_group_aspects_by_planet($enriched);
      $planet_vi = function_exists('bccm_planet_names_vi') ? bccm_planet_names_vi() : [];
      $aspect_vi = function_exists('bccm_aspect_names_vi') ? bccm_aspect_names_vi() : [];
      $aspect_symbols = function_exists('bccm_aspect_symbols') ? bccm_aspect_symbols() : [];
      $aspect_colors  = function_exists('bccm_aspect_colors') ? bccm_aspect_colors() : [];

      echo '<div class="postbox" style="margin-top:16px"><div class="inside">';
      echo '<h3 style="margin-top:0">🔗 Góc Chiếu Giữa Các Hành Tinh <small style="font-weight:400;color:#888">(Aspects)</small></h3>';
      echo '<p class="description" style="margin-bottom:12px">Tổng cộng ' . count($enriched) . ' góc chiếu, nhóm theo hành tinh đầu tiên. Orb được tính từ fullDegree.</p>';

      // Aspect legend
      echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;padding:10px;background:#f9fafb;border-radius:8px;font-size:11px">';
      foreach ($aspect_vi as $aen => $avi) {
        $c = $aspect_colors[$aen] ?? '#888';
        $s = $aspect_symbols[$aen] ?? '';
        echo '<span style="display:inline-flex;align-items:center;gap:3px"><span style="color:' . $c . ';font-weight:700">' . $s . '</span> ' . esc_html($avi) . '</span>';
      }
      echo '</div>';

      echo '<table class="widefat bccm-natal-table bccm-aspects-full"><thead><tr>';
      echo '<th style="width:22%">Hành tinh 1</th>';
      echo '<th style="width:8%;text-align:center"></th>';
      echo '<th style="width:22%">Góc chiếu</th>';
      echo '<th style="width:22%">Hành tinh 2</th>';
      echo '<th style="width:16%">Orb</th>';
      echo '</tr></thead><tbody>';

      foreach ($grouped as $planet_key => $planet_aspects) {
        // Group header
        $pvi = $planet_vi[$planet_key] ?? $planet_key;
        echo '<tr class="bccm-aspect-group-header"><td colspan="5"><strong style="color:#1e293b">' . esc_html($pvi) . '</strong> <span style="color:#9ca3af;font-weight:400">(' . count($planet_aspects) . ' góc chiếu)</span></td></tr>';

        foreach ($planet_aspects as $asp) {
          $type_en = $asp['aspect_en'];
          $type_vi = $aspect_vi[$type_en] ?? $type_en;
          $p2_vi   = $planet_vi[$asp['planet_2_en']] ?? $asp['planet_2_en'];
          $sym     = $aspect_symbols[$type_en] ?? '';
          $color   = $aspect_colors[$type_en] ?? '#888';
          $orb_val = $asp['orb'];
          $orb_display = $orb_val !== null ? bccm_astro_decimal_to_dms($orb_val, true) : '—';
          // Exact aspect highlight
          $orb_style = '';
          if ($orb_val !== null && $orb_val < 1) {
            $orb_style = 'color:#059669;font-weight:700';
          } elseif ($orb_val !== null && $orb_val < 3) {
            $orb_style = 'color:#2563eb';
          }

          echo '<tr>';
          echo '<td style="padding-left:24px;color:#6b7280">' . esc_html($planet_vi[$asp['planet_1_en']] ?? $asp['planet_1_en']) . '</td>';
          echo '<td style="text-align:center;font-size:16px;color:' . $color . '" title="' . esc_attr($type_en) . '">' . $sym . '</td>';
          echo '<td style="color:' . $color . ';font-weight:500">' . esc_html($type_vi) . '</td>';
          echo '<td>' . esc_html($p2_vi) . '</td>';
          echo '<td style="font-family:\'Courier New\',monospace;font-size:12px;' . $orb_style . '">' . esc_html($orb_display) . '</td>';
          echo '</tr>';
        }
      }
      echo '</tbody></table>';

      // Aspect statistics
      $stats = [];
      foreach ($enriched as $asp) {
        $type = $asp['aspect_en'];
        if (!isset($stats[$type])) $stats[$type] = 0;
        $stats[$type]++;
      }
      echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">';
      foreach ($stats as $type => $count) {
        $c = $aspect_colors[$type] ?? '#888';
        $s = $aspect_symbols[$type] ?? '';
        echo '<span style="display:inline-flex;align-items:center;gap:3px;padding:3px 10px;background:#f9fafb;border-radius:99px;font-size:12px;border:1px solid #e5e7eb">';
        echo '<span style="color:' . $c . ';font-weight:700">' . $s . '</span> ';
        echo '<span style="color:#6b7280">' . esc_html($aspect_vi[$type] ?? $type) . '</span> ';
        echo '<strong>' . $count . '</strong>';
        echo '</span>';
      }
      echo '</div>';

      echo '</div></div>';
    }

    // ══════════════ VEDIC ASTROLOGY TABLES (Graha, Navamsa) ══════════════
    $vedic_positions = $vedic_traits['positions'] ?? [];
    $vedic_navamsa   = $vedic_traits['navamsa'] ?? [];
    
    if (!empty($vedic_positions) && $has_vedic) {
      echo '<div class="postbox" style="margin-top:16px;border-left:4px solid #7c3aed"><div class="inside">';
      echo '<h3 style="margin-top:0;color:#7c3aed">🕉️ Vị Trí Các Hành Tinh (Vedic) <small style="font-weight:400;color:#888">(Grahas in Rashi)</small></h3>';
      echo '<table class="widefat bccm-natal-table"><thead><tr>';
      echo '<th style="width:28%">Graha (Hành tinh)</th>';
      echo '<th style="width:22%">Rashi (Cung)</th>';
      echo '<th style="width:20%">Vị trí</th>';
      echo '<th style="width:16%">Chúa cung</th>';
      echo '<th style="width:14%">Nghịch hành</th>';
      echo '</tr></thead><tbody>';

      // Planet order with Rahu and Ketu
      $vedic_planet_order = ['Sun','Moon','Mars','Mercury','Jupiter','Venus','Saturn','Rahu','Ketu','Ascendant'];
      $vedic_planet_symbols = [
        'Sun' => '☉', 'Moon' => '☽', 'Mars' => '♂', 'Mercury' => '☿', 'Jupiter' => '♃',
        'Venus' => '♀', 'Saturn' => '♄', 'Rahu' => '☊', 'Ketu' => '☋', 'Ascendant' => 'ASC',
      ];

      $row_idx = 0;
      foreach ($vedic_planet_order as $pname) {
        if (!isset($vedic_positions[$pname])) continue;
        $p = $vedic_positions[$pname];
        $symbol = $vedic_planet_symbols[$pname] ?? '';
        $dms = bccm_astro_decimal_to_dms($p['norm_degree'] ?? 0);
        $sanskrit = $p['sign_sanskrit'] ?? '';
        $sign_lord = $p['sign_lord'] ?? '';
        
        $row_class = ($row_idx % 2 === 0) ? 'bccm-row-even' : 'bccm-row-odd';
        
        // Separator before Rahu/Ketu
        if ($pname === 'Rahu' && $row_idx > 0) {
          echo '<tr class="bccm-table-separator"><td colspan="5" style="background:#e5e7eb;height:2px;padding:0"></td></tr>';
        }
        // Separator before Ascendant
        if ($pname === 'Ascendant' && $row_idx > 0) {
          echo '<tr class="bccm-table-separator"><td colspan="5" style="background:#e5e7eb;height:2px;padding:0"></td></tr>';
        }

        echo '<tr class="' . $row_class . '">';
        echo '<td><span style="font-size:16px;margin-right:6px;vertical-align:middle">' . $symbol . '</span><strong>' . esc_html($p['planet_vi'] ?? $pname) . '</strong>';
        if ($sanskrit && $pname !== 'Ascendant') {
          echo ' <small style="color:#9ca3af">(' . esc_html($sanskrit) . ')</small>';
        }
        echo '</td>';
        echo '<td><span style="font-size:16px;vertical-align:middle">' . esc_html($p['sign_symbol'] ?? '') . '</span> ' . esc_html($p['sign_vi'] ?? '');
        if (!empty($p['sign_sanskrit'])) {
          echo ' <small style="color:#7c3aed;font-weight:500">(' . esc_html($p['sign_sanskrit']) . ')</small>';
        }
        echo '</td>';
        echo '<td style="font-family:\'Courier New\',monospace;font-size:13px">' . esc_html($dms) . '</td>';
        echo '<td style="color:#6366f1;font-size:12px">' . ($sign_lord ? esc_html($sign_lord) : '—') . '</td>';
        echo '<td style="text-align:center">' . ($p['is_retro'] ? '<span style="color:#ef4444;font-weight:700" title="Nghịch hành">℞</span>' : '<span style="color:#d1d5db">—</span>') . '</td>';
        echo '</tr>';
        $row_idx++;
      }
      echo '</tbody></table>';
      echo '<p class="description" style="margin-top:4px;font-size:11px;color:#7c3aed">Nguồn: Freeze Astrology API (Vedic/Jyotish) — Hệ thống Lahiri Ayanamsha</p>';
      echo '</div></div>';

      // ══════════════ NAVAMSA CHART (D9) ══════════════
      $navamsa_chart_url = $vedic_summary['navamsa_chart_url'] ?? '';
      if ($navamsa_chart_url) {
        echo '<div class="postbox" style="margin-top:16px;border-left:4px solid #7c3aed"><div class="inside">';
        echo '<h3 style="margin-top:0;color:#7c3aed">💍 Navamsa Chart (D9) <small style="font-weight:400;color:#888">(Hôn nhân & Dharma)</small></h3>';
        echo '<div style="text-align:center;margin:12px 0">';
        echo '<img src="' . esc_url($navamsa_chart_url) . '" alt="Navamsa Chart (D9)" style="max-width:100%;border-radius:12px;box-shadow:0 4px 16px rgba(124,58,237,0.2)" loading="lazy"/>';
        echo '<p class="description" style="margin-top:8px">Navamsa (D9) — Divisional chart for marriage, relationships & dharma</p>';
        echo '</div>';
        
        // Navamsa positions table (if available)
        if (!empty($vedic_navamsa) && is_array($vedic_navamsa)) {
          echo '<h4 style="margin:16px 0 8px;color:#6b7280;font-size:14px">📊 Vị Trí Các Hành Tinh Trong Navamsa</h4>';
          echo '<table class="widefat bccm-natal-table" style="font-size:12px"><thead><tr>';
          echo '<th>Graha</th>';
          echo '<th>Rashi (D9)</th>';
          echo '<th>Độ</th>';
          echo '</tr></thead><tbody>';
          
          $row_idx = 0;
          foreach ($vedic_planet_order as $pname) {
            if (!isset($vedic_navamsa[$pname])) continue;
            $np = $vedic_navamsa[$pname];
            $sign_num = intval($np['current_sign'] ?? 0);
            $rashi_signs = bccm_vedic_rashi_signs();
            $sign_info = $rashi_signs[$sign_num] ?? ['vi' => '?', 'symbol' => '?', 'sanskrit' => '?'];
            $norm_deg = floatval($np['normDegree'] ?? 0);
            $dms = bccm_astro_decimal_to_dms($norm_deg);
            $symbol = $vedic_planet_symbols[$pname] ?? '';
            $planet_vi_names = bccm_vedic_planet_names_vi();
            $planet_vi = $planet_vi_names[$pname] ?? $pname;
            
            $row_class = ($row_idx % 2 === 0) ? 'bccm-row-even' : 'bccm-row-odd';
            
            echo '<tr class="' . $row_class . '">';
            echo '<td><span style="font-size:14px;margin-right:4px">' . $symbol . '</span>' . esc_html($planet_vi) . '</td>';
            echo '<td><span style="font-size:14px">' . esc_html($sign_info['symbol']) . '</span> ' . esc_html($sign_info['vi']) . ' <small style="color:#7c3aed">(' . esc_html($sign_info['sanskrit']) . ')</small></td>';
            echo '<td style="font-family:\'Courier New\',monospace;font-size:11px">' . esc_html($dms) . '</td>';
            echo '</tr>';
            $row_idx++;
          }
          echo '</tbody></table>';
        }
        
        echo '</div></div>';
      }
    }

    // ══════════════ CHART PATTERNS (Astro-Charts style) ══════════════
    if (!empty($aspects) && !empty($positions)) {
      $chart_patterns = bccm_detect_chart_patterns($positions, $aspects);
      if (!empty($chart_patterns)) {
        echo '<div class="postbox" style="margin-top:16px"><div class="inside">';
        echo '<h3 style="margin-top:0">🔷 Mô Hình Bản Đồ <small style="font-weight:400;color:#888">(Chart Patterns)</small></h3>';
        echo '<p class="description" style="margin-bottom:14px">Các mô hình hình học đặc biệt giữa các hành tinh, cho thấy cấu trúc tổng thể của bản đồ.</p>';

        echo '<div class="bccm-patterns-grid">';
        foreach ($chart_patterns as $pattern) {
          $planet_vi_names = bccm_planet_names_vi();
          $planet_list = [];
          foreach ($pattern['planets'] as $pn) {
            $pvi = $planet_vi_names[$pn] ?? $pn;
            $sign_vi_p = $positions[$pn]['sign_vi'] ?? '';
            $norm_deg_p = $positions[$pn]['norm_degree'] ?? 0;
            $deg_str = floor($norm_deg_p) . '°';
            $planet_list[] = "$pvi trong $deg_str $sign_vi_p";
          }

          echo '<div class="bccm-pattern-card">';
          echo '<div class="bccm-pattern-header">';
          echo '<span class="bccm-pattern-icon">' . ($pattern['icon'] ?? '🔷') . '</span>';
          echo '<span class="bccm-pattern-type">' . esc_html($pattern['type_vi']) . '</span>';
          echo '</div>';
          echo '<div class="bccm-pattern-planets">';
          foreach ($planet_list as $pl) {
            echo '<div class="bccm-pattern-planet">' . esc_html($pl) . '</div>';
          }
          echo '</div>';
          echo '<div class="bccm-pattern-desc">' . esc_html($pattern['description']) . '</div>';
          echo '</div>';
        }
        echo '</div>';

        echo '</div></div>';
      }
    }

    // ══════════════ SPECIAL FEATURES (Astro-Charts style) ══════════════
    if (!empty($positions)) {
      $special_features = bccm_analyze_special_features(
        $positions,
        $aspects ?? [],
        $houses_raw ?? [],
        $astro_traits['birth_data'] ?? []
      );
      if (!empty($special_features)) {
        echo '<div class="postbox" style="margin-top:16px"><div class="inside">';
        echo '<h3 style="margin-top:0">✨ Đặc Điểm Nổi Bật <small style="font-weight:400;color:#888">(Special Features)</small></h3>';
        echo '<p class="description" style="margin-bottom:14px">Phân tích tổng quan về các đặc điểm nổi bật trong bản đồ sao.</p>';

        echo '<div class="bccm-special-grid">';
        foreach ($special_features as $feature) {
          echo '<div class="bccm-special-card">';
          echo '<div class="bccm-special-icon">' . ($feature['icon'] ?? '✨') . '</div>';
          echo '<div class="bccm-special-text">';
          echo '<p class="bccm-special-main">' . esc_html($feature['text']) . '</p>';
          if (!empty($feature['text_vi']) && $feature['text_vi'] !== $feature['text']) {
            echo '<p class="bccm-special-sub">' . esc_html($feature['text_vi']) . '</p>';
          }
          echo '</div>';
          echo '</div>';
        }
        echo '</div>';

        echo '</div></div>';
      }
    }

    $vedic_fetched = !empty($vedic_summary['fetched_at']) ? ' | Vedic: ' . esc_html($vedic_summary['fetched_at']) : '';
    echo '<p class="description" style="margin-top:8px">Western: ' . esc_html($astro_summary['fetched_at'] ?? '—') . $vedic_fetched . ' | Powered by Freeze Astrology API (Western + Vedic)</p>';
    echo '</div>'; // .bccm-natal-report
  }

  // Quick links → Step 2
  echo '<div style="margin-top:20px;padding:16px;background:#f0f4ff;border-radius:8px;border-left:4px solid #6366f1">';
  echo '<h3 style="margin-top:0">🚀 Bước tiếp theo</h3>';
  echo '<p style="margin-bottom:8px">Sau khi hoàn thành hồ sơ và tạo bản đồ chiêm tinh, hãy tiến tới Bước 2 để chọn Coach Template phù hợp.</p>';
  echo '<p>';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_step2_coach_template')) . '" class="button button-primary button-hero">📋 Bước 2: Chọn Coach Template →</a> ';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_step3_character')) . '" class="button">🤖 Bước 3: Tạo Character</a> ';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_step4_success_plan')) . '" class="button">🎯 Bước 4: Success Plan</a>';
  echo '</p>';
  echo '</div>';

  // ── Geo lookup JS ──
  ?>
  <script>
  (function(){
    var btn = document.getElementById('bccm_geo_lookup_btn');
    if (!btn) return;
    btn.addEventListener('click', function(){
      var place = document.getElementById('bccm_birth_place').value.trim();
      if (!place) { alert('Nhập nơi sinh trước'); return; }
      var status = document.getElementById('bccm_geo_status');
      status.textContent = 'Đang tìm…';
      fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(place))
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (data && data[0]) {
            document.getElementById('bccm_astro_lat').value = parseFloat(data[0].lat).toFixed(7);
            document.getElementById('bccm_astro_lng').value = parseFloat(data[0].lon).toFixed(7);
            status.textContent = '✅ ' + data[0].display_name;
          } else {
            status.textContent = '❌ Không tìm thấy';
          }
        })
        .catch(function(){ status.textContent = '❌ Lỗi kết nối'; });
    });
  })();
  </script>
  <?php

  echo '</div>'; // .wrap
}
