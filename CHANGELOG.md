# Changelog

All notable changes to **BizCity Twin AI** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> Schema-level changes (DB tables/columns) live in per-module JSON files under
> [core/diagnostics/changelog/](core/diagnostics/changelog) and are NOT duplicated here.
> See rule [R-DCL · Diagnostics Changelog](docs/diagnostics/PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md).

---

## [Unreleased]

### TwinBrain MPR V5 Gate 6 outbound evidence hardening — 2026-08-19

| Area | Change | Status |
|---|---|---|
| Channel Gateway outbound evidence | `BizCity_Gateway_Sender` now publishes explicit `idempotency_key` metadata alongside `side_effect_status` and `provider_request_id` in `bizcity_channel_outbound_logged` payloads for both adapter and legacy send paths. This aligns with Gate 6 aggregate disk checks and live-canary evidence capture requirements. | Fixed locally; deploy + OPcache refresh + probe rerun required |
| Goal Contract probe version gate | `twinbrain.goal_contracts` no longer requires strict `current_version === DB_VERSION` for `core.twinbrain.json`. The probe now accepts `current_version >= DB_VERSION` so module-level changelog bumps (for taxonomy/non-DDL rows) do not false-fail the R-DCL table contract check. | Fixed locally; deploy + probe rerun required |
| MPR V5 roadmap/probe parity | Documented the Gate 6 marker fix in the MPR V5 roadmap as source-level closure for aggregate step-4 (`Gateway outbound idempotency evidence`) while keeping §21 DoD checkboxes unchanged until synthetic rerun and live canary evidence pass. | Updated locally |

### TwinChat Woo BizOps direct vertical path — 2026-08-16

| Area | Change | Status |
|---|---|---|
| TwinBrain runtime | `web_mode=woo_bizops` now skips generic Notebook Selector and Tool Intent stages; Woo is treated as a direct Vertical Plugin and still uses Final Composer for presentation. | Fixed locally; tenant rerun required |
| Woo order list | Added bounded `order_list` intent using HPOS-aware `wc_get_orders()` with structured `orders[]`, date range, status, total and citations. | Fixed locally; tenant rerun required |
| TwinChat timeline | Added visible Woo domain/query/composed stages and order count handling for `woo_bizops_*` SSE events. | TwinChat build PASS |
| Automation picker | `#` overlay now says Automation Workflow, mutually exclusive picker overlays close on a new prefix, and Builder exposes `command_invokable` for workflow listing. | Automation/TwinChat builds PASS |

### Guru versus Vertical Plugin terminology — 2026-08-16

| Area | Change | Status |
|---|---|---|
| Product vocabulary | Canonical split is now explicit: Guru = notebook/knowledge scope; `/vertical_slug` = Vertical Brain Mode / Plugin capability. `guru_id` and `BizCity_TwinBrain_Guru_Policy` remain compatibility identifiers only. | Documentation and user-facing error text updated |
| Woo BizOps policy messages | Replaced user-facing wording that implied Guru owns Woo BizOps access with `scope notebook` and `Vertical Plugin capability` wording. | Updated locally |

### Automation Diagnostics runtime evidence — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| Automation loader | Diagnostics loads the Automation surface before probe execution; Ask Guru uses the active `blocks/actions/` path. | Fixed locally; runtime evidence updated | Run `core.automation` on the canonical Diagnostics page after deploy and verify every required class, including Templates Seeder. |
| `automation.matcher` probe | Fixture carries linked owner identity, preserves top-level `raw_text` for `@` command tests, and invokes `extract_ref_uuid()` with its current `(payload, platform)` signature. | **Runtime PASS confirmed** | Synthetic channel payloads must satisfy R-ZONE/R-CH-IDMEM identity and normalized/raw text contracts. |
| `automation.crm_path` probe | CRM instantiate assertion unwraps canonical REST `{ ok, row }` response; Zalo OA/Bot fixtures include owner identity. | **Runtime PASS confirmed** | Probe assertions must consume the public REST DTO rather than assuming a flat row. |
| `twinbrain.notebook_depth` | Source map, W0.20 graph/retrieval/rerank pack, citation guard and depth profile contract pass on the tenant. | **Runtime PASS confirmed** | Keep notebook source-layer and final-composer profile expectations versioned together. |
| Templates Seeder visibility | Seeder remains intentionally lazy; the core Automation probe loads it only while that Diagnostics probe runs. | Fixed locally; rerun required | Do not load template seeding on unrelated admin, frontend or channel requests. |
| BE-7 builtin catalog count | The core Automation probe now runs an explicit idempotent `force_reseed()` before comparing builtin slugs, and reports failed slugs instead of accepting a partial catalog. | Fixed locally; rerun required | A partial builtin catalog must be repaired and measured before `core.automation` can PASS. |
| CCG-1 explicit command smoke | Extended the existing `core.automation` probe with an exact `#workflow_slug` + args resolve assertion in `zone=admin`, using a disposable command-invokable workflow and existing cleanup. | Fixed locally; rerun required | Keep explicit command DDV inside the tenant-visible core probe when the standalone command-zone probe is not exposed. |

### TwinChat Woo BizOps vertical dispatch — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| `/woo_bizops` dispatch | The generic `bizcity_twinbrain_web_mode_effective` Guru web-fallback gate converted `woo_bizops` to `off` before `dispatch_web_research()`, so TwinChat stayed in Notebook/MPR and never emitted `woo_bizops_*` events. Woo BizOps now passes that generic gate and is checked by its dedicated `BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS` boundary. | Fixed locally; tenant rerun required | Do not apply the generic web-fallback flag to sensitive built-in verticals with their own capability policy. |

