<?php if (!defined('ABSPATH')) exit;

if (!function_exists('bccm_generate_baby_growth_map')) {
  function bccm_generate_baby_growth_map($coachee_id){
    global $wpdb; 
    $t   = bccm_tables();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id), ARRAY_A);
    if (!$row) {
      if (function_exists('is_wp_error')) return new WP_Error('not_found','Không tìm thấy coachee');
      return false;
    }

    $gender = $row['baby_gender'] ?? '';
    $dob    = $row['dob'] ?? null;
    $weeks  = isset($row['baby_gestational_weeks']) ? (int)$row['baby_gestational_weeks'] : null;
    $h      = isset($row['baby_height_cm']) ? $row['baby_height_cm'] : null;
    $w      = isset($row['baby_weight_kg']) ? $row['baby_weight_kg'] : null;

    // Tính toán theo WHO
    if (!function_exists('bccm_baby_calc')) {
      if (function_exists('is_wp_error')) return new WP_Error('helper_missing','Thiếu helper_baby.php (bccm_baby_calc)');
      return false;
    }
    $calc = bccm_baby_calc($gender, $dob, $weeks, $h, $w);

    // Gói dữ liệu lưu
    $payload = [
      'age'     => $calc['age'],
      'gender'  => $calc['gender'],
      'inputs'  => [
        'name'   => $row['baby_name'] ?? null,
        'weeks'  => $weeks,
        'height' => is_numeric($h) ? (float)$h : null,
        'weight' => is_numeric($w) ? (float)$w : null,
        'dob'    => $dob,
      ],
      'std'       => $calc['std'],
      'delta_pct' => $calc['delta_pct'],
      'band'      => $calc['band'],
      'charts'    => $calc['charts'],
      'ts'        => current_time('mysql'),
      'disclaimer'=> $calc['disclaimer'],
    ];

    $ok = $wpdb->update($t['profiles'], [
      'baby_json'  => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
    ], ['id' => (int)$coachee_id]);

    if ($ok === false) {
      if (function_exists('is_wp_error')) return new WP_Error('db_error','Không lưu được baby_json');
      return false;
    }
    return true;
  }
}
/**
 * Tạo Overview cho BabyCoach: gọi AI + điền fallback bắt buộc.
 * - Luôn có growth_comment.height/weight/bmi (dù thiếu dữ liệu -> "—").
 * - Luôn có parenting.* (nếu AI thiếu -> chèn mặc định "Chưa có ...").
 * - Luôn có numbers_full từ numeric_json; life_path/soul_number lấy từ đó.
 */
