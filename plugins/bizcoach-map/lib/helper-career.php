<?php
/**
 * BizCoach Map – CareerCoach Helpers
 * 
 * Generators dựa trên dữ liệu chiêm tinh thực từ bảng bccm_astro
 * (summary, traits, llm_report) thay vì numeric_json (thần số học)
 * 
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * HELPER: Load Astro Data cho Coachee
 * =====================================================================*/
if (!function_exists('bccm_load_astro_data')) {
  /**
   * Load dữ liệu chiêm tinh từ bảng bccm_astro theo coachee_id
   * 
   * @param int $coachee_id
   * @return array {
   *   'western' => [...],  // Western chart data
   *   'vedic'   => [...],  // Vedic chart data
   *   'summary' => [...],  // Merged summary từ cả 2 chart
   *   'traits'  => [...],  // Merged traits
   *   'llm_report' => [...], // AI report chi tiết
   * }
   */
  function bccm_load_astro_data($coachee_id) {
    global $wpdb;
    $t = bccm_tables();
    
    // Lấy user_id từ coachee
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id
    ), ARRAY_A);
    
    if (!$coachee) return null;
    
    $user_id = (int)($coachee['user_id'] ?? 0);
    if (!$user_id) return null;
    
    // Load tất cả chart của user này
    $astro_table = $wpdb->prefix . 'bccm_astro';
    $charts = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$astro_table} WHERE user_id=%d OR coachee_id=%d ORDER BY created_at DESC",
      $user_id, (int)$coachee_id
    ), ARRAY_A);
    
    if (!$charts) return null;
    
    $result = [
      'western' => null,
      'vedic'   => null,
      'summary' => [],
      'traits'  => [],
      'llm_report' => [],
    ];
    
    foreach ($charts as $chart) {
      $type = strtolower($chart['chart_type'] ?? '');
      
      // Parse JSON columns
      $summary = !empty($chart['summary']) ? json_decode($chart['summary'], true) : [];
      $traits  = !empty($chart['traits']) ? json_decode($chart['traits'], true) : [];
      $llm_report = !empty($chart['llm_report']) ? json_decode($chart['llm_report'], true) : [];
      
      if ($type === 'western') {
        $result['western'] = [
          'id'         => $chart['id'],
          'summary'    => $summary,
          'traits'     => $traits,
          'llm_report' => $llm_report,
          'birth_info' => [
            'place'     => $chart['birth_place'] ?? '',
            'time'      => $chart['birth_time'] ?? '',
            'latitude'  => $chart['latitude'] ?? 0,
            'longitude' => $chart['longitude'] ?? 0,
            'timezone'  => $chart['timezone'] ?? '',
          ],
        ];
      } elseif ($type === 'vedic') {
        $result['vedic'] = [
          'id'         => $chart['id'],
          'summary'    => $summary,
          'traits'     => $traits,
          'llm_report' => $llm_report,
          'birth_info' => [
            'place'     => $chart['birth_place'] ?? '',
            'time'      => $chart['birth_time'] ?? '',
            'latitude'  => $chart['latitude'] ?? 0,
            'longitude' => $chart['longitude'] ?? 0,
            'timezone'  => $chart['timezone'] ?? '',
          ],
        ];
      }
    }
    
    // Merge summary & traits từ cả Western và Vedic
    if ($result['western']) {
      $result['summary'] = array_merge($result['summary'], $result['western']['summary'] ?? []);
      $result['traits']  = array_merge($result['traits'], $result['western']['traits'] ?? []);
      $result['llm_report'] = array_merge($result['llm_report'], $result['western']['llm_report'] ?? []);
    }
    
    if ($result['vedic']) {
      $result['summary'] = array_merge($result['summary'], $result['vedic']['summary'] ?? []);
      $result['traits']  = array_merge($result['traits'], $result['vedic']['traits'] ?? []);
      // Vedic llm_report có thể merge riêng nếu format khác
    }
    
    return $result;
  }
}

