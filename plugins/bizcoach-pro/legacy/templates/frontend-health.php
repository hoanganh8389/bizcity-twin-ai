<?php if (!defined('ABSPATH')) exit;
/**
 * Frontend Template: HealthCoach & Life Map
 * Hiển thị: Bản đồ cuộc đời + Milestone Calendar + Health Map
 * Hỗ trợ Save PDF qua html2pdf (đã có ở frontend.php)
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

// Merge plan data + milestone
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
?>

<style>
:root {
  --hc-primary: #10b981;
  --hc-primary-dark: #059669;
  --hc-accent: #f59e0b;
  --hc-danger: #ef4444;
  --hc-blue: #3b82f6;
  --hc-purple: #8b5cf6;
  --hc-dark: #0f172a;
  --hc-gray: #64748b;
  --hc-light: #f8fafc;
  --hc-radius: 16px;
  --hc-shadow: 0 8px 24px rgba(0,0,0,.12);
}

body {
  margin: 0 !important; padding: 0 !important;
  background: linear-gradient(135deg, #ecfdf5 0%, #f0f9ff 50%, #fef3c7 100%);
  font-family: Inter, system-ui, Arial, sans-serif;
  color: var(--hc-dark);
}

.hc-container { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }

.hc-hero {
  text-align: center; padding: 40px 20px 30px;
}
.hc-hero .title {
  font-size: 34px; font-weight: 900;
  background: linear-gradient(90deg, var(--hc-primary), var(--hc-blue));
  -webkit-background-clip: text; color: transparent;
}
.hc-hero .subtitle { color: var(--hc-gray); font-size: 16px; margin-top: 8px; }

.hc-card {
  border-radius: var(--hc-radius);
  padding: 24px; margin: 18px 0;
  background: #fff;
  box-shadow: var(--hc-shadow);
  border: 1px solid #e2e8f0;
}
.hc-card:hover { transform: translateY(-2px); transition: .2s ease; }

.hc-h2 {
  font-size: 22px; font-weight: 800; margin: 0 0 16px;
  color: var(--hc-primary-dark);
  display: flex; align-items: center; gap: 10px;
}
.hc-h3 { font-size: 17px; font-weight: 700; margin: 14px 0 8px; color: var(--hc-dark); }

.hc-badge {
  display: inline-block; padding: 4px 14px;
  border-radius: 999px; font-size: 12px; font-weight: 700;
  color: #fff; margin: 0 4px 4px 0;
}
.hc-badge-green { background: var(--hc-primary); }
.hc-badge-amber { background: var(--hc-accent); }
.hc-badge-red   { background: var(--hc-danger); }
.hc-badge-blue  { background: var(--hc-blue); }
.hc-badge-purple { background: var(--hc-purple); }

.hc-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.hc-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media (max-width: 768px) {
  .hc-grid-2, .hc-grid-3 { grid-template-columns: 1fr; }
}

/* Score Gauge */
.hc-gauge {
  width: 100px; height: 100px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 28px; font-weight: 900; color: #fff;
  margin: 0 auto 8px;
}

/* Progress bar */
.hc-progress-bar {
  height: 8px; background: #e2e8f0; border-radius: 4px;
  overflow: hidden; margin: 4px 0;
}
.hc-progress-fill {
  height: 100%; border-radius: 4px;
  transition: width .5s ease;
}

/* Timeline */
.hc-timeline { position: relative; padding-left: 28px; }
.hc-timeline::before {
  content: ''; position: absolute; left: 10px; top: 0; bottom: 0;
  width: 3px; background: linear-gradient(180deg, var(--hc-primary), var(--hc-blue));
  border-radius: 2px;
}
.hc-timeline-item { position: relative; margin-bottom: 24px; }
.hc-timeline-dot {
  position: absolute; left: -24px; top: 4px;
  width: 14px; height: 14px; border-radius: 50%;
  background: var(--hc-primary); border: 3px solid #fff;
  box-shadow: 0 0 0 2px var(--hc-primary);
}
.hc-timeline-item:nth-child(even) .hc-timeline-dot { background: var(--hc-blue); box-shadow: 0 0 0 2px var(--hc-blue); }

