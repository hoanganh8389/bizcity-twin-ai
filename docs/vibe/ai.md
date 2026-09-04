# AI Agent Onboarding — Đọc file này TRƯỚC KHI viết bất kỳ plugin mới nào

> Dành cho AI coding agent (GitHub Copilot, Cursor, Antigravity, Claude Code,
> Codex...) khi được yêu cầu tạo một plugin/extension mới cho BizCity Twin
> AI. Mục tiêu: agent chỉ cần đọc file này + 1-2 link được trỏ tới, KHÔNG cần
> đọc hết `core/` (2.400+ file) để bắt đầu code an toàn.

## 1. Sự thật nền tảng (đừng đoán, đọc đúng những dòng này)

1. Bạn KHÔNG được sửa bất cứ file nào trong `core/` hoặc `modules/` để thêm
   tính năng mới. Muốn thêm khả năng → tạo **plugin mới** trong `plugins/`.
   Xem tôn chỉ đầy đủ: [00-VIBE-CANON.md](00-VIBE-CANON.md).
2. Plugin của bạn CHỈ được nói chuyện với core qua **7 hàm đăng ký công khai**:
   `register_plugin()`, `register_tool()`, `register_skill()`,
   `register_source()`, `register_event()`, `register_diagnostic()`,
   `register_ui()`. Không gọi thẳng class nội bộ `core/*/includes/*`.
   Chi tiết: [07-PLUGIN-SDK-PUBLIC-INTERFACES.md](07-PLUGIN-SDK-PUBLIC-INTERFACES.md).
3. Plugin PHẢI có file khai báo `twin-plugin.json` (map từ `manifest.json` đã
   có sẵn schema). Đọc: [08-TWIN-PLUGIN-MANIFEST-SPEC.md](08-TWIN-PLUGIN-MANIFEST-SPEC.md).
4. Plugin PHẢI thuộc đúng 1 trong 3 nhóm: **Act** (thực thi ra CRM/POS/ERP),
   **Channel** (dẫn dòng chảy hội thoại từ Zalo/FB/Google...), **View**
   (UI/dashboard). Đọc: [06-PLUGIN-TAXONOMY-ACT-CHANNEL-VIEW.md](06-PLUGIN-TAXONOMY-ACT-CHANNEL-VIEW.md).
5. Plugin KHÔNG được tự viết pipeline gọi LLM riêng, KHÔNG tự mở SSE stream
   riêng ra browser. Mọi suy luận AI đi qua TwinBrain Runtime + Twin Event
   Bus. Đọc: [04-SSE-EVENT-STREAM-UNIFICATION.md](04-SSE-EVENT-STREAM-UNIFICATION.md).
6. Plugin KHÔNG được tạo bảng SQL log/audit mới. Mọi log/dữ liệu vận hành đi
   qua `BizCity_JSONL_File_Logger` + đăng ký `BizCity_Log_Contract_Registry`.
   Đọc: [02-FILESTORE-LOG-INDEX-STANDARD.md](02-FILESTORE-LOG-INDEX-STANDARD.md).
7. Plugin KHÔNG được đọc/ghi trực tiếp bảng `bizcity_kg_*`. Tri thức plugin
   thu thập được phải chảy vào KG Hub qua KG Source Adapter facade.
   Đọc: [03-KG-HUB-UNIFICATION.md](03-KG-HUB-UNIFICATION.md).
8. Plugin KHÔNG được tự dựng UI chat/inbox khách hàng riêng. Bề mặt ra ngoài
   canonical là `/gpt` (`modules/twinweb`) cho member workspace và
   `bizcity-twin-crm` cho dữ liệu khách hàng (Zone 1). Đọc:
   [05-SURFACE-UNIFICATION-TWINWEB-CRM.md](05-SURFACE-UNIFICATION-TWINWEB-CRM.md).
9. Plugin KHÔNG được gọi thẳng provider AI (OpenAI/OpenRouter/Tavily/Kling...).
   Luôn dùng `BizCity_LLM_Client` / `BizCity_Search_Client` / `BizCity_Video_Client`.
   Xem [PHASE-0-RULE-GATEWAY-ONLY.md](../rules/PHASE-0-RULE-GATEWAY-ONLY.md) (R-GW-8).
