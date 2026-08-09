# BizCity Twin Framework Contract Audit

**Audit date:** 2026-07-30  
**Repository:** `bizcity-twin-ai`  
**Scope:** active documentation, `core/`, and active `plugins/`  
**Exclusions:** every `_archived/`, `_library/`, `node_modules/`, `vendor/`, generated `dist/`/`build/`/`.vite/` tree, and external application source that is not loaded by the WordPress plugin.

## Executive Verdict

**Framework baseline: PARTIAL PASS. Plugin ecosystem: NOT production-ready.**

The public contract catalog, JSON Schemas, fixtures, contract test runner, manifest validator, capability guard, error payload helper, channel logger, and diagnostic probe framework are present. The central framework boundary is therefore real and testable.

The ecosystem that consumes the framework is not yet uniformly compliant. Active code still contains:

1. raw channel business listeners and direct `waic_twf_process_flow` bridges that bypass the canonical normalized channel path;
2. remaining direct provider calls and raw gateway-key reads outside the managed client boundary;
3. many user-facing `wp_send_json_error()` responses without the four-field error envelope;
4. plugin-owned DDL/self-healing installers that are not proven in this repository audit to be registered through the central schema/changelog pipeline;
5. legacy cron and mutation paths without complete runtime evidence.

This audit is an assessment artifact. It does not mark a plugin compliant merely because a rule is documented or a central helper exists.

## Audit Method

The review used:

- framework contract documents under `docs/contracts/`;
- Tier-0/Tier-1 rules under `docs/rules/` and the repository Copilot instructions;
- public JSON catalog, schemas, fixtures, and contract tests under `core/twin-core/contracts/`;
- active probes under `core/diagnostics/includes/probes/`;
- active PHP call sites in `core/` and `plugins/`;
- direct reads of representative loaders, adapters, REST controllers, installers, and channel listeners.

## Hybrid Test Ownership (Adopted)

Framework contract verification is split into two ownership layers:

1. **CI-owned deterministic gates**
	- `core/twin-core/contracts/tests/run-contract-tests.mjs`
	- `bin/framework-contract-audit.mjs`
	- `bin/validate-plugin-contract-registry.mjs`
	- `bin/validate-sdk-release.mjs`
	- `.github/workflows/ci.yml`
2. **Diagnostics probe-owned runtime evidence**
	- `core/diagnostics/includes/probes/class-probe-framework-production-contract.php`

Ownership rule: deterministic invariants remain in CI; runtime lifecycle checks
that require WordPress load-order, multisite context, options, and hooks remain
in diagnostics probes.

Search results were manually classified. A text match in a document, probe, backup file, vendor tree, or archived tree is not treated as a runtime violation. Conversely, an active caller is not considered compliant merely because a compliant reference implementation exists elsewhere.

## Contract Sources

| Source | Purpose | Audit result |
|---|---|---|
| `docs/contracts/PUBLIC-CONTRACTS-v1.md` | Stable public API list and compatibility policy | PASS |
| `docs/contracts/CONTRACT-TESTING-v1.md` | Required contract, security, reliability, and CI layers | PASS as specification; ecosystem adoption PARTIAL |
| `docs/contracts/CAPABILITY-SECURITY-v1.md` | Permissions, consent, approval, network, upload, and secret controls | PASS as baseline; plugin adoption PARTIAL |
| `docs/contracts/RUNTIME-PRODUCTION-CONTRACT-v1.md` | Idempotency, retry, DLQ, trace, SLO requirements | PARTIAL; migration section correctly admits remaining work |
| `core/twin-core/contracts/schema/public/v1/contract-catalog.json` | Machine-readable public catalog | PASS |
| `core/twin-core/contracts/schema/public/v1/*.schema.json` | Eleven public schemas | PASS for shape/catalog coverage |
| `core/twin-core/contracts/schema/public/v1/fixtures/` | Valid and invalid fixtures | PASS; covered by contract runner |
| `core/twin-core/contracts/schema/manifest.schema.json` | Extension manifest security contract | PASS as schema; only reference extension proves end-to-end adoption |
| `core/diagnostics/includes/probes/` | Disk/Loader/Runtime evidence | Strong baseline; plugin contract coverage PARTIAL |
| `core/helper/includes/class-bizcity-error-payload.php` | Canonical error payload builder | PRESENT and loaded; migration coverage PARTIAL |
| `docs/roadmaps/PHASE-0-DOC-CHANNEL-LISTENING.md` | Canonical channel listening reference | Accurate target contract; active raw bypasses remain |
| `docs/rules/PHASE-0-RULE-CHANNEL-UNIFY.md` | Mandatory single inbound path | Accurate rule; active code violates it in several adapters/listeners |
| `docs/roadmaps/PHASE-0-CONTEXT-CLEANUP.md` | Twin context/resolver status and migration | Useful architecture reference; snapshot remains disabled and should not be read as fully live |

