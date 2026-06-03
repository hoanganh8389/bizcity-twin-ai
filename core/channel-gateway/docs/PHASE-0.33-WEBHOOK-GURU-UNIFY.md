# PHASE 0.33 — Webhook Gateway × Guru × Channel Unify

> **Status**: M1 ✅ DONE · M2-essential ✅ DONE · 2026-05-09 (proven by Sprint 7 diag T-S7.1→T-S7.8)
>
> **Progress snapshot 2026-05-09**:
> - ✅ `BizCity_Webhook_Router` (singleton, rewrite `/biz/hook/{platform}/`, legacy aliases tap, parse_request + shutdown finalize)
> - ✅ `BizCity_Webhook_Log` (daily-partition `wp_{Y_m_d}_webhook_log`, TTL 3 days, prune cron 02:15)
> - ✅ `BizCity_Channel_Binding` (`wp_bizcity_channel_bindings`, exact + `account_id='*'` fallback)
> - ✅ `wp_bizcity_channel_messages` schema 1.1.0 (+`webhook_log_id`, `webhook_log_date`, `character_id`)
> - ✅ `BizCity_Universal_Channel_Listener` taps `waic_twf_process_flow` @ priority 5 — resolves binding → mirrors `channel_messages` → patches `webhook_log` 2-way
> - ✅ Zalo Bot direct-action **bridge** (`bizcity_zalo_message_received` → `waic_twf_process_flow`) — fixes BUG-B without forking the Zalo plugin
> - ✅ Re-emits `bizcity_channel_normalized` envelope @ priority 6 (carries `character_id`, `channel_message_id`, `webhook_log_id` for downstream consumers)
> - ✅ 8 diagnostic tasks T-S7.1 → T-S7.8 in `core/channel-gateway` Sprint Diagnostic table
>
> **Deferred (no longer blocking)**: Full M2 adapter compliance refactor (4 adapters implementing `get_legacy_endpoints()` + `get_verify_strategy()`). The Universal Listener achieves the same end-to-end observability without touching FB/Zalo/WebChat handler files. Refactor when convenient.
>
> **Next**: M4 (Webhook Inspector React page) → M5 (Replay endpoint + full Automation listener via guru→KG→Sender)
>
> **Originally proposed**: 2026-05-09
> **Parent rules**: `PHASE-0-RULE-GATEWAY-ONLY.md`, `PHASE-0-RULE-AGENTIC-CORE.md`, `PHASE-0-RULE-INTENT-MONITOR.md`
> **Parallel with**: `PHASE-0.32-CRM-INBOX-HUB.md` M2 (auto-reply outbound)
> **Companion runtime**: `core/channel-gateway/` + `plugins/bizcity-twin-crm/`

---

## 0. Quy tắc tối cao (HIGHEST RULE)

> **MỘT cánh cổng — hai dòng chảy.**
>
> Mọi sự kiện đến từ kênh ngoài (FB / Zalo / WebChat / Telegram / …) **PHẢI** đi qua **một** Webhook Router duy nhất, **một** schema chuẩn hoá, **một** sink kế toán raw, **một** sink nghiệp vụ. Sau cánh cổng, dòng chảy chia hai:
>
> 1. **CRM** (Inbox cho người trực) — `bizcity-twin-crm`
> 2. **Automation** (workflow + Guru AI auto-reply) — `bizcity-automation` + `core/intent`
>
> **Cấm** mỗi plugin tự `add_rewrite_rule()` cho webhook riêng. **Cấm** mỗi adapter tự log vào file riêng. **Cấm** trigger naming khác nhau giữa các kênh.

### 4 hệ quả bắt buộc

