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

### Cấu hình qua WP Admin UI

**WP Admin → BizCity Twin AI → Settings** → tab **Gateway** → nhập gateway URL
và dán key → Lưu.

Runtime dùng hai option canonical `bizcity_llm_gateway_url` và
`bizcity_llm_api_key`. Hai constant `BIZCITY_LLM_GATEWAY_URL` và
`BIZCITY_LLM_API_KEY` không phải contract runtime của plugin hiện tại; không dùng
chúng thay cho Settings UI nếu deployment chưa có lớp ánh xạ riêng.

---

## Kiểm tra key hoạt động

**WP Admin → Tools → BizCity Diagnostics** → Probe `gateway.health`:

- ✅ **PASS** — Key hợp lệ, kết nối OK
- ❌ **FAIL** — Kiểm tra key có đúng format `biz-xxx` không; kiểm tra mạng internet

Nếu dùng Tool Image background removal, chạy thêm probe
`core.piapi.image_task` để kiểm tra wrapper, trace, idempotency và gateway mock.

---

## Lưu ý bảo mật

- **KHÔNG** commit key vào git repository
- Dùng `wp-config.php` thay vì WP Admin để key không lưu trong DB
- Key bị lộ? Vào bizcity.vn → Dashboard → Revoke key cũ → Tạo key mới
