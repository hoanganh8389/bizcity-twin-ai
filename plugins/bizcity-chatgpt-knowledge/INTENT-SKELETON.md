# ChatGPT Knowledge — INTENT SKELETON

## Plugin Overview
- **Name**: ChatGPT Knowledge – Trợ lý Kiến thức AI
- **Type**: Knowledge Pipeline Override (NOT an execution-mode agent)
- **Model**: OpenAI GPT-4o (via OpenRouter)
- **Purpose**: Replace built-in Knowledge Pipeline with ChatGPT-powered responses

## Architecture

### How It Works (Flow)

```
User Message
    ↓
Mode Classifier → classifies as "knowledge" mode
    ↓
Pipeline Registry → finds BizCity_ChatGPT_Knowledge_Pipeline (overridden)
    ↓
ChatGPT Knowledge Pipeline:
    1. Build system prompt (detailed knowledge instructions)
    2. Gather RAG context (character knowledge base)
    3. Gather agent knowledge (from registered providers)
    4. Build conversation history
    5. Call ChatGPT directly via bizcity_openrouter_chat()
    6. Return action=reply (DIRECT — bypasses Chat Gateway default model)
    ↓
Chat Gateway receives complete reply → sends to user
```

### Pipeline Override Mechanism

```php
add_action( 'bizcity_mode_register_pipelines', function( $registry ) {
    $registry->register( new BizCity_ChatGPT_Knowledge_Pipeline() );
} );
```

## Files

| File | Purpose |
|------|---------|
| `bizcity-chatgpt-knowledge.php` | Bootstrap — constants, requires, hooks |
| `includes/class-chatgpt-knowledge-pipeline.php` | **CORE** — ChatGPT Knowledge Pipeline |
| `includes/class-chatgpt-knowledge.php` | API helper — standalone ask(), settings |
| `includes/class-intent-provider.php` | Intent Provider — profile context |
| `includes/install.php` | DB tables: bzck_search_history, bzck_bookmarks |
| `includes/topics.php` | Suggested topics & questions |
| `includes/admin-menu.php` | Admin menu registration |
| `includes/admin-dashboard.php` | Admin pages |
| `includes/ajax.php` | AJAX handlers |
| `includes/shortcode.php` | `[bizcity_chatgpt_knowledge]` shortcode |
| `includes/integration-chat.php` | Chat hooks |
| `includes/knowledge-binding.php` | Knowledge character binding (RAG) |

## Available Models
- `openai/gpt-4o` — High quality, 128K context (default)
- `openai/gpt-4o-mini` — Fast, cost-effective (fallback)
- `openai/gpt-4-turbo` — Powerful, 128K context
- `openai/gpt-4.1` — Latest, 1M context
- `openai/gpt-4.1-mini` — Latest mini, 1M context

## Naming Convention
- Prefix: `bzck_` (BizCity ChatGPT Knowledge)
- Constants: `BZCK_DIR`, `BZCK_URL`, `BZCK_VERSION`, `BZCK_SLUG`
- DB tables: `bzck_search_history`, `bzck_bookmarks`
- AJAX actions: `bzck_ask`, `bzck_public_ask`, `bzck_save_bookmark`, `bzck_delete_bookmark`
- Options: `bzck_settings`, `bzck_db_version`, `bzck_knowledge_character_id`
