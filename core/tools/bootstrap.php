<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Tools
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Atomic Tools — Master Bootstrap
 *
 * Loads all tool group bootstraps under core/tools/{group}/bootstrap.php.
 * Each group registers its own atomic tools into BizCity_Intent_Tools.
 *
 * Registration priority order:
 *   planning/          — Tier 0 orchestration (priority 15)
 *   scheduler/         — Timeline anchor (priority 16)
 *   content/           — 36 atomic content tools (priority 25)
 *   distribution/      — Delivery only, accepts_skill=false (priority 26)
 *   workspace_google/  — Google Docs/Sheets/Slides/Drive + composites (priority 27)
 *
 * Future groups (loaded but no tools yet):
 *   web/      — HTTP, scraping, deep research, web search
 *   memory/   — Working memory (save/load/search via knowledge sources)
 *   data/     — CSV, JSON, structured data transforms
 *   compute/  — Calculator, text formatting, UUID, timestamps
 *   media/    — Image gen, image analysis, TTS, transcription
 *   project/  — Project/workspace CRUD
 *   task/     — Task planning + automation pipeline wrapper
 *   coding/   — Code generation, analysis, debugging (LLM wrappers)
 *
 * @package  BizCity_Tools
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'BIZCITY_TOOLS_DIR' ) ) {
	define( 'BIZCITY_TOOLS_DIR', __DIR__ . '/' );
}

/* ── Phase 1.9: Resource Resolver (must load before tool groups) ──── */
require_once BIZCITY_TOOLS_DIR . 'class-resource-resolver.php';

/* ── Phase 1.9 Sprint 1: Output Store (must load before tool groups) */
require_once BIZCITY_TOOLS_DIR . 'class-output-store.php';
add_action( 'plugins_loaded', [ 'BizCity_Output_Store', 'init' ], 20 );

/* ── Phase 1.9 Sprint 4: Auto-cleanup cron for stale outputs ──── */
add_action( 'plugins_loaded', [ 'BizCity_Output_Store', 'schedule_cleanup' ], 21 );

/* ── Phase 1.9 Sprint 2: Unified Tool Registry ───────────────────── */
require_once BIZCITY_TOOLS_DIR . 'class-tool-registry.php';

/* ── Phase 1.20: Canvas Adapter (handoff layer Intent → Canvas Panel) */
require_once BIZCITY_TOOLS_DIR . 'class-canvas-adapter.php';

/* ── Auto-load all tool group bootstraps ──────────────────────────── */
$tool_groups = [
	// ─── Active groups (priority ordered by each bootstrap) ───
	'planning',      // Tier 0 — build_workflow, knowledge_* (priority 15)
	'scheduler',     // Timeline — 8 scheduler atomic tools (priority 16)
	'content',       // Content — 36 atomic generators (priority 25)
	'distribution',  // Delivery — post, send, publish (priority 26)
	'workspace_google', // Google Workspace — Docs, Sheets, Slides, Drive (priority 27)
	'video',         // Phase 1.12 — 5 video tools: script, create, poll, fetch, post-prod (priority 25)

	// ─── Future groups (empty scaffolds) ───
	'web',       // Phase 2.2  — HTTP, research, scraping
	'memory',    // Phase 2.3  — Working memory via knowledge sources
	'data',      // Phase 2.5  — Data/structured tools
	'compute',   // Phase 2.6  — Utility/compute tools
	'coding',    // Phase 2.7  — Code generation/analysis
	'media',     // Phase 2.8  — Image/audio (non-video)
	'project',   // Phase 2.9  — Workspace management
	'task',      // Phase 2.10 — Task planning
];

foreach ( $tool_groups as $group ) {
	$bootstrap = BIZCITY_TOOLS_DIR . $group . '/bootstrap.php';
	if ( file_exists( $bootstrap ) ) {
		require_once $bootstrap;
	}
}

/* ── Phase 1.9 Sprint 2: Auto-sync adapters into BizCity_Tool_Registry
 *
 *  Priority 30 — content/distribution tools from BizCity_Intent_Tools
 *  Priority 31 — notebook tools from BCN_Notebook_Tool_Registry
 *  Priority 32 — seal the registry (no further mutations after this)
 * ─────────────────────────────────────────────────────────────────── */

// Adapter A: BizCity_Intent_Tools → BizCity_Tool_Registry
add_action( 'init', static function () {
	if ( ! class_exists( 'BizCity_Intent_Tools' ) || ! class_exists( 'BizCity_Tool_Registry' ) ) {
		return;
	}
	$all = BizCity_Intent_Tools::instance()->list_all();
	foreach ( $all as $slug => $schema ) {
		$content_tier  = (int) ( $schema['content_tier'] ?? 0 );
		$accepts_skill = ! empty( $schema['accepts_skill'] );
		$tool_type     = $schema['tool_type'] ?? 'atomic';
		BizCity_Tool_Registry::register( $slug, array_merge( $schema, [
			'label'          => ucwords( str_replace( [ '_', '-' ], ' ', preg_replace( '/^generate_/', '', $slug ) ) ),
			'icon'           => $tool_type === 'distribution' ? '📤' : '📝',
			'icon_url'       => '',
			'color'          => $tool_type === 'distribution' ? 'green' : 'blue',
			'category'       => $tool_type === 'distribution' ? 'distribution' : 'content',
			'available'      => true,
			'studio_enabled' => $content_tier >= 1,
			'at_enabled'     => $accepts_skill,
			'source'         => 'intent_tools',
		] ) );
	}
}, 30 );

// Adapter B: BCN_Notebook_Tool_Registry → BizCity_Tool_Registry
add_action( 'init', static function () {
	if ( ! class_exists( 'BCN_Notebook_Tool_Registry' ) || ! class_exists( 'BizCity_Tool_Registry' ) ) {
		return;
	}
	foreach ( BCN_Notebook_Tool_Registry::get_all() as $type => $tool ) {
		BizCity_Tool_Registry::register( $type, array_merge( $tool, [
			'slug'           => $type,
			'tool_type'      => 'notebook',
			'studio_enabled' => true,
			'at_enabled'     => false,
			'accepts_skill'  => false,
			'content_tier'   => 1,
			'input_fields'   => [],
			'source'         => 'notebook_registry',
		] ) );
	}
}, 31 );

// Seal the registry so later code cannot mutate it
add_action( 'init', static function () {
	if ( class_exists( 'BizCity_Tool_Registry' ) ) {
		BizCity_Tool_Registry::seal();
	}
}, 32 );
