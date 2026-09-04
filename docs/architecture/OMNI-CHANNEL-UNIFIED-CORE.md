# Unified Core Architecture — Omni-Channel · Contact · Event · Campaign

> Version: 1.0.0 · Ngày: 2026-06-15 · Tác giả: Johnny Chu  
> Phạm vi: `bizcity-twin-ai` + `bizcity-twin-crm` + `core/channel-gateway` + `core/automation` + `core/scheduler`  
> Mục đích: Thiết kế "xương sống" xuyên suốt, không gap, không mất data, mượt giữa tất cả flow.

---

## 1. Bức tranh tổng thể (Target Architecture)

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│  INBOUND CHANNELS (Zone 1 & 2)                                                   │
│  FB Messenger · Zalo OA · Zalo Personal · Telegram · WebChat · Email             │
│  Zalo Bot (Zone 2 admin) · TwinBrain TwinChat (Zone 2 admin)                     │
└──────────────────────────────────────────────────────────────────────────────────┘
                │ webhook → Channel Gateway UCL
                ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│  LAYER 0 — RAW TRANSPORT LEDGER                                                  │
│  bizcity_channel_messages  (per-blog, owned by core/channel-gateway)             │
│  • 1 row per message, any channel, any direction                                 │
│  • Không phân biệt Zone 1/2                                                      │
│  • Source of truth cho retry, dedup, raw payload                                 │
│  • hooks: bizcity_channel_message_logged(msg_id, platform, chat_id, body)        │
└──────────────────────────────────────────────────────────────────────────────────┘
                │ Zone 1 only (R-ZONE)
                ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│  LAYER 1 — CONTACT IDENTITY RESOLUTION (SKELETON XUYÊN SUỐT)                   │
│                                                                                  │
│  bizcity_crm_contacts          bizcity_crm_contact_identities  (NEW v2.0)        │
│  ─────────────────────         ──────────────────────────────────────────        │
│  id (PK)                       id (PK)                                           │
│  name, email, phone            contact_id  → FK crm_contacts.id                 │
│  avatar_url                    platform    (FACEBOOK|ZALO|ZALO_BOT|TELEGRAM|     │
│  lead_score, segment           │            WEBCHAT|EMAIL|TWINBRAIN)             │
│  wp_user_id (nullable)         platform_uid (FB PSID / Zalo user_id / chat_id)  │
│  created_at, updated_at        account_id   (Page ID / OA ID / Bot ID)          │
│  deleted_at                    display_name (denorm từ platform)                 │
│                                avatar_url   (denorm từ platform)                 │
│  DEDUP KEY:                    is_primary   (1 = identity chính)                 │
│  KHÔNG còn platform+uid        last_active_at                                    │
│  unique trên contacts          created_at, updated_at                            │
│  → CHUYỂN sang identities      UNIQUE(platform, platform_uid, account_id)        │
└──────────────────────────────────────────────────────────────────────────────────┘
                │ resolve/upsert contact
                ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│  LAYER 2 — OMNI-CHANNEL INBOX STATE (Zone 1 only)                               │
│                                                                                  │
│  bizcity_crm_inboxes           bizcity_crm_conversations                         │
│  ─────────────────────         ──────────────────────────                        │
│  id, name, channel_type        id (PK)                                           │
│  channel_id                    inbox_id  → FK inboxes.id                         │
│  settings_json                 contact_id → FK contacts.id                       │
│  (1 row per Page/OA/account)   status (open|resolved|pending|snoozed)            │
│                                acquisition_source (campaign:X | qr:Y | organic) │
│                                workflow_run_id (nullable) ← NEW v3.0            │
│                                created_at, updated_at                            │
│                                                                                  │
│  bizcity_crm_messages                                                            │
│  ─────────────────────                                                           │
│  conversation_id → FK          channel_message_id → FK channel_messages.id      │
│  direction (1=in, 2=out)       ← NEW v3.0 (link back to raw ledger)             │
│  body, content_type            automation_run_id (nullable) ← NEW v3.0          │
│  platform_msg_id               created_at                                        │
└──────────────────────────────────────────────────────────────────────────────────┘
                │ bizcity_crm_conversation_created/message_received hook
                ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│  LAYER 3 — EVENT BACKBONE (XƯƠNG SỐNG TRUNG TÂM)                               │