### BizCoach Pro F.9 diagnostics contract — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| `T-BCPRO.F9.a` Intent Provider class | The Diagnostics admin page is outside the normal Intent loader gate, so the read-only BizCoach matrix could evaluate `class_exists( 'BizCoach_Pro_Intent_Provider' )` before its base contract was available. The BizCoach probe now loads the Intent bootstrap/contract only during its precondition, then loads the lazy BizCoach provider. | **Runtime PASS confirmed** — F.9: 4 PASS · 0 FAIL · 0 WARN · 0 SKIP; matrix: 48 PASS · 12 WARN | Keep Diagnostics probes self-contained for the contracts they inspect; do not widen the global Intent preload gate for unrelated admin pages. |

### Diagnostics command-zone probe interface fatal — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| `core.twinbrain.command_zone` probe | The probe declared `private static cleanup(array $ids)`, conflicting with the required public non-static `BizCity_Diagnostics_Probe::cleanup(): void`. Renamed the health-test helper to `cleanup_workflows()` and added the interface-compliant cleanup method. | Fixed locally; production deploy required | Every new probe must be checked against `interface-diagnostics-probe.php` before lazy loading. |

### Automation diagnostics probe parse fatal — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| `core.automation` probe | Removed stray standalone action block strings that caused `unexpected ','` in `class-probe-automation.php`, and restored the missing `Repo_Runs::enqueue()` health-test step before runner execution. | Fixed locally; production deploy required | Run PHP syntax validation for every probe before deploy. A single malformed lazy-loaded probe can abort the entire Diagnostics catalog. |

### TwinChat shell KG-Hub menu fatal — 2026-08-13

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| Central admin menu | `includes/class-admin-menu.php` called `BizCity_KG_Admin_Menu::instance()` even on the TwinChat shell, where PHASE-1.26 intentionally loads only the lightweight Knowledge admin-menu class and not the KG-Hub runtime. Added a `class_exists()` guard; the full admin path remains registered by the KG-Hub bootstrap. | Fixed locally; production deploy required | Keep shell-only loader paths compatible with central menu callbacks. Run the admin-navigation probe on both the TwinChat shell and full Knowledge/KG-Hub admin surface. |

### Zalo Bot AI gateway unavailable — 2026-08-13

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| Zalo `/zalohook/` AI reply | The request gate loaded Knowledge/TwinBrain for `/zalohook/`, but both the main and compat LLM loader gates only recognized `/bizhook/`, `/wp-json/`, `/gpt`, and `/twin`. `BizCity_LLM_Client` was therefore absent, so the runtime misleadingly reported the gateway/API key as unavailable. Added `/zalohook/` to the main plugin and both compat loader copies. | Fixed locally; production deploy and OPcache refresh required | Keep main plugin, source compat, and deployed compat route matrices identical. Smoke-test `/zalohook/` for `BizCity_LLM_Client` before testing provider credentials. |
| Tenant Rolling Memory | Tenant `wp_1513_bizcity_memory_rolling` was missing `blog_id`/`identity_uuid` while the version option was already `1.4`. The version-only fast path allowed runtime SQL to reach the missing columns. Added physical-column verification and read/write guards. | Fixed locally; tenant migration still must be verified on `slave10` | A schema version option is not physical-shard evidence. Verify required columns on the routed tenant after deploy. |
| Zalo no-match reply | The automation matcher sent `BizCity_Automation_Default_Reply`, then legacy `twf_handle_chat_flow()` also sent a response. Disabled the automation default reply for Zalo Bot when the legacy responder is active. | Fixed locally; production deploy required | One channel/request must have one responder owner. Check send traces for duplicate `automation.default_reply` and legacy TWF sends. |
| Workflow continuity | Raw Zalo intake did not resolve the linked WordPress owner before enqueue, and `web_post` scheduler metadata lacked `web_content` in the stale production path. Added owner resolution and the required metadata field. | Fixed locally; production deploy required | Test owner/chat continuity and scheduler metadata on a real workflow run, not only static contracts. |
| Zalo `/link` and SSO binding | Automation slash matching could claim `/link <nonce>` before `BizCity_Zalobot_Command_Router`, while the CRM SSO return could lose `bzzalolink` before token consumption. Reserved `/link` at both matcher/router boundaries and added a short-lived return marker so an authenticated SSO return can consume the pending magic link. | Fixed locally; production deploy required | Treat identity-binding commands as system-reserved. `welcome=1` is only a UI redirect; verify the canonical channel mapping and `link_command_bound` evidence. |
| Magic-link ownership contract | Documented and locked ownership: `BizCity_CRM_Magic_Link` / `BizCity_CRM_Magic_Link_Handler` own issue, verify, consume and browser/SSO callback; `BizCity_Channel_User_Linker` owns canonical `ZALO_BOT` identity mapping; `BizCity_Zalobot_User_Linker` is compatibility-only during migration. Updated R-CH-UNI, CRM Phase 3.5, Zalo Admin Guide and identity/memory/notebook roadmaps. | Documentation updated 2026-08-13 | Do not add token issuance or callback handling back to a channel plugin. Validate both `consumed_at` and canonical `bizcity_channel_user_links` after login. |
| Legacy TWF retirement for Zalo Bot | Production evidence showed `bizgpt_chatbot_run_admin_flows()` still calling `twf_process_flow_from_params()` for `ZALO_BOT`, causing `/link <nonce>` to be classified by LLM and then handled as ordinary chat. Added hard-stop guards in the active MU adapter, bundled Zalo adapter, and `core/helper-legacy/legacy_flow-router.php`. | Fixed locally; production deploy required | A Zalo Bot request must not emit `ai_result user_text`, `ai_result json`, or legacy `twf_handle_chat_flow`; it must be handled by canonical UCL/Command Router/Automation only. |
| `/link` recovery and login status | `/link` nonce failures now inspect the current canonical identity first: an already-linked user is told the active WordPress account; an unlinked user receives a fresh CRM Magic Link instead of only an error message. The `đăng nhập` command reports the linked account or explicitly says no account is linked and sends a fresh link. | Fixed locally; production deploy required | Do not report `chưa đăng nhập` when `resolve_wp_user()` returns a user. Only unlinked identities receive a replacement URL. |

