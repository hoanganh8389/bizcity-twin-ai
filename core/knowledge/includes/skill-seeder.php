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

/* ================================================================
 *  Phase 1.12: Scan plugin skills/ directory → DB sync
 * ================================================================ */

/**
 * Scan all plugin skills/ directories for .md skill files.
 * Parses YAML frontmatter (including chain:, blocks:, skip_* for pipeline config)
 * and upserts into bizcity_skills table.
 *
 * Directories scanned:
 *   - wp-content/plugins/bizcity-twin-ai/core/tools/ * /skills/*.md
 *   - wp-content/plugins/ * /skills/*.md
 *   - wp-content/mu-plugins/ * /skills/*.md
 *
 * @return array Results [ 'skill-key (created #ID)', 'skill-key (skipped)', ... ]
 */
function bizcity_scan_plugin_skills(): array {
	$log = '[BizCity SkillSeeder]';
	error_log( $log . ' === scan_plugin_skills START ===' );

	if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
		error_log( $log . ' ABORT — BizCity_Skill_Database not loaded' );
		return [ 'error' => 'BizCity_Skill_Database not loaded' ];
	}

	$db      = BizCity_Skill_Database::instance();
	$results = [];

	// Build scan directories
	$scan_dirs = [];

	// Core tools
	$twin_dir = defined( 'BIZCITY_TWIN_AI_DIR' )
		? BIZCITY_TWIN_AI_DIR
		: WP_PLUGIN_DIR . '/bizcity-twin-ai';
	$scan_dirs[] = $twin_dir . '/core/tools';

	// Plugins
	$scan_dirs[] = WP_PLUGIN_DIR;

	// MU-Plugins
	if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
		$scan_dirs[] = WPMU_PLUGIN_DIR;
	}

	foreach ( $scan_dirs as $base ) {
		if ( ! is_dir( $base ) ) {
			continue;
		}

		// Glob: {base}/*/skills/*.md
		$pattern = rtrim( $base, '/\\' ) . '/*/skills/*.md';
		$files   = glob( $pattern );

		error_log( $log . ' Scan: ' . $pattern . ' → ' . count( $files ?: [] ) . ' files' );

		if ( empty( $files ) ) {
			continue;
		}

		foreach ( $files as $file_path ) {
			error_log( $log . ' Processing: ' . $file_path );
			$parsed = bizcity_parse_skill_file( $file_path );
			if ( ! $parsed ) {
				error_log( $log . ' ❌ Parse failed: ' . basename( $file_path ) );
				$results[] = basename( $file_path ) . ' (parse failed)';
				continue;
			}

			$skill_key = $parsed['skill_key'];
			$data      = $parsed['data'];

			// Upsert into DB
			$id = $db->upsert( $data );
			if ( $id ) {
				error_log( $log . ' ✅ Synced: ' . $skill_key . ' #' . $id
					. ' (tools=' . count( is_array( $data['tools_json'] ) ? $data['tools_json'] : [] )
					. ', pipeline=' . ( ! empty( $data['pipeline_json'] ) ? 'yes' : 'no' ) . ')' );
				$results[] = $skill_key . ' (synced #' . $id . ')';

				// Link tools via skill_tool_map
				if ( class_exists( 'BizCity_Skill_Tool_Map' ) && ! empty( $data['tools_json'] ) ) {
					$tools = is_array( $data['tools_json'] )
						? $data['tools_json']
						: ( json_decode( $data['tools_json'], true ) ?: [] );
					$map = BizCity_Skill_Tool_Map::instance();
					foreach ( $tools as $tool_key ) {
						$map->link( (int) $id, $tool_key, 'primary' );
					}
				}
			} else {
				error_log( $log . ' ❌ Upsert FAILED: ' . $skill_key );
				$results[] = $skill_key . ' (FAILED)';
			}
		}
	}

	error_log( $log . ' === scan_plugin_skills END — ' . count( $results ) . ' results ===' );
	return $results;
}

/**
 * Parse a single .md skill file into DB-ready data.
 *
 * @param string $file_path Path to .md file.
 * @return array|null { skill_key, data } or null on failure.
 */
