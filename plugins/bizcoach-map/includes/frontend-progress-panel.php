<?php
/**
 * BizCoach Map – Frontend Progress Panel (Nobi Panel)
 *
 * Hiển thị floating panel tiến độ xây dựng bản đồ cá nhân.
 * Hiện trên frontend cho user đã đăng nhập.
 *
 * 4 bước:
 *   Step 1: Tạo bản đồ sao (Hồ sơ & Chiêm tinh)
 *   Step 2: Đăng ký thành viên
 *   Step 3: Tạo AI Agent (Trợ lý)
 *   Step 4: Gán Character (đồng hành AI)
 *
 * @package BizCoach_Map
 * @since   0.1.0.21
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * 1. RENDER FUNCTION: Nobi Progress Panel
 * =====================================================================*/
function bccm_render_frontend_progress_panel() {
  if (!is_user_logged_in()) return;

  $user_id = get_current_user_id();
  $user = get_userdata($user_id);
  $progress = bccm_get_user_onboarding_progress($user_id);
  if (empty($progress)) return;

  $full_name = $user->display_name ?: $user->user_login;
  $percentage = $progress['percentage'];
  $is_complete = ($percentage >= 100);

  // Step definitions
  $steps = [
    1 => [
      'icon'   => '🌟',
      'label'  => 'Hồ sơ & Chiêm tinh',
      'tip'    => 'Tạo hồ sơ cá nhân + bản đồ chiêm tinh',
      'done'   => $progress['step1'],
      'url'    => function_exists('wc_get_page_permalink')
                    ? wc_get_page_permalink('myaccount') . 'life-map/'
                    : '#',
    ],
    2 => [
      'icon'   => '📋',
      'label'  => 'Đăng ký thành viên',
      'tip'    => 'Tạo tài khoản để lưu & truy cập bản đồ',
      'done'   => $progress['step2'],
      'url'    => '#',
    ],
    3 => [
      'icon'   => '🤖',
      'label'  => 'Tạo AI Agent',
      'tip'    => 'Tạo trợ lý AI đồng hành từ chiêm tinh',
      'done'   => $progress['step3'],
      'url'    => function_exists('wc_get_page_permalink')
                    ? wc_get_page_permalink('myaccount') . 'life-map/'
                    : '#',
    ],
    4 => [
      'icon'   => '🎯',
      'label'  => 'Gán Character',
      'tip'    => 'Gán trợ lý AI làm bạn đồng hành',
      'done'   => $progress['step4'],
      'url'    => function_exists('wc_get_page_permalink')
                    ? wc_get_page_permalink('myaccount') . 'life-map/'
                    : '#',
    ],
  ];

  // Nobi icon URL
  $nobi_icon = BCCM_URL . 'assets/icon/nobi.png';

  // Check if site is still being created
  $is_new = get_option('creating_new_site', false);
  ?>

  <!-- NOBI Float Button -->
  <button id="nobi-fe-float-btn" class="nobi-fe-float-btn" title="Tiến độ xây dựng bản đồ">
    <?php if (file_exists(BCCM_DIR . 'assets/icon/nobi.png')): ?>
      <img src="<?php echo esc_url($nobi_icon); ?>" alt="Nobi">
    <?php else: ?>
      <span style="font-size:24px">🌟</span>
    <?php endif; ?>
    <?php if (!$is_complete): ?>
      <span class="nobi-fe-badge"><?php echo $progress['completed']; ?></span>
    <?php endif; ?>
  </button>

  <!-- NOBI Panel -->
  <div id="nobi-fe-panel" class="nobi-fe-panel">
    <!-- Header -->
    <div class="nobi-fe-panel-header">
      <div class="nobi-fe-panel-header-left">
        <?php if (file_exists(BCCM_DIR . 'assets/icon/nobi.png')): ?>
          <img src="<?php echo esc_url($nobi_icon); ?>" alt="Nobi" class="nobi-fe-panel-avatar">
        <?php endif; ?>
        <div>
          <h3>Bản đồ cá nhân</h3>
          <span class="sub-text">
            <?php echo esc_html($full_name); ?> –
            <?php echo $is_complete ? '✅ Hoàn thành' : '⏳ Đang xây dựng'; ?>
          </span>
        </div>
      </div>
      <button id="nobi-fe-panel-close" class="nobi-fe-panel-close">×</button>
    </div>

    <!-- Body -->
    <div class="nobi-fe-panel-body">

      <!-- Progress Bar -->
      <div class="nobi-fe-progress">
        <div class="nobi-fe-progress-header">
          <span>Tiến trình xây dựng bản đồ</span>
          <span class="pct"><?php echo $percentage; ?>%</span>
        </div>
        <div class="nobi-fe-progress-track">
          <div class="nobi-fe-progress-fill" style="width:<?php echo $percentage; ?>%"></div>
        </div>
      </div>

      <?php if ($is_new && !$is_complete): ?>
      <!-- In-progress banner -->
      <div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:12px;padding:14px;margin-bottom:16px;text-align:center">
        <span style="font-size:20px">🔨</span>
        <p style="color:#f59e0b;font-size:13px;font-weight:600;margin:6px 0 0">
          AI Agent đang được xây dựng...<br>
          <small style="color:#94a3b8">Hệ thống đang tạo trợ lý AI cho bạn. Vui lòng chờ trong giây lát.</small>
        </p>
      </div>
      <?php endif; ?>

      <!-- Milestones Timeline -->
      <div class="nobi-fe-milestones">
        <h4>Milestones</h4>
        <div class="nobi-fe-timeline">
          <?php foreach ($steps as $num => $step):
            $is_done = $step['done'];
            $is_active_step = (!$is_done && $num === $progress['current_step']);
            $ms_class = $is_done ? 'done' : ($is_active_step ? 'active' : 'pending');
            $level_text = $is_done ? '100%' : ($is_active_step ? 'Đang thực hiện' : 'Chưa bắt đầu');
            $is_last = ($num === 4);
          ?>
          <div class="nobi-fe-ms <?php echo $ms_class; ?>">
            <div class="nobi-fe-ms-connector">
              <div class="nobi-fe-ms-dot">
                <?php if ($is_done): ?>
                  <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                    <path d="M2 6L5 9L10 3" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                  </svg>
                <?php elseif ($is_active_step): ?>
                  <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                    <circle cx="5" cy="5" r="3" fill="#fff"/>
                  </svg>
                <?php endif; ?>
              </div>
              <?php if (!$is_last): ?>
                <div class="nobi-fe-ms-line"></div>
              <?php endif; ?>
            </div>
            <div class="nobi-fe-ms-content">
              <a href="<?php echo esc_url($step['url']); ?>" class="nobi-fe-ms-link">
                <span class="nobi-fe-ms-icon"><?php echo $step['icon']; ?></span>
                <span class="nobi-fe-ms-label"><?php echo esc_html($step['label']); ?></span>
                <span class="nobi-fe-ms-level"><?php echo esc_html($level_text); ?></span>
              </a>
              <p class="nobi-fe-ms-tip"><?php echo esc_html($step['tip']); ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Status Cards -->
      <div class="nobi-fe-cards">
        <?php foreach ($steps as $num => $step):
          $card_class = $step['done'] ? 'done' : ($num === $progress['current_step'] ? 'active' : 'pending');
          $card_value = $step['done'] ? '✅' : ($num === $progress['current_step'] ? '⏳' : '⬜');
        ?>
        <div class="nobi-fe-card <?php echo $card_class; ?>">
          <div class="nobi-fe-card-icon"><?php echo $step['icon']; ?></div>
          <div>
            <span class="nobi-fe-card-value"><?php echo $card_value; ?></span>
            <span class="nobi-fe-card-label"><?php echo esc_html($step['label']); ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>

    <!-- Footer Quick Links -->
    <div class="nobi-fe-panel-footer">
      <?php
      $my_account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '#';
      $footer_links = [
        ['icon' => '🌟', 'label' => 'Hồ sơ', 'url' => $my_account_url . 'life-map/'],
        ['icon' => '📋', 'label' => 'Template', 'url' => $my_account_url . 'life-map/'],
        ['icon' => '🤖', 'label' => 'Character', 'url' => $my_account_url . 'life-map/'],
        ['icon' => '🎯', 'label' => 'Plan', 'url' => $my_account_url . 'life-map/'],
      ];
      foreach ($footer_links as $link):
      ?>
      <a href="<?php echo esc_url($link['url']); ?>" class="nobi-panel-footer-link">
        <span><?php echo $link['icon']; ?></span>
        <?php echo esc_html($link['label']); ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php
  // Show dialog notification if site is being created and step 4 not complete
  if ($is_new && !$progress['step4']):
  ?>
  <div class="astro-lp-dialog-overlay active" id="nobi-building-dialog">
    <div class="astro-lp-dialog">
      <span class="dialog-icon">🔨</span>
      <h3>AI Agent đang được xây dựng</h3>
      <p>
        Hệ thống đang tạo trợ lý AI cá nhân cho bạn dựa trên bản đồ chiêm tinh.
        Quá trình này sẽ hoàn thành trong vài phút.<br><br>
        <strong>Tiến độ hiện tại: <?php echo $percentage; ?>%</strong><br>
        <?php
        $pending_steps = [];
        foreach ($steps as $num => $step) {
          if (!$step['done']) $pending_steps[] = $step['label'];
        }
        if ($pending_steps) {
          echo 'Còn lại: ' . esc_html(implode(', ', $pending_steps));
        }
        ?>
      </p>
      <div class="astro-lp-dialog-actions">
        <button class="astro-lp-btn-primary" data-dialog-close>
          OK, Tôi hiểu
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php
}

