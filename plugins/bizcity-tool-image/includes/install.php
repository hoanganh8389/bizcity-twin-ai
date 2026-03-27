<?php
/**
 * BizCity Tool Image — DB Install & Migration
 *
 * @package BizCity_Tool_Image
 * @since   2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function bztimg_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'bztimg_jobs';

    $sql = "CREATE TABLE {$table} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
        prompt          TEXT            NOT NULL,
        model           VARCHAR(50)     NOT NULL DEFAULT 'flux-pro',
        size            VARCHAR(20)     NOT NULL DEFAULT '1024x1024',
        style           VARCHAR(30)     NOT NULL DEFAULT 'auto',
        ref_image       TEXT,
        status          VARCHAR(20)     NOT NULL DEFAULT 'processing',
        image_url       TEXT,
        attachment_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        error_message   TEXT,
        session_id      VARCHAR(100)    NOT NULL DEFAULT '',
        chat_id         VARCHAR(100)    NOT NULL DEFAULT '',
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME        NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY status (status),
        KEY model (model),
        KEY created_at (created_at)
    ) $charset;";

    dbDelta( $sql );
}
