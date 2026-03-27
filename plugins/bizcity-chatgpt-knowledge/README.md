# ChatGPT Knowledge – Trợ lý Kiến thức AI

AI Agent chuyên trả lời kiến thức chuyên sâu — powered by OpenAI ChatGPT (GPT-4o).

## Tính năng

- 🧠 **Trả lời chi tiết**: Câu trả lời dài, đầy đủ, có cấu trúc
- 🔍 **Đa lĩnh vực**: Công nghệ, kinh doanh, sức khỏe, giáo dục, pháp luật...
- 📚 **RAG Integration**: Tích hợp Knowledge Base cho câu trả lời chính xác
- 🔖 **Bookmarks**: Lưu câu trả lời hay để xem lại
- 📊 **Analytics**: Theo dõi lịch sử tìm kiếm và token usage
- ⚙️ **Configurable**: Chọn model ChatGPT, điều chỉnh temperature/tokens

## Kiến trúc

Plugin hoạt động bằng cách **override knowledge pipeline** mặc định:

1. Khi user hỏi câu hỏi kiến thức → Mode Classifier classify là `knowledge`
2. Pipeline Registry tìm pipeline cho mode `knowledge`
3. **ChatGPT Knowledge Pipeline** (đã override built-in) xử lý:
   - Gọi trực tiếp ChatGPT qua OpenRouter
   - Trả về `action=reply` (direct response, không qua Chat Gateway)
4. User nhận câu trả lời chi tiết từ ChatGPT

## Cài đặt

1. Upload plugin vào `/wp-content/plugins/bizcity-chatgpt-knowledge/`
2. Activate plugin trong WordPress admin
3. Vào **🧠 ChatGPT AI → ⚙️ Cài đặt** để cấu hình model
4. (Optional) Liên kết Knowledge Character cho RAG

## Shortcode

```
[bizcity_chatgpt_knowledge]
```

## So sánh với Gemini Knowledge

| Aspect | Gemini Knowledge | ChatGPT Knowledge |
|--------|-----------------|-------------------|
| Provider | Google Gemini | OpenAI ChatGPT |
| Model mặc định | Gemini 2.5 Flash | GPT-4o |
| Context window | 1M-2M tokens | 128K-1M tokens |
| Prefix | `bzgk_` | `bzck_` |
| DB tables | `bzgk_search_history` | `bzck_search_history` |

## Cài đồng thời

Cả hai plugin có thể cài đồng thời. Plugin nào đăng ký **sau** trong `bizcity_mode_register_pipelines` 
sẽ là plugin xử lý knowledge mode. Tương lai sẽ có router thông minh để chọn provider theo context.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- bizcity-openrouter (mu-plugin) — OpenRouter API gateway
- bizcity-intent (mu-plugin) — Intent Engine + Mode Pipeline
