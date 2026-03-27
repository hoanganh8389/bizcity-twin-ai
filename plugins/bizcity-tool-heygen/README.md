# BizCity Video Avatar by HeyGen

Plugin WordPress tạo video avatar lipsync bằng HeyGen AI — quản lý nhân vật AI (voice clone + avatar + persona), nhập script → video chuyên nghiệp.

## 🎯 Tính Năng

- ✅ Quản lý nhân vật AI: voice clone, avatar, persona prompt
- ✅ Tạo video lipsync từ script (text-to-speech hoặc audio mode)
- ✅ 4-Tab profile: Create + Monitor + Chat + Settings
- ✅ Tích hợp Intent Engine (create_lipsync_video, list_characters, check_video_status)
- ✅ Async polling với WP-Cron → gửi kết quả về webchat
- ✅ Tải video về WordPress Media Library
- ✅ HeyGen API v2

## 📋 Yêu Cầu

- WordPress 6.3+
- PHP 7.4+
- HeyGen Account với API Key ([Đăng ký tại đây](https://heygen.com))
- BizCity Intent Engine (mu-plugin `bizcity-intent`)
- BizCity Bot Webchat (mu-plugin `bizcity-bot-webchat`)

## 🚀 Cài Đặt

1. Copy thư mục `bizcity-tool-heygen` vào `/wp-content/plugins/`
2. Activate plugin trong WordPress Admin
3. Truy cập `/tool-heygen/` hoặc chat với AI
4. Vào tab **Settings** → nhập HeyGen API Key
5. Tạo nhân vật AI (upload voice sample + avatar → clone voice)

## 💡 Kiến trúc 2 Lớp

### Lớp A — Cấu hình nhân vật AI (một lần)

Admin cấu hình mỗi nhân vật:
- **Voice Clone**: Upload file audio → HeyGen clone → voice_id
- **Avatar**: Upload ảnh đại diện → avatar gắn với character
- **Persona**: Tên, mô tả, prompt, tone of voice, ngôn ngữ, CTA mặc định

### Lớp B — Tạo video lipsync (hằng ngày)

User/Agent chỉ cần:
1. Chọn nhân vật AI (hoặc dùng nhân vật mặc định)
2. Nhập script / lời thoại
3. Chọn mode: text (TTS) hoặc audio
4. Bấm tạo → HeyGen xử lý async → nhận kết quả qua chat

## 🤖 Intent Provider

| Goal                 | Tool                   | Slots                                          |
|----------------------|------------------------|-------------------------------------------------|
| `create_lipsync_video` | `create_lipsync_video` | `script` (required), `character_id`, `mode`    |
| `list_characters`    | `list_characters`      | (none)                                         |
| `check_video_status` | `check_video_status`   | `job_id` (optional)                            |

### Ví dụ chat

```
User: tạo video avatar giới thiệu sản phẩm mới
Bot: Nhập lời thoại / script cho nhân vật AI nhé! 🎬
User: Xin chào mọi người, hôm nay mình giới thiệu sản phẩm mới nhất...
Bot: 🎬 Đang tạo video lipsync... Khi hoàn thành sẽ gửi kết quả về đây!
```

## 📁 Cấu trúc thư mục

```
bizcity-tool-heygen/
├── bizcity-tool-heygen.php     ← Main plugin + Intent Provider
├── bootstrap.php               ← Load order
├── index.php
├── README.md
├── INTENT-SKELETON.md
├── assets/
│   └── index.php
├── includes/
│   ├── class-database.php      ← 2 bảng: characters + jobs
│   ├── class-tools-heygen.php  ← Tool callbacks (3 tools)
│   ├── class-cron-chat.php     ← Cron poll + webchat push
│   ├── class-ajax-heygen.php   ← AJAX handlers (profile)
│   └── index.php
├── lib/
│   ├── heygen_api.php          ← HeyGen API wrapper
│   └── index.php
└── views/
    ├── page-heygen-profile.php ← 4-tab profile view
    └── index.php
```

## 🗄️ Database

### `{prefix}bizcity_tool_heygen_characters`
Nhân vật AI: name, slug, voice_id, voice_clone_status, avatar_id, image_url, persona_prompt, tone_of_voice, language, default_cta, status.

### `{prefix}bizcity_tool_heygen_jobs`
Video jobs: character_id, job_key, task_id, script, mode, video_url, media_url, status (draft/queued/processing/completed/failed), checkpoints, metadata (session_id, chat_id, conversation_id).

## ⚙️ HeyGen API

- **Voice Clone**: `POST /v2/voices/clone` (audio file → voice_id)
- **Create Video**: `POST /v2/video/generate` (avatar + voice + script → task_id)
- **Poll Status**: `GET /v1/video_status.get?video_id={task_id}` → video_url
- **Cron Poll**: 20s interval, max 120 polls (~40 phút)

## 📝 License

Proprietary — BizCity © 2026
