# 11 — Scoreboard: Twin Framework Audit Format

> **Status:** 🟡 MỘT PHẦN — hạ tầng verdict JSON (`wp bizcity status`,
> `wp bizcity diagnostics`, R-DDV Disk/Loader/Runtime evidence) đã tồn tại.
> Việc còn thiếu là 1 lớp trình bày (presentation layer) tổng hợp CORE +
> từng PLUGIN thành đúng định dạng scoreboard mà user mô tả.

> **Cập nhật 2026-08-29:** Scoreboard không được dừng ở việc chứng minh
> framework/plugin "đã load". Thước đo thành công của Twin Data Center là
> **insight chính xác, có citation/link dẫn chứng và truy vết được tới dữ
> liệu gốc**. Contract chi tiết nằm ở §7; checklist triển khai tập trung ở
> [MASTER-CHECKLIST.md](MASTER-CHECKLIST.md).

---

## 1. Định dạng mục tiêu

```text
Twin Framework 1.2.0

CORE
✓ Bootstrap
✓ Database
✓ KG Hub
✓ LLM Gateway
✓ Memory
✓ Event Bus

PLUGIN: my-woo-insight
✓ manifest valid
✓ plugin loaded
✓ tool registered
✓ REST routes registered
✓ permissions valid
✓ diagnostics registered
✓ runtime probe passed

Result: 18 PASS / 0 FAIL
READY
```

## 2. Nguồn dữ liệu đã có cho từng dòng

### 2.1 Khối CORE

| Dòng | Nguồn dữ liệu |
|---|---|
| Bootstrap | `BizCity_Loader_Ownership_Registry` (đã claim/transition đúng state) |
| Database | Schema Inventory trong Diagnostics (`class-diagnostics-table-registry.php`) |
| KG Hub | Probe `core.knowledge.*` (đã có trong `core/diagnostics/includes/probes/`) |
| LLM Gateway | Probe entitlement/gateway (`class-probe-framework-production-contract.php` và tương đương) |
| Memory | Probe `core.memory.filestore_parity`/tương đương (xem PHASE-1.30 Sprint 3) |
| Event Bus | `bin/validate-event-stream.php` + probe R-EVT liên quan |

### 2.2 Khối PLUGIN

Mỗi dòng map 1-1 vào lint checklist đã mô tả ở
[10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md](10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md)
§2, cộng thêm dòng "runtime probe passed" lấy từ
`wp bizcity diagnostics plugin <slug> --json`.

## 3. Verdict contract dùng chung (không phát minh format mới)

`wp bizcity status` đã trả về đúng 1 JSON contract
(`"contract": "diagnostics-verdict", "version": "1"`) — Scoreboard chỉ là
**một renderer khác** đọc cùng contract này (giống cách `BizCity_Log_Explorer`
là renderer của `BizCity_Log_Contract_Registry`, không phải nguồn dữ liệu
mới). Cấu trúc đề xuất mở rộng:

```json
{
  "contract": "diagnostics-verdict",
  "version": "1",
  "framework_version": "1.2.0",
  "core": [
    { "id": "bootstrap", "label": "Bootstrap", "status": "pass" },
    { "id": "kg_hub", "label": "KG Hub", "status": "pass" }
  ],
  "plugins": [
    {
      "slug": "my-woo-insight",
      "checks": [
        { "id": "manifest", "status": "pass" },
        { "id": "tool_registered", "status": "pass" }
      ]
    }
  ],
  "summary": { "pass": 18, "warn": 0, "fail": 0 },
  "verdict": "READY"
}
```

`verdict` chỉ là `READY` khi `fail === 0` — `warn > 0` vẫn có thể `READY` tuỳ
policy (giống `--strict` flag đã có ở `diagnostics plugin`, nơi `--strict`
biến WARN thành FAIL).

## 4. Vì sao Scoreboard quan trọng cho vibe coding

Scoreboard là **bằng chứng khách quan cuối cùng** để AI coding agent (hoặc
con người) biết khi nào dừng vòng lặp sửa lỗi (xem
[10](10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md) §5). Không có Scoreboard, mỗi
agent tự quyết "tôi nghĩ là xong" — có Scoreboard, tiêu chí "xong" là 1 con số
duy nhất (`fail === 0`) không thể tranh cãi.

