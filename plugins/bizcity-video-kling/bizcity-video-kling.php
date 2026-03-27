<?php
/**
 * Plugin Name:       B-roll video
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-video-kling
 * Description:       Tạo video B-roll bằng Sora, Veo 3, SeeDance, Kling AI qua PiAPI Gateway — Image-to-Video cho Social Media. Chat để tạo video TikTok/Reels từ ảnh + prompt.
 * Short Description: Chat gửi ảnh + prompt → AI tạo video TikTok/Reels tự động.
 * Quick View:        🎬 Gửi ảnh + mô tả → AI tạo video → Nhận kết quả qua chat
 * Version:           2.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Icon Path:         /assets/icon.png
 * Role:              agent
 * Featured:          true
 * Credit:            100
 * Price:             1000000
 * Cover URI:         https://media.bizcity.vn/uploads/2026/02/hq7201.jpg
 * Template Page:     kling-video
 * Category:          entertainment, social media, video
 * Tags:              video, kling, sora, veo, tiktok, reels, image-to-video, AI tool, b-roll
 * Plan:              pro
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-video-kling
 *
 * === Giới thiệu ===
 * BizCity B-roll Video tích hợp đa model AI (Sora, Veo 3, SeeDance, Kling)
 * vào nền tảng Agentic qua PiAPI Gateway. Gửi ảnh + prompt qua chat, AI tạo
 * video TikTok/Reels chất lượng cao.
 *
 * === Tính năng chính ===
 * • Tạo video Image-to-Video từ ảnh + prompt
 * • Hỗ trợ TTS voiceover, ghép nhạc nền
 * • Multi-segment chain — ghép nhiều đoạn video
 * • FFmpeg post-production tự động
 * • Async cron poll + push kết quả về chat
 * • Giao diện Video Studio tại /kling-video/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • PiAPI Gateway key (cho Kling/Sora/Veo)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine.
 * Truy cập /kling-video/ để mở Video Studio.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>B-roll video</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

// Constants
define( 'BIZCITY_VIDEO_KLING_VERSION', '2.0.0' );
define( 'BIZCITY_VIDEO_KLING_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIZCITY_VIDEO_KLING_URL', plugin_dir_url( __FILE__ ) );
define( 'BIZCITY_VIDEO_KLING_SLUG', 'kling-video' );

// Load bootstrap
require_once BIZCITY_VIDEO_KLING_DIR . 'bootstrap.php';

// Load tool callbacks (needed for intent engine)
require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-tools-kling.php';

/* ══════════════════════════════════════════════════════════════
 *  PILLAR 2 — Register Intent Provider
 *  Primary tool: create_video (ảnh + prompt → video)
 *  Secondary: check_video_status, list_my_videos
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    if ( ! function_exists( 'bizcity_intent_register_plugin' ) ) return;

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'video-kling',
        'name' => 'BizCity Video B-Roll (Tạo video AI từ ảnh)',

        /* ── Goal patterns (Router) ─────────────────────────
         * ORDER MATTERS: specific patterns first, primary last
         */
        'patterns' => [
            '/xem trạng thái video|kiểm tra video|video.*status|check.*video|video đang xử lý/ui' => [
                'goal'        => 'check_video_status',
                'label'       => 'Kiểm tra trạng thái video',
                'description' => 'Xem tiến trình video đang xử lý hoặc đã hoàn thành',
                'extract'     => [ 'job_id' ],
            ],
            '/danh sách video|list.*video|video.*gần đây|video của tôi|my.*video/ui' => [
                'goal'        => 'list_my_videos',
                'label'       => 'Xem danh sách video',
                'description' => 'Liệt kê các video đã tạo gần đây',
                'extract'     => [ 'limit' ],
            ],
            '/tạo video|make video|create video|quay video|video.*từ.*ảnh|image.*to.*video|ảnh.*thành.*video|render video|làm video|video kling|video sora|video veo|video seedance|sinh video|b[- ]?r[o]+l[l]?|video nền/ui' => [
                'goal'        => 'create_video',
                'label'       => 'Tạo video từ ảnh',
                'description' => 'Tạo video TikTok/Reels bằng AI từ ảnh + prompt. Hỗ trợ Kling, SeeDance, Sora, Veo. TTS voiceover, chọn thời lượng và tỷ lệ',
                'extract'     => [ 'message', 'image_url', 'duration', 'aspect_ratio', 'voiceover_text', 'model' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ─────────────── */
        'plans' => [
            'create_video' => [
                'required_slots' => [],
                'optional_slots' => [
                    'image_url' => [
                        'type'    => 'image',
                        'prompt'  => 'Gửi ảnh gốc để AI tạo video từ ảnh nhé! (hoặc gõ "bỏ qua" nếu chỉ dùng prompt)',
                        'default' => '',
                    ],
                    'message' => [
                        'type'    => 'text',
                        'prompt'  => 'Mô tả nội dung video (prompt) nhé! 🎬 Ví dụ: "Cô gái đi dạo trên bãi biển hoàng hôn" (để trống nếu chỉ dùng ảnh)',
                        'default' => '',
                    ],
                    'duration' => [
                        'type'    => 'choice',
                        'choices' => [
                            '5'  => '5 giây',
                            '10' => '10 giây',
                            '15' => '15 giây',
                            '20' => '20 giây',
                            '30' => '30 giây',
                        ],
                        'prompt'  => 'Thời lượng video (giây)?',
                        'default' => '5',
                    ],
                    'aspect_ratio' => [
                        'type'    => 'choice',
                        'choices' => [
                            '9:16' => '9:16 (TikTok/Reels dọc)',
                            '16:9' => '16:9 (YouTube ngang)',
                            '1:1'  => '1:1 (Vuông)',
                        ],
                        'prompt'  => 'Video dọc (TikTok), ngang (YouTube), hay vuông?',
                        'default' => '9:16',
                    ],
                    'voiceover_text' => [
                        'type'    => 'text',
                        'prompt'  => 'Lời thoại/voiceover cho video (để trống nếu không cần)',
                        'default' => '',
                    ],
                    'model' => [
                        'type'    => 'choice',
                        'choices' => [
                            '2.6|pro'      => 'Kling 2.6 Pro',
                            '2.6|std'      => 'Kling 2.6 Standard',
                            '2.5|pro'      => 'Kling 2.5 Pro',
                            '1.6|pro'      => 'Kling 1.6 Pro',
                            'seedance:1.0' => 'SeeDance 1.0',
                            'sora:v1'      => 'Sora v1',
                            'veo:3'        => 'Veo 3',
                        ],
                        'default' => '2.6|pro',
                    ],
                ],
                'tool'       => 'create_video',
                'ai_compose' => false,
                'slot_order' => [ 'image_url', 'message', 'duration' ],
            ],
            'check_video_status' => [
                'required_slots' => [],
                'optional_slots' => [
                    'job_id' => [ 'type' => 'text', 'prompt' => 'ID video cần kiểm tra? (để trống = video gần nhất)', 'default' => '' ],
                ],
                'tool'       => 'check_video_status',
                'ai_compose' => false,
            ],
            'list_my_videos' => [
                'required_slots' => [],
                'optional_slots' => [
                    'limit' => [ 'type' => 'choice', 'choices' => [ '3' => '3 video', '5' => '5 video', '10' => '10 video' ], 'default' => '5' ],
                ],
                'tool'       => 'list_my_videos',
                'ai_compose' => false,
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'create_video' => [
                'schema' => [
                    'description'  => 'Tạo video TikTok/Reels bằng Kling, Veo3, Sora, SeeDance AI từ ảnh + prompt. Tự động xử lý multi-segment, TTS voiceover, FFmpeg ghép.',
                    'input_fields' => [
                        'message'        => [ 'required' => true,  'type' => 'text',   'description' => 'Mô tả nội dung video (prompt)' ],
                        'image_url'      => [ 'required' => false, 'type' => 'image',  'description' => 'URL ảnh gốc để tạo video' ],
                        'duration'       => [ 'required' => false, 'type' => 'choice', 'description' => 'Thời lượng video (giây): 5, 10, 15, 20, 30' ],
                        'aspect_ratio'   => [ 'required' => false, 'type' => 'choice', 'description' => 'Tỷ lệ: 9:16 (dọc TikTok), 16:9 (ngang), 1:1 (vuông)' ],
                        'voiceover_text' => [ 'required' => false, 'type' => 'text',   'description' => 'Lời thoại TTS cho video' ],
                        'model'          => [ 'required' => false, 'type' => 'choice', 'description' => 'Video AI model: Kling (2.6|pro, 2.6|std, 2.5|pro, 1.6|pro), SeeDance (seedance:1.0), Sora (sora:v1), Veo (veo:3)' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Kling', 'create_video' ],
            ],
            'check_video_status' => [
                'schema' => [
                    'description'  => 'Kiểm tra trạng thái video đang xử lý hoặc đã hoàn thành',
                    'input_fields' => [
                        'job_id' => [ 'required' => false, 'type' => 'text', 'description' => 'Job ID cần kiểm tra (mặc định: video gần nhất)' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Kling', 'check_video_status' ],
            ],
            'list_my_videos' => [
                'schema' => [
                    'description'  => 'Liệt kê video đã tạo gần đây với trạng thái và link xem',
                    'input_fields' => [
                        'limit' => [ 'required' => false, 'type' => 'choice', 'description' => 'Số lượng video hiển thị (3, 5, 10)' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Kling', 'list_my_videos' ],
            ],
        ],

        /* ── Context ──────────────────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $goals_map = [
                'create_video'       => 'Tạo video TikTok/Reels bằng Kling, Veo3, Sora, SeeDance AI từ ảnh + prompt',
                'check_video_status' => 'Kiểm tra trạng thái video đang xử lý',
                'list_my_videos'     => 'Xem danh sách video đã tạo gần đây',
            ];
            $ctx  = "Plugin: BizCity Video B-Roll (AI Video Generator)\n";
            $ctx .= 'Mục tiêu: ' . ( $goals_map[ $goal ] ?? $goal ) . "\n";
            $ctx .= "Hỗ trợ: tạo video từ ảnh, text-to-video, TTS voiceover, multi-segment chain.\n";
            $ctx .= "Mặc định: video dọc 9:16 (TikTok), 10 giây, model Kling v2.6 Pro. Hỗ trợ thêm: SeeDance (seedance:1.0), Sora (sora:v1), Veo (veo:3).\n";
            $ctx .= "Khi tạo video, AI sẽ xử lý async và gửi kết quả về chat khi hoàn thành.\n";
            return $ctx;
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  PILLAR 1 — Profile View Route: /kling-video/
 *  Touch Bar clicks → postMessage to parent chat
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^kling-video/?$', 'index.php?bizcity_agent_page=kling-video', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'kling-video' ) {
        include BIZCITY_VIDEO_KLING_DIR . 'views/page-kling-profile.php';
        exit;
    }
} );

/**
 * Activation hook
 */
register_activation_hook( __FILE__, function() {
    // Set default options
    if ( ! get_option( 'bizcity_video_kling_api_key' ) ) {
        add_option( 'bizcity_video_kling_api_key', '' );
    }
    if ( ! get_option( 'bizcity_video_kling_endpoint' ) ) {
        add_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
    }
    if ( ! get_option( 'bizcity_video_kling_default_model' ) ) {
        add_option( 'bizcity_video_kling_default_model', '2.6|pro' );
    }
    if ( ! get_option( 'bizcity_video_kling_default_duration' ) ) {
        add_option( 'bizcity_video_kling_default_duration', 10 );
    }
    if ( ! get_option( 'bizcity_video_kling_default_aspect_ratio' ) ) {
        add_option( 'bizcity_video_kling_default_aspect_ratio', '9:16' );
    }

    // Create database tables
    require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-database.php';
    BizCity_Video_Kling_Database::create_tables();

    // Set DB version
    update_option( 'bizcity_video_kling_db_version', '2.0.0' );

    // Flush rewrite rules
    flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
