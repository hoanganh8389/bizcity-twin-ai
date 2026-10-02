# Zalo Personal — Session & Error Contract v1 (`zalo-personal-session-errors@1.0.0`)

> Ban hành: 2026-09-18 · Rule: **R-ZP-ERR** (+ R-ZP-DUP, R-ERROR-UX, R-ACTION-SHEET) · Owner: `plugins/bizcity-zalo-personal`
> Nguồn chuẩn duy nhất (SoT): [`class-zalo-session-errors.php`](../../plugins/bizcity-zalo-personal/includes/shared/class-zalo-session-errors.php)
> Bản đọc máy: `GET /wp-json/bizcity-channel/v1/zalo-bridge/error-catalog` · Mirror client B2: [`frontend/src/lib/zaloSession.js`](../../plugins/bizcity-twin-crm/frontend/src/lib/zaloSession.js)
> Đồng bộ doc ↔ code được khoá bởi `tests/unit/ZaloSessionErrorsContractTest.php`.

## 1. Vì sao có contract này

Một lỗi Zalo Cá nhân đi qua **3 tầng** trước khi tới người dùng:

```
zca sidecar (Node)  →  Router / Hub (bizcity-llm-router, Branch 19)  →  Site (bizcity-zalo-personal)  →  Client (/crm/, /gpt/, wp-admin)
   error: qr_failed        code / reason_bucket / message / hint          reason_bucket + message + hint + action      in câu + nút
```

Trước 2026-09-18 mỗi tầng tự đặt tên, site gom mọi mã lạ về `qr_response_empty` + một câu chung, và mỗi màn hình client tự viết nhãn trạng thái riêng (5 bảng nhãn khác nhau trong CRM). Hậu quả: "trùng SĐT", "Hub timeout", "API key sai domain" đều hiện *"Chưa tạo được mã QR"* (sự cố live 0931576886).

Contract này quy định: **router/sidecar trả mã gì → site gom về bucket nào → client in câu gì, gợi ý nút gì.**

## 2. Envelope lỗi (mọi route Zalo Cá nhân của site)

HTTP luôn `200` cho lỗi nghiệp vụ (R-ZP-3 fail-open, không retry-loop). Client đọc `ok`.

```json
{
  "ok": false,
  "success": false,
  "code": "qr_operation_failed",
  "reason_bucket": "duplicate_zalo_login",
  "operation_status": "blocked",
  "message": "Số Zalo này đang kết nối ở tài khoản khác: «0931576886» (#12). Đăng nhập QR ở đây sẽ đẩy tài khoản kia ra.",
  "hint": "Hai tài khoản đang trùng một SĐT. Dùng tài khoản đang kết nối và xoá tài khoản trùng này (lịch sử CRM vẫn được giữ).",
  "action": "delete_duplicate",
  "action_label": "Xoá tài khoản trùng",
  "help_code": "zalo_duplicate_account",
  "stage": "account_scope",
  "upstream_code": "qr_failed | unrecognized:1a2b3c4d | ''",
  "duplicate_of": { "bridge_account_id": "12", "status": "connected" },
  "operation_id": "qr_…", "request_id": "wp_…",
  "contract": "zalo-personal-session-errors@1.0.0"
}
```

| Field | Bắt buộc | Ý nghĩa cho client |
|---|---|---|
| `ok` | ✔ | `false` = thất bại nghiệp vụ. Không suy từ HTTP status. |
| `reason_bucket` | ✔ | Khoá ổn định để phân nhánh UI (bảng §4). Không bao giờ là văn bản từ provider. |
| `message` | ✔ | Câu in cho người dùng. **Luôn in nguyên văn.** Có thể cụ thể hơn catalog (ví dụ nêu tên tài khoản trùng). |
| `hint` | ✔ | Việc cần làm tiếp. In ngay sau `message`. |
| `operation_status` | ✔ | `blocked` = người dùng/quản trị phải đổi gì đó, **không** tự retry. `degraded` = tạm thời, được retry có backoff. |
| `action` | ✔ (từ v1) | Nút gợi ý (bảng §5). Giá trị lạ ⇒ không vẽ nút. |
| `action_label` | – | Nhãn nút mặc định; client có thể dùng nhãn riêng cùng nghĩa. |
| `upstream_code` | – | Mã gốc từ router/sidecar **chỉ khi thuộc danh sách đã biết**; mã lạ ⇒ `unrecognized:<hash8>` (R-ZP-DUP-5). Dùng cho hỗ trợ, không in cho người dùng. |
| `duplicate_of` | – | Có khi `reason_bucket` ∈ {`duplicate_phone`, `duplicate_zalo_login`}: tài khoản đang giữ SĐT. |
| `operation_id`, `request_id` | – | Tương quan log (`zalo_personal` channel log, trace `qr_operation_failed.upstream`). |
| `contract` | – | Phiên bản contract đã áp. |

