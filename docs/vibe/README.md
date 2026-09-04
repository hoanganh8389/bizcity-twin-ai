# docs/vibe/ — All Channel, One Brain: Closed-Loop Vibe Coding

> **Tuyên ngôn hệ thống:** **All Channel, One Brain.** Đây là bộ tài liệu "hiến pháp mở rộng" (extension constitution) mô
> tả cách BizCity Twin trở thành một **framework lõi** mà cộng đồng lập trình viên
> (và AI coding agent — Copilot, Cursor, Antigravity, Claude Code...) có thể dựa
> vào để **vibe-code** ra plugin mới mà KHÔNG cần hiểu 230+ class nội bộ của core.
>
> **Không override** [PHASE-0-CANON.md](../rules/PHASE-0-CANON.md) hay
> [PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md](../rules/PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md).
> Toàn bộ nội dung ở đây là lớp "áp dụng lại" (re-application) các rule đã tồn tại
> vào một mục tiêu cụ thể: đóng vòng lặp *viết plugin → lint → self-diagnostic →
> AI tự sửa → PASS → cài đặt được*.
>
> **Ngày mở:** 2026-08-29 · **Owner:** Johnny Chu · **Status:** 🟡 tài liệu định
> hướng (canon-level vision), triển khai code theo waves ở
> [12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md](12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md).

---

## 0. Tuyên ngôn và cách hiện thực hóa (Johnny Chu)

> **All Channel, One Brain.** Mọi channel, dữ liệu, chức năng và nhu cầu của
> doanh nghiệp cùng làm việc trên một bộ não chung.

Nguyên tắc dành cho Dev để hiện thực hóa tuyên ngôn là: **muốn thêm khả năng,
hãy thêm plugin, không sửa Core**.
>
> **Tôn chỉ #2: Twin Framework là một Twin Data Center — không phải xây
> 1 chatbot thông minh, mà là tập trung dòng chảy dữ liệu/plugin/ứng dụng
> của doanh nghiệp về 1 chỗ để phân tích, graph hoá, dashboard và trợ
> giúp chiến lược — sau đó doanh nghiệp cắm MCP vào là dùng được
> Claude/ChatGPT trên dữ liệu đã unify.**

Mọi tài liệu trong folder này phục vụ đúng hai tôn chỉ trên: làm cho chúng
**khả thi về mặt kỹ thuật**, không chỉ là khẩu hiệu. Xem chi tiết ở
[00-VIBE-CANON.md](00-VIBE-CANON.md) và đánh giá phản biện thật ở
[13-GAP-CRITIQUE-READINESS-REVIEW.md](13-GAP-CRITIQUE-READINESS-REVIEW.md).

## 1. Trạng thái tài liệu

Mỗi file dùng 1 trong 3 nhãn trạng thái ở đầu file:

| Nhãn | Ý nghĩa |
|---|---|
| ✅ **CANON HIỆN HÀNH** | Mô tả rule/contract đã tồn tại và đang thi hành trong code — chỉ tổng hợp lại, không phát minh. |
| 🟡 **MỘT PHẦN** | Hạ tầng đã có nhưng chưa đủ để đạt tầm nhìn — có gap analysis + đề xuất. |
| ⛔ **ROADMAP/PROPOSAL** | Chưa có code — là đề xuất kiến trúc cần review trước khi build. |

Không tài liệu nào ở đây được phép tự nhận "tối thượng" theo nghĩa override
rule cũ — nếu có xung đột, [PHASE-0-CANON.md](../rules/PHASE-0-CANON.md) và
[PHASE-0-RULES.md](../rules/PHASE-0-RULES.md) thắng.

## 2. Thứ tự đọc

