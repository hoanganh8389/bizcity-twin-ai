# Gemini Knowledge — INTENT SKELETON

## Plugin Overview
- **Name**: Gemini Knowledge – Trợ lý Kiến thức AI
- **Type**: Knowledge Pipeline Override (NOT an execution-mode agent)
- **Model**: Google Gemini (via OpenRouter)
- **Purpose**: Replace built-in Knowledge Pipeline with Gemini-powered responses

## Architecture

### How It Works (Flow)

```
User Message
    ↓
Mode Classifier → classifies as "knowledge" mode
    ↓
Pipeline Registry → finds BizCity_Gemini_Knowledge_Pipeline (overridden)
    ↓
Gemini Knowledge Pipeline:
    1. Build system prompt (detailed knowledge instructions)
    2. Gather RAG context (character knowledge base)
    3. Gather agent knowledge (from registered providers)
    4. Build conversation history
    5. Call Gemini directly via bizcity_openrouter_chat()
    6. Return action=reply (DIRECT — bypasses Chat Gateway default model)
    ↓
Chat Gateway receives complete reply → sends to user
```

### KEY Difference from Built-in Knowledge Pipeline

| Aspect | Built-in (BizCity_Knowledge_Pipeline) | Gemini Override |
|--------|---------------------------------------|-----------------|
| Model | DeepSeek (via Chat Gateway default) | Gemini 2.5 Flash |
| Action | `compose` → delegate to Chat Gateway | `reply` → direct response |
| Max tokens | ~3000 (Chat Gateway limit) | 8000 (configurable) |
| Temperature | 0.4 | 0.55 |
| Response style | Short, concise | Detailed, structured, ChatGPT-like |
| Fallback | None | Falls back to compose if Gemini fails |

### Pipeline Override Mechanism

```php
// In bootstrap (bizcity-gemini-knowledge.php):
add_action( 'bizcity_mode_register_pipelines', function( $registry ) {
    $registry->register( new BizCity_Gemini_Knowledge_Pipeline() );
    // This REPLACES the built-in knowledge pipeline
    // because $registry->pipelines['knowledge'] gets overwritten
} );
```

## Files

| File | Purpose |
|------|---------|
| `bizcity-gemini-knowledge.php` | Bootstrap — constants, requires, hooks |
| `includes/class-gemini-knowledge-pipeline.php` | **CORE** — Gemini Knowledge Pipeline (overrides built-in) |
| `includes/class-gemini-knowledge.php` | API helper — standalone Gemini ask(), settings, search logging |
| `includes/class-intent-provider.php` | Intent Provider — profile context, no execution goals |
| `includes/install.php` | DB tables: search_history, bookmarks |
| `includes/topics.php` | Suggested topics & questions data |
| `includes/admin-menu.php` | Admin menu registration |
| `includes/admin-dashboard.php` | Admin pages: Dashboard, Ask, History, Bookmarks, Settings |
| `includes/ajax.php` | AJAX handlers: ask, bookmark CRUD |
| `includes/shortcode.php` | `[bizcity_knowledge]` shortcode |
| `includes/integration-chat.php` | Chat hooks, keyword detection, post-response filter |
| `includes/knowledge-binding.php` | Knowledge character binding (RAG link) |
| `views/` | Landing page, full page template |
| `assets/` | CSS + JS (admin & public) |

## Configuration

### Admin Settings (`⚙️ Cài đặt`)
- **Model**: Select Gemini variant (Flash, Pro, etc.)
- **Temperature**: 0 (factual) to 1.5 (creative)
- **Max Tokens**: Output token limit (default: 8000)
- **Knowledge Character**: RAG binding to bizcity-knowledge

### Available Models
- `google/gemini-2.5-flash` — Fast, high-quality (default)
- `google/gemini-2.0-flash-001` — Balanced (fallback)
- `google/gemini-pro-1.5` — Highest quality, 2M context
- `google/gemini-flash-1.5` — Fastest

## Goal Patterns

This plugin does NOT register execution goals. It works through mode override:

1. Mode Classifier detects `knowledge` mode (built-in + extended patterns)
2. Extended patterns via `bizcity_mode_knowledge_patterns` filter:
   - `tìm hiểu`, `nghiên cứu`, `phân tích`, `so sánh`
   - `viết bài`, `tóm tắt`, `trình bày`, `liệt kê`
   - `chi tiết`, `cụ thể`, `giải thích rõ`
   - `định nghĩa`, `khái niệm`, `ý nghĩa`
   - `thông tin về`, `tìm kiếm`, `tra cứu`
   - etc.

## Shortcode Usage

```
[bizcity_knowledge]
[bizcity_knowledge theme="dark"]
[bizcity_knowledge show_topics="no" placeholder="Hỏi gì đi..."]
```

## Landing Page

URL: `/gemini-knowledge/`
Shortcode: `[bizcity_knowledge_landing]`

## Future Plans
- [ ] Streaming responses (SSE via bizcity_openrouter_chat_stream)
- [ ] Image understanding (Gemini's vision capability)
- [ ] Web search grounding
- [ ] Multi-turn conversation memory
- [ ] Topic auto-categorization
- [ ] Usage analytics dashboard
