# Framework Contract Inventory v1

> Status: 🟡 Inventory baseline for CLI contract discovery
> Opened: 2026-08-27 · Owner: Johnny Chu
> Scope: active `core/`, `modules/`, and `plugins/` under `bizcity-twin-ai`
> Exclusions: every `_archived/`, backup, vendor, generated, and library tree
> Cross-reference: [PHASE-1.31 WP-CLI Command Family](../roadmaps/PHASE-1.31-WP-CLI-BIZCITY-COMMAND-FAMILY.md), [PUBLIC-CONTRACTS-v1.md](PUBLIC-CONTRACTS-v1.md), [PLUGIN-CONTRACT-REGISTRY-v1.json](PLUGIN-CONTRACT-REGISTRY-v1.json)
> **Direction gate:** [PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md](../rules/PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md) — every inventory row must identify its place in the horizontal Channel Gateway, vertical Brain Mode/extension, or KG Graph evidence spine.
> **Context spine:** [R-CONTEXT-BANK](../rules/PHASE-0-RULE-CONTEXT-BANK.md) — enterprise stream, rollup, memory/rule link, KG promotion and MPR retrieval contracts must converge on one corpus/pointer spine.

This is the first inventory for a future `wp bizcity contracts ...` command. It
is deliberately an inventory, not a claim that every listed surface is already
public, versioned, or runtime-proven. The CLI must report those distinctions.

The inventory must also preserve the Enterprise Brain ownership test: a
contract belongs to one shared brain spine and one owner. A new contract that
introduces a parallel brain, siloed enterprise data path, ungoverned MCP/tool
boundary, or bypasses Channel Gateway/KG evidence is a design failure even if
its static schema is valid.

---

## 1. What Is A Framework Contract?

A **framework contract** is a stable agreement between an owner and one or more
producers/consumers. It describes what another part of the system may rely on,
how compatibility is maintained, and how a failure is reported.

A contract is strong enough for CLI validation when it has these fields:

| Field | Meaning | Example |
|---|---|---|
| `contract_id` | Stable machine identifier; do not derive it from a filename at runtime | `tool-io-envelope` |
| `owner` | The one subsystem responsible for compatibility and migration | `core/twin-core` |
| `artifact` | Interface, JSON Schema, registry, route, hook, or documented payload | `contracts/schema/public/v1/tool-io-envelope.schema.json` |
| `version` | Contract/schema version and compatibility policy | `1.0.0`, producer/consumer `1.x` |
| `scope` | `public`, `framework_internal`, `domain`, `legacy_adapter`, or `runtime_evidence` | `public` |
| `producer` | Code that creates the payload or registration | Tool runner |
| `consumer` | Code that reads or executes it | TwinBrain planner |
| `validator` | Deterministic or runtime check that can produce evidence | Contract fixture + Diagnostics probe |
| `failure_policy` | Fail closed, degrade, skip, retry, or report-only behavior | Error envelope + `help_code` |

A filename containing `contract`, `schema`, `interface`, `registry`, `route`, or
`hook` is only a **candidate artifact**. It becomes a CLI-visible contract row
only after the owner, scope, version, producer, consumer, and validator are
identified.

### 1.1 Contract classes

| Class | What CLI can check without WordPress | What still needs Runtime evidence |
|---|---|---|
| `public_typed` | Interface exists, required methods, PHP compatibility, implementation references | Class loads and registration/behavior works |
| `public_schema` | JSON parses, catalog entry exists, valid/invalid fixtures behave correctly | The live producer and consumer use the same schema |
| `framework_internal` | Artifact and registration are present | Lifecycle/order, ownership, and compatibility behavior |
| `domain_runtime` | Adapter/registry/route declarations and documented fields | Actual WordPress request, DB/shard, side effect, and error path |
| `package_adoption` | Manifest, bootstrap, declared capability surface, static rules | Package-specific runtime probe and ownership/permission proof |
| `legacy_adapter` | Adapter boundary and sunset metadata exist | Legacy path is isolated and does not become a second owner |
| `evidence_contract` | Probe ID, verdict shape, fixture, report fields | Disk/Loader/Runtime evidence from a real site |

