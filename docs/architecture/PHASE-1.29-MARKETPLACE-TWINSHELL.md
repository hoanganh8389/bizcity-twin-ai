# Marketplace and TwinShell Architecture

Status: implemented locally on 2026-08-27.

## 1. Local bundle listing

The Local Marketplace tab lists installed nested bundle plugins directly from the
filesystem under `bizcity-twin-ai/plugins/`. This is the source of truth for
installed artifacts.

- Exclude every `_archived` directory.
- Show inactive plugins as well as active plugins.
- Use plugin headers when available and fall back to the directory slug for the
  title.
- Do not use the remote catalog table, a transient, or `active_plugins` to
  decide whether an installed artifact is visible.
- The remote Marketplace remains a separate server/catalog surface.

This keeps visibility, activation state, and runtime readiness as three separate
contracts.

## 2. Activation guard

Marketplace activation uses the normal WordPress plugin lifecycle and
`active_plugins` state. Before calling `is_plugin_active()`, the Marketplace
loads `wp-admin/includes/plugin.php` when the function is not already available.

Activation is still restricted by the existing capability and file checks. A
successful activation does not imply that the plugin is fully runtime-ready;
its own loader, routes, dependencies, and diagnostics must still pass.

## 3. Tool Content namespace conflict

`bizcity-tool-content` is a legacy optional plugin. It registers the same
`write_article` Intent namespace used by Core Intent. It must not be activated
alongside Core Intent until that namespace is migrated and ownership is explicit.

The Marketplace may list and manage the artifact, but listing it does not make
it a Core dependency and the main loader must not add it to `must_load`.

## 4. TwinShell registry and route gate

Marketplace is registered once in the TwinShell Activity Bar as the
`marketplace` entry in `modules/twinshell/includes/default-plugins.php`.

- The canonical content route is the existing admin page
  `index.php?page=bizcity-marketplace`.
- TwinShell adds `bizcity_iframe=1` when it opens the content route.
- Marketplace hides WordPress admin chrome only for that explicit embedded
  request.
- The legacy `admin.php?page=bizcity-twinchat` route redirects to `/twin/` and
  forwards its allowlisted deep-link parameters. It no longer embeds `/twin/`
  in a second iframe.

Therefore the navigation path is one TwinShell document plus one active plugin
frame, instead of an admin wrapper iframe around TwinShell and a plugin iframe.

## 5. Channel Gateway ownership

Customer-care and command-channel plugins do not become general-purpose Core
Intent dependencies. Facebook, Messenger, Zalo, Telegram, WebChat, email, and
related channel behavior belongs behind the Channel Gateway contracts,
including identity normalization, CRM writes, archive logging, and channel
send ownership.

A bundle plugin may expose a UI entry in TwinShell only when its own route and
capability gate are registered. The UI entry is not a security boundary and
must not bypass Gateway ownership or server-side authorization.

## 6. Optional and partial plugin group

These nested artifacts remain optional and outside the main `must_load` list:

- `bizcity-tool-image`: Image and Product Studio surface; substantial routes
  exist, but legacy paths still require runtime hardening.
- `bizcity-content-creator`: newer Brain Factory/content surface; available for
  explicit activation, with remaining error-payload and runtime integration
  work.
- `bizcity-video-kling`: Video Studio surface; entrypoint and route metadata
  exist, but provider/transport readiness is partial.
- `bizcity-tool-content`: legacy content surface with the Core Intent namespace
  conflict described above.

The Marketplace should keep these artifacts visible when present, show their
activation state, and avoid claiming full readiness until their own runtime
checks pass. Missing cover or icon metadata uses the Marketplace default logo.

## Operational rule

`core/bizcity-market` is the canonical active Marketplace implementation.
Backup or archived MU-plugin copies are not active sources and must not be
edited to change this behavior.
