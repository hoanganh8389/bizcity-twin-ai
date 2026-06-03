<?php
/**
 * BizCoach Map – HealthCoach & Life Map Helpers
 * 
 * Chứa các generators cho:
 * 1. Health Overview (ai_summary)
 * 2. Health Map toàn diện (health_json)
 * 3. Life Map – Bản đồ cuộc đời (mental_json) → dùng làm RAG cho AI Agent
 * 4. Milestone Calendar 90 ngày (bizcoach_json)
 * 
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * 1) GENERATE HEALTH OVERVIEW (ai_summary)
 * =====================================================================*/
if (!function_exists('bccm_generate_health_overview')) {
  function bccm_generate_health_overview($coachee_id) {
    global $wpdb;
    $t   = bccm_tables();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id), ARRAY_A);
    if (!$row) return new WP_Error('not_found', 'Không tìm thấy coachee');

    @set_time_limit(120);

    // Lấy answers
    $answers = [];
    if (!empty($row['answer_json'])) {
      $answers = json_decode($row['answer_json'], true) ?: [];
    }

    // Lấy metrics nếu có
    $metrics = [];
    $m_row = $wpdb->get_row($wpdb->prepare(
      "SELECT numbers_full FROM {$wpdb->prefix}bccm_metrics WHERE coachee_id=%d", $coachee_id
    ), ARRAY_A);
    if ($m_row && !empty($m_row['numbers_full'])) {
      $metrics = json_decode($m_row['numbers_full'], true) ?: [];
    }

    $sys = "Bạn là HealthCoach chuyên gia, kết hợp y học dự phòng, dinh dưỡng lâm sàng, tâm lý sức khỏe và thần số học.
CHỈ trả về MỘT JSON hợp lệ (RFC8259), không markdown, tiếng Việt UTF-8:

{
  \"overview\": \"[nhận xét tổng quan 3–5 câu về tình trạng sức khỏe]\",
  \"life_path\": \"[số]\",
  \"soul_number\": \"[số]\",
  \"health_score\": 0-100,
  \"bmi\": { \"value\": 0, \"category\": \"\" },
  \"risk_factors\": [\"yếu tố 1\", \"yếu tố 2\"],
  \"strengths\": [\"điểm mạnh 1\", \"điểm mạnh 2\"],
  \"priority_areas\": [\"ưu tiên 1\", \"ưu tiên 2\", \"ưu tiên 3\"],
  \"immediate_actions\": [\"hành động 1\", \"hành động 2\", \"hành động 3\"],
  \"wellness_dimensions\": {
    \"physical\": { \"score\": 0-10, \"note\": \"\" },
    \"mental\": { \"score\": 0-10, \"note\": \"\" },
    \"nutrition\": { \"score\": 0-10, \"note\": \"\" },
    \"sleep\": { \"score\": 0-10, \"note\": \"\" },
    \"stress\": { \"score\": 0-10, \"note\": \"\" },
    \"social\": { \"score\": 0-10, \"note\": \"\" }
  }
}";

    $usr = wp_json_encode([
      'profile'  => [
        'full_name' => $row['full_name'] ?? '',
        'dob'       => $row['dob'] ?? '',
        'height_cm' => $row['height_cm'] ?? $row['baby_height_cm'] ?? '',
        'weight_kg' => $row['weight_kg'] ?? $row['baby_weight_kg'] ?? '',
      ],
      'answers'  => $answers,
      'metrics'  => $metrics,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_health_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_health_parse_json($raw);
    if (!$data) {
      return new WP_Error('parse_fail', 'Không parse được JSON từ AI', ['raw' => $raw]);
    }

    $wpdb->update($t['profiles'], [
      'ai_summary' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
    ], ['id' => (int)$coachee_id]);

    return true;
  }
}

/* =====================================================================
 * 2) GENERATE HEALTH MAP (health_json)
 * =====================================================================*/
if (!function_exists('bccm_generate_health_map')) {
  function bccm_generate_health_map($coachee_id) {
    global $wpdb;
    $t   = bccm_tables();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id), ARRAY_A);
    if (!$row) return new WP_Error('not_found', 'Không tìm thấy coachee');

    @set_time_limit(180);

    $answers    = json_decode($row['answer_json'] ?? '[]', true) ?: [];
    $ai_summary = json_decode($row['ai_summary'] ?? '[]', true) ?: [];

    $sys = "Bạn là HealthCoach toàn diện. Dựa trên hồ sơ sức khỏe, hãy xây dựng BẢN ĐỒ SỨC KHỎE chi tiết.
