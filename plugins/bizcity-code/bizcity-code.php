<?php
/**
 * Plugin Name:       Code Builder — AI tạo web & landing page
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-code
 * Description:       Screenshot/prompt → AI sinh code HTML+Tailwind, React, Vue. Live preview, iterative edit, variant compare, publish to subdomain.
 * Short Description: Upload screenshot hoặc mô tả → AI tạo web/landing page — preview & chỉnh sửa realtime.
 * Quick View:        🖥️ Screenshot/Prompt → AI sinh code → Live Preview & Edit → Publish
 * Version:           0.4.3
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-code
 * License:           Proprietary
 * Role:              agent
 * Featured:          true
 * Notebook:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon-code.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/04/code-builder-cover.png
 * Template Page:     tool-code
 * Category:          code, web, landing-page, builder
 * Tags:              code,web,landing page,HTML,Tailwind,React,Vue,screenshot-to-code,AI builder
 * Plan:              free
 *
 * === Giới thiệu ===
 * BizCity Code Builder biến screenshot, mockup hoặc mô tả văn bản thành
 * code web hoàn chỉnh bằng AI. Hỗ trợ HTML+Tailwind, React+Tailwind,
 * Vue+Tailwind. Live preview, iterative edit qua chat, variant compare,
 * publish ra subdomain.
 *
 * === Tính năng chính ===
 * • Screenshot/Image → AI vision → code generation (như screenshot-to-code)
 * • Text prompt → AI tạo web/landing page từ mô tả
 * • Iterative edit: chat để chỉnh sửa code, agent loop tối đa 20 turns
 * • Variant system: tạo N variants song song, user chọn bản tốt nhất
 * • Live preview với iframe sandbox
 * • Code editor (Monaco/CodeMirror) tích hợp
 * • Project management: multi-page, asset manager
 * • Publish to subdomain hoặc download HTML
 * • Tích hợp bizcity-wallet billing per generation
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine ≥ 2.4.0
 * • BizCity LLM Router (multi-provider)
 * • Vision model (GPT-4o / Gemini Flash / Claude Opus 4.5)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Guard: require bizcity-twin-ai host plugin ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		echo '<strong>BizCity Code Builder</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt.';
		echo '</p></div>';
	} );
	return;
}

/* ═══════════════════════════════════════════════
   CONSTANTS
   ═══════════════════════════════════════════════ */
define( 'BZCODE_VERSION',        '0.4.8' );
define( 'BZCODE_DIR',            __DIR__ . '/' );
define( 'BZCODE_FILE',           __FILE__ );
define( 'BZCODE_URL',            plugin_dir_url( __FILE__ ) );
define( 'BZCODE_SLUG',           'bizcity-code' );
define( 'BZCODE_SCHEMA_VERSION', '1.2' );

/* ═══════════════════════════════════════════════
   AUTOLOAD INCLUDES
   ═══════════════════════════════════════════════ */
require_once BZCODE_DIR . 'includes/class-installer.php';
require_once BZCODE_DIR . 'includes/class-admin-menu.php';
require_once BZCODE_DIR . 'includes/class-project-manager.php';
require_once BZCODE_DIR . 'includes/class-page-manager.php';
require_once BZCODE_DIR . 'includes/class-variant-manager.php';
require_once BZCODE_DIR . 'includes/class-source-manager.php';
require_once BZCODE_DIR . 'includes/class-code-engine.php';
require_once BZCODE_DIR . 'includes/class-rest-api.php';
require_once BZCODE_DIR . 'includes/class-frontend.php';
require_once BZCODE_DIR . 'includes/class-canvas-bridge.php';
require_once BZCODE_DIR . 'includes/class-notebook-bridge.php';

/* ── Self-healing: table creation (sub-plugin — activation hook won't fire) ── */
BZCode_Installer::maybe_create_tables();

/* ── Flush rewrite rules once after install ── */
if ( ! get_option( 'bzcode_rewrite_version' ) || get_option( 'bzcode_rewrite_version' ) !== BZCODE_VERSION ) {
	add_action( 'init', function () {
		BZCode_Frontend::register_rewrite_rules();
		flush_rewrite_rules( false );
		update_option( 'bzcode_rewrite_version', BZCODE_VERSION );
	}, 99 );
}

/* ── Admin ── */
BZCode_Admin_Menu::init();

/* ── Frontend (template page at /tool-code/) ── */
BZCode_Frontend::init();

/* ── REST API (SSE streaming + code gen + CRUD) ── */
BZCode_Rest_API::init();

