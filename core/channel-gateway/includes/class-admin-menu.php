<?php
/**
 * Channel Gateway — Admin Menu Pages
 *
 * Registers Gateway submenu under BizChat Menu.
 * Renders Gateway overview (channel list, status, quick actions).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Gateway_Admin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Register submenus via BizChat Menu system.
		add_action( 'bizchat_register_menus', [ $this, 'register_menus' ] );

		// Enqueue assets on our pages only.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX: save channel role definitions & assignments.
		add_action( 'wp_ajax_bizchat_save_channel_roles', [ $this, 'ajax_save_roles' ] );
	}

	/**
	 * Register Gateway pages under BizChat Menu.
	 */
	public function register_menus(): void {
		if ( ! class_exists( 'BizChat_Menu' ) ) {
			return;
		}

		BizChat_Menu::add_submenu( 'bizchat-gateway', [
			'title'      => __( 'Channel Gateway', 'bizcity-twin-ai' ),
			'menu_title' => '⚡ ' . __( 'Gateway', 'bizcity-twin-ai' ),
			'capability' => 'manage_options',
			'callback'   => [ $this, 'render_overview' ],
			'position'   => 60,
		] );
	}

	/**
	 * Enqueue CSS on gateway pages.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'bizchat-gateway' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'bizchat-gateway-admin',
			plugins_url( 'assets/gateway-admin.css', dirname( __FILE__ ) ),
			[],
			BIZCITY_TWIN_CORE_VERSION ?? '1.0'
		);
	}

	/* ─────────────────── Render: Overview ─────────────────── */

	/**
	 * Render Gateway overview page.
	 *
	 * Shows:
	 *  - Registered channel adapters (from Bridge)
	 *  - Legacy channels (always-on)
	 *  - Quick status indicators
	 */
	public function render_overview(): void {
		$bridge   = BizCity_Gateway_Bridge::instance();
		$adapters = $bridge->get_adapters();

		// Legacy channels with admin page links for management.
		// Order: Zalo Bot OA → Zalo BizCity → Telegram → Facebook → others
		$legacy_channels = [
			'zalo_bot'  => [
				'label'       => 'Zalo Bot OA',
				'desc'        => 'Quản lý bot, webhook, gửi & nhận tin nhắn tự động qua Zalo OA',
				'icon'        => '🤖',
				'status'      => class_exists( 'BizCity_Zalo_Bot_Database' ),
				'admin_page'  => 'bizchat-zalobot',
			],
			'zalo'      => [
				'label'       => 'Zalo BizCity',
				'desc'        => 'Gửi tin nhắn qua tài khoản Zalo cá nhân',
				'icon'        => '💬',
				'status'      => function_exists( 'send_zalo_botbanhang' ) || function_exists( 'biz_send_message' ),
				'admin_page'  => '',
			],
			'telegram'  => [
				'label'       => 'Telegram',
				'desc'        => 'Kết nối Telegram Bot API',
				'icon'        => '✈️',
				'status'      => function_exists( 'twf_telegram_send_message' ),
				'admin_page'  => '',
			],
			'facebook'  => [
				'label'       => 'Facebook Messenger',
				'desc'        => 'Kết nối Facebook Fanpage Messenger',
				'icon'        => '📘',
				'status'      => function_exists( 'fbm_send_text_to_user' ),
				'admin_page'  => '',
			],
			'webchat'   => [
				'label'       => 'WebChat',
				'desc'        => 'Chat trực tiếp trên website cho khách hàng',
				'icon'        => '🌐',
				'status'      => class_exists( 'BizCity_WebChat_Trigger' ) || class_exists( 'BizCity_WebChat_Database' ),
				'admin_page'  => '',
			],
			'adminchat' => [
				'label'       => 'Admin Chat',
				'desc'        => 'Chat nội bộ dành cho quản trị viên',
				'icon'        => '👤',
				'status'      => class_exists( 'BizCity_WebChat_Database' ),
				'admin_page'  => '',
			],
		];

		// Determine current tab.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'channels';
		$tabs = [
			'channels' => [ 'label' => '📡 Kênh kết nối',  'icon' => '' ],
			'roles'    => [ 'label' => '🎭 Phân vai',      'icon' => '' ],
			'adapters' => [ 'label' => '🔌 Adapters',      'icon' => '' ],
			'test'     => [ 'label' => '🧪 Test gửi tin',  'icon' => '' ],
		];
		$gateway_base = admin_url( 'admin.php?page=bizchat-gateway' );

		?>
		<div class="wrap bizchat-gw-wrap">
			<h1>⚡ <?php esc_html_e( 'Channel Gateway', 'bizcity-twin-ai' ); ?></h1>
			<p class="description" style="margin-bottom: 20px;">
				<?php esc_html_e( 'Quản lý tập trung tất cả kênh nhắn tin. Nhấn vào từng kênh để cấu hình & quản lý.', 'bizcity-twin-ai' ); ?>
			</p>

			<!-- ── Tab Navigation ── -->
			<nav class="nav-tab-wrapper bizchat-gw-tabs">
				<?php foreach ( $tabs as $tab_key => $tab ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_key, $gateway_base ) ); ?>"
					   class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="bizchat-gw-tab-content">

			<?php if ( $current_tab === 'channels' ) : ?>
			<!-- ═══ TAB: Channel Connections ═══ -->
			<div class="bizchat-gw-grid">
				<?php foreach ( $legacy_channels as $key => $ch ) :
					$active      = $ch['status'];
					$has_adapter = isset( $adapters[ strtoupper( $key ) ] );
					$has_page    = ! empty( $ch['admin_page'] );
					$manage_url  = $has_page ? admin_url( 'admin.php?page=' . $ch['admin_page'] . '&bizcity_iframe=true' ) : '';
				?>
				<div class="bizchat-gw-card <?php echo $active ? 'is-active' : 'is-inactive'; ?> <?php echo $has_page ? 'has-link' : ''; ?>">
					<?php if ( $has_page ) : ?>
					<a href="<?php echo esc_url( $manage_url ); ?>" class="bizchat-gw-card-link" title="<?php echo esc_attr( 'Quản lý ' . $ch['label'] ); ?>"></a>
					<?php endif; ?>
					<div class="bizchat-gw-card-icon"><?php echo esc_html( $ch['icon'] ); ?></div>
					<div class="bizchat-gw-card-body">
						<strong><?php echo esc_html( $ch['label'] ); ?></strong>
						<span class="bizchat-gw-card-desc"><?php echo esc_html( $ch['desc'] ); ?></span>
						<div class="bizchat-gw-card-badges">
							<?php if ( $has_adapter ) : ?>
								<span class="bizchat-gw-badge gw-adapter">Adapter</span>
							<?php endif; ?>
							<span class="bizchat-gw-badge <?php echo $active ? 'gw-on' : 'gw-off'; ?>">
								<?php echo $active ? '● ' . esc_html__( 'Connected', 'bizcity-twin-ai' ) : '○ ' . esc_html__( 'Not available', 'bizcity-twin-ai' ); ?>
							</span>
						</div>
					</div>
					<div class="bizchat-gw-card-action">
						<?php if ( $has_page && $active ) : ?>
							<a href="<?php echo esc_url( $manage_url ); ?>" class="button button-primary button-small">Quản lý →</a>
						<?php elseif ( $has_page ) : ?>
							<a href="<?php echo esc_url( $manage_url ); ?>" class="button button-small">Cài đặt</a>
						<?php else : ?>
							<span class="bizchat-gw-coming-soon">Sắp ra mắt</span>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<?php elseif ( $current_tab === 'roles' ) : ?>
			<!-- ═══ TAB: Channel Role Management ═══ -->
			<?php $this->render_roles_tab(); ?>

			<?php elseif ( $current_tab === 'adapters' ) : ?>
			<!-- ═══ TAB: Registered Adapters ═══ -->
			<?php if ( ! empty( $adapters ) ) : ?>
			<table class="widefat striped bizchat-gw-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Platform', 'bizcity-twin-ai' ); ?></th>
						<th><?php esc_html_e( 'Prefix', 'bizcity-twin-ai' ); ?></th>
						<th><?php esc_html_e( 'Endpoints', 'bizcity-twin-ai' ); ?></th>
						<th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $adapters as $platform => $adapter ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $platform ); ?></strong></td>
						<td><code><?php echo esc_html( $adapter->get_prefix() ); ?></code></td>
						<td><?php echo esc_html( implode( ', ', $adapter->get_endpoints() ) ); ?></td>
						<td><span class="bizchat-gw-on">✓ <?php esc_html_e( 'Active', 'bizcity-twin-ai' ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
			<div class="bizchat-gw-empty">
				<p><?php esc_html_e( 'Chưa có adapter nào được đăng ký. Các adapter sẽ tự động xuất hiện khi plugin kênh được kích hoạt.', 'bizcity-twin-ai' ); ?></p>
			</div>
			<?php endif; ?>

			<?php elseif ( $current_tab === 'test' ) : ?>
			<!-- ═══ TAB: Quick Test ═══ -->
			<h2><?php esc_html_e( 'Quick Test', 'bizcity-twin-ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Gửi tin nhắn test đến bất kỳ kênh nào qua Gateway thống nhất.', 'bizcity-twin-ai' ); ?></p>
			<form id="bizchat-gw-test" class="bizchat-gw-test-form">
				<?php wp_nonce_field( 'wp_rest', '_wpnonce' ); ?>
				<label><?php esc_html_e( 'Chat ID', 'bizcity-twin-ai' ); ?>
					<input type="text" name="chat_id" placeholder="webchat_xxx, zalobot_1_xxx, fb_xxx..." class="regular-text" required />
				</label>
				<label><?php esc_html_e( 'Message', 'bizcity-twin-ai' ); ?>
					<textarea name="message" rows="3" class="large-text" required></textarea>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Send Test', 'bizcity-twin-ai' ); ?></button>
				<div id="bizchat-gw-test-result"></div>
			</form>
			<?php endif; ?>

			</div><!-- .bizchat-gw-tab-content -->

			<script>
			jQuery(function($){
				$('#bizchat-gw-test').on('submit', function(e){
					e.preventDefault();
					var $r = $('#bizchat-gw-test-result').text('Sending...');
					$.ajax({
						url: '<?php echo esc_url( rest_url( 'bizcity/v1/channel/send' ) ); ?>',
						method: 'POST',
						beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', $('[name=_wpnonce]').val()); },
						data: { chat_id: $('[name=chat_id]').val(), message: $('[name=message]').val() },
						success: function(d){ $r.html('<span style="color:green">✓ ' + JSON.stringify(d.data) + '</span>'); },
						error: function(x){ $r.html('<span style="color:red">✗ ' + (x.responseJSON?.message || x.statusText) + '</span>'); }
					});
				});
			});
			</script>
		</div>
		<?php
	}

	/* ─────────────────── AJAX: Save Roles ─────────────────── */

	public function ajax_save_roles(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}
		if ( ! wp_verify_nonce( $_POST['_nonce'] ?? '', 'bizchat_roles_save' ) ) {
			wp_send_json_error( 'Invalid nonce', 403 );
		}

		// 1. Update focus_override for each role definition.
		$roles_input   = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? $_POST['roles'] : [];
		$existing_defs = BizCity_Channel_Role::get_definitions();
		$layers        = BizCity_Channel_Role::get_all_context_layers();

		foreach ( $roles_input as $slug => $fields ) {
			$slug = sanitize_key( $slug );
			if ( ! isset( $existing_defs[ $slug ] ) ) {
				continue;
			}
			$overrides = [];
			$focus     = isset( $fields['focus_override'] ) && is_array( $fields['focus_override'] ) ? $fields['focus_override'] : [];
			foreach ( $focus as $lk => $val ) {
				$lk = sanitize_key( $lk );
				if ( ! isset( $layers[ $lk ] ) || $val === '' ) {
					continue;
				}
				$overrides[ $lk ] = self::string_to_layer_value( sanitize_text_field( $val ) );
			}
			$existing_defs[ $slug ]['focus_override'] = $overrides;
		}

		// Separate builtins vs custom before saving (save_definitions merges builtins).
		$custom_defs = [];
		foreach ( $existing_defs as $slug => $def ) {
			if ( empty( $def['builtin'] ) ) {
				$custom_defs[ $slug ] = $def;
			} else {
				// For builtins, save only the overridden focus_override.
				$builtin_base = BizCity_Channel_Role::get_builtin_definitions()[ $slug ] ?? [];
				$builtin_base['focus_override'] = $def['focus_override'] ?? [];
				$custom_defs[ $slug ] = $builtin_base;
			}
		}
		update_option( 'bizcity_channel_role_definitions', $custom_defs, false );

		// 2. Save assignments.
		$assign_input = isset( $_POST['assignments'] ) && is_array( $_POST['assignments'] ) ? $_POST['assignments'] : [];
		BizCity_Channel_Role::save_assignments( $assign_input );

		wp_send_json_success( [ 'saved' => true ] );
	}

	/* ─────────────────── Render: Roles Tab ─────────────────── */

	private function render_roles_tab(): void {
		$definitions  = BizCity_Channel_Role::get_definitions();
		$assignments  = BizCity_Channel_Role::get_assignments();
		$layers       = BizCity_Channel_Role::get_all_context_layers();
		$bots         = BizCity_Channel_Role::get_bot_instances();
		$nonce        = wp_create_nonce( 'bizchat_roles_save' );

		?>
		<div id="bizchat-roles-app" data-nonce="<?php echo esc_attr( $nonce ); ?>">

		<!-- ── Section 1: Role definitions ── -->
		<h2><?php esc_html_e( 'Định nghĩa vai trò (Role Definitions)', 'bizcity-twin-ai' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Mỗi vai trò quyết định context nào được gửi cho LLM. Các role builtin (cskh, admin, user) không thể xoá.', 'bizcity-twin-ai' ); ?></p>

		<div class="bizchat-roles-grid">
		<?php foreach ( $definitions as $slug => $def ) :
			$is_builtin = ! empty( $def['builtin'] );
			$overrides  = $def['focus_override'] ?? [];
		?>
			<div class="bizchat-role-card <?php echo $is_builtin ? 'is-builtin' : ''; ?>" data-slug="<?php echo esc_attr( $slug ); ?>">
				<div class="bizchat-role-header">
					<strong><?php echo esc_html( $def['label'] ?? $slug ); ?></strong>
					<?php if ( $is_builtin ) : ?>
						<span class="bizchat-gw-badge gw-adapter">Builtin</span>
					<?php endif; ?>
					<code class="bizchat-role-slug"><?php echo esc_html( $slug ); ?></code>
				</div>
				<div class="bizchat-role-meta">
					<span>KCI: <strong><?php echo esc_html( $def['kci_ratio'] ?? '—' ); ?><?php echo ! empty( $def['kci_locked'] ) ? ' 🔒' : ''; ?></strong></span>
					<span>Max tokens: <strong><?php echo esc_html( $def['max_tokens'] ?? '—' ); ?></strong></span>
					<span>Tools: <strong><?php echo esc_html( $def['tools_enabled'] ?? '—' ); ?></strong></span>
				</div>
				<table class="bizchat-role-layers widefat">
					<thead>
						<tr>
							<th style="width:40%"><?php esc_html_e( 'Context Layer', 'bizcity-twin-ai' ); ?></th>
							<th><?php esc_html_e( 'Giá trị', 'bizcity-twin-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $layers as $lk => $lmeta ) :
						$current = $overrides[ $lk ] ?? null;
					?>
						<tr>
							<td>
								<label for="role_<?php echo esc_attr( $slug . '_' . $lk ); ?>">
									<?php echo esc_html( $lmeta['label'] ); ?>
								</label>
							</td>
							<td>
												<select name="role[<?php echo esc_attr( $slug ); ?>][focus_override][<?php echo esc_attr( $lk ); ?>]"
										id="role_<?php echo esc_attr( $slug . '_' . $lk ); ?>"
										class="bizchat-role-select">
									<option value=""><?php esc_html_e( '— mặc định —', 'bizcity-twin-ai' ); ?></option>
									<?php foreach ( $lmeta['values'] as $v ) :
										$val_str = self::layer_value_to_string( $v );
										$label   = self::layer_value_label( $v );
										$sel     = ( $current !== null && self::layer_value_to_string( $current ) === $val_str ) ? 'selected' : '';
									?>
										<option value="<?php echo esc_attr( $val_str ); ?>" <?php echo $sel; ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
		</div>

		<!-- ── Section 2: Channel→Role assignments ── -->
		<h2 style="margin-top: 30px;"><?php esc_html_e( 'Gán vai trò cho kênh / bot', 'bizcity-twin-ai' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Chọn role mặc định cho từng kênh hoặc bot instance. Nếu không gán, hệ thống dùng role mặc định của platform.', 'bizcity-twin-ai' ); ?></p>

		<table class="widefat striped bizchat-gw-table bizchat-assign-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Kênh / Bot', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Platform', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Role', 'bizcity-twin-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $bots as $bot ) :
				$assigned = $assignments[ $bot['key'] ] ?? '';
			?>
				<tr>
					<td>
						<?php echo esc_html( $bot['label'] ); ?>
						<?php if ( ! empty( $bot['locked'] ) ) : ?>
							<span class="bizchat-gw-badge gw-off" style="margin-left:6px;">locked</span>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $bot['platform'] ); ?></code></td>
					<td>
						<?php if ( ! empty( $bot['locked'] ) ) : ?>
							<em><?php echo esc_html( BizCity_Channel_Role::PLATFORM_DEFAULTS[ $bot['platform'] ] ?? 'user' ); ?></em>
						<?php else : ?>
							<select name="assign[<?php echo esc_attr( $bot['key'] ); ?>]" class="bizchat-role-select">
								<option value=""><?php esc_html_e( '— platform default —', 'bizcity-twin-ai' ); ?></option>
								<?php foreach ( $definitions as $slug => $def ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $assigned, $slug ); ?>>
										<?php echo esc_html( $def['label'] ?? $slug ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="submit">
			<button type="button" id="bizchat-roles-save" class="button button-primary">
				<?php esc_html_e( 'Lưu cấu hình vai trò', 'bizcity-twin-ai' ); ?>
			</button>
			<span id="bizchat-roles-status" style="margin-left:12px;"></span>
		</p>

		</div><!-- #bizchat-roles-app -->

		<script>
		jQuery(function($){
			$('#bizchat-roles-save').on('click', function(){
				var $btn = $(this).prop('disabled', true);
				var $st  = $('#bizchat-roles-status').text('Đang lưu...');
				var data = {
					action: 'bizchat_save_channel_roles',
					_nonce: $('#bizchat-roles-app').data('nonce'),
					roles: {},
					assignments: {}
				};

				// Gather role focus overrides
				$('select[name^="role["]').each(function(){
							var m = this.name.match(/^role\[([^\]]+)\]\[focus_override\]\[([^\]]+)\]$/);
					if (!m) return;
					var slug = m[1], layer = m[2], val = $(this).val();
					if (!data.roles[slug]) data.roles[slug] = {};
					if (val !== '') data.roles[slug][layer] = val;
				});

				// Gather assignments
				$('select[name^="assign["]').each(function(){
					var m = this.name.match(/^assign\[([^\]]+)\]$/);
					if (!m) return;
					data.assignments[m[1]] = $(this).val();
				});

				$.post(ajaxurl, data, function(r){
					$btn.prop('disabled', false);
					if (r.success) {
						$st.html('<span style="color:green">✓ Đã lưu</span>');
					} else {
						$st.html('<span style="color:red">✗ ' + (r.data || 'Lỗi') + '</span>');
					}
				}).fail(function(){
					$btn.prop('disabled', false);
					$st.html('<span style="color:red">✗ Request failed</span>');
				});
			});
		});
		</script>
		<?php
	}

	/* ─────────────────── Helpers ─────────────────── */

	private static function layer_value_to_string( $v ): string {
		if ( $v === true )  return '__true';
		if ( $v === false ) return '__false';
		return (string) $v;
	}

	private static function layer_value_label( $v ): string {
		if ( $v === true )  return 'true (đầy đủ)';
		if ( $v === false ) return 'false (tắt)';
		return (string) $v;
	}

	private static function string_to_layer_value( string $s ) {
		if ( $s === '__true' )  return true;
		if ( $s === '__false' ) return false;
		if ( is_numeric( $s ) ) return (int) $s;
		return $s;
	}
}
