# Changelog

All notable changes to **BizCity Twin AI** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> Schema-level changes (DB tables/columns) live in per-module JSON files under
> [core/diagnostics/changelog/](core/diagnostics/changelog) and are NOT duplicated here.
> See rule [R-DCL · Diagnostics Changelog](docs/diagnostics/PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md).

---

## [Unreleased]

### CI clean checkout and diagnostics activation gate - 2026-08-25

| Area | Change | Status |
|---|---|---|
| Packaging | Stopped excluding the active `plugins/bizcity-profile/` module from Git, which caused the clean-checkout activation fatal for the required Profile wheel provider. | Implemented locally; push required |
| CI | Added a shipped-tree preflight before `wp plugin activate` so missing runtime artifacts fail with an actionable path. | Implemented locally |
| CRM loader | Proprietary CRM now skips a partial deployment instead of aborting Diagnostics; Inbox Access accepts both canonical flat and legacy reorganized paths. | Implemented locally; CI rerun pending |
| Trace gate | Added a CI check that rejects the stale CRM bootstrap which directly required `includes/inbox/class-inbox-access.php` and stopped Diagnostics with exit code 255. | Implemented locally; CI rerun pending |
| Main loader | Main plugin now skips incomplete proprietary CRM artifacts before the legacy bootstrap can execute; public framework Diagnostics continues without CRM. | Implemented locally; CI rerun pending |
| Roadmap | Made the two PHASE-1.24 audit roadmaps trackable and recorded the remaining WordPress matrix and Diagnostics JUnit gates. | Implemented locally; CI rerun pending |

### Facebook webhook dùng duy nhất callback Plan B — 2026-08-25

| Area | Change | Status |
|---|---|---|
| Channel Gateway | Loại bỏ URL Plan A và Plan A fallback khỏi REST settings/UI; `/?fbhook=1` là callback Facebook chính thức duy nhất. | Implemented locally; frontend artifact build passed |

### Profile shared Twin GPT SSE and no-notebook chat — 2026-08-25

| Area | Change | Status |
|---|---|---|
| Transport | Profile public React chat now consumes the canonical TwinWeb `/chat/stream` SSE event contract with live token rendering and synchronous fallback. | Implemented locally; WordPress runtime SSE smoke pending |
| Identity | Profile stream verifies signed Profile context, keeps WEBCHAT/Guru/CRM attribution and Profile session prefix through completion. | Implemented locally; runtime CRM stream smoke pending |
| Grounding | Profile forces `mode=chat`, ignores notebook focus and injects canonical public Profile context into the shared runtime prompt. | Implemented locally; answer-quality smoke pending |

### PHASE-0.39F CRM progress snapshot and Inbox column collapse — 2026-08-24

| Area | Change | Status |
|---|---|---|
| F5 assignment | Added assignment policy CRUD/binding, fair-count and capacity selection, tenant lock/transaction, scoped auto-assign REST command and no-admin-fallback outcomes. | Local foundation implemented; canonical inbound trigger, idempotency and concurrency DDV pending |
| F6 Kanban | Added scoped conversation/order-care projections, repository-backed conversation move command and cache invalidation without creating CRM order/Kanban shadow tables. | Local foundation implemented; FE adoption, task/opportunity commands and browser/DDV pending |
| Inbox UI | Added independent collapse controls to Channels, Conversation List, Conversation and Contact columns; collapsed tracks use `44px` and responsive selectors preserve tablet/mobile behavior. | CRM frontend build and artifact smoke passed; browser visual smoke pending |
| Release status | Added dated progress snapshot and synchronized PHASE-0.39F/master tracker status. | Overall 0.39F remains ACTIVE / PRE-RELEASE; F2/F3/F7/F8 and bridge BD-4..BD-6 gates remain open |

### Floating brain Hero polish and compact chat launcher — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Hero | Replaced the unstable circular force layout with a stable two-hemisphere neural mesh, multicolor links and floating brain aura. | Implemented locally; browser visual smoke pending |
| Chat float | Replaced the oversized closed pill with a circular icon-only launcher; the readable chat headline and prompt input appear after opening the panel. | Implemented locally; browser chat smoke pending |
| Deployment gate | Profile diagnostics now requires the compact launcher, neural mesh and public chat panel markers. | Implemented locally |

### Floating brain neuron Hero and visible prompt — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Hero visual | Increased the public graph field to a dense two-hemisphere neuron silhouette with cross-links, aura and central fissure treatment. | Implemented locally; browser visual smoke pending |
| Prompt | Public React chat now shows an input composer immediately instead of requiring a launcher first. | Implemented locally; browser chat smoke pending |
| Data boundary | Ambient points are unlabeled visual-only particles; labeled nodes remain limited to the published public graph/capability allowlist. | Implemented locally |

### Public Profile graph density and prompt composer — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Hero graph | Added ambient visual points and low-emphasis ring links when the public snapshot has few nodes, while keeping labeled nodes limited to public-safe data. | Implemented locally; browser visual smoke pending |
| Chat prompt | Added an always-visible React prompt composer in the public Profile chat surface; Enter and Send use the canonical Profile chat route. | Implemented locally; browser chat smoke pending |
| Diagnostics | The deployed public artifact gate now requires the Hero graph, highlight event and visible prompt composer markers. | Implemented locally |

### Public Profile Hero graph visualization — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Hero | Ported a public-safe read-only SVG graph into the Profile Hero/cover position with category colors, curved relations, drag, zoom and pulse effects. | Implemented locally; browser visual smoke pending |
| Chat reaction | Chat questions and answers broadcast public label matches; the Hero highlights matched nodes and connected relations while dimming unrelated nodes. | Implemented locally; runtime graph snapshot smoke pending |
| Privacy | The graph reads only the server-published `publicGraphSnapshot` or `publicCapabilities`; no notebook/KG query, drawer or edit action is exposed publicly. | Implemented locally |

### Public Profile React chat foundation — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Public mount | Added a dedicated `profile-public.js` React entrypoint for public Profile WebChat, while preserving Page Builder HTML as the SEO/no-JS fallback. | Implemented locally; browser/runtime WordPress smoke pending |
| Chat contract | React chat reuses the existing signed `channel_context`, `chat_turn`, `profile_webchat_{card_id}_*` session and canonical CRM/TwinBrain handler. | Implemented locally |
| Performance | Public Profile no longer needs to load the 412 KB dashboard bundle; dedicated chat artifact is about 153 KB before gzip. | Implemented locally |

### Profile Public and editor UX fixes — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Editor | Moved Avatar/Cover controls into the Hero panel and added a Template-tab artifact gate plus production asset-version bump. | Implemented locally; production deployment/cache purge pending |
| Avatar | Added a profile icon fallback for empty or broken avatar URLs in Profile Edit, Page Builder canvas and public export. | Implemented locally |
| Public assistant | Renamed the public CTA to “Hỏi quản gia của tôi” and made it open the actual WebChat launcher. | Implemented locally; browser smoke pending |

### Profile Edit template picker — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Template tab | Added a Profile Edit tab that loads the three server-owned Profile templates and applies a selected layout after explicit confirmation. | Implemented locally; browser/runtime WordPress smoke pending |
| Preservation | Template switching keeps owner Profile/Twin/CTA/slug/capability data, `profileCardId`, and the canonical CF7 lead form, then republishes already-published cards. | Implemented locally; aggregate Profile Diagnostics pending |
| Safety | Added an owner-scoped REST route and diagnostics contract; no new table, browser file path, Membership or entitlement logic. | Implemented locally |

### Page Builder canvas parity for Profile portfolio — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Canvas renderers | Added `timeline` features, `progress` stats, Portfolio/Blog metadata cards and Portfolio category filter to the Page Builder canvas preview. | Implemented locally; browser editor smoke pending |
| Canvas layout | Canvas now mirrors unique block anchors and the responsive `vcard_portfolio` sidebar/main composition used by public export. | Implemented locally; browser editor smoke pending |
| Safety | Canvas filtering is local to the preview iframe; no CRM, provider, visitor or public-page side effects are introduced. | Implemented locally |

### PHASE-0.39F CRM framework storage and operations foundations — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Archive F1 | Expanded encrypted conversation archive to active Zone 1 channels and added redacted attachment refs. | Implemented locally; per-channel runtime/DDV pending |
| Hybrid storage F2 | Added storage lifecycle fields, archive receipt index, bounded offload method and read-only cold rehydrate path. | Foundation implemented; pilot activation and round-trip DDV pending |
| Reporting F3 | Added content-free reporting facts, daily rollup tables/writer and scoped `/reports/rollups` endpoint with cache. | Foundation implemented; migrate existing dashboards and validate metric parity |
| Teams/assignment F4-F5 | Added tenant-local Teams, Team Members, Inbox Members, capabilities, policy CRUD/binding and scoped assignment foundations; manual assignment now checks membership. | Foundation implemented; canonical inbound trigger, fair-distribution fixtures, UI and DDV pending |
| Owner scope F7 | `BizCity_CRM_Inbox_Access` now unions explicit Inbox Members with the existing Zalo Personal owner scope. | Partial; provider-specific ownership and field projection remain |

### CRM archive channel coverage — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Zone 1 archive | Expanded encrypted conversation archive to Facebook, Messenger, Zalo OA, Zalo Personal, WebChat, Email, Instagram and WhatsApp. Normalized legacy adapter aliases (`email_imap`, `web_widget`, `whatsapp_cloud`, `zalo`) to canonical archive channels. | Implemented locally; deployed per-channel archive DDV pending |
| Attachment archive refs | Archive rows now include attachment ID/type, keyed hashes, bounded size and MIME metadata without raw provider URLs. | Implemented locally; media fixture and retention/reconcile DDV pending |
| Hybrid offload | No SQL content offload was enabled by this slice. Archive receipt/lifecycle contract remains a prerequisite before clearing message content. | Intentionally pending F2 |

### Profile portfolio fidelity pass — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Resume | Added an additive `timeline` variant to the existing features renderer and ported the source education/experience structure. | Implemented locally; public browser smoke pending |
| Skills | Added an additive `progress` variant to the existing stats renderer for source-style skill bars. | Implemented locally; public browser smoke pending |
| Content cards | Portfolio and Blog entries now support title/date/description metadata while retaining the existing gallery contract. | Implemented locally |

### Profile portfolio interaction port — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Layout | Ported the source vCard responsive desktop sidebar/main composition using existing Page Builder output; it collapses to one column on mobile. | Implemented locally; public browser smoke pending |
| Portfolio filter | Added reusable category filtering for gallery blocks and enabled it on the portfolio template with All, Web design, Applications and Web development categories. | Implemented locally; public browser smoke pending |
| Source boundary | Ported visual language and interaction behavior only; no vendor HTML/CSS/JS was copied, and Profile WebChat, lead-form/CRM and tracking mounts remain canonical. | Implemented locally |

### CRM Channel Framework contract gate — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Channel input | Added `BizCity_CRM_Channel_Contract` validation before shared CRM SQL: stable inbox/source/dedupe identity, content type, attachments, zone, storage and TwinBrain descriptors. | Implemented locally; multi-channel runtime DDV pending |
| Channel output | Normalized adapter outcomes to `success`, `outcome`, `code`, `external_source_id`, `error`, `retryable`, `channel_code` and `contract_version`. | Implemented locally |
| CRM write ownership | Moved outbound message status mutation into `BizCity_CRM_Repository::update_message_delivery()`; REST no longer writes message status directly. | Implemented locally; PHP runtime smoke pending |
| Zone and catalog | Registry/REST channel catalog now exposes the framework descriptor; Zone 2 adapter ingress is rejected by the shared CRM gate, with T-M1.4 synthetic valid-input and Telegram rejection probes. | Implemented locally; deployed Diagnostics PASS and producer ownership migration pending |
| TwinBrain | Kept one `crm_message_received` AI listener owner and documented that full canonical TwinBrain parity is still pending for Personal/WebChat/Email/other Zone 1 channels. | Contract documented; parity work remains roadmap |

### Profile portfolio template port — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Template | Added `business-card-portfolio.json`, porting the supplied vCard Personal Portfolio structure into existing Page Builder blocks: About, Resume, Portfolio, Blog, Contact, services, testimonials, clients and skills. | Implemented locally; public browser smoke pending |
| Visual language | Added the `vcard_portfolio` Profile preset with Poppins, dark surfaces and yellow accent while preserving Profile WebChat, vCard, lead-form and CRM contracts. | Implemented locally; public browser smoke pending |
| Navigation | Page Builder section anchors now use unique block IDs, so repeated `content`/`gallery` sections remain reachable from the portfolio navbar. | Implemented locally; Page Builder build PASS |

### Profile portfolio snapshot and KG graph redaction — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Portfolio | Publish now creates a bounded `publicPortfolioSnapshot` from allowlisted public Page Builder blocks; forms, shortcodes, custom HTML and team/private payloads are excluded. | Implemented locally; publish/browser smoke pending |
| Graph privacy | `publicGraphSnapshot` accepts only server-authorized graph input through `bizcity_profile_public_graph_snapshot`, then allowlists node/edge fields, caps sizes and drops invalid references/private fields. | Implemented locally; trusted KG provider and runtime privacy smoke pending |
| Freshness | Added `graph_hash` alongside the capability `content_hash` so published graph changes have an independent fingerprint. | Implemented locally |
| Scope | No live KG query, CRM query, new table or Membership entitlement policy was added to public rendering. | Implemented locally |

### Profile editor accent-aware link preview — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Editor preview | Added a compact live preview for populated contact, social and messaging links; icon/border/background accents follow `brainAccentColor`. | Implemented locally; browser smoke pending |
| Scope | Presentation-only change; Profile REST, CRM ownership and Membership entitlement contracts are unchanged. | Implemented locally |

### Profile gift provider fallback — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Public resilience | Profile renderer now shows a neutral fallback when the selected Gift Wheel provider is unavailable or returns no public markup; chat and portfolio remain available. | Implemented locally; public browser smoke pending |

