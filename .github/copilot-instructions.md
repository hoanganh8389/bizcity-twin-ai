# bizcity-twin-ai — AI Agent Instructions (public)

> Project-scoped rules for AI coding agents working in this repository: GitHub
> Copilot, Claude Code, OpenAI Codex, Cursor and anything else that reads
> repository instructions. Copilot loads this file automatically; Claude Code
> loads it through `CLAUDE.md`; Codex loads it through the generated `AGENTS.md`.
>
> **These rules outrank an agent's own defaults.** When a suggestion conflicts
> with a rule here, the rule wins. When two rules conflict, stop and ask in the
> pull request instead of guessing.
>
> Human contributors: read [CONTRIBUTING.md](../CONTRIBUTING.md) first; this file
> is the machine-facing companion to it.

---

## 0. How to find the rest of the environment

| Layer | File | Use |
|---|---|---|
| These rules | `.github/copilot-instructions.md` | always loaded |
| Environment map | `.github/instructions/agent-environment.instructions.md` | always loaded — index of contracts, guides, READMEs, `bin/` tools, test suites, CI commands, per-module docs folders |
| Doc catalog | `docs/AGENT-DOC-CATALOG.md` | grep it to find the right document before you start |
| Path-scoped rules | `.github/instructions/*.instructions.md` | apply when the file you touch matches their `applyTo` glob |

Both generated files come from `node bin/sync-agent-instructions.mjs`; CI fails if
they drift. Never edit them, `AGENTS.md`, or the marker block in `CLAUDE.md` by hand.

### `.local.` means private — never publish it, never copy from it

**Any instruction, rule, memory or index file whose name contains `.local.` is a
private operator file: it is git-ignored and must never be committed, quoted,
summarised or copied into a published file, issue, pull request or commit message.**
The plain name is the public counterpart; the `.local.` twin holds deployment
identity (hosts, operator paths, log locations, tenant ids, incident history).

| Public (committed) | Private twin (`.local.`, git-ignored) |
|---|---|
| `.github/copilot-instructions.md` | `.github/copilot-instructions.local.md` |
| `.github/instructions/<name>.instructions.md` | `.github/instructions/<name>.local.instructions.md` |
| `.github/instructions/agent-environment.instructions.md` | `.github/instructions/agent-environment.local.instructions.md` |
| `docs/AGENT-DOC-CATALOG.md` | `docs/AGENT-DOC-CATALOG.local.md` |
| `CLAUDE.md` | `CLAUDE.local.md` |

Rules for every agent:

- Adding a private instruction/index file: name it `*.local.*`, put it in
  `.gitignore`, and keep the public counterpart usable on its own.
- A `.local.` file may reference public rules; a public file may **not** depend on
  a `.local.` file, because contributors never receive one.
- Content that belongs in a `.local.` file: host names, SSH users, absolute server
  paths, log paths, customer domains, tenant/blog ids, real probe command values,
  deployment steps, incident history. Public files use placeholders instead.
- `node bin/sync-agent-instructions.mjs` enforces both directions: a `.local.`
  file that is not git-ignored, or a git-ignored agent instruction file without
  `.local.` in its name, fails the check. Exit codes: `1` drift, `2` deployment
  identity in a public file, `3` dead link in a committed doc, `4` naming.
  `node bin/sync-agent-instructions-fixtures.mjs` proves all four on disposable
  fixtures, and `git config core.hooksPath .githooks` runs the check pre-commit.
- Not every unpublished document uses this convention — whole directories can be
  excluded in `.gitignore` instead (their names are listed only in the `.local.`
  index). The `.local.` infix is mandatory for **instruction, memory and index**
  files that sit next to a published counterpart.

**Working protocol — follow it for every task:**

1. **Locate** the area you are about to change: core module, bundled plugin,
   channel, database, cron, REST route, or frontend bundle.
