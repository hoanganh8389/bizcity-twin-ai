# Knowledge Base — Kho Kiến Thức (KG Hub)

> **Knowledge Base** (hay **KG Hub**) là nơi bạn lưu trữ mọi thông tin về doanh nghiệp,
> sản phẩm, dịch vụ, quy trình... để TwinBrain có thể tra cứu và trả lời chính xác.
> Đây là nền tảng của mọi câu trả lời thông minh trong BizCity Twin AI.

---

## Knowledge Base là gì?

Khác với ChatGPT thông thường chỉ biết kiến thức đến ngày training, TwinBrain với
Knowledge Base của bạn sẽ:

- Biết **sản phẩm, dịch vụ, giá cả** cụ thể của bạn
- Biết **quy trình nội bộ, chính sách** của doanh nghiệp
- Biết **thông tin khách hàng** đã lưu trong CRM
- Trả lời dựa trên **nội dung website** của bạn
- Tóm tắt **tài liệu PDF** khi cần

---

## Các loại nguồn kiến thức

| Loại nguồn | Ví dụ | Tần suất cập nhật |
|---|---|---|
| **Website / URL** | Blog, trang sản phẩm, FAQ | Tự động (crawl định kỳ) |
| **PDF / Word / Excel** | Hợp đồng, brochure, bảng giá | Thủ công (upload lại khi có bản mới) |
| **YouTube / Video** | Video hướng dẫn, podcast | Thủ công |
| **Google Drive** | Tài liệu nội bộ | Tự động (qua OAuth) |
| **Văn bản thủ công** | FAQ tự soạn, script CSKH | Thủ công |

---

## Graph RAG — Điều làm nên sự khác biệt

BizCity Twin AI dùng **Graph RAG** thay vì Vector Search đơn thuần:

```
Vector Search thông thường:
  Query → Top-K similar chunks → Answer

Graph RAG của BizCity:
  Query → Top-K chunks → Tìm liên kết giữa chunks → 
  Mở rộng theo graph → Reasoning đa chiều → Answer
```

Kết quả: TwinBrain hiểu **ngữ cảnh và mối quan hệ** giữa các thông tin,
không chỉ tìm nội dung gần giống.

---

## Bắt đầu

→ [Thêm nguồn kiến thức đầu tiên](add-sources.md)

→ [Nhúng website](sources/website.md)

→ [Upload tài liệu](sources/document.md)

---

## Liên kết liên quan

- [TwinBrain — dùng Knowledge Base để trả lời](../twinbrain/overview.md)
- [Tìm kiếm & RAG](search.md)