### Profile Funnel DDV contract expansion — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Public snapshot probe | Extended `modules.personal.profile.wave62` with an in-memory privacy fixture for `publicGraphSnapshot`, including allowlisted fields and content-hash verification. | Implemented locally; Diagnostics rerun pending |
| Surface probe | Added deployed artifact checks for Profile Care/Public navigation and a side-effect-free loader check for the canonical WebChat CRM adapter/ingestor. | Implemented locally; WordPress runtime evidence pending |
| CRM attribution fixture | Profile WebChat now carries the real `profile_card_id` and `profile_public` source through normalization; the probe verifies stable external message ID and card attribution without CRM writes. | Implemented locally; CRM runtime smoke pending |

### Profile Care/Public navigation split — 2026-08-24

| Area | Change | Status |
|---|---|---|
| Surface navigation | Profile Care no longer exposes or reopens the Profile Public card workspace through sidebar, query or legacy hash navigation. Profile Public keeps its direct card/portfolio workspace. | Implemented locally; browser smoke pending |
| Boundary | This is a presentation/navigation split only; backend REST ownership remains shared and server-side entitlement is still pending. | Documented |

### Profile publish-time public-safe capability snapshot — 2026-08-23

| Area | Change | Status |
|---|---|---|
| Publish boundary | Profile publish now writes a server-generated `publicGraphSnapshot` into the existing `profile-card.props`, containing only approved capability fields and a content hash. | Implemented locally; WordPress publish smoke pending |
| Public renderer | Page Builder prefers the published snapshot and preserves `publicCapabilities` fallback for legacy cards. No private KG, memory or CRM query was added to public rendering. | Implemented locally; public browser smoke pending |
| Snapshot freshness | Editing `publicCapabilities` removes the old snapshot from shared SiteConfig; the next publish regenerates it from the current owner-approved capabilities. | Implemented locally; republish smoke pending |

### Profile Public WebChat to canonical CRM — 2026-08-23

| Area | Change | Status |
|---|---|---|
| CRM projection | Profile Public WebChat inbound now reuses the canonical CRM WebChat adapter and ingestor; owner projection also includes `profile_webchat_{card_id}_*` sessions. | Implemented locally; WordPress runtime smoke pending |
| Single responder | Profile temporarily vetoes CRM AI auto-reply while ingesting the inbound turn, then mirrors the successful TwinBrain answer into the CRM conversation without a second channel send. | Implemented locally; duplicate-reply smoke pending |
| Provenance | Pipeline lead source preserves `webchat` instead of classifying Profile WebChat as `zalo_oa`; CRM message IDs are stable for idempotent inserts. | Implemented locally |

### Profile detailed metrics and chat transcript — 2026-08-23

| Area | Change | Status |
|---|---|---|
| Dashboard metrics | Added separate views, Tel, Email, Facebook, chat-open, successful-chat and contact metrics to per-card and aggregate Profile dashboards. | Implemented locally; public/runtime smoke pending |
| Chat content | Added owner-scoped `/profile/cards/{id}/chat-transcript`, persisting Profile WebChat user questions and Twin answers in canonical `bizcity_webchat_messages` under `profile_webchat_{card_id}_*`. | Implemented locally; WebChat/CRM runtime smoke pending |
| Cache compatibility | Versioned Profile analytics report cache to `report_v2` so old cached payloads without `metrics` cannot mask the new REST contract. | Implemented locally |

### Profile metrics and chat transcript — 2026-08-23

| Area | Change | Status |
|---|---|---|
| Metrics | Profile analytics now returns and renders separate counts for views, Tel, Email, Facebook, chat opens, successful chat questions, and submitted contacts in both per-card and aggregate dashboards. | Implemented locally; public/runtime smoke pending |
| Transcript | Successful Profile WebChat turns persist user questions and Twin answers in canonical `bizcity_webchat_messages` under a card-scoped session prefix; owner-scoped read-only transcript REST/UI was added. | Implemented locally; WordPress CRM/WebChat runtime smoke pending |
| Privacy boundary | Daily Profile JSONL remains redacted event evidence only; raw chat content is kept in WebChat canonical storage and is never copied into traffic logs. | Implemented locally |

### Profile Public runtime UX and observability — 2026-08-23

| Area | Change | Status |
|---|---|---|
| Brain hero | Added a deterministic visible Brain visualization behind the canvas animation and a legacy `profileCardId` resolver from published page to Profile registry, preventing `card_id=0` from disabling chat/tracking/vCard. | Implemented locally; public browser smoke pending |
| Ordering | Added native drag-and-drop ordering for public contact links and social links; order persists in the shared Page Builder SiteConfig. | Implemented locally; Profile UI build PASS |
| Traffic | Mirrored accepted Profile events into the canonical daily `profile` JSONL channel log and added owner/card/date-filtered log REST reads in analytics. | Implemented locally; WordPress runtime log read pending |
| Twin GPT menus | Split the server app catalog into `My card QR` and `My profile`, with deep-link support for Profile Public/Care while preserving the legacy `profile` ID. | Implemented locally; Twin GPT UI build PASS |
| Notebook detail | Remounted the page editor after detail fetch so API-provided title/content is displayed; documented SQL metadata/index versus `.md` content ownership. | Implemented locally; Profile UI build PASS |
| Legacy card recovery | Page Builder now passes its known published `post_id` into Profile rendering, making `profileCardId` recovery deterministic even when query context is unavailable. | Implemented locally; public browser smoke pending |
| Analytics contract | Added the missing `funnel` field to Profile analytics REST responses and ensured the resolved card ID is applied before rendering the public Profile tab. | Implemented locally; public browser smoke pending |

### Zalo Personal native self-echo and Personal-only scope — 2026-08-23

| Area | Change | Status |
|---|---|---|
| Personal ownership | `bizcity-zalo-personal` bootstrap/probe now owns Personal only; OA is not loaded or required by this module. | Implemented locally; deploy + rerun Diagnostics |
| Native Zalo messages | Sidecar preserves `threadId`, records WP-only outbound provider IDs, and marks `origin=crm` versus `origin=native_zalo`; native messages mirror into CRM as outgoing agent rows. | Implemented locally; production smoke pending |
| CRM resolver | Added the missing WordPress DB handle in `BizCity_CRM_Guru_Resolver` before binding/notebook queries. | Implemented locally; production log verification pending |
| Probe semantics | Domain-deny fixtures now allow entitlement to remain true; Personal probe no longer fails because OA is owned elsewhere. | Implemented locally; WordPress Diagnostics rerun pending |

### Managed Zalo entitlement capacity repair — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Root-cause trace | Confirmed the failing key can be `master_premium` with `bizcity-zalo-personal` enabled while `zalo_personal_account_limit=0`; Branch 19 correctly fails closed on the independent capacity gate. | Verified from production response for key `4474` |
| Hub migration | Bumped Master Plan schema migration to `2.6.2`; built-in managed Zalo defaults are now Free `1`, Pro `3`, Premium `-1` (unlimited), with the feature enabled in all three plans. | Implemented locally; deploy and rerun `/master/config` |
| Admin save safety | Legacy Master Plan submissions that omit the Zalo capacity field now preserve the stored value instead of silently writing `0`. | Implemented locally |
| Error reason clarity | Exact-key Zalo capability now distinguishes `account_capacity_disabled` from `feature_not_enabled`; client UI explains that the plugin is enabled but the account capacity is zero. | Implemented locally |
| Client capability freshness | Zalo Personal UI now prefers the latest `/zalo-bridge/health` capability over stale cached settings capability, so `allowed=true/account_limit=-1` can enable account creation immediately. | Implemented locally; deploy rebuilt Channel Gateway bundle |
| Acceptance gate | Added roadmap/API documentation requiring exact-key `channels.zalo_personal` verification after migration. | Documented; runtime evidence pending |

### Managed Zalo create UX and trace — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Sheet lifecycle | Save/create sheets close only after `ok=true`, show global success/error toast, and refresh account/health state after account creation. | Implemented locally; deploy rebuilt Channel Gateway bundle |
| Create trace | Added redacted `[BIZCITY_ZCA_TRACE]` milestones for auth, Hub response, sidecar account, mapping schema/save, CRM inbox upsert, and completion. | Implemented locally; inspect PHP/file channel logs after the next create attempt |
| Error response | Client Zalo REST proxy now preserves `code`, `message`, `hint`, and `help_code` instead of returning a blank degraded message. | Implemented locally |

### Managed Zalo default seat policy — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Product policy | Managed Zalo Personal is enabled by default for Free, Pro and Premium; account seats are `1 / 3 / -1 unlimited`. | Implemented in Hub seed/migration and documented across Hub/client/plugin contracts |
| Zero-capacity semantics | `0` remains an explicit lock; `reason=account_capacity_disabled` distinguishes it from a missing feature slug. | Implemented locally |

### Managed Zalo create domain-gate trace — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Exact-key diagnosis | Confirmed production create for `mrodemo.btnet.vn` authenticates as Hub API key `#59`; the request stops at `allowed_domain` before any sidecar call. | Verified from `invalid_metadata` response |
| Redacted trace | Hub now records `domain_gate` with key id, domain presence, host hashes and match booleans; client forwards safe correlation fields without raw domain or credential. | Implemented locally; deploy and retry once |
| Repair path | Existing-key Master Admin now provides a sanitized hostname editor; set key `#59` to the real client hostname, then retry account creation. | Implemented locally; production parse-error artifact must be replaced |

### API-key revoke diagnostics — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Revoke backend | Key revoke now verifies the exact row is active, checks the database update result, reads back `is_active=0`, and logs redacted `revoke_start/update/complete` stages. | Implemented locally |
| Revoke UI | HTTP `403`/AJAX failures now show an error and re-enable the button instead of failing silently after the confirm dialog. | Implemented locally; deploy Master Admin artifact |
| Domain contract | Documented `invalid_metadata` as exact-key `allowed_domain` failure with safe `key_id/domain_set/host_hash` correlation. | Documented |

### Master Admin script-loader fix — 2026-08-22

| Area | Root cause / change | Status |
|---|---|---|
| JavaScript boot | Master page was loading API Monitor's `admin-monitor.js`, which expects `BizMonitor`; this caused `BizMonitor is not defined` and prevented admin handlers from running. | Fixed locally; deploy Master Admin artifact |
| Inline config | `BizMaster` is now defined before the Master page's inline key/plan handlers execute; jQuery is explicitly enqueued. | Fixed locally |
| Revoke interaction | Revoke buttons are explicit `type="button"` controls and use AJAX error handling with DB read-back verification. | Fixed locally |

### Production verification: Managed Zalo provisioning — 2026-08-22

| Area | Evidence | Status |
|---|---|---|
| Exact-key entitlement | Client production received Premium `channels.zalo_personal.allowed=true` with `account_limit=-1`. | User-confirmed production verification |
| Domain gate | API key `#59` was assigned the client hostname and the create request passed `allowed_domain`. | User-confirmed production verification |
| Account provisioning | Managed Zalo account creation proceeded after entitlement and domain repairs. | User-confirmed production verification |
| Remaining gate | QR login, managed inbound callback, outbound delivery, restart recovery and two-key isolation still need separate evidence. | Pending |

### B2B2C trace-first framework and Zalo UI consolidation — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Framework rule | Added a reusable preflight route matrix across Copilot instructions, R-B2B2C, Framework Guide, contract testing/runtime, Plugin Standard, Hub API and client/module contracts. | Implemented and validated locally |
| Failure classification | Standardized transport/auth/entitlement/domain/tenant/mapping/side-effect/presentation-cache classification with success and denial evidence requirements. | Documented |
| UI ownership | Merged Zalo Personal Guru controls and account list into one Overview Card; Guru panel supports embedded rendering and no longer owns a duplicate card. | Implemented locally; Channel Gateway build PASS |

### Phase 0.39C production-closure roadmap — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Roadmap | Created `PHASE-0.39C-ZALO-PERSONAL-PRODUCTION-CLOSURE-ROADMAP.md` with ordered C0-C7 slices, route trace matrix, denial matrix, code anchors and release checklist. | Active roadmap |
| Remaining gates | QR/restart recovery, managed inbound callback, CRM outbound, two-key isolation, `/gpt/` member smoke and archive/DDV production evidence are explicitly separated from already verified entitlement/domain/account-create work. | Pending by wave |

### Phase 0.39C C0 contract fixtures — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Public contract | Registered `zalo-personal-bridge` v1 in `core/twin-core` contract catalog with schema, allowed/denied/mapping fixture matrix and invalid fixture. | Implemented locally; Node suite PASS with 14 contracts |
| Diagnostics | Extended `modules.zalo-personal` with Disk/Loader/Runtime semantics for exact key, domain, entitlement, side effect and mapping outcomes without network calls. | Implemented locally; WordPress runtime PASS pending |
| Next wave | C1 is now the active coding slice: managed QR, status transitions and restart/expiry recovery. | Ready to implement |

### B1/B2/C Master Plan entitlement contract — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Hub plan catalog | Added the canonical `BizCity_LLM_Client::get_master_plans()` wrapper and made the same-origin Wallet proxy preserve `member_seats`, `channels.zalo_personal`, and `zalo_personal_account_limit`. | Implemented locally; deploy and verify `/bizcity-channel/v1/master/plans` |
| Exact-key entitlement | The current-plan proxy now resolves through `get_plan_config()` with `allow_main_site_fallback=false`; local user meta is no longer used as a Hub plan identity. | Implemented locally; deploy and verify current-blog key scope |
| Framework contract | Documented B1 Hub exact-key ceiling → B2 tenant projection/policy → C actor/member policy, explicitly separating public plan catalog from runtime authorization. | Documented in B2B2C and Membership contracts |

