# Async Ingest — Error Code Propagation & Retry UX (Design)

> Ngày: 2026-07-23 · Author: Johnny Chu (design doc)
> Scope: `core/knowledge/kg-hub` (async scoped ingest) + `modules/twinchat` (Learning Log UI)
> Trigger: audit lỗi `gemini_quota_exhausted` trên blog 1416, user hỏi "design nút retry để trace/log/fix dễ hơn"
> Trạng thái: **IMPLEMENTED 2026-07-23** — xem §7 Changelog

---

## 1. Kết luận quan trọng nhất (đọc trước khi code bất cứ gì)

**Nút Retry + hệ thống trace/log cho async ingest ĐÃ ĐƯỢC XÂY DỰNG SẴN**, dưới các
stamp `HOTFIX async-retry`, `HOTFIX async-watchdog`, `PHASE-0.44 Learning Log
Daily Analytics`, `HOTFIX learning-log-json-export`, `HOTFIX learning-log-parity`
(2026-07-23 → 2026-07-27). Đây **không phải feature cần thiết kế từ đầu** — chỉ có
**1 khoảng hở cụ thể** trong luồng propagate error code, mô tả ở §3.

Nếu không đọc phần này trước, rất dễ code trùng lặp/method mới đè lên hệ thống đã có.

---

## 2. Hiện trạng đầy đủ (đã audit code, không phải suy đoán)

### 2.1 Backend — async ingest lifecycle

File chính: [class-kg-scoped-rest-controller.php](../includes/class-kg-scoped-rest-controller.php)

| Cơ chế | Hàm | Vị trí | Mô tả |
|---|---|---|---|
| Enqueue | `enqueue_async_file_ingest()` | ~L295 | Tạo placeholder source, stage file, `as_enqueue_async_action()` (Action Scheduler) + `wp_schedule_single_event()` fallback |
| Worker | `run_async_ingest()` | ~L765 | Chạy `BizCity_KG::ingest()`, cập nhật `async_state` qua từng bước (`running→materializing→persisting→done`) |
| Auto-retry | `schedule_async_retry()` | ~L595 | Tăng `async_attempt`, requeue `wp_schedule_single_event(+60s)`. Dừng khi `attempt >= ASYNC_MAX_ATTEMPTS` (=3) |
| Terminal fail | `mark_async_placeholder_failed()` | ~L541 | Set `embedding_status='error'`, ghi `error_message` (string, đã strip tag, cắt 500 ký tự) vào bảng `bizcity_twinchat_sources` + set `kg_sources.status='error'` |
| Watchdog | `watchdog()` | ~L610 | Cron 5 phút, quét source `embedding_status='processing'` bị "mồ côi" (heartbeat quá `ASYNC_STALE_AFTER`=10 phút) → retry tiếp hoặc mark failed nếu hết file gốc/hết attempt |
| **Manual retry** | `retry_async_source()` | ~L668 | **API 1-click retry** — reset `async_attempt=0`, `async_state='queued'`, requeue lại từ file đã stage. Fail closed rõ ràng (`retry_unsupported` nếu không phải async upload, `async_file_missing` HTTP 410 nếu file gốc đã bị xoá sau khi hết attempt) |
| File-log evidence | `async_log()` | trong cùng file | Mọi lifecycle event (`start`, `wp_error`, `done`, `queued_action_scheduler`, `watchdog_retry`, `placeholder_error`...) được ghi kèm `code` (error code!) vào canonical log `bizcity_tc_learning_debug_log()` — đúng file `uploads/sites/{blog_id}/bizcity_learning_logs/YYYY-MM-DD.log` mà bạn đang xem |

### 2.2 REST endpoint đã có

- `GET bizcity-twinchat/v1/.../learning-log` → trả `status`, `progress`, `error_message`, `retryable`, `counts`, `phases`, `chunks`, `events`, `raw_log_hint` (class-twinchat-rest-controller.php ~L1290-1400).
- `POST .../retry` → `retry_source_ingest()` (class-twinchat-rest-controller.php ~L1400) gọi thẳng `BizCity_KG_Scoped_REST_Controller::retry_async_source()`.

