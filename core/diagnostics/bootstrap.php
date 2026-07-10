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
define( 'BIZCITY_DIAGNOSTICS_DIR', __DIR__ . '/' );
define( 'BIZCITY_DIAGNOSTICS_VERSION', '0.41.0' );
define( 'BIZCITY_DIAGNOSTICS_REST_NS', 'bizcity-diagnostics/v1' );

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
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-kg-seeding.php';

// 2026-06-04 — R-DDV row cho Google Hub canonical 1-API. Read-only,
// verify connect-URL builder + service catalog + status snapshot.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-google-hub.php';

// [2026-06-04 Johnny Chu] HOTFIX R-GW-8 — Account quota & entitlement probe.
// 3-layer: disk + loader + 3 hub REST calls (account/info, account/limits,
// account/entitlement). Shows credits, tier, KG quota config, service limits.
// Helps diagnose learning jobs stuck on "quota hôm nay đã hết".
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-account-quota-entitlement.php';

// Phase 0.36-UNIFIED TBR.W5 (2026-05-21) — Gateway-verified Web Search ping
// cho Web Research Fallback Layer. R-GW: dùng BizCity_Search_Client thay vì
// provider key client-side (probe cũ `class-probe-search-web.php` outdated).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-web-search-ping.php';

// Phase 0.36-UNIFIED TBR.W7-fix-1 (2026-05-21) — Real-call probe cho Web Deep
// ReAct agent. Gọi thật BizCity_TwinBrain_Web_Deep::run() để bắt các bug như
// `forced_final:budget_or_iter_cap`, empty answer, missing [web:N] citations.
// Thay thế cho debug wp-cli command (operator có thể run từ admin UI).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-web-deep-llm.php';

// Phase 0.36-UNIFIED TBR.W17 (2026-05-27) — Real-call probe cho Web Med
// vertical. Kiểm tra allowlist hits, citation [med:N], disclaimer ⚕️, và
// stance cap (med KHÔNG BAO GIỜ 'confident'). RFC:
// core/twinbrain/docs/TWINBRAIN-EXT-VERTICAL-WEB-RESEARCH.md §7.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-web-med.php';

// Phase 0.36-UNIFIED TBR.W17 (2026-05-28) — Vertical Wave 1 probes (5):
// scholar / nutri / law / tax / gov. Mỗi probe gọi thật engine tương ứng
// để verify allowlist hit + citation + (disclaimer nếu có) + stance.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-web-scholar.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-web-nutri.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-web-law.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-web-tax.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-web-gov.php';

// Phase 0.36-UNIFIED TBR.W18 (2026-05-28) — Brain Auto-Degrade Chat probe.
// Validate compose_chat_stream() + eligibility filters cho luồng chat tự
// nhiên khi K=0 candidates + memory ≥120B (skip Perspective/Tool/Synthesizer
// layers, stream chat-tone answer chỉ dùng memory_block).
// Guard with file_exists() — newly added 2026-05-28; production may lag
// behind before file is rsync'd up. Skip silently rather than fatal.
$bizcity_probe_brain_auto_degrade = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-brain-auto-degrade.php';
if ( file_exists( $bizcity_probe_brain_auto_degrade ) ) {
	require_once $bizcity_probe_brain_auto_degrade;
}
unset( $bizcity_probe_brain_auto_degrade );

// Phase 0.36-UNIFIED TBR.W20 (2026-05-28) — Agent ReAct Runner probe.
// Validate Agent_Runner::run() + whitelist filter + agent_loop_started/done
// + agent_step_done event taxonomy. TỐN ~1-3 LLM call.
$bizcity_probe_agent_react = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-agent-react.php';
if ( file_exists( $bizcity_probe_agent_react ) ) {
	require_once $bizcity_probe_agent_react;
}
unset( $bizcity_probe_agent_react );

// Phase 0.36-UNIFIED TBR.W19 (2026-05-21) — Real-call probe cho Final Composer
// (Layer 4.5). Gọi thật compose_stream() với synthesizer giả + perspectives
// giả, đo deltas + citation preservation. Validate streaming pipeline BE
// end-to-end trước khi đụng FE work (TBR.W18).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-final-compose.php';