## Public Contract Assessment

| Contract | Framework producer/consumer evidence | Plugin adoption evidence | Status |
|---|---|---|---|
| `event-envelope` | Event stream classes, event taxonomy, and fixtures exist | Plugin-level producer/consumer inventory is incomplete | PARTIAL |
| `tool-io-envelope` | `BizCity_Twin_Tool_Registry` and tool interface enforce the boundary | Reference extension proves discovery; most feature plugins do not expose a validated manifest/tool contract | PASS / adoption PARTIAL |
| `mutation-contract` | `BizCity_Twin_Mutation_Guard`; workflow CRUD now has preflight/audit | Automation REST is only a partial migration; channel/content/other controllers remain | PARTIAL |
| `citation-pack` | Citation generator/validator and TwinBrain probes exist | Not every plugin output path proves citation provenance | PARTIAL |
| `error-envelope` | Canonical helper, schema, and `class-probe-error-ux.php` exist | Active REST/AJAX code still returns raw strings or `{message}` only | PARTIAL, release blocker |
| `sse-events` | SSE writer/parser and contract fixtures exist | Consumer coverage is concentrated in Twin UI surfaces | PASS / adoption PARTIAL |
| `permission-scopes` | Manifest schema, consent page, capability guard, and reference extension exist | Active plugins rarely register a validated framework manifest | PASS / adoption PARTIAL |
| `workflow-json` | Automation templates/seeder/REST and schema exist | Template lifecycle is documented; plugin-created workflow paths need per-plugin evidence | PASS / adoption PARTIAL |
| `kg-adapter-payload` | KG adapter contracts and probes exist | External/plugin adapters are not all proven against the schema | PARTIAL |
| `channel-payload` | UCL and normalized envelope contract exist | Raw-hook consumers and legacy WAIC paths remain active | PARTIAL, release blocker |
| `runtime-execution-policy` | Reliability policy, tool boundary, SLO store, and reliable HTTP adapter exist | Scheduler/channel/gateway-client/legacy-controller migration is incomplete | PARTIAL, release blocker |

## Rule Compliance Matrix

