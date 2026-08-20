# BizCity Codec Standard

> **Status:** Tier 1 CANON
> **Version:** 1.0.0
> **Effective:** 2026-08-20
> **Owner:** BizCity Core Helper
> **Runtime:** PHP 7.4 compatibility floor
> **Canonical implementation:** `core/helper/class-bizcity-codec.php`

## 1. Purpose

This document is the source of truth for encoding, decoding, signing, and
small authenticated state payloads across the whole BizCity platform:

- `core/**`
- `modules/**`
- bundled and standalone `plugins/**`
- compatibility and MU-plugin callers that depend on this plugin contract

The goal is one shared primitive implementation with domain-specific payload
schemas kept in the owning module. New code must not create another local
`base64url`, token, AES, or HMAC helper.

This standard does **not** mean that every payload uses the same wire format.
URL state, JWT segments, binary media, and encrypted secrets have different
contracts and must remain distinguishable.

## 2. Canonical Helper

Load the helper through:

```php
require_once BIZCITY_TWIN_AI_DIR . '/core/helper/bootstrap.php';
```

The bootstrap loads:

```php
require_once __DIR__ . '/class-bizcity-codec.php';
```

Callers use `BizCity_Codec` for primitives and keep only their own:

- payload schema and version
- purpose/audience/tenant checks
- key derivation policy
- TTL and replay policy
- DB row correlation and authorization
- provider-specific protocol rules

## 3. Primitive Catalog

| Primitive | Contract | Use for |
|---|---|---|
| `base64url_encode($value)` | Strict URL-safe Base64 without padding | URL/JWT segment and compact binary transport |
| `base64url_decode($value)` | Strict URL-safe Base64 decode, returns `string|false` | URL/JWT segment input |
| `json_base64url_encode($payload)` | JSON with unescaped slash/Unicode flags, then Base64URL | Small unsigned or separately authenticated JSON state |
| `json_base64url_decode($value)` | Base64URL decode then JSON array, returns `array|false` | Small JSON state input |
| `hmac_sha256($value, $key, $raw)` | HMAC-SHA256, raw or hex output | Webhook signatures, JWT signatures, payload MAC |
| `encrypt_json_payload($payload, $key, $prefix, $mac_context)` | AES-256-CBC plus HMAC, URL-safe output | Short-lived authenticated URL/OAuth state |
| `decrypt_json_payload($token, $key, $prefix, $mac_context)` | Verify MAC, decrypt, JSON decode, returns `array|false` | Authenticated URL/OAuth state input |
| `encrypt_raw_payload($plain, $key, $iv, $cipher)` | Base64 of `iv + raw ciphertext` | Existing raw ciphertext storage format |
| `decrypt_raw_payload($encoded, $key, $cipher)` | Decode `iv + raw ciphertext` | Existing raw ciphertext storage format |
| `encrypt_legacy_storage($value, $key, $iv, $cipher)` | Base64 of OpenSSL output | Existing integration secret format |
| `decrypt_legacy_storage($value, $key, $iv, $cipher)` | Reverse legacy storage format | Existing integration secret format |
| `legacy_url_encode($plain, $key, $iv, $cipher)` | Byte-compatible double-Base64 URL token | Existing printed QR/Messenger ref links |
| `legacy_url_decode($encoded, $key, $iv, $cipher)` | Reverse legacy URL token | Existing printed QR/Messenger ref links |
| `decrypt_fixed_iv_raw($encoded, $key, $iv, $cipher)` | Decode fixed-IV raw ciphertext | Existing fixed-IV fallback readers |

The helper owns the primitive implementation. A caller must not duplicate the
body of any row in this table.

## 4. Payload Taxonomy

### 4.1 URL state and cross-domain OAuth state

Use `encrypt_json_payload()` for a small state that must cross domains:

```text
canonical DB row
  -> compact payload { v, platform, chat_id, account_id, blog_id, exp, nonce }
  -> encrypt + authenticate
  -> base64url token in query parameter
  -> state/redirect_to preserves the exact value
  -> callback decodes and verifies
  -> callback compares payload with the canonical DB row
  -> atomic consume and one owner notification
```