function bizcity_parse_skill_file( string $file_path ): ?array {
	$raw = file_get_contents( $file_path );
	if ( ! $raw ) {
		return null;
	}

	// Extract YAML frontmatter
	if ( ! preg_match( '/\A---\s*\n(.*?)\n---\s*\n(.*)\z/s', $raw, $m ) ) {
		return null;
	}

	$yaml_str = $m[1];
	$body     = $m[2];

	// Parse YAML — use PHP yaml extension if available, else simple parser
	$fm = null;
	if ( function_exists( 'yaml_parse' ) ) {
		$fm = yaml_parse( '---' . "\n" . $yaml_str . "\n" . '---' );
		if ( ! is_array( $fm ) ) {
			error_log( '[BizCity SkillSeeder] yaml_parse failed for: ' . basename( $file_path )
				. ' — falling back to simple parser' );
			$fm = bizcity_simple_yaml_parse( $yaml_str );
		}
	} else {
		$fm = bizcity_simple_yaml_parse( $yaml_str );
	}

	if ( ! is_array( $fm ) || empty( $fm['title'] ) ) {
		error_log( '[BizCity SkillSeeder] Frontmatter invalid or missing title: ' . basename( $file_path )
			. ' — keys=' . ( is_array( $fm ) ? implode( ',', array_keys( $fm ) ) : 'NOT_ARRAY' ) );
		return null;
	}

	error_log( '[BizCity SkillSeeder] Parsed OK: ' . basename( $file_path )
		. ' — title=' . $fm['title']
		. ', tools=' . count( (array) ( $fm['tools'] ?? [] ) )
		. ', chain=' . count( (array) ( $fm['chain'] ?? [] ) )
		. ', blocks=' . count( (array) ( $fm['blocks'] ?? [] ) ) );

	// Generate skill_key from filename
	$skill_key = pathinfo( $file_path, PATHINFO_FILENAME );

	// Build pipeline_config from frontmatter
	$pipeline_config = [];
	if ( ! empty( $fm['chain'] ) ) {
		$pipeline_config['chain'] = (array) $fm['chain'];
	}
	if ( ! empty( $fm['blocks'] ) ) {
		$pipeline_config['blocks'] = (array) $fm['blocks'];
	}
	foreach ( [ 'skip_research', 'skip_planner', 'skip_memory', 'skip_reflection' ] as $flag ) {
		if ( isset( $fm[ $flag ] ) ) {
			$pipeline_config[ $flag ] = (bool) $fm[ $flag ];
		}
	}

	$data = [
		'skill_key'    => $skill_key,
		'user_id'      => 0,
		'character_id' => 0,
		'title'        => $fm['title'],
		'description'  => $fm['description'] ?? '',
		'category'     => $fm['category'] ?? 'general',
		'triggers_json' => (array) ( $fm['triggers'] ?? [] ),
		'tools_json'    => (array) ( $fm['tools'] ?? [] ),
		'modes'         => implode( ',', (array) ( $fm['modes'] ?? [ 'execution' ] ) ),
		'slash_commands' => implode( ',', (array) ( $fm['slash_commands'] ?? [] ) ),
		'priority'       => (int) ( $fm['priority'] ?? 50 ),
		'status'         => $fm['status'] ?? 'active',
		'content'        => trim( $body ),
		'pipeline_json'  => ! empty( $pipeline_config ) ? $pipeline_config : null,
	];

	return [ 'skill_key' => $skill_key, 'data' => $data ];
}

/**
 * Simple YAML frontmatter parser (no dependency).
 *
 * Handles:
 * - Flat scalars:         key: value
 * - Booleans:             key: true / false
 * - Inline arrays:        key: [a, b, c]
 * - Inline objects:       key: { k1: v1, k2: v2 }
 * - Nested maps:          key:\n  sub: val
 * - List of maps:         key:\n  - step: 2\n    from: { ... }
 *
 * FIX 1.12-BUG1: No PHP reference (&) — prevents zval aliasing wipe.
 * FIX 1.12-BUG2: Multi-line list items (continuation lines merged into last entry).
 * FIX 1.12-BUG3: Inline objects { k: v, ... } parsed recursively.
 *
 * @param string $yaml_str Raw YAML between --- markers.
 * @return array
 */
