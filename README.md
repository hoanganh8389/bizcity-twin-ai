# Twin Claw — Turn WordPress Into Your AI Super-Assistant

> **One chat. Every plugin. Every task. Done.**  
> Code. Automate. Create content. Sell products. Connect 60,000+ plugins. All through a single conversation.

<div align="center">

```
 ████████╗██╗    ██╗██╗███╗   ██╗     ██████╗██╗      █████╗ ██╗    ██╗
 ╚══██╔══╝██║    ██║██║████╗  ██║    ██╔════╝██║     ██╔══██╗██║    ██║
    ██║   ██║ █╗ ██║██║██╔██╗ ██║    ██║     ██║     ███████║██║ █╗ ██║
    ██║   ██║███╗██║██║██║╚██╗██║    ██║     ██║     ██╔══██║██║███╗██║
    ██║   ╚███╔███╔╝██║██║ ╚████║    ╚██████╗███████╗██║  ██║╚███╔███╔╝
    ╚═╝    ╚══╝╚══╝ ╚═╝╚═╝  ╚═══╝     ╚═════╝╚══════╝╚═╝  ╚═╝ ╚══╝╚══╝
```

**WordPress-native · Agentic AI · Self-hosted · Open Source · MIT License**

[![GitHub](https://img.shields.io/badge/GitHub-hoanganh8389%2Fbizcity--twin--claw-blue?logo=github)](https://github.com/hoanganh8389/bizcity-twin-claw)
[![Demo](https://img.shields.io/badge/Live%20Demo-bizgpt.vn-green)](https://bizgpt.vn)
[![License](https://img.shields.io/badge/License-MIT-yellow)](LICENSE)
[![Models](https://img.shields.io/badge/AI%20Models-500%2B-purple)](https://bizcity.vn/ai-models/)

</div>

---

## What Is Twin Claw?

**Twin Claw** is a WordPress plugin that turns your entire WordPress installation into a **full-blown AI super-assistant** — like having Claude, ChatGPT, and n8n combined, running on your own server.

WordPress has **60,000+ plugins**. Each one is a capability: e-commerce, email, CRM, forms, video, payments, SEO, analytics... Twin Claw registers all of them into a **Tool Registry** and lets an AI agent orchestrate them through natural language.

The result: **an online assistant that can do everything WordPress can do** — just by chatting.

```
You:  "Write a blog post about electric stoves, publish it, and share on my Facebook page"
Twin Claw: → Research → Write article → Generate image → Publish to WordPress → Post to Facebook → Done ✅
```

No more switching between 10 tabs. No more manual copy-paste workflows. **One claw. Every task.**

---

## Why "Claw"?

Like a claw machine that reaches in and grabs exactly what you need — Twin Claw reaches into your WordPress ecosystem, picks the right plugins, and executes the job. Multiple arms, one brain, infinite reach.

---

## What Can It Do?

### 💻 Code & DevOps
```
You:  "Create a REST API endpoint that returns products by category"
Claw: → Write PHP code → Register route → Test → Return result
```

### 📝 Content & Marketing
```
You:  "Write an SEO article about AI trends 2026, publish it, share on Facebook and Telegram"
Claw: → Research → Write → Generate featured image → Publish → Share to socials → Done
```

### 🛒 E-commerce (WooCommerce)
```
You:  "Update iPhone 16 Pro price to $999 and add the 'hot deal' tag"
Claw: → Find product → Update price + tag → Confirm
```

### 🔄 Workflow Automation
```
You:  "Every time a new order comes in, send me an SMS and add the customer to Google Sheets"
Claw: → Create trigger → Connect SMS → Connect Google Sheets → Deploy
```

### 📅 Calendar & Connections
```
You:  "Schedule a team meeting next Thursday, add to Google Calendar and notify on Telegram"
Claw: → Create event → Sync Google Calendar → Send Telegram → Done
```

### 🧠 Research & Knowledge
```
You:  "Summarize these 3 PDF files and tell me the key differences"
Claw: → Read PDFs → Analyze → Synthesize → Save to knowledge base → Present
```

### 🎨 Creative
```
You:  "Generate a product video for our new coffee machine with AI avatar"
Claw: → Script → Generate video (Kling) → Add avatar (HeyGen) → Deliver
```

---

## Architecture — Simple but Powerful

```
  ┌──────────────────────────────────────────────────────────────────┐
  │         YOU  (Webchat · Zalo · Telegram · Facebook · Widget)     │
  └──────────────────────────────┬───────────────────────────────────┘
                                 │  one message
                                 ▼
  ┌──────────────────────────────────────────────────────────────────┐
  │                      CHANNEL GATEWAY                             │
  │           Unified adapter-based multi-channel bridge              │
  └──────────────────────────────┬───────────────────────────────────┘
                                 │
                                 ▼
  ┌──────────────────────────────────────────────────────────────────┐
  │                    INTENT ENGINE (Team Leader)                    │
  │         Classify → Plan → Orchestrate → Verify result            │
  └──────────┬───────────────────┬───────────────────┬───────────────┘
             │                   │                   │
             ▼                   ▼                   ▼
      ┌───────────┐      ┌───────────┐       ┌──────────────┐
      │  Tool A   │      │  Tool B   │       │   Tool N...  │
      │  Content  │      │  WooCom.  │       │   Video AI   │
      │  Code     │      │  Google   │       │   Zalo Bot   │
      └─────┬─────┘      └─────┬─────┘       └──────┬───────┘
            │                  │                     │
            └──────────────────┴─────────────────────┘
                               │
                   ┌───────────▼────────────┐
                   │     TOOL REGISTRY      │
                   │   60,000+ WP plugins   │
                   │   = 60,000+ AI tools   │
                   └────────────────────────┘
```

**One brain. Many claws. Zero manual configuration.**

---

## Plugin Ecosystem — Every Plugin Is a Superpower

| Plugin | What It Does | Role |
|--------|-------------|------|
| `bizcity-tool-content` | Blog writing, copywriting, SEO | Agent |
| `bizcity-tool-woo` | WooCommerce product & order management | Agent |
| `bizcity-tool-image` | AI image generation & upscaling | Agent |
| `bizcity-video-kling` | AI video creation (Kling) | Agent |
| `bizcity-tool-mindmap` | Auto mind map generation | Agent |
| `bizcity-tool-slide` | Presentation builder | Agent |
| `bizgpt-tool-google` | Google Calendar, Sheets, Drive | Agent |
| `bizcity-tool-facebook` | Facebook Page management & posting | Agent |
| `bizcity-tool-pdf` | PDF reading, summarization, analysis | Agent |
| `bizcity-tool-heygen` | AI avatar videos (HeyGen) | Agent |
| `bizcity-tool-landing` | Landing page auto-builder | Agent |
| `bizcity-companion-notebook` | Deep research, RAG notebook | Agent |
| `bizcity-automation` | Workflow builder (WaicFrame) | Agent |
| `bizcity-tarot` | AI Tarot reading | Agent |
| `bizcity-agent-calo` | Nutrition, fitness, education | Agent |
| `bizcoach-map` | Astrology, coaching, natal charts | Agent |
| `bizcity-zalo-bot` | Zalo OA channel connector | Tool |
| `bizcity-gemini-knowledge` | Gemini-powered knowledge Q&A | Agent |
| `bizcity-chatgpt-knowledge` | OpenAI-powered knowledge Q&A | Agent |

> **All auto-register into the Tool Registry. The AI knows when to use each one. Zero configuration.**

---

## The Smart Core

### Intent Engine — The Brain
- **Single-call classification**: 1 LLM call → classifies mode, intent, goal, entities, slots simultaneously
- **5 execution modes**: `knowledge` · `tool` · `pipeline` · `ambiguous` · `chitchat`
- **Slot Engine**: Asks for missing info, auto-executes when all slots are filled
- **HIL Focus Mode**: Keeps AI focused on one task until completion

### 3-Layer Memory
```
Rolling Memory   → Last N messages (short-term)
Episodic Memory  → Summarized past sessions (mid-term)
User Profile     → Habits, preferences, personal info (long-term)
```

### Knowledge Companion Intelligence (KCI)
- Upload PDF, CSV, Excel, JSON → AI reads and remembers
- Crawl websites → auto-learn from URLs
- Vector-based semantic search
- Multiple AI personas with separate knowledge bases

### WaicFrame — Workflow Engine
- Build automation pipelines without code
- Any tool in the registry is a workflow step
- Checkpoints: auto-save progress, auto-recover on failure
- Triggers: message, schedule, webhook, WooCommerce event

---

## Multi-Channel — Talk to Your AI Anywhere

| Channel | Status |
|---------|--------|
| 💬 Webchat (React) | ✅ Production |
| 📱 Zalo OA | ✅ Production |
| ✈️ Telegram | ✅ Production |
| 📘 Facebook Messenger | ✅ Production |
| 🔲 Embeddable Widget | ✅ Production |
| 📧 Email (SMTP trigger) | 🔜 Coming soon |

One pipeline handles everything. Adapter-based. Add new channels without refactoring.

---

## 500+ AI Models — Right Model for the Right Job

Twin Claw doesn't lock you into one provider:

```
chat      → Gemini 2.0 Flash / GPT-4o / Claude 3.5
vision    → GPT-4o Vision / Gemini Vision
code      → DeepSeek Coder / GPT-4o / Claude 3.5
fast      → Gemini Flash / GPT-4o-mini
planner   → Claude 3.5 Sonnet / GPT-4o
executor  → Gemini 2.0 / GPT-4o
```

Each purpose uses the best model. Auto-fallback on failure. Switch models without changing code.

---

## Comparison

| | Twin Claw | ChatGPT / Claude | n8n / Make |
|--|-----------|-----------------|------------|
| Runs on your server | ✅ | ❌ | ✅ |
| 100% data privacy | ✅ | ❌ | Partial |
| Connects 60,000+ WP plugins | ✅ | ❌ | ❌ |
| Autonomous task execution | ✅ | ❌ | ✅ |
| Built-in chat UI | ✅ | ✅ | ❌ |
| Multi-channel (Zalo, TG, FB) | ✅ | ❌ | Via plugins |
| Open source | ✅ MIT | ❌ | ✅ |
| Free to use | ✅ | ❌ Paid | Freemium |
| Visual workflow builder | ✅ WaicFrame | ❌ | ✅ |
| RAG / Custom knowledge base | ✅ | ❌ | ❌ |
| Long-term user memory | ✅ 3 layers | Limited | ❌ |

---

## Quick Start — 3 Steps

### Requirements
- WordPress 6.0+ · PHP 7.4+ · BizCity API key

### Step 1 — Clone

```bash
cd wp-content/plugins/
git clone https://github.com/hoanganh8389/bizcity-twin-claw.git
```

### Step 2 — Activate

**WP Admin → Plugins → Activate Twin Claw**

The plugin automatically:
- Copies `bizcity-twin-compat.php` to `mu-plugins/`
- Registers all bundled agent plugins
- Initializes Tool Registry, Intent Engine, Channel Gateway

### Step 3 — Configure API

**WP Admin → Bots - Web Chat → ⚡ LLM Settings**

Enter API key from [bizcity.vn/my-account/](https://bizcity.vn/my-account/) → Test connection → Done.

> No API key? [Register for free →](https://bizcity.vn/my-account/)

---

## Project Structure

```
bizcity-twin-claw/
├── bizcity-twin-ai.php          # Entry point
├── core/
│   ├── bizcity-llm/             # LLM Gateway (500+ models)
│   ├── intent/                  # Intent Engine — Team Leader AI
│   ├── knowledge/               # Knowledge base + RAG + vector search
│   ├── skills/                  # Skill Library (YAML/Markdown, slash commands)
│   ├── bizcity-market/          # Plugin marketplace + agent catalog
│   ├── twin-core/               # Twin identity + memory + menu system
│   └── channel-gateway/         # Multi-channel bridge (adapter-based)
├── modules/
│   ├── webchat/                 # React chat UI + REST API + admin dashboard
│   ├── automation/              # WaicFrame workflow engine
│   ├── notebook/                # Research notebook (RAG + deep read)
│   └── identity/                # User identity + persona
└── plugins/                     # Bundled agent/tool plugins (19+)
    ├── bizcity-tool-content/
    ├── bizcity-tool-woo/
    ├── bizgpt-tool-google/
    └── ...
```

---

## Latest Release — v1.4.0 (2026-04-02)

- **Skill Library** — File-based skill auto-discovery, YAML/Markdown definitions, slash commands, triggers
- **WaicFrame Automation** — Resumable pipeline, todos ledger, mismatch detection, E2E test suite
- **Scheduler Core** — Timeline backbone, event CRUD, Google Calendar sync, dual-context
- **Demo & Marketplace** — Live demo at [bizgpt.vn](https://bizgpt.vn) · 500+ AI models
- **MIT License** — Officially open source

---

## Links

| | |
|----|------|
| 🎮 **Live Demo** | [bizgpt.vn](https://bizgpt.vn) |
| 📦 **GitHub** | [github.com/hoanganh8389/bizcity-twin-claw](https://github.com/hoanganh8389/bizcity-twin-claw) |
| 🏪 **AI Plugin Marketplace** | [bizcity.vn/marketplace/](https://bizcity.vn/marketplace/) |
| 🤖 **500+ AI Models** | [bizcity.vn/ai-models/](https://bizcity.vn/ai-models/) |
| 🔑 **Register API Key** | [bizcity.vn/my-account/](https://bizcity.vn/my-account/) |
| 🛠️ **Request Custom Agent** | [bizcity.vn/request-agent/](https://bizcity.vn/request-agent/) |
| ☕ **Support the Project** | [buymeacoffee.com/chuhoanganh](https://buymeacoffee.com/chuhoanganh) |

---

## Documentation

| # | Guide | Link |
|---|-------|------|
| 1 | Companion Twin — Digital Twin Overview | [GUIDE-00-COMPANION-TWIN.md](GUIDE-00-COMPANION-TWIN.md) |
| 2 | Agentic Plugin Ecosystem | [GUIDE-01-AGENTIC-ECOSYSTEM.md](GUIDE-01-AGENTIC-ECOSYSTEM.md) |
| 3 | Automation Pipeline | [GUIDE-02-AUTOMATION-PIPELINE.md](GUIDE-02-AUTOMATION-PIPELINE.md) |
| 4 | Expert Twin Training | [GUIDE-03-EXPERT-TWIN.md](GUIDE-03-EXPERT-TWIN.md) |
| 5 | Agentic Tool Execution | [PHASE-1.1-AGENTIC-TOOL-EXECUTION.md](PHASE-1.1-AGENTIC-TOOL-EXECUTION.md) |
| 6 | Skill ↔ Pipeline Integration | [PHASE-1.2-SKILL-PIPELINE-INTEGRATION.md](PHASE-1.2-SKILL-PIPELINE-INTEGRATION.md) |
| 7 | Scheduler Core | [PHASE-1.3-SCHEDULER-CORE.md](PHASE-1.3-SCHEDULER-CORE.md) |

---

## 🇻🇳 Made in Vietnam

> **Author**: Johnny Chu (Chu Hoàng Anh)  
> **Contact**: Hoanganh.itm@gmail.com · +84 931 576 886  
> **Website**: [bizcity.vn](https://bizcity.vn) · [bizcity.ai](https://bizcity.ai)

---

> *"WordPress has 60,000+ plugins. Each one is a capability. Twin Claw turns them all into tools for an AI that can do everything."*

**⭐ Star if you find this useful. Fork to build your own. Contributions welcome.**

`#TwinClaw` `#OnlineClaw` `#WordPressAI` `#AgenticAI` `#OpenSource`