The CLI must never turn a static `public_schema` PASS into a package Runtime
PASS. This follows R-DDV and PHASE-1.28 §4.

---

## 2. Canonical Framework Contracts: Where They Live

### 2.1 Typed PHP contracts

The canonical extension interfaces live here:

- [core/twin-core/contracts/framework-contracts.php](../../core/twin-core/contracts/framework-contracts.php)
  contains `BizCity_Module_Interface`, `BizCity_Phone_Normalizer_Interface`,
  `BizCity_LLM_Client_Interface`, `BizCity_Tool_Interface`,
  `BizCity_Agent_Interface`, `BizCity_Capability_Guard_Interface`,
  `BizCity_Runtime_Policy_Interface`, and
  `BizCity_Admin_Navigation_Provider_Interface`.
- [core/twin-core/contracts/content-contracts.php](../../core/twin-core/contracts/content-contracts.php)
  contains the opt-in content interfaces:
  `BizCity_Skill_Interface`, `BizCity_Channel_Adapter_Interface`,
  `BizCity_KG_Source_Adapter_Interface`, `BizCity_Workflow_Block_Interface`,
  `BizCity_Persona_Provider_Interface`, and
  `BizCity_Output_Renderer_Interface`.
- [core/twin-core/contracts/class-module-registry.php](../../core/twin-core/contracts/class-module-registry.php)
  is the lifecycle/registration owner for module contracts.
- [core/twin-core/includes/class-twin-content-registry.php](../../core/twin-core/includes/class-twin-content-registry.php)
  is the typed content registration boundary.
- [core/twin-core/contracts/class-admin-navigation-registry.php](../../core/twin-core/contracts/class-admin-navigation-registry.php)
  owns navigation provider discovery and normalization.

These 14 interfaces are the **framework extension contract layer**. They are
not the same thing as every internal interface in `core/`.

### 2.2 JSON Schema and public payload contracts

The public machine-readable catalog is:

- [core/twin-core/contracts/schema/public/v1/contract-catalog.json](../../core/twin-core/contracts/schema/public/v1/contract-catalog.json)
- [core/twin-core/contracts/schema/manifest.schema.json](../../core/twin-core/contracts/schema/manifest.schema.json)

The current public catalog contains 15 entries:

| Contract ID | Schema | Primary concern |
|---|---|---|
| `event-envelope` | `event-envelope.schema.json` | Event Stream identity and event payload |
| `tool-io-envelope` | `tool-io-envelope.schema.json` | Tool input/output envelope |
| `mutation-contract` | `mutation-contract.schema.json` | Permission, trace, idempotency, outcome |
| `citation-pack` | `citation-pack.schema.json` | Grounded source/citation output |
| `error-envelope` | `error-envelope.schema.json` | `code`, Vietnamese `message`, `hint`, `help_code` |
| `sse-events` | `sse-events.schema.json` | Streaming event protocol |
| `permission-scopes` | `permission-scopes.schema.json` | Scope and capability declarations |
| `workflow-json` | `workflow-json.schema.json` | Workflow graph payload |
| `kg-adapter-payload` | `kg-adapter-payload.schema.json` | KG source adapter output |
| `channel-payload` | `channel-payload.schema.json` | Normalized channel identity/event payload |
| `runtime-execution-policy` | `runtime-execution-policy.schema.json` | Retry, idempotency, DLQ, lock policy |
| `admin-navigation` | `admin-navigation.schema.json` | Central navigation metadata |
| `diagnostics-verdict` | `diagnostics-verdict.schema.json` | `pass/warn/fail/skip`, evidence, exit code |
| `zalo-personal-bridge` | `zalo-personal-bridge.schema.json` | Zalo Personal bridge mapping and admission |
| `channel-diagnostics-record` | `channel-diagnostics-record.schema.json` | Account-scoped channel operational evidence and Context Bank pipeline status |

