# CONTEXT-BANK-ASYNC-TIMELINE-CONTRACT-v1

> **Status:** PROPOSED - contract freeze candidate (documentation only)
> **Date:** 2026-09-16
> **Owner:** Context Bank + MPR/TwinBrain + TwinChat/Twin GPT UI
> **Design owner document:** [PHASE-1.33D](../analysis/PHASE-1.33D-CONTEXT-BANK-ASYNC-MPR-TIMELINE-VERTICAL-BRAIN-2026-09-16.md)
> **Binding matrix:** [CONTEXT-BANK-VERTICAL-BRIDGE-BINDING-MATRIX-v1](./CONTEXT-BANK-VERTICAL-BRIDGE-BINDING-MATRIX-v1.md)
> **Existing published schema this extends:** `core/twinbrain/includes/event-schemas/notebook_source_layer_ready.json`

This document freezes **what the Context Bank phase is allowed to say to the MPR
Thinking Timeline**, and how a timeline that receives that phase out of order must
behave. It defines no storage, no retrieval behavior and no UI layout.

---

## 1. Scope and non-scope

**In scope:** the nested `context_bank` object on `notebook_source_layer_ready`,
one new paired phase event on the existing `twin_event` channel, the reason-bucket
vocabulary, ordering/late-arrival rules, and redaction rules.

**Out of scope, and forbidden here:** a Context Bank SSE channel, a Context Bank
timeline component, a Context Bank message body, any ledger row, any owner body,
any query text, any pointer/offset/hash, any credential, any raw provider ID.

---

## 2. Payload contract - `notebook_source_layer_ready.context_bank`

### 2.1 Version 1 (shipped today)

| Field | Type | Meaning |
|---|---|---|
| `enabled` | bool | The layer was evaluated and executed |
| `mode` | string | Effective retrieval mode |
| `source_ref_count` | int | Verified refs admitted after the contract allowlist |
| `pointer_follows` | int | Pointer verifications performed |
| `owner_excerpt_count` | int | Retrieval-safe owner excerpts admitted to W0.20 |
| `degraded` | bool | At least one pointer/verification failure bucket occurred |
| `incomplete` | bool | The result was truncated or partially verified |
| `reason` | string | Free-form reason string (legacy shape) |

### 2.2 Version 2 (proposed, additive only)

Every v1 field is preserved with identical meaning. A v1 consumer must keep
working against a v2 payload.

| New field | Type | Required | Meaning |
|---|---|---|---|
| `duration_ms` | int | yes | Server-measured wall time of the Context Bank phase. Source already exists: `BizCity_Context_Bank_Search::search()` returns it |
| `budget_ms` | int | yes | Declared bound for the phase, from the scope resolver budgets |
| `reason_bucket` | enum string | yes | Stable bucket from §4. `reason` remains for display only |
| `status` | enum string | yes | `ran` \| `skipped` \| `degraded` \| `deadline` |
| `matched_count` | int | no | Ledger rows matched before verification |
| `returned_count` | int | no | Rows returned after verification |
| `truncated` | bool | no | The ledger page was truncated |
| `vertical_id` | string | no | Server-derived vertical binding that applied, empty when none |
| `binding_source` | enum string | no | `web_mode` \| `slash_command` \| `none` |
| `dimension_filters` | string[] | no | **Filter field names only** (e.g. `entity_type`, `date_from`). Never the values |
| `contract_count` | int | no | Number of contracts in the effective allowlist after narrowing |
| `rounds` | int | no | How many bounded retrieve rounds reused this one phase result. Must be `>= 1` |
| `cache` | enum string | no | `live` \| `warm_hit` \| `warm_stale` \| `warm_miss` |
| `mode_hint_applied` | enum string | no | `inherit` \| `hybrid` \| `skip` - the vertical binding's declared hint, distinct from `mode` (the resolver's effective mode). Present only when a `vertical_id` applied |
| `contracts_before_narrowing` | int | no | Contract count from the mode policy allowlist before any vertical intersection |
| `contracts_after_narrowing` | int | no | Contract count actually queried, after the vertical's binding intersected the allowlist. Must be `<= contracts_before_narrowing`; equality is valid only when the binding's contract list is a superset of the allowlist by design |

Rules:

1. `duration_ms` is the phase duration, never the turn duration. A consumer that
   receives `duration_ms` must not substitute a live turn timer for this row.
2. `status` and `enabled` must agree: `enabled=false` implies `status=skipped`.
3. `dimension_filters` carries names, not values. A value can be PII; a name cannot.
4. Counts are counts. No array of records, titles, snippets or IDs is ever added
   to this object.
5. Adding a field is a minor version bump; changing a field's meaning is not
   allowed - add a new field instead.

---

## 3. Phase event contract

### 3.1 Registration order (mandatory)

The taxonomy is the gate. In one change, and **before any dispatch**:

```text
1. add the constants to core/twin-core/event-stream/class-twin-event-taxonomy.php
2. add their required_fields contract
3. add the JSON schemas under event-stream/schemas/events/
4. bump TAXONOMY_VERSION
```

Dispatching a type that the loaded taxonomy does not declare throws and the event
is lost silently - this is the exact failure that made `memory_recall`
unattributable for months (PHASE-1.33C §4 C9).

### 3.2 The pair

```text
context_bank_started
context_bank_done
```

