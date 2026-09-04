# BizCity Zalo Admin Hook

Legacy Zalo Hotline (ZNS) channel adapter with the `/bizhook/` webhook and
`gateway-functions` glue, network-activated so webhooks stay alive across the
multisite network.

- **Framework contract:** [manifest.json](manifest.json) declares this
  plugin's capabilities. See
  [PLUGIN-TWIN-STANDARD.md](../../docs/extending/PLUGIN-TWIN-STANDARD.md) for
  the canonical extension shape and validation command
  (`php bin/twin diagnostics plugin plugins/bizcity-zalo-bizcity --json`).
- **Status:** `legacy_adapter` per
  [PLUGIN-CONTRACT-REGISTRY-v1.json](../../docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json)
  (`bizcity.zalo-bizcity = partial`). Bounded compatibility path — do not
  extend with new capability; migrate new Zalo channel work into
  `bizcity-zalo-personal` / `bizcity-zalo-bot` per the active channel
  contract instead.
- **Operational docs:** see [GATEWAY-README.md](GATEWAY-README.md).

Normalized inbound payload must still carry the canonical identity tuple
(`platform`, `account_id`, `user_id`, `chat_id`) per R-CH-IDMEM even on this
legacy path.
