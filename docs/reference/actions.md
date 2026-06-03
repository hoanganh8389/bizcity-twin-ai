# Action Reference

> Auto-curated catalog of `do_action()` event hooks in `core/`.
> Inspired by WooCommerce's [Action Hook Reference](https://woocommerce.github.io/code-reference/hooks/hooks.html).
>
> Each row: hook name, source file:line, action arguments. Generated 2026-06-02.

Actions are side-effect only — return value is ignored. Use these to attach
observers, audit loggers, or downstream integrations without modifying the
framework's own data flow.

For curated narrative + `@since` tags, see [docs/extension/HOOKS.md](../extension/HOOKS.md).

---

## agents

| Hook | File:line | Args |
|---|---|---|
| `bizcity_artifact_federation_stamped` | `core/agents/contracts/class-artifact-source-federation.php:103` | `$plugin_name, $artifact_id, $notebook_id, $title, $edit_url` |

## bizcity-llm

| Hook | File:line | Args |
|---|---|---|
| `bizcity_deprecation_notice` | `core/bizcity-llm/includes/helpers-deprecation.php:152` | `$payload` (kind, old, new, since, reason, caller) |

## bizcity-market

| Hook | File:line | Args |
|---|---|---|
| `bizcity_market_plugin_activated` | `core/bizcity-market/includes/class-marketplace.php:246` | `$slug, $plugin_file, (int) $blog_id` |
| `bizcity_market_plugin_deactivated` | `core/bizcity-market/includes/class-marketplace.php:328` | `$slug, $plugin_file, (int) $blog_id` |

## channel-gateway

| Hook | File:line | Args |
|---|---|---|
| `bizcity_register_channel` | `core/channel-gateway/bootstrap.php:348` | `$bridge` (Integration registry) |
| `bizcity_register_integrations` | `core/channel-gateway/includes/class-integration-registry.php:96` | — |
| `bizcity_listener_event_pre` | `core/channel-gateway/includes/listener/class-listener-bus.php:337` | `$event` (pre-normalization) |
| `bizcity_listener_event_emitted` | `core/channel-gateway/includes/listener/class-listener-bus.php:373` | `$normalized` |
| `bizcity_channel_normalized` | `core/channel-gateway/includes/class-universal-channel-listener.php:210` | `$envelope, $trigger_key` |
| `bizcity_channel_message_received` | `core/channel-gateway/includes/class-gateway-bridge.php:212` | `$payload` |
| `bizcity_channel_registered` | `core/channel-gateway/includes/class-gateway-bridge.php:351` | `$channel, $platform` |
| `bizcity_channel_verify_failed` | `core/channel-gateway/includes/class-gateway-bridge.php:180` | `$request, $platform` |
| `bizcity_channel_after_send` | `core/channel-gateway/includes/class-gateway-sender.php:76` | `$result, $chat_id, $platform` |
| `bizcity_channel_outbound_logged` | `core/channel-gateway/includes/class-gateway-sender.php:78` | `array(payload, channel, …)` |
| `bizcity_network_oauth_saved` | `core/channel-gateway/includes/class-network-oauth-page.php:158` | `self::get_all_globals()` |
| `bizcity_zalo_reminder_sent` | `core/channel-gateway/includes/class-zalo-reminder.php:184` | `$event_id, $zalo_user_id, $meta['zalo_message_id']` |
| `bizcity_webhook_router_intake` | `core/channel-gateway/includes/class-webhook-router.php:135` | `$log, $platform, $body` |
| `bizcity_channel_replay` | `core/channel-gateway/includes/class-webhook-replay.php:167` | `$row, $log, $parent` |
| `bizcity_web_post_publish_start` | `core/channel-gateway/includes/class-web-post-publisher.php:140` | `$event_id, $event` |
| `bizcity_web_post_published` | `core/channel-gateway/includes/class-web-post-publisher.php:189` | `$event_id, $post_id, $permalink` |
| `bizcity_web_post_failed` | `core/channel-gateway/includes/class-web-post-publisher.php:269` | `$event_id, $error` |
| `bizcity_fb_post_publish_start` | `core/channel-gateway/includes/class-fb-publisher.php:120` | `$event_id, $event` |
| `bizcity_fb_post_published` | `core/channel-gateway/includes/class-fb-publisher.php:173` | `$event_id, $meta['fb_post_id'], $meta['fb_permalink']` |
| `bizcity_fb_post_failed` | `core/channel-gateway/includes/class-fb-publisher.php:271` | `$event_id, $error` |
| `bizcity_webchat_push_message` | `core/channel-gateway/includes/adapters/class-webchat-adapter.php:103` | `$session_id, $message, $options` |
| `bizcity_listener_emit` | `core/channel-gateway/includes/adapters/class-facebook-page-rest.php:990` | `array(payload …)` |

