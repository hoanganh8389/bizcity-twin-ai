---
name: 💡 Feature request
about: Đề xuất tính năng mới hoặc API mới
title: '[FEAT] '
labels: ['enhancement', 'triage']
---

## Vấn đề muốn giải quyết

<!-- Use case cụ thể, ai gặp, gặp như thế nào. -->

## Đề xuất giải pháp

<!-- Mô tả API/feature đề xuất. -->

## Đã check 1-API catalog?

- [ ] Đã đọc [bizcity-llm-router/docs/api/README.md](../../../bizcity-llm-router/docs/api/README.md) §2 (12 branches).
- [ ] Có endpoint sẵn nhưng thiếu wrapper PHP → đề xuất wrapper.
- [ ] Cần endpoint MỚI trên server (nhánh nào? — gov/law/med/gen-image/search/...).
- [ ] Cần thêm hook public mới ([HOOKS.md](../../docs/extension/HOOKS.md)).

## Sub-plugin đang làm hay core?

- [ ] Sub-plugin (workaround không cần modify core).
- [ ] Core framework (cần discuss BC + semver).

## Alternative đã cân nhắc

<!-- -->

---

> 💡 Nếu chỉ là feature cho 1 use case riêng, hãy build sub-plugin trước,
> sau đó propose nâng pattern lên core nếu reusable.
