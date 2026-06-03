# Contributing to BizCity Twin AI

Cảm ơn bạn quan tâm đóng góp! Đây là framework AI cho WordPress được duy trì bởi
[BizCity](https://bizcity.vn). Tài liệu này tóm tắt rule và workflow.

---

## Quick links

- 🏗️ [docs/getting-started.md](docs/getting-started.md) — setup dev environment.
- 🔌 [docs/extending/sub-plugin-quickstart.md](docs/extending/sub-plugin-quickstart.md) — build sub-plugin đầu tiên.
- 🎯 [docs/rules/PHASE-0-CANON.md](docs/rules/PHASE-0-CANON.md) — tier rules (TỐI THƯỢNG / QUAN TRỌNG).
- 🔧 [.github/copilot-instructions.md](.github/copilot-instructions.md) — rule cho AI agent (Copilot/Claude/Cursor).
- 📜 [CHANGELOG.md](CHANGELOG.md) — version history.

---

## 1. Rule TỐI THƯỢNG cần đọc trước khi code

| Rule | Tóm tắt | Spec |
|---|---|---|
| **R-DCL** | Mọi schema change → JSON changelog + validator. | [PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md](docs/diagnostics/PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md) |
| **R-DDV** | Mỗi sprint mới phải có diagnostic row PASS. | [PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md](docs/diagnostics/PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md) |
| **R-CRON-META** | Cron handler phải `note()` evidence. | [PHASE-0-RULE-CRON-META.md](docs/rules/PHASE-0-RULE-CRON-META.md) |
| **R-CH-NS** | Channel REST namespace = `bizcity-channel/v1`. | [PHASE-0-RULE-CHANNEL-ONLY.md](docs/rules/PHASE-0-RULE-CHANNEL-ONLY.md) |
| **R-GW-8** | Client KHÔNG cài `bizcity-llm-router` — proxy về bizcity.vn. | [PHASE-0-RULE-GATEWAY-ONLY.md](docs/rules/PHASE-0-RULE-GATEWAY-ONLY.md) |
| **R-GW-API-CATALOG** | Lookup [docs/api/](../bizcity-llm-router/docs/api) trước, build endpoint server trước nếu thiếu. | [.github/copilot-instructions.md](.github/copilot-instructions.md#R-GW-API-CATALOG) |

---

## 2. PHP 7.4 compatibility floor

Target runtime: **PHP 7.4** trên shared hosting khách hàng. CẤM:

- Union return type `): int|string`
- Nullsafe `?->`
- `match()` expression
- Named arguments `f(timeout: 30)`
- Constructor promotion
- `enum`, `readonly`
- `str_contains` / `str_starts_with` / `str_ends_with` (dùng `strpos`/`substr` thay).

CI grep guard tự chặn PR. Xem chi tiết trong [.github/copilot-instructions.md §PHP 7.4](.github/copilot-instructions.md).

---

## 3. Branch & commit

- Default branch: `main`. PR target `main`.
- Branch name: `feat/<scope>-<slug>` · `fix/<scope>-<slug>` · `docs/<slug>`.
- Commit theo [Conventional Commits](https://www.conventionalcommits.org/):
  ```
  feat(twinchat): add stream tool calls
  fix(php74): replace str_contains in channel-router
  docs(hooks): document bizcity_register_module
  ```

---

## 4. Workflow PR

1. Fork + clone.
2. `composer install`
3. Code — tuân R-DCL/R-DDV/R-GW-8 etc.
4. `composer lint` — phải pass WPCS.
5. `composer compat:php74` — phải exit 0.
6. `composer diagnostics` (Phase 0.99.8 sẽ có CLI runner) — probe liên quan phải PASS.
7. Update [CHANGELOG.md](CHANGELOG.md) `## [Unreleased]`.
8. Update [docs/extension/HOOKS.md](docs/extension/HOOKS.md) nếu thêm filter/action public.
9. Mở PR — fill template `.github/PULL_REQUEST_TEMPLATE.md`.

---

## 5. Test strategy

Bizcity Twin AI **dùng probe-based diagnostics** thay PHPUnit cho integration test
(triết lý "real-call validation"):

- ✅ Integration test → probe trong `core/diagnostics/includes/probes/`.
- ✅ Smoke test → probe `*.health`.
- ✅ Schema migration test → JSON validator + auto-create probe.
- 🟡 Unit test (pure helper) → PHPUnit suite tối thiểu trong `tests/unit/` (Phase 0.99.8).

KHÔNG viết wp-cli script ad-hoc để check schema — biến nó thành probe.

---

## 6. Sub-plugin authoring

- KHÔNG modify core file để thêm feature → dùng filter/action hoặc kế thừa `BizCity_Module_Base`.
- KHÔNG `class_exists( 'BizCity_Router_*' )` — class này CHỈ tồn tại trên server.
- KHÔNG `wp_remote_post( 'https://bizcity.vn/...' )` thẳng → dùng `BizCity_LLM_Client` wrapper.
- KHÔNG đăng ký REST route ở namespace `bizcity/v1` (xung đột router) — dùng `bizcity-channel/v1` hoặc namespace riêng.
- Hook public mới phải có `@since X.Y.Z` PHPDoc.

---

## 7. Rule khi đụng tài liệu

- KHÔNG tạo file `.md` mới chỉ để document fix nhỏ — update `CHANGELOG.md` đủ.
- Phase doc roadmap → `docs/roadmaps/PHASE-0.X-*.md`.
- Rule mới → `docs/rules/PHASE-0-RULE-*.md`.
- Diagnostic-related → `docs/diagnostics/`.

---

## 8. Code of Conduct

Xem [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Tóm tắt: tôn trọng, xây dựng, không phân biệt.

---

## 9. Developer Certificate of Origin (DCO) — REQUIRED

Mọi commit trong PR **bắt buộc** kèm dòng `Signed-off-by` để xác nhận bạn có
quyền submit code đó dưới license **GPL-2.0-or-later** của repo. Đây là
[DCO](https://developercertificate.org/) — nhẹ hơn CLA, không yêu cầu chuyển
quyền tác giả về BizCity (bạn vẫn giữ copyright của mình).

### Cách dùng

```bash
git commit -s -m "feat(twinchat): add stream tool calls"
# Tự động chèn trailer:
#   Signed-off-by: Your Name <your.email@example.com>
```

Hoặc cấu hình một lần:

```bash
git config --global user.name "Your Name"
git config --global user.email "your.email@example.com"
# Mỗi commit từ đó chỉ cần `-s`.
```

### Bot kiểm tra

GitHub Action [`.github/workflows/dco.yml`](.github/workflows/dco.yml) tự chặn
PR nếu có commit thiếu `Signed-off-by`. Sửa bằng `git rebase -i` + `--signoff`
rồi force-push.

### Bạn xác nhận điều gì?

Đọc đầy đủ tại https://developercertificate.org/. Tóm tắt:

- Bạn viết code này, hoặc
- Code này được license tương thích GPL-2.0+, và
- Bạn đồng ý đóng góp dưới GPL-2.0+ với public record forever.

---

## 10. License header cho file PHP mới

File PHP **public mới** (trong `core/`, `modules/` không gitignored, hoặc
`plugins/` không gitignored) phải có header:

```php
<?php
/**
 * @package   BizCity_Twin_AI
 * @copyright 2023-2026 Johnny Chu (Chu Hoàng Anh)
 * @license   GPL-2.0-or-later
 */
```

File **proprietary** (gitignored vertical plugins) dùng header riêng — xem
[docs/roadmaps/PHASE-0.98-IP-PROTECTION.md §4.2](docs/roadmaps/PHASE-0.98-IP-PROTECTION.md).

---

## 11. Liên hệ

- 🐛 Bug: GitHub Issues.
- 🔒 Security: [SECURITY.md](SECURITY.md) — KHÔNG file public issue cho lỗ hổng.
- 💬 Hỗ trợ chung: https://bizcity.vn/contact.
- 📧 Maintainer: **Johnny Chu (Chu Hoàng Anh)** <hoanganh.itm@gmail.com>.