2. **Read before writing.** Open the contract and the module docs folder for that
   area (environment map §2 and §7) and grep the doc catalog for the current
   phase/roadmap document. Do not reconstruct a rule from memory when a file states it.
3. **Reuse the existing tool.** Check the `bin/` table (environment map §5) before
   writing any new script. Ad-hoc debug scripts are not an accepted substitute for
   the registered diagnostics probes and validators.
4. **Validate** with the CI/composer command that covers what you changed
   (environment map §6) and report the real result: `PASS`, `FAIL` or `SKIP`.
5. **Keep docs in step.** Update the owning document in the same change, and
   re-run the sync script if you added, renamed or removed docs, `bin/` tools,
   tests, composer scripts or CI steps.

Some internal rule documents, roadmaps and audits are not published in this
repository. If this file summarises a rule and you cannot find its full spec,
the summary here is authoritative for your change.

---

## 1. Product direction — one brain, not a pile of chatbots

`bizcity-twin-ai` is an **analytical brain for a business**, not a collection of
unrelated plugins. Three architectural axes; every change must strengthen one and
break none:

1. **Horizontal — Channel Gateway.** Normalize inbound/outbound business data
   (CRM, POS, inventory, messaging) into one tenant- and identity-resolved spine.
2. **Vertical — brain modes.** Each vertical capability integrates through a
   declared contract and reuses the shared spine. It never grows a second brain
   or a private data pipeline.
3. **Knowledge — KG graph.** Documents and internal knowledge are built, linked
   and retrieved through the Knowledge Graph / Graph RAG layer, which is the
   evidence base for reasoning and decisions.

The canonical downstream order is:
`Channel → CRM → Context Bank → KG-Hub → Brain/reasoning → Twin Core → MCP/actions`.

MCP servers and external tools are consumers behind existing contracts,
permissions and tenant boundaries. They are never a source of truth and never a
way around the brain.

**Stop and redesign** if a request would fragment data, create a second brain,
skip the knowledge/evidence layer, bypass the Channel Gateway, or break an
extension contract.

---

## 2. Topology — this plugin is the client, the gateway lives elsewhere

```
┌─────────────────────────────────────┐        ┌──────────────────────────────┐
│ CLIENT site (this plugin)           │        │ GATEWAY server (hosted)      │
│  core/bizcity-llm  (client library) │ HTTPS  │  provider keys, routing,     │
│   BizCity_LLM_Client                │ ─────▶ │  quota, billing, catalogs    │
│   BizCity_Search_Client             │ Bearer │                              │
│   BizCity_Video_Client              │        │                              │
│  proxy REST routes (same origin)    │        │                              │
└─────────────────────────────────────┘        └──────────────────────────────┘
```

**R-GW-8 · client standalone.** The router/gateway plugin exists only on the
vendor's servers. A client site installs `bizcity-twin-ai` alone and must keep
working that way.

```php
// ✅ Server-side PHP: go through the client wrapper and degrade gracefully.
if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
    return array( 'success' => false, '_degraded' => true,
                  'message' => 'BizCity LLM client is not loaded.' );
}
$llm = BizCity_LLM_Client::instance();
if ( ! $llm->is_ready() ) {
    return array( 'success' => false, '_degraded' => true,
                  'message' => 'BizCity API key is not configured.' );
}
$response = $llm->chat( $messages, array( 'purpose' => 'reasoning' ) );
```

Forbidden:

- ❌ Referencing server-only router classes (`BizCity_Router_*`) from client code.
- ❌ Reading or storing provider credentials (OpenRouter, search, video, …) on a client site.
- ❌ Frontend code fetching the vendor domain directly, or calling the server-only
  `bizcity/v1` namespace on a client site. Add a same-origin proxy route instead
  (`X-WP-Nonce`), and let the PHP wrapper talk to the gateway.
- ❌ Returning `5xx` when the gateway is unavailable. Fail **open**:
  `200 + success:false + _degraded:true`, so the frontend does not retry-loop.
