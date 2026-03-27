<?php
// includes/ai.php
if (!defined('ABSPATH')) exit;

/* ======================== COMMON HELPERS ======================== */

function bccm_metric_keys(){
  return [
    'life_path','balance','mission','link_life_mission',
    'soul_number','birth_day','personality','link_soul_personality',
    'maturity','attitude','missing','lesson',
    'logic','subconscious_power','passion',
    'personal_year','personal_month','pinnacle',
    'personal_day','generation','challenges',
  ];
}
function bccm_brief_5w($t){
  $t = trim((string)$t); if($t==='') return '—';
  $p = preg_split('/\s+/u',$t,-1,PREG_SPLIT_NO_EMPTY);
  return count($p)<=5? $t : implode(' ',array_slice($p,0,5));
}

/* ---------- Destiny fallback (đủ 21 ô) ---------- */
function bccm_destiny_fallback($profile){
  $life = function_exists('bccm_numerology_life_path')   ? (string) bccm_numerology_life_path($profile['dob'] ?? '')        : '';
  $soul = function_exists('bccm_numerology_name_number') ? (string) bccm_numerology_name_number($profile['full_name'] ?? ''): '';
  $mk = function($val,$brief,$meaning){
    return ['value'=>($val!==''?$val:'—'),'brief'=>bccm_brief_5w($brief),'meaning'=>$meaning];
  };
  $map = [
    'life_path'             => $mk($life,'Hướng đời','Định hướng & bài học lớn.'),
    'balance'               => $mk('','Cân bằng','Điều chỉnh cảm xúc – hành động.'),
    'mission'               => $mk('','Sứ mệnh','Giá trị trao cho người khác.'),
    'link_life_mission'     => $mk('','Liên kết mục tiêu','Hòa hợp đích đến – con đường.'),
    'soul_number'           => $mk($soul,'Động lực nội tại','Điều khiến bạn hứng thú.'),
    'birth_day'             => $mk('','Khí chất','Ưu điểm bẩm sinh.'),
    'personality'           => $mk('','Xuất hiện','Cách bạn thể hiện ra ngoài.'),
    'link_soul_personality' => $mk('','Trong – ngoài','Đồng nhất cảm xúc & biểu hiện.'),
    'maturity'              => $mk('','Độ chín','Tầm nhìn & mục tiêu dài hạn.'),
    'attitude'              => $mk('','Thái độ','Góc nhìn & phản ứng hằng ngày.'),
    'missing'               => $mk('','Thiếu hụt','Điểm yếu cần bù đắp.'),
    'lesson'                => $mk('','Bài học','Chủ đề cần hoàn thiện.'),
    'logic'                 => $mk('','Lý trí','Phân tích & quyết định.'),
    'subconscious_power'    => $mk('','Tiềm thức','Nguồn lực vô thức hỗ trợ.'),
    'passion'               => $mk('','Đam mê','Năng lượng sáng tạo.'),
    'personal_year'         => $mk('','Năm cá nhân','Chu kỳ & chiến lược năm.'),
    'personal_month'        => $mk('','Tháng cá nhân','Trọng tâm theo tháng.'),
    'pinnacle'              => $mk('','Chặng','Giai đoạn lớn của cuộc đời.'),
    'personal_day'          => $mk('','Ngày cá nhân','Nhịp điệu/khung giờ phù hợp.'),
    'generation'            => $mk('','Thế hệ','Ảnh hưởng bối cảnh xã hội.'),
    'challenges'            => $mk('','Thách thức','Bài kiểm tra giúp trưởng thành.'),
  ];
  return [
    'overview'      => 'Tổng quan sẽ được coach bổ sung.',
    'life_path'     => ($life!==''?$life:'—'),
    'soul_number'   => ($soul!==''?$soul:'—'),
    'numbers_full'  => $map,
    'career'        => '',
    'mission'       => '',
    'jobs_recommended'=>[],
    'lucky_colors'  => [],
    'to_practice'   => [],
    'to_avoid'      => [],
    'twelve_months' => [],
    'fortune'       => ['money'=>'','colleagues'=>'','career'=>'','luck'=>''],
  ];
}

function bccm_destiny_merge($ai, $fallback){
  if(!is_array($ai)) $ai=[];
  if(!is_array($fallback)) $fallback=bccm_destiny_fallback([]);
  $out = array_replace_recursive($fallback,$ai);
  if(empty($out['numbers_full']) || !is_array($out['numbers_full'])) $out['numbers_full']=$fallback['numbers_full'];
  foreach(bccm_metric_keys() as $k){
    if(empty($out['numbers_full'][$k])) $out['numbers_full'][$k]=$fallback['numbers_full'][$k];
    $tile = $out['numbers_full'][$k];
    $tile['value']   = isset($tile['value'])&&$tile['value']!==''   ? (string)$tile['value']   : $fallback['numbers_full'][$k]['value'];
    $tile['brief']   = isset($tile['brief'])&&$tile['brief']!==''   ? bccm_brief_5w($tile['brief']) : $fallback['numbers_full'][$k]['brief'];
    $tile['meaning'] = isset($tile['meaning'])&&$tile['meaning']!==''? (string)$tile['meaning'] : $fallback['numbers_full'][$k]['meaning'];
    $out['numbers_full'][$k]=$tile;
  }
  $out += [
    'career'=>'','mission'=>'','jobs_recommended'=>[],
    'lucky_colors'=>[],'to_practice'=>[],'to_avoid'=>[],
    'twelve_months'=>[],
    'fortune'=>['money'=>'','colleagues'=>'','career'=>'','luck'=>''],
  ];
  return $out;
}

/* ======================== WEEKLY NORMALIZER ======================== */

/** Defaults mạnh tay cho từng tuần nếu AI/Template bỏ trống */
function bccm_week_defaults($phaseIndex, $weekIndex){
  // w: 0..3 cho mỗi phase
  $defs = [
    // Phase 1: nền tảng
    0 => [
      ['goal'=>'Rõ mục tiêu & baseline','focus'=>'Chuẩn bị hệ thống & môi trường',
       'kpis'=>['Hoàn tất bộ mục tiêu tuần','Thiết lập baseline đo lường','Thói quen ≥5/7 ngày'],
       'tasks'=>['Định nghĩa mục tiêu SMART','Thiết lập lịch làm việc/nhắc việc','Chuẩn hoá không gian & công cụ']],
      ['goal'=>'Xây thói quen & kỷ luật','focus'=>'Theo dõi tiến độ hằng ngày',
       'kpis'=>['Duy trì thói quen ≥6/7 ngày','100% công việc ưu tiên (MIT)'],
       'tasks'=>['Lập checklist ngày/tuần','Theo dõi KPI mỗi tối','Tuỳ chỉnh lịch cho phù hợp']],
      ['goal'=>'Chuẩn hoá quy trình','focus'=>'Viết quy trình cơ bản',
       'kpis'=>['Hoàn thành ≥3 quy trình cốt lõi'],
       'tasks'=>['Vẽ luồng công việc','Định nghĩa tiêu chuẩn đầu–ra','Phân vai & thời lượng']],
      ['goal'=>'Khởi động chiến dịch nhỏ','focus'=>'Thử nghiệm kênh/hoạt động chủ lực',
       'kpis'=>['Hoàn tất 1 chiến dịch thử','Tỉ lệ hoàn thành công việc ≥90%'],
       'tasks'=>['Lên kế hoạch mini-campaign','Chuẩn bị nội dung/tài nguyên','Chạy thử & ghi nhận số liệu']],
    ],
    // Phase 2: thực thi tăng tốc
    1 => [
      ['goal'=>'Triển khai chiến lược','focus'=>'Thực thi đều, theo dõi sát',
       'kpis'=>['≥80% công việc hoàn thành','Tăng trưởng đầu mối ≥10%/tuần'],
       'tasks'=>['Phân rã mục tiêu/tuần','Đi nhịp làm–đo–sửa','Họp nhanh kiểm điểm']],
      ['goal'=>'Tăng tốc theo dữ liệu','focus'=>'A/B & tối ưu điểm nghẽn',
       'kpis'=>['Cải thiện % chuyển đổi ≥15%','Giảm thời gian xử lý ≥10%'],
       'tasks'=>['Chạy A/B 1 biến số','Rà bottleneck & tối ưu','Tài liệu hoá bài học']],
      ['goal'=>'Mở rộng quy mô','focus'=>'Nhân bản thứ hiệu quả',
       'kpis'=>['Tăng tệp khách/đầu mối ≥20%','Doanh số/đầu ra tăng ≥15%'],
       'tasks'=>['Nhân bản quy trình tốt','Tăng ngân sách/kênh hiệu quả','Chuẩn hoá mẫu biểu']],
      ['goal'=>'Chuẩn bị chốt đợt','focus'=>'Ổn định vận hành & chất lượng',
       'kpis'=>['Tỉ lệ hài lòng ≥90%','SLA đáp ứng ≥95%'],
       'tasks'=>['Rà tiêu chuẩn chất lượng','Đào tạo nhanh cho team','Kịch bản xử lý sự cố']],
    ],
    // Phase 3: đánh giá – chuẩn hoá – duy trì
    2 => [
      ['goal'=>'Đánh giá tổng hợp KPI','focus'=>'Phân tích chênh lệch & insight',
       'kpis'=>['100% KPI có báo cáo','≥3 insight hành động'],
       'tasks'=>['Tổng hợp dashboard','Retrospective nhóm','Chốt nguyên nhân chính']],
      ['goal'=>'Chuẩn hoá quy trình tốt','focus'=>'Chốt SOP, checklist',
       'kpis'=>['Hoàn thiện ≥5 SOP/Checklist','Tài liệu hoá đầy đủ'],
       'tasks'=>['Viết SOP cuối','Chuẩn hoá checklist/biểu mẫu','Thiết lập kho tài liệu']],
      ['goal'=>'Chốt kết quả & bàn giao','focus'=>'Đóng chiến dịch/đợt',
       'kpis'=>['Đạt ≥90% mục tiêu đợt','Biên bản tổng kết hoàn tất'],
       'tasks'=>['Đóng chiến dịch & nghiệm thu','Tổng hợp case study','Tri ân/CSKH hậu chiến dịch']],
      ['goal'=>'Kế hoạch duy trì 30–90 ngày','focus'=>'Hệ thống theo dõi & cảnh báo',
       'kpis'=>['Lộ trình duy trì được duyệt','Bộ chuẩn đo KPI chạy định kỳ'],
       'tasks'=>['Lập Roadmap 30–90 ngày','Thiết lập lịch review định kỳ','Giao người chịu trách nhiệm']],
    ],
  ];
  return $defs[$phaseIndex][$weekIndex] ?? ['goal'=>'—','focus'=>'','kpis'=>[],'tasks'=>[]];
}

