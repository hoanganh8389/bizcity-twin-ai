# MCP Audit Before Implementation / Reflect

> **Date:** 2026-07-28
> **Module:** `core/mcp`
> **Source design:** `PHASE-MCP-01-TWIN-CLIENT-BRAIN-DOCUMENT-MCP-v2.md`
> **Scope:** backend contract, active services, schema, security boundaries, and remaining acceptance gaps.
> **Browser evidence update (2026-07-28):** ChatGPT now reports `BizCity MCP đã kết nối thành công` and successfully calls `brain.list_notebooks`, returning 3 ACL-filtered notebooks with passage/entity/relation counts.
> **Diagnostics update (2026-07-28):** `core.mcp.gateway` is PASS with the default 8-tool catalog ready. Earlier production `REST POST blocked` results are historical pre-deployment evidence; the current connected ChatGPT result proves the transport path is now usable. Document/render tools still require their explicit scopes and separate calls.

## 1. Active Reusable Services

| Concern | Canonical implementation | MCP usage |
|---|---|---|
| Notebook ACL/list | `BizCity_KG_Notebook_Service::list_for_user()` | `BizCity_MCP_Client_Scope_Resolver`, `brain.list_notebooks` |
| Graph RAG retrieval | `BizCity_KG_Retriever::instance()->ask()` | `brain.search`; MCP does not create a second retrieval pipeline |
| Passage hydration | `BizCity_KG_Content_Router::instance()->hydrate_passages()` | `brain.get_passage` direct path; fail-closed if unavailable |
| Citation validation | `bizcity_kg_validate_citations_in_json()` | `document.validate_draft`, render gate |
| DOCX schema renderer | `plugins/bizcity-doc/app/src/lib/document-builder.ts` / built `doc-document-builder.js` | `document.render_docx` returns validated browser handoff |
| PPTX schema renderer | `plugins/bizcity-doc/app/src/lib/presentation-builder.ts` / built `doc-presentation-builder.js` | `document.render_pptx` returns validated browser handoff |

## 2. Active MCP Surface