Envelope thành công của QR: `{ ok: true, operation_status: "ready", qr_base64, reason_bucket: "qr_generated", rebind_pending }`.

## 3. Quy tắc cho client (B2 `/crm/`, C `/gpt/`, wp-admin, app)

1. **In `message` + `hint` của server.** Cấm tự dựng câu từ `code`/`reason_bucket`/`upstream_code` (ngoại lệ: server không trả `message` ⇒ dùng câu của catalog theo `reason_bucket`).
2. **Không im lặng**: HTTP 200 + `ok:false` phải hiện lỗi (R-ZP-DUP-6). Không gọi `onSaved`/đóng sheet khi `ok:false`.
3. **Nút theo `action`** (§5). `blocked` ⇒ không tự retry; `degraded` ⇒ có thể retry tay, tối đa 1 lần tự động (QR hết hạn — xem §6).
4. **Nhãn trạng thái phiên chỉ lấy từ §4.2** (qua `lib/zaloSession.js` ở B2). Cấm bảng nhãn cục bộ.
5. **Chỉ mời QR khi `can_relogin = true`.** `duplicate` và `revoked` **không bao giờ** có nút QR.
6. Hiển thị `request_id` (nhỏ, có nút sao chép) khi `operation_status = degraded` để người dùng gửi hỗ trợ.

## 4. Catalog

### 4.1 Lỗi (`reason_bucket`)

Nguồn: `site` = kiểm tra tại website · `hub` = router `bizcity-llm-router` (`class-router-zalo-personal-bridge-rest.php`, `BizCity_LLM_Client::gateway_*`) · `sidecar` = zca-bridge (`qrErrors.ts`, `wpRoutes.ts`).
Xuất hiện ở: `create` (tạo tài khoản) · `qr` (tạo/reset QR) · `status` (poll trạng thái) · `any`.