│                                                                                  │
│  bizcity_crm_events   (per-blog, owned by core/scheduler)                        │
│  ──────────────────────────────────────────────────────                          │
│  id, user_id, title, description                                                 │
│  start_at, end_at, all_day                                                       │
│  reminder_min, reminder_sent, reminder_claimed_at                                │
│  status (active|draft|done|cancelled)                                            │
│  event_type  ← CANONICAL TYPE LIST (§3)                                          │
│  source      ← WHO CREATED (§4)                                                  │
│  metadata    ← JSON block (§5)                                                   │
│  google_*    (calendar sync)                                                     │
│                                                                                  │
│  NEW COLUMNS v3.6:                                                               │
│  contact_id       BIGINT NULL  ← FK crm_contacts.id                             │
│  conversation_id  BIGINT NULL  ← FK crm_conversations.id                         │
│  campaign_id      BIGINT NULL  ← FK crm_broadcasts.id hoặc crm_campaigns.id     │
│  qr_code_id       VARCHAR(64)  ← FK qr_codes (new table §8)                     │
└──────────────────────────────────────────────────────────────────────────────────┘
                │ event_type + metadata → route to handler
                ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│  LAYER 4 — AUTOMATION ENGINE                                                     │
│                                                                                  │
│  bizcity_automation_workflows  bizcity_automation_runs                           │
│  ─────────────────────────     ─────────────────────────                         │
│  trigger_type + config         workflow_id                                       │
│  enabled, graph_json           contact_id  ← NEW v1.9                           │
│                                conversation_id ← NEW v1.9                        │
│                                crm_event_id (FK crm_events)                     │
│                                trigger_payload_json                              │
│                                status, started_at, ended_at                      │
│                                                                                  │
│  NEW TRIGGER TYPES v1.9:                                                         │
│  crm_conversation_created · crm_contact_updated · crm_deal_stage_changed         │
│  qr_scan · broadcast_done                                                        │
└──────────────────────────────────────────────────────────────────────────────────┘
                │ via BizCity_Cron_Manager
                ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│  LAYER 5 — OBSERVABILITY                                                        │
│  bizcity_cron_registry · bizcity_cron_runs · bizcity_cron_retries               │
│  JSONL logs: wp_uploads/bizcity_cron_logs/YYYY-MM-DD.jsonl                      │
│  bizcity_crm_audit_log (twin-crm — entity mutations)                            │
│  bizcity_twin_event_stream (twin event bus — workflow nodes, LLM phases)         │
└──────────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Câu trả lời 4 vấn đề cốt lõi

### 2.1 — Omni-Channel Inbox: unify về đâu?

**Nguyên tắc thiết kế:**

| Store | Mục đích | Zone | Owner |
|---|---|---|---|
| `bizcity_channel_messages` | Raw transport ledger — mọi kênh, mọi zone, cả admin command | 1 + 2 | core/channel-gateway |
| `bizcity_crm_conversations` | Customer conversation state thread — 1 thread/khách/inbox | Zone 1 only | bizcity-twin-crm |
| `bizcity_crm_messages` | Per-message trong conversation — bao gồm AI response | Zone 1 only | bizcity-twin-crm |

**KHÔNG merge 2 bảng thành 1.** Lý do:
- `bizcity_channel_messages` cần ghi TRƯỚC khi biết `contact_id` (contact có thể chưa tồn tại khi webhook về)
- Zone 2 (Zalo Bot admin, TwinChat) cần `channel_messages` nhưng KHÔNG được tạo `crm_conversations` (R-ZONE)
- `crm_messages` cần thêm fields CRM (private note, author_id, macro_id) mà `channel_messages` không có

**Bridge tự động (NEW — CRM_Inbox_Bridge):**

```
Inbound webhook đến
  → UCL normalize → bizcity_channel_messages INSERT (msg_id='cm_xxx')
  → do_action('bizcity_channel_message_logged', $msg_id, $platform, $chat_id, $body)
    │
    ├─ [Zone 1] BizCity_CRM_Inbox_Bridge::on_message_logged()
    │     1. Resolve contact_id qua bizcity_crm_contact_identities
    │        (platform+platform_uid+account_id → contact_id)
    │        Nếu chưa có: INSERT bizcity_crm_contacts + INSERT identities
    │     2. Resolve inbox_id qua bizcity_crm_inboxes (channel_type+channel_id)
    │     3. Upsert bizcity_crm_conversations (contact_id+inbox_id)
    │        → acquisition_source từ metadata (campaign:X, qr:Y, organic)
    │     4. INSERT bizcity_crm_messages (conversation_id, channel_message_id)
    │     5. do_action('bizcity_crm_conversation_updated', $conv_id)
    │
    └─ [Zone 2] BAIL (code=zalo_bot|telegram|twinchat_be → skip bridge)
```