.hc-week-card {
  background: var(--hc-light); border-radius: 12px; padding: 16px;
  border-left: 4px solid var(--hc-primary); margin: 8px 0;
}
.hc-week-card:nth-child(even) { border-left-color: var(--hc-blue); }

.hc-list { list-style: none; padding: 0; margin: 6px 0; }
.hc-list li { padding: 4px 0 4px 20px; position: relative; font-size: 14px; }
.hc-list li::before { content: '✓'; position: absolute; left: 0; color: var(--hc-primary); font-weight: 700; }

.hc-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.hc-table th { background: var(--hc-primary); color: #fff; padding: 10px 14px; text-align: left; }
.hc-table td { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; }
.hc-table tr:hover td { background: #f0fdf4; }

@media print {
  body { background: #fff !important; }
  .hc-card { box-shadow: none !important; border: 1px solid #ddd !important; break-inside: avoid; }
}
</style>

<div class="hc-container">

<!-- ===================== HERO ===================== -->
<div class="hc-hero">
  <div class="title">🌱 Bản Đồ Cuộc Đời</div>
  <div class="subtitle"><?php echo esc_html($name); ?> <?php if ($dob) echo '• ' . esc_html($dob); ?></div>
</div>

<!-- ===================== HEALTH SCORE ===================== -->
<?php if (!empty($ai_summary['health_score']) || !empty($ai_summary['wellness_dimensions'])): ?>
<div class="hc-card">
  <h2 class="hc-h2">🏥 Tổng Quan Sức Khỏe</h2>

  <?php if (!empty($ai_summary['overview'])): ?>
    <p style="font-size:15px;line-height:1.7;color:var(--hc-gray)"><?php echo esc_html($ai_summary['overview']); ?></p>
  <?php endif; ?>

  <div class="hc-grid-2" style="margin-top:16px">
    <!-- Health Score -->
    <div style="text-align:center">
      <?php
      $score = intval($ai_summary['health_score'] ?? 0);
      $color = $score >= 70 ? 'var(--hc-primary)' : ($score >= 40 ? 'var(--hc-accent)' : 'var(--hc-danger)');
      ?>
      <div class="hc-gauge" style="background:<?php echo $color; ?>"><?php echo $score; ?></div>
      <div style="font-weight:700;font-size:14px">Health Score</div>
    </div>

    <!-- BMI -->
    <?php if (!empty($ai_summary['bmi'])): ?>
    <div style="text-align:center">
      <div class="hc-gauge" style="background:var(--hc-blue)"><?php echo esc_html($ai_summary['bmi']['value'] ?? '—'); ?></div>
      <div style="font-weight:700;font-size:14px">BMI – <?php echo esc_html($ai_summary['bmi']['category'] ?? ''); ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Wellness Dimensions -->
  <?php if (!empty($ai_summary['wellness_dimensions'])): ?>
  <h3 class="hc-h3">📊 6 Chiều Khoẻ</h3>
  <div class="hc-grid-3">
    <?php
    $dim_icons = ['physical' => '💪', 'mental' => '🧠', 'nutrition' => '🥗', 'sleep' => '😴', 'stress' => '🧘', 'social' => '👥'];
    $dim_labels = ['physical' => 'Thể chất', 'mental' => 'Tinh thần', 'nutrition' => 'Dinh dưỡng', 'sleep' => 'Giấc ngủ', 'stress' => 'Stress', 'social' => 'Xã hội'];
    foreach ($ai_summary['wellness_dimensions'] as $dk => $dv):
      if (!is_array($dv)) continue;
      $sc = intval($dv['score'] ?? 0);
      $pct = $sc * 10;
      $fg = $sc >= 7 ? 'var(--hc-primary)' : ($sc >= 4 ? 'var(--hc-accent)' : 'var(--hc-danger)');
    ?>
    <div style="padding:12px;background:var(--hc-light);border-radius:12px">
      <div style="font-weight:700;font-size:14px"><?php echo ($dim_icons[$dk] ?? '📌') . ' ' . ($dim_labels[$dk] ?? $dk); ?></div>
      <div class="hc-progress-bar"><div class="hc-progress-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $fg; ?>"></div></div>
      <div style="font-size:13px;color:var(--hc-gray)"><?php echo $sc; ?>/10 – <?php echo esc_html($dv['note'] ?? ''); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Risk & Strengths -->
  <div class="hc-grid-2" style="margin-top:16px">
    <?php if (!empty($ai_summary['risk_factors'])): ?>
    <div>
      <h3 class="hc-h3">⚠️ Yếu tố rủi ro</h3>
      <ul class="hc-list">
        <?php foreach ($ai_summary['risk_factors'] as $r): ?>
          <li style="color:var(--hc-danger)"><?php echo esc_html($r); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <?php if (!empty($ai_summary['strengths'])): ?>
    <div>
      <h3 class="hc-h3">💪 Điểm mạnh</h3>
      <ul class="hc-list">
        <?php foreach ($ai_summary['strengths'] as $s): ?>
          <li><?php echo esc_html($s); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ===================== LIFE MAP ===================== -->
<?php if (!empty($life_map)): ?>
<div class="hc-card">
  <h2 class="hc-h2">🗺️ Bản Đồ Cuộc Đời</h2>

  <!-- Identity -->
  <?php if (!empty($life_map['identity'])): $id = $life_map['identity']; ?>
  <div style="background:linear-gradient(135deg,#ecfdf5,#f0f9ff);border-radius:12px;padding:20px;margin-bottom:16px">
    <h3 class="hc-h3" style="margin-top:0">🆔 Nhận Dạng Cá Nhân</h3>
    <?php if (!empty($id['life_stage'])): ?>
      <p><strong>Giai đoạn cuộc đời:</strong> <?php echo esc_html($id['life_stage']); ?></p>
    <?php endif; ?>
    <?php if (!empty($id['core_values'])): ?>
      <p><strong>Giá trị cốt lõi:</strong> 
        <?php foreach ($id['core_values'] as $cv): ?>
          <span class="hc-badge hc-badge-green"><?php echo esc_html($cv); ?></span>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <?php if (!empty($id['personality_traits'])): ?>
      <p><strong>Tính cách:</strong> 
        <?php foreach ($id['personality_traits'] as $pt): ?>
          <span class="hc-badge hc-badge-blue"><?php echo esc_html($pt); ?></span>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <?php if (!empty($id['communication_style'])): ?>
      <p><strong>Phong cách giao tiếp:</strong> <?php echo esc_html($id['communication_style']); ?></p>
    <?php endif; ?>
    <?php if (!empty($id['decision_making_style'])): ?>
      <p><strong>Phong cách ra quyết định:</strong> <?php echo esc_html($id['decision_making_style']); ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Life Dimensions -->
  <?php if (!empty($life_map['life_dimensions'])): ?>
  <h3 class="hc-h3">📐 Các Chiều Cuộc Sống</h3>
  <div class="hc-grid-2">
    <?php
    $ldim_icons = ['career'=>'💼','health'=>'🏥','relationships'=>'❤️','finance'=>'💰','personal_growth'=>'🌱','lifestyle'=>'🏠'];
    $ldim_labels = ['career'=>'Sự nghiệp','health'=>'Sức khoẻ','relationships'=>'Mối quan hệ','finance'=>'Tài chính','personal_growth'=>'Phát triển bản thân','lifestyle'=>'Lối sống'];
    foreach ($life_map['life_dimensions'] as $dk => $dv):
      if (!is_array($dv)) continue;
      $sc = intval($dv['score'] ?? 0);
      $pct = $sc * 10;
      $fg = $sc >= 7 ? 'var(--hc-primary)' : ($sc >= 4 ? 'var(--hc-accent)' : 'var(--hc-danger)');
    ?>
    <div style="background:var(--hc-light);border-radius:12px;padding:16px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <strong><?php echo ($ldim_icons[$dk] ?? '📌') . ' ' . ($ldim_labels[$dk] ?? ucfirst($dk)); ?></strong>
        <span class="hc-badge" style="background:<?php echo $fg; ?>"><?php echo $sc; ?>/10</span>
      </div>
      <div class="hc-progress-bar"><div class="hc-progress-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $fg; ?>"></div></div>
      <?php if (!empty($dv['current_status'])): ?>
        <p style="font-size:13px;color:var(--hc-gray);margin:6px 0 0"><?php echo esc_html($dv['current_status']); ?></p>
      <?php endif; ?>
      <?php if (!empty($dv['goals']) || !empty($dv['aspirations'])): $goals = $dv['goals'] ?? $dv['aspirations']; ?>
        <div style="margin-top:6px">
          <?php foreach (array_slice($goals, 0, 3) as $g): ?>
            <span class="hc-badge hc-badge-purple" style="font-size:11px"><?php echo esc_html($g); ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Pain Points -->
  <?php if (!empty($life_map['pain_points'])): ?>
  <h3 class="hc-h3">🔥 Nỗi Đau & Thách Thức</h3>
  <div class="hc-grid-2">
    <?php foreach ($life_map['pain_points'] as $pp):
      if (!is_array($pp)) continue;
      $impact = intval($pp['impact_level'] ?? 0);
      $bg_col = $impact >= 7 ? '#fef2f2' : ($impact >= 4 ? '#fffbeb' : '#f0fdf4');
    ?>
    <div style="background:<?php echo $bg_col; ?>;border-radius:12px;padding:14px;border-left:4px solid <?php echo $impact >= 7 ? 'var(--hc-danger)' : 'var(--hc-accent)'; ?>">
      <strong><?php echo esc_html($pp['area'] ?? ''); ?></strong>
      <span class="hc-badge" style="background:<?php echo $impact >= 7 ? 'var(--hc-danger)' : 'var(--hc-accent)'; ?>;float:right"><?php echo $impact; ?>/10</span>
      <p style="font-size:13px;margin:6px 0 0;color:var(--hc-gray)"><?php echo esc_html($pp['description'] ?? ''); ?></p>
      <?php if (!empty($pp['root_cause'])): ?>
        <p style="font-size:12px;color:var(--hc-danger);margin:4px 0 0">Gốc rễ: <?php echo esc_html($pp['root_cause']); ?></p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Motivations -->
  <?php if (!empty($life_map['motivations'])): ?>
  <h3 class="hc-h3">🚀 Động Lực</h3>
  <?php foreach ($life_map['motivations'] as $mot):
    if (!is_array($mot)) continue;
    $str = intval($mot['strength_level'] ?? 0);
  ?>
  <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9">
    <div class="hc-gauge" style="width:50px;height:50px;font-size:16px;background:var(--hc-primary)"><?php echo $str; ?></div>
    <div>
      <strong><?php echo esc_html($mot['source'] ?? ''); ?></strong>
      <p style="font-size:13px;color:var(--hc-gray);margin:2px 0 0"><?php echo esc_html($mot['description'] ?? ''); ?></p>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Life Vision -->
  <?php if (!empty($life_map['life_vision'])): $v = $life_map['life_vision']; ?>
  <h3 class="hc-h3">🔮 Tầm Nhìn Cuộc Đời</h3>
  <div style="background:linear-gradient(135deg,#ede9fe,#dbeafe);border-radius:12px;padding:20px">
    <div class="hc-grid-3">
      <?php if (!empty($v['1_year'])): ?>
      <div><strong>1 năm tới</strong><p style="font-size:13px"><?php echo esc_html($v['1_year']); ?></p></div>
      <?php endif; ?>
      <?php if (!empty($v['3_year'])): ?>
      <div><strong>3 năm tới</strong><p style="font-size:13px"><?php echo esc_html($v['3_year']); ?></p></div>
      <?php endif; ?>
      <?php if (!empty($v['5_year'])): ?>
      <div><strong>5 năm tới</strong><p style="font-size:13px"><?php echo esc_html($v['5_year']); ?></p></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($v['life_purpose'])): ?>
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(0,0,0,.1)">
      <strong>🎯 Mục đích sống:</strong> <?php echo esc_html($v['life_purpose']); ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($v['legacy'])): ?>
    <div style="margin-top:8px">
      <strong>🏛️ Di sản:</strong> <?php echo esc_html($v['legacy']); ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===================== HEALTH MAP ===================== -->
<?php if (!empty($health)): ?>
<div class="hc-card">
  <h2 class="hc-h2">🏋️ Kế Hoạch Sức Khoẻ</h2>

  <!-- Nutrition -->
  <?php if (!empty($health['nutrition_plan'])): $np = $health['nutrition_plan']; ?>
  <h3 class="hc-h3">🥗 Dinh Dưỡng</h3>
  <div class="hc-grid-3" style="margin-bottom:12px">
    <div style="text-align:center;padding:12px;background:#fef3c7;border-radius:12px">
      <div style="font-size:24px;font-weight:900"><?php echo intval($np['daily_calories'] ?? 0); ?></div>
      <div style="font-size:12px;font-weight:700">kcal/ngày</div>
    </div>
    <?php if (!empty($np['macros'])): ?>
    <div style="text-align:center;padding:12px;background:#dbeafe;border-radius:12px">
      <div style="font-size:14px">P: <?php echo intval($np['macros']['protein_g'] ?? 0); ?>g | C: <?php echo intval($np['macros']['carbs_g'] ?? 0); ?>g | F: <?php echo intval($np['macros']['fat_g'] ?? 0); ?>g</div>
      <div style="font-size:12px;font-weight:700">Macros</div>
    </div>
    <?php endif; ?>
    <div style="text-align:center;padding:12px;background:#f0fdf4;border-radius:12px">
      <div style="font-size:14px"><?php echo count($np['meal_plan'] ?? []); ?> bữa/ngày</div>
      <div style="font-size:12px;font-weight:700">Meal Plan</div>
    </div>
  </div>

  <?php if (!empty($np['meal_plan'])): ?>
  <table class="hc-table">
    <thead><tr><th>Bữa ăn</th><th>Giờ</th><th>Gợi ý</th></tr></thead>
    <tbody>
    <?php foreach ($np['meal_plan'] as $meal): ?>
      <tr>
        <td><strong><?php echo esc_html($meal['meal'] ?? ''); ?></strong></td>
        <td><?php echo esc_html($meal['time'] ?? ''); ?></td>
        <td><?php echo esc_html(implode(', ', $meal['suggestions'] ?? [])); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Exercise -->
  <?php if (!empty($health['exercise_plan'])): $ep = $health['exercise_plan']; ?>
  <h3 class="hc-h3">🏃 Tập Luyện (<?php echo intval($ep['weekly_sessions'] ?? 0); ?> buổi/tuần)</h3>
  <?php if (!empty($ep['plan'])): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($ep['plan'] as $day): ?>
    <div style="flex:1;min-width:120px;background:var(--hc-light);border-radius:12px;padding:12px;text-align:center">
      <div style="font-weight:700;color:var(--hc-primary)"><?php echo esc_html($day['day'] ?? ''); ?></div>
      <div style="font-size:13px"><?php echo esc_html($day['type'] ?? ''); ?></div>
      <div style="font-size:12px;color:var(--hc-gray)"><?php echo intval($day['duration_min'] ?? 0); ?> phút</div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Sleep Plan -->
  <?php if (!empty($health['sleep_plan'])): $sp = $health['sleep_plan']; ?>
  <h3 class="hc-h3">😴 Giấc Ngủ</h3>
  <div style="display:flex;gap:16px;flex-wrap:wrap">
    <span class="hc-badge hc-badge-purple">🕐 Đi ngủ: <?php echo esc_html($sp['bedtime'] ?? ''); ?></span>
    <span class="hc-badge hc-badge-blue">🌅 Thức dậy: <?php echo esc_html($sp['wake_time'] ?? ''); ?></span>
    <span class="hc-badge hc-badge-green">⏰ <?php echo intval($sp['target_hours'] ?? 0); ?> tiếng/đêm</span>
  </div>
  <?php if (!empty($sp['rituals'])): ?>
  <ul class="hc-list" style="margin-top:8px">
    <?php foreach ($sp['rituals'] as $r): ?>
      <li><?php echo esc_html($r); ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===================== MILESTONE CALENDAR ===================== -->
<?php if (!empty($milestone['phases'])): ?>
<div class="hc-card">
  <h2 class="hc-h2">📅 Hành Trình 90 Ngày</h2>
  <?php if (!empty($milestone['meta']['objective'])): ?>
    <p style="font-size:15px;color:var(--hc-gray)"><?php echo esc_html($milestone['meta']['objective']); ?></p>
  <?php endif; ?>

  <?php foreach ($milestone['phases'] as $pi => $phase): ?>
  <div style="margin:20px 0;background:<?php echo ['#ecfdf5','#eff6ff','#fef3c7'][$pi % 3]; ?>;border-radius:16px;padding:20px">
    <h3 style="font-size:18px;font-weight:800;margin:0 0 14px;color:<?php echo ['var(--hc-primary-dark)','var(--hc-blue)','var(--hc-accent)'][$pi % 3]; ?>">
      <?php echo esc_html($phase['title'] ?? 'Giai đoạn ' . ($pi + 1)); ?>
    </h3>

    <div class="hc-timeline">
    <?php
    $weeks = $phase['weeks'] ?? [];
    foreach ($weeks as $wi => $wk):
    ?>
      <div class="hc-timeline-item">
        <div class="hc-timeline-dot"></div>
        <div class="hc-week-card">
          <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap">
            <strong style="font-size:15px"><?php echo esc_html($wk['title'] ?? 'Tuần ' . ($wk['week'] ?? $wi + 1)); ?></strong>
            <?php if (!empty($wk['theme'])): ?>
              <span class="hc-badge hc-badge-purple"><?php echo esc_html($wk['theme']); ?></span>
            <?php endif; ?>
          </div>

          <?php if (!empty($wk['goal'])): ?>
            <p style="margin:6px 0;font-size:14px"><strong>🎯 Mục tiêu:</strong> <?php echo esc_html($wk['goal']); ?></p>
          <?php endif; ?>

          <?php if (!empty($wk['focus'])): ?>
            <p style="margin:4px 0;font-size:13px;color:var(--hc-gray)"><strong>🔍 Trọng tâm:</strong> <?php echo esc_html($wk['focus']); ?></p>
          <?php endif; ?>

          <?php if (!empty($wk['daily_habits'])): ?>
          <div style="margin-top:8px">
            <strong style="font-size:13px">🔁 Thói quen hàng ngày:</strong>
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">
              <?php foreach ($wk['daily_habits'] as $h): ?>
                <span class="hc-badge hc-badge-green" style="font-size:11px"><?php echo esc_html($h); ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($wk['tasks'])): ?>
          <div style="margin-top:8px">
            <strong style="font-size:13px">📋 Công việc:</strong>
            <ul class="hc-list">
              <?php foreach (array_slice($wk['tasks'], 0, 5) as $task): ?>
                <li><?php echo esc_html($task); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <?php if (!empty($wk['milestone'])): ?>
          <div style="margin-top:8px;padding:8px 12px;background:#fff;border-radius:8px;border:2px dashed var(--hc-primary)">
            <strong>🏁 Cột mốc:</strong> <?php echo esc_html($wk['milestone']); ?>
          </div>
          <?php endif; ?>

          <?php if (!empty($wk['reflection_prompt'])): ?>
          <div style="margin-top:8px;font-size:13px;font-style:italic;color:var(--hc-purple)">
            💭 "<?php echo esc_html($wk['reflection_prompt']); ?>"
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Rewards -->
  <?php if (!empty($milestone['rewards'])): ?>
  <h3 class="hc-h3">🎁 Phần Thưởng Cột Mốc</h3>
  <div class="hc-grid-3">
    <?php foreach ($milestone['rewards'] as $rw): ?>
    <div style="text-align:center;padding:16px;background:linear-gradient(135deg,#fef3c7,#fef9c3);border-radius:12px">
      <div style="font-size:24px">🏆</div>
      <strong><?php echo esc_html($rw['milestone'] ?? ''); ?></strong>
      <p style="font-size:13px;color:var(--hc-gray);margin:4px 0 0"><?php echo esc_html($rw['reward'] ?? ''); ?></p>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===================== NUMEROLOGY (nếu có) ===================== -->
<?php if (!empty($destiny['numbers_full'])): ?>
<div class="hc-card">
  <h2 class="hc-h2">🔢 Bản Đồ Thần Số Học</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px">
  <?php
  $titles = function_exists('bccm_metric_titles') ? bccm_metric_titles() : [];
  foreach ($destiny['numbers_full'] as $mk => $mv):
    if (!is_array($mv)) continue;
    $val = $mv['value'] ?? '—';
    $brief = $mv['brief'] ?? '—';
  ?>
    <div style="background:var(--hc-light);border-radius:10px;padding:12px;display:flex;gap:10px;align-items:center">
      <div style="width:40px;height:40px;border-radius:10px;background:var(--hc-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16px;flex-shrink:0"><?php echo esc_html($val); ?></div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--hc-primary);text-transform:uppercase"><?php echo esc_html($titles[$mk] ?? $mk); ?></div>
        <div style="font-size:13px"><?php echo esc_html($brief); ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ===================== COACHING STRATEGY ===================== -->
