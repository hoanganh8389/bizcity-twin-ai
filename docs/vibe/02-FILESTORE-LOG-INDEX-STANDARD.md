# 02 — Filestore JSONL + `bizcity_log_index` Ledger Standard

> **Status:** ✅ CANON HIỆN HÀNH — đây là tổng hợp lại
> [PHASE-0-RULE-LOG-HYBRID-CANON.md](../rules/PHASE-0-RULE-LOG-HYBRID-CANON.md)
> (R-LOG-HYBRID) và
> [PHASE-0-RULE-FILESTORE-BUSINESS-CANON.md](../rules/PHASE-0-RULE-FILESTORE-BUSINESS-CANON.md)
> (R-FILESTORE-BUSINESS) áp dụng riêng cho plugin mới. Không phát minh rule
> mới — chỉ đóng khung thành checklist bắt buộc khi scaffold 1 plugin.
>
> **Ghi chú chính tả:** yêu cầu gốc viết `_bicity_log_index` — tên bảng chuẩn
> đang triển khai trong code là **`bizcity_log_index`** (không có dấu gạch
> dưới đầu, đúng chính tả `bizcity`). Toàn bộ tài liệu dưới đây dùng tên đúng.

---

## 1. Ba loại dữ liệu, ba đích lưu trữ khác nhau

Không phải mọi dữ liệu của plugin đều lưu giống nhau. Trước khi viết bất kỳ
`INSERT`/`file_put_contents` nào, phân loại dữ liệu theo bảng sau:

| Loại dữ liệu | Ví dụ | Đích lưu trữ bắt buộc | Rule |
|---|---|---|---|
| **Operational log/audit/trace** (append-only, đọc tuần tự, không cần atomic counter) | webhook nhận được, tool đã chạy, lỗi provider | `BizCity_JSONL_File_Logger` + đăng ký `BizCity_Log_Contract_Registry` | R-LOG-HYBRID |
| **Durable business record** (dữ liệu nghiệp vụ cần tồn tại lâu dài, có thể sửa/xoá theo ID) | đơn hàng đã tạo, ghi chú người dùng, hồ sơ khách hàng riêng của plugin | `BizCity_Business_JSONL_File_Store` + đăng ký `BizCity_File_Contract_Registry`, encrypted + append-only upsert/tombstone | R-FILESTORE-BUSINESS |
| **Structural SQL** (mutex/lock, queue, atomic counter, N-N relation/join, CRM snapshot, billing ledger) | rate-limit lock, quota counter | Bảng SQL bình thường, đăng ký qua R-DCL + `BizCity_Schema_Registry` | Ngoại lệ tường minh, không phải mặc định |

**Mặc định của mọi plugin mới: nếu không chắc, dùng JSONL trước.** SQL chỉ
được chọn khi chứng minh được nhu cầu atomic/join/query tần suất cao mà JSONL
không đáp ứng — và phải qua review, không được tự quyết.

## 2. Đăng ký contract — bắt buộc, không có ngoại lệ

Mọi nguồn JSONL của plugin (dù log hay business record) PHẢI đăng ký một
contract, giống hệt pattern `BizCity_Cache_Registry`/`BizCity_Schema_Registry`
đã dùng trong toàn framework (R-CR — đăng ký ở file scope, ngoài mọi hook):

```php
// Đặt ở file scope trong bootstrap.php của plugin, sau class definition.
if ( class_exists( 'BizCity_Log_Contract_Registry' ) ) {
    BizCity_Log_Contract_Registry::register( 'plugins.my_plugin.audit', array(
        'owner_module'   => 'plugins/my-plugin',
        'folder'         => 'bizcity-my-plugin-logs',
        'module'         => 'audit-trail',
        'retention_days' => 90,
        'indexed'        => true, // có cần search cross-source qua bizcity_log_index?
        'description'    => 'Audit log cho mọi hành động ghi của My Plugin.',
        'since'          => '1.0.0',
    ) );
}
```

Nếu là business record thay vì log, dùng `BizCity_File_Contract_Registry`
với schema tương đương (owner, storage scope, encryption, retention, rollback
owner) — xem đầy đủ tại
[PHASE-0-RULE-FILESTORE-BUSINESS-CANON.md](../rules/PHASE-0-RULE-FILESTORE-BUSINESS-CANON.md).

