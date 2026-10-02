# Twin Agent Surface Adapter Guide v1 — one `runAgentTurn`, every surface an adapter

> **Axis:** [R-TWIN-AGENT-AXIS](../rules/PHASE-0-RULE-TWIN-AGENT-AXIS.md) R-TAA-1, R-TAA-13 (supreme) · **Status:** v1.0 · 2026-09-30 · **Owner:** Twin AI Core (text), brain-core (`zalo-hub/src/agent/`) and each surface owner (code).
> **Read this before:** touching `runAgentTurn` or anything it calls, adding a tool, adding a surface, changing how TwinChat / Twin GPT / Zalo Cá nhân produce or show a reply, or adding an agent mode.
> **Contracts used:** [`twin-agent-turn@1`](../contracts/TWIN-AGENT-TURN-CONTRACT-v1.md), [`owner-agent-block@1`](../contracts/OWNER-AGENT-BLOCK-CONTRACT-v1.md), [`agent-mode-access@1`](../contracts/AGENT-MODE-ACCESS-CONTRACT-v1.md), [`projection-pack@1`](../contracts/PROJECTION-PACK-CONTRACT-v1.md).

## 1. The one sentence

**Zalo Cá nhân, TwinChat and Twin GPT share one agent — brain-core's `runAgentTurn` — with one persona assembly, one tool catalog, one set of packs and one model profile; each surface is only an adapter that takes a message in, proves who sent it, shows the answer and hands back the record.**

If a change makes the answer to the same question from the same person differ between surfaces (other than formatting), the change is wrong.

## 2. Where the line is

```
            ┌──────────────── ADAPTER (per surface) ────────────────┐   ┌──────────── brain-core (shared) ────────────┐
 channel ──▶│ A1 Intake → twin-agent-turn@1                          │──▶│ role + modes → blocks                         │
 event      │ A2 Principal (server facts only)                       │   │ persona(base [+ owner overlay]) + format block│
            │                                                        │   │ tools(base [+ modes]) · packs (local store)   │
 channel ◀──│ A3 Delivery: typed frames → channel format             │◀──│ runAgentTurn (one loop)                       │
 output     │ A4 Record: write-back / channel record                 │   │ frames + turn-complete                        │
            └────────────────────────────────────────────────────────┘   └──────────────────────────────────────────────┘
```

| Belongs to the **adapter** | Belongs to **brain-core** (never in an adapter) |
|---|---|
| Parsing the channel event; media references; thread/session id | Intent, tool choice, planning, retrieval, reasoning |
| Batching/debounce of a chat channel (Zalo batcher) | Prompt assembly (persona, Guru, history, format block) |
| Proving the principal from server facts; sending role + modes | Deciding which blocks and tools a role/mode gets |
| Typing indicator, seen/delivered receipts, streaming UI | Model calls, retries, tool loop guard, wrap-up turn |
| Rendering frames: Zalo parts, SSE, markdown, citation chips | The answer text and its citations |
| Channel record (CRM row for customer conversations) | The twin record (`turn-complete`) |

## 3. The four adapter duties in detail

### A1 — Intake

Produce `twin-agent-turn@1` (contract §1). Required: `surface`, `key_id`, `account_ref` (Zalo) or `session_id` (web), `thread_id`, `trace_id`, `parts[]` (text + media refs, never inline binaries), `focus` (optional: notebook in view, contact in view). The adapter may batch several channel events into one turn (Zalo: `message-batcher.ts`, mid-turn injection). It may not drop, summarise or reinterpret the user's text.

### A2 — Principal

Prove who is speaking from **server facts** (R-TAA-4) and put `principal`, `role`, `modes` in the envelope:

| Surface | How the principal is proven | Role `owner` when | Modes from |
|---|---|---|---|
| Zalo Cá nhân | the sender UID of the 1-1 message (`senderId`) against the number's **"UID chủ tài khoản"** (`policy.owner_uid` → `AccountConfig.ownerUserId`) — the same gate as `list_threads`/`read_thread`; later phase: the UID is also link-verified | `senderId === ownerUserId`, not a group, and the bundle has `owner_agent.principal` | bundle `accounts[].owner_agent.principal.modes` |
| TwinChat | `get_current_user_id()` + nonce at the site's `/turn`; the site signs the prepare call with its key | the user is logged in (owner of their own session) | computed by the site at prepare (`agent-mode-access@1`) |
| Twin GPT | same as TwinChat, C-surface rules (R-TWIN-GPT-FIRST) | same | same |

