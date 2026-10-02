# Projection Pack Contract v1 — `projection-pack@1.2.0`

> **Axis:** [R-TWIN-AGENT-AXIS](../rules/PHASE-0-RULE-TWIN-AGENT-AXIS.md) (R-TAA-5, R-TAA-6, R-TAA-8, R-TAA-14) · **Machine form:** [TWIN-AGENT-AXIS-v1.json](TWIN-AGENT-AXIS-v1.json) · **Permissions:** [`agent-mode-access@1`](AGENT-MODE-ACCESS-CONTRACT-v1.md)
> **Status:** v1.2.0 · 2026-10-01 · **coded + unit** (site exporters + `zalo-bridge/packs` route, Hub relay, cell pack store; not yet runtime-proven). 1.2 = PHASE-0.87 wave 2: `principal=<user_hash>` on list/page (owner or staff of the number), list entry `principal_role`; `customers` list entry may carry `scope: person` for a CRM lead/agent (D-TAA-7, per-person copy, no fallback to the shop copy). PHASE-0.88 L2 adds, additively: `uri` (`bizcity://…`) on each list entry (the pack = cache of that resource) and a new kind `catalog` (mode `stock`, audience `base`) — owned by PHASE-0.88, see its doc 20 · v1.1.0 · 2026-09-30 (each owner kind belongs to an agent mode; `owner_knowledge`/`notebook_meta` are per `user_id` — first `user_id`, daily notebooks included; freshness 60 s, D-TAA-3) · v1.0.0 · 2026-09-30 · design, no code yet · **Owner:** client exporters (`bizcity-twin-ai`); consumer: brain-core pack store (`zalo-hub/src/brain-core/packs/`).
> **Scope:** `framework_internal`. **Generalises:** the PHASE-0.81 C-4 notebook pack (`zalo-bridge/guru/{ref}/knowledge[/{nb}]`), which becomes pack kind `knowledge` without changing its wire format.

## 1. Why packs

A pack is the **cache and fallback of an MCP resource** (R-TAA-5 v1.4): the cell fills it from the site's `core/mcp` resources outside any reply turn. Customer and walk-in turns read only this local copy — no MCP call, no PHP on the hot path (R-PF-7, R-BC-3); owner/staff turns call MCP live and fall back to it. A thin client with read-only exporters that share the MCP handlers (R-TAA-6), and projections the data plane may hold (R-B2B2C §1.1).

## 2. Routes (same three hops as C-4)

