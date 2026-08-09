# Capability Security Contract v1

Status: Stable baseline
Scope: Third-party extension permissions, approval gates, and execution safeguards.

## Permission Model

Permissions are declared in extension manifest as explicit scopes.

Example:

```json
{
  "permissions": [
    "kg.read",
    "kg.write",
    "memory.read",
    "content.publish",
    "finance.write",
    "channel.zalo.send",
    "woocommerce.order.create"
  ]
}
```

Permission naming rule:

- pattern: action.domain[.resource][.verb]
- examples: kg.read, content.publish, channel.zalo.send, woocommerce.order.create

## Required Security Controls

- Least-privilege by explicit scope declaration.
- Install-time consent screen using requested permissions and reasons.
- Scope binding to tenant/site/user.
- Audit log for every mutation and tool execution.
- Webhook signature verification policy.
- Secret references via vault keys, not raw secrets in manifest.
- SSRF controls using allow-host policy.
- Upload controls using allowed MIME, max size, and scan-required flags.
- Approval gates for sensitive actions.

## Sensitive Actions Requiring Approval

1. send_message
2. publish_content
3. create_order
4. delete_data
5. execute_payment

## Manifest Fields

- permissions
- scope_bindings
- approval_gates
- security.webhook_signature
- security.secret_refs
- security.network_policy
- security.upload_policy

All fields are validated by:

- core/twin-core/contracts/schema/manifest.schema.json
- bin/bizcity-manifest-validate.php

## Runtime Enforcement Expectations

- Unauthorized scope request is denied before execution.
- Declared permissions are not active until an administrator grants them in the
  Twin Permissions page.
- User-scoped permissions require an explicit `scope_level=user` and a positive
  `user_id`; missing identity fails closed with `scope_mismatch`.
- Finance mutations use the dedicated `finance.write` permission; the broader
  `content.write` permission cannot authorize a `finance_entry` resource.
- Missing approval for sensitive action is rejected.
- Webhook without valid signature is rejected for signed channels.
- Secret material must be read from vault at runtime only.
- Outbound URL calls must honor allow-host policy.
- Uploads outside policy are rejected.

## Runtime Integration

Twin tools are dispatched through `BizCity_Twin_Tool_Registry::execute()`.
The registry performs permission authorization, approval-gate checks, trace and
idempotency propagation, replay protection, and metadata-only audit logging
before returning the tool result.

`BizCity_Twin_Capability_Consent` stores site-local grants in the current
WordPress option scope and exposes the `Tools > Twin Permissions` page. An
extension registers its validated manifest with `register_manifest()`, and its
execution context must include `extension_id` for those grants to be merged by
the capability guard. User-scoped grants are filtered by `user_id`; tenant and
site grants remain local to the current WordPress site.
The public `grant()` and `revoke()` mutation methods also require
`current_user_can( 'manage_options' )` and fail closed, so a direct caller cannot
bypass administrator consent by skipping the admin form. This boundary is covered
by the framework smoke and the `core.framework.production_contract` diagnostics
probe.

At runtime, `register_manifest()` fails closed unless the manifest has schema
version `1.0`, stable SemVer, valid permission syntax, approval gates for
sensitive permissions, and valid secret-reference syntax. The standalone CLI
validator and runtime registration boundary therefore enforce the same minimum
security invariants before a manifest can enter the consent registry.

`BizCity_Twin_Security_Policy` is the shared enforcement baseline for outbound
URLs and PHP upload arrays. It blocks private/reserved IPs by default, supports
exact manifest allow-host lists, checks actual MIME type and byte limits, and
requires a callable scanner when `scan_required` is enabled. The central tool
registry copies the registered manifest network/upload policy into the
execution context, so a caller cannot weaken the extension policy.

The current slice is registration-time consent infrastructure. Activation-flow
blocking, user-facing grant delegation, and a production secret-vault provider
remain separate rollout items.

Mutation controllers should call `BizCity_Twin_Mutation_Guard::validate()`
before the side effect and `BizCity_Twin_Mutation_Guard::record()` after the
outcome. The guard does not create a new SQL table; it writes sanitized JSONL
evidence through the shared runtime audit sink.