// [2026-06-04 Johnny Chu] PHASE-A C.0b — DDV probe for BizCoach Pro Astro Transit
// Resolver (DB-first → cron prefetch fallback). Smoke check: class loaded,
// resolve() shape valid, CAP filter `bizcity_twin_context_artifacts` wired.
$bizcity_probe_astro_transit = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-astro-transit-resolver.php';
if ( file_exists( $bizcity_probe_astro_transit ) ) {
	require_once $bizcity_probe_astro_transit;
}
unset( $bizcity_probe_astro_transit );

// [2026-06-04 Johnny Chu] PHASE-A C.3b — DDV probe for TwinBrain Astro Mode
// pipeline. 3 layers: Disk (runtime file), Loader (class + stream_astro_mode
// method), Runtime (CAP filter subscriber + Final_Composer available).
$bizcity_probe_astro_mode = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-astro-mode.php';
if ( file_exists( $bizcity_probe_astro_mode ) ) {
	require_once $bizcity_probe_astro_mode;
}
unset( $bizcity_probe_astro_mode );

// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — DDV probes for readiness gate
// and automation per-day message loop wiring.
$bizcity_probe_astro_readiness_gate = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-astro-readiness-gate.php';
if ( file_exists( $bizcity_probe_astro_readiness_gate ) ) {
	require_once $bizcity_probe_astro_readiness_gate;
}
unset( $bizcity_probe_astro_readiness_gate );

$bizcity_probe_astro_per_day_loop = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-astro-per-day-loop.php';
if ( file_exists( $bizcity_probe_astro_per_day_loop ) ) {
	require_once $bizcity_probe_astro_per_day_loop;
}
unset( $bizcity_probe_astro_per_day_loop );

// [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — DDV probe for
// astro_data_action_required runtime evidence + payload contract.
$bizcity_probe_astro_data_action_required = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-astro-data-action-required.php';
if ( file_exists( $bizcity_probe_astro_data_action_required ) ) {
	require_once $bizcity_probe_astro_data_action_required;
}
unset( $bizcity_probe_astro_data_action_required );

// [2026-07-04 Johnny Chu] PHASE-FAA2-DDV — DDV probe for FAA2 natal-wheel-chart
// (url-only) pipeline. 3 layers: Disk (provider file in bizcity-llm-router),
// Loader (class + supports + router), Runtime (live natal_wheel_chart call).
$bizcity_probe_astro_faa2_svg = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-astro-faa2-chart-svg.php';
if ( file_exists( $bizcity_probe_astro_faa2_svg ) ) {
	require_once $bizcity_probe_astro_faa2_svg;
}
unset( $bizcity_probe_astro_faa2_svg );

// [2026-07-09 Johnny Chu] PHASE-A5 — DDV probe for pro charts wave
// (synastry/composite/solar_return/lunar_return) across hub+client.
$bizcity_probe_astro_pro_charts_a5 = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-astro-pro-charts-a5.php';
if ( file_exists( $bizcity_probe_astro_pro_charts_a5 ) ) {
	require_once $bizcity_probe_astro_pro_charts_a5;
}
unset( $bizcity_probe_astro_pro_charts_a5 );

// [2026-07-09 Johnny Chu] PHASE-A5 — DDV probe for tokenized anonymous share
// on Relations/Ephemeris/Transits Timeline (/me/tools/share + /public/tools/share).
$bizcity_probe_astro_tool_share_a5 = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-astro-tool-share-a5.php';
if ( file_exists( $bizcity_probe_astro_tool_share_a5 ) ) {
	require_once $bizcity_probe_astro_tool_share_a5;
}
unset( $bizcity_probe_astro_tool_share_a5 );

// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — DDV probe for client plan sync
// routes (/bizcity-client/v1/entitlement/sync + /bizcity-client/v1/me/plan).
$bizcity_probe_client_plan_sync = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-client-plan-sync.php';
if ( file_exists( $bizcity_probe_client_plan_sync ) ) {
	require_once $bizcity_probe_client_plan_sync;
}
unset( $bizcity_probe_client_plan_sync );

// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — DDV probes for Hub commerce and
// license branches from Branch 18 API catalog.
$bizcity_probe_commerce_hub_checkout = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-commerce-hub-checkout.php';
if ( file_exists( $bizcity_probe_commerce_hub_checkout ) ) {
	require_once $bizcity_probe_commerce_hub_checkout;
}
unset( $bizcity_probe_commerce_hub_checkout );

$bizcity_probe_license_hub_entitlement_issue = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-license-hub-entitlement-issue.php';
if ( file_exists( $bizcity_probe_license_hub_entitlement_issue ) ) {
	require_once $bizcity_probe_license_hub_entitlement_issue;
}
unset( $bizcity_probe_license_hub_entitlement_issue );

// [2026-07-07 Johnny Chu] PHASE-FAA2-NEXT — DDV probe for relation/ashtakoot path
// (wrapper callsite contract + R-ERROR-UX payload shape in relation handlers).
$bizcity_probe_astro_relation_ashtakoot = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-astro-relation-ashtakoot.php';
if ( file_exists( $bizcity_probe_astro_relation_ashtakoot ) ) {
	require_once $bizcity_probe_astro_relation_ashtakoot;
}
unset( $bizcity_probe_astro_relation_ashtakoot );

// PHASE-0.35 GURU-ZALO-BOT §1.8 (2026-05-26) — Real-call probe cho unified
// Guru Runtime DTO contract. Verify reply() trả DTO hợp lệ + trace_id
// + event stream. Skip nếu chưa có character nào.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-guru-runtime.php';

// Phase 0.36-UNIFIED Wave 2.8 TBR.MEM-12 (2026-05-22) — Real-call probes cho
// TwinBrain Memory Layer (Layer 0.5 Recall + Layer 4.7 Writer Mode 1+2). Plant
// __healthtest_ rows + cleanup; verify citation [mem:U#id] echo + idempotency
// per trace_id. Mode 3 (MemGPT) deferred → no probe.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-memory-recall.php';

// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-1 — foundation smoke (read-only).
// Verify VIEW bizcity_brain_sessions + 5 brain_session_* event_types + 5 JSON schemas.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-brain-sessions.php';

// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — Sessions CRUD real-call probe.
// Mint → VIEW → rename → list → archive cycle qua Sessions_Manager.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-brain-sessions-crud.php';

// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — Mood sampler real-call probe.
// Synthesize cadence-3 turns → sample_mood() → verify event + VIEW.has_mood +
// Sessions_Manager::latest_mood() + Memory_Recall Tier F render + idempotency.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-mood-sampler.php';
// R-TB-HYDRATE (2026-05-27) — Read-only probe guarding TwinBrain
// Perspective_Runner fallback hydration (regression guard for P0 bug where
// fetch_recent_passages / fetch_passages_by_keyword skipped Content_Router
// hydrate → empty notebook context → Final Composer collapsed to web-only).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-retrieval-hydration.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-memory-writer-explicit.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-memory-writer-llm.php';
// Wave 2.8b TBR.MEM-N5 (2026-05-23) — Notebook chat memory parity probe.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-memory-notebook-chat.php';
// Wave 2.8c TBR.MEM-C7 (2026-05-24) — Hub REST CRUD probe (/memory/me).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-memory-hub-rest.php';
// Wave 2.8 TBR.MEM-6 (2026-05-24) — Mode 3 MemGPT-style memory tool
// dispatcher probe (memory_remember / memory_forget / memory_recall).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-memory-tool-calls.php';
// Wave 2.8e TBR.TOOL-S5 (2026-05-24) — TwinBrain Sheets 3-stage enricher
// real-call probe (create 1x3 sheet → enrich → verify cells + sources +
// aggregates + SSE events).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-sheet-enrich.php';
// Wave 2.8d TBR.MEM-D5e (2026-05-24) — Unified memory dual-write parity probe.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-memory-unified-dual-write.php';
// Wave 2.8d TBR.MEM-D6 (2026-05-24) — Unified memory recall parity probe
// (legacy vs unified Memory_Recall tokens overlap ≥ 95%).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-memory-unified-recall-parity.php';

// Phase 0.41 L9.b — Schema Changelog Ledger + Auto-Create.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-changelog-loader.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/class-diagnostics-auto-create.php';

// Phase 0.99.3 (2026-06-01) — Module registry probe — surfaces 3rd-party
// modules registered via the `bizcity_register_module` filter.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-module-registry.php';

