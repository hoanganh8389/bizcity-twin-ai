---
name: 🐛 Bug report
about: Báo lỗi runtime / regression / crash
title: '[BUG] '
labels: ['bug', 'triage']
---

## Mô tả

<!-- Mô tả ngắn lỗi (1-2 câu). -->

## Reproduction

1. ...
2. ...
3. ...

**Expected:**
**Actual:**

## Environment

| Item | Value |
|---|---|
| Plugin version | (xem `bizcity-twin-ai.php` header hoặc Settings → BizCity) |
| WordPress version | |
| PHP version | |
| Hosting | (SiteGround / Hostinger / VPS / local) |
| `bizcity-llm-router` version (nếu là server) | N/A nếu client |

## Diagnostics evidence

Vào **Tools → BizCity Diagnostics**, click **Run All**, paste row FAIL relevant:

```
[paste output here]
```

## Logs

```
[paste error_log lines here, redact API keys]
```

## Đã thử fix?

<!-- Optional: workaround / debug đã thử. -->

---

> ⚠️ KHÔNG paste API key (`biz-xxx...`) vào issue. Mask hoặc dùng `biz-***`.
