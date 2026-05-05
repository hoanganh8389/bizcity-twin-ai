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

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( self::NS, '/shell/plugins', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_plugins' ],
			'permission_callback' => static function () {
				return is_user_logged_in();
			},
		] );
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
		return new WP_REST_Response( [
			'plugins' => $plugins,
			'default' => $registry->default_id(),
		], 200 );
	}
}