- ❌ Telling users to install the router plugin on their own site.

**Credential boundary.** One API key is an opaque credential *and* a license
identity. Read it only through `BizCity_LLM_Client::instance()->get_api_key()`
and the gateway URL through `get_gateway_url()`. Never build an
`Authorization: Bearer` header from a raw option in a feature module, never
rewrite or normalise the key body (the separator is part of the secret), and
never log a full key, hash, header, DSN or token. Plan, quota and entitlement
always resolve from the exact key the request sent — never from a user id, user
meta, "latest key for this user", or a cache keyed by user alone.

**Resources come from the API, not from hard-coded lists.** Templates, catalogs,
OAuth apps, marketplace data and similar resources are fetched through a client
wrapper. If an endpoint is missing, it must be added on the server with a
documented spec before the client feature ships.

---

## 3. Multi-tenant database rules (R-MSDB)

This framework runs on single-site WordPress **and** on multisite installations
where tenants can live on different physical database shards. Code that assumes
`$wpdb` always points at the same database is a security bug, not a style issue —
a wrong route reads or writes another tenant (OWASP A01).

Routing chain — if one link cannot be proven, **fail closed**:

```text
HTTP domain → blog id → tenant identifier → shard config
  → connection → per-shard verification marker → tenant query
```

Rules:

- **Global vs tenant storage are different tiers.** Network registry/identity
  tables use `$wpdb->base_prefix`; every tenant table uses `$wpdb->prefix`.
  Never move tenant data to the base prefix just to simplify a query.
- **Fail closed.** On any routing failure: do not execute tenant SQL, do not fall
  back to the global database or the current connection, do not force blog 1.
  Record a reason bucket and return a structured error (see §7).
- **`switch_to_blog()` is a physical boundary.** Compute table names, options,
  cache keys and paths *after* the switch, and always `restore_current_blog()` in
  a `finally` block.

```php
$origin = get_current_blog_id();
switch_to_blog( $target_blog_id );
try {
    $table = $wpdb->prefix . 'bizcity_items'; // AFTER the switch
    // …tenant work only…
} finally {
    restore_current_blog();
}
```

- **Cache keys carry tenant identity.** Every tenant cache key includes the blog
  id (plus the physical database when the value depends on it). Never cache a
  degraded or fallback result as if it were a valid tenant result.
- **No bulk DDL in a web request.** Do not create tables for every site on `init`,
  `plugins_loaded` or at file scope. Batch it through cron/CLI with checkpoints.
- **Shared code must run standalone.** Only call routing-specific classes behind
  `class_exists()`/`method_exists()` guards, and never ship shard configuration
  or credentials to a client site.

---

## 4. Schema, registries and caching

### R-DCL · schema changelog first

Before any `dbDelta`, `CREATE TABLE` or `ALTER TABLE`, and before any probe that
checks or repairs schema:

1. Update `core/diagnostics/changelog/<module_id>.json`: bump `current_version`
   and push a `{version, date, change}` row; every new column/index carries a
   matching `since`.
2. Run the validator: `php core/diagnostics/validate-schema-changelog.php`
   (must exit `0`).
3. Repairs must be idempotent. `DROP`/`MODIFY`/`CHANGE` is a hand-written
   migration run through the site provisioner, never an auto-create.

### R-CR · central registries

```php
// Schema: register BEFORE dbDelta, at file scope after the installer class.
BizCity_Schema_Registry::register(
    'bizcity_my_table',              // base name, no prefix
    'my-module.feature',             // module id
    My_Installer::SCHEMA_VERSION,
    My_Installer::VERSION_OPTION,
    array( 'My_Installer', 'install' )
);

// Rewrite rules: register at file load time; the registry performs one flush.
BizCity_Rewrite_Flush_Registry::register( 'my-plugin', MY_STABLE_VERSION );
```

