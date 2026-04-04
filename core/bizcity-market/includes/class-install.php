<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_Market
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;

/**
 * BizCity_Market_Install
 *
 * Lớp chịu trách nhiệm tạo/upgrade schema DB cho module BizCity Market.
 * - Sử dụng option (site option) để lưu version schema (OPT_DB_VER).
 * - Chỉ thực hiện CREATE/ALTER khi version trên hệ thống khác với DB_VERSION.
 * - Trả về WP_Error khi có lỗi để caller/cron/plugin loader có thể log/hiện notice.
 *
 * Ghi chú:
 * - Không drop bảng cũ tự động ở đây (tránh mất data). Nếu muốn reset, thực hiện thủ công.
 * - Dùng get_site_option / update_site_option để hỗ trợ multisite.
 */
class BizCity_Market_Install {

    const DB_VERSION = '0.4.0';
    const OPT_DB_VER = 'bizcity_market_db_version';

    /**
     * boot()
     * - Đăng ký hook để kiểm tra phiên bản DB sớm khi plugins_loaded.
     * - Priority thấp (2) để chạy trước nhiều plugin khác nếu cần.
     */
    public static function boot() {
        add_action('plugins_loaded', [__CLASS__, 'maybe_install'], 2);
    }

    /**
     * maybe_install()
     *
     * Mục đích:
     * - Kiểm tra option lưu version schema. Nếu khác DB_VERSION -> gọi install().
     * - Nếu install() thành công thì cập nhật option phiên bản.
     *
     * Chi tiết:
     * - Nếu option chưa tồn tại nhưng bảng đã tồn tại (ví dụ import từ nơi khác),
     *   hàm install() vẫn sẽ kiểm tra và chỉ set option khi xác nhận bảng tồn tại.
     * - Trả về true khi đã có version đúng (không làm gì) hoặc cài thành công.
     * - Trả về WP_Error nếu cài thất bại (caller có thể log).
     */
    public static function maybe_install() {
        $installed = get_site_option(self::OPT_DB_VER, '');
        if ($installed === self::DB_VERSION) {
            // Đã đúng version, không cần làm gì.
            return true;
        }

        $res = self::install();
        if (is_wp_error($res)) {
            // Nếu cần: log lỗi tại đây (không echo)
            return $res;
        }

        // Chỉ update option khi install() thành công
        update_site_option(self::OPT_DB_VER, self::DB_VERSION);
        return true;
    }

    /**
     * install()
     *
     * Mục đích:
     * - Tạo các bảng cần thiết nếu chưa tồn tại, hoặc đảm bảo schema cơ bản.
     * - Không DROP dữ liệu (tránh mất data). Sử dụng CREATE TABLE IF NOT EXISTS.
     *
     * Quy trình:
     * 1. Lấy handle DB toàn cục (BizCity_Market_DB::globaldb()).
     * 2. Chuẩn bị các câu SQL CREATE TABLE IF NOT EXISTS.
     * 3. Thực thi từng câu, kiểm tra lỗi $db->last_error.
     * 4. Xác nhận bảng thực sự tồn tại (SHOW TABLES LIKE ...) trước khi trả về success.
     *
     * Trả về:
     * - true khi thành công (bảng đã tồn tại sau thao tác).
     * - WP_Error khi có lỗi (kèm message từ $wpdb->last_error nếu có).
     */
    public static function install() {
        $db = BizCity_Market_DB::globaldb();
        if (!$db) return new WP_Error('globaldb_missing', 'Global DB handle not found (globaldb/gwpdb missing)');

        $t_plugins = BizCity_Market_DB::table('market_plugins');
        $t_votes   = BizCity_Market_DB::table('market_plugin_votes');
        $t_ent     = BizCity_Market_DB::table('entitlements');
        $t_hub_rollups = BizCity_Market_DB::table('market_hub_rollups');

        // Charset/collation từ $wpdb để tương thích môi trường
        $charset = $db->get_charset_collate();

        // SQL schema (CREATE IF NOT EXISTS để an toàn)
        $sql_plugins = "CREATE TABLE IF NOT EXISTS {$t_plugins} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_slug VARCHAR(191) NOT NULL,
            plugin_file VARCHAR(191) NOT NULL,
            directory   VARCHAR(191) NOT NULL,
            title       VARCHAR(255) NOT NULL,
            author_name VARCHAR(191) DEFAULT '',
            author_url  VARCHAR(255) DEFAULT '',
            image_url   VARCHAR(255) DEFAULT '',
            icon_url    VARCHAR(255) DEFAULT '',
            quickview LONGTEXT NULL,
            description LONGTEXT NULL,
            credit_price INT UNSIGNED NOT NULL DEFAULT 0,
            vnd_price    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            download_url VARCHAR(255) DEFAULT '',
            demo_url     VARCHAR(255) DEFAULT '',
            after_active_url VARCHAR(255) DEFAULT '',
            views BIGINT UNSIGNED NOT NULL DEFAULT 0,
            useful_score DECIMAL(3,2) NOT NULL DEFAULT 0.00,
            useful_count INT UNSIGNED NOT NULL DEFAULT 0,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            is_active   TINYINT(1) NOT NULL DEFAULT 1,
            sort_order  INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (plugin_slug),
            KEY idx_active (is_active, sort_order),
            KEY idx_dir (directory),
            KEY idx_views (views)
        ) {$charset};";

