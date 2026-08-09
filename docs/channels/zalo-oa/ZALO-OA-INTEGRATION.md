# Zalo OA — Tài liệu Phát triển Tích hợp CRM CSKH

> **Ngày soạn:** 2026-06-12  
> **Cập nhật:** 2026-06-13 — Sprint ZA-1 → ZA-5 DONE + 2 bug fixes (reflect 2026-06-13 session)  
> **Phiên bản Zalo API:** OA OpenAPI v3.0 (tin Tư vấn via `/v3.0/oa/message/cs`)  
> **Phiên bản tài liệu:** v1.3  
> **Trạng thái hiện tại:** ZA-1 → ZA-5 DONE ✅ — pipeline hoàn chỉnh từ webhook đến CRM reply

---

## 0. Mục tiêu

Tích hợp Zalo OA vào luồng CRM CSKH của BizCity Twin AI theo đúng **pattern đã hoạt động với Facebook Messenger**, cụ thể:

```
User nhắn → Webhook Zalo OA
   ↓
Normalize payload → canonical envelope
   ↓
Tạo CRM Event (bizcity_crm_events) với inbound metadata
   ↓
Automation / AI xử lý
   ↓
Scheduler Completion Notifier reply lại kênh Zalo OA
   ↓
User nhận tin phản hồi
```

---

## 1. Tổng quan Zalo OA OpenAPI

### 1.1 Các tài khoản cần có