## 5. Anti-pattern CẤM

- ❌ Hard-code danh sách CORE checks trong 1 file cấu hình tĩnh tách rời khỏi
  Diagnostics thật — Scoreboard phải luôn đọc probe registry thật, không được
  là 1 danh sách "để đẹp" không phản ánh runtime.
- ❌ Đánh dấu `READY` khi có bất kỳ dòng FAIL nào, bất kể "chỉ là lỗi nhỏ".
- ❌ Tạo 1 Diagnostics engine thứ hai riêng cho Scoreboard — chỉ được là
  renderer trên cùng 1 nguồn `wp bizcity status`/`diagnostics`.

## 6. Tham chiếu

- `core/cli/class-bizcity-framework-cli.php` (`status`, `diagnostics`)
- [FRAMEWORK-GUIDE-v1.md §10](../framework/FRAMEWORK-GUIDE-v1.md) (Diagnostics và Definition of Done)
- [PHASE-1.28-RUNTIME-READINESS-CLOSURE.md](../roadmaps/PHASE-1.28-RUNTIME-READINESS-CLOSURE.md) (verdict contract gốc)

## 7. Insight Accuracy + Data Lineage Contract

### 7.1 Thước đo thành công

Một insight chỉ được tính là **đạt** khi có đủ cả bốn lớp:

```text
accuracy
  + evidence/citation
  + data lineage
  + reproducibility
  = valid Twin Data Center insight
```

| Lớp | Câu hỏi phải trả lời | Bằng chứng tối thiểu |
|---|---|---|
| **Accuracy** | Insight có đúng với dữ liệu đã được kiểm chứng không? | Claim, phương pháp/tập dữ liệu, thời điểm tính, confidence và kết quả validation |
| **Citation** | Người đọc có mở được dẫn chứng không? | Citation ID ổn định + link nội bộ hoặc URL được phép + đoạn/bản ghi tham chiếu |
| **Lineage** | Insight đến từ nguồn nào, qua bước nào? | `source_id`, adapter/plugin, event/trace ID, transformation/query và thời gian quan sát |
| **Storage trace** | Dữ liệu gốc đang lưu ở đâu? | Contract/filestore hoặc bảng structural, relative file, JSONL event/pointer, database/blog/shard |
| **Business reference** | Insight nói về đối tượng nào? | `record_id`, `order_id`, `contact_id`, `customer_id`, `post_id`, CPT/post type hoặc entity ID; chỉ ghi field được phép |
| **Reproducibility** | Có thể chạy lại và ra cùng kết quả trong cùng snapshot không? | Dataset/snapshot version, `computed_at`, timezone UTC, code/contract version và input hash |

Thiếu **một** lớp không được gắn nhãn `accurate` hoặc `READY`; dùng
`WARN`, `DEGRADED` hoặc `UNVERIFIED` với reason bucket cụ thể. Citation đơn
thuần không chứng minh accuracy; một link đẹp nhưng không trỏ được về bản ghi
và thời điểm tính là **không đủ**.

### 7.2 Insight Evidence Envelope

Mọi service tạo insight (plugin, CRM report, MCP analytics hoặc Twin Data
Center Overview) phải trả về một envelope có cấu trúc tương thích sau. Đây là
contract nội bộ để audit/trace, không phải nội dung chain-of-thought:

```json
{
  "insight_id": "ins_01J...",
  "title": "Tỷ lệ phản hồi tự động giảm trong tuần này",
  "claim": "Tỷ lệ ...",
  "status": "verified",
  "confidence": 0.93,
  "computed_at": "2026-08-29T09:30:00Z",
  "window": { "from": "2026-08-22T00:00:00Z", "to": "2026-08-29T00:00:00Z", "timezone": "Asia/Ho_Chi_Minh" },
  "method": { "name": "crm_response_rate", "version": "1.0.0", "input_hash": "hmac-sha256:..." },
  "citations": [
    { "citation_id": "cit_01", "label": "CRM response events", "link": "/wp-admin/...", "source_id": "crm.reporting_events" }
  ],
  "lineage": [
    { "source_id": "crm.reporting_events", "plugin": "bizcity-twin-crm", "trace_id": "tr_...", "event_ids": ["evt_..."], "observed_at": "2026-08-29T09:29:58Z" }
  ],
  "storage": [
    { "kind": "sql", "contract_id": "crm.reporting_events", "blog_id": 1258, "table": "wp_{blog_id}_bizcity_crm_reporting_events", "shard": "verified" },
    { "kind": "jsonl", "contract_id": "core.channel_gateway.messenger", "relative_file": "2026-08-29.jsonl", "event_uuid": "evt_...", "byte_offset": 1234, "index_id": 91 }
  ],
  "references": [
    { "type": "order", "id": "order_..." },
    { "type": "cpt", "post_type": "product", "post_id": 42 }
  ],
  "limitations": ["Không suy ra nguyên nhân nhân quả từ dữ liệu quan sát"]
}
```

Các quy tắc bảo mật bắt buộc:

- `link` phải là link dẫn chứng có quyền truy cập phù hợp, không đưa secret,
  token, PII không cần thiết hoặc raw SQL vào response.
- `table`/`relative_file` là locator đã được redacted/allowlist; không log
  absolute path. Tên bảng tenant phải được resolve sau đúng blog/shard context.
- `references` chỉ giữ ID/loại entity cần cho audit; nội dung khách hàng nhạy
  cảm phải dùng hash/HMAC hoặc reference tới CRM owner, không copy vào insight.
- `bizcity_log_index` chỉ là pointer ledger tới JSONL; không coi index row là
  dữ liệu nguồn hay bằng chứng thay thế cho file gốc.
- `status=verified` chỉ được trả sau khi citation và lineage resolver kiểm tra
  được nguồn; nếu nguồn mất, pointer lệch hash hoặc snapshot không còn thì
  hạ xuống `unverified`/`degraded`.

### 7.3 Scoreboard checks cho insight

Khi một plugin hoặc Data Center service khai báo capability tạo insight,
Scoreboard phải có các dòng sau:

```text
✓ insight contract declared
✓ claim has citation
✓ citation link resolves
✓ source/plugin identified
✓ timestamp and analysis window present
✓ event/trace lineage present
✓ storage locator verified (SQL/JSONL/CPT)
✓ business record references resolved
✓ tenant/blog/shard scope verified
✓ result reproducible from declared snapshot
```

Không dùng `runtime probe passed` để thay cho các dòng trên. Một plugin có
Tool chạy được nhưng không trả provenance chỉ đạt `tool_ready`, chưa đạt
`insight_ready`.

### 7.4 Phân biệt citation và truy vết dữ liệu

```text
citation link       → người dùng mở được bằng chứng để đọc
source_id           → xác định adapter/plugin sở hữu nguồn
event_id/trace_id   → biết dòng chảy đã tạo hoặc quan sát dữ liệu
record/CPT ID       → biết đối tượng nghiệp vụ cụ thể
filestore/table     → biết dữ liệu vật lý đang nằm ở đâu
computed_at/window  → biết insight đúng cho thời điểm/phạm vi nào
```

Insight chỉ có citation nhưng thiếu `record_id`/`event_id`/storage locator là
**có dẫn nguồn nhưng chưa có lineage**. Insight có lineage nhưng không có link
hoặc citation là **có thể audit nội bộ nhưng chưa đủ cho người dùng kiểm chứng**.

### 7.5 Metric đề xuất cho Twin Data Center

Scoreboard tổng hợp nên báo cáo tối thiểu:

```text
insight_accuracy_rate       = verified insights / evaluated insights
citation_coverage           = claims with resolvable citation / factual claims
lineage_completeness        = insights with full source→event→storage trace / insights
record_resolution_rate      = resolved business references / referenced records
reproducibility_rate        = reruns matching snapshot result / reruns
unverified_insight_rate     = unverified or degraded insights / total insights
```

Các tỷ lệ phải kèm `evaluation_window`, `dataset_snapshot` và số mẫu tử/mẫu
số. Không báo một phần trăm không có mẫu, thời gian hoặc định nghĩa denominator.