if (!function_exists('bccm_ai_generate_for_baby_overview')) {
  function bccm_ai_generate_for_baby_overview(array $profile, array $questions, array $answers) {

    // ---------- 0) Lấy numeric_json (bắt buộc) ----------
    $numeric = [];
    if (!empty($profile['numeric_json'])) {
      $tmp = json_decode($profile['numeric_json'], true);
      if (json_last_error()===JSON_ERROR_NONE && is_array($tmp)) $numeric = $tmp;
    }
    if (empty($numeric['numbers_full']) || !is_array($numeric['numbers_full'])) {
      return new WP_Error('missing_numeric_json', 'Chưa có bộ chỉ số Thần số học (numeric_json). Vui lòng bấm “Tạo bản đồ Thần số học” trước.');
    }
    $numbers_full = $numeric['numbers_full'];
    $life_path   = (string)($numbers_full['life_path']['value']   ?? '');
    $soul_number = (string)($numbers_full['soul_number']['value'] ?? '');

    // ---------- 1) Tính số đo hiện tại ----------
    $months = null;
    if (!empty($profile['dob'])) {
      try {
        $dob   = new DateTime($profile['dob']);
        $now   = new DateTime('now', wp_timezone());
        $diff  = $dob->diff($now);
        $months = $diff->y * 12 + $diff->m + ($diff->d >= 15 ? 1 : 0);
      } catch (\Exception $e) {}
    }
    $h_cm = is_numeric($profile['baby_height_cm'] ?? null) ? (float)$profile['baby_height_cm'] : null;
    $w_kg = is_numeric($profile['baby_weight_kg'] ?? null) ? (float)$profile['baby_weight_kg'] : null;
    $bmi  = ($h_cm && $h_cm>0 && $w_kg) ? round($w_kg / pow($h_cm/100.0, 2), 2) : null;

    // ---------- 2) Chuẩn bị prompt gọi BizGPT ----------
    $ans_lines=[]; if (!empty($answers) && is_array($answers)){
      foreach($answers as $k=>$v){ if(is_array($v)) $v=wp_json_encode($v,JSON_UNESCAPED_UNICODE); $ans_lines[]="$k: $v"; }
    }
    $start_m = (int) date_i18n('n', current_time('timestamp'));
    $start_y = (int) date_i18n('Y', current_time('timestamp'));

    $sys = "Bạn là Chuyên gia Phát triển Trẻ em & Nhân số học. 
Chỉ trả về JSON hợp lệ theo schema:
{
  \"overview\":\"...\",
  \"life_path\":\"...\",
  \"soul_number\":\"...\",
  \"numbers\": [{\"name\":\"\",\"meaning\":\"\",\"advice\":\"\"}],
  \"career\":\"\",\"mission\":\"\",
  \"lucky_colors\":[],\"jobs_recommended\":[],
  \"to_practice\":[],\"to_avoid\":[],
  \"growth_comment\": {\"height\":\"\",\"weight\":\"\",\"bmi\":\"\",\"advice\":[]},
  \"parenting\": {\"nutrition\":[],\"study\":[],\"discipline\":[],\"compensate\":[]},
  \"twelve_months\":[{\"month\":\"Tháng m/YYYY\",\"personal_number\":0,\"reaction\":\"\",\"focus\":\"\",\"notes\":\"\"}],
  \"fortune\":{\"money\":\"\",\"colleagues\":\"\",\"career\":\"\",\"luck\":\"\"}
}
Yêu cầu: dùng numbers_full (life_path/soul_number) trong numeric_json làm nguồn sự thật; 12 mục cho twelve_months, bắt đầu từ start_month/start_year; câu văn ngắn, tích cực.";

    $user = "Hồ sơ bé: ".wp_json_encode([
      'id'=>$profile['id']??null,
      'name'=>$profile['baby_name'] ?? ($profile['full_name'] ?? ''),
      'gender'=>$profile['baby_gender'] ?? ($profile['gender'] ?? ''),
      'dob'=>$profile['dob'] ?? '',
      'age_months'=>$months,
      'height_cm'=>$h_cm,
      'weight_kg'=>$w_kg,
      'bmi'=>$bmi,
    ], JSON_UNESCAPED_UNICODE)
    ."\nnumeric_json(numbers_full): ".wp_json_encode($numeric, JSON_UNESCAPED_UNICODE)
    ."\nstart: ".wp_json_encode(['start_month'=>$start_m, 'start_year'=>$start_y], JSON_UNESCAPED_UNICODE)
    ."\nquestions: ".wp_json_encode(array_values($questions), JSON_UNESCAPED_UNICODE)
    ."\nanswers:\n- ".(!empty($ans_lines)?implode("\n- ",$ans_lines):'(chưa có)');

    $message = "SYSTEM:\n{$sys}\n\nUSER:\n{$user}";

    // ---------- 3) Gọi BizGPT ----------
    $api_key = get_option('bccm_openai_api_key','');
    $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, $message, false);

    // ---------- 4) Parse lỏng ----------
    $extract_json = function($text){
      if (preg_match('/```(?:json)?\s*(.+?)\s*```/is',$text,$m)) return $m[1];
      if (preg_match('/\{(?:[^{}]|(?R))*\}/s',$text,$m)) return $m[0];
      return null;
    };
    $parsed = [];
    if (is_string($raw) && trim($raw)!==''){
      $j = json_decode($raw,true);
      if (json_last_error()!==JSON_ERROR_NONE) {
        $f = $extract_json($raw);
        if ($f) $j = json_decode($f,true);
      }
      if (json_last_error()===JSON_ERROR_NONE && is_array($j)) $parsed = $j;
    }

    // ---------- 5) Chuẩn hoá & BẮT BUỘC có đủ field ----------
    $out = array_merge([
      'overview'=>'',
      'life_path'=>'',
      'soul_number'=>'',
      'numbers'=>[],
      'career'=>'','mission'=>'',
      'lucky_colors'=>[],'jobs_recommended'=>[],
      'to_practice'=>[],'to_avoid'=>[],
      'growth_comment'=>['height'=>'','weight'=>'','bmi'=>'','advice'=>[]],
      'parenting'=>['nutrition'=>[],'study'=>[],'discipline'=>[],'compensate'=>[]],
      'twelve_months'=>[],
      'fortune'=>['money'=>'','colleagues'=>'','career'=>'','luck'=>''],
    ], is_array($parsed)?$parsed:[]);

    // Gắn numbers_full + life_path / soul_number từ numeric_json
    $out['numbers_full'] = $numbers_full;
    if (empty($out['life_path']) && $life_path!=='')   $out['life_path']   = $life_path;
    if (empty($out['soul_number']) && $soul_number!=='') $out['soul_number'] = $soul_number;

    // ====== Fallback “Nhận xét tăng trưởng hiện tại” ======
    // height
    if (!isset($out['growth_comment']) || !is_array($out['growth_comment'])) $out['growth_comment']=[];
    if (empty($out['growth_comment']['height'])) {
      $out['growth_comment']['height'] = ($h_cm!==null && $months!==null)
        ? "Chiều cao hiện tại ~ {$h_cm} cm ở khoảng {$months} tháng"
        : "—";
    }
    // weight
    if (empty($out['growth_comment']['weight'])) {
      $out['growth_comment']['weight'] = ($w_kg!==null && $months!==null)
        ? "Cân nặng hiện tại ~ {$w_kg} kg ở khoảng {$months} tháng"
        : "—";
    }
    // bmi
    if (empty($out['growth_comment']['bmi'])) {
      $out['growth_comment']['bmi'] = ($bmi!==null) ? (string)$bmi : "—";
    }
    // advice mảng
    if (empty($out['growth_comment']['advice']) || !is_array($out['growth_comment']['advice'])) {
      $out['growth_comment']['advice'] = [];
    }

    // ====== Fallback “Phương pháp đồng hành” ======
    if (!isset($out['parenting']) || !is_array($out['parenting'])) $out['parenting']=[];
    foreach (['nutrition'=>'Chưa có khuyến nghị dinh dưỡng.',
              'study'=>'Chưa có gợi ý học & chơi.',
              'discipline'=>'Chưa có gợi ý kỷ luật.',
              'compensate'=>'Chưa có khuyến nghị bù đắp.'] as $k=>$msg) {
      if (empty($out['parenting'][$k]) || !is_array($out['parenting'][$k])) {
        $out['parenting'][$k] = [$msg];
      } elseif (count(array_filter($out['parenting'][$k], 'strlen'))===0) {
        $out['parenting'][$k] = [$msg];
      }
    }

    // Bảo toàn cấu trúc arrays khác
    foreach (['numbers','jobs_recommended','lucky_colors','to_practice','to_avoid','twelve_months'] as $k) {
      if (empty($out[$k]) || !is_array($out[$k])) $out[$k] = [];
    }
    if (empty($out['fortune']) || !is_array($out['fortune'])) {
      $out['fortune'] = ['money'=>'','colleagues'=>'','career'=>'','luck'=>''];
    } else {
      $out['fortune'] = array_merge(['money'=>'','colleagues'=>'','career'=>'','luck'=>''], $out['fortune']);
    }

    return $out; // bccm_generate_baby_overview() sẽ lưu ai_summary
  }
}

