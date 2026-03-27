<?php
/**
 * BCCM_Installer — Quản lý DB schema & migration cho BizCoach Map.
 *
 * Singleton class:
 *   BCCM_Installer::instance()->install_tables();
 *   BCCM_Installer::instance()->maybe_upgrade();
 *
 * Backward-compatible wrappers (global functions) được giữ ở cuối file
 * để các file khác gọi bccm_install_tables() vẫn hoạt động.
 *
 * @package BizCoach_Map
 * @since   0.1.0.8
 */

if (!defined('ABSPATH')) exit;

class BCCM_Installer {

  /** @var self|null */
  private static $instance = null;

  /** @var \wpdb */
  private $wpdb;

  /** @var string Option key lưu DB version đã migrate */
  private const OPT_DB_VERSION = 'bccm_db_version';

  /* ------------------------------------------------------------------
   * Singleton
   * ----------------------------------------------------------------*/
  public static function instance(): self {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private function __construct() {
    global $wpdb;
    $this->wpdb = $wpdb;
  }

  /* ==================================================================
   * INSTALL — CREATE TABLE IF NOT EXISTS (dbDelta)
   * ==================================================================*/

  /**
   * Tạo tất cả bảng cần thiết. Safe gọi nhiều lần (idempotent).
   */
  public function install_tables(): void {
    $charset = $this->wpdb->get_charset_collate();
    $t       = bccm_tables();
    $prefix  = $this->wpdb->prefix;

    $sqls = [];

    // 1. Profiles (coachees)
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$t['profiles']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NULL DEFAULT NULL,
      platform_type VARCHAR(32) NOT NULL DEFAULT 'WEBCHAT',
      coach_type VARCHAR(64) NOT NULL,
      full_name VARCHAR(255) NOT NULL,
      phone VARCHAR(64) NULL,
      address VARCHAR(255) NULL,
      company_name VARCHAR(255) NULL,
      company_founded_date DATE NULL,
      company_industry VARCHAR(255) NULL,
      company_product VARCHAR(255) NULL,
      dob DATE NULL,
      ai_summary LONGTEXT NULL,
      numeric_json LONGTEXT NULL,
      answer_json LONGTEXT NULL,
      vision_json LONGTEXT NULL,
      swot_json LONGTEXT NULL,
      customer_json LONGTEXT NULL,
      winning_json LONGTEXT NULL,
      value_json LONGTEXT NULL,
      bizcoach_json LONGTEXT NULL,
      baby_name VARCHAR(255) NULL,
      baby_gender VARCHAR(16) NULL,
      baby_gestational_weeks TINYINT NULL,
      baby_weight_kg DECIMAL(5,2) NULL,
      baby_height_cm DECIMAL(5,2) NULL,
      baby_json LONGTEXT NULL,
      iqmap_json LONGTEXT NULL,
      health_json LONGTEXT NULL,
      mental_json LONGTEXT NULL,
      zodiac_sign VARCHAR(32) NULL DEFAULT '',
      extra_fields_json LONGTEXT NULL,
      public_url TEXT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY coach_type (coach_type),
      KEY phone (phone),
      KEY user_id (user_id),
      KEY platform_type (platform_type)
    ) $charset;";

    // 2. Templates
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$t['templates']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coach_type VARCHAR(64) NOT NULL,
      title VARCHAR(255) NOT NULL,
      questions LONGTEXT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY coach_type (coach_type)
    ) $charset;";

