# TwinBrain — Trợ lý AI Cá nhân

> **TwinBrain** là lõi AI của BizCity Twin AI. Đây là trợ lý thông minh đa chế độ,
> kết hợp Knowledge Base riêng của bạn với các mô hình ngôn ngữ lớn (LLM) để trả lời
> câu hỏi chuyên sâu, nghiên cứu, sáng tạo nội dung và hơn thế nữa.

---

## TwinBrain làm được gì?

| Khả năng | Mô tả |
|---|---|
| **Multi-mode reasoning** | 8 chế độ AI độc lập: Research, Creative, Expert, Astro, Numerology... |
| **Knowledge-aware** | Tự động tra cứu Knowledge Base của bạn trước khi trả lời |
| **Web search** | Tìm kiếm internet real-time khi cần thông tin cập nhật |
| **Multi-perspective** | Phân tích 1 vấn đề từ nhiều góc nhìn (MPR Thinking) |
| **Session history** | Lưu trữ & resume hội thoại bất kỳ lúc nào |
| **Streaming** | Trả lời theo dòng real-time, không chờ load |

---

## Các chế độ AI

→ Xem chi tiết từng chế độ tại [Các chế độ AI](modes.md)

---

## Bắt đầu sử dụng TwinBrain

1. Đảm bảo đã [kết nối BizCity API Key](../getting-started/api-key.md).
2. Vào **WP Admin → BizCity → TwinChat** (hoặc mở TwinChat widget).
3. Chọn chế độ AI phù hợp ở thanh bên trái.
4. Nhập câu hỏi và nhấn Enter.

> **Mẹo:** Để TwinBrain trả lời chính xác hơn về lĩnh vực của bạn,
> hãy [thêm kiến thức vào Knowledge Base](../knowledge/overview.md) trước.

---

## Kiến trúc TwinBrain

TwinBrain hoạt động theo mô hình **ReAct Agent** (Reason + Act):

```
User Query
    │
    ▼
Intent Classifier ──→ Chọn chế độ + tools phù hợp
    │
    ▼
Knowledge Retrieval ──→ KG Hub (Graph RAG)
    │
    ▼
Web Search (nếu cần) ──→ Tavily / Google
    │
    ▼
Multi-Perspective Reasoning ──→ Phân tích đa góc
    │
    ▼
Final Answer (Streaming) ──→ TwinChat UI
```

---

## Liên kết liên quan

- [Lịch sử hội thoại (Sessions)](sessions.md)
- [Knowledge Base — Thêm kiến thức cho AI](../knowledge/overview.md)
- [Cài đặt TwinBrain](settings.md)
