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

### Browser-console evidence is mandatory for live web surfaces

When a task touches a browser-visible REST/React/cache/runtime surface, the
agent MUST provide one browser-console evidence command or a repo-owned
read-only self-check artifact that the operator can paste into DevTools. The
command must print a structured table with `PASS`, `FAIL` or `SKIP`, include the
URL/surface and the exact endpoint or runtime object checked, and never print
credentials, nonce values, tokens, SQL, PII or full response bodies.

Use a committed `docs/tools/*selfcheck.js` artifact when the check is reusable;
do not invent a one-off console snippet in chat. The artifact must:

- be read-only unless the task explicitly requires a write test;
- use same-origin `fetch()` with `credentials: 'same-origin'` and the
  localized REST nonce where needed;
- inspect browser runtime payload, REST status/envelope, built assets and
  IndexedDB/cache scope when relevant;
- label unavailable prerequisites as `SKIP`, never as `PASS`;
- redact identifiers and cap diagnostic detail before `console.table()`;
- state the exact page/surface and optional URL parameter needed before paste;
- be rerunnable and append no persistent data.

This browser evidence is part of R-DDV's Runtime layer. A source build or
editor diagnostic alone is not runtime evidence. For CRM `/crm/` work, prefer
the reusable `plugins/bizcity-twin-crm/docs/tools/*selfcheck.js` pattern; for
other surfaces, add the equivalent self-check beside that surface's docs.

### Mandatory evidence handoff for every agent environment

This contract applies equally to GitHub Copilot, Claude Code, Codex, Cursor,
VS Code custom agents and any `.vscode`/workspace agent configuration. An agent
may not report a browser-visible task as complete without a repo-owned evidence
artifact and a recorded result.

For every live REST/React/cache/runtime change, the handoff MUST include:

1. the exact committed `docs/tools/*selfcheck.js` path;
2. the exact surface URL and required route/query/hash state;
3. the persona/permission used, never an assumed persona;
4. the full `console.table()` summary with `PASS`, `FAIL`, and `SKIP` rows;
5. the stable `[claude-handoff]`/agent handoff line containing phase, task id,
   surface, layout/role where relevant, failed count and skipped count;
6. changed files, validation commands, remaining gaps and the next discriminating check.

The same contract MUST be followed when work is delegated to Claude Code,
Codex or a VS Code agent. Generated `AGENTS.md` or agent profile files must not
weaken it. A handoff that says only “built”, “deployed”, “looks good” or
“tested” is incomplete. `SKIP` is never converted to `PASS` by an agent.

For UI work, the self-check must test the actual user-visible behavior, not only
class names: mount state, runtime payload/envelope, exact endpoint, URL state,
responsive layout tier, action controls, and relevant empty/error/permission
states. If the check requires a mutation, use a separate write-mode artifact
with explicit opt-in, warning, idempotency, restore/cleanup and an error
envelope containing `code/message/hint/help_code`.

The owning phase document MUST contain an acceptance matrix mapping each task to
its self-check, latest result, evidence date and next action. This is mandatory
for work handed to an agent in `.vscode`, Claude Code or Codex, not an optional
team convention.

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

**Where the data plane lives in this workspace (2026-09-30).** The Node cell repo — zalo-cell `:3901`, ai-gateway
`:3902`, brain-core — is `zalo-hub/` at the plugin root (moved from `core/channel-gateway/_library/zalo-hub`; it is no
longer a read-only `_library` snapshot, it is code we study and change). It is its own deploy unit (Docker on the VPS)
and is listed in `bin/dev-only-paths.txt`, so it never ships inside the WordPress plugin. PHP never `require`s it;
PHP tests may read its contract fixtures (`zalo-hub/contracts/`). Contracts shared by both sides live in
`zalo-hub/contracts/` and are the single copy.

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

1. Update `core/helper/schema/changelog/<module_id>.json` (the schema owner moved
   out of Diagnostics, R-DCL v1.1): bump `current_version` and push a
   `{version, date, change}` row; every new column/index carries a matching `since`.
   This folder is public: no blog ids, tenant domains or database hosts.
