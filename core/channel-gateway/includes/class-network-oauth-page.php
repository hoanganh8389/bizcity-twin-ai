<?php
/**
 * Network OAuth Page (PHASE 0.31 T-S4.3)
 *
 * Single network-admin page for site-wide OAuth credentials shared across all
 * blogs in the multisite — Facebook App ID/Secret, Google Client ID/Secret,
 * Zalo OA App ID/Secret. Per-blog plugins read these via the static helpers
 * {@see BizCity_Network_OAuth_Page::get_global()} which falls back to the
 * legacy per-blog option when the network value is empty.
 *
 * Storage: `wp_sitemeta` (multisite) via `update_site_option()` / `get_site_option()`.
 * On a single-site install the same calls degrade to `update_option()`.
 *
 * Why mu-plugin entry point? Network admin menus (`network_admin_menu`) only
 * fire when at least one mu-plugin or auto-loaded plugin registers them; we
 * keep the heavy class here in the channel-gateway and let
 * `mu-plugins/bizcity-channel-network-oauth.php` instantiate it.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.4.0 (Sprint 6 P1)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Network_OAuth_Page {

	const MENU_SLUG    = 'bizcity-network-oauth';
	const NONCE_ACTION = 'bizcity_network_oauth_save';
	const CAPABILITY   = 'manage_network_options';

	/** Site-option keys. Per-blog plugins MUST read via get_global(). */
	const KEYS = array(
		'fb_app_id'             => 'bizcity_oauth_fb_app_id',
		'fb_app_secret'         => 'bizcity_oauth_fb_app_secret',
		'google_client_id'      => 'bizcity_oauth_google_client_id',
		'google_client_secret'  => 'bizcity_oauth_google_client_secret',
		'zalo_oa_app_id'        => 'bizcity_oauth_zalo_oa_app_id',
		'zalo_oa_app_secret'    => 'bizcity_oauth_zalo_oa_app_secret',
	);

	/** Encrypted (secret) keys — stored base64(openssl_encrypt). */
	const ENCRYPTED = array( 'fb_app_secret', 'google_client_secret', 'zalo_oa_app_secret' );

	/** @var self|null */
	private static $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		// Register the menu in network admin (multisite); single-site degrades to settings page.
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'add_menu' ) );
			add_action( 'admin_post_bizcity_network_oauth_save', array( $this, 'handle_save' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'add_menu_singlesite' ) );
			add_action( 'admin_post_bizcity_network_oauth_save', array( $this, 'handle_save' ) );
		}
	}

	public function add_menu(): void {
		add_submenu_page(
			'settings.php', // Network admin "Settings" parent
			__( 'BizCity OAuth Globals', 'bizcity' ),
			__( 'BizCity OAuth', 'bizcity' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function add_menu_singlesite(): void {
		add_options_page(
			__( 'BizCity OAuth Globals', 'bizcity' ),
			__( 'BizCity OAuth', 'bizcity' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/* ─── Public read API used by per-blog plugins ─── */

	/**
	 * Read a sitewide OAuth credential. Falls back to per-blog `get_option()`
	 * with the same key for legacy compatibility, then to $default.
	 *
	 * @param string $logical_key e.g. 'fb_app_id', 'google_client_secret'.
	 * @param string $default
	 * @return string Decrypted value (for ENCRYPTED keys) or raw value.
	 */
	public static function get_global( string $logical_key, string $default = '' ): string {
		if ( ! isset( self::KEYS[ $logical_key ] ) ) {
			return $default;
		}
		$opt = self::KEYS[ $logical_key ];
		$val = (string) get_site_option( $opt, '' );
		if ( $val === '' ) {
			$val = (string) get_option( $opt, '' );
		}
		if ( $val === '' ) {
			return $default;
		}
		if ( in_array( $logical_key, self::ENCRYPTED, true ) ) {
			$dec = self::decrypt( $val );
			return $dec === false ? $default : $dec;
		}
		return $val;
	}

	/**
	 * Bulk-read all globals (decrypted). Use sparingly — only for admin contexts.
	 *
	 * @return array<string,string>
	 */
	public static function get_all_globals(): array {
		$out = array();
		foreach ( self::KEYS as $logical => $opt ) {
			$out[ $logical ] = self::get_global( $logical );
		}
		return $out;
	}

	/* ─── Save handler ─── */

	public function handle_save(): void {
		if ( ! current_user_can( is_multisite() ? self::CAPABILITY : 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		foreach ( self::KEYS as $logical => $opt ) {
			$raw = isset( $_POST[ $logical ] ) ? wp_unslash( (string) $_POST[ $logical ] ) : '';
			$raw = trim( $raw );

			// Skip placeholder for encrypted keys when user did not change it.
			if ( in_array( $logical, self::ENCRYPTED, true ) && $raw === '********' ) {
				continue;
			}
			$store = $raw;
			if ( $raw !== '' && in_array( $logical, self::ENCRYPTED, true ) ) {
				$store = self::encrypt( $raw );
			}
			if ( is_multisite() ) {
				update_site_option( $opt, $store );
			} else {
				update_option( $opt, $store );
			}
		}

		do_action( 'bizcity_network_oauth_saved', self::get_all_globals() );

		$redirect = add_query_arg(
			array( 'page' => self::MENU_SLUG, 'updated' => 1 ),
			is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/* ─── UI ─── */

	public function render(): void {
		$cap = is_multisite() ? self::CAPABILITY : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			return;
		}
		$action_url = admin_url( 'admin-post.php' );
		$globals    = self::get_all_globals();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BizCity — Network OAuth Globals', 'bizcity' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Centralized OAuth App credentials shared across every blog. Per-blog plugins (Facebook Bot, Google Tools, Zalo Hotline) fall back to these values when their local setting is empty.', 'bizcity' ); ?>
			</p>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'bizcity' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="bizcity_network_oauth_save">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( self::KEYS as $logical => $opt ) :
						$is_secret = in_array( $logical, self::ENCRYPTED, true );
						$value     = $globals[ $logical ] ?? '';
						$display   = $is_secret && $value !== '' ? '********' : $value;
						?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( 'bnoa_' . $logical ); ?>">
									<?php echo esc_html( str_replace( '_', ' ', ucwords( $logical, '_' ) ) ); ?>
								</label>
							</th>
							<td>
								<input
									type="<?php echo $is_secret ? 'password' : 'text'; ?>"
									id="<?php echo esc_attr( 'bnoa_' . $logical ); ?>"
									name="<?php echo esc_attr( $logical ); ?>"
									value="<?php echo esc_attr( $display ); ?>"
									class="regular-text"
									autocomplete="off">
								<p class="description"><code><?php echo esc_html( $opt ); ?></code></p>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save OAuth Globals', 'bizcity' ) ); ?>
			</form>
		</div>
		<?php
	}

	/* ─── Encryption (uses WP salts; fallback to LOGGED_IN_KEY) ─── */

	private static function key(): string {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bizcity_oauth_default';
		return substr( hash( 'sha256', 'bizcity-net-oauth|' . $salt, true ), 0, 16 );
	}
	private static function iv(): string {
		$salt = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : 'bizcity_iv_default';
		return substr( hash( 'sha256', 'bizcity-net-oauth-iv|' . $salt, true ), 0, 16 );
	}
	private static function encrypt( string $plain ): string {
		$enc = openssl_encrypt( $plain, 'AES-128-CBC', self::key(), 0, self::iv() );
		return $enc === false ? '' : base64_encode( $enc );
	}
	private static function decrypt( string $cipher ) {
		$raw = base64_decode( $cipher, true );
		if ( $raw === false ) { return false; }
		return openssl_decrypt( $raw, 'AES-128-CBC', self::key(), 0, self::iv() );
	}
}
