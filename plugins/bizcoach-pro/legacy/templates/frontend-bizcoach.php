<?php if (!defined('ABSPATH')) exit; 


  global $wpdb; $t=bccm_tables();

  $planRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['plans']} WHERE public_key=%s AND status='active'", $key), ARRAY_A);
  if(!$planRow){ status_header(404); echo '<div class="bccm-public"><h1>Not found</h1></div>'; return; }

  $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $planRow['coachee_id']), ARRAY_A);

  // 1) Lấy plan, hỗ trợ cả 2 dạng: {title,phases} hoặc {plan:{title,phases}, destiny:{...}}
  $planJson = json_decode($planRow['plan'], true);
  $planData = $planJson;
  if (isset($planJson['plan']['phases'])) $planData = $planJson['plan']; // AI nhúng dưới "plan"

  $phases = isset($planData['phases']) && is_array($planData['phases']) ? $planData['phases'] : [];
  $phases = bccm_render_normalize_weeks($phases);

  // 2) Lấy destiny: ưu tiên ai_summary -> trong plan -> fallback
  $destiny = !empty($profile['ai_summary']) ? json_decode($profile['ai_summary'], true) : [];
  if (empty($destiny) && isset($planJson['destiny']) && is_array($planJson['destiny'])) {
    $destiny = $planJson['destiny'];
  }
  $fallback = bccm_render_fallback_destiny($profile);
  $destiny  = bccm_render_merge_destiny($destiny, $fallback);
   // === ƯU TIÊN nạp 21 chỉ số từ bảng bccm_metrics ===
  // === ƯU TIÊN nạp 21 chỉ số từ bảng bccm_metrics ===
  $numbers_full_db = bccm_get_metrics_numbers_full( intval($planRow['coachee_id']) ); // <-- FIX
  if (!empty($numbers_full_db)) {
    $destiny['numbers_full'] = $numbers_full_db;
    // Đồng bộ 2 chỉ số nền tảng
    if (isset($numbers_full_db['life_path']['value']))   $destiny['life_path']   = (string)$numbers_full_db['life_path']['value'];
    if (isset($numbers_full_db['soul_number']['value'])) $destiny['soul_number'] = (string)$numbers_full_db['soul_number']['value'];
  }
  // 3) Nhãn coach
  $types = bccm_coach_types();
  $coach_label = $types[$profile['coach_type']] ?? $profile['coach_type'];

  // CSS đẹp (fallback inline; nếu bạn có assets/public.css thì có thể chuyển qua file)
  ?>
  <style>
:root{
  --bc-blue: #0052CC;     /* Xanh BizCoach */
  --bc-red:  #FF2D2D;     /* Đỏ BizCoach */
  --bc-dark: #0e172a;
  --bc-gray: #6b7280;
  --bc-light: #ffffff;
  --bc-radius: 20px;

  --bc-shadow-strong: 0 12px 28px rgba(0,0,0,.25);
  --bc-glow-blue: 0 0 18px rgba(0,82,204,.6);
  --bc-glow-red:  0 0 18px rgba(255,45,45,.6);
}

