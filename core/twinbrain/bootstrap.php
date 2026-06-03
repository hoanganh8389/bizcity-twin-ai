<?php
/**
 * Bizcity Twin AI — TwinBrain Core Bootstrap (PHASE 0.36 v3)
 *
 * Não tổng / Central Brain Orchestrator — **BE-only** runtime.
 *
 * As of PHASE-0.36 v3 (2026-05-10) TwinBrain has NO standalone SPA. The entire
 * UX (Ask Brain composer, KG workspace resize, History tab, BrainTimeline)
 * lives inside `modules/twinchat/ui/` and is toggled via `chatMode='brain'`.
 * This module ships only:
 *   1. REST endpoints (`bizcity-twinbrain/v1/*`)
 *   2. MPR runtime classes (Selector / Matcher / Runner / Synthesizer)
 *   3. Schema view (`bizcity_brain_turns`)
 *   4. 3 new event_type registrations on `bizcity_twin_event_stream`
 *   5. A redirect from the legacy admin page to TwinChat (mode=brain)
 *
 * Loaded from `bizcity-twin-ai.php` via `core/twinbrain/bootstrap.php`.
 *
 * Spec: PHASE-0.36-TWINBRAIN-CENTRAL-BRAIN.md
 *
 * Hard rules respected:
 *   - R-EVT-1/2/4 — uses bizcity_twin_event_stream + 1 SSE channel only.
 *     3 new event_types: brain_perspective_selected, brain_perspective_answer,
 *     brain_tool_intent. NO new log/audit/trace tables.
 *   - R-GW         — every LLM call goes through bizcity-llm-router.
 *   - R-VFS        — retrieval via BizCity_KG_Vector_File_Store::search().
 *   - R-TG-*       — does NOT bypass Guru persona resolution.
 *
 * Wave 0 (this commit): bootstrap + runtime stub + REST shell + REST registration.
 * Wave 1+ (TODO):       NotebookSelector, ToolIntentMatcher, PerspectiveRunner,
 *                       Synthesizer, React UI. See PHASE-0.36 §8 sprints.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( defined( 'BIZCITY_TWINBRAIN_LOADED' ) ) {
	return;
}
define( 'BIZCITY_TWINBRAIN_LOADED', true );

if ( ! defined( 'BIZCITY_TWINBRAIN_DIR' ) ) {
	define( 'BIZCITY_TWINBRAIN_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_URL' ) ) {
	define( 'BIZCITY_TWINBRAIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_VERSION' ) ) {
	define( 'BIZCITY_TWINBRAIN_VERSION', '0.36.0-w0' );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_REST_NS' ) ) {
	define( 'BIZCITY_TWINBRAIN_REST_NS', 'bizcity-twinbrain/v1' );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_K_DEFAULT' ) ) {
	define( 'BIZCITY_TWINBRAIN_K_DEFAULT', 5 );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_K_MAX' ) ) {
	define( 'BIZCITY_TWINBRAIN_K_MAX', 7 );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD' ) ) {
	define( 'BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD', 0.55 );
}
if ( ! defined( 'BIZCITY_TWINBRAIN_TOOL_AUTOSUGGEST_THRESHOLD' ) ) {
	define( 'BIZCITY_TWINBRAIN_TOOL_AUTOSUGGEST_THRESHOLD', 0.7 );
}

require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-runtime.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-notebook-selector.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-tool-intent-matcher.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-perspective-runner.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-synthesizer.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-rest.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-schema.php';

// Phase 0.36-UNIFIED TBR.W8 (2026-05-21) — Seed 2 global skills cho Web
// Research Fallback Layer vào bizcity_skills (idempotent qua UNIQUE key).
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-skills-seeder.php';

// Phase 0.36-UNIFIED TBR.W6 (2026-05-21) — Quick Web engine (1 search + 1 LLM
// synth, ~3-4s). Stage 2.5 fast path; emits web_research_started /
// web_search_done / web_synthesize_done qua bizcity_twin_event_stream.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-quick.php';

// Phase 0.36-UNIFIED TBR.W7 (2026-05-21) — Deep Web engine (ReAct agent,
// max 5 iter, ~8-12s). Stage 2.5 depth path; emits 5 web_* events incl.
// per-iteration web_react_step (port từ tavily-chat-main create_react_agent).
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-deep.php';

// Phase 0.36-UNIFIED TBR.W14 (2026-05-22) — Social Listening engine (1 search
// + 1 LLM synth, ~4-5s) bound to TikTok/Reddit/Instagram/X/Facebook/LinkedIn
// qua `include_domains`. Port từ tavily-cookbook-main/.../social_media.py.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-social.php';

// Phase 0.36-UNIFIED TBR.W15 (2026-05-22) — Company Intelligence engine
// (1 news search + 1 site crawl + 1 LLM synth, ~12-18s). Port từ
// tavily-cookbook-main/.../company_intelligence_deep_agent.py (ReAct →
// linear pipeline để tránh trùng Web_Deep).
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-company.php';

// Phase 0.36-UNIFIED TBR.W17 (2026-05-27 / 2026-05-28) — Vertical Web Research
// Wave 1 (6 verticals). Mỗi engine = 1 Tavily search advanced + include_domains
// (allowlist tier A-D) + 1 LLM synth. RFC:
// core/twinbrain/docs/TWINBRAIN-EXT-VERTICAL-WEB-RESEARCH.md.
//   • med     — y khoa, citation [med:N], disclaimer ⚕️, stance cap conditional
//   • scholar — học thuật, citation [sch:N] + (Author, Year)
//   • nutri   — dinh dưỡng, citation [nut:N], disclaimer 🥗
//   • law     — pháp luật VN, citation [law:N], disclaimer 📜
//   • tax     — thuế VN, citation [tax:N], disclaimer 💰
//   • gov     — chính sách / tin VBQPPL mới, citation [gov:N], time=week
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-med.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-scholar.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-nutri.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-law.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-tax.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-gov.php';

// Phase 0.36-UNIFIED TBR.W11 (2026-05-21) — Guru `allow_web_fallback` flag:
// schema migration + filter `bizcity_twinbrain_web_mode_effective` gate +
// REST GET/POST `/guru/{id}/web-fallback` (manage_options only).
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-guru-web-flag.php';

// Phase 0.36-UNIFIED TBR.W10 (2026-05-21) — Citation Resolver baseline
// (R-BRAIN-2). Single source of truth cho citation token → resolved record.
// Cover 6 namespaces: mem|faq|nb|src|ent|web. REST GET /citations/resolve.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-citation-resolver.php';

// Phase 0.36-UNIFIED TBR.W16 (2026-05-21) — Final Composer (Layer 4.5).
// Streams câu trả lời cuối cùng cho user qua SSE (`final_token` events) sau
// khi Synthesizer (Layer 4) trả về structured output. Dùng
// BizCity_LLM_Client::chat_stream() → gateway /llm/router/v1/chat/stream.
// Degrade gracefully về synthesizer.answer_md khi gateway down.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-final-composer.php';

// Phase 0.36-UNIFIED TBR.W20 (2026-05-28) — Agent ReAct Runner.
// Generic ReAct loop over Tool_Registry; activates when REST request sets
// `mode=agent`. Whitelist (filter `bizcity_twinbrain_agent_allowed_tools`)
// default = retriever + memory tools only (no producer/distributor for
// safety). Max 5 iter, 60s wall budget. Events: agent_loop_started /
// agent_step_done / agent_loop_done via Event_Bus + SSE bridge.
// Guarded with file_exists() — production may lag deploy. Runtime branch
// (`mode=agent`) checks class_exists() before calling and degrades gracefully.
$bizcity_twinbrain_agent_runner = BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-agent-runner.php';
if ( file_exists( $bizcity_twinbrain_agent_runner ) ) {
	require_once $bizcity_twinbrain_agent_runner;
}
unset( $bizcity_twinbrain_agent_runner );

// Phase 0.36-UNIFIED Wave 2.8 (2026-05-22) — Memory Layer.
// TBR.MEM-2: Memory_Recall (Layer 0.5) — pulls 4 tiers of user memory and
// renders a Memory_Block injected into Final_Composer system prompt.
// TBR.MEM-4: Memory_Writer (Layer 4.7) — Mode 1 regex extracts explicit
// "hãy nhớ ..." phrases after final_done and persists to memory_users.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-memory-recall.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-memory-writer.php';

// Phase 0.36-UNIFIED Wave 2.8c (2026-05-24) TBR.MEM-C1 — Owner-self Memory
// Hub REST endpoints (/memory/me) cho FE BrainMemoryButton + MemoryHubDrawer.
// Permission: is_user_logged_in + force user_id = current.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-rest-memory-me.php';

// Phase 0.36-UNIFIED Wave 2.8 (2026-05-24) TBR.MEM-6 — Memory Tool Dispatcher
// (Mode 3 MemGPT-style function-call). 3 tool: memory_remember / memory_forget
// / memory_recall đăng ký qua filter `bizcity_twin_register_tool`. Final
// Composer inject prompt section khi flag `bizcity_twinbrain_memory_tools_enabled`
// ON. Runtime gọi dispatcher sau final_done → parse text → execute → emit
// memory_tool_call / memory_tool_result / memory_tool_error events.
// Tools (reorganized 2026-05-24): `core/twinbrain/tools/<domain>/<file>.php`.
// Domains hiện có: memory, sheet. Plan thêm: producer, distributor, canvas.
// Memory dispatcher (runtime infra) vẫn nằm ở `includes/`.
require_once BIZCITY_TWINBRAIN_DIR . 'tools/memory/class-twinbrain-memory-tool-remember.php';
require_once BIZCITY_TWINBRAIN_DIR . 'tools/memory/class-twinbrain-memory-tool-forget.php';
require_once BIZCITY_TWINBRAIN_DIR . 'tools/memory/class-twinbrain-memory-tool-recall.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-memory-tool-dispatcher.php';

add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
	if ( ! is_array( $registry ) ) $registry = [];
	$registry['memory_remember'] = new BizCity_TwinBrain_Memory_Tool_Remember();
	$registry['memory_forget']   = new BizCity_TwinBrain_Memory_Tool_Forget();
	$registry['memory_recall']   = new BizCity_TwinBrain_Memory_Tool_Recall();
	return $registry;
}, 10 );

// Phase 0.36-UNIFIED Wave 2.8e (2026-05-24) TBR.TOOL-S1..S3 — TwinBrain
// Sheets producer tool. Installer dbDelta 2 bảng (`bizcity_sheets`,
// `bizcity_sheet_cells`) gated bởi option `bizcity_twinbrain_sheets_db_ver`.
// Enricher port LangGraph 3-stage Tavily Sheets pipeline (search → extract
// → store) qua gateway `BizCity_Research_Tool_Router` (R-GW-1). Tool
// `sheet_enrich` đăng ký vào registry để LLM emit text-tool block hoặc FE
// gọi qua REST. Citation token format `[sheet:S#<id>/r<row>c<col>]`.
require_once BIZCITY_TWINBRAIN_DIR . 'sheets/includes/class-sheets-installer.php';
require_once BIZCITY_TWINBRAIN_DIR . 'sheets/includes/class-sheet-enricher.php';
require_once BIZCITY_TWINBRAIN_DIR . 'tools/sheet/class-twinbrain-sheet-tool-enrich.php';
BizCity_TwinBrain_Sheets_Installer::instance();

add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
	if ( ! is_array( $registry ) ) $registry = [];
	$registry['sheet_enrich'] = new BizCity_TwinBrain_Sheet_Tool_Enrich();
	return $registry;
}, 11 );

add_action( 'rest_api_init', static function () {
	BizCity_TwinBrain_REST::instance()->register_routes();
	BizCity_TwinBrain_REST_Memory_Me::instance()->register_routes();
} );

// Ensure the bizcity_brain_turns VIEW + perspective columns exist per-blog
// (both idempotent, version-gated).
add_action( 'init', static function () {
	BizCity_TwinBrain_Schema::ensure_view();
	BizCity_TwinBrain_Schema::ensure_notebook_perspective_columns();
}, 20 );

// PHASE 0.36 v3 (2026-05-10) — TwinBrain has NO standalone SPA.
// All UI lives inside TwinChat (mode='brain'). The legacy admin page
// `bizcity-twinbrain` redirects to TwinChat with the brain mode flag so any
// bookmarks / external links keep working.
add_action( 'admin_menu', static function () {
	add_submenu_page(
		'bizcity-ai',
		__( 'Twin Brain (Não tổng)', 'bizcity-twin-ai' ),
		__( 'Twin Brain', 'bizcity-twin-ai' ),
		'read',
		'bizcity-twinbrain',
		static function () {
			echo '<div class="wrap"><p>' . esc_html__( 'Đang chuyển về TwinChat (Ask Brain mode)…', 'bizcity-twin-ai' ) . '</p></div>';
		}
	);
}, 30 );

add_action( 'admin_init', static function () {
	if ( ! is_admin() || empty( $_GET['page'] ) || $_GET['page'] !== 'bizcity-twinbrain' ) {
		return;
	}
	$target = add_query_arg(
		[ 'page' => 'bizcity-twinchat', 'mode' => 'brain' ],
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $target );
	exit;
} );