/** Chuẩn hoá plan với template + defaults (không còn tuần rỗng) */
function bccm_weekly_normalize_with_template($tpl, $plan){
  $defs = [
    ['key'=>'p1','title'=>'Giai đoạn 1: Khởi động & Chuẩn bị','notes'=>'Thiết lập nền tảng & thói quen.'],
    ['key'=>'p2','title'=>'Giai đoạn 2: Hành động & Thực thi','notes'=>'Triển khai chiến lược & theo dõi KPI.'],
    ['key'=>'p3','title'=>'Giai đoạn 3: Đánh giá & Chuẩn hoá','notes'=>'Tối ưu, chốt kết quả & duy trì.'],
  ];
  $tplPhases = is_array($tpl['phases'] ?? null) ? $tpl['phases'] : [];
  $in        = is_array($plan['phases'] ?? null) ? $plan['phases'] : [];

  $out = [];
  for ($i=0; $i<3; $i++){
    $pIn  = $in[$i]  ?? [];
    $pTpl = $tplPhases[$i] ?? [];
    $base = $defs[$i];

    $weeksIn  = is_array($pIn['weeks'] ?? null) ? $pIn['weeks'] : [];
    $weeksTpl = is_array($pTpl['weeks'] ?? null) ? $pTpl['weeks'] : [];

    $weeks = [];
    for ($w=0; $w<4; $w++){
      $wi = $weeksIn[$w]  ?? [];
      $wt = $weeksTpl[$w] ?? [];
      // default mạnh tay
      $wd = bccm_week_defaults($i,$w);

      $title = !empty($wi['title']) ? $wi['title'] : (!empty($wt['title']) ? $wt['title'] : 'Tuần '.($w+1));
      $goal  = $wi['goal']  ?? ($wt['goal']  ?? $wd['goal']);
      $focus = $wi['focus'] ?? ($wt['focus'] ?? $wd['focus']);

      $kpis  = (is_array($wi['kpis'] ?? null) && count($wi['kpis'])) ? $wi['kpis']
             : ((is_array($wt['kpis'] ?? null) && count($wt['kpis'])) ? $wt['kpis'] : $wd['kpis']);
      $tasks = (is_array($wi['tasks'] ?? null) && count($wi['tasks'])) ? $wi['tasks']
             : ((is_array($wt['tasks'] ?? null) && count($wt['tasks'])) ? $wt['tasks'] : $wd['tasks']);

      $weeks[] = [
        'title' => $title,
        'goal'  => $goal,
        'focus' => $focus,
        'kpis'  => array_values(array_filter($kpis)),
        'tasks' => array_values(array_filter($tasks)),
        'notes' => $wi['notes'] ?? ($wt['notes'] ?? ''),
      ];
    }

    $out[] = [
      'key'   => in_array(($pIn['key'] ?? ''), ['p1','p2','p3'], true) ? $pIn['key'] : $base['key'],
      'title' => $pIn['title'] ?? ($pTpl['title'] ?? $base['title']),
      'notes' => $pIn['notes'] ?? ($pTpl['notes'] ?? $base['notes']),
      'weeks' => $weeks,
    ];
  }
  return ['title'=>$plan['title'] ?? 'Kế hoạch 90 ngày', 'phases'=>$out];
}

/* ======================== PROMPT BUILDER ======================== */

function bccm_build_ai_prompt_weekly($profile, $coach_label, $questions, $answers, $numerology, $plan_tpl){
  $pairs=[]; for($i=0;$i<20;$i++){ $q=$questions[$i]??''; $a=$answers[$i]??''; if($q||$a) $pairs[]=['q'=>$q,'a'=>$a]; }
  $sys = 'Bạn là chuyên gia '.$coach_label.' tạo **Tổng quan + Bản đồ 90 ngày theo TUẦN**. '
        .'Chỉ trả về JSON hợp lệ (RFC8259). KHÔNG markdown.';
  $usr = [
    'coachee'=>$profile,
    'qa'=>$pairs,
    'numerology'=>$numerology,
    'plan_template'=>$plan_tpl,
    'destiny_requirements'=>[
      'numbers_full_keys'=>bccm_metric_keys(),
      'numbers_full_shape'=>'{value, brief(≤5 từ), meaning (1 câu)}',
      'need_sections'=>['overview','life_path','soul_number','career','mission','jobs_recommended','lucky_colors','to_practice','to_avoid','twelve_months','fortune'],
    ],
    'plan_requirements'=>[
      'structure'=>'3 phases: p1,p2,p3; mỗi phase 4 tuần; **không tuần nào rỗng**.',
      'week'=>'{title, goal, focus, kpis[], tasks[], notes}',
      'limits'=>'kpis ≤5, tasks ≤7; KPI định lượng.',
      'fill_from_template'=>'Nếu có plan_template, dùng các goal/focus/kpis/tasks trong đó. Chỉ sáng tạo thêm khi trống.',
    ],
    'style'=>'ngắn gọn, rõ ràng, hành động.',
  ];
  return [$sys, wp_json_encode($usr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
}

/* ======================== MAIN CALLER ======================== */

function bccm_ai_generate($profile, $coach_label, $questions, $answers){
  @set_time_limit(180);

  $numerology = [
    'life_path'   => function_exists('bccm_numerology_life_path')   ? bccm_numerology_life_path($profile['dob'] ?? '')        : '',
    'soul_number' => function_exists('bccm_numerology_name_number') ? bccm_numerology_name_number($profile['full_name'] ?? ''): '',
  ];
  $plan_tpl = function_exists('bccm_get_plan_tpl')
    ? bccm_get_plan_tpl($profile['coach_type'] ?? 'biz_coach')
    : ['title'=>'','phases'=>[]];

  list($sys,$usr_json) = bccm_build_ai_prompt_weekly($profile,$coach_label,$questions,$answers,$numerology,$plan_tpl);

  $api_key = get_option('bccm_openai_api_key','');
  $ask = "CHỈ trả về JSON hợp lệ.\nSYSTEM: {$sys}\nUSER_JSON: {$usr_json}";
  $out = chatbot_chatgpt_call_omni_bizcoach_map($api_key, $ask, false, false, false, false);

  if (!is_string($out) || $out===''){
    $fallback = bccm_destiny_fallback($profile);
    $plan = bccm_weekly_normalize_with_template($plan_tpl, []);
    if (empty($plan['title'])) $plan['title']='Kế hoạch 90 ngày - '.$coach_label;
    return ['summary'=>$fallback,'plan'=>$plan];
  }

  $data = function_exists('bccm_try_decode_json_relaxed') ? bccm_try_decode_json_relaxed($out) : json_decode($out,true);
  if (is_array($data) && !isset($data['plan'])) {
    if (isset($data['map_90_days']) && function_exists('bccm_adapt_map90_to_weekly')) $data = bccm_adapt_map90_to_weekly($data);
    elseif (isset($data['map']) && function_exists('bccm_adapt_map_to_weekly'))       $data = bccm_adapt_map_to_weekly($data);
  }

  $ai_destiny = is_array($data['destiny'] ?? null) ? $data['destiny'] : [];
  $ai_plan    = is_array($data['plan']    ?? null) ? $data['plan']    : [];

  $destiny = bccm_destiny_merge($ai_destiny, bccm_destiny_fallback($profile));
  $plan    = bccm_weekly_normalize_with_template($plan_tpl, $ai_plan);
  if (empty($plan['title'])) $plan['title']='Kế hoạch 90 ngày - '.$coach_label;

  return ['summary'=>$destiny,'plan'=>$plan];
}

/**
 * Gọi BizGPT (qua chatbot_chatgpt_call_omni_bizcoach_map) để sinh dữ liệu "destiny"
 * theo schema phẳng cho trang tổng quan coachee.
 *
 * @param array  $profile      Bản ghi coachee (wp_*_bccm_coachees)
 * @param string $coach_label  Label loại coach (VD: "Biz Coach (Kinh doanh)")
 * @param array  $questions    Danh sách câu hỏi (optional)
 * @param array  $answers      Câu trả lời đã lưu (assoc) (optional)
 * @return array|WP_Error      Mảng destiny đã chuẩn hóa hoặc WP_Error nếu fail
 */
function bccm_ai_generate_for_overview(array $profile, $coach_label, array $questions, array $answers) {

  // ---------- Lấy numeric_json ----------
  $numeric = [];
  if (!empty($profile['numeric_json'])) {
    $decoded = json_decode($profile['numeric_json'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      $numeric = $decoded;
    }
  }
  // Backfill từ bảng metrics nếu cần (tuỳ chọn)
  if (empty($numeric)) {
    global $wpdb; $t = bccm_tables();
    $rowM = $wpdb->get_row($wpdb->prepare(
      "SELECT numbers_full FROM {$wpdb->prefix}bccm_metrics WHERE coachee_id=%d",
      (int)($profile['id'] ?? 0)
    ), ARRAY_A);
    if (!empty($rowM['numbers_full'])) {
      $nf = json_decode($rowM['numbers_full'], true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($nf)) {
        $numeric = ['numbers_full' => $nf];
      }
    }
  }

  if (empty($numeric['numbers_full']) || !is_array($numeric['numbers_full'])) {
    return new WP_Error('missing_numeric_json', 'Chưa có bộ chỉ số Thần số học (numeric_json). Vui lòng bấm "Tạo bản đồ Thần số học" trước.');
  }

  $numbers_full = $numeric['numbers_full'];

  // Chuẩn hoá lấy life_path, soul_number từ numeric_json
  $life_path   = (string)($numbers_full['life_path']['value'] ?? '');
  $soul_number = (string)($numbers_full['soul_number']['value'] ?? '');

  // ---------- Helpers (giữ nguyên từ bản trước) ----------
  $pick = function($arr, $keys){
    $o=[]; foreach($keys as $k){ $o[$k] = $arr[$k] ?? ''; } return $o;
  };
  $extract_first_json = function($text){
    if (!is_string($text) || $text==='') return null;
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $text, $m)) return $m[1];
    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) return $m[0];
    return null;
  };
  $json_decode_loose = function($text){
    $j = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($j)) return $j;
    $first = $extract_first_json($text);
    if ($first) {
      $j = json_decode($first, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($j)) return $j;
    }
    return null;
  };
  $normalize_destiny = function($parsed){
    $dest = array_merge([
      'overview'         => '',
      'life_path'        => '',
      'soul_number'      => '',
      'numbers'          => [],
      'career'           => '',
      'mission'          => '',
      'jobs_recommended' => [],
      'lucky_colors'     => [],
      'to_practice'      => [],
      'to_avoid'         => [],
      'twelve_months'    => [],
      'fortune'          => ['money'=>'','colleagues'=>'','career'=>'','luck'=>''],
    ], is_array($parsed) ? $parsed : []);

    if (is_array($dest['overview'])) {
      $ov = $dest['overview'];
      $dest['overview'] = (string)($ov['overview'] ?? '');
      if (empty($dest['career'])  && !empty($ov['career']))  $dest['career']  = (string)$ov['career'];
      if (empty($dest['mission']) && !empty($ov['mission'])) $dest['mission'] = (string)$ov['mission'];
    } else {
      $dest['overview'] = (string)$dest['overview'];
    }

    foreach (['numbers','jobs_recommended','lucky_colors','to_practice','to_avoid','twelve_months'] as $k) {
      if (!isset($dest[$k]) || !is_array($dest[$k])) $dest[$k] = [];
    }
    if (!isset($dest['fortune']) || !is_array($dest['fortune'])) {
      $dest['fortune'] = ['money'=>'','colleagues'=>'','career'=>'','luck'=>''];
    } else {
      $dest['fortune'] = array_merge(['money'=>'','colleagues'=>'','career'=>'','luck'=>''], $dest['fortune']);
    }

    // numbers: nếu là assoc → list
    if ($dest['numbers'] && array_values($dest['numbers']) !== $dest['numbers']) {
      $list = [];
      foreach ($dest['numbers'] as $key => $row) {
        if (!is_array($row)) continue;
        $list[] = [
          'name'    => (string)($row['title'] ?? $row['name'] ?? $key),
          'meaning' => (string)($row['meaning'] ?? ''),
          'advice'  => (string)($row['brief'] ?? $row['advice'] ?? ''),
        ];
      }
      $dest['numbers'] = $list;
    }

    // map 12 tháng về {month, number, reaction, focus, notes}
    if (!empty($dest['twelve_months']) && is_array($dest['twelve_months'])) {
      $fixed = [];
      foreach ($dest['twelve_months'] as $m) {
        if (!is_array($m)) continue;
        $month  = $m['month'] ?? ($m['thang'] ?? '');
        $pnum   = $m['personal_number'] ?? ($m['num'] ?? ($m['Số học tương ứng'] ?? $m['so_hoc_tuong_ung'] ?? ''));
        $react  = $m['reaction'] ?? ($m['phan_ung'] ?? ($m['phản_ứng'] ?? ''));
        $focus  = $m['focus'] ?? '';
        $notes  = $m['notes'] ?? '';
        $fixed[] = [
          'month'    => (string)$month,
          'number'   => is_numeric($pnum) ? (int)$pnum : (string)$pnum,
          'reaction' => (string)$react,
          'focus'    => (string)$focus,
          'notes'    => (string)$notes,
        ];
      }
      $dest['twelve_months'] = $fixed;
    }
    return $dest;
  };

  // ---------- 1) Chuẩn bị ngữ cảnh ----------
  $coachee_core = $pick($profile, [
    'id','full_name','gender','dob',
    'phone','email','address',
    'company_name','company_industry','company_founded_date',
    'coach_type'
  ]);

  // Câu trả lời → text xem được
  $answers_lines = [];
  if (!empty($answers) && is_array($answers)) {
    foreach ($answers as $k => $v) {
      if (is_array($v)) $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
      $answers_lines[] = $k . ': ' . (string)$v;
    }
  }
  $answers_text = implode("\n- ", $answers_lines);

  // time now cho 12 tháng sắp tới
  $now_ts  = current_time('timestamp');
  $start_m = (int) date_i18n('n', $now_ts);
  $start_y = (int) date_i18n('Y', $now_ts);

  // ---------- 2) Prompt (nhấn mạnh dùng numeric_json) ----------
  $sys = "Bạn là chuyên gia Nhân số học & Coach phát triển sự nghiệp.
