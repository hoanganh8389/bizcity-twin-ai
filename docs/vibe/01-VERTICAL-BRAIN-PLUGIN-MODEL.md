# 01 — Vertical Brain Plugin Model

> **Status:** 🟡 MỘT PHẦN — khái niệm "Vertical Brain Mode" đã tồn tại trong
> [PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md](../rules/PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md)
> và trong roadmap TwinBrain
> ([TWINBRAIN-EXT-VERTICAL-CHAT-DEFAULT-ROADMAP.md](../../core/twinbrain/docs/TWINBRAIN-EXT-VERTICAL-CHAT-DEFAULT-ROADMAP.md),
> [TWINBRAIN-EXT-VERTICAL-CONVERSATION-MODES.md](../../core/twinbrain/docs/TWINBRAIN-EXT-VERTICAL-CONVERSATION-MODES.md)).
> Tài liệu này chuẩn hoá lại thành **hợp đồng bắt buộc cho mọi plugin mới**.

> ⚠️ **GAP THẬT (audit code 2026-08-29 — xem
> [13-GAP-CRITIQUE-READINESS-REVIEW.md](13-GAP-CRITIQUE-READINESS-REVIEW.md) §2):**
> điều mô tả dưới đây là **thiết kế mục tiêu**, KHÔNG phải hiện trạng. Hôm
> nay 100% "vertical" (`astro`, `products`, `woo_bizops`, `med`, `law`, `tax`,
> `gov`, `nutri`, `scholar`, `social`) là class hardcoded nằm trong
> `core/twinbrain/includes/class-twinbrain-web-*.php`, không phải plugin.
> `BizCity_TwinBrain_Conversation_Router::SPECIALIZED_ROUTING_ENABLED = false`
> — cơ chế route theo vertical đang BỊ TẮT. Chưa plugin nào implement
> `BizCity_KG_Source_Adapter_Interface` để trở thành 1 vertical brain mode
> thật. Xem Wave 8 trong
> [12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md](12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md)
> để biết kế hoạch đóng gap này.

---

## 1. Định nghĩa "Vertical Brain Mode"

> **Mỗi plugin = 1 Vertical Brain Mode.**

Một Vertical Brain Mode là một **lát cắt chuyên môn** (domain expertise) được
TwinBrain Runtime gọi vào đúng thời điểm trong pipeline suy luận chung, KHÔNG
phải một bộ não độc lập chạy song song. Plugin không sở hữu vòng lặp suy luận
của riêng nó — nó chỉ đóng góp:

1. **Evidence** (qua KG Source Adapter — nạp tri thức vào KG Hub).
2. **Capability thực thi** (qua Tool/Skill — được Tool Intent Matcher gọi khi
   cần).
3. **Kênh giao tiếp** (qua Channel Adapter — nếu plugin thuộc nhóm Channel).
4. **Trình bày** (qua Output Renderer/Persona — nếu plugin thuộc nhóm View).

## 2. Plugin "cắm" vào pipeline chung ở đâu

TwinBrain Runtime chạy một pipeline cố định (canonical, không đổi theo
plugin) — tóm tắt từ
[TWINBRAIN-MPR-V5-GOAL-LOOP-INTENT-NOTICE-HIL-ROADMAP.md §3](../../core/twinbrain/docs/TWINBRAIN-MPR-V5-GOAL-LOOP-INTENT-NOTICE-HIL-ROADMAP.md):

```text
inbound_accepted
  → subject_resolved
  → prompt_intent_detected
  → goal_draft_ready / goal_session_opened|resumed
  → notebook_selection_started/done      ← Vertical Brain injects EVIDENCE tại đây (KG Source Adapter)
  → tool_intent_started/done             ← Vertical Brain injects CAPABILITY tại đây (Tool/Skill)
  → retrieval_started/done               ← evidence nạp từ KG Hub được rerank cùng nguồn khác
  → draft_ready
  → reflection_done
  → retrieve_round_started/done (nếu cần bổ sung bằng chứng)
  → final_gate_decision
  → final_compose_started/done           ← duy nhất Final Composer soạn câu trả lời cuối
  → goal_delta_persisted
  → turn_completed|failed
```

**Nguyên tắc bất di bất dịch:** một plugin KHÔNG BAO GIỜ tự chèn một bước gọi
LLM riêng nằm ngoài chuỗi trên (vd: tự gọi `BizCity_LLM_Client::chat()` để
"tổng hợp câu trả lời phụ" rồi ghép vào response cuối bằng tay). Nếu plugin
cần LLM để xử lý dữ liệu nội bộ của chính nó (vd: tóm tắt 1 document trước khi
ingest vào KG Hub), lời gọi đó là **tác vụ nền** (ingestion-time), không phải
một bước trong Draft→Reflection→Final Gate của lượt hội thoại hiện tại.

## 3. "Functional bridge" — cầu nối chức năng của plugin

Khi user viết: *"mỗi vertical brain này đều sử dụng MPR thinking timeline và
inject layer data, tìm kiếm bằng functional bridge của plugin đó để trả kết
quả về thêm 1 thinking layer cho LLM model trả kết quả"* — điều này ánh xạ
chính xác vào 2 cơ chế đã tồn tại trong TwinBrain Runtime:

| Cơ chế | File | Vai trò |
|---|---|---|
| **KG Source Adapter** | `BizCity_KG_Source_Adapter_Interface` (`core/twin-core/contracts/content-contracts.php`) | "Tìm kiếm" nội bộ của plugin (vd: query đơn hàng Woo, tra cứu sản phẩm ERP) được expose thành nguồn có thể ingest/retrieve qua KG Hub — không phải một lời gọi runtime riêng biệt mỗi turn. |
| **Tool Intent Matcher (Layer 2.5)** | `core/twinbrain/includes/class-twinbrain-tool-intent-matcher.php` | Khi câu hỏi cần một hành động/tra cứu thời gian thực (không nằm trong KG đã ingest), Tool Intent Matcher chọn đúng Tool/Skill của plugin, dispatch qua `dispatch_tool()`, kết quả trả về được đưa vào Synthesis làm thêm 1 "thinking layer" trước khi Final Composer soạn câu trả lời — **evidence bổ sung, không phải câu trả lời cuối**. |

Kết quả trả về từ cả 2 đường trên đều được quan sát trong **MPR Thinking
Timeline** (`decision.stage` events, xem
[PHASE-0-RULE-MPR-THINKING.md](../rules/PHASE-0-RULE-MPR-THINKING.md)) — user
nhìn thấy "Đã chọn N notebook", "Đã tìm thấy tool", đúng như timeline hiện có
của TwinChat/Twin GPT. Plugin không cần tự vẽ UI riêng cho việc này.

## 4. Khai báo "brain layer" trong manifest

Một plugin implement KG Source Adapter hoặc Tool phải khai báo rõ trong
`twin-plugin.json` (xem [08](08-TWIN-PLUGIN-MANIFEST-SPEC.md)) để Diagnostics
và Tool Intent Matcher biết capability tồn tại — không có cơ chế "tự động
phát hiện" ẩn nào khác ngoài đăng ký tường minh.

```json
"capabilities": {
  "kg_source_adapters": [
    { "id": "woo.orders", "label": "Đơn hàng WooCommerce", "class": "BizCity_Woo_KG_Source_Adapter" }
  ],
  "tools": [
    { "id": "woo.create_order", "label": "Tạo đơn hàng", "class": "BizCity_Woo_Create_Order_Tool", "primary": true }
  ]
}
```

## 5. Ranh giới quyền sở hữu dữ liệu (owner scope)

Vertical Brain Mode của plugin chỉ được đọc tri thức trong đúng phạm vi quyền
của identity hiện tại (`owner_scope_where()` pattern đã dùng trong
`class-twinbrain-notebook-selector.php`) — plugin KHÔNG được mở rộng quyền
truy cập tri thức rộng hơn những gì KG Hub đã cấp cho user/tenant hiện tại chỉ
vì plugin "muốn thấy nhiều dữ liệu hơn để trả lời tốt hơn". Vi phạm ranh giới
này là lỗi bảo mật (OWASP A01), không phải tối ưu trải nghiệm.

## 6. Anti-pattern CẤM

- ❌ Plugin tự viết `class-my-plugin-brain-runtime.php` mô phỏng lại
  Draft→Reflection→Final Gate cho riêng nó.
- ❌ Plugin tự gọi `wp_remote_post()` tới OpenRouter/OpenAI thay vì
  `BizCity_LLM_Client` (vi phạm R-GW-8 ngay từ "vertical brain" đầu tiên).
- ❌ Plugin cache kết quả "thinking layer" của mình vào transient/table riêng
  rồi tự răn đe Final Composer dùng — evidence phải đi qua đúng
  Synthesis/Source Layer để được Reflection chấm điểm cùng nguồn khác.
- ❌ Plugin tạo "picker" vertical riêng ở FE ngoài `TwinVerticalPicker`/mode
  registry đã có (xem R-TWEB-12 trong `TWINBRAIN-EXT-VERTICAL-*` docs).

## 7. Tham chiếu

- [PHASE-0-RULE-MULTI-PERSPECTIVE.md](../rules/PHASE-0-RULE-MULTI-PERSPECTIVE.md)
- [PHASE-0-RULE-MPR-THINKING.md](../rules/PHASE-0-RULE-MPR-THINKING.md)
- [TWINBRAIN-EXT-VERTICAL-CHAT-DEFAULT-ROADMAP.md](../../core/twinbrain/docs/TWINBRAIN-EXT-VERTICAL-CHAT-DEFAULT-ROADMAP.md)
- [TWINBRAIN-EXT-VERTICAL-CONVERSATION-MODES.md](../../core/twinbrain/docs/TWINBRAIN-EXT-VERTICAL-CONVERSATION-MODES.md) (RFC — chưa ship `chat_memory`/`run_tools`, không dùng làm căn cứ runtime hiện tại)
- [core/twin-core/contracts/content-contracts.php](../../core/twin-core/contracts/content-contracts.php)