**Kết quả:** Admin mở CRM Inbox → thấy tất cả kênh Zone 1 trong 1 view. Filter theo `inbox.channel_type` để xem Zalo / Facebook / WebChat riêng. Không cần nhiều tab.

---

### 2.2 — Contact Skeleton: `bizcity_crm_contacts` là master

**Vấn đề hiện tại:** `bizcity_crm_contacts` có UNIQUE(`platform`, `platform_uid`) → 1 contact = 1 platform. Người dùng nhắn trên cả Zalo lẫn Facebook = 2 contact rows khác nhau. Không merge được.

**Giải pháp: tách Contact Identity ra bảng riêng**

```sql
-- bizcity_crm_contact_identities (NEW v2.0)
CREATE TABLE {prefix}bizcity_crm_contact_identities (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id      BIGINT UNSIGNED NOT NULL,  -- FK crm_contacts.id
    platform        VARCHAR(32) NOT NULL,       -- FACEBOOK|ZALO|ZALO_BOT|TELEGRAM|WEBCHAT|EMAIL|TWINBRAIN
    platform_uid    VARCHAR(190) NOT NULL,      -- FB PSID / Zalo user_id / telegram chat_id
    account_id      VARCHAR(190) NULL,          -- Page ID / OA ID / Bot ID (account phía platform)
    display_name    VARCHAR(255) NULL,          -- denorm từ platform API
    avatar_url      VARCHAR(512) NULL,          -- denorm từ platform API
    is_primary      TINYINT(1) NOT NULL DEFAULT 0,
    last_active_at  DATETIME NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_platform_identity (platform, platform_uid, account_id),
    KEY idx_contact (contact_id),
    KEY idx_platform (platform, account_id)
);
```

**Migration `bizcity_crm_contacts`:**
- Xoá UNIQUE(`platform`, `platform_uid`) từ `contacts`
- Giữ `platform`, `platform_uid` trên `contacts` như **backward-compat columns** (deprecated)
- Backfill: 1 row contacts → 1 row identities (is_primary=1)
- Sau đó: mọi lookup mới dùng `contact_identities` table
- `contacts.platform` + `contacts.platform_uid` sẽ bị deprecated ở v2.1

**Canonical lookup function:**

```php
// BizCity_CRM_Contact_Repository::resolve_by_identity($platform, $platform_uid, $account_id, $display_name)
// 1. SELECT contact_id FROM contact_identities WHERE platform=? AND platform_uid=? AND account_id=?
// 2. Nếu không có → INSERT contacts (name=$display_name) + INSERT identities
// 3. Return contact_id
```

**Mapping platform UIDs:**

| Platform | platform_uid | account_id |
|---|---|---|
| FACEBOOK | Facebook PSID (page-scoped user ID) | Page ID |
| ZALO | Zalo user_id | OA ID |
| ZALO_BOT | Zalo user_id (bot friend) | OA/Bot ID |
| TELEGRAM | Telegram chat_id | Bot username |
| WEBCHAT | session_id (hoặc wp_user_id sau login) | site_url |
| EMAIL | email address | — |
| TWINBRAIN | wp_user_id (as string) | — |
| WP_USER | wp_user_id (as string) | — |

**Merge contacts (khi biết 2 identities = cùng người):**
- Admin mở profile contact → click "Gộp với ..."
- `UPDATE contact_identities SET contact_id = $keep_id WHERE contact_id = $merge_id`
- `DELETE FROM contacts WHERE id = $merge_id`
- Hook: `do_action('bizcity_crm_contacts_merged', $keep_id, $merge_id)`
- `bizcity_crm_conversations` tự động chuyển về `$keep_id` qua FK cascade

---

### 2.3 — Events Backbone: `bizcity_crm_events` là trung tâm

**Nguyên tắc:** Mọi "việc có thời gian xảy ra" đều là 1 row trong `bizcity_crm_events`. Đây là nơi duy nhất để:
- Lên lịch (scheduler)
- Theo dõi tiến độ automation
- Hiển thị trên Calendar
- Gửi reminder

**Canonical `event_type` đầy đủ (v3.6):**