<?php if (!empty($life_map['coaching_strategy'])): $cs = $life_map['coaching_strategy']; ?>
<div class="hc-card">
  <h2 class="hc-h2">🤖 Chiến Lược Đồng Hành AI</h2>
  <?php if (!empty($cs['approach'])): ?>
    <p><strong>Phương pháp:</strong> <?php echo esc_html($cs['approach']); ?></p>
  <?php endif; ?>
  <?php if (!empty($cs['tone'])): ?>
    <p><strong>Giọng điệu:</strong> <?php echo esc_html($cs['tone']); ?></p>
  <?php endif; ?>
  <?php if (!empty($cs['focus_areas'])): ?>
    <p><strong>Trọng tâm:</strong>
      <?php foreach ($cs['focus_areas'] as $fa): ?>
        <span class="hc-badge hc-badge-green"><?php echo esc_html($fa); ?></span>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
  <?php if (!empty($cs['check_in_frequency'])): ?>
    <p><strong>Tần suất check-in:</strong> <?php echo esc_html($cs['check_in_frequency']); ?></p>
  <?php endif; ?>
  <?php if (!empty($cs['accountability_method'])): ?>
    <p><strong>Phương pháp theo dõi:</strong> <?php echo esc_html($cs['accountability_method']); ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===================== WEEKLY ROUTINES ===================== -->
