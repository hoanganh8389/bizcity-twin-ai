<?php
/**
 * Plugin Name:       Tạo ảnh AI chuyên nghiệp — BizCity Tool Image
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-image
 * Description:       Tạo ảnh AI chuyên nghiệp từ văn bản bằng FLUX.2, Gemini, Seedream, GPT-5 Image. Kho prompt mẫu, lịch sử tạo ảnh, chia sẻ và upload Media Library.
 * Short Description: Chat gửi prompt → AI tạo ảnh (FLUX.2/Gemini/GPT-5) → Lưu / Chia sẻ / Dùng trong pipeline.
 * Quick View:        🎨 Prompt → AI tạo ảnh FLUX.2/Gemini → Lưu Media / Pipeline
 * Version:           2.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Icon Path:         /assets/icon.png
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/6-fun-and-easy-ways-to-generate-ai-images-for-free-with-flux-11.jpg
 * Template Page:     tool-image
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-image
 * Plan:              free
 * Category:          image, ai, creative, tạo ảnh
 * Tags:              image, FLUX.2, Gemini, Seedream, GPT-5 Image, tạo ảnh, creative, AI tool, generative
 *
 * === Giới thiệu ===
 * BizCity Tool Image là bộ công cụ tạo ảnh AI chuyên nghiệp, tích hợp đa
 * model hàng đầu: FLUX.2, Gemini Imagen, Seedream, GPT-5 Image. Gửi prompt
 * qua chat → AI tạo ảnh → lưu Media Library hoặc dùng trong pipeline.
 *
 * === Tính năng chính ===
 * • Tạo ảnh từ prompt bằng nhiều model: FLUX.2, Gemini, Seedream, GPT-5 Image
 * • Kho prompt mẫu theo chủ đề (sản phẩm, quảng cáo, ảnh bìa, minh họa…)
 * • Lịch sử tạo ảnh — xem lại, tải lại, xóa
 * • Upload vào WordPress Media Library tự động
 * • Pipeline-ready: output image_url cho bước tiếp theo (đăng bài, tạo video…)
 * • Giao diện Image Studio tại /tool-image/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • BizCity Gateway hoặc OpenRouter API
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine.
 * Truy cập /tool-image/ để mở Image Studio.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity Tool — Image</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

/* ═══════════════════════════════════════════════
   CONSTANTS
   ═══════════════════════════════════════════════ */
define( 'BZTIMG_DIR',           plugin_dir_path( __FILE__ ) );
define( 'BZTIMG_URL',           plugin_dir_url( __FILE__ ) );
define( 'BZTIMG_VERSION',       '3.7.1' );
define( 'BZTIMG_SCHEMA_VERSION','6.2' );   // Bump this whenever DB schema changes
define( 'BZTIMG_SLUG',          'tool-image' );

/* ═══════════════════════════════════════════════
   AUTOLOAD INCLUDES
   ═══════════════════════════════════════════════ */
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

require_once BZTIMG_DIR . 'includes/install.php';
require_once BZTIMG_DIR . 'includes/class-tools-image.php';
require_once BZTIMG_DIR . 'includes/class-ajax-image.php';
require_once BZTIMG_DIR . 'includes/admin-menu.php';
require_once BZTIMG_DIR . 'includes/integration-chat.php';
require_once BZTIMG_DIR . 'includes/class-intent-provider.php';

/* Phase 3 — Template Library */
require_once BZTIMG_DIR . 'includes/class-template-category-manager.php';
require_once BZTIMG_DIR . 'includes/class-template-manager.php';
require_once BZTIMG_DIR . 'includes/class-rest-api-templates.php';
require_once BZTIMG_DIR . 'includes/seed-templates.php';

/* Phase 3.2 — Design Editor Projects (CRUD for editor saves) */
require_once BZTIMG_DIR . 'includes/class-rest-api-projects.php';