| event_type | Handler | Owner | Mô tả |
|---|---|---|---|
| `meeting` | — | core/scheduler | Cuộc họp người dùng tạo |
| `reminder` | — | core/scheduler | Reminder thủ công |
| `task` | — | core/scheduler | Task to-do |
| `reminder_personal` | HIL Router | core/scheduler | Reminder qua TwinBrain master |
| `reminder_zalo` | Zalo Reminder | core/channel-gateway | Gửi reminder qua Zalo |
| `telegram_send` | Telegram Publisher | core/scheduler | Gửi Telegram |
| `fb_post` | FB Publisher | core/channel-gateway | Đăng bài Facebook scheduled |
| `web_post` | Web Post Publisher | core/channel-gateway | Đăng bài WordPress |
| `automation_workflow` | Automation Matcher | core/automation | Trigger workflow theo lịch |
| `broadcast_scheduled` | Broadcast Dispatcher | bizcity-twin-crm | Broadcast campaign lên lịch **(NEW)** |
| `qr_scan_followup` | QR Automation Handler | core/channel-gateway | Follow-up sau QR scan **(NEW)** |
| `crm_conversation_task` | — | bizcity-twin-crm | Task gắn với 1 conversation **(NEW)** |
| `woo_product_create` | Woo Handler | core/channel-gateway | Tạo sản phẩm WooCommerce |
| `woo_order_create` | Woo Handler | core/channel-gateway | Tạo đơn hàng WooCommerce |
| `lead_report` | Lead Report | core/channel-gateway | Báo cáo lead |

**3 columns mới v3.6 trên `bizcity_crm_events`:**

```sql
ALTER TABLE {prefix}bizcity_crm_events
  ADD COLUMN contact_id      BIGINT UNSIGNED NULL DEFAULT NULL
             COMMENT 'FK crm_contacts.id — ai là chủ thể của event này'
             AFTER user_id,
  ADD COLUMN conversation_id BIGINT UNSIGNED NULL DEFAULT NULL
             COMMENT 'FK crm_conversations.id — event gắn với conversation nào'
             AFTER contact_id,
  ADD COLUMN campaign_id     BIGINT UNSIGNED NULL DEFAULT NULL
             COMMENT 'FK crm_broadcasts.id — event được tạo bởi campaign nào'
             AFTER conversation_id,
  ADD KEY idx_contact (contact_id),
  ADD KEY idx_conversation (conversation_id),
  ADD KEY idx_campaign (campaign_id);
```

---

### 2.4 — Campaign QR Code: Skeleton xuyên suốt

**Hiện tại thiếu:** Không có table canonical cho QR code definition.

**Đề xuất: `bizcity_crm_qr_codes` (NEW)**

```sql
CREATE TABLE {prefix}bizcity_crm_qr_codes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(64) NOT NULL,         -- dùng trong URL ?biz_qr=xxxx
    name            VARCHAR(255) NOT NULL DEFAULT '',
    campaign_id     BIGINT UNSIGNED NULL,         -- FK crm_broadcasts.id (optional)
    workflow_id     BIGINT UNSIGNED NULL,         -- FK automation_workflows.id
    inbox_id        BIGINT UNSIGNED NULL,         -- mở conversation tại inbox nào
    attribution     VARCHAR(128) NULL,            -- 'campaign:camp_X', 'product:P5', 'event:E8'
    welcome_message TEXT NULL,                    -- tin chào khi user scan
    redirect_url    VARCHAR(512) NULL,            -- redirect sau scan (optional)
    scan_count      INT UNSIGNED NOT NULL DEFAULT 0,
    unique_scan_count INT UNSIGNED NOT NULL DEFAULT 0,
    status          VARCHAR(16) NOT NULL DEFAULT 'active', -- active|paused|archived
    expires_at      DATETIME NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_slug (slug),
    KEY idx_workflow (workflow_id),
    KEY idx_campaign (campaign_id),
    KEY idx_status (status)
);
```

**Flow khi user scan QR:**

```
User scan QR code → truy cập URL /?biz_qr=SLUG
    │
    ▼
BizCity_QR_Handler::handle_scan($slug, $platform_context)
    1. SELECT * FROM qr_codes WHERE slug=? AND status='active'
    2. Increment scan_count (+ unique check qua session/cookie)
    3. Log bizcity_crm_events:
       {event_type='qr_scan_followup',
        source='qr_code',
        contact_id=?,       ← resolve sau khi user auth/identify
        campaign_id=qr.campaign_id,
        metadata={
          qr_code_id: qr.id, qr_slug: slug, attribution: qr.attribution,
          platform: detected_platform, ip: hash(ip), user_agent: ua,
          inbound: {platform, chat_id, ...}
        }}
    4. Nếu qr.workflow_id → fire bizcity_automation_trigger('qr_scan', {qr_id, contact_id, ...})
    5. Nếu qr.inbox_id → tạo bizcity_crm_conversations (để agent follow-up)
    6. Nếu qr.redirect_url → redirect
       Nếu qr.welcome_message + platform là Zalo/FB → gửi tin chào
```

