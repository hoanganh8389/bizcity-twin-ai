# Hướng dẫn cài đặt Bizcity Twin Brain từ A → Z

> **Đối tượng:** người dùng cuối / chủ doanh nghiệp tự cài plugin trên máy
> cá nhân hoặc site WordPress thật, **không cần biết lập trình**.
> **Thời gian:** ~30 phút (lần đầu) — ~5 phút (các lần sau).
>
> Sau hướng dẫn này bạn sẽ có:
> 1. Một site WordPress chạy local bằng **LocalWP** (miễn phí).
> 2. Plugin **bizcity-twin-ai** đã cài và kích hoạt.
> 3. **BizCity API key** (`biz-xxx…`) đã đăng ký từ `https://bizcity.vn`.
> 4. Key đã dán vào trang `admin.php?page=bizcity-twinchat-settings` và **test
>    connection OK** — sẵn sàng dùng TwinChat, KG Hub, Automation Workflow…

---

## Mục lục

1. [Cài LocalWP — tạo site WordPress trên máy](#1-cài-localwp--tạo-site-wordpress-trên-máy)
2. [Tải plugin Bizcity Twin Brain](#2-tải-plugin-bizcity-twin-brain)
3. [Cài và kích hoạt plugin](#3-cài-và-kích-hoạt-plugin)
4. [Đăng ký BizCity API key](#4-đăng-ký-bizcity-api-key)
5. [Dán API key vào trang Settings](#5-dán-api-key-vào-trang-settings)
6. [Test connection — kiểm tra hoạt động](#6-test-connection--kiểm-tra-hoạt-động)
7. [FAQ & xử lý sự cố](#7-faq--xử-lý-sự-cố)

---

## 1. Cài LocalWP — tạo site WordPress trên máy

**LocalWP** (gọi tắt là *Local*) là phần mềm **miễn phí** của WP Engine cho phép
chạy WordPress ngay trên máy tính Windows / macOS / Linux mà không cần thuê
hosting. Lý tưởng để test plugin trước khi triển khai thật.

### 1.1. Tải Local

- Vào trang: **https://localwp.com/**
- Bấm **"Download"** → chọn hệ điều hành (Windows / macOS / Linux).
- Cài đặt như phần mềm bình thường (Next → Next → Finish).

### 1.2. Tạo site mới

1. Mở **Local** → bấm **"Create a new site"** (góc dưới trái dấu **+**).
2. Đặt tên site (ví dụ: `my-twin-brain`) → **Continue**.
3. Chọn **"Preferred"** environment (PHP 7.4 hoặc 8.1, MySQL, nginx) — mặc định
   là đủ. **Continue**.
4. Đặt **WordPress Username / Password / Email** (ghi nhớ để đăng nhập admin).
   **Add Site**.
5. Đợi 1–2 phút Local sẽ tự download WordPress, tạo DB, khởi động server.
6. Khi site sẵn sàng, bấm nút **"WP Admin"** (góc trên phải) → trình duyệt mở
   trang đăng nhập WordPress.

> 💡 Site sẽ có URL kiểu `http://my-twin-brain.local`. Bookmark lại.

---

## 2. Tải plugin Bizcity Twin Brain

### 2.1. Từ GitHub (khuyên dùng — luôn có bản mới nhất)

- Truy cập: **https://github.com/bizcity/bizcity-twin-ai** *(repo public)*
- Bấm nút xanh **"Code"** → **"Download ZIP"**.
- File sẽ tải về: `bizcity-twin-ai-main.zip`.

### 2.2. Từ release ZIP (bản ổn định)

- Truy cập: **https://github.com/bizcity/bizcity-twin-ai/releases**
- Tải file **`bizcity-twin-ai-v1.0.0.zip`** mới nhất.

> ⚠️ **KHÔNG** cần tải `bizcity-llm-router` — đó là plugin chỉ chạy trên server
> BizCity. Client (bạn) chỉ cần `bizcity-twin-ai` + API key.

---

## 3. Cài và kích hoạt plugin

### 3.1. Upload qua WP Admin (cách dễ nhất)

1. Trong WordPress admin → menu **Plugins → Add New**.
2. Bấm **"Upload Plugin"** (góc trên).
3. Chọn file ZIP vừa tải → **"Install Now"**.
4. Khi cài xong, bấm **"Activate Plugin"**.

### 3.2. Hoặc copy thủ công (nếu upload bị giới hạn dung lượng)

1. Mở Local → click chuột phải vào site → **"Go to site folder"**.
2. Đi vào: `app/public/wp-content/plugins/`.
3. Giải nén file ZIP vào đó. Đảm bảo cấu trúc:
   ```
   wp-content/plugins/bizcity-twin-ai/
       bizcity-twin-ai.php
       core/
       modules/
       ...
   ```
   (KHÔNG được lồng 2 lần như `bizcity-twin-ai/bizcity-twin-ai/...`)
4. Quay lại WP Admin → **Plugins** → tìm **"Bizcity Twin AI"** → **Activate**.

### 3.3. Sau khi activate

Bạn sẽ thấy menu mới bên trái WP Admin:

- **TwinChat** (icon 💬)
  - ⚙ Settings
  - Conversations
  - Personas
  - Knowledge Hub
  - Automation
  - ...

Nếu menu chưa hiện → tải lại trang (F5).

---

## 4. Đăng ký BizCity API key

Plugin cần **1 API key duy nhất** để gọi LLM (GPT/Claude/Gemini), search web,
generate ảnh/video, astrology… Key dạng `biz-xxxxxxxxxxxxxxxxxxxxxx`.

### Cách 1 — Đăng ký nhanh ngay trong plugin (khuyên dùng)

1. WP Admin → **TwinChat → ⚙ Settings** (URL:
   `admin.php?page=bizcity-twinchat-settings`).
2. Cuộn xuống mục **"2. Đăng ký nhanh (chưa có key?)"**.
3. Bấm nút **"⚡ Đăng ký nhanh key BizCity"**.
4. Hệ thống dùng email admin của site để tạo tài khoản + sinh key tự động.
5. Sau vài giây trang sẽ tự reload → key đã có sẵn trong ô **"BizCity API key"**.

### Cách 2 — Đăng ký thủ công trên website BizCity

1. Mở trình duyệt → vào **https://bizcity.vn**.
2. Bấm **"Đăng ký"** (góc trên phải) → tạo tài khoản bằng email.
3. Sau khi đăng nhập → vào **My Account → API Keys**
   (URL: `https://bizcity.vn/my-account/api-keys/`).
4. Bấm **"Tạo key mới"** → đặt tên (ví dụ "site-tap-hoa-cua-toi") → **Create**.
5. **Copy ngay** key dạng `biz-xxxxxxxxxxxxxx…` (key chỉ hiện 1 lần — nếu mất
   phải tạo key mới).
6. Mặc định bạn được **free-tier $0** — đủ để test. Muốn dùng production →
   topup credit ở **My Account → Billing**.

---

## 5. Dán API key vào trang Settings

1. WP Admin → **TwinChat → ⚙ Settings** (URL:
   `admin.php?page=bizcity-twinchat-settings`).
2. Mục **"1. Cấu hình API key"** → ô **"BizCity API key"** → dán key vừa copy
   (`biz-xxxxxxxxxxxxxxxxxxxxxx…`).
3. Ô **"Gateway URL (tuỳ chọn)"** → **để trống** (mặc định
   `https://bizcity.vn` — không cần đổi).
4. Bấm **"💾 Lưu cấu hình"**.
5. Trang reload, hiện thông báo xanh **"Đã lưu cấu hình"**.

> 🔒 Key được lưu trong DB WordPress qua `update_site_option()` — chỉ admin
> mới đọc/sửa được. Plugin **không bao giờ** gửi key đi đâu khác ngoài
> `https://bizcity.vn`.

---

## 6. Test connection — kiểm tra hoạt động

1. Vẫn ở trang Settings, cuộn tới mục **"3. Test connection"**.
2. Bấm **"🔌 Test gateway"**.
3. Đợi 1–3 giây, trang sẽ hiện banner kết quả:
   - ✅ **"Test OK"** + HTTP 200 + latency (ms) + tier + balance → **THÀNH CÔNG**.
   - ❌ **"Test failed"** + HTTP code lỗi → xem mục FAQ bên dưới.

### Sau khi test OK

Plugin đã sẵn sàng. Hãy thử:

- **TwinChat → Conversations** → bấm **"New chat"** → gõ "Xin chào" → bot trả lời.
- **TwinChat → Knowledge Hub** → upload 1 PDF → plugin tự OCR + index.
- **TwinChat → Automation** → tạo workflow đầu tiên (kéo-thả canvas).

---

## 7. FAQ & xử lý sự cố

### ❓ "Test failed" — HTTP 401 Unauthorized

→ Key sai hoặc đã bị thu hồi. Kiểm tra lại key trên
`https://bizcity.vn/my-account/api-keys/`, copy lại không thiếu/thừa khoảng
trắng, dán lại và lưu.

### ❓ "Test failed" — HTTP 402 Payment Required / "insufficient_balance"

→ Tài khoản hết credit. Vào `https://bizcity.vn/my-account/` để topup.

### ❓ "Test failed" — HTTP 0 / "could not resolve host"

→ Máy không kết nối internet được, hoặc firewall chặn `bizcity.vn`. Kiểm tra
mạng / proxy / VPN.

### ❓ Plugin báo "BizCity API key chưa cấu hình" trên các trang khác

→ Quay lại bước 5, dán key, lưu, **không cần** dán lại ở từng plugin con.
Theo nguyên tắc **R-1API** — 1 key dùng chung cho mọi plugin BizCity.

### ❓ Site bị trắng trang sau khi activate plugin

→ Có thể do PHP version. Mở Local → click site → tab **Overview** → đổi
**PHP** sang **7.4** hoặc **8.1**. Restart site.

### ❓ Tôi có cần cài thêm `bizcity-llm-router` không?

**KHÔNG.** Plugin đó chỉ chạy trên server `bizcity.vn`. Client chỉ cần
`bizcity-twin-ai` + API key. Nếu thấy hướng dẫn nào yêu cầu cài
`bizcity-llm-router` trên máy của bạn → **bỏ qua**, hướng dẫn đó sai.

### ❓ Tôi muốn cài lên hosting thật (không dùng Local nữa)?

- Cách 1: dùng Local **"Push to staging"** (cần tài khoản WP Engine miễn phí).
- Cách 2: export site bằng plugin **All-in-One WP Migration** → import lên
  hosting → dán lại API key trong Settings.
- Cách 3: cài thủ công — upload thư mục `wp-content/plugins/bizcity-twin-ai/`
  qua FTP/cPanel → activate trong WP Admin → dán key.

### ❓ Làm sao update plugin lên bản mới?

- Tải ZIP mới từ GitHub Releases → **Plugins → Add New → Upload** → chọn
  **"Replace current with uploaded"** khi WordPress hỏi.
- Hoặc nếu cài qua `git clone`: `cd wp-content/plugins/bizcity-twin-ai && git pull`.

### ❓ Tôi gặp lỗi không có trong FAQ?

- Mở **TwinChat → Diagnostics** (nếu có) → xem dashboard probe PASS/FAIL/SKIP.
- Hoặc kiểm tra log: `wp-content/debug.log` (bật `WP_DEBUG_LOG = true` trong
  `wp-config.php`).
- Liên hệ hỗ trợ: **support@bizcity.vn** kèm screenshot lỗi.

---

## Tài liệu liên quan

- [getting-started.md](getting-started.md) — hướng dẫn nhanh dành cho developer.
- [docs/api/README.md](api/README.md) — 1-API Catalog 12 nhánh endpoint.
- [docs/rules/PHASE-0-RULE-GATEWAY-ONLY.md](rules/PHASE-0-RULE-GATEWAY-ONLY.md)
  — vì sao client KHÔNG cài `bizcity-llm-router`.

---

*Tài liệu này được hiển thị tự động (collapsed) ngay trên trang
`admin.php?page=bizcity-twinchat-settings` để admin có thể tra cứu nhanh.*
