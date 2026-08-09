# BizCity Twin Contract Testing v1

## Why These Contracts Matter

Public contracts are the compatibility boundary between the self-hosted Twin
Runtime, third-party extensions, WordPress REST/SSE clients, and the managed
AI gateway.

They provide four practical benefits:

1. A plugin can implement against schemas without reading internal classes.
2. A client can reject malformed or unsafe payloads before rendering or acting.
3. A server can evolve internal storage without silently changing public behavior.
4. Security and reliability requirements become testable fields, not prose.

## Contract Roles

| Contract | Role | Primary consumers |
|---|---|---|
| event-envelope | Causal, traceable event transport | Event Bus, projectors, REST |
| tool-io-envelope | Tool execution input/output boundary | Twin Agent, tools, audit |
| mutation-contract | Server-to-client side-effect result | REST clients, workflow UI |
| citation-pack | Evidence and citation provenance | Twin GPT, TwinChat, renderers |
| error-envelope | User-safe actionable errors | REST, SSE, SDK clients |
| sse-events | Ordered streaming protocol | React/TypeScript SDK, chat UI |
| permission-scopes | Least-privilege capability declaration | Manifest, consent UI, guard |
| workflow-json | Versioned workflow interchange | Builder, scheduler, SDK |
| kg-adapter-payload | Knowledge source adapter boundary | KG adapters, ingestion |
| channel-payload | Inbound/outbound channel identity | Channel Gateway, adapters |
| runtime-execution-policy | Reliability and SRE policy | Tools, scheduler, channels |

## Test Layers

### Layer 1: Shape and Schema

Command:

```text
node core/twin-core/contracts/tests/run-contract-tests.mjs
```

Checks:

- Every catalog entry has a SemVer version.
- Every schema parses as JSON.
- Every contract has valid and invalid fixtures.
- Valid fixtures pass required fields, types, enums, ranges, and local refs.
- Invalid fixtures fail validation.
- Deprecation grace is between 2 and 3 minor versions.

### Layer 2: Manifest Security

Command in CI:

```text
php bin/bizcity-manifest-validate.php --plugin=examples/bizcity-reference-plugin
```

Checks:

- Permission names use the canonical scope syntax.
- Scope bindings reference declared permissions.
- Sensitive permissions declare approval gates.
- Secret references use vault-key syntax.
- Upload and network policy fields are shaped correctly.

### Layer 3: Runtime Discovery

WordPress integration smoke must verify:

- Reference plugin loads after the framework.
- `BizCity_Twin_Content_Registry::all()` returns one provider for each of the
  eight content capability groups.
- A provider with a wrong interface is rejected.
- Legacy array providers still load through their existing registry paths.

The reference fixture and registration hook live in:

- examples/bizcity-reference-plugin/bizcity-reference-plugin.php
- core/twin-core/includes/class-twin-content-registry.php

### Layer 4: Security Enforcement

Required cases:

- Undeclared permission is denied before `execute()`.
- Wrong tenant/site/user scope is denied.
- Sensitive action without approval is denied.
- Approved sensitive action reaches the provider.
- Registered extensions cannot self-grant a manifest permission through caller context before admin consent.
- Revoking a stored grant prevents the next execution without changing the extension manifest.
- Denied authorization does not poison a later retry with a new approval.
- Audit record contains trace/idempotency/status metadata only.
- Full arguments, secrets, tokens, and tenant content are absent from audit.
- Private IP literals and DNS-resolved private ranges are rejected by the shared URL policy.
- A registered manifest allow-host list is enforced before the HTTP client runs.
- Uploads exceeding size/MIME policy or missing required malware scan approval are rejected.

### Layer 5: Reliability

The current automated runtime baseline covers these cases at the Twin tool
boundary; WordPress integration tests should exercise the same cases against
the real object-cache and event environment:

- Same idempotency key returns the prior result and does not execute twice.
- Concurrent duplicate receives `execution_locked` or a replayed result.
- Transient/timeout/rate-limit/permanent errors map to the correct retry bucket.
- Exhausted work is written to the DLQ.
- Quota rejection and circuit-open responses occur before provider side effects.
- Half-open circuit probes are bounded by `half_open_max_calls`.
- Circuit breaker opens after the configured threshold.
- Trace ID is preserved across client, runtime, gateway, and provider calls.
- SLO counters measure success, latency, citation coverage, and tool errors.

Still required for full system coverage:

- Scheduler/channel/gateway-client retry and DLQ adapters.
- Distributed trace-header propagation through every downstream HTTP client.
- Persistent SLO aggregation and an operator-facing dashboard probe.

## CI Gates

The repository CI must run:

