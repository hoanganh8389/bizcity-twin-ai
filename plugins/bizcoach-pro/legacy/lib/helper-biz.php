<?php

/**
 * Sinh Mission • Vision Map cho coachee rồi lưu vào profiles.vision_json
 * - Thu thập: profile, ai_summary (overview), numbers_full (21 chỉ số), answers (bảng hỏi)
 * - Gọi BizGPT -> trả về JSON theo schema BÊN DƯỚI
 * - Chuẩn hoá, fill mặc định rỗng an toàn, lưu DB
 */
function bccm_generate_vision_map($coachee_id){
  global $wpdb; $t=bccm_tables();
  @set_time_limit(180);

  // ----- 0) Nạp dữ liệu nguồn
  $profile = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id
  ), ARRAY_A);
  if (!$profile) return new WP_Error('profile_nf','Không tìm thấy hồ sơ coachee');

  $ans_row  = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$t['answers']} WHERE coachee_id=%d", $coachee_id
  ), ARRAY_A);
  $answers  = $ans_row && $ans_row['answers'] ? json_decode($ans_row['answers'], true) : [];

  // metrics (21 chỉ số)
  $numbers_full = [];
  $row_metrics = $wpdb->get_row($wpdb->prepare(
    "SELECT numbers_full FROM {$wpdb->prefix}bccm_metrics WHERE coachee_id=%d", $coachee_id
  ), ARRAY_A);
  if ($row_metrics && !empty($row_metrics['numbers_full'])) {
    $numbers_full = json_decode($row_metrics['numbers_full'], true) ?: [];
  }

  // overview ngắn gọn & vài chỉ số cơ bản (để AI bám theo)
  $ai_summary = json_decode($profile['ai_summary'] ?? '[]', true);
  $life_path  = $ai_summary['life_path'] ?? ($numbers_full['life_path']['value'] ?? '');
  $soul_num   = $ai_summary['soul_number'] ?? ($numbers_full['soul_number']['value'] ?? '');
  $overview   = is_array($ai_summary['overview'] ?? null) ? ($ai_summary['overview']['overview'] ?? '') : ($ai_summary['overview'] ?? '');

  // ----- 1) Build prompt
  $sys = "Bạn là BizCoach nhiều kinh nghiệm, kết hợp giáo trình Bizcoach, Business Model Canvas (Osterwalder) và Value Proposition Canvas (Strategyzer).
CHỈ trả về MỘT JSON hợp lệ (RFC8259), không markdown/code fences, tiếng Việt UTF-8, theo SCHEMA:

{
  \"mission\": \"[1 đoạn 2–3 câu, rõ mục tiêu tồn tại của DN]\",
  \"vision\": \"[1 đoạn 2–3 câu, viễn cảnh 3–5 năm]\",

  \"bmc\": {
    \"key_partners\": [],
    \"key_activities\": [],
    \"value_propositions\": [],
    \"customer_relationships\": [],
    \"customer_segments\": [],
    \"key_resources\": [],
    \"channels\": [],
    \"cost_structure\": [],
    \"revenue_streams\": []
  },

  \"vpc\": {
    \"customer_jobs\": [],
    \"pains\": [],
    \"gains\": [],
    \"products_services\": [],
    \"pain_relievers\": [],
    \"gain_creators\": []
  },

  \"brand\": {
    \"archetype\": \"\",                 // archetype thương hiệu (nếu phù hợp)
    \"tone_of_voice\": [],              // giọng điệu
    \"colors\": [],                     // bảng màu HEX hoặc tên màu
    \"logo_ideas\": [],                 // hình tượng/ý tưởng logo
    \"symbols\": [],                    // biểu tượng nên dùng
    \"keywords\": [],                   // từ khoá định vị
    \"slogan_options\": []              // 3-5 đề xuất slogan ngắn gọn
  }
}

NGUYÊN TẮC:
- Liên hệ hợp lý với Life Path và Soul Number để gợi ý định vị/giọng điệu/thế mạnh (không huyền bí hoá).
- BMC/VPC mạch lạc, mỗi mảng 3–7 gạch đầu dòng, thực dụng, có thể triển khai.
- Brand: chọn 1–2 gam màu chủ đạo + 1 phụ; slogan ≤ 8 từ, dễ nhớ.
";

  // đóng gói user payload (chỉ thông tin cần thiết, tránh dài)
  $payload = [
    'profile' => [
      'id'         => (int)$profile['id'],
      'full_name'  => $profile['full_name'] ?? '',
      'company'    => $profile['company_name'] ?? '',
      'industry'   => $profile['company_industry'] ?? '',
      'dob'        => $profile['dob'] ?? '',
      'coach_type' => $profile['coach_type'] ?? '',
    ],
    'overview' => [
      'text'        => $overview,
      'life_path'   => (string)$life_path,
      'soul_number' => (string)$soul_num,
    ],
    'answers' => $answers,           // câu trả lời bảng hỏi (để AI suy luận)
    'numbers_full' => $numbers_full  // 21 chỉ số (value/brief/meaning)
  ];

  $user = "Dữ liệu đầu vào:\n".wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\nHãy trả về CHỈ JSON đúng schema.";

  // ----- 2) Call BizGPT
  $api_key = get_option('bccm_openai_api_key',''); // để tương thích hàm gọi
  $message = "SYSTEM:\n{$sys}\n\nUSER:\n{$user}";
  $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, $message, false);

  if (!is_string($raw) || trim($raw)==='') {
    return new WP_Error('ai_empty','Không nhận được phản hồi từ BizGPT');
  }

  // ----- 3) Parse JSON + normalize
  $vision = json_decode($raw, true);
  if (!is_array($vision)) {
    // thử bắt JSON trong chuỗi
    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) {
      $vision = json_decode($m[0], true);
    }
  }
  if (!is_array($vision)) {
    return new WP_Error('ai_parse','AI không trả JSON hợp lệ', ['raw'=>$raw]);
  }

  // fill defaults an toàn
  $vision = array_merge([
    'mission' => '',
    'vision'  => '',
    'bmc'     => [
      'key_partners'=>[], 'key_activities'=>[], 'value_propositions'=>[],
      'customer_relationships'=>[], 'customer_segments'=>[],
      'key_resources'=>[], 'channels'=>[],
      'cost_structure'=>[], 'revenue_streams'=>[]
    ],
    'vpc'     => [
      'customer_jobs'=>[], 'pains'=>[], 'gains'=>[],
      'products_services'=>[], 'pain_relievers'=>[], 'gain_creators'=>[]
    ],
    'brand'   => [
      'archetype'=>'', 'tone_of_voice'=>[], 'colors'=>[],
      'logo_ideas'=>[], 'symbols'=>[], 'keywords'=>[], 'slogan_options'=>[]
    ],
  ], $vision);

  // ----- 4) Lưu DB
  $wpdb->update($t['profiles'], [
    'vision_json' => wp_json_encode($vision, JSON_UNESCAPED_UNICODE),
    'updated_at'  => current_time('mysql'),
  ], ['id'=>$coachee_id]);

  return $vision;
}
/**
 * Sinh SWOT theo chủ đề và lưu coachees.swot_json
 * Dựa trên: 21 chỉ số, ai_summary, vision_json (+ answers nếu cần)
 */
