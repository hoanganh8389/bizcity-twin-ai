# Mode Classification Log

| Field | Value |
|-------|-------|
| Timestamp | 2026-04-02 12:41:25 UTC |
| Trace ID | trace_bef9c0ff01f69eda |
| Site ID | 583 |
| User ID | 6 |
| Model | google/gemini-2.5-flash |

## 1. User Message

```
bạn có thể làm gì
```

## 2. Conversation Context

_No active conversation/goal._

## 3. Regex Pre-Match Result

_No regex match._

## 4. Focused Tool Schema (sent to LLM)

```
## GOALS & TOOLS (10 mục — hiển thị top 10/126, ★=likely)
1. analyze_sheet_data: Phân tích bảng tính — Đọc dữ liệu bảng tính, tóm tắt cấu trúc, gợi ý insight và cột số liệu | cần: sheet_data(text) | tùy chọn: analysis_goal(choice)
2. list_workflows: Xem danh sách workflows — Liệt kê workflows đã tạo | tùy chọn: limit(number)
3. rewrite_article: Viết lại bài viết — Viết lại / chỉnh sửa / biên tập nội dung một bài viết WordPress đã có | cần: post_id(text) | tùy chọn: instruction(text), tone(choice)
4. render_project: Gửi render video project qua Remotion renderer | cần: project_id(number)
5. publish_workflow: Publish một workflow draft đã tạo trước đó. | cần: task_id(number)
6. product_stats: Thống kê hàng hóa — Top sản phẩm bán chạy nhất, thống kê hàng hóa theo thời gian | tùy chọn: so_ngay(number)
7. post_facebook: Đăng bài Facebook — Đăng bài lên trang Facebook (nội dung, ảnh, link) | tùy chọn: message(text), image_url(url), content(text), title(text), url(url)
8. order_stats: Thống kê đơn hàng — Báo cáo doanh thu, thống kê đơn hàng theo ngày/tuần/tháng | tùy chọn: so_ngay(number), from_date(date), to_date(date)
9. list_projects: Liệt kê các video project đã tạo | tùy chọn: limit(number)
10. scheduler_cancel_event: Huy su kien lich — Danh dau mot su kien la cancelled de dung reminder va follow-up. | cần: event_ref(text)
```

## 5. Full LLM Prompt

<details>
<summary>Click to expand full prompt (7968 chars)</summary>