**Tracking attribution trong `bizcity_crm_conversations`:**
```
bizcity_crm_conversations.acquisition_source = 'qr:SLUG'
→ Admin xem report: bao nhiêu conversation đến từ QR nào
→ Campaign analytics: QR scan → conversation rate
```

---

## 3. Canonical `event_type` và `source` whitelist (v3.6)

### event_type sources
```
source = 'user'           — tạo thủ công trong calendar UI
source = 'ai_plan'        — TwinBrain lên kế hoạch
source = 'ai_reminder'    — TwinBrain đặt reminder
source = 'workflow'       — Automation workflow tạo (BizCity_Automation_Schedule_Manager)
source = 'channel_gateway'— Channel gateway publisher (fb_post, web_post, zalo_reminder)
source = 'crm_calendar'   — CRM Calendar tool tạo
source = 'crm_inbox'      — Tạo từ conversation trong CRM Inbox
source = 'broadcast'      — Broadcast campaign dispatcher  ← NEW
source = 'qr_code'        — Từ QR code scan                ← NEW
source = 'google_sync'    — Import từ Google Calendar
source = 'external_sync'  — Import từ source ngoài
```

---

## 4. Automation Trigger Types (v1.9) — đầy đủ

```
── INBOUND CHANNEL (Zone 1) ──
zalo_inbound          — Zalo OA message
fb_message            — Facebook Messenger DM
fb_comment            — Facebook Page comment
telegram_inbound      — Telegram bot message
webchat_inbound       — WebChat widget message

── INBOUND CHANNEL (Zone 2) ──
zalo_bot_command      — Zalo Bot admin command
twinchat_command      — TwinChat admin command

── CRON / SCHEDULE ──
cron                  — cron expression schedule
scheduler             — bizcity_crm_events fire (reminder_personal, automation_workflow)

── WEBHOOK ──
webhook               — External HTTP POST webhook

── CRM EVENTS (NEW v1.9) ──
crm_conversation_created  — New conversation opened in any inbox
crm_conversation_resolved — Conversation marked resolved
crm_message_created       — Any new message in any conversation
crm_contact_updated       — Contact profile changed (lead_score, segment, custom attr)
crm_deal_stage_changed    — CRM opportunity stage change
crm_label_added           — Label applied to conversation

── CAMPAIGN / QR (NEW v1.9) ──
qr_scan               — User scanned QR code
broadcast_done        — Broadcast send completed (for follow-up workflows)
broadcast_recipient_sent — Per-recipient send event

── TWINBRAIN / AI ──
twinbrain_intent      — TwinBrain intent resolved
twinbrain_turn_completed — synthesis_done/final_done/agent_loop_done
twinbrain_tool_decided   — Stage 3 tool suggestion
skill_intent          — Skill A/B/C invocation

── WOOCOMMERCE ──
woo_order_created     — New WooCommerce order
woo_order_status_changed — Order status change
```

---

## 5. `metadata` JSON Schema cho từng event_type (canonical §5)

### 5.1 `broadcast_scheduled` (campaign scheduling)

```jsonc
{
  "broadcast_id": 42,                    // FK crm_broadcasts.id
  "broadcast_title": "Khuyến mãi Tết",
  "inbox_ids": [1, 2],                   // inboxes broadcast từ
  "recipient_count": 1250,               // tổng recipient đã enqueue
  "batch_size": 50,                      // mỗi cron tick gửi bao nhiêu
  "delay_sec": 5,                        // khoảng cách giữa sends
  "run_status": "pending|sending|done|failed|cancelled",
  "inbound": { "platform": "ADMIN", "chat_id": "", "user_id": "1", "intent_tag": "broadcast" }
}
```

### 5.2 `qr_scan_followup` (QR code tracking)

```jsonc
{
  "qr_code_id": 7,
  "qr_slug": "khuyen-mai-he-2026",
  "attribution": "campaign:42",
  "scan_number": 3,                      // lần scan này là lần thứ mấy của QR
  "is_unique_scan": true,
  "platform": "ZALO",                    // phát hiện từ user-agent / URL param
  "workflow_id": 15,                     // workflow được trigger
  "welcome_sent": true,
  "inbound": { "platform": "ZALO", "chat_id": "zalo_user_xxx", "intent_tag": "qr_scan" }
}
```

### 5.3 `crm_conversation_task` (task gắn conversation)

```jsonc
{
  "conversation_id": 88,
  "contact_id": 33,
  "inbox_id": 2,
  "assigned_to": 5,                      // wp_user_id của agent
  "task_type": "follow_up|call_back|send_quote|manual",
  "due_note": "Gửi báo giá theo yêu cầu của khách",
  "inbound": { "platform": "ZALO", "chat_id": "...", "intent_tag": "task" }
}
```

