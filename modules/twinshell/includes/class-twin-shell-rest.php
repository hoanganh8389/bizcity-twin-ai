<?php
/**
 * Twin Shell — REST endpoint exposing the plugin registry.
 *
 * GET /wp-json/bizcity-twinchat/v1/shell/plugins
	 *   → { plugins: [ ... ], default: 'crm' }
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

		register_rest_route( self::NS, '/shell/setting-panel', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_setting_panel' ],
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
	 * Return server-authorized Setting Panel metadata for the current operator.
	 *
	 * The registry is discovery-only: renderer loading, owner reads and
	 * mutations remain outside this endpoint.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function list_setting_panel( $request ) {
		// [2026-09-13 10:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — expose read-only authorized registry metadata through the existing TwinShell REST owner.
		$items = array();
		if ( class_exists( 'BizCity_Setting_Panel_Registry' ) ) {
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — resolve label/url/availability server-side so the FE never derives a label or builds a link.
			$resolved = method_exists( 'BizCity_Setting_Panel_Registry', 'resolved_all' )
				? BizCity_Setting_Panel_Registry::resolved_all()
				: BizCity_Setting_Panel_Registry::all();
			foreach ( $resolved as $item ) {
				if ( ! empty( $item['capability'] ) && ! current_user_can( (string) $item['capability'] ) ) {
					continue;
				}
				if ( 'network' === (string) ( $item['scope'] ?? '' ) && ! is_network_admin() ) {
					continue;
				}
				$items[] = $item;
			}
		}

		return new WP_REST_Response(
			array(
				'success'      => true,
				'contract'     => 'setting-panel-registration',
				'version'      => '1.0.0',
				'items'        => $items,
				'destinations' => array( 'workspace', 'settings', 'control-panel', 'channel-settings', 'crm-inbox', 'plugins-store' ),
			),
			200
		);
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
