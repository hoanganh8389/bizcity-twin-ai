# bizcity-tool-image — Plugin Change Log

> **Role**: BizCity SDK Tool Plugin — AI Image Generation Agent
> **Category**: Creative / AI Image
> **Platform Log**: [bizcity-intent/SYSTEM-LOG.md](../../mu-plugins/bizcity-intent/SYSTEM-LOG.md)
> **Architecture**: [ARCHITECTURE.md](../../mu-plugins/bizcity-intent/ARCHITECTURE.md)
> **Roadmap Phase**: Phase 10 — Pipeline Orchestration + Tool Registry

---

## Plugin Status

| Item | Status |
|------|--------|
| Scaffold created | ✅ 2026-03-03 |
| Intent Provider registered | ✅ `bizcity_intent_register_providers` |
| Tool callbacks coded | ✅ 2 tools |
| `bizcity_intent_tools_ready` hook fires | ⏳ Blocked — Known Issue #11 |
| Tool name conflict | ✅ Không conflict — engine chưa có built-in `generate_image` |
| INTENT-SKELETON.md | ⏳ |
| Production active | ⏳ Chờ Phase 10 engine fix |

---

## Tools trong Plugin

| Tool Name | Callback | Wraps | Output |
|-----------|----------|-------|--------|
| `generate_image` | `BizCity_Tool_Image::generate_image` | `twf_generate_image_url()` (gpt-image-1) | `data.image_url` |
| `generate_image_and_save` | `BizCity_Tool_Image::generate_image_and_save` | twf + WP Media Library | `data.image_url` + `data.meta.attachment_id` |

> ✅ Tên `generate_image` KHÔNG conflict với built-ins. Plugin này là ưu tiên implement đầu tiên.

---

## Pipeline I/O — KEY CONNECTOR

> Plugin này là **nguồn `image_url`** cho toàn bộ pipeline platform.

```
Input  ← $slots.prompt (mô tả ảnh, free text)
         $slots.title  (auto-derive prompt từ title nếu không có prompt)
         $slots.content (auto-derive từ content excerpt)
Output → data.image_url (CDN URL hoặc WordPress attachment URL)
         data.type = 'ai_image', data.id = attachment_id (nếu saved)
         data.meta.model = 'gpt-image-1', data.meta.size = '1024x1024'
```

**Pipeline chains — image là PROVIDER, luôn ở step đầu:**
```
generate_image → data.image_url → content_write_article   (ảnh bìa bài viết)
generate_image → data.image_url → fb_post_facebook        (ảnh đăng Facebook)
generate_image → data.image_url → woo_create_product      (ảnh sản phẩm WooCommerce)
generate_image → data.image_url → create_video            (thumbnail video Kling)
```

---

## AI Model Config

| Setting | Value |
|---------|-------|
| Primary model | `gpt-image-1` |
| Fallback model | `dall-e-3` |
| Default size | `1024x1024` |
| Format | `b64_json` → decode → WP Media Library |
| API key source | `get_option('twf_openai_api_key')` |

---

## Backlog

```
CRITICAL (Phase 10):
  [ ] engine fire bizcity_intent_tools_ready (zero conflict — ưu tiên cao)
  [ ] Verify twf_generate_image_url() tồn tại trong bizcity-admin-hook/includes/flows/functions.php

HIGH:
  [ ] INTENT-SKELETON.md
  [ ] Pipeline test: generate_image → fb_post_facebook (image_url)
  [ ] Pipeline test: generate_image → content_write_article (image_url)
  [ ] Fallback: nếu gpt-image-1 404 → dùng dall-e-3

MEDIUM:
  [ ] Style presets: realistic / anime / minimalist / watercolor
  [ ] Size options: 1024x1024 / 1792x1024 (landscape) / 1024x1792 (portrait)
  [ ] generate_image_and_save: auto-attach vào post nếu pipeline có post_id upstream

LOW:
  [ ] Upscale tool: nhận image_url → gọi upscale API → trả về HD version
  [ ] Watermark option: overlay brand logo
```

---

## Change Log

### 2026-03-03
- Plugin scaffold tạo — 5 files, 2 tools: `generate_image`, `generate_image_and_save`
- Model: gpt-image-1 (primary), dall-e-3 (fallback)
- Dependency: `bizcity-admin-hook` functions.php cho `twf_generate_image_url()`, `twf_save_base64_image_to_media()`
- Prompt auto-derive: nếu không có `$slots['prompt']`, dùng `$slots['title']` hoặc `$slots['content']` từ pipeline

---

*Ref: [bizcity-intent SYSTEM-LOG.md](../../mu-plugins/bizcity-intent/SYSTEM-LOG.md) — Issue #11 (không conflict — implement sớm)*
