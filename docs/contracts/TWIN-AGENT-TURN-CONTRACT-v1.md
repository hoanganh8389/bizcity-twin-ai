# Twin Agent Turn Contract v1 — `twin-agent-turn@1.2.0`

> **Axis:** [R-TWIN-AGENT-AXIS](../rules/PHASE-0-RULE-TWIN-AGENT-AXIS.md) (R-TAA-1, R-TAA-2, R-TAA-9, R-TAA-11, R-TAA-13, R-TAA-15) · **Machine form:** [TWIN-AGENT-AXIS-v1.json](TWIN-AGENT-AXIS-v1.json) · **Design guide:** [Surface Adapter Guide](../framework/TWIN-AGENT-SURFACE-ADAPTER-GUIDE-v1.md)
> **Status:** v1.2.0 · 2026-09-30 (Zalo principal = sender UID equal to "UID chủ tài khoản", 1-1; new §5 `owner-capture@1`) · v1.1.0 · 2026-09-30 (`modes` in the envelope; deep-analysis result identical on the three surfaces) · v1.0.0 · 2026-09-30 · design, no code yet · **Owner:** brain-core (`zalo-hub/src`); client side of the write-back: `core/twin-core/event-stream`.
> **Scope:** `framework_internal`. **Producers / consumers:** surface adapters → brain-core (input); brain-core → surface (frames); brain-core → Hub → site (write-back).
> **Fixtures (to create with the code):** `zalo-hub/contracts/fixtures/taa/{turn.zalo,turn.twinchat,turn.gpt,frames,turn-complete,deep-analysis-job}.json` + `CHECKSUMS.json` (same mechanism as `zalo-hub/contracts/`).

## 1. Input envelope `twin-agent-turn@1.0.0`

Built by the surface adapter; never by the model. One envelope per turn (a Zalo batch is one turn).

```jsonc
{
  "contract": "twin-agent-turn@1.0.0",
  "axis": "twin-agent-axis@1",
  "trace_id": "01J…",                       // minted by the adapter; reused by every frame, record and usage event
  "surface": "zalo_personal",               // zalo_personal | twinchat | gpt
  "tenant": { "key_id": 4560, "blog_id": 1 },
  "account_id": "100001",                   // zalo_personal: the number; web: the principal's bound number or "" 
  "principal": {                            // server-resolved, never from the browser/model
    "kind": "zalo_uid | wp_user",
    "ref": "sha256(…)[0:16]",               // hashed; raw Zalo UID/phone never in the envelope
    "wp_user_id": 12                        // web surfaces only
  },
  "role": "owner",                          // owner | customer  (R-TAA-3/4)
  "role_evidence": "owner_uid_match | owner_uid_verified | wp_principal | default_customer",
  "modes": ["notebook", "astro_self", "sales"],   // agent-mode-access@1 of the principal; [] for customer
  "blocks": ["base", "owner_agent"],        // derived from role (owner-agent-block@1)
  "session": { "thread_key": "100001:8123456789", "session_id": "tc_…" },
  "parts": [                                // user message parts, in order
    { "type": "text", "text": "doanh số tháng này thế nào?" },
    { "type": "image", "url": "https://…", "local_path": "media/…" }
  ],
  "focus": { "notebook_id": 26 },           // web only: notebook the user has open; optional
  "guru_ref": "guru:41",
  "sent_at": "2026-10-01T09:00:00Z"
}
```