| # | File | Trả lời câu hỏi |
|---|---|---|
| 1 | [ai.md](ai.md) | "Tôi là AI coding agent, tôi cần đọc gì trước khi viết 1 dòng code?" |
| 2 | [00-VIBE-CANON.md](00-VIBE-CANON.md) | "Kiến trúc tổng — One KG Hub → Many Vertical Brain Modes → Unlimited Plugins là gì? Tôn chỉ Twin Data Center là gì?" |
| 3 | [01-VERTICAL-BRAIN-PLUGIN-MODEL.md](01-VERTICAL-BRAIN-PLUGIN-MODEL.md) | "Plugin của tôi trở thành 1 'vertical brain mode' bằng cách nào?" |
| 4 | [02-FILESTORE-LOG-INDEX-STANDARD.md](02-FILESTORE-LOG-INDEX-STANDARD.md) | "Plugin lưu log/dữ liệu ở đâu, theo chuẩn nào?" |
| 5 | [03-KG-HUB-UNIFICATION.md](03-KG-HUB-UNIFICATION.md) | "Dữ liệu/tri thức của plugin chảy về đâu để cả hệ thống dùng chung?" |
| 6 | [04-SSE-EVENT-STREAM-UNIFICATION.md](04-SSE-EVENT-STREAM-UNIFICATION.md) | "Khi plugin cần gọi LLM hoặc báo tiến trình, đi qua kênh nào?" |
| 7 | [05-SURFACE-UNIFICATION-TWINWEB-CRM.md](05-SURFACE-UNIFICATION-TWINWEB-CRM.md) | "Plugin hiển thị/giao tiếp ra ngoài qua bề mặt nào?" |
| 8 | [06-PLUGIN-TAXONOMY-ACT-CHANNEL-VIEW.md](06-PLUGIN-TAXONOMY-ACT-CHANNEL-VIEW.md) | "Plugin của tôi thuộc nhóm Act, Channel hay View?" |
| 9 | [07-PLUGIN-SDK-PUBLIC-INTERFACES.md](07-PLUGIN-SDK-PUBLIC-INTERFACES.md) | "7 hàm register_*() công khai là gì, map vào class nào?" |
| 10 | [08-TWIN-PLUGIN-MANIFEST-SPEC.md](08-TWIN-PLUGIN-MANIFEST-SPEC.md) | "`twin-plugin.json` cần khai báo những field nào?" |
| 11 | [09-CLI-SCAFFOLDING-WP-BIZCITY.md](09-CLI-SCAFFOLDING-WP-BIZCITY.md) | "Lệnh nào sinh skeleton plugin/tool/source/event/diagnostic?" |
| 12 | [10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md](10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md) | "Làm sao để AI tự lint, tự sửa, tự PASS?" |
| 13 | [11-SCOREBOARD-AUDIT-FRAMEWORK.md](11-SCOREBOARD-AUDIT-FRAMEWORK.md) | "Bảng điểm PASS/FAIL cuối cùng trông như thế nào?" |
| 14 | [12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md](12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md) | "Cần build gì, theo thứ tự nào, để đạt được tầm nhìn này?" |
| 15 | [13-GAP-CRITIQUE-READINESS-REVIEW.md](13-GAP-CRITIQUE-READINESS-REVIEW.md) | "Khung này đã chắc chưa? GAP thật nào cần biết trước khi code?" |
| 16 | [MASTER-CHECKLIST.md](MASTER-CHECKLIST.md) | "Trạng thái từng hạng mục (TODO/DONE/BLOCKED) hiện giờ là gì?" |

## 3. Nguyên tắc biên soạn

- Không lặp lại nội dung đã có ở [FRAMEWORK-GUIDE-v1.md](../framework/FRAMEWORK-GUIDE-v1.md),
  [PUBLIC-CONTRACTS-v1.md](../contracts/PUBLIC-CONTRACTS-v1.md),
  [PLUGIN-STANDARD.md](../extending/PLUGIN-STANDARD.md),
  [PLUGIN-TWIN-STANDARD.md](../extending/PLUGIN-TWIN-STANDARD.md) — chỉ trích
  dẫn và tổng hợp lại theo góc nhìn "vibe coding closed loop".
- Mọi class/file được nêu là "đã có" phải là tên thật trong repo tại thời điểm
  viết (2026-08-29) — nếu chưa có, đánh dấu rõ ⛔ ROADMAP.
- Không tạo thuật ngữ mới trùng nghĩa với thuật ngữ đã canon hoá (vd không gọi
  lại "Central Brain" là tên khác — dùng đúng "KG Hub", "TwinBrain Runtime",
  "Twin Event Bus" như code hiện tại).
- **Không thêm checklist (`- [ ]`) mới vào bất kỳ file nào khác ngoài
  [MASTER-CHECKLIST.md](MASTER-CHECKLIST.md).** Mọi task/roadmap item mới
  lấy ID mới trong đó; các file khác chỉ trích dẫn ID để tránh phân mảnh
  trạng thái tiến độ ra nhiều nơi khó theo dõi.