2. Run the validators: `node bin/validate-schema-owner.mjs` (everywhere) and, in the
   local workspace, `php core/diagnostics/validate-schema-changelog.php` (must exit `0`;
   Diagnostics is local-only, see R-DIAG-LOCAL).
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

### R-BOTSTUDIO · Channel Gateway is the Bot Studio control plane

Channel Gateway (`/gateway/`) is the **single configuration gateway** for bots, and Bot Studio is the **first**
navigation group there — above the connected channels: Overview · Agents · Sessions · Contacts · Assistants &
Zalo numbers · Operations tuning · Queue & runtime. Contacts deep-links into the one CRM contact store; every
link between screens addresses a customer by `conversation_id`/`contact_id`, never a raw provider UID.

Everything else is a shortcut that reopens the **same** owners — binding (`bizcity_channel_bindings` via
`inspector/bindings`), Guru persona/instruction (`bizcity_characters` via `quick-edit`), run-on-channel flags and
tools (`settings.bot` via `bot/runtime`), media keys and config (`bizcity_bot_secrets` via `bot/media`). CRM Inbox
shortcuts: the `+` add-number sheet must offer **all existing personal numbers with their current owner** (server-listed,
multi-select, assigned through the existing owner-transfer path) besides adding a new number — an unowned number is assigned
at once, a number that already has another owner is changed only after an in-sheet confirmation; the composer
has an options button next to "AI reply" opening the standard action sheet (bot on/off and mode, Guru instruction,
actions such as image/music generation, each labelled with its scope and availability). Do not add a second config
store, form or route for bot settings in CRM, `/gpt/`, TwinChat or a satellite plugin, do not widen who may change
bot settings without an explicit security decision, and do not offer a per-conversation scope until it has an
owner.

The Agents section configures each agent quickly (no trip into `core/automation`); Contacts lists contacts that are
actively conversing and can add JSON metadata under `additional_attributes.custom_meta`; context/memory views must be
traceable per `account_id` and state plainly what the Context Bank ledger cannot attribute.

`/twinchat/` only holds and presents Context Bank values; it is never a bot configuration surface. Each new
surface needs a read-only browser self-check row and the four R-ERROR-UX states (R-DDV).

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

### R-SETTINGS-4L · four settings layers, one sheet contract

Every setting or write action lives in exactly one of four layers, from easiest to hardest, and is placed in the
lowest layer that fits the person who uses it:

| Layer | What | Who | Where |
|---|---|---|---|
| 1 | Quick edit — a `⋯` menu or a verb button opens a dialog sheet | everyone | on the row/card/data itself |
| 2 | Leader-level advanced — a "Bảng điều khiển …" tab | admin / supervisor / lead (`Staff_Policy`) | inside the same React app |
| 3 | IT-level advanced — central configuration | admin / IT | Channel Gateway |
| 4 | Shell settings — API key, Master Plan, appearance, the user's own config, simple extension settings | site admin + each user | Twin shell Control Panel (R-SETTING-PANEL registry) |

- Layer 1 is mandatory on every screen in every app (Channel Gateway, CRM, Twin GPT, TwinChat, Twin shell,
  Automation, extensions). Higher layers appear only as a small "Advanced …" link for people who have the right.
- Every sheet implements the shared `ActionSheet` contract: verb + object title, error shown inside the sheet
  (R-ERROR-UX), `dirty` discard confirmation, `busy` lock, independently scrolling body, and an action bar fixed
  at the bottom that never scrolls out of view (long layer-3 forms may repeat the primary action in the header).
- A setting may be opened from several layers but is written through one service/owner only.
- ❌ `window.prompt` / `window.confirm` / `alert`, inline editing, or saving on Enter/blur.
- ❌ A hand-rolled `fixed inset-0` dialog when the app has `ActionSheet`.

Spec, reference components per app and known debt:
`docs/rules/PHASE-0-RULE-SETTINGS-4-LAYERS-SHEET-STANDARD.md` (extends `PHASE-0-RULE-ACTION-SHEET-UX.md`).