| Field | `started` | `done` | Notes |
|---|---|---|---|
| `trace_id` | required | required | Same turn correlation as every other MPR event |
| `event_uuid` | required | required | |
| `parent_event_uuid` | required | required | Preserves the phase chain |
| `phase` | required | required | Constant `context_bank` |
| `started_epoch_ms` | required | required | Server clock, monotonic within the turn |
| `completed_epoch_ms` | - | required | |
| `duration_ms` | - | required | Must equal the payload `duration_ms` for the same trace |
| `status` | - | required | Same enum as §2.2 |
| `reason_bucket` | - | required | Empty string when `status=ran` |
| `mode` | required | required | |
| `vertical_id` | optional | optional | Empty when no binding applied |
| `contract_count` | optional | required | |
| `source_ref_count` | - | required | |
| `pointer_follows` | - | required | |

Forbidden on both events: query text, prompt, ledger row, owner body, relative
file, byte offset, row hash, content hash, account key, bearer token, raw
provider ID, notebook/passage text.

### 3.3 Only one channel

Both events travel on the existing `twin_event` channel and the existing SSE
frame family. No new channel, no new endpoint, no Context Bank-specific stream.
Snapshot/replay must reproduce both events from the stored turn parts, exactly as
`perspective_done` does today.

---

## 4. Reason-bucket registry

A bucket is a stable machine token. UI copy may translate it; logs and probes must
match on the token.

| Bucket | Emitted when | `status` |
|---|---|---|
| `context_bank_disabled_or_unavailable` | Flag stored `0`, filter disabled, or runtime class missing | `skipped` |
| `tenant_context_missing` | No resolvable blog | `skipped` |
| `linked_user_required` | User-bound channel with no linked user | `skipped` |
| `identity_required` | No authenticated identity | `skipped` |
| `group_private_scope_denied` | Group chat - private scope refused | `skipped` |
| `mode_unknown` | Requested mode outside the allowlist | `skipped` |
| `vertical_not_registered` | Vertical hint not in the Vertical Bridge Registry | `skipped` |
| `vertical_scope_widening_denied` | Vertical requested a contract outside the mode policy allowlist | `skipped` |
| `notebook_owner_scope_denied` | Notebook hint failed ownership validation | `skipped` |
| `entitlement_plan_insufficient` | Plan below the mode minimum | `skipped` |
| `entitlement_owner_unavailable` | Entitlement owner not loaded | `skipped` |
| `context_bank_channel_scope_denied` | Exact account grant refused | `skipped` |
| `pointer_missing` | Pointer target absent | `degraded` |
| `pointer_hash_mismatch` | Durable row does not match the receipt hash | `degraded` |
| `pointer_follow_budget_deferred` | Pointer limit reached before verification | `degraded` |
| `pointer_budget_exhausted` | Time budget consumed inside pointer follow | `degraded` |
| `context_bank_join_deadline_exceeded` | The async lane missed the W0.20 join point | `deadline` |
| `context_bank_warm_stale` | Warm pack found but past TTL; live lane used | `ran` |
| `""` (empty) | Normal completion | `ran` |

A new bucket requires a row here plus a probe assertion. An unknown bucket
reaching the UI must render verbatim, never be swallowed.

---

## 5. Ordering and late-arrival rules

The Context Bank phase may start before other rows and finish after them. The
reducer contract is therefore:

1. **Phase order is declared, not observed.** Rows render in the canonical MPR
   phase order (memory recall -> candidate selection -> source layer -> Context
   Bank -> perspectives -> tool -> synthesis -> final), regardless of arrival.
2. **A phase is one row.** A repeated `context_bank_done` for the same `trace_id`
   replaces the row; it never appends a second row.
3. **`rounds` is a counter on one row.** Bounded retrieve rounds must not create
   one row per round.
4. **A `started` with no `done` renders as pending with a live timer.** Once
   `done` arrives, the measured duration wins permanently.
5. **A `done` after the answer completed still updates the row**, but the row is
   marked `deadline` if it arrived after the W0.20 join point - and the answer is
   never re-rendered because of it.
6. **Snapshot replay must produce the same row and the same duration** as the live
   turn. If replay cannot, the phase is not contract-complete.

---

## 6. Compatibility and versioning

| Change | Allowed | Requires |
|---|---|---|
| Add a field in §2.2 | yes | Schema update + probe assertion in the same change |
| Add a reason bucket | yes | Row in §4 + probe assertion |
| Add a phase event type | yes | Taxonomy constant + required_fields + JSON schema + version bump |
| Change a field's meaning | no | Add a new field instead |
| Remove a v1 field | no | v1 consumers must keep working |
| Emit a phase event on a new channel | no | - |

Feature-off parity is part of the contract: with the Context Bank flag stored `0`,
the payload must be exactly the v1 skipped shape plus `status=skipped` and the
matching bucket, and no phase event pair beyond the `skipped` `done` is required.

---

## 7. Probe assertions this contract implies

- `context_bank.duration_ms` is present, is an integer, and differs from the turn
  elapsed time on a turn longer than the phase.
- `context_bank.duration_ms == context_bank_done.duration_ms` for the same trace.
- `status` and `enabled` never disagree.
- `source_ref_count == count( context_bank_source_refs )`.
- A disabled turn writes exactly one footlog row and emits exactly one `done`.
- A turn with N bounded retrieve rounds still reports `rounds = N` on **one** row
  and performs one ledger search.
- No event or payload field in §2/§3 contains a path, hash, offset, key, query or
  body. Assert by allowlist, not by regex denial.
- `contracts_after_narrowing <= contracts_before_narrowing` on every row where
  `vertical_id` is non-empty; a violation is a binding-matrix defect, not a
  passing narrowing.
- For the pilot vertical's closure procedure (single-vertical MVP, not this
  contract's general shape), see
  [PHASE-1.33D §5A](../analysis/PHASE-1.33D-CONTEXT-BANK-ASYNC-MPR-TIMELINE-VERTICAL-BRAIN-2026-09-16.md#5a-mvp-closure-plan---the-three-unified-brain-fail-conditions).