Hãy phân tích hồ sơ coachee và TRẢ VỀ DUY NHẤT MỘT JSON (RFC8259), không markdown, theo schema:

{
  \"overview\": \"[3–5 câu: (1) chân dung nhanh theo Life Path & Soul Number; (2) thế mạnh; (3) rủi ro thường gặp; (4) gợi ý chiến lược 90 ngày 1 câu]\",
  \"life_path\": \"[số đường đời]\",
  \"soul_number\": \"[số linh hồn]\",
  \"numbers\": [{\"name\":\"Tên chỉ số\", \"meaning\":\"Ý nghĩa 1 câu\", \"advice\":\"Lời khuyên ngắn\"}],
  \"career\": \"[2–3 câu, gắn Life Path/Soul Number]\",
  \"mission\": \"[2–3 câu, thực tế]\",
  \"jobs_recommended\": [\"Nghề 1\", \"Nghề 2\", \"Nghề 3\"],
  \"lucky_colors\": [\"màu 1\", \"màu 2\"],
  \"to_practice\": [\"Điều 1\", \"Điều 2\"],
  \"to_avoid\": [\"Điều 1\", \"Điều 2\"],
  \"twelve_months\": [
    {\"month\":\"Tháng m/YYYY\",\"personal_number\":0,\"reaction\":\"\",\"focus\":\"\",\"notes\":\"\"}
  ],
  \"fortune\": { \"money\": \"\", \"colleagues\": \"\", \"career\": \"\", \"luck\": \"\" }
}

YÊU CẦU:
- Chỉ JSON hợp lệ; UTF-8; không code fences.
- PHẢI dùng bộ chỉ số trong \"numeric_json\" bên dưới như NGUỒN SỰ THẬT (không tự suy số mới).
- \"life_path\" và \"soul_number\" lấy trực tiếp từ numeric_json (numbers_full.life_path.value, numbers_full.soul_number.value).
- \"twelve_months\" có đúng 12 phần tử, bắt đầu từ start_month/start_year dưới đây, cộng 11 tháng tiếp theo.
- personal_number từng tháng: nếu có DOB → PersonalYear(year) + month rồi reduce (giữ 11/22/33); nếu không có DOB → reduce(life_path + month).
- Mỗi mục reaction/focus/notes: 1 câu ngắn, thực dụng, phù hợp life_path/soul_number.";

  $user = "Ngữ cảnh Coach: ".(string)$coach_label."

Hồ sơ Coachee (rút gọn):
".wp_json_encode($coachee_core, JSON_UNESCAPED_UNICODE)."

Start thời gian:
".wp_json_encode(['start_month'=>$start_m, 'start_year'=>$start_y], JSON_UNESCAPED_UNICODE)."

Bộ chỉ số (numeric_json):
".wp_json_encode($numeric, JSON_UNESCAPED_UNICODE)."

Câu hỏi mẫu:
".wp_json_encode(array_values($questions), JSON_UNESCAPED_UNICODE)."

Câu trả lời của coachee:
- ".($answers_text ?: '(chưa có)')."

Ghi chú:
- ĐỪNG phát minh thêm số; luôn bám theo numeric_json.";

  $message = "SYSTEM:\n{$sys}\n\nUSER:\n{$user}";

  // ---------- 3) Gọi BizGPT ----------
  $api_key = get_option('bccm_openai_api_key','');
  $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, $message, false);

  if (!is_string($raw) || trim($raw)==='') {
    return new WP_Error('ai_empty', 'Không nhận được phản hồi từ BizGPT');
  }

  // ---------- 4) Parse + chuẩn hoá ----------
  $parsed  = $json_decode_loose($raw);
  if (!$parsed) {
    return new WP_Error('ai_parse_json_failed', 'AI không trả JSON hợp lệ', ['raw'=>$raw]);
  }

  // Bảo toàn life_path & soul_number theo numeric_json nếu thiếu
  if (empty($parsed['life_path']) && $life_path!=='')   $parsed['life_path']   = $life_path;
  if (empty($parsed['soul_number']) && $soul_number!=='') $parsed['soul_number'] = $soul_number;

  // Nhúng luôn numbers_full gốc vào output (để front-end hiển thị đồng bộ)
  $parsed['numbers_full'] = $numbers_full;

  return $normalize_destiny($parsed);
}


function bccm_generate_overview($coachee_id){
  global $wpdb; $t=bccm_tables();

  $profile = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id
  ), ARRAY_A);
  if (!$profile) return new WP_Error('profile_nf','Không tìm thấy hồ sơ coachee');

  $questions = bccm_get_questions_for($profile['coach_type']);
  $ans_row   = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$t['answers']} WHERE coachee_id=%d", $coachee_id
  ), ARRAY_A);
  $answers   = $ans_row && $ans_row['answers'] ? json_decode($ans_row['answers'], true) : [];

  $coach_label = bccm_coach_types()[$profile['coach_type']] ?? $profile['coach_type'];

  // Gọi AI (bắt buộc có numeric_json)
  $ai = bccm_ai_generate_for_overview($profile,$coach_label,$questions,$answers);
  if (is_wp_error($ai)) {
    // Thông báo rõ ràng khi chưa có numeric_json
    if ($ai->get_error_code()==='missing_numeric_json') {
      return $ai; // để UI hiện thông báo: “Bạn cần tạo bộ chỉ số Thần số học trước.”
    }
    return $ai;
  }

  // CHUẨN HÓA → schema mà view đang render
  $destiny = bccm_ai_to_destiny( is_array($ai) ? $ai : [] );

  // SANITY: nếu chưa có numbers_full trong destiny thì lấy từ numeric_json
  if (empty($destiny['numbers_full'])) {
    if (!empty($profile['numeric_json'])) {
      $nj = json_decode($profile['numeric_json'], true);
      if (json_last_error() === JSON_ERROR_NONE && !empty($nj['numbers_full'])) {
        $destiny['numbers_full'] = $nj['numbers_full'];
      }
    }
  }

  // Lưu
  $wpdb->update($t['profiles'], [
    'ai_summary' => bccm_safe_json($destiny),
    'updated_at' => current_time('mysql'),
  ], ['id'=>$coachee_id]);

  return true;
}



