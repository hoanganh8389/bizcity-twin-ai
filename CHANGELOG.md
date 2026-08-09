# Changelog

All notable changes to **BizCity Twin AI** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> Schema-level changes (DB tables/columns) live in per-module JSON files under
> [core/diagnostics/changelog/](core/diagnostics/changelog) and are NOT duplicated here.
> See rule [R-DCL · Diagnostics Changelog](docs/diagnostics/PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md).

---

## [Unreleased]

### R-PERF/R-CACHE audit ledger — 2026-08-09

| Area | Canonical record | Change / evidence | Status | Next action |
|---|---|---|---|---|
| WebChat bootstrap | `modules.webchat.json` | `ensure_tables_exist()` no longer runs schema checks on ordinary frontend HTML; `SHOW TABLES` callers use metadata helper. | Fixed | Keep frontend, REST, webhook and cron checks separate in regression tests. |
| Compat loader | `R-PERF.5` in `.github/copilot-instructions.md` | Intent, Knowledge, Twin Core and Market preloads are gated; deployed and source compat loaders are both tracked. | Fixed | Check both loader copies after every sync/deploy change. |
| Metadata helper | `R-PERF.5` | `information_schema` fallback uses blog/database-aware `wp_cache`, caches false, and performs no `update_option()` on a hot-path miss. | Fixed | Use canonical helper for all new table/column checks. |
| Automation Calendar REST | `core.automation.json` + `R-PERF.5` | Raw `SHOW TABLES` replaced with `bizcity_tbl_exists()` and a blog/database-aware fallback. | Fixed | Add REST request-count evidence when the endpoint is changed. |
| Channel identity caches | `core.channel-gateway.json` + `R-PERF.5` | Identity Hub and Channel User Linker memo keys now include blog/database; false results are preserved. | Fixed | Re-check cache isolation after shard/router changes. |
| ZNS Rules | `modules.zns-automation.json` | Changelog normalized to schema-v1; runtime cache preserves false and includes blog/database. | Fixed | Run schema validator and diagnostics probe. |
| BizCoach Pro | `bizcoach.astro.json` + `R-PERF.5` | Stable `BCPRO_VERSION`; legacy cron cleanup and Astro Checklist repair moved off frontend bootstrap. | Fixed | Audit remaining legacy `bccm_*` ownership before adding schema rows. |
| BZCC/BZDOC/BZPB installers | `modules.bzcc.json` + R-DCL debt list | Schema self-healing is restricted to admin/REST/cron/CLI; public route registration remains intact. | Fixed | Create canonical changelogs only after table registry/schema ownership is complete. |
| Tool Image | R-DCL debt list + `R-PERF.5` | Removed redundant file-scope `upgrade.php`; installer loads it immediately before `dbDelta()`. | Fixed | Catalog all BZTIMG tables before any schema change. |
| Diagnostics graph | `R-PERF.5` | Full probe graph no longer loads on unrelated REST requests; retained for admin/CLI/diagnostics namespace. | Fixed | Lazy-load further by diagnostics screen when admin memory is measured. |
| Frontend baseline preload | `R-PERF.5` | Scheduler, Memory, Agents, Runtime, Skills, Persona, TwinChat, TwinShell, TwinSearch and Doc/Image/Page Builder full runtimes are now route/context-gated; `/gpt/`, `/twin/`, `/twinchat/`, `/scheduler/`, `/skills/` and tool routes remain explicit exceptions. | Fixed locally | Re-measure frontend HTML and wp-admin separately; split TwinWeb public shortcode bootstrap before gating it. |
| LLM client preload | `R-PERF.5` | `core/bizcity-llm/bootstrap.php` is now gated in the main plugin and both compat-loader copies; plain frontend HTML no longer loads the gateway client graph. | Fixed locally | Verify OPcache/deployed loader parity and measure a fresh PHP worker. |
| Metadata cold misses in Query Monitor | `R-PERF.5` + `R-METADATA-CACHE` | Astro Checklist table check and Rolling/Episodic `identity_uuid` checks now use version fast paths plus blog/database-aware fallback caches, including cached false results. | Fixed locally | Re-run the same request and confirm rows 1–3 disappear when schema options are current; repair context may still issue one metadata query. |
| Outstanding R-DCL | `core/diagnostics/changelog/` | BZDOC, BZPB and remaining BZTIMG tables need canonical table registry + JSON ownership; BizCoach legacy tables need ownership reconciliation. | Open | Do not invent `since` versions; inventory installer DDL first, then add registry and JSON in one change. |

