# ERROR-UX-SPEC · Explicit Error Messages & Help Dialog Contract

> **Module:** `core/helper`
> **Rule canonical:** [PHASE-0-RULE-ERROR-UX.md](../../../docs/rules/PHASE-0-RULE-ERROR-UX.md)
> **Phiên bản:** v1.0 · 2026-06-04 · **Owner:** Twin AI Core
>
> Tài liệu này là **technical spec** cho R-ERROR-UX — mô tả contract PHP
> (error payload), contract TS (HelpDialogSpec), catalog error code chuẩn,
> và pattern tích hợp `ErrorHelpDialog` vào FE.

---

## 0 · Pre-design audit (R-DA)

| Nguồn | Kết quả |
|---|---|
| `docs/rules/` | Không có rule ERROR-UX trước đây — file này là nguồn gốc. |
| `core/` live code | `StreamErrorBanner.tsx` (7 codes mapped) · `SetupApiKeyDialog.tsx` (pattern proactive health-check) · `automation-rest.php` (`_degraded:true` + `code` string) · `channel-gateway/class-admin-menu.php` (raw `wp_send_json_error('string')` — **anti-pattern cần migrate**). |
| `docs/roadmaps/` | Không có roadmap liên quan. |

**Quyết định:** Xây dựng rule R-ERROR-UX + spec này. KHÔNG tạo bảng mới, KHÔNG tạo REST mới — chỉ chuẩn hoá payload hiện có và bổ sung `help_code` field + catalog.

---

## 1 · Vấn đề hiện tại (audit gap)

### 1.1 Trên PHP (REST response)

| Anti-pattern phổ biến | File điển hình | Vấn đề |
|---|---|---|
| `wp_send_json_error( 'Forbidden', 403 )` | `class-admin-menu.php:295` | String phẳng, FE không map được |
| `wp_send_json_error( 'Invalid data' )` | `class-admin-menu.php:789` | Không có `code`, `hint`, `help_code` |
| `return [ 'ok' => false, '_degraded' => true ]` thiếu `message` | nhiều file | FE hiển thị fallback "Có lỗi xảy ra" |
| `WP_Error('graph_error', $msg)` không có `hint` / `help_code` | automation, channel-gateway | Admin không biết làm gì |
| `error_log()` only — không surface lên FE | cron handlers | User không biết thất bại |

### 1.2 Trên TypeScript/FE

| Anti-pattern | Nơi | Vấn đề |
|---|---|---|
| `console.error('[BizCoachProArtifactDialog]...', e)` | `BizCoachProArtifactDialog.tsx:83` | Lỗi bị nuốt, user thấy spinning |
| `setError(err?.message ?? 'Không tải được...')` | nhiều component | Generic message, không có CTA |
| Không link đến hướng dẫn cụ thể | khắp nơi | User không biết bước tiếp theo |

---

## 2 · PHP Error Payload Contract

Mọi REST endpoint và AJAX handler trong `bizcity-twin-ai` PHẢI trả payload
theo cấu trúc dưới đây khi có lỗi.

### 2.1 Canonical error response (200 + explicit payload)

```php
// ✅ Chuẩn — dùng helper BizCity_Error_Payload::make()
return BizCity_Error_Payload::make(
    code:      'token_invalid',     // string key từ catalog §4
    message:   'Facebook Page Token hết hạn.',
    hint:      'Vào Cài đặt → Facebook → Cấp quyền lại để refresh token.',
    help_code: 'fb_token_expired',  // key tra cứu trong HelpDialog catalog
    context:   [ 'page_id' => $page_id ] // optional debug data
);
```

Output JSON:
```json
{
  "success": false,
  "_degraded": true,
  "code":      "token_invalid",
  "message":   "Facebook Page Token hết hạn.",
  "hint":      "Vào Cài đặt → Facebook → Cấp quyền lại để refresh token.",
  "help_code": "fb_token_expired",
  "context":   { "page_id": "123456" }
}
```

### 2.2 Rules bắt buộc

