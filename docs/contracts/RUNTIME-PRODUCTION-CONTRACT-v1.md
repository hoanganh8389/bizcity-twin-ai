# Runtime Production Contract v1

Status: Stable baseline
Schema source: core/twin-core/contracts/schema/public/v1/runtime-execution-policy.schema.json

## Required Runtime Guarantees

- Idempotency key is mandatory for mutation and external side effects.
- Retry policy is bucketed by error type.
- Dead-letter queue exists for exhausted retries.
- Concurrency lock prevents duplicate execution.
- Circuit breaker protects upstream failures.
- Backpressure and quota prevent overload.
- Timeout budgets are explicit per layer.
- Distributed trace id is propagated end-to-end.
- SLO targets are declared for reliability and quality.

## Standard Error Buckets for Retry

1. transient
2. rate_limited
3. timeout
4. permanent

## Core Metrics (SLO)

- success_rate_target
- p95_latency_ms
- citation_coverage_target
- tool_error_rate_max

## Enforcement Contract

- Runtime policy object must be present in module runtime config.
- Execution framework rejects operations missing idempotency key when required.
- Retry and DLQ behavior must follow policy config.
- Trace headers must be propagated to all downstream calls.

## Current Framework Wiring

- `BizCity_Twin_Tool_Registry::execute()` enforces idempotency replay
- protection for Twin agent tools, applies the shared retry buckets and timeout
	deadline, and emits metadata-only audit evidence.
- `BizCity_Twin_Runtime_Reliability` provides the v1 baseline for quota,
	circuit-breaker state (including half-open probe limits), metadata-only DLQ
	entries, and daily in-memory SLO counters.
- `BizCity_Twin_Mutation_Guard` provides the common mutation preflight and
	outcome audit boundary for controllers being migrated.
- `BizCity_Twin_Capability_Guard` enforces declared permission, scope, and
	approval decisions before tool execution.

The reliability baseline is currently enforced at the Twin tool boundary.
Scheduler, channel, gateway-client, and legacy mutation-controller migration
remains required before claiming system-wide production reliability.

The DLQ stores metadata only (`tool`, `trace_id`, `idempotency_key`, error code,
bucket, and attempts). It deliberately does not persist arguments, response
bodies, tokens, or tenant content. A queue consumer must resolve replay input
from its own protected source before replaying an idempotent operation.

## Contract Test

Run:

- node core/twin-core/contracts/tests/run-contract-tests.mjs

Validation includes:

- valid runtime policy fixture passes
- invalid runtime policy fixture fails
