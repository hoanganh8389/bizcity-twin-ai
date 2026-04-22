<?php
/**
 * BizCoach Map – Step 3: Tạo Trợ lý (Bridge to bizcity-knowledge)
 *
 * Bước 3 trong workflow 4 bước:
 *   Step 1: Hồ sơ cá nhân + Chiêm tinh
 *   Step 2: Chọn Coach Template + Câu hỏi bổ sung
 *   Step 3: Tạo Trợ lý từ dữ liệu chiêm tinh + coach template (trang này)
 *   Step 4: Success Plan + Gán Trợ lý đồng hành
 *
 * Tạo trợ lý AI dựa trên nền tảng chiêm tinh + coach type → sử dụng
 * bizcity-knowledge plugin (BizCity_Knowledge_Database::create_character).
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * ADMIN MENU: Bước 3 – Tạo Trợ lý
 * =====================================================================*/
add_action('admin_menu', function () {
  // Phase 7.1: ẩn submenu khỏi navigation chính, nhưng vẫn giữ page callable trực tiếp.
  add_submenu_page(
    null,
    'Bước 3: Tạo Trợ lý',
    'Bước 3: Tạo Trợ lý',
    'edit_posts',
    'bccm_step3_character',
    'bccm_admin_step3_character',
    13
  );
}, 13);

/* =====================================================================
 * HELPER: Build system prompt from astro + coach type data
 * =====================================================================*/
function bccm_build_character_system_prompt($coachee, $astro_row) {
  $coach_type  = $coachee['coach_type'] ?? 'mental_coach';
  $types_meta  = bccm_coach_types();
  $type_label  = $types_meta[$coach_type]['label'] ?? $coach_type;
  $full_name   = $coachee['full_name'] ?? 'Người dùng';
  $zodiac      = $coachee['zodiac_sign'] ?? '';

  // Astro context
  $astro_context = '';
  if (!empty($astro_row['summary'])) {
    $summary = json_decode($astro_row['summary'], true);
    if ($summary) {
      $sun  = $summary['sun_sign'] ?? '';
      $moon = $summary['moon_sign'] ?? '';
      $asc  = $summary['ascendant_sign'] ?? '';
      $astro_context = "Bản đồ chiêm tinh: Sun in $sun, Moon in $moon, Ascendant $asc.";
    }
  }

  // Coaching prompt from astro-api if available
  $coaching_prompt = '';
  if (function_exists('bccm_astro_build_coaching_prompt')) {
    $coaching_prompt = bccm_astro_build_coaching_prompt($coachee['id'] ?? 0);
  }

  $prompt = <<<PROMPT
Bạn là một AI Coach chuyên nghiệp thuộc hệ thống BizCoach Map.
Loại Coach: {$type_label}
Người được coaching: {$full_name}

== NỀN TẢNG CHIÊM TINH ==
Cung hoàng đạo: {$zodiac}
{$astro_context}
{$coaching_prompt}

== NGUYÊN TẮC ==
1. Tất cả gợi ý và phân tích đều dựa trên nền tảng chiêm tinh của người dùng.
2. Kết hợp kiến thức coaching chuyên sâu ({$type_label}) với insight từ bản đồ chiêm tinh.
3. Phong cách giao tiếp thân thiện, chuyên nghiệp, sâu sắc.
4. Luôn đưa ra hành động cụ thể, khả thi, phù hợp với đặc điểm chiêm tinh.
5. Trả lời bằng tiếng Việt, có thể dùng thuật ngữ tiếng Anh khi cần thiết.
PROMPT;

  // Add type-specific instructions
  $type_instructions = [
    'biz_coach'    => "\n\n== BIZ COACH ==\nTập trung vào kinh doanh, lãnh đạo, chiến lược. Phân tích SWOT, tầm nhìn, mô hình kinh doanh dựa trên bản đồ chiêm tinh.",
    'baby_coach'   => "\n\n== BABY COACH ==\nTư vấn nuôi dạy con theo chiêm tinh học. Hiểu tính cách bé qua bản đồ sao, gợi ý phương pháp giáo dục phù hợp.",
    'mental_coach' => "\n\n== HEALTH COACH ==\nHướng dẫn sức khỏe thể chất & tinh thần dựa trên chiêm tinh. Nhận diện điểm mạnh/yếu sức khỏe qua bản đồ nhà.",
    'tiktok_coach' => "\n\n== TIKTOK COACH ==\nTư vấn xây dựng thương hiệu cá nhân trên TikTok. Chiến lược content dựa trên cung hoàng đạo và đặc điểm chiêm tinh.",
    'astro_coach'  => "\n\n== ASTRO COACH ==\nChuyên sâu về chiêm tinh học. Phân tích chi tiết bản đồ sao, hành tinh, nhà, góc chiếu. Tư vấn toàn diện.",
    'tarot_coach'  => "\n\n== TAROT COACH ==\nKết hợp Tarot và Chiêm tinh. Đọc bài Tarot dựa trên năng lượng chiêm tinh, insight tâm linh sâu sắc.",
  ];

  $prompt .= $type_instructions[$coach_type] ?? '';

  return $prompt;
}

