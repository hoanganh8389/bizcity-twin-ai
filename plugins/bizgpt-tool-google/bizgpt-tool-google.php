<?php
/**
 * Plugin Name:       Tool — Google
 * Plugin URI:        https://bizcity.vn/marketplace/bizgpt-tool-google
 * Description:       Kết nối Google (Gmail, Calendar, Drive, Contacts) qua OAuth Hub trung tâm. Site con chỉ cần kích hoạt — không cần tạo Google API project.
 * Short Description: Kết nối Gmail, Calendar, Drive qua BizCity OAuth Hub — 1 click, không cần API Console.
 * Quick View:        🔗 Kết nối Google → Đọc mail, lịch, file — tất cả qua chat
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizgpt-tool-google
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/google.png
 * Template Page:     tool-google
 * Plan:              free
 * Category:          google, email, calendar, automation
 * Tags:              gmail, calendar, drive, contacts, google, oauth, email, lịch, automation
 *
 * === Giới thiệu ===
 * BizGPT Tool Google cho phép người dùng kết nối tài khoản Google thông qua
 * OAuth Hub trung tâm của BizCity. Chỉ cần bấm "Kết nối Google" — không cần
 * tự tạo Google API project hay vào Google Cloud Console.
 *
 * === Tính năng chính ===
 * • Đọc danh sách email Gmail
 * • Gửi email qua Gmail
 * • Tóm tắt inbox bằng AI
 * • Đọc sự kiện Calendar
 * • Tạo sự kiện Calendar
 * • Đọc / upload file Google Drive
 * • Đọc danh bạ Google Contacts
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • BizGPT OAuth Server (bizgpt-oauth-server-new) trên hub site
 *
 * === Hướng dẫn kích hoạt ===
 * 1. Kích hoạt plugin trên site bất kỳ trong network.
 * 2. Nếu là hub site: vào Settings > Google OAuth Hub để nhập Google client_id / client_secret.
 * 3. Nếu là site con: người dùng vào trang cá nhân > bấm "Kết nối Google". * Truy cập /tool-google/ để mở Google Studio. */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity Tool — Google</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

/* ── Constants ─────────────────────────────────────────────── */
define( 'BZGOOGLE_VERSION',  '1.0.1' );
define( 'BZGOOGLE_DIR',      plugin_dir_path( __FILE__ ) );
define( 'BZGOOGLE_URL',      plugin_dir_url( __FILE__ ) );
define( 'BZGOOGLE_SLUG',     'bizgpt-tool-google' );
define( 'BZGOOGLE_FILE',     __FILE__ );

/* ── Autoload classes ──────────────────────────────────────── */
require_once BZGOOGLE_DIR . 'includes/class-installer.php';
require_once BZGOOGLE_DIR . 'includes/class-token-store.php';
require_once BZGOOGLE_DIR . 'includes/class-google-oauth.php';
require_once BZGOOGLE_DIR . 'includes/class-google-service.php';
require_once BZGOOGLE_DIR . 'includes/class-tools-google.php';
require_once BZGOOGLE_DIR . 'includes/class-admin.php';
require_once BZGOOGLE_DIR . 'includes/class-rest-api.php';
require_once BZGOOGLE_DIR . 'includes/class-cron.php';

/* ── Activation / Deactivation ─────────────────────────────── */
register_activation_hook( __FILE__, [ 'BZGoogle_Installer', 'activate' ] );
register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, [ 'BZGoogle_Cron', 'deactivate' ] );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );

/* ── Self-healing: create tables if activation hook was skipped ── */
BZGoogle_Installer::maybe_create_tables();

/* ── Init ──────────────────────────────────────────────────── */
add_action( 'init', [ 'BZGoogle_Google_OAuth', 'register_rewrite_rules' ] );
add_action( 'init', [ 'BZGoogle_Admin',        'init' ] );
add_action( 'rest_api_init', [ 'BZGoogle_REST_API', 'register_routes' ] );

/* ── Cron ──────────────────────────────────────────────────── */
add_action( 'init', [ 'BZGoogle_Cron', 'schedule' ] );
add_action( 'bzgoogle_refresh_tokens', [ 'BZGoogle_Cron', 'refresh_expiring_tokens' ] );

