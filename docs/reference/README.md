# BizCity Twin AI — API Reference

> **Phase 0.99.4 / 1.0.0** · Auto-curated reference for sub-plugin authors.
> Inspired by WooCommerce's [Hook Reference](https://woocommerce.github.io/code-reference/hooks/hooks.html).
> Updated: 2026-06-02.

Use this directory to discover **stable extension points** when building
add-on plugins for the BizCity Twin AI framework. Anything documented here
follows semver: signatures stay stable across minor versions; breaking
changes are announced via `BizCity_Deprecation::notify*()` for ≥ 1 minor
release before removal.

---

## Catalogs

| Catalog | Purpose | File |
|---|---|---|
| **Actions** | `do_action()` event hooks — side-effects only | [actions.md](actions.md) |
| **Filters** | `apply_filters()` — mutate return values / config | [filters.md](filters.md) |
| **REST API** | All public REST routes by namespace | [rest-api.md](rest-api.md) |
| **Classes** | Public classes + interfaces + abstract bases | [classes.md](classes.md) |

For a curated walkthrough of the most-used hooks (with `@since` tags +
copy-paste examples), see the legacy [docs/extension/HOOKS.md](../extension/HOOKS.md)
(maintained by hand — kept for narrative clarity).

---

## Module map

The framework `core/` is split into 21 cooperating modules. Each module
declares its public surface (hooks + REST + classes) in the catalogs above.

| Module | Domain | Headline hook |
|---|---|---|
| **agents** | Twin Agent registry + federation | `bizcity_register_agent` (filter) |
| **automation** | Visual workflow canvas (xyflow) | `bizcity_automation_channel_registry` |
| **bizcity-llm** | Gateway client wrappers (R-GW-8) | `bizcity_deprecation_notice` |
| **bizcity-market** | Plugin marketplace + entitlements | `bizcity_market_plugin_activated` |
| **channel-gateway** | Multi-channel inbox (Zalo/FB/Tele/WebChat) | `bizcity_channel_message_received` |
| **content-ops** | Multi-channel publishing + scheduler | `bizcity_content_publish` |
| **cron** | Cron jobs + per-run meta (R-CRON-META) | `BizCity_Cron_Manager::note()` |
| **diagnostics** | Probes + schema changelog + repair | `bizcity_diagnostics_register_probes` |
| **intent** | Intent classifier + tool/skill routing | `bizcity_intent_processed` |
| **knowledge** | Character + KG hub + RAG | `bizcity_chat_message_processed` |
| **memory** | User memory + reflection log | `bizcity_memory_mirror_write` |
| **persona** | Guru bridge + tool providers | `bizcity_persona_tool_providers` |
| **research** | _(reserved v2.1+)_ | — |
| **runtime** | _(deprecated → use `twin-core`)_ | — |
| **scheduler** | Calendar events + Google sync | `bizcity_scheduler_event_created` |
| **skills** | Micro-workflows + prompt templates | `bizcity_skill_trigger_pipeline` |
| **smtp** | SMTP mailer config proxy | `bizcity_smtp_config` |
| **tools** | Built-in tool library + canvas | `bizcity_canvas_handlers` |
| **twin-core** | Agent backbone (event bus, state, prompt) | `bizcity_twin_event` |
| **twinbrain** | Multi-mode ReAct agent (8 verticals) | `bizcity_twinbrain_agent_model` |

---

## How to extend (quickstart)

1. **Register your module** via `bizcity_register_module` (filter) returning an
   instance of `BizCity_Module_Interface` — see [extending/sub-plugin-quickstart.md](../extending/sub-plugin-quickstart.md).
2. **Add a tool** via `bizcity_twin_register_tool` (filter) returning an
   instance of `BizCity_Twin_Tool` — see [extending/agent-tool-recipe.md](../extending/agent-tool-recipe.md).
3. **Add a probe** via `bizcity_diagnostics_register_probes` (filter) returning
   a FQCN or instance implementing `BizCity_Diagnostics_Probe`.
4. **Add a REST route** — register under your OWN namespace (e.g.
   `myplugin/v1`); never reuse `bizcity/v1` (reserved for the LLM Router server)
   and prefer `bizcity-channel/v1` only for channel adapters (rule R-CH-NS).

---

## Conventions

| Symbol | Meaning |
|---|---|
| `bizcity_*` | Framework-owned hook prefix — stable per semver |
| `twin_*` / `bzc_*` | Legacy aliases — prefer `bizcity_*` for new code |
| `bizcity-<module>/v1` | REST namespace pattern (one per module) |
| `_degraded: true` | Gateway response when upstream unavailable (fail-OPEN) |

---

## Sources of truth

- **Hand-curated narrative:** [docs/extension/HOOKS.md](../extension/HOOKS.md) (40+ entries with examples).
- **Auto-inventory (this dir):** generated from `core/` source on 2026-06-02 via the audit subagent.
- **Re-generate:** run an exploration pass against `core/` and update each `.md` table here — or wire a generator script (see [PHASE-0.99-FRAMEWORK-V1.md §6](../roadmaps/PHASE-0.99-FRAMEWORK-V1.md#6-out-of-scope-phase-1x-trở-đi) for backlog).

---

**License:** GPL-2.0-or-later · **Author:** Johnny Chu (Chu Hoàng Anh) `<hoanganh.itm@gmail.com>`