/* =====================================================================
 * ADMIN PAGE: Bước 3 – Tạo Character
 * =====================================================================*/
function bccm_admin_step3_character() {
  if (!current_user_can('edit_posts')) return;

  global $wpdb;
  $t = bccm_tables();
  $user_id = get_current_user_id();

  // Check bizcity-knowledge plugin availability
  $knowledge_available = class_exists('BizCity_Knowledge_Database');

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

  // Check if character already linked to this coachee
  $linked_character_id = get_user_meta($user_id, 'bccm_linked_character_id', true);
  $linked_character    = null;
  if ($linked_character_id && $knowledge_available) {
    $db = new BizCity_Knowledge_Database();
    $linked_character = $db->get_character($linked_character_id);
  }

  /* ==================== HANDLE POST ACTIONS ==================== */
  if (!empty($_POST['bccm_action']) && check_admin_referer('bccm_step3_character')) {
    $action = sanitize_text_field($_POST['bccm_action']);

    if ($action === 'create_character' && $knowledge_available) {
      // Build character data from astro + coach type
      $char_name = sanitize_text_field($_POST['character_name'] ?? '');
      if (empty($char_name)) {
        $char_name = $type_label . ' Coach – ' . ($coachee['full_name'] ?? 'User');
      }

      $system_prompt = bccm_build_character_system_prompt($coachee, $astro_row);

      // Override with custom prompt if provided
      $custom_prompt = wp_kses_post($_POST['custom_system_prompt'] ?? '');
      if (!empty($custom_prompt)) {
        $system_prompt = $custom_prompt;
      }

      $capabilities = [
        'coaching',
        'astrology',
        strtolower(str_replace('_', '-', $selected_type)),
      ];

      $char_data = [
        'name'              => $char_name,
        'slug'              => sanitize_title($char_name . '-' . $coachee_id),
        'description'       => 'AI Coach tự động tạo từ BizCoach Map (Bước 3). Coach type: ' . $type_label . '. Coachee ID: ' . $coachee_id,
        'system_prompt'     => $system_prompt,
        'capabilities'      => $capabilities,
        'industries'        => [$selected_type],
        'status'            => 'active',
        'settings'          => [
          'bccm_coachee_id' => $coachee_id,
          'bccm_coach_type' => $selected_type,
          'bccm_zodiac'     => $coachee['zodiac_sign'] ?? '',
          'auto_generated'  => true,
        ],
      ];

      $db = new BizCity_Knowledge_Database();

      if ($linked_character) {
        // Update existing character
        $result = $db->update_character($linked_character_id, $char_data);
        if (is_wp_error($result)) {
          echo '<div class="error"><p>❌ Lỗi cập nhật Trợ lý: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
          echo '<div class="updated"><p>✅ Đã cập nhật Trợ lý "' . esc_html($char_name) . '" thành công!</p></div>';
          $linked_character = $db->get_character($linked_character_id);
        }
      } else {
        // Create new character
        $new_id = $db->create_character($char_data);
        if (is_wp_error($new_id)) {
          echo '<div class="error"><p>❌ Lỗi tạo Trợ lý: ' . esc_html($new_id->get_error_message()) . '</p></div>';
        } else {
          update_user_meta($user_id, 'bccm_linked_character_id', $new_id);
          $linked_character_id = $new_id;
          $linked_character = $db->get_character($new_id);
          echo '<div class="updated"><p>✅ Đã tạo Trợ lý "' . esc_html($char_name) . '" thành công! (ID: ' . $new_id . ')</p></div>';
        }
      }
    } elseif ($action === 'unlink_character') {
      delete_user_meta($user_id, 'bccm_linked_character_id');
      $linked_character_id = null;
      $linked_character = null;
      echo '<div class="updated"><p>✅ Đã hủy liên kết Trợ lý.</p></div>';
    }
  }

  /* ==================== RENDER UI ==================== */
  echo '<div class="wrap bccm-wrap">';
  bccm_render_workflow_steps();

  echo '<h1>🤖 Bước 3: Tạo Trợ lý đồng hành</h1>';
  echo '<p class="description">Tạo trợ lý AI dựa trên nền tảng chiêm tinh (Bước 1) + Coach Template (Bước 2). Trợ lý sẽ là AI cá nhân hóa cho bạn.</p>;';

  // Plugin availability check
  if (!$knowledge_available) {
    echo '<div class="notice notice-error" style="padding:12px">';
    echo '<strong>❌ Plugin bizcity-knowledge chưa được kích hoạt!</strong><br>';
    echo 'Bước 3 cần plugin <code>bizcity-knowledge</code> để tạo Trợ lý. Hãy kích hoạt plugin này trong MU-Plugins.';
    echo '</div>';
    echo '</div>';
    return;
  }

  // ── Summary cards: Step 1 & 2 status ──
  echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">';

  // Card 1: Astro status
  $has_astro = !empty($astro_row['summary']);
  echo '<div style="background:' . ($has_astro ? '#f0fdf4' : '#fef3c7') . ';padding:16px;border-radius:10px;border:1px solid ' . ($has_astro ? '#86efac' : '#fde68a') . '">';
  echo '<div style="font-weight:600;margin-bottom:6px">' . ($has_astro ? '✅' : '⚠️') . ' Bước 1: Chiêm tinh</div>';
  if ($has_astro) {
    $summary = json_decode($astro_row['summary'], true);
    echo '<div style="font-size:13px;color:#374151">☀️ ' . esc_html($summary['sun_sign'] ?? '') . ' | 🌙 ' . esc_html($summary['moon_sign'] ?? '') . ' | ⬆️ ' . esc_html($summary['ascendant_sign'] ?? '') . '</div>';
  } else {
    echo '<div style="font-size:13px;color:#92400e">Chưa tạo bản đồ chiêm tinh. <a href="' . esc_url(admin_url('admin.php?page=bccm_my_profile')) . '">Quay lại Bước 1</a></div>';
  }
  echo '</div>';

  // Card 2: Coach Template status
  echo '<div style="background:#eff6ff;padding:16px;border-radius:10px;border:1px solid #93c5fd">';
  echo '<div style="font-weight:600;margin-bottom:6px">✅ Bước 2: Coach Template</div>';
  echo '<div style="font-size:13px;color:#1e40af">' . esc_html($type_label) . ' | Coachee: ' . esc_html($coachee['full_name'] ?? '—') . '</div>';
  echo '</div>';
  echo '</div>';

  echo '<form method="post">';
  wp_nonce_field('bccm_step3_character');

  // ── Current Character Status ──
  if ($linked_character) {
    echo '<div class="postbox" style="margin-bottom:20px"><div class="inside">';
    echo '<h3 style="margin-top:0">🤖 Trợ lý hiện tại</h3>';
    echo '<table class="form-table">';
    echo '<tr><th>Tên</th><td><strong>' . esc_html($linked_character->name ?? $linked_character['name'] ?? '') . '</strong></td></tr>';
    echo '<tr><th>Slug</th><td><code>' . esc_html(is_object($linked_character) ? ($linked_character->slug ?? '') : ($linked_character['slug'] ?? '')) . '</code></td></tr>';
    echo '<tr><th>Status</th><td><span class="bccm-badge bccm-badge-success">' . esc_html(is_object($linked_character) ? ($linked_character->status ?? '') : ($linked_character['status'] ?? '')) . '</span></td></tr>';
    echo '<tr><th>ID</th><td>' . intval($linked_character_id) . '</td></tr>';
    echo '</table>';

    // Link to bizcity-knowledge edit page
    echo '<p>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=bizcity-knowledge-character-edit&id=' . $linked_character_id)) . '" class="button" target="_blank">📝 Cài đặt và nạp thêm kiến thức cho trợ lý →</a> ';
    echo '<button class="button" name="bccm_action" value="unlink_character" onclick="return confirm(\'Hủy liên kết Trợ lý?\')">🔗 Hủy liên kết</button>';
    echo '</p>';
    echo '</div></div>';
  }

  // ── Create / Update Character Form ──
  echo '<div class="postbox"><div class="inside">';
  echo '<h3 style="margin-top:0">' . ($linked_character ? '🔄 Cập nhật Trợ lý' : '✨ Tạo Trợ lý mới') . '</h3>';

  echo '<table class="form-table">';
  $default_name = $type_label . ' Coach – ' . ($coachee['full_name'] ?? 'User');
  echo '<tr><th>Tên Trợ lý</th><td>';
  echo '<input name="character_name" value="' . esc_attr($linked_character ? (is_object($linked_character) ? ($linked_character->name ?? $default_name) : ($linked_character['name'] ?? $default_name)) : $default_name) . '" class="regular-text"/>';
  echo '<p class="description">Tên hiển thị của trợ lý AI Coach</p>';
  echo '</td></tr>';
  echo '</table>';

  // Preview system prompt
  $preview_prompt = bccm_build_character_system_prompt($coachee, $astro_row);
  echo '<h4>📋 System Prompt (tự động tạo từ chiêm tinh + coach type)</h4>';
  echo '<textarea name="custom_system_prompt" rows="12" style="width:100%;font-family:monospace;font-size:12px">' . esc_textarea($preview_prompt) . '</textarea>';
  echo '<p class="description">Bạn có thể chỉnh sửa System Prompt trước khi tạo/cập nhật Trợ lý. Prompt đã được tích hợp dữ liệu chiêm tinh tự động.</p>';

  echo '</div></div>';

  // Action buttons
  echo '<p style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">';
  echo '<button class="button button-primary button-hero" name="bccm_action" value="create_character">';
  echo ($linked_character ? '🔄 Cập nhật Trợ lý' : '✨ Tạo Trợ lý');
  echo '</button>';
  echo '<a href="' . esc_url(admin_url('admin.php?page=bccm_step4_success_plan')) . '" class="button button-hero" style="margin-left:auto">Bước 4: Success Plan →</a>';
  echo '</p>';

  echo '</form>';

  echo '</div>'; // .wrap
}
