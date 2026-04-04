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

/* ── Auto-load all tool group bootstraps ──────────────────────────── */
$tool_groups = [
	// ─── Active groups (priority ordered by each bootstrap) ───
	'planning',      // Tier 0 — build_workflow, knowledge_* (priority 15)
	'scheduler',     // Timeline — 8 scheduler atomic tools (priority 16)
	'content',       // Content — 36 atomic generators (priority 25)
	'distribution',  // Delivery — post, send, publish (priority 26)
	'workspace_google', // Google Workspace — Docs, Sheets, Slides, Drive (priority 27)

	// ─── Future groups (empty scaffolds) ───
	'web',       // Phase 2.2  — HTTP, research, scraping
	'memory',    // Phase 2.3  — Working memory via knowledge sources
	'data',      // Phase 2.5  — Data/structured tools
	'compute',   // Phase 2.6  — Utility/compute tools
	'coding',    // Phase 2.7  — Code generation/analysis
	'media',     // Phase 2.8  — Image/audio/video
	'project',   // Phase 2.9  — Workspace management
	'task',      // Phase 2.10 — Task planning
];

foreach ( $tool_groups as $group ) {
	$bootstrap = BIZCITY_TOOLS_DIR . $group . '/bootstrap.php';
	if ( file_exists( $bootstrap ) ) {
		require_once $bootstrap;
	}
}