### 2.3 Frontend đã có

File: [SourceLearningLogPanel.tsx](../../../../modules/twinchat/ui/src/components/SourceLearningLogPanel.tsx)

- Drawer chi tiết per-source: header (status badge, % progress bar), banner đỏ khi `status==='failed'` với nút **"Thử lại"** (`retryMutation` → `api.retrySourceIngest()`), 3 section Phases / Chunks / Timeline, nút **"Tải JSON"** để export toàn bộ learning log (debug offline không cần DB access).
- [SmartSourcesPanel.tsx](../../../../modules/twinchat/ui/src/components/SmartSourcesPanel.tsx): mỗi source row có `StepDots` (3 chấm màu: Tải file / Embedding / Học KG — đỏ khi `extraction_status==='error'`), click mở đúng drawer `SourceLearningLogPanel` này (comment "same drawer as screenshot 5").
- [humanizeIngestError.ts](../../../../modules/twinchat/ui/src/utils/humanizeIngestError.ts): **đã có handling riêng cho `gemini_quota_exhausted`** (L153) — parse `ctx.master_level`, `ctx.used_requests`, `ctx.cap_requests_day`, `ctx.reset_at` từ payload Hub, hiển thị tiêu đề "API key Hub đã hết quota đọc tài liệu" + CTA **"Quản lý gói và API key"** (không phải "Thử lại" mù quáng) — kèm gợi ý chính xác: *"Nếu giao diện đang hiển thị Master Premium nhưng Hub trả Master Pro, hãy đồng bộ lại master_level của chính Bearer key"* (đúng root-cause bug đã fix trong phiên trước).

**→ Tức là: phần "hiểu lỗi thông minh" (humanized message + CTA đúng) đã được viết, nhưng CHỈ dùng cho luồng ingest đồng bộ** (lỗi trả về ngay lúc submit form, ví dụ `TwinChatAddSourceDialog.tsx`). **Luồng async (nút Retry trong drawer) KHÔNG dùng lại hàm này.**

---

## 3. Gap cụ thể — error code bị thất lạc giữa file-log và UI

### 3.1 Bằng chứng (đã trace code, không suy đoán)

Tại `run_async_ingest()` (class-kg-scoped-rest-controller.php ~L812):

```php
if ( is_wp_error( $res ) ) {
    $retry_file = self::schedule_async_retry( $job, $source_id, $res->get_error_message() );  // ← chỉ truyền MESSAGE
    if ( ! $retry_file ) {
        self::mark_async_placeholder_failed( $payload, $res->get_error_message() );            // ← chỉ truyền MESSAGE
    }
    self::async_log( 'wp_error', array(
        ...
        'code' => $res->get_error_code(),   // ← CODE có ở đây, nhưng CHỈ ghi vào file log
    ) );
    return;
}
```

`mark_async_placeholder_failed( array $payload, string $message )` (~L541) chỉ nhận
`string $message` — không có tham số code — nên chỉ ghi được:

```php
$source_db->update_source( $source_id, array(
    'embedding_status' => 'error',
    'error_message'    => $error,   // "Gemini fallback: API key đã dùng hết quota Hub trong kỳ hiện tại."
    'metadata'         => $source_meta,  // KHÔNG có async_error_code
) );
```

REST `get_source_learning_log()` (class-twinchat-rest-controller.php ~L1330) đọc lại
đúng cột này:

```php
$error_message = isset( $legacy_row['error_message'] ) ? (string) $legacy_row['error_message'] : '';
// KHÔNG có $error_code tương ứng
```

FE `SourceLearningLogPanel.tsx` (~L200) render thẳng string này, KHÔNG gọi
`humanizeIngestError()`:

```tsx
<p className="text-[11.5px] text-red-700 leading-5">
  {data.error_message || 'Xu ly nguon nay da that bai sau nhieu lan thu.'}
</p>
...
{data.retryable ? (
  <button onClick={() => retryMutation.mutate()}>Thu lai</button>  // ← LUÔN hiện, bất kể lý do lỗi
) : ( ... )}
```

### 3.2 Hệ quả thực tế (đúng case bạn đang gặp)

1. User thấy message thô "API key đã dùng hết quota Hub trong kỳ hiện tại" — không biết đây là quota **theo tháng** (30 ngày rolling, per R-LLM-KEY-ONLY audit) hay **theo ngày** hay do **bug đồng bộ master_level** (case đã fix hôm nay).
2. Nút "Thử lại" luôn hiện — nếu quota thật sự chưa hết hạn 30 ngày, bấm Retry **chắc chắn fail lại y hệt** → tốn thời gian, gây cảm giác "sao sửa hoài không được", giống hệt log 12:18 → 15:38 lặp lại trong file log gốc.
3. `humanizeIngestError.ts` đã viết sẵn logic đúng (hiện `used/cap/reset_at`, CTA "Quản lý gói và API key") nhưng **không được tái sử dụng** ở màn hình này — 2 chỗ code hiển thị 2 UX khác nhau cho CÙNG 1 loại lỗi.
4. Muốn debug sâu hơn phải mở "Tải JSON" rồi tự đọc `ctx.used_requests`/`ctx.cap_usd` thay vì thấy ngay trên UI.

---

## 4. Thiết kế đề xuất (surgical fix, không đổi kiến trúc)

### 4.1 Nguyên tắc

- **Không tạo bảng/cột DB mới** — `bizcity_twinchat_sources.metadata` đã là cột JSON,
  chỉ cần thêm 1 key mới `async_error_code` bên trong, giống cách `async_error`
  (message) đã làm ở `schedule_async_retry()`. → **Không cần R-DCL/schema migration.**
- Tái sử dụng nguyên vẹn `humanizeIngestError()` đã có, không viết lại logic quota.
- Giữ nguyên nút "Thử lại" khi hợp lý, nhưng thêm ngữ cảnh trước khi user bấm.

### 4.2 Backend — thread `$error_code` xuyên suốt 3 điểm

**a) `run_async_ingest()`** — truyền thêm code khi gọi 2 hàm terminal:

```php
if ( is_wp_error( $res ) ) {
    $err_code   = $res->get_error_code();                 // NEW
    $retry_file = self::schedule_async_retry( $job, $source_id, $res->get_error_message(), $err_code ); // NEW param
    if ( ! $retry_file ) {
        self::mark_async_placeholder_failed( $payload, $res->get_error_message(), $err_code );          // NEW param
    }
    ...
}
```

**b) `mark_async_placeholder_failed( array $payload, string $message, string $error_code = '' )`**
— thêm 1 dòng ghi vào metadata JSON đã có sẵn:

```php
$source_meta['async_state']      = 'error';
$source_meta['async_error_at']   = time();
$source_meta['async_error_code'] = sanitize_key( $error_code ); // NEW — không cần cột DB mới
```

**c) `schedule_async_retry( array $job, $source_id, $message, string $error_code = '' )`**
— tương tự, thêm `$meta['async_error_code'] = sanitize_key( $error_code )` cạnh
`$meta['async_error']` đã có.

**d) `watchdog()`** (~L633) — 2 nhánh gọi `mark_async_placeholder_failed()` với message
cứng ("File xử lý nền đã thất lạc.", "Đã vượt quá số lần thử xử lý file.") nên set
`$error_code` tương ứng: `'async_file_lost'`, `'async_max_attempts_exceeded'` — để FE
phân biệt được "lỗi hạ tầng" (không liên quan quota) khỏi lỗi nghiệp vụ thật.

### 4.3 REST — expose `error_code` song song `error_message`

`class-twinchat-rest-controller.php::get_source_learning_log()` (~L1330-1384):