1. **One Router, two compat URL**: Router mới `^biz/hook/{platform}/?$` là canonical, **đồng thời** lắng nghe legacy URL (`/bizfbhook/`, `?fbhook=1`, `/zalohook/`, `/bizhook/`, `/webchat-hook/`) → không phải re-verify FB Developer Console.
2. **One trigger pattern**: tất cả kênh fire `do_action('waic_twf_process_flow', 'bizcity_<platform>_<event>', $envelope)`. CRM và Automation cùng listen — không cần biết kênh nào.
3. **One ledger**: `wp_bizcity_channel_messages` (đã có v1.0.0) là sink nghiệp vụ duy nhất. `wp_{Y_M_D}_webhook_log` là sink raw kế toán (daily-partitioned, TTL 3 ngày).
4. **One persona binding**: mỗi channel **bind đúng 1 Guru** (`character_id`). Guru nắm KG (notebook attached qua `wp_bizcity_kg_sources.character_id`). Inbound → router lookup `channel→guru→notebooks` → Automation enrich system prompt → reply qua cùng cổng.

---

## 1. Mô hình GURU × CHANNEL × NOTEBOOK

### 1.1 Khái niệm

```
┌─────────────┐  attached via    ┌──────────────────────────┐
│  Notebook   │ ───────────────► │ wp_bizcity_kg_sources    │ (character_id)
│  (id 21,22, │                  │  scope=character         │
│   23 …)     │                  └──────────────┬───────────┘
└─────────────┘                                 │
                                                ▼
                            ┌────────────────────────────────┐
                            │  GURU = character_id           │
                            │  (e.g. 3 = CSKH)               │
                            │  - persona / instruction       │
                            │  - merged KG (n notebooks)     │
                            │  - tools/skills granted        │
                            └────────────────┬───────────────┘
                                             │ bind 1-1
                                             ▼
                            ┌────────────────────────────────┐
                            │  CHANNEL_BINDING               │
                            │  (platform, account_id) → guru │
                            │  e.g. FB Page 1234 → guru #3   │
                            └────────────────────────────────┘
```

### 1.2 Quy tắc binding

| Cardinality | Rule |
|---|---|
| Notebook → Guru | **N:1** — 1 Guru gom nhiều notebook (qua `kg_sources.character_id`) để merge KG |
| Guru → Channel | **1:N** — 1 Guru có thể chịu nhiều channel binding (FB Page A + Zalo OA B đều do CSKH trả) |
| Channel → Guru | **N:1** — 1 channel chỉ có **đúng 1** Guru active tại 1 thời điểm |
| Inbound message → Guru | **lookup** `binding(platform, account_id) → character_id` |
| Outbound (auto / manual) | luôn ghi `from_character_id` để trace ai trả |

> **Vì sao 1 channel ↔ 1 Guru?**
> Tránh ambiguity khi auto-reply: chỉ 1 persona, 1 KG context, 1 prompt template. Đổi guru = đổi channel binding (audit trail).

### 1.3 Ví dụ vận hành (theo yêu cầu)

```
Notebook 21 ─┐
Notebook 22 ─┼── _bizcity_kg_sources(character_id=3, scope=character)
Notebook 23 ─┘

Guru #3 = "CSKH Customer Service"
  - instruction: "Bạn là chuyên viên CSKH BizCity, trả lời ngắn, gợi ý nâng cấp gói…"
  - KG merged: 21 + 22 + 23 (auto re-rank top-K khi truy vấn)

Channel binding:
  FB Page "BizCity Official" (page_id=1234567)  →  guru_id=3
  Zalo OA "BizCity Hotline"  (oa_id=987654)     →  guru_id=3
  WebChat default                               →  guru_id=3

Inbound flow:
  1. POST /bizfbhook/  →  Router  →  FB Adapter normalize
  2. Router lookup binding(platform=FB_MESS, account_id=1234567) → guru_id=3
  3. log to wp_{date}_webhook_log + wp_bizcity_channel_messages (with character_id=3)
  4. fire waic_twf_process_flow('bizcity_facebook_message_received', envelope+character_id)
  5a. CRM ingestor → tạo conversation gắn character_id=3
  5b. Automation listener → load guru #3 prompt + KG merge (21,22,23) → reply
  6. Reply outbound: BizCity_Gateway_Sender::send() → mirror outbound vào ledger
```

---

## 2. Hiện trạng (audit gọn)