1. **`code`** — PHẢI là string từ catalog §4. Không được dùng free-text làm code.
2. **`message`** — PHẢI là câu tiếng Việt rõ ràng, nêu SỰ KIỆN gì xảy ra. Tối đa 120 ký tự. KHÔNG được là `"error"`, `"failed"`, `"unknown"`.
3. **`hint`** — PHẢI là câu chỉ dẫn hành động (action-oriented). Bắt đầu bằng động từ: "Vào...", "Kiểm tra...", "Liên hệ...". Có thể `null` nếu không có hành động rõ ràng cho user.
4. **`help_code`** — PHẢI là key tồn tại trong `HelpDialogCatalog` (FE). Nếu lỗi mới chưa có entry, BẮT BUỘC thêm entry vào catalog TRƯỚC.
5. **`_degraded: true`** — PHẢI set khi lỗi là do dependency thiếu/timeout (không phải user error). Cho phép FE graceful fallback.
6. **HTTP 200** — PHẢI trả 200 ngay cả khi lỗi logic (fail-OPEN). Chỉ trả 4xx/5xx cho auth failure và server crash.

### 2.3 Helper class (PHP)

**File:** `core/helper/includes/class-bizcity-error-payload.php`

```php
<?php
/**
 * BizCity_Error_Payload — canonical error response builder.
 *
 * @since 1.0.0
 * @package BizCity_Helper
 */
class BizCity_Error_Payload {
    /**
     * Build a standardised fail-OPEN error array.
     *
     * @param string      $code      Error code from catalog (e.g. 'token_invalid').
     * @param string      $message   Human-readable Vietnamese sentence.
     * @param string|null $hint      Action-oriented instruction for the user.
     * @param string|null $help_code HelpDialog catalog key.
     * @param array       $context   Optional debug key/value pairs (never PII).
     * @return array
     */
    public static function make( $code, $message, $hint = null, $help_code = null, $context = array() ) {
        return array(
            'success'    => false,
            '_degraded'  => true,
            'code'       => (string) $code,
            'message'    => (string) $message,
            'hint'       => $hint ? (string) $hint : null,
            'help_code'  => $help_code ? (string) $help_code : null,
            'context'    => (array) $context,
        );
    }

    /**
     * Build from a WP_Error object.
     *
     * @param WP_Error    $error
     * @param string|null $hint
     * @param string|null $help_code
     * @return array
     */
    public static function from_wp_error( $error, $hint = null, $help_code = null ) {
        $code    = $error->get_error_code();
        $message = $error->get_error_message();
        $data    = $error->get_error_data( $code );
        return self::make( $code, $message, $hint, $help_code, is_array( $data ) ? $data : array() );
    }
}
```

### 2.4 Migration cũ → mới

```php
// ❌ Cũ — xóa dần
wp_send_json_error( 'Invalid data' );
wp_send_json_error( 'Registry not loaded' );

// ✅ Mới
wp_send_json_success( BizCity_Error_Payload::make(
    'invalid_param',
    'Dữ liệu gửi lên không hợp lệ.',
    'Tải lại trang và thử lại. Nếu vẫn lỗi, liên hệ admin.',
    'invalid_param_generic'
) );

wp_send_json_success( BizCity_Error_Payload::make(
    'module_not_loaded',
    'Module Channel Gateway chưa được load.',
    'Kiểm tra plugin bizcity-twin-ai đã activate và không có PHP fatal error.',
    'module_not_loaded'
) );
```

---

## 3 · TypeScript / FE Contract

### 3.1 ErrorPayload type

```typescript
// types/error-payload.ts
export interface ErrorPayload {
  success: false
  _degraded?: boolean
  code: string           // catalog key
  message: string        // Vietnamese human sentence
  hint: string | null    // action-oriented instruction
  help_code: string | null  // HelpDialog catalog key
  context?: Record<string, unknown>  // debug, never PII
}

export function isErrorPayload(v: unknown): v is ErrorPayload {
  return (
    typeof v === 'object' && v !== null &&
    (v as any).success === false &&
    typeof (v as any).code === 'string'
  )
}
```

### 3.2 useErrorHelp hook

```typescript
// hooks/useErrorHelp.ts
import { useCallback } from 'react'
import { openHelpDialog } from '../stores/helpDialogStore'
import type { ErrorPayload } from '../types/error-payload'

export function useErrorHelp() {
  const showHelp = useCallback((payload: ErrorPayload) => {
    if (payload.help_code) {
      openHelpDialog(payload.help_code)
    }
  }, [])
  return { showHelp }
}
```

