# PHASE 0.32 — CRM Inbox Hub (Twin Brain ↔ Multi-Channel Conversations)

> **Plugin chủ:** `bizcity-twin-ai/plugins/bizcity-twin-crm/`
> **Phiên bản:** 1.0 — 2026-05-09 · **Trạng thái:** 📐 ROADMAP — M1 sẵn sàng triển khai
> **Tác giả:** Twin AI Architecture
> **Liên quan (đọc TRƯỚC khi code):**
> - [PHASE-0-RULE-EVENT-STREAM.md](PHASE-0-RULE-EVENT-STREAM.md) — R-EVT-1..9
> - [PHASE-0-RULE-INTENT-MONITOR.md](PHASE-0-RULE-INTENT-MONITOR.md) — R-IMN-1..7
> - [PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md](PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md)
> - [PHASE-0-RULE-GATEWAY-ONLY.md](PHASE-0-RULE-GATEWAY-ONLY.md)
> - [PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md](PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY.md)
> - [PHASE-0.12-TWIN-EVENT-STREAM-UNIFICATION.md](PHASE-0.12-TWIN-EVENT-STREAM-UNIFICATION.md)
> - [PHASE-0.17-INTENT-MONITOR-BACKEND.md](PHASE-0.17-INTENT-MONITOR-BACKEND.md)

---

## TL;DR

Xây 1 plugin nội bộ `bizcity-twin-crm` đóng vai **Inbox Hub thống nhất** — gom hội thoại từ Facebook / Messenger / Zalo OA / Zalo Hotline / WebChat về **1 mặt phẳng React SPA** trong wp-admin, để:

1. **Trace** — mọi câu trả lời của Twin Brain với khách đều hiển thị: prompt + KG passages + model + tokens + latency.
2. **Reply** — admin trả thủ công hoặc bật auto-reply theo từng inbox.
3. **Unify** — schema CRM-style (Inbox / Contact / Conversation / Message) port từ Chatwoot, channel adapter contract port từ ChatbotX, **không port code runtime**.

Phase này định nghĩa **3 milestone** (M1 → M3) và **8 RULE bắt buộc** (R-CRM-1..8) phải tuân thủ trước khi viết dòng code đầu tiên.

---

## 0. RULE BẮT BUỘC (đọc TRƯỚC, không thương lượng)

### R-CRM-1 — Bảng CRM là **state snapshot**, không phải log

CRM tables (`bizcity_crm_inboxes`, `bizcity_crm_contacts`, `bizcity_crm_contact_inboxes`, `bizcity_crm_conversations`, `bizcity_crm_messages`, `bizcity_crm_attachments`) là **bảng state** — lưu hiện trạng hội thoại (Channelable / Conversation / Message theo Chatwoot). Đây là ngoại lệ R-EVT-2 dạng "snapshot" như `twin_identity`.

**Bắt buộc:**
- Mọi `INSERT/UPDATE` vào CRM tables PHẢI đi qua `BizCity_CRM_Repository` và **emit event tương ứng qua `BizCity_Twin_Event_Bus::dispatch()`** TRONG CÙNG TRANSACTION:
  - `crm_message_received` — khi inbound message vào
  - `crm_message_sent` — khi outbound (do bot hoặc human) ra channel
  - `crm_message_failed` — khi gửi channel fail
  - `crm_conversation_opened` / `crm_conversation_resolved` / `crm_conversation_assigned`
  - `crm_contact_upserted`
- KHÔNG được `INSERT` thẳng từ controller / adapter / cron → repository là chốt chặn duy nhất.
- KHÔNG được tạo thêm bảng `*_log` / `*_audit` / `*_trace` cho CRM. Cần thêm chiều phân tích → thêm `event_type` mới + projection.
- Cột `external_source_id` (FB mid, Zalo msg_id, webchat session_id) PHẢI có UNIQUE index `(inbox_id, external_source_id)` để **idempotent webhook delivery** — Facebook/Zalo retry 3-5 lần là bình thường.

### R-CRM-2 — Channel Adapter là contract DUY NHẤT để inbound/outbound

**Bắt buộc:**
- Mỗi channel (facebook, messenger, zalo_oa, zalo_hotline, webchat) implement `BizCity_CRM_Channel_Adapter` interface.
- Đăng ký qua filter chuẩn `bizcity_crm_register_adapters` (giống pattern `bizcity_register_channel_integrations` của PHASE-0.31).
- KHÔNG được gọi thẳng API kênh từ business logic — luôn đi qua `$adapter->send()`.
- Adapter PHẢI **idempotent**: webhook deduplicate theo `external_source_id`; send retry safe (cùng `client_msg_id` không tạo 2 message).
- Adapter cũ (`bizcity-facebook-bot`, `bizcity-zalo-bot`, `bizcity-bot-webchat`) **vẫn chạy độc lập** — CRM chỉ subscribe `do_action` tự nhiên của họ; KHÔNG fork code.

### R-CRM-3 — Brain hand-off đi qua Event Bus, không gọi LLM trực tiếp

