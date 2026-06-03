# BizCity Twin AI — Public Hooks Catalog

> **Phase 0.99.4** · Public extension points (filter & action) — sub-plugin
> authors có thể rely on stability theo semver (`@since` tag) qua các framework
> version. Hook KHÔNG có trong file này = **internal**, có thể thay đổi mà
> không cảnh báo.
>
> **Quy ước:**
> - `apply_filters( 'bizcity_*', $value, …context )` → filter (return mutated value).
> - `do_action( 'bizcity_*', …args )` → action (side-effect only).
> - `@since X.Y.Z` = framework version đầu tiên expose hook này. Nếu hook
>   được rename, dùng `BizCity_Deprecation::notify_filter()` để giữ BC ≥ 1 minor.
>
> **Khi đổi signature:** bump major framework version + thêm row vào
> [CHANGELOG.md](../../CHANGELOG.md) `### Changed`.

---

## 1. Module / Bootstrap

### `bizcity_register_module` *(filter — registry pull)*
- **Since:** `1.0.0`
- **Signature:** `array $modules = apply_filters( 'bizcity_register_module', [] );`
- **Purpose:** Sub-plugin push instance `BizCity_Module_Interface` vào framework loader.
- **Example:**
  ```php
  add_filter( 'bizcity_register_module', function( array $list ) {
      $list[] = new My_Custom_Module();
      return $list;
  } );
  ```

