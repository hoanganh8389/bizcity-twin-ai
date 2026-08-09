# Dành cho Developer — Tổng quan kiến trúc

> Phần này dành cho developer muốn **extend hoặc tích hợp** với BizCity Twin AI.
> Plugin được thiết kế theo kiến trúc module — bạn có thể thêm tính năng mà
> không sửa core, thông qua hooks, filters và sub-plugin API.

---

## Kiến trúc tổng quan

```
┌────────────────────────────────────────────────────────────────────┐
│                      bizcity-twin-ai (Core Plugin)                  │
│                                                                      │
│  core/                          modules/                             │
│  ├─ agents/       Agent reg.    ├─ twinchat/    Chat UI (React)      │
│  ├─ automation/   Workflow      ├─ twinbrain/   Brain UI             │
│  ├─ bizcity-llm/  Gateway lib   ├─ twinsearch/  Search UI            │
│  ├─ channel-gateway/ Inbox      ├─ twinshell/   Canvas               │
│  ├─ cron/         Job runner    ├─ twinsource/  Source mgmt          │
│  ├─ diagnostics/  Health        └─ webchat/     Widget               │
│  ├─ intent/       Classifier                                          │
│  ├─ knowledge/    KG Hub                                             │
│  ├─ memory/       User memory   plugins/  (sub-plugins)              │
│  ├─ persona/      Persona       ├─ bizcity-zalo-personal/            │
│  ├─ scheduler/    Calendar      ├─ bizcity-doc/                      │
│  ├─ skills/       Micro-flows   └─ ...                               │
│  ├─ twinbrain/    ReAct agent                                        │
│  └─ twin-core/    Event bus                                          │
└────────────────────────────────────────────────────────────────────┘
                              │ BizCity_LLM_Client (Bearer)
                              ▼
                    https://bizcity.vn (LLM Router Server)
                    (Provider keys: OpenRouter, Tavily, Kling...)
```

---

## Nguyên tắc thiết kế quan trọng nhất

### R-GW-8: Client Standalone

`bizcity-twin-ai` là **client plugin** — KHÔNG cần cài `bizcity-llm-router`.
Router chỉ chạy trên server BizCity. Client gọi gateway qua `BizCity_LLM_Client`.

→ Chi tiết: [R-GW-8: Client Standalone](rules/gateway-standalone.md)

### PHP 7.4 Floor

Mọi code PHP phải tương thích PHP 7.4 — không dùng union types, nullsafe `?->`,
`match`, `str_contains`, enum, constructor promotion.

→ Chi tiết: [PHP 7.4 Compat](rules/php74-compat.md)

---

## Extension points

Bạn có thể extend BizCity Twin AI qua:

| Cách | Dùng khi |
|---|---|
| **WordPress Actions** | Side-effects, logging, custom processing |
| **WordPress Filters** | Thay đổi data/config trả về |
| **Sub-plugin** | Thêm module mới hoàn chỉnh |
| **Automation Block** | Thêm action/trigger cho Workflow |
| **Agent Tool** | Thêm tool cho TwinBrain ReAct agent |

---

## Bắt đầu

→ [Getting Started (Dev)](getting-started.md)

→ [API Reference](api/README.md)

→ [Tạo sub-plugin đầu tiên](extending/sub-plugin.md)
