# Public Class Reference

> Auto-curated catalog of public classes, interfaces, and abstract bases in
> `core/`. Sub-plugin authors can subclass / implement / inject these.
> Generated 2026-06-02.

Classes prefixed with `BizCity_` are framework-owned. Anything marked
**interface** or **abstract** is an explicit extension point — implementing
or subclassing them is the recommended way to register new behaviour.

---

## agents

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Twin_Agent_Registry` | `core/agents/class-twin-agent-registry.php` | Agent registry resolver; consumes `bizcity_register_agent` filter |
| class | `BizCity_Twin_Tool` | `core/agents/class-twin-tool.php` | Built-in Twin Agent tool base |
| **interface** | `BizCity_Artifact_Source_Federation` | `core/agents/contracts/class-artifact-source-federation.php` | Artifact ↔ source federation contract (Rule 8g) |

## automation

| Type | Name | File | Purpose |
|---|---|---|---|
| **abstract** | `BizCity_Block` | `core/automation/includes/blocks/abstract-block.php` | Base class for automation block nodes |
| class | `BizCity_Automation_Repo_Workflows` | `core/automation/includes/class-automation-repo-workflows.php` | Workflow persistence layer |
| class | `BizCity_Automation_Admin_SPA` | `core/automation/includes/class-automation-admin-spa.php` | Canvas admin UI + block registry |

## bizcity-llm

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_LLM_Client` | `core/bizcity-llm/includes/class-llm-client.php` | Server-side gateway client (Bearer auth, fail-graceful) — **the** wrapper for upstream LLM calls (R-GW-8) |
| class | `BizCity_Search_Client` | `core/bizcity-llm/includes/class-search-client.php` | Reranking + search wrapper |
| class | `BizCity_Google_Hub` | `core/bizcity-llm/includes/class-google-hub.php` | Google OAuth hub client |
| class | `BizCity_LLM_Usage_Log` | `core/bizcity-llm/includes/class-llm-usage-log.php` | Usage metering + billing |
| class | `BizCity_LLM_Models` | `core/bizcity-llm/includes/class-llm-models.php` | Model registry |
| class | `BizCity_LLM_Settings` | `core/bizcity-llm/includes/class-llm-settings.php` | API key configuration |

## bizcity-market

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Market_Marketplace` | `core/bizcity-market/includes/class-marketplace.php` | Plugin activation/deactivation orchestrator |
| class | `BizCity_Market_Entitlements` | `core/bizcity-market/includes/class-entitlements.php` | Entitlement lookup + verification |
| class | `BizCity_Market_Catalog` | `core/bizcity-market/includes/class-catalog.php` | Remote plugin catalog cache |
| class | `BizCity_Market_Site_Apps` | `core/bizcity-market/includes/class-site-apps.php` | Per-site app provisioning |
| class | `BizCity_Market_Admin` | `core/bizcity-market/includes/class-admin.php` | Marketplace admin page |
| class | `BizCity_Plugin_Installer` | `core/bizcity-market/includes/class-plugin-installer.php` | Auto-installer with rollback |

## channel-gateway

| Type | Name | File | Purpose |
|---|---|---|---|
| **abstract** | `BizCity_Channel_Adapter_Base` | `core/channel-gateway/includes/class-channel-adapter-base.php` | Adapter base (send/receive contract) |
| **interface** | `BizCity_Channel_Adapter` | `core/channel-gateway/includes/interface-channel-adapter.php` | Channel adapter contract |
| **abstract** | `BizCity_Channel_Integration` | `core/channel-gateway/includes/class-channel-integration.php` | Integration registry binding |
| **abstract** | `BizCity_Integration` | `core/channel-gateway/includes/class-integration.php` | Base integration (extensible parent) |
| class | `BizCity_Gateway_Bridge` | `core/channel-gateway/includes/class-gateway-bridge.php` | Webhook → normalized envelope router |
| class | `BizCity_Gateway_Sender` | `core/channel-gateway/includes/class-gateway-sender.php` | Message send orchestrator |
| class | `BizCity_Listener_Bus` | `core/channel-gateway/includes/listener/class-listener-bus.php` | Event bus for listener feed/stream |
| class | `BizCity_Universal_Channel_Listener` | `core/channel-gateway/includes/class-universal-channel-listener.php` | Normalized webhook ingestion |
| class | `BizCity_Channel_REST_API` | `core/channel-gateway/includes/class-channel-rest-api.php` | Main REST controller |
| class | `BizCity_Facebook_Page_REST` | `core/channel-gateway/includes/adapters/class-facebook-page-rest.php` | Facebook Pages API proxy |
| class | `BizCity_WebChat_Adapter` | `core/channel-gateway/includes/adapters/class-webchat-adapter.php` | WebChat push adapter |
| class | `BizCity_Telegram_Adapter` | `core/channel-gateway/includes/adapters/class-telegram-adapter.php` | Telegram adapter |
| class | `BizCity_Zalo_Hotline_Adapter` | `core/channel-gateway/includes/adapters/class-zalo-hotline-adapter.php` | Zalo Hotline adapter |

## content-ops

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Content_REST_API` | `core/content-ops/includes/class-rest-api.php` | Content REST controller |
| class | `BizCity_Content_Scheduler` | `core/content-ops/includes/class-scheduler.php` | Scheduler + publish orchestration |
| class | `BizCity_Content_Post_Repo` | `core/content-ops/includes/class-post-repo.php` | Post persistence |
| class | `BizCity_Content_Asset_Repo` | `core/content-ops/includes/class-asset-repo.php` | Asset repo |
| class | `BizCity_Content_CPT_Bridge` | `core/content-ops/includes/class-cpt-bridge.php` | Custom post type bridge |