### 3.3 ErrorHelpBanner component (pattern)

```tsx
// Pattern sử dụng trong mọi component khi hiển thị lỗi:
import { ErrorPayload, isErrorPayload } from '../types/error-payload'
import { StreamErrorBanner } from './StreamErrorBanner'
import { openHelpDialog } from '../stores/helpDialogStore'

function MyComponent() {
  const [error, setError] = useState<ErrorPayload | null>(null)
  // ...
  return error ? (
    <StreamErrorBanner
      code={error.code}
      message={error.message}
      hint={error.hint ?? undefined}
      helpCode={error.help_code ?? undefined}
      onHelp={error.help_code ? () => openHelpDialog(error.help_code!) : undefined}
      onRetry={handleRetry}
      onDismiss={() => setError(null)}
    />
  ) : null
}
```

---

## 4 · Error Code Catalog

> Đây là "single source of truth" cho mọi `code` trong payload.
> FE `HelpDialogCatalog` map từ `help_code` (column cuối) sang nội dung hướng dẫn.

### 4.1 Authentication / Authorization

| code | Mô tả tình huống | hint chuẩn | help_code |
|---|---|---|---|
| `auth_required` | Session hết hạn, cần login lại | Tải lại trang để đăng nhập lại. | `auth_session_expired` |
| `permission_denied` | User không có quyền thực hiện | Liên hệ admin để được cấp quyền. | `permission_denied` |
| `nonce_invalid` | Nonce WordPress hết hạn | Tải lại trang và thử lại. | `nonce_expired` |
| `api_key_missing` | BizCity API key chưa cấu hình | Vào **Cài đặt → BizCity AI** để nhập API key. | `api_key_setup` |
| `api_key_invalid` | API key sai hoặc hết hạn | Kiểm tra lại API key tại dashboard.bizcity.vn. | `api_key_invalid` |

### 4.2 Quota / Rate Limit

| code | Mô tả tình huống | hint chuẩn | help_code |
|---|---|---|---|
| `rate_limited` | Gọi quá nhanh | Đợi 30 giây rồi thử lại. | `rate_limit_general` |
| `quota_exceeded` | Hết hạn mức ngày | Đợi sang ngày mai hoặc nâng gói. | `quota_upgrade` |
| `quota_messages_exceeded` | Hết tin nhắn tháng | Nâng gói để tiếp tục dùng AI chat. | `quota_messages` |

### 4.3 Facebook / Channel Token

| code | Mô tả tình huống | hint chuẩn | help_code |
|---|---|---|---|
| `token_invalid` | Token Facebook/Zalo hết hạn | Vào **Kênh → Facebook → Cấp quyền lại** để refresh token. | `fb_token_expired` |
| `token_scope_missing` | Token thiếu quyền cần thiết | Revoke app → re-auth đầy đủ quyền pages_manage_posts. | `fb_token_scope` |
| `page_not_connected` | Page chưa kết nối | Vào **Kênh → Facebook → Thêm trang** để kết nối. | `fb_page_not_connected` |
| `channel_not_configured` | Kênh chưa cấu hình | Vào **Kênh** và hoàn thành wizard thiết lập kênh. | `channel_setup` |

### 4.4 Module / Service

| code | Mô tả tình huống | hint chuẩn | help_code |
|---|---|---|---|
| `module_not_loaded` | Class PHP chưa load | Kiểm tra plugin đã activate; xem PHP error log. | `module_not_loaded` |
| `gateway_degraded` | Gateway BizCity.vn không trả lời | Dịch vụ AI tạm thời không khả dụng. Thử lại sau vài phút. | `gateway_degraded` |
| `llm_error` | Provider LLM trả lỗi | Thử lại sau vài phút. Admin có thể xem log để biết chi tiết. | `llm_error` |
| `kg_empty` | Knowledge Graph chưa có dữ liệu | Vào **Notebook → Nguồn** và thêm ít nhất 1 tài liệu. | `kg_add_source` |
| `retrieval_error` | Không truy vấn được KG | Kiểm tra kết nối database; xem log PHP. | `kg_retrieval_error` |
| `skill_db_missing` | Skill chưa có trong DB | Vào **Guru → Kỹ năng** và nhập dữ liệu cho skill. | `skill_not_found` |

