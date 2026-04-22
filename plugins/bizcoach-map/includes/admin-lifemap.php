<?php
/**
 * BizCoach Map – Life Map Plan Builder & AI Companion
 *
 * Sub-menu: Xây kế hoạch đồng hành + Chọn AI character
 * Chức năng:
 * 1. Chọn coachee → xem Life Map tóm tắt
 * 2. Chọn AI character (từ bizcity_knowledge_characters)
 * 3. Setup lịch nhắc nhở (hàng ngày, hàng tuần)
 * 4. Kích hoạt cron reminders
 * 5. Đồng bộ Life Map sang Knowledge RAG
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * ADMIN MENU REGISTRATION
 * =====================================================================*/
add_action('admin_menu', function () {
  // Phase 7.1: ẩn submenu khỏi navigation chính, nhưng vẫn giữ page callable trực tiếp.
  add_submenu_page(
    null,
    'Bước 5: Life Map – Kế hoạch đồng hành',
    'Bước 5: Life Map Plan',
    'manage_options',
    'bccm_lifemap_plan',
    'bccm_admin_lifemap_plan',
    35
  );
  add_submenu_page(
    null,
    'Bước 6: Cron & Reminders',
    'Bước 6: Nhắc nhở & AI',
    'manage_options',
    'bccm_cron_reminders',
    'bccm_admin_cron_reminders',
    36
  );
});

/* =====================================================================
 * 1. LIFE MAP PLAN BUILDER PAGE
 * =====================================================================*/