| Channel | URL | Trigger hiện tại | Verify | Logger | Có vào `channel_messages`? |
|---|---|---|---|---|---|
| FB Messenger | `/bizfbhook/` + `?fbhook=1` | `waic_twf_process_flow('bizcity_facebook_message_received')` | `bztfb_verify_token` | `mu-plugins/logs/fbhook-{date}.log` | ❌ |
| FB Comments | `/bizfbhook/` (entry.changes) | `waic_twf_process_flow('bizcity_facebook_comment_received')` | (dùng chung) | (dùng chung) | ❌ |
| Zalo OA Bot | `/zalohook/` | `do_action('bizcity_zalo_message_received')` ⚠ KHÁC pattern | header `X-Bot-Api-Secret-Token` (không enforce) | tự `log_zalohook_*` | ❌ |
| Zalo Hotline (ZNS) | `/bizhook/` | `do_action('bizcity_zalo_hotline_sent')` (out) | signature riêng | bảng riêng | ✅ outbound only |
| WebChat | `/webchat-hook/` + AJAX `bizcity_chat_send` | `waic_twf_process_flow('wu_webchat_message_received')` | nonce | `*_webchat_messages` | ❌ |
| Telegram (legacy) | REST `telegram-trigger/v1/webhook/{token}` | (legacy chain) | path token | — | ❌ |

### Vấn đề chính (đánh giá ngắn)
1. **Phân mảnh handler** — 5 file webhook khác nhau, 5 cách verify, 5 cách log.
2. **Inconsistent trigger** — Zalo Bot lệch khỏi `waic_twf_process_flow`.
3. **Không có observability tập trung** — Admin phải mở 3 nơi để trace 1 hook.
4. **`channel_messages` chưa bắt inbound FB / Zalo Bot / WebChat** dù schema có sẵn `payload_json`.
5. **Chưa có Guru ↔ Channel binding** — auto-reply không biết dùng persona nào.

> **Verdict**: cơ chế hiện tại *functional nhưng phân mảnh*, không đáp ứng được yêu cầu "1 cánh cổng, vừa CRM vừa Automation". **Cần unify ở Phase 0.33**.

---

## 3. Kiến trúc đích — 3 lớp

```
┌────────────────────────────────────────────────────────────────────┐
│                     📡 INBOUND WEBHOOK SURFACE                     │
│                                                                    │
│  Canonical:  /biz/hook/facebook/   /biz/hook/zalo-bot/             │
│              /biz/hook/zalo-hotline/  /biz/hook/webchat/           │
│              /biz/hook/telegram/                                   │
│                                                                    │
│  Legacy alias (Router cùng nghe, KHÔNG redirect):                  │
│              /bizfbhook/   ?fbhook=1   /zalohook/                  │
│              /bizhook/     /webchat-hook/                          │
└──────────────────────────┬─────────────────────────────────────────┘
                           ▼
┌────────────────────────────────────────────────────────────────────┐
│  🧭 BizCity_Webhook_Router  (NEW — core/channel-gateway/)          │
│   1. Detect platform from URL path / query var                     │
│   2. Capture raw body + headers + start timer                      │
│   3. INSERT to wp_{Y_M_D}_webhook_log (always — audit trail)       │
│   4. $adapter->verify_webhook($req)  → 403 + log verify_status     │
│   5. $adapter->normalize_inbound($raw) → standard envelope         │
│   6. lookup channel_binding → attach character_id (guru) to env    │
│   7. BizCity_Channel_Messages::log_inbound( envelope )             │
│   8. do_action('waic_twf_process_flow',                            │
│         'bizcity_<platform>_<event>', $envelope)                   │
│   9. response 200 fast (defer heavy work)                          │
└──────────────────────────┬─────────────────────────────────────────┘
                           ▼
        ┌──────────────────┴──────────────────┐
        ▼                                     ▼
┌──────────────────────┐              ┌──────────────────────┐
│  🤖 AUTOMATION       │              │  💬 CRM INBOX         │
│  bizcity-automation  │              │  bizcity-twin-crm    │
│  + core/intent       │              │  (ingestor M1)       │
│                      │              │                      │
│  - load guru config  │              │  - upsert contact    │
│  - merge KG (notes)  │              │  - append message    │
│  - generate reply    │              │  - SLA timer         │
│  - send via Sender   │              │  - assignee routing  │
└──────────┬───────────┘              └──────────────────────┘
           ▼
    BizCity_Gateway_Sender::send()
           ▼
    log_outbound() → channel_messages
```

