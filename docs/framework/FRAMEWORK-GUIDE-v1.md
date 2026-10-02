# BizCity Twin Framework Guide v1

> **Directive:** Johnny Chu - Chu Hoàng Anh · 2026-09-13

> Status: Orientation guide
> Scope: `bizcity-twin-ai`, active satellite plugins, `bizcity-llm-router`
> Audience: framework developers, extension authors, reviewers, operators
> This guide is a navigation layer. It does not override the canonical rules.
> Ultimate direction: [Enterprise Brain Direction](../rules/PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md) — every framework path must reinforce the horizontal Channel Gateway, vertical contract-based Brain Modes/extensions, and KG Graph knowledge layer.
> Context spine: [R-CONTEXT-BANK](../rules/PHASE-0-RULE-CONTEXT-BANK.md) — normalized enterprise streams, rollups, memory/rule references, KG promotion and MPR retrieval share one corpus/pointer architecture.

## 1. Read This First

Use this priority order when documents disagree:

1. **Stable public API:** [PUBLIC-CONTRACTS-v1.md](../contracts/PUBLIC-CONTRACTS-v1.md)
2. **Extension conventions:** [HOOKS.md](../extension/HOOKS.md), [getting-started.md](../getting-started.md), and [sub-plugin-quickstart.md](../extending/sub-plugin-quickstart.md)
3. **Security and capability baseline:** [CAPABILITY-SECURITY-v1.md](../contracts/CAPABILITY-SECURITY-v1.md)
4. **Runtime and reliability baseline:** [RUNTIME-PRODUCTION-CONTRACT-v1.md](../contracts/RUNTIME-PRODUCTION-CONTRACT-v1.md)
5. **Internal architecture and release governance:** maintained in the approved development workspace and intentionally omitted from the public package.

The public guide does not link to private roadmaps, audit reports, diagnostics
evidence or plugin distribution decisions. Public extension authors should use
the versioned schemas, SDK interfaces and onboarding checks; internal release
governance is reviewed separately.

A roadmap explains delivery state. It does not create a new architectural authority.
A helper or existing code path is not automatically a public contract. For all
bootstrap loading, [R-SAFE-LOADER](../rules/PHASE-0-RULE-SAFE-LOADER.md) is the
controlling rule.

Before designing a new capability, apply the Enterprise Brain direction gate:
identify its Channel Gateway intake, Vertical Brain Mode/extension contract,
Context Bank stream/rollup contract, KG Graph evidence path, shared spine owner,
and non-duplicated UI/data owner.

### 1.1a Public framework distribution boundary

The public GitHub package contains the reusable framework spine: `core/`,
framework-owned `modules/`, and channel/brain contracts that implement the
**all channels, one brain** direction. Customer-specific utility packages are
not part of that distribution and must never become `must-load` dependencies:

- `bizcity-video-kling`
- `bizcity-tool-image`
- `bizcity-doc`
- `bizcoach-pro`
- `ibs-hi`
- legacy `bizcity-personal`

These packages may remain in an approved private deployment as separately
installed Pro utilities. Their absence from the public checkout must degrade
to a clear Pro-required state, not a fatal, a missing route, or a second
framework owner. `bizcity-zalo-personal` is intentionally different: it is an
active Zone 1 channel package and remains governed by the Channel Gateway
contracts until a separate channel migration decision is approved.

## 1.1 Safe PHP Artifact Loading

Module bootstraps MUST load optional or deployable PHP artifacts through
`BizCity_Safe_Loader::require_file( $path, $label )` from
`core/helper/class-bizcity-safe-loader.php`. The helper checks `is_file()` and
`is_readable()` before `require_once`, catches load-time `Throwable`, and emits
only a bounded `label/reason` log entry. A missing artifact must degrade the
owning feature or module; it must not turn activation or a REST request into a
500 response.

```php
$file = MY_MODULE_DIR . 'includes/class-optional-adapter.php';
if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
  BizCity_Safe_Loader::require_file( $file, 'my_module.optional_adapter' );
}
```

Use a guarded direct load only to bootstrap the Safe Loader itself. Do not add
raw `require_once` calls for module artifacts to a feature bootstrap. CI rejects
new raw module/probe requires in every active bootstrap under `plugins/`, `core/`,
and `modules/` as a framework enforcement gate. Required classes must retain
`class_exists()` checks at their registration/use boundary,
so a partial deploy produces a controlled unavailable/degraded state.

## 1.2 Trace-First Route Matrix

For any feature that crosses a Hub, client site, tenant shard, channel, or
member surface, write and verify this route before changing code:

```text
surface/domain
  → blog_id + physical shard
  → exact key_id + allowed_domain
  → plan/tier + feature/capacity
  → tenant mapping + owner
  → canonical row/inbox/account
  → callback/provider
  → response + reason bucket
```