### TBP-6.3 Profile Zalo Personal owner picker — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Profile REST | Added `platform=zalo_personal` account projection from `BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner()`, filtering to the current owner's connected accounts with a usable Zalo UID. | Implemented locally; live bridge/CRM ownership smoke pending |
| Profile UI | Added the owner-scoped Zalo Personal selector to Entrypoints and dedicated public CTA labeling. | Implemented locally; Profile UI build PASS |
| Loader/DDV | Loaded Zalo Personal mapping on Profile Care/Public editor routes and extended the Profile Wave 6.2 probe with picker contract evidence. | Implemented locally; WordPress probe rerun required |
| Save boundary | Enabled Zalo Personal entrypoints now require a fallback URL derived from a connected account owned by the current Profile owner; arbitrary manually submitted URLs are rejected. | Implemented locally; live ownership/CRM smoke pending |

### Diagnostics phase groups and stale-deploy hardening — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Curated phase groups | Added P0 foundation, B1 entitlement, B2 isolation, C Twin GPT CRM and W8 archive `Run group` shortcuts in Diagnostics. | Implemented locally; run on authenticated WordPress Diagnostics |
| Zalo managed compatibility | Added the missing Hub client singleton and guarded every managed bridge callsite so stale deployments degrade instead of throwing `undefined method ...::instance()`. | Implemented locally; deploy and rerun `modules.zalo-personal` |
| Schema inventory | Reconciled `modules.twin-crm.json` automation-rules catalog v1.28.1 with the active CRM installer (`created_by_id`, `inbox_id`, `last_run_at`, `idx_event_active`, `idx_inbox`). | Implemented locally; deploy and rerun `schema.inventory` |
| Probe runtime fixes | Corrected Zalo Personal probe to use `Integration_Registry::get()`, loaded the existing `core.channel.zone_ui` probe in Diagnostics bootstrap, and synchronized the Personal probe's 35-row metadata. | Implemented locally; deploy and rerun P0/B1/B2/W8 groups |

### Managed Zalo health contract — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Health boundary | Added `BizCity_Zalo_Bridge_Client::health()` for managed Hub and custom sidecar modes; REST now normalizes `success/ok` and degrades safely when a mixed-version client lacks the method. | Implemented locally; deploy both Bridge Client and REST, then rerun the Zalo Personal probe |

### Profile roadmap and next wave — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Runtime evidence | Recorded that `modules.personal.profile`, `modules.personal.profile.wave5`, and `modules.personal.profile.wave62` each passed on WordPress runtime; the curated `profile` group remains pending aggregate execution. | Individual probes PASS; group run pending |
| TBP-6 | Defined the next wave for Profile Public channel funnel and ownership: five channels converge on canonical CRM, Zalo Personal account ownership is verified server-side, Profile Care/Public navigation is separated while BE remains shared, and public Brain rendering observes publish-time privacy/performance boundaries. | Roadmap defined; implementation pending |

### Profile Diagnostics group — 2026-08-22

| Area | Change | Status |
|---|---|---|
| Curated probe group | Added the `profile` Diagnostics quick-run group, covering `modules.personal.profile`, `modules.personal.profile.wave5`, and `modules.personal.profile.wave62` while preserving each probe's individual evidence and cleanup behavior. | Implemented locally; run the group on the WordPress Diagnostics page |

### Managed Zalo Personal B2B2C enforcement — 2026-08-22

| Area | Change | Status |
|---|---|---|
| B1 Hub entitlement | Master Plan migration repairs existing Pro rows with the canonical `bizcity-zalo-personal` feature and exposes exact-key account capacity through Branch 19. | Implemented locally; Hub migration and two-key runtime smoke required |
| B2 tenant isolation | Managed client calls use the current blog's API key without main-site fallback; Hub callback registration requires an exact key domain and caches account counts by physical Hub database plus `key_id`. | Implemented locally; multi-client callback smoke required |
| C Twin GPT | `/gpt/` Personal account routes require the managed Hub capability before list/create/QR/status/delete, reject guests/custom bridge mode, and create accounts through an explicit `owner_user_id` service boundary. | Implemented locally; member/guest/denied browser smoke required |
| Ownership review fixes | QR/status/delete no longer delegate to admin-only handlers; the owner service verifies `kind=personal`, and the Personal route cannot be repurposed to create a Zalo OA account. | Implemented locally |
| Legacy tenant migration | Mapping migration v1.1.4 backfills `owner_user_id` from legacy `user_id` and `account_name` from `label` so existing accounts remain visible under owner-scoped `/gpt/` queries. | Implemented locally; tenant migration smoke required |
| Callback transport security | Managed Branch 19 rejects non-HTTPS callback URLs before registering account-specific bearer credentials. | Implemented locally |
| Capacity concurrency | Branch 19 serializes provisioning per exact API key and invalidates the entitlement count after create/delete. | Implemented locally; concurrent quota smoke required |
| Conversation archive | The encrypted append-only CRM archive now uses one channel-aware pipeline for both `zalo_personal` and `messenger`, including a channel contract diagnostic row. | Implemented locally; Messenger event and archive smoke required |
| Archive safety bound | Archive JSONL rows are rejected above 256 KiB before filesystem append and emit a redacted operational failure event. | Implemented locally |
| Archive lifecycle | Added 365-day monthly retention through the existing guarded retention job, authorization-scoped encrypted export, legal-hold-aware atomic conversation erase, and bounded read-only partition reconciliation. | Implemented locally; production policy and smoke required |
| Archive integrity | Export and reconciliation now verify `blog_id`, channel, account HMAC, and peer HMAC against the requested partition before accepting an archive row. | Implemented locally |
| Archive maintenance boundary | Added admin-only same-origin `bizcity-channel/v1/conversation-archive/{reconcile,export,erase}` routes scoped to the current tenant; Inbox never calls these endpoints. | Implemented locally; production authorization smoke required |
| Twin GPT Personal CRM | Added same-origin Personal CRM list/detail/messages routes and a read-only Inbox panel in My Channels, reusing tenant CRM SQL and owner/inbox ACLs. | Implemented locally; authenticated browser smoke required |
| Twin GPT Personal CRM send | Added owner/inbox-scoped Personal send route and composer delegating to canonical CRM `post_message()` and channel adapter delivery path. | Implemented locally; authenticated Zalo Personal outbound smoke required |
| Twin GPT Personal send safety | Personal CRM history remains readable after logout, while outbound is restricted to connected Personal accounts only. | Implemented locally |

### Zalo Personal admin control plane — 2026-08-22

| Area | Change | Status |
|---|---|---|
| QR/account state | Zalo Personal QR success now refreshes the account list so `connected` is reflected immediately instead of leaving the row at `pending_qr`. | Implemented locally; deploy + browser smoke required |
| Guru binding | Channel Gateway now exposes per-account Zalo Personal Guru binding using the canonical `ZALO_PERSONAL` binding registry. | Implemented locally; runtime binding smoke required |
| Twin Brain reply switch | Added explicit `Bật trả lời` / `Tắt trả lời` control backed by the existing binding `mode` + `auto_reply` contract. New Zalo Personal bindings default to `manual/OFF`; OFF keeps CRM Inbox ingestion and disables automatic Guru reply. | Implemented locally; runtime send/skip smoke required |
| Guru Quick Edit | Personal accounts can open the shared Guru Quick Edit surface for system prompt, runtime, Quick Training, notebook attach/detach, and source-to-notebook bridge. | Implemented locally; deploy + browser smoke required |
| CRM Inbox progress | Recorded the live admin evidence: connected Personal account and inbound contact/conversation/message visible in BizCity Twin CRM Inbox BE. | Verified on admin BE; outbound/access policy evidence remains pending |
| Auto-reply enforcement | CRM AI Autoreply Listener now fails closed for `zalo_personal` unless an exact Guru binding has `auto_reply=1`; the previous global default could call Chat Gateway even when the Personal UI showed OFF. | Fixed locally; deploy + OFF/ON live smoke required |
| Legacy Zalo channel cleanup | Added a dedicated `DELETE /bizcity-crm/v1/inboxes/{id}/zalo-legacy` route and CRM Inbox rail action. It only purges `zalo_personal` inboxes with no local managed-account mapping; active managed channels are protected. | Implemented locally; deploy + legacy/managed deletion smoke required |
| QR/session recovery | QR login now validates Personal account type, prevents duplicate in-flight login, persists terminal expiry, resumes an existing QR, and marks connected accounts with missing credentials as expired on restart. | Implemented locally; deploy + restart/QR smoke required |
| CRM Zalo flow diagnostic | Added read-only `GET /bizcity-crm/v1/inboxes/{id}/zalo-diagnostic` and a per-inbox `Kiểm tra flow` action. It checks CRM inbox, adapter, account mapping, Client/Hub bridge health and recent inbound/outbound evidence without sending a message. | Implemented locally; deploy + browser flow smoke required |

### Diagnostics CI mock-mode stabilization — 2026-08-21

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| CLI schema orchestration | `bin/diagnostics-run.php` now calls `BizCity_Site_Provisioner::run_all( true )` (registering default installers first) right after `init`, instead of relying on `admin_init`/activation-hook lifecycle that a headless CLI request never triggers. This is the root fix for the CI "table_missing" cascade (`schema.inventory`, `core.automation`, `channel-gateway.fb-publisher`, `scheduler.automation`, `scheduler.nerve_center`, `scheduler.inbound_backfill`, `core.memory.unified_dual-write-parity`, etc.), all of which share the same underlying cause: bundled/library schemas that only install via `register_activation_hook()` or `admin_init` never ran in the mock-mode job. | Implemented locally; CI rerun required to confirm the cascade clears | Any new schema installer must register with the `bizcity_register_installers` filter (Site Provisioner), not rely solely on `register_activation_hook()`/`admin_init`, so headless CI and multisite new-blog provisioning both pick it up. |
| CLI failure evidence | `bin/diagnostics-run.php` now prints each probe's `summary`/`error` detail (truncated) inline in the console/JUnit output instead of only badge + id, so CI logs are actionable without re-running locally. | Implemented locally | — |
| R-DDV-MOCK-GATEWAY probe skip pattern | Added `BIZCITY_DIAGNOSTICS_MOCK` skip guards to probes that make a real Search/LLM/embedding/Graph-API call and cannot be asserted without live credentials: `account.quota_entitlement`, `kg.filestore.standalone`, `kg.upload_attach_source`, `twinbrain.memory.writer.llm`, `twinbrain.sheet.enrich`, `twinbrain.web.gov`, `twinbrain.web.law`, `twinbrain.web.med`, `twinbrain.web.nutri`, `twinbrain.web.scholar`, `twinbrain.web.tax`, `web.deep_llm`, `web.search_ping` (plus the pre-existing `twinbrain.agent.react` / `twinbrain.brain.auto-degrade` guards from 2026-08-20). These probes now report SKIP in `--skip-network` CI runs instead of a misleading FAIL. | Implemented locally; CI rerun required | New real-call probes must add this guard in `precondition()` before any network/LLM/embedding call, matching the sibling `twinbrain.web.*` probes. |
| R-DDV-MOCK-GATEWAY — remaining gaps closed | Added the same guard to `twin.final.compose` (Final Composer streams a real LLM call) and `kg.graph.rag.ask` (embeds question, LLM rerank, LLM answer generation) — both were missing the guard even though every sibling live-gateway probe already had it. | Fixed locally; CI rerun required | — |
| Zalo Bot schema never provisioned headlessly | `plugins/bizcity-zalo-bot` is `require_once`'d by `bizcity-twin-ai.php` as a bundled sub-plugin, so its own `register_activation_hook( BIZCITY_ZALO_BOT_FILE, ... )` never fires (WordPress only calls activation hooks for plugins passed to `activate_plugin()`); its only other install path was `admin_init`, which a headless CLI request never triggers either. This is why `schema.inventory` reported `bizcity_zalo_bots` as critical-missing right after that table was catalogued as critical today. Registered `BizCity_Zalo_Bot_Plugin::maybe_create_tables()` with the `bizcity_register_installers` filter so Site Provisioner (CLI diagnostics, multisite new-blog, admin self-heal) provisions it independently of activation/admin_init. | Fixed locally; CI rerun required | Any bundled sub-plugin (`plugins/*` required by the parent instead of separately activated) must register its installer with Site Provisioner; its own `register_activation_hook()` is dead code in that topology. |
| Proprietary plugin folders missing from `.gitignore` | `.gitignore` had descriptive comments stating `bizcoach-pro` and `bizcity-twin-crm` are "in-house"/"proprietary, do NOT publish", but the actual ignore patterns (`/plugins/bizcoach-pro/`, `/plugins/bizcity-twin-crm/`) were absent, so a `git add .` on this workspace could commit proprietary commercial code to the public OSS repo. Added the missing patterns. | Fixed locally | If a plugin folder is documented as proprietary/in-house in `.gitignore` comments or in `bizcity-twin-ai.php`'s bundled-loader comments, verify the actual ignore pattern exists in the same PR — a comment alone does not exclude the path. **Follow-up required:** run `git status`/`git log -- plugins/bizcoach-pro plugins/bizcity-twin-crm` to confirm neither was already committed to the public remote; if either was, it must be purged from history, not just gitignored going forward. |



| Area | Change | Status |
|---|---|---|
| Vite assets | Profile UI now emits stable `assets/profile.js` and `assets/profile.css` files instead of hash-named bundles; the PHP runtime adds a `time()` cache version in local/development mode and preserves the stable plugin version in production. | Implemented locally; rebuild and browser smoke required |

### Twin GPT Profile and Workflow navigation — 2026-08-20

| Area | Change | Status |
|---|---|---|
| Profile app path | Twin AI now must-loads the physical `bizcity-profile` bundle, exposes `My Profiles`, and serves the Profile SPA at `/profile/`; `/personal/` remains a compatibility alias. | Implemented locally; route and browser smoke required |
| My Workflows | Removed the false `OFF` state caused by surface-gated Automation classes, lazy-loads Automation for workflow API calls, and routes customer users to channel preflight plus the real ON/OFF controls. | Implemented locally; channel activation smoke required |