/** 3) Chỉ tạo Thần số học (numbers_full) */
/** 3) Chỉ tạo Thần số học (numbers_full) + lưu numeric_json vào profiles */
function bccm_generate_metrics_only($coachee_id){
  global $wpdb; $t=bccm_tables();
  $t_metrics = $wpdb->prefix.'bccm_metrics';
  $t_astro   = $wpdb->prefix.'bccm_astro';

  $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
  if (!$profile) return new WP_Error('profile_nf','Không tìm thấy hồ sơ coachee');

  $astro_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_astro WHERE coachee_id=%d", $coachee_id), ARRAY_A);
  $pf = $profile;
  $pf['_birth_place'] = $astro_row['birth_place'] ?? '';
  $pf['_birth_time']  = $astro_row['birth_time']  ?? '';

  // 1) Tính 21 chỉ số theo công thức (no AI)
  $metrics = bccm_ai_generate_21_metrics($pf);
  $metrics['numbers_full'] = bccm_autofill_metrics($profile, $metrics['numbers_full'] ?? []);
  $numbers = $metrics['numbers_full'] ?? [];

  // 2) AI tạo brief 7–10 từ cho từng key (nếu có proxy), fallback local
  $numbers = bccm_ai_make_7_10_briefs($profile, $numbers);

  // 3) Log
  back_trace('NOTICE', 'AI Metrics only for coachee_id='.$coachee_id.': '.print_r($numbers,true));

  // 4) Lưu vào bảng metrics (giữ API cũ)
  $wpdb->replace($t_metrics, [
    'coachee_id'   => (int)$coachee_id,
    'numbers_full' => bccm_safe_json($numbers),
    'created_at'   => current_time('mysql'),
    'updated_at'   => current_time('mysql'),
  ], ['%d','%s','%s','%s']);

  // 5) LƯU THÊM vào cột numeric_json của profiles (bccm_coachees)
  $wpdb->update($t['profiles'], [
    'numeric_json' => bccm_safe_json(['numbers_full'=>$numbers]),
    'updated_at'   => current_time('mysql'),
  ], ['id' => (int)$coachee_id], ['%s','%s'], ['%d']);

  return true;
}



/** 5) Tạo bản đồ 90 ngày (theo bảng hỏi) – UPSERT vào action_plans và trả về plan_id */
function bccm_generate_plan_from_answers($coachee_id){
  global $wpdb; 
  $t = bccm_tables();

  $profile = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id
  ), ARRAY_A);
  if (!$profile) return new WP_Error('profile_nf','Không tìm thấy hồ sơ coachee');

  // Lấy dữ liệu bảng hỏi (legacy)
  $ans_row     = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['answers']} WHERE coachee_id=%d", (int)$coachee_id), ARRAY_A);
  $questions   = bccm_get_questions_for($profile['coach_type']);
  $answers     = $ans_row && $ans_row['answers'] ? json_decode($ans_row['answers'], true) : [];
  $coach_label = bccm_coach_types()[$profile['coach_type']] ?? $profile['coach_type'];

  // Gọi AI tạo summary + plan nếu cần (để nguyên comment nếu bạn xử lý nơi khác)
  # $ai = bccm_ai_generate($profile,$coach_label,$questions,$answers);
  # if (is_wp_error($ai)) return $ai;


  // ===== Upsert vào bảng action_plans =====
  $table_action = $t['plans'] ?? ($wpdb->prefix.'bccm_action_plans');
  $now          = current_time('mysql');
  #$plan_json    = bccm_safe_json($ai['plan'] ?? []);

  // Kiểm tra đã có plan cho coachee này chưa
  $existing = $wpdb->get_row($wpdb->prepare(
    "SELECT id, public_key FROM {$table_action} WHERE coachee_id=%d LIMIT 1", (int)$coachee_id
  ));
  back_trace('NOTICE', 'Existing plan for coachee_id='.$coachee_id.': '.print_r($existing,true));
  $existing_id = $existing ? (int)$existing->id : 0;
  $public_key  = $existing ? (string)$existing->public_key : '';  
  if ($existing_id) {
    // UPDATE
    $ok = $wpdb->update(
      $table_action,
      [
        'plan'       => $plan_json,
        'updated_at' => $now,
      ],
      ['id' => $existing_id],
      ['%s','%s'],
      ['%d']
    );
    if ($ok === false) return new WP_Error('db_error','Không cập nhật được action plan.');
    $plan_id = $existing_id;
  } else {
    // INSERT
    $public_key = substr(bccm_uuid(),0,36);
    $ok = $wpdb->insert($t['plans'], [
        'coachee_id' => (int)$coachee_id,
        'template_id'=> 0,
        'plan'       => bccm_safe_json($ai['plan'] ?? []),
        'public_key' => $public_key,
        'status'     => 'active',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
      ]);
    if ($ok === false) return new WP_Error('db_error','Không lưu được action plan.');
    $plan_id = (int)$wpdb->insert_id;
  }
  $public_url = bccm_public_map_url($public_key);


  // Lưu summary về profiles (an toàn với fallback rỗng)
  $wpdb->update($t['profiles'], [
    'public_url' => $public_url,
    'updated_at' => current_time('mysql'),
  ], ['id' => (int)$coachee_id]);


  // Trả về ID để các hàm khác xử lý tiếp (không tạo/thay đổi public_url ở đây)
  return ['plan_id'=>$plan_id,'public_url'=>$public_url];
}


/* ====== AUTOFILL 21 KEYS (điền công thức tối thiểu) ====== */
function bccm_reduce_digit($n){ // giữ 11/22/33
  $n = (int)$n;
  while ($n > 9 && !in_array($n,[11,22,33],true)){
    $n = array_sum(str_split((string)$n));
  }
  return $n;
}
function bccm_autofill_metrics(array $profile, array $in): array {
  $out = $in;

  $name = (string)($profile['full_name'] ?? '');
  $dob  = (string)($profile['dob'] ?? '');
  $y=$m=$d=0;
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $mm)){
    $y=(int)$mm[1]; $m=(int)$mm[2]; $d=(int)$mm[3];
  }

  // Name numbers
  list($sum_all, $digits_all)       = bccm_sum_name($name,'all');
  list($sum_vowels, )               = bccm_sum_name($name,'vowels');     // Soul Urge
  list($sum_consonants, $digs_name) = bccm_sum_name($name,'consonants'); // Personality

  $expr    = bccm_reduce_keep_master($sum_all);        // Expression/Destiny
  $soul    = bccm_reduce_keep_master($sum_vowels);     // Soul Urge
  $perso   = bccm_reduce_keep_master($sum_consonants); // Personality
  $life    = 0;

  if (function_exists('bccm_numerology_life_path')) {
    $life = (int) bccm_numerology_life_path($dob);
  } else {
    // life path = reduce(yyyy + mm + dd)
    if ($y && $m && $d) $life = bccm_reduce_keep_master($y+$m+$d);
  }

  // Subconscious Self = số lượng CHỮ SỐ (1..9) XUẤT HIỆN ít nhất 1 lần trong tên (theo chuẩn Pitago)
  $unique = array_unique($digits_all);
  $subcon = max(1, min(9, count($unique))); // 1..9

  // Passion = “mode” (giá trị xuất hiện nhiều nhất) trong tên
  $freq = array_count_values($digits_all);
  arsort($freq);
  $passion = (int) array_key_first($freq);

  // Birth day (nguyên bản)
  $birth_day = $d ?: null;

  // Attitude = reduce(mm + dd)
  $attitude = ($m && $d) ? bccm_reduce_keep_master($m+$d) : null;

  // Maturity = reduce(life + expression)
  $maturity = ($life && $expr) ? bccm_reduce_keep_master($life+$expr) : null;

  // Personal year / month / day (theo năm hiện tại)
  if ($y && $m && $d){
    $cy = (int)date('Y');
    $py = bccm_reduce_keep_master( bccm_reduce_keep_master($cy) + $m + $d );
    $pm = bccm_reduce_keep_master( $py + (int)date('n') );
    $pd = bccm_reduce_keep_master( $pm + (int)date('j') );
  } else { $py=$pm=$pd=null; }

  // Pinnacle 1 & Challenge 1 (đơn giản hoá): pinnacle = reduce(mm + dd); challenge = reduce(|mm - dd|)
  $pinn  = ($m && $d) ? bccm_reduce_keep_master($m+$d) : null;
  $chall = ($m && $d) ? bccm_reduce_keep_master(abs($m-$d)) : null;

  // Mission = dùng Expression (thực tế nhiều trường phái lấy Expression/Destiny)
  $mission = $expr ?: null;

  // Balance = reduce(soul + personality)
  $balance = ($soul && $perso) ? bccm_reduce_keep_master($soul+$perso) : null;

  // Link life–mission = reduce(|life - mission|)
  $link_life_mission = ($life && $mission) ? bccm_reduce_keep_master(abs($life-$mission)) : null;

  // Link soul–personality = reduce(|soul - personality|)
  $link_soul_personality = ($soul && $perso) ? bccm_reduce_keep_master(abs($soul-$perso)) : null;

  // Logic = dùng Expression (khuynh hướng tư duy)
  $logic = $expr ?: null;

  // Missing digits 1..9 trong TÊN (theo số Pythagoras)
  $has = array_fill(1,9,false);
  foreach ($digits_all as $dv){ if ($dv>=1 && $dv<=9) $has[$dv]=true; }
  $missing = [];
  for($i=1;$i<=9;$i++){ if(!$has[$i]) $missing[]=$i; }
  $missing_val = $missing ? implode(',', $missing) : '—';

  // Generation (đơn giản)
  $gen = '—';
  if ($y){
    if ($y>=1965 && $y<=1980) $gen='Gen X';
    elseif ($y>=1981 && $y<=1996) $gen='Millennials';
    elseif ($y>=1997 && $y<=2012) $gen='Gen Z';
    elseif ($y<=1964) $gen='Boomers';
  }

  $set = function($k,$val,$brief) use (&$out){
    if (empty($out[$k]['value']) || $out[$k]['value']==='—'){
      $out[$k] = ['value'=>$val, 'brief'=>bccm_brief_5w($brief)];
    }
  };

  // ===== Đặt giá trị cho 21 key khi còn trống =====
  if ($life)                     $set('life_path', $life, 'Đường đời');
  if ($soul)                     $set('soul_number', $soul, 'Linh hồn');
  if ($perso)                    $set('personality', $perso, 'Nhân cách');
  if ($expr)                     $set('logic', $expr, 'Lý trí');
  if ($birth_day)                $set('birth_day', $birth_day, 'Ngày sinh');
  if ($attitude)                 $set('attitude', $attitude, 'Thái độ');
  if ($maturity)                 $set('maturity', $maturity, 'Trưởng thành');
  if ($py)                       $set('personal_year', $py, 'Năm cá nhân');
  if ($pm)                       $set('personal_month', $pm, 'Tháng cá nhân');
  if ($pd)                       $set('personal_day', $pd, 'Ngày cá nhân');
  if ($pinn)                     $set('pinnacle', $pinn, 'Đỉnh vận');
  if ($chall!==null)             $set('challenges', $chall, 'Thử thách');
  if ($passion)                  $set('passion', $passion, 'Đam mê');
  if ($subcon)                   $set('subconscious_power', $subcon, 'Tiềm thức');
  if ($mission)                  $set('mission', $mission, 'Sứ mệnh');
  if ($balance)                  $set('balance', $balance, 'Cân bằng');
  if ($link_life_mission!==null) $set('link_life_mission', $link_life_mission, 'Liên kết');
  if ($link_soul_personality!==null) $set('link_soul_personality', $link_soul_personality, 'Trong–ngoài');
  if ($missing_val!=='—')        $set('missing', $missing_val, 'Thiếu');
  if ($gen!=='—')                $set('generation', $gen, 'Thế hệ');

  // Bài học – lesson (giản lược): reduce(life + soul)
  if ($life && $soul) $set('lesson', bccm_reduce_keep_master($life+$soul),'Bài học');

  // Nếu vẫn rỗng vài ô, để nguyên '—' (sẽ được fallback ở bước merge)
  return $out;
}


