<?php
/**
 * BizCoach Map – Step 2: Chọn Coach Template + Câu hỏi bổ sung
 *
 * Bước 2 trong workflow 4 bước:
 *   Step 1: Hồ sơ cá nhân + Chiêm tinh (admin-self-profile.php)
 *   Step 2: Chọn Coach Template + Câu hỏi bổ sung (trang này)
 *   Step 3: Tạo Character (admin-step3-character.php)
 *   Step 4: Success Plan + Gán Character đồng hành (admin-step4-success-plan.php)
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * ADMIN MENU: Bước 2 – Chọn Coach Template
 * =====================================================================*/
add_action('admin_menu', function () {
  add_submenu_page(
    'bccm_root',
    'Bước 2: Coach Template',
    'Bước 2: Coach Template',
    'edit_posts',
    'bccm_step2_coach_template',
    'bccm_admin_step2_coach_template',
    12
  );
}, 12);

/* =====================================================================
 * ADMIN PAGE: Bước 2 – Chọn Coach Template & Câu hỏi bổ sung
 * =====================================================================*/
function bccm_admin_step2_coach_template() {
  if (!current_user_can('edit_posts')) return;
  
  // Enqueue jQuery for AJAX
  wp_enqueue_script('jquery');

  global $wpdb;
  $t = bccm_tables();
  $user_id = get_current_user_id();
  $types_meta = bccm_coach_types();
 
  // Lấy hoặc tạo hồ sơ ADMINCHAT
  $coachee = bccm_get_or_create_user_coachee($user_id, 'ADMINCHAT', 'career_coach');
  if (!$coachee) {
   # echo '<div class="wrap bccm-wrap"><h1>Lỗi</h1><p>Không thể tạo hồ sơ.</p></div>';
    #return;
  }
  $coachee = bccm_hydrate_extra_fields($coachee);

  $coachee_id    = (int)$coachee['id'];
  $selected_type = $coachee['coach_type'] ?: 'career_coach';

  // Check if Step 1 is complete (has DOB + astro data)
  $t_astro   = $wpdb->prefix . 'bccm_astro';
  // USE user_id as primary for cross-platform consistency
  $astro_row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' ORDER BY id DESC LIMIT 1", $user_id
  ), ARRAY_A);
  if (!$astro_row) {
    $astro_row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic' ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
  }
  $step1_complete = !empty($coachee['dob']) && !empty($coachee['full_name']);

  // Handle coach type switch via GET param
  if (!empty($_GET['switch_type'])) {
    $new_type = sanitize_text_field($_GET['switch_type']);
    if (isset($types_meta[$new_type]) && $new_type !== $selected_type) {
      $wpdb->update($t['profiles'], [
        'coach_type'  => $new_type,
        'updated_at'  => current_time('mysql'),
      ], ['id' => $coachee_id]);
      $selected_type = $new_type;
      $coachee['coach_type'] = $new_type;
    }
  }

  // Questions & answers
  $questions = bccm_get_questions_for($selected_type);
  $answers   = [];
  if (!empty($coachee['answer_json'])) {
    $tmp = json_decode($coachee['answer_json'], true);
    if (is_array($tmp)) $answers = $tmp;
  }

  /* ==================== HANDLE POST ACTIONS ==================== */
  if (!empty($_POST['bccm_action']) && check_admin_referer('bccm_step2_template')) {

    $coach_type = sanitize_text_field($_POST['coach_type'] ?? $selected_type);
    $data = [
      'coach_type' => $coach_type,
    ];

    // Type-specific fields
    $type_fields = bccm_fields_for_type($coach_type);
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

    // Legacy fields
    foreach (['company_name','company_industry','company_founded_date'] as $legacy) {
      if (isset($_POST[$legacy])) $data[$legacy] = sanitize_text_field($_POST[$legacy]);
    }

    // Tách DB columns vs extra fields (type-specific fields không có cột DB)
    list($db_data, $extra_data) = bccm_split_extra_fields($data, $type_fields);
    $db_data['updated_at'] = current_time('mysql');

    $wpdb->update($t['profiles'], $db_data, ['id' => $coachee_id]);

    // Lưu extra fields vào JSON
    if (!empty($extra_data)) {
      bccm_save_extra_fields($coachee_id, $extra_data);
    }

    // Save answers
    $answers_raw = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
    $answers_arr = [];
    for ($i = 0; $i < 20; $i++) {
      $answers_arr[$i] = sanitize_text_field($answers_raw[$i] ?? '');
    }
    $wpdb->update($t['profiles'], [
      'answer_json' => wp_json_encode($answers_arr, JSON_UNESCAPED_UNICODE),
    ], ['id' => $coachee_id]);

    $action = sanitize_text_field($_POST['bccm_action']);

    // Run generator if not save_only
    if ($action !== 'save_only') {
      $gens = bccm_generators_for_type($coach_type);
      $gens_map = [];
      foreach ($gens as $g) {
        if (!empty($g['key']) && !empty($g['fn'])) $gens_map[$g['key']] = $g['fn'];
      }

      if (isset($gens_map[$action]) && function_exists($gens_map[$action])) {
        $result = call_user_func($gens_map[$action], $coachee_id);
        if (is_wp_error($result)) {
          echo '<div class="error"><p>Lỗi: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
          echo '<div class="updated"><p>✅ Thành công!</p></div>';
        }
      }
    } else {
      // Lưu thành công → redirect sang Bước 3
      echo '<div class="updated"><p>✅ Đã lưu câu trả lời. Đang chuyển sang Bước 3...</p></div>';
      $step3_url = admin_url('admin.php?page=bccm_step3_character');
      echo '<script>setTimeout(function(){ window.location.href = ' . wp_json_encode($step3_url) . '; }, 800);</script>';
    }

    // Refresh data
    $coachee = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
    $coachee = bccm_hydrate_extra_fields($coachee);
    $selected_type = $coachee['coach_type'] ?? $selected_type;
    $questions = bccm_get_questions_for($selected_type);
    $answers = json_decode($coachee['answer_json'] ?? '[]', true) ?: [];
  }

  $v = function($k) use($coachee) { return esc_attr($coachee[$k] ?? ''); };

  /* ==================== RENDER UI ==================== */
  echo '<div class="wrap bccm-wrap">';
  bccm_render_workflow_steps();

  // Step navigation
  echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_my_profile')) . '" class="button">← Bước 1: Hồ sơ cá nhân</a>';
  echo '<span style="font-weight:600;color:#6366f1">📋 Bước 2: Coach Template & Câu hỏi</span>';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_step3_character')) . '" class="button button-primary">Bước 3: Tạo Character →</a>';
  echo '</div>';

  // Step 1 prerequisite warning
  if (!$step1_complete) {
    echo '<div class="notice notice-warning" style="padding:12px;border-left:4px solid #f59e0b">';
    echo '<strong>⚠️ Chưa hoàn thành Bước 1!</strong> ';
    echo 'Hãy hoàn thành <a href="' . esc_url(admin_url('admin.php?page=bccm_my_profile')) . '">Hồ sơ cá nhân & Chiêm tinh</a> trước khi chọn Coach Template.';
    echo '</div>';
  }

  echo '<h1>📋 Bước 2: Chọn Coach Template</h1>';
  echo '<p class="description">Chọn một Coach Template phù hợp. Tất cả templates đều dựa trên nền tảng chiêm tinh từ Bước 1.</p>';

  // ── Astro summary banner ──
  $zodiac = $coachee['zodiac_sign'] ?? '';
  if ($zodiac || !empty($astro_row)) {
    echo '<div class="postbox" style="padding:12px 16px;margin:10px 0 20px;border-left:4px solid #6366f1">';
    echo '<strong>🌟 Nền tảng Chiêm tinh:</strong> ';
    if ($zodiac) {
      $signs = bccm_zodiac_signs();
      foreach ($signs as $s) {
        if (strtolower($s['en'] ?? '') === strtolower($zodiac)) {
          echo esc_html($s['symbol'] . ' ' . $s['vi'] . ' (' . $s['en'] . ')');
          break;
        }
      }
    }
    if (!empty($astro_row['birth_place'])) {
      echo ' | Sinh tại: <em>' . esc_html($astro_row['birth_place']) . '</em>';
    }
    if (!empty($astro_row['birth_time'])) {
      echo ' | Giờ sinh: <em>' . esc_html($astro_row['birth_time']) . '</em>';
    }
    echo ' <a href="' . esc_url(admin_url('admin.php?page=bccm_my_profile')) . '" class="button button-small" style="margin-left:10px">Chỉnh sửa Bước 1 →</a>';
    echo '</div>';
  }

  $redir = admin_url('admin.php?page=bccm_step2_coach_template');

  echo '<form method="post">';
  wp_nonce_field('bccm_step2_template');

  // ── Coach Template Cards ──
  echo '<h3>🎯 Chọn Coach Template</h3>';
  $icons = [
    'career_coach' => '🚀',
    'biz_coach'    => '💼',
    'baby_coach'   => '👶',
    'mental_coach' => '🧘',
    'tiktok_coach' => '📱',
    'astro_coach'  => '🌟',
    'tarot_coach'  => '🔮',
    'health_coach' => '💪',
  ];

  // ── Career Coach featured hero card ──
  if (isset($types_meta['career_coach'])) {
    $cc_active = ($selected_type === 'career_coach');
    $cc_url    = esc_url($redir . '&switch_type=career_coach');
    if ($cc_active) {
      $hero_bg = 'background:linear-gradient(135deg,#6366f1,#8b5cf6);border:2px solid #4f46e5';
      $hero_badge = '<span style="display:inline-block;background:rgba(255,255,255,0.25);color:#fff;font-size:11px;padding:2px 10px;border-radius:20px;margin-top:6px">✅ Đang chọn</span>';
    } else {
      $hero_bg = 'background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:2px solid #38bdf8';
      $hero_badge = '<span style="display:inline-block;background:#38bdf8;color:#fff;font-size:11px;padding:2px 10px;border-radius:20px;margin-top:6px">⭐ Mặc định</span>';
    }
    echo '<a href="' . $cc_url . '" style="' . $hero_bg . ';padding:20px 24px;border-radius:12px;text-decoration:none;display:flex;align-items:center;gap:16px;margin-bottom:16px;box-shadow:0 2px 8px rgba(99,102,241,0.15)">';
    echo '<div style="font-size:40px;line-height:1">🚀</div>';
    echo '<div>';
    echo '<div style="font-size:16px;font-weight:700;color:' . ($cc_active ? '#fff' : '#1e40af') . '">Career Coach <span style="font-size:12px;font-weight:400;opacity:0.8">— Sự nghiệp & Phát triển bản thân</span></div>';
    echo '<div style="font-size:12px;color:' . ($cc_active ? '#c4b5fd' : '#3b82f6') . ';margin-top:4px">Khám phá tiềm năng • Xây dựng lộ trình sự nghiệp • Bản đồ nhà lãnh đạo</div>';
    echo $hero_badge;
    echo '</div>';
    echo '</a>';
  }

  // ── Other types grid (exclude career_coach) ──
  echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:20px">';
  foreach ($types_meta as $slug => $meta) {
    if ($slug === 'career_coach') continue;
    $label = $meta['label'] ?? $slug;
    $is_active = ($selected_type === $slug);
    $icon = $icons[$slug] ?? '📋';

    $card_style = $is_active
      ? 'background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:2px solid #4f46e5'
      : 'background:#fff;border:2px solid #e2e8f0;cursor:pointer';

    $url = esc_url($redir . '&switch_type=' . urlencode($slug));

    echo '<a href="' . $url . '" style="' . $card_style . ';padding:14px;border-radius:10px;text-align:center;text-decoration:none;display:block">';
    echo '<div style="font-size:24px;margin-bottom:5px">' . $icon . '</div>';
    echo '<div style="font-size:13px;font-weight:600;' . ($is_active ? 'color:#fff' : 'color:#1e293b') . '">' . esc_html($label) . '</div>';
    if ($is_active) echo '<div style="font-size:10px;margin-top:3px;color:#c4b5fd">✅ Đang chọn</div>';
    echo '</a>';
  }
  echo '</div>';

  echo '<input type="hidden" name="coach_type" value="' . esc_attr($selected_type) . '"/>';

  echo '<table class="form-table">';

  // Type-specific fields
  $type_fields = bccm_fields_for_type($selected_type);
  if (!empty($type_fields)) {
    echo '<tr><th colspan="2"><h3 style="margin:0">📝 Thông tin bổ sung cho ' . esc_html($types_meta[$selected_type]['label'] ?? $selected_type) . '</h3></th></tr>';
    foreach ($type_fields as $key => $cfg) {
      $label = $cfg['label'] ?? $key;
      $ftype = $cfg['type'] ?? 'text';
      $val   = $coachee[$key] ?? '';
      $ph    = $cfg['placeholder'] ?? '';
      $step  = !empty($cfg['step']) ? ' step="' . esc_attr($cfg['step']) . '"' : '';

      echo '<tr><th>' . esc_html($label) . '</th><td>';
      if ($ftype === 'select') {
        echo '<select name="' . esc_attr($key) . '">';
        foreach (($cfg['options'] ?? []) as $optVal => $optLabel) {
          printf('<option value="%s"%s>%s</option>', esc_attr($optVal), selected($val, $optVal, false), esc_html($optLabel));
        }
        echo '</select>';
      } else {
        printf('<input type="%s" name="%s" value="%s" placeholder="%s"%s class="regular-text"/>',
          esc_attr($ftype), esc_attr($key), esc_attr($val), esc_attr($ph), $step
        );
      }
      echo '</td></tr>';
    }
  }

  echo '</table>';

  // ── 20 Câu hỏi ──
  echo '<h3>📝 Câu trả lời (20 câu hỏi cho ' . esc_html($types_meta[$selected_type]['label'] ?? $selected_type) . ')</h3>';
  echo '<table class="widefat striped"><thead><tr><th style="width:40px">#</th><th>Câu hỏi</th><th>Trả lời</th></tr></thead><tbody>';
  for ($i = 0; $i < 20; $i++) {
    $q = $questions[$i] ?? '';
    $a = $answers[$i] ?? '';
    echo '<tr><td>' . ($i + 1) . '</td><td>' . esc_html($q) . '</td><td><input type="text" name="answers[]" value="' . esc_attr($a) . '" style="width:100%"/></td></tr>';
  }
  echo '</tbody></table>';

  // ── Generators với Job Monitor ──
  $generators = bccm_generators_for_type($selected_type);
  if (!empty($generators)) {
    echo '<button class="button button-primary button-hero" style="margin: 20px auto" name="bccm_action" value="save_only">💾 Lưu câu trả lời → Bước 3</button>';
    echo '<h3>🗺️ Generate bản đồ Coach</h3>';
    echo '<p class="description">Nhớ chọn lưu câu trả lời trước khi generate bản đồ.</p>';
    echo '<p class="description">Các bản đồ sẽ extend thêm yếu tố cá nhân, mong muốn sự nghiệp, và kế hoạch 90 ngày dựa trên nền tảng chiêm tinh từ Bước 1.</p>';

    // Truyền generators xuống JS qua data attribute
    $gen_data = wp_json_encode(array_values($generators), JSON_UNESCAPED_UNICODE);

    echo '<div id="bccm-map-generator" style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin:16px 0">';

    // -- Danh sach generators voi badges --
    echo '<div id="bccm-gen-list" style="margin-bottom:16px"></div>';

    // -- Action buttons --
    echo '<div id="bccm-gen-actions" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">';
    echo '<button type="button" id="bccm-start-gen" class="button button-primary button-hero">▶️ Generate tất cả</button>';
    echo '<button type="button" id="bccm-resume-gen" class="button button-hero" style="display:none;background:#f59e0b;color:#fff;border-color:#d97706">🔁 Resume failed</button>';
    echo '</div>';

    // -- Progress bar --
    echo '<div id="bccm-gen-progress" style="display:none;margin-bottom:12px">';
    echo '<div style="background:#e5e7eb;height:24px;border-radius:12px;overflow:hidden">';
    echo '<div id="bccm-progress-bar" style="background:linear-gradient(90deg,#6366f1,#8b5cf6);height:100%;width:0%;transition:width 0.4s;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px"></div>';
    echo '</div>';
    echo '</div>';

    // -- Log --
    echo '<div id="bccm-gen-log" style="max-height:280px;overflow-y:auto;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:12px;font-family:monospace;font-size:12px;line-height:1.7;display:none"></div>';

    echo '</div>';

    // JavaScript
    ?>
    <script>
    (function() {
      var userId     = <?php echo (int)$user_id; ?>;
      var coacheeId  = <?php echo (int)$coachee_id; ?>;
      var coachType  = <?php echo wp_json_encode($selected_type); ?>;
      var ajaxUrl    = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
      var nonce      = <?php echo wp_json_encode(wp_create_nonce('bccm_map_gen')); ?>;
      var allGens    = <?php echo $gen_data; ?>;   // [{key,fn,label,column},...]

      // -- DOM refs --
      var $list     = document.getElementById('bccm-gen-list');
      var $actions  = document.getElementById('bccm-gen-actions');
      var $startBtn = document.getElementById('bccm-start-gen');
      var $resumeBtn= document.getElementById('bccm-resume-gen');
      var $progress = document.getElementById('bccm-gen-progress');
      var $bar      = document.getElementById('bccm-progress-bar');
      var $log      = document.getElementById('bccm-gen-log');

      // Statuses: { gen_key => 'pending'|'running'|'success'|'error' }
      var statuses  = {};
      var isRunning = false;

      /* -------- helpers -------- */
      function addLog(msg, type) {
        var colors = {info:'#6b7280', success:'#10b981', error:'#ef4444', warn:'#f59e0b'};
        $log.style.display = '';
        var d = document.createElement('div');
        d.style.color = colors[type] || colors.info;
        d.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
        $log.appendChild(d);
        $log.scrollTop = $log.scrollHeight;
      }

      function setProgress(cur, total) {
        var pct = total > 0 ? Math.round((cur / total) * 100) : 0;
        $bar.style.width = pct + '%';
        $bar.textContent = pct + '%';
      }

      /* -------- render generator list -------- */
      function renderList() {
        $list.innerHTML = '';
        var hasFailed = false;
        allGens.forEach(function(g, i) {
          var st = statuses[g.key] || 'pending';
          if (st === 'error') hasFailed = true;

          var badge = {
            pending: '<span style="background:#e5e7eb;color:#6b7280;font-size:11px;padding:2px 7px;border-radius:10px">⬜ Chưa tạo</span>',
            running: '<span style="background:#dbeafe;color:#1d4ed8;font-size:11px;padding:2px 7px;border-radius:10px">🔄 Đang tạo...</span>',
            success: '<span style="background:#dcfce7;color:#15803d;font-size:11px;padding:2px 7px;border-radius:10px">✅ Thành công</span>',
            error:   '<span style="background:#fee2e2;color:#b91c1c;font-size:11px;padding:2px 7px;border-radius:10px">❌ Lỗi</span>',
          }[st] || '';

          var errTip = '';
          if (st === 'error' && statuses['_err_' + g.key]) {
            errTip = ' <span style="color:#ef4444;font-size:11px" title="' +
              statuses['_err_' + g.key].replace(/"/g,'&quot;') + '">⚠️</span>';
          }

          // Nút tạo (lại) cho từng bản đồ — hiện ở mọi trạng thái trừ khi đang chạy batch hoặc đang running
          var regenBtn = '';
          if (st !== 'running' && !isRunning) {
            var btnLabel = (st === 'success' || st === 'error') ? '🔄 Tạo lại' : '▶️ Tạo';
            regenBtn = ' <button type="button" class="bccm-regen-btn" data-idx="' + i + '" ' +
              'style="background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;border-radius:8px;padding:2px 8px;font-size:11px;cursor:pointer;white-space:nowrap">' +
              btnLabel + '</button>';
          }

          var row = document.createElement('div');
          row.id  = 'bccm-gen-row-' + g.key;
          row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px';
          row.innerHTML =
            '<span style="color:#94a3b8;min-width:20px;text-align:right">' + (i+1) + '</span>' +
            '<span style="flex:1;color:#1e293b">' + g.label + '</span>' +
            badge + errTip + regenBtn;
          $list.appendChild(row);
        });

        // Hien/an Resume button
        $resumeBtn.style.display = hasFailed ? '' : 'none';
      }

      function setBadge(key, st, errMsg) {
        statuses[key] = st;
        if (errMsg) statuses['_err_' + key] = errMsg;
        renderList();
      }

      /* -------- AJAX -------- */
      function callAjax(params) {
        return new Promise(function(resolve, reject) {
          var xhr = new XMLHttpRequest();
          xhr.open('POST', ajaxUrl, true);
          xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
          xhr.timeout = 160000; // 160s = buffer cho set_time_limit(150)
          xhr.ontimeout = function() { reject('Timeout (160s)'); };
          xhr.onerror   = function() { reject('Network error'); };
          xhr.onload    = function() {
            if (xhr.status !== 200) {
              reject('HTTP ' + xhr.status + ' | ' + xhr.responseText.substring(0, 200));
              return;
            }
            var raw = xhr.responseText;
            var start = raw.indexOf('{');
            if (start > 0) raw = raw.substring(start);
            var parsed;
            try { parsed = JSON.parse(raw); } catch(e) {
              reject('JSON: ' + e.message + ' | ' + raw.substring(0, 150));
              return;
            }
            if (parsed && parsed.success) {
              resolve(parsed.data);
            } else {
              var msg = (parsed && parsed.data && parsed.data.message)
                ? parsed.data.message : JSON.stringify(parsed).substring(0, 200);
              reject(msg);
            }
          };
          var body = Object.keys(params).map(function(k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
          }).join('&');
          xhr.send(body);
        });
      }

      /* -------- load saved statuses on page load -------- */
      function loadStatuses() {
        callAjax({ action:'bccm_get_gen_statuses', nonce:nonce, user_id:userId })
          .then(function(data) {
            var saved = data.statuses || {};
            Object.keys(saved).forEach(function(k) {
              if (k.indexOf('_err_') === 0) {
                statuses[k] = saved[k];
              } else {
                statuses[k] = saved[k].status || 'pending';
                if (saved[k].error) statuses['_err_' + k] = saved[k].error;
              }
            });
            renderList();
          })
          .catch(function() { renderList(); }); // fallback: all pending
      }

      /* -------- run generators sequentially -------- */
      function runSequential(queue, total, doneCount) {
        if (queue.length === 0) {
          finishGeneration();
          return;
        }
        var gen = queue[0];
        var remaining = queue.slice(1);

        setBadge(gen.key, 'running', null);
        addLog('⏳ Đang tạo: ' + gen.label + ' (' + (doneCount+1) + '/' + total + ')...', 'info');

        callAjax({
          action:     'bccm_run_single_generator',
          nonce:      nonce,
          user_id:    userId,
          fn:         gen.fn,
          gen_key:    gen.key,
          gen_label:  gen.label,
        }).then(function(data) {
          setProgress(doneCount + 1, total);
          if (data.success) {
            setBadge(gen.key, 'success', null);
            addLog('✅ ' + gen.label, 'success');
          } else {
            setBadge(gen.key, 'error', data.error);
            addLog('❌ ' + gen.label + ': ' + data.error, 'error');
          }
          runSequential(remaining, total, doneCount + 1);
        }).catch(function(err) {
          setProgress(doneCount + 1, total);
          setBadge(gen.key, 'error', err);
          addLog('❌ ' + gen.label + ' (AJAX error): ' + err, 'error');
          runSequential(remaining, total, doneCount + 1);
        });
      }

      /* -------- start / resume -------- */
      function startRun(onlyFailed) {
        if (isRunning) return;
        isRunning = true;
        $progress.style.display = '';
        $log.style.display = '';
        $log.innerHTML = '';
        $startBtn.disabled  = true;
        $resumeBtn.disabled = true;

        var queue = allGens.filter(function(g) {
          return !onlyFailed || statuses[g.key] !== 'success';
        });

        if (queue.length === 0) {
          addLog('Tất cả bản đồ đã tạo thành công rồi!', 'success');
          finishGeneration();
          return;
        }

        setProgress(0, queue.length);

        if (!onlyFailed) {
          // Reset tất cả status về pending
          allGens.forEach(function(g) { statuses[g.key] = 'pending'; delete statuses['_err_' + g.key]; });
          renderList();
          // Xoa transient cu tren server
          callAjax({ action:'bccm_clear_gen_statuses', nonce:nonce, user_id:userId })
            .catch(function(){});
          addLog('🚀 Generate tất cả ' + queue.length + ' bản đồ...', 'info');
        } else {
          addLog('🔁 Resume ' + queue.length + ' bản đồ bị lỗi...', 'warn');
        }

        runSequential(queue, queue.length, 0);
      }

      function finishGeneration() {
        isRunning = false;
        $progress.style.display = 'none';
        $startBtn.disabled  = false;
        $resumeBtn.disabled = false;
        renderList(); // re-render để show resume button nếu có lỗi

        var failed = allGens.filter(function(g) { return statuses[g.key] === 'error'; }).length;
        var success = allGens.filter(function(g) { return statuses[g.key] === 'success'; }).length;
        if (failed > 0) {
          addLog('⚠️ Xong: ' + success + ' thành công, ' + failed + ' lỗi. Nhấn "Resume failed" để thử lại.', 'warn');
        } else {
          addLog('🎉 Tất cả ' + success + ' bản đồ đã được tạo thành công!', 'success');
        }
      }

      /* -------- run single generator (tạo lại từng bản đồ) -------- */
      function runSingle(gen) {
        if (isRunning) return;
        isRunning = true;
        $startBtn.disabled  = true;
        $resumeBtn.disabled = true;
        $log.style.display  = '';

        setBadge(gen.key, 'running', null);
        addLog('🔄 Tạo lại: ' + gen.label + '...', 'info');

        callAjax({
          action:     'bccm_run_single_generator',
          nonce:      nonce,
          user_id:    userId,
          fn:         gen.fn,
          gen_key:    gen.key,
          gen_label:  gen.label,
        }).then(function(data) {
          if (data.success) {
            setBadge(gen.key, 'success', null);
            addLog('✅ ' + gen.label + ' — Tạo lại thành công!', 'success');
          } else {
            setBadge(gen.key, 'error', data.error);
            addLog('❌ ' + gen.label + ': ' + data.error, 'error');
          }
        }).catch(function(err) {
          setBadge(gen.key, 'error', err);
          addLog('❌ ' + gen.label + ' (AJAX error): ' + err, 'error');
        }).finally(function() {
          isRunning = false;
          $startBtn.disabled  = false;
          $resumeBtn.disabled = false;
          renderList();
        });
      }

      /* -------- event listeners -------- */
      $startBtn.addEventListener('click', function() { startRun(false); });
      $resumeBtn.addEventListener('click', function() { startRun(true); });

      // Delegate click cho nút "Tạo lại" trong danh sách
      $list.addEventListener('click', function(e) {
        var btn = e.target.closest('.bccm-regen-btn');
        if (!btn || isRunning) return;
        var idx = parseInt(btn.getAttribute('data-idx'), 10);
        if (!isNaN(idx) && allGens[idx]) runSingle(allGens[idx]);
      });

      /* -------- init -------- */
      loadStatuses();
    })();
    </script>
    <?php
  }

  // Action buttons
  echo '<p style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid #e2e8f0">';

  // ── Public Map + PDF Buttons ──
  if (function_exists('bccm_ensure_action_plan')) {
    $public_key = bccm_ensure_action_plan($coachee_id);
    if ($public_key) {
      $public_url = bccm_public_map_url($public_key);
      echo '<a href="' . esc_url($public_url) . '" target="_blank" class="button button-hero" style="background:#7c3aed;color:#fff;border-color:#6d28d9">👁️ Xem bản đồ public</a>';
      echo '<a href="' . esc_url($public_url) . '" target="_blank" class="button button-hero" style="background:#059669;color:#fff;border-color:#047857">📄 Xuất PDF</a>';
    }
  }

  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_my_profile')) . '" class="button button-hero">← Bước 1: Hồ sơ cá nhân</a>';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_step3_character')) . '" class="button button-hero" style="margin-left:auto">Bước 3: Tạo Character →</a>';
  echo '</p>';

  echo '</form>';

  // ── Life Map summary if exists ──
  $life_map = json_decode($coachee['mental_json'] ?? '[]', true) ?: [];
  if (!empty($life_map['identity'])) {
    echo '<div class="postbox" style="padding:12px 16px;margin:20px 0;border-left:4px solid #f59e0b">';
    echo '<strong>🗺️ Life Map đã tạo:</strong> ';
    if (!empty($life_map['identity']['life_stage'])) {
      echo 'Giai đoạn: <em>' . esc_html($life_map['identity']['life_stage']) . '</em> | ';
    }
    if (!empty($life_map['identity']['core_values'])) {
      echo 'Giá trị: <em>' . esc_html(implode(', ', $life_map['identity']['core_values'])) . '</em>';
    }
    $lm_url = admin_url('admin.php?page=bccm_lifemap_plan&coachee_id=' . $coachee_id);
    echo ' <a href="' . esc_url($lm_url) . '" class="button button-small" style="margin-left:10px">Xem Life Map đầy đủ →</a>';
    echo '</div>';
  }

  echo '</div>'; // .wrap
}