```php
$error_message = isset( $legacy_row['error_message'] ) ? (string) $legacy_row['error_message'] : '';
$error_code    = '';                                                        // NEW
if ( is_array( $legacy_meta ) && ! empty( $legacy_meta['async_error_code'] ) ) {
    $error_code = sanitize_key( (string) $legacy_meta['async_error_code'] ); // NEW
}
...
return rest_ensure_response( [ ... , 'error_message' => $error_message, 'error_code' => $error_code, ... ] );
```

### 4.4 Frontend — tái dùng `humanizeIngestError()` trong banner lỗi

`SourceLearningLogPanel.tsx` — thay banner thô bằng humanized:

```tsx
import { humanizeIngestError } from '../utils/humanizeIngestError'

// trong render, khi data.status === 'failed':
const humanized = humanizeIngestError({ code: data.error_code, message: data.error_message, status: 0 })

<div className="mt-2 rounded-lg border ... ">
  <p className="font-semibold text-red-800">{humanized.title}</p>
  <p className="text-[11.5px] text-red-700 leading-5 mt-1">{humanized.body}</p>
  {retryError && <p className="mt-1 text-[11.5px] text-red-700">{retryError}</p>}
  <div className="mt-2 flex items-center gap-2">
    {data.retryable && (
      <button onClick={() => retryMutation.mutate()} disabled={retryMutation.isPending}>
        {retryMutation.isPending ? 'Đang thử lại…' : 'Thử lại'}
      </button>
    )}
    {humanized.cta?.intent === 'open_upgrade_modal' && (
      <button onClick={openUpgradeModal} className="...">{humanized.cta.label}</button>
    )}
  </div>
</div>
```

Kết quả: quota-type error hiện CẢ HAI — "Thử lại" (phòng trường hợp là bug đồng bộ
key, retry sẽ ăn ngay) VÀ "Quản lý gói và API key" (nếu quota thật sự chưa reset) —
thay vì chỉ có 1 nút mù quáng.

### 4.5 Việc KHÔNG làm (tránh over-engineer)

- Không đổi `ASYNC_MAX_ATTEMPTS`/watchdog interval.
- Không thêm cột DB mới (dùng metadata JSON sẵn có).
- Không viết lại `humanizeIngestError.ts` — chỉ import và gọi lại.
- Không đổi hành vi auto-retry (background), chỉ cải thiện UX khi đã ở trạng thái
  `failed` cuối cùng.

---

## 5. Checklist implement (khi được duyệt)

- [ ] `mark_async_placeholder_failed()` + `schedule_async_retry()` + 2 call site trong
      `watchdog()` + 1 call site trong `run_async_ingest()` — thêm tham số `$error_code`.
- [ ] REST `get_source_learning_log()` — thêm field `error_code` vào response + TS type
      `SourceLearningLog` trong `api/client.ts`.
- [ ] `SourceLearningLogPanel.tsx` — import `humanizeIngestError`, thay banner thô,
      thêm nút CTA thứ 2 khi có `cta.intent==='open_upgrade_modal'`.
- [ ] R-STAMP: `// [2026-07-23 Johnny Chu] PHASE-0.47-ASYNC-ERROR-CODE — ...` tại mỗi
      điểm sửa (theo R-STAMP convention của repo).
- [ ] Không cần R-DCL (không đổi schema) — chỉ cần xác nhận qua Diagnostics/manual
      test: upload 1 file để cố tình trip `gemini_quota_exhausted`, xem banner mới.

## 6. Việc chưa quyết định — cần bạn xác nhận trước khi code

Đã xác nhận (2026-07-23):
1. ✅ Thêm CTA "Quản lý gói và API key" — dùng lại `useEntitlementStore.getState().openUpgrade()` (global store, cùng cơ chế `TwinChatAddSourceDialog.tsx` đã dùng cho luồng đồng bộ) thay vì tạo modal riêng.
2. ✅ Tên code `async_file_lost` / `async_max_attempts_exceeded` không trùng convention nào khác trong repo — giữ nguyên.
3. ✅ Cần retro-fix source đã failed trước đó — dùng heuristic đọc lúc REST trả response (`infer_legacy_async_error_code()`, pattern-match message cũ → code), **không viết migration/backfill script** vì message cũ đủ đặc trưng để suy ngược chính xác.

