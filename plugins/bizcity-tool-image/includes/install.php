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

    /* ── Jobs table (existing) ── */
    $table_jobs = $wpdb->prefix . 'bztimg_jobs';
    $sql = "CREATE TABLE {$table_jobs} (
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

    /* ── Template categories ── */
    $table_cats = $wpdb->prefix . 'bztimg_template_categories';
    $sql .= "\nCREATE TABLE {$table_cats} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug        VARCHAR(50)     NOT NULL,
        name        VARCHAR(100)    NOT NULL,
        description TEXT,
        icon_emoji  VARCHAR(10)     NOT NULL DEFAULT '',
        icon_url    VARCHAR(500)    NOT NULL DEFAULT '',
        parent_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sort_order  INT             NOT NULL DEFAULT 0,
        status      VARCHAR(20)     NOT NULL DEFAULT 'active',
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug)
    ) $charset;";

    /* ── Templates ── */
    $table_tpl = $wpdb->prefix . 'bztimg_templates';
    $sql .= "\nCREATE TABLE {$table_tpl} (
        id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug               VARCHAR(100)    NOT NULL,
        category_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
        subcategory        VARCHAR(50)     NOT NULL DEFAULT '',
        title              VARCHAR(200)    NOT NULL,
        description        TEXT,
        thumbnail_url      VARCHAR(500)    NOT NULL DEFAULT '',
        badge_text         VARCHAR(50)     NOT NULL DEFAULT '',
        badge_color        VARCHAR(20)     NOT NULL DEFAULT '',
        tags               VARCHAR(500)    NOT NULL DEFAULT '',
        prompt_template    TEXT            NOT NULL,
        negative_prompt    TEXT,
        form_fields        LONGTEXT,
        recommended_model  VARCHAR(50)     NOT NULL DEFAULT 'flux-pro',
        recommended_size   VARCHAR(20)     NOT NULL DEFAULT '1024x1024',
        style              VARCHAR(30)     NOT NULL DEFAULT 'auto',
        num_outputs        INT             NOT NULL DEFAULT 1,
        version            VARCHAR(20)     NOT NULL DEFAULT '',
        extra_data         LONGTEXT,
        use_count          INT UNSIGNED    NOT NULL DEFAULT 0,
        is_featured        TINYINT(1)      NOT NULL DEFAULT 0,
        sort_order         INT             NOT NULL DEFAULT 0,
        status             VARCHAR(20)     NOT NULL DEFAULT 'active',
        author_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME        NULL,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY idx_category (category_id),
        KEY idx_status (status),
        KEY idx_featured (is_featured),
        KEY idx_sort (sort_order)
    ) $charset;";

    /* ── Projects (design editor saves) ── */
    $table_proj = $wpdb->prefix . 'bztimg_projects';
    $sql .= "\nCREATE TABLE {$table_proj} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     BIGINT UNSIGNED NOT NULL,
        title       VARCHAR(200)    NOT NULL DEFAULT '',
        data        LONGTEXT,
        status      VARCHAR(20)     NOT NULL DEFAULT 'active',
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME        NULL,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_status (status)
    ) $charset;";

    /* ── Compositions (multi-image collage) ── */
    $table_comp = $wpdb->prefix . 'bztimg_compositions';
    $sql .= "\nCREATE TABLE {$table_comp} (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       BIGINT UNSIGNED NOT NULL,
        title         VARCHAR(200)    NOT NULL DEFAULT '',
        layout_data   LONGTEXT,
        images        LONGTEXT,
        canvas_width  INT             NOT NULL DEFAULT 1024,
        canvas_height INT             NOT NULL DEFAULT 1024,
        output_url    VARCHAR(500)    NOT NULL DEFAULT '',
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status        VARCHAR(20)     NOT NULL DEFAULT 'draft',
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME        NULL,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_status (status)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    /* ── Editor Asset tables (shapes, frames, fonts, text-presets) ── */
    bztimg_install_editor_asset_tables();

    /* ── Seed default categories (only if empty) ── */
    bztimg_seed_categories();

    /* ── Seed default templates (only if empty) ── */
    if ( function_exists( 'bztimg_seed_templates' ) ) {
        bztimg_seed_templates();
    }

    /* ── Seed templates from data/*.json files ── */
    if ( function_exists( 'bztimg_seed_json_templates' ) ) {
        bztimg_seed_json_templates();
    }
}

/**
 * Seed default template categories.
 */