/* =====================================================================
 * 1) CAREER OVERVIEW (ai_summary)
 * =====================================================================*/
if (!function_exists('bccm_generate_career_overview')) {
  function bccm_generate_career_overview($coachee_id) {
    global $wpdb;
    $t = bccm_tables();
    
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id
    ), ARRAY_A);
    
    if (!$coachee) return new WP_Error('not_found', 'Không tìm thấy coachee');
    
    // Load astro data
    $astro = bccm_load_astro_data($coachee_id);
    if (!$astro) return new WP_Error('no_astro', 'Chưa có dữ liệu chiêm tinh');
    
    // Load answers
    $answers = !empty($coachee['answer_json']) ? json_decode($coachee['answer_json'], true) : [];
    
    $sys = "Bạn là CareerCoach chuyên gia, kết hợp chiêm tinh học (Western + Vedic Astrology) với tư vấn sự nghiệp.
CHỈ trả về MỘT JSON hợp lệ (RFC8259), không markdown, tiếng Việt UTF-8:

{
  \"overview\": \"[nhận xét tổng quan 3–5 câu về tiềm năng sự nghiệp dựa trên chiêm tinh]\",
  \"sun_sign\": \"[cung mặt trời]\",
  \"moon_sign\": \"[cung mặt trăng]\",
  \"rising_sign\": \"[cung Ascendant]\",
  \"career_score\": 0-100,
  \"career_archetype\": \"[kiểu hình sự nghiệp: Leader/Creator/Helper/Analyst/...]\",
  \"natural_talents\": [\"tài năng 1\", \"tài năng 2\", \"tài năng 3\"],
  \"career_challenges\": [\"thách thức 1\", \"thách thức 2\"],
  \"ideal_roles\": [\"vai trò lý tưởng 1\", \"vai trò 2\", \"vai trò 3\"],
  \"work_style\": \"[phong cách làm việc phù hợp]\",
  \"leadership_potential\": 0-100,
  \"communication_style\": \"[cách giao tiếp trong công việc]\",
  \"growth_areas\": [\"kỹ năng cần phát triển 1\", \"kỹ năng 2\"],
  \"career_timing\": {
    \"current_phase\": \"[giai đoạn hiện tại: tích lũy/bứt phá/chuyển đổi/...]\",
    \"favorable_period\": \"[thời kỳ thuận lợi sắp tới]\",
    \"caution_period\": \"[thời kỳ cần cẩn trọng]\"
  }
}";

    $usr = wp_json_encode([
      'coachee' => [
        'name'             => $coachee['full_name'] ?? '',
        'dob'              => $coachee['dob'] ?? '',
        'current_role'     => $coachee['current_role'] ?? '',
        'years_experience' => $coachee['years_experience'] ?? 0,
        'education_level'  => $coachee['education_level'] ?? '',
      ],
      'astro_data' => [
        'western_summary' => $astro['western']['summary'] ?? [],
        'western_traits'  => $astro['western']['traits'] ?? [],
        'vedic_summary'   => $astro['vedic']['summary'] ?? [],
        'vedic_traits'    => $astro['vedic']['traits'] ?? [],
        'llm_report'      => $astro['llm_report'] ?? [],
      ],
      'answers' => $answers,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_career_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_career_parse_json($raw);
    if (!$data) return new WP_Error('parse_error', 'Không parse được JSON từ AI');

    bccm_save_gen_result(
      $coachee_id, 'gen_career_overview', 'bccm_generate_career_overview',
      'Career Overview', 'career_coach', $data, 'ai_summary'
    );

    return true;
  }
}

/* =====================================================================
 * 2) CAREER VISION MAP (vision_json)
 * =====================================================================*/