### 3.1 Hai sink (cùng append-only)

| Sink | Bảng | Mục đích | TTL |
|---|---|---|---|
| **Raw** | `wp_{Y_M_D}_webhook_log` (daily partition) | Forensics: header/body raw, latency, verify_status, http_status | **3 ngày** (cron prune) |
| **Normalized** | `wp_bizcity_channel_messages` (existing v1.0.0, bump 1.1) | State nghiệp vụ — inbound + outbound đã verify | vĩnh viễn |

#### 3.1.1 Schema `wp_{Y_M_D}_webhook_log`

```sql
CREATE TABLE wp_2026_05_09_webhook_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  platform VARCHAR(40) NOT NULL DEFAULT '',
  endpoint VARCHAR(190) NOT NULL DEFAULT '',
  method VARCHAR(8) NOT NULL DEFAULT 'POST',
  http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  verify_status VARCHAR(16) NOT NULL DEFAULT '',  -- verified|failed|skipped
  latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
  remote_ip VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  headers_json TEXT NULL,
  body_raw LONGTEXT NULL,                          -- raw POST body
  channel_message_id BIGINT UNSIGNED NULL,         -- FK to channel_messages.id (nullable)
  character_id BIGINT UNSIGNED NULL,               -- guru routed to (if matched)
  error VARCHAR(500) NOT NULL DEFAULT '',
  is_replay TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_platform (platform),
  KEY idx_status (verify_status, http_status),
  KEY idx_msg (channel_message_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB;
```

- Bảng được **tự tạo** lúc gọi `log()` đầu tiên trong ngày (lazy + `CREATE TABLE IF NOT EXISTS`).
- Cron `bizcity_webhook_log_prune` chạy daily 02:15 → `DROP TABLE` mọi `wp_*_webhook_log` có ngày < today − 3.
- Query API: `BizCity_Webhook_Log::query(['days'=>3, ...])` tự `UNION ALL` qua các bảng còn sống.

#### 3.1.2 Bump `wp_bizcity_channel_messages` → 1.1.0

Thêm 3 cột:

```sql
ALTER TABLE wp_bizcity_channel_messages
  ADD COLUMN webhook_log_id BIGINT UNSIGNED NULL AFTER payload_json,
  ADD COLUMN webhook_log_date DATE NULL AFTER webhook_log_id,    -- để JOIN đúng partition
  ADD COLUMN character_id BIGINT UNSIGNED NULL AFTER webhook_log_date,
  ADD KEY idx_character (character_id),
  ADD KEY idx_log (webhook_log_date, webhook_log_id);
```

`SCHEMA_VERSION = '1.1.0'` → `maybe_install()` tự dbDelta.

### 3.2 Bảng `wp_bizcity_channel_bindings` (NEW)

```sql
CREATE TABLE wp_bizcity_channel_bindings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  platform VARCHAR(40) NOT NULL,             -- FB_MESS, ZALO_BOT, WEBCHAT, ...
  account_id VARCHAR(190) NOT NULL,          -- FB page_id, Zalo oa_id, '*' for default
  character_id BIGINT UNSIGNED NOT NULL,     -- the GURU
  status TINYINT UNSIGNED NOT NULL DEFAULT 1, -- 0=disabled, 1=active
  auto_reply TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0=CRM only, 1=automation reply
  fallback_assignee BIGINT UNSIGNED NULL,    -- WP user fallback nếu auto-reply fail
  meta_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_binding (blog_id, platform, account_id),
  KEY idx_character (character_id)
) ENGINE=InnoDB;
```

> Lookup function: `BizCity_Channel_Binding::resolve($platform, $account_id) : ?array` — fallback wildcard `account_id='*'` nếu không tìm exact.

---

## 4. Adapter contract (mở rộng từ interface đã có)

