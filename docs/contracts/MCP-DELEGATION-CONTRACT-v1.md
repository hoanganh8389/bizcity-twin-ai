# MCP Delegation + Bridge Contract v1 — `bizcity-mcp-bridge@1.0.0`

> **Rule:** [R-MCP-OAUTH-ID.6](../rules/PHASE-0-RULE-MCP-OAUTH-IDENTITY.md) (delegated identity, zero extra setup) · **Axis:** [R-TWIN-AGENT-AXIS](../rules/PHASE-0-RULE-TWIN-AGENT-AXIS.md) R-TAA-5 v1.3 · **Phase:** [PHASE-0.88](../../core/channel-gateway/docs/PHASE-0.88-MCP-ONE-STANDARD/01-MASTER-PLAN.md) L3-1/L3-2 · **Machine form:** [BIZCITY-MCP-STANDARD-v1.json](BIZCITY-MCP-STANDARD-v1.json) `bridge`, `modes`
> **Status:** v1.0.0 · 2026-10-01 · **coded + unit** (site, Hub, cell; not yet runtime-proven) · **Fixtures:** `zalo-hub/contracts/fixtures/mcp/bridge.tools_list.owner.json`, `bridge.tools_call.sales_summary.json`, `bridge.denied.json`, `write.idempotency.json`

## 1. Hops

| Hop | Route | Credential | Owner |
|---|---|---|---|
| cell → Hub | `POST /wp-json/bizcity/v1/zalo-hub/mcp?key_id=&account_id=` | `Bearer CELL_SECRET` + C-4 gate (`guru_gate_s81`: active zalo_hub number of that key on that cell) | Hub `class-router-zalo-hub-mcp-relay-s88.php` |
| Hub → site | `POST /wp-json/bizcity-channel/v1/zalo-bridge/mcp?account_id=` | the number's callback Bearer token (same channel as packs, owner-capture, turn-complete — Q88-4) | site `class-zalo-mcp-bridge-rest.php` |

Body: one JSON-RPC 2.0 object (no batch), forwarded byte-for-byte by the Hub. Headers: `X-BizCity-Principal` (user_hash, 64 hex, required), `X-BizCity-Turn-Id` (`[A-Za-z0-9._:-]`, ≤ 120), `X-BizCity-Timeout-Ms` (clamped 1000..8000; Hub waits clamp + 1 s). No MCP session (stateless POST).

## 2. Identity on the site

1. Token check (hash_equals) → else 401, JSONL `delegation_bad_token`.
2. `BizCity_Zalo_Agent_Principals::by_hash(account_id, user_hash)` must return an ACTIVE `owner` or `staff` → else 401 `MCP_DELEGATION_PRINCIPAL_UNBOUND`. The site never trusts a user id from the request.
3. `wp_set_current_user(user_id)` for the request (restored after); context `{auth_method:'delegated', client_id:'zalo-cell', key_id:0, user_id, role, modes, account_id, turn_id, allowed_notebook_ids: notebooks OWNED by the user}`.
4. Scopes = `scopes_of(modes)` ∩ supported (`BizCity_MCP_Delegation`) → none ⇒ 401 `MCP_SCOPE_DENIED` (`delegation_no_scope`).
5. Tool gate (Q88-6): `_meta.bizcity.mode` ∈ modes (`*business` = any business mode); tools without a mode are not offered; deprecated aliases are hidden from `tools/list`; the admin allowlist for external clients does not apply.

Customers never reach this route: the cell sends no principal on customer turns.

## 3. Budgets and fallbacks

| Call | Cap | On failure |
|---|---|---|
| `tools/list` | 3 s, cached 60 s per (key, account, principal), failure remembered 15 s | canonical tools use packs |
| read `tools/call` / `resources/read` | 5 s | pack copy, `degraded:true`, pack `as_of` |
| write `tools/call` | 8 s | "đang xử lý"; background retries with the same `_meta.idempotency_key` (20 s, 60 s); site replays the stored result (`write.idempotency.json`) |

## 4. Errors

Hub: R-ERROR-UX `{ok:false, code, message, hint, help_code}` — `invalid_principal` 400, `invalid_body` 400, `body_too_large` 413, `rate_limited` 429 (120/min/key), `callback_unreachable` 502, `site_mcp_unsupported` 502, `site_mcp_invalid` 502, `site_timeout` 504. Site transport: HTTP 401/400 + JSON-RPC `-32000` with `data.mcp_code`. Tool: JSON-RPC result `isError:true`, `structuredContent.code` (`MCP_MODE_NOT_ALLOWED`, `MCP_SCOPE_DENIED`, `MCP_WRITE_IN_PROGRESS`, …).

## 5. Hardening (wave 6, L3-7)

A Hub-signed Ed25519 per-turn token (claims `iss, aud, key_id, account_id, principal, role, modes, turn_id, jti, iat, exp ≤ 60 s`, key shared with NV-2) is added on top of §2.1 once NV-2 is decided. §2.2–2.5 do not change.