if (!function_exists('bccm_generate_career_vision')) {
  function bccm_generate_career_vision($coachee_id) {
    global $wpdb;
    $t = bccm_tables();
    
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id
    ), ARRAY_A);
    
    if (!$coachee) return new WP_Error('not_found', 'Không tìm thấy coachee');
    
    $astro = bccm_load_astro_data($coachee_id);
    if (!$astro) return new WP_Error('no_astro', 'Chưa có dữ liệu chiêm tinh');
    
    $answers = !empty($coachee['answer_json']) ? json_decode($coachee['answer_json'], true) : [];
    
    $sys = "Bạn là CareerCoach Vision Strategist. Dựa trên chiêm tinh + câu trả lời, xây dựng TẦM NHÌN SỰ NGHIỆP cá nhân.
CHỈ trả về MỘT JSON hợp lệ (RFC8259):

{
  \"vision_statement\": \"[câu tuyên bố tầm nhìn 1-2 câu mạnh mẽ]\",
  \"career_purpose\": \"[mục đích sự nghiệp sâu xa]\",
  \"core_values\": [\"giá trị 1\", \"giá trị 2\", \"giá trị 3\"],
  \"dream_roles\": [
    {\"role\": \"[vai trò mơ ước]\", \"why\": \"[lý do phù hợp]\", \"timeline\": \"[1-3-5 năm]\"}
  ],
  \"impact_goals\": [\"ảnh hưởng 1\", \"ảnh hưởng 2\"],
  \"legacy_statement\": \"[điều muốn để lại sau sự nghiệp]\",
  \"milestones\": [
    {\"year\": 1, \"goal\": \"[mục tiêu năm 1]\"}, 
    {\"year\": 3, \"goal\": \"[mục tiêu năm 3]\"}, 
    {\"year\": 5, \"goal\": \"[mục tiêu năm 5]\"}
  ]
}";

    $usr = wp_json_encode([
      'coachee' => [
        'name'             => $coachee['full_name'] ?? '',
        'current_role'     => $coachee['current_role'] ?? '',
        'ai_summary'       => json_decode($coachee['ai_summary'] ?? '{}', true),
      ],
      'astro_data' => $astro,
      'answers'    => $answers,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_career_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_career_parse_json($raw);
    if (!$data) return new WP_Error('parse_error', 'Không parse được JSON');

    bccm_save_gen_result(
      $coachee_id, 'gen_career_vision', 'bccm_generate_career_vision',
      'Career Vision', 'career_coach', $data, 'vision_json'
    );

    return true;
  }
}

/* =====================================================================
 * 3) CAREER SWOT (swot_json) - Thế mạnh & Điểm yếu BẢN THÂN
 * =====================================================================*/
if (!function_exists('bccm_generate_career_swot')) {
  function bccm_generate_career_swot($coachee_id) {
    global $wpdb;
    $t = bccm_tables();
    
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id
    ), ARRAY_A);
    
    if (!$coachee) return new WP_Error('not_found', 'Không tìm thấy coachee');
    
    $astro = bccm_load_astro_data($coachee_id);
    if (!$astro) return new WP_Error('no_astro', 'Chưa có dữ liệu chiêm tinh');
    
    $answers = !empty($coachee['answer_json']) ? json_decode($coachee['answer_json'], true) : [];
    
    $sys = "Bạn là CareerCoach SWOT Analyst. Phân tích SWOT BẢN THÂN (Personal SWOT) dựa trên chiêm tinh + thực tế.
CHỈ trả về MỘT JSON hợp lệ (RFC8259):

{
  \"strengths\": [
    {\"item\": \"[thế mạnh]\", \"evidence\": \"[chứng cứ từ chiêm tinh hoặc kinh nghiệm]\", \"leverage\": \"[cách tận dụng]\"}
  ],
  \"weaknesses\": [
    {\"item\": \"[điểm yếu]\", \"impact\": \"[ảnh hưởng]\", \"mitigation\": \"[cách khắc phục]\"}
  ],
  \"opportunities\": [
    {\"item\": \"[cơ hội]\", \"timing\": \"[thời điểm]\", \"action\": \"[hành động cần làm]\"}
  ],
  \"threats\": [
    {\"item\": \"[thách thức]\", \"risk_level\": \"[cao/trung bình/thấp]\", \"response\": \"[cách ứng phó]\"}
  ],
  \"swot_strategy\": \"[chiến lược tổng thể kết hợp S-W-O-T]\"
}";

    $usr = wp_json_encode([
      'coachee'    => [
        'name'             => $coachee['full_name'] ?? '',
        'current_role'     => $coachee['current_role'] ?? '',
        'years_experience' => $coachee['years_experience'] ?? 0,
        'ai_summary'       => json_decode($coachee['ai_summary'] ?? '{}', true),
      ],
      'astro_data' => $astro,
      'answers'    => $answers,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_career_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_career_parse_json($raw);
    if (!$data) return new WP_Error('parse_error', 'Không parse được JSON');

    bccm_save_gen_result(
      $coachee_id, 'gen_career_swot', 'bccm_generate_career_swot',
      'Career SWOT', 'career_coach', $data, 'swot_json'
    );

    return true;
  }
}

/* =====================================================================
 * 4) CAREER VALUE MAP (value_json) - Giá trị Bản thân Hướng đến
 * =====================================================================*/
if (!function_exists('bccm_generate_career_value')) {
  function bccm_generate_career_value($coachee_id) {
    global $wpdb;
    $t = bccm_tables();
    
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id
    ), ARRAY_A);
    
    if (!$coachee) return new WP_Error('not_found', 'Không tìm thấy coachee');
    
    $astro = bccm_load_astro_data($coachee_id);
    if (!$astro) return new WP_Error('no_astro', 'Chưa có dữ liệu chiêm tinh');
    
    $answers = !empty($coachee['answer_json']) ? json_decode($coachee['answer_json'], true) : [];
    
    $sys = "Bạn là CareerCoach Value Architect. Xác định hệ GIÁ TRỊ cá nhân trong sự nghiệp dựa trên chiêm tinh + câu trả lời.
CHỈ trả về MỘT JSON hợp lệ (RFC8259):

{
  \"core_values\": [
    {\"value\": \"[giá trị cốt lõi]\", \"definition\": \"[định nghĩa]\", \"importance_score\": 0-10}
  ],
  \"work_values\": [\"giá trị công việc 1\", \"giá trị 2\", \"giá trị 3\"],
  \"non_negotiables\": [\"điều không thể thỏa hiệp 1\", \"điều 2\"],
  \"value_conflicts\": [
    {\"conflict\": \"[xung đột giá trị]\", \"resolution\": \"[cách hòa giải]\"}
  ],
  \"value_alignment\": {
    \"current_job\": 0-10,
    \"ideal_job\": \"[mô tả công việc lý tưởng phù hợp giá trị]\"
  },
  \"contribution_model\": \"[cách bạn muốn đóng góp cho thế giới qua sự nghiệp]\"
}";

    $usr = wp_json_encode([
      'coachee'    => [
        'name'         => $coachee['full_name'] ?? '',
        'current_role' => $coachee['current_role'] ?? '',
        'vision_json'  => json_decode($coachee['vision_json'] ?? '{}', true),
      ],
      'astro_data' => $astro,
      'answers'    => $answers,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_career_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_career_parse_json($raw);
    if (!$data) return new WP_Error('parse_error', 'Không parse được JSON');

    bccm_save_gen_result(
      $coachee_id, 'gen_career_value', 'bccm_generate_career_value',
      'Career Value Map', 'career_coach', $data, 'value_json'
    );

    return true;
  }
}

/* =====================================================================
 * 5) CAREER WINNING MODEL (winning_json) - Công thức Chiến thắng
 * =====================================================================*/
if (!function_exists('bccm_generate_career_winning')) {
  function bccm_generate_career_winning($coachee_id) {
    global $wpdb;
    $t = bccm_tables();
    
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id
    ), ARRAY_A);
    
    if (!$coachee) return new WP_Error('not_found', 'Không tìm thấy coachee');
    
    $astro = bccm_load_astro_data($coachee_id);
    if (!$astro) return new WP_Error('no_astro', 'Chưa có dữ liệu chiêm tinh');
    
    $answers = !empty($coachee['answer_json']) ? json_decode($coachee['answer_json'], true) : [];
    
    $sys = "Bạn là CareerCoach Strategy Designer. Xây dựng CÔNG THỨC CHIẾN THẮNG cá nhân (Personal Winning Formula).
CHỈ trả về MỘT JSON hợp lệ (RFC8259):

{
  \"what\": \"[Điều gì bạn làm tốt nhất - Unique Ability]\",
  \"why\": \"[Tại sao bạn làm điều đó - Core Motivation]\",
  \"how\": \"[Cách bạn làm khác biệt - Methodology]\",
  \"who\": \"[Ai hưởng lợi từ công việc của bạn - Target Impact]\",
  \"winning_formula\": \"[công thức tóm gọn: When I [what], for [who], by [how], because [why]]\",
  \"competitive_advantages\": [\"lợi thế cạnh tranh 1\", \"lợi thế 2\", \"lợi thế 3\"],
  \"success_patterns\": [
    {\"pattern\": \"[khuôn mẫu thành công đã từng có]\", \"repeatability\": \"[cách lặp lại]\"} 
  ],
  \"growth_multipliers\": [\"yếu tố nhân sức 1\", \"yếu tố 2\"],
  \"execution_principles\": [\"nguyên tắc hành động 1\", \"nguyên tắc 2\", \"nguyên tắc 3\"]
}";

    $usr = wp_json_encode([
      'coachee'    => [
        'name'         => $coachee['full_name'] ?? '',
        'current_role' => $coachee['current_role'] ?? '',
        'swot_json'    => json_decode($coachee['swot_json'] ?? '{}', true),
        'value_json'   => json_decode($coachee['value_json'] ?? '{}', true),
      ],
      'astro_data' => $astro,
      'answers'    => $answers,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_career_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_career_parse_json($raw);
    if (!$data) return new WP_Error('parse_error', 'Không parse được JSON');

    bccm_save_gen_result(
      $coachee_id, 'gen_career_winning', 'bccm_generate_career_winning',
      'Career Winning Model', 'career_coach', $data, 'winning_json'
    );

    return true;
  }
}

/* =====================================================================
 * 6) CAREER LEADERSHIP MAP (iqmap_json) - Bản đồ Nhà lãnh đạo
 * =====================================================================*/
if (!function_exists('bccm_generate_career_leadership')) {
  function bccm_generate_career_leadership($coachee_id) {
    global $wpdb;
    $t = bccm_tables();
    
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id
    ), ARRAY_A);
    
    if (!$coachee) return new WP_Error('not_found', 'Không tìm thấy coachee');
    
    $astro = bccm_load_astro_data($coachee_id);
    if (!$astro) return new WP_Error('no_astro', 'Chưa có dữ liệu chiêm tinh');
    
    $answers = !empty($coachee['answer_json']) ? json_decode($coachee['answer_json'], true) : [];
    
    $sys = "Bạn là CareerCoach Leadership Expert. Đánh giá TIỀM NĂNG NHÀ LÃNH ĐẠO dựa trên chiêm tinh + kinh nghiệm.
CHỈ trả về MỘT JSON hợp lệ (RFC8259):

{
  \"leadership_archetype\": \"[kiểu lãnh đạo: Visionary/Executor/Coach/Innovator/...]\",
  \"leadership_scores\": {
    \"vision\": 0-10,
    \"execution\": 0-10,
    \"people_management\": 0-10,
    \"strategic_thinking\": 0-10,
    \"communication\": 0-10,
    \"decision_making\": 0-10,
    \"emotional_intelligence\": 0-10,
    \"influence\": 0-10
  },
  \"natural_leadership_style\": \"[phong cách lãnh đạo tự nhiên]\",
  \"leadership_strengths\": [\"điểm mạnh 1\", \"điểm mạnh 2\", \"điểm mạnh 3\"],
  \"leadership_blind_spots\": [\"điểm mù 1\", \"điểm mù 2\"],
  \"team_dynamics\": {
    \"ideal_team_size\": \"[nhỏ/trung bình/lớn]\",
    \"preferred_team_culture\": \"[văn hóa đội nhóm ưa thích]\",
    \"conflict_resolution_style\": \"[cách giải quyết xung đột]\"
  },
  \"leadership_development\": [
    {\"skill\": \"[kỹ năng cần phát triển]\", \"priority\": \"[cao/trung bình/thấp]\", \"approach\": \"[cách học]\"}
  ],
  \"leadership_readiness\": {
    \"current_level\": \"[Individual Contributor/Team Lead/Manager/Director/Executive]\",
    \"next_level\": \"[cấp tiếp theo]\",
    \"gap_analysis\": \"[khoảng cách cần rút ngắn]\",
    \"timeline\": \"[thời gian ước tính để lên cấp]\"
  }
}";

    $usr = wp_json_encode([
      'coachee'    => [
        'name'             => $coachee['full_name'] ?? '',
        'current_role'     => $coachee['current_role'] ?? '',
        'years_experience' => $coachee['years_experience'] ?? 0,
        'ai_summary'       => json_decode($coachee['ai_summary'] ?? '{}', true),
        'winning_json'     => json_decode($coachee['winning_json'] ?? '{}', true),
      ],
      'astro_data' => $astro,
      'answers'    => $answers,
    ], JSON_UNESCAPED_UNICODE);

    $raw = bccm_career_call_ai($sys, $usr);
    if (is_wp_error($raw)) return $raw;

    $data = bccm_career_parse_json($raw);
    if (!$data) return new WP_Error('parse_error', 'Không parse được JSON');

    bccm_save_gen_result(
      $coachee_id, 'gen_career_leadership', 'bccm_generate_career_leadership',
      'Career Leadership Map', 'career_coach', $data, 'iqmap_json'
    );

    return true;
  }
}