/**
 * Generator lưu vào ai_summary (không đổi chữ ký).
 */
if (!function_exists('bccm_generate_baby_overview')) {
  function bccm_generate_baby_overview($coachee_id){
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

    $ai = bccm_ai_generate_for_baby_overview($profile, $questions, $answers);
    if (is_wp_error($ai)) return $ai;

    // Đưa về schema destiny thống nhất với frontend
    $destiny = bccm_ai_to_destiny( is_array($ai) ? $ai : [] );

    // Lưu
    $wpdb->update($t['profiles'], [
      'ai_summary' => bccm_safe_json($destiny),
      'updated_at' => current_time('mysql'),
    ], ['id'=>$coachee_id]);

    return true;
  }
}



/**
 * ===== BabyCoach — IQ Map from numeric_json (no AI) =====
 * - Đọc numbers_full trong numeric_json
 * - Quy về 10 chức năng tư duy (hình minh hoạ): management, logic_math, fine_motor,
 *   language, observation, leadership, imagination, gross_motor, auditory, aesthetic.
 * - Sinh profile đồng hành, phương pháp học, tiềm năng & chỉ số thấu cảm.
 * - Lưu vào cột profiles.iqmap_json (JSON, UTF-8).
 */

if (!function_exists('bccm_norm_1_9_to_10')) {
  function bccm_norm_1_9_to_10($v, $fallback = 5.5) {
    if (!is_numeric($v)) $v = $fallback;
    $v = max(1, min(9, floatval($v)));
    return round(($v / 9.0) * 10.0, 1);
  }
}