**Bắt buộc:**
- Khi `crm_message_received` được emit, **listener `BizCity_CRM_AI_Responder`** quyết định có auto-reply không (theo flag `inbox.settings.ai_auto_reply`).
- Nếu auto-reply on → gọi `BizCity_LLM_Router` (qua `bizcity-llm-router` plugin) **với `parent_event_uuid`** = uuid của `crm_message_received` để build causal chain (R-EVT-6).
- Output LLM lưu dưới dạng outbound `crm_messages` row + `ai_metadata` JSON + emit `crm_message_sent` event.
- KHÔNG được gọi thẳng OpenAI/Claude/Gemini từ adapter hoặc controller. KHÔNG được lưu API key trong CRM plugin.

### R-CRM-4 — Inbox UI là React SPA, dùng tokens & Frontend Standard

**Bắt buộc:**
- Stack: **React 18 + Vite + Redux Toolkit + React Router + Tailwind + Headless UI + Heroicons** (đồng bộ TwinChat hiện tại + LMS reference). KHÔNG dùng Vue, KHÔNG dùng Alpine.js cho inbox SPA (Alpine chỉ dùng cho widget nhỏ).
- Mount trong wp-admin qua iframe-less embed: `<div id="bizcity-crm-inbox-root"></div>` + `wp_enqueue_script` bundle build.
- Hash routing (`#/inbox/:inboxId/conversation/:convId`) — KHÔNG history routing (đụng wp-admin route).
- Bảng màu / spacing / typography: dùng `design-tokens.css` chung của Twin AI; KHÔNG tự đặt color hex riêng.
- Realtime: **polling 2-5s** qua REST `bizcity-crm/v1/conversations?since=...` — TUÂN THỦ R-IMN-5 (cấm SSE/WebSocket riêng từ admin).
- Lazy-load conversation detail (`/conversations/:id/messages`) chỉ khi user mở; KHÔNG load all upfront.

### R-CRM-5 — REST API namespace cố định + response shape chuẩn

**Bắt buộc:**
- Operations (CRUD inbox / conversation / message) → namespace `bizcity-crm/v1/...`
- Observability (trace của 1 conversation, replay event chain) → namespace `bizcity-intent-monitor/v1/...` (R-IMN-3)
- Webhook callback từ kênh ngoài → namespace `bizcity-crm/v1/webhook/{channel_code}` — nhưng adapter cũ tự handle webhook ở plugin gốc, CRM chỉ là consumer. Endpoint webhook trong CRM chỉ tồn tại nếu có channel mới adapter-only (vd: Telegram chưa có plugin riêng).
- Permission: `manage_options` cho admin tabs; capability custom `bizcity_crm_handle_inbox` cho operator (dùng `add_cap`).
- Response shape (R-IMN-3):
  ```json
  { "ok": true, "data": [...], "next_cursor": "...", "ts": 1746800000000 }
  ```
- Mọi handler bắt buộc bọc `try { ... } catch (\Throwable $e) { return new WP_Error(...); }` — fail-loud nhưng không HTML.

### R-CRM-6 — Top-level menu CHỈ cho operations, observability ở Intent Monitor

**Bắt buộc:**
- Tạo top-level menu `BizCity CRM` (slug `bizcity-crm`, dashicon `dashicons-format-chat`, position 26 — sát menu Comments). Đây là **operations surface**, không vi phạm R-IMN-1.
- Submenu thuộc `BizCity CRM`:
  - Inbox (default page)
  - Channels (manage adapters / connect FB Page / Zalo OA — **deep-link** sang Automation → Integrations theo PHASE-0.31, KHÔNG tự config)
  - Settings (auto-reply rules, business hours, default notebook per inbox)
- Trace / Replay / Event chain → **submenu của `bizcity-intent-monitor`** (R-IMN-1, R-IMN-3, R-IMN-4):
  - Tab `CRM Conversations` đăng ký qua filter `bizcity_intent_monitor_tabs`.
  - Click 🧠 trong message bubble → mở drawer hoặc deep-link sang `admin.php?page=bizcity-intent-monitor&tab=crm-conversations&conv=123`.

### R-CRM-7 — Diagnostic-Driven Validation (DDV) bắt buộc 100% task

**Bắt buộc theo PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION:**
- Mỗi `[T-Mx.y]` trong roadmap dưới đây có 1 row tại trang `tools.php?page=bizcity-crm-sprint-diag` với 4 cột Task / Status / Check / Evidence.
- 3 layers evidence: Disk (file exists) + Loader (in `get_included_files()`) + Runtime (class/route/hook registered).
- Live probe button:
  - Adapter Facebook: gửi mock webhook payload → assert message row tạo + event emit
  - Send outbound: nhập text → gọi `adapter::send` → assert API response success
  - REST: loopback dispatch `rest_do_request` cho mỗi route
  - SQL: SAVEQUERIES on → assert query đếm đúng số lượng

### R-CRM-8 — Naming, runtime path, runtime ownership

