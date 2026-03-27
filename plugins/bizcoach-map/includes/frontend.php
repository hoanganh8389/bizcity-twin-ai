<?php if (!defined('ABSPATH')) exit;

/** 1) Rewrite rules + tags */
function bccm_add_rewrite(){
  // Khai báo tag để WP giữ biến custom
  add_rewrite_tag('%bccm_key%', '(0|1)');
  add_rewrite_tag('%bccm_public_key%', '([^&]+)');

  // Pretty URL: /coachee-map/<key>/
  add_rewrite_rule('^coachee-map/([^/]+)/?$', 'index.php?bccm_key=1&bccm_public_key=$matches[1]', 'top');

  // /coachee-map/ (không có key) -> router hiển thị lỗi
  add_rewrite_rule('^coachee-map/?$', 'index.php?bccm_key=1', 'top');
}
add_action('init','bccm_add_rewrite');

/** 2) Whitelist query vars */
function bccm_qvars($vars){
  $vars[] = 'bccm_key';
  $vars[] = 'bccm_public_key';
  $vars[] = 'key'; // tương thích kiểu cũ ?key=
  return $vars;
}
add_filter('query_vars','bccm_qvars');

/** 3) Tránh canonical redirect phá route này (dùng 2 tham số đúng chuẩn) */
add_filter('redirect_canonical', function($redirect_url, $requested){
  if (strpos($requested, '/coachee-map/') !== false) return false;           // pretty
  if (isset($_GET['bccm_key']) || isset($_GET['key']) || isset($_GET['bccm_public_key'])) return false; // query
  return $redirect_url;
}, 10, 2);


/** 4) Helper: lấy public key từ request (có fallback từ REQUEST_URI) */
function bccm_get_public_key_from_request(){
  $key = get_query_var('bccm_public_key');
  if (empty($key)) $key = get_query_var('key');
  if (empty($key) && isset($_GET['bccm_public_key'])) $key = sanitize_text_field($_GET['bccm_public_key']);
  if (empty($key) && isset($_GET['key']))             $key = sanitize_text_field($_GET['key']);

  // Fallback cuối: bóc từ path /coachee-map/<key>[/...]
  if (empty($key) && !empty($_SERVER['REQUEST_URI'])) {
    $req = $_SERVER['REQUEST_URI'];
    if (preg_match('#/coachee-map/([^/?#]+)/?#', $req, $m)) {
      $key = sanitize_text_field($m[1]);
    }
  }
  return $key;
}

/** 5) Router: template_redirect */
function bccm_template_redirect(){
  $is_route = get_query_var('bccm_key') || isset($_GET['bccm_key']) || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/coachee-map/') !== false);
  if ($is_route) {
    $key = bccm_get_public_key_from_request();
    nocache_headers();
    if ($key) { status_header(200); bccm_render_public_map($key); exit; }
    status_header(400); echo '<div class="bccm-public"><h1>Missing key</h1></div>'; exit;
  }
}
add_action('template_redirect','bccm_template_redirect');

/** 6) Router #2: parse_request (đặt ưu tiên 0 để bắt sớm nếu theme/plugin can thiệp) */
function bccm_parse_request_router($wp){
  if ( get_query_var('bccm_key') || isset($_GET['bccm_key']) ) {
    $key = bccm_get_public_key_from_request();
    nocache_headers();
    if ($key) { status_header(200); bccm_render_public_map($key); exit; }
    status_header(400); echo '<div class="bccm-public"><h1>Missing key</h1></div>'; exit;
  }
}
add_action('parse_request','bccm_parse_request_router', 0);


/* === 21 keys & titles (map sang tiêu đề hiển thị) === */

function bccm_metric_titles(){
  return [
    'life_path'            => 'ĐƯỜNG ĐỜI',
    'balance'              => 'CÂN BẰNG',
    'mission'              => 'SỨ MỆNH',
    'link_life_mission'    => 'LIÊN KẾT ĐƯỜNG ĐỜI – SỨ MỆNH',
    'soul_number'          => 'LINH HỒN',
    'birth_day'            => 'NGÀY SINH',
    'personality'          => 'NHÂN CÁCH',
    'link_soul_personality'=> 'LIÊN KẾT LINH HỒN – NHÂN CÁCH',
    'maturity'             => 'TRƯỞNG THÀNH',
    'attitude'             => 'THÁI ĐỘ',
    'missing'              => 'THIẾU',
    'lesson'               => 'BÀI HỌC',
    'logic'                => 'TƯ DUY LÝ TRÍ',
    'subconscious_power'   => 'SỨC MẠNH TIỀM THỨC',
    'passion'              => 'ĐAM MÊ',
    'personal_year'        => 'NĂM CÁ NHÂN',
    'personal_month'       => 'THÁNG CÁ NHÂN',
    'pinnacle'             => 'CHẶNG',
    'personal_day'         => 'NGÀY CÁ NHÂN',
    'generation'           => 'THẾ HỆ',
    'challenges'           => 'THÁCH THỨC',
  ];
}

