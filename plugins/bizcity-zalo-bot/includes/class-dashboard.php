<?php
/**
 * BizCity Zalo Bot – Dashboard & Workflow Steps
 *
 * Hiển thị Dashboard tổng quan và workflow 3 bước:
 *   Step 1: Tạo Bots (bizcity-zalo-bots)
 *   Step 2: Test Bots (bizcity-zalo-bot-test-api / listener)
 *   Step 3: Gán Bots cho User quản trị (bizcity-zalo-bot-assign)
 *
 * @package BizCity_Zalo_Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_Dashboard {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Priority 11 → after parent menu registered at priority 10 in class-admin-menu.php
		add_action( 'admin_menu', array( $this, 'add_dashboard_menu' ), 11 );
	}

	/**
	 * Add unified Dashboard as the first submenu under Bots - Zalo
	 */
	public function add_dashboard_menu() {
		// Unified single-screen dashboard with 4 tabs
		add_submenu_page(
			'bizcity-zalo-bots',
			'Dashboard',
			'Dashboard',
			'manage_options',
			'bizcity-zalo-bot-dashboard',
			array( $this, 'render_unified_dashboard' ),
			0
		);

		// Keep slug registered for backward-compat deep-links → redirect to unified dashboard
		add_submenu_page(
			'bizcity-zalo-bots',
			'Gán Bots cho User',
			'Gán Bots',
			'manage_options',
			'bizcity-zalo-bot-assign',
			array( $this, 'redirect_assign_to_dashboard' )
		);
	}

	/**
	 * Redirect legacy assign page to the unified dashboard assign tab.
	 */
	public function redirect_assign_to_dashboard() {
		wp_safe_redirect( admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard&tab=assign' ) );
		exit;
	}

	/* ═══════════════════════════════════════════════════════════
	 * WORKFLOW STEPS BANNER (reusable across pages)
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Render workflow steps bar
	 *
	 * @param int $current_step 1, 2, or 3
	 */
	public static function render_workflow_steps( $current_step = 0 ) {
		$steps = array(
			1 => array(
				'label' => 'Tạo Bots',
				'icon'  => '🤖',
				'url'   => admin_url( 'admin.php?page=bizcity-zalo-bots' ),
				'desc'  => 'Tạo và cấu hình Zalo Bot',
			),
			2 => array(
				'label' => 'Test Bots',
				'icon'  => '🧪',
				'url'   => admin_url( 'admin.php?page=bizcity-zalo-bot-test-api' ),
				'desc'  => 'Kiểm tra kết nối & gửi tin nhắn',
			),
			3 => array(
				'label' => 'Gán Bots',
				'icon'  => '👤',
				'url'   => admin_url( 'admin.php?page=bizcity-zalo-bot-assign' ),
				'desc'  => 'Gán Bot cho tài khoản quản trị',
			),
		);

		echo '<div class="bizcity-zalobot-steps" style="display:flex;gap:0;margin:16px 0 24px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06)">';

		foreach ( $steps as $num => $step ) {
			$is_active   = ( $current_step === $num );
			$is_complete = ( $current_step > $num );

			if ( $is_active ) {
				$bg    = 'background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff';
				$badge = '<span style="background:rgba(255,255,255,0.25);padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700">Đang xem</span>';
			} elseif ( $is_complete ) {
				$bg    = 'background:#ecfdf5;color:#065f46';
				$badge = '<span style="color:#10b981;font-weight:700">✅</span>';
			} else {
				$bg    = 'background:#f8fafc;color:#64748b';
				$badge = '';
			}

			$border_right = ( $num < 3 ) ? 'border-right:1px solid #e2e8f0;' : '';

			echo '<a href="' . esc_url( $step['url'] ) . '" style="flex:1;text-decoration:none;padding:16px 20px;text-align:center;' . $bg . ';' . $border_right . 'transition:all .2s">';
			echo '<div style="font-size:28px;margin-bottom:4px">' . $step['icon'] . '</div>';
			echo '<div style="font-size:13px;font-weight:700">Bước ' . $num . ': ' . esc_html( $step['label'] ) . '</div>';
			echo '<div style="font-size:11px;margin-top:2px;opacity:.8">' . esc_html( $step['desc'] ) . '</div>';
			if ( $badge ) {
				echo '<div style="margin-top:6px">' . $badge . '</div>';
			}
			echo '</a>';
		}

		echo '</div>';
	}

	/* ═══════════════════════════════════════════════════════════
	 * UNIFIED DASHBOARD (4 tabs: bots | assign | test | logs)
	 * ═══════════════════════════════════════════════════════════ */

	public function render_unified_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Không có quyền truy cập.' );
		}

		$db  = BizCity_Zalo_Bot_Database::instance();

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'bots';
		$valid_tabs = array( 'bots', 'assign', 'test', 'logs' );
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'bots';
		}

		// ── Bots tab data ──
		$all_bots = $this->get_all_bots();

		$total_assignments = 0;
		foreach ( $all_bots as $b ) {
			$total_assignments += count( self::get_users_for_bot( (int) $b->id ) );
		}
		$recent_logs       = $db->get_logs( array( 'limit' => 8 ) );
		$gateway_available = function_exists( 'bizcity_gateway_fire_trigger' );

		// ── Assign tab: handle POST ──
		$assign_message      = '';
		$assign_message_type = '';

		if ( ! empty( $_POST['bzcz_assign_action'] ) && check_admin_referer( 'bzcz_assign_bots' ) ) {
			$action = sanitize_text_field( $_POST['bzcz_assign_action'] );

			if ( $action === 'assign' ) {
				$bot_id  = intval( $_POST['assign_bot_id'] ?? 0 );
				$user_id = intval( $_POST['assign_user_id'] ?? 0 );

				if ( $bot_id > 0 && $user_id > 0 ) {
					$result = self::assign_bot_to_user( $bot_id, $user_id );
					if ( $result ) {
						$assign_message      = '✅ Đã gán Bot #' . $bot_id . ' cho User #' . $user_id . ' thành công!';
						$assign_message_type = 'success';
					} else {
						$assign_message      = '⚠️ Bot này đã được gán cho user này rồi.';
						$assign_message_type = 'warning';
					}
				} else {
					$assign_message      = '❌ Vui lòng chọn cả Bot và User.';
					$assign_message_type = 'error';
				}
			}

			if ( $action === 'unassign' ) {
				$bot_id  = intval( $_POST['unassign_bot_id'] ?? 0 );
				$user_id = intval( $_POST['unassign_user_id'] ?? 0 );
				if ( $bot_id > 0 && $user_id > 0 ) {
					self::unassign_bot_from_user( $bot_id, $user_id );
					$assign_message      = '✅ Đã gỡ gán Bot #' . $bot_id . ' khỏi User #' . $user_id . '.';
					$assign_message_type = 'success';
				}
			}
		}

		$all_assignments = self::get_all_assignments();
		$wp_users        = get_users( array(
			'role__in' => array( 'administrator', 'editor', 'author' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		) );

		// ── Logs tab data ──
		$log_bot_id_filter = isset( $_GET['bot_id'] ) ? intval( $_GET['bot_id'] ) : 0;
		$logs              = $db->get_logs( array( 'bot_id' => $log_bot_id_filter, 'limit' => 100 ) );

		require __DIR__ . '/../templates/admin-unified.php';
	}

	/* ═══════════════════════════════════════════════════════════
	 * LEGACY DASHBOARD (kept for reference / direct URL compat)
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * @deprecated Use render_unified_dashboard() instead.
	 */
	public function render_dashboard() {
		$this->render_unified_dashboard();
	}

	/* ═══════════════════════════════════════════════════════════
	 * STEP 3: GÁN BOTS CHO USER QUẢN TRỊ
	 * ═══════════════════════════════════════════════════════════ */

	public function render_assign_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Không có quyền truy cập.' );
		}

		$db   = BizCity_Zalo_Bot_Database::instance();
		$bots = $this->get_all_bots();

		// Handle form submission
		$message      = '';
		$message_type = '';
		if ( ! empty( $_POST['bzcz_assign_action'] ) && check_admin_referer( 'bzcz_assign_bots' ) ) {
			$action = sanitize_text_field( $_POST['bzcz_assign_action'] );

			if ( $action === 'assign' ) {
				$bot_id  = intval( $_POST['assign_bot_id'] ?? 0 );
				$user_id = intval( $_POST['assign_user_id'] ?? 0 );

				if ( $bot_id > 0 && $user_id > 0 ) {
					$result = self::assign_bot_to_user( $bot_id, $user_id );
					if ( $result ) {
						$message      = '✅ Đã gán Bot #' . $bot_id . ' cho User #' . $user_id . ' thành công!';
						$message_type = 'success';
					} else {
						$message      = '⚠️ Bot này đã được gán cho user này rồi.';
						$message_type = 'warning';
					}
				} else {
					$message      = '❌ Vui lòng chọn cả Bot và User.';
					$message_type = 'error';
				}
			}

			if ( $action === 'unassign' ) {
				$bot_id  = intval( $_POST['unassign_bot_id'] ?? 0 );
				$user_id = intval( $_POST['unassign_user_id'] ?? 0 );

				if ( $bot_id > 0 && $user_id > 0 ) {
					self::unassign_bot_from_user( $bot_id, $user_id );
					$message      = '✅ Đã gỡ gán Bot #' . $bot_id . ' khỏi User #' . $user_id . '.';
					$message_type = 'success';
				}
			}
		}

		// Get currently selected bot from URL
		$filter_bot_id = intval( $_GET['bot_id'] ?? 0 );

		// Get all WordPress admin users
		$wp_users = get_users( array(
			'role__in' => array( 'administrator', 'editor', 'author' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		) );

		?>
		<div class="wrap bizcity-zalo-bot-wrap">
			<h1 style="display:flex;align-items:center;gap:10px">
				<span class="dashicons dashicons-admin-users" style="font-size:28px;width:28px;height:28px;color:#6366f1"></span>
				Bước 3: Gán Bots cho tài khoản quản trị
			</h1>

			<?php self::render_workflow_steps( 3 ); ?>

			<!-- Step Navigation -->
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-test-api' ) ); ?>" class="button">← Bước 2: Test Bots</a>
				<span style="font-weight:600;color:#6366f1">👤 Bước 3: Gán Bots cho User quản trị</span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard' ) ); ?>" class="button button-primary">📊 Về Dashboard</a>
			</div>

			<?php if ( $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( $message_type ); ?>" style="padding:12px;border-radius:4px">
					<?php echo esc_html( $message ); ?>
				</div>
			<?php endif; ?>

			<p class="description" style="font-size:14px;margin-bottom:20px">
				Gán mỗi Zalo Bot với một tài khoản WordPress quản trị. Khi người dùng nhắn tin qua Zalo Bot, hệ thống sẽ tự động xác định WordPress user_id tương ứng để sử dụng trong automation, AI chat, và các tính năng gateway.
			</p>

			<!-- Assignment Form -->
			<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin:20px 0;box-shadow:0 2px 8px rgba(0,0,0,.06)">
				<h2 style="margin-top:0;padding-bottom:12px;border-bottom:2px solid #f0f0f0">➕ Gán Bot mới</h2>
				<form method="post">
					<?php wp_nonce_field( 'bzcz_assign_bots' ); ?>
					<input type="hidden" name="bzcz_assign_action" value="assign" />

					<table class="form-table">
						<tr>
							<th scope="row"><label for="assign_bot_id">Chọn Bot</label></th>
							<td>
								<select name="assign_bot_id" id="assign_bot_id" class="regular-text" style="min-width:300px;padding:8px 12px;border-radius:6px;border:1px solid #d0d0d0">
									<option value="">-- Chọn Bot --</option>
									<?php foreach ( $bots as $bot ) : ?>
										<option value="<?php echo esc_attr( $bot->id ); ?>" <?php selected( $filter_bot_id, $bot->id ); ?>>
											<?php echo esc_html( '#' . $bot->id . ' – ' . $bot->bot_name . ' (' . $bot->status . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">Chọn Zalo Bot cần gán cho user quản trị</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="assign_user_id">Chọn User quản trị</label></th>
							<td>
								<select name="assign_user_id" id="assign_user_id" class="regular-text" style="min-width:300px;padding:8px 12px;border-radius:6px;border:1px solid #d0d0d0">
									<option value="">-- Chọn User --</option>
									<?php foreach ( $wp_users as $u ) : ?>
										<option value="<?php echo esc_attr( $u->ID ); ?>">
											<?php echo esc_html( '#' . $u->ID . ' – ' . $u->display_name . ' (' . $u->user_email . ') [' . implode( ', ', $u->roles ) . ']' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">Chọn tài khoản WordPress sẽ quản lý bot này. Tin nhắn Zalo sẽ được xử lý với quyền và ngữ cảnh của user này.</p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary button-hero" style="display:inline-flex;align-items:center;gap:8px">
							🔗 Gán Bot cho User
						</button>
					</p>
				</form>
			</div>

			<!-- Current Assignments -->
			<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin:20px 0;box-shadow:0 2px 8px rgba(0,0,0,.06)">
				<h2 style="margin-top:0;padding-bottom:12px;border-bottom:2px solid #f0f0f0">📋 Danh sách Bot ↔ User đã gán</h2>

				<?php
				$all_assignments = self::get_all_assignments();
				if ( empty( $all_assignments ) ) :
				?>
					<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:15px 20px;margin:15px 0;border-radius:4px">
						<strong>Chưa có gán bot nào.</strong> Sử dụng form ở trên để gán bot cho user quản trị.
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped" style="border:none;margin-top:12px">
						<thead>
							<tr>
								<th style="width:80px">Bot ID</th>
								<th>Tên Bot</th>
								<th style="width:80px">User ID</th>
								<th>Tên User</th>
								<th>Email</th>
								<th>Vai trò</th>
								<th style="width:140px">Hành động</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $all_assignments as $assign ) : ?>
								<tr>
									<td><strong>#<?php echo esc_html( $assign['bot_id'] ); ?></strong></td>
									<td>
										<?php echo esc_html( $assign['bot_name'] ); ?>
										<?php if ( $assign['bot_status'] === 'active' ) : ?>
											<span style="background:#4CAF50;color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;margin-left:6px">Active</span>
										<?php else : ?>
											<span style="background:#ff9800;color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;margin-left:6px">Inactive</span>
										<?php endif; ?>
									</td>
									<td><strong>#<?php echo esc_html( $assign['user_id'] ); ?></strong></td>
									<td><?php echo esc_html( $assign['user_display_name'] ); ?></td>
									<td><code style="font-size:11px"><?php echo esc_html( $assign['user_email'] ); ?></code></td>
									<td><?php echo esc_html( $assign['user_roles'] ); ?></td>
									<td>
										<form method="post" style="display:inline" onsubmit="return confirm('Bạn chắc chắn muốn gỡ gán này?');">
											<?php wp_nonce_field( 'bzcz_assign_bots' ); ?>
											<input type="hidden" name="bzcz_assign_action" value="unassign" />
											<input type="hidden" name="unassign_bot_id" value="<?php echo esc_attr( $assign['bot_id'] ); ?>" />
											<input type="hidden" name="unassign_user_id" value="<?php echo esc_attr( $assign['user_id'] ); ?>" />
											<button type="submit" class="button button-small" style="color:#dc3232;border-color:#dc3232">
												🗑️ Gỡ gán
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- How It Works -->
			<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin:20px 0;box-shadow:0 2px 8px rgba(0,0,0,.06)">
				<h2 style="margin-top:0;padding-bottom:12px;border-bottom:2px solid #f0f0f0">💡 Cách hoạt động</h2>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-top:16px">

					<div style="background:#f0f4ff;padding:20px;border-radius:8px;border-left:4px solid #6366f1">
						<h3 style="margin-top:0;color:#4f46e5">1. Gán Bot ↔ User</h3>
						<p style="margin:8px 0 0;font-size:13px;line-height:1.6">
							Mỗi Zalo Bot được gán cho một hoặc nhiều tài khoản WordPress (admin/editor). Mapping được lưu vào <code>usermeta</code>.
						</p>
					</div>

					<div style="background:#ecfdf5;padding:20px;border-radius:8px;border-left:4px solid #10b981">
						<h3 style="margin-top:0;color:#065f46">2. Auto Resolve User</h3>
						<p style="margin:8px 0 0;font-size:13px;line-height:1.6">
							Khi có tin nhắn từ Zalo gửi đến Bot, webhook handler sẽ tự động tìm WordPress user_id tương ứng với bot đó.
						</p>
					</div>

					<div style="background:#fef3c7;padding:20px;border-radius:8px;border-left:4px solid #f59e0b">
						<h3 style="margin-top:0;color:#92400e">3. Gateway Integration</h3>
						<p style="margin:8px 0 0;font-size:13px;line-height:1.6">
							Tin nhắn được normalize qua <code>bizcity_gateway_normalize_trigger()</code> và fire vào automation workflow, AI chat với context của user quản trị.
						</p>
					</div>

				</div>

				<div style="background:#e7f5ff;border-left:4px solid #2196F3;padding:15px 20px;margin:20px 0 0;border-radius:4px">
					<strong>📌 UsrMeta keys:</strong>
					<ul style="margin:8px 0 0 20px;line-height:1.8">
						<li><code>_bizcity_zalo_bot_ids</code> — Mảng JSON chứa danh sách bot_id được gán cho user</li>
						<li><code>_bizcity_zalo_bot_{bot_id}_assigned</code> — Flag "1" xác nhận user quản lý bot này</li>
					</ul>
				</div>
			</div>

		</div>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════
	 * STATIC HELPERS: Bot ↔ User Mapping via usermeta
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Gán bot_id cho user_id (lưu vào usermeta)
	 *
	 * @param int $bot_id
	 * @param int $user_id WordPress user ID
	 * @return bool True nếu gán mới, false nếu đã tồn tại
	 */
	public static function assign_bot_to_user( $bot_id, $user_id ) {
		$bot_id  = (int) $bot_id;
		$user_id = (int) $user_id;

		// Get current list of bot_ids for this user
		$bot_ids = self::get_user_bot_ids( $user_id );

		if ( in_array( $bot_id, $bot_ids, true ) ) {
			return false; // Already assigned
		}

		$bot_ids[] = $bot_id;
		update_user_meta( $user_id, '_bizcity_zalo_bot_ids', wp_json_encode( array_values( array_unique( $bot_ids ) ) ) );
		update_user_meta( $user_id, '_bizcity_zalo_bot_' . $bot_id . '_assigned', '1' );

		// Log assignment
		error_log( sprintf( '[BizCity Zalo Bot] Assigned bot #%d to user #%d', $bot_id, $user_id ) );

		return true;
	}

	/**
	 * Gỡ gán bot_id khỏi user_id
	 */
	public static function unassign_bot_from_user( $bot_id, $user_id ) {
		$bot_id  = (int) $bot_id;
		$user_id = (int) $user_id;

		$bot_ids = self::get_user_bot_ids( $user_id );
		$bot_ids = array_filter( $bot_ids, function( $id ) use ( $bot_id ) {
			return $id !== $bot_id;
		} );

		update_user_meta( $user_id, '_bizcity_zalo_bot_ids', wp_json_encode( array_values( $bot_ids ) ) );
		delete_user_meta( $user_id, '_bizcity_zalo_bot_' . $bot_id . '_assigned' );

		error_log( sprintf( '[BizCity Zalo Bot] Unassigned bot #%d from user #%d', $bot_id, $user_id ) );
	}

	/**
	 * Lấy danh sách bot_ids được gán cho user
	 *
	 * @param int $user_id
	 * @return array Array of bot_id (int)
	 */
	public static function get_user_bot_ids( $user_id ) {
		$raw = get_user_meta( (int) $user_id, '_bizcity_zalo_bot_ids', true );
		if ( empty( $raw ) ) {
			return array();
		}

		$ids = json_decode( $raw, true );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Tìm WordPress user_id theo bot_id
	 *
	 * @param int $bot_id
	 * @return array Array of WP user IDs assigned to this bot
	 */
	public static function get_users_for_bot( $bot_id ) {
		$bot_id = (int) $bot_id;

		$users = get_users( array(
			'meta_key'   => '_bizcity_zalo_bot_' . $bot_id . '_assigned',
			'meta_value' => '1',
			'fields'     => 'ID',
		) );

		return array_map( 'intval', $users );
	}

	/**
	 * Resolve WordPress user_id cho bot_id (trả về user đầu tiên)
	 *
	 * @param int $bot_id
	 * @return int WordPress user_id hoặc 0 nếu chưa gán
	 */
	public static function resolve_user_for_bot( $bot_id ) {
		$users = self::get_users_for_bot( $bot_id );
		return ! empty( $users ) ? $users[0] : 0;
	}

	/**
	 * Lấy tất cả assignments (bot ↔ user)
	 *
	 * @return array
	 */
	public static function get_all_assignments() {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_bizcity_zalo_bot_ids' AND meta_value != '' AND meta_value != '[]'",
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return array();
		}

		$db = BizCity_Zalo_Bot_Database::instance();
		$assignments = array();

		foreach ( $results as $row ) {
			$user_id = (int) $row['user_id'];
			$bot_ids = json_decode( $row['meta_value'], true );

			if ( ! is_array( $bot_ids ) ) {
				continue;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			foreach ( $bot_ids as $bot_id ) {
				$bot = $db->get_bot( (int) $bot_id );
				$assignments[] = array(
					'bot_id'            => (int) $bot_id,
					'bot_name'          => $bot ? $bot->bot_name : '(Đã xóa)',
					'bot_status'        => $bot ? $bot->status : 'deleted',
					'user_id'           => $user_id,
					'user_display_name' => $user->display_name,
					'user_email'        => $user->user_email,
					'user_roles'        => implode( ', ', $user->roles ),
				);
			}
		}

		return $assignments;
	}

	/**
	 * Get all bots (active and inactive)
	 */
	private function get_all_bots() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		return $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC" );
	}
}
