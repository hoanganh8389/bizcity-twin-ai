<?php
/**
 * Bizcity Twin AI — Diagnostics Core Bootstrap (PHASE-0.40)
 *
 * Central table-inventory + soft-guard registry for the whole bizcity-twin-ai
 * ecosystem (core/, modules/, plugins/). Lists every `bizcity_*` table managed
 * by the platform, surfaces presence / row-count / size per blog, and exposes
 * a single Tools admin page + REST endpoint so operators can audit shard drift
 * (multisite + WPDB_Router slave3/slave10) without grepping the codebase.
 *
 * Spec: PHASE-0.40-DIAGNOSTICS-TOOLS.md
 *
 * Public APIs:
 *   - BizCity_Diagnostics_Table_Registry::get_tables()    — all registered tables
 *   - BizCity_Diagnostics_Table_Inspector::inspect_all()  — physical status snapshot
 *   - BizCity_Diagnostics_Table_Activity                    — sampled read/write telemetry
 *   - Filter `bizcity_diagnostics_register_tables`        — modules add their tables
 *   - Action `bizcity_diagnostics_notice`                 — modules surface soft-guard banners
 *   - REST GET /wp-json/bizcity-diagnostics/v1/tables     — JSON snapshot
 *   - Admin page Tools → BizCity Diagnostics
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      2026-05-20
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( defined( 'BIZCITY_DIAGNOSTICS_LOADED' ) ) {
	return;
}
define( 'BIZCITY_DIAGNOSTICS_LOADED', true );
// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — automation REST may define DIR early to run additive schema repair before diagnostics bootstrap.
if ( ! defined( 'BIZCITY_DIAGNOSTICS_DIR' ) ) {
	define( 'BIZCITY_DIAGNOSTICS_DIR', __DIR__ . '/' );
}
define( 'BIZCITY_DIAGNOSTICS_VERSION', '0.41.0' );
define( 'BIZCITY_DIAGNOSTICS_REST_NS', 'bizcity-diagnostics/v1' );

// [2026-07-28 Johnny Chu] R-DDV — missing optional probe files must not break the diagnostics bootstrap.
// [2026-08-09 Johnny Chu] R-PERF-LOADER-A - queue probes at bootstrap time;
// diagnostics screen, diagnostics REST, and WP-CLI flush the queue on demand.
if ( ! isset( $GLOBALS['bizcity_diagnostics_probe_queue'] ) ) {
	$GLOBALS['bizcity_diagnostics_probe_queue'] = array();
}
if ( ! function_exists( 'bizcity_diagnostics_require_probe' ) ) {
	function bizcity_diagnostics_require_probe( string $probe_file ): void {
		$probe_file = ltrim( $probe_file, '/' );
		if ( $probe_file === '' ) {
			return;
		}
		$GLOBALS['bizcity_diagnostics_probe_queue'][ $probe_file ] = true;
	}
}
if ( ! function_exists( 'bizcity_diagnostics_load_probes_once' ) ) {
	function bizcity_diagnostics_load_probes_once(): void {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		$queue = isset( $GLOBALS['bizcity_diagnostics_probe_queue'] )
			? array_keys( (array) $GLOBALS['bizcity_diagnostics_probe_queue'] )
			: array();
		$retired_probe_files = array( 'class-probe-automation-runtime.php', 'class-probe-automation-runtime-impl.php' );
		foreach ( $queue as $probe_file ) {
			// [2026-08-17 Johnny Chu] R-DDV-PROBE-RETIRE — stale queue entries must not require retired class-bearing probe artifacts.
			if ( in_array( $probe_file, $retired_probe_files, true ) ) {
				continue;
			}
			$probe_path = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/' . $probe_file;
			if ( file_exists( $probe_path ) ) {
				// [2026-08-16 Johnny Chu] R-DDV-PROBE-LOAD — stale/renamed probe paths must not redeclare a class already loaded by another active path.
				$source = is_readable( $probe_path ) ? (string) file_get_contents( $probe_path ) : '';
				$declared_class = '';
				if ( $source !== '' && preg_match( '/\b(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $source, $match ) ) {
					$declared_class = (string) $match[1];
				}
				if ( $declared_class !== '' && class_exists( $declared_class, false ) ) {
					continue;
				}
				require_once $probe_path;
			}
		}
	}
}

require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-table-registry.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-table-inspector.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-column-inspector.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-installer-resolver.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-notices.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-rest.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-orphan-cleaner.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-site-provisioner.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/installer-registry.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-error-reporter.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/trait-rest-error.php';

// Phase 0.41 L9.a — Smoke-Test Wizard runtime.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-smoke-runner.php';

// [2026-08-09 Johnny Chu] R-PERF-LOADER-HOOK - hook telemetry is opt-in to
// Diagnostics/REST/CLI contexts; ordinary admin pages do not retain snapshots.
// Keep this class load here, but defer probe flushing until the full queue has
// been declared below. Flushing here would permanently mark the lazy loader
// complete before later probe registrations are added.
if ( function_exists( 'bizcity_diagnostics_should_load_probes' )
	&& bizcity_diagnostics_should_load_probes() ) {
	require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-loader-hook-panel.php';
	BizCity_Diagnostics_Loader_Hook_Panel::init();
}
// [2026-08-16 Johnny Chu] DIAGNOSTICS-PROBE-QUEUE-FIX - queue the observe-only
// loader trace completeness probe; it is loaded only on Diagnostics/REST/CLI.
bizcity_diagnostics_require_probe( 'class-probe-loader-trace-completeness.php' );
// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W3 - queue the observe-only
// canonical owner audit beside trace completeness; no loader is blocked.
bizcity_diagnostics_require_probe( 'class-probe-loader-ownership.php' );
// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W3 - queue hook/route/cron
// registration integrity checks; REST is skipped when no REST server exists.
bizcity_diagnostics_require_probe( 'class-probe-loader-registration-integrity.php' );
// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W4 - queue the read-only
// TwinCore semantic span continuity probe.
bizcity_diagnostics_require_probe( 'class-probe-runtime-flow-continuity.php' );
// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W5 - queue opt-in cache
// semantic trace assertion; normal requests do not emit cache spans.
bizcity_diagnostics_require_probe( 'class-probe-cache-trace-integrity.php' );
// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W5 - queue forensic user-meta
// trace assertion; normal requests do not register metadata filters.
bizcity_diagnostics_require_probe( 'class-probe-user-meta-trace-integrity.php' );
bizcity_diagnostics_require_probe( 'class-probe-kg-seeding.php' );
// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — verify runtime skill rows
// remain active while system-owned rows stay out of the Journal tree.
bizcity_diagnostics_require_probe( 'class-probe-skills-journal-isolation.php' );
// [2026-07-27 Johnny Chu] PHASE-0.53-MCP Wave A — MCP gateway DDV probe.
bizcity_diagnostics_require_probe( 'class-probe-mcp-gateway.php' );

// [2026-07-27 Johnny Chu] HOTFIX — load by default again; probe file itself now follows standard class_exists guard pattern.
bizcity_diagnostics_require_probe( 'class-probe-notebook-multitenant-isolation.php' );

// 2026-06-04 — R-DDV row cho Google Hub canonical 1-API. Read-only,
// verify connect-URL builder + service catalog + status snapshot.
bizcity_diagnostics_require_probe( 'class-probe-google-hub.php' );

// [2026-06-04 Johnny Chu] HOTFIX R-GW-8 — Account quota & entitlement probe.
// 3-layer: disk + loader + 3 hub REST calls (account/info, account/limits,
// account/entitlement). Shows credits, tier, KG quota config, service limits.
// Helps diagnose learning jobs stuck on "quota hôm nay đã hết".
bizcity_diagnostics_require_probe( 'class-probe-account-quota-entitlement.php' );

// Phase 0.36-UNIFIED TBR.W5 (2026-05-21) — Gateway-verified Web Search ping
// cho Web Research Fallback Layer. R-GW: dùng BizCity_Search_Client thay vì
// provider key client-side (probe cũ `class-probe-search-web.php` outdated).
bizcity_diagnostics_require_probe( 'class-probe-web-search-ping.php' );

// Phase 0.36-UNIFIED TBR.W7-fix-1 (2026-05-21) — Real-call probe cho Web Deep
// ReAct agent. Gọi thật BizCity_TwinBrain_Web_Deep::run() để bắt các bug như
// `forced_final:budget_or_iter_cap`, empty answer, missing [web:N] citations.
// Thay thế cho debug wp-cli command (operator có thể run từ admin UI).
bizcity_diagnostics_require_probe( 'class-probe-web-deep-llm.php' );

// Phase 0.36-UNIFIED TBR.W17 (2026-05-27) — Real-call probe cho Web Med
// vertical. Kiểm tra allowlist hits, citation [med:N], disclaimer ⚕️, và
// stance cap (med KHÔNG BAO GIỜ 'confident'). RFC:
// core/twinbrain/docs/TWINBRAIN-EXT-VERTICAL-WEB-RESEARCH.md §7.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-web-med.php' );

// Phase 0.36-UNIFIED TBR.W17 (2026-05-28) — Vertical Wave 1 probes (5):
// scholar / nutri / law / tax / gov. Mỗi probe gọi thật engine tương ứng
// để verify allowlist hit + citation + (disclaimer nếu có) + stance.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-web-scholar.php' );
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-web-nutri.php' );
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-web-law.php' );
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-web-tax.php' );
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-web-gov.php' );

// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — dedicated DDV probe for products vertical.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-products.php' );

// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER — DDV probe for
// Layer 4.2 source-of-truth links contract (links + counters + source block).
bizcity_diagnostics_require_probe( 'class-probe-products-source-layer.php' );

// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — DDV for phone identity, Woo
// BizOps REST/Engine/Action wiring and user-points contact projection.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-woo-bizops.php' );

// Phase 0.36-UNIFIED TBR.W18 (2026-05-28) — Brain Auto-Degrade Chat probe.
// Validate compose_chat_stream() + eligibility filters cho luồng chat tự
// nhiên khi K=0 candidates + memory ≥120B (skip Perspective/Tool/Synthesizer
// layers, stream chat-tone answer chỉ dùng memory_block).
// Guard with file_exists() — newly added 2026-05-28; production may lag
// behind before file is rsync'd up. Skip silently rather than fatal.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-brain-auto-degrade.php' );

// Phase 0.36-UNIFIED TBR.W20 (2026-05-28) — Agent ReAct Runner probe.
// Validate Agent_Runner::run() + whitelist filter + agent_loop_started/done
// + agent_step_done event taxonomy. TỐN ~1-3 LLM call.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-agent-react.php' );

// Phase 0.36-UNIFIED TBR.W19 (2026-05-21) — Real-call probe cho Final Composer
// (Layer 4.5). Gọi thật compose_stream() với synthesizer giả + perspectives
// giả, đo deltas + citation preservation. Validate streaming pipeline BE
// end-to-end trước khi đụng FE work (TBR.W18).
bizcity_diagnostics_require_probe( 'class-probe-final-compose.php' );

// [2026-06-04 Johnny Chu] PHASE-A C.0b — DDV probe for BizCoach Pro Astro Transit
// Resolver (DB-first → cron prefetch fallback). Smoke check: class loaded,
// resolve() shape valid, CAP filter `bizcity_twin_context_artifacts` wired.
bizcity_diagnostics_require_probe( 'class-probe-astro-transit-resolver.php' );

// [2026-06-04 Johnny Chu] PHASE-A C.3b — DDV probe for TwinBrain Astro Mode
// pipeline. 3 layers: Disk (runtime file), Loader (class + stream_astro_mode
// method), Runtime (CAP filter subscriber + Final_Composer available).
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-astro-mode.php' );

// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — DDV probes for readiness gate
// and automation per-day message loop wiring.
bizcity_diagnostics_require_probe( 'class-probe-astro-readiness-gate.php' );

bizcity_diagnostics_require_probe( 'class-probe-astro-per-day-loop.php' );

// [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — DDV probe for
// astro_data_action_required runtime evidence + payload contract.
bizcity_diagnostics_require_probe( 'class-probe-astro-data-action-required.php' );

// [2026-07-04 Johnny Chu] PHASE-FAA2-DDV — DDV probe for FAA2 natal-wheel-chart
// (url-only) pipeline. 3 layers: Disk (provider file in bizcity-llm-router),
// Loader (class + supports + router), Runtime (live natal_wheel_chart call).
bizcity_diagnostics_require_probe( 'class-probe-astro-faa2-chart-svg.php' );

// [2026-07-09 Johnny Chu] PHASE-A5 — DDV probe for pro charts wave
// (synastry/composite/solar_return/lunar_return) across hub+client.
bizcity_diagnostics_require_probe( 'class-probe-astro-pro-charts-a5.php' );

// [2026-07-09 Johnny Chu] PHASE-A5 — DDV probe for tokenized anonymous share
// on Relations/Ephemeris/Transits Timeline (/me/tools/share + /public/tools/share).
bizcity_diagnostics_require_probe( 'class-probe-astro-tool-share-a5.php' );

// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — DDV probe for client plan sync
// routes (/bizcity-client/v1/entitlement/sync + /bizcity-client/v1/me/plan).
bizcity_diagnostics_require_probe( 'class-probe-client-plan-sync.php' );

// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — DDV probes for Hub commerce and
// license branches from Branch 18 API catalog.
bizcity_diagnostics_require_probe( 'class-probe-commerce-hub-checkout.php' );

bizcity_diagnostics_require_probe( 'class-probe-license-hub-entitlement-issue.php' );

// [2026-07-07 Johnny Chu] PHASE-FAA2-NEXT — DDV probe for relation/ashtakoot path
// (wrapper callsite contract + R-ERROR-UX payload shape in relation handlers).
bizcity_diagnostics_require_probe( 'class-probe-astro-relation-ashtakoot.php' );

// PHASE-0.35 GURU-ZALO-BOT §1.8 (2026-05-26) — Real-call probe cho unified
// Guru Runtime DTO contract. Verify reply() trả DTO hợp lệ + trace_id
// + event stream. Skip nếu chưa có character nào.
bizcity_diagnostics_require_probe( 'class-probe-guru-runtime.php' );

// Phase 0.36-UNIFIED Wave 2.8 TBR.MEM-12 (2026-05-22) — Real-call probes cho
// TwinBrain Memory Layer (Layer 0.5 Recall + Layer 4.7 Writer Mode 1+2). Plant
// __healthtest_ rows + cleanup; verify citation [mem:U#id] echo + idempotency
// per trace_id. Mode 3 (MemGPT) deferred → no probe.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-memory-recall.php' );

// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-1 — foundation smoke (read-only).
// Verify VIEW bizcity_brain_sessions + 5 brain_session_* event_types + 5 JSON schemas.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-brain-sessions.php' );

// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — Sessions CRUD real-call probe.
// Mint → VIEW → rename → list → archive cycle qua Sessions_Manager.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-brain-sessions-crud.php' );

// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — Mood sampler real-call probe.
// Synthesize cadence-3 turns → sample_mood() → verify event + VIEW.has_mood +
// Sessions_Manager::latest_mood() + Memory_Recall Tier F render + idempotency.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-mood-sampler.php' );
// R-TB-HYDRATE (2026-05-27) — Read-only probe guarding TwinBrain
// Perspective_Runner fallback hydration (regression guard for P0 bug where
// fetch_recent_passages / fetch_passages_by_keyword skipped Content_Router
// hydrate → empty notebook context → Final Composer collapsed to web-only).
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-retrieval-hydration.php' );
// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — DDV probe for Notebook Source Layer + answer source contract.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-notebook-source-layer.php' );
// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — DDV for default chat routing, workflow guide, and safe fuzzy suggestions.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-chat-default.php' );
// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G0 — deterministic state, closure, and event-contract DDV.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-goal-loop.php' );
// [2026-08-03 Johnny Chu] G12.7 — DDV for Goal Contract projection registration, changelog, and read-only runtime boundary.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-goal-contracts.php' );
// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — DDV for unified channel adapters, identity ownership, and group isolation.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-channel-unify.php' );
// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — DDV for Goal REST/UI shell and dist-only deployment policy.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-goal-loop-ui.php' );
// [2026-08-16 Johnny Chu] MPR-V5-HIL-DDV — synthetic HIL Spec/compiler contract probe; no provider or DB writes.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-hil.php' );
// [2026-08-16 Johnny Chu] MPR-V5-DDV — aggregate synthetic evidence probe; live canary is only exposed through /smoke/run-live.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-mpr-v5.php' );
// [2026-08-16 Johnny Chu] R-SCH-TARGET — verify shared Scheduler/Progress Notice target precedence and fail-closed routing.
bizcity_diagnostics_require_probe( 'class-probe-scheduler-target-v2.php' );
// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — DDV probe for default attachment/vision/file intake layer and MPR event contract.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-multimodal-intake.php' );
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-memory-writer-explicit.php' );
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-memory-writer-llm.php' );
// Wave 2.8b TBR.MEM-N5 (2026-05-23) — Notebook chat memory parity probe.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-memory-notebook-chat.php' );
// Wave 2.8c TBR.MEM-C7 (2026-05-24) — Hub REST CRUD probe (/memory/me).
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-memory-hub-rest.php' );
// Wave 2.8 TBR.MEM-6 (2026-05-24) — Mode 3 MemGPT-style memory tool
// dispatcher probe (memory_remember / memory_forget / memory_recall).
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-memory-tool-calls.php' );
// Wave 2.8e TBR.TOOL-S5 (2026-05-24) — TwinBrain Sheets 3-stage enricher
// real-call probe (create 1x3 sheet → enrich → verify cells + sources +
// aggregates + SSE events).
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-sheet-enrich.php' );
// Wave 2.8d TBR.MEM-D5e (2026-05-24) — Unified memory dual-write parity probe.
bizcity_diagnostics_require_probe( 'class-probe-memory-unified-dual-write.php' );
// Wave 2.8d TBR.MEM-D6 (2026-05-24) — Unified memory recall parity probe
// (legacy vs unified Memory_Recall tokens overlap ≥ 95%).
bizcity_diagnostics_require_probe( 'class-probe-memory-unified-recall-parity.php' );
// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-UNIFY — non-destructive W2 ZaloBot staging contract probe.
bizcity_diagnostics_require_probe( 'class-probe-zalobot-memory-unify.php' );

// Phase 0.41 L9.b — Schema Changelog Ledger + Auto-Create.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-changelog-loader.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-auto-create.php';

// Phase 0.99.3 (2026-06-01) — Module registry probe — surfaces 3rd-party
// modules registered via the `bizcity_register_module` filter.
bizcity_diagnostics_require_probe( 'class-probe-module-registry.php' );

// Phase 0.41 L9.b+ — Schema inventory meta-probe (drives Auto-Fix-All UX).
bizcity_diagnostics_require_probe( 'class-probe-schema-inventory.php' );
// [2026-08-01 Johnny Chu] PHASE-1.23-TABLE-ACTIVITY — DDV for runtime table activity telemetry and enriched inventory fields.
bizcity_diagnostics_require_probe( 'class-probe-table-activity.php' );
// [2026-08-01 Johnny Chu] PHASE-1.26-CORRELATION — read-only DDV for the shared
// event_uuid/trace_id/parent_event_uuid contract across chat log boundaries.
bizcity_diagnostics_require_probe( 'class-probe-chat-correlation.php' );

// Phase 0.41 L9.c — Structural wiring probes (research / upload / vector).
// NOTE: search probe lives at `class-probe-web-search-ping.php` (loaded above
// for Phase 0.36-UNIFIED TBR.W5); no duplicate registered here.
bizcity_diagnostics_require_probe( 'class-probe-research-deep.php' );
bizcity_diagnostics_require_probe( 'class-probe-upload-learning.php' );
bizcity_diagnostics_require_probe( 'class-probe-vector-graph.php' );
// [2026-07-27 Johnny Chu] PHASE-0.51-DDV - verify learning payload redaction at REST, SSE, and public-share boundaries.
bizcity_diagnostics_require_probe( 'class-probe-twinchat-learning-payload-redaction.php' );

// Phase 0.42 (2026-05-27) — LiteParse layout-preserving adapter probe.
// 11-step wiring check for Tier-2 PDF/Office/Image engine (CLI + sidecar),
// R-VFS screenshot path, R-DCL changelog ≥ 0.27.0, entitlement gate.
// File is gitignored (private addon, deployed via scp to Linux VPS) — guard
// with file_exists() so absence on dev clones is a no-op, never fatal.
$bizcity_liteparse_probe = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-liteparse.php';
if ( file_exists( $bizcity_liteparse_probe ) ) {
	require_once $bizcity_liteparse_probe;
}
unset( $bizcity_liteparse_probe );

// Phase 0.7 Wave F4.1c (2026-05-26) — KG Filestore "Learning" probe.
// Surfaces 16-table KG-Hub health + 3-day housekeeping cron heartbeat
// (backfill v1→v2, NULL embeddings, parity sha256). Drainable via
// Tools → BizCity KG Filestore → 🏥 Run housekeeping (all steps).
bizcity_diagnostics_require_probe( 'class-probe-kg-filestore-learning.php' );
// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — end-to-end ingest → zero-SQL-leak
// → hydrate → search (bin-first) → visualize → RAG citation evidence pack probe.
// Plants + cleans up a real __healthtest_ notebook/passage/entity/relation.
bizcity_diagnostics_require_probe( 'class-probe-kg-filestore-standalone.php' );
// [2026-07-24 Johnny Chu] PHASE-0.46-DDV — durable async source lifecycle evidence.
bizcity_diagnostics_require_probe( 'class-probe-kg-async-source-lifecycle.php' );
// [2026-07-24 Johnny Chu] PHASE-0.46 W1 DDV — channel "@notebook" capture bridge:
// scope isolation (group vs private) + content-hash dedup + message_id retry idempotency.
bizcity_diagnostics_require_probe( 'class-probe-channel-notebook-bridge.php' );

// [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — 5 new KG scenario probes covering
// the full user journey (upload → RAG ask → citation → multi-doc search → Guru binding),
// each exercising a DIFFERENT production code path than kg.filestore.standalone.
bizcity_diagnostics_require_probe( 'class-probe-kg-upload-attach-source.php' );
bizcity_diagnostics_require_probe( 'class-probe-kg-graph-rag-ask.php' );
bizcity_diagnostics_require_probe( 'class-probe-kg-citation-resolve.php' );
bizcity_diagnostics_require_probe( 'class-probe-kg-search-multi-doc-highlight.php' );
bizcity_diagnostics_require_probe( 'class-probe-kg-guru-notebook-binding.php' );

// Phase 6.6 S1.2 — Notebook skeleton coverage probe (R-SK-DOC §15).
bizcity_diagnostics_require_probe( 'class-probe-skeleton-coverage.php' );

// Phase 5 (2026-05-22) — Channel-gateway FB REST routes wiring probe
// (3-layer: disk / loader / runtime). Để debug 404 cho /facebook/* SPA tabs.
bizcity_diagnostics_require_probe( 'class-probe-channel-gateway-rest.php' );

// [2026-06-03 Johnny Chu] GURU-UI W0.4+W0.5 — channel binding stack probe.
// 3-layer DDV: disk + class load + DB table + REST inspector routes +
// listener resolve callable + orphan binding scan.
bizcity_diagnostics_require_probe( 'class-probe-channel-binding.php' );

// PHASE-CG-SCHEDULER v0.2 (2026-05-23) — FB Publisher bridge probe
// (3-layer: disk/loader/runtime + R-DCL changelog check + scheduler cron).
bizcity_diagnostics_require_probe( 'class-probe-fb-publisher.php' );

// PHASE-CORE-CRON v1.0 (2026-05-23) — Unified cron registry & dispatch health.
// Lists every job registered through BizCity_Cron_Manager; verifies tables
// (bizcity_cron_registry, bizcity_cron_runs), schedules, and last-run drift.
bizcity_diagnostics_require_probe( 'class-probe-cron-registry.php' );

// Phase 0.37 (2026-05-27) — Scheduler Automation Runner real-call probe.
// 3-layer R-DDV: disk wiring + loader hooks + real fire-now via Lab endpoint.
// PASS nếu fire-now returns ok=true + chain evidence in bizcity_cron_runs.
bizcity_diagnostics_require_probe( 'class-probe-scheduler-automation.php' );

// PHASE-N (2026-05-25) — Flows sub-module smoke (ported bizgpt-custom-flows).
// 3-layer + INSERT/SELECT/DELETE round-trip + REST route registration.
bizcity_diagnostics_require_probe( 'class-probe-cg-flows.php' );

// AUTOMATION BE-1 (2026-05-29) — Native xyflow automation backend smoke.
// 3-layer (disk/loader/runtime) + create workflow + enqueue run round-trip.
bizcity_diagnostics_require_probe( 'class-probe-automation.php' );

// SCENARIO BUILDER MVP (2026-06-01) — Trigger matcher ref-based + keywords[] OR-match.
// Synthetic FB referral payload → matched_ref event + run row; ref_unmatched fallthrough.
bizcity_diagnostics_require_probe( 'class-probe-automation-matcher.php' );
// [2026-08-16 Johnny Chu] CCG-1/CCG-7 - exact #workflow_slug and progress Zone 2 boundary DDV.
bizcity_diagnostics_require_probe( 'class-probe-twinbrain-command-zone.php' );

// [2026-08-17 Johnny Chu] HOTFIX — retire the runtime error probe loader after repeated production redeclare fatals.
// The probe is read-only and must not block Diagnostics or ordinary requests.

// SCENARIO BUILDER MVP (2026-06-01) — Ad-image proxy loopback (rest_do_request).
// Verify route registered + permission pass + handler reachable (degraded path OK).
bizcity_diagnostics_require_probe( 'class-probe-automation-ad-image.php' );

// AUTOMATION HARDEN (2026-06-02) — publish_fb_post block chain probe
// (block → CRM Bridge → Scheduler Manager). R-DDV evidence cho bug
// wf-14 step=4 RUN không có OK/FAIL. Tạo + xóa 1 scheduler event test.
bizcity_diagnostics_require_probe( 'class-probe-automation-publish-fb.php' );

// WF-AUTO GURU W2/W3/W6 (2026-06-03) — Slash matcher dual-tier dispatch + W5 hardening
// + Canvas import/export REST routes (Wave D). R-DDV evidence (read-only unit assertions).
bizcity_diagnostics_require_probe( 'class-probe-slash-matcher.php' );

// WF-AUTO W7 (2026-06-03) — Community Gallery PoC (Wave E): GitHub raw fetch
// allowlist + 3 REST routes (read-only PoC). R-DDV evidence (no external HTTP).
bizcity_diagnostics_require_probe( 'class-probe-automation-community.php' );

// CRM-PATH (2026-06-07 PHASE-0.41) — dual-path zone isolation + recipe catalog
// + crm-instantiate + bind_channel + ZALO_OA/ZALO_BOT zone isolation (R-ZONE-2).
bizcity_diagnostics_require_probe( 'class-probe-automation-crm-path.php' );

// SCH-NC W2/W3/W4 (2026-06-03) — Scheduler Nerve Center smoke: adapter registry
// + 6 built-in adapters + validate hook + completion-notifier listener + status
// active→done fires bizcity_scheduler_event_completed. R-DDV evidence
// (PHASE-SCHEDULER-AS-NERVE-CENTER §1 R-SCH).
bizcity_diagnostics_require_probe( 'class-probe-scheduler-nerve-center.php' );

// SCH-NC W10 (2026-06-03) — Inbound provenance backfill probe: scans 6 cases of
// legacy events missing metadata.inbound{}, exposes per-case "🔧 Fix" via
// Site Provisioner installers (scheduler_backfill_inbound__<case>).
bizcity_diagnostics_require_probe( 'class-probe-scheduler-inbound-backfill.php' );

// NOTE (2026-06-02) — `class-probe-qr-proxy.php` removed: client KHÔNG được phép
// biết tồn tại của bizcity-llm-router (R-GW-8 client topology). QR proxy probe
// chỉ tồn tại server-side (bizcity.vn/bizcity.ai) trong plugin router.

// CONSOLIDATION M1 (2026-06-02) — KG Skeleton smoke (replaces standalone admin
// page `tools.php?page=bizcity-kg-skeleton-diag`). Wraps audit_blog().
bizcity_diagnostics_require_probe( 'class-probe-kg-skeleton.php' );

// CONSOLIDATION M2 (2026-06-02) — TwinChat Pro Learning smoke (replaces
// standalone admin page `tools.php?page=bizcity-pro-learning-diag`).
// Wraps run_all() across PHASE-0.7-MASTER 8 sections.
bizcity_diagnostics_require_probe( 'class-probe-twinchat-pro-learning.php' );

// [2026-07-14 Johnny Chu] PHASE-0.43 — Disk/Loader/Runtime evidence for shared local-document TwinSearch.
bizcity_diagnostics_require_probe( 'class-probe-twinsearch-shared.php' );

// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — DDV probe for Twin GPT control-plane
// (GET /config/effective + GET/PUT /admin/access contract evidence).
bizcity_diagnostics_require_probe( 'class-probe-twinweb-control-plane.php' );

// [2026-07-15 Johnny Chu] PHASE-TWINWEB-MPR-TIMELINE — DDV probe for citation
// continuity from retrieval source payload to thread history reload.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-citation-continuity.php' );

// [2026-07-16 Johnny Chu] PHASE-TWINWEB W6 — DDV probe for member Facebook
// connect flow (user-oauth-start + pending stash callback handoff + user-pages owner scope).
bizcity_diagnostics_require_probe( 'class-probe-twinweb-fb-connect.php' );

// [2026-07-17 Johnny Chu] SPRINT-5 Q-1 R-BIZ-MODEL-11 — /me plan_catalog + subscription server-driven catalog.
// Disk: build_plan_catalog/build_subscription methods; Loader: /me route registered; Runtime: sorted catalog, no VND.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-me-plan-catalog.php' );

// [2026-07-17 Johnny Chu] PHASE-TWINWEB CAP-1 — DDV probe for /apps/effective app catalog contract.
// Disk/Loader/Runtime: route wiring + guest/subscriber workflow state gating.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-app-catalog.php' );

// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — DDV probe for model/token preset policy + runtime budget propagation.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-model-policy.php' );

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — DDV probe for metadata-only tool registry + artifact canvas contract.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-tool-registry.php' );

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — DDV probe for owner-scoped attachment upload strip.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-attachments.php' );

// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — DDV probe for voice input/transcribe foundation.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-voice-input.php' );

// [2026-08-01 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS AT-7 — DDV probe for durable async artifact job transitions.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-async-artifacts.php' );

// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-4 — DDV probe for /gpt/myaccount/ account foundation.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-myaccount.php' );

// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-9 — DDV probe for /gpt/ chat layout foundation.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-chat-layout.php' );

// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — DDV probe for thread_spec/registry/search/customer queue foundation.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-thread-registry.php' );

// [2026-07-17 Johnny Chu] SPRINT-10 SB-3 — DDV probe for Commerce/Woo seat capacity + projection queue payload.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-commerce-capacity.php' );

// [2026-07-18 Johnny Chu] PHASE-TWINWEB F4 — DDV probe for owner continuity acceptance lines.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-owner-continuity.php' );

// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — DDV probe for /gpt/mychannels customer channels MVP.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-customer-channels.php' );

// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — DDV probe for customer-owned channel automation runtime guards.
bizcity_diagnostics_require_probe( 'class-probe-twinweb-customer-channel-automation.php' );

// CONSOLIDATION M3 (2026-06-02) — Channel PHASE 0.37 task matrix (replaces
// standalone admin page `tools.php?page=bizcity-channel-phase-037-diag`).
bizcity_diagnostics_require_probe( 'class-probe-channel-phase-037.php' );

// CONSOLIDATION M4 (2026-06-02) — Channel Gateway Sprint Matrix (replaces
// standalone admin page `tools.php?page=bizcity-channel-gateway-sprint-diag`).
// Wraps collect_results() for PHASE-0.31 task_row aggregation.
bizcity_diagnostics_require_probe( 'class-probe-channel-sprint.php' );

// CONSOLIDATION M5 (2026-06-02) — KG .bin canonical schema (smoke portion of
// `tools.php?page=bizcity-kg-bin-diagnostic` — page kept as operator console).
bizcity_diagnostics_require_probe( 'class-probe-kg-bin-schema.php' );

// CONSOLIDATION M7 (2026-06-02) — BizCoach Pro sprint matrix wrapper.
// Aggregates BizCoach_Pro_Sprint_Diagnostic::compute_fX_tasks() (F.1–F.16,
// read-only) into canonical Diagnostics. Operator console kept at
// `tools.php?page=bizcoach-pro-diag` for smoke runner / browsers / G.6 live.
bizcity_diagnostics_require_probe( 'class-probe-bizcoach-pro.php' );

// TASK-UNIFY Phase 1.5 (2026-05-29) — Web Post Publisher real-call smoke.
// Layer 1+2: disk/loader, Layer 3: bizcity_crm_events schema + real-call:
// insert event → on_reminder_fire() → assert wp_post_id set → cleanup.
bizcity_diagnostics_require_probe( 'class-probe-web-post-publisher.php' );

// TASK-UNIFY Phase 2 (2026-05-29) — Zalo Reminder smoke.
// disk + loader + hook @30 + bizcity_zalo_bots schema + real-call (bot_id=0 → graceful fail).
bizcity_diagnostics_require_probe( 'class-probe-zalo-reminder.php' );

// TASK-UNIFY Phase 2 (2026-05-29) — CG Admin Router + CMD Classifier smoke.
// disk + loader + hook @5 + classifier unit tests (8 patterns) + REST route.
bizcity_diagnostics_require_probe( 'class-probe-admin-router.php' );

// [2026-06-05 Johnny Chu] R-ERROR-UX — Error Payload helper + legacy anti-pattern audit.
bizcity_diagnostics_require_probe( 'class-probe-error-ux.php' );
// [2026-07-30 Johnny Chu] PHASE-1.22-DDV — register the core security/reliability production contract probe.
bizcity_diagnostics_require_probe( 'class-probe-framework-production-contract.php' );
// [2026-08-11 Johnny Chu] PHASE-1.26-CONTRACT — register the unified admin navigation contract probe.
bizcity_diagnostics_require_probe( 'class-probe-admin-navigation.php' );
// [2026-08-10 Johnny Chu] PHASE-1.25-PIAPI-DDV — mock submit/poll/header contract.
bizcity_diagnostics_require_probe( 'class-probe-piapi-image-task.php' );
// [2026-08-10 Johnny Chu] PHASE-1.24-DDV — queue PageBuilder/Video Kling package adoption probe lazily.
bizcity_diagnostics_require_probe( 'class-probe-framework-package-adoption.php' );
// [2026-07-31 Johnny Chu] HOTFIX — register the read-only WP_Hook callback and shard-context integrity probe.
bizcity_diagnostics_require_probe( 'class-probe-wp-hook-callback-integrity.php' );

// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP M8 — Membership entitlement + plan registry probe.
// Covers: class load, bizcity_membership_plans, 3 tables, expiry cron, PayPal v2 wiring, for_user() merge.
bizcity_diagnostics_require_probe( 'class-probe-membership-entitlement.php' );

// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3A/3B — Membership REST /me + quota gates probe.
// Covers: REST routes (/me, /me/payments, /me/cancel), AJAX handlers, enforcer hooks, profile fields, usage snapshot.
bizcity_diagnostics_require_probe( 'class-probe-membership-rest.php' );

// [2026-07-17 Johnny Chu] SPRINT-5 MBR-RANK — Plan rank + consumes_seat + audience contract.
// Disk: normalize() fields; Loader: all() typed; Runtime: free.rank=0/consumes_seat=false; paid rank>=100.
bizcity_diagnostics_require_probe( 'class-probe-membership-plan-rank.php' );

// [2026-07-17 Johnny Chu] SPRINT-7 MBR-EXPIRY — expiry cohort dashboard + cron counters contract.
// Disk: report/admin/cron markers; Loader: expiry_cohorts method; Runtime: cohort keys + monotonic counts.
bizcity_diagnostics_require_probe( 'class-probe-membership-expiry-cohorts.php' );

// [2026-07-17 Johnny Chu] SPRINT-8 WC-0A — Woo mapper foundation contract.
// Disk: mapper file/meta markers; Loader: class+map option; Runtime: synthetic product/variation override mapping.
bizcity_diagnostics_require_probe( 'class-probe-membership-woo-mapper.php' );

// [2026-07-17 Johnny Chu] SPRINT-9 WC-2 — Woo paid-order projection contract.
// Disk: projector markers; Loader: Woo hooks; Runtime: apply-once + idempotent + capacity_blocked fail-closed.
bizcity_diagnostics_require_probe( 'class-probe-membership-woo-projection.php' );

// [2026-07-17 Johnny Chu] SPRINT-11 PGM-3 — hub seat admission contract.
// Disk: manager counter + seat-limit markers; Loader: methods loaded; Runtime: admin-excluded seat counter + blocked net-new seat.
bizcity_diagnostics_require_probe( 'class-probe-membership-hub-seat-admission.php' );

// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — TwinShell boundary R-DDV probe.
// 3-layer: disk guards + loader hooks + runtime REST/registry/iframe contract.
bizcity_diagnostics_require_probe( 'class-probe-twinshell-boundary.php' );

// [2026-07-10 Johnny Chu] PHASE-TWINSHELL-IMPL — consolidated runtime evidence
// probe for checklist sections 2-5 (timeline/account-hub executable checks).
bizcity_diagnostics_require_probe( 'class-probe-twinshell-runtime-evidence.php' );

// TASK-UNIFY Phase 3 (2026-05-30) — Woo Product + Lead Report + Woo Order handlers.
// disk + loader + hook priorities + event_type whitelist + legacy wrapper gates.
bizcity_diagnostics_require_probe( 'class-probe-phase3-handlers.php' );

// M-CRM.M5 (2026-05-25) — Sales Pipeline (Lead/Opportunity/Contract) smoke.
// 3-layer + INSERT/UPDATE(stage)/DELETE round-trip simulating Kanban drag.
bizcity_diagnostics_require_probe( 'class-probe-crm-pipeline.php' );

// M-CRM.M1.W3 (2026-05-28) — Audit Log BE smoke.
// 3-layer + log_created/find_by_entity round-trip + auto-create via migrate_phase_043().
bizcity_diagnostics_require_probe( 'class-probe-crm-audit-log.php' );

// [2026-06-07 Johnny Chu] PHASE-0.38.W1.7 — Create Woo Order action block DDV smoke.
// 3-layer: file exists (Disk) + class+WooCommerce loaded (Loader) + synthetic order (Runtime).
bizcity_diagnostics_require_probe( 'class-probe-crm-create-order.php' );

// [2026-06-07 Johnny Chu] PHASE-0.38.W2 — Recap Notifier DDV (order=40).
bizcity_diagnostics_require_probe( 'class-probe-crm-recap-notifier.php' );

// [2026-06-07 Johnny Chu] PHASE-0.38.W3 — Public Tracking Page DDV (order=41).
bizcity_diagnostics_require_probe( 'class-probe-crm-public-tracking.php' );

// [2026-06-07 Johnny Chu] PHASE-0.38.W4 — Shipping Tracker Cron DDV (order=42).
bizcity_diagnostics_require_probe( 'class-probe-crm-shipping-tracker.php' );

// [2026-06-07 Johnny Chu] PHASE-0.40 G0.4 — Zone Isolation DDV (order=43).
// Verifies R-ZONE-2: ZALO_BOT stays in Zone 2 (admin/automation); zalo_oa routes to Zone 1 (CRM).
bizcity_diagnostics_require_probe( 'class-probe-channel-zone-isolation.php' );
// [2026-07-27 Johnny Chu] PHASE-0.52 W6 — load channel identity/memory ownership DDV probe.
bizcity_diagnostics_require_probe( 'class-probe-channel-identity-memory.php' );

// [2026-06-07 Johnny Chu] PHASE-0.40 G3.4+G4.5 — BizCity parity probe (order=44).
// 3-layer: 6 report callbacks + broadcast dispatcher disk, class loader, runtime GET /reports/message.
bizcity_diagnostics_require_probe( 'class-probe-crm-bizcity-parity.php' );
// [2026-06-07 Johnny Chu] PHASE-0.40 G7.4 — G7 Integration probe (order=45): Discord action block.
bizcity_diagnostics_require_probe( 'class-probe-crm-g7-integration.php' );
// [2026-06-07 Johnny Chu] PHASE-0.43 M5 — Broadcast Mass-Send BizCity Parity probe (order=46).
// 6 assertions: disk.schema_json (1.23.0), disk.dispatcher (pick_variant_full), loader.dispatcher,
// loader.columns, runtime.rest_route (/bizcity-crm/v1/broadcasts), runtime.cron_hook.
bizcity_diagnostics_require_probe( 'class-probe-crm-broadcast-bizcity.php' );

// [2026-08-01 Johnny Chu] PHASE-CG-QR-LINK — standalone QR Link schema/loader/REST DDV.
bizcity_diagnostics_require_probe( 'class-probe-crm-qr-link.php' );

// [2026-07-10 Johnny Chu] PHASE-0.47 — Broadcast import smoke matrix probe
// for csv/xls/xlsx/google_sheet_url REST path.
bizcity_diagnostics_require_probe( 'class-probe-channel-broadcast-import-matrix.php' );

// [2026-06-07 Johnny Chu] PHASE-0.39 — Zalo Personal & OA channel gateway DDV (order=45).
// 7-row probe: bridge health, catalog filter, integration registry, inbound emitter,
// schema tables (3 bảng), OA window logic, zone isolation.
bizcity_diagnostics_require_probe( 'class-probe-zalo-personal.php' );

// M-CRM.M4.Inbox (2026-05-28) — Broadcast + Lead Classification smoke.
// 3-layer: tables (bizcity_crm_broadcasts, recipients), lead_score/segment cols, REST routes.
// DISABLED 2026-06-01 — feature chưa được dùng / test; tránh wizard báo FAIL gây nhiễu.
// Re-enable bằng cách bỏ comment khi M-CRM.M4 gắn vào roadmap active.
// require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-broadcast.php';

// Phase 0.41 L9.e — Dashboard widget + external monitoring REST.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-dashboard-widget.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-external-monitor.php';

if ( is_admin() ) {
	require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-admin-page.php';
	BizCity_Diagnostics_Admin_Page::instance();
	BizCity_Diagnostics_Dashboard_Widget::instance();
}

// [2026-08-09 Johnny Chu] R-PERF-LOADER-A - flush the queued probe graph only
// for the diagnostics screen, diagnostics REST namespace, or CLI. Other admin
// pages keep the table/REST infrastructure without the probe class graph.
if ( function_exists( 'bizcity_diagnostics_should_load_probes' )
	&& bizcity_diagnostics_should_load_probes() ) {
	bizcity_diagnostics_load_probes_once();
}

if ( function_exists( 'bizcity_diagnostics_should_load_probes' )
	&& bizcity_diagnostics_should_load_probes()
	&& class_exists( 'BizCity_Diagnostics_Loader_Hook_Panel' )
	&& is_admin() ) {
	add_action( 'admin_footer', array( 'BizCity_Diagnostics_Loader_Hook_Panel', 'render' ), PHP_INT_MAX );
}

// REST + soft-guard notices always loaded (cron + AJAX paths).
BizCity_Diagnostics_REST::instance();
BizCity_Diagnostics_External_Monitor::instance();
BizCity_Diagnostics_Notices::instance();

// Site provisioner — unified table-installer orchestrator.
// Hooks: wp_initialize_site (new blog) + admin_init (self-heal throttled).
BizCity_Site_Provisioner::register_hooks();

// Error Reporter — telemetry sink + critical-error email handler.
BizCity_Error_Reporter::register_hooks();
