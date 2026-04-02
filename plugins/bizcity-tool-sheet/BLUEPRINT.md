# BizCity Tool Sheet — Blueprint MVP

## 1. Mục tiêu plugin

`bizcity-tool-sheet` là spreadsheet studio plugin cho Twin AI.

MVP này giải quyết 3 việc trước:

1. Dùng chat để tạo workbook từ prompt tự nhiên.
2. Lưu workbook theo chuẩn BizCity bằng CPT + JSON.
3. Mở studio frontend để chỉnh sửa, phân tích, export và bridge dữ liệu sang chat.

Phase sau mới cắm spreadsheet engine nặng như SpreadJS, Univer hoặc canvas/grid engine riêng.

---

## 2. Cấu trúc file/folder chuẩn BizCity

```text
bizcity-tool-sheet/
├── bizcity-tool-sheet.php
├── index.php
├── BLUEPRINT.md
├── assets/
│   ├── index.php
│   └── sheet.svg
├── includes/
│   ├── index.php
│   └── class-tools-sheet.php
└── views/
    ├── index.php
    └── page-sheet.php
```

### Vai trò từng file

- `bizcity-tool-sheet.php`
  - bootstrap plugin
  - đăng ký intent provider
  - route Template Page `/tool-sheet/`
  - load class tool

- `includes/class-tools-sheet.php`
  - CPT `bz_sheet`
  - tool callbacks
  - AJAX handlers cho studio
  - helpers workbook JSON / CSV / formula patch

- `assets/sheet.svg`
  - icon tối thiểu cho plugin header

- `views/page-sheet.php`
  - studio shell frontend
  - create/history/editor/chat
  - bridge `postMessage()` sang parent chat

---

## 3. Mapping vào kiến trúc Twin AI

### Pillar 1 — Profile View / Studio

- Route: `/tool-sheet/`
- UI hiện tại là `studio shell`
- Source of truth là `workbook_json`
- Có thể thay renderer phase sau mà không đổi backend contract

### Pillar 2 — Primary Tool

- `create_sheet_from_prompt`
- Đây là goal generic nhất của plugin
- Dùng khi user nói: “tạo bảng tính”, “làm bảng ngân sách”, “tạo workbook”

### Pillar 3 — Secondary Tools

- `analyze_sheet_data`
- `fill_formula_range`
- `export_sheet_file`

---

## 4. Data model MVP

### CPT

- Post type: `bz_sheet`

### Meta keys

- `_bz_sheet_workbook`
  - JSON workbook
- `_bz_sheet_blueprint`
  - prompt blueprint đã suy luận
- `_bz_sheet_purpose`
  - budget/dashboard/tracker/...
- `_bz_sheet_rows_estimate`
  - số dòng mẫu đã tạo
- `_bz_sheet_last_patch`
  - formula patch cuối cùng
- `_bz_sheet_context`
  - context string nếu có

### Workbook JSON contract

```json
{
  "version": 1,
  "engine": "sheet-studio-mvp",
  "sheets": [
    {
      "name": "Sheet1",
      "rows": [
        ["Header 1", "Header 2"],
        ["Row 1", "1000"]
      ],
      "freeze": { "row": 1, "col": 1 }
    }
  ],
  "meta": {
    "purpose": "budget",
    "title": "Bang ngan sach"
  }
}
```

Nguyên tắc quan trọng:

- Không lưu DOM.
- Không gắn runtime state của editor vào DB.
- Chỉ lưu workbook model có cấu trúc.

---

## 5. Tool schema cụ thể

### 5.1 `create_sheet_from_prompt`

**Use case**

- tạo bảng ngân sách
- tạo KPI dashboard
- tạo bảng chấm công
- tạo tồn kho

**Input fields**

```php
[
  'topic'         => [ 'required' => true,  'type' => 'text' ],
  'sheet_purpose' => [ 'required' => false, 'type' => 'choice' ],
  'rows_estimate' => [ 'required' => false, 'type' => 'number' ],
]
```

**Output schema**

```php
[
  'workbook_id'   => [ 'type' => 'int' ],
  'title'         => [ 'type' => 'string' ],
  'workbook_json' => [ 'type' => 'string' ],
  'sheet_url'     => [ 'type' => 'string' ],
  'sheet_purpose' => [ 'type' => 'string' ],
]
```

### 5.2 `analyze_sheet_data`

**Use case**

- đọc CSV hoặc workbook JSON
- nhận diện headers
- tìm cột số
- gợi ý dashboard / formula

**Input fields**

```php
[
  'sheet_data'    => [ 'required' => true,  'type' => 'text' ],
  'analysis_goal' => [ 'required' => false, 'type' => 'choice' ],
]
```

**Output schema**

```php
[
  'row_count'       => [ 'type' => 'int' ],
  'column_count'    => [ 'type' => 'int' ],
  'headers'         => [ 'type' => 'array' ],
  'numeric_columns' => [ 'type' => 'array' ],
  'insights'        => [ 'type' => 'array' ],
]
```

### 5.3 `fill_formula_range`

**Use case**