| `reason_bucket` | Nguồn | Xuất hiện ở | `operation_status` | `action` | Thông báo (`message`) | Gợi ý (`hint`) | Mã upstream gom vào (`aliases`) |
|---|---|---|---|---|---|---|---|
| `duplicate_phone` | site | create | blocked | `use_existing` | SĐT này đã có tài khoản Zalo Cá nhân trên website. | Dùng tài khoản đã có; nếu nó đã đăng xuất thì bấm "Đăng nhập lại" trên tài khoản đó thay vì tạo mới. | `duplicate_phone` |
| `duplicate_zalo_login` | site | qr | blocked | `delete_duplicate` | Số Zalo này đang kết nối ở tài khoản khác; đăng nhập QR ở đây sẽ đẩy tài khoản kia ra. | Dùng tài khoản đang kết nối và xoá tài khoản trùng này (lịch sử CRM vẫn được giữ). | `duplicate_zalo_login` |
| `already_connected` | sidecar | qr | blocked | `none` | Tài khoản Zalo đã kết nối, không cần tạo mã QR mới. | Mở trạng thái tài khoản hoặc ngắt kết nối trước khi tạo mã QR mới. | `already_connected` |
| `qr_in_progress` | sidecar | qr | blocked | `close_other_qr` | Đang có một mã QR khác chờ quét cho tài khoản này. | Đóng cửa sổ QR khác (hoặc đợi khoảng 1 phút cho mã cũ hết hạn) rồi thử lại. | `qr_in_progress` |
| `qr_expired` | sidecar | qr | degraded | `relogin_qr` | Mã QR đã hết hạn. | Bấm "Tạo lại mã QR" và quét trong vòng 1 phút. | `qr_expired` |
| `qr_declined` | sidecar | qr | degraded | `relogin_qr` | Đăng nhập QR đã bị từ chối trên điện thoại. | Tạo mã mới và bấm "Đăng nhập" trên Zalo điện thoại. | `qr_declined` |
| `qr_failed` | sidecar | qr | degraded | `retry_later` | Zalo không tạo được mã QR lúc này. | Thử lại sau ít phút. Nếu SĐT này vừa đăng nhập ở tài khoản khác, dùng tài khoản đó thay vì tạo mã mới. | `qr_failed`, `qr_session_start_failed`, `sidecar_session_failed`, `qr_response_invalid`, `invalid_json`, `mapping_failed` |
| `qr_response_empty` | site | qr | degraded | `retry_later` | Chưa tạo được mã QR đăng nhập Zalo Cá nhân. | Kiểm tra trạng thái bridge và thử tạo mã QR lại sau ít phút. | `qr_response_empty` |
| `managed_account_other_site` | hub | any | blocked | `relogin_qr` | Tài khoản Zalo này đang nhận tin tại website khác. | Bấm "Đăng nhập lại" và quét QR tại website này để chuyển tài khoản về đây. | `managed_account_other_site` |
| `key_domain_mismatch` | hub | any | blocked | `contact_admin` | API key của website này chưa gắn đúng domain nên chưa dùng được Zalo Cá nhân ở đây. | Quản trị viên gắn domain website cho API key (hoặc dùng API key riêng) rồi đăng nhập QR lại. | `key_domain_mismatch`, `invalid_metadata` |
| `account_not_owned` | hub | any | blocked | `delete_and_recreate` | Tài khoản Zalo này không còn thuộc API key của website (có thể đã bị xoá ở máy chủ). | Xoá tài khoản này trong danh sách rồi tạo lại bằng SĐT đó. | `account_not_owned`, `managed_account_not_owned`, `account_not_found`, `mapping_missing`, `not_found`, `sidecar_account_missing` |
| `bridge_not_configured` | hub | any | blocked | `contact_admin` | Website chưa được cấp quyền dùng Zalo Cá nhân (API key chưa hợp lệ hoặc bridge chưa bật). | Quản trị viên kiểm tra API key BizCity của website và trạng thái Zalo bridge. | `bridge_not_configured`, `managed_bridge_not_configured`, `managed_client_missing`, `api_key_missing`, `no_api_key`, `llm_client_missing`, `key_inactive` |
| `feature_not_enabled` | hub | any | blocked | `upgrade_plan` | Gói hiện tại chưa cho phép dùng Zalo Cá nhân. | Nâng cấp gói hoặc nhờ quản trị viên bật Zalo Cá nhân cho website. | `feature_not_enabled`, `plan_missing` |
| `account_limit_reached` | hub | create | blocked | `upgrade_plan` | Website đã dùng hết số tài khoản Zalo Cá nhân của gói. | Xoá một tài khoản không dùng (kể cả tài khoản trùng) hoặc nâng giới hạn gói rồi thử lại. | `account_limit_reached` |
| `relay_timeout` | hub | any | degraded | `retry_later` | Máy chủ Zalo phản hồi chậm hoặc không phản hồi. | Đợi khoảng 30 giây rồi thử lại. Nếu lặp lại nhiều lần, báo quản trị viên kiểm tra Zalo bridge. | `relay_timeout`, `http_request_failed`, `operation_timedout`, `managed_bridge_unreachable`, `managed_bridge_upstream_error`, `decode_failed`, `managed_bridge_invalid_response`, `managed_capacity_lock_timeout` |
| `relay_auth_failed` | hub | any | degraded | `contact_admin` | Máy chủ Zalo từ chối xác thực của website. | Quản trị viên kiểm tra API key / bridge secret rồi thử lại. | `relay_auth_failed`, `unauthorized`, `service_auth_failed` |
| `provisioning_failed` | hub | create | degraded | `contact_admin` | Chưa tạo xong tài khoản Zalo Cá nhân (máy chủ chưa cấp đủ quyền nhận tin). | Không dùng tài khoản dở dang này; báo quản trị viên kiểm tra rồi tạo lại. | `provisioning_failed`, `managed_callback_secret_missing`, `managed_callback_secret_failed`, `managed_registry_write_failed`, `callback_token_store_failed`, `mapping_insert_failed`, `mapping_schema_not_ready`, `module_not_loaded`, `channel_grant_unavailable` |

