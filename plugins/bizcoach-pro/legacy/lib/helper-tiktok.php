<?php
if (!defined('ABSPATH')) exit;
/**
 * Sinh kế hoạch 90 ngày TIKTOK + ~30 kịch bản theo phong cách cá nhân.
 * Dùng bccm_action_plan_template_90d_tiktokcoach() làm skeleton.
 */
if (!function_exists('bccm_generate_90day_tiktokcoach_ai')) {
  function bccm_generate_90day_tiktokcoach_ai($coachee_id, $plan_id = null){
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
      'numeric'  => $pick_json($row['numeric_json']   ?? ''),
      'summary'  => $pick_json($row['ai_summary']     ?? ''),
      'iqmap'    => $pick_json($row['iqmap_json']     ?? ''),
      'vision'   => $pick_json($row['vision_json']    ?? ''),
      'swot'     => $pick_json($row['swot_json']      ?? ''),
      'customer' => $pick_json($row['customer_json']  ?? ''),
      'value'    => $pick_json($row['value_json']     ?? ''),
      'winning'  => $pick_json($row['winning_json']   ?? ''),
      // NEW: cố gắng lấy answer_json từ nhiều cột khả dĩ
      'answers'  => $pick_json($row['answer_json']    ?? ($row['answers_json'] ?? ($row['answers'] ?? ($row['questionnaire_json'] ?? '')))),
    ];

    // Lấy vài hint cá nhân hóa
    $life_path   = (string)($ctx['numeric']['numbers_full']['life_path']['value']   ?? ($ctx['summary']['life_path'] ?? ''));
    $soul_number = (string)($ctx['numeric']['numbers_full']['soul_number']['value'] ?? ($ctx['summary']['soul_number'] ?? ''));

    // ===== template skeleton TikTok =====
    $template = bccm_action_plan_template_90d_tiktokcoach([
      'company'  => (string)($row['company_name'] ?? $row['full_name'] ?? ''),
      'industry' => (string)($row['company_industry'] ?? ''),
    ]);

    // Ghi chú hint
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

    // ===== prompt =====
    $sys = "Bạn là TikTokCoach. Trả về DUY NHẤT JSON hợp lệ (RFC8259), không markdown.
Nhiệm vụ: Cá nhân hoá lộ trình 90 ngày xây kênh TikTok dựa trên TEMPLATE + dữ liệu coachee (answer_json, numeric_json, ai_summary...).
Quy tắc:
- Giữ nguyên cấu trúc 4 phase như TEMPLATE (Module 0 + 3 giai đoạn x 3 module).
- Mỗi module có: title, day_range, objectives[], tools[], outputs[], và thêm:
  - kpis[]  (≤5 KPI định lượng)
  - tasks[] (≤7 hành động rõ ràng)
  - owners[] (vai trò: Creator/Editor/TikTokCoach/Marketing/Sales/IT/CRM)
- BẮT BUỘC thêm mảng content_scripts (~30 items) theo phong cách cá nhân từ answer_json:
  Mỗi item gồm: 
    { 
      \"title\", \"hook\", \"outline\"[], \"style\"[], \"duration_sec\", 
      \"cta\", \"caption\", \"hashtags\"[], \"assets\"[], \"broll\"[], \"reason\"
    }
- Phải map script theo Content Pillars (nếu có), ưu tiên chủ đề ‘fighting’ & pain points, ngôn ngữ Việt, giọng văn phù hợp brandstyle.
- Nếu có niches/ICP/độ tuổi/giới tính → phản ánh vào hook/caption/hashtags.
- Ưu tiên retention (3s–8s), call-to-action rõ, và khả năng thương mại hoá (live/UGC/lead magnet/Shop).";

    $usr = [
      'coachee' => [
        'id'          => (int)$coachee_id,
        'name'        => (string)($row['full_name'] ?? ''),
        'company'     => (string)($row['company_name'] ?? ''),
        'industry'    => (string)($row['company_industry'] ?? ''),
        'coach_type'  => (string)($row['coach_type'] ?? ''),
      ],
      'data' => [
        'answer_json'   => $ctx['answers'],
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
        'scripts_target' => 30,
        'owners_examples'=> ['Creator','Editor','TikTokCoach','Marketing','Sales','IT','CRM'],
        'tone'           => 'ngắn gọn, đậm tính hành động, Việt hoá',
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
    $ok_shape = (
      is_array($ai) &&
      isset($ai['phases']) && is_array($ai['phases']) && count($ai['phases'])===4 &&
      isset($ai['content_scripts']) && is_array($ai['content_scripts']) && count($ai['content_scripts'])>=10 // tối thiểu có kịch bản
    );

    if (!$ok_shape) {
      // Fallback: dùng template + seed sơ bộ kịch bản từ answers (nếu có pillars/keywords)
      $scripts = [];
      $pillars = (array)($ctx['answers']['content_pillars'] ?? $ctx['answers']['pillars'] ?? []);
      $keywords= (array)($ctx['answers']['keywords'] ?? []);
      $styles  = (array)($ctx['answers']['brandstyle'] ?? $ctx['answers']['tone'] ?? []);

      $make_script = function($title,$style) {
        return [
          'title'        => $title,
          'hook'         => 'Sự thật ít ai nói: '.$title,
          'outline'      => ['Hook','Ý chính 1','Ý chính 2','Proof/Case','CTA'],
          'style'        => $style ? (array)$style : ['authentic','authority'],
          'duration_sec' => 35,
          'cta'          => 'Comment “INFO” để nhận tài liệu chi tiết.',
          'caption'      => 'Chia sẻ góc nhìn thực chiến.',
          'hashtags'     => ['#tiktokcoach','#fyp','#kinhdoanh'],
          'assets'       => [],
          'broll'        => [],
          'reason'       => 'Phù hợp niche & pain points cơ bản khi thiếu dữ liệu AI.',
        ];
      };

      // sinh tối thiểu 12 script
      foreach (array_slice($pillars,0,4) as $p) {
        $scripts[] = $make_script("Sai lầm thường gặp về ".(string)$p, $styles);
        $scripts[] = $make_script("Checklist nhanh cho ".(string)$p,  $styles);
        $scripts[] = $make_script("Case study mini: ".(string)$p,     $styles);
      }
      if (!$scripts) {
        foreach (array_slice($keywords,0,4) as $k) {
          $scripts[] = $make_script("Bạn đã biết mẹo về ".(string)$k." chưa?", $styles);
          $scripts[] = $make_script("3 điều phải tránh khi ".(string)$k,      $styles);
          $scripts[] = $make_script("Cách nhanh nhất để bắt đầu với ".(string)$k, $styles);
        }
      }

      $ai = $template;
      $ai['trace'] = [
        'source'     => 'fallback:template+tiktok_seed',
        'coachee_id' => (int)$coachee_id,
        'raw'        => is_string($raw)? mb_substr($raw,0,1600) : '',
      ];
      $ai['content_scripts'] = $scripts;
    } else {
      // Bảo đảm các trường meta/overview/trace tồn tại
      $ai['meta']     = array_merge(['version'=>'1.0','generated_at'=>current_time('mysql'),'notes'=>[]], (array)($ai['meta'] ?? []));
      $ai['overview'] = array_merge((array)$template['overview'], (array)($ai['overview'] ?? []));
      $ai['trace']    = ['source'=>'ai:tiktok', 'coachee_id'=>(int)$coachee_id];
    }

    // Upsert DB
    return $wpdb->update($t['profiles'], ['bizcoach_json' => wp_json_encode($ai, JSON_UNESCAPED_UNICODE)], ['id'=>$coachee_id]);
  }
}
if (!function_exists('bccm_action_plan_template_90d_tiktokcoach')) {
  function bccm_action_plan_template_90d_tiktokcoach(array $ctx = []) {
    $company  = (string)($ctx['company'] ?? '');
    $industry = (string)($ctx['industry'] ?? '');
    $meta = [
      'version'      => '1.0',
      'generated_at' => current_time('mysql'),
      'notes'        => ['Template: TikTokCoach – 90 Days Transform'],
    ];
    $overview = [
      'theme'     => '90-Day TikTok Growth System',
      'objective' => 'Xây kênh TikTok từ nền tảng → tăng trưởng → thương mại hoá.',
      'output'    => 'Kênh TikTok chuẩn hoá + lịch nội dung 90 ngày + báo cáo & playbook',
      'company'   => $company,
      'industry'  => $industry,
    ];

    // ===== Phase 0 (Day 1–9): Awakening =====
    $p0 = [
      'title'   => 'Module 0 – Awakening (Day 1–9)',
      'key'     => 'p0',
      'modules' => [[
        'title'     => 'Định vị • Niche • Persona • Cốt truyện kênh',
        'day_range' => '1–9',
        'objectives'=> [
          'Khoanh vùng niche (problem → promise) & ICP.',
          'Xây Content Pillars (3–5 trụ): Hook • Teach • Proof • Offer.',
          'Define persona & brand voice (authentic + authority).',
          'Soạn 30 hooks “fighting” theo trend và nỗi đau.',
        ],
        'kpis'      => ['Hoàn tất 5 trụ nội dung', '30 hooks nháp', '3 format chủ lực', 'Bộ 10 CTA'],
        'tasks'     => [
          'Nghiên cứu top 20 video trong niche (save link, note hook/retention).',
          'Viết 30 hooks dạng câu hỏi/đối đầu/fact–shock.',
          'Chuẩn hoá brand voice (3 ví dụ good/bad).',
          'Lập content calendar nháp 4 tuần.',
        ],
        'owners'    => ['Founder','Marketing','TikTokCoach'],
        'tools'     => ['BizGPT Content','TikTok Creative Center','CapCut','Sheets Calendar'],
        'outputs'   => ['Brief kênh','30 hooks','Calendar 4w','CTA kit'],
        'context'   => $ctx,
      ]],
      'weeks'   => [
        ['title'=>'W1 – Niche & ICP','milestones'=>['Xác định niche/ICP','Pillars x5'],'kpis'=>['5 pillars','brief kênh']],
        ['title'=>'W2 – Hooks & Script','milestones'=>['30 hooks','Mẫu script 30–45s'],'kpis'=>['30 hooks','10 scripts']],
      ],
    ];

    // ===== Phase 1 (Day 10–36): Foundation =====
    $p1 = [
      'title'   => 'Giai đoạn 1 – Foundation',
      'key'     => 'p1',
      'modules' => [
        [
          'title'     => 'Module 1 (Day 10–18) – Setup sản xuất & quy trình quay/chỉnh',
          'day_range' => '10–18',
          'objectives'=> [
            'Thiết kế pipeline: Idea → Script → Shoot → Edit → Publish → Analyze.',
            'Thiết lập preset CapCut/Template: intro 0.5s • caption • subtitle.',
            'Chuẩn hoá bối cảnh quay (ánh sáng/âm thanh/bố cục).',
          ],
          'kpis'   => ['Tốc độ sản xuất < 60 phút/video','Preset hoàn chỉnh','Library B-roll 50 clips'],
          'tasks'  => [
            'Tạo 3 preset CapCut (talking head, screen demo, street voxpop).',
            'Chuẩn hoá checklist quay (light, mic, framing).',
            'Tạo Notion/Sheet pipeline & status.',
          ],
          'owners' => ['TikTokCoach','Editor','Creator'],
          'tools'  => ['CapCut','Notion/Sheets','Teleprompter','Canva'],
          'outputs'=> ['SOP sản xuất','Preset CapCut','Checklist quay'],
        ],
        [
          'title'     => 'Module 2 (Day 19–27) – Lịch đăng & tín hiệu thuật toán',
          'day_range' => '19–27',
          'objectives'=> [
            'Thiết lập cadence: 1–2 video/ngày + 3–5 comments pin.',
            'Tối ưu 3s–8s đầu (hook), retention 30%/60%/90%.',
            'Thử nghiệm giờ vàng & hashtag theo pillar.',
          ],
          'kpis'   => ['7–14 video/tuần','Avg retention ≥ 35%','3h giờ đăng hiệu quả'],
          'tasks'  => [
            'Đăng 10–14 video thử nghiệm (A/B hook).',
            'Lưu 5 hashtag buckets cho từng pillar.',
            'Pin 1 comment có CTA ở mỗi video.',
          ],
          'owners' => ['Creator','TikTokCoach'],
          'tools'  => ['TikTok Analytics','BizGPT Captioner','Hashtag buckets'],
          'outputs'=> ['Calendar 2 tuần','Report A/B hooks','Hashtag buckets'],
        ],
        [
          'title'     => 'Module 3 (Day 28–36) – Branding & Compliance',
          'day_range' => '28–36',
          'objectives'=> [
            'Thống nhất style guide (logo, lower-third, màu, font).',
            'Checklist nhạc/bản quyền, chính sách nội dung.',
            'Chuẩn hóa BIO/Link hub (mini-site, i18n nếu cần).',
          ],
          'kpis'   => ['Style guide 1.0','100% video có CTA','Bio CTR ≥ 2%'],
          'tasks'  => [
            'Tạo 3 mẫu lower-third và end-card 2s.',
            'Setup link-in-bio (Woo/Shopify/LP).',
            'Soát policy & danh sách “avoid words”.',
          ],
          'owners' => ['Designer','TikTokCoach','Legal'],
          'tools'  => ['Canva','CapCut','Woo/Shopify','BizGPT Landing'],
          'outputs'=> ['Brand kit','Link hub','Policy checklist'],
        ],
      ],
      'weeks' => [
        ['title'=>'W3 – Pipeline Ready','milestones'=>['Preset & SOP'],'kpis'=>['60 phút/video']],
        ['title'=>'W4 – Cadence & A/B','milestones'=>['10–14 video'],'kpis'=>['Retention ≥35%']],
        ['title'=>'W5 – Brand & Bio','milestones'=>['Brand kit','Link hub'],'kpis'=>['Bio CTR ≥2%']],
      ],
    ];

    // ===== Phase 2 (Day 37–63): Acceleration =====
    $p2 = [
      'title'   => 'Giai đoạn 2 – Acceleration',
      'key'     => 'p2',
      'modules' => [
        [
          'title'     => 'Module 4 (Day 37–45) – Growth Mechanics & Series',
          'day_range' => '37–45',
          'objectives'=> [
            'Tạo 2–3 series (mỗi series ≥ 7 tập).',
            'Hook patterns: controversy, myth-busting, POV, challenge.',
            'Tối ưu bình luận dẫn dắt (comment bait, reply video).',
          ],
          'kpis'   => ['2 series hoạt động','Reply video ≥ 5/tuần','Shares/Video tăng 30%'],
          'tasks'  => [
            'Danh sách 20 comment-bait theo pillar.',
            'Quay 7 tập/series • schedule.',
            'Reply bằng video cho top 5 comment mỗi ngày.',
          ],
          'owners' => ['Creator','TikTokCoach'],
          'tools'  => ['TikTok Q&A','BizGPT Script','CapCut Templates'],
          'outputs'=> ['Series Bible','Reply playbook'],
        ],
        [
          'title'     => 'Module 5 (Day 46–54) – Live & Social Commerce',
          'day_range' => '46–54',
          'objectives'=> [
            'Thiết lập lịch live 2–3 phiên/tuần (45–90 phút).',
            'Kịch bản: Hook • Demo • Social proof • CTA • Q&A.',
            'Kết nối giỏ hàng (TikTok Shop/Woo/Shopify).',
          ],
          'kpis'   => ['Live ≥ 6 phiên/3 tuần','CVR live ≥ 3%','GMV thử nghiệm'],
          'tasks'  => [
            'Chuẩn bị product sheet & ưu đãi live.',
            'Live rehearsal 2 phiên nội bộ.',
            'Setup tracking pixel & coupons.',
          ],
          'owners' => ['Host','Sales','TikTokCoach','IT'],
          'tools'  => ['TikTok Live Studio','Woo/Shopify','Coupons/Pixel'],
          'outputs'=> ['Live script','Offer kit','Pixel config'],
        ],
        [
          'title'     => 'Module 6 (Day 55–63) – Collab/KOL/UGC & Community',
          'day_range' => '55–63',
          'objectives'=> [
            'Thiết lập chương trình UGC (brief, fee, rights).',
            'Collab 3–5 creators micro-niche.',
            'Mở nhóm cộng đồng (Zalo/Telegram/Discord) chăm nuôi lead.',
          ],
          'kpis'   => ['5 video UGC','3 collab/tuần','Community 300 members'],
          'tasks'  => [
            'Soạn UGC brief & bảng giá.',
            'Tìm 20 micro-creator phù hợp.',
            'Chuẩn bị onboarding cho group cộng đồng.',
          ],
          'owners' => ['Influencer MKT','TikTokCoach','CSKH'],
          'tools'  => ['Creator Marketplace','Telegram/Zalo','Contract templates'],
          'outputs'=> ['UGC kit','Collab list','Community SOP'],
        ],
      ],
      'weeks' => [
        ['title'=>'W6 – Launch Series','milestones'=>['Series #1/#2'],'kpis'=>['Shares +30%']],
        ['title'=>'W7 – Live Engine','milestones'=>['6 phiên live'],'kpis'=>['CVR ≥3%']],
        ['title'=>'W8 – UGC/Collab','milestones'=>['5 UGC'],'kpis'=>['300 members']],
      ],
    ];

    // ===== Phase 3 (Day 64–90): Growth & Monetization =====
    $p3 = [
      'title'   => 'Giai đoạn 3 – Growth & Monetization',
      'key'     => 'p3',
      'modules' => [
        [
          'title'     => 'Module 7 (Day 64–72) – Funnel & Lead Gen',
          'day_range' => '64–72',
          'objectives'=> [
            'Thiết kế mini-funnel: Hook video → Link hub → Lead magnet → CRM.',
            'Tự động hoá follow-up (bot/flow).',
          ],
          'kpis'   => ['Lead/Video ≥ 0.5%','Email open ≥ 30%','Reply bot < 5 phút'],
          'tasks'  => [
            'Tạo 2 lead magnet (mini eBook/checklist).',
            'Thiết lập chatbot kịch bản FAQ/booking.',
            'Kết nối CRM & tag hành vi.',
          ],
          'owners' => ['Growth','CRM','TikTokCoach'],
          'tools'  => ['BizGPT Landing','CRM','Chatbot connectors'],
          'outputs'=> ['Funnel map','Bot flow','CRM tags'],
        ],
        [
          'title'     => 'Module 8 (Day 73–81) – Ads & Scaling',
          'day_range' => '73–81',
          'objectives'=> [
            'Chạy Spark Ads cho video thắng.',
            'Test 3 creative angles/nhóm.',
            'Quy tắc scale & kill nhanh.',
          ],
          'kpis'   => ['CPA trong target','ROAS ≥ 1.5 (test)','CTR ≥ 1.2%'],
          'tasks'  => [
            'Chọn 3–5 video top để Spark.',
            'Thiết lập CBO/ABO nhỏ (test).',
            'Bộ quy tắc scale/kill mỗi 24–48h.',
          ],
          'owners' => ['Ads','TikTokCoach'],
          'tools'  => ['TikTok Ads Manager','UTM/Analytics'],
          'outputs'=> ['Ads plan','Report test','Ruleset'],
        ],
        [
          'title'     => 'Module 9 (Day 82–90) – Report & Playbook hóa',
          'day_range' => '82–90',
          'objectives'=> [
            'Đối chiếu baseline ↔ kết quả 90 ngày.',
            'Chuẩn hóa Playbook (SOP + Checklist + Templates).',
            'Kế hoạch 90 ngày tiếp theo (scale/nhân bản).',
          ],
          'kpis'   => ['Báo cáo đủ 6 phần','Playbook v1.0','Roadmap Q+1'],
          'tasks'  => [
            'Tổng hợp số liệu (reach, views, retention, leads, GMV).',
            'Chốt list “video thắng” & tiêu chí chọn.',
            'Lập backlog 50 ý tưởng cho Q+1.',
          ],
          'owners' => ['TikTokCoach','Ops','Founder'],
          'tools'  => ['TikTok Analytics','Data Studio/Sheets','BizCoach Report'],
          'outputs'=> ['90-Day Report','Playbook v1.0','Roadmap Q+1'],
        ],
      ],
      'weeks' => [
        ['title'=>'W9 – Funnel Ready','milestones'=>['Bot & CRM'],'kpis'=>['Lead ≥0.5%']],
        ['title'=>'W10 – Ads Test','milestones'=>['Spark Ads','Angles'],'kpis'=>['CPA target']],
        ['title'=>'W11 – Report & SOP','milestones'=>['Report','Playbook'],'kpis'=>['Playbook v1.0']],
      ],
    ];

    return [
      'meta'            => $meta,
      'overview'        => $overview,
      'phases'          => [$p0,$p1,$p2,$p3],
      // MỚI: nơi AI đổ ~30 kịch bản theo style cá nhân
      'content_scripts' => [], // mỗi item: {title, hook, outline[], style[], duration_sec, cta, caption, hashtags[], assets[], broll[], reason}
    ];
  }
}
