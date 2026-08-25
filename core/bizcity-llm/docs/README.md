# `core/bizcity-llm/docs/`

Tài liệu cho lớp gateway client (`BizCity_LLM_Client`) và hệ thống gói thành viên.

## Canonical role (B2B2C)

`core/bizcity-llm` là lớp **projection license/entitlement** từ hub về site
client, không phải lớp quản lý end-user membership.

Chuỗi canonical:

```text
bizcity-llm-router (hub)
  -> Twinmaster license / site entitlement (biz-xxx)
  -> core/bizcity-llm (client projection + runtime adapters)
  -> TwinChat Settings + TwinChat UI (admin operations surface)
  -> core/membership + Twin GPT (end-user plan consumption)
```

Ranh giới bắt buộc:

- Hub entitlement (`tier`, `balance`, capability ceiling) đọc qua
  `BizCity_LLM_Client`.
- End-user plan label/quota display không lấy trực tiếp từ `tier` hub.
- TwinChat surfaces là nơi vận hành API key + entitlement của site client.

## Mục lục

| Tài liệu | Nội dung |
|---|---|
| [MEMBERSHIP-CLIENT-PLANS.md](MEMBERSHIP-CLIENT-PLANS.md) | Core membership client với seeded/custom plan cho WP user — Hub feature/seat ceiling × local capability plan, kiến trúc `core/membership/`, lộ trình M1–M6. |

## Bối cảnh nhanh

- **Hub** (`bizcity-llm-router` trên `bizcity.vn`): nguồn sự thật của **site
  tier** (`free`/`paid`/`enterprise`) + ví credit USD (Twinmaster license).
  Client KHÔNG cài (R-GW-8).
- **Client** (`bizcity-twin-ai`): gọi hub qua `BizCity_LLM_Client::get_entitlement()`
  / `get_account_info()`, và hiển thị vận hành tại TwinChat Settings + TwinChat UI.
- **Membership local:** phân bổ seeded/custom capability plan cho WP user local,
  hook vào các filter cost-guard sẵn có (`bizcity_kg_quota_per_user`,
  `bizcity_kg_user_is_exempt`).

## Master Plan contract

Hai response không được dùng lẫn nhau:

| Contract | Endpoint/path | Vai trò |
|---|---|---|
| Plan catalog | Hub `GET /wp-json/bizcity/v1/master/plans`; client proxy `GET /wp-json/bizcity-channel/v1/master/plans` | Hiển thị tất cả gói, giá, giới hạn và danh sách capability có thể bán |
| Exact-key entitlement | Client `BizCity_LLM_Client::get_plan_config()` → Hub `GET /wp-json/bizcity/v1/master/config` | Xác định `master_level`, `plugins_enabled`, `channels`, quota và giới hạn của API key hiện tại |

`master/plans` là catalog, không phải authorization. Feature runtime phải đọc
`master/config`/`account-info` theo exact Bearer key; không chọn `plans[0]`, không
suy từ `user_id` hoặc local membership. Zalo Personal dùng
`channels.zalo_personal.allowed` và `account_limit` từ exact-key config.

### Zalo Personal managed seat contract

Hub bật `bizcity-zalo-personal` mặc định cho các gói built-in Free, Pro và
Premium. Số seat/account managed được sở hữu bởi exact BizCity API key:

| Master Plan | Mặc định | Ý nghĩa |
|---|---:|---|
| Free | `1` | Một tài khoản Zalo Personal |
| Pro | `3` | Tối đa ba tài khoản |
| Premium | `-1` | Unlimited |

`0` vẫn là trạng thái khóa có chủ đích. `core/bizcity-llm` chỉ project kết quả
Hub, không tự nâng seat và không dùng local membership hoặc `user_id` để suy ra
quyền. Khi nhận `reason=account_capacity_disabled`, quản trị viên Hub phải sửa
capacity của Master Plan rồi làm mới exact-key config.