### 4.5 Data / Validation

| code | Mô tả tình huống | hint chuẩn | help_code |
|---|---|---|---|
| `invalid_param` | Tham số gửi lên không hợp lệ | Tải lại trang và thử lại. Nếu vẫn lỗi, liên hệ admin. | `invalid_param_generic` |
| `invalid_metadata` | Metadata JSON bị hỏng | Xóa sự kiện bị lỗi và tạo lại. | `invalid_metadata` |
| `not_found` | Resource không tồn tại | Kiểm tra ID đúng chưa hoặc resource đã bị xóa. | `not_found` |
| `duplicate` | Đã tồn tại bản ghi trùng | Tìm bản ghi đang có và cập nhật thay vì tạo mới. | `duplicate_record` |

### 4.6 Agent / Automation

> **Catalog đầy đủ cho automation runtime:** [core/automation/docs/AUTOMATION-RUNTIME-ERRORS.md](../../automation/docs/AUTOMATION-RUNTIME-ERRORS.md)

#### 4.6.1 High-level (agent + run lifecycle)

| code | Mô tả tình huống | hint chuẩn | help_code |
|---|---|---|---|
| `twin_agent_exception` | Agent loop gặp exception | Thử lại. Nếu lặp lại, xem log tại **Diagnostics**. | `agent_exception` |
| `automation_run_failed` | Automation workflow thất bại (high-level) | Mở **Automation → Lịch sử** để xem chi tiết lỗi. | `automation_run_failed` |
| `cron_failed` | Cron job thất bại | Xem **Tools → BizCity Cron** → tab Meta để phân tích. | `cron_meta_viewer` |
| `workflow_not_found` | Workflow không tìm thấy / bị xóa | Kiểm tra workflow ID còn tồn tại trong **Automation**. | `workflow_not_found` |
| `automation_run_conflict` | Run đã kết thúc, không thể khởi động lại | Xem **Automation → Lịch sử** để kiểm tra trạng thái run. | `automation_run_failed` |
| `automation_enqueue_failed` | Không thể tạo run vào hàng đợi (DB lỗi) | Kiểm tra bảng `bizcity_automation_runs`; xem PHP error log. | `automation_run_failed` |

#### 4.6.2 Graph / validation errors

| code | Mô tả tình huống | hint chuẩn | help_code |
|---|---|---|---|
| `automation_graph_invalid` | Workflow graph có cycle hoặc node lạc | Mở editor workflow → xóa cạnh tạo vòng tròn → lưu lại. | `automation_graph_invalid` |
| `webhook_token_invalid` | Webhook secret token không khớp | Vào **Automation → Webhook** → copy Secret → cập nhật bên caller. | `automation_webhook_setup` |

#### 4.6.3 Block runtime errors

| code | Mô tả tình huống | hint chuẩn | help_code |
|---|---|---|---|
| `automation_block_error` | Block ném PHP Exception khi thực thi | Mở Automation → Lịch sử → chọn run → xem step lỗi màu đỏ. | `automation_block_error` |
| `automation_block_timeout` | Block vượt quá giới hạn thời gian thực thi | Rút ngắn timeout trong block config, hoặc tối ưu API downstream. | `automation_block_error` |
| `automation_http_error` | action.http_request nhận phản hồi lỗi 4xx/5xx | Kiểm tra URL và credentials. Xem detail step trong Lịch sử. | `automation_block_error` |
| `ssrf_blocked` | URL trỏ IP nội bộ bị chặn (SSRF guard) | Dùng URL public có thể reach từ internet. Không dùng localhost/192.168.x. | `automation_block_error` |
| `automation_email_failed` | action.send_email — wp_mail() trả false | Kiểm tra cấu hình SMTP trong **Cài đặt → BizCity SMTP**. | `automation_block_error` |

---

## 5 · HelpDialog Catalog (FE)

### 5.1 Cấu trúc entry

