# Filter Reference

> Auto-curated catalog of `apply_filters()` extension points in `core/`.
> Inspired by WooCommerce's [Filter Hook Reference](https://woocommerce.github.io/code-reference/hooks/hooks.html).
>
> Each row: `hook name`, source file:line, brief purpose, default value.
> Generated: 2026-06-02. Re-run audit before each release.

For narrative usage with `@since` tags + copy-paste examples, see also the
hand-maintained [docs/extension/HOOKS.md](../extension/HOOKS.md).

---

## agents

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_twin_dev_mode` | `core/agents/bootstrap.php:37` | Toggle Twin Agent dev/debug mode | `WP_DEBUG` |
| `bizcity_register_agent` | `core/agents/class-twin-agent-registry.php:74` | Push custom `BizCity_Twin_Agent` instance into registry | `[]` |
| `bizcity_artifact_federation_stamp_enabled` | `core/agents/contracts/class-artifact-source-federation.php:65` | Enable artifact↔source stamp generation (Rule 8g) | `true` |

## automation

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_automation_map_trigger_type` | `core/automation/includes/class-automation-trigger-matcher.php:110` | Map trigger string → internal type | `''` |
| `bizcity_automation_default_reply_enabled` | `core/automation/includes/class-automation-trigger-matcher.php:357` | Toggle auto-reply when no match | `true` |
| `bizcity_automation_extract_ref_candidates` | `core/automation/includes/class-automation-trigger-matcher.php:795` | Inject reference candidates | `[]` |
| `bizcity_automation_channel_registry` | `core/automation/includes/class-automation-rest.php:954` | Override channel registry passed to canvas | `[]` |
| `bizcity_crm_event_create_filter` | `core/automation/includes/class-automation-crm-bridge.php:125` | Mutate CRM event payload pre-create | `null` |
| `bizcity_automation_external_blocks_paths` | `core/automation/includes/class-automation-admin-spa.php:118` | Add 3rd-party block JS paths | `[]` |
| `bizcity_automation_llm_compose` | `core/automation/includes/blocks/llm/class-llm-compose.php:57` | Override LLM compose result | `null` |
| `bizcity_automation_search_kg` | `core/automation/includes/blocks/actions/class-action-search-kg.php:40` | Override KG search executor | `null` |
| `bizcity_automation_db_write_whitelist` | `core/automation/includes/blocks/actions/class-action-db-write.php:47` | Whitelist tables for DB-Write block | `DEFAULT_WHITELIST` |

## bizcity-llm

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_deprecation_silent` | `core/bizcity-llm/includes/helpers-deprecation.php:140` | Silence all deprecation notices (production) | `false` |

## bizcity-market

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_market_plugin_slug` | `core/bizcity-market/includes/class-entitlements.php:73` | Override plugin slug used in entitlement lookup | `$slug` |

## channel-gateway

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_register_channel_integrations` | `core/channel-gateway/includes/class-sprint-diagnostic.php:318` | Register channel integration class list | `[]` |
| `bizcity_inbox_reply_cap` | `core/channel-gateway/includes/class-inbox-send-rest.php:56` | Required capability for inbox reply | `'edit_posts'` |
| `bizcity_webhook_router_enabled` | `core/channel-gateway/includes/class-webhook-router.php:70` | Master switch for webhook router | `(bool) option` |
| `bizcity_webhook_replay_cap` | `core/channel-gateway/includes/class-webhook-replay.php:51` | Capability to replay webhook | `'manage_options'` |
| `bizcity_webhook_log_root_dir` | `core/channel-gateway/includes/class-webhook-log.php:57` | Override webhook log storage root | `uploads/bizcity-webhook-logs` |
| `bizcity_channel_formatters` | `core/channel-gateway/includes/formatters/class-channel-formatter.php:60` | Add custom message formatter | `[]` |
| `bizcity_oauth_proxy_base` | `core/channel-gateway/includes/class-oauth-proxy.php:33` | Override BizCity OAuth proxy base URL | `self::PROXY_BASE` |
| `bizcity_channel_before_send` | `core/channel-gateway/includes/class-gateway-sender.php:54` | Mutate message just before send | `$message` |
| `bizcity_channel_envelope_before_send` | `core/channel-gateway/includes/class-gateway-sender.php:326` | Mutate normalized envelope pre-send | `$envelope` |
| `bizcity_cmd_classify_fallback` | `core/channel-gateway/includes/class-cmd-classifier.php:64` | Fallback classifier when LLM unavailable | `null` |
| `bizcity_channel_messages_auto_mirror` | `core/channel-gateway/includes/class-channel-messages.php:229` | Toggle auto-mirror of inbox messages | `true` |
| `bizcity_channel_fb_ai_compose_options` | `core/channel-gateway/includes/adapters/class-facebook-page-rest.php:755` | Override FB AI compose options | `$options` |
| `bizcity_listener_automation_emit` | `core/channel-gateway/includes/listener/class-listener-automation-bridge.php:47` | Toggle automation emission + always-emit | `$debug` |

## content-ops

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_content_publish` | `core/content-ops/includes/class-scheduler.php:180` | Override publish result for content scheduler | `$default_result` |