**Record rule:** Runtime-only performance changes belong in this ledger and
`R-PERF.5`; only table/column/index changes bump a module JSON
`current_version`/`history`. Every schema entry must pass the canonical
validator before merge.

### Memory unify progress and DDV — 2026-07-31
- **PASS:** `modules.zalobot.memory_unify` verifies the active ZaloBot staging contract: canonical TwinBrain writer call, normalized channel context, and rollback-controlled legacy writer flag. No LLM call or persistent write is performed by this probe.
- **PASS:** `twinbrain.memory.hub-rest` verifies mirror wiring plus POST/GET/PUT/DELETE `/memory/me` round-trip and sentinel cleanup.
- **Completed:** memory quarantine/inventory hardening, canonical writer context helper, Zalo staging wiring, REST/Notes mirror delete wiring, and the active diagnostics contracts are documented in [PHASE-MEMORY-UNIFY-ANALYSIS.md](core/memory/docs/PHASE-MEMORY-UNIFY-ANALYSIS.md).
- **Still blocked:** legacy Zalo writer shutdown, session/note read-policy decision, REST unified read cutover, EXPLAIN benchmark, historical backfill, write-cutover flag, and D7 rename/drop. No destructive migration was performed.

### Diagnostics and production triage — 2026-07-28
- **PASS:** `twinbrain.memory.writer.llm` now completes Mode 2 extraction with a unique per-run probe payload; the probe no longer collides with the writer's identity-scoped 24-hour dedupe transient. The latest runtime result persisted 4 extracted rows, including a `preference` row.
- **Fixed:** `core/intent/bootstrap.php` now guards Rolling/Episodic memory initialization with `class_exists()` and records an artifact/load-order error instead of producing a Class-not-found fatal when a memory file is missing or invalid.
- **Improved diagnostics:** Rolling and Episodic schema migration failures now include a bounded `db_error` reason in the PHP log, without logging SQL, credentials, or PII. This is needed to distinguish routed DDL refusal, read-only shards, missing ALTER privileges, and silent `dbDelta()` no-op behavior.
- **Validated locally:** touched PHP files have balanced braces and VS Code diagnostics report no errors. PHP CLI syntax validation is unavailable in the local environment.
- **OPEN — production artifact:** production still reports a parse error in `core/knowledge/includes/class-user-memory.php` at line 733, while the local file is structurally complete. Production file hash/deploy parity and OPcache/PHP-FPM refresh remain unverified; this is not considered resolved by local validation.
- **OPEN — tenant schema:** multiple tenant tables still lack `identity_uuid` in `bizcity_memory_rolling` and `bizcity_memory_episodic`. The migration/backoff prevents request storms but does not make the DDL succeed. The next production log must capture the new `db_error` field before choosing a router/privilege/read-only fix.
- **OPEN — retrieval hydration:** `twinbrain.retrieval.hydration` remains `CRITICAL`/not executed in the latest Diagnostics screen. The KG filestore fallback patch is present locally, but runtime PASS evidence is still missing.
- **Scope:** `store_locations` remains intentionally out of scope for this triage.