| Rule | Evidence | Finding | Status |
|---|---|---|---|
| `R-CH-UNI` | `PHASE-0-RULE-CHANNEL-UNIFY.md`, UCL, normalized hook | Personal listener and Zalo command router now consume normalized envelopes; Zalo Guru/notebook/link/bridge and Facebook active paths still emit/consume legacy raw hooks/WAIC | FAIL |
| `R-CH-NS` | Channel gateway REST classes and namespace docs | Audited channel routes use `bizcity-channel/v1`; requires an automated route scanner for complete proof | PASS with evidence gap |
| `R-CH-IDMEM` | CRM identity resolver, normalized envelope, channel identity probe | Canonical tuple exists, but raw listeners and legacy adapters make continuity hard to prove end-to-end | PARTIAL |
| `R-ZONE` | Zone rule, UCL guards, CRM normalized subscriber | Zone guards exist, but raw Zone 2 listeners and compatibility bridges remain | PARTIAL |
| `R-CH-FILE-LOG` | `BizCity_Channel_File_Logger`, CG debug logger, channel bootstrap ordering | Central file-first logger exists; direct legacy paths need coverage assertions | PASS baseline / PARTIAL coverage |
| `R-SCH-REPLY` | `build_event_metadata()`, scheduler notifier, action references | Canonical inbound metadata forwarding is implemented in core | PASS |
| `R-GW-8` | `BizCity_LLM_Client`, client proxy docs | `plugins/bizcity-pagebuilder/includes/class-rest-api.php` calls OpenAI directly; other active direct provider paths remain | FAIL for affected plugins |
| `R-1API-AUTH` | LLM client getter and gateway rules | `class-remote-catalog.php`, pagebuilder, video/image paths and settings/callers need full credential-boundary migration | FAIL for affected callers |
| `R-CACHE` | Cache helper, cache registry, selected manager contracts | Central infrastructure is present, but active plugin managers/installers were not all proven to declare and invalidate contracts | PARTIAL |
| `R-CR` | Schema/rewrite registries and registry probes | Core registry exists; plugin-owned installers require per-table registration evidence | PARTIAL |
| `R-DCL` | Changelog JSON files and validator | Core changelog pipeline exists; active plugin DDL is visible without a complete cross-check to module changelog entries | PARTIAL |
| `R-DDV` | 141 active probe files were inventoried; framework smoke exists | Probe coverage is strong for core/roadmap features, not every plugin contract; QR/vendor claims require explicit probe ownership | PARTIAL |
| `R-CRON-META` | Cron manager, notes, event evidence | Many core paths call `note()`/`note_event()`, but direct schedule/fallback paths remain in knowledge, intent, scheduler, content, plugin installers, and one-shot jobs | PARTIAL |
| `R-ERROR-UX` | Error helper and error UX probe are present | Numerous active core/plugin AJAX endpoints still return raw `wp_send_json_error()` strings or message-only arrays | FAIL for ecosystem |
| `R-MSDB` | Multisite/shard rule | This repository is the standalone client; Hub routing is out of scope here. Client code must still avoid assuming one DB and must use `$wpdb->prefix` for tenant data | N/A for Hub routing / review for DDL |
| `R-PERF` | Core gates and lazy-load guidance | Active plugin bootstraps/loaders need a full context-gate review; not proven globally | PARTIAL |

## Plugin Contract Matrix

Status means the plugin's interaction with the framework, not the plugin's product functionality.

| Active package | Main framework surfaces | Evidence | Status | Required action |
|---|---|---|---|---|
| `plugins/bizcity-twin-crm` | `bizcity_channel_normalized`, outbound log, CRM adapters, identity resolver | Normalized subscriber exists in `bootstrap.php`; raw compatibility adapters and legacy trigger references remain | PARTIAL | Make normalized envelope the only business ingest path; retain raw hooks only inside UCL/adapter boundary |
| `plugins/bizcity-personal` | Zalo Bot Zone 2, scheduler, channel sender | Listener now subscribes to `bizcity_channel_normalized` and reuses UCL `chat_id`; scheduler/error probe coverage remains | PARTIAL | Migrate user-facing errors and add scheduler/channel runtime evidence |
| `plugins/bizcity-zalo-bot` | Zalo webhook, UCL bridge, gateway sender, automation | Guru/notebook/link listeners and direct WAIC bridge remain in active code; command router now consumes normalized envelope | FAIL | Migrate remaining business listeners to normalized envelope; isolate compatibility bridge and add duplicate/reply safety test |
| `plugins/bizcity-facebook-bot` | Facebook webhook, comments/DM, UCL, sender | Active webhook handler still fires legacy WAIC flow directly | FAIL | Route business dispatch through normalized envelope; preserve comment context in `raw`/`extra` |
| `plugins/bizcity-pagebuilder` | REST, image generation, LLM gateway, mutation guard | Image generation uses `BizCity_LLM_Client`; save/delete/publish now use mutation preflight and outcome evidence; other REST paths still need migration/probe coverage | PARTIAL | Complete error envelope migration and add PageBuilder runtime probe |
| `plugins/bizcity-video-kling` | Video jobs, async queue, LLM generation | Active direct OpenAI call and raw `bizcity_llm_api_key` fallback were found | FAIL | Move generation to approved client wrapper; add runtime retry/idempotency and R-ERROR-UX payloads |
| `plugins/bizcity-tool-image` | Image tools, templates, QR Studio, external stock API | LLM path documents gateway use, but stock/template REST has direct HTTP and message-only errors | PARTIAL | Register external resource contract, use reliable HTTP/security policy, standardize errors, add QR REST probe |
| `plugins/bizcity-doc` | LLM client, document REST, image pipeline, external content fetch | LLM gateway usage and canonical getter are present; external fetch/error paths are not uniformly framework-wrapped | PARTIAL | Audit each external call for SSRF, reliability, and error envelope; add document contract probe |
| `plugins/bizcoach-pro` | Astro/profile REST, membership/entitlement, LLM client | Deprecated Astro settings no longer persist raw gateway credentials; many legacy AJAX endpoints still use raw error payloads and current-blog credential scope needs broader evidence | PARTIAL | Migrate user-facing endpoints and prove current-blog credential scope |
| `plugins/bizcity-zalo-bizcity` | Zalo sidecar/legacy gateway bridge | Legacy bridge and self-healing/table code are active | PARTIAL | Document whether it is an adapter-only compatibility layer; prohibit business logic bypass |
| `plugins/bizgpt-tool-google` | Google OAuth, account storage, cron | Installer creates tables; no complete public manifest/DDL/DDV evidence in this audit | REVIEW | Add schema changelog/registry/diagnostic evidence and secret reference contract |
| `plugins/bizcity-content-creator` | REST/AJAX, templates, installer, LLM client | Cache contract exists in selected manager; active AJAX errors remain message-only | PARTIAL | Complete error and DDL/registry evidence |
| `examples/bizcity-reference-plugin` | Manifest, eight capability groups, framework registry | Manifest validator and WordPress smoke cover discovery | PASS reference only |

