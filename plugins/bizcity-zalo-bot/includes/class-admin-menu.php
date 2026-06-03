<?php
/**
 * Admin Menu & Settings Page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_Admin_Menu {
	
	private static $instance = null;
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );

		// Zalo pages are now managed under Gateway (class-admin-menu.php centralized).
		// BizChat Menu registration removed — no longer duplicated under Chat.
		
		add_action( 'wp_ajax_bizcity_zalo_bot_save', array( $this, 'ajax_save_bot' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_delete', array( $this, 'ajax_delete_bot' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_test', array( $this, 'ajax_test_bot' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_set_webhook', array( $this, 'ajax_set_webhook' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_start_listener', array( $this, 'ajax_start_listener' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_check_listener', array( $this, 'ajax_check_listener' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_stop_listener', array( $this, 'ajax_stop_listener' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_send_message', array( $this, 'ajax_send_message' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_send_photo', array( $this, 'ajax_send_photo' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_get_user_ids', array( $this, 'ajax_get_user_ids' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_build_memory', array( $this, 'ajax_build_memory' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_get_me', array( $this, 'ajax_get_me' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_get_updates', array( $this, 'ajax_get_updates' ) );
		add_action( 'wp_ajax_bizcity_zalo_bot_delete_webhook', array( $this, 'ajax_delete_webhook' ) );
		add_action( 'wp_ajax_bizcity_zalobot_unlink_user', array( $this, 'ajax_unlink_user' ) );
		// PHASE-0.35 GURU-ZALO-BOT §1.6
		add_action( 'wp_ajax_bizcity_zalobot_save_guru_settings', array( $this, 'ajax_save_guru_settings' ) );
	}
	
	/**
	 * Add admin menu
	 */
	public function add_menu() {
		add_menu_page(
			'Bots - Zalo',
			'Bots - Zalo',
			'manage_options',
			'bizcity-zalo-bots',
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			30
		);
		
		add_submenu_page(
			'bizcity-zalo-bots',
			'Tất cả Bots',
			'Tất cả Bots',
			'manage_options',
			'bizcity-zalo-bots',
			array( $this, 'render_page' )
		);
		
		add_submenu_page(
			'bizcity-zalo-bots',
			'Nghe Webhook',
			'Nghe Webhook',
			'manage_options',
			'bizcity-zalo-bot-listener',
			array( $this, 'render_listener_page' )
		);

		// ── Kết nối Zalo — User linking management ──
		add_submenu_page(
			'bizcity-zalo-bots',
			'Kết nối Zalo ↔ Tài khoản',
			'Kết nối Zalo',
			'manage_options',
			'bizcity-zalobot-connections',
			array( $this, 'render_connections_page' )
		);
		
		add_submenu_page(
			'bizcity-zalo-bots',
			'Test API',
			'Test API',
			'manage_options',
			'bizcity-zalo-bot-test-api',
			array( $this, 'render_test_api_page' )
		);
		
		add_submenu_page(
			'bizcity-zalo-bots',
			'Nhật ký',
			'Nhật ký',
			'manage_options',
			'bizcity-zalo-bot-logs',
			array( $this, 'render_logs_page' )
		);
		
		add_submenu_page(
			'bizcity-zalo-bots',
			'Phân tích Ký ức',
			'Ký ức',
			'manage_options',
			'bizcity-zalo-bot-memory',
			array( $this, 'render_memory_page' )
		);

		// PHASE-0.35 GURU-ZALO-BOT §1.6 — Guru AI binding settings.
		add_submenu_page(
			'bizcity-zalo-bots',
			'Guru AI',
			'🤖 Guru AI',
			'manage_options',
			'bizcity-zalo-bot-guru',
			array( $this, 'render_guru_page' )
		);
	}

	// register_bizchat_menus() removed — Zalo pages consolidated under Gateway.
	
	
	/**
	 * Render main page
	 */
	public function render_page() {
		$db = BizCity_Zalo_Bot_Database::instance();
		$bots = $db->get_active_bots();
		
		$action = isset( $_GET['action'] ) ? $_GET['action'] : 'list';
		$bot_id = isset( $_GET['bot_id'] ) ? intval( $_GET['bot_id'] ) : 0;
		
		if ( $action === 'edit' && $bot_id > 0 ) {
			$bot = $db->get_bot( $bot_id );
			$this->render_edit_form( $bot );
		} elseif ( $action === 'add' ) {
			$this->render_edit_form( null );
		} else {
			$this->render_list( $bots );
		}
	}
	
	/**
	 * Render bot list
	 */
	private function render_list( $bots ) {
		?>
		<style>
		.bizcity-zalo-bot-wrap .status-active {
			background: linear-gradient(45deg, #4CAF50, #45a049);
			color: white;
			padding: 4px 12px;
			border-radius: 15px;
			font-size: 11px;
			font-weight: bold;
			box-shadow: 0 2px 4px rgba(76,175,80,0.3);
			display: inline-block;
		}
		
		.bizcity-zalo-bot-wrap .status-inactive {
			background: linear-gradient(45deg, #ff9800, #f57c00);
			color: white;
			padding: 4px 12px;
			border-radius: 15px;
			font-size: 11px;
			font-weight: bold;
			box-shadow: 0 2px 4px rgba(255,152,0,0.3);
			display: inline-block;
		}
		
		.bizcity-zalo-bot-wrap .wp-list-table td {
			vertical-align: middle;
		}
		</style>
		<div class="wrap bizcity-zalo-bot-wrap">
			<h1>
				Bước 1: Quản lý Zalo Bot
				<a href="<?php echo admin_url( 'admin.php?page=bizcity-zalo-bots&action=add' ); ?>" class="page-title-action">
					<span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
					Thêm Bot mới
				</a>
			</h1>
			
			<?php
			// ── Workflow Steps Banner ──
			if ( class_exists( 'BizCity_Zalo_Bot_Dashboard' ) ) {
				BizCity_Zalo_Bot_Dashboard::render_workflow_steps( 1 );
			}
			?>
			
			<!-- Step Navigation -->
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard' ) ); ?>" class="button">📊 Dashboard</a>
				<span style="font-weight:600;color:#6366f1">🤖 Bước 1: Tạo & Cấu hình Bots</span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-test-api' ) ); ?>" class="button button-primary">Bước 2: Test Bots →</a>
			</div>
			
			<div style="background: #e7f5ff; border-left: 4px solid #2196F3; padding: 15px 20px; margin: 20px 0; border-radius: 4px;">
				<p style="margin: 0;">
					<strong>Mẹo:</strong> Để tạo Zalo Bot, vui lòng làm theo hướng dẫn bên dưới.
				</p>
			</div>
			
			<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
			<table class="wp-list-table widefat fixed striped" style="border: none;">
				<thead>
					<tr>
						<th style="width: 60px;">ID</th>
						<th style="width: 200px;">Tên Bot</th>
						<th>Webhook URL</th>
						<th style="width: 100px;">Trạng thái</th>
						<th style="width: 280px;">Hành động</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $bots ) ) : ?>
						<tr>
							<td colspan="6">Chưa có bot nào. Nhấn "Thêm mới" để tạo bot đầu tiên.</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $bots as $bot ) : ?>
							<tr>
								<td><?php echo esc_html( $bot->id ); ?></td>
								<td>
									<div style="display: flex; align-items: center; gap: 10px;">
										<strong><?php echo esc_html( $bot->bot_name ); ?></strong>
										<button type="button" class="set-webhook-btn" data-bot-id="<?php echo esc_attr( $bot->id ); ?>" 
											style="background: linear-gradient(45deg, #4CAF50, #45a049); color: white; border: none; padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; cursor: pointer; box-shadow: 0 2px 4px rgba(76,175,80,0.3); transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 4px;" 
											title="Nhấn để kích hoạt webhook Zalo Bot. Bot sẽ tự động nhận và xử lý tin nhắn từ Zalo sau khi kích hoạt."
											onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 4px 8px rgba(76,175,80,0.4)'"
											onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 2px 4px rgba(76,175,80,0.3)'">
											🚀 Kích hoạt Bot
										</button>
									</div>
								</td>
								<td>
									<input type="text" readonly value="<?php echo esc_url( home_url( '/zalohook/' ) ); ?>" style="width: 100%;" onclick="this.select();" />
								</td>
								<td>
									<span class="status-<?php echo esc_attr( $bot->status ); ?>">
										<?php echo esc_html( ucfirst( $bot->status ) ); ?>
									</span>
								</td>
								<td>
									<a href="<?php echo admin_url( 'admin.php?page=bizcity-zalo-bots&action=edit&bot_id=' . $bot->id ); ?>">
										<?php _e( 'Edit', 'bizcity-zalo-bot' ); ?>
									</a>
									|
									<a href="#" class="test-bot" data-bot-id="<?php echo esc_attr( $bot->id ); ?>">
										<?php _e( 'Test', 'bizcity-zalo-bot' ); ?>
									</a>
									|
									<button type="button" class="set-webhook-btn" data-bot-id="<?php echo esc_attr( $bot->id ); ?>" 
										style="background: linear-gradient(45deg, #2196F3, #1976D2); color: white; border: none; padding: 4px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; cursor: pointer; box-shadow: 0 1px 3px rgba(33,150,243,0.3); transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 2px;" 
										title="Kích hoạt webhook để bot có thể nhận tin nhắn từ Zalo tự động"
										onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 2px 6px rgba(33,150,243,0.4)'"
										onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 1px 3px rgba(33,150,243,0.3)'">
										⚙️ Kích hoạt
									</button>
									|
									<button type="button" class="get-me-btn" data-bot-id="<?php echo esc_attr( $bot->id ); ?>" 
										style="background: linear-gradient(45deg, #4CAF50, #45a049); color: white; border: none; padding: 4px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; cursor: pointer; box-shadow: 0 1px 3px rgba(76,175,80,0.3); transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 2px;"
										title="Kiểm tra thông tin bot và kết nối API"
										onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 2px 6px rgba(76,175,80,0.4)'"
										onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 1px 3px rgba(76,175,80,0.3)'">
										🤖 GetMe
									</button>
									|
									<a href="#" class="delete-bot" data-bot-id="<?php echo esc_attr( $bot->id ); ?>" style="color: #dc3232;">
										<?php _e( 'Delete', 'bizcity-zalo-bot' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			</div>
			
			<!-- Hướng dẫn tạo Bot -->
			<div style="background: #fff; border-left: 4px solid #00a0d2; padding: 25px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					Hướng dẫn tạo Zalo Bot
				</h2>
				<p>Để tạo Zalo Bot, vui lòng thực hiện theo hướng dẫn sau:</p>
				
				<div style="display: flex; gap: 30px; align-items: flex-start; flex-wrap: wrap;">
					<div style="flex: 1; min-width: 300px;">
						<h3>Bước 1: Truy cập Zalo OA</h3>
						<ul>
							<li>Mở ứng dụng Zalo</li>
							<li>Tìm kiếm OA <strong>Zalo Bot Manager</strong></li>
							<li>Chọn <strong>Tạo bot</strong> trong menu cửa sổ chat để truy cập ứng dụng <strong>Zalo Bot Creator</strong></li>
						</ul>
					</div>
					<div style="flex: 0 0 auto; text-align: center;">
						<img src="https://bot.zapps.me/images/zbot-creator_qrcode.jpg" alt="Zalo Bot Creator QR Code" style="max-width: 200px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);" />
						<p style="margin-top: 10px; font-size: 12px; color: #666;">Quét mã QR để truy cập nhanh</p>
					</div>
				</div>
				
				<h3>Bước 2: Thiết lập thông tin Bot</h3>
				<ul>
					<li>Nhập tên Bot (bắt buộc bắt đầu bằng tiền tố Bot, ví dụ: <code>Bot MyShop</code>) và các thông tin cần thiết.</li>
					<li>Nhấn <strong>Tạo Bot</strong> để xác nhận</li>
					<li>Sau khi tạo thành công, hệ thống sẽ gửi:
						<ul>
							<li>Thông tin Bot</li>
							<li><code>Bot Token</code> qua tin nhắn cho tài khoản Zalo của bạn.</li>
						</ul>
					</li>
				</ul>
				
				<p style="margin-bottom: 0;">
					<strong>Mẹo:</strong> Sau khi nhận được Bot Token, nhấn nút <strong>"Thêm mới"</strong> phía trên để thêm bot vào hệ thống.
				</p>
			</div>
			
			<!-- Giải thích 2 chế độ nhận tin nhắn -->
			<div style="background: #fff; border-left: 4px solid #ff9800; padding: 25px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					Hai chế độ nhận tin nhắn
				</h2>
				<p><strong>Zalo Bot API hỗ trợ 2 cách nhận tin nhắn độc lập - chỉ được dùng 1 cách tại 1 thời điểm:</strong></p>
				
				<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
					<div style="padding: 20px; background: #e3f2fd; border-radius: 8px; border: 1px solid #2196f3;">
						<h3 style="margin-top: 0; color: #1976d2;">
							Webhook Mode (Tự động)
						</h3>
						<ul style="margin: 0;">
							<li>✅ <strong>Được khuyến nghị</strong></li>
							<li>🔄 Zalo tự động gửi tin nhắn đến server</li>
							<li>⚡ Phản hồi ngay lập tức</li>
							<li>🏠 Yêu cầu có domain HTTPS</li>
							<li>🎯 Bot nhận tin nhắn tự động</li>
						</ul>
						<p style="margin: 15px 0 5px 0; color: #666;"><strong>Cách sử dụng:</strong></p>
						<p style="margin: 0; font-size: 13px;">Nhấn nút <strong style="color: #2196F3;">⚙️ Kích hoạt</strong> trong danh sách bot</p>
					</div>
					
					<div style="padding: 20px; background: #f3e5f5; border-radius: 8px; border: 1px solid #9c27b0;">
						<h3 style="margin-top: 0; color: #7b1fa2;">
							getUpdates Mode (Thủ công)
						</h3>
						<ul style="margin: 0;">
							<li>🛠️ <strong>Chế độ test/debug</strong></li>
							<li>🔍 Tự chủ động lấy tin nhắn</li>
							<li>⏱️ Phải gọi API liên tục</li>
							<li>🌐 Không cần domain</li>
							<li>📋 Thích hợp test và phát triển</li>
						</ul>
						<p style="margin: 15px 0 5px 0; color: #666;"><strong>Cách sử dụng:</strong></p>
						<p style="margin: 0; font-size: 13px;">
							1. Nhấn <strong style="color: #ff9800;">🔄 Polling</strong> để tắt webhook<br>
							2. Nhấn <strong style="color: #9c27b0;">Get Updates</strong> để lấy tin
						</p>
					</div>
				</div>
				
				<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin-top: 20px;">
					<strong>Lưu ý quan trọng:</strong>
					<ul style="margin: 10px 0 0 20px;">
						<li><strong>Chỉ một chế độ hoạt động tại một thời điểm</strong></li>
						<li>Nếu đang dùng Webhook, phải xóa webhook trước khi dùng getUpdates</li>
						<li>Nếu đang dùng getUpdates, phải ngưng polling trước khi thiết lập webhook</li>
						<li>Chế độ Webhook được khuyến nghị cho production</li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render edit form
	 */
	private function render_edit_form( $bot ) {
		$is_new = ! $bot;
		?>
		<div class="wrap bizcity-zalo-bot-wrap">
			<h1><?php echo $is_new ? 'Thêm Bot mới' : 'Sửa Bot'; ?></h1>
			
			<form method="post" id="bot-form">
				<input type="hidden" name="bot_id" value="<?php echo $is_new ? '' : esc_attr( $bot->id ); ?>" />
				
				<table class="form-table">
					<tr>
					<th scope="row"><label>Tên Bot</label></th>
					<td>
						<input type="text" name="bot_name" value="<?php echo $is_new ? '' : esc_attr( $bot->bot_name ); ?>" class="regular-text" required />
						<p class="description">Nhập tên để nhận diện bot, ví dụ: "Bot Hỗ trợ khách hàng"</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label>Bot Token</label></th>
					<td>
						<div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
							<div style="flex: 1; min-width: 300px;">
								<input type="text" name="bot_token" value="<?php echo $is_new ? '' : esc_attr( $bot->bot_token ); ?>" class="large-text" required style="width: 100%;" />
								<p class="description">Lấy token từ <a href="https://bot.zalo.me/" target="_blank">Zalo Bot Creator</a></p>
								<p>Sau khi đăng ký tạo Bot, tự Bot sẽ nhắn cho bạn mã token như trong hình, bạn copy vào đây.</p>
							</div>
							<div style="flex: 0 0 auto; text-align: center;">
								<img src="https://media.bizcity.vn/uploads/sites/1375/2026/01/Untitled-1.jpg" alt="Copy Bot Token" style="max-width: 250px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); border: 1px solid #ddd;" />
								<p style="margin-top: 10px; font-size: 13px; color: #666; font-weight: 500;">Copy bot token tại đây</p>
							</div>
						</div>
					</td>
				</tr>
					
				<tr>
					<th scope="row"><label>Mã bảo mật Webhook</label></th>
					<td>
						<div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
							<div style="flex: 1; min-width: 300px;">
								<input type="text" name="webhook_secret" value="<?php echo $is_new ? '' : esc_attr( $bot->webhook_secret ); ?>" class="regular-text" minlength="8" maxlength="64" style="width: 100%;" />
								<p class="description">Bạn hãy đặt mã bảo mật để xác thực webhook (yêu cầu 8-64 ký tự)</p>
							</div>
							<div style="flex: 0 0 auto; text-align: center;">
								<img src="https://media.bizcity.vn/uploads/sites/1258/2026/02/Untitled-2.jpg" alt="Webhook URL và Secret" style="max-width: 250px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); border: 1px solid #ddd;" />
								<p style="margin-top: 10px; font-size: 13px; color: #666; font-weight: 500;">Webhook URL và mã bảo mật WebHook<br>nhập đặt sẽ được điền ở đây</p>
							</div>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label>Webhook URL</label></th>
					<td>
						<input type="text" readonly value="<?php echo esc_url( home_url( '/zalohook/' ) ); ?>" class="large-text" onclick="this.select();" />
						<p class="description">Copy URL này và dán vào phần cài đặt của Zalo Bot Creator</p>
					</td>
				</tr>
					
					<?php if ( ! $is_new ): ?>
					<tr>
						<th scope="row"><label>Cài đặt Webhook</label></th>
						<td>
							<button type="button" class="button button-primary" id="btn-set-webhook" data-bot-id="<?php echo esc_attr( $bot->id ); ?>" 
								style="background: linear-gradient(45deg, #4CAF50, #45a049); border: none; padding: 12px 24px; border-radius: 25px; font-weight: bold; box-shadow: 0 4px 12px rgba(76,175,80,0.3); transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; font-size: 14px;"
								title="Nhấn để tự động cài đặt webhook URL và kích hoạt bot Zalo. Bot sẽ bắt đầu nhận tin nhắn ngay sau khi kích hoạt thành công."
								onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(76,175,80,0.4)'"
								onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(76,175,80,0.3)'">
								🚀 Nhấn vào để kích hoạt Zalo Bot hoạt động
							</button>
							<p class="description">
								<?php 
								$webhook_url = trailingslashit( get_site_url() ) . 'zalohook/';
								printf( 
									'Nhấn để tự động kích hoạt webhook URL vào cài đặt ZaloBot: %s', 
									'<code style="color:#fff">' . esc_html( $webhook_url ) . '</code>' 
								); 
								?>
							</p>
							<div id="webhook-result" style="margin-top: 10px;"></div>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label>Trạng thái</label></th>
						<td>
							<select name="status">
								<option value="active" <?php echo ( ! $is_new && $bot->status === 'active' ) ? 'selected' : ''; ?>>✅ Kích hoạt</option>
								<option value="inactive" <?php echo ( ! $is_new && $bot->status === 'inactive' ) ? 'selected' : ''; ?>>⏸️ Tạm dừng</option>
							</select>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<button type="submit" class="button button-primary">💾 Lưu Bot</button>
					<a href="<?php echo admin_url( 'admin.php?page=bizcity-zalo-bots' ); ?>" class="button">❌ Hủy</a>
				</p>
			</form>
		</div>
		<?php
	}
	
	/**
	 * Render webhook listener page
	 */
	public function render_listener_page() {
		$db = BizCity_Zalo_Bot_Database::instance();
		$bots = $db->get_active_bots();
		?>
		<div class="wrap bizcity-zalo-bot-wrap">
			<h1>
				Bước 2: Nghe Webhook
			</h1>
			
			<?php
			// ── Workflow Steps Banner ──
			if ( class_exists( 'BizCity_Zalo_Bot_Dashboard' ) ) {
				BizCity_Zalo_Bot_Dashboard::render_workflow_steps( 2 );
			}
			?>
			
			<!-- Step Navigation -->
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bots' ) ); ?>" class="button">← Bước 1: Tạo Bots</a>
				<span style="font-weight:600;color:#6366f1">📡 Bước 2: Nghe Webhook</span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-assign' ) ); ?>" class="button button-primary">Bước 3: Gán Bots →</a>
			</div>
			
			<p class="description">
				Kiểm tra webhook Zalo Bot theo thời gian thực. Bắt đầu lắng nghe, sau đó gửi tin nhắn hoặc hình ảnh đến bot của bạn.
			</p>
			
			<?php if ( empty( $bots ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php printf( 
							'Chưa có bot nào hoạt động. Vui lòng <a href="%s">thêm bot</a> trước.',
							admin_url( 'admin.php?page=bizcity-zalo-bots&action=add' )
						); ?>
					</p>
				</div>
			<?php else : ?>
				
				<div class="listener-container" style="max-width: 900px;">
					<div class="listener-settings" style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
						<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
							Cài đặt Listener
						</h2>
						
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="listener-bot-select">Chọn Bot</label>
								</th>
								<td>
									<select id="listener-bot-select" class="regular-text" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px;">
										<option value="">-- Chọn một bot --</option>
										<?php foreach ( $bots as $bot ) : ?>
											<option value="<?php echo esc_attr( $bot->id ); ?>" data-webhook-url="<?php echo esc_url( home_url( '/zalohook/' ) ); ?>">
												<?php echo esc_html( $bot->bot_name ); ?>
												<?php if ( $bot->webhook_secret ) : ?>
													(🔒 Secured)
												<?php endif; ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
									Chọn bot để lắng nghe webhook.
								</p>
							</td>
						</tr>
						<tr id="bot-info-row" style="display:none;">
							<th scope="row">Thông tin Bot</th>
								<td>
									<div id="bot-info-content"></div>
								</td>
							</tr>
						</table>
						
						<div style="padding-top: 20px; border-top: 2px solid #f0f0f0; margin-top: 20px;">
							<button type="button" class="button button-primary button-hero" id="btn-start-listening" disabled>
								<span class="dashicons dashicons-controls-play"></span>
								Bắt đầu nghe
							</button>
							<button type="button" class="button button-secondary button-hero" id="btn-stop-listening" style="display:none;">
								<span class="dashicons dashicons-controls-pause"></span>
								Dừng lắng nghe
							</button>
						</div>
					</div>
					
					<div id="listener-status-container" style="display:none; margin: 20px 0;"></div>
					
					<div id="listener-results-container" style="display:none; margin: 20px 0;">
						<div style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
							<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
								Đã nhận dữ liệu Webhook
							</h2>
							<div id="listener-results-content"></div>
						</div>
					</div>
					
					<div class="listener-instructions" style="background: #e5f5fa; padding: 20px; border-radius: 8px; border-left: 4px solid #00a0d2; margin: 20px 0;">
						<h3 style="margin-top: 0;">
							Hướng dẫn kiểm tra
						</h3>
						<ol style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
							<li><strong>Bước 1:</strong> Chọn bot từ dropdown phía trên</li>
							<li><strong>Bước 2:</strong> Nhấn nút "Bắt đầu nghe"</li>
							<li><strong>Bước 3:</strong> Mở app Zalo và gửi tin nhắn đến bot của bạn:
								<ul style="margin: 10px 0 10px 20px; list-style-type: disc;">
									<li><strong>Tin nhắn text:</strong> Gõ bất kỳ text nào, ví dụ: <code>"Xin chào bot"</code></li>
									<li><strong>Tin nhắn hình ảnh:</strong> Gửi bất kỳ ảnh hoặc hình ảnh nào</li>
								</ul>
							</li>
							<li><strong>Bước 4:</strong> Dữ liệu webhook sẽ hiển thị tự động bên dưới</li>
						</ol>
						<div style="margin: 20px 0 0 0; padding: 15px; background: #fff3cd; border-radius: 4px; border-left: 3px solid #ffc107;">
							<p style="margin: 0;"><strong>Lưu ý quan trọng:</strong></p>
							<ul style="margin: 10px 0 0 20px;">
								<li>Listener sẽ tự động dừng sau <strong>5 phút</strong> hoặc khi nhận được webhook</li>
								<li>Đảm bảo bot đã được cài đặt webhook trước khi kiểm tra</li>
								<li>Kiểm tra xem bạn đã cấu hình đúng <strong>Webhook Secret</strong> chưa</li>
							</ul>
						</div>
					</div>
				</div>
				
			<?php endif; ?>
		</div>
		<?php
	}
	
	/**
	 * Render Test API page
	 */
	public function render_test_api_page() {
		$db = BizCity_Zalo_Bot_Database::instance();
		$bots = $db->get_active_bots();
		?>
		<div class="wrap bizcity-zalo-bot-wrap">
			<h1>
				Bước 2: Test API
			</h1>
			
			<?php
			// ── Workflow Steps Banner ──
			if ( class_exists( 'BizCity_Zalo_Bot_Dashboard' ) ) {
				BizCity_Zalo_Bot_Dashboard::render_workflow_steps( 2 );
			}
			?>
			
			<!-- Step Navigation -->
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bots' ) ); ?>" class="button">← Bước 1: Tạo Bots</a>
				<span style="font-weight:600;color:#6366f1">🧪 Bước 2: Test Bots</span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-assign' ) ); ?>" class="button button-primary">Bước 3: Gán Bots →</a>
			</div>
			
			<p class="description">
				Kiểm tra các tính năng API của Zalo Bot. Chọn bot và thử gửi tin nhắn hoặc hình ảnh.
			</p>
			
			<?php if ( empty( $bots ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php printf( 
							'Chưa có bot nào hoạt động. Vui lòng <a href="%s">thêm bot</a> trước.',
							admin_url( 'admin.php?page=bizcity-zalo-bots&action=add' )
						); ?>
					</p>
				</div>
			<?php else : ?>
				
				<div class="test-api-container" style="max-width: 1200px;">
					<!-- Bot Selection -->
					<div class="test-api-settings" style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
						<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">

						</h2>
						
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="test-api-bot-select">Bot</label>
								</th>
								<td>
									<select id="test-api-bot-select" class="regular-text" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px;">
										<option value="">-- Chọn một bot --</option>
										<?php foreach ( $bots as $bot ) : ?>
											<option value="<?php echo esc_attr( $bot->id ); ?>" data-bot-token="<?php echo esc_attr( $bot->bot_token ); ?>">
												<?php echo esc_html( $bot->bot_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>
					</div>
					
					<!-- sendMessage Test -->
					<div class="test-api-section" style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
						<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
						sendMessage - Gửi tin nhắn text
						
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="send-message-chat-id">Chat ID</label>
								</th>
								<td>
									<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
										<select id="send-message-user-select" class="regular-text" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px; flex: 1; min-width: 200px;">
											<option value="">-- Hoặc chọn từ danh sách --</option>
										</select>
										<input type="text" id="send-message-chat-id" class="regular-text" placeholder="Nhập User ID hoặc chọn bên dưới" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px; flex: 1; min-width: 250px;" />
										
									</div>
									<p class="description">💡 Nhập User ID thủ công hoặc chọn từ danh sách người dùng đã nhắn tin với bot</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="send-message-text">Text</label>
								</th>
								<td>
									<textarea id="send-message-text" class="large-text" rows="4" placeholder="Hello" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px;">Hello</textarea>
									<p class="description">Nội dung tin nhắn</p>
								</td>
							</tr>
						</table>
						
						<p>
							<button type="button" class="button button-primary button-large" id="btn-send-message">
								Gửi tin nhắn
							</button>
						</p>
						
						<div id="send-message-result" style="margin-top: 20px;"></div>
						
						<details style="margin-top: 20px; padding: 15px; background: #f8f8f8; border-radius: 4px;">
							<summary style="cursor: pointer; font-weight: bold;">Xem cURL Example</summary>
							<pre style="background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; margin-top: 10px;"><code>curl -X POST "https://bot-api.zaloplatforms.com/bot&lt;BOT_TOKEN&gt;/sendMessage" \
  -H "Content-Type: application/json" \
  -d '{
    "chat_id": "abc.xyz",
    "text": "Hello"
  }'</code></pre>
						</details>
					</div>
					
					<!-- sendPhoto Test -->
					<div class="test-api-section" style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
						<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
						sendPhoto - Gửi hình ảnh
						
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="send-photo-chat-id">Chat ID</label>
								</th>
								<td>
									<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
										<select id="send-photo-user-select" class="regular-text" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px; flex: 1; min-width: 200px;">
											<option value="">-- Hoặc chọn từ danh sách --</option>
										</select>
										<input type="text" id="send-photo-chat-id" class="regular-text" placeholder="Nhập User ID hoặc chọn bên dưới" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px; flex: 1; min-width: 250px;" />
										
									</div>
									<p class="description">💡 Nhập User ID thủ công hoặc chọn từ danh sách người dùng đã nhắn tin với bot</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="send-photo-url">Photo URL</label>
								</th>
								<td>
									<input type="url" id="send-photo-url" class="large-text" placeholder="https://placehold.co/600x400" value="https://placehold.co/600x400" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px;" />
									<p class="description">URL của hình ảnh (phải là URL công khai)</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="send-photo-caption">Caption</label>
								</th>
								<td>
									<textarea id="send-photo-caption" class="large-text" rows="3" placeholder="My photo" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px;">My photo</textarea>
									<p class="description">Mô tả hình ảnh (tùy chọn)</p>
								</td>
							</tr>
						</table>
						
						<p>
							<button type="button" class="button button-primary button-large" id="btn-send-photo">
								Gửi hình ảnh
							</button>
						</p>
						
						<div id="send-photo-result" style="margin-top: 20px;"></div>
						
						<details style="margin-top: 20px; padding: 15px; background: #f8f8f8; border-radius: 4px;">
							<summary style="cursor: pointer; font-weight: bold;">Xem cURL Example</summary>
							<pre style="background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; margin-top: 10px;"><code>curl -X POST "https://bot-api.zaloplatforms.com/bot&lt;BOT_TOKEN&gt;/sendPhoto" \
  -H "Content-Type: application/json" \
  -d '{
    "chat_id": "abc.xyz",
    "caption": "My photo",
    "photo": "https://placehold.co/600x400"
  }'</code></pre>
						</details>
					</div>
					
					<!-- Instructions -->
					<div class="test-api-instructions" style="background: #fff3cd; padding: 20px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 20px 0;">
						<h3 style="margin-top: 0;">
							<span class="dashicons dashicons-info"></span>
							💡 Hướng dẫn sử dụng
						</h3>
						<ol style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
							<li>Chọn bot từ dropdown phía trên</li>
							<li>Lấy <strong>Chat ID</strong> của người dùng:
								<ul style="margin: 10px 0 10px 20px; list-style-type: disc;">
									<li>Vào trang <a href="<?php echo admin_url('admin.php?page=bizcity-zalo-bot-logs'); ?>">Nhật ký</a></li>
									<li>Xem cột "ID người dùng" để lấy Chat ID</li>
								</ul>
							</li>
							<li>Điền thông tin vào form và nhấn nút gửi</li>
							<li>Kết quả sẽ hiển thị bên dưới</li>
						</ol>
						
						<p style="margin: 15px 0 0 0;">
							<strong>Lưu ý:</strong> Người dùng phải đã nhắn tin với bot ít nhất 1 lần trước khi bạn có thể gửi tin nhắn cho họ.
						</p>
					</div>
					
				</div>
				
			<?php endif; ?>
		</div>
		<?php
	}
	
	/**
	 * Render logs page
	 */
	public function render_logs_page() {
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot_id = isset( $_GET['bot_id'] ) ? intval( $_GET['bot_id'] ) : 0;
		$logs = $db->get_logs( array( 'bot_id' => $bot_id, 'limit' => 100 ) );
		$bots = $db->get_active_bots();
		?>
		<div class="wrap bizcity-zalo-bot-wrap">
			<h1>
				Nhật ký Zalo Bot
			</h1>
			<p class="description">
				Xem lịch sử tất cả các sự kiện webhook đã nhận từ Zalo Bot. Theo dõi tin nhắn, hình ảnh và các tương tác khác.
			</p>
			
			<!-- Filter Section -->
			<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					Lọc nhật ký
				</h2>
			<form method="get" style="margin-top: 15px;">
				<input type="hidden" name="page" value="bizcity-zalo-bot-logs" />
				<table class="form-table">
					<tr>
						<th scope="row" style="width: 150px;">
							<label for="bot-filter">Chọn Bot</label>
						</th>
						<td>
				<select name="bot_id" id="bot-filter" class="regular-text" onchange="this.form.submit()" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px;">
					<option value="">📊 Tất cả Bot</option>
					<?php foreach ( $bots as $bot ) : ?>
						<option value="<?php echo esc_attr( $bot->id ); ?>" <?php selected( $bot_id, $bot->id ); ?>>
							🤖 <?php echo esc_html( $bot->bot_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">Chọn bot để xem nhật ký cụ thể hoặc xem tất cả</p>
						</td>
					</tr>
				</table>
			</form>
			</div>
			
			<!-- Logs Table -->
			<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					Danh sách sự kiện (<?php echo count( $logs ); ?> bản ghi)
				</h2>
				
				<?php if ( empty( $logs ) ) : ?>
					<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px 20px; margin: 15px 0; border-radius: 4px;">
						<p style="margin: 0;">
							<strong>Không tìm thấy nhật ký.</strong> Khi bot nhận được webhook từ Zalo, các sự kiện sẽ hiển thị ở đây.
						</p>
					</div>
				<?php else : ?>
					<div style="overflow-x: auto; margin-top: 15px;">
			<table class="wp-list-table widefat fixed striped" style="border: none;">
				<thead>
					<tr>
						<th style="width: 140px;">Thời gian</th>
						<th style="width: 80px;">Bot</th>
						<th style="width: 180px;">Sự kiện</th>
						<th style="width: 150px;">Client ID</th>
						<th style="width: 120px;">Tên</th>
						<th style="width: 200px;">Tin nhắn</th>
						<th>Dữ liệu</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="7">Không tìm thấy nhật ký.</td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( date( 'd/m/Y H:i:s', strtotime( $log->created_at ) ) ); ?></td>
								<td><?php echo esc_html( $log->bot_id ); ?></td>
								<td><code style="font-size: 11px;"><?php echo esc_html( $log->event_name ); ?></code></td>
								<td><code style="font-size: 11px; word-break: break-all;"><?php echo esc_html( $log->client_id ?: $log->user_id ?: '-' ); ?></code></td>
								<td><strong><?php echo esc_html( $log->display_name ?: '-' ); ?></strong></td>
								<td><?php echo esc_html( mb_substr( $log->text ?: '-', 0, 50 ) . ( mb_strlen( $log->text ) > 50 ? '...' : '' ) ); ?></td>
								<td><details><summary>Xem chi tiết</summary><pre style="max-height: 300px; overflow: auto;"><?php echo esc_html( $log->event_data ); ?></pre></details></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
					</div>
				<?php endif; ?>
			</div>
			
			<!-- Info Section -->
			<div style="background: #e7f5ff; border-left: 4px solid #2196F3; padding: 15px 20px; margin: 20px 0; border-radius: 4px;">
				<h3 style="margin-top: 0;">
					Thông tin
				</h3>
				<ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
					<li>Nhật ký hiển thị <strong>100 sự kiện gần nhất</strong></li>
					<li><strong>Client ID</strong>: ID duy nhất của người dùng Zalo</li>
					<li><strong>Sự kiện</strong>: Loại webhook nhận được (message.text.received, message.photo.received, ...)</li>
					<li>Click <strong>"Xem chi tiết"</strong> để xem toàn bộ dữ liệu JSON của webhook</li>
				</ul>
			</div>
		</div>
		<?php
	}
	
	/**
	 * AJAX: Save bot
	 */
	public function ajax_save_bot() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}
		
		$data = array(
			'id' => isset( $_POST['bot_id'] ) ? intval( $_POST['bot_id'] ) : 0,
			'bot_name' => sanitize_text_field( $_POST['bot_name'] ),
			'bot_token' => sanitize_text_field( trim( $_POST['bot_token'] ) ),
			'app_id' => sanitize_text_field( $_POST['app_id'] ),
			'app_secret' => sanitize_text_field( $_POST['app_secret'] ),
			'oa_id' => sanitize_text_field( $_POST['oa_id'] ),
			'webhook_secret' => $this->process_webhook_secret( $_POST['webhook_secret'] ),
			'status' => sanitize_text_field( $_POST['status'] ),
		);
		
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot_id = $db->save_bot( $data );
		
		wp_send_json_success( array(
			'message' => bzb_t( 'Bot saved successfully' ),
			'bot_id' => $bot_id,
		) );
	}
	
	/**
	 * AJAX: Delete bot
	 */
	public function ajax_delete_bot() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		$db = BizCity_Zalo_Bot_Database::instance();
		$db->delete_bot( $bot_id );
		
		wp_send_json_success( array( 'message' => bzb_t( 'Bot deleted' ) ) );
	}
	
	/**
	 * AJAX: Test bot
	 */
	public function ajax_test_bot() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( array( 'message' => 'Bot not found' ) );
		}
		
		// Test Bot API connection by getting webhook info
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		$response = $api->get_webhook_info();
		
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 
				'message' => $response->get_error_message(),
				'details' => $response->get_error_data(),
			) );
		}
		
		wp_send_json_success( array(
			'message' => bzb_t( 'Bot connection successful! Webhook info retrieved.' ),
			'data' => $response,
		) );
	}
	
	/**
	 * Process and encrypt webhook secret
	 */
	private function process_webhook_secret( $secret ) {
		$secret = sanitize_text_field( $secret );
		
		// Validate minimum length
		if ( ! empty( $secret ) && strlen( $secret ) < 8 ) {
			wp_send_json_error( array( 'message' => bzb_t( 'Webhook secret must be at least 8 characters long' ) ) );
		}
		
		// Validate maximum length
		if ( strlen( $secret ) > 64 ) {
			wp_send_json_error( array( 'message' => bzb_t( 'Webhook secret must be less than 64 characters' ) ) );
		}
		
		// Encrypt secret if provided
		if ( ! empty( $secret ) ) {
			#return $this->encrypt_secret( $secret );
			return $secret;
		}
		
		return '';
	}
	
	/**
	 * Encrypt secret using WordPress salts
	 */
	private function encrypt_secret( $secret ) {
		// Use WordPress AUTH_KEY as encryption key
		$key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bizcity_default_key_2026';
		
		// Simple base64 + XOR encryption
		$encrypted = '';
		$key_length = strlen( $key );
		
		for ( $i = 0; $i < strlen( $secret ); $i++ ) {
			$encrypted .= $secret[$i] ^ $key[$i % $key_length];
		}
		
		return base64_encode( $encrypted );
	}
	
	/**
	 * Decrypt secret using WordPress salts
	 */
	public static function decrypt_secret( $encrypted_secret ) {
		if ( empty( $encrypted_secret ) ) {
			return '';
		}
		
		// Use WordPress AUTH_KEY as decryption key
		$key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bizcity_default_key_2026';
		
		// Decode and decrypt
		$encrypted = base64_decode( $encrypted_secret );
		if ( $encrypted === false ) {
			return '';
		}
		
		$decrypted = '';
		$key_length = strlen( $key );
		
		for ( $i = 0; $i < strlen( $encrypted ); $i++ ) {
			$decrypted .= $encrypted[$i] ^ $key[$i % $key_length];
		}
							
		#return $decrypted;
		return $encrypted_secret;
	}
	
	/**
	 * AJAX: Set webhook for Zalo Bot
	 */
	public function ajax_set_webhook() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-zalo-bot' ) ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( array( 'message' => __( 'Bot not found', 'bizcity-zalo-bot' ) ) );
		}
		
		// Get current site domain
		$site_url = get_site_url();
		$webhook_url = trailingslashit( $site_url ) . 'zalohook/';
		
		// Call Zalo API to set webhook
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		$response = $api->set_webhook( $webhook_url, $bot->webhook_secret );
		
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 
				'message' => $response->get_error_message(),
				'details' => $response->get_error_data(),
			) );
		}
		
		wp_send_json_success( array(
			'message' => bzb_t( 'Webhook set successfully' ),
			'webhook_url' => $webhook_url,
			'data' => $response,
		) );
	}

	/**
	 * AJAX: Start webhook listener
	 */
	public function ajax_start_listener() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-zalo-bot' ) ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		
		// Set listening flag with 5 minute expiry
		set_transient( 'zalobot_listening_' . $bot_id, true, 300 );
		
		// Clear any previous webhook data
		delete_transient( 'zalobot_webhook_data_' . $bot_id );
		
		wp_send_json_success( array(
			'message' => bzb_t( 'Listening started. Send a message to your Zalo bot now.' ),
		) );
	}

	/**
	 * AJAX: Check for webhook data
	 */
	public function ajax_check_listener() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-zalo-bot' ) ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		
		// Check if still listening
		$is_listening = get_transient( 'zalobot_listening_' . $bot_id );
		if ( ! $is_listening ) {
			wp_send_json_error( array( 'message' => bzb_t( 'Listener expired' ) ) );
		}
		
		// Check for webhook data
		$webhook_data = get_transient( 'zalobot_webhook_data_' . $bot_id );
		if ( $webhook_data ) {
			// Stop listening
			delete_transient( 'zalobot_listening_' . $bot_id );
			
			wp_send_json_success( array(
				'message' => bzb_t( 'Webhook received!' ),
				'data' => $webhook_data,
			) );
		}
		
		wp_send_json_success( array(
			'message' => bzb_t( 'Waiting for webhook...' ),
			'waiting' => true,
		) );
	}

	/**
	 * AJAX: Stop webhook listener
	 */
	public function ajax_stop_listener() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-zalo-bot' ) ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		
		// Stop listening
		delete_transient( 'zalobot_listening_' . $bot_id );
		delete_transient( 'zalobot_webhook_data_' . $bot_id );
		
		wp_send_json_success( array(
			'message' => bzb_t( 'Listener stopped' ),
		) );
	}

	/**
	 * AJAX: Send message
	 */
	public function ajax_send_message() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền' ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		$chat_id = sanitize_text_field( $_POST['chat_id'] );
		$text = sanitize_textarea_field( $_POST['text'] );
		
		if ( empty( $chat_id ) || empty( $text ) ) {
			wp_send_json_error( array( 'message' => 'Vui lòng điền đầy đủ Chat ID và Text' ) );
		}
		
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( array( 'message' => 'Bot không tồn tại' ) );
		}
		
		// Call Zalo API to send message
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		#back_trace('NOTICE', 'Sending text message to Chat ID: ' . $chat_id . ' with text: ' . $text. ' via Bot token: ' . $bot->bot_token);
		$response = $api->send_message( $chat_id, $text );
		
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 
				'message' => $response->get_error_message(),
				'details' => $response->get_error_data(),
			) );
		}
		
		wp_send_json_success( array(
			'message' => 'Tin nhắn đã được gửi thành công!✅ ',
			'data' => $response,
		) );
	}

	/**
	 * AJAX: Send photo
	 */
	public function ajax_send_photo() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền' ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		$chat_id = sanitize_text_field( $_POST['chat_id'] );
		$photo = esc_url_raw( $_POST['photo'] );
		$caption = sanitize_textarea_field( $_POST['caption'] );
		
		if ( empty( $chat_id ) || empty( $photo ) ) {
			wp_send_json_error( array( 'message' => 'Vui lòng điền đầy đủ Chat ID và Photo URL' ) );
		}
		
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( array( 'message' => 'Bot không tồn tại' ) );
		}
		
		// Call Zalo API to send photo
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		$response = $api->send_photo( $chat_id, $photo, $caption );
		
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 
				'message' => $response->get_error_message(),
				'details' => $response->get_error_data(),
			) );
		}
		
		wp_send_json_success( array(
			'message' => '✅ Hình ảnh đã được gửi thành công!',
			'data' => $response,
		) );
	}
	
	/**
	 * AJAX: Get user IDs for testing
	 */
	public function ajax_get_user_ids() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền' ) );
		}
		
		$bot_id = isset( $_POST['bot_id'] ) ? intval( $_POST['bot_id'] ) : 0;
		
		$db = BizCity_Zalo_Bot_Database::instance();
		$user_ids = $db->get_user_ids( $bot_id );
		
		wp_send_json_success( array(
			'user_ids' => $user_ids,
		) );
	}
	
	/**
	 * Render Memory Analytics page
	 */
	public function render_memory_page() {
		$db = BizCity_Zalo_Bot_Database::instance();
		$bots = $db->get_active_bots();
		
		$bot_id = isset( $_GET['bot_id'] ) ? intval( $_GET['bot_id'] ) : 0;
		$client_id = isset( $_GET['client_id'] ) ? sanitize_text_field( $_GET['client_id'] ) : '';
		
		// Get statistics
		$memory = BizCity_Zalo_Bot_Memory::instance();
		$stats = $memory->get_stats( array(
			'bot_id' => $bot_id,
			'client_id' => $client_id,
		) );
		
		// Get recent memories
		$memories = $memory->get_memories( array(
			'bot_id' => $bot_id,
			'client_id' => $client_id,
			'limit' => 50,
		) );
		
		// Get unique users
		$users = $db->get_user_ids( $bot_id );
		
		?>
		<div class="wrap bizcity-zalo-bot-wrap">
			<h1>
				<span class="dashicons dashicons-analytics" style="font-size: 32px; width: 32px; height: 32px;"></span>
				Ký ức ghi nhớ về chủ nhân
			</h1>
			<p class="description">
				Hệ thống tự động phân tích và lưu trữ ký ức dài hạn từ hội thoại với người dùng. Sử dụng AI để trích xuất insights về sở thích, mục tiêu, vấn đề và ràng buộc.
			</p>
			
			<!-- Build Memory Tool -->
			<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					<span class="dashicons dashicons-update" style="color: #00a0d2;"></span>
					⚙️ Xây dựng Ký ức
				</h2>
				
				<p>Phân tích logs và trích xuất ký ức sử dụng AI (LLM). Chạy thủ công hoặc tự động qua cron job hàng ngày.</p>
				
				<table class="form-table">
					<tr>
						<th scope="row" style="width: 150px;">
							<label for="memory-bot-select">Chọn Bot</label>
						</th>
						<td>
							<select id="memory-bot-select" class="regular-text" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px;">
								<option value="0">📊 Tất cả Bot</option>
								<?php foreach ( $bots as $bot ) : ?>
									<option value="<?php echo esc_attr( $bot->id ); ?>">
										🤖 <?php echo esc_html( $bot->bot_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Chọn bot để phân tích logs</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="memory-limit">Số lượng logs</label>
						</th>
						<td>
							<input type="number" id="memory-limit" class="regular-text" value="100" min="10" max="500" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px; width: 120px;" />
							<p class="description">Số lượng logs gần nhất để phân tích (10-500)</p>
						</td>
					</tr>
				</table>
				
				<p>
					<button type="button" class="button button-primary button-large" id="btn-build-memory">
						<span class="dashicons dashicons-analytics"></span>
						Bắt đầu phân tích
					</button>
				</p>
				
				<div id="memory-build-result" style="margin-top: 20px;"></div>
				
				<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px 20px; margin: 15px 0; border-radius: 4px;">
					<p style="margin: 0;">
						<strong>Lưu ý:</strong> Quá trình phân tích sử dụng có thể mất 1-2 phút.
					</p>
				</div>
			</div>
			
			<!-- Filter Section -->
			<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					<span class="dashicons dashicons-filter" style="color: #00a0d2;"></span>
					🔍 Lọc ký ức
				</h2>
				<form method="get" style="margin-top: 15px;">
					<input type="hidden" name="page" value="bizcity-zalo-bot-memory" />
					<table class="form-table">
						<tr>
							<th scope="row" style="width: 150px;">
								<label for="filter-bot">Bot</label>
							</th>
							<td>
								<select name="bot_id" id="filter-bot" class="regular-text" onchange="this.form.submit()" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px;">
									<option value="0">📊 Tất cả Bot</option>
									<?php foreach ( $bots as $bot ) : ?>
										<option value="<?php echo esc_attr( $bot->id ); ?>" <?php selected( $bot_id, $bot->id ); ?>>
											🤖 <?php echo esc_html( $bot->bot_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="filter-user">Người dùng</label>
							</th>
							<td>
								<select name="client_id" id="filter-user" class="regular-text" onchange="this.form.submit()" style="border: 1px solid #d0d0d0; border-radius: 6px; padding: 8px 12px;">
									<option value="">👥 Tất cả người dùng</option>
									<?php foreach ( $users as $user ) : ?>
										<option value="<?php echo esc_attr( $user->user_id ); ?>" <?php selected( $client_id, $user->user_id ); ?>>
											<?php echo esc_html( $user->display_name ?: $user->user_id ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
				</form>
			</div>
			
			<!-- Statistics Dashboard -->
			<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					<span class="dashicons dashicons-chart-bar" style="color: #00a0d2;"></span>
					📊 Thống kê tổng quan
				</h2>
				
				<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 20px;">
					<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
						<div style="font-size: 32px; font-weight: bold;"><?php echo number_format( $stats['totals']['total'] ?? 0 ); ?></div>
						<div style="margin-top: 5px; opacity: 0.9;">Tổng ký ức</div>
					</div>
					
					<div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 20px; border-radius: 8px; text-align: center;">
						<div style="font-size: 32px; font-weight: bold;"><?php echo number_format( $stats['totals']['pain_count'] ?? 0 ); ?></div>
						<div style="margin-top: 5px; opacity: 0.9;">😰 Pain Points</div>
					</div>
					
					<div style="background: linear-gradient(135deg, #fad0c4 0%, #ffd1ff 100%); color: #333; padding: 20px; border-radius: 8px; text-align: center;">
						<div style="font-size: 32px; font-weight: bold;"><?php echo number_format( $stats['totals']['constraint_count'] ?? 0 ); ?></div>
						<div style="margin-top: 5px; opacity: 0.9;">🚧 Giới hạn</div>
					</div>
					
					<div style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333; padding: 20px; border-radius: 8px; text-align: center;">
						<div style="font-size: 32px; font-weight: bold;"><?php echo number_format( $stats['totals']['goal_count'] ?? 0 ); ?></div>
						<div style="margin-top: 5px; opacity: 0.9;">🎯 Mục tiêu</div>
					</div>
				</div>
				
				<!-- Chart by type -->
				<div style="margin-top: 30px;">
					<h3>Phân bố theo loại ký ức</h3>
					<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 15px;">
						<?php if ( ! empty( $stats['by_type'] ) ) : ?>
							<?php foreach ( $stats['by_type'] as $type_stat ) : ?>
								<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; border-left: 4px solid #00a0d2;">
									<div style="font-size: 11px; color: #666; text-transform: uppercase;"><?php echo esc_html( $type_stat['memory_type'] ); ?></div>
									<div style="font-size: 24px; font-weight: bold; margin: 5px 0;"><?php echo number_format( $type_stat['count'] ); ?></div>
									<div style="font-size: 11px; color: #666;">Score: <?php echo number_format( $type_stat['avg_score'], 1 ); ?></div>
								</div>
							<?php endforeach; ?>
						<?php else : ?>
							<div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #666;">
								Chưa có dữ liệu. Hãy chạy phân tích ký ức ở trên.
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
			
			<!-- Memories List -->
			<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 20px 0;">
				<h2 style="margin-top: 0; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;">
					Danh sách ký ức (<?php echo count( $memories ); ?> bản ghi)
				</h2>
				
				<?php if ( empty( $memories ) ) : ?>
					<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px 20px; margin: 15px 0; border-radius: 4px;">
						<p style="margin: 0;">
							<span class="dashicons dashicons-info" style="color: #856404;"></span>
							<strong>Chưa có ký ức nào.</strong> Hãy chạy công cụ "Xây dựng Ký ức" ở trên để bắt đầu phân tích.
						</p>
					</div>
				<?php else : ?>
					<div style="overflow-x: auto; margin-top: 15px;">
						<table class="wp-list-table widefat fixed striped" style="border: none;">
							<thead>
								<tr>
									<th style="width: 100px;">📌 Loại</th>
									<th style="width: 150px;">🔑 Key</th>
									<th>💭 Nội dung</th>
									<th style="width: 80px;">⭐ Score</th>
									<th style="width: 80px;">👁️ Seen</th>
									<th style="width: 140px;">⏰ Cập nhật</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $memories as $mem ) : ?>
									<tr>
										<td>
											<?php
											$type_badges = array(
												'identity' => array( 'color' => '#667eea', 'label' => '🆔 Identity' ),
												'preference' => array( 'color' => '#f093fb', 'label' => '❤️ Sở thích' ),
												'goal' => array( 'color' => '#4facfe', 'label' => '🎯 Mục tiêu' ),
												'pain' => array( 'color' => '#f5576c', 'label' => '😰 Pain' ),
												'constraint' => array( 'color' => '#fa709a', 'label' => '🚧 Ràng buộc' ),
												'habit' => array( 'color' => '#30cfd0', 'label' => '🔄 Thói quen' ),
												'relationship' => array( 'color' => '#a8edea', 'label' => '👥 Quan hệ' ),
												'fact' => array( 'color' => '#d299c2', 'label' => '📋 Fact' ),
											);
											$badge = $type_badges[ $mem->memory_type ] ?? array( 'color' => '#999', 'label' => $mem->memory_type );
											?>
											<span style="background: <?php echo esc_attr( $badge['color'] ); ?>; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block;">
												<?php echo esc_html( $badge['label'] ); ?>
											</span>
										</td>
										<td>
											<code style="font-size: 10px; background: #f0f0f0; padding: 3px 6px; border-radius: 3px; word-break: break-all;">
												<?php echo esc_html( $mem->memory_key ); ?>
											</code>
										</td>
										<td>
											<strong><?php echo esc_html( $mem->memory_text ); ?></strong>
										</td>
										<td>
											<div style="text-align: center;">
												<div style="font-size: 18px; font-weight: bold; color: <?php echo $mem->score >= 50 ? '#00a0d2' : '#999'; ?>">
													<?php echo esc_html( $mem->score ); ?>
												</div>
												<div style="width: 100%; height: 4px; background: #e0e0e0; border-radius: 2px; margin-top: 3px;">
													<div style="width: <?php echo min( 100, $mem->score ); ?>%; height: 100%; background: linear-gradient(90deg, #00a0d2, #0073aa); border-radius: 2px;"></div>
												</div>
											</div>
										</td>
										<td style="text-align: center;">
											<span style="background: #e7f5ff; color: #0066cc; padding: 3px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;">
												<?php echo esc_html( $mem->times_seen ); ?>×
											</span>
										</td>
										<td>
											<span style="font-size: 11px; color: #666;">
												<?php echo esc_html( date( 'd/m/Y', strtotime( $mem->updated_at ) ) ); ?>
											</span><br>
											<strong><?php echo esc_html( date( 'H:i', strtotime( $mem->updated_at ) ) ); ?></strong>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
			
			<!-- Info Section -->
			<div style="background: #e7f5ff; border-left: 4px solid #2196F3; padding: 15px 20px; margin: 20px 0; border-radius: 4px;">
				<h3 style="margin-top: 0;">
					<span class="dashicons dashicons-info" style="color: #2196F3;"></span>
					💡 Về hệ thống Memory
				</h3>
				<ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
					<li><strong>Long-term Memory:</strong> Hệ thống tự động trích xuất và lưu trữ thông tin quan trọng từ hội thoại</li>
					<li><strong>AI-powered:</strong> Sử dụng LLM để phân tích ngữ nghĩa và context</li>
					<li><strong>Score:</strong> Điểm càng cao = thông tin càng quan trọng (auto-increment khi gặp lại)</li>
					<li><strong>Auto-update:</strong> Cron job chạy hàng ngày để cập nhật ký ức mới</li>
					<li><strong>Pain Points:</strong> Phát hiện stress, lo âu, vấn đề người dùng đang gặp</li>
					<li><strong>Constraints:</strong> Xác định các hạn chế (thời gian, tiền bạc, năng lực...)</li>
				</ul>
			</div>
		</div>
		
		<script>
		jQuery(document).ready(function($) {
			$('#btn-build-memory').on('click', function() {
				var btn = $(this);
				var botId = $('#memory-bot-select').val();
				var limit = $('#memory-limit').val();
				var resultDiv = $('#memory-build-result');
				
				btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Đang phân tích...');
				resultDiv.html('<div style="background: #e7f5ff; padding: 15px; border-radius: 4px;"><span class="dashicons dashicons-update spin"></span> Đang xử lý... Vui lòng đợi.</div>');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'bizcity_zalo_bot_build_memory',
						nonce: '<?php echo wp_create_nonce( "bizcity_zalo_bot_nonce" ); ?>',
						bot_id: botId,
						limit: limit
					},
					success: function(response) {
						btn.prop('disabled', false).html('<span class="dashicons dashicons-analytics"></span> Bắt đầu phân tích');
						
						if (response.success) {
							resultDiv.html(
								'<div style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px; border-radius: 4px;">' +
								'<strong>✅ Hoàn thành!</strong><br>' +
								'Đã phân tích: ' + response.data.count + ' logs<br>' +
								'Thêm mới: ' + response.data.inserted + ' ký ức<br>' +
								'Cập nhật: ' + response.data.updated + ' ký ức<br>' +
								'<a href="' + location.href + '" class="button button-primary" style="margin-top: 10px;">🔄 Tải lại trang</a>' +
								'</div>'
							);
						} else {
							resultDiv.html(
								'<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; border-radius: 4px;">' +
								'<strong>❌ Lỗi:</strong> ' + response.data.message +
								'</div>'
							);
						}
					},
					error: function() {
						btn.prop('disabled', false).html('<span class="dashicons dashicons-analytics"></span> Bắt đầu phân tích');
						resultDiv.html(
							'<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; border-radius: 4px;">' +
							'<strong>❌ Lỗi:</strong> Không thể kết nối server' +
							'</div>'
						);
					}
				});
			});
		});
		</script>
		<style>
		.dashicons.spin {
			animation: spin 1s linear infinite;
		}
		@keyframes spin {
			from { transform: rotate(0deg); }
			to { transform: rotate(360deg); }
		}
		</style>
		<?php
	}
	
	/**
	 * AJAX: Build memory from logs
	 */
	public function ajax_build_memory() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền' ) );
		}
		
		$bot_id = isset( $_POST['bot_id'] ) ? intval( $_POST['bot_id'] ) : 0;
		$limit = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 100;
		
		// Check OpenAI API key
		$api_key = get_option( 'twf_openai_api_key' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'Chưa cấu hình OpenAI API key (twf_openai_api_key)' ) );
		}
		
		$memory = BizCity_Zalo_Bot_Memory::instance();
		$result = $memory->build_from_logs( array(
			'bot_id' => $bot_id,
			'limit' => $limit,
		) );
		
		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( array( 'message' => 'Có lỗi xảy ra khi phân tích' ) );
		}
	}
	
	/**
	 * AJAX: Get bot information using getMe API
	 */
	public function ajax_get_me() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-zalo-bot' ) ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( array( 'message' => __( 'Bot not found', 'bizcity-zalo-bot' ) ) );
		}
		
		// Call Zalo Bot API getMe
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		$response = $api->get_me();
		
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 
				'message' => $response->get_error_message(),
				'details' => $response->get_error_data(),
			) );
		}
		
		wp_send_json_success( array(
			'message' => bzb_t( 'Bot information retrieved successfully' ),
			'data' => $response,
		) );
	}
	
	/**
	 * AJAX: Get updates using long polling
	 */
	public function ajax_get_updates() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-zalo-bot' ) ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : null;
		$limit = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 10;
		$timeout = isset( $_POST['timeout'] ) ? intval( $_POST['timeout'] ) : 30;
		
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( array( 'message' => __( 'Bot not found', 'bizcity-zalo-bot' ) ) );
		}
		
		// Call Zalo Bot API getUpdates
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		$response = $api->get_updates( $offset, $limit, $timeout );
		
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 
				'message' => $response->get_error_message(),
				'details' => $response->get_error_data(),
			) );
		}
		
		wp_send_json_success( array(
			'message' => bzb_t( 'Updates retrieved successfully' ),
			'data' => $response,
		) );
	}
	
	/**
	 * AJAX: Delete webhook to enable getUpdates mode
	 */
	public function ajax_delete_webhook() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-zalo-bot' ) ) );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( array( 'message' => __( 'Bot not found', 'bizcity-zalo-bot' ) ) );
		}
		
		// Call Zalo Bot API deleteWebhook
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		$response = $api->delete_webhook();
		
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 
				'message' => $response->get_error_message(),
				'details' => $response->get_error_data(),
			) );
		}
		
		wp_send_json_success( array(
			'message' => bzb_t( 'Webhook deleted successfully - getUpdates mode enabled' ),
			'data' => $response,
		) );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 * CONNECTIONS — Zalo ↔ WP user binding management
	 * ═══════════════════════════════════════════════════════════════════ */

	/**
	 * Render "Kết nối Zalo" admin page.
	 */
	public function render_connections_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Permission denied', 'bizcity-zalo-bot' ) );
		}

		$can_use_linker = class_exists( 'BizCity_Zalobot_User_Linker' );
		$links          = $can_use_linker ? BizCity_Zalobot_User_Linker::get_all_links( [ 'limit' => 100 ] ) : [];
		$nonce          = wp_create_nonce( 'bizcity_zalobot_unlink' );

		// Status counts
		$counts = [ 'linked' => 0, 'pending' => 0, 'unlinked' => 0 ];
		foreach ( $links as $row ) {
			$s = $row['status'] ?? 'pending';
			if ( isset( $counts[ $s ] ) ) {
				$counts[ $s ]++;
			}
		}
		?>
		<div class="wrap">
			<h1>🔗 <?php esc_html_e( 'Kết nối Zalo ↔ WordPress', 'bizcity-zalo-bot' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Quản lý liên kết giữa Zalo user ID và tài khoản WordPress. Mỗi người dùng Zalo cần đăng nhập một lần để AI nhận đầy đủ context cá nhân.', 'bizcity-zalo-bot' ); ?></p>

			<!-- Stats -->
			<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 24px;text-align:center;min-width:120px;">
					<div style="font-size:28px;font-weight:700;color:#10b981;"><?php echo esc_html( $counts['linked'] ); ?></div>
					<div style="font-size:13px;color:#6b7280;">Đã liên kết</div>
				</div>
				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 24px;text-align:center;min-width:120px;">
					<div style="font-size:28px;font-weight:700;color:#f59e0b;"><?php echo esc_html( $counts['pending'] ); ?></div>
					<div style="font-size:13px;color:#6b7280;">Chờ đăng nhập</div>
				</div>
				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 24px;text-align:center;min-width:120px;">
					<div style="font-size:28px;font-weight:700;color:#dc2626;"><?php echo esc_html( $counts['unlinked'] ); ?></div>
					<div style="font-size:13px;color:#6b7280;">Đã huỷ</div>
				</div>
			</div>

			<!-- Flow Steps Banner -->
			<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px;margin-bottom:20px;">
				<strong style="color:#1d4ed8;">📋 Quy trình kết nối:</strong>
				<ol style="margin:8px 0 0 20px;color:#374151;font-size:13px;">
					<li>Người dùng Zalo nhắn tin cho bot → hệ thống gửi link đăng nhập</li>
					<li>Người dùng mở link → đăng nhập WordPress (hoặc đã đăng nhập sẵn)</li>
					<li>Liên kết được lưu: <code>Zalo user_id ↔ WP user_id</code></li>
					<li>Từ đó trở đi: AI dùng đầy đủ context cá nhân (memory, notes, companion...)</li>
				</ol>
			</div>

			<!-- Table -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:50px;">ID</th>
						<th>Zalo User</th>
						<th>Tên Zalo</th>
						<th>Bot</th>
						<th>WP User</th>
						<th>Trạng thái</th>
						<th>Liên kết lúc</th>
						<th style="width:100px;">Thao tác</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $links ) ) : ?>
					<tr><td colspan="8" style="text-align:center;padding:24px;color:#6b7280;">
						<?php esc_html_e( 'Chưa có kết nối nào. Người dùng Zalo sẽ xuất hiện ở đây sau lần đầu nhắn tin.', 'bizcity-zalo-bot' ); ?>
					</td></tr>
				<?php else : ?>
					<?php foreach ( $links as $row ) :
						$wp_user   = $row['wp_user_id'] ? get_user_by( 'id', (int) $row['wp_user_id'] ) : null;
						$status    = $row['status'] ?? 'pending';
						$color_map = [ 'linked' => '#10b981', 'pending' => '#f59e0b', 'unlinked' => '#dc2626' ];
						$label_map = [ 'linked' => '✅ Đã kết nối', 'pending' => '⏳ Chờ đăng nhập', 'unlinked' => '❌ Đã huỷ' ];
						$color     = $color_map[ $status ] ?? '#6b7280';
						$label     = $label_map[ $status ] ?? $status;
					?>
					<tr>
						<td><?php echo esc_html( $row['id'] ); ?></td>
						<td><code style="font-size:12px;"><?php echo esc_html( $row['zalo_user_id'] ); ?></code></td>
						<td><?php echo esc_html( $row['display_name'] ?: '—' ); ?></td>
						<td><code>#<?php echo esc_html( $row['bot_id'] ); ?></code></td>
						<td>
							<?php if ( $wp_user ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $wp_user->ID ) ); ?>"><?php echo esc_html( $wp_user->display_name ); ?></a>
								<br><small style="color:#6b7280;">#<?php echo esc_html( $wp_user->ID ); ?></small>
							<?php else : ?>
								<span style="color:#6b7280;">—</span>
							<?php endif; ?>
						</td>
						<td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo esc_html( $label ); ?></span></td>
						<td style="font-size:12px;"><?php echo esc_html( $row['linked_at'] ?: '—' ); ?></td>
						<td>
							<?php if ( $status === 'linked' ) : ?>
							<button class="button button-small bizcity-zalobot-unlink"
								data-id="<?php echo esc_attr( $row['id'] ); ?>"
								data-nonce="<?php echo esc_attr( $nonce ); ?>"
								style="color:#dc2626;border-color:#dc2626;">
								Huỷ liên kết
							</button>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<script>
		(function($){
			$(document).on('click', '.bizcity-zalobot-unlink', function(e) {
				e.preventDefault();
				if ( ! confirm('Xác nhận huỷ liên kết? Người dùng Zalo này sẽ cần đăng nhập lại để kết nối.') ) return;
				var $btn = $(this);
				$btn.prop('disabled', true).text('Đang xử lý...');
				$.post(ajaxurl, {
					action:  'bizcity_zalobot_unlink_user',
					link_id: $btn.data('id'),
					nonce:   $btn.data('nonce'),
				}, function(res) {
					if ( res.success ) {
						$btn.closest('tr').fadeOut(400, function(){ $(this).remove(); });
					} else {
						alert(res.data || 'Lỗi không xác định');
						$btn.prop('disabled', false).text('Huỷ liên kết');
					}
				}).fail(function(){
					alert('Lỗi kết nối server');
					$btn.prop('disabled', false).text('Huỷ liên kết');
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * AJAX: Unlink a Zalo ↔ WP user binding.
	 */
	public function ajax_unlink_user(): void {
		if ( ! check_ajax_referer( 'bizcity_zalobot_unlink', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Nonce không hợp lệ' ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Không có quyền' ] );
		}

		$link_id = (int) ( $_POST['link_id'] ?? 0 );
		if ( $link_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Link ID không hợp lệ' ] );
		}

		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			wp_send_json_error( [ 'message' => 'User Linker chưa được load' ] );
		}

		$ok = BizCity_Zalobot_User_Linker::unlink( $link_id );
		if ( $ok ) {
			wp_send_json_success( [ 'message' => "Đã huỷ liên kết #{$link_id}" ] );
		} else {
			wp_send_json_error( [ 'message' => 'Không tìm thấy liên kết hoặc không thể huỷ' ] );
		}
	}

	/* ═══════════════════════════════════════════════════════════
	 * PHASE-0.35 GURU-ZALO-BOT §1.6 — Guru AI Settings Page
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Render the Guru AI settings page.
	 * Global toggle + per-bot character binding.
	 */
	public function render_guru_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		$db   = BizCity_Zalo_Bot_Database::instance();
		$bots = $db->get_active_bots();

		// Resolve available characters.
		$characters = $this->get_all_characters();

		$global_enabled = (int) get_option( 'bizcity_zalo_guru_enabled', 0 );
		$default_char   = (int) get_option( 'bizcity_zalo_guru_default_character_id', 0 );

		?>
		<div class="wrap">
			<h1>🤖 Guru AI — Zalo Bot</h1>
			<p>Kích hoạt <strong>BizCity Guru Runtime</strong> để trả lời Zalo qua 3-layer context (L1/L2/L3), citation chuẩn, trace_id. Sau khi bật, mỗi tin nhắn sẽ đi qua <code>BizCity_Guru_Runtime::reply()</code> thay vì legacy gateway.</p>

			<?php if ( empty( $characters ) ) : ?>
				<div class="notice notice-warning"><p>⚠️ Chưa có <strong>Guru character</strong> nào. Vào <strong>Bizcity → Nhân vật AI</strong> để tạo ít nhất 1 character trước.</p></div>
			<?php endif; ?>

			<form id="guru-settings-form">
				<?php wp_nonce_field( 'bizcity_zalo_bot_nonce', 'nonce' ); ?>

				<h2>Cài đặt toàn cục</h2>
				<table class="form-table">
					<tr>
						<th>Bật Guru Runtime</th>
						<td>
							<label>
								<input type="checkbox" name="guru_enabled" value="1" <?php checked( $global_enabled, 1 ); ?> />
								<strong>Bật</strong> — toàn bộ bot Zalo sẽ dùng Guru Runtime khi có character binding
							</label>
							<p class="description">Tắt = quay về legacy gateway (bizcity-admin-hook-zalo). Không ảnh hưởng bot nào chưa có character binding.</p>
						</td>
					</tr>
					<tr>
						<th>Character mặc định</th>
						<td>
							<select name="default_character_id">
								<option value="0">— Không có (bắt buộc bind riêng từng bot) —</option>
								<?php foreach ( $characters as $c ) : ?>
									<option value="<?php echo (int) $c->id; ?>" <?php selected( $default_char, (int) $c->id ); ?>>
										<?php echo esc_html( $c->display_name ?: $c->name ); ?> (ID <?php echo (int) $c->id; ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Fallback khi bot không có binding riêng.</p>
						</td>
					</tr>
				</table>

				<h2>Binding theo từng bot</h2>
				<table class="widefat striped" style="max-width:800px">
					<thead>
						<tr>
							<th>Bot</th>
							<th>Character (Guru)</th>
							<th>Trạng thái</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $bots ) ) : ?>
							<tr><td colspan="3">Chưa có bot nào.</td></tr>
						<?php else : ?>
							<?php foreach ( $bots as $bot ) :
								$bot_id      = (int) $bot->id;
								$bound_char  = (int) get_option( 'bizcity_zalobot_guru_char_' . $bot_id, 0 );
								$effective   = $bound_char ?: $default_char;
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $bot->bot_name ); ?></strong>
									<br><small style="color:#888">ID <?php echo $bot_id; ?> · <?php echo esc_html( $bot->status ); ?></small>
								</td>
								<td>
									<select name="char_bot_<?php echo $bot_id; ?>">
										<option value="0">— Dùng mặc định —</option>
										<?php foreach ( $characters as $c ) : ?>
											<option value="<?php echo (int) $c->id; ?>" <?php selected( $bound_char, (int) $c->id ); ?>>
												<?php echo esc_html( $c->display_name ?: $c->name ); ?> (ID <?php echo (int) $c->id; ?>)
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<?php if ( $global_enabled && $effective > 0 ) : ?>
										<span style="color:#0a0;font-weight:600">✅ Guru active (char #<?php echo $effective; ?>)</span>
									<?php elseif ( $global_enabled && $effective === 0 ) : ?>
										<span style="color:#e67e22">⚠️ Bật nhưng chưa có character</span>
									<?php else : ?>
										<span style="color:#888">⏸ Legacy mode</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p class="submit" style="margin-top:16px">
					<button type="button" id="guru-save-btn" class="button button-primary button-large">💾 Lưu cài đặt Guru</button>
					<span id="guru-save-status" style="margin-left:12px;display:none"></span>
				</p>
			</form>
		</div>

		<script>
		(function($){
			$('#guru-save-btn').on('click', function(){
				var $btn    = $(this);
				var $status = $('#guru-save-status');
				var form    = $('#guru-settings-form');
				var data    = form.serializeArray();
				data.push({ name: 'action', value: 'bizcity_zalobot_save_guru_settings' });

				$btn.prop('disabled', true).text('Đang lưu…');
				$status.hide();

				$.post(ajaxurl, data, function(resp){
					$btn.prop('disabled', false).text('💾 Lưu cài đặt Guru');
					if (resp.success) {
						$status.show().css('color','#0a0').text('✅ ' + (resp.data.message || 'Đã lưu!'));
						// Reload để cập nhật cột Trạng thái
						setTimeout(function(){ location.reload(); }, 1200);
					} else {
						$status.show().css('color','#c00').text('❌ ' + (resp.data.message || 'Lỗi!'));
					}
				}).fail(function(){
					$btn.prop('disabled', false).text('💾 Lưu cài đặt Guru');
					$status.show().css('color','#c00').text('❌ Lỗi kết nối AJAX');
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * AJAX: Save Guru AI settings (global toggle + per-bot character binding).
	 */
	public function ajax_save_guru_settings() {
		check_ajax_referer( 'bizcity_zalo_bot_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied' ] );
		}

		// Global toggle.
		$enabled = isset( $_POST['guru_enabled'] ) && $_POST['guru_enabled'] === '1' ? 1 : 0;
		update_option( 'bizcity_zalo_guru_enabled', $enabled );

		// Default character.
		$default_char = (int) ( $_POST['default_character_id'] ?? 0 );
		update_option( 'bizcity_zalo_guru_default_character_id', max( 0, $default_char ) );

		// Per-bot bindings.
		$db       = BizCity_Zalo_Bot_Database::instance();
		$bots     = $db->get_active_bots();
		$bound    = 0;
		foreach ( $bots as $bot ) {
			$bot_id  = (int) $bot->id;
			$key     = 'char_bot_' . $bot_id;
			$char_id = isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
			update_option( 'bizcity_zalobot_guru_char_' . $bot_id, max( 0, $char_id ) );
			if ( $char_id > 0 ) { $bound++; }
		}

		$summary = $enabled
			? sprintf( 'Guru Runtime BẬT · %d bot có character binding · default char #%d', $bound, $default_char )
			: 'Guru Runtime TẮT — đang dùng legacy gateway';

		wp_send_json_success( [ 'message' => $summary ] );
	}

	/**
	 * Return all characters from bizcity_characters table.
	 * Fails gracefully when table or class doesn't exist.
	 *
	 * @return array<object{id,name,display_name}>
	 */
	private function get_all_characters(): array {
		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			$db  = BizCity_Knowledge_Database::instance();
			$all = $db->get_characters();
			return is_array( $all ) ? $all : [];
		}
		// Direct fallback.
		global $wpdb;
		$t = $wpdb->prefix . 'bizcity_characters';
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( "SELECT id, name, display_name FROM `$t` ORDER BY id ASC" );
		$wpdb->suppress_errors( false );
		return is_array( $rows ) ? $rows : [];
	}

}