Each catalog row is a separate CLI check target. A schema file without a catalog
row is `unregistered`, not a public contract.

### 2.3 Evidence and governance registries

These are contract registries even though they are not PHP interfaces:

- [docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json](PLUGIN-CONTRACT-REGISTRY-v1.json)
  records package adoption status and required surfaces.
- [core/helper/class-bizcity-log-contract-registry.php](../../core/helper/class-bizcity-log-contract-registry.php)
  records JSONL log ownership, folder, retention, and indexing contracts.
- `core/diagnostics` owns the probe interface, probe catalog, last-result
  evidence, and R-DDV Disk/Loader/Runtime reporting.
- `core/runtime` and `core/cron` own loader/runtime and per-run operational
  evidence contracts; their output must still use the diagnostics verdict
  contract where it is exposed to CLI/CI.

### 2.4 Context Bank contract family

R-CONTEXT-BANK defines four Context Bank contracts for the shared enterprise
context corpus. Diagnostics evidence reuses the existing `diagnostics-verdict`
contract instead of creating a fifth Context Bank verdict shape:

| Candidate ID | Intended class | Owner | Status required from CLI |
|---|---|---|---|
| `context-bank-record` | `public_schema` | Twin AI Core / Context Bank | `schema_fixture_pass`, catalog `pending`, Runtime `pending` |
| `context-rollup-definition` | `public_schema` + `domain_runtime` | Context Bank Rollup Registry | `schema_fixture_pass`, catalog `pending`, Runtime `pending` |
| `context-relation` | `public_schema` + `domain_runtime` | Context Bank correlation owner | `schema_fixture_pass`, catalog `pending`, Runtime `pending` |
| `context-retrieval-pack` | `public_schema` + `domain_runtime` | Context Bank Search / TwinBrain source layer | `schema_fixture_pass`, catalog `pending`, Runtime `pending` |

These four rows are now schema-backed design contracts. Their static schema and
fixture status is separate from producer/consumer and Disk/Loader/Runtime
evidence; static PASS must not promote the Context Bank runtime package to PASS.
Discovery of the rule document alone remains insufficient for Runtime PASS.

Memory boundary: `bizcity_memory_users`, `bizcity_memory_episodic`,
`bizcity_memory_rolling`, `bizcity_memory_session`, `bizcity_memory_notes`,
`bizcity_memory_specs`, `bizcity_memory_logs`, `bizcity_memory_research` and
the legacy unified `bizcity_memory` are not Context Bank payload contracts.
New memory/rule payloads must use a registered encrypted JSONL/business
filestore contract; Context Bank stores only references, scope, provenance,
hashes and verified file-pointer metadata. Legacy SQL rows remain migration
debt and must not be used as a canonical new-write fallback.

The future collector must also report the boundary between:

- Context Bank business payload files and `bizcity_context_bank` pointer rows;
- operational JSONL and `bizcity_log_index`;
- Twin Event Stream canonical runtime history;
- KG-Hub entities/relations/citations;
- `core/skills` and TwinChat as read-through UI consumers.

---

## 3. Active `core/` Contract Inventory

This table covers every active top-level `core/` package. “Candidate” means the
package has a meaningful boundary for CLI inspection but does not necessarily
have a public typed contract yet.

