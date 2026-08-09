# BizCity Twin Public Contracts v1

Status: Stable
Catalog source: core/twin-core/contracts/schema/public/v1/contract-catalog.json
SemVer policy: semver

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
| channel-payload | 1.0.0 | 1.x | 1.x | 3 minors |
| runtime-execution-policy | 1.0.0 | 1.x | 1.x | 3 minors |

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
- SDK interfaces in core/twin-core/contracts/content-contracts.php.
- Manifest schema in core/twin-core/contracts/schema/manifest.schema.json.

Internal API (no compatibility guarantee):

- Class internals under core/*/includes except explicit interfaces.
- Private helper functions and cache internals.
- Diagnostics probes and internal cron wiring.
- Feature-specific SQL columns not exposed by public schemas.

## Contract Tests

Run:

- node core/twin-core/contracts/tests/run-contract-tests.mjs

Expected output:

- CONTRACT TESTS PASS (11 contracts)