10. Trước khi coi plugin "xong", chạy lint + diagnostics — đừng tự khai PASS.
    Đọc: [10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md](10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md).

## 2. Quy trình bắt buộc (closed loop)

```text
USER IDEA
  → đọc file này (ai.md) + 00-VIBE-CANON.md
  → xác định taxonomy: Act | Channel | View (06)
  → sinh skeleton bằng CLI scaffold (09) — KHÔNG tự sáng tác cấu trúc thư mục
  → điền logic vào skeleton (Tool/Skill/Source/Event/Diagnostic — 07/08)
  → chạy plugin lint (10)
  → FAIL → tự sửa đúng lỗi được lint chỉ ra → lint lại
  → PASS → chạy diagnostics runtime probe (10, 11)
  → FAIL → tự sửa → chạy lại
  → PASS → plugin coi là INSTALLABLE
```

Không được bỏ qua bước lint/diagnostics chỉ vì "code trông đúng". Không được
tự đánh dấu file `README.md`/comment là "DONE" nếu chưa có bằng chứng chạy
thật (Disk/Loader/Runtime) — nguyên tắc R-DDV áp dụng y hệt plugin của bên
thứ 3.

## 3. Master canonical bắt buộc đọc theo domain

| Nếu plugin của bạn đụng tới... | Đọc trước |
|---|---|
| Bất kỳ domain nào | [PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md](../rules/PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md), [PHASE-0-RULE-BRAIN-UNIFICATION.md](../rules/PHASE-0-RULE-BRAIN-UNIFICATION.md) |
| Gọi LLM/Search/Video/Astro | [PHASE-0-RULE-GATEWAY-ONLY.md](../rules/PHASE-0-RULE-GATEWAY-ONLY.md), [API catalog](../api/README.md) |
| Kênh chat (Zalo/FB/Telegram/WebChat) | [PHASE-0-RULE-CHANNEL-UNIFY.md](../rules/PHASE-0-RULE-CHANNEL-UNIFY.md), [PHASE-0-RULE-ZONE-CHANNEL.md](../rules/PHASE-0-RULE-ZONE-CHANNEL.md) |
| Tri thức/KG/RAG | [PHASE-0-RULE-KG-HUB-CONTRACT.md](../rules/PHASE-0-RULE-KG-HUB-CONTRACT.md) |
| Log/audit/file lưu trữ | [PHASE-0-RULE-LOG-HYBRID-CANON.md](../rules/PHASE-0-RULE-LOG-HYBRID-CANON.md), [PHASE-0-RULE-FILESTORE-BUSINESS-CANON.md](../rules/PHASE-0-RULE-FILESTORE-BUSINESS-CANON.md) |
| Mutation/side-effect ra hệ thống ngoài | [PUBLIC-CONTRACTS-v1.md](../contracts/PUBLIC-CONTRACTS-v1.md), [RUNTIME-PRODUCTION-CONTRACT-v1.md](../contracts/RUNTIME-PRODUCTION-CONTRACT-v1.md) |
| Lỗi/REST response | [PHASE-0-RULE-ERROR-UX.md](../rules/PHASE-0-RULE-ERROR-UX.md) |
| Event/SSE/Timeline | [PHASE-0-RULE-EVENT-STREAM.md](../rules/PHASE-0-RULE-EVENT-STREAM.md), [PHASE-0-RULE-MPR-THINKING.md](../rules/PHASE-0-RULE-MPR-THINKING.md) |
| Schema DB mới | [Diagnostics changelog rule](../diagnostics/PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md) |
| Chuẩn cấu trúc plugin cổ điển (3 trụ cột) | [PLUGIN-STANDARD.md](../extending/PLUGIN-STANDARD.md), [PLUGIN-TWIN-STANDARD.md](../extending/PLUGIN-TWIN-STANDARD.md) |

## 4. Câu hỏi tự kiểm (self-check) trước khi báo cáo "đã xong"

Trạng thái self-check được quản lý tập trung tại
[MASTER-CHECKLIST.md AGENT-1..AGENT-10](MASTER-CHECKLIST.md). Agent phải đọc
các ID này trước khi báo cáo "đã xong"; file onboarding này không chứa bản
checkbox thứ hai.

Nếu bất kỳ dòng nào ở trên là "không" → plugin CHƯA xong, bất kể code có chạy
được trên máy dev hay không.
