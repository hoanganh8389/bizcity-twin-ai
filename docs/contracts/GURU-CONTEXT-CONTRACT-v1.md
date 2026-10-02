# Guru Context Contract v1 — `bizcity-guru-context/1.0`

> **Rule:** [R-GURU-SOURCE](../rules/PHASE-0-RULE-GURU-CONTEXT-SOURCE.md) (Tier 0). **Design + checklist:** [PHASE-0.80 doc 24](../../core/channel-gateway/docs/PHASE-0.80-LIBE-BRIDGE/24-GURU-ONE-SOURCE-CONTEXT-API.md).
> **Scope class:** `domain_runtime` — internal service contract (site ↔ Router Hub ↔ zalo-hub cell). **Not public** (R-GP-2).
> **Owner:** `core/channel-gateway` (resolver + site routes) · `bizcity-llm-router` (Hub routes) · `zalo-hub` (consumer).
> **Status:** v1.0 · 2026-09-26 · fixtures `zalo-hub/contracts/guru-context/{guru_profile,guru_context}.json` + own `CHECKSUMS.json` (GS-7); cell, site and Hub tests read the same files.

## 1. Parties and routes

| Hop | Method + route | Auth | Purpose |
|---|---|---|---|
| cell → Hub | `GET /wp-json/bizcity/v1/zalo-hub/guru/{ref}?key_id={id}&account_id={bridge_id}` (+ `If-None-Match`) | `Authorization: Bearer <CELL_SECRET>` + `X-BizCity-Cell-Id` | Guru **profile**: identity, instruction, scope, compose |
| cell → Hub | `POST /wp-json/bizcity/v1/zalo-hub/guru/{ref}/context` | same | **Turn context** (`prompt`) for one question. POST so the customer text is never in a URL; read-only, idempotent |
| Hub → site | `GET /wp-json/bizcity-channel/v1/zalo-bridge/guru-profile?account_id=&ref=` | `Authorization: Bearer <per-account callback token>` | forwarded profile |
| Hub → site | `POST /wp-json/bizcity-channel/v1/zalo-bridge/guru-context` | same | forwarded turn context |
| PHP (zca numbers) | `BizCity_Guru_Context_Resolver::resolve()` in-process | — | same result shape, no HTTP |