CHỈ trả về MỘT JSON hợp lệ (RFC8259):

{
  \"nutrition_plan\": {
    \"daily_calories\": 0,
    \"macros\": { \"protein_g\": 0, \"carbs_g\": 0, \"fat_g\": 0 },
    \"meal_plan\": [
      { \"meal\": \"Bữa sáng\", \"time\": \"7:00\", \"suggestions\": [] },
      { \"meal\": \"Bữa trưa\", \"time\": \"12:00\", \"suggestions\": [] },
      { \"meal\": \"Bữa tối\", \"time\": \"18:30\", \"suggestions\": [] },
      { \"meal\": \"Snack\", \"time\": \"15:00\", \"suggestions\": [] }
    ],
    \"supplements\": [],
    \"avoid_foods\": []
  },
  \"exercise_plan\": {
    \"weekly_sessions\": 0,
    \"plan\": [
      { \"day\": \"T2\", \"type\": \"\", \"duration_min\": 0, \"exercises\": [] }
    ],
    \"warm_up\": [],
    \"cool_down\": []
  },
  \"sleep_plan\": {
    \"target_hours\": 0,
    \"bedtime\": \"\",
    \"wake_time\": \"\",
    \"rituals\": []
  },
  \"stress_management\": {
    \"daily_practices\": [],
    \"weekly_activities\": [],
    \"emergency_techniques\": []
  },
  \"health_goals\": [
    { \"goal\": \"\", \"metric\": \"\", \"target\": \"\", \"deadline_weeks\": 0 }
  ],
  \"tracking_metrics\": [\"cân nặng\", \"giấc ngủ\", \"bước chân\", \"mood\"]
}";

    $usr = wp_json_encode([
      'profile'    => [
        'full_name' => $row['full_name'] ?? '',
        'dob'       => $row['dob'] ?? '',
        'height_cm' => $row['height_cm'] ?? '',
        'weight_kg' => $row['weight_kg'] ?? '',
      ],
      'answers'    => $answers,
      'ai_summary' => $ai_summary,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_health_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_health_parse_json($raw);
    if (!$data) {
      return new WP_Error('parse_fail', 'Không parse được Health Map JSON', ['raw' => $raw]);
    }

    $wpdb->update($t['profiles'], [
      'health_json' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
      'updated_at'  => current_time('mysql'),
    ], ['id' => (int)$coachee_id]);

    return true;
  }
}

/* =====================================================================
 * 3) GENERATE LIFE MAP – Bản đồ cuộc đời (mental_json)
 * Đây là lớp RAG quan trọng nhất → bổ sung vào bizcity_knowledge_chat
 * =====================================================================*/
if (!function_exists('bccm_generate_life_map')) {
  function bccm_generate_life_map($coachee_id) {
    global $wpdb;
    $t   = bccm_tables();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id), ARRAY_A);
    if (!$row) return new WP_Error('not_found', 'Không tìm thấy coachee');

    @set_time_limit(240);

    $answers    = json_decode($row['answer_json'] ?? '[]', true) ?: [];
    $ai_summary = json_decode($row['ai_summary'] ?? '[]', true) ?: [];
    $health     = json_decode($row['health_json'] ?? '[]', true) ?: [];

    // Lấy metrics
    $metrics = [];
    $m_row = $wpdb->get_row($wpdb->prepare(
      "SELECT numbers_full FROM {$wpdb->prefix}bccm_metrics WHERE coachee_id=%d", $coachee_id
    ), ARRAY_A);
    if ($m_row && !empty($m_row['numbers_full'])) {
      $metrics = json_decode($m_row['numbers_full'], true) ?: [];
    }

    $sys = "Bạn là Life Coach & Personal Strategist AI. Nhiệm vụ: tạo BẢN ĐỒ CUỘC ĐỜI (Life Map) toàn diện cho chủ nhân.
Bản đồ này sẽ được AI Agent sử dụng để hiểu sâu về chủ nhân, từ đó tư vấn cá nhân hóa.

CHỈ trả về MỘT JSON hợp lệ (RFC8259), tiếng Việt:

{
  \"identity\": {
    \"full_name\": \"\",
    \"dob\": \"\",
    \"age\": 0,
    \"life_stage\": \"\",
    \"core_values\": [],
    \"personality_traits\": [],
    \"communication_style\": \"\",
    \"decision_making_style\": \"\"
  },
  \"life_dimensions\": {
    \"career\": {
      \"current_status\": \"\",
      \"strengths\": [],
      \"gaps\": [],
      \"aspirations\": [],
      \"score\": 0
    },
    \"health\": {
      \"current_status\": \"\",
      \"strengths\": [],
      \"risks\": [],
      \"goals\": [],
      \"score\": 0
    },
    \"relationships\": {
      \"current_status\": \"\",
      \"strengths\": [],
      \"challenges\": [],
      \"goals\": [],
      \"score\": 0
    },
    \"finance\": {
      \"current_status\": \"\",
      \"strengths\": [],
      \"gaps\": [],
      \"goals\": [],
      \"score\": 0
    },
    \"personal_growth\": {
      \"current_status\": \"\",
      \"strengths\": [],
      \"areas_to_develop\": [],
      \"goals\": [],
      \"score\": 0
    },
    \"lifestyle\": {
      \"current_status\": \"\",
      \"habits_good\": [],
      \"habits_to_change\": [],
      \"goals\": [],
      \"score\": 0
    }
  },
  \"pain_points\": [
    { \"area\": \"\", \"description\": \"\", \"impact_level\": 0, \"root_cause\": \"\" }
  ],
  \"limitations\": [
    { \"type\": \"internal|external\", \"description\": \"\", \"overcoming_strategy\": \"\" }
  ],
  \"motivations\": [
    { \"source\": \"\", \"description\": \"\", \"strength_level\": 0 }
  ],
  \"life_vision\": {
    \"1_year\": \"\",
    \"3_year\": \"\",
    \"5_year\": \"\",
    \"life_purpose\": \"\",
    \"legacy\": \"\"
  },
  \"coaching_strategy\": {
    \"approach\": \"\",
    \"tone\": \"\",
    \"focus_areas\": [],
    \"triggers_to_avoid\": [],
    \"motivation_techniques\": [],
    \"check_in_frequency\": \"\",
    \"accountability_method\": \"\"
  },
  \"weekly_routines\": {
    \"morning_ritual\": [],
    \"evening_ritual\": [],
    \"weekly_review\": \"\",
    \"self_care\": []
  },
  \"numerology_insights\": {
    \"life_path_meaning\": \"\",
    \"soul_meaning\": \"\",
    \"personal_year_focus\": \"\",
    \"strength_from_numbers\": \"\",
    \"challenge_from_numbers\": \"\"
  },
  \"ai_agent_instructions\": {
    \"personality_brief\": \"\",
    \"communication_rules\": [],
    \"topics_of_interest\": [],
    \"sensitive_topics\": [],
    \"preferred_response_style\": \"\",
    \"encouragement_style\": \"\"
  }
}

NGUYÊN TẮC:
- Phân tích sâu câu trả lời, tìm pain points ẩn, giới hạn chưa nói ra.
- Kết hợp thần số học (nếu có) để gợi ý phong cách coaching phù hợp.
- ai_agent_instructions: viết rõ ràng để AI Agent khác đọc và hiểu cách tương tác với chủ nhân.
- Tất cả score (0-10), impact_level (1-10), strength_level (1-10).
- coaching_strategy.tone: ví dụ 'ấm áp nhưng thẳng thắn', 'nhẹ nhàng khích lệ', 'đanh thép thúc đẩy'.";

    $usr = wp_json_encode([
      'profile'  => [
        'full_name' => $row['full_name'] ?? '',
        'dob'       => $row['dob'] ?? '',
        'height_cm' => $row['height_cm'] ?? '',
        'weight_kg' => $row['weight_kg'] ?? '',
      ],
      'answers'        => $answers,
      'ai_summary'     => $ai_summary,
      'health_data'    => $health,
      'numerology'     => $metrics,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_health_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_health_parse_json($raw);
    if (!$data) {
      return new WP_Error('parse_fail', 'Không parse được Life Map JSON', ['raw' => $raw]);
    }

    // Lưu vào mental_json
    $wpdb->update($t['profiles'], [
      'mental_json' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
      'updated_at'  => current_time('mysql'),
    ], ['id' => (int)$coachee_id]);

    // === Đồng bộ sang bizcity_knowledge_sources nếu plugin knowledge tồn tại ===
    bccm_sync_lifemap_to_knowledge($coachee_id, $data, $row);

    return true;
  }
}