```php
interface BizCity_Channel_Adapter {
    public function get_platform(): string;
    public function get_prefix(): string;
    public function get_endpoints(): array;             // ['facebook'] (canonical) + legacy aliases khai báo qua get_legacy_endpoints()
    public function get_legacy_endpoints(): array;      // NEW: ['/bizfbhook/', '?fbhook=1']
    public function get_verify_strategy(): array;       // NEW: ['type'=>'query_token', 'field'=>'hub_verify_token', 'option'=>'bztfb_verify_token']
    public function verify_webhook(array $req): bool;
    public function normalize_inbound(array $raw): array; // MUST include: platform, account_id, chat_id, user_id, message, attachments[], event_type, raw
    public function send_outbound(string $chat_id, string $message, array $opts = []): bool;
}
```

**Mỗi plugin chỉ giữ Adapter** — webhook handler riêng tư biến mất:
- `bizcity-facebook-bot/includes/class-webhook-handler.php` → **deprecate** (Router thay thế; class giữ lại làm `BizCity_FB_Channel_Adapter`).
- `bizcity-zalo-bot/includes/class-webhook-handler.php` → **deprecate** (đổi trigger sang `waic_twf_process_flow`).
- `bizcity-admin-hook-zalo/...` → adapter only.
- WebChat AJAX `bizcity_chat_send`: GIỮ (không phải webhook 3rd-party) **nhưng** sau khi xử lý phải gọi `BizCity_Webhook_Router::ingest_internal('webchat', $payload)` để cùng đi qua sink.

---

## 5. Replay (theo yêu cầu)

> **Quy tắc**: Replay **MẶC ĐỊNH FIRE** `waic_twf_process_flow` (vì automation cũng dùng cánh cổng chung — replay phải tái hiện đầy đủ side-effects để debug hợp nhất).

### Endpoint
```
POST /wp-json/bizcity-channels/v1/webhooks/{date}/{id}/replay
Body: { "fire_downstream": true|false (default true), "force_verify_pass": false }
```

### Pipeline
1. Nạp row từ `wp_{date}_webhook_log` (raw body + headers).
2. Re-construct request envelope.
3. Verify lại (trừ khi `force_verify_pass=true` cho test).
4. `normalize_inbound()`.
5. Insert log row mới với `is_replay=1`.
6. Insert `channel_messages` row mới (dedup bằng `(platform, message_id)` — nếu trùng → trả `existing_id`, không double).
7. Nếu `fire_downstream=true` → `do_action('waic_twf_process_flow', ...)` y hệt lần đầu **nhưng** envelope mang flag `'is_replay' => true` để consumer tự quyết (CRM vẫn ingest, Automation có thể skip auto-reply).
8. Trả về cả 2 row id (log + message) + status downstream.

> **Audit**: replay luôn lưu `parent_log_id` trong `meta_json` để truy vết chuỗi.

---

## 6. Admin UI — Webhook Inspector × Guru Bindings

Top-level menu: `BizCity Channels` (đã có) → 2 submenu mới:

### 6.1 `Webhook Inspector` (tabbed)
```
[ All ] [ Facebook (847) ] [ Zalo Bot (320) ] [ Zalo Hotline (54) ]
[ WebChat (62) ] [ Telegram (3) ] [ ⚠ Failed (12) ] [ 🔁 Replay (5) ]

Filters: [Date▼ today/3d] [Verify▼] [HTTP▼] [🔍 chat_id|message_id|account_id]

Time         Plat    Endpoint            HTTP  Verify  Latency  Guru   Actions
─────────────────────────────────────────────────────────────────────────────────
14:32:01 ✓   FB      /bizfbhook/         200   ok       87ms   #3 CSKH  [👁][🔁]
14:31:58 ✓   ZBOT    /zalohook/          200   ok       52ms   #3 CSKH  [👁][🔁]
14:30:14 ✗   FB      /bizfbhook/         403   failed   11ms   —        [👁]
14:28:00 ✓   WCHAT   ajax bizcity_chat   200   skipped 190ms   #5 SALE  [👁][🔁]
```

Row expand → 4 tabs:
1. **Raw Headers** (table)
2. **Raw Body** (pretty JSON, syntax highlight)
3. **Normalized Envelope** (JSON đã chuẩn hoá)
4. **Linked**: → channel_message #N → CRM conversation #M → guru #K

