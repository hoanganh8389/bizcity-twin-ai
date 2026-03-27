<?php // includes/admin-pages.php
if (!defined('ABSPATH')) exit;

/* =========================== ADMIN MENU =========================== */
function bccm_admin_menu(){
  // Main menu: Bản đồ chủ nhân (admin's own journey)
  add_menu_page(
    'Bản đồ chủ nhân',
    'Bản đồ chủ nhân',
    'edit_posts',
    'bccm_root',
    'bccm_admin_dashboard',
    plugins_url('assets/icon/nobi_16.png', dirname(__FILE__)),
    26
  );

  // === WORKFLOW STEPS (admin's journey) ===
  // Dashboard shows as first item automatically via parent callback

  // Bước 1: Hồ sơ & Chiêm tinh → registered in admin-self-profile.php
  // Bước 2: Coach Template     → registered in admin-step2-coach-template.php
  // Bước 3: Tạo Character      → registered in admin-step3-character.php
  // Bước 4: Success Plan       → registered in admin-step4-success-plan.php
  // Life Map Plan              → registered in admin-lifemap.php
  // Nhắc nhở & AI             → registered in admin-lifemap.php

  // === BẢN ĐỒ NGƯỜI KHÁC (manage other coachees) ===
  #add_submenu_page('bccm_root', 'Thêm Coachees', 'Bản đồ người khác', 'manage_options', 'bccm_coachees', 'bccm_admin_coachees', 30);
  #add_submenu_page('bccm_root', 'Danh sách Coachees', '  └ Danh sách', 'manage_options', 'bccm_coachees_list', 'bccm_admin_coachees_list', 31);

  // === CÀI ĐẶT (tabbed: Tổng quan | Câu hỏi | Bản đồ cuộc đời) ===
  add_submenu_page('bccm_root', 'Cài đặt', 'Cài đặt', 'manage_options', 'bccm_settings', 'bccm_admin_settings', 50);

  // Hidden pages (no menu label)
  add_submenu_page(
    null,
    'Coachee • Sửa bản đồ',
    'Coachee • Sửa bản đồ',
    'manage_options',
    'bccm_ai_json_editor',
    'bccm_admin_ai_json_editor'
  );
}

add_action('admin_menu','bccm_admin_menu');


/* =========================================================
 * Helpers: 21 keys + fallback + AI callers (parser phòng thủ)
 * =======================================================*/
function bccm_metric_21_keys(){
  return [
    'life_path','balance','mission','link_life_mission',
    'soul_number','birth_day','personality','link_soul_personality',
    'maturity','attitude','missing','lesson',
    'logic','subconscious_power','passion',
    'personal_year','personal_month','pinnacle',
    'personal_day','generation','challenges',
  ];
}
function bccm_brief_7($txt){
  $txt = trim((string)$txt);
  if ($txt==='') return '—';
  $w = preg_split('/\s+/u', $txt, -1, PREG_SPLIT_NO_EMPTY);
  return (count($w)<=7)? $txt : implode(' ', array_slice($w,0,7));
}

/* Fallback khi AI lỗi: luôn trả đủ 21 key với value/brief */
function bccm_build_metrics_fallback($profile){
  $life = function_exists('bccm_numerology_life_path')   ? (string) bccm_numerology_life_path($profile['dob'] ?? '')        : '';
  $soul = function_exists('bccm_numerology_name_number') ? (string) bccm_numerology_name_number($profile['full_name'] ?? ''): '';
  $mk = function($v,$b){ return ['value'=>($v!==''?$v:'—'),'brief'=>bccm_brief_7($b)]; };
  $map = [
    'life_path'             => $mk($life,'Định hướng cuộc đời'),
    'balance'               => $mk('','Cân đối cảm xúc'),
    'mission'               => $mk('','Giá trị cốt lõi'),
    'link_life_mission'     => $mk('','Gắn kết mục tiêu'),
    'soul_number'           => $mk($soul,'Động lực nội tại'),
    'birth_day'             => $mk('','Ngày sinh'),
    'personality'           => $mk('','Ấn tượng ban đầu'),
    'link_soul_personality' => $mk('','Trong ngoài hài hòa'),
    'maturity'              => $mk('','Độ chín cuộc đời'),
    'attitude'              => $mk('','Thái độ sống'),
    'missing'               => $mk('','Thiếu cần bù'),
    'lesson'                => $mk('','Bài học chính'),
    'logic'                 => $mk('','Tư duy lý trí'),
    'subconscious_power'    => $mk('','Sức mạnh tiềm thức'),
    'passion'               => $mk('','Đam mê cốt lõi'),
    'personal_year'         => $mk('','Chu kỳ năm'),
    'personal_month'        => $mk('','Chu kỳ tháng'),
    'pinnacle'              => $mk('','Đỉnh vận'),
    'personal_day'          => $mk('','Chu kỳ ngày'),
    'generation'            => $mk('','Ảnh hưởng thế hệ'),
    'challenges'            => $mk('','Thử thách chính'),
  ];
  return $map;
}

/* ---------- JSON substring extractor (last resort) ---------- */
// Đếm ngoặc để cắt 1 JSON object hoàn chỉnh kể từ $startPos
function bccm_json_grab_balanced_object($s, $startPos){
  $len = strlen($s);
  $depth = 0; $inStr = false; $esc = false;
  for ($i = $startPos; $i < $len; $i++){
    $ch = $s[$i];

    if ($inStr){
      if ($esc) { $esc = false; continue; }
      if ($ch === '\\') { $esc = true; continue; }
      if ($ch === '"')  { $inStr = false; continue; }
      continue;
    }

    if ($ch === '"'){ $inStr = true; continue; }
    if ($ch === '{'){ $depth++; continue; }
    if ($ch === '}'){ $depth--; if ($depth===0) return substr($s, $startPos, $i-$startPos+1); }
  }
  return null; // không cân bằng
}

function bccm_extract_numbers_full_substring($raw){
  if (!is_string($raw) || $raw==='') return null;
  // loại bỏ BOM/zero-width
  $s = preg_replace('/^\xEF\xBB\xBF/u','',$raw);
  $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u','',$s);

  $needle = '{"numbers_full":';
  $pos = strpos($s, $needle);
  if ($pos === false) return null;

  // lùi tới '{' mở bao ngoài
  $start = $pos - 1;
  while ($start >= 0 && $s[$start] !== '{') $start--;
  if ($start < 0) $start = $pos;

  $json = bccm_json_grab_balanced_object($s, $start);
  if (!$json) return null;

  $data = json_decode($json, true);
  if (is_array($data) && isset($data['numbers_full']) && is_array($data['numbers_full'])){
    return $data['numbers_full'];
  }
  return null;
}




/* =========================== SETTINGS PAGE (Tabbed) =========================== */
function bccm_admin_settings(){
  if (!current_user_can('manage_options')) return;

  // Active tab
  $tab = sanitize_text_field($_GET['tab'] ?? 'overview');
  $tabs = [
    'overview'  => 'Tổng quan',
    'astrology' => '🌟 Chiêm tinh',
    'questions' => 'Câu hỏi',
    'plan'      => 'Bản đồ cuộc đời',
  ];

  echo '<div class="wrap bccm-wrap">';
  echo '<h1>Cài đặt</h1>';

  // Tab navigation
  echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
  foreach ($tabs as $t_key => $t_label) {
    $url   = admin_url('admin.php?page=bccm_settings&tab=' . $t_key);
    $class = ($tab === $t_key) ? 'nav-tab nav-tab-active' : 'nav-tab';
    echo '<a href="' . esc_url($url) . '" class="' . $class . '">' . esc_html($t_label) . '</a>';
  }
  echo '</nav>';

  // Render active tab
  switch ($tab) {
    case 'questions':
      bccm_settings_tab_questions();
      break;
    case 'plan':
      bccm_settings_tab_plan();
      break;
    case 'astrology':
      bccm_settings_tab_astrology();
      break;
    default:
      bccm_settings_tab_overview();
      break;
  }

  echo '</div>'; // .wrap
}

