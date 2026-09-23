<?php
/**
 * Public Channel Gateway Bot Studio shell at /gateway/.
 *
 * The page reuses the Channel Gateway SPA bundle and Bot Studio REST owners.
 * It does not create a second settings owner or a second REST namespace.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Public_Gateway_Page', false ) ) {
	return;
}

final class BizCity_Public_Gateway_Page {

	const QUERY_VAR = 'bizcity_channel_gateway_public';
	const REWRITE_KEY = '^gateway/?$';
	const SCRIPT_HANDLE = 'bizcity-channel-gateway-public-app';

	private static $instance = null;
	private $registered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/** Register the public route and renderer exactly once. */
	public function register() {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		add_action( 'init', array( $this, 'add_rewrite_rule' ), 11 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	/** Register /gateway/; flushing is owned by BizCity_Rewrite_Flush_Registry. */
	public function add_rewrite_rule() {
		add_rewrite_rule(
			self::REWRITE_KEY,
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Render only the authenticated, authorized Bot Studio surface. */
	public function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/gateway/' ) ) );
			exit;
		}

		$can_manage = class_exists( 'BizCity_Network_Admin_Capability' )
			? BizCity_Network_Admin_Capability::can_manage()
			: current_user_can( 'manage_options' );
		if ( ! $can_manage ) {
			wp_die(
				esc_html__( 'Bạn không có quyền quản lý Bot Studio.', 'bizcity-twin-ai' ),
				esc_html__( 'Bot Studio', 'bizcity-twin-ai' ),
				array( 'response' => 403 )
			);
		}

		// [2026-09-23 07:00 PM GitHub Copilot] BOT-STUDIO-PUBLIC-ROUTE — keep the public shell isolated from the active theme/admin chrome.
		nocache_headers();
		add_filter( 'show_admin_bar', '__return_false', 100 );
		header( 'Content-Type: text/html; charset=utf-8' );
		$root = defined( 'BIZCITY_TWIN_AI_URL' )
			? trailingslashit( BIZCITY_TWIN_AI_URL ) . 'core/channel-gateway/assets/dist/'
			: plugins_url( '../assets/dist/', __FILE__ );
		$js_file = dirname( __DIR__ ) . '/assets/dist/channel-gateway-app.js';
		$css_file = dirname( __DIR__ ) . '/assets/dist/channel-gateway-app.css';
		$js_ver = is_readable( $js_file ) ? (string) filemtime( $js_file ) : '1';
		$css_ver = is_readable( $css_file ) ? (string) filemtime( $css_file ) : $js_ver;
		$boot = array(
			'restUrl' => '/wp-json/bizcity-channel/v1/',
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'adminUrl' => admin_url( 'admin.php?page=bizchat-gateway-spa' ),
			'siteUrl' => home_url( '/' ),
			'version' => defined( 'BIZCITY_TWIN_AI_VERSION' ) ? BIZCITY_TWIN_AI_VERSION : '1.0',
			'publicGateway' => true,
			'caps' => array( 'manage' => true, 'send' => true ),
			'i18n' => array( 'plugin_title' => __( 'BizChat Channels', 'bizcity-twin-ai' ) ),
			'platforms' => array(),
		);
		?>
<!doctype html>
<html <?php language_attributes(); ?>><head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e( 'Bot Studio — Channel Gateway', 'bizcity-twin-ai' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $root . 'channel-gateway-app.css?ver=' . rawurlencode( $css_ver ) ); ?>">
</head><body>
<div id="bizcity-channel-gateway-root" translate="no" style="min-height:100vh;"></div>
<script>window.BIZCITY_CG_BOOT=<?php echo wp_json_encode( $boot ); ?>;</script>
<script src="<?php echo esc_url( $root . 'channel-gateway-app.js?ver=' . rawurlencode( $js_ver ) ); ?>"></script>
</body></html>
<?php
		exit;
	}
}