/* =====================================================================
 * HELPER FUNCTIONS: AI Call & JSON Parse
 * =====================================================================*/

/** Gọi AI thông qua BizGPT proxy hoặc OpenAI trực tiếp */
if (!function_exists('bccm_career_call_ai')) {
  function bccm_career_call_ai($system_prompt, $user_input) {
    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
      return new WP_Error( 'no_openrouter', 'bizcity_openrouter_chat() chưa sẵn sàng.' );
    }

    $messages = [
      [ 'role' => 'system', 'content' => $system_prompt ],
      [ 'role' => 'user',   'content' => $user_input ],
    ];

    $result = bizcity_openrouter_chat( $messages, [
      'purpose'     => 'career_analysis',
      'temperature' => 0.7,
      'max_tokens'  => 2000,
    ] );

    if ( empty( $result['success'] ) ) {
      return new WP_Error( 'openrouter_error', $result['error'] ?? 'Unknown error' );
    }

    return $result['message'];
  }
}

/** Parse JSON từ AI output (loại bỏ markdown fences, tìm JSON object) */
if (!function_exists('bccm_career_parse_json')) {
  function bccm_career_parse_json($raw_text) {
    if (empty($raw_text)) return null;

    // Loại bỏ markdown code fences
    $text = preg_replace('/```json\s*/', '', $raw_text);
    $text = preg_replace('/```\s*$/', '', $text);
    $text = trim($text);

    // Thử decode trực tiếp
    $data = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
      return $data;
    }

    // Tìm JSON object trong text
    if (preg_match('/\{.*\}/s', $text, $matches)) {
      $data = json_decode($matches[0], true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        return $data;
      }
    }

    return null;
  }
}
