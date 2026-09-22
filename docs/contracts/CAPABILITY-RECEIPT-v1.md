# Capability Registration Receipt v1

> **Directive:** Johnny Chu - Chu Hoàng Anh · 2026-09-15
> **Roadmap owner:** `docs/roadmaps/PHASE-1.22A-CLOSED-LOOP-EXTENSION-ADOPTION-ENFORCEMENT.md` §WP3
> **Validator:** `bin/validate-capability-receipts.mjs`

A **registration receipt** is the declaration that ties one declared capability
to exactly one owner, one scope and one contract. It is the WP3 answer to
"who owns this capability, under which contract, and where does it come from?"

A receipt is **declaration evidence**. It is not runtime registration proof and
it does not promote a package to `pass`.

## 1. Receipt fields

Every entry in `capabilities.<kind>[]` must resolve to these fields. `id` and
`label` are already required by `bin/bizcity-manifest-validate.php`; the
remaining fields are the receipt.

| Field | Required | Source | Meaning |
|---|---|---|---|
| `id` | yes | capability entry | Stable capability ID, unique across the whole ecosystem |
| `label` | yes | capability entry | Human-readable name |
| `owner` | yes | capability entry, else manifest `owner` | Canonical owner of this capability |
| `scope` | yes | capability entry, else `account_scope`, else manifest `scope` | Tenant/site/user boundary the capability operates in |
| `contract_id` | yes | capability entry | Contract ID from `core/twin-core/contracts/schema/public/v1/contract-catalog.json` |
| `contract_version` | yes | capability entry | Contract version the capability implements |

Derived, not declared in the manifest:

| Field | Derived from |
|---|---|
| `extension_id` | manifest `id` |
| `manifest_version` | manifest `version` |
| `capability_kind` | the `capabilities.<kind>` key |
| `source_artifact` | the manifest path |

## 2. Integrity rules

1. **One owner per capability ID.** The same `id` must not be claimed by two
   different extensions. Violation: `capability.duplicate_id`.
2. **Complete receipt.** All required fields above must be non-empty.
   Violation: `capability.receipt_incomplete`.
3. **Declared ID.** A capability entry without `id` is
   `capability.receipt_missing_id`.
4. **Known contract.** A declared `contract_id` must resolve to a real entry in
   the public contract catalog
   (`core/twin-core/contracts/schema/public/v1/contract-catalog.json`).
   Violation: `capability.contract_unknown`. If the catalog cannot be read, the
   report sets `contract_catalog_available=false` and this rule is skipped
   rather than silently passing.

> **Not a conflict:** reusing one ID string across two capability kinds inside
> the *same* manifest is legitimate. Verified against the reference plugin,
> where `reference.echo` is both a tool `id()` and a workflow `node_id()`
> (`examples/bizcity-reference-plugin/bizcity-reference-plugin.php` lines 26
> and 148) because the tool registry and the workflow-block registry are
> separate namespaces. Only cross-extension collisions are conflicts.

## 3. Typed capability or explicit legacy classification

A package must either declare a typed capability (with a `class` implementing
the matching interface) or be explicitly classified as a legacy adapter through
`package_role: "legacy_adapter"` plus a `sunset` block. Silent untyped
capabilities are not permitted.

**Three interface naming styles exist and all are accepted:**

| Style | Location | Example |
|---|---|---|
| runtime, suffixed | `core/twin-core/contracts/framework-contracts.php`, `content-contracts.php` | `BizCity_Tool_Interface` |
| runtime, unsuffixed | `core/channel-gateway/includes/interface-channel-adapter.php` | `BizCity_Channel_Adapter` |
| SDK, namespaced | `packages/bizcity-framework-sdk/src/Contracts.php` | `BizCity\Twin\Contracts\ToolInterface` |

Matching strips the `BizCity_` prefix, the namespace, underscores and a
trailing `Interface`, so all three collapse to one base name. Capability kind →
required interface base name:

| Kind | Required interface base name |
|---|---|
| `tools` | `Tool` |
| `skills` | `Skill` |
| `agents` | `Agent` |
| `channels` | `ChannelAdapter` |
| `kg_source_adapters` | `KgSourceAdapter` |
| `workflow_blocks` | `WorkflowBlock` |
| `personas` | `PersonaProvider` |
| `output_renderers` | `OutputRenderer` |

Violations: `class_not_found` (declared class exists nowhere in the package),
`class_not_typed` (class exists but implements the wrong interface), and
`untyped_unclassified` (no `class` declared and the package is not classified
as a legacy adapter).

**Legacy filter path.** A package that registers through the legacy filter
surface (`bizcity_register_channel_integrations`, `bizcity_register_agent`,
`bizcity_agent_plugins`, `bizcity_intent_register_providers`, ...) instead of a
typed class must declare `package_role: "legacy_adapter"` plus a `sunset`
block. That classification is the explicit acknowledgement required by §3; it
is not a permanent exemption and the sunset owner must migrate the package to a
typed capability or remove it.

The seven SDK verbs are:

```text
register_plugin · register_tool · register_skill · register_source
register_event  · register_diagnostic · register_ui
```

## 4. Migration window

Receipts are **additive**. During the migration window:

- Missing receipt fields are recorded as reviewed debt in
  `tests/fixtures/capability-receipts/baseline.json`.
- `--strict` fails only on **new** findings.
- Legacy filter compatibility is preserved; no runtime behavior changes.

## 5. What this contract does NOT prove

- It does not prove the capability is registered at runtime.
- It does not prove the declared `class` exists or implements the interface.
- It does not prove consent, approval or scope enforcement.
- It does not authorize removing a legacy adapter or dropping a table.

Runtime registration evidence remains owned by WP11 and the diagnostics probes.

## 6. Inspection

Read-only inspection is exposed through `wp bizcity contracts list|show|check|audit|graph`
(`core/cli/class-bizcity-framework-cli.php`) and the Diagnostics surface. The
receipt validator is the CI-side counterpart of that inspection.