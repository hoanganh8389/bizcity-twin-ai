# TwinSearch — Module

> **Family:** `retrieval` Input Provider (R-IP-1)  •  **Slug:** `twinsearch`  •
> **Surfaces:** Admin character editor + TwinChat SmartSourcesPanel
>
> See: `PHASE-0-RULE-INPUT-PROVIDER.md`, `PHASE-0.18.1-GURU-RESEARCH-TAVILY.md`

TwinSearch wraps Tavily (search / extract / crawl) into a standardized input
gate that a Twin Guru character can REQUIRE. User flow:

1. Character set `provider_id="twinsearch"` + `input_gate.required=true`.
2. TwinChat shows only the **🔬 Nghiên cứu sâu** button (no raw "+ Add URL").
3. User opens dialog → query → live NDJSON stream (search → extract → report).
4. Per source `+ Add` / `− Remove` syncs to Smart Sources Panel via REST.
5. Markdown content lands in `wp_bizcity_kg_sources` with kind
   `research_studio` / `research_extract` / `research_crawl`.

## Layout

```
modules/twinsearch/
├── bootstrap.php                              # Loaded by bizcity-twin-ai.php
├── includes/
│   ├── class-twinsearch-persona-provider.php  # Persona + Input Provider impl
│   └── class-twinsearch-asset-loader.php      # Enqueue Vite bundle on 2 surfaces
└── ui/                                        # React + Vite + Tailwind + Zustand
    ├── package.json
    ├── vite.config.ts
    ├── index.html
    └── src/
        ├── main.tsx                           # Multi-mount entry
        ├── api/client.ts                      # REST + NDJSON stream reader
        ├── store.ts                           # Zustand turn state
        ├── types.ts                           # Mirrors PHP REST shapes
        ├── hooks/useResearchCapability.ts
        └── components/
            ├── ResearchDialog.tsx             # Top-level modal (R-IP-6)
            ├── ThinkingTimeline.tsx           # 3-phase indicator
            ├── ToolPipeline.tsx               # Horizontal pipeline status
            ├── WebSearchResults.tsx           # Search result list + hover preview
            ├── ExtractResults.tsx             # Extract output cards
            ├── CrawlResults.tsx               # Crawl output rows
            ├── MarkdownReport.tsx             # Streaming markdown report
            └── SourceListWithSync.tsx         # +Add / −Remove KG sync
```

## Build

```powershell
cd modules/twinsearch/ui
npm install
npm run build       # Outputs dist/ + .vite/manifest.json
```

The asset loader reads `dist/.vite/manifest.json` to resolve hashed entry
filenames; if `dist/` doesn't exist, the loader silently no-ops (dev mode
should run `npm run dev` and inject manually).

## Mount

Either surface drops:

```html
<div
  data-input-mount="twinsearch"
  data-scope="character|notebook"
  data-scope-id="123"
  data-character-id="6"
  data-trigger-label="🔬 Nghiên cứu sâu (BizCoach)"
></div>
```

The bundle finds every matching node on `DOMContentLoaded` and on the custom
event `twinsearch:remount` (admin tab swaps).

## Backend contracts

REST namespace: `bizcity/research/v1` (see [class-research-rest.php](../../core/research/includes/class-research-rest.php)).

Routes the FE consumes:

- `GET  /capability/{character_id}` — gate flags (modes / tools / starter queries)
- `POST /sessions` — create research session
- `POST /sessions/{id}/turns/stream` — NDJSON stream of phase + tool events
- `GET  /turns/{id}/sources?scope_type=&scope_id=` — source list with state
- `POST /turns/{id}/source/attach` — attach URL → kg_source
- `POST /turns/{id}/source/detach` — detach URL (status='detached')

Events emitted on attach/detach: `research_source_attached`,
`research_source_detached` (Twin Event Stream).
