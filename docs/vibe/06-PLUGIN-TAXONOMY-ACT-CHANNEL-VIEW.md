# 06 — Plugin Taxonomy: Act · Channel · View

> **Status:** 🟡 MỘT PHẦN — taxonomy 3 nhóm là đề xuất tổ chức mới, nhưng mỗi
> nhóm ánh xạ vào contract đã tồn tại (không phát minh interface mới ở đây).

---

## 1. Ba nhóm plugin

| Nhóm | Vai trò | Ví dụ hệ thống hiện có |
|---|---|---|
| **Act** | Thực thi hành động ra hệ thống ngoài: CRM, POS, ERP, kế toán, thanh toán... | `bizcity-twin-crm` (ghi CRM), `bizcity-doc` (tạo tài liệu), `bizcity-tool-image`, `bizcity-video-kling` |
| **Channel** | Là 1 trong các channel dẫn dòng chảy hội thoại/sự kiện về TwinBrain: Zalo, Google, Facebook, POS webhook... | `bizcity-zalo-bot`, `bizcity-facebook-bot`, `bizcity-zalo-personal`, `bizgpt-tool-google` |
| **View** | Tạo UI/dashboard/workspace chuyên ngành | `bizcity-pagebuilder`, `bizcity-profile`, phần dashboard của `bizcity-twin-crm` |

Một plugin CÓ THỂ thuộc nhiều hơn 1 nhóm (vd `bizcity-twin-crm` vừa là **Act**
— ghi sự kiện CRM — vừa là **View** — SPA Inbox/Contacts). Khi đó, manifest
phải khai báo đủ các nhóm và mỗi nhóm phải tự thoả checklist riêng của nó bên
dưới — không được coi 1 nhóm PASS là đại diện cho cả plugin.

## 2. Checklist theo từng nhóm

Trạng thái checklist Act/Channel/View được quản lý tập trung tại
[MASTER-CHECKLIST.md TAX-1..TAX-15](MASTER-CHECKLIST.md). Các điều kiện dưới
đây là diễn giải contract để người viết plugin hiểu phạm vi áp dụng; không
đánh dấu trạng thái riêng trong tài liệu này.

### 2.1 Act — thực thi hành động (mutation/side-effect)

Bắt buộc tuân [PUBLIC-CONTRACTS-v1.md](../contracts/PUBLIC-CONTRACTS-v1.md) và
[RUNTIME-PRODUCTION-CONTRACT-v1.md](../contracts/RUNTIME-PRODUCTION-CONTRACT-v1.md).
Các gate tương ứng là `TAX-1..TAX-6` trong MASTER: idempotency, approval,
trace, retry/deadline, outcome evidence và CRM Repository.

### 2.2 Channel — dẫn dòng chảy về não

Bắt buộc tuân [PHASE-0-RULE-CHANNEL-UNIFY.md](../rules/PHASE-0-RULE-CHANNEL-UNIFY.md)
và [PHASE-0-RULE-ZONE-CHANNEL.md](../rules/PHASE-0-RULE-ZONE-CHANNEL.md).
Các gate tương ứng là `TAX-7..TAX-11` trong MASTER: normalized envelope,
zone, namespace, Gateway Sender và CRM Repository cho Zone 1.

### 2.3 View — UI/dashboard/workspace

Các gate View `TAX-12..TAX-15` trong MASTER yêu cầu đăng ký qua
[ADMIN-NAVIGATION-CONTRACT-v1.md](../contracts/ADMIN-NAVIGATION-CONTRACT-v1.md),
không nhúng lại `/gpt`/CRM Inbox, load bundle đúng surface theo R-PERF và
dùng `BizCity_Output_Renderer_Interface` khi cần render artifact AI.

## 3. Khai báo taxonomy trong manifest

```json
{
  "taxonomy": ["act", "channel"],
  "primary_taxonomy": "act"
}
```

`primary_taxonomy` xác định checklist nào là bắt buộc "chặn PASS" khi lint
(xem [10](10-PLUGIN-LINT-DIAGNOSTICS-CLOSED-LOOP.md)); các taxonomy phụ vẫn
được kiểm tra nhưng có thể ở mức WARN thay vì FAIL tuỳ độ hoàn thiện.

## 4. Vì sao cần taxonomy này (thay vì chỉ dùng `capabilities[]` sẵn có)

`capabilities` (tool/skill/agent/channel/kg_source_adapter/workflow_block/
persona/output_renderer — xem [07](07-PLUGIN-SDK-PUBLIC-INTERFACES.md)) mô tả
**plugin làm được gì về mặt kỹ thuật**. Taxonomy Act/Channel/View mô tả
**plugin đóng vai trò gì trong luồng nghiệp vụ tổng** — dùng để:

- Diagnostics nhóm plugin theo vai trò khi hiển thị Scoreboard (xem
  [11](11-SCOREBOARD-AUDIT-FRAMEWORK.md)).
- Reviewer/AI agent biết ngay checklist nào áp dụng mà không cần đọc hết
  `capabilities[]` để suy luận.

## 5. Tham chiếu

- [PUBLIC-CONTRACTS-v1.md](../contracts/PUBLIC-CONTRACTS-v1.md)
- [PHASE-0-RULE-ZONE-CHANNEL.md](../rules/PHASE-0-RULE-ZONE-CHANNEL.md)
- [ADMIN-NAVIGATION-CONTRACT-v1.md](../contracts/ADMIN-NAVIGATION-CONTRACT-v1.md)