/* =====================================================================
 * 4) GENERATE MILESTONE CALENDAR 90 NGÀY (bizcoach_json)
 * =====================================================================*/
if (!function_exists('bccm_generate_health_milestone_calendar')) {
  function bccm_generate_health_milestone_calendar($coachee_id) {
    global $wpdb;
    $t   = bccm_tables();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id), ARRAY_A);
    if (!$row) return new WP_Error('not_found', 'Không tìm thấy coachee');

    @set_time_limit(180);

    $answers    = json_decode($row['answer_json'] ?? '[]', true) ?: [];
    $ai_summary = json_decode($row['ai_summary'] ?? '[]', true) ?: [];
    $health     = json_decode($row['health_json'] ?? '[]', true) ?: [];
    $life_map   = json_decode($row['mental_json'] ?? '[]', true) ?: [];

    $sys = "Bạn là HealthCoach + Life Coach. Tạo MILESTONE CALENDAR 90 NGÀY cho chủ nhân.
Đây là lộ trình hành trình đồng hành cùng AI Agent, tuần nào làm gì, mục tiêu gì.

CHỈ trả về MỘT JSON hợp lệ (RFC8259):

{
  \"meta\": {
    \"title\": \"Hành trình 90 ngày cùng [tên]\",
    \"start_date\": \"\",
    \"objective\": \"\",
    \"version\": \"1.0\"
  },
  \"phases\": [
    {
      \"key\": \"p1\",
      \"title\": \"Giai đoạn 1 – Khởi động & Nhận thức (Tuần 1–4)\",
      \"notes\": \"\",
      \"weeks\": [
        {
          \"week\": 1,
          \"title\": \"\",
          \"theme\": \"\",
          \"goal\": \"\",
          \"focus\": \"\",
          \"daily_habits\": [],
          \"tasks\": [],
          \"kpis\": [],
          \"milestone\": \"\",
          \"coach_check_in\": \"\",
          \"reflection_prompt\": \"\"
        }
      ]
    },
    {
      \"key\": \"p2\",
      \"title\": \"Giai đoạn 2 – Hành động & Xây thói quen (Tuần 5–8)\",
      \"notes\": \"\",
      \"weeks\": [{\"week\":5},{\"week\":6},{\"week\":7},{\"week\":8}]
    },
    {
      \"key\": \"p3\",
      \"title\": \"Giai đoạn 3 – Chuẩn hóa & Duy trì (Tuần 9–12)\",
      \"notes\": \"\",
      \"weeks\": [{\"week\":9},{\"week\":10},{\"week\":11},{\"week\":12}]
    }
  ],
  \"reminders\": [
    { \"type\": \"daily|weekly|milestone\", \"message\": \"\", \"day_of_week\": \"\", \"time\": \"\" }
  ],
  \"rewards\": [
    { \"milestone\": \"Tuần 4\", \"reward\": \"\" },
    { \"milestone\": \"Tuần 8\", \"reward\": \"\" },
    { \"milestone\": \"Tuần 12\", \"reward\": \"\" }
  ]
}

NGUYÊN TẮC:
- 3 giai đoạn, mỗi giai đoạn 4 tuần, tổng 12 tuần = ~90 ngày.
- Mỗi tuần phải có: theme, goal, focus, daily_habits (3–5 thói quen), tasks (3–7 việc cụ thể), kpis (2–4 chỉ số), milestone (1 cột mốc), coach_check_in (câu hỏi coach hỏi), reflection_prompt (câu journaling).
- Liên kết chặt với pain_points, limitations, motivations từ Life Map.
- Tăng dần độ khó: tuần đầu nhẹ → tuần cuối cao hơn.
- reminders: gợi ý nhắc nhở để cron job chạy.
- rewards: phần thưởng motivation cho mỗi cột mốc.";

    $usr = wp_json_encode([
      'profile'  => [
        'full_name' => $row['full_name'] ?? '',
        'dob'       => $row['dob'] ?? '',
      ],
      'answers'    => $answers,
      'ai_summary' => $ai_summary,
      'health'     => $health,
      'life_map'   => $life_map,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_health_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_health_parse_json($raw);
    if (!$data) {
      return new WP_Error('parse_fail', 'Không parse được Milestone Calendar JSON', ['raw' => $raw]);
    }

    // Xác định gen_key dựa trên coach_type
    $coach_type = $row['coach_type'] ?? 'health_coach';
    $gen_key = ($coach_type === 'career_coach') ? 'gen_career_milestone' : 'gen_milestone';

    bccm_save_gen_result(
      $coachee_id, $gen_key, 'bccm_generate_health_milestone_calendar',
      '90-Days Milestone Calendar', $coach_type, $data, 'bizcoach_json'
    );

    return true;
  }
}

/* =====================================================================
 * SYNC LIFE MAP → BIZCITY KNOWLEDGE (RAG layer)
 * =====================================================================*/
if (!function_exists('bccm_sync_lifemap_to_knowledge')) {
  function bccm_sync_lifemap_to_knowledge($coachee_id, $life_map_data, $profile) {
    global $wpdb;
    $table_sources = $wpdb->prefix . 'bizcity_knowledge_sources';

    // Kiểm tra bảng knowledge có tồn tại không
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_sources'") !== $table_sources) {
      return; // Plugin knowledge chưa active
    }

    // Build text context từ life map
    $name = $profile['full_name'] ?? 'Chủ nhân';
    $text_parts = [];

    $text_parts[] = "=== BẢN ĐỒ CUỘC ĐỜI CỦA {$name} ===";

    // Identity
    if (!empty($life_map_data['identity'])) {
      $id = $life_map_data['identity'];
      $text_parts[] = "Nhân dạng: {$name}";
      if (!empty($id['life_stage'])) $text_parts[] = "Giai đoạn cuộc đời: {$id['life_stage']}";
      if (!empty($id['core_values'])) $text_parts[] = "Giá trị cốt lõi: " . implode(', ', $id['core_values']);
      if (!empty($id['personality_traits'])) $text_parts[] = "Tính cách: " . implode(', ', $id['personality_traits']);
      if (!empty($id['communication_style'])) $text_parts[] = "Phong cách giao tiếp: {$id['communication_style']}";
    }

    // Life dimensions
    if (!empty($life_map_data['life_dimensions'])) {
      foreach ($life_map_data['life_dimensions'] as $dim_name => $dim) {
        if (!is_array($dim)) continue;
        $label = ucfirst(str_replace('_', ' ', $dim_name));
        $text_parts[] = "\n--- {$label} ---";
        if (!empty($dim['current_status'])) $text_parts[] = "Hiện trạng: {$dim['current_status']}";
        if (!empty($dim['strengths'])) $text_parts[] = "Điểm mạnh: " . implode(', ', $dim['strengths']);
        if (!empty($dim['gaps']) || !empty($dim['risks']) || !empty($dim['challenges'])) {
          $weaknesses = $dim['gaps'] ?? $dim['risks'] ?? $dim['challenges'] ?? [];
          $text_parts[] = "Cần cải thiện: " . implode(', ', $weaknesses);
        }
        if (!empty($dim['goals']) || !empty($dim['aspirations'])) {
          $goals = $dim['goals'] ?? $dim['aspirations'] ?? [];
          $text_parts[] = "Mục tiêu: " . implode(', ', $goals);
        }
      }
    }

    // Pain points
    if (!empty($life_map_data['pain_points'])) {
      $text_parts[] = "\n--- NỖI ĐAU ---";
      foreach ($life_map_data['pain_points'] as $pp) {
        if (!is_array($pp)) continue;
        $text_parts[] = "• {$pp['area']}: {$pp['description']} (mức ảnh hưởng: {$pp['impact_level']}/10)";
        if (!empty($pp['root_cause'])) $text_parts[] = "  Nguyên nhân gốc: {$pp['root_cause']}";
      }
    }

    // Limitations
    if (!empty($life_map_data['limitations'])) {
      $text_parts[] = "\n--- GIỚI HẠN ---";
      foreach ($life_map_data['limitations'] as $lim) {
        if (!is_array($lim)) continue;
        $text_parts[] = "• [{$lim['type']}] {$lim['description']}";
        if (!empty($lim['overcoming_strategy'])) $text_parts[] = "  Chiến lược vượt qua: {$lim['overcoming_strategy']}";
      }
    }

    // Motivations
    if (!empty($life_map_data['motivations'])) {
      $text_parts[] = "\n--- ĐỘNG LỰC ---";
      foreach ($life_map_data['motivations'] as $mot) {
        if (!is_array($mot)) continue;
        $text_parts[] = "• {$mot['source']}: {$mot['description']} (sức mạnh: {$mot['strength_level']}/10)";
      }
    }

    // Life vision
    if (!empty($life_map_data['life_vision'])) {
      $v = $life_map_data['life_vision'];
      $text_parts[] = "\n--- TẦM NHÌN CUỘC ĐỜI ---";
      if (!empty($v['1_year'])) $text_parts[] = "1 năm tới: {$v['1_year']}";
      if (!empty($v['3_year'])) $text_parts[] = "3 năm tới: {$v['3_year']}";
      if (!empty($v['5_year'])) $text_parts[] = "5 năm tới: {$v['5_year']}";
      if (!empty($v['life_purpose'])) $text_parts[] = "Mục đích sống: {$v['life_purpose']}";
    }

    // AI agent instructions
    if (!empty($life_map_data['ai_agent_instructions'])) {
      $ai = $life_map_data['ai_agent_instructions'];
      $text_parts[] = "\n--- HƯỚNG DẪN CHO AI AGENT ---";
      if (!empty($ai['personality_brief'])) $text_parts[] = "Tóm tắt: {$ai['personality_brief']}";
      if (!empty($ai['communication_rules'])) $text_parts[] = "Quy tắc giao tiếp: " . implode('; ', $ai['communication_rules']);
      if (!empty($ai['sensitive_topics'])) $text_parts[] = "Chủ đề nhạy cảm (tránh): " . implode(', ', $ai['sensitive_topics']);
      if (!empty($ai['preferred_response_style'])) $text_parts[] = "Phong cách trả lời: {$ai['preferred_response_style']}";
      if (!empty($ai['encouragement_style'])) $text_parts[] = "Phong cách khích lệ: {$ai['encouragement_style']}";
    }

    $knowledge_text = implode("\n", $text_parts);

    // Tìm character_id mặc định (từ webchat hoặc pmfacebook)
    $character_id = intval(get_option('bizcity_webchat_default_character_id', 0));
    if (empty($character_id)) {
      $bot_setup = get_option('pmfacebook_options', []);
      $character_id = isset($bot_setup['default_character_id']) ? intval($bot_setup['default_character_id']) : 0;
    }

    if (empty($character_id)) return; // Không có character thì không sync

    // Upsert: tìm source cũ hoặc tạo mới
    $source_url = "lifemap://coachee/{$coachee_id}";
    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table_sources WHERE url = %s AND character_id = %d",
      $source_url, $character_id
    ));

    $source_data = [
      'character_id'   => $character_id,
      'source_type'    => 'lifemap',
      'url'            => $source_url,
      'knowledge_text' => $knowledge_text,
      'status'         => 'ready',
    ];

    if ($existing) {
      $wpdb->update($table_sources, $source_data, ['id' => $existing]);
    } else {
      $wpdb->insert($table_sources, $source_data);
    }
  }
}

