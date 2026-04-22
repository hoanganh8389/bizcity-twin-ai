<?php
/**
 * Plugin Name: BizCity Content Creator
 * Plugin URI:  https://bizcity.vn
 * Description: Template-driven AI content creation — categories, form builder, SSE chunk generation, multi-platform output.
 * Version:     0.1.16
 * Author:      BizCity
 * License:     Proprietary
 * Role:        agent
 * Featured:    1
 * Category:    Content
 * Tags:        content,marketing,copywriting,campaign,template
 * Template Page: tool-content-creator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Guard: require bizcity-twin-ai host plugin ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
	return;
}

/* ── Constants ── */
define( 'BZCC_VERSION', '0.1.28' );
define( 'BZCC_DIR',     __DIR__ . '/' );
define( 'BZCC_FILE',    __FILE__ );
define( 'BZCC_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZCC_SLUG',    'bizcity-content-creator' );

/* ── Autoload includes ── */
require_once BZCC_DIR . 'includes/class-installer.php';
require_once BZCC_DIR . 'includes/class-admin-menu.php';
require_once BZCC_DIR . 'includes/class-category-manager.php';
require_once BZCC_DIR . 'includes/class-template-manager.php';
require_once BZCC_DIR . 'includes/class-file-manager.php';
require_once BZCC_DIR . 'includes/class-chunk-meta-manager.php';
require_once BZCC_DIR . 'includes/class-frontend.php';
require_once BZCC_DIR . 'includes/class-rest-api.php';

/* ── Smart Input Pipeline (Phase 3.2) ── */
require_once BZCC_DIR . 'includes/class-knowledge-bridge.php';
require_once BZCC_DIR . 'includes/class-vision-processor.php';
require_once BZCC_DIR . 'includes/class-file-processor.php';
require_once BZCC_DIR . 'includes/class-smart-input-pipeline.php';

/* ── Canvas Bridge (Phase 1.20) ── */
require_once BZCC_DIR . 'includes/class-canvas-bridge.php';

/* ── Activation hook ── */
register_activation_hook( __FILE__, [ 'BZCC_Installer', 'activate' ] );

/* ── Self-healing: table creation for marketplace/AJAX activation ── */
BZCC_Installer::maybe_create_tables();

/* ── Flush rewrite rules once after install (must-load won't trigger activation hook) ── */
if ( ! get_option( 'bzcc_rewrite_version' ) || get_option( 'bzcc_rewrite_version' ) !== BZCC_VERSION ) {
	add_action( 'init', function () {
		BZCC_Frontend::register_rewrite_rules();
		flush_rewrite_rules( false );
		update_option( 'bzcc_rewrite_version', BZCC_VERSION );
	}, 99 );
}

/* ── Admin ── */
BZCC_Admin_Menu::init();

/* ── Frontend (shortcode + template page) ── */
BZCC_Frontend::init();

/* ── REST API (SSE streaming + file operations) ── */
BZCC_Rest_API::init();

/* ── Canvas Adapter handler (Phase 1.20) ── */
add_filter( 'bizcity_canvas_handlers', [ 'BZCC_Canvas_Bridge', 'register_handlers' ] );

/* ── Intent Provider (HIL + dual-path) ── */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

	bizcity_intent_register_plugin( $registry, [

		'id'   => 'content-creator',
		'name' => 'BizCity Content Creator — Tạo nội dung sáng tạo',

		/* ── PATTERNS ── */
		'patterns' => bzcc_get_intent_patterns(),

		/* ── PLANS ── */
		'plans' => bzcc_get_intent_plans(),

		/* ── TOOLS ── */
		'tools' => [
			'content_creator_execute' => [
				'schema' => [
					'description'    => 'Tạo file + outline + chunks từ template + slots',
					'accepts_skill'  => true,
					'content_tier'   => 1,
					'studio_enabled' => true,
					'tool_type'      => 'content',
					'input_fields'   => [
						'template_id' => [ 'required' => true,  'type' => 'number' ],
						'form_data'   => [ 'required' => false, 'type' => 'text' ],
					],
				],
				'callback'  => [ 'BZCC_Canvas_Bridge', 'handle_inline_fallback' ],
				'save_mode' => 'always',
			],
			'content_creator_select_template' => [
				'schema' => [
					'description'  => 'Giúp user chọn template phù hợp',
					'input_fields' => [
						'template_choice' => [ 'required' => true, 'type' => 'text' ],
					],
				],
				'callback'  => [ 'BZCC_Canvas_Bridge', 'handle_select_template' ],
				'save_mode' => 'never',
			],
		],
	] );
} );