/* ---------- Tab: Tổng quan (API keys + coach types) ---------- */
function bccm_settings_tab_overview(){
  $api    = get_option('bccm_openai_api_key','');
  $proxy  = get_option('bizgpt_api','');
  $model  = get_option('bizgpt_model','gpt-4.1-nano');
  $astro_key = get_option('bccm_astro_api_key','');

  $pk_client_id     = get_option('bccm_prokerala_client_id','');
  $pk_client_secret = get_option('bccm_prokerala_client_secret','');

  if (!empty($_POST['bccm_save_settings']) && check_admin_referer('bccm_save_settings')){
    update_option('bizgpt_api', sanitize_text_field($_POST['bizgpt_api'] ?? ''));
    update_option('bizgpt_model', sanitize_text_field($_POST['bizgpt_model'] ?? 'gpt-4.1-nano'));
    update_option('bccm_openai_api_key', sanitize_text_field($_POST['api_key'] ?? ''));
    update_option('bccm_astro_api_key', sanitize_text_field($_POST['astro_api_key'] ?? ''));
    update_option('bccm_prokerala_client_id', sanitize_text_field($_POST['prokerala_client_id'] ?? ''));
    update_option('bccm_prokerala_client_secret', sanitize_text_field($_POST['prokerala_client_secret'] ?? ''));
    echo '<div class="updated"><p>Saved.</p></div>';
    $api   = get_option('bccm_openai_api_key','');
    $proxy = get_option('bizgpt_api','');
    $model = get_option('bizgpt_model','gpt-4.1-nano');
    $astro_key = get_option('bccm_astro_api_key','');
    $pk_client_id     = get_option('bccm_prokerala_client_id','');
    $pk_client_secret = get_option('bccm_prokerala_client_secret','');
  }

  echo '<form method="post">'; wp_nonce_field('bccm_save_settings');
  echo '<h2>API Settings</h2>';
  echo '<table class="form-table">';
  echo '<tr><th>BizGPT Proxy Token</th><td><input type="password" name="bizgpt_api" value="'.esc_attr($proxy).'" class="regular-text"/></td></tr>';
  echo '<tr><th>BizGPT Model</th><td><input type="text" name="bizgpt_model" value="'.esc_attr($model).'" class="regular-text"/></td></tr>';
  echo '<tr><th>OpenAI API Key (fallback)</th><td><input type="password" name="api_key" value="'.esc_attr($api).'" class="regular-text"/></td></tr>';
  echo '</table>';

  echo '<h2>🌟 Astrology API (Free Astrology API)</h2>';
  // Show network key status
  $network_astro_key_ov = is_multisite() ? get_site_option('bccm_network_astro_api_key', '') : '';
  echo '<table class="form-table">';
  echo '<tr><th>API Key (site này)</th><td>';
  echo '<input type="password" name="astro_api_key" value="'.esc_attr($astro_key).'" class="regular-text" autocomplete="new-password" placeholder="Để trống = dùng Network key"/>';
  echo '<p class="description">Đăng ký tại <a href="https://freeastrologyapi.com/signup" target="_blank">freeastrologyapi.com</a> để lấy API key.</p>';
  if ($astro_key) {
    echo '<p class="description" style="color:#059669">✔ Site này có key riêng.</p>';
  } elseif ($network_astro_key_ov) {
    $masked_ov = function_exists('bccm_network_mask_key') ? bccm_network_mask_key($network_astro_key_ov) : substr($network_astro_key_ov,0,4).'****';
    echo '<p class="description" style="color:#2563eb">ℹ️ Đang dùng Network key: <code>'.esc_html($masked_ov).'</code>.</p>';
  } else {
    echo '<p class="description" style="color:#dc2626">✘ Chưa có API key nào.</p>';
  }
  echo '</td></tr>';
  echo '</table>';

  echo '<h2>🔮 Prokerala Astrology API v2 (Natal Chart)</h2>';
  echo '<table class="form-table">';
  echo '<tr><th>Client ID</th><td>';
  echo '<input type="text" name="prokerala_client_id" value="'.esc_attr($pk_client_id).'" class="regular-text"/>';
  echo '</td></tr>';
  echo '<tr><th>Client Secret</th><td>';
  echo '<input type="password" name="prokerala_client_secret" value="'.esc_attr($pk_client_secret).'" class="regular-text"/>';
  echo '<p class="description">Đăng ký tại <a href="https://api.prokerala.com" target="_blank">api.prokerala.com</a> → Clients → Tạo Web Application.</p>';
  echo '</td></tr>';
  echo '</table>';

  echo '<p><button class="button button-primary" name="bccm_save_settings" value="1">Save</button></p>';
  echo '</form>';

  // Add new coach type
  $types_base  = function_exists('bccm_coach_types') ? (array) bccm_coach_types() : [];
  $types_extra = (array) get_option('bccm_coach_types_extra', []);

  if (!empty($_POST['bccm_add_type']) && check_admin_referer('bccm_add_type')) {
    $slug  = sanitize_title($_POST['new_type_slug'] ?? '');
    $label = sanitize_text_field($_POST['new_type_label'] ?? '');
    if ($slug && $label && !isset($types_base[$slug])) {
      $types_extra[$slug] = ['label' => $label];
      update_option('bccm_coach_types_extra', $types_extra);
      echo '<div class="updated"><p>Coach type <b>'.esc_html($label).'</b> đã được thêm.</p></div>';
    }
  }

  echo '<hr/>';
  echo '<h2>Coach Types</h2>';
  echo '<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">';
  wp_nonce_field('bccm_add_type');
  echo '<input type="text" name="new_type_slug"  placeholder="slug (vd: tiktok_coach)" class="regular-text" />';
  echo '<input type="text" name="new_type_label" placeholder="Label (vd: TikTok Coach)" class="regular-text" />';
  echo '<button class="button" name="bccm_add_type" value="1">Thêm Type</button>';
  echo '</form>';
}

