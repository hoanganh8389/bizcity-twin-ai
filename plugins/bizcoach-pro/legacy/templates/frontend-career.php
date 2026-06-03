<?php if (!defined('ABSPATH')) exit;
/**
 * Frontend Template: CareerCoach  Bản đồ Sự nghiệp Đột phá
 * Dark cosmic theme, consistent with AstroCoach (ac-* design system)
 *
 * Hiển thị: Career Overview + Vision Map + SWOT + Value Map + Winning Model + Leadership Map + 90-Days Plan
 * Dữ liệu: ưu tiên đọc từ bccm_gen_results, fallback về profiles columns
 */

global $wpdb;
$t = bccm_tables();

$planRow = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$t['plans']} WHERE public_key=%s AND status='active'", $key
), ARRAY_A);

if (!$planRow) {
  status_header(404);
  echo '<div class="ac-container" style="text-align:center;padding:60px 20px"><h1 style="color:#c084fc">Không tìm thấy bản đồ</h1></div>';
  return;
}

$profile = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$t['profiles']} WHERE id=%d", $planRow['coachee_id']
), ARRAY_A);

$coachee_id = (int)$planRow['coachee_id'];

// === Load dữ liệu từ gen_results (ưu tiên) hoặc fallback profiles ===
$gen_map = [
  'gen_career_overview'   => 'ai_summary',
  'gen_career_vision'     => 'vision_json',
  'gen_career_swot'       => 'swot_json',
  'gen_career_value'      => 'value_json',
  'gen_career_winning'    => 'winning_json',
  'gen_career_leadership' => 'iqmap_json',
  'gen_career_milestone'  => 'bizcoach_json',
];

$gen_data = [];
if (function_exists('bccm_get_gen_results')) {
  $all_results = bccm_get_gen_results($coachee_id);
  if ($all_results) {
    foreach ($all_results as $r) {
      $gen_data[$r['gen_key']] = $r['result_json'] ?? '';
    }
  }
}

// Helper: get JSON for a gen_key, fallback to profile column
$load = function($gen_key) use ($gen_data, $gen_map, $profile) {
  $json_str = '';
  if (!empty($gen_data[$gen_key])) {
    $json_str = $gen_data[$gen_key];
  } elseif (isset($gen_map[$gen_key]) && !empty($profile[$gen_map[$gen_key]])) {
    $json_str = $profile[$gen_map[$gen_key]];
  }
  return json_decode($json_str ?: '{}', true) ?: [];
};

$overview   = $load('gen_career_overview');
$vision     = $load('gen_career_vision');
$swot       = $load('gen_career_swot');
$value      = $load('gen_career_value');
$winning    = $load('gen_career_winning');
$leadership = $load('gen_career_leadership');
$milestone  = $load('gen_career_milestone');

// Load astro data for enrichment
$astro = function_exists('bccm_load_astro_data') ? bccm_load_astro_data($coachee_id) : null;

$name = $profile['full_name'] ?? 'Chủ nhân';
$dob  = $profile['dob'] ?? '';
$current_role = $profile['current_role'] ?? '';
$zodiac_sign  = $profile['zodiac_sign'] ?? '';
$zodiac_labels = [
  'aries'=>'Bạch Dương ','taurus'=>'Kim Ngưu ','gemini'=>'Song Tử ',
  'cancer'=>'Cự Giải ','leo'=>'Sư Tử ','virgo'=>'Xử Nữ ',
  'libra'=>'Thiên Bình ','scorpio'=>'Bọ Cạp ','sagittarius'=>'Nhân Mã ',
  'capricorn'=>'Ma Kết ','aquarius'=>'Bảo Bình ','pisces'=>'Song Ngư ',
];
$zodiac_vn = $zodiac_labels[strtolower($zodiac_sign)] ?? '';
?>

<style>
/* ====== CAREER COACH  Dark Cosmic Theme (shared with AstroCoach) ====== */
:root {
  --cc-primary: #7c3aed;
  --cc-primary-dark: #5b21b6;
  --cc-accent: #f59e0b;
  --cc-danger: #ef4444;
  --cc-blue: #3b82f6;
  --cc-teal: #14b8a6;
  --cc-pink: #ec4899;
  --cc-success: #10b981;
  --cc-dark: #0f172a;
  --cc-gray: #64748b;
  --cc-radius: 16px;
  --cc-shadow: 0 8px 24px rgba(124,58,237,.15);
  --cc-glow: 0 0 20px rgba(124,58,237,.25);
}