/* === Lấy 21 chỉ số từ DB (wp_bccm_metrics) === */
function bccm_get_metrics_numbers_full($coachee_id){
  global $wpdb;
  $row = $wpdb->get_row(
    $wpdb->prepare("SELECT numbers_full FROM {$wpdb->prefix}bccm_metrics WHERE coachee_id=%d", $coachee_id),
    ARRAY_A
  );
  $data = [];
  if ($row && !empty($row['numbers_full'])){
    $tmp = json_decode($row['numbers_full'], true);
    if (is_array($tmp)) $data = $tmp;
  }
  // bảo đảm đủ key & có cấu trúc {value,brief}
  $keys = bccm_metric_21_keys();
  foreach ($keys as $k){
    if (empty($data[$k]) || !is_array($data[$k])){
      $data[$k] = ['value'=>'—','brief'=>'—'];
    } else {
      if (!isset($data[$k]['value']) || $data[$k]['value']==='') $data[$k]['value'] = '—';
      if (!isset($data[$k]['brief']) || $data[$k]['brief']==='') $data[$k]['brief'] = '—';
    }
  }
  return $data;
}


/** 7) Shortcode fallback: [bccm_map key="..."] */
add_shortcode('bccm_map', function($atts){
  $atts = shortcode_atts(['key'=>''], $atts);
  ob_start(); bccm_render_public_map( sanitize_text_field($atts['key']) ); return ob_get_clean();
});

/** 8) Helper tạo URL public (dùng lúc generate) */
function bccm_public_url($public_key){
  return home_url( '/coachee-map/' . rawurlencode($public_key) . '/' );
}

function bccm_render_fallback_destiny($profile){
  $life = function_exists('bccm_numerology_life_path')   ? (string)bccm_numerology_life_path($profile['dob'] ?? '') : '';
  $soul = function_exists('bccm_numerology_name_number') ? (string)bccm_numerology_name_number($profile['full_name'] ?? '') : '';
  $mk = function($v,$b,$m){ return ['value'=>($v!==''?$v:'—'),'brief'=>$b,'meaning'=>$m]; };
  return [
    'overview'    => 'Tổng quan sẽ được coach bổ sung.',
    'life_path'   => $life!=='' ? $life : '—',
    'soul_number' => $soul!=='' ? $soul : '—',
    'numbers_full'=> [
      'life_path'             => $mk($life,'Hướng đời, bài học','Định hướng xuyên suốt cuộc đời.'),
      'balance'               => $mk('—','Cân bằng nội–ngoại','Cách cân đối cảm xúc & hành động.'),
      'mission'               => $mk('—','Giá trị cốt lõi','Giá trị mang lại cho người khác.'),
      'link_life_mission'     => $mk('—','Liên kết mục tiêu','Hòa hợp giữa đường đời & sứ mệnh.'),
      'soul_number'           => $mk($soul,'Động lực nội tại','Điều khiến bạn thấy hứng thú.'),
      'birth_day'             => $mk('—','Khí chất bẩm sinh','Ưu điểm nổi bật theo ngày sinh.'),
      'personality'           => $mk('—','Thể hiện ra ngoài','Cách bạn xuất hiện với người khác.'),
      'link_soul_personality' => $mk('—','Trong–ngoài hài hòa','Đồng nhất giữa cảm xúc & biểu hiện.'),
      'maturity'              => $mk('—','Độ chín, tầm nhìn','Mục tiêu dài hạn & giai đoạn chín muồi.'),
      'attitude'              => $mk('—','Thái độ hằng ngày','Góc nhìn & phản ứng thường ngày.'),
      'missing'               => $mk('—','Điểm cần bù đắp','Kỹ năng/yếu tố nên rèn luyện thêm.'),
      'lesson'                => $mk('—','Bài học lớn','Chủ đề cần hoàn thiện dần.'),
      'logic'                 => $mk('—','Tư duy lý trí','Cách phân tích & ra quyết định.'),
      'subconscious_power'    => $mk('—','Sức mạnh tiềm thức','Nguồn lực vô thức hỗ trợ.'),
      'passion'               => $mk('—','Nguồn lửa sáng tạo','Điều tiếp thêm nhiệt huyết.'),
      'personal_year'         => $mk('—','Chu kỳ theo năm','Gợi ý chiến lược của năm.'),
      'personal_month'        => $mk('—','Trọng tâm tháng','Ưu tiên theo từng tháng.'),
      'pinnacle'              => $mk('—','Các chặng lớn','Giai đoạn bứt phá/biến chuyển.'),
      'personal_day'          => $mk('—','Nhịp điệu ngày','Khung giờ & nhịp làm việc hợp.'),
      'generation'            => $mk('—','Ảnh hưởng thế hệ','Bối cảnh xã hội tác động.'),
      'challenges'            => $mk('—','Thử thách trưởng thành','Các “bài test” giúp nâng cấp.'),
    ],
    'fortune' => ['money'=>'','colleagues'=>'','career'=>'','luck'=>''],
    'jobs_recommended'=>[], 'lucky_colors'=>[], 'to_practice'=>[], 'to_avoid'=>[], 'twelve_months'=>[]
  ];
}

