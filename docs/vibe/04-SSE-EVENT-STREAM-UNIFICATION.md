# 04 — SSE Event Stream Unification: Mọi lời gọi LLM đều qua Twin Core Stream

> **Status:** ✅ CANON HIỆN HÀNH — tổng hợp lại
> [PHASE-0-RULE-EVENT-STREAM.md](../rules/PHASE-0-RULE-EVENT-STREAM.md) (R-EVT),
> [PHASE-0-RULE-MPR-THINKING.md](../rules/PHASE-0-RULE-MPR-THINKING.md), và
> [PHASE-0-RULE-GATEWAY-ONLY.md](../rules/PHASE-0-RULE-GATEWAY-ONLY.md) (R-GW-8)
> áp dụng riêng cho plugin.

---

## 1. Phát biểu gốc

> *"Tất cả dòng chảy khi sử dụng đến LLM đều đi qua Twin Core Stream SSE."*

Có 2 lớp cần phân biệt rõ:

1. **Lớp gọi provider AI** — plugin không bao giờ tự gọi OpenAI/OpenRouter/
   Tavily/Kling trực tiếp. Luôn qua `BizCity_LLM_Client` /
   `BizCity_Search_Client` / `BizCity_Video_Client` / `BizCity_Astro_Client`
   (R-GW-8, đã canon hoá toàn framework — không phải rule riêng của tài liệu
   này).
2. **Lớp phát tiến trình cho user** — mọi tiến trình/kết quả suy luận hiển thị
   cho người dùng phải chảy qua **Twin Event Bus** →
   `bizcity_twin_event_stream` → SSE (`token`/`twin_event`/`complete`/`error`)
   — đây là phần user yêu cầu chuẩn hoá và là trọng tâm của tài liệu này.

## 2. Vì sao không cho plugin tự mở SSE riêng

- Một khái niệm user-facing chỉ có **một owner trạng thái/UI** (nguyên tắc đã
  ghi trong nhiều rule — ví dụ R-TWEB-3 "Spine Reuse"). Nếu mỗi plugin tự mở
  1 luồng SSE riêng, FE (TwinChat/Twin GPT) phải lắng nghe N luồng khác nhau,
  phá vỡ Thinking Timeline hợp nhất mà user đang thấy.
- Trace/replay/CRM projection (xem
  [TWINBRAIN-MPR-FINAL-GATE-RUNTIME-EVIDENCE-ROADMAP.md](../../core/twinbrain/docs/TWINBRAIN-MPR-FINAL-GATE-RUNTIME-EVIDENCE-ROADMAP.md))
  chỉ hoạt động nếu event đi qua đúng 1 Event Bus — một luồng SSE song song sẽ
  không bao giờ xuất hiện trong CRM Goal Loop timeline hay Diagnostics.

## 3. Cách plugin "phát tiến trình" đúng chuẩn

Khi Tool/Skill của plugin được TwinBrain dispatch (Layer 2.5, xem
[01-VERTICAL-BRAIN-PLUGIN-MODEL.md](01-VERTICAL-BRAIN-PLUGIN-MODEL.md)),
runtime đã tự động emit các event chuẩn quanh lời gọi đó (`tool_intent_done`,
tương lai `tools_step_done` — xem RFC
[TWINBRAIN-EXT-VERTICAL-CONVERSATION-MODES.md §3.5](../../core/twinbrain/docs/TWINBRAIN-EXT-VERTICAL-CONVERSATION-MODES.md)).
Plugin **không cần tự viết code emit SSE** — chỉ cần trả về kết quả đúng
schema Tool (`output_fields`), Runtime lo phần còn lại.

Nếu Tool chạy lâu (nhiều bước con), Tool được phép emit sub-progress qua đúng
Twin Event Bus (không phải `echo`/response riêng) — xem pattern
`emit_event()` trong `class-twinbrain-runtime.php`.

## 4. Khi plugin cần báo tiến trình ra kênh chat (Zalo Bot/Telegram)

Với kênh chỉ có text (không có UI Timeline), tiến trình được chiếu qua
**Progress Notice Projector** (R-TWIN-NOTICE, xem
[TWINBRAIN-MPR-V5-GOAL-LOOP-INTENT-NOTICE-HIL-ROADMAP.md §6](../../core/twinbrain/docs/TWINBRAIN-MPR-V5-GOAL-LOOP-INTENT-NOTICE-HIL-ROADMAP.md)),
KHÔNG phải plugin tự soạn câu "đang xử lý..." rồi gửi thẳng qua
`bizcity_channel_send()`. Rule phạm vi: R-TWIN-NOTICE **chỉ áp dụng cho kênh
Zone 2 `user_bound`** (Zalo Bot, Telegram, TwinChat command mode) — KHÔNG áp
dụng cho Zone 1 CRM `guest_channel` (Messenger, Zalo OA, WebChat CSKH), vì
gửi tiến trình kỹ thuật cho khách hàng thật là trải nghiệm sai (đã có tiền lệ
lỗi được ghi nhận trong tài liệu gốc).

## 5. MPR Thinking Timeline — nơi user "nhìn thấy" plugin đang làm gì

Timeline hiển thị đúng thứ tự stage canonical (xem
[PHASE-0-RULE-MPR-THINKING.md](../rules/PHASE-0-RULE-MPR-THINKING.md)) —
plugin không tự thêm bước mới vào thứ tự này; nếu cần 1 label riêng (vd "Đã
tra cứu đơn hàng Woo"), nó xuất hiện dưới dạng chi tiết của
`tool_intent_done`/`notebook_selection_done` đã có, không phải một
`decision.stage` mới tự chế. Muốn thêm `decision.stage`/`event_type` mới PHẢI
đi qua quy trình RFC của R-EVT-2 (schema whitelist), không được dispatch tuỳ
tiện — vi phạm sẽ bị `BizCity_Event_Validation_Exception` chặn.

## 6. Checklist bắt buộc

Trạng thái các điều kiện SSE/Event Stream được quản lý tập trung tại
[MASTER-CHECKLIST.md SSE-1..SSE-5](MASTER-CHECKLIST.md). Phần này chỉ giữ
giải thích contract, không chứa checkbox trạng thái riêng.

## 7. Tham chiếu

- [PHASE-0-RULE-EVENT-STREAM.md](../rules/PHASE-0-RULE-EVENT-STREAM.md)
- [PHASE-0-RULE-MPR-THINKING.md](../rules/PHASE-0-RULE-MPR-THINKING.md)
- [PHASE-0-RULE-GATEWAY-ONLY.md](../rules/PHASE-0-RULE-GATEWAY-ONLY.md)
- [TWINBRAIN-MPR-V5-GOAL-LOOP-INTENT-NOTICE-HIL-ROADMAP.md](../../core/twinbrain/docs/TWINBRAIN-MPR-V5-GOAL-LOOP-INTENT-NOTICE-HIL-ROADMAP.md)