### BizCity Profile REST foundation — 2026-08-20

| Area | Change | Status |
|---|---|---|
| REST namespace | Canonicalized the Personal/Profile REST API to `bizcity-profile/v1`; PHP class names, storage tables, shortcode, and `/personal/` page path remain compatibility identifiers. | Implemented locally; deploy and route smoke required |
| Multisite POST guard | Added `bizcity-profile/v1` to the `bizgpt-multisite.php` route registry and POST bypass, including normalized `rest_route` and URI fallback handling. | Implemented locally; multisite smoke required |
| Profile schema | Added and implemented the R-DCL entry for Profile card, QR style, and analytics event tables under `modules.personal` schema version 1.5.0. | Implemented locally; provisioning and Diagnostics evidence required |
| Page Builder bridge | Profile card publish now delegates to `bzpb/v1/publish` with trace/idempotency headers and updates the Profile registry only after BZPB success. | Implemented locally; publish smoke required |
| Channel context | Added published-card/entrypoint validation and short-lived `WEBCHAT`/`TWINWEB` context resolution through `BizCity_Channel_Binding`; no browser-supplied Guru ID or provider credential is exposed. | Implemented locally; channel binding smoke required |
| Entrypoint configuration | Added owner-scoped `GET/PUT /profile/cards/{id}/entrypoints`, Page Builder SiteConfig persistence with mutation headers, and a clean Profile UI panel for channel toggles, WebChat presentation, and tracking tags. | Implemented locally; save/binding smoke required |

### TwinBrain MPR V5.9 Media HIL interaction slice — 2026-08-19

| Area | Change | Status |
|---|---|---|
| HIL media runtime | `BizCity_TwinBrain_HIL_Runtime` now supports explicit `chọn ảnh khác` for media slots, keeps the same pending slot on that branch, and returns candidate-aware prompts (no-candidate upload guidance vs indexed selection guidance). | Fixed locally; deploy + Diagnostics rerun required |
| HIL progress notice | `BizCity_TwinBrain_Progress_Notice_Projector::on_hil_step()` now emits `hil_evidence_candidate_found` when scoped media candidates exist, then keeps waiting guidance user-safe without exposing raw URLs/tokens. | Fixed locally; deploy + canary evidence required |
| HIL synthetic DDV | `twinbrain.hil` fixture now verifies `select-other keeps pending slot` and `missing candidate asks for upload` contracts for media slots. | Fixed locally; rerun required |

### TwinBrain MPR V5.10 Intent compatibility migration slice — 2026-08-19

| Area | Change | Status |
|---|---|---|
| Intent compatibility adapter | Added `core/twinbrain/includes/class-twinbrain-intent-compat-adapter.php` to expose deterministic Slot Analysis / Clarify Gate / Confirm Analyzer / Memory Spec compatibility fields from Prompt Intent + Goal context, without provider calls or dual-write Goal truth. | Fixed locally; deploy + Diagnostics rerun required |
| Runtime decision stage | `BizCity_TwinBrain_Runtime::start_turn()` now emits `decision.stage=intent_compat_ready` and returns `intent_compat` envelope for migration surfaces. | Fixed locally; deploy + event evidence required |
| TwinChat surface migration | TwinChat stream pipeline now forwards `prompt_intent` + `intent_compat` into completion opts and emits SSE `decision.stage=intent_compat_ready` with compact compatibility telemetry (`clarify_needed`, `slot_missing_count`, `confirm_intent`, `memory_scope`) for timeline observability. | Fixed locally; deploy + timeline/event evidence required |
| Zalo workflow migration | `llm.mpr_think` action output now carries `prompt_intent` + `intent_compat`; compact per-event summaries retain compatibility keys so Zalo workflow traces can audit migration state without leaking raw payloads. | Fixed locally; deploy + workflow evidence required |
| Automation bridge migration | `BizCity_Automation_TwinBrain_Bridge::run_with_capture()` and `action.ask_guru` now preserve Prompt Intent/Intent Compat (plus related triage/context fields) across `complete_turn`, preventing contract loss between start and completion phases. | Fixed locally; deploy + workflow evidence required |
| Legacy pending read window | HIL payload preparation now accepts legacy `_resume.attachment_url` / `attachment_url` / `media_url` fallback when canonical `attachments[]` is absent. | Fixed locally; deploy + canary verification required |
| V5 product-match gate | HIL product matching defaults to deterministic mode; optional LLM matcher is now explicit opt-in via filter `bizcity_twinbrain_v5_allow_llm_product_match`. | Fixed locally; deploy + behavior verification required |
| Aggregate DDV | `twinbrain.mpr_v5` now checks the V5.10 compatibility adapter, deterministic fixture contract, and disk-level surface wiring markers for TwinChat/Zalo/Automation bridge migration. | Fixed locally; rerun required |

### TwinBrain MPR V5 Gate 6 outbound evidence hardening — 2026-08-19

| Area | Change | Status |
|---|---|---|
| Live canary surfaces | `POST /wp-json/bizcity-diagnostics/v1/smoke/run-live` now supports explicit surface routing: `zalo_bot` (legacy default) and `twinchat` (new). The aggregate probe `twinbrain.mpr_v5` dispatches live execution by `live_surface`, preserving existing Zalo linked-identity contract while adding TwinChat runtime trace/stage evidence capture. | Fixed locally; deploy + live evidence required |
| Live canary run options | Added `GET /wp-json/bizcity-diagnostics/v1/smoke/run-live/options` to return required fields, payload templates, REST endpoint metadata, and WP-CLI fallback command snippets for `twinchat` and `zalo_bot` surfaces. | Fixed locally; deploy + usage verification required |
| Windows execution helper | The live-canary options response now includes PowerShell `Invoke-RestMethod` snippets with JSON bodies for both surfaces. Auth values remain explicit placeholders for the current admin REST nonce/session cookie and are never persisted as evidence. | Fixed locally; deploy + usage verification required |
| Channel Gateway outbound evidence | `BizCity_Gateway_Sender` now publishes explicit `idempotency_key` metadata alongside `side_effect_status` and `provider_request_id` in `bizcity_channel_outbound_logged` payloads for both adapter and legacy send paths. This aligns with Gate 6 aggregate disk checks and live-canary evidence capture requirements. | Fixed locally; deploy + OPcache refresh + probe rerun required |
| Goal Contract probe version gate | `twinbrain.goal_contracts` no longer requires strict `current_version === DB_VERSION` for `core.twinbrain.json`. The probe now accepts `current_version >= DB_VERSION` so module-level changelog bumps (for taxonomy/non-DDL rows) do not false-fail the R-DCL table contract check. | Fixed locally; deploy + probe rerun required |
| MPR V5 roadmap/probe parity | Documented the Gate 6 marker fix in the MPR V5 roadmap as source-level closure for aggregate step-4 (`Gateway outbound idempotency evidence`) while keeping §21 DoD checkboxes unchanged until synthetic rerun and live canary evidence pass. | Updated locally |

### TwinChat Woo BizOps direct vertical path — 2026-08-16

| Area | Change | Status |
|---|---|---|
| TwinBrain runtime | `web_mode=woo_bizops` now skips generic Notebook Selector and Tool Intent stages; Woo is treated as a direct Vertical Plugin and still uses Final Composer for presentation. | Fixed locally; tenant rerun required |
| Woo order list | Added bounded `order_list` intent using HPOS-aware `wc_get_orders()` with structured `orders[]`, date range, status, total and citations. | Fixed locally; tenant rerun required |
| TwinChat timeline | Added visible Woo domain/query/composed stages and order count handling for `woo_bizops_*` SSE events. | TwinChat build PASS |
| Automation picker | `#` overlay now says Automation Workflow, mutually exclusive picker overlays close on a new prefix, and Builder exposes `command_invokable` for workflow listing. | Automation/TwinChat builds PASS |

### Guru versus Vertical Plugin terminology — 2026-08-16

| Area | Change | Status |
|---|---|---|
| Product vocabulary | Canonical split is now explicit: Guru = notebook/knowledge scope; `/vertical_slug` = Vertical Brain Mode / Plugin capability. `guru_id` and `BizCity_TwinBrain_Guru_Policy` remain compatibility identifiers only. | Documentation and user-facing error text updated |
| Woo BizOps policy messages | Replaced user-facing wording that implied Guru owns Woo BizOps access with `scope notebook` and `Vertical Plugin capability` wording. | Updated locally |

### Automation Diagnostics runtime evidence — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| Automation loader | Diagnostics loads the Automation surface before probe execution; Ask Guru uses the active `blocks/actions/` path. | Fixed locally; runtime evidence updated | Run `core.automation` on the canonical Diagnostics page after deploy and verify every required class, including Templates Seeder. |
| `automation.matcher` probe | Fixture carries linked owner identity, preserves top-level `raw_text` for `@` command tests, and invokes `extract_ref_uuid()` with its current `(payload, platform)` signature. | **Runtime PASS confirmed** | Synthetic channel payloads must satisfy R-ZONE/R-CH-IDMEM identity and normalized/raw text contracts. |
| `automation.crm_path` probe | CRM instantiate assertion unwraps canonical REST `{ ok, row }` response; Zalo OA/Bot fixtures include owner identity. | **Runtime PASS confirmed** | Probe assertions must consume the public REST DTO rather than assuming a flat row. |
| `twinbrain.notebook_depth` | Source map, W0.20 graph/retrieval/rerank pack, citation guard and depth profile contract pass on the tenant. | **Runtime PASS confirmed** | Keep notebook source-layer and final-composer profile expectations versioned together. |
| Templates Seeder visibility | Seeder remains intentionally lazy; the core Automation probe loads it only while that Diagnostics probe runs. | Fixed locally; rerun required | Do not load template seeding on unrelated admin, frontend or channel requests. |
| BE-7 builtin catalog count | The core Automation probe now runs an explicit idempotent `force_reseed()` before comparing builtin slugs, and reports failed slugs instead of accepting a partial catalog. | Fixed locally; rerun required | A partial builtin catalog must be repaired and measured before `core.automation` can PASS. |
| CCG-1 explicit command smoke | Extended the existing `core.automation` probe with an exact `#workflow_slug` + args resolve assertion in `zone=admin`, using a disposable command-invokable workflow and existing cleanup. | Fixed locally; rerun required | Keep explicit command DDV inside the tenant-visible core probe when the standalone command-zone probe is not exposed. |

### TwinChat Woo BizOps vertical dispatch — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| `/woo_bizops` dispatch | The generic `bizcity_twinbrain_web_mode_effective` Guru web-fallback gate converted `woo_bizops` to `off` before `dispatch_web_research()`, so TwinChat stayed in Notebook/MPR and never emitted `woo_bizops_*` events. Woo BizOps now passes that generic gate and is checked by its dedicated `BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS` boundary. | Fixed locally; tenant rerun required | Do not apply the generic web-fallback flag to sensitive built-in verticals with their own capability policy. |

### BizCoach Pro F.9 diagnostics contract — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| `T-BCPRO.F9.a` Intent Provider class | The Diagnostics admin page is outside the normal Intent loader gate, so the read-only BizCoach matrix could evaluate `class_exists( 'BizCoach_Pro_Intent_Provider' )` before its base contract was available. The BizCoach probe now loads the Intent bootstrap/contract only during its precondition, then loads the lazy BizCoach provider. | **Runtime PASS confirmed** — F.9: 4 PASS · 0 FAIL · 0 WARN · 0 SKIP; matrix: 48 PASS · 12 WARN | Keep Diagnostics probes self-contained for the contracts they inspect; do not widen the global Intent preload gate for unrelated admin pages. |

### Diagnostics command-zone probe interface fatal — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| `core.twinbrain.command_zone` probe | The probe declared `private static cleanup(array $ids)`, conflicting with the required public non-static `BizCity_Diagnostics_Probe::cleanup(): void`. Renamed the health-test helper to `cleanup_workflows()` and added the interface-compliant cleanup method. | Fixed locally; production deploy required | Every new probe must be checked against `interface-diagnostics-probe.php` before lazy loading. |

### Automation diagnostics probe parse fatal — 2026-08-16

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| `core.automation` probe | Removed stray standalone action block strings that caused `unexpected ','` in `class-probe-automation.php`, and restored the missing `Repo_Runs::enqueue()` health-test step before runner execution. | Fixed locally; production deploy required | Run PHP syntax validation for every probe before deploy. A single malformed lazy-loaded probe can abort the entire Diagnostics catalog. |

### TwinChat shell KG-Hub menu fatal — 2026-08-13

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| Central admin menu | `includes/class-admin-menu.php` called `BizCity_KG_Admin_Menu::instance()` even on the TwinChat shell, where PHASE-1.26 intentionally loads only the lightweight Knowledge admin-menu class and not the KG-Hub runtime. Added a `class_exists()` guard; the full admin path remains registered by the KG-Hub bootstrap. | Fixed locally; production deploy required | Keep shell-only loader paths compatible with central menu callbacks. Run the admin-navigation probe on both the TwinChat shell and full Knowledge/KG-Hub admin surface. |

### Zalo Bot AI gateway unavailable — 2026-08-13

