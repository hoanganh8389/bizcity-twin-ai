# TwinKG — the Knowledge Graph UI module

> Created 2026-09-24 by `CORE-REDUCTION-WP-09` (steps T1–T3). Design and the remaining
> steps: `core/knowledge/docs/CORE-REDUCTION-WP-09-TWINKG-MODULE.md`.

## What this module is

The React workspace for configuring knowledge: notebooks, sources, passages, the graph,
the triplet queue, workspaces/ACL and Guru promotion. It used to live inside
`core/knowledge/kg-hub/ui` and mount on a wp-admin page. It now lives here and is served
at its own URL.

**It is a view, and only a view.** It owns no database table, no cron, no REST route, and
no option beyond its own rewrite sentinel. It never calls a KG service in PHP. Everything
it shows comes from REST namespaces that `core/knowledge` owns.

That boundary is the rule the move exists to establish (proposed id `R-KG-UI`):

> `core/*` declares no React application, no `add_menu_page` callback that mounts a bundle
> and no inline-HTML admin view. Knowledge and KG-Hub interfaces live in `modules/twinkg/`
> and reach core only through REST. A capability the UI needs that REST does not expose yet
> is added to `core/knowledge` as a REST route — never as PHP in this module.

Server-side PHP callers are a separate question and are **not** affected: they keep using
the `BizCity_KG` facade that `docs/rules/PHASE-0-RULE-KG-HUB-CONTRACT.md` §3 already requires.

## Surfaces

| URL | What it does |
|---|---|
| `/twinkg/` | The app. Login required. A logged-in visitor who is not inside the shell is redirected to `/twin/?plugin=twinkg`; `?bizcity_iframe=1` (the shell) or `?shell=0` renders directly. |
| `/twin/?plugin=twinkg` | The same page inside the Twin Shell, with the ActivityBar. This is the canonical entry. |
| `admin.php?page=bizcity-twinkg` | A wp-admin wrapper that iframes the shell, for operators who work inside wp-admin. It never enqueues the bundle itself. |
| `admin.php?page=bizcity-kg-hub` · `…=bizcity-twinchat-gurus` | Retired slugs. Redirected here, never 404. The second one opens `view=gurus`. |

`?view=` accepts `gurus`, `graph`, `queue`, `sources` — anything else is ignored and the app
keeps its own state.

## Files

```
bootstrap.php                      constants, Safe Loader, TwinShell entry, rewrite flush
includes/class-twinkg-public-page.php    /twinkg/ rewrite + page render from the Vite manifest
includes/class-twinkg-admin-menu.php     wp-admin iframe wrapper + legacy slug redirects
includes/class-twinkg-bootstrap-data.php the window.BIZCITY_KG_HUB payload, shared by both surfaces
ui/                                the React app (Vite + React 18 + react-query + zustand + Tailwind)
```

Four PHP files, about 20 KB. If a fifth one starts to look necessary, check first whether
what you are about to write belongs in `core/knowledge` behind a REST route.

## Build

```bash
cd modules/twinkg/ui
npm install
npm run build          # -> ui/dist + ui/dist/.vite/manifest.json
```

`ui/.gitignore` ignores `dist/`, so a deployment either builds this or ships the folder
separately (open item T-D1 in the design doc). When the manifest is missing the page renders
a bounded "UI chưa được build" notice with these instructions instead of a blank screen.

PHP reads the manifest and cache-busts on its mtime, so a rebuild invalidates the browser
cache without a version bump.

## The bootstrap contract

`window.BIZCITY_KG_HUB`, read by `ui/src/api/client.ts` and `ui/src/main.tsx`:

| Key | Meaning |
|---|---|
| `restNamespace` | `bizcity-knowledge/v2` — the canonical KG REST namespace |
| `restRoot`, `nonce` | `rest_url()` and a `wp_rest` nonce; the client sends `credentials: 'same-origin'` |
| `currentUserId`, `blogId` | identity/scope for display only — REST re-checks both |
| `pluginUrl`, `buildVersion` | asset base and the manifest mtime |
| `defaultView` | from `?view=`, validated; `null` means "keep what you had" |
| `surface`, `shellEmbed`, `homeUrl`, `twinUrl` | how the page is being shown |

The object name and the root element id (`bizcity-kg-hub-root`) are inherited from the old
mount on purpose: step T1 moved the app without editing a line of `ui/src`. Renaming both is
step T6 and must ship with a one-release alias.

## What is still in core

`core/knowledge/kg-hub` keeps everything that is not UI: the REST controllers, the
`BizCity_KG` facade, retrieval, ingest, ACL, cost guard, filestore, skeleton and
`kg-hub/assets/bztwin-skeleton.js` — that last one is a vanilla Web Component shared with
TwinChat and bizcity-doc, not React.

`core/knowledge/kg-hub/includes/class-kg-settings-page.php` is still a PHP form in core. It
becomes a view here once its Cost Guard settings have a REST write (WP-09 step T4 /
WP-08 item H-09).
