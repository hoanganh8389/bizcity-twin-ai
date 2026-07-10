# Knowledge Router — Intent-based Provider Delegation

> **Version:** 3.2.0
> **Created:** 2026-03-02
> **Updated:** 2026-03-03
> **Status:** Code sẵn sàng local, cần deploy lên production

---

## 1. Vấn đề gốc (v3.1.0)

Khi user nói **"dùng chatgpt để viết kịch bản..."**, hệ thống route sang **Gemini** thay vì **ChatGPT**, mặc dù cả 2 plugin đều đã kích hoạt.

**Root Cause:** `class-knowledge-router.php` chưa deploy → thiếu `BizCity_Knowledge_Provider_Registry` → both plugins fallback `$registry->register()` trực tiếp → last-write-wins → Gemini luôn thắng.

---

## 2. Yêu cầu mở rộng (v3.2.0)

2 plugin Gemini + ChatGPT phục vụ người dùng hỏi đáp, soạn content, kịch bản, nghiên cứu bằng model khác nhau. Cần routing thông minh thay vì chỉ chọn 1:

| Tình huống | Hành vi mong muốn |
|---|---|
| Không có plugin nào active | Built-in compose (Chat Gateway default) |
| 1 plugin active | Dùng làm **mở rộng** → câu trả lời đầy đủ hơn |
| 2 plugin active, generic query | Preferred trả lời + **gợi ý**: "Bạn có muốn xem thêm từ [provider kia] không?" |
| Explicit mention + knowledge action | **Direct execution** qua provider pipeline, log vào `intent_conversations` |

---

## 3. Kiến trúc 4-Scenario Router (v3.2.0)

### 3.1 Routing Decision Tree

```
User message
    │
    ▼
detect_explicit_provider()
    │
    ├─ NULL (no mention) ──────────────────────────┐
    │                                               │
    │                                    count(providers)?
    │                                    ├─ 0 → S0: fallback_compose()
    │                                    ├─ 1 → S1: EXPANSION
    │                                    └─ 2+→ S2: MULTI+SUGGEST
    │
    ├─ 'chatgpt' (available) ─────────→ S3: EXPLICIT EXECUTION
    │                                    ├─ pipeline->process()
    │                                    ├─ log_provider_execution()
    │                                    └─ return reply
    │
    ├─ 'gemini' (available) ──────────→ S3: EXPLICIT EXECUTION
    │
    └─ 'chatgpt' (NOT available) ────→ S3b: UNAVAIL
                                         ├─ fallback to preferred
                                         └─ prepend "⚡ ChatGPT chưa kích hoạt..."
```

### 3.2 Scenario Details

#### S0: No Plugin Active
- Router không được gọi (built-in `BizCity_Knowledge_Pipeline` vẫn hoạt động)
- Action: `compose` → Chat Gateway default model

#### S1: Single Plugin — Expansion Mode
```
User: "blockchain là gì?" (only Gemini active)
  → Gemini trả lời đầy đủ (GPT-4o hoặc Gemini 2.5 Flash)
  → meta.expansion = true
  → Câu trả lời fuller hơn built-in compose
```

#### S2: Multiple Plugins — Preferred + Suggest
```
User: "tư vấn chế độ ăn cho bé"  (both active)
  → Preferred provider (Gemini, pri=10) trả lời
  → Append:
    ---
    💡 *Câu trả lời trên sử dụng Google Gemini. Bạn đang có thêm
    **OpenAI ChatGPT** — muốn xem thêm góc nhìn khác không?*
    *Gõ:* `dùng chatgpt [câu hỏi]` *để chuyển sang provider khác.*
```

#### S3: Explicit Mention — Direct Execution
```
User: "dùng chatgpt viết kịch bản giới thiệu khóa học dinh dưỡng"
  → detect_explicit_provider() → 'chatgpt'
  → has_knowledge_action() → true ("viết kịch bản")
  → ChatGPT pipeline process() → GPT-4o
  → log_provider_execution() → intent_conversations turn (role='tool')
  → Return reply directly
```

**Knowledge Action Keywords** (triggers S3 tool-like execution):
- viết/soạn/tạo/làm + kịch bản/bài/nội dung/content/script
- phân tích/analyze/đánh giá
- tìm hiểu/nghiên cứu/research
- giải thích/explain
- tóm tắt/summarize/tổng hợp
- so sánh/compare
- lập kế hoạch/plan/chiến lược
- viết code/lập trình
- dịch/translate
- review/kiểm tra

---

## 4. Intent Conversations Logging (S3)

Khi explicit mention + knowledge action → log vào `bizcity_intent_turns`:

