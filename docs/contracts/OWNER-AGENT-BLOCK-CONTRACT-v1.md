# Owner Agent Block Contract v1 — `owner-agent-block@1.2.0`

> **Axis:** [R-TWIN-AGENT-AXIS](../rules/PHASE-0-RULE-TWIN-AGENT-AXIS.md) (R-TAA-3, R-TAA-4, R-TAA-7, R-TAA-8, R-TAA-14, R-TAA-15) · **Machine form:** [TWIN-AGENT-AXIS-v1.json](TWIN-AGENT-AXIS-v1.json)
> **Status:** v1.2.0 · 2026-09-30 (owner = the Zalo UID of "UID chủ tài khoản", 1-1 with the number — replaces "Cloud của tôi"; capture/remember tools; notebook search offered first) · v1.1.0 · 2026-09-30 · v1.0.0 · 2026-09-30 · design, no code yet · **Owner:** brain-core (`zalo-hub/src/agent/`); facts supplied by the client bundle / web prepare.
> **Scope:** `framework_internal`. **Permissions:** [`agent-mode-access@1`](AGENT-MODE-ACCESS-CONTRACT-v1.md). **Capture write path:** [`owner-capture@1`](TWIN-AGENT-TURN-CONTRACT-v1.md#5-owner-capture-owner-capture100). **Design guide:** [Surface Adapter Guide](../framework/TWIN-AGENT-SURFACE-ADAPTER-GUIDE-v1.md).

## 1. One pipeline, blocks by role, content by mode

```
turn envelope ──▶ role ──▶ blocks = role==owner ? [base, owner_agent(modes)] : [base]
                            │
                            ├─ system prompt = persona(base) [+ owner overlay] + channel-format block(surface) + data sections
                            ├─ tool schema   = tools(base)   [+ tools of each allowed mode]
                            └─ pack access   = packs(base)   [+ packs of each allowed mode]   (enforced again in the pack store)
```

A customer turn is exactly the owner turn with the `owner_agent` block removed; a principal without a mode is the same turn with that mode's tools and packs removed. Same `runAgentTurn`, model profile, history and Guru.

## 2. Role resolution (server facts only)

| Surface | `owner` when | Evidence |
|---|---|---|
| `zalo_personal` | the sender's Zalo UID (`senderId`) **equals** the number's **"UID chủ tài khoản"** (Bot Studio `policy.owner_uid` → bundle → cell `AccountConfig.ownerUserId`) **and** the conversation is 1-1 with the number (not a group) | `owner_uid_match` |
| `zalo_personal` (later phase, D-TAA-6) | same, and the UID has been **verified by link** (the owner opened a one-time link sent by the bot and confirmed it while logged in as the number's `owner_user_id`) | `owner_uid_verified` |
| `twinchat`, `gpt` | a logged-in WordPress user on the current blog (owner of their own session) | `wp_principal` |
| anything else — every other sender, **every group** (D-TAA-5), messages written by the number itself (`isSelf`) | — | `default_customer` / no turn |

This is the **same gate** the cell already uses for `list_threads`/`read_thread` (`cross-thread-gate.ts`: owner set, 1-1, `senderId === ownerUserId`) — one owner field for every owner capability. Empty "UID chủ tài khoản" ⇒ nobody is owner (fail closed).

**Binding to the WordPress user.** The UID owner of a number is the number's `owner_user_id` (R-SETUP-4 ③): the person who set their UID in Bot Studio for their number. Notebooks, modes and astro of an owner turn are those of `owner_user_id` (first `user_id`). The link verification of the later phase makes this binding proven instead of declared.

## 3. Block contents

| | Base block | Owner Agent block |
|---|---|---|
| Persona | Guru `instruction` + quick FAQ (R-GURU-SOURCE), customer tone; **rule:** if asked to save a document or "remember" something, answer that only the account owner can ask the bot to save into the notebook (R-TAA-15) | + owner overlay: speaks to the owner in first person, business tone; **rule:** when the answer is not in context and the owner's notebooks may help, **offer** "Bạn có muốn mình tra sổ ghi chú để tìm thêm không?" and search on yes (R-TAA-7) |
| Data sections | Guru knowledge (if `scope.knowledge=base+notebooks`), thread history, contact block (if enabled) | pack data on demand via tools (not dumped into the prompt) |
| Tools | existing customer-safe catalog (reaction, sticker, send file, …) + `notebook_search` (Guru scope) | per allowed mode: `notebook_search` (owner scope: the number's daily-notebook workspace) · `notebook_remember` · `astro_self` · `biz_sales` · `biz_orders` · `biz_customer_find` · `biz_stock` · `request_deep_analysis`; plus the existing owner gates (`list_threads`, `read_thread`, group admin) |
| Pack kinds | `knowledge` | per allowed mode: `owner_knowledge` + `notebook_meta` · `astro_self` · `sales` · `orders` · `customers` · `stock` |
| Capture | — | files the owner sends are saved automatically (R-TAA-15) |

Owner tools: `group:"read"` except `notebook_remember` (`group:"action"`), `runsInScheduledTurn:false`, each also gated by a snapshot capability (`owner_agent.<mode>`); results wrapped as untrusted data; pack tools include `as_of`.

## 4. Fields supplied by the client

**Zalo — config bundle** (existing field reused + one new block; deploy cell first):

```jsonc
"accounts": [{
  "id": "…",
  "policy": { "owner_uid": "1234567890123456789" },   // EXISTING: Bot Studio "UID chủ tài khoản"; raw UID reaches only the cell, never logged
  "owner_agent": {                                     // NEW
    "enabled": true,                                   // Bot Studio switch "Agent của chủ"
    "principal": {
      "user_hash": "sha256(blog_id|owner_user_id|site_salt)",
      "modes": ["notebook", "astro_self", "sales", "orders", "customers", "stock", "deep_analysis"],   // agent-mode-access@1 of owner_user_id
      "uid_verified_at": null                          // later phase (link verification)
    },
    "capture": { "files": true, "remember": true },    // owner files auto-saved; "ghi nhớ" tool on
    "owner_knowledge_ref": "u:<user_hash>"
  }
}]
```

**Web — `/turn` prepare** (PHASE-0.84 contract A, extended): `principal: { user_hash, modes }`, `role: "owner"`, computed by the site; `owner_knowledge_ref` of that user.

## 5. Guarantees and tests

1. Customer turn: owner tools absent; owner pack read refused (`not_owner_turn`); no owner overlay.
2. Impersonation: a sender whose display name equals the owner's but whose UID differs ⇒ `customer`.
3. Group: every sender, the owner included, gets the base block (D-TAA-5).
4. Customer sends a file or says "ghi nhớ giúp tôi …" ⇒ nothing is saved; the bot says only the account owner can save to the notebook.
5. Owner sends a file 1-1 ⇒ one `owner-capture@1` event; the bot confirms "đã lưu vào sổ ghi chú <ngày>"; the file appears in the daily notebook and its KG is built on the site.
6. Modes: a principal without `sales` ⇒ `biz_sales` absent, pack `sales` refused (`mode_not_allowed`); `owner_knowledge` of another `user_hash` refused (`not_own_notebook`).
7. Parity: the same owner question on `zalo_personal`, `twinchat`, `gpt` returns the same `packs_read` versions and facts.
