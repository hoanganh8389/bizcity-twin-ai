<?php
/**
 * BizCoach Map – Nobi Float Button & Admin Bar
 *
 * Floating button kiểu Nobita hiển thị tiến độ xây dựng bản đồ cá nhân.
 * Admin bar "Bản đồ cá nhân (Life Success)" cùng các sub-link.
 *
 * Giao diện đồng bộ với bc-agent-float-btn (bizcity-brain-level),
 * bizcity-knowledge, bizcity-bot-webchat.
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * HELPERS: Tính tiến độ bản đồ cá nhân
 * =====================================================================*/

/**
 * Lấy coachee mặc định (coachee gần nhất thuộc coach_type = mental_coach)
 * [2026-03-17] Cached per user — avoids SHOW COLUMNS + multiple SELECTs on every admin page.
 */
function bccm_nobi_get_default_coachee() {
  $uid = get_current_user_id();
  $cache_key = 'bccm_nobi_coachee_' . ( $uid ?: 'anon' );
  $cached = wp_cache_get( $cache_key, 'bccm_coachees' );
  if ( false !== $cached ) {
    return $cached ?: null; // stored empty array as "no result"
  }

  global $wpdb;
  $t = bccm_tables();

  // Đảm bảo migration đã chạy (tạo cột user_id, llm_report nếu thiếu)
  static $ensured = false;
  if (!$ensured) {
    $ensured = true;
    if (class_exists('BCCM_Installer')) {
      BCCM_Installer::instance()->maybe_upgrade();
    }
  }

  $row = null;

  // Ưu tiên 1: coachee của user hiện tại (ADMINCHAT trước, rồi WEBCHAT)
  if ($uid) {
    // Cache SHOW COLUMNS result per request to avoid repeated schema queries
    static $has_user_id_col = null;
    if ( null === $has_user_id_col ) {
      $cols = $wpdb->get_col("SHOW COLUMNS FROM {$t['profiles']}", 0);
      $has_user_id_col = in_array('user_id', $cols, true);
    }
    if ( $has_user_id_col ) {
      $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['profiles']} WHERE user_id=%d ORDER BY FIELD(platform_type,'ADMINCHAT','WEBCHAT') LIMIT 1",
        $uid
      ), ARRAY_A);
    }
  }

  // Ưu tiên 2: option đã lưu
  if ( ! $row ) {
    $saved_id = intval(get_option('bccm_nobi_default_coachee', 0));
    if ($saved_id) {
      $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $saved_id), ARRAY_A);
    }
  }

  // Fallback: coachee mental_coach gần nhất
  if ( ! $row ) {
    $row = $wpdb->get_row("SELECT * FROM {$t['profiles']} WHERE coach_type='mental_coach' ORDER BY id DESC LIMIT 1", ARRAY_A);
  }

  // Fallback cuối: bất kỳ coachee nào
  if ( ! $row ) {
    $row = $wpdb->get_row("SELECT * FROM {$t['profiles']} ORDER BY id DESC LIMIT 1", ARRAY_A);
  }

  wp_cache_set( $cache_key, $row ?: array(), 'bccm_coachees', 900 ); // 15 min
  return $row;
}

/**
 * Tính % tiến độ xây dựng bản đồ cá nhân (đơn giản hóa)
 * [2026-03-17] Result cached per request via static to avoid 3× duplicate computation.
 *
 * 2 bước workflow (mỗi bước 50%):
 *  1. Tạo & lưu hồ sơ (full_name + dob)
 *  2. Tạo bản đồ sao (astro chart western hoặc vedic)
 *
 * @return array{overall:int, steps:array, coachee:array|null}
 */
