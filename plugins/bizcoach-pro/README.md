# BizCoach Pro — Producer Hub

> **Status:** SKELETON v0.1.0 (2026-05-15) — Sprint G scaffold ship
> **Spec:** [PHASE-0.36-BIZCOACH-MAP-FRAMEWORK.md](../../PHASE-0.36-BIZCOACH-MAP-FRAMEWORK.md)
> **Canon:** [PHASE-0-CANON.md](../../PHASE-0-CANON.md) Tier 2 — **R-PROD-HUB**
> **Visibility:** IN-HOUSE — file `bizcoach-pro/` đã được add vào `.gitignore` của `bizcity-twin-ai`. KHÔNG publish lên GitHub.

## Vai trò

`bizcoach-pro` là **Producer hub** flagship của Twin AI. Mọi plugin tương lai muốn cung cấp **artifact đầu vào** cho guru (bản đồ coaching, tử vi, lá bài, survey, bài thi, doanh nghiệp profile, ...) sẽ đi qua đây thay vì tự tạo `BizCity_Persona_Tool_Provider` riêng.

Cặp đối xứng:

| Hub | Plugin | Trách nhiệm |
|---|---|---|
| **Producer (VÀO guru)** | `bizcoach-pro` | Template registry, FAQ inputs, Persona Provider, Federation::stamp |
| **Distributor (RA ngoài)** | `bizcity-doc` | Export PDF/MD/HTML share, shortcode landing, channel publish |

## Quan hệ với `bizcoach-map` legacy

`bizcoach-map` (10K LOC, function-based) **GIỮ NGUYÊN** chạy production cho dữ liệu cũ + 8 coach types hardcoded. `bizcoach-pro` viết mới cleanroom, port logic dần (Sprint H-K). Khi parity → Sprint L retire `bizcoach-map`.

## Cấu trúc hiện tại (Sprint G scaffold)

```
bizcoach-pro/
├── bizcoach-pro.php                  Bootstrap (constants + require + hooks)
├── README.md                         File này
├── includes/
│   ├── class-installer.php           DB schema (wp_bcpro_templates, wp_bcpro_artifact_shares) + DB_VERSION
│   ├── class-template-registry.php   In-memory store (slug → template)
│   ├── class-template-loader.php     Boot loader: JSON files → DB overrides
│   ├── class-persona-provider.php    Skeleton — extends BizCity_Persona_Tool_Provider
│   ├── class-intent-provider.php     Skeleton — extends BizCity_Intent_Provider
│   └── class-rest.php                GET /bizcoach-pro/v1/templates[/{slug}]
└── data/
    └── coach-templates/
        └── career_coach.v1.json      Seed template (1 of 8 — others ship Sprint H)
```

## Roadmap

| Sprint | Scope | Files chính |
|---|---|---|
| **G** (current) | Scaffold (đã ship) | bootstrap + 6 class + 1 seed JSON |
| **H** | Foundation (~15h) | Astro Provider interface + 7 JSON seed (biz/baby/mental/tiktok/astro/tarot/health) + DDV section |
| **I** | Producer + FE (~12h) | `tool_create_coach_map()` execute, `render_to_passages()`, Federation::stamp, FE dialog port |
| **J** | Knowledge & Templates (~16h) | Admin Template Builder UI, JSON import/export, knowledge binding cho guru |
| **K** | Funnel handoff to bizcity-doc (~10h) | API contract `BizCity_Doc_Export::request()`, cookbook |
| **L** | Retire bizcoach-map (~6h, optional) | Legacy admin-only mode |

## DDV (sprint H ship)

Sẽ thêm section "Phase F · bizcoach-pro" vào `class-sprint-diagnostic-phase-cd.php` (hoặc sibling) với probe:

- T-BCPRO.a — Persona Provider class loaded + `id() === 'bizcoach_pro'`
- T-BCPRO.b — Template registry count active > 0
- T-BCPRO.c — Each template `schema_version` ≤ supported
- T-BCPRO.d — REST routes `/bizcoach-pro/v1/templates` registered
- T-BCPRO.e — Schema tables `wp_bcpro_templates` + `wp_bcpro_artifact_shares` exist
- T-BCPRO.f — Federation::stamp callsite present (Sprint I gate)
- T-BCPRO.g — `bizcity_artifact_created` action listener attached (Sprint I gate)

## Trạng thái BC

- KHÔNG đụng `bccm_*` table / function / option / page.
- Namespace `bcpro_*` (DB) + `bizcoach-pro/v1/` (REST) tách hoàn toàn.
- Có thể chạy song song `bizcoach-map` mà không xung đột.

## R-NO-CONFLICT contract (PHASE-0.36 §5b — canon Tier 2)

Khi cả hai plugin cùng active, BẮT BUỘC tách rời:

| Loại | bizcoach-map | bizcoach-pro |
|---|---|---|
| Constant | `BCCM_*` | `BCPRO_*` |
| Table prefix | `wp_bccm_*` | `wp_bcpro_*` |
| Option | `bccm_*` | `bcpro_*` |
| Persona id | `bizcoach` | `bizcoach_pro` |
| Source kinds | `astro_natal_chart`, `astro_transit_report` | `coach_map` |
| Tool name | `create_natal_chart`, `create_transit_map`, `bizcoach_consult` | `create_coach_map_<slug>` |
| REST ns | `bizcity/v1/persona/*` | `bizcoach-pro/v1/*` |
| Shortcode | `bccm_*` | `bcpro_*` (Sprint J+) |
| JS handle | `bccm-*` | `bcpro-*` |

Bootstrap có **runtime sentinel** (chạy `plugins_loaded` priority 50, chỉ active khi `WP_DEBUG`): phát hiện collision Persona id / source_kind → `error_log` cảnh báo. Chặn cứng do DDV Phase F (Sprint H ship).

**Mục tiêu cuối**: Sprint L → `mv plugins/bizcoach-map plugins/_archived/bizcoach-map_2026_XX_XX/` không cần rollback DB / option / rewrite.