Rules:
- `role`, `modes` and `blocks` are computed by the adapter from server facts only (Zalo: sender UID = the number's "UID chủ tài khoản" `policy.owner_uid`, 1-1 only, modes from bundle `owner_agent.principal`; web: `get_current_user_id()` + `agent-mode-access@1`). The model never sees `role_evidence`.
- The envelope carries no raw phone, no raw Zalo UID, no prompt text other than the user's parts.
- Web adapter: the site `POST …/turn` builds the envelope, calls `prepare` (PHASE-0.84 contract A, `kind: "twin_agent.turn"` replacing `twinchat.final`) and returns the ticket; the PHP side no longer runs `start_turn` or compiles a prompt for cut-over surfaces.

## 2. Output frames

The same typed twin frames on every surface (the PHASE-0.84 contract C vocabulary, which the TwinChat parser already reads):

| Frame | Payload | Zalo delivery | Web delivery |
|---|---|---|---|
| `final_started` | `{trace_id, model, role, blocks}` | — | SSE |
| `final_token` | `{trace_id, seq, delta, len}` | buffered into reply parts (DL-17) | SSE |
| `tool_started` / `tool_done` | `{trace_id, tool, block, ms, ok}` (no arguments, no results) | — | SSE `twin_event` |
| `twin_event` | R-EVT envelope `{event_uuid, event_type, event_source:"data_plane", payload}` | — | SSE |
| `final_done` | `{trace_id, answer_md, finish_reason, tokens, model, packs_read:[{kind, version, as_of}]}` | last part sent | SSE |
| `error` | `{code, message, hint, help_code, retryable}` (R-ERROR-UX) | error sentence (existing mapping) | SSE |
| `end` | `{}` | — | SSE |

Answers are rendered from `answer_md`; the surface adapter owns only formatting (Zalo colour markup vs Markdown), declared in the persona's channel-format block (lane BC item BC-7).

## 3. Write-back `twin-agent-turn-complete@1.0.0`

Cell outbox → `POST {HUB}/zalo-hub/turn-complete` (Bearer `CELL_SECRET`) → Hub resolves the site by `key_id` → `POST {site}/wp-json/bizcity-twin/v1/turn-complete` with the per-account callback Bearer (same storage as the Zalo bridge callback token). For `zalo_personal` the existing `bot_reply` event keeps feeding the CRM thread; `turn-complete` adds the twin record.

```jsonc
{
  "event": "twin_agent_turn_complete",
  "contract": "twin-agent-turn-complete@1.0.0",
  "idempotency_key": "taa:<key_id>:<surface>:<trace_id>",
  "trace_id": "01J…", "surface": "twinchat", "account_id": "", "wp_user_id": 12,
  "role": "owner", "blocks": ["base", "owner_agent"],
  "answer_md": "…", "finish_reason": "stop|length|client_abort|error",
  "model": "…", "tokens": { "prompt": 1234, "completion": 456 },
  "tools": [ { "tool": "biz_sales", "ok": true, "ms": 3 } ],
  "packs_read": [ { "kind": "sales", "version": "v-1a2b3c4d", "as_of": "2026-10-01T08:59:00Z" } ],
  "spans": { "t_recv": 0, "t_ctx_ready": 40, "t_first_token": 900, "t_done": 3200 },
  "events": [ { "event_uuid": "…", "event_type": "assistant_message", "payload": {} } ]
}
```

Site handler: `hash_equals` the callback Bearer; dedupe on `idempotency_key` and each `event_uuid`; persist into `bizcity_twin_event_stream`; enqueue (never inline) Memory_Writer / Context Bank pointers. `200 {ok, duplicate}`; `4xx` dead-letters in the outbox, `5xx` retries 48 h (same policy as `bot_reply`).

## 4. Deep-analysis job `deep-analysis-job@1.1.0`

Only way to reach MPR from an axis turn (R-TAA-9). Owner role, mode `deep_analysis` only.

```jsonc
// started from any surface (cell tool request_deep_analysis, or TwinChat / Twin GPT) → site (async, returns at once)
{ "contract": "deep-analysis-job@1.1.0", "job_id": "da_…", "trace_id": "01J…", "started_on": "zalo_personal",
  "account_id": "100001", "user_hash": "…", "vertical": "woo_bizops|astro|crm_customer|notebooks",
  "question": "…" }
// site → 202 { ok:true, job_id }
// site runs TwinBrain start_turn + complete_turn (headless, web_mode=vertical) in a background job,
// writes the MPR timeline and ONE result to its event stream (event deep_analysis_completed {job_id, answer_md, citations}),
// then delivers that same result to every surface of the owner (D-TAA-4):
//   site → Hub → cell  POST /wp/deep-analysis/result  { job_id, ok, answer_md, citations[], timeline_url }  → 1-1 chat of the owner UID
//   TwinChat and Twin GPT → notification + message in the owner's session, read from the same event
```

Same `job_id`, same `answer_md` everywhere; adapters only change rendering. Failure ⇒ one short apology message on each surface; never a silent drop.

## 5. Owner capture `owner-capture@1.0.0`

The only write path from an axis turn into the site's knowledge (R-TAA-15). Asynchronous, through the cell outbox, never a synchronous call inside the turn. Owner role only, 1-1 only.

```jsonc
// cell outbox → POST {HUB}/zalo-hub/owner-capture (Bearer CELL_SECRET) → Hub → site POST bizcity-channel/v1/zalo-bridge/owner-capture (callback Bearer)
{ "contract": "owner-capture@1.0.0",
  "idempotency_key": "oc:<key_id>:<account_id>:<msg_id>:<i>",
  "account_id": "100001", "user_hash": "…", "trace_id": "01J…", "sent_at": "2026-10-01T09:00:00Z",
  "kind": "file | remember",
  "items": [
    { "kind": "image|file|audio", "url": "https://…zalo cdn…", "file_name": "bang-gia.pdf", "msg_id": "…" },   // kind=file
    { "kind": "text", "title_hint": "Hẹn chị Lan", "text": "Thứ 6 giao 20 hộp …" }                               // kind=remember
  ] }
// site → 200 { ok, notebook_id, duplicate } ; 4xx dead-letter, 5xx retry 48 h (bot_reply policy)
```

- `kind=file`: sent automatically by the cell when the owner's 1-1 message carries a file/image/voice (no model decision needed).
- `kind=remember`: sent by the owner tool `notebook_remember(text, title?)` when the owner asks the bot to remember something.
- Site: verify, dedupe, schedule the capture job (PHASE-0.86 capture class) → per-number daily notebook in workspace "Zalo Personal daily" → ingest → **KG built** (passages + triplets) on the site. Silent on the site side (no progress replies); the confirmation is the agent's own reply in the same turn.

## 6. Compatibility

- Additive fields only within `1.x`. A breaking change bumps the major and ships fixtures for both versions until every cell is upgraded (deploy order: Hub → cell → site, same as PHASE-0.81).
- The PHASE-0.84 frame set stays a subset of this contract; TwinChat's parser needs no change beyond `tool_started`/`tool_done`, which older parsers ignore.