## Module Contract Matrix

Modules are evaluated against the same core contract boundary as plugins. A module
is not compliant merely because its bootstrap loads; it must prove its declared
surfaces through CI gates and runtime diagnostics evidence.

| Active module | Main framework surfaces | Current assessment | Required action |
|---|---|---|---|
| `modules/twinchat` | Runtime client, identity scope, error UX, diagnostics | PARTIAL: central TwinChat surface exists, but module-wide manifest/probe coverage is incomplete | Add runtime probe and complete gateway/error evidence |
| `modules/twinweb` | Public identity, ownership guard, same-origin proxy | PARTIAL: identity-first and proxy architecture are documented/implemented, end-to-end runtime evidence remains incomplete | Add ownership/proxy probe and manifest record |
| `modules/webchat` | Normalized channel, identity, SSE, error envelope | PARTIAL: public webchat surface is active, but channel continuity and error adoption need proof | Add channel identity/runtime probe and migrate remaining errors |
| `modules/twinsearch` | Search wrapper, reliable HTTP, search contract | REVIEW: bootstrap exists, complete active surface inventory and runtime evidence are not yet established | Verify wrapper boundary and add reliable HTTP probe |
| `modules/twinshell` | Capability registry, identity, error envelope | PARTIAL: shell surface is active, but capability/diagnostics adoption is not fully recorded | Add manifest/capability probe and error contract coverage |
| `modules/twinsource` | Source contract, ownership, cache | REVIEW: bootstrap exists, source ownership and cache evidence need a focused review | Register source contract and add ownership/cache probe |

## Document Consistency Findings

### 1. Channel listening documentation is a target contract, not current reality

`PHASE-0-DOC-CHANNEL-LISTENING.md` correctly states that business consumers should subscribe to `bizcity_channel_normalized`. The active code still has raw business listeners in Personal and Zalo Bot, plus legacy WAIC dispatch in Zalo/Facebook paths. The document should be updated with a clearly visible **current migration status** section so developers do not mistake the target architecture for complete implementation.

### 2. Context cleanup document contains mixed historical/runtime status

`PHASE-0-CONTEXT-CLEANUP.md` says Resolver is live while Snapshot is code-complete but disabled. That distinction is valid, but it should be repeated near the top of the document and in the framework readiness index. Snapshot fields such as `memory_refs` and journeys must not be treated as production guarantees by plugin authors.

