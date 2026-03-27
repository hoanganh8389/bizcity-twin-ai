<?php
/**
 * BizCity Tool WooCommerce — Database Install
 *
 * Creates tables for prompt/action history.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function bztw_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $table = $wpdb->prefix . 'bztw_prompt_history';
    $sql = "CREATE TABLE {$table} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        goal        VARCHAR(50)  NOT NULL DEFAULT 'create_product',
        prompt      TEXT         NOT NULL,
        ai_title    TEXT,
        ai_content  LONGTEXT,
        product_id  BIGINT(20) UNSIGNED DEFAULT NULL,
        product_url TEXT,
        image_url   TEXT,
        status      VARCHAR(20) NOT NULL DEFAULT 'completed',
        created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user   (user_id),
        KEY idx_goal   (goal),
        KEY idx_created (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