### Added — TwinBrain + TwinCore canon documentation pass (2026-06-03)
- Restructured BRAIN-SESSIONS group: moved `class-twinbrain-sessions-manager.php` + `class-twinbrain-sessions-rest.php` into [core/twinbrain/includes/sessions/](core/twinbrain/includes/sessions/) and feature design doc into [core/twinbrain/docs/sessions/](core/twinbrain/docs/sessions/). Bootstrap.php paths updated with R-STAMP.
- New: [core/twinbrain/docs/sessions/README.md](core/twinbrain/docs/sessions/README.md) — sessions group doc index (read-order + surface map + rule inheritance).
- New: [core/twinbrain/docs/sessions/TWINBRAIN-SESSIONS-CANON.md](core/twinbrain/docs/sessions/TWINBRAIN-SESSIONS-CANON.md) — sub-canon (rules · file map · session_id format · 5 event taxonomy · REST contract · Mode 4 + Tier F · anti-patterns · DDV checklist).
- New: [core/twinbrain/docs/sessions/TWINBRAIN-SESSIONS-ROADMAP.md](core/twinbrain/docs/sessions/TWINBRAIN-SESSIONS-ROADMAP.md) — SHIPPED waves (BS-1..5 + restructure) + 5 PENDING (BS-6 Scheduler bridge / BS-7 cron close-scan / BS-8 timeline REST / BS-9 hard-delete / BS-10 LLM mood upgrade) + risk matrix + dep graph.
- New: [core/twin-core/docs/TWINCORE-0-CANON.md](core/twin-core/docs/TWINCORE-0-CANON.md) — kernel canon for twin-core: 4-cluster topology (Trace+Debug · Event Stream Backbone · Focus Gate · Data Contract+State+Agent Loop), full file catalog, bootstrap order, feature flags, anti-patterns, sub-canon links to event-stream README.
- Updated: [core/twinbrain/docs/TWINBRAIN-0-CANON.md](core/twinbrain/docs/TWINBRAIN-0-CANON.md) v1.0 → v1.1 — added Layer 0.7 (Session Threading) to pipeline diagram, R-CH-NS + R-TBR-6 to inherited rules, sessions group entries to file catalog (§2), sessions docs to read-order (§4), 3 new anti-patterns (no `bizcity_brain_sessions` table, dispatch via bus, mood cadence rule), and links to sessions sub-canon + twin-core canon.

### Added — BRAIN-SESSIONS BS-1 → BS-4 (TwinBrain conversation threads + empathic memory) (2026-06-03)
- Foundation: 5 new event_types (`brain_session_{created,renamed,archived,mood_sampled,carry_forward}`) + JSON schemas (draft-07) + canonical session_id format `^brain_sess_[0-9]+_[0-9]+_[0-9a-f]{4}$`.
- Schema: VIEW `bizcity_brain_sessions` projects per-session state from `bizcity_twin_event_stream` (no new tables — R-TBR-6 compliant).
- Sessions Manager: `BizCity_TwinBrain_Sessions_Manager` (mint / create / rename / archive / list / latest_title / latest_mood).
- REST: `bizcity-twinbrain/v1/sessions` — `GET/POST/PATCH/archive` (X-WP-Nonce, ownership-checked).
- Runtime: `/turn` + `/turn/stream` accept `session_id`; SSE `started` frame echoes id; auto-mint on first turn.
- FE: `brainSessions.ts` API client + `brainSessionsStore` (Zustand, persists active session in `sessionStorage`) + `BrainSessionsList.tsx` sidebar (Refresh / New / archived toggle / Rename / Archive) + 220px collapsible column in `BrainHome`.
- Memory_Writer Mode 4: `sample_mood()` heuristic-only mood sampler (cadence-3 default, idempotent per `trace_id`, VN/EN cue lexicons, 9 labels, valence ∈ [-1,+1]). Emits `brain_session_mood_sampled`. Filters: `bizcity_twinbrain_mood_sample_cadence`, `bizcity_twinbrain_mood_derive` (LLM override hook).
- Memory_Recall Tier F: `🌱 Trạng thái cảm xúc (latest)` block in both legacy + unified collectors; counts `F`.
- Probes: `twinbrain.brain.sessions` (3-layer foundation), `twinbrain.brain.sessions.crud` (real-call CRUD), `twinbrain.brain.mood.sampler` (real-call mood + Tier F + idempotency).
- Docs: [core/twinbrain/docs/sessions/TWINBRAIN-FEATURE-BRAIN-SESSIONS.md](core/twinbrain/docs/sessions/TWINBRAIN-FEATURE-BRAIN-SESSIONS.md) bumped to v1.3 ACTIVE with §16 ship log.

