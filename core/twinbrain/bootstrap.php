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
	// [2026-08-20 Johnny Chu] HOTFIX-DIAGNOSTICS-CLI — an early compatibility
	// loader may set the module sentinel before the full class list is loaded.
	// Recover the provider-free adapter instead of treating the sentinel as proof
	// that every TwinBrain contract is available.
	if ( ! class_exists( 'BizCity_TwinBrain_Intent_Compat_Adapter', false ) ) {
		$intent_compat_file = __DIR__ . '/includes/class-twinbrain-intent-compat-adapter.php';
		if ( is_readable( $intent_compat_file ) ) {
			require_once $intent_compat_file;
		}
		unset( $intent_compat_file );
	}
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

// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — default attachment/vision/file intake layer before Notebook retrieval.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-multimodal-intake-layer.php';
// [2026-08-07 Johnny Chu] V4-TRIAGE — classify no-goal conversation before Goal Parser/MPR dispatch.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-pre-mpr-triage.php';
// [2026-08-16 Johnny Chu] MPR-V5-TEMPORAL — load the deterministic temporal context resolver before Runtime hooks.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-temporal-context-resolver.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-runtime.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-notebook-selector.php';
// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — classify conversational fallback before full Brain dispatch.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-conversation-router.php';
// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — shared pending confirmation for specialized route selection.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-conversation-confirmation.php';
// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G0 — deterministic goal lifecycle contract shared by every TwinBrain surface.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-state.php';
// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — event snapshot repository and Intent compatibility boundary.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-repository.php';
// [2026-08-03 Johnny Chu] G12.3 — current Goal Contract projection and per-goal JSONL trace; provisioning remains explicit, never file-scope DDL.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-contract-store.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-intent-adapter.php';
// [2026-08-19 Johnny Chu] MPR-V5.10-COMPAT — deterministic Slot/Clarify/Confirm/Memory compatibility envelope for workflow-by-workflow migration.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-intent-compat-adapter.php';
// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — load deterministic Goal Delta before Runtime hooks.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-delta.php';
// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G8 — load deterministic Parser and Reflector before Runtime hooks.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-parser.php';
// [2026-08-16 Johnny Chu] MPR-V5-GOAL-ALIGNMENT — load the pure triage-to-goal alignment gate before Runtime hooks.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-alignment.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-reflector.php';
// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G9 — load the bounded deterministic continuity question engine.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-question-engine.php';
// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — load identity-scoped Goal REST routes.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-rest.php';
// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G7 — load stale Goal Loop scanner and abandoned transition policy.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-scheduler.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-goal-loop-runtime.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-hil-spec.php';
// [2026-08-15 Johnny Chu] MPR-V5-HIL-COMPILER — load the pure prompt compiler after the HIL validator.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-hil-compiler.php';
// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — bounded slot-collection instance: state -> extractor -> coordinator -> event-sourced repository.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-hil-state.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-hil-extractor.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-hil-runtime.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-hil-repository.php';
// [2026-08-16 Johnny Chu] MPR-V5-MEDIA — canonical attachments[] candidate resolver for HIL media confirmation.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-media-candidate-resolver.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-tool-intent-matcher.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-perspective-runner.php';
// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — load Source File Deep Layer before Notebook Source Layer builds file briefs.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-source-file-deep-layer.php';
// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — load Notebook Source Layer before runtime turn compose uses source maps.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-notebook-source-layer.php';
// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — subject-first customer profile layer for Notebook/vertical personalization.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-subject-profile-layer.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-synthesizer.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-rest.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-schema.php';

// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — Conversation thread manager
// + REST surface (/sessions CRUD). Spec:
// core/twinbrain/docs/sessions/TWINBRAIN-FEATURE-BRAIN-SESSIONS.md §11.
// Reorg 2026-06-03: BS group consolidated under includes/sessions/ for
// discoverability (manager + REST + future companion-context controller).
require_once BIZCITY_TWINBRAIN_DIR . 'includes/sessions/class-twinbrain-sessions-manager.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/sessions/class-twinbrain-sessions-rest.php';
// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — expose the shared channel boundary and dual-owner session resolver.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-brain-session-resolver.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-channel-adapter.php';
// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — load concrete channel identity policy adapters.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-channel-adapters.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-progress-notice-policy.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-progress-notice-projector.php';
BizCity_TwinBrain_Progress_Notice_Projector::init();
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-vertical-bridge-registry.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-guru-focus-validator.php';
BizCity_TwinBrain_Goal_Loop_Scheduler::init();

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

// [2026-06-04 Johnny Chu] PHASE-A C.3b — Web_Astro engine (multi-step astro
// mode: LLM-classify period → CAP filter (natal+transit DB-first) → return
// passages for Final_Composer). Parallel với web-deep/web-law nhưng KHÔNG
// gọi Tavily — nguồn là artifact nội bộ qua bizcoach-pro CAP provider.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-astro.php';

// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — Products vertical shared stack.
// Provider + resolver + composer are shared by Ask Brain runtime and
// Automation action.run_products* blocks. Web engine handles web_mode=products.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-product-provider.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-product-composer.php';
// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - load canonical source-of-truth link builder for products vertical.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-product-source-layer.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-product-resolver-service.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-products.php';
// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — load the admin-gated Woo BizOps resolver and web-mode engine.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-woo-bizops-resolver-service.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-web-woo-bizops.php';
// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — load the shared sensitive-capability decision boundary before vertical engines.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-guru-policy.php';

// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — Workflow-Driven Brain Pipeline.
// Generic engine: chạy bất kỳ automation workflow nào AS brain pipeline khi
// user gõ /skill trong twinchat hoặc twinweb. Emits workflow_started /
// workflow_step / workflow_completed SSE events; terminal llm.compose node
// calls Final_Composer::compose_stream(). Requires BizCity_Twin_Artifact_Normalizer
// (core/twin-core) and BizCity_Twin_Event_Taxonomy v7+.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-workflow-pipeline.php';

// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W3 — Built-in skill seeder.
// Contributes 3 defaults (web_research_quick, web_research_deep, astro_quick)
// via filter 'bizcity_twinbrain_builtin_skills'. Tier 3 in 3-tier resolution.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-builtin-skills.php';
BizCity_TwinBrain_Builtin_Skills::init();

// Phase 0.36-UNIFIED TBR.W11 (2026-05-21) — Guru `allow_web_fallback` flag:
// schema migration + filter `bizcity_twinbrain_web_mode_effective` gate +
// REST GET/POST `/guru/{id}/web-fallback` (manage_options only).
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-guru-web-flag.php';

// Phase 0.36-UNIFIED TBR.W10 (2026-05-21) — Citation Resolver baseline
// (R-BRAIN-2). Single source of truth cho citation token → resolved record.
// Cover 6 namespaces: mem|faq|nb|src|ent|web. REST GET /citations/resolve.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-citation-resolver.php';

// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN A1 — Astro Recall read layer.
// Lightweight collector: user_id → primary coachee → natal/report/transit → context_md.
// Injected into Final Composer extra_context_md for regular brain turns with astro keywords.
// Does NOT require web_mode='astro' — runs as silent Layer 0.x enrichment.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-astro-recall.php';

// [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — unified subject profile
// service shared by TwinBrain astro runtime and automation action.run_astro.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-astro-subject-profile-service.php';

// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — shared relation
// assessment service + composer used by TwinBrain runtime and automation.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-astro-relation-assessment-service.php';
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-astro-relation-composer.php';

// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — shared composer for
// transit each-day outputs (LLM + deterministic fallback), reused by Web Astro
// runtime and Automation action.run_astro_transit for consistent per-day tone
// and final recommendation contract.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-astro-transit-eachday-composer.php';

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

