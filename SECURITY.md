# Security Policy

## Supported Versions

Bizcity Twin AI security patches được phát hành cho:

| Version | Supported |
|---------|-----------|
| 1.x.x   | ✅ |
| < 1.0   | ❌ (pre-release; upgrade to 1.x) |

---

## Reporting a Vulnerability

**KHÔNG** mở public GitHub Issue cho lỗ hổng bảo mật.

Vui lòng báo qua một trong các kênh:

1. **Email (preferred):** `hoanganh.itm@gmail.com` — subject prefix `[SECURITY]`. Encrypt with PGP key (xem dưới) nếu disclosure nhạy cảm.
2. **GitHub Security Advisory:** https://github.com/hoanganh8389/bizcity-twin-ai/security/advisories/new

**Maintainer:** Johnny Chu (Chu Hoàng Anh) — sole security responder until team scales.

Khi báo, vui lòng kèm:

- Mô tả lỗ hổng + impact (Confidentiality / Integrity / Availability).
- Reproduction steps tối thiểu.
- Phiên bản plugin bị ảnh hưởng.
- (Optional) Suggested fix.

### Response timeline

- **Trong 48 giờ:** acknowledge nhận báo cáo.
- **Trong 7 ngày:** đánh giá severity (CVSS 3.1) + roadmap fix.
- **Trong 30 ngày:** patch release cho high/critical, public advisory + CVE assignment.

---

## In-scope

- Plugin code: `core/`, `modules/`, `plugins/`, `includes/`, `mu-plugin/`.
- REST API endpoints (namespaces `bizcity-twinchat/v1`, `bizcity-channel/v1`, etc.).
- FE bundles (TS/JS) shipped trong `*/ui/` hoặc `assets/`.
- Authentication / capability check / nonce verification flows.
- Schema migration (auto-create / orphan cleaner).

---

## Out-of-scope

- Vulnerabilities trong `bizcity-llm-router` (server-side, repo riêng): báo về repo đó.
- Vulnerabilities trong WordPress core / vendor library: báo upstream.
- Self-XSS yêu cầu user paste payload vào console.
- Issue cần admin/super-admin access đã sẵn (post-auth admin RCE).
- Missing security headers ở server level (job của hosting).
- Brute-force login (recommend WP plugin chuyên dụng).

---

## Hardening guidelines

Khi deploy production:

1. **API key** trong `wp-config.php` (`BIZCITY_LLM_API_KEY`) — KHÔNG `update_option`.
2. Disable file edit: `define( 'DISALLOW_FILE_EDIT', true );`
3. Force SSL admin: `define( 'FORCE_SSL_ADMIN', true );`
4. Limit `manage_options` capability — chỉ admin thật cần thiết.
5. Set `define( 'WP_DEBUG_DISPLAY', false );` + `WP_DEBUG_LOG = true` để log error riêng.
6. Subscribe action `bizcity_deprecation_notice` để monitor API drift.
7. Enable diagnostic `*.health` probes trong cron để phát hiện degradation sớm.

---

## Known security boundaries

- **Gateway proxy fail-OPEN:** khi gateway lỗi, client trả `200 + _degraded:true` thay vì 5xx (R-GW-8.4). Tradeoff: FE không retry-loop, nhưng caller phải verify `success:true` trước khi dùng data.
- **Diagnostic probe admin-only:** mọi probe yêu cầu `manage_options`. CLI runner cần WP bootstrap với user có cap (Phase 0.99.8 sẽ document cách chạy `wp_set_current_user`).
- **Channel webhook signature:** mỗi adapter (FB / Zalo / Telegram) verify HMAC độc lập — xem `class-webhook-router.php`.

---

## PGP key

```
[Add PGP key fingerprint here when available]
```

---

## Hall of fame

Researchers ack với report verified sẽ được list tại:
https://bizcity.vn/security-hall-of-fame

---

## Disclosure policy

- **Coordinated disclosure** mặc định — public advisory chỉ release sau khi patch ship.
- Researcher có thể yêu cầu credit hoặc anonymous.
- Bizcity KHÔNG threat legal action với good-faith research tuân theo policy này.
