<?php
/**
 * Plugin Name:       Page Builder — AI tạo website trực quan
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-pagebuilder
 * Description:       Prompt → AI sinh website hoàn chỉnh. Visual drag-and-drop editor, 19 block types, 10 theme presets, export HTML hoặc publish WordPress page.
 * Short Description: AI Website Builder — kéo thả block, đổi theme, export HTML.
 * Quick View:        🌐 Prompt → AI sinh website → Drag & Drop → Export HTML / Publish WP Page
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-pagebuilder
 * License:           MIT
 * Role:              agent
 * Featured:          true
 * Notebook:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon-pagebuilder.png
 * Template Page:     tool-pagebuilder
 * Category:          website, builder, landing-page
 * Tags:              website,builder,landing-page,drag-drop,AI website,page builder,visual editor
 * Plan:              free
 *
 * === Giới thiệu ===
 * BizCity Page Builder biến prompt thành website trực quan.
 * Dựa trên kiến trúc JSON-first: AI generate → typed blocks → visual editor → export HTML.
 * Fork từ OpenPage (MIT License): https://github.com/buildingopen/openpage
 *
 * === Tính năng chính ===
 * • AI Website Generator: Prompt → full website (19 block types, 42 variants)
 * • Visual Editor: Drag-and-drop blocks, inline editing, real-time preview
 * • Theme System: 10 presets (Dark Minimal, Ivory, Clean, Sand, Amber, Ocean, Rose, Purple Haze, Slate, Forest)
 * • JSON-first: Mọi edit đều sinh clean JSON — diffable, version-controllable
 * • Export: Standalone HTML file (self-contained, zero runtime dependencies)
 * • Publish: Export → WordPress page (wp_insert_post)
 * • Undo/Redo: Full history stack (Zustand + Immer)
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity LLM Router (AI backend)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// [2026-08-09 Johnny Chu] R-PERF-LOADER-BUNDLE — TwinChat admin shell is an
// iframe host and does not need Page Builder's editor/runtime graph. Keep
// /tool-pagebuilder, REST/AJAX, cron, CLI and dedicated admin pages enabled.
$_bzpb_shell_plugin = isset( $_GET['plugin'] )
	? sanitize_key( (string) $_GET['plugin'] )
	: '';
if ( is_admin()
	&& isset( $_GET['page'] )
	&& 'bizcity-twinchat' === sanitize_key( (string) $_GET['page'] )
	&& ( $_bzpb_shell_plugin === '' || $_bzpb_shell_plugin === 'twinchat' ) ) {
	return;
}
unset( $_bzpb_shell_plugin );

/* ── Guard: require bizcity-twin-ai host plugin ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		echo '<strong>BizCity Page Builder</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt.';
		echo '</p></div>';
	} );
	return;
}

/* ═══════════════════════════════════════════════
   CONSTANTS
   ═══════════════════════════════════════════════ */
define( 'BZPB_VERSION',        '0.1.0' );
define( 'BZPB_DIR',            __DIR__ . '/' );
define( 'BZPB_FILE',           __FILE__ );
define( 'BZPB_URL',            plugin_dir_url( __FILE__ ) );
define( 'BZPB_SLUG',           'bizcity-pagebuilder' );
define( 'BZPB_SCHEMA_VERSION', '1.2' );

/* ═══════════════════════════════════════════════
   AUTOLOAD INCLUDES
   ═══════════════════════════════════════════════ */
require_once BZPB_DIR . 'includes/class-installer.php';
require_once BZPB_DIR . 'includes/class-admin-menu.php';
require_once BZPB_DIR . 'includes/class-rest-api.php';
require_once BZPB_DIR . 'includes/class-frontend.php';
require_once BZPB_DIR . 'includes/class-canvas-bridge.php';
require_once BZPB_DIR . 'includes/class-export.php';
require_once BZPB_DIR . 'includes/class-submission-handler.php';
require_once BZPB_DIR . 'includes/class-submissions-admin.php';
require_once BZPB_DIR . 'includes/class-inline-editor.php';

/* ── Self-healing: table creation ── */
// [2026-08-09 Johnny Chu] R-PERF/R-DCL — schema repair is not part of frontend HTML bootstrap.
if ( is_admin()
	|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
	|| ( defined( 'DOING_CRON' ) && DOING_CRON )
	|| ( defined( 'WP_CLI' ) && WP_CLI )
	|| ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/wp-json/' ) )
) {
	BZPB_Installer::maybe_create_tables();
}

