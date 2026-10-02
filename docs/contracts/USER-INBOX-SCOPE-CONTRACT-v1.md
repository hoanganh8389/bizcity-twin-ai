# BizCity User Inbox Scope Contract v1

> **Contract:** `user-inbox-scope@1.0.0` · catalog `contract-catalog.json` (public v1)
> **Schema:** `core/twin-core/contracts/schema/public/v1/user-inbox-scope.schema.json` + `fixtures/user-inbox-scope.{valid,invalid}.json`
> **Rule:** [R-USER-INBOX-SPINE](../rules/PHASE-0-RULE-USER-CENTRIC-INBOX-SPINE.md) §6 (normative text) ·
> [R-LEADER-MEMBER](../rules/PHASE-0-RULE-LEADER-MEMBER-WORKSPACE.md) R-LM-1/R-LM-2/R-LM-8
> **Producer:** `BizCity_CRM_Inbox_Access::resolve_user_inbox_scope()` (`plugins/bizcity-twin-crm/includes/class-inbox-access.php`) +
> Zalo Bot linked-user producer (admin branch).
> **Consumers:** C `/gpt/crm/` exact Inbox (`user_scope`), B2 selected-user adapter (**partial** — SPINE §9), Context Bank CX2.
> **Status (2026-09-18):** schema + fixtures + catalog ✅; C wired; B2 selected-user adapter 🟡. Written as the missing
> contract page (CRM master roadmap M0-09); it restates the schema, SPINE §6 stays normative.

## 1. Envelope

| Field | Type | Meaning |
|---|---|---|
| `contract` / `version` | `user-inbox-scope` / `1.0.0` | two fields, not `name@semver` |
| `surface` | `B2_ADMIN_CRM` \| `C_PUBLIC_TWINGPT` | resolved by the server, never by the client |
| `principal` | `{ blog_id, user_id }` | on C always the logged-in user; on B2 the user chosen through `Staff_Policy` |
| `phone_spine` | `{ status, phone_key?, phone_masked?, verification_source?, verified_at? }` | `status` ∈ `verified/unverified/conflict/absent/revoked`; `phone_key` is an HMAC, never a raw phone |
| `branches.customer[]` | `scope_item[]` | Zone 1 customer-care inboxes |
| `branches.admin[]` | `scope_item[]` | Zone 2 internal channels (Zalo Bot…) |
| `denied[]` | `{ scope_id, reason }` | `personal_owner_mismatch` · `membership_missing` · `phone_conflict` · `channel_disabled` · `zone_mismatch` |

`scope_item` (all required unless marked): `scope_id`, `branch`, `zone`, `channel`, `access_mode`
(`owner_only` \| `owner_or_membership` \| `membership` \| `linked_user`), `account_key` (HMAC hex 16–64),
`account_label`?, `crm_mode` (`customer_inbox` \| `disabled`), `capabilities[]`, `context_policy`.

## 2. Invariants

1. `zalo_personal` ⇒ `branch=customer`, `access_mode=owner_only`, `crm_mode=customer_inbox`. Inbox membership alone never
   lists another user's Personal number on C (R-LM-2, R-ZP-OWNER).
2. `zalo_bot` ⇒ `branch=admin`, `access_mode=linked_user`, `crm_mode=disabled`; bound per R-LM-8 (admin bind in Channel
   Gateway or owner bind in "Kênh của tôi").
3. Business channels (`zalo_oa`, `facebook`/`messenger`, `webchat`…) ⇒ `membership` or `owner_or_membership`, never an owner.
4. A principal with N Personal numbers gets **N** customer items (probe `core.channel.personal_multi_account_owner` check 4).
5. No raw phone, provider UID, token, SQL or path anywhere in the envelope; `account_key` and `phone_key` are HMACs.
6. Adding an optional field is a minor bump; changing meaning, removing a field or a new enum value that consumers must
   handle is a major bump.

## 3. Evidence

- Disk: `node core/twin-core/contracts/tests/run-contract-tests.mjs` (valid + invalid fixture).
- Runtime: R-TWEB matrix and LM-T1/T3/T8 (CRM master roadmap M2).