### 4.2 Trạng thái phiên (`session_state` / `status` của SĐT)

Dùng cho rail Inbox, tab Nhân viên, Bảng điều hành đội, Kênh của tôi. `can_relogin` quyết định có nút QR hay không.

| state | Nhãn | Tone | Mời QR (`can_relogin`) | `action` | Ý nghĩa |
|---|---|---|---|---|---|
| `connected` | Đang kết nối | ok | không | `none` | Phiên sống, tin nhắn về CRM. |
| `pending_qr` | Chờ quét QR | warn | có | `relogin_qr` | Đã tạo mã QR, chờ điện thoại quét. |
| `expired` | Hết phiên | crit | có | `relogin_qr` | Mã QR hoặc phiên đã hết hạn; tin mới không về CRM. |
| `logged_out` | Đã đăng xuất | crit | có | `relogin_qr` | Phiên bị đăng xuất trên điện thoại hoặc bởi hệ thống. |
| `session_disconnected` | Mất kết nối | crit | có | `relogin_qr` | Bridge còn tài khoản nhưng phiên runtime không sống. |
| `superseded` | Đăng nhập nơi khác | crit | có | `relogin_qr` | Một lần đăng nhập QR khác đã thay phiên này (sidecar supersede). |
| `other_site` | Đang ở website khác | crit | có | `relogin_qr` | Tài khoản đang nhận tin tại website khác; quét QR tại đây để chuyển về. |
| `duplicate` | Trùng SĐT | crit | không | `open_connected` | Cùng số Zalo đang kết nối ở tài khoản khác của website; không đăng nhập lại ở đây. |
| `revoked` | Đã xoá | muted | không | `none` | Tài khoản đã xoá khỏi bridge; lịch sử CRM vẫn giữ. |
| `bridge_unavailable` | Bridge lỗi | crit | không | `contact_admin` | Không đọc được trạng thái từ máy chủ Zalo. |
| `unknown` | — | muted | không | `none` | Chưa có dữ liệu trạng thái. |

## 5. Hành động (`action`)

| `action` | Nhãn nút gợi ý |
|---|---|
| `relogin_qr` | Tạo mã QR / Đăng nhập lại |
| `retry_later` | Thử lại sau |
| `open_connected` | Mở SĐT đang kết nối |
| `delete_duplicate` | Xoá tài khoản trùng |
| `use_existing` | Dùng tài khoản đã có |
| `delete_and_recreate` | Xoá rồi tạo lại |
| `contact_admin` | Báo quản trị viên |
| `upgrade_plan` | Nâng cấp gói |
| `close_other_qr` | Đóng mã QR khác |
| `none` | — (không nút) |

## 6. Router (Hub) → bucket: bảng tra đầy đủ

Mã do `bizcity-llm-router` trả về cho luồng Zalo Cá nhân, và bucket site gom về. Cột "HTTP router" là mã HTTP Hub trả cho site; site luôn trả 200 cho client.