### R-VERTICAL-AXIS · zalo-hub receives and thinks, the client supplies context (supreme)

On the vertical axis, zalo-hub (Docker: cell + brain-core) receives 100 % of inbound messages and does 100 % of
the AI work of a reply turn. The client site holds only the context and hands it over:

- **KEEP on the client:** Guru RAG (instruction/prompt + quick FAQ, thin `core/knowledge`), KG-Hub notebooks and
  graph (`core/kg-hub`, primary), the context export (config bundle + notebook pack, R-GS-7c), the configuration UI
  (R-SETUP-4, Bot Studio, CRM) and the CRM record of conversations received as events.
- **Reduction compass.** Every reduction/refactor wave classifies each file it touches as KEEP, MOVE (AI work that
  belongs in zalo-hub) or CUT; client code on a reply path with no KEEP role is CUT by default.
- **Realtime is Node's job (R-VA-9).** Long-lived streams — the Twin event stream SSE and token streaming or
  realtime sockets to LLM providers — move to the Node data plane (ai-gateway / brain-core). PHP keeps the event
  contract (taxonomy, schemas), issues a short-lived stream token, stores the finished record and exports context.
  The wire format stays the typed Twin SSE events. ❌ New PHP code that holds a request open to relay provider tokens.
- Not in this phase: non-reply AI (Automation, TwinBrain) and other channels (Facebook, Zalo OA, webchat).
- ❌ A new client-side model call, prompt builder, retriever or reply loop for channel messages. Put it in zalo-hub
  and export the context field instead. Legacy paths (zca, `get_ai_response()`, `BIZCITY_LEGACY_PATH_C`) are frozen.

Code: the data plane is `zalo-hub/` (plugin root, own deploy unit). Spec: `docs/rules/PHASE-0-RULE-VERTICAL-AXIS-ZALO-HUB.md`.

### R-SETUP-4 · four-step setup is the entry of the vertical axis (supreme)

Every channel setup surface (Channel Gateway dashboard, `/crm/`, `/gpt/crm/`, wp-admin "Bắt đầu") shows the same four
steps, in this order, with these labels:

| Step | Label | Passes when (server check only) |
|---|---|---|
| ① | Kết nối tài khoản BizCity | the site's 1API key is saved and tested (connection report: no `fail` in L0–L5) |
| ② | Kết nối máy chủ Zalo | Zalo Hub (default) is checked automatically; other branches are a secondary choice |
| ③ | Đăng nhập số Zalo | QR shown only after ①② pass; after login, pick the WordPress user who owns the number |
| ④ | Chọn Agent Guru | the default Agent Guru is preselected; quick create/edit in place; auto-reply on |

- **Five states per step.** Each step is Khoá, Đang kiểm tra, Đạt, Chưa đạt (R-ERROR-UX message + a hint that starts
  with a verb) or Không có quyền. A step unlocks only when the previous one passes.
- **One skeleton for every channel.** The four slots are: ① account, ② transport, ③ identity + owner, ④ Agent Guru.
- **Name.** "Agent Guru" is the user-facing name of a Guru everywhere. Code identifiers stay `character`/`guru`.
- **Sheets.** Per-item settings open in `ActionSheet`. "Quản lý Agent Guru" lists Agent Gurus with create and edit;
  it is never a create-only dialog.
- **Axis and packaging.** The path is channel ⇒ CRM Inbox ⇒ Agent Guru ⇒ knowledge. A channel package ships only when
  its four steps pass end to end (FRAMEWORK-GUIDE §14.1).
- ❌ Reaching the QR code before the key and the Zalo server are checked. Showing words like bridge, binding, cell,
  character id or provider on a step. A step marked "Đạt" from client state.

Spec: `docs/rules/PHASE-0-RULE-FOUR-STEP-SETUP-AXIS.md`. Design: `core/channel-gateway/docs/PHASE-0.83-FOUR-STEP-SETUP/`.

