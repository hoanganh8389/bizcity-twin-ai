<?php
/**
 * BizCoach Map – Frontend Standalone Natal Chart Viewer
 *
 * Công khai bản đồ sao (Natal Chart) dưới dạng:
 *   - Trang standalone với URL: /my-natal-chart/?token=xxx
 *   - Có thể export PDF
 *   - Không cần đăng nhập (dùng token bảo mật)
 *
 * Shortcode: [bccm_natal_chart token="xxx"] hoặc auto-detect from URL param
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * REGISTER REWRITE ENDPOINT: my-natal-chart
 * =====================================================================*/
add_action('init', function () {
  add_rewrite_rule('^my-natal-chart/?', 'index.php?bccm_natal_chart=1', 'top');
});

add_filter('query_vars', function ($vars) {
  $vars[] = 'bccm_natal_chart';
  $vars[] = 'chart_id';
  $vars[] = 'chart_hash';
  $vars[] = 'export_pdf';
  return $vars;
});

/* =====================================================================
 * HANDLE NATAL CHART PAGE TEMPLATE
 * =====================================================================*/
add_action('template_redirect', function () {
  if (get_query_var('bccm_natal_chart')) {
    $chart_id = intval($_GET['id'] ?? get_query_var('chart_id'));
    $chart_hash = sanitize_text_field($_GET['hash'] ?? get_query_var('chart_hash'));
    $export_pdf = !empty($_GET['export_pdf']) || get_query_var('export_pdf');

    if (empty($chart_id) || empty($chart_hash)) {
      wp_die('Link không hợp lệ. Vui lòng kiểm tra lại.');
    }

    // Find coachee by id and verify hash
    global $wpdb;
    $t = bccm_tables();
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t['profiles']} WHERE id = %d LIMIT 1", $chart_id
    ), ARRAY_A);

    // Verify hash
    if (!$coachee || !bccm_verify_natal_chart_hash($chart_id, $chart_hash)) {
      wp_die('Link không hợp lệ hoặc đã hết hạn.');
    }

    if (!$coachee) {
      wp_die('Bản đồ sao không tồn tại hoặc đã bị xóa.');
    }

    $coachee_id = (int)$coachee['id'];
    $user_id = (int)($coachee['user_id'] ?? 0);

    // Load astro data by user_id (consistent across platforms)
    $t_astro = $wpdb->prefix . 'bccm_astro';
    
    // Query by user_id first for cross-platform consistency
    if ($user_id > 0) {
      $astro_western = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY id DESC LIMIT 1", $user_id
      ), ARRAY_A);
      $astro_vedic = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic' AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY id DESC LIMIT 1", $user_id
      ), ARRAY_A);
    }
    
    // Fallback to coachee_id if user_id has no data
    if (empty($astro_western) && empty($astro_vedic)) {
      $astro_western = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $t_astro WHERE coachee_id=%d AND chart_type='western'", $coachee_id
      ), ARRAY_A);
      $astro_vedic = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $t_astro WHERE coachee_id=%d AND chart_type='vedic'", $coachee_id
      ), ARRAY_A);
    }

    if (!$astro_western && !$astro_vedic) {
      wp_die('Bản đồ sao chưa được tạo. Vui lòng liên hệ admin.');
    }

    // If PDF export requested
    if ($export_pdf) {
      bccm_natal_chart_export_pdf($coachee, $astro_western, $astro_vedic);
      exit;
    }

    // Render HTML view
    bccm_natal_chart_render_html($coachee, $astro_western, $astro_vedic);
    exit;
  }
});

/* =====================================================================
 * SHORTCODE: [bccm_natal_chart token="xxx"]
 * =====================================================================*/