if (!function_exists('bccm_num_pick')) {
  /**
   * Lấy value từ numbers_full theo nhiều "khóa khả dĩ" (không phân biệt hoa thường).
   * Hỗ trợ cả dạng ["value"=>x] lẫn số trực tiếp.
   */
  function bccm_num_pick(array $numbers_full, array $candidates, $fallback = null) {
    $flat = [];
    foreach ($numbers_full as $k => $info) {
      $kl = strtolower((string)$k);
      $val = is_array($info) && array_key_exists('value', $info) ? $info['value'] : $info;
      $flat[$kl] = $val;
    }
    foreach ($candidates as $c) {
      $c = strtolower($c);
      if (array_key_exists($c, $flat) && is_numeric($flat[$c])) {
        return floatval($flat[$c]);
      }
      // match fuzzy
      foreach ($flat as $k=>$v) {
        if (strpos($k, $c)!==false && is_numeric($v)) return floatval($v);
      }
    }
    return $fallback;
  }
}

if (!function_exists('bccm_weighted')) {
  function bccm_weighted(array $pairs, $fallbackMid = 5.5) {
    // $pairs: [[value(1-9)|null, weight(0..1), label], ...]
    $sumW = 0; $acc = 0; $used = [];
    foreach ($pairs as $p) {
      $val = $p[0]; $w = $p[1]; $lab = $p[2] ?? '';
      if (!is_numeric($w) || $w<=0) continue;
      $sumW += $w;
      $v9 = is_numeric($val) ? floatval($val) : $fallbackMid; // vẫn dùng mid nếu thiếu
      $acc += $v9 * $w;
      if (is_numeric($val)) $used[] = $lab;
    }
    if ($sumW<=0) { $sumW = 1; $acc = $fallbackMid; }
    $v9 = $acc / $sumW;
    return [ bccm_norm_1_9_to_10($v9), $used ];
  }
}
if (!function_exists('bccm_generate_baby_iqmap')) {
  /**
   * Generate & save iqmap_json cho coachee:
   *  - Đọc numeric_json, answer_json, ai_summary
   *  - Prompt AI qua chatbot_chatgpt_call_omni_bizcoach_map()
   *  - Ép toàn bộ thang điểm về bậc 0.5 (0.0..10.0)
   *  - Lưu profiles.iqmap_json
   *
   * @param int $coachee_id
   * @return true|WP_Error
   */
  function bccm_generate_baby_iqmap($coachee_id) {
    global $wpdb; $t = bccm_tables();

    // ---------- helpers ----------
    $q05 = function($v, $min=0.0, $max=10.0) {
      if (!is_numeric($v)) return null;
      $v = floatval($v);
      $v = max($min, min($max, $v));
      // làm tròn về bậc 0.5
      return round($v * 2.0) / 2.0;
    };
    $pick_json = function($str){
      if (is_array($str)) return $str;
      if (!is_string($str) || $str==='') return [];
      // bắt code-fence trước, nếu có
      if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $str, $m)) {
        $str = $m[1];
      } else {
        // nếu không có fence, cố gắng bắt JSON khối lớn nhất
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $str, $m2)) {
          $str = $m2[0];
        }
      }
      $arr = json_decode($str, true);
      return (json_last_error()===JSON_ERROR_NONE && is_array($arr)) ? $arr : [];
    };
    $quantize_map_scores = function(array $iq){
      // 1) 10 chức năng (0..10)
      if (!empty($iq['scores']) && is_array($iq['scores'])) {
        foreach ($iq['scores'] as $k => $row) {
          if (isset($row['score'])) $iq['scores'][$k]['score'] = is_numeric($row['score']) ? round(floatval($row['score'])*2)/2 : $row['score'];
        }
      }
      // 2) empathy_index.score
      if (isset($iq['empathy_index']['score'])) {
        $iq['empathy_index']['score'] = round(floatval($iq['empathy_index']['score'])*2)/2;
      }
      // 3) basic_indexes: chuyển về 0..10 rồi mới % nếu cần (giữ nguyên nếu bạn đang dùng %)
      // -> Không động chạm vì layout của bạn đang hiển thị % nhỏ (12.5%). Bỏ qua.

      // 4) dominant_profile: không số -> bỏ qua

      // 5) learning_recos: không số -> bỏ qua

      // 6) mi_domains: nếu có score theo thang 0..5 sao, cũng ép 0.5
      if (!empty($iq['mi_domains']) && is_array($iq['mi_domains'])) {
        foreach ($iq['mi_domains'] as $k => $row) {
          if (isset($row['score'])) {
            // mi_domains thường là "sao" (0..5). Vẫn ép bậc 0.5.
            $iq['mi_domains'][$k]['score'] = round(floatval($row['score'])*2)/2;
          }
        }
      }
      return $iq;
    };

    // ---------- load profile ----------
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id),
      ARRAY_A
    );
    if (!$row) {
      return function_exists('is_wp_error')
        ? new WP_Error('profile_nf','Không tìm thấy hồ sơ coachee')
        : false;
    }

    // ---------- decode blocks ----------
    $numeric = [];
    if (!empty($row['numeric_json'])) {
      $tmp = json_decode($row['numeric_json'], true);
      if (json_last_error()===JSON_ERROR_NONE && is_array($tmp)) $numeric = $tmp;
    }
    $answers = [];
    if (!empty($row['answer_json'])) {
      $tmp = json_decode($row['answer_json'], true);
      if (json_last_error()===JSON_ERROR_NONE && is_array($tmp)) $answers = $tmp;
    }
    $ai_summary = [];
    if (!empty($row['ai_summary'])) {
      $tmp = json_decode($row['ai_summary'], true);
      if (json_last_error()===JSON_ERROR_NONE && is_array($tmp)) $ai_summary = $tmp;
      // một số nơi ai_summary có thể là chuỗi
      if (!$ai_summary && is_string($row['ai_summary'])) {
        $ai_summary = ['text' => $row['ai_summary']];
      }
    }

    if (empty($numeric['numbers_full']) || !is_array($numeric['numbers_full'])) {
      return function_exists('is_wp_error')
        ? new WP_Error('missing_numeric_json','Chưa có numbers_full trong numeric_json.')
        : false;
    }

    // ---------- build prompt ----------
    $api_key = get_option('bccm_openai_api_key','');
    // Nếu chưa có proxy/API thì fallback dùng builder local (nếu có)
    if ( !function_exists('chatbot_chatgpt_call_omni_bizcoach_map')) {
      if (function_exists('bccm_build_iqmap_from_numeric')) {
        $iqmap = bccm_build_iqmap_from_numeric($row, $numeric);
        $ok = $wpdb->update($t['profiles'], [
          'iqmap_json' => wp_json_encode($iqmap, JSON_UNESCAPED_UNICODE),
          'updated_at' => current_time('mysql'),
        ], ['id' => (int)$coachee_id]);
        if ($ok === false) return new WP_Error('db_error','Không lưu được iqmap_json (fallback)');
        return true;
      }
      return function_exists('is_wp_error') ? new WP_Error('no_api','Thiếu API hoặc function gọi AI') : false;
    }

    $schema = [
      'meta' => [
        'source' => 'numeric_json + answer_json + ai_summary',
        'version' => 'ai.1.0',
      ],
      // 10 chức năng (0..10; bậc 0.5). Mỗi mục: {score, strength}
      'scores' => [
        'management'  => ['score'=>'float(0..10, step=0.5)','strength'=>'string'],
        'logic_math'  => ['score'=>'float','strength'=>'string'],
        'fine_motor'  => ['score'=>'float','strength'=>'string'],
        'language'    => ['score'=>'float','strength'=>'string'],
        'observation' => ['score'=>'float','strength'=>'string'],
        'leadership'  => ['score'=>'float','strength'=>'string'],
        'imagination' => ['score'=>'float','strength'=>'string'],
        'gross_motor' => ['score'=>'float','strength'=>'string'],
        'auditory'    => ['score'=>'float','strength'=>'string'],
        'aesthetic'   => ['score'=>'float','strength'=>'string'],
      ],
      // empathy_index.score cũng bậc 0.5
      'empathy_index' => [
        'score' => 'float(0..10, step=0.5)',
        'boost' => ['array of short tips']
      ],
      // VAK % (0..100, 2 decimals)
      'learning_styles' => [
        'Thị giác'=>'percent','Thính giác'=>'percent','Vận động'=>'percent'
      ],
      // 10 chỉ số gọn (có thể % như mockup; nếu trả điểm 0..10 vẫn OK)
      'basic_indexes' => [
        'EQ'=>'number','IQ'=>'number','AQ'=>'number','CQ'=>'number','SQ'=>'number',
        'MQ'=>'number','BQ'=>'number','EntQ'=>'number','JQ'=>'number','PQ'=>'number'
      ],
      'dominant_profile' => [
        'hemisphere'=>'string','style'=>'string','why'=>'string','parent_actions'=>['array of short tips']
      ],
      'learning_recos' => [
        'mindmap'=>'string','tutor'=>'string','co_study_with_mom'=>'string','methods'=>['array']
      ],
      'innate_talent' => [
        'potential'=>'string','signals'=>['array'],'weakness'=>'string','what_to_do'=>['array']
      ],
      // 19 lĩnh vực: score là số sao 0..5 bậc 0.5
        'mi_domains' => [
        'music'         => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'agri_forestry' => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'construction'  => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'engineering'   => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'earth_env'     => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'life_science'  => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'medicine'      => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'education'     => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'finance'       => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'mass_media'    => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'it'            => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'literature'    => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'social_psych'  => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'math_analytic' => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'management'    => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'politics'      => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'foreign_lang'  => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'sports'        => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        'arts'          => ['label'=>'string','score'=>'float(0..5, step=0.5)'],
        ],
    ];

    $ask = "Bạn là chuyên gia giáo dục và phân tích hồ sơ trẻ em. Hãy tổng hợp BỘ CHỈ SỐ IQMAP cho một bé dựa vào dữ liệu sau đây. 