| Area | Root cause / change | Status | Prevention |
|---|---|---|---|
| Zalo `/zalohook/` AI reply | The request gate loaded Knowledge/TwinBrain for `/zalohook/`, but both the main and compat LLM loader gates only recognized `/bizhook/`, `/wp-json/`, `/gpt`, and `/twin`. `BizCity_LLM_Client` was therefore absent, so the runtime misleadingly reported the gateway/API key as unavailable. Added `/zalohook/` to the main plugin and both compat loader copies. | Fixed locally; production deploy and OPcache refresh required | Keep main plugin, source compat, and deployed compat route matrices identical. Smoke-test `/zalohook/` for `BizCity_LLM_Client` before testing provider credentials. |
| Tenant Rolling Memory | Tenant `wp_1513_bizcity_memory_rolling` was missing `blog_id`/`identity_uuid` while the version option was already `1.4`. The version-only fast path allowed runtime SQL to reach the missing columns. Added physical-column verification and read/write guards. | Fixed locally; tenant migration still must be verified on `slave10` | A schema version option is not physical-shard evidence. Verify required columns on the routed tenant after deploy. |
| Zalo no-match reply | The automation matcher sent `BizCity_Automation_Default_Reply`, then legacy `twf_handle_chat_flow()` also sent a response. Disabled the automation default reply for Zalo Bot when the legacy responder is active. | Fixed locally; production deploy required | One channel/request must have one responder owner. Check send traces for duplicate `automation.default_reply` and legacy TWF sends. |
| Workflow continuity | Raw Zalo intake did not resolve the linked WordPress owner before enqueue, and `web_post` scheduler metadata lacked `web_content` in the stale production path. Added owner resolution and the required metadata field. | Fixed locally; production deploy required | Test owner/chat continuity and scheduler metadata on a real workflow run, not only static contracts. |
| Zalo `/link` and SSO binding | Automation slash matching could claim `/link <nonce>` before `BizCity_Zalobot_Command_Router`, while the CRM SSO return could lose `bzzalolink` before token consumption. Reserved `/link` at both matcher/router boundaries and added a short-lived return marker so an authenticated SSO return can consume the pending magic link. | Fixed locally; production deploy required | Treat identity-binding commands as system-reserved. `welcome=1` is only a UI redirect; verify the canonical channel mapping and `link_command_bound` evidence. |
| Magic-link ownership contract | Documented and locked ownership: `BizCity_CRM_Magic_Link` / `BizCity_CRM_Magic_Link_Handler` own issue, verify, consume and browser/SSO callback; `BizCity_Channel_User_Linker` owns canonical `ZALO_BOT` identity mapping; `BizCity_Zalobot_User_Linker` is compatibility-only during migration. Updated R-CH-UNI, CRM Phase 3.5, Zalo Admin Guide and identity/memory/notebook roadmaps. | Documentation updated 2026-08-13 | Do not add token issuance or callback handling back to a channel plugin. Validate both `consumed_at` and canonical `bizcity_channel_user_links` after login. |
| Legacy TWF retirement for Zalo Bot | Production evidence showed `bizgpt_chatbot_run_admin_flows()` still calling `twf_process_flow_from_params()` for `ZALO_BOT`, causing `/link <nonce>` to be classified by LLM and then handled as ordinary chat. Added hard-stop guards in the active MU adapter, bundled Zalo adapter, and `core/helper-legacy/legacy_flow-router.php`. | Fixed locally; production deploy required | A Zalo Bot request must not emit `ai_result user_text`, `ai_result json`, or legacy `twf_handle_chat_flow`; it must be handled by canonical UCL/Command Router/Automation only. |
| `/link` recovery and login status | `/link` nonce failures now inspect the current canonical identity first: an already-linked user is told the active WordPress account; an unlinked user receives a fresh CRM Magic Link instead of only an error message. The `đăng nhập` command reports the linked account or explicitly says no account is linked and sends a fresh link. | Fixed locally; production deploy required | Do not report `chưa đăng nhập` when `resolve_wp_user()` returns a user. Only unlinked identities receive a replacement URL. |

### R-PERF/R-CACHE audit ledger — 2026-08-09

