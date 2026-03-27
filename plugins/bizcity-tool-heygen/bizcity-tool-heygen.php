<?php
/**
 * Plugin Name:       Video Avatar by HeyGen
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-heygen
 * Description:       Tạo video lipsync bằng HeyGen AI — quản lý nhân vật AI (voice clone + avatar), nhập script → video avatar chuyên nghiệp. Chat để tạo video avatar tự động.
 * Short Description: Chọn nhân vật AI + nhập script → video avatar lipsync tự động.
 * Quick View:        🎭 Chọn nhân vật → nhập script → AI tạo video avatar lipsync
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Icon Path:         /assets/icon.png
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/heygen-11.jpg
 * Template Page:     tool-heygen
 * Category:          video, avatar, AI
 * Tags:              heygen, avatar, lipsync, video, voice clone, AI tool
 * Plan:              free
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-heygen
 *
 * === Giới thiệu ===
 * BizCity Tool HeyGen tích hợp HeyGen API vào nền tảng Agentic.
 * Quản lý nhân vật AI: clone voice, avatar, persona prompt.
 * Tạo video lipsync hằng ngày từ script — async cron poll + webchat push.
 *
 * === Tính năng chính ===
 * • Quản lý nhân vật AI: avatar, voice clone, persona prompt
 * • Tạo video lipsync từ script bằng AI
 * • Async cron poll — tự động kiểm tra trạng thái job
 * • Chat push — gửi kết quả video về chat khi hoàn thành
 * • 4 tab: Create, Monitor, Chat, Settings
 * • Giao diện HeyGen Studio tại /tool-heygen/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • HeyGen API key (qua BizCity Gateway hoặc trực tiếp)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine.
 * Truy cập /tool-heygen/ để mở HeyGen Studio.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Video Avatar by HeyGen</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

// Constants
define( 'BIZCITY_TOOL_HEYGEN_VERSION', '1.0.0' );
define( 'BIZCITY_TOOL_HEYGEN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIZCITY_TOOL_HEYGEN_URL', plugin_dir_url( __FILE__ ) );
define( 'BIZCITY_TOOL_HEYGEN_SLUG', 'tool-heygen' );

// Load bootstrap
require_once BIZCITY_TOOL_HEYGEN_DIR . 'bootstrap.php';

// Load tool callbacks (needed for intent engine)
require_once BIZCITY_TOOL_HEYGEN_DIR . 'includes/class-tools-heygen.php';

/* ══════════════════════════════════════════════════════════════
 *  PILLAR 2 — Register Intent Provider
 *  Primary tool: create_lipsync_video
 *  Secondary: list_characters, check_video_status
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    if ( ! function_exists( 'bizcity_intent_register_plugin' ) ) return;

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-heygen',
        'name' => 'BizCity Video Avatar by HeyGen (Tạo video lipsync từ nhân vật AI)',

        /* ── Goal patterns (Router) ─────────────────────────
         * ORDER MATTERS: specific patterns first, primary last
         */
        'patterns' => [
            '/danh sách nhân vật|list.*character|nhân vật ai|xem nhân vật|character.*list/ui' => [
                'goal'        => 'list_characters',
                'label'       => 'Danh sách nhân vật AI',
                'description' => 'Xem các nhân vật AI đã cấu hình (voice, avatar, persona)',
                'extract'     => [],
            ],
            '/xem trạng thái.*heygen|kiểm tra.*heygen|heygen.*status|check.*heygen|video avatar.*status/ui' => [
                'goal'        => 'check_video_status',
                'label'       => 'Kiểm tra trạng thái video avatar',
                'description' => 'Xem tiến trình video lipsync đang xử lý hoặc đã hoàn thành',
                'extract'     => [ 'job_id' ],
            ],
            '/tạo video.*avatar|video.*lipsync|tạo.*lipsync|heygen|video.*nhân vật|video.*ai avatar|lip.*sync|tạo video.*heygen|generate.*avatar.*video|video.*clone.*voice|tạo avatar/ui' => [
                'goal'        => 'create_lipsync_video',
                'label'       => 'Tạo video avatar lipsync',
                'description' => 'Chọn nhân vật AI + nhập lời thoại → tạo video lipsync bằng HeyGen. Hỗ trợ text-to-speech và audio mode.',
                'extract'     => [ 'script', 'character_id', 'mode' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ─────────────── */
        'plans' => [
            'create_lipsync_video' => [
                'required_slots' => [
                    'script' => [
                        'type'   => 'text',
                        'prompt' => 'Nhập lời thoại / script cho nhân vật AI nhé! 🎬 Ví dụ: "Xin chào mọi người, hôm nay mình sẽ giới thiệu..."',
                    ],
                ],
                'optional_slots' => [
                    'character_id' => [
                        'type'    => 'choice',
                        'choices' => [], // Dynamic — populated at runtime from DB
                        'prompt'  => 'Chọn nhân vật AI (để trống = nhân vật đầu tiên)',
                        'default' => '',
                    ],
                    'mode' => [
                        'type'    => 'choice',
                        'choices' => [
                            'text'  => 'Text → TTS → Lipsync (mặc định)',
                            'audio' => 'Audio Upload → Lipsync',
                        ],
                        'prompt'  => 'Chế độ tạo video?',
                        'default' => 'text',
                    ],
                ],
                'tool'       => 'create_lipsync_video',
                'ai_compose' => false,
                'slot_order' => [ 'script', 'character_id' ],
            ],
            'list_characters' => [
                'required_slots' => [],
                'optional_slots' => [],
                'tool'       => 'list_characters',
                'ai_compose' => false,
            ],
            'check_video_status' => [
                'required_slots' => [],
                'optional_slots' => [
                    'job_id' => [
                        'type'    => 'text',
                        'prompt'  => 'ID video cần kiểm tra? (để trống = video gần nhất)',
                        'default' => '',
                    ],
                ],
                'tool'       => 'check_video_status',
                'ai_compose' => false,
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'create_lipsync_video' => [
                'schema' => [
                    'description'  => 'Tạo video avatar lipsync bằng HeyGen — chọn nhân vật AI (voice + avatar) + nhập script → video lipsync. Hỗ trợ text-to-speech và audio mode.',
                    'input_fields' => [
                        'script'       => [ 'required' => true,  'type' => 'text',   'description' => 'Lời thoại / script cho nhân vật AI' ],
                        'character_id' => [ 'required' => false, 'type' => 'choice', 'description' => 'ID nhân vật AI (để trống = nhân vật đầu tiên)' ],
                        'mode'         => [ 'required' => false, 'type' => 'choice', 'description' => 'Chế độ: text (TTS trong HeyGen) hoặc audio (upload audio)' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_HeyGen', 'create_lipsync_video' ],
            ],
            'list_characters' => [
                'schema' => [
                    'description'  => 'Liệt kê nhân vật AI đã cấu hình với voice, avatar, persona prompt',
                    'input_fields' => [],
                ],
                'callback' => [ 'BizCity_Tool_HeyGen', 'list_characters' ],
            ],
            'check_video_status' => [
                'schema' => [
                    'description'  => 'Kiểm tra trạng thái video lipsync đang xử lý hoặc đã hoàn thành',
                    'input_fields' => [
                        'job_id' => [ 'required' => false, 'type' => 'text', 'description' => 'Job ID cần kiểm tra (mặc định: video gần nhất)' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_HeyGen', 'check_video_status' ],
            ],
        ],

        /* ── Context ──────────────────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $goals_map = [
                'create_lipsync_video' => 'Tạo video avatar lipsync bằng HeyGen từ nhân vật AI + script',
                'list_characters'      => 'Xem danh sách nhân vật AI đã cấu hình',
                'check_video_status'   => 'Kiểm tra trạng thái video lipsync đang xử lý',
            ];
            $ctx  = "Plugin: BizCity Video Avatar by HeyGen (AI Lipsync Generator)\n";
            $ctx .= 'Mục tiêu: ' . ( $goals_map[ $goal ] ?? $goal ) . "\n";
            $ctx .= "Hỗ trợ: tạo video lipsync từ nhân vật AI (voice cloned + avatar), text-to-speech mode hoặc audio upload mode.\n";
            $ctx .= "Admin cấu hình nhân vật 1 lần (clone voice, avatar, persona). User chỉ cần chọn nhân vật + nhập script.\n";
            $ctx .= "Khi tạo video, HeyGen sẽ xử lý async và gửi kết quả về chat khi hoàn thành.\n";
            return $ctx;
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  PILLAR 1 — Profile View Route: /tool-heygen/
 *  4-Tab: Create + Monitor + Chat + Settings
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-heygen/?$', 'index.php?bizcity_agent_page=tool-heygen', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-heygen' ) {
        include BIZCITY_TOOL_HEYGEN_DIR . 'views/page-heygen-profile.php';
        exit;
    }
} );

/* ══════════════════════════════════════════════════════════════
 *  Activation & Deactivation
 * ══════════════════════════════════════════════════════════════ */
register_activation_hook( __FILE__, function() {
    // Set default options
    if ( ! get_option( 'bizcity_tool_heygen_api_key' ) ) {
        add_option( 'bizcity_tool_heygen_api_key', '' );
    }
    if ( ! get_option( 'bizcity_tool_heygen_endpoint' ) ) {
        add_option( 'bizcity_tool_heygen_endpoint', 'https://api.heygen.com' );
    }
    if ( ! get_option( 'bizcity_tool_heygen_default_mode' ) ) {
        add_option( 'bizcity_tool_heygen_default_mode', 'text' );
    }

    // Create database tables
    require_once BIZCITY_TOOL_HEYGEN_DIR . 'includes/class-database.php';
    BizCity_Tool_HeyGen_Database::create_tables();

    // Set DB version
    update_option( 'bizcity_tool_heygen_db_version', '1.0.0' );

    // Flush rewrite rules
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );
