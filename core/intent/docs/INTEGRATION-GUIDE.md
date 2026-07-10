# BizCity Intent — Integration Guide

## Kiến trúc tổng quan

```
User Message (any channel)
       │
       ▼
┌─────────────────────────────────┐
│   BizCity Intent Engine         │
│   (Router + State Machine)      │
│                                 │
│  ┌─────────────┐ ┌───────────┐ │
│  │ Intent      │ │ Conver-   │ │
│  │ Router      │ │ sation    │ │
│  │ (classify)  │ │ Manager   │ │
│  └──────┬──────┘ └─────┬─────┘ │
│         │              │       │
│  ┌──────▼──────────────▼─────┐ │
│  │   Flow Planner            │ │
│  │   (next action?)          │ │
│  └──────┬────────────┬───────┘ │
│         │            │         │
│  ┌──────▼──────┐ ┌───▼───────┐ │
│  │ Tool        │ │ Stream    │ │
│  │ Registry    │ │ Adapter   │ │
│  │ (execute)   │ │ (SSE/batch│ │
│  └─────────────┘ └───────────┘ │
└──────────────────┬──────────────┘
                   │
         ┌─────────┴──────────┐
         │                    │
    ┌────▼────┐         ┌─────▼─────┐
    │ Chat    │         │ OpenRouter│
    │ Gateway │         │ (stream)  │
    │ (Brain) │         │           │
    └─────────┘         └───────────┘
```

## File Structure

```
mu-plugins/
  bizcity-intent.php                      ← MU loader
  bizcity-intent/
    bootstrap.php                          ← Constants, class loading, helpers
    index.php                              ← Silence
    includes/
      class-intent-database.php            ← DB tables (conversations + turns)
      class-intent-conversation.php        ← Conversation lifecycle + slots
      class-intent-router.php              ← Hybrid intent classification
      class-intent-planner.php             ← Goal plans + slot requirements
      class-intent-tools.php               ← Tool registry + built-in bridges
      class-intent-stream.php              ← SSE + batch adapters
      class-intent-engine.php              ← Main orchestrator
```

## Database Tables

### `{prefix}bizcity_intent_conversations`

| Column            | Type         | Description                                    |
|-------------------|--------------|------------------------------------------------|
| conversation_id   | VARCHAR(64)  | UUID — primary lookup key                      |
| user_id           | BIGINT       | WP user ID (0 = guest)                         |
| session_id        | VARCHAR(255) | Session ID (for anonymous users)               |
| channel           | VARCHAR(50)  | webchat / adminchat / zalo / telegram / facebook |
| character_id      | INT          | AI character ID                                |
| goal              | VARCHAR(100) | Goal identifier: create_product, tarot_reading... |
| status            | VARCHAR(20)  | ACTIVE / WAITING_USER / COMPLETED / CLOSED / EXPIRED |
| slots_json        | LONGTEXT     | JSON object with collected slot values         |
| waiting_for       | TEXT         | What we're waiting for: text / image / choice  |
| waiting_field     | VARCHAR(100) | Specific slot field we need                    |
| rolling_summary   | TEXT         | LLM-generated conversation summary             |
| turn_count        | INT          | Number of turns                                |
| last_activity_at  | DATETIME     | Auto-expire after 30 min inactive              |

### `{prefix}bizcity_intent_turns`

| Column          | Type        | Description                              |
|-----------------|-------------|------------------------------------------|
| conversation_id | VARCHAR(64) | FK to conversations                      |
| turn_index      | INT         | Sequential index (0-based)               |
| role            | VARCHAR(20) | user / assistant / system / tool         |
| content         | LONGTEXT    | Message text                             |
| attachments     | LONGTEXT    | JSON array of image URLs                 |
| intent          | VARCHAR(50) | Classified intent for this turn          |
| slots_delta     | LONGTEXT    | Slots changed in this turn               |
| tool_calls      | LONGTEXT    | Tool execution records                   |

## Intent Classification Pipeline

```
Message → End Pattern? ──YES──→ end_conversation
    │
    NO
    │
    ▼
WAITING_USER? ──YES──→ provide_input (fill waiting slot)
    │
    NO
    │
    ▼
Has Images? ──YES──→ map to goal (tarot / image slot)
    │
    NO
    │
    ▼
Pattern Match Goals? ──YES──→ new_goal / continue_goal
    │
    NO
    │
    ▼
Active Goal? ──YES──→ continue_goal (confidence 0.6)
    │
    NO
    │
    ▼
LLM Router (fast model) → classify via JSON
    │
    ▼
Default: small_talk → passthrough to AI Brain
```