| Area | Canonical record | Change / evidence | Status | Next action |
|---|---|---|---|---|
| Diagnostics probe lazy queue | `R-PERF-LOADER` + `R-DDV` | Removed an early `bizcity_diagnostics_load_probes_once()` flush from `core/diagnostics/bootstrap.php`; it could mark the loader complete before the remaining probe queue declarations were registered, leaving the Diagnostics catalog empty or incomplete. | Fixed locally 2026-08-16 | Deploy to the affected site and verify `GET /wp-json/bizcity-diagnostics/v1/smoke/probes` returns a non-empty catalog as an admin. |
| Canonical loader rule | `R-PERF-LOADER` + `R-DDV` | Codified PHASE-1.23 lessons: surface-scoped loading, pre-`plugins_loaded` evidence, compat/main/bundle parity, shell iframe isolation, file/class delta as primary signal, and QM A/B instrumentation. The observed shell gates reduced approximately 6 MB and are now mandatory guidance for new core/module/plugin loaders. | Fixed locally | Read `docs/rules/PHASE-0-RULE-PERFORMANCE-LOADER.md` before any loader/context-gate change. |
| PHASE-1.23 roadmap status | `R-PERF-LOADER` + `R-DDV` | Updated the root-cause document from analysis-only to implementation status: Wave 1–3 done locally, Wave 5 in progress, Wave 4 WooCommerce REST profiling next, and Wave 6 surface manifest/thin cron bridge planned. Added A/B, regression matrix and stop conditions. | Fixed locally | Establish deploy parity and route-level Woo evidence before shared-runtime changes. |
| PHASE-1.23 bundle root-cause dossier | `R-PERF-LOADER` + `R-DDV` | Added a five-layer trace model (`entrypoint` -> `hook` -> `runtime` -> `data/provider` -> `coexistence`), evidence fields, verified code anchors, root-cause categories, and a per-group matrix for CRM/BizCoach, Intent, LLM, channels, tools, Dino plugins and MU files. | Fixed locally | Capture first include/parent callback and classify each row before writing further guards. |
| PHASE-1.23 continuity evaluation | `R-PERF-LOADER` + `R-DDV` | Added a framework continuity scorecard (xuyen suot/ke thua/lien mach/no-recall/no-miss/no-broken-flow), Go/No-Go decision, invariants and pre-Wave-4 evidence gates. Declared route-level ownership trace as the next valid step and blocked broad guard expansion. | Fixed locally | Upgrade observability ownership fields (`callback_file`, `first_loaded_file`, `require_parent`) before route-level Woo/payment/channel refactor. |
| PHASE-1.23 loader risk deep review | `R-PERF-LOADER` + `R-DDV` | Distinguished duplicate file loading from duplicate hook/boot/state loading; documented main/compat/bundled/regular multi-owner paths; identified current collector blind spots and flow-break modes for OAuth, payment, webhook and cron; added PASS evidence gates. | Fixed locally | Add canonical boot state and causal trace fields before further context-gate changes. |
| PHASE-1.23 instrumentation and boot ownership spec | `R-PERF-LOADER` + `R-DDV` | Added `docs/analysis/PHASE-1.23-R-PERF-INSTRUMENTATION-BOOT-OWNERSHIP-SPEC-2026-08-10.md`: observe-only instrumentation v2, monotonic boot states, canonical owner claims, duplicate registration evidence, bounded JSONL schema, migration phases and flow invariants. Explicitly excludes WooCommerce loader/payment changes. | Documentation complete | Implement Level A/B evidence first; keep ownership observe-only until deployment topology is proven. |
| PHASE-1.23 QM trace operating model | `R-PERF-LOADER` + `R-DDV` | Added `docs/analysis/PHASE-1.23-R-PERF-QM-TRACE-PROBE-AUDIT-BA-TEST-2026-08-10.md`: QM limits, runtime/TwinCore/helper event layers, boot/hook/route/cron ownership, user-meta/cache semantics, probe catalog, PM/BA/audit/test/SRE responsibilities and acceptance matrix. WooCommerce remains observe-only external baseline. | Documentation complete | Implement observe-only QM/event/probe layer before any ownership enforcement or loader migration. |
| PHASE-1.23 CANONICAL roadmap | `R-PERF-LOADER` + `R-DDV` | Established `docs/roadmaps/PHASE-1.23-CANONICAL.md` as the single source of truth with scope boundary, canonical architecture, boot ownership, trace contract, W0-W7 code roadmap, cross-functional ownership, Definition of Done, stop conditions and release decisions. Marked the prior roadmap as supporting implementation notes. | Canonical established | Execute W1 observe-only QM instrumentation; do not change WooCommerce or enforce ownership before W1/W2 evidence passes. |
| PHASE-1.23 W1/W3 trace implementation | `R-PERF-LOADER` + `R-DDV` | Implemented local observe-only `bizcity.loader.v2` snapshots/export: callback file/line, bounded first/last file anchors, request context, all-callback source aggregation, registration delta and explicit unknown parent/boot-state fields. Added lazy Diagnostics probe `core.loader.trace_completeness`; no loader decision or WooCommerce code changed. | Implemented locally | Run Diagnostics/QM/JSONL A/B and deploy-parity validation before W2 ownership claims. |
| PHASE-1.23 W2 ownership observe-only | `R-PERF-LOADER` + `R-DDV` | Added `BizCity_Loader_Ownership_Registry` with monotonic state, canonical path claims, secondary-owner/version conflict events and QM/probe visibility. Integrated `llm_client`, `knowledge`, `intent` and `twin_core` claims across main, source compat and deployed compat loaders; corrected registry sequencing before the first claim and record claim attempts even when `class_exists()` skips a require. No enforcement and no WooCommerce changes. | Implemented locally | Deploy parity and run main/compat/bundled/regular combination matrix before W2 PASS. |
| PHASE-1.23 W3 loader probes | `R-PERF-LOADER` + `R-DDV` | Added lazy Diagnostics probes `core.loader.ownership` and `core.loader.registration_integrity` alongside `core.loader.trace_completeness`. They audit canonical owner/state, duplicate/conflicting claims, hook identity, available REST route identity and required cron schedules without changing runtime behavior. | Implemented locally | Run all three probes on Diagnostics/REST/CLI contexts; user-meta/cache probes remain planned. |
| PHASE-1.23 W4 TwinCore semantic trace | `R-PERF-LOADER` + `R-DDV` | Added bounded parent-child runtime spans to `BizCity_Twin_Trace`, instrumented `Twin_Context_Resolver::build_prompt_bundle()`, and added lazy probe `twinbrain.runtime.continuity` for balanced enter/exit/open-span evidence. No DB/event-stream/provider writes and no payload logging. | Partial implemented locally | Extend one BizCity-owned boundary at a time across LLM, Knowledge, Memory, Composer, Scheduler and Channel Sender. |
| PHASE-1.23 W4 LLM boundary trace | `R-PERF-LOADER` + `R-DDV` | Instrumented `BizCity_LLM_Client::chat()` with one parent operation span and child spans for primary/fallback gateway attempts. Continuity probe now verifies parent existence and requires `chat_gateway` children to belong to `llm_client.chat`; fallback remains explicit rather than being misclassified as duplicate runtime. | Partial implemented locally | Validate one gateway request and one fallback/degraded request without logging prompts, keys or provider payloads. |
| PHASE-1.23 W4 streaming boundary trace | `R-PERF-LOADER` + `R-DDV` | Instrumented `BizCity_LLM_Client::chat_stream()` with primary/fallback `chat_stream_gateway` child spans and extended continuity assertions for blocking and streaming parent edges. No callback chunks, prompts or credentials are logged. | Partial implemented locally | Exercise blocking and streaming routes, then extend semantic spans to Memory/Knowledge one boundary at a time. |
| PHASE-1.23 W5 Memory Recall boundary trace | `R-PERF-LOADER` + `R-DDV` | Instrumented `BizCity_TwinBrain_Memory_Recall::collect()` with parent recall span and unified/legacy child spans, including hashed scope, tier/citation counts and explicit fallback path. Extended continuity probe to validate memory child edges without logging memory text or prompt content. | Partial implemented locally | Validate unified-enabled and legacy fallback requests; add user-meta/cache semantic wrappers next. |
| PHASE-1.23 W5 cache semantic trace | `R-PERF-LOADER` + `R-DDV` | Added opt-in `BizCity_Cache` semantic spans for get/set/delete/flush_group with blog-scoped key signatures, hit/success and TTL buckets; added lazy `core.cache.trace_integrity` probe. Normal requests remain uninstrumented at cache-operation level and raw keys/values are never logged. | Partial implemented locally | Run forensic cache trace and correlate hit/miss/invalidation with QM DB/cache evidence; user-meta trace remains next. |
| PHASE-1.23 W5 user-meta semantic trace | `R-PERF-LOADER` + `R-DDV` | Added forensic-only WordPress user-meta filters for get/update/add/delete with hashed user/key scope, key family, blog ID, caller file/line and short-circuit result; added lazy `core.user_meta.trace_integrity` probe. Filters are absent on ordinary requests and raw values are never logged. | Partial implemented locally | Run a forensic profile/login/memory request and classify repeated reads by business scenario. |
| PHASE-1.23 registration duplicate audit correction | `R-PERF-LOADER` + `R-DDV` | Screenshot review showed `dup:3/7` could conflate separate object instances sharing the same method. Registration keys now include request-local object/closure identity hashes; duplicate counts must be re-captured before being treated as real duplicate boot. | Fixed locally | Re-run the same QM request and compare identity-aware `dup` counts; keep Woo/external buckets as observed baseline only. |
| PHASE-1.23 loader screenshot metric correction | `R-PERF-LOADER` + `R-DDV` | Screenshot showed BizCity phase memory at 2 MB beside QM top-bar 49 MB. Snapshot/output now separates current, allocated and peak PHP memory; path normalization also handles relative/Windows/UNC paths before source-group classification. | Fixed locally | Re-capture the same request; compare current/peak columns with QM top-bar and confirm remaining `external/unknown` is genuinely external. |
| PHASE-1.23 instrumentation overhead correction | `R-PERF-LOADER` + `R-DDV` | Screenshot showed `+15.7 MB` to `+31.8 MB` capture deltas caused by the snapshot itself retaining callback rows/Reflection/source summaries. Renamed the metric to `capture_overhead_delta_kb`, switched default QM requests to summary mode, retained full detail only for Diagnostics or `?bizcity_qm_probe=1`, and kept runtime used/peak separate. | Fixed locally | Re-capture QM-only and explicit forensic requests; never use capture overhead as plugin phase memory. |
| PHASE-1.23 QM readiness gate | `R-PERF-LOADER` + `R-DDV` | Added `memory_metric_consistent` evidence and explicit readiness policy: targeted BizCity fixes may proceed when duplicate/ownership evidence is clean; WooCommerce/Object Cache Pro/theme/external fixes remain separate workstreams. Diagnostics probe graph is not treated as normal shell baseline. | Fixed locally | Recapture after deploy/OPcache; start only owner-scoped BizCity fixes from clean evidence. |
| PHASE-1.23 runtime recapture blocker | `R-PERF-LOADER` + `R-DDV` | QM now shows ownership parity (`CONTRACT_READY`, `conflicts=0`) and the canonical footer pair is valid (`85.55 / 85.55 MB`, `source: lifecycle_snapshot`). The remaining red state is now explicitly labeled `raw allocator mismatch`, separating PHP/OPcache evidence drift from the displayed canonical invariant. | Canonical display fixed / raw runtime evidence pending | Deploy hook panel, collector, HTML output, header output, registry and BizCoach entrypoint together; refresh OPcache/PHP-FPM; verify raw consistency is true before closing the gate. |
| PHASE-1.23 owner-track analysis roadmap | `R-PERF-LOADER` + `R-DDV` | Added `docs/analysis/PHASE-1.23-BUNDLE-OWNER-FIX-ROADMAP-2026-08-10.md` with deep analysis for BizCoach, CRM, Intent, LLM, TwinCore/Knowledge/Memory, bundle/compat ownership, cache/user-meta and channel/OAuth/cron boundaries. Added mandatory pre-fix evidence, BA/audit/test gates and A0-A7 analysis roadmap. No runtime code changed. | Documentation complete | Use the seven-item readiness rule before any owner-scoped fix. |
| PHASE-1.23 BizCoach first lazy split | `R-PERF-LOADER` + `R-DDV` | Deferred `bizcoach-pro/includes/admin/class-astro-admin-form.php` from plugin file scope to `BizCoach_Pro_Astro_Admin_List::render_add_new()`. The class has no file-scope hook/route side effect; public routers, REST, cron and Intent provider paths remain unchanged. | Implemented locally | Validate Vedic/BaZi add-new submit plus normal admin/list/detail and bundled/regular ownership before the next BizCoach split. |
| PHASE-1.23 BizCoach bundled ownership claim | `R-PERF-LOADER` + `R-DDV` | Added observe-only `bizcoach` claim/`CONTRACT_READY` transition at the bundled entrypoint, before shell guard and after stable constants. This records shell skip and real surface ownership without blocking or changing runtime loading. | Implemented locally | Recapture Boot ownership on shell, BizCoach admin and public/REST surfaces before enforcing or splitting more graph. |
| PHASE-1.23 BizCoach OPcache invalidation fix | `R-PERF-LOADER` + `R-DDV` | Removed per-request `opcache_invalidate()` for BizCoach Astro REST class. Kept `require_once` and explicit idempotent `init()`; stale bytecode is handled by deploy/maintenance OPcache refresh. No route, OAuth or provider behavior changed. | Implemented locally | Validate persona REST, public astrology/transit, normal admin and deploy OPcache procedure before next BizCoach split. |
| PHASE-1.23 BizCoach persona provider lazy split | `R-PERF-LOADER` + `R-DDV` | Added `bcpro_load_persona_provider_classes()` and moved persona provider requires behind the provider filter, direct REST consumers and diagnostic F4/F6/F14 tasks. Provider IDs/source kinds remain unchanged; no new ownership path or Woo code changed. | Implemented locally | Validate provider catalog, coach-map REST, passage REST and BizCoach diagnostics before the next eager include split. |
| PHASE-1.23 BizCoach admin lifecycle splits | `R-PERF-LOADER` + `R-DDV` | Deferred `class-admin-coachees.php`, deprecated `class-astro-admin-settings.php`, and `class-astro-log-admin.php` from normal admin file scope. Menu registration stays on `admin_menu`; legacy Astro settings POST handlers and Astro log AJAX registration load only for their matching actions. No public, REST, cron or Intent contract was changed. | Implemented locally | Validate Coachees/Vedic/BaZi menus, legacy settings POST actions, Astro log AJAX, normal admin, bundled/regular ownership and fresh deploy parity. |
| PHASE-1.23 framework monitor evaluation | `R-PERF-LOADER` + `R-DDV` | Added [framework monitor evaluation](docs/analysis/PHASE-1.23-FRAMEWORK-MONITOR-EVALUATION-2026-08-10.md) from the current QM/BizCity Loader capture. It separates ownership PASS, bounded registration evidence, Diagnostics contamination, external Woo/Flatsome baseline, raw memory evidence and the prioritized optimization framework/backlog. | Research complete / runtime gates open | Use the dossier to run surface-labeled A/B captures and route-level owner analysis; do not treat the Diagnostics request as a normal shell memory baseline. |
| PHASE-1.23 monitor severity/result UX | `R-PERF-LOADER` + `R-DDV` | Clarified the Diagnostics probe table labels from `Severity`/`Last result` to `Risk severity`/`Last runtime result`. Probe semantics remain unchanged: a critical-risk probe can legitimately have a passing latest run. | Implemented locally | Re-capture the Diagnostics page and verify the labels prevent confusion without changing probe status or severity. |
| PHASE-1.23 monitor runtime identity | `R-PERF-LOADER` + `R-DDV` | Added bounded runtime identity to loader context and QM output: plugin/PHP version, OPcache availability, release hash and short hashes for main/collector/panel/registry/source-deployed compat artifacts. Missing files remain `unknown`; no loading decision changed. | Implemented locally / runtime evidence pending | Deploy the hook panel/output with the same release, then compare runtime identity hashes across source/deployed and regular/compat requests. |
| PHASE-1.23 version authority parity | `R-PERF-LOADER` + `R-DDV` | Removed the stale compat version `1.0.1`; main and both compat paths now use canonical `1.3.7` and expose `BIZCITY_TWIN_AI_VERSION_SOURCE` (`compat_constant` or `main_constant`). Runtime identity renders `version_source` so early constant shadowing is visible. | Implemented locally / artifact parity pending | Deploy main plus the active compat copy together; verify runtime `plugin=1.3.7`, `version_source=compat_constant`, and compare all artifact hashes. |
| PHASE-1.23 Automation surface gate | `R-PERF-LOADER` + `R-DDV` | Replaced the broad `$_bizcity_admin_ctx` Automation bootstrap gate with an explicit resolver for `bizcity-automation` admin, `bizcity-automation/v1` REST, `/flow`, webhook, cron and CLI surfaces. Unconditional cron schedule-name registration remains intact. | Implemented locally / runtime evidence pending | Recapture Diagnostics/unrelated admin versus Automation page/REST/webhook/cron and verify the `core:automation` pre-plugin bucket drops only on unrelated surfaces. |
| WebChat bootstrap | `modules.webchat.json` | `ensure_tables_exist()` no longer runs schema checks on ordinary frontend HTML; `SHOW TABLES` callers use metadata helper. | Fixed | Keep frontend, REST, webhook and cron checks separate in regression tests. |
| Compat loader | `R-PERF.5` in `.github/copilot-instructions.md` | Intent, Knowledge, Twin Core and Market preloads are gated; deployed and source compat loaders are both tracked. | Fixed | Check both loader copies after every sync/deploy change. |
| Metadata helper | `R-PERF.5` | `information_schema` fallback uses blog/database-aware `wp_cache`, caches false, and performs no `update_option()` on a hot-path miss. | Fixed | Use canonical helper for all new table/column checks. |
| Automation Calendar REST | `core.automation.json` + `R-PERF.5` | Raw `SHOW TABLES` replaced with `bizcity_tbl_exists()` and a blog/database-aware fallback. | Fixed | Add REST request-count evidence when the endpoint is changed. |
| Channel identity caches | `core.channel-gateway.json` + `R-PERF.5` | Identity Hub and Channel User Linker memo keys now include blog/database; false results are preserved. | Fixed | Re-check cache isolation after shard/router changes. |
| ZNS Rules | `modules.zns-automation.json` | Changelog normalized to schema-v1; runtime cache preserves false and includes blog/database. | Fixed | Run schema validator and diagnostics probe. |
| BizCoach Pro | `bizcoach.astro.json` + `R-PERF.5` | Stable `BCPRO_VERSION`; legacy cron cleanup and Astro Checklist repair moved off frontend bootstrap. | Fixed | Audit remaining legacy `bccm_*` ownership before adding schema rows. |
| BZCC/BZDOC/BZPB installers | `modules.bzcc.json` + R-DCL debt list | Schema self-healing is restricted to admin/REST/cron/CLI; public route registration remains intact. | Fixed | Create canonical changelogs only after table registry/schema ownership is complete. |
| Tool Image | R-DCL debt list + `R-PERF.5` | Removed redundant file-scope `upgrade.php`; installer loads it immediately before `dbDelta()`. | Fixed | Catalog all BZTIMG tables before any schema change. |
| Diagnostics graph | `R-PERF.5` | Full probe graph no longer loads on unrelated REST requests; retained for admin/CLI/diagnostics namespace. | Fixed | Lazy-load further by diagnostics screen when admin memory is measured. |
| Frontend baseline preload | `R-PERF.5` | Scheduler, Memory, Agents, Runtime, Skills, Persona, TwinChat, TwinShell, TwinSearch and Doc/Image/Page Builder full runtimes are now route/context-gated; `/gpt/`, `/twin/`, `/twinchat/`, `/scheduler/`, `/skills/` and tool routes remain explicit exceptions. | Fixed locally | Re-measure frontend HTML and wp-admin separately; split TwinWeb public shortcode bootstrap before gating it. |
| LLM client preload | `R-PERF.5` | `core/bizcity-llm/bootstrap.php` is now gated in the main plugin and both compat-loader copies; plain frontend HTML no longer loads the gateway client graph. | Fixed locally | Verify OPcache/deployed loader parity and measure a fresh PHP worker. |
| Metadata cold misses in Query Monitor | `R-PERF.5` + `R-METADATA-CACHE` | Astro Checklist table check and Rolling/Episodic `identity_uuid` checks now use version fast paths plus blog/database-aware fallback caches, including cached false results. | Fixed locally | Re-run the same request and confirm rows 1–3 disappear when schema options are current; repair context may still issue one metadata query. |
| Facebook widget option scan | `R-PERF.5` + `R-CACHE` | `BizCity_FB_Chat_Widget::get_any_enabled()` no longer scans the options table on every `wp_footer`; it uses blog/database-scoped object + transient cache and invalidates after REST save. | Fixed locally | Confirm the `LIKE 'bizcity_cg_fb_widget_%'` query appears only on cold-cache rebuild. |
| Facebook widget exact option index | `R-PERF.5` + `R-CACHE` | Added autoloaded `bizcity_cg_fb_widget_active_page`; normal frontend lookup now uses exact `get_option()` plus one page-specific option read. Prefix `LIKE` scan remains only as migration fallback for legacy sites. | Fixed locally | Save widget settings once or let the first legacy fallback backfill the index, then confirm the `LIKE` query disappears. |
| Diagnostics probe loader | `PHASE-1.23-R-PERF-LOADER` + `R-PERF.5` | Diagnostics probes are queued at bootstrap and loaded once only for the Diagnostics screen, `bizcity-diagnostics/v1` REST namespace, or WP-CLI. Probe IDs, order, interface and registration filter remain unchanged. | Fixed locally | Re-measure unrelated admin pages, Diagnostics screen and Smoke REST after deploy; verify source/deploy tracer parity. |
| Loader Hook Observability Panel | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-HOOK` | Added bounded lifecycle snapshots for `plugins_loaded`, `init`, `rest_api_init`, `current_screen`, and `admin_footer`, with normalized callback owner, source group, source-group aggregate and memory delta. `admin_footer` capture now runs before panel render. Added Diagnostics UI injection and admin-only `bizcity-diagnostics/v1/loader/hooks` endpoint. | Fixed locally | Deploy and compare phase deltas against the memory trace; keep caps if panel overhead is measurable. |
| Query Monitor loader integration | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-QM` | Added lazy QM collector `bizcity_loader`, HTML `BizCity Loader` panel, compact REST/AJAX `X-QM-bizcity_loader-*` headers, stage markers, required `qm/output/menus` registration, and bounded admin-default JSONL export for agent/debugger analysis. | Fixed locally | Deploy the updated collector/output/integration, reload OPcache/PHP-FPM, then inspect `qm-loader-snapshots-YYYY-MM-DD.jsonl` from an admin request. |
| Query Monitor loader integration | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-QM` | Added lazy QM collector `bizcity_loader`, HTML `BizCity Loader` panel, compact REST/AJAX headers, stage markers, menu registration, and opt-in JSONL export. Trace UI now separates new-file module buckets from callback source groups, shows top buckets only, and preserves total-vs-sampled callback counts. | Fixed locally | Deploy the updated panel/collector, run a probe request, then use new-file buckets at `init` and `rest_api_init` as the primary root-cause signal. |
| Composer preload on TwinChat shell | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-COMPOSER` | Composer `vendor/autoload.php` for FPDI/FPDF is no longer loaded at file scope on the TwinChat admin shell or ordinary HTML. It remains enabled for REST, cron, CLI, and PDF/tool surfaces. | Fixed locally | Re-run the same TwinChat admin request and compare peak memory, included files, and declared classes before removing additional core preload. |
| TwinChat shell runtime graph | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-SHELL` | TwinChat admin shell no longer loads `tools/class-diagnostics.php`, `modules/twinsource/bootstrap.php`, or `core/twinbrain/bootstrap.php`. The shell only renders the iframe; iframe/REST/Diagnostics requests retain their existing loaders. | Fixed locally | Re-run the same `admin.php?page=bizcity-twinchat&plugin=twinchat` probe and compare pre-`plugins_loaded`/peak memory, files, and classes. |
| Early preload trace | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-EARLY` | Added admin-default `pre_plugins_loaded` baseline/delta to the Query Monitor loader panel and JSONL snapshot. This exposes compat/plugin file-scope loading that the previous lifecycle trace could not see. | Fixed locally | Reload an admin request without query flags and compare `pre_plugins_loaded` buckets before/after shell gates. |
| Admin-default loader evidence | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-QM` | Loader baseline, stage markers and JSONL snapshot now run by default for admin users with `manage_options`; `?bizcity_qm_probe=1` remains available for non-admin diagnostic requests. | Fixed locally | Reload an admin request without query flags and inspect the loader panel/JSONL snapshot. |
| Early capability fatal regression | `PHASE-1.23-R-PERF-LOADER` + `PHP74-COMPAT` | Admin-default probe baseline no longer calls `current_user_can()` during mu-plugin/file-scope loading. Capability checks remain deferred to post-loader marker/stage/export paths. This avoids entering WordPress capability resolution before the normal user/capability lifecycle. | Fixed locally | Deploy the integration fix and verify the TwinChat admin URL no longer produces the capabilities.php fatal; confirm JSONL still records admin stages. |
| Bundled CRM/BizCoach shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added entrypoint guards so bundled `bizcity-twin-crm` and `bizcoach-pro` return early on the default TwinChat admin shell, including alternate/regular-plugin load paths. Dedicated CRM (`plugin=crm`), REST, webhook, cron and public surfaces remain allowed. | Fixed locally | Re-run `pre_plugins_loaded` and confirm `bundle:bizcity-twin-crm` / `bundle:bizcoach-pro` new-file buckets disappear from the default shell. |
| Zalo Bot shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added a self-guard to the `bizcity-zalo-bot` entrypoint so a separately active regular-plugin copy cannot preload its bootstrap on the default TwinChat admin shell. Dedicated Zalo admin, REST, webhook, cron and public surfaces remain enabled. | Fixed locally | Reload the same TwinChat shell and confirm `bundle:bizcity-zalo-bot` disappears from `pre_plugins_loaded`. |
| Intent graph on TwinChat shell | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-INTENT` | Fixed the main plugin Intent loader condition so `core/intent/bootstrap.php` is excluded from the TwinChat admin shell. Compat loader already had the shell guard; the main plugin was reintroducing the 64-file graph. REST, webhook, cron, CLI and other admin Intent surfaces remain enabled. | Fixed locally | Re-run `pre_plugins_loaded` and confirm `core:intent` disappears from the default TwinChat shell bucket. |
| Wallet MU runtime split | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-MU` | Wallet MU now skips the TwinChat shell, defers REST controllers to `rest_api_init`, network/admin pages to their menu hooks, and product-credit fields to the product screen. Woo checkout/payment/referral/account/cron hooks remain loaded. | Fixed locally | Compare TwinChat shell and frontend HTML traces; separately verify wallet REST, network admin, product edit, checkout and cron. |
| BizCity Web Odoo admin split | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-MU` | Deferred the two Odoo app-catalog function files from every request to `network_admin_menu`/`admin_menu`; clone, OAuth provisioning, public shortcode and duplicate operations remain in the existing path. | Fixed locally | Compare ordinary frontend/REST/cron traces and verify network/site app-catalog pages. |
| Facebook Bot shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added self-guards to the Facebook Bot entrypoint and alternate bootstrap so the Messenger/Page runtime is not preloaded by the TwinChat shell; webhook, OAuth, REST, cron and dedicated admin remain enabled. | Fixed locally | Confirm `bundle:bizcity-facebook-bot` disappears from `pre_plugins_loaded`. |
| CF7/TTCK shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added exact TwinChat-shell guards to Contact Form 7 and the TTCK Woo payment plugin. Public forms, checkout/payment AJAX, REST, cron and dedicated admin pages remain enabled. | Fixed locally | Confirm `plugin:contact-form-7` and `plugin:thanh-toan-chuyen-khoan` disappear from the shell trace. |
| Doc/Content Creator shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added self-guards to Doc Studio and Content Creator entrypoints so their 17-file runtime graphs are not loaded by the TwinChat shell. `/doc`, `/tool-doc`, `/tool-content-creator`, REST/AJAX, cron, CLI and dedicated admin surfaces remain enabled. | Fixed locally | Confirm `bundle:bizcity-doc` and `bundle:bizcity-content-creator` disappear from `pre_plugins_loaded`. |
| Video Kling shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added self-guards to Video Kling entrypoint and bootstrap so the 21-file video/FFmpeg/TTS graph is not loaded by the TwinChat shell. `/kling-video`, `/video-editor`, REST/AJAX, cron, CLI and dedicated admin surfaces remain enabled. | Fixed locally | Confirm `bundle:bizcity-video-kling` disappears from `pre_plugins_loaded`. |
| Wallet REST partial-deploy guard | `R-GW-8` + `R-PERF-LOADER-MU` | Wallet REST controllers now require readable artifacts and boot only available classes, so a missing `class-plans-proxy-rest.php` cannot turn unrelated Doc/KG/CRM REST requests into HTTP 500. Restore the missing artifact separately to recover the Wallet proxy route. | Fixed locally | Deploy the bootstrap plus missing controller, then retest `/bzdoc/v1/list`, `/bizcity/kg/v1/notebooks`, and `/bizcity-crm/v1/admin-chat-grants/version`. |
| Admin Menu Editor shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Admin Menu Editor now skips only the TwinChat iframe shell before loading its dependency graph; Network Admin and all dedicated WordPress admin pages remain enabled. | Fixed locally | Confirm `plugin:admin-menu-editor` disappears from the TwinChat shell trace and Network Admin still renders normally. |
| Agent Market shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Agent Market now skips only the TwinChat iframe shell before loading its 11-file graph; public marketplace pages, shortcodes, REST/AJAX, cron and Network Admin remain enabled. | Fixed locally | Confirm `plugin:bizcity-agent-market` disappears from the TwinChat shell trace. |
| Login with Google shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Login with Google now skips Composer/plugin runtime only on the TwinChat iframe shell; wp-login, frontend OAuth, callbacks and settings pages remain enabled. | Fixed locally | Confirm `plugin:login-with-google` disappears from the TwinChat shell trace and Google login still works outside it. |
| Page Builder shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Page Builder now skips its 10-file editor/runtime graph only on the TwinChat iframe shell; `/tool-pagebuilder`, REST/AJAX, cron, CLI and dedicated admin pages remain enabled. | Fixed locally | Confirm `bundle:bizcity-pagebuilder` disappears from the TwinChat shell trace. |
| Page Builder/WP Crontrol shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Page Builder and WP Crontrol now skip their editor/cron-admin graphs only on the TwinChat iframe shell; Page Builder routes/REST/AJAX and WP Crontrol admin/cron behavior remain enabled elsewhere. | Fixed locally | Confirm `bundle:bizcity-pagebuilder` and `plugin:wp-crontrol` disappear from the shell trace. |
| Transposh translation shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Transposh and its Language Switcher now skip translation/parser/widget runtime only on the TwinChat iframe shell; frontend translation/rewrite, translation AJAX, cron and dedicated admin settings remain enabled. | Fixed locally | Confirm `plugin:transposh-translation-filter-for-wordpress` and `plugin:language-switcher-for-transposh` disappear from the shell trace. |
| BizCity Web MU shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-MU` | BizCity Web clone/OAuth/app-catalog runtime now skips only the TwinChat iframe shell; frontend shortcodes, clone/AJAX, OAuth provisioning and Network Admin remain enabled. | Fixed locally | Confirm `mu:bizcity-web` disappears from the shell trace and network clone pages still render. |
| OAuth Server shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | OAuth Server now skips its OAuth2/autoprovision graph only on the TwinChat iframe shell; `/oauth`, `/.well-known`, REST bearer authentication, callbacks and OAuth admin pages remain enabled. | Fixed locally | Confirm `plugin:bizgpt-oauth-server-new` disappears from the shell trace and OAuth flows still work outside it. |
| User-role/Admin Bar MU shell split | `R-PERF-LOADER-MU` | User-role repair skips its profile/admin graph on the TwinChat shell. Admin Bar keeps the global `pre_get_blogs_of_user` loop protection but skips custom Recent Sites rendering and user-meta tracking on the shell. | Fixed locally | Confirm residual user-role/Admin Bar callbacks drop while multisite protection remains active. |
| Phone auth/User sync MU shell split | `R-PERF-LOADER-MU` | Phone login/OTP/Woo My Account hooks and BizGPT user/password/global-meta sync hooks now skip only the TwinChat shell; login, registration, profile and multisite sync remain enabled outside it. | Fixed locally | Confirm `mu:bizcity-myaccount-phone.php` and `mu:biz-id.php` residual callbacks drop without affecting auth/profile flows. |
| Legacy Custom Flows shell guard | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Deprecated `bizgpt-custom-flows` now skips its handler/shortcode graph only on the TwinChat iframe shell; legacy flow listeners, shortcodes, admin editor and cron remain enabled elsewhere. | Fixed locally | Confirm `plugin:bizgpt-custom-flows` disappears from the shell trace before considering removal/migration. |
| Dino-site plugin shell guards | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-BUNDLE` | Added exact TwinChat-shell guards to Dino points/order tooling, Product Import/Export for WooCommerce, BizGPT Voucher, and Mabel Wheel. Public/order/checkout/AJAX and dedicated admin behavior remain enabled outside the shell; Mabel file-scope table DDL is skipped on the shell. | Fixed locally | On Dino site, confirm `bizgpt-dino-tichdiem`, `product-import-export-for-woo`, `bizgpt-vi-voucher`, and `mabel-wheel-of-fortune` disappear from `pre_plugins_loaded`. |
| Dino guard placement correction | `R-PERF-LOADER-BUNDLE` + `PHP74-COMPAT` | Corrected a context-placement regression: Mabel guard is now outside `MABEL_WOF_auto_loader()`, and Product Import/Export guard is outside its plugin header comment. The old placement could cause Mabel class-load fatal or leave Product Import guard inert. | Fixed locally | Deploy corrected files and confirm no new Mabel fatal plus Dino shell buckets disappear. |
| Admin Bar shell Quick Tools | `R-PERF-LOADER-MU` | TwinChat shell retains a lightweight Quick Tools menu for TwinChat, Channel Gateway, Automation and Diagnostics. It avoids Recent Sites/user_meta/global wp_blogs work; the full My Sites menu remains unchanged outside the shell. | Fixed locally | Confirm Quick Tools appears in the shell and no user-meta/global-site queries are added. |
| Intent graph on unrelated admin pages | `PHASE-1.23-R-PERF-LOADER` + `R-PERF-LOADER-INTENT` | Main and both compat loaders now keep Intent only for backend dispatch, Intent-owned admin/AJAX/public surfaces, and explicit Intent/WebChat pipeline actions; ordinary wp-admin pages no longer preload the full graph. | Fixed locally | Measure ordinary wp-admin, TwinChat/TwinBrain shell, Intent admin page, REST/webhook and cron separately; confirm `core:intent` remains present only where required. |
| Outstanding R-DCL | `core/diagnostics/changelog/` | BZDOC, BZPB and remaining BZTIMG tables need canonical table registry + JSON ownership; BizCoach legacy tables need ownership reconciliation. | Open | Do not invent `since` versions; inventory installer DDL first, then add registry and JSON in one change. |

**Record rule:** Runtime-only performance changes belong in this ledger and
`R-PERF.5`; only table/column/index changes bump a module JSON
`current_version`/`history`. Every schema entry must pass the canonical
validator before merge.

### Memory unify progress and DDV — 2026-07-31
- **PASS:** `modules.zalobot.memory_unify` verifies the active ZaloBot staging contract: canonical TwinBrain writer call, normalized channel context, and rollback-controlled legacy writer flag. No LLM call or persistent write is performed by this probe.
- **PASS:** `twinbrain.memory.hub-rest` verifies mirror wiring plus POST/GET/PUT/DELETE `/memory/me` round-trip and sentinel cleanup.
- **Completed:** memory quarantine/inventory hardening, canonical writer context helper, Zalo staging wiring, REST/Notes mirror delete wiring, and the active diagnostics contracts are documented in [PHASE-MEMORY-UNIFY-ANALYSIS.md](core/memory/docs/PHASE-MEMORY-UNIFY-ANALYSIS.md).
- **Still blocked:** legacy Zalo writer shutdown, session/note read-policy decision, REST unified read cutover, EXPLAIN benchmark, historical backfill, write-cutover flag, and D7 rename/drop. No destructive migration was performed.

### Diagnostics and production triage — 2026-07-28
- **PASS:** `twinbrain.memory.writer.llm` now completes Mode 2 extraction with a unique per-run probe payload; the probe no longer collides with the writer's identity-scoped 24-hour dedupe transient. The latest runtime result persisted 4 extracted rows, including a `preference` row.
- **Fixed:** `core/intent/bootstrap.php` now guards Rolling/Episodic memory initialization with `class_exists()` and records an artifact/load-order error instead of producing a Class-not-found fatal when a memory file is missing or invalid.
- **Improved diagnostics:** Rolling and Episodic schema migration failures now include a bounded `db_error` reason in the PHP log, without logging SQL, credentials, or PII. This is needed to distinguish routed DDL refusal, read-only shards, missing ALTER privileges, and silent `dbDelta()` no-op behavior.
- **Validated locally:** touched PHP files have balanced braces and VS Code diagnostics report no errors. PHP CLI syntax validation is unavailable in the local environment.
- **OPEN — production artifact:** production still reports a parse error in `core/knowledge/includes/class-user-memory.php` at line 733, while the local file is structurally complete. Production file hash/deploy parity and OPcache/PHP-FPM refresh remain unverified; this is not considered resolved by local validation.
- **OPEN — tenant schema:** multiple tenant tables still lack `identity_uuid` in `bizcity_memory_rolling` and `bizcity_memory_episodic`. The migration/backoff prevents request storms but does not make the DDL succeed. The next production log must capture the new `db_error` field before choosing a router/privilege/read-only fix.
- **OPEN — retrieval hydration:** `twinbrain.retrieval.hydration` remains `CRITICAL`/not executed in the latest Diagnostics screen. The KG filestore fallback patch is present locally, but runtime PASS evidence is still missing.
- **Scope:** `store_locations` remains intentionally out of scope for this triage.