/* Phase 4 — Design Editor Assets (shapes, frames, fonts, text presets) */
require_once BZTIMG_DIR . 'includes/class-rest-api-editor-assets.php';
require_once BZTIMG_DIR . 'includes/seed-editor-assets.php';

/* Phase 3.6 — AI Template Generator (Vision + Variation Engine) */
require_once BZTIMG_DIR . 'includes/class-ai-template-generator.php';

/* Phase 4.1 — Full-page Design Editor at /canva/ */
require_once BZTIMG_DIR . 'includes/class-canva-page.php';
BizCity_Canva_Page::init();

/* Phase 3.8 — Profile Studio at /profile-studio/ */
require_once BZTIMG_DIR . 'includes/class-profile-studio-page.php';
BizCity_Profile_Studio_Page::init();

/* Canvas Bridge — generate_image → Canvas Adapter handoff */
require_once BZTIMG_DIR . 'includes/class-canvas-bridge-image.php';
add_filter( 'bizcity_canvas_handlers', [ 'BizCity_Canvas_Bridge_Image', 'register_handlers' ] );

/* Phase 3.4 — Character Studio: Model Manager */
if ( file_exists( BZTIMG_DIR . 'includes/class-model-manager.php' ) ) {
    require_once BZTIMG_DIR . 'includes/class-model-manager.php';
    BizCity_Model_Manager::boot();
}

BizCity_REST_API_Templates::init();
BizCity_REST_API_Projects::init();
BizCity_REST_API_Editor_Assets::init();