<?php if (!empty($life_map['weekly_routines'])): $wr = $life_map['weekly_routines']; ?>
<div class="hc-card">
  <h2 class="hc-h2">🔄 Thói Quen Hàng Tuần</h2>
  <div class="hc-grid-2">
    <?php if (!empty($wr['morning_ritual'])): ?>
    <div style="background:#fef3c7;border-radius:12px;padding:16px">
      <strong>🌅 Nghi thức buổi sáng</strong>
      <ul class="hc-list">
        <?php foreach ($wr['morning_ritual'] as $mr): ?>
          <li><?php echo esc_html($mr); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <?php if (!empty($wr['evening_ritual'])): ?>
    <div style="background:#ede9fe;border-radius:12px;padding:16px">
      <strong>🌙 Nghi thức buổi tối</strong>
      <ul class="hc-list">
        <?php foreach ($wr['evening_ritual'] as $er): ?>
          <li><?php echo esc_html($er); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
  <?php if (!empty($wr['self_care'])): ?>
  <div style="margin-top:12px">
    <strong>🧘 Self-care:</strong>
    <?php foreach ($wr['self_care'] as $sc): ?>
      <span class="hc-badge hc-badge-purple"><?php echo esc_html($sc); ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div style="text-align:center;padding:30px;color:var(--hc-gray);font-size:13px">
  <p>🌱 Hành trình 90 ngày cùng <?php echo esc_html($name); ?> – Powered by BizCoach AI</p>
  <p style="font-size:11px">Generated: <?php echo date('d/m/Y H:i'); ?></p>
</div>

</div><!-- /.hc-container -->