## diagnostics

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_diagnostics_register_tables` | `core/diagnostics/includes/class-diagnostics-table-registry.php:192` | Register module-owned tables | `[]` |
| `bizcity_diagnostics_deprecated_tables` | `core/diagnostics/includes/class-diagnostics-table-registry.php:247` | Mark deprecated tables for inventory | `[]` |
| `bizcity_diagnostics_register_probes` | `core/diagnostics/includes/class-diagnostics-smoke-runner.php:46` | Register `BizCity_Diagnostics_Probe` instances/FQCNs | `[]` |
| `bizcity_diagnostics_expected_columns` | `core/diagnostics/includes/class-diagnostics-column-inspector.php:165` | Add expected column expectations | `[]` |
| `bizcity_register_installers` | `core/diagnostics/includes/class-site-provisioner.php:64` | Register schema installers | `[]` |
| `bizcity_error_report_redact` | `core/diagnostics/includes/class-error-reporter.php:94` | Redact sensitive fields before persist | `$row` |
| `bizcity_error_fix_map` | `core/diagnostics/includes/class-error-reporter.php:150` | Map error fingerprints → fix instructions | `$map` |
| `bizcity_alert_email_to` | `core/diagnostics/includes/class-error-reporter.php:235` | Recipient for critical-error email | admin email |
| `bizcity_kg_skeleton_coverage_threshold` | `core/diagnostics/includes/probes/class-probe-skeleton-coverage.php:130` | PASS threshold % | `80.0` |
| `bizcity_kg_skeleton_failed_threshold` | `core/diagnostics/includes/probes/class-probe-skeleton-coverage.php:131` | FAIL threshold % | `20.0` |

## intent

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_intent_goal_patterns` | `core/intent/includes/routing/class-intent-router.php:2847` | Goal pattern dictionary | `$patterns` |
| `bizcity_intent_plans` | `core/intent/includes/orchestration/class-intent-planner.php:740` | Override planner plans | `$plans` |
| `bizcity_intent_mode_result` | `core/intent/includes/orchestration/class-intent-engine.php:1359` | Mutate mode-resolution result | `$mode_result` |
| `bizcity_mode_memory_patterns` | `core/intent/includes/classification/class-mode-classifier.php:142` | Memory mode pattern list | `$patterns` |
| `bizcity_intent_stream_enable_sse_endpoint` | `core/intent/includes/infrastructure/class-intent-stream.php:63` | Enable SSE endpoint | `$enable_sse` |
| `bizcity_chat_system_prompt` | `core/intent/includes/infrastructure/class-intent-stream.php:1189` | Mutate final system prompt (also used by twin-core + knowledge) | `$system_content` |
| `bizcity_chat_pre_ai_response` | `core/intent/includes/infrastructure/class-unified-rest-api.php:756` | Short-circuit pre-AI response | `null` |