- REST namespace: `bizcity-mcp/v1`
- OAuth discovery: `/.well-known/oauth-protected-resource` và `/.well-known/oauth-authorization-server`
- OAuth bridge: dynamic client registration, authorization code + PKCE S256, token TTL 1 giờ
- POST endpoint: `/wp-json/bizcity-mcp/v1/mcp`
- GET endpoint: same route, bounded authenticated SSE replay when `MCP-Session-Id` is supplied; GET without a session returns `405` because discovery is performed through POST JSON-RPC
- DELETE endpoint: same route, authenticated session termination for the supplied `MCP-Session-Id`
- Health endpoint: `/wp-json/bizcity-mcp/v1/health`
- Methods: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`; lifecycle notifications return `202` without a JSON-RPC response
- Tools: 4 `brain.*` + 4 `document.*` by default; PHASE-0.54 có thể bật thêm `page.*`, `business.*`, Content Brain `content.*`, `content.create_draft` và Report Brain `report.*` theo feature flag/scope.
- Local credential table: `wp_{blog_id}_bizcity_mcp_api_keys`; OAuth token/code/client state dùng WordPress transient có TTL, không thêm bảng và không lưu plaintext token.
- OAuth token endpoint emits JSONL reason buckets (`tool_name=oauth.token`) for deterministic diagnostics in multishard deployments.
- Local scopes: `brain.read`, `document.context.build`, `document.validate`, `document.render.docx`, `document.render.pptx`

## 3. Schema Reality

MCP owns four tenant-prefixed tables:

- `bizcity_mcp_api_keys`
- `bizcity_mcp_retrieval_snapshots`
- `bizcity_mcp_context_packs`
- `bizcity_mcp_audit_log`

R-DCL is `core/diagnostics/changelog/core.mcp.json`, version `1.1.0`. R-CR registration is file-scope in `class-mcp-installer.php` before `dbDelta()`. Version `1.1.0` adds:

- context pack index `(user_id, created_at)`;
- audit indexes `trace_id` and `(user_id, created_at)`.

## 4. Security Findings Resolved

- Error mapper now uses a closed MCP error catalog; arbitrary `WP_Error` codes and messages are mapped to a generic internal error.
- MCP errors expose `code`, `message`, `hint`, and `help_code` without exposing stack traces, SQL, token, or raw internal error details.
- Rate limit is enforced per blog/client after successful authentication.
- Snapshot and context-pack reads require both `client_id` and `user_id` ownership; cross-client mismatch returns not-found semantics to reduce existence disclosure.
- Notebook ACL is rechecked when building/reading context packs and passages.
- Direct passage retrieval fails closed if canonical filestore hydration is unavailable.
- Audit stores only argument keys/counts, output keys, hashes, IDs, status, and duration; it does not store draft/content bodies or credentials.
- `brain.search` defaults to evidence-only response: full passage content remains in the server snapshot but is redacted from the response unless `include_full_content=true`.
- `brain.search` requires `deterministic=true` and `citation_mode=strict`; entity/relation options are part of the cache variant.
- Rollback flags are available: `BIZCITY_MCP_ENABLED`, `BIZCITY_MCP_BRAIN_TOOLS_ENABLED`, `BIZCITY_MCP_DOCUMENT_TOOLS_ENABLED`, `BIZCITY_MCP_RENDER_ENABLED`.
- Admin key management is available through same-origin `bizcity-channel/v1/mcp/keys`; list never exposes `key_hash`, issue returns plaintext once, and revoke targets one numeric row ID.
- Canonical retrieval score and `score_source` are preserved from keyword, vector, graph-relation, and hybrid paths; MCP does not fabricate rank scores.
- Stateful transport stores only client/user identity and bounded protocol events. Bearer credentials are never written to session state.
- `document.validate_draft` runs canonical citation validation plus deep checks for unsupported factual claims, deprecated-only evidence, proposals, and cross-identity mixing before render.
- TwinWeb customer surface `/gpt/mymcp/` now owns customer key creation/list/revoke, live initialize/tools/call/SSE probe, reconnect state, and per-user log display. Customer cannot choose notebook scope.
- Channel Gateway Twin GPT policy owns the live MCP scope: auto mode includes administrator-owned notebooks, Guru mode includes that Guru's attached notebooks, and `mcp_excluded_notebook_ids` removes selected IDs. An empty derived scope is fail-closed. `mcp_allowed_notebook_ids` remains a read-compatible legacy policy until the admin saves the new exclusion form; customer REST keys are bound to the live server policy.
- `BizCity_MCP_File_Logger` writes sanitized request evidence before DB audit to `uploads/sites/{blog_id}/bizcity-mcp-logs/YYYY-MM-DD.jsonl`, including validation counters and score-source counters.
- OAuth-only hosts such as ChatGPT now use the authorization-code bridge. Runtime scope authority for OAuth tokens is `grant_scopes ∩ supported_scopes` (feature flags), and auth boundary does not re-intersect with historical key scopes.
- When no active MCP key exists for the consenting user in the current blog context, `/oauth/token` auto-provisions a local active key using current consent scopes and notebook policy; this prevents `active_key_missing` disconnect loops without cross-blog fallback.

## 5. Diagnostics Evidence

Probe: `core.mcp.gateway` in `class-probe-mcp-gateway.php`.

The probe checks:

1. **Disk:** MCP bootstrap, brain/document services, registry, and controller exist.
2. **Loader:** classes and `bizcity-mcp/v1/mcp` route are loaded.
3. **Runtime:** OAuth JSONL contains a reason bucket for the current blog; expected tools match active feature flags; document dispatch reaches a real callable and returns `MCP_QUERY_INVALID` for empty input; audit row is written.

The probe implements the full `BizCity_Diagnostics_Probe` contract, including `cleanup(): void`, and returns `status=pass|fail|skip` rather than the obsolete boolean `pass` field.

### 5.1 Official MCP probe

Run the MCP-specific probe from the Diagnostics REST API:

```http
POST /wp-json/bizcity-diagnostics/v1/smoke/run
Content-Type: application/json

