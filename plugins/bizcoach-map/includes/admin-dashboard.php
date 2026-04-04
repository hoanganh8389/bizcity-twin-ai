<?php
/**
 * BizCoach Map – Admin Dashboard
 *
 * Trang tổng quan (Dashboard) hiển thị:
 *  - Workflow Steps (quy trình hoàn thiện hồ sơ)
 *  - Stat Cards (thống kê nhanh)
 *  - Tiến độ hồ sơ cá nhân
 *  - Bản đồ & Plan đồng hành
 *  - Danh sách Cron & Reminders
 *  - Reminder Logs gần nhất
 *
 * Design đồng bộ với bizcity-video-kling & bizcity-knowledge.
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * WORKFLOW STEPS COMPONENT (reusable)
 * =====================================================================*/

/**
 * Render workflow steps navigation bar
 *
 * @param string $current_step  Active step key
 */
function bccm_render_workflow_steps( $current_step = '' ) {
  // Auto-detect current step from page param
  if (empty($current_step) && isset($_GET['page'])) {
    $page_map = [
      'bccm_my_profile'          => 'step1',
      'bccm_step2_coach_template'=> 'step2',
      'bccm_step3_character'     => 'step3',
      'bccm_step4_success_plan'  => 'step4',
      'bccm_lifemap_plan'        => 'lifemap',
      'bccm_cron_reminders'      => 'reminders',
      'bccm_dashboard'           => 'dashboard',
      'bccm_root'                => 'dashboard',
      'bccm_coachees'            => 'coachees',
      'bccm_coachees_list'       => 'coachees',
      'bccm_templates'           => 'templates',
      'bccm_plan_templates'      => 'templates',
      'bccm_settings'            => 'settings',
    ];
    $current_step = $page_map[$_GET['page']] ?? '';
  }

  $basic_steps = [
    'step1'   => [
      'icon'  => '🌟',
      'label' => 'Bước 1: Hồ sơ & Chiêm tinh',
      'url'   => admin_url('admin.php?page=bccm_my_profile'),
    ],
    'step2'   => [
      'icon'  => '📋',
      'label' => 'Bước 2: Coach Template',
      'url'   => admin_url('admin.php?page=bccm_step2_coach_template'),
    ],
    'step3'   => [
      'icon'  => '🤖',
      'label' => 'Bước 3: Tạo Character',
      'url'   => admin_url('admin.php?page=bccm_step3_character'),
    ],
  ];

  $advanced_steps = [
    'step4'   => [
      'icon'  => '🎯',
      'label' => 'Bước 4: Success Plan',
      'url'   => admin_url('admin.php?page=bccm_step4_success_plan'),
    ],
    'lifemap' => [
      'icon'  => '🗺️',
      'label' => 'Bước 5: Life Map Plan',
      'url'   => admin_url('admin.php?page=bccm_lifemap_plan'),
    ],
    'reminders' => [
      'icon'  => '⏰',
      'label' => 'Bước 6: Nhắc nhở & AI',
      'url'   => admin_url('admin.php?page=bccm_cron_reminders'),
    ],
  ];
  ?>
  <div class="bccm-workflow-steps">
    <h3>🎯 Quy trình xây dựng Bản đồ cuộc đời</h3>
    <div class="bccm-workflow-group-label">⭐ Cơ bản</div>
    <div class="bccm-workflow-steps-container">
      <?php foreach ( $basic_steps as $key => $step ):
        $is_active = ( $key === $current_step );
      ?>
      <a href="<?php echo esc_url( $step['url'] ); ?>"
         class="bccm-workflow-step <?php echo $is_active ? 'active' : ''; ?>">
        <span class="bccm-step-icon"><?php echo $step['icon']; ?></span>
        <span class="bccm-step-label"><?php echo esc_html( $step['label'] ); ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="bccm-workflow-group-label bccm-advanced-label">🚀 Nâng cao</div>
    <div class="bccm-workflow-steps-container">
      <?php foreach ( $advanced_steps as $key => $step ):
        $is_active = ( $key === $current_step );
      ?>
      <a href="<?php echo esc_url( $step['url'] ); ?>"
         class="bccm-workflow-step bccm-workflow-step-advanced <?php echo $is_active ? 'active' : ''; ?>">
        <span class="bccm-step-icon"><?php echo $step['icon']; ?></span>
        <span class="bccm-step-label"><?php echo esc_html( $step['label'] ); ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
}