**Bắt buộc:**
- Plugin path: `plugins/bizcity-twin-ai/plugins/bizcity-twin-crm/` (must-load qua bundled loader của Twin AI). KHÔNG cài ở `wp-content/plugins/` riêng.
- Class prefix: `BizCity_CRM_*` (trùng prefix folder).
- Table prefix: `{$wpdb->prefix}bizcity_crm_*` — **KHÔNG dùng `crm_*`** (nguy cơ đụng plugin CRM khác).
- Hook prefix: `bizcity_crm_*`.
- REST namespace: `bizcity-crm/v1` cho operations.
- Front bundle handle: `bizcity-crm-inbox-app`.
- File pattern: `class-*.php`, `class-adapter-*.php`, kebab-case như Twin AI quy ước.
- **Runtime location guard:** trước khi search/edit Facebook bot code, `Test-Path` cả 2:
  - `plugins/bizcity-twin-ai/plugins/bizcity-facebook-bot/` (current per repo memory)
  - `mu-plugins/bizcity-facebook-bot/` (per PHASE-0.31 note)
  - Path nào load thực tế → đó là runtime active. Đừng sửa file dead.

---

## 1. Bối cảnh & vấn đề

### 1.1. Tài sản hiện có

- ✅ `bizcity-facebook-bot` — webhook FB Messenger + DB `bizcity_facebook_bot_messages` (PSID, page_id, sender_type) + inbox cơ bản tại `class-admin-menu.php::render_inbox_page()`
- ✅ `bizcity-zalo-bot` — Zalo OA webhook + send API
- ✅ `bizcity-admin-hook-zalo` — Zalo Hotline 0562608899
- ✅ `bizcity-bot-webchat` — webchat widget + SSO Google
- ✅ `bizcity-llm-router` — LLM gateway + rate limit + Twin Brain orchestration
- ✅ `bizcity-twin-ai` core — KGHub, Notebook, Twin Event Stream (`bizcity_twin_event_stream`), Trace Store (`bizcity_traces`, `bizcity_trace_tasks`, `bizcity_trace_runs`, `bizcity_trace_artifacts`, `bizcity_trace_idempotency`, `bizcity_trace_resume_signals`, `bizcity_trace_task_queue`)

### 1.2. Khoảng trống

| Vấn đề | Hệ quả |
|---|---|
| Mỗi channel plugin lưu message theo schema riêng | Không truy vấn cross-channel "khách X đã chat ở đâu" |
| Không có lớp `Conversation` thống nhất | Brain trả lời đứt gãy giữa các session |
| Không có inbox UI cross-channel | Admin phải mở 4 tab khác nhau để theo dõi |
| Không trace được Brain trả gì cho ai dựa vào KG nào | Khó debug, không audit được chất lượng AI |
| Không có toggle "auto-reply on/off per inbox" | Khó scale (Brain trả tất cả hoặc không trả gì) |

### 1.3. Đánh giá thư viện reference (đã survey ở message trước)

| Thư viện | Mức copy | Đối tượng |
|---|---|---|
| **Chatwoot** (Rails+Vue) | 🟢 Data model + UX 3-pane inbox | `Conversation`/`Message`/`Channelable` mixin, ReplyBox, ConversationList layout |
| **ChatbotX** (Next.js+TS) | 🟢 Integration contract | `IntegrationDefinition<Config,Auth>` + `handleRequest(webhook\|callback)` |
| **ilmly-lms-frontend** (React+Redux) | 🟡 Scaffold style + Kanban | `AdminLayout` pattern + `leads/Card+Column+DropIndicator` Kanban (cho "Conversations Board" view sau M3) |

**Không port runtime của bất kỳ cái nào** — chỉ port mô hình. LMS frontend phù hợp **làm scaffold React + Tailwind tokens** vì stack trùng (React 18 + Vite + Redux Toolkit + Tailwind + Heroicons + Headless UI). LMS không có inbox UI sẵn, nên UX inbox vẫn theo Chatwoot.

---

## 2. Kiến trúc TO-BE

```
┌─────────────────────────────────────────────────────────────────────┐
│                         wp-admin (React SPA)                         │
│  ┌──────────┬───────────────────┬───────────────────────────────┐   │
│  │ Channels │ Conversation List │ Conversation Detail + 🧠 Trace│   │
│  └──────────┴───────────────────┴───────────────────────────────┘   │
└─────────────────────────────────────┬───────────────────────────────┘
                                      │ REST: bizcity-crm/v1/* (poll 2-5s)
┌─────────────────────────────────────▼───────────────────────────────┐
│                      bizcity-twin-crm (this plugin)                  │
│  ┌──────────────────────┐  ┌─────────────────────┐  ┌────────────┐  │
│  │ REST Controllers     │  │ Repository          │  │ AI         │  │
│  │ (operations)         │──┤ (CRM tables write)  │──┤ Responder  │  │
│  └──────────────────────┘  └──────────┬──────────┘  └─────┬──────┘  │
│                                        │ emit                │       │
│  ┌──────────────────────┐              ▼                     │       │
│  │ Channel Adapters     │   ┌────────────────────┐           │       │
│  │ (filter-registered)  │──▶│ Twin Event Bus     │◀──────────┘       │
│  └──────────────────────┘   │ (R-EVT-1)          │                   │
└──────────┬──────────────────┴──────────┬─────────┴───────────────────┘
           │ subscribe                    │ event_uuid chain
           │                              ▼
┌──────────┴────────────┐   ┌──────────────────────────────────────────┐
│ Existing channel      │   │ bizcity_twin_event_stream                │
│ plugins (FB/Zalo/Web) │   │ + projector → CRM tables (snapshots)     │
└───────────────────────┘   └──────────────────────────────────────────┘
                                          │
                                          ▼
                            ┌─────────────────────────────────┐
                            │ Intent Monitor (observability)  │
                            │ Tab: CRM Conversations          │
                            └─────────────────────────────────┘
```