1. Public contract JSON parse and fixture tests.
2. TypeScript SDK compilation.
3. PHP syntax and PHP 7.4 compatibility checks.
4. Reference manifest validator on PHP 7.4, 8.1, and 8.2.
5. WordPress runtime smoke/diagnostics when the test database is available.

## Hybrid Ownership Model (CI and Diagnostics Probe)

The framework uses a hybrid testing model. Deterministic contract checks stay in CI.
Runtime contract behavior in a real WordPress lifecycle stays in diagnostics probes.

### CI-owned checks (deterministic contract gates)

These checks must stay in CI and block merge/release when failing:

1. Schema fixtures and contract invariants:
  - core/twin-core/contracts/tests/run-contract-tests.mjs
2. Caller regression audit against active code:
  - bin/framework-contract-audit.mjs
3. Plugin contract registry governance:
  - bin/validate-plugin-contract-registry.mjs
4. SDK release governance:
  - bin/validate-sdk-release.mjs
5. Central workflow gate:
  - .github/workflows/ci.yml

### Probe-owned checks (runtime contract evidence)

These checks belong to diagnostics because they require real WordPress runtime context,
including hook order, multisite context, options, loaders, and live plugin wiring:

1. Runtime fail-closed behavior
2. Loader and load-order verification
3. Real plugin wiring and capability boundary checks
4. Checks dependent on multisite, option scope, and lifecycle hooks

Canonical probe owner for this hybrid boundary:

- core/diagnostics/includes/probes/class-probe-framework-production-contract.php

### Rule of ownership

If a check can run as a deterministic file/process assertion without WordPress runtime,
it is CI-owned. If it requires real runtime lifecycle or environment state, it is
probe-owned.

## How A New Plugin Or Module Uses This Contract

For every new active package under `plugins/` or `modules/`, onboarding is valid only
after it appears in both the registry and the runtime evidence path.

### Step 1: Declare package in registry (appearance layer)

Add one row to the `plugins[]` or `modules[]` collection in
`docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json` with:

1. `id`, `path`, `kind`, `status`, `bootstrap`, `required_surfaces`
2. `manifest` path if the package is a reference/manifested extension
3. surfaces that map to its runtime boundary (for example: `error_envelope`,
   `channel_normalized`, `gateway_client`, `runtime_probe`)

Gate:

- `bin/validate-plugin-contract-registry.mjs` must pass in CI.

The registry is the discoverability layer, not a compliance pass. A package remains
`partial`, `fail`, or `review` until its declared surfaces have corresponding CI
and runtime evidence in the scorecard.

### Step 2: Pass deterministic contract gates (CI layer)

The package must not break central CI-owned contract gates:

1. `run-contract-tests.mjs`
2. `framework-contract-audit.mjs`
3. `validate-plugin-contract-registry.mjs`
4. `validate-sdk-release.mjs` when SDK-facing metadata/contracts are touched

### Step 3: Prove runtime contract behavior (probe layer)

The package must provide Disk/Loader/Runtime evidence through diagnostics probes.
At minimum, it must be covered by core production contract probes or package-specific
probes for its critical surfaces.

Canonical baseline probe:

- `core/diagnostics/includes/probes/class-probe-framework-production-contract.php`

## Contract Scoring Model For Plugin Compliance

This score evaluates plugin/module contract compliance readiness; it is not a product
feature score.

### Weighted dimensions

1. CI deterministic contract gates: 40%
2. Runtime probe evidence (Disk/Loader/Runtime): 30%
3. Security boundary compliance (permissions, secret boundary, fail-closed, error envelope): 20%
4. Adoption coverage of required surfaces in registry row: 10%

### Scoring formula

`score = (ci * 0.40) + (probe * 0.30) + (security * 0.20) + (surfaces * 0.10)`

Each component is normalized to 0..100 from observed PASS/PARTIAL/FAIL evidence.

### Grade thresholds

1. `pass`: score >= 85 and no release-blocker rule violated
2. `partial`: score 60..84 or no blocker but missing runtime evidence
3. `fail`: score < 60 or any release-blocker violated
4. `review`: insufficient evidence to score confidently

### Non-negotiable blocker rule

Even when the numeric score is high, a plugin cannot be treated as compliant if any
release-blocker class remains active (for example channel bypass, credential boundary
bypass, raw user-facing error envelope violations).

## Completion Rule

A contract is **release-ready** only when all are true:

- Schema, version, compatibility range, and deprecation policy are published.
- Valid and invalid fixtures pass in CI.
- At least one producer and one consumer use the contract at runtime.
- Security-sensitive contracts have allow and deny tests.
- Legacy compatibility behavior has a migration test.
- The contract is listed as Stable or explicitly marked Internal.

A framework release is **production-ready** only when no unchecked migration
item remains in the applicable security or reliability section, even if the
schema catalog itself is complete.
