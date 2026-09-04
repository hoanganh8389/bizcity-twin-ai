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
  and architecture notes.
- **Registry status:** `bizcity.zalo-personal` in
  [PLUGIN-CONTRACT-REGISTRY-v1.json](../../docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json)
  — required surfaces include `channel_normalized`, `identity_scoping`,
  `archive_contract`, `file_log`, `error_envelope`.

Zalo Personal is a Zone 1 customer channel per R-ZONE; do not route it into
Zone 2 admin/command handling.