The first investigation must contain one falsifiable hypothesis and one cheap
check that can disprove it. Keep the dimensions `domain`, `blog_id`, `key_id`,
`tier`, `capability`, `owner`, and route visible in evidence. Classify the
failure before editing as transport, auth, entitlement, domain, tenant,
mapping, side effect, or presentation/cache. Local source/static evidence is
not runtime evidence until the deployed loader and route return the same
contract.

One user-facing concept has one state/UI owner. A child component may render
embedded content, but must not create a second card, query, cache, or mutation
owner for the same state.

## 1.3 Find Your Work Path

| You are trying to... | Start with | Required boundary |
|---|---|---|
| Add an LLM/Search/Video/Astro/PiAPI feature | [API catalog](../api/README.md) | Existing client wrapper first; no direct provider HTTP |
| Add a browser or SPA capability | [Sub-plugin quickstart](../extending/sub-plugin-quickstart.md) | Same-origin REST/AJAX + nonce, then PHP wrapper; optional utility UI must use the Pro-required fallback when its package is absent |
| Add or change a module/plugin settings page | [R-SETTING-PANEL](../rules/PHASE-0-RULE-SETTING-PANEL.md) and [registration contract](../contracts/SETTING-PANEL-REGISTRATION-CONTRACT-v1.md) | Register into TwinShell Control Panel; keep renderer/value ownership; no new top-level menu |
| Add a Tool/Agent/Skill/Channel/Adapter | [Agent/tool recipe](../extending/agent-tool-recipe.md) and [HOOKS.md](../extension/HOOKS.md) | Typed contract or explicit legacy adapter |
| Build a community plugin scaffold | [PLUGIN-STANDARD.md](../extending/PLUGIN-STANDARD.md) and [PLUGIN-TWIN-STANDARD.md](../extending/PLUGIN-TWIN-STANDARD.md) | `manifest.json` + bootstrap + declared capability contract |
| Receive or send a channel message | [Channel-Only R-CH-10](../rules/PHASE-0-RULE-CHANNEL-ONLY.md#r-ch-10--all-channels-one-diagnostics-contract) | `channel-payload` + exact tenant/account/identity/zone + `channel-diagnostics-record`; one logger/index/Log Explorer |
| Add a channel diagnostics/log surface | [Channel File Log target v2](../../core/channel-gateway/docs/RULE-CHANNEL-FILE-LOG.md) | Extend the canonical writer/query/component; no plugin logger, route, retention or viewer |
| Add an enterprise context stream, rollup, relation or MPR search source | [Context Bank rule](../rules/PHASE-0-RULE-CONTEXT-BANK.md) | Registered producer + encrypted JSONL/canonical owner + tenant pointer ledger + bounded retrieval; no SQL payload copy |

For all `bizcity_memory_*` families and the legacy `bizcity_memory` table,
apply the same gate: encrypted JSONL/business filestore is the payload source
of truth, while `bizcity_context_bank` is a pointer/correlation projection.
Do not create new SQL memory payload writes or copy decrypted memory into the
ledger; use the lifecycle roadmap for legacy-row retention and cleanup.
| Implement a Context Bank capability | Public Context Bank contracts | Follow the published owner, pointer and bounded-retrieval contracts |
| Read/write KG or memory | [Brain Unification](../rules/PHASE-0-RULE-BRAIN-UNIFICATION.md) | Facade/service only; no direct KG table access |
| Add a REST/AJAX error | [Error UX rule](../rules/PHASE-0-RULE-ERROR-UX.md) | `code`, `message`, `hint`, `help_code` |
| Add a mutation, queue, or external side effect | [Public contracts](../contracts/PUBLIC-CONTRACTS-v1.md) | Permission, idempotency, trace, retry, outcome evidence |
| Add or change a table/column/index | [Diagnostics changelog rule](../diagnostics/PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md) | R-DCL + schema registry + provisioner + DDV |
| Change a loader or bootstrap | Loader/runtime contract | Declare the surface gate and collect focused load evidence |
| Prepare a release | CI and diagnostics contracts | Validate public contracts, package metadata and applicable runtime evidence |
| Enforce complete extension adoption | Public extension contracts | Prove the applicable Channel, CRM, Context Bank/KG, Brain and MCP/action boundaries |
| Add or extend a WP-CLI command | `diagnostics-verdict` and CLI contract | Use the root `bizcity` namespace and do not create a parallel diagnostics engine |

When two rows appear to apply, follow both boundaries. The more restrictive
security, identity, storage, or runtime rule wins.

## 1.4 The Four-Layer Verification Model

Every contract in this framework is checked through the same four layers, in
the same order, for both a local developer and CI:

```text
CLI            ->  bin/twin (doctor · validate · test · diagnostics · inspect)
Diagnostics    ->  core/diagnostics (Runtime · Config · Hooks · Plugin/SDK
                    contracts · Schema · Permissions · API · Compatibility)
Verdict        ->  PASS / WARN / FAIL, exit code 0 / 1 / 2
GitHub Checks  ->  .github/workflows/ci.yml jobs (public-contracts, lint-php,
                    schema-changelog, diagnostics-mock, sdk-package-build)
```

`bin/twin` is the single entrypoint that ties the previously scattered
`bin/*.php` / `bin/*.mjs` / `wp bizcity diag` commands together:

| Command | Layer it drives | Needs WordPress? |
|---|---|---|
| `php bin/twin doctor` | Local environment sanity (PHP version, extensions, node/wp-cli availability) | No |
| `php bin/twin validate [--plugin=P]` | Static manifest/registry/contract-audit/SDK checks | No |
| `php bin/twin test [--filter=F]` | Contract fixture tests + PHPUnit | No |
| `php bin/twin diagnostics [opts]` | Full runtime probe engine (`core/diagnostics`) | Yes |
| `wp bizcity diagnostics [opts]` | WordPress/WP-CLI facade for the canonical Smoke Runner | Yes |
| `php bin/twin inspect manifest\|registry\|probe` | Read-only inspection of one artifact | `probe` only |

CI keeps each underlying script as its own job step (fine-grained GitHub
Checks annotations); `bin/twin` runs the same scripts locally so a developer
or agent gets the identical PASS/FAIL verdict before pushing. Do not
duplicate validator logic inside `bin/twin` — add new checks to the owning
script under `bin/`, `core/diagnostics/includes/probes/`, or
`core/twin-core/contracts/tests/`, then wire them into the relevant `twin_cmd_*`
step list.

Local developer, GitHub Actions, Codex, and an in-editor agent should all be
able to run the same public validation commands and get the same contract
verdict. Detailed parity gaps and release closure evidence are internal
governance artifacts and are not distributed in the public package.

## 2. The One-Sentence Architecture

BizCity Twin is a **Self-hosted Twin Runtime using the BizCity Managed AI Gateway**:

- WordPress client owns orchestration, tenant **raw** data and content, identity context, channel state, memory, KG, CRM, scheduler, and local evidence.
- BizCity Gateway is **three roles**, fixed by [R-PROVIDER-FLOW](../rules/PHASE-0-RULE-PROVIDER-FLOW.md) §1.1 (2026-09-27, D-OB-8…8e):
  - **Ops console:** the mu-plugin `bizcity-openrouter`.
  - **Commercial Hub:** `bizcity-llm-router`.
  - **Data plane:** the zalo-hub cell running **brain-core**. It holds every provider secret and makes every provider call.
- Extensions consume versioned contracts and must not create a parallel brain, gateway, identity system, or billing ledger.

### 2.1 Core skeleton (supreme, R-PROVIDER-FLOW §1.1)

![Two planes](../rules/assets/R-PROVIDER-FLOW-v2-two-planes-brain-core.png)

| Role | Component | Owns | Never |
|---|---|---|---|
| **Ops console** | mu-plugin `bizcity-openrouter` (Network Admin + Monitor) | Cell-hub configuration and test tools; **model per purpose** (pushed to the data plane as platform config); the overall **Monitor**; the zca Managed Bridge card (legacy, secondary) | 1API keys, plans, sales; provider keys; request path |
| **Commercial Hub** | `bizcity-llm-router` | **1API keys** + domain, **plans**, **purchase/renewal** (WooCommerce), **my-account**, entitlement/limits/credit, usage ledger and billing; pushes the tenant snapshot | Provider keys or calls; model per purpose; ops UI; request path |
| **Data plane** | Cell (Node, loopback): **brain-core** + zalo-cell `:3901` + ai-gateway `:3902`. Code: `zalo-hub/` at the plugin root (own deploy unit, Docker; never shipped in the plugin) | Every provider account and every external API call (LLM, search, image, audio, video, astrology/FreeAstroAPI, future APIs — 100 %), reasoning over client raw data, streaming, Zalo; obeys platform config + tenant snapshot; reports usage | Prices, plans, limits; settings UI |
| **Client site** | `bizcity-twin-ai` + satellites | Raw resources and content, CRM, Guru content; calls the Hub's public URL with its domain + one API key | Reaching a provider or a data-plane port |

A new capability first picks its row. Anything that puts provider calls in PHP, plan logic in the data plane, or model/cell config in the Commercial Hub is rejected in review.

The old identifier `R-GW-8` remains valid. “Standalone client” describes deployment topology only; it does not mean provider-independent AI capability.

### 2.2 Reply-turn axis (supreme, R-TWIN-AGENT-AXIS)

Reply turns on **Zalo Cá nhân (zalo-hub), TwinChat and Twin GPT** follow one axis: *one agent · one twin stream · two roles · data by packs* ([rule](../rules/PHASE-0-RULE-TWIN-AGENT-AXIS.md), machine form [`TWIN-AGENT-AXIS-v1.json`](../contracts/TWIN-AGENT-AXIS-v1.json)).

| Question | Answer on the axis |
|---|---|
| Who answers the turn? | brain-core `runAgentTurn` (data plane), **one loop for all three surfaces**. Each surface is only an adapter with four duties — intake, principal, delivery, record — per the [Surface Adapter Guide](TWIN-AGENT-SURFACE-ADAPTER-GUIDE-v1.md). The only surface branch inside the loop is the channel-format block. |
| Owner or customer? | Resolved on the server (`owner-agent-block@1`); default customer. On Zalo the owner is the 1-1 sender whose UID equals the number's "UID chủ tài khoản" (⇒ `owner_user_id`); files and "ghi nhớ" from the owner go into the daily notebook (`owner-capture@1`), nobody else can save; on the web the owner is `get_current_user_id()` — same `user_id`. A customer turn = the owner turn without the Owner Agent block; owner tools never in groups. |
| What may the owner block use? | Only the **agent modes** granted by [`agent-mode-access@1`](../contracts/AGENT-MODE-ACCESS-CONTRACT-v1.md) (`bizcity_agent_mode_<mode>`; editor ⇒ `notebook`; business modes ⇒ CRM admin/supervisor). Notebooks are always the principal's own (first `user_id`). |
| How does business data reach the turn? | Projection packs pulled by the cell (`projection-pack@1`); never a PHP call inside the turn. |
| What does the client plugin ship for it? | Connection (4 steps), read-only exporters, invalidation hooks, config UI, records. No LLM or embedding. |
| Where does MPR run? | On the web, only as the asynchronous custom add-on `deep-analysis-job@1`. |
| What does a new feature on these surfaces declare? | Surface, block, packs, tools, role gate — in the axis JSON — and `@axis twin-agent-axis@1 …` code markers. |

### 2.2a Tenant tool capability gate + usage metering (PHASE-0.85, shared framework primitive)

Any data-plane tool that can cost real provider money, or that a tenant's plan should be able to turn on/off, goes through **one gate and one meter** — built in PHASE-0.85 ([01-CONTRACTS](../../core/channel-gateway/docs/PHASE-0.85-TENANT-TOOL-METERING/01-CONTRACTS.md)), already reused outside that phase (PHASE-0.87 lane BC's `owner_agent.<mode>` capability reuses the same `capability.ts`/`tenant-tool-policy.ts`, see §2.2 above):