/* =====================================================================
 * ADMIN MENU: Dashboard (parent default page)
 * =====================================================================*/
/* Dashboard is now registered as parent callback in admin-pages.php bccm_admin_menu().
 * The submenu reorder is handled in bccm_reorder_admin_submenu() below.
 */

// Primary reorder: runs after all add_submenu_page calls
add_action('admin_menu', 'bccm_reorder_admin_submenu', 9999);

// Backup reorder: _admin_menu fires after admin_menu + WP internal ksort
add_action('_admin_menu', 'bccm_reorder_admin_submenu', 9999);

/**
 * Reorder admin submenu to follow workflow steps order.
 *
 * Gọi 2 lần (admin_menu + _admin_menu) để đảm bảo
 * WordPress ksort() không xáo trộn lại thứ tự.
 */
function bccm_reorder_admin_submenu() {
  global $submenu;
  if (!isset($submenu['bccm_root']) || empty($submenu['bccm_root'])) return;

  // Desired order by page slug
  $order = [
    'bccm_root',                // Dashboard (parent default)
    'bccm_my_profile',          // Bước 1: Hồ sơ & Chiêm tinh
    'bccm_step2_coach_template',// Bước 2: Coach Template
    'bccm_step3_character',     // Bước 3: Tạo Character
    'bccm_step4_success_plan',  // Bước 4: Success Plan
    'bccm_lifemap_plan',        // Life Map Plan
    'bccm_cron_reminders',      // Cron & Reminders
    'bccm_coachees',            // Bản đồ người khác
    'bccm_coachees_list',       // └ Danh sách
    'bccm_settings',            // Cài đặt (tabbed)
  ];

  $sorted   = [];
  $leftover = [];

  // Build lookup: slug → submenu item
  $lookup = [];
  foreach ($submenu['bccm_root'] as $item) {
    $slug = $item[2] ?? '';
    $lookup[$slug] = $item;
  }

  // Add items in desired order
  foreach ($order as $slug) {
    if (isset($lookup[$slug])) {
      $sorted[] = $lookup[$slug];
      unset($lookup[$slug]);
    }
  }

  // Add remaining items
  foreach ($lookup as $item) {
    $sorted[] = $item;
  }

  $submenu['bccm_root'] = array_values($sorted);
}


/* =====================================================================
 * DASHBOARD PAGE RENDER
 * =====================================================================*/