| Router trả (`code` / `reason_bucket`) | Khi nào (router) | HTTP router | → `reason_bucket` site |
|---|---|---|---|
| `permission_denied` + `managed_account_not_owned` | Tài khoản không thuộc API key (`account_not_owned_response`) | 403 | `account_not_owned` |
| `permission_denied` + `managed_account_other_site` | Tài khoản đang bound website khác, key hợp lệ (`account_other_site_response`) | 403 | `managed_account_other_site` |
| `permission_denied` + `key_domain_mismatch` | Callback URL / domain không khớp API key (tạo tài khoản hoặc rebind) | 403 | `key_domain_mismatch` |
| `invalid_metadata` | API key chưa có domain hợp lệ cho Zalo managed | 200 | `key_domain_mismatch` |
| `feature_not_enabled` / `plan_missing` | Gói chưa bật Zalo Cá nhân (`entitlement_error`) | 403 | `feature_not_enabled` |
| `api_key_missing` / `no_api_key` | Site chưa có API key riêng | 403 / 200 | `bridge_not_configured` |
| `key_inactive` | API key bị tắt | 403 | `bridge_not_configured` |
| `account_limit_reached` | Hết hạn mức tài khoản của gói | 403 | `account_limit_reached` |
| `managed_bridge_not_configured` | Hub chưa cấu hình sidecar | 200 | `bridge_not_configured` |
| `managed_bridge_unreachable` | Hub không gọi được sidecar | 200 | `relay_timeout` |
| `managed_bridge_upstream_error` | Sidecar trả non-2xx không mã | 200 | `relay_timeout` |
| `managed_bridge_invalid_response` | Sidecar trả JSON hỏng | 200 | `relay_timeout` |
| `managed_capacity_lock_timeout` | Tranh khoá tạo tài khoản | 200 | `relay_timeout` |
| `managed_callback_secret_missing` / `managed_callback_secret_failed` / `managed_registry_write_failed` | Hub không cấp/ghi được callback credential | 200 | `provisioning_failed` |
| `unauthorized` / `service_auth_failed` | Xác thực service giữa Hub ↔ sidecar/site sai | 401 | `relay_auth_failed` |
| `sidecar_account_missing` (reconcile) | Registry Hub còn, sidecar mất tài khoản | – | `account_not_owned` |
| Sidecar `already_connected` | Đã có phiên sống | 409 | `already_connected` |
| Sidecar `qr_in_progress` | Đang có QR khác chờ | 409 | `qr_in_progress` |
| Sidecar `qr_expired` / `qr_declined` | QR hết hạn / bị từ chối trên điện thoại | 400 | `qr_expired` / `qr_declined` |
| Sidecar `qr_failed` / `account_not_found` / `personal_accounts_only` | zca-js không tạo được QR / id sai | 502 / 404 / 409 | `qr_failed` / `account_not_owned` / (generic) |
| `http_request_failed` / `operation_timedout` (WP HTTP) | Site → Hub quá 10s (Hub → sidecar 15s) | – | `relay_timeout` |
| `decode_failed` | Hub trả không phải JSON | – | `relay_timeout` |
| *(không mã)* | Upstream không trả gì | – | `qr_response_empty` |
| *(mã lạ)* | Bất kỳ mã chưa có trong catalog | – | `qr_response_empty`, `upstream_code = unrecognized:<hash8>` |

**Kiểm tra phía site (không qua router):** `duplicate_phone` (tạo trùng SĐT, R-ZP-DUP-1), `duplicate_zalo_login` (QR khi cùng `zaloUid` đang kết nối, R-ZP-DUP-2).

### 6.1 Nghĩa vụ của router (Hub) — đề xuất ràng buộc cho `bizcity-llm-router`

- R-ZP-ERR-H1: mọi lỗi trả **cả** `code` và `reason_bucket` dạng slug (`[a-z0-9_]`), không nhét văn bản vào `code`.
- R-ZP-ERR-H2: mã mới phải được thêm vào catalog site (§8) **trước** khi Hub phát hành; nếu không, client sẽ chỉ thấy câu chung `qr_response_empty`.
- R-ZP-ERR-H3: `message`/`hint` của Hub là tiếng Việt cho người dùng cuối; site **không** chuyển tiếp nguyên văn cho QR (chống lộ body), chỉ dùng catalog. Với tạo tài khoản site giữ `message`/`hint` Hub nếu có.
- R-ZP-ERR-H4: timeout Hub → sidecar (15s) phải **nhỏ hơn** timeout site → Hub (hiện 10s — lệch, xem §9 việc mở).

## 7. Luồng đặc biệt

