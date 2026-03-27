<?php
/**
 * BizCoach Map – Transit Report (Standalone HTML Page)
 *
 * Hiển thị bản đồ sao dịch chuyển (transit) so sánh với natal chart.
 * Mở trong tab mới, cùng pattern với bccm_natal_report_full.
 *
 * AJAX action: bccm_transit_report
 *   GET params: coachee_id, period (week|month|year), _wpnonce
 *
 * @package BizCoach_Map
 * @since   0.1.0.21
 */
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_bccm_transit_report', 'bccm_transit_report_handler');

/**
 * AJAX handler: render full transit report HTML page
 */
function bccm_transit_report_handler() {
    if (!current_user_can('edit_posts')) wp_die('Unauthorized');
    check_ajax_referer('bccm_transit_report', '_wpnonce');

    $coachee_id = intval($_GET['coachee_id'] ?? 0);
    if (!$coachee_id) wp_die('Missing coachee_id');

    $period = sanitize_text_field($_GET['period'] ?? 'week');
    $allowed = ['week', 'month', 'year'];
    if (!in_array($period, $allowed)) $period = 'week';

    global $wpdb;
    $t = bccm_tables();

    // ── Load coachee profile ──
    $coachee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id
    ), ARRAY_A);
    if (!$coachee) wp_die('Coachee not found');

    // ── Load natal chart (western) - query by user_id first, fallback to coachee_id ──
    $user_id = $coachee['user_id'] ?? 0;
    $astro_row = null;
    if ($user_id) {
        $astro_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='western' AND (summary IS NOT NULL OR traits IS NOT NULL)",
            $user_id
        ), ARRAY_A);
    }
    if (!$astro_row) {
        $astro_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d AND chart_type='western'",
            $coachee_id
        ), ARRAY_A);
    }
    if (!$astro_row || empty($astro_row['traits'])) {
        wp_die('Chưa có bản đồ sao natal. Hãy tạo bản đồ Western Astrology trước.');
    }

    $natal_traits    = json_decode($astro_row['traits'], true) ?: [];
    $natal_summary   = json_decode($astro_row['summary'] ?? '{}', true) ?: [];
    $natal_positions = $natal_traits['positions'] ?? [];
    $birth_data      = $natal_traits['birth_data'] ?? [];
    if (empty($natal_positions)) wp_die('Natal chart data invalid.');

    // Location
    $latitude  = floatval($astro_row['latitude'] ?: 21.0285);
    $longitude = floatval($astro_row['longitude'] ?: 105.8542);
    $timezone  = floatval($astro_row['timezone'] ?: 7.0);

    // ── Time range config ──
    $time_configs = [
        'week'  => ['period' => 'week',  'days' => 7,   'label' => 'Tuần tới (7 ngày)',  'label_short' => 'Tuần tới'],
        'month' => ['period' => 'month', 'days' => 30,  'label' => 'Tháng tới (30 ngày)', 'label_short' => 'Tháng tới'],
        'year'  => ['period' => 'year',  'days' => 365, 'label' => 'Năm tới (12 tháng)',  'label_short' => 'Năm tới'],
    ];
    $time_range = $time_configs[$period];
    $check_dates = bccm_transit_get_check_dates($time_range);

    // ── Read pre-fetched transit data from DB (no live API calls) ──
    $snapshots   = [];
    $all_aspects = [];
    $no_data_msg = '';

    $t_snap = $wpdb->prefix . 'bccm_transit_snapshots';
    $snap_table_exists = ($wpdb->get_var("SHOW TABLES LIKE '{$t_snap}'") === $t_snap);

    if ($snap_table_exists) {
        $where_id = $user_id
            ? $wpdb->prepare('(coachee_id = %d OR user_id = %d)', $coachee_id, $user_id)
            : $wpdb->prepare('coachee_id = %d', $coachee_id);

        $db_rows = $wpdb->get_results(
            "SELECT target_date, label, planets_json, aspects_json, fetched_at "
            . "FROM {$t_snap} "
            . "WHERE {$where_id} "
            . "  AND target_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) "
            . "  AND target_date <= DATE_ADD(CURDATE(), INTERVAL 400 DAY) "
            . 'ORDER BY target_date ASC'
        );

        // Map by date for nearest-match lookup
        $snap_by_date = [];
        foreach ($db_rows as $row) {
            $snap_by_date[$row->target_date] = $row;
        }

        $used_dates = [];
        foreach ($check_dates as $date_info) {
            $target_ts = strtotime($date_info['date']);
            $best_date = null;
            $best_diff = PHP_INT_MAX;

            foreach ($snap_by_date as $snap_date => $row) {
                $diff = abs(strtotime($snap_date) - $target_ts);
                if ($diff < $best_diff) { $best_diff = $diff; $best_date = $snap_date; }
            }

            // Accept snapshot within ±45 days of requested date
            if ($best_date && $best_diff <= 45 * DAY_IN_SECONDS && !isset($used_dates[$best_date])) {
                $used_dates[$best_date] = true;
                $row       = $snap_by_date[$best_date];
                $positions = json_decode($row->planets_json, true) ?: [];
                $aspects   = json_decode($row->aspects_json, true) ?: [];

                if (empty($positions)) continue;

                $snapshots[] = [
                    'date'       => $best_date,
                    'label'      => $date_info['label'],
                    'positions'  => $positions,
                    'fetched_at' => $row->fetched_at,
                ];

                foreach ($aspects as &$asp) {
                    $asp['transit_date']  = $best_date;
                    $asp['transit_label'] = $date_info['label'];
                }
                unset($asp);
                $all_aspects = array_merge($all_aspects, $aspects);
            }
        }
    }

    // If no snapshots in DB → schedule background prefetch and show notice
    if (empty($snapshots)) {
        $prefetch_user_id = $user_id ? (int) $user_id : 0;
        $existing_cron = wp_next_scheduled('bccm_transit_prefetch_cron', [$coachee_id, $prefetch_user_id]);
        if (!$existing_cron) {
            wp_schedule_single_event(time() + 5, 'bccm_transit_prefetch_cron', [$coachee_id, $prefetch_user_id]);
        }
        $no_data_msg = 'Dữ liệu transit chưa được tạo cho hồ sơ này. Hệ thống đang cập nhật trong nền — vui lòng thử lại sau 1-2 phút.';
    }

    // Deduplicate aspects (same transit-natal-aspect → keep closest orb)
    $unique_aspects = [];
    foreach ($all_aspects as $a) {
        $key = $a['transit_planet'] . '_' . $a['natal_planet'] . '_' . $a['aspect'];
        if (!isset($unique_aspects[$key]) || $a['orb'] < $unique_aspects[$key]['orb']) {
            $unique_aspects[$key] = $a;
        }
    }
    $all_aspects = array_values($unique_aspects);

    // Sort: exact first, then by orb
    usort($all_aspects, function($a, $b) {
        if ($a['is_exact'] !== $b['is_exact']) return $a['is_exact'] ? -1 : 1;
        return $a['orb'] <=> $b['orb'];
    });

    // Separate by nature
    $harmonious  = array_filter($all_aspects, function($a) { return $a['nature'] === 'harmonious'; });
    $challenging = array_filter($all_aspects, function($a) { return $a['nature'] === 'challenging'; });

    // ── Helper data ──
    $planet_vi      = function_exists('bccm_planet_names_vi') ? bccm_planet_names_vi() : [];
    $aspect_vi      = function_exists('bccm_aspect_names_vi') ? bccm_aspect_names_vi() : [];
    $aspect_symbols = function_exists('bccm_aspect_symbols')  ? bccm_aspect_symbols()  : [];
    $aspect_colors  = function_exists('bccm_aspect_colors')   ? bccm_aspect_colors()   : [];
    $signs          = function_exists('bccm_zodiac_signs')    ? bccm_zodiac_signs()    : [];

    $planet_symbols = [
        'Sun'=>'☉','Moon'=>'☽','Mercury'=>'☿','Venus'=>'♀','Mars'=>'♂',
        'Jupiter'=>'♃','Saturn'=>'♄','Uranus'=>'♅','Neptune'=>'♆','Pluto'=>'♇',
        'Chiron'=>'⚷','True Node'=>'☊','Ascendant'=>'ASC','MC'=>'MC',
    ];

    $find_sign = function($name) use ($signs) {
        foreach ($signs as $s) {
            if (strtolower($s['en'] ?? '') === strtolower($name)) return $s;
        }
        return ['vi' => $name, 'symbol' => '?', 'en' => $name];
    };

    // ── Coachee display info ──
    $name_esc = esc_html($coachee['full_name'] ?? 'Natal Chart');
    $dob_display = '';
    if (!empty($birth_data['day']) && !empty($birth_data['month']) && !empty($birth_data['year'])) {
        $dob_display = sprintf('%02d/%02d/%04d', $birth_data['day'], $birth_data['month'], $birth_data['year']);
    } elseif (!empty($coachee['dob'])) {
        $dob_display = date('d/m/Y', strtotime($coachee['dob']));
    }

    // Retrograde planets across all snapshots
    $retro_planets = [];
    foreach ($snapshots as $snap) {
        foreach ($snap['positions'] as $pname => $pos) {
            if (!empty($pos['is_retro']) && !in_array($pname, ['Ascendant','MC','IC','Descendant','Mean Node','True Node'])) {
                if (!isset($retro_planets[$pname])) {
                    $retro_planets[$pname] = [
                        'name_vi' => $planet_vi[$pname] ?? $pname,
                        'sign'    => $pos['sign_vi'] ?? ($pos['sign_en'] ?? ''),
                        'dates'   => [],
                    ];
                }
                $retro_planets[$pname]['dates'][] = $snap['label'];
            }
        }
    }

    // Slow planet highlights
    $slow_names   = ['Jupiter','Saturn','Uranus','Neptune','Pluto'];
    $slow_aspects = array_filter($all_aspects, function($a) use ($slow_names) { return in_array($a['transit_planet'], $slow_names); });

    // Nonce for other period links
    $transit_nonce = wp_create_nonce('bccm_transit_report');

    // ═══════════════════════════════════════════════════════════
    // BEGIN HTML OUTPUT
    // ═══════════════════════════════════════════════════════════
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Transit Report — <?php echo $name_esc; ?> — <?php echo esc_html($time_range['label']); ?></title>
<style>
@page { size: A4; margin: 15mm 12mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 12px; color: #1a1a2e; line-height: 1.6; background: #f0f4ff; }
.page { max-width: 960px; margin: 0 auto; background: #fff; min-height: 100vh; }

/* Toolbar */
.toolbar { text-align: center; padding: 14px 20px; background: linear-gradient(135deg,#0f172a,#1e1b4b); border-bottom: 3px solid #818cf8; }
.toolbar a { padding: 8px 18px; color: #fff; border: none; border-radius: 8px; font-size: 12px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; margin: 4px; transition: all .15s; }
.toolbar a:hover { filter: brightness(1.15); transform: translateY(-1px); }
.toolbar .btn-week { background: #3b82f6; }
.toolbar .btn-month { background: #8b5cf6; }
.toolbar .btn-year { background: #059669; }
.toolbar .btn-active { box-shadow: 0 0 0 3px rgba(255,255,255,.5); transform: scale(1.05); }
.toolbar .btn-back { background: #475569; }
.toolbar .hint { color: #94a3b8; font-size: 11px; margin-top: 8px; }

/* Cover */
.cover { background: linear-gradient(135deg,#0c0a1d,#1e1b4b,#312e81); color:#fff; padding: 40px 30px; text-align: center; }
.cover h1 { font-size: 28px; font-weight: 800; color: #a78bfa; margin-bottom: 4px; }
.cover .csub { font-size: 12px; color: #94a3b8; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 20px; }
.cover .cname { font-size: 20px; font-weight: 700; color: #e0e7ff; }
.cover .cmeta { font-size: 12px; color: #818cf8; margin: 3px 0; }
.cover .period-badge { display: inline-block; background: linear-gradient(135deg,#7c3aed,#6366f1); padding: 8px 24px; border-radius: 99px; font-size: 16px; font-weight: 700; color: #fbbf24; margin-top: 16px; letter-spacing: 1px; }

/* Content */
.content { padding: 24px 30px; }
.section { margin-bottom: 28px; }
.section h2 { font-size: 16px; font-weight: 700; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }

/* Snapshot cards */
.snapshot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px; }
.snapshot-card { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
.snapshot-card .card-header { background: linear-gradient(135deg,#1e1b4b,#312e81); color: #fff; padding: 10px 14px; font-weight: 600; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
.snapshot-card .card-header .date { color: #a5b4fc; font-size: 11px; font-weight: 400; }
.snapshot-card .card-body { padding: 10px 14px; }
.snapshot-card .planet-row { display: flex; justify-content: space-between; align-items: center; padding: 3px 0; border-bottom: 1px solid #f1f5f9; font-size: 11px; }
.snapshot-card .planet-row:last-child { border-bottom: none; }
.snapshot-card .planet-name { font-weight: 600; color: #334155; }
.snapshot-card .planet-sign { color: #6366f1; }
.snapshot-card .planet-retro { color: #ef4444; font-weight: 700; font-size: 13px; }

/* Aspect tables */
table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 11.5px; }
th { background: #f1f5f9; color: #334155; font-weight: 600; text-align: left; padding: 6px 8px; border-bottom: 2px solid #e2e8f0; }
td { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; }
tr:nth-child(even) td { background: #fafbfc; }
.exact-badge { background: #fef3c7; color: #92400e; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; }
.orb-tight { color: #059669; font-weight: 700; }
.orb-normal { color: #2563eb; }

/* Retro & Slow */
.retro-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
.retro-card { background: linear-gradient(135deg,#fef2f2,#fff1f2); border: 1px solid #fecaca; border-radius: 10px; padding: 12px 16px; }
.retro-card .retro-planet { font-weight: 700; color: #dc2626; font-size: 14px; }
.retro-card .retro-sign { color: #9f1239; font-size: 12px; margin-top: 2px; }

.slow-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; }
.slow-card { background: linear-gradient(135deg,#f5f3ff,#ede9fe); border: 1px solid #ddd6fe; border-radius: 10px; padding: 12px 16px; }
.slow-card .slow-planet { font-weight: 700; color: #6d28d9; font-size: 14px; }
.slow-card .slow-detail { color: #5b21b6; font-size: 11px; margin-top: 4px; }

/* Summary box */
.summary-box { background: linear-gradient(135deg,#ecfdf5,#f0fdf4); border: 1px solid #bbf7d0; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
.summary-box h3 { font-size: 15px; color: #166534; margin-bottom: 10px; }
.summary-stat { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 10px; }
.summary-stat .stat { background: #fff; border: 1px solid #d1fae5; border-radius: 8px; padding: 10px 16px; text-align: center; min-width: 100px; }
.summary-stat .stat-num { font-size: 24px; font-weight: 800; color: #059669; }
.summary-stat .stat-label { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; }

/* Interpretation guide */
.guide { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 16px 20px; margin-top: 24px; }
.guide h3 { font-size: 14px; color: #92400e; margin-bottom: 8px; }
.guide ul { padding-left: 18px; font-size: 11.5px; color: #78350f; }
.guide li { margin-bottom: 4px; }

/* Footer */
.footer { text-align: center; padding: 20px; color: #9ca3af; font-size: 10px; border-top: 1px solid #e2e8f0; margin-top: 20px; }

@media print {
  .toolbar { display: none; }
  body { background: #fff; }
  .page { box-shadow: none; }
}
</style>
</head>
<body>
<div class="page">

<!-- TOOLBAR -->
<div class="toolbar">
    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=week&_wpnonce=' . $transit_nonce)); ?>" class="btn-week<?php echo $period === 'week' ? ' btn-active' : ''; ?>">📅 Tuần tới</a>
    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=month&_wpnonce=' . $transit_nonce)); ?>" class="btn-month<?php echo $period === 'month' ? ' btn-active' : ''; ?>">🗓️ Tháng tới</a>
    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=year&_wpnonce=' . $transit_nonce)); ?>" class="btn-year<?php echo $period === 'year' ? ' btn-active' : ''; ?>">📆 Năm tới</a>
    <a href="javascript:window.print()" class="btn-back" style="background:#64748b">🖨️ In / PDF</a>
    <a href="<?php echo esc_url(admin_url('admin.php?page=bccm_my_profile')); ?>" class="btn-back">← Quay lại hồ sơ</a>
    <div class="hint">Transit Report — Dữ liệu dịch chuyển sao so sánh với bản đồ natal của bạn</div>
</div>

<!-- COVER -->
<div class="cover">
    <h1>🔮 Bản Đồ Sao Dịch Chuyển</h1>
    <div class="csub">Transit Astrology Report</div>
    <div class="cname"><?php echo $name_esc; ?></div>
    <?php if ($dob_display): ?><div class="cmeta">📅 <?php echo esc_html($dob_display); ?><?php if (!empty($astro_row['birth_time'])) echo ' 🕐 ' . esc_html($astro_row['birth_time']); ?><?php if (!empty($astro_row['birth_place'])) echo ' 📍 ' . esc_html($astro_row['birth_place']); ?></div><?php endif; ?>
    <div class="cmeta">📊 Phân tích ngày: <?php echo current_time('d/m/Y H:i'); ?></div>
    <div class="period-badge">🔮 <?php echo esc_html($time_range['label']); ?></div>
</div>

<div class="content">

<?php if (empty($snapshots)): ?>
    <div style="text-align:center;padding:60px 20px;color:#9ca3af">
        <p style="font-size:40px">⏳</p>
        <p style="font-size:16px;margin-top:12px;color:#1e293b;font-weight:600">Dữ liệu transit chưa sẵn sàng</p>
        <p style="margin-top:8px;color:#475569"><?php echo esc_html($no_data_msg); ?></p>
        <p style="margin-top:16px;font-size:11px;color:#94a3b8">Dữ liệu được tự động tạo sau khi bản đồ sao được tạo/cập nhật.</p>
        <p style="margin-top:12px"><a href="<?php echo esc_url(admin_url('admin.php?page=bccm_my_profile')); ?>" style="background:#6366f1;color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-size:12px">← Quay lại hồ sơ</a></p>
    </div>
<?php else: ?>

    <!-- SUMMARY -->
    <div class="summary-box">
        <h3>📊 Tổng Quan Transit — <?php echo esc_html($time_range['label']); ?></h3>
        <p style="color:#374151;font-size:12px">Phân tích <?php echo count($snapshots); ?> thời điểm, tìm thấy <?php echo count($all_aspects); ?> góc chiếu transit ↔ natal.</p>
        <div class="summary-stat">
            <div class="stat">
                <div class="stat-num"><?php echo count($harmonious); ?></div>
                <div class="stat-label">✅ Thuận lợi</div>
            </div>
            <div class="stat">
                <div class="stat-num"><?php echo count($challenging); ?></div>
                <div class="stat-label">⚡ Thách thức</div>
            </div>
            <div class="stat">
                <div class="stat-num"><?php echo count($retro_planets); ?></div>
                <div class="stat-label">℞ Nghịch hành</div>
            </div>
            <div class="stat">
                <div class="stat-num"><?php echo count($slow_aspects); ?></div>
                <div class="stat-label">🌍 Sao chậm</div>
            </div>
        </div>
    </div>

    <!-- SKY SNAPSHOTS -->
    <div class="section">
        <h2>🌌 Bầu Trời Qua Các Thời Điểm</h2>
        <div class="snapshot-grid">
        <?php
        $key_planets = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto'];
        foreach ($snapshots as $snap):
        ?>
            <div class="snapshot-card">
                <div class="card-header">
                    <span><?php echo esc_html($snap['label']); ?></span>
                    <span class="date"><?php echo esc_html(date('d/m/Y', strtotime($snap['date']))); ?></span>
                </div>
                <div class="card-body">
                    <?php foreach ($key_planets as $pname):
                        if (empty($snap['positions'][$pname])) continue;
                        $p = $snap['positions'][$pname];
                        $vi = $planet_vi[$pname] ?? $pname;
                        $sym = $planet_symbols[$pname] ?? '';
                        $sign_vi = $p['sign_vi'] ?? ($p['sign_en'] ?? '');
                        $deg = round($p['norm_degree'] ?? 0, 1);
                        $retro = !empty($p['is_retro']);
                    ?>
                    <div class="planet-row">
                        <span class="planet-name"><?php echo $sym; ?> <?php echo esc_html($vi); ?></span>
                        <span class="planet-sign"><?php echo esc_html($sign_vi); ?> <?php echo $deg; ?>°</span>
                        <?php if ($retro): ?><span class="planet-retro">℞</span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <?php if (!empty($retro_planets)): ?>
    <!-- RETROGRADE ALERTS -->
    <div class="section">
        <h2>⚠️ Sao Nghịch Hành (Retrograde)</h2>
        <p style="color:#6b7280;font-size:11px;margin-bottom:12px">Khi hành tinh nghịch hành, năng lượng hướng nội — thời điểm xem xét lại, không nên khởi đầu mới.</p>
        <div class="retro-grid">
            <?php foreach ($retro_planets as $pname => $info): ?>
            <div class="retro-card">
                <div class="retro-planet"><?php echo ($planet_symbols[$pname] ?? ''); ?> <?php echo esc_html($info['name_vi']); ?> ℞</div>
                <div class="retro-sign">Trong cung: <?php echo esc_html($info['sign']); ?></div>
                <div style="font-size:10px;color:#9f1239;margin-top:4px">Phát hiện tại: <?php echo esc_html(implode(', ', array_unique($info['dates']))); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- HARMONIOUS ASPECTS -->
    <?php if (!empty($harmonious)): ?>
    <div class="section">
        <h2>✅ Góc Chiếu Thuận Lợi (Transit → Natal)</h2>
        <p style="color:#6b7280;font-size:11px;margin-bottom:10px">Những góc chiếu hỗ trợ, mang cơ hội và năng lượng tích cực.</p>
        <table>
            <thead>
                <tr>
                    <th style="width:22%">Transit Planet</th>
                    <th style="width:10%;text-align:center">Aspect</th>
                    <th style="width:22%">Natal Planet</th>
                    <th style="width:12%">Orb</th>
                    <th style="width:14%">Thời điểm</th>
                    <th style="width:20%">Ghi chú</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($harmonious as $a):
                $t_vi  = $planet_vi[$a['transit_planet']] ?? $a['transit_planet'];
                $n_vi  = $planet_vi[$a['natal_planet']] ?? $a['natal_planet'];
                $a_vi  = $aspect_vi[$a['aspect']] ?? $a['aspect'];
                $a_sym = $aspect_symbols[$a['aspect']] ?? '';
                $a_clr = $aspect_colors[$a['aspect']] ?? '#888';
                $orb_class = $a['orb'] < 1 ? 'orb-tight' : ($a['orb'] < 3 ? 'orb-normal' : '');
            ?>
            <tr>
                <td><strong><?php echo ($planet_symbols[$a['transit_planet']] ?? ''); ?> <?php echo esc_html($t_vi); ?></strong> <small style="color:#6b7280">(<?php echo esc_html($a['transit_sign']); ?>)</small><?php if ($a['transit_retro']) echo ' <span style="color:#ef4444">℞</span>'; ?></td>
                <td style="text-align:center;font-size:16px;color:<?php echo $a_clr; ?>" title="<?php echo esc_attr($a['aspect']); ?>"><?php echo $a_sym; ?></td>
                <td><strong><?php echo ($planet_symbols[$a['natal_planet']] ?? ''); ?> <?php echo esc_html($n_vi); ?></strong> <small style="color:#6b7280">(<?php echo esc_html($a['natal_sign']); ?>)</small></td>
                <td class="<?php echo $orb_class; ?>"><?php echo $a['orb']; ?>°<?php if ($a['is_exact']) echo ' <span class="exact-badge">⭐ EXACT</span>'; ?></td>
                <td style="font-size:11px;color:#6366f1"><?php echo esc_html($a['transit_label']); ?></td>
                <td style="font-size:10px;color:#059669"><?php echo esc_html($a_vi); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- CHALLENGING ASPECTS -->
    <?php if (!empty($challenging)): ?>
    <div class="section">
        <h2>⚡ Góc Chiếu Thách Thức (Transit → Natal)</h2>
        <p style="color:#6b7280;font-size:11px;margin-bottom:10px">Những góc chiếu mang căng thẳng, nhưng cũng là động lực thay đổi và trưởng thành.</p>
        <table>
            <thead>
                <tr>
                    <th style="width:22%">Transit Planet</th>
                    <th style="width:10%;text-align:center">Aspect</th>
                    <th style="width:22%">Natal Planet</th>
                    <th style="width:12%">Orb</th>
                    <th style="width:14%">Thời điểm</th>
                    <th style="width:20%">Ghi chú</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($challenging as $a):
                $t_vi  = $planet_vi[$a['transit_planet']] ?? $a['transit_planet'];
                $n_vi  = $planet_vi[$a['natal_planet']] ?? $a['natal_planet'];
                $a_vi  = $aspect_vi[$a['aspect']] ?? $a['aspect'];
                $a_sym = $aspect_symbols[$a['aspect']] ?? '';
                $a_clr = $aspect_colors[$a['aspect']] ?? '#888';
                $orb_class = $a['orb'] < 1 ? 'orb-tight' : ($a['orb'] < 3 ? 'orb-normal' : '');
            ?>
            <tr>
                <td><strong><?php echo ($planet_symbols[$a['transit_planet']] ?? ''); ?> <?php echo esc_html($t_vi); ?></strong> <small style="color:#6b7280">(<?php echo esc_html($a['transit_sign']); ?>)</small><?php if ($a['transit_retro']) echo ' <span style="color:#ef4444">℞</span>'; ?></td>
                <td style="text-align:center;font-size:16px;color:<?php echo $a_clr; ?>" title="<?php echo esc_attr($a['aspect']); ?>"><?php echo $a_sym; ?></td>
                <td><strong><?php echo ($planet_symbols[$a['natal_planet']] ?? ''); ?> <?php echo esc_html($n_vi); ?></strong> <small style="color:#6b7280">(<?php echo esc_html($a['natal_sign']); ?>)</small></td>
                <td class="<?php echo $orb_class; ?>"><?php echo $a['orb']; ?>°<?php if ($a['is_exact']) echo ' <span class="exact-badge">⭐ EXACT</span>'; ?></td>
                <td style="font-size:11px;color:#6366f1"><?php echo esc_html($a['transit_label']); ?></td>
                <td style="font-size:10px;color:#ef4444"><?php echo esc_html($a_vi); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- SLOW PLANET TRANSITS -->
    <?php if (!empty($slow_aspects)): ?>
    <div class="section">
        <h2>🌍 Dịch Chuyển Sao Chậm (Long-term Transits)</h2>
        <p style="color:#6b7280;font-size:11px;margin-bottom:12px">Những hành tinh chuyển động chậm (Jupiter → Pluto) tạo ảnh hưởng sâu rộng, kéo dài nhiều tháng.</p>
        <div class="slow-grid">
            <?php
            $slow_shown = [];
            foreach ($slow_aspects as $a):
                $key = $a['transit_planet'] . '_' . $a['natal_planet'];
                if (isset($slow_shown[$key])) continue;
                $slow_shown[$key] = true;
                $t_vi = $planet_vi[$a['transit_planet']] ?? $a['transit_planet'];
                $n_vi = $planet_vi[$a['natal_planet']] ?? $a['natal_planet'];
                $a_vi = $aspect_vi[$a['aspect']] ?? $a['aspect'];
                $nature_label = $a['nature'] === 'harmonious' ? '✅ Thuận lợi' : '⚡ Thách thức';
                $nature_color = $a['nature'] === 'harmonious' ? '#059669' : '#dc2626';
            ?>
            <div class="slow-card">
                <div class="slow-planet"><?php echo ($planet_symbols[$a['transit_planet']] ?? ''); ?> <?php echo esc_html($t_vi); ?> đang ở <?php echo esc_html($a['transit_sign']); ?></div>
                <div class="slow-detail">
                    → <?php echo esc_html($a_vi); ?> <?php echo esc_html($n_vi); ?> natal (<?php echo esc_html($a['natal_sign']); ?>) — orb <?php echo $a['orb']; ?>°
                    <br><span style="color:<?php echo $nature_color; ?>;font-weight:600"><?php echo $nature_label; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- NATAL vs TRANSIT COMPARISON TABLE -->
    <div class="section">
        <h2>🔄 So Sánh Natal ↔ Transit (Hôm nay)</h2>
        <?php if (!empty($snapshots[0])): ?>
        <table>
            <thead>
                <tr>
                    <th style="width:22%">Hành tinh</th>
                    <th style="width:20%">Natal (Gốc)</th>
                    <th style="width:20%">Transit (Nay)</th>
                    <th style="width:18%">Khoảng cách</th>
                    <th style="width:20%">Ghi chú</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $compare_planets = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto'];
            $today_pos = $snapshots[0]['positions'];
            foreach ($compare_planets as $pname):
                if (empty($natal_positions[$pname]) || empty($today_pos[$pname])) continue;
                $n = $natal_positions[$pname];
                $t = $today_pos[$pname];
                $n_sign = $n['sign_vi'] ?? ($n['sign_en'] ?? '');
                $t_sign = $t['sign_vi'] ?? ($t['sign_en'] ?? '');
                $n_deg  = round($n['norm_degree'] ?? 0, 1);
                $t_deg  = round($t['norm_degree'] ?? 0, 1);
                $diff   = abs(($t['full_degree'] ?? 0) - ($n['full_degree'] ?? 0));
                if ($diff > 180) $diff = 360 - $diff;
                $diff_r = round($diff, 1);
                $same_sign = (strtolower($n['sign_en'] ?? '') === strtolower($t['sign_en'] ?? ''));
                $retro_t = !empty($t['is_retro']);
            ?>
            <tr>
                <td><strong><?php echo ($planet_symbols[$pname] ?? ''); ?> <?php echo esc_html($planet_vi[$pname] ?? $pname); ?></strong></td>
                <td><?php echo esc_html($n_sign); ?> <?php echo $n_deg; ?>°</td>
                <td><?php echo esc_html($t_sign); ?> <?php echo $t_deg; ?>°<?php if ($retro_t) echo ' <span style="color:#ef4444">℞</span>'; ?></td>
                <td><?php echo $diff_r; ?>°</td>
                <td style="font-size:10px">
                    <?php if ($same_sign): ?><span style="color:#059669">● Cùng cung</span><?php else: ?><span style="color:#6b7280">○ Khác cung</span><?php endif; ?>
                    <?php if ($diff_r < 5): ?><span style="color:#dc2626;font-weight:600"> — GẦN CHÍNH XÁC</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- INTERPRETATION GUIDE -->
    <div class="guide">
        <h3>📝 Hướng Dẫn Đọc Bản Đồ Transit</h3>
        <ul>
            <li><strong>Conjunction (Hợp — 0°):</strong> Năng lượng hợp nhất, khởi đầu mới trong lĩnh vực liên quan.</li>
            <li><strong>Trine (Tam hợp — 120°) &amp; Sextile (Lục hợp — 60°):</strong> Thuận lợi, cơ hội tự nhiên, dòng chảy dễ dàng.</li>
            <li><strong>Square (Vuông — 90°):</strong> Căng thẳng, xung đột nhưng thúc đẩy hành động và thay đổi.</li>
            <li><strong>Opposition (Đối — 180°):</strong> Đối đầu, cần cân bằng hai cực, nhận ra hai mặt vấn đề.</li>
            <li><strong>Sao nghịch hành (℞):</strong> Năng lượng hướng nội — xem xét lại, sửa chữa, chậm lại. Không nên khởi đầu mới.</li>
            <li><strong>Sao chậm (Jupiter → Pluto):</strong> Ảnh hưởng kéo dài nhiều tháng → tác động sâu sắc lên cuộc sống.</li>
            <li><strong>Orb nhỏ (&lt; 1°):</strong> Aspect chính xác — ảnh hưởng mạnh nhất; orb &lt; 3° vẫn rất đáng chú ý.</li>
        </ul>
    </div>

<?php endif; ?>

</div><!-- .content -->

<!-- FOOTER -->
<div class="footer">
    Transit Report — <?php echo $name_esc; ?> — <?php echo esc_html($time_range['label']); ?><br>
    Generated: <?php echo current_time('d/m/Y H:i:s'); ?> | Powered by Astrology API + BizCoach Map
</div>

</div><!-- .page -->
</body>
</html>
<?php
    exit;
}