add_shortcode('bccm_natal_chart', function ($atts) {
  $atts = shortcode_atts([
    'id' => '',
    'hash' => '',
  ], $atts, 'bccm_natal_chart');

  $chart_id = intval($atts['id'] ?: $_GET['id'] ?? 0);
  $chart_hash = sanitize_text_field($atts['hash'] ?: $_GET['hash'] ?? '');
  
  if (empty($chart_id) || empty($chart_hash)) {
    return '<div class="bccm-notice bccm-notice-error"><p>Link không hợp lệ.</p></div>';
  }

  global $wpdb;
  $t = bccm_tables();
  $coachee = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$t['profiles']} WHERE id = %d LIMIT 1", $chart_id
  ), ARRAY_A);

  if (!$coachee || !bccm_verify_natal_chart_hash($chart_id, $chart_hash)) {
    return '<div class="bccm-notice bccm-notice-error"><p>Link không hợp lệ.</p></div>';
  }

  if (!$coachee) {
    return '<div class="bccm-notice bccm-notice-error"><p>Bản đồ sao không tồn tại.</p></div>';
  }

  $coachee_id = (int)$coachee['id'];
  $user_id = (int)($coachee['user_id'] ?? 0);
  $t_astro = $wpdb->prefix . 'bccm_astro';
  
  // Query by user_id first for cross-platform consistency
  if ($user_id > 0) {
    $astro_western = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='western' AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
    $astro_vedic = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t_astro WHERE user_id=%d AND chart_type='vedic' AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
  }
  
  // Fallback to coachee_id if user_id has no data
  if (empty($astro_western) && empty($astro_vedic)) {
    $astro_western = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t_astro WHERE coachee_id=%d AND chart_type='western'", $coachee_id
    ), ARRAY_A);
    $astro_vedic = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t_astro WHERE coachee_id=%d AND chart_type='vedic'", $coachee_id
    ), ARRAY_A);
  }

  ob_start();
  bccm_natal_chart_render_html($coachee, $astro_western, $astro_vedic, false);
  return ob_get_clean();
});

/* =====================================================================
 * RENDER HTML: Standalone Natal Chart View
 * =====================================================================*/
