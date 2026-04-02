# BizCity Twin Plugin Standard — v2.0

> **Tiêu chuẩn chung cho tất cả plugin tích hợp BizCity Twin Core**  
> **Phiên bản**: 2.0 | **Thời gian**: 2026-03-27  
> **Scope**: Plugin architecture, Provider contracts, API standardization, SSE streaming, UI/UX patterns  
> **Áp dụng cho**: bizcity-tool-* | bizcity-automation-* | bizcity-studio-* | legacy integration

---

## 1. Overview — 3 Layer Plugin Architecture

```
┌──────────────────────────────────────────────────────────────┐
│            TWIN PLUGIN ARCHITECTURE (3 LAYER)                │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  🎨 LAYER 1: UI/UX — Touch Bar + Agent Profile              │
│     ├─ Touch Bar iframe → guided commands (/slash notation)  │
│     ├─ Quick Chat shortcuts → payload standardization        │
│     └─ Welcome shortcuts (React) → mention @tool reference   │
│                                                               │
│  ⚙️  LAYER 2: Intent & Automation Provider                   │
│     ├─ Intent mapping (chat/execution/planning modes)        │
│     ├─ Tool registration in Twin Core registry               │
│     ├─ Slot collection + Planner hooks                       │
│     └─ Job Trace for step-by-step execution                 │
│                                                               │
│  🎬 LAYER 3: Studio Provider & Output Embedding              │
│     ├─ Output formatter (markdown/rich HTML)                 │
│     ├─ Studio component (React) for preview/edit             │
│     ├─ Persistence to Notebook                               │
│     └─ Export & Share integration                            │
│                                                               │
│  📡 SSE MODE: Real-time Streaming                            │
│     ├─ Job events ("step_complete", "tool_executed", etc)   │
│     ├─ Frontend listens & renders live progress              │
│     └─ Error recovery & state sync                          │
│                                                               │
│  🔌 API GATEWAY: Plugin Registry & Bridge                    │
│     ├─ Plugin discovery (featured/automation/studio flags)   │
│     ├─ API method extraction (WordPress REST / custom RPC)   │
│     └─ Tool contract validation (inputs/outputs schemas)     │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

---

## 2. Core Principles

✅ **Slash Command Standard**: All quick prompts and shortcuts MUST resolve to `/tool_name base_text` format

✅ **Capability Declaration**: Every plugin MUST declare machine-readable metadata (featured, automation_ready, studio_ready, sse_streaming, api_version)

✅ **Unified Output Envelope**: Tool execution MUST follow ONE output structure + ONE SSE event contract

✅ **Mode-Aware Context**: Context injection MUST use Twin resolver contracts (chat/knowledge/execution/planning/emotion)

✅ **Contract-First APIs**: All plugin APIs MUST be discoverable via Gateway + standardized inspection

✅ **Payload Standardization**: postMessage from profile MUST include: type, source, plugin_slug, tool_name, text

---

## 3. Quick Prompt Standard — Slash + Mention

All clickable shortcut elements MUST build and send slash commands:

### HTML Markup (page-agent-profile.php)

```html
<div data-msg="Viết bài về dinh dưỡng" data-tool="tool_content" data-plugin="bizcity-tool-content"></div>
```

Client send payload standard:

```js
window.parent.postMessage({
  type: 'bizcity_agent_command',
  source: 'bizcity-tool-content',
  plugin_slug: 'bizcity-tool-content',
  tool_name: 'write_article',
  text: '/write_article Viết bài về dinh dưỡng'
}, '*');
```

Normalization rule:

- If text does not start with / and tool_name exists, prepend `/{tool_name}`.
- If source exists but tool_name missing, receiver may infer main_tool from plugin metadata.

---

## 4) Intent Provider Standard

Intent provider must define:

- id, name, patterns, plans, tools
- capabilities block (section 2)
- context() for mode-aware context enrichment
- optional gateway contract mapping (section 9)

Minimal tools schema requirement:

```php
'tools' => [
  'write_article' => [
    'label' => 'Write and publish article',
    'schema' => [
      'description' => 'Generate article and publish to WordPress',
      'input_fields' => [
        'topic' => [ 'required' => true, 'type' => 'text' ],
      ],
    ],
    'callback' => [ 'BizCity_Tool_Content', 'write_article' ],
  ],
],
```

---

## 5) Automation Provider Standard

Automation provider must expose:

- node catalog entry with stable node_id
- input/output schema
- deterministic tool binding to main_tool and secondary tools
- compensation strategy for retry/failure

Required fields:

```php
[
  'node_id' => 'tool-content.write_article',
  'tool_name' => 'write_article',
  'provider_id' => 'tool-content',
  'supports_retry' => true,
  'supports_idempotency' => true,
]
```

---

## 6) Studio Provider Standard

Studio integration must declare:

- available actions
- input panel schema
- preview renderer
- output artifact type

Required metadata:

```php
[
  'studio_action' => 'content.write',
  'artifact_type' => 'article',
  'editable' => true,
  'stream_mode' => 'step+delta',
]
```

---

## 7) BizChat Menu Registration Standard

Use centralized hook:

```php
add_action('bizchat_register_menus', function($menu) {
  $menu->add([
    'id' => 'tool-content',
    'title' => 'Tool Content',
    'parent' => 'bizchat',
    'capability' => 'read',
    'position' => 80,
  ]);
});
```

Rules:

- Do not register direct top-level menu for BizChat tools.
- Keep menu IDs stable and slug-safe.

---

## 8) SSE Stream Mode Standard

Tool pipelines should emit stream events in this order:

1. trace_begin
2. step events (pending/running/done or failed)
3. optional partial delta events
4. trace_end

Common event payload:

```json
{
  "event": "tool_trace",
  "tool_name": "write_article",
  "step": "T2",
  "status": "running",
  "message": "Generating outline"
}
```

Requirements:

- send `tool_name` and stable `trace_id`
- include status transitions, not only done state
- send fail event before terminating stream on error

---

## 9) Context Contract (Twin Resolver)

All AI-calling tool callbacks must consume `_meta`:

```php
$meta       = $slots['_meta'] ?? [];
$ai_context = $meta['_context'] ?? '';
```

Context usage policy:

- append ai_context to system prompt when non-empty
- do not mutate or return `_meta` in tool output
- use mode-aware behavior (emotion/knowledge/planning/execution/studio)

---

## 10) API Integration Contract (Gateway Bridge)

Every tool should optionally define an API contract block to support gateway orchestration:

```php
'gateway_contract' => [
  'contract_version' => '1.0',
  'endpoint' => '/tool-content/v1/write-article',
  'method' => 'POST',
  'input_schema_ref' => 'tool-content.write-article.input',
  'output_schema_ref' => 'tool-content.write-article.output',
  'hil_points' => [ 'before_publish' ],
],
```

Benefits:

- API-first wrappers for legacy WordPress handlers
- deterministic registry map from contract
- HIL and slot collection inferred from schema

---

## 11) Loader Information Standard

Plugin loader should expose normalized info object:

```php
[
  'slug' => 'bizcity-tool-content',
  'role' => 'tool',
  'featured' => true,
  'supports_studio' => true,
  'supports_automation' => true,
  'main_tool' => 'write_article',
  'template_page' => 'tool-content',
]
```

---

## 12) Legacy Wrapper Standard

For old plugins in WordPress legacy:

- keep old callback intact
- wrap with contract adapter
- map old args to schema input
- map old result to output envelope

Wrapper naming:

- `Legacy_{Plugin}_Gateway_Adapter`
- `Legacy_{Plugin}_Contract_Map`

---

## 13) Compliance Checklist

- Shortcut click sends slash-prefixed message
- Shortcut payload includes tool_name + plugin_slug
- Provider declares capabilities
- main_tool declared and valid in tools map
- SSE emits trace begin/step/end
- Tool callback reads `_meta` and `_context`
- Tool output envelope contract satisfied
- Menu registered via bizchat_register_menus
- Optional API contract block added

---

## 14) Migration Plan for Existing Plugins

1. Add capabilities and main_tool metadata
2. Add shortcut payload normalization in profile pages
3. Add tool_name/plugin_slug to postMessage payloads
4. Add gateway_contract block for each main tool
5. Add automation/studio metadata
6. Verify SSE event compliance
7. Register BizChat menu entry

---

## 15) References

- core/intent/PLUGIN-STANDARD.md
- plugins/bizcity-tool-content/PLUGIN-STANDARD.md
- scaffold/views/page-agent-profile.php
- IMPLEMENTATION-ROADMAP.md
