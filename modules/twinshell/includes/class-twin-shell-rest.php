<?php
/**
 * Twin Shell — REST endpoint exposing the plugin registry.
 *
 * GET /wp-json/bizcity-twinchat/v1/shell/plugins
 *   → { plugins: [ ... ], default: 'twinchat' }
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_REST {

	const NS = 'bizcity-twinchat/v1';

	private static $instance = null;
	private $registered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — idempotent register.
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( self::NS, '/shell/plugins', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_plugins' ],
			'permission_callback' => [ $this, 'permission_logged_in' ],
		] );

		register_rest_route( self::NS, '/shell/self', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_self_config' ],
			'permission_callback' => [ $this, 'permission_logged_in' ],
		] );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — explicit auth rejection
	 * so FE receives deterministic 401 code instead of generic false callback.
	 *
	 * @return true|WP_Error
	 */
	public function permission_logged_in() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error(
			'auth_required',
			'Vui lòng đăng nhập để dùng Twin Shell.',
			array( 'status' => 401 )
		);
	}

	public function list_plugins( $request ) {
		$registry = BizCity_Twin_Shell_Registry::instance();
		$plugins  = [];
		foreach ( $registry->all() as $p ) {
			if ( ! empty( $p['capability'] ) && ! current_user_can( $p['capability'] ) ) {
				continue;
			}
			$plugins[] = $p;
		}

		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — stable response shape
		// to keep FE parser resilient in fail-open mode.
		return new WP_REST_Response( array(
			'success' => true,
			'plugins' => $plugins,
			'default' => $registry->default_id(),
		), 200 );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — self-scoped shell config
	 * for FE bootstrap without exposing cross-user data.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_self_config( $request ) {
		$uid = (int) get_current_user_id();
		$u   = wp_get_current_user();

		return new WP_REST_Response( array(
			'success' => true,
			'user'    => array(
				'id'    => $uid,
				'name'  => $u ? (string) $u->display_name : '',
				'roles' => $u ? array_values( (array) $u->roles ) : array(),
			),
			'shell'   => array(
				'url'    => class_exists( 'BizCity_Twin_Shell_Page' ) ? esc_url_raw( BizCity_Twin_Shell_Page::shell_url() ) : home_url( '/twin/' ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'blogId' => (int) get_current_blog_id(),
			),
		), 200 );
	}
}