```typescript
// types/help-dialog-catalog.ts
export interface HelpEntry {
  title: string          // Tiêu đề ngắn
  summary: string        // 1-2 câu mô tả vấn đề
  steps: string[]        // Các bước khắc phục (action-oriented)
  docs_url?: string      // Link đến docs (nếu có)
  video_url?: string     // Link video hướng dẫn (nếu có)
  related?: string[]     // help_code liên quan
}

export const HELP_CATALOG: Record<string, HelpEntry> = {
  'api_key_setup': {
    title: 'Cấu hình BizCity API Key',
    summary: 'Plugin cần API key để kết nối với dịch vụ AI của BizCity.',
    steps: [
      'Truy cập dashboard.bizcity.vn → Tài khoản → API Keys.',
      'Copy key có dạng biz-xxx...',
      'Trở lại WordPress → Cài đặt → BizCity AI → dán key → Lưu.',
      'Nhấn "Kiểm tra kết nối" để xác nhận.',
    ],
    docs_url: 'https://docs.bizcity.vn/setup/api-key',
  },
  'fb_token_expired': {
    title: 'Gia hạn Facebook Page Token',
    summary: 'Token Facebook đã hết hạn hoặc bị thu hồi. Cần cấp quyền lại để tiếp tục đăng bài và nhắn tin.',
    steps: [
      'Vào Channel Gateway → Facebook Pages.',
      'Tìm trang bị lỗi → nhấn "Cấp quyền lại".',
      'Đăng nhập Facebook và chấp nhận đầy đủ quyền.',
      'Kiểm tra lại badge sức khoẻ — phải hiển thị ✅ LIVE.',
    ],
    docs_url: 'https://docs.bizcity.vn/channels/facebook/token-refresh',
    related: ['fb_token_scope'],
  },
  'quota_upgrade': {
    title: 'Nâng cấp gói để tiếp tục',
    summary: 'Bạn đã dùng hết hạn mức AI của gói hiện tại trong ngày hôm nay.',
    steps: [
      'Đợi đến 00:00 ngày mai để quota tự reset.',
      'Hoặc: Vào Tài khoản → Nâng gói để tăng hạn mức ngay lập tức.',
    ],
    docs_url: 'https://docs.bizcity.vn/plans/compare',
    related: ['quota_messages'],
  },
  'gateway_degraded': {
    title: 'Dịch vụ AI tạm thời không khả dụng',
    summary: 'Không thể kết nối đến máy chủ BizCity AI lúc này. Đây thường là sự cố tạm thời.',
    steps: [
      'Thử lại sau 1-2 phút.',
      'Kiểm tra status.bizcity.vn để xem có incident đang diễn ra không.',
      'Nếu lỗi kéo dài trên 10 phút, liên hệ support@bizcity.vn.',
    ],
    docs_url: 'https://status.bizcity.vn',
  },
  'kg_add_source': {
    title: 'Thêm nguồn vào Knowledge Graph',
    summary: 'Notebook này chưa có dữ liệu nào trong Knowledge Graph. AI cần dữ liệu để trả lời câu hỏi.',
    steps: [
      'Nhấn nút "+ Thêm nguồn" trong Smart Sources panel.',
      'Chọn loại nguồn: URL, file PDF, hoặc gõ trực tiếp.',
      'Đợi quá trình index hoàn tất (badge sẽ chuyển sang ✅).',
      'Thử đặt câu hỏi lại.',
    ],
  },
  'cron_meta_viewer': {
    title: 'Xem log chi tiết Cron Job',
    summary: 'Mỗi cron job đều ghi lại meta evidence để bạn phân tích lý do thất bại.',
    steps: [
      'Vào WordPress Admin → Tools → BizCity Cron.',
      'Tìm cron job bị lỗi trong danh sách.',
      'Nhấn "▶ Meta JSON" để xem chi tiết: lý do, mã lỗi, timestamp.',
      'Tìm `reason` trong JSON để biết bước tiếp theo.',
    ],
    related: ['automation_run_failed'],
  },

  // ── PHASE-0.39 Zalo Personal & OA — 5 help_codes ─────────────────────────
  // [2026-06-07 Johnny Chu] PHASE-0.39 R-ERROR-UX — Zalo channel error catalog.

  'zalo_bridge_offline': {
    title: 'Khởi động dịch vụ zca-bridge',
    summary: 'Plugin Zalo Personal/OA cần sidecar Node.js (zca-bridge) đang chạy để nhận và gửi tin nhắn Zalo.',
    steps: [
      'Trên server, chạy: `node dist/main.js` trong thư mục zca-bridge.',
      'Hoặc dùng Docker: `docker compose up -d zca-bridge`.',
      'Vào Channel Gateway → Zalo Cá nhân → tab Cấu hình → nhập Bridge URL (vd: http://localhost:4000) và Bridge Token.',
      'Nhấn "Lưu" rồi quay lại trang tổng quan — badge phải chuyển sang ✅.',
    ],
    docs_url: 'https://docs.bizcity.vn/channels/zalo/bridge-setup',
    related: ['zalo_session_expired'],
  },

  'zalo_qr_expired': {
    title: 'Tạo lại mã QR Zalo',
    summary: 'Mã QR Zalo đã hết hạn (tối đa 60 giây). Cần tạo mã mới và quét ngay.',
    steps: [
      'Vào Channel Gateway → Zalo Cá nhân.',
      'Tìm tài khoản cần đăng nhập lại → nhấn "QR Login".',
      'Nhấn "Tạo mã QR" → mở Zalo trên điện thoại → Quét mã.',
      'Quét trong vòng 60 giây. Nếu hết hạn, nhấn "Tạo lại QR".',
    ],
    docs_url: 'https://docs.bizcity.vn/channels/zalo/personal-login',
    related: ['zalo_session_expired'],
  },

  'zalo_oa_oauth_failed': {
    title: 'Kết nối lại Zalo OA',
    summary: 'Quá trình cấp quyền OAuth cho Zalo Official Account bị thất bại hoặc bị từ chối.',
    steps: [
      'Vào Channel Gateway → Zalo OA.',
      'Tìm OA bị lỗi → nhấn "Kết nối lại".',
      'Đăng nhập tài khoản Zalo có quyền quản lý OA và chấp nhận tất cả quyền được yêu cầu.',
      'Sau khi cấp quyền thành công, badge sẽ chuyển sang ✅ Đã kết nối.',
      'Nếu vẫn lỗi, kiểm tra App ID/Secret trong cấu hình zca-bridge còn hợp lệ.',
    ],
    docs_url: 'https://docs.bizcity.vn/channels/zalo/oa-oauth',
    related: ['zalo_oa_window_closed'],
  },

  'zalo_oa_window_closed': {
    title: 'Cửa sổ CSKH Zalo OA đã đóng',
    summary: 'Khách hàng chưa nhắn tin trong 7 ngày qua. Zalo OA không cho phép gửi tin CSKH khi cửa sổ đã đóng (lỗi -230).',
    steps: [
      'Chờ khách hàng chủ động nhắn tin lại để mở lại cửa sổ 7 ngày.',
      'Hoặc gửi ZNS (Zalo Notification Service) — template được Zalo duyệt — để reach out.',
      'Sau khi khách nhắn tin lại, cửa sổ CSKH sẽ tự mở lại và bạn có thể trả lời.',
    ],
    docs_url: 'https://docs.bizcity.vn/channels/zalo/oa-cskh-window',
    related: ['zalo_oa_oauth_failed'],
  },

  'zalo_session_expired': {
    title: 'Đăng nhập lại Zalo Cá nhân',
    summary: 'Phiên đăng nhập Zalo cá nhân đã hết hạn. Cần quét QR lại để tiếp tục nhận tin.',
    steps: [
      'Vào Channel Gateway → Zalo Cá nhân.',
      'Tìm tài khoản có badge "Hết phiên" → nhấn "Đăng nhập lại".',
      'Nhấn "Tạo mã QR" → mở Zalo trên điện thoại → Quét mã trong 60 giây.',
      'Sau khi kết nối lại, badge chuyển sang ✅ Đã kết nối.',
    ],
    docs_url: 'https://docs.bizcity.vn/channels/zalo/personal-login',
    related: ['zalo_qr_expired', 'zalo_bridge_offline'],
  },

  // ── Automation Runtime Errors — [2026-06-08 Johnny Chu] R-ERROR-UX + AUTOMATION-RUNTIME-ERRORS.md ──

  'automation_run_failed': {
    title: 'Phân tích lỗi Automation Run',
    summary: 'Một automation workflow đã thất bại. Thông tin chi tiết có trong Lịch sử chạy.',
    steps: [
      'Vào WordPress Admin → Automation → Lịch sử.',
      'Tìm run có badge đỏ FAIL.',
      'Nhấn vào run để xem danh sách step.',
      'Tìm step màu đỏ → đọc error message để biết block nào thất bại.',
      'Kiểm tra cấu hình block đó trong workflow editor.',
      'Nhấn "Chạy lại" sau khi sửa để xác nhận.',
    ],
    related: ['automation_block_error', 'cron_meta_viewer'],
  },

  'automation_block_error': {
    title: 'Xử lý lỗi Automation Block',
    summary: 'Một block trong workflow gặp lỗi khi thực thi (exception, HTTP lỗi, hoặc timeout). Lỗi này thường do cấu hình block sai hoặc service phụ thuộc không khả dụng.',
    steps: [
      'Vào Automation → Lịch sử → tìm run FAIL.',
      'Xem danh sách step → tìm step màu đỏ và đọc error message.',
      'Đối với HTTP error: kiểm tra URL đích còn hoạt động và credentials đúng.',
      'Đối với LLM error: kiểm tra API key BizCity và trạng thái gateway.',
      'Đối với Zalo/Facebook error: kiểm tra token kênh còn hạn.',
      'Sửa workflow → Lưu → Chạy thử (nút Test trên toolbar).',
    ],
    related: ['automation_run_failed', 'gateway_degraded'],
  },

  'automation_graph_invalid': {
    title: 'Sửa cấu trúc Workflow Graph',
    summary: 'Workflow graph chứa cycle (vòng tròn) hoặc node cô lập khiến hệ thống không thể xác định thứ tự chạy.',
    steps: [
      'Mở workflow trong Canvas editor.',
      'Nhìn toàn bộ graph — kiểm tra không có cạnh nào tạo thành vòng tròn (A→B→C→A).',
      'Xóa cạnh thừa: nhấn vào cạnh → nhấn Delete.',
      'Xóa node cô lập: node không có cạnh nào kết nối.',
      'Lưu workflow → Chạy thử để xác nhận.',
    ],
  },

  'automation_webhook_setup': {
    title: 'Cấu hình Webhook Token',
    summary: 'Webhook secret token không khớp với caller. Automation từ chối request để bảo vệ an toàn.',
    steps: [
      'Vào Automation → chọn workflow → tab Webhook.',
      'Nhấn "Hiện Secret Token" → copy giá trị.',
      'Cập nhật secret tương ứng trong hệ thống 3rd-party gọi webhook.',
      'Test lại với curl: `curl -X POST <url> -H "X-Bizcity-Token: <secret>" -d \'{}\'`.',
    ],
    docs_url: 'https://docs.bizcity.vn/automation/webhook',
  },

  'workflow_not_found': {
    title: 'Workflow không tìm thấy',
    summary: 'Workflow đã bị xóa hoặc ID không còn tồn tại. Có thể có run đang chờ cho workflow này.',
    steps: [
      'Vào Automation → danh sách workflow → tìm bằng tên hoặc ID.',
      'Nếu đã xóa nhầm: khôi phục từ backup DB (snapshot bizcity_automation_workflows).',
      'Xóa các run queued cho workflow đã xóa: Automation → Lịch sử → filter Status=Queued → xóa.',
    ],
    related: ['automation_run_failed'],
  },
}
```

### 5.2 ErrorHelpDialog component (pattern)

```tsx
// components/ErrorHelpDialog.tsx
import { HelpEntry, HELP_CATALOG } from '../types/help-dialog-catalog'
import { ExternalLink, BookOpen, X } from 'lucide-react'

