# BizCity Personal

Per-user personal assistant workspace: calendar, tasks, budget, documents,
journal, and chat, scoped to the current WordPress identity.

- **Framework contract:** [manifest.json](manifest.json) declares this
  plugin's capabilities. See
  [PLUGIN-TWIN-STANDARD.md](../../docs/extending/PLUGIN-TWIN-STANDARD.md) for
  the canonical extension shape and validation command
  (`php bin/twin diagnostics plugin plugins/bizcity-profile --json`).
- **Bootstrap:** `bizcity-personal.php` (entrypoint filename differs from the
  plugin directory `bizcity-profile/` — this is the historical name, do not
  rename without a migration plan).
- **Docs:** see [docs/](docs/) for phase-level design notes
  (profile growth surface, hero evaluation, QR/vCard, home personal
  assistant).
- **Registry status:** `bizcity.profile` in
  [PLUGIN-CONTRACT-REGISTRY-v1.json](../../docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json)
  is `partial` — required surfaces include `identity_scoping`,
  `profile_routes`, `runtime_probe`.

Every read/write MUST guard `owner === identity` per R-DA/OWASP A01 — no
cross-user data exposure.