- ❌ Never call `flush_rewrite_rules()` from `init` at any priority.
- ❌ Never derive a flush guard from `time()` — it flushes on every request.
- ❌ Never run `dbDelta()` for a table that is not registered.

### R-CACHE · cache contract for every CRUD class

Every manager/reader that queries the database declares a cache contract block in
its docblock, wraps reads in `BizCity_Cache::get/set`, calls
`BizCity_Cache::flush_group()` after every successful write, and registers its
group with `BizCity_Cache_Registry::register()` at file scope. Cache keys include
every filter argument; a private `static $cache = []` is not acceptable because
it cannot be invalidated.

### R-METADATA-CACHE · never probe schema metadata per request

`SHOW TABLES LIKE …`, `SHOW COLUMNS` and `SHOW INDEX` are forbidden in runtime
code paths. Use the canonical helper:

```php
BizCity_Table_Metadata::table_exists( $table );
BizCity_Table_Metadata::column_exists( $table, $column );
BizCity_Table_Metadata::invalidate( $table ); // after successful DDL
```

Cache both `true` and `false` with a finite TTL, key by blog and physical
database, and never write options or transients from an existence getter.

### R-DATA-STORAGE · choose storage deliberately

Before creating a table, file store, option, user meta or post type, classify the
data: role, criticality, volume, consistency needs, query shape, retention,
rebuildability and sensitivity. Rough guide:

| Data | Target |
|---|---|
| Logs, traces, operational telemetry | contracted JSONL file logger + pointer index |
| Durable business records, relearnable memory | encrypted business JSONL file store |
| Ordered timelines and events | the event stream owner |
| Core state, relations, locks, queues, counters, billing, hot queries | typed SQL table + repository |
| Small, rarely changed configuration | WordPress options at the right scope |
| Editorial catalog content | an existing post type (reuse before inventing) |

Post types are never the answer for logs, hot-path memory, junction tables,
mutexes, queues or counters.

---

## 5. Loading, performance and PHP floor

### R-PERF · surface-scoped loading

This plugin has well over a thousand PHP files. Loading everything on every
request is a production defect.

- Classify the surface first: public HTML, admin shell, specific admin page,
  REST route, webhook, cron, CLI, diagnostics.
- Gate admin/REST/cron-only modules behind the shared admin-context flag; keep
  public shortcodes and rewrite rules registered where they must be.
- Never call the database, object cache, `get_option()`, `dbDelta()` or
  `wp_next_scheduled()` at file scope.
- Defer probe and heavy-class loading to `current_screen` or the matching REST
  namespace, wrapped in a `static $done` guard.
- `wp_schedule_event()` always pairs with `! wp_next_scheduled()` and a context guard.
- When a loader has a compat copy (for example under `mu-plugins/`), both copies
  must carry the same gate; fixing one is a regression in waiting.

### R-SAFE-LOADER · guarded artifact loading

Bootstraps load module artifacts through the safe loader, check
`is_file()` + `is_readable()`, catch load-time `Throwable`, and degrade when an
artifact is missing. Raw `require`/`require_once` for module, probe or provider
artifacts in a bootstrap is rejected by CI. Never log full paths, SQL, tokens or PII.

### R-ORPHAN-FILE · retiring a PHP file

A retired file is renamed to `<name>_deleted.php`, carries an "ORPHAN FILE — DO
NOT USE" banner naming the canonical owner, and has its historical body wrapped in
`if ( false ) { … }` so it declares **zero** classes. A duplicate class
declaration that loads first silently replaces the canonical one. Never treat a
`*_deleted.php` file as a source of truth, and never rename a live file to
`_deleted` as a way to switch it off.

### PHP 7.4 compatibility floor

Target runtime is **PHP 7.4** on customer hosting. PHP 8-only syntax is a fatal
error there:

| ❌ Not allowed | ✅ Use |
|---|---|
| `function f(): int\|string` | drop the return type, document `@return int\|string` |
| `$obj?->method()` | explicit null checks |
| `match (…)` | `switch` / ternary |
| constructor promotion, `readonly`, enums | plain properties and class constants |
| `str_contains` / `str_starts_with` / `str_ends_with` | `strpos() !== false`, `substr()` comparisons |
| named arguments, first-class callables, `never` | positional args, `[$this, 'method']` |

Allowed 7.4 features: typed properties, arrow functions, `??=`, array spread,
numeric literal separators.

---

## 6. Channels, identity and zones

### R-CH-NS · REST namespace

Every channel route — in `core/channel-gateway` and in every channel plugin —
uses `bizcity-channel/v1`. The `bizcity/v1` namespace belongs to the gateway
server; reusing it on a client site causes route collisions and 404s. Change the
namespace in PHP and the matching constant in the JS/TS API slice together.

### R-ZONE · two channel zones that never mix

| | Zone 1 — customer channels | Zone 2 — admin/command channels |
|---|---|---|
| Purpose | customer support: inbound → CRM inbox → human or AI reply | staff instructing the system: automation, workflows |
| Inbound target | CRM tables | automation + brain runtime |

Every emitter tags `platform` and `code`; every Zone 2 listener bails out on a
Zone 1 payload and vice versa. Never create a customer inbox record for an
admin/command channel, and never render admin-command conversations in the
customer inbox.

### R-CH-IDMEM · identity-scoped continuity

Every normalized payload carries `platform`, `account_id`, `user_id` and
`chat_id`. Sessions use the canonical per-channel key derived from the account
and the counterpart identity — never a technical conversation id as the primary
key, and never one shared prefix for two different channel types. A group chat is
conversation context, never a personal identity, and must not pull private memory.

### R-CH-FILE-LOG · file evidence before database writes

Every channel dispatcher writes a JSONL evidence line **before** any database
call, and an outer `try/catch` always writes a failure line — not only when
debugging is enabled. File logs keep working when the database does not.

```php
BizCity_Channel_File_Logger::write(
    BizCity_Channel_File_Logger::CH_EMAIL,
    BizCity_Channel_File_Logger::LEVEL_INFO,
    'send_attempt',
    'Sending message',
    array( 'rule_id' => $rule_id )   // no passwords, tokens, full SQL or PII
);
```

Use the canonical logger; do not add a per-plugin logger, index, route or viewer.
Conversation archives are append-only audit/recovery artifacts: the database
remains the source of truth for lists, filters, assignment and analytics. Folder
names use stable hashes, never raw phone numbers or provider user ids.

---

## 7. Errors, cron evidence and async isolation

### R-ERROR-UX · every user-visible error carries four fields

| Field | Meaning |
|---|---|
| `code` | a value from the error catalog, e.g. `token_invalid` |
| `message` | what happened, in the user's language, ≤ 120 characters |
| `hint` | what to do next, starting with a verb |
| `help_code` | a key that exists in the help catalog |

```php
return BizCity_Error_Payload::make(
    'token_invalid',
    'The page token has expired.',
    'Open Settings → Channels and reconnect the account.',
    'token_expired'
);
```

- ❌ `wp_send_json_error( 'Invalid data' )` — a bare string the frontend cannot use.
- ❌ A silent `catch` that only writes to the log.
- ❌ SQL, stack traces, file paths or PII in a user-visible message.

### R-CRON-META · cron runs leave evidence

Every registered cron job and every subscriber running in cron context records
structured evidence through the cron manager's `note()` / `note_event()` API,
with a reason bucket on failure (`token_invalid`, `permission_denied`,
`rate_limited`, `timeout`, `http_error`, `invalid_param`, …). Swallowing an
exception into `error_log()` is not evidence, and a private log table is not an
acceptable substitute.

### R-CLI-ASYNC-ISOLATION · diagnostics never run production workers

