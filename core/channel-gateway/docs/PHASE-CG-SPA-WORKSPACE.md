# PHASE CG-SPA-WORKSPACE — Per-Platform Workspace UX cho Channel Gateway SPA

> **Status:** v0.1 · 2026-05-21 · **Owner:** Channel Gateway team
> **Indexed in:** [PHASE-0-CANON.md](../../PHASE-0-CANON.md) Cross-walk (Tier 1 · R-CH)
> **Effective:** ngay khi tất cả task table dưới đây PASS trong [Sprint Diagnostic](../../wp-admin/tools.php?page=bizcity-channel-gateway-sprint-diag) §"Sprint CG-SPA".
> **Pre-req rules:** R-CH (`PHASE-0-RULE-CHANNEL-ONLY.md`), R-DDV (`PHASE-0-RULE-DIAGNOSTIC-DRIVEN-VALIDATION.md`), R-NS, R-1API.

---

## 1 · Mục tiêu

Side-nav SPA cũ gộp chung tất cả tài khoản vào 1 bảng phẳng — operator phải lọc tay theo platform mỗi lần. UX mới: **mỗi platform là 1 workspace độc lập** với 4 sub-page riêng (Overview / Settings / Test / Webhook Logs). Top-level chỉ giữ `Bảng điều khiển`, `Sức khỏe tổng thể`, `Thêm kênh`, `Cài đặt`.

```
SIDE-NAV (v2)
├── Bảng điều khiển          /
├── Sức khỏe tổng thể        /health
├── Thêm kênh mới            /add
├── ────────  Mạng xã hội  ────────
│   ├─ ⬛ Facebook Page         /p/facebook_page
│   │    ├─ Tổng quan          /p/facebook_page
│   │    ├─ Cấu hình           /p/facebook_page/settings
│   │    ├─ Test gửi tin        /p/facebook_page/test
│   │    └─ Webhook logs       /p/facebook_page/logs
│   ├─ ⬛ Facebook Messenger    /p/facebook_messenger/...
│   ├─ ⬛ Zalo Bot OA           /p/zalo_bot/...
│   ├─ ⬛ Zalo BizCity Hotline  /p/zalo_hotline/...
│   └─ ⬛ Telegram              /p/telegram/...
├── ────────  Website / Nội bộ  ────────
│   ├─ ⬛ WebChat               /p/webchat/...
│   └─ ⬛ Admin Chat            /p/adminchat/...
├── ────────  Email  ────────
│   └─ ⬛ Email SMTP            /p/email_smtp/...
├── ────────  Google Tools (Phase 2)  ────────  [disabled]
│   ├─ Gmail                   (—)
│   ├─ Google Calendar         (—)
│   └─ Google Drive            (—)
└── Cài đặt chung              /settings
```

Mỗi workspace dùng chung `PlatformWorkspace` shell (header platform + tab strip + outlet).

---

## 2 · Task table  (R-DDV: 1 row ↔ 1 probe trong Sprint Diag)

| ID            | Task                                                                                                                                                                            | Status |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| **T-CG-SPA.1** | Build artifacts present (`assets/dist/channel-gateway-app.js` + `.css` exist, filesize > 50 KB) — pre-req cho mọi probe khác                                                     | PASS   |
| **T-CG-SPA.2** | REST route `GET /bizcity-channel/v1/logs` được đăng ký, perm `manage_options`, accept query `?platform=&instance_id=&days=&limit=`                                              | PASS   |
| **T-CG-SPA.3** | Server-side `BizCity_Channel_REST_API::list_logs()` proxy `BizCity_Webhook_Log::query(['platform'=>strtoupper, 'days'=>min(7,n), 'limit'=>min(200,n)])` — verify bằng loopback dispatch | PASS   |
| **T-CG-SPA.4** | Boot payload `BIZCITY_CG_BOOT.platforms` có flag `ready` đúng theo registry (`BizCity_Integration_Registry::get($code)` ≠ null cho built-in 7 platform; Phase-2 Google = `false`) | PASS   |

→ Tất cả PHẢI **PASS** trong [Sprint Diag](/wp-admin/tools.php?page=bizcity-channel-gateway-sprint-diag) §"Sprint CG-SPA" trước khi tick xong workspace.

---

## 3 · BE — REST surface bổ sung

```
GET /wp-json/bizcity-channel/v1/logs
  ?platform=FACEBOOK          (uppercase, optional)
  &instance_id=12345          (optional; client-side filter)
  &days=3                     (1..7, default 3)
  &limit=100                  (1..200, default 100)
  &verify_status=verified     (optional pass-through)
  &http_min=200&http_max=299  (optional pass-through)

Response 200:
{
  "success": true,
  "filters": { platform, instance_id, days, limit, verify_status, http_min, http_max },
  "count":   42,
  "logs":    [ { id, log_date, platform, endpoint, method, http_status,
                 verify_status, latency_ms, remote_ip, character_id,
                 channel_message_id, is_replay, created_at, error } ]
}
```

