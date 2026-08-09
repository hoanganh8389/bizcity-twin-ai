# Kết nối BizCity API Key

> BizCity API Key (`biz-xxxxx`) là chìa khóa để plugin của bạn giao tiếp với
> các mô hình AI (GPT, Gemini, Claude, Llama...) thông qua gateway BizCity.

---

## Đăng ký API Key

1. Truy cập: **https://bizcity.vn/dang-ky**
2. Tạo tài khoản → vào **Dashboard → API Keys → Tạo key mới**
3. Copy key có dạng `biz-xxxxxxxxxxxxxxxxxxxxxxxx`

---

## Cấu hình trên WordPress

### Cách 1: `wp-config.php` (khuyến cáo — an toàn hơn)

```php
define( 'BIZCITY_LLM_GATEWAY_URL', 'https://bizcity.vn' );
define( 'BIZCITY_LLM_API_KEY',     'biz-xxxxxxxxxxxxxxxxxxxxxxxx' );
```

### Cách 2: WP Admin UI

**WP Admin → BizCity → Settings** → tab **Gateway** → dán key → Lưu.

---

## Kiểm tra key hoạt động

**WP Admin → Tools → BizCity Diagnostics** → Probe `gateway.health`:

- ✅ **PASS** — Key hợp lệ, kết nối OK
- ❌ **FAIL** — Kiểm tra key có đúng format `biz-xxx` không; kiểm tra mạng internet

---

## Lưu ý bảo mật

- **KHÔNG** commit key vào git repository
- Dùng `wp-config.php` thay vì WP Admin để key không lưu trong DB
- Key bị lộ? Vào bizcity.vn → Dashboard → Revoke key cũ → Tạo key mới