### 3. Runtime contract correctly admits incomplete migration

`RUNTIME-PRODUCTION-CONTRACT-v1.md` explicitly says reliability is currently enforced at the Twin tool boundary and that scheduler/channel/gateway-client/legacy-controller migration remains. This is consistent with the audit and should remain the canonical caveat until the matrix turns green.

### 4. Error UX documentation is stronger than active adoption

The helper and probe exist at `core/helper/includes/class-bizcity-error-payload.php` and `core/diagnostics/includes/probes/class-probe-error-ux.php`. The remaining problem is not missing infrastructure; it is active caller migration. Any report claiming the class is missing is incorrect.

### 5. Public SDK contract is not the same as plugin contract adoption

The reference extension proves the framework registry and manifest path. It does not prove that every bundled plugin has a manifest, permission declaration, versioned contract, compatibility range, or runtime probe. The release checklist must therefore include a per-plugin contract manifest or an explicit `internal/legacy adapter` classification.

## Release Blockers

The following blockers prevent a system-wide production-ready claim:

1. **Channel bypass:** raw inbound business listeners and direct legacy WAIC dispatch remain active.
2. **Credential boundary bypass:** active direct provider calls/options exist outside the managed client boundary.
3. **Error envelope migration incomplete:** active user-facing AJAX/REST callers return message-only or string errors.
4. **DDL governance not proven for every active plugin:** installers and self-healing table paths need changelog, schema registry, provisioning, and DDV evidence.
5. **Runtime reliability migration incomplete:** scheduler, channel, gateway-client, and remaining mutation controllers need shared adapter coverage and persistent evidence.
6. **Plugin manifest adoption incomplete:** only the reference extension is currently demonstrated through the public framework discovery contract.

## Recommended Remediation Order

### P0: Stop new bypasses

- Add CI grep/AST checks for active direct `waic_twf_process_flow` outside UCL compatibility code.
- Add CI check for direct provider calls and raw gateway option access outside approved client/settings boundaries.
- Add CI check for `wp_send_json_error()` in user-facing active endpoints unless the payload is produced by `BizCity_Error_Payload`.

### P1: Close runtime boundaries

- Migrate Personal/Zalo/Facebook business listeners to normalized envelopes.
- Migrate Pagebuilder and Video Kling provider calls to the managed client/catalog path.
- Migrate remaining workflow/channel/content mutation controllers to mutation preflight, idempotency, replay, and outcome audit.
- Add a channel identity continuity probe covering `platform/account_id/user_id/chat_id` through CRM and reply.

### P2: Close plugin governance

For each active plugin that creates tables or exposes REST/AJAX:

- declare ownership and storage tier;
- add/update the module changelog before DDL changes;
- register schemas in the central registry;
- document cache contract and invalidation;
- add manifest/permission contract or explicitly mark the package as a legacy adapter;
- add Disk/Loader/Runtime diagnostic evidence;
- migrate user-visible errors to the canonical envelope.

### P3: Documentation maintenance

- Add a generated framework contract index to `docs/`.
- Add `current implementation status` blocks to channel/context/runtime roadmap documents.
- Link each plugin's contract record to its manifest, bootstrap, REST routes, DDL/changelog, probes, and release status.
- Re-run this audit after each migration wave and before release tags.

## Required Definition of Done

A plugin is **framework-compliant** only when all applicable rows are `PASS`:

- public contract and compatibility version declared;
- manifest/permission/secret references validated, or plugin explicitly classified as an internal legacy adapter;
- loader/bootstrap order proven;
- REST namespace and error envelope proven;
- channel consumers use normalized identity where applicable;
- external calls use approved client/security/reliability boundaries;
- DDL follows changelog/schema registry/provisioner rules;
- cache contract and invalidation are documented where DB reads exist;
- mutations carry trace and idempotency and produce outcome evidence;
- at least one runtime probe returns `PASS` with Disk/Loader/Runtime evidence.

**Current overall status:** `NOT PRODUCTION-READY` until the release blockers above are closed and the WordPress CI matrix runs the framework smoke successfully.
