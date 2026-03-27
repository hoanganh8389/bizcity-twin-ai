# bizcity-tool-woo — Plugin Change Log

> **Role**: BizCity SDK Tool Plugin — WooCommerce Agent
> **Category**: Business / WooCommerce
> **Platform Log**: [bizcity-intent/SYSTEM-LOG.md](../../mu-plugins/bizcity-intent/SYSTEM-LOG.md)
> **Architecture**: [ARCHITECTURE.md](../../mu-plugins/bizcity-intent/ARCHITECTURE.md)
> **Roadmap Phase**: Phase 10 — Pipeline Orchestration + Tool Registry

---

## Plugin Status

| Item | Status |
|------|--------|
| Scaffold created | ✅ 2026-03-03 |
| Intent Provider registered | ✅ `bizcity_intent_register_providers` |
| Tool callbacks coded | ✅ 11 tools |
| `bizcity_intent_tools_ready` hook fires | ⏳ Blocked — Known Issue #11 |
| Tool name conflict resolved | ⏳ Cần namespace thành `woo_*` |
| INTENT-SKELETON.md | ⏳ |
| Production active | ⏳ Chờ Phase 10 engine fix |

---

## Tools trong Plugin

| Tool Name | Callback | Wraps |
|-----------|----------|-------|
| `create_product` | `BizCity_Tool_Woo_Products::create_product` | `twf_handle_product_post_flow()` |
| `edit_product` | `BizCity_Tool_Woo_Products::edit_product` | `twf_handle_edit_product_flow()` |
| `create_order` | `BizCity_Tool_Woo_Orders::create_order` | `twf_handle_create_order_ai_flow()` |
| `list_orders` | `BizCity_Tool_Woo_Orders::list_orders` | WooCommerce query |
| `find_customer` | `BizCity_Tool_Woo_Orders::find_customer` | `twf_handle_find_customer_order_by_phone()` |
| `generate_report` | `BizCity_Tool_Woo_Reports::generate_report` | `twf_get_order_stats_range()` |
| `product_stats` | `BizCity_Tool_Woo_Reports::product_stats` | `twf_bao_cao_top_product()` |
| `customer_stats` | `BizCity_Tool_Woo_Reports::customer_stats` | `twf_bao_cao_top_customers()` |
| `inventory_report` | `BizCity_Tool_Woo_Inventory::inventory_report` | WooCommerce stock query |
| `inventory_journal` | `BizCity_Tool_Woo_Inventory::inventory_journal` | WooCommerce orders |
| `warehouse_receipt` | `BizCity_Tool_Woo_Inventory::warehouse_receipt` | `twf_parse_phieu_nhap_kho_ai()` |

---

## Pipeline I/O

```
Input  ← $slots.title, $slots.price, $slots.image_url (từ generate_image step)
Output → data.id (WC product/order ID), data.url, data.title, data.type='woo_product'
```

**Pipeline chains:**
- `generate_image → data.image_url → create_product (image_url)`
- `generate_report → data.content → write_article (topic)`
- `find_customer → data.meta.phone → create_order (phone)`

---

## Backlog

```
CRITICAL (Phase 10):
  [ ] Rename tools thành namespace: woo_create_product, woo_create_order, ...
  [ ] engine fire bizcity_intent_tools_ready (xem bizcity-intent backlog)

HIGH:
  [ ] INTENT-SKELETON.md
  [ ] WooCommerce active check graceful error
  [ ] Test pipeline end-to-end

MEDIUM:
  [ ] Admin menu tab riêng trong WP Admin
  [ ] Profile context: VIP customer history inject vào build_context()
```

---

## Change Log

### 2026-03-03
- Plugin scaffold tạo bởi BizCity Platform session — 9 files, 11 tools
- Dependency: `bizcity-admin-hook` mu-plugin (tất cả `twf_*` functions)
- Status: Scaffold complete, chờ Phase 10 engine implementation

### 2026-03-07 — Tool Input Meta & Context Injection
- **[class-tools-woo.php]** 4 tool callbacks updated to receive `$slots['_meta']` with 6-layer dual context:
  - `create_product()`: `$ai_context` appended to `$sys_prompt` (Pattern A)
  - `edit_product()`: `$ai_context` appended to `$sys_edit` (Pattern A)
  - `create_order()`: `$ai_context` appended to `$sys_order` (Pattern A, fallback path)
  - `warehouse_receipt()`: `$ai_context` appended to `$sys_warehouse` (Pattern A, fallback path)
- Remaining 7 tools are data-only (Pattern C) — `_meta` available in `$slots` but no AI calls to inject
- No breaking changes — all `_meta` access uses `?? []` / `?? ''` defaults

---

*Ref: [bizcity-intent SYSTEM-LOG.md](../../mu-plugins/bizcity-intent/SYSTEM-LOG.md) — Issue #11, #12, #13*