- **Implementation**: thêm method `list_logs(WP_REST_Request)` vào `BizCity_Channel_REST_API`.
- **Perm**: `manage_options`.
- **Reuse**: gọi `BizCity_Webhook_Log::query()` (đã có), KHÔNG tự đọc file-system.
- **No schema change** → không cần update changelog DB JSON (theo copilot-instructions §Diagnostics Changelog rule).

---

## 4 · FE — Workspace topology

### 4.1 Routes mới
```
/                              Overview (giữ)
/health                        HealthRoute (giữ)
/add  |  /add/:code            AddChannelRoute (giữ)
/settings                      SettingsRoute (giữ)

/p/:platform                   PlatformOverview     ← NEW
/p/:platform/settings          PlatformSettings     ← NEW
/p/:platform/test              PlatformTest         ← NEW
/p/:platform/logs              PlatformLogs         ← NEW
```

`:platform` = key trong `BOOT.platforms[].code` (vd `facebook_page`).

### 4.2 Files mới
```
src/shell/PlatformWorkspace.jsx     shell: header + tabs + <Outlet/>
src/routes/platform/Overview.jsx    KPI accounts + last events
src/routes/platform/Settings.jsx    bảng accounts + drawer edit (extract từ ChannelsRoute cũ)
src/routes/platform/Test.jsx        playground bind sẵn platform + chọn account
src/routes/platform/Logs.jsx        bảng webhook logs (RTK Query)
```

### 4.3 RTK Query bổ sung
- `getLogs({ platform, days, limit, instance_id })` → tag `Logs`.

### 4.4 SideNav v2
- `NAV_GROUPS` chia thành `top` + `bottom`. Giữa 2 nhóm = danh sách groups platform (`social`, `web`, `email`, `google`) sinh từ `BOOT.platforms`.
- Mỗi platform là 1 `<details>` collapsible với 4 link con. Active route highlight cha + con.

---

## 5 · Diagnostic wiring (R-DDV bắt buộc)

`core/channel-gateway/includes/class-sprint-diagnostic.php` thêm:

```php
echo '<h2>Sprint CG-SPA · Per-Platform Workspace</h2>';
echo '<table class="widefat striped">...';
$this->check_t_cg_spa_1();   // built bundles + filesize
$this->check_t_cg_spa_2();   // REST route registered
$this->check_t_cg_spa_3();   // loopback GET /logs
$this->check_t_cg_spa_4();   // BOOT platforms ready flag matrix
echo '</tbody></table>';
```

→ Mỗi check dùng `$this->task_row( 'T-CG-SPA.x', $status, $check, $evidence )`. Evidence phải include file path, bytes, route URL, count, etc. — KHÔNG dùng "implemented".

---

## 6 · Changelog discipline (R-CANON §5)

- **Rule này không tự bump schema DB** → không cần touch `core/diagnostics/changelog/*.json` (chỉ FE + REST add-only).
- **Rule pinned**: R-CH (`PHASE-0-RULE-CHANNEL-ONLY.md`) — vì thêm route mới vào `bizcity-channel/v1`. Khi merge: ghi 1 dòng `2026-05-21 · Channel GW · GET /logs route + per-platform workspace SPA · PR #—` vào CHANGELOG cuối `PHASE-0-RULE-CHANNEL-ONLY.md`.
- **R-DDV**: 4 probe ở §5 phải PASS trước khi đóng task.

---

## 7 · Roadmap kế tiếp (Phase 2)

| Wave | Task |
|------|------|
| W1   | OAuth wizard cho Gmail / Calendar / Drive (replace "Coming soon") |
| W2   | Webhook log viewer: filter advanced (verify_status, http range, regex error) + replay button |
| W3   | Per-platform analytics: msg in/out 24h, latency p95, fail rate, top errors |
| W4   | Bulk action (test all, disable all, rotate token) trong PlatformSettings |
| W5   | Inline channel-role assignment UI ở PlatformSettings |

---

## 8 · CHANGELOG (chính file này)

| Date       | Author                | Change                                                                                                  |
| ---------- | --------------------- | ------------------------------------------------------------------------------------------------------- |
| 2026-05-21 | Channel Gateway team  | v0.1 — Spec per-platform workspace + REST `/logs` + diagnostic rows T-CG-SPA.1..4. Code shipped same day. |