Buttons: `🔁 Replay`, `📋 Copy as cURL`, `🔓 Force-verify pass (debug)`.

**Stack**: cùng React 18 + RTK Query + Tailwind (scoped `.bizcity-channels-root`) — share Vite skeleton với CRM M1 nếu thuận, fallback `wp.element` zero-build.

### 6.2 `Guru Bindings`
```
Platform     Account             Guru                Auto-reply  Fallback   Status
──────────────────────────────────────────────────────────────────────────────────
FB Messenger  Page 1234567890     #3 CSKH              ON         user@a    ● active
FB Messenger  Page 9876543210     #5 SALE              OFF        user@b    ● active
Zalo Bot     OA 987654             #3 CSKH              ON         user@a    ● active
WebChat      *  (default)         #3 CSKH              ON         —         ● active
Telegram     bot xxxx              #7 SUPPORT           OFF        user@c    ○ disabled

[+ Add binding]
```

Add/edit modal:
- Select platform (dropdown)
- Account ID (auto-suggest từ adapter `list_accounts()` nếu có)
- Select Guru = `character_id` từ `bizcity_characters` table
- Toggle Auto-reply
- Fallback assignee = WP user picker

---

## 7. REST API

```
Namespace: bizcity-channels/v1

GET    /webhooks                       ?platform=&verify=&since=&until=&limit=
GET    /webhooks/{date}/{id}           full row + linked refs
POST   /webhooks/{date}/{id}/replay    { fire_downstream, force_verify_pass }
GET    /webhooks/stats                 counts per platform per day (3d window)

GET    /bindings                       list all
POST   /bindings                       create
PATCH  /bindings/{id}                  update
DELETE /bindings/{id}                  soft-disable (status=0)
GET    /bindings/resolve               ?platform=&account_id= → guru config

GET    /channels                       inventory (đã có 1 phần — bổ sung)
```

Tất cả route require `manage_options` hoặc cap mới `bizcity_manage_channels`.

---

## 8. Roadmap — 6 milestones (M0–M5), parallel với CRM M2

| M | Title | Output | Risk | Cùng Sprint với |
|---|---|---|---|---|
| **M0** | Audit & lock | Inventory webhook (§2) khoá lại; PR thêm doc này | none | CRM M2 kickoff |
| **M1** ✅ | Router + Sinks | `BizCity_Webhook_Router`, daily-partition `webhook_log`, schema bump `channel_messages` 1.1.0, prune cron | low | CRM M2 schema |
| **M2** ⚠️ partial | Adapter compliance | 4 adapter implement `get_legacy_endpoints()` + `get_verify_strategy()`; **Zalo Bot đổi trigger sang `waic_twf_process_flow`**; FB & Zalo Bot stop self-routing (Router takes over) | med | — |
| **M2-essential** ✅ | Universal Listener bridge | `BizCity_Universal_Channel_Listener` taps `waic_twf_process_flow`@5 cho 5 trigger keys; Zalo direct-action bridged; mirror channel_messages + patch webhook_log 2-way; re-emit `bizcity_channel_normalized` envelope | low | none |
| **M3** ✅ (binding lookup) | Guru binding + lookup | Bảng `channel_bindings`, `BizCity_Channel_Binding::resolve()`, attach `character_id` vào envelope, CRM ingestor hiểu field này | med | CRM M2 conversation.character_id |
| **M4** | Admin UI Inspector + Bindings | Tabbed React page + binding manager; REST endpoints | med | CRM M2 inbox UI (cùng vendor bundle) |
| **M5** | Automation hookup + Replay | Automation listener nạp guru→KG→reply via Sender; Replay endpoint hoàn chỉnh; legacy webhook handler files chuyển trạng thái deprecated (giữ adapter only) | high | CRM M2 outbound |

### 8.1 Cross-link với CRM M2

| CRM M2 cần | Phase 0.33 cung cấp |
|---|---|
| `conversations.character_id` để gắn guru cho phiên | M3 envelope đã có |
| `messages.from_character_id` để biết auto-reply do guru nào trả | M5 outbound mirror |
| Tag UI "Auto" / "Manual" trên message | M5 envelope flag `is_automation=1` |
| Inbox sidebar list channel theo guru | M4 binding API `/bindings` |

