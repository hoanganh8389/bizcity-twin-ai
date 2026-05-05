<?php
/**
 * Phase 0.13 / Vòng 3 — Sprint 6 (Canvas Activation)
 * Twin Root triage accuracy fixture — 20 prompts.
 *
 * Each entry: prompt → expected sub-agent the LLM should hand off to.
 * Used by tests/run-triage-test.php to compute accuracy.
 *
 * Target: > 80% correct (Vòng 3 deliverable #2).
 */

return [
	// ── mindmap ───────────────────────────────────────────────────────
	[ 'prompt' => 'Vẽ mindmap về dinh dưỡng cho học sinh',                'expect' => 'mindmap' ],
	[ 'prompt' => 'Cho mình sơ đồ tư duy về quy trình bán hàng',          'expect' => 'mindmap' ],
	[ 'prompt' => 'Tạo mindmap về AI cho doanh nghiệp nhỏ',               'expect' => 'mindmap' ],
	[ 'prompt' => 'Brainstorm visual cho chiến lược 2026',                'expect' => 'mindmap' ],
	[ 'prompt' => 'Draw a mindmap about content marketing',               'expect' => 'mindmap' ],

	// ── image ─────────────────────────────────────────────────────────
	[ 'prompt' => 'Tạo hình ảnh sản phẩm cà phê hữu cơ',                   'expect' => 'image' ],
	[ 'prompt' => 'Generate image prompt cho banner Facebook',             'expect' => 'image' ],
	[ 'prompt' => 'Vẽ ảnh phong cảnh núi rừng cho website',                'expect' => 'image' ],
	[ 'prompt' => 'Soạn prompt ảnh cho khoá học online',                   'expect' => 'image' ],
	[ 'prompt' => 'Cần một bức hình minh hoạ về team building',            'expect' => 'image' ],

	// ── content ───────────────────────────────────────────────────────
	[ 'prompt' => 'Viết blog intro về AI cho doanh nghiệp',               'expect' => 'content' ],
	[ 'prompt' => 'Soạn bài Facebook post bán khoá học',                  'expect' => 'content' ],
	[ 'prompt' => 'Cho mình headline marketing cho sản phẩm mới',         'expect' => 'content' ],
	[ 'prompt' => 'Write a short marketing copy for our launch',          'expect' => 'content' ],
	[ 'prompt' => 'Viết bài quảng cáo về dịch vụ tư vấn',                 'expect' => 'content' ],

	// ── doc ───────────────────────────────────────────────────────────
	[ 'prompt' => 'Soạn tài liệu nội quy công ty',                        'expect' => 'doc' ],
	[ 'prompt' => 'Draft báo cáo tổng kết quý 1',                         'expect' => 'doc' ],
	[ 'prompt' => 'Viết một document tóm tắt chiến lược bán hàng',        'expect' => 'doc' ],
	[ 'prompt' => 'Tạo article phân tích thị trường e-commerce',          'expect' => 'doc' ],
	[ 'prompt' => 'Soạn tài liệu hướng dẫn sử dụng sản phẩm',             'expect' => 'doc' ],
];