| Concern | File (`zalo-hub/src/...`) | What it does |
|---|---|---|
| Capability decision (does this tenant's plan allow this tool right now?) | `brain-core/entitlement/capability.ts` (`decideCapability`) | Pure: snapshot + tool key → `ok` or a `CapReason` (`snapshot_missing`, `plan_excludes_tool`, `budget_exhausted`, `provider_not_configured`). Tool keys with no capability entry (e.g. `web_fetch`, pack-only owner tools) always pass — the gate only exists for tools that cost provider money. **Fails closed**: missing/expired snapshot or budget exhausted ⇒ blocked. |
| Per-plan agent-mode gate (does this tenant's plan allow this *mode*, e.g. PHASE-0.87's `owner_agent.<mode>`?) | `brain-core/entitlement/capability.ts` (`decideOwnerMode`), `agent/tools/tenant-tool-policy.ts` (`ownerModeAllowedForTenant`) | A **separate, deliberately fail-open** gate from `decideCapability` above: it only blocks on an explicit `capabilities["owner_agent.<mode>"].allowed === false` — a missing key, missing snapshot, or Hub not yet aware of the mode all mean *allowed*, because the mode gates the tenant's **own** data (owner tools), not a paid provider call, and a Hub that doesn't know the key yet must not lock an owner out of their own business data. Don't "fix" this to fail closed to match `decideCapability` — the asymmetry is intentional. |
| Combined gate (capability + provider registry) | `agent/tools/tenant-tool-policy.ts` (`toolDecisionForTenant`) | What `listAvailableTools` actually calls before a tool enters a tenant's schema. Chủ cell (`tenantId<=0`) always `ok` here — gated upstream by agent/account `disabledTools` instead. |
| Quota (per-day / per-customer-per-day) | `brain-core/entitlement/quota.ts` (`checkQuota`) | Read-only; never writes. Reads `tenant_usage_counters`/`tool_usage`, written by the metering call below. |
| Usage metering (one row + one Hub event per provider call) | `brain-core/usage/tool-usage.ts` (`meterTool`, `recordToolUsage`) | Wrap the actual provider call: records `tool_usage` (operational row, kept 90 days) and emits a `tool_usage` event (C85-2) to the Commercial Hub, which is the only source of billed `cost_usd`. Records on **both** success and failure (`units:0` on error) so error rate is visible. A tool that reads only pre-synced local data (e.g. PHASE-0.87's pack-based owner tools, which never call a provider — R-PF-2) does **not** need this; only a real provider call does. |
| LLM-shaped side calls (not a discrete "tool", but still a provider call with tokens/cost) | `brain-core/usage/record.ts` (`recordSideCallUsage`, `purpose` ∈ `summary\|vision\|test_turn\|scheduled\|embeddings`) | Use this instead of `meterTool` when the call is itself an LLM/vision call (cost/tokens from `providerMetadata`, not a fixed `unit`/`units`) — e.g. the vision sidecar (`purpose:"vision"`) or embeddings (`purpose:"embeddings"`, already used by PHASE-0.87's BC-5 vector search). |

**Rule of thumb for a new tool/lane:** if it calls an external provider (LLM, image, voice, video, search, astrology, scrape...), wire it through `meterTool`/`recordSideCallUsage` the same way Z4's 9 tools + the vision sidecar do — don't invent a parallel usage path. If it only reads pack/local data, it needs neither metering nor a capability key (unless the business wants a plan-level on/off switch for it, in which case add a capability key but skip the metering call).

### 2.2 How the core is built: lean, four steps (supreme, R-LEAN-4)

The client plugin is built to get **smaller, lighter and faster with every change** ([R-LEAN-4](../rules/PHASE-0-RULE-LEAN-FOUR-STEP.md)):

1. **Every job ≤ 4 steps**, every step checkable; big work = several small wins that ship alone.
2. **Every UI flow ≤ 4 steps**; a page shows ≤ 4 primary steps; detail lives in sheets (R-SETTINGS-4L); R-SETUP-4 is the model.
3. **Lean scoreboard on every change** — core PHP, PHP loaded per REST request, tables, routes/AJAX, PHP HTML, client reply loops, UI flows > 4 steps.
4. **Fewer tables, lighter loading**: prove a new table is needed; load code only on the surface that uses it.

Where each core folder stands on the Twin Agent Axis (CORE-REDUCTION WP-15, 2026-09-30):

| Tier | Core folders | Meaning |
|---|---|---|
| **Axis** | helper, runtime, cron, bizcity-llm (Hub client + key), channel-gateway (except `includes/bot/`), kg-hub, knowledge (Guru + quick FAQ), conversation, twin-core `event-stream/`, smtp | what the thin client keeps (R-TAA-6); loaded on axis routes |
| **Add-on** (leaves as plugin `bizcity-twin-brain-addon`, Q-W16-1) | twinbrain (minus the vertical resolvers kept for MCP), automation, intent (tool registry), memory, scheduler UI, rest of twin-core, persona runtime | custom branch; not part of the main plugin |
| **Legacy** | Bot Studio PHP turn runner (`channel-gateway/includes/bot/`), persona Guru runtime, CRM AI replier, knowledge chat gateway, TwinChat stream handler, `/gpt/` chat stream | second reply loops (R-TAA-1); cut in cut-over order |
| **Owner decision** | membership, bizcity-market, KG-Hub repair pages (D-12) | business choice |
| **Cut** (Q-W16-3) | client tools: video-kling, bizgpt-tool-google, pagebuilder, twinsearch, tool image | tools run in brain-core |

CRM keeps 11 core tables; campaigns, invoices, contracts, SLA and reporting move to `bizcity-twin-crm-addon` (Q-W16-2). Budget contract: `docs/contracts/CLIENT-LEAN-BUDGET-v1.json`.

Today every `/wp-json/` request loads ≈ 13 MB of `core/` PHP; the axis REST profile and add-on gating (WP-15 W15-2/W15-3) are the largest "light" lever.

**Client scope and budgets (R-LEAN-4 §3b, 2026-09-30):** the client is *every channel ⇒ CRM ⇒ notebook (KG-Hub) + Context Bank + vertical models for MCP*. Shipped PHP must go below **10 MB** (21.97 MB today) and declared tables below **30** (150 today); `node bin/lean-scoreboard.mjs` measures both. Budget per block and the 29-table target: CORE-REDUCTION WP-16.

## 3. Ownership Map

| Responsibility | Owner | Correct extension boundary |
|---|---|---|
| Focus, intent gating, local orchestration | `bizcity-twin-ai` client | Twin Kernel interfaces and local hooks |
| Tenant data and state | Current WordPress blog/shard | `$wpdb->prefix`, canonical facades, R-MSDB |
| LLM/Search/Video/Astro/PiAPI (and every future) provider execution | **Data plane** (cell brain-core), reached through the Hub's public URL | Client wrapper with Bearer `biz-xxx` — unchanged for extension authors |
| Provider credentials | **Data plane only** (cell, encrypted) | Never store/read provider keys on the client, the Commercial Hub, or the mu-plugin |
| Model per purpose, cell configuration, overall monitoring | **Ops console** (mu-plugin `bizcity-openrouter`) | Never in the Commercial Hub or the client |
| User/member/channel identity | Canonical client/Gateway identity services | Preserve `(platform, account_id, user_id, chat_id)` |
| Billing/quota/plan, 1API keys, purchase, my-account | **Commercial Hub**, from the authenticated API key | Never infer from `user_id` alone; never in the data plane |
| Runtime evidence | Diagnostics on the WordPress site | Disk, Loader, Runtime layers |
| Stable extension contract | JSON Schema + PHP/TypeScript interfaces | No direct dependency on private class internals |

## 4. Choose the Correct Path

### 4.1 Need an LLM call

```text
Extension -> BizCity_LLM_Client -> same-origin proxy when FE is involved
          -> Hub public URL (bizcity.vn/wp-json/bizcity/v1/ai/openai/* …)
          -> Apache ProxyPass -> ai-gateway :3902 -> brain-core -> provider   (no PHP on this path, R-PF-8)
```

Use `BizCity_LLM_Client::chat()`, `chat_stream()`, `generate_image()`, or the relevant approved wrapper. Do not call OpenAI, Anthropic, OpenRouter, or provider endpoints directly.

### 4.2 Need search, video, Astro, or PiAPI

Use the dedicated wrapper when it exists:

| Capability | Wrapper | Current note |
|---|---|---|
| LLM/image generation | `BizCity_LLM_Client` | Branch 01/06 |
| Search | `BizCity_Search_Client` | Branch 03 |
| Video | `BizCity_Video_Client` | Branch 04 |
| Astrology | `BizCity_Astro_Client` | Branch 07 |
| PiAPI image task | `BizCity_PiAPI_Client` | Phase 1.25 Wave 1: `remove_background` |

If a wrapper is missing, check the Hub API catalog before writing a new transport. Do not use a different modality wrapper just because the upstream provider is the same.

### 4.3 Need a new frontend call

```text
Browser -> same-origin client REST/AJAX route + X-WP-Nonce
        -> PHP client wrapper
        -> Hub Bearer request
```

The browser must not fetch `bizcity.vn` directly and must not receive provider credentials.

### 4.4 Need a channel event

```text
Verified webhook/intake
  -> canonical normalized envelope
  -> business consumers
  -> identity/memory/CRM/automation
  -> canonical sender
```

Required identity tuple:

```text
platform + account_id + user_id + chat_id + message_id
```

Zone 1 customer channels and Zone 2 admin channels must remain separate. Raw hooks may exist only inside a bounded adapter that produces the normalized envelope.

### 4.5 Need knowledge or memory

Use the KG/Memory facade and canonical services. Do not query `bizcity_kg_*` directly from an extension. Do not create a second memory table or a surface-specific “brain”.

### 4.6 Need to add or migrate data

Before creating a table, file contract, option, user meta, CPT, repository,
Event Stream projection or knowledge artifact, declare the public
`extension-storage-context@1.0.0` contract. Use the smallest canonical storage
that preserves correctness:

```text
operational log/audit/trace
  -> canonical JSONL logger and optional pointer index

reusable business/context payload
  -> encrypted Business JSONL File Store
  -> lock-captured receipt
  -> pointer-only Context Bank ledger
  -> registered rollup or explicit no-rollup decision

small low-churn configuration/profile/editorial data
  -> existing CPT / option / site option / user meta / repository

large, atomic, relational or hot-path state
  -> typed tenant SQL/canonical repository
  -> Context Bank adapter or reviewed no-context decision
  -> bounded Context Retrieval Pack/MPR bridge when reusable
```

Never put full payloads into `bizcity_context_bank`, never scan filestore files
inside a chat/MPR request, and never create a second vector/KG/retrieval path.
An extension that creates a SQL table must also declare its payload owner,
receipt/ledger policy, rollup/rebuild policy, retention, rollback owner and
`context-retrieval-pack@1.x`/MPR relationship. A missing framework capability is
a deferred framework proposal, not permission to add a private replacement.

### 4.6 Need a mutation or external side effect

The operation must have:

- permission and scope decision;
- approval gate when sensitive;
- idempotency key;
- trace ID;
- retry bucket and deadline;
- outcome evidence;
- metadata-only audit/DLQ behavior where applicable.

## 5. Public Contracts vs Internal Code

Stable public API includes:

- JSON schemas under `core/twin-core/contracts/schema/public/v1`;
- framework/content interfaces under `core/twin-core/contracts/`;
- manifest security schema;
- documented extension hooks and versioned payload envelopes.

Internal code includes:

- private classes under `core/*/includes` unless explicitly documented;
- cache/DB helpers;
- probe internals;
- feature-specific SQL columns;
- legacy adapters.

When an extension needs a private helper, stop and decide whether to:

1. use an existing public wrapper;
2. add a small public contract;
3. keep the call inside a compatibility adapter with a sunset condition.

Do not make internal code public by copying its current shape into a new plugin.

## 6. Error Contract

Every user-visible error must contain:

```json
{
  "code": "invalid_param",
  "message": "Mô tả ngắn gọn điều đã xảy ra.",
  "hint": "Thực hiện hành động tiếp theo.",
  "help_code": "valid_help_catalog_key"
}
```

Rules:

- Vietnamese, actionable, and concise.
- No SQL, stack trace, filesystem path, provider response, token, or PII.
- Use `BizCity_Error_Payload::make()` or `from_wp_error()` at the REST/AJAX boundary.
- Preserve the error object in TypeScript; do not flatten it to a string.
- Retry only when the code is retryable.
- Use HTTP 200 with `_degraded:true` for client gateway degradation where R-GW-8 requires fail-open behavior.

Domain services may return `WP_Error` internally. The boundary caller must map it before it reaches a user or public API consumer.

## 7. Security and Credential Rules

### Client

- Store only the BizCity gateway URL and opaque `biz-xxx` key.
- Read the key through `BizCity_LLM_Client`.
- Never read `bizcity_piapi_api_key`, provider OpenAI/Anthropic/Tavily/Kling keys, or server-only Router classes.
- Validate outbound URLs with the shared security policy when the client fetches user-controlled URLs.
- Validate uploads by MIME, size, pixel budget, and scan policy where applicable.

### Commercial Hub (`bizcity-llm-router`)

- Authenticate the exact Bearer key and preserve `key_id` (and publish the key snapshot the ai-gateway enforces).
- Resolve plan/quota/entitlement from that key, not `user_id` alone.
- Hold **no** provider credentials (R-PF-1).

### Data plane (cell brain-core)

- The only holder of provider credentials, encrypted with `CREDENTIALS_ENCRYPTION_KEY`, never returned by any API/log/export.
- Binds to `127.0.0.1` only; reached through the Hub domain's reverse proxy or loopback.
- Obeys the tenant snapshot; never decides price, plan or limit.
- Revalidate URL, MIME, size, redirects, and private/reserved IP ranges before provider fetch.
- Never trust client-provided plan, cost, provider, user, or key identity.

## 8. Database, Cache, and Multisite Rules

Before adding or querying storage, answer:

1. Is this global or tenant data?
2. Does the query run before or after `switch_to_blog()`?
3. Is `$wpdb->prefix` or `$wpdb->base_prefix` the correct owner?
4. Is the physical shard/keymeta route verified?
5. Is the cache key dimensioned by blog/shard/identity?
6. Is schema change registered through changelog, schema registry, and provisioner?

Use `$wpdb->prefix` for tenant tables and calculate table names after switching blogs. Always restore the original blog in `finally`-equivalent cleanup. Do not use `SHOW TABLES`; use the canonical metadata helper with dual cache.

Every DB reader needs a cache contract where applicable:

- group and key shape;
- filter dimensions;
- TTL;
- invalidation after successful writes;
- blog/shard dimensions.

## 9. Loader and Performance Rules

Classify the request surface before loading code:

```text
public_html | admin_shell | admin_page | REST route | webhook | cron | CLI | diagnostics
```

Then load only the contract, bootstrap, and runtime handler required by that surface.

Do not:

- load diagnostics probes on ordinary frontend/admin requests;
- call DB/Redis at file scope or blanket `plugins_loaded` for admin-only data;
- treat `is_admin()` or `/wp-json/` as a complete dependency list;
- load all provider/runtime classes for one small route;
- repair schema on every request.

After loader changes, validate frontend, normal admin, target REST, webhook, cron, and Diagnostics contexts separately.

## 10. Diagnostics and Definition of Done

Diagnostics is the runtime evidence layer, not a substitute for every unit test.
A capability is not done because a file or class exists.

Required evidence for a production-facing change:

| Layer | Proves |
|---|---|
| Disk | Required files, schemas, and registration artifacts exist |
| Loader | Correct classes/hooks load in the intended surface and order |
| Runtime | Allow/deny/error/success behavior works with real WordPress lifecycle |

For gateway work, prefer mock probes for deterministic behavior and real health probes only when credentials/environment are available. Never log full keys, provider keys, raw SQL, or private user content.

### Minimum evidence by change type

| Change type | Focused check before broad regression | Runtime evidence required |
|---|---|---|
| Pure parser/sanitizer/helper | Unit/contract fixture or direct deterministic test | Only when lifecycle-dependent |
| Gateway wrapper | Mock HTTP response and degraded/error cases | Route, auth, trace, idempotency, retry, key-scope probe |
| REST/AJAX endpoint | Permission, success, invalid input and failure response | Loader + real route + error envelope |
| Channel/webhook | Synthetic normalized payload and duplicate case | Identity tuple, zone isolation, outbound reply |
| Mutation/queue | Replay/idempotency and denied-permission case | Outcome audit, retry/DLQ, scheduler or worker path |
| Schema/installer | Changelog/schema validator | Physical shard, loader, DDL/provisioner evidence |
| Loader/performance | Included-file/class and route-focused check | Frontend, admin, REST, webhook, cron matrix |

Do not mark a runtime-sensitive row `PASS` from Disk evidence alone. Use `SKIP`
or `PENDING` when the required WordPress lifecycle cannot run, and record the
missing environment explicitly.

## 11. Framework Change Workflow

### Before coding

- Read the applicable canonical rule and API catalog branch.
- Identify the owning abstraction and one nearby implementation.
- Write one falsifiable hypothesis and one discriminating check.
- Decide whether the change is client, Hub, or hybrid.
- Decide error, identity, idempotency, storage, cache, and probe contracts.

### While coding

- Make the smallest owner-boundary change.
- Add the required change stamp for PHP changes.
- Preserve PHP 7.4 compatibility.
- Do not edit `_archived/`.
- Do not add provider credentials or direct provider HTTP.
- Keep public success payloads backward-compatible unless a versioned contract change is approved.

### After coding

- Run the cheapest focused validation immediately.
- Run `get_errors` on touched files.
- Run contract tests, active audit, registry validator, and SDK validator as applicable.
- Add or update a Diagnostics probe for lifecycle-dependent behavior.
- Record residual risk and runtime gaps in the roadmap.

## 12. Package Compliance Levels

| Level | Meaning |
|---|---|
| `pass` | Applicable contracts, security, runtime behavior, and evidence are all proven |
| `partial` | Core path exists but one or more adoption/evidence requirements remain |
| `fail` | Active release-blocking bypass or unsafe boundary remains |
| `review` | Scope/ownership/evidence is not yet sufficient to score |
| `legacy_adapter` | Bounded compatibility path with explicit owner and sunset condition |

Registry presence is discoverability only. It is not compliance evidence.

## 13. Current Work Focus

As of 2026-08-10:

- Channel source migration is statically clean; runtime identity/zone probes remain required.
- PiAPI Wave 1 supports `remove_background`, with client wrapper, idempotency, owner metadata, reliable HTTP, SSRF, MIME, byte, pixel, and mock DDV coverage.
- Tool Image core AJAX and REST error batches use the canonical error envelope.
- `image_edit` and `image_upscale` remain fail-closed until Hub provider mappings and pricing are approved.
- Remaining framework work is concentrated in runtime evidence, package governance, broader credential sweep, scheduler/channel reliability, and release reproducibility.

## 14. Quick Checklists

### New gateway capability

- [ ] API catalog branch checked.
- [ ] Existing wrapper checked.
- [ ] Hub route/auth/key_id/plan/quota defined.
- [ ] Provider mapping and cost approved.
- [ ] Idempotency/task ownership defined.
- [ ] SSRF/MIME/size/pixel policy defined.
- [ ] Client wrapper uses canonical key and reliable HTTP.
- [ ] Error/help codes defined.
- [ ] Mock and runtime probes added.
- [ ] Tool caller migrated only after Hub evidence passes.

### New plugin/module

- [ ] Registry row and bootstrap path are real.
- [ ] Surface/load gate defined.
- [ ] Manifest or explicit legacy-adapter classification exists.
- [ ] Public contracts/hooks documented.
- [ ] Permission/scope/approval behavior defined.
- [ ] Error envelope adopted.
- [ ] Storage/cache/DDL ownership documented.
- [ ] Idempotency/retry/trace behavior defined for side effects.
- [ ] Disk/Loader/Runtime probe exists.
- [ ] CI and release metadata are reproducible.

### Before release

- [ ] Active audit has no unreviewed findings.
- [ ] Contract fixtures pass.
- [ ] Registry and SDK release validators pass.
- [ ] PHP 7.4 compatibility passes.
- [ ] Schema changelog validator passes.
- [ ] WordPress smoke matrix passes.
- [ ] Runtime probes pass for changed boundaries.
- [ ] Residual risk and known gaps are documented.
- [ ] No production-ready claim is made from static evidence alone.
- [ ] The four-step setup passes end to end on a clean site (§14.1).

### 14.1 Packaging: the four-step setup on the vertical axis (supreme, R-SETUP-4)

A package that ships a channel is packaged **along the vertical axis**: channel ⇒ CRM Inbox ⇒ Agent Guru (one brain) ⇒ knowledge. Its setup is the entry of that axis and follows [R-SETUP-4](../rules/PHASE-0-RULE-FOUR-STEP-SETUP-AXIS.md):

| Step | Label (same on every host) | Passes when (server check) |
|---|---|---|
| ① | Kết nối tài khoản BizCity | The site's own 1API key is saved and the connection report has no `fail` in L0–L5 |
| ② | Kết nối máy chủ Zalo | Zalo Hub is allowed for the key (default), or the chosen alternative connection is healthy |
| ③ | Đăng nhập số Zalo | The QR login reports `connected`, and the number has a WordPress owner |
| ④ | Chọn Agent Guru | The number is bound to an Agent Guru (default preselected) with auto-reply on or deliberately off |

Release checklist for a channel package:

- [ ] The same four steps (labels, order, five states) render on every host that exposes the channel: Channel Gateway dashboard, `/crm/`, `/gpt/crm/`, wp-admin "Bắt đầu".
- [ ] On a clean site, a site admin completes ①–④ without copying an ID and without leaving the stepper.
- [ ] A message sent to the new number lands in the CRM Inbox and is answered by the Agent Guru chosen in ④.
- [ ] Non-admin personas see read-only or "Không có quyền" states, never a working save button.
- [ ] Per-item settings open in the app's `ActionSheet` (R-SETTINGS-4L-7).
- [ ] The host's browser self-check (`core/channel-gateway/docs/tools/*selfcheck.js`) reports no FAIL on the step rows.
- [ ] Knowledge capture through the channel (R-S4-9) is either evidenced end to end or explicitly listed as a known gap.