/**
 * Re-authenticate from cookie for plugin REST routes.
 * WordPress REST API requires nonce+cookie for cookie-based auth.
 * In iframe context, nonce can become stale (Cloudflare cache, Rocket Loader).
 * This filter re-validates the logged_in cookie directly for our routes only.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    $route = $request->get_route();
    if ( strpos( $route, '/bztool-image/v1/' ) === 0 || strpos( $route, '/image-editor/v1/' ) === 0 ) {
        if ( ! get_current_user_id() ) {
            $user_id = wp_validate_auth_cookie( '', 'logged_in' );
            if ( $user_id ) {
                wp_set_current_user( $user_id );
            }
        }
    }
    return $result;
}, 10, 3 );

/* ═══════════════════════════════════════════════
   PILLAR 2 — Intent Provider (bizcity_intent_register_plugin)
   Primary tool: generate_image
   Secondary: list_my_images, generate_product_image
   ═══════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    if ( ! function_exists( 'bizcity_intent_register_plugin' ) ) {
        if ( class_exists( 'BizCity_Intent_Provider' ) ) {
            $registry->register( new BizCity_Tool_Image_Intent_Provider() );
        }
        return;
    }

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-image',
        'name' => 'BizCity Tool — Image AI (FLUX.2 / Gemini / GPT-5)',

        /* ── Goal patterns (Router) ───────────────────── */
        'patterns' => [
            /* List images */
            '/danh sách ảnh|list.*image|ảnh.*gần đây|ảnh.*của tôi|my.*image|xem.*ảnh.*đã tạo/ui' => [
                'goal'        => 'list_my_images',
                'label'       => 'Xem danh sách ảnh đã tạo',
                'description' => 'Liệt kê các ảnh AI đã tạo gần đây',
                'extract'     => [ 'limit' ],
            ],
            /* Primary: Generate image — auto-detect purpose from prompt */
            '/tạo ảnh|vẽ ảnh|tạo hình|generate image|tạo hình ảnh|vẽ|minh họa|sinh ảnh|image.*ai|ảnh.*ai|flux.*ảnh|dall.?e|gpt.*image|render.*ảnh|tao anh|ve anh|ảnh.*flux|ảnh.*gemini|seedream|ảnh sản phẩm|product.*image|ảnh.*bán hàng|chân dung|portrait/ui' => [
                'goal'        => 'generate_image',
                'label'       => 'Tạo ảnh AI',
                'description' => 'Tạo ảnh AI — tự nhận diện mục đích (sản phẩm / chân dung / phong cảnh / social / ẩm thực) và enhance prompt phù hợp',
                'extract'     => [ 'prompt', 'purpose', 'size', 'style' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ──────────── */
        'plans' => [
            'generate_image' => [
                'required_slots' => [
                    'prompt' => [
                        'type'   => 'text',
                        'prompt' => '🎨 Mô tả ảnh bạn muốn tạo (chi tiết phong cách, màu sắc, bố cục):',
                    ],
                ],
                'optional_slots' => [
                    'image_url' => [
                        'type'    => 'image',
                        'prompt'  => '📷 Gửi ảnh tham chiếu hoặc dán link ảnh (hoặc bỏ qua):',
                        'default' => '',
                    ],
                    'purpose' => [
                        'type'    => 'choice',
                        'choices' => [
                            'product'   => '📦 Sản phẩm (studio, styled scene, luxury)',
                            'portrait'  => '👤 Chân dung (bokeh, studio)',
                            'landscape' => '🌄 Phong cảnh (golden hour, vivid)',
                            'social'    => '📱 Social Media (bold, trending)',
                            'food'      => '🍜 Ẩm thực (flat lay, warm tones)',
                            'general'   => '🎨 Khác / Tự do',
                        ],
                        'prompt'  => 'Bạn muốn tạo ảnh cho mục đích gì?',
                        'default' => '',
                    ],
                    'size' => [
                        'type'    => 'choice',
                        'choices' => [
                            '1024x1024' => '1:1 Vuông',
                            '1024x1536' => '2:3 Dọc',
                            '1536x1024' => '3:2 Ngang',
                            '768x1344'  => '9:16 Story',
                            '1344x768'  => '16:9 Landscape',
                        ],
                        'prompt'  => 'Kích thước ảnh:',
                        'default' => '1024x1024',
                    ],
                    'style' => [
                        'type'    => 'choice',
                        'choices' => [
                            'auto'          => 'Tự động',
                            'photorealistic' => 'Chân thực',
                            'artistic'       => 'Nghệ thuật',
                            'anime'          => 'Anime',
                            'illustration'   => 'Minh họa',
                        ],
                        'prompt'  => 'Phong cách:',
                        'default' => 'auto',
                    ],
                ],
                'tool'       => 'generate_image',
                'ai_compose' => false,
                'slot_order' => [ 'prompt', 'purpose', 'image_url' ],
            ],
            'list_my_images' => [
                'required_slots' => [],
                'optional_slots' => [
                    'limit' => [ 'type' => 'choice', 'choices' => [ '5' => '5 ảnh', '10' => '10 ảnh', '20' => '20 ảnh' ], 'default' => '10' ],
                ],
                'tool'       => 'list_my_images',
                'ai_compose' => false,
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'generate_image' => [
                'schema' => [
                    'description'    => 'Tạo ảnh AI — tự nhận diện mục đích (sản phẩm / chân dung / phong cảnh / social / ẩm thực) và enhance prompt phù hợp. Model mặc định từ cài đặt.',
                    'accepts_skill'  => true,
                    'content_tier'   => 1,
                    'studio_enabled' => true,
                    'tool_type'      => 'image',
                    'input_fields'   => [
                        'prompt'    => [ 'required' => true,  'type' => 'text',   'description' => 'Mô tả chi tiết ảnh cần tạo' ],
                        'purpose'   => [ 'required' => false, 'type' => 'choice', 'description' => 'Mục đích: product, portrait, landscape, social, food, general (tự detect nếu bỏ trống)' ],
                        'image_url' => [ 'required' => false, 'type' => 'image',  'description' => 'URL ảnh tham chiếu (img2img)' ],
                        'size'      => [ 'required' => false, 'type' => 'choice', 'description' => 'Kích thước: 1024x1024, 1024x1536, 1536x1024, 768x1344, 1344x768' ],
                        'style'     => [ 'required' => false, 'type' => 'choice', 'description' => 'Phong cách: auto, photorealistic, artistic, anime, illustration' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Image', 'generate_image' ],
            ],
            'list_my_images' => [
                'schema' => [
                    'description'  => 'Xem danh sách ảnh AI đã tạo gần đây với link xem và trạng thái',
                    'input_fields' => [
                        'limit' => [ 'required' => false, 'type' => 'choice', 'description' => 'Số lượng ảnh (5, 10, 20)' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Image', 'list_my_images' ],
            ],
        ],

        /* ── Context ──────────────────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $model = get_option( 'bztimg_default_model', 'flux-pro' );
            $ctx  = "Plugin: BizCity Tool Image (AI Image Generator)\n";
            $ctx .= "Model mặc định: {$model} — KHÔNG cần hỏi user chọn model.\n";
            $ctx .= "Khi user gửi prompt tạo ảnh:\n";
            $ctx .= "  1. Tự detect mục đích từ prompt (product/portrait/landscape/social/food)\n";
            $ctx .= "  2. Nếu detect được → hỏi thêm ảnh tham chiếu (upload/URL) → rồi tạo ảnh\n";
            $ctx .= "  3. Nếu KHÔNG rõ mục đích → hỏi: 'Bạn muốn tạo ảnh cho mục đích gì?'\n";
            $ctx .= "  4. Với product/portrait: LUÔN hỏi ảnh tham chiếu trước khi tạo (user có thể bỏ qua)\n";
            $ctx .= "Purpose styles:\n";
            $ctx .= "  - product: Studio-grade product photo, styled environment with props, cinematic lighting, luxury aesthetic, magazine-quality\n";
            $ctx .= "  - portrait: Professional portrait, soft natural lighting, shallow DOF, bokeh, studio quality\n";
            $ctx .= "  - landscape: Golden hour, vivid colors, wide angle, dramatic sky\n";
            $ctx .= "  - social: Bold colors, modern typography, trending aesthetic\n";
            $ctx .= "  - food: Flat lay, warm tones, natural light, appetizing styling\n";
            $ctx .= "Output image_url dùng trong pipeline: write_article, post_facebook, create_product.\n";
            return $ctx;
        },

        /* ── System instructions ── */
        'instructions' => function ( $goal ) {
            return 'Bạn là trợ lý tạo ảnh AI chuyên nghiệp. '
                 . 'Khi nhận prompt tạo ảnh: tự nhận diện mục đích (sản phẩm / chân dung / phong cảnh / social / ẩm thực) rồi enhance prompt. '
                 . 'Sau khi xác định mục đích, LUÔN hỏi user gửi ảnh tham chiếu (upload hoặc dán URL) trước khi tạo — user có thể bỏ qua. '
                 . 'Không hỏi chọn model — dùng model mặc định. '
                 . 'Trả lời tiếng Việt, thân thiện, gợi ý prompt chi tiết.';
        },
    ] );
} );

/* ══════════════════════════════════════════════
 *  Sync studio pages → bizcity_skills (Universal Plugin Router)
 *
 *  Profile Studio + Product Studio become virtual skill rows so they
 *  appear in the SlashDialog, are discoverable by Pre-Rules, and feed
 *  tool_refs to the Smart Classifier.
 * ══════════════════════════════════════════════ */
add_action( 'init', 'bztimg_sync_studio_skills', 25 );

/**
 * Force re-sync on next load (call after changing studio config).
 */
function bztimg_invalidate_skill_sync(): void {
	delete_transient( 'bztimg_skill_sync_hash' );
}

function bztimg_sync_studio_skills(): void {
	if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
		return;
	}

	/* ── Skip if already synced for this version ── */
	$fingerprint = md5( BZTIMG_VERSION . '::studio_skills::v2' );
	if ( get_transient( 'bztimg_skill_sync_hash' ) === $fingerprint ) {
		return;
	}

	$db     = BizCity_Skill_Database::instance();
	$synced = [];

	/* ══════════════════════════════════════════
	 *  Product Studio categories → skills
	 *  Each category = one skill, launches /product-studio/?tool={slug}
	 * ══════════════════════════════════════════ */
	$product_categories = [
		'background' => [
			'title'    => 'Thay nền sản phẩm',
			'desc'     => 'Xóa & thay nền sản phẩm bằng AI: nền studio, nền tùy chỉnh, nền phong cảnh',
			'triggers' => [ 'thay nền', 'background', 'xóa nền', 'nền sản phẩm', 'remove background', 'change background' ],
			'icon'     => '🖼️',
		],
		'on-hand' => [
			'title'    => 'Ảnh sản phẩm trên tay',
			'desc'     => 'Tạo ảnh sản phẩm trên tay người mẫu AI, phong cách lifestyle',
			'triggers' => [ 'trên tay', 'on hand', 'on-hand', 'cầm sản phẩm', 'product on hand', 'lifestyle' ],
			'icon'     => '🤳',
		],
		'concepts' => [
			'title'    => 'Ý tưởng sản phẩm',
			'desc'     => 'Tạo concept art & ý tưởng thiết kế sản phẩm bằng AI',
			'triggers' => [ 'concept', 'ý tưởng', 'concepts', 'thiết kế sản phẩm', 'product concept', 'idea' ],
			'icon'     => '💡',
		],
		'apparel-tryon' => [
			'title'    => 'Thử quần áo AI',
			'desc'     => 'Virtual try-on: thử quần áo, phụ kiện lên người mẫu AI',
			'triggers' => [ 'thử đồ', 'try on', 'thử quần áo', 'apparel', 'virtual try-on', 'mặc thử' ],
			'icon'     => '👗',
		],
		'mockup' => [
			'title'    => 'Mockup sản phẩm',
			'desc'     => 'Tạo mockup sản phẩm: áo, cốc, hộp, poster, packaging',
			'triggers' => [ 'mockup', 'mock up', 'mô hình', 'product mockup', 'mẫu sản phẩm' ],
			'icon'     => '📦',
		],
		'packaging' => [
			'title'    => 'Thiết kế bao bì',
			'desc'     => 'Tạo thiết kế bao bì, nhãn mác, hộp sản phẩm bằng AI',
			'triggers' => [ 'bao bì', 'packaging', 'nhãn mác', 'hộp sản phẩm', 'label', 'package design' ],
			'icon'     => '🏷️',
		],
	];

	foreach ( $product_categories as $cat_slug => $cat ) {
		$skill_key  = 'timg_ps_' . str_replace( '-', '_', $cat_slug );
		$launch_url = '/product-studio/?tool=' . $cat_slug;
		$content    = sprintf(
			"Mở Product Studio — %s tại %s.\nGọi tool generate_product_image.",
			$cat['title'],
			$launch_url
		);

		$db->upsert( [
			'skill_key'      => $skill_key,
			'user_id'        => 0,
			'character_id'   => 0,
			'title'          => $cat['icon'] . ' ' . $cat['title'],
			'description'    => $cat['desc'],
			'category'       => 'tool-image',
			'triggers_json'  => $cat['triggers'],
			'slash_commands' => [ $cat_slug ],
			'modes'          => [ 'image' ],
			'tools_json'     => [ 'generate_product_image' ],
			'content'        => $content,
			'pipeline_json'  => [
				'tool'        => 'generate_product_image',
				'launch_url'  => $launch_url,
				'studio'      => 'product-studio',
				'studio_tool' => $cat_slug,
			],
			'priority'       => 30,
			'status'         => 'active',
		] );

		$synced[] = $skill_key;
	}

	/* ══════════════════════════════════════════
	 *  Profile Studio tabs → skills
	 *  Each tab = one skill, launches /profile-studio/?tab={slug}
	 * ══════════════════════════════════════════ */
	$profile_tabs = [
		'template-quick' => [
			'title'    => 'Ảnh chân dung nhanh',
			'desc'     => 'Chọn template có sẵn + upload khuôn mặt → AI tạo ảnh chân dung nhanh',
			'triggers' => [ 'template nhanh', 'ảnh nhanh', 'chân dung nhanh', 'quick portrait', 'template chân dung' ],
			'icon'     => '⚡',
		],
		'advanced' => [
			'title'    => 'Tùy chỉnh nâng cao',
			'desc'     => 'Tùy chỉnh chi tiết ảnh chân dung: pose, background, clothing, style',
			'triggers' => [ 'tùy chỉnh', 'nâng cao', 'advanced portrait', 'tùy chỉnh chân dung', 'chi tiết' ],
			'icon'     => '🎛️',
		],
		'free-prompt' => [
			'title'    => 'Prompt tự do',
			'desc'     => 'Viết prompt tự do để tạo ảnh chân dung AI theo ý muốn',
			'triggers' => [ 'prompt tự do', 'tự do', 'free prompt', 'viết prompt', 'tạo ảnh tự do' ],
			'icon'     => '✍️',
		],
		'style-copy' => [
			'title'    => 'Sao chép phong cách',
			'desc'     => 'Upload ảnh mẫu → AI sao chép phong cách sang ảnh chân dung của bạn',
			'triggers' => [ 'sao chép', 'style copy', 'copy phong cách', 'sao chép phong cách', 'clone style' ],
			'icon'     => '🎨',
		],
		'gallery' => [
			'title'    => 'Gallery ảnh đã tạo',
			'desc'     => 'Xem lại tất cả ảnh chân dung AI đã tạo, tải về hoặc chia sẻ',
			'triggers' => [ 'gallery', 'thư viện', 'ảnh đã tạo', 'xem ảnh', 'lịch sử ảnh' ],
			'icon'     => '🖼️',
		],
	];

	foreach ( $profile_tabs as $tab_slug => $tab ) {
		$skill_key  = 'timg_pf_' . str_replace( '-', '_', $tab_slug );
		$launch_url = '/profile-studio/?tab=' . $tab_slug;
		$content    = sprintf(
			"Mở Profile Studio — %s tại %s.\nGọi tool generate_image.",
			$tab['title'],
			$launch_url
		);

		$db->upsert( [
			'skill_key'      => $skill_key,
			'user_id'        => 0,
			'character_id'   => 0,
			'title'          => $tab['icon'] . ' ' . $tab['title'],
			'description'    => $tab['desc'],
			'category'       => 'tool-image',
			'triggers_json'  => $tab['triggers'],
			'slash_commands' => [ $tab_slug ],
			'modes'          => [ 'image' ],
			'tools_json'     => [ 'generate_image' ],
			'content'        => $content,
			'pipeline_json'  => [
				'tool'        => 'generate_image',
				'launch_url'  => $launch_url,
				'studio'      => 'profile-studio',
				'studio_tab'  => $tab_slug,
			],
			'priority'       => 30,
			'status'         => 'active',
		] );

		$synced[] = $skill_key;
	}

	/* ── Also keep two umbrella skills for generic studio requests ── */
	$umbrellas = [
		[
			'skill_key'  => 'timg_profile_studio',
			'title'      => '📸 Profile Studio',
			'desc'       => 'Studio ảnh chân dung AI: face-swap, style-copy, prompt tự do',
			'triggers'   => [ 'profile studio', 'ảnh chân dung', 'face swap', 'chân dung ai', 'ảnh đại diện' ],
			'slash'      => 'profile-studio',
			'tool'       => 'generate_image',
			'launch_url' => '/profile-studio/',
			'studio'     => 'profile-studio',
		],
		[
			'skill_key'  => 'timg_product_studio',
			'title'      => '🛍️ Product Studio',
			'desc'       => 'Studio ảnh sản phẩm AI: thay nền, thử đồ, mockup, packaging',
			'triggers'   => [ 'product studio', 'ảnh sản phẩm', 'chụp sản phẩm', 'studio sản phẩm', 'ảnh bán hàng' ],
			'slash'      => 'product-studio',
			'tool'       => 'generate_product_image',
			'launch_url' => '/product-studio/',
			'studio'     => 'product-studio',
		],
	];

	foreach ( $umbrellas as $u ) {
		$db->upsert( [
			'skill_key'      => $u['skill_key'],
			'user_id'        => 0,
			'character_id'   => 0,
			'title'          => $u['title'],
			'description'    => $u['desc'],
			'category'       => 'tool-image',
			'triggers_json'  => $u['triggers'],
			'slash_commands' => [ $u['slash'] ],
			'modes'          => [ 'image' ],
			'tools_json'     => [ $u['tool'] ],
			'content'        => sprintf( "Mở %s tại %s.", $u['title'], $u['launch_url'] ),
			'pipeline_json'  => [
				'tool'        => $u['tool'],
				'launch_url'  => $u['launch_url'],
				'studio'      => $u['studio'],
			],
			'priority'       => 20,
			'status'         => 'active',
		] );

		$synced[] = $u['skill_key'];
	}

	/* ── Archive orphaned skills (old per-template skills, etc.) ── */
	if ( ! empty( $synced ) ) {
		global $wpdb;
		$table        = $wpdb->prefix . 'bizcity_skills';
		$placeholders = implode( ',', array_fill( 0, count( $synced ), '%s' ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'archived'
			 WHERE category = 'tool-image'
			   AND skill_key NOT IN ({$placeholders})
			   AND status = 'active'",
			...$synced
		) );
	}

	/* ── Mark synced — skip until version changes ── */
	set_transient( 'bztimg_skill_sync_hash', $fingerprint, 0 );
}

/* ═══════════════════════════════════════════════
   PILLAR 1 — View Routes: /tool-image/, /product-studio/
   ═══════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-image/?$', 'index.php?bizcity_agent_page=tool-image', 'top' );
    add_rewrite_rule( '^tool-image/product-studio/?$', 'index.php?bizcity_agent_page=tool-image-product-studio', 'top' );
    /* Standalone /product-studio/ route (mirrors /tool-image/product-studio/) */
    add_rewrite_rule( '^product-studio/?$', 'index.php?bizcity_agent_page=tool-image-product-studio', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    $page = get_query_var( 'bizcity_agent_page' );
    if ( $page === 'tool-image' ) {
        include BZTIMG_DIR . 'views/page-image-profile.php';
        exit;
    }
    if ( $page === 'tool-image-product-studio' ) {
        include BZTIMG_DIR . 'views/page-product-studio.php';
        exit;
    }
} );

/* ═══════════════════════════════════════════════
   ACTIVATION / DEACTIVATION
   ═══════════════════════════════════════════════ */
register_activation_hook( __FILE__, function() {
    bztimg_install_tables();
    if ( ! get_option( 'bztimg_api_key' ) ) {
        add_option( 'bztimg_api_key', '' );
    }
    if ( ! get_option( 'bztimg_api_endpoint' ) ) {
        add_option( 'bztimg_api_endpoint', 'https://openrouter.ai/api/v1' );
    }
    if ( ! get_option( 'bztimg_default_model' ) ) {
        add_option( 'bztimg_default_model', 'flux-pro' );
    }
    if ( ! get_option( 'bztimg_default_size' ) ) {
        add_option( 'bztimg_default_size', '1024x1024' );
    }
    // Marketplace: Hub URL (empty = local-only, set to e.g. https://bizcity.vn for proxy)
    if ( false === get_option( 'bztimg_editor_hub_url' ) ) {
        add_option( 'bztimg_editor_hub_url', '' );
    }

    // Phase 3.4: Register CPT and seed default models
    BizCity_Model_Manager::register_cpt();
    BizCity_Model_Manager::seed_defaults();

    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );

/* ── DB migration — runs on every request until schema is current ── */
add_action( 'init', function() {
    // Auto-flush rewrite rules when /canva/ or /product-studio/ rule is missing
    $rules = get_option( 'rewrite_rules', array() );
    if ( is_array( $rules ) && ( ! isset( $rules['^canva/?$'] ) || ! isset( $rules['^product-studio/?$'] ) ) ) {
        flush_rewrite_rules( false );
    }

    if ( get_option( 'bztimg_schema_version' ) === BZTIMG_SCHEMA_VERSION ) return;
    bztimg_install_tables();
    bztimg_seed_categories(); // 3.6 — idempotent, adds accessory-tryon + any missing categories
    bztimg_seed_json_templates(); // Re-seed all JSON templates + library items on schema bump
    // Auto-migrate PiAPI endpoint → OpenRouter
    $endpoint = get_option( 'bztimg_api_endpoint', '' );
    if ( empty( $endpoint ) || strpos( $endpoint, 'piapi.ai' ) !== false ) {
        update_option( 'bztimg_api_endpoint', 'https://openrouter.ai/api/v1' );
    }
    // Fix model templates seeded with broken status (format array bug pre-3.1)
    global $wpdb;
    // Fix model rows with wrong subcategory — detect by form_fields containing model_description
    $wpdb->query(
        "UPDATE {$wpdb->prefix}bztimg_templates SET subcategory = 'model'
         WHERE subcategory != 'model' AND form_fields LIKE '%model_description%' AND form_fields NOT LIKE '[%'"
    );
    // Fix status/style for ALL model rows (including those just fixed above)
    $wpdb->query(
        "UPDATE {$wpdb->prefix}bztimg_templates SET status = 'active' WHERE subcategory = 'model' AND status IN ('0', '')"
    );
    $wpdb->query(
        "UPDATE {$wpdb->prefix}bztimg_templates SET style = 'photorealistic' WHERE subcategory = 'model' AND style IN ('0', '')"
    );
    // Backfill parent_slug for model rows that don't have it (wider scope — any subcategory='model')
    $models = $wpdb->get_results(
        "SELECT id, category_id, form_fields FROM {$wpdb->prefix}bztimg_templates WHERE subcategory = 'model' AND form_fields NOT LIKE '%parent_slug%'",
        ARRAY_A
    );
    foreach ( $models as $m ) {
        $parent_slug = $wpdb->get_var( $wpdb->prepare(
            "SELECT slug FROM {$wpdb->prefix}bztimg_templates WHERE category_id = %d AND subcategory != 'model' ORDER BY sort_order ASC LIMIT 1",
            $m['category_id']
        ) );
        if ( $parent_slug ) {
            $ff = json_decode( $m['form_fields'], true ) ?: array();
            $ff['parent_slug'] = $parent_slug;
            $wpdb->update(
                $wpdb->prefix . 'bztimg_templates',
                array( 'form_fields' => wp_json_encode( $ff ) ),
                array( 'id' => $m['id'] ),
                array( '%s' ),
                array( '%d' )
            );
        }
    }
    update_option( 'bztimg_db_version',    BZTIMG_VERSION );    // legacy compat
    update_option( 'bztimg_schema_version', BZTIMG_SCHEMA_VERSION );
}, 1 ); // priority 1 — before REST/rewrite rules

/* ═══════════════════════════════════════════════
   REGISTER ASSETS
   ═══════════════════════════════════════════════ */
add_action( 'init', function() {
    wp_register_style(  'bztimg-admin', BZTIMG_URL . 'assets/admin.css', [], BZTIMG_VERSION );
    wp_register_script( 'bztimg-admin', BZTIMG_URL . 'assets/admin.js', [ 'jquery' ], BZTIMG_VERSION, true );
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, BZTIMG_SLUG ) === false ) return;
    wp_enqueue_style( 'bztimg-admin' );
    wp_enqueue_script( 'bztimg-admin' );
    wp_localize_script( 'bztimg-admin', 'BZTIMG', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bztimg_nonce' ),
    ] );
} );
