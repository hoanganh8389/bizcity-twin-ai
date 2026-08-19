# BizCity 1-API — Client Integration Guide (bizcity-twin-ai)

> **Đọc trước:** [PHASE-0-RULE-GATEWAY-ONLY.md](../rules/PHASE-0-RULE-GATEWAY-ONLY.md) — đặc biệt §R-GW-8.
>
> **Catalog đầy đủ (source of truth) nằm ở plugin hub:**  
> `bizcity-llm-router/docs/api/README.md` — 13 branch + full request/response samples.

---

## 1. Quan hệ to-be

```
Khách hàng (WordPress)                          BizCity Hub Server
┌────────────────────────────────┐              ┌──────────────────────────────┐
│  bizcity-twin-ai (client)      │   Bearer     │  bizcity-llm-router (hub)    │
│  ├─ core/bizcity-llm/          │  biz-xxx     │  ├─ /wp-json/bizcity/v1/*    │
│  │   BizCity_LLM_Client        │  ──────▶     │  ├─ /wp-json/llm/router/v1/* │
│  │   BizCity_Search_Client     │              │  ├─ /wp-json/search/router/* │
│  │   BizCity_Video_Client      │              │  ├─ /wp-json/video/router/*  │
│  │   BizCity_Astro_Client      │              │  ├─ /wp-json/piapi/router/*  │
│  │   BizCity_OCR_Client        │              │  ├─ /wp-json/market/v1/*     │
│  │   BizCity_AV_Transcribe_*   │              │  │                            │
│  │   bizcity_llm_google_*      │              │  └─ Provider keys (server-only)│
│  │                              │              │     OpenRouter · Tavily ·    │
│  └─ Proxy REST                  │              │     Kling · PiAPI · FAA ·    │
│      /wp-json/bizcity-twin*/v1/*│              │     Google OAuth · PayPal    │
└────────────────────────────────┘              └──────────────────────────────┘
       ▲                                                      ▲
       │ FE (TS) cùng-origin, X-WP-Nonce                       │ Provider APIs
       │                                                       │ (HTTPS keyed)
```

**Quy tắc bất di:** FE **CẤM** gọi thẳng `bizcity.vn`. Mọi route gateway cần FE → tạo proxy REST cùng origin (xem `modules/twinchat/includes/class-twinchat-entitlement-proxy.php` làm mẫu).

---

## 2. 13 nhánh API hub cung cấp

| # | Branch | Helper Client (PHP) | Spec đầy đủ |
|---|---|---|---|
| 1 | LLM Chat / Embeddings | `BizCity_LLM_Client` | [01-llm.md](../../../bizcity-llm-router/docs/api/01-llm.md) |
| 2 | LLM Tools (OCR · Transcribe) | `BizCity_OCR_Client`, `BizCity_AV_Transcribe_Client` | [02-llm-tools.md](../../../bizcity-llm-router/docs/api/02-llm-tools.md) |
| 3 | Search (Tavily) | `BizCity_Search_Client` | [03-search.md](../../../bizcity-llm-router/docs/api/03-search.md) |
| 4 | Video (Kling · Runway) | `BizCity_Video_Client` | [04-video.md](../../../bizcity-llm-router/docs/api/04-video.md) |
| 5 | PiAPI (Faceswap · VTO) | ⚠️ TODO — tạm `wp_remote_post` + Bearer | [05-piapi.md](../../../bizcity-llm-router/docs/api/05-piapi.md) |
| 6 | Image Generation | `BizCity_LLM_Client::generate_image()` | [06-image.md](../../../bizcity-llm-router/docs/api/06-image.md) |
| 7 | Astrology (FAA · Vedic · BaZi) | `BizCity_Astro_Client` | [07-astrology.md](../../../bizcity-llm-router/docs/api/07-astrology.md) |
| 8 | Rerank | ⚠️ TODO — tạm `wp_remote_post` | [08-rerank.md](../../../bizcity-llm-router/docs/api/08-rerank.md) |
| 9 | Account · Billing · Entitlement | `BizCity_LLM_Client::get_entitlement/balance()` | [09-account-billing.md](../../../bizcity-llm-router/docs/api/09-account-billing.md) |
| 10 | **Google OAuth Hub** ⭐ NEW v1.9.0 | `bizcity_llm_google_*` (procedural) | [10-google-oauth.md](../../../bizcity-llm-router/docs/api/10-google-oauth.md) |
| 11 | Marketplace (auto-update) | (admin updater) | [11-marketplace.md](../../../bizcity-llm-router/docs/api/11-marketplace.md) |
| 12 | QR Templates Library | (bizcity-tool-image) | [12-qr-templates.md](../../../bizcity-llm-router/docs/api/12-qr-templates.md) |
| 13 | **Image Template Library + Editor Assets + Bundle** ⭐ NEW v1.10.0 | ⚠️ TODO — `BizCity_Image_Template_Sync` (1-button "Update Templates"; 2-nhóm source=`hub`/`local` + `protected_from_sync`; ETag manifest + bundle endpoint) | [13-image-templates.md](../../../bizcity-llm-router/docs/api/13-image-templates.md) |

