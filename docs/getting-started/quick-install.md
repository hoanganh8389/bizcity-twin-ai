# Cài đặt nhanh — 5 phút

> Dành cho **developer** đã có server WordPress đang chạy.
> Người dùng không rành kỹ thuật → xem [Hướng dẫn chi tiết](install-user-guide.md).

---

## Yêu cầu

| | Tối thiểu |
|---|---|
| PHP | 7.4+ |
| WordPress | 6.0+ |
| BizCity API Key | `biz-xxxxx` (đăng ký tại [bizcity.vn](https://bizcity.vn)) |

---

## 1. Cài plugin

```bash
cd wp-content/plugins/
git clone https://github.com/bizcity/bizcity-twin-ai.git
```

Hoặc upload ZIP qua **WP Admin → Plugins → Add New → Upload Plugin** → Activate.

---

## 2. Cấu hình API Key

```php
// wp-config.php
define( 'BIZCITY_LLM_GATEWAY_URL', 'https://bizcity.vn' );
define( 'BIZCITY_LLM_API_KEY',     'biz-xxxxxxxxxxxxxxxxxxxxxxxx' );
```

Hoặc: **WP Admin → BizCity → Settings → Gateway**.

---

## 3. Kiểm tra

**WP Admin → Tools → BizCity Diagnostics** → Probe `gateway.health` → **PASS** ✅

---

## Tiếp theo

- [Thêm kiến thức vào Knowledge Base](../knowledge/overview.md)
- [Dùng TwinBrain](../twinbrain/overview.md)
- [Kết nối kênh Zalo / Facebook](../channels/overview.md)
