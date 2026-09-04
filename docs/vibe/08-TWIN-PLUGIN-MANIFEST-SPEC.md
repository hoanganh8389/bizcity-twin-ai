# 08 — `twin-plugin.json` Manifest Spec

> **Status:** ✅ WAVE 2 LANDED — schema đầy đủ đã tồn tại và các field
> taxonomy/diagnostics/logging đã được bổ sung, validator đã kiểm tra shape
> và quan hệ `primary_taxonomy` → `taxonomy`.
> (`core/twin-core/contracts/schema/manifest.schema.json` +
> `examples/bizcity-reference-plugin/manifest.json`). Tài liệu này hoà giải
> phiên bản rút gọn user đề xuất với schema đầy đủ đã implement.

---

## 1. Đối chiếu đề xuất của user với schema đã có

User đề xuất (rút gọn):

```json
{
  "name": "woocommerce-insight",
  "version": "1.0.0",
  "requires_twin": ">=1.2",
  "capabilities": ["tool", "source", "event"],
  "permissions": ["woocommerce.read"],
  "entry": "plugin.php",
  "diagnostics": true
}
```

Schema hiện đã implement (`manifest.schema.json`, xác nhận qua
`examples/bizcity-reference-plugin/manifest.json`):

```json
{
  "schema_version": "1.0",
  "id": "bizcity.reference",
  "name": "BizCity Reference Extension",
  "version": "1.0.0",
  "requires": { "framework": ">=1.1.0", "php": ">=7.4", "wp": ">=6.0" },
  "permissions": ["kg.read", "..."],
  "scope_bindings": [{ "permission": "kg.read", "scope_level": "tenant" }],
  "approval_gates": ["publish_content"],
  "security": { "webhook_signature": {...}, "secret_refs": [...], "network_policy": {...}, "upload_policy": {...} },
  "navigation": [{...}],
  "capabilities": {
    "tools": [{...}], "skills": [{...}], "agents": [{...}], "channels": [{...}],
    "kg_source_adapters": [{...}], "workflow_blocks": [{...}], "personas": [{...}],
    "output_renderers": [{...}]
  }
}
```

**Quyết định:** giữ schema đầy đủ ở trên làm **canonical** (đã có validator,
đã có reference plugin chạy PASS) — KHÔNG viết lại thành schema rút gọn của
user. Bù lại, CLI scaffold (xem [09](09-CLI-SCAFFOLDING-WP-BIZCITY.md)) sinh ra
manifest với **các field tối thiểu điền sẵn**, để trải nghiệm gõ tay ban đầu
gọn như đề xuất của user, nhưng file cuối cùng vẫn đúng schema đầy đủ.

Khác biệt cụ thể cần lưu ý khi map:

| Field user đề xuất | Field canonical tương ứng | Ghi chú |
|---|---|---|
| `requires_twin` | `requires.framework` | Giữ `requires` là object để mở rộng thêm `php`/`wp` |
| `capabilities: ["tool","source","event"]` (mảng string loại) | `capabilities.tools[]`, `capabilities.kg_source_adapters[]`, ... (mảng object theo từng loại) | Schema đầy đủ cần object để có `id/class/schema` — không thể chỉ ghi tên loại |
| `entry` | Không có field riêng — dùng tên file bootstrap chuẩn `{slug}.php` (WordPress plugin header) | WordPress đã có cơ chế phát hiện entry qua Plugin Header, không cần field riêng |
| `diagnostics: true` | Ngầm định bởi việc có đăng ký `register_diagnostic()` + file trong `diagnostics/probes.php` | Field boolean tường minh nên **được thêm** vào schema (xem §3 đề xuất bổ sung) |
| — | `taxonomy: ["act","channel","view"]` | Mới, xem [06](06-PLUGIN-TAXONOMY-ACT-CHANNEL-VIEW.md) — cần bổ sung vào schema |

## 2. Field reference đầy đủ (annotated)