```json
{
  "conversation_id": "conv_xxx",
  "role": "tool",
  "content": "[full reply from provider]",
  "intent": "knowledge_chatgpt",
  "tool_calls": [{
    "name": "knowledge_provider_chatgpt",
    "result": {
      "success": true,
      "provider": "chatgpt",
      "provider_label": "OpenAI ChatGPT",
      "model": "openai/gpt-4o",
      "tokens": { "prompt_tokens": 1200, "completion_tokens": 800 },
      "reply_length": 2500,
      "has_action": true,
      "action_type": "direct_execution"
    }
  }],
  "meta": {
    "pipeline": "knowledge-router-s3",
    "provider": "chatgpt",
    "model": "openai/gpt-4o",
    "explicit": true,
    "has_action": true
  }
}
```

### Router Event Log (admin console):
```json
{
  "step": "knowledge_provider_exec",
  "mode": "knowledge",
  "provider": "chatgpt",
  "provider_label": "OpenAI ChatGPT",
  "model": "openai/gpt-4o",
  "has_action": true,
  "reply_length": 2500
}
```

---

## 5. Key Files

### 5.1 bizcity-intent (MU Plugin) — v3.2.0

| File | Thay đổi |
|---|---|
| `bootstrap.php` | VERSION 3.2.0 |
| `includes/class-knowledge-router.php` | **Full rewrite**: 4-scenario routing, `has_knowledge_action()`, `log_provider_execution()`, `build_provider_suggestion()`, `enrich_meta()` |
| `includes/class-intent-engine.php` | Enriched `pipeline_process` log (từ v3.1.0) |

### 5.2 Plugins (không thay đổi)

| Plugin | Registration |
|---|---|
| `bizcity-gemini-knowledge` | `register_provider('gemini', ..., priority=10)` |
| `bizcity-chatgpt-knowledge` | `register_provider('chatgpt', ..., priority=20)` |

---

## 6. Provider Detection Patterns

### Built-in (class-knowledge-router.php)

```php
PROVIDER_DETECT_PATTERNS = [
    'gemini'  => [
        '/\b(gemini|google\s*ai|google\s*gemini)\b/ui',
        '/\b(dùng|hỏi|sử\s+dụng)\s+(gemini|google\s*ai)\b/ui',
        '/\b(gemini)\s*(ơi|cho|giúp|trả\s*lời|cho\s*biết)\b/ui',
    ],
    'chatgpt' => [
        '/\b(chatgpt|chat\s*gpt|gpt[-\s]?4o?|openai)\b/ui',
        '/\b(dùng|hỏi|sử\s+dụng)\s+(chatgpt|chat\s*gpt|gpt|openai)\b/ui',
        '/\b(chatgpt|gpt)\s*(ơi|cho|giúp|trả\s*lời|cho\s*biết)\b/ui',
    ],
];
```

### Knowledge Action Patterns

```php
KNOWLEDGE_ACTION_PATTERNS = [
    '/\b(viết|soạn|tạo|làm)\s+(kịch\s*bản|bài|nội\s*dung|content|script|outline)/ui',
    '/\b(phân\s*tích|analyze|đánh\s*giá)/ui',
    '/\b(tìm\s*hiểu|nghiên\s*cứu|research)/ui',
    '/\b(giải\s*thích|explain)/ui',
    '/\b(tóm\s*tắt|summarize|tổng\s*hợp)/ui',
    '/\b(so\s*sánh|compare)/ui',
    '/\b(lập\s*kế\s*hoạch|plan|chiến\s*lược|strategy|roadmap)/ui',
    '/\b(viết\s*code|viết\s*hàm|code|lập\s*trình)/ui',
    '/\b(dịch|translate|chuyển\s*ngữ)/ui',
    '/\b(review|kiểm\s*tra|check|rà\s*soát)/ui',
];
```

---

## 7. Deploy Checklist

### Files cần deploy

```
bizcity-intent/
  ├── bootstrap.php                          ← VERSION 3.2.0
  └── includes/
      ├── class-knowledge-router.php         ← Full rewrite: 4-scenario
      └── class-intent-engine.php            ← Enriched pipeline_process log
```

### Verify sau deploy

1. **S1 test** (disable ChatGPT, only Gemini): "AI là gì?"
   - Expect: Gemini answers, `meta.expansion = true`

2. **S2 test** (both active): "blockchain là gì?"
   - Expect: Preferred answers + suggestion appears at bottom
   - Look for: `💡 Câu trả lời trên sử dụng Google Gemini...`

3. **S3 test** (both active, explicit): "dùng chatgpt viết kịch bản"
   - Expect: ChatGPT answers, no suggestion
   - `pipeline_process.knowledge_provider = 'chatgpt'`
   - `knowledge_provider_exec` log event appears
   - Turn logged in `intent_turns` with `role='tool'`

4. **S3 test** (both active, explicit): "gemini phân tích thị trường"
   - Expect: Gemini answers directly