function bccm_natal_chart_render_html($coachee, $astro_western, $astro_vedic, $full_page = true) {
  $coachee_id = (int)$coachee['id'];
  $name = esc_html($coachee['full_name'] ?? 'Chủ nhân bản đồ');
  $dob = $coachee['dob'] ?? '';
  $zodiac = $coachee['zodiac_sign'] ?? '';

  // Parse data
  $astro_summary = !empty($astro_western['summary']) ? json_decode($astro_western['summary'], true) : [];
  $astro_traits  = !empty($astro_western['traits'])  ? json_decode($astro_western['traits'], true) : [];
  $vedic_summary = !empty($astro_vedic['summary']) ? json_decode($astro_vedic['summary'], true) : [];
  $vedic_traits  = !empty($astro_vedic['traits'])  ? json_decode($astro_vedic['traits'], true) : [];
  $has_western   = !empty($astro_summary) || !empty($astro_traits);
  $has_vedic     = !empty($vedic_summary) || !empty($vedic_traits);
  
  $birth_data = $astro_traits['birth_data'] ?? $vedic_traits['birth_data'] ?? [];

  if ($full_page) {
    // Full page with HTML header
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
      <meta charset="<?php bloginfo('charset'); ?>">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>🌟 Bản Đồ Sao - <?php echo $name; ?></title>
      <?php wp_head(); ?>
      <link rel="stylesheet" href="<?php echo esc_url(BCCM_URL . 'assets/css/natal-chart-public.css'); ?>?v=<?php echo BCCM_VERSION; ?>">
      <style>
        body {
          margin: 0;
          padding: 20px;
          font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
          background: linear-gradient(135deg, #0f0a1e 0%, #1e1b4b 30%, #312e81 60%, #0f0a1e 100%);
          color: #e2e8f0;
          min-height: 100vh;
        }
        .bccm-natal-public-wrap {
          max-width: 1200px;
          margin: 0 auto;
        }
      </style>
    </head>
    <body class="bccm-natal-chart-public">
    <?php
  }

  // Enqueue CSS
  wp_enqueue_style('bccm-natal-public', BCCM_URL . 'assets/css/natal-chart-public.css', [], BCCM_VERSION);

  ?>
  <div class="bccm-natal-public-wrap">

    <!-- HEADER -->
    <div class="bccm-natal-header">
      <div class="natal-header-top">
        <div>
          <h1>🌟 Bản Đồ Sao Cá Nhân</h1>
          <p class="natal-subtitle">Natal Chart - Western + Vedic Astrology</p>
        </div>
        <div class="natal-actions">
          <?php 
          $chart_url_params = 'id=' . $coachee_id . '&hash=' . bccm_generate_natal_chart_hash($coachee_id);
          ?>
          <a href="?<?php echo $chart_url_params; ?>&export_pdf=1" class="btn-export" target="_blank">
            📥 Tải PDF
          </a>
          <button onclick="window.print()" class="btn-print">
            🖨️ In
          </button>
        </div>
      </div>

      <!-- Birth Info -->
      <div class="natal-birth-info">
        <div class="birth-info-item">
          <span class="icon">👤</span>
          <span class="label">Họ tên:</span>
          <strong><?php echo $name; ?></strong>
        </div>
        <?php if ($dob): ?>
        <div class="birth-info-item">
          <span class="icon">📅</span>
          <span class="label">Ngày sinh:</span>
          <strong><?php echo date('d/m/Y', strtotime($dob)); ?></strong>
        </div>
        <?php endif; ?>
        <?php if (!empty($astro_western['birth_time'])): ?>
        <div class="birth-info-item">
          <span class="icon">🕐</span>
          <span class="label">Giờ sinh:</span>
          <strong><?php echo esc_html($astro_western['birth_time']); ?></strong>
        </div>
        <?php endif; ?>
        <?php if (!empty($astro_western['birth_place'])): ?>
        <div class="birth-info-item">
          <span class="icon">📍</span>
          <span class="label">Nơi sinh:</span>
          <strong><?php echo esc_html($astro_western['birth_place']); ?></strong>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- BIG 3 CARDS -->
    <?php
    $sun_sign = $astro_summary['sun_sign'] ?? $vedic_summary['sun_sign'] ?? '';
    $moon_sign = $astro_summary['moon_sign'] ?? $vedic_summary['moon_sign'] ?? '';
    $asc_sign = $astro_summary['ascendant_sign'] ?? $vedic_summary['ascendant_sign'] ?? '';

    if ($sun_sign || $moon_sign || $asc_sign):
      $signs = bccm_zodiac_signs();
      $find_sign = function($name) use ($signs) {
        foreach ($signs as $s) {
          if (strtolower($s['en'] ?? '') === strtolower($name)) return $s;
        }
        return ['vi' => $name, 'symbol' => '?', 'en' => $name, 'element' => ''];
      };
    ?>
    <div class="bccm-big3-cards">
      <?php if ($sun_sign):
        $sun_info = $find_sign($sun_sign);
      ?>
      <div class="big3-card sun-card">
        <div class="card-icon">☀️</div>
        <div class="card-title">Mặt Trời (Sun)</div>
        <div class="card-symbol"><?php echo esc_html($sun_info['symbol']); ?></div>
        <div class="card-sign"><?php echo esc_html($sun_info['vi']); ?></div>
        <div class="card-en"><?php echo esc_html($sun_info['en']); ?></div>
        <div class="card-desc">Bản ngã, ý chí sống</div>
      </div>
      <?php endif; ?>

      <?php if ($moon_sign):
        $moon_info = $find_sign($moon_sign);
      ?>
      <div class="big3-card moon-card">
        <div class="card-icon">🌙</div>
        <div class="card-title">Mặt Trăng (Moon)</div>
        <div class="card-symbol"><?php echo esc_html($moon_info['symbol']); ?></div>
        <div class="card-sign"><?php echo esc_html($moon_info['vi']); ?></div>
        <div class="card-en"><?php echo esc_html($moon_info['en']); ?></div>
        <div class="card-desc">Cảm xúc, nội tâm</div>
      </div>
      <?php endif; ?>

      <?php if ($asc_sign):
        $asc_info = $find_sign($asc_sign);
      ?>
      <div class="big3-card asc-card">
        <div class="card-icon">⬆️</div>
        <div class="card-title">Cung Mọc (ASC)</div>
        <div class="card-symbol"><?php echo esc_html($asc_info['symbol']); ?></div>
        <div class="card-sign"><?php echo esc_html($asc_info['vi']); ?></div>
        <div class="card-en"><?php echo esc_html($asc_info['en']); ?></div>
        <div class="card-desc">Ấn tượng đầu tiên</div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- CHARTS DISPLAY -->
     <style>
        .bccm-charts-grid  {
          /* display: grid; */
          grid-template-columns: unset !important;
          /* gap: 20px; */
          margin-bottom: 32px;
      }
      .bccm-charts-grid {
          /* display: grid; */
          grid-template-columns: unset !important;
          gap: 20px;
          margin-bottom: 32px;
      }
      </style>
    <div class="bccm-charts-grid">
      <!-- Western Chart -->
      <?php if ($has_western): ?>
      <div class="chart-panel">
        <h2 class="panel-title">🌟 Western Astrology</h2>
        <p class="panel-subtitle">Tropical - Placidus System</p>
        <?php
        $chart_url = $astro_western['chart_svg'] ?? $astro_summary['chart_url'] ?? '';
        if ($chart_url):
        ?>
        <div class="chart-image">
          <img src="<?php echo esc_url($chart_url); ?>" alt="Western Natal Chart">
        </div>
        <?php endif; ?>

        <!-- Planets Table -->
        <?php
        $positions = $astro_traits['positions'] ?? [];
        if (!empty($positions)):
        ?>
        <div class="chart-table-wrap">
          <h3>🪐 Vị Trí Các Hành Tinh</h3>
          <table class="natal-table">
            <thead>
              <tr>
                <th>Hành tinh</th>
                <th>Cung</th>
                <th>Vị trí</th>
                <th>Nhà</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $planet_order = ['Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Ascendant'];
              $planet_symbols = [
                'Sun' => '☉', 'Moon' => '☽', 'Mercury' => '☿', 'Venus' => '♀', 'Mars' => '♂',
                'Jupiter' => '♃', 'Saturn' => '♄', 'Uranus' => '♅', 'Neptune' => '♆', 'Pluto' => '♇',
                'Ascendant' => 'ASC',
              ];
              foreach ($planet_order as $pname):
                if (!isset($positions[$pname])) continue;
                $p = $positions[$pname];
                $symbol = $planet_symbols[$pname] ?? '';
                $dms = bccm_astro_decimal_to_dms($p['norm_degree'] ?? 0);
              ?>
              <tr>
                <td><span class="planet-symbol"><?php echo $symbol; ?></span> <?php echo esc_html($p['planet_vi'] ?? $pname); ?></td>
                <td><?php echo esc_html($p['sign_symbol'] ?? ''); ?> <?php echo esc_html($p['sign_vi'] ?? ''); ?></td>
                <td class="mono"><?php echo esc_html($dms); ?></td>
                <td>—</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Vedic Chart -->
      <?php if ($has_vedic): ?>
      <div class="chart-panel">
        <h2 class="panel-title">🕉️ Vedic Astrology</h2>
        <p class="panel-subtitle">Sidereal - Indian System</p>
        <?php
        // Build Vedic wheel from positions
        $vedic_positions = $vedic_traits['positions'] ?? [];
        $vedic_houses = $vedic_traits['houses'] ?? [];
        if (!empty($vedic_positions) && !empty($vedic_houses)):
          $vedic_wheel_url = bccm_build_astroviet_wheel_url($vedic_positions, $vedic_houses, $name, $birth_data);
          if ($vedic_wheel_url):
        ?>
        <div class="chart-image">
          <img src="<?php echo esc_url($vedic_wheel_url); ?>" alt="Vedic Natal Chart">
        </div>
        <?php
          endif;
        endif;
        ?>

        <!-- Vedic Planets Table -->
        <?php if (!empty($vedic_positions)): ?>
        <div class="chart-table-wrap">
          <h3>🪐 Vị Trí Các Graha</h3>
          <table class="natal-table">
            <thead>
              <tr>
                <th>Graha</th>
                <th>Rashi</th>
                <th>Vị trí</th>
                <th>Chúa cung</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $vedic_order = ['Sun','Moon','Mars','Mercury','Jupiter','Venus','Saturn','Rahu','Ketu','Ascendant'];
              $vedic_symbols = [
                'Sun' => '☉', 'Moon' => '☽', 'Mars' => '♂', 'Mercury' => '☿', 'Jupiter' => '♃',
                'Venus' => '♀', 'Saturn' => '♄', 'Rahu' => '☊', 'Ketu' => '☋', 'Ascendant' => 'ASC',
              ];
              foreach ($vedic_order as $pname):
                if (!isset($vedic_positions[$pname])) continue;
                $p = $vedic_positions[$pname];
                $symbol = $vedic_symbols[$pname] ?? '';
                $dms = bccm_astro_decimal_to_dms($p['norm_degree'] ?? 0);
              ?>
              <tr>
                <td><span class="planet-symbol"><?php echo $symbol; ?></span> <?php echo esc_html($p['planet_vi'] ?? $pname); ?></td>
                <td><?php echo esc_html($p['sign_symbol'] ?? ''); ?> <?php echo esc_html($p['sign_vi'] ?? ''); ?></td>
                <td class="mono"><?php echo esc_html($dms); ?></td>
                <td><?php echo esc_html($p['sign_lord'] ?? '—'); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php
    // ══════════════ HOUSES TABLE (12 Cung Nhà) ══════════════
    $houses_data = $astro_traits['houses'] ?? [];
    $houses_raw = [];
    if (!empty($houses_data)) {
      if (isset($houses_data[0]['House']) || isset($houses_data[0]['house'])) {
        $houses_raw = $houses_data;
      } elseif (isset($houses_data['Houses'])) {
        $houses_raw = $houses_data['Houses'];
      }
    }

    if (!empty($houses_raw)):
      $signs = bccm_zodiac_signs();
      $house_meanings = function_exists('bccm_house_meanings_vi') ? bccm_house_meanings_vi() : [];
    ?>
    <div class="chart-section">
      <h3 class="section-title">🏛️ Vị Trí 12 Cung Nhà</h3>
      <p class="section-subtitle">Hệ thống Placidus</p>
      <table class="natal-table natal-table-houses">
        <thead>
          <tr>
            <th style="width:12%">Nhà</th>
            <th style="width:20%">Cung</th>
            <th style="width:22%">Đỉnh cung</th>
            <th>Ý nghĩa</th>
          </tr>
        </thead>
        <tbody>
          <?php
          foreach ($houses_raw as $h):
            $num = $h['House'] ?? ($h['house'] ?? 0);
            if ($num < 1) continue;
            $sign_num = $h['zodiac_sign']['number'] ?? 0;
            $sign_vi = $signs[$sign_num]['vi'] ?? '';
            $symbol = $signs[$sign_num]['symbol'] ?? '';
            $norm_deg = $h['normDegree'] ?? ($h['degree'] ?? 0);
            $dms = bccm_astro_decimal_to_dms($norm_deg);
            $meaning = $house_meanings[$num] ?? '';
            $angular = in_array($num, [1,4,7,10]);
          ?>
          <tr class="<?php echo $angular ? 'angular-house' : ''; ?>">
            <td class="house-num"><?php echo intval($num); ?></td>
            <td><span class="sign-symbol"><?php echo esc_html($symbol); ?></span> <?php echo esc_html($sign_vi); ?></td>
            <td class="mono"><?php echo esc_html($dms); ?></td>
            <td class="house-meaning"><?php echo esc_html($meaning); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php
    // ══════════════ ASPECTS TABLE (Góc Chiếu) ══════════════
    $aspects = $astro_traits['aspects'] ?? [];
    $positions = $astro_traits['positions'] ?? [];
    
    if (!empty($aspects) && !empty($positions)):
      $enriched = function_exists('bccm_astro_enrich_aspects') ? bccm_astro_enrich_aspects($aspects, $positions) : [];
      $grouped  = function_exists('bccm_astro_group_aspects_by_planet') ? bccm_astro_group_aspects_by_planet($enriched) : [];
      $planet_vi = function_exists('bccm_planet_names_vi') ? bccm_planet_names_vi() : [];
      $aspect_vi = function_exists('bccm_aspect_names_vi') ? bccm_aspect_names_vi() : [];
      $aspect_symbols = function_exists('bccm_aspect_symbols') ? bccm_aspect_symbols() : [];
      $aspect_colors = function_exists('bccm_aspect_colors') ? bccm_aspect_colors() : [];
    ?>
    <div class="chart-section">
      <h3 class="section-title">🔗 Góc Chiếu Giữa Các Hành Tinh</h3>
      <p class="section-subtitle">Tổng cộng <?php echo count($enriched); ?> góc chiếu</p>
      
      <!-- Aspect Legend -->
      <div class="aspect-legend">
        <?php foreach ($aspect_vi as $aen => $avi):
          $c = $aspect_colors[$aen] ?? '#888';
          $s = $aspect_symbols[$aen] ?? '';
        ?>
        <span class="legend-item"><span style="color:<?php echo $c; ?>;font-weight:700"><?php echo $s; ?></span> <?php echo esc_html($avi); ?></span>
        <?php endforeach; ?>
      </div>
      
      <table class="natal-table natal-table-aspects">
        <thead>
          <tr>
            <th style="width:22%">Hành tinh 1</th>
            <th style="width:8%"></th>
            <th style="width:22%">Góc chiếu</th>
            <th style="width:22%">Hành tinh 2</th>
            <th style="width:16%">Orb</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grouped as $planet_key => $planet_aspects):
            $pvi = $planet_vi[$planet_key] ?? $planet_key;
          ?>
          <tr class="aspect-group-header">
            <td colspan="5"><strong><?php echo esc_html($pvi); ?></strong> <span class="aspect-count">(<?php echo count($planet_aspects); ?> góc chiếu)</span></td>
          </tr>
          <?php foreach ($planet_aspects as $asp):
            $type_en = $asp['aspect_en'] ?? '';
            $type_vi = $aspect_vi[$type_en] ?? $type_en;
            $p2_vi = $planet_vi[$asp['planet_2_en'] ?? ''] ?? $asp['planet_2_en'];
            $sym = $aspect_symbols[$type_en] ?? '';
            $color = $aspect_colors[$type_en] ?? '#888';
            $orb_val = $asp['orb'] ?? null;
            $orb_display = $orb_val !== null ? bccm_astro_decimal_to_dms($orb_val, true) : '—';
            $orb_class = '';
            if ($orb_val !== null && $orb_val < 1) $orb_class = 'orb-exact';
            elseif ($orb_val !== null && $orb_val < 3) $orb_class = 'orb-tight';
          ?>
          <tr>
            <td class="planet-col"><?php echo esc_html($planet_vi[$asp['planet_1_en'] ?? ''] ?? $asp['planet_1_en']); ?></td>
            <td class="aspect-sym" style="color:<?php echo $color; ?>"><?php echo $sym; ?></td>
            <td style="color:<?php echo $color; ?>;font-weight:500"><?php echo esc_html($type_vi); ?></td>
            <td><?php echo esc_html($p2_vi); ?></td>
            <td class="mono <?php echo $orb_class; ?>"><?php echo esc_html($orb_display); ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
      
      <!-- Aspect Statistics -->
      <div class="aspect-stats">
        <?php
        $stats = [];
        foreach ($enriched as $asp) {
          $type = $asp['aspect_en'] ?? '';
          if (!isset($stats[$type])) $stats[$type] = 0;
          $stats[$type]++;
        }
        foreach ($stats as $type => $count):
          $c = $aspect_colors[$type] ?? '#888';
          $s = $aspect_symbols[$type] ?? '';
        ?>
        <span class="stat-badge">
          <span style="color:<?php echo $c; ?>;font-weight:700"><?php echo $s; ?></span>
          <?php echo esc_html($aspect_vi[$type] ?? $type); ?>
          <strong><?php echo $count; ?></strong>
        </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php
    // ══════════════ CHART PATTERNS (Mô Hình Bản Đồ) ══════════════
    if (!empty($aspects) && !empty($positions) && function_exists('bccm_detect_chart_patterns')):
      $chart_patterns = bccm_detect_chart_patterns($positions, $aspects);
      if (!empty($chart_patterns)):
    ?>
    <div class="chart-section">
      <h3 class="section-title">🔷 Mô Hình Bản Đồ</h3>
      <p class="section-subtitle">Các mô hình hình học đặc biệt giữa các hành tinh</p>
      <div class="patterns-grid">
        <?php foreach ($chart_patterns as $pattern): ?>
        <div class="pattern-card">
          <div class="pattern-header">
            <span class="pattern-icon"><?php echo esc_html($pattern['icon'] ?? '⭐'); ?></span>
            <span class="pattern-type"><?php echo esc_html($pattern['type_vi'] ?? $pattern['type'] ?? ''); ?></span>
          </div>
          <div class="pattern-planets">
            <?php foreach (($pattern['planets'] ?? []) as $pp): ?>
            <div class="pattern-planet"><?php echo esc_html($pp); ?></div>
            <?php endforeach; ?>
          </div>
          <div class="pattern-desc"><?php echo esc_html($pattern['description_vi'] ?? ''); ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php 
      endif;
    endif; 
    ?>

    <?php
    // ══════════════ SPECIAL FEATURES (Đặc Điểm Nổi Bật) ══════════════
    if (!empty($positions) && function_exists('bccm_analyze_special_features')):
      $special_features = bccm_analyze_special_features(
        $positions,
        $aspects ?? [],
        $houses_raw ?? [],
        $astro_traits ?? []
      );
      if (!empty($special_features)):
    ?>
    <div class="chart-section">
      <h3 class="section-title">✨ Đặc Điểm Nổi Bật</h3>
      <p class="section-subtitle">Phân tích tổng quan về các đặc điểm nổi bật trong bản đồ sao</p>
      <div class="special-grid">
        <?php foreach ($special_features as $sf): ?>
        <div class="special-card">
          <div class="special-icon"><?php echo esc_html($sf['icon'] ?? '✨'); ?></div>
          <div class="special-text">
            <p class="special-main"><?php echo esc_html($sf['text'] ?? ''); ?></p>
            <?php if (!empty($sf['text_vi']) && $sf['text_vi'] !== ($sf['text'] ?? '')): ?>
            <p class="special-sub"><?php echo esc_html($sf['text_vi']); ?></p>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php 
      endif;
    endif; 
    ?>

    <!-- ── GROWTH LOOP CTA ── -->
    <?php
    // Xác định URL tạo AI Agent (shortcode-site-add với ?astro=1)
    // Lấy từ network option nếu có, fallback sang trang /tao-ai-agent/
    $create_agent_url = get_option('bizcity_create_agent_url', '');
    if (empty($create_agent_url)) {
        // Thử tìm page có shortcode [shortcode_create_site]
        global $wpdb;
        $page_id = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%shortcode_create_site%' LIMIT 1");
        $create_agent_url = $page_id ? get_permalink($page_id) : home_url('/dung-thu-mien-phi/');
    }
    $create_agent_url_astro = add_query_arg('astro', '1', $create_agent_url);

    // Lấy Big 3 lại để hiện trong CTA
    $cta_sun  = $astro_summary['sun_sign']         ?? $vedic_summary['sun_sign']        ?? '';
    $cta_asc  = $astro_summary['ascendant_sign']   ?? $vedic_summary['ascendant_sign']  ?? '';
    ?>
    <div class="bccm-growth-cta" style="margin:40px 0 0;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 60%,#4c1d95 100%);border-radius:20px;padding:36px 32px;color:#fff;text-align:center">

      <div style="font-size:48px;margin-bottom:10px">🌟</div>
      <h2 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#fff">
        Bản đồ sao của <?php echo esc_html($name); ?> chỉ là bước khởi đầu
      </h2>
      <?php if ($cta_sun || $cta_asc): ?>
      <p style="margin:0 0 20px;font-size:15px;color:#c4b5fd">
        <?php if ($cta_sun): ?>☀️ <?php echo esc_html(ucfirst($cta_sun)); ?> <?php endif; ?>
        <?php if ($cta_asc): ?>• ⬆️ Cung mọc <?php echo esc_html(ucfirst($cta_asc)); ?><?php endif; ?>
        — Tìm hiểu sứ mệnh cuộc đời của bạn
      </p>
      <?php else: ?>
      <p style="margin:0 0 20px;font-size:15px;color:#c4b5fd">Khám phá sứ mệnh cuộc đời qua chiêm tinh học chuyên sâu</p>
      <?php endif; ?>

      <div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-bottom:28px">

        <!-- CTA 1: Tạo bản đồ sao bản thân (nếu chưa có) -->
        <a href="<?php echo esc_url($create_agent_url_astro); ?>" target="_blank"
           style="display:inline-flex;align-items:center;gap:8px;padding:14px 24px;border-radius:12px;background:#10b981;color:#fff;text-decoration:none;font-size:15px;font-weight:600;box-shadow:0 4px 16px rgba(16,185,129,0.4);transition:all 0.2s"
           onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
          🌙 Tạo Bản Đồ Sao Của Bạn
          <span style="font-size:12px;background:rgba(255,255,255,0.2);padding:2px 8px;border-radius:20px">Miễn phí</span>
        </a>

        <!-- CTA 2: Tạo AI Agent để xem luận giải -->
        <a href="<?php echo esc_url($create_agent_url); ?>" target="_blank"
           style="display:inline-flex;align-items:center;gap:8px;padding:14px 24px;border-radius:12px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;text-decoration:none;font-size:15px;font-weight:600;box-shadow:0 4px 16px rgba(99,102,241,0.45);transition:all 0.2s"
           onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
          🤖 Tạo AI Agent để luận giải
          <span style="font-size:12px;background:rgba(255,255,255,0.2);padding:2px 8px;border-radius:20px">Doraemon - Moltbot online</span>
        </a>

      </div>

      <!-- Feature grid -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;max-width:720px;margin:0 auto 24px">
        <div style="background:rgba(255,255,255,0.07);border-radius:12px;padding:16px 12px">
          <div style="font-size:28px;margin-bottom:6px">🔮</div>
          <div style="font-size:13px;font-weight:600;color:#e0e7ff">Luận Giải AI</div>
          <div style="font-size:12px;color:#a5b4fc;margin-top:4px">Western + Vedic chuyên sâu theo bản đồ sao cá nhân</div>
        </div>
        <div style="background:rgba(255,255,255,0.07);border-radius:12px;padding:16px 12px">
          <div style="font-size:28px;margin-bottom:6px">⚡</div>
          <div style="font-size:13px;font-weight:600;color:#e0e7ff">Dự Báo Vận Hạn</div>
          <div style="font-size:12px;color:#a5b4fc;margin-top:4px">Transit tuần / tháng / năm — ngày tốt xấu theo sao</div>
        </div>
        <div style="background:rgba(255,255,255,0.07);border-radius:12px;padding:16px 12px">
          <div style="font-size:28px;margin-bottom:6px">🃏</div>
          <div style="font-size:13px;font-weight:600;color:#e0e7ff">Bói Bài Tarot</div>
          <div style="font-size:12px;color:#a5b4fc;margin-top:4px">Kết hợp Tarot + Chiêm tinh học theo bản đồ sao</div>
        </div>
        <div style="background:rgba(255,255,255,0.07);border-radius:12px;padding:16px 12px">
          <div style="font-size:28px;margin-bottom:6px">💾</div>
          <div style="font-size:13px;font-weight:600;color:#e0e7ff">Trí Nhớ Cá Nhân</div>
          <div style="font-size:12px;color:#a5b4fc;margin-top:4px">AI ghi nhớ bạn, hỏi gì cũng hiểu ngay hoàn cảnh</div>
        </div>
      </div>

      <p style="margin:0;font-size:12px;color:#818cf8">💜 Powered by BizCoach Map × BizCity AI — Hoàn toàn miễn phí, thiết lập trong vài giây</p>
    </div>

    <!-- FOOTER -->
    <div class="natal-footer" style="margin-top:20px">
      <p>💜 Bản đồ sao được tạo bởi <strong>BizCoach Map</strong> - Powered by Freeze Astrology API</p>
    </div>

  </div>

  <?php
  if ($full_page) {
    wp_footer();
    echo '</body></html>';
  }
}