function bccm_nobi_get_progress() {
  static $cached_result = null;
  if ( null !== $cached_result ) {
    return $cached_result;
  }

  global $wpdb;
  $coachee = bccm_nobi_get_default_coachee();

  $has_profile = false;
  $has_astro   = false;

  if ($coachee) {
    $cid = (int) $coachee['id'];

    // Step 1: Hồ sơ đã lưu (full_name + dob)
    $has_profile = !empty($coachee['full_name']) && !empty($coachee['dob']);

    // Step 2: Bản đồ sao (western hoặc vedic chart tồn tại)
    $astro_tbl = $wpdb->prefix . 'bccm_astro';
    $astro_count = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $astro_tbl WHERE coachee_id = %d AND summary IS NOT NULL AND summary != ''",
      $cid
    ));
    $has_astro = $astro_count > 0;
  }

  // 2 steps: profile = 50%, astro = 50%
  $overall = ($has_profile ? 50 : 0) + ($has_astro ? 50 : 0);

  $steps = [
    [
      'id'     => 'step1_profile',
      'label'  => 'Lưu hồ sơ',
      'icon'   => '📝',
      'url'    => admin_url('admin.php?page=bccm_my_profile'),
      'tip'    => 'Điền thông tin cá nhân: Họ tên, Ngày sinh',
      'level'  => $has_profile ? 100 : 0,
      'status' => $has_profile ? 'done' : 'pending',
    ],
    [
      'id'     => 'step2_astro',
      'label'  => 'Tạo bản đồ sao',
      'icon'   => '🌟',
      'url'    => admin_url('admin.php?page=bccm_my_profile'),
      'tip'    => 'Nhấn "Tạo bản đồ sao" để xem luận giải chiêm tinh',
      'level'  => $has_astro ? 100 : 0,
      'status' => $has_astro ? 'done' : 'pending',
    ],
  ];

  $cached_result = [
    'overall'  => $overall,
    'steps'    => $steps,
    'coachee'  => $coachee,
  ];

  return $cached_result;
}

/* =====================================================================
 * ADMIN BAR: "Bản đồ cá nhân (Life Success)"
 * =====================================================================*/
add_action('admin_bar_menu', function ($wp_admin_bar) {
  if (!is_admin() || !current_user_can('edit_posts')) return;

  $progress = bccm_nobi_get_progress();
  $overall  = $progress['overall'];
  $steps    = $progress['steps'];
  $coachee  = $progress['coachee'];

  // Color based on progress
  $bar_color = '#ef4444'; // red
  if ($overall >= 75)      $bar_color = '#10b981'; // green
  elseif ($overall >= 40)  $bar_color = '#f59e0b'; // amber
  elseif ($overall >= 15)  $bar_color = '#3b82f6'; // blue

  $nobi_icon = esc_url(BCCM_URL . 'assets/icon/nobi.png');

  // Main node
  $wp_admin_bar->add_node([
    'id'    => 'bccm-lifemap',
    'title' => sprintf(
      '<span class="bc-nobi-bar">
        <img src="%s" alt="Nobi" style="width: 16px; height: 16px;" class="bc-nobi-bar-avatar">
        Bản đồ cá nhân
        <span class="bc-nobi-progress-wrap">
          <span class="bc-nobi-progress-bar" style="width:%d%%;background:%s"></span>
        </span>
        <span class="bc-nobi-pct">%d%%</span>
      </span>',
      $nobi_icon,
      $overall,
      $bar_color,
      $overall
    ),
    'href'  => admin_url('admin.php?page=bccm_lifemap_plan'),
    'meta'  => [
      'title' => 'Bản đồ cá nhân – ' . $overall . '% hoàn thành',
    ],
  ]);

  // Step sub-nodes
  foreach ($steps as $step) {
    $status_icon = '⬜';
    if ($step['status'] === 'done')        $status_icon = '✅';
    elseif ($step['status'] === 'in-progress') $status_icon = '🔄';

    $wp_admin_bar->add_node([
      'id'     => 'bccm-lifemap-' . $step['id'],
      'parent' => 'bccm-lifemap',
      'title'  => sprintf(
        '<span class="bc-step-item bc-step-%s">
          <span class="bc-step-icon">%s</span>
          <span class="bc-step-label">%s %s</span>
          <span class="bc-step-level">%d%%</span>
        </span>',
        esc_attr($step['status']),
        $step['icon'],
        $status_icon,
        esc_html($step['label']),
        $step['level']
      ),
      'href'   => $step['url'],
      'meta'   => ['title' => $step['tip']],
    ]);
  }

  // Separator
  $wp_admin_bar->add_node([
    'id'     => 'bccm-lifemap-sep',
    'parent' => 'bccm-lifemap',
    'title'  => '<hr style="margin:4px 0;border:0;border-top:1px solid rgba(255,255,255,0.15)">',
  ]);

  // Quick links
  $coachee_param = $coachee ? '&coachee_id=' . $coachee['id'] : '';

  $wp_admin_bar->add_node([
    'id'     => 'bccm-lifemap-plan',
    'parent' => 'bccm-lifemap',
    'title'  => '🗺️ Life Map Plan',
    'href'   => admin_url('admin.php?page=bccm_lifemap_plan' . $coachee_param),
  ]);
  $wp_admin_bar->add_node([
    'id'     => 'bccm-lifemap-cron',
    'parent' => 'bccm-lifemap',
    'title'  => '⏰ Nhắc nhở & AI',
    'href'   => admin_url('admin.php?page=bccm_cron_reminders'),
  ]);
  $wp_admin_bar->add_node([
    'id'     => 'bccm-lifemap-coachees',
    'parent' => 'bccm-lifemap',
    'title'  => '👥 Danh sách Coachees',
    'href'   => admin_url('admin.php?page=bccm_coachees_list'),
  ]);
}, 101); // Priority 101 → right after AI Agent (100)