## 7. Changelog — đã implement (2026-07-23)

| File | Thay đổi |
|---|---|
| [class-kg-scoped-rest-controller.php](../includes/class-kg-scoped-rest-controller.php) | `mark_async_placeholder_failed()` + `schedule_async_retry()` thêm param `$error_code`, lưu vào `metadata.async_error_code` (không cột DB mới). `run_async_ingest()` truyền `$res->get_error_code()`. `watchdog()` truyền `async_file_lost`/`async_max_attempts_exceeded`. 2 nhánh schedule-fail sớm truyền `async_ingest_schedule_failed`. `catch(Throwable)` truyền `async_ingest_exception`. |
| [class-twinchat-rest-controller.php](../../../modules/twinchat/includes/class-twinchat-rest-controller.php) | `get_source_learning_log()` đọc `metadata.async_error_code`, fallback `infer_legacy_async_error_code()` (retro-fix heuristic theo message text) khi thiếu; thêm field `error_code` vào response. Helper mới `infer_legacy_async_error_code()` đặt cạnh `retry_source_ingest()`. |
| [client.ts](../../../modules/twinchat/ui/src/api/client.ts) | `SourceLearningLog` type thêm field `error_code?: string`. |
| [SourceLearningLogPanel.tsx](../../../modules/twinchat/ui/src/components/SourceLearningLogPanel.tsx) | Banner lỗi async giờ gọi `humanizeIngestError({code, message})` thay vì hiện text thô; giữ nút "Thử lại" khi `retryable`, thêm nút CTA thứ 2 (vd "Quản lý gói và API key") khi `humanized.cta.intent==='open_upgrade_modal'`, mở qua `useEntitlementStore.getState().openUpgrade()`. |

Không cần R-DCL/schema migration (toàn bộ dùng cột `metadata` JSON đã có sẵn).
Chưa chạy test thực tế trên site khách (cần 1 file trip `gemini_quota_exhausted` thật để xác nhận banner mới hiển thị đúng).

## 8. Bug liên quan phát hiện thêm (2026-07-23) — main list % SAI, không phải thiếu nút Retry

User báo: 2 source đã xử lý xong (Learning Log xác nhận `done`, 368/368 và 178/178
chunks, không có `error`) nhưng **main list vẫn hiện 60%/51%**, và không thấy nút
Retry. Kết luận: **đây không phải thiếu nút Retry** — Retry chỉ hiện khi
`status==='failed'`, và các source này KHÔNG failed (Learning Log đã xác nhận),
nên đúng ra không cần Retry. Vấn đề thật là **% ở main list bị tính sai**.

---

## 9. Addendum 2026-07-26 — Upload-link file accepted but async ingest fails `no_file`

### 9.1 Incident summary

Observed in channel notebook bridge flow (Zalo upload-link):

- User uploads `.docx` successfully (file appears in WP Media Library).
- Session finalize returns accepted/queued confirmation to Zalo.
- Learning Log for that source later ends at `failed` with
  `error_code=no_file`, `error_message="Attachment file not found"`.
- Public learning-share link mirrors the same zero-progress failed snapshot.

### 9.2 Why this is a different failure class than §1-§8

The async retry/UX system uses a durable async file (`metadata.async_file`)
before queueing. When a job fails, retry and watchdog resume rehydrate from
that staged file rather than trusting a scheduler trigger or the attachment
ID alone.

Notebook Bridge file captures now have a stricter R2/CDN guarantee: when
`force_remote_evidence=1`, enqueue downloads the evidence URL from R2/CDN,
copies it into the blog-local `bizcity-async-ingest/` staging directory, and
the worker reads that staged copy. Direct TwinChat multipart uploads are a
separate path; they stage the received upload locally and do not promise that
the original media attachment is R2-backed.

