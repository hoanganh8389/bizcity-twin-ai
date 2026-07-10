# Broadcast Recipients — Google Sheet Template

## 1. Header bắt buộc

Dòng 1 phải có các cột (ít nhất 1 cột định danh):

- `name`
- `phone`
- `email`
- `external_id` (optional)
- `tags` (optional)

## 2. Ví dụ dữ liệu

| name | phone | email | external_id | tags |
|---|---|---|---|---|
| Nguyen Van A | 0901234567 | a@example.com | KH001 | vip,new |
| Tran Thi B | 0912345678 | b@example.com | KH002 | vip |

## 3. Cách lấy link import

### URL edit

`https://docs.google.com/spreadsheets/d/{sheet_id}/edit#gid={gid}`

### URL export CSV (khuyến nghị cho parser)

`https://docs.google.com/spreadsheets/d/{sheet_id}/export?format=csv&gid={gid}`

## 4. Quyền truy cập

Để import qua URL không OAuth, sheet cần:

- Share: `Anyone with the link`
- Permission: `Viewer`

## 5. Lưu ý

- Không merge cell ở hàng header.
- Không để dòng trống xen kẽ trong block dữ liệu.
- Với số điện thoại, nên để dạng text để giữ số `0` đầu.
