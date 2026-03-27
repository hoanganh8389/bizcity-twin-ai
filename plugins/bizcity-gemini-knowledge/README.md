# Gemini Knowledge – Trợ lý Kiến thức AI

AI Agent chuyên trả lời kiến thức chuyên sâu, tìm kiếm thông tin, giải thích chi tiết — powered by Google Gemini.

## Tính năng

- 🧠 **Trả lời chi tiết**: Câu trả lời dài, đầy đủ, có cấu trúc như ChatGPT
- 🔍 **Đa lĩnh vực**: Công nghệ, kinh doanh, sức khỏe, giáo dục, pháp luật...
- 📚 **RAG Integration**: Tích hợp Knowledge Base cho câu trả lời chính xác
- 🔖 **Bookmarks**: Lưu câu trả lời hay để xem lại
- 📊 **Analytics**: Theo dõi lịch sử tìm kiếm và token usage
- ⚙️ **Configurable**: Chọn model Gemini, điều chỉnh temperature/tokens

## Kiến trúc

Plugin hoạt động bằng cách **override knowledge pipeline** mặc định:

1. Khi user hỏi câu hỏi kiến thức → Mode Classifier classify là `knowledge`
2. Pipeline Registry tìm pipeline cho mode `knowledge`
3. **Gemini Knowledge Pipeline** (đã override built-in) xử lý:
   - Gọi trực tiếp Gemini qua OpenRouter
   - Trả về `action=reply` (direct response, không qua Chat Gateway)
4. User nhận câu trả lời chi tiết từ Gemini

## Cài đặt

1. Upload plugin vào `/wp-content/plugins/bizcity-gemini-knowledge/`
2. Activate plugin trong WordPress admin
3. Vào **🧠 Knowledge AI → ⚙️ Cài đặt** để cấu hình model
4. (Optional) Liên kết Knowledge Character cho RAG

## Shortcode

```
[bizcity_knowledge]
```

## Requirements

- WordPress 6.0+
- PHP 8.0+
- bizcity-openrouter (mu-plugin) — OpenRouter API gateway
- bizcity-intent (mu-plugin) — Intent Engine + Mode Pipeline
