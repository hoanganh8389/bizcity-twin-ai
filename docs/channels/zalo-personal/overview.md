# Zalo Cá nhân — Tổng quan

> Kết nối tài khoản **Zalo cá nhân** vào CRM Inbox của BizCity Twin AI, cho phép
> nhận & gửi tin nhắn Zalo trực tiếp từ WordPress — hoàn toàn tự động.

---

## ⚠️ CẢNH BÁO — Đọc trước khi sử dụng

Kênh Zalo cá nhân hoạt động qua thư viện **`zca-js`** (unofficial API).
Việc sử dụng **vi phạm Điều khoản dịch vụ của Zalo** và có thể:

- Tài khoản bị **tạm khóa** hoặc **cấm vĩnh viễn**
- Yêu cầu xác minh lại danh tính

**→ Chỉ nên dùng tài khoản Zalo phụ, được tạo riêng cho mục đích kinh doanh.**

Nếu muốn dùng kênh Zalo **chính thức và an toàn**, hãy dùng [Zalo OA](../zalo-oa.md).

---

## Hướng dẫn từng bước

1. **[Cài đặt zca-bridge](setup-bridge.md)** — cài sidecar Node.js tự host
2. **[Đăng nhập QR Code](login-qr.md)** — quét QR bằng điện thoại
3. **[Xác minh luồng nhận tin](verify-inbound.md)** — kiểm tra tin nhắn vào CRM
4. **[Xử lý sự cố](troubleshooting.md)** — khi có vấn đề

---

## Kiến trúc tổng quan

```
Điện thoại Zalo
    │ tin nhắn
    ▼
[zca-js listener] trong zca-bridge (Node.js — bạn tự host)
    │ forward inbound
    ▼
POST /wp-json/bizcity-channel/v1/zalo-bridge/inbound
    │ Bearer token
    ▼
WordPress: BizCity_Zalo_Inbound_Emitter
    │
    ▼
CRM Inbox → TwinBrain / Automation
```

---

## Yêu cầu

- Một **VPS / server** chạy Docker (hoặc Node.js 24+) — cùng hoặc khác server WordPress
- Plugin `bizcity-twin-ai` version ≥ PHASE-0.39
- Tài khoản Zalo (nên dùng tài khoản phụ)

---

## Tính năng hỗ trợ

| Tính năng | Hỗ trợ |
|---|---|
| Nhận tin nhắn 1-1 | ✅ |
| Nhận tin nhắn nhóm | ✅ |
| Gửi tin nhắn text | ✅ |
| Gửi ảnh / file | ✅ |
| Nhận/gửi sticker | ✅ |
| Trả lời trích dẫn | ✅ |
| Thu hồi tin nhắn | ✅ |
| Đa tài khoản | ✅ (qua nhiều account trong bridge) |

---

## Liên kết

- [Zalo OA (chính thức, an toàn hơn)](../zalo-oa.md)
- [Tài liệu zca-js](https://tdung.gitbook.io/zca-js)
- [Hướng dẫn chi tiết đầy đủ (tiếng Việt)](../../plugins/bizcity-zalo-personal/docs/HUONG-DAN-KET-NOI-ZALO-CA-NHAN.md)
