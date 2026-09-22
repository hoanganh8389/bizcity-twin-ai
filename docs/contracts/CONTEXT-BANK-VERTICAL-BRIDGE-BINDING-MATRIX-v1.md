# CONTEXT-BANK-VERTICAL-BRIDGE-BINDING-MATRIX-v1

> **Status:** PROPOSED - per-vertical binding freeze candidate (documentation only)
> **Date:** 2026-09-16
> **Owner:** Context Bank + MPR/TwinBrain + each vertical's owner plugin
> **Design owner document:** [PHASE-1.33D](../analysis/PHASE-1.33D-CONTEXT-BANK-ASYNC-MPR-TIMELINE-VERTICAL-BRAIN-2026-09-16.md)
> **Timeline contract:** [CONTEXT-BANK-ASYNC-TIMELINE-CONTRACT-v1](./CONTEXT-BANK-ASYNC-TIMELINE-CONTRACT-v1.md)
> **Vertical catalog owner:** `core/twinbrain/includes/class-twinbrain-vertical-bridge-registry.php`
> **Mode policy owner:** `core/context-bank/contracts/class-context-bank-mode-policy.php`

This matrix declares, for every registered Vertical Brain Mode, **how much of the
horizontal Context Bank that vertical may see and how it narrows it**. It is the
missing link between the two axes: today no vertical declares a Context Bank
binding and no caller forwards `vertical_id`, so the vertical axis has zero
influence on Context Bank retrieval (PHASE-1.33D §3 F1).

---

## 1. The rule before the table

```text
Vertical = domain policy and action extension (one turn's "which plugin/data outside KG").
Context Bank = horizontal enterprise context (one tenant's "what already happened").
A vertical NARROWS the horizontal scope. It never widens it, never owns it,
never reads the ledger or the filestore, and never selects contracts the
mode policy has not already allowed.
```

The effective contract set is always:

```text
effective = BizCity_Context_Bank_Mode_Policy::contracts_for( mode )
            INTERSECT vertical.context_bank.contracts
```

An empty intersection is a valid outcome and must render as
`vertical_scope_widening_denied` or as zero refs - never as a silent widening.

---

## 1A. MVP pilot - `woo_bizops` only

