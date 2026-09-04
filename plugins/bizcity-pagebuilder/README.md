# BizCity Page Builder

Prompt-to-website generation plugin: visual drag-and-drop editor, typed JSON
blocks, theme presets, and HTML export or WordPress page publish
(`/tool-pagebuilder/`).

- **Framework contract:** [manifest.json](manifest.json) declares this
  plugin's capabilities. See
  [PLUGIN-TWIN-STANDARD.md](../../docs/extending/PLUGIN-TWIN-STANDARD.md) for
  the canonical extension shape and validation command
  (`php bin/twin diagnostics plugin plugins/bizcity-pagebuilder --json`).
- **Primary tool:** `pagebuilder.generate` (Intent Provider primary tool).
- **Docs:** see [docs/](docs/) for design/architecture notes.
- **Registry status:** `bizcity.pagebuilder` in
  [PLUGIN-CONTRACT-REGISTRY-v1.json](../../docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json)
  is `partial` — required surfaces include `gateway_client`, `secret_boundary`,
  `reliable_http`, `mutation_guard`, `error_envelope`, `runtime_probe`. Every
  save/delete/publish mutation MUST send `X-Idempotency-Key`.

Do not call an LLM/image provider directly — all generation goes through
`BizCity_LLM_Client` per R-GW-8.