| Owner path | Contract surface | Artifact/evidence to inspect | Class | CLI check |
|---|---|---|---|---|
| `core/twin-core` | Module, Tool, Agent, Skill, Channel, KG source, Workflow block, Persona, Renderer, LLM, Guard, Runtime Policy, Navigation | `contracts/*.php`, public schemas, content registry | `public_typed` + `public_schema` | Interface method/implementation, catalog/schema/fixtures, registry |
| `core/agents` | Agent/tool compatibility and execution boundary | `class-twin-tool.php`, agent runtime callers | `framework_internal` | Tool contract reference and runtime registration |
| `core/automation` | Workflow block and side-effect policy | `includes/blocks/interface-block.php`, `class-automation-side-effect-contract.php`, workflow JSON | `domain_runtime` | Block metadata, input/output, side-effect and owner checks |
| `core/bizcity-llm` | Managed gateway client, usage, reliable HTTP | `class-llm-client.php`, client wrappers, usage client | `domain_runtime` | Gateway wrapper/key boundary, trace/retry/degraded response |
| `core/bizcity-market` | Market/catalog capability and navigation boundary | Market registry/bootstrap contracts | `domain_runtime` | Registry presence, capability ownership, route contract |
| `core/channel-gateway` | Channel adapter, magic link, normalized identity, sender | `interface-channel-adapter.php`, `interface-channel-magic-link-capable.php`, channel contract docs | `domain_runtime` | Identity tuple, zone, normalized envelope, sender/error contract |
| `core/cli` | WP-CLI command family and command output | `class-bizcity-framework-cli.php`, PHASE-1.31 | `evidence_contract` | Command catalog, args, JSON verdict, exit code |
| `core/content-ops` | Content scheduling/publishing integration | Scheduler class and content hooks | `domain_runtime` | Owner, schedule, mutation/idempotency and completion metadata |
| `core/cron` | Cron registry, run metadata, retry/lock ownership | `class-cron-manager.php`, R-CRON-META | `domain_runtime` | Registered job, next/last run, error bucket, lock/meta evidence |
| `core/diagnostics` | Probe lifecycle and verdict evidence | `interface-diagnostics-probe.php`, Smoke Runner, public verdict schema | `evidence_contract` | Catalog, precondition, steps, cleanup, status normalization |
| `core/helper` | Safe Loader, Error UX, Codec, Cache, JSONL Log Contract | helper classes/docs and log registry | `framework_internal` + `evidence_contract` | Artifact loading, error fields, codec round-trip, cache/log registry |
| `core/helper-legacy` | Compatibility helpers | Legacy helper files and callers | `legacy_adapter` | Inventory only, no new public contract; owner/sunset required |
| `core/intent` | Provider, planner, tool index, intent payload | `data-contracts/intent-contract-v1.json`, provider registry, Tool Index | `domain_runtime` | Provider/tool registration, schema, index state, plugin owner |
| `core/knowledge` | KG facade, source adapters, graph/RAG, filestore | `interface-source-adapter.php`, KG adapter registry, KG schemas | `domain_runtime` | Adapter capability, payload schema, shard/tenant boundary |
| `core/mcp` | OAuth consent, token identity and MCP tool boundary | MCP OAuth docs, routes, scope handling | `domain_runtime` | PKCE, redirect, scope intersection, blog/key identity |
| `core/membership` | Local capability plan, entitlement, usage/seat policy | Membership services and entitlement contracts | `domain_runtime` | Plan/seat/usage ownership and key/member scope |
| `core/memory` | Unified memory, recall parity, mutation audit | Unified probes, memory rules, log contract | `domain_runtime` + `evidence_contract` | Dual-write/recall parity, owner scope, drift and D7 gates |
| `core/persona` | Persona provider/tool bridge | Persona providers and tool adapter | `domain_runtime` | Provider metadata, identity scope, tool contract |
| `core/research` | Research/search orchestration boundary | Research clients and probe contracts | `domain_runtime` | Gateway wrapper, citations, degraded/error behavior |
| `core/runtime` | Session, runtime lifecycle, loader ownership | `interface-twin-session.php`, runtime policy/ownership classes | `framework_internal` | Session method compatibility, lifecycle and owner trace |
| `core/scheduler` | Event adapter and completion metadata | `interface-scheduler-event-adapter.php`, notifier | `domain_runtime` | Event type schema, inbound provenance, completion delivery |
| `core/skills` | Skill registration and skill execution metadata | Skill registry/bootstrap and skill contracts | `domain_runtime` | Skill ID, metadata, sub-tools, provider ownership |
| `core/tools` | Legacy diagnostics/tool integration | Tool bootstrap and compatibility callers | `legacy_adapter` | Discover/report only; no second tool owner |
| `core/twinbrain` | Goal contracts, ReAct/runtime orchestration, source pack | Goal contract store, TwinBrain probes | `domain_runtime` | Goal schema, tool trace, retrieval pack, owner continuity |
| `core/twinsearch` | Shared search/input gate | Search core and REST contract | `domain_runtime` | Search wrapper, query/result shape, gateway/degraded path |

