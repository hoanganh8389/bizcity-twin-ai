# BizCity Twin Admin Navigation Contract v1

> Status: Stable opt-in contract  
> Added: 2026-08-11  
> Product policy: Phase 1.26 Unified Admin Menu  
> Migration notice: the three-visible-group product policy is superseded by
> [R-SETTING-PANEL](../rules/PHASE-0-RULE-SETTING-PANEL.md). This v1 contract,
> schema and registry remain stable compatibility inputs until the
> `setting-panel-registration` schema/adapter and runtime migration pass.

## Purpose

The Admin Navigation Contract standardizes how a module or plugin describes its
WordPress admin navigation without owning the global menu tree. It is a metadata
contract, not a renderer or business-logic contract.

The currently implemented Twin AI runtime exposes three visible site-level groups:

| Group | Canonical slug | Responsibility |
|---|---|---|
| `settings` | `bizcity-ai` | API, gateway, templates, sync and configuration |
| `workspace` | `bizcity-twin-workspace` | Twin Chat, Knowledge, CRM, Channels, Automation and Studio |
| `diagnostics` | `bizcity-twin-diagnostics` | Runtime, loader, logs, probes, schema and health |

The three-group policy belongs to the Phase 1.26 runtime and must not be treated
as the target product IA for new work. The Setting Panel target materializes one
visible Control Panel and adapts this metadata into six TwinShell destinations.
This framework contract continues to define the current registration shape and
deep-link compatibility without copying product business logic.

## Contract Shape

A contract payload has:

- `contract`: always `admin-navigation`;
- `version`: contract semver;
- `top_level_groups`: the three groups implemented by the v1 compatibility profile;
- `items`: submenu metadata owned by modules/plugins through the central registry;
- `slot`: the approved area inside a group;
- `origin`: `core`, `bundle` or `extension`.

Navigation metadata must not contain PHP callables, credentials, SQL, provider
payloads or runtime state. `renderer` is a stable renderer ID resolved by PHP.

## Manifest Integration

Extension manifests may include an optional `navigation` array. Existing manifests
without this field remain valid. New extensions should declare navigation metadata
and register the same provider ID through `BizCity_Admin_Menu_Registry`.

The manifest is discoverability metadata. The registry remains the runtime source
of truth for capability checks, collision handling and WordPress registration.

The opt-in PHP metadata registry is `BizCity_Admin_Navigation_Registry`. Providers
implement `BizCity_Admin_Navigation_Provider_Interface`; their IDs preserve the
public dot-notation form such as `core.channel-gateway`. During the Phase 1.26
migration, the registry validates and inventories metadata while
`BizCity_Admin_Menu` remains the WordPress registration adapter.

## Workspace Placement Policy

Every Workspace item must declare one approved slot:

| Slot | Intended contents |
|---|---|
| `workspace.chat` | Twin Chat and chat workspace surfaces |
| `workspace.profile` | My AI Profile, Guru and persona surfaces |
| `workspace.knowledge` | Knowledge, KG, notebooks used for grounding and memory |
| `workspace.crm` | CRM Inbox, contacts and CRM operations |
| `workspace.channels` | Facebook, Zalo, WebChat and channel operations |
| `workspace.automation` | Workflows, Flows, Scheduler and triggers |
| `workspace.studio` | Writer, Image, Video, Documents and Skill Library |
| `workspace.account` | Account, Usage, Membership and user-owned settings |
| `workspace.extensions` | Default landing area for a new bundle/plugin/extension |

New extension rule:

1. `origin=extension` or `origin=bundle`.
2. `group=workspace`.
3. Default `slot=workspace.extensions`.
4. A move into `workspace.crm`, `workspace.channels`, `workspace.studio`, etc.
   requires the feature to actually belong to that domain and must be recorded
   in its manifest/provider.
5. An extension never creates a new top-level parent or an arbitrary Workspace
   parent slug.

Core-owned items use `origin=core`. A bundle can contribute multiple items to
one slot, ordered by `position`; it does not own the slot itself. The registry
rejects a slot whose prefix does not match its group.

The first migrated bundle provider is `plugins.bizgpt-tool-google`. Its site-level
page is declared as `settings.integrations`; its network OAuth configuration stays
on `network_admin_menu` and is not imported into the site navigation tree.

## Invariants

The following invariants describe the v1 runtime profile. During the Setting
Panel migration, invariant 1 is replaced at the materialization layer by the
one-visible-entry rule; invariants 2-9 remain mandatory.

1. Only the three canonical groups may be visible as site-level top-level menus.
2. A module must not create a top-level menu as a fallback when its parent is unavailable.
3. A `(parent, slug)` pair must have one visible registration owner.
4. Legacy aliases and legacy parents may remain during migration, but they must not
   create a second visible top-level menu.
5. `scope=network` entries must register through `network_admin_menu` and must not
   enter the site-level tree.
6. Diagnostics pages belong under `bizcity-twin-diagnostics`; new diagnostics must
   not be registered under `tools.php`.
7. Providers must be lightweight and must not perform DB/Redis reads, schema repair,
   provider calls or heavy dependency loading during metadata registration.
8. Renderer/page code remains owned by the module; the navigation registry owns only
   navigation metadata and registration.
9. A new bundle or extension has a deterministic destination: `workspace.extensions`.

## Compatibility

The contract is opt-in for new modules. Legacy `add_menu_page()` and
`add_submenu_page()` callers are migrated through adapters and remain supported for
the deprecation window. A slug may move parent while retaining a legacy alias and
redirect/deep-link behavior.

New settings contributions target
[`setting-panel-registration@1.x`](SETTING-PANEL-REGISTRATION-CONTRACT-v1.md).
Existing `admin-navigation@1.x` providers remain valid inputs to the migration
adapter and must not be bulk-renamed or removed before runtime compatibility
evidence exists.

## Validation

- Schema: `core/twin-core/contracts/schema/public/v1/admin-navigation.schema.json`
- Manifest schema: `core/twin-core/contracts/schema/manifest.schema.json`
- Fixtures: `admin-navigation.valid.json` and `admin-navigation.invalid.json`
- Contract test: `node core/twin-core/contracts/tests/run-contract-tests.mjs`
- Runtime probe: `core.admin_menu.unified`