function bccm_admin_dashboard() {
  if (!current_user_can('edit_posts')) return;

  global $wpdb;
  $t = bccm_tables();

  // ---- STATS ----
  $total_coachees = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['profiles']}");
  $total_plans    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['plans']}");
  $total_logs     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['logs']}");

  // Active reminders
  $select_fields = bccm_profiles_support_platform_type()
    ? 'id, full_name, coach_type, user_id, platform_type'
    : 'id, full_name, coach_type, user_id';
  $all_coachees = $wpdb->get_results("SELECT {$select_fields} FROM {$t['profiles']} ORDER BY id DESC", ARRAY_A);
  $active_reminders = 0;
  foreach ($all_coachees as $c) {
    $cfg = get_option("bccm_reminders_config_{$c['id']}", []);
    if (!empty($cfg['enabled'])) $active_reminders++;
  }

  // Reminder logs
  $logs_tbl    = $wpdb->prefix . 'bccm_reminder_logs';
  $has_logs    = ($wpdb->get_var("SHOW TABLES LIKE '$logs_tbl'") === $logs_tbl);
  $total_sent  = 0;
  $recent_logs = [];
  if ($has_logs) {
    $total_sent  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $logs_tbl WHERE status='sent'");
    $recent_logs = $wpdb->get_results("SELECT * FROM $logs_tbl ORDER BY created_at DESC LIMIT 8", ARRAY_A);
  }

  // Current user progress (ADMINCHAT profile)
  $progress = function_exists('bccm_nobi_get_progress') ? bccm_nobi_get_progress() : null;
  $my_coachee = $progress['coachee'] ?? null;

  ?>
  <div class="wrap bccm-wrap">
    <h1>📊 Bản đồ chủ nhân – Dashboard</h1>
    <p class="description">Tổng quan hệ thống coaching & bản đồ cuộc đời</p>

    <?php bccm_render_workflow_steps(''); ?>

    <!-- ======================== STAT CARDS ======================== -->
    <div class="bccm-stats-grid">
      <div class="bccm-stat-card">
        <div class="bccm-stat-icon"><span>👥</span></div>
        <div class="bccm-stat-content">
          <span class="bccm-stat-number"><?php echo $total_coachees; ?></span>
          <span class="bccm-stat-label">Coachees</span>
        </div>
      </div>
      <div class="bccm-stat-card bccm-stat-processing">
        <div class="bccm-stat-icon"><span>📋</span></div>
        <div class="bccm-stat-content">
          <span class="bccm-stat-number"><?php echo $total_plans; ?></span>
          <span class="bccm-stat-label">Action Plans</span>
        </div>
      </div>
      <div class="bccm-stat-card bccm-stat-completed">
        <div class="bccm-stat-icon"><span>⏰</span></div>
        <div class="bccm-stat-content">
          <span class="bccm-stat-number"><?php echo $active_reminders; ?></span>
          <span class="bccm-stat-label">Active Reminders</span>
        </div>
      </div>
      <div class="bccm-stat-card bccm-stat-sent">
        <div class="bccm-stat-icon"><span>✅</span></div>
        <div class="bccm-stat-content">
          <span class="bccm-stat-number"><?php echo $total_sent; ?></span>
          <span class="bccm-stat-label">Reminders Sent</span>
        </div>
      </div>
    </div>

    <!-- ======================== TWO COLUMNS ======================== -->
    <div class="bccm-dashboard-columns">

      <!-- LEFT: Personal Progress -->
      <div class="bccm-dashboard-col">

        <?php if ($progress && $my_coachee): ?>
        <!-- My Profile Progress -->
        <div class="bccm-card">
          <h2>👤 Tiến độ hồ sơ cá nhân</h2>
          <div class="bccm-profile-summary">
            <div class="bccm-profile-info">
              <strong><?php echo esc_html($my_coachee['full_name'] ?? '—'); ?></strong>
              <span class="bccm-badge"><?php echo esc_html(ucfirst(str_replace('_', ' ', $my_coachee['coach_type'] ?? ''))); ?></span>
              <?php if (!empty($my_coachee['platform_type'])): ?>
                <span class="bccm-badge bccm-badge-outline"><?php echo esc_html($my_coachee['platform_type']); ?></span>
              <?php endif; ?>
            </div>
            <div class="bccm-progress-bar-wrap">
              <div class="bccm-progress-bar">
                <div class="bccm-progress-fill" style="width:<?php echo $progress['overall']; ?>%"></div>
              </div>
              <span class="bccm-progress-text"><?php echo $progress['overall']; ?>%</span>
            </div>
          </div>

          <!-- Steps checklist -->
          <div class="bccm-steps-checklist">
            <?php foreach ($progress['steps'] as $step): ?>
            <a href="<?php echo esc_url($step['url']); ?>" class="bccm-step-item <?php echo $step['status'] === 'done' ? 'done' : 'pending'; ?>">
              <span class="bccm-step-check"><?php echo $step['status'] === 'done' ? '✅' : '⬜'; ?></span>
              <span class="bccm-step-emoji"><?php echo $step['icon']; ?></span>
              <span class="bccm-step-name"><?php echo esc_html($step['label']); ?></span>
              <?php if ($step['status'] !== 'done'): ?>
                <span class="bccm-step-action">Bắt đầu →</span>
              <?php endif; ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="bccm-card">
          <h2>⚡ Quick Actions</h2>
          <div class="bccm-quick-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=bccm_my_profile')); ?>" class="button button-primary button-hero">
              🌟 Bước 1: Hồ sơ & Chiêm tinh
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=bccm_step2_coach_template')); ?>" class="button button-hero">
              📋 Bước 2: Coach Template
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=bccm_step3_character')); ?>" class="button button-hero">
              🤖 Bước 3: Tạo Character
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=bccm_step4_success_plan')); ?>" class="button button-hero">
              🎯 Bước 4: Success Plan
            </a>
          </div>
        </div>
      </div>

      <!-- RIGHT: Recent Activity -->
      <div class="bccm-dashboard-col">

        <!-- Life Map Overview -->
        <?php if ($my_coachee): 
          $life_map = json_decode($my_coachee['mental_json'] ?? '[]', true) ?: [];
        ?>
        <div class="bccm-card">
          <h2>🗺️ Life Map – Bản đồ cuộc đời</h2>
          <?php if (!empty($life_map['identity'])): ?>
            <div class="bccm-lifemap-snapshot">
              <?php if (!empty($life_map['identity'])): ?>
              <div class="bccm-lm-row">
                <span class="bccm-lm-label">🧠 Tôi là ai</span>
                <span class="bccm-lm-value"><?php echo esc_html(wp_trim_words($life_map['identity'], 15, '…')); ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($life_map['strengths'])): ?>
              <div class="bccm-lm-row">
                <span class="bccm-lm-label">💪 Thế mạnh</span>
                <span class="bccm-lm-value"><?php echo esc_html(wp_trim_words(is_array($life_map['strengths']) ? implode(', ', $life_map['strengths']) : $life_map['strengths'], 12, '…')); ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($life_map['vision_3y'])): ?>
              <div class="bccm-lm-row">
                <span class="bccm-lm-label">🔭 Tầm nhìn 3 năm</span>
                <span class="bccm-lm-value"><?php echo esc_html(wp_trim_words($life_map['vision_3y'], 15, '…')); ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($life_map['core_values'])): ?>
              <div class="bccm-lm-row">
                <span class="bccm-lm-label">💎 Giá trị cốt lõi</span>
                <span class="bccm-lm-value"><?php echo esc_html(wp_trim_words(is_array($life_map['core_values']) ? implode(', ', $life_map['core_values']) : $life_map['core_values'], 12, '…')); ?></span>
              </div>
              <?php endif; ?>
            </div>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=bccm_lifemap_plan&coachee_id=' . $my_coachee['id'])); ?>" class="bccm-link-arrow">Xem chi tiết Life Map →</a></p>
          <?php else: ?>
            <div class="bccm-empty-state">
              <span class="bccm-empty-icon">🗺️</span>
              <p>Bạn chưa tạo Life Map.</p>
              <a href="<?php echo esc_url(admin_url('admin.php?page=bccm_coachees&edit=' . $my_coachee['id'])); ?>" class="button button-primary">Generate Life Map ngay</a>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Active Reminders -->
        <div class="bccm-card">
          <h2>⏰ Active Reminders</h2>
          <?php
          $has_any_reminder = false;
          foreach ($all_coachees as $c):
            $cfg = get_option("bccm_reminders_config_{$c['id']}", []);
            if (empty($cfg['enabled'])) continue;
            $has_any_reminder = true;

            $daily_next  = wp_next_scheduled('bccm_daily_reminder', [$c['id']]);
            $weekly_next = wp_next_scheduled('bccm_weekly_reminder', [$c['id']]);
            $current_wk  = intval($cfg['current_week'] ?? 1);
          ?>
          <div class="bccm-reminder-row">
            <div class="bccm-reminder-who">
              <strong><?php echo esc_html($c['full_name']); ?></strong>
              <span class="bccm-badge bccm-badge-sm bccm-badge-success">Active</span>
            </div>
            <div class="bccm-reminder-meta">
              <span>📅 Daily: <?php echo esc_html($cfg['daily_time'] ?? '—'); ?>
                <?php if ($daily_next): ?>
                  <small>(Next: <?php echo date_i18n('d/m H:i', $daily_next); ?>)</small>
                <?php endif; ?>
              </span>
              <span>📆 Tuần <?php echo $current_wk; ?>/12</span>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!$has_any_reminder): ?>
            <div class="bccm-empty-state bccm-empty-sm">
              <p>Chưa có lịch nhắc nhở.</p>
              <a href="<?php echo esc_url(admin_url('admin.php?page=bccm_lifemap_plan')); ?>" class="button">Setup ngay</a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ======================== REMINDER LOGS ======================== -->
    <div class="bccm-card">
      <h2>📝 Reminder Logs gần nhất</h2>
      <?php if (!empty($recent_logs)): ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th>Thời gian</th>
              <th>Coachee</th>
              <th>Type</th>
              <th>Channel</th>
              <th>Message</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_logs as $log):
              $name = $wpdb->get_var($wpdb->prepare("SELECT full_name FROM {$t['profiles']} WHERE id=%d", $log['coachee_id'])) ?: '#' . $log['coachee_id'];
            ?>
            <tr>
              <td><?php echo esc_html($log['created_at'] ?? $log['sent_at'] ?? ''); ?></td>
              <td><?php echo esc_html($name); ?></td>
              <td><span class="bccm-badge bccm-badge-sm"><?php echo esc_html($log['reminder_type'] ?? ''); ?></span></td>
              <td><?php echo esc_html($log['channel'] ?? ''); ?></td>
              <td style="max-width:320px"><?php echo esc_html(wp_trim_words($log['message_preview'] ?? $log['message'] ?? '', 18, '…')); ?></td>
              <td>
                <?php if (($log['status'] ?? '') === 'sent'): ?>
                  <span class="bccm-badge bccm-badge-sm bccm-badge-success">✅ Sent</span>
                <?php else: ?>
                  <span class="bccm-badge bccm-badge-sm bccm-badge-danger">❌ Failed</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p style="margin-top:12px"><a href="<?php echo esc_url(admin_url('admin.php?page=bccm_cron_reminders')); ?>" class="bccm-link-arrow">Xem tất cả logs →</a></p>
      <?php else: ?>
        <div class="bccm-empty-state bccm-empty-sm">
          <p>Chưa có reminder logs nào.</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- ======================== COACHEES OVERVIEW ======================== -->
    <div class="bccm-card">
      <h2>👥 Coachees gần đây</h2>
      <?php
      $recent = $wpdb->get_results("SELECT * FROM {$t['profiles']} ORDER BY id DESC LIMIT 6", ARRAY_A);
      if ($recent):
      ?>
      <table class="widefat striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Họ tên</th>
            <th>Coach Type</th>
            <th>Platform</th>
            <th>Bản đồ</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $r):
            // Check map completeness
            $has_map = [];
            if (!empty(json_decode($r['numeric_json'] ?? '', true)))  $has_map[] = '🔢';
            if (!empty(json_decode($r['ai_summary'] ?? '', true)) || !empty($r['ai_summary']))    $has_map[] = '🩺';
            if (!empty(json_decode($r['health_json'] ?? '', true)))   $has_map[] = '💪';
            if (!empty(json_decode($r['mental_json'] ?? '', true)))   $has_map[] = '🗺️';
            if (!empty(json_decode($r['bizcoach_json'] ?? '', true))) $has_map[] = '📅';
          ?>
          <tr>
            <td><?php echo $r['id']; ?></td>
            <td><strong><?php echo esc_html($r['full_name']); ?></strong></td>
            <td><span class="bccm-badge bccm-badge-sm"><?php echo esc_html(ucfirst(str_replace('_',' ',$r['coach_type'] ?? ''))); ?></span></td>
            <td><?php echo esc_html($r['platform_type'] ?? 'WEBCHAT'); ?></td>
            <td><?php echo $has_map ? implode(' ', $has_map) : '<span style="color:#aaa">—</span>'; ?></td>
            <td><?php echo esc_html(wp_date('d/m/Y', strtotime($r['created_at']))); ?></td>
            <td>
              <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=bccm_coachees&edit=' . $r['id'])); ?>">Edit</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin-top:12px"><a href="<?php echo esc_url(admin_url('admin.php?page=bccm_coachees_list')); ?>" class="bccm-link-arrow">Xem tất cả coachees →</a></p>
      <?php else: ?>
        <div class="bccm-empty-state bccm-empty-sm">
          <span class="bccm-empty-icon">👥</span>
          <p>Chưa có coachee nào.</p>
          <a href="<?php echo esc_url(admin_url('admin.php?page=bccm_coachees')); ?>" class="button button-primary">Thêm Coachee đầu tiên</a>
        </div>
      <?php endif; ?>
    </div>

  </div>
  <?php
}