function bztimg_seed_categories() {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_template_categories';

    $categories = array(
        array( 'background',       'Thay Background',       '🖼️', 1 ),
        array( 'on-hand',          'Trên Tay Sản Phẩm',     '🤲', 2 ),
        array( 'concepts',         'AI Concept',             '💡', 3 ),
        array( 'ai-model',         'AI Model Studio',        '👤', 4 ),
        array( 'apparel-tryon',    'Thử Đồ',                '👕', 5 ),
        array( 'accessory-tryon',  'Thử Phụ Kiện',           '💍', 6 ),
        array( 'mockup',           'Mockup',                 '📐', 7 ),
        array( 'packaging',        'Bao Bì',                '📦', 8 ),
        array( 'social-media',     'Social Media',           '📱', 9 ),
        array( 'portrait',         'Chân Dung',              '🧑', 10 ),
        array( 'branding',         'Thương Hiệu',            '🏷️', 11 ),
        /* Phase 3.7 — editor AI tools */
        array( 'remove-bg',        'Xóa Nền Ảnh',            '✂️', 12 ),
        array( 'face-swap',        'Thay Khuôn Mặt',         '🎭', 13 ),
        array( 'style-transfer',   'Chuyển Phong Cách',      '🎨', 14 ),
        array( 'upscale',          'Phóng To / Nét Hơn',     '🔍', 15 ),
    );

    foreach ( $categories as $cat ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE slug = %s", $cat[0]
        ) );
        if ( ! $exists ) {
            $wpdb->insert( $table, array(
                'slug'       => $cat[0],
                'name'       => $cat[1],
                'icon_emoji' => $cat[2],
                'sort_order' => $cat[3],
                'status'     => 'active',
            ), array( '%s', '%s', '%s', '%d', '%s' ) );
        }
    }
}

/**
 * Create DB tables for design-editor assets: shapes, frames, fonts, text_presets, templates.
 * Marketplace-ready: attachment_id for WP Media Library thumbnails.
 *
 * NOTE on width/height columns:
 *   These store THUMBNAIL dimensions (typically 256×256), matching the mock-api format.
 *   Actual canvas dimensions are embedded inside data_json (RootLayer → boxSize).
 *   This is by design — canva-editor uses width/height only for thumbnail display.
 */
function bztimg_install_editor_asset_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    /* ── Shapes ── */
    $sql = "CREATE TABLE {$wpdb->prefix}bztimg_editor_shapes (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        clip_path     TEXT            NOT NULL,
        description   VARCHAR(500)    NOT NULL DEFAULT '',
        background    VARCHAR(50)     NOT NULL DEFAULT 'rgb(0,0,0)',
        width         INT UNSIGNED    NOT NULL DEFAULT 256,
        height        INT UNSIGNED    NOT NULL DEFAULT 256,
        img_url       VARCHAR(500)    NOT NULL DEFAULT '',
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        content_hash  VARCHAR(32)     NOT NULL DEFAULT '',
        sort_order    INT             NOT NULL DEFAULT 0,
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_hash (content_hash),
        FULLTEXT KEY ft_desc (description)
    ) $charset;";

    /* ── Frames ── */
    $sql .= "\nCREATE TABLE {$wpdb->prefix}bztimg_editor_frames (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        clip_path     TEXT            NOT NULL,
        description   VARCHAR(500)    NOT NULL DEFAULT '',
        width         INT UNSIGNED    NOT NULL DEFAULT 256,
        height        INT UNSIGNED    NOT NULL DEFAULT 256,
        img_url       VARCHAR(500)    NOT NULL DEFAULT '',
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        content_hash  VARCHAR(32)     NOT NULL DEFAULT '',
        sort_order    INT             NOT NULL DEFAULT 0,
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_hash (content_hash),
        FULLTEXT KEY ft_desc (description)
    ) $charset;";

    /* ── Fonts ── */
    $sql .= "\nCREATE TABLE {$wpdb->prefix}bztimg_editor_fonts (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        family      VARCHAR(200)    NOT NULL,
        styles_json LONGTEXT        NOT NULL,
        sort_order  INT             NOT NULL DEFAULT 0,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_family (family)
    ) $charset;";

    /* ── Text presets ── */
    $sql .= "\nCREATE TABLE {$wpdb->prefix}bztimg_editor_text_presets (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        description   VARCHAR(500)    NOT NULL DEFAULT '',
        data_json     LONGTEXT        NOT NULL,
        width         INT UNSIGNED    NOT NULL DEFAULT 256,
        height        INT UNSIGNED    NOT NULL DEFAULT 256,
        img_url       VARCHAR(500)    NOT NULL DEFAULT '',
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        content_hash  VARCHAR(32)     NOT NULL DEFAULT '',
        sort_order    INT             NOT NULL DEFAULT 0,
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_hash (content_hash),
        FULLTEXT KEY ft_desc (description)
    ) $charset;";

    /* ── Editor Templates (canva-editor design templates — separate from AI prompt templates) ── */
    $sql .= "\nCREATE TABLE {$wpdb->prefix}bztimg_editor_templates (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        description   VARCHAR(500)    NOT NULL DEFAULT '',
        data_json     LONGTEXT        NOT NULL,
        pages         INT UNSIGNED    NOT NULL DEFAULT 1,
        width         INT UNSIGNED    NOT NULL DEFAULT 256,
        height        INT UNSIGNED    NOT NULL DEFAULT 256,
        img_url       VARCHAR(500)    NOT NULL DEFAULT '',
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        content_hash  VARCHAR(32)     NOT NULL DEFAULT '',
        sort_order    INT             NOT NULL DEFAULT 0,
        created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_hash (content_hash),
        FULLTEXT KEY ft_desc (description)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