Everything that fails a check is `customer` with `modes: []`. An adapter never adds a mode, never reads a role from the payload, a URL, a display name, or model output.

### A3 — Delivery

Render the typed frames of contract §2 (`final_started`, `final_token`, `twin_event`, `final_done`, `error`, `end`):

| Surface | Rendering |
|---|---|
| Zalo Cá nhân | `final_token` batches become reply parts (`stream-reply.ts` / `deliverChatReply`), Zalo formatting, mentions, stickers sent by tools; `error` ⇒ the technical-error sentence of R-ERROR-UX |
| TwinChat / Twin GPT | SSE frames parsed by the shared parser (`useTwinChatStream.ts`); markdown, citation chips, timeline events |

The **content** of `final_done.answer_md` is identical across surfaces. The only per-surface difference inside brain-core is the **channel-format block** of the system prompt (how to format: Zalo plain text + colour markup vs markdown), chosen by `surface`.

### A4 — Record

- brain-core writes `twin-agent-turn-complete@1` to the outbox; the Hub relays it; the site persists it into `bizcity_twin_event_stream` (idempotent). This is the **one** twin record for every surface.
- Zalo customer conversations additionally keep the CRM row (existing `inbound_forward` / `bot_reply`). The owner's 1-1 conversation stays one CRM conversation whose contact is tagged `role:owner` (excluded from customer pipelines); the owner's files and "ghi nhớ" additionally go to the daily notebook through `owner-capture@1` (R-TAA-15).
- An adapter never writes a second transcript.

## 4. What brain-core must keep surface-neutral

To let TwinChat and Twin GPT reuse `runAgentTurn` (PHASE-0.87 BC-7), three Zalo couplings are removed from the loop's signature (research 2026-09-30, `zalo-hub/src/agent/*`):

| Today (Zalo-specific) | Target |
|---|---|
| `AgentTurnParams.api: API` (zca) and tools calling `ctx.api.sendMessage` / `enqueueSend` | a `ChannelPort` interface `{ sendPart, sendFile, sendImage, react, typing }`; the Zalo port wraps zca; the web port emits frames; tools call the port |
| `batch: ParsedMessage[]` with zca `ThreadType` | `TurnInput` from the envelope (`parts`, `thread_id`, `is_group`, `principal`, `role`, `modes`); the Zalo adapter maps `ParsedMessage` → `TurnInput` |
| persona text hard-codes "trả lời tin nhắn trên Zalo" and Zalo colour markup | persona is surface-neutral; a separate channel-format block per surface |
| `AccountConfig` is a Zalo number (tenant, agent, entitlement, usage) | a web turn carries `account_ref` of the principal's primary number or a per-site web pseudo-account for tenant/usage; the same Guru/agent is resolved |

The existing "Thử bot" path (`zalo/test-turn.ts`, no Zalo session, simulated tools) is the proof that the loop already runs without a live channel; the `ChannelPort` generalises it.

## 5. Adding things — checklists

### 5.1 Adding a tool

- [ ] Belongs to the base block (customer-safe) or to an agent mode (owner); declare which in the axis JSON.
- [ ] Reads plugin data through MCP tools/resources on owner/staff turns (R-TAA-5 v1.4), or from the cell's cache of them on customer/walk-in turns (never an MCP call, no PHP, no Hub call) — or is a pure provider call through brain-core. Never a bespoke site route.
- [ ] Has `group`, `runsInScheduledTurn`, a snapshot capability if billable (PHASE-0.85).
- [ ] Sends anything to the user only through the `ChannelPort`, never through zca directly.
- [ ] Output always states `as_of` for pack data; wraps pack/web content as untrusted.
- [ ] Unit test: absent from the schema for `customer` and for a principal without the mode.

### 5.2 Adding an agent mode (a vertical brain mode for the owner)

- [ ] Registered through `bizcity_agent_modes_register` with packs, tools, data scope, `access` (grantable/delegated + default roles), linked vertical bridge id.
- [ ] Its packs follow `projection-pack@1` (read-only exporter, masked PII, bounded, invalidation ≤ 60 s).
- [ ] Declared in `TWIN-AGENT-AXIS-v1.json` `modes`; code markers `@axis twin-agent-axis@1 mode <id>`.
- [ ] Bot Studio "Agent của chủ" lists it; CRM Staff screen shows it.
- [ ] Parity self-check: the same owner question on the owner's 1-1 Zalo chat, TwinChat and Twin GPT reads the same pack version.