Required payload fields for identity flows:

- `v`: payload version
- `platform`: normalized channel/platform
- `chat_id` or `external_user_id`
- `account_id` such as bot/page/account id
- `blog_id` or tenant id when the flow is tenant-scoped
- `exp`: absolute expiry timestamp
- `nonce`: random per-issuance value

The URL payload is correlation state, not the source of truth. The DB row is
the source of truth.

### 4.2 JWT and signed segments

JWT-style segments use `base64url_decode()` for segments and
`hmac_sha256()` for the signature. Do not use JSON payload encryption here
unless the protocol explicitly changes. Preserve the existing JWT wire format.

### 4.3 Raw binary and media Base64

Image/audio/PDF/API binary payloads may use ordinary `base64_encode()` and
`base64_decode()` when the external provider requires it. These are not URL
state tokens and should not be changed to JSON state codecs merely to reduce a
search result.

### 4.4 Secret-at-rest storage

Secrets stored in options or integration records use the legacy storage/raw
wrappers with the original key, IV, cipher, and fallback behavior. A migration
must be dual-read or byte-compatible before changing the stored format.

Never put provider secrets, passwords, Bearer tokens, or API keys in URL
payloads, even when encrypted.

## 5. Security Contract

Every new authenticated payload must satisfy all items below:

- Use encryption with integrity: AEAD where available, otherwise encryption plus
  HMAC through `BizCity_Codec`.
- Use a stable version, random nonce, short TTL, and explicit audience/tenant.
- Verify integrity before trusting any decoded field.
- Validate the destination against an allowlist before following a redirect.
- Compare decoded identity with the canonical DB row and physical tenant.
- Consume atomically and make retries idempotent.
- Define one notification/side-effect owner and one idempotency key.
- Redact full tokens, secrets, SQL, DSN, and PII from logs.
- Log only reason buckets, token/hash prefixes, row id, blog id, account id, and
  verify/consume/send outcomes.

A decoded `chat_id`, `user_id`, `blog_id`, or `account_id` supplied by a
browser is untrusted until integrity, tenant, and DB-row checks pass.

## 6. Compatibility Rules

Do not silently change a format that is already in URLs, QR codes, options, or
DB rows.

| Existing surface | Current compatibility rule |
|---|---|
| CRM magic-link `bzm2_` | Keep prefix, key derivation, IV/MAC context, and payload contract stable; decode old tokens after helper migration |
| Messenger/QR flow ref | Use `legacy_url_encode/decode()`; preserve byte-compatible double-Base64 format |
| OAuth proxy JWT | Preserve three dot-separated segments and hex/raw signature behavior |
| CF7 ZNS and BigLead | Preserve raw `iv + ciphertext` Base64 storage |
| `BizCity_Integration` secrets | Preserve legacy Base64(OpenSSL output) format and cipher-specific key/IV |
| Network OAuth secrets | Preserve AES-128-CBC storage format |
| Broadcast ZNS fallback | Preserve fixed-IV AES-256 reader until a deliberate migration exists |
| Image/audio/API Base64 | Preserve provider-required ordinary Base64 format |

When introducing a new format, add a version/prefix and document the reader,
writer, TTL, key derivation, and migration window in the owning module.

## 7. Migration Playbook

### Step 1: Inventory

Search active runtime code and exclude `_archived/`, vendor, generated assets,
and documentation from the first shortlist. Classify every match as URL state,
JWT, binary media, secret storage, webhook HMAC, or unrelated serialization.

### Step 2: Choose the primitive

Use the catalog in section 3. If no primitive fits, extend
`BizCity_Codec` first and document the new wire format before changing callers.

### Step 3: Preserve the boundary

Keep key derivation, cipher, IV, prefix, padding behavior, and fallback behavior
identical for legacy readers. Do not replace a legacy reader with a new format
without fixtures and a migration plan.

### Step 4: Refactor callers

Replace local calls with `BizCity_Codec`. The caller should retain only domain
validation, payload construction, and authorization checks.