### Added — TwinBrain + TwinCore canon documentation pass (2026-06-03)
- Restructured BRAIN-SESSIONS group: moved `class-twinbrain-sessions-manager.php` + `class-twinbrain-sessions-rest.php` into [core/twinbrain/includes/sessions/](core/twinbrain/includes/sessions/) and feature design doc into [core/twinbrain/docs/sessions/](core/twinbrain/docs/sessions/). Bootstrap.php paths updated with R-STAMP.
- New: [core/twinbrain/docs/sessions/README.md](core/twinbrain/docs/sessions/README.md) — sessions group doc index (read-order + surface map + rule inheritance).
- New: [core/twinbrain/docs/sessions/TWINBRAIN-SESSIONS-CANON.md](core/twinbrain/docs/sessions/TWINBRAIN-SESSIONS-CANON.md) — sub-canon (rules · file map · session_id format · 5 event taxonomy · REST contract · Mode 4 + Tier F · anti-patterns · DDV checklist).
- New: [core/twinbrain/docs/sessions/TWINBRAIN-SESSIONS-ROADMAP.md](core/twinbrain/docs/sessions/TWINBRAIN-SESSIONS-ROADMAP.md) — SHIPPED waves (BS-1..5 + restructure) + 5 PENDING (BS-6 Scheduler bridge / BS-7 cron close-scan / BS-8 timeline REST / BS-9 hard-delete / BS-10 LLM mood upgrade) + risk matrix + dep graph.
- New: [core/twin-core/docs/TWINCORE-0-CANON.md](core/twin-core/docs/TWINCORE-0-CANON.md) — kernel canon for twin-core: 4-cluster topology (Trace+Debug · Event Stream Backbone · Focus Gate · Data Contract+State+Agent Loop), full file catalog, bootstrap order, feature flags, anti-patterns, sub-canon links to event-stream README.
- Updated: [core/twinbrain/docs/TWINBRAIN-0-CANON.md](core/twinbrain/docs/TWINBRAIN-0-CANON.md) v1.0 → v1.1 — added Layer 0.7 (Session Threading) to pipeline diagram, R-CH-NS + R-TBR-6 to inherited rules, sessions group entries to file catalog (§2), sessions docs to read-order (§4), 3 new anti-patterns (no `bizcity_brain_sessions` table, dispatch via bus, mood cadence rule), and links to sessions sub-canon + twin-core canon.

### Added — BRAIN-SESSIONS BS-1 → BS-4 (TwinBrain conversation threads + empathic memory) (2026-06-03)
- Foundation: 5 new event_types (`brain_session_{created,renamed,archived,mood_sampled,carry_forward}`) + JSON schemas (draft-07) + canonical session_id format `^brain_sess_[0-9]+_[0-9]+_[0-9a-f]{4}$`.
- Schema: VIEW `bizcity_brain_sessions` projects per-session state from `bizcity_twin_event_stream` (no new tables — R-TBR-6 compliant).
- Sessions Manager: `BizCity_TwinBrain_Sessions_Manager` (mint / create / rename / archive / list / latest_title / latest_mood).
- REST: `bizcity-twinbrain/v1/sessions` — `GET/POST/PATCH/archive` (X-WP-Nonce, ownership-checked).
- Runtime: `/turn` + `/turn/stream` accept `session_id`; SSE `started` frame echoes id; auto-mint on first turn.
- FE: `brainSessions.ts` API client + `brainSessionsStore` (Zustand, persists active session in `sessionStorage`) + `BrainSessionsList.tsx` sidebar (Refresh / New / archived toggle / Rename / Archive) + 220px collapsible column in `BrainHome`.
- Memory_Writer Mode 4: `sample_mood()` heuristic-only mood sampler (cadence-3 default, idempotent per `trace_id`, VN/EN cue lexicons, 9 labels, valence ∈ [-1,+1]). Emits `brain_session_mood_sampled`. Filters: `bizcity_twinbrain_mood_sample_cadence`, `bizcity_twinbrain_mood_derive` (LLM override hook).
- Memory_Recall Tier F: `🌱 Trạng thái cảm xúc (latest)` block in both legacy + unified collectors; counts `F`.
- Probes: `twinbrain.brain.sessions` (3-layer foundation), `twinbrain.brain.sessions.crud` (real-call CRUD), `twinbrain.brain.mood.sampler` (real-call mood + Tier F + idempotency).
- Docs: [core/twinbrain/docs/sessions/TWINBRAIN-FEATURE-BRAIN-SESSIONS.md](core/twinbrain/docs/sessions/TWINBRAIN-FEATURE-BRAIN-SESSIONS.md) bumped to v1.3 ACTIVE with §16 ship log.

### Added — Phase 0.99 Framework v1.0 Readiness
- `composer.json` root + PSR-4 autoload `BizCity\Twin\` namespace + classmap fallback giữ legacy `BizCity_*`.
- `core/twin-core/contracts/framework-contracts.php` — public interfaces (`BizCity_Module_Interface`, `BizCity_LLM_Client_Interface`, `BizCity_Tool_Interface`, `BizCity_Agent_Interface`) + abstract `BizCity_Module_Base`.
- `core/bizcity-llm/includes/helpers-deprecation.php` — `BizCity_Deprecation::notify()` / `notify_filter()` / `notify_storage()` + filter `bizcity_deprecation_silent` + action `bizcity_deprecation_notice`.
- `docs/extension/HOOKS.md` — public hooks catalog (40+ filter/action với `@since`).
- `docs/getting-started.md` — 5-min setup guide cho dev mới.
- `docs/extending/sub-plugin-quickstart.md` — copy-paste sub-plugin template.
- `docs/extending/agent-tool-recipe.md` — pattern register tool vào agent registry.
- `docs/roadmaps/PHASE-0.99-FRAMEWORK-V1.md` — 8-sprint roadmap để tag `v1.0.0`.
- `.github/copilot-instructions.md` — rule TỐI THƯỢNG R-GW-API-CATALOG (workflow lookup/extend 1-API trước khi code).
- `core/twin-core/contracts/class-module-registry.php` — implementation cho filter `bizcity_register_module` (boot lifecycle, requirement gating, exception isolation, inventory introspection).
- `core/diagnostics/includes/probes/class-probe-module-registry.php` — diagnostic probe `core.module-registry` (R-DDV) surface 3rd-party modules đăng ký qua filter.
- `bin/diagnostics-run.php` — headless CLI runner cho diagnostics + JUnit XML reporter (`--junit=path`, `--filter=glob`, `--skip-network`).
- `.github/workflows/ci.yml` — PHP 7.4/8.1/8.2 matrix · syntax check · grep guards (PHP 7.4 compat + R-GW-8 anti-patterns) · schema validator · diagnostics CLI mock · HOOKS.md coverage diff.
- `CHANGELOG.md` + `CONTRIBUTING.md` + `SECURITY.md` + 3 `.github/ISSUE_TEMPLATE/` files + `.github/PULL_REQUEST_TEMPLATE.md` cho OSS hygiene.

### Changed
- [`core/knowledge/includes/functions.php`](core/knowledge/includes/functions.php) canonical filter đổi sang `bizcity_after_handle_guest_flows`; legacy `bizgpt_after_handle_guest_flows` vẫn applied (back-compat) + emit deprecation notice via `BizCity_Deprecation::notify_filter()`. Sẽ remove ở 2.0.0.
- [`core/helper-legacy/flows/legacy_bizgpt_facebook.php`](core/helper-legacy/flows/legacy_bizgpt_facebook.php) `twf_handle_facebook_multi_page_post()` chuyển sang `BizCity_Deprecation::notify()` (fallback `_doing_it_wrong()` khi class chưa load).
- [`core/bizcity-llm/includes/class-llm-client.php`](core/bizcity-llm/includes/class-llm-client.php) `generate_image()` forward thêm `input_images[]` + `stream` xuống gateway.
- [`plugins/bizcity-tool-image/includes/class-qr-studio-page.php`](plugins/bizcity-tool-image/includes/class-qr-studio-page.php) refactor sang `BizCity_LLM_Client` (R-GW-8 compliance, không còn dependence vào `BizCity_Router_Proxy`).
- [`plugins/bizcity-doc/includes/image/class-image-pipeline.php`](plugins/bizcity-doc/includes/image/class-image-pipeline.php) cùng pattern — không còn reference server-only class trên client.
- [`plugins/bizcity-tool-image/includes/admin-menu.php`](plugins/bizcity-tool-image/includes/admin-menu.php) Character Studio status check qua `BizCity_LLM_Client::is_ready()`.

### Server-side companion (`bizcity-llm-router`)
- `handle_image_generation` whitelist `input_images[]` + dispatch sang `generate_image_stream()` khi có vision refs.

### Deprecated
- Filter `bizgpt_after_handle_guest_flows` → renamed to `bizcity_after_handle_guest_flows` (will remove in 2.0.0). Emits a one-shot notice when listeners are attached.
- `twf_handle_facebook_multi_page_post()` → use `BizCity_FB_Publisher` via the scheduler (`event_type=fb_post`).

### Removed
- Bundled vertical plugins moved to `plugins/_archived/` (no longer auto-loaded):
  - `bizcity-automation` → replaced by `core/automation/` (native xyflow runtime, BE-1..BE-5 shipped 2026-05-29).
  - `bizcity-tool-mindmap` → mindmap functionality moved to `bizcity-doc` (Phase 6.3 PHASE-0.7-DOCGEN).
  - `bizcity-tool-woo` → archived; WooCommerce tools to be re-shipped via Marketplace branch (catalog #11).
  - `bizcity-crm-tichdiem` → archived; loyalty/points adapter folded into `bizcity-twin-crm` Customer Source registry (`bizcity_crm_register_customer_sources` filter, `modules.twin-crm` v1.16.0).
- Loader entry `bizcity-tool-mindmap` removed from `_bizcity_bundled_must_load` in [bizcity-twin-ai.php](bizcity-twin-ai.php).

---

## [1.3.7] — 2026-05-29

### Added
- AUTOMATION BE-5 — CRM bridge polish (`emit_crm_bridge()` capture `event_id` qua filter `bizcity_crm_event_create_filter` rồi UPDATE ngược `runs.crm_event_id`).
- Legacy guard `admin_notices` cảnh báo plugin `bizcity-automation` cũ collision hook.
- User guide `core/automation/docs/AUTOMATION-USER-GUIDE.md` (12 chương).

### Fixed
- `class-channel-router.php` — thay `str_contains()` (PHP 8+) bằng `strpos() !== false` (compat PHP 7.4).
- `class-fb-publisher.php` — bỏ union return type `array|WP_Error` (fatal trên PHP 7.4).

---

## [1.3.6] — 2026-05-26

### Added
- Channel Gateway namespace `bizcity-channel/v1` (R-CH-NS) — bypass mu-plugins `bizgpt-multisite.php`.
- R-DCL-NAME · Single Canonical Table Name rule (RENAME TABLE thay INSERT...SELECT).
- Probe `class-probe-cg-flows.php` step "Runtime · interim table dropped" anti-duplicate.

### Changed
- `bizcity_kg_sources` table renamed atomically từ legacy `wp_bizgpt_custom_flows` qua RENAME TABLE.

---

## [1.3.5] — 2026-05-22

### Added
- 41 diagnostics probes (KG seeding, deep research, vector+graph, web verticals: gov/law/med/nutri/scholar/tax, fb publisher, automation, twinbrain memory hub).
- 3-layer evidence per probe (Disk · Loader · Runtime).
- `BizCity_Diagnostics_Smoke_Runner` orchestrator + REST runner.

---

## [1.3.0] — 2026-05-14

### Added
- TwinBrain Central Brain — agent runner + perspective runner + ReAct loop.
- Memory hub (REST + writer + recall + tool calls).
- Web research verticals (10 domains).

### Changed
- Refactor `core/intent/` → orchestration / classification / routing / infrastructure layers.

---

## Earlier history

Pre-1.3.x history is maintained per-phase in [docs/roadmaps/](docs/roadmaps/) (PHASE-0.x.y files).
Schema migration history in [core/diagnostics/changelog/*.json](core/diagnostics/changelog) (R-DCL).

---

## Versioning policy

- **MAJOR** (`x.0.0`) — breaking changes to public contracts (`BizCity_*_Interface`), namespace removal, hook signature break.
- **MINOR** (`1.x.0`) — new features, new modules, new hooks (additive only). Sub-plugin author không cần đổi code.
- **PATCH** (`1.0.x`) — bug fix, internal refactor, perf. Hook signature giữ nguyên.

Deprecated APIs giữ ≥ 1 minor version với `BizCity_Deprecation::notify()` warning trước khi remove.

[Unreleased]: https://github.com/bizcity/bizcity-twin-ai/compare/v1.3.7...HEAD
[1.3.7]: https://github.com/bizcity/bizcity-twin-ai/compare/v1.3.6...v1.3.7
[1.3.6]: https://github.com/bizcity/bizcity-twin-ai/compare/v1.3.5...v1.3.6
[1.3.5]: https://github.com/bizcity/bizcity-twin-ai/compare/v1.3.0...v1.3.5
[1.3.0]: https://github.com/bizcity/bizcity-twin-ai/releases/tag/v1.3.0