/* ── Canvas Adapter (Twin AI integration) ── */
add_filter( 'bizcity_canvas_handlers', [ 'BZCode_Canvas_Bridge', 'register_handlers' ] );

/* ── Notebook Tool Registration (Phase 6.0 — Unify Sources) ── */
add_action( 'bcn_register_notebook_tools', [ 'BZCode_Notebook_Bridge', 'register' ] );

/* ══════════════════════════════════════════════
 *  Intent Provider — patterns → plans → tools
 * ══════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

	bizcity_intent_register_plugin( $registry, [

		'id'   => 'code-builder',
		'name' => 'BizCity Code Builder — AI tạo web & landing page',

		/* ── Goal patterns ── */
		'patterns' => [
			'/tạo (web|website|trang web|landing\s*page|trang đích).*?(từ|theo|dựa).*?(screenshot|ảnh|hình|mockup|figma)/iu' => [
				'goal'        => 'screenshot_to_code',
				'label'       => 'Screenshot → Code',
				'description' => 'Tạo code web/landing page từ screenshot/mockup',
				'extract'     => [ 'message', 'images' ],
			],
			'/tạo (web|website|trang web|landing\s*page)|thiết kế (web|trang)|build (web|website|page|landing)/iu' => [
				'goal'        => 'create_web',
				'label'       => 'Tạo Web/Landing Page',
				'description' => 'AI tạo web/landing page từ mô tả',
				'extract'     => [ 'message', 'topic', 'product_type' ],
			],
			'/code (html|react|vue|tailwind)|sinh code|generate code|viết code web/iu' => [
				'goal'        => 'generate_code',
				'label'       => 'Generate Code',
				'description' => 'AI sinh code HTML/React/Vue',
				'extract'     => [ 'message', 'stack' ],
			],
		],

		/* ── Plans ── */
		'plans' => [
			'screenshot_to_code' => [
				'label'  => 'Screenshot → Code',
				'steps'  => [
					[ 'tool' => 'code_generate', 'params' => [ 'mode' => 'screenshot' ] ],
				],
			],
			'create_web' => [
				'label'  => 'Tạo Web/Landing Page',
				'steps'  => [
					[ 'tool' => 'code_generate', 'params' => [ 'mode' => 'text' ] ],
				],
			],
			'generate_code' => [
				'label'  => 'Generate Code',
				'steps'  => [
					[ 'tool' => 'code_generate', 'params' => [ 'mode' => 'text' ] ],
				],
			],
		],

		/* ── Tools ── */
		'tools' => [
			'code_generate' => [
				'schema' => [
					'description'    => 'Sinh code web (HTML/React/Vue+Tailwind) từ screenshot hoặc mô tả',
					'accepts_skill'  => true,
					'content_tier'   => 2,
					'studio_enabled' => true,
					'tool_type'      => 'code',
					'input_fields'   => [
						'mode'       => [ 'required' => true,  'type' => 'text', 'enum' => [ 'screenshot', 'text', 'edit' ] ],
						'prompt'     => [ 'required' => false, 'type' => 'text' ],
						'images'     => [ 'required' => false, 'type' => 'array' ],
						'stack'      => [ 'required' => false, 'type' => 'text', 'enum' => [ 'html_tailwind', 'react_tailwind', 'vue_tailwind', 'html_css', 'bootstrap' ] ],
						'project_id' => [ 'required' => false, 'type' => 'number' ],
					],
				],
				'callback'  => [ 'BZCode_Canvas_Bridge', 'handle_generate' ],
				'save_mode' => 'always',
			],
			'code_edit' => [
				'schema' => [
					'description'  => 'Chỉnh sửa code web đã tạo — iterative edit qua chat',
					'tool_type'    => 'code',
					'input_fields' => [
						'project_id'  => [ 'required' => true,  'type' => 'number' ],
						'instruction' => [ 'required' => true,  'type' => 'text' ],
						'images'      => [ 'required' => false, 'type' => 'array' ],
					],
				],
				'callback'  => [ 'BZCode_Canvas_Bridge', 'handle_edit' ],
				'save_mode' => 'always',
			],
		],
	] );
} );

/* ── TouchBar Agent ── */
add_filter( 'bizcity_agent_plugins', function ( $agents ) {
	$agents[] = [
		'slug' => 'code-builder',
		'name' => 'Code Builder',
		'icon' => '🖥️',
		'type' => 'agent',
		'src'  => admin_url( 'admin.php?page=bizcity-code' ),
	];
	return $agents;
} );