/* ---------- Tab: Chiêm tinh (Astrology Settings) ---------- */
function bccm_settings_tab_astrology(){
  $astro_key = get_option('bccm_astro_api_key','');
  $pk_client_id     = get_option('bccm_prokerala_client_id','');
  $pk_client_secret = get_option('bccm_prokerala_client_secret','');

  if (!empty($_POST['bccm_save_astro']) && check_admin_referer('bccm_save_astro')){
    update_option('bccm_astro_api_key', sanitize_text_field($_POST['astro_api_key'] ?? ''));
    update_option('bccm_prokerala_client_id', sanitize_text_field($_POST['prokerala_client_id'] ?? ''));
    update_option('bccm_prokerala_client_secret', sanitize_text_field($_POST['prokerala_client_secret'] ?? ''));
    $astro_key = get_option('bccm_astro_api_key','');
    $pk_client_id     = get_option('bccm_prokerala_client_id','');
    $pk_client_secret = get_option('bccm_prokerala_client_secret','');
    echo '<div class="updated"><p>Đã lưu cấu hình Astrology.</p></div>';
  }

  // Test Free Astrology API
  if (!empty($_POST['bccm_test_astro']) && check_admin_referer('bccm_save_astro')){
    $test_data = [
      'year' => 1989, 'month' => 3, 'day' => 8,
      'hour' => 8, 'minute' => 5, 'second' => 0,
      'latitude' => 21.0285, 'longitude' => 105.8542, 'timezone' => 7,
    ];
    $result = bccm_astro_get_planets($test_data);
    if (is_wp_error($result)) {
      echo '<div class="error"><p>❌ Free API Lỗi: ' . esc_html($result->get_error_message()) . '</p></div>';
    } else {
      $parsed = bccm_astro_parse_planets($result['planets'] ?? []);
      echo '<div class="updated"><p>✅ Free API hoạt động! Sun: ' . esc_html($parsed['sun_sign']) . ', Moon: ' . esc_html($parsed['moon_sign']) . ', ASC: ' . esc_html($parsed['ascendant_sign']) . '</p></div>';
    }
  }

  // Test Prokerala API
  if (!empty($_POST['bccm_test_prokerala']) && check_admin_referer('bccm_save_astro')){
    $test_data = [
      'year' => 1989, 'month' => 3, 'day' => 8,
      'hour' => 8, 'minute' => 5, 'second' => 0,
      'latitude' => 21.0285, 'longitude' => 105.8542, 'timezone' => 7,
    ];
    $pk_result = bccm_prokerala_get_natal_chart($test_data);
    if (is_wp_error($pk_result)) {
      echo '<div class="error"><p>❌ Prokerala Lỗi: ' . esc_html($pk_result->get_error_message()) . '</p></div>';
    } else {
      $svg_len = strlen($pk_result['chart_svg'] ?? '');
      echo '<div class="updated"><p>✅ Prokerala API hoạt động! SVG chart: ' . number_format($svg_len) . ' bytes</p></div>';
      if (!empty($pk_result['chart_svg'])) {
        echo '<div style="text-align:center;margin:12px 0;max-width:500px">' . $pk_result['chart_svg'] . '</div>';
      }
    }
  }

  // Network key info for display
  $network_astro_key = is_multisite() ? get_site_option('bccm_network_astro_api_key', '') : '';
  $effective_key     = $astro_key ?: $network_astro_key;

  echo '<form method="post">'; wp_nonce_field('bccm_save_astro');
  echo '<h2>🌟 Free Astrology API</h2>';
  echo '<p class="description">Dịch vụ tính toán bản đồ chiêm tinh Western. Đăng ký tại <a href="https://freeastrologyapi.com/signup" target="_blank">freeastrologyapi.com</a></p>';

  echo '<table class="form-table">';
  echo '<tr><th>API Key (site này)</th><td>';
  echo '<input type="password" name="astro_api_key" value="'.esc_attr($astro_key).'" class="regular-text" autocomplete="new-password" placeholder="Để trống = dùng Network key"/>';
  if ($astro_key) {
    echo '<p class="description" style="color:#059669">✔ Site này có key riêng — sẽ được ưu tiên sử dụng.</p>';
  } elseif ($network_astro_key) {
    $masked = (function($key) {
      $len = strlen($key);
      if ($len <= 8) return str_repeat('*', $len);
      return substr($key, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($key, -4);
    })($network_astro_key);
    echo '<p class="description" style="color:#2563eb">ℹ️ Đang dùng <strong>Network key</strong>: <code>'.esc_html($masked).'</code>. Nhập key riêng bên trên để override.</p>';
    if (current_user_can('manage_network')) {
      echo '<p class="description"><a href="'.esc_url(network_admin_url('admin.php?page=bccm_network_settings')).'">⚙️ Quản lý Network key</a></p>';
    }
  } else {
    echo '<p class="description" style="color:#dc2626">✘ Chưa có API key nào. Nhập key hoặc cấu hình Network key.</p>';
    if (current_user_can('manage_network')) {
      echo '<p class="description"><a href="'.esc_url(network_admin_url('admin.php?page=bccm_network_settings')).'">⚙️ Cấu hình Network key</a></p>';
    }
  }
  echo '</td></tr>';
  echo '</table>';

  echo '<h2>🔮 Prokerala Astrology API v2</h2>';
  echo '<p class="description">Dịch vụ tạo biểu đồ Natal Chart SVG chuyên nghiệp. Đăng ký tại <a href="https://api.prokerala.com" target="_blank">api.prokerala.com</a></p>';

  echo '<table class="form-table">';
  echo '<tr><th>Client ID</th><td>';
  echo '<input type="text" name="prokerala_client_id" value="'.esc_attr($pk_client_id).'" class="regular-text"/>';
  echo '</td></tr>';
  echo '<tr><th>Client Secret</th><td>';
  echo '<input type="password" name="prokerala_client_secret" value="'.esc_attr($pk_client_secret).'" class="regular-text"/>';
  echo '</td></tr>';
  echo '</table>';

  echo '<p style="display:flex;gap:8px">';
  echo '<button class="button button-primary" name="bccm_save_astro" value="1">💾 Lưu</button>';
  echo '<button class="button" name="bccm_test_astro" value="1">🧪 Test Free API (08/03/1989)</button>';
  echo '<button class="button" name="bccm_test_prokerala" value="1" style="background:#7c3aed;color:#fff;border-color:#6d28d9">🔮 Test Prokerala (08/03/1989)</button>';
  echo '</p>';
  echo '</form>';

  echo '<hr/>';
  echo '<h2>📋 Hướng dẫn sử dụng</h2>';
  echo '<div class="postbox" style="padding:16px">';
  echo '<h3>Shortcode: [bccm_astro_form]</h3>';
  echo '<p>Đặt shortcode này vào bất kỳ trang nào để hiển thị form khai báo chiêm tinh.</p>';
  echo '<h4>Tham số:</h4>';
  echo '<ul>';
  echo '<li><code>redirect</code> – URL redirect sau khi tạo bản đồ (mặc định: trang hiện tại)</li>';
  echo '<li><code>show_register="yes/no"</code> – Hiện/ẩn nút đăng ký tài khoản (mặc định: yes)</li>';
  echo '<li><code>coach_type</code> – Pre-select coach type (vd: biz_coach, mental_coach)</li>';
  echo '</ul>';
  echo '<h4>Flow hoạt động:</h4>';
  echo '<ol>';
  echo '<li>User nhập form ngày/giờ/nơi sinh → Gọi API → Hiển thị bản đồ chiêm tinh</li>';
  echo '<li>Dữ liệu được lưu vào transient (trước đăng ký) hoặc user_meta (đã đăng nhập)</li>';
  echo '<li>Sau khi đăng ký tài khoản → Tự động lấy transient → Lưu vào user_meta + coachee profile</li>';
  echo '<li>Sau khi tạo web mới trong multisite → Copy dữ liệu astro sang site mới</li>';
  echo '<li>AI agent sử dụng bản đồ chiêm tinh để coaching (biz, mental, health, tiktok...)</li>';
  echo '</ol>';
  echo '</div>';

  // Show existing astro data stats
  global $wpdb;
  $t_astro = $wpdb->prefix . 'bccm_astro';
  if ($wpdb->get_var("SHOW TABLES LIKE '$t_astro'") === $t_astro) {
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_astro");
    echo '<p style="margin-top:20px"><strong>📊 Thống kê:</strong> ' . $count . ' bản đồ chiêm tinh đã lưu.</p>';
  }
}

/* ---------- Tab: Câu hỏi (Templates – Questions) ---------- */
function bccm_settings_tab_questions(){
  global $wpdb; $t = bccm_tables();

  $types_base  = function_exists('bccm_coach_types') ? (array) bccm_coach_types() : [];
  $types_extra = (array) get_option('bccm_coach_types_extra', []);
  $types = $types_base + $types_extra;
  if (!isset($types['tiktok_coach'])) $types['tiktok_coach'] = ['label' => 'TikTok Coach'];

  $selected = (isset($_GET['type']) && isset($types[$_GET['type']])) ? $_GET['type'] : key($types);

  // Save
  if (!empty($_POST['bccm_save_template']) && check_admin_referer('bccm_save_template')){
    $qs_raw = $_POST['questions'] ?? [];
    $qs = array_map('sanitize_text_field', is_array($qs_raw) ? $qs_raw : []);
    $json = function_exists('bccm_safe_json') ? bccm_safe_json(array_values($qs)) : wp_json_encode(array_values($qs), JSON_UNESCAPED_UNICODE);
    $wpdb->replace($t['templates'], [
      'coach_type' => $selected,
      'title'      => 'Template Questions - '.$selected,
      'questions'  => $json,
      'updated_at' => current_time('mysql'),
    ], ['%s','%s','%s','%s']);
    echo '<div class="updated"><p>Saved.</p></div>';
  }

  // Load
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['templates']} WHERE coach_type=%s", $selected), ARRAY_A);
  $defaults = bccm_default_questions();
  $questions = $row && !empty($row['questions'])
    ? (json_decode($row['questions'], true) ?: [])
    : ($defaults[$selected] ?? array_fill(0,20,''));

  // Type selector
  echo '<form method="get" style="margin:0 0 12px 0">';
  echo '<input type="hidden" name="page" value="bccm_settings"/>';
  echo '<input type="hidden" name="tab" value="questions"/>';
  echo '<label><strong>Coach Type:</strong></label> ';
  echo '<select name="type" onchange="this.form.submit()">';
  foreach ($types as $slug => $cfg){
    $label = is_array($cfg) ? ($cfg['label'] ?? $slug) : (string)$cfg;
    echo '<option value="'.esc_attr($slug).'"'.selected($selected,$slug,false).'>'.esc_html($label).'</option>';
  }
  echo '</select>';
  echo '</form>';

  // Questions table
  echo '<form method="post">';
  echo '<input type="hidden" name="page" value="bccm_settings"/>';
  wp_nonce_field('bccm_save_template');
  echo '<table class="widefat striped"><thead><tr><th style="width:48px">#</th><th>Question</th></tr></thead><tbody>';
  for($i=0;$i<20;$i++){
    $val = $questions[$i] ?? '';
    echo '<tr><td>'.($i+1).'</td><td><input type="text" name="questions[]" value="'.esc_attr($val).'" style="width:100%"/></td></tr>';
  }
  echo '</tbody></table>';
  echo '<p><button class="button button-primary" name="bccm_save_template" value="1">Save Template</button></p>';
  echo '</form>';
}