```
Bạn là AI Team Leader & Thư ký công việc cho hệ thống BizCity. Phân tích tin nhắn → trả JSON.

## BƯỚC 1 — XÁC ĐỊNH MODE (chọn 1):
1. emotion — THUẦN tâm sự, cảm xúc, than phiền, chia sẻ cảm giác. KHÔNG có yêu cầu hành động nào.
2. reflection — Kể chuyện, chia sẻ trải nghiệm, sự kiện đã xảy ra (hiếm)
3. knowledge — Hỏi đáp, nghiên cứu, phân tích, tư vấn, lập kế hoạch, viết code, brainstorm. Bất kỳ tin nhắn nào CÓ CHỦ ĐỀ CỤ THỂ mà KHÔNG map trực tiếp vào tool.
4. execution — THỰC THI hành động cụ thể bằng 1 trong các tool bên dưới. Phải match CHÍNH XÁC 1 tool.
5. ambiguous — CHỈ cho tin nhắn 1-3 từ MƠ HỒ thuần xã giao: "hi", "ok", "ờ", "hmm", "chào". KHÔNG dùng cho câu có nội dung.

PHÂN BỔ ƯU TIÊN (AI Team Leader style):
- ~60% knowledge: Câu hỏi, tư vấn, phân tích, brainstorm, lập kế hoạch, viết code, nghiên cứu → knowledge
- ~30% execution: Yêu cầu hành động RÕ RÀNG match tool cụ thể (tạo, xóa, gửi, bói, báo cáo, xem...) → execution
- ~10% emotion + ambiguous + reflection: Chỉ khi KHÔNG CÓ nội dung cụ thể hoặc THUẦN cảm xúc

QUAN TRỌNG:
- Tin nhắn có CHỦ ĐỀ (dù chưa phải câu hỏi rõ) → knowledge (KHÔNG phải ambiguous)
  VD: "marketing", "ý tưởng kinh doanh", "capacitor react" → knowledge
- Tin nhắn hỏi ý kiến, tư vấn, so sánh → knowledge (KHÔNG phải execution)
  VD: "nên dùng React hay Vue?", "tư vấn chiến lược Q2" → knowledge
- Tin nhắn yêu cầu LÀM gì đó + match tool → execution
  VD: "viết bài về marketing" (match tool-content) → execution
- CHỈ dùng ambiguous cho tin nhắn THẬT SỰ mơ hồ 1-3 từ xã giao thuần


🎯 CHẾ ĐỘ KCI: ƯU TIÊN KIẾN THỨC (Execution chỉ 20%)
- ƯU TIÊN MẠNH chọn knowledge. CHỈ chọn execution khi tin nhắn YÊU CẦU TRỰC TIẾP và TƯỜNG MINH thực thi tool.
- Khi mơ hồ giữa knowledge và execution → LUÔN chọn knowledge.

🔮 NHẬN DIỆN CÂU HỎI CHIÊM TINH & DỰ BÁO TƯƠNG LAI (ĐỘ NHẠY CAO — trust cao):
User có bản đồ sao cá nhân (natal chart) trong hệ thống. Khi user hỏi về TƯƠNG LAI, VẬN MỆNH, DỰ BÁO → mode=execution, goal="bizcoach_consult", confidence ≥ 0.85.
Các dấu hiệu (dù KHÔNG nói rõ "chiêm tinh"):
- Hỏi về ngày/tuần/tháng/năm sắp tới: "ngày mai thế nào", "tuần này ra sao", "tháng sau có gì"
- Hỏi vận mệnh/tương lai cá nhân: "tình hình tài chính tương lai", "sự nghiệp sắp tới", "tình cảm năm nay"
- Hỏi tình hình hiện tại kiểu dự báo: "hôm nay tôi thế nào", "hôm nay nên làm gì", "hôm nay có thuận lợi không"
- Hỏi trực tiếp: chiêm tinh, tử vi, bói, tarot, vận hạn, bản đồ sao, transit, natal
- Hỏi "tôi nên..." kèm thời gian tương lai hoặc chủ đề cá nhân (tài chính, tình cảm, sức khỏe, sự nghiệp)
→ Đây là DỰ BÁO CÁ NHÂN cần bản đồ sao → execution + bizcoach_consult.
⚠️ PHÂN BIỆT: "ngày mai thời tiết thế nào" = knowledge (tra cứu). "ngày mai tôi thế nào" = execution (dự báo cá nhân).

NẾU mode=knowledge → xác định knowledge_type (chọn 1):
- research: Nghiên cứu sâu, phân tích chi tiết, giải thích khái niệm, hướng dẫn kỹ thuật
- advisor: Tư vấn, đề xuất phương án, so sánh lựa chọn, lập kế hoạch, brainstorm
- lookup: Tra cứu ngắn, số liệu, sự kiện cụ thể, câu trả lời 1-2 dòng

NẾU mode=knowledge VÀ confidence 0.5-0.7 (gần ranh giới execution) → điền suggested_tool = tên tool gợi ý (nếu có).

## GOALS & TOOLS (10 mục — hiển thị top 10/126, ★=likely)
1. analyze_sheet_data: Phân tích bảng tính — Đọc dữ liệu bảng tính, tóm tắt cấu trúc, gợi ý insight và cột số liệu | cần: sheet_data(text) | tùy chọn: analysis_goal(choice)
2. list_workflows: Xem danh sách workflows — Liệt kê workflows đã tạo | tùy chọn: limit(number)
3. rewrite_article: Viết lại bài viết — Viết lại / chỉnh sửa / biên tập nội dung một bài viết WordPress đã có | cần: post_id(text) | tùy chọn: instruction(text), tone(choice)
4. render_project: Gửi render video project qua Remotion renderer | cần: project_id(number)
5. publish_workflow: Publish một workflow draft đã tạo trước đó. | cần: task_id(number)
6. product_stats: Thống kê hàng hóa — Top sản phẩm bán chạy nhất, thống kê hàng hóa theo thời gian | tùy chọn: so_ngay(number)
7. post_facebook: Đăng bài Facebook — Đăng bài lên trang Facebook (nội dung, ảnh, link) | tùy chọn: message(text), image_url(url), content(text), title(text), url(url)
8. order_stats: Thống kê đơn hàng — Báo cáo doanh thu, thống kê đơn hàng theo ngày/tuần/tháng | tùy chọn: so_ngay(number), from_date(date), to_date(date)
9. list_projects: Liệt kê các video project đã tạo | tùy chọn: limit(number)
10. scheduler_cancel_event: Huy su kien lich — Danh dau mot su kien la cancelled de dung reminder va follow-up. | cần: event_ref(text)

## BƯỚC 2 — NẾU mode=execution → XÁC ĐỊNH INTENT + GOAL + ENTITY EXTRACTION:

INTENTS:
- new_goal: Bắt đầu nhiệm vụ mới (map vào 1 goal cụ thể)
- provide_input: Trả lời/cung cấp thông tin cho goal đang chờ (WAITING_USER)
- continue_goal: Bổ sung thông tin cho goal đang active
- (Nếu mode KHÁC execution → intent="" goal="")

QUY TẮC:
1. Nếu goal có [khi nói: ...] → khi user dùng từ khóa đó → ưu tiên goal này
2. WAITING_USER + tin nhắn = câu trả lời → provide_input (giữ goal cũ)
3. WAITING_USER + yêu cầu MỚI khác hẳn → new_goal
4. confidence: 0.0-1.0, ≥ 0.8 khi chắc chắn
5. ★ marked goal = regex pre-matched → ưu tiên cao nếu ngữ cảnh phù hợp

## BƯỚC 2b — ENTITY/SLOT EXTRACTION (CHỈ khi mode=execution):
Dựa vào "cần" và "tùy chọn" của goal đã chọn (chú ý type: text, number, choice, image...):
- **entities**: chỉ chứa giá trị THỰC SỰ có trong tin nhắn. KHÔNG đoán, KHÔNG bịa.
  VD: "viết bài về marketing" → entities={"topic":"marketing"}
  VD: "tạo mindmap về ý tưởng kinh doanh sữa bột trên tiktok" → entities={"topic":"ý tưởng kinh doanh sữa bột trên tiktok"}
  VD: "đăng bài giúp mình" → entities={} (KHÔNG có topic)
- **filled_slots**: mảng tên field đã extract được giá trị thực.
- **missing_slots**: mảng tên field BẮT BUỘC (cần) mà CHƯA có giá trị.

QUAN TRỌNG:
- Câu lệnh/politeness KHÔNG PHẢI là slot (VD: "giúp mình nhé" ≠ topic)
- Chỉ fill slot khi tin nhắn THẬT SỰ chứa nội dung cụ thể cho slot đó
- Lấy TOÀN BỘ phần nội dung dài cho slot text (không cắt ngắn)

## BƯỚC 3 — MEMORY CHECK + BUILT-IN FUNCTION:
is_memory = true nếu yêu cầu ghi nhớ/thiết lập preference/quy tắc.
memory_type = loại memory (6 loại):
  - "save_fact": ghi nhớ thông tin cá nhân ("tên tôi là X", "tôi 30 tuổi")
  - "set_response_rule": quy tắc phản hồi ("trả lời ngắn gọn", "bạn cần viết chi tiết hơn")
  - "set_communication_style": xưng hô, ngôn ngữ, giọng ("xưng hô anh em", "nói tiếng Anh")
  - "pin_context": ghim ngữ cảnh liên tục ("tôi đang làm dự án ABC")
  - "set_output_format": định dạng output ("format dạng json", "luôn trả lời bằng bảng")
  - "set_focus_topic": chủ đề quan tâm ("từ giờ tập trung vào marketing")
built_in_function = hàm hệ thống cần gọi (nếu có):
  - "save_user_memory": lưu bất kỳ memory nào ở trên
  - "forget_memory": xoá/quên ("quên đi tên tôi", "xóa thông tin X")
  - "list_memories": xem lại ký ức ("bạn nhớ gì về tôi?")
  - "end_conversation": kết thúc hội thoại ("thôi", "tạm biệt", "bye")
  - "explain_last": giải thích câu trả lời trước ("tại sao bạn nói vậy?", "giải thích lại")
  - "summarize_session": tóm tắt phiên chat ("tóm tắt cuộc trò chuyện")
  - "": không phải built-in function
VD: "hãy xưng hô anh em" → is_memory=true, memory_type="set_communication_style", built_in_function="save_user_memory"
VD: "bạn nhớ gì về tôi?" → is_memory=false, memory_type="", built_in_function="list_memories"
VD: "tạm biệt" → is_memory=false, memory_type="", built_in_function="end_conversation"


LỊCH SỬ HỘI THOẠI GẦN ĐÂY (để hiểu ngữ cảnh tin nhắn hiện tại):
  • Chủ nhân: alo cu
  • AI: Chào bạn! Mình có thể giúp gì cho bạn hôm nay?

💡 **Gợi ý:** Bạn đang tìm kiếm thông tin về dịch vụ nào của BizGPT? | Bạn có mục tiêu cụ thể nào muốn 

Tin nhắn: "bạn có thể làm gì"

Trả lời CHÍNH XÁC 1 JSON, KHÔNG giải thích:
{"mode":"...","confidence":0.0,"is_memory":false,"memory_type":"","built_in_function":"","knowledge_type":"","suggested_tool":"","intent":"","goal":"","goal_label":"","entities":{},"filled_slots":[],"missing_slots":[]}
```

