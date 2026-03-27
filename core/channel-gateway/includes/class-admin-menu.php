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

		// Legacy channels that are always available.
		$legacy_channels = [
			'zalo'      => [ 'label' => 'Zalo Personal',     'icon' => '💬', 'status' => function_exists( 'send_zalo_botbanhang' ) || function_exists( 'biz_send_message' ) ],
			'zalo_bot'  => [ 'label' => 'Zalo Bot OA',       'icon' => '🤖', 'status' => class_exists( 'BizCity_Zalo_Bot_Database' ) ],
			'webchat'   => [ 'label' => 'WebChat',           'icon' => '🌐', 'status' => class_exists( 'BizCity_WebChat_Trigger' ) || class_exists( 'BizCity_WebChat_Database' ) ],
			'adminchat' => [ 'label' => 'Admin Chat',        'icon' => '👤', 'status' => class_exists( 'BizCity_WebChat_Database' ) ],
			'facebook'  => [ 'label' => 'Facebook Messenger', 'icon' => '📘', 'status' => function_exists( 'fbm_send_text_to_user' ) ],
			'telegram'  => [ 'label' => 'Telegram',          'icon' => '✈️',  'status' => function_exists( 'twf_telegram_send_message' ) ],
		];

		?>
		<div class="wrap bizchat-gw-wrap">
			<h1>⚡ <?php esc_html_e( 'Channel Gateway', 'bizcity-twin-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Unified multi-channel messaging hub. Manage all inbound and outbound connections from one place.', 'bizcity-twin-ai' ); ?>
			</p>

			<?php if ( ! empty( $adapters ) ) : ?>
			<h2><?php esc_html_e( 'Registered Adapters', 'bizcity-twin-ai' ); ?></h2>
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
			<?php endif; ?>

			<h2><?php esc_html_e( 'Channel Connections', 'bizcity-twin-ai' ); ?></h2>
			<div class="bizchat-gw-grid">
				<?php foreach ( $legacy_channels as $key => $ch ) :
					$active = $ch['status'];
					// Check if this channel has a registered adapter (upgraded)
					$has_adapter = isset( $adapters[ strtoupper( $key ) ] );
				?>
				<div class="bizchat-gw-card <?php echo $active ? 'is-active' : 'is-inactive'; ?>">
					<div class="bizchat-gw-card-icon"><?php echo esc_html( $ch['icon'] ); ?></div>
					<div class="bizchat-gw-card-body">
						<strong><?php echo esc_html( $ch['label'] ); ?></strong>
						<?php if ( $has_adapter ) : ?>
							<span class="bizchat-gw-badge gw-adapter">Adapter</span>
						<?php endif; ?>
						<span class="bizchat-gw-badge <?php echo $active ? 'gw-on' : 'gw-off'; ?>">
							<?php echo $active ? '● ' . esc_html__( 'Connected', 'bizcity-twin-ai' ) : '○ ' . esc_html__( 'Not available', 'bizcity-twin-ai' ); ?>
						</span>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Quick Test', 'bizcity-twin-ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Send a test message to any channel via the unified Gateway.', 'bizcity-twin-ai' ); ?></p>
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
}
