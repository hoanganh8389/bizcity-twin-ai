# Bizcity Twin AI — Turn Any WordPress Into Your **Second Brain**

> **One person. One WordPress. One AI brain that runs the entire business.**
>
> Twin AI is a **WordPress-native Second Brain** powered by **Graph RAG + Neo4j-style knowledge graph**, designed for the **one-person business** era — where a single founder + one digital twin can replace an entire ops team.

<div align="center">

```
 ████████╗██╗    ██╗██╗███╗   ██╗     █████╗ ██╗
 ╚══██╔══╝██║    ██║██║████╗  ██║    ██╔══██╗██║
    ██║   ██║ █╗ ██║██║██╔██╗ ██║    ███████║██║
    ██║   ██║███╗██║██║██║╚██╗██║    ██╔══██║██║
    ██║   ╚███╔███╔╝██║██║ ╚████║    ██║  ██║██║
    ╚═╝    ╚══╝╚══╝ ╚═╝╚═╝  ╚═══╝    ╚═╝  ╚═╝╚═╝
```

**Second Brain · Graph RAG · Neo4j-grade Knowledge Graph · WordPress-native · Self-hosted**

[![Version](https://img.shields.io/badge/Version-v1.3.7-orange)](bizcity-twin-ai.php)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)](https://wordpress.org/)
[![Graph RAG](https://img.shields.io/badge/Graph%20RAG-Neo4j%20compatible-008CC1?logo=neo4j&logoColor=white)](#3-graph-rag--the-engine-behind-the-second-brain)
[![License](https://img.shields.io/badge/License-GPL--2.0-yellow)](LICENSE)
[![Live Demo](https://img.shields.io/badge/Live%20Demo-bizgpt.vn%2Fchat-ff4b6e?logo=googlechrome&logoColor=white)](https://bizgpt.vn/chat/)

### 🚀 Try the Second Brain live → **[bizgpt.vn/chat](https://bizgpt.vn/chat/)**

</div>

---

## ✨ Screenshots

<p align="center">
  <a href="https://bizgpt.vn/chat/">
    <img src="https://media.bizcity.vn/uploads/sites/1258/2026/05/Screenshot-2026-05-06-003857-scaled.png" alt="Twin AI Second Brain — Live Thinking Timeline" width="900" />
  </a>
</p>

<p align="center">
  <a href="https://bizgpt.vn/chat/">
    <img src="https://media.bizcity.vn/uploads/sites/1258/2026/05/Screenshot-2026-05-06-003734-scaled.png" alt="Twin AI Second Brain — Knowledge Graph & Multi-Perspective Reasoning" width="900" />
  </a>
</p>

<p align="center">
  <a href="https://bizgpt.vn/chat/">
    <img src="https://media.bizcity.vn/uploads/sites/1258/2026/05/Screenshot-2026-05-06-003758-scaled.png" alt="Twin AI Second Brain — Memory Federation & Citations" width="900" />
  </a>
</p>

<p align="center"><sub>👉 Click any screenshot to open the live demo at <a href="https://bizgpt.vn/chat/"><b>bizgpt.vn/chat</b></a></sub></p>

---

## 1. The thesis — Why a *Second Brain*, not a chatbot

The "one-person business" is no longer a meme. A single founder today can ship products, run marketing, support customers and close deals — **if** they have a brain that:

- **Remembers** every customer, every doc, every promise.
- **Connects** facts that live in different silos (CRM, docs, email, chats, WooCommerce orders).
- **Reasons** across those connections instead of just regurgitating snippets.
- **Acts** through tools, not just text.

That brain has a name: a **Second Brain**. Twin AI builds one **inside your WordPress**, so your CMS — the system you already trust to host your business — becomes the **operating cortex** of the entire company.

| One-person business needs | Generic chatbot | Vector-only RAG | **Twin AI (Graph RAG Second Brain)** |
|---|---|---|---|
| Remember a 6-month-old customer chat | ❌ | Partial | ✅ via 5-cortex memory federation |
| Answer "Who introduced me to client X?" | ❌ | ❌ (no relations) | ✅ via graph traversal |
| Combine WooCommerce order + Calendar + Doc | ❌ | ❌ | ✅ via Tool Registry + KG entities |
| Run autonomously while you sleep | ❌ | ❌ | ✅ via Intent Engine + Scheduler |
| Stay 100% under your control | ❌ | Depends | ✅ self-hosted on your WP |
| Cost-scale with one user | $20+/mo per seat | high egress | flat WP hosting |

---

## 2. What is Twin AI?

**Bizcity Twin AI** is a WordPress plugin that turns any WordPress site into a **personalized AI Second Brain** with three pillars every real assistant needs: an **Identity** (Twin Guru persona), a **Memory** (5-cortex federation + KG), and an **Intent** (action-taking Intent Engine).

It is built on three foundational ideas:

1. **Graph RAG over Vector RAG.** Vector search is great at "find similar text", terrible at "find connected meaning". Twin AI uses a **knowledge graph** as the index and vectors only as one signal among many.
2. **WordPress is the substrate, not a wrapper.** Tables live in `$wpdb`, sites are multisite-shard native, posts/users/Woo-orders are first-class entities in the graph.
3. **A brain, not an app.** Every reasoning step is observable through the **Brain Spine** event stream. The user *sees* the brain think.

---

## 3. Graph RAG — the engine behind the Second Brain

### 3.1 Why "Graph RAG"

Classic RAG = `embed(question) → top-k chunks → stuff into prompt`. It collapses on three things every business needs:

- **Multi-hop questions** — "Which clients introduced by Anh in Q1 still owe me money?" requires **traversing relationships**, not similarity.
- **Entity disambiguation** — "Apple" the customer vs "Apple" the supplier vs "apple" the product line.
- **Temporal + provenance reasoning** — "What did we agree on *last week*, and where is the source?"

A **knowledge graph** solves all three: entities and relations are first-class, hops are cheap, every edge carries provenance and time.

Twin AI ships a **Neo4j-compatible property-graph model** implemented natively in MySQL via 7 KG tables (no extra service required), with an optional adapter to push to a real Neo4j cluster for enterprise scale.

### 3.2 The KG schema (Neo4j-style, in `$wpdb`)

```
                    ┌──────────────────────────────────┐
                    │      bizcity_kg_entities         │  ← :Person, :Project, :Order, :Doc, :Note…
                    │  (id, type, name, labels, props) │
                    └──────────┬───────────────────────┘
                               │
        ┌──────────────────────┼──────────────────────┐
        │                      │                      │
        ▼                      ▼                      ▼
┌────────────────┐   ┌──────────────────┐   ┌──────────────────────┐
│ kg_relations   │   │  kg_passages     │   │   kg_xref            │
│ subj──pred──obj│   │ chunked text +   │   │  (cortex,id) ↔ ent   │
│ (cypher-like)  │   │ embeddings       │   │  the "axon"          │
└────────────────┘   └──────────────────┘   └──────────────────────┘
        │                      │                      │
        ▼                      ▼                      ▼
┌────────────────┐   ┌──────────────────┐   ┌──────────────────────┐
│ kg_mentions    │   │  kg_sources      │   │   kg_scope_links     │
│ entity ↔ chunk │   │  doc/url/post    │   │   notebook ↔ ent     │
└────────────────┘   └──────────────────┘   └──────────────────────┘
```

Every WordPress object you already have — a **post**, a **user**, a **WooCommerce order**, a **Companion Notebook**, a **chat message** — is mirrored into the graph as an entity, with `kg_xref` acting as the **axon** that points back to the live row in its source cortex.

KG is an **index, not a store**: the source cortex remains the system of record (with its own retention rules), the graph just connects.

### 3.3 The 4-step Graph RAG retrieval

When a question arrives, Twin AI does not just "find similar chunks". It runs a **4-step pipeline**:

```
Question
  │
  ├─► [1] RETRIEVE   BM25 + dense fusion → top-k entities + passages
  │
  ├─► [2] EXPAND     Cypher-like graph traversal:
  │                    MATCH (e)-[:RELATES|MENTIONS|OWNS]->(n)
  │                    WHERE e IN seed AND depth ≤ 2
  │                    RETURN n, path, weight
  │
  ├─► [3] RERANK     Cross-encoder over (question, expanded passages)
  │                  + recency + provenance + persona-fit
  │
  └─► [4] ANSWER     Build context pack with stable citations:
                     [src:N#pM] [ent:N] [mem:U#42] [nb:17] [faq:7]
```

Result: the model sees not just "10 similar paragraphs", but **the actual subgraph** around the question — entities, relations, sources and memories all wired together. This is what unlocks multi-hop, business-grade reasoning on commodity hardware.

### 3.4 Human-in-the-loop graph growth

Triplets extracted by the LLM never enter retrieval until **approved** by a human (or by a confidence policy). The plugin ships a review queue under **WP Admin → Twin → KG Review** with:

- Pending vs Approved view
- One-click merge / split / forget
- Force-graph visualization of the live brain

This is non-negotiable: a Second Brain you cannot *correct* will eventually lie to you.

---

## 4. The Second Brain anatomy (Cortex Federation)

Twin AI is not a monolith — it is a **federation of cortexes**, each owning its own data, all wired through the KG-Hub:

```
            ┌──────────────────────────────────────────────────┐
            │      KG-HUB  (thalamus + hippocampus)            │
            │   Graph RAG index · entity registry · kg_xref    │
            └───┬────────┬────────┬──────────┬───────────┬─────┘
                │        │        │          │           │
            ┌───▼──┐ ┌───▼──┐ ┌───▼──────┐ ┌─▼────────┐ ┌─▼─────────┐
            │INTENT│ │MEMORY│ │ TOOL     │ │ CHANNEL  │ │ KNOWLEDGE │
            │cortex│ │cortex│ │ cortex   │ │ cortex   │ │ cortex    │
            │      │ │ 5×   │ │ Registry │ │ Web/Zalo │ │ Sources / │
            │ plan │ │ tier │ │ + agents │ │ TG / FB  │ │ Notebooks │
            └──────┘ └──────┘ └──────────┘ └──────────┘ └───────────┘
```

### 4.1 The 5-cortex Memory Federation

A real brain doesn't have "one memory". Twin AI ships five, each tuned for a different timescale:

| Tier | Table | Role | Citation token |
|---|---|---|---|
| 🧠 User | `bizcity_memory_users` | Long-term facts ("my company is ACME") | `[mem:U#N]` |
| 📖 Episodic | `bizcity_memory_episodic` | Event log with embeddings | `[mem:E#N]` |
| 🔄 Rolling | `bizcity_memory_rolling` | Last-N session summary | `[mem:R#N]` |
| 📝 Notes | `bizcity_memory_notes` | Notebook-scoped personal notes | `[mem:N#N]` |
| ❓ FAQ | quick FAQ table | Pinned Q/A pairs | `[faq:N]` |

Every memory is **a citation, not a hallucination**. Click any token in the chat → the source row opens in the side panel → user can **edit or forget** it (the *Right-to-Forget* contract, R-Right-to-Forget).

### 4.2 Twin Guru — 3-layer persona

The Second Brain is opinionated on purpose. A **Twin Guru** is a domain-expert nucleus the user can summon with `@guru-name`:

- **L1 Instruction** — domain system prompt, priority 20, never trimmed.
- **L2 Knowledge sources** — pulled before generic RAG.
- **L3 Personal artifacts** — owner-uploaded materials.

Bind a Guru to a Notebook → that notebook *thinks like* that expert.

### 4.3 Multi-Perspective Reasoning (MPR)

A single answer is rarely enough for a real decision. MPR runs **multiple sub-agents in parallel**, each anchored to a different notebook (lens), and the synthesizer returns:

```
CONSENSUS   → what every lens agrees on
TENSIONS    → where they disagree (and why)
RECOMMENDATION → the synthesizer's call, with explicit caveats
```

This is the difference between a chatbot ("here's an answer") and a Second Brain ("here are the trade-offs you should be aware of").

### 4.4 Brain Spine — observable thinking

Every reasoning step is dispatched through a single event stream `bizcity_twin_event_stream` (taxonomy v4, 15 + 6 standard event types) and broadcast on a single SSE channel `twin_event`. Result:

- A **Thinking Timeline** the user watches in real time.
- A **Live KG Activation** — when the answer cites `[ent:45]`, node 45 on the graph **pulses**. The user literally sees which "brain region" is firing.
- A **Reasoning Ledger** (`bizcity_twin_reasoning_steps`, 90-day retention) lets admins **replay** any past turn step-by-step.

---

## 5. The end-to-end turn pipeline

```
USER turn ──► Twin Core (entry)
                │
                ├─► T-1  Guru Lookup           (decision.kind=guru_lookup)
                ├─► T-2  Context Pack Enrich   (decision.kind=context_pack_enriched)
                ├─► T-3  FAQ Lookup            (retrieval.kind=faq_lookup)
                ├─► T-4  Hybrid Passages       (retrieval.kind=hybrid_passages)
                ├─► T-5  Graph Augment         (retrieval.kind=graph_augment)  ← Cypher-like hop
                ├─► T-6  Memory Recall ×4 tier (memory_recall.kind=user|episodic|rolling|note)
                ├─► T-7  Perspective Select    (decision.kind=perspective_select)
                ├─► T-8  Sub-agents (parallel) (decision.kind=perspective_answer)
                ├─► T-9  Synthesize            (decision.kind=synthesize)
                └─► T-10 Stream answer + citations
                         │
                         └─► Reasoning Projector → bizcity_twin_reasoning_steps (90d)
                                                   bizcity_twin_citations_index (hot)
                                                   bizcity_kg_xref (axon, kept forever if cited)
```

---

## 6. The one-person business operating model

Twin AI is built around a single mental model: **one founder + one Twin = one company**. The Twin handles the boring loop, the founder handles judgment calls.

```
                ┌──────────────────────────────────────────────┐
                │             FOUNDER (you)                    │
                │   Strategy · Relationships · Final calls     │
                └───────────────────────┬──────────────────────┘
                                        │ delegates
                                        ▼
                ┌──────────────────────────────────────────────┐
                │           TWIN (Second Brain)                │
                │  Remembers · Connects · Drafts · Acts        │
                └─┬──────────┬──────────┬──────────┬───────────┘
                  │          │          │          │
                  ▼          ▼          ▼          ▼
              CRM &      Content    Calendar &  WooCommerce
            customer     drafting   scheduling    & orders
              chats     (Doc/Slide)
                  │          │          │          │
                  ▼          ▼          ▼          ▼
                ┌──────────────────────────────────────────────┐
                │       WORDPRESS = Operating System           │
                │  Posts · Users · Woo · Notebooks · Tools     │
                └──────────────────────────────────────────────┘
```

Concrete examples a founder can ship today:

- **Sales** — Twin reads Zalo/Telegram/Messenger threads, extracts entities into the KG, and drafts follow-ups citing previous promises with `[mem:E#N]`.
- **Content** — `@content-guru` writes a blog post that cites internal sources `[src:N#pM]` and avoids contradicting last week's notes `[nb:17]`.
- **Ops** — Intent Engine spots "customer X mentioned a refund 3 days ago", checks the WooCommerce order via Tool Registry, and proposes a one-click action.
- **Knowledge ops** — every meeting transcript becomes new triplets, reviewed in 30 seconds, and the brain gets smarter overnight.

---

## 7. Project structure

```
bizcity-twin-ai/
├── bizcity-twin-ai.php          # Entry point (v1.3.7)
├── core/
│   ├── bizcity-llm/             # LLM Gateway client (500+ models via router)
│   ├── intent/                  # Intent Engine — classify, plan, orchestrate
│   │   └── shell/               # Phase 0.16 — Intent Shell
│   ├── knowledge/               # KG-Hub — 7 tables, Graph RAG, vector search
│   ├── persona/                 # Phase 0.18 — Persona Provider contract
│   ├── twin-core/               # Twin identity + memory + Guru + Event Bus
│   ├── bizcity-market/          # Plugin marketplace + agent catalog
│   ├── channel-gateway/         # Multi-channel adapter (Zalo/TG/FB/Web)
│   ├── skills/                  # Skill Library (YAML/Markdown)
│   ├── tools/                   # Tool registry runtime
│   ├── scheduler/               # Cron + automation triggers
│   ├── memory/                  # 5-cortex memory federation
│   ├── agents/                  # Phase 0.13 — TwinShell Agents-as-Tools
│   ├── runtime/                 # Phase 0.15 — Runner + REST /run
│   ├── research/                # Phase 0.18.1 — Guru Research Studio (Tavily)
│   └── helper-legacy/           # Backward-compat flow helpers
├── modules/
│   ├── twinchat/                # ⭐ Default dashboard (TwinChat React SPA)
│   ├── twinshell/               # Phase 0.11 — /twin/ ActivityBar wrapper
│   ├── twinsource/              # Phase 6.1 — Source-management panel
│   ├── twinsearch/              # Phase 0.18.1.7 — Tavily input gate
│   └── webchat/                 # Legacy webchat (settings only)
├── plugins/                     # Bundled "must-load" agent plugins
│   ├── bizgpt-tool-google/      # ✅ Google Workspace
│   ├── bizcity-tool-image/      # ✅ Image Studio
│   ├── bizcity-content-creator/ # ✅ Content templates
│   └── bizcity-doc/             # ✅ Doc / Slides / Sheets generation
├── changelog/                   # Per-phase changelog dashboard (admin)
├── mu-plugin/
│   └── bizcity-twin-compat.php  # Loader for early hooks (auto-copied)
└── PHASE-*.md                   # Architecture phase docs (0.3 → 0.19)
```

---

## 8. Bundled plugins — loaded by default

| Plugin | Role |
|---|---|
| `bizgpt-tool-google` | Google Calendar, Sheets, Drive, Docs |
| `bizcity-tool-image` | AI image generation, templates, editor assets |
| `bizcity-content-creator` | Template-driven AI content generation |
| `bizcity-doc` | AI-generated Word, PowerPoint, Excel |

> Several optional plugins (Companion Notebook, Automation, Zalo Bot, Tool Facebook, Tool Mindmap, Tool PDF, Tool Content, Tool Heygen, Tool Woo, Pagebuilder, ChatGPT/Gemini Knowledge, Agent Calo, Tarot) are **not loaded by default** and **excluded from GitHub** (see `.gitignore`). They can be re-enabled by activating them manually under `wp-admin/plugins.php`.

---

## 9. Quick Start

### Requirements
- WordPress 6.0+ · PHP 7.4+ · MySQL 5.7+
- BizCity API key (free signup at [bizcity.vn/my-account/](https://bizcity.vn/my-account/))
- *(Optional)* Neo4j 5.x — for projecting the in-WP graph to a real graph DB at scale

### Install

```bash
cd wp-content/plugins/
git clone https://github.com/hoanganh8389/bizcity-twin-ai.git
```

### Activate

**WP Admin → Plugins → Activate "Bizcity Twin AI"**

The plugin will automatically:
1. Create the `bizcity_kg_*` and `bizcity_memory_*` tables (per-blog shard).
2. Copy `mu-plugin/bizcity-twin-compat.php` into `mu-plugins/`.
3. Register the Tool Registry, Intent Engine and Channel Gateway.
4. Mount **Twin** as the default dashboard (menu position 2, slug `bizcity-twinchat`).

### Configure

**WP Admin → BizCity AI → LLM Settings** → enter API key → Test → Done.

To enable optional Neo4j projection: set `BIZCITY_KG_NEO4J_URI`, `BIZCITY_KG_NEO4J_USER`, `BIZCITY_KG_NEO4J_PASS` in `wp-config.php`.

---

## 10. Phase Roadmap

| Phase | Summary | Status |
|---|---|---|
| **0.3** [KG-Hub](PHASE-0.3-KGHUB.md) | Graph schema (entity/relation/passage), 4-step Graph RAG | ✅ |
| **0.6** [Central Brain](PHASE-0.6-KGHUB-CENTRAL-BRAIN.md) | Cortex federation, `kg_xref` axon, citation V2 | ✅ |
| **0.8** Multi-Perspective Reasoning | Notebook = lens | ✅ |
| **0.11** Twin Shell | Universal `/twin/` Activity Bar | ✅ |
| **0.12** Event Stream Unification | 1 table, 15 event types, 1 SSE channel | ✅ |
| **0.13** TwinShell Primitives | Standardized UX layer, per-source learning progress | ✅ |
| **0.15** Agents-as-Tools | OpenAI SDK pattern composition | ✅ |
| **0.16** Intent Shell Migration | Intent flows through Shell | ✅ |
| **0.17** [Brain Spine](PHASE-0.17-BRAIN-SPINE.md) | Memory citation, Thinking Timeline, Reasoning Ledger | ✅ |
| **0.18** Notebook Persona | Character ↔ Notebook binding, 3-layer Guru pipeline | ✅ |
| **0.18.1** Guru Research (Tavily) | L2 Knowledge Builder | 🔄 Draft |
| **0.19** [Guru Surface Polish](PHASE-0.19-GURU-INDEX.md) | Observability, UI polish | 🟡 In progress |

Further reading: [GUIDE-00-COMPANION-TWIN.md](GUIDE-00-COMPANION-TWIN.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · the `PHASE-0-RULE-*.md` files (pinned rules — read these before opening a PR).

---

## 11. Pinned Rules (read before contributing)

These rules are **immutable** — never refactor without updating the rule first:

- **R-EVT-*** — Every state change goes through `Event_Bus::dispatch()`. No new tables or event types outside taxonomy v4.
- **R-MPR-*** — A Notebook is a lens, not a store. No cross-notebook entity merging.
- **R-KG-*** — All ingestion goes through `BizCity_KG_Source_Service::ingest()`. No bypass.
- **R-TG-1/2** — Guru instruction has priority 20 and is never trimmed. Guru sources are pulled before generic RAG.
- **R-PERSONA-*** — Bridge contract between character and notebook.
- **R-Right-to-Forget** — Users always retain the ability to edit or forget any memory item.

Full set: `PHASE-0-RULE-*.md`.

---

## 12. Comparison

| | **Twin AI Second Brain** | ChatGPT / Claude | n8n / Make | Vector-RAG SaaS |
|--|---|---|---|---|
| Self-hosted, full data privacy | ✅ | ❌ | ✅ | ❌ |
| **Graph RAG** (multi-hop reasoning) | ✅ | ❌ | ❌ | ❌ |
| Neo4j-compatible schema | ✅ | ❌ | ❌ | ❌ |
| Multi-Perspective Reasoning | ✅ | ❌ | ❌ | ❌ |
| Live thinking timeline | ✅ | Partial | ❌ | ❌ |
| Memory citations (`[mem:U#N]`) | ✅ | ❌ | ❌ | Partial |
| Right-to-forget for users | ✅ | Limited | ❌ | Limited |
| Multi-channel (Zalo, TG, FB) | ✅ | ❌ | Via plugins | ❌ |
| 500+ AI models with auto-fallback | ✅ | ❌ | Limited | Limited |
| WordPress-native | ✅ | ❌ | ❌ | ❌ |
| Multisite-shard ready | ✅ | N/A | N/A | N/A |
| **Cost for one-person business** | flat WP host | $20+/seat | per-execution | per-doc |

---

## 13. About

> **Author**: Johnny Chu (Chu Hoàng Anh)
> **Contact**: Hoanganh.itm@gmail.com · +84 931 576 886
> **Website**: [bizcity.vn](https://bizcity.vn) · [bizcity.ai](https://bizcity.ai)
> **Live demo**: [bizgpt.vn/chat](https://bizgpt.vn/chat/)
> **License**: GPL-2.0-or-later
> **Made in Vietnam 🇻🇳, built for the world.**

---

> *"The future of business is not 1,000 employees with 1 AI tool — it's 1 founder with 1 Second Brain that knows everything they know, and never forgets."*