The diagnostics CLI defines its own context constant before WordPress loads.
Guard **all four** boundaries — enqueue, schedule, dispatcher callback, and the
worker entry itself:

```php
if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
    return;
}
```

A guard only in `schedule()` does not stop a callback, a shutdown handler, an
action-scheduler job or a loopback cron from running a job that was already in the
database. Network mocking is not execution isolation, and `WP_CLI` is not a
substitute for the diagnostics constant.

---

## 8. Validation, evidence and honesty

### R-DDV · diagnostic-driven validation

A change that touches a gateway, REST route, SQL, hook or schema is not done
until a registered probe reports `PASS` with evidence at three layers:

| Layer | Question |
|---|---|
| Disk | does the artifact exist and is it readable? |
| Loader | is it actually registered/loaded at runtime? |
| Runtime | does the behaviour work against real state? |

New probes live in `core/diagnostics/includes/probes/class-probe-*.php` and are
registered through the diagnostics registration filter. Ad-hoc CLI scripts are not
an accepted replacement for a probe.

Run a narrow filter first:

```bash
php bin/diagnostics-run.php --filter=<probe.id> --skip-provision --format=json
```

Read the JSON: `verdict`, `counts`, and each result's `status`, `summary`,
`error`, `fix_hint`, `run_id`. An exit code alone proves nothing.

Server deployments often ship only built frontend bundles, so a probe must not
fail merely because React sources are absent — that step is `SKIP`/`INFO`.

### Resolve the interpreter before claiming a tool is missing

```bash
PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] && "$PHP_BIN" --version
```

```powershell
$php = (Get-Command php.exe -ErrorAction SilentlyContinue).Source
if ($php) { & $php --version }
```

Record the resolved binary in your validation notes. "No PHP CLI" is a conclusion
you may only reach after running discovery.

### Commands you hand to an operator

- **No placeholders** in anything meant to be pasted (`<run_id>`, `/path/to/…`,
  `example.com`). Derive values inside the command instead — for example read
  `run_id` from the JSON the previous step wrote.
- **Prefer a repo tool over a pasted script.** If a procedure needs loops,
  parsing or more than a few lines, add it to `bin/` (with a fixture test) and
  hand the operator one short command. Pasting long blocks into an interactive
  shell interleaves lines, and any TAB character triggers tab-completion that
  corrupts heredocs. For diagnostics batches use
  `bash bin/diagnostics-batch-until-complete.sh --host=<mapped-domain> --batch=<name>`
  (the host comes from the operator; the tool never guesses it).
- **One paste, one execution.** When a short paste is unavoidable, use a single
  `bash <<'EOF' … EOF` block indented with spaces only — never TAB characters.