function bccm_admin_lifemap_plan() {
  if (!current_user_can('manage_options')) return;

  global $wpdb;
  $t = bccm_tables();

  // Load knowledge characters (nếu plugin knowledge đã active)
  $characters     = [];
  $characters_tbl = $wpdb->prefix . 'bizcity_characters';
  if ($wpdb->get_var("SHOW TABLES LIKE '$characters_tbl'") === $characters_tbl) {
    $characters = $wpdb->get_results("SELECT id, name, avatar, system_prompt FROM $characters_tbl ORDER BY id DESC", ARRAY_A);
  }
  $default_avatar = plugins_url('assets/icon/Dorami.png', dirname(__FILE__));

  // Selected coachee
  $coachee_id = intval($_GET['coachee_id'] ?? 0);
  $coachee    = null;
  $life_map   = [];
  $milestone  = [];
  $reminders_config = [];

  if ($coachee_id) {
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id
    ), ARRAY_A);

    if ($coachee) {
      $life_map  = json_decode($coachee['mental_json'] ?? '[]', true) ?: [];
      $milestone = json_decode($coachee['bizcoach_json'] ?? '[]', true) ?: [];

      // Load reminders config
      $reminders_config = get_option("bccm_reminders_config_{$coachee_id}", [
        'enabled'        => false,
        'character_id'   => 0,
        'daily_time'     => '08:00',
        'weekly_day'     => 'monday',
        'weekly_time'    => '09:00',
        'channels'       => ['email'],
        'start_date'     => '',
        'current_week'   => 1,
      ]);
    }
  }

  // === HANDLE SAVE ===
  if (!empty($_POST['bccm_save_lifemap_plan']) && check_admin_referer('bccm_lifemap_plan')) {
    $config = [
      'enabled'        => !empty($_POST['reminder_enabled']),
      'character_id'   => intval($_POST['character_id'] ?? 0),
      'daily_time'     => sanitize_text_field($_POST['daily_time'] ?? '08:00'),
      'weekly_day'     => sanitize_text_field($_POST['weekly_day'] ?? 'monday'),
      'weekly_time'    => sanitize_text_field($_POST['weekly_time'] ?? '09:00'),
      'channels'       => array_map('sanitize_text_field', (array)($_POST['channels'] ?? ['email'])),
      'start_date'     => sanitize_text_field($_POST['start_date'] ?? current_time('Y-m-d')),
      'current_week'   => intval($_POST['current_week'] ?? 1),
    ];

    update_option("bccm_reminders_config_{$coachee_id}", $config);
    $reminders_config = $config;

    // Đồng bộ Life Map sang Knowledge nếu có
    if (!empty($life_map) && $coachee) {
      bccm_sync_lifemap_to_knowledge($coachee_id, $life_map, $coachee);

      // Nếu chọn character, gán character_id vào knowledge source
      if (!empty($config['character_id'])) {
        $table_sources = $wpdb->prefix . 'bizcity_knowledge_sources';
        $source_url    = "lifemap://coachee/{$coachee_id}";
        $wpdb->update($table_sources, [
          'character_id' => $config['character_id'],
        ], ['url' => $source_url]);
      }
    }

    // Schedule/Unschedule cron
    if ($config['enabled']) {
      bccm_schedule_reminders($coachee_id, $config);
    } else {
      bccm_unschedule_reminders($coachee_id);
    }

    echo '<div class="updated"><p>Đã lưu cấu hình Life Map Plan.</p></div>';
  }

  // === HANDLE: Generate All Maps (dynamic per coach type) ===
  if (!empty($_POST['bccm_generate_all_maps']) && check_admin_referer('bccm_lifemap_plan') && $coachee_id) {
    $coach_type_gen = $coachee['coach_type'] ?? 'biz_coach';
    $steps = bccm_generators_for_type($coach_type_gen);

    foreach ($steps as $step) {
      $fn_name = $step['fn'] ?? '';
      $label   = $step['label'] ?? $step['key'] ?? '';
      if (!empty($fn_name) && function_exists($fn_name)) {
        $result = call_user_func($fn_name, $coachee_id);
        if (is_wp_error($result)) {
          echo '<div class="error"><p>Lỗi ' . esc_html($label) . ': ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
          echo '<div class="updated"><p>✅ ' . esc_html($label) . ' – Thành công!</p></div>';
        }
      }
    }

    // Refresh data
    $coachee   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
    $life_map  = json_decode($coachee['mental_json'] ?? '[]', true) ?: [];
    $milestone = json_decode($coachee['bizcoach_json'] ?? '[]', true) ?: [];
  }

  // === HANDLE: Individual Generator Action ===
  if (!empty($_POST['bccm_action']) && check_admin_referer('bccm_lifemap_plan') && $coachee_id) {
    $action = sanitize_text_field($_POST['bccm_action']);
    $coach_type_gen = $coachee['coach_type'] ?? 'biz_coach';
    $gens = bccm_generators_for_type($coach_type_gen);
    $gens_map = [];
    foreach ($gens as $g) {
      if (!empty($g['key']) && !empty($g['fn'])) $gens_map[$g['key']] = $g['fn'];
    }

    if ($action === 'gen_plan' && function_exists('bccm_generate_plan_from_answers')) {
      $ok = bccm_generate_plan_from_answers($coachee_id);
      if (is_wp_error($ok)) {
        echo '<div class="error"><p>Lỗi: ' . esc_html($ok->get_error_message()) . '</p></div>';
      } else {
        $pub_url = $ok['public_url'] ?? '';
        echo '<div class="updated"><p>✅ Đã tạo bản đồ Public.' . ($pub_url ? ' <a href="' . esc_url($pub_url) . '" target="_blank">Xem →</a>' : '') . '</p></div>';
      }
    } elseif (isset($gens_map[$action]) && function_exists($gens_map[$action])) {
      $result = call_user_func($gens_map[$action], $coachee_id);
      if (is_wp_error($result)) {
        echo '<div class="error"><p>Lỗi: ' . esc_html($result->get_error_message()) . '</p></div>';
      } else {
        echo '<div class="updated"><p>✅ Đã chạy generator thành công!</p></div>';
      }
    }

    // Refresh data
    $coachee   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
    $life_map  = json_decode($coachee['mental_json'] ?? '[]', true) ?: [];
    $milestone = json_decode($coachee['bizcoach_json'] ?? '[]', true) ?: [];
  }

  // === RENDER UI ===
  echo '<div class="wrap bccm-wrap">';
  bccm_render_workflow_steps();
  echo '<h1>🗺️ Life Map – Kế Hoạch Đồng Hành</h1>';
  echo '<p class="description">Xây dựng bản đồ cuộc đời, chọn AI character đồng hành, setup nhắc nhở tự động.</p>';

  // === Coachee Selector ===
  $coachees = $wpdb->get_results("SELECT id, full_name, coach_type, dob FROM {$t['profiles']} ORDER BY id DESC LIMIT 100", ARRAY_A);
  echo '<form method="get" style="margin:0 0 20px">';
  echo '<input type="hidden" name="page" value="bccm_lifemap_plan"/>';
  echo '<label><strong>Chọn Coachee:</strong></label> ';
  echo '<select name="coachee_id" onchange="this.form.submit()" style="min-width:300px">';
  echo '<option value="">-- Chọn coachee --</option>';
  foreach ($coachees as $c) {
    $sel = ($c['id'] == $coachee_id) ? ' selected' : '';
    echo '<option value="' . intval($c['id']) . '"' . $sel . '>' . esc_html($c['full_name']) . ' (' . esc_html($c['coach_type']) . ')</option>';
  }
  echo '</select> ';
  echo '</form>';

  if (!$coachee) {
    echo '<div class="notice notice-info"><p>Vui lòng chọn coachee để bắt đầu.</p></div>';
    echo '</div>';
    return;
  }

  // === Main Form ===
  echo '<form method="post">';
  wp_nonce_field('bccm_lifemap_plan');
  echo '<input type="hidden" name="coachee_id" value="' . intval($coachee_id) . '"/>';

  echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">';

  // LEFT: Life Map Summary
  echo '<div class="postbox" style="padding:16px">';
  echo '<h2>📋 Tóm Tắt Life Map</h2>';

  // Public map link
  $pub_url = $coachee['public_url'] ?? '';
  if (!empty($pub_url)) {
    echo '<div style="background:linear-gradient(135deg,#ecfdf5,#f0f9ff);border-radius:10px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">';
    echo '<span style="font-size:20px">🗺️</span>';
    echo '<div style="flex:1;min-width:200px">';
    echo '<strong style="font-size:13px;color:#059669">Bản đồ Public đã tạo</strong><br/>';
    echo '<a href="' . esc_url($pub_url) . '" target="_blank" style="font-size:12px;word-break:break-all;color:#6366f1">' . esc_html($pub_url) . '</a>';
    echo '</div>';
    echo '<a href="' . esc_url($pub_url) . '" target="_blank" class="button" style="white-space:nowrap">Xem bản đồ ↗</a>';
    echo '</div>';
  }

  if (empty($life_map)) {
    echo '<p style="color:#888">Chưa có Life Map. Bấm "Generate All Maps" để tạo.</p>';
  } else {
    // Identity
    if (!empty($life_map['identity'])) {
      $id = $life_map['identity'];
      echo '<p><strong>Giai đoạn:</strong> ' . esc_html($id['life_stage'] ?? '—') . '</p>';
      if (!empty($id['core_values'])) {
        echo '<p><strong>Giá trị cốt lõi:</strong> ' . esc_html(implode(', ', $id['core_values'])) . '</p>';
      }
    }

    // Pain points
    if (!empty($life_map['pain_points'])) {
      echo '<h4>🔥 Nỗi đau chính</h4><ul>';
      foreach (array_slice($life_map['pain_points'], 0, 3) as $pp) {
        echo '<li><strong>' . esc_html($pp['area'] ?? '') . '</strong>: ' . esc_html($pp['description'] ?? '') . ' (ảnh hưởng: ' . intval($pp['impact_level'] ?? 0) . '/10)</li>';
      }
      echo '</ul>';
    }

    // Coaching strategy
    if (!empty($life_map['coaching_strategy'])) {
      $cs = $life_map['coaching_strategy'];
      echo '<h4>🤖 Chiến lược đồng hành</h4>';
      echo '<p><strong>Tone:</strong> ' . esc_html($cs['tone'] ?? '') . '</p>';
      echo '<p><strong>Check-in:</strong> ' . esc_html($cs['check_in_frequency'] ?? '') . '</p>';
    }
  }

  // Per-coach-type generators – Job Monitor (AJAX)
  $coach_type_for_gen = $coachee['coach_type'] ?? 'biz_coach';
  $gens_for_lifemap   = bccm_generators_for_type($coach_type_for_gen);

  // Thêm "Tạo bản đồ Public" vào cuối danh sách nếu hàm tồn tại
  if (function_exists('bccm_generate_plan_from_answers')) {
    $gens_for_lifemap[] = array(
      'key'    => 'gen_plan',
      'fn'     => 'bccm_generate_plan_from_answers',
      'label'  => 'Tạo bản đồ Public',
      'column' => 'public_url',
    );
  }

  $lm_gen_data = wp_json_encode(array_values($gens_for_lifemap), JSON_UNESCAPED_UNICODE);

  echo '<hr/>';
  echo '<h4>🗺️ Generate bản đồ Coach</h4>';
  echo '<p class="description">Các bản đồ sẽ được tạo tuần tự qua AJAX.</p>';

  echo '<div id="bccm-map-generator" style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin:16px 0">';

  // Danh sách generators với badges
  echo '<div id="bccm-gen-list" style="margin-bottom:16px"></div>';

  // Action buttons
  echo '<div id="bccm-gen-actions" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">';
  echo '<button type="button" id="bccm-start-gen" class="button button-primary button-hero">▶️ Generate tất cả</button>';
  echo '<button type="button" id="bccm-resume-gen" class="button button-hero" style="display:none;background:#f59e0b;color:#fff;border-color:#d97706">🔁 Resume failed</button>';
  echo '</div>';

  // Progress bar
  echo '<div id="bccm-gen-progress" style="display:none;margin-bottom:12px">';
  echo '<div style="background:#e5e7eb;height:24px;border-radius:12px;overflow:hidden">';
  echo '<div id="bccm-progress-bar" style="background:linear-gradient(90deg,#6366f1,#8b5cf6);height:100%;width:0%;transition:width 0.4s;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px"></div>';
  echo '</div>';
  echo '</div>';

  // Log
  echo '<div id="bccm-gen-log" style="max-height:280px;overflow-y:auto;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:12px;font-family:monospace;font-size:12px;line-height:1.7;display:none"></div>';

  echo '</div>';

  ?>
  <script>
  (function() {
    var coacheeId  = <?php echo (int)$coachee_id; ?>;
    var ajaxUrl    = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var nonce      = <?php echo wp_json_encode(wp_create_nonce('bccm_map_gen')); ?>;
    var allGens    = <?php echo $lm_gen_data; ?>;

    var $list     = document.getElementById('bccm-gen-list');
    var $startBtn = document.getElementById('bccm-start-gen');
    var $resumeBtn= document.getElementById('bccm-resume-gen');
    var $progress = document.getElementById('bccm-gen-progress');
    var $bar      = document.getElementById('bccm-progress-bar');
    var $log      = document.getElementById('bccm-gen-log');

    var statuses  = {};
    var isRunning = false;

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

      $resumeBtn.style.display = hasFailed ? '' : 'none';
    }

    function setBadge(key, st, errMsg) {
      statuses[key] = st;
      if (errMsg) statuses['_err_' + key] = errMsg;
      renderList();
    }

    function callAjax(params) {
      return new Promise(function(resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 160000;
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

    function loadStatuses() {
      callAjax({ action:'bccm_get_gen_statuses', nonce:nonce, coachee_id:coacheeId })
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
        .catch(function() { renderList(); });
    }

    function runSequential(queue, total, doneCount) {
      if (queue.length === 0) { finishGeneration(); return; }
      var gen = queue[0];
      var remaining = queue.slice(1);

      setBadge(gen.key, 'running', null);
      addLog('⏳ Đang tạo: ' + gen.label + ' (' + (doneCount+1) + '/' + total + ')...', 'info');

      callAjax({
        action:     'bccm_run_single_generator',
        nonce:      nonce,
        coachee_id: coacheeId,
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
        finishGeneration(); return;
      }
      setProgress(0, queue.length);
      if (!onlyFailed) {
        allGens.forEach(function(g) { statuses[g.key] = 'pending'; delete statuses['_err_' + g.key]; });
        renderList();
        callAjax({ action:'bccm_clear_gen_statuses', nonce:nonce, coachee_id:coacheeId }).catch(function(){});
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
      renderList();
      var failed  = allGens.filter(function(g) { return statuses[g.key] === 'error'; }).length;
      var success = allGens.filter(function(g) { return statuses[g.key] === 'success'; }).length;
      if (failed > 0) {
        addLog('⚠️ Xong: ' + success + ' thành công, ' + failed + ' lỗi. Nhấn "Resume failed" để thử lại.', 'warn');
      } else {
        addLog('🎉 Tất cả ' + success + ' bản đồ đã được tạo thành công!', 'success');
      }
    }

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
        coachee_id: coacheeId,
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

    $startBtn.addEventListener('click', function() { startRun(false); });
    $resumeBtn.addEventListener('click', function() { startRun(true); });
    $list.addEventListener('click', function(e) {
      var btn = e.target.closest('.bccm-regen-btn');
      if (!btn || isRunning) return;
      var idx = parseInt(btn.getAttribute('data-idx'), 10);
      if (!isNaN(idx) && allGens[idx]) runSingle(allGens[idx]);
    });

    loadStatuses();
  })();
  </script>
  <?php

  echo '</div>';

  // RIGHT: Config panel
  echo '<div class="postbox" style="padding:16px">';
  echo '<h2>⚙️ Cấu Hình Đồng Hành</h2>';

  // Character selection – visual card grid
  echo '<div style="margin-bottom:20px">';
  echo '<h3 style="margin:0 0 12px;font-size:15px">AI Character đồng hành</h3>';
  if (!empty($characters)) {
    $sel_char_id = intval($reminders_config['character_id'] ?? 0);
    echo '<input type="hidden" name="character_id" id="bccm-char-input" value="' . $sel_char_id . '"/>';
    echo '<div class="bccm-char-grid">';
    foreach ($characters as $ch) {
      $avatar_url = !empty($ch['avatar']) ? esc_url($ch['avatar']) : esc_url($default_avatar);
      $is_sel = (intval($ch['id']) === $sel_char_id);
      $cls = 'bccm-char-card' . ($is_sel ? ' bccm-char-card--active' : '');
      echo '<div class="' . $cls . '" data-char-id="' . intval($ch['id']) . '" tabindex="0">';
      echo '<img src="' . $avatar_url . '" alt="' . esc_attr($ch['name']) . '" class="bccm-char-avatar"/>';
      echo '<span class="bccm-char-name">' . esc_html($ch['name']) . '</span>';
      echo '<span class="bccm-char-check">&#10003;</span>';
      echo '</div>';
    }
    echo '</div>';
    echo '<p class="description" style="margin-top:8px">Nhấn chọn character đồng hành. Character sẽ nhận Life Map context từ plugin Knowledge.</p>';
    // Inline JS for card selection
    echo '<script>'
      . 'document.addEventListener("DOMContentLoaded",function(){'
      . '  var cards=document.querySelectorAll(".bccm-char-card");'
      . '  var inp=document.getElementById("bccm-char-input");'
      . '  cards.forEach(function(c){'
      . '    c.addEventListener("click",function(){'
      . '      cards.forEach(function(x){x.classList.remove("bccm-char-card--active")});'
      . '      c.classList.add("bccm-char-card--active");'
      . '      inp.value=c.getAttribute("data-char-id");'
      . '    });'
      . '  });'
      . '});'
      . '</script>';
  } else {
    echo '<div class="bccm-char-empty">';
    echo '<img src="' . esc_url($default_avatar) . '" alt="Dorami" style="width:64px;height:64px;border-radius:50%;opacity:.5;margin-bottom:8px"/>';
    echo '<p>Chưa có character nào.<br/>Vui lòng tạo character trong mục <strong>Knowledge</strong>.</p>';
    echo '</div>';
    echo '<input type="hidden" name="character_id" value="0"/>';
  }
  echo '</div>';

  echo '<table class="form-table">';

  // Reminder enabled
  echo '<tr><th>Kích hoạt nhắc nhở</th><td>';
  echo '<label><input type="checkbox" name="reminder_enabled" value="1"' . checked($reminders_config['enabled'] ?? false, true, false) . '/> Bật cron nhắc nhở tự động</label>';
  echo '</td></tr>';

  // Start date
  echo '<tr><th>Ngày bắt đầu</th><td>';
  echo '<input type="date" name="start_date" value="' . esc_attr($reminders_config['start_date'] ?? current_time('Y-m-d')) . '"/>';
  echo '</td></tr>';

  // Current week
  echo '<tr><th>Tuần hiện tại</th><td>';
  echo '<input type="number" name="current_week" value="' . intval($reminders_config['current_week'] ?? 1) . '" min="1" max="12" style="width:80px"/>';
  echo ' <span class="description">(1–12)</span>';
  echo '</td></tr>';

  // Daily reminder time
  echo '<tr><th>Nhắc hàng ngày lúc</th><td>';
  echo '<input type="time" name="daily_time" value="' . esc_attr($reminders_config['daily_time'] ?? '08:00') . '"/>';
  echo '</td></tr>';

  // Weekly reminder
  echo '<tr><th>Nhắc hàng tuần</th><td>';
  $days = ['monday' => 'Thứ 2', 'tuesday' => 'Thứ 3', 'wednesday' => 'Thứ 4', 'thursday' => 'Thứ 5', 'friday' => 'Thứ 6', 'saturday' => 'Thứ 7', 'sunday' => 'Chủ nhật'];
  echo '<select name="weekly_day">';
  foreach ($days as $dv => $dl) {
    echo '<option value="' . $dv . '"' . selected($reminders_config['weekly_day'] ?? 'monday', $dv, false) . '>' . $dl . '</option>';
  }
  echo '</select> ';
  echo '<input type="time" name="weekly_time" value="' . esc_attr($reminders_config['weekly_time'] ?? '09:00') . '"/>';
  echo '</td></tr>';

  // Channels
  echo '<tr><th>Kênh nhắc nhở</th><td>';
  $available_channels = ['email' => 'Email', 'telegram' => 'Telegram', 'zalo' => 'Zalo'];
  $current_channels   = (array)($reminders_config['channels'] ?? ['email']);
  foreach ($available_channels as $ck => $cl) {
    $checked = in_array($ck, $current_channels) ? ' checked' : '';
    echo '<label style="margin-right:16px"><input type="checkbox" name="channels[]" value="' . $ck . '"' . $checked . '/> ' . $cl . '</label>';
  }
  echo '</td></tr>';

  echo '</table>';

  echo '<p style="margin-top:16px">';
  echo '<button class="button button-primary" name="bccm_save_lifemap_plan" value="1">💾 Lưu Cấu Hình</button>';
  echo '</p>';
  echo '</div>';

  echo '</div>'; // grid

  // === Milestone Calendar Preview ===
  if (!empty($milestone['phases'])) {
    echo '<div class="postbox" style="padding:16px;margin-top:20px">';
    echo '<h2>📅 Milestone Calendar Preview</h2>';

    foreach ($milestone['phases'] as $pi => $phase) {
      echo '<h3 style="color:#059669">' . esc_html($phase['title'] ?? "Phase " . ($pi + 1)) . '</h3>';
      echo '<table class="widefat striped"><thead><tr>';
      echo '<th style="width:80px">Tuần</th><th>Theme</th><th>Mục tiêu</th><th>Cột mốc</th><th>Check-in</th>';
      echo '</tr></thead><tbody>';

      foreach ($phase['weeks'] ?? [] as $wk) {
        $week_num = $wk['week'] ?? '';
        echo '<tr>';
        echo '<td><strong>W' . esc_html($week_num) . '</strong></td>';
        echo '<td>' . esc_html($wk['theme'] ?? $wk['title'] ?? '') . '</td>';
        echo '<td>' . esc_html($wk['goal'] ?? '') . '</td>';
        echo '<td>' . esc_html($wk['milestone'] ?? '') . '</td>';
        echo '<td style="font-size:12px;color:#666">' . esc_html($wk['coach_check_in'] ?? '') . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';
  }

  echo '</form>';
  echo '</div>'; // .wrap
}

/* =====================================================================
 * 2. CRON & REMINDERS MANAGEMENT PAGE
 * =====================================================================*/
function bccm_admin_cron_reminders() {
  if (!current_user_can('manage_options')) return;

  global $wpdb;
  $t = bccm_tables();

  // Get all active reminders
  $coachees = $wpdb->get_results("SELECT id, full_name, coach_type FROM {$t['profiles']} ORDER BY id DESC", ARRAY_A);

  echo '<div class="wrap bccm-wrap">';
  bccm_render_workflow_steps();
  echo '<h1>⏰ Cron & Reminders</h1>';

  // Scheduled events overview
  echo '<div class="postbox" style="padding:16px">';
  echo '<h2>📋 Active Reminder Schedules</h2>';

  echo '<table class="widefat striped">';
  echo '<thead><tr><th>Coachee</th><th>Character</th><th>Daily</th><th>Weekly</th><th>Tuần hiện tại</th><th>Status</th><th>Actions</th></tr></thead>';
  echo '<tbody>';

  $has_any = false;
  foreach ($coachees as $c) {
    $config = get_option("bccm_reminders_config_{$c['id']}", []);
    if (empty($config) || empty($config['enabled'])) continue;
    $has_any = true;

    // Get character name
    $char_name = '—';
    if (!empty($config['character_id'])) {
      $char_tbl = $wpdb->prefix . 'bizcity_characters';
      if ($wpdb->get_var("SHOW TABLES LIKE '$char_tbl'") === $char_tbl) {
        $char_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $char_tbl WHERE id=%d", $config['character_id'])) ?: '—';
      }
    }

    $daily_next = wp_next_scheduled('bccm_daily_reminder', [$c['id']]);
    $weekly_next = wp_next_scheduled('bccm_weekly_reminder', [$c['id']]);

    echo '<tr>';
    echo '<td><strong>' . esc_html($c['full_name']) . '</strong></td>';
    echo '<td>' . esc_html($char_name) . '</td>';
    echo '<td>' . esc_html($config['daily_time'] ?? '') . ($daily_next ? '<br><small>Next: ' . date('d/m H:i', $daily_next) . '</small>' : '') . '</td>';
    echo '<td>' . esc_html(($config['weekly_day'] ?? '') . ' ' . ($config['weekly_time'] ?? '')) . ($weekly_next ? '<br><small>Next: ' . date('d/m H:i', $weekly_next) . '</small>' : '') . '</td>';
    echo '<td>Tuần ' . intval($config['current_week'] ?? 1) . '/12</td>';
    echo '<td><span style="color:green;font-weight:700">● Active</span></td>';
    echo '<td><a class="button" href="' . esc_url(admin_url('admin.php?page=bccm_lifemap_plan&coachee_id=' . $c['id'])) . '">Edit</a></td>';
    echo '</tr>';
  }

  if (!$has_any) {
    echo '<tr><td colspan="7" style="text-align:center;color:#888">Chưa có lịch nhắc nhở nào. Vào <strong>Life Map Plan</strong> để setup.</td></tr>';
  }

  echo '</tbody></table>';
  echo '</div>';

  // Reminder logs
  echo '<div class="postbox" style="padding:16px;margin-top:20px">';
  echo '<h2>📝 Reminder Logs (10 gần nhất)</h2>';

  $logs_tbl = $wpdb->prefix . 'bccm_reminder_logs';
  if ($wpdb->get_var("SHOW TABLES LIKE '$logs_tbl'") === $logs_tbl) {
    $logs = $wpdb->get_results("SELECT * FROM $logs_tbl ORDER BY sent_at DESC LIMIT 10", ARRAY_A);
    if ($logs) {
      echo '<table class="widefat striped">';
      echo '<thead><tr><th>Thời gian</th><th>Coachee</th><th>Type</th><th>Channel</th><th>Message</th><th>Status</th></tr></thead>';
      echo '<tbody>';
      foreach ($logs as $log) {
        $name = $wpdb->get_var($wpdb->prepare("SELECT full_name FROM {$t['profiles']} WHERE id=%d", $log['coachee_id'])) ?: '#' . $log['coachee_id'];
        echo '<tr>';
        echo '<td>' . esc_html($log['sent_at']) . '</td>';
        echo '<td>' . esc_html($name) . '</td>';
        echo '<td>' . esc_html($log['reminder_type']) . '</td>';
        echo '<td>' . esc_html($log['channel']) . '</td>';
        echo '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis">' . esc_html(wp_trim_words($log['message'], 20)) . '</td>';
        echo '<td>' . ($log['status'] === 'sent' ? '<span style="color:green">✅</span>' : '<span style="color:red">❌</span>') . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    } else {
      echo '<p style="color:#888">Chưa có logs.</p>';
    }
  } else {
    echo '<p style="color:#888">Bảng logs chưa được tạo (sẽ tự tạo khi gửi reminder đầu tiên).</p>';
  }

  echo '</div>';
  echo '</div>';
}

/* =====================================================================
 * 3. CRON SCHEDULING HELPERS
 * =====================================================================*/

/** Schedule daily + weekly reminders cho coachee */
function bccm_schedule_reminders($coachee_id, $config) {
  // Clear existing
  bccm_unschedule_reminders($coachee_id);

  // Parse time
  $daily_parts = explode(':', $config['daily_time'] ?? '08:00');
  $daily_hour  = intval($daily_parts[0] ?? 8);
  $daily_min   = intval($daily_parts[1] ?? 0);

  // Calculate next run (Vietnam timezone UTC+7)
  $tz     = new DateTimeZone('Asia/Ho_Chi_Minh');
  $now    = new DateTime('now', $tz);

  // Daily: next occurrence of the daily_time
  $daily_next = clone $now;
  $daily_next->setTime($daily_hour, $daily_min, 0);
  if ($daily_next <= $now) {
    $daily_next->modify('+1 day');
  }

  wp_schedule_event($daily_next->getTimestamp(), 'daily', 'bccm_daily_reminder', [$coachee_id]);

  // Weekly: next occurrence of weekly_day at weekly_time
  $weekly_parts = explode(':', $config['weekly_time'] ?? '09:00');
  $weekly_hour  = intval($weekly_parts[0] ?? 9);
  $weekly_min   = intval($weekly_parts[1] ?? 0);

  $weekly_next = clone $now;
  $day_map = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];
  $target_day = $day_map[$config['weekly_day'] ?? 'monday'] ?? 1;
  $current_day = intval($weekly_next->format('N'));

  $diff_days = $target_day - $current_day;
  if ($diff_days < 0) $diff_days += 7;
  if ($diff_days === 0) {
    $weekly_next->setTime($weekly_hour, $weekly_min, 0);
    if ($weekly_next <= $now) {
      $weekly_next->modify('+7 days');
    }
  } else {
    $weekly_next->modify("+{$diff_days} days");
    $weekly_next->setTime($weekly_hour, $weekly_min, 0);
  }

  wp_schedule_event($weekly_next->getTimestamp(), 'weekly', 'bccm_weekly_reminder', [$coachee_id]);
}

/** Unschedule all reminders for coachee */
function bccm_unschedule_reminders($coachee_id) {
  $daily_ts  = wp_next_scheduled('bccm_daily_reminder', [$coachee_id]);
  $weekly_ts = wp_next_scheduled('bccm_weekly_reminder', [$coachee_id]);

  if ($daily_ts) wp_unschedule_event($daily_ts, 'bccm_daily_reminder', [$coachee_id]);
  if ($weekly_ts) wp_unschedule_event($weekly_ts, 'bccm_weekly_reminder', [$coachee_id]);
}

/* =====================================================================
 * 4. AI-PERSONALIZED REMINDER GENERATOR
 * =====================================================================*/

/**
 * Gọi AI (BizGPT proxy) để tạo message nhắc nhở cá nhân hóa theo character.
 *
 * @param string $type          'daily' | 'weekly'
 * @param array  $coachee       Coachee profile row
 * @param array  $week_data     Dữ liệu tuần hiện tại từ milestone
 * @param array  $next_week     Dữ liệu tuần kế (weekly only)
 * @param int    $week_num      Số tuần hiện tại (1–12)
 * @param int    $character_id  ID character đồng hành
 * @return string|false  Message text hoặc false nếu thất bại
 */
function bccm_ai_generate_reminder($type, $coachee, $week_data, $next_week, $week_num, $character_id) {
  // Load character
  global $wpdb;
  $char_tbl = $wpdb->prefix . 'bizcity_characters';
  if ($wpdb->get_var("SHOW TABLES LIKE '$char_tbl'") !== $char_tbl) return false;

  $character = $wpdb->get_row($wpdb->prepare(
    "SELECT name, system_prompt, creativity_level FROM $char_tbl WHERE id=%d", $character_id
  ), ARRAY_A);
  if (!$character || empty($character['system_prompt'])) return false;

  // BizGPT proxy token
  $proxy_token = get_option('bizgpt_api', '');
  if (empty($proxy_token)) return false;

  $name       = $coachee['full_name'] ?? 'Chủ nhân';
  $coach_type = $coachee['coach_type'] ?? 'biz_coach';
  $char_name  = $character['name'] ?? 'AI Coach';
  $temp       = floatval($character['creativity_level'] ?? 0.7);

  // Build system prompt: character personality + reminder role
  $system = $character['system_prompt'] . "\n\n"
    . "---\n"
    . "BỐI CẢNH BỔ SUNG:\n"
    . "Bạn đang đóng vai \"{$char_name}\" – AI Coach đồng hành cùng \"{$name}\" trong hành trình 90 ngày.\n"
    . "Loại coaching: {$coach_type}. Tuần hiện tại: {$week_num}/12.\n"
    . "Hãy viết tin nhắn nhắc nhở bằng giọng và tính cách riêng của bạn (theo system prompt ở trên).\n"
    . "Viết tự nhiên, ấm áp, có emoji phù hợp. Độ dài: 150–300 từ. Ngôn ngữ: Tiếng Việt.\n"
    . "KHÔNG bao bọc trong markdown code block. Trả về plain text.";

  // Build user context
  $context = [
    'type'       => $type,
    'coachee'    => $name,
    'coach_type' => $coach_type,
    'week'       => $week_num,
    'week_data'  => $week_data,
  ];

  if ($type === 'daily') {
    $user_prompt = "Viết tin nhắn CHÀO BUỔI SÁNG cho {$name} (Tuần {$week_num}/12).\n"
      . "Nội dung cần bao gồm:\n"
      . "- Lời chào ấm áp theo phong cách character\n"
      . "- Nhắc mục tiêu tuần (nếu có)\n"
      . "- Liệt kê thói quen hôm nay (nếu có)\n"
      . "- Trọng tâm hôm nay (nếu có)\n"
      . "- Câu suy ngẫm/động lực (nếu có)\n"
      . "- Lời kết động viên\n\n"
      . "DỮ LIỆU TUẦN HIỆN TẠI:\n" . wp_json_encode($week_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  } else {
    $context['next_week'] = $next_week;
    $user_prompt = "Viết tin nhắn TỔNG KẾT TUẦN {$week_num} cho {$name}.\n"
      . "Nội dung cần bao gồm:\n"
      . "- Tổng kết cột mốc tuần vừa qua\n"
      . "- KPIs cần đánh giá (nếu có)\n"
      . "- Câu hỏi check-in motivational\n"
      . "- Preview tuần tiếp theo (nếu có)\n"
      . "- Lời động viên theo phong cách character\n\n"
      . "DỮ LIỆU TUẦN HIỆN TẠI:\n" . wp_json_encode($week_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
      . (!empty($next_week) ? "\n\nDỮ LIỆU TUẦN KẾ TIẾP:\n" . wp_json_encode($next_week, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '');
  }

  // Call BizGPT proxy
  $model   = get_option('bizgpt_model', 'gpt-4.1-nano');
  $payload = [
    'model'       => $model,
    'messages'    => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user',   'content' => $user_prompt],
    ],
    'max_tokens'  => 2000,
    'temperature' => min(max($temp, 0.3), 1.0),
  ];

  $response = wp_remote_post('https://bizgpt.vn/wp-json/bizgpt/chat', [
    'headers' => [
      'Authorization' => 'Bearer ' . $proxy_token,
      'Content-Type'  => 'application/json',
    ],
    'body'    => wp_json_encode($payload),
    'timeout' => 60,
  ]);

  if (is_wp_error($response)) {
    error_log('[BCCM Reminder AI] HTTP error: ' . $response->get_error_message());
    return false;
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);
  $text = trim($body['choices'][0]['message']['content'] ?? '');

  if (empty($text)) {
    error_log('[BCCM Reminder AI] Empty response from API');
    return false;
  }

  return $text;
}

/* =====================================================================
 * 5. CRON HANDLERS
 * =====================================================================*/

/** Daily reminder: nhắc thói quen + tasks hàng ngày */
add_action('bccm_daily_reminder', function ($coachee_id) {
  $config = get_option("bccm_reminders_config_{$coachee_id}", []);
  if (empty($config['enabled'])) return;

  global $wpdb;
  $t = bccm_tables();
  $coachee = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
  if (!$coachee) return;

  $milestone = json_decode($coachee['bizcoach_json'] ?? '[]', true) ?: [];
  $life_map  = json_decode($coachee['mental_json'] ?? '[]', true) ?: [];
  $week_num  = intval($config['current_week'] ?? 1);

  // Tìm tuần hiện tại trong milestone
  $current_week_data = bccm_find_week_in_milestone($milestone, $week_num);

  // === TRY AI-PERSONALIZED MESSAGE ===
  $message = false;
  $char_id = intval($config['character_id'] ?? 0);
  if ($char_id > 0) {
    $message = bccm_ai_generate_reminder('daily', $coachee, $current_week_data, [], $week_num, $char_id);
  }

  // === FALLBACK: hardcoded template ===
  if (!$message) {
    $name = $coachee['full_name'] ?? 'Chủ nhân';
    $message = "🌅 Chào buổi sáng {$name}! (Tuần {$week_num}/12)\n\n";

    if (!empty($current_week_data['goal'])) {
      $message .= "🎯 Mục tiêu tuần: {$current_week_data['goal']}\n\n";
    }

    if (!empty($current_week_data['daily_habits'])) {
      $message .= "🔁 Thói quen hôm nay:\n";
      foreach ($current_week_data['daily_habits'] as $i => $h) {
        $message .= "  " . ($i + 1) . ". {$h}\n";
      }
      $message .= "\n";
    }

    if (!empty($current_week_data['focus'])) {
      $message .= "🔍 Trọng tâm: {$current_week_data['focus']}\n";
    }

    if (!empty($current_week_data['reflection_prompt'])) {
      $message .= "\n💭 Câu suy ngẫm: \"{$current_week_data['reflection_prompt']}\"\n";
    }

    $message .= "\n💪 Hãy hoàn thành những việc nhỏ hôm nay để tiến gần hơn tới mục tiêu!";
  }

  // Send via channels
  bccm_send_reminder($coachee_id, 'daily', $message, $config);
});

/** Weekly reminder: tổng kết tuần + chuẩn bị tuần mới */
add_action('bccm_weekly_reminder', function ($coachee_id) {
  $config = get_option("bccm_reminders_config_{$coachee_id}", []);
  if (empty($config['enabled'])) return;

  global $wpdb;
  $t = bccm_tables();
  $coachee = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
  if (!$coachee) return;

  $milestone = json_decode($coachee['bizcoach_json'] ?? '[]', true) ?: [];
  $week_num  = intval($config['current_week'] ?? 1);

  $current_week_data = bccm_find_week_in_milestone($milestone, $week_num);
  $next_week_data    = bccm_find_week_in_milestone($milestone, $week_num + 1);

  // === TRY AI-PERSONALIZED MESSAGE ===
  $message = false;
  $char_id = intval($config['character_id'] ?? 0);
  if ($char_id > 0) {
    $message = bccm_ai_generate_reminder('weekly', $coachee, $current_week_data, $next_week_data, $week_num, $char_id);
  }

  // === FALLBACK: hardcoded template ===
  if (!$message) {
    $name = $coachee['full_name'] ?? 'Chủ nhân';
    $message = "📅 Tổng kết tuần {$week_num} – {$name}\n\n";

    if (!empty($current_week_data['milestone'])) {
      $message .= "🏁 Cột mốc tuần này: {$current_week_data['milestone']}\n";
    }

    if (!empty($current_week_data['kpis'])) {
      $message .= "\n📊 KPIs cần đạt:\n";
      foreach ($current_week_data['kpis'] as $kpi) {
        $message .= "  ☐ {$kpi}\n";
      }
    }

    if (!empty($current_week_data['coach_check_in'])) {
      $message .= "\n🤖 Coach hỏi: {$current_week_data['coach_check_in']}\n";
    }

    // Next week preview
    if (!empty($next_week_data)) {
      $message .= "\n---\n📌 Đón đọc tuần " . ($week_num + 1) . ":\n";
      if (!empty($next_week_data['theme'])) $message .= "Theme: {$next_week_data['theme']}\n";
      if (!empty($next_week_data['goal'])) $message .= "Mục tiêu: {$next_week_data['goal']}\n";
    }
  }

  // Auto advance week (luôn chạy bất kể AI hay fallback)
  if ($week_num < 12) {
    $config['current_week'] = $week_num + 1;
    update_option("bccm_reminders_config_{$coachee_id}", $config);
    $message .= "\n\n✅ Hệ thống đã chuyển sang Tuần " . ($week_num + 1) . " tự động.";
  } else {
    $message .= "\n\n🎉 Chúc mừng! Bạn đã hoàn thành hành trình 90 ngày!";
    $config['enabled'] = false;
    update_option("bccm_reminders_config_{$coachee_id}", $config);
    bccm_unschedule_reminders($coachee_id);
  }

  bccm_send_reminder($coachee_id, 'weekly', $message, $config);
});

/* =====================================================================
 * 5. SEND REMINDER (multi-channel)
 * =====================================================================*/
function bccm_send_reminder($coachee_id, $type, $message, $config) {
  $channels = (array)($config['channels'] ?? ['email']);

  foreach ($channels as $channel) {
    $status = 'failed';

    switch ($channel) {
      case 'email':
        $status = bccm_send_reminder_email($coachee_id, $message) ? 'sent' : 'failed';
        break;
      case 'telegram':
        $status = bccm_send_reminder_telegram($coachee_id, $message) ? 'sent' : 'failed';
        break;
      case 'zalo':
        $status = bccm_send_reminder_zalo($coachee_id, $message) ? 'sent' : 'failed';
        break;
    }

    // Log
    bccm_log_reminder($coachee_id, $type, $channel, $message, $status);
  }
}

/** Send email reminder */
function bccm_send_reminder_email($coachee_id, $message) {
  global $wpdb;
  $t = bccm_tables();
  $coachee = $wpdb->get_row($wpdb->prepare("SELECT full_name, phone FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);

  // Try to find user email by phone or name
  $email = '';
  if (!empty($coachee['phone'])) {
    $user = get_user_by('login', $coachee['phone']);
    if ($user) $email = $user->user_email;
  }

  // Fallback to admin email
  if (empty($email)) {
    $email = get_option('admin_email');
  }

  $subject = '🌱 BizCoach – Nhắc nhở hành trình cùng ' . ($coachee['full_name'] ?? 'Chủ nhân');

  return wp_mail($email, $subject, $message);
}

/** Send Telegram reminder (hook into bizcity-admin-hook-telegram if available) */
function bccm_send_reminder_telegram($coachee_id, $message) {
  if (function_exists('bizcity_telegram_send_message')) {
    return bizcity_telegram_send_message($message);
  }

  // Fallback: use WordPress HTTP API
  $bot_token = get_option('bizcity_telegram_bot_token', '');
  $chat_id   = get_option('bizcity_telegram_chat_id', '');

  if (empty($bot_token) || empty($chat_id)) return false;

  $response = wp_remote_post("https://api.telegram.org/bot{$bot_token}/sendMessage", [
    'body' => [
      'chat_id'    => $chat_id,
      'text'       => $message,
      'parse_mode' => 'HTML',
    ],
  ]);

  return !is_wp_error($response);
}

/** Send Zalo reminder (hook into bizcity-zalo-bot if available) */
function bccm_send_reminder_zalo($coachee_id, $message) {
  if (function_exists('bizcity_zalo_send_message')) {
    return bizcity_zalo_send_message($message);
  }
  return false;
}

/** Log reminder to database */
function bccm_log_reminder($coachee_id, $type, $channel, $message, $status) {
  global $wpdb;
  $table = $wpdb->prefix . 'bccm_reminder_logs';

  // Create table if not exists
  $charset = $wpdb->get_charset_collate();
  $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coachee_id BIGINT UNSIGNED NOT NULL,
    reminder_type VARCHAR(32) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    message LONGTEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'sent',
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY coachee_id (coachee_id),
    KEY sent_at (sent_at)
  ) $charset;");

  $wpdb->insert($table, [
    'coachee_id'    => $coachee_id,
    'reminder_type' => $type,
    'channel'       => $channel,
    'message'       => $message,
    'status'        => $status,
    'sent_at'       => current_time('mysql'),
  ]);
}

/* =====================================================================
 * 6. MILESTONE HELPER
 * =====================================================================*/
function bccm_find_week_in_milestone($milestone, $week_num) {
  if (empty($milestone['phases'])) return [];

  foreach ($milestone['phases'] as $phase) {
    foreach ($phase['weeks'] ?? [] as $wk) {
      if (intval($wk['week'] ?? 0) === $week_num) {
        return $wk;
      }
    }
  }
  return [];
}