## cron

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Cron_Manager` | `core/cron/includes/class-cron-manager.php` | Cron job registration + meta writer (`note()` / `note_event()` — R-CRON-META) |
| class | `BizCity_Cron_REST` | `core/cron/includes/class-cron-rest.php` | REST API for job control |
| class | `BizCity_Cron_MCP` | `core/cron/includes/class-cron-mcp.php` | MCP server for CLI |
| class | `BizCity_Cron_Admin_Page` | `core/cron/includes/class-cron-admin-page.php` | Cron admin UI |

## diagnostics

| Type | Name | File | Purpose |
|---|---|---|---|
| **interface** | `BizCity_Diagnostics_Probe` | `core/diagnostics/includes/probes/interface-probe.php` | Probe contract (id, label, run, repair, …) |
| class | `BizCity_Diagnostics_Smoke_Runner` | `core/diagnostics/includes/class-diagnostics-smoke-runner.php` | Probe orchestrator |
| class | `BizCity_Diagnostics_REST` | `core/diagnostics/includes/class-diagnostics-rest.php` | Diagnostics REST API |
| class | `BizCity_Site_Provisioner` | `core/diagnostics/includes/class-site-provisioner.php` | Installer registry + auto-create |
| class | `BizCity_Error_Reporter` | `core/diagnostics/includes/class-error-reporter.php` | Error capture + alerting |

## intent

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Intent_Router` | `core/intent/includes/routing/class-intent-router.php` | Top-level intent classifier + router |
| class | `BizCity_Intent_Engine` | `core/intent/includes/orchestration/class-intent-engine.php` | Mode selection + orchestration |
| class | `BizCity_Intent_Provider_Registry` | `core/intent/includes/providers/class-intent-provider-registry.php` | Provider registry (tool/skill sources) |
| **abstract** | `BizCity_Intent_Provider` | `core/intent/includes/providers/class-intent-provider.php` | Base provider contract |
| class | `BizCity_Intent_Tool_Index` | `core/intent/includes/tools/class-intent-tool-index.php` | Tool registry + activation |
| class | `BizCity_Intent_Tools` | `core/intent/includes/tools/class-intent-tools.php` | Built-in tool handlers |
| class | `BizCity_Unified_REST_API` | `core/intent/includes/infrastructure/class-unified-rest-api.php` | Main unified REST controller |
| class | `BizCity_Mode_Classifier` | `core/intent/includes/classification/class-mode-classifier.php` | Mode pattern matcher |