The full 13-row matrix in §3 is the target shape. It is **not** the MVP scope.
[PHASE-1.33D §5A](../analysis/PHASE-1.33D-CONTEXT-BANK-ASYNC-MPR-TIMELINE-VERTICAL-BRAIN-2026-09-16.md#5a-mvp-closure-plan---the-three-unified-brain-fail-conditions)
wires exactly one row - `woo_bizops` - end to end first, with a ready-to-apply
registry block:

```php
// core/twinbrain/includes/class-twinbrain-vertical-bridge-registry.php
// inside all(), the woo_bizops row gains one additive key:
array_merge(
    self::row( 'woo_bizops', 'Woo BizOps', 'Du lieu doanh thu, don hang va khach hang WooCommerce.', 'core/twinbrain', 'table_and_narrative', false, 'free', 'BarChart3' ),
    array(
        'sensitive' => true,
        'context_bank' => array(
            'mode_hint'          => 'hybrid',
            'contracts'          => array( 'core.context_bank.commerce_order', 'core.context_bank.rollup' ),
            'record_kinds'       => array( 'event', 'rollup' ),
            'dimension_source'   => array( 'entity_type' ),
            'static_entity_type' => 'order',
            'window_days'        => 90,
            'requires_grant'     => true,
        ),
    )
)
```

Every other row in §3 stays exactly as registered today (no `context_bank` key),
which is defined to behave as `mode_hint: inherit` - byte-identical to current
behavior. A row only moves out of `inherit` after its own 5A-style closure with
its own filled evidence template. Do not implement `products`, `company` or any
`skip` candidate row in the same change as the `woo_bizops` pilot - one proven
row before the next, per the roadmap's own staged-adoption discipline (CB4/CB5).

---

## 2. Binding vocabulary

Proposed additive block on each registry row. An absent block must behave exactly
as today (no narrowing, default horizontal mode) so the change is backwards safe.

```php
'context_bank' => array(
    'mode_hint'         => 'inherit',   // inherit | hybrid | skip
    'contracts'         => array(),     // subset of the mode policy allowlist; empty = no narrowing
    'record_kinds'      => array(),     // subset of event|rollup|memory|rule|relation
    'dimension_source'  => array(),     // which dimension fields the vertical can supply
    'window_days'       => 0,           // 0 = no date narrowing
    'requires_grant'    => false,       // exact channel-account grant required before search
),
```

| `mode_hint` | Meaning |
|---|---|
| `inherit` | Keep today's behavior: horizontal `context_bank` mode, no vertical narrowing |
| `hybrid` | Compose vertical dimensions with the horizontal scope; `hybrid` becomes the effective mode |
| `skip` | Do not run Context Bank for this vertical; the timeline renders the `khong chay` row with a bucket |

`dimension_source` names the ledger filter fields the vertical may fill - the
ledger already supports `entity_type`, `entity_key`, `record_kind`, `case_id`,
`goal_id`, `date_from`/`date_to` (`core/context-bank/includes/class-context-bank-ledger.php:412-451`).
**The vertical supplies dimensions, never free text**; the pointer ledger has no
relevance search and must not grow one in this phase.

---

## 3. The matrix

Contract short names: `corpus` = `core.channel_gateway.context_corpus`,
`order` = `core.context_bank.commerce_order`, `rollup` = `core.context_bank.rollup`,
`event` = `core.twin_core.context_bank_event`.

| Vertical | Owner plugin | Proposed `mode_hint` | Contracts | Dimensions it may supply | Window | Grant | Rationale |
|---|---|---|---|---|---|---|---|
| `woo_bizops` | core/twinbrain | **`hybrid` (MVP pilot, §1A)** | `order`, `rollup` | `entity_type=order` (static, MVP-scoped - see PHASE-1.33D §5A.5 for why not `entity_key`) | 90d | **yes** | The only vertical whose questions are literally about tenant commerce state. Already `sensitive=true` in the registry and already allowlisted in the R4 Guru ACL |
| `products` (Super-MRO) | core/twinbrain | `hybrid` (candidate, phase 2 - not in MVP scope) | `rollup`, `order` | `entity_type=sku\|product`, `entity_key` | 180d | no | Product advisory improves with customer-product affinity rollups; raw orders stay out unless the grant path applies. Implement only after `woo_bizops`'s evidence template (PHASE-1.33D §5A.6) is filled and matches expectations |
| `social` | core/twinbrain | `inherit` | - | - | - | no | External signal discovery. Tenant context is not evidence for "what is hot publicly", but the horizontal default stays until D4 evidence says otherwise |
| `quick` | core/twinbrain | `inherit` | - | - | - | no | Short web answer; horizontal default only |
| `deep` | core/twinbrain | `inherit` | - | - | - | no | Multi-source research; horizontal default only |
| `company` | core/twinbrain | `inherit` | `corpus` (candidate) | `entity_type=channel_account` (candidate) | 365d | no | A brand brief about a counterparty the tenant has talked to could legitimately use the conversation corpus. Candidate only - needs D4 evidence before promotion to `hybrid` |
| `scholar` | core/twinbrain | `skip` (candidate) | - | - | - | no | Academic citation retrieval; tenant context adds prompt tokens and unrelated chunks. Flip to `skip` only with D4 before/after evidence |
| `med` | core/twinbrain | `skip` (candidate) | - | - | - | no | Same as `scholar`, plus a sensitivity reason: personal business context must not leak into a medical answer's evidence list |
| `nutri` | core/twinbrain | `skip` (candidate) | - | - | - | no | Same as `med` |
| `law` | core/twinbrain | `skip` (candidate) | - | - | - | no | Statutory lookup; tenant context is not legal evidence |
| `tax` | core/twinbrain | `skip` (candidate) | - | - | - | no | Same as `law`. Revisit only if a tenant-specific tax rollup contract is ever registered |
| `gov` | core/twinbrain | `skip` (candidate) | - | - | - | no | Same as `law` |
| `astro` | bizcoach-pro | `skip` (by construction) | - | - | - | no | The astro path short-circuits the turn before the Notebook Source Layer (`class-twinbrain-runtime.php:231`, `:1460`), so Context Bank is already unreachable. Declaring it keeps the matrix total |
| `off` / `notebooks` | (Guru Workspace axis) | `inherit`, `hybrid` when a notebook is focused | per mode policy | `notebook_id` | - | no | Not a vertical (TWINBRAIN-VERTICAL-PLUGIN-BRIDGE-UNIFY §1.3). Listed because it is the default turn shape and because a focused notebook is the other legitimate `hybrid` trigger |

**Every `(candidate)` row is a proposal, not an approved default.** Until the D4
before/after evidence exists, all of them must ship as `inherit`, which is exactly
today's behavior. Flipping a row to `skip` is a product decision about evidence
quality and prompt budget, and it must be visible on the timeline as a reason
bucket - never as a silently missing row.

---

## 4. Authorization order (non-negotiable)

```text
1. resolve web_mode / explicit /slug  -> Vertical Bridge Registry (canonical owner)
2. read vertical.context_bank binding (absent = inherit)
3. mode_hint -> requested Context Bank mode
4. Scope Resolver: tenant, identity, group denial, user-bound channel, entitlement
5. exact channel-account grant when requires_grant = true
6. intersect vertical contracts with the mode policy allowlist  (narrowing only)
7. bounded ledger search with the vertical's dimension filters
8. verified pointer follow -> retrieval-safe owner excerpts
9. one W0.20 merge, one canonical rerank, one final pack
```

Step 6 happens **after** step 4 and 5 on purpose: a vertical binding can never be
used to skip an identity, group, grant or entitlement check.

---

## 5. Evidence gates per row

A row may be promoted from `inherit` to `hybrid` only with:

- a trace where the vertical is active and Context Bank returns refs **only** from
  the declared contracts;
- a trace where the same prompt without the vertical returns a different (wider or
  differently ordered) ref set;
- a denial trace where the vertical requests a contract outside the mode policy
  allowlist and the result is `vertical_scope_widening_denied` with zero refs;
- for `requires_grant = true` rows, a delegate-allow and an outsider-deny trace
  through the existing `core.channel.channel_user_grants` matrix.

A row may be demoted to `skip` only with:

- a before/after comparison on the same prompt showing lower final prompt tokens
  and no loss of a cited source that the answer actually used;
- the timeline rendering `Context Bank Brain - khong chay` with a reason bucket, so
  the decision stays visible.

---

## 6. Anti-patterns

- Adding a vertical-specific Context Bank contract that the mode policy does not
  already declare.
- Letting a vertical pass raw provider IDs, account keys or free text as ledger
  filters.
- Using the binding to bypass identity, group, grant or entitlement checks.
- Duplicating this matrix inside a frontend catalog - the registry row is the
  source of truth and the REST projection is the only distribution path.
- Treating `skip` as "Context Bank has no data". `skip` is a policy decision and
  must always carry its bucket.