### 9.3 Legacy scoped uploads — bounded automatic resume

The scoped upload watchdog also scans legacy rows whose `embedding_status` is
already `error`. When `metadata.async_file` still resolves to a readable staged
file, it resets the transient attempt counter and schedules one automatic
resume (`async_auto_resume_count` is capped at 1). This recovers uploads that
were marked failed by the old scheduler/path-cleanup behavior without creating
an infinite retry loop for quota or content errors. Rows without the staged
file, or rows that already consumed the auto-resume pass, remain available for
manual retry or explicit re-upload.

**Bằng chứng code (trích ngắn, full trace + line-by-line ở
[TRACE-NOTEBOOK-CAPTURE-SESSION-FINALIZE-SILENT-DROP.md §13](TRACE-NOTEBOOK-CAPTURE-SESSION-FINALIZE-SILENT-DROP.md)):**

Notebook bridge payload bắt đầu với `attachment_id`, sau đó enqueue stage bản
evidence từ R2/CDN và persist anchor `metadata.async_file`:

```php
// class-kg-channel-notebook-bridge.php::build_ingest_payload()
return array(
    'type'          => 'file',
    'title'         => $title,
    'attachment_id' => $attach_id,
    'metadata'      => array_merge( $source_meta, array(
        'provider_url' => $url,
        'file_name'    => (string) ( $attachment['file_name'] ?? $attachment['name'] ?? '' ),
        // KHÔNG có 'async_file' ở đây.
    ) ),
);
```

Bridge enqueue tương đương scoped REST controller: stage file trước khi queue,
delay cron 5 giây, và worker hydrate từ anchor:

```php
// class-kg-channel-notebook-bridge.php::stage_bridge_async_attachment()
$source_path = download_url( $evidence_url, 30 ); // force_remote_evidence=1
$path = trailingslashit( $dir ) . $job_id . '-' . $name;
if ( is_wp_error( $source_path ) || ! @copy( $source_path, $path ) ) {
    return new WP_Error( 'notebook_bridge_async_file_stage_failed', '...', array( 'status' => 500 ) );
}
$payload['metadata']['async_file'] = basename( $path ); // anchor worker/retry/watchdog
```

Worker materialize hydrate staged file first. The bridge watchdog scans failed
or stale placeholders, verifies the staged file, and schedules at most one
automatic resume (`async_auto_resume_count <= 1`). Missing staging is logged as
`notebook_bridge_async_file_missing`; it never deletes a file after retry
exhaustion.

### 9.4 Implemented backend contract

1. At enqueue time, R2/CDN-backed bridge captures are downloaded and copied into
  `uploads/.../bizcity-async-ingest/` with a bridge job ID.
2. Placeholder metadata stores `async_file`, original name/type/size,
  `async_state`, `async_ingest=true`, and the job/source IDs.
3. Worker and watchdog read the staged file first; `attachment_id` is identity
  and provenance, not the durable worker file reference.
4. Retry and watchdog may resume only while the staged file exists. A missing
  staged file is reported as `async_file_missing`/`async_file_lost`, not as
  proof that retry exhaustion deleted the original.
5. Cleanup occurs only after successful ingest or deduplication. Failed
  scheduling, parser, quota, and worker paths retain the staged file.
6. Bridge lifecycle events are mirrored to
  `uploads/sites/{blog_id}/bizcity_learning_logs/YYYY-MM-DD.log` and to the
  bridge JSONL logger.

### 9.5 Sprint action (UI)

Upload-link page already supports baseline drag-drop + multi-file. Next sprint
for this surface should improve user confidence and diagnosability:

1. File chips with remove action before submit.
2. Per-file validation badges (type/size) before submit.
3. Per-file upload progress and mounted result (`session`, `pending_fallback`).
4. Post-submit summary links each accepted file to its eventual source row/state
  (queued, processing, failed).