## knowledge

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Chat_Gateway` | `core/knowledge/includes/class-chat-gateway.php` | Chat orchestrator + RAG query handler |
| class | `BizCity_Chat_REST_API` | `core/knowledge/includes/class-chat-rest-api.php` | Chat REST controller |
| class | `BizCity_Agent_REST_API` | `core/knowledge/includes/class-agent-rest-api.php` | Agent query interface |
| class | `BizCity_API` | `core/knowledge/includes/class-api.php` | Character CRUD + search |
| class | `BizCity_Skill_REST_API` | `core/knowledge/includes/class-skill-rest-api.php` | Skill CRUD + test |
| class | `BizCity_Knowledge_Fabric` | `core/knowledge/lib/class-knowledge-fabric.php` | Knowledge ingestion orchestrator |
| class | `BizCity_Context_API` | `core/knowledge/lib/class-context-api.php` | Context builder for RAG |
| class | `BizCity_KG_Facade` | `core/knowledge/kg-hub/includes/class-kg-facade.php` | KG hub unified interface |
| class | `BizCity_KG_Retriever` | `core/knowledge/kg-hub/includes/class-kg-retriever.php` | Vector search + passage ranking |
| class | `BizCity_KG_Source_Service` | `core/knowledge/kg-hub/includes/class-kg-source-service.php` | Source CRUD + notebook linking |
| class | `BizCity_KG_Cost_Guard` | `core/knowledge/kg-hub/includes/class-kg-cost-guard.php` | Cost quota + dedup |

## memory

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Memory_Manager` | `core/memory/includes/class-memory-manager.php` | Memory orchestrator |
| class | `BizCity_Memory_Unified_Writer` | `core/memory/includes/class-memory-unified-writer.php` | Unified memory mirror writer |
| class | `BizCity_Memory_REST_API` | `core/memory/includes/class-memory-rest-api.php` | Memory REST CRUD |
| class | `BizCity_Memory_Log` | `core/memory/includes/class-memory-log.php` | Memory event log |
| class | `BizCity_Memory_Database` | `core/memory/includes/class-memory-database.php` | Memory table schema |

## persona

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Persona_Registry` | `core/persona/includes/class-persona-registry.php` | Guru tool provider registry |
| **abstract** | `BizCity_Persona_Tool_Provider` | `core/persona/includes/class-persona-tool-provider.php` | Tool provider contract |
| class | `BizCity_Guru_Skill_Bridge` | `core/persona/includes/class-guru-skill-bridge.php` | Guru → Skill adapter |
| class | `BizCity_Guru_Provider_Bridge` | `core/persona/includes/class-guru-provider-bridge.php` | Guru → Provider adapter |
| class | `BizCity_Guru_Token_Parser` | `core/persona/includes/class-guru-token-parser.php` | Token-level prompt engineering |
| class | `BizCity_Twin_Guru_Context` | `core/persona/includes/class-twin-guru-context.php` | Context resolver for guru queries |
| class | `BizCity_Guru_Bridge_REST` | `core/persona/includes/class-guru-bridge-rest.php` | Guru REST adapter |

## research

_Reserved for v2.1+ — no public classes yet._

## runtime *(deprecated)*

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Twin_REST_Controller` | `core/runtime/includes/class-twin-rest-controller.php` | _Deprecated — use `twin-core` + `intent` + `twinbrain`._ |

## scheduler

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Scheduler_Manager` | `core/scheduler/includes/class-scheduler-manager.php` | Event CRUD orchestrator |
| class | `BizCity_Scheduler_REST_API` | `core/scheduler/includes/class-scheduler-rest-api.php` | Scheduler REST controller |
| class | `BizCity_Scheduler_Google` | `core/scheduler/includes/class-scheduler-google.php` | Google Calendar sync bridge |
| class | `BizCity_Scheduler_Tools` | `core/scheduler/includes/class-scheduler-tools.php` | Scheduler helper tools |
| class | `BizCity_Scheduler_Automation` | `core/scheduler/includes/class-scheduler-automation.php` | Reminder → automation chain trigger |
| class | `BizCity_Scheduler_Cron` | `core/scheduler/includes/class-scheduler-cron.php` | Cron-based reminder executor |

## skills

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Skill_Tool_Map` | `core/skills/includes/class-skill-tool-map.php` | Skill → tool mapper |
| class | `BizCity_Skill_Context` | `core/skills/includes/class-skill-context.php` | Skill context injector + matcher |
| class | `BizCity_Skill_Pipeline_Bridge` | `core/skills/includes/class-skill-pipeline-bridge.php` | Skill → pipeline trigger |
| class | `BizCity_Skill_REST_API` | `core/skills/includes/class-skill-rest-api.php` | Skill CRUD REST |

## smtp

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_SMTP_*` | `core/smtp/includes/` | SMTP mailer + template registry |

## tools

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Canvas_Adapter` | `core/tools/includes/class-canvas-adapter.php` | Canvas output handler registry |
| class | `BizCity_Tool_Wrapper` | `core/tools/includes/tools/class-tool-wrapper.php` | Tool execution wrapper |
| class | `BizCity_Tool_Registry_Map` | `core/tools/includes/tools/class-tool-registry-map.php` | Tool registry mapper |
| class | `BizCity_Tool_Evidence` | `core/tools/includes/tools/class-tool-evidence.php` | Tool execution evidence logger |
| class | `BizCity_Tool_IO_Mapper` | `core/tools/includes/tools/class-tool-io-mapper.php` | Input/output schema mapper |
| class | `BizCity_Tool_Control_Panel` | `core/tools/includes/tools/class-tool-control-panel.php` | Tool admin UI |

