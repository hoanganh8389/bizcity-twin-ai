<?php
/**
 * BizCoach Map – Step 4: Success Plan & Gán Trợ lý đồng hành
 *
 * Bước 4 (cuối cùng) trong workflow 4 bước:
 *   Step 1: Hồ sơ cá nhân + Chiêm tinh
 *   Step 2: Chọn Coach Template + Câu hỏi bổ sung
 *   Step 3: Tạo Trợ lý (bizcity-knowledge)
 *   Step 4: Generate Success Plan + Gán Trợ lý đồng hành (trang này)
 *
 * Tổng hợp tất cả dữ liệu từ 3 bước trước → generate Success Plan AI
 * → gán Trợ lý từ Bước 3 làm trợ lý đồng hành.
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * ADMIN MENU: Bước 4 – Success Plan
 * =====================================================================*/
add_action('admin_menu', function () {
  add_submenu_page(
    'bccm_root',
    'Bước 4: Success Plan',
    'Bước 4: Success Plan',
    'edit_posts',
    'bccm_step4_success_plan',
    'bccm_admin_step4_success_plan',
    14
  );
}, 14);

/* =====================================================================
 * ADMIN PAGE: Bước 4 – Success Plan
 * =====================================================================*/
function bccm_admin_step4_success_plan() {
  if (!current_user_can('edit_posts')) return;

  global $wpdb;
  $t = bccm_tables();
  $user_id = get_current_user_id();

  // Lấy coachee
  $coachee = bccm_get_or_create_user_coachee($user_id, 'ADMINCHAT', 'mental_coach');
  #if (!$coachee) {
   #echo '<div class="wrap bccm-wrap"><h1>Lỗi</h1><p>Không thể tạo hồ sơ.</p></div>';
    #return;
  #}

  $coachee_id    = (int)$coachee['id'];
  $selected_type = $coachee['coach_type'] ?: 'mental_coach';
  $types_meta    = bccm_coach_types();
  $type_label    = $types_meta[$selected_type]['label'] ?? $selected_type;

  // Astro data — USE user_id as primary for cross-platform consistency
  $t_astro   = $wpdb->prefix . 'bccm_astro';
  $astro_row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' ORDER BY id DESC LIMIT 1", $user_id
  ), ARRAY_A);
  if (!$astro_row) {
    $astro_row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic' ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
  }

  // Life Map data
  $life_map  = json_decode($coachee['mental_json'] ?? '[]', true) ?: [];
  $milestone = json_decode($coachee['bizcoach_json'] ?? '[]', true) ?: [];

  // Linked Character
  $linked_character_id = get_user_meta($user_id, 'bccm_linked_character_id', true);
  $linked_character    = null;
  $knowledge_available = class_exists('BizCity_Knowledge_Database');
  if ($linked_character_id && $knowledge_available) {
    $db = new BizCity_Knowledge_Database();
    $linked_character = $db->get_character($linked_character_id);
  }

  // Generators available for this type
  $generators = bccm_generators_for_type($selected_type);

  /* ==================== HANDLE POST ACTIONS ==================== */
  if (!empty($_POST['bccm_action']) && check_admin_referer('bccm_step4_plan')) {
    $action = sanitize_text_field($_POST['bccm_action']);

    if ($action === 'assign_character' && $linked_character_id) {
      // Assign character as reminder companion
      update_option("bccm_reminders_config_{$coachee_id}", array_merge(
        get_option("bccm_reminders_config_{$coachee_id}", []),
        ['character_id' => $linked_character_id]
      ));
      echo '<div class="updated"><p>✅ Đã gán Trợ lý làm đồng hành!</p></div>';
    } elseif ($action !== 'save_only') {
      // Run a generator
      $gens_map = [];
      foreach ($generators as $g) {
        if (!empty($g['key']) && !empty($g['fn'])) $gens_map[$g['key']] = $g['fn'];
      }

      if (isset($gens_map[$action]) && function_exists($gens_map[$action])) {
        $result = call_user_func($gens_map[$action], $coachee_id);
        if (is_wp_error($result)) {
          echo '<div class="error"><p>Lỗi: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
          echo '<div class="updated"><p>✅ Đã generate thành công!</p></div>';
        }
      }

      // Refresh data
      $coachee   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
      $life_map  = json_decode($coachee['mental_json'] ?? '[]', true) ?: [];
      $milestone = json_decode($coachee['bizcoach_json'] ?? '[]', true) ?: [];
    }
  }

  /* ==================== RENDER UI ==================== */
  echo '<div class="wrap bccm-wrap">';
  bccm_render_workflow_steps();

  echo '<h1>🎯 Bước 4: Success Plan & Trợ lý đồng hành</h1>';
  echo '<p class="description">Tổng hợp dữ liệu từ 3 bước trước, generate bản đồ cuộc đời và gán Trợ lý AI làm đồng hành.</p>';

  echo '<form method="post">';
  wp_nonce_field('bccm_step4_plan');

  // ── Progress Overview: 4 Steps Status ──
  echo '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:24px">';

  // Step 1 status
  $s1_done = !empty($coachee['dob']) && !empty($coachee['full_name']);
  $s1_astro = !empty($astro_row['summary']);
  echo '<div style="background:' . ($s1_done && $s1_astro ? '#f0fdf4' : '#fef3c7') . ';padding:12px;border-radius:8px;text-align:center;border:1px solid ' . ($s1_done && $s1_astro ? '#86efac' : '#fde68a') . '">';
  echo '<div style="font-size:20px">' . ($s1_done && $s1_astro ? '✅' : '⚠️') . '</div>';
  echo '<div style="font-size:12px;font-weight:600">Bước 1</div>';
  echo '<div style="font-size:11px;color:#666">Hồ sơ & Chiêm tinh</div>';
  echo '</div>';

  // Step 2 status
  $answers = json_decode($coachee['answer_json'] ?? '[]', true) ?: [];
  $s2_done = !empty($selected_type) && count(array_filter($answers)) > 0;
  echo '<div style="background:' . ($s2_done ? '#f0fdf4' : '#fef3c7') . ';padding:12px;border-radius:8px;text-align:center;border:1px solid ' . ($s2_done ? '#86efac' : '#fde68a') . '">';
  echo '<div style="font-size:20px">' . ($s2_done ? '✅' : '⚠️') . '</div>';
  echo '<div style="font-size:12px;font-weight:600">Bước 2</div>';
  echo '<div style="font-size:11px;color:#666">' . esc_html($type_label) . '</div>';
  echo '</div>';

  // Step 3 status
  $s3_done = !empty($linked_character);
  echo '<div style="background:' . ($s3_done ? '#f0fdf4' : '#fef3c7') . ';padding:12px;border-radius:8px;text-align:center;border:1px solid ' . ($s3_done ? '#86efac' : '#fde68a') . '">';
  echo '<div style="font-size:20px">' . ($s3_done ? '✅' : '⚠️') . '</div>';
  echo '<div style="font-size:12px;font-weight:600">Bước 3</div>';
  echo '<div style="font-size:11px;color:#666">Trợ lý</div>';
  echo '</div>';

  // Step 4 status (this page)
  $s4_done = !empty($life_map) || !empty($milestone);
  echo '<div style="background:' . ($s4_done ? '#f0fdf4' : '#eff6ff') . ';padding:12px;border-radius:8px;text-align:center;border:2px solid ' . ($s4_done ? '#86efac' : '#6366f1') . '">';
  echo '<div style="font-size:20px">' . ($s4_done ? '✅' : '🎯') . '</div>';
  echo '<div style="font-size:12px;font-weight:600">Bước 4</div>';
  echo '<div style="font-size:11px;color:#666">Success Plan</div>';
  echo '</div>';

  echo '</div>';

  // ── Character Companion Section ──
  echo '<div class="postbox"><div class="inside">';
  echo '<h3 style="margin-top:0">🤖 Trợ lý đồng hành</h3>';

  if ($linked_character) {
    $char_name = is_object($linked_character) ? ($linked_character->name ?? '') : ($linked_character['name'] ?? '');
    $char_status = is_object($linked_character) ? ($linked_character->status ?? '') : ($linked_character['status'] ?? '');

    echo '<div style="display:flex;align-items:center;gap:12px;padding:12px;background:#f0fdf4;border-radius:8px;border:1px solid #86efac">';
    echo '<div style="font-size:32px">🤖</div>';
    echo '<div>';
    echo '<div style="font-weight:600;font-size:15px">' . esc_html($char_name) . '</div>';
    echo '<div style="font-size:12px;color:#666">Status: <span class="bccm-badge bccm-badge-success">' . esc_html($char_status) . '</span> | ID: ' . intval($linked_character_id) . '</div>';
    echo '</div>';
    echo '<div style="margin-left:auto">';
    echo '<button class="button button-primary" name="bccm_action" value="assign_character">🔗 Gán làm trợ lý</button>';
    echo '</div>';
    echo '</div>';

    // Link to chat
    echo '<p style="margin-top:8px">';
    echo '<a href="' . esc_url(admin_url('')) . '" class="button" target="_blank">💬 Chat với Trợ lý</a> ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_step3_character')) . '" class="button">🔄 Chỉnh sửa Trợ lý</a>';
    echo '</p>';
  } else {
    echo '<div style="padding:16px;background:#fef3c7;border-radius:8px;text-align:center">';
    echo '<div style="font-size:24px;margin-bottom:8px">⚠️</div>';
    echo '<p>Chưa tạo Trợ lý. Hãy quay lại <a href="' . esc_url(admin_url('admin.php?page=bccm_step3_character')) . '"><strong>Bước 3</strong></a> để tạo Trợ lý trước.</p>';
    echo '</div>';
  }
  echo '</div></div>';

  // ── Generate Maps Section ──
  echo '<div class="postbox"><div class="inside">';
  echo '<h3 style="margin-top:0">🗺️ Generate Success Plan & Bản đồ</h3>';
  echo '<p class="description">Generate các bản đồ cuộc đời dựa trên dữ liệu chiêm tinh + câu hỏi coaching. Mỗi bản đồ sẽ được lưu và dùng làm nền tảng cho trợ lý AI.</p>';

  if (!empty($generators)) {
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px;margin-top:12px">';
    foreach ($generators as $g) {
      $gen_key    = $g['key'] ?? '';
      $gen_label  = $g['label'] ?? $gen_key;
      $gen_column = $g['column'] ?? '';

      // Check if map already generated
      $has_data = false;
      if ($gen_column && !empty($coachee[$gen_column])) {
        $decoded = json_decode($coachee[$gen_column], true);
        $has_data = !empty($decoded);
      }

      $bg_color = $has_data ? '#f0fdf4' : '#fff';
      $border_color = $has_data ? '#86efac' : '#e2e8f0';
      $status_icon = $has_data ? '✅' : '⬜';

      echo '<div style="background:' . $bg_color . ';padding:12px;border-radius:8px;border:1px solid ' . $border_color . ';display:flex;align-items:center;gap:8px">';
      echo '<span>' . $status_icon . '</span>';
      echo '<div style="flex:1;font-size:13px">' . esc_html($gen_label) . '</div>';
      echo '<button class="button button-small" name="bccm_action" value="' . esc_attr($gen_key) . '">Generate</button>';
      echo '</div>';
    }
    echo '</div>';
  }
  echo '</div></div>';

  // ── Life Map Preview ──
  if (!empty($life_map)) {
    echo '<div class="postbox"><div class="inside">';
    echo '<h3 style="margin-top:0">🗺️ Life Map Preview</h3>';

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';

    if (!empty($life_map['identity'])) {
      echo '<div style="background:#f8f9fa;padding:12px;border-radius:8px">';
      echo '<div style="font-weight:600;margin-bottom:6px">🧠 Tôi là ai</div>';
      if (!empty($life_map['identity']['life_stage'])) {
        echo '<div style="font-size:13px">Giai đoạn: <em>' . esc_html($life_map['identity']['life_stage']) . '</em></div>';
      }
      if (!empty($life_map['identity']['core_values'])) {
        echo '<div style="font-size:13px">Giá trị: <em>' . esc_html(implode(', ', $life_map['identity']['core_values'])) . '</em></div>';
      }
      echo '</div>';
    }

    if (!empty($life_map['strengths'])) {
      echo '<div style="background:#f8f9fa;padding:12px;border-radius:8px">';
      echo '<div style="font-weight:600;margin-bottom:6px">💪 Thế mạnh</div>';
      echo '<div style="font-size:13px">' . esc_html(is_array($life_map['strengths']) ? implode(', ', $life_map['strengths']) : $life_map['strengths']) . '</div>';
      echo '</div>';
    }

    if (!empty($life_map['vision_3y'])) {
      echo '<div style="background:#f8f9fa;padding:12px;border-radius:8px">';
      echo '<div style="font-weight:600;margin-bottom:6px">🔭 Tầm nhìn 3 năm</div>';
      echo '<div style="font-size:13px">' . esc_html($life_map['vision_3y']) . '</div>';
      echo '</div>';
    }

    if (!empty($life_map['goals_90d'])) {
      echo '<div style="background:#f8f9fa;padding:12px;border-radius:8px">';
      echo '<div style="font-weight:600;margin-bottom:6px">🎯 Mục tiêu 90 ngày</div>';
      echo '<div style="font-size:13px">';
      if (is_array($life_map['goals_90d'])) {
        echo '<ul style="margin:4px 0;padding-left:16px">';
        foreach ($life_map['goals_90d'] as $goal) {
          echo '<li>' . esc_html(is_array($goal) ? ($goal['goal'] ?? json_encode($goal)) : $goal) . '</li>';
        }
        echo '</ul>';
      } else {
        echo esc_html($life_map['goals_90d']);
      }
      echo '</div></div>';
    }

    echo '</div>';

    echo '<p style="margin-top:12px">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_lifemap_plan&coachee_id=' . $coachee_id)) . '" class="button">📋 Xem Life Map đầy đủ</a> ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_ai_json_editor&coachee_id=' . $coachee_id)) . '" class="button">📝 Sửa bản đồ JSON</a>';
    echo '</p>';

    echo '</div></div>';
  }

  // ── Next Steps ──
  echo '<div style="margin-top:20px;padding:16px;background:#f0f4ff;border-radius:8px;border-left:4px solid #6366f1">';
  echo '<h3 style="margin-top:0">🚀 Sau khi hoàn thành</h3>';
  echo '<p>';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_lifemap_plan&coachee_id=' . $coachee_id)) . '" class="button button-primary">🗺️ Life Map Plan</a> ';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_cron_reminders')) . '" class="button">⏰ Cron & Reminders</a> ';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_coachees')) . '" class="button">👥 Bản đồ người khác</a>';
  echo '</p>';
  echo '</div>';

  echo '</form>';
  echo '</div>'; // .wrap
}