/* ================== 21 metrics by formula (no AI) ================== */
function bccm_calc_21_metrics($full_name, $dob){
  // ===== Helpers =====
  $briefs = [
    'life_path'=>'Định hướng cuộc đời','balance'=>'Cân đối cảm xúc','mission'=>'Giá trị cốt lõi',
    'link_life_mission'=>'Gắn kết mục tiêu','soul_number'=>'Động lực nội tại','birth_day'=>'Ngày sinh',
    'personality'=>'Ấn tượng ban đầu','link_soul_personality'=>'Trong ngoài hài hòa','maturity'=>'Độ chín cuộc đời',
    'attitude'=>'Thái độ sống','missing'=>'Thiếu cần bù','lesson'=>'Bài học chính','logic'=>'Tư duy lý trí',
    'subconscious_power'=>'Sức mạnh tiềm thức','passion'=>'Đam mê cốt lõi','personal_year'=>'Chu kỳ năm',
    'personal_month'=>'Chu kỳ tháng','pinnacle'=>'Đỉnh vận (Chặng 1–4)','personal_day'=>'Chu kỳ ngày',
    'generation'=>'Ảnh hưởng thế hệ','challenges'=>'Thử thách chính (1–4)',
  ];
  $mk = function($v,$k) use($briefs){ return ['value'=>($v!==''?$v:'—'),'brief'=>($briefs[$k]??'—')]; };

  // Reduce: giữ 11/22/33 nếu $keep_master=true
  $reduce = function($n, $keep_master=true){
    $n = (int)$n; $abs = abs($n);
    while ($abs >= 10){
      if ($keep_master && ($abs==11 || $abs==22 || $abs==33)) return $abs;
      $sum = 0; foreach (str_split((string)$abs) as $d){ $sum += (int)$d; }
      $abs = $sum;
    }
    return $abs;
  };

  // Chuẩn hoá VN → ASCII (giữ space), trả UPPER
  $vi_norm = function($s){
    $map = [
      'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a',
      'â'=>'a','ầ'=>'a','ấ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a','è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e','ê'=>'e',
      'ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e','ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
      'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
      'ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o','ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u',
      'ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u','ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y','đ'=>'d',
      'À'=>'A','Á'=>'A','Ả'=>'A','Ã'=>'A','Ạ'=>'A','Ă'=>'A','Ằ'=>'A','Ắ'=>'A','Ẳ'=>'A','Ẵ'=>'A','Ặ'=>'A',
      'Â'=>'A','Ầ'=>'A','Ấ'=>'A','Ẩ'=>'A','Ẫ'=>'A','Ậ'=>'A','È'=>'E','É'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ẹ'=>'E','Ê'=>'E',
      'Ề'=>'E','Ế'=>'E','Ể'=>'E','Ễ'=>'E','Ệ'=>'E','Ì'=>'I','Í'=>'I','Ỉ'=>'I','Ĩ'=>'I','Ị'=>'I','Ò'=>'O','Ó'=>'O',
      'Ỏ'=>'O','Õ'=>'O','Ọ'=>'O','Ô'=>'O','Ồ'=>'O','Ố'=>'O','Ổ'=>'O','Ỗ'=>'O','Ộ'=>'O','Ơ'=>'O','Ờ'=>'O','Ớ'=>'O',
      'Ở'=>'O','Ỡ'=>'O','Ợ'=>'O','Ù'=>'U','Ú'=>'U','Ủ'=>'U','Ũ'=>'U','Ụ'=>'U','Ư'=>'U','Ừ'=>'U','Ứ'=>'U','Ử'=>'U',
      'Ữ'=>'U','Ự'=>'U','Ỳ'=>'Y','Ý'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y','Ỵ'=>'Y','Đ'=>'D'
    ];
    return strtoupper(strtr($s,$map));
  };

  // Bảng Pythagoras
  $letter2num = function($ch){
    if (!preg_match('/[A-Z]/',$ch)) return null;
    if (strpos('AJS',$ch)!==false) return 1;
    if (strpos('BKT',$ch)!==false) return 2;
    if (strpos('CLU',$ch)!==false) return 3;
    if (strpos('DMV',$ch)!==false) return 4;
    if (strpos('ENW',$ch)!==false) return 5;
    if (strpos('FOX',$ch)!==false) return 6;
    if (strpos('GPY',$ch)!==false) return 7;
    if (strpos('HQZ',$ch)!==false) return 8;
    if (strpos('IR' ,$ch)!==false) return 9;
    return null;
  };

  // Tokenization
  $name_raw  = trim((string)$full_name);
  $name_norm = $vi_norm($name_raw);
  $words = array_values(array_filter(preg_split('/\s+/u',$name_raw)));

  // Xây mảng số: toàn bộ / nguyên âm / phụ âm 17 chữ
  $is_vowel_base = function($origChar) use ($vi_norm){
    $base = $vi_norm($origChar);
    return in_array($base, ['A','E','I','O','U','Y'], true);
  };
  $is_vn_consonant = function($origChar){
    $base = mb_strtolower($origChar,'UTF-8'); // Unicode-safe
    return in_array($base, ['b','c','d','đ','g','h','k','l','m','n','p','q','r','s','t','v','x'], true);
  };

  $nums_all=[]; $nums_vowels=[]; $nums_cons=[];
  $len = mb_strlen($name_raw,'UTF-8');
  for ($i=0; $i<$len; $i++){
    $orig = mb_substr($name_raw,$i,1,'UTF-8');
    $baseU = $vi_norm($orig);
    $n = $letter2num($baseU);
    if ($n){
      $nums_all[] = $n;
      if ($is_vowel_base($orig))   $nums_vowels[] = $n;
      if ($is_vn_consonant($orig)) $nums_cons[]   = $n;
    }
  }

  $sum = function($arr){ return array_sum($arr); };
  $sum_digits = function($n){ $n=(string)abs((int)$n); $s=0; foreach(str_split($n) as $d){ $s+=(int)$d; } return $s; };

  // Parse DOB
  $y=$m=$d=0;
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',(string)$dob,$mm)){ $y=(int)$mm[1]; $m=(int)$mm[2]; $d=(int)$mm[3]; }

  // Tổng từng phần
  $tDay   = $d? $sum_digits($d)   : 0;
  $tMonth = $m? $sum_digits($m)   : 0;
  $tYear  = $y? $sum_digits($y)   : 0;

  // 1) Life Path
  $life_path = ($d&&$m&&$y) ? $reduce($reduce($tDay,true)+$reduce($tMonth,true)+$reduce($tYear,true), true) : '—';

  // 2) Mission (cộng theo từng từ)
  $mission = '—';
  if (!empty($words)){
    $parts = [];
    foreach($words as $w){
      $wN = $vi_norm($w); $acc=0;
      foreach (str_split($wN) as $ch){ $n=$letter2num($ch); if($n){ $acc += $n; } }
      $parts[] = $reduce($acc, true);
    }
    $mission = $reduce(array_sum($parts), true);
  }

  // 3) Maturity
  $maturity = (is_numeric($life_path)&&is_numeric($mission)) ? $reduce($life_path+$mission, true) : '—';

  // 4) Soul
  $soul = count($nums_vowels) ? $reduce($sum($nums_vowels), true) : '—';

  // 5) Balance (chữ cái đầu mỗi từ)
  $balance = '—';
  if (!empty($words)){
    $acc=0; foreach($words as $w){
      $first = mb_substr($w,0,1,'UTF-8');
      $n=$letter2num($vi_norm($first)); if($n) $acc+=$n;
    }
    if ($acc>0) $balance=$reduce($acc,true);
  }

  // 6) Personality
  $personality = count($nums_cons) ? $reduce($sum($nums_cons), true) : '—';

  // 7) Birth day
  $birth_day = $d ? $reduce($tDay, true) : '—';

  // 8) Links
  $link_life_mission = (is_numeric($life_path)&&is_numeric($mission))
    ? abs($reduce($life_path,false) - $reduce($mission,false)) : '—';
  $link_soul_personality = (is_numeric($soul)&&is_numeric($personality))
    ? abs($reduce($soul,false) - $reduce($personality,false)) : '—';

  // 9) Missing / Subconscious / Passion
  $freq = array_fill(1,9,0);
  foreach($nums_all as $n){ if(isset($freq[$n])) $freq[$n]++; }
  $missing_arr=[]; foreach(range(1,9) as $k){ if($freq[$k]===0) $missing_arr[]=$k; }
  $missing_str = $missing_arr ? implode(',', $missing_arr) : 'Không thiếu';
  $subconscious = 9 - count($missing_arr);
  $maxc = max($freq) ?: 0;
  $passion = $maxc>0
    ? implode(',', array_keys(array_filter($freq, function($c) use($maxc){ return $c===$maxc; })))
    : '—';

  // 10) Logic = (tên gọi) + (tổng ngày)
  $logic = '—';
  if (!empty($words) && $d){
    $given = $vi_norm($words[count($words)-1]);
    $acc=0; foreach(str_split($given) as $ch){ $n=$letter2num($ch); if($n){ $acc+=$n; } }
    $logic = $reduce($acc + $tDay, true);
  }

  // 11) Attitude
  $attitude = ($d&&$m) ? $reduce($tDay + $tMonth, true) : '—';

  // 12) Pinnacles & Challenges
  $c1 = ($d||$m) ? $reduce($tDay+$tMonth,false) : null;
  $c2 = ($d||$y) ? $reduce($tDay+$tYear ,false) : null;
  $c3 = ($c1!==null && $c2!==null) ? $reduce($c1+$c2,false) : null;
  $c4 = ($m||$y) ? $reduce($tMonth+$tYear,false) : null;
  $pinnacle_val = ($c1!==null) ? 'C1='.$c1.', C2='.$c2.', C3='.$c3.', C4='.$c4 : '—';

  $th1 = ($d||$m) ? $reduce(abs($tDay-$tMonth),false) : null;
  $th2 = ($d||$y) ? $reduce(abs($tDay-$tYear ),false) : null;
  $th3 = ($th1!==null && $th2!==null) ? $reduce(abs($th1-$th2),false) : null;
  $th4 = ($m||$y) ? $reduce(abs($tMonth-$tYear),false) : null;
  $challenges_val = ($th1!==null) ? 'T1='.$th1.', T2='.$th2.', T3='.$th3.', T4='.$th4 : '—';

  // 13) PY/PM/PD
  if ($d&&$m){
    $nowY=(int)date('Y'); $nowM=(int)date('n'); $nowD=(int)date('j');
    $py = $reduce($tDay+$tMonth+$sum_digits($nowY), false);
    $pm = $reduce($py+$nowM, false);
    $pd = $reduce($pm+$sum_digits($nowD), false);
  } else { $py=$pm=$pd='—'; }

  // 14) Generation
  $gen='—';
  if ($y){
    if ($y>=1981&&$y<=1996) $gen='Millennials';
    elseif($y>=1965&&$y<=1980) $gen='Gen X';
    elseif($y>=1997&&$y<=2012) $gen='Gen Z';
    elseif($y<=1964) $gen='Boomers';
  }

  // 15) Build output
  $keys = function_exists('bccm_metric_21_keys') ? bccm_metric_21_keys() : (function_exists('bccm_metric_keys') ? bccm_metric_keys() : []);
  $out=[];
  $out['life_path']             = $mk($life_path,'life_path');
  $out['balance']               = $mk($balance,'balance');
  $out['mission']               = $mk($mission,'mission');
  $out['link_life_mission']     = $mk($link_life_mission,'link_life_mission');
  $out['soul_number']           = $mk($soul,'soul_number');
  $out['birth_day']             = $mk($birth_day,'birth_day');
  $out['personality']           = $mk($personality,'personality');
  $out['link_soul_personality'] = $mk($link_soul_personality,'link_soul_personality');
  $out['maturity']              = $mk($maturity,'maturity');
  $out['attitude']              = $mk($attitude,'attitude');
  $out['missing']               = $mk($missing_str,'missing');
  $out['lesson']                = $mk($missing_str,'lesson');
  $out['logic']                 = $mk($logic,'logic');
  $out['subconscious_power']    = $mk($subconscious,'subconscious_power');
  $out['passion']               = $mk($passion,'passion');
  $out['personal_year']         = $mk($py,'personal_year');
  $out['personal_month']        = $mk($pm,'personal_month');
  $out['pinnacle']              = $mk($pinnacle_val,'pinnacle');
  $out['personal_day']          = $mk($pd,'personal_day');
  $out['generation']            = $mk($gen,'generation');
  $out['challenges']            = $mk($challenges_val,'challenges');

  foreach($keys as $k){ if(empty($out[$k])) $out[$k]=['value'=>'—','brief'=>($briefs[$k]??'—')]; }
  return ['numbers_full'=>$out];
}