### `bizcity_register_installers` *(filter)*
- **Since:** `0.40.0` (existed pre-1.0)
- **File:** [core/diagnostics/includes/class-site-provisioner.php](../../core/diagnostics/includes/class-site-provisioner.php#L64)
- **Purpose:** Đăng ký schema installer chạy qua `BizCity_Site_Provisioner`.

### `bizcity_diagnostics_register_probes` *(filter)*
- **Since:** `0.41.0`
- **File:** [core/diagnostics/includes/class-diagnostics-smoke-runner.php](../../core/diagnostics/includes/class-diagnostics-smoke-runner.php#L46)
- **Purpose:** Đăng ký probe (FQCN string hoặc instance) — see `BizCity_Diagnostics_Probe` interface.

### `bizcity_diagnostics_register_tables` *(filter)*
- **Since:** `0.40.0`
- **File:** [core/diagnostics/includes/class-diagnostics-table-registry.php](../../core/diagnostics/includes/class-diagnostics-table-registry.php#L192)

### `bizcity_diagnostics_expected_columns` *(filter)*
- **Since:** `0.40.0`
- **File:** [core/diagnostics/includes/class-diagnostics-column-inspector.php](../../core/diagnostics/includes/class-diagnostics-column-inspector.php#L165)

---

## 2. Agent / Tool Registry

### `bizcity_register_agent` *(filter)*
- **Since:** `0.36.0`
- **Signature:** `array $agents = apply_filters( 'bizcity_register_agent', [] );` — each entry implements `BizCity_Agent_Interface`.
- **File:** [core/persona/includes/class-guru-skill-bridge.php](../../core/persona/includes/class-guru-skill-bridge.php#L155)

### `bizcity_twin_register_tool` *(filter)*
- **Since:** `0.13.0`
- **File:** [core/twin-core/includes/class-twin-tool-registry.php](../../core/twin-core/includes/class-twin-tool-registry.php#L58)
- **Purpose:** External tool plug vào agent function-calling registry. Entry implements `BizCity_Tool_Interface`.

### `bizcity_persona_tool_providers` *(filter)*
- **Since:** `0.18.0`
- **File:** [core/persona/includes/class-persona-registry.php](../../core/persona/includes/class-persona-registry.php#L287)

---

## 3. Channel Gateway

### `bizcity_register_channel_integrations` *(filter)*
- **Since:** `0.37.0`
- **File:** [core/channel-gateway/includes/class-sprint-diagnostic.php](../../core/channel-gateway/includes/class-sprint-diagnostic.php#L292)

### `bizcity_channel_formatters` *(filter)*
- **Since:** `0.37.0`
- **File:** [core/channel-gateway/includes/formatters/class-channel-formatter.php](../../core/channel-gateway/includes/formatters/class-channel-formatter.php#L60)

### `bizcity_channel_before_send` *(filter)*
- **Since:** `0.37.0`
- **Signature:** `(string $message, string $chat_id, string $platform): string`
- **File:** [core/channel-gateway/includes/class-gateway-sender.php](../../core/channel-gateway/includes/class-gateway-sender.php#L54)

### `bizcity_channel_envelope_before_send` *(filter)*
- **Since:** `0.37.0`
- **File:** [core/channel-gateway/includes/class-gateway-sender.php](../../core/channel-gateway/includes/class-gateway-sender.php#L326)

### `bizcity_channel_messages_auto_mirror` *(filter)*
- **Since:** `0.37.0`
- **File:** [core/channel-gateway/includes/class-channel-messages.php](../../core/channel-gateway/includes/class-channel-messages.php#L229)

### `bizcity_channel_message_received` *(action)*
- **Since:** `0.37.0`
- **Purpose:** Subscriber points cho automation/CRM bridge khi inbound message tới.

### `bizcity_listener_event_pre` *(filter)*
- **Since:** `0.37.0`
- **File:** [core/channel-gateway/includes/listener/class-listener-bus.php](../../core/channel-gateway/includes/listener/class-listener-bus.php#L337)

### `bizcity_webhook_router_enabled` *(filter)*
- **Since:** `0.37.0`
- **File:** [core/channel-gateway/includes/class-webhook-router.php](../../core/channel-gateway/includes/class-webhook-router.php#L70)

---

## 4. Intent / Chat / Twin Context

### `bizcity_chat_system_prompt` *(filter)*
- **Since:** `0.6.0`
- **Signature:** `(string $system_content, array $context): string`
- **File:** [core/twin-core/includes/class-twin-context-resolver.php](../../core/twin-core/includes/class-twin-context-resolver.php#L176)

### `bizcity_chat_pre_ai_response` *(filter)*
- **Since:** `0.6.0`
- **Purpose:** Short-circuit AI call (return non-null skip LLM).
- **File:** [core/intent/includes/infrastructure/class-unified-rest-api.php](../../core/intent/includes/infrastructure/class-unified-rest-api.php#L756)

### `bizcity_intent_mode_result` *(filter)*
- **Since:** `0.16.0`
- **File:** [core/intent/includes/orchestration/class-intent-engine.php](../../core/intent/includes/orchestration/class-intent-engine.php#L1359)

### `bizcity_intent_goal_patterns` *(filter)*
- **Since:** `0.16.0`
- **File:** [core/intent/includes/routing/class-intent-router.php](../../core/intent/includes/routing/class-intent-router.php#L2847)

### `bizcity_twin_focus_profile` *(filter)*
- **Since:** `0.0.0` (Phase 0)
- **File:** [core/twin-core/includes/class-focus-router.php](../../core/twin-core/includes/class-focus-router.php#L149)

### `bizcity_twin_context_resolver_result` *(filter)*
- **Since:** `0.0.0`
- **File:** [core/twin-core/includes/class-twin-context-resolver.php](../../core/twin-core/includes/class-twin-context-resolver.php#L312)

---

## 5. TwinBrain / Web Verticals

### `bizcity_twinbrain_agent_model` *(filter)*
- **Since:** `0.36.0`
- **File:** [core/twinbrain/includes/class-twinbrain-agent-runner.php](../../core/twinbrain/includes/class-twinbrain-agent-runner.php#L508)

### `bizcity_twinbrain_sub_agent_model` *(filter)*
- **Since:** `0.36.0`

### `bizcity_twinbrain_perspectives_skip_llm` *(filter)*
- **Since:** `0.36.0`

### `bizcity_twinbrain_<vertical>_allowlist` *(filter)*
- **Since:** `0.36.0`
- **Verticals:** `gov` · `law` · `med` · `nutri` · `scholar` · `tax`
- **Pattern:** Each `class-twinbrain-web-<vertical>.php` exposes domain allowlist.
- **Example:** `bizcity_twinbrain_gov_allowlist`, `bizcity_twinbrain_med_allowlist`.

### `bizcity_twinbrain_web_<vertical>_model` *(filter)*
- **Since:** `0.36.0`
- **Verticals:** `gov` · `law` · `med` · `nutri` · `scholar` · `tax` · `quick` · `deep` · `social` · `company`.

### `bizcity_twinbrain_memory_writer_enable_llm` *(filter)*
- **Since:** `0.36.0`
- **File:** [core/twinbrain/includes/class-twinbrain-memory-writer.php](../../core/twinbrain/includes/class-twinbrain-memory-writer.php#L248)

---

## 6. Knowledge / KG Hub

### `bizcity_kg_skeleton_coverage_threshold` *(filter)*
- **Since:** `0.22.0`
- **Default:** `80.0` (percent).

### `bizcity_kg_skeleton_failed_threshold` *(filter)*
- **Since:** `0.22.0`
- **Default:** `20.0`.

### `bizcity_kg_xref_evidence_cache_ttl` *(filter)*
- **Since:** `0.6.5`
- **Default:** `60` seconds.

### `bizcity_kg_is_main_task` *(filter)*
- **Since:** `0.6.5`

### `bizcity_knowledge_context_parts` *(filter)*
- **Since:** `0.6.0`

---

## 7. Content Ops

### `bizcity_content_publish` *(filter)*
- **Since:** `1.0.0`
- **File:** [core/content-ops/includes/class-scheduler.php](../../core/content-ops/includes/class-scheduler.php#L180)
- **Purpose:** Per-target publish handler dispatch (FB / Zalo / Email…).

---

## 8. Persona / Skills

### `bizcity_skill_match_message` *(filter)*
- **Since:** `0.20.0`
- **File:** [core/skills/includes/class-skill-context.php](../../core/skills/includes/class-skill-context.php#L196)

### `bizcity_twin_default_trigger_tag` *(filter)*
- **Since:** `0.18.0`
- **Default:** `'trigger'`.

---

## 9. Diagnostics & Error Reporter

### `bizcity_error_report_redact` *(filter)*
- **Since:** `0.41.0`
- **File:** [core/diagnostics/includes/class-error-reporter.php](../../core/diagnostics/includes/class-error-reporter.php#L94)

### `bizcity_error_fix_map` *(filter)*
- **Since:** `0.41.0`

### `bizcity_alert_email_to` *(filter)*
- **Since:** `0.41.0`

### `bizcity_diagnostics_deprecated_tables` *(filter)*
- **Since:** `0.40.0`

---

## 10. Deprecation & Telemetry

### `bizcity_deprecation_silent` *(filter)*
- **Since:** `1.0.0` ⭐
- **File:** [core/bizcity-llm/includes/helpers-deprecation.php](../../core/bizcity-llm/includes/helpers-deprecation.php)
- **Purpose:** Production sites silence deprecation notices: `add_filter( 'bizcity_deprecation_silent', '__return_true' );`

### `bizcity_deprecation_notice` *(action)*
- **Since:** `1.0.0` ⭐
- **Payload:** `[kind, old, new, since, reason, caller]`
- **Subscribers:** monitoring plugins, error-reporter.

---

## 11. Twin Event Bus

### `bizcity_twin_event_projectors` *(filter)*
- **Since:** `0.12.0`
- **File:** [core/twin-core/event-stream/class-twin-event-bus.php](../../core/twin-core/event-stream/class-twin-event-bus.php#L575)
- **Purpose:** Đăng ký projector listen event stream.

---

## 12. SMTP / Email

### `bizcity_smtp_config` *(filter)*
- **Since:** `0.35.0`
- **File:** [core/smtp/bootstrap.php](../../core/smtp/bootstrap.php#L85)

---

## 13. Artifact Federation

### `bizcity_artifact_federation_stamp_enabled` *(filter)*
- **Since:** `0.36.0`
- **Signature:** `(bool $enabled, string $plugin, int $artifact_id): bool`
- **Doc:** [docs/rules/PHASE-0-RULE-ARTIFACT-FEDERATION.md](../rules/PHASE-0-RULE-ARTIFACT-FEDERATION.md#L147)

---

## 14. Admin / UI

### `bizcity_admin_support_link_is_internal` *(filter)*
- **Since:** `0.40.0`
- **File:** [includes/class-admin-support-link.php](../../includes/class-admin-support-link.php#L162)

### `bizcity_canvas_handlers` *(filter)*
- **Since:** `0.14.0`
- **File:** [core/tools/class-canvas-adapter.php](../../core/tools/class-canvas-adapter.php#L240)

---

## 15. Rate Limit / Runtime

### `bizcity_twin_run_rate_window` *(filter — int seconds)*
- **Since:** `0.11.0`
- **Default:** `60`.

### `bizcity_twin_run_rate_max` *(filter — int)*
- **Since:** `0.11.0`
- **Default:** `10`.

### `bizcity_twin_debug` *(filter)*
- **Since:** `0.0.0`
- **Purpose:** Toggle debug tracer.

---

## 16. CRM / Automation Bridge

### `bizcity_crm_event_create_filter` *(filter — short-circuit insert)*
- **Since:** `0.36.0`
- **Signature:** `(?int $existing_event_id, array $payload): ?int`
- **Purpose:** External CRM injection point — return event_id để Runner update ngược.

### `bizcity_listener_automation_emit` *(filter — debug toggle)*
- **Since:** `0.37.0`

### `bizcity_listener_automation_always_emit` *(filter)*
- **Since:** `0.37.0`

---

## Cách thêm hook mới (CONTRIBUTING)

1. Khi thêm `apply_filters` / `do_action` mới CÓ Ý ĐỊNH public:
   - Đặt prefix `bizcity_*` (snake_case).
   - Thêm PHPDoc `@since X.Y.Z` ngay trên dòng `apply_filters`.
   - Thêm 1 row vào file này (group đúng category).
2. Khi rename/đổi signature:
   - Bump framework major version.
   - Gọi `BizCity_Deprecation::notify_filter( $old, $new, $since )` ở chỗ legacy.
   - Update row trong file này + thêm note vào [CHANGELOG.md](../../CHANGELOG.md).
3. Hook bắt đầu bằng `_bizcity_*` (underscore prefix) = **private** — KHÔNG list ở đây, không guarantee BC.

---

## Reference

- Contracts: [core/twin-core/contracts/framework-contracts.php](../../core/twin-core/contracts/framework-contracts.php)
- Deprecation: [core/bizcity-llm/includes/helpers-deprecation.php](../../core/bizcity-llm/includes/helpers-deprecation.php)
- Phase doc: [docs/roadmaps/PHASE-0.99-FRAMEWORK-V1.md](../roadmaps/PHASE-0.99-FRAMEWORK-V1.md)
