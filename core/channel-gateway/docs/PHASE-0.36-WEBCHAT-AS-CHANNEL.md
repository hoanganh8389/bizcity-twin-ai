# PHASE 0.36 — WebChat as Channel (Channel Gateway + CRM Inbox + Guru Binding)

> **Status**: 🚧 W1 shipped 2026-05-25 · **Owner**: Twin AI · Channel Gateway
> **Anchor commit**: PHASE 0.36 W1 (canonical inbound trigger + auto-binding + outbound stamp)
> **Parent docs**:
> - [PHASE-0.3-CRM-CANON.md](PHASE-0.3-CRM-CANON.md) — Stage A/D
> - [PHASE-0.32-CRM-INBOX-HUB.md](PHASE-0.32-CRM-INBOX-HUB.md)
> - [PHASE-0.34-CRM-INBOX-CHATWOOT-FE.md](PHASE-0.34-CRM-INBOX-CHATWOOT-FE.md) §0.1–0.2 (binding modes + responder stamp)

---

## 0. Scope (TỐI THƯỢNG — đọc trước)

**WebChat = FRONTEND visitor float widget**. Phase này wire nó thành 1 channel ngang
hàng FB Page / Zalo OA / Telegram trong Channel Gateway.

| | WebChat (scope phase này) | Twin Admin (KHÔNG đụng) |
|---|---|---|
| Surface | Float widget ngoài frontend site | wp-admin `/twin/`, twinchat |
| User | Visitor (anonymous/login) | Admin / staff đã login |
| Pipeline | Trigger → Listener → CRM Inbox → Guru auto-reply hoặc CSKH manual | Intent Engine → TwinShell → tools |
| Schema | `bizcity_webchat_*` + `_bizcity_channel_messages` + `_bizcity_channel_bindings` | `bizcity_twinchat_*`, `bizcity_intent_*` |

**KHÔNG vi phạm**: webchat module KHÔNG được gọi `BizCity_Intent_Engine`,
`BizCity_TwinShell_*`, hay TwinChat REST endpoints. Mọi luồng intent admin là
surface riêng — xem [/memories/repo/webchat-scope.md].

---

## 1. RULE bắt buộc (R-WC-1..4)

### R-WC-1 — Inbound canonical trigger key
Mọi tin nhắn webchat MUỐN xuất hiện trong CRM Inbox PHẢI fire
`do_action('waic_twf_process_flow', 'wu_webchat_message_received', $payload)`
với payload theo spec của [class-universal-channel-listener.php](../includes/class-universal-channel-listener.php) §`self::$map['wu_webchat_message_received']`:

```php
[
    'site_id'    => (string) get_current_blog_id(),
    'session_id' => $webchat_session_id,
    'message'    => $text,
    'message_id' => $mid,
]
```

Bot-to-bot loop guard: KHÔNG fire trigger này từ trong listener callbacks của
chính `bizcity_channel_normalized` (sẽ recursion).

### R-WC-2 — Binding tự sinh khi vắng
`BizCity_WebChat_Binding_Bootstrap::ensure()` PHẢI được gọi TRƯỚC mỗi lần emit
canonical trigger. Ensure idempotent qua UNIQUE `(blog_id, WEBCHAT, blog_id)`.

Convention:
- `account_id = (string) blog_id` — webchat là local, 1 binding / 1 site.
- `character_id = option('bizcity_webchat_default_character_id', 0)` — admin
  có thể đổi sau qua UI Channel Binding (sẽ ship M2).
- `mode = 'auto'` mặc định.

### R-WC-3 — Outbound stamp về `_bizcity_channel_messages`
Mọi bot reply sau khi log vào `bizcity_webchat_messages` PHẢI gọi
`BizCity_Responder_Stamper::record_outbound()` với:

```php
[
    'platform'  => 'WEBCHAT',
    'chat_id'   => 'webchat_' . $session_id,
    'user_psid' => $session_id,
    'body'      => $reply,
    'status'    => 'sent',
]
```