### 8.2 Diagnostic tasks (DDV — Diagnostic-Driven Validation)

Thêm vào `core/channel-gateway/includes/class-sprint-diagnostic.php`:

| ID | Task |
|---|---|
| T-S7.1 | Router class loaded + canonical rewrite registered |
| T-S7.2 | Legacy alias (`/bizfbhook/`, `/zalohook/`, `/bizhook/`, `/webchat-hook/`) routes vào Router |
| T-S7.3 | Today's `wp_{Y_M_D}_webhook_log` table exists & writeable |
| T-S7.4 | Prune cron registered + last-run within 24h |
| T-S7.5 | `channel_messages` schema = 1.1.0 (3 cột mới + 2 index) |
| T-S7.6 | All 4 adapters return non-empty `get_legacy_endpoints()` |
| T-S7.7 | Zalo Bot fires `waic_twf_process_flow` (not direct action) — grep source |
| T-S7.8 | `channel_bindings` rows ≥ 1 + every active binding has valid `character_id` |
| T-S7.9 | Replay loopback: insert fixture log → call replay → verify new log + new channel_message + downstream fire counter ↑ |
| T-S7.10 | Inspector REST `GET /webhooks` returns ≥ 1 row in last 3 days |

---

## 9. Backward compatibility & rollback

- Legacy URL **vẫn sống** sau M5 — Router đăng ký song song (FB Developer Console không cần re-verify).
- Adapter cũ (`class-webhook-handler.php`) chỉ deprecate qua **feature flag** `option('bizcity_webhook_router_enabled', 1)`. Tắt = rơi về behavior cũ trong 1 release.
- `webhook_log` daily-partition → mất dữ liệu raw cũ là expected (TTL 3d) — không ảnh hưởng nghiệp vụ vì `channel_messages` lưu vĩnh viễn.
- Schema bump 1.1.0 dùng dbDelta → idempotent, có thể rollback bằng `ALTER TABLE … DROP COLUMN` thủ công.

---

## 10. Định nghĩa "Done"

Phase 0.33 đóng khi tất cả thoả:

1. ✅ Mọi inbound (FB/ZBOT/HOTLINE/WCHAT) chạy qua `BizCity_Webhook_Router` — verify bằng grep `add_rewrite_rule.*bizfbhook` chỉ còn ở 1 file (Router).
2. ✅ Diagnostic T-S7.1 → T-S7.10 ALL PASS.
3. ✅ Inspector hiển thị traffic 3 ngày gần nhất, replay hoạt động.
4. ✅ Có ≥ 3 binding active, mỗi binding link đúng `character_id`.
5. ✅ CRM Inbox hiển thị `Guru: #3 CSKH` ở mỗi conversation.
6. ✅ Automation nạp đúng guru → trả lời thử trên 1 channel test thành công.
7. ✅ `wp_{today}_webhook_log` có row, `wp_{today-4}_webhook_log` đã bị prune.

---

## 11. Câu hỏi mở (cần chốt trước khi code M1)

1. **Tên menu top-level**: dùng lại `BizCity Channels` hay tách ra `Channel Gateway`? → đề xuất **giữ `BizCity Channels`** + 2 submenu mới.
2. **Bindings storage**: bảng riêng (đã đề xuất) hay dùng `wp_options` JSON? → đề xuất **bảng riêng** vì cần FK lookup và admin UI list/filter.
3. **Multisite scope**: `webhook_log` per-blog (`wp_1_2026_..`, `wp_2_2026_..`) hay shared? → đề xuất **per-blog** (theo prefix `$wpdb->prefix`) để consistent với `channel_messages`.
4. **Replay cap**: giới hạn replay = `manage_options` hay tạo cap mới `bizcity_replay_webhook`? → đề xuất cap mới (audit nghiêm hơn).

---

> **Khi user duyệt phase này** → bắt đầu code M1 skeleton: Router class + daily-partition log + schema bump 1.1.0 + prune cron + 4 diagnostic task đầu, song song với CRM M2 outbound.