### R-PERF/R-CACHE audit ledger — 2026-08-09

| Area | Canonical record | Change / evidence | Status | Next action |
|---|---|---|---|---|
| Diagnostics probe lazy queue | `R-PERF-LOADER` + `R-DDV` | Removed an early `bizcity_diagnostics_load_probes_once()` flush from `core/diagnostics/bootstrap.php`; it could mark the loader complete before the remaining probe queue declarations were registered, leaving the Diagnostics catalog empty or incomplete. | Fixed locally 2026-08-16 | Deploy to the affected site and verify `GET /wp-json/bizcity-diagnostics/v1/smoke/probes` returns a non-empty catalog as an admin. |
| Canonical loader rule | `R-PERF-LOADER` + `R-DDV` | Codified PHASE-1.23 lessons: surface-scoped loading, pre-`plugins_loaded` evidence, compat/main/bundle parity, shell iframe isolation, file/class delta as primary signal, and QM A/B instrumentation. The observed shell gates reduced approximately 6 MB and are now mandatory guidance for new core/module/plugin loaders. | Fixed locally | Read `docs/rules/PHASE-0-RULE-PERFORMANCE-LOADER.md` before any loader/context-gate change. |
| PHASE-1.23 roadmap status | `R-PERF-LOADER` + `R-DDV` | Updated the root-cause document from analysis-only to implementation status: Wave 1–3 done locally, Wave 5 in progress, Wave 4 WooCommerce REST profiling next, and Wave 6 surface manifest/thin cron bridge planned. Added A/B, regression matrix and stop conditions. | Fixed locally | Establish deploy parity and route-level Woo evidence before shared-runtime changes. |
| PHASE-1.23 bundle root-cause dossier | `R-PERF-LOADER` + `R-DDV` | Added a five-layer trace model (`entrypoint` -> `hook` -> `runtime` -> `data/provider` -> `coexistence`), evidence fields, verified code anchors, root-cause categories, and a per-group matrix for CRM/BizCoach, Intent, LLM, channels, tools, Dino plugins and MU files. | Fixed locally | Capture first include/parent callback and classify each row before writing further guards. |
| PHASE-1.23 continuity evaluation | `R-PERF-LOADER` + `R-DDV` | Added a framework continuity scorecard (xuyen suot/ke thua/lien mach/no-recall/no-miss/no-broken-flow), Go/No-Go decision, invariants and pre-Wave-4 evidence gates. Declared route-level ownership trace as the next valid step and blocked broad guard expansion. | Fixed locally | Upgrade observability ownership fields (`callback_file`, `first_loaded_file`, `require_parent`) before route-level Woo/payment/channel refactor. |
| PHASE-1.23 loader risk deep review | `R-PERF-LOADER` + `R-DDV` | Distinguished duplicate file loading from duplicate hook/boot/state loading; documented main/compat/bundled/regular multi-owner paths; identified current collector blind spots and flow-break modes for OAuth, payment, webhook and cron; added PASS evidence gates. | Fixed locally | Add canonical boot state and causal trace fields before further context-gate changes. |
| PHASE-1.23 instrumentation and boot ownership spec | `R-PERF-LOADER` + `R-DDV` | Added `docs/analysis/PHASE-1.23-R-PERF-INSTRUMENTATION-BOOT-OWNERSHIP-SPEC-2026-08-10.md`: observe-only instrumentation v2, monotonic boot states, canonical owner claims, duplicate registration evidence, bounded JSONL schema, migration phases and flow invariants. Explicitly excludes WooCommerce loader/payment changes. | Documentation complete | Implement Level A/B evidence first; keep ownership observe-only until deployment topology is proven. |
| PHASE-1.23 QM trace operating model | `R-PERF-LOADER` + `R-DDV` | Added `docs/analysis/PHASE-1.23-R-PERF-QM-TRACE-PROBE-AUDIT-BA-TEST-2026-08-10.md`: QM limits, runtime/TwinCore/helper event layers, boot/hook/route/cron ownership, user-meta/cache semantics, probe catalog, PM/BA/audit/test/SRE responsibilities and acceptance matrix. WooCommerce remains observe-only external baseline. | Documentation complete | Implement observe-only QM/event/probe layer before any ownership enforcement or loader migration. |
| PHASE-1.23 CANONICAL roadmap | `R-PERF-LOADER` + `R-DDV` | Established `docs/roadmaps/PHASE-1.23-CANONICAL.md` as the single source of truth with scope boundary, canonical architecture, boot ownership, trace contract, W0-W7 code roadmap, cross-functional ownership, Definition of Done, stop conditions and release decisions. Marked the prior roadmap as supporting implementation notes. | Canonical established | Execute W1 observe-only QM instrumentation; do not change WooCommerce or enforce ownership before W1/W2 evidence passes. |
| PHASE-1.23 W1/W3 trace implementation | `R-PERF-LOADER` + `R-DDV` | Implemented local observe-only `bizcity.loader.v2` snapshots/export: callback file/line, bounded first/last file anchors, request context, all-callback source aggregation, registration delta and explicit unknown parent/boot-state fields. Added lazy Diagnostics probe `core.loader.trace_completeness`; no loader decision or WooCommerce code changed. | Implemented locally | Run Diagnostics/QM/JSONL A/B and deploy-parity validation before W2 ownership claims. |
| PHASE-1.23 W2 ownership observe-only | `R-PERF-LOADER` + `R-DDV` | Added `BizCity_Loader_Ownership_Registry` with monotonic state, canonical path claims, secondary-owner/version conflict events and QM/probe visibility. Integrated `llm_client`, `knowledge`, `intent` and `twin_core` claims across main, source compat and deployed compat loaders; corrected registry sequencing before the first claim and record claim attempts even when `class_exists()` skips a require. No enforcement and no WooCommerce changes. | Implemented locally | Deploy parity and run main/compat/bundled/regular combination matrix before W2 PASS. |
| PHASE-1.23 W3 loader probes | `R-PERF-LOADER` + `R-DDV` | Added lazy Diagnostics probes `core.loader.ownership` and `core.loader.registration_integrity` alongside `core.loader.trace_completeness`. They audit canonical owner/state, duplicate/conflicting claims, hook identity, available REST route identity and required cron schedules without changing runtime behavior. | Implemented locally | Run all three probes on Diagnostics/REST/CLI contexts; user-meta/cache probes remain planned. |
| PHASE-1.23 W4 TwinCore semantic trace | `R-PERF-LOADER` + `R-DDV` | Added bounded parent-child runtime spans to `BizCity_Twin_Trace`, instrumented `Twin_Context_Resolver::build_prompt_bundle()`, and added lazy probe `twinbrain.runtime.continuity` for balanced enter/exit/open-span evidence. No DB/event-stream/provider writes and no payload logging. | Partial implemented locally | Extend one BizCity-owned boundary at a time across LLM, Knowledge, Memory, Composer, Scheduler and Channel Sender. |
| PHASE-1.23 W4 LLM boundary trace | `R-PERF-LOADER` + `R-DDV` | Instrumented `BizCity_LLM_Client::chat()` with one parent operation span and child spans for primary/fallback gateway attempts. Continuity probe now verifies parent existence and requires `chat_gateway` children to belong to `llm_client.chat`; fallback remains explicit rather than being misclassified as duplicate runtime. | Partial implemented locally | Validate one gateway request and one fallback/degraded request without logging prompts, keys or provider payloads. |
| PHASE-1.23 W4 streaming boundary trace | `R-PERF-LOADER` + `R-DDV` | Instrumented `BizCity_LLM_Client::chat_stream()` with primary/fallback `chat_stream_gateway` child spans and extended continuity assertions for blocking and streaming parent edges. No callback chunks, prompts or credentials are logged. | Partial implemented locally | Exercise blocking and streaming routes, then extend semantic spans to Memory/Knowledge one boundary at a time. |
| PHASE-1.23 W5 Memory Recall boundary trace | `R-PERF-LOADER` + `R-DDV` | Instrumented `BizCity_TwinBrain_Memory_Recall::collect()` with parent recall span and unified/legacy child spans, including hashed scope, tier/citation counts and explicit fallback path. Extended continuity probe to validate memory child edges without logging memory text or prompt content. | Partial implemented locally | Validate unified-enabled and legacy fallback requests; add user-meta/cache semantic wrappers next. |
| PHASE-1.23 W5 cache semantic trace | `R-PERF-LOADER` + `R-DDV` | Added opt-in `BizCity_Cache` semantic spans for get/set/delete/flush_group with blog-scoped key signatures, hit/success and TTL buckets; added lazy `core.cache.trace_integrity` probe. Normal requests remain uninstrumented at cache-operation level and raw keys/values are never logged. | Partial implemented locally | Run forensic cache trace and correlate hit/miss/invalidation with QM DB/cache evidence; user-meta trace remains next. |
| PHASE-1.23 W5 user-meta semantic trace | `R-PERF-LOADER` + `R-DDV` | Added forensic-only WordPress user-meta filters for get/update/add/delete with hashed user/key scope, key family, blog ID, caller file/line and short-circuit result; added lazy `core.user_meta.trace_integrity` probe. Filters are absent on ordinary requests and raw values are never logged. | Partial implemented locally | Run a forensic profile/login/memory request and classify repeated reads by business scenario. |
| PHASE-1.23 registration duplicate audit correction | `R-PERF-LOADER` + `R-DDV` | Screenshot review showed `dup:3/7` could conflate separate object instances sharing the same method. Registration keys now include request-local object/closure identity hashes; duplicate counts must be re-captured before being treated as real duplicate boot. | Fixed locally | Re-run the same QM request and compare identity-aware `dup` counts; keep Woo/external buckets as observed baseline only. |
| PHASE-1.23 loader screenshot metric correction | `R-PERF-LOADER` + `R-DDV` | Screenshot showed BizCity phase memory at 2 MB beside QM top-bar 49 MB. Snapshot/output now separates current, allocated and peak PHP memory; path normalization also handles relative/Windows/UNC paths before source-group classification. | Fixed locally | Re-capture the same request; compare current/peak columns with QM top-bar and confirm remaining `external/unknown` is genuinely external. |
| PHASE-1.23 instrumentation overhead correction | `R-PERF-LOADER` + `R-DDV` | Screenshot showed `+15.7 MB` to `+31.8 MB` capture deltas caused by the snapshot itself retaining callback rows/Reflection/source summaries. Renamed the metric to `capture_overhead_delta_kb`, switched default QM requests to summary mode, retained full detail only for Diagnostics or `?bizcity_qm_probe=1`, and kept runtime used/peak separate. | Fixed locally | Re-capture QM-only and explicit forensic requests; never use capture overhead as plugin phase memory. |
| PHASE-1.23 QM readiness gate | `R-PERF-LOADER` + `R-DDV` | Added `memory_metric_consistent` evidence and explicit readiness policy: targeted BizCity fixes may proceed when duplicate/ownership evidence is clean; WooCommerce/Object Cache Pro/theme/external fixes remain separate workstreams. Diagnostics probe graph is not treated as normal shell baseline. | Fixed locally | Recapture after deploy/OPcache; start only owner-scoped BizCity fixes from clean evidence. |
| PHASE-1.23 runtime recapture blocker | `R-PERF-LOADER` + `R-DDV` | QM now shows ownership parity (`CONTRACT_READY`, `conflicts=0`) and the canonical footer pair is valid (`85.55 / 85.55 MB`, `source: lifecycle_snapshot`). The remaining red state is now explicitly labeled `raw allocator mismatch`, separating PHP/OPcache evidence drift from the displayed canonical invariant. | Canonical display fixed / raw runtime evidence pending | Deploy hook panel, collector, HTML output, header output, registry and BizCoach entrypoint together; refresh OPcache/PHP-FPM; verify raw consistency is true before closing the gate. |
| PHASE-1.23 owner-track analysis roadmap | `R-PERF-LOADER` + `R-DDV` | Added `docs/analysis/PHASE-1.23-BUNDLE-OWNER-FIX-ROADMAP-2026-08-10.md` with deep analysis for BizCoach, CRM, Intent, LLM, TwinCore/Knowledge/Memory, bundle/compat ownership, cache/user-meta and channel/OAuth/cron boundaries. Added mandatory pre-fix evidence, BA/audit/test gates and A0-A7 analysis roadmap. No runtime code changed. | Documentation complete | Use the seven-item readiness rule before any owner-scoped fix. |
| PHASE-1.23 BizCoach first lazy split | `R-PERF-LOADER` + `R-DDV` | Deferred `bizcoach-pro/includes/admin/class-astro-admin-form.php` from plugin file scope to `BizCoach_Pro_Astro_Admin_List::render_add_new()`. The class has no file-scope hook/route side effect; public routers, REST, cron and Intent provider paths remain unchanged. | Implemented locally | Validate Vedic/BaZi add-new submit plus normal admin/list/detail and bundled/regular ownership before the next BizCoach split. |
| PHASE-1.23 BizCoach bundled ownership claim | `R-PERF-LOADER` + `R-DDV` | Added observe-only `bizcoach` claim/`CONTRACT_READY` transition at the bundled entrypoint, before shell guard and after stable constants. This records shell skip and real surface ownership without blocking or changing runtime loading. | Implemented locally | Recapture Boot ownership on shell, BizCoach admin and public/REST surfaces before enforcing or splitting more graph. |
| PHASE-1.23 BizCoach OPcache invalidation fix | `R-PERF-LOADER` + `R-DDV` | Removed per-request `opcache_invalidate()` for BizCoach Astro REST class. Kept `require_once` and explicit idempotent `init()`; stale bytecode is handled by deploy/maintenance OPcache refresh. No route, OAuth or provider behavior changed. | Implemented locally | Validate persona REST, public astrology/transit, normal admin and deploy OPcache procedure before next BizCoach split. |
| PHASE-1.23 BizCoach persona provider lazy split | `R-PERF-LOADER` + `R-DDV` | Added `bcpro_load_persona_provider_classes()` and moved persona provider requires behind the provider filter, direct REST consumers and diagnostic F4/F6/F14 tasks. Provider IDs/source kinds remain unchanged; no new ownership path or Woo code changed. | Implemented locally | Validate provider catalog, coach-map REST, passage REST and BizCoach diagnostics before the next eager include split. |
| PHASE-1.23 BizCoach admin lifecycle splits | `R-PERF-LOADER` + `R-DDV` | Deferred `class-admin-coachees.php`, deprecated `class-astro-admin-settings.php`, and `class-astro-log-admin.php` from normal admin file scope. Menu registration stays on `admin_menu`; legacy Astro settings POST handlers and Astro log AJAX registration load only for their matching actions. No public, REST, cron or Intent contract was changed. | Implemented locally | Validate Coachees/Vedic/BaZi menus, legacy settings POST actions, Astro log AJAX, normal admin, bundled/regular ownership and fresh deploy parity. |
| PHASE-1.23 framework monitor evaluation | `R-PERF-LOADER` + `R-DDV` | Added [framework monitor evaluation](docs/analysis/PHASE-1.23-FRAMEWORK-MONITOR-EVALUATION-2026-08-10.md) from the current QM/BizCity Loader capture. It separates ownership PASS, bounded registration evidence, Diagnostics contamination, external Woo/Flatsome baseline, raw memory evidence and the prioritized optimization framework/backlog. | Research complete / runtime gates open | Use the dossier to run surface-labeled A/B captures and route-level owner analysis; do not treat the Diagnostics request as a normal shell memory baseline. |
| PHASE-1.23 monitor severity/result UX | `R-PERF-LOADER` + `R-DDV` | Clarified the Diagnostics probe table labels from `Severity`/`Last result` to `Risk severity`/`Last runtime result`. Probe semantics remain unchanged: a critical-risk probe can legitimately have a passing latest run. | Implemented locally | Re-capture the Diagnostics page and verify the labels prevent confusion without changing probe status or severity. |
| PHASE-1.23 monitor runtime identity | `R-PERF-LOADER` + `R-DDV` | Added bounded runtime identity to loader context and QM output: plugin/PHP version, OPcache availability, release hash and short hashes for main/collector/panel/registry/source-deployed compat artifacts. Missing files remain `unknown`; no loading decision changed. | Implemented locally / runtime evidence pending | Deploy the hook panel/output with the same release, then compare runtime identity hashes across source/deployed and regular/compat requests. |
| PHASE-1.23 version authority parity | `R-PERF-LOADER` + `R-DDV` | Removed the stale compat version `1.0.1`; main and both compat paths now use canonical `1.3.7` and expose `BIZCITY_TWIN_AI_VERSION_SOURCE` (`compat_constant` or `main_constant`). Runtime identity renders `version_source` so early constant shadowing is visible. | Implemented locally / artifact parity pending | Deploy main plus the active compat copy together; verify runtime `plugin=1.3.7`, `version_source=compat_constant`, and compare all artifact hashes. |
| PHASE-1.23 Automation surface gate | `R-PERF-LOADER` + `R-DDV` | Replaced the broad `$_bizcity_admin_ctx` Automation bootstrap gate with an explicit resolver for `bizcity-automation` admin, `bizcity-automation/v1` REST, `/flow`, webhook, cron and CLI surfaces. Unconditional cron schedule-name registration remains intact. | Implemented locally / runtime evidence pending | Recapture Diagnostics/unrelated admin versus Automation page/REST/webhook/cron and verify the `core:automation` pre-plugin bucket drops only on unrelated surfaces. |
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
| Facebook widget option scan | `R-PERF.5` + `R-CACHE` | `BizCity_FB_Chat_Widget::get_any_enabled()` no longer scans the options table on every `wp_footer`; it uses blog/database-scoped object + transient cache and invalidates after REST save. | Fixed locally | Confirm the `LIKE 'bizcity_cg_fb_widget_%'` query appears only on cold-cache rebuild. |
| Facebook widget exact option index | `R-PERF.5` + `R-CACHE` | Added autoloaded `bizcity_cg_fb_widget_active_page`; normal frontend lookup now uses exact `get_option()` plus one page-specific option read. Prefix `LIKE` scan remains only as migration fallback for legacy sites. | Fixed locally | Save widget settings once or let the first legacy fallback backfill the index, then confirm the `LIKE` query disappears. |
| Diagnostics probe loader | `PHASE-1.23-R-PERF-LOADER` + `R-PERF.5` | Diagnostics probes are queued at bootstrap and loaded once only for the Diagnostics screen, `bizcity-diagnostics/v1` REST namespace, or WP-CLI. Probe IDs, order, interface and registration filter remain unchanged. | Fixed locally | Re-measure unrelated admin pages, Diagnostics screen and Smoke REST after deploy; verify source/deploy tracer parity. |
| Loader Hook Observability Panel | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-HOOK` | Added bounded lifecycle snapshots for `plugins_loaded`, `init`, `rest_api_init`, `current_screen`, and `admin_footer`, with normalized callback owner, source group, source-group aggregate and memory delta. `admin_footer` capture now runs before panel render. Added Diagnostics UI injection and admin-only `bizcity-diagnostics/v1/loader/hooks` endpoint. | Fixed locally | Deploy and compare phase deltas against the memory trace; keep caps if panel overhead is measurable. |
| Query Monitor loader integration | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-QM` | Added lazy QM collector `bizcity_loader`, HTML `BizCity Loader` panel, compact REST/AJAX `X-QM-bizcity_loader-*` headers, stage markers, required `qm/output/menus` registration, and bounded admin-default JSONL export for agent/debugger analysis. | Fixed locally | Deploy the updated collector/output/integration, reload OPcache/PHP-FPM, then inspect `qm-loader-snapshots-YYYY-MM-DD.jsonl` from an admin request. |
| Query Monitor loader integration | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-QM` | Added lazy QM collector `bizcity_loader`, HTML `BizCity Loader` panel, compact REST/AJAX headers, stage markers, menu registration, and opt-in JSONL export. Trace UI now separates new-file module buckets from callback source groups, shows top buckets only, and preserves total-vs-sampled callback counts. | Fixed locally | Deploy the updated panel/collector, run a probe request, then use new-file buckets at `init` and `rest_api_init` as the primary root-cause signal. |
| Composer preload on TwinChat shell | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-COMPOSER` | Composer `vendor/autoload.php` for FPDI/FPDF is no longer loaded at file scope on the TwinChat admin shell or ordinary HTML. It remains enabled for REST, cron, CLI, and PDF/tool surfaces. | Fixed locally | Re-run the same TwinChat admin request and compare peak memory, included files, and declared classes before removing additional core preload. |
| TwinChat shell runtime graph | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-SHELL` | TwinChat admin shell no longer loads `tools/class-diagnostics.php`, `modules/twinsource/bootstrap.php`, or `core/twinbrain/bootstrap.php`. The shell only renders the iframe; iframe/REST/Diagnostics requests retain their existing loaders. | Fixed locally | Re-run the same `admin.php?page=bizcity-twinchat&plugin=twinchat` probe and compare pre-`plugins_loaded`/peak memory, files, and classes. |
| Early preload trace | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-EARLY` | Added admin-default `pre_plugins_loaded` baseline/delta to the Query Monitor loader panel and JSONL snapshot. This exposes compat/plugin file-scope loading that the previous lifecycle trace could not see. | Fixed locally | Reload an admin request without query flags and compare `pre_plugins_loaded` buckets before/after shell gates. |
| Admin-default loader evidence | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-QM` | Loader baseline, stage markers and JSONL snapshot now run by default for admin users with `manage_options`; `?bizcity_qm_probe=1` remains available for non-admin diagnostic requests. | Fixed locally | Reload an admin request without query flags and inspect the loader panel/JSONL snapshot. |
| Early capability fatal regression | `PHASE-1.23-R-PERF-LOADER` + `PHP74-COMPAT` | Admin-default probe baseline no longer calls `current_user_can()` during mu-plugin/file-scope loading. Capability checks remain deferred to post-loader marker/stage/export paths. This avoids entering WordPress capability resolution before the normal user/capability lifecycle. | Fixed locally | Deploy the integration fix and verify the TwinChat admin URL no longer produces the capabilities.php fatal; confirm JSONL still records admin stages. |
| Bundled CRM/BizCoach shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added entrypoint guards so bundled `bizcity-twin-crm` and `bizcoach-pro` return early on the default TwinChat admin shell, including alternate/regular-plugin load paths. Dedicated CRM (`plugin=crm`), REST, webhook, cron and public surfaces remain allowed. | Fixed locally | Re-run `pre_plugins_loaded` and confirm `bundle:bizcity-twin-crm` / `bundle:bizcoach-pro` new-file buckets disappear from the default shell. |
| Zalo Bot shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added a self-guard to the `bizcity-zalo-bot` entrypoint so a separately active regular-plugin copy cannot preload its bootstrap on the default TwinChat admin shell. Dedicated Zalo admin, REST, webhook, cron and public surfaces remain enabled. | Fixed locally | Reload the same TwinChat shell and confirm `bundle:bizcity-zalo-bot` disappears from `pre_plugins_loaded`. |
| Intent graph on TwinChat shell | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-INTENT` | Fixed the main plugin Intent loader condition so `core/intent/bootstrap.php` is excluded from the TwinChat admin shell. Compat loader already had the shell guard; the main plugin was reintroducing the 64-file graph. REST, webhook, cron, CLI and other admin Intent surfaces remain enabled. | Fixed locally | Re-run `pre_plugins_loaded` and confirm `core:intent` disappears from the default TwinChat shell bucket. |
| Wallet MU runtime split | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-MU` | Wallet MU now skips the TwinChat shell, defers REST controllers to `rest_api_init`, network/admin pages to their menu hooks, and product-credit fields to the product screen. Woo checkout/payment/referral/account/cron hooks remain loaded. | Fixed locally | Compare TwinChat shell and frontend HTML traces; separately verify wallet REST, network admin, product edit, checkout and cron. |
| BizCity Web Odoo admin split | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-MU` | Deferred the two Odoo app-catalog function files from every request to `network_admin_menu`/`admin_menu`; clone, OAuth provisioning, public shortcode and duplicate operations remain in the existing path. | Fixed locally | Compare ordinary frontend/REST/cron traces and verify network/site app-catalog pages. |
| Facebook Bot shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added self-guards to the Facebook Bot entrypoint and alternate bootstrap so the Messenger/Page runtime is not preloaded by the TwinChat shell; webhook, OAuth, REST, cron and dedicated admin remain enabled. | Fixed locally | Confirm `bundle:bizcity-facebook-bot` disappears from `pre_plugins_loaded`. |
| CF7/TTCK shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added exact TwinChat-shell guards to Contact Form 7 and the TTCK Woo payment plugin. Public forms, checkout/payment AJAX, REST, cron and dedicated admin pages remain enabled. | Fixed locally | Confirm `plugin:contact-form-7` and `plugin:thanh-toan-chuyen-khoan` disappear from the shell trace. |
| Doc/Content Creator shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added self-guards to Doc Studio and Content Creator entrypoints so their 17-file runtime graphs are not loaded by the TwinChat shell. `/doc`, `/tool-doc`, `/tool-content-creator`, REST/AJAX, cron, CLI and dedicated admin surfaces remain enabled. | Fixed locally | Confirm `bundle:bizcity-doc` and `bundle:bizcity-content-creator` disappear from `pre_plugins_loaded`. |
| Video Kling shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added self-guards to Video Kling entrypoint and bootstrap so the 21-file video/FFmpeg/TTS graph is not loaded by the TwinChat shell. `/kling-video`, `/video-editor`, REST/AJAX, cron, CLI and dedicated admin surfaces remain enabled. | Fixed locally | Confirm `bundle:bizcity-video-kling` disappears from `pre_plugins_loaded`. |
| Wallet REST partial-deploy guard | `R-GW-8` + `R-PERF-LOADER-MU` | Wallet REST controllers now require readable artifacts and boot only available classes, so a missing `class-plans-proxy-rest.php` cannot turn unrelated Doc/KG/CRM REST requests into HTTP 500. Restore the missing artifact separately to recover the Wallet proxy route. | Fixed locally | Deploy the bootstrap plus missing controller, then retest `/bzdoc/v1/list`, `/bizcity/kg/v1/notebooks`, and `/bizcity-crm/v1/admin-chat-grants/version`. |
| Admin Menu Editor shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Admin Menu Editor now skips only the TwinChat iframe shell before loading its dependency graph; Network Admin and all dedicated WordPress admin pages remain enabled. | Fixed locally | Confirm `plugin:admin-menu-editor` disappears from the TwinChat shell trace and Network Admin still renders normally. |
| Agent Market shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Agent Market now skips only the TwinChat iframe shell before loading its 11-file graph; public marketplace pages, shortcodes, REST/AJAX, cron and Network Admin remain enabled. | Fixed locally | Confirm `plugin:bizcity-agent-market` disappears from the TwinChat shell trace. |
| Login with Google shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Login with Google now skips Composer/plugin runtime only on the TwinChat iframe shell; wp-login, frontend OAuth, callbacks and settings pages remain enabled. | Fixed locally | Confirm `plugin:login-with-google` disappears from the TwinChat shell trace and Google login still works outside it. |
| Page Builder shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Page Builder now skips its 10-file editor/runtime graph only on the TwinChat iframe shell; `/tool-pagebuilder`, REST/AJAX, cron, CLI and dedicated admin pages remain enabled. | Fixed locally | Confirm `bundle:bizcity-pagebuilder` disappears from the TwinChat shell trace. |
| Page Builder/WP Crontrol shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Page Builder and WP Crontrol now skip their editor/cron-admin graphs only on the TwinChat iframe shell; Page Builder routes/REST/AJAX and WP Crontrol admin/cron behavior remain enabled elsewhere. | Fixed locally | Confirm `bundle:bizcity-pagebuilder` and `plugin:wp-crontrol` disappear from the shell trace. |
| Transposh translation shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Transposh and its Language Switcher now skip translation/parser/widget runtime only on the TwinChat iframe shell; frontend translation/rewrite, translation AJAX, cron and dedicated admin settings remain enabled. | Fixed locally | Confirm `plugin:transposh-translation-filter-for-wordpress` and `plugin:language-switcher-for-transposh` disappear from the shell trace. |
| BizCity Web MU shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-MU` | BizCity Web clone/OAuth/app-catalog runtime now skips only the TwinChat iframe shell; frontend shortcodes, clone/AJAX, OAuth provisioning and Network Admin remain enabled. | Fixed locally | Confirm `mu:bizcity-web` disappears from the shell trace and network clone pages still render. |
| OAuth Server shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | OAuth Server now skips its OAuth2/autoprovision graph only on the TwinChat iframe shell; `/oauth`, `/.well-known`, REST bearer authentication, callbacks and OAuth admin pages remain enabled. | Fixed locally | Confirm `plugin:bizgpt-oauth-server-new` disappears from the shell trace and OAuth flows still work outside it. |
| User-role/Admin Bar MU shell split | `R-PERF-LOADER-MU` | User-role repair skips its profile/admin graph on the TwinChat shell. Admin Bar keeps the global `pre_get_blogs_of_user` loop protection but skips custom Recent Sites rendering and user-meta tracking on the shell. | Fixed locally | Confirm residual user-role/Admin Bar callbacks drop while multisite protection remains active. |
| Phone auth/User sync MU shell split | `R-PERF-LOADER-MU` | Phone login/OTP/Woo My Account hooks and BizGPT user/password/global-meta sync hooks now skip only the TwinChat shell; login, registration, profile and multisite sync remain enabled outside it. | Fixed locally | Confirm `mu:bizcity-myaccount-phone.php` and `mu:biz-id.php` residual callbacks drop without affecting auth/profile flows. |
| Legacy Custom Flows shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Deprecated `bizgpt-custom-flows` now skips its handler/shortcode graph only on the TwinChat iframe shell; legacy flow listeners, shortcodes, admin editor and cron remain enabled elsewhere. | Fixed locally | Confirm `plugin:bizgpt-custom-flows` disappears from the shell trace before considering removal/migration. |
| Dino-site plugin shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added exact TwinChat-shell guards to Dino points/order tooling, Product Import/Export for WooCommerce, BizGPT Voucher, and Mabel Wheel. Public/order/checkout/AJAX and dedicated admin behavior remain enabled outside the shell; Mabel file-scope table DDL is skipped on the shell. | Fixed locally | On Dino site, confirm `bizgpt-dino-tichdiem`, `product-import-export-for-woo`, `bizgpt-vi-voucher`, and `mabel-wheel-of-fortune` disappear from `pre_plugins_loaded`. |
| Dino guard placement correction | `R-PERF-LOADER-BUNDLE` + `PHP74-COMPAT` | Corrected a context-placement regression: Mabel guard is now outside `MABEL_WOF_auto_loader()`, and Product Import/Export guard is outside its plugin header comment. The old placement could cause Mabel class-load fatal or leave Product Import guard inert. | Fixed locally | Deploy corrected files and confirm no new Mabel fatal plus Dino shell buckets disappear. |
| Admin Bar shell Quick Tools | `R-PERF-LOADER-MU` | TwinChat shell retains a lightweight Quick Tools menu for TwinChat, Channel Gateway, Automation and Diagnostics. It avoids Recent Sites/user_meta/global wp_blogs work; the full My Sites menu remains unchanged outside the shell. | Fixed locally | Confirm Quick Tools appears in the shell and no user-meta/global-site queries are added. |
| Intent graph on unrelated admin pages | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-INTENT` | Main and both compat loaders now keep Intent only for backend dispatch, Intent-owned admin/AJAX/public surfaces, and explicit Intent/WebChat pipeline actions; ordinary wp-admin pages no longer preload the full graph. | Fixed locally | Measure ordinary wp-admin, TwinChat/TwinBrain shell, Intent admin page, REST/webhook and cron separately; confirm `core:intent` remains present only where required. |
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