/* =====================================================================
 * ADMIN BAR INLINE STYLES (đồng bộ với bizcity-brain-level)
 * =====================================================================*/
add_action('admin_head', function () {
  if (!is_admin_bar_showing()) return;
  ?>
  <style>
    /* === Admin Bar Nobi – Life Map Progress (đồng bộ bc-agent-bar) === */
    #wp-admin-bar-bccm-lifemap { line-height: 1 !important; }
    #wp-admin-bar-bccm-lifemap > .ab-item {
      height: 32px !important;
      line-height: 32px !important;
      padding: 0 10px !important;
      display: flex !important;
      align-items: center !important;
    }
    .bc-nobi-bar {
      display: inline-flex !important;
      align-items: center !important;
      gap: 8px;
      height: 32px;
      line-height: 1;
      vertical-align: middle;
      white-space: nowrap;
    }
    .bc-nobi-bar * { box-sizing: border-box; }
    .bc-nobi-bar-avatar {
      width: 22px; height: 22px;
      border-radius: 50%;
      border: 1.5px solid rgba(255,255,255,0.35);
      object-fit: cover;
      display: block;
      flex-shrink: 0;
    }
    .bc-nobi-progress-wrap {
      width: 70px; height: 6px;
      background: rgba(255,255,255,0.15);
      border-radius: 3px;
      overflow: hidden;
      position: relative;
      flex-shrink: 0;
    }
    .bc-nobi-progress-bar {
      height: 100%;
      border-radius: 3px;
      transition: width 0.6s ease;
      position: relative;
    }
    .bc-nobi-progress-bar::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
    }
    .bc-nobi-pct {
      font-size: 11px;
      font-weight: 600;
      color: #fff;
      min-width: 26px;
      text-align: right;
      flex-shrink: 0;
    }

    /* ---- Dropdown: reuse bc-step-item from bizcity-brain-level ---- */
    #wp-admin-bar-bccm-lifemap .ab-sub-wrapper {
      min-width: 280px;
    }
  </style>
  <?php
});

/* =====================================================================
 * FLOATING BUTTON + PANEL
 * [2026-03-17] Disabled — admin bar is sufficient, float btn causes extra
 * queries + asset loads on every admin page. Re-enable if needed later.
 * =====================================================================*/