/* ── Early route: handle /google-auth/* at init level ──────── *
 * Bypasses WP_Query entirely so it works even when rewrite     *
 * rules haven't been flushed (404 → canonical redirect → 500). */
add_action( 'init', function () {
    if ( empty( $_SERVER['REQUEST_URI'] ) ) return;
    $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    if ( preg_match( '/^google-auth\/([a-z]+)$/', $path, $m ) ) {
        BZGoogle_Google_OAuth::handle_request_direct( $m[1] );
        // handle_request_direct exits internally — should never reach here
        exit;
    }
}, 20 );

/* ── Query vars (kept for when rewrite rules ARE flushed) ──── */
add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'bzgoogle_action';
    return $vars;
} );

/* ── Template redirect fallback (only if init handler missed it) ── */
add_action( 'template_redirect', [ 'BZGoogle_Google_OAuth', 'handle_request' ], 1 );

/* ══════════════════════════════════════════════════════════════
 *  TEMPLATE PAGE — /tool-google/
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-google/?$', 'index.php?bizcity_agent_page=tool-google', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
/* ── Early route: handle /tool-google/ at init level ──────── */
add_action( 'init', function () {
    if ( empty( $_SERVER['REQUEST_URI'] ) ) return;
    $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    if ( preg_match( '/^tool-google\/?$/', $path ) ) {
        // Let WordPress finish init, then render at template_redirect
        // We set a flag so the template_redirect handler can pick it up
        add_action( 'template_redirect', function () {
            include BZGOOGLE_DIR . 'views/page-google.php';
            exit;
        }, 0 ); // Priority 0 = before canonical redirect
    }
}, 20 );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-google' ) {
        include BZGOOGLE_DIR . 'views/page-google.php';
        exit;
    }
} );