/* ---------- Tab: Bản đồ cuộc đời (Templates – 90-Day Plan) ---------- */
function bccm_settings_tab_plan(){
  global $wpdb; $t = bccm_tables();
  $types = bccm_coach_types();

  $selected = (isset($_GET['type']) && isset($types[$_GET['type']]))
    ? sanitize_text_field($_GET['type'])
    : key($types);

  // Save
  if (!empty($_POST['bccm_save_plan_template']) && check_admin_referer('bccm_save_plan_template')){
    $title  = sanitize_text_field($_POST['title'] ?? '');
    $phases = $_POST['phases'] ?? [];
    $clean  = [];
    foreach ($phases as $i=>$p){
      $weeks = [];
      if (!empty($p['weeks']) && is_array($p['weeks'])){
        foreach ($p['weeks'] as $w){
          $weeks[] = [
            'title' => sanitize_text_field($w['title'] ?? ''),
            'goal'  => sanitize_textarea_field($w['goal'] ?? ''),
          ];
        }
      }
      $clean[] = [
        'key'   => sanitize_text_field($p['key'] ?? ''),
        'title' => sanitize_text_field($p['title'] ?? ''),
        'notes' => sanitize_textarea_field($p['notes'] ?? ''),
        'weeks' => $weeks,
      ];
    }
    $wpdb->replace($t['plan_templates'], [
      'coach_type' => $selected,
      'title'      => $title,
      'content'    => wp_json_encode(['phases'=>$clean], JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
    ], ['%s','%s','%s','%s']);
    echo '<div class="updated"><p>Saved.</p></div>';
  }

  $tpl = bccm_get_plan_tpl($selected);

  // Type selector
  echo '<form method="get" style="margin:0 0 12px 0">';
  echo '<input type="hidden" name="page" value="bccm_settings"/>';
  echo '<input type="hidden" name="tab" value="plan"/>';
  echo '<label><strong>Coach Type:</strong></label> ';
  echo '<select name="type" onchange="this.form.submit()">';
  foreach ($types as $slug => $cfg){
    $label = is_array($cfg) ? ($cfg['label'] ?? $slug) : (string)$cfg;
    echo '<option value="'.esc_attr($slug).'"'.selected($selected,$slug,false).'>'.esc_html($label).'</option>';
  }
  echo '</select>';
  echo '</form>';

  echo '<form method="post">'; wp_nonce_field('bccm_save_plan_template');
  echo '<p><label>Title</label><br/><input type="text" name="title" value="'.esc_attr($tpl['title']??'').'" style="width:100%"/></p>';

  foreach (($tpl['phases']??[]) as $i=>$p){
    echo '<fieldset style="border:1px solid #ddd;padding:14px;margin:14px 0">';
    echo '<legend>'.esc_html($p['title']).'</legend>';
    echo '<input type="hidden" name="phases['.$i.'][key]" value="'.esc_attr($p['key']).'"/>';
    echo '<p><label>Phase Title</label><br/><input type="text" name="phases['.$i.'][title]" value="'.esc_attr($p['title']).'" style="width:100%"/></p>';
    echo '<p><label>Notes</label><br/><textarea name="phases['.$i.'][notes]" rows="2" style="width:100%">'.esc_textarea($p['notes']).'</textarea></p>';

    echo '<h4>Tuần và mục tiêu</h4>';
    $weeks = $p['weeks'] ?? [];
    for ($w=0; $w<4; $w++){
      $wk = $weeks[$w] ?? ['title'=>'Tuần '.($w+1),'goal'=>''];
      echo '<div style="display:grid;grid-template-columns:180px 1fr;gap:8px;margin:8px 0">';
      echo '<input type="text" name="phases['.$i.'][weeks]['.$w.'][title]" value="'.esc_attr($wk['title']).'" />';
      echo '<textarea name="phases['.$i.'][weeks]['.$w.'][goal]" rows="2" placeholder="Mục tiêu tuần">'.esc_textarea($wk['goal']).'</textarea>';
      echo '</div>';
    }
    echo '</fieldset>';
  }

  echo '<p><button class="button button-primary" name="bccm_save_plan_template" value="1">Save Template</button></p>';
  echo '</form>';
}


/* ==================== COACHEES: ADD/EDIT/GENERATE ==================== */
/* ---------- Coachees Add/Edit + 5 actions ---------- */
function bccm_admin_coachees(){
  if ( ! current_user_can('manage_options') ) return;

  global $wpdb;
  $t          = bccm_tables();
  $types_meta = bccm_coach_types();                 // slug => ['label','template','fields','generators']

  $editing = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
  $row     = $editing ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $editing), ARRAY_A) : null;
  if ($row) $row = bccm_hydrate_extra_fields($row);

  $selected_type = ( isset($_GET['type']) && isset($types_meta[$_GET['type']]) )
      ? sanitize_text_field($_GET['type'])
      : ( $row['coach_type'] ?? bccm_default_coach_type() );

  $t_astro   = $wpdb->prefix.'bccm_astro';
  $astro_row = $editing ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_astro WHERE coachee_id=%d", $editing), ARRAY_A) : null;

  /* ==================== HANDLE ACTIONS ==================== */
  if ( !empty($_POST['bccm_action']) && check_admin_referer('bccm_coachee_actions') ) {

    // --- 1) Chuẩn bị dữ liệu lưu hồ sơ (base + field theo type) ---
    $coach_type = sanitize_text_field($_POST['coach_type'] ?? bccm_default_coach_type());
    $data = [
      'coach_type' => $coach_type,
      'full_name'  => sanitize_text_field($_POST['full_name'] ?? ''),
      'phone'      => sanitize_text_field($_POST['phone'] ?? ''),
      'address'    => sanitize_text_field($_POST['address'] ?? ''),
      'dob'        => sanitize_text_field($_POST['dob'] ?? ''),
    ];

    // Whitelist & ép kiểu field theo type
    $type_fields = bccm_fields_for_type($coach_type); // key => cfg
    foreach ($type_fields as $key => $cfg) {
      if (!array_key_exists($key, $_POST)) continue;
      $val  = $_POST[$key];
      $type = isset($cfg['type']) ? $cfg['type'] : 'text';

      if (in_array($type, ['number'], true)) {
        // một số key là int, số còn lại là float
        if (in_array($key, ['baby_gestational_weeks'], true)) {
          $data[$key] = is_numeric($val) ? intval($val) : null;
        } else {
          $data[$key] = is_numeric($val) ? (float)$val : null;
        }
      } else {
        $data[$key] = sanitize_text_field($val);
      }
    }

    // Tương thích legacy cho BizCoach (nếu schema cũ vẫn còn các cột company_*)
    foreach (['company_name','company_industry','company_founded_date'] as $legacy) {
      if (isset($_POST[$legacy])) $data[$legacy] = sanitize_text_field($_POST[$legacy]);
    }

    // Upsert hồ sơ
    $coachee_id = bccm_upsert_profile($data, $editing);

    // Lưu Answers (20 câu) & Astro meta
    // Lưu 20 câu trả lời vào cột answer_json (thay vì bảng bccm_answers)
    $answers_raw = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
    $answers_arr = [];
    for ($i=0; $i<20; $i++) {
      $answers_arr[$i] = sanitize_text_field($answers_raw[$i] ?? '');
    }
    $wpdb->update($t['profiles'], [
      'answer_json' => wp_json_encode($answers_arr, JSON_UNESCAPED_UNICODE),
    ], ['id' => (int)$coachee_id]);

    // Astro meta giữ nguyên
    $birth_place = sanitize_text_field($_POST['birth_place'] ?? '');
    $birth_time  = sanitize_text_field($_POST['birth_time']  ?? '');
    #bccm_upsert_astro_meta($coachee_id, $birth_place, $birth_time);

    // --- 2) Xử lý action ---
    $action   = sanitize_text_field($_POST['bccm_action']);
    $gens     = bccm_generators_for_type($coach_type);   // mảng generator cho type hiện tại
    $gens_map = [];
    foreach ($gens as $g) { if (!empty($g['key']) && !empty($g['fn'])) $gens_map[$g['key']] = $g['fn']; }

    $print_success = function($msg){
      echo '<div class="updated"><p>'.esc_html($msg).'</p></div>';
    };
    $print_error = function($err){
      if (is_wp_error($err)) {
        $raw = $err->get_error_data()['raw'] ?? '';
        echo '<div class="error"><p>'.esc_html($err->get_error_message()).'</p>';
        if ($raw){
          echo '<details style="margin-top:8px"><summary>Raw AI output</summary><pre style="white-space:pre-wrap;max-height:280px;overflow:auto;border:1px solid #ddd;padding:8px;background:#fff">'.esc_html($raw).'</pre></details>';
        }
        echo '</div>';
      } else {
        echo '<div class="error"><p>Đã có lỗi không xác định.</p></div>';
      }
    };

    // Ưu tiên: nếu action khớp generator key trong registry → gọi hàm tương ứng
    if (isset($gens_map[$action]) && function_exists($gens_map[$action])) {
      $ok = call_user_func($gens_map[$action], (int)$coachee_id);
      if (is_wp_error($ok)) { $print_error($ok); }
      else { $print_success('Đã chạy generator: '.$action.' và lưu vào hồ sơ.'); }
    } else {
      // Fallback: các action legacy sẵn có trước đây
      switch ($action) {
        case 'save_only':
          $print_success('Đã lưu hồ sơ.');
          break;

        case 'gen_overview':
          $ok = bccm_generate_overview($coachee_id);
          is_wp_error($ok) ? $print_error($ok) : $print_success('Đã tạo Thông tin tổng quan & lưu vào bản đồ chuyển hóa.');
          break;
        case 'gen_iqmap':
          $ok = bccm_generate_leadership_iqmap($coachee_id);
          is_wp_error($ok) ? $print_error($ok) : $print_success('Đã tạo bản đồ IQ Map & lưu vào hồ sơ.');
          break;
        case 'gen_metrics':
          $ok = bccm_generate_metrics_only($coachee_id);
          is_wp_error($ok) ? $print_error($ok) : $print_success('Đã tạo bản đồ Thần số học (21 chỉ số).');
          break;

        case 'gen_vision':
          $ok = bccm_generate_vision_map($coachee_id);
          is_wp_error($ok) ? $print_error($ok) : $print_success('Đã tạo Mission • Vision Map và lưu vào hồ sơ.');
          break;

        case 'gen_swot':
          $ok = bccm_generate_swot_map($coachee_id);
          is_wp_error($ok) ? $print_error($ok) : $print_success('Đã tạo SWOT và lưu vào hồ sơ.');
          break;

        case 'gen_customer':
          $ok = bccm_generate_customer_insight_map($coachee_id);
          is_wp_error($ok) ? $print_error($ok) : $print_success('Đã tạo Customer Insights & lưu vào hồ sơ.');
          break;

        case 'gen_value':
          $ok = bccm_generate_value_map($coachee_id);
          is_wp_error($ok) ? $print_error($ok) : $print_success('Đã tạo Value Proposition & lưu vào hồ sơ.');
          break;

        case 'gen_winning':
          $ok = bccm_generate_winning_model($coachee_id);
          is_wp_error($ok) ? $print_error($ok) : $print_success('Đã tổng hợp Winning Model (What • Why • How • Who) và lưu vào hồ sơ.');
          break;

        case 'gen_bizcoach':
          $ok = bccm_generate_bizcoach_map($coachee_id);
          is_wp_error($ok) ? $print_error($ok) : $print_success('Đã tạo BizCoach 90-Day Map & lưu vào hồ sơ.');
          break;
        case 'gen_action_plan_90':
          $ok = bccm_generate_90day_action_plan_ai($coachee_id);
          if (is_wp_error($ok)) { $print_error($ok); }
          else {
            $msg = 'Đã tạo 90-Day Action Plan (Template) và lưu vào bảng action_plans.';
            $print_success($msg);
          }
          break;

        case 'gen_plan':
          
          $ok = bccm_generate_plan_from_answers($coachee_id);
          $plan_id = $ok['plan_id'] ?? 0;
          $plan90 = bccm_generate_90day_action_plan_ai($coachee_id, $plan_id);
          if (is_wp_error($plan90)) { $print_error($plan90); }
          else {
            $msg = 'Đã tạo 90-Day Action Plan (Template) và lưu vào bảng action_plans.';
            $print_success($msg);
          }

          if (is_wp_error($ok)) { $print_error($ok); }
          else {
            $msg = 'Đã tạo bản đồ chuyển hóa 90 ngày.';
            if (is_array($ok) && !empty($ok['public_url'])) $msg .= ' ';
            $print_success($msg . ( !empty($ok['public_url']) ? 'Xem: '.$ok['public_url'] : '' ));
            if (!empty($ok['public_url'])) {
              echo '<p><a target="_blank" class="button button-primary" href="'.esc_url($ok['public_url']).'">Open public map</a></p>';
            }
          }
          break;
      }
    }

    // --- refresh dữ liệu sau khi hành động ---
    $editing = $coachee_id;
    $row     = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $editing), ARRAY_A);
    $selected_type = !empty($row['coach_type']) ? $row['coach_type'] : $coach_type;
  }

  /* ==================== UI ==================== */
  $v = function($k) use($row){ return esc_attr($row[$k] ?? ''); };

  $questions = bccm_get_questions_for($selected_type);

  // Ưu tiên đọc từ cột answer_json trong profiles
  $answers = [];
  if ($editing && !empty($row['answer_json'])) {
    $tmp = json_decode($row['answer_json'], true);
    if (json_last_error()===JSON_ERROR_NONE && is_array($tmp)) $answers = $tmp;
  }

  // (Tùy chọn) migrate một lần từ bảng cũ nếu còn dữ liệu legacy
  if ($editing && empty($answers)) {
    $legacy_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['answers']} WHERE coachee_id=%d", $editing), ARRAY_A);
    if ($legacy_row && !empty($legacy_row['answers'])) {
      $tmp = json_decode($legacy_row['answers'], true);
      if (is_array($tmp)) {
        $answers = $tmp;
        // ghi sang answer_json để lần sau dùng trực tiếp
        $wpdb->update($t['profiles'], [
          'answer_json' => wp_json_encode($answers, JSON_UNESCAPED_UNICODE),
        ], ['id' => (int)$editing]);
      }
    }
  }

  $redir = admin_url('admin.php?page=bccm_coachees' . ($editing ? '&edit='.$editing : ''));

  echo '<div class="wrap bccm-wrap"><h1>Coachees</h1><div style="display:flex;gap:24px">';

  /* ---------- LEFT: FORM ---------- */
  echo '<div style="flex:1;min-width:420px">';
  echo '<h2>'.($editing? 'Edit' : 'Add').' Coachee</h2>';

  echo '<form method="post">';
  wp_nonce_field('bccm_coachee_actions');
  echo '<table class="form-table">';

  // Chọn coach type
  echo '<tr><th>Coach Type</th><td><select name="coach_type" id="bccm_coach_type" onchange="window.location.href=\''.
       esc_url($redir).'&type=\'+encodeURIComponent(this.value)">';
  foreach ($types_meta as $slug => $meta) {
    $label = isset($meta['label']) ? $meta['label'] : $slug;
    echo '<option value="'.esc_attr($slug).'"'.selected($selected_type,$slug,false).'>'.esc_html($label).'</option>';
  }
  echo '</select></td></tr>';

  // Field chung
  echo '<tr><th>Full name</th><td><input name="full_name" value="'.$v('full_name').'" class="regular-text"/></td></tr>';
  echo '<tr><th>Phone</th><td><input name="phone" value="'.$v('phone').'" class="regular-text"/></td></tr>';
  echo '<tr><th>Address</th><td><input name="address" value="'.$v('address').'" class="regular-text"/></td></tr>';
  echo '<tr><th>DOB</th><td><input type="date" name="dob" value="'.$v('dob').'"/></td></tr>';

  // Astro meta
  echo '<tr><th>Birth place</th><td><input name="birth_place" value="'.esc_attr($astro_row['birth_place'] ?? '').'" class="regular-text" placeholder="Thành phố, quốc gia"/></td></tr>';
  echo '<tr><th>Birth time</th><td><input name="birth_time" value="'.esc_attr($astro_row['birth_time'] ?? '').'" class="regular-text" placeholder="HH:MM (24h), có thể để trống)"/></td></tr>';

  // Field theo coach type
  $type_fields = bccm_fields_for_type($selected_type);
  if (!empty($type_fields)) {
    echo '<tr><th colspan="2"><h3 style="margin:0">Thông tin theo Coach Type</h3></th></tr>';
    foreach ($type_fields as $key => $cfg) {
      $label = $cfg['label'] ?? $key;
      $type  = $cfg['type']  ?? 'text';
      $val   = $row[$key] ?? '';
      $ph    = $cfg['placeholder'] ?? '';
      $step  = !empty($cfg['step']) ? ' step="'.esc_attr($cfg['step']).'"' : '';
      
      echo '<tr><th>'.esc_html($label).'</th><td>';
      if ($type === 'select') {
        echo '<select name="'.esc_attr($key).'">';
        foreach (($cfg['options'] ?? []) as $optVal => $optLabel) {
          printf('<option value="%s"%s>%s</option>', esc_attr($optVal), selected($val,$optVal,false), esc_html($optLabel));
        }
        echo '</select>';
      } else {
        printf('<input type="%s" name="%s" value="%s" placeholder="%s"%s class="regular-text"/>',
          esc_attr($type), esc_attr($key), esc_attr($val), esc_attr($ph), $step
        );
      }
      echo '</td></tr>';
    }
  }

  echo '</table>';

  // Answers (20 câu động theo type)
  echo '<h3>Answers</h3><table class="widefat striped"><thead><tr><th style="width:40px">#</th><th>Question</th><th>Answer</th></tr></thead><tbody>';
  for ($i=0; $i<20; $i++) {
    $q = $questions[$i] ?? '';
    $a = $answers[$i] ?? '';
    echo '<tr><td>'.($i+1).'</td><td>'.esc_html($q).'</td><td><input type="text" name="answers[]" value="'.esc_attr($a).'" style="width:100%"/></td></tr>';
  }
  echo '</tbody></table>';