function bccm_generate_swot_map($coachee_id){
  global $wpdb; $t=bccm_tables();
  @set_time_limit(180);

  // ---- Load nguồn
  $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
  if(!$profile) return new WP_Error('profile_nf','Không tìm thấy hồ sơ coachee');

  $ans_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['answers']} WHERE coachee_id=%d", $coachee_id), ARRAY_A);
  $answers = $ans_row && $ans_row['answers'] ? json_decode($ans_row['answers'], true) : [];

  $summary = json_decode($profile['ai_summary'] ?? '[]', true);
  $vision  = json_decode($profile['vision_json'] ?? '[]', true);

  $row_m = $wpdb->get_row($wpdb->prepare("SELECT numbers_full FROM {$wpdb->prefix}bccm_metrics WHERE coachee_id=%d", $coachee_id), ARRAY_A);
  $numbers_full = $row_m && !empty($row_m['numbers_full']) ? (json_decode($row_m['numbers_full'], true) ?: []) : [];

  $life_path = (string)($summary['life_path'] ?? ($numbers_full['life_path']['value'] ?? ''));
  $soul      = (string)($summary['soul_number'] ?? ($numbers_full['soul_number']['value'] ?? ''));
  $overview  = is_array($summary['overview'] ?? null) ? ($summary['overview']['overview'] ?? '') : ($summary['overview'] ?? '');

  // ---- Prompt
  $topics = ['marketing','innovation','technology','management_team','finance','employee','business_model','operations'];
  $sys = "Bạn là BizCoach. Hãy lập SWOT theo chủ đề cho một doanh nghiệp, kết hợp dữ liệu nhân số học (life path/soul number) như gợi ý định hướng, KHÔNG huyền bí hoá.
Trả về DUY NHẤT JSON (RFC8259), tiếng Việt UTF-8, không markdown.

SCHEMA:
{
  \"meta\": {\"company\":\"\",\"industry\":\"\",\"life_path\":\"\",\"soul_number\":\"\"},
  \"swot\": {
    \"<topic>\": {
      \"strengths\": [\"...\"],
      \"weaknesses\": [\"...\"],
      \"opportunities\": [\"...\"],
      \"threats\": [\"...\"],
      \"actions\": [\"3–7 hành động ưu tiên, đo được\"],
      \"kpis\": [\"2–5 KPI gợi ý\"]
    }
    // topics: marketing, innovation, technology, management_team, finance, employee, business_model, operations
  },
  \"priorities\": [ {\"topic\":\"...\",\"why\":\"1 câu\",\"quarter\":\"Q1|Q2|Q3|Q4\"} ]
}

QUY TẮC:
- Mỗi mục strengths/weaknesses/opportunities/threats: 3–7 ý cụ thể.
- Actions theo nguyên tắc 80/20, bám vision/bmc/vpc nếu có.
- KPI ngắn gọn: ví dụ % chuyển đổi, CAC, NPS, GM%, vòng quay hàng tồn...
- Ngôn ngữ thực dụng, không chung chung.";
  
  $payload = [
    'profile'=>[
      'id'=>(int)$profile['id'],
      'company'=>$profile['company_name'] ?? '',
      'industry'=>$profile['company_industry'] ?? '',
      'coach_type'=>$profile['coach_type'] ?? '',
    ],
    'overview'=>[
      'text'=>$overview,
      'life_path'=>$life_path,
      'soul_number'=>$soul
    ],
    'vision'=>$vision,               // mission/vision + bmc + vpc + brand
    'numbers_full'=>$numbers_full,   // 21 chỉ số
    'answers'=>$answers,
    'topics'=>$topics
  ];
  $user = "DỮ LIỆU:\n".wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nHãy trả về CHỈ JSON đúng SCHEMA.";

  $api_key = get_option('bccm_openai_api_key','');
  $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, "SYSTEM:\n{$sys}\n\nUSER:\n{$user}", false);
  if (!is_string($raw) || trim($raw)==='') return new WP_Error('ai_empty','Không nhận phản hồi từ BizGPT');

  // ---- Parse + normalize
  if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) $raw = $m[0];
  $data = json_decode($raw, true);
  if (!is_array($data)) return new WP_Error('ai_parse','AI không trả JSON hợp lệ', ['raw'=>$raw]);

  // defaults
  $emptyTopic = ['strengths'=>[],'weaknesses'=>[],'opportunities'=>[],'threats'=>[],'actions'=>[],'kpis'=>[]];
  $out = [
    'meta'=>[
      'company'=>$profile['company_name'] ?? '',
      'industry'=>$profile['company_industry'] ?? '',
      'life_path'=>$life_path,
      'soul_number'=>$soul
    ],
    'swot'=>[],
    'priorities'=>[]
  ];
  foreach ($topics as $tp){
    $out['swot'][$tp] = array_merge($emptyTopic, (array)($data['swot'][$tp] ?? []));
  }
  if (!empty($data['priorities']) && is_array($data['priorities'])) {
    $out['priorities'] = $data['priorities'];
  }

  // ---- Save
  $wpdb->update($t['profiles'], [
    'swot_json' => wp_json_encode($out, JSON_UNESCAPED_UNICODE),
    'updated_at'=> current_time('mysql')
  ], ['id'=>$coachee_id]);

  return $out;
}
/**
 * Sinh insight khách hàng + ma trận ưu tiên (customer & value map) + Magic Triangle
 * Lưu vào profiles.customer_json
 */