/* =====================================================================
 * EXPORT PDF (Using browser print or TCPDF if available)
 * =====================================================================*/
function bccm_natal_chart_export_pdf($coachee, $astro_western, $astro_vedic) {
  // Generate HTML with embedded CSS for PDF export
  header('Content-Type: text/html; charset=utf-8');
  
  // Read the CSS file and embed inline
  $css_path = BCCM_DIR . 'assets/css/natal-chart-public.css';
  $css_content = file_exists($css_path) ? file_get_contents($css_path) : '';
  
  echo '<!DOCTYPE html><html><head>';
  echo '<meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
  echo '<title>Bản Đồ Sao - ' . esc_html($coachee['full_name'] ?? 'Export') . '</title>';
  echo '<style>';
  // Base styles for PDF
  echo '
  body {
    margin: 0;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: linear-gradient(135deg, #0f0a1e 0%, #1e1b4b 30%, #312e81 60%, #0f0a1e 100%);
    color: #e2e8f0;
    min-height: 100vh;
    line-height: 1.6;
  }
  ';
  // Embed full CSS
  echo $css_content;
  // Print-specific overrides
  echo '
  @media print {
    body {
      background: #fff !important;
      color: #000 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .btn-export, .btn-print, .natal-actions { display: none !important; }
    .bccm-natal-public-wrap { max-width: 100%; }
    .bccm-natal-header,
    .chart-panel,
    .chart-section,
    .big3-card {
      background: #f8f9fa !important;
      border: 1px solid #ddd !important;
      page-break-inside: avoid;
    }
    .natal-table { border: 1px solid #ddd !important; background: #fff !important; }
    .natal-table th { background: #f0f0f0 !important; }
    .natal-table th, .natal-table td { border: 1px solid #ddd !important; color: #000 !important; }
    h1, h2, h3, .section-title { color: #1a1a2e !important; -webkit-text-fill-color: #1a1a2e !important; }
    .big3-card-inner { color: #000 !important; }
    .chart-panel { page-break-inside: avoid; }
    .patterns-grid, .special-grid { page-break-inside: avoid; }
  }
  @page { margin: 1cm; size: A4 portrait; }
  ';
  echo '</style>';
  echo '</head><body class="bccm-natal-chart-public">';
  bccm_natal_chart_render_html($coachee, $astro_western, $astro_vedic, false);
  echo '<script>window.onload = function() { window.print(); }</script>';
  echo '</body></html>';
}

/* =====================================================================
 * GENERATE HASH FOR COACHEE ID (Security)
 * =====================================================================*/
function bccm_generate_natal_chart_hash($coachee_id) {
  // Use WordPress salt for security
  $salt = defined('AUTH_KEY') ? AUTH_KEY : 'bccm_natal_chart';
  return substr(md5($coachee_id . $salt), 0, 16);
}

/**
 * Verify natal chart hash
 */
function bccm_verify_natal_chart_hash($coachee_id, $hash) {
  return $hash === bccm_generate_natal_chart_hash($coachee_id);
}

/* =====================================================================
 * HELPER: Get public URL for natal chart
 * =====================================================================*/
function bccm_get_natal_chart_public_url($coachee_id) {
  $hash = bccm_generate_natal_chart_hash($coachee_id);
  return home_url('/my-natal-chart/?id=' . $coachee_id . '&hash=' . $hash);
}
/**
 * Get natal chart URL by user_id (finds the coachee_id that has actual astro data)
 * Useful for admin pages where coachee_id may be from a different platform
 *
 * @param int $user_id WordPress user ID
 * @return string|false Public URL or false if no astro data found
 */
function bccm_get_natal_chart_url_by_user($user_id) {
  global $wpdb;
  $t_astro = $wpdb->prefix . 'bccm_astro';
  
  // Find coachee_id that has actual astro data (summary/traits not null)
  $coachee_id = $wpdb->get_var($wpdb->prepare(
    "SELECT coachee_id FROM $t_astro WHERE user_id = %d AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY id DESC LIMIT 1",
    $user_id
  ));
  
  if (!$coachee_id) {
    return false;
  }
  
  return bccm_get_natal_chart_public_url($coachee_id);
}