- gợi ý công thức SUM/AVERAGE/MARGIN/COUNT
- tạo patch công thức cho một vùng
- phase sau apply trực tiếp vào engine frontend

**Input fields**

```php
[
  'formula_goal' => [ 'required' => true,  'type' => 'text' ],
  'target_range' => [ 'required' => true,  'type' => 'text' ],
  'sheet_name'   => [ 'required' => false, 'type' => 'text' ],
  'workbook_id'  => [ 'required' => false, 'type' => 'number' ],
]
```

**Output schema**

```php
[
  'formula'      => [ 'type' => 'string' ],
  'target_range' => [ 'type' => 'string' ],
  'sheet_name'   => [ 'type' => 'string' ],
  'patch_id'     => [ 'type' => 'string' ],
]
```

### 5.4 `export_sheet_file`

**Use case**

- export JSON
- export CSV
- phase sau export XLSX/PDF qua frontend engine

**Input fields**

```php
[
  'workbook_id'   => [ 'required' => true,  'type' => 'number' ],
  'export_format' => [ 'required' => false, 'type' => 'choice' ],
]
```

**Output schema**

```php
[
  'workbook_id'   => [ 'type' => 'int' ],
  'export_format' => [ 'type' => 'string' ],
  'file_name'     => [ 'type' => 'string' ],
  'payload'       => [ 'type' => 'string' ],
]
```

---

## 6. MVP frontend flow

### Create tab

- nhập prompt
- chọn purpose
- chọn rows estimate
- gọi AJAX `bztool_sheet_create`
- backend tạo workbook JSON + CPT

### History tab

- liệt kê workbook đã lưu theo user
- mở workbook vào editor
- xóa workbook

### Editor tab

- preview workbook dưới dạng HTML table
- chỉnh JSON trực tiếp
- save JSON
- export JSON/CSV
- send workbook sang chat

### Chat tab

- guided commands với `data-tool` attribute
- `postMessage({ type: 'bizcity_agent_command', source: 'bizcity-tool-sheet', plugin_slug: 'bizcity-tool-sheet', tool_name, text })` (v2 contract)
- `buildSlashMessage()` tự prepend `/tool_name` trước message

---

## 7. Khi cắm SpreadJS hoặc engine thật ở phase sau

Thay đổi chủ yếu ở frontend:

1. `page-sheet.php`
   - thay HTML table preview bằng spreadsheet runtime
   - load workbook JSON vào runtime
   - serialize ngược runtime state về `workbook_json`

2. `class-tools-sheet.php`
   - giữ nguyên CPT, AJAX và tool contract
   - chỉ thêm export xlsx/pdf hoặc patch apply sâu hơn nếu cần

---

## 8. Review checklist (2026-03-30)

| Item | Status |
|------|--------|
| Plugin headers đúng chuẩn BizCity | ✅ |
| BIZCITY_TWIN_AI_VERSION guard | ✅ |
| Intent provider 4 patterns + 4 plans + 4 tools | ✅ |
| CPT `bz_sheet` (private, author-scoped) | ✅ |
| 5 AJAX endpoints (create/list/get/save/delete) | ✅ |
| Nonce + auth + owner check trên mọi AJAX | ✅ |
| Tool callbacks return format chuẩn | ✅ |
| Template Page `/tool-sheet/` + rewrite rule | ✅ |
| postMessage v2 contract (text, source, plugin_slug, tool_name) | ✅ |
| buildSlashMessage helper | ✅ |
| SVG icon + index.php guard files | ✅ |
| JSON normalize validation trên save | ✅ |
| PHP syntax — no errors | ✅ |
| Auto-discovered by mu-plugin compat loader | ✅ |

### Phase 2 TODO

- [ ] Gắn SpreadJS / Univer / canvas engine thay HTML table preview
- [ ] Export XLSX/PDF qua frontend engine
- [ ] AI generate nâng cao qua OpenRouter (hiện dùng local blueprint heuristic)
- [ ] Import XLSX/CSV upload handler (`class-sheet-import.php`)
- [ ] Notebook tool delegation (`bcn_register_notebook_tools`)
- [ ] Job Trace integration cho tool callbacks
- [ ] REST API endpoints cho external access

3. Tool contracts
   - không đổi tên tool
   - không đổi output envelope
   - chỉ mở rộng `output_schema` nếu có thêm workbook artifacts

---

## 8. Phase roadmap ngắn

### Phase A — MVP shell

- provider
- CPT
- studio shell
- JSON/CSV export
- formula patch gợi ý

### Phase B — Spreadsheet engine

- mount SpreadJS hoặc engine khác
- open file/import workbook thật
- apply formula patch trực tiếp
- selection bridge sang chat

### Phase C — AI operations nâng cao

- generate chart suggestions
- data cleaning patch
- pivot builder
- dashboard layout auto-generate
- workbook diff/trace

---

## 9. Lưu ý kiến trúc

- AI không thay thế spreadsheet runtime.
- Workbook model là source of truth.
- Chat chỉ thao tác qua tool contract, không điều khiển DOM trực tiếp.
- Export XLSX/PDF nên đi qua engine frontend khi gắn thư viện chuyên dụng.