/* ══════════════════════════════════════════════════════════════
 *  Register Intent Provider
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-google',
        'name' => 'BizGPT Tool — Google (Gmail, Calendar, Drive, Contacts)',

        /* ── Goal patterns ──────────────────────────────── */
        'patterns' => [
            /* Gmail */
            '/đọc mail|đọc email|xem mail|xem email|inbox|hộp thư|read mail|read email|check mail|check email/ui' => [
                'goal'    => 'gmail_list_messages',
                'label'   => 'Đọc email Gmail',
                'description' => 'Đọc / xem danh sách email trong hộp thư Gmail',
                'extract' => [ 'max_results', 'query' ],
            ],
            '/gửi mail|gửi email|send mail|send email|soạn mail|soạn email|compose mail/ui' => [
                'goal'    => 'gmail_send_message',
                'label'   => 'Gửi email Gmail',
                'description' => 'Soạn và gửi email qua Gmail',
                'extract' => [ 'to', 'subject', 'body' ],
            ],
            '/tóm tắt mail|tóm tắt email|summarize inbox|tóm tắt inbox|tóm tắt hộp thư/ui' => [
                'goal'    => 'gmail_summarize_inbox',
                'label'   => 'Tóm tắt inbox Gmail',
                'description' => 'Tóm tắt nội dung hộp thư Gmail bằng AI',
                'extract' => [ 'max_results' ],
            ],
            /* Calendar */
            '/xem lịch|đọc lịch|lịch hôm nay|lịch tuần|calendar|events|sự kiện/ui' => [
                'goal'    => 'calendar_list_events',
                'label'   => 'Xem lịch Google Calendar',
                'description' => 'Xem danh sách sự kiện trong Google Calendar',
                'extract' => [ 'time_min', 'time_max', 'max_results' ],
            ],
            '/tạo lịch|tạo sự kiện|thêm sự kiện|hẹn lịch|đặt lịch|create event|add event|book meeting/ui' => [
                'goal'    => 'calendar_create_event',
                'label'   => 'Tạo sự kiện Calendar',
                'description' => 'Tạo sự kiện mới trong Google Calendar',
                'extract' => [ 'title', 'start_time', 'end_time', 'description', 'attendees' ],
            ],
            /* Drive */
            '/xem file|đọc file|danh sách file|list file|drive|google drive|tìm file/ui' => [
                'goal'    => 'drive_list_files',
                'label'   => 'Xem file Google Drive',
                'description' => 'Xem danh sách file trong Google Drive',
                'extract' => [ 'query', 'max_results' ],
            ],
            /* Contacts */
            '/danh bạ|contacts|liên hệ|xem danh bạ|đọc danh bạ|list contacts/ui' => [
                'goal'    => 'contacts_list',
                'label'   => 'Xem danh bạ Google',
                'description' => 'Xem danh sách liên hệ trong Google Contacts',
                'extract' => [ 'max_results', 'query' ],
            ],
        ],

        /* ── Plans ──────────────────────────────────────── */
        'plans' => [
            'gmail_list_messages' => [
                'required_slots' => [],
                'optional_slots' => [
                    'max_results' => [ 'type' => 'number', 'default' => 10 ],
                    'query'       => [ 'type' => 'text', 'prompt' => 'Bạn muốn tìm email theo từ khóa gì? (bỏ qua nếu muốn xem tất cả)' ],
                ],
                'tool' => 'gmail_list_messages', 'ai_compose' => false,
                'slot_order' => [ 'query', 'max_results' ],
            ],
            'gmail_send_message' => [
                'required_slots' => [
                    'to'      => [ 'type' => 'text', 'prompt' => 'Gửi đến email nào?' ],
                    'subject' => [ 'type' => 'text', 'prompt' => 'Tiêu đề email là gì?' ],
                    'body'    => [ 'type' => 'text', 'prompt' => 'Nội dung email:' ],
                ],
                'optional_slots' => [],
                'tool' => 'gmail_send_message', 'ai_compose' => false,
                'slot_order' => [ 'to', 'subject', 'body' ],
            ],
            'gmail_summarize_inbox' => [
                'required_slots' => [],
                'optional_slots' => [
                    'max_results' => [ 'type' => 'number', 'default' => 20 ],
                ],
                'tool' => 'gmail_summarize_inbox', 'ai_compose' => false,
                'slot_order' => [ 'max_results' ],
            ],
            'calendar_list_events' => [
                'required_slots' => [],
                'optional_slots' => [
                    'time_min'    => [ 'type' => 'text', 'default' => '' ],
                    'time_max'    => [ 'type' => 'text', 'default' => '' ],
                    'max_results' => [ 'type' => 'number', 'default' => 10 ],
                ],
                'tool' => 'calendar_list_events', 'ai_compose' => false,
                'slot_order' => [ 'time_min', 'time_max' ],
            ],
            'calendar_create_event' => [
                'required_slots' => [
                    'title'      => [ 'type' => 'text', 'prompt' => 'Tên sự kiện là gì?' ],
                    'start_time' => [ 'type' => 'text', 'prompt' => 'Bắt đầu lúc nào? (ví dụ: 2026-03-20 10:00)' ],
                    'end_time'   => [ 'type' => 'text', 'prompt' => 'Kết thúc lúc nào? (ví dụ: 2026-03-20 11:00)' ],
                ],
                'optional_slots' => [
                    'description' => [ 'type' => 'text', 'default' => '' ],
                    'attendees'   => [ 'type' => 'text', 'prompt' => 'Email người tham dự (cách nhau bởi dấu phẩy):' ],
                ],
                'tool' => 'calendar_create_event', 'ai_compose' => false,
                'slot_order' => [ 'title', 'start_time', 'end_time', 'attendees' ],
            ],
            'drive_list_files' => [
                'required_slots' => [],
                'optional_slots' => [
                    'query'       => [ 'type' => 'text', 'prompt' => 'Tìm file theo tên hoặc từ khóa?' ],
                    'max_results' => [ 'type' => 'number', 'default' => 10 ],
                ],
                'tool' => 'drive_list_files', 'ai_compose' => false,
                'slot_order' => [ 'query' ],
            ],
            'contacts_list' => [
                'required_slots' => [],
                'optional_slots' => [
                    'max_results' => [ 'type' => 'number', 'default' => 20 ],
                    'query'       => [ 'type' => 'text' ],
                ],
                'tool' => 'contacts_list', 'ai_compose' => false,
                'slot_order' => [ 'query' ],
            ],
        ],

        /* ── Tools ──────────────────────────────────────── */
        'tools' => [
            'gmail_list_messages' => [
                'schema' => [
                    'description'  => 'Đọc danh sách email trong Gmail',
                    'input_fields' => [
                        'max_results' => [ 'required' => false, 'type' => 'number' ],
                        'query'       => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BZGoogle_Tools', 'gmail_list_messages' ],
            ],
            'gmail_send_message' => [
                'schema' => [
                    'description'  => 'Gửi email qua Gmail',
                    'input_fields' => [
                        'to'      => [ 'required' => true, 'type' => 'text' ],
                        'subject' => [ 'required' => true, 'type' => 'text' ],
                        'body'    => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BZGoogle_Tools', 'gmail_send_message' ],
            ],
            'gmail_summarize_inbox' => [
                'schema' => [
                    'description'  => 'Tóm tắt inbox Gmail bằng AI',
                    'input_fields' => [
                        'max_results' => [ 'required' => false, 'type' => 'number' ],
                    ],
                ],
                'callback' => [ 'BZGoogle_Tools', 'gmail_summarize_inbox' ],
            ],
            'calendar_list_events' => [
                'schema' => [
                    'description'  => 'Xem danh sách sự kiện Google Calendar',
                    'input_fields' => [
                        'time_min'    => [ 'required' => false, 'type' => 'text' ],
                        'time_max'    => [ 'required' => false, 'type' => 'text' ],
                        'max_results' => [ 'required' => false, 'type' => 'number' ],
                    ],
                ],
                'callback' => [ 'BZGoogle_Tools', 'calendar_list_events' ],
            ],
            'calendar_create_event' => [
                'schema' => [
                    'description'  => 'Tạo sự kiện mới trong Google Calendar',
                    'input_fields' => [
                        'title'       => [ 'required' => true, 'type' => 'text' ],
                        'start_time'  => [ 'required' => true, 'type' => 'text' ],
                        'end_time'    => [ 'required' => true, 'type' => 'text' ],
                        'description' => [ 'required' => false, 'type' => 'text' ],
                        'attendees'   => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BZGoogle_Tools', 'calendar_create_event' ],
            ],
            'drive_list_files' => [
                'schema' => [
                    'description'  => 'Xem danh sách file trong Google Drive',
                    'input_fields' => [
                        'query'       => [ 'required' => false, 'type' => 'text' ],
                        'max_results' => [ 'required' => false, 'type' => 'number' ],
                    ],
                ],
                'callback' => [ 'BZGoogle_Tools', 'drive_list_files' ],
            ],
            'contacts_list' => [
                'schema' => [
                    'description'  => 'Xem danh sách liên hệ trong Google Contacts',
                    'input_fields' => [
                        'max_results' => [ 'required' => false, 'type' => 'number' ],
                        'query'       => [ 'required' => false, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BZGoogle_Tools', 'contacts_list' ],
            ],
        ],

        /* ── Context ────────────────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $goals = [
                'gmail_list_messages'   => 'Đọc danh sách email Gmail',
                'gmail_send_message'    => 'Gửi email qua Gmail',
                'gmail_summarize_inbox' => 'Tóm tắt inbox Gmail bằng AI',
                'calendar_list_events'  => 'Xem sự kiện Google Calendar',
                'calendar_create_event' => 'Tạo sự kiện Google Calendar',
                'drive_list_files'      => 'Xem file Google Drive',
                'contacts_list'         => 'Xem danh bạ Google Contacts',
            ];
            $connected = BZGoogle_Token_Store::has_valid_token( get_current_blog_id(), $user_id );
            $status    = $connected ? 'Đã kết nối Google.' : '⚠️ Chưa kết nối Google — cần yêu cầu user kết nối trước.';
            return "Plugin: BizGPT Tool Google\n"
                . 'Mục tiêu: ' . ( $goals[ $goal ] ?? $goal ) . "\n"
                . "Trạng thái: {$status}\n";
        },
    ] );
} );