### 3.1 Important duplicate-looking contracts in `core/`

The CLI must report these as related but different contracts until a migration
closes them:

- `BizCity_Tool_Interface` in `core/twin-core/contracts/framework-contracts.php`
  is the public opt-in content contract; `BizCity_Twin_Tool` in
  `core/twin-core/includes/interface-twin-tool.php` is a legacy/runtime tool
  contract. They must not be silently treated as aliases.
- `BizCity_Channel_Adapter_Interface` in the content contracts and
  `BizCity_Channel_Adapter` in `core/channel-gateway` have different method
  shapes. A CLI check must show both signatures and their adapters.
- `BizCity_KG_Source_Adapter_Interface` and `BizCity_KG_Source_Adapter` are
  separate contracts with different `supports`/extraction APIs.
- `BizCity_Automation_Block` is the active workflow runtime interface; the
  content-level `BizCity_Workflow_Block_Interface` is the opt-in extension
  contract. A compatibility bridge is required before claiming parity.

These are not cosmetic duplicates. They are migration/adapter findings that a
future `contracts check` must surface as `related_contracts` and `migration_gap`.

---

## 4. Active `modules/` Contract Inventory

Active modules are `twinchat`, `twinsearch`, `twinshell`, `twinweb`, and
`webchat`. `modules/_archived/` is excluded.

| Module | Contract surfaces | Artifact/evidence | Class | CLI check |
|---|---|---|---|---|
| `modules/twinchat` | REST/SSE chat, workspace history, source ingestion, learning, studio, admin shell | `bootstrap.php`, REST controllers, `core.twinchat.*` probes | `domain_runtime` | Route namespace, SSE event shape, identity/error contract, probe coverage |
| `modules/twinsearch` | Shared search and input gate | `bootstrap.php`, search REST and provider filters | `domain_runtime` | Gateway wrapper, result fields, no direct provider transport |
| `modules/twinshell` | Shell REST/primitives, learning SDK, account hub | `includes/*rest.php`, `class-twin-shell-learning-sdk.php`, account hub doc | `domain_runtime` | Scope, session, tool trace, REST/error contract |
| `modules/twinweb` | Twin GPT public REST, guest/member identity, thread/project, citation, FB connect | `bootstrap.php`, `class-twinweb-rest.php`, identity/thread probes | `package_adoption` + `domain_runtime` | Same-origin proxy, identity/owner guard, SSE/citation/thread continuity |
| `modules/webchat` | Public shortcode/REST, WebChat platform discriminator, SQL/event-stream lifecycle | `bootstrap.php`, surface/SQL lifecycle probes | `domain_runtime` + `evidence_contract` | Platform boundary, route/shortcode loader, retained projection, lifecycle status |

Module contract rows must include `surface` values such as `public_html`,
`rest_route`, `admin_shell`, `webhook`, `cron`, or `diagnostics`. A module that
has only a bootstrap file is not automatically contract-compliant.

---

## 5. Active `plugins/` Contract Inventory

The active plugin tree contains the following package owners. `_archived/` is
excluded. Package status comes from the adoption registry where a row exists;
absence from that registry is itself a CLI finding (`registry_untracked`).