/* =====================================================================
 * 2. ENQUEUE CSS/JS FOR PROGRESS PANEL (early hook)
 * =====================================================================*/
add_action('wp_enqueue_scripts', function () {
  // Only for frontend, logged-in users
  if (is_admin()) return;
  if (!is_user_logged_in()) return;

  // Don't enqueue on my-account pages
  if (function_exists('is_account_page') && is_account_page()) return;

  // Check if panel should be shown
  $progress = bccm_get_user_onboarding_progress(get_current_user_id());
  if (empty($progress)) return;

  // Enqueue if user has started onboarding
  if ($progress['step1'] && $progress['percentage'] < 100) {
    wp_enqueue_style('bccm-astro-landing', BCCM_URL . 'assets/css/astro-landing.css', [], BCCM_VERSION);
    wp_enqueue_script('bccm-astro-landing', BCCM_URL . 'assets/js/astro-landing.js', [], BCCM_VERSION, true);
  }
}, 20);

/* =====================================================================
 * 3. AUTO-RENDER PANEL ON FRONTEND PAGES
 * [2026-04-18] Disabled — nobi float btn causes clutter on all frontend pages.
 * Admin bar + bizchat float also hidden globally. Re-enable if needed.
 * =====================================================================*/
/*
add_action('wp_footer', function () {
  // Only show panel on frontend, not admin
  if (is_admin()) return;
  if (!is_user_logged_in()) return;

  // Don't show on my-account pages (has inline progress tracker)
  if (function_exists('is_account_page') && is_account_page()) return;

  // Don't show on astro landing page (it has its own progress section)
  global $post;
  if ($post && is_a($post, 'WP_Post')) {
    if (has_shortcode($post->post_content, 'bccm_astro_landing')) return;
    if (has_shortcode($post->post_content, 'bccm_astro_form')) return;
  }

  // Check if panel should be shown (user has started onboarding)
  $progress = bccm_get_user_onboarding_progress(get_current_user_id());
  if (empty($progress)) return;

  // Show panel if user has at least started step 1
  if ($progress['step1'] && $progress['percentage'] < 100) {
    bccm_render_frontend_progress_panel();
  }
}, 99);
*/ // END disabled nobi float auto-render

