<?php if (!defined('ABSPATH')) exit;
/**
 * FRONTEND — BABYCOACH PUBLIC MAP
 * Biến có sẵn: $coachee (JOIN từ plans -> profiles)
 * Yêu cầu schema AI trong $coachee['ai_summary'] theo bccm_ai_generate_for_baby_overview()
 */

/////////////////////////////////////////////
// 0) Helpers nhỏ & safe access
/////////////////////////////////////////////

$h = function($v){ return esc_html(is_scalar($v)?$v:wp_json_encode($v,JSON_UNESCAPED_UNICODE)); };

$read_json = function($raw){
  if (empty($raw) || !is_string($raw)) return [];
  $j = json_decode($raw, true);
  return (json_last_error()===JSON_ERROR_NONE && is_array($j)) ? $j : [];
};

$pick = function($arr, $path, $default=null){
  $cur = $arr;
  foreach (explode('.', $path) as $key){
    if ($key==='') continue;
    if (!is_array($cur) || !array_key_exists($key,$cur)) return $default;
    $cur = $cur[$key];
  }
  return $cur;
};

$age_in_months = function(?string $dob){
  if (empty($dob)) return null;
  try{
    $tz  = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $d0  = new DateTime($dob, $tz);
    $now = new DateTime('now', $tz);
    $diff= $d0->diff($now);
    return (int)($diff->y*12 + $diff->m + ($diff->d>=15?1:0));
  }catch(Exception $e){ return null; }
};

/////////////////////////////////////////////
// 1) Nạp dữ liệu gốc
/////////////////////////////////////////////

$ai     = $read_json($coachee['ai_summary'] ?? '');
$destiny= is_array($ai) ? $ai : [];

// Một số profile cơ bản
$baby_name  = $coachee['baby_name'] ?: ($coachee['full_name'] ?? '');
$gender_raw = strtolower($coachee['baby_gender'] ?? ($coachee['gender'] ?? ''));
$gender_vn  = ($gender_raw==='female') ? 'Bé gái' : 'Bé trai';
$dob        = $coachee['dob'] ?? '';
$months     = $age_in_months($dob);
$months_txt = $months!==null ? $months.' tháng' : '—';

$height_cm  = is_numeric($coachee['baby_height_cm'] ?? null) ? (float)$coachee['baby_height_cm'] : null;
$weight_kg  = is_numeric($coachee['baby_weight_kg'] ?? null) ? (float)$coachee['baby_weight_kg'] : null;
$bmi        = ($height_cm && $height_cm>0 && $weight_kg) ? round($weight_kg / pow($height_cm/100.0,2), 2) : null;

// Các field từ AI summary (đã theo đúng schema)
$overview   = (string)$pick($destiny, 'overview', '');
$life_path  = (string)$pick($destiny, 'life_path', '');
$soul_num   = (string)$pick($destiny, 'soul_number', '');

$growth_h   = (string)$pick($destiny, 'growth_comment.height', '');
$growth_w   = (string)$pick($destiny, 'growth_comment.weight', '');
$growth_bmi = (string)$pick($destiny, 'growth_comment.bmi', '');
$growth_adv = (array)$pick($destiny, 'growth_comment.advice', []);

$parent_nutri = (array)$pick($destiny, 'parenting.nutrition', []);
$parent_study = (array)$pick($destiny, 'parenting.study', []);
$parent_disc  = (array)$pick($destiny, 'parenting.discipline', []);
$parent_comp  = (array)$pick($destiny, 'parenting.compensate', []);

$talents   = (array)$pick($destiny, 'talent_suggestions', []);
$numbers_l = (array)$pick($destiny, 'numbers', []);
$lucky     = (array)$pick($destiny, 'lucky_colors', []);
$jobs_rec  = (array)$pick($destiny, 'jobs_recommended', []);
$to_prac   = (array)$pick($destiny, 'to_practice', []);
$to_avoid  = (array)$pick($destiny, 'to_avoid', []);
$months12  = (array)$pick($destiny, 'twelve_months', []);
$fortune   = (array)$pick($destiny, 'fortune', ['money'=>'','colleagues'=>'','career'=>'','luck'=>'']);

// 21 chỉ số (ưu tiên từ ai_summary.numbers_full; fallback sang numeric_json)
$numbers_full = (array)$pick($destiny, 'numbers_full', []);
if (!$numbers_full && !empty($coachee['numeric_json'])) {
  $nj = $read_json($coachee['numeric_json']);
  if (!empty($nj['numbers_full']) && is_array($nj['numbers_full'])) {
    $numbers_full = $nj['numbers_full'];
  }
}
$titles = function_exists('bccm_metric_titles') ? bccm_metric_titles() : [];
$keys21 = function_exists('bccm_metric_21_keys') ? bccm_metric_21_keys() : array_keys($numbers_full);

// Rút gọn: lấy ~9 ô tiêu biểu
$first9 = [];
foreach ($keys21 as $k){
  if (isset($numbers_full[$k])) $first9[$k] = $numbers_full[$k];
  if (count($first9) >= 9) break;
}