This addendum should be treated as the channel-bridge parity extension of the
async architecture already documented above.

### Root cause

`class-twinchat-rest-controller.php::_list_kg_sources()` (đường mặc định khi
`bizcity_kg_unified_read_enabled=true`, default) và bản legacy fallback trong
`list_sources()` đều tính `extraction_total`/`extraction_done` bằng SQL:

```php
"SELECT source_id, COUNT(*) total_chunks, SUM(...) done_chunks, ...
 FROM {$tbl_passages}
 WHERE source_id IN ({$placeholders})   // ← THIẾU notebook_id!
 GROUP BY source_id"
```

Trong khi `get_source_learning_log()` (endpoint đứng sau drawer Learning Log)
luôn có `WHERE notebook_id = %d AND source_id IN (...)`. Vì `kg_passages.source_id`
(đặc biệt khi là `origin_id` — id bảng legacy `bizcity_webchat_sources`, autoincrement
theo BLOG chứ không theo notebook) **không unique toàn cục giữa các notebook**,
thiếu filter `notebook_id` khiến passage của MỘT NOTEBOOK KHÁC (tình cờ trùng số
id) bị cộng nhầm vào `total`/`done` của source đang xem → tổng bị pha loãng sai
lệch → % hiển thị THẤP hơn thực tế dù nguồn đã xong 100%.

### Fix đã áp dụng

Thêm `notebook_id = %d AND` vào cả 2 câu SQL (`_list_kg_sources()` và
`list_sources()` legacy fallback) trong
[class-twinchat-rest-controller.php](../../../modules/twinchat/includes/class-twinchat-rest-controller.php),
đúng pattern đã dùng ở `get_source_learning_log()`. Không đổi schema, không đổi
FE — chỉ sửa 2 câu SQL thiếu điều kiện lọc.

**Không cần thêm gì cho nút Retry** ở case này — sau khi % hiển thị đúng lại
(≈100%), banner lỗi/nút Retry sẽ tự động không hiện vì source thực sự không
có lỗi.

## 9. Xác nhận fix + 2 phát hiện mới (2026-07-24)

### 9.1 Xác nhận fix 2026-07-23 đã hoạt động đúng

Retest thực tế trên đúng `job_id=9ceec536-5c84-4011-a554-ac4a30328b09`
(blog 1416, notebook/scope 10, source_id=66, kg_source_id=73) — Learning Log
JSON trả về:

```json
{ "status": "active", "embedding_status": "ready", "chunk_count": 171,
  "error_message": "", "metadata": { "async_state": "done", ... } }
```

Ingest chạy trọn vẹn, không trip `gemini_quota_exhausted` lần này → xác nhận
fix master_level/quota-gate 2026-07-23 đứng vững cho case tương tự.

### 9.2 Phát hiện mới #1 — 3 hệ thống quota KHÁC NHAU, đừng gộp khi debug

Log `bps_php_error.log` cùng job cho thấy: **ingest chính (chunk hoá file)
thành công** (`async scoped ingest done`, chunk_count=171) nhưng 2 bước
downstream SAU ingest lại fail vì **một cơ chế quota HOÀN TOÀN KHÁC**:

```
[twinchat-welcome] job#44 failed: LLM call failed: Đã dùng 11463/0 lượt
  request và đạt trần chi phí $10.09/$10.00 hôm nay.
[bizcity-kg-skeleton] single_pass llm_error ... build returned null reason=llm_failed
```

Đây là **daily cost cap theo `key_id`** (`class-router-rest.php`, hàm check
trước mỗi lượt chat/stream — message format
`sprintf('Đã dùng %d/%d lượt request và đạt trần chi phí $%.2f/$%.2f hôm nay.', ...)`),
**KHÔNG PHẢI** cùng cơ chế với `gemini_quota_exhausted` (lỗi ingest-time của
Gemini OCR/LiteParse fallback) và cũng **KHÔNG PHẢI** `BizCity_Entitlement`
free_day_quota (per-user feature counter, §9.3 dưới đây). Ghi lại chi tiết
đầy đủ vào `/memories/repo/bizcity-llm-router-quota-systems.md` — đọc file đó
trước khi debug bất kỳ báo lỗi "hết quota" nào để tránh sửa nhầm chỗ.