// Nút hành động: Save + generators theo type + Public Map
echo '<p style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">';
echo '<button class="button button-secondary" name="bccm_action" value="save_only">1) Save</button>';

$gens = bccm_generators_for_type($selected_type);
$idx  = 2; // đánh số nút kế tiếp

if (!empty($gens)) {
  foreach ($gens as $g) {
    $gkey   = esc_attr($g['key']);
    $glabel = esc_html($g['label'] ?? ('Run '.$gkey));
    echo '<button class="button" name="bccm_action" value="'.$gkey.'">'.($idx++).') Tạo '.$glabel.'</button>';
  }
} else {
  // fallback hiển thị các nút legacy nếu type chưa khai báo generators
  echo '<button class="button" name="bccm_action" value="gen_metrics">'.($idx++).') Tạo bản đồ Thần số học</button>';
  echo '<button class="button" name="bccm_action" value="gen_overview">'.($idx++).') Tạo nhận xét tổng quan</button>';
  echo '<button class="button" name="bccm_action" value="gen_iqmap">'.($idx++).') Tạo bản đồ lãnh đạo (Leadership Map)</button>';
  echo '<button class="button" name="bccm_action" value="gen_vision">'.($idx++).') Tạo Mission • Vision Map</button>';
  echo '<button class="button" name="bccm_action" value="gen_swot">'.($idx++).') Tạo SWOT Analysis</button>';
  echo '<button class="button" name="bccm_action" value="gen_customer">'.($idx++).') Tạo Customer Insights</button>';
  echo '<button class="button" name="bccm_action" value="gen_value">'.($idx++).') Tạo bản đồ chuỗi giá trị</button>';
  echo '<button class="button" name="bccm_action" value="gen_winning">'.($idx++).') Winning Model (What • Why • How • Who)</button>';
  // ... trong bccm_admin_coachees(), ngay sau các nút khác:
  echo '<button class="button button-primary" name="bccm_action" value="gen_bizcoach">'.($idx++).') Tạo 90-Day Business Transform</button>';
  echo '<button class="button button-primary" name="bccm_action" value="gen_action_plan_90">'.($idx++).') Tạo 90-Day Digital Transform</button>';

}

