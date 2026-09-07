# BizCity Zalo Personal & OA Gateway

Connects a personal Zalo account (QR login via the `zca-bridge` sidecar) and a
Zalo Official Account (OAuth v4 + webhook MAC signature) into
`core/channel-gateway` and the `bizcity-twin-crm` Inbox.

- **Framework contract:** [manifest.json](manifest.json) declares this
  plugin's capabilities. See
  [PLUGIN-TWIN-STANDARD.md](../../docs/extending/PLUGIN-TWIN-STANDARD.md) for
  the canonical extension shape and validation command
  (`php bin/twin diagnostics plugin plugins/bizcity-zalo-personal --json`).
- **Requires:** `bizcity-twin-ai` host plugin loaded first
  (`BIZCITY_CHANNEL_GATEWAY_LOADED` guard).
- **Docs:** see [docs/](docs/) — connection guide, zca-bridge connect test,
  [development and integration rules](docs/DEVELOPMENT-RULES.md), architecture
  notes and the [VPS deployment runbook](_library/zca-bridge-main/VPS-DEPLOYMENT-RUNBOOK.md).
- **Deployment instruction:** the parent workspace instruction
  `.github/instructions/zalo-personal-vps-deployment.instructions.md` applies
  to this plugin. When this folder is opened alone, use the local shim at
  `.github/instructions/vps-deployment.instructions.md`. The standalone
  sidecar instruction is at
  `_library/zca-bridge-main/.github/instructions/vps-deployment.instructions.md`.
- **Registry status:** `bizcity.zalo-personal` in
  [PLUGIN-CONTRACT-REGISTRY-v1.json](../../docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json)
  — required surfaces include `channel_normalized`, `identity_scoping`,
  `archive_contract`, `file_log`, `error_envelope`.

Zalo Personal is a Zone 1 customer channel per R-ZONE; do not route it into
Zone 2 admin/command handling.