{"id":"core.mcp.gateway"}
```

The complete smoke suite is available at `POST /wp-json/bizcity-diagnostics/v1/smoke/run-all`.
`core.mcp.gateway` currently reports these 14 base checks; when `BIZCITY_MCP_PAGE_TOOLS_ENABLED=true`, it adds one Page Action validation check, and when `BIZCITY_MCP_BUSINESS_TOOLS_ENABLED=true`, it adds one Business Brain read-only dispatch check:

| # | Layer | Check |
|---:|---|---|
| 1 | Disk | MCP bootstrap, tool registry, Brain/Document services, session store, file logger, scope resolver, HTTP/OAuth/admin REST files exist. |
| 2 | Loader | MCP tool registry, HTTP controller, Brain/Document services, session/logger/OAuth/admin classes are loaded. |
| 3 | Loader | `bizcity-mcp/v1/mcp` REST route is registered. |
| 4 | Loader | OAuth authorize/token/dynamic registration routes are registered. |
| 5 | Runtime | OAuth JSONL contains at least one `tool_name=oauth.token` row with `evaluation.reason` for the current blog. |
| 6 | Runtime | `tools/list` contains all expected tools according to feature flags, 8 by default. |
| 7 | Runtime | `document.validate_draft` reaches a real callable and empty input returns `MCP_QUERY_INVALID`. |
| 8 | Runtime | The dispatch writes an audit row containing metadata/hash rather than sensitive payload. |
| 9 | Runtime | Session TTL and event retention are bounded; POST/GET/DELETE ownership is enforced. |
| 10 | Runtime | Twin GPT auto/Guru scope, exclusions, legacy sentinel and empty-scope fail-closed behavior match policy. |
| 11 | Loader | Admin MCP key list/issue/revoke routes are registered under `bizcity-channel/v1`. |
| 12 | Loader | TwinWeb customer MCP key/policy/log routes are registered under `bizcity-twinweb/v1`. |
| 13 | Disk | Canonical KG retriever and MCP score/`score_source` transport contract exist. |
| 14 | Disk | Optional parity fixture status is reported without changing MCP runtime acceptance. |

The Diagnostics screenshot on 2026-07-28 shows `PASS` and “MCP gateway + 8-tool catalog sẵn sàng”. The top-level `WARNING` label is the probe severity configured for this diagnostic category, not a failed child step. Optional Page Action and Business Brain checks are skipped while their flags remain false.

### 5.2 Live HTTP probe matrix

The internal probe does not replace external-client acceptance. Run these checks with a real OAuth client or MCP key:

| Flow | Endpoint/action | Expected result | Current evidence |
|---|---|---|---|
| OAuth metadata | GET protected-resource and authorization-server metadata | Valid issuer, registration, authorize and token URLs | ✅ consent reached |
| Registration | POST `/wp-json/bizcity-mcp/v1/oauth/register` | `201` and dynamic `client_id` | ✅ PASS |
| Token exchange | POST `/wp-json/bizcity-mcp/v1/oauth/token` | Valid token for correct code/PKCE; structured OAuth error for invalid request | ✅ handler reached; synthetic invalid request `400 invalid_request` |
| MCP initialize | POST `/wp-json/bizcity-mcp/v1/mcp` | JSON-RPC `initialize`, `MCP-Session-Id`, tools capability | ✅ ChatGPT connected |
| Lifecycle notification | POST `notifications/initialized` without `id` | HTTP `202` | ✅ protocol contract |
| Tool discovery | POST `tools/list` with same session | Default catalog has 8 tools | ✅ PASS |
| Notebook call | `tools/call` `brain.list_notebooks` | ACL-filtered notebook rows | ✅ ChatGPT returned 3 notebooks |
| Search call | `tools/call` `brain.search` | Immutable snapshot and strict citation IDs | 🧪 browser probe now runs after `brain.list_notebooks`; live evidence pending |
| Document flow | context pack → validate draft | Context pack and validation report | 🧪 manual test recipe below; requires document scopes and a non-empty search snapshot |
| SSE/reconnect | GET `/mcp` with session and `Last-Event-ID` | Owned stream and replay | 🧪 My MCP has an explicit reconnect test; live evidence pending |
| Termination | DELETE `/mcp` with session | Only the owned session is terminated | 🧪 My MCP stop action calls DELETE; live evidence pending |

The old `403 rest_forbidden` / `REST POST blocked` result belongs to the pre-deployment transport investigation. It must not be used as the current status after the connected ChatGPT evidence, but should remain in deployment history when diagnosing stale hosts.

Incident 2026-07-29 proved that consent can succeed while token exchange fails at `active_key_missing` in a tenant shard. Current runtime now self-heals by key auto-provision and logs deterministic reason buckets.

### 5.2.1 Manual document-flow test recipe

Run this through the same authenticated MCP session after `brain.search` returns a
non-empty `retrieval_snapshot_id` and at least one `allowed_citations` item:

```json
{
	"name": "document.build_context_pack",
	"arguments": {
		"retrieval_snapshot_id": "<snapshot from brain.search>",
		"citation_ids": ["<first allowed citation>"],
		"document_type": "document",
		"max_blocks": 3,
		"max_total_chars": 12000
	}
}
```

Expected result: `context_pack_id` is returned. Then call:

```json
{
	"name": "document.validate_draft",
	"arguments": {
		"context_pack_id": "<context_pack_id>",
		"draft_content": "Tóm tắt dựa trên evidence [<first allowed citation>].",
		"draft_format": "markdown"
	}
}
```

Expected result: `validation_id`, `valid`, `report` and `draft_hash`. A `403` means
the current key lacks document scope; a `422` means the real citation validator
rejected the draft and must be recorded as a validation result, not a transport
failure.

### 5.2.2 SSE/reconnect and termination test recipe

1. In My MCP, run **Test kết nối** and wait for `MCP-Session-Id` plus at least one SSE event.
2. Click **Test reconnect**. The client closes the stream, records the latest event ID, and reconnects with `Last-Event-ID`.
3. Confirm status returns to connected and reconnect count increments.
4. Click **Ngắt**. Expected result is `DELETE /mcp: PASS`; the session ID is cleared and the server no longer accepts the terminated session.
5. Record the browser timestamp, session outcome, reconnect count, and the corresponding sanitized JSONL evidence. Never record the bearer key or session secret in the evidence.

### 5.3 MCP capabilities catalog

The active default catalog exposes four Brain tools and four Document tools:

| Tool | Capability | Scope | Output/limit |
|---|---|---|---|
| `brain.list_notebooks` | Discover notebooks visible to the client, with query, cursor, counts and archive filter. | `brain.read` | ACL-filtered notebook metadata; empty policy fails closed. |
| `brain.search` | Canonical Graph RAG retrieval across selected allowed notebooks, with entities, relations, top-k and graph depth. | `brain.read` | Deterministic immutable snapshot, strict citation IDs and score provenance; no prose answer. |
| `brain.get_passage` | Resolve one cited passage from a snapshot or a strict `source_id` + `passage_id` pair. | `brain.read` | Hydrated full passage after ACL/content-router checks. |
| `brain.get_citation_pack` | Select and format cited evidence blocks from a retrieval snapshot for downstream LLM composition. | `brain.read` | Full evidence blocks, character limit and citation rules. |
| `document.build_context_pack` | Combine snapshots/citations into a bounded immutable document or presentation context. | `document.context.build` | `context_pack_id`, allowed citations, KG revisions and truncation metadata. |
| `document.validate_draft` | Validate Markdown/text/JSON draft citations and deep evidence support before rendering. | `document.validate` | `valid`, report, validation ID, draft hash and evidence counters. |
| `document.render_docx` | Validate a document schema and prepare the canonical browser DOCX exporter handoff. | `document.render.docx` | `client_handoff_ready`; PHP does not generate the binary. |
| `document.render_pptx` | Validate a presentation schema and prepare the canonical browser PPTX exporter handoff. | `document.render.pptx` | `client_handoff_ready`; PHP does not generate the binary. |

Operationally, MCP also provides notebook ACL/policy enforcement, deterministic snapshots, strict citation provenance, OAuth + PKCE for external hosts, bounded Streamable HTTP/SSE sessions, key lifecycle management, and sanitized JSONL/DB audit evidence. It is a read and document-grounding gateway: it does not publish content, mutate KG notebooks, or invent citations.

## 6. Remaining Acceptance Checks

These remain acceptance/deployment checks rather than unimplemented core code:

- Live external-client integration must verify `initialize -> tools/list -> tools/call` with one `MCP-Session-Id`, SSE replay with `Last-Event-ID`, DELETE termination, wrong-client rejection, and expiry behavior.
- Browser acceptance must verify the TwinWeb My MCP flow with a real web server: create key, handshake, long-lived SSE, forced stream close, automatic reconnect, log refresh, and owner-only key/log visibility.
- OAuth acceptance must verify discovery metadata, dynamic registration, login redirect, exact redirect URI validation, consent nonce, one-time authorization code, PKCE S256 success/failure, OAuth Bearer `initialize`, token expiry, and invalidation after the bound MCP key is revoked.
- OAuth acceptance must include JSONL evidence from `oauth.token` with reason bucket `token_issued` and at least one negative-path bucket (`redirect_uri_mismatch` or `pkce_verifier_mismatch`) to prove observability.
- Deploy/runtime gate check is mandatory before re-testing ChatGPT: ensure the running `mu-plugins/bizgpt-multisite.php` includes MCP bypass normalization for `route`, `rest_route`, and URL-decoded `REQUEST_URI`, and ensure `class-mcp-oauth.php` direct POST fallback (`maybe_serve_oauth_post_routes`) is present on the host.
- ChatGPT browser evidence now proves OAuth consent plus connected runtime and a successful `brain.list_notebooks` call. The TwinWeb My MCP browser probe now also runs `brain.search` when the current policy exposes a notebook and validates `retrieval_snapshot_id` + citation arrays; external-client evidence for that deeper call remains pending until the probe is run against the live site.
- The screenshot requested `brain.read`, `document.context.build`, and `document.validate`. Render tools are intentionally out of scope for that connector unless `document.render.docx` and `document.render.pptx` are also requested.
- Admin acceptance must verify Twin GPT policy changes affect newly issued customer keys and that an empty allowlist cannot read notebook data.
- Customer key scope uses the internal deny-all sentinel when the server policy is empty; this is intentionally distinct from legacy admin-issued empty allowlists.
- Deep claim-support thresholds need fixture-based tuning against real Vietnamese/English context packs; proposals are reported separately and do not block by themselves.
- PHP does not generate DOCX/PPTX binaries in this repository. Render tools return a validated `client_handoff_ready` schema package for the existing browser exporters. No server `artifact_id`, signed download URL, or SHA-256 file evidence can be claimed until a real artifact service is selected and integrated.
- The offline Claude–ChatGPT structural parity harness is present at `tests/mcp/parity/`; it deliberately compares snapshot/citation/score structure, not generated prose. Remote-client evidence is still a deployment check.
- The parity runner and server PHP lint require PHP CLI, which is not installed in the local Windows environment.

## 7. Validation Run on 2026-07-29

- VS Code diagnostics: no errors on the touched MCP, probe, changelog, and roadmap files.
- JSON parse: `core.mcp` changelog parsed successfully at version `1.1.0`.
- PHP 7.4 static scan: no union return types, nullsafe calls, `match`, or PHP 8-only string helpers in `core/mcp`.
- No `handler => null` document tools remain in the active registry.
- PHP CLI was unavailable on the local Windows environment; server-side `php -l` and runtime Diagnostics execution remain deployment checks.
- TwinWeb UI build passed after adding `MyMcpPage` and the customer MCP API client; the build output remains a single large bundle by the existing TwinWeb configuration.
- OAuth bridge implementation is present in `core/mcp/includes/class-mcp-oauth.php`; live ChatGPT connectivity is now evidenced, while PHP CLI-based parity/lint checks remain unavailable in the local Windows environment.
- Browser evidence on 2026-07-28 confirms the ChatGPT custom-plugin reached consent, connected to MCP, discovered/called the Brain surface, and received 3 policy-filtered notebooks. Render scopes were not part of that connector and remain untested there.
- TwinWeb UI build passed after adding the `brain.search` live probe: when a notebook is available, the browser validates `retrieval_snapshot_id`, `passages[]`, and `allowed_citations[]`; an empty notebook policy is reported as an explicit skip.