function bccm_generate_customer_insight_map($coachee_id){
  global $wpdb; $t=bccm_tables();
  @set_time_limit(180);

  $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
  if (!$profile) return new WP_Error('profile_nf','Không tìm thấy hồ sơ');

  $ai_summary = json_decode($profile['ai_summary'] ?? '[]', true);
  $vision     = json_decode($profile['vision_json'] ?? '[]', true);
  $swot       = json_decode($profile['swot_json'] ?? '[]', true);

  $row_m = $wpdb->get_row($wpdb->prepare("SELECT numbers_full FROM {$wpdb->prefix}bccm_metrics WHERE coachee_id=%d", $coachee_id), ARRAY_A);
  $numbers_full = $row_m && !empty($row_m['numbers_full']) ? (json_decode($row_m['numbers_full'], true) ?: []) : [];

  $ans_row  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['answers']} WHERE coachee_id=%d", $coachee_id), ARRAY_A);
  $answers  = $ans_row && $ans_row['answers'] ? json_decode($ans_row['answers'], true) : [];

  $life_path = (string)($ai_summary['life_path'] ?? ($numbers_full['life_path']['value'] ?? ''));
  $soul_num  = (string)($ai_summary['soul_number'] ?? ($numbers_full['soul_number']['value'] ?? ''));
  $overview  = is_array($ai_summary['overview'] ?? null) ? ($ai_summary['overview']['overview'] ?? '') : ($ai_summary['overview'] ?? '');

  // --- PROMPT
  $sys = "Bạn là BizCoach. Hãy tổng hợp INSIGHT KHÁCH HÀNG và ma trận ưu tiên (Customer Priority & Value Map Priority),
dựa theo khung Strategyzer & Magic Triangle. Trả về DUY NHẤT JSON (RFC8259), tiếng Việt UTF-8, không markdown.

SCHEMA:
{
  \"magic_triangle\": {
    \"what\": \"[sản phẩm/giải pháp chính, 1–2 câu]\",
    \"why\":  \"[vì sao mô hình có lợi nhuận/giá trị, 1–2 câu]\",
    \"how\":  \"[chuỗi giá trị/ kênh tạo & giao giá trị, 1–2 câu]\",
    \"who\":  \"[ân tượng phân khúc/ICP trọng tâm, 1–2 câu]\"
  },
  \"customer_priority_matrix\": {
    \"jobs_importance\":   [{\"text\":\"...\",\"score\":1-10}],
    \"gains_importance\":  [{\"text\":\"...\",\"score\":1-10}],
    \"pains_importance\":  [{\"text\":\"...\",\"score\":1-10}]
  },
  \"value_map_priority_matrix\": {
    \"products_services_importance\": [{\"text\":\"...\",\"score\":1-10,\"maps_to_jobs\":[\"...\"],\"maps_to_pains\":[\"...\"],\"maps_to_gains\":[\"...\"]}],
    \"gain_creators_importance\":     [{\"text\":\"...\",\"score\":1-10,\"maps_to_gains\":[\"...\"]}],
    \"pain_relievers_importance\":    [{\"text\":\"...\",\"score\":1-10,\"maps_to_pains\":[\"...\"]}]
  },
  \"personas\": [
    {\"name\":\"\",\"segment\":\"\",\"goals\":[\"\"],\"top_jobs\":[\"\"],\"pains\":[\"\"],\"gains\":[\"\"],\"channels\":[\"\"]}
  ],
  \"jtbd\": [\"Việc cần làm theo format: Khi [tình huống], tôi muốn [động cơ], để [kết quả].\"],
  \"actions\": [\"3–7 hành động nghiên cứu/giả thuyết cần kiểm chứng\"]
}

QUY TẮC:
- Sắp xếp theo mức độ ưu tiên giảm dần (score 10 = ưu tiên cao nhất).
- Liên kết rõ ràng maps_to_* giúp tra cứu chéo pains/gains/jobs.
- Ngôn ngữ thực dụng, ngắn gọn, có thể triển khai.";
  
  $payload = [
    'company'      => $profile['company_name'] ?? '',
    'industry'     => $profile['company_industry'] ?? '',
    'overview'     => $overview,
    'life_path'    => $life_path,
    'soul_number'  => $soul_num,
    'vision'       => $vision,       // mission, vision, bmc, vpc, brand
    'swot'         => $swot,         // swot theo chủ đề
    'numbers_full' => $numbers_full, // 21 chỉ số
    'answers'      => $answers
  ];
  $user = "DỮ LIỆU:\n".wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nHãy trả về CHỈ JSON đúng SCHEMA.";

  $api_key = get_option('bccm_openai_api_key','');
  $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, "SYSTEM:\n{$sys}\n\nUSER:\n{$user}", false);
  if (!is_string($raw) || trim($raw)==='') return new WP_Error('ai_empty','Không nhận phản hồi từ BizGPT');

  // Parse
  if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) $raw = $m[0];
  $data = json_decode($raw, true);
  if (!is_array($data)) return new WP_Error('ai_parse','AI không trả JSON hợp lệ', ['raw'=>$raw]);

  // Fill defaults
  $defaults = [
    'magic_triangle'=>['what'=>'','why'=>'','how'=>'','who'=>''],
    'customer_priority_matrix'=>[
      'jobs_importance'=>[], 'gains_importance'=>[], 'pains_importance'=>[]
    ],
    'value_map_priority_matrix'=>[
      'products_services_importance'=>[], 'gain_creators_importance'=>[], 'pain_relievers_importance'=>[]
    ],
    'personas'=>[], 'jtbd'=>[], 'actions'=>[]
  ];
  $data = array_merge($defaults, $data);

  // Save
  $wpdb->update($t['profiles'], [
    'customer_json' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
    'updated_at'    => current_time('mysql')
  ], ['id'=>$coachee_id]);

  return $data;
}
/**
 * Tổng hợp WHAT/WHY/HOW/WHO (win theme) từ ai_summary + vision + swot + customer_json
 * + đề xuất Unfair Advantage, North Star Metric, 3 Strategic Bets, GTM và Risk Mitigation
 */
