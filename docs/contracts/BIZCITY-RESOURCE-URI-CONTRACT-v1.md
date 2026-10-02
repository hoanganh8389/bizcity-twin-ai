# BizCity Resource URI Contract v1 — `bizcity-resource-uri@1.0.0`

> **Phase:** [PHASE-0.88](../../core/channel-gateway/docs/PHASE-0.88-MCP-ONE-STANDARD/20-LAYER-2-RESOURCES.md) L2 · **Machine form:** [BIZCITY-MCP-STANDARD-v1.json](BIZCITY-MCP-STANDARD-v1.json) `resources` · **Packs:** [PROJECTION-PACK-CONTRACT-v1](PROJECTION-PACK-CONTRACT-v1.md)
> **Status:** v1.0.0 · 2026-10-01 · **coded + unit** (site `class-mcp-resource-uri.php` + `class-mcp-resource-service.php`; cell `resource-uri.ts` + `resources.ts`) · **Fixture:** `zalo-hub/contracts/fixtures/mcp/resources.json`

## 1. URIs

| Template | mimeType | Mode |
|---|---|---|
| `bizcity://guru/{ref}` | application/json | principal of the bound number |
| `bizcity://guru/{ref}/notebook/{id}` | text/markdown | as above |
| `bizcity://notebook/{id}` | application/json | `notebook`, own notebooks only |
| `bizcity://notebook/{id}/passage/{pid}` | text/markdown | `notebook`, own notebooks only |
| `bizcity://customer/{contact_id}/context` | application/json | `customers` (lead/agent: assigned only, D-TAA-7) |
| `bizcity://product/catalog`, `bizcity://product/{id}` | application/json | `stock` (cell cache readable by every role: pack `catalog` is audience `base`) |
| `bizcity://pack/{kind}` | application/json | mode of that pack kind |

Rules: logical and tenant-free (no blog id, no host); integer ids without leading zeros; no trailing slash, query, fragment or `..`. Legacy: `twin-source://nb/{nb}/p/{p}` ↔ `bizcity://notebook/{nb}/passage/{p}`; `notebook://<slug>` is still read by `citation-pack@1.1` but has no URI mapping.

## 2. Methods

`resources/list` (filtered by principal, cursor/`nextCursor`), `resources/read` (`contents[{uri, mimeType, text}]`; not found and not allowed are the same JSON-RPC error `-32002 "Resource not found"`), `resources/templates/list`, `resources/subscribe|unsubscribe` (session-bound; delegated calls have no session), notifications `notifications/resources/updated {uri}` and `notifications/resources/list_changed` from the same hooks that send C-10 / packs invalidate. `annotations {audience:["assistant"], lastModified}` = the pack `as_of`.

## 3. Pack = cache of the resource

`bizcity://pack/{kind}` reads through the same exporter `page()` as the `zalo-bridge/packs` route, so the cell's pack copy and `resources/read` hold the same content for the same principal. Pack list entries carry `uri` and invalidate payloads carry `uris[]` (additive, optional). The cell resolves URIs cache-first; only owner/staff turns fall back to MCP `resources/read` (5 s); customer turns never call the Hub.

## 4. Prompts

`prompts/list` returns only the Guru(s) bound to the principal's number(s); `prompts/get {name:"guru/<ref>"}` returns the instruction as a `user` message plus an embedded `bizcity://guru/<ref>` resource. Engine rules (C-8) never go through MCP.
