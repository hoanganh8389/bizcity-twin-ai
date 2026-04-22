<?php
/**
 * Plugin Name:       Doc Studio — AI tạo tài liệu chuyên nghiệp
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-doc
 * Description:       Prompt → AI sinh Document (DOCX/PDF), Presentation (PPTX), Spreadsheet (XLSX). Preview, chat edit, download.
 * Short Description: AI tạo Word, PowerPoint, Excel chuyên nghiệp — Preview & Edit realtime.
 * Quick View:        📄 Prompt → AI sinh tài liệu → Preview & Edit → Download DOCX/PDF/PPTX/XLSX
 * Version:           0.4.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-doc
 * License:           Proprietary
 * Role:              agent
 * Featured:          true
 * Notebook:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon-doc.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/04/doc-studio-cover.png
 * Template Page:     tool-doc
 * Category:          document, office, productivity
 * Tags:              document,word,powerpoint,excel,pdf,presentation,spreadsheet,AI document generator
 * Plan:              free
 *
 * === Giới thiệu ===
 * BizCity Doc Studio biến prompt thành tài liệu chuyên nghiệp:
 * Word (DOCX), PDF, PowerPoint (PPTX), Excel (XLSX).
 * Dựa trên cơ chế JSON Schema → AI generate → Client-side render + export.
 * Tham khảo kiến trúc từ MyDocMaker (MIT License).
 *
 * === Tính năng chính ===
 * • AI Document Generator: Prompt → DOCX/PDF với heading, table, list, image
 * • AI Presentation Maker: Prompt → PPTX slide decks có theme & layout
 * • AI Spreadsheet Maker: Prompt → XLSX với headers, formulas, data
 * • Chat-based editing: Chỉnh sửa tài liệu qua chat (split panel)
 * • Template system: Invoice, Resume, Report, Proposal, Contract, Meeting Notes
 * • Theme system: Modern, Classic, Professional, Creative, Minimal
 * • Client-side export: DOCX (docx.js), PDF (jsPDF), PPTX (pptxgenjs), XLSX (SheetJS)
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity LLM Router (AI backend)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Guard: require bizcity-twin-ai host plugin ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		echo '<strong>BizCity Doc Studio</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt.';
		echo '</p></div>';
	} );
	return;
}

/* ═══════════════════════════════════════════════
   CONSTANTS
   ═══════════════════════════════════════════════ */
define( 'BZDOC_VERSION',        '0.3.224' );
define( 'BZDOC_DIR',            __DIR__ . '/' );
define( 'BZDOC_FILE',           __FILE__ );
define( 'BZDOC_URL',            plugin_dir_url( __FILE__ ) );
define( 'BZDOC_SLUG',           'bizcity-doc' );
define( 'BZDOC_SCHEMA_VERSION', '2.2' );

/* ═══════════════════════════════════════════════
   AUTOLOAD INCLUDES
   ═══════════════════════════════════════════════ */
require_once BZDOC_DIR . 'includes/class-installer.php';
require_once BZDOC_DIR . 'includes/class-admin-menu.php';
require_once BZDOC_DIR . 'includes/class-rest-api.php';
require_once BZDOC_DIR . 'includes/class-frontend.php';
require_once BZDOC_DIR . 'includes/class-canvas-bridge.php';
require_once BZDOC_DIR . 'includes/class-sources.php';
require_once BZDOC_DIR . 'includes/class-embedder.php';
require_once BZDOC_DIR . 'includes/class-notebook-bridge.php';

/* ── Self-healing: table creation ── */
BZDoc_Installer::maybe_create_tables();

/* ── Admin ── */
BZDoc_Admin_Menu::init();

/* ── Frontend (template page at /tool-doc/) ── */
BZDoc_Frontend::init();

/* ── REST API ── */
BZDoc_Rest_API::init();

/* ── Register WordPress upload filters for DOCX/XLSX support ── */
add_action( 'init', [ 'BZDoc_Sources', 'register_upload_filters' ] );

/* ── Async embed hook (scheduled from upload handler) ── */
add_action( 'bzdoc_embed_source', function ( $source_id ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 );
	}
	// Increase memory for embedding large documents
	@ini_set( 'memory_limit', '1G' );

	try {
		BZDoc_Embedder::embed_source( (int) $source_id );
	} catch ( \Throwable $e ) {
		error_log( '[BZDoc] Embed cron fatal: ' . $e->getMessage() );
		// Mark source as failed so UI can show error
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bzdoc_project_sources',
			[ 'embedding_status' => 'failed' ],
			[ 'id' => (int) $source_id ]
		);
	}
} );

/* ── Canvas Adapter (Twin AI integration) ── */
add_filter( 'bizcity_canvas_handlers', [ 'BZDoc_Canvas_Bridge', 'register_handlers' ] );

/* ── Notebook Tool Registration (Phase 6.0 — Unify Sources) ── */
add_action( 'bcn_register_notebook_tools', [ 'BZDoc_Notebook_Bridge', 'register' ] );