---

## 3. Schema (port từ Chatwoot, đơn giản hoá cho WP)

> **Quy tắc đặt tên:** `{$wpdb->prefix}bizcity_crm_<noun>` — số nhiều, snake_case.
> **Engine:** InnoDB, charset từ `$wpdb->get_charset_collate()`.
> **Migration:** dùng `dbDelta` qua `BizCity_CRM_DB_Installer` — DB_VERSION option `bizcity_crm_db_ver`.

### 3.1. `bizcity_crm_inboxes` — 1 row / channel-account

| Column | Type | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `name` | VARCHAR(190) | Tên hiển thị: "FB Page Bizcity", "Zalo OA Bizcity" |
| `channel_type` | VARCHAR(32) | `facebook` \| `messenger` \| `zalo_oa` \| `zalo_hotline` \| `webchat` |
| `channel_ref_id` | VARCHAR(190) | Page ID / OA ID / widget key — KEY |
| `default_notebook_id` | BIGINT UNSIGNED NULL | Notebook nào Brain dùng cho inbox này |
| `default_assignee_id` | BIGINT UNSIGNED NULL | WP user mặc định nhận |
| `settings_json` | LONGTEXT | `{ai_auto_reply: bool, business_hours: {...}, greeting: str}` |
| `is_active` | TINYINT(1) | |
| `created_at` / `updated_at` | DATETIME | |

Index: `(channel_type, channel_ref_id)` UNIQUE; `(is_active)`.

### 3.2. `bizcity_crm_contacts` — danh bạ thật (dedupe theo email/phone)

| Column | Type | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `name` | VARCHAR(190) | |
| `email` | VARCHAR(190) NULL | KEY |
| `phone` | VARCHAR(32) NULL | KEY |
| `avatar_url` | TEXT NULL | |
| `additional_attributes` | LONGTEXT | JSON: locale, country, gender, ... |
| `wp_user_id` | BIGINT UNSIGNED NULL | Nếu khách có account WP (SSO) |
| `created_at` / `updated_at` | DATETIME | |

### 3.3. `bizcity_crm_contact_inboxes` — N:N contact ↔ inbox

| Column | Type | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `contact_id` | BIGINT UNSIGNED | FK |
| `inbox_id` | BIGINT UNSIGNED | FK |
| `source_id` | VARCHAR(190) | PSID / Zalo user_id / webchat visitor_id |
| `last_seen_at` | DATETIME | |

Index: `(inbox_id, source_id)` UNIQUE; `(contact_id)`.

### 3.4. `bizcity_crm_conversations` — 1 thread

| Column | Type | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `inbox_id` | BIGINT UNSIGNED | FK |
| `contact_inbox_id` | BIGINT UNSIGNED | FK |
| `status` | VARCHAR(16) | `open` \| `pending` \| `resolved` \| `snoozed` |
| `assignee_id` | BIGINT UNSIGNED NULL | WP user |
| `notebook_id` | BIGINT UNSIGNED NULL | Brain scope (override inbox default) |
| `priority` | TINYINT | 0-3 |
| `last_activity_at` | DATETIME | |
| `last_message_id` | BIGINT UNSIGNED NULL | Denorm cho list view |
| `unread_count` | INT UNSIGNED | |
| `created_at` / `updated_at` | DATETIME | |

Index: `(inbox_id, status, last_activity_at)`; `(assignee_id, status)`; `(contact_inbox_id)`.

### 3.5. `bizcity_crm_messages` — mỗi message

| Column | Type | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `conversation_id` | BIGINT UNSIGNED | FK |
| `external_source_id` | VARCHAR(190) NULL | FB mid / Zalo msg_id — UNIQUE per (inbox_id) |
| `inbox_id` | BIGINT UNSIGNED | Denorm để index UNIQUE compound |
| `content` | LONGTEXT | text body |
| `content_type` | VARCHAR(16) | `text` \| `image` \| `file` \| `audio` \| `card` \| `quick_reply` |
| `message_type` | VARCHAR(16) | `incoming` \| `outgoing` \| `activity` \| `template` |
| `sender_type` | VARCHAR(16) | `contact` \| `user` \| `bot` \| `system` |
| `sender_id` | BIGINT UNSIGNED NULL | contact_id hoặc wp_user_id |
| `status` | VARCHAR(16) | `pending` \| `sent` \| `delivered` \| `read` \| `failed` |
| `ai_metadata_json` | LONGTEXT NULL | `{model, prompt_tokens, completion_tokens, kg_passages: [...], notebook_id, latency_ms, trace_id, event_uuid}` |
| `event_uuid` | CHAR(36) NULL | UUID v7 của event_stream gốc — để click "Trace" mở chain |
| `created_at` | DATETIME | |