/////////////////////////////////////////////
// 2) CSS
/////////////////////////////////////////////
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
body {  margin:0!important; padding:0!important;  background: #eeeeee;  font-family: Inter,system-ui,Arial,sans-serif;  color: var(--bc-dark);}
.bccm-container { max-width:1100px; margin:0 auto; padding:28px 20px; }

/* Card */
.bccm-card{border-radius:var(--bc-radius);padding:22px 20px;margin:18px 0;background:linear-gradient(180deg,#ffffff 0%,#f9fbff 50%,#ffffff 100%);box-shadow:var(--bc-shadow-strong);border:1px solid #e3e7ef;position:relative}
.bccm-card:hover{transform:translateY(-4px);transition:.25s ease;box-shadow:var(--bc-shadow-strong),var(--bc-glow-blue)}
.bccm-h2{font-size:22px;font-weight:800;margin:0 0 12px;color:var(--bc-blue)}
.bccm-h3{font-size:17px;font-weight:700;margin:12px 0 8px;color:var(--bc-dark)}
.bccm-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:860px){.bccm-grid{grid-template-columns:1fr}}

.bccm-wrap{max-width:1100px;margin:0 auto;padding:28px 18px}
.card {
  border-radius: var(--bc-radius);
  padding:22px 20px;
  margin:18px 0;
  background: linear-gradient(180deg,#ffffff 0%,#f9fbff 50%,#ffffff 100%);
  box-shadow: var(--bc-shadow-strong);
  border:1px solid #e3e7ef;
  position: relative;
}
.card:hover{
  transform: translateY(-4px);
  transition: .25s ease;
  box-shadow: var(--bc-shadow-strong), var(--bc-glow-blue);
}

.h2{font-size:22px;font-weight:800;color:var(--bc-blue);margin:0 0 8px}
.h3{font-size:15px;font-weight:700;color:#22366a;margin:10px 0 6px}
.muted{color:var(--bc-gray)} .small{font-size:12px}
.badge{display:inline-block; padding:4px 12px; 
  margin:0 6px 6px 0;
  border-radius:999px; font-size:12px; font-weight:700;
  background: linear-gradient(90deg,var(--bc-blue),var(--bc-red));
  color:#fff; box-shadow: var(--bc-glow-blue);}
.list{padding-left:18px;margin:6px 0} .list li{margin:2px 0}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px} @media(max-width:860px){.grid2{grid-template-columns:1fr}}
.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
@media(max-width:980px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.metrics{grid-template-columns:1fr}}
.tile{border-radius:16px;padding:14px;color:#fff;background:linear-gradient(135deg,#1d4ed8,#3b82f6)}
.tile.alt{background:linear-gradient(135deg,#a21caf,#e879f9)}
.tile .n{font-size:28px;font-weight:900}
.tile .t{font-weight:800;text-transform:uppercase;letter-spacing:.02em}
.tile .d{opacity:.95;margin-top:3px}
.kv{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center}
.pill{display:inline-block;padding:4px 10px;border-radius:999px;background:#f1f5f9;margin:4px 6px 0 0}

/* === Cards Biểu đồ WHO (đẹp & đơn giản) === */
.bc-chart-grid{display:grid;grid-template-columns:1fr ;gap:1px}
@media(max-width:980px){.bc-chart-grid{grid-template-columns:1fr}}
.bc-chart-card{background:#fff;border:1px solid #e9eef5;border-radius:16px;padding:16px 16px 12px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
.bc-chart-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.bc-chart-title{font-size:18px;font-weight:800;color:#0f172a;margin:0}
.bc-legend{display:flex;gap:10px}
.bc-dot{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#334155}
.bc-dot i{display:inline-block;width:12px;height:12px;border-radius:3px}
.bc-dot .std{background:#60a5fa}
.bc-dot .me{background:#f97316}
.bc-sub{color:#6b7280;font-size:12px;margin:2px 0 10px}
.bc-canvas-wrap{position:relative;height:360px}

/* Hero Title */
.bccm-hero .title {
  font-size:32px; font-weight:900; letter-spacing:.5px;
  background: linear-gradient(90deg,var(--bc-blue),var(--bc-red));
  -webkit-background-clip:text; color:transparent;
}
</style>

<div class="bccm-wrap">
    
  <div class="bccm-hero">
      <div>
        <div class="title">90 Days of Transformation – <?php echo esc_html($coachee['baby_name'] ?? ''); ?></div>
        <div class="muted small"><?php echo esc_html($coach_label); ?> • Public Key: <span class="acc"><?php echo esc_html($key); ?></span></div>
      </div>
    </div>
  <!-- CARD: Tổng quan -->
  <div class="card">
    <div class="h2">Tổng quan về em bé</div>
    <div class="kv muted small">
      <span><strong>Họ tên:</strong> <?php echo $h($baby_name ?: '—'); ?></span>
      <span>• <strong>Giới tính:</strong> <?php echo $h($gender_vn); ?></span>
      <span>• <strong>Ngày sinh:</strong> <?php echo $h($dob ?: '—'); ?></span>
      <span>• <strong>Tuổi:</strong> <?php echo $h($months_txt); ?></span>
      <?php if ($height_cm!==null): ?><span>• <strong>Chiều cao:</strong> <?php echo $h($height_cm.' cm'); ?></span><?php endif; ?>
      <?php if ($weight_kg!==null): ?><span>• <strong>Cân nặng:</strong> <?php echo $h($weight_kg.' kg'); ?></span><?php endif; ?>
      <?php if ($bmi!==null): ?><span>• <strong>BMI:</strong> <?php echo $h($bmi); ?></span><?php endif; ?>
    </div>

    <?php if ($overview): ?>
      <p class="muted" style="margin-top:8px"><?php echo $h($overview); ?></p>
    <?php else: ?>
      <p class="muted"><em>Chưa có tổng quan. Hãy chạy “Tạo nhận xét tổng quan (Baby Overview)”.</em></p>
    <?php endif; ?>

    <?php if ($life_path || $soul_num): ?>
      <div class="kv" style="margin-top:8px">
        <?php if ($life_path): ?><span class="bccm-tag">Life Path: <?php echo $h($life_path); ?></span><?php endif; ?>
        <?php if ($soul_num): ?><span class="bccm-tag">Soul Number: <?php echo $h($soul_num); ?></span><?php endif; ?>
      </div>
    <?php endif; ?>

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
          <ul class="list">
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
          <ul class="list"><?php foreach ((array)($destiny['to_practice'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>

          <div class="bccm-h3">8) Cần tránh</div>
          <ul class="list"><?php foreach ((array)($destiny['to_avoid'] ?? []) as $it) echo '<li>'.esc_html($it).'</li>'; ?></ul>
        </div>
      </div>

      <div class="bccm-grid">
        <div>
          <div class="bccm-h3">9) Tài lộc & sự nghiệp thời gian tới</div>
          <ul class="list">
            <li><strong>Tiền bạc:</strong> <?php echo esc_html($destiny['fortune']['money'] ?? ''); ?></li>
            <li><strong>Đồng nghiệp:</strong> <?php echo esc_html($destiny['fortune']['colleagues'] ?? ''); ?></li>
            <li><strong>Sự nghiệp:</strong> <?php echo esc_html($destiny['fortune']['career'] ?? ''); ?></li>
            <li><strong>May mắn:</strong> <?php echo esc_html($destiny['fortune']['luck'] ?? ''); ?></li>
          </ul>
        </div>
      </div>
  </div>

  <?php
/* =======================
 * BABY – GROWTH SUMMARY CARDS + CHARTS (WHO)
 * ======================= */

/* —— helpers —— */
if (!function_exists('bccm_baby_age_months_simple')) {
  function bccm_baby_age_months_simple(?string $dob): ?int {
    if (empty($dob)) return null;
    try {
      $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
      $d0 = new DateTime($dob, $tz);
      $now= new DateTime('now', $tz);
      $diff=$d0->diff($now);
      return (int)($diff->y*12 + $diff->m + ($diff->d>=15?1:0));
    } catch (Exception $e) { return null; }
  }
}

/* Fallback xác định percentile-band nếu site chưa có sẵn hàm */
if (!function_exists('bccm_percentile_from_table')) {
  function bccm_percentile_from_table(?array $table, int $months, float $value): ?string {
    if (!$table) return null;
    $closest=null; $best=999;
    foreach($table as $row){
      $m = isset($row['months']) ? (int)$row['months'] : (isset($row['month'])?(int)$row['month']:null);
      if ($m===null) continue;
      $d = abs($m - $months);
      if ($d < $best){ $best=$d; $closest=$row; }
    }
    if (!$closest) return null;
    $norm = [];
    foreach ($closest as $k=>$v){
      $kl = strtolower((string)$k);
      if (preg_match('/^p(\d{1,2})$/',$kl,$mm)) $norm[(int)$mm[1]]=(float)$v;
      if (in_array($kl,['p3','p15','p50','p85','p97'],true)) {
        $n=(int)filter_var($kl,FILTER_SANITIZE_NUMBER_INT); $norm[$n]=(float)$v;
      }
    }
    $p3=$norm[3]??null; $p15=$norm[15]??null; $p50=$norm[50]??null; $p85=$norm[85]??null; $p97=$norm[97]??null;
    if ($p3!==null && $value < $p3)  return 'P<3';
    if ($p15!==null && $value < $p15) return 'P3–P15';
    if ($p50!==null && $value < $p50) return 'P15–P50';
    if ($p85!==null && $value < $p85) return 'P50–P85';
    if ($p97!==null && $value < $p97) return 'P85–P97';
    if ($p97!==null && $value >= $p97) return '>P97';
    return null;
  }
}

/* Đọc WHO JSON (tự động dò 2 nơi) */
$sex = (strtolower($coachee['baby_gender'] ?? '')==='female') ? 'girl' : 'boy';
$paths = [
  WP_PLUGIN_DIR . "/bizcoach-map/data/{$sex}_height.json",
  WP_PLUGIN_DIR . "/bizcoach-map/json/{$sex}_height.json",
  dirname(__FILE__) . "/../data/{$sex}_height.json",
  dirname(__FILE__) . "/../json/{$sex}_height.json",
];
$pathW = [
  WP_PLUGIN_DIR . "/bizcoach-map/data/{$sex}_weight.json",
  WP_PLUGIN_DIR . "/bizcoach-map/json/{$sex}_weight.json",
  dirname(__FILE__) . "/../data/{$sex}_weight.json",
  dirname(__FILE__) . "/../json/{$sex}_weight.json",
];
$whoH=[]; foreach ($paths as $p){ if (is_readable($p)) { $tmp=json_decode(@file_get_contents($p),true); if(is_array($tmp)){$whoH=$tmp; break;} } }
$whoW=[]; foreach ($pathW as $p){ if (is_readable($p)) { $tmp=json_decode(@file_get_contents($p),true); if(is_array($tmp)){$whoW=$tmp; break;} } }

/* Lấy tháng tuổi + số đo hiện tại */
$months = bccm_baby_age_months_simple($coachee['dob'] ?? '');
$hNow   = is_numeric($coachee['baby_height_cm'] ?? null) ? (float)$coachee['baby_height_cm'] : null;
$wNow   = is_numeric($coachee['baby_weight_kg'] ?? null) ? (float)$coachee['baby_weight_kg'] : null;
$bmi    = ($hNow && $wNow) ? round($wNow / pow($hNow/100.0, 2), 2) : null;

/* Hàm lấy record gần nhất theo tháng & giá trị chuẩn (median/standard) */
$nearest = function(array $tbl, ?int $m) {
  if ($m===null) return [null,null];
  $best=null; $dist=999;
  foreach ($tbl as $r){
    $mm = isset($r['months']) ? (int)$r['months'] : (isset($r['month'])?(int)$r['month']:null);
    if ($mm===null) continue;
    $d = abs($mm - $m);
    if ($d < $dist) { $dist=$d; $best=$r; }
  }
  if(!$best) return [null,null];
  $std = $best['standard'] ?? ($best['p50'] ?? ($best['P50'] ?? null));
  if ($std!==null && $std>200) $std=$std/10.0; // fix dữ liệu cm bị x10 (nếu dataset bị lỗi)
  return [$best, is_numeric($std) ? (float)$std : null];
};

/* Tính chuẩn & band */
list($rowH, $stdH) = $nearest($whoH, $months);
list($rowW, $stdW) = $nearest($whoW, $months);
$bandH = ($months!==null && $hNow!==null) ? bccm_percentile_from_table($whoH, $months, $hNow) : null;
$bandW = ($months!==null && $wNow!==null) ? bccm_percentile_from_table($whoW, $months, $wNow) : null;

/* Độ lệch */
$deltaH = ($stdH!==null && $hNow!==null) ? round($hNow - $stdH, 2) : null;
$deltaW = ($stdW!==null && $wNow!==null) ? round($wNow - $stdW, 2) : null;

/* Map band -> màu + nhãn ngắn */
$classOf = function($band){
  if (!$band) return ['muted','Chưa rõ'];
  if ($band==='P50–P85' || $band==='P15–P50') return ['ok','Bình thường'];
  if ($band==='P85–P97' || $band==='>P97')   return ['hi','Nhỉnh hơn tuổi'];
  if ($band==='P3–P15')                      return ['lo','Hơi thấp so tuổi'];
  if ($band==='P<3')                         return ['warn','Thấp so tuổi'];
  return ['muted','—'];
};
list($cH,$tH) = $classOf($bandH);
list($cW,$tW) = $classOf($bandW);

/* Escape helper */
$h = function($v){ return esc_html(is_scalar($v)?$v:wp_json_encode($v,JSON_UNESCAPED_UNICODE)); };

/* ===== LỊCH SỬ ĐO -> THÁNG TUỔI (để kẻ vạch đỏ) ===== */
$logs_raw = $coachee['growth_logs'] ?? ($coachee['growth_log_json'] ?? '');
$growth_logs = (isset($read_json) && is_callable($read_json)) ? $read_json($logs_raw) : (is_string($logs_raw) ? json_decode($logs_raw, true) : []);
if (!is_array($growth_logs)) $growth_logs = [];
$age_at = function(?string $dob, ?string $dstr) {
  if (empty($dob) || empty($dstr)) return null;
  try{
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $d0 = new DateTime($dob, $tz);
    $dd = new DateTime($dstr, $tz);
    $df = $d0->diff($dd);
    return (int)($df->y*12 + $df->m + ($df->d>=15?1:0));
  }catch(Exception $e){ return null; }
};
$event_months = [];
foreach ($growth_logs as $r) {
  $m = $age_at($coachee['dob'] ?? '', $r['tracking_date'] ?? ($r['date'] ?? null));
  if ($m!==null) $event_months[] = $m;
}
$event_months = array_values(array_unique(array_filter($event_months, fn($x)=>is_int($x) && $x>=0 && $x<=120)));
?>

<style>
  /* ===== Cards tổng quan chiều cao / cân nặng ===== */
  .bc-growth-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  @media(max-width:920px){.bc-growth-grid{grid-template-columns:1fr}}
  .bc-growth-card{background:#fff;border:1px solid #e9eef5;border-radius:16px;padding:18px 16px;
    box-shadow:0 10px 24px rgba(15,23,42,.06)}
  .bc-title{font-size:18px;font-weight:800;color:#0e172a;margin:0 0 6px}
  .bc-sub{color:#6b7280;font-size:12px;margin-bottom:10px}
  .bc-chip{display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#f1f5f9}
  .bc-chip.ok{background:#dcfce7;color:#065f46}
  .bc-chip.hi{background:#dbeafe;color:#1e40af}
  .bc-chip.lo{background:#fef3c7;color:#92400e}
  .bc-chip.warn{background:#fee2e2;color:#991b1b}
  .bc-metric{display:flex;align-items:baseline;gap:10px;margin:8px 0 6px}
  .bc-metric .big{font-size:32px;font-weight:900;color:#0f172a}
  .bc-metric .unit{font-size:13px;color:#6b7280}
  .bc-cols{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}
  @media(max-width:560px){.bc-cols{grid-template-columns:1fr}}
  .bc-box{background:#f8fafc;border:1px dashed #e5e7eb;border-radius:12px;padding:10px 12px}
  .bc-box .label{font-size:12px;color:#6b7280}
  .bc-box .val{font-weight:800}

  /* ===== Cards biểu đồ ===== */
  .bc-chart-grid{display:grid;grid-template-columns:1fr;gap:18px;margin-top:10px}
  .bc-chart-card{background:#fff;border:1px solid #e9eef5;border-radius:16px;padding:16px;
    box-shadow:0 10px 24px rgba(15,23,42,.06)}
  .bc-chart-wrap{height:360px}
  @media(max-width:560px){.bc-chart-wrap{height:300px}}
  .bc-legend{display:flex;gap:16px;align-items:center;margin:6px 0 8px 2px;color:#475569;font-size:12px}
  .bc-legend .d{display:inline-flex;gap:6px;align-items:center}
  .bc-legend .dot{width:10px;height:10px;border-radius:50%}
  .bc-legend .bar{width:2px;height:12px;background:#ef4444;border-radius:1px}
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
</style>

<!-- ====== 2 CARD: CHIỀU CAO / CÂN NẶNG (số liệu nhanh) ====== -->
<div class="bc-growth-grid">
  <div class="bc-growth-card">
    <div class="bc-title">Chiều cao theo tuổi (cm)</div>
    <div class="bc-sub">
      Tháng tuổi: <strong><?php echo $h($months!==null ? $months : '—'); ?></strong>
      <?php if ($bmi!==null): ?> • BMI: <strong><?php echo $h($bmi); ?></strong><?php endif; ?>
    </div>
    <div class="bc-metric">
      <div class="big"><?php echo $h($hNow!==null ? $hNow : '—'); ?></div>
      <div class="unit">cm</div>
      <span class="bc-chip <?php echo esc_attr($cH); ?>"><?php echo $h($tH); ?></span>
    </div>
    <div class="bc-cols">
      <div class="bc-box">
        <div class="label">Chuẩn WHO (gần nhất)</div>
        <div class="val"><?php echo $h($stdH!==null ? $stdH.' cm' : '—'); ?></div>
      </div>
      <div class="bc-box">
        <div class="label">Lệch so chuẩn</div>
        <div class="val">
          <?php echo $h($deltaH!==null ? (($deltaH>0?'+':'').$deltaH.' cm') : '—'); ?>
          <?php if ($bandH): ?> • <span class="label">Band:</span> <?php echo $h($bandH); ?><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="bc-growth-card">
    <div class="bc-title">Cân nặng theo tuổi (kg)</div>
    <div class="bc-sub">Tháng tuổi: <strong><?php echo $h($months!==null ? $months : '—'); ?></strong></div>
    <div class="bc-metric">
      <div class="big"><?php echo $h($wNow!==null ? $wNow : '—'); ?></div>
      <div class="unit">kg</div>
      <span class="bc-chip <?php echo esc_attr($cW); ?>"><?php echo $h($tW); ?></span>
    </div>
    <div class="bc-cols">
      <div class="bc-box">
        <div class="label">Chuẩn WHO (gần nhất)</div>
        <div class="val"><?php echo $h($stdW!==null ? $stdW.' kg' : '—'); ?></div>
      </div>
      <div class="bc-box">
        <div class="label">Lệch so chuẩn</div>
        <div class="val">
          <?php echo $h($deltaW!==null ? (($deltaW>0?'+':'').$deltaW.' kg') : '—'); ?>
          <?php if ($bandW): ?> • <span class="label">Band:</span> <?php echo $h($bandW); ?><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ====== 2 CARD: BIỂU ĐỒ (nằm TRƯỚC mục Thần số) ====== -->
<div class="bc-chart-grid">
  <div class="bccm-card">
    <div class="bc-title">Biểu đồ cân nặng của bé so với chuẩn WHO</div>
    <div class="bc-legend">
      <span class="d"><i class="dot" style="background:#60a5fa"></i>Chuẩn WHO (median)</span>
      <span class="d"><i class="dot" style="background:#f59e0b"></i>Số đo hiện tại</span>
      <span class="d"><i class="bar"></i>Mốc kiểm tra</span>
    </div>
    <div class="bc-chart-wrap"><canvas id="bcWeightChart"></canvas></div>
  </div>

  <div class="bccm-card">
    <div class="bc-title">Biểu đồ chiều cao của bé so với chuẩn WHO</div>
    <div class="bc-legend">
      <span class="d"><i class="dot" style="background:#60a5fa"></i>Chuẩn WHO (median)</span>
      <span class="d"><i class="dot" style="background:#f59e0b"></i>Số đo hiện tại</span>
      <span class="d"><i class="bar"></i>Mốc kiểm tra</span>
    </div>
    <div class="bc-chart-wrap"><canvas id="bcHeightChart"></canvas></div>
  </div>
</div>

<!-- ====== CHART.JS + PLUGIN VẠCH ĐỎ ====== -->
<script>
(function loadChart(render){
  if (window.Chart) return render();
  var s=document.createElement('script');
  s.src='https://cdn.jsdelivr.net/npm/chart.js';
  s.onload=render;
  document.head.appendChild(s);
})(function renderCharts(){
  const WHO_H = <?php echo wp_json_encode($whoH, JSON_UNESCAPED_UNICODE); ?> || [];
  const WHO_W = <?php echo wp_json_encode($whoW, JSON_UNESCAPED_UNICODE); ?> || [];
  const CUR_MONTH = <?php echo json_encode($months); ?>;
  const H_NOW = <?php echo json_encode($hNow); ?>;
  const W_NOW = <?php echo json_encode($wNow); ?>;
  const EVENT_MONTHS = <?php echo wp_json_encode($event_months); ?> || [];

  // Plugin kẻ vạch dọc mốc kiểm tra
  const verticalMarkers = {
    id: 'verticalMarkers',
    afterDatasetsDraw(chart, args, opts){
      const evts = (opts && Array.isArray(opts.events)) ? opts.events : [];
      if (!evts.length) return;
      const {ctx, chartArea, scales:{x}} = chart;
      ctx.save();
      ctx.strokeStyle = opts.color || '#ef4444';
      ctx.setLineDash(opts.dash || [4,3]);
      ctx.globalAlpha = .75;
      ctx.lineWidth = opts.lineWidth || 2;
      evts.forEach(m=>{
        const px = x.getPixelForValue(m);
        if (px>=chartArea.left && px<=chartArea.right){
          ctx.beginPath(); ctx.moveTo(px, chartArea.top); ctx.lineTo(px, chartArea.bottom); ctx.stroke();
        }
      });
      ctx.restore();
    }
  };
  Chart.register(verticalMarkers);

  // Chuẩn hoá WHO -> labels & values theo tháng
  function toSeries(tbl, maxM=120){
    const rows=[];
    (tbl||[]).forEach(r=>{
      const m = r.months ?? r.month ?? r.m ?? r.age_months ?? r.age;
      let v = r.standard ?? r.p50 ?? r.P50;
      if (m==null || v==null) return;
      let mm = parseInt(m,10), vv = parseFloat(v);
      if (!isFinite(mm) || !isFinite(vv)) return;
      if (vv>200) vv = vv/10; // fix cm nếu bị x10
      if (mm>=0 && mm<=maxM) rows.push({m:mm, v:vv});
    });
    rows.sort((a,b)=>a.m-b.m);
    return {labels: rows.map(x=>x.m), values: rows.map(x=>x.v)};
  }

  function renderLine(canvasId, labels, stdValues, pointVal, unit, events){
    const el = document.getElementById(canvasId); if(!el) return;
    const ctx = el.getContext('2d');
    const grad = ctx.createLinearGradient(0,0,0,320);
    grad.addColorStop(0,'rgba(96,165,250,.20)');
    grad.addColorStop(1,'rgba(96,165,250,0)');

    const datasets = [{
      label:'Chuẩn WHO (median)',
      data: stdValues,
      fill:true,
      borderColor:'#60a5fa',
      backgroundColor: grad,
      borderWidth:2,
      tension:.35,
      pointRadius:0
    }];

    // Điểm hiện tại (scatter 1 điểm)
    if (Number.isFinite(pointVal?.x) && Number.isFinite(pointVal?.y)){
      const scatter = labels.map(()=>null);
      let idx = labels.indexOf(pointVal.x);
      if (idx===-1){
        let best=0,dist=1e9; labels.forEach((m,i)=>{const d=Math.abs(m-pointVal.x);if(d<dist){dist=d;best=i;}});
        idx = best;
      }
      scatter[idx] = pointVal.y;
      datasets.push({
        label:'Số đo hiện tại',
        data: scatter,
        showLine:false,
        pointRadius:5,
        backgroundColor:'#f59e0b',
        borderColor:'#f59e0b'
      });
    }

    new Chart(ctx,{
      type:'line',
      data:{ labels, datasets },
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{ labels:{ boxWidth:12 } },
          tooltip:{ callbacks:{ label:(c)=> `${c.dataset.label}: ${c.parsed.y} ${unit}` } },
          verticalMarkers:{ events: events, color:'#ef4444', dash:[4,3], lineWidth:2 }
        },
        interaction:{ mode:'index', intersect:false },
        scales:{
          x:{ grid:{display:false}, title:{display:true,text:'Tháng tuổi'} },
          y:{ beginAtZero:false, title:{display:true,text:unit.toUpperCase()} }
        }
      }
    });
  }

  const H = toSeries(WHO_H, 120);
  const W = toSeries(WHO_W, 120);

  renderLine('bcWeightChart', W.labels, W.values, {x: CUR_MONTH, y: W_NOW}, 'kg', EVENT_MONTHS);
  renderLine('bcHeightChart', H.labels, H.values, {x: CUR_MONTH, y: H_NOW}, 'cm', EVENT_MONTHS);
});
</script>

<!-- ====== CARD: Thần số học rút gọn (giữ nguyên, nằm dưới biểu đồ) ====== -->
<div class="card">
  <div class="h2">Thần số học – Chân dung tính cách</div>
  <?php if (!$first9): ?>
    <p class="muted"><em>Chưa có dữ liệu “21 chỉ số”. Vào Admin → bấm “Tạo bản đồ Thần số học”.</em></p>
  <?php else: ?>
    <div class="metrics">
      <?php $i=0; foreach ($first9 as $key=>$info):
        $val   = isset($info['value']) ? $info['value'] : '—';
        $brief = isset($info['brief']) ? $info['brief'] : ($info['meaning'] ?? '—');
        $title = $titles[$key] ?? strtoupper($key);
        $alt   = (++$i>5) ? ' alt' : '';
      ?>
        <div class="tile<?php echo $alt; ?>">
          <div class="n"><?php echo $h($val); ?></div>
          <div class="t"><?php echo $h($title); ?></div>
          <div class="d"><?php echo $h($brief); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>


  <!-- CARD: Nhận xét tăng trưởng 
  <div class="card">
    <div class="h2">Nhận xét tăng trưởng hiện tại</div>
    <ul class="list">
      <li><strong>Chiều cao:</strong> <?php echo $h($growth_h ?: '—'); ?></li>
      <li><strong>Cân nặng:</strong> <?php echo $h($growth_w ?: '—'); ?></li>
      <li><strong>BMI:</strong> <?php echo $h(($growth_bmi ?: ($bmi!==null?$bmi:'—'))); ?></li>
    </ul>
    <?php if (!empty($growth_adv)): ?>
      <div class="h3">Gợi ý tức thời</div>
      <ul class="list">
        <?php foreach ($growth_adv as $it): ?><li><?php echo $h($it); ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>-->


  <?php
/* =======================
 * CARD: IQ MAP — 10 CHỨC NĂNG TƯ DUY (from iqmap_json)
 * ======================= */

$iq = isset($read_json) && is_callable($read_json) ? $read_json($coachee['iqmap_json'] ?? '') : [];
$iq_scores = is_array($iq['scores'] ?? null) ? $iq['scores'] : [];

$iq_labels = [
  'management'  => 'Quản lý',
  'logic_math'  => 'Logic/Toán học',
  'fine_motor'  => 'Vận động tinh',
  'language'    => 'Ngôn ngữ',
  'observation' => 'Quan sát',
  'leadership'  => 'Lãnh đạo',
  'imagination' => 'Tưởng tượng',
  'gross_motor' => 'Vận động thô',
  'auditory'    => 'Âm thanh',
  'aesthetic'   => 'Thẩm mỹ',
];

// Chuẩn hoá dữ liệu chart
$labels_js = [];
$data_js   = [];
foreach ($iq_labels as $k=>$vn){
  $labels_js[] = $vn;
  $data_js[]   = isset($iq_scores[$k]['score']) ? (float)$iq_scores[$k]['score'] : null;
}

// Một số ô tóm tắt
$iq_style   = $iq['dominant_profile']['style'] ?? '—';
$iq_hemi    = $iq['dominant_profile']['hemisphere'] ?? '';
$iq_why     = $iq['dominant_profile']['why'] ?? '';
$iq_actions = (array)($iq['dominant_profile']['parent_actions'] ?? []);
$iq_methods = (array)($iq['learning_recos']['methods'] ?? []);
$iq_mindmap = $iq['learning_recos']['mindmap'] ?? '';
$iq_tutor   = $iq['learning_recos']['tutor'] ?? '';
$iq_costudy = $iq['learning_recos']['co_study_with_mom'] ?? '';
$iq_potential = $iq['innate_talent']['potential'] ?? '';
$iq_signals   = (array)($iq['innate_talent']['signals'] ?? []);
$iq_weak      = $iq['innate_talent']['weakness'] ?? '';
$iq_weak_do   = (array)($iq['innate_talent']['what_to_do'] ?? []);
$iq_empathy   = isset($iq['empathy_index']['score']) ? (float)$iq['empathy_index']['score'] : null;
$iq_empathy_boost = (array)($iq['empathy_index']['boost'] ?? []);
?>

<style>
  /* ===== IQ MAP styles ===== */
  .iq-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:18px}
  @media(max-width:980px){.iq-grid{grid-template-columns:1fr}}
  .iq-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  @media(max-width:560px){.iq-stats{grid-template-columns:1fr}}
  .iq-tile{border-radius:14px;padding:12px;background:linear-gradient(135deg,#eef2ff,#ffffff);border:1px solid #e5e7eb}
  .iq-tile .n{font-weight:900;font-size:22px;color:#0f172a}
  .iq-tile .t{font-weight:700;font-size:13px;color:#334155}
  .iq-note{color:#6b7280;font-size:12px;margin-top:2px}
  .iq-chart-wrap{height:380px}
  @media(max-width:560px){.iq-chart-wrap{height:320px}}
</style>

<div class="card">
  <div class="h2">Bản đồ IQ – 10 chức năng tư duy</div>

  <?php if (empty($iq_scores)): ?>
    <p class="muted"><em>Chưa có <code>iqmap_json</code>. Vui lòng bấm “Tạo bản đồ IQ (Baby IQ Map)”.</em></p>
  <?php else: ?>
  <div class="iq-grid">
    <!-- Cột trái: Radar + các ô điểm -->
    <div>
      <div class="iq-chart-wrap"><canvas id="bcIQRadar"></canvas></div>

      <div class="h3" style="margin-top:12px">Điểm theo chức năng</div>
      <div class="iq-stats">
        <?php foreach ($iq_labels as $k=>$vn):
          $sc = isset($iq_scores[$k]['score']) ? round((float)$iq_scores[$k]['score'],1) : '—';
          $st = $iq_scores[$k]['strength'] ?? '';
          ?>
          <div class="iq-tile">
            <div class="t"><?php echo $h($vn); ?></div>
            <div class="n"><?php echo $h($sc); ?>/10</div>
            <?php if ($st): ?><div class="iq-note"><?php echo $h($st); ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Cột phải: Phong cách & gợi ý -->
    <div>
      <div class="h3">Phong cách đồng hành</div>
      <p><strong><?php echo $h($iq_style); ?></strong>
        <?php if ($iq_hemi): ?> • <span class="muted small"><?php echo $h($iq_hemi); ?></span><?php endif; ?>
      </p>
      <?php if ($iq_why): ?><p class="muted small"><?php echo $h($iq_why); ?></p><?php endif; ?>

      <?php if (!empty($iq_actions)): ?>
        <div class="h3">5 việc bố mẹ nên làm</div>
        <ul class="list"><?php foreach (array_slice($iq_actions,0,5) as $a) echo '<li>'.$h($a).'</li>'; ?></ul>
      <?php endif; ?>

      <div class="h3">Khuyến nghị học tập</div>
      <ul class="list">
        <?php if ($iq_mindmap): ?><li><strong>Mindmap:</strong> <?php echo $h($iq_mindmap); ?></li><?php endif; ?>
        <?php if ($iq_tutor):   ?><li><strong>Gia sư:</strong> <?php echo $h($iq_tutor); ?></li><?php endif; ?>
        <?php if ($iq_costudy): ?><li><strong>Mẹ học cùng:</strong> <?php echo $h($iq_costudy); ?></li><?php endif; ?>
        <?php foreach ($iq_methods as $m) echo '<li>'.$h($m).'</li>'; ?>
      </ul>

      <div class="h3">Thiên bẩm & điểm yếu</div>
      <p class="muted"><strong><?php echo $h($iq_potential ?: '—'); ?></strong></p>
      <?php if (!empty($iq_signals)): ?>
        <div><?php foreach ($iq_signals as $s) echo '<span class="pill">'.$h($s).'</span> '; ?></div>
      <?php endif; ?>
      <?php if ($iq_weak): ?>
        <p class="small"><strong>Điểm yếu:</strong> <?php echo $h($iq_weak); ?></p>
      <?php endif; ?>
      <?php if (!empty($iq_weak_do)): ?>
        <ul class="list"><?php foreach ($iq_weak_do as $d) echo '<li>'.$h($d).'</li>'; ?></ul>
      <?php endif; ?>

      <div class="h3">Chỉ số thấu cảm</div>
      <p>
        <strong><?php echo $h($iq_empathy!==null ? (round($iq_empathy,1).'/10') : '—'); ?></strong>
      </p>
      <?php if (!empty($iq_empathy_boost)): ?>
        <ul class="list"><?php foreach ($iq_empathy_boost as $b) echo '<li>'.$h($b).'</li>'; ?></ul>
      <?php endif; ?>
    </div>
  </div>

  <script>
    (function ensureChart(cb){
      if (window.Chart) return cb();
      var s=document.createElement('script');
      s.src='https://cdn.jsdelivr.net/npm/chart.js';
      s.onload=cb; document.head.appendChild(s);
    })(function(){
      const ctx = document.getElementById('bcIQRadar');
      if (!ctx) return;

      const labels = <?php echo wp_json_encode(array_values($labels_js), JSON_UNESCAPED_UNICODE); ?>;
      const data   = <?php echo wp_json_encode(array_values($data_js), JSON_UNESCAPED_UNICODE); ?>;

      new Chart(ctx, {
        type: 'radar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Điểm IQ map (0–10)',
            data: data,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,.18)',
            borderWidth: 2,
            pointRadius: 3
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            r: {
              suggestedMin: 0, suggestedMax: 10,
              ticks: { stepSize: 2 },
              grid: { color: 'rgba(2,6,23,.08)' },
              angleLines: { color: 'rgba(2,6,23,.08)' },
              pointLabels: { font: { size: 12 } }
            }
          }
        }
      });
    });
  </script>
  <?php endif; ?>
</div>
<?php
/* ============================================================
 * CARDS: (A) MI Career Stars  • (B) Summary Board
 * Nguồn: profiles.iqmap_json
 * ============================================================ */
$iq = isset($read_json) && is_callable($read_json) ? $read_json($coachee['iqmap_json'] ?? '') : [];

/* ---------- helpers ---------- */
$star = function($val){
  // val: 0..5 (float). Trả về 5 icon <i> tô màu đỏ theo phần nguyên, nửa sao nếu .5
  if (!is_numeric($val)) $val = 0;
  $v = max(0, min(5, (float)$val));
  $full = (int)floor($v);
  $half = ($v - $full) >= 0.5 ? 1 : 0;
  $empty= 5 - $full - $half;

  $buf = '';
  for($i=0;$i<$full;$i++) $buf .= '<i class="mi-star full"></i>';
  if ($half) $buf .= '<i class="mi-star half"></i>';
  for($i=0;$i<$empty;$i++) $buf .= '<i class="mi-star"></i>';
  return $buf;
};

$valOr = function($v,$d='—'){ return (isset($v) && $v!=='' && $v!==null) ? $v : $d; };

/* ---------- (A) MI – career domains ---------- */
$mi_default = [
  // slug => label (19 dòng như ảnh)
  'music'           => 'Âm nhạc',
  'agri_forestry'   => 'Nông nghiệp, Lâm nghiệp và Thuỷ sản',
  'construction'    => 'Xây dựng & Thiết kế',
  'engineering'     => 'Kỹ thuật',
  'earth_env'       => 'Trái đất & Môi trường',
  'life_science'    => 'Khoa học đời sống',
  'medicine'        => 'Y khoa',
  'education'       => 'Giáo dục',
  'finance'         => 'Tài chính',
  'mass_media'      => 'Thông tin & Truyền thông đại chúng',
  'it'              => 'Công nghệ thông tin',
  'literature'      => 'Văn chương',
  'social_psych'    => 'Xã hội học & Tâm lý học',
  'math_analytic'   => 'Toán học & Phân tích',
  'management'      => 'Quản lý',
  'politics'        => 'Chính trị',
  'foreign_lang'    => 'Ngoại ngữ',
  'sports'          => 'Thể thao',
  'arts'            => 'Nghệ thuật',
];
// Ghép dữ liệu thực tế nếu có
$mi_domains = [];
$src = is_array($iq['mi_domains'] ?? null) ? $iq['mi_domains'] : [];
foreach ($mi_default as $slug=>$label){
  $row = $src[$slug] ?? [];
  $mi_domains[] = [
    'label' => $label,
    'score' => isset($row['score']) ? (float)$row['score'] : null, // 0..5
    'note'  => isset($row['note']) ? (string)$row['note'] : '',
  ];
}

/* ---------- (B) Summary ---------- */
// 10 chỉ số cơ bản (dạng phần trăm)
$basic = is_array($iq['basic_indexes'] ?? null) ? $iq['basic_indexes'] : []; // ['EQ'=>13.14, 'IQ'=>...]
$basic_order = ['EQ','IQ','AQ','CQ','SQ','MQ','BQ','EntQ','JQ','PQ'];

// 10 chức năng – tách Não trái / Não phải
$scores = is_array($iq['scores'] ?? null) ? $iq['scores'] : [];
$left_keys  = ['management','logic_math','fine_motor','language','observation'];
$right_keys = ['leadership','imagination','gross_motor','auditory','aesthetic'];
$label_of = [
  'management'=>'Quản lý','logic_math'=>'Logic/Toán học','fine_motor'=>'Vận động tinh','language'=>'Ngôn ngữ','observation'=>'Quan sát',
  'leadership'=>'Lãnh đạo','imagination'=>'Tưởng tượng','gross_motor'=>'Vận động thô','auditory'=>'Âm thanh','aesthetic'=>'Thẩm mỹ',
];

// Phong cách học & phong cách đồng hành (nếu đã có từ iqmap_json)
$learn_styles = is_array($iq['learning_styles'] ?? null) ? $iq['learning_styles'] : []; // ví dụ: ['Thính giác'=>35.7, 'Thị giác'=>35.7, 'Vận động'=>28.5]
$dom_style    = $iq['dominant_profile']['style'] ?? '';
$dom_why      = $iq['dominant_profile']['why'] ?? '';
?>

<style>
  /* ===== CSS dùng chung cho 2 card ===== */
  .mi-stars{display:inline-flex;gap:4px;vertical-align:middle}
  .mi-star{width:14px;height:14px;display:inline-block;background:#e5e7eb;clip-path:polygon(50% 0%,61% 35%,98% 35%,68% 57%,79% 91%,50% 70%,21% 91%,32% 57%,2% 35%,39% 35%)}
  .mi-star.full{background:#ef4444}
  .mi-star.half{background:linear-gradient(90deg,#ef4444 0 50%,#e5e7eb 50% 100%)}
  .mi-table{width:100%;border-collapse:separate;border-spacing:0 6px}
  .mi-table th,.mi-table td{padding:10px 12px}
  .mi-table thead th{background:#ffedd5;color:#0f172a;font-weight:800;border-top-left-radius:10px;border-top-right-radius:10px}
  .mi-row{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px}
  .mi-row:nth-child(even){background:#f1f5f9}
  .mi-stt{width:56px;text-align:center;font-weight:700;color:#1f2937}
  .mi-note{color:#64748b;font-size:12px}

  .sum-badges{display:flex;flex-wrap:wrap;gap:8px}
  .sum-badge{background:#eef2ff;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-size:12px}
  .chips10{display:flex;flex-wrap:wrap;gap:8px}
  .chip10{background:linear-gradient(135deg,#e0f2fe,#fff);border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-size:12px}
  .brain-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:860px){.brain-grid{grid-template-columns:1fr}}
  .brain-card{background:#fff;border:1px solid #e9eef5;border-radius:14px;padding:12px}
  .brain-card h4{margin:0 0 6px;font-size:14px;font-weight:800;color:#0f172a}
  .brain-list{margin:0;padding:0;list-style:none}
  .brain-list li{display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-top:1px dashed #e5e7eb}
  .brain-list li:first-child{border-top:none}
  .pct{font-weight:800}
</style>

<!-- =================== (A) MI – Career Stars =================== -->
<div class="card">
  <div class="h2">Định hướng phát triển nghề nghiệp theo Thuyết đa thông minh</div>
  <table class="mi-table">
    <thead>
      <tr>
        <th class="mi-stt">STT</th>
        <th>Lĩnh vực</th>
        <th style="width:220px">Đánh giá</th>
      </tr>
    </thead>
    <tbody>
      <?php $i=0; foreach ($mi_domains as $r): $i++; ?>
        <tr class="mi-row">
          <td class="mi-stt"><?php echo $i; ?></td>
          <td>
            <div style="font-weight:700"><?php echo $h($r['label']); ?></div>
            <?php if (!empty($r['note'])): ?><div class="mi-note"><?php echo $h($r['note']); ?></div><?php endif; ?>
          </td>
          <td>
            <span class="mi-stars"><?php echo $star($r['score']); ?></span>
            <?php if (is_numeric($r['score'])): ?>
              <span class="mi-note">&nbsp;<?php echo $h(number_format((float)$r['score'],1)); ?>/5</span>
            <?php else: ?>
              <span class="mi-note">&nbsp;—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- =================== (B) Summary Board =================== -->
<div class="card">
  <div class="h2">Bảng thông số – Tổng hợp kết quả</div>

  <!-- 10 chỉ số cơ bản -->
  <div class="h3">10 chỉ số cơ bản</div>
  <div class="chips10">
    <?php foreach ($basic_order as $k):
      $v = isset($basic[$k]) ? (float)$basic[$k] : null; ?>
      <span class="chip10">
        <strong><?php echo $h($k); ?></strong>:
        <?php echo $h($v!==null ? number_format($v,2).'%' : '—'); ?>
      </span>
    <?php endforeach; ?>
  </div>

  <!-- Phong cách học + đồng hành -->
  <?php if ($learn_styles || !empty($dom_style)): ?>
    <div class="h3" style="margin-top:10px">Phong cách học & Đồng hành</div>
    <div class="sum-badges">
      <?php if ($learn_styles) {
        foreach ($learn_styles as $name=>$pct) {
          $pct_txt = is_numeric($pct) ? number_format((float)$pct,2).'%' : '—';
          echo '<span class="sum-badge"><strong>'.$h($name).'</strong>: '.$h($pct_txt).'</span>';
        }
      } ?>
      <?php if (!empty($dom_style)) { ?>
        <span class="sum-badge"><strong>Phong cách đồng hành:</strong> <?php echo $h($dom_style); ?></span>
      <?php } ?>
    </div>
    <?php if (!empty($dom_why)): ?><p class="mi-note" style="margin-top:6px"><?php echo $h($dom_why); ?></p><?php endif; ?>
  <?php endif; ?>

  <!-- Não trái / Não phải — 10 chức năng -->
  <div class="brain-grid" style="margin-top:12px">
    <div class="brain-card">
      <h4>Não trái</h4>
      <ul class="brain-list">
        <?php foreach ($left_keys as $k):
          $sc = isset($scores[$k]['score']) ? (float)$scores[$k]['score'] : null; ?>
          <li>
            <span><?php echo $h($label_of[$k]); ?></span>
            <span class="pct"><?php echo $h($sc!==null ? number_format($sc,1).'/10' : '—'); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="brain-card">
      <h4>Não phải</h4>
      <ul class="brain-list">
        <?php foreach ($right_keys as $k):
          $sc = isset($scores[$k]['score']) ? (float)$scores[$k]['score'] : null; ?>
          <li>
            <span><?php echo $h($label_of[$k]); ?></span>
            <span class="pct"><?php echo $h($sc!==null ? number_format($sc,1).'/10' : '—'); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>



  <!-- CARD: Phương pháp đồng hành -->
  <div class="card">
    <div class="h2">Phương pháp đồng hành</div>
    <div class="grid2">
      <div>
        <div class="h3">Dinh dưỡng</div>
        <?php if ($parent_nutri): ?>
          <ul class="list"><?php foreach ($parent_nutri as $x): ?><li><?php echo $h($x); ?></li><?php endforeach; ?></ul>
        <?php else: ?><p class="muted small"><em>Chưa có khuyến nghị dinh dưỡng.</em></p><?php endif; ?>

        <div class="h3">Kỷ luật tích cực</div>
        <?php if ($parent_disc): ?>
          <ul class="list"><?php foreach ($parent_disc as $x): ?><li><?php echo $h($x); ?></li><?php endforeach; ?></ul>
        <?php else: ?><p class="muted small"><em>Chưa có gợi ý kỷ luật.</em></p><?php endif; ?>
      </div>

      <div>
        <div class="h3">Học & chơi</div>
        <?php if ($parent_study): ?>
          <ul class="list"><?php foreach ($parent_study as $x): ?><li><?php echo $h($x); ?></li><?php endforeach; ?></ul>
        <?php else: ?><p class="muted small"><em>Chưa có gợi ý học & chơi.</em></p><?php endif; ?>

        <div class="h3">Bù đắp / Khắc phục</div>
        <?php if ($parent_comp): ?>
          <ul class="list"><?php foreach ($parent_comp as $x): ?><li><?php echo $h($x); ?></li><?php endforeach; ?></ul>
        <?php else: ?><p class="muted small"><em>Chưa có khuyến nghị bù đắp.</em></p><?php endif; ?>
      </div>
    </div>

    <?php if (!empty($talents)): ?>
      <div class="h3">Gợi ý năng khiếu / hoạt động</div>
      <div><?php foreach ($talents as $j) echo '<span class="pill">'.$h($j).'</span> '; ?></div>
    <?php endif; ?>
  </div>

  <!-- CARD: Định hướng khí chất & môi trường -->
  <div class="card">
    <div class="h2">Định hướng khí chất & môi trường</div>
    <div class="grid2">
      <div>
        <div class="h3">Sự nghiệp / Năng khiếu</div>
        <p class="muted"><?php echo $h($pick($destiny,'career','')); ?></p>
        <?php if (!empty($jobs_rec)): ?>
          <div class="h3">Hoạt động nên khuyến khích</div>
          <div><?php foreach ($jobs_rec as $j) echo '<span class="pill">'.$h($j).'</span> '; ?></div>
        <?php endif; ?>
      </div>
      <div>
        <div class="h3">Sứ mệnh / Động lực</div>
        <p class="muted"><?php echo $h($pick($destiny,'mission','')); ?></p>
        <?php if (!empty($lucky)): ?>
          <div class="h3">Màu sắc tốt cho bé</div>
          <div><?php foreach ($lucky as $c) echo '<span class="pill">'.$h($c).'</span> '; ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($to_prac) || !empty($to_avoid)): ?>
      <div class="grid2" style="margin-top:8px">
        <div>
          <div class="h3">Cần rèn luyện</div>
          <ul class="list"><?php foreach ($to_prac as $it) echo '<li>'.$h($it).'</li>'; ?></ul>
        </div>
        <div>
          <div class="h3">Cần tránh</div>
          <ul class="list"><?php foreach ($to_avoid as $it) echo '<li>'.$h($it).'</li>'; ?></ul>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- CARD: Dự cảm thời gian tới -->
  <div class="card">
    <div class="h2">Dự cảm thời gian tới</div>
    <ul class="list">
      <li><strong>Kết nối & bạn bè:</strong> <?php echo $h($fortune['colleagues'] ?? ''); ?></li>
      <li><strong>Trải nghiệm / Hoạt động:</strong> <?php echo $h($fortune['career'] ?? ''); ?></li>
      <li><strong>Niềm vui & may mắn:</strong> <?php echo $h($fortune['luck'] ?? ''); ?></li>
    </ul>
  </div>


</div>