**Hệ quả UX cần biết:** một source có thể hiện `status=active`/`done` (ingest
xong, không lỗi) NHƯNG các bước làm giàu sau ingest (welcome message, KG
skeleton graph) vẫn có thể fail thầm lặng nếu key đã chạm cost cap ngày hôm
đó — user sẽ không thấy banner lỗi nào trong Learning Log panel (vì nó chỉ
theo dõi trạng thái `kg_sources`/`bizcity_twinchat_sources`, không theo dõi
`twinchat-welcome`/`kg-skeleton` job riêng). Đây là gap UX tiềm ẩn, CHƯA fix,
ghi nhận để theo dõi nếu user báo "học xong nhưng không thấy tóm tắt/sơ đồ".

### 9.3 Phát hiện mới #2 — entitlement REST trả raw plan-matrix spec thay vì client shape (FIXED)

Không liên quan trực tiếp async-ingest pipeline, nhưng phát hiện qua đúng CTA
"Quản lý gói và API key" (§4.4 ở trên) khi user bấm mở UpgradeModal: MỌI dòng
capability hiện `"undefined/undefined/day"`.

**Root cause:** `bizcity-llm-router/includes/class-router-entitlement-rest.php::handle_get()`
trả thẳng `BizCity_Entitlement::for_user()['features']` — dict chỉ có
`quota_day`/`free_day`/`metered.unit`/`metered.rate` (xem
`BizCity_Entitlement::default_matrix()`) — trong khi FE
(`modules/twinchat/ui/src/components/account/UpgradeModal.tsx` +
`api/entitlement.ts` type `EntitlementFeature`) đọc `free_day_quota`/
`used_today`/`unit`/`allowed`/`cost_per_unit_usd`. 2 shape này KHÔNG BAO GIỜ
khớp — bug tồn tại từ khi route được viết (PHASE-0.7 Wave T0), không phải
regression mới.

**Fix (2026-07-24):** thêm `BizCity_Router_Entitlement_REST::build_client_features_map()`
map raw spec → shape FE cần (metered feature → free_day_quota/used_today thật;
capability-gate thuần `quota_day` không có `metered` → coi là Unlimited `-1`
vì không có counter thật để track). Áp dụng ở cả 2 điểm response (`handle_get()`
chính + nhánh `api_key_owner_proxy`). Thêm guard phòng thủ phía client
(`typeof f.free_day_quota !== 'number'` → hiện `—`) cho payload cũ/degraded
còn sót trong 10s transient cache.

**Đã fix nhưng CHƯA compliant R-LLM-KEY-ONLY:** `BizCity_Entitlement::for_user()`
vẫn resolve tier qua `resolve_tier($user_id)` (user_id-based), đây là gap đã
biết ghi trong `/memories/repo/bizcity-llm-router-master-plans.md` — KHÔNG
động vào trong lần fix này (chỉ sửa shape của response, không sửa identity
resolution). Theo dõi riêng nếu cần R-LLM-KEY-ONLY compliance đầy đủ cho class
này (cần thêm `$key_id` vào toàn bộ public API + schema `bizcity_entitlement_usage`).

### 9.4 UX bổ sung cùng phiên (không phải bug fix, additive)

Thêm nút **"Fetch"** (force refetch bỏ qua staleTime 3s) và **"Xem JSON"**
(toggle xem raw response inline, không cần "Tải JSON" xuống máy) vào header
`SourceLearningLogPanel.tsx` — độc lập với status, dùng để trace nhanh mà
không cần rời khỏi UI. Không thay đổi hành vi nút "Thử lại" đã có.