Index: `(conversation_id, created_at)`; `(inbox_id, external_source_id)` UNIQUE; `(sender_type, message_type)`.

### 3.6. `bizcity_crm_attachments` — file/image

| Column | Type | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `message_id` | BIGINT UNSIGNED | FK |
| `file_type` | VARCHAR(16) | `image` \| `audio` \| `video` \| `file` |
| `data_url` | TEXT | URL (FB CDN, S3, WP uploads) |
| `thumb_url` | TEXT NULL | |
| `meta_json` | LONGTEXT | size, mime, duration |
| `created_at` | DATETIME | |

Index: `(message_id)`.

---

## 4. Channel Adapter Contract (port từ ChatbotX)

```php
interface BizCity_CRM_Channel_Adapter {

    // ── Metadata ─────────────────────────────────────
    public function code(): string;          // 'facebook' | 'zalo_oa' | 'webchat'
    public function label(): string;
    public function capabilities(): array;   // ['text', 'image', 'file', 'quick_reply', 'typing', 'mark_seen']

    // ── Inbound: webhook payload → normalized message ───
    /**
     * @param array $raw  Raw webhook payload from channel
     * @return array|null Normalized: [
     *   'inbox_ref'         => '<page_id|oa_id>',
     *   'source_id'         => '<PSID|user_id>',
     *   'contact_name'      => string,
     *   'contact_avatar'    => string|null,
     *   'content'           => string,
     *   'content_type'      => 'text|image|...',
     *   'attachments'       => [ {file_type, data_url, ...} ],
     *   'external_source_id'=> string,  // dedupe key
     *   'received_at'       => DateTime,
     * ]
     */
    public function normalize_inbound( array $raw ): ?array;

    // ── Outbound: normalized message → channel API ────
    /**
     * @param object $conversation BizCity_CRM_Conversation
     * @param array  $message      ['content', 'content_type', 'attachments']
     * @return array ['success' => bool, 'external_source_id' => ?string, 'error' => ?string]
     */
    public function send( object $conversation, array $message ): array;

    // ── Optional ─────────────────────────────────────
    public function mark_seen( object $conversation, string $external_source_id ): void;
    public function set_typing( object $conversation, bool $on ): void;
}
```

**Đăng ký adapter:**

```php
add_filter( 'bizcity_crm_register_adapters', function ( array $adapters ): array {
    $adapters['facebook'] = new BizCity_CRM_Adapter_Facebook();
    return $adapters;
} );
```

---

## 5. Front-end React SPA — port style từ LMS, UX từ Chatwoot

### 5.1. Stack chốt

| Layer | Lựa chọn | Lý do |
|---|---|---|
| Framework | React 18 | Đồng bộ TwinChat, LMS reference |
| Build | Vite 5 | LMS dùng, build nhanh, ESM-friendly |
| State | Redux Toolkit + RTK Query | LMS dùng RTK; RTK Query xử lý poll cache đẹp |
| Router | React Router DOM 6 (HashRouter) | Hash để không đụng wp-admin route |
| Style | Tailwind CSS 3 + Heroicons + Headless UI | LMS dùng — khớp design system Twin AI |
| Animations | Framer Motion (optional) | Smooth panel transitions |
| HTTP | Axios + nonce header `X-WP-Nonce` | Pattern WP REST chuẩn |

### 5.2. Đánh giá port LMS frontend

| Khía cạnh | Có port? | Ghi chú |
|---|---|---|
| `AdminLayout` (sidebar + topbar + outlet) | ✅ Port | Adapt: bỏ login flow (đã có WP nonce), giữ layout |
| `redux/slices` pattern + `config/service.js` (Axios singleton) | ✅ Port | Đổi base URL → `wp-json/bizcity-crm/v1` + nonce header |
| Tailwind config + design tokens | ✅ Port | Đồng nhất với Twin AI tokens (kế thừa `design-tokens.css`) |
| `pages/leads/Card+Column+DropIndicator` Kanban | 🟡 Defer M3 | Dùng cho "Conversations Board" view sau khi inbox 3-pane stable |
| `components/Pagination`, `SearchBar`, `loaders/` | ✅ Port | Generic, tái dùng được |
| Login / Auth slice | ❌ KHÔNG port | WP đã handle auth qua nonce |
| Cookie service (`getCookie x-token`) | ❌ KHÔNG port | Dùng `wpApiSettings.nonce` thay |
| Pages: courses/groups/students/teachers | ❌ KHÔNG port | Domain LMS khác CRM |
| `react-to-print`, `xlsx`, `apexcharts` | ❌ KHÔNG port M1-M3 | Defer khi cần report |

**Kết luận:** LMS phù hợp ~30% (scaffold + Kanban), không có inbox UI sẵn. Port **scaffold + Kanban**, viết mới phần inbox theo UX Chatwoot.

### 5.3. Cấu trúc thư mục SPA