function bccm_generate_winning_model($coachee_id){
  global $wpdb; $t=bccm_tables();
  @set_time_limit(120);

  $profile  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
  if(!$profile) return new WP_Error('profile_nf','Không tìm thấy hồ sơ');

  $ai_summary = json_decode($profile['ai_summary'] ?? '[]', true);
  $vision     = json_decode($profile['vision_json'] ?? '[]', true);
  $swot       = json_decode($profile['swot_json'] ?? '[]', true);
  $customer   = json_decode($profile['customer_json'] ?? '[]', true);

  $sys = "Bạn là BizCoach. Hãy kết tinh chiến lược thắng cuộc (Winning Model) từ dữ liệu dưới đây.
Trả về DUY NHẤT JSON (RFC8259), tiếng Việt UTF-8, không markdown.

SCHEMA:
{
  \"what\": \"[DN thực sự bán cái gì/giải pháp lõi, 1–2 câu]\",
  \"why\":  \"[vì sao có lời/giá trị vượt trội, 1–2 câu]\",
  \"how\":  \"[cách tạo & phân phối giá trị/chuỗi giá trị & kênh, 1–2 câu]\",
  \"who\":  \"[ICP/khách hàng mục tiêu sắc nét, 1–2 câu]\",
  \"unfair_advantage\": [\"...\"],
  \"north_star_metric\": \"[KPI dẫn đường duy nhất]\", 
  \"strategic_bets\": [\"3–5 canh bạc chiến lược 12–24 tháng\"],
  \"go_to_market\": {\"positioning\":\"\",\"channels\":[\"\"],\"messages\":[\"\"],\"offers\":[\"\"],\"growth_loops\":[\"\"]},
  \"risk_mitigations\": [\"3–7 rủi ro + cách giảm thiểu\"]
}";
  $payload = [
    'company'  => $profile['company_name'] ?? '',
    'industry' => $profile['company_industry'] ?? '',
    'overview' => $ai_summary,
    'vision'   => $vision,
    'swot'     => $swot,
    'customer' => $customer
  ];
  $user = "DỮ LIỆU:\n".wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nHãy trả về CHỈ JSON đúng SCHEMA.";

  $api_key = get_option('bccm_openai_api_key','');
  $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, "SYSTEM:\n{$sys}\n\nUSER:\n{$user}", false);
  if (!is_string($raw) || trim($raw)==='') return new WP_Error('ai_empty','Không nhận phản hồi từ BizGPT');
  if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) $raw = $m[0];

  $data = json_decode($raw, true);
  if (!is_array($data)) return new WP_Error('ai_parse','AI không trả JSON hợp lệ', ['raw'=>$raw]);

  $defaults = [
    'what'=>'','why'=>'','how'=>'','who'=>'',
    'unfair_advantage'=>[], 'north_star_metric'=>'', 'strategic_bets'=>[],
    'go_to_market'=>['positioning'=>'','channels'=>[],'messages'=>[],'offers'=>[],'growth_loops'=>[]],
    'risk_mitigations'=>[]
  ];
  $data = array_merge($defaults, $data);

  $wpdb->update($t['profiles'], [
    'winning_json' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
    'updated_at'   => current_time('mysql')
  ], ['id'=>$coachee_id]);

  return $data;
}
/**
 * Sinh Value Proposition Map từ customer_json, vision_json, ai_summary, swot_json, winning_json
 * Lưu vào profiles.value_json (JSON)
 */