## How to Register a Custom Tool

```php
// In your plugin
add_action( 'bizcity_intent_register_tools', function( $registry ) {

    $registry->register( 'my_custom_tool', [
        'description'  => 'Mô tả tool',
        'input_fields' => [
            'field1' => [ 'required' => true,  'type' => 'text' ],
            'field2' => [ 'required' => false, 'type' => 'number' ],
        ],
    ], function( array $slots ) {
        // Execute logic
        $result = do_something( $slots['field1'], $slots['field2'] ?? 0 );

        return [
            'success' => true,
            'message' => 'Done! Result: ' . $result,
            'data'    => [ 'output' => $result ],
        ];

        // Or request more info:
        // return [ 'success' => false, 'missing_fields' => ['field2'] ];
    });
});
```

## How to Register a Custom Goal Plan

```php
add_filter( 'bizcity_intent_plans', function( $plans ) {
    $plans['my_goal'] = [
        'required_slots' => [
            'field1' => [
                'type'   => 'text',
                'prompt' => 'Trường 1 là gì?',
            ],
        ],
        'optional_slots' => [],
        'tool'       => 'my_custom_tool',
        'ai_compose' => false,
        'slot_order' => [ 'field1' ],
    ];
    return $plans;
});
```

## How to Add Goal Patterns

```php
add_filter( 'bizcity_intent_goal_patterns', function( $patterns ) {
    $patterns['/custom_pattern|mẫu tùy chỉnh/ui'] = [
        'goal'    => 'my_goal',
        'label'   => 'Tên hiển thị',
        'extract' => [ 'field1' ],
    ];
    return $patterns;
});
```

## SSE Streaming (Webchat)

### JavaScript Client

```javascript
// POST approach with fetch + ReadableStream
async function streamChat(message, characterId, sessionId) {
    const formData = new FormData();
    formData.append('action', 'bizcity_chat_stream');
    formData.append('message', message);
    formData.append('character_id', characterId);
    formData.append('session_id', sessionId);

    const response = await fetch(ajaxurl, {
        method: 'POST',
        body: formData,
    });

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop(); // Keep incomplete line

        for (const line of lines) {
            if (line.startsWith('data: ')) {
                const data = JSON.parse(line.slice(6));
                if (data.delta) {
                    // Append delta to chat bubble
                    appendToChat(data.delta);
                }
            }
            if (line.startsWith('event: done')) {
                // Stream complete
            }
        }
    }
}
```

### EventSource approach

```javascript
const source = new EventSource(
    ajaxurl + '?action=bizcity_chat_stream&message=' +
    encodeURIComponent(message) + '&character_id=' + characterId
);

source.addEventListener('chunk', (e) => {
    const data = JSON.parse(e.data);
    appendToChat(data.delta);
});

source.addEventListener('done', (e) => {
    const data = JSON.parse(e.data);
    source.close();
});

source.addEventListener('error', (e) => {
    source.close();
});
```

## Batch Response (Zalo/Telegram)

```php
// In your hook handler
$stream = BizCity_Intent_Stream::instance();

$response = $stream->batch_response(
    $message,
    $character_id,
    $session_id,
    $images,
    $user_id,
    'ZALO_BOT'
);

// $response['message'] = full AI reply text
send_zalo_reply( $response['message'] );
```

## Direct Engine Usage

```php
// Process a message from any context
$result = bizcity_intent_process([
    'message'      => 'Xem tarot tình cảm hôm nay',
    'session_id'   => 'sess_abc123',
    'user_id'      => 5,
    'channel'      => 'webchat',
    'character_id' => 1,
    'images'       => [],
]);

// $result structure:
// [
//     'reply'           => 'Bạn muốn rút mấy lá? 🃏\n1. 1 lá\n2. 3 lá...',
//     'action'          => 'ask_user',
//     'conversation_id' => 'conv_abc-def-ghi',
//     'goal'            => 'tarot_reading',
//     'status'          => 'WAITING_USER',
//     'slots'           => [ 'question_focus' => 'tinh_cam' ],
//     'meta'            => [ 'intent' => 'new_goal', 'confidence' => 0.9 ],
// ]
```

## Conversation Flow Example: Tarot Reading

