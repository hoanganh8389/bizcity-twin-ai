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

// PHASE-0.35 GURU-ZALO-BOT §1.8 (2026-05-26) — Real-call probe cho unified
// Guru Runtime DTO contract. Verify reply() trả DTO hợp lệ + trace_id
// + event stream. Skip nếu chưa có character nào.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-guru-runtime.php';

// Phase 0.36-UNIFIED Wave 2.8 TBR.MEM-12 (2026-05-22) — Real-call probes cho
// TwinBrain Memory Layer (Layer 0.5 Recall + Layer 4.7 Writer Mode 1+2). Plant
// __healthtest_ rows + cleanup; verify citation [mem:U#id] echo + idempotency
// per trace_id. Mode 3 (MemGPT) deferred → no probe.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-twinbrain-memory-recall.php';
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

// SCENARIO BUILDER MVP (2026-06-01) — Ad-image proxy loopback (rest_do_request).
// Verify route registered + permission pass + handler reachable (degraded path OK).
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-automation-ad-image.php';

// AUTOMATION HARDEN (2026-06-02) — publish_fb_post block chain probe
// (block → CRM Bridge → Scheduler Manager). R-DDV evidence cho bug
// wf-14 step=4 RUN không có OK/FAIL. Tạo + xóa 1 scheduler event test.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-automation-publish-fb.php';

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

// TASK-UNIFY Phase 3 (2026-05-30) — Woo Product + Lead Report + Woo Order handlers.
// disk + loader + hook priorities + event_type whitelist + legacy wrapper gates.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-phase3-handlers.php';

// M-CRM.M5 (2026-05-25) — Sales Pipeline (Lead/Opportunity/Contract) smoke.
// 3-layer + INSERT/UPDATE(stage)/DELETE round-trip simulating Kanban drag.
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-pipeline.php';

// M-CRM.M1.W3 (2026-05-28) — Audit Log BE smoke.
// 3-layer + log_created/find_by_entity round-trip + auto-create via migrate_phase_043().
require_once BIZCITY_DIAGNOSTICS_DIR . 'includes/probes/class-probe-crm-audit-log.php';

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
