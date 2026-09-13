# Plugin Contract Registry Adoption v1

> **Directive:** Johnny Chu - Chu Hoàng Anh · 2026-09-13

> Internal/public-contract companion for the optional registry adoption metadata.
> The registry remains discovery-only until package evidence receipts are current.

## Metadata

Registry rows may declare these optional fields during the v1.x migration window:

| Field | Meaning | Promotion boundary |
|---|---|---|
| `role` | One canonical package role from the framework role catalog | Metadata only until applicable stages are evidenced |
| `applicable_stages` | Closed-loop stages relevant to this package | Does not imply any stage PASS |
| `distribution` | Public framework or approved private/legacy overlay boundary | Does not prove Git history cleanup |
| `probe_ids` | Candidate/current diagnostics probe IDs owned by the package | Empty means runtime evidence is not yet attached |
| `sunset` | Owner/status metadata for legacy adapters | Does not authorize removal or DROP |

## Role Catalog

The nine roles are:

`core` · `module` · `channel_owner` · `framework_integrated` ·
`vertical_extension` · `optional_utility` · `private_pro_utility` ·
`legacy_adapter` · `reference_only`.

The role fixture at
`tests/fixtures/plugin-adoption-role-fixtures.json` records the minimum
applicable-stage shape for each role. It is a shape/applicability fixture, not
runtime compliance evidence.

## Migration Matrix

| Version | Requirement | Legacy behavior |
|---|---|---|
| Registry 1.0.x | Existing fields remain valid; adoption fields are optional | Rows without adoption metadata remain discoverable and `partial/review` |
| Registry 1.1.x | Rows may declare role, applicable stages, distribution, probe IDs and sunset | Validator checks fields when present; no runtime promotion |
| Manifest 1.x | `package_role`, `spine` and `evidence` remain optional | Existing manifests continue to validate unchanged |
| Manifest 2.0 | New framework-integrated/channel-owner/vertical-extension packages must declare applicable spine metadata | Legacy packages require explicit migration adapter and owner/sunset metadata |

## Status Rules

- Registry discovery is not contract-static, runtime, or closed-loop PASS.
- An empty `probe_ids` list means no current probe receipt is attached.
- `partial`, `review`, `warn`, `skip`, `deferred` and `legacy_adapter` do not
  satisfy the release gate.
- `distribution=private_overlay` or `legacy_private` is a packaging declaration;
  it does not prove historical source removal.
- Lifecycle, schema and runtime changes remain governed by their owning rules and
  probes; this document does not authorize destructive cleanup.

## Storage and Context Bank adoption

Every new or migrated package that owns reusable business/context data must also
declare `extension-storage-context@1.0.0` before adding a table, log writer,
filestore, option, user meta, CPT, Event Stream projection or KG artifact.

The declaration must identify:

```text
storage_decisions[]
  -> role/criticality/capacity/target/CRUD/canonical owner/rollback owner
context_bank
  -> encrypted payload owner/receipt/pointer-only ledger/rollup/retrieval pack
mpr_bridge
  -> context-retrieval-pack@1.x/citation/phase events/Brain owner
evidence
  -> decision record/probe IDs/Disk-Loader-Runtime state
```

The default decision order is:

1. Reuse an existing canonical owner and repository.
2. Use existing CPT, option/site option or user meta for small, low-churn data
   when scope and ownership match.
3. Use encrypted Business JSONL File Store for durable reusable payloads and
   contracted JSONL for logs/traces.
4. Create a typed tenant SQL table only for atomic, relational, high-volume or
   hot-path correctness state; keep payload thin and declare Context Bank/MPR
   integration or an explicit reviewed no-context rationale.

`bizcity_context_bank` is a pointer/correlation/provenance ledger, never a
payload warehouse. MPR/TwinBrain consumers use the bounded
`context-retrieval-pack@1.x`; no package may read raw Context Bank files or build
a private retrieval/KG/vector path during a chat turn.

Manifest v1.x adoption is additive through `storage_context`. The next manifest
major makes this declaration mandatory for `vertical_extension`,
`framework_integrated` and `channel_owner` roles. Until then, registry status
must distinguish `storage_context=declared`, `storage_context=missing` and
`storage_context=not_applicable_with_reason`; missing metadata is not PASS.