/* ══════════════════════════════════════════════
 *  Intent Provider — patterns → plans → tools
 * ══════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

	bizcity_intent_register_plugin( $registry, [

		'id'   => 'doc-studio',
		'name' => 'BizCity Doc Studio — AI tạo tài liệu chuyên nghiệp',

		/* ── Goal patterns ── */
		'patterns' => [
			/* Presentation / Slide */
			'/tạo (slide|presentation|bài thuyết trình|powerpoint|pptx|trình chiếu)|make (slide|presentation|pptx)/iu' => [
				'goal'        => 'create_presentation',
				'label'       => 'Tạo Presentation',
				'description' => 'AI tạo bài thuyết trình/slide deck chuyên nghiệp',
				'extract'     => [ 'message', 'topic', 'slide_count' ],
			],
			/* Spreadsheet / Excel */
			'/tạo (bảng tính|spreadsheet|excel|xlsx|bảng dữ liệu)|make (spreadsheet|excel)/iu' => [
				'goal'        => 'create_spreadsheet',
				'label'       => 'Tạo Spreadsheet',
				'description' => 'AI tạo bảng tính Excel với dữ liệu & công thức',
				'extract'     => [ 'message', 'topic' ],
			],
			/* Document / Word (catch-all — primary goal last) */
			'/tạo (tài liệu|document|word|docx|pdf|báo cáo|report|hợp đồng|contract|đề xuất|proposal|thư|letter|biên bản|cv|resume)|viết (báo cáo|proposal|report|document)|make (document|word|report|pdf)|soạn (tài liệu|văn bản|hợp đồng)/iu' => [
				'goal'        => 'create_document',
				'label'       => 'Tạo Document',
				'description' => 'AI tạo tài liệu Word/PDF chuyên nghiệp',
				'extract'     => [ 'message', 'topic', 'template_name' ],
			],
		],

		/* ── Plans ── */
		'plans' => [
			'create_document' => [
				'label'  => 'Tạo Document (DOCX/PDF)',
				'steps'  => [
					[ 'tool' => 'doc_generate', 'params' => [ 'doc_type' => 'document' ] ],
				],
			],
			'create_presentation' => [
				'label'  => 'Tạo Presentation (PPTX)',
				'steps'  => [
					[ 'tool' => 'doc_generate', 'params' => [ 'doc_type' => 'presentation' ] ],
				],
			],
			'create_spreadsheet' => [
				'label'  => 'Tạo Spreadsheet (XLSX)',
				'steps'  => [
					[ 'tool' => 'doc_generate', 'params' => [ 'doc_type' => 'spreadsheet' ] ],
				],
			],
		],

		/* ── Tools ── */
		'tools' => [
			'doc_generate' => [
				'schema' => [
					'description'    => 'AI sinh tài liệu chuyên nghiệp (Document/Presentation/Spreadsheet)',
					'accepts_skill'  => true,
					'content_tier'   => 1,
					'studio_enabled' => true,
					'tool_type'      => 'document',
					'input_fields'   => [
						'doc_type'      => [ 'required' => true,  'type' => 'text', 'enum' => [ 'document', 'presentation', 'spreadsheet' ] ],
						'topic'         => [ 'required' => true,  'type' => 'text' ],
						'template_name' => [ 'required' => false, 'type' => 'text' ],
						'theme_name'    => [ 'required' => false, 'type' => 'text', 'enum' => [ 'modern', 'classic', 'professional', 'creative', 'minimal' ] ],
						'slide_count'   => [ 'required' => false, 'type' => 'number' ],
					],
				],
				'callback'  => [ 'BZDoc_Canvas_Bridge', 'handle_generate' ],
				'save_mode' => 'always',
			],
			'doc_edit' => [
				'schema' => [
					'description'  => 'Chỉnh sửa tài liệu đã tạo qua chat',
					'tool_type'    => 'document',
					'input_fields' => [
						'doc_id'      => [ 'required' => true,  'type' => 'number' ],
						'instruction' => [ 'required' => true,  'type' => 'text' ],
					],
				],
				'callback'  => [ 'BZDoc_Canvas_Bridge', 'handle_edit' ],
				'save_mode' => 'always',
			],
		],

		/* ── Context ── */
		'context' => function ( $goal, $slots, $user_id, $conversation ) {
			return "Plugin: BizCity Doc Studio\nMục tiêu: {$goal}\nLoại: tạo tài liệu chuyên nghiệp\n";
		},
	] );
} );

/* ══════════════════════════════════════════════════════════════
 *  PILLAR 1 — Profile View Route: /tool-doc/
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function () {
	add_rewrite_rule( '^tool-doc/?$', 'index.php?bizcity_agent_page=tool-doc', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) {
	if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) {
		$vars[] = 'bizcity_agent_page';
	}
	return $vars;
} );
add_action( 'template_redirect', function () {
	if ( get_query_var( 'bizcity_agent_page' ) === 'tool-doc' ) {
		include BZDOC_DIR . 'views/page-doc-studio.php';
		exit;
	}
} );

/* ── Flush rewrite rules once ── */
if ( ! get_option( 'bzdoc_rewrite_version' ) || get_option( 'bzdoc_rewrite_version' ) !== BZDOC_VERSION ) {
	add_action( 'init', function () {
		flush_rewrite_rules( false );
		update_option( 'bzdoc_rewrite_version', BZDOC_VERSION );
	}, 99 );
}
