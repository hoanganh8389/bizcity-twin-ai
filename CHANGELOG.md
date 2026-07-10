# Changelog

All notable changes to **BizCity Twin AI** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> Schema-level changes (DB tables/columns) live in per-module JSON files under
> [core/diagnostics/changelog/](core/diagnostics/changelog) and are NOT duplicated here.
> See rule [R-DCL · Diagnostics Changelog](docs/diagnostics/PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md).

---

## [Unreleased]

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
- Docs: [core/twinbrain/docs/TWINBRAIN-FEATURE-BRAIN-SESSIONS.md](core/twinbrain/docs/TWINBRAIN-FEATURE-BRAIN-SESSIONS.md) bumped to v1.3 ACTIVE with §16 ship log.

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
