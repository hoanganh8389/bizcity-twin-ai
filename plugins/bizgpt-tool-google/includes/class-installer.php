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
        $sql_accounts = "
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
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /* Suppress expected DESCRIBE errors from dbDelta — tables don't exist
           yet on first install, so DESCRIBE fails before CREATE TABLE runs.
           Also suppress on the global DB handle (BizCity sharding router). */
        $old_suppress = $wpdb->suppress_errors( true );
        if ( isset( $wpdb->gwpdb ) && $wpdb->gwpdb instanceof wpdb ) {
            $wpdb->gwpdb->suppress_errors( true );
        } elseif ( method_exists( $wpdb, 'biz_ensure_gwpdb' ) ) {
            $gw = $wpdb->biz_ensure_gwpdb();
            if ( $gw ) $gw->suppress_errors( true );
        }

        dbDelta( $sql_accounts );

        /* Restore error reporting */
        $wpdb->suppress_errors( $old_suppress );
        if ( isset( $wpdb->gwpdb ) && $wpdb->gwpdb instanceof wpdb ) {
            $wpdb->gwpdb->suppress_errors( false );
        }

        // [2026-08-28 Johnny Chu] PHASE-1.30-LIFECYCLE — refresh metadata cache keys only for tables that were eligible for DDL.
        if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
            bizcity_tbl_invalidate( $t_accounts );
        }

        update_site_option( 'bzgoogle_db_version', BZGOOGLE_VERSION );
    }

    /**
     * Self-healing: ensure tables exist at runtime.
     * Called on plugins_loaded — handles cases where register_activation_hook
     * didn't fire (e.g. plugin activated via marketplace AJAX).
     */
    public static function maybe_create_tables() {
        // Quick check: if version option matches, tables are already created
        if ( get_site_option( 'bzgoogle_db_version' ) === BZGOOGLE_VERSION ) {
            return;
        }
        self::create_tables();
    }

    /**
     * Flush rewrite rules once.
     */
    private static function maybe_flush_rewrite() {
        BZGoogle_Google_OAuth::register_rewrite_rules();
        flush_rewrite_rules();
    }
}