/* Alias giữ API cũ */
function bccm_ai_generate_21_metrics($profile){
  return bccm_calc_21_metrics($profile['full_name'] ?? '', $profile['dob'] ?? '');
}

/**
 * Sinh brief 7–10 từ cho MỖI key dựa trên numbers_full hiện có (value + title).
 * Trả về mảng numbers_full MỚI với brief đã được update (fallback: giữ nguyên).
 */

function bccm_ai_make_7_10_briefs(array $profile, array $numbers_full): array {
  // Chuẩn bị payload gọn
  $titles = function_exists('bccm_metric_titles') ? bccm_metric_titles() : array_combine(bccm_metric_keys(), bccm_metric_keys());
  $keys   = bccm_metric_keys();
  $input  = [];
  foreach($keys as $k){
    $val = $numbers_full[$k]['value'] ?? '—';
    $input[$k] = [
      'title' => $titles[$k] ?? $k,
      'value' => is_array($val) ? '' : (string)$val,
    ];
  }

  // Prompt
  $sys = "Bạn là chuyên gia Nhân số học.
Trả về DUY NHẤT JSON hợp lệ (RFC8259), KHÔNG markdown.
Với MỖI key trong danh sách, tạo 'brief' tiếng Việt 7–10 từ,
là câu nhận xét súc tích, thực dụng, không emoji, không dấu chấm cuối câu.
Không trả field ngoài yêu cầu.";
  $usr = [
    'profile'=>[
      'name'=>$profile['full_name'] ?? '',
      'dob'=>$profile['dob'] ?? '',
    ],
    'require'=>[
      'keys'=>$keys,
      'accepted_shapes'=>[
        '{"numbers_full":{"<key>":{"brief":"..."}}}',
        '{"briefs":{"<key>":"..."}}',
        '{"<key>":"..."}'
      ],
      'rule'=>[
        'ngắn'=>'7–10 từ',
        'dựa_trên'=>'title + value của từng key',
        'không'=>'không thêm field lạ, không viết dài dòng'
      ]
    ],
    'data'=> $input,
    'example'=>[
      'numbers_full'=>[
        'life_path'=>['brief'=>'khả năng lãnh đạo mạnh, định hướng rõ ràng cuộc đời'],
        'soul_number'=>['brief'=>'động lực bên trong hướng tới sáng tạo, cảm xúc sâu']
      ]
    ]
  ];
  $ask  = "SYSTEM: {$sys}\nUSER_JSON: ".wp_json_encode($usr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

  // Nếu thiếu proxy/token hoặc hàm call không tồn tại → fallback local
  $api_key = get_option('bccm_openai_api_key',''); // vẫn giữ để tương thích, nhưng proxy dùng token khác
  if (!function_exists('chatbot_chatgpt_call_omni_bizcoach_map') || empty(get_option('bizgpt_api',''))){
    if (function_exists('back_trace')) back_trace('WARN','AI proxy unavailable → use local fallback briefs');
    return bccm_local_make_briefs($numbers_full);
  }

  // Gọi proxy
  $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, $ask, false,false,false,false);
  if (function_exists('back_trace')) back_trace('NOTICE', 'AI raw briefs: '.(is_string($raw)?$raw:'[non-string]'));

  // Nếu rỗng/lỗi → fallback local
  if (!is_string($raw) || trim($raw)===''){
    if (function_exists('back_trace')) back_trace('ERROR','AI briefs empty → use local fallback');
    return bccm_local_make_briefs($numbers_full);
  }

  // Parse
  $mapBriefs = bccm_parse_ai_briefs($raw);
  if (!$mapBriefs){
    if (function_exists('back_trace')) back_trace('ERROR','AI briefs parse failed → use local fallback');
    return bccm_local_make_briefs($numbers_full);
  }

  // Merge
  foreach($keys as $k){
    if (!array_key_exists($k, $mapBriefs)) continue;
    $brief_ai = bccm_brief_force_7_10((string)$mapBriefs[$k]);
    if (!isset($numbers_full[$k]) || !is_array($numbers_full[$k])) $numbers_full[$k]=['value'=>'—','brief'=>'—'];
    $numbers_full[$k]['brief'] = $brief_ai !== '' ? $brief_ai : ($numbers_full[$k]['brief'] ?? '—');
  }

  if (function_exists('back_trace')) back_trace('NOTICE','AI briefs merged: '.print_r($mapBriefs,true));
  return $numbers_full;
}