</details>

## 6. LLM Raw Response

```json
```json
{"mode":"knowledge","confidence":0.9,"is_memory":false,"memory_type":"","built_in_function":"","knowledge_type":"lookup","suggested_tool":"","intent":"","goal":"","goal_label":"","entities":{},"filled_slots":[],"missing_slots":[]}
```
```

## 7. LLM Parsed Result

```json
{
    "mode": "knowledge",
    "confidence": 0.90000000000000002220446049250313080847263336181640625,
    "is_memory": false,
    "memory_type": "",
    "built_in_function": "",
    "knowledge_type": "lookup",
    "suggested_tool": "",
    "intent": "",
    "goal": "",
    "goal_label": "",
    "entities": [],
    "filled_slots": [],
    "missing_slots": []
}
```

## 8. Final Classification Result

```json
{
    "mode": "knowledge",
    "confidence": 0.90000000000000002220446049250313080847263336181640625,
    "method": "llm",
    "is_memory": false,
    "meta": {
        "llm_tokens": {
            "prompt_tokens": 2667,
            "completion_tokens": 65,
            "total_tokens": 2732,
            "cost": 0.000962599999999999979098663782650646680849604308605194091796875,
            "is_byok": false,
            "prompt_tokens_details": {
                "cached_tokens": 0,
                "cache_write_tokens": 0,
                "audio_tokens": 0,
                "video_tokens": 0
            },
            "cost_details": {
                "upstream_inference_cost": 0.000962599999999999979098663782650646680849604308605194091796875,
                "upstream_inference_prompt_cost": 0.00080009999999999998655797472935091718682087957859039306640625,
                "upstream_inference_completions_cost": 0.000162499999999999992540689053299729494028724730014801025390625
            },
            "completion_tokens_details": {
                "reasoning_tokens": 0,
                "image_tokens": 0,
                "audio_tokens": 0
            }
        },
        "llm_model": "google\/gemini-2.5-flash",
        "knowledge_type": "lookup",
        "suggested_tool": "",
        "memory_type": "",
        "built_in_function": ""
    }
}
```
