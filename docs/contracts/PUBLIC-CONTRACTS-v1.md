# BizCity Twin Public Contracts v1

Status: Stable (catalog 1.5.0, 19 contracts)
Catalog source: core/twin-core/contracts/schema/public/v1/contract-catalog.json
SemVer policy: semver

## Enterprise Brain contract boundary

These public contracts are the extension boundary for the Enterprise Brain
direction. A producer or consumer must fit the shared spine: Channel Gateway
normalizes horizontal enterprise inputs, Vertical Brain Modes provide
contract-based capabilities, and KG Graph/Graph RAG supplies knowledge evidence.
Chatbot, GPT/Twin GPT, Profile, Landing Page, Automation, and Notes consume or
extend these contracts; they must not create parallel brains or siloed data
paths. MCP and AI tools remain governed by identity, tenant, permission, and
evidence contracts.

See [PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md](../rules/PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md),
[PHASE-0-RULE-BRAIN-UNIFICATION.md](../rules/PHASE-0-RULE-BRAIN-UNIFICATION.md),
and [PHASE-0-RULE-CONTEXT-BANK.md](../rules/PHASE-0-RULE-CONTEXT-BANK.md)
before adding a public contract.

## Stable Public API Surface

The following contracts are stable public API for plugin ecosystem integrations.

1. event-envelope
2. tool-io-envelope
3. mutation-contract
4. citation-pack
5. error-envelope
6. sse-events
7. permission-scopes
8. workflow-json
9. kg-adapter-payload
10. channel-payload
11. runtime-execution-policy
12. admin-navigation
13. diagnostics-verdict
14. zalo-personal-bridge
15. channel-diagnostics-record
16. context-bank-record
17. context-rollup-definition
18. context-relation
19. context-retrieval-pack

Each contract has:

- Dedicated JSON Schema.
- Dedicated contract version.
- SemVer validation in contract tests.
- Compatibility declaration in the catalog.
- Deprecation grace window enforced by tests.
- Valid and invalid fixtures validated by contract tests.

## Compatibility Matrix

| Contract | Current | Producer Range | Consumer Range | Deprecation Grace |
|---|---:|---|---|---:|
| event-envelope | 1.0.0 | 1.x | 1.x | 3 minors |
| tool-io-envelope | 1.0.0 | 1.x | 1.x | 3 minors |
| mutation-contract | 1.0.0 | 1.x | 1.x | 3 minors |
| citation-pack | 1.0.0 | 1.x | 1.x | 3 minors |
| error-envelope | 1.0.0 | 1.x | 1.x | 3 minors |
| sse-events | 1.0.0 | 1.x | 1.x | 3 minors |
| permission-scopes | 1.0.0 | 1.x | 1.x | 3 minors |
| workflow-json | 1.0.0 | 1.x | 1.x | 3 minors |
| kg-adapter-payload | 1.0.0 | 1.x | 1.x | 3 minors |
| channel-payload | 1.1.0 | 1.x | 1.x | 3 minors |
| runtime-execution-policy | 1.0.0 | 1.x | 1.x | 3 minors |
| admin-navigation | 1.0.0 | 1.x | 1.x | 3 minors |
| diagnostics-verdict | 1.0.0 | 1.x | 1.x | 3 minors |
| zalo-personal-bridge | 1.0.0 | 1.x | 1.x | 3 minors |
| channel-diagnostics-record | 1.0.0 | 1.x | 1.x | 3 minors |
| context-bank-record | 1.0.0 | 1.x | 1.x | 3 minors |
| context-rollup-definition | 1.0.0 | 1.x | 1.x | 3 minors |
| context-relation | 1.0.0 | 1.x | 1.x | 3 minors |
| context-retrieval-pack | 1.0.0 | 1.x | 1.x | 3 minors |

### Channel diagnostics contract

`channel-diagnostics-record` is the public management/observability contract
for every channel plugin and module. It requires exact tenant, channel, zone and
account scope, structured event/stage metadata, producer identity and separate
pipeline statuses:

```text
operational_logged
context_captured
ledger_indexed
kg_candidate
```

The canonical Zalo channel IDs are `zalo_bot`, `zalo_oa` and
`zalo_personal`. A generic `zalo` value is not accepted at the public boundary.
Each channel supports multiple accounts; readers must filter exact account
scope server-side. Runtime adoption remains pending until the CB-CH writer,
reader, shared UI and multi-account probes pass.

## Context Bank Contracts

The following contracts are now registered in the stable public catalog at
version 1.0.0. Their schemas and valid/invalid fixtures are covered by the
contract runner. Runtime producer/consumer adoption and Context Bank probes
remain separate pending gates:

1. `context-bank-record`
2. `context-rollup-definition`
3. `context-relation`
4. `context-retrieval-pack`
Evidence uses the existing stable `diagnostics-verdict` contract; no separate
`context-bank-verdict` contract is defined.

Until runtime adoption is proven, CLI/framework inventory must label these rows
`catalog=stable`, `runtime=pending`. Existing stable contracts such as
`channel-payload`, `event-envelope`, `kg-adapter-payload`, `citation-pack`,
`permission-scopes`, `runtime-execution-policy` and `diagnostics-verdict` remain
their required dependencies.

## Deprecation Policy

- A public contract field cannot be removed in the same major line.
- A field marked deprecated must remain supported for at least 3 minor versions.
- Breaking changes require a major version bump of that contract.
- New required fields are breaking changes and must wait for next major.
- New optional fields are minor changes and are backward-compatible.
- Contract tests must include one valid fixture and one invalid fixture per contract.

## Stable vs Internal Boundary

Stable API:

- JSON contract schemas under core/twin-core/contracts/schema/public/v1.
- SDK interfaces in core/twin-core/contracts/framework-contracts.php.
- `BizCity_Phone_Normalizer_Interface::normalize_vn()` — canonical phone identity contract for CRM/Woo/loyalty/channel sources.
- SDK interfaces in core/twin-core/contracts/content-contracts.php.
- Manifest schema in core/twin-core/contracts/schema/manifest.schema.json.
- Admin navigation schema in core/twin-core/contracts/schema/public/v1/admin-navigation.schema.json.

Internal API (no compatibility guarantee):

- Class internals under core/*/includes except explicit interfaces.
- Private helper functions and cache internals.
- Diagnostics probes and internal cron wiring.
- Feature-specific SQL columns not exposed by public schemas.

## Contract Tests

Run:

- node core/twin-core/contracts/tests/run-contract-tests.mjs

Expected output for the current stable catalog:

- CONTRACT TESTS PASS (19 contracts)
