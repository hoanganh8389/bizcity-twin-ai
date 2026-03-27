<?php
/**
 * BizCity Tool Facebook — Database Install
 *
 * Creates tables for Facebook posting jobs and page connections.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function bztfb_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Facebook posting jobs table
    $table_jobs = $wpdb->prefix . 'bztfb_jobs';
    $sql_jobs = "CREATE TABLE {$table_jobs} (
        id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        session_id    VARCHAR(100) NOT NULL DEFAULT '',
        chat_id       VARCHAR(100) NOT NULL DEFAULT '',
        topic         TEXT NOT NULL,
        ai_title      TEXT,
        ai_content    LONGTEXT,
        image_url     TEXT,
        page_ids      TEXT COMMENT 'JSON array of target page IDs',
        fb_post_ids   TEXT COMMENT 'JSON array of resulting FB post IDs',
        wp_post_id    BIGINT(20) UNSIGNED DEFAULT NULL,
        status        VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|generating|posting|completed|failed',
        error_message TEXT,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at  DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_user    (user_id),
        KEY idx_status  (status),
        KEY idx_session (session_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_jobs );
}
