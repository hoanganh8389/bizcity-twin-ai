---
title: Tạo quy trình follow-up tự động
modes: [automation, planning]
triggers: [follow-up, chăm sóc khách, tự động, automation flow]
tools: [create_workflow, send_message]
plugins: [bizcity-automation]
priority: 55
status: active
version: "1.0"
---

# Tạo quy trình follow-up tự động

## Mục đích

Hướng dẫn AI thiết kế workflow tự động chăm sóc khách hàng sau khi mua hàng hoặc tương tác.

## Quy trình

1. **Thu thập thông tin**: Hỏi trigger event (mua hàng, đăng ký, inbox)
2. **Xác định timeline**: Bao nhiêu ngày, bao nhiêu bước
3. **Thiết kế nội dung**: Tin nhắn cho mỗi bước
4. **Tạo workflow**: Dùng tool `create_workflow` nếu plugin automation có sẵn
5. **Test**: Chạy thử với 1 contact test

## Mẫu workflow phổ biến

### Post-Purchase (Sau mua hàng)
```
Ngày 0: Cảm ơn + xác nhận đơn hàng
Ngày 1: Hướng dẫn sử dụng sản phẩm
Ngày 3: Hỏi thăm trải nghiệm
Ngày 7: Mời đánh giá / review
Ngày 14: Gợi ý sản phẩm liên quan
Ngày 30: Ưu đãi tái mua
```

### Lead Nurturing (Chăm lead)
```
Ngày 0: Gửi tài liệu miễn phí (lead magnet)
Ngày 1: Email giới thiệu giá trị
Ngày 3: Case study / testimonial
Ngày 5: Ưu đãi giới hạn
Ngày 7: Last call + urgency
```

## Guardrails

- Không spam — tối đa 1 tin nhắn/ngày
- Phải có opt-out / unsubscribe option
- Nội dung phải có giá trị, không chỉ là quảng cáo
- Respect thời gian khách hàng — không gửi ngoài giờ hành chính

## Ví dụ

**Input**: "Tạo flow chăm sóc khách sau khi mua gói dịch vụ SEO"

**Output**: Workflow 6 bước trong 30 ngày, mỗi bước có nội dung tin nhắn cụ thể
