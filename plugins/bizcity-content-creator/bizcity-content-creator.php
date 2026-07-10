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
define( 'BZCC_VERSION', '0.1.41' ); // [2026-06-08 Johnny Chu] HOTFIX — bump to flush rewrite rules (underscore slug fix)
// [2026-06-11 Johnny Chu] R-PERF — DB schema version tách riêng khỏi BZCC_VERSION
// Để bump BZCC_VERSION (rewrite flush) không vô tình invalidate db guard mỗi request.
// Chỉ bump BZCC_DB_VERSION khi có thay đổi schema thực sự.
if ( ! defined( 'BZCC_DB_VERSION' ) ) {
	define( 'BZCC_DB_VERSION', '1.0' );
}
define( 'BZCC_DIR',     __DIR__ . '/' );
define( 'BZCC_FILE',    __FILE__ );
define( 'BZCC_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZCC_SLUG',    'bizcity-content-creator' );

/* ── Autoload includes ── */
require_once BZCC_DIR . 'includes/class-installer.php';
require_once BZCC_DIR . 'includes/class-template-seeder.php';

// Vòng 4.5.5e (Rule 8g v2 — 2026-05-02) — own our TwinShell agent.
// Registers via add_filter( 'bizcity_register_agent', ... ); resolved lazily
// by BizCity_Twin_Agent_Registry on first dispatch.
require_once BZCC_DIR . 'includes/agents/register-content-agent.php';
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

/* ── Persona Tool Provider (Wave F7.0b — Producer plugin per R-MPRT §6.5) ── */
require_once BZCC_DIR . 'includes/class-persona-provider.php';
add_filter( 'bizcity_persona_tool_providers', function ( array $providers ): array {
	if ( class_exists( 'BZCC_Persona_Provider' ) ) {
		$providers['content-creator'] = new BZCC_Persona_Provider();
	}
	return $providers;
}, 20 );

/* ── Activation hook ── */
register_activation_hook( __FILE__, [ 'BZCC_Installer', 'activate' ] );

/* ── Self-healing: table creation for marketplace/AJAX activation ── */
BZCC_Installer::maybe_create_tables();

/* ── Auto-seed shipped JSON templates on version bump (per-site) ── */
BZCC_Template_Seeder::init();

