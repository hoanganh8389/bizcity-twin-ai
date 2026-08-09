# Automation Workflow — Tự Động Hoá

> **Automation** là công cụ xây dựng quy trình tự động không cần lập trình.
> Kéo-thả block để tạo workflow: khi nhận tin Zalo → AI xử lý → đăng bài Facebook
> → nhắc nhở khách hàng... Tất cả chạy tự động 24/7.

---

## Automation làm được gì?

### Ví dụ workflow thực tế

**1. Trả lời tự động tin nhắn Zalo:**
```
[Trigger: Tin nhắn Zalo] → [AI xử lý intent] → [Trả lời tự động]
```

**2. Đăng bài Facebook tự động mỗi ngày:**
```
[Trigger: Cron 8h sáng] → [AI sinh nội dung] → [Đăng Facebook Page]
```

**3. Nhắc khách hàng gia hạn:**
```
[Trigger: Sự kiện CRM] → [Condition: Còn 7 ngày] → [Gửi Zalo nhắc nhở]
```

**4. Thu thập leads từ chat:**
```
[Trigger: Tin nhắn chứa "báo giá"] → [AI trả lời] → [Tạo CRM contact] → [Gửi email nội bộ]
```

---

## Cấu trúc Workflow

Mỗi workflow gồm:

```
Trigger (1)
    │
    ▼
Condition (0..n) — lọc / phân nhánh
    │
    ▼
Action (1..n) — hành động thực thi
```

### Triggers (Kích hoạt)
- Tin nhắn từ kênh (Zalo, Facebook, Telegram, WebChat)
- Lịch cố định (Cron) — mỗi ngày, mỗi tuần...
- Sự kiện CRM (tạo contact, đặt lịch...)
- Webhook từ bên ngoài

### Actions (Hành động)
- Gửi tin nhắn qua bất kỳ kênh nào
- Đăng bài Facebook / WordPress
- Gọi TwinBrain AI để xử lý
- Tạo/cập nhật sự kiện CRM
- Đặt lịch nhắc nhở

---

## Bắt đầu

→ [Tạo workflow đầu tiên](quickstart.md)

→ [Danh mục Triggers](triggers.md)

→ [Danh mục Actions](actions.md)

→ [Ví dụ workflow thực tế](examples.md)

---

## Liên kết liên quan

- [Channel Gateway — kênh nhận/gửi tin](../channels/overview.md)
- [Scheduler — lịch hẹn tích hợp](../scheduler/overview.md)
- [TwinBrain trong Automation](actions/call-ai.md)