interface Props {
  helpCode: string
  onClose: () => void
}

export function ErrorHelpDialog({ helpCode, onClose }: Props) {
  const entry: HelpEntry | undefined = HELP_CATALOG[helpCode]

  if (!entry) {
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
        <div className="bg-white rounded-lg p-6 w-full max-w-md shadow-xl">
          <p className="text-sm text-gray-600">
            Không có hướng dẫn cho lỗi này. Liên hệ support@bizcity.vn kèm code: <code>{helpCode}</code>
          </p>
          <button onClick={onClose} className="mt-4 text-xs text-gray-500 underline">Đóng</button>
        </div>
      </div>
    )
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div className="flex items-start gap-3 p-5 border-b">
          <BookOpen size={20} className="mt-0.5 text-blue-600 flex-shrink-0" />
          <div className="flex-1">
            <h2 className="font-semibold text-gray-900">{entry.title}</h2>
            <p className="text-sm text-gray-600 mt-1">{entry.summary}</p>
          </div>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600">
            <X size={18} />
          </button>
        </div>
        <div className="p-5">
          <h3 className="text-xs font-semibold uppercase text-gray-500 mb-2">Các bước khắc phục</h3>
          <ol className="space-y-2">
            {entry.steps.map((step, i) => (
              <li key={i} className="flex gap-2 text-sm">
                <span className="flex-shrink-0 w-5 h-5 rounded-full bg-blue-100 text-blue-700
                                 text-xs flex items-center justify-center font-semibold">
                  {i + 1}
                </span>
                <span dangerouslySetInnerHTML={{ __html: step }} />
              </li>
            ))}
          </ol>
          {entry.docs_url && (
            <a
              href={entry.docs_url}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-4 flex items-center gap-1 text-xs text-blue-600 hover:underline"
            >
              <ExternalLink size={12} /> Xem tài liệu đầy đủ
            </a>
          )}
        </div>
      </div>
    </div>
  )
}
```

---

## 6 · Integration Pattern — StreamErrorBanner nâng cấp

`StreamErrorBanner` hiện có đã có `code` → `Spec` map. Cần bổ sung prop `helpCode` + `onHelp`:

```tsx
// Props mở rộng (không breaking — optional)
interface Props {
  message: string
  code: string | null
  hint?: string       // ← MỚI: hint từ payload PHP
  helpCode?: string   // ← MỚI: kích hoạt nút "Xem hướng dẫn"
  onRetry?: () => void
  onDismiss?: () => void
  onHelp?: () => void  // ← MỚI: callback mở HelpDialog
}