/* ── Flush rewrite rules once after install (must-load won't trigger activation hook) ── */
// [2026-06-09 Johnny Chu] HOTFIX — guard Transposh/WooCommerce wp_loaded flush loop:
// flush_rewrite_rules() on init:99 saves rules BEFORE Transposh appends language patterns
// [2026-06-09 Johnny Chu] R-CR — migrated to Central Rewrite Flush Registry.
// Registry consolidates all module flushes into ONE flush_rewrite_rules(false) at admin_init:1.
// Old per-plugin pending-flag pattern preserved as fallback in the registry class itself.
BizCity_Rewrite_Flush_Registry::register( 'bizcity-content-creator', BZCC_VERSION );

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

	// [2026-06-09 Johnny Chu] PERF-1 — Skip 341 KB template data on admin UI pages.
	// bzcc_get_intent_patterns() AND bzcc_get_intent_plans() both call
	// BZCC_Template_Manager::get_all_active() → 341 KB Redis deserialization per request.
	// On admin UI pages that don't dispatch intents (TwinChat admin, Channel pages, etc.)
	// this data is loaded but never used. Patterns and plans only needed during:
	//   a) REST/webhook intent dispatch  b) WP-Cron  c) WP-CLI  d) frontend chat pages
	// PHP 7.4 compat: defined() check before constant access.
	$_bzcc_in_intent_ctx =
		( ! is_admin() )
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ( defined( 'DOING_CRON' ) && DOING_CRON )
		|| ( defined( 'WP_CLI' ) && WP_CLI );

	bizcity_intent_register_plugin( $registry, [

		'id'   => 'content-creator',
		'name' => 'TwinPlanner — Tạo nội dung & kế hoạch với AI',  // [2026-06-06 Johnny Chu] BZCC-SKEL — rebrand to TwinPlanner

		/* ── PATTERNS — skip on admin UI (no DB hit) ── */
		'patterns' => $_bzcc_in_intent_ctx ? bzcc_get_intent_patterns() : [],

		/* ── PLANS — skip on admin UI (no DB hit) ── */
		'plans' => $_bzcc_in_intent_ctx ? bzcc_get_intent_plans() : [],

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
		'name' => 'TwinPlanner', // [2026-06-06 Johnny Chu] BZCC-SKEL — rebrand
		'icon' => '🧠',
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
 *
 *  Trigger policy (Phase 1.20.1 — 2026-05-20):
 *    • Template CRUD (insert/update/delete) → bzcc_invalidate_skill_sync()
 *      runs the sync immediately on the same request.
 *    • JSON seeder completion (`bzcc_seed_complete` action) → sync runs once.
 *    • One-time bootstrap on first admin load after this version is deployed
 *      (covers sites where transient was never populated).
 *    • NO global `init` hook — sync no longer fires on every request.
 * ══════════════════════════════════════════════ */

// Fire after JSON template seeder imports new templates.
add_action( 'bzcc_seed_complete', 'bzcc_sync_template_skills' );

// One-time bootstrap: ensure sync runs once on existing sites after deploy.
add_action( 'admin_init', function () {
	if ( get_option( 'bzcc_skill_sync_bootstrapped' ) === BZCC_VERSION ) {
		return;
	}
	bzcc_sync_template_skills();
	update_option( 'bzcc_skill_sync_bootstrapped', BZCC_VERSION, false );
}, 99 );

/**
 * Invalidate the BZCC skill-sync cache and run the sync.
 *
 * Called after any template insert / update / delete. The actual DB work is
 * cheap (fingerprint check skips when nothing changed) but we defer to
 * `shutdown` so the CRUD request returns to the user without extra latency.
 */
function bzcc_invalidate_skill_sync(): void {
	delete_transient( 'bzcc_skill_sync_hash' );

	// Avoid double-scheduling within the same request.
	static $scheduled = false;
	if ( $scheduled ) {
		return;
	}
	$scheduled = true;

	add_action( 'shutdown', 'bzcc_sync_template_skills', 99 );
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

	// Guard: table must exist before querying.
	if ( ! BZCC_Installer::tables_exist() ) {
		return;
	}

	// [2026-06-21 Johnny Chu] R-CACHE bzcc — cache fingerprint row; group bzcc, key skill_sync_fp, TTL 5min.
	$fp_cache_key = 'skill_sync_fp';
	$row          = BizCity_Cache::get( 'bzcc', $fp_cache_key );
	if ( false === $row ) {
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, MAX(updated_at) AS max_upd FROM {$tpl_table} WHERE status = 'active'"
		);
		BizCity_Cache::set( 'bzcc', $fp_cache_key, $row, BizCity_Cache::TTL_MEDIUM );
	}
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

	// [2026-06-03 Johnny Chu] HOTFIX — hard guard: class + table phải sẵn sàng.
	// Trước đây chỉ check tables_exist() nhưng cache có thể bị set sai khi
	// create_tables() fail silent → query xuống fatal. Giờ thêm self-heal:
	// nếu thiếu table thì gọi maybe_create_tables() 1 lần rồi check lại.
	if ( ! class_exists( 'BZCC_Installer' ) || ! class_exists( 'BZCC_Template_Manager' ) ) {
		return $patterns;
	}
	if ( ! BZCC_Installer::tables_exist() ) {
		BZCC_Installer::maybe_create_tables();
		if ( ! BZCC_Installer::tables_exist() ) {
			return $patterns;
		}
	}

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

	// [2026-06-03 Johnny Chu] HOTFIX — cùng pattern self-heal như get_intent_patterns.
	if ( ! class_exists( 'BZCC_Installer' ) || ! class_exists( 'BZCC_Template_Manager' ) ) {
		return $plans;
	}
	if ( ! BZCC_Installer::tables_exist() ) {
		BZCC_Installer::maybe_create_tables();
		if ( ! BZCC_Installer::tables_exist() ) {
			return $plans;
		}
	}

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