// nút tạo bản đồ Public – luôn hiển thị cho mọi coach type
echo '<button class="button button-primary" name="bccm_action" value="gen_plan">'.($idx++).') Tạo bản đồ Public</button>';
echo '</p>';

echo '</form>'; // end form


  /* ---------- RIGHT: LIST ---------- */
  $list = $wpdb->get_results("SELECT * FROM {$t['profiles']} ORDER BY id DESC LIMIT 50", ARRAY_A);

  echo '</div><div style="flex:1">';
  echo '<h2>Latest Coachees</h2>';
  echo '<table class="widefat striped"><thead><tr><th style="width:70px">ID</th><th>Name</th><th>Type</th><th>Phone</th><th>Actions</th></tr></thead><tbody>';

  foreach($list as $r){
    $type_label = isset($types_meta[$r['coach_type']]['label']) ? $types_meta[$r['coach_type']]['label'] : $r['coach_type'];

    // ưu tiên tên bé
    $name = $r['full_name'];
    $slug = function_exists('bccm_slugify_type') ? bccm_slugify_type($r['coach_type']) : strtolower($r['coach_type']);
    if (strpos($slug, 'baby') !== false && !empty($r['baby_name'])) {
      $name = $r['baby_name']; // hoặc "{$r['baby_name']} ({$r['full_name']})"
    }
    $coachee_id = (int)($r['id'] ?? 0);
    $ai_url = add_query_arg(['page'=>'bccm_ai_json_editor','coachee_id'=>$coachee_id], admin_url('admin.php'));
    $view = !empty($r['public_url'])? ' <a class="button button-primary" target="_blank" href="'.esc_url($r['public_url']).'">View Public</a>' : '';
    $dup  = wp_nonce_url(admin_url('admin-post.php?action=bccm_dup_coachee&id='.$r['id']), 'bccm_dup_'.$r['id']);
    $del  = wp_nonce_url(admin_url('admin-post.php?action=bccm_del_coachee&id='.$r['id']), 'bccm_del_'.$r['id']);

    echo '<tr><td>'.intval($r['id']).'</td><td>'.esc_html($name).'</td><td>'.esc_html($type_label).'</td><td>'.esc_html($r['phone']).'</td><td><a class="button" href="'.esc_url(admin_url('admin.php?page=bccm_coachees&edit='.$r['id'])).'">Edit</a> <a class="button" href="'.$ai_url.'">Sửa bản đồ khai mở Mindset</a>  <a class="button" href="'.esc_url($dup).'">Duplicate</a>'.$view.' <a class="button" href="'.esc_url($del).'" onclick="return confirm(\'Delete?\')">Delete</a></td></tr>';
  }

  echo '</tbody></table></div></div></div>';
}