/* ── Tool Registry ──
 * REMOVED: bizcity_register_tools dead code (action never fired).
 * Registration now happens via Bootstrap Adapter A: Intent_Tools → Tool_Registry sync.
 * content_creator_execute schema includes content_tier=1 → Adapter A sets studio_enabled=true.
 */

/* ── TouchBar Agent ── */
add_filter( 'bizcity_agent_plugins', function ( $agents ) {
	$agents[] = [
		'slug' => 'content-creator',
		'name' => 'Content Creator',
		'icon' => '✨',
		'type' => 'agent',
		'src'  => admin_url( 'admin.php?page=bizcity-creator' ),
	];
	return $agents;
} );

/* ══════════════════════════════════════════════
 *  Phase 1.20: Sync templates → bizcity_skills (Universal Plugin Router)
 *
 *  Each active template becomes a virtual skill row so it appears in
 *  the SlashDialog, is discoverable by Pre-Rules, and feeds tool_refs
 *  to the Smart Classifier.
 * ══════════════════════════════════════════════ */
add_action( 'init', 'bzcc_sync_template_skills', 25 );

/**
 * Invalidate the BZCC skill-sync transient so next init re-syncs.
 * Call this after any template insert / update / delete.
 */
function bzcc_invalidate_skill_sync(): void {
	delete_transient( 'bzcc_skill_sync_hash' );
}

/**
 * Sync active Content Creator templates into bizcity_skills DB.
 *
 * Runs on every init (priority 25, before Adapter A at 30).
 * Uses a transient fingerprint (COUNT + MAX updated_at + BZCC_VERSION)
 * to skip re-sync when nothing changed.
 */
function bzcc_sync_template_skills(): void {
	if ( ! class_exists( 'BizCity_Skill_Database' ) || ! class_exists( 'BZCC_Template_Manager' ) ) {
		return;
	}

	/* ── Fingerprint check: skip if nothing changed ── */
	global $wpdb;
	$tpl_table = BZCC_Installer::table_templates();
	$row       = $wpdb->get_row(
		"SELECT COUNT(*) AS cnt, MAX(updated_at) AS max_upd FROM {$tpl_table} WHERE status = 'active'"
	);
	$fingerprint = md5( BZCC_VERSION . ':' . ( $row->cnt ?? 0 ) . ':' . ( $row->max_upd ?? '' ) );

	if ( get_transient( 'bzcc_skill_sync_hash' ) === $fingerprint ) {
		return; // Already synced for this state.
	}

	$db        = BizCity_Skill_Database::instance();
	$templates = BZCC_Template_Manager::get_all_active();
	$synced    = [];

	foreach ( $templates as $tpl ) {
		$slug      = $tpl->slug ?? '';
		$skill_key = 'cc_' . $slug;

		if ( empty( $slug ) ) {
			continue;
		}

		$tags     = array_filter( array_map( 'trim', explode( ',', $tpl->tags ?? '' ) ) );
		$desc     = mb_substr( $tpl->description ?? '', 0, 250 );
		$content  = sprintf(
			"Sử dụng Content Creator template \"%s\" (ID: %d).\nGọi tool content_creator_execute với template_id=%d.",
			$tpl->title ?? $slug,
			(int) $tpl->id,
			(int) $tpl->id
		);

		$db->upsert( [
			'skill_key'      => $skill_key,
			'user_id'        => 0,
			'character_id'   => 0,
			'title'          => $tpl->title ?? $slug,
			'description'    => $desc,
			'category'       => 'content-creator',
			'triggers_json'  => $tags,
			'slash_commands' => [ $slug ],
			'modes'          => [ 'content' ],
			'tools_json'     => [ 'content_creator_execute' ],
			'content'        => $content,
			'pipeline_json'  => [
				'tool'        => 'content_creator_execute',
				'tool_params' => [ 'template_id' => (int) $tpl->id ],
			],
			'priority'       => 30,
			'status'         => 'active',
		] );

		$synced[] = $skill_key;
	}

	// Archive orphaned template skills (templates deleted/deactivated)
	if ( ! empty( $synced ) ) {
		global $wpdb;
		$table        = $wpdb->prefix . 'bizcity_skills';
		$placeholders = implode( ',', array_fill( 0, count( $synced ), '%s' ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'archived'
			 WHERE category = 'content-creator'
			   AND skill_key NOT IN ({$placeholders})
			   AND status = 'active'",
			...$synced
		) );
	}

	/* ── Persist fingerprint so next init skips ── */
	set_transient( 'bzcc_skill_sync_hash', $fingerprint, 0 );
}