// [2026-06-04 Johnny Chu] PHASE-A C.3c — Mode Context Memory (Layer 4.8).
// Reusable standard: bất kỳ mode nào (astro/web/law…) persist 1 context
// summary của lượt hỏi vào memory tier gắn session_id + provenance source_url.
// Spec: core/docs/CORE-PHASE-A-MODE-MEMORY.md. Reuse bizcity_memory_users
// (tier=extracted) → KHÔNG đụng schema → R-DCL không phát sinh.
require_once BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-mode-memory.php';

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
// [2026-06-04 Johnny Chu] PHASE-A A.0 — canonical paths after sheets→tools/sheet move.
require_once BIZCITY_TWINBRAIN_DIR . 'tools/sheet/class-twinbrain-sheet-installer.php';
require_once BIZCITY_TWINBRAIN_DIR . 'tools/sheet/class-twinbrain-sheet-enricher.php';
require_once BIZCITY_TWINBRAIN_DIR . 'tools/sheet/class-twinbrain-sheet-tool-enrich.php';
BizCity_TwinBrain_Sheets_Installer::instance();

add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
	if ( ! is_array( $registry ) ) $registry = [];
	$registry['sheet_enrich'] = new BizCity_TwinBrain_Sheet_Tool_Enrich();
	return $registry;
}, 11 );

// [2026-06-03 Johnny Chu] SCH-NC W6 — Scheduler HIL tool. LLM master xuất
// `<tool name="scheduler_set_reminder">{...}</tool>` → tạo event status='draft'
// → gửi confirm envelope qua Gateway Sender → user reply OK/Hủy/Sửa được match
// bởi `BizCity_Scheduler_HIL_Router`. Reminder thật fire qua cron sau khi
// status flip 'active'.
require_once BIZCITY_TWINBRAIN_DIR . 'tools/scheduler/class-twinbrain-scheduler-tool-set-reminder.php';

add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
	if ( ! is_array( $registry ) ) $registry = [];
	$registry['scheduler_set_reminder'] = new BizCity_TwinBrain_Scheduler_Tool_Set_Reminder();
	return $registry;
}, 12 );

// [2026-06-13 Johnny Chu] PHASE-0.40 G3 P6 — ingest_document producer tool.
// Allows admin/staff to ingest text or URL into the guru's attached notebook via chat.
// tool_class = 'P' (Producer) — requires allow_producer in admin-chat grant.
// [2026-06-21 Johnny Chu] HOTFIX — guard file_exists to prevent fatal cascade when file
// is missing on server (caused twinweb routes to 404 via PHP crash on REST requests).
if ( file_exists( BIZCITY_TWINBRAIN_DIR . 'tools/knowledge/class-twinbrain-tool-ingest-document.php' ) ) {
	require_once BIZCITY_TWINBRAIN_DIR . 'tools/knowledge/class-twinbrain-tool-ingest-document.php';

	add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
		if ( ! is_array( $registry ) ) $registry = [];
		$registry['ingest_document'] = new BizCity_TwinBrain_Tool_Ingest_Document();
		return $registry;
	}, 13 );
}

add_action( 'rest_api_init', static function () {
	BizCity_TwinBrain_REST::instance()->register_routes();
	BizCity_TwinBrain_Goal_Loop_REST::instance()->register_routes();
	BizCity_TwinBrain_REST_Memory_Me::instance()->register_routes();
	// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — /sessions CRUD routes.
	BizCity_TwinBrain_Sessions_REST::instance()->register_routes();
} );

// Ensure the bizcity_brain_turns VIEW + perspective columns exist per-blog
// (both idempotent, version-gated).
add_action( 'init', static function () {
	BizCity_TwinBrain_Schema::ensure_view();
	BizCity_TwinBrain_Schema::ensure_notebook_perspective_columns();
	// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-1 — sessions VIEW projection.
	BizCity_TwinBrain_Schema::ensure_sessions_view();
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