/* ── Initialize submission handling ── */
BZPB_Submission_Handler::init();
BZPB_Submissions_Admin::init();

/* ── Initialize inline editor ── */
BZPB_Inline_Editor::init();

/* ── Admin ── */
BZPB_Admin_Menu::init();

/* ── Frontend (template page at /tool-pagebuilder/) ── */
BZPB_Frontend::init();

/* ── REST API ── */
BZPB_Rest_API::init();

/* ── Canvas Adapter (Twin AI integration) ── */
add_filter( 'bizcity_canvas_handlers', [ 'BZPB_Canvas_Bridge', 'register_handlers' ] );

/* ══════════════════════════════════════════════
 *  Intent Provider — patterns → plans → tools
 * ══════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

	bizcity_intent_register_plugin( $registry, [

		'id'   => 'page-builder',
		'name' => 'BizCity Page Builder — AI tạo website trực quan',

		/* ── Goal patterns ── */
		'patterns' => [
			/* Website / Landing page */
			'/tạo (web|website|landing ?page|trang web|site|trang đích|trang chủ)|build (website|landing|page|site)|làm (web|trang|website)/iu' => [
				'goal'        => 'create_website',
				'label'       => 'Tạo Website',
				'description' => 'AI tạo website trực quan với drag-and-drop editor',
				'extract'     => [ 'message', 'topic', 'theme' ],
			],
			/* Design website */
			'/thiết kế (web|website|trang)|design (website|page|landing)/iu' => [
				'goal'        => 'create_website',
				'label'       => 'Thiết kế Website',
				'description' => 'Thiết kế website bằng AI + visual editor',
				'extract'     => [ 'message', 'style' ],
			],
		],

		/* ── Plans ── */
		'plans' => [
			'create_website' => [
				'label' => 'Tạo Website (Visual Builder)',
				'steps' => [
					[ 'tool' => 'page_generate', 'params' => [] ],
				],
			],
		],

		/* ── Tools ── */
		'tools' => [
			'page_generate' => [
				'schema' => [
					'description'    => 'AI sinh website hoàn chỉnh — visual drag-and-drop builder',
					'accepts_skill'  => true,
					'content_tier'   => 2,
					'studio_enabled' => true,
					'tool_type'      => 'website',
					'input_fields'   => [
						'prompt' => [ 'required' => true,  'type' => 'text' ],
						'theme'  => [ 'required' => false, 'type' => 'text', 'enum' => [
							'dark-minimal', 'ivory', 'clean', 'sand', 'amber',
							'ocean', 'rose', 'purple-haze', 'slate', 'forest',
						] ],
					],
				],
				'callback'  => [ 'BZPB_Canvas_Bridge', 'handle_generate' ],
				'save_mode' => 'always',
			],
			'page_edit' => [
				'schema' => [
					'description'  => 'Chỉnh sửa website đã tạo qua prompt',
					'tool_type'    => 'website',
					'input_fields' => [
						'project_id'  => [ 'required' => true,  'type' => 'number' ],
						'instruction' => [ 'required' => true,  'type' => 'text' ],
					],
				],
				'callback'  => [ 'BZPB_Canvas_Bridge', 'handle_edit' ],
				'save_mode' => 'always',
			],
		],

		/* ── Context ── */
		'context' => function ( $goal, $slots, $user_id, $conversation ) {
			return "Plugin: BizCity Page Builder\nMục tiêu: {$goal}\nLoại: tạo website visual (JSON-first, drag-and-drop, 19 block types)\n";
		},
	] );
} );

/* ══════════════════════════════════════════════════════════════
 *  Canvas Template Route: pages published by BZPB Page Builder
 *  Uses template_include filter so WordPress require()s the file
 *  in global scope — avoids PHP closure scope issues with include.
 * ══════════════════════════════════════════════════════════════ */