## diagnostics

| Hook | File:line | Args |
|---|---|---|
| `bizcity_diagnostics_notice` | `core/diagnostics/includes/trait-rest-error.php:131` | `$module, [...]` |
| `bizcity_error_recorded` | `core/diagnostics/includes/class-error-reporter.php:109` | `$row` |
| `bizcity_critical_error` | `core/diagnostics/includes/class-error-reporter.php:117` | `$row` |

## intent

| Hook | File:line | Args |
|---|---|---|
| `bizcity_intent_processed` | `core/intent/includes/orchestration/class-intent-engine.php:310+` | `$result, $params` |
| `bizcity_skill_trigger_pipeline` | `core/intent/includes/orchestration/class-intent-engine.php:801` | `$skill, [$args]` |
| `bizcity_pipeline_created` | `core/intent/includes/orchestration/class-scenario-generator.php:55` | `$task_id, $trigger_context, $scenario` |
| `bizcity_pipeline_completed` | `core/intent/includes/orchestration/class-step-executor.php:425` | `(int) $task_id, $state` |
| `bizcity_pipeline_node_event` | `core/intent/includes/orchestration/class-step-executor.php:309+` | `[node_id, type, status, …]` |
| `bizcity_intent_pipeline_log` | `core/intent/includes/workflow/class-pipeline-middleware.php:84` | `$step, [...], $level, $elapsed` |
| `bizcity_tool_execution_completed` | `core/intent/includes/tools/class-tool-run.php:247` | `[tool_id, result, …]` |
| `bizcity_tool_registry_changed` | `core/intent/includes/tools/class-intent-tool-index.php:516+` | `$action, $provider_id, $active_keys` |
| `bizcity_intent_tools_ready` | `core/intent/includes/providers/class-intent-provider-registry.php:198` | `$tools` |
| `bizcity_intent_tool_*` | `core/intent/includes/tools/class-intent-tools.php:677+` | per-tool actions: `create_product`, `write_article`, `set_reminder`, … |

## knowledge

| Hook | File:line | Args |
|---|---|---|
| `bizcity_chat_message_processed` | `core/knowledge/includes/class-chat-gateway.php:481` | `[user_id, character_id, …]` |
| `bizcity_channel_outbound_logged` | `core/knowledge/includes/class-chat-gateway.php:536` | `[log_entry]` (shared with channel-gateway) |
| `bizcity_webchat_message_saved` | `core/knowledge/includes/class-chat-gateway.php:3123` | `[message_data]` |
| `bizcity_knowledge_character_response` | `core/knowledge/includes/bootstrap.php:324` | `[response_data]` |
| `bizcity_knowledge_character_saved` | `core/knowledge/includes/class-admin-menu.php:3046` | `(int) $id, (array) $data` |
| `bizcity_knowledge_ingested` | `core/knowledge/lib/class-knowledge-fabric.php:186` | `$result, $params` |
| `bizcity_kg_legacy_chunks_persisted` | `core/knowledge/lib/class-embedding.php:764` | `[chunk_data]` |
| `bizcity_kg_notebook_skeleton_marked_dirty` | `core/knowledge/kg-hub/includes/class-notebook-skeleton-service.php:160` | `$notebook_id` |
| `bizcity_kg_notebook_skeleton_built` | `core/knowledge/kg-hub/includes/class-notebook-skeleton-service.php:581` | `$notebook_id, [stats]` |
| `bizcity_kg_extraction_passage_error` | `core/knowledge/kg-hub/includes/class-kg-triplet-extractor.php:101` | `[error_data]` |
| `bizcity_kg_extraction_passage_done` | `core/knowledge/kg-hub/includes/class-kg-triplet-extractor.php:164` | `[passage_data]` |
| `bizcity_kg_extraction_batch_done` | `core/knowledge/kg-hub/includes/class-kg-triplet-extractor.php:361` | `[batch_stats]` |
| `bizcity_kg_bin_missing` | `core/knowledge/kg-hub/includes/class-kg-retriever.php:425+` | `'notebooks'|'gurus', $uuid, $hdr` |
| `bizcity_kg_identity_report_built` | `core/twin-core/includes/tools/class-tool-search-kg.php:419` | `$scope_id, $query, $identity_report` |
| `bizcity_twin_notebook_event` | `core/knowledge/kg-hub/includes/class-kg-source-service.php:488` | `'note_created'|'note_updated'|'note_tagged', [data]` |
| `bizcity_kg_notebook_before_delete` | `core/knowledge/kg-hub/includes/class-kg-notebook-service.php:163` | `$id` |
| `bizcity_kg_notebook_deleted` | `core/knowledge/kg-hub/includes/class-kg-notebook-service.php:192` | `$id` |
| `bizcity_kg_notebook_stats_dirty` | `core/knowledge/kg-hub/includes/class-kg-graph-service.php:50+` | `$notebook_id` |
| `bizcity_kg_graph_updated` | `core/knowledge/kg-hub/includes/class-kg-graph-service.php:423` | `$notebook_id, [stats]` |
| `bizcity_kg_identity_backfill_batch_done` | `core/knowledge/kg-hub/includes/class-kg-identity-backfill.php:169` | `$stats, $args` |
| `bizcity_kg_after_ingest_central` | `core/knowledge/kg-hub/includes/class-kg-facade.php:708` | `$kg_source_id, $scope, $payload, $kg_uuid` |
| `bizcity_kg_cost_alert_80` | `core/knowledge/kg-hub/includes/class-kg-cost-guard.php:312` | `$spent, $cap` |
| `bizcity_kg_legacy_cols_dropped` | `core/knowledge/kg-hub/includes/class-kg-bin-diagnostic.php:1879` | `$tbl, $drops` |
| `bizcity_after_handle_guest_flows` | `core/knowledge/includes/functions.php:519` | `$msgs, $platform, $question` |
| `bizcity_diagnostics_notice` | `core/knowledge/kg-hub/includes/filestore/class-kg-filestore-diagnostic.php:1191` | `'kg_housekeeping', $summary` |