| Tình huống | Hành vi chuẩn (B2 = C) |
|---|---|
| QR hết hạn khi đang chờ quét | Tự tạo lại **1 lần** mỗi lần mở sheet; lần sau hiện `qr_expired` + nút "Tạo lại mã QR". |
| `logged_out` / `revoked` / `expired` trong lúc poll | Dừng poll, hiện lý do theo §4.2 + nút QR (trừ `revoked`). |
| Ngắt phiên & tạo QR mới | Hỏi xác nhận (R-ACTION-SHEET, danger) rồi gọi reset. |
| Tài khoản ở website khác | Hiện `managed_account_other_site`; quét QR tại đây sẽ chuyển tài khoản về (`rebind_pending`). |
| Trùng SĐT | Tạo: chặn `duplicate_phone`. QR: chặn `duplicate_zalo_login`. Danh sách: state `duplicate`, không có nút QR, nút "Mở SĐT đang kết nối". |
| Xoá tài khoản | Local status → `revoked` (Đã xoá), lịch sử CRM giữ, không mời QR, không tính trùng. |

## 8. Thay đổi contract (quy trình bắt buộc)

1. Sửa `BizCity_Zalo_Session_Errors` (thêm bucket / alias / state / action). Không đổi nghĩa bucket cũ — đổi nghĩa ⇒ bucket mới.
2. Chạy lại sinh bảng §4–§5 và cập nhật doc này (test `ZaloSessionErrorsContractTest` fail nếu doc thiếu bucket/state/action).
3. Cập nhật mirror client: `plugins/bizcity-twin-crm/frontend/src/lib/zaloSession.js` (test kiểm nhãn state khớp).
4. Bump `VERSION`: thêm = minor, đổi message/hint = patch, xoá/đổi nghĩa = major (kèm CANON changelog).
5. Router thêm mã mới ⇒ báo owner `bizcity-zalo-personal` để thêm alias (R-ZP-ERR-H2).

## 9. Checklist triển khai

- [x] ERR-01 Catalog PHP `BizCity_Zalo_Session_Errors` (17 bucket, 11 state, 10 action) + nạp trong bootstrap.
- [x] ERR-02 `normalize_qr_result` lấy bucket/câu từ catalog; mọi lỗi QR đi qua `enrich()` ⇒ có `action`, `contract`.
- [x] ERR-03 Tạo tài khoản: lỗi Hub degraded và `duplicate_phone` đi qua `enrich()`.
- [x] ERR-04 REST `GET bizcity-channel/v1/zalo-bridge/error-catalog` (đăng nhập là đọc được, không credential).
- [x] ERR-05 B2 gom 5 bảng nhãn (ChannelSidebar, StaffPanel, TeamDashboardPanel, ContactsTab, Customer360TeamPage) + SessionAlertButton về `lib/zaloSession.js`.
- [x] ERR-06 `duplicate` hiện ở rail, tab Nhân viên, Bảng điều hành đội (chip "N trùng SĐT", hành động "Cần xử lý ngay" loại `duplicate_phone`), không mời QR.
- [x] ERR-07 Xoá tài khoản ⇒ `revoked` (DUP-11).
- [x] ERR-08 Unit test đồng bộ doc ↔ code ↔ mirror JS.
- [ ] ERR-09 C (`modules/twinweb/ui`): thay các danh sách `[ 'expired', 'logged_out', 'revoked' ]` + nhãn cục bộ (`MyChannelsPage.tsx`, `ChannelConnectHub.tsx`, `CrmInboxPage.tsx`) bằng mirror TS đọc `error-catalog`; hiện nút theo `action`. Lưu ý: ở C `revoked` hiện đang được coi là "cần QR" — chỉ đúng với status từ sidecar, không đúng với local `revoked` (đã xoá). Danh sách Kênh của tôi đã lọc tài khoản không còn thuộc key nên chưa lộ lỗi.
- [ ] ERR-10 Router: áp R-ZP-ERR-H1..H4 (owner `bizcity-llm-router`), đặc biệt đồng bộ timeout (site 10s < Hub 15s).
- [ ] ERR-11 Hiện `request_id` + nút sao chép cho lỗi `degraded` ở cả B2 và C.
