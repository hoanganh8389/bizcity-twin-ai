# Cài đặt nhanh — 5 phút

> Dành cho **developer** đã có server WordPress đang chạy.
> Người dùng không rành kỹ thuật → xem [Hướng dẫn chi tiết](../INSTALL-USER-GUIDE-VI.md).

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

Vào **WP Admin → BizCity Twin AI → Settings → Gateway** và lưu:

- Gateway URL: `https://bizcity.vn`;
- BizCity API key: dạng `biz-xxx`.

Runtime chỉ dùng hai option `bizcity_llm_gateway_url` và
`bizcity_llm_api_key`. Không nhập provider key OpenAI, Anthropic, Tavily, Kling
hoặc PiAPI trên client.

---

## 3. Kiểm tra

**WP Admin → Tools → BizCity Diagnostics** → Probe `gateway.health` → **PASS** ✅

Sau khi gateway pass, chạy probe `core.piapi.image_task` nếu site dùng Tool Image
background removal.

---

## Tiếp theo

- [Thêm kiến thức vào Knowledge Base](../knowledge/overview.md)
- [Dùng TwinBrain](../twinbrain/overview.md)
- [Kết nối kênh Zalo / Facebook](../channels/overview.md)