| Tài khoản | Mục đích | Ghi chú |
|---|---|---|
| **Zalo Official Account (OA)** | Tài khoản doanh nghiệp tương tác với users | Tạo tại [oa.zalo.me](https://oa.zalo.me) |
| **Ứng dụng Zalo App** | Cấp quyền cho API, nhận webhook | Tạo tại [developers.zalo.me/createapp](https://developers.zalo.me/createapp) |
| **Zalo Cloud Account (ZCA)** | Quản lý chi phí gửi tin có phí (sau 48h) | Liên kết ZCA → OA → App |

### 1.2 Luồng xác thực (Authorization)

```
1. Admin vào developers.zalo.me → tạo App
2. Liên kết App với OA (cấp Official_Account_Access_Token)
3. Lấy access_token + refresh_token từ trang App settings
4. Điền vào BizCity Channel Gateway settings
   - OA ID: xxxxxxx
   - App ID: xxxxxxx  
   - App Secret: xxxxxxx (để verify webhook signature)
   - Access Token: OA_ACCESS_TOKEN (hết hạn sau 3600s)
   - Refresh Token: OA_REFRESH_TOKEN (hết hạn sau 90 ngày)
```

**Token endpoint** (khi cần refresh):
```
POST https://oauth.zaloapp.com/v4/oa/access_token
Headers:
  secret_key: {app_secret}
Body (x-www-form-urlencoded):
  refresh_token={refresh_token}
  app_id={app_id}
  grant_type=refresh_token
  
Response:
  { "access_token": "...", "expires_in": 3600 }
```

> **⚠️ Lưu ý quan trọng:** Access token hết hạn sau **3600 giây (1 giờ)**.  
> Hệ thống phải tự động làm mới trước khi gửi tin. Refresh token có hiệu lực **90 ngày**  
> nhưng **phải lưu lại refresh token mới** sau mỗi lần refresh (token cũ sẽ bị thu hồi).

---

## 2. Webhook — Nhận sự kiện từ Zalo OA

### 2.1 Cấu hình webhook trong Zalo App

1. Vào App settings → tab Webhook
2. Điền **Webhook URL**: `https://SITE.vn/wp-json/bizcity-channel/v1/webhook/zalo_bot/{OA_ID}`
3. Bật các Event cần nhận: Tin nhắn, Follow/Unfollow, Bài viết...
4. Lưu → Zalo sẽ gửi test request đến URL

### 2.2 Zalo gửi webhook như thế nào

- Method: **POST**
- Signature: query param `?mac={HMAC-SHA256(body, app_secret)}`
- Retry: nếu server trả khác 200, Zalo retry theo timeline:  
  30s → 5m → 15m → 30m → 1h  
  (header `num_retry` tăng dần)
- **Yêu cầu:** Phản hồi HTTP 200 trong vòng **2 giây**

### 2.3 Cấu trúc payload Zalo OA v3

#### Sự kiện tin nhắn văn bản (`user_send_text`)
```json
{
  "app_id": "123456",
  "event_name": "user_send_text",
  "timestamp": "1700000000000",
  "sender": { "id": "ZALO_USER_ID" },
  "recipient": { "id": "OA_ID" },
  "message": {
    "msg_id": "msg_abc123",
    "text": "Cho hỏi giá sản phẩm A?"
  }
}
```

#### Sự kiện gửi ảnh (`user_send_image`)
```json
{
  "event_name": "user_send_image",
  "sender": { "id": "ZALO_USER_ID" },
  "recipient": { "id": "OA_ID" },
  "message": {
    "msg_id": "msg_def456",
    "attachments": [
      {
        "type": "photo",
        "payload": {
          "url": "https://down-static.zalo.me/...",
          "thumbnail": "https://down-static.zalo.me/..."
        }
      }
    ]
  }
}
```

#### Sự kiện gửi file (`user_send_file`)
```json
{
  "event_name": "user_send_file",
  "sender": { "id": "ZALO_USER_ID" },
  "recipient": { "id": "OA_ID" },
  "message": {
    "msg_id": "msg_ghi789",
    "attachments": [
      {
        "type": "file",
        "payload": {
          "url": "https://down-static.zalo.me/...",
          "checksum": "md5hash",
          "size": 1024,
          "name": "document.pdf"
        }
      }
    ]
  }
}
```

#### Sự kiện Quan tâm OA (`follow`)
```json
{
  "event_name": "follow",
  "follower": { "id": "ZALO_USER_ID" },
  "oa_id": "OA_ID",
  "timestamp": "1700000000000"
}
```

#### Sự kiện Hủy Quan tâm (`unfollow`)
```json
{
  "event_name": "unfollow",
  "follower": { "id": "ZALO_USER_ID" },
  "oa_id": "OA_ID",
  "timestamp": "1700000000000"
}
```

### 2.4 Các sự kiện webhook khác (chưa handle trong adapter hiện tại)

| event_name | Mô tả |
|---|---|
| `user_send_audio` | User gửi tin thoại |
| `user_send_video` | User gửi video |
| `user_send_location` | User gửi vị trí |
| `user_send_business_card` | User gửi danh thiếp |
| `oa_send_text` | OA gửi tin (echo) |
| `oa_send_image` | OA gửi ảnh (echo) |
| `anonymous_send_text` | User chưa Quan tâm OA gửi tin |
| `app_new_install` | User cài Mini App |
| `notification_clicked` | User click notification |
| `reaction_message` | User react tin nhắn |

---

## 3. Gửi tin (Outbound) — Zalo OA Send API

### 3.1 Tin Tư vấn (CS Message) — Dùng cho CSKH

**Endpoint:** `POST https://openapi.zalo.me/v3.0/oa/message/cs`

**Headers:**
```
Content-Type: application/json
access_token: {OA_ACCESS_TOKEN}
```

**Điều kiện quan trọng:**
- User phải có tương tác với OA trong vòng **7 ngày** GẦN NHẤT
- Nếu trong vòng **48 giờ**: MIỄN PHÍ
- Nếu sau **48 giờ đến 7 ngày**: CÓ PHÍ (xem bảng giá tại zalo.cloud/oa/pricing)

#### Gửi tin văn bản
```json
{
  "recipient": { "user_id": "ZALO_USER_ID" },
  "message": {
    "text": "Cảm ơn bạn đã liên hệ! Chúng tôi sẽ phản hồi ngay."
  }
}
```

#### Gửi ảnh
```json
{
  "recipient": { "user_id": "ZALO_USER_ID" },
  "message": {
    "attachment": {
      "type": "template",
      "payload": {
        "template_type": "media",
        "elements": [
          {
            "media_type": "image",
            "url": "https://example.com/image.jpg"
          }
        ]
      }
    }
  }
}
```

#### Gửi tin kèm nút bấm (Button Template)
```json
{
  "recipient": { "user_id": "ZALO_USER_ID" },
  "message": {
    "attachment": {
      "type": "template",
      "payload": {
        "template_type": "generic",
        "elements": [
          {
            "title": "Bạn cần hỗ trợ vấn đề gì?",
            "image_url": "https://example.com/img.jpg",
            "subtitle": "Chọn danh mục để được hỗ trợ nhanh hơn",
            "buttons": [
              { "title": "Đặt hàng", "type": "oa.query.show", "payload": "dat_hang" },
              { "title": "Tra cứu đơn", "type": "oa.query.show", "payload": "tra_don" },
              { "title": "Khiếu nại", "type": "oa.query.show", "payload": "khieu_nai" }
            ]
          }
        ]
      }
    }
  }
}
```

#### Response khi thành công
```json
{
  "error": 0,
  "message": "Success",
  "data": {
    "message_id": "G2972289882340527111"
  }
}
```

#### Response khi lỗi
```json
{
  "error": -201,
  "message": "The user has not followed this OA"
}
```

**Bảng error code Zalo OA hay gặp:**

| error | Ý nghĩa | Xử lý |
|---|---|---|
| 0 | Thành công | — |
| -201 | User chưa Quan tâm OA | Không thể gửi tin; hiển thị thông báo cho admin |
| -216 | Token không hợp lệ | Refresh token |
| -218 | OA không có quyền gửi | Kiểm tra quota/gói OA |
| -101 | Tham số không hợp lệ | Kiểm tra lại payload |

### 3.2 Tin Giao dịch / Hậu mãi (ZNS) — Cho thông báo proactive

Khác với Tin Tư vấn, ZNS (Zalo Notification Service) dùng để gửi thông báo proactive theo mẫu:
- Phải đăng ký template được duyệt
- Gửi qua UID hoặc số điện thoại
- Có phí theo từng tin (ZCA billing)
- **Endpoint khác:** `POST https://business.googleapis.com/...` (qua ZCA)

> **Scope phát triển hiện tại:** Tập trung vào **Tin Tư vấn** cho CSKH inbound.  
> ZNS là phase sau khi cần gửi broadcast/reminder chủ động.

---

## 4. Lấy thông tin User Profile

```
GET https://openapi.zalo.me/v3.0/oa/user/detail
Headers:
  access_token: {OA_ACCESS_TOKEN}
Params:
  data: {"user_id": "ZALO_USER_ID"}

Response:
{
  "error": 0,
  "message": "Success",
  "data": {
    "user_id": "ZALO_USER_ID",
    "display_name": "Nguyễn Văn A",
    "birth_date": "01/01/1990",
    "avatar": "https://...",
    "shared_info": {
      "phone": "0912345678",
      "city": "Hà Nội"
    }
  }
}
```

> **Lưu ý bảo mật:** `shared_info.phone` chỉ có nếu user đã chấp nhận chia sẻ SĐT với OA.

---

## 5. Trạng thái tích hợp hiện tại (Current State)

### ✅ Đã có (trong `class-zalo-bot-oa-integration.php`)

| Tính năng | Status | File |
|---|---|---|
| Webhook verification (GET challenge + POST mac) | ✅ Hoạt động | `adapters/class-zalo-bot-oa-integration.php` |
| Normalize inbound payload (text, image, file, sticker, follow, unfollow) | ✅ Hoạt động | idem |
| Gửi tin văn bản qua `/v3.0/oa/message/cs` | ✅ Hoạt động | idem |
| Gửi ảnh qua template media | ✅ Hoạt động | idem |
| Token auto-refresh (khi có refresh_token + app_secret + app_id) | ✅ Hoạt động | idem |
| **Token persistence sau refresh** — save lại vào WP options | ✅ **DONE ZA-1.1** | idem |
| Test connection (`do_test()` → `/v2.0/oa/getoa`) | ✅ Hoạt động | idem |
| REST endpoint `bizcity-channel/v1/webhook/zalo_bot/{oa_id}` | ✅ Hoạt động | `class-channel-rest-api.php` |
| Legacy webhook `/zalohook/` | ✅ Hoạt động (deprecated) | `bizcity-zalo-bot/includes/class-webhook-handler.php` |
| **handle_webhook bifurcated** — new-style `BizCity_Channel_Integration` path (WP_REST_Request) | ✅ **DONE ZA-1.2** | `class-channel-rest-api.php` |
| **Unified pipeline** — `wu_zalobot_message_received` key trong UCL `$map` | ✅ **DONE ZA-1.2** | `class-universal-channel-listener.php` |
| **bizcity_channel_messages** log qua UCL (character_id + Listener Bus) | ✅ **DONE ZA-1.2** | idem |
| **bizcity_crm_events** creation với inbound{} metadata — CRM ingestor priority 9 | ✅ **DONE ZA-1.2** | idem |
| **Error normalization** — map Zalo error codes vào `BizCity_Error_Payload` | ✅ **DONE ZA-1.3** | `class-zalo-bot-oa-integration.php` |
| **Diagnostic probe** — 15 lớp kiểm tra (5 row groups) | ✅ **DONE ZA-5** | `class-probe-zalo-oa-crm.php` |

### ❌ Còn thiếu (cần phát triển)

| Tính năng | Priority | Ảnh hưởng |
|---|---|---|
| **Scheduler reply loop verify** — kiểm tra `BizCity_Scheduler_Completion_Notifier` handle `ZALO_BOT` platform | ✅ DONE ZA-2 | OA registry lookup fixed in `send_legacy()` |
| **Admin CRM UI** — conversation view + admin-send REST cho Zalo OA | ✅ DONE ZA-3 | `class-zalo-oa-rest.php` |
| **Follow/Unfollow CRM contact sync** — fetch profile sau follow, update status sau unfollow | ✅ DONE ZA-4 | `class-zalo-oa-contact-sync.php` |
| **Message types mở rộng** — audio, video, location chưa normalize | 🟡 MEDIUM | Không xử lý được các loại tin này |
| **Tin Broadcast / ZNS** — chưa có publisher | 🟢 LOW (phase sau) | Cần ZCA billing setup |

### 🔍 Bug Fixes (session 2026-06-13)

| Bug | File | Fix |
|---|---|---|
| **UCL hardcodes `event_type='message'`** cho ALL events qua `wu_zalobot_message_received` — follow/unfollow tạo CRM events rác | `class-universal-channel-listener.php::on_normalized_crm_ingest()` | Đọc `envelope['raw']['type']` (Zalo original type); skip nếu `'follow'/'unfollow'`; dùng raw type cho `in_array` classification (thêm `'text'` vào array) |
| **Contact sync never fires** — `$envelope['event_type']='message'` luôn ≠ `'follow'` | `class-zalo-oa-contact-sync.php::on_normalized()` | Đọc `$_raw['type']` từ `envelope['raw']` thay vì `envelope['event_type']` |

---

## 6. Kế hoạch Phát triển (Development Plan)

### ✅ Sprint ZA-1: CRM Inbound Foundation — DONE (2026-06-13)

**ZA-1.1 ✅** — Token Persistence: `maybe_refresh_token()` now calls `BizCity_Integration_Registry::update_channel_account_status()` to persist new token to WP options.

**ZA-1.2 ✅** — Unified Pipeline + CRM event creation:
- `handle_webhook()` bifurcated: new `BizCity_Channel_Integration` path passes `WP_REST_Request` to `verify_webhook()` (fixes TypeError on PHP 7.4)
- UCL `$map` now has `wu_zalobot_message_received` key (fields: `account_field=instance_id`, `user_field=sender_id`, `message_field=text`, `msgid_field=mid`)
- `handle_webhook()` fires `do_action('waic_twf_process_flow', 'wu_zalobot_message_received', $payload)` → UCL handles entire pipeline
- UCL new `on_normalized_crm_ingest()` at priority 9 on `bizcity_channel_normalized`: creates `bizcity_crm_events` with `event_type=zalo_inbound` + `inbound{}` metadata (R-SCH-REPLY)
- `zalo_inbound` added to `$allowed_event_types` in `BizCity_Scheduler_Manager::sanitize_row()`

**ZA-1.3 ✅** — Error Payload: `send_outbound()` maps Zalo error codes (-201/-216/-218) to `BizCity_Error_Payload` fields (code, message, hint, help_code).

**Straight line verified:**
```
Webhook POST
  → handle_webhook() verify + normalize
  → do_action('bizcity_channel_message_received')   // Automation Listener capture
  → do_action('waic_twf_process_flow', 'wu_zalobot_message_received', payload)
        → UCL::on_trigger() [priority 5]
            → bizcity_channel_messages::log_inbound()  ← SINGLE WRITE
            → BizCity_Channel_Binding::resolve() → character_id
            → do_action('bizcity_channel_normalized') [priority 6]
                → Automation_Listener::on_channel_normalized() [priority 1]
                → UCL::on_normalized_crm_ingest() [priority 9] → bizcity_crm_events
                → Listener_Bus::on_inbound() [priority 20]
        → Chat Gateway / Intent Engine [priority 10+]
```

---

### ✅ Sprint ZA-2: Scheduler Reply Loop — DONE (2026-06-13)

**Mục tiêu:** Khi automation/AI hoàn thành → tự động reply về Zalo OA

**Kết quả verify:**

| Component | Status | Ghi chú |
|---|---|---|
| `BizCity_Scheduler_Completion_Notifier::on_completed()` | ✅ | Đọc `metadata.inbound.{platform,chat_id}`, gọi `BizCity_Gateway_Sender::send()` |
| `BizCity_Gateway_Sender::send()` → detect `ZALO_BOT` | ✅ | `detect_platform_legacy('zalobot_...')` → `'ZALO_BOT'` |
| `send_legacy()` `zalo_bot` case — OA registry lookup | ✅ **FIXED ZA-2** | Thêm block thử `BizCity_Integration_Registry` + `BizCity_Channel_Integration` TRƯỚC `BizCity_Zalo_Bot_Database` (old plugin). Nếu tìm thấy account theo `oa_id`, gọi trực tiếp `send_outbound()`. |
| `BizCity_Zalo_Bot_OA_Integration::send_outbound()` | ✅ | Nhận `['recipient'=>$user_id, 'text'=>$msg]`, gọi `/v3.0/oa/message/cs` |
| `zalo_inbound` CRM event `notify: false` | ✅ **FIXED ZA-2** | Prevent ghost "Đã xử lý xong" message khi tracking event bị đóng. Automation events carry own `inbound{}` và notify riêng. |

**Lý do bug cũ:** `send_legacy()` dùng `BizCity_Zalo_Bot_Database::get_bot($oa_id)` (bảng của bizcity-zalo-bot plugin cũ), không liên quan đến OA integration được lưu trong `BizCity_Integration_Registry`.

**Straight line reply (verified):**
```
Automation/AI finishes → marks event 'done'
  → fires 'bizcity_scheduler_event_completed'
  → BizCity_Scheduler_Completion_Notifier::on_completed()
      → resolve_target() reads metadata.inbound.{platform, chat_id}
      → BizCity_Gateway_Sender::send('zalobot_{oa_id}_{user_id}', msg)
          → detect_platform() → 'ZALO_BOT'
          → try BizCity_Integration_Registry (OA) first  ← ZA-2 fix
          → BizCity_Zalo_Bot_OA_Integration::send_outbound()
          → POST /v3.0/oa/message/cs → User nhận tin trả lời
```

---

**Kiểm tra flow:**
1. Scheduler event `event_type=zalo_inbound` có `metadata.inbound` đúng format
2. `BizCity_Scheduler_Completion_Notifier::on_completed()` đọc `metadata.inbound.platform = 'ZALO_BOT'`
3. Gọi `BizCity_Gateway_Sender::send_envelope()` với platform `ZALO_BOT`
4. `send_envelope()` resolve account từ `instance_id = metadata.inbound.account_id`
5. Gọi `send_outbound()` của `BizCity_Zalo_Bot_OA_Integration`

**Kiểm tra `BizCity_Gateway_Sender` có handle `ZALO_BOT` không:**
```bash
grep -r "ZALO_BOT" core/channel-gateway/includes/class-gateway-sender.php
```

**Files cần kiểm tra:**
- `core/scheduler/includes/class-scheduler-completion-notifier.php`
- `core/channel-gateway/includes/class-gateway-sender.php`
- `core/channel-gateway/includes/class-gateway-bridge.php`

---

### ✅ Sprint ZA-3: Admin CRM UI — DONE (2026-06-13)

**File:** `core/channel-gateway/includes/adapters/class-zalo-oa-rest.php`

| Route | Method | Mô tả |
|---|---|---|
| `/bizcity-channel/v1/zalo/recent-users` | GET | Grouped recent Zalo users for one OA (oa_id param) |
| `/bizcity-channel/v1/zalo/conversation` | GET | Timeline for (oa_id, user_id) — sorted ASC, ready for bubble render |
| `/bizcity-channel/v1/zalo/admin-send` | POST | Admin chat-back + log to `bizcity_channel_messages` + listener emit |

- Tất cả require `manage_options`.
- `admin-send` dùng `BizCity_Gateway_Sender::send()` — cùng path với automation replies (ZA-2).
- `recent-users` trả `display_name` từ `bizcity_crm_contacts` (nếu CRM có) hoặc `Zalo {user_id}` fallback.
- `conversation` trả array `{id, role, event_name, text, display_name, message_id, created_at, status, error}` — compatible với ConversationPanel FE pattern.

---

### ✅ Sprint ZA-4: Follow / Unfollow CRM Contact — DONE (2026-06-13)

**File:** `core/channel-gateway/includes/adapters/class-zalo-oa-contact-sync.php`  
**API extension:** `BizCity_Zalo_Bot_OA_Integration::api_get_user_profile()` added.

**Follow event flow:**
1. `normalize_inbound()` → `type='follow'` → UCL passes through `bizcity_channel_normalized`
2. `BizCity_Zalo_OA_Contact_Sync::on_normalized()` [priority 7]
3. `api_get_user_profile($user_id, $account)` → `GET /v3.0/oa/user/detail`
4. Upsert into `bizcity_crm_contacts`:
   - Dedupe by `additional_attributes->zalo_user_id` first, then by phone
   - Insert or update with `name`, `phone`, `acquisition_source='zalo_oa'`
   - `additional_attributes` JSON: `{zalo_follow_status, zalo_oa_id, zalo_user_id, zalo_avatar, zalo_followed_at}`
5. Fires `bizcity_zalo_oa_contact_synced($contact_id, $user_id, $meta)` hook

**Unfollow event flow:**
1. Find contact by `zalo_user_id` in `additional_attributes`
2. Merge `zalo_follow_status='unfollowed'` + `zalo_unfollowed_at` (record kept for history)
3. No delete — contact stays in CRM

Fails silently if `bizcity-twin-crm` not installed (no table → skip).

---

### ✅ Sprint ZA-5: Diagnostic Probe — DONE (2026-06-13)

Probe `cg.zalo-oa-crm` (order=47) đã có trong `core/diagnostics/includes/probes/class-probe-zalo-oa-crm.php`.
15 lớp kiểm tra (5 row groups): adapter disk+loader+runtime, webhook, token, tables, scheduler bridge.

---

## 7. Luồng dữ liệu đầy đủ (Target State)

```
┌──────────────────────────────────────────────────────────────────────┐
│ USER ZALO → OA "Tôi muốn đặt hàng"                                  │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ WEBHOOK ZALO POST                                                    │
│  → POST /wp-json/bizcity-channel/v1/webhook/zalo_bot/{oa_id}        │
│  Headers: (no X-Hub, Zalo dùng ?mac= query param)                   │
│  Body: { event_name: "user_send_text", sender: {id: "uid"}, ... }   │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ BizCity_Channel_REST_API::handle_webhook(POST)                       │
│  1. Verify mac signature (HMAC-SHA256 nếu có app_secret)             │
│  2. Route đến BizCity_Zalo_Bot_OA_Integration::normalize_inbound()  │
│  3. Trả HTTP 200 NGAY (async nếu cần, Zalo timeout 2s)              │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ Canonical Envelope                                                    │
│  {                                                                   │
│    platform: 'ZALO_BOT',                                             │
│    instance_id: 'OA_ID',                                             │
│    chat_id: 'zalobot_OA_ID_USER_ID',                                 │
│    sender_id: 'USER_ID',                                             │
│    text: 'Tôi muốn đặt hàng',                                       │
│    type: 'text',                                                     │
│    mid: 'msg_abc123',                                                │
│    timestamp: 1700000000                                             │
│  }                                                                   │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ BizCity_Universal_Channel_Listener::on_trigger()                     │
│  1. Resolve character_id (Guru binding)                              │
│  2. Insert bizcity_channel_messages (inbound ledger)                 │
│  3. Log vào webhook audit log                                        │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ CRM Event Creation [ZA-1 — PHẦN CẦN THÊM]                           │
│  BizCity_Scheduler_Manager::create_event( {                         │
│    event_type: 'zalo_inbound',                                       │
│    status: 'received',                                               │
│    metadata: {                                                       │
│      inbound: {                                                      │
│        platform: 'ZALO_BOT',                                         │
│        chat_id: 'zalobot_OA_ID_USER_ID',                            │
│        user_id: 'USER_ID',                                           │
│        account_id: 'OA_ID',                                          │
│        message_id: 'msg_abc123',                                     │
│        raw_text: 'Tôi muốn đặt hàng'                                │
│      }                                                               │
│    }                                                                 │
│  } )                                                                 │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ Automation / AI Processing                                            │
│  - trigger.zalo_message → workflow automation                        │
│  - hoặc Twin AI xử lý intent                                         │
│  - Kết quả: "Bạn muốn đặt hàng sản phẩm X? Giá là Y..."            │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ BizCity_Scheduler_Manager::update_event(                             │
│    $event_id,                                                        │
│    ['status' => 'done', 'metadata' => merged_meta]                  │
│  )                                                                   │
│  → Hook: bizcity_scheduler_event_completed                           │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ BizCity_Scheduler_Completion_Notifier::on_completed()                │
│  1. Đọc metadata.inbound.platform = 'ZALO_BOT'                      │
│  2. Đọc metadata.inbound.chat_id                                     │
│  3. Extract user_id từ chat_id (strip 'zalobot_OA_ID_' prefix)      │
│  4. Gọi BizCity_Gateway_Sender::send_envelope( {                    │
│       platform: 'ZALO_BOT',                                          │
│       instance_id: 'OA_ID',                                          │
│       recipient: 'USER_ID',                                          │
│       message: 'Bạn muốn đặt hàng sản phẩm X? Giá là Y...',        │
│       type: 'text'                                                   │
│     } )                                                              │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ BizCity_Zalo_Bot_OA_Integration::send_outbound()                     │
│  1. maybe_refresh_token() — refresh nếu gần hết hạn                 │
│  2. POST https://openapi.zalo.me/v3.0/oa/message/cs                 │
│     Headers: access_token: {TOKEN}                                   │
│     Body: { recipient: {user_id: 'USER_ID'}, message: {text: ...} } │
│  3. Update event.metadata.delivery = {sent: true, mid: ...}         │
└──────────────────────────────┬───────────────────────────────────────┘
                                ↓
┌──────────────────────────────────────────────────────────────────────┐
│ USER ZALO nhận tin: "Bạn muốn đặt hàng sản phẩm X? Giá là Y..." ✅  │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 8. So sánh với Facebook Messenger Pattern

| Khía cạnh | Facebook Messenger | Zalo OA | Ghi chú |
|---|---|---|---|
| Webhook auth | X-Hub-Signature-256 header | `?mac=` query param (HMAC-SHA256) | Khác nhau về vị trí signature |
| Token loại | Page Access Token (không hết hạn nếu long-lived) | Access Token 3600s + Refresh 90 ngày | Zalo phức tạp hơn, phải persist refresh |
| User ID | PSID (Page Scoped ID) — ổn định | User Follow ID — có thể đổi khi unfollow/refollow | ⚠️ Zalo UID không stable như PSID |
| Send window | 24h rule (có ngoại lệ) | 7 ngày kể từ tương tác cuối | Zalo rộng hơn (7 ngày) |
| Chi phí | Theo Meta policy | 48h đầu miễn phí, sau tính phí | Cần theo dõi last_interaction_at |
| Attachment | Gửi qua URL trực tiếp | Gửi qua URL hoặc upload trước | Zalo recommend upload trước để cache |
| CRM event type | `fb_message` | `zalo_inbound` | Cần thêm trigger block mới |
| Trigger block | `wu_fb_message_received` | `wu_zalobot_message_received` | Class đã khai báo, chưa implement |
| Action block | `wa_send_fb_message` | `wa_send_zalo_bot_message` | Class đã khai báo, chưa implement |

---

## 9. Chú ý quan trọng khi implement

### 9.1 Zalo User ID không ổn định

Khi user Hủy Quan tâm OA rồi Quan tâm lại, **User ID có thể thay đổi**. Cần:
- Lưu thêm `phone` (nếu user cho phép) làm khóa phụ
- Link Zalo UID với WP user thông qua `bizcity_zalo_user_links` table (đã có)

### 9.2 Gửi tin sau 48h có phí — cần track `last_interaction_at`

```php
// Khi tạo/update CRM contact sau mỗi webhook event:
update_user_meta( $wp_user_id, '_zalobot_last_interaction_at_' . $oa_id, time() );

// Khi gửi tin:
$last = get_user_meta( $wp_user_id, '_zalobot_last_interaction_at_' . $oa_id, true );
$free_window = ( time() - (int)$last ) < 48 * HOUR_IN_SECONDS;
if ( ! $free_window ) {
    // Cảnh báo admin: "Tin này có thể bị tính phí (sau 48h tương tác)"
}
```

### 9.3 Webhook phải trả 200 trong 2 giây

Nếu xử lý nặng (AI, DB queries), phải:
1. Trả 200 ngay
2. Đẩy task vào cron/async queue để xử lý sau

Pattern đã có trong `BizCity_Channel_REST_API` — verify lại có hoạt động không với Zalo.

### 9.4 Không dùng `CURLFile` trên shared hosting

`class-zalo-bot-api.php` dùng `CURLFile` để upload — nhiều shared hosting không cho phép.  
Thay bằng `wp_remote_post()` với `body` là stream.

### 9.5 PHP 7.4 Compat trong adapter

File `class-zalo-bot-oa-integration.php` hiện dùng typed properties (`protected string $code = 'zalo_bot'`) — PHP 7.4 native, OK.  
Nhưng nếu thêm code mới **tránh** union types, nullsafe operator, `str_contains()`.

---

## 10. Quick Reference — API Endpoints

| Action | Method | URL | Auth |
|---|---|---|---|
| Get OA info | GET | `https://openapi.zalo.me/v2.0/oa/getoa` | `access_token` header |
| Get user profile | GET | `https://openapi.zalo.me/v3.0/oa/user/detail?data={"user_id":"..."}` | `access_token` header |
| Send CS message | POST | `https://openapi.zalo.me/v3.0/oa/message/cs` | `access_token` header |
| Upload image | POST | `https://openapi.zalo.me/v2.0/oa/upload/image` | `access_token` header |
| Upload file | POST | `https://openapi.zalo.me/v2.0/oa/upload/file` | `access_token` header |
| Refresh token | POST | `https://oauth.zaloapp.com/v4/oa/access_token` | `secret_key` header = app_secret |
| List followers | GET | `https://openapi.zalo.me/v2.0/oa/getfollowers?data={"offset":0,"count":50}` | `access_token` header |

---

## 11. Checklist trước khi demo CSKH Zalo OA

- [ ] Tài khoản Zalo App đã được tạo và liên kết với OA
- [ ] Webhook URL đã đăng ký trong Zalo App settings
- [ ] `app_secret` đã điền trong Channel Gateway (để verify webhook signature)
- [ ] `access_token` và `refresh_token` hợp lệ, test thành công (green badge)
- [ ] `token_expires_at` được lưu đúng định dạng ISO 8601
- [ ] Webhook nhận được event `user_send_text` (kiểm tra qua `/wp-json/bizcity-channel/v1/logs`)
- [ ] CRM event được tạo khi user nhắn tin (kiểm tra `bizcity_crm_events` table)
- [ ] Scheduler Notifier reply được về Zalo (end-to-end test với user thật)
- [ ] Error message hiển thị đúng khi token hết hạn (không phải raw Zalo error code)
- [ ] Diagnostic probe trả PASS (hoặc SKIP nếu chưa cấu hình tài khoản)

---

## 12. References

- [Zalo OA OpenAPI docs](https://developers.zalo.me/docs/official-account)
- [Webhook tổng quan](https://developers.zalo.me/docs/official-account/webhook/tong-quan)
- [Tin nhắn tổng quan](https://developers.zalo.me/docs/official-account/tin-nhan/tong-quan)
- Facebook pattern (mẫu): `core/channel-gateway/includes/adapters/class-facebook-page-integration.php`
- Scheduler Nerve Center spec: `core/scheduler/docs/PHASE-SCHEDULER-AS-NERVE-CENTER.md`
- R-SCH-REPLY rule: `.github/copilot-instructions.md §R-SCH-REPLY`
- Error UX spec: `core/helper/docs/ERROR-UX-SPEC.md`
- Channel Gateway overview: `docs/channels/overview.md`
