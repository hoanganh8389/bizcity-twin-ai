<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicInstallerDbUpdater {
	
	private static $migration_checked = false;
	
	public static function runUpdate( $current_version ) {
		global $wpdb;
		
		// Cache trong cùng request - tránh check nhiều lần
		if ( ! empty( self::$migration_checked ) ) {
			return;
		}
		
		// Cache cross-request - check 1 lần/24h
		$blog_id = get_current_blog_id();
		$cache_key = 'waic_migration_ok_' . $blog_id;
		if ( get_transient( $cache_key ) === 'yes' ) {
			self::$migration_checked = true;
			return;
		}
		
		// MIGRATION: Rename tables from waic_ to bizcity_ if needed
		self::migrateTablePrefixes();
		
		// Check if required tables exist first (tránh query vào DB chưa ready)
		$prefix = $wpdb->prefix;
		$table_modules = $prefix . 'bizcity_modules';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_modules}'" );
		
		if ( $table_exists !== $table_modules ) {
			// Tables chưa tồn tại, skip migration
			self::$migration_checked = true;
			return;
		}
		
		// Wrap queries trong try-catch để tránh fatal khi connection fail
		try {
			// Ensure core modules exist even on old installs
		$coreModules = array(
			'adminmenu' => 'Admin Menu',
			'options' => 'Options',
			'workspace' => 'Workspace',
			'workflow' => 'Workflow',
			'mcp' => 'MCP',
		);
		foreach ($coreModules as $code => $label) {
			if ( WaicDb::get( "SELECT 1 FROM `@__modules` WHERE code='" . addslashes($code) . "'", 'one' ) != 1 ) {
				WaicDb::query( "INSERT INTO `@__modules` (id, code, active, type_id, label) VALUES (NULL, '" . addslashes($code) . "', 1, 1, '" . addslashes($label) . "');" );
			} else {
				// If present but disabled, enable it (required for admin UI)
				WaicDb::query( "UPDATE `@__modules` SET active=1 WHERE code='" . addslashes($code) . "'" );
			}
		}

		if ($current_version && version_compare($current_version, '1.1.1', '<')) {
			WaicDb::query( "ALTER TABLE `@__tasks` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
			WaicDb::query( "ALTER TABLE `@__posts_create` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
		}
		
		if ( WaicDb::get( "SELECT 1 FROM `@__modules` WHERE code='postsfields'", 'one' ) != 1 ) {
			WaicDb::query( "INSERT INTO `@__modules` (id, code, active, type_id, label) VALUES (NULL, 'postsfields', 1, 1, 'PostsFields');" );
		}
		if ( WaicDb::get( "SELECT 1 FROM `@__modules` WHERE code='chatbots'", 'one' ) != 1 ) {
			WaicDb::query( "INSERT INTO `@__modules` (id, code, active, type_id, label) VALUES (NULL, 'chatbots', 1, 1, 'Chatbots');" );
		}
		if ( WaicDb::get( "SELECT 1 FROM `@__modules` WHERE code='promo'", 'one' ) != 1 ) {
			WaicDb::query( "INSERT INTO `@__modules` (id, code, active, type_id, label) VALUES (NULL, 'promo', 1, 1, 'Promo');" );
		}
		if ( WaicDb::get( "SELECT 1 FROM `@__modules` WHERE code='magictext'", 'one' ) != 1 ) {
			WaicDb::query( "INSERT INTO `@__modules` (id, code, active, type_id, label) VALUES (NULL, 'magictext', 1, 1, 'Magictext');" );
		}
		if ( WaicDb::get( "SELECT 1 FROM `@__modules` WHERE code='mcp'", 'one' ) != 1 ) {
			WaicDb::query( "INSERT INTO `@__modules` (id, code, active, type_id, label) VALUES (NULL, 'mcp', 1, 1, 'MCP');" );
		}
		if ( WaicDb::get( "SELECT 1 FROM `@__modules` WHERE code='forms'", 'one' ) != 1 ) {
			WaicDb::query( "INSERT INTO `@__modules` (id, code, active, type_id, label) VALUES (NULL, 'forms', 1, 1, 'Forms');" );
		}
		if ( WaicDb::get( "SELECT 1 FROM `@__modules` WHERE code='workflow'", 'one' ) != 1 ) {
			WaicDb::query( "INSERT INTO `@__modules` (id, code, active, type_id, label) VALUES (NULL, 'workflow', 1, 1, 'Workflow');" );
		}
		if ( ! WaicDb::existsTableColumn( '@__workspace', 'flag' ) ) {
			WaicDb::query( 'ALTER TABLE `@__workspace` ADD COLUMN `flag` INT NOT NULL DEFAULT 0 AFTER `value`' );
			WaicDb::query( "ALTER TABLE `@__workspace` ADD COLUMN `timeout` INT NOT NULL DEFAULT 0 AFTER `flag`" );
		}
		if ( WaicDb::get( "SELECT 1 FROM `@__workspace` WHERE name='flow'", 'one' ) != 1 ) {
			WaicDb::query( "INSERT INTO `@__workspace` (id, name, value, flag, timeout) VALUES (11, 'flow', 0, 0, 0);" );
		}
		if ( ! WaicDb::existsTableColumn( '@__tasks', 'cycle' ) ) {
			WaicDb::query( 'ALTER TABLE `@__tasks` ADD COLUMN `cycle` INT NOT NULL DEFAULT 0 AFTER `steps`' );
			WaicDb::query( "ALTER TABLE `@__tasks` ADD COLUMN `message` VARCHAR(250) DEFAULT '' AFTER `cycle`" );
		}
		if ( ! WaicDb::existsTableColumn( '@__tasks', 'title' ) ) {
			WaicDb::query( "ALTER TABLE `@__tasks` ADD COLUMN `title` VARCHAR(250) DEFAULT '' AFTER `author`" );
		}
		if ( ! WaicDb::existsTableColumn( '@__tasks', 'tokens' ) ) {
			WaicDb::query( "ALTER TABLE `@__tasks` ADD COLUMN `tokens` BIGINT NOT NULL DEFAULT 0 AFTER `message`" );
		}
		if ( ! WaicDb::existsTableColumn( '@__tasks', 'mode' ) ) {
			WaicDb::query( "ALTER TABLE `@__tasks` ADD COLUMN `mode` VARCHAR(24) DEFAULT '' AFTER `tokens`" );
			WaicDb::query( "ALTER TABLE `@__tasks` ADD COLUMN `obj_id` BIGINT NOT NULL DEFAULT 0 AFTER `mode`" );
		}
		
		if ( ! WaicDb::existsTableColumn( '@__posts_create', 'added' ) ) {
			WaicDb::query( 'ALTER TABLE `@__posts_create` ADD COLUMN `added` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `post_id`' );
			WaicDb::query( "ALTER TABLE `@__posts_create` ADD COLUMN `uniq` VARCHAR(32) NULL AFTER `added`" );
		}
		if ( ! WaicDb::existsTableColumn( '@__history', 'feature' ) ) {
			WaicDb::query( "ALTER TABLE `@__history` ADD COLUMN `feature` VARCHAR(24) NOT NULL AFTER `task_id`" );
		}
		if ( ! WaicDb::existsTableColumn( '@__history', 'engine' ) ) {
			WaicDb::query( "ALTER TABLE `@__history` ADD COLUMN `engine` VARCHAR(20) DEFAULT '' AFTER `ip`" );
		}
		if ( WaicDb::get( "SELECT 1 FROM `@__tasks` WHERE feature='magictext'", 'one' ) != 1 ) {
			WaicDb::query( "INSERT INTO `@__tasks` (id, feature, title, author, status) VALUES (NULL, 'magictext', 'Magic Text', 0, 4);");
		}
		
		$forceSeedTemplates = defined('WAIC_FORCE_SEED_TEMPLATES') && WAIC_FORCE_SEED_TEMPLATES;
		$templateCount = (int) WaicDb::get( "SELECT COUNT(*) FROM `@__tasks` WHERE feature='template'", 'one' );
		// Seed bundled templates if missing (or force-run).
		if ( $forceSeedTemplates  ) {
			$json = '{"nodes":[{"id":"1","type":"trigger","position":{"x":350,"y":200},"data":{"dragged":true,"type":"trigger","category":"wp","error":false,"code":"wp_user_register","label":"New user registered","settings":{"login":"","name":"","email":"","role":"","capability":""}}},{"id":"2","type":"logic","position":{"x":535,"y":200},"data":{"dragged":true,"type":"logic","category":"un","code":"un_branch","label":"Branch","settings":{"name":"IF","criteria":"{{node#1.display_name}}","operator":"is_known"},"error":false}},{"id":"3","type":"action","position":{"x":719.11372505269,"y":99.933325895769},"data":{"dragged":true,"type":"action","category":"wp","error":false,"code":"wp_send_email","label":"Send Email","settings":{"to":"{{node#1.user_email}}","from":"support@bizgpt.vn","from_name":"Max from AIWU","subject":"Welcome to our WordPress community!","body":"Hey {{node#1.display_name}}!\\nThanks for joining! We’re really happy to have you on board.\\nYour account is ready — you can log in anytime and start exploring.\\n\\nIf you have any questions, just reply to this email — we’re here to help.\\nSee you around!\\n\\n— The AIWU Team"}}},{"id":"4","type":"action","position":{"x":724.9120303178,"y":286.61233136753},"data":{"dragged":true,"type":"action","category":"wp","error":false,"code":"wp_send_email","label":"Send Email","settings":{"to":"{{node#1.user_email}}","from":"max@bizgpt.vn","from_name":"Max form AIWU","subject":"Welcome to our WordPress community!","body":"Hey there!\\nThanks for joining! We’re really happy to have you on board.\\nYour account is ready — you can log in anytime and start exploring.\\n\\nIf you have any questions, just reply to this email — we’re here to help.\\nSee you around!\\n\\n— The AIWU Team"}}}],"edges":[{"id":"1","source":"1","target":"2","sourceHandle":"output-right","targetHandle":"input-left","type":"default"},{"id":"2","source":"2","target":"3","sourceHandle":"output-then","targetHandle":"input-left","type":"default"},{"id":"3","source":"2","target":"4","sourceHandle":"output-else","targetHandle":"input-left","type":"default"}],"viewport":{"x":-70.95266505528,"y":-38.855946890096,"zoom":1.1892071068141},"settings":"","version":"1.0.0"}';
			if ( $forceSeedTemplates ) {
				WaicDb::query( "UPDATE `@__tasks` SET title='Email chào mừng được cá nhân hóa', mode='1', message='Tự động gửi email chào mừng cá nhân hóa cho người dùng mới khi họ đăng ký trên trang web của bạn..', params='" . addslashes($json) . "' WHERE feature='template' AND (title='Email chào mừng được cá nhân hóa' OR mode='1')" );
			}
			WaicDb::query( "INSERT INTO `@__tasks` (id, feature, title, mode, message, params) SELECT NULL, 'template', 'Email chào mừng được cá nhân hóa', 1, 'Tự động gửi email chào mừng cá nhân hóa cho người dùng mới khi họ đăng ký trên trang web của bạn..', '" . addslashes($json) . "' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `@__tasks` WHERE feature='template' AND title='Email chào mừng được cá nhân hóa' LIMIT 1);" );
			$json = '{"nodes":[{"id":"1","type":"trigger","position":{"x":350,"y":200},"data":{"dragged":true,"type":"trigger","category":"sy","code":"sy_manual","label":"Manually","settings":[],"error":false}},{"id":"2","type":"logic","position":{"x":535,"y":200},"data":{"dragged":true,"type":"logic","category":"lp","error":false,"code":"lp_posts","label":"Search Posts","settings":{"name":"","ids":"","title":"","body":"","date_mode":"","categories":"","tags":"","status":"publish","author":""}}},{"id":"3","type":"logic","position":{"x":709,"y":134},"data":{"dragged":true,"type":"logic","category":"un","code":"un_branch","label":"Branch","settings":{"name":"IF","criteria":"{{node#2.post_excerpt}}","operator":"is_unknown"},"error":false}},{"id":"4","type":"action","position":{"x":890,"y":46},"data":{"dragged":true,"type":"action","category":"ai","error":false,"code":"ai_generate_text","label":"Generate Text","settings":{"name":"Open AI - Generate Text","model":"gpt-4o","tokens":"4096","temperature":"0.7","prompt":"Write a short excerpt up to 130 character based on the post title: {{node#2.post_title}}"}}},{"id":"5","type":"action","position":{"x":1090,"y":45.253725775965},"data":{"dragged":true,"type":"action","category":"wp","error":false,"code":"wp_update_post","label":"Update Post","settings":{"id":"{{node#2.post_ID}}","title":"","body":"","excerpt":"{{node#4.content}}","status":"","author":""}}},{"id":"6","type":"action","position":{"x":726.50732078504,"y":322.88980344863},"data":{"dragged":true,"type":"action","category":"wp","error":false,"code":"wp_send_email","label":"Send Email","settings":{"to":"support@bizgpt.vn","from":"support@bizgpt.vn","from_name":"Max","subject":"Workflow completed","body":"Hi Max\\n\\nThe meta description generation workflow is complete - please check it out.\\n\\nThanks"}}}],"edges":[{"id":"1","source":"1","target":"2","sourceHandle":"output-right","targetHandle":"input-left","type":"default"},{"id":"2","source":"2","target":"3","sourceHandle":"output-then","targetHandle":"input-left","type":"default"},{"id":"3","source":"3","target":"4","sourceHandle":"output-then","targetHandle":"input-left","type":"default"},{"id":"4","source":"4","target":"5","sourceHandle":"output-right","targetHandle":"input-left","type":"default"},{"id":"5","source":"2","target":"6","sourceHandle":"output-else","targetHandle":"input-left","type":"default"}],"viewport":{"x":176.23629455824,"y":70.813761773201,"zoom":0.76962215630794},"settings":"","version":"1.0.0"}';
			if ( $forceSeedTemplates ) {
				WaicDb::query( "UPDATE `@__tasks` SET title='Bổ sung thêm tóm tắt, giới thiệu ngắn cho các bài viết', mode='2', message='Quy trình này sẽ quét nội dung của bạn, xác định các bài đăng không có đoạn trích và sử dụng trí tuệ nhân tạo để tạo ra các bản tóm tắt hấp dẫn dài 150 ký tự dựa trên tiêu đề bài đăng..', params='" . addslashes($json) . "' WHERE feature='template' AND (title='Bổ sung thêm tóm tắt, giới thiệu ngắn cho các bài viết' OR mode='2')" );
			}
			WaicDb::query( "INSERT INTO `@__tasks` (id, feature, title, mode, message, params) SELECT NULL, 'template', 'Bổ sung thêm tóm tắt, giới thiệu ngắn cho các bài viết', 2, 'Quy trình này sẽ quét nội dung của bạn, xác định các bài đăng không có đoạn trích và sử dụng trí tuệ nhân tạo để tạo ra các bản tóm tắt hấp dẫn dài 150 ký tự dựa trên tiêu đề bài đăng..', '" . addslashes($json) . "' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `@__tasks` WHERE feature='template' AND title='Bổ sung thêm tóm tắt, giới thiệu ngắn cho các bài viết' LIMIT 1);" );
			$json = '{"nodes":[{"id":"1","type":"trigger","position":{"x":350,"y":200},"data":{"dragged":true,"type":"trigger","category":"sy","code":"sy_manual","label":"Manually","settings":[],"error":false}},{"id":"2","type":"logic","position":{"x":535,"y":200},"data":{"dragged":true,"type":"logic","category":"lp","error":false,"code":"lp_posts","label":"Search Posts","settings":{"name":"","ids":"","title":"","body":"","date_mode":"","categories":"","tags":"","status":"publish","author":""}}},{"id":"3","type":"logic","position":{"x":714.93187267115,"y":123},"data":{"dragged":true,"type":"logic","category":"un","code":"un_branch","label":"Branch","settings":{"name":"IF","criteria":"{{node#2.post_image}}","operator":"is_unknown"},"error":false}},{"id":"4","type":"action","position":{"x":896,"y":69.666666666667},"data":{"dragged":true,"type":"action","category":"ai","error":false,"code":"ai_generate_image","label":"Open AI Generate Image","settings":{"name":"Generate Image","model":"dall-e-3","orientation":"horizontal","prompt":"Create a high-quality featured image for the article that visually interprets its central themes and ideas, based on the provided details: \\n\\n- Article Title: {{node#2.post_title}}\\n\\nThe image should embody the main concepts and mood of the article without including any text, ensuring it complements the content effectively. This visual representation should enhance the article appeal and provide deeper insight into its themes."}}},{"id":"6","type":"action","position":{"x":1269.3333333333,"y":68.333333333333},"data":{"dragged":true,"type":"action","category":"wp","error":false,"code":"wp_update_post_image","label":"Update Post Featured image","settings":{"id":"{{node#2.post_ID}}","image":"{{node#4.image_id}}","alt":"{{node#8.content}}"}}},{"id":"8","type":"action","position":{"x":1089.3333333333,"y":69},"data":{"dragged":true,"type":"action","category":"ai","error":false,"code":"ai_generate_text","label":"Open AI Generate Text","settings":{"name":"Generate Alt Text","model":"gpt-4o","tokens":"300","temperature":0.7,"prompt":"We created an image for an article about the topic &quot;{{node#2.post_title}}&quot;. \\n\\nPlease generate an Alt text for this image. \\nThe Alt text must be between 10-70 characters long. Your response should contain only the Alt text, with no additional text or explanations."}}}],"edges":[{"id":"1","source":"1","target":"2","sourceHandle":"output-right","targetHandle":"input-left","type":"default"},{"id":"2","source":"2","target":"3","sourceHandle":"output-then","targetHandle":"input-left","type":"default"},{"id":"3","source":"3","target":"4","sourceHandle":"output-then","targetHandle":"input-left","type":"default"},{"id":"6","source":"4","target":"8","sourceHandle":"output-right","targetHandle":"input-left","type":"default"},{"id":"7","source":"8","target":"6","sourceHandle":"output-right","targetHandle":"input-left","type":"default"}],"viewport":{"x":-961.82371544285,"y":65.956273933069,"zoom":1.5},"settings":"","version":"1.0.0"}';
			if ( $forceSeedTemplates ) {
				WaicDb::query( "UPDATE `@__tasks` SET title='Tạo ảnh nổi bật bị thiếu', mode='3', message='Tự động tạo ảnh nổi bật và văn bản thay thế (alt text) bằng AI cho tất cả các bài đăng thiếu hình ảnh. Phục vụ cho việc tối ưu hóa nội dung hàng loạt..', params='" . addslashes($json) . "' WHERE feature='template' AND (title='Tạo ảnh nổi bật bị thiếu' OR mode='3')" );
			}
			WaicDb::query( "INSERT INTO `@__tasks` (id, feature, title, mode, message, params) SELECT NULL, 'template', 'Tạo ảnh nổi bật bị thiếu', 3, 'Tự động tạo ảnh nổi bật và văn bản thay thế (alt text) bằng AI cho tất cả các bài đăng thiếu hình ảnh. Phục vụ cho việc tối ưu hóa nội dung hàng loạt..', '" . addslashes($json) . "' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `@__tasks` WHERE feature='template' AND title='Tạo ảnh nổi bật bị thiếu' LIMIT 1);" );
			$json = '{"nodes":[{"id":"1","type":"trigger","position":{"x":332,"y":198},"data":{"dragged":true,"type":"trigger","category":"wc","error":false,"code":"wc_new_order","label":"New Order Created","settings":{"status":"","total":"","customer":"","products":"","categories":"","tags":""}}},{"id":"7","type":"logic","position":{"x":628.33333333333,"y":196},"data":{"dragged":true,"type":"logic","category":"un","code":"un_branch","label":"Branch","settings":{"name":"is not completed","criteria":"{{node#1.order_status}}","operator":"does_not_equal","value":"Completed","compare":"text"},"error":false}},{"id":"8","type":"logic","position":{"x":491,"y":198.66666666667},"data":{"dragged":true,"type":"logic","category":"un","code":"un_delay","label":"Delay","settings":{"mode":"amount","days":"","hours":"1","minutes":"0"},"error":false}},{"id":"9","type":"action","position":{"x":782,"y":126.66666666667},"data":{"dragged":true,"type":"action","category":"wp","error":false,"code":"wp_send_email","label":"Send Email","settings":{"to":"{{node#1.user_email}}","from":"support@bizgpt.vn","from_name":"Max from AIWU","subject":"You left something in your cart 🛒","body":"Hey {{node#1.display_name}}! 👋\\n\\nWe noticed you didn&#039;t finish your order. Your cart is waiting for you:\\n\\n{{node#1.order_products}}\\n\\nTotal: {{node#1.order_total}}\\n\\nThese items are popular and might sell out soon! \\n\\n👉 Complete your order now:\\nhttps://bizcity.vn/cart\\n\\nQuestions? Just hit reply – we&#039;re here to help!\\n\\nCheers,\\nMax"}}},{"id":"10","type":"logic","position":{"x":922.91879759531,"y":125.17125070133},"data":{"dragged":true,"type":"logic","category":"un","code":"un_delay","label":"Delay","settings":{"mode":"amount","days":"1","hours":"0","minutes":"0"},"error":false}},{"id":"12","type":"logic","position":{"x":1092.5480638794,"y":124.5582176804},"data":{"dragged":true,"type":"logic","category":"un","code":"un_branch","label":"Branch","settings":{"name":"Still Pending Payment","criteria":"","operator":"equals","value":"","compare":"text"},"error":false}},{"id":"18","type":"action","position":{"x":1257.6187652117,"y":26.537738448272},"data":{"dragged":true,"type":"action","category":"wp","error":false,"code":"wp_send_email","label":"Send Email","settings":{"to":"{{node#1.user_email}}","from":"support@bizgpt.vn","from_name":"Max from AIWU","subject":"Reminder: You left something in your cart 🛒","body":"Hi {{node#1.display_name}}, still thinking about it? 🤔\\n\\nYour cart is still here, and honestly – we really think you&#039;ll love these items.\\n\\nSo here&#039;s a little nudge: 20% OFF just for you.\\n\\n{{node#1.order_products}}\\n\\nTotal: {{node#1.order_total}}\\n\\n💰 Use code: AIWU20 at checkout\\n\\nThis deal expires in 24 hours, so don&#039;t wait too long!\\n\\nComplete your order:\\nhttps://bizcity.vn/cart\\n\\nAny questions? Hit reply.\\n\\nMax\\nAIWU Team"}}}],"edges":[{"id":"6","source":"1","target":"8","sourceHandle":"output-right","targetHandle":"input-left","type":"default"},{"id":"7","source":"8","target":"7","sourceHandle":"output-right","targetHandle":"input-left","type":"default"},{"id":"8","source":"7","target":"9","sourceHandle":"output-then","targetHandle":"input-left","type":"default"},{"id":"9","source":"9","target":"10","sourceHandle":"output-right","targetHandle":"input-left","type":"default"},{"id":"10","source":"10","target":"12","sourceHandle":"output-right","targetHandle":"input-left","type":"default"},{"id":"11","source":"12","target":"18","sourceHandle":"output-then","targetHandle":"input-left","type":"default"}],"viewport":{"x":-311.49041249201,"y":8.5837701174823,"zoom":1.25},"settings":"","version":"1.0.0"}';
			if ( $forceSeedTemplates ) {
				WaicDb::query( "UPDATE `@__tasks` SET title='Email nhắc nhở giỏ hàng bị bỏ quên', mode='4', message='Tự động khôi phục doanh số bị mất! Gửi lời nhắc giỏ hàng theo thời gian với các ưu đãi giảm giá cho khách hàng bỏ dở đơn hàng..', params='" . addslashes($json) . "' WHERE feature='template' AND (title='Email nhắc nhở giỏ hàng bị bỏ quên' OR mode='4')" );
			}
			WaicDb::query( "INSERT INTO `@__tasks` (id, feature, title, mode, message, params) SELECT NULL, 'template', 'Email nhắc nhở giỏ hàng bị bỏ quên', 4, 'Tự động khôi phục doanh số bị mất! Gửi lời nhắc giỏ hàng theo thời gian với các ưu đãi giảm giá cho khách hàng bỏ dở đơn hàng..', '" . addslashes($json) . "' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `@__tasks` WHERE feature='template' AND title='Email nhắc nhở giỏ hàng bị bỏ quên' LIMIT 1);" );
			$json = '{"nodes":[{"id":"1","type":"trigger","position":{"x":350,"y":200},"data":{"dragged":true,"type":"trigger","category":"wc","error":false,"code":"wc_new_order","label":"New Order Created","settings":{"status":["completed"],"total":"","customer":"first","products":"","categories":"","tags":""}}},{"id":"3","type":"action","position":{"x":530,"y":200},"data":{"dragged":true,"type":"action","category":"wp","error":false,"code":"wp_send_email","label":"Send Email","settings":{"to":"{{node#1.user_email}}","from":"support@bizgpt.vn","from_name":"Max from AIWU","subject":"Thank you for your first order! 🎉","body":"Hey {{node#1.billing_first_name}} 👋\\n\\nWe&#039;re absolutely thrilled to have you as a customer! Your order has been confirmed and is on its way.\\n\\n🎁 As a thank you for choosing us, here&#039;s a special gift: use code WELCOME15 on your next purchase for 15% off!\\n\\n📦 Order Details:\\n{{node#1.order_ID}}\\n\\nBest Regards,\\nAdmin"}}}],"edges":[{"id":"2","source":"1","target":"3","sourceHandle":"output-right","targetHandle":"input-left","type":"default"}],"viewport":{"x":-99.940278981879,"y":-73.739280322291,"zoom":1.5},"settings":"","version":"1.0.0"}';
			if ( $forceSeedTemplates ) {
				WaicDb::query( "UPDATE `@__tasks` SET title='Email cảm ơn – Khách hàng mua lần đầu tiên', mode='5', message='Tự động gửi email cảm ơn cho khách hàng lần đầu tiên. Bao gồm chi tiết đơn hàng và mã giảm giá chào mừng để khuyến khích mua hàng lặp lại..', params='" . addslashes($json) . "' WHERE feature='template' AND (title='Email cảm ơn – Khách hàng mua lần đầu tiên' OR mode='5')" );
			}
			WaicDb::query( "INSERT INTO `@__tasks` (id, feature, title, mode, message, params) SELECT NULL, 'template', 'Email cảm ơn – Khách hàng mua lần đầu tiên', 5, 'Tự động gửi email cảm ơn cho khách hàng lần đầu tiên. Bao gồm chi tiết đơn hàng và mã giảm giá chào mừng để khuyến khích mua hàng lặp lại..', '" . addslashes($json) . "' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `@__tasks` WHERE feature='template' AND title='Email cảm ơn – Khách hàng mua lần đầu tiên' LIMIT 1);" );
		}
		
		} catch ( Exception $e ) {
			// Log error nhưng không crash
			error_log( '[WAIC Migration] Error: ' . $e->getMessage() );
			self::$migration_checked = true;
			return;
		}
		
		// Set cache - migration đã chạy thành công
		set_transient( $cache_key, 'yes', DAY_IN_SECONDS );
		self::$migration_checked = true;
	}
	
	/**
	 * Migrate table prefixes from waic_ to bizcity_
	 * This allows smooth transition to new plugins while keeping old data
	 */
	public static function migrateTablePrefixes() {
		global $wpdb;
		
		$prefix = $wpdb->prefix;
		
		// Check if migration already done
		$migration_done = get_option('bizcity_table_migration_done', false);
		if ($migration_done) {
			return;
		}
		
		// Tables to migrate
		$tables = array(
			'modules',
			'workspace',
			'tasks',
			'workflows',
			'flowruns',
			'flowlogs',
			'posts_create',
			'history',
			'chatlogs',
			'datasets',
			'ds_data',
			'ds_posts',
			'em_chunks',
			'formlogs',
			'training',
			'relations',
		);
		
		foreach ($tables as $table) {
			$old_table = $prefix . 'waic_' . $table;
			$new_table = $prefix . 'bizcity_' . $table;
			
			// Check if old table exists and new table doesn't
			$old_exists = $wpdb->get_var("SHOW TABLES LIKE '$old_table'") === $old_table;
			$new_exists = $wpdb->get_var("SHOW TABLES LIKE '$new_table'") === $new_table;
			
			if ($old_exists && !$new_exists) {
				// Rename table
				$wpdb->query("RENAME TABLE `$old_table` TO `$new_table`");
				error_log("[BizCity Migration] Renamed table: $old_table -> $new_table");
			}
		}
		
		// Update option names from waic_ to bizcity_
		$option_prefixes = array(
			'waic_db_version' => 'bizcity_db_version',
			'waic_db_installed' => 'bizcity_db_installed',
			'waic_first_activation' => 'bizcity_first_activation',
			'waic_plug_was_used' => 'bizcity_plug_was_used',
		);
		
		foreach ($option_prefixes as $old_option => $new_option) {
			$value = get_option($prefix . $old_option);
			if ($value !== false) {
				update_option($prefix . $new_option, $value);
			}
		}
		
		// Mark migration as done
		update_option('bizcity_table_migration_done', true);
		error_log("[BizCity Migration] Table prefix migration completed");
	}
	}
