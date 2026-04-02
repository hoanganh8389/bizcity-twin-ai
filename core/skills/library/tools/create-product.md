---
title: Tạo sản phẩm WooCommerce
modes: [tools, ecommerce]
triggers: [tạo sản phẩm, thêm sản phẩm, product, woocommerce]
tools: [create_product, update_product]
plugins: [woocommerce]
priority: 50
status: active
version: "1.0"
---

# Tạo sản phẩm WooCommerce

## Mục đích

Hướng dẫn AI tạo sản phẩm WooCommerce với đầy đủ thông tin từ mô tả user.

## Quy trình

1. **Thu thập thông tin cơ bản**:
   - Tên sản phẩm
   - Giá bán (regular price)
   - Giá khuyến mãi (sale price, nếu có)
   - Danh mục sản phẩm
2. **Mô tả sản phẩm**: Viết short description + long description
3. **Thuộc tính**: Màu sắc, kích thước, chất liệu (nếu applicable)
4. **SEO**: Meta title, meta description
5. **Tạo sản phẩm**: Dùng tool `create_product`

## Template mô tả

### Short Description (50-100 từ)
- Tóm tắt lợi ích chính
- 2-3 bullet points nổi bật
- Tone phù hợp với brand

### Long Description (200-400 từ)
- Mô tả chi tiết sản phẩm
- Thông số kỹ thuật
- Hướng dẫn sử dụng
- FAQ ngắn

## Guardrails

- Giá phải là số dương, đơn vị VNĐ
- Danh mục phải tồn tại trong hệ thống
- Không tự ý set stock status — hỏi user
- Ảnh sản phẩm: nhắc user upload nếu chưa có
- SKU: tạo theo pattern brand-category-number nếu user không chỉ định

## Ví dụ

**Input**: "Tạo sản phẩm áo thun cotton unisex, giá 250k, sale 199k, danh mục Thời trang"

**Output**: Gọi create_product với:
- name: "Áo Thun Cotton Unisex"
- regular_price: "250000"
- sale_price: "199000"
- categories: ["Thời trang"]
- short_description: (auto-generated)
- description: (auto-generated)
