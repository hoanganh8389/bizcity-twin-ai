# BizCity Twin AI

> **Turn WordPress into an AI-native operating system.**  
> Every plugin becomes a tool. Every website becomes an agent.

## Latest Release

### v1.3.1 (2026-03-28)

- Refactored Intent includes into role-based modules: classification, orchestration, routing, conversation, tools, providers, infrastructure.
- Added/kept backward-compatible shim layer for legacy include paths during migration.
- Fixed bootstrap class load order to guarantee base pipeline classes load before dependent router classes.
- Hardened clarify and plan-confirm flows in Intent Engine (including plan-builder confirm state handling).
- Updated architecture roadmap with today's execution log and a 3-phase priority plan.

---

## What Is This?

**BizCity Twin AI** is a WordPress-native AI agentic platform — the missing layer that transforms your WordPress installation into an intelligent, autonomous AI assistant.

Think of it as bringing the power of a Claude or ChatGPT workspace *directly into WordPress*, where the AI doesn't just chat — it **acts**. It writes content, manages products, creates videos, reads knowledge bases, executes workflows, and coordinates between hundreds of specialized plugins automatically.

The core idea: **WordPress has 60,000+ plugins. Each one is a capability. BizCity Twin AI turns them into tools an AI agent can use.**

---

## The Vision — WordPress as an AI Operating System

Most AI chatbots are isolated boxes. You talk to them, they answer, nothing happens. BizCity Twin AI breaks that wall.

Every WordPress plugin in this ecosystem exposes itself to the AI through a standardized **Tool Registry**. When the AI receives a message like *"write a product description and schedule it to Facebook"*, it doesn't just reply — it:

1. **Classifies** the intent using a single optimized LLM call
2. **Discovers** which tools (plugins) can fulfill the request
3. **Plans** a multi-step workflow: `write_article → post_facebook → notify_zalo`
4. **Executes** each step, mapping outputs to the next step's inputs
5. **Verifies** results and returns a human-readable summary

This is **agentic automation with a chat UI** — not a chatbot bolted onto WordPress, but an AI that *runs* WordPress.

The vision goes further: imagine 100,000 specialized agent plugins — for accounting, astrology, nutrition, e-commerce, content, video, voice — all discoverable, all orchestratable, all composable by a single AI Team Leader per site. That is BizCity.

---

## Architecture in One Diagram

```
  User (Webchat / Zalo / Telegram / Admin UI)
            │
            ▼
  ┌─────────────────────────────┐  │    Channel Gateway Bridge       │  ← Unified inbound/outbound
  │  (adapter-based multi-channel)  │    (bizcity-channel-gateway)
  └─────────────┬───────────────┘
             │
             ▼
  ┌─────────────────────────────┐  │      Intent Engine           │  ← Team Leader
  │  Classify → Plan → Execute  │    (bizcity-intent)
  └──────────┬──────────────────┘
             │
    ┌────────┼─────────────────┐
    ▼        ▼                 ▼
 Tool A    Tool B           Tool N
(Content) (WooCommerce)   (Video AI)
    │        │                 │
    └────────┴─────────────────┘
             │
    Tool Registry (DB-persisted)
    — every active agent plugin
      self-registers here
```

**One Team Leader. Many agents. Zero configuration.**

---

## What Has Been Built

### Core Engine (bizcity-intent)
The brain of the system. A full **Intent Classification + Tool Orchestration** engine:

- **Unified Single-Call Classifier** — one LLM call returns mode, intent, goal, entities, filled/missing slots simultaneously. Token budget: ~1,100–1,500 input tokens.
- **Tool Registry** — database-persisted index of every tool available across active plugins. The AI always knows what it can do.
- **5-Mode Classification** — `knowledge`, `tool`, `pipeline`, `ambiguous`, `chitchat`. Each mode has its own execution path.
- **Slot Engine** — extracts required parameters from conversation, asks the user for missing ones, then auto-executes when all slots are filled.
- **Pipeline Orchestrator** — sequential multi-step workflows where output of step N maps automatically into input of step N+1. Zero-config chaining.
- **HIL (Human-in-the-Loop) Focus Mode** — scoped conversations that keep AI focused on a single task until completion.
- **3-Memory Architecture** — Rolling (recent turns) + Episodic (past sessions) + User Profile (persistent persona). Context is always personalized to *this user*.
- **Priority Built-in Functions** — 5 implicit regex patterns detect user preferences (likes/dislikes, favorites, moods, birthdate, self-intro). 3-tier dispatcher: direct save → LLM extraction → fallback. Integrated at Intent Engine Step 2.4.