---

## 6. Flow Integration Matrix — mọi flow qua đâu?

### Flow A: Customer gửi tin Zalo OA → AI trả lời → Task follow-up

```
1. Zalo OA webhook → UCL normalize
2. bizcity_channel_messages INSERT (raw_message, direction=inbound)
3. CRM_Inbox_Bridge::on_message_logged()
   → resolve/create bizcity_crm_contacts + bizcity_crm_contact_identities
   → resolve/create bizcity_crm_conversations
   → INSERT bizcity_crm_messages (link channel_message_id)
4. do_action('bizcity_crm_conversation_updated', conv_id)
   → [p10] CRM AI Responder: auto-reply if inbox.settings.ai_auto_reply = true
       → TwinBrain turn → INSERT crm_messages outbound
   → [p20] Automation Trigger Matcher: match trigger_type='crm_message_created'
       → BizCity_Automation_Runner::run_now(wf_id, {conversation_id, contact_id, message})
           → INSERT bizcity_automation_runs (conversation_id, contact_id, crm_event_id)
           → If workflow tạo task: INSERT bizcity_crm_events
             {event_type='crm_conversation_task', contact_id, conversation_id}
```

### Flow B: Admin tạo Campaign Broadcast → gửi Zalo hàng loạt

```
1. Admin tạo bizcity_crm_broadcasts (status=draft)
2. Admin schedule: POST /broadcasts/42/send?scheduled_at=2026-07-01T08:00:00
3. INSERT bizcity_crm_events:
   {event_type='broadcast_scheduled', source='broadcast',
    start_at='2026-07-01 08:00:00', status='active',
    campaign_id=42, metadata={broadcast_id:42, ...}}
4. Scheduler cron tick tại 08:00
   → scan_reminders() claim event 42
   → do_action('bizcity_scheduler_reminder_fire', $event)
       → BizCity_Broadcast_Dispatcher::on_reminder_fire($event)
           → BizCity_Cron_Manager::instance()->register('crm.broadcast_send', ...)
           → SELECT recipients WHERE broadcast_id=42 AND status='queued'
              AND scheduled_send_at <= NOW() LIMIT 50
           → For each: send via channel adapter → UPDATE recipient.status
           → note({sent_batch:50, failed:2})
5. After all recipients done:
   → UPDATE bizcity_crm_broadcasts SET status='sent', sent_at=NOW()
   → UPDATE bizcity_crm_events SET status='done'
   → do_action('bizcity_crm_broadcast_done', 42)
       → [optional] Trigger automation workflow trigger_type='broadcast_done'
```

### Flow C: QR Code scan → mở conversation → kích workflow

```
1. User scan QR → /?biz_qr=khuyen-mai-he-2026
2. BizCity_QR_Handler::handle_scan('khuyen-mai-he-2026')
3. Detect platform từ context (referrer/param) hoặc ask user identify
4. INSERT bizcity_crm_events:
   {event_type='qr_scan_followup', source='qr_code',
    contact_id=resolved, campaign_id=qr.campaign_id, metadata={qr_code_id:7,...}}
5. Nếu qr.inbox_id → CRM_Inbox_Bridge::open_conversation_from_qr(contact_id, inbox_id, attribution)
6. Nếu qr.workflow_id → do_action('bizcity_automation_trigger', 'qr_scan', {qr_id, contact_id})
   → Automation Matcher match trigger_type='qr_scan'
   → Runner → workflow chạy (vd: gửi tin chào + tạo task cho agent)
7. UPDATE qr_codes SET scan_count++, unique_scan_count++ (if unique)
```

### Flow D: Automation Workflow trigger cron → mark Calendar

```
1. Admin tạo workflow trigger_type='cron', trigger_config.schedule='0 8 * * 1'
2. On save: BizCity_Automation_Schedule_Manager::sync_workflow_events($wf)
   → INSERT 30 rows bizcity_crm_events:
     {event_type='automation_workflow', source='workflow',
      reminder_min=0, status='active',
      metadata={workflow_id:15, occurrence:N, run_status:'pending'}}
3. Every Monday 08:00: bizcity_automation_cron_dispatch tick
   → on_cron_scan() check cron_should_fire()
   → Runner::run_now(wf_id, {...})
   → mark_event_done(wf_id, event_id):
     UPDATE crm_events SET status='done', metadata.run_status='done'
4. Calendar UI hiển thị ✅ done / ⏳ active cho từng Monday
```

---

## 7. Schema Migration Plan (thứ tự an toàn)

### Wave 1 — Contact Identity (KHÔNG breaking)