### R-ROUTE · the URL is the only source of location

Anything a user navigates *to* — the ActivityBar plugin, a tab or menu, the record being viewed, a
filter worth keeping on reload — is read from the URL and written by navigating. Stores may derive
from the URL; they may not be a second source that is synced back and forth. Test every change with
one question: *click it, press F5 (or open the link in a new tab), do you land in the same place?*

- Each layer owns one URL segment: the wp-admin host owns `page`, the shell owns `plugin` plus `r`
  (a route relative to the plugin, e.g. `/inbox/13/conv/88`), and the plugin owns its own route.
  A host never copies a child's query keys.
- Plugins report their route through one channel (the `TwinRoute` bridge) and declare `route_mode`
  (`hash` | `path` | `query`) in `bizcity_twin_register_plugins`. No polling, click hooks or
  `setTimeout` guesses to catch a route.
- Menus are real links (`<a href>`), not buttons that set a tab in a store. Cross-plugin and
  outbound links (menus, emails, notifications, REST responses) come from one helper
  (`BizCity_Twin_Route::url()` / `TwinRoute.href()`), never a hand-built `page=…&plugin=…` string.
- No wrapper page whose only job is to hold another iframe, unless it relays routes both ways.
- Opening a place (tab, record) pushes history; changing a filter replaces it.
- `r` is validated as a relative path and always joined to the registered entry URL, never used as
  a full URL.

**No exceptions for new work.** Any new child plugin, new plugin, new module, or new menu/tab/nav
item — including one built as a React component in its own separate bundle — follows every rule
above starting with its first commit. Declaring `route_mode` "later" is not an option; a reviewer
rejects a PR that adds navigation without it, at the same severity as a R-SAFE-LOADER or R-DCL
violation.

Contract and migration plan: [PHASE-TWINSHELL-DEEPLINK-RUNTIME.md](../docs/architecture/PHASE-TWINSHELL-DEEPLINK-RUNTIME.md).

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

Probes run in the local development workspace only (R-DIAG-LOCAL): a probe `PASS`
is local evidence, not evidence about a server.

### R-DIAG-LOCAL · Diagnostics is a local development tool (D-35)

`core/diagnostics/` (engine, probes, diagnostic admin pages, the
`bizcity-diagnostics/v1` REST routes, `validate-schema-changelog.php`), `tests/`,
`_notes/`, and the module diagnostic pages and probes exist **only in the developer's
local workspace**. They are never uploaded to a server and never committed to GitHub.
The full list is `bin/dev-only-paths.txt`. Server operations CLIs and repair classes
(`wp bizcity diag`, KG-Hub repair) are not diagnostics tooling and stay.

- **No production dependency.** An optional reference checks `is_file()` and then
  loads through `BizCity_Safe_Loader::require_file()`, or checks `class_exists()` /
  `bizcity_diagnostics_available()`. Links into Diagnostics appear only when it is
  present. A missing folder must never cause a fatal, a 500, a missing table or a
  lost error record.
- **Runtime owners stay outside it.** The schema changelog, loader and auto-create
  live in `core/helper/schema/`, and the error reporter and REST error trait live in
  `core/helper`. Never move runtime logic back into `core/diagnostics/`.
- **Evidence.** Probes run locally, against the local site, a mirror or a harness.
  Server evidence comes from production surfaces: JSONL logs, browser self-checks
  (`<module>/docs/tools/*selfcheck.js`), and `wp bizcity health`, which answers
  `skip` on a server. Never run probes or `bin/diagnostics-run.php` on a server.
- **Git and upload.** `.gitignore` ignores these paths, and CI `shipped-tree` fails
  if `core/diagnostics/` or `tests/` is tracked. Commits are made from the server
  after an upload, so exclude every `bin/dev-only-paths.txt` entry from the upload,
  and upload `.gitignore` with every change. A deploy list names production files
  only and lists dev-only files separately as "do not upload".