// merge destiny: prefer data -> fallback
function bccm_render_merge_destiny($base, $add){
  if (!is_array($base)) $base = [];
  if (!is_array($add))  $add  = [];
  $out = array_replace_recursive($add, $base); // base (AI) ghi đè fallback
  // đảm bảo có numbers_full đủ 21 keys
  if (empty($out['numbers_full']) || !is_array($out['numbers_full'])) $out['numbers_full'] = $add['numbers_full'];
  else {
    foreach ($add['numbers_full'] as $k=>$v){
      if (empty($out['numbers_full'][$k])) $out['numbers_full'][$k] = $v;
    }
  }
  return $out;
}

// đảm bảo mỗi phase có đúng 4 tuần, không rỗng
function bccm_render_fill_weeks($weeks){
  $clean = [];
  for ($i=0; $i<4; $i++){
    $w = $weeks[$i] ?? [];
    $clean[] = [
      'title' => isset($w['title']) && $w['title']!=='' ? $w['title'] : ('Tuần '.($i+1)),
      'goal'  => $w['goal']  ?? '—',
      'focus' => $w['focus'] ?? '—',
      'kpis'  => is_array($w['kpis'] ?? null)  ? $w['kpis']  : [],
      'tasks' => is_array($w['tasks'] ?? null) ? $w['tasks'] : [],
      'notes' => $w['notes'] ?? '',
    ];
  }
  return $clean;
}
function bccm_render_normalize_weeks($phases){
  foreach ($phases as &$p){
    $p['weeks'] = bccm_render_fill_weeks(is_array($p['weeks'] ?? null) ? $p['weeks'] : []);
  }
  return $phases;
}