## knowledge

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_kg_is_main_task` | `core/knowledge/includes/class-chat-gateway.php` | Mark task as main for KG attribution | `true` |
| `bizcity_agent_plugins_list` | `core/knowledge/includes/class-agent-rest-api.php:752` | Available agent plugins | `[]` |
| `bizcity_kg_skeleton_load_chunks` | `core/knowledge/kg-hub/includes/skeleton/class-notebook-skeleton-service.php:523` | Override skeleton chunk loader | `$chunks` |
| `bizcity_kg_skeleton_pinned_notes_limit` | `core/knowledge/kg-hub/includes/skeleton/class-notebook-skeleton-service.php:604` | Pinned notes ceiling | `10` |
| `bizcity_kg_min_passages` | `core/knowledge/kg-hub/includes/class-kg-retriever.php:162` | Minimum passages for RAG | `3` |
| `bizcity_kg_rag_include_chat_promoted` | `core/knowledge/kg-hub/includes/class-kg-retriever.php:415` | Include chat-promoted passages | `false` |
| `bizcity_kg_identity_patterns` | `core/knowledge/kg-hub/includes/class-kg-identity-extractor.php:168` | Identity extraction regex set | default |
| `bizcity_kg_v06_dual_write` | `core/knowledge/kg-hub/includes/class-kg-facade.php:667` | Dual-write legacy + unified schema | `false` |
| `bizcity_kg_unified_write_enabled` | `core/knowledge/kg-hub/includes/class-kg-facade.php:772` | Master switch unified writer | option |
| `bizcity_kg_cost_guard_enabled` | `core/knowledge/kg-hub/includes/class-kg-cost-guard.php:91` | Enable cost guard | `true` |
| `bizcity_kg_quota_per_user` | `core/knowledge/kg-hub/includes/class-kg-cost-guard.php:95` | Per-user request quota | default |
| `bizcity_kg_daily_cap_usd` | `core/knowledge/kg-hub/includes/class-kg-cost-guard.php:99` | Site-wide daily USD cap | default |
| `bizcity_kg_dedupe_cosine_threshold` | `core/knowledge/kg-hub/includes/class-kg-cost-guard.php:103` | Dedup cosine threshold | default |
| `bizcity_kg_extract_batch_size` | `core/knowledge/kg-hub/includes/class-kg-cost-guard.php:107` | Extraction batch size | default |
| `bizcity_kg_user_is_exempt` | `core/knowledge/kg-hub/includes/class-kg-cost-guard.php:128` | Exempt user from cost guard | `false` |
| `bizcity_kg_liteparse_available` | `core/knowledge/kg-hub/includes/adapters/class-liteparse-adapter.php:299` | Liteparse availability override | `null` |
| `bizcity_kg_liteparse_gemini_fallback` | `core/knowledge/kg-hub/includes/adapters/class-liteparse-adapter.php:666` | Gemini fallback enabler | `null` |
| `bizcity_av_media_url` | `core/knowledge/kg-hub/includes/adapters/class-av-adapter.php:155` | Override AV media URL | `$media_url` |
| `bizcity_pdf_ocr_enabled` | `core/knowledge/kg-hub/includes/adapters/class-pdf-adapter.php:302` | Enable PDF OCR | `true` |
| `bizcity_embed_throttle_us` | `core/knowledge/includes/lib/class-embedding.php:346` | Embedding throttle (µs) | `50000` |
| `bizcity_embed_max_per_sec` | `core/knowledge/includes/lib/class-embedding.php:355` | Embedding rate cap | `20` |
| `bizcity_knowledge_context_config` | `core/knowledge/includes/lib/class-context-api.php:141` | Context-builder config | `$config` |
| `bizcity_knowledge_context_parts` | `core/knowledge/includes/lib/class-context-api.php:261` | Mutate context parts | `$context_parts` |
| `bizcity_kg_source_adapters` | `core/knowledge/kg-hub/includes/adapters/class-adapter-registry.php:45` | Register custom source adapter | `[]` |
| `bizcity_kg_register_source_table` | `core/knowledge/kg-hub/includes/class-kg-source-registry.php:68` | Register source table | `[]` |
| `bizcity_kg_progress_log_trigger` | `core/knowledge/kg-hub/includes/class-kg-progress-log.php:314` | Custom progress trigger | `''` |
| `bizcity_kg_housekeeping_budget_s` | `core/knowledge/kg-hub/includes/filestore/class-kg-filestore-diagnostic.php:1124` | Housekeeping budget seconds | `120` |
| `bizcity_kg_housekeeping_reembed_max_steps` | `…class-kg-filestore-diagnostic.php:1125` | Re-embed max steps per run | `50` |
| `bizcity_kg_housekeeping_phases` | `…class-kg-filestore-diagnostic.php:1145` | Housekeeping phase plan | `$phases` |
| `bizcity_kg_vector_search_backend` | `core/knowledge/kg-hub/includes/class-kg-vector-file-store.php:548` | Force vector backend (faiss/hnsw) | detected |
| `bizcity_kg_vector_search_<backend>` | `…class-kg-vector-file-store.php:558` | Per-backend search override | `null` |
| `bizcity_kg_extract_time_budget_seconds` | `core/knowledge/kg-hub/includes/class-kg-triplet-extractor.php:194` | Extraction budget seconds | `75` |
| `bizcity_kg_xref_evidence_cache_ttl` | `core/knowledge/includes/tools/class-tool-search-kg.php:463` | Evidence cache TTL (seconds) | `60` |
| `bizcity_youtube_av_fallback_default_on` | `core/knowledge/kg-hub/includes/clients/class-youtube-transcriber.php:475` | YouTube AV fallback default | `true` |

## memory

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_memory_mirror_row` | `core/memory/includes/class-memory-unified-writer.php:62` | Mutate row before mirror persist | `$row` |

