<?php if (!defined('ABSPATH')) exit;
/**
 * Frontend Template: AstroCoach – Bản đồ Chiêm tinh
 * Hiển thị: Thần số học + Life Map + Health Map + Milestone Calendar
 */

global $wpdb;
$t = bccm_tables();

$planRow = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$t['plans']} WHERE public_key=%s AND status='active'", $key
), ARRAY_A);

if (!$planRow) {
  status_header(404);
  echo '<div class="bccm-public"><h1>Không tìm thấy bản đồ</h1></div>';
  return;
}

$profile = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$t['profiles']} WHERE id=%d", $planRow['coachee_id']
), ARRAY_A);

// Decode JSON data
$ai_summary = json_decode($profile['ai_summary'] ?? '[]', true) ?: [];
$health     = json_decode($profile['health_json'] ?? '[]', true) ?: [];
$life_map   = json_decode($profile['mental_json'] ?? '[]', true) ?: [];
$milestone  = json_decode($profile['bizcoach_json'] ?? '[]', true) ?: [];
$planData   = json_decode($planRow['plan'] ?? '[]', true) ?: [];

if (isset($planData['phases'])) {
  $milestone = array_merge($milestone, $planData);
}

// Destiny (thần số học)
$destiny = $ai_summary;
$numbers_full_db = bccm_get_metrics_numbers_full(intval($planRow['coachee_id']));
if (!empty($numbers_full_db)) {
  $destiny['numbers_full'] = $numbers_full_db;
}

$name = $profile['full_name'] ?? 'Chủ nhân';
$dob  = $profile['dob'] ?? '';
$birth_place = $profile['birth_place'] ?? '';
$birth_time  = $profile['birth_time'] ?? '';
$zodiac_sign = $profile['zodiac_sign'] ?? '';
$zodiac_labels = [
  'aries'=>'Bạch Dương ♈','taurus'=>'Kim Ngưu ♉','gemini'=>'Song Tử ♊',
  'cancer'=>'Cự Giải ♋','leo'=>'Sư Tử ♌','virgo'=>'Xử Nữ ♍',
  'libra'=>'Thiên Bình ♎','scorpio'=>'Bọ Cạp ♏','sagittarius'=>'Nhân Mã ♐',
  'capricorn'=>'Ma Kết ♑','aquarius'=>'Bảo Bình ♒','pisces'=>'Song Ngư ♓',
];
$zodiac_vn = $zodiac_labels[strtolower($zodiac_sign)] ?? ucfirst($zodiac_sign);
?>

<style>
:root {
  --ac-primary: #7c3aed;
  --ac-primary-dark: #5b21b6;
  --ac-accent: #f59e0b;
  --ac-danger: #ef4444;
  --ac-blue: #3b82f6;
  --ac-teal: #14b8a6;
  --ac-pink: #ec4899;
  --ac-dark: #0f172a;
  --ac-gray: #64748b;
  --ac-light: #faf5ff;
  --ac-radius: 16px;
  --ac-shadow: 0 8px 24px rgba(124,58,237,.15);
  --ac-glow: 0 0 20px rgba(124,58,237,.25);
}

