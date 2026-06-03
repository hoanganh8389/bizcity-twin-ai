<?php
/**
 * Network Admin — Facebook Page Routes Management
 *
 * Adds a page under Network Admin → CSKH - Facebook → Quản lý Page Routes
 * Super-admin manages: which Page connects to which subsite,
 * Facebook App credentials, central webhook URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Network_Admin_Facebook {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
		add_action( 'wp_ajax_biz_network_fb_save_route', array( $this, 'ajax_save_route' ) );
		add_action( 'wp_ajax_biz_network_fb_delete_route', array( $this, 'ajax_delete_route' ) );
		add_action( 'wp_ajax_biz_network_fb_save_app_settings', array( $this, 'ajax_save_app_settings' ) );
	}

	/**
	 * Add menu under Network Admin.
	 */
	public function add_network_menu() {
		add_menu_page(
			'Facebook Central',
			'Facebook Central',
			'manage_network_options',
			'biz-network-facebook',
			array( $this, 'render_page' ),
			'dashicons-facebook',
			32
		);
	}

	/**
	 * Render the Network Admin page.
	 */
	public function render_page() {
		if ( ! is_super_admin() ) {
			wp_die( 'Access denied.' );
		}

		$routes = array();
		if ( class_exists( 'BizCity_Facebook_Central_Webhook' ) ) {
			$routes = BizCity_Facebook_Central_Webhook::get_all_routes();
		}

		// Get all blogs for dropdown
		$sites = get_sites( array( 'number' => 500, 'orderby' => 'domain' ) );

		// App settings (network-wide)
		$app_id       = get_site_option( 'bizcity_fb_app_id', '' );
		$app_secret   = get_site_option( 'bizcity_fb_app_secret', '' );
		$verify_token = get_site_option( 'bizcity_fb_verify_token', 'bizgpt' );

		// Hub Site config
		$hub_blog_id = 0;
		if ( class_exists( 'BizCity_Facebook_Central_Webhook' ) ) {
			$hub_blog_id = BizCity_Facebook_Central_Webhook::get_hub_blog_id();
			$webhook_url = BizCity_Facebook_Central_Webhook::get_webhook_url();
			$hub_site_url = BizCity_Facebook_Central_Webhook::get_hub_site_url();
		} else {
			$hub_blog_id = get_main_site_id();
			$main_site_url = get_site_url( get_main_site_id() );
			$webhook_url   = trailingslashit( $main_site_url ) . 'facehook/';
			$hub_site_url = $main_site_url;
		}

		$nonce = wp_create_nonce( 'biz_network_fb_nonce' );
		?>
		<div class="wrap">
			<h1>Facebook Central Webhook — Quản lý Page Routes</h1>

			<!-- Webhook Info -->
			<div class="card" style="max-width:800px; margin-bottom:20px; padding:15px;">
				<h2 style="margin-top:0;">🔗 Central Webhook URL</h2>
				<p>Khai báo URL này trong <strong>Facebook App → Webhooks</strong>:</p>
				<code style="font-size:14px; padding:8px 12px; background:#f0f0f0; display:block; margin:10px 0;">
					<?php echo esc_html( $webhook_url ); ?>
				</code>
				<p style="color:#666;">Verify Token: <code><?php echo esc_html( $verify_token ); ?></code></p>
				<p style="color:#666;">Tất cả subsites dùng chung webhook này. Không cần tạo Facebook App riêng cho mỗi site.</p>
				<p style="color:#666;">OAuth redirect URI: <code><?php echo esc_html( trailingslashit( $hub_site_url ) . '?biz_fb_oauth=callback' ); ?></code></p>
			</div>

			<!-- App Settings -->
			<div class="card" style="max-width:800px; margin-bottom:20px; padding:15px;">
				<h2 style="margin-top:0;">⚙️ Facebook App Settings (Network-wide)</h2>
				<table class="form-table" id="biz-fb-app-form">
					<tr>
						<th>Hub Site</th>
						<td>
							<select id="biz_fb_hub_blog_id" class="regular-text">
								<option value="0" <?php selected( (int) get_site_option( 'bizcity_fb_hub_blog_id', 0 ), 0 ); ?>>
									— Main Site (<?php echo esc_html( get_site_url( get_main_site_id() ) ); ?>) —
								</option>
								<?php foreach ( $sites as $site ) : ?>
									<option value="<?php echo (int) $site->blog_id; ?>"
										<?php selected( $hub_blog_id, (int) $site->blog_id ); ?>>
										<?php echo esc_html( $site->domain . $site->path ); ?> (ID: <?php echo (int) $site->blog_id; ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Site đóng vai trò gateway Facebook: nhận webhook, xử lý OAuth callback.<br>
							Domain này phải được đăng ký trong <b>Facebook App → Valid OAuth Redirect URIs</b>.</p>
						</td>
					</tr>
					<tr>
						<th>App ID</th>
						<td><input type="text" id="biz_fb_app_id" value="<?php echo esc_attr( $app_id ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>App Secret</th>
						<td><input type="password" id="biz_fb_app_secret" value="<?php echo esc_attr( $app_secret ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>Verify Token</th>
						<td><input type="text" id="biz_fb_verify_token" value="<?php echo esc_attr( $verify_token ); ?>" class="regular-text" /></td>
					</tr>
				</table>
				<p><button type="button" class="button button-primary" id="biz-fb-save-app">Lưu App Settings</button>
				   <span id="biz-fb-app-msg" style="margin-left:10px;"></span></p>
			</div>

			<!-- Route Table -->
			<h2>📋 Page Routes</h2>
			<p>Mỗi Facebook Page được gán cho 1 subsite. Khi Page nhận webhook, hệ thống tự động chuyển về site tương ứng.</p>

			<table class="wp-list-table widefat fixed striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th style="width:140px;">Page ID</th>
						<th>Page Name</th>
						<th style="width:200px;">Subsite</th>
						<th style="width:100px;">Status</th>
						<th style="width:160px;">Registered</th>
						<th style="width:100px;">Actions</th>
					</tr>
				</thead>
				<tbody id="biz-fb-routes-body">
					<?php if ( empty( $routes ) ) : ?>
						<tr><td colspan="6" style="text-align:center; color:#999;">Chưa có route nào.</td></tr>
					<?php else : ?>
						<?php foreach ( $routes as $route ) :
							$site_url = get_site_url( (int) $route->blog_id );
						?>
						<tr data-page-id="<?php echo esc_attr( $route->page_id ); ?>">
							<td><code><?php echo esc_html( $route->page_id ); ?></code></td>
							<td><?php echo esc_html( $route->page_name ); ?></td>
							<td>
								<a href="<?php echo esc_url( $site_url ); ?>" target="_blank">
									<?php echo esc_html( $site_url ); ?>
								</a>
								<br><small>blog_id: <?php echo (int) $route->blog_id; ?></small>
							</td>
							<td>
								<span class="<?php echo $route->status === 'active' ? 'dashicons dashicons-yes-alt' : 'dashicons dashicons-warning'; ?>"
									  style="color:<?php echo $route->status === 'active' ? '#46b450' : '#dc3232'; ?>;">
								</span>
								<?php echo esc_html( $route->status ); ?>
							</td>
							<td><?php echo esc_html( $route->registered_at ); ?></td>
							<td>
								<button type="button" class="button button-small button-link-delete biz-fb-delete-route"
										data-page-id="<?php echo esc_attr( $route->page_id ); ?>">Xóa</button>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Add New Route -->
			<h3 style="margin-top:30px;">➕ Thêm Page Route</h3>
			<table class="form-table" style="max-width:800px;">
				<tr>
					<th>Page ID</th>
					<td><input type="text" id="biz_new_page_id" class="regular-text" placeholder="VD: 123456789012345" /></td>
				</tr>
				<tr>
					<th>Page Name</th>
					<td><input type="text" id="biz_new_page_name" class="regular-text" placeholder="VD: BizCity Official" /></td>
				</tr>
				<tr>
					<th>Page Access Token</th>
					<td><textarea id="biz_new_access_token" class="large-text" rows="3" placeholder="Lấy từ Facebook Graph API Explorer hoặc qua OAuth flow"></textarea></td>
				</tr>
				<tr>
					<th>Gán cho Subsite</th>
					<td>
						<select id="biz_new_blog_id" class="regular-text">
							<?php foreach ( $sites as $site ) : ?>
								<option value="<?php echo (int) $site->blog_id; ?>">
									<?php echo esc_html( $site->domain . $site->path ); ?> (ID: <?php echo (int) $site->blog_id; ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="biz-fb-add-route">Thêm Route</button>
				<span id="biz-fb-route-msg" style="margin-left:10px;"></span>
			</p>
		</div>

		<!-- Tester Requests (Plan A) -->
		<div class="card" style="max-width:1100px; margin-top:20px; padding:15px;">
			<h2 style="margin-top:0;">👥 Yêu cầu Tester — Facebook Developer</h2>
			<p>Danh sách người dùng đã gửi Facebook Profile để được thêm làm Tester trong Facebook App → App Roles.<br>
			   <strong>Quy trình:</strong> Copy Facebook URL/ID → vào
			   <a href="https://developers.facebook.com/apps/<?php echo esc_attr( $app_id ); ?>/roles/test-users/" target="_blank">Facebook App Roles</a>
			   → Add Testers.</p>

			<?php
			// Query all users who have submitted Facebook profile
			$tester_requests = get_users( array(
				'meta_key'   => 'bztfb_facebook_profile',
				'meta_compare' => 'EXISTS',
				'orderby'    => 'meta_value',
				'fields'     => array( 'ID', 'user_login', 'user_email', 'display_name' ),
			) );
			?>

			<?php if ( empty( $tester_requests ) ) : ?>
				<p style="color:#999;">Chưa có yêu cầu tester nào.</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:160px;">Người dùng</th>
							<th>Email</th>
							<th>Facebook Profile</th>
							<th style="width:120px;">Site</th>
							<th style="width:150px;">Ngày gửi</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tester_requests as $tester ) :
							$fb_profile  = get_user_meta( $tester->ID, 'bztfb_facebook_profile', true );
							$requested_at = get_user_meta( $tester->ID, 'bztfb_tester_requested_at', true );
							$blog_id_req  = (int) get_user_meta( $tester->ID, 'bztfb_tester_blog_id', true );
							$site_url = $blog_id_req ? get_site_url( $blog_id_req ) : '—';

							// Build Facebook link
							$fb_link = $fb_profile;
							if ( preg_match( '/^\d{5,20}$/', $fb_profile ) ) {
								$fb_link = 'https://facebook.com/' . $fb_profile;
							} elseif ( preg_match( '/^[a-zA-Z0-9.]{5,50}$/', $fb_profile ) ) {
								$fb_link = 'https://facebook.com/' . $fb_profile;
							}
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $tester->display_name ); ?></strong>
								<br><small style="color:#666;"><?php echo esc_html( $tester->user_login ); ?> (ID: <?php echo (int) $tester->ID; ?>)</small>
							</td>
							<td><?php echo esc_html( $tester->user_email ); ?></td>
							<td>
								<?php if ( filter_var( $fb_link, FILTER_VALIDATE_URL ) ) : ?>
									<a href="<?php echo esc_url( $fb_link ); ?>" target="_blank" style="word-break:break-all;">
										<?php echo esc_html( $fb_profile ); ?>
									</a>
								<?php else : ?>
									<code><?php echo esc_html( $fb_profile ); ?></code>
								<?php endif; ?>
								<br><button type="button" class="button button-small biz-fb-copy-profile"
									data-value="<?php echo esc_attr( $fb_profile ); ?>"
									title="Copy để paste vào Facebook App Roles">📋 Copy</button>
							</td>
							<td>
								<?php if ( $blog_id_req ) : ?>
									<a href="<?php echo esc_url( $site_url ); ?>" target="_blank">
										<?php echo esc_html( wp_parse_url( $site_url, PHP_URL_HOST ) ); ?>
									</a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $requested_at ?: '—' ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<script>
		jQuery(function($) {
			var nonce = '<?php echo esc_js( $nonce ); ?>';

			// Save App Settings
			$('#biz-fb-save-app').on('click', function() {
				var btn = $(this);
				btn.prop('disabled', true);
				$.post(ajaxurl, {
					action: 'biz_network_fb_save_app_settings',
					_nonce: nonce,
					hub_blog_id: $('#biz_fb_hub_blog_id').val(),
					app_id: $('#biz_fb_app_id').val(),
					app_secret: $('#biz_fb_app_secret').val(),
					verify_token: $('#biz_fb_verify_token').val()
				}, function(r) {
					$('#biz-fb-app-msg').text(r.success ? '✅ Đã lưu' : '❌ ' + (r.data || 'Lỗi')).fadeIn().delay(3000).fadeOut();
					btn.prop('disabled', false);
				});
			});

			// Add Route
			$('#biz-fb-add-route').on('click', function() {
				var btn = $(this);
				var pageId = $('#biz_new_page_id').val().trim();
				var pageName = $('#biz_new_page_name').val().trim();
				var token = $('#biz_new_access_token').val().trim();
				var blogId = $('#biz_new_blog_id').val();

				if (!pageId || !token) {
					$('#biz-fb-route-msg').text('❌ Page ID và Access Token bắt buộc').show();
					return;
				}

				btn.prop('disabled', true);
				$.post(ajaxurl, {
					action: 'biz_network_fb_save_route',
					_nonce: nonce,
					page_id: pageId,
					page_name: pageName,
					access_token: token,
					blog_id: blogId
				}, function(r) {
					$('#biz-fb-route-msg').text(r.success ? '✅ Đã thêm — đang reload...' : '❌ ' + (r.data || 'Lỗi')).show();
					btn.prop('disabled', false);
					if (r.success) location.reload();
				});
			});

			// Delete Route
			$(document).on('click', '.biz-fb-delete-route', function() {
				var pageId = $(this).data('page-id');
				if (!confirm('Xóa route cho Page ' + pageId + '?')) return;

				$(this).prop('disabled', true);
				$.post(ajaxurl, {
					action: 'biz_network_fb_delete_route',
					_nonce: nonce,
					page_id: pageId
				}, function(r) {
					if (r.success) location.reload();
					else alert('Lỗi: ' + (r.data || 'Unknown'));
				});
			});

			// Copy Facebook profile to clipboard
			$(document).on('click', '.biz-fb-copy-profile', function() {
				var val = $(this).data('value');
				if (navigator.clipboard) {
					navigator.clipboard.writeText(val);
					$(this).text('✅ Copied!');
				} else {
					prompt('Copy:', val);
				}
			});
		});
		</script>
		<?php
	}

	// ==========================================
	// AJAX Handlers
	// ==========================================

	/**
	 * Save or update a page route.
	 */
	public function ajax_save_route() {
		$this->verify_network_ajax();

		$page_id      = sanitize_text_field( wp_unslash( $_POST['page_id'] ?? '' ) );
		$page_name    = sanitize_text_field( wp_unslash( $_POST['page_name'] ?? '' ) );
		$access_token = sanitize_textarea_field( wp_unslash( $_POST['access_token'] ?? '' ) );
		$blog_id      = (int) ( $_POST['blog_id'] ?? 0 );

		if ( empty( $page_id ) || empty( $access_token ) || $blog_id <= 0 ) {
			wp_send_json_error( 'Thiếu Page ID, Access Token hoặc Blog ID' );
		}

		if ( ! class_exists( 'BizCity_Facebook_Central_Webhook' ) ) {
			wp_send_json_error( 'Central Webhook class not available' );
		}

		$result = BizCity_Facebook_Central_Webhook::register_route( $page_id, $blog_id, $page_name, $access_token );
		if ( $result ) {
			wp_send_json_success( 'Route saved' );
		} else {
			wp_send_json_error( 'Database error' );
		}
	}

	/**
	 * Delete a page route.
	 */
	public function ajax_delete_route() {
		$this->verify_network_ajax();

		$page_id = sanitize_text_field( wp_unslash( $_POST['page_id'] ?? '' ) );
		if ( empty( $page_id ) ) {
			wp_send_json_error( 'Missing page_id' );
		}

		if ( ! class_exists( 'BizCity_Facebook_Central_Webhook' ) ) {
			wp_send_json_error( 'Central Webhook class not available' );
		}

		$result = BizCity_Facebook_Central_Webhook::unregister_route( $page_id );
		if ( $result ) {
			wp_send_json_success( 'Route deleted' );
		} else {
			wp_send_json_error( 'Database error' );
		}
	}

	/**
	 * Save Facebook App settings (network-wide).
	 */
	public function ajax_save_app_settings() {
		$this->verify_network_ajax();

		$hub_blog_id  = (int) ( $_POST['hub_blog_id'] ?? 0 );
		$app_id       = sanitize_text_field( wp_unslash( $_POST['app_id'] ?? '' ) );
		$app_secret   = sanitize_text_field( wp_unslash( $_POST['app_secret'] ?? '' ) );
		$verify_token = sanitize_text_field( wp_unslash( $_POST['verify_token'] ?? '' ) );

		// Validate hub blog exists
		if ( $hub_blog_id > 0 ) {
			$site = get_site( $hub_blog_id );
			if ( ! $site ) {
				wp_send_json_error( 'Hub Site ID không hợp lệ.' );
			}
		}
		update_site_option( 'bizcity_fb_hub_blog_id', $hub_blog_id );

		update_site_option( 'bizcity_fb_app_id', $app_id );
		update_site_option( 'bizcity_fb_app_secret', $app_secret );
		if ( ! empty( $verify_token ) ) {
			update_site_option( 'bizcity_fb_verify_token', $verify_token );
		}

		wp_send_json_success( 'Settings saved' );
	}

	/**
	 * Verify AJAX request from network admin.
	 */
	private function verify_network_ajax() {
		if ( ! is_super_admin() ) {
			wp_send_json_error( 'Access denied' );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'biz_network_fb_nonce' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}
	}
}

// Initialize
BizCity_Network_Admin_Facebook::instance();