## persona

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_persona_tool_providers` | `core/persona/includes/class-persona-registry.php:287` | Register `BizCity_Persona_Tool_Provider`s | `[]` |
| `bizcity_register_agent` | `core/persona/includes/class-guru-skill-bridge.php:155` | Re-export agent registry to persona layer | `[]` |

## runtime *(deprecated)*

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_twin_run_rate_window` | `core/runtime/includes/class-twin-rest-controller.php:188` | Rate-limit window (seconds) | `60` |
| `bizcity_twin_run_rate_max` | `core/runtime/includes/class-twin-rest-controller.php:189` | Max calls per window | `10` |

## scheduler

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_scheduler_parse_quick` | `core/scheduler/includes/class-scheduler-rest-api.php:673` | Quick-event natural-language parser | `$parsed` |
| `bizcity_scheduler_google_account_for_event` | `core/scheduler/includes/class-scheduler-google.php:141` | Resolve Google account ID for event | `$account_id` |
| `bizcity_scheduler_google_account_context` | `core/scheduler/includes/class-scheduler-google.php:148` | Mutate Google sync context | `$context` |
| `bizcity_scheduler_event_metadata` | `core/scheduler/includes/class-scheduler-tools.php:903` | Augment event metadata | `$meta` |

## skills

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_skill_match_message` | `core/skills/includes/class-skill-context.php:196` | Mutate user message before skill matching | `$raw_user_message` |

## smtp

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_smtp_config` | `core/smtp/bootstrap.php:85` | Override SMTP config dict | `$cfg` |

## tools

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_canvas_handlers` | `core/tools/includes/class-canvas-adapter.php:240` | Register canvas output handler | `[]` |