| Field | Bắt buộc | Ý nghĩa |
|---|---|---|
| `schema_version` | ✅ | Version của chính schema này, không phải version plugin |
| `id` | ✅ | ID duy nhất, dạng `vendor.plugin_slug` |
| `name` | ✅ | Tên hiển thị |
| `version` | ✅ | SemVer của plugin |
| `requires.framework` | ✅ | Range version Twin framework tương thích (SemVer range) |
| `requires.php` / `requires.wp` | ✅ | Ràng buộc môi trường (giữ `php: ">=7.4"` do toàn framework target PHP 7.4) |
| `permissions[]` | ✅ nếu có mutation/side-effect | Danh sách quyền cần cấp (least privilege — xem CAPABILITY-SECURITY-v1.md) |
| `scope_bindings[]` | Khuyến nghị | Mỗi permission gắn `scope_level` (`tenant`/`user`/`site`) |
| `approval_gates[]` | Bắt buộc nếu có hành động nhạy cảm | Tên gate cần user/admin xác nhận trước khi chạy |
| `security.webhook_signature` | Bắt buộc nếu có Channel Adapter nhận webhook | HMAC verify config |
| `security.secret_refs[]` | Bắt buộc nếu cần secret | Chỉ tên constant/option, KHÔNG chứa giá trị thật |
| `security.network_policy` | Bắt buộc nếu gọi URL ngoài | Allowlist host, chặn private IP range (SSRF policy) |
| `security.upload_policy` | Bắt buộc nếu nhận file upload | MIME allowlist, max bytes, scan required |
| `navigation[]` | Nếu có nhóm **View** | Entry cho Admin Navigation Registry |
| `capabilities.tools[]` | Nếu có nhóm **Act** | Mỗi tool: `id`, `label`, `description`, `class`, `primary`, `schema.input_fields`, `schema.output_fields` |
| `capabilities.skills[]` | Tuỳ chọn | Skill tái sử dụng |
| `capabilities.agents[]` | Tuỳ chọn | Agent orchestration riêng (hiếm dùng — ưu tiên Tool) |
| `capabilities.channels[]` | Nếu có nhóm **Channel** | Mỗi channel: `id`, `class`, `zone` (`guest_channel`/`user_bound`) |
| `capabilities.kg_source_adapters[]` | Nếu đóng góp tri thức | Xem [03](03-KG-HUB-UNIFICATION.md) |
| `capabilities.workflow_blocks[]` | Nếu tích hợp Automation builder | Block kéo-thả trong workflow |
| `capabilities.personas[]` | Hiếm dùng | Persona provider riêng |
| `capabilities.output_renderers[]` | Nếu nhóm **View** cần render nội dung AI | `artifact_type` (text/table/chart/...) |

## 3. Field mở rộng đã ship ở Wave 2

Các field user yêu cầu đã được thêm theo hướng backward-compatible vào
`manifest.schema.json`, `bin/bizcity-manifest-validate.php` và golden fixture
`examples/bizcity-reference-plugin/manifest.json`:

```json
{
  "taxonomy": ["act", "channel"],
  "primary_taxonomy": "act",
  "diagnostics": true,
  "logging": {
    "contracts": ["plugins.my_plugin.audit"]
  }
}
```

- `taxonomy`/`primary_taxonomy` — xem [06](06-PLUGIN-TAXONOMY-ACT-CHANNEL-VIEW.md).
  Validator bắt buộc taxonomy không rỗng nếu field xuất hiện và
  `primary_taxonomy` phải nằm trong taxonomy.
- `diagnostics: true` — xác nhận tường minh plugin có self-check runtime,
  Diagnostics dùng field này để phân biệt "plugin cố tình không cần
  diagnostics" (hiếm, phải giải trình) với "plugin quên đăng ký".
- `logging.contracts[]` — liệt kê ID contract JSONL/filestore mà plugin đã
  đăng ký (xem [02](02-FILESTORE-LOG-INDEX-STANDARD.md)), để lint có thể đối
  chiếu chéo với `BizCity_Log_Contract_Registry`/`BizCity_File_Contract_Registry`
  thực tế mà không cần load toàn bộ plugin. Wave 4 sẽ bổ sung cross-check
  registry runtime nếu cần; Wave 2 hiện kiểm tra cú pháp/shape/ID contract.

Evidence: Wave 2 validator PASS trên reference fixture và toàn bộ 9 plugin
manifest trong `PLUGIN-CONTRACT-REGISTRY-v1.json` ngày 2026-08-29; schema và
manifest đều parse JSON thành công.

## 4. Validate

Dùng CLI đã có:

```bash
php bin/bizcity-manifest-validate.php --plugin=plugins/my-plugin
```

Không viết validator riêng cho plugin — mọi field mới ở §3 phải được thêm vào
`manifest.schema.json` + `bin/bizcity-manifest-validate.php` chung, để mọi
plugin (kể cả plugin cũ) hưởng lợi từ cùng 1 validator.

## 5. Tham chiếu

- `core/twin-core/contracts/schema/manifest.schema.json`
- `examples/bizcity-reference-plugin/manifest.json`
- `bin/bizcity-manifest-validate.php`
- [CAPABILITY-SECURITY-v1.md](../contracts/CAPABILITY-SECURITY-v1.md)