5. **S3b test** (only Gemini, explicit ChatGPT): "dùng chatgpt soạn bài"
   - Expect: "⚡ ChatGPT chưa kích hoạt..." + Gemini fallback

### error_log entries

```
[KNOWLEDGE-ROUTER] S3:EXPLICIT target=chatgpt explicit=Y available=[gemini,chatgpt] preferred=gemini msg="dùng chatgpt viết kịch bản..."
[KNOWLEDGE-ROUTER] S2:MULTI+SUGGEST target=gemini explicit=N available=[gemini,chatgpt] preferred=gemini msg="blockchain là gì?"
[KNOWLEDGE-ROUTER] S1:EXPANSION target=gemini explicit=N available=[gemini] preferred=gemini msg="AI là gì?"
```

---

## 8. Architecture Diagram

```
┌────────────────────────────────────────────────────────┐
│                    User Message                         │
└─────────────────────┬──────────────────────────────────┘
                      │
                      ▼
┌────────────────────────────────────────────────────────┐
│            Mode Classifier → knowledge                  │
│   (patterns from HOOK 2, pri 5: "dùng chatgpt" 0.92)  │
└─────────────────────┬──────────────────────────────────┘
                      │
                      ▼
┌────────────────────────────────────────────────────────┐
│        Knowledge Router Pipeline (HOOK 1, pri 999)      │
│                                                         │
│  detect_explicit_provider(message)                      │
│         │                                               │
│   ┌─────┴──────────────────────────────┐                │
│   │                                    │                │
│   ▼ explicit_id != null          ▼ null                 │
│   │                              │                      │
│   │  provider available?     count(providers)?          │
│   │  ├─ YES → S3:EXEC       ├─ 0  → S0:COMPOSE         │
│   │  │   ├─ pipeline()      ├─ 1  → S1:EXPANSION       │
│   │  │   ├─ log_exec()      └─ 2+ → S2:SUGGEST         │
│   │  │   └─ return reply         │                      │
│   │  │                           ├─ preferred.process()  │
│   │  └─ NO → S3b:UNAVAIL        └─ append suggestion    │
│   │      ├─ fallback preferred                          │
│   │      └─ prepend "⚡ ..."                            │
│   │                                                     │
└───┴─────────────────────────────────────────────────────┘
                      │
     ┌────────────────┼────────────────┐
     ▼                ▼                ▼
┌──────────┐  ┌──────────────┐  ┌────────────┐
│  Gemini  │  │   ChatGPT    │  │  Built-in  │
│ Pipeline │  │  Pipeline    │  │  Compose   │
│          │  │              │  │            │
│ Gemini   │  │ GPT-4o via   │  │ Chat GW   │
│ 2.5Flash │  │ OpenRouter   │  │ default    │
└──────────┘  └──────────────┘  └────────────┘
     │                │
     └───────┬────────┘
             ▼
┌────────────────────────────────┐
│   intent_conversations turns   │
│   (role='tool' for S3)         │
│   (role='assistant' for S1/S2) │
└────────────────────────────────┘
```

---

## 9. Test Messages Matrix

| Message | Scenario | Provider | Has Action | Suggestion |
|---|---|---|---|---|
| "AI là gì?" (both) | S2 | gemini (preferred) | N | ✅ gợi ý chatgpt |
| "AI là gì?" (only gemini) | S1 | gemini | N | ❌ |
| "dùng chatgpt viết kịch bản" | S3 | chatgpt | ✅ | ❌ |
| "gemini phân tích SEO" | S3 | gemini | ✅ | ❌ |
| "hỏi chatgpt về React" | S3 | chatgpt | ❌ | ❌ |
| "chatgpt ơi giúp tôi tóm tắt" | S3 | chatgpt | ✅ | ❌ |
| "viết bài về marketing" (both) | S2 | gemini (preferred) | N/A | ✅ gợi ý chatgpt |
| "dùng chatgpt..." (chatgpt off) | S3b | gemini (fallback) | N/A | ❌ + note |

---

## 10. Code Changes History

### v3.1.0 (2026-03-02)
- Added `class-knowledge-router.php` with Registry + Router + 2 hooks
- Enriched `pipeline_process` log in `class-intent-engine.php`
- Version bump to 3.1.0

### v3.2.0 (2026-03-03)
- **Full rewrite** `BizCity_Knowledge_Router_Pipeline::process()` — 4-scenario branching (S0/S1/S2/S3/S3b)
- Added `KNOWLEDGE_ACTION_PATTERNS` constant (10 Vietnamese + English knowledge action verbs)
- Added `has_knowledge_action()` method
- Added `log_provider_execution()` — logs as `role='tool'` turn in `intent_conversations`
- Added `build_provider_suggestion()` — builds markdown suggestion for S2
- Added `enrich_meta()` helper
- Added `log_routing()` helper with scenario labels
- Version bump to 3.2.0