/* ================== Coachees list (minimal) ================== */
/* ================== Coachees list (with pagination & actions) ================== */
function bccm_admin_coachees_list(){
  if ( ! current_user_can('manage_options') ) return;

  global $wpdb; 
  $t           = bccm_tables();
  $types_meta  = bccm_coach_types();

  // ---- Pagination ----
  $per_page = 20;
  $paged    = max(1, intval($_GET['paged'] ?? 1));
  $offset   = ($paged - 1) * $per_page;

  $total    = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['profiles']}");
  $pages    = max(1, (int)ceil($total / $per_page));

  // ---- Query list ----
  $sql  = $wpdb->prepare("SELECT * FROM {$t['profiles']} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset);
  $list = $wpdb->get_results($sql, ARRAY_A);

  $base_url = admin_url('admin.php?page=bccm_coachees_list');

  echo '<div class="wrap bccm-wrap"><h1>Coachees List</h1>';

  // Add new button
  echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bccm_coachees')).'">+ Add new Coachee</a></p>';

  // ---- Table ----
  echo '<table class="widefat striped">';
  echo '<thead><tr>';
  echo '<th style="width:70px">ID</th>';
  echo '<th>Name</th>';
  echo '<th>Type</th>';
  echo '<th>Phone</th>';
  echo '<th style="width:140px">Saved at</th>';
  echo '<th style="width:110px">Map</th>';
  echo '<th style="width:110px">Lập bản đồ</th>';
  echo '<th style="width:240px">Actions</th>';
  echo '</tr></thead><tbody>';

  if (!$list){
    echo '<tr><td colspan="7">No coachees yet.</td></tr>';
  } else {
    foreach ($list as $r){
      // Type label
      $type_label = isset($types_meta[$r['coach_type']]['label']) ? $types_meta[$r['coach_type']]['label'] : $r['coach_type'];

      // Name: ưu tiên baby_name nếu là baby
      $name = $r['full_name'];
      if (function_exists('bccm_slugify_type')) {
        $slug = bccm_slugify_type($r['coach_type']);
        if (strpos($slug, 'baby') !== false && !empty($r['baby_name'])) $name = $r['baby_name'];
      }

      // Saved at
      $saved_raw = !empty($r['updated_at']) ? $r['updated_at'] : (!empty($r['created_at']) ? $r['created_at'] : '');
      $saved_at  = $saved_raw ? esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($saved_raw))) : '-';

      // Map link
      $map_html = !empty($r['public_url']) 
        ? '<a class="button" target="_blank" href="'.esc_url($r['public_url']).'">Open Map</a>'
        : '-';
      $ai_url = add_query_arg([
        'page' => 'bccm_ai_json_editor',
        'coachee_id' => (int)$r['id'],
      ], admin_url('admin.php'));
      // Action links
      $edit_url = admin_url('admin.php?page=bccm_coachees&edit='.(int)$r['id']);
      $dup_url  = wp_nonce_url(admin_url('admin-post.php?action=bccm_dup_coachee&id='.(int)$r['id']), 'bccm_dup_'.(int)$r['id']);
      $del_url  = wp_nonce_url(admin_url('admin-post.php?action=bccm_del_coachee&id='.(int)$r['id']), 'bccm_del_'.(int)$r['id']);

      echo '<tr>';
      echo '<td>'.intval($r['id']).'</td>';
      echo '<td>'.esc_html($name).'</td>';
      echo '<td>'.esc_html($type_label).'</td>';
      echo '<td>'.esc_html($r['phone']).'</td>';
      echo '<td>'.$saved_at.'</td>';
      echo '<td>'.$map_html.'</td>';

      echo '<td><a class="button " href="'.esc_url($ai_url).'">Sửa bản đồ khai mở Mindset</a></td>';
      // Nếu muốn nhảy thẳng tới tab cụ thể, thêm 'tab' => 'vision_json' chẳng hạn.
      
      echo '<td>'.
             '<a class="button" href="'.esc_url($edit_url).'">Edit</a> '.
             '<a class="button" href="'.esc_url($dup_url).'">Duplicate</a> '.
             '<a class="button" href="'.esc_url($del_url).'" onclick="return confirm(\'Delete this coachee?\')">Delete</a>'.
           '</td>';
      echo '</tr>';
    }
  }

  echo '</tbody></table>';

  // ---- Pagination controls ----
  if ($pages > 1){
    echo '<div class="tablenav"><div class="tablenav-pages" style="margin-top:10px">';
    $first = esc_url(add_query_arg('paged', 1, $base_url));
    $prev  = esc_url(add_query_arg('paged', max(1, $paged-1), $base_url));
    $next  = esc_url(add_query_arg('paged', min($pages, $paged+1), $base_url));
    $last  = esc_url(add_query_arg('paged', $pages, $base_url));

    echo '<span class="displaying-num">'.intval($total).' items</span> ';
    echo '<span class="pagination-links">';
    echo '<a class="first-page'.($paged<=1?' disabled':'').'" href="'.($paged<=1?'#':$first).'">&laquo;</a>';
    echo '<a class="prev-page'.($paged<=1?' disabled':'').'" href="'.($paged<=1?'#':$prev).'">&lsaquo;</a>';
    echo '<span class="paging-input">'.$paged.' / <span class="total-pages">'.$pages.'</span></span>';
    echo '<a class="next-page'.($paged>=$pages?' disabled':'').'" href="'.($paged>=$pages?'#':$next).'">&rsaquo;</a>';
    echo '<a class="last-page'.($paged>=$pages?' disabled':'').'" href="'.($paged>=$pages?'#':$last).'">&raquo;</a>';
    echo '</span>';

    echo '</div></div>';
  }

  echo '</div>'; // .wrap
}


/* ================== Duplicate/Delete handlers ================== */
add_action('admin_post_bccm_dup_coachee', function(){
  if (!current_user_can('manage_options')) wp_die('No');
  global $wpdb; $t=bccm_tables();
  $id = isset($_GET['id'])? intval($_GET['id']) : 0; check_admin_referer('bccm_dup_'.$id);
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $id), ARRAY_A);
  if(!$row) wp_safe_redirect(admin_url('admin.php?page=bccm_coachees_list&error=nf'));
  unset($row['id']);
  $row['full_name'] = ($row['full_name']?:'Coachee').' (Copy)';
  $row['public_url']='';
  $row['created_at']=current_time('mysql');
  $row['updated_at']=current_time('mysql');
  $wpdb->insert($t['profiles'],$row);
  $new = $wpdb->insert_id;
  $ans = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['answers']} WHERE coachee_id=%d", $id), ARRAY_A);
  if($ans){
    unset($ans['id']);
    $ans['coachee_id']=$new;
    $ans['updated_at']=current_time('mysql');
    $wpdb->insert($t['answers'],$ans);
  }
  wp_safe_redirect(admin_url('admin.php?page=bccm_coachees&edit='.$new.'&duplicated=1')); exit;
});