/* ══════════════════════════════════════════════
 *  HELPER: Build intent patterns from active templates
 * ══════════════════════════════════════════════ */

/**
 * Build goal patterns from active templates.
 */
function bzcc_get_intent_patterns(): array {
	$patterns = [
		'/tạo nội dung|content creator|viết bài sáng tạo/iu' => [
			'goal'        => 'cc_generic',
			'label'       => 'Tạo nội dung sáng tạo',
			'description' => 'Mở Content Creator để chọn template',
			'extract'     => [ 'message' ],
		],
	];

	$templates = BZCC_Template_Manager::get_all_active();
	foreach ( $templates as $tpl ) {
		$keywords = array_filter( array_map( 'trim', explode( ',', $tpl->tags ?? '' ) ) );
		if ( empty( $keywords ) ) {
			continue;
		}
		$regex = '/' . implode( '|', array_map( 'preg_quote', $keywords ) ) . '/iu';
		$patterns[ $regex ] = [
			'goal'        => 'cc_' . $tpl->slug,
			'label'       => $tpl->title,
			'description' => $tpl->description ?? '',
			'extract'     => [ 'message' ],
		];
	}

	return $patterns;
}

/**
 * Build plans from active templates — form_fields → HIL slots.
 */
function bzcc_get_intent_plans(): array {
	$plans = [];

	/* Generic: user chọn template */
	$plans['cc_generic'] = [
		'required_slots' => [
			'template_choice' => [
				'type'   => 'text',
				'prompt' => 'Bạn muốn tạo loại nội dung gì? (bán hàng, marketing, kế hoạch, ...)',
			],
		],
		'tool'       => 'content_creator_select_template',
		'ai_compose' => true,
	];

	$type_map = [
		'number'       => 'number',
		'image_upload' => 'image',
		'date'         => 'text',
	];

	$templates = BZCC_Template_Manager::get_all_active();
	foreach ( $templates as $tpl ) {
		$goal_key    = 'cc_' . $tpl->slug;
		$form_fields = json_decode( $tpl->form_fields, true ) ?: [];
		$required    = [];
		$optional    = [];

		foreach ( $form_fields as $field ) {
			$slot_def = [
				'type'    => $type_map[ $field['type'] ] ?? 'text',
				'prompt'  => $field['label'] ?? '',
				'options' => $field['options'] ?? null,
			];

			if ( ! empty( $field['required'] ) ) {
				$required[ $field['slug'] ] = $slot_def;
			} else {
				$optional[ $field['slug'] ] = $slot_def;
			}
		}

		$plans[ $goal_key ] = [
			'required_slots' => $required,
			'optional_slots' => $optional,
			'tool'           => 'content_creator_execute',
			'tool_params'    => [ 'template_id' => $tpl->id ],
			'ai_compose'     => true,
		];
	}

	return $plans;
}