/*
add_action('admin_enqueue_scripts', function () {
  if (!is_admin() || !current_user_can('edit_posts')) return;

  wp_enqueue_style(
    'bccm-nobi-float',
    BCCM_URL . 'assets/css/nobi-float.css',
    [],
    BCCM_VERSION
  );
  wp_enqueue_script(
    'bccm-nobi-float',
    BCCM_URL . 'assets/js/nobi-float.js',
    ['jquery'],
    BCCM_VERSION,
    true
  );
  wp_localize_script('bccm-nobi-float', 'bcNobiFloat', [
    'ajaxurl'   => admin_url('admin-ajax.php'),
    'nonce'     => wp_create_nonce('bccm_nobi_nonce'),
    'icon_url'  => BCCM_URL . 'assets/icon/nobi.png',
    'progress'  => bccm_nobi_get_progress(),
    'site_name' => get_bloginfo('name'),
  ]);
});
*/ // END disabled float enqueue

/* [2026-03-17] Float button + panel HTML disabled — admin bar only
add_action('admin_footer', function () {
  if (!is_admin() || !current_user_can('edit_posts')) return;

  $progress = bccm_nobi_get_progress();
  $overall  = $progress['overall'];
  $steps    = $progress['steps'];
  $coachee  = $progress['coachee'];
  $coachee_name = $coachee['full_name'] ?? 'Chưa chọn';

  $nobi_icon = esc_url(BCCM_URL . 'assets/icon/nobi.png');

  // Find next incomplete step
  $next_step = null;
  foreach ($steps as $step) {
    if ($step['status'] !== 'done') {
      $next_step = $step;
      break;
    }
  }
  ?>

  <!-- Nobi Float Button -->
  <button id="btn-nobi-float-btn" class="btn-nobi-float-btn" title="Bản đồ cá nhân – <?php echo esc_attr($overall); ?>%">
    <img src="<?php echo $nobi_icon; ?>" alt="Nobi" class="btn-nobi-float-avatar">
    <?php if ($overall < 100): ?>
    <span class="btn-nobi-float-badge"><?php echo esc_html($overall); ?>%</span>
    <?php endif; ?>
  </button>

  <!-- Nobi Panel -->
  <div id="nobi-panel" class="nobi-panel">
    <!-- Header -->
    <div class="nobi-panel-header">
      <div class="nobi-panel-header-left">
        <img src="<?php echo $nobi_icon; ?>" alt="Nobi" class="nobi-panel-avatar">
        <div class="nobi-panel-header-info">
          <h3>Bản đồ cá nhân</h3>
          <span class="nobi-panel-header-sub">
            <?php echo esc_html($coachee_name); ?>
            –
            <?php if ($overall >= 100): ?>
              ✅ Hoàn thành
            <?php elseif ($overall >= 50): ?>
              🔄 Đang xây dựng...
            <?php else: ?>
              ⚠️ Cần bổ sung thêm
            <?php endif; ?>
          </span>
        </div>
      </div>
      <button id="nobi-panel-close" class="nobi-panel-close">&times;</button>
    </div>

    <!-- Progress -->
    <div class="nobi-panel-progress">
      <div class="nobi-panel-progress-header">
        <span>Tiến trình xây dựng bản đồ</span>
        <span class="nobi-panel-progress-pct"><?php echo esc_html($overall); ?>%</span>
      </div>
      <div class="nobi-panel-progress-track">
        <div class="nobi-panel-progress-fill" style="width:<?php echo esc_attr($overall); ?>%"></div>
      </div>
    </div>

    <!-- Next action banner -->
    <?php if ($next_step): ?>
    <a href="<?php echo esc_url($next_step['url']); ?>" class="nobi-panel-next-action">
      <div class="nobi-panel-next-icon"><?php echo $next_step['icon']; ?></div>
      <div class="nobi-panel-next-info">
        <strong>Bước tiếp theo</strong>
        <span><?php echo esc_html($next_step['label']); ?></span>
      </div>
      <span class="nobi-panel-next-arrow">→</span>
    </a>
    <?php endif; ?>

    <!-- Milestones Timeline -->
    <div class="nobi-panel-milestones">
      <h4>Milestones</h4>
      <div class="nobi-panel-timeline">
        <?php foreach ($steps as $i => $step):
          $status_class = 'nobi-ms-' . $step['status'];
          $is_last = ($i === count($steps) - 1);
        ?>
        <div class="nobi-panel-milestone <?php echo esc_attr($status_class); ?>">
          <div class="nobi-ms-connector">
            <div class="nobi-ms-dot">
              <?php if ($step['status'] === 'done'): ?>
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6L5 9L10 3" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>
              <?php elseif ($step['status'] === 'in-progress'): ?>
                <div class="nobi-ms-pulse"></div>
              <?php endif; ?>
            </div>
            <?php if (!$is_last): ?>
            <div class="nobi-ms-line"></div>
            <?php endif; ?>
          </div>
          <div class="nobi-ms-content">
            <a href="<?php echo esc_url($step['url']); ?>" class="nobi-ms-link">
              <span class="nobi-ms-icon"><?php echo $step['icon']; ?></span>
              <span class="nobi-ms-label"><?php echo esc_html($step['label']); ?></span>
              <span class="nobi-ms-level"><?php echo $step['level']; ?>%</span>
            </a>
            <?php if ($step['id'] === 'step1_profile_astro' && !empty($step['sub'])): ?>
            <div class="nobi-ms-sub-details" style="margin:4px 0 0 2px;font-size:11px;line-height:1.5">
              <?php
              $sub = $step['sub'];
              $sub_checks = [
                ['Hồ sơ',           $sub['has_profile']],
                ['Western Chart',   $sub['has_western']],
                ['Vedic Chart',     $sub['has_vedic']],
                ['AI Report (W)',   $sub['has_ai_western']],
                ['AI Report (V)',   $sub['has_ai_vedic']],
              ];
              foreach ($sub_checks as $sc_item):
              ?>
              <span style="display:inline-block;margin-right:6px;opacity:0.85"><?php echo $sc_item[1] ? '✅' : '⬜'; ?> <?php echo esc_html($sc_item[0]); ?></span>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="nobi-ms-tip"><?php echo esc_html($step['tip']); ?></p>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Status Cards (2 Steps) -->
    <div class="nobi-panel-cards">
      <?php foreach ($steps as $i => $sc):
        $card_classes = ['nobi-card'];
        if ($sc['status'] === 'done') $card_classes[] = 'nobi-card-done';
      ?>
      <div class="<?php echo implode(' ', $card_classes); ?>">
        <div class="nobi-card-icon"><?php echo $sc['icon']; ?></div>
        <div class="nobi-card-info">
          <span class="nobi-card-value"><?php echo $sc['status'] === 'done' ? '✅' : '⬜'; ?></span>
          <span class="nobi-card-label"><?php echo esc_html($sc['label']); ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Footer Quick Links -->
    <div class="nobi-panel-footer">
      <a href="<?php echo admin_url('admin.php?page=bccm_my_profile'); ?>" class="nobi-panel-footer-link">
        <span>📝</span> Hồ sơ
      </a>
      <a href="<?php echo admin_url('admin.php?page=bccm_lifemap_plan'); ?>" class="nobi-panel-footer-link">
        <span>🗺️</span> Bản đồ
      </a>
    </div>
  </div>
  <?php
});
*/ // END disabled float button + panel

/* =====================================================================
 * AJAX: Refresh progress
 * =====================================================================*/
add_action('wp_ajax_bccm_nobi_progress', function () {
  check_ajax_referer('bccm_nobi_nonce', 'nonce');
  wp_send_json_success(bccm_nobi_get_progress());
});