        $sql_votes = "CREATE TABLE IF NOT EXISTS {$t_votes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_slug VARCHAR(191) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            score TINYINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_vote (plugin_slug, user_id),
            KEY idx_slug (plugin_slug),
            KEY idx_user (user_id)
        ) {$charset};";

        $sql_ent = "CREATE TABLE IF NOT EXISTS {$t_ent} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hub_blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            blog_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_type VARCHAR(32) NOT NULL DEFAULT 'plugin',
            product_slug VARCHAR(191) NOT NULL,
            mode VARCHAR(32) NOT NULL DEFAULT 'once',
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            credit_cost INT UNSIGNED NOT NULL DEFAULT 0,
            period_start DATETIME NULL,
            period_end   DATETIME NULL,
            next_charge_at DATETIME NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_blog (blog_id, product_type, status),
            KEY idx_slug (product_slug),
            KEY idx_next (status, next_charge_at),
            KEY idx_user (user_id)
        ) {$charset};";

        $sql_hub_rollup = "
            CREATE TABLE IF NOT EXISTS {$t_hub_rollups} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hub_blog_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(32) NOT NULL DEFAULT 'plugin',
            day TINYINT UNSIGNED NOT NULL,
            month TINYINT UNSIGNED NOT NULL,
            year SMALLINT UNSIGNED NOT NULL,
            amount_money_vnd BIGINT UNSIGNED NOT NULL DEFAULT 0,
            amount_credit BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_rollup (hub_blog_id, type, day, month, year),
            KEY idx_hub (hub_blog_id),
            KEY idx_date (year, month, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // ✅ NEW v0.3.0: global_plugins_meta table
        $t_meta = method_exists('BizCity_Market_DB', 't_plugins_meta')
            ? BizCity_Market_DB::t_plugins_meta()
            : BizCity_Market_DB::table('global_plugins_meta');
        $sql_meta = "CREATE TABLE IF NOT EXISTS {$t_meta} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_slug VARCHAR(191) NOT NULL,
            total_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_installs BIGINT UNSIGNED NOT NULL DEFAULT 0,
            active_count INT UNSIGNED NOT NULL DEFAULT 0,
            avg_rating DECIMAL(3,2) NOT NULL DEFAULT 0.00,
            rating_count INT UNSIGNED NOT NULL DEFAULT 0,
            category VARCHAR(191) DEFAULT '',
            tags VARCHAR(500) DEFAULT '',
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (plugin_slug),
            KEY idx_category (category),
            KEY idx_installs (total_installs),
            KEY idx_rating (avg_rating)
        ) {$charset};";


        // Thực thi từng câu SQL, kiểm tra lỗi ngay lập tức
        $queries = [
            'plugins' => $sql_plugins,
            'votes'   => $sql_votes,
            'ent'     => $sql_ent,
            'meta'    => $sql_meta,
        ];

        foreach ($queries as $k => $sql) {
            $res = $db->query($sql);
            if ($res === false || !empty($db->last_error)) {
                $err = $db->last_error ?: 'Unknown error while creating table ' . ($k);
                return new WP_Error('db_query_failed', 'Failed to create/ensure table: ' . $err);
            }
        }

        // Migration: add icon_url column if missing (v0.2.2+)
        $cols = $db->get_col( "SHOW COLUMNS FROM {$t_plugins}" );
        if ( ! in_array( 'icon_url', $cols, true ) ) {
            $db->query( "ALTER TABLE {$t_plugins} ADD COLUMN icon_url VARCHAR(255) DEFAULT '' AFTER image_url" );
        }

        // Migration v0.3.0: add category column to market_plugins
        if ( ! in_array( 'category', $cols, true ) ) {
            $db->query( "ALTER TABLE {$t_plugins} ADD COLUMN category VARCHAR(191) DEFAULT '' AFTER sort_order" );
            $db->query( "ALTER TABLE {$t_plugins} ADD KEY idx_category (category)" );
        }

        // Migration v0.3.0: add gallery column if missing
        if ( ! in_array( 'gallery', $cols, true ) ) {
            $db->query( "ALTER TABLE {$t_plugins} ADD COLUMN gallery LONGTEXT NULL AFTER description" );
        }

        // Migration v0.4.0: add required_plan column for plan-based access control
        if ( ! in_array( 'required_plan', $cols, true ) ) {
            $db->query( "ALTER TABLE {$t_plugins} ADD COLUMN required_plan VARCHAR(32) NOT NULL DEFAULT 'free' AFTER category" );
        }

        // Xác nhận tồn tại từng bảng (phòng trường hợp create silently failed)
        $tables_to_check = [$t_plugins, $t_votes, $t_ent, $t_meta];
        foreach ($tables_to_check as $tbl) {
            $exists = $db->get_var($db->prepare("SHOW TABLES LIKE %s", $tbl));
            if ($exists !== $tbl) {
                return new WP_Error('db_table_missing', "Table {$tbl} does not exist after creation attempt.");
            }
        }
        
        // Thành công: cập nhật site option ở caller (maybe_install) hoặc ở đây cũng ok.
        return true;
    }

}