## twin-core

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_twin_debug` | `core/twin-core/includes/class-twin-debug.php:63` | Twin debug toggle | `$on` |
| `bizcity_twin_register_tool` | `core/twin-core/includes/class-twin-tool-registry.php:58` | Register `BizCity_Twin_Tool` for function-calling | `[]` |
| `bizcity_twin_agent_force_search_kg` | `core/twin-core/includes/class-twin-agent-loop.php:202` | Force KG search step in agent loop | `true` |
| `bizcity_twin_agent_numeric_min_len` | `core/twin-core/includes/class-twin-agent-loop.php:636` | Min response length to skip numeric retry | `200` |
| `bizcity_chat_system_prompt` | `core/twin-core/includes/class-twin-context-resolver.php:176` | Shared with intent + knowledge — mutate prompt | `$system_content` |
| `bizcity_twin_context_resolver_result` | `core/twin-core/includes/class-twin-context-resolver.php:312` | Override resolver result | `$result` |
| `bizcity_twin_focus_profile` | `core/twin-core/includes/class-focus-router.php:149` | Override focus profile | `$profile` |
| `bizcity_twin_event_projectors` | `core/twin-core/event-stream/class-twin-event-bus.php:575` | Register event projector callable | `[]` |

## twinbrain

| Hook | File:line | Purpose | Default |
|---|---|---|---|
| `bizcity_twinbrain_tax_allowlist` | `core/twinbrain/includes/class-twinbrain-web-tax.php:113` | Tax-vertical domain allowlist | `$base` |
| `bizcity_twinbrain_web_tax_model` | `…class-twinbrain-web-tax.php:165` | Tax vertical LLM model | gateway default |
| `bizcity_twinbrain_web_social_model` | `…class-twinbrain-web-social.php:383` | Social vertical model | gateway default |
| `bizcity_twinbrain_scholar_allowlist` | `…class-twinbrain-web-scholar.php:128` | Scholar allowlist | `$base` |
| `bizcity_twinbrain_web_scholar_model` | `…class-twinbrain-web-scholar.php:180` | Scholar model | gateway default |
| `bizcity_twinbrain_web_quick_model` | `…class-twinbrain-web-quick.php:320` | Quick mode model | gateway default |
| `bizcity_twinbrain_nutri_allowlist` | `…class-twinbrain-web-nutri.php:114` | Nutrition allowlist | `$base` |
| `bizcity_twinbrain_web_nutri_model` | `…class-twinbrain-web-nutri.php:166` | Nutrition model | gateway default |
| `bizcity_twinbrain_med_allowlist` | `…class-twinbrain-web-med.php:305` | Medical allowlist | `$base` |
| `bizcity_twinbrain_web_med_model` | `…class-twinbrain-web-med.php:487` | Medical model | gateway default |
| `bizcity_twinbrain_law_allowlist` | `…class-twinbrain-web-law.php:115` | Legal allowlist | `$base` |
| `bizcity_twinbrain_web_law_model` | `…class-twinbrain-web-law.php:167` | Legal model | gateway default |
| `bizcity_twinbrain_gov_local_provinces_enabled` | `…class-twinbrain-web-gov.php:112` | Enable local-province sources | `false` |
| `bizcity_twinbrain_gov_allowlist` | `…class-twinbrain-web-gov.php:119` | Gov allowlist | `$base` |
| `bizcity_twinbrain_web_gov_model` | `…class-twinbrain-web-gov.php:172` | Gov model | gateway default |
| `bizcity_twinbrain_web_deep_model` | `…class-twinbrain-web-deep.php:400` | Web-Deep mode model | gateway default |
| `bizcity_twinbrain_web_company_model` | `…class-twinbrain-web-company.php:506` | Company mode model | gateway default |
| `bizcity_twinbrain_memory_writer_enable_llm` | `…class-twinbrain-memory-writer.php:248` | Enable LLM-assisted memory write | `true` |
| `bizcity_twinbrain_memory_writer_llm_purpose` | `…class-twinbrain-memory-writer.php:289` | Memory writer LLM purpose | `'fast'` |
| `bizcity_twinbrain_perspectives_skip_llm` | `…class-twinbrain-perspective-runner.php:79` | Skip LLM in perspective synth | `true` |
| `bizcity_twinbrain_sub_agent_model` | `…class-twinbrain-perspective-runner.php:637` | Sub-agent model | gateway default |
| `bizcity_twinbrain_agent_model` | `…class-twinbrain-agent-runner.php:508` | Main agent model | gateway default |

---

**See also:** [actions.md](actions.md) · [rest-api.md](rest-api.md) · [classes.md](classes.md)