## twin-core

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_Twin_Data_Contract` | `core/twin-core/includes/class-twin-data-contract.php` | Event type constants (EVT_* taxonomy) |
| class | `BizCity_Twin_State_Schema` | `core/twin-core/includes/class-twin-state-schema.php` | Agent state schema validator |
| class | `BizCity_Twin_Event_Bus` | `core/twin-core/event-stream/class-twin-event-bus.php` | Event dispatcher + projector registry |
| class | `BizCity_Twin_Event_Taxonomy` | `core/twin-core/event-stream/class-twin-event-taxonomy.php` | Event taxonomy validator |
| class | `BizCity_Twin_Context_Resolver` | `core/twin-core/includes/class-twin-context-resolver.php` | Context builder for agent reasoning |
| class | `BizCity_Focus_Router` | `core/twin-core/includes/class-focus-router.php` | Focus profile router |
| class | `BizCity_Focus_Gate` | `core/twin-core/includes/class-focus-gate.php` | Focus gate (output filter) |
| class | `BizCity_Twin_Prompt_Parser` | `core/twin-core/includes/class-twin-prompt-parser.php` | Prompt + directive parser |
| class | `BizCity_Twin_Agent` | `core/twin-core/includes/class-twin-agent-loop.php` | Main agent loop (function-calling) |
| **interface** | `BizCity_Twin_Tool` | `core/twin-core/includes/interface-twin-tool.php` | Tool invocation contract |
| class | `BizCity_Twin_Trace` | `core/twin-core/includes/class-twin-trace.php` | Execution trace logger |
| class | `BizCity_Twin_Tool_Registry` | `core/twin-core/includes/class-twin-tool-registry.php` | Tool registry broker |
| class | `BizCity_Twin_SSE_Writer` | `core/twin-core/includes/class-twin-sse-writer.php` | SSE streaming writer |
| class | `BizCity_Twin_Suggest` | `core/twin-core/includes/class-twin-suggest.php` | Suggestion generator |
| class | `BizCity_Twin_Citation_Validator` | `core/twin-core/includes/class-twin-citation-validator.php` | Citation format validator |
| class | `BizCity_Twin_Citation_Id_Generator` | `core/twin-core/includes/class-twin-citation-id-generator.php` | Citation ID generator |

## twinbrain

| Type | Name | File | Purpose |
|---|---|---|---|
| class | `BizCity_TwinBrain_Runtime` | `core/twinbrain/includes/class-twinbrain-runtime.php` | Runtime orchestrator (mode dispatch) |
| class | `BizCity_TwinBrain_REST` | `core/twinbrain/includes/class-twinbrain-rest.php` | REST turn/stream controller |
| class | `BizCity_TwinBrain_REST_Memory_Me` | `core/twinbrain/includes/class-twinbrain-rest-memory-me.php` | Memory REST endpoints |
| class | `BizCity_TwinBrain_Agent_Runner` | `core/twinbrain/includes/class-twinbrain-agent-runner.php` | Main agent mode runner |
| class | `BizCity_TwinBrain_Perspective_Runner` | `core/twinbrain/includes/class-twinbrain-perspective-runner.php` | Multi-perspective aggregator |
| class | `BizCity_TwinBrain_Final_Composer` | `core/twinbrain/includes/class-twinbrain-final-composer.php` | Final response composer |
| class | `BizCity_TwinBrain_Memory_Writer` | `core/twinbrain/includes/class-twinbrain-memory-writer.php` | Memory persistence bridge |
| class | `BizCity_TwinBrain_Citation_Resolver` | `core/twinbrain/includes/class-twinbrain-citation-resolver.php` | Citation URL resolver |
| class | `BizCity_TwinBrain_Guru_Web_Flag` | `core/twinbrain/includes/class-twinbrain-guru-web-flag.php` | Guru web fallback toggle |
| class | `BizCity_TwinBrain_Synthesizer` | `core/twinbrain/includes/class-twinbrain-synthesizer.php` | Response synthesizer |
| class | `BizCity_TwinBrain_Schema` | `core/twinbrain/includes/class-twinbrain-schema.php` | TwinBrain schema contract |
| class | `BizCity_TwinBrain_Notebook_Selector` | `core/twinbrain/includes/class-twinbrain-notebook-selector.php` | Notebook auto-selector |

---

**Totals:** 21 modules · 150+ public types · 8 interfaces · 7 abstract bases.

**See also:** [README.md](README.md) · [filters.md](filters.md) · [actions.md](actions.md) · [rest-api.md](rest-api.md)