body {
  margin: 0 !important; padding: 0 !important;
  background: linear-gradient(135deg, #0c0a1d 0%, #1a1145 25%, #1e0a3c 50%, #0d1117 100%);
  font-family: Inter, system-ui, Arial, sans-serif;
  color: #e2e8f0;
  min-height: 100vh;
}

.cc-container { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }

/* Hero */
.cc-hero {
  text-align: center; padding: 56px 20px 40px;
  position: relative;
}
.cc-hero::before {
  content: ''; position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(124,58,237,.15) 0%, transparent 70%);
  pointer-events: none;
}
.cc-hero .title {
  font-size: 38px; font-weight: 900; margin: 0;
  background: linear-gradient(90deg, #60a5fa, #c084fc, #f9a8d4, #fbbf24);
  -webkit-background-clip: text; color: transparent;
  position: relative;
}
.cc-hero .subtitle { color: #a78bfa; font-size: 17px; margin-top: 10px; }
.cc-hero .meta {
  margin-top: 20px; display: flex; justify-content: center; gap: 16px; flex-wrap: wrap;
}
.cc-hero .meta-item {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 18px; border-radius: 999px; font-size: 14px; font-weight: 600;
  background: rgba(124,58,237,.2); color: #c4b5fd; border: 1px solid rgba(124,58,237,.3);
}

/* Cards */
.cc-card {
  border-radius: var(--cc-radius); padding: 28px; margin: 20px 0;
  background: rgba(15,12,40,.85);
  backdrop-filter: blur(12px);
  box-shadow: var(--cc-shadow);
  border: 1px solid rgba(124,58,237,.25);
  transition: transform .2s, border-color .2s;
}
.cc-card:hover { transform: translateY(-2px); border-color: rgba(124,58,237,.5); }

.cc-h2 {
  font-size: 24px; font-weight: 800; margin: 0 0 20px;
  color: #c084fc;
  display: flex; align-items: center; gap: 10px;
}
.cc-h3 { font-size: 17px; font-weight: 700; margin: 16px 0 10px; color: #e2e8f0; }

/* Badges */
.cc-badge {
  display: inline-block; padding: 5px 14px;
  border-radius: 999px; font-size: 12px; font-weight: 700;
  color: #fff; margin: 3px 4px 3px 0;
}
.cc-badge-purple { background: var(--cc-primary); }
.cc-badge-pink { background: var(--cc-pink); }
.cc-badge-amber { background: var(--cc-accent); }
.cc-badge-teal { background: var(--cc-teal); }
.cc-badge-blue { background: var(--cc-blue); }
.cc-badge-red { background: var(--cc-danger); }
.cc-badge-success { background: var(--cc-success); }

/* Grids */
.cc-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.cc-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 16px; }
.cc-grid-4 { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; }
@media (max-width: 768px) { .cc-grid-2, .cc-grid-3, .cc-grid-4 { grid-template-columns: 1fr; } }

/* Tiles */
.cc-tile {
  border-radius: 16px; padding: 20px; text-align: center;
  font-weight: 600; color: #fff;
  background: linear-gradient(135deg, #7c3aed, #4c1d95);
  box-shadow: var(--cc-glow);
}
.cc-tile.alt { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.cc-tile.pink { background: linear-gradient(135deg, #ec4899, #be185d); }
.cc-tile.teal { background: linear-gradient(135deg, #14b8a6, #0d9488); }
.cc-tile .num { font-size: 32px; font-weight: 900; margin-bottom: 6px; }
.cc-tile .label { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; }
.cc-tile .desc { font-size: 12px; opacity: .85; margin-top: 4px; }

/* Score circle */
.cc-score {
  display: inline-flex; align-items: center; justify-content: center;
  width: 90px; height: 90px; border-radius: 50%;
  font-size: 1.5em; font-weight: 900; color: #fff;
  background: linear-gradient(135deg, #7c3aed, #3b82f6);
  box-shadow: 0 0 30px rgba(124,58,237,.4);
}

/* Progress bar */
.cc-progress-bar { height: 8px; background: rgba(255,255,255,.1); border-radius: 4px; overflow: hidden; margin: 4px 0; }
.cc-progress-fill { height: 100%; border-radius: 4px; transition: width .5s ease; }

/* List */
.cc-list { list-style: none; padding: 0; margin: 6px 0; }
.cc-list li { padding: 4px 0 4px 22px; position: relative; font-size: 14px; line-height: 1.6; }
.cc-list li::before { content: '\2726'; position: absolute; left: 0; color: #c084fc; font-weight: 700; }

/* Vision statement banner */
.cc-vision-banner {
  background: linear-gradient(135deg, rgba(124,58,237,.3), rgba(59,130,246,.2));
  border: 1px solid rgba(124,58,237,.4);
  border-radius: 12px; padding: 28px; text-align: center;
  font-size: 1.3em; font-weight: 600; font-style: italic;
  color: #e0e7ff; line-height: 1.6;
}

/* SWOT cards */
.cc-swot-item {
  border-radius: 12px; padding: 16px; margin-bottom: 10px;
}
.cc-swot-strength { background: rgba(16,185,129,.12); border-left: 4px solid #10b981; }
.cc-swot-weakness { background: rgba(239,68,68,.12); border-left: 4px solid #ef4444; }
.cc-swot-opportunity { background: rgba(124,58,237,.12); border-left: 4px solid #7c3aed; }
.cc-swot-threat { background: rgba(245,158,11,.12); border-left: 4px solid #f59e0b; }

/* Timeline */
.cc-timeline { position: relative; padding-left: 28px; }
.cc-timeline::before {
  content: ''; position: absolute; left: 10px; top: 0; bottom: 0;
  width: 3px; background: linear-gradient(180deg, #3b82f6, #7c3aed, #ec4899, #fbbf24);
  border-radius: 2px;
}
.cc-timeline-item { position: relative; margin-bottom: 24px; }
.cc-timeline-dot {
  position: absolute; left: -24px; top: 4px;
  width: 14px; height: 14px; border-radius: 50%;
  background: #60a5fa; border: 3px solid rgba(15,12,40,.85);
  box-shadow: 0 0 0 2px #60a5fa;
}
.cc-week-card {
  background: rgba(59,130,246,.12); border-radius: 12px; padding: 16px;
  border-left: 4px solid #3b82f6; margin: 8px 0;
}
.cc-week-card:nth-child(even) { border-left-color: #7c3aed; }

/* Winning formula highlight */
.cc-formula {
  background: linear-gradient(135deg, rgba(245,158,11,.2), rgba(239,68,68,.15));
  border: 1px solid rgba(245,158,11,.4);
  border-radius: 12px; padding: 28px; text-align: center;
  font-size: 1.2em; font-weight: 700; color: #fbbf24;
}

/* Inner block */
.cc-block {
  background: rgba(124,58,237,.08);
  border-radius: 12px; padding: 18px; margin-bottom: 12px;
}

@media print {
  body { background: #0c0a1d !important; }
  .cc-card { box-shadow: none !important; break-inside: avoid; }
}
</style>

<div class="cc-container">

<!-- ====== HERO ====== -->
<div class="cc-hero">
  <div class="title">🚀 Career Breakthrough Map</div>
  <div class="subtitle"><?php echo esc_html($name); ?></div>
  <div class="meta">
    <?php if ($dob): ?>
      <span class="meta-item">📅 <?php echo esc_html(date('d/m/Y', strtotime($dob))); ?></span>
    <?php endif; ?>
    <?php if ($current_role): ?>
      <span class="meta-item">💼 <?php echo esc_html($current_role); ?></span>
    <?php endif; ?>
    <?php if ($zodiac_vn): ?>
      <span class="meta-item"><?php echo esc_html($zodiac_vn); ?></span>
    <?php endif; ?>
    <?php if ($astro && isset($astro['summary']['sun_sign'])): ?>
      <span class="meta-item">☀️ <?php echo esc_html($astro['summary']['sun_sign']); ?></span>
    <?php endif; ?>
  </div>
</div>

<!-- ====== 1. CAREER OVERVIEW ====== -->
<?php if (!empty($overview)): ?>
<div class="cc-card">
  <h2 class="cc-h2">📊 Tổng Quan Sự Nghiệp</h2>

  <?php if (isset($overview['overview'])): ?>
    <p style="font-size:15px;line-height:1.8;color:#cbd5e1"><?php echo nl2br(esc_html($overview['overview'])); ?></p>
  <?php endif; ?>

  <?php if (isset($overview['career_score']) || isset($overview['career_archetype'])): ?>
  <div style="display:flex;align-items:center;gap:30px;justify-content:center;margin:28px 0;flex-wrap:wrap">
    <?php if (isset($overview['career_score'])): ?>
      <div style="text-align:center">
        <div class="cc-score"><?php echo (int)$overview['career_score']; ?></div>
        <div style="margin-top:8px;font-weight:700;color:#a78bfa;font-size:13px">CAREER POTENTIAL</div>
      </div>
    <?php endif; ?>
    <?php if (!empty($overview['career_archetype'])): ?>
      <div style="text-align:center">
        <span class="cc-badge cc-badge-purple" style="font-size:16px;padding:10px 24px"><?php echo esc_html($overview['career_archetype']); ?></span>
        <div style="margin-top:8px;font-weight:700;color:#a78bfa;font-size:13px">ARCHETYPE</div>
      </div>
    <?php endif; ?>
    <?php if (isset($overview['leadership_potential'])): ?>
      <div style="text-align:center">
        <div class="cc-score" style="width:70px;height:70px;font-size:1.2em;background:linear-gradient(135deg,#ec4899,#be185d)"><?php echo (int)$overview['leadership_potential']; ?></div>
        <div style="margin-top:8px;font-weight:700;color:#f9a8d4;font-size:13px">LEADERSHIP</div>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="cc-grid-2">
    <?php if (!empty($overview['natural_talents'])): ?>
    <div class="cc-block">
      <h3 class="cc-h3" style="margin-top:0">⭐ Tài Năng Tự Nhiên</h3>
      <ul class="cc-list">
        <?php foreach ($overview['natural_talents'] as $t2): ?>
          <li><?php echo esc_html($t2); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($overview['ideal_roles'])): ?>
    <div class="cc-block">
      <h3 class="cc-h3" style="margin-top:0">🎭 Vai Trò Lý Tưởng</h3>
      <ul class="cc-list">
        <?php foreach ($overview['ideal_roles'] as $r): ?>
          <li><?php echo esc_html($r); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($overview['career_challenges'])): ?>
  <div class="cc-block" style="margin-top:12px">
    <h3 class="cc-h3" style="margin-top:0">⚠️ Thách Thức Sự Nghiệp</h3>
    <?php foreach ($overview['career_challenges'] as $ch): ?>
      <span class="cc-badge cc-badge-amber"><?php echo esc_html($ch); ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($overview['growth_areas'])): ?>
  <div class="cc-block">
    <h3 class="cc-h3" style="margin-top:0">📈 Kỹ Năng Cần Phát Triển</h3>
    <?php foreach ($overview['growth_areas'] as $g): ?>
      <span class="cc-badge cc-badge-teal"><?php echo esc_html($g); ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($overview['career_timing'])): $ct = $overview['career_timing']; ?>
  <div class="cc-grid-3" style="margin-top:16px">
    <div class="cc-tile">
      <div class="label">Giai Đoạn Hiện Tại</div>
      <div class="desc" style="font-size:14px;margin-top:6px"><?php echo esc_html($ct['current_phase'] ?? ''); ?></div>
    </div>
    <div class="cc-tile alt">
      <div class="label">Thời Kỳ Thuận Lợi</div>
      <div class="desc" style="font-size:14px;margin-top:6px"><?php echo esc_html($ct['favorable_period'] ?? ''); ?></div>
    </div>
    <div class="cc-tile pink">
      <div class="label">Cần Cẩn Trọng</div>
      <div class="desc" style="font-size:14px;margin-top:6px"><?php echo esc_html($ct['caution_period'] ?? ''); ?></div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ====== 2. CAREER VISION ====== -->
<?php if (!empty($vision)): ?>
<div class="cc-card">
  <h2 class="cc-h2">🎯 Tầm Nhìn Sự Nghiệp</h2>

  <?php if (isset($vision['vision_statement'])): ?>
    <div class="cc-vision-banner">
      "<?php echo esc_html($vision['vision_statement']); ?>"
    </div>
  <?php endif; ?>

  <?php if (isset($vision['career_purpose'])): ?>
    <div class="cc-block" style="margin-top:20px">
      <h3 class="cc-h3" style="margin-top:0">🧭 Mục Đích Sự Nghiệp</h3>
      <p style="color:#cbd5e1;line-height:1.7"><?php echo esc_html($vision['career_purpose']); ?></p>
    </div>
  <?php endif; ?>

  <?php if (!empty($vision['core_values'])): ?>
    <div style="margin:16px 0">
      <h3 class="cc-h3">💜 Giá Trị Cốt Lõi</h3>
      <?php foreach ($vision['core_values'] as $cv): ?>
        <span class="cc-badge cc-badge-purple"><?php echo esc_html($cv); ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($vision['milestones'])): ?>
    <h3 class="cc-h3">⏳ Mốc Thời Gian</h3>
    <div class="cc-grid-3">
      <?php $colors = ['','alt','pink']; $idx=0; ?>
      <?php foreach ($vision['milestones'] as $m): ?>
        <div class="cc-tile <?php echo $colors[$idx%3]; ?>">
          <div class="num"><?php echo esc_html($m['year'] ?? ''); ?> năm</div>
          <div class="desc" style="font-size:14px"><?php echo esc_html($m['goal'] ?? ''); ?></div>
        </div>
      <?php $idx++; endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($vision['impact_goals'])): ?>
    <div class="cc-block" style="margin-top:16px">
      <h3 class="cc-h3" style="margin-top:0">🌍 Mục Tiêu Ảnh Hưởng</h3>
      <ul class="cc-list">
        <?php foreach ($vision['impact_goals'] as $ig): ?><li><?php echo esc_html($ig); ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($vision['legacy_statement'])): ?>
    <div style="margin-top:16px;padding:16px;background:rgba(236,72,153,.1);border-radius:12px;border-left:4px solid #ec4899">
      <strong style="color:#f9a8d4">🏛️ Di Sản:</strong>
      <span style="color:#cbd5e1"> <?php echo esc_html($vision['legacy_statement']); ?></span>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ====== 3. SWOT ANALYSIS ====== -->
<?php if (!empty($swot)): ?>
<div class="cc-card">
  <h2 class="cc-h2">⚖️ Phân Tích SWOT Cá Nhân</h2>

  <div class="cc-grid-2">
    <!-- Strengths -->
    <?php if (!empty($swot['strengths'])): ?>
    <div>
      <h3 class="cc-h3" style="color:#10b981">💪 Thế Mạnh</h3>
      <?php foreach ($swot['strengths'] as $s): ?>
        <div class="cc-swot-item cc-swot-strength">
          <strong style="color:#6ee7b7"><?php echo esc_html($s['item'] ?? ''); ?></strong>
          <?php if (!empty($s['evidence'])): ?>
            <div style="font-size:12px;color:#94a3b8;margin-top:4px">📋 <?php echo esc_html($s['evidence']); ?></div>
          <?php endif; ?>
          <?php if (!empty($s['leverage'])): ?>
            <div style="font-size:13px;color:#34d399;margin-top:4px">→ <?php echo esc_html($s['leverage']); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Weaknesses -->
    <?php if (!empty($swot['weaknesses'])): ?>
    <div>
      <h3 class="cc-h3" style="color:#ef4444">⚠️ Điểm Yếu</h3>
      <?php foreach ($swot['weaknesses'] as $w): ?>
        <div class="cc-swot-item cc-swot-weakness">
          <strong style="color:#fca5a5"><?php echo esc_html($w['item'] ?? ''); ?></strong>
          <?php if (!empty($w['impact'])): ?>
            <div style="font-size:12px;color:#94a3b8;margin-top:4px">📋 <?php echo esc_html($w['impact']); ?></div>
          <?php endif; ?>
          <?php if (!empty($w['mitigation'])): ?>
            <div style="font-size:13px;color:#f87171;margin-top:4px">🔧 <?php echo esc_html($w['mitigation']); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="cc-grid-2" style="margin-top:16px">
    <!-- Opportunities -->
    <?php if (!empty($swot['opportunities'])): ?>
    <div>
      <h3 class="cc-h3" style="color:#7c3aed">🚀 Cơ Hội</h3>
      <?php foreach ($swot['opportunities'] as $o): ?>
        <div class="cc-swot-item cc-swot-opportunity">
          <strong style="color:#c4b5fd"><?php echo esc_html($o['item'] ?? ''); ?></strong>
          <?php if (!empty($o['timing'])): ?>
            <div style="font-size:12px;color:#94a3b8;margin-top:4px">⏰ <?php echo esc_html($o['timing']); ?></div>
          <?php endif; ?>
          <?php if (!empty($o['action'])): ?>
            <div style="font-size:13px;color:#a78bfa;margin-top:4px">→ <?php echo esc_html($o['action']); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Threats -->
    <?php if (!empty($swot['threats'])): ?>
    <div>
      <h3 class="cc-h3" style="color:#f59e0b">⚡ Thách Thức</h3>
      <?php foreach ($swot['threats'] as $th): ?>
        <div class="cc-swot-item cc-swot-threat">
          <strong style="color:#fcd34d"><?php echo esc_html($th['item'] ?? ''); ?></strong>
          <?php if (!empty($th['risk_level'])): ?>
            <span class="cc-badge cc-badge-<?php echo ($th['risk_level'] ?? '') === 'cao' ? 'red' : (($th['risk_level'] ?? '') === 'thấp' ? 'teal' : 'amber'); ?>" style="float:right"><?php echo esc_html($th['risk_level']); ?></span>
          <?php endif; ?>
          <?php if (!empty($th['response'])): ?>
            <div style="font-size:13px;color:#fbbf24;margin-top:4px">🛡️ <?php echo esc_html($th['response']); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($swot['swot_strategy'])): ?>
    <div style="margin-top:20px;padding:18px;background:rgba(124,58,237,.12);border-radius:12px;border-left:4px solid #7c3aed">
      <strong style="color:#c084fc">📌 Chiến Lược Tổng Thể:</strong>
      <span style="color:#cbd5e1"> <?php echo esc_html($swot['swot_strategy']); ?></span>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ====== 4. VALUE MAP ====== -->
<?php if (!empty($value)): ?>
<div class="cc-card">
  <h2 class="cc-h2">💎 Bản Đồ Giá Trị</h2>

  <?php if (!empty($value['core_values'])): ?>
    <h3 class="cc-h3">💖 Giá Trị Cốt Lõi</h3>
    <div class="cc-grid-2">
      <?php foreach ($value['core_values'] as $cv):
        $score = intval($cv['importance_score'] ?? 0);
        $fill_color = $score >= 8 ? '#10b981' : ($score >= 5 ? '#f59e0b' : '#ef4444');
      ?>
      <div class="cc-block">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong style="color:#e0e7ff"><?php echo esc_html($cv['value'] ?? ''); ?></strong>
          <span style="font-weight:900;color:<?php echo $fill_color; ?>"><?php echo $score; ?>/10</span>
        </div>
        <div class="cc-progress-bar" style="margin-top:6px">
          <div class="cc-progress-fill" style="width:<?php echo $score*10; ?>%;background:<?php echo $fill_color; ?>"></div>
        </div>
        <?php if (!empty($cv['definition'])): ?>
          <p style="margin:6px 0 0;color:#94a3b8;font-size:13px"><?php echo esc_html($cv['definition']); ?></p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="cc-grid-2" style="margin-top:16px">
    <?php if (!empty($value['work_values'])): ?>
    <div class="cc-block">
      <h3 class="cc-h3" style="margin-top:0">💼 Giá Trị Công Việc</h3>
      <?php foreach ($value['work_values'] as $wv): ?>
        <span class="cc-badge cc-badge-blue"><?php echo esc_html($wv); ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($value['non_negotiables'])): ?>
    <div class="cc-block">
      <h3 class="cc-h3" style="margin-top:0">🚫 Không Thể Thỏa Hiệp</h3>
      <?php foreach ($value['non_negotiables'] as $nn): ?>
        <span class="cc-badge cc-badge-red"><?php echo esc_html($nn); ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($value['contribution_model'])): ?>
    <div style="margin-top:16px;padding:18px;background:rgba(59,130,246,.1);border-radius:12px;border-left:4px solid #3b82f6">
      <strong style="color:#93c5fd">🎁 Mô Hình Đóng Góp:</strong>
      <span style="color:#cbd5e1"> <?php echo esc_html($value['contribution_model']); ?></span>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ====== 5. WINNING MODEL ====== -->
<?php if (!empty($winning)): ?>
<div class="cc-card">
  <h2 class="cc-h2">🏆 Công Thức Chiến Thắng</h2>

  <?php if (isset($winning['winning_formula'])): ?>
    <div class="cc-formula">
      <?php echo esc_html($winning['winning_formula']); ?>
    </div>
  <?php endif; ?>

  <div class="cc-grid-2" style="margin-top:24px">
    <div class="cc-tile">
      <div class="label">🎯 WHAT</div>
      <div class="desc" style="font-size:14px;margin-top:8px"><?php echo esc_html($winning['what'] ?? ''); ?></div>
    </div>
    <div class="cc-tile alt">
      <div class="label">💡 WHY</div>
      <div class="desc" style="font-size:14px;margin-top:8px"><?php echo esc_html($winning['why'] ?? ''); ?></div>
    </div>
    <div class="cc-tile pink">
      <div class="label">🔧 HOW</div>
      <div class="desc" style="font-size:14px;margin-top:8px"><?php echo esc_html($winning['how'] ?? ''); ?></div>
    </div>
    <div class="cc-tile teal">
      <div class="label">👥 WHO</div>
      <div class="desc" style="font-size:14px;margin-top:8px"><?php echo esc_html($winning['who'] ?? ''); ?></div>
    </div>
  </div>

  <?php if (!empty($winning['competitive_advantages'])): ?>
    <div style="margin-top:20px">
      <h3 class="cc-h3">🏅 Lợi Thế Cạnh Tranh</h3>
      <?php foreach ($winning['competitive_advantages'] as $adv): ?>
        <span class="cc-badge cc-badge-success"><?php echo esc_html($adv); ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($winning['success_patterns'])): ?>
    <div style="margin-top:16px">
      <h3 class="cc-h3">📐 Khuôn Mẫu Thành Công</h3>
      <?php foreach ($winning['success_patterns'] as $sp): ?>
        <div class="cc-block">
          <strong style="color:#e0e7ff"><?php echo esc_html($sp['pattern'] ?? ''); ?></strong>
          <?php if (!empty($sp['repeatability'])): ?>
            <div style="font-size:13px;color:#94a3b8;margin-top:4px">🔄 <?php echo esc_html($sp['repeatability']); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($winning['execution_principles'])): ?>
    <div style="margin-top:16px">
      <h3 class="cc-h3">⚡ Nguyên Tắc Hành Động</h3>
      <ul class="cc-list">
        <?php foreach ($winning['execution_principles'] as $ep): ?><li><?php echo esc_html($ep); ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ====== 6. LEADERSHIP MAP ====== -->
<?php if (!empty($leadership)): ?>
<div class="cc-card">
  <h2 class="cc-h2">👑 Bản Đồ Nhà Lãnh Đạo</h2>

  <?php if (isset($leadership['leadership_archetype'])): ?>
    <div style="text-align:center;margin-bottom:24px">
      <span class="cc-badge cc-badge-purple" style="font-size:18px;padding:12px 28px"><?php echo esc_html($leadership['leadership_archetype']); ?></span>
      <?php if (!empty($leadership['natural_leadership_style'])): ?>
        <div style="margin-top:8px;color:#94a3b8;font-size:14px"><?php echo esc_html($leadership['natural_leadership_style']); ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($leadership['leadership_scores'])): ?>
    <h3 class="cc-h3">📊 Leadership Scores</h3>
    <div class="cc-grid-2">
      <?php foreach ($leadership['leadership_scores'] as $skill => $score):
        $score = (int)$score;
        $fg = $score >= 8 ? '#10b981' : ($score >= 5 ? '#f59e0b' : '#ef4444');
      ?>
        <div style="margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px">
            <span style="font-size:13px"><?php echo esc_html(ucwords(str_replace('_', ' ', $skill))); ?></span>
            <strong style="color:<?php echo $fg; ?>"><?php echo $score; ?>/10</strong>
          </div>
          <div class="cc-progress-bar">
            <div class="cc-progress-fill" style="width:<?php echo $score*10; ?>%;background:<?php echo $fg; ?>"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="cc-grid-2" style="margin-top:20px">
    <?php if (!empty($leadership['leadership_strengths'])): ?>
    <div class="cc-block">
      <h3 class="cc-h3" style="margin-top:0;color:#10b981">💪 Điểm Mạnh Lãnh Đạo</h3>
      <ul class="cc-list">
        <?php foreach ($leadership['leadership_strengths'] as $s): ?><li><?php echo esc_html($s); ?></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($leadership['leadership_blind_spots'])): ?>
    <div class="cc-block">
      <h3 class="cc-h3" style="margin-top:0;color:#f59e0b">👁️ Điểm Mù</h3>
      <ul class="cc-list">
        <?php foreach ($leadership['leadership_blind_spots'] as $b): ?><li><?php echo esc_html($b); ?></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($leadership['team_dynamics'])): $td = $leadership['team_dynamics']; ?>
    <div class="cc-block" style="margin-top:16px">
      <h3 class="cc-h3" style="margin-top:0">👥 Động Lực Nhóm</h3>
      <?php if (!empty($td['ideal_team_size'])): ?>
        <p><strong>Quy mô lý tưởng:</strong> <span class="cc-badge cc-badge-blue"><?php echo esc_html($td['ideal_team_size']); ?></span></p>
      <?php endif; ?>
      <?php if (!empty($td['preferred_team_culture'])): ?>
        <p><strong>Văn hóa đội nhóm:</strong> <?php echo esc_html($td['preferred_team_culture']); ?></p>
      <?php endif; ?>
      <?php if (!empty($td['conflict_resolution_style'])): ?>
        <p><strong>Giải quyết xung đột:</strong> <?php echo esc_html($td['conflict_resolution_style']); ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (isset($leadership['leadership_readiness'])): $lr = $leadership['leadership_readiness']; ?>
    <div style="margin-top:20px;background:linear-gradient(135deg,rgba(59,130,246,.15),rgba(124,58,237,.1));border-radius:12px;padding:20px">
      <h3 class="cc-h3" style="margin-top:0;color:#60a5fa">🎖️ Leadership Readiness</h3>
      <div class="cc-grid-2" style="gap:12px">
        <div><strong>Hiện tại:</strong> <span class="cc-badge cc-badge-blue"><?php echo esc_html($lr['current_level'] ?? ''); ?></span></div>
        <div><strong>Cấp tiếp theo:</strong> <span class="cc-badge cc-badge-purple"><?php echo esc_html($lr['next_level'] ?? ''); ?></span></div>
      </div>
      <?php if (!empty($lr['gap_analysis'])): ?>
        <p style="margin-top:10px;color:#94a3b8;font-size:14px">📋 <?php echo esc_html($lr['gap_analysis']); ?></p>
      <?php endif; ?>
      <?php if (!empty($lr['timeline'])): ?>
        <p style="color:#93c5fd;font-size:14px">⏳ <?php echo esc_html($lr['timeline']); ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ====== 7. 90-DAYS CAREER BREAKTHROUGH ====== -->
<?php if (!empty($milestone['phases'])): ?>
<div class="cc-card">
  <h2 class="cc-h2">📅 Hành Trình 90 Ngày</h2>
  <?php if (!empty($milestone['meta']['objective'])): ?>
    <p style="font-size:15px;color:#94a3b8;margin-bottom:20px"><?php echo esc_html($milestone['meta']['objective']); ?></p>
  <?php endif; ?>

  <?php
  $phase_bg = ['rgba(59,130,246,.1)','rgba(124,58,237,.12)','rgba(236,72,153,.1)'];
  foreach ($milestone['phases'] as $pi => $phase):
  ?>
  <div style="margin:20px 0;background:<?php echo $phase_bg[$pi % 3]; ?>;border-radius:16px;padding:24px">
    <h3 class="cc-h3" style="margin-top:0;color:#60a5fa"><?php echo esc_html($phase['title'] ?? "Phase ".($pi+1)); ?></h3>

    <div class="cc-timeline">
      <?php foreach ($phase['weeks'] ?? [] as $wk): ?>
      <div class="cc-timeline-item">
        <div class="cc-timeline-dot"></div>
        <div class="cc-week-card">
          <strong>Tuần <?php echo esc_html($wk['week'] ?? ''); ?></strong>
          <?php if (!empty($wk['theme'] ?? $wk['title'] ?? '')): ?>
             <?php echo esc_html($wk['theme'] ?? $wk['title'] ?? ''); ?>
          <?php endif; ?>
          <?php if (!empty($wk['goal'])): ?>
            <p style="margin:6px 0 0;font-size:13px;color:#94a3b8">🎯 <?php echo esc_html($wk['goal']); ?></p>
          <?php endif; ?>
          <?php if (!empty($wk['milestone'])): ?>
            <p style="margin:4px 0 0;font-size:13px;color:#60a5fa">🏁 <?php echo esc_html($wk['milestone']); ?></p>
          <?php endif; ?>
          <?php if (!empty($wk['daily_habits'])): ?>
            <ul class="cc-list" style="margin-top:6px">
              <?php foreach ($wk['daily_habits'] as $h): ?><li><?php echo esc_html($h); ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if (!empty($wk['tasks'])): ?>
            <div style="margin-top:6px;font-size:12px;color:#64748b">
              <?php foreach ($wk['tasks'] as $task): ?><span class="cc-badge cc-badge-blue" style="font-size:11px"><?php echo esc_html($task); ?></span><?php endforeach; ?>
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
  <h3 class="cc-h3">🎁 Phần Thưởng Cột Mốc</h3>
  <div class="cc-grid-3">
    <?php $rwc = ['','alt','pink']; $ri=0; ?>
    <?php foreach ($milestone['rewards'] as $rw): ?>
    <div class="cc-tile <?php echo $rwc[$ri%3]; ?>">
      <div class="label"><?php echo esc_html($rw['milestone'] ?? ''); ?></div>
      <div class="desc"><?php echo esc_html($rw['reward'] ?? ''); ?></div>
    </div>
    <?php $ri++; endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ====== FOOTER ====== -->
<div style="text-align:center;padding:30px;color:#a78bfa;font-size:13px">
  <p>🚀 Career Breakthrough Map • <?php echo esc_html($name); ?> • Powered by BizCoach AI</p>
  <p style="font-size:11px">Generated: <?php echo date('d/m/Y H:i'); ?></p>
</div>

</div><!-- /.cc-container -->