Stamper tự pull `character_id` + `responder_kind` từ context push bởi
`Universal_Channel_Listener::on_trigger()` ở cùng request. KHÔNG cần truyền tay.

### R-WC-4 — KHÔNG đụng admin Intent Engine
Webchat module có namespace class riêng (`BizCity_WebChat_*`) và CHỈ được phép
import từ:
- `core/channel-gateway/` (Channel_Binding, Channel_Messages, Responder_Stamper, Universal_Channel_Listener)
- WP core (wpdb, options, transients)

CẤM import: `BizCity_Intent_Engine`, `BizCity_TwinShell_*`, `BizCity_TwinChat_*`,
`BizCity_TwinBrain_*`.

---

## 2. W1 — DONE (2026-05-25) — Inbound + Outbound bridge

### Files đã sửa
| File | Diff |
|---|---|
| [modules/webchat/includes/class-webchat-trigger.php](../../../modules/webchat/includes/class-webchat-trigger.php) | `fire_workflow_trigger()` thêm emit canonical key + call `Binding_Bootstrap::ensure()`; `process_message()` thêm `Responder_Stamper::record_outbound()` sau mỗi bot reply |
| [core/channel-gateway/includes/class-webchat-binding-bootstrap.php](../includes/class-webchat-binding-bootstrap.php) | NEW — `ensure()` static helper |
| [core/channel-gateway/bootstrap.php](../bootstrap.php) | Require new bootstrap file |

### Smoke validate
1. Mở frontend public site (logged out), click float widget, gõ "test".
2. SQL check: `SELECT * FROM wp_bizcity_channel_bindings WHERE platform='WEBCHAT'` → có 1 row mới.
3. SQL check: `SELECT direction, platform, body, character_id, responder_kind FROM wp_bizcity_channel_messages WHERE platform='WEBCHAT' ORDER BY id DESC LIMIT 4`
   - row direction=1 (inbound), body="test", character_id=NULL (chưa set guru)
   - row direction=2 (outbound), body=<reply>, responder_kind='auto' nếu binding có character_id

---

## 3. W2 — TODO — Channel Binding admin UI cho WEBCHAT

Hiện tại tạo binding auto với `character_id=0`. Cần FE Inbox / Bindings tab có
1 card "Web Chat (site này)" cho phép:
- Pick Guru từ dropdown character list
- Toggle mode: auto / manual / hybrid / roundrobin
- Khi manual: pick `fallback_assignee` (WP user CSKH)

Anchor: extend `core/channel-gateway/frontend/src/routes/bindings/` (mirror
FacebookPageBindingPanel pattern).

---

## 4. W3 — TODO — Outbound từ CRM Inbox về widget

Khi CSKH manual reply trong CRM Inbox (mode=manual/hybrid), backend đã có
`POST /bizcity/cg/v1/inbox/send` (PHASE 0.34 M4.2). Cần wire route đó về
`BizCity_WebChat_Adapter::send_outbound()` → push qua webchat polling endpoint
`/wp-json/bizcity-webchat/v1/pull` để widget visitor thấy reply.

Hiện adapter đã có stub gọi `BizCity_WebChat_Trigger::push_message()` /
`BizCity_WebChat_Database::insert_bot_message()` — kiểm tra method tồn tại
và wire vào polling.

---

## 5. W4 — TODO — CRM Inbox conversation thread cho webchat

Frontend `PlatformInbox.jsx` filter platform='WEBCHAT' → render bubble UI giống
FB. Icon platform = 💬. Header conversation hiện Guru pill + mode badge.

---

## 6. Anti-patterns

❌ Webchat trigger fire `do_action('waic_twf_process_flow', $array, ...)` mà KHÔNG
   kèm emit canonical string key → Listener bail, không mirror message.
❌ Tạo binding row tay trong code module thay vì dùng `Binding_Bootstrap::ensure()`.
❌ Stamp outbound khi binding chưa tồn tại → responder_kind='auto' nhưng character_id=NULL.
❌ Webchat trigger gọi `BizCity_Intent_Engine` hay TwinChat surface — vi phạm R-WC-4.