body {
  margin: 0 !important; padding: 0 !important;
  background: linear-gradient(135deg, #1e1b4b 0%, #312e81 30%, #4c1d95 60%, #1e1b4b 100%);
  font-family: Inter, system-ui, Arial, sans-serif;
  color: #e2e8f0;
  min-height: 100vh;
}

.ac-container { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }

.ac-hero {
  text-align: center; padding: 48px 20px 36px;
}
.ac-hero .title {
  font-size: 36px; font-weight: 900;
  background: linear-gradient(90deg, #c084fc, #f9a8d4, #fbbf24);
  -webkit-background-clip: text; color: transparent;
}
.ac-hero .subtitle { color: #a78bfa; font-size: 16px; margin-top: 8px; }
.ac-hero .zodiac-badge {
  display: inline-block; margin-top: 12px; padding: 8px 24px;
  border-radius: 999px; font-size: 18px; font-weight: 700;
  background: linear-gradient(135deg, #7c3aed, #ec4899);
  color: #fff; box-shadow: var(--ac-glow);
}

.ac-card {
  border-radius: var(--ac-radius); padding: 24px; margin: 18px 0;
  background: rgba(30,27,75,.85);
  backdrop-filter: blur(12px);
  box-shadow: var(--ac-shadow);
  border: 1px solid rgba(124,58,237,.3);
}
.ac-card:hover { transform: translateY(-2px); transition: .2s ease; border-color: rgba(124,58,237,.6); }

.ac-h2 {
  font-size: 22px; font-weight: 800; margin: 0 0 16px;
  color: #c084fc;
  display: flex; align-items: center; gap: 10px;
}
.ac-h3 { font-size: 17px; font-weight: 700; margin: 14px 0 8px; color: #e2e8f0; }

.ac-badge {
  display: inline-block; padding: 4px 14px;
  border-radius: 999px; font-size: 12px; font-weight: 700;
  color: #fff; margin: 0 4px 4px 0;
}
.ac-badge-purple { background: var(--ac-primary); }
.ac-badge-pink { background: var(--ac-pink); }
.ac-badge-amber { background: var(--ac-accent); }
.ac-badge-teal { background: var(--ac-teal); }
.ac-badge-blue { background: var(--ac-blue); }
.ac-badge-red { background: var(--ac-danger); }

.ac-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.ac-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 16px; }
@media (max-width: 768px) { .ac-grid-2, .ac-grid-3 { grid-template-columns: 1fr; } }

.ac-progress-bar { height: 8px; background: rgba(255,255,255,.1); border-radius: 4px; overflow: hidden; margin: 4px 0; }
.ac-progress-fill { height: 100%; border-radius: 4px; transition: width .5s ease; }

.ac-list { list-style: none; padding: 0; margin: 6px 0; }
.ac-list li { padding: 4px 0 4px 20px; position: relative; font-size: 14px; }
.ac-list li::before { content: '✦'; position: absolute; left: 0; color: #c084fc; font-weight: 700; }

.ac-tile {
  border-radius: 16px; padding: 18px; text-align: center;
  font-weight: 600; color: #fff;
  background: linear-gradient(135deg, #7c3aed, #4c1d95);
  box-shadow: var(--ac-glow);
}
.ac-tile.alt { background: linear-gradient(135deg, #ec4899, #be185d); }
.ac-tile .num { font-size: 32px; font-weight: 900; margin-bottom: 6px; }
.ac-tile .label { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; }
.ac-tile .desc { font-size: 12px; opacity: .85; margin-top: 4px; }

.ac-timeline { position: relative; padding-left: 28px; }
.ac-timeline::before {
  content: ''; position: absolute; left: 10px; top: 0; bottom: 0;
  width: 3px; background: linear-gradient(180deg, #7c3aed, #ec4899, #fbbf24);
  border-radius: 2px;
}
.ac-timeline-item { position: relative; margin-bottom: 24px; }
.ac-timeline-dot {
  position: absolute; left: -24px; top: 4px;
  width: 14px; height: 14px; border-radius: 50%;
  background: #c084fc; border: 3px solid rgba(30,27,75,.85);
  box-shadow: 0 0 0 2px #c084fc;
}

.ac-week-card {
  background: rgba(124,58,237,.15); border-radius: 12px; padding: 16px;
  border-left: 4px solid #7c3aed; margin: 8px 0;
}
.ac-week-card:nth-child(even) { border-left-color: #ec4899; }

@media print {
  body { background: #1e1b4b !important; }
  .ac-card { box-shadow: none !important; break-inside: avoid; }
}
</style>

<div class="ac-container">

<!-- HERO -->
<div class="ac-hero">
  <div class="title">✨ Bản Đồ Chiêm Tinh</div>
  <div class="subtitle"><?php echo esc_html($name); ?> <?php if ($dob) echo '• ' . esc_html($dob); ?></div>
  <?php if ($zodiac_vn): ?>
    <div class="zodiac-badge"><?php echo esc_html($zodiac_vn); ?></div>
  <?php endif; ?>
</div>

<!-- ASTRO PROFILE -->
<div class="ac-card">
  <h2 class="ac-h2">🌌 Hồ Sơ Chiêm Tinh</h2>
  <div class="ac-grid-3">
    <?php if ($zodiac_vn): ?>
    <div class="ac-tile">
      <div class="num"><?php echo esc_html(strtoupper(substr($zodiac_sign,0,3))); ?></div>
      <div class="label">Cung hoàng đạo</div>
      <div class="desc"><?php echo esc_html($zodiac_vn); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($birth_time): ?>
    <div class="ac-tile alt">
      <div class="num"><?php echo esc_html($birth_time); ?></div>
      <div class="label">Giờ sinh</div>
      <div class="desc"><?php echo esc_html($birth_place ?: '—'); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($dob): ?>
    <div class="ac-tile">
      <div class="num"><?php echo esc_html(date('d/m', strtotime($dob))); ?></div>
      <div class="label">Ngày sinh</div>
      <div class="desc"><?php echo esc_html(date('Y', strtotime($dob))); ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- NUMEROLOGY -->
<?php if (!empty($destiny['numbers_full'])): ?>
<div class="ac-card">
  <h2 class="ac-h2">🔢 Bản Đồ Thần Số Học</h2>
  <div class="ac-grid-3">
  <?php
  $titles = function_exists('bccm_metric_titles') ? bccm_metric_titles() : [];
  foreach ($destiny['numbers_full'] as $mk => $mv):
    $val   = $mv['value'] ?? '—';
    $title = $titles[$mk] ?? ucwords(str_replace('_',' ',$mk));
    $brief = $mv['brief'] ?? '—';
    $alt   = (array_search($mk, array_keys($destiny['numbers_full'])) % 2 === 1) ? ' alt' : '';
  ?>
  <div class="ac-tile<?php echo $alt; ?>">
    <div class="num"><?php echo esc_html($val); ?></div>
    <div class="label"><?php echo esc_html($title); ?></div>
    <div class="desc"><?php echo esc_html(mb_substr($brief,0,80)); ?></div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- AI OVERVIEW -->
<?php if (!empty($ai_summary['overview'])): ?>
<div class="ac-card">
  <h2 class="ac-h2">🔮 Nhận Xét Tổng Quan</h2>
  <p style="font-size:15px;line-height:1.7;color:#cbd5e1"><?php echo nl2br(esc_html($ai_summary['overview'])); ?></p>

  <?php if (!empty($ai_summary['strengths'])): ?>
  <h3 class="ac-h3">💎 Điểm mạnh</h3>
  <ul class="ac-list">
    <?php foreach ((array)$ai_summary['strengths'] as $s): ?>
      <li><?php echo esc_html($s); ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <?php if (!empty($ai_summary['risk_factors'])): ?>
  <h3 class="ac-h3">⚠️ Cần lưu ý</h3>
  <ul class="ac-list">
    <?php foreach ((array)$ai_summary['risk_factors'] as $r): ?>
      <li><?php echo esc_html($r); ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- LIFE MAP -->
<?php if (!empty($life_map)): ?>
<div class="ac-card">
  <h2 class="ac-h2">🗺️ Bản Đồ Cuộc Đời</h2>

  <?php if (!empty($life_map['identity'])): $id = $life_map['identity']; ?>
  <div style="background:rgba(124,58,237,.12);border-radius:12px;padding:20px;margin-bottom:16px">
    <h3 class="ac-h3" style="margin-top:0">🆔 Nhận Dạng Cá Nhân</h3>
    <?php if (!empty($id['life_stage'])): ?>
      <p><strong>Giai đoạn cuộc đời:</strong> <?php echo esc_html($id['life_stage']); ?></p>
    <?php endif; ?>
    <?php if (!empty($id['core_values'])): ?>
      <p><strong>Giá trị cốt lõi:</strong>
        <?php foreach ($id['core_values'] as $v): ?>
          <span class="ac-badge ac-badge-purple"><?php echo esc_html($v); ?></span>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <?php if (!empty($id['personality_traits'])): ?>
      <p><strong>Tính cách:</strong>
        <?php foreach ($id['personality_traits'] as $t2): ?>
          <span class="ac-badge ac-badge-pink"><?php echo esc_html($t2); ?></span>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <?php if (!empty($id['communication_style'])): ?>
      <p><strong>Phong cách giao tiếp:</strong> <?php echo esc_html($id['communication_style']); ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Life Dimensions -->
  <?php if (!empty($life_map['life_dimensions'])): ?>
  <h3 class="ac-h3">📐 Các Chiều Cuộc Sống</h3>
  <div class="ac-grid-2">
    <?php
    $ldim_icons = ['career'=>'💼','health'=>'🏥','relationships'=>'❤️','finance'=>'💰','personal_growth'=>'🌱','lifestyle'=>'🏠'];
    foreach ($life_map['life_dimensions'] as $dk => $dv):
      $sc = intval($dv['score'] ?? 0);
      $fg = $sc >= 7 ? '#10b981' : ($sc >= 4 ? '#f59e0b' : '#ef4444');
      $icon = $ldim_icons[$dk] ?? '📊';
      $label = ucwords(str_replace('_',' ',$dk));
    ?>
    <div style="background:rgba(124,58,237,.1);border-radius:12px;padding:16px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <span style="font-size:20px"><?php echo $icon; ?></span>
        <strong><?php echo esc_html($label); ?></strong>
        <span style="margin-left:auto;font-weight:900;color:<?php echo $fg; ?>"><?php echo $sc; ?>/10</span>
      </div>
      <div class="ac-progress-bar">
        <div class="ac-progress-fill" style="width:<?php echo $sc*10; ?>%;background:<?php echo $fg; ?>"></div>
      </div>
      <?php if (!empty($dv['detail'])): ?>
        <p style="font-size:13px;color:#94a3b8;margin:6px 0 0"><?php echo esc_html($dv['detail']); ?></p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Pain Points -->
  <?php if (!empty($life_map['pain_points'])): ?>
  <h3 class="ac-h3">🔥 Nỗi Đau & Thách Thức</h3>
  <div class="ac-grid-2">
    <?php foreach ($life_map['pain_points'] as $pp):
      $impact = intval($pp['impact_level'] ?? 0);
      $bg_col = $impact >= 7 ? 'rgba(239,68,68,.15)' : ($impact >= 4 ? 'rgba(245,158,11,.1)' : 'rgba(16,185,129,.1)');
    ?>
    <div style="background:<?php echo $bg_col; ?>;border-radius:12px;padding:16px">
      <strong><?php echo esc_html($pp['area'] ?? ''); ?></strong>
      <span class="ac-badge ac-badge-<?php echo $impact>=7?'red':($impact>=4?'amber':'teal'); ?>"><?php echo $impact; ?>/10</span>
      <p style="font-size:13px;color:#94a3b8;margin:6px 0 0"><?php echo esc_html($pp['description'] ?? ''); ?></p>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Motivations -->
  <?php if (!empty($life_map['motivations'])): ?>
  <h3 class="ac-h3">🚀 Động Lực</h3>
  <?php foreach ($life_map['motivations'] as $mot): ?>
  <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(124,58,237,.2)">
    <span style="font-size:18px"><?php echo esc_html($mot['type'] ?? '—') === 'intrinsic' ? '🔥' : '🎯'; ?></span>
    <div style="flex:1">
      <strong><?php echo esc_html($mot['area'] ?? ''); ?></strong>
      <p style="margin:2px 0;font-size:13px;color:#94a3b8"><?php echo esc_html($mot['description'] ?? ''); ?></p>
    </div>
    <span style="font-weight:900;color:#c084fc"><?php echo intval($mot['strength_level'] ?? 0); ?>/10</span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Life Vision -->
  <?php if (!empty($life_map['life_vision'])): $v = $life_map['life_vision']; ?>
  <h3 class="ac-h3">🔮 Tầm Nhìn Cuộc Đời</h3>
  <div style="background:linear-gradient(135deg,rgba(124,58,237,.2),rgba(236,72,153,.15));border-radius:12px;padding:20px">
    <?php if (!empty($v['1_year'])): ?><p><strong>1 năm:</strong> <?php echo esc_html($v['1_year']); ?></p><?php endif; ?>
    <?php if (!empty($v['5_year'])): ?><p><strong>5 năm:</strong> <?php echo esc_html($v['5_year']); ?></p><?php endif; ?>
    <?php if (!empty($v['10_year'])): ?><p><strong>10 năm:</strong> <?php echo esc_html($v['10_year']); ?></p><?php endif; ?>
    <?php if (!empty($v['life_purpose'])): ?><p><strong>Mục đích sống:</strong> <?php echo esc_html($v['life_purpose']); ?></p><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- HEALTH MAP -->
<?php if (!empty($health)): ?>
<div class="ac-card">
  <h2 class="ac-h2">🏋️ Kế Hoạch Sức Khoẻ</h2>

  <?php if (!empty($health['nutrition_plan'])): $np = $health['nutrition_plan']; ?>
  <h3 class="ac-h3">🥗 Dinh Dưỡng</h3>
  <div class="ac-grid-3" style="margin-bottom:12px">
    <div class="ac-tile"><div class="num"><?php echo intval($np['daily_calories'] ?? 0); ?></div><div class="label">Calories/ngày</div></div>
    <div class="ac-tile alt"><div class="num"><?php echo intval($np['protein_g'] ?? 0); ?>g</div><div class="label">Protein</div></div>
    <div class="ac-tile"><div class="num"><?php echo intval($np['water_ml'] ?? 0); ?>ml</div><div class="label">Nước</div></div>
  </div>
  <?php endif; ?>

  <?php if (!empty($health['exercise_plan'])): $ep = $health['exercise_plan']; ?>
  <h3 class="ac-h3">🏃 Tập Luyện (<?php echo intval($ep['weekly_sessions'] ?? 0); ?> buổi/tuần)</h3>
  <?php if (!empty($ep['plan'])): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($ep['plan'] as $ex): ?>
    <div class="ac-badge ac-badge-teal"><?php echo esc_html(($ex['day'] ?? '').' – '.($ex['activity'] ?? '')); ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($health['sleep_plan'])): $sp = $health['sleep_plan']; ?>
  <h3 class="ac-h3">😴 Giấc Ngủ</h3>
  <p><?php echo esc_html(($sp['bedtime'] ?? '').' – '.($sp['wake_time'] ?? '')); ?> (<?php echo esc_html($sp['hours'] ?? ''); ?>h)</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- COACHING STRATEGY -->
<?php if (!empty($life_map['coaching_strategy'])): $cs = $life_map['coaching_strategy']; ?>
<div class="ac-card">
  <h2 class="ac-h2">🤖 Chiến Lược Đồng Hành AI</h2>
  <?php if (!empty($cs['approach'])): ?><p><strong>Phương pháp:</strong> <?php echo esc_html($cs['approach']); ?></p><?php endif; ?>
  <?php if (!empty($cs['tone'])): ?><p><strong>Giọng điệu:</strong> <?php echo esc_html($cs['tone']); ?></p><?php endif; ?>
  <?php if (!empty($cs['focus_areas'])): ?>
    <p><strong>Lĩnh vực tập trung:</strong>
      <?php foreach ($cs['focus_areas'] as $fa): ?>
        <span class="ac-badge ac-badge-purple"><?php echo esc_html($fa); ?></span>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
  <?php if (!empty($cs['check_in_frequency'])): ?><p><strong>Tần suất check-in:</strong> <?php echo esc_html($cs['check_in_frequency']); ?></p><?php endif; ?>
</div>
<?php endif; ?>

<!-- MILESTONE CALENDAR -->
<?php if (!empty($milestone['phases'])): ?>
<div class="ac-card">
  <h2 class="ac-h2">📅 Hành Trình 90 Ngày</h2>
  <?php if (!empty($milestone['meta']['objective'])): ?>
    <p style="font-size:15px;color:#94a3b8"><?php echo esc_html($milestone['meta']['objective']); ?></p>
  <?php endif; ?>

  <?php
  $phase_colors = ['rgba(124,58,237,.15)','rgba(236,72,153,.12)','rgba(251,191,36,.1)'];
  foreach ($milestone['phases'] as $pi => $phase):
  ?>
  <div style="margin:20px 0;background:<?php echo $phase_colors[$pi % 3]; ?>;border-radius:16px;padding:20px">
    <h3 class="ac-h3" style="margin-top:0;color:#c084fc"><?php echo esc_html($phase['title'] ?? "Phase ".($pi+1)); ?></h3>

    <div class="ac-timeline">
      <?php foreach ($phase['weeks'] ?? [] as $wk): ?>
      <div class="ac-timeline-item">
        <div class="ac-timeline-dot"></div>
        <div class="ac-week-card">
          <strong>Tuần <?php echo esc_html($wk['week'] ?? ''); ?></strong>
          <?php if (!empty($wk['theme'] ?? $wk['title'] ?? '')): ?>
            – <?php echo esc_html($wk['theme'] ?? $wk['title'] ?? ''); ?>
          <?php endif; ?>
          <?php if (!empty($wk['goal'])): ?>
            <p style="margin:6px 0 0;font-size:13px;color:#94a3b8">🎯 <?php echo esc_html($wk['goal']); ?></p>
          <?php endif; ?>
          <?php if (!empty($wk['milestone'])): ?>
            <p style="margin:4px 0 0;font-size:13px;color:#c084fc">🏁 <?php echo esc_html($wk['milestone']); ?></p>
          <?php endif; ?>
          <?php if (!empty($wk['daily_habits'])): ?>
            <ul class="ac-list" style="margin-top:6px">
              <?php foreach ($wk['daily_habits'] as $h): ?><li><?php echo esc_html($h); ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Rewards -->
  <?php if (!empty($milestone['rewards'])): ?>
  <h3 class="ac-h3">🎁 Phần Thưởng Cột Mốc</h3>
  <div class="ac-grid-3">
    <?php foreach ($milestone['rewards'] as $rw): ?>
    <div class="ac-tile">
      <div class="label"><?php echo esc_html($rw['milestone'] ?? ''); ?></div>
      <div class="desc"><?php echo esc_html($rw['reward'] ?? ''); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- WEEKLY ROUTINES -->
<?php if (!empty($life_map['weekly_routines'])): $wr = $life_map['weekly_routines']; ?>
<div class="ac-card">
  <h2 class="ac-h2">🔄 Thói Quen Hàng Tuần</h2>
  <div class="ac-grid-2">
    <?php if (!empty($wr['morning_routine'])): ?>
    <div>
      <h3 class="ac-h3">🌅 Buổi sáng</h3>
      <ul class="ac-list"><?php foreach ($wr['morning_routine'] as $r): ?><li><?php echo esc_html($r); ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <?php if (!empty($wr['evening_routine'])): ?>
    <div>
      <h3 class="ac-h3">🌙 Buổi tối</h3>
      <ul class="ac-list"><?php foreach ($wr['evening_routine'] as $r): ?><li><?php echo esc_html($r); ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div style="text-align:center;padding:30px;color:#a78bfa;font-size:13px">
  <p>✨ Bản Đồ Chiêm Tinh cùng <?php echo esc_html($name); ?> – Powered by BizCoach AI</p>
  <p style="font-size:11px">Generated: <?php echo date('d/m/Y H:i'); ?></p>
</div>

</div><!-- /.ac-container -->