YÊU CẦU QUAN TRỌNG:
- TẤT CẢ CÁC điểm thang 0..10 phải làm tròn BẬC 0.5 (ví dụ: 4, 4.5, 5, 5.5, …, 10). 
- Điểm 'mi_domains' là số sao 0..5, cũng làm tròn BẬC 0.5. 
- Điểm phải phản ánh khác nhau theo dữ liệu; không trả về một dãy giống nhau.
- Chỉ trả về **DUY NHẤT** JSON đúng schema (không kèm lời giải thích).

<SCHEMA>
".wp_json_encode($schema, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."
</SCHEMA>

<NUMERIC_JSON>
".wp_json_encode($numeric, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."
</NUMERIC_JSON>

<ANSWER_JSON>
".wp_json_encode($answers, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."
</ANSWER_JSON>

<AI_SUMMARY>
".wp_json_encode($ai_summary, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."
</AI_SUMMARY>";

    // ---------- call AI ----------
    try {
      $raw = chatbot_chatgpt_call_omni_bizcoach_map($api_key, $ask, false, false, false, false);
    } catch (\Throwable $e) {
      $raw = '';
    }

    // ---------- parse & normalize ----------
    $iqmap = $pick_json($raw);

    // Nếu AI không trả JSON hợp lệ, dùng fallback local (nếu có)
    if (!$iqmap) {
      if (function_exists('bccm_build_iqmap_from_numeric')) {
        $iqmap = bccm_build_iqmap_from_numeric($row, $numeric);
      } else {
        return function_exists('is_wp_error') ? new WP_Error('ai_parse','Không parse được JSON từ AI') : false;
      }
    } else {
      // Ép các điểm về bậc 0.5
      $iqmap = ($quantize_map_scores)($iqmap);
    }

    // ---------- save ----------
    $ok = $wpdb->update($t['profiles'], [
      'iqmap_json' => wp_json_encode($iqmap, JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
    ], ['id' => (int)$coachee_id]);

    if ($ok === false) {
      return function_exists('is_wp_error')
        ? new WP_Error('db_error','Không lưu được iqmap_json')
        : false;
    }
    return true;
  }
}