### Maturity Dashboard & Knowledge Training Hub
A comprehensive admin dashboard for monitoring and training the AI's knowledge maturity:

- **5-Dimension Scoring** — Intake, Compression, Continuity, Execution, Retrieval — each scored 0–100 with daily cron snapshots.
- **Knowledge Training Hub** — 3 admin sub-pages with inline contenteditable editing:
  - **📚 Đào tạo (Training)** — Quick FAQ, Documents (tài liệu), Website knowledge tabs.
  - **🧠 Memory** — Memory, Episodic, Rolling, Research (Notes) tabs.
  - **📊 Chat Monitor** — Sessions, Goals, Messages, Trend tabs with Chart.js timeline.
- **Inline Editing** — All data tables use `contenteditable` cells with blur-to-save (AJAX per cell). Add row, delete row, bulk operations.
- **Export / Import** — JSON and CSV export/import for any training tab.
- **Overview Dashboard** — Wave chart, Radar, Dimension breakdown, Growth metrics, Timeline, Execution stats — grouped into 3 stat rows (Training → Auto-analyzed → Resources).

### Knowledge Companion Intelligence (KCI)
The knowledge layer, inspired by NotebookLM but with an agentic soul:

- **RAG pipeline** — file uploads (PDF, CSV, Excel, JSON), web crawling, manual Q&A, Facebook Fanpage import → auto-chunked + embedded.
- **Semantic search** — vector similarity retrieval with configurable thresholds.
- **Character system** — each AI persona has its own knowledge base. Multiple characters can be active simultaneously; intent-tag routing pulls knowledge from the right character automatically.
- **Intent Tag Routing** — if the current character doesn't have relevant knowledge, the system automatically pulls from another character whose tags match the query.
- **Provider Characters** — plugin agents can have their own private knowledge base (e.g., a Tarot plugin has its own tarot knowledge, invisible in the main character list).

### LLM Client (bizcity-llm)
A unified LLM gateway client:

- **Gateway mode (default)** — routes through `bizcity.vn` API Hub (OpenRouter, Gemini, OpenAI, video generation, web search, astrology APIs) under a single API key. Pro upgrade available for direct API key integration.
- Model catalog with purpose-based defaults and fallbacks (`chat`, `vision`, `code`, `fast`, `router`, `planner`, `executor`).
- **i18n** — English base language with Vietnamese translations (.po/.mo).
- **Site-level settings** — each site admin configures LLM at **Bots - Web Chat → ⚡ LLM Settings** (`admin.php?page=bizcity-llm`): set Gateway URL, API key, test connection, view usage logs.
- **Network-level settings** — Network Admin → Settings → BizCity LLM (`network/settings.php?page=bizcity-llm`) for global override.
- **Cache-busted assets** — CSS/JS version params use `filemtime()` so updates are never cached.

### Webchat Module
A production-ready chat interface:

- Streaming responses (SSE).
- Session management with ChatGPT-style project scoping.
- Markdown rendering, image attachments, file uploads.
- Admin chat UI for testing and managing conversations.
- **React Slash Commands** — type `/` in the chat input to browse the full tool catalog in a SlashDialog overlay. Select a tool to auto-fill the prompt. Tool chips on welcome screen cards.
- Supports Zalo OA, Telegram, Facebook Messenger, and web widget from a single pipeline.

### Channel Gateway (NEW in v1.3.0)
A unified multi-channel messaging infrastructure:

- **Adapter-based architecture** — each channel (Zalo, Telegram, Facebook, Web) implements the `BizCity_Channel_Adapter` interface.
- **Gateway Bridge** — central adapter registry, inbound message routing, outbound dispatch.
- **Gateway Sender** — unified `send()` API with automatic legacy fallback for channels not yet migrated.
- **User Resolver** — maps `chat_id` → `wp_user_id` across all channels.
- **Blog Resolver** — multisite blog resolution for cross-site routing.
- **Admin Overview** — channel status dashboard with adapter registry, quick test form via REST API.
- **Backward compatibility** — legacy `bizcity_gateway_send_message()` and `bizcity_gateway_detect_platform()` auto-delegate to new gateway when loaded.

### BizChat Menu System (NEW in v1.3.0)
A centralized admin menu registry for the Chat area:

- **Hook-based registration** — any core, module, or plugin can add submenus via `bizchat_register_menus` action.
- **Parent slug** — all items live under the main Chat dashboard (`bizcity-webchat-dashboard`).
- **Position map** — Gateway @60, Zalo Bot @61-63, future channels @70+.
- Plugins like `bizcity-zalo-bot` register their admin pages (settings, test API, message logs) under this unified menu.

### Automation Module (WaicFrame)
A workflow engine for multi-step agentic tasks:

- Visual workflow builder (React).
- Trigger-based automation (message received, product updated, schedule, webhook).
- Step library maps directly to Tool Registry — any registered tool is a usable step.
- Checkpoint system: progress is saved after every step, resumable on failure.

### Notebook Module
A companion research workspace:

- Long-form session context distinct from chat history.
- Deep research mode: the AI reads multiple knowledge sources, synthesizes, and returns structured notes.
- React-based UI.

### Agent Plugin Ecosystem
The actual tools the AI uses, each a standalone WordPress plugin:

| Plugin | Domain | Role |
|--------|--------|------|
| `bizcity-agent-calo` | Multi-domain agent (nutrition, fitness, education) | Agent |
| `bizcity-tool-content` | AI blog writing, copywriting | Agent |
| `bizcity-tool-image` | Image generation + upscaling | Agent |
| `bizcity-video-kling` | AI video generation (Kling) | Agent |
| `bizcity-tool-mindmap` | Mind map generation | Agent |
| `bizcity-tool-slide` | Presentation creation | Agent |
| `bizcity-tool-woo` | WooCommerce product management | Agent |
| `bizgpt-tool-google` | Google Workspace integration | Agent |
| `bizcoach-map` | Astrology + coaching (natal charts, transits) | Agent |
| `bizcity-chatgpt-knowledge` | OpenAI-powered knowledge Q&A | Agent |
| `bizcity-gemini-knowledge` | Gemini-powered knowledge Q&A | Agent |
| `bizcity-companion-notebook` | Deep research notebook | Agent |
| `bizcity-automation` | Workflow automation (WaicFrame) | Agent |
| `bizcity-tarot` | Tarot readings | Agent |
| `bizcity-tool-facebook` | Facebook Fanpage integration | Agent |
| `bizcity-tool-heygen` | HeyGen video avatars | Agent |
| `bizcity-tool-landing` | Landing page builder | Agent |
| `bizcity-tool-pdf` | PDF processing | Agent |
| `bizcity-zalo-bot` | Zalo Bot channel connector | **Tool** |

Each plugin self-registers its tools into the Tool Registry on activation. The AI Team Leader discovers them automatically — no manual wiring needed.

### BizCity Market
An in-WordPress agent marketplace:

- Auto-discovers plugins with `Role: agent` or `Role: tool` in their header.
- Catalog management: activate, deactivate, browse available agents and tools.
- **Two categories**: Agent (AI personas) and Tool (utilities like Zalo Bot, channel connectors).
- Foundation for a public marketplace on `bizcity.ai`.

---

## Installation