`{ref}`: `guru:0` (the tenant's default Guru, always resolvable) or `guru:<character_id>` of **this** site. The Hub accepts a request only when `account_id` is an ACTIVE `zalo_hub` number of `key_id` hosted on the calling cell; the site accepts only when the Bearer matches that number's callback token **and `ref` is the Guru that answers that number** (the bound Guru as `guru:<id>`, or `guru:0` when the number uses the default Guru). Any other ref ⇒ 404 `guru_not_found`, so a cell can never enumerate the site's Gurus (least privilege, R-GP-3).

## 2. Request (context)

```json
{ "key_id": 123, "account_id": "1711111111111", "thread_id": "8123456789", "query": "Shop có ship ra Đà Nẵng không?",
  "include_instruction": false, "max_blocks": 5, "max_chars": 3000 }
```
`thread_id` is the Zalo thread id (same value the cell already sends as `inbound_forward.conversation_id`, same trust boundary); the site needs it to find the CRM contact for the customer block. `query` ≤ 2 000 chars. Caller limits are capped by the Guru scope.

## 3. Response (profile and context share one envelope)

```json
{
  "contract": "bizcity-guru-context/1.0",
  "guru":        { "ref": "guru:17", "is_default": false, "name": "Tư vấn viên", "version": 12, "etag": "g17-v12" },
  "instruction": { "source": "guru", "text": "…", "faq": [ { "q": "…", "a": "…" } ], "version": 12 },
  "prompt":      { "blocks": [ { "kind": "knowledge", "label": "Quick FAQ", "ref": "faq:3", "text": "…" },
                               { "kind": "contact",   "label": "Customer profile", "text": "…" } ],
                   "chars": 842, "truncated": false },
  "scope":       { "knowledge": "base", "max_context_chars": 3000, "max_blocks": 5, "contact_block": "on", "history_limit": 20 },
  "compose":     { "prefer": "guru", "engine_rules": "always" },
  "cache":       { "profile_ttl_s": 300 },
  "generated_at": "2026-09-26T10:00:00Z"
}
```

| Field | Rule |
|---|---|
| `instruction` | Present in profile; in context only when `include_instruction=true`. `source ∈ {guru, default_guru}`. Never contains turn data. |
| `prompt` | Present in context only (profile: omitted). `blocks[].kind ∈ {knowledge, contact}`. Never contains the instruction. The engine wraps it as external data. |
| `scope` | Effective scope after Guru settings (R-GS-3). `knowledge ∈ {base, base+notebooks}`. |
| `compose.prefer` | `guru` or `engine_default`. `engine_rules` is always `always` (engine safety rules cannot be dropped). |
| `guru.version`/`etag` | Monotonic per Guru edit; `304 Not Modified` on matching `If-None-Match`. |

## 4. Default Guru instruction template (gate 0, deterministic)

```
Bạn là trợ lý chăm sóc khách hàng của {site_name}{, {site_tagline}}.
{if shop} {site_name} bán hàng trực tuyến; hỏi về sản phẩm, giá, giao hàng thì trả lời theo thông tin đã có, không bịa.
Xưng "em", gọi khách là "anh/chị". Trả lời ngắn gọn, lịch sự, tiếng Việt.
Không biết thì nói sẽ chuyển nhân viên hỗ trợ; không hứa điều chưa được xác nhận.
```
Inputs: `blogname`, `blogdescription`, WooCommerce product count > 0. No LLM, no other tenant data.

## 5. Errors (R-ERROR-UX, English text)

| HTTP | `code` | When |
|---|---|---|
| 401 | `unauthorized` | bad cell secret / callback token (no detail leaked) |
| 404 | `account_not_on_cell` | number not an active `zalo_hub` number of the key on this cell |
| 404 | `guru_not_found` | `ref` not a Guru of this site (never for `guru:0`) |
| 409 | `busy` | site resolver locked; retry |
| 502 | `site_guru_unsupported` | site client older than GS-4 |
| 502 | `callback_unreachable` | Hub cannot reach the site |

## 6. Versioning

Additive fields ⇒ same `1.x`. Removing/renaming a field, merging `instruction` and `prompt`, or changing composition semantics ⇒ `2.0` + entry in PHASE-0.80 `50 §D` + fixture checksum bump.

## 7. Implementation notes (2026-09-26, GS-1…GS-6 code + unit)

| Side | Code | Notes |
|---|---|---|
| site resolver | `core/channel-gateway/includes/bot/class-guru-context-resolver.php` (`BizCity_Guru_Context_Resolver`) | `profile()` / `context()` / `compose_system()`; gate 0 `default_character_id()` (option `bizcity_bot_default_character_id`); GS-2 `normalize_legacy_bindings()` (option `bizcity_guru_source_gs2_bindings_normalized`) runs before gate 0 is ever used; scope in `settings.bot.scope` |
| site REST | `plugins/bizcity-zalo-personal/includes/shared/class-zalo-bridge-rest.php` — `GET zalo-bridge/guru-profile`, `POST zalo-bridge/guru-context` | ETag + 304 |
| Hub | `bizcity-llm-router/includes/class-router-zalo-personal-bridge-rest.php` — `GET zalo-hub/guru/{ref}`, `POST zalo-hub/guru/{ref}/context` | profile cache 300 s per key+number+ref, context never cached, 600 context calls/min/key, site timeout 2 s (context) / 5 s (profile); traces hold hashes/sizes only |
| cell | `zalo-hub/src/wp-surface/guru-context-client.ts` + `agent-loop.ts` + `persona-prompt.ts` (`PromptMemory.guruContext`) | profile → persona (or "" for `engine_default`), prompt → `<noi_dung_ngoai>` section; fail open to the bundle persona |
| bundle | `config_bundle.agents[].guru_version` = profile **etag** (string); `agents[].ref` / `accounts[].agent_ref` use `guru:0` for the default Guru | additive, accepted by the cell schema |

### 7.1 `ref=auto` (console R8)
`GET zalo-bridge/guru-profile?account_id=&ref=auto` returns the profile of the Guru answering that number — still exactly one Guru (least privilege holds). Used by the Hub console R8, which does not know the bound ref; cells always send the concrete `agent_ref`.