// Trong render:
{onHelp && (
  <button
    onClick={onHelp}
    className={`flex items-center gap-1 border rounded px-2 py-1 text-xs ${TONE_BTN[spec.tone]}`}
  >
    <BookOpen size={12} /> Xem hướng dẫn
  </button>
)}
```

---

## 7 · Checklist khi thêm lỗi mới

```
[ ] 1. Thêm code vào catalog §4 (nếu chưa có).
[ ] 2. Thêm HelpEntry vào HELP_CATALOG §5.1 (nếu chưa có).
[ ] 3. PHP: dùng BizCity_Error_Payload::make() — KHÔNG dùng wp_send_json_error(string).
[ ] 4. TS: check isErrorPayload() trước khi render error.
[ ] 5. Gán helpCode → StreamErrorBanner + onHelp → ErrorHelpDialog.
[ ] 6. Verify: user nhìn thấy message rõ ràng + hint + nút "Xem hướng dẫn".
```

---

## 8 · Anti-patterns CẤM (tham chiếu từ R-ERROR-UX)

| Anti-pattern | Lý do |
|---|---|
| `wp_send_json_error('Something failed')` | String phẳng, FE không parse được code/hint |
| `throw new Exception('error')` không catch lên REST | Exception biến mất, user thấy 500 |
| `console.error(e)` only, không surface lên UI | User không biết thất bại |
| `setError('Có lỗi xảy ra')` generic | User không biết làm gì |
| `_degraded: true` thiếu `message` | FE hiển thị fallback chung |
| `help_code` không có trong HELP_CATALOG | Nút "Xem hướng dẫn" mở ra trang trống |
| Message tiếng Anh cho user thông thường | User không hiểu |
| Lộ stack trace hoặc SQL trong `message` | Security risk (OWASP A05) |