---

## 3. Cấu hình client — 2 site option duy nhất

```php
// Settings → BizCity AI Hub
update_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' );   // mặc định
update_option( 'bizcity_llm_api_key',     'biz-xxxxxxxxxxxxxxxx' ); // BizCity issue
```

Lấy key tại: `https://bizcity.vn/wp-admin/admin.php?page=bizcity-account` (sau khi đăng ký + topup).

---

## 4. Pattern dùng client wrapper (PHP server-side)

```php
// ✅ ĐÚNG — guard + fail-graceful + dùng wrapper
if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
    return [ 'success' => false, '_degraded' => true, 'message' => 'LLM client missing.' ];
}
$llm = BizCity_LLM_Client::instance();
if ( ! $llm->is_ready() ) {
    return [ 'success' => false, '_degraded' => true, 'message' => 'API key chưa cấu hình.' ];
}
$resp = $llm->chat( $messages, [ 'purpose' => 'reasoning' ] );
```

```php
// ❌ SAI — gọi thẳng provider domain (vi phạm R-GW)
wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [...] );

// ❌ SAI — class chỉ tồn tại bên server router
BizCity_Router_Proxy::generate_image( $prompt, $opts );
```

---

## 5. Pattern proxy FE → gateway (R-GW-8)

```php
// modules/<feature>/includes/class-<feature>-proxy.php
register_rest_route( 'bizcity-<feature>/v1', '/<endpoint>', [
    'methods'             => 'GET',
    'permission_callback' => 'is_user_logged_in',
    'callback' => function( $req ) {
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            return rest_ensure_response([ '_degraded' => true, 'tier' => 'free', 'bypass' => true ]);
        }
        $llm = BizCity_LLM_Client::instance();
        if ( ! $llm->is_ready() ) {
            return rest_ensure_response([ '_degraded' => true, 'tier' => 'free', 'bypass' => true ]);
        }
        return rest_ensure_response( $llm->some_method( $req->get_params() ) );
    },
] );
```

FE TS:
```ts
const NS = '/wp-json/bizcity-<feature>/v1/'
fetch(NS + '<endpoint>', { headers: { 'X-WP-Nonce': window.wpApiSettings.nonce } })
  .then(r => r.json())
  .then(data => {
    if (data._degraded) console.warn('Gateway degraded:', data._degraded)
    // ... use data anyway (synthetic fallback)
  })
```

**Fail-OPEN rule:** Proxy LUÔN trả 200 + synthetic payload + `_degraded` thay vì 4xx/5xx → FE không retry-loop, vẫn render được UI fallback.

---

## 6. Use case mapping

| Module bizcity-twin-ai | Sử dụng branch | Wrapper |
|---|---|---|
| `modules/twinchat` | LLM (chat/stream), Search, Account/Entitlement | LLM_Client, Search_Client |
| `modules/twincoach` | LLM (reasoning), Astrology | LLM_Client, Astro_Client |
| `modules/twinsearch` | Search, Rerank, LLM (summarize) | Search_Client, LLM_Client |
| `core/knowledge/kg-hub` | LLM Tools (OCR, Transcribe), Embeddings, Search | OCR_Client, AV_Transcribe_Client, LLM_Client |
| `core/scheduler` | **Google OAuth Hub** (NEW v1.9.0), LLM (gợi ý lịch) | `bizcity_llm_google_*`, LLM_Client |
| `core/channel-gateway/fb-publisher` | LLM (gen content) | LLM_Client |
| `core/twinbrain` (Twin Event Engine) | LLM, Rerank, Search | LLM_Client |
| `bizcity-tool-image` (sub-plugin) | Image Generation, PiAPI, QR Templates | LLM_Client::generate_image() |
| `bizcity-video-kling` (sub-plugin) | Video | Video_Client |

---

## 7. Roadmap — wrappers cần build

| Wrapper | Branch | Ưu tiên |
|---|---|---|
| `BizCity_PiAPI_Client` | PiAPI | Medium (đã có Video_Client tham khảo) |
| `BizCity_Rerank_Client` | Rerank | Low (chỉ Twin Event Engine dùng) |
| `BizCity_Marketplace_Client` | Marketplace | Low (admin only) |
| `BizCity_QR_Templates_Client` | QR Templates | Low (chỉ bizcity-tool-image cần) |

---

## 8. References

- **Catalog full spec:** `bizcity-llm-router/docs/api/README.md`
- **Architecture rule:** `docs/rules/PHASE-0-RULE-GATEWAY-ONLY.md`
- **Canon index:** `docs/PHASE-0-CANON.md`
- **Proxy reference:** `modules/twinchat/includes/class-twinchat-entitlement-proxy.php`
- **Bootstrap helpers:** `core/bizcity-llm/bootstrap.php`
