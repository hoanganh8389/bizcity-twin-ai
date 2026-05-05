# Twinsource — Standard Source Panel for `bizcity-twin-ai`

> **Status**: Wave 0 scaffold (2026-04-29). Component chưa implement, đây là khung thư mục + contract.
> **Spec đầy đủ**: [`PHASE-6.1-TWINSOURCE-STANDARD.md`](../../PHASE-6.1-TWINSOURCE-STANDARD.md)

## Vai trò

`Twinsource` là **component chuẩn** quản lý nguồn (sources) cho **toàn bộ** plugin/module trong `bizcity-twin-ai`. Mỗi khi bạn cần một panel "+ Thêm nguồn / dán URL / list nguồn / chọn nguồn", **PHẢI** dùng `<TwinsourcePanel/>`.

## Kiến trúc

```
PHP host page                              React app (TwinsourcePanel)
─────────────                              ───────────────────────────
Twinsource::render([                       <TwinsourcePanel
   'scope' => [...],                          scope={...}
   'capabilities' => [...],          ───►    capabilities={...}
   'mount_id' => 'foo'                       toggles={...}
])                                          />
   │                                            │
   │ enqueue twinsource.js                      │ fetch
   │ echo <div data-twinsource>                 ▼
   ▼                                  KG Hub REST (bizcity-knowledge/v2/scoped/...)
auto-mount                                      │
                                                ▼
                                  bizcity_kg_sources / _chunks / _entities / _relations
```

## Quickstart (sau Wave 1)

### PHP

```php
twinsource_render([
   'scope'        => [ 'plugin' => 'bzdoc', 'scope_type' => 'document', 'scope_id' => 'doc_42' ],
   'capabilities' => [ 'borrow' => true, 'web_search' => true ],
   'mount_id'     => 'bzdoc-twinsource',
] );
```

### React (trong app riêng đã có Twinsource enqueued)

```tsx
import { TwinsourcePanel } from '@bizcity/twinsource';

<TwinsourcePanel
   scope={{ plugin: 'twinchat', scope_type: 'notebook', scope_id: `tc_${nbId}` }}
   capabilities={{ borrow: true, web_search: true, select_filter: true }}
   toggles={[
      { id: 'use_kg', label: t('Dùng Second Brain'), value: useKG, onChange: setUseKG },
   ]}
/>
```

## Build

```bash
cd modules/twinsource/ui
npm install
npm run build      # → ui/dist/twinsource.js + .css
```

PHP `Twinsource::enqueue()` tự lo handle + version stamp (mtime).

## CẤM

- Tự dựng panel nguồn riêng cho plugin mới.
- Insert thẳng `bizcity_kg_sources` — luôn qua REST KG Hub.
- Tạo bảng `<plugin>_sources` mới.

Xem đầy đủ anti-patterns trong spec.