// Phase 0.41 L9.b+ — Schema inventory meta-probe (drives Auto-Fix-All UX).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-schema-inventory.php';

// Phase 0.41 L9.c — Structural wiring probes (research / upload / vector).
// NOTE: search probe lives at `class-probe-web-search-ping.php` (loaded above
// for Phase 0.36-UNIFIED TBR.W5); no duplicate registered here.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-research-deep.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-upload-learning.php';
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-vector-graph.php';

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
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-kg-filestore-learning.php';

// Phase 6.6 S1.2 — Notebook skeleton coverage probe (R-SK-DOC §15).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-skeleton-coverage.php';

// Phase 5 (2026-05-22) — Channel-gateway FB REST routes wiring probe
// (3-layer: disk / loader / runtime). Để debug 404 cho /facebook/* SPA tabs.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-channel-gateway-rest.php';

// [2026-06-03 Johnny Chu] GURU-UI W0.4+W0.5 — channel binding stack probe.
// 3-layer DDV: disk + class load + DB table + REST inspector routes +
// listener resolve callable + orphan binding scan.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-channel-binding.php';

// PHASE-CG-SCHEDULER v0.2 (2026-05-23) — FB Publisher bridge probe
// (3-layer: disk/loader/runtime + R-DCL changelog check + scheduler cron).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-fb-publisher.php';

// PHASE-CORE-CRON v1.0 (2026-05-23) — Unified cron registry & dispatch health.
// Lists every job registered through BizCity_Cron_Manager; verifies tables
// (bizcity_cron_registry, bizcity_cron_runs), schedules, and last-run drift.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-cron-registry.php';

// Phase 0.37 (2026-05-27) — Scheduler Automation Runner real-call probe.
// 3-layer R-DDV: disk wiring + loader hooks + real fire-now via Lab endpoint.
// PASS nếu fire-now returns ok=true + chain evidence in bizcity_cron_runs.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-scheduler-automation.php';

// PHASE-N (2026-05-25) — Flows sub-module smoke (ported bizgpt-custom-flows).
// 3-layer + INSERT/SELECT/DELETE round-trip + REST route registration.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-cg-flows.php';

// AUTOMATION BE-1 (2026-05-29) — Native xyflow automation backend smoke.
// 3-layer (disk/loader/runtime) + create workflow + enqueue run round-trip.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-automation.php';

// SCENARIO BUILDER MVP (2026-06-01) — Trigger matcher ref-based + keywords[] OR-match.
// Synthetic FB referral payload → matched_ref event + run row; ref_unmatched fallthrough.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-automation-matcher.php';

// [2026-06-08 Johnny Chu] PHASE-0.43 R-ERROR-UX + R-DDV — Automation Runtime Error Report.
// Reads bizcity_automation_runs (last 24h STATUS_FAIL) + bizcity_cron_runs meta,
// maps reason_bucket → canonical ERROR-UX catalog, surfaces per-bucket WARN steps.
// Spec: core/automation/docs/AUTOMATION-RUNTIME-ERRORS.md
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-automation-runtime.php';

// SCENARIO BUILDER MVP (2026-06-01) — Ad-image proxy loopback (rest_do_request).
// Verify route registered + permission pass + handler reachable (degraded path OK).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-automation-ad-image.php';

// AUTOMATION HARDEN (2026-06-02) — publish_fb_post block chain probe
// (block → CRM Bridge → Scheduler Manager). R-DDV evidence cho bug
// wf-14 step=4 RUN không có OK/FAIL. Tạo + xóa 1 scheduler event test.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-automation-publish-fb.php';

// WF-AUTO GURU W2/W3/W6 (2026-06-03) — Slash matcher dual-tier dispatch + W5 hardening
// + Canvas import/export REST routes (Wave D). R-DDV evidence (read-only unit assertions).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-slash-matcher.php';

// WF-AUTO W7 (2026-06-03) — Community Gallery PoC (Wave E): GitHub raw fetch
// allowlist + 3 REST routes (read-only PoC). R-DDV evidence (no external HTTP).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-automation-community.php';

