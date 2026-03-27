<?php
/**
 * Database installer — creates global tables on activation.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BZGoogle_Installer {

    /**
     * Global table: Google accounts (tokens).
     */
    public static function table_accounts() {
        global $wpdb;
        return $wpdb->base_prefix . 'bizcity_google_accounts';
    }

    /**
     * Global table: usage logs.
     */
    public static function table_logs() {
        global $wpdb;
        return $wpdb->base_prefix . 'bizcity_google_usage_logs';
    }

    /**
     * Run on plugin activation.
     */
    public static function activate() {
        self::create_tables();
        self::maybe_flush_rewrite();
    }

    /**
     * Create global tables using dbDelta.
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $t_accounts = self::table_accounts();
        $t_logs     = self::table_logs();

        $sql = "
CREATE TABLE {$t_accounts} (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    blog_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    google_email    VARCHAR(255) NOT NULL DEFAULT '',
    google_sub      VARCHAR(255) NOT NULL DEFAULT '',
    access_token    TEXT NOT NULL,
    refresh_token   TEXT NOT NULL,
    scope           TEXT NOT NULL,
    expires_at      DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    connection_mode VARCHAR(20) NOT NULL DEFAULT 'shared_app',
    status          VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blog_user_email (blog_id, user_id, google_email),
    KEY idx_blog_user (blog_id, user_id),
    KEY idx_expires (expires_at),
    KEY idx_status (status)
) {$charset};

CREATE TABLE {$t_logs} (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    blog_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    user_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    service           VARCHAR(50) NOT NULL DEFAULT '',
    action            VARCHAR(100) NOT NULL DEFAULT '',
    request_summary   VARCHAR(500) NOT NULL DEFAULT '',
    response_status   VARCHAR(20) NOT NULL DEFAULT '',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_blog_user_svc (blog_id, user_id, service),
    KEY idx_created (created_at)
) {$charset};
";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_site_option( 'bzgoogle_db_version', BZGOOGLE_VERSION );
    }

    /**
     * Flush rewrite rules once.
     */
    private static function maybe_flush_rewrite() {
        BZGoogle_Google_OAuth::register_rewrite_rules();
        flush_rewrite_rules();
    }
}