    // 3. Answers
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$t['answers']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coachee_id BIGINT UNSIGNED NOT NULL,
      template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      answers LONGTEXT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY coachee_id (coachee_id)
    ) $charset;";

    // 4. Plan templates
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$t['plan_templates']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coach_type VARCHAR(64) NOT NULL,
      title VARCHAR(255) NOT NULL,
      content LONGTEXT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY coach_type (coach_type)
    ) $charset;";

    // 5. Plans
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$t['plans']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coachee_id BIGINT UNSIGNED NOT NULL,
      template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      plan LONGTEXT NULL,
      public_key VARCHAR(64) NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY public_key (public_key),
      KEY coachee_id (coachee_id)
    ) $charset;";

    // 6. Daily logs
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$t['logs']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coachee_id BIGINT UNSIGNED NOT NULL,
      plan_id BIGINT UNSIGNED NOT NULL,
      day_number SMALLINT UNSIGNED NOT NULL,
      journal LONGTEXT NULL,
      ai_feedback LONGTEXT NULL,
      ai_score TINYINT NULL,
      coach_score TINYINT NULL,
      total_score TINYINT NULL,
      entry_date DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY unique_day (plan_id, day_number),
      KEY coachee_id (coachee_id)
    ) $charset;";

    // 7. Metrics (numerology)
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$prefix}bccm_metrics (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coachee_id BIGINT UNSIGNED NOT NULL,
      numbers_full LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_coachee (coachee_id)
    ) $charset;";

    // 8. Astro (supports multiple chart_type per coachee: western, vedic)
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$prefix}bccm_astro (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coachee_id BIGINT UNSIGNED NOT NULL,
      user_id    BIGINT UNSIGNED NULL DEFAULT NULL,
      chart_type VARCHAR(32)  NOT NULL DEFAULT 'western',
      birth_place VARCHAR(191) DEFAULT '',
      birth_time  VARCHAR(32)  DEFAULT '',
      latitude    DECIMAL(10,7) NULL DEFAULT NULL,
      longitude   DECIMAL(10,7) NULL DEFAULT NULL,
      timezone    DECIMAL(4,1) NULL DEFAULT 7.0,
      summary     LONGTEXT NULL,
      traits      LONGTEXT NULL,
      chart_svg   TEXT NULL,
      llm_report  LONGTEXT NULL,
      created_at  DATETIME NOT NULL,
      updated_at  DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_coachee_chart (coachee_id, chart_type),
      KEY idx_user_id (user_id),
      KEY idx_chart_type (chart_type)
    ) $charset;";

    // 9. Reminder logs
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$prefix}bccm_reminder_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coachee_id BIGINT UNSIGNED NOT NULL,
      reminder_type VARCHAR(32) NOT NULL DEFAULT 'daily',
      channel VARCHAR(32) NOT NULL DEFAULT 'email',
      message_preview TEXT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'sent',
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY coachee_id (coachee_id),
      KEY reminder_type (reminder_type)
    ) $charset;";

    // 10. Generator results — lưu từng kết quả generator riêng biệt
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$prefix}bccm_gen_results (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coachee_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NULL DEFAULT NULL,
      coach_type VARCHAR(64) NOT NULL,
      gen_key VARCHAR(128) NOT NULL,
      gen_fn VARCHAR(255) NOT NULL DEFAULT '',
      gen_label VARCHAR(255) NOT NULL DEFAULT '',
      result_json LONGTEXT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'success',
      error_msg TEXT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_coachee_gen (coachee_id, gen_key),
      KEY idx_user_id (user_id),
      KEY idx_coach_type (coach_type),
      KEY idx_status (status)
    ) $charset;";

    // 11. Transit snapshots — pre-fetched planet positions for AI transit context (no live API during chat)
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$prefix}bccm_transit_snapshots (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      coachee_id   BIGINT UNSIGNED NOT NULL,
      user_id      BIGINT UNSIGNED NULL DEFAULT NULL,
      target_date  DATE NOT NULL,
      label        VARCHAR(64) NOT NULL DEFAULT '',
      planets_json LONGTEXT NULL,
      aspects_json LONGTEXT NULL,
      fetched_at   DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_coachee_date (coachee_id, target_date),
      KEY idx_user_id (user_id),
      KEY idx_target_date (target_date)
    ) $charset;";

    foreach ($sqls as $sql) {
      dbDelta($sql);
    }
  }

  /* ==================================================================
   * MIGRATION SYSTEM — version-based upgrade
   * ==================================================================*/

  /**
   * Registry: version → method name.
   * Sắp xếp tăng dần. Khi thêm version mới:
   *   1. Tạo method migrate_X_Y_Z() trong class này.
   *   2. Thêm entry vào mảng.
   *   3. Bump BCCM_VERSION trong bizcoach.php.
   *
   * @return array<string, string>
   */
  private function migration_steps(): array {
    return [
      '0.1.0.5' => 'migrate_0_1_0_5',
      '0.1.0.8' => 'migrate_0_1_0_8',
      '0.1.0.14' => 'migrate_0_1_0_14',
      '0.1.0.15' => 'migrate_0_1_0_15',
      '0.1.0.17' => 'migrate_0_1_0_17',
      '0.1.0.18' => 'migrate_0_1_0_18',
      '0.1.0.19' => 'migrate_0_1_0_19',
      '0.1.0.35' => 'migrate_0_1_0_35',
    ];
  }

  /**
   * So sánh db_version vs BCCM_VERSION.
   * Nếu cũ hơn → install_tables() + chạy từng migration step chưa áp dụng.
   * Gọi trên admin_init (priority 5).
   */
  public function maybe_upgrade(): void {
    $db_ver = $this->get_db_version();
    $target = defined('BCCM_VERSION') ? BCCM_VERSION : '0.1.0.8';

    if (version_compare($db_ver, $target, '>=')) {
      return;
    }

    // Đảm bảo tables tồn tại trước khi ALTER
    $this->install_tables();

    // Chạy từng migration chưa áp dụng
    foreach ($this->migration_steps() as $ver => $method) {
      if (version_compare($db_ver, $ver, '<') && method_exists($this, $method)) {
        $this->$method();
        // Lưu ngay sau mỗi step → crash-safe
        update_option(self::OPT_DB_VERSION, $ver);
      }
    }

    // Ghi target version cuối cùng
    update_option(self::OPT_DB_VERSION, $target);
  }

  /* ------------------------------------------------------------------
   * Migration methods — mỗi version 1 method
   * ----------------------------------------------------------------*/

  /**
   * v0.1.0.5: Thêm user_id, platform_type vào profiles.
   */
  private function migrate_0_1_0_5(): void {
    $table = bccm_tables()['profiles'];
    $cols  = $this->get_columns($table);

    if (!in_array('user_id', $cols, true)) {
      $this->wpdb->query("ALTER TABLE `$table` ADD COLUMN user_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER id");
      $this->wpdb->query("ALTER TABLE `$table` ADD INDEX user_id (user_id)");
    }
    if (!in_array('platform_type', $cols, true)) {
      $this->wpdb->query("ALTER TABLE `$table` ADD COLUMN platform_type VARCHAR(32) NOT NULL DEFAULT 'WEBCHAT' AFTER user_id");
      $this->wpdb->query("ALTER TABLE `$table` ADD INDEX platform_type (platform_type)");
    }
  }

  /**
   * v0.1.0.8: Astrology — zodiac_sign in profiles, expand bccm_astro.
   */
  private function migrate_0_1_0_8(): void {
    $table = bccm_tables()['profiles'];
    $cols  = $this->get_columns($table);

    if (!in_array('zodiac_sign', $cols, true)) {
      $this->wpdb->query("ALTER TABLE `$table` ADD COLUMN zodiac_sign VARCHAR(32) NULL DEFAULT '' AFTER mental_json");
    }

    $t_astro = $this->wpdb->prefix . 'bccm_astro';
    if ($this->table_exists($t_astro)) {
      $acols = $this->get_columns($t_astro);
      $this->add_column_if_missing($t_astro, $acols, 'latitude',  'DECIMAL(10,7) NULL DEFAULT NULL', 'birth_time');
      $this->add_column_if_missing($t_astro, $acols, 'longitude', 'DECIMAL(10,7) NULL DEFAULT NULL', 'latitude');
      $this->add_column_if_missing($t_astro, $acols, 'timezone',  'DECIMAL(4,1) NULL DEFAULT 7.0',   'longitude');
      $this->add_column_if_missing($t_astro, $acols, 'chart_svg', 'TEXT NULL',                       'traits');
      $this->add_column_if_missing($t_astro, $acols, 'prokerala_chart', 'LONGTEXT NULL',              'chart_svg');
    }
  }

  /**
   * v0.1.0.14: Ensure prokerala_chart column exists (may have been skipped).
   */
  private function migrate_0_1_0_14(): void {
    $t_astro = $this->wpdb->prefix . 'bccm_astro';
    if ($this->table_exists($t_astro)) {
      $acols = $this->get_columns($t_astro);
      $this->add_column_if_missing($t_astro, $acols, 'prokerala_chart', 'LONGTEXT NULL', 'chart_svg');
    }
  }

  /**
   * v0.1.0.15: Add prokerala_summary + prokerala_traits columns for dual-chart support.
   */
  private function migrate_0_1_0_15(): void {
    $t_astro = $this->wpdb->prefix . 'bccm_astro';
    if ($this->table_exists($t_astro)) {
      $acols = $this->get_columns($t_astro);
      $this->add_column_if_missing($t_astro, $acols, 'prokerala_summary', 'LONGTEXT NULL', 'prokerala_chart');
      $this->add_column_if_missing($t_astro, $acols, 'prokerala_traits',  'LONGTEXT NULL', 'prokerala_summary');
    }
  }

  /**
   * v0.1.0.17: Add user_id, chart_type, llm_report columns.
   * Change UNIQUE KEY from coachee_id to (coachee_id, chart_type).
   * Backfill user_id from coachee profile.
   * Set existing rows chart_type = 'western'.
   */
  private function migrate_0_1_0_17(): void {
    $t_astro = $this->wpdb->prefix . 'bccm_astro';
    if (!$this->table_exists($t_astro)) return;

    $acols = $this->get_columns($t_astro);

    // Add new columns
    $this->add_column_if_missing($t_astro, $acols, 'user_id',    'BIGINT UNSIGNED NULL DEFAULT NULL', 'coachee_id');
    $this->add_column_if_missing($t_astro, $acols, 'chart_type', "VARCHAR(32) NOT NULL DEFAULT 'western'", 'user_id');
    $this->add_column_if_missing($t_astro, $acols, 'llm_report', 'LONGTEXT NULL', 'chart_svg');

    // Refresh column list
    $acols = $this->get_columns($t_astro);

    // Set old Prokerala columns to allow NULL with default (in case they don't), then drop them
    if (in_array('prokerala_chart', $acols)) {
      $this->wpdb->query("ALTER TABLE `$t_astro` MODIFY COLUMN prokerala_chart LONGTEXT NULL DEFAULT NULL");
      $this->wpdb->query("ALTER TABLE `$t_astro` DROP COLUMN prokerala_chart");
    }
    if (in_array('prokerala_summary', $acols)) {
      $this->wpdb->query("ALTER TABLE `$t_astro` MODIFY COLUMN prokerala_summary LONGTEXT NULL DEFAULT NULL");
      $this->wpdb->query("ALTER TABLE `$t_astro` DROP COLUMN prokerala_summary");
    }
    if (in_array('prokerala_traits', $acols)) {
      $this->wpdb->query("ALTER TABLE `$t_astro` MODIFY COLUMN prokerala_traits LONGTEXT NULL DEFAULT NULL");
      $this->wpdb->query("ALTER TABLE `$t_astro` DROP COLUMN prokerala_traits");
    }

    // Add index for user_id (ignore errors if exists)
    $this->wpdb->query("ALTER TABLE `$t_astro` ADD INDEX idx_user_id (user_id)");
    $this->wpdb->query("ALTER TABLE `$t_astro` ADD INDEX idx_chart_type (chart_type)");

    // Change UNIQUE KEY from (coachee_id) to (coachee_id, chart_type)
    // Drop old unique key first
    $indexes = $this->wpdb->get_results("SHOW INDEX FROM `$t_astro` WHERE Key_name = 'uniq_coachee'");
    if (!empty($indexes)) {
      $this->wpdb->query("ALTER TABLE `$t_astro` DROP INDEX uniq_coachee");
      $this->wpdb->query("ALTER TABLE `$t_astro` ADD UNIQUE KEY uniq_coachee_chart (coachee_id, chart_type)");
    }

    // Set existing rows to chart_type = 'western'
    $this->wpdb->query("UPDATE `$t_astro` SET chart_type = 'western' WHERE chart_type = '' OR chart_type IS NULL");

    // Backfill user_id from coachee profiles
    $t_profiles = bccm_tables()['profiles'];
    $this->wpdb->query("
      UPDATE `$t_astro` a
      INNER JOIN `$t_profiles` p ON a.coachee_id = p.id
      SET a.user_id = p.user_id
      WHERE a.user_id IS NULL AND p.user_id IS NOT NULL
    ");

    // Migrate existing LLM reports from wp_options to llm_report column
    $rows = $this->wpdb->get_results("SELECT id, coachee_id FROM `$t_astro` WHERE chart_type = 'western' AND (llm_report IS NULL OR llm_report = '')");
    foreach ($rows as $row) {
      $cache_key = 'bccm_llm_report_raw_' . $row->coachee_id;
      $cached = get_option($cache_key);
      if (is_array($cached) && !empty($cached['sections'])) {
        $this->wpdb->update($t_astro, [
          'llm_report' => wp_json_encode($cached, JSON_UNESCAPED_UNICODE),
        ], ['id' => $row->id]);
        // Clean up old wp_options cache
        delete_option($cache_key);
      }
    }
  }

  /**
   * v0.1.0.18: Tạo bảng bccm_gen_results + backfill dữ liệu từ profiles.
   * Bảng mới lưu kết quả từng generator riêng biệt (thay vì nhét vào cột LONGTEXT trong profiles).
   */
  private function migrate_0_1_0_18(): void {
    $prefix = $this->wpdb->prefix;
    $t_gen  = $prefix . 'bccm_gen_results';

    // install_tables() đã tạo bảng rồi (gọi ở maybe_upgrade trước khi chạy migration)
    // Backfill: Với mỗi coachee có các cột JSON đã gen, copy sang bảng mới
    $t_profiles = bccm_tables()['profiles'];

    // Mapping: gen_key → column trong profiles
    $col_map = [
      'gen_career_overview'   => 'ai_summary',
      'gen_career_vision'     => 'vision_json',
      'gen_career_swot'       => 'swot_json',
      'gen_career_value'      => 'value_json',
      'gen_career_winning'    => 'winning_json',
      'gen_career_leadership' => 'iqmap_json',
      'gen_career_milestone'  => 'bizcoach_json',
      // Health coach
      'gen_health_overview'   => 'ai_summary',
      'gen_health_plan'       => 'health_json',
      'gen_health_mental'     => 'mental_json',
      'gen_health_milestone'  => 'bizcoach_json',
    ];

    // Lấy tất cả coachees có coach_type career_coach hoặc health_coach
    $rows = $this->wpdb->get_results("
      SELECT id, user_id, coach_type FROM `$t_profiles`
      WHERE coach_type IN ('career_coach','health_coach')
    ");

    if (empty($rows)) return;

    foreach ($rows as $row) {
      $gens = function_exists('bccm_generators_for_type') ? bccm_generators_for_type($row->coach_type) : [];
      foreach ($gens as $g) {
        $gen_key = $g['key'] ?? '';
        $col     = $col_map[$gen_key] ?? '';
        if (!$gen_key || !$col) continue;

        // Đọc giá trị từ profiles
        $val = $this->wpdb->get_var($this->wpdb->prepare(
          "SELECT `$col` FROM `$t_profiles` WHERE id=%d", $row->id
        ));

        if (empty($val)) continue;

        // Chỉ insert nếu chưa có
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
          "SELECT id FROM `$t_gen` WHERE coachee_id=%d AND gen_key=%s", $row->id, $gen_key
        ));
        if ($exists) continue;

        $this->wpdb->insert($t_gen, [
          'coachee_id'  => (int)$row->id,
          'user_id'     => $row->user_id ? (int)$row->user_id : null,
          'coach_type'  => $row->coach_type,
          'gen_key'     => $gen_key,
          'gen_fn'      => $g['fn'] ?? '',
          'gen_label'   => $g['label'] ?? '',
          'result_json' => $val,
          'status'      => 'success',
          'error_msg'   => null,
          'created_at'  => current_time('mysql'),
          'updated_at'  => current_time('mysql'),
        ]);
      }
    }
  }

  /* ==================================================================
   * DB HELPERS
   * ==================================================================

  /**
   * Lấy DB version đã migrate.
   */
  public function get_db_version(): string {
    return get_option(self::OPT_DB_VERSION, '0');
  }

  /**
   * Kiểm tra table có tồn tại không.
   */
  public function table_exists( string $table ): bool {
    return $this->wpdb->get_var(
      $this->wpdb->prepare("SHOW TABLES LIKE %s", $table)
    ) === $table;
  }

  /**
   * Lấy danh sách tên cột của 1 table.
   *
   * @return string[]
   */
  public function get_columns( string $table ): array {
    if (!$this->table_exists($table)) {
      return [];
    }
    return $this->wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
  }

  /**
   * Kiểm tra cột có tồn tại trong table.
   */
  public function has_column( string $table, string $column ): bool {
    return in_array($column, $this->get_columns($table), true);
  }

  /**
   * Thêm cột nếu chưa tồn tại (DRY helper cho migration).
   *
   * @param string   $table  Full table name.
   * @param string[] $cols   Danh sách cột hiện có (từ get_columns()).
   * @param string   $column Tên cột cần thêm.
   * @param string   $def    Định nghĩa cột (type + default).
   * @param string   $after  Thêm AFTER cột nào.
   */
  /**
   * v0.1.0.19: Add extra_fields_json column to profiles for type-specific fields
   * (career_coach: current_role, years_experience, education_level, etc.)
   */
  private function migrate_0_1_0_19(): void {
    $table = bccm_tables()['profiles'];
    $cols  = $this->get_columns($table);
    $this->add_column_if_missing($table, $cols, 'extra_fields_json', 'LONGTEXT NULL', 'zodiac_sign');

    // Backfill: if coachees already have data in known type-specific columns that
    // were somehow stored (e.g. via direct SQL), migrate them to extra_fields_json.
    // For now, the column is new so no backfill needed.
  }

  /**
   * v0.1.0.35: Add bccm_transit_snapshots table (pre-fetched transit data for AI, no live API at chat time).
   */
  private function migrate_0_1_0_35(): void {
    // Table is created via install_tables() / dbDelta — no ALTER needed.
    // This entry just ensures maybe_upgrade() reruns install_tables() on deploys.
  }

  private function add_column_if_missing( string $table, array $cols, string $column, string $def, string $after ): void {
    if (!in_array($column, $cols, true)) {
      $this->wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $def AFTER `$after`");
    }
  }
}

