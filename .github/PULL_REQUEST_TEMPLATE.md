# Pull Request

## Mô tả

<!-- 1-2 câu PR làm gì + tại sao. Link issue: Closes #xxx -->

## Loại change

- [ ] 🐛 Bug fix (non-breaking)
- [ ] ✨ Feature mới (non-breaking)
- [ ] 💥 Breaking change (cần bump major)
- [ ] 📝 Docs only
- [ ] 🔧 Refactor / chore

## Rule compliance checklist

- [ ] **R-GW-8** — Không reference `BizCity_Router_*` trong code client. Dùng `BizCity_LLM_Client` wrapper.
- [ ] **R-GW-API-CATALOG** — Đã check [docs/api/README.md](../../../bizcity-llm-router/docs/api/README.md) trước khi code feature mới.
- [ ] **R-DCL** — Schema change (nếu có) đi qua `core/diagnostics/changelog/<module>.json` + `php core/diagnostics/validate-schema-changelog.php` exit 0.
- [ ] **R-DDV** — Có diagnostic row PASS cho sprint mới (gateway/REST/SQL/hook).
- [ ] **R-CRON-META** — Cron handler mới có `note()` / `note_event()`.
- [ ] **R-CH-NS** — REST channel route dùng `bizcity-channel/v1`.
- [ ] **PHP 7.4** — Không union return type, nullsafe, match, str_contains, named arg, enum, readonly.
- [ ] **HOOKS.md** — Filter/action public mới đã add row vào [docs/extension/HOOKS.md](../../docs/extension/HOOKS.md) với `@since`.
- [ ] **CHANGELOG.md** — Đã update `## [Unreleased]`.

## CI

- [ ] `composer lint` pass
- [ ] `composer compat:php74` exit 0
- [ ] `composer diagnostics` (probe relevant) PASS
- [ ] Nếu FE: `npm run build` không lỗi

## Diagnostic evidence

<!-- Paste screenshot hoặc text output của Diagnostic page row liên quan -->

```
[paste diagnostic output]
```

## Breaking changes (nếu có)

- Old API: ``
- New API: ``
- Migration path: <!-- thường là `BizCity_Deprecation::notify(...)` + giữ legacy ≥ 1 minor -->

## Reviewer checklist

- [ ] Code đã đọc, không có TODO/FIXME bỏ ngỏ.
- [ ] Hook public mới có `@since X.Y.Z`.
- [ ] Sub-plugin author không cần đổi code (hoặc đã document trong CHANGELOG).
- [ ] Không leak API key / PII trong code/log.
