# Setting Panel Registration Contract v1

> Contract ID: `setting-panel-registration`  
> Version: `1.0.0`  
> Status: Manifest/schema/fixture/registry foundation implemented - runtime pending
> Owner: Twin AI Core / TwinShell  
> Product canon: [PHASE-0-SETTING-PANEL.md](../../modules/twinshell/docs/PHASE-0-SETTING-PANEL.md)

## 1. Purpose

This contract lets built-in core packages, modules and plugins contribute
configuration or administrative surfaces to the unified TwinShell Control
Panel without owning the global WordPress menu tree.

It standardizes discovery metadata only. It does not standardize all setting
values, replace owner-specific APIs, grant authorization or make TwinShell the
business-logic owner.

All native entries inherit the global `ios-kit-settings-v1` presentation
profile defined by the [iOS Kit parity research](../../modules/twinshell/docs/PHASE-0-SETTING-PANEL-IOS-KIT-PARITY-RESEARCH.md).
This profile is rendered through CoreUI web adapters. A registration cannot
select a different component library, inject theme values or override global
row/control accessibility behavior.

## 2. Relationship to Existing Contracts

`setting-panel-registration@1.x` specializes the existing
`admin-navigation@1.x` contract:

- `admin-navigation` continues to describe WordPress menu/deep-link placement
  and compatibility aliases;
- `setting-panel-registration` describes placement and rendering inside the
  TwinShell Control Panel;
- one provider may expose both views of the same canonical renderer;
- adapters may derive initial Setting Panel metadata from legacy navigation,
  but derived metadata is labelled `legacy_adapter` and is not a native PASS.

The extension `manifest.json` may contain a `setting_panel` array after the
manifest schema is versioned. Until that schema change lands, examples here are
design targets and must not be inserted into manifests that reject unknown keys.

The additive SDK bridge is `BizCity_Twin_Plugin_SDK::register_ui()` with a
`setting_panel` array. It forwards metadata to
`BizCity_Setting_Panel_Registry` without registering a WordPress menu or
loading a renderer. Manifest-level `setting_panel` declarations remain
disabled until the manifest schema is versioned.

TwinShell exposes the normalized, server-authorized read surface through
`GET /wp-json/bizcity-twinchat/v1/shell/setting-panel`. The endpoint filters
capability and network scope, returns no callables/secrets/options, and does
not resolve or execute owner renderers. REST registration and Runtime evidence
remain separate from this source-level contract.

## 2.1 Author guide — registering a setting surface

Any core package, module, bundled plugin or extension can contribute a Control
Panel entry. The registration is metadata only: the owner keeps its renderer,
storage, capability checks and provider credentials.

### Step 1 — Register through the SDK, never through the menu

```php
if ( ! defined( 'MY_PLUGIN_SETTING_PANEL_REGISTERED' )
    && class_exists( 'BizCity_Twin_Plugin_SDK' )
    && class_exists( 'BizCity_Setting_Panel_Registry' ) ) {
    BizCity_Twin_Plugin_SDK::register_ui( array(
        'setting_panel' => array(
            array(
                'contract'        => 'setting-panel-registration',
                'version'         => '1.0.0',
                'id'              => 'bundle.my-plugin.settings',
                'owner'           => 'plugins/my-plugin',
                'origin'          => 'bundle',
                'destination'     => 'control-panel',
                'group'           => 'studio',
                'label_key'       => 'settings.my_plugin.label',
                'description_key' => 'settings.my_plugin.description',
                'icon'            => 'cil-description',
                'capability'      => 'manage_options',
                'scope'           => 'site',
                'surface'         => 'admin_shell',
                'renderer'        => array(
                    'type'           => 'deep_link',
                    'id'             => 'bundle.my-plugin.settings',
                    'canonical_slug' => 'my-plugin-settings',
                ),
                'availability'    => array(
                    'policy'         => 'registered-owner',
                    'dependency_ids' => array( 'plugins.my-plugin' ),
                ),
                'position'        => 700,
            ),
        ),
    ) );
    define( 'MY_PLUGIN_SETTING_PANEL_REGISTERED', true );
}
```

### Step 2 — Survive load order

The framework contracts may load after your plugin. Register immediately when
they exist, otherwise retry on the earliest hook:

```php
if ( class_exists( 'BizCity_Twin_Plugin_SDK' ) && class_exists( 'BizCity_Setting_Panel_Registry' ) ) {
    my_plugin_register_setting_panel();
} elseif ( function_exists( 'add_action' ) ) {
    add_action( 'plugins_loaded', 'my_plugin_register_setting_panel', 1 );
    add_action( 'init', 'my_plugin_register_setting_panel', 1 );
}
```

Do **not** register inside an `is_admin()` guard: CLI, cron and diagnostics
probe contexts must see the same registry. Two production defects were caused
by exactly this mistake (see the checklist's Known Gaps section).

### Step 3 — Pick the right destination and zone

| Destination | Use for |
|---|---|
| `workspace` | Daily work surfaces (Brain, Profile) |
| `settings` | Configuration (gateway, account, appearance, integrations) |
| `control-panel` | Modules, extensions and studio tools |
| `channel-settings` | Channel accounts and bindings — **requires `zone`** |
| `crm-inbox` | Exactly one canonical Inbox renderer |
| `plugins-store` | Catalog and lifecycle surfaces |

`channel-settings` entries must declare `zone` as `customer`, `admin` or
`system`. Zone 1 customer channels (Facebook, Messenger, Zalo OA, WebChat,
Email) and Zone 2 admin channels (Zalo Bot, Telegram, TwinChat BE) must never
be mixed.

### Step 4 — Rules that are enforced

- `id` must be unique; a duplicate is rejected with `duplicate_id:<id>`.
- Two non-`legacy_adapter` entries cannot share a `renderer.id`
  (`renderer_collision:<id>`).
- `renderer.type = external` requires an `https://` `target_url`.
- `position` must be an integer in `0..9999`.
- Never put credentials, option values, callables or PII in the metadata.

### Step 5 — Verify

```bash
php bin/diagnostics-run.php \
  --filter=modules.twinshell.setting_panel \
  --skip-provision \
  --skip-network \
  --format=json
```

The probe reports the registered item count, destination validity, ID
uniqueness, lookup resolution and any rejected registrations. A `fail` or a
non-zero rejection count means the entry was not admitted.

### Reference implementations

| Owner | ID | Pattern |
|---|---|---|
| `core/bizcity-llm` | `core.bizcity-llm.api-gateway` | core, `deep_link` |
| `modules/twinchat` | `core.twinchat.brain` | module, `deep_link` |
| `modules/twinshell` | `core.twinshell.user_preferences` | `scope=user`, `route` |
| `core/channel-gateway` | `core.channel-gateway.zone1` | `zone=customer` |
| `plugins/bizcity-twin-crm` | `bundle.twin-crm.inbox` | single Inbox owner |
| `plugins/bizcity-facebook-bot` | `bundle.facebook-bot.channels` | bundle, Zone 1 |
| `plugins/bizcity-zalo-bot` | `bundle.zalo-bot.channels` | bundle, Zone 2 |
| `plugins/bizgpt-tool-google` | `bundle.google_tools.settings` | integration, no secret |

## 3. Envelope

A native registration has this shape:

```json
{
  "contract": "setting-panel-registration",
  "version": "1.0.0",
  "id": "plugins.bizcity-doc.settings",
  "owner": "plugins/bizcity-doc",
  "origin": "bundle",
  "destination": "control-panel",
  "group": "documents",
  "label_key": "bizcity_doc.settings.label",
  "description_key": "bizcity_doc.settings.description",
  "icon": "cil-description",
  "capability": "manage_options",
  "scope": "site",
  "surface": "admin_page",
  "renderer": {
    "type": "deep_link",
    "id": "plugins.bizcity-doc.settings",
    "canonical_slug": "bizcity-doc-settings"
  },
  "availability": {
    "policy": "registered-owner",
    "dependency_ids": ["plugins.bizcity-doc"]
  },
  "position": 300,
  "keywords": ["document", "pdf", "export"],
  "diagnostics": {
    "probe_id": "plugins.bizcity_doc",
    "help_code": "module_not_loaded"
  }
}
```

Presentation profile and theme tokens are intentionally absent from each entry:
the registry applies `ios-kit-settings-v1` globally to prevent per-plugin drift.

## 4. Required Fields

| Field | Type | Rule |
|---|---|---|
| `contract` | string | Exactly `setting-panel-registration` |
| `version` | semver | Producer version; consumer accepts compatible `1.x` |
| `id` | string | Stable dot-notation ID, unique across the registry |
| `owner` | string | Canonical package/module owner path or owner ID |
| `origin` | enum | `core`, `module`, `bundle`, `extension`, `legacy_adapter` |
| `destination` | enum | One of the six canonical destination IDs |
| `group` | string | Stable owner/domain grouping ID, not a translated label |
| `label_key` | string | Translation key; not a machine ID fallback |
| `icon` | string | Existing approved CoreUI icon ID |
| `capability` | string | WordPress capability checked server-side |
| `scope` | enum | `site`, `network`, `user` or `site_user` |
| `surface` | enum | `admin_shell`, `admin_page`, `network_admin`, `external` |
| `renderer` | object | Approved renderer descriptor |
| `availability` | object | Declarative dependency policy |
| `position` | integer | `0..9999`; deterministic secondary sort uses stable ID |

## 5. Optional Fields

| Field | Type | Purpose |
|---|---|---|
| `description_key` | string | Concise translated supporting text |
| `badge` | enum | `new`, `beta`, `pro`, `update`, `deprecated`; display only |
| `keywords` | string array | Locale-neutral search aliases, no secrets or PII |
| `zone` | enum | `customer`, `admin`, `system`; required for channel entries |
| `plan` | string | Informational minimum plan; server entitlement remains final |
| `required` | boolean | Whether missing availability is a product health failure |
| `aliases` | string array | Legacy page slugs or route IDs |
| `diagnostics` | object | Probe/help metadata |
| `docs_url` | string | Approved documentation link |
| `order_lock` | enum | `none`, `system_first`, `system_last` |

## 6. Destination Enum

| ID | Allowed content |
|---|---|
| `workspace` | Brain and primary work surfaces |
| `settings` | Shared product configuration |
| `control-panel` | Core/module/plugin setting catalogs |
| `channel-settings` | Canonical Channel Gateway settings |
| `crm-inbox` | Canonical CRM Inbox route only |
| `plugins-store` | Trusted plugin catalog and lifecycle |

Contributors cannot define new first-level destinations in contract v1.

## 7. Renderer Descriptor

### 7.1 Common fields

| Field | Required | Rule |
|---|---|---|
| `type` | yes | `coreui`, `route`, `embed`, `deep_link` or `external` |
| `id` | yes | Stable renderer resolver ID |
| `canonical_slug` | conditional | Required for `deep_link`; allowed for route compatibility |
| `route` | conditional | Required for native `route`/`coreui` renderers |
| `target_url` | conditional | Required for `external`; server-produced allowlisted URL |
| `allowed_query_keys` | optional | Explicit scalar keys forwarded to route/embed |
| `shell_mode` | optional | `detail`, `full`, `external`; default `detail` |

### 7.2 Renderer invariants

- No PHP callable is serialized into manifest or REST output.
- Renderer IDs resolve through a server registry.
- Same-origin is required for `route`, `coreui` and `embed`.
- `external` URLs cannot include credentials or arbitrary user-provided hosts.
- `deep_link` must name a canonical slug and may declare legacy aliases.
- Renderers recheck authorization; successful discovery does not grant access.

## 8. Availability Descriptor

```json
{
  "policy": "all",
  "dependency_ids": ["core.channel-gateway", "plugins.bizcity-twin-crm"],
  "min_framework": "1.0.0",
  "min_php": "7.4",
  "min_wp": "6.0"
}
```

Allowed policies:

- `registered-owner`: owner/provider registration is sufficient;
- `all`: all listed dependency IDs must be available;
- `any`: at least one listed dependency ID must be available;
- `always`: reserved for core destination descriptors.

Availability metadata cannot invoke classes, functions or remote calls from a
manifest. Runtime adapters may map legacy `class`/`function` checks to bounded
dependency IDs, but must report them as legacy debt.

## 9. Normalized Runtime Entry

The server may enrich a valid registration before sending it to TwinShell:

```json
{
  "id": "plugins.bizcity-doc.settings",
  "destination": "control-panel",
  "group": "documents",
  "label": "Document settings",
  "description": "Configure document generation and exports.",
  "icon": "cil-description",
  "state": "ready",
  "state_reason": "",
  "can_view": true,
  "can_manage": true,
  "renderer": {
    "type": "deep_link",
    "id": "plugins.bizcity-doc.settings",
    "url": "/wp-admin/admin.php?page=bizcity-doc-settings"
  },
  "diagnostics": {
    "probe_id": "plugins.bizcity_doc",
    "status": "pass"
  }
}
```

Runtime-only fields such as translated label, resolved URL, state and
capability decisions are not persisted back into the manifest.

## 10. State Model

| State | Meaning | Required UI behavior |
|---|---|---|
| `ready` | Available and authorized | Open renderer |
| `unavailable` | Optional dependency absent | Explain requirement; no broken link |
| `locked` | Entitlement/capability blocks management | Show reason and approved next action |
| `degraded` | Owner loaded but a dependency/service is impaired | Allow safe reads; show structured warning |
| `incompatible` | Framework/PHP/WP contract mismatch | Block load and identify supported version |
| `update_required` | Compatible path requires package update | Route to trusted update owner |
| `hidden_by_policy` | Server policy excludes the item | Omit from ordinary UI; retain diagnostics evidence |

The client cannot change a state to `ready` on its own.

## 11. Capability and Scope

Discovery performs an initial server-side view check. Every renderer and
mutation repeats its own authorization check.

- `site`: current routed blog/tenant only.
- `network`: network admin only; excluded from site bootstrap.
- `user`: current server-resolved user only.
- `site_user`: current user within the current routed tenant.

An owner needing site and network entries registers separate IDs. A posted
`blog_id`, `user_id`, `owner_id`, `account_id` or `inbox_id` never expands scope.

## 12. Channel and CRM Constraints

- Entries under `channel-settings` require `zone`.
- Zone 1 and Zone 2 are separate groups and cannot share an Inbox renderer.
- Account-specific settings resolve exact account ownership server-side.
- `crm-inbox` accepts one canonical CRM renderer registration; extensions add
  CRM tools through CRM-owned extension points, not another Inbox.
- Phone or provider labels are correlation/display data, not ACL.

## 13. Plugins Store Constraints

Only the Marketplace/WordPress lifecycle owner can register lifecycle actions.
Third-party extensions may register a detail page but cannot declare themselves
trusted installers.

Install/update/activate/deactivate operations require:

- approved catalog/package identity;
- capability and nonce validation;
- version and compatibility checks;
- integrity/signature evidence where available;
- explicit user confirmation for destructive or availability-changing actions;
- structured result beyond HTTP status;
- rollback/recovery guidance.

## 14. Compatibility

Contract v1 uses additive semver within `1.x`. Consumers ignore unknown optional
fields only when the schema explicitly permits them. Unknown required semantics
require a major version.

Legacy `admin-navigation` entries can be adapted when they provide a stable
owner, capability, scope, renderer/deep-link and availability boundary. The
adapter must add:

```text
origin=legacy_adapter
native_contract=false
migration_owner=<owner>
sunset_after=<release or phase>
```

Adapters cannot invent a renderer or broaden a capability.

## 15. Validation Plan

Implemented artifacts:

- `setting-panel-registration.schema.json`;
- `setting-panel-registration.valid.json` covering core, module, bundle, CRM
  and legacy-adapter entries;
- `setting-panel-registration.invalid.json` covering unknown destination and
  unsafe external URL rejection;
- additional invalid fixtures covering missing capability, ambiguous scope and
  unsafe external URL;
- focused registry test coverage for duplicate IDs and native renderer
  collisions;
- framework manifest schema `schema_version=1.1` and optional public extension
  manifest `setting_panel` field;
- contract catalog row;
- semantic uniqueness/destination/legacy checks in `run-contract-tests.mjs`.

Pending artifacts:

- invalid fixtures for every remaining failure class;
- runtime probe `modules.twinshell.setting_panel`.

Validation statuses remain distinct:

```text
schema_fixture_pass != registry_pass != runtime_pass != production_pass
```

## 16. Failure Reasons

Canonical reason buckets:

- `contract_invalid`
- `unsupported_contract_version`
- `registry_conflict`
- `renderer_collision`
- `unknown_destination`
- `scope_mismatch`
- `capability_denied`
- `owner_unavailable`
- `dependency_missing`
- `renderer_unavailable`
- `unsafe_target_url`
- `update_required`

User-visible responses map these reasons into the R-ERROR-UX envelope.

## 17. Non-Goals

This contract does not:

- define a universal settings value schema;
- move or duplicate current option/data stores;
- authorize a user because an item is visible;
- define plugin package distribution by itself;
- replace admin-navigation, diagnostics, Channel Gateway or CRM contracts;
- permit direct remote provider calls from the panel.