if (!function_exists('bccm_generate_baby_growth_map')) {
  function bccm_generate_baby_growth_map($coachee_id){
    global $wpdb; $t = bccm_tables();

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id), ARRAY_A);
    if (!$row) return;

    // Tính tuổi theo tháng (xấp xỉ)
    $months = null;
    if (!empty($row['dob'])) {
      try {
        $dob = new DateTime($row['dob']);
        $now = new DateTime('now', wp_timezone());
        $diff = $dob->diff($now);
        $months = $diff->y * 12 + $diff->m + ($diff->d >= 15 ? 1 : 0);
      } catch (\Exception $e) {}
    }

    // Stub percentiles (đặt mặc định, bạn sẽ thay bằng bảng WHO/CDC sau)
    $heightP = null; $weightP = null; $bmiP = null;
    if (is_numeric($row['baby_height_cm']) && is_numeric($row['baby_weight_kg']) && $months !== null) {
      $h = (float)$row['baby_height_cm'];
      $w = (float)$row['baby_weight_kg'];
      $bmi = $h > 0 ? ($w / pow($h/100.0, 2)) : null;

      // Rule of thumb giả định (đủ để demo UI): 
      //   chuẩn ~ P50 khi (tháng<=12 && h~75, w~9) hoặc (tháng>12 && h~80-85, w~11-12)
      //   chỉ để hiển thị — bạn thay bằng lookup WHO sau.
      $heightP = $h < 70 ? 'P10' : ($h < 75 ? 'P25' : ($h < 80 ? 'P50' : ($h < 85 ? 'P75' : 'P90')));
      $weightP = $w < 8 ? 'P10' : ($w < 9 ? 'P25' : ($w < 11 ? 'P50' : ($w < 13 ? 'P75' : 'P90')));
      $bmiP    = ($bmi !== null) ? ( $bmi < 14 ? 'P10' : ($bmi < 15 ? 'P25' : ($bmi < 17 ? 'P50' : ($bmi < 18.5 ? 'P75' : 'P90'))) ) : null;
    }

    $baby = [
      'age_months'  => $months,
      'inputs'      => [
        'name'   => $row['baby_name'] ?? null,   // <— MỚI
        'gender' => $row['baby_gender'] ?? null,
        'weeks'  => $row['baby_gestational_weeks'] ?? null,
        'weight' => $row['baby_weight_kg'] ?? null,
        'height' => $row['baby_height_cm'] ?? null,
        'dob'    => $row['dob'] ?? null,
      ],
      'percentiles' => [
        'height' => $heightP,
        'weight' => $weightP,
        'bmi'    => $bmiP,
      ],
      'advice'      => [
        'summary' => 'Kết quả ước lượng minh họa. Vui lòng cập nhật bảng WHO/CDC để có percentile chính xác.',
        'next'    => ['Theo dõi hàng tháng', 'Bổ sung dinh dưỡng phù hợp', 'Khám định kỳ nếu thấp hơn P10 kéo dài'],
      ],
      'ts' => current_time('mysql'),
    ];

    $wpdb->update($t['profiles'], ['baby_json' => wp_json_encode($baby, JSON_UNESCAPED_UNICODE)], ['id'=>$coachee_id]);
  }
}


function bccm_generate_90day_action_plan_ai($coachee_id, $plan_id = null){
  global $wpdb;

  // ===== resolve tables =====
  $t = function_exists('bccm_tables') ? bccm_tables() : [];
  $profiles_tbl     = $t['profiles']      ?? ($wpdb->prefix.'bccm_coachees');
  $action_plans_tbl = $t['action_plans']  ?? ($wpdb->prefix.'bccm_action_plans');

  // ===== load profile =====
  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$profiles_tbl} WHERE id=%d", (int)$coachee_id
  ), ARRAY_A);
  if (!$row) {
    return function_exists('is_wp_error') ? new WP_Error('coachee_nf','Không tìm thấy coachee.') : false;
  }

  // ===== helpers =====
  $pick_json = function($v){
    if (is_array($v)) return $v;
    if (!is_string($v) || $v==='') return [];
    $j = json_decode($v, true);
    if (json_last_error()===JSON_ERROR_NONE && is_array($j)) return $j;
    // last resort: bắt khối JSON lớn nhất
    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $v, $m)) {
      $j = json_decode($m[0], true);
      return (json_last_error()===JSON_ERROR_NONE && is_array($j)) ? $j : [];
    }
    return [];
  };

  $extract_first_json = function($text){
    if (!is_string($text) || trim($text)==='') return null;
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $text, $m)) return $m[1];
    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) return $m[0];
    return null;
  };

  // IMPORTANT: use($extract_first_json) để tránh lỗi "Function name must be a string"
  $decode_loose = function($raw) use ($extract_first_json){
    if (is_array($raw)) return $raw;
    if (!is_string($raw)) return [];
    $j = json_decode($raw, true);
    if (json_last_error()===JSON_ERROR_NONE && is_array($j)) return $j;
    $raw = trim($raw);
    $first = $extract_first_json($raw);
    if ($first) {
      $j = json_decode($first, true);
      if (json_last_error()===JSON_ERROR_NONE && is_array($j)) return $j;
    }
    return [];
  };

  // ===== context =====
  $ctx = [
    'numeric'  => $pick_json($row['numeric_json']  ?? ''),
    'summary'  => $pick_json($row['ai_summary']    ?? ''),
    'iqmap'    => $pick_json($row['iqmap_json']    ?? ''),
    'vision'   => $pick_json($row['vision_json']   ?? ''),
    'swot'     => $pick_json($row['swot_json']     ?? ''),
    'customer' => $pick_json($row['customer_json'] ?? ''),
    'value'    => $pick_json($row['value_json']    ?? ''),
    'winning'  => $pick_json($row['winning_json']  ?? ''),
  ];

  $life_path   = (string)($ctx['numeric']['numbers_full']['life_path']['value']   ?? ($ctx['summary']['life_path'] ?? ''));
  $soul_number = (string)($ctx['numeric']['numbers_full']['soul_number']['value'] ?? ($ctx['summary']['soul_number'] ?? ''));

  // ===== template skeleton =====
  if (function_exists('bccm_action_plan_template_90d')) {
    $template = bccm_action_plan_template_90d();
  } else {
    // minimal fallback template (đủ 4 phase để AI đổ nội dung)
    $template = [
      'meta' => [
        'version' => '1.0',
        'generated_at' => current_time('mysql'),
        'notes' => [],
      ],
      'overview' => [
        'theme' => '90-Day Strategy Canvas',
        'objective' => 'Xây & triển khai canvas chiến lược 90 ngày để tăng trưởng.',
        'output' => 'Bản đồ 90 ngày chuyển hoá + báo cáo đánh giá',
        'company' => (string)($row['company_name'] ?? $row['full_name'] ?? ''),
        'industry'=> (string)($row['company_industry'] ?? ''),
      ],
      'phases' => [
        [
          'title' => 'Module 0 – Awakening (Day 1–9)',
          'key'   => 'p0',
          'modules' => [[
            'title'=>'Thức tỉnh tư duy & vẽ bản đồ 90 ngày',
            'day_range'=>'1–9',
            'objectives'=>[
              'Hiểu “Why” và năng lực lõi doanh nghiệp.',
              'Xác định Business Model, khách hàng, nỗi đau.',
              'Xây dựng ma trận nhu cầu & chuỗi giá trị.',
              'Xác định SWOT, pain points.',
            ],
            'tools'=>['BizCoach Map'],
            'outputs'=>['Bản đồ 90 ngày chuyển hóa'],
            'context'=> new stdClass(),
          ]],
        ],
        ['title'=>'Giai đoạn 1 – Foundation','key'=>'p1','modules'=>[[],[],[]]],
        ['title'=>'Giai đoạn 2 – Acceleration','key'=>'p2','modules'=>[[],[],[]]],
        ['title'=>'Giai đoạn 3 – Growth','key'=>'p3','modules'=>[[],[],[]]],
      ],
    ];
  }

  // Hints cá nhân hoá
  $lead_hint = '';
  if (!empty($ctx['iqmap']['scores']['leadership']['strength'])) {
    $lead_hint = 'Leadership: '.$ctx['iqmap']['scores']['leadership']['strength'];
  }
  $personal_hints = array_values(array_filter([
    $life_path ? ('LifePath '.$life_path) : '',
    $soul_number ? ('Soul '.$soul_number) : '',
    $lead_hint
  ]));
  if ($personal_hints) {
    $template['meta']['notes'][] = 'Hints: '.implode(' | ', $personal_hints);
  }

  // Gắn bối cảnh cho Module 0
  if (isset($template['phases'][0]['modules'][0])) {
    $template['phases'][0]['modules'][0]['context'] = [
      'why'            => $ctx['vision']['why'] ?? ($ctx['summary']['mission'] ?? ''),
      'business_model' => $ctx['winning']['what']['model'] ?? ($ctx['value']['model'] ?? ''),
      'customers'      => $ctx['customer']['persona'] ?? ($ctx['customer']['personas'] ?? ($ctx['customer']['profiles'] ?? [])),
      'pain_points'    => $ctx['customer']['pain_points'] ?? [],
      'value_chain'    => $ctx['value']['value_chain'] ?? ($ctx['value']['proposition'] ?? []),
      'swot'           => [
        'strengths'     => array_values((array)($ctx['swot']['strengths']      ?? [])),
        'weaknesses'    => array_values((array)($ctx['swot']['weaknesses']     ?? [])),
        'opportunities' => array_values((array)($ctx['swot']['opportunities']  ?? [])),
        'threats'       => array_values((array)($ctx['swot']['threats']        ?? [])),
      ],
      'winning'        => $ctx['winning'],
    ];
  }

  // ===== prompt =====
  $sys = "Bạn là BizCoach chiến lược. Trả về DUY NHẤT JSON hợp lệ (RFC8259), không markdown.
Nhiệm vụ: Cá nhân hoá 90-Day Action Plan dựa trên TEMPLATE + dữ liệu coachee.
Quy tắc:
- Giữ nguyên cấu trúc 4 phase như TEMPLATE (Module 0 + 3 giai đoạn x 3 module).
- Mỗi module có: title, day_range, objectives[], tools[], outputs[], và thêm:
  - kpis[]  (≤5 KPI định lượng, đo hàng tuần hoặc theo module)
  - tasks[] (≤7 công việc hành động, rõ ràng, có thể giao)
  - owners[] (vai trò chịu trách nhiệm: Founder/Marketing/Sales/CSKH/CRM/IT)