function bizcity_simple_yaml_parse( string $yaml_str ): array {
	$result = [];
	$lines  = explode( "\n", $yaml_str );
	$current_key   = null;  // Top-level key owning current nested block
	$current_items = [];    // Accumulator for nested block (NO reference)
	$last_list_idx = -1;    // Index of last list item (for continuation lines)

	$log = '[BizCity SkillYAML]';

	foreach ( $lines as $line_num => $line ) {
		$trimmed = trim( $line );

		// Skip empty lines and comments
		if ( $trimmed === '' || strpos( $trimmed, '#' ) === 0 ) {
			continue;
		}

		$indent = strlen( $line ) - strlen( ltrim( $line ) );

		// ── Top-level key (indent 0) ──────────────────────────────
		if ( $indent === 0 ) {
			// Flush previous nested block
			if ( $current_key !== null ) {
				$result[ $current_key ] = $current_items;
				$current_key   = null;
				$current_items = [];
				$last_list_idx = -1;
			}

			// Inline array: key: [val1, val2]
			if ( preg_match( '/^([a-z_]+)\s*:\s*\[(.+)\]\s*$/i', $line, $m ) ) {
				$result[ trim( $m[1] ) ] = array_map( 'trim', explode( ',', $m[2] ) );
				continue;
			}

			// Key: value (scalar / inline object)
			if ( preg_match( '/^([a-z_]+)\s*:\s*(.+)$/i', $line, $m ) ) {
				$key   = trim( $m[1] );
				$value = trim( $m[2] );
				$result[ $key ] = bizcity_yaml_cast_value( $value );
				continue;
			}

			// Key: (start block — nested map or list)
			if ( preg_match( '/^([a-z_]+)\s*:\s*$/i', $line, $m ) ) {
				$current_key   = trim( $m[1] );
				$current_items = [];
				$last_list_idx = -1;
				continue;
			}

			error_log( $log . ' Unrecognized top-level line ' . $line_num . ': ' . $trimmed );
			continue;
		}

		// ── Nested content (indent >= 2) ──────────────────────────
		if ( $current_key === null ) {
			// Orphan indented line — skip
			continue;
		}

		// List item: "  - ..."
		if ( preg_match( '/^\s{2,}-\s+(.+)$/', $line, $m ) ) {
			$item_str = trim( $m[1] );

			// Inline object: - { step: 1, fields: [a, b] }
			if ( preg_match( '/^\{(.+)\}$/', $item_str, $obj_m ) ) {
				$current_items[] = bizcity_yaml_parse_inline_object( $obj_m[1] );
				$last_list_idx   = count( $current_items ) - 1;
				continue;
			}

			// Map starter: - key: value  (first key of a map entry)
			if ( preg_match( '/^(\w+)\s*:\s*(.+)$/', $item_str, $km ) ) {
				$val = trim( $km[2] );
				// Value might be inline object: from: { step: 1, fields: [a] }
				if ( preg_match( '/^\{(.+)\}$/', $val, $obj_m ) ) {
					$val = bizcity_yaml_parse_inline_object( $obj_m[1] );
				} else {
					$val = bizcity_yaml_cast_value( $val );
				}
				$current_items[] = [ trim( $km[1] ) => $val ];
				$last_list_idx   = count( $current_items ) - 1;
				continue;
			}

			// Plain scalar list item
			$current_items[] = bizcity_yaml_cast_value( $item_str );
			$last_list_idx   = count( $current_items ) - 1;
			continue;
		}

		// Continuation of last list item: "    key: value" (deeper indent, no -)
		if ( $last_list_idx >= 0 && is_array( $current_items[ $last_list_idx ] ) ) {
			if ( preg_match( '/^\s{4,}(\w+)\s*:\s*(.+)$/i', $line, $m ) ) {
				$k = trim( $m[1] );
				$v = trim( $m[2] );

				// Value might be inline object or inline array
				if ( preg_match( '/^\{(.+)\}$/', $v, $obj_m ) ) {
					$v = bizcity_yaml_parse_inline_object( $obj_m[1] );
				} elseif ( preg_match( '/^\[(.+)\]$/', $v, $arr_m ) ) {
					$v = array_map( 'trim', explode( ',', $arr_m[1] ) );
				} else {
					$v = bizcity_yaml_cast_value( $v );
				}

				$current_items[ $last_list_idx ][ $k ] = $v;
				continue;
			}
		}

		// Nested map entry (not a list): "  tool_key: block_code"
		if ( preg_match( '/^\s{2,}([a-z_][a-z0-9_]*)\s*:\s*(.+)$/i', $line, $m ) ) {
			$k = trim( $m[1] );
			$v = trim( $m[2] );
			$current_items[ $k ] = bizcity_yaml_cast_value( $v );
			$last_list_idx = -1; // Not in list mode
			continue;
		}
	}

	// Flush final nested block
	if ( $current_key !== null ) {
		$result[ $current_key ] = $current_items;
	}

	error_log( $log . ' Parsed: keys=' . implode( ',', array_keys( $result ) ) );

	return $result;
}

/**
 * Cast a YAML scalar string to proper PHP type.
 *
 * @param string $value
 * @return mixed
 */
function bizcity_yaml_cast_value( string $value ) {
	if ( $value === 'true' ) {
		return true;
	}
	if ( $value === 'false' ) {
		return false;
	}
	if ( $value === 'null' || $value === '~' ) {
		return null;
	}
	if ( is_numeric( $value ) ) {
		return strpos( $value, '.' ) !== false ? (float) $value : (int) $value;
	}
	// Strip surrounding quotes
	if ( ( $value[0] ?? '' ) === '"' || ( $value[0] ?? '' ) === "'" ) {
		return trim( $value, "\"'" );
	}
	return $value;
}

/**
 * Parse an inline YAML object string: k1: v1, k2: v2
 * Handles nested inline arrays: fields: [a, b]
 *
 * @param string $inner Content between { and }
 * @return array
 */
function bizcity_yaml_parse_inline_object( string $inner ): array {
	$result = [];
	// Split on commas that are NOT inside brackets
	$parts  = preg_split( '/,\s*(?![^\[]*\])/', $inner );

	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( ! preg_match( '/^(\w+)\s*:\s*(.+)$/', $part, $m ) ) {
			continue;
		}
		$key = trim( $m[1] );
		$val = trim( $m[2] );

		// Inline array inside object: fields: [a, b]
		if ( preg_match( '/^\[(.+)\]$/', $val, $arr_m ) ) {
			$result[ $key ] = array_map( 'trim', explode( ',', $arr_m[1] ) );
		} else {
			$result[ $key ] = bizcity_yaml_cast_value( $val );
		}
	}

	return $result;
}