```
plugins/bizcity-twin-crm/
├── bootstrap.php
├── plugin-info.json
├── includes/
│   ├── class-plugin.php                    -- singleton, hook setup
│   ├── class-db-installer.php              -- dbDelta cho 6 bảng
│   ├── class-repository.php                -- write gate (R-CRM-1)
│   ├── class-channel-adapter-interface.php
│   ├── class-channel-registry.php          -- filter bizcity_crm_register_adapters
│   ├── class-ai-responder.php              -- listener crm_message_received
│   ├── class-event-projector.php           -- subscribe event stream → CRM tables
│   ├── class-admin-menu.php                -- top-level + 3 submenu
│   ├── class-rest-controller.php           -- bizcity-crm/v1
│   ├── class-monitor-tab.php               -- bizcity_intent_monitor_tabs filter
│   ├── class-sprint-diagnostic.php         -- DDV (R-CRM-7)
│   └── adapters/
│       ├── class-adapter-facebook.php      -- M1
│       ├── class-adapter-messenger.php     -- M2
│       ├── class-adapter-zalo-oa.php       -- M2
│       └── class-adapter-webchat.php       -- M2
├── frontend/
│   ├── package.json
│   ├── vite.config.js
│   ├── tailwind.config.js
│   ├── src/
│   │   ├── main.jsx
│   │   ├── App.jsx                         -- HashRouter + AdminLayout
│   │   ├── config/
│   │   │   ├── apiClient.js                -- Axios + X-WP-Nonce
│   │   │   └── routes.js
│   │   ├── redux/
│   │   │   ├── store.js
│   │   │   ├── api/                        -- RTK Query slices
│   │   │   │   ├── inboxesApi.js
│   │   │   │   ├── conversationsApi.js
│   │   │   │   └── messagesApi.js
│   │   │   └── slices/
│   │   │       ├── uiSlice.js              -- selectedInboxId, selectedConvId, filters
│   │   │       └── composerSlice.js
│   │   ├── components/
│   │   │   ├── AdminLayout.jsx             -- ported from LMS, adapted
│   │   │   ├── ChannelSidebar.jsx          -- ported list-style from LMS Navbar
│   │   │   ├── ConversationList.jsx
│   │   │   ├── ConversationDetail.jsx
│   │   │   ├── MessageBubble.jsx
│   │   │   ├── ReplyBox.jsx
│   │   │   ├── TraceDrawer.jsx             -- 🧠 panel
│   │   │   ├── EmptyState.jsx
│   │   │   └── loaders/                    -- ported from LMS
│   │   └── pages/
│   │       ├── InboxPage.jsx               -- 3-pane main
│   │       ├── ChannelsPage.jsx
│   │       └── SettingsPage.jsx
│   └── dist/                               -- build output → enqueued
└── tests/
    ├── test-repository.php
    ├── test-adapter-facebook.php
    └── fixtures/
        └── fb-webhook-payload.json
```

### 5.4. Layout 3-pane (UX Chatwoot)

```
┌──────────────┬───────────────────────────┬────────────────────────┐
│ ChannelSide  │ ConversationList          │ ConversationDetail     │
│  ─────────   │  ─────────────────        │  Header (contact info) │
│  ▾ Channels  │  [filter chips]           │  ──────────────────    │
│   ● FB Page  │  ┌──────────────────────┐ │  Messages scroll       │
│   ● Zalo OA  │  │ Avatar  Name      2m │ │   ◀ Khách: alo         │
│   ● WebChat  │  │ "alo còn không..."  │ │     Bot ▶ "Chào..." 🧠  │
│  ▾ Status    │  ├──────────────────────┤ │   ◀ Khách: yes         │
│   ● Open 12  │  │ Avatar  Name     15m │ │  ──────────────────    │
│   ● Pending  │  │ "..."                │ │  ReplyBox              │
│   ● Resolved │  └──────────────────────┘ │  [text]   [📎][😀][▶]  │
│              │                            │  ☑ AI Auto-reply       │
└──────────────┴───────────────────────────┴────────────────────────┘
```

Hash route: `#/inbox/123/conversation/456` — bookmarkable.

### 5.5. Realtime polling (R-CRM-4, R-IMN-5)

- RTK Query `pollingInterval: 3000` cho `getConversations({ since })`.
- Detail view: `pollingInterval: 2000` cho `getMessages({ convId, since_id })`.
- Khi tab inactive (`document.visibilityState !== 'visible'`) → pause polling.
- Backoff khi 5xx: 1.5s → 3s → 6s → 8s max.

---

## 6. Milestone & Task Table (DDV-ready)

> Mỗi `[T-Mx.y]` PHẢI có 1 row diagnostic.

### M1 — Skeleton + 6 bảng + Facebook adapter (read-only inbox)