| Plugin | Main contract surfaces | Registry/manifest state | Class | CLI check |
|---|---|---|---|---|
| `plugins/bizcity-content-creator` | Intent provider, content mutation, admin/AJAX | Registry row must be confirmed | `package_adoption` | Manifest/entrypoint, primary tool, permission, mutation/error evidence |
| `plugins/bizcity-doc` | Document generation/storage and Twin integration | Registry row must be confirmed | `package_adoption` | Gateway, document artifact, ownership, error/runtime probe |
| `plugins/bizcity-facebook-bot` | Facebook channel/webhook, normalized payload, sender, logging | Registry row exists in framework scope | `domain_runtime` | Signature/identity/zone, webhook, sender, file-log and error contract |
| `plugins/bizcity-pagebuilder` | Gateway image, upload, save/delete/publish mutation | Registry `bizcity.pagebuilder = partial` | `package_adoption` | Error envelope, idempotency header, ownership, replay, runtime probe |
| `plugins/bizcity-profile` | Profile routes, identity/profile wheel/provider | Registry `bizcity.profile = partial` | `package_adoption` | Optional loader, identity ownership, route/error/runtime evidence |
| `plugins/bizcity-tool-image` | Image tool/editor, gateway and upload policy | Registry row must be confirmed | `package_adoption` | Tool schema, gateway wrapper, upload/secret boundary, mutation evidence |
| `plugins/bizcity-twin-crm` | CRM Inbox/channel contract, contacts/conversations, outbound logging | Registry `bizcity.twin-crm = partial` | `package_adoption` + `domain_runtime` | Channel contract, zone, identity, repository/event emitter, ownership |
| `plugins/bizcity-video-kling` | Managed video submit/poll, retry/replay/error boundary | Registry `bizcity.video-kling = partial` | `package_adoption` | Video wrapper, no provider key/URL, idempotency, owner scope, runtime probe |
| `plugins/bizcity-zalo-bizcity` | Legacy Zalo hotline adapter and integration | Registry `bizcity.zalo-bizcity = partial`, `legacy_adapter` | `legacy_adapter` | Adapter boundary, normalized payload, DDL/error governance, sunset metadata |
| `plugins/bizcity-zalo-bot` | Admin/command channel, identity link, automation bridge | Registry `bizcity.zalo-bot = partial` | `domain_runtime` | Zone 2 isolation, identity tuple, command owner, channel/file-log/error contract |
| `plugins/bizcity-zalo-personal` | Zalo Personal bridge, account mapping, archive, file log | Registry row must be confirmed | `domain_runtime` | Bridge schema, domain/capacity/mapping failure, archive and secret boundary |
| `plugins/bizcoach-pro` | Coach/profile/astro, membership, gateway/cache and legacy surfaces | Registry `bizcity.bizcoach-pro = partial` | `package_adoption` | Credential boundary, cache contract, user ownership, error/runtime evidence |
| `plugins/bizgpt-tool-google` | Google OAuth/tool integration and gateway capability | Registry row must be confirmed | `package_adoption` | OAuth scope/identity, gateway-only transport, secret boundary, manifest |

### 5.1 Plugin package contract minimum

For a package to receive a machine-readable contract row, CLI should look for:

```text
package_id
path
kind: framework_integrated | legacy_adapter | reference | external
status: pass | partial | fail | review | reference_only
bootstrap
manifest or explicit legacy classification
capabilities[]
required_surfaces[]
contracts[]
permissions/scopes
schema/changelog ownership
runtime probes
error and side-effect policy
```

The current `PLUGIN-CONTRACT-REGISTRY-v1.json` has useful adoption metadata but
does not yet contain a `contracts[]` array for every package. Adding that field
is a future registry/schema change and must follow R-DCL/R-CR before implementation.
Until then, the CLI can produce a derived inventory report, but must label it
`derived`, not canonical registry data.