/* =====================================================================
 * Hook admin_init → maybe_upgrade (per-site, khi admin vào dashboard)
 * =====================================================================*/
add_action('admin_init', function () {
  BCCM_Installer::instance()->maybe_upgrade();
}, 5);

/* =====================================================================
 * Hook network_admin_init → upgrade tất cả sites trong multisite network
 *
 * Khi plugin kích hoạt ở cấp mạng (network-activate), activation_hook
 * không chạy per-site. Hook này đảm bảo bảng mới được tạo trên MỌI site
 * mỗi khi version mismatch được phát hiện từ Network Admin.
 * =====================================================================*/
add_action('network_admin_init', function () {
  if (!is_multisite()) return;

  $target   = defined('BCCM_VERSION') ? BCCM_VERSION : '0.1.0.8';
  $opt_key  = 'bccm_db_version';

  // Quick check on current site before iterating all sites
  $current_ver = get_site_option('bccm_network_db_version', '0.0.0');
  if (version_compare($current_ver, $target, '>=')) return;

  // Iterate all sites and run install_tables() + maybe_upgrade() on each
  $sites = get_sites(['number' => 500, 'fields' => 'ids']);
  foreach ($sites as $blog_id) {
    switch_to_blog($blog_id);
    BCCM_Installer::instance()->install_tables();
    BCCM_Installer::instance()->maybe_upgrade();
    restore_current_blog();
  }

  // Mark network-level version so we don't re-iterate on every page load
  update_site_option('bccm_network_db_version', $target);
}, 5);