```
1. core/diagnostics/changelog/modules.twin-crm.json: bump v2.0.0
   + Add table bizcity_crm_contact_identities
2. CREATE TABLE bizcity_crm_contact_identities
3. BACKFILL: INSERT INTO identities SELECT id, platform, platform_uid, NULL, name, avatar_url, 1
             FROM contacts WHERE platform != 'unknown'
4. UPDATE BizCity_CRM_Contact_Repository::resolve_by_identity() → dùng identities table
5. Deprecated (NOT dropped): contacts.platform, contacts.platform_uid columns
   → Giữ lại 2 cột này, chỉ drop ở v2.2 sau 1 tháng
6. New UNIQUE index trên identities; DROP UNIQUE idx_platform_uid trên contacts
```

### Wave 2 — crm_events new columns (KHÔNG breaking)

```
1. core/diagnostics/changelog/core.scheduler.json: bump v3.6.0
2. ALTER TABLE bizcity_crm_events:
   ADD COLUMN contact_id      BIGINT UNSIGNED NULL AFTER user_id
   ADD COLUMN conversation_id BIGINT UNSIGNED NULL AFTER contact_id
   ADD COLUMN campaign_id     BIGINT UNSIGNED NULL AFTER conversation_id
3. BACKFILL: UPDATE crm_events SET contact_id = ...
   WHERE source IN ('crm_inbox', 'channel_gateway') AND metadata LIKE '%chat_id%'
   (via BizCity_Scheduler_Inbound_Backfiller pattern đã có)
4. New indexes idx_contact, idx_conversation, idx_campaign
```

### Wave 3 — QR Codes table (NEW)

```
1. core/diagnostics/changelog/core.channel-gateway.json: bump
   + Add table bizcity_crm_qr_codes
2. CREATE TABLE bizcity_crm_qr_codes
3. Register BizCity_Schema_Registry::register('bizcity_crm_qr_codes', ...)
4. REST: POST/GET /wp-json/bizcity-channel/v1/qr-codes
5. Admin UI: QR Codes section trong Channel Gateway SPA
6. BizCity_QR_Handler::init() + rewrite rule /?biz_qr=SLUG
```

### Wave 4 — Automation new trigger types (KHÔNG breaking)

```
1. Add to TRIGGER_TYPES array in BizCity_Automation_Repo_Workflows
2. CREATE class-trigger-crm-conversation.php
3. CREATE class-trigger-crm-contact.php
4. CREATE class-trigger-qr-scan.php
5. CREATE class-trigger-broadcast-done.php
6. Register in automation/bootstrap.php
7. Add hook listeners in CRM_Inbox_Bridge + QR_Handler
```

### Wave 5 — automation_runs new columns (KHÔNG breaking)

```
1. core/diagnostics/changelog/core.automation.json: bump v1.9.0
2. ALTER TABLE bizcity_automation_runs:
   ADD COLUMN contact_id      BIGINT UNSIGNED NULL DEFAULT 0
   ADD COLUMN conversation_id BIGINT UNSIGNED NULL DEFAULT 0
3. Automation Trigger Matcher: extract contact_id/conversation_id từ payload
   → pass to Repo_Runs::enqueue()
4. Index idx_contact, idx_conversation
```

### Wave 6 — Broadcast via crm_events scheduling (integration)

```
1. CREATE class-broadcast-dispatcher.php trong bizcity-twin-crm
2. Register hook bizcity_scheduler_reminder_fire với check event_type='broadcast_scheduled'
3. BizCity_Cron_Manager::register('crm.broadcast_send', ...)
4. Broadcasts admin page: add "Schedule" button → POST to scheduler
5. Broadcasts cron: gửi theo batch + note_event()
```

---

## 8. Table Ownership Map (không được nhầm)

| Table | Owner | Per-blog? | Zone |
|---|---|---|---|
| `bizcity_channel_messages` | core/channel-gateway | ✅ | 1 + 2 |
| `bizcity_crm_contacts` | bizcity-twin-crm | ✅ | 1 |
| `bizcity_crm_contact_identities` | bizcity-twin-crm | ✅ | 1 |
| `bizcity_crm_inboxes` | bizcity-twin-crm | ✅ | 1 |
| `bizcity_crm_conversations` | bizcity-twin-crm | ✅ | 1 |
| `bizcity_crm_messages` | bizcity-twin-crm | ✅ | 1 |
| `bizcity_crm_events` | core/scheduler | ✅ | 1 + 2 |
| `bizcity_crm_broadcasts` | bizcity-twin-crm | ✅ | 1 |
| `bizcity_crm_broadcast_recipients` | bizcity-twin-crm | ✅ | 1 |
| `bizcity_crm_qr_codes` | core/channel-gateway | ✅ | 1 + 2 |
| `bizcity_automation_workflows` | core/automation | ✅ | 2 |
| `bizcity_automation_runs` | core/automation | ✅ | 2 |
| `bizcity_automation_logs` | core/automation | ✅ | 2 |
| `bizcity_cron_registry` | core/cron | ✅ | 2 |
| `bizcity_cron_runs` | core/cron | ✅ | 2 |
| `bizcity_crm_audit_log` | bizcity-twin-crm | ✅ | 1 |
| `bizcity_twin_event_stream` | core/twinbrain | multisite (base_prefix) | 2 |