### Step 5: Verify

Run static diagnostics and focused runtime checks:

- encode/decode round trip
- old fixture decode
- tampered token rejection
- wrong prefix/audience/tenant rejection
- expired payload rejection
- HMAC raw and hex output compatibility
- legacy storage decrypt compatibility
- cross-domain `state`/`redirect_to` preservation
- atomic consume and duplicate notification prevention

### Step 6: Roll out by surface

Use this order to reduce blast radius:

1. `core/helper` and bootstrap
2. core token/state callers
3. `core/channel-gateway`
4. `core/automation`, `core/knowledge`, `core/twinbrain`, and other core modules
5. bundled plugins
6. standalone plugins and MU-plugin compatibility callers
7. archived code only when explicitly reactivated

## 8. Static Sweep

PowerShell example for the active plugin tree:

```powershell
$root = 'wp-content/plugins/bizcity-twin-ai'
Get-ChildItem $root -Recurse -File -Filter *.php |
  Where-Object { $_.FullName -notmatch '\\(_archived|vendor|_library)\\' } |
  Select-String -Pattern 'base64_encode|base64_decode|openssl_encrypt|openssl_decrypt|hash_hmac|encode_token|decode_token'
```

Every result must be classified. Direct primitive usage is allowed only inside
`core/helper/class-bizcity-codec.php` or for a clearly documented binary media
or provider protocol that is not a codec/state contract.

## 9. Definition of Done

Codec standardization is complete for a surface only when:

- the caller uses `BizCity_Codec` or is explicitly classified as binary media;
- no new local encode/decode/crypto helper exists;
- legacy fixtures still decode where compatibility is required;
- tampering, wrong tenant, expiry, and wrong audience fail closed;
- cross-domain state survives the full redirect chain;
- consume and notification have one owner and idempotency protection;
- diagnostics report no new errors;
- the owning module documents its payload and migration contract;
- any schema/DDL change follows R-DCL/R-CR separately.

## 10. References

- Runtime helper: `core/helper/class-bizcity-codec.php`
- Helper bootstrap: `core/helper/bootstrap.php`
- Project agent rule: `.github/copilot-instructions.md`
- URL-state precedent: `plugins/bizcity-twin-crm/includes/admin-chat/class-magic-link.php`
- Legacy flow precedent: `core/channel-gateway/includes/flows/class-flow-ref-codec.php`
- OAuth JWT precedent: `core/channel-gateway/includes/class-oauth-proxy.php`

## 11. Post-Audit Status — 2026-08-20

The second-pass audit covered active PHP runtime code and excluded archived,
vendor, library, generated, documentation, test, and media-build surfaces.

### 11.1 Migrated surfaces

The following active BizCity surfaces now delegate codec primitives to
`BizCity_Codec` while preserving their existing wire/storage formats:

- `core/channel-gateway` flow refs, OAuth proxy, network OAuth, broadcast
  fallback, CF7 ZNS/BigLead, integrations, webhook HMAC checks
- `core/mcp` OAuth Base64URL/Basic decoding
- `modules/twinchat` learning share tokens
- `modules/twinweb` guest identity HMAC
- `plugins/bizcity-twin-crm` campaign/order tokens, email/Gmail secret storage,
  magic-link payloads
- `plugins/bizcity-facebook-bot` OAuth state and webhook signatures
- `plugins/bizcity-zalo-bot` secret/webhook legacy XOR/Base64 paths
- bundled `plugins/bizgpt-tool-google` OAuth state and token storage

### 11.2 Valid direct primitive exceptions

These are not local codec violations when the provider contract requires the
format:

- MIME transfer decoding/encoding
- image, audio, PDF, and OCR binary payloads
- `data:*;base64,` media URLs
- HTTP Basic `client_id:secret` authorization headers
- third-party libraries and their own protocol implementations
- diagnostic source markers that only inspect whether a dependency exists

They must still use `BizCity_Codec::base64_encode/decode()` when the caller is
BizCity-owned and the change does not alter a provider wire contract.

### 11.3 Standalone-plugin boundary

