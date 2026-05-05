<?php
/**
 * Twin Shell — Learning Hub REST proxy (Wave D).
 *
 * Namespace: bizcity-twin-shell/v1
 *
 * Endpoints:
 *   GET  /learning/cortexes
 *     → list visible cortex IDs + labels for the current user.
 *
 *   GET  /learning/aggregate?cortex=*&scope=user|site
 *     → call each visible cortex's aggregator->summary() in turn and merge.
 *       Default cortex=* (all visible). Pass cortex=twinchat,bzdoc to filter.
 *
 *   GET  /learning/aggregate/analytics?cortex=*&range=24h|7d|30d&scope=…
 *     → merged analytics. Cortex without an `analytics` callable is skipped.
 *
 *   GET  /learning/stream?aggregate=1[&notebook_id=]
 *     → SSE multi-cortex merge. v1 forwards to the twinchat stream when only
 *       the twinchat cortex is present. True merge of multi-cortex SSE is
 *       deferred (single-thread PHP can only poll one upstream at a time).
 *
 * Capability: every route requires logged-in user. Per-cortex capability is
 * enforced by the SDK's `cortexes()` accessor.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell\Learning
 * @since 0.13.38
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_Learning_REST {

	const NS = 'bizcity-twin-shell/v1';

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
		$logged_in = static function () {
			return is_user_logged_in();
		};

		register_rest_route( self::NS, '/learning/cortexes', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_cortexes' ],
			'permission_callback' => $logged_in,
		] );

		register_rest_route( self::NS, '/learning/aggregate', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'aggregate_summary' ],
			'permission_callback' => $logged_in,
			'args'                => [
				'cortex' => [ 'type' => 'string', 'required' => false, 'default' => '*' ],
				'scope'  => [ 'type' => 'string', 'required' => false, 'default' => 'user', 'enum' => [ 'user', 'site' ] ],
			],
		] );

		register_rest_route( self::NS, '/learning/aggregate/analytics', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'aggregate_analytics' ],
			'permission_callback' => $logged_in,
			'args'                => [
				'cortex' => [ 'type' => 'string', 'required' => false, 'default' => '*' ],
				'range'  => [ 'type' => 'string', 'required' => false, 'default' => '24h', 'enum' => [ '24h', '7d', '30d' ] ],
				'scope'  => [ 'type' => 'string', 'required' => false, 'default' => 'user', 'enum' => [ 'user', 'site' ] ],
			],
		] );

		register_rest_route( self::NS, '/learning/stream', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'aggregate_stream' ],
			'permission_callback' => $logged_in,
			'args'                => [
				'aggregate'   => [ 'type' => 'integer', 'required' => false, 'default' => 1 ],
				'notebook_id' => [ 'type' => 'integer', 'required' => false ],
			],
		] );
	}

	// ── Routes ──────────────────────────────────────────────────────────

	public function list_cortexes( WP_REST_Request $req ) {
		unset( $req );
		$uid = get_current_user_id();
		$out = [];
		foreach ( BizCity_Twin_Shell_Learning_SDK::instance()->cortexes( $uid ) as $id => $c ) {
			$out[] = [
				'id'             => $id,
				'label'          => $c['label'],
				'has_analytics'  => ! empty( $c['analytics'] ),
			];
		}
		return new WP_REST_Response( [ 'cortexes' => $out ], 200 );
	}

	public function aggregate_summary( WP_REST_Request $req ) {
		$uid    = get_current_user_id();
		$site   = $this->resolve_site_scope( $req );
		$picked = $this->resolve_cortex_filter( $req->get_param( 'cortex' ) );

		$cortexes = BizCity_Twin_Shell_Learning_SDK::instance()->cortexes( $uid );
		$results  = [];
		$errors   = [];
		$started  = microtime( true );

		foreach ( $cortexes as $id => $c ) {
			if ( null !== $picked && ! in_array( $id, $picked, true ) ) {
				continue;
			}
			try {
				$results[ $id ] = call_user_func( $c['aggregator'], $uid, $site );
			} catch ( \Throwable $e ) {
				$errors[ $id ] = $e->getMessage();
			}
		}

		return new WP_REST_Response( [
			'scope'    => $site ? 'site' : 'user',
			'cortexes' => $results,
			'errors'   => $errors,
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'generated'   => time(),
		], 200 );
	}

	public function aggregate_analytics( WP_REST_Request $req ) {
		$uid    = get_current_user_id();
		$site   = $this->resolve_site_scope( $req );
		$range  = (string) $req->get_param( 'range' );
		$picked = $this->resolve_cortex_filter( $req->get_param( 'cortex' ) );

		$cortexes = BizCity_Twin_Shell_Learning_SDK::instance()->cortexes( $uid );
		$results  = [];
		$errors   = [];
		$started  = microtime( true );

		foreach ( $cortexes as $id => $c ) {
			if ( null !== $picked && ! in_array( $id, $picked, true ) ) {
				continue;
			}
			if ( empty( $c['analytics'] ) ) {
				continue;
			}
			try {
				$results[ $id ] = call_user_func( $c['analytics'], $uid, $range, $site );
			} catch ( \Throwable $e ) {
				$errors[ $id ] = $e->getMessage();
			}
		}

		return new WP_REST_Response( [
			'scope'    => $site ? 'site' : 'user',
			'range'    => $range,
			'cortexes' => $results,
			'errors'   => $errors,
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'generated'   => time(),
		], 200 );
	}

	/**
	 * SSE forwarder.
	 *
	 * v1 limitation: PHP single-thread can only long-poll one upstream at a
	 * time, so when ?aggregate=1 we forward to the twinchat stream if it is
	 * the only registered cortex with SSE. Future cortex contributing SSE
	 * MUST go through a multiplexed upstream (Redis pub/sub, websocket, etc.).
	 */
	public function aggregate_stream( WP_REST_Request $req ) {
		$nb = (int) $req->get_param( 'notebook_id' );
		if ( $nb <= 0 ) {
			return new WP_Error(
				'aggregate_stream_unsupported',
				'Aggregate SSE without notebook_id is not yet implemented; pass ?notebook_id= to forward to the cortex stream.',
				[ 'status' => 501 ]
			);
		}
		if ( ! class_exists( 'BizCity_TwinChat_Learning_Stream' ) ) {
			return new WP_Error( 'no_stream_handler', 'No SSE handler available', [ 'status' => 503 ] );
		}
		// Forward to twinchat handler — same SSE contract.
		return BizCity_TwinChat_Learning_Stream::instance()->handle( $req );
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	private function resolve_site_scope( WP_REST_Request $req ) {
		$scope = (string) $req->get_param( 'scope' );
		if ( 'site' !== $scope ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Parse `cortex=*` (all) or `cortex=a,b,c` (filtered list).
	 *
	 * @param mixed $raw
	 * @return array<int,string>|null  null = all
	 */
	private function resolve_cortex_filter( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw || '*' === $raw ) {
			return null;
		}
		$ids = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) ) );
		return array_values( array_unique( $ids ) );
	}
}