- Ngôn ngữ: tiếng Việt, ngắn gọn, thực dụng.";

  $usr = [
    'coachee' => [
      'id'          => (int)$coachee_id,
      'name'        => (string)($row['full_name'] ?? ''),
      'company'     => (string)($row['company_name'] ?? ''),
      'industry'    => (string)($row['company_industry'] ?? ''),
      'coach_type'  => (string)($row['coach_type'] ?? ''),
    ],
    'data' => [
      'numeric_json'  => $ctx['numeric'],
      'ai_summary'    => $ctx['summary'],
      'iqmap_json'    => $ctx['iqmap'],
      'vision_json'   => $ctx['vision'],
      'swot_json'     => $ctx['swot'],
      'customer_json' => $ctx['customer'],
      'value_json'    => $ctx['value'],
      'winning_json'  => $ctx['winning'],
    ],
    'template' => $template,
    'requirements' => [
      'keep_structure' => true,
      'limit_kpis'     => 5,
      'limit_tasks'    => 7,
      'owners_examples'=> ['Founder','Marketing','Sales','CSKH','CRM','IT'],
      'tone'           => 'ngắn gọn, rõ ràng, hành động',
    ],
  ];

  $api_key = get_option('bccm_openai_api_key','');
  $raw = '';

  if (function_exists('chatbot_chatgpt_call_omni_bizcoach_map')) {
    $ask = "SYSTEM:\n{$sys}\n\nUSER_JSON:\n".wp_json_encode($usr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    try {
      $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, $ask, false, false, false, false);
    } catch (\Throwable $e) {
      $raw = '';
    }
  }

  // parse AI
  $ai = $decode_loose($raw);

  // Validate tối thiểu
  $ok_shape = (is_array($ai) && isset($ai['phases']) && is_array($ai['phases']) && count($ai['phases'])===4);
  if (!$ok_shape) {
    // Fallback: dùng template gốc
    $ai = [
      'meta'     => $template['meta'],
      'overview' => $template['overview'],
      'phases'   => $template['phases'],
      'trace'    => [
        'source'=>'fallback:template',
        'coachee_id'=>(int)$coachee_id,
        'raw'=> is_string($raw)? mb_substr($raw,0,2000) : ''
      ],
    ];
  } else {
    // Bảo đảm các trường meta/overview/trace tồn tại
    $ai['meta']     = array_merge(['version'=>'1.0','generated_at'=>current_time('mysql'),'notes'=>[]], (array)($ai['meta'] ?? []));
    $ai['overview'] = array_merge((array)$template['overview'], (array)($ai['overview'] ?? []));
    $ai['trace']    = ['source'=>'ai', 'coachee_id'=>(int)$coachee_id];
  }

  // Upsert DB
  return bccm_action_plan_upsert($action_plans_tbl, $coachee_id, $plan_id, $ai);
}

/**
 * Upsert tiện ích cho bảng action_plans (id, coachee_id, title, plan, status, created_at, updated_at)
 */
if (!function_exists('bccm_action_plan_upsert')) {
  function bccm_action_plan_upsert($table, $coachee_id, $plan_id, array $plan){
    global $wpdb;
    $now = current_time('mysql');
    back_trace('NOTICE','Upsert action plan for coachee_id='.(int)$coachee_id);
    back_trace('DEBUG','Plan: '.print_r($plan,true));
    $exists = (int)$plan_id;

    if ($exists) {
      $ok = $wpdb->update($table, [
        'plan'       => wp_json_encode($plan, JSON_UNESCAPED_UNICODE),
        'status'     => 'active',
        'updated_at' => $now,
      ], ['id'=>$exists], ['%s','%s','%s','%s'], ['%d']);
    } else {
      $ok = $wpdb->insert($table, [
        'coachee_id' => (int)$coachee_id,
        'plan'       => wp_json_encode($plan, JSON_UNESCAPED_UNICODE),
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
      ], ['%d','%s','%s','%s','%s','%s']);
    }

    if ($ok === false) {
      return function_exists('is_wp_error') ? new WP_Error('db_error','Không lưu được action plan.') : false;
    }
    return true;
  }
}


/**
 * Template 90 ngày: dựng đúng cấu trúc bạn gửi.
 * Có thêm trường 'context','tools','outputs' để sau này hiển thị/tuỳ biến dễ.
 */
if (!function_exists('bccm_action_plan_template_90d')) {
  function bccm_action_plan_template_90d() {
    $meta = [
      'version'     => '1.0',
      'generated_at'=> current_time('mysql'),
      'notes'       => [],
    ];
    $overview = [
      'theme'     => '90-Day Strategy Canvas',
      'objective' => 'Xây và triển khai canvas chiến lược 90 ngày để tăng trưởng thực dụng.',
      'output'    => 'Bản đồ 90 ngày chuyển hoá + báo cáo đánh giá',
    ];

    $phase0 = [
      'title'   => 'Module 0 – Awakening (Day 1–9)',
      'key'     => 'p0',
      'modules' => [[
        'title'     => 'Thức tỉnh tư duy & vẽ bản đồ 90 ngày',
        'day_range' => '1–9',
        'objectives'=> [
          'Hiểu “Why” và năng lực lõi doanh nghiệp.',
          'Xác định Business Model doanh nghiệp.',
          'Xác định khách hàng, chân dung khách hàng, nỗi đau.',
          'Xây dựng Ma trận nhu cầu khách hàng và chìa khóa.',
          'Xây dựng Ma trận chuỗi giá trị phục vụ khách hàng.',
          'Xây dựng lộ trình củng cố các trụ cột cho hoạt động kinh doanh.',
          'Thảo luận xác định SWOT, pain points.'
        ],
        'tools'     => ['BizCoach Map'],
        'notes'     => ['Sử dụng BizCoach Map để xây dựng “90-day strategy canvas”.'],
        'outputs'   => ['Bản đồ 90 ngày chuyển hóa'],
        'context'   => new stdClass(), // sẽ gắn ở hàm chính
      ]]
    ];

    $phase1 = [
      'title'   => 'Giai đoạn 1 – Foundation',
      'key'     => 'p1',
      'modules' => [
        [
          'title'=>'Module 1 (Day 10–18) – Xây thương hiệu',
          'day_range'=>'10–18',
          'objectives'=>[
            'Công cụ: temAI để giữ chân khách hàng, tích điểm, đổi quà, được chăm sóc',
            'Xây dựng Brand Kit (slide, guideline).',
            'Thực hành: chỉnh sửa bản nhận diện nhanh bằng AI.',
          ],
          'tools'=>['temAI','AI design tools'],
          'outputs'=>['Brand Kit tối thiểu','Checklist triển khai temAI'],
        ],
        [
          'title'=>'Module 2 (Day 19–27) – Web & Truyền thông',
          'day_range'=>'19–27',
          'objectives'=>[
            'Công cụ: BizGPT Web đa ngôn ngữ.',
            'Website + blog + landing page.',
            'Tích hợp chatbot 24/7 (Messenger, Zalo, Telegram).',
          ],
          'tools'=>['BizGPT Web','Chatbot connectors'],
          'outputs'=>['Website/LP chạy bản đầu','Chatbot trực 24/7'],
        ],
        [
          'title'=>'Module 3 (Day 28–36) – Chẩn đoán & Quy trình',
          'day_range'=>'28–36',
          'objectives'=>[
            'Chuẩn hóa quy trình kinh doanh (sales, CSKH).',
            'Dùng BizCoach Map để tracking baseline.',
            'Dashboard chỉ số vận hành.',
          ],
          'tools'=>['BizCoach Map','Dashboard BI'],
          'outputs'=>['SOP/Checklist cốt lõi','Baseline dashboard'],
        ],
      ],
    ];

    $phase2 = [
      'title'   => 'Giai đoạn 2 – Acceleration',
      'key'     => 'p2',
      'modules' => [
        [
          'title'=>'Module 4 (Day 37–45) – CRM & Quản trị khách hàng',
          'day_range'=>'37–45',
          'objectives'=>[
            'Tích hợp CRM + AI reminder.',
            'Quản lý leads, pipeline bán hàng.',
            'Case study: BizGPT CRM demo.',
          ],
          'tools'=>['CRM','AI Reminder'],
          'outputs'=>['Pipeline hoạt động','Nhật ký chăm sóc khách'],
        ],
        [
          'title'=>'Module 5 (Day 46–54) – Content & Marketing AI',
          'day_range'=>'46–54',
          'objectives'=>[
            'Lập lịch content 30 ngày bằng BizCoach.',
            'Dùng BizGPT tạo social post, video AI, infographic.',
            'Workshop: thiết kế 1 chiến dịch social.',
          ],
          'tools'=>['BizCoach Content','BizGPT Creative'],
          'outputs'=>['Calendar 30 ngày','1 chiến dịch thử'],
        ],
        [
          'title'=>'Module 6 (Day 55–63) – Loyalty & Doanh thu',
          'day_range'=>'55–63',
          'objectives'=>[
            'QR code temAI để quản lý khách hàng thân thiết.',
            'Tự động upsell/cross-sell qua chatbot.',
            'Kết quả: khởi chạy chiến dịch loyalty đầu tiên.',
          ],
          'tools'=>['temAI','Chatbot'],
          'outputs'=>['Chiến dịch loyalty #1','Báo cáo A/B đơn giản'],
        ],
      ],
    ];

    $phase3 = [
      'title'   => 'Giai đoạn 3 – Growth',
      'key'     => 'p3',
      'modules' => [
        [
          'title'=>'Module 7 (Day 64–72) – Tự động hóa bán hàng',
          'day_range'=>'64–72',
          'objectives'=>[
            'AI Chatbot chốt đơn tự động qua comment, messenger, hoặc khi livestream.',
            'Tích hợp WooCommerce/POS.',
            'Demo: quy trình bán hàng tự động end-to-end.',
          ],
          'tools'=>['AI Comment Bot','WooCommerce/POS'],
          'outputs'=>['Kịch bản auto-chốt','Demo end-to-end'],
        ],
        [
          'title'=>'Module 8 (Day 73–81) – Mở rộng thị trường',
          'day_range'=>'73–81',
          'objectives'=>[
            'Website đa ngôn ngữ (EN/JP/CN).',
            'Quảng cáo AI-optimized (Google/Facebook Ads).',
            'Case study: mở rộng xuất khẩu bằng web đa ngôn ngữ.',
          ],
          'tools'=>['i18n Website','Ads Manager + AI'],
          'outputs'=>['Site i18n tối thiểu','Kế hoạch Ads mở rộng'],
        ],
        [
          'title'=>'Module 9 (Day 82–90) – Đánh giá & Nhân bản',
          'day_range'=>'82–90',
          'objectives'=>[
            'So sánh baseline ↔ kết quả sau 90 ngày.',
            'Sinh 90-Day Report Map bằng BizCoach.',
            'Chuẩn hóa playbook để scale ra chi nhánh/đối tác.',
          ],
          'tools'=>['BizCoach Report','Playbook Builder'],
          'outputs'=>['90-Day Report','Playbook scale'],
        ],
      ],
    ];

    return [
      'meta'     => $meta,
      'overview' => $overview,
      'phases'   => [$phase0, $phase1, $phase2, $phase3],
    ];
  }
}