/* =====================================================================
 * SHARED HELPERS
 * =====================================================================*/

/** Gọi AI thông qua BizGPT proxy hoặc OpenAI trực tiếp */
if (!function_exists('bccm_health_call_ai')) {
  function bccm_health_call_ai($system_prompt, $user_content) {
    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
      return new WP_Error( 'no_openrouter', 'bizcity_openrouter_chat() chưa sẵn sàng.' );
    }

    $messages = [
      [ 'role' => 'system', 'content' => $system_prompt ],
      [ 'role' => 'user',   'content' => $user_content ],
    ];

    $result = bizcity_openrouter_chat( $messages, [
      'purpose'     => 'health_analysis',
      'temperature' => 0.7,
      'max_tokens'  => 4000,
    ] );

    if ( empty( $result['success'] ) ) {
      return new WP_Error( 'openrouter_error', $result['error'] ?? 'Unknown error' );
    }

    return $result['message'];
  }
}

/** Parse JSON từ AI output (loại bỏ markdown fences, tìm JSON object) */
if (!function_exists('bccm_health_parse_json')) {
  function bccm_health_parse_json($raw) {
    if (!is_string($raw) || trim($raw) === '') return null;

    // Loại bỏ code fences
    $s = trim($raw);
    $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
    $s = preg_replace('/\s*```$/i', '', $s);
    $s = trim($s);

    // Thử decode trực tiếp
    $data = json_decode($s, true);
    if (is_array($data)) return $data;

    // Tìm JSON object lớn nhất
    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $s, $m)) {
      $data = json_decode($m[0], true);
      if (is_array($data)) return $data;
    }

    // Fallback: dùng helper plugin nếu có
    if (function_exists('bccm_try_decode_json_relaxed')) {
      return bccm_try_decode_json_relaxed($s);
    }

    return null;
  }
}