/** 9) Renderer */
/** 9) Renderer */
function bccm_render_public_map($key){
  global $wpdb;
  $t = bccm_tables();

  // nhận key từ tham số hoặc từ query var (giữ tùy chọn)
  $key = sanitize_text_field($key ?: ($_GET['bccm_public_key'] ?? ''));
  if (empty($key)) {
    status_header(404);
    echo '<h2>Không tìm thấy bản đồ.</h2>';
    return;
  }

  // LEFT JOIN plans -> profiles (coachees) để lấy coach_type + dữ liệu hồ sơ
  $sql = "
    SELECT p.*, c.*
    FROM {$t['plans']} p
    LEFT JOIN {$t['profiles']} c ON c.id = p.coachee_id
    WHERE p.public_key = %s AND p.status = 'active'
    LIMIT 1
  ";
  $row = $wpdb->get_row($wpdb->prepare($sql, $key), ARRAY_A);

  if (!$row) {
    status_header(404);
    echo '<h2>Không tìm thấy bản đồ.</h2>';
    return;
  }

  // Lấy coach_type từ hồ sơ (profiles/coachees)
  $coach_type = !empty($row['coach_type']) ? $row['coach_type'] : bccm_default_coach_type();

  // Chọn template theo registry (helpers) và fallback về BizCoach nếu thiếu
  $template_path = bccm_coach_template_for($coach_type);
  if (!file_exists($template_path)) {
    $template_path = bccm_coach_template_for('bizcoach');
  }

  // Biến cho template
  // $coachee sẽ chứa cả cột từ plans (p.*) lẫn từ profiles (c.*)
  $coachee = $row;

  // Tiện ích: nếu plan/summary là JSON thì giải sẵn (không bắt buộc)
  if (!empty($coachee['plan'])) {
    $plan_dec = json_decode($coachee['plan'], true);
    if (is_array($plan_dec)) $coachee['plan_data'] = $plan_dec;
  }
  if (!empty($coachee['ai_summary'])) {
    $sum_dec = json_decode($coachee['ai_summary'], true);
    if (is_array($sum_dec)) $coachee['ai_summary_arr'] = $sum_dec;
  }

  // ====== UI: thanh nút In / PDF (áp dụng cho TẤT CẢ template) ======
  $name_for_file = $row['baby_name'] ?? ($row['full_name'] ?? 'map');
  if (function_exists('remove_accents')) $name_for_file = remove_accents($name_for_file);
  $name_for_file = preg_replace('/[^A-Za-z0-9_\-]+/','-', strtolower($name_for_file));
  $pdf_filename  = 'BizCoach-Map-'.$name_for_file.'-'.date('Ymd').'.pdf';

  // Style & thanh nút (fixed góc phải trên cùng, ẩn khi in)
  ?>
  <style>
    .bccm-printbar{
      position:fixed; top:12px; right:12px; z-index:9999;
      display:flex; gap:8px; align-items:center;
      background:rgba(255,255,255,.9); backdrop-filter:blur(6px);
      border:1px solid #e5e7eb; border-radius:12px; padding:6px 8px;
      box-shadow:0 8px 24px rgba(2,6,23,.12);
      font-family:Inter,system-ui,Arial,sans-serif;
    }
    .bccm-btn{
      appearance:none; border:1px solid #dbe1ea; border-radius:10px;
      padding:6px 10px; font-size:13px; font-weight:700; cursor:pointer;
      background:#0ea5e9; color:#fff;
    }
    .bccm-btn.secondary{ background:#10b981; }
    .bccm-btn:hover{ filter:brightness(0.95); }
    @media print {
      .bccm-printbar{ display:none !important; }
      html, body { background:#fff; }
    }
    /* Giới hạn vùng in/PDF: lấy toàn bộ nội dung map */
    #bccm-public-map-root{ max-width:1100px; margin:0 auto; }
  </style>

  <div class="bccm-printbar" role="region" aria-label="Công cụ xuất bản đồ">
    <button class="bccm-btn" onclick="window.print()">🖨️ In</button>
    <button class="bccm-btn secondary" id="bccm-dl-pdf">⬇️ Tải PDF</button>
  </div>

  <!-- Thư viện html2pdf cho nút PDF (đã bỏ integrity, có fallback) -->
<script>
(function(){
  // nạp 1 lần duy nhất + fallback
  function ensureHtml2Pdf(cb){
    if (window.html2pdf) return cb();

    var tried = 0;
    var sources = [
      'https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js',
      'https://unpkg.com/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js'
    ];

    function loadNext(){
      if (tried >= sources.length) {
        console.error('[BizCoach] Không tải được html2pdf từ các CDN.');
        alert('Không tải được thư viện xuất PDF. Vui lòng thử lại hoặc dùng nút In.');
        return;
      }
      var s = document.createElement('script');
      s.src = sources[tried++];
      s.referrerPolicy = 'no-referrer';
      s.async = true;
      s.onload = cb;
      s.onerror = loadNext; // thử CDN tiếp theo
      document.head.appendChild(s);
    }
    loadNext();
  }

  function downloadPDF(){
    var root = document.getElementById('bccm-public-map-root') || document.body;
    var opt = {
      margin:       [0, 0, 0, 0],              // inch
      filename:     <?php echo json_encode($pdf_filename); ?>,
      image:        { type:'jpeg', quality:0.98 },
      html2canvas:  { scale:2, useCORS:true, scrollY:0, logging:false },
      jsPDF:        { unit:'in', format:'a4', orientation:'portrait' }
    };
    var prevOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    html2pdf().set(opt).from(root).save().finally(function(){
      document.documentElement.style.overflow = prevOverflow || '';
    });
  }

  document.addEventListener('click', function(e){
    if (e.target && e.target.id === 'bccm-dl-pdf') {
      ensureHtml2Pdf(downloadPDF);
    }
  }, false);
})();
</script>



  <!-- Bọc toàn bộ template trong root để chụp PDF gọn gàng -->
  <div id="bccm-public-map-root">
  <?php
  // ====== Render template chuyên biệt ======
  include $template_path;
  ?>
  </div>
  <?php
}