---

## 9. Hook Contract Matrix

| Hook | Fires | Consumer | Payload |
|---|---|---|---|
| `bizcity_channel_message_logged` | Sau UCL write channel_messages | CRM_Inbox_Bridge | `(msg_id, platform, chat_id, body, direction)` |
| `bizcity_crm_conversation_updated` | Sau create/update conv | Automation Matcher, AI Responder, SLA Engine | `(conv_id, event='created|message|resolved')` |
| `bizcity_crm_contact_upserted` | Sau create/update contact | Automation Matcher, Lead Scoring | `(contact_id, changes[])` |
| `bizcity_crm_broadcast_done` | Sau broadcast hoàn thành | Automation Matcher (trigger broadcast_done) | `(broadcast_id, stats)` |
| `bizcity_qr_scanned` | Sau QR scan | Automation Matcher, Analytics | `(qr_id, contact_id, attribution)` |
| `bizcity_scheduler_reminder_fire` | Cron claim event | All adapters by event_type | `($event array)` |
| `bizcity_scheduler_event_completed` | Sau status→done | Completion Notifier | `(event_id, $event)` |

---

## 10. Anti-Patterns CẤM sau unify

| Anti-pattern | Vì sao sai | Fix |
|---|---|---|
| Lookup contact_id qua `contacts.platform` + `contacts.platform_uid` trực tiếp | Deprecated, multi-identity không hoạt động | Dùng `BizCity_CRM_Contact_Repository::resolve_by_identity()` |
| INSERT `crm_conversations` trực tiếp từ adapter/cron | Bypass CRM Repository → miss audit log + hook | Gọi qua `CRM_Inbox_Bridge::on_message_logged()` |
| Tạo CRM conversation cho Zone 2 message (Zalo Bot admin cmd) | Vi phạm R-ZONE | Bail early: check platform code = zalo_bot|telegram|twinchat_be |
| `bizcity_crm_automation_rules` (twin-crm) trigger logic riêng | Parallel với `bizcity_automation_workflows` → duplicate | CRM rules chỉ là "simple rules", complex → delegate sang automation workflow via `workflow_id` FK |
| Campaign gửi trực tiếp, không qua `bizcity_cron_runs` | Mất observability | Đăng ký `crm.broadcast_send` qua BizCity_Cron_Manager |
| QR tracking chỉ tăng `scan_count` không tạo `crm_events` row | Admin không biết ai scan, không trigger workflow | Mỗi scan = 1 `crm_events` row + optional conversation open |
| `automation_runs` không có `contact_id` | CRM Inbox không biết workflow nào chạy cho khách nào | Wave 5: thêm cột + extract từ trigger payload |

---

## 11. FE Visibility Map — Admin thấy gì, ở đâu?

| Người dùng muốn thấy | Admin page | Bảng nguồn |
|---|---|---|
| Tất cả tin nhắn từ khách (mọi kênh) | CRM Inbox SPA `/inbox` | `crm_conversations` + `crm_messages` |
| Timeline 1 khách (đã liên hệ kênh nào, khi nào) | Contact Detail → Timeline | `crm_contact_identities` + `crm_conversations` + `crm_events` |
| Lịch của mình (meetings, tasks, reminders) | Scheduler/Calendar | `crm_events` filter `user_id=me` |
| Các automation sẽ chạy (cron workflows) | Automation Calendar | `crm_events` filter `event_type=automation_workflow` |
| Campaign đang gửi | Broadcasts page | `crm_broadcasts` + `crm_broadcast_recipients` |
| QR code analytics | Channel Gateway → QR Codes | `crm_qr_codes` + `crm_events` filter `event_type=qr_scan_followup` |
| Cron job health | Cron Admin page | `cron_registry` + `cron_runs` + JSONL logs |
| Workflow runs lịch sử | Automation Runs | `automation_runs` JOIN `crm_events` |
