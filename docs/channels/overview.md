# Channel Gateway — Kênh Giao Tiếp

> **Channel Gateway** là tầng quản lý đa kênh của BizCity Twin AI. Nhận tin nhắn
> từ Facebook, Zalo, Telegram, WebChat về một **CRM Inbox** duy nhất — AI xử lý,
> tự động trả lời, tạo leads và đẩy sang Automation Workflow.

---

## Các kênh được hỗ trợ

| Kênh | Loại | Trạng thái | Ghi chú |
|---|---|---|---|
| **Facebook Messenger** | Official API | ✅ Sẵn sàng | Cần Page Token |
| **Zalo OA** | Official API | ✅ Sẵn sàng | Cần Zalo OA App |
| **Zalo Cá nhân** | Unofficial (zca-js) | ✅ Sẵn sàng | ⚠️ Có rủi ro tài khoản |
| **Telegram** | Bot API | ✅ Sẵn sàng | Cần Bot Token |
| **WebChat** | Widget nhúng | ✅ Sẵn sàng | Không cần tài khoản |

---

## CRM Inbox — Hộp thư hợp nhất

Tất cả tin nhắn từ mọi kênh tập trung về **CRM Inbox** trong WP Admin:

```
Facebook Messenger ──┐
Zalo OA              ├──→ Channel Gateway ──→ CRM Inbox ──→ TwinBrain / Automation
Zalo Cá nhân         │         │
Telegram             │         └──→ Intent Engine ──→ Xử lý tự động
WebChat ─────────────┘
```

Mỗi hội thoại có:
- Thread đầy đủ với lịch sử
- Tag nguồn kênh (Zalo / FB / Telegram...)
- Trạng thái xử lý (mới / đang xử lý / done)
- AI reply gợi ý

---

## Bắt đầu theo kênh

- [Facebook Messenger](facebook.md) — Kết nối Facebook Page
- [Zalo OA](zalo-oa.md) — Kết nối Zalo Official Account
- [Zalo Cá nhân](zalo-personal/overview.md) — Qua zca-bridge (unofficial)
- [Telegram](telegram.md) — Kết nối Telegram Bot
- [WebChat](webchat.md) — Nhúng widget vào website

---

## Tổng quan kiến trúc

```
[Kênh bên ngoài]
        │  webhook / polling
        ▼
[Channel Gateway] ──→ BizCity_Inbound_Emitter
        │                      │
        │              do_action('bizcity_channel_message_received')
        │                      │
        ▼                      ▼
[CRM Inbox DB]          [Intent Engine]
                               │
                     ┌─────────┴──────────┐
                     ▼                    ▼
              [TwinBrain AI]      [Automation Workflow]
                     │                    │
                     └──────────┬─────────┘
                                ▼
                         [Reply / Action]
```

---

## Liên kết liên quan

- [Automation — tự động xử lý tin nhắn](../automation/overview.md)
- [CRM Inbox chi tiết](crm-inbox.md)