| Task | Mô tả | Diagnostic check |
|---|---|---|
| **[T-M1.1]** | Tạo skeleton plugin: `bootstrap.php`, `plugin-info.json`, autoload, `BizCity_CRM_Plugin::instance()` singleton, `plugins_loaded` priority 6 | `class_exists('BizCity_CRM_Plugin')` + bootstrap.php in `get_included_files()` |
| **[T-M1.2]** | `BizCity_CRM_DB_Installer::install()` — tạo 6 bảng theo §3, set DB_VERSION option | `SHOW TABLES LIKE '..._bizcity_crm_%'` đếm = 6; `bizcity_crm_db_ver` option = '1.0' |
| **[T-M1.3]** | `BizCity_CRM_Repository::create_inbox/upsert_contact/open_or_get_conversation/insert_message` + emit event qua Event_Bus theo R-CRM-1 | Probe button: insert mock → assert row created + 1 row trong `bizcity_twin_event_stream` với `event_type=crm_message_received` |
| **[T-M1.4]** | `BizCity_CRM_Channel_Adapter` interface + `BizCity_CRM_Channel_Registry` (filter `bizcity_crm_register_adapters`) | `apply_filters('bizcity_crm_register_adapters', [])` không throw, trả mảng |
| **[T-M1.5]** | `BizCity_CRM_Adapter_Facebook` — `normalize_inbound()` parse payload từ `do_action('bizcity_facebook_bot_message_in', $raw)`; `send()` reuse `BizCity_Facebook_Bot_API::send_text()` | Probe: post fixture `fb-webhook-payload.json` → assert message + conversation row tạo |
| **[T-M1.6]** | Subscribe `do_action('bizcity_facebook_bot_message_in')` → adapter normalize → repository insert | Live: gửi tin thật vào FB Page test → 1 row mới xuất hiện trong inbox |
| **[T-M1.7]** | REST `GET bizcity-crm/v1/inboxes`, `GET bizcity-crm/v1/conversations?inbox_id=`, `GET bizcity-crm/v1/conversations/{id}/messages` (read-only) | Loopback `rest_do_request` cho 3 routes → status 200 + shape ok |
| **[T-M1.8]** | Top-level menu `BizCity CRM` + page Inbox renders `<div id="bizcity-crm-inbox-root"></div>` + enqueue bundle | `did_action('admin_menu')` + `wp_script_is('bizcity-crm-inbox-app','enqueued')` |
| **[T-M1.9]** | Frontend skeleton: Vite project, AdminLayout port từ LMS, ChannelSidebar + ConversationList + ConversationDetail rendered (dữ liệu từ M1.7) | Browser smoke: mở `/wp-admin/admin.php?page=bizcity-crm` → thấy 3-pane, click conv → bubble hiện |
| **[T-M1.10]** | `BizCity_CRM_Sprint_Diagnostic` — trang `tools.php?page=bizcity-crm-sprint-diag` với 10 row M1 | Diagnostic page render đầy đủ, nút Reset OPcache + Inspect SQL hoạt động |

**Definition of Done M1:** Tất cả 10 task PASS trong diagnostic page; gửi 1 tin từ FB Page test → admin mở wp-admin → thấy hội thoại + bubble đẹp; **chưa cần reply ra channel** (đó là M2).

### M2 — Outbound + Zalo + WebChat + Reply UI

| Task | Mô tả |
|---|---|
| **[T-M2.1]** | `Adapter_Facebook::send()` thực thi + emit `crm_message_sent` |
| **[T-M2.2]** | `Adapter_Zalo_OA` (subscribe `bizcity_zalo_bot_message_in` action có sẵn) |
| **[T-M2.3]** | `Adapter_WebChat` (subscribe `bizcity_webchat_message_in`) |
| **[T-M2.4]** | REST `POST bizcity-crm/v1/conversations/{id}/messages` — gửi tin manual |
| **[T-M2.5]** | Frontend ReplyBox + composer slice + optimistic update |
| **[T-M2.6]** | Polling RTK Query + visibility-aware pause |
| **[T-M2.7]** | Status update: `mark_seen`, conversation status change (open/resolved) |
| **[T-M2.8]** | Diagnostic rows + live probe send |

### M3 — Brain auto-reply + Trace Drawer + Intent Monitor tab

| Task | Mô tả |
|---|---|
| **[T-M3.1]** | `BizCity_CRM_AI_Responder` listener `crm_message_received` → check `inbox.settings.ai_auto_reply` → call `BizCity_LLM_Router` với `parent_event_uuid` |
| **[T-M3.2]** | Lưu `ai_metadata_json` (model, tokens, kg_passages, notebook_id, trace_id, event_uuid, latency_ms) |
| **[T-M3.3]** | Frontend `TraceDrawer` — click 🧠 trên message bubble → mở drawer side-panel hiện prompt + retrieved passages + cost |
| **[T-M3.4]** | Intent Monitor tab "CRM Conversations" qua filter `bizcity_intent_monitor_tabs` (R-IMN-4) — list trace gần đây, deep-link sang inbox |
| **[T-M3.5]** | Toggle `ai_auto_reply` per inbox trong Settings page |
| **[T-M3.6]** | REST `bizcity-intent-monitor/v1/crm-conversations` — list traces gắn với CRM messages |
| **[T-M3.7]** | Diagnostic rows + replay event chain probe |

---

## 7. Event Taxonomy bổ sung (cập nhật `BizCity_Event_Taxonomy`)

Theo R-EVT-2, thêm 7 event_type mới:

