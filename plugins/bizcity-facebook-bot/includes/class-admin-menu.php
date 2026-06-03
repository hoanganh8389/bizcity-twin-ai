<?php
/**
 * Admin Menu & Settings Page for Facebook Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Facebook_Bot_Admin_Menu {
	
	private static $instance = null;
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		
		// AJAX handlers
		add_action( 'wp_ajax_bizcity_facebook_bot_save', array( $this, 'ajax_save_bot' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_delete', array( $this, 'ajax_delete_bot' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_test', array( $this, 'ajax_test_bot' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_start_listener', array( $this, 'ajax_start_listener' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_check_listener', array( $this, 'ajax_check_listener' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_stop_listener', array( $this, 'ajax_stop_listener' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_send_message', array( $this, 'ajax_send_message' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_send_photo', array( $this, 'ajax_send_photo' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_get_me', array( $this, 'ajax_get_me' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_get_inbox', array( $this, 'ajax_get_inbox' ) );
		add_action( 'wp_ajax_bizcity_facebook_bot_get_recent_clients', array( $this, 'ajax_get_recent_clients' ) );
		// Settings save handler — fallback khi bizcity-tool-facebook không được load
		add_action( 'admin_post_bztfb_save_settings', array( $this, 'handle_save_settings' ) );
		// OAuth callback: /?fb_callback=1&code=...
		add_action( 'init', array( $this, 'handle_fb_oauth_callback' ) );
	}
	
	/**
	 * Add admin menu
	 */
	public function add_menu() {
		add_menu_page(
			'CSKH - Facebook',
			'CSKH - Facebook',
			'manage_options',
			'bizcity-facebook-bots',
			array( $this, 'render_page' ),
			'dashicons-facebook',
			31
		);
		/*
		add_submenu_page(
			'bizcity-facebook-bots',
			'Tất cả Bot',
			'Tất cả Bot',
			'manage_options',
			'bizcity-facebook-bots',
			array( $this, 'render_page' )
		);
		
		// Cấu hình Facebook App
		add_submenu_page(
			'bizcity-facebook-bots',
			'Cấu hình',
			'Cấu hình',
			'manage_options',
			'bizcity-facebook-bot-settings',
			'bizcity_fb_app_settings_admin_page'
		);*/
		
		// Kết nối Fanpage
		add_submenu_page(
			'bizcity-facebook-bots',
			'Kết nối Fanpage',
			'Kết nối Fanpage',
			'manage_options',
			'bizcity-facebook-bot-connect',
			array( $this, 'render_connect_page' )
		);
		
		// Quản lý bài đăng
		add_submenu_page(
			'bizcity-facebook-bots',
			'Quản lý bài đăng',
			'Quản lý bài đăng',
			'manage_options',
			'bizcity-facebook-bot-posts',
			array( $this, 'render_fanpage_posts_page' )
		);
		
		// Quản lý Comment
		add_submenu_page(
			'bizcity-facebook-bots',
			'Quản lý Comment',
			'Quản lý Comment',
			'manage_options',
			'bizcity-facebook-bot-comments',
			array( $this, 'render_comments_manager_page' )
		);
		
		// Liên kết Business
		add_submenu_page(
			'bizcity-facebook-bots',
			'Liên kết Business',
			'Liên kết Business',
			'manage_options',
			'bizcity-facebook-bot-business',
			array( $this, 'render_business_page' )
		);
		
		add_submenu_page(
			'bizcity-facebook-bots',
			'Nghe Webhook',
			'Nghe Webhook',
			'manage_options',
			'bizcity-facebook-bot-listener',
			array( $this, 'render_listener_page' )
		);
		
		add_submenu_page(
			'bizcity-facebook-bots',
			'Test API',
			'Test API',
			'manage_options',
			'bizcity-facebook-bot-test-api',
			array( $this, 'render_test_api_page' )
		);
		
		add_submenu_page(
			'bizcity-facebook-bots',
			'Inbox',
			'Inbox',
			'manage_options',
			'bizcity-facebook-bot-inbox',
			array( $this, 'render_inbox_page' )
		);
		
		add_submenu_page(
			'bizcity-facebook-bots',
			'Nhật ký',
			'Nhật ký',
			'manage_options',
			'bizcity-facebook-bot-logs',
			array( $this, 'render_logs_page' )
		);
		
		// Migration tools page - only for super_admin or admin1
		if ( $this->can_access_migration_tools() ) {
			add_submenu_page(
				'bizcity-facebook-bots',
				'Migration Tools',
				'Migration Tools',
				'manage_options',
				'bizcity-facebook-bot-migration',
				array( $this, 'render_migration_page' )
			);
		}
		
		// Settings page — fallback khi bizcity-tool-facebook không được load
		if ( ! function_exists( 'bztfb_render_settings_page' ) ) {
			add_submenu_page(
				'bizcity-facebook-bots',
				'Facebook Settings',
				'⚙️ Cài đặt App',
				'manage_options',
				'bizcity-facebook-settings',
				array( $this, 'render_settings_page' )
			);
		}

		// Legacy menu slug compatibility - messenger-inbox-page
		add_submenu_page(
			'bizcity-facebook-bots',
			'Nhận inbox từ Messenger',
			'Nhận inbox từ Messenger',
			'manage_options',
			'messenger-inbox-page',
			array( $this, 'render_legacy_messenger_inbox_page' )
		);
	}
	
	/**
	 * Render main page
	 */
	public function render_page() {
		$db = BizCity_Facebook_Bot_Database::instance();
		$bots = $db->get_active_bots();

		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
		$bot_id = isset( $_GET['bot_id'] ) ? intval( $_GET['bot_id'] ) : 0;

		// Inline editor flows still use the legacy form (modal-like).
		if ( $action === 'edit' && $bot_id > 0 ) {
			$bot = $db->get_bot( $bot_id );
			$this->render_edit_form( $bot );
			return;
		}
		if ( $action === 'add' ) {
			$this->render_edit_form( null );
			return;
		}

		// Default → unified single-screen 4-tab UI matching /tool-facebook/.
		$this->render_unified_dashboard( $bots );
	}

	/**
	 * Unified single-screen dashboard. Loads templates/admin-unified.php
	 * with a curated, view-ready data bundle. Mirrors /tool-facebook/'s
	 * 4-tab style so both flows feel like the same product.
	 */
	private function render_unified_dashboard( $db_bots ) {
		// Credentials (new key first, legacy fallback).
		$app_id           = (string) get_option( 'bztfb_app_id',     get_option( 'fb_app_id', '' ) );
		$app_secret_raw   = (string) get_option( 'bztfb_app_secret', get_option( 'fb_app_secret', '' ) );
		$app_secret_masked = $app_secret_raw !== '' ? '••••••••' : '';
		$verify_token     = (string) get_option( 'bztfb_verify_token', 'bizfbhook' );
		$webhook_url      = home_url( '/bizfbhook/' );

		// OAuth URL (mirrors render_connect_page()).
		$blog   = get_blog_details( get_current_blog_id() );
		$domain = 'https://' . rtrim( (string) ( $blog->domain ?? wp_parse_url( home_url(), PHP_URL_HOST ) ), '/' );
		$scopes = implode( ',', array(
			'pages_show_list',
			'pages_manage_posts',
			'pages_manage_engagement',
			'pages_manage_metadata',
			'pages_read_engagement',
			'pages_read_user_content',
			'pages_messaging',
			'pages_messaging_subscriptions',
			'public_profile',
		) );
		$redirect_uri = rawurlencode( $domain . '/?fb_callback=1' );
		$oauth_url    = $app_id !== ''
			? "https://www.facebook.com/v18.0/dialog/oauth?client_id={$app_id}&redirect_uri={$redirect_uri}&scope={$scopes}&response_type=code"
			: '';

		// Pages — merge legacy option + DB bots in template.
		$legacy_pages = (array) get_option( 'fb_pages_connected', array() );

		// Listener status (best-effort; class may not be loaded).
		$listener_status = array( 'running' => false, 'note' => '' );
		if ( class_exists( 'BizCity_Facebook_Bot_Listener' ) && method_exists( 'BizCity_Facebook_Bot_Listener', 'get_status' ) ) {
			$st = BizCity_Facebook_Bot_Listener::get_status();
			if ( is_array( $st ) ) {
				$listener_status['running'] = ! empty( $st['running'] );
				$listener_status['note']    = (string) ( $st['note'] ?? '' );
			}
		}

		// Recent clients — best-effort via existing AJAX backend.
		$recent_clients = array();
		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) && method_exists( 'BizCity_Facebook_Bot_Database', 'get_recent_clients' ) ) {
			$rc = BizCity_Facebook_Bot_Database::instance()->get_recent_clients( 10 );
			if ( is_array( $rc ) ) { $recent_clients = $rc; }
		}

		// Tools links (Migration only for super admin).
		$action_links = array();
		if ( $this->can_access_migration_tools() ) {
			$action_links['migration'] = admin_url( 'admin.php?page=bizcity-facebook-bot-migration' );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'pages';

		require __DIR__ . '/../templates/admin-unified.php';
	}
	
	/**
	 * Print shared CSS (once per page)
	 */
	private function print_styles() {
		static $printed = false;
		if ( $printed ) return;
		$printed = true;
		?>
		<style>
		.bizfb-wrap { max-width: 1200px; }
		.bizfb-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 16px; }
		.bizfb-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 16px; }
		@media (max-width: 900px) { .bizfb-grid, .bizfb-grid-3 { grid-template-columns: 1fr; } }
		.bizfb-card {
			background: #fff;
			border: 1px solid #e5e7eb;
			border-radius: 14px;
			padding: 20px 24px;
			box-shadow: 0 1px 3px rgba(0,0,0,.06);
			margin-bottom: 20px;
		}
		.bizfb-card h2 { margin-top: 0; font-size: 15px; }
		.bizfb-badge {
			display: flex; align-items: center; gap: 8px;
			font-size: 14px; font-weight: 700; color: #1e293b;
			margin: 0 0 16px 0; padding-bottom: 10px;
			border-bottom: 1px solid #f1f5f9;
		}
		.bizfb-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
		.dot-blue   { background: #3b82f6; }
		.dot-green  { background: #22c55e; }
		.dot-amber  { background: #f59e0b; }
		.dot-slate  { background: #94a3b8; }
		.dot-red    { background: #ef4444; }
		.bizfb-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
		.bizfb-title h1 { margin: 0; font-size: 22px; }
		.bizfb-title .bizfb-sub { color: #64748b; font-size: 13px; margin-top: 4px; }
		.bizfb-actions { display: flex; gap: 8px; flex-wrap: wrap; }
		.bizfb-bot-row {
			display: flex; align-items: center; justify-content: space-between;
			padding: 12px 0; border-bottom: 1px solid #f1f5f9;
		}
		.bizfb-bot-row:last-child { border-bottom: none; }
		.bizfb-bot-info { display: flex; align-items: center; gap: 12px; }
		.bizfb-bot-avatar {
			width: 40px; height: 40px; border-radius: 10px;
			background: #eff6ff; display: flex; align-items: center;
			justify-content: center; font-size: 18px; flex-shrink: 0;
		}
		.bizfb-bot-name { font-weight: 700; font-size: 14px; color: #0f172a; }
		.bizfb-bot-meta { font-size: 12px; color: #64748b; margin-top: 2px; }
		.bizfb-status-active  { color: #16a34a; font-weight: 600; font-size: 12px; }
		.bizfb-status-inactive { color: #94a3b8; font-size: 12px; }
		.bizfb-row-actions { display: flex; gap: 6px; }
		.bizfb-row-actions a { font-size: 12px; text-decoration: none; padding: 4px 10px; border-radius: 6px; border: 1px solid #e5e7eb; color: #334155; }
		.bizfb-row-actions a:hover { background: #f8fafc; }
		.bizfb-row-actions a.danger { color: #dc2626; border-color: #fecaca; }
		.bizfb-code-block {
			background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px;
			padding: 10px 14px; font-family: monospace; font-size: 12px;
			word-break: break-all; color: #0f172a;
		}
		.bizfb-help { font-size: 13px; color: #475569; line-height: 1.6; }
		.bizfb-help ol, .bizfb-help ul { padding-left: 18px; margin: 8px 0; }
		.bizfb-empty { text-align: center; color: #94a3b8; padding: 32px 0; font-size: 13px; }
		.bizfb-stat-card {
			background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
			border: 1px solid #bfdbfe; border-radius: 14px; padding: 20px;
			text-align: center;
		}
		.bizfb-stat-num { font-size: 32px; font-weight: 800; color: #1d4ed8; }
		.bizfb-stat-label { font-size: 13px; color: #3b82f6; margin-top: 4px; }
		</style>
		<?php
	}

	/**
	 * Render bot list — dashboard chính
	 */
	private function render_list( $bots ) {
		$db      = BizCity_Facebook_Bot_Database::instance();
		$pages   = method_exists( $db, 'get_connected_pages' ) ? $db->get_connected_pages() : [];
		$app_id  = get_option( 'bztfb_app_id', '' );
		$webhook = home_url( '/bizfbhook/' );
		$verify  = get_option( 'bztfb_verify_token', 'bizfbhook' );
		$this->print_styles();
		?>
		<div class="wrap bizfb-wrap">

			<!-- Header -->
			<div class="bizfb-title">
				<div>
					<h1>📘 CSKH Facebook</h1>
					<div class="bizfb-sub">Quản lý Bot Messenger, theo dõi inbox và comment theo Fanpage.</div>
				</div>
				<div class="bizfb-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots&action=add' ) ); ?>" class="button button-primary">➕ Thêm Bot mới</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-connect' ) ); ?>" class="button">🔗 Kết nối Fanpage</a>
				</div>
			</div>

			<!-- Stats row -->
			<div class="bizfb-grid-3">
				<div class="bizfb-stat-card">
					<div class="bizfb-stat-num"><?php echo count( $bots ); ?></div>
					<div class="bizfb-stat-label">Bot đang hoạt động</div>
				</div>
				<div class="bizfb-stat-card" style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-color: #bbf7d0;">
					<div class="bizfb-stat-num" style="color: #15803d;"><?php echo count( $pages ); ?></div>
					<div class="bizfb-stat-label" style="color: #16a34a;">Fanpage đã kết nối</div>
				</div>
				<div class="bizfb-stat-card" style="background: linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%); border-color: #fdba74;">
					<div class="bizfb-stat-num" style="color: #c2410c;"><?php echo empty( $app_id ) ? '⚠️' : '✅'; ?></div>
					<div class="bizfb-stat-label" style="color: #ea580c;">Facebook App <?php echo empty( $app_id ) ? 'chưa cấu hình' : 'đã cấu hình'; ?></div>
				</div>
			</div>

			<div class="bizfb-grid">
				<!-- LEFT: danh sách bot -->
				<div>
					<div class="bizfb-card">
						<h2 class="bizfb-badge"><span class="bizfb-dot dot-blue"></span>Danh sách Bot (<?php echo count( $bots ); ?>)</h2>
						<?php if ( empty( $bots ) ) : ?>
							<div class="bizfb-empty">
								<span style="font-size:40px;">🤖</span><br>
								Chưa có bot nào.<br>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots&action=add' ) ); ?>" class="button button-primary" style="margin-top:12px;">➕ Tạo bot đầu tiên</a>
							</div>
						<?php else : ?>
							<?php foreach ( $bots as $bot ) : ?>
								<div class="bizfb-bot-row">
									<div class="bizfb-bot-info">
										<div class="bizfb-bot-avatar">📘</div>
										<div>
											<div class="bizfb-bot-name"><?php echo esc_html( $bot->bot_name ); ?></div>
											<div class="bizfb-bot-meta">
												Page ID: <code><?php echo esc_html( $bot->page_id ); ?></code>
												&nbsp;•&nbsp;
												<span class="bizfb-status-<?php echo esc_attr( $bot->status ); ?>">
													<?php echo $bot->status === 'active' ? '● Hoạt động' : '○ Tắt'; ?>
												</span>
											</div>
										</div>
									</div>
									<div class="bizfb-row-actions">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots&action=edit&bot_id=' . $bot->id ) ); ?>">✏️ Sửa</a>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-inbox&page_id=' . $bot->page_id ) ); ?>">💬 Inbox</a>
										<a href="#" class="delete-bot danger" data-bot-id="<?php echo esc_attr( $bot->id ); ?>">🗑️</a>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<!-- RIGHT: webhook + hướng dẫn -->
				<div>
					<div class="bizfb-card">
						<h2 class="bizfb-badge"><span class="bizfb-dot dot-green"></span>🔗 Webhook & Cấu hình</h2>
						<table class="form-table" style="margin:0;">
							<tr>
								<th style="width:120px;">Callback URL</th>
								<td>
									<div class="bizfb-code-block"><?php echo esc_html( $webhook ); ?></div>
									<button class="button button-small" style="margin-top:6px;" onclick="navigator.clipboard.writeText('<?php echo esc_js( $webhook ); ?>');this.textContent='✅ Đã copy!';">📋 Copy</button>
								</td>
							</tr>
							<tr>
								<th>Verify Token</th>
								<td><code><?php echo esc_html( $verify ); ?></code></td>
							</tr>
							<tr>
								<th>Subscribe</th>
								<td><code>messages, messaging_postbacks, feed</code></td>
							</tr>
							<tr>
								<th>App ID</th>
								<td><?php echo $app_id ? '<code>' . esc_html( $app_id ) . '</code>' : '<span style="color:#ef4444;">⚠️ Chưa nhập — <a href="' . esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-connect' ) ) . '">Kết nối ngay</a></span>'; ?></td>
							</tr>
						</table>
					</div>

					<div class="bizfb-card">
						<h2 class="bizfb-badge"><span class="bizfb-dot dot-amber"></span>📋 Hướng dẫn nhanh</h2>
						<div class="bizfb-help">
							<ol>
								<li>Vào <a href="https://developers.facebook.com/apps" target="_blank">developers.facebook.com/apps</a> → Tạo App Business.</li>
								<li>Thêm sản phẩm <strong>Messenger</strong> vào App.</li>
								<li>Copy <strong>App ID</strong> &amp; <strong>App Secret</strong> → điền vào <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-connect' ) ); ?>">🔗 Kết nối Fanpage</a>.</li>
								<li>Trong Messenger → Settings → Webhooks → nhập Callback URL + Verify Token ở trên.</li>
								<li>Kết nối Page → copy <strong>Page Access Token</strong> → <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots&action=add' ) ); ?>">➕ Thêm Bot mới</a>.</li>
							</ol>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render edit form
	 */
	private function render_edit_form( $bot ) {
		$is_edit = ! empty( $bot );
		?>
		<div class="wrap bizcity-facebook-bot-wrap">
			<h1>
				<?php echo $is_edit ? 'Chỉnh sửa Bot' : 'Thêm Bot mới'; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots' ) ); ?>" class="page-title-action">
					← Quay lại
				</a>
			</h1>
			
			<div class="bizcity-fb-card">
				<form id="facebook-bot-form">
					<?php wp_nonce_field( 'bizcity_facebook_bot_save', 'nonce' ); ?>
					<input type="hidden" name="bot_id" value="<?php echo $is_edit ? esc_attr( $bot->id ) : ''; ?>">
					
					<table class="form-table">
						<tr>
							<th><label for="bot_name">Tên Bot <span class="required">*</span></label></th>
							<td>
								<input type="text" id="bot_name" name="bot_name" class="regular-text" 
									value="<?php echo $is_edit ? esc_attr( $bot->bot_name ) : ''; ?>" required>
								<p class="description">Tên để nhận diện Bot trong hệ thống</p>
							</td>
						</tr>
						<tr>
							<th><label for="page_id">Page ID <span class="required">*</span></label></th>
							<td>
								<input type="text" id="page_id" name="page_id" class="regular-text" 
									value="<?php echo $is_edit ? esc_attr( $bot->page_id ) : ''; ?>" required>
								<p class="description">ID của Facebook Page</p>
							</td>
						</tr>
						<tr>
							<th><label for="page_access_token">Page Access Token <span class="required">*</span></label></th>
							<td>
								<textarea id="page_access_token" name="page_access_token" class="large-text" rows="3" required><?php echo $is_edit ? esc_textarea( $bot->page_access_token ) : ''; ?></textarea>
								<p class="description">Token lấy từ Facebook Developer</p>
							</td>
						</tr>
						<tr>
							<th><label for="app_secret">App Secret</label></th>
							<td>
								<input type="text" id="app_secret" name="app_secret" class="regular-text" 
									value="<?php echo $is_edit ? esc_attr( $bot->app_secret ) : ''; ?>">
								<p class="description">Dùng để verify webhook signature (khuyến nghị)</p>
							</td>
						</tr>
						<tr>
							<th><label for="verify_token">Verify Token</label></th>
							<td>
								<input type="text" id="verify_token" name="verify_token" class="regular-text" 
									value="<?php echo $is_edit ? esc_attr( $bot->verify_token ) : 'bizgpt'; ?>">
								<p class="description">Token để Facebook verify webhook (mặc định: bizgpt)</p>
							</td>
						</tr>
						<tr>
							<th><label for="ai_enabled">Kích hoạt AI</label></th>
							<td>
								<label>
									<input type="checkbox" id="ai_enabled" name="ai_enabled" value="1" 
										<?php checked( $is_edit && $bot->ai_enabled ); ?>>
									Bật trả lời tự động bằng AI
								</label>
							</td>
						</tr>
						<tr>
							<th><label for="openai_api_key">OpenAI API Key</label></th>
							<td>
								<input type="text" id="openai_api_key" name="openai_api_key" class="regular-text" 
									value="<?php echo $is_edit ? esc_attr( $bot->openai_api_key ) : ''; ?>">
								<p class="description">API key để sử dụng GPT trả lời tin nhắn</p>
							</td>
						</tr>
						<tr>
							<th><label for="ai_prompt">AI System Prompt</label></th>
							<td>
								<textarea id="ai_prompt" name="ai_prompt" class="large-text" rows="5"><?php echo $is_edit ? esc_textarea( $bot->ai_prompt ) : 'Bạn là trợ lý ảo thân thiện của cửa hàng. Hãy trả lời ngắn gọn, lịch sự và hữu ích.'; ?></textarea>
							</td>
						</tr>
						<tr>
							<th><label for="status">Trạng thái</label></th>
							<td>
								<select id="status" name="status">
									<option value="active" <?php selected( ! $is_edit || $bot->status === 'active' ); ?>>Active</option>
									<option value="inactive" <?php selected( $is_edit && $bot->status === 'inactive' ); ?>>Inactive</option>
								</select>
							</td>
						</tr>
					</table>
					
					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php echo $is_edit ? 'Cập nhật Bot' : 'Thêm Bot'; ?>
						</button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render listener page
	 */
	public function render_listener_page() {
		$db = BizCity_Facebook_Bot_Database::instance();
		$pages = $db->get_connected_pages();
		?>
		<div class="wrap bizcity-facebook-bot-wrap">
			<h1>Nghe Webhook Facebook</h1>
			
			<div class="bizcity-fb-card">
				<div class="listener-controls">
					<label for="select-page">Chọn Page:</label>
					<select id="select-page">
						<option value="">-- Chọn Page --</option>
						<?php foreach ( $pages as $page ) : ?>
							<option value="<?php echo esc_attr( $page->bot_id ); ?>" data-page-id="<?php echo esc_attr( $page->page_id ); ?>">
								<?php echo esc_html( $page->bot_name ); ?> (<?php echo esc_html( $page->page_id ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					
					<button type="button" id="btn-start-listener" class="button button-primary" disabled>
						<span class="dashicons dashicons-controls-play" style="margin-top: 3px;"></span>
						Bắt đầu nghe
					</button>
					
					<button type="button" id="btn-stop-listener" class="button" disabled>
						<span class="dashicons dashicons-controls-pause" style="margin-top: 3px;"></span>
						Dừng nghe
					</button>
					
					<button type="button" id="btn-clear-log" class="button">
						<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
						Xóa log
					</button>
				</div>
				
				<div class="listener-status">
					<span id="listener-status-text">Chưa kết nối</span>
					<span id="listener-status-indicator" class="status-indicator offline"></span>
				</div>
			</div>
			
			<div class="bizcity-fb-card">
				<h3>Webhook Log</h3>
				<div id="webhook-log" class="webhook-log">
					<p class="log-empty">Nhấn "Bắt đầu nghe" để bắt đầu nhận webhook từ Facebook...</p>
				</div>
			</div>
		</div>
		
		<script>
		jQuery(document).ready(function($) {
			// Change select-bot to select-page for compatibility with existing JS
			$('#select-page').attr('id', 'select-bot');
		});
		</script>
		<?php
	}
	
	/**
	 * Render test API page
	 */
	public function render_test_api_page() {
		$db = BizCity_Facebook_Bot_Database::instance();
		$pages = $db->get_connected_pages();
		
		// Get recent clients for each page (JSON for JS)
		$clients_by_page = array();
		foreach ( $pages as $page ) {
			$clients = $db->get_recent_clients_by_page( $page->page_id, 20 );
			$clients_by_page[ $page->page_id ] = $clients;
		}
		?>
		<div class="wrap bizcity-facebook-bot-wrap">
			<h1>Test Facebook Bot API</h1>
			
			<div class="bizcity-fb-card">
				<h3>Chọn Page</h3>
				<select id="test-page-select" class="regular-text">
					<option value="" data-page-id="">-- Chọn Page --</option>
					<?php foreach ( $pages as $page ) : ?>
						<option value="<?php echo esc_attr( $page->bot_id ); ?>" data-page-id="<?php echo esc_attr( $page->page_id ); ?>">
							<?php echo esc_html( $page->bot_name ); ?> (Page: <?php echo esc_html( $page->page_id ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
				
				<button type="button" id="btn-get-me" class="button">
					<span class="dashicons dashicons-admin-users" style="margin-top: 3px;"></span>
					Get Page Info
				</button>
			</div>
			
			<div class="bizcity-fb-test-grid">
				<!-- Send Message -->
				<div class="bizcity-fb-card">
					<h3>📤 Gửi tin nhắn</h3>
					<table class="form-table">
						<tr>
							<th><label for="test-client-select">Chọn khách hàng</label></th>
							<td>
								<select id="test-client-select" class="regular-text">
									<option value="">-- Chọn hoặc nhập PSID --</option>
								</select>
								<p class="description">Hoặc nhập trực tiếp PSID bên dưới</p>
							</td>
						</tr>
						<tr>
							<th><label for="test-user-id">User ID (PSID)</label></th>
							<td>
								<input type="text" id="test-user-id" class="regular-text" placeholder="Recipient PSID">
							</td>
						</tr>
						<tr>
							<th><label for="test-message">Nội dung</label></th>
							<td>
								<textarea id="test-message" class="large-text" rows="3" placeholder="Nhập tin nhắn..."></textarea>
							</td>
						</tr>
					</table>
					<button type="button" id="btn-send-message" class="button button-primary">
						Gửi tin nhắn
					</button>
				</div>
				
				<!-- Send Photo -->
				<div class="bizcity-fb-card">
					<h3>📷 Gửi hình ảnh</h3>
					<table class="form-table">
						<tr>
							<th><label for="test-photo-client-select">Chọn khách hàng</label></th>
							<td>
								<select id="test-photo-client-select" class="regular-text">
									<option value="">-- Chọn hoặc nhập PSID --</option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="test-photo-user-id">User ID (PSID)</label></th>
							<td>
								<input type="text" id="test-photo-user-id" class="regular-text" placeholder="Recipient PSID">
							</td>
						</tr>
						<tr>
							<th><label for="test-photo-url">URL hình ảnh</label></th>
							<td>
								<input type="url" id="test-photo-url" class="regular-text" placeholder="https://...">
							</td>
						</tr>
						<tr>
							<th><label for="test-photo-caption">Caption</label></th>
							<td>
								<input type="text" id="test-photo-caption" class="regular-text" placeholder="Caption (tùy chọn)">
							</td>
						</tr>
					</table>
					<button type="button" id="btn-send-photo" class="button button-primary">
						Gửi hình ảnh
					</button>
				</div>
			</div>
			
			<!-- API Result -->
			<div class="bizcity-fb-card">
				<h3>Kết quả API</h3>
				<pre id="api-result" class="api-result">Chọn page và thực hiện test để xem kết quả...</pre>
			</div>
		</div>
		
		<script>
		jQuery(document).ready(function($) {
			var clientsByPage = <?php echo wp_json_encode( $clients_by_page ); ?>;
			
			// Copy page select value to bot select (for existing JS compatibility)
			$('#test-page-select').on('change', function() {
				var $selected = $(this).find(':selected');
				var pageId = $selected.data('page-id');
				var botId = $(this).val();
				
				// Update hidden bot select for compatibility
				$('#test-bot-select').val(botId);
				
				// Update client dropdowns
				var clients = clientsByPage[pageId] || [];
				var html = '<option value="">-- Chọn hoặc nhập PSID --</option>';
				
				clients.forEach(function(client) {
					var name = client.customer_name || client.client_name || client.client_id;
					var lastMsg = client.last_message ? client.last_message.substring(0, 30) + '...' : '';
					html += '<option value="' + client.client_id + '">' + name + ' (' + lastMsg + ')</option>';
				});
				
				$('#test-client-select').html(html);
				$('#test-photo-client-select').html(html);
			});
			
			// Fill PSID when client selected
			$('#test-client-select').on('change', function() {
				$('#test-user-id').val($(this).val());
			});
			
			$('#test-photo-client-select').on('change', function() {
				$('#test-photo-user-id').val($(this).val());
			});
			
			// Create hidden bot select for JS compatibility
			$('<input type="hidden" id="test-bot-select">').insertAfter('#test-page-select');
		});
		</script>
		<?php
	}
	
	/**
	 * Render inbox page
	 */
	public function render_inbox_page() {
		$db = BizCity_Facebook_Bot_Database::instance();
		$pages = $db->get_connected_pages();
		
		$selected_page = isset( $_GET['page_id'] ) ? sanitize_text_field( $_GET['page_id'] ) : '';
		$selected_client = isset( $_GET['client_id'] ) ? sanitize_text_field( $_GET['client_id'] ) : '';
		
		// Get recent clients for selected page
		$clients = array();
		if ( ! empty( $selected_page ) ) {
			$clients = $db->get_recent_clients_by_page( $selected_page, 50 );
		}
		
		// Get messages for selected client
		$messages = array();
		if ( ! empty( $selected_client ) && ! empty( $selected_page ) ) {
			$messages = $db->get_inbox_messages( $selected_client, $selected_page, 100 );
		}
		?>
		<div class="wrap bizcity-facebook-bot-wrap">
			<h1>Inbox - Tin nhắn Facebook</h1>
			
			<div class="bizcity-fb-card">
				<form method="get" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
					<input type="hidden" name="page" value="bizcity-facebook-bot-inbox">
					
					<div>
						<label for="filter-page"><strong>Page:</strong></label>
						<select id="filter-page" name="page_id" onchange="this.form.submit()">
							<option value="">-- Chọn Page --</option>
							<?php foreach ( $pages as $page ) : ?>
								<option value="<?php echo esc_attr( $page->page_id ); ?>" <?php selected( $selected_page, $page->page_id ); ?>>
									<?php echo esc_html( $page->bot_name ); ?> (<?php echo esc_html( $page->page_id ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					
					<?php if ( ! empty( $clients ) ) : ?>
					<div>
						<label for="filter-client"><strong>Khách hàng:</strong></label>
						<select id="filter-client" name="client_id" onchange="this.form.submit()">
							<option value="">-- Chọn khách hàng --</option>
							<?php foreach ( $clients as $client ) : 
								$name = $client->customer_name ?: $client->client_name ?: $client->client_id;
							?>
								<option value="<?php echo esc_attr( $client->client_id ); ?>" <?php selected( $selected_client, $client->client_id ); ?>>
									<?php echo esc_html( $name ); ?> - <?php echo esc_html( wp_trim_words( $client->last_message, 5 ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php endif; ?>
				</form>
			</div>
			
			<?php if ( ! empty( $selected_client ) && ! empty( $messages ) ) : ?>
			<div class="bizcity-fb-card">
				<h3>Cuộc hội thoại với <?php echo esc_html( $selected_client ); ?></h3>
				<div class="inbox-chat-container" style="max-height: 500px; overflow-y: auto; padding: 10px; background: #f5f5f5; border-radius: 8px;">
					<?php foreach ( $messages as $msg ) : 
						// Fallback: if sender_type is empty (old data), default to 'client'
						$sender_type = ! empty( $msg->sender_type ) ? $msg->sender_type : 'client';
						$is_client = $sender_type === 'client';
						$align = $is_client ? 'flex-start' : 'flex-end';
						$bg = $is_client ? '#fff' : '#0084ff';
						$color = $is_client ? '#333' : '#fff';
					?>
						<div style="display: flex; justify-content: <?php echo $align; ?>; margin-bottom: 8px;">
							<div style="max-width: 70%; padding: 8px 12px; background: <?php echo $bg; ?>; color: <?php echo $color; ?>; border-radius: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
								<?php if ( $msg->message_type === 'image' && ! empty( $msg->attachment_url ) ) : ?>
									<img src="<?php echo esc_url( $msg->attachment_url ); ?>" style="max-width: 200px; border-radius: 8px;">
								<?php else : ?>
									<?php echo esc_html( $msg->message_text ); ?>
								<?php endif; ?>
								<div style="font-size: 11px; opacity: 0.7; margin-top: 4px;">
									<?php echo esc_html( date( 'H:i d/m', strtotime( $msg->created_at ) ) ); ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php elseif ( ! empty( $selected_page ) && empty( $selected_client ) ) : ?>
			<div class="bizcity-fb-card">
				<h3>Danh sách khách hàng gần đây</h3>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 150px;">Client ID</th>
							<th style="width: 150px;">Tên</th>
							<th>Tin nhắn cuối</th>
							<th style="width: 150px;">Thời gian</th>
							<th style="width: 100px;">Hành động</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $clients ) ) : ?>
							<tr>
								<td colspan="5">Chưa có tin nhắn nào từ page này.</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $clients as $client ) : 
								$name = $client->customer_name ?: $client->client_name ?: '';
								$view_url = add_query_arg( array(
									'page'      => 'bizcity-facebook-bot-inbox',
									'page_id'   => $selected_page,
									'client_id' => $client->client_id,
								), admin_url( 'admin.php' ) );
							?>
								<tr>
									<td><code><?php echo esc_html( $client->client_id ); ?></code></td>
									<td><?php echo esc_html( $name ); ?></td>
									<td><?php echo esc_html( wp_trim_words( $client->last_message, 10 ) ); ?></td>
									<td><?php echo esc_html( $client->last_message_time ); ?></td>
									<td>
										<a href="<?php echo esc_url( $view_url ); ?>" class="button button-small">Xem</a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php else : ?>
			<div class="bizcity-fb-card">
				<p>Chọn Page để xem danh sách khách hàng và tin nhắn.</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
	
	/**
	 * Render logs page
	 */
	public function render_logs_page() {
		$db = BizCity_Facebook_Bot_Database::instance();
		$bots = $db->get_active_bots();
		
		$selected_bot = isset( $_GET['bot_id'] ) ? intval( $_GET['bot_id'] ) : 0;
		$logs = $db->get_logs( $selected_bot, 100 );
		?>
		<div class="wrap bizcity-facebook-bot-wrap">
			<h1>Nhật ký Facebook Bot</h1>
			
			<div class="bizcity-fb-card">
				<form method="get">
					<input type="hidden" name="page" value="bizcity-facebook-bot-logs">
					<label for="filter-bot">Chọn Bot:</label>
					<select id="filter-bot" name="bot_id" onchange="this.form.submit()">
						<option value="">-- Tất cả Bot --</option>
						<?php foreach ( $bots as $bot ) : ?>
							<option value="<?php echo esc_attr( $bot->id ); ?>" <?php selected( $selected_bot, $bot->id ); ?>>
								<?php echo esc_html( $bot->bot_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>
			
			<div class="bizcity-fb-card">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 60px;">ID</th>
							<th style="width: 100px;">Bot ID</th>
							<th style="width: 100px;">Loại</th>
							<th>Nội dung</th>
							<th style="width: 150px;">Thời gian</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $logs ) ) : ?>
							<tr>
								<td colspan="5">Chưa có log nào.</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $logs as $log ) : ?>
								<tr>
									<td><?php echo esc_html( $log->id ); ?></td>
									<td><?php echo esc_html( $log->bot_id ); ?></td>
									<td>
										<span class="log-type log-type-<?php echo esc_attr( $log->event_name ); ?>">
											<?php echo esc_html( $log->event_name ); ?>
										</span>
									</td>
									<td>
										<code style="font-size: 11px; word-break: break-all;">
											<?php echo esc_html( wp_trim_words( $log->event_data, 50 ) ); ?>
										</code>
									</td>
									<td><?php echo esc_html( $log->created_at ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Check if current user can access migration tools
	 * Only super_admin or user with login 'admin1' can access
	 */
	private function can_access_migration_tools() {
		// Check if user is logged in
		if ( ! is_user_logged_in() ) {
			return false;
		}
		
		$current_user = wp_get_current_user();
		
		// Allow super_admin (multisite)
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		
		// Allow user with login 'admin1'
		if ( $current_user->user_login === 'admin1' ) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Render migration tools page
	 */
	public function render_migration_page() {
		// Security check - only super_admin or admin1
		if ( ! $this->can_access_migration_tools() ) {
			wp_die( 'Bạn không có quyền truy cập trang này.', 'Không có quyền', array( 'response' => 403 ) );
		}
		
		// Handle force migrate action
		if ( isset( $_POST['action'] ) && $_POST['action'] === 'force_migrate' ) {
			check_admin_referer( 'bizcity_fb_migration' );
			
			if ( class_exists( 'BizCity_Facebook_Bot_Migration' ) ) {
				BizCity_Facebook_Bot_Migration::force_migrate();
				echo '<div class="notice notice-success"><p>Migration đã chạy xong. Kiểm tra log để xem chi tiết.</p></div>';
			}
		}
		
		// Get status
		$status = array();
		if ( class_exists( 'BizCity_Facebook_Bot_Migration' ) ) {
			$status = BizCity_Facebook_Bot_Migration::get_status();
		}
		?>
		<div class="wrap bizcity-facebook-bot-wrap">
			<h1>Database Migration Tools</h1>
			
			<div class="bizcity-fb-card">
				<h2>Trạng thái Migration</h2>
				<table class="wp-list-table widefat">
					<tr>
						<th>Current Version</th>
						<td><code><?php echo esc_html( $status['current_version'] ?? 'N/A' ); ?></code></td>
					</tr>
					<tr>
						<th>Target Version</th>
						<td><code><?php echo esc_html( $status['target_version'] ?? 'N/A' ); ?></code></td>
					</tr>
				</table>
			</div>
			
			<div class="bizcity-fb-card">
				<h2>Trạng thái bảng database</h2>
				<p>Hệ thống sẽ tự động di chuyển dữ liệu từ bảng cũ (<code>bizgpt_*</code>) sang bảng mới (<code>bizcity_facebook_*</code>).</p>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th>Bảng cũ</th>
							<th>Bảng mới</th>
							<th>Trạng thái bảng cũ</th>
							<th>Trạng thái bảng mới</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $status['tables'] ) ) : ?>
							<?php foreach ( $status['tables'] as $old_name => $info ) : ?>
								<tr>
									<td><code><?php echo esc_html( $old_name ); ?></code></td>
									<td><code><?php echo esc_html( $info['new_name'] ); ?></code></td>
									<td>
										<?php if ( $info['old_exists'] ) : ?>
											<span style="color: orange;">⚠️ Còn tồn tại</span>
										<?php else : ?>
											<span style="color: green;">✓ Đã xóa/không có</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $info['new_exists'] ) : ?>
											<span style="color: green;">✓ Đã có</span>
										<?php else : ?>
											<span style="color: red;">✗ Chưa có</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="4">Không có thông tin.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			
			<div class="bizcity-fb-card">
				<h2>Actions</h2>
				<form method="post">
					<?php wp_nonce_field( 'bizcity_fb_migration' ); ?>
					<input type="hidden" name="action" value="force_migrate">
					<p>
						<button type="submit" class="button button-primary" onclick="return confirm('Bạn có chắc muốn chạy migration? Hành động này sẽ RENAME hoặc DROP bảng cũ.');">
							🔄 Chạy Migration ngay
						</button>
					</p>
					<p class="description">
						<strong>Lưu ý:</strong> Migration sẽ:<br>
						• Nếu bảng mới chưa có → RENAME bảng cũ thành bảng mới<br>
						• Nếu bảng mới đã có → DROP bảng cũ (dữ liệu trong bảng cũ sẽ mất)<br>
						• Log migration được lưu tại: <code>/bizcity-facebook-bot/logs/migration-*.log</code>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render legacy messenger inbox page (for backward compatibility)
	 * Supports old slug: messenger-inbox-page
	 */
	public function render_legacy_messenger_inbox_page() {
		// Check if old function exists from messenger/inbox.php
		if ( function_exists( 'messenger_inbox_page' ) ) {
			messenger_inbox_page();
			return;
		}
		
		// Get pages from both new system and legacy option
		$db = BizCity_Facebook_Bot_Database::instance();
		$pages = $db->get_connected_pages();
		
		// If no pages found, show guidance
		if ( empty( $pages ) ) {
			echo '<div class="wrap">';
			echo '<h1>Nhận inbox từ Messenger</h1>';
			echo '<div class="notice notice-warning"><p>';
			echo 'Chưa có Fanpage nào được kết nối. ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-connect' ) ) . '">Kết nối Fanpage</a> ';
			echo 'hoặc <a href="' . esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots&action=add' ) ) . '">Thêm Bot mới</a>.';
			echo '</p></div>';
			echo '</div>';
			return;
		}
		
		// Fallback to new inbox page
		$this->render_inbox_page();
	}
	
	/**
	 * AJAX: Save bot
	 */
	public function ajax_save_bot() {
		check_ajax_referer( 'bizcity_facebook_bot_save', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		
		$bot_id = isset( $_POST['bot_id'] ) ? intval( $_POST['bot_id'] ) : 0;
		
		$data = array(
			'bot_name'          => sanitize_text_field( $_POST['bot_name'] ),
			'page_id'           => sanitize_text_field( $_POST['page_id'] ),
			'page_access_token' => sanitize_textarea_field( $_POST['page_access_token'] ),
			'app_secret'        => sanitize_text_field( $_POST['app_secret'] ),
			'verify_token'      => sanitize_text_field( $_POST['verify_token'] ),
			'ai_enabled'        => isset( $_POST['ai_enabled'] ) ? 1 : 0,
			'openai_api_key'    => sanitize_text_field( $_POST['openai_api_key'] ),
			'ai_prompt'         => sanitize_textarea_field( $_POST['ai_prompt'] ),
			'status'            => sanitize_text_field( $_POST['status'] ),
		);
		
		$db = BizCity_Facebook_Bot_Database::instance();
		
		if ( $bot_id > 0 ) {
			$result = $db->update_bot( $bot_id, $data );
		} else {
			$result = $db->insert_bot( $data );
		}
		
		if ( $result ) {
			wp_send_json_success( array(
				'message' => 'Bot đã được lưu thành công!',
				'redirect' => admin_url( 'admin.php?page=bizcity-facebook-bots' ),
			) );
		} else {
			wp_send_json_error( 'Có lỗi xảy ra khi lưu bot.' );
		}
	}
	
	/**
	 * AJAX: Delete bot
	 */
	public function ajax_delete_bot() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		
		$bot_id = intval( $_POST['bot_id'] );
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$result = $db->delete_bot( $bot_id );
		
		if ( $result ) {
			wp_send_json_success( 'Bot đã được xóa.' );
		} else {
			wp_send_json_error( 'Có lỗi xảy ra.' );
		}
	}
	
	/**
	 * AJAX: Test bot connection
	 */
	public function ajax_test_bot() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		$bot_id = intval( $_POST['bot_id'] );
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( 'Bot không tồn tại.' );
		}
		
		$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
		$result = $api->get_me();
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		
		wp_send_json_success( $result );
	}
	
	/**
	 * AJAX: Get Me (Page Info)
	 */
	public function ajax_get_me() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		$bot_id = intval( $_POST['bot_id'] );
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( 'Bot không tồn tại.' );
		}
		
		$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
		$result = $api->get_me();
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		
		wp_send_json_success( $result );
	}
	
	/**
	 * AJAX: Send message
	 */
	public function ajax_send_message() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		$bot_id = intval( $_POST['bot_id'] );
		$user_id = sanitize_text_field( $_POST['user_id'] );
		$message = sanitize_textarea_field( $_POST['message'] );
		
		if ( empty( $user_id ) || empty( $message ) ) {
			wp_send_json_error( 'Vui lòng nhập User ID và nội dung tin nhắn.' );
		}
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( 'Bot không tồn tại.' );
		}
		
		$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
		$result = $api->send_message( $user_id, $message );
		
		// Log
		$db->insert_log( $bot_id, 'send_message', json_encode( array(
			'user_id' => $user_id,
			'message' => $message,
			'result' => $result,
		) ) );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		
		wp_send_json_success( $result );
	}
	
	/**
	 * AJAX: Send photo
	 */
	public function ajax_send_photo() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		$bot_id = intval( $_POST['bot_id'] );
		$user_id = sanitize_text_field( $_POST['user_id'] );
		$photo_url = esc_url_raw( $_POST['photo_url'] );
		$caption = isset( $_POST['caption'] ) ? sanitize_text_field( $_POST['caption'] ) : '';
		
		if ( empty( $user_id ) || empty( $photo_url ) ) {
			wp_send_json_error( 'Vui lòng nhập User ID và URL hình ảnh.' );
		}
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			wp_send_json_error( 'Bot không tồn tại.' );
		}
		
		$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
		$result = $api->send_photo( $user_id, $photo_url, $caption );
		
		// Log
		$db->insert_log( $bot_id, 'send_photo', json_encode( array(
			'user_id' => $user_id,
			'photo_url' => $photo_url,
			'caption' => $caption,
			'result' => $result,
		) ) );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		
		wp_send_json_success( $result );
	}
	
	/**
	 * AJAX: Start listener
	 */
	public function ajax_start_listener() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		$bot_id = intval( $_POST['bot_id'] );
		
		// Clear existing listener logs
		delete_transient( 'bizcity_fb_listener_' . $bot_id );
		set_transient( 'bizcity_fb_listener_active_' . $bot_id, true, HOUR_IN_SECONDS );
		
		wp_send_json_success( array(
			'message' => 'Listener đã được kích hoạt',
			'bot_id' => $bot_id,
		) );
	}
	
	/**
	 * AJAX: Check listener
	 */
	public function ajax_check_listener() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		$bot_id = intval( $_POST['bot_id'] );
		
		$is_active = get_transient( 'bizcity_fb_listener_active_' . $bot_id );
		$logs = get_transient( 'bizcity_fb_listener_' . $bot_id );
		
		if ( ! $logs ) {
			$logs = array();
		}
		
		// Clear read logs
		delete_transient( 'bizcity_fb_listener_' . $bot_id );
		
		wp_send_json_success( array(
			'active' => $is_active ? true : false,
			'logs' => $logs,
		) );
	}
	
	/**
	 * AJAX: Stop listener
	 */
	public function ajax_stop_listener() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		$bot_id = intval( $_POST['bot_id'] );
		
		delete_transient( 'bizcity_fb_listener_active_' . $bot_id );
		delete_transient( 'bizcity_fb_listener_' . $bot_id );
		
		wp_send_json_success( 'Listener đã dừng.' );
	}
	
	/**
	 * AJAX: Get inbox messages
	 */
	public function ajax_get_inbox() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		$bot_id = intval( $_POST['bot_id'] );
		$limit = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 50;
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$messages = $db->get_inbox_messages( $bot_id, $limit );
		
		wp_send_json_success( $messages );
	}
	
	/**
	 * AJAX: Get recent clients by page_id
	 */
	public function ajax_get_recent_clients() {
		check_ajax_referer( 'bizcity_facebook_bot_nonce', 'nonce' );
		
		$page_id = isset( $_POST['page_id'] ) ? sanitize_text_field( $_POST['page_id'] ) : '';
		$limit = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 20;
		
		if ( empty( $page_id ) ) {
			wp_send_json_error( 'page_id is required' );
		}
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$clients = $db->get_recent_clients_by_page( $page_id, $limit );
		
		wp_send_json_success( $clients );
	}

	/**
	 * Render Connect Fanpage page (OAuth flow + fb_pages_connected legacy)
	 *
	 * Pattern ref: mu-plugins/backup/fb-connect-poster_20260207.php :: fb_connect_page()
	 * Legacy data: get_option('fb_pages_connected') — [{id, name, access_token}]
	 * Future:      waic_intergations_facebook (PHASE 0.31.2 T2.2)
	 */
	public function render_connect_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền truy cập trang này.' );
		}

		// Đọc App ID/Secret: ưu tiên key mới, fallback legacy (xem PHASE-0.31 §L1.6)
		$app_id     = get_option( 'bztfb_app_id',     get_option( 'fb_app_id',     '' ) );
		$app_secret = get_option( 'bztfb_app_secret', get_option( 'fb_app_secret', '' ) );

		$blog_details = get_blog_details( get_current_blog_id() );
		$domain       = 'https://' . rtrim( $blog_details->domain, '/' );

		$scopes = implode( ',', [
			'pages_show_list',
			'pages_manage_posts',
			'pages_manage_engagement',
			'pages_manage_metadata',
			'pages_read_engagement',
			'pages_read_user_content',
			'pages_messaging',
			'pages_messaging_subscriptions',
			'public_profile',
		] );

		$redirect_uri = urlencode( $domain . '/?fb_callback=1' );
		$fb_login_url = ! empty( $app_id )
			? "https://www.facebook.com/v18.0/dialog/oauth?client_id={$app_id}&redirect_uri={$redirect_uri}&scope={$scopes}&response_type=code"
			: '';

		// Xử lý xóa page
		if ( isset( $_GET['remove_page'] ) ) {
			check_admin_referer( 'bztfb_remove_page' );
			$remove_id  = sanitize_text_field( $_GET['remove_page'] );
			$pages_opt  = get_option( 'fb_pages_connected', [] );
			$pages_opt  = array_values( array_filter( $pages_opt, function( $p ) use ( $remove_id ) {
				return $p['id'] !== $remove_id;
			} ) );
			update_option( 'fb_pages_connected', $pages_opt );
		}

		// Đọc legacy pages + DB bots — merge dedup theo page_id
		$legacy_pages = get_option( 'fb_pages_connected', [] );
		$db           = BizCity_Facebook_Bot_Database::instance();
		$db_bots      = $db->get_active_bots();

		$this->print_styles();
		?>
		<div class="wrap bizfb-wrap">
			<h2>Kết nối với Facebook</h2>

			<?php if ( isset( $_GET['status'] ) && $_GET['status'] === 'success' ) : ?>
			<div class="notice notice-success is-dismissible"><p>✅ Đã kết nối Fanpage thành công! Danh sách đã được cập nhật bên dưới.</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['remove_page'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>✅ Đã xóa Fanpage khỏi danh sách.</p></div>
			<?php endif; ?>

			<!-- Hướng dẫn -->
			<div style="background:#fffbe5;border-left:4px solid #ffba00;padding:16px 20px 10px;margin-bottom:20px;border-radius:8px;">
				<b>Hướng dẫn kết nối Fanpage Facebook:</b>
				<ol style="margin:8px 0 8px 20px;">
					<li><b>Bước 1:</b> Vào <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots' ) ); ?>" style="color:#1977f2;"><b>Facebook Bots → ⚙️ Cài đặt App</b></a> để nhập <b>App ID</b> và <b>App Secret</b> của Facebook App bạn quản lý.</li>
					<li><b>Bước 2:</b> Sau khi lưu cấu hình, quay lại trang này và bấm nút <span style="color:#1977f2;">Đăng nhập Facebook</span> bên dưới.</li>
					<li><b>Bước 3:</b> Chọn Fanpage muốn đồng bộ, cấp đầy đủ quyền để hoàn tất kết nối.</li>
					<li><b>Bước 4:</b> Sau khi kết nối, Fanpage sẽ hiển thị ở bảng bên dưới. Bạn có thể thao tác đăng bài, quản lý bình luận...</li>
				</ol>
				<div style="color:#b94a48;font-size:13px;"><b>Lưu ý:</b> Nếu token hết hạn hoặc chưa kết nối, hãy bấm lại nút Đăng nhập để kết nối lại.</div>
			</div>

			<?php if ( empty( $app_id ) ) : ?>
			<div class="notice notice-warning">
				<p>⚠️ Chưa có <b>App ID</b>. <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots#settings' ) ); ?>">Cài đặt ngay</a> trước khi kết nối.</p>
			</div>
			<?php else : ?>
			<a href="<?php echo esc_url( $fb_login_url ); ?>" class="button button-primary" style="font-size:15px;padding:8px 24px;margin-bottom:20px;display:inline-flex;align-items:center;gap:6px;">
				🔗 Đăng nhập Facebook
			</a>
			<?php endif; ?>

			<!-- Danh sách Fanpage từ OAuth (legacy option fb_pages_connected) -->
			<div class="bizfb-card">
				<h2 class="bizfb-badge"><span class="bizfb-dot dot-green"></span>✅ Fanpage đã kết nối qua OAuth (<?php echo count( $legacy_pages ); ?>)</h2>
				<?php if ( empty( $legacy_pages ) ) : ?>
					<div class="bizfb-empty">Chưa có Fanpage nào. Hãy bấm nút Đăng nhập ở trên.</div>
				<?php else : ?>
					<table class="widefat striped" style="border-radius:8px;overflow:hidden;">
						<thead><tr><th>Tên Fanpage</th><th>ID</th><th>Token (8 ký tự)</th><th>Hành động</th></tr></thead>
						<tbody>
						<?php foreach ( $legacy_pages as $p ) :
							$remove_url = wp_nonce_url(
								add_query_arg( [ 'page' => 'bizcity-facebook-bot-connect', 'remove_page' => $p['id'] ], admin_url( 'admin.php' ) ),
								'bztfb_remove_page'
							);
						?>
							<tr>
								<td><a href="https://facebook.com/<?php echo esc_attr( $p['id'] ); ?>" target="_blank"><strong><?php echo esc_html( $p['name'] ); ?></strong></a></td>
								<td><code><?php echo esc_html( $p['id'] ); ?></code></td>
								<td><code><?php echo esc_html( substr( $p['access_token'] ?? '', 0, 8 ) ); ?>••••</code></td>
								<td>
									<a href="<?php echo esc_url( $remove_url ); ?>" class="button button-small" onclick="return confirm('Xóa Fanpage này khỏi danh sách?');">&#10060; Xóa</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $db_bots ) ) : ?>
			<!-- Danh sách Bot từ DB (biệt lập với OAuth list) -->
			<div class="bizfb-card">
				<h2 class="bizfb-badge"><span class="bizfb-dot dot-blue"></span>🤖 Bot đã cấu hình trong hệ thống (<?php echo count( $db_bots ); ?>)</h2>
				<table class="widefat striped" style="border-radius:8px;overflow:hidden;">
					<thead><tr><th>Tên Bot</th><th>Page ID</th><th>Trạng thái</th><th>Hành động</th></tr></thead>
					<tbody>
					<?php foreach ( $db_bots as $b ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $b->bot_name ); ?></strong></td>
							<td><code><?php echo esc_html( $b->page_id ); ?></code></td>
							<td><?php echo $b->status === 'active' ? '<span class="bizfb-status-active">● Active</span>' : '<span class="bizfb-status-inactive">○ Inactive</span>'; ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots&action=edit&bot_id=' . $b->id ) ); ?>">✏️ Sửa</a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * OAuth callback: /?fb_callback=1&code=...
	 *
	 * Pattern ref: mu-plugins/backup/fb-connect-poster_20260207.php :: add_action('init', ...)
	 * Lưu kết quả: update_option('fb_pages_connected', $pages_clean)
	 * Future: sync vào waic_intergations_facebook (PHASE 0.31.2)
	 */
	public function handle_fb_oauth_callback() {
		if ( ! isset( $_GET['fb_callback'] ) || ! isset( $_GET['code'] ) ) {
			return;
		}

		$code       = sanitize_text_field( $_GET['code'] );
		$app_id     = get_option( 'bztfb_app_id',     get_option( 'fb_app_id',     '' ) );
		$app_secret = get_option( 'bztfb_app_secret', get_option( 'fb_app_secret', '' ) );

		if ( empty( $app_id ) || empty( $app_secret ) ) {
			wp_die( 'Chưa có App ID / App Secret. <a href="' . esc_url( admin_url( 'admin.php?page=bizcity-facebook-bots' ) ) . '">Cài đặt ngay</a>.' );
		}

		$blog_details = get_blog_details( get_current_blog_id() );
		$domain       = 'https://' . rtrim( $blog_details->domain, '/' );
		$redirect_uri = $domain . '/?fb_callback=1';

		$token_url = 'https://graph.facebook.com/v18.0/oauth/access_token?' . http_build_query( [
			'client_id'     => $app_id,
			'redirect_uri'  => $redirect_uri,
			'client_secret' => $app_secret,
			'code'          => $code,
		] );

		$response = wp_remote_get( $token_url, [
			'timeout'   => 30,
			'sslverify' => true,
			'headers'   => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			wp_die( 'Lỗi kết nối tới Facebook: ' . esc_html( $response->get_error_message() ) );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http_code !== 200 ) {
			$msg = $body['error']['message'] ?? 'HTTP ' . $http_code;
			wp_die( 'Facebook trả lỗi: ' . esc_html( $msg ) );
		}

		$access_token = $body['access_token'] ?? '';
		if ( empty( $access_token ) ) {
			wp_die( 'Không lấy được access token từ Facebook.' );
		}

		$pages_resp = wp_remote_get(
			'https://graph.facebook.com/v18.0/me/accounts?access_token=' . rawurlencode( $access_token ),
			[ 'timeout' => 15 ]
		);
		$pages_data = json_decode( wp_remote_retrieve_body( $pages_resp ), true );

		if ( empty( $pages_data['data'] ) ) {
			wp_die( 'Không lấy được danh sách Fanpage từ Facebook. Kiểm tra lại quyền App.' );
		}

		$pages_clean = [];
		foreach ( $pages_data['data'] as $page ) {
			$pages_clean[] = [
				'id'           => sanitize_text_field( $page['id'] ),
				'name'         => sanitize_text_field( $page['name'] ),
				'access_token' => sanitize_text_field( $page['access_token'] ),
			];
		}

		// Lưu legacy option (compat với code cũ) — xem PHASE-0.31 §L1.5
		update_option( 'fb_pages_connected', $pages_clean );
		update_option( 'fb_user_token', $access_token );

		wp_redirect( admin_url( 'admin.php?page=bizcity-facebook-bot-connect&status=success' ) );
		exit;
	}

	/**
	 * Render Fanpage Posts page
	 */
	public function render_fanpage_posts_page() {
		$db   = BizCity_Facebook_Bot_Database::instance();
		$bots = $db->get_active_bots();
		$this->print_styles();
		$selected_bot_id = isset( $_GET['bot_id'] ) ? intval( $_GET['bot_id'] ) : 0;
		$bot = $selected_bot_id ? $db->get_bot( $selected_bot_id ) : ( ! empty( $bots ) ? $bots[0] : null );
		?>
		<div class="wrap bizfb-wrap">
			<div class="bizfb-title">
				<div><h1>📰 Quản lý bài đăng</h1><div class="bizfb-sub">Xem bài đăng gần đây trên từng Fanpage.</div></div>
			</div>
			<div class="bizfb-card">
				<h2 class="bizfb-badge"><span class="bizfb-dot dot-blue"></span>Chọn Fanpage</h2>
				<form method="get" style="display:flex;gap:10px;align-items:center;">
					<input type="hidden" name="page" value="bizcity-facebook-bot-posts">
					<select name="bot_id" class="regular-text" onchange="this.form.submit()">
						<?php foreach ( $bots as $b ) : ?>
							<option value="<?php echo esc_attr( $b->id ); ?>" <?php selected( $b->id, $bot ? $bot->id : 0 ); ?>>
								<?php echo esc_html( $b->bot_name ); ?> (<?php echo esc_html( $b->page_id ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>
			<?php if ( $bot ) :
				$token = $bot->page_access_token ?? '';
				$pid   = $bot->page_id ?? '';
				$url   = "https://graph.facebook.com/{$pid}/feed?fields=id,message,created_time,permalink_url,full_picture&access_token={$token}&limit=20";
				$res   = wp_remote_get( $url, [ 'timeout' => 15 ] );
				$data  = json_decode( wp_remote_retrieve_body( $res ), true );
			?>
			<div class="bizfb-card">
				<h2 class="bizfb-badge"><span class="bizfb-dot dot-green"></span>Bài đăng gần đây</h2>
				<?php if ( ! empty( $data['data'] ) ) : ?>
					<table class="widefat striped" style="border-radius:8px;overflow:hidden;">
						<thead><tr><th style="width:80px;">Ảnh</th><th>Nội dung</th><th style="width:160px;">Ngày đăng</th><th style="width:80px;">Link</th></tr></thead>
						<tbody>
						<?php foreach ( $data['data'] as $post ) : ?>
							<tr>
								<td><?php if ( ! empty( $post['full_picture'] ) ) : ?><img src="<?php echo esc_url( $post['full_picture'] ); ?>" style="width:60px;height:60px;object-fit:cover;border-radius:6px;"><?php endif; ?></td>
								<td><?php echo esc_html( mb_substr( $post['message'] ?? '(không có text)', 0, 120 ) ); ?></td>
								<td><?php echo esc_html( $post['created_time'] ?? '' ); ?></td>
								<td><?php if ( ! empty( $post['permalink_url'] ) ) : ?><a href="<?php echo esc_url( $post['permalink_url'] ); ?>" target="_blank">🔗</a><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php elseif ( ! empty( $data['error'] ) ) : ?>
					<div class="bizfb-empty">❌ Lỗi Graph API: <?php echo esc_html( $data['error']['message'] ?? 'Unknown' ); ?></div>
				<?php else : ?>
					<div class="bizfb-empty">Không có bài đăng nào.</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Comments Manager page
	 */
	public function render_comments_manager_page() {
		$db   = BizCity_Facebook_Bot_Database::instance();
		$bots = $db->get_active_bots();
		$this->print_styles();
		$selected_bot_id = isset( $_GET['bot_id'] ) ? intval( $_GET['bot_id'] ) : 0;
		$bot = $selected_bot_id ? $db->get_bot( $selected_bot_id ) : ( ! empty( $bots ) ? $bots[0] : null );
		?>
		<div class="wrap bizfb-wrap">
			<div class="bizfb-title">
				<div><h1>💬 Quản lý Comment</h1><div class="bizfb-sub">Xem comment và tương tác theo từng bài đăng.</div></div>
			</div>
			<div class="bizfb-card">
				<form method="get" style="display:flex;gap:10px;align-items:center;">
					<input type="hidden" name="page" value="bizcity-facebook-bot-comments">
					<select name="bot_id" class="regular-text" onchange="this.form.submit()">
						<?php foreach ( $bots as $b ) : ?>
							<option value="<?php echo esc_attr( $b->id ); ?>" <?php selected( $b->id, $bot ? $bot->id : 0 ); ?>>
								<?php echo esc_html( $b->bot_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>
			<?php if ( $bot ) :
				$token = $bot->page_access_token ?? '';
				$pid   = $bot->page_id ?? '';
				$url   = "https://graph.facebook.com/{$pid}/feed?fields=id,message,created_time,permalink_url,comments.summary(true){id,message,from,created_time},likes.summary(true)&access_token={$token}&limit=10";
				$res   = wp_remote_get( $url, [ 'timeout' => 15 ] );
				$data  = json_decode( wp_remote_retrieve_body( $res ), true );
			?>
			<div class="bizfb-card">
				<h2 class="bizfb-badge"><span class="bizfb-dot dot-blue"></span>Bài đăng &amp; Comment</h2>
				<?php if ( ! empty( $data['data'] ) ) : ?>
					<?php foreach ( $data['data'] as $post ) :
						$comments = $post['comments']['data'] ?? [];
						$ccount   = $post['comments']['summary']['total_count'] ?? 0;
						$lcount   = $post['likes']['summary']['total_count'] ?? 0;
					?>
					<div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:14px;">
						<div style="display:flex;justify-content:space-between;align-items:flex-start;">
							<div style="font-size:13px;color:#0f172a;max-width:70%;"><?php echo esc_html( mb_substr( $post['message'] ?? '(ảnh/video)', 0, 100 ) ); ?></div>
							<div style="font-size:12px;color:#64748b;">❤️ <?php echo (int) $lcount; ?> &nbsp;💬 <?php echo (int) $ccount; ?>&nbsp;&nbsp;<a href="<?php echo esc_url( $post['permalink_url'] ?? '#' ); ?>" target="_blank" style="font-size:11px;">🔗 Xem</a></div>
						</div>
						<?php if ( ! empty( $comments ) ) : ?>
						<div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e5e7eb;">
							<?php foreach ( array_slice( $comments, 0, 5 ) as $cm ) : ?>
								<div style="font-size:12px;margin-bottom:6px;"><strong><?php echo esc_html( $cm['from']['name'] ?? '' ); ?>:</strong> <?php echo esc_html( $cm['message'] ?? '' ); ?></div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				<?php elseif ( ! empty( $data['error'] ) ) : ?>
					<div class="bizfb-empty">❌ Lỗi Graph API: <?php echo esc_html( $data['error']['message'] ?? '' ); ?></div>
				<?php else : ?>
					<div class="bizfb-empty">Không có dữ liệu.</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Business Management page
	 */
	public function render_business_page() {
		$db   = BizCity_Facebook_Bot_Database::instance();
		$bots = $db->get_active_bots();
		$this->print_styles();
		?>
		<div class="wrap bizfb-wrap">
			<div class="bizfb-title">
				<div><h1>🏢 Liên kết Business</h1><div class="bizfb-sub">Xác nhận quyền quản trị viên và trạng thái liên kết Business.</div></div>
			</div>
			<div class="bizfb-grid">
				<div>
					<div class="bizfb-card">
						<h2 class="bizfb-badge"><span class="bizfb-dot dot-blue"></span>Danh sách Fanpage &amp; Token</h2>
						<table class="widefat striped" style="border-radius:8px;overflow:hidden;">
							<thead><tr><th>Tên Bot</th><th>Page ID</th><th>Token (4 ký tự đầu)</th><th>Trạng thái</th></tr></thead>
							<tbody>
							<?php foreach ( $bots as $b ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $b->bot_name ); ?></strong></td>
									<td><code><?php echo esc_html( $b->page_id ); ?></code></td>
									<td><code><?php echo esc_html( substr( $b->page_access_token ?? '', 0, 6 ) ); ?>••••</code></td>
									<td><?php echo ( $b->status === 'active' ) ? '<span class="bizfb-status-active">● Active</span>' : '<span class="bizfb-status-inactive">○ Inactive</span>'; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<div>
					<div class="bizfb-card">
						<h2 class="bizfb-badge"><span class="bizfb-dot dot-amber"></span>Lưu ý về Business Management</h2>
						<div class="bizfb-help">
							<p>Bảng này liệt kê các Fanpage đã cấp quyền quản trị qua <strong>business_management</strong>, phục vụ kết nối và đồng bộ nội dung giữa Fanpage với ứng dụng BizCity.</p>
							<p>Không dùng để truy xuất hoặc quản lý tài sản quảng cáo.</p>
							<p><a href="https://developers.facebook.com/docs/permissions" target="_blank">📖 Tài liệu Permissions Facebook</a></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle save settings (admin-post handler)
	 * Fallback khi bizcity-tool-facebook không load.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Không có quyền.' );
		}
		check_admin_referer( 'bztfb_settings_nonce' );

		update_option( 'bztfb_app_id',       sanitize_text_field( $_POST['bztfb_app_id']       ?? '' ) );
		update_option( 'bztfb_verify_token', sanitize_text_field( $_POST['bztfb_verify_token'] ?? 'bizfbhook' ) );

		$secret = sanitize_text_field( $_POST['bztfb_app_secret'] ?? '' );
		if ( ! empty( $secret ) && $secret !== '••••••••' ) {
			update_option( 'bztfb_app_secret', $secret );
		}

		wp_redirect( add_query_arg( [ 'page' => 'bizcity-facebook-bot-connect', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render Facebook App Settings page
	 * Fallback khi bizcity-tool-facebook không được load trên server.
	 */
	public function render_settings_page() {
		$app_id       = get_option( 'bztfb_app_id', '' );
		$app_secret   = get_option( 'bztfb_app_secret', '' );
		$verify_token = get_option( 'bztfb_verify_token', 'bizfbhook' );
		$webhook_url  = home_url( '/bizfbhook/' );

		$saved   = ! empty( $_GET['saved'] );
		$status  = sanitize_text_field( $_GET['bztfb_status'] ?? '' );
		$pages_n = (int) ( $_GET['bztfb_pages'] ?? 0 );
		$err_msg = sanitize_text_field( urldecode( $_GET['bztfb_msg'] ?? '' ) );
		?>
		<div class="wrap">
			<h1>⚙️ Facebook App — Cài đặt Developer</h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>✅ Đã lưu cài đặt.</p></div>
			<?php endif; ?>
			<?php if ( $status === 'connected' ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>🎉 Kết nối thành công! Đã lưu <strong><?php echo esc_html( $pages_n ); ?></strong> Facebook Page.</p>
				</div>
			<?php elseif ( $status === 'error' ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>❌ Lỗi OAuth: <?php echo esc_html( $err_msg ); ?></p>
				</div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:1100px;">

				<div>
					<div class="postbox">
						<div class="postbox-header"><h2 class="hndle">🔑 Facebook Developer App</h2></div>
						<div class="inside">
							<p>Tạo Facebook App tại <a href="https://developers.facebook.com/apps" target="_blank">developers.facebook.com/apps</a> → loại <strong>Business</strong>.</p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="bztfb_save_settings">
								<?php wp_nonce_field( 'bztfb_settings_nonce' ); ?>
								<table class="form-table">
									<tr>
										<th><label for="bztfb_app_id">App ID</label></th>
										<td><input type="text" id="bztfb_app_id" name="bztfb_app_id"
											value="<?php echo esc_attr( $app_id ); ?>" class="regular-text" placeholder="123456789012345"></td>
									</tr>
									<tr>
										<th><label for="bztfb_app_secret">App Secret</label></th>
										<td><input type="password" id="bztfb_app_secret" name="bztfb_app_secret"
											value="<?php echo empty( $app_secret ) ? '' : '••••••••'; ?>" class="regular-text" placeholder="Để trống = giữ nguyên"></td>
									</tr>
									<tr>
										<th><label for="bztfb_verify_token">Verify Token</label></th>
										<td>
											<input type="text" id="bztfb_verify_token" name="bztfb_verify_token"
												value="<?php echo esc_attr( $verify_token ); ?>" class="regular-text">
											<p class="description">Nhập đúng vào Facebook App → Webhooks → Verify Token.</p>
										</td>
									</tr>
								</table>
								<?php submit_button( 'Lưu cài đặt', 'primary', 'submit', false ); ?>
							</form>
						</div>
					</div>

					<div class="postbox" style="margin-top:16px;">
						<div class="postbox-header"><h2 class="hndle">🔗 Webhook URL</h2></div>
						<div class="inside">
							<code style="display:block;padding:10px;background:#f5f5f5;border-radius:4px;word-break:break-all;"><?php echo esc_html( $webhook_url ); ?></code>
							<p style="margin-top:8px;">Verify Token: <code><?php echo esc_html( $verify_token ); ?></code></p>
							<p class="description">Fields: <em>messages, messaging_postbacks, feed</em></p>
						</div>
					</div>
				</div>

				<div>
					<div class="postbox">
						<div class="postbox-header"><h2 class="hndle">📋 Hướng dẫn tạo Facebook App</h2></div>
						<div class="inside">
							<ol>
								<li>Vào <a href="https://developers.facebook.com/apps" target="_blank">developers.facebook.com/apps</a> → <strong>Create App</strong>.</li>
								<li>Chọn loại <strong>Business</strong>, điền tên app.</li>
								<li>Thêm sản phẩm <strong>Messenger</strong> vào app.</li>
								<li>Copy <strong>App ID</strong> và <strong>App Secret</strong> từ Basic Settings → dán vào form bên trái.</li>
								<li>Trong Messenger → Settings → Webhooks:<br>
									&nbsp;• Callback URL: <code><?php echo esc_html( $webhook_url ); ?></code><br>
									&nbsp;• Verify Token: <code><?php echo esc_html( $verify_token ); ?></code><br>
									&nbsp;• Subscribe: <code>messages, messaging_postbacks, feed</code>
								</li>
								<li>Kết nối Page: Messenger → Pages → <strong>Add or Remove Pages</strong>.</li>
							</ol>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

// Initialize
BizCity_Facebook_Bot_Admin_Menu::instance();