## memory

| Hook | File:line | Args |
|---|---|---|
| `bizcity_memory_mirror_write` | `core/memory/includes/class-memory-unified-writer.php` | `$class, $row, $result` |

## scheduler

| Hook | File:line | Args |
|---|---|---|
| `bizcity_scheduler_reminder_fire` | `core/scheduler/includes/class-scheduler-rest-api.php:421` | `$event` |
| `bizcity_scheduler_event_created` | `core/scheduler/includes/class-scheduler-manager.php:366` | `$event, $data` |
| `bizcity_scheduler_event_updated` | `core/scheduler/includes/class-scheduler-manager.php:421` | `$event, $old, array_keys($update)` |
| `bizcity_scheduler_event_deleted` | `core/scheduler/includes/class-scheduler-manager.php:459` | `$event` |
| `bizcity_scheduler_google_error` | `core/scheduler/includes/class-scheduler-google.php:561+` | `'token_refresh'|'oauth_callback'|…, $msg, (int) $event_id, $context` |
| `bizcity_scheduler_google_synced` | `core/scheduler/includes/class-scheduler-google.php:711+` | `'push'|'patch'|'pull', $event_id, $google_event_id, $context` |
| `bizcity_scheduler_automation_chain_done` | `core/scheduler/includes/class-scheduler-automation.php:149` | `$event, $results, $counters` |

## skills

| Hook | File:line | Args |
|---|---|---|
| `bizcity_skill_saved` | `core/skills/includes/class-skill-rest-api.php:1286` | `(int) $skill_id, $store_content, $fm['title']` |
| `bizcity_skill_trigger_pipeline` | `core/skills/includes/class-skill-pipeline-bridge.php:811` | `$top_c, $args` |

## twin-core

| Hook | File:line | Args |
|---|---|---|
| `bizcity_twin_event` | `core/twin-core/event-stream/class-twin-event-bus.php:73` | `$event_key, $payload` |
| `bizcity_twin_event_<key>` | `core/twin-core/event-stream/class-twin-event-bus.php:108` | `$payload` (typed variant per event key) |
| `bizcity_twin_event_v2` | `core/twin-core/event-stream/class-twin-event-bus.php:432` | `$event` (v2 envelope) |
| `bizcity_intent_pipeline_log` | `core/twin-core/includes/class-twin-trace.php:74` | `$step, $data, $level, $elapsed` |
| `bizcity_twin_agent_chunk_emitted` | `core/twin-core/includes/class-twin-agent-loop.php:836` | `$accum, 'content'` |
| `bizcity_system_prompt_built` | `core/twin-core/includes/class-twin-context-resolver.php:189` | `$system_content, $filter_args, $bundle` |

---

**See also:** [filters.md](filters.md) · [rest-api.md](rest-api.md) · [classes.md](classes.md)