## 3. `bizcity_log_index` — 1 ledger duy nhất cho toàn framework

Mỗi lần plugin ghi 1 dòng JSONL qua `BizCity_JSONL_File_Logger::write_contract()`
(hoặc `write_scoped_contract()` cho path lồng nhau), hệ thống **tự động** ghi
một con trỏ (pointer) vào bảng chia sẻ duy nhất `bizcity_log_index` NẾU
contract khai báo `'indexed' => true`. Plugin KHÔNG được:

- Tự tạo bảng index thứ hai cho riêng mình.
- `INSERT` trực tiếp vào `bizcity_log_index` — chỉ `BizCity_Log_Index` (nội
  bộ core) được ghi bảng này.
- Đưa nội dung log (message body, PII, token, credential) vào pointer — bảng
  chỉ chứa toạ độ định vị dòng JSONL: `event_uuid`, `contract_id`,
  `jsonl_folder`, `jsonl_module`, `log_date`, `ts`, `event`, `ref_id`,
  `blog_id`, `relative_file`, `byte_offset`, `row_hash`, `meta` (đã redact).

Đọc lại bằng `BizCity_Log_Explorer` (Tools → BizCity Logs) — **không viết
trang admin đọc log riêng cho plugin**. Nếu Explorer chưa đủ (vd cần biểu đồ
riêng), thêm tab/filter vào Explorer thay vì tạo trang mới (xem R-LOG-HYBRID
§6.4 trong rule gốc).

## 4. Đường dẫn vật lý chuẩn

```text
uploads/sites/{blog_id}/bizcity-{owner-slug}-logs/{module}/YYYY-MM-DD.jsonl
```

- `{owner-slug}` = tên plugin viết thường có gạch nối (vd `bizcity-my-plugin-logs`).
- `{module}` = nhóm log trong plugin (vd `audit-trail`, `webhook-intake`).
- KHÔNG tự thêm `{blog_id}` vào path thủ công — `wp_upload_dir()` đã tự xử lý
  trên multisite.
- File phải nằm ngoài web root truy cập trực tiếp hoặc có `.htaccess`/`web.config`/
  `index.php` chặn download trực tiếp (canonical logger đã tự sinh các file
  bảo vệ này — không tự viết lại).

## 5. Retention

`retention_days` khai báo trong contract là nguồn thật duy nhất. Không hard-code
số ngày trong code plugin. Quy trình purge: xoá file JSONL hết hạn trước → chỉ
xoá pointer trong `bizcity_log_index` sau khi file đã xác nhận bị xoá/absent →
reconcile pointer mồ côi. Plugin không tự viết cron purge riêng — dùng
retention cron chung của `BizCity_JSONL_File_Logger`.

## 6. Checklist bắt buộc khi scaffold plugin mới

Trạng thái các điều kiện filestore/log được quản lý tập trung tại
[MASTER-CHECKLIST.md FS-1..FS-7](MASTER-CHECKLIST.md). Phần này chỉ giải thích
contract và không chứa checkbox trạng thái riêng, tránh tạo nhiều nguồn theo
dõi tiến độ.

## 7. Tham chiếu

- [PHASE-0-RULE-LOG-HYBRID-CANON.md](../rules/PHASE-0-RULE-LOG-HYBRID-CANON.md)
- [PHASE-0-RULE-FILESTORE-BUSINESS-CANON.md](../rules/PHASE-0-RULE-FILESTORE-BUSINESS-CANON.md)
- [PHASE-1.30-LEGACY-TABLE-LIFECYCLE.md](../roadmaps/PHASE-1.30-LEGACY-TABLE-LIFECYCLE.md) (ví dụ migrate bảng SQL cũ sang chuẩn này)
- [PHASE-1.29-PLUGIN-TIER-EXTENSIONS-LOG-CANON.md](../roadmaps/PHASE-1.29-PLUGIN-TIER-EXTENSIONS-LOG-CANON.md) §5–§8 (bối cảnh ra đời của chuẩn này)