// CRM-PATH (2026-06-07 PHASE-0.41) — dual-path zone isolation + recipe catalog
// + crm-instantiate + bind_channel + ZALO_OA/ZALO_BOT zone isolation (R-ZONE-2).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-automation-crm-path.php';

// SCH-NC W2/W3/W4 (2026-06-03) — Scheduler Nerve Center smoke: adapter registry
// + 6 built-in adapters + validate hook + completion-notifier listener + status
// active→done fires bizcity_scheduler_event_completed. R-DDV evidence
// (PHASE-SCHEDULER-AS-NERVE-CENTER §1 R-SCH).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-scheduler-nerve-center.php';

// SCH-NC W10 (2026-06-03) — Inbound provenance backfill probe: scans 6 cases of
// legacy events missing metadata.inbound{}, exposes per-case "🔧 Fix" via
// Site Provisioner installers (scheduler_backfill_inbound__<case>).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-scheduler-inbound-backfill.php';

// NOTE (2026-06-02) — `class-probe-qr-proxy.php` removed: client KHÔNG được phép
// biết tồn tại của bizcity-llm-router (R-GW-8 client topology). QR proxy probe
// chỉ tồn tại server-side (bizcity.vn/bizcity.ai) trong plugin router.

// CONSOLIDATION M1 (2026-06-02) — KG Skeleton smoke (replaces standalone admin
// page `tools.php?page=bizcity-kg-skeleton-diag`). Wraps audit_blog().
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-kg-skeleton.php';

// CONSOLIDATION M2 (2026-06-02) — TwinChat Pro Learning smoke (replaces
// standalone admin page `tools.php?page=bizcity-pro-learning-diag`).
// Wraps run_all() across PHASE-0.7-MASTER 8 sections.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinchat-pro-learning.php';

// CONSOLIDATION M3 (2026-06-02) — Channel PHASE 0.37 task matrix (replaces
// standalone admin page `tools.php?page=bizcity-channel-phase-037-diag`).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-channel-phase-037.php';

// CONSOLIDATION M4 (2026-06-02) — Channel Gateway Sprint Matrix (replaces
// standalone admin page `tools.php?page=bizcity-channel-gateway-sprint-diag`).
// Wraps collect_results() for PHASE-0.31 task_row aggregation.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-channel-sprint.php';

// CONSOLIDATION M5 (2026-06-02) — KG .bin canonical schema (smoke portion of
// `tools.php?page=bizcity-kg-bin-diagnostic` — page kept as operator console).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-kg-bin-schema.php';

// CONSOLIDATION M7 (2026-06-02) — BizCoach Pro sprint matrix wrapper.
// Aggregates BizCoach_Pro_Sprint_Diagnostic::compute_fX_tasks() (F.1–F.16,
// read-only) into canonical Diagnostics. Operator console kept at
// `tools.php?page=bizcoach-pro-diag` for smoke runner / browsers / G.6 live.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-bizcoach-pro.php';

// TASK-UNIFY Phase 1.5 (2026-05-29) — Web Post Publisher real-call smoke.
// Layer 1+2: disk/loader, Layer 3: bizcity_crm_events schema + real-call:
// insert event → on_reminder_fire() → assert wp_post_id set → cleanup.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-web-post-publisher.php';

// TASK-UNIFY Phase 2 (2026-05-29) — Zalo Reminder smoke.
// disk + loader + hook @30 + bizcity_zalo_bots schema + real-call (bot_id=0 → graceful fail).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-zalo-reminder.php';

// TASK-UNIFY Phase 2 (2026-05-29) — CG Admin Router + CMD Classifier smoke.
// disk + loader + hook @5 + classifier unit tests (8 patterns) + REST route.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-admin-router.php';

// [2026-06-05 Johnny Chu] R-ERROR-UX — Error Payload helper + legacy anti-pattern audit.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-error-ux.php';

// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP M8 — Membership entitlement + plan registry probe.
// Covers: class load, bizcity_membership_plans, 3 tables, expiry cron, PayPal v2 wiring, for_user() merge.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-membership-entitlement.php';

// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3A/3B — Membership REST /me + quota gates probe.
// Covers: REST routes (/me, /me/payments, /me/cancel), AJAX handlers, enforcer hooks, profile fields, usage snapshot.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-membership-rest.php';

// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — TwinShell boundary R-DDV probe.
// 3-layer: disk guards + loader hooks + runtime REST/registry/iframe contract.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinshell-boundary.php';

// [2026-07-10 Johnny Chu] PHASE-TWINSHELL-IMPL — consolidated runtime evidence
// probe for checklist sections 2-5 (timeline/account-hub executable checks).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinshell-runtime-evidence.php';

// TASK-UNIFY Phase 3 (2026-05-30) — Woo Product + Lead Report + Woo Order handlers.
// disk + loader + hook priorities + event_type whitelist + legacy wrapper gates.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-phase3-handlers.php';

// M-CRM.M5 (2026-05-25) — Sales Pipeline (Lead/Opportunity/Contract) smoke.
// 3-layer + INSERT/UPDATE(stage)/DELETE round-trip simulating Kanban drag.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-pipeline.php';

// M-CRM.M1.W3 (2026-05-28) — Audit Log BE smoke.
// 3-layer + log_created/find_by_entity round-trip + auto-create via migrate_phase_043().
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-audit-log.php';

// [2026-06-07 Johnny Chu] PHASE-0.38.W1.7 — Create Woo Order action block DDV smoke.
// 3-layer: file exists (Disk) + class+WooCommerce loaded (Loader) + synthetic order (Runtime).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-create-order.php';

// [2026-06-07 Johnny Chu] PHASE-0.38.W2 — Recap Notifier DDV (order=40).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-recap-notifier.php';

// [2026-06-07 Johnny Chu] PHASE-0.38.W3 — Public Tracking Page DDV (order=41).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-public-tracking.php';

// [2026-06-07 Johnny Chu] PHASE-0.38.W4 — Shipping Tracker Cron DDV (order=42).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-shipping-tracker.php';

// [2026-06-07 Johnny Chu] PHASE-0.40 G0.4 — Zone Isolation DDV (order=43).
// Verifies R-ZONE-2: ZALO_BOT stays in Zone 2 (admin/automation); zalo_oa routes to Zone 1 (CRM).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-channel-zone-isolation.php';

// [2026-06-07 Johnny Chu] PHASE-0.40 G3.4+G4.5 — BizCity parity probe (order=44).
// 3-layer: 6 report callbacks + broadcast dispatcher disk, class loader, runtime GET /reports/message.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-bizcity-parity.php';
// [2026-06-07 Johnny Chu] PHASE-0.40 G7.4 — G7 Integration probe (order=45): Discord action block.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-g7-integration.php';
// [2026-06-07 Johnny Chu] PHASE-0.43 M5 — Broadcast Mass-Send BizCity Parity probe (order=46).
// 6 assertions: disk.schema_json (1.23.0), disk.dispatcher (pick_variant_full), loader.dispatcher,
// loader.columns, runtime.rest_route (/bizcity-crm/v1/broadcasts), runtime.cron_hook.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-broadcast-bizcity.php';

// [2026-07-10 Johnny Chu] PHASE-0.47 — Broadcast import smoke matrix probe
// for csv/xls/xlsx/google_sheet_url REST path.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-channel-broadcast-import-matrix.php';

// [2026-06-07 Johnny Chu] PHASE-0.39 — Zalo Personal & OA channel gateway DDV (order=45).
// 7-row probe: bridge health, catalog filter, integration registry, inbound emitter,
// schema tables (3 bảng), OA window logic, zone isolation.
$bizcity_probe_zalo_personal = BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-zalo-personal.php';
if ( file_exists( $bizcity_probe_zalo_personal ) ) {
	require_once $bizcity_probe_zalo_personal;
}
unset( $bizcity_probe_zalo_personal );

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

// REST + soft-guard notices always loaded (cron + AJAX paths).
BizCity_Diagnostics_REST::instance();
BizCity_Diagnostics_External_Monitor::instance();
BizCity_Diagnostics_Notices::instance();

// Site provisioner — unified table-installer orchestrator.
// Hooks: wp_initialize_site (new blog) + admin_init (self-heal throttled).
BizCity_Site_Provisioner::register_hooks();

// Error Reporter — telemetry sink + critical-error email handler.
BizCity_Error_Reporter::register_hooks();