- **Checks.** `node bin/validate-dev-only-boundary.mjs` (dev and CI) fails on an
  unguarded require of a dev-only path, or an unguarded use of a class declared only
  there. Before a deploy, `node bin/simulate-production-tree.mjs` also checks that
  every unguarded require target ships, and runs the mirror harnesses.

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

**UI-first documents (R-SETTINGS-4L-8).** Any analysis or phase document that
touches a UI starts with the design: an HTML mockup in the owning
`docs/mockups/` folder (example data labelled as such, loading / empty / error /
no-permission states, desktop and mobile), linked from the top of the document
and approved by the product owner before any code. Every such document then
carries two separate checklists — **Checklist A: code to the HTML UI** (one item
per screen, sheet, state and button in the mockup, each tagged with its settings
layer 1–4) and **Checklist B: code solution** (data, contracts/REST, permissions,
services, migration, cache, tests/probes) — plus an Evidence section.

### Change stamps (R-STAMP)

Every functional edit to a `.php` file carries a stamp at the point of change.
This is mandatory for **every changed PHP file and every functional PHP slice**,
including edits delegated to Claude Code, Codex, Cursor or a VS Code agent. A
file-level stamp alone is insufficient when one file contains multiple separate
functional edits.

**Same requirement outside PHP (R-STAMP-JS, 2026-09-30).** A functional edit to
a `.ts`/`.tsx`/`.js`/`.jsx` file, or a decision recorded in a `.md` doc's own
body (not its dated changelog line, which already carries author/date), carries
the same stamp content — author, local time, phase/rule ID, short description —
written in that file's own comment syntax (`//` or `{/* … */}` for TS/TSX/JS,
`<!-- … -->` for Markdown/HTML). Never omit the author or the time to save
space, and never substitute the agent's own name/model for the author: the
stamp records who directed the change, not which tool typed it.

```php
// [YYYY-MM-DD HH:MM Johnny Chu - Chu Hoàng Anh]] <Phase-ID> — <short description>
```

Required concrete format:

```php
// [2026-09-08 01:27 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX0 — produce the public user-centric Inbox scope.
```

Stamp requirements:

- Use local project time in `YYYY-MM-DD HH:MM AM/PM` format.
- Include the real author do not use `<Author>` literally.
- Include the exact phase/rule identifier (`PHASE-0.41-CX0`, `R-DDV`, `HOTFIX`, etc.).
- Describe the changed behavior briefly and concretely.
- Put the stamp immediately above the changed logic, on the first line of a new
  method body, or on the `if` line of a new guard.
- If one PHP file has multiple unrelated functional slices, add one stamp per
  slice at each changed logic point.
- Do not replace an existing stamp; add a new stamp for a new change.
- PHP-only comment syntax is required; never put a JavaScript/Markdown stamp in
  a PHP file.

Before handoff, the agent must list every changed `.php` path and confirm that
each functional hunk has its stamp. A missing or vague stamp is a validation
`FAIL`, not a documentation nicety.

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
Dev-only paths (`bin/dev-only-paths.txt`, R-DIAG-LOCAL) are never part of a deploy.

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
- ❌ An everyday action reachable only from a control panel or the Channel Gateway, with no `⋯` → sheet shortcut.
- ❌ `window.prompt` / `confirm` / `alert`, inline editing, or a sheet that is not the shared `ActionSheet` contract.
- ❌ UI code written before its HTML mockup is approved, or a UI document without Checklist A and Checklist B.
- ❌ A tab, record or filter that lives only in component/store state and is lost on F5.
- ❌ A new plugin, module or menu that ships without declaring `route_mode`, planning to add it later.
- ❌ A cron failure with no reason bucket in its run evidence.
- ❌ Diagnostics runs that execute production workers, send messages or call providers.
- ❌ Uploading or committing `core/diagnostics/`, `tests/` or `_notes/`; production code that requires them; probes run on a server.
- ❌ Marking work done without a probe result, or presenting a `SKIP` as a `PASS`.
- ❌ A `.php` change with no stamp, or edits inside archived/vendored trees.
- ❌ Secrets, customer domains, server paths or PII in code, logs, docs or replies.