### Added — Phase 0.99 Framework v1.0 Readiness
- `composer.json` root + PSR-4 autoload `BizCity\Twin\` namespace + classmap fallback giữ legacy `BizCity_*`.
- `core/twin-core/contracts/framework-contracts.php` — public interfaces (`BizCity_Module_Interface`, `BizCity_LLM_Client_Interface`, `BizCity_Tool_Interface`, `BizCity_Agent_Interface`) + abstract `BizCity_Module_Base`.
- `core/bizcity-llm/includes/helpers-deprecation.php` — `BizCity_Deprecation::notify()` / `notify_filter()` / `notify_storage()` + filter `bizcity_deprecation_silent` + action `bizcity_deprecation_notice`.
- `docs/extension/HOOKS.md` — public hooks catalog (40+ filter/action với `@since`).
- `docs/getting-started.md` — 5-min setup guide cho dev mới.
- `docs/extending/sub-plugin-quickstart.md` — copy-paste sub-plugin template.
- `docs/extending/agent-tool-recipe.md` — pattern register tool vào agent registry.
- `docs/roadmaps/PHASE-0.99-FRAMEWORK-V1.md` — 8-sprint roadmap để tag `v1.0.0`.
- `.github/copilot-instructions.md` — rule TỐI THƯỢNG R-GW-API-CATALOG (workflow lookup/extend 1-API trước khi code).
- `core/twin-core/contracts/class-module-registry.php` — implementation cho filter `bizcity_register_module` (boot lifecycle, requirement gating, exception isolation, inventory introspection).
- `core/diagnostics/includes/probes/class-probe-module-registry.php` — diagnostic probe `core.module-registry` (R-DDV) surface 3rd-party modules đăng ký qua filter.
- `bin/diagnostics-run.php` — headless CLI runner cho diagnostics + JUnit XML reporter (`--junit=path`, `--filter=glob`, `--skip-network`).
- `.github/workflows/ci.yml` — PHP 7.4/8.1/8.2 matrix · syntax check · grep guards (PHP 7.4 compat + R-GW-8 anti-patterns) · schema validator · diagnostics CLI mock · HOOKS.md coverage diff.
- `CHANGELOG.md` + `CONTRIBUTING.md` + `SECURITY.md` + 3 `.github/ISSUE_TEMPLATE/` files + `.github/PULL_REQUEST_TEMPLATE.md` cho OSS hygiene.

### Changed
- [`core/knowledge/includes/functions.php`](core/knowledge/includes/functions.php) canonical filter đổi sang `bizcity_after_handle_guest_flows`; legacy `bizgpt_after_handle_guest_flows` vẫn applied (back-compat) + emit deprecation notice via `BizCity_Deprecation::notify_filter()`. Sẽ remove ở 2.0.0.
- [`core/helper-legacy/flows/legacy_bizgpt_facebook.php`](core/helper-legacy/flows/legacy_bizgpt_facebook.php) `twf_handle_facebook_multi_page_post()` chuyển sang `BizCity_Deprecation::notify()` (fallback `_doing_it_wrong()` khi class chưa load).
- [`core/bizcity-llm/includes/class-llm-client.php`](core/bizcity-llm/includes/class-llm-client.php) `generate_image()` forward thêm `input_images[]` + `stream` xuống gateway.
- [`plugins/bizcity-tool-image/includes/class-qr-studio-page.php`](plugins/bizcity-tool-image/includes/class-qr-studio-page.php) refactor sang `BizCity_LLM_Client` (R-GW-8 compliance, không còn dependence vào `BizCity_Router_Proxy`).
- [`plugins/bizcity-doc/includes/image/class-image-pipeline.php`](plugins/bizcity-doc/includes/image/class-image-pipeline.php) cùng pattern — không còn reference server-only class trên client.
- [`plugins/bizcity-tool-image/includes/admin-menu.php`](plugins/bizcity-tool-image/includes/admin-menu.php) Character Studio status check qua `BizCity_LLM_Client::is_ready()`.

### Server-side companion (`bizcity-llm-router`)
- `handle_image_generation` whitelist `input_images[]` + dispatch sang `generate_image_stream()` khi có vision refs.

### Deprecated
- Filter `bizgpt_after_handle_guest_flows` → renamed to `bizcity_after_handle_guest_flows` (will remove in 2.0.0). Emits a one-shot notice when listeners are attached.
- `twf_handle_facebook_multi_page_post()` → use `BizCity_FB_Publisher` via the scheduler (`event_type=fb_post`).

### Removed
- Bundled vertical plugins moved to `plugins/_archived/` (no longer auto-loaded):
  - `bizcity-automation` → replaced by `core/automation/` (native xyflow runtime, BE-1..BE-5 shipped 2026-05-29).
  - `bizcity-tool-mindmap` → mindmap functionality moved to `bizcity-doc` (Phase 6.3 PHASE-0.7-DOCGEN).
  - `bizcity-tool-woo` → archived; WooCommerce tools to be re-shipped via Marketplace branch (catalog #11).
  - `bizcity-crm-tichdiem` → archived; loyalty/points adapter folded into `bizcity-twin-crm` Customer Source registry (`bizcity_crm_register_customer_sources` filter, `modules.twin-crm` v1.16.0).
- Loader entry `bizcity-tool-mindmap` removed from `_bizcity_bundled_must_load` in [bizcity-twin-ai.php](bizcity-twin-ai.php).

---

## [1.3.7] — 2026-05-29

### Added
- AUTOMATION BE-5 — CRM bridge polish (`emit_crm_bridge()` capture `event_id` qua filter `bizcity_crm_event_create_filter` rồi UPDATE ngược `runs.crm_event_id`).
- Legacy guard `admin_notices` cảnh báo plugin `bizcity-automation` cũ collision hook.
- User guide `core/automation/docs/AUTOMATION-USER-GUIDE.md` (12 chương).

### Fixed
- `class-channel-router.php` — thay `str_contains()` (PHP 8+) bằng `strpos() !== false` (compat PHP 7.4).
- `class-fb-publisher.php` — bỏ union return type `array|WP_Error` (fatal trên PHP 7.4).

---

## [1.3.6] — 2026-05-26

### Added
- Channel Gateway namespace `bizcity-channel/v1` (R-CH-NS) — bypass mu-plugins `bizgpt-multisite.php`.
- R-DCL-NAME · Single Canonical Table Name rule (RENAME TABLE thay INSERT...SELECT).
- Probe `class-probe-cg-flows.php` step "Runtime · interim table dropped" anti-duplicate.

### Changed
- `bizcity_kg_sources` table renamed atomically từ legacy `wp_bizgpt_custom_flows` qua RENAME TABLE.

---

## [1.3.5] — 2026-05-22

### Added
- 41 diagnostics probes (KG seeding, deep research, vector+graph, web verticals: gov/law/med/nutri/scholar/tax, fb publisher, automation, twinbrain memory hub).
- 3-layer evidence per probe (Disk · Loader · Runtime).
- `BizCity_Diagnostics_Smoke_Runner` orchestrator + REST runner.

---

## [1.3.0] — 2026-05-14

### Added
- TwinBrain Central Brain — agent runner + perspective runner + ReAct loop.
- Memory hub (REST + writer + recall + tool calls).
- Web research verticals (10 domains).

### Changed
- Refactor `core/intent/` → orchestration / classification / routing / infrastructure layers.

---

## Earlier history

Pre-1.3.x history is maintained per-phase in [docs/roadmaps/](docs/roadmaps/) (PHASE-0.x.y files).
Schema migration history in [core/diagnostics/changelog/*.json](core/diagnostics/changelog) (R-DCL).

---

## Versioning policy

- **MAJOR** (`x.0.0`) — breaking changes to public contracts (`BizCity_*_Interface`), namespace removal, hook signature break.
- **MINOR** (`1.x.0`) — new features, new modules, new hooks (additive only). Sub-plugin author không cần đổi code.
- **PATCH** (`1.0.x`) — bug fix, internal refactor, perf. Hook signature giữ nguyên.

Deprecated APIs giữ ≥ 1 minor version với `BizCity_Deprecation::notify()` warning trước khi remove.

[Unreleased]: https://github.com/bizcity/bizcity-twin-ai/compare/v1.3.7...HEAD
[1.3.7]: https://github.com/bizcity/bizcity-twin-ai/compare/v1.3.6...v1.3.7
[1.3.6]: https://github.com/bizcity/bizcity-twin-ai/compare/v1.3.5...v1.3.6
[1.3.5]: https://github.com/bizcity/bizcity-twin-ai/compare/v1.3.0...v1.3.5
[1.3.0]: https://github.com/bizcity/bizcity-twin-ai/releases/tag/v1.3.0