| Hop | Route | Auth |
|---|---|---|
| cell → Hub | `GET {HUB}/zalo-hub/packs?key_id&account_id` (list) · `GET {HUB}/zalo-hub/packs/{kind}?key_id&account_id&cursor&limit` (pages) | Bearer `CELL_SECRET` (+ `x-bizcity-cell-id`) |
| Hub → site | `GET {site}/wp-json/bizcity-channel/v1/zalo-bridge/packs[/{kind}]` (URL derived from the number's `callback_url`, like `guru_site_url`) | Bearer per-account callback token (`hash_equals`) |
| site → Hub (invalidate) | `POST {HUB}/zalo-hub/packs/invalidate {key_id, account_id, kinds[]}` | Bearer site 1API key |
| Hub → cell (invalidate) | `POST /wp/packs/invalidate {account_id, kinds[]}` | Bearer `CELL_SECRET` + `X-BizCity-Key-Id` |

Hub relays only (rate limit and gate like the C-4 relay); it stores nothing.

## 3. List

```jsonc
{ "contract": "projection-pack@1.0.0", "account_id": "100001", "owner_user_id_hash": "…",
  "packs": [
    { "kind": "sales", "audience": "owner_agent", "version": "v-1a2b3c4d", "as_of": "2026-10-01T08:59:00Z", "bytes": 18234, "enabled": true },
    { "kind": "knowledge", "audience": "base", "version": "v-9f8e7d6c", "as_of": "…", "bytes": 410233, "enabled": true },
    { "kind": "astro_self", "audience": "owner_agent", "enabled": false, "reason": "source_plugin_missing" }
  ],
  "etag": "p-…" }
```
`304` on `If-None-Match`. `enabled:false` carries a reason (`source_plugin_missing`, `turned_off`, `no_owner_verified`, `not_leader`).

## 4. Page

```jsonc
{ "contract": "projection-pack@1.0.0", "kind": "sales", "version": "v-1a2b3c4d", "as_of": "…",
  "full": true, "next_cursor": null,
  "items": [ /* kind-specific rows, §5 */ ] }
```
`full:true` ⇒ the cell replaces the kind atomically after the last page (the C-4 rule). Page ≤ 200 items, ≤ 256 KB.

## 5. Kinds (v1)

| Kind | Audience | Item shape (summary) | Source (read-only) | Refresh trigger |
|---|---|---|---|---|
| `knowledge` | base | C-4 chunk `{chunk_id, source_id, source_title, text≤4000, hash, updated_at}` | KG-Hub passages of Guru-bound notebooks | existing C-4 invalidation |
| `owner_knowledge` | owner_agent | same as `knowledge` + `notebook_id` | owner's notebooks (capped) | notebook change |
| `notebook_meta` | owner_agent | `{notebook_id, title, day_key, workspace_id, source_count, updated_at}` | KG-Hub | notebook change |
| `sales` | owner_agent | `{grain:"day"\|"month", date, order_count, paid_count, gross, net, refunds, aov, currency}` 90 days + 12 months; top 20 products `{product_id, name, qty, gross}` | Woo (`woo_bizops` resolver / MCP `business.get_sales_metrics`) | order status change |
| `orders` | owner_agent | last 50 `{order_ref, date, total, status, customer_label_masked, items_count}` | MCP `commerce.list_orders` | order change |
| `customers` | owner_agent | top 200 `{contact_ref, name, phone_masked, orders, total_spent, last_order_at, crm_stage, last_contact_at}` | CRM `customer-360-team-view@1.2.0` + MCP `commerce.list_customers` | order / stage change |
| `stock` | owner_agent | `{product_id, name, stock_qty, status: low\|out}` | MCP `business.get_inventory_metrics` | stock change |
| `astro_self` | owner_agent | `{profile_ref, natal_summary, periods:[{from, to, highlights}]}` of the `is_self` profile | bizcoach `bccm_*` (R-COACHEE.4) — no FreeAstroAPI call from PHP (R-PF-2) | profile change |

## 6. Rules for exporters (client)

1. Read-only queries with cache; **no** LLM call, **no** embedding, no write (R-TAA-6).
2. Run as `owner_user_id` of the number, resolved on the server; check the same capability the source requires (`manage_woocommerce`, `can_read_contact_scope`, leader `Staff_Policy` for business kinds).
3. Mask PII: phone → `…` + last 3 digits; no email, no street address; names allowed.
4. Version = `'v-' . substr(sha1(count|max_id|sum_len|max_updated), 0, 8)` (content fingerprint, the C-4 lesson: never `updated_at` alone).
5. Auto-enable when the source exists; a switch per kind per number in Bot Studio (ActionSheet, R-SETTINGS-4L).
6. Invalidate on source change with a 60 s trailing debounce (pattern `class-zalo-hub-guru-invalidate.php`).

## 7. Rules for the cell store

1. Store per `(tenant, account_id, kind)`; atomic replace per version; keep the old copy on a failed pull.
2. Size cap per tenant (`knowledge_max_bytes` family), counted in bytes consistently (fixes the C-4 chars/bytes drift).
3. Reads check the turn's role: `owner_agent` kinds only for `role=owner` of the same `account_id` (R-TAA-8).
4. Tenant deletion or number unbinding ⇒ delete every pack of that scope.
5. Each read reports `{kind, version, as_of}` into the turn record (`packs_read`).

## 8. Errors

Site/Hub errors use `{ok:false, code, message, hint, help_code}`. Codes: `pack_kind_unknown`, `pack_disabled`, `owner_not_verified`, `forbidden_capability`, `source_unavailable`, `rate_limited`.