/* =====================================================================
 * Hook wp_initialize_site → tạo bảng cho site mới tạo trong network
 * =====================================================================*/
add_action('wp_initialize_site', function ($new_site) {
  if (!is_multisite()) return;
  switch_to_blog($new_site->blog_id);
  BCCM_Installer::instance()->install_tables();
  BCCM_Installer::instance()->maybe_upgrade();
  restore_current_blog();
}, 99);

/* =====================================================================
 * BACKWARD-COMPATIBLE WRAPPERS
 * Giữ global functions để code cũ (bizcoach.php, frontend-astro-form.php)
 * gọi bccm_install_tables() vẫn hoạt động bình thường.
 * =====================================================================*/

function bccm_install_tables() {
  BCCM_Installer::instance()->install_tables();
}

function bccm_get_db_version(): string {
  return BCCM_Installer::instance()->get_db_version();
}

function bccm_table_exists( $wpdb_or_table, $table = null ): bool {
  // Hỗ trợ cả cách gọi cũ bccm_table_exists($wpdb, $table) và mới bccm_table_exists($table)
  if ($table !== null) {
    return BCCM_Installer::instance()->table_exists($table);
  }
  return BCCM_Installer::instance()->table_exists($wpdb_or_table);
}

function bccm_get_table_columns( $wpdb_or_table, $table = null ): array {
  if ($table !== null) {
    return BCCM_Installer::instance()->get_columns($table);
  }
  return BCCM_Installer::instance()->get_columns($wpdb_or_table);
}
