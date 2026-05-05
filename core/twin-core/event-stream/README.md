# Twin Event Stream — Single Backbone

> **Rule:** [PHASE-0-RULE-EVENT-STREAM.md](../../../PHASE-0-RULE-EVENT-STREAM.md) (R-EVT-1..7)
> **Spec:** [PHASE-0.12-TWIN-EVENT-STREAM-UNIFICATION.md](../../../PHASE-0.12-TWIN-EVENT-STREAM-UNIFICATION.md)
> **Folder consolidated:** 2026-04-30 (Sprint 5.0+)

This folder is the **single backbone** for every observable side-effect in BizCity Twin AI: LLM calls, tool calls, decisions, classifications, focus changes, milestones, message lifecycles, suggestions, notes, jobs… ALL flow through `BizCity_Twin_Event_Bus::dispatch_v2()` and persist to ONE table `bizcity_twin_event_stream`.

---

## Files

| File | Class | Role |
|---|---|---|
| `class-bizcity-uuid.php` | `Bizcity_Uuid` | UUID v7 generator (event_uuid + trace_id) |
| `class-twin-event-taxonomy.php` | `BizCity_Twin_Event_Taxonomy` | Whitelist of `event_type`s + required-fields validator. Bump `TAXONOMY_VERSION` whenever you add a type. |
| `class-twin-event-stream-schema.php` | `BizCity_Twin_Event_Stream_Schema` | DDL + migration for `bizcity_twin_event_stream` (the ONLY append table allowed) |
| `class-twin-event-store.php` | `BizCity_Twin_Event_Store` | INSERT + indexed SELECT helpers |
| `class-twin-event-bus.php` | `BizCity_Twin_Event_Bus` | Public `dispatch_v2($type, $payload, $opts)` + `ingest_remote()` (only sanctioned write path). Fires `do_action('bizcity_twin_event_v2', $event)` for projectors. |
| `class-twin-event-trace-projector.php` | `BizCity_Twin_Event_Trace_Projector` | Materializes legacy `traces` view from event stream (read-only consumer code unchanged) |
| `class-router-event-ingester.php` | `BizCity_Router_Event_Ingester` | Parses `_twin_events[]` from `bizcity-llm-router` HTTP responses → `Event_Bus::ingest_remote()` (R-EVT-5) |
| `class-twin-event-stream-rest.php` | `BizCity_Twin_Event_Stream_REST` | Read-only `GET /wp-json/bizcity-twin/v1/events` for the Inspector drawer |
| `class-twin-event-inspector-page.php` | `BizCity_Twin_Event_Inspector_Page` | Admin UI (debug / replay / search) — admin-only |

## `schemas/events/`

JSON Schema (draft-07) — one file per `event_type`. File name = constant lowercase value. Required keys must mirror `BizCity_Twin_Event_Taxonomy::required_fields()`. See `schemas/events/README.md`.

---

## DO / DO NOT (R-EVT-1..7 quick reference)

| ✅ DO | ❌ DO NOT |
|---|---|
| `Event_Bus::dispatch_v2('llm_request', $payload)` | `$wpdb->insert($prefix.'..._log', ...)` to log/audit/event/trace tables |
| Add `event_type` constant + `required_fields()` entry + JSON schema → bump `TAXONOMY_VERSION` | Create new `*_log` / `*_event_*` / `*_audit` table |
| FE subscribes ONE SSE name `twin_event` and switches on `data.event_type` | `addEventListener('chunk' \| 'suggestions' \| 'thinking' \| ...)` |
| Logger classes survive as **thin facades** delegating to `dispatch_v2()` | New logger class (`*_Logger`) with own write path |
| Server (`bizcity-llm-router`) attaches `_twin_events[]` to HTTP responses → `Router_Event_Ingester` ingests | Server inserting into its own log table |
| Admin debug via `BizCity_Twin_Event_Inspector_Page` | Custom debug log file scattered per feature |

## Validator

Run `php bin/validate-event-stream.php` from plugin root before each PR. Fails if any of the rules above are violated.

## Adding a new `event_type` — checklist

1. Add `const FOO = 'foo';` to `class-twin-event-taxonomy.php`.
2. Add `self::FOO => ['required_field_a', 'required_field_b'],` to `required_fields()`.
3. Bump `TAXONOMY_VERSION`.
4. Create `schemas/events/foo.json` (draft-07; `required` keys mirror step 2).
5. If FE needs to react: add `case 'foo':` in `useTwinChatStream.ts` `'twin_event'` switch (NEVER add a new SSE event_name).
6. If FE needs to dispatch: whitelist in `class-twinchat-rest-controller.php::$fe_dispatchable_types` then call `api.dispatchTwinEvent({ eventType: 'foo', payload })`.
7. Run validator.
