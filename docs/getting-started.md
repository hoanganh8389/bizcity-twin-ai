# Getting Started — BizCity Twin AI Framework

> **5-minute setup** cho dev mới muốn dùng Bizcity Twin AI làm framework AI cho
> WordPress. Sau hướng dẫn này bạn có 1 site WP chạy được TwinChat + tự build
> được 1 sub-plugin hello-world.

---

## 1. Yêu cầu

| Thành phần | Phiên bản tối thiểu |
|---|---|
| PHP | **7.4** (test trên 7.4 / 8.1 / 8.2) |
| WordPress | 6.0+ |
| MySQL/MariaDB | 5.7+ / 10.3+ |
| Composer | 2.x (chỉ dev) |
| Node.js | 18+ (chỉ build FE) |
| `bizcity_llm_api_key` | `biz-xxxxx` cấp bởi BizCity (đăng ký tại https://bizcity.vn) |

> ⚠️ **KHÔNG** cần cài plugin `bizcity-llm-router`. Plugin đó chỉ chạy trên server
> BizCity. Client chỉ cần API key + URL gateway. Xem
> [PHASE-0-RULE-GATEWAY-ONLY.md](rules/PHASE-0-RULE-GATEWAY-ONLY.md).

---

## 2. Cài plugin

### Option A — Composer (recommended cho dev)

```bash
cd wp-content/plugins/
git clone https://github.com/bizcity/bizcity-twin-ai.git
cd bizcity-twin-ai
composer install --no-dev   # production
# hoặc:
composer install            # dev (kèm phpunit, phpcs)
```

### Option B — ZIP

1. Tải release ZIP từ GitHub Releases.
2. Upload qua WP Admin → Plugins → Add New → Upload Plugin.
3. Activate.

---

## 3. Cấu hình API key

```php
// wp-config.php (recommended)
define( 'BIZCITY_LLM_GATEWAY_URL', 'https://bizcity.vn' );
define( 'BIZCITY_LLM_API_KEY',     'biz-xxxxxxxxxxxxxxxxxxxxxxxx' );
```

Hoặc qua WP Admin: **Settings → BizCity Twin AI → Gateway**.

Verify: vào **Tools → BizCity Diagnostics** → chạy probe `gateway.health` → kỳ vọng badge **PASS**.

---

## 4. Hello-world: gọi LLM trong code của bạn

```php
<?php
// my-test.php (dán vào /wp-content/mu-plugins/ hoặc trong functional plugin)

add_action( 'init', function() {
    if ( ! isset( $_GET['twin_test'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
        wp_die( 'BizCity Twin AI chưa load.' );
    }

    $llm = BizCity_LLM_Client::instance();
    if ( ! $llm->is_ready() ) {
        wp_die( 'API key chưa set — kiểm tra wp-config.php.' );
    }

    $resp = $llm->chat( [
        [ 'role' => 'system', 'content' => 'Bạn là trợ lý ngắn gọn.' ],
        [ 'role' => 'user',   'content' => 'Xin chào!' ],
    ], [ 'purpose' => 'fast' ] );

    wp_send_json( $resp );
} );
```

Truy cập: `https://your-site.test/?twin_test=1`

---

## 5. Build sub-plugin đầu tiên

Xem [extending/sub-plugin-quickstart.md](extending/sub-plugin-quickstart.md) — copy-paste 1 file PHP, đăng ký 1 tool, agent gọi được trong 3 phút.

---

## 6. Diagnostics — health check thay vì PHPUnit

Bizcity Twin AI dùng **probe-based diagnostics** thay test framework truyền thống. Mọi capability quan trọng (KG seeding, deep research, vector+graph, fb publisher, schema migration…) đều có 1 probe real-call kiểm tra.

```bash
# CLI runner (Phase 0.99.8 — sắp có)
php bin/diagnostics-run.php --junit=build/junit.xml
```

UI: **Tools → BizCity Diagnostics** → click **Run All** → 30s xong, mỗi row có badge `PASS / FAIL / SKIP` + 3 layers evidence (Disk / Loader / Runtime).

> Triết lý: thay vì mock test, mình real-call gateway + DB + REST → bug production phát hiện ở dev.

Rule chuẩn: [PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md](diagnostics/PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md).

---

## 7. Tiếp theo

- [extending/sub-plugin-quickstart.md](extending/sub-plugin-quickstart.md) — tạo sub-plugin scaffold.
- [extending/agent-tool-recipe.md](extending/agent-tool-recipe.md) — đăng ký 1 tool vào agent registry.
- [extension/HOOKS.md](extension/HOOKS.md) — danh sách filter/action public.
- [api/README.md](api/README.md) → trỏ sang [bizcity-llm-router/docs/api/README.md](../../bizcity-llm-router/docs/api/README.md) — 12 branches API gateway.
- [rules/PHASE-0-CANON.md](rules/PHASE-0-CANON.md) — tier rule (đọc khi PR).

---

## 8. Troubleshooting

| Triệu chứng | Nguyên nhân | Fix |
|---|---|---|
| `404 /wp-json/bizcity/v1/*` trên client | Code đang fetch thẳng namespace router | Refactor sang `BizCity_LLM_Client` + proxy REST same-origin. **KHÔNG** cài `bizcity-llm-router`. |
| Admin trang trắng `&#xFEFF;` xuất hiện | PHP file có BOM | `[System.IO.File]::ReadAllBytes($p)[0..2]` ≠ `60 63 112` → fix bằng `create_file` tool, không `Set-Content -Encoding UTF8`. |
| Probe `*.health` luôn FAIL | API key sai / hết hạn / quota = 0 | Mở **Settings → BizCity Twin AI**, regenerate key. |
| Fatal `union return type` trên PHP 7.4 | Code mới dùng `): array\|WP_Error` | Bỏ return type, doc `@return`. Xem [.github/copilot-instructions.md](../.github/copilot-instructions.md#PHP-74). |

---

## License

GPL-2.0-or-later. Xem [LICENSE](../LICENSE).
