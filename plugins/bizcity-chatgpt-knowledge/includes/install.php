<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Table name helper.
 */
function bzck_tables() {
    global $wpdb;
    return [
        'search_history' => $wpdb->prefix . 'bzck_search_history',
        'bookmarks'      => $wpdb->prefix . 'bzck_bookmarks',
    ];
}

/**
 * Install / upgrade DB tables.
 */
function bzck_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $t       = bzck_tables();

    $sql = "CREATE TABLE {$t['search_history']} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
        query_text      VARCHAR(500)    NOT NULL DEFAULT '',
        answer_text     LONGTEXT,
        provider        VARCHAR(30)     NOT NULL DEFAULT 'chatgpt',
        model_used      VARCHAR(120)    NOT NULL DEFAULT '',
        tokens_prompt   INT UNSIGNED    NOT NULL DEFAULT 0,
        tokens_reply    INT UNSIGNED    NOT NULL DEFAULT 0,
        is_success      TINYINT(1)      NOT NULL DEFAULT 1,
        source          VARCHAR(30)     NOT NULL DEFAULT 'pipeline',
        category        VARCHAR(60)     NOT NULL DEFAULT '',
        rating          TINYINT         NULL,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY provider (provider),
        KEY created_at (created_at)
    ) $charset;

    CREATE TABLE {$t['bookmarks']} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
        query_text      VARCHAR(500)    NOT NULL DEFAULT '',
        answer_text     LONGTEXT,
        model_used      VARCHAR(120)    NOT NULL DEFAULT '',
        tags            VARCHAR(255)    NOT NULL DEFAULT '',
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id)
    ) $charset;";

    dbDelta( $sql );
}
