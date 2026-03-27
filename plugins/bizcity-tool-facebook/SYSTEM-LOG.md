# bizcity-tool-facebook — Plugin Change Log

> **Role**: BizCity SDK Tool Plugin — Facebook Social Agent
> **Category**: Social / Facebook
> **Version**: 2.0.0
> **Platform Log**: [bizcity-intent/SYSTEM-LOG.md](../../mu-plugins/bizcity-intent/SYSTEM-LOG.md)
> **Architecture**: [ARCHITECTURE.md](../../mu-plugins/bizcity-intent/ARCHITECTURE.md)

---

## Plugin Status

| Item | Status |
|------|--------|
| Scaffold created | ✅ 2026-03-03 |
| v2.0.0 rewrite | ✅ 2025-07 |
| Intent Provider registered | ✅ `bizcity_intent_register_providers` |
| Tool callbacks (self-contained) | ✅ 3 tools |
| Post type `biz_facebook` | ✅ Extracted from legacy |
| Profile view `/tool-facebook/` | ✅ 4-tab UI |
| Admin dashboard | ✅ Stats + recent jobs |
| Central webhook support | ✅ Route registration via AJAX |
| Legacy bizgpt_facebook.php | 🔄 Deprecated — auto-skipped |

---

## Tools trong Plugin

| Tool Name | Callback | Description |
|-----------|----------|-------------|
| `create_facebook_post` | `BizCity_Tools_Facebook::create_facebook_post` | AI generates content + posts to FB Pages |
| `post_facebook` | `BizCity_Tools_Facebook::post_facebook` | Pipeline: posts pre-made content to FB |
| `list_facebook_posts` | `BizCity_Tools_Facebook::list_facebook_posts` | Lists recent biz_facebook posts |

---

## Architecture (v2.0.0)

### 3-Pillar Design
1. **Profile View** (`/tool-facebook/`) — 4 tabs: Create, Monitor, Pages, Settings
2. **Intent Provider** — Goal detection with tone analysis, slot extraction
3. **Chat Notification** — Notifies webchat on job completion

### Centralized Webhook (bizcity-facebook-bot mu-plugin)
- Single endpoint: `bizcity.vn/facehook/`
- Network table: `bizcity_facebook_page_routes` (page_id → blog_id mapping)
- Subsites register pages → Central router delegates via `switch_to_blog()`
- No per-subsite Facebook app or webhook required

### File Structure
```
bizcity-tool-facebook/
  bizcity-tool-facebook.php    — Main plugin (CPT, intent registration, rewrite)
  includes/
    class-tools-facebook.php   — Self-contained AI + FB Graph API posting
    class-intent-provider.php  — Intent detection with tone patterns
    class-ajax-facebook.php    — AJAX handlers for profile view
    install.php                — DB table: bztfb_jobs
    admin-menu.php             — Admin dashboard
    integration-chat.php       — Chat notification
  views/
    page-facebook-profile.php  — 4-tab profile view
  assets/
    admin.css, admin.js
```

---

## Change Log

### 2025-07 — v2.0.0 Major Rewrite
- **BREAKING**: No longer depends on `bizcity-admin-hook/flows/bizgpt_facebook.php`
- Extracted `biz_facebook` post type, REST fields, AI generation to standalone plugin
- Added 3 self-contained tools: `create_facebook_post`, `post_facebook`, `list_facebook_posts`
- Added intent provider with tone detection (TONE_PATTERNS) and expanded goal patterns
- Added profile view (`/tool-facebook/`) with 4 tabs: Create, Monitor, Pages, Settings
- Added admin dashboard with stats and recent jobs table
- Added AJAX handlers: generate post, poll jobs, save settings, connect/disconnect page
- Added central webhook route registration (register/unregister via AJAX to main site)
- Added `bztfb_jobs` database table for job tracking
- Added chat notification via `bizcity_webchat_log_message()`
- Deprecated `bizgpt_facebook.php` in bizcity-admin-hook (auto-skipped when plugin active)
- Added public bridge methods to `class-webhook-handler.php` for central router delegation

### 2026-03-03 — v1.0.0 Initial Scaffold
- Plugin scaffold — 5 files, 1 tool: `post_facebook`
- Dependency: `bizcity-admin-hook` (bizgpt_facebook.php — `twf_handle_facebook_request`)

---

*Ref: [bizcity-intent SYSTEM-LOG.md](../../mu-plugins/bizcity-intent/SYSTEM-LOG.md)*
