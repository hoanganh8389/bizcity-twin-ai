# Cài đặt zca-bridge

> `zca-bridge` là sidecar Node.js bạn tự host, đóng vai trò cầu nối giữa
> tài khoản Zalo cá nhân và WordPress. Tài liệu chi tiết đầy đủ:
> [`plugins/bizcity-zalo-personal/docs/HUONG-DAN-KET-NOI-ZALO-CA-NHAN.md`](../../../plugins/bizcity-zalo-personal/docs/HUONG-DAN-KET-NOI-ZALO-CA-NHAN.md)

---

## Bước 1: Chuẩn bị server

Bạn cần một server/VPS có thể chạy Docker. WordPress phải gọi được bridge qua
HTTP/HTTPS và bridge phải gọi được WordPress inbound endpoint.

**Ví dụ topology:**
- Cùng server: `http://127.0.0.1:4000`
- Khác server: `https://bridge.yourdomain.com`

---

## Bước 2: Tạo file `.env` cho WordPress CRM mode

```bash
cp .env.example .env
```

Điền các giá trị bắt buộc:

```dotenv
DATABASE_URL=postgres://zca:zca@bridge-db:5432/zca
PORT=4000
PUBLIC_BASE_URL=http://your-server-ip:4000

# Tạo bằng: openssl rand -hex 32
CREDENTIALS_KEY=<32-byte-hex>

# Không cần Chatwoot cho mode này.
CHATWOOT_BASE_URL=

# URL WordPress nhận inbound
BIZCITY_INBOUND_URL=https://your-wp-site.com/wp-json/bizcity-channel/v1/zalo-bridge/inbound

# Shared secret — PHẢI khớp với WP Settings
BIZCITY_INBOUND_TOKEN=<openssl-rand-hex-32>
```

`BIZCITY_INBOUND_TOKEN` là shared secret hai chiều. Nhập cùng giá trị vào
WordPress option `bizcity_zalo_bridge_token`. `CREDENTIALS_KEY` phải giữ nguyên
sau lần đăng nhập QR đầu tiên.

> ⚠️ `CREDENTIALS_KEY` mã hóa session Zalo trong DB. Không được thay đổi sau khi đăng nhập.

---

## Bước 3: Chạy Docker từ source custom

```bash
docker compose -f docker-compose.example.yml up -d --build

# Kiểm tra
curl http://localhost:4000/healthz
# Kỳ vọng: {"ok":true,...}
```

---

## Bước 4: Cấu hình WordPress

Vào **WP Admin → BizCity → Channel Settings → Zalo Personal**:

| Trường | Giá trị |
|---|---|
| Bridge URL | `http://127.0.0.1:4000` |
| Bridge Token | Giá trị `BIZCITY_INBOUND_TOKEN` |

Nhấn **"Kiểm tra kết nối"** → kỳ vọng 3 layer **PASS**.

---

→ Tiếp theo: [Đăng nhập QR Code](login-qr.md)

→ Tài liệu đầy đủ: [HUONG-DAN-KET-NOI-ZALO-CA-NHAN.md](../../../plugins/bizcity-zalo-personal/docs/HUONG-DAN-KET-NOI-ZALO-CA-NHAN.md)