The 2026-08-27 readiness audit found `manifest` was `null` for every active
package row, confirming only 1/13 active plugins (`bizcity-tool-image`)
shipped the `manifest.json` that `PLUGIN-TWIN-STANDARD.md §2.1` requires, and
only 5/13 shipped a root `README.md`. This gap is pinned as Contract 12 in
[PHASE-0-RULE-BRAIN-UNIFICATION.md](../rules/PHASE-0-RULE-BRAIN-UNIFICATION.md)
and was closed the same day: 12/13 plugins now have `manifest.json` and 13/13
have a `README.md`; registry `manifest` paths were updated to match. Detail in
[PHASE-1.32](../roadmaps/PHASE-1.32-SELF-DIAGNOSING-SYSTEM-READINESS-AUDIT.md).

---

## 6. Contract CLI Design

The following commands are proposed for the next CLI work package. They are not
claimed as implemented by this inventory document:

```text
wp bizcity contracts list
wp bizcity contracts list --scope=core
wp bizcity contracts list --scope=modules
wp bizcity contracts list --scope=plugins
wp bizcity contracts show <contract-id>
wp bizcity contracts check [--scope=...] [--id=...] [--strict] [--json]
wp bizcity contracts audit --changed [--json]
wp bizcity contracts graph [--id=<contract-id>] [--json]
```

### 6.1 `contracts list`

Read-only catalog output. It must show:

- stable ID, owner, path, class, version/source, scope, and status;
- related contracts and migration gaps;
- whether the row is canonical or derived;
- producer/consumer names when known;
- the validator and latest evidence timestamp/status.

It must not query every database table or execute provider calls.

### 6.2 `contracts show`

Explain one contract and its dependency graph:

```text
contract
  -> owner
  -> artifact/schema/interface
  -> producer(s)
  -> consumer(s)
  -> registry/hook/route
  -> validator/probe
  -> storage/identity/permission boundary
  -> failure policy
  -> migration/deprecation status
```

This is the command intended to answer “framework contract này nằm ở đâu?”
without making the operator search through 2,000 files.

### 6.3 `contracts check`

Checks static and runtime evidence separately:

| Check group | Static result | Runtime result |
|---|---|---|
| Artifact | File readable and parseable | Loader includes the intended artifact |
| Shape | Interface/schema/manifest fields match | Producer and consumer use the same shape |
| Registration | Registry/catalog entry exists | Hook/route/provider actually registers |
| Compatibility | Version/semver and related contracts are declared | Old/new adapter behavior is proven |
| Security | Permission/scope/secret declarations are present | Denial, tenant, identity and secret behavior are proven |
| Operations | Retry/idempotency/log/cron metadata is declared | Replay, timeout, cron and evidence behavior is proven |

The result must use `diagnostics-verdict` v1. A static-only run may be
`pass` for static rows while the package/runtime aggregate remains `partial` or
`skip`.

### 6.4 `contracts graph`

The graph is for dependency discovery, not execution. Example edges:

```text
BizCity_Tool_Interface
  -> tool-io-envelope
  -> permission-scopes
  -> runtime-execution-policy
  -> diagnostics-verdict

BizCity_Channel_Adapter
  -> channel-payload
  -> event-envelope
  -> error-envelope
  -> identity/zone runtime probes

BizCity_Workflow_Block
  -> workflow-json
  -> mutation-contract
  -> runtime-execution-policy
  -> scheduler completion metadata
```

The graph must identify cycles, duplicate owners, unregistered schemas, and
public contracts with no fixture or validator.

---

## 7. Proposed Contract Record

This is a design shape for CLI output and a future registry extension. It is
not yet a schema change:

```json
{
  "contract_id": "tool-io-envelope",
  "version": "1.0.0",
  "owner": "core/twin-core",
  "scope": "public_schema",
  "canonical": true,
  "artifact": "core/twin-core/contracts/schema/public/v1/tool-io-envelope.schema.json",
  "interfaces": ["BizCity_Tool_Interface"],
  "producers": ["core/intent", "core/twinbrain"],
  "consumers": ["core/agents", "modules/twinchat"],
  "related_contracts": ["permission-scopes", "runtime-execution-policy"],
  "validators": ["contract-fixture", "core.diagnostics.tool_io"],
  "evidence": {
    "disk": "pass",
    "loader": "pending",
    "runtime": "pending"
  },
  "status": "partial",
  "migration_gap": null,
  "deprecation": { "status": "active", "grace_versions": 3 }
}
```

Rules:

- `canonical=true` only for the owner-declared source of truth.
- A derived row must include `source: derived` and cannot promote a package to
  `pass`.
- `status` is contract evidence status, not package registry status; both must
  be retained.
- `interfaces` may contain legacy and public contracts together only when
  `related_contracts` explains the adapter/migration relation.
- No raw tokens, SQL, PII, provider credentials, full paths, or private content
  belong in contract evidence.

---

## 8. Roadmap For CLI Contract Support

| Sprint | Deliverable | Acceptance |
|---|---|---|
| C1 | Freeze this inventory and define canonical/derived/legacy row semantics | Every active core/module/plugin path has one inventory row or an explicit `untracked` result |
| C2 | Add public `contracts` command design to the CLI contract and help output | `list/show/check/graph` argument and JSON shapes are documented |
| C3 | Build static contract collector | Interfaces, schemas, manifests, registries, routes, hooks, probes and package rows are collected without WP boot where possible |
| C4 | Add contract catalog schema/registry extension | Registry has `contracts[]`, owner, version, scope, validators, and migration metadata; R-DCL/R-CR completed if storage/schema changes |
| C5 | Add runtime reconciliation | Loader/registration/producer/consumer/permission/tenant/side-effect evidence is linked to each row |
| C6 | Add contract graph and migration-gap report | Duplicate shapes, missing owner, missing schema fixture, unregistered artifact, and legacy adapter gaps are visible |
| C7 | CI adoption | Changed active package contract check runs in CI; static PASS cannot hide runtime SKIP/PENDING |
| C8 | Self-diagnosing loop | `wp bizcity contracts check --json` emits actionable evidence/fix hints that an agent can consume and rerun |

Dependencies: C1 before C3, C4 before treating the catalog as canonical, C5
before package status promotion, and C7 before claiming environment parity.

---

## 9. Current Baseline And Known Gaps

- Canonical typed extension contracts: **14 interfaces** in
  `core/twin-core/contracts/`.
- Canonical public JSON catalog: **15 schema contracts** in
  `core/twin-core/contracts/schema/public/v1/`.
- Active top-level package inventory: **22 core owners, 5 modules, 13 plugins**;
  archived/library trees are excluded from this baseline.
- Existing adoption registry: useful but incomplete for contract-level detail;
  many plugin rows lack `contracts[]`, manifest, or runtime probe linkage.
- Several duplicate-looking interfaces are real migration boundaries, not safe
  aliases; the CLI must expose them as related contracts.
- Existing Diagnostics probes provide many runtime checks, but there is no
  generic contract collector/reconciler or contract dependency graph yet.
- No package should be promoted to `pass` from this inventory alone.

---

## 10. Definition Of Done For This Inventory

- [x] Definition of framework contract is explicit and machine-oriented.
- [x] Canonical typed contract location is documented.
- [x] Public JSON contract catalog and current 14 entries are listed.
- [x] Active `core/`, `modules/`, and `plugins/` owners are inventoried.
- [x] `_archived/`, backup, vendor, generated, and library trees are excluded.
- [x] Legacy/domain/runtime contracts are distinguished from public contracts.
- [x] Proposed `contracts list/show/check/audit/graph` CLI surface is documented.
- [x] Proposed record shape separates canonical source, derived inventory, and
      Disk/Loader/Runtime evidence.
- [ ] Contract collector, registry extension, runtime reconciliation, graph,
      and CI implementation are still open implementation work.
