# Facebook Messenger × Twin Guru — AI Autoreply Integration

> **Phase:** PHASE-CRM-FB-GURU  
> **Status:** Production (2026-06-29)  
> **Canonical files:**
> - `plugins/bizcity-twin-crm/includes/class-ai-autoreply-listener.php`
> - `plugins/bizcity-twin-crm/includes/class-guru-resolver.php`
> - `plugins/bizcity-twin-crm/includes/class-ai-replier.php`
> - `core/channel-gateway/frontend/src/routes/platform/facebook/FacebookPages.jsx`

---

## 1. Kiến trúc luồng autoreply

```
Messenger user nhắn tin
    ↓ (Facebook Graph API webhook)
bizcity-facebook-bot → UCL → bizcity_facebook_message_received
    ↓ (action hook)
BizCity_CRM_Inbox_Handler → lưu vào bizcity_crm_messages (platform='FB_MESS')
    ↓ (hook bizcity_crm_message_received)
BizCity_CRM_AI_Autoreply_Listener::on_message()
    ├── resolve inbox (channel_type='facebook', channel_ref_id=page_id)
    ├── resolve Guru (BizCity_CRM_Guru_Resolver::resolve_for_inbox)
    │       └── platform alias: 'FACEBOOK' ⟷ 'FB_MESS' (binding key)
    ├── skip nếu KHÔNG có cả character VÀ notebook
    ├── tiếp tục nếu character > 0 (có system_prompt/FAQ) dù notebook_id=0
    ↓
BizCity_AI_Replier::reply()
    ├── KG retrieval: SKIP khi notebook_id=0 (char-only mode)
    ├── KG retrieval: chạy bình thường khi notebook_id > 0
    └── LLM generate → gửi qua BizCity_Chat_Gateway
```

---

## 2. Platform String Mapping (quan trọng)

| Layer | Giá trị platform | Ghi chú |
|---|---|---|
| CRM Inbox `channel_type` | `'facebook'` | Lowercase, dùng cho JOIN queries |
| Guru Resolver query | `'FACEBOOK'` | `strtoupper(channel_type)` |
| Channel Gateway binding (FE save) | `'FB_MESS'` | `const PLATFORM = 'FB_MESS'` trong `FacebookPages.jsx` |
| UCL inbound message | `'FB_MESS'` | Từ `bizcity_facebook_message_received` |

**Vì CRM Resolver query với `'FACEBOOK'` nhưng binding lưu là `'FB_MESS'`, Guru Resolver
phải tự alias hai giá trị này trong cùng một `IN (...)` clause.**

```php
// class-guru-resolver.php — alias mapping (hiện đã áp dụng 2026-06-29)
if ( $platform === 'FACEBOOK' ) { $platform_aliases[] = 'FB_MESS'; }
if ( $platform === 'FB_MESS'  ) { $platform_aliases[] = 'FACEBOOK'; }
```

---

## 3. Notebook là tuỳ chọn — Character là bắt buộc

### Trước 2026-06-29 (hành vi cũ — BUG)
- Autoreply listener SKIP toàn bộ khi `notebook_id <= 0`
- AI Replier throw `RuntimeException('no_notebook_attached')` khi `notebook_id <= 0`
- Log: `skip conv#X: no_notebook`

### Sau 2026-06-29 (hành vi đúng)
| Tình huống | Kết quả |
|---|---|
| Character bound + Notebook attached | AI trả lời với KG retrieval (đầy đủ nhất) |
| Character bound + Không có notebook | AI trả lời với system_prompt + FAQ only (không KG) |
| Không có character, có notebook | **SKIP** — không thể reply không có persona |
| Không có character, không có notebook | **SKIP** |

**Rule:** Notebook làm câu trả lời _phong phú hơn_ (KG-grounded),
nhưng KHÔNG phải điều kiện tiên quyết để AI trả lời.
Character (system_prompt + giọng điệu + FAQ) là điều kiện tiên quyết.

---

## 4. Cấu hình từ UI Channel Gateway

### Bước 1 — Kết nối Facebook Page
`Channel Gateway → Facebook → Quản lý Pages → OAuth Kết nối`

### Bước 2 — Chọn Twin Guru
`Channel Gateway → Facebook → Danh sách Pages → [Cột Twin Guru phụ trách] → Chọn character`

- FE gọi `upsertBinding({ platform: 'FB_MESS', account_id: page_id, character_id, mode:'auto', auto_reply:true })`
- Lưu vào `_bizcity_channel_bindings` với `platform='FB_MESS'`

### Bước 3 — Chỉnh sửa Guru
`[Nút Quick Edit]` → chỉnh system_prompt, giọng điệu, FAQ trong `GuruQuickEditSheet`

### Bước 4 — Gắn Notebook (tuỳ chọn, để AI có kiến thức cụ thể)
`GuruQuickEditSheet → Tab Notebooks → Gắn notebook`

### Bước 5 — Bật Autoreply
`Channel Gateway → Facebook → [Cột AI trả lời] → Toggle ON`  
hoặc `[Nút Chi tiết] → Messenger Inbox → LIVE mode`

---

## 5. Subscribe Webhook Fields (bắt buộc để nhận tin)

Sau khi kết nối Page, vào **Meta App Dashboard** và subscribe các fields:

| Field | Mục đích |
|---|---|
| `messages` | Nhận tin nhắn từ user |
| `messaging_postbacks` | Nhận postback button |
| `message_deliveries` | Xác nhận delivery |

Nếu inbox trống dù đã kết nối → kiểm tra webhook subscription trong Meta App Dashboard.

---

## 6. Autoreply Suppression (legacy bot bypass)

`BizCity_CRM_AI_Autoreply_Listener::maybe_suppress_legacy()` được gọi trước
khi legacy bot handler chạy. Nếu CRM Guru đang active cho page đó:
- Return `true` → suppresses legacy bot reply
- Legacy bot không gửi tin → CRM Replier xử lý

---

## 7. Log Pattern để debug

```
# Tìm trong PHP error log (WP_DEBUG=true) hoặc BizCity Debug tab:

skip conv#X: no_notebook              → KHÔNG CÒN xảy ra khi char bound
no_notebook_but_character: conv#X...  → char-only mode, KG skipped
→ kg_retrieval SKIP notebook_id=0    → in replier, confirms char-only path
→ resolve_context notebook#0 ...     → replier log khi char-only

# Nếu vẫn thấy guru_char#0:
# → platform binding mismatch. Kiểm tra _bizcity_channel_bindings:
#   SELECT platform, account_id, character_id FROM wp_418_bizcity_channel_bindings;
# → Nếu trống: user chưa chọn Guru trong UI.
# → Nếu có row nhưng vẫn guru_char#0: xem platform alias mapping.
```

---

## 8. Anti-patterns CẤM

- ❌ Hard-code `if ($notebook_id <= 0) { return; }` mà không kiểm tra `character_id` — bỏ sót autoreply.
- ❌ Guru Resolver chỉ query `WHERE UPPER(platform)='FACEBOOK'` — bỏ sót binding `'FB_MESS'`.
- ❌ Gọi `BizCity_KG_Retriever::ask(0, ...)` với `notebook_id=0` — DB query lỗi.
- ❌ Lưu binding với `platform='FACEBOOK'` trong FE khi UCL dùng `'FB_MESS'` — gây ambiguity.
- ❌ Thiếu webhook subscription trong Meta App Dashboard — inbox luôn trống.
