<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Skill Library — Sample Skills Seeder
 *
 * Run via admin panel (one-time) or WP-CLI:
 *   wp eval-file wp-content/plugins/bizcity-twin-ai/core/knowledge/includes/skill-seeder.php
 *
 * @package  BizCity_Knowledge
 * @since    2026-03-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

function bizcity_seed_sample_skills(): array {
	if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
		return [ 'error' => 'BizCity_Skill_Database class not found' ];
	}

	$db      = BizCity_Skill_Database::instance();
	$created = [];

	$samples = [
		/* ──────────────────────────────────────────────────────────── */
		[
			'skill_key'          => 'write-sales-post',
			'title'              => 'Viết bài bán hàng chuyên nghiệp',
			'description'        => 'Quy trình 5 bước viết bài bán hàng hấp dẫn, chuyển đổi cao',
			'category'           => 'content',
			'status'             => 'active',
			'priority'           => 70,
			'modes_json'         => [ 'content', 'planning' ],
			'triggers_json'      => [ 'bài bán hàng', 'sales post', 'viết quảng cáo', 'bài quảng cáo', 'content marketing', 'bài đăng facebook' ],
			'related_tools_json' => [ 'create_post', 'generate_image' ],
			'related_plugins_json' => [],
			'content_md'         => <<<'MD'
# Viết bài bán hàng chuyên nghiệp

## Mục đích
Hướng dẫn AI viết bài bán hàng hấp dẫn, đúng cấu trúc, tối ưu chuyển đổi.

## Quy trình 5 bước

### Bước 1 — Thu thập thông tin
- Hỏi người dùng: sản phẩm gì? giá? đối tượng? điểm nổi bật?
- Nếu thiếu thông tin → hỏi lại, KHÔNG bịa

### Bước 2 — Cấu trúc bài viết
```
1. Hook (câu mở đầu gây chú ý) — 1-2 dòng
2. Pain point (vấn đề khách hàng đang gặp) — 2-3 dòng
3. Solution (giới thiệu sản phẩm như giải pháp) — 3-5 dòng
4. Proof (bằng chứng: review, số liệu, case study) — 2-3 dòng
5. CTA (kêu gọi hành động rõ ràng) — 1-2 dòng
```

### Bước 3 — Viết nội dung
- Giọng văn: thân thiện, tự nhiên, không quá "sales"
- Ngôn ngữ: tiếng Việt chuẩn, emoji vừa phải (2-3 emoji)
- Độ dài: 150-300 từ cho Facebook, 500-800 từ cho blog

### Bước 4 — Tối ưu hóa
- Đặt keyword tự nhiên (nếu SEO)
- Thêm hashtag phù hợp (3-5 cái)
- Gợi ý hình ảnh đi kèm

### Bước 5 — Review
- Kiểm tra chính tả, ngữ pháp
- Đảm bảo CTA rõ ràng
- Đề xuất A/B test 2 phiên bản hook khác nhau

## Guardrails
- ❌ KHÔNG bịa thông tin sản phẩm, giá cả
- ❌ KHÔNG dùng ngôn ngữ quá phóng đại ("tốt nhất thế giới", "100% hiệu quả")
- ❌ KHÔNG copy nội dung từ nguồn khác
- ✅ Luôn hỏi xác nhận trước khi đăng bài

## Ví dụ Hook

**Sản phẩm: Kem chống nắng**
> "☀️ Bạn biết không? 80% lão hóa da đến từ tia UV hàng ngày — và hầu hết chúng ta đang bảo vệ da SAI CÁCH..."

**Sản phẩm: Khóa học Marketing**
> "Bạn đã chi bao nhiêu tiền quảng cáo mà vẫn chưa có đơn hàng? 🤔 Có thể vấn đề không phải ở ngân sách..."
MD,
		],

		/* ──────────────────────────────────────────────────────────── */
		[
			'skill_key'          => 'create-followup-flow',
			'title'              => 'Tạo automation chăm sóc khách hàng',
			'description'        => 'Thiết kế flow tự động follow-up sau khi khách mua hàng hoặc quan tâm sản phẩm',
			'category'           => 'automation',
			'status'             => 'active',
			'priority'           => 65,
			'modes_json'         => [ 'automation', 'planning', 'execution' ],
			'triggers_json'      => [ 'follow up', 'chăm sóc khách', 'automation flow', 'tự động hóa', 'email sequence', 'drip campaign' ],
			'related_tools_json' => [ 'create_workflow', 'send_email', 'send_zalo', 'schedule_task' ],
			'related_plugins_json' => [ 'bizcity-automation' ],
			'content_md'         => <<<'MD'
# Tạo Automation Follow-Up

## Mục đích
Hướng dẫn AI thiết kế flow chăm sóc khách hàng tự động, tăng retention và lifetime value.

## Quy trình

### Bước 1 — Xác định trigger
Hỏi người dùng:
- Sự kiện kích hoạt? (mua hàng, đăng ký, bỏ giỏ hàng, xem sản phẩm)
- Đối tượng? (khách mới, khách cũ, lead)
- Kênh? (email, Zalo, SMS, push notification)

### Bước 2 — Thiết kế timeline
Mẫu flow chuẩn cho post-purchase:
```
Ngày 0  → Cảm ơn + Hướng dẫn sử dụng
Ngày 3  → Hỏi thăm trải nghiệm
Ngày 7  → Gợi ý sản phẩm liên quan
Ngày 14 → Offer đặc biệt (nếu chưa quay lại)
Ngày 30 → Khảo sát + Loyalty program
```

### Bước 3 — Soạn nội dung
- Mỗi touchpoint cần: subject/tiêu đề + body + CTA
- Cá nhân hóa: dùng {tên}, {sản phẩm}, {ngày mua}
- Giọng điệu: ấm áp, hỗ trợ, không spam

### Bước 4 — Cấu hình workflow
- Sử dụng tool `create_workflow` nếu có
- Đặt điều kiện: if opened → path A, if not → reminder
- Set delay time giữa các bước

### Bước 5 — Testing
- Gửi test cho chính người dùng trước
- Kiểm tra link, hình ảnh, cá nhân hóa
- Monitor open rate, click rate sau 1 tuần

## Guardrails
- ❌ KHÔNG gửi quá 1 tin/ngày
- ❌ KHÔNG gửi ngoài giờ (tránh 21h-8h)
- ❌ KHÔNG thiếu nút unsubscribe
- ✅ Luôn có exit condition (khách unsubscribe hoặc đã mua lại)
- ✅ Comply CAN-SPAM / PDPA
MD,
		],

		/* ──────────────────────────────────────────────────────────── */
		[
			'skill_key'          => 'create-product',
			'title'              => 'Hướng dẫn tạo sản phẩm trên hệ thống',
			'description'        => 'Quy trình tạo sản phẩm mới qua WooCommerce/tool API',
			'category'           => 'tools',
			'status'             => 'active',
			'priority'           => 60,
			'modes_json'         => [ 'execution' ],
			'triggers_json'      => [ 'tạo sản phẩm', 'thêm sản phẩm', 'product', 'new product', 'đăng sản phẩm' ],
			'related_tools_json' => [ 'create_product', 'upload_image', 'update_product' ],
			'related_plugins_json' => [ 'woocommerce' ],
			'content_md'         => <<<'MD'
# Tạo sản phẩm trên hệ thống

## Mục đích
Hướng dẫn AI thu thập đủ thông tin và sử dụng tool tạo sản phẩm chính xác.

## Thông tin bắt buộc

| Field          | Yêu cầu                      |
|:--------------|:-----------------------------|
| Tên sản phẩm  | Rõ ràng, có keyword          |
| Giá           | Số cụ thể (VNĐ)              |
| Mô tả         | Ít nhất 50 từ                |
| Danh mục      | Chọn từ danh mục có sẵn      |
| Hình ảnh      | Ít nhất 1 ảnh chính          |

## Quy trình

### Bước 1 — Thu thập thông tin
Hỏi tuần tự:
1. "Tên sản phẩm là gì?"
2. "Giá bán bao nhiêu?" (nếu có giá sale → hỏi cả 2)
3. "Mô tả sản phẩm — đặc điểm, thành phần, kích thước?"
4. "Thuộc danh mục nào?"
5. "Có hình ảnh không?" (hướng dẫn upload nếu cần)

### Bước 2 — Validate
- Tên: không trùng sản phẩm đã có
- Giá: phải > 0, format đúng
- Mô tả: đề xuất bổ sung nếu quá ngắn
- Hình: kiểm tra URL/file hợp lệ

### Bước 3 — Tạo sản phẩm
```
Tool: create_product
Params: {
  "name": "...",
  "regular_price": "...",
  "description": "...",
  "categories": [...],
  "images": [...]
}
```

### Bước 4 — Xác nhận
- Hiển thị preview cho người dùng
- Hỏi "Bạn muốn đăng ngay hay lưu nháp?"
- Set status: publish / draft

## Guardrails
- ❌ KHÔNG tạo sản phẩm khi chưa có đủ tên + giá + mô tả
- ❌ KHÔNG bịa mô tả nếu người dùng chưa cung cấp
- ✅ Luôn hiển thị preview trước khi confirm
- ✅ Default status = draft (an toàn)
MD,
		],
	];

	foreach ( $samples as $data ) {
		if ( $db->key_exists( $data['skill_key'] ) ) {
			$created[] = $data['skill_key'] . ' (skipped — exists)';
			continue;
		}

		$data['author_id'] = get_current_user_id();
		$id = $db->save( $data );
		$created[] = $data['skill_key'] . ( $id ? " (created #$id)" : ' (FAILED)' );
	}

	return $created;
}

// Auto-run if called directly via WP-CLI or eval-file
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$results = bizcity_seed_sample_skills();
	foreach ( $results as $r ) {
		WP_CLI::log( $r );
	}
}
