# Twin Event Stream — JSON schemas

One file per `event_type` in `BizCity_Twin_Event_Taxonomy`. Schemas are JSON Schema draft-07.

These files are reference-only documentation for the FE adapter + backend dispatchers — runtime validation is enforced by `BizCity_Twin_Event_Taxonomy::required_fields()` (PHP, lightweight). Tooling (PR review checklist, IDE hints, future static validator) consumes these schemas.

## Contract (R-EVT-2)

- **No new event_type without a schema file here**. Schema name = `<event_type>.json`.
- `required` keys must mirror `required_fields()` output for the same type.
- Extra payload fields are allowed (`additionalProperties: true`) — schemas describe known fields, not exhaustive whitelist.
- Bump `BizCity_Twin_Event_Taxonomy::TAXONOMY_VERSION` whenever you add/remove a type.

## Naming

Files are kebab-case match of the constant lowercase value. e.g. `BizCity_Twin_Event_Taxonomy::SUGGESTION_EMITTED = 'suggestion_emitted'` → `suggestion_emitted.json`.

## Sprint 5 additions (2026-04-30)

- `suggestion_emitted.json` — server-proposed chip set
- `suggestion_clicked.json` — FE audit trail (user picked chip)
- `welcome_job.json` — AI-welcome-after-upload lifecycle (status: started|completed|failed)
- `research_job.json` — autonomous research lifecycle (status: started|tavily_returned|imported|failed)
- `note_pinned.json` — user pinned assistant message as note
