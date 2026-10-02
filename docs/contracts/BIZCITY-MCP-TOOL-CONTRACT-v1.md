# BizCity MCP Tool Contract v1 — `bizcity-mcp-tool@1.0.0`

> **Phase:** [PHASE-0.88](../../core/channel-gateway/docs/PHASE-0.88-MCP-ONE-STANDARD/10-LAYER-1-TOOLS.md) L1 · **Machine form (catalog):** [BIZCITY-MCP-STANDARD-v1.json](BIZCITY-MCP-STANDARD-v1.json) `tools` · **Validator:** `bin/validate-mcp-standard.mjs`
> **Status:** v1.0.0 · 2026-10-01 · **coded + unit** (17 tools in `core/mcp`; cell consumes) · **Fixtures:** `zalo-hub/contracts/fixtures/mcp/bridge.tools_list.owner.json`, `bridge.tools_call.sales_summary.json`, `confirm.flow.json`, `inventory.reserve.json`, `write.idempotency.json`

## 1. One tool = one capability, for every client

Every action or knowledge capability of the plugin is one MCP tool in `core/mcp`, used with the same name, schema and scope by the zalo-hub cell (delegated), ChatGPT and Claude (OAuth). Canonical names use dots (`crm.customer.lookup`, D-MCP-1); the cell exposes `_meta.bizcity.llm_alias` to the model (Q88-5 keeps existing cell keys such as `biz_sales`). Handlers are thin calls into existing business code; they run as the resolved WordPress user and re-check CRM/Woo rights; they never call a model or provider.

## 2. Descriptor (`tools/list`)

Required: `name`, `title`, `description` (Vietnamese, for an LLM), `inputSchema`, `outputSchema` (data.as_of required), `annotations {readOnlyHint, destructiveHint, idempotentHint, openWorldHint}`, `_meta.bizcity {contract, mode, scopes, confirm: never|always, llm_alias, fallback_pack, deprecated_alias, since}`. A deprecated alias additionally carries `deprecated_alias_of` and stays callable for one version. Site code marks each registration with `// @mcp bizcity-mcp-standard@1 tool <name>`.

## 3. Result

`content[]` text + `isError` + `structuredContent` = the core/mcp envelope `{success, complete, message, data, meta}`; success `data.as_of` is always present (UTC ISO 8601). Errors: `{success:false, code, message, hint, help_code, error{code, retryable, details}}` with codes from `BizCity_MCP_Error`.

## 4. Confirm before write (`confirm: always`, Q88-1)

Call without `confirm_token` → nothing is written; `data {status:"needs_confirmation", preview, confirm_token, expires_at}`. Token: one-time, 900 s, bound to tool + client + user + `args_hash` (sha256 of the canonical JSON of the arguments without `confirm_token`, keys sorted). Same arguments + token → write, `data.status:"done"`. Changed arguments → `MCP_CONFIRM_ARGS_CHANGED` (token kept); reused/expired/other identity → `MCP_CONFIRM_INVALID`. The cell keeps no state; a persona rule makes the agent read the preview back and ask.

## 5. Write idempotency

Write calls carrying `params._meta.idempotency_key` are executed at most once per (blog, user, tool, key) within 900 s: a repeat returns the stored envelope with `meta.replayed:true`; while running → `MCP_WRITE_IN_PROGRESS` (retryable). Transients only, no table.

## 6. Holds (Q88-2)

`order.create` / `inventory.reserve` create a WooCommerce `pending` order (meta `_bizcity_mcp_hold`) and reserve stock with Woo's `ReserveStock` for `hold_minutes` ∈ [5, `woocommerce_hold_stock_minutes` or 60]; Woo's own cron releases unpaid holds. `inventory.check` returns `available = stock − reserved`. No reservation table.

## 7. Who may call writes (D-MCP-5)

Only the owner and staff who own an agent (UID bound, role staff), within the scopes of their modes and CRM role; customers have no write tool; no owner tool in groups (D-TAA-5). External clients get new tools OFF by default until the admin enables them.