add_action('admin_post_bccm_del_coachee', function(){
  if (!current_user_can('manage_options')) wp_die('No');
  global $wpdb; $t=bccm_tables();
  $id = isset($_GET['id'])? intval($_GET['id']) : 0; check_admin_referer('bccm_del_'.$id);
  $wpdb->delete($t['answers'], ['coachee_id'=>$id], ['%d']);
  $wpdb->delete($t['plans'],   ['coachee_id'=>$id], ['%d']);
  $wpdb->delete($t['logs'],    ['coachee_id'=>$id], ['%d']);
  $wpdb->delete($t['profiles'],['id'=>$id], ['%d']);
  wp_safe_redirect(admin_url('admin.php?page=bccm_coachees_list&deleted=1')); exit;
});


/* ====================== Questions loader ====================== */
function bccm_get_questions_for($coach_type){
  global $wpdb; 
  $t = bccm_tables();

  // chuẩn hoá & tạo các biến thể slug để dò
  $slug = bccm_slugify_type($coach_type);
  $candidates = array_values(array_unique([
    (string)$coach_type,              // giữ nguyên giá trị truyền vào
    $slug,                            // biz_coach
    str_replace('_','',$slug),        // bizcoach
    str_replace('_','-',$slug),       // biz-coach
  ]));

  // Ưu tiên match chính xác theo danh sách candidates
  $placeholders = implode(',', array_fill(0, count($candidates), '%s'));
  $sql1 = "SELECT questions FROM {$t['templates']} 
           WHERE coach_type IN ($placeholders) 
           ORDER BY updated_at DESC, id DESC LIMIT 1";
  $row  = $wpdb->get_row($wpdb->prepare($sql1, $candidates), ARRAY_A);

  // Fallback: LIKE theo slug
  if (!$row) {
    $like = '%'.$slug.'%';
    $sql2 = "SELECT questions FROM {$t['templates']} 
             WHERE coach_type LIKE %s 
             ORDER BY updated_at DESC, id DESC LIMIT 1";
    $row  = $wpdb->get_row($wpdb->prepare($sql2, $like), ARRAY_A);
  }

  // Decode JSON câu hỏi
  $questions = [];
  if ($row && !empty($row['questions'])) {
    $questions = bccm_try_decode_questions_json($row['questions']);
  }

  // Fallback về default nếu vẫn rỗng
  if (empty($questions)) {
    $defs = function_exists('bccm_default_questions') ? bccm_default_questions() : [];
    $questions = $defs[$slug] ?? [];

    // Alias lookup: mental_coach → mental, health_coach → health, baby_coach → baby_health …
    if (empty($questions)) {
      $without_suffix = preg_replace('/_coach$/', '', $slug);
      foreach ($defs as $dk => $dv) {
        if ($dk === $without_suffix || strpos($dk, $without_suffix) === 0 || strpos($without_suffix, $dk) === 0) {
          $questions = $dv;
          break;
        }
      }
    }
  }

  // Chuẩn hoá: mảng indexed, tối đa 20 mục, pad rỗng nếu thiếu
  $questions = array_values(array_map('wp_strip_all_tags', (array)$questions));
  $questions = array_slice($questions, 0, 20);
  for ($i = count($questions); $i < 20; $i++) $questions[] = '';

  return $questions;
}
if (!function_exists('bccm_try_decode_questions_json')) {
  function bccm_try_decode_questions_json($str){
    if (!is_string($str) || $str === '') return [];

    // Thử decode bình thường
    $arr = json_decode($str, true);
    if (is_array($arr)) return $arr;

    // Thử chuyển từ ISO-8859-1 -> UTF-8 (trường hợp "MÃ…")
    $s1 = @iconv('ISO-8859-1','UTF-8//IGNORE', $str);
    $arr = json_decode($s1, true);
    if (is_array($arr)) return $arr;

    // Thử utf8_encode
    $s2 = utf8_encode($str);
    $arr = json_decode($s2, true);
    if (is_array($arr)) return $arr;

    // Thử bỏ slash nếu bị escape
    $s3 = wp_unslash($str);
    $arr = json_decode($s3, true);
    return is_array($arr) ? $arr : [];
  }
}
// Chuẩn hoá coach_type về dạng slug dùng cho so khớp
if (!function_exists('bccm_slugify_type')) {
  function bccm_slugify_type($s){
    $s = strtolower(trim((string)$s));
    $s = str_replace([' ', '-'], '_', $s);
    // giữ lại chữ/số/_ giống sanitize_key nhưng không mất số
    return preg_replace('/[^a-z0-9_]/', '', $s);
  }
}
/**
 * Upsert hồ sơ coachee (Bước 1)
 * Trả về ID coachee
 */
function bccm_upsert_profile(array $data, $editing_id = 0){
  global $wpdb; 
  $t = bccm_tables();

  // chuẩn hoá nhanh cho number & date theo locale vn
  $norm_decimal = function($v){
    if ($v === '' || $v === null) return null;
    $v = trim((string)$v);
    $v = str_replace([' ', '.'], '', $v); // bỏ phân tách nghìn
    $v = str_replace(',', '.', $v);       // phẩy -> chấm
    return is_numeric($v) ? (float)$v : null;
  };
  $norm_date = function($v){
    if (empty($v)) return null;
    $v = trim((string)$v);
    // dd/mm/yyyy hoặc dd-mm-yyyy -> yyyy-mm-dd
    if (preg_match('~^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$~', $v, $m)) {
      return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return $v; // đã là yyyy-mm-dd thì giữ nguyên
  };

  $row = [
    // chung
    'coach_type'           => sanitize_text_field($data['coach_type'] ?? ''),
    'full_name'            => sanitize_text_field($data['full_name'] ?? ''),
    'phone'                => sanitize_text_field($data['phone'] ?? ''),
    'address'              => sanitize_text_field($data['address'] ?? ''),
    'dob'                  => $norm_date($data['dob'] ?? null),

    // user binding
    'user_id'              => isset($data['user_id']) ? (int)$data['user_id'] : null,
    'platform_type'        => sanitize_text_field($data['platform_type'] ?? 'WEBCHAT'),

    // BizCoach (legacy) – giữ để tương thích
    'company_name'         => sanitize_text_field($data['company_name'] ?? ''),
    'company_industry'     => sanitize_text_field($data['company_industry'] ?? ''),
    'company_founded_date' => $norm_date($data['company_founded_date'] ?? null),

    // BabyCoach
    'baby_name'              => sanitize_text_field($data['baby_name'] ?? ''),
    'baby_gender'            => sanitize_text_field($data['baby_gender'] ?? ''),
    'baby_gestational_weeks' => isset($data['baby_gestational_weeks']) && $data['baby_gestational_weeks'] !== '' ? (int)$data['baby_gestational_weeks'] : null,
    'baby_weight_kg'         => $norm_decimal($data['baby_weight_kg'] ?? null),
    'baby_height_cm'         => $norm_decimal($data['baby_height_cm'] ?? null),

    // timestamps
    'updated_at'           => current_time('mysql'),
  ];

  // Tách extra fields (type-specific fields không có cột DB)
  $coach_type   = $row['coach_type'];
  $type_fields  = function_exists('bccm_fields_for_type') ? bccm_fields_for_type($coach_type) : [];
  $extra_data   = [];
  foreach ($type_fields as $fk => $fcfg) {
    if (array_key_exists($fk, $data) && !array_key_exists($fk, $row)) {
      $extra_data[$fk] = $data[$fk];
    }
  }

  if ($editing_id){
    $wpdb->update($t['profiles'], $row, ['id'=>$editing_id]);
    if (!empty($extra_data)) bccm_save_extra_fields($editing_id, $extra_data);
    return (int)$editing_id;
  } else {
    $row['created_at'] = current_time('mysql');
    $wpdb->insert($t['profiles'], $row);
    $new_id = (int)$wpdb->insert_id;
    if (!empty($extra_data) && $new_id) bccm_save_extra_fields($new_id, $extra_data);
    return $new_id;
  }
}
