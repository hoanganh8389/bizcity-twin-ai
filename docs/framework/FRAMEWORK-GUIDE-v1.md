# BizCity Twin Framework Guide v1

> Status: Orientation guide
> Scope: `bizcity-twin-ai`, active satellite plugins, `bizcity-llm-router`
> Audience: framework developers, extension authors, reviewers, operators
> This guide is a navigation layer. It does not override the canonical rules.

## 1. Read This First

Use this priority order when documents disagree:

1. **Ownership and topology:** [PHASE-0-RULE-BRAIN-UNIFICATION.md](../rules/PHASE-0-RULE-BRAIN-UNIFICATION.md)
2. **Cross-cutting rules:** [PHASE-0-CANON.md](../rules/PHASE-0-CANON.md) and the linked Tier 0/Tier 1 rule documents
3. **Stable public API:** [PUBLIC-CONTRACTS-v1.md](../contracts/PUBLIC-CONTRACTS-v1.md)
4. **Extension conventions:** [HOOKS.md](../extension/HOOKS.md), [getting-started.md](../getting-started.md), and [sub-plugin-quickstart.md](../extending/sub-plugin-quickstart.md)
5. **Feature roadmap and current implementation status:** the applicable `PHASE-*` document, especially [PHASE-1.25](roadmaps/PHASE-1.25-PIAPI-IMAGE-GATEWAY-PLUGIN-FRAMEWORK-AUDIT.md)

A roadmap explains delivery state. It does not create a new architectural authority.
A helper or existing code path is not automatically a public contract.

## 1.1 Find Your Work Path

| You are trying to... | Start with | Required boundary |
|---|---|---|
| Add an LLM/Search/Video/Astro/PiAPI feature | [API catalog](../api/README.md) | Existing client wrapper first; no direct provider HTTP |
| Add a browser or SPA capability | [Sub-plugin quickstart](../extending/sub-plugin-quickstart.md) | Same-origin REST/AJAX + nonce, then PHP wrapper |
| Add a Tool/Agent/Skill/Channel/Adapter | [Agent/tool recipe](../extending/agent-tool-recipe.md) and [HOOKS.md](../extension/HOOKS.md) | Typed contract or explicit legacy adapter |
| Receive a channel message | [Channel unify rule](../rules/PHASE-0-RULE-CHANNEL-UNIFY.md) | Normalized envelope + identity tuple + zone guard |
| Read/write KG or memory | [Brain Unification](../rules/PHASE-0-RULE-BRAIN-UNIFICATION.md) | Facade/service only; no direct KG table access |
| Add a REST/AJAX error | [Error UX rule](../rules/PHASE-0-RULE-ERROR-UX.md) | `code`, `message`, `hint`, `help_code` |
| Add a mutation, queue, or external side effect | [Public contracts](../contracts/PUBLIC-CONTRACTS-v1.md) | Permission, idempotency, trace, retry, outcome evidence |
| Add or change a table/column/index | [Diagnostics changelog rule](../diagnostics/PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md) | R-DCL + schema registry + provisioner + DDV |
| Change a loader or bootstrap | [Phase 1.23 loader roadmap](../roadmaps/PHASE-1.23-R-PERF-LOADER.md) | Surface gate + focused memory/load evidence |
| Prepare a release | [Phase 1.24 readiness](../roadmaps/PHASE-1.24-FRAMEWORK-ADOPTION-RELEASE-READINESS.md) | CI, runtime probes, registry, SDK, schema and residual-risk scorecard |

When two rows appear to apply, follow both boundaries. The more restrictive
security, identity, storage, or runtime rule wins.

## 2. The One-Sentence Architecture

BizCity Twin is a **Self-hosted Twin Runtime using the BizCity Managed AI Gateway**:

- WordPress client owns orchestration, tenant data, identity context, channel state, memory, KG, CRM, scheduler, and local evidence.
- BizCity Gateway owns provider secrets, model policy, billing, quota, entitlement, key identity, and managed provider execution.
- Extensions consume versioned contracts and must not create a parallel brain, gateway, identity system, or billing ledger.

The old identifier `R-GW-8` remains valid. “Standalone client” describes deployment topology only; it does not mean provider-independent AI capability.

## 3. Ownership Map

| Responsibility | Owner | Correct extension boundary |
|---|---|---|
| Focus, intent gating, local orchestration | `bizcity-twin-ai` client | Twin Kernel interfaces and local hooks |
| Tenant data and state | Current WordPress blog/shard | `$wpdb->prefix`, canonical facades, R-MSDB |
| LLM/Search/Video/Astro/PiAPI provider execution | `bizcity-llm-router` Hub | Client wrapper with Bearer `biz-xxx` |
| Provider credentials | Hub only | Never store/read provider keys on client |
| User/member/channel identity | Canonical client/Gateway identity services | Preserve `(platform, account_id, user_id, chat_id)` |
| Billing/quota/plan | Authenticated API key on Hub | Never infer from `user_id` alone |
| Runtime evidence | Diagnostics on the WordPress site | Disk, Loader, Runtime layers |
| Stable extension contract | JSON Schema + PHP/TypeScript interfaces | No direct dependency on private class internals |

## 4. Choose the Correct Path

### 4.1 Need an LLM call

```text
Extension -> BizCity_LLM_Client -> same-origin proxy when FE is involved
          -> BizCity Managed Gateway -> provider
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

### Hub

- Authenticate the exact Bearer key and preserve `key_id`.
- Resolve plan/quota/entitlement from that key, not `user_id` alone.
- Keep provider credentials server-side.
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
