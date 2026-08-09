# Skills — Kỹ Năng AI Micro-Workflow

> **Skills** là các micro-workflow có sẵn cho AI. Thay vì viết prompt dài,
> bạn gọi 1 Skill theo tên — AI biết chính xác phải làm gì, theo template
> đã được tối ưu sẵn.

---

## Skill là gì?

Một Skill = Prompt template + Context injection + Post-processing logic.

**Ví dụ các Skill có sẵn:**

| Skill | Mô tả |
|---|---|
| `summarize` | Tóm tắt nội dung dài |
| `translate_vi` | Dịch sang tiếng Việt |
| `rewrite_professional` | Viết lại theo văn phong chuyên nghiệp |
| `generate_fb_post` | Tạo bài đăng Facebook tối ưu |
| `extract_keywords` | Trích xuất từ khoá |
| `customer_reply` | Trả lời khách hàng theo tone brand |
| `schedule_reminder` | Tạo nội dung nhắc nhở |

---

## Dùng Skill trong Automation

```
[Trigger] → [Action: Call AI với Skill "generate_fb_post"] → [Action: Đăng Facebook]
```

---

## Bắt đầu

→ [Danh mục Skills đầy đủ](catalog.md)

→ [Tạo Skill tùy chỉnh](custom.md)

→ [Skills trong Automation Workflow](in-automation.md)
