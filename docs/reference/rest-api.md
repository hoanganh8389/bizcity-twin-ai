# REST API Reference

> Auto-curated catalog of REST routes registered by `core/` modules.
> Generated 2026-06-02.
>
> **Namespace rules (R-CH-NS):**
> - **NEVER** `bizcity/v1` — reserved for the upstream LLM Router (`bizcity-llm-router`) on the BizCity server.
> - **CHANNEL adapters** (Zalo, Facebook, Telegram, WebChat, Gmail, …) MUST use `bizcity-channel/v1`.
> - **Other modules** declare their own namespace: `bizcity-<module>/v1`.

All routes return JSON. User-scoped routes require `X-WP-Nonce` (cookie auth)
or Bearer JWT (auth/login flow). Listed permissions are the WordPress
capability checked by `permission_callback`.

---

## bizcity-channel/v1 — channel-gateway

| Method | Path | Class | Permission |
|---|---|---|---|
| GET | `/webchat/sessions` | `BizCity_WebChat_Inbox_REST::get_sessions` | `edit_posts` |
| POST | `/webchat/messages` | `BizCity_WebChat_Inbox_REST::post_message` | `edit_posts` |
| GET, POST | `/listener/feed` | `BizCity_Listener_REST` | `manage_options` |
| GET | `/listener/stream` | `BizCity_Listener_REST` | `manage_options` |
| POST | `/listener/test-emit` | `BizCity_Listener_REST` | `manage_options` |
| POST | `/listener/clear` | `BizCity_Listener_REST` | `manage_options` |
| GET, POST | `/facebook/pages` | `BizCity_Facebook_Page_REST` | `manage_options` |
| GET, POST | `/facebook/bots` | `BizCity_Facebook_Page_REST` | `manage_options` |
| GET, PUT, DELETE | `/facebook/bots/(?P<id>\d+)` | `BizCity_Facebook_Page_REST` | `manage_options` |
| GET, POST | `/facebook/settings` | `BizCity_Facebook_Page_REST` | `manage_options` |
| GET | `/facebook/history` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/facebook/test-send` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/facebook/admin-send` | `BizCity_Facebook_Page_REST` | `manage_options` |
| GET, POST | `/facebook/conversation` | `BizCity_Facebook_Page_REST` | `manage_options` |
| GET | `/facebook/recent-users` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/facebook/post` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/facebook/test-connection` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/facebook/test-app-config` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/facebook/ai-compose` | `BizCity_Facebook_Page_REST` | `manage_options` |
| GET | `/facebook/page-posts` | `BizCity_Facebook_Page_REST` | `manage_options` |
| DELETE | `/facebook/page-posts/delete` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/facebook/publisher/force` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/web/ai-compose` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/web/publisher/force` | `BizCity_Facebook_Page_REST` | `manage_options` |
| POST | `/inbox/send` | `BizCity_Inbox_Send_REST` | `edit_posts` |
| POST | `/inbox/note` | `BizCity_Inbox_Send_REST` | `edit_posts` |
| POST | `/flows` | `BizCity_Flow_REST::post_flows` | `manage_options` |
| GET | `/flows/dropdowns` | `BizCity_Flow_REST` | `manage_options` |
| GET | `/flows/health` | `BizCity_Flow_REST` | `manage_options` |
| GET, PUT, DELETE | `/flows/(?P<id>\d+)` | `BizCity_Flow_REST` | `manage_options` |
| POST | `/flows/(?P<id>\d+)/test` | `BizCity_Flow_REST` | `manage_options` |
| GET | `/inspector/logs` | `BizCity_Webhook_Inspector` | `manage_options` |
| GET | `/inspector/log/(?P<date>\d{4}_\d{2}_\d{2})/(?P<id>\d+)` | `BizCity_Webhook_Inspector` | `manage_options` |
| GET | `/inspector/bindings` | `BizCity_Webhook_Inspector` | `manage_options` |
| POST | `/inspector/bindings/(?P<id>\d+)/disable` | `BizCity_Webhook_Inspector` | `manage_options` |
| GET | `/inspector/stats` | `BizCity_Webhook_Inspector` | `manage_options` |
| GET | `/inspector/gurus` | `BizCity_Webhook_Inspector` | `manage_options` |
| GET | `/inspector/channels` | `BizCity_Webhook_Inspector` | `manage_options` |
| GET | `/debug-logs` | `BizCity_CG_Debug_Logger` | `manage_options` |
| GET | `/debug-logs/dates` | `BizCity_CG_Debug_Logger` | `manage_options` |
| POST | `/debug-logs/clear` | `BizCity_CG_Debug_Logger` | `manage_options` |
| POST | `/debug-logs/test-emit` | `BizCity_CG_Debug_Logger` | `manage_options` |
| GET | `/debug-logs/threads` | `BizCity_CG_Debug_Logger` | `manage_options` |
| POST | `/tasks/(?P<id>\d+)/confirm` | `BizCity_CG_Admin_Router` | `manage_options` |
| GET, POST, DELETE | `/registry` | `BizCity_Channel_REST_API` | `manage_options` |
| GET, POST | `/registry/(?P<uid>[a-zA-Z0-9_-]+)` | `BizCity_Channel_REST_API` | `manage_options` |
| POST | `/webhook/(?P<platform>[a-z0-9_-]+)/(?P<instance_id>[a-z0-9_-]+)` | `BizCity_Channel_REST_API` | public (webhook signature) |
| POST | `/test/(?P<uid>[a-zA-Z0-9_-]+)` | `BizCity_Channel_REST_API` | public |
| GET | `/health` | `BizCity_Channel_REST_API` | public |
| GET | `/logs` | `BizCity_Channel_REST_API` | `manage_options` |
| POST | `/send` | `BizCity_Channel_REST_API` | public (X-WP-Nonce) |

## bizcity-content/v1 — content-ops

| Method | Path | Class | Permission |
|---|---|---|---|
| GET, POST | `/posts` | `BizCity_Content_REST_API` | `edit_posts` |
| GET, PUT | `/posts/(?P<id>\d+)` | `BizCity_Content_REST_API` | `edit_posts` |
| GET, POST | `/posts/(?P<id>\d+)/targets` | `BizCity_Content_REST_API` | `edit_posts` |
| PUT | `/posts/(?P<id>\d+)/targets/(?P<tid>\d+)` | `BizCity_Content_REST_API` | `edit_posts` |
| POST | `/posts/(?P<id>\d+)/schedule` | `BizCity_Content_REST_API` | `edit_posts` |
| POST | `/posts/(?P<id>\d+)/publish-now` | `BizCity_Content_REST_API` | `edit_posts` |
| POST | `/posts/(?P<id>\d+)/sync-wp` | `BizCity_Content_REST_API` | `edit_posts` |
| GET | `/calendar` | `BizCity_Content_REST_API` | `edit_posts` |
| GET | `/assets` | `BizCity_Content_REST_API` | `edit_posts` |
| POST | `/ai/generate` | `BizCity_Content_REST_API` | `edit_posts` |
| GET | `/ai/jobs` | `BizCity_Content_REST_API` | `edit_posts` |
| POST | `/scheduler/run` | `BizCity_Content_REST_API` | `edit_posts` |
| GET | `/scheduler/status` | `BizCity_Content_REST_API` | `edit_posts` |
| GET | `/readiness` | `BizCity_Content_REST_API` | public |

## bizcity-cron/v1 — cron

| Method | Path | Class | Permission |
|---|---|---|---|
| GET | `/jobs` | `BizCity_Cron_REST` | `manage_options` |
| GET | `/jobs/(?P<id>[A-Za-z0-9_.\-]+)` | `BizCity_Cron_REST` | `manage_options` |
| POST | `/jobs/(?P<id>[A-Za-z0-9_.\-]+)/run` | `BizCity_Cron_REST` | `manage_options` |
| GET | `/retries` | `BizCity_Cron_REST` | `manage_options` |

## bizcity-diagnostics/v1 — diagnostics

| Method | Path | Class | Permission |
|---|---|---|---|
| GET | `/tables` | `BizCity_Diagnostics_REST` | `manage_options` |
| POST | `/error-report` | `BizCity_Diagnostics_REST` | public (throttled) |
| GET | `/smoke/probes` | `BizCity_Diagnostics_REST` | `manage_options` |
| POST | `/smoke/run` | `BizCity_Diagnostics_REST` | `manage_options` |
| POST | `/smoke/run-all` | `BizCity_Diagnostics_REST` | `manage_options` |
| POST | `/smoke/auto-fix-all` | `BizCity_Diagnostics_REST` | `manage_options` |
| GET | `/smoke/installers` | `BizCity_Diagnostics_REST` | `manage_options` |
| POST | `/smoke/run-installer` | `BizCity_Diagnostics_REST` | `manage_options` |
| GET | `/wizard/eligibility` | `BizCity_Diagnostics_REST` | public |
| POST | `/wizard/mark-seen` | `BizCity_Diagnostics_REST` | `manage_options` |

## bizcity-intent/v1 — intent

| Method | Path | Class | Permission |
|---|---|---|---|
| POST | `/auth/login` | `BizCity_Unified_REST_API` | public |
| POST | `/auth/register` | `BizCity_Unified_REST_API` | public |
| GET | `/auth/me` | `BizCity_Unified_REST_API` | `read` |
| POST | `/auth/refresh` | `BizCity_Unified_REST_API` | `read` |
| POST | `/auth/logout` | `BizCity_Unified_REST_API` | `read` |
| POST | `/chat/send` | `BizCity_Unified_REST_API` | `read` |
| GET, DELETE | `/chat/history` | `BizCity_Unified_REST_API` | `read` |
| POST | `/chat/pull` | `BizCity_Unified_REST_API` | `read` |
| GET, POST, DELETE | `/sessions` | `BizCity_Unified_REST_API` | `read` |
| GET | `/sessions/stats` | `BizCity_Unified_REST_API` | `read` |
| GET, PUT, DELETE | `/sessions/(?P<id>\d+)` | `BizCity_Unified_REST_API` | `read` |
| GET | `/sessions/(?P<id>\d+)/messages` | `BizCity_Unified_REST_API` | `read` |
| GET | `/sessions/by-sid/(?P<sid>[a-zA-Z0-9_-]+)` | `BizCity_Unified_REST_API` | `read` |
| POST | `/sessions/(?P<id>\d+)/move` | `BizCity_Unified_REST_API` | `read` |
| POST | `/sessions/(?P<id>\d+)/gen-title` | `BizCity_Unified_REST_API` | `read` |
| POST | `/sessions/close-all` | `BizCity_Unified_REST_API` | `read` |
| GET, POST | `/tasks` | `BizCity_Unified_REST_API` | `read` |
| GET | `/tasks/stats` | `BizCity_Unified_REST_API` | `read` |
| POST | `/tasks/close-all` | `BizCity_Unified_REST_API` | `read` |
| GET | `/tasks/(?P<id>[a-zA-Z0-9_-]+)` | `BizCity_Unified_REST_API` | `read` |
| GET | `/tasks/(?P<id>[a-zA-Z0-9_-]+)/turns` | `BizCity_Unified_REST_API` | `read` |
| POST | `/tasks/(?P<id>[a-zA-Z0-9_-]+)/cancel` | `BizCity_Unified_REST_API` | `read` |
| GET, POST, PUT | `/projects` | `BizCity_Unified_REST_API` | `read` |
| GET, PUT | `/projects/(?P<id>\d+)` | `BizCity_Unified_REST_API` | `read` |
| GET | `/projects/(?P<id>\d+)/sessions` | `BizCity_Unified_REST_API` | `read` |
| GET | `/tools/search` | `BizCity_Unified_REST_API` | `read` |
| POST | `/tools/estimate` | `BizCity_Unified_REST_API` | `read` |
| GET | `/agents` | `BizCity_Unified_REST_API` | `read` |
| GET | `/agents/(?P<slug>[a-zA-Z0-9\-_]+)` | `BizCity_Unified_REST_API` | `read` |
| GET, POST | `/user/profile` | `BizCity_Unified_REST_API` | `read` |
| GET, POST | `/user/settings` | `BizCity_Unified_REST_API` | `read` |
| GET, POST | `/companion/emotion` | `BizCity_Unified_REST_API` | `read` |
| GET, POST | `/companion/bond` | `BizCity_Unified_REST_API` | `read` |

## bizcity-knowledge/v1 — knowledge

| Method | Path | Class | Permission |
|---|---|---|---|
| GET, POST | `/characters` | `BizCity_API` | `read` |
| GET, PUT, DELETE | `/characters/(?P<id>\d+)` | `BizCity_API` | `read` |
| POST | `/characters/(?P<id>\d+)/query` | `BizCity_API` | `read` |
| POST | `/characters/(?P<id>\d+)/parse-intent` | `BizCity_API` | `read` |
| GET | `/characters/(?P<id>\d+)/knowledge` | `BizCity_API` | `read` |
| POST | `/search` | `BizCity_API` | `read` |
| POST | `/send` | `BizCity_Chat_REST_API` | `read` |
| GET, DELETE | `/history` | `BizCity_Chat_REST_API` | `read` |
| POST | `/session` | `BizCity_Chat_REST_API` | public |
| POST | `/auth/login` | `BizCity_Chat_REST_API` | public |
| POST | `/auth/register` | `BizCity_Chat_REST_API` | public |
| GET, POST | `/emotion` | `BizCity_Chat_REST_API` | `read` |
| GET, POST | `/bond` | `BizCity_Chat_REST_API` | `read` |
| GET, POST | `/skills` | `BizCity_Skill_REST_API` | `read` |
| GET | `/skills/categories` | `BizCity_Skill_REST_API` | `read` |
| POST | `/skills/test` | `BizCity_Skill_REST_API` | `read` |
| GET, PUT, DELETE | `/skills/(?P<id>\d+)` | `BizCity_Skill_REST_API` | `read` |

## bizcity-memory/v1 — memory

| Method | Path | Class | Permission |
|---|---|---|---|
| GET | `/tree` | `BizCity_Memory_REST_API` | `read` |
| GET | `/list` | `BizCity_Memory_REST_API` | `read` |
| GET, PUT, DELETE | `/(?P<id>\d+)` | `BizCity_Memory_REST_API` | `read` |
| POST | `/create` | `BizCity_Memory_REST_API` | `read` |
| POST | `/(?P<id>\d+)/section` | `BizCity_Memory_REST_API` | `read` |
| GET | `/(?P<id>\d+)/log` | `BizCity_Memory_REST_API` | `read` |
| POST | `/load-or-create` | `BizCity_Memory_REST_API` | `read` |

## bizcity-guru/v1 — persona

| Method | Path | Class | Permission |
|---|---|---|---|
| GET, POST | `/guru/(?P<id>\d+)/web-fallback` | `BizCity_TwinBrain_Guru_Web_Flag` | `read` |
| POST | `/citations/resolve` | `BizCity_TwinBrain_Citation_Resolver` | `read` |

## bizcity-scheduler/v1 — scheduler

| Method | Path | Class | Permission |
|---|---|---|---|
| GET, POST | `/events` | `BizCity_Scheduler_REST_API` | `manage_options` |
| GET, PUT, DELETE | `/events/(?P<id>\d+)` | `BizCity_Scheduler_REST_API` | `manage_options` |
| POST | `/events/quick` | `BizCity_Scheduler_REST_API` | `manage_options` |
| GET | `/today` | `BizCity_Scheduler_REST_API` | `manage_options` |
| GET | `/google/status` | `BizCity_Scheduler_REST_API` | `manage_options` |
| GET | `/google/accounts` | `BizCity_Scheduler_REST_API` | `manage_options` |
| POST | `/google/sync` | `BizCity_Scheduler_REST_API` | `manage_options` |
| GET, POST | `/google/settings` | `BizCity_Scheduler_REST_API` | `manage_options` |
| POST | `/google/disconnect` | `BizCity_Scheduler_REST_API` | `manage_options` |
| POST | `/google/callback` | `BizCity_Scheduler_REST_API` | public (OAuth redirect) |
| POST | `/automation/fire-now` | `BizCity_Scheduler_REST_API` | `manage_options` |
| GET | `/automation/recent` | `BizCity_Scheduler_REST_API` | `manage_options` |

## bizcity-twin-events/v1 — twin-core

| Method | Path | Class | Permission |
|---|---|---|---|
| GET, POST | `/events` | `BizCity_Twin_Event_Stream_REST` | `manage_options` |
| GET | `/events/recent_traces` | `BizCity_Twin_Event_Stream_REST` | `manage_options` |

## bizcity-twinbrain/v1 — twinbrain

| Method | Path | Class | Permission |
|---|---|---|---|
| POST | `/turn` | `BizCity_TwinBrain_REST` | `read` |
| GET | `/turn/stream` | `BizCity_TwinBrain_REST` | `read` |
| POST | `/tool/confirm` | `BizCity_TwinBrain_REST` | `read` |
| GET | `/turn/(?P<trace_id>[\w\-]+)` | `BizCity_TwinBrain_REST` | `read` |
| GET, POST | `/memory/me` | `BizCity_TwinBrain_REST_Memory_Me` | `read` |
| GET, PUT, DELETE | `/memory/me/(?P<id>\d+)` | `BizCity_TwinBrain_REST_Memory_Me` | `read` |

---

## Modules with no REST surface (server-side only)

- **agents** — pure registry, no public routes.
- **automation** — REST in progress (phases BE-2…BE-7).
- **bizcity-llm** — client wrapper only; server routes live on the upstream
  `bizcity-llm-router` plugin under `bizcity/v1` (see
  [bizcity-llm-router/docs/api/README.md](../../../bizcity-llm-router/docs/api/README.md)).
- **bizcity-market** — uses upstream `market/v1/*` on the router.
- **research** — reserved for v2.1+.
- **runtime** — _deprecated_ (use `intent` + `twinbrain`).
- **skills** — REST exposed under `bizcity-knowledge/v1/skills*`.
- **smtp**, **tools** — no public REST.

---

**See also:** [filters.md](filters.md) · [actions.md](actions.md) · [classes.md](classes.md)