Standalone plugins such as `bizgpt-open-api`, `bizgpt-oauth-client-new`, and
`bizgpt-oauth-server-new` cannot assume `BizCity_Codec` is loaded. They must
adopt one of these paths before migration:

1. Load the shared helper through a guarded compatibility bridge when the
  `bizcity-twin-ai` runtime is present.
2. Move the contract to a separately shipped shared package/library used by
  both sides.
3. Keep a temporary local implementation only during a documented migration
  window, with byte-compatible fixtures and an owner/date recorded here.

A standalone plugin must never call `BizCity_Codec` unguarded if it can run
without `bizcity-twin-ai`. The bridge must not copy a second codec body.

### 11.4 Remaining audit backlog

The following require separate compatibility work and are not silently marked
compliant by this document:

- `bizgpt-open-api` domain token helper
- `bizgpt-oauth-client-new` magic OAuth state helper
- `bizgpt-oauth-server-new` bundled OAuth library encryption classes
- standalone `bizgpt-tool-google` copy outside the bundled `bizcity-twin-ai` tree
- legacy `bizcity-automation` integration/JWT helpers
- `bizcity-agent-calo` and `bizcity-voice-chat` signed payload helpers
- independent BizCity plugins that ship and boot without the core helper

For each backlog item, the migration PR must identify the runtime owner,
whether the plugin is standalone or bundled, the existing wire format, the
bridge/load strategy, and the fixture test before changing the implementation.

### 11.5 Audit conclusion

The shared standard is **PASS for the active `bizcity-twin-ai` runtime tree**:
`core/helper`, active `core/**`, active `modules/**`, and bundled BizCity
plugins migrated in this audit use `BizCity_Codec` for shared primitives.

The standard is **NOT YET a blanket PASS for every plugin directory in the
workspace** because standalone and legacy plugins listed in section 11.4 still
own independent runtime boundaries. Their code must not be silently treated as
compliant until a guarded bridge or shared package is deployed and their old
fixtures pass.

## 12. Mandatory CLI and Diagnostics Gate

Static search is only an inventory step. It is not proof that the helper is
loaded or that a token can be decoded at runtime. Every codec change must pass
all three layers below before it is considered complete.

### 12.1 Disk

- `core/helper/class-bizcity-codec.php` exists, is readable, non-empty, and has
  no UTF-8 BOM.
- `core/helper/bootstrap.php` loads the helper before the caller module.
- Active runtime contains no direct `twf_encrypt_chat_id()` or
  `twf_decrypt_chat_id()` calls.

### 12.2 Loader

- `BizCity_Codec` is loaded.
- Required methods in the changed contract exist.
- Caller classes load without a fatal error. A missing helper class is a
  critical failure because it can become a public-request HTTP 500.

### 12.3 Runtime

- Authenticated JSON payload round-trip passes.
- A tampered token is rejected.
- Wrong prefix/context/tenant and expired state are rejected where applicable.
- Legacy fixture/wire-format round-trip passes.
- HMAC raw/hex output matches the existing protocol.
- Consume/notification paths have one owner and do not duplicate side effects.

### 12.4 Required commands

When PHP CLI and a WordPress root are available:

```powershell
php bin/diagnostics-run.php --codec --wp-root="D:\path\to\wordpress"
```

Equivalent focused Diagnostics REST call:

```text
POST /wp-json/bizcity-diagnostics/v1/smoke/run
{ "id": "core.helper.codec_standard" }
```

Run-all is also allowed, but `core.helper.codec_standard` must be present and
PASS. `SKIP` or `precheck-fail` is not evidence of completion for a changed
codec. If PHP CLI is unavailable, run the Diagnostics REST probe and retain
Disk/Loader/Runtime evidence instead of writing an ad-hoc debug script.

### 12.5 CI acceptance

The focused CLI gate must return exit code `0`. These are blocking failures:

- `codec_helper_missing`
- `codec_class_or_method_missing`
- `codec_standard_runtime_failed`
- active `twf_*` calls
- tamper acceptance
- legacy fixture mismatch
- duplicate notification or side-effect evidence