### 5.3 Adding a surface

- [ ] Implements A1–A4 only; no prompt, tool or model code in the adapter.
- [ ] Principal proof from server facts; default `customer`.
- [ ] Renders the typed frames; adds a channel-format block to brain-core (the only brain-core change allowed).
- [ ] Writes the twin record through `turn-complete`; keeps its channel record if it has one.
- [ ] Declared in the axis JSON `surfaces`; self-check proving parity with an existing surface.

### 5.4 Changing `runAgentTurn`

- [ ] The change applies to every surface; no `if (surface === …)` except the channel-format block.
- [ ] Zalo tests, the test-turn path and (after cut-over) the web adapter tests all stay green.

## 6. Worked examples

**Owner asks "doanh số hôm nay?"**
- Zalo: the owner writes 1-1 to the business number from their own Zalo → adapter: `senderId` = "UID chủ tài khoản", not a group ⇒ `owner`, modes from bundle `[notebook, astro_self, sales, orders, customers, stock, deep_analysis]` → `runAgentTurn` → `biz_sales` reads pack `sales` v-… → reply parts in that 1-1 chat.
- TwinChat: same person logged in → site `/turn` ⇒ `owner`, modes computed now → same loop, same tool, same pack version → SSE frames.
- Same `answer_md` except formatting.

**Editor asks the same on TwinChat.** Modes `[notebook, astro_self]` ⇒ `biz_sales` not in the schema; the agent says it has no access to sales data and who can grant it.

**Customer writes to the number "doanh số shop bao nhiêu?"** `customer` ⇒ no Owner Agent block; the agent answers from the Guru only.

**Owner in a group** (D-TAA-5): base block; the agent suggests asking in the 1-1 chat with the number.

**Owner sends a file** (R-TAA-15): owner 1-1 sends `bang-gia.pdf` → the cell emits `owner-capture@1 {kind:file}` from the batch (no model decision) → the agent replies "đã lưu vào sổ ghi chú 30/09" → site capture job → daily notebook in workspace "Zalo Personal daily" → ingest → KG built.

**Owner asks to remember**: "ghi nhớ giúp: thứ 6 giao chị Lan 20 hộp" → tool `notebook_remember(text)` → `owner-capture@1 {kind:remember}` → same daily notebook.

**Customer asks to save/remember**: no capture tool in the schema; the base persona rule answers that only the account owner can ask the bot to save into the notebook.

**Owner asks something the context does not answer**: the agent offers "Bạn có muốn mình tra sổ ghi chú để tìm thêm không?"; on "có" → `notebook_search` over the number's daily-notebook workspace (owner scope). An explicit reference ("hôm trước tôi lưu…", "trong sổ ghi chú") searches directly.

**Deep analysis** (D-TAA-4): owner on any surface asks "phân tích sâu vì sao doanh số giảm" → tool `request_deep_analysis` (mode `deep_analysis`) → immediate "em đang phân tích" → job on the web → one result → delivered to the owner's 1-1 Zalo chat, TwinChat and Twin GPT with the same content.

## 7. Anti-patterns (review rejects)

- A surface-specific prompt, tool list or retriever.
- A web surface calling TwinBrain Runtime for a reply after cut-over.
- A tool that uses `ctx.api` (zca) directly.
- A role or mode derived from anything but server facts.
- Owner data in a customer turn; another user's notebook in an owner turn.
- A deep-analysis result rendered differently in content between surfaces.

## 8. Related

PHASE-0.87 lanes: [BC (brain-core)](../../core/channel-gateway/docs/PHASE-0.87-OWNER-AGENT-VERTICAL-TOOLS/10-LANE-BC-BRAIN-CORE.md) · [CL (client)](../../core/channel-gateway/docs/PHASE-0.87-OWNER-AGENT-VERTICAL-TOOLS/20-LANE-CL-CLIENT.md) · [seams](../../core/channel-gateway/docs/PHASE-0.87-OWNER-AGENT-VERTICAL-TOOLS/30-HANDOFF-SEAMS.md) · [one stream, owner and customer](../../core/channel-gateway/docs/PHASE-0.87-OWNER-AGENT-VERTICAL-TOOLS/40-ONE-STREAM-OWNER-CUSTOMER.md). Realtime web transport: [PHASE-0.84](../../core/channel-gateway/docs/PHASE-0.84-REALTIME-STREAM/00-PHASE-1-DESIGN.md).