| event_type | Khi nào emit | Payload bắt buộc |
|---|---|---|
| `crm_inbox_created` | sau insert inbox | `{inbox_id, channel_type, channel_ref_id}` |
| `crm_contact_upserted` | sau upsert contact | `{contact_id, source_id, inbox_id}` |
| `crm_conversation_opened` | conversation status `open` lần đầu | `{conversation_id, inbox_id, contact_inbox_id}` |
| `crm_conversation_resolved` | status → `resolved` | `{conversation_id, by_user_id}` |
| `crm_conversation_assigned` | assignee_id thay đổi | `{conversation_id, assignee_id, prev_assignee_id}` |
| `crm_message_received` | inbound message insert | `{message_id, conversation_id, inbox_id, content_type, sender_type:'contact', external_source_id}` |
| `crm_message_sent` | outbound thành công | `{message_id, conversation_id, sender_type:'bot'\|'user', ai_metadata?, parent_event_uuid?}` |
| `crm_message_failed` | gửi channel fail | `{message_id, conversation_id, error_code, error_message, retry_count}` |

**Schema files:** `core/twin-core/event-stream/schemas/events/crm_*.json` — tạo trong M1.3.

---

## 8. Anti-patterns CẤM (CRM-specific)

| ❌ Sai | ✅ Đúng |
|---|---|
| `$wpdb->insert($prefix.'bizcity_crm_messages', ...)` từ controller | `BizCity_CRM_Repository::insert_message()` (gateway có emit event) |
| Tạo `bizcity_crm_message_logs` để debug | Thêm `event_type` mới hoặc field vào `payload_json` |
| FB adapter gọi thẳng Graph API curl | Reuse `BizCity_Facebook_Bot_API` đã có |
| Frontend mở `EventSource` riêng | RTK Query polling 2-5s + visibility pause |
| Mount inbox vào `tools.php` | Top-level menu `bizcity-crm` (R-CRM-6) |
| Trace UI top-level menu | Submenu Intent Monitor (R-IMN-1) |
| Hard-code FB token vào `bizcity_crm_inboxes.settings_json` | Reuse credential storage của `bizcity-facebook-bot` (PHASE-0.31 §3) |
| Vue/Svelte/Alpine cho inbox SPA | React 18 + RTK + Tailwind (R-CRM-4) |
| Bỏ qua diagnostic page "vì code đã chạy" | Diagnostic PASS = task done (R-CRM-7) |
| Sửa code Facebook bot ở 2 path khác nhau | `Test-Path` trước, chỉ sửa runtime active (R-CRM-8) |

---

## 9. Checklist M1 trước khi push commit đầu tiên

- [ ] Đọc đủ 4 RULE đính kèm + 8 RULE R-CRM-* trong file này
- [ ] Tạo branch `feature/phase-0.32-crm-m1`
- [ ] `Test-Path` cả 2 location của bizcity-facebook-bot, ghi chú vào commit message
- [ ] Skeleton plugin + 6 bảng + repository + interface (T-M1.1 → T-M1.4)
- [ ] Adapter Facebook subscribe action có sẵn (T-M1.5 → T-M1.6)
- [ ] REST 3 endpoints read-only (T-M1.7)
- [ ] Top-level menu + script enqueue (T-M1.8)
- [ ] Frontend skeleton render được 3-pane với mock data (T-M1.9)
- [ ] Diagnostic page 10 row, 100% PASS với evidence 3-layer (T-M1.10)
- [ ] Update `BizCity_Event_Taxonomy` với 7 event_type mới + 7 schema JSON
- [ ] Smoke test: gửi tin từ FB test page → wp-admin thấy hội thoại
- [ ] Update [PHASE-0-RULES.md](PHASE-0-RULES.md) (nếu có) thêm pointer tới file này

---

## 10. Mục Phase tiếp theo (sau M3)

- **PHASE 0.33** — CRM Conversations Board (Kanban view, port `leads/` từ LMS frontend) cho team quản lý funnel.
- **PHASE 0.34** — Macros / Canned responses + Tag / Label.
- **PHASE 0.35** — SLA + Business Hours + Auto-assign rules.
- **PHASE 0.36** — Tích hợp WhatsApp Business + Telegram (qua adapter mới, không cần plugin trung gian).
- **PHASE 0.37** — Customer 360 view (gộp với `bizcity-twin-crm/_library/` reference data — courses/leads từ LMS pattern).

---

## 11. Reference

- Chatwoot data model: `_library/chatwoot-develop/app/models/{conversation,message,inbox,contact,contact_inbox}.rb`
- Chatwoot Channelable mixin: `app/models/concerns/channelable.rb` + `app/models/channel/facebook_page.rb`
- ChatbotX Integration contract: `_library/ChatbotX-main/integrations/zalo/src/integration.ts` (mẫu chuẩn)
- LMS scaffold: `_library/ilmly-lms-frontend-main/src/{App.jsx,components/Navbar.jsx,pages/leads/}`
- Existing Inbox UI hiện tại: `bizcity-facebook-bot/includes/class-admin-menu.php::render_inbox_page()` (basic, sẽ deprecate sau M2)

---

**Đọc xong → bắt đầu T-M1.1.** Vi phạm bất kỳ R-CRM-* nào → revert PR, không merge.
