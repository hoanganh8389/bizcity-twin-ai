# TwinChat — NotebookLM-Parity Surface

> **Sprint:** Phase 0.5 Sprint 5 — see [PHASE-0.5-SPRINT-5-NOTEBOOKLM-PARITY.md](../PHASE-0.5-SPRINT-5-NOTEBOOKLM-PARITY.md)
> **Backbone:** ALL writes flow through [`core/twin-core/event-stream/`](../../../core/twin-core/event-stream/README.md) (R-EVT-1..7)
> **Folder consolidated:** 2026-04-30

This folder contains everything that gives TwinChat its **NotebookLM-style** experience: pinning a message → note, suggestion chips, citation `[1][2]` interactions, and the controllers/components powering them. Kept in one place so the parity work can evolve independently of the core chat REST surface.

---

## Backend — `notebooklm/includes/`

| File | Class | REST routes | Writes |
|---|---|---|---|
| `class-twinchat-notes-controller.php` | `BizCity_TwinChat_Notes_Controller` | `POST /messages/{id}/pin`, `POST /notes`, `GET /notes`, `DELETE /notes/{id}` | `bizcity_memory_notes` (write) + `Event_Bus::dispatch_v2('note_pinned', …)` |

> Loaded from [bootstrap.php](../bootstrap.php) (search for `notebooklm/includes/`).

## Frontend — `ui/src/components/notebooklm/`

| File | Component | Used by |
|---|---|---|
| `CitationLink.tsx` | `<CitationLink>` + `renderWithCitations()` (2-pass tokenizer) | `ChatPanel.tsx`, `StreamingMarkdown` |
| `SuggestionChips.tsx` | `<SuggestionChips messageId>` — reads `messageSuggestions` map from store, dispatches `suggestion_clicked` via `api.dispatchTwinEvent` | `ChatPanel.tsx` |
| `MessageActions.tsx` | Pin / Copy / 👍👎 / Retry buttons; pin → `api.pinMessageAsNote` | `ChatPanel.tsx` |

> Imports: `from './notebooklm/SuggestionChips'`, `from './notebooklm/CitationLink'`, `from './notebooklm/MessageActions'`. Internal sibling imports use `../../api/client`, `../../stores/twinchatStore`, `../../types/twinchat` (depth +1).

## Event types consumed/emitted

| Event type | Direction | Schema |
|---|---|---|
| `suggestion_emitted` | inbound (server → FE store) | [event-stream/schemas/events/suggestion_emitted.json](../../../core/twin-core/event-stream/schemas/events/suggestion_emitted.json) |
| `suggestion_clicked` | outbound (FE → `POST /events/dispatch`) | [event-stream/schemas/events/suggestion_clicked.json](../../../core/twin-core/event-stream/schemas/events/suggestion_clicked.json) |
| `note_pinned` | outbound (controller → bus) | [event-stream/schemas/events/note_pinned.json](../../../core/twin-core/event-stream/schemas/events/note_pinned.json) |
| `welcome_job` / `research_job` | inbound (lifecycle progress) | event-stream/schemas/events/{welcome_job,research_job}.json |

## Rule reminders

- **Never** add a new SSE event_name. Subscribe to `'twin_event'` and switch on `record.event_type` inside `useTwinChatStream.ts`.
- **Never** insert directly into `bizcity_memory_logs` / a new `*_log` table. Dispatch a `memory_mutation` event; a projector will materialize.
- FE → BE event dispatching MUST go through `POST /events/dispatch` (whitelist). Do NOT call `Event_Bus::dispatch_v2` from JS (no such path exists; this is intentional).