/* Background tổng thể */
body {
  margin:0!important; padding:0!important;
  background: linear-gradient(135deg,#fefefe 0%,#f8faff 40%,#fff1f1 100%);
  font-family: Inter,system-ui,Arial,sans-serif;
  color: var(--bc-dark);
}

/* Container */
.bccm-container { max-width:1100px; margin:0 auto; padding:28px 20px; }

/* Hero Title */
.bccm-hero .title {
  font-size:32px; font-weight:900; letter-spacing:.5px;
  background: linear-gradient(90deg,var(--bc-blue),var(--bc-red));
  -webkit-background-clip:text; color:transparent;
}

/* Card */
.bccm-card {
  border-radius: var(--bc-radius);
  padding:22px 20px;
  margin:18px 0;
  background: linear-gradient(180deg,#ffffff 0%,#f9fbff 50%,#ffffff 100%);
  box-shadow: var(--bc-shadow-strong);
  border:1px solid #e3e7ef;
  position: relative;
}
.bccm-card:hover{
  transform: translateY(-4px);
  transition: .25s ease;
  box-shadow: var(--bc-shadow-strong), var(--bc-glow-blue);
}

/* Headings */
.bccm-h2 { font-size:22px; font-weight:800; margin:0 0 12px; color:var(--bc-blue); }
.bccm-h3 { font-size:17px; font-weight:700; margin:12px 0 8px; color:var(--bc-dark); }

/* Tag */
.bccm-tag {
  display:inline-block; padding:4px 12px; 
  margin:0 6px 6px 0;
  border-radius:999px; font-size:12px; font-weight:700;
  background: linear-gradient(90deg,var(--bc-blue),var(--bc-red));
  color:#fff; box-shadow: var(--bc-glow-blue);
}

/* Pill */
.pill {
  display:inline-block; padding:6px 14px; margin:6px 6px 0 0;
  border-radius:999px; font-weight:600; color:#fff;
  background: var(--bc-blue);
  box-shadow: var(--bc-glow-blue);
}
.pill--red { background: var(--bc-red); box-shadow: var(--bc-glow-red); }

/* Metric Tiles */
.tile-xl {
  border-radius:20px; min-height:160px;
  padding:20px 16px; text-align:center;
  font-weight:600; color:#fff;
  box-shadow: var(--bc-shadow-strong);
}
.tile-xl.cream {
  background: linear-gradient(135deg,var(--bc-blue) 0%,#1d4ed8 100%);
  box-shadow: var(--bc-shadow-strong), var(--bc-glow-blue);
}
.tile-xl.indigo {
  background: linear-gradient(135deg,var(--bc-red) 0%,#b91c1c 100%);
  box-shadow: var(--bc-shadow-strong), var(--bc-glow-red);
}
.tile-num { font-size:34px; font-weight:900; margin-bottom:8px; }
.tile-title { font-size:15px; font-weight:800; text-transform:uppercase; }
.tile-desc { font-size:13px; opacity:.9; }

/* Timeline */
.bccm-timeline::before {
  background: linear-gradient(var(--bc-blue),var(--bc-red));
}
.bccm-tl-item::before {
  background: var(--bc-red);
  box-shadow:0 0 0 4px rgba(255,45,45,.3);
}
.bccm-tl-badge {
  background: var(--bc-blue); color:#fff; font-weight:600;
  border:none; box-shadow: var(--bc-glow-blue);
}

/* Grid responsive */
.bccm-grid{ display:grid; grid-template-columns:1fr 1fr; gap:18px }
@media(max-width:860px){ .bccm-grid{ grid-template-columns:1fr } }
</style>
<style>
/* ==== FIX: Metrics 3 columns (desktop), 2 (tablet), 1 (mobile) ==== */
.bccm-card.metrics-onecard .metrics-grid-3{
  display:grid !important;
  grid-template-columns: repeat(3, minmax(0,1fr)) !important;
  gap:16px !important;
}

/* Chống CSS bên ngoài ép phần tử con span full-width hay bắt grid-column */
.bccm-card.metrics-onecard .metrics-grid-3 > *{
  grid-column: auto !important;
  width: 100% !important;
  margin: 0 !important;
}

/* Tablet */
@media (max-width: 1024px){
  .bccm-card.metrics-onecard .metrics-grid-3{
    grid-template-columns: repeat(2, minmax(0,1fr)) !important;
  }
}

/* Mobile */
@media (max-width: 640px){
  .bccm-card.metrics-onecard .metrics-grid-3{
    grid-template-columns: 1fr !important;
  }
}

/* Đảm bảo tile không tự giãn full hàng */
.bccm-card.metrics-onecard .tile-xl{
  display:block;
  border-radius:20px;
  box-sizing:border-box;
}

/* Nếu có CSS nào set .tile-xl { grid-column: 1 / -1 } thì reset: */
.bccm-card.metrics-onecard .tile-xl[class]{
  grid-column: auto !important;
}
</style>

  <div class="bccm-wrap"><div class="bccm-container">
    <div class="bccm-hero">
      <div>
        <div class="title">90 Days of Transformation – <?php echo esc_html($profile['full_name'] ?? ''); ?></div>
        <div class="muted small"><?php echo esc_html($coach_label); ?> • Public Key: <span class="acc"><?php echo esc_html($key); ?></span></div>
      </div>
    </div>

    <!-- Phần 1: Về Coachee -->
    <div class="bccm-card">
      <div class="bccm-h2">Phần 1 — Về Coachee</div>
      <div class="bccm-grid">

        <div>
          <div class="bccm-h3">1) Tổng quan</div>
          <p class="muted"><?php echo esc_html($destiny['overview'] ?? ''); ?></p>

          <div class="bccm-h3">2) Đường đời & Linh hồn</div>
          <div class="bccm-kv">
            <div><span class="bccm-tag">Life path: <?php echo esc_html($destiny['life_path'] ?? ''); ?></span></div>
            <div><span class="bccm-tag">Soul number: <?php echo esc_html($destiny['soul_number'] ?? ''); ?></span></div>
          </div>

          <div class="bccm-h3">3) Các con số & ý nghĩa</div>
          <ul class="bccm-list">
            <?php foreach ((array)($destiny['numbers'] ?? []) as $n): ?>
              <li><strong><?php echo esc_html($n['name'] ?? ''); ?>:</strong> <?php echo esc_html($n['meaning'] ?? ''); ?> — <em class="muted"><?php echo esc_html($n['advice'] ?? ''); ?></em></li>
            <?php endforeach; ?>
          </ul>

          <div class="bccm-h3">6) Màu may mắn</div>
          <div>
            <?php foreach ((array)($destiny['lucky_colors'] ?? []) as $c): ?>
              <span class="pill"><?php echo esc_html($c); ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <div class="bccm-h3">4) Sự nghiệp • Sứ mệnh</div>
          <p><strong>Sự nghiệp:</strong> <?php echo esc_html($destiny['career'] ?? ''); ?></p>
          <p><strong>Sứ mệnh:</strong> <?php echo esc_html($destiny['mission'] ?? ''); ?></p>

          <div class="bccm-h3">5) Nghề/định hướng gợi ý</div>
          <div><?php foreach ((array)($destiny['jobs_recommended'] ?? []) as $j) echo '<span class="pill">'.esc_html($j).'</span> '; ?></div>

          <div class="bccm-h3">7) Cần rèn luyện</div>
          <ul class="bccm-list"><?php foreach ((array)($destiny['to_practice'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

          <div class="bccm-h3">8) Cần tránh</div>
          <ul class="bccm-list"><?php foreach ((array)($destiny['to_avoid'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
        </div>
      </div>
      
      <div class="bccm-grid">
        <!--
        <div>
          <div class="bccm-h3">9) Lộ trình 12 tháng sắp tới</div>
          <ul class="bccm-list">
            <?php foreach ((array)($destiny['twelve_months'] ?? []) as $m): ?>
              <li><strong><?php echo esc_html($m['month'] ?? ''); ?>:</strong> <?php echo esc_html($m['focus'] ?? ''); ?> <span class="muted">— <?php echo esc_html($m['notes'] ?? ''); ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>-->
        <div>
          <div class="bccm-h3">9) Tài lộc & sự nghiệp thời gian tới</div>
          <ul class="bccm-list">
            <li><strong>Tiền bạc:</strong> <?php echo esc_html($destiny['fortune']['money'] ?? ''); ?></li>
            <li><strong>Đồng nghiệp:</strong> <?php echo esc_html($destiny['fortune']['colleagues'] ?? ''); ?></li>
            <li><strong>Sự nghiệp:</strong> <?php echo esc_html($destiny['fortune']['career'] ?? ''); ?></li>
            <li><strong>May mắn:</strong> <?php echo esc_html($destiny['fortune']['luck'] ?? ''); ?></li>
          </ul>
        </div>
      </div>
    </div>
    <!-- Phần 2: Lộ trình 3 tháng -->
    <style>
  /* ===== Timeline (12 tháng) ===== */
  .bccm-timeline { position: relative; margin: 16px 0 8px 0; padding-left: 18px; }
  .bccm-timeline::before { content: ""; position: absolute; left: 8px; top: 0; bottom: 0; width: 2px; background: rgba(255,255,255,0.12); }
  .bccm-tl-item { position: relative; margin: 0 0 14px 0; padding-left: 12px; }
  .bccm-tl-item::before { content: ""; position: absolute; left: -2px; top: 6px; width: 10px; height: 10px; border-radius: 50%; background: rgba(255,255,255,0.9); box-shadow: 0 0 0 3px rgba(255,255,255,0.15); }
  .bccm-tl-row { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: baseline; }
  .bccm-tl-month { font-weight: 700; }
  .bccm-tl-badge { display:inline-block; padding:2px 8px; border-radius:999px; background:rgba(255,255,255,0.08); font-size:12px; }
  .bccm-tl-reaction { font-style: italic; opacity:.85; }
  @media (min-width: 900px){
    .bccm-tl-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 8px 32px; }
  }
  </style>



  <div class="bccm-card">
    <div class="bccm-h2">Lộ trình 12 tháng sắp tới</div>
    <p class="muted"><strong>Số tháng cá nhân</strong> và gợi ý phản ứng theo con số.</p>

    <?php $months = (array)($destiny['twelve_months'] ?? []); ?>
    <?php if (!$months): ?>
      <p><em>Chưa có dữ liệu.</em></p>
    <?php else: ?>
      <div class="bccm-timeline bccm-tl-grid">
        <?php foreach ($months as $m): ?>
          <div class="bccm-tl-item">
            <div class="bccm-tl-row">
              <span class="bccm-tl-month"><?php echo esc_html($m['month'] ?? ''); ?></span>
              <?php if(isset($m['number'])): ?>
                <span class="bccm-tl-badge">Số tháng cá nhân: <?php echo esc_html($m['number']); ?></span>
              <?php endif; ?>
            </div>
            <?php if(!empty($m['reaction'])): ?>
              <div class="bccm-tl-reaction"><?php echo esc_html($m['reaction']); ?></div>
            <?php endif; ?>
            <?php if(!empty($m['focus'])): ?>
              <div><strong>Trọng tâm:</strong> <?php echo esc_html($m['focus']); ?></div>
            <?php endif; ?>
            <?php if(!empty($m['notes'])): ?>
              <div class="muted"><strong>Ghi chú:</strong> <?php echo esc_html($m['notes']); ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

    <?php
    // Chuẩn hoá bộ chỉ số hiển thị dạng card
    $cards = array();
    // Ưu tiên từ destiny['numbers_full'] nếu AI đã trả
    if (!empty($destiny['numbers_full']) && is_array($destiny['numbers_full'])) {
    foreach ($destiny['numbers_full'] as $label=>$info){
        $cards[] = array(
        'title' => $label,
        'text'  => is_array($info) ? ($info['meaning'] ?? '') : (string)$info
        );
    }
    } else {
    // fallback từ destiny['numbers'] (name/meaning)
    foreach ((array)($destiny['numbers'] ?? array()) as $n){
        $cards[] = array('title'=>$n['name'] ?? '', 'text'=>$n['meaning'] ?? '');
    }
    // Bổ sung tối thiểu: Đường đời / Linh hồn
    if (!empty($destiny['life_path'])) {
        $cards[] = array('title'=>'Đường đời', 'text'=>'Chỉ số cốt lõi: '.$destiny['life_path']);
    }
    if (!empty($destiny['soul_number'])) {
        $cards[] = array('title'=>'Linh hồn', 'text'=>'Động lực nội tại: '.$destiny['soul_number']);
    }
    }
    ?>
        <div class="page-break"></div>
    <div class="bccm-card metrics-onecard">
      <div class="bccm-h2">TỔNG HỢP CHỈ SỐ</div>
      <p class="metrics-sub muted small">Tóm tắt 21 chỉ số: <em>Value</em> &nbsp;•&nbsp; <em>Lời khuyên ngắn</em>.</p>
      <?php
        $titles = bccm_metric_titles();
        $numbers_full = (array)($destiny['numbers_full'] ?? []);
        // 12 ô "kem" đầu + 9 ô "indigo" sau (giống bố cục bạn muốn)
        $keys = bccm_metric_21_keys();
      ?>
      <div class="metrics-grid-3">
        <?php foreach ($keys as $idx=>$key):
          $tileClass = ($idx < 12) ? 'cream' : 'indigo';
          $val   = isset($numbers_full[$key]['value']) ? $numbers_full[$key]['value'] : '—';
          $brief = isset($numbers_full[$key]['brief']) ? $numbers_full[$key]['brief'] : '—';
          $title = $titles[$key] ?? strtoupper($key);
        ?>
          <div class="tile-xl <?php echo esc_attr($tileClass); ?>">
            <div class="tile-num"><?php echo esc_html($val); ?></div>
            <div class="tile-title"><?php echo esc_html($title); ?></div>
            <div class="tile-desc"><?php echo esc_html($brief); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>



    
    <style>@media print {.page-break{page-break-before:always}}</style>
    <div class="page-break"></div>
        <div class="bccm-card">
        <div class="bccm-h2">Lý giải các chỉ số</div>
        <p class="muted small">Bảng sau tóm tắt ý nghĩa thường gặp của một số nhóm chỉ số. Coach có thể tuỳ chỉnh template trong Admin.</p>
        <ul class="bccm-list">
            <li><strong>Đường đời:</strong> Xu hướng lớn của cuộc đời, bài học trọng tâm.</li>
            <li><strong>Linh hồn:</strong> Động lực bên trong, điều khiến bạn thấy hứng thú và trọn vẹn.</li>
            <li><strong>Sứ mệnh:</strong> Giá trị bạn trao cho người khác / xã hội khi sống đúng với bản chất.</li>
            <li><strong>Năm cá nhân / Tháng cá nhân:</strong> Chu kỳ biến động theo năm/tháng, gợi ý chiến lược phù hợp.</li>
            <li><strong>Thách thức:</strong> Những bài kiểm tra giúp trưởng thành; nhìn như “trở ngại” nhưng là “đòn bẩy”.</li>
            <li><strong>KPI & Tasks:</strong> KPI định lượng; Tasks là công việc then chốt giúp hiện thực hoá mục tiêu.</li>
        </ul>
    </div>

    <?php
// ==== Lấy leadership từ iqmap_json (hoặc fallback tính nhanh) ====
$iqmap_all = json_decode($profile['iqmap_json'] ?? '', true);
if (!is_array($iqmap_all)) $iqmap_all = [];
$lead = $iqmap_all['leadership'] ?? (function_exists('bccm_build_leadership_iqmap_payload') ? bccm_build_leadership_iqmap_payload($profile) : null);

$idx_items    = (array)($lead['leadership_10']['items'] ?? []);
$skill_items  = (array)($lead['skills_8']['items'] ?? []);
$idx_avg      = $lead['leadership_10']['avg'] ?? null;
$skill_avg    = $lead['skills_8']['avg'] ?? null;
$advice_bul   = (array)($lead['advice']['bullets'] ?? []);

$idx_titles   = function_exists('bccm_leadership_index_titles') ? bccm_leadership_index_titles() : array_combine(array_keys($idx_items), array_keys($idx_items));
$skill_titles = function_exists('bccm_leadership_skill_titles') ? bccm_leadership_skill_titles() : array_combine(array_keys($skill_items), array_keys($skill_items));

// giữ thứ tự đẹp
$idx_order = ['personal_skill','strategic_thinking','execution','people_mgmt','special_leadership','culture','talent_mgmt','performance_report','information','work_methods'];
$skill_order = ['communication','innovation','negotiation','decision','critical','problem_solving','motivation','relationship'];

$fmt10 = function($v){ $v = floatval($v); if ($v<1) $v=1; if ($v>10) $v=10; return number_format($v,1); };
?>
<style>
  .bccm-grid { display:grid; gap:10px; }
  .grid-2 { grid-template-columns: 1fr 1fr; }
  .grid-3 { grid-template-columns: repeat(3,1fr); }
  .m12 { margin:12px 0; }
  .muted{ color:#6b7280; font-size:12px; }
  .kbar{ background:#eef2f7; border-radius:999px; height:10px; overflow:hidden;}
  .kbar>span{ display:block; height:100%; background:#4285f4; }
  .kv{ font-weight:600; }
  .mini-row{ display:grid; grid-template-columns: 1fr 56px; align-items:center; gap:8px; }
  .pill{ display:inline-block; background:#eef2f7; border-radius:999px; padding:2px 8px; margin-right:6px; font-size:12px; }
  .bccm-radar{ width:100%; height:420px; max-width:720px; margin:10px auto; }
  .bccm-radar svg{ width:100%; height:100%; display:block; }
</style>

<div class="bccm-card">
  <div class="bccm-h2">Bản đồ Năng lực Lãnh đạo (10 chỉ số)</div>

  <?php
    $idx_labels = [];
    $idx_values = [];
    foreach ($idx_order as $k){
      if (!isset($idx_items[$k])) continue;
      $idx_labels[] = $idx_titles[$k] ?? $k;
      $idx_values[] = (float)$idx_items[$k];
    }
  ?>
  <div id="leadership10" class="bccm-radar"
       data-labels="<?php echo esc_attr(json_encode($idx_labels,JSON_UNESCAPED_UNICODE)); ?>"
       data-values="<?php echo esc_attr(json_encode($idx_values)); ?>"></div>
  <div class="muted">Điểm trung bình: <span class="kv"><?php echo esc_html($idx_avg!==null?$fmt10($idx_avg):'—'); ?></span></div>

  <div class="m12 bccm-grid grid-2">
    <?php foreach ($idx_order as $k): if (!isset($idx_items[$k])) continue; ?>
      <div class="mini-row">
        <div>
          <div style="font-size:13px;"><?php echo esc_html($idx_titles[$k] ?? $k); ?></div>
          <div class="kbar"><span style="width:<?php echo esc_attr(min(100,max(10,$idx_items[$k]*10))); ?>%"></span></div>
        </div>
        <div class="kv" title="1..10"><?php echo esc_html($fmt10($idx_items[$k])); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="bccm-card">
  <div class="bccm-h2">8 Kỹ năng Lãnh đạo</div>

  <?php
    $sk_labels=[]; $sk_values=[];
    foreach ($skill_order as $k){
      if (!isset($skill_items[$k])) continue;
      $sk_labels[] = $skill_titles[$k] ?? $k;
      $sk_values[] = (float)$skill_items[$k];
    }
  ?>
  <div id="leadership8" class="bccm-radar"
       data-labels="<?php echo esc_attr(json_encode($sk_labels,JSON_UNESCAPED_UNICODE)); ?>"
       data-values="<?php echo esc_attr(json_encode($sk_values)); ?>"></div>
  <div class="muted">Điểm trung bình: <span class="kv"><?php echo esc_html($skill_avg!==null?$fmt10($skill_avg):'—'); ?></span></div>

  <div class="m12 bccm-grid grid-2">
    <?php foreach ($skill_order as $k): if (!isset($skill_items[$k])) continue; ?>
      <div class="mini-row">
        <div>
          <div style="font-size:13px;"><?php echo esc_html($skill_titles[$k] ?? $k); ?></div>
          <div class="kbar"><span style="width:<?php echo esc_attr(min(100,max(10,$skill_items[$k]*10))); ?>%"></span></div>
        </div>
        <div class="kv" title="1..10"><?php echo esc_html($fmt10($skill_items[$k])); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!empty($advice_bul)): ?>
<div class="bccm-card">
  <div class="bccm-h2">Gợi ý phát triển nhanh</div>
  <ul class="bccm-list">
    <?php foreach ($advice_bul as $li): ?>
      <li><?php echo esc_html(is_array($li)?implode(' - ',$li):$li); ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<script>
// ===== Radar SVG thuần (no CDN) =====
(function(){
  function drawRadar(el){
    try{
      const labels = JSON.parse(el.dataset.labels||'[]');
      const values = JSON.parse(el.dataset.values||'[]').map(v=>Math.max(1,Math.min(10,+v||0)));
      if(!labels.length || !values.length) return;

      const W=el.clientWidth, H=el.clientHeight, cx=W/2, cy=H/2, r=Math.min(W,H)*0.38;
      const N=Math.min(labels.length, values.length);
      const toXY=(i,s)=>{ const a=(-Math.PI/2)+i*(2*Math.PI/N); return [cx+r*(s/10)*Math.cos(a), cy+r*(s/10)*Math.sin(a)]; };

      let svg=`<svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg">`;
      // rings
      for(let g=2; g<=10; g+=2){ const rr=r*g/10; svg+=`<circle cx="${cx}" cy="${cy}" r="${rr}" fill="none" stroke="#e9eef3" stroke-width="1"/>`; }
      // spokes + labels
      for(let i=0;i<N;i++){
        const [x,y]=toXY(i,10); svg+=`<line x1="${cx}" y1="${cy}" x2="${x}" y2="${y}" stroke="#e9eef3" stroke-width="1"/>`;
        const [lx,ly]=toXY(i,11.7);
        svg+=`<text x="${lx}" y="${ly}" font-size="11" text-anchor="middle" fill="#5b6b7a">${String(labels[i]).replace(/&/g,'&amp;').replace(/</g,'&lt;')}</text>`;
      }
      // polygon
      const pts = values.map((v,i)=>toXY(i,v));
      let d=''; pts.forEach((p,i)=>{ d+=(i?'L':'M')+p[0]+','+p[1]; }); d+='Z';
      svg+=`<path d="${d}" fill="rgba(66,133,244,.18)" stroke="#4285f4" stroke-width="2"/>`;
      pts.forEach(p=> svg+=`<circle cx="${p[0]}" cy="${p[1]}" r="3" fill="#4285f4"/>`);
      svg+='</svg>';
      el.innerHTML=svg;
    }catch(e){}
  }
  const run=()=>document.querySelectorAll('.bccm-radar').forEach(drawRadar);
  window.addEventListener('resize', run); run();
})();
</script>

    <!-- Phần 2: Hành trình 90 ngày -->

    <?php $vision = is_array($vision ?? null) ? $vision : json_decode($profile['vision_json'] ?? '[]', true); ?>

<?php if ($vision): ?>

<!-- CARD 1: Mission • Vision -->
<div class="bccm-card">
  <div class="bccm-h2">Sứ mệnh & Tầm nhìn của <?php echo esc_html($profile['full_name'] ?? ''); ?></div>
  <p><strong>Sứ mệnh:</strong> <?php echo esc_html($vision['mission'] ?? ''); ?></p>
  <p><strong>Tầm nhìn:</strong> <?php echo esc_html($vision['vision'] ?? ''); ?></p>
</div>

<!-- CARD 2: Business Model Canvas -->
<div class="bccm-card">
  <div class="bccm-h2">Gợi ý Mô hình kinh doanh cần hướng tới của <?php echo esc_html($profile['full_name'] ?? ''); ?></div>
  <?php $b = (array)($vision['bmc'] ?? []); ?>
  <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div>
      <div class="bccm-h3">Cần thiết lập các Đối tác chính - Key Partners</div>
      <ul class="bccm-list"><?php foreach ((array)($b['key_partners']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

      <div class="bccm-h3">Cần thực hiện các Hoạt động chính - Key Activities</div>
      <ul class="bccm-list"><?php foreach ((array)($b['key_activities']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

      <div class="bccm-h3">Cần có các Tài nguyên chính - Key Resources</div>
      <ul class="bccm-list"><?php foreach ((array)($b['key_resources']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

      <div class="bccm-h3">Cần xác định Cơ cấu chi phí - Cost Structure</div>
      <ul class="bccm-list"><?php foreach ((array)($b['cost_structure']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
    </div>

    <div>
      <div class="bccm-h3">Cần có các Đề xuất giá trị - Value Propositions</div>
      <ul class="bccm-list"><?php foreach ((array)($b['value_propositions']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

      <div class="bccm-h3">Cần xác định Mối quan hệ khách hàng - Customer Relationships</div>
      <ul class="bccm-list"><?php foreach ((array)($b['customer_relationships']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

      <div class="bccm-h3">Cần xác định Phân khúc khách hàng - Customer Segments</div>
      <ul class="bccm-list"><?php foreach ((array)($b['customer_segments']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

      <div class="bccm-h3">Cần xác định Kênh phân phối - Channels</div>
      <ul class="bccm-list"><?php foreach ((array)($b['channels']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

      <div class="bccm-h3">Cần xác định Dòng doanh thu - Revenue Streams</div>
      <ul class="bccm-list"><?php foreach ((array)($b['revenue_streams']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
    </div>
  </div>
</div>

<!-- CARD 3: Value Proposition Canvas + Brand -->
<div class="bccm-card">
  <div class="bccm-h2">Cần xác định tuyên bố giá trị - Value Proposition Canvas</div>
  <?php $v = (array)($vision['vpc'] ?? []); ?>
  <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div>
      <div class="bccm-h3">Xác định Customer Jobs</div>
      <ul class="bccm-list"><?php foreach ((array)($v['customer_jobs']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
      <div class="bccm-h3">Xác định Pains - Nỗi đau</div>
      <ul class="bccm-list"><?php foreach ((array)($v['pains']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
      <div class="bccm-h3">Xác định Gains - Lợi ích</div>
      <ul class="bccm-list"><?php foreach ((array)($v['gains']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
    </div>
    <div>
      <div class="bccm-h3">Xác định Products & Services - Sản phẩm & Dịch vụ</div>
      <ul class="bccm-list"><?php foreach ((array)($v['products_services']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
      <div class="bccm-h3">Xác định Pain Relievers - Giảm đau</div>
      <ul class="bccm-list"><?php foreach ((array)($v['pain_relievers']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
      <div class="bccm-h3">Xác định Gain Creators - Tạo ra lợi ích</div>
      <ul class="bccm-list"><?php foreach ((array)($v['gain_creators']??[]) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
    </div>
  </div>

</div>
<!-- CARD 4: Brand Identity -->
<div class="bccm-card">  

  <div class="bccm-h2" style="margin-top:16px;">Lưu ý Brand nhận diện</div>
  <?php $brand = (array)($vision['brand'] ?? []); ?>
  <ul class="bccm-list">
    <?php if(!empty($brand['archetype'])): ?><li><strong>Archetype:</strong> <?php echo esc_html($brand['archetype']); ?></li><?php endif; ?>
    <?php if(!empty($brand['tone_of_voice'])): ?><li><strong>Giọng điệu:</strong> <?php foreach ($brand['tone_of_voice'] as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li><?php endif; ?>
    <?php if(!empty($brand['colors'])): ?>
    <li><strong>Màu sắc:</strong>
        <?php foreach ($brand['colors'] as $x): 
          $color = esc_html($x); ?>
          <span class="pill" style="display:inline-flex;align-items:center;gap:6px;">
            <span style="display:inline-block;width:18px;height:18px;border-radius:4px;background:<?php echo $color; ?>;border:1px solid #ccc;"></span>
            <?php echo $color; ?>
          </span>
        <?php endforeach; ?>
      </li>
    <?php endif; ?>
    <?php if(!empty($brand['logo_ideas'])): ?><li><strong>Ý tưởng logo:</strong> <?php foreach ($brand['logo_ideas'] as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li><?php endif; ?>
    <?php if(!empty($brand['symbols'])): ?><li><strong>Biểu tượng:</strong> <?php foreach ($brand['symbols'] as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li><?php endif; ?>
    <?php if(!empty($brand['keywords'])): ?><li><strong>Từ khoá:</strong> <?php foreach ($brand['keywords'] as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li><?php endif; ?>
    <?php if(!empty($brand['slogan_options'])): ?>
      <li><strong>Slogan gợi ý:</strong>
        <ul class="bccm-list"><?php foreach ($brand['slogan_options'] as $x) echo '<li>“'.esc_html($x).'”</li>'; ?></ul>
      </li>
    <?php endif; ?>
  </ul>
</div>

<?php endif; ?>



<?php $customer = json_decode($profile['customer_json'] ?? '[]', true); ?>
<?php if (!empty($customer)): ?>

<!-- Magic Triangle -->
<div class="bccm-card">
  <div class="bccm-h2">Mô hình giá trị <?php echo $profile['full_name'];?> cần hướng tới</div>
  <ul class="bccm-list">
    <li><strong>What:</strong> <?php echo esc_html($customer['magic_triangle']['what'] ?? ''); ?></li>
    <li><strong>Why:</strong>  <?php echo esc_html($customer['magic_triangle']['why'] ?? ''); ?></li>
    <li><strong>How:</strong>  <?php echo esc_html($customer['magic_triangle']['how'] ?? ''); ?></li>
    <li><strong>Who:</strong>  <?php echo esc_html($customer['magic_triangle']['who'] ?? ''); ?></li>
  </ul>
</div>

<!-- Customer Priority Matrix -->
<div class="bccm-card">
  <div class="bccm-h2">Customer Priority Matrix - Ma trận ưu tiên khách hàng của <?php echo $profile['full_name'];?> </div>
  <?php $cm = (array)($customer['customer_priority_matrix'] ?? []); ?>
  <div class="bccm-grid">
    <?php foreach (['jobs_importance'=>'Jobs','gains_importance'=>'Gains','pains_importance'=>'Pains'] as $k=>$title): ?>
      <div>
        <div class="bccm-h3"><?php echo esc_html($title); ?> Importance - Tầm quan trọng</div>
        <ol class="bccm-list">
          <?php foreach ((array)($cm[$k] ?? []) as $row): ?>
            <li><?php echo esc_html(($row['text'] ?? '')); ?>
              <?php if(isset($row['score'])): ?><span class="pill">★ <?php echo (int)$row['score']; ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Value Map Priority Matrix -->
<div class="bccm-card">
  <div class="bccm-h2">Value Map Priority Matrix - Ma trận ưu tiên giá trị của <?php echo $profile['full_name'];?> </div>
  <?php $vm = (array)($customer['value_map_priority_matrix'] ?? []); ?>
  <?php
    $blocks = [
      'products_services_importance'=>'Products/Services',
      'gain_creators_importance'=>'Gain Creators',
      'pain_relievers_importance'=>'Pain Relievers'
    ];
  ?>
  <div class="bccm-grid">
    <?php foreach ($blocks as $k=>$title): ?>
      <div>
        <div class="bccm-h3"><?php echo esc_html($title); ?> Importance - Tầm quan trọng</div>
        <ol class="bccm-list">
          <?php foreach ((array)($vm[$k] ?? []) as $row): ?>
            <li>
              <?php echo esc_html($row['text'] ?? ''); ?>
              <?php if(isset($row['score'])): ?><span class="pill">★ <?php echo (int)$row['score']; ?></span><?php endif; ?>
              <?php foreach (['maps_to_jobs'=>'jobs','maps_to_pains'=>'pains','maps_to_gains'=>'gains'] as $mk=>$label): ?>
                <?php if(!empty($row[$mk])): ?>
                  <div class="muted" style="margin-left:8px"><em>→ map to <?php echo $label; ?>:</em>
                    <?php foreach ((array)$row[$mk] as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Personas -->
<?php if(!empty($customer['personas'])): ?>
<div class="bccm-card">
  <div class="bccm-h2">Buyer Personas - Chân dung khách hàng của <?php echo $profile['full_name'];?></div>
  <?php foreach ((array)$customer['personas'] as $p): ?>
    <div class="bccm-subcard" style="margin-bottom:12px">
      <div class="bccm-h3"><?php echo esc_html($p['name'] ?? 'Persona'); ?> <span class="pill"><?php echo esc_html($p['segment'] ?? ''); ?></span></div>
      <ul class="bccm-list">
        <li><strong>Goals - Mục tiêu:</strong> <?php foreach ((array)($p['goals']??[]) as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li>
        <li><strong>Top Jobs - Top việc:</strong> <?php foreach ((array)($p['top_jobs']??[]) as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li>
        <li><strong>Pains - Vấn đề:</strong> <?php foreach ((array)($p['pains']??[]) as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li>
        <li><strong>Gains - Cần khắc phục:</strong> <?php foreach ((array)($p['gains']??[]) as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li>
        <li><strong>Channels - Kênh:</strong> <?php foreach ((array)($p['channels']??[]) as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li>
      </ul>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- JTBD & Actions -->
<div class="bccm-card">
  <div class="bccm-h2">Jobs To Be Done & Next Actions - Công việc cần thực hiện & Hành động tiếp theo của <?php echo $profile['full_name'];?></div>
  <div class="bccm-grid">
    <div>
      <div class="bccm-h3">JTBD</div>
      <ul class="bccm-list"><?php foreach ((array)($customer['jtbd'] ?? []) as $x) echo '<li>'.esc_html($x).'</li>'; ?></ul>
    </div>
    <div>
      <div class="bccm-h3">Actions</div>
      <ul class="bccm-list"><?php foreach ((array)($customer['actions'] ?? []) as $x) echo '<li>'.esc_html($x).'</li>'; ?></ul>
    </div>
  </div>
</div>

<?php endif; ?>

<?php $winning = json_decode($profile['winning_json'] ?? '[]', true); ?>
<?php if (!empty($winning)): ?>
<div class="bccm-card">
  <div class="bccm-h2">Winning Model — What • Why • How • Who</div>
  <ul class="bccm-list">
    <li><strong>What:</strong> <?php echo esc_html($winning['what'] ?? ''); ?></li>
    <li><strong>Why:</strong>  <?php echo esc_html($winning['why'] ?? ''); ?></li>
    <li><strong>How:</strong>  <?php echo esc_html($winning['how'] ?? ''); ?></li>
    <li><strong>Who:</strong>  <?php echo esc_html($winning['who'] ?? ''); ?></li>
  </ul>
  <div class="bccm-grid">
    <div>
      <div class="bccm-h3">Unfair Advantage</div>
      <ul class="bccm-list"><?php foreach ((array)($winning['unfair_advantage'] ?? []) as $x) echo '<li>'.esc_html($x).'</li>'; ?></ul>
      <div class="bccm-h3">North Star Metric</div>
      <p><strong><?php echo esc_html($winning['north_star_metric'] ?? ''); ?></strong></p>
    </div>
    <div>
      <div class="bccm-h3">Strategic Bets (12–24m)</div>
      <ul class="bccm-list"><?php foreach ((array)($winning['strategic_bets'] ?? []) as $x) echo '<li>'.esc_html($x).'</li>'; ?></ul>
    </div>
  </div>
  <div class="bccm-h3">Go-To-Market</div>
  <?php $gtm = (array)($winning['go_to_market'] ?? []); ?>
  <ul class="bccm-list">
    <li><strong>Positioning:</strong> <?php echo esc_html($gtm['positioning'] ?? ''); ?></li>
    <li><strong>Channels:</strong> <?php foreach ((array)($gtm['channels'] ?? []) as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li>
    <li><strong>Messages:</strong> <?php foreach ((array)($gtm['messages'] ?? []) as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li>
    <li><strong>Offers:</strong> <?php foreach ((array)($gtm['offers'] ?? []) as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li>
    <li><strong>Growth loops:</strong> <?php foreach ((array)($gtm['growth_loops'] ?? []) as $x) echo '<span class="pill">'.esc_html($x).'</span> '; ?></li>
  </ul>
  <div class="bccm-h3">Risk Mitigations</div>
  <ul class="bccm-list"><?php foreach ((array)($winning['risk_mitigations'] ?? []) as $x) echo '<li>'.esc_html($x).'</li>'; ?></ul>
</div>
<?php endif; ?>





<?php
// —— BCCM slide-like UI (safe to include multiple times) ——
if (!defined('BCCM_SLIDE_STYLES')) {
  define('BCCM_SLIDE_STYLES', true);
  echo '<style>
    .bccm-card{margin:22px 0 28px;padding:22px 24px;border:1px solid #e9eef3;border-radius:14px;background:#fff;
      box-shadow:0 2px 10px rgba(20,35,80,.05);}
    .bccm-h2{font-size:22px;font-weight:700;margin:0 0 14px;color:#142350;}
    .bccm-h3{font-size:18px;font-weight:700;margin:8px 0 10px;color:#22366a;}
    .bccm-sub{color:#5b6b88;margin:6px 0 0;}
    .bccm-list{list-style:disc;padding-left:20px;margin:10px 0;}
    .bccm-list li{margin:8px 0;line-height:1.7;}
    .pill{display:inline-block;padding:4px 10px;border-radius:30px;background:#f2f5fb;margin:4px 6px 0 0;
      font-size:12px;color:#314167;border:1px solid #e6ecf5}
    .pillbox{margin:12px 0 0;padding:12px 14px;border:1px dashed #dfe7f3;border-radius:10px;background:#fbfdff}
    .bccm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
    .week-tile{border:1px solid #e8eef7;border-radius:12px;padding:12px 14px;background:#fafdff}
    .week-tile .title{font-weight:700;margin:0 0 6px;color:#1c2e57}
    .kv{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0}
    .kv b{min-width:110px;color:#2a3d73}
    .tight p, .tight div{margin:8px 0;line-height:1.75}
  </style>';
}

/* ====================== RENDER: VALUE PROPOSITION (split cards) ====================== */
function bccm_render_value_cards($value){
  if (empty($value) || !is_array($value)) return;
  $segments = $value['segments'] ?? [];
  $props    = $value['propositions'] ?? [];

  // Header card
  echo '<div class="bccm-card tight">';
  echo '<div class="bccm-h2">Value Proposition Map</div>';
  $segCount = is_array($segments) ? count($segments) : 0;
  $propCount = is_array($props) ? count($props) : 0;
  echo '<div class="bccm-sub">Tổng quan: <span class="pill">'.$segCount.' segments</span> <span class="pill">'.$propCount.' propositions</span></div>';
  echo '</div>';

  // Segments — mỗi segment một card
  if ($segments){
    echo '<div class="bccm-card"><div class="bccm-h3">Customer Segments - Xác định phân khúc khách hàng</div>';
    echo '<div class="bccm-grid">';
    foreach ($segments as $s){
      echo '<div class="pillbox">';
      echo '<div style="font-weight:700;color:#22366a">'.esc_html($s['name'] ?? 'Segment').'</div>';
      if (!empty($s['profile'])) echo '<div class="bccm-sub">'.esc_html($s['profile']).'</div>';
      if (!empty($s['jobs']))  { echo '<div class="kv"><b>Jobs - Nhiệm vụ </b><div>'.esc_html(implode(', ', (array)$s['jobs'])).'</div></div>'; }
      if (!empty($s['pains'])) { echo '<div class="kv"><b>Pains - Vấn đề</b><div>'.esc_html(implode(', ', (array)$s['pains'])).'</div></div>'; }
      if (!empty($s['gains'])) { echo '<div class="kv"><b>Gains - Cách giải quy</b><div>'.esc_html(implode(', ', (array)$s['gains'])).'</div></div>'; }
      if (isset($s['priority'])) echo '<span class="pill">Priority: '.esc_html($s['priority']).'</span>';
      echo '</div>';
    }
    echo '</div></div>';
  }

  // Propositions — mỗi proposition một card riêng
  if ($props){
    echo '<div class="bccm-card"><div class="bccm-h3">Chuỗi giá trị Value chains cần tập trung</div>';
    echo '<div class="bccm-grid">';
    foreach ($props as $p){
      echo '<div class="pillbox">';
      echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px">';
      echo '<div style="font-weight:700">'.esc_html($p['title'] ?? 'Proposition').'</div>';
      if (!empty($p['for_segment'])) echo '<span class="pill">'.esc_html($p['for_segment']).'</span>';
      echo '</div>';

      if (!empty($p['promise']))    echo '<div class="kv"><b>Promise - Niềm tin</b><div>'.esc_html($p['promise']).'</div></div>';
      if (!empty($p['solutions']))  echo '<div class="kv"><b>Solutions - Giải pháp</b><div>'.esc_html(implode(', ', (array)$p['solutions'])).'</div></div>';
      if (!empty($p['channels']))   echo '<div class="kv"><b>Channels - Kênh</b><div>'.esc_html(implode(', ', (array)$p['channels'])).'</div></div>';
      if (!empty($p['proof']))      echo '<div class="kv"><b>Proof - Cơ sở</b><div>'.esc_html(implode(', ', (array)$p['proof'])).'</div></div>';
      if (!empty($p['kpis']))       echo '<div class="kv"><b>KPIs</b><div>'.esc_html(implode(', ', (array)$p['kpis'])).'</div></div>';
      if (!empty($p['wow']))        echo '<div class="kv"><b>WOW - Công thức WOW</b><div>'.esc_html(implode(', ', (array)$p['wow'])).'</div></div>';
      if (!empty($p['risks']))      echo '<div class="kv"><b>Risks - Đề phòng rủi ro</b><div>'.esc_html(implode(', ', (array)$p['risks'])).'</div></div>';
      if (!empty($p['timeframe']))  echo '<span class="pill">'.esc_html($p['timeframe']).'</span>';
      if (isset($p['priority']))    echo ' <span class="pill">Priority: '.esc_html($p['priority']).'</span>';
      echo '</div>';
    }
    echo '</div></div>';
  }
}
$value = json_decode($profile['value_json'] ?? '', true);
bccm_render_value_cards($value);
?>

<div class="bccm-card tight"><div class="bccm-h2">Xác định mục tiêu cần làm gì trong 90 ngày cấu trúc hoạt động kinh doanh của <?php echo esc_html($profile['full_name']); ?></div></div>

<!-- Phần 3: SWOT -->
<?php $swot = json_decode($profile['swot_json'] ?? '[]', true); ?>
<?php if (!empty($swot['swot'])): ?>
  <div class="bccm-card">
    <div class="bccm-h2">Giai đoạn 1: Tối ưu từng trụ cột</div>
    <?php if(!empty($swot['priorities'])): ?>
      <ul class="bccm-list">
        <?php foreach ($swot['priorities'] as $p): ?>
          <li><strong><?php echo esc_html($p['topic'] ?? ''); ?></strong> — <?php echo esc_html($p['why'] ?? ''); ?>
            <?php if (!empty($p['quarter'])): ?><span class="pill"><?php echo esc_html($p['quarter']); ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="muted">Chưa có danh sách ưu tiên.</p>
    <?php endif; ?>
  </div>

  <?php
    $labels = [
      'marketing'=>'Chiến lược Marketing','innovation'=>'Đào sâu yếu tố khác biệt - Innovation','technology'=>'Cải thiện công nghệ - Technology',
      'management_team'=>'Đào tạo đội ngũ Quản lý - Management team','finance'=>'Cải thiện tài chính - Finance','employee'=>'Nhân sự - Employee',
      'business_model'=>'Củng cố mô hình Kinh doanh - Business Model','operations'=>'Vận hành - Operations'
    ];
    foreach ($swot['swot'] as $topic => $box):
      $box = is_array($box) ? $box : [];
  ?>
    <div class="bccm-card">
      <div class="bccm-h2"><?php echo esc_html($labels[$topic] ?? ucfirst($topic)); ?></div>
      <div class="bccm-grid">
        <div>
          <div class="bccm-h3">Điểm mạnh cần tăng cường - Strengths</div>
          <ul class="bccm-list"><?php foreach ((array)($box['strengths'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

          <div class="bccm-h3">Cơ hội - Opportunities</div>
          <ul class="bccm-list"><?php foreach ((array)($box['opportunities'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
        </div>
        <div>
          <div class="bccm-h3">Điểm yếu - Weaknesses</div>
          <ul class="bccm-list"><?php foreach ((array)($box['weaknesses'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

          <div class="bccm-h3">Rủi ro - Threats</div>
          <ul class="bccm-list"><?php foreach ((array)($box['threats'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
        </div>
      </div>

      <?php if(!empty($box['actions']) || !empty($box['kpis'])): ?>
        <div class="bccm-grid">
          <div>
            <div class="bccm-h3">Hành động ưu tiên</div>
            <ul class="bccm-list"><?php foreach ((array)($box['actions'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
          </div>
          <div>
            <div class="bccm-h3">KPI cần có</div>
            <ul class="bccm-list"><?php foreach ((array)($box['kpis'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php
/* ============================================================
 * PHẦN 4: KẾ HOẠCH 90 NGÀY (90 Days Transform)
 * Dựa vào $planData['overview'], $phases (đã normalize)
 * ============================================================ */

// Helper nhỏ để in list <li>
$render_list = function($arr){
  if (empty($arr) || !is_array($arr)) return;
  echo '<ul class="bccm-list">';
  foreach ($arr as $it) {
    if (is_array($it)) $it = implode(', ', array_map('strval', $it));
    echo '<li>'.esc_html((string)$it).'</li>';
  }
  echo '</ul>';
};

// Helper in pill list
$render_pills = function($arr){
  if (empty($arr) || !is_array($arr)) return;
  foreach ($arr as $it) {
    if (is_array($it)) $it = implode(', ', array_map('strval', $it));
    echo '<span class="pill">'.esc_html((string)$it).'</span> ';
  }
};

// ===== Overview card =====
$ov = is_array($planData['overview'] ?? null) ? $planData['overview'] : [];
?>
<div class="bccm-card">
  <div class="bccm-h2">90 ngày chuyển đổi số </div>
  <div class="bccm-grid">
    <div>
      <div class="bccm-h3">Chủ đề</div>
      <p><strong><?php echo esc_html($ov['theme'] ?? '90-Day Strategy Canvas'); ?></strong></p>

      <div class="bccm-h3">Mục tiêu</div>
      <p><?php echo esc_html($ov['objective'] ?? 'Xây & triển khai canvas chiến lược 90 ngày chuyển đổi số.'); ?></p>
    </div>
    <div>
      <div class="bccm-h3">Kết quả đầu ra</div>
      <p><?php echo esc_html($ov['output'] ?? 'Trở thành doanh nghiệp trên nền tảng AI, update toàn diện các phân khúc khách hàng trên các nền tảng mạng xã hội'); ?></p>

      <?php if (!empty($ov['company']) || !empty($ov['industry'])): ?>
      <div class="bccm-h3">Doanh nghiệp</div>
      <p>
        <?php if(!empty($ov['company'])): ?><span class="pill"><?php echo esc_html($ov['company']); ?></span><?php endif; ?>
        <?php if(!empty($ov['industry'])): ?><span class="pill"><?php echo esc_html($ov['industry']); ?></span><?php endif; ?>
      </p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
// ===== Phases + Modules =====
if (!empty($phases)):
  foreach ($phases as $pi => $phase):
    $p_title = (string)($phase['title'] ?? ('Giai đoạn '.($pi+1)));
    $modules = is_array($phase['modules'] ?? null) ? $phase['modules'] : [];
?>
  <div class="bccm-card">
    <div class="bccm-h2"><?php echo esc_html($p_title); ?></div>

    <?php if ($modules): ?>
      <?php foreach ($modules as $mi => $m):
        if (!is_array($m)) continue;
        $m_title    = (string)($m['title'] ?? ('Module '.($mi+1)));
        $day_range  = (string)($m['day_range'] ?? '');
        $objectives = (array)($m['objectives'] ?? []);
        $kpis       = (array)($m['kpis'] ?? []);
        $tasks      = (array)($m['tasks'] ?? []);
        $owners     = (array)($m['owners'] ?? []);
        $tools      = (array)($m['tools'] ?? []);
        $outputs    = (array)($m['outputs'] ?? []);
      ?>
        <div class="bccm-card" style="margin:14px 0 0">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div class="bccm-h3" style="margin:0;"><?php echo esc_html($m_title); ?></div>
            <?php if($day_range): ?><span class="pill">⏱ <?php echo esc_html($day_range); ?></span><?php endif; ?>
          </div>

          <?php if ($objectives): ?>
            <div class="bccm-h3">Objectives</div>
            <?php $render_list($objectives); ?>
          <?php endif; ?>

          <div class="bccm-grid" style="margin-top:8px">
            <div>
              <?php if ($kpis): ?>
                <div class="bccm-h3">KPIs</div>
                <?php $render_list($kpis); ?>
              <?php endif; ?>

              <?php if ($tools): ?>
                <div class="bccm-h3">Tools</div>
                <p><?php $render_pills($tools); ?></p>
              <?php endif; ?>
            </div>

            <div>
              <?php if ($tasks): ?>
                <div class="bccm-h3">Tasks</div>
                <?php $render_list($tasks); ?>
              <?php endif; ?>

              <?php if ($owners): ?>
                <div class="bccm-h3">Owners</div>
                <p><?php $render_pills($owners); ?></p>
              <?php endif; ?>

              <?php if ($outputs): ?>
                <div class="bccm-h3">Outputs</div>
                <?php $render_list($outputs); ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php
      // Weeks timeline của phase (nếu có)
      $weeks = is_array($phase['weeks'] ?? null) ? $phase['weeks'] : [];
      if ($weeks):
    ?>
      <div class="bccm-card" style="margin-top:14px">
        <div class="bccm-h3"><?php echo esc_html($p_title); ?> — Weeks Timeline</div>
        <div class="bccm-grid">
          <?php foreach ($weeks as $w):
            if (!is_array($w)) continue;
            $w_title = (string)($w['title'] ?? 'Week');
            $mil     = (array)($w['milestones'] ?? []);
            $wkpis   = (array)($w['kpis'] ?? []);
            $wtasks  = (array)($w['tasks'] ?? []);
          ?>
            <div class="week-tile">
              <div class="title"><?php echo esc_html($w_title); ?></div>
              <?php if ($mil): ?><div><em>Milestones:</em> <?php echo esc_html(implode(', ', $mil)); ?></div><?php endif; ?>
              <?php if ($wkpis): ?><div><em>KPIs:</em> <?php echo esc_html(implode(', ', $wkpis)); ?></div><?php endif; ?>
              <?php if ($wtasks): ?><div><em>Tasks:</em> <?php echo esc_html(implode(', ', $wtasks)); ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
<?php
  endforeach;
endif;
?>

<?php

/* ====================== RENDER: BIZCOACH 90-DAY (split cards) ====================== */
function bccm_render_bizcoach_map($biz){
  if (empty($biz) || !is_array($biz)) return;

  

  // Overview card
  if (!empty($biz['overview'])){
    $ov = $biz['overview'];
    echo '<div class="bccm-card tight">';
    echo '<div class="bccm-h3">Overview tổng kết</div>';
    if (!empty($ov['north_star'])) echo '<div class="kv"><b>North Star - Tầm nhìn</b><div>'.esc_html($ov['north_star']).'</div></div>';
    if (!empty($ov['key_kpis']))   echo '<div class="kv"><b>Key KPIs</b><div>'.esc_html(implode(', ', (array)$ov['key_kpis'])).'</div></div>';
    if (!empty($ov['assumptions']))echo '<div class="kv"><b>Assumptions - Giả định</b><div>'.esc_html(implode(', ', (array)$ov['assumptions'])).'</div></div>';
    echo '</div>';
  }

  // Phases — mỗi phase 2 card: (A) Objectives & Workstreams, (B) Weeks timeline
  if (!empty($biz['phases']) && is_array($biz['phases'])){
    foreach ($biz['phases'] as $p){
      // (A) Objectives + Workstreams
      echo '<div class="bccm-card tight">';
      echo '<div class="bccm-h3">'.esc_html($p['title'] ?? 'Phase').'</div>';
      if (!empty($p['objectives'])) echo '<div class="kv"><b>Mục tiêu</b><div>'.esc_html(implode(', ', (array)$p['objectives'])).'</div></div>';

      if (!empty($p['workstreams'])){
        echo '<div class="bccm-sub" style="margin-top:12px">Workstreams - Các luồng việc</div>';
        echo '<div class="bccm-grid">';
        foreach ($p['workstreams'] as $ws){
          echo '<div class="pillbox">';
          echo '<div style="font-weight:700;color:#22366a">'.esc_html($ws['name'] ?? 'Workstream').'</div>';
          if (!empty($ws['tasks']))       echo '<div class="kv"><b>Tasks</b><div>'.esc_html(implode(', ', (array)$ws['tasks'])).'</div></div>';
          if (!empty($ws['owners']))      echo '<div class="kv"><b>Owners</b><div>'.esc_html(implode(', ', (array)$ws['owners'])).'</div></div>';
          if (!empty($ws['tools']))       echo '<div class="kv"><b>Tools</b><div>'.esc_html(implode(', ', (array)$ws['tools'])).'</div></div>';
          if (!empty($ws['deliverables']))echo '<div class="kv"><b>Deliverables</b><div>'.esc_html(implode(', ', (array)$ws['deliverables'])).'</div></div>';
          if (!empty($ws['kpis']))        echo '<div class="kv"><b>KPIs</b><div>'.esc_html(implode(', ', (array)$ws['kpis'])).'</div></div>';
          if (!empty($ws['risks']))       echo '<div class="kv"><b>Risks</b><div>'.esc_html(implode(', ', (array)$ws['risks'])).'</div></div>';
          if (!empty($ws['budget']))      echo '<span class="pill">Budget: '.esc_html($ws['budget']).'</span>';
          echo '</div>';
        }
        echo '</div>';
      }
      echo '</div>';

      // (B) Weeks timeline card
      if (!empty($p['weeks'])){
        echo '<div class="bccm-card tight">';
        echo '<div class="bccm-h3">'.esc_html($p['title'] ?? 'Phase').' — Weeks</div>';
        echo '<div class="bccm-grid">';
        foreach ($p['weeks'] as $w){
          echo '<div class="week-tile">';
          echo '<div class="title">'.esc_html($w['title'] ?? 'Week').'</div>';
          if (!empty($w['milestones']))
            echo '<div><em>Milestones:</em> '.esc_html(implode(', ', (array)$w['milestones'])).'</div>';
          if (!empty($w['kpis']))
            echo '<div><em>KPIs:</em> '.esc_html(implode(', ', (array)$w['kpis'])).'</div>';
          echo '</div>';
        }
        echo '</div></div>';
      }
    }
  }
}


$biz   = json_decode($profile['bizcoach_json'] ?? '', true);
bccm_render_bizcoach_map($biz);