- **Never write diagnostics output inside the web root.** JSON, JUnit, stderr
  logs and dumps go to a directory outside the document root (for example under
  the operator's home), because anything under the site root can be downloaded
  over HTTP.
- **Make checks discriminating.** A check must give a different answer before and
  after the fix (grep for the exact new line or stamp, not for a word both
  versions contain), and log checks must compare timestamps against the deploy time.

### Honest status reporting

`SKIP`, `deferred`, `incomplete` and a silent runner are **not** `PASS`. Static
greps, class existence and successful builds are not runtime evidence. A failing
result stays failing until the same check is rerun and produces real evidence.
Never invent tool output; if you cannot run something, say so and give the command.

While a multi-step task is in progress, end each reply with a short progress block:

```text
Progress: <done>/<total slices>
Done: <artifact or checklist item just completed>
Doing: <current slice>
Next: <the cheapest discriminating check or next slice>
Validation: <PASS/FAIL/SKIP + the actual command and evidence>
Blocker: <none, or a real blocker>
```

After each verifiable group of edits, list the relative paths of every file you
created or changed, so a reviewer can read the diff before deciding to merge.

---

## 9. Working in this repository

### Roadmap and checklist discipline

Documentation, rules, contracts, code and probes are coordinated through a
roadmap document with an actionable checklist using one status vocabulary:
`[ ] pending`, `[~] in progress`, `[x] done`, `[!] blocked`. Reuse the existing
roadmap for a feature instead of starting a parallel one, update the checklist in
the same change as the code — including when a slice fails or is rolled back —
and only mark `[x]` when the matching evidence exists.

Project conventions belong in the owning document (rule, contract, roadmap, or
this file), where they are reviewed together with the code. An agent's private
memory is not a place to store project rules.

### Change stamps (R-STAMP)

Every functional edit to a `.php` file carries a stamp at the point of change:

```php
// [YYYY-MM-DD HH:MM AM/PM <Author>] <Phase-ID> — <short description>
```

Put it on the first line of a new method body, directly above changed logic, or on
the `if` line of a new guard. Use the identifier of the rule or phase you are
implementing (for example `R-CH-NS`, `R-CRON-META`, `PHP74-COMPAT`, `HOTFIX`).

### Pull requests

- Branch from the default branch; keep one concern per pull request.
- Run the validators that cover your change before opening it (environment map §6);
  paste the real output into the description.
- Update the relevant documentation, changelog entry and probe in the same change.
- Sign your commits off as the project requires (see `CONTRIBUTING.md`); the DCO
  check runs in CI.

### Security and secrets

- Never commit API keys, tokens, passwords, database credentials, customer
  domains, server paths, personal data or full log excerpts. Redact before pasting.
- A credential must never appear as a default value, a fallback or a comment.
  Read it from configuration and fail closed when it is missing.
- Run `pwsh bin/secret-scan.ps1` before a public push; `node bin/sync-agent-instructions.mjs`
  additionally refuses deployment identity and dead links in the committed docs.
- Treat host names, deployment paths, tenant identifiers and log locations as
  operator-supplied at runtime — ask for them, do not hard-code or guess them.
- Report vulnerabilities through the process in `SECURITY.md`, not in a public issue.

### Deployments are never automatic

An agent must not infer, configure or execute a deployment. When a deployment is
requested, ask for the exact mechanism (remote and branch, or host and path) and
confirm before every run. Production sites are not a test environment: no write
SQL, schema repair or migration against a remote host from a development machine,
and no falling back to production data when local configuration is missing.

### Editing files from a terminal on Windows

PowerShell 5.1 writes a UTF-8 BOM, which breaks PHP output and headers. Use your
editor tooling to write `.php` files, or write explicitly without a BOM:

```powershell
[System.IO.File]::WriteAllText($path, $content, (New-Object System.Text.UTF8Encoding $false))
```

---

## 10. Quick anti-pattern checklist

- ❌ Calling server-only classes, provider APIs or the vendor domain from client code.
- ❌ Registering a channel route outside `bizcity-channel/v1`.
- ❌ Tenant SQL after a failed routing check, or a fallback to the global database.
- ❌ `SHOW TABLES` / `SHOW COLUMNS` / `SHOW INDEX` in a runtime path.
- ❌ `dbDelta` without a changelog entry and a schema registration.
- ❌ `flush_rewrite_rules()` in `init`, or a version guard built from `time()`.
- ❌ Reads without a cache wrapper, or writes without a group flush.
- ❌ Loading admin/REST-only modules on public page requests.
- ❌ PHP 8-only syntax anywhere in shipped code.
- ❌ A user-visible error without `code`, `message`, `hint` and `help_code`.
- ❌ A cron failure with no reason bucket in its run evidence.
- ❌ Diagnostics runs that execute production workers, send messages or call providers.
- ❌ Marking work done without a probe result, or presenting a `SKIP` as a `PASS`.
- ❌ A `.php` change with no stamp, or edits inside archived/vendored trees.
- ❌ Secrets, customer domains, server paths or PII in code, logs, docs or replies.
