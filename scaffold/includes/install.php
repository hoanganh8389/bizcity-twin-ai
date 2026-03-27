<?php
/**
 * Database installation, migration, and seed data.
 *
 * @package BizCity_{Name}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─── Table names ─── */
function bz{prefix}_tables() {
    global $wpdb;
    return array(
        'items'   => $wpdb->prefix . 'bz{prefix}_items',
        'history' => $wpdb->prefix . 'bz{prefix}_history',
    );
}

/* ─── CREATE / UPDATE tables ─── */
function bz{prefix}_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $t       = bz{prefix}_tables();

    $sql = "CREATE TABLE {$t['items']} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug        VARCHAR(80)     NOT NULL DEFAULT '',
        name_vi     VARCHAR(255)    NOT NULL DEFAULT '',
        name_en     VARCHAR(255)    NOT NULL DEFAULT '',
        category    VARCHAR(50)     NOT NULL DEFAULT '',
        description TEXT,
        data_json   LONGTEXT,
        image_url   VARCHAR(500)    NOT NULL DEFAULT '',
        sort_order  SMALLINT        NOT NULL DEFAULT 0,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY category (category)
    ) $charset;

    CREATE TABLE {$t['history']} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     BIGINT UNSIGNED NULL,
        client_id   VARCHAR(100)    NOT NULL DEFAULT '',
        platform    VARCHAR(30)     NOT NULL DEFAULT '',
        session_id  VARCHAR(64)     NOT NULL DEFAULT '',
        topic       VARCHAR(255)    NOT NULL DEFAULT '',
        request_json LONGTEXT,
        result_json LONGTEXT,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY client_id (client_id),
        KEY created_at (created_at)
    ) $charset;";

    dbDelta( $sql );
    bz{prefix}_seed_defaults();
}

/* ─── Seed default data ─── */
function bz{prefix}_seed_defaults() {
    global $wpdb;
    $t = bz{prefix}_tables();

    if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['items']}" ) > 0 ) return;

    $defaults = bz{prefix}_get_default_items();
    foreach ( $defaults as $item ) {
        $wpdb->replace( $t['items'], $item );
    }
}

/* ─── Default items — CUSTOMIZE THIS ─── */
function bz{prefix}_get_default_items() {
    return array(
        // Example:
        // array(
        //     'slug'      => 'item-01',
        //     'name_vi'   => 'Mục đầu tiên',
        //     'name_en'   => 'First Item',
        //     'category'  => 'default',
        //     'sort_order' => 1,
        // ),
    );
}