/* =====================================================================
 * 4. MY ACCOUNT: Progress Tracker Widget
 * =====================================================================*/
function bccm_render_myaccount_progress_tracker() {
  if (!is_user_logged_in()) return;

  $user_id = get_current_user_id();
  $user = get_userdata($user_id);
  $progress = bccm_get_user_onboarding_progress($user_id);
  if (empty($progress)) return;

  $full_name = $user->display_name ?: $user->user_login;
  $percentage = $progress['percentage'];

  // Determine coach type badge
  $coach_badge = '';
  if (function_exists('bccm_tables')) {
    global $wpdb;
    $t = bccm_tables();
    $coachee = $wpdb->get_row($wpdb->prepare(
      "SELECT coach_type FROM {$t['profiles']} WHERE user_id=%d ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
    if ($coachee) {
      $types = function_exists('bccm_coach_types') ? bccm_coach_types() : [];
      $coach_badge = $types[$coachee['coach_type']]['label'] ?? ucfirst($coachee['coach_type']);
    }
  }

  // Check linked character
  $linked_char = get_user_meta($user_id, 'bccm_linked_character_id', true);

  $steps = [
    1 => [
      'icon'  => '🌟',
      'name'  => 'Hồ sơ & Bản đồ sao',
      'desc'  => 'Tạo hồ sơ & xem chiêm tinh',
      'done'  => $progress['step1'],
    ],
    2 => [
      'icon'  => '🤖',
      'name'  => 'Tạo AI Agent',
      'desc'  => 'Tạo trợ lý AI đồng hành 24/7',
      'done'  => $progress['step3'],
      'url'   => 'https://bizcity.vn/dung-thu-mien-phi/',
    ],
  ];
  ?>
  <div class="bccm-fe-progress-wrap">
    <div class="bccm-fe-progress-card">

      <!-- Header -->
      <div class="bccm-fe-progress-header">
        <h3>
          👤 Tiến độ hồ sơ cá nhân
        </h3>
        <span class="bccm-fe-progress-pct"><?php echo $percentage; ?>%</span>
      </div>

      <!-- User info row -->
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
        <strong style="color:#1e293b"><?php echo esc_html($full_name); ?></strong>
        <?php if ($coach_badge): ?>
          <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#7c3aed;color:#fff">
            <?php echo esc_html($coach_badge); ?>
          </span>
        <?php endif; ?>
        <?php if ($linked_char): ?>
          <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;border:1px solid #7c3aed;color:#7c3aed">
            AI Agent #<?php echo intval($linked_char); ?>
          </span>
        <?php endif; ?>
      </div>

      <!-- Progress bar -->
      <div class="bccm-fe-progress-track">
        <div class="bccm-fe-progress-bar" style="width:<?php echo $percentage; ?>%"></div>
      </div>

      <!-- Steps Grid (2 cards) -->
      <div class="bccm-fe-steps-grid">
        <?php foreach ($steps as $num => $step):
          $is_done = $step['done'];
          $is_active = (!$is_done && $num === $progress['current_step']);
          $card_class = $is_done ? 'done' : ($is_active ? 'in-progress' : 'pending');
          $step_url = $step['url'] ?? '';
        ?>
        <?php if ($step_url && !$is_done): ?>
        <a href="<?php echo esc_url($step_url); ?>" class="bccm-fe-step-card <?php echo $card_class; ?>" style="text-decoration:none;cursor:pointer" target="_blank">
        <?php else: ?>
        <div class="bccm-fe-step-card <?php echo $card_class; ?>">
        <?php endif; ?>
          <div class="step-card-icon"><?php echo $step['icon']; ?></div>
          <div class="step-card-name">Bước <?php echo $num; ?>: <?php echo esc_html($step['name']); ?></div>
          <div class="step-card-desc"><?php echo esc_html($step['desc']); ?></div>
          <?php if ($is_done): ?>
            <div class="step-card-status done">✓ Hoàn thành</div>
          <?php elseif ($is_active): ?>
            <div class="step-card-status active">⏳ Đang thực hiện</div>
          <?php elseif ($step_url): ?>
            <div class="step-card-status" style="background:#f59e0b;color:#fff">👉 Tạo ngay</div>
          <?php else: ?>
            <div class="step-card-status pending">Chưa bắt đầu</div>
          <?php endif; ?>
        <?php if ($step_url && !$is_done): ?>
        </a>
        <?php else: ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
  <?php
}