add_filter( 'template_include', function ( $template ) {
	if ( ! is_singular( 'page' ) ) return $template;
	$page_id = get_queried_object_id();
	if ( ! $page_id ) return $template;
	$raw_cfg = get_post_meta( $page_id, '_bzpb_site_config', true );
	if ( empty( $raw_cfg ) ) return $template;

	// ── Decode post meta JSON ──────────────────────────────────────────────
	$config = is_string( $raw_cfg ) ? json_decode( $raw_cfg, true ) : null;

	// ── Recovery: if meta JSON is broken, read from bzpb_projects ─────────
	if ( ! is_array( $config ) ) {
		global $wpdb;
		$project_id = (int) get_post_meta( $page_id, '_bzpb_project_id', true );
		error_log( '[BZPB] post meta JSON failed (json_error=' . json_last_error_msg()
			. ' raw_len=' . strlen( (string) $raw_cfg )
			. ') — attempting recovery from bzpb_projects id=' . $project_id );

		if ( $project_id ) {
			$table     = $wpdb->prefix . 'bzpb_projects';
			$proj_json = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT site_config FROM {$table} WHERE id = %d LIMIT 1",
				$project_id
			) );
			if ( $proj_json ) {
				$config = json_decode( $proj_json, true );
				// Auto-repair the broken post meta so future hits read fast
				if ( is_array( $config ) ) {
					$repaired = wp_json_encode( $config, JSON_UNESCAPED_UNICODE );
					if ( $repaired ) {
						update_post_meta( $page_id, '_bzpb_site_config', $repaired );
						error_log( '[BZPB] post meta repaired (new_len=' . strlen( $repaired ) . ')' );
					}
				}
			}
		}
	}

	if ( ! is_array( $config ) ) {
		// Both paths failed — log and let theme render (or debug below)
		error_log( '[BZPB] template_include: could not decode config for page_id=' . $page_id );
		$GLOBALS['_bzpb_canvas_html']    = '';
		$GLOBALS['_bzpb_canvas_post_id'] = $page_id;
		$GLOBALS['_bzpb_canvas_raw_len'] = strlen( (string) $raw_cfg );
		$GLOBALS['_bzpb_json_error']     = json_last_error_msg();
		return BZPB_DIR . 'views/template-canvas.php';
	}

	error_log( '[BZPB] template_include OK: page_id=' . $page_id
		. ' blocks=' . count( $config['blocks'] ?? [] ) );

	$html = BZPB_Export::render_canvas_page( $config, $page_id );
	$GLOBALS['_bzpb_canvas_html']    = $html;
	$GLOBALS['_bzpb_canvas_post_id'] = $page_id;
	return BZPB_DIR . 'views/template-canvas.php';
} );

/* ══════════════════════════════════════════════════════════════
 *  Template Page Route: /tool-pagebuilder/
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function () {
	add_rewrite_rule( '^tool-pagebuilder/?$', 'index.php?bizcity_agent_page=tool-pagebuilder', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) {
	if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) {
		$vars[] = 'bizcity_agent_page';
	}
	return $vars;
} );
add_action( 'template_redirect', function () {
	if ( get_query_var( 'bizcity_agent_page' ) === 'tool-pagebuilder' ) {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/tool-pagebuilder/' ) ) );
			exit;
		}
		include BZPB_DIR . 'views/page-builder.php';
		exit;
	}
} );

/* ── Flush rewrite rules once ── */
// [2026-06-09 Johnny Chu] R-CR — migrated to Central Rewrite Flush Registry.
// [2026-06-11 Johnny Chu] HOTFIX — guard against deployment gaps where
// class-rewrite-flush-registry.php may not be on server yet (OneDrive rsync lag).
if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
	BizCity_Rewrite_Flush_Registry::register( 'bizcity-pagebuilder', BZPB_VERSION );

	// [2026-06-25 Johnny Chu] R-CR SELF-HEAL — if the rewrite rule is missing from the
	// flushed DB rules (e.g. first deploy, no admin visit yet), queue an immediate flush.
	// queue_flush() sets PENDING_OPTION=1 in DB; the registry’s wp_loaded:99 hook picks
	// it up and flushes on this request so the NEXT request works correctly.
	// Uses get_option() directly (cheap, already cached by WP object cache) to avoid
	// adding a global init hook that would run on every request.
	$_bzpb_rules = get_option( 'rewrite_rules', array() );
	if ( ! is_array( $_bzpb_rules ) || ! isset( $_bzpb_rules['^tool-pagebuilder/?$'] ) ) {
		BizCity_Rewrite_Flush_Registry::queue_flush( 'bizcity-pagebuilder' );
	}
	unset( $_bzpb_rules );
}