function bccm_generate_value_map($coachee_id){
  global $wpdb; $t = bccm_tables();
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
  if(!$row) return new WP_Error('nf','Coachee not found');

  // Gom inputs
  $inputs = [
    'customer_json' => json_decode($row['customer_json'] ?? '', true),
    'vision_json'   => json_decode($row['vision_json']   ?? '', true),
    'ai_summary'    => json_decode($row['ai_summary']    ?? '', true),
    'swot_json'     => json_decode($row['swot_json']     ?? '', true),
    'winning_json'  => json_decode($row['winning_json']  ?? '', true),
    'profile'       => [
      'name'     => $row['full_name'] ?? '',
      'company'  => $row['company_name'] ?? '',
      'industry' => $row['company_industry'] ?? '',
    ],
  ];

  // Prompt AI
  $sys = "Bạn là chuyên gia Value Proposition/Business Design. Hãy tổng hợp INSIGHT và tạo bản đồ giá trị cho doanh nghiệp (ngắn gọn, rõ, hành động). 
Trả về DUY NHẤT JSON hợp lệ theo schema phía dưới, không thêm giải thích.";

  $schema = [
    'value_json' => [
      'segments' => [[
        'name'        => 'Tên phân khúc',
        'profile'     => 'Mô tả nhanh phân khúc',
        'jobs'        => ['Việc cần làm chính của khách hàng'],
        'pains'       => ['Nỗi đau trọng yếu'],
        'gains'       => ['Lợi ích mong đợi'],
        'priority'    => 1
      ]],
      'propositions' => [[
        'title'       => 'Tên gói giá trị/đề xuất',
        'for_segment' => 'Tên phân khúc (tham chiếu ở trên)',
        'promise'     => 'Tuyên ngôn giá trị 1 câu',
        'solutions'   => ['Giải pháp/đặc tính then chốt'],
        'proof'       => ['Bằng chứng: case, số liệu, chứng nhận'],
        'channels'    => ['Kênh tiếp cận/chuyển đổi'],
        'wow'         => ['Yếu tố gây nghiện/độc đáo'],
        'risks'       => ['Rủi ro & cách giảm thiểu'],
        'kpis'        => ['KPI đo giá trị (đơn vị cụ thể)'],
        'priority'    => 1,
        'timeframe'   => 'ngắn hạn | trung hạn | dài hạn'
      ]]
    ]
  ];

  $ask = "SYSTEM: $sys\nSCHEMA_EXAMPLE: ".wp_json_encode($schema, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
       . "\nUSER_INPUTS: ".wp_json_encode($inputs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

  $api_key = get_option('bccm_openai_api_key','');
  if (!function_exists('chatbot_chatgpt_call_omni_bizcoach_map')){
    // Fallback khung rỗng
    $value = ['segments'=>[],'propositions'=>[]];
  } else {
    $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, $ask, false,false,false,false);
    $data = function_exists('bccm_try_decode_json_relaxed') ? bccm_try_decode_json_relaxed($raw) : json_decode($raw, true);
    if (!is_array($data) || !isset($data['value_json'])) {
      return new WP_Error('ai_parse','AI không trả JSON value_json hợp lệ', ['raw'=>$raw]);
    }
    $value = $data['value_json'];
  }

  $ok = $wpdb->update($t['profiles'], [
    'value_json' => wp_json_encode($value, JSON_UNESCAPED_UNICODE),
    'updated_at' => current_time('mysql'),
  ], ['id'=>$coachee_id], ['%s','%s'], ['%d']);
  return $ok===false ? new WP_Error('db','Không lưu được value_json') : true;
}
/**
 * Sinh BizCoach 90-Day Transformation Map (30–50–10)
 * Dùng các khối customer/vision/swot/value/winning để đổ mục tiêu, workstream, KPI, tuần.
 * Lưu profiles.bizcoach_json (JSON)
 */
function bccm_generate_bizcoach_map($coachee_id){
  global $wpdb; $t = bccm_tables();
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
  if(!$row) return new WP_Error('nf','Coachee not found');

  $inputs = [
    'customer_json' => json_decode($row['customer_json'] ?? '', true),
    'vision_json'   => json_decode($row['vision_json']   ?? '', true),
    'ai_summary'    => json_decode($row['ai_summary']    ?? '', true),
    'swot_json'     => json_decode($row['swot_json']     ?? '', true),
    'value_json'    => json_decode($row['value_json']    ?? '', true),
    'winning_json'  => json_decode($row['winning_json']  ?? '', true),
    'profile'       => [
      'name'     => $row['full_name'] ?? '',
      'company'  => $row['company_name'] ?? '',
      'industry' => $row['company_industry'] ?? '',
    ],
  ];

  $sys = "Bạn là chief transformation officer. Hãy lập KẾ HOẠCH 90 NGÀY thực thi theo 3 giai đoạn 30–50–10.
Phải cụ thể, đo lường được (KPI, mốc tuần), phân vai (Owner/RACI), kênh/công cụ, deliverables, ngân sách ước tính, rủi ro & giảm thiểu.
Trả về DUY NHẤT JSON hợp lệ theo schema cho trước.";
$sys .= "\nBẮT BUỘC: Trả duy nhất JSON có khóa gốc tên \"bizcoach_json\" (không dùng tên khác như chief_transformation_plan). Không kèm text ngoài JSON.";


  $schema = [
    'bizcoach_json' => [
      'overview' => [
        'north_star' => '1 câu đích đến 90 ngày',
        'key_kpis'   => ['3-7 KPI tổng'],
        'assumptions'=> ['Giả định then chốt cần kiểm chứng'],
      ],
      'phases' => [
        [
          'key'   => 'phase_1_build',
          'title' => '30 ngày – Build Brand & Foundation',
          'objectives' => ['Mục tiêu giai đoạn'],
          'workstreams' => [[
            'name'   => 'Brand/Website/AI/Tem/CRM',
            'tasks'  => ['Danh sách công việc theo tuần'],
            'owners' => ['Vai trò chính: Brand lead, Tech lead...'],
            'tools'  => ['WP, BizGPT, FAQ KB, TemAI, CRM, Voucher/Points'],
            'deliverables' => ['Bộ nhận diện, website, FAQ, tem AI, voucher flow...'],
            'kpis'   => ['KPI ngắn hạn (ví dụ: hoàn tất 100 Q&A FAQ, web launch, 1.000 tem in)'],
            'risks'  => ['Rủi ro & phương án'],
            'budget' => 'ước tính'
          ]],
          'weeks' => [
            ['title'=>'Tuần 1','milestones'=>['Audit/định vị/roadmap'],'kpis'=>['hoàn tất audit']],
            ['title'=>'Tuần 2','milestones'=>['Logo/CI/Website khung','chuẩn hóa FAQ'] ,'kpis'=>['50 Q&A']],
            ['title'=>'Tuần 3','milestones'=>['Tem AI, CRM, loyalty'],'kpis'=>['khởi tạo tem, CRM']],
            ['title'=>'Tuần 4','milestones'=>['Soft-launch + kiểm thử'],'kpis'=>['site live, tem test']]
          ]
        ],
        [
          'key'   => 'phase_2_execute',
          'title' => '60 ngày – Execute & Value Chain Expansion',
          'objectives' => ['Chạy chiến dịch, huấn luyện đội ngũ, khuếch đại chuỗi giá trị từ SWOT & Value'],
          'workstreams' => [[
            'name'=>'Campaign + Sales Ops + Training',
            'tasks'=>['Chiến dịch A/B, content engine, onboarding sale, KOL/KOC, referral'],
            'owners'=>['Marketing lead, Sales lead, Trainer'],
            'tools'=>['Ads, Marketing automation, LMS mini, Dashboard KPI'],
            'deliverables'=>['Campaign kits, playbook đào tạo, script bán hàng, case study'],
            'kpis'=>['CPA, CR%, MRR/GMV, NPS, training hours'],
            'risks'=>['Burn rate, lệch thông điệp, thiếu leads'],
            'budget'=>'ước tính'
          ]],
          'weeks' => [
            ['title'=>'Tuần 5-6','milestones'=>['Launch 1-2 chiến dịch trụ cột','đào tạo batch 1'],'kpis'=>['CR>=x%']],
            ['title'=>'Tuần 7-8','milestones'=>['Mở rộng kênh/partner','case đầu tiên'],'kpis'=>['NPS>=y']],
            ['title'=>'Tuần 9-10','milestones'=>['Tối ưu funnel','content repurpose'],'kpis'=>['CPA giảm z%']],
            ['title'=>'Tuần 11-12','milestones'=>['Scale chiến dịch hiệu quả','chuẩn hóa SOP'],'kpis'=>['MRR mục tiêu']]
          ]
        ],
        [
          'key'   => 'phase_3_optimize',
          'title' => '10 ngày – Optimize, Trim, Prepare to Scale',
          'objectives' => ['Rà soát, tinh gọn, chuẩn hóa cho giai đoạn mở rộng'],
          'workstreams' => [[
            'name'=>'Optimization/Finance/Planning',
            'tasks'=>['Cut features thừa, chuẩn hoá quy trình, chốt báo cáo 90 ngày, OKR quý tới'],
            'owners'=>['COO, Finance'],
            'tools'=>['BI dashboard, Retrospective kit'],
            'deliverables'=>['Retrospective report, OKR plan, hiring plan'],
            'kpis'=>['Gross margin, CAC payback, churn'],
            'risks'=>['Phụ thuộc 1-2 kênh'],
            'budget'=>'-'
          ]],
          'weeks' => [
            ['title'=>'Tuần 13','milestones'=>['Retro + tối ưu + chốt OKR'],'kpis'=>['hoàn tất retro']]
          ]
        ]
      ]
    ]
  ];

  $ask = "SYSTEM: $sys\nSCHEMA_EXAMPLE: ".wp_json_encode($schema, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
       . "\nUSER_INPUTS: ".wp_json_encode($inputs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

  $api_key = get_option('bccm_openai_api_key','');
if (!function_exists('chatbot_chatgpt_call_omni_bizcoach_map')){
  $biz = $schema['bizcoach_json']; // fallback khung
} else {
  $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, $ask, false,false,false,false);
  $biz = bccm_extract_bizcoach_from_raw($raw);
  if (!is_array($biz)) {
    return new WP_Error('ai_parse','AI không trả JSON bizcoach_json hợp lệ', ['raw'=>$raw]);
  }
}

  $ok = $wpdb->update($t['profiles'], [
    'bizcoach_json' => wp_json_encode($biz, JSON_UNESCAPED_UNICODE),
    'updated_at'    => current_time('mysql'),
  ], ['id'=>$coachee_id], ['%s','%s'], ['%d']);
  return $ok===false ? new WP_Error('db','Không lưu được bizcoach_json') : true;
}

/**
 * Trích xuất bizcoach_json từ nhiều dạng AI có thể trả về.
 * Hỗ trợ:
 * - { "bizcoach_json": { ... } }
 * - { "chief_transformation_plan": { ... } }
 * - { ... có "phases": [...] } (trả thẳng object)
 * - { "top_level": "{ \"bizcoach_json\": { ... } }" } (string JSON)
 * - Bỏ code fences; last resort: cắt substring theo từ khóa
 */
function bccm_extract_bizcoach_from_raw($raw){
  if (!is_string($raw) || $raw==='') return null;

  // Remove BOM / Zero-width & code fences
  $s = preg_replace('/^\xEF\xBB\xBF/u','', $raw);
  $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u','', $s);
  $s = trim($s);
  if (preg_match('/^```/u', $s)) {
    $s = preg_replace('/^```[a-zA-Z0-9_-]*\s*|\s*```$/u', '', $s);
    $s = trim($s);
  }

  $decode = function($txt){
    if (function_exists('bccm_try_decode_json_relaxed')) return bccm_try_decode_json_relaxed($txt);
    return json_decode($txt, true);
  };

  $data = $decode($s);

  // Case 1: Chuẩn
  if (is_array($data) && isset($data['bizcoach_json']) && is_array($data['bizcoach_json'])) {
    return $data['bizcoach_json'];
  }

  // Case 2: Key khác tên (chief_transformation_plan)
  if (is_array($data) && isset($data['chief_transformation_plan']) && is_array($data['chief_transformation_plan'])) {
    return $data['chief_transformation_plan'];
  }

  // Case 3: Trả thẳng object có 'phases'
  if (is_array($data) && isset($data['phases']) && is_array($data['phases'])) {
    return $data;
  }

  // Case 4: JSON lồng dạng string
  if (is_array($data) && isset($data['top_level']) && is_string($data['top_level'])) {
    $tl = $decode($data['top_level']);
    if (is_array($tl)) {
      if (isset($tl['bizcoach_json']) && is_array($tl['bizcoach_json'])) return $tl['bizcoach_json'];
      if (isset($tl['chief_transformation_plan']) && is_array($tl['chief_transformation_plan'])) return $tl['chief_transformation_plan'];
      if (isset($tl['phases']) && is_array($tl['phases'])) return $tl;
    }
  }

  // Case 5: Last resort — cắt substring theo từ khóa và đếm ngoặc
  $grab = function($haystack, $needle){
    $pos = strpos($haystack, $needle);
    if ($pos===false) return null;
    // lùi tới '{' mở bao ngoài
    $start = $pos;
    while ($start>=0 && $haystack[$start] !== '{') $start--;
    if ($start<0) $start = $pos;
    if (!function_exists('bccm_json_grab_balanced_object')) return null;
    $json = bccm_json_grab_balanced_object($haystack, $start);
    return $json ? json_decode($json, true) : null;
  };

  $j1 = $grab($s, '"bizcoach_json"');
  if (is_array($j1) && isset($j1['bizcoach_json'])) return $j1['bizcoach_json'];
  $j2 = $grab($s, '"chief_transformation_plan"');
  if (is_array($j2) && isset($j2['chief_transformation_plan'])) return $j2['chief_transformation_plan'];

  return null;
}
// ====== LEADERSHIP: titles ======


/**
 * Generate Leadership Map (10 chỉ số năng lực + 8 kỹ năng) lưu vào iqmap_json['leadership'].
 * Inputs: numeric_json + ai_summary + answer_json
 *
 * @param int $coachee_id
 * @return true|WP_Error
 */
if (!function_exists('bccm_generate_leadership_iqmap')) {
  function bccm_generate_leadership_iqmap($coachee_id){
    global $wpdb; $t = bccm_tables();

    // 1) Load profile
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id
    ), ARRAY_A);
    if (!$row) return new WP_Error('nf','Coachee not found');

    // 2) Inputs
    $numeric = json_decode($row['numeric_json'] ?? '', true);
    if (empty($numeric) || !is_array($numeric)) {
      return new WP_Error('numeric_empty','Chưa có numeric_json');
    }
    $answers = [];
    if (!empty($row['answer_json'])) {
      $tmp = json_decode($row['answer_json'], true);
      if (json_last_error()===JSON_ERROR_NONE && is_array($tmp)) $answers = $tmp;
    }
    $answer_text = '';
    if ($answers) {
      foreach ($answers as $k=>$v) {
        if (is_array($v)) $v = implode(' ', $v);
        $answer_text .= ' '.wp_strip_all_tags((string)$v);
      }
    }
    $answer_text = mb_strtolower(trim($answer_text));

    // ---- helpers ----
    $getMetric = function(string $k, float $fallback=6.0) use ($numeric): float {
      // thử nhiều đường dẫn
      $cands = [
        $numeric['numbers_full'][$k]['value'] ?? null,
        $numeric[$k]['value'] ?? null,
        $numeric[$k] ?? null,
      ];
      foreach ($cands as $v) {
        if ($v===null || $v==='') continue;
        $x = (float)$v;
        // đưa về 1..10 nếu thang khác
        if ($x > 100) $x = fmod($x, 9.0) + 1.0;
        elseif ($x > 10) $x = $x / 10.0;
        if     ($x < 1)  $x = 1.0;
        elseif ($x > 10) $x = 10.0;
        return $x;
      }
      return $fallback;
    };

    $wavg = function(array $pairs) use ($getMetric): float {
      $sum=0; $w=0;
      foreach ($pairs as $p){ // [metric_key, weight]
        $m = $getMetric($p[0], 6.0);
        $sum += $m * (float)$p[1];
        $w   += (float)$p[1];
      }
      return $w>0 ? $sum/$w : 6.0;
    };

    $kwBias = function(array $pos, array $neg, float $step, string $text) {
      $bias = 0.0;
      foreach ($pos as $k) if ($k && mb_strpos($text, mb_strtolower($k))!==false) $bias += $step;
      foreach ($neg as $k) if ($k && mb_strpos($text, mb_strtolower($k))!==false) $bias -= $step;
      return $bias;
    };

    $clamp01 = function(float $v): float {
      if ($v < 1) $v = 1;
      if ($v > 10) $v = 10;
      return round($v, 1);
    };

    // 3) Ma trận trọng số (map 21 metrics → leadership)
    $L10 = [
      'personal_skill'     => [['personality',0.35], ['birth_day',0.25], ['soul_number',0.25], ['maturity',0.15]],
      'strategic_thinking' => [['life_path',0.30],  ['mission',0.30],   ['logic',0.25],       ['link_life_mission',0.15]],
      'execution'          => [['attitude',0.40],   ['maturity',0.35],  ['subconscious_power',0.25]],
      'people_mgmt'        => [['personality',0.35],['soul_number',0.25],['passion',0.20],    ['link_soul_personality',0.20]],
      'special_leadership' => [['passion',0.45],    ['subconscious_power',0.30], ['birth_day',0.25]],
      'culture'            => [['mission',0.35],    ['personality',0.30],['attitude',0.20],   ['maturity',0.15]],
      'talent_mgmt'        => [['maturity',0.35],   ['soul_number',0.25],['personality',0.20],['life_path',0.20]],
      'performance_report' => [['logic',0.45],      ['maturity',0.30],   ['attitude',0.25]],
      'information'        => [['logic',0.40],      ['subconscious_power',0.30], ['link_life_mission',0.30]],
      'work_methods'       => [['attitude',0.40],   ['logic',0.35],      ['maturity',0.25]],
    ];
    $S8 = [
      'communication'   => [['personality',0.40], ['soul_number',0.35], ['birth_day',0.25]],
      'innovation'      => [['passion',0.45],     ['subconscious_power',0.30], ['life_path',0.25]],
      'negotiation'     => [['birth_day',0.35],   ['personality',0.35],  ['maturity',0.30]],
      'decision'        => [['attitude',0.45],    ['logic',0.35],        ['maturity',0.20]],
      'critical'        => [['logic',0.50],       ['maturity',0.30],     ['mission',0.20]],
      'problem_solving' => [['logic',0.45],       ['subconscious_power',0.30], ['attitude',0.25]],
      'motivation'      => [['passion',0.45],     ['life_path',0.35],    ['soul_number',0.20]],
      'relationship'    => [['link_soul_personality',0.40], ['personality',0.35], ['mission',0.25]],
    ];

    // 4) Từ khoá bias (pos/neg) – điều chỉnh nhẹ để tạo khác biệt theo answers
    $K1 = [
      'execution'          => [['kỷ luật','deadline','kế hoạch','tuân thủ','cam kết'], ['trì hoãn','chậm trễ','thiếu kỷ luật']],
      'people_mgmt'        => [['đồng đội','gắn kết','coaching','lắng nghe','teamwork'], ['xung đột','mất đoàn kết']],
      'special_leadership' => [['sáng tạo','đổi mới','tầm nhìn','phá cách'], ['bảo thủ','ngại thay đổi']],
      'culture'            => [['giá trị cốt lõi','văn hoá','minh bạch','trao quyền'], ['độc đoán','quan liêu']],
      'talent_mgmt'        => [['tuyển dụng','đào tạo','kế nhiệm','phát triển nhân tài'], ['thiếu người','chảy máu chất xám']],
      'performance_report' => [['số liệu','kpi','dashboard','báo cáo','data-driven','metrics'], ['cảm tính','không đo lường']],
      'information'        => [['thông tin','chia sẻ kiến thức','documentation'], ['thiếu thông tin','đứt gãy thông tin']],
      'work_methods'       => [['quy trình','chuẩn hoá','lean','agile'], ['tuỳ hứng','thiếu quy trình']],
      'strategic_thinking' => [['chiến lược','dài hạn','ưu tiên','roadmap'], ['thiếu chiến lược','ngắn hạn']],
      'personal_skill'     => [['tự giác','tự học','quản trị bản thân','tập trung'], ['thiếu tập trung','cảm xúc thất thường']],
    ];
    $K2 = [
      'communication'   => [['thuyết trình','giao tiếp','truyền đạt','trình bày'], ['kém giao tiếp','ngại nói']],
      'innovation'      => [['sáng tạo','đổi mới','ideation','prototype'], ['bảo thủ','ngại thay đổi']],
      'negotiation'     => [['đàm phán','thuyết phục','win-win'], ['tranh cãi','cứng nhắc']],
      'decision'        => [['quyết định','dứt khoát','ra quyết định'], ['do dự','thiếu quyết đoán']],
      'critical'        => [['phản biện','logic','lý lẽ'], ['cảm tính']],
      'problem_solving' => [['giải quyết vấn đề','root cause','pdca'], ['đổ lỗi','lúng túng']],
      'motivation'      => [['truyền cảm hứng','động lực','khích lệ'], ['mất động lực','kiệt sức']],
      'relationship'    => [['mối quan hệ','kết nối','đồng cảm','lắng nghe'], ['xung đột','khó hợp tác']],
    ];

    // 5) Tính điểm nền + bias
    $lead10 = [];
    foreach ($L10 as $k => $pairs) {
      $base = $wavg($pairs);
      $bias = isset($K1[$k]) ? $kwBias($K1[$k][0], $K1[$k][1], 0.5, $answer_text) : 0.0; // ±0.5 mỗi cụm
      $lead10[$k] = $base + $bias;
    }
    $skills8 = [];
    foreach ($S8 as $k => $pairs) {
      $base = $wavg($pairs);
      $bias = isset($K2[$k]) ? $kwBias($K2[$k][0], $K2[$k][1], 0.5, $answer_text) : 0.0;
      $skills8[$k] = $base + $bias;
    }

    // 6) Chuẩn hoá phân tán (z-score → target sd ≈ 1.0)
    $expand = function(array $arr): array {
      $vals = array_values($arr);
      $n = max(1,count($vals));
      $mean = array_sum($vals)/$n;
      $var = 0.0;
      foreach ($vals as $v) $var += ($v-$mean)*($v-$mean);
      $sd = sqrt($var/$n);
      // mồi phân tán nếu sd quá nhỏ
      if ($sd < 0.05) {
        $i=0; foreach($arr as $k=>$v){ $arr[$k] = $v + (($i%2)?0.2:-0.2); $i++; }
        $vals = array_values($arr);
        $mean = array_sum($vals)/$n;
        $var=0; foreach($vals as $v){ $var += ($v-$mean)*($v-$mean); }
        $sd = sqrt($var/$n);
      }
      $target = 1.0;
      $factor = ($sd > 0) ? min(2.5, $target / $sd) : 1.0;
      foreach ($arr as $k=>$v) {
        $nv = $mean + ($v - $mean) * $factor;
        // ép 1..10 và làm tròn 0.1
        if ($nv < 1) $nv = 1; if ($nv > 10) $nv = 10;
        $arr[$k] = round($nv, 1);
      }
      return $arr;
    };
    $lead10  = $expand($lead10);
    $skills8 = $expand($skills8);

    // 7) Build payload
    $payload = [
      'leadership_10' => [
        'items' => $lead10,
        'avg'   => round(array_sum($lead10)/count($lead10), 1),
        'notes' => new stdClass(),
      ],
      'skills_8' => [
        'items' => $skills8,
        'avg'   => round(array_sum($skills8)/count($skills8), 1),
        'notes' => new stdClass(),
      ],
      'advice'     => ['bullets' => []],
      'updated_at' => current_time('mysql'),
    ];

    // 8) Save
    $all = json_decode($row['iqmap_json'] ?? '', true);
    if (!is_array($all)) $all = [];
    $all['leadership'] = $payload;

    $ok = $wpdb->update($t['profiles'], [
      'iqmap_json' => wp_json_encode($all, JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
    ], ['id' => (int)$coachee_id], ['%s','%s'], ['%d']);

    return ($ok===false) ? new WP_Error('db','Không lưu được iqmap_json') : true;
  }
}
