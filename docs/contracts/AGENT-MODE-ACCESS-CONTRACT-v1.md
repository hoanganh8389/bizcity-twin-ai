# Agent Mode Access Contract v1 — `agent-mode-access@1.0.0`

> **Axis:** [R-TWIN-AGENT-AXIS](../rules/PHASE-0-RULE-TWIN-AGENT-AXIS.md) R-TAA-14 (decision D-TAA-1, 2026-09-30) · **Machine form:** [TWIN-AGENT-AXIS-v1.json](TWIN-AGENT-AXIS-v1.json) `modes`
> **Status:** v1.0.0 · 2026-09-30 · **coded + unit** 2026-09-30/10-01 (site `core/channel-gateway/includes/class-agent-mode-access.php` + CRM delegate `class-crm-agent-mode-delegate.php` incl. D-TAA-7 `customers_scope()`; cell enforces modes on owner/staff turns), not yet runtime-proven. Modes `booking` and `automation` come in PHASE-0.88 (Q88-3) · **Owner:** client (`core/channel-gateway` Bot Studio + registry; business modes delegated to `plugins/bizcity-twin-crm` `Staff_Policy`); enforced by brain-core.
> **Scope:** `framework_internal` (registry filter is public for extensions).
> **Sibling:** [`module-access@1`](MODULE-ACCESS-CONTRACT-v1.md) answers "may this user open module X in `/twin/`"; this contract answers "which vertical brain modes may the agent use **for** this user". Same mechanism, different question; neither replaces the other.

## 1. Owner directive

> *"D-TAA-1: nhân viên được dùng khối Owner Agent không thì cần bổ sung framework contract về permission danh sách các vertical brain mode mà user ở role nào được quyền dùng gì. Ví dụ editor chỉ được dùng notebook. Toàn bộ nhân viên hay admin, đều dùng trong phạm vi first user id với notebook."*

## 2. Agent modes (registry)

An **agent mode** is one vertical brain capability of the Owner Agent block: a set of packs + tools the agent may use in an owner turn. v1 modes:

| Mode id | Packs | Tools | Data scope | Linked vertical bridge (R-BRAIN-UNIFY Contract 13) |
|---|---|---|---|---|
| `notebook` | `owner_knowledge`, `notebook_meta` | `notebook_search` (owner scope) | **the principal's own notebooks only** (first `user_id`), daily notebooks included | — (KG-Hub) |
| `astro_self` | `astro_self` | `astro_self` | the principal's own `is_self` profile (R-COACHEE.4) | `astro` |
| `sales` | `sales` | `biz_sales` | site | `woo_bizops` |
| `orders` | `orders` | `biz_orders` | site | `woo_bizops` |
| `customers` | `customers` | `biz_customer_find` | site (masked PII) | `crm_customer` (new) |
| `stock` | `stock` | `biz_stock` | site | `woo_bizops` |
| `deep_analysis` | — | `request_deep_analysis` | job runs as the principal on the web (R-TAA-9) | TwinBrain MPR (custom add-on) |

Extensions add modes with the filter `bizcity_agent_modes_register` (id, label, packs, tools, data scope, `access` block of §3, linked vertical bridge id). A mode whose linked vertical bridge or source plugin is absent is not registered (fail closed). The registry is the only list; the axis JSON mirrors it for the validator.

## 3. Permission resolution (one answer per user per mode)

Meta capability `bizcity_agent_mode_<mode>` on the current blog, mapped by `map_meta_cap` exactly like `module-access@1`:

| Case | Result |
|---|---|
| Unknown mode, user 0 | `do_not_allow` |
| Site admin (`BizCity_Network_Admin_Capability::can_manage( $user_id )`) | allowed (all modes) |
| mode `access.mode = delegated` | owner answer via filter `bizcity_agent_mode_delegate( false, $mode, $user_id )`; business modes → CRM `Staff_Policy` (§4); no owner answer ⇒ denied |
| mode `access.mode = grantable` | primitive capability `bizcity_agent_access_<mode>` (role or per-user; per-user explicit `false` wins) |

Seeds on first registration (`access.default_roles`), never re-applied over an admin's later change:

| Mode | `access.mode` | Default grant |
|---|---|---|
| `notebook` | grantable | every role that can use TwinChat or Twin GPT (`editor`, `author`, `contributor`, `subscriber` with Twin GPT access) — the data is always their own |
| `astro_self` | grantable | same as `notebook` (own chart only) |
| `sales`, `orders`, `stock` | delegated | CRM `Staff_Policy` role `admin` or `supervisor` |
| `customers` | delegated | CRM `admin`, `supervisor` = whole shop; CRM `lead`, `agent` = **only contacts assigned to them** (D-TAA-7, decided 2026-10-01; per-person pack, work item CL-15/BC-15 — not coded yet) |
| `deep_analysis` | grantable | administrators only |

Example (owner's words): a WordPress **editor** with no CRM role ⇒ `notebook` (and `astro_self` for their own chart) — no sales, orders, customers, stock.

Plans are applied **after** access: a mode allowed here is still absent if the key's snapshot capability excludes it (PHASE-0.85).

## 4. Delegation to CRM `Staff_Policy`

`bizcity_agent_mode_delegate` answer for business modes: `BizCity_CRM_Authority::can( 'crm.report.view', [], BizCity_CRM_Actor::for_user( $user_id ) )` for `sales`/`orders`/`stock`, and `crm.contact.read_all` for `customers` (exact action names fixed in lane CL-12 against the CRM action catalog; if an action does not exist the mode stays denied). Roles: `ROLE_ADMIN`, `ROLE_SUPERVISOR` pass; `ROLE_LEAD`, `ROLE_AGENT`, `ROLE_NONE` fail in v1.

## 5. First `user_id` for notebooks (invariant)

- The `owner_knowledge` pack is built **per `user_id`** from notebooks whose owner is that user (KG-Hub `owner_id`), including daily notebooks captured from that user's own number (PHASE-0.86).
- No mode, grant or role — administrators included — makes an owner turn read another user's notebooks. Shared/team knowledge reaches customers and staff only through the Guru's `knowledge` pack (base block, R-GP-6 opt-in).

## 6. Projection to brain-core

The cell never evaluates capabilities. The site projects, for each **bound principal** (a user who owns a zalo-hub number, or who has an active web session on an axis surface), its allowed modes:

- **Zalo:** bundle field `accounts[].owner_agent = { enabled, principal: { user_hash, modes: [...] } }` for the number's `owner_user_id`. Recomputed and pushed (debounced, ≤ 60 s) when a role, a per-user grant, a CRM staff role or the number's owner changes.
- **Web:** the site's `/turn` prepare call sends `principal.modes` computed at that moment (site key authenticates the claim, PHASE-0.84 contract A).

The cell attaches only listed modes; a mode it does not know is ignored; an empty list ⇒ Owner Agent block with persona only (no data tools).

## 7. UI

Bot Studio → number → "Agent của chủ" (ActionSheet, R-SETTINGS-4L): shows the bound owner, the modes they have (read from this contract, with the source: admin / role / CRM role / per-user), and links to where each is changed (WordPress role, CRM Staff screen, per-user override). CRM Staff screen shows the same list per staff member. No second permission store.

## 8. Invariants

| # | Invariant |
|---|---|
| AMA-1 | `current_user_can( 'bizcity_agent_mode_<mode>' )` on the current blog is the only answer; no parallel list. |
| AMA-2 | Storage = WordPress capabilities (`bizcity_agent_access_<mode>`) or the delegated owner's store (CRM). No new table, no generic option. |
| AMA-3 | Delegated mode without an owner answer ⇒ denied. |
| AMA-4 | `notebook` data is always the principal's own (§5). |
| AMA-5 | The cell enforces the projected list in the tool schema **and** in the pack store (R-TAA-8). |
| AMA-6 | A grant change reaches the cell within the pack freshness window (60 s, D-TAA-3). |

## 9. Tests (lane CL-12 / BC-3)

1. editor ⇒ `[notebook, astro_self]`; administrator ⇒ all; CRM supervisor (WP editor) ⇒ `notebook, astro_self, sales, orders, customers, stock`.
2. Per-user `bizcity_agent_access_notebook = false` on an editor ⇒ `notebook` denied.
3. `owner_knowledge` pack of user A never contains a notebook of user B, even when A is administrator.
4. Cell: mode absent ⇒ its tools absent from the schema and its pack read refused.