> **GitHub**: [hoanganh8389/bizcity-twin-ai](https://github.com/hoanganh8389/bizcity-twin-ai)

**Requirements**: WordPress 6.0+ · PHP 7.4+ · BizCity API key

### Step 1 — Clone or upload

```bash
cd wp-content/plugins/
git clone https://github.com/hoanganh8389/bizcity-twin-ai.git
```

### Step 2 — Activate

Go to **WP Admin → Plugins** → Activate **BizCity Twin AI**.

On activation, the plugin **automatically copies** `bizcity-twin-compat.php` into `mu-plugins/`. If the directory is not writable, a red admin notice will appear with a one-click **"Auto-copy to mu-plugins/"** button.

Every time you re-activate the plugin (e.g. after an update), the mu-plugin file is **overwritten** to stay in sync. If the file becomes outdated, a yellow warning notice appears with an **"Update mu-plugin now"** button.

> **What does this file do?** WordPress loads `mu-plugins/` before regular plugins. Some tool plugins extend `BizCity_Intent_Provider` at file scope — the class must exist before they load. The compat loader also ensures `bizcity-market`’s `plugins_loaded @1` hook registers in time.

---

## Made in Vietnam 🇻🇳

> **Author**: Johnny Chu (Chu Hoàng Anh)  
> **Contact**: Hoanganh.itm@gmail.com · +84 931 576 886  
> **Website**: [bizcity.vn](https://bizcity.vn) · [bizcity.ai](https://bizcity.ai)

---

*Architecture docs: [IMPLEMENTATION-ROADMAP.md](IMPLEMENTATION-ROADMAP.md)*

---

## Hướng dẫn cài đặt (Tiếng Việt)

### Yêu cầu

- WordPress 6.0+
- PHP 7.4+
- Plugin phải được cài vào `wp-content/plugins/bizcity-twin-ai/`

---

### Cài đặt

#### Bước 1 — Upload plugin

```bash
# Clone repo hoặc upload thư mục bizcity-twin-ai/ vào wp-content/plugins/
cp -r bizcity-twin-ai/ /path/to/wp-content/plugins/
```

#### Bước 2 — Kích hoạt plugin

Vào **WP Admin → Plugins** → Kích hoạt **BizCity Twin AI**.

Khi kích hoạt, plugin **tự động copy** file `bizcity-twin-compat.php` vào `mu-plugins/`. Nếu thư mục không có quyền ghi, sẽ hiện thông báo đỏ ở đầu trang admin với nút **"Auto-copy to mu-plugins/"**.

Mỗi lần tái kích hoạt plugin (ví dụ sau khi update), file mu-plugin sẽ được **ghi đè** để đồng bộ với version mới nhất. Nếu file đã cũ, sẽ hiện thông báo vàng với nút **"Update mu-plugin now"**.

> **Tại sao cần file này?** WordPress tải `mu-plugins/` **trước** regular plugins. Một số plugin tool extend class `BizCity_Intent_Provider` ngay ở file scope — nên class phải tồn tại trước khi chúng load. Ngoài ra, `bizcity-market` cần đăng ký hook ở `plugins_loaded @1` — phải load tại mu-plugin time.

---

### Cấu trúc thư mục

```
bizcity-twin-ai/
├── bizcity-twin-ai.php          # Plugin entry point (v1.3.0)
├── mu-plugin/
│   └── bizcity-twin-compat.php  # ← Copy file này vào wp-content/mu-plugins/
├── core/
│   ├── bizcity-llm/             # LLM Client (OpenRouter, OpenAI, Gemini)
│   ├── knowledge/               # Knowledge base + vector search
│   ├── intent/                  # Intent Engine + Provider API
│   ├── bizcity-market/          # Plugin marketplace + agent/tool catalog
│   ├── twin-core/               # Twin identity + memory + BizChat Menu
│   └── channel-gateway/         # Multi-channel bridge (adapter-based)
├── modules/
│   ├── webchat/                 # Chat interface + REST API + React dashboard
│   ├── identity/                # User identity + persona
│   ├── notebook/                # Companion notebook (React)
│   └── automation/              # Workflow automation
├── plugins/                     # ← Bundled agent/tool plugins (auto-discovered)
│   ├── bizcity-tool-content/
│   ├── bizcity-tool-image/
│   ├── bizcity-video-kling/
│   ├── bizcity-tool-woo/
│   ├── bizcity-zalo-bot/       # Zalo Bot channel (Role: tool)
│   └── ...                      # Mỗi thư mục con là 1 agent/tool plugin
└── includes/
    ├── class-twin-ai.php        # Main orchestrator
    ├── class-module-loader.php  # Auto-discovery for modules/
    └── class-connection-gate.php
```

---

### Bundled Agent Plugins

Agent plugins có thể đặt ở 2 nơi — WordPress nhận diện cả hai:

| Vị trí | Mô tả |
|--------|--------|
| `wp-content/plugins/{slug}/` | Cài riêng lẻ (WordPress mặc định) |
| `wp-content/plugins/bizcity-twin-ai/plugins/{slug}/` | **Bundled** — đi kèm trong repo |

Cơ chế: `bizcity-twin-compat.php` (mu-plugin) tự động scan thư mục `bizcity-twin-ai/plugins/`, đọc plugin headers, và inject vào `get_plugins()` cache + `all_plugins` filter. Kết quả:

- **WP Admin → Plugins**: hiển thị bundled plugins y như plugin thường
- **Chợ ứng dụng** (`index.php?page=bizcity-marketplace`): nhận diện và cho phép kích hoạt/tắt
- **Network Admin → BizCity Market**: sync và quản lý đầy đủ

Khi `git clone` repo, tất cả agent plugins đi kèm luôn — không cần cài từng cái.

---

### Chợ ứng dụng (BizCity Market)

Marketplace tích hợp sẵn để duyệt, kích hoạt, và quản lý agent plugins:

| Trang | URL | Ai dùng |
|-------|-----|---------|
| Chợ ứng dụng (site) | `wp-admin/index.php?page=bizcity-marketplace` | Site admin |
| BizCity Market (network) | `network/admin.php?page=bizcity-market` | Network admin |

**Sync Agent Plugins**: Cả hai trang đều có nút **🔄 Sync Agent Plugins** để quét lại danh sách plugin agent từ filesystem vào database. Sync tự động chạy 1 lần / 24h, nhưng có thể bấm nút để force sync ngay.

---

### Cấu hình LLM (BizCity LLM)

`bizcity-llm` là adapter kết nối API LLM với bizcity.vn. Mỗi site tự cấu hình kết nối riêng:

| Trang | URL | Ai dùng |
|-------|-----|---------|
| ⚡ LLM Settings (site) | `wp-admin/admin.php?page=bizcity-llm` | Site admin — nằm dưới menu **Bots - Web Chat** |
| BizCity LLM (network) | `network/settings.php?page=bizcity-llm` | Network admin |

Tại trang LLM Settings, site admin có thể:
- Nhập API key hoặc **đăng ký API key tự động** từ bizcity.vn
- **Kiểm tra kết nối** với bizcity.vn
- Chọn model theo mục đích (chat, vision, code, fast, router, planner, executor)
- Xem **Usage Dashboard** — thống kê sử dụng, top models, recent calls
- Cấu hình Tavily (web search API)
- Nâng cấp bản **Pro** để dùng API key riêng (OpenRouter, OpenAI…)

---

### Load Order

```
[mu-plugin time]
  bizcity-twin-compat.php loads:
    LLM Client → Knowledge → Intent → Market → WebChat

[regular plugin time]
  Tool plugins load → extend BizCity_Intent_Provider ✓
  Market bootstrap: add_action('plugins_loaded', fn, 1) registered ✓

[plugins_loaded @1]  → bizcity-market boot callback fires → BizCity_Market_Catalog init ✓
[plugins_loaded @5]  → intent + knowledge init ✓
[plugins_loaded @10] → (legacy mu-plugin loaders, nếu còn)
[plugins_loaded @11] → bizcity-twin-ai boot() → class_exists guards → skip (đã load rồi) ✓
```

---

### Gỡ cài đặt

Khi xóa plugin, nhớ xóa cả file mu-plugin loader:

```bash
rm wp-content/mu-plugins/bizcity-twin-compat.php
```

---

### Changelog

### 1.3.0 (2026-03-27)
- **§31 React Slash Commands**: `SlashDialog.jsx` — full-screen tool browser with search, tabs, and category grouping. `PromptForm.jsx` — type `/` to trigger slash overlay, select tool to fill prompt (not auto-send). Tool chip buttons on welcome screen tool cards. `ChatContext` slash state management. Built with Vite.
- **§32 Channel Gateway**: New `core/channel-gateway/` module — `BizCity_Channel_Adapter` interface (6 methods), `Gateway Bridge` (adapter registry + route), `Gateway Sender` (unified outbound + legacy fallback), `User Resolver` (chat_id → WP user), `Blog Resolver` (multisite). Admin overview page with adapter table, channel status grid, quick test form. Backward-compat guards in legacy `gateway-functions.php`.
- **§33 BizChat Menu System**: New `core/twin-core/includes/class-bizchat-menu.php` — unified admin menu registry for Chat area. Hook `bizchat_register_menus` at `admin_menu @30`. Parent slug `bizcity-webchat-dashboard`. Position map: Gateway @60, Zalo Bot @61-63, future @70+.
- **§34 Market Tools Expansion**: `class-catalog.php` now accepts `Role: tool` alongside `Role: agent`. Zalo Bot plugin registered as `Role: tool`, `Category: Tools`. Discovery text updated to "Plugin Discovery" covering agents + tools.
- **Zalo Bot Bundled**: `plugins/bizcity-zalo-bot/` — moved into bizcity-twin-ai repo. 3 BizChat submenus: settings, test API, message logs. Reuses existing render handlers.
- **Sidebar Nav**: Added Gateway link (🔌) to React dashboard sidebar.
- **18 Bundled Plugins**: Total bundled agent/tool plugins now 18 (was 15+).

### 1.2.0 (2026-03-27)
- **§30 Knowledge Training Hub v2**: Full admin restructure — 3 new sub-pages (Training, Memory, Chat Monitor) with inline `contenteditable` editing, JSON/CSV export/import, AJAX cell-level save.
- **Admin Menu Overhaul**: Replaced old Knowledge Admin menu with 3 submenus: 📚 Đào tạo, 🧠 Memory, 📊 Chat Monitor. Maturity Dashboard now serves as Knowledge overview.
- **§29.1 Priority Built-in Functions**: 5 implicit regex patterns detect user preferences (likes, dislikes, mood, birthdate, intro). 3-tier dispatcher with LLM extraction fallback. Intent Engine Step 2.4 integration.
- **Inline Editing**: Generic `renderEditableTable()` with `TAB_COLUMNS` config for 10+ data tabs. Blur-to-save, add/delete rows, row numbering.
- **Export / Import**: Download any tab as JSON or CSV. Import JSON files with 500-row limit and validation.
- **3 New Templates**: `admin-training.php`, `admin-memory.php`, `admin-monitor.php` — each with page-scoped `bizcPageContext` for tab filtering.
- **3 New AJAX Endpoints**: `maturity_inline_save`, `maturity_export`, `maturity_import` with nonce verification and field-level sanitization.
- **Overview Dashboard**: Stat cards reorganized into 3 grouped rows — Training (chủ động), Auto-analyzed (tự động), Resources (tài nguyên).

### 1.1.0 (2026-03-24)
- **Auto-sync mu-plugin**: On plugin activation, `bizcity-twin-compat.php` is automatically copied/overwritten into `mu-plugins/`. Admin notice shows if file is missing or outdated.
- **LLM Settings — Gateway-only mode**: Removed Direct/OpenRouter connection mode. Free version always uses BizCity Gateway. Pro upgrade notice added.
- **i18n**: All LLM admin strings use English base with Vietnamese `.po/.mo` translations.
- **Cache busting**: CSS/JS assets use `filemtime()` instead of static version constant.
- **URL fix**: API key registration URL updated to `my-account/api-keys/`.

### 1.0.0
- Initial release — Intent Engine, Knowledge Base, Market Catalog, WebChat
