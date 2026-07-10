# BizCity Plugin Standard — Chuẩn Phát Triển Plugin Mở Rộng

> **Mục tiêu**: Chuẩn bị kiến trúc cho 1000+ plugin mở rộng mà **KHÔNG chỉnh sửa lõi**.
> Mỗi plugin = 1 **AI Agent chuyên biệt** trong AI Agentic Team.
> Khi plugin active → Agent auto-available. Khi deactivate → Agent auto-removed.
>
> Xem kiến trúc tổng thể: [ARCHITECTURE.md](ARCHITECTURE.md)

---

## Mục lục

1. [Tổng quan kiến trúc](#1-tổng-quan-kiến-trúc)
2. [Cấu trúc thư mục chuẩn](#2-cấu-trúc-thư-mục-chuẩn)
3. [Main Plugin File — Bootstrap](#3-main-plugin-file--bootstrap)
4. [Module 1: Database (install.php)](#4-module-1-database--installphp)
5. [Module 2: Topics / Data Registry](#5-module-2-topics--data-registry)
6. [Module 3: Admin Menu & Dashboard](#6-module-3-admin-menu--dashboard)
7. [Module 4: AJAX Endpoints](#7-module-4-ajax-endpoints)
8. [Module 5: Shortcode & Frontend](#8-module-5-shortcode--frontend)
9. [Module 6: Template Page](#9-module-6-template-page)
10. [Module 7: Chat Integration](#10-module-7-chat-integration)
11. [Module 8: Intent Provider (AI Skill)](#11-module-8-intent-provider--ai-skill)
12. [Module 9: Knowledge Binding (RAG Context)](#12-module-9-knowledge-binding--rag-context)
13. [Naming Convention](#13-naming-convention)
14. [Checklist khi tạo plugin mới](#14-checklist-khi-tạo-plugin-mới)
15. [Ví dụ thực tế: bizcity-tarot](#15-ví-dụ-thực-tế-bizcity-tarot)
16. [Ý tưởng plugin tương lai](#16-ý-tưởng-plugin-tương-lai)

---

## 1. Tổng quan kiến trúc

```
┌─────────────────────────────────────────────────────┐
│                    AI AGENT ENGINE                    │
│              (bizcity-intent — MU Plugin)             │
│                                                       │
│  Router ← goal_patterns    Planner ← plans            │
│  Tool Registry ← tools     AI Compose ← context       │
│                                                       │
│  ┌──── Provider Registry ────────────────────────┐   │
│  │  Provider A (tarot)     → tarot_reading        │   │
│  │  Provider B (bizcoach)  → astro_forecast        │   │
│  │  Provider C (admin)     → create_product ...    │   │
│  │  Provider D (????)      → ???? (tương lai)      │   │
│  └────────────────────────────────────────────────┘   │
└───────────────────────┬─────────────────────────────┘
                        │
         ┌──────────────┼──────────────┐
         ▼              ▼              ▼
    ┌─────────┐   ┌──────────┐   ┌─────────┐
    │  Tarot  │   │ BizCoach │   │  Plugin  │  ← Regular plugins
    │  Plugin │   │   Map    │   │   ???    │     (wp-content/plugins/)
    │         │   │          │   │          │
    │ ✅ DB   │   │ ✅ DB    │   │ ✅ DB    │
    │ ✅ Admin│   │ ✅ Admin │   │ ✅ Admin │
    │ ✅ AJAX │   │ ✅ AJAX  │   │ ✅ AJAX  │
    │ ✅ Short│   │ ✅ Short │   │ ✅ Short │  ← Shortcode [bizcity_xxx]
    │ ✅ Templ│   │ ✅ Templ │   │ ✅ Templ │  ← Template page
    │ ✅ Chat │   │ ✅ Chat  │   │ ✅ Chat  │  ← Chat integration
    │ ✅ Prov │   │ ✅ Prov  │   │ ✅ Prov  │  ← Intent Provider
    └─────────┘   └──────────┘   └─────────┘
```

### Nguyên tắc cốt lõi (Agentic)

| Nguyên tắc | Mô tả |
|------------|-------|
| **Zero Core Touch** | Plugin mới KHÔNG sửa bất kỳ file nào trong bizcity-intent |
| **Self-contained** | Mỗi Agent tự quản lý: DB, Admin, Assets, AJAX, Frontend, Knowledge |
| **Plug-and-Play** | Active plugin = AI Agent ready; Deactivate = Agent removed |
| **Specialized** | Mỗi Agent giỏi 1 lĩnh vực cụ thể, có context riêng |
| **Resource-efficient** | Chỉ load context của Agent liên quan, không load toàn bộ |
| **Convention over Config** | Tuân theo naming convention, cấu trúc thư mục chuẩn |
| **Progressive Enhancement** | Intent Provider + Knowledge Binding là OPTIONAL |

> **Mô hình Agentic**: 1 Blog = Team Leader • 1 Plugin = Agent • Intent Engine = Dispatcher
> Xem chi tiết: [ARCHITECTURE.md](ARCHITECTURE.md)

---

## 2. Cấu trúc thư mục chuẩn

```
bizcity-{slug}/
├── bizcity-{slug}.php              ← Main bootstrap file
├── index.php                        ← Security: "Silence is golden"
├── README.md                        ← Plugin documentation
│
├── assets/                          ← CSS, JS, images
│   ├── index.php
│   ├── admin.css                    ← Admin dashboard styles
│   ├── admin.js                     ← Admin dashboard scripts
│   ├── public.css                   ← Frontend/shortcode styles
│   ├── public.js                    ← Frontend/shortcode scripts
│   └── {feature}.css                ← Feature-specific styles (optional)
│
├── includes/                        ← PHP logic files
│   ├── index.php
│   ├── install.php                  ← DB tables + seed data + migration
│   ├── topics.php                   ← Data registry (topics, categories, configs)
│   ├── admin-menu.php               ← WP Admin menu registration
│   ├── admin-{feature}.php          ← Admin sub-pages (dashboard, settings, ...)
│   ├── ajax.php                     ← All AJAX handlers (admin + public)
│   ├── shortcode.php                ← [bizcity_{slug}] shortcode renderer
│   ├── integration-chat.php         ← Chat gateway hooks (intent filter, push results)
│   ├── class-intent-provider.php    ← Intent Provider (AI skill registration)
│   └── knowledge-binding.php        ← Knowledge ↔ Plugin linkage (RAG context)
│
└── views/                           ← Template files
    ├── index.php
    ├── page-{slug}-full.php         ← Full-page WP template
    └── {slug}-landing.php           ← Landing page template registration
```

### Security files

Mỗi thư mục PHẢI có `index.php`:
```php
<?php // Silence is golden.
```

---

## 3. Main Plugin File — Bootstrap

File: `bizcity-{slug}/bizcity-{slug}.php`

```php
<?php
/*
Plugin Name: BizCity {Name} – {Subtitle}
Description: {Mô tả ngắn gọn chức năng plugin}
Version: 1.0.0
Author: Chu Hoàng Anh
Author URI: https://bizcity.vn
Text Domain: bizcity-{slug}
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─── CONSTANTS ─── */
define( 'BZ{PREFIX}_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZ{PREFIX}_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZ{PREFIX}_VERSION', '1.0.0' );
define( 'BZ{PREFIX}_SLUG',    'bizcity-{slug}' );

/* ─── AUTOLOAD INCLUDES ─── */
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

require_once BZ{PREFIX}_DIR . 'includes/install.php';
require_once BZ{PREFIX}_DIR . 'includes/topics.php';
require_once BZ{PREFIX}_DIR . 'includes/admin-menu.php';
require_once BZ{PREFIX}_DIR . 'includes/admin-dashboard.php';
require_once BZ{PREFIX}_DIR . 'includes/ajax.php';
require_once BZ{PREFIX}_DIR . 'includes/shortcode.php';
require_once BZ{PREFIX}_DIR . 'includes/integration-chat.php';
require_once BZ{PREFIX}_DIR . 'views/{slug}-landing.php';

/* ─── INTENT PROVIDER (conditional — chỉ khi Intent Engine active) ─── */
if ( class_exists( 'BizCity_Intent_Provider' ) ) {
    require_once BZ{PREFIX}_DIR . 'includes/class-intent-provider.php';
    add_action( 'bizcity_intent_register_providers', function( $registry ) {
        $registry->register( new BizCity_{Name}_Intent_Provider() );
    } );
}

/* ─── ACTIVATION / DEACTIVATION ─── */
register_activation_hook( __FILE__, 'bz{prefix}_activate' );
function bz{prefix}_activate() {
    bz{prefix}_install_tables();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'bz{prefix}_deactivate' );
function bz{prefix}_deactivate() {
    flush_rewrite_rules();
}

/* ─── DB MIGRATION (runs once per version bump) ─── */
add_action( 'admin_init', 'bz{prefix}_maybe_migrate_db' );
function bz{prefix}_maybe_migrate_db() {
    if ( get_option( 'bz{prefix}_db_version' ) === BZ{PREFIX}_VERSION ) return;
    // ... migration logic ...
    update_option( 'bz{prefix}_db_version', BZ{PREFIX}_VERSION );
}

/* ─── REGISTER ASSETS ─── */
add_action( 'init', 'bz{prefix}_register_assets' );
function bz{prefix}_register_assets() {
    wp_register_style(  'bz{prefix}-public',  BZ{PREFIX}_URL . 'assets/public.css',  [], BZ{PREFIX}_VERSION );
    wp_register_script( 'bz{prefix}-public',  BZ{PREFIX}_URL . 'assets/public.js',   ['jquery'], BZ{PREFIX}_VERSION, true );
    wp_register_style(  'bz{prefix}-admin',   BZ{PREFIX}_URL . 'assets/admin.css',   [], BZ{PREFIX}_VERSION );
    wp_register_script( 'bz{prefix}-admin',   BZ{PREFIX}_URL . 'assets/admin.js',    ['jquery'], BZ{PREFIX}_VERSION, true );
}

/* ─── ENQUEUE ADMIN ASSETS ─── */
add_action( 'admin_enqueue_scripts', 'bz{prefix}_enqueue_admin' );
function bz{prefix}_enqueue_admin( $hook ) {
    if ( strpos( $hook, BZ{PREFIX}_SLUG ) === false ) return;
    wp_enqueue_style( 'bz{prefix}-admin' );
    wp_enqueue_script( 'bz{prefix}-admin' );
    wp_localize_script( 'bz{prefix}-admin', 'BZ{PREFIX}', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bz{prefix}_nonce' ),
    ] );
}
```

### Quy tắc quan trọng

| Quy tắc | Mô tả |
|---------|-------|
| `class_exists` guard | Intent Provider chỉ load khi Intent Engine đã active |
| `register_activation_hook` | Tạo DB tables khi activate |
| `admin_init` migration | Auto-migrate DB khi version bump |
| `init` asset registration | Register (KHÔNG enqueue) assets |
| Prefix riêng biệt | Mỗi plugin dùng prefix duy nhất (`bct_`, `bccm_`, `bz{xxx}_`) |

---

## 4. Module 1: Database — install.php

```php
<?php
/* ─── Table name helper ─── */
function bz{prefix}_tables() {
    global $wpdb;
    return [
        'items'    => $wpdb->prefix . 'bz{prefix}_items',      // Data chính
        'history'  => $wpdb->prefix . 'bz{prefix}_history',    // Lịch sử sử dụng
    ];
}

/* ─── CREATE TABLE ─── */
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

/* ─── Seed dữ liệu mặc định ─── */
function bz{prefix}_seed_defaults() {
    global $wpdb;
    $t = bz{prefix}_tables();
    if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['items']}" ) > 0 ) return;

    $defaults = bz{prefix}_get_default_items();
    foreach ( $defaults as $item ) {
        $wpdb->replace( $t['items'], $item );
    }
}
```

### Chuẩn DB Tables

| Table | Mục đích | Bắt buộc |
|-------|---------|----------|
| `bz{prefix}_items` | Dữ liệu chính của plugin (cards, stones, runes, ...) | ✅ |
| `bz{prefix}_history` | Lịch sử user sử dụng (readings, sessions, ...) | ✅ |
| `bz{prefix}_settings` | Cài đặt tùy chỉnh (nếu cần nhiều hơn wp_options) | Optional |

### Chuẩn Columns chung

| Column | Type | Mọi table đều có |
|--------|------|-------------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | ✅ PK |
| `user_id` | BIGINT UNSIGNED NULL | ✅ (table history) |
| `client_id` | VARCHAR(100) | ✅ (cho Zalo/Telegram/external) |
| `platform` | VARCHAR(30) | ✅ (WEBCHAT/ADMINCHAT/ZALO_BOT/...) |
| `session_id` | VARCHAR(64) | ✅ (liên kết với chat session) |
| `created_at` | DATETIME DEFAULT CURRENT_TIMESTAMP | ✅ |

---

## 5. Module 2: Topics / Data Registry

File: `includes/topics.php`

```php
<?php
/**
 * Data registry — chủ đề, categories, và câu hỏi gợi ý.
 * Dữ liệu tĩnh dùng cho frontend selector + intent matching.
 */

function bz{prefix}_get_topics() {
    return [
        [
            'value'     => 'Tình cảm',
            'label'     => 'Tình cảm',
            'icon'      => '💕',
            'category'  => 'tinh_yeu',
            'questions' => [
                'Tình cảm hiện tại của tôi thế nào?',
                'Mối quan hệ sẽ phát triển ra sao?',
            ],
        ],
        // ... thêm topics ...
    ];
}

function bz{prefix}_get_topic_categories() {
    return [
        'tinh_yeu'  => [ 'label' => 'Tình yêu',           'icon' => '❤️' ],
        'tai_chinh' => [ 'label' => 'Tài chính',           'icon' => '💰' ],
        'cong_viec' => [ 'label' => 'Công việc & Đối tác', 'icon' => '💼' ],
        'suc_khoe'  => [ 'label' => 'Sức khỏe',           'icon' => '🌿' ],
        'ban_than'  => [ 'label' => 'Bản thân',            'icon' => '🌟' ],
    ];
}
```

### Chuẩn Topic Structure

```
topic = {
    value:     string   // Unique identifier & display value
    label:     string   // Display label (có thể khác value)
    icon:      string   // Emoji icon
    category:  string   // Category key (snake_case)
    questions: string[] // Gợi ý câu hỏi cho user
}

category = {
    [slug]: {
        label: string   // Display name
        icon:  string   // Emoji
    }
}
```

---

## 6. Module 3: Admin Menu & Dashboard

File: `includes/admin-menu.php`

```php
<?php
add_action( 'admin_menu', 'bz{prefix}_register_admin_menu' );
function bz{prefix}_register_admin_menu() {
    // Top-level menu
    add_menu_page(
        'BizCity {Name}',           // page_title
        '{Icon} {Short Name}',      // menu_title
        'manage_options',            // capability
        'bizcity-{slug}',           // menu_slug
        'bz{prefix}_page_dashboard', // callback
        'dashicons-{icon}',         // icon
        58                           // position (58-69 range cho BizCity plugins)
    );

    // Default submenu (same slug = replaces "parent" label)
    add_submenu_page( 'bizcity-{slug}', '{Name}', '📊 Dashboard', 'manage_options', 'bizcity-{slug}', 'bz{prefix}_page_dashboard' );

    // Feature submenus
    add_submenu_page( 'bizcity-{slug}', 'Quản lý dữ liệu', '🗂️ Dữ liệu', 'manage_options', 'bizcity-{slug}-data', 'bz{prefix}_page_data' );
    add_submenu_page( 'bizcity-{slug}', 'Lịch sử', '📜 Lịch sử', 'manage_options', 'bizcity-{slug}-history', 'bz{prefix}_page_history' );
    add_submenu_page( 'bizcity-{slug}', 'Cài đặt', '⚙️ Cài đặt', 'manage_options', 'bizcity-{slug}-settings', 'bz{prefix}_page_settings' );
}
```

### Chuẩn Admin Menu

| # | Submenu | Slug | Chức năng |
|---|---------|------|-----------|
| 1 | 📊 Dashboard | `bizcity-{slug}` | Tổng quan, thống kê |
| 2 | 🗂️ Dữ liệu | `bizcity-{slug}-data` | CRUD dữ liệu chính (cards, items, ...) |
| 3 | 📜 Lịch sử | `bizcity-{slug}-history` | Lịch sử sử dụng |
| 4 | ⚙️ Cài đặt | `bizcity-{slug}-settings` | Options, config |

---

## 7. Module 4: AJAX Endpoints

File: `includes/ajax.php`

```php
<?php
/**
 * AJAX Endpoints — Public + Admin
 *
 * Naming convention: bz{prefix}_ajax_{action}
 * AJAX action name:  bz{prefix}_{action}
 */

// ── Admin-only endpoints ──
add_action( 'wp_ajax_bz{prefix}_import', 'bz{prefix}_ajax_import' );

// ── Public endpoints (cả logged-in và logged-out) ──
add_action( 'wp_ajax_bz{prefix}_get_items',        'bz{prefix}_ajax_get_items' );
add_action( 'wp_ajax_nopriv_bz{prefix}_get_items', 'bz{prefix}_ajax_get_items' );

add_action( 'wp_ajax_bz{prefix}_get_detail',        'bz{prefix}_ajax_get_detail' );
add_action( 'wp_ajax_nopriv_bz{prefix}_get_detail', 'bz{prefix}_ajax_get_detail' );

add_action( 'wp_ajax_bz{prefix}_save_result',        'bz{prefix}_ajax_save_result' );
add_action( 'wp_ajax_nopriv_bz{prefix}_save_result', 'bz{prefix}_ajax_save_result' );

add_action( 'wp_ajax_bz{prefix}_ai_interpret',        'bz{prefix}_ajax_ai_interpret' );
add_action( 'wp_ajax_nopriv_bz{prefix}_ai_interpret', 'bz{prefix}_ajax_ai_interpret' );

/* ─── Handler pattern ─── */
function bz{prefix}_ajax_get_items() {
    check_ajax_referer( 'bz{prefix}_pub_nonce', 'nonce' );

    global $wpdb;
    $t = bz{prefix}_tables();
    $items = $wpdb->get_results(
        "SELECT id, slug, name_vi, name_en, category, image_url FROM {$t['items']} ORDER BY sort_order",
        ARRAY_A
    );

    wp_send_json_success( $items );
}
```

### Chuẩn AJAX Endpoint

| Loại | Pattern | Nonce | Scope |
|------|---------|-------|-------|
| Admin-only | `wp_ajax_{action}` | `bz{prefix}_nonce` + `manage_options` cap | Import, crawl, delete |
| Public read | `wp_ajax_` + `wp_ajax_nopriv_` | `bz{prefix}_pub_nonce` | Get items, get detail |
| Public write | `wp_ajax_` + `wp_ajax_nopriv_` | `bz{prefix}_pub_nonce` | Save result, AI interpret |
| Chat integration | `wp_ajax_` + `wp_ajax_nopriv_` | `bz{prefix}_pub_nonce` | Push result to chat |

### Chuẩn Response

```php
// Success
wp_send_json_success( [ 'items' => [...], 'total' => 78 ] );

// Error
wp_send_json_error( [ 'message' => 'Lỗi: ...' ] );
```

---

## 8. Module 5: Shortcode & Frontend

File: `includes/shortcode.php`

```php
<?php
add_shortcode( 'bizcity_{slug}', 'bz{prefix}_shortcode' );

function bz{prefix}_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'items_count'    => get_option( 'bz{prefix}_items_count', 3 ),
        'show_topics'    => 1,
        'show_questions' => 1,
    ], $atts, 'bizcity_{slug}' );

    // 1. Enqueue assets
    wp_enqueue_style( 'bz{prefix}-public' );
    wp_enqueue_script( 'bz{prefix}-public' );

    // 2. Localize JS
    wp_localize_script( 'bz{prefix}-public', 'BZ{PREFIX}_PUB', [
        'ajax_url'     => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'bz{prefix}_pub_nonce' ),
        'items_count'  => (int) $atts['items_count'],
        'is_logged'    => is_user_logged_in() ? 1 : 0,
        'site_url'     => get_site_url(),
        // Chat context (from secure token URL)
        'token'        => sanitize_text_field( $_GET['bz{prefix}_token'] ?? '' ),
        'has_chat_ctx' => ! empty( $_GET['bz{prefix}_token'] ) ? 1 : 0,
    ] );

    // 3. Query data
    global $wpdb;
    $t = bz{prefix}_tables();
    $items = $wpdb->get_results( "SELECT * FROM {$t['items']} ORDER BY sort_order", ARRAY_A );

    // 4. Render
    ob_start();
    ?>
    <div class="bz{prefix}-app" data-count="<?php echo esc_attr( $atts['items_count'] ); ?>">
        <!-- Topic selector -->
        <!-- Items grid -->
        <!-- Result panel -->
        <!-- AI interpretation panel -->
    </div>

    <!-- Data for JS -->
    <script type="application/json" id="bz{prefix}-topics-data">
        <?php echo wp_json_encode( bz{prefix}_get_topics() ); ?>
    </script>
    <?php
    return ob_get_clean();
}
```

### Chuẩn Shortcode

| Attribute | Type | Mô tả |
|-----------|------|-------|
| `items_count` | int | Số lượng items chọn (default từ option) |
| `show_topics` | 0\|1 | Hiện topic selector |
| `show_questions` | 0\|1 | Hiện câu hỏi gợi ý |

### Chuẩn JS Localize (`BZ{PREFIX}_PUB`)

| Key | Type | Mô tả |
|-----|------|-------|
| `ajax_url` | string | WP AJAX URL |
| `nonce` | string | Public nonce |
| `items_count` | int | Items to pick |
| `is_logged` | 0\|1 | User logged-in state |
| `site_url` | string | Site URL |
| `token` | string | Secure chat token |
| `has_chat_ctx` | 0\|1 | Whether opened from chat |

---

## 9. Module 6: Template Page

File: `views/{slug}-landing.php`

```php
<?php
/**
 * Template page registration — cho phép chọn template từ Page Attributes.
 */

// 1. Add template to Page Attributes dropdown
add_filter( 'theme_page_templates', function( $templates ) {
    $templates['bz{prefix}-landing'] = '{Plugin Name} (BizCity)';
    return $templates;
} );

// 2. Route to custom template file
add_filter( 'template_include', function( $template ) {
    if ( is_page() ) {
        $page_template = get_post_meta( get_the_ID(), '_wp_page_template', true );
        if ( 'bz{prefix}-landing' === $page_template ) {
            $custom = BZ{PREFIX}_DIR . 'views/page-{slug}-full.php';
            if ( file_exists( $custom ) ) return $custom;
        }
    }
    return $template;
} );
```

File: `views/page-{slug}-full.php`

```php
<?php get_header(); ?>
<main class="bz{prefix}-page-wrapper">
    <?php echo do_shortcode( '[bizcity_{slug}]' ); ?>
</main>
<?php get_footer(); ?>
```

### Chuẩn Template Page

| Item | Convention |
|------|-----------|
| Template slug | `bz{prefix}-landing` |
| Template display name | `{Name} (BizCity)` |
| Template file | `views/page-{slug}-full.php` |
| Rendering | Wraps `get_header()` + shortcode + `get_footer()` |

### Auto-create page (optional)

```php
add_action( 'admin_init', function() {
    if ( get_option( 'bz{prefix}_page_checked' ) ) return;
    update_option( 'bz{prefix}_page_checked', 1 );

    // Check if page with shortcode already exists
    global $wpdb;
    $exists = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%[bizcity_{slug}]%' AND post_status = 'publish' LIMIT 1" );
    if ( $exists ) return;

    // Create page
    wp_insert_post( [
        'post_title'   => '{Plugin Name}',
        'post_content' => '[bizcity_{slug}]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'meta_input'   => [ '_wp_page_template' => 'bz{prefix}-landing' ],
    ] );
} );
```

---

## 10. Module 7: Chat Integration

File: `includes/integration-chat.php`

### Vai trò

| Chức năng | Hook | Priority |
|-----------|------|----------|
| Intent detection (Zalo/Bot) | `bizcity_unified_message_intent` filter | 10 |
| Intent detection (Webchat) | `bizcity_chat_pre_ai_response` filter | 10 |
| Push results back to chat | `wp_ajax_bz{prefix}_push_result` | - |
| Settings fields | `bz{prefix}_settings_fields` action | 10 |

### ⚠️ BẮT BUỘC: Enriched Context trong Intent Filter (v3.1)

Filter `bizcity_unified_message_intent` cung cấp **enriched context** bao gồm:

| Key | Type | Mô tả |
|-----|------|--------|
| `message` | string | Tin nhắn hiện tại |
| `chat_id` | string | ID cuộc hội thoại |
| `wp_user_id` | int | WP User ID (0 nếu chưa login) |
| `client_id` | string | Client ID (Zalo/Telegram) |
| `platform` | string | ZALO_PERSONAL, ZALO_BOT, FACEBOOK, ... |
| `blog_id` | int | Blog ID (multisite) |
| `session_id` | string | Chat session ID |
| `attachment_url` | string | URL attachment gốc |
| `attachment_type` | string | image, file, sticker, ... |
| **`image_url`** | string | **URL ảnh đã xử lý (từ transient hoặc attachment)** |
| **`recent_context`** | string | **Ngữ cảnh hội thoại gần đây (30phút/100 tin)** |

> `recent_context` được build bởi hàm `bizgpt_build_cross_path_context()` cho Zalo/Bot/FB.
> Gồm: User Memory + Profile Context + Lịch sử chat gần đây.
> Webchat có riêng session context qua 6-Layer Dual Context Chain.

Plugin **PHẢI** sử dụng `recent_context` khi gọi AI để đảm bảo:
- AI hiểu đúng ngữ cảnh (đang nói về gì trước đó)
- Không hỏi lại thông tin đã cung cấp
- Giữ giọng điệu nhất quán xuyên suốt cuộc hội thoại

### Pattern 1: Native Processing (KHUYẾN KHÍCH)

Xử lý trực tiếp trên Zalo/Bot — không cần redirect link:

```php
add_filter( 'bizcity_unified_message_intent', 'bz{prefix}_intent_filter', 10, 2 );
function bz{prefix}_intent_filter( $handled, $ctx ) {
    if ( $handled ) return $handled;

    $message   = $ctx['message'] ?? '';
    $user_id   = (int) ( $ctx['wp_user_id'] ?? 0 );
    $chat_id   = $ctx['chat_id'] ?? '';
    $platform  = $ctx['platform'] ?? '';
    $recent    = $ctx['recent_context'] ?? '';  // ✨ Enriched context
    $image_url = $ctx['image_url'] ?? '';       // ✨ Image from transient
    $client_id = $ctx['client_id'] ?? '';

    // ── 1. Check pending follow-up TRƯỚC keyword check ──
    if ( $chat_id && $user_id ) {
        $pending_key = 'bz{prefix}_pending_' . md5( $chat_id );
        $pending     = get_site_transient( $pending_key );
        if ( $pending && ! empty( $message ) ) {
            delete_site_transient( $pending_key );
            // Xử lý message như input cho bước trước
            $reply = bz{prefix}_process_followup( $user_id, $message, $pending, $recent );
            bz{prefix}_send_long_message( $chat_id, $client_id, $platform, $reply );
            return true;
        }
    }

    // ── 2. Keyword check ──
    if ( ! bz{prefix}_is_intent( $message ) ) return $handled;

    // ── 3. Classify sub-intent & process natively ──
    $sub = bz{prefix}_classify_sub_intent( $message );

    switch ( $sub ) {
        case 'action_with_data':
            // Message chứa đủ dữ liệu → xử lý trực tiếp
            $reply = bz{prefix}_process_action( $user_id, $message, $platform, $recent );
            bz{prefix}_send_long_message( $chat_id, $client_id, $platform, $reply );
            return true;

        case 'query':
            // Câu hỏi → query DB & trả lời
            $reply = bz{prefix}_process_query( $user_id, $message, $recent );
            bz{prefix}_send_long_message( $chat_id, $client_id, $platform, $reply );
            return true;

        case 'needs_input':
        default:
            // Cần thêm thông tin → hỏi (dùng LLM cho tự nhiên)
            $ask = bz{prefix}_compose_natural_ask( $user_id, $message, $recent );
            set_site_transient(
                'bz{prefix}_pending_' . md5( $chat_id ),
                'awaiting_input',
                10 * MINUTE_IN_SECONDS
            );
            biz_send_message( $chat_id, $ask );
            return true;
    }
}
```

> ⚠️ **Pending check TRƯỚC keyword check** — vì reply của user (VD: "cơm gà") không match keywords.

### Pattern 2: Link Redirect (cho plugin cần frontend interaction)

```php
add_filter( 'bizcity_unified_message_intent', 'bz{prefix}_intent_filter', 10, 2 );
function bz{prefix}_intent_filter( $handled, $ctx ) {
    if ( $handled ) return $handled;
    $message = $ctx['message'] ?? '';
    if ( ! bz{prefix}_is_intent( $message ) ) return $handled;

    $link = bz{prefix}_build_link(
        $ctx['chat_id'] ?? '', $ctx['wp_user_id'] ?? 0,
        $ctx['client_id'] ?? '', $ctx['platform'] ?? '',
        $ctx['blog_id'] ?? 0, $message
    );
    biz_send_message( $ctx['chat_id'], "🔮 Link:\n{$link}" );
    return true;
}
```

### Pattern: Webchat / Admin Chat (không thay đổi)

Webchat đã có 6-Layer Dual Context Chain → Intent Engine tự xử lý context.
Plugin chỉ cần `bizcity_chat_pre_ai_response` cho trường hợp đặc biệt (VD: ảnh).

```php
add_filter( 'bizcity_chat_pre_ai_response', 'bz{prefix}_webchat_filter', 10, 2 );
function bz{prefix}_webchat_filter( $pre_reply, $ctx ) {
    if ( $pre_reply ) return $pre_reply;
    $message = $ctx['message'] ?? '';
    // CHỈ xử lý case đặc biệt (ảnh, file, ...) — text-only để Intent Engine handle
    if ( ! bz{prefix}_has_special_case( $ctx ) ) return $pre_reply;
    // ... xử lý case đặc biệt ...
    return [ 'message' => $result ];
}
```

### Pattern: Truyền recent_context vào AI prompt

Khi plugin gọi `bizcity_openrouter_chat()` trên Zalo/Bot, **LUÔN** include `recent_context`:

```php
$user_prompt = "Phân tích: {$message}";
if ( $recent ) {
    $user_prompt .= "\n\n[Ngữ cảnh hội thoại]:\n" . mb_substr( $recent, 0, 500 );
}
$ai = bizcity_openrouter_chat( [
    [ 'role' => 'system', 'content' => $system ],
    [ 'role' => 'user',   'content' => $user_prompt ],
], [ 'purpose' => 'chat' ] );
```

### ✅ Cross-Path Context Checklist

```
□ Intent Filter sử dụng $ctx['recent_context'] khi gọi AI
□ Intent Filter sử dụng $ctx['image_url'] khi xử lý ảnh
□ Pending follow-up check TRƯỚC keyword check
□ Native processing (không chỉ gửi link) cho Zalo/Bot
□ Tool helpers nhận explicit user_id (KHÔNG dùng get_current_user_id() trên Zalo)
□ AI prompts include recent_context để hiểu ngữ cảnh hội thoại
□ Test trên: Webchat, Zalo Personal, Zalo Bot, FB Messenger
```

### Pattern: Secure token system

```php
<?php
function bz{prefix}_create_token( $chat_id, $user_id, $client_id, $platform, $blog_id, $message ) {
    $payload = compact( 'chat_id', 'user_id', 'client_id', 'platform', 'blog_id', 'message' );
    $payload['created_at'] = time();
    $token = substr( hash_hmac( 'sha256', wp_json_encode( $payload ), wp_salt( 'auth' ) ), 0, 20 );
    set_site_transient( 'bz{prefix}_token_' . $token, $payload, 48 * HOUR_IN_SECONDS );
    return $token;
}

function bz{prefix}_validate_token( $token ) {
    $token = preg_replace( '/[^a-f0-9]/', '', $token );
    return get_site_transient( 'bz{prefix}_token_' . $token ) ?: null;
}

function bz{prefix}_build_link( $chat_id, $user_id, $client_id, $platform, $blog_id, $message ) {
    $token = bz{prefix}_create_token( $chat_id, $user_id, $client_id, $platform, $blog_id, $message );
    return add_query_arg( 'bz{prefix}_token', $token, bz{prefix}_get_page_url() );
}
```

### Pattern: Push result back to chat

```php
<?php
add_action( 'wp_ajax_bz{prefix}_push_result',        'bz{prefix}_ajax_push_result' );
add_action( 'wp_ajax_nopriv_bz{prefix}_push_result', 'bz{prefix}_ajax_push_result' );

function bz{prefix}_ajax_push_result() {
    check_ajax_referer( 'bz{prefix}_pub_nonce', 'nonce' );

    $token    = sanitize_text_field( $_POST['token'] ?? '' );
    $ai_reply = wp_kses_post( $_POST['ai_reply'] ?? '' );

    $ctx = bz{prefix}_validate_token( $token );
    if ( ! $ctx ) {
        wp_send_json_error( [ 'message' => 'Token hết hạn' ] );
    }

    // Send AI interpretation back to the chat platform
    bz{prefix}_send_long_message( $ctx['chat_id'], $ctx['client_id'], $ctx['platform'], $ai_reply );

    wp_send_json_success( [
        'sent'     => true,
        'chat_id'  => $ctx['chat_id'],
        'platform' => $ctx['platform'],
    ] );
}
```

---

## 11. Module 8: Intent Provider (AI Skill)

File: `includes/class-intent-provider.php`

Đây là **trái tim** kết nối plugin với AI Agent Engine.

### Sơ đồ liên kết

```
┌────────────────────────────────────────────────────────────────┐
│                     INTENT ENGINE (Lõi)                         │
│                                                                 │
│  ┌─── Router ──────┐   ┌─── Planner ──────┐   ┌─── Tools ──┐ │
│  │ classify(msg)    │   │ plan(goal, slots) │   │ execute()  │ │
│  │                  │   │                   │   │            │ │
│  │ goal_patterns    │   │ plans             │   │ registry   │ │
│  │  ↑ filter merge  │   │  ↑ filter merge   │   │  ↑ direct  │ │
│  └──┬───────────────┘   └──┬────────────────┘   └──┬─────────┘ │
│     │                      │                        │           │
│  ┌──┴──────────────────────┴────────────────────────┴─────────┐ │
│  │              Provider Registry (singleton)                  │ │
│  │                                                             │ │
│  │  register(provider) → merge_goal_patterns()                 │ │
│  │                     → merge_plans()                         │ │
│  │                     → register_provider_tools()             │ │
│  │                                                             │ │
│  │  inject_context(goal) → provider.build_context()            │ │
│  │                       → provider.get_system_instructions()  │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                              ↑                                   │
│         bizcity_intent_register_providers action                 │
└──────────────────────────────┬──────────────────────────────────┘
                               │
              ┌────────────────┼────────────────┐
              ▼                ▼                ▼
      ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
      │ Tarot        │ │ BizCoach     │ │ Plugin ???    │
      │ Provider     │ │ Provider     │ │ Provider      │
      │              │ │              │ │               │
      │ get_id()     │ │ get_id()     │ │ get_id()      │
      │ get_name()   │ │ get_name()   │ │ get_name()    │
      │ patterns()   │ │ patterns()   │ │ patterns()    │
      │ plans()      │ │ plans()      │ │ plans()       │
      │ tools()      │ │ tools()      │ │ tools()       │
      │ context()    │ │ context()    │ │ context()     │
      │ instructions │ │ instructions │ │ instructions  │
      └──────────────┘ └──────────────┘ └──────────────┘
```

### Provider Contract (Abstract Base)

```php
abstract class BizCity_Intent_Provider {

    /* ── BẮT BUỘC (abstract) ── */
    abstract public function get_id();          // 'tarot', 'rune', 'numerology'
    abstract public function get_name();         // 'BizCity Tarot — Bói bài'

    /* ── ĐĂNG KÝ (override để cung cấp data) ── */
    public function get_goal_patterns() {}       // regex → goal mapping
    public function get_plans() {}               // goal → slot schema + tool
    public function get_tools() {}               // tool_name → schema + callback

    /* ── CONTEXT (override để cung cấp kiến thức cho AI) ── */
    public function build_context() {}           // DATA cho system prompt
    public function get_system_instructions() {} // HƯỚNG DẪN cho AI behavior

    /* ── TỰ ĐỘNG (không cần override) ── */
    public function get_owned_goals() {}         // Auto-derived
    public function owns_goal( $goal ) {}        // Auto-derived
}
```

### Chi tiết từng method

#### `get_goal_patterns()` — Needles (Kim phát hiện ý định)

```php
public function get_goal_patterns() {
    return [
        // regex => config
        '/keyword1|keyword2|keyword3/ui' => [
            'goal'    => 'goal_id',        // Unique goal ID (snake_case)
            'label'   => 'Display Label',  // Hiển thị trên dashboard
            'extract' => [ 'slot1', 'slot2' ], // Slots có thể extract từ message
        ],
    ];
}
```

**Quy tắc viết regex (needles):**
- Dùng flag `/ui` (unicode + case-insensitive)
- Bao gồm cả tiếng Việt CÓ DẤU và KHÔNG DẤU
- Mỗi goal nên có 3-10 keyword variations
- Keyword phải đủ cụ thể để tránh false positive
- Test với ít nhất 20 câu mẫu

**Ví dụ thực tế từ bizcity-tarot:**
```
/tarot|bốc bài|xem bài|rút bài|lá bài|bói bài|gieo quẻ|trải bài|xem tarot|muốn bói|cho xem bài/ui
```

#### `get_plans()` — Kịch bản hội thoại

```php
public function get_plans() {
    return [
        'goal_id' => [
            'required_slots' => [
                'slot_name' => [
                    'type'    => 'choice|text|number|image|date|phone',
                    'prompt'  => 'Câu hỏi cho user khi thiếu slot',
                    'choices' => [ 'key' => 'Display' ],  // Chỉ cho type=choice
                    'default' => 'default_value',          // Optional
                ],
            ],
            'optional_slots' => [ /* cùng format */ ],
            'tool'       => 'tool_name' | null,   // Tool gọi khi đủ slots (null = chỉ AI compose)
            'ai_compose' => true | false,          // AI soạn câu trả lời?
            'slot_order' => [ 'slot1', 'slot2' ],  // Thứ tự hỏi
        ],
    ];
}
```

**Lifecycle của Plan:**
```
1. User gửi tin nhắn → Router detect goal
2. Planner check required_slots → thiếu slot nào?
3. Nếu thiếu → trạng thái "ask_user" → hỏi user
4. Nếu đủ → "call_tool" (nếu có tool) hoặc "compose_answer" (nếu ai_compose)
5. Tool trả về { complete: true } → goal done
6. Tool trả về { complete: false } → tiếp tục conversation
```

#### `get_tools()` — Hành động thực thi

```php
public function get_tools() {
    return [
        'tool_name' => [
            'schema' => [
                'description'  => 'Mô tả tool',
                'input_fields' => [
                    'field' => [ 'required' => true|false, 'type' => 'text|number|...' ],
                ],
            ],
            'callback' => [ $this, 'tool_method_name' ],
        ],
    ];
}

/* Tool callback — BẮT BUỘC trả về format này */
public function tool_method_name( array $slots ) {
    return [
        'success'  => true,       // bool — thao tác thành công?
        'complete' => true,       // bool — goal hoàn thành? (true = đóng, false = tiếp)
        'message'  => 'Kết quả', // string — message trả về user
        'data'     => [],         // array — structured data (optional)
    ];
}
```

**`complete` flag — QUAN TRỌNG:**
- `true` → Goal kết thúc, conversation chuyển sang COMPLETED
- `false` → Tool cần thêm thông tin hoặc có bước tiếp theo
- Mặc định: `success === true` thì `complete = true`

#### `build_context()` — Kiến thức chuyên môn cho AI

```php
public function build_context( $goal, array $slots, $user_id, array $conversation ) {
    // Trả về STRING chứa dữ liệu chuyên môn
    // String này sẽ được inject vào system prompt của AI

    $parts = [];

    // 1. Dữ liệu từ DB của plugin
    $parts[] = "=== DỮ LIỆU {NAME} ===\n" . $this->get_domain_data( $user_id );

    // 2. Dữ liệu liên quan đến user (cá nhân hóa)
    $parts[] = "=== LỊCH SỬ USER ===\n" . $this->get_user_history( $user_id );

    // 3. Dữ liệu từ slots hiện tại
    if ( ! empty( $slots['topic'] ) ) {
        $parts[] = "=== CHỦ ĐỀ ĐƯỢC CHỌN ===\nChủ đề: " . $slots['topic'];
    }

    return implode( "\n\n", array_filter( $parts ) );
}
```

**Khi nào build_context() được gọi?**
```
User message
  → Router classify → goal detected
  → Planner → action = "compose_answer"
  → Engine → apply_filters('bizcity_intent_compose_context', ...)
  → Registry → provider.build_context()
  → Context string INJECT vào system prompt
  → AI compose câu trả lời với KIẾN THỨC CHUYÊN MÔN
```

#### `get_system_instructions()` — Hướng dẫn hành vi AI

```php
public function get_system_instructions( $goal ) {
    // Trả về STRING hướng dẫn AI cách trả lời cho domain này
    // Khác với build_context() (DATA), đây là INSTRUCTIONS (hướng dẫn)

    return "Bạn là chuyên gia {domain}.\n"
         . "Khi trả lời về {goal}, hãy:\n"
         . "1. {Quy tắc 1}\n"
         . "2. {Quy tắc 2}\n"
         . "3. {Quy tắc 3}\n"
         . "Giọng văn: {mô tả tone}\n";
}
```

### Flow toàn bộ — Từ tin nhắn đến kết quả

```
┌─────────────────────────────────────────────────────────────────┐
│ User: "Bốc bài tarot về tình cảm"                              │
└──────────────────────┬──────────────────────────────────────────┘
                       ▼
┌─────────── Chat Gateway ────────────────────────────────────────┐
│ fire: bizcity_chat_pre_ai_response                              │
│       → Intent Engine intercept                                 │
└──────────────────────┬──────────────────────────────────────────┘
                       ▼
┌─────────── Intent Engine ───────────────────────────────────────┐
│                                                                  │
│  1. ROUTER.classify("Bốc bài tarot về tình cảm")               │
│     → regex match: /tarot|bốc bài.../ui                         │
│     → goal = "tarot_reading"                                     │
│     → extract: { question_focus: "tình cảm" }                   │
│                                                                  │
│  2. PLANNER.plan("tarot_reading", slots)                        │
│     → required: question_focus ✅ (đã có "tình cảm")            │
│     → required: spread ❌ (chưa có)                              │
│     → action = "ask_user" + prompt: "Bạn muốn rút mấy lá? 🃏"  │
│                                                                  │
└──────────────────────┬──────────────────────────────────────────┘
                       ▼
┌─────────── Response ────────────────────────────────────────────┐
│ Bot: "Bạn muốn rút mấy lá? 🃏                                   │
│       • 1 lá                                                     │
│       • 3 lá (Quá khứ - Hiện tại - Tương lai)"                 │
└─────────────────────────────────────────────────────────────────┘

                      (User replies: "3 lá")

┌─────────── Intent Engine (tiếp) ────────────────────────────────┐
│                                                                  │
│  3. PLANNER.plan("tarot_reading", { focus: "tình cảm", spread: "3" })
│     → required: question_focus ✅                                │
│     → required: spread ✅                                        │
│     → tool = "send_link_tarot"                               │
│     → action = "call_tool"                                       │
│                                                                  │
│  4. TOOLS.execute("send_link_tarot", slots)                      │
│     → callback: BizCity_Tarot_Intent_Provider::tool_start_reading│
│     → kết quả: { success: true, complete: true, message: "🔮..." }│
│                                                                  │
│  5. COMPOSE (ai_compose = true)                                  │
│     → Registry.inject_context("tarot_reading", ...)              │
│     → Tarot Provider.build_context() → topics + matched data     │
│     → Tarot Provider.get_system_instructions() → AI guidelines   │
│     → System prompt enriched + AI compose final answer            │
│                                                                  │
│  6. Conversation → COMPLETED                                     │
│                                                                  │
└──────────────────────┬──────────────────────────────────────────┘
                       ▼
┌─────────── Response ────────────────────────────────────────────┐
│ Bot: "🔮 Bốc bài Tarot 🔮                                       │
│       Tuyệt vời! Hãy truy cập link bên dưới:                    │
│       👉 https://bizcity.vn/tarot/?bct_token=abc123              │
│       Chủ đề: Tình cảm                                          │
│       Sau khi bốc xong, kết quả sẽ gửi tại đây! ✨"             │
└─────────────────────────────────────────────────────────────────┘
```

---

## 12. Module 9: Knowledge Binding (RAG Context)

File: `includes/knowledge-binding.php`

Mỗi Plugin Agent có lớp tri thức riêng, liên kết với `bizcity-knowledge` qua **Character**.

> Xem chi tiết kiến trúc: [ARCHITECTURE.md § 7](ARCHITECTURE.md#7-liên-kết-knowledge--plugin-agent)

### Sơ đồ liên kết

```
Plugin Agent ──── get_knowledge_character_id() ────▶ Knowledge Character
    │                                                   │
    │   build_context()                     Knowledge Sources
    │   ├── Domain Data (plugin DB)              ├── Quick FAQ
    │   └── Knowledge RAG (API) ◀──────────────┤   ├── Files (PDF, CSV)
    │                                        │   ├── Web Crawl
    │                                        │   └── Fanpage
    │                                        │
    │──── Context inject vào AI compose  ────┘
```

### Implementation

```php
<?php
/**
 * Knowledge Binding — Liên kết plugin với bizcity-knowledge.
 * File: includes/knowledge-binding.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─── Lấy character_id liên kết ─── */
function bz{prefix}_get_knowledge_character_id() {
    return (int) get_option( 'bz{prefix}_knowledge_character_id', 0 );
}

/* ─── Lấy knowledge context từ bizcity-knowledge ─── */
function bz{prefix}_get_knowledge_context( $query, $max_tokens = 2000 ) {
    $char_id = bz{prefix}_get_knowledge_character_id();
    if ( ! $char_id || ! class_exists( 'BizCity_Knowledge_Context_API' ) ) {
        return '';
    }

    $result = BizCity_Knowledge_Context_API::instance()->build_context(
        $char_id, $query, [ 'max_tokens' => $max_tokens ]
    );

    return ! empty( $result['context'] ) ? $result['context'] : '';
}

/* ─── Admin Settings: Chọn character liên kết ─── */
function bz{prefix}_knowledge_settings_section() {
    $char_id = bz{prefix}_get_knowledge_character_id();
    $characters = bz{prefix}_get_available_characters();
    ?>
    <h3>📚 Đào tạo kiến thức</h3>
    <table class="form-table">
        <tr>
            <th>Character liên kết</th>
            <td>
                <select name="bz{prefix}_knowledge_character_id">
                    <option value="0">-- Chưa liên kết --</option>
                    <?php foreach ( $characters as $c ) : ?>
                        <option value="<?php echo esc_attr( $c->id ); ?>"
                            <?php selected( $char_id, $c->id ); ?>>
                            <?php echo esc_html( $c->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="<?php echo admin_url( 'admin.php?page=bizcity-knowledge-new' ); ?>" target="_blank">
                    🔗 Tạo Character mới
                </a>
                <p class="description">
                    Character chứa kiến thức chuyên môn (FAQ, files, web crawl) riêng cho plugin này.
                </p>
            </td>
        </tr>
    </table>
    <?php
    if ( $char_id ) {
        $knowledge_url = admin_url( 'admin.php?page=bizcity-knowledge-edit&character_id=' . $char_id );
        echo '<p><a href="' . esc_url( $knowledge_url ) . '" class="button" target="_blank">';
        echo '📝 Quản lý kiến thức (FAQ, Upload, Crawl)</a></p>';
    }
}

/* ─── Helper: Lấy danh sách characters từ bizcity-knowledge ─── */
function bz{prefix}_get_available_characters() {
    global $wpdb;
    $table = $wpdb->prefix . 'bizcity_characters';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return [];
    return $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY name" );
}

/* ─── Save settings ─── */
add_action( 'admin_init', function() {
    if ( ! isset( $_POST['bz{prefix}_knowledge_character_id'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    check_admin_referer( 'bz{prefix}_settings' );
    update_option( 'bz{prefix}_knowledge_character_id',
        (int) $_POST['bz{prefix}_knowledge_character_id'] );
} );

/* ─── Auto-sync: khi plugin data thay đổi → re-index knowledge ─── */
add_action( 'bz{prefix}_item_saved', function( $item_id, $item_data ) {
    $char_id = bz{prefix}_get_knowledge_character_id();
    if ( ! $char_id || ! function_exists( 'bizcity_knowledge_sync_plugin_data' ) ) return;

    bizcity_knowledge_sync_plugin_data( $char_id, 'bz{prefix}', $item_id, [
        'title'   => $item_data['name_vi'] ?? '',
        'content' => ($item_data['description'] ?? '') . "\n" . ($item_data['data_json'] ?? ''),
    ] );
}, 10, 2 );
```

### Sử dụng trong Intent Provider

```php
// Trong class-intent-provider.php
public function build_context( $goal, array $slots, $user_id, array $conversation ) {
    $parts = [];

    // 1. Domain data (từ plugin DB)
    $parts[] = $this->build_domain_context( $goal, $slots );

    // 2. Knowledge RAG (từ bizcity-knowledge)
    $query = $slots['question_focus'] ?? $conversation['last_message'] ?? '';
    $knowledge = bz{prefix}_get_knowledge_context( $query, 2000 );
    if ( $knowledge ) {
        $parts[] = "=== KIẾN THỨC CHUYÊN MÔN ===\n" . $knowledge;
    }

    return implode( "\n\n", array_filter( $parts ) );
}
```

### Chuẩn Settings Tab

Mỗi plugin Agent cần gọi `bz{prefix}_knowledge_settings_section()` trong trang Cài đặt:

| Tab | Nội dung |
|-----|--------|
| ⚙️ Cài đặt chung | Plugin-specific settings |
| 📚 Đào tạo kiến thức | Character selector + link quản lý knowledge |

---

## 13. Naming Convention

### Plugin naming

| Item | Pattern | Ví dụ |
|------|---------|-------|
| Plugin folder | `bizcity-{slug}` | `bizcity-tarot`, `bizcity-rune` |
| Main file | `bizcity-{slug}.php` | `bizcity-tarot.php` |
| Text domain | `bizcity-{slug}` | `bizcity-tarot` |
| Constant prefix | `BZ{PREFIX}_` (3-4 ký tự UPPER) | `BCT_`, `BZR_`, `BZNM_` |
| Function prefix | `bz{prefix}_` (lowercase) | `bct_`, `bzr_`, `bznm_` |
| DB table prefix | `bz{prefix}_` | `bct_cards`, `bzr_stones` |
| AJAX action prefix | `bz{prefix}_` | `bct_get_cards`, `bzr_get_stones` |
| Shortcode | `[bizcity_{slug}]` | `[bizcity_tarot]`, `[bizcity_rune]` |
| Admin menu slug | `bizcity-{slug}` | `bizcity-tarot` |

### Intent Provider naming

| Item | Pattern | Ví dụ |
|------|---------|-------|
| Class name | `BizCity_{Name}_Intent_Provider` | `BizCity_Tarot_Intent_Provider` |
| Provider ID | `{slug}` (snake_case) | `tarot`, `rune`, `numerology` |
| Goal ID | `{action}_{object}` (snake_case) | `tarot_reading`, `rune_casting` |
| Tool name | `{slug}_{action}` | `send_link_tarot`, `rune_cast_stones` |

### Goal patterns (needles) naming

| Goal type | Pattern |
|-----------|---------|
| Interactive (user tương tác frontend) | `{slug}_reading`, `{slug}_casting`, `{slug}_session` |
| Analysis (AI phân tích) | `{slug}_analysis`, `{slug}_report` |
| Forecast (dự báo) | `{slug}_forecast`, `{slug}_outlook` |
| Action (thao tác) | `{slug}_create`, `{slug}_generate` |

---

## 14. Checklist khi tạo plugin mới

### Phase 1: Foundation (Ngày 1)
- [ ] Tạo folder `bizcity-{slug}/` với cấu trúc chuẩn
- [ ] Viết `bizcity-{slug}.php` bootstrap (constants, includes, assets)
- [ ] Viết `includes/install.php` (DB tables + seed data)
- [ ] Viết `includes/topics.php` (data registry)
- [ ] Thêm `index.php` security files mỗi thư mục
- [ ] Test: Activate plugin → DB tables created → Seed data OK

### Phase 2: Admin (Ngày 2)
- [ ] Viết `includes/admin-menu.php` (menu + submenus)
- [ ] Viết `includes/admin-dashboard.php` (CRUD cho items)
- [ ] Viết `assets/admin.css` + `assets/admin.js`
- [ ] Test: Admin menu hiện → CRUD items hoạt động

### Phase 3: Frontend (Ngày 3)
- [ ] Viết `includes/shortcode.php` (shortcode renderer)
- [ ] Viết `views/{slug}-landing.php` (template registration)
- [ ] Viết `views/page-{slug}-full.php` (full-page template)
- [ ] Viết `assets/public.css` + `assets/public.js`
- [ ] Test: Shortcode render đúng → Items hiển thị → Interaction hoạt động

### Phase 4: AJAX & AI (Ngày 4)
- [ ] Viết `includes/ajax.php` (get_items, get_detail, save_result, ai_interpret)
- [ ] Test: AJAX calls hoạt động → AI interpretation OK

### Phase 5: Chat Integration (Ngày 5)
- [ ] Viết `includes/integration-chat.php` (intent filter, secure token, push result)
- [ ] ✨ Sử dụng `$ctx['recent_context']` khi gọi AI
- [ ] ✨ Sử dụng `$ctx['image_url']` khi xử lý ảnh
- [ ] ✨ Native processing cho Zalo/Bot (không chỉ gửi link)
- [ ] ✨ Pending follow-up check TRƯỚC keyword check
- [ ] Test: Chat keyword → xử lý native → kết quả trực tiếp
- [ ] Test: Chat ảnh → phân tích → kết quả
- [ ] Test: Multi-turn (hỏi-đáp) → pending transient

### Phase 6: Intent Provider (Ngày 6)
- [ ] Viết `includes/class-intent-provider.php` (Provider class)
- [ ] Register provider trong bootstrap (`bizcity_intent_register_providers`)
- [ ] Define: goal_patterns (needles), plans (slots), tools (callbacks)
- [ ] Implement: build_context(), get_system_instructions()
- [ ] Test: Chat → Router detect → Planner ask slots → Tool execute → AI compose

### Phase 7: Knowledge Binding (Ngày 7)
- [ ] Viết `includes/knowledge-binding.php` (character selector, context helper)
- [ ] Thêm "Đào tạo kiến thức" section trong Admin Settings
- [ ] Integrate `bz{prefix}_get_knowledge_context()` vào Provider.build_context()
- [ ] Create character riêng cho plugin trong bizcity-knowledge
- [ ] Upload FAQ + tài liệu cơ bản → test knowledge context
- [ ] Test: Chat intent → AI compose có kèm knowledge context

### Phase 8: Polish (Ngày 8)
- [ ] Viết `README.md`
- [ ] DB migration logic (admin_init version check)
- [ ] Error handling, edge cases
- [ ] Performance: transient cache cho heavy queries
- [ ] Review: all nonces verified, capabilities checked, data sanitized

---

## 15. Ví dụ thực tế: bizcity-tarot

| Module | File | Lines | Status |
|--------|------|-------|--------|
| Bootstrap | bizcity-tarot.php | 133 | ✅ |
| Database | includes/install.php | 300 | ✅ (2 tables, 78 cards seed) |
| Topics | includes/topics.php | 413 | ✅ (24 topics, 7 categories) |
| Admin Menu | includes/admin-menu.php | 68 | ✅ (4 submenus) |
| Admin Cards | includes/admin-cards.php | 350 | ✅ (CRUD + settings) |
| Admin Crawl | includes/admin-crawl.php | 216 | ✅ (learntarot.com import) |
| AJAX | includes/ajax.php | 560 | ✅ (6 endpoints: 1 admin + 5 public) |
| Shortcode | includes/shortcode.php | 261 | ✅ (`[bizcity_tarot]`) |
| Template | views/tarot-landing.php | 197 | ✅ (`bct-tarot-landing`) |
| Chat Integration | includes/integration-chat.php | 711 | ✅ (intent filter + token + push) |
| Intent Provider | includes/class-intent-provider.php | 289 | ✅ (1 goal, 1 tool, context) |
| **TOTAL** | | **~3500** | |

---

## 16. Ý tưởng plugin tương lai

Mỗi plugin = 1 Skill cho AI Agent. Danh sách tiềm năng:

### Tier 1 — Divination & Insight (Bói toán & Tâm linh)

| Plugin | Goal ID | Needles (keywords) | Tools |
|--------|---------|---------------------|-------|
| bizcity-rune | `rune_casting` | rune, phù văn, bắc âu, futhark | `rune_cast_stones`, `rune_interpret` |
| bizcity-iching | `iching_reading` | kinh dịch, quẻ dịch, chu dịch, bát quái | `iching_cast_hexagram` |
| bizcity-numerology | `numerology_analysis` | thần số học, số chủ đạo, numerology | `numerology_calculate`, `numerology_report` |
| bizcity-palmistry | `palm_reading` | xem tay, chỉ tay, palmistry, bàn tay | `palm_analyze_image` |
| bizcity-fengshui | `fengshui_consult` | phong thủy, hướng nhà, ngũ hành, fengshui | `fengshui_analyze`, `fengshui_suggest` |
| bizcity-dream | `dream_interpret` | giải mộng, mơ thấy, chiêm bao, giấc mơ | `dream_interpret`, `dream_lookup` |
| bizcity-horoscope | `daily_horoscope` | cung hoàng đạo, tử vi hàng ngày, horoscope | `horoscope_daily`, `horoscope_weekly` |

### Tier 2 — Business & Productivity (Kinh doanh)

| Plugin | Goal ID | Needles | Tools |
|--------|---------|---------|-------|
| bizcity-invoice | `create_invoice` | hóa đơn, invoice, xuất hóa đơn | `invoice_create`, `invoice_send` |
| bizcity-crm | `crm_action` | khách hàng, CRM, follow up, pipeline | `crm_add_contact`, `crm_update_deal` |
| bizcity-seo | `seo_analysis` | SEO, từ khóa, keyword, ranking | `seo_analyze_page`, `seo_suggest` |
| bizcity-email | `send_email` | gửi email, email marketing, newsletter | `email_compose`, `email_send` |

### Tier 3 — Wellness & Lifestyle (Sức khỏe & Đời sống)

| Plugin | Goal ID | Needles | Tools |
|--------|---------|---------|-------|
| bizcity-meditation | `meditation_session` | thiền, meditation, mindfulness, thư giãn | `meditation_guide`, `meditation_timer` |
| bizcity-workout | `workout_plan` | tập luyện, workout, gym, cardio | `workout_generate`, `workout_track` |
| bizcity-recipe | `recipe_suggest` | nấu ăn, recipe, món ăn, thực đơn | `recipe_suggest`, `recipe_nutrition` |
| bizcity-journal | `daily_journal` | nhật ký, diary, journal, ghi chép | `journal_create`, `journal_reflect` |

### Tier 4 — Education & Learning (Giáo dục)

| Plugin | Goal ID | Needles | Tools |
|--------|---------|---------|-------|
| bizcity-quiz | `quiz_session` | quiz, kiểm tra, trắc nghiệm, test | `quiz_generate`, `quiz_grade` |
| bizcity-flashcard | `flashcard_study` | flashcard, ôn bài, ghi nhớ, anki | `flashcard_create`, `flashcard_review` |
| bizcity-tutor | `tutor_explain` | giải thích, dạy bài, explain, tutor | `tutor_explain`, `tutor_exercise` |

---

> **Tổng kết**: Với chuẩn này, mỗi developer mới chỉ cần:
> 1. Copy folder template
> 2. Thay {prefix}, {slug}, {name}
> 3. Implement 8 modules theo thứ tự checklist
> 4. Activate → AI Agent tự động có thêm skills mới
>
> **Zero core touch. Infinite scalability. 🚀**