```
User: "Xem tarot hôm nay"
  → Router: new_goal (tarot_reading) via pattern match
  → Planner: missing 'question_focus' → ask_user
  → Bot: "Bạn muốn xem về lĩnh vực nào? 🔮"
  → Status: WAITING_USER (waiting_field: question_focus)

User: "Tình cảm"
  → Router: provide_input (WAITING_USER)
  → Slots: { question_focus: "tinh_cam" }
  → Planner: missing 'spread' → ask_user
  → Bot: "Bạn muốn rút mấy lá? 🃏"
  → Status: WAITING_USER (waiting_field: spread)

User: "1 lá"
  → Router: provide_input (WAITING_USER)
  → Slots: { question_focus: "tinh_cam", spread: 1 }
  → Planner: all required filled → compose_answer
  → Passthrough to AI Brain with full context
  → AI generates tarot reading response (streamed via SSE)

User: "Cảm ơn"
  → Router: end_conversation via pattern match
  → Bot: "✅ Đã hoàn thành "Tarot Reading". Cảm ơn bạn!"
  → Status: COMPLETED
```

## OpenRouter Streaming Changes

### New method: `BizCity_OpenRouter::chat_stream()`

```php
$result = BizCity_OpenRouter::instance()->chat_stream(
    $messages,
    [ 'purpose' => 'chat', 'temperature' => 0.7 ],
    function( $delta, $full_text ) {
        // Called for each chunk received
        echo $delta;
        flush();
    }
);
// $result = ['success' => true, 'message' => 'full text', ...]
```

### New purpose: `router`

Used for fast, cheap intent classification:
- Default: `openai/gpt-4o-mini`
- Fallback: `google/gemini-2.0-flash-001`

## Image Handling

All images sent from frontend **MUST** be converted to WordPress Media Library URLs before entering the Intent Engine.

### Why Media Library URLs?

1. **Persistence**: Base64 data URLs are ephemeral, lost after session ends
2. **API Compatibility**: AI Vision APIs (OpenAI, Gemini, Claude) require fetchable URLs
3. **Size**: Base64 strings are too large for API payloads
4. **Standard**: Consistent URL format across all plugins

### Image Flow

```
Frontend (webchat/mobile)
    ↓ base64 / file upload
AJAX Handler (bizcity_chat_gateway_send / stream / ...)
    ↓ bizcity_convert_images_to_media_urls()
WordPress Media Library
    ↓ https://domain/wp-content/uploads/2026/03/image.jpg
Intent Engine → Tool Execution
    ↓ Valid URL passed to plugins
Plugin gets usable URL
```

### Helper Functions

Available in `bizcity-bot-webchat/bootstrap.php`:

```php
// Batch convert array of base64/URLs → Media URLs
$images = bizcity_convert_images_to_media_urls( $images );
// Already-valid URLs are returned unchanged

// Single base64 → attachment
$attachment_id = bizcity_save_base64_to_media( $base64_data_url, 'photo.jpg' );
$url = wp_get_attachment_url( $attachment_id );

// File upload → Media Library
$result = bizcity_upload_to_media_library( $_FILES['image'] );
// Returns ['url' => '...', 'attachment_id' => 123]
```

### Slot Type Image

When defining slots:

```php
'slots' => [
    [
        'id'       => 'photo_url',
        'type'     => 'image',   // ← Indicates this is an image slot
        'prompt'   => 'Gửi ảnh để phân tích',
        'required' => true,
    ],
],
```

**Tool receives Media Library URL (not base64):**

```php
function execute_analyze_photo( $slots ) {
    $photo_url = $slots['photo_url'];  // https://domain/wp-content/uploads/...
    // Use URL with AI Vision API, save to DB, etc.
}
```

**See also:** [INTENT-SKELETON.md](INTENT-SKELETON.md) section 10 for complete checklist.

## Migration from Legacy Flows

The existing `twf_process_flow_from_params()` in bizcity-admin-hook can be gradually migrated:

1. Register legacy tools via `bizcity_intent_tools_ready` action
2. Add goal plans via `bizcity_intent_plans` filter
3. Add goal patterns via `bizcity_intent_goal_patterns` filter
4. The intent engine will automatically route to the registered tools

```php
// Example: Bridge legacy product creation
add_action( 'bizcity_intent_tools_ready', function( $registry ) {
    $registry->register( 'create_product', [...], function( $slots ) {
        return twf_handle_product_post_flow( [
            'type' => 'tao_san_pham',
            'info' => $slots,
        ]);
    });
});
```
