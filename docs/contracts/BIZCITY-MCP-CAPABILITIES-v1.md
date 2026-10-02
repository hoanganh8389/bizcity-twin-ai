# BizCity MCP Capabilities Contract v1 — `bizcity-mcp-capabilities@1.0.0`

> **Phase:** [PHASE-0.88](../../core/channel-gateway/docs/PHASE-0.88-MCP-ONE-STANDARD/30-LAYER-3-CONFIG-CONTROL.md) L3-3 / L3-5 · **Fixture:** `zalo-hub/contracts/fixtures/mcp/capabilities.manifest.json`
> **Status:** v1.0.0 · 2026-10-01 · **coded + unit** (cell `mcp-capabilities-manifest.ts` + `mcp-health.ts`; site route `agent-capabilities` + Bot Studio line "Agent dùng được"); not yet runtime-proven

## 1. What it answers

"What can this person's agent use right now?" — per number and per principal (owner, each staff member): modes, modes the plan does not include, canonical MCP tools the site currently offers that person, and a Vietnamese label line ("Agent dùng được: Doanh số, Đơn hàng, Khách hàng, Sổ ghi chú").

## 2. Where it comes from (no new route, no table)

The cell computes it from data it already holds: the config bundle (`owner_agent` principal + `staff[]` modes), the C-1 tenant snapshot (`owner_agent.<mode>` plan gates) and its cached site `tools/list` per principal (`tools: null` if never listed — the manifest never forces a site call). It is returned as field `mcp` of the existing `GET /wp/brain/overview`, which the Hub already relays to the site (C-7 read path). Overview is per key, so `mcp` is an **array with one manifest per number**, each carrying `account_id` (the fixture shows one element). The site reads it with `GET bizcity-channel/v1/bot/policy/{binding_id}/agent-capabilities` (accepts one object or a list) and shows only `{labels, plan_denied}` + site reachability; an older cell without `mcp` ⇒ nothing shown. Principals are identified by role + `principal_ref` (first 8 hex of `user_hash`); raw UIDs and full hashes never appear.

## 3. Health

`GET /wp/providers/health` (C-6) carries `mcp {calls_5m, failures_5m, timeouts_5m, last_error_code, pending_late_writes}` from in-memory counters.

## 4. Changing what an agent can use

There is no new switch: modes are granted in Bot Studio / CRM (`agent-mode-access@1`), plans on the Hub (C-1). The manifest only reflects them.
