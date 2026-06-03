<?php
/**
 * BizCity Channel REST API — Unified Channel Management Endpoints (PHASE 0.37)
 *
 * Provides a secure REST surface for:
 *   - Managing channel accounts (registry CRUD)
 *   - Health monitoring
 *   - Unified outbound dispatch (R-CH-5)
 *   - Connection testing
 *
 * All routes require `manage_options` unless noted.
 *
 * Namespace: bizcity-channel/v1
 *
 * Routes:
 *   GET    /registry                        List all channel accounts (credentials masked)
 *   POST   /registry                        Save/update one channel account
 *   DELETE /registry/(?P<uid>[w-]+)         Delete account by uid
 *   POST   /test/(?P<uid>[w-]+)             Test connection for account uid
 *   GET    /health                           All adapter health
 *   POST   /send                             Unified outbound (R-CH-5 external surface)
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 * @see        PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL.md
 * @see        PHASE-0-RULE-CHANNEL-ONLY.md R-CH-5
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Channel_REST_API {

	private const NS = 'bizcity-channel/v1';

	/**
	 * Register REST routes. Called via add_action('rest_api_init').
	 */
	public static function init(): void {
		$instance = new self();

		register_rest_route( self::NS, '/registry', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $instance, 'list_registry' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $instance, 'save_account' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
				'args'                => [
					'code'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
					'account' => [ 'required' => true, 'type' => 'object' ],
				],
			],
		] );

		register_rest_route( self::NS, '/registry/(?P<uid>[a-zA-Z0-9_-]+)', [
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $instance, 'delete_account' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
			],
		] );

		// T-P0.37.4.1.1 — Unified inbound webhook (GET=verify, POST=event).
		// Permission: __return_true (public, verified by adapter::verify_webhook()).
		register_rest_route( self::NS, '/webhook/(?P<platform>[a-z0-9_-]+)/(?P<instance_id>[a-z0-9_-]+)', [
			[
				'methods'             => 'GET, POST',
				'callback'            => [ $instance, 'handle_webhook' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( self::NS, '/test/(?P<uid>[a-zA-Z0-9_-]+)', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $instance, 'test_account' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
				'args'                => [
					'code' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
				],
			],
		] );

		register_rest_route( self::NS, '/health', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $instance, 'get_health' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
			],
		] );

		// T-CG-SPA.2 — Webhook logs for per-platform workspace SPA.
		// See: core/channel-gateway/PHASE-CG-SPA-WORKSPACE.md §3
		register_rest_route( self::NS, '/logs', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $instance, 'list_logs' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
				'args'                => [
					'platform'      => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'instance_id'   => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'days'          => [ 'default' => 3,  'sanitize_callback' => 'absint' ],
					'limit'         => [ 'default' => 100,'sanitize_callback' => 'absint' ],
					'verify_status' => [ 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
					'http_min'      => [ 'default' => 0,  'sanitize_callback' => 'absint' ],
					'http_max'      => [ 'default' => 0,  'sanitize_callback' => 'absint' ],
				],
			],
		] );

		register_rest_route( self::NS, '/send', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $instance, 'send_message' ],
				'permission_callback' => [ $instance, 'require_send_permission' ],
				'args'                => [
					'platform'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'instance_id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'recipient'   => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'message'     => [ 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
					'type'        => [ 'default' => 'text', 'sanitize_callback' => 'sanitize_key' ],
					'meta'        => [ 'default' => [], 'type' => 'object' ],
				],
			],
		] );
	}

	/* ═══════════════════════════════════════════
	 *  Permission callbacks
	 * ═══════════════════════════════════════════ */

	public function require_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	public function require_send_permission(): bool {
		// R-CH-5: outbound requires manage_options OR the custom capability.
		return current_user_can( 'manage_options' ) || current_user_can( 'bizcity_channel_send' );
	}

	/* ═══════════════════════════════════════════
	 *  GET /registry — list channel accounts
	 * ═══════════════════════════════════════════ */

	public function list_registry( WP_REST_Request $request ): WP_REST_Response {
		$registry = BizCity_Integration_Registry::instance();
		$code_filter = sanitize_key( $request->get_param( 'code' ) ?? '' );

		$channels = [];
		foreach ( $registry->get_all() as $code => $integ ) {
			if ( ! ( $integ instanceof BizCity_Channel_Integration ) ) {
				continue;
			}
			if ( $code_filter && $code !== $code_filter ) {
				continue;
			}

			$accounts = $registry->get_accounts( $code );
			$masked   = array_map( fn( $acc ) => $this->mask_credentials( $acc, $integ ), $accounts );

			$channels[ $code ] = array_merge( $integ->to_admin_array(), [
				'accounts' => $masked,
			] );
		}

		return new WP_REST_Response( [
			'success'  => true,
			'channels' => $channels,
		], 200 );
	}

	/* ═══════════════════════════════════════════
	 *  POST /registry — save/update account
	 * ═══════════════════════════════════════════ */

	public function save_account( WP_REST_Request $request ): WP_REST_Response {
		$code         = $request->get_param( 'code' );
		$account_data = (array) $request->get_param( 'account' );

		// Security: strip PHP object/function payloads from account_data.
		$account_data = $this->sanitize_account_data( $account_data );

		$registry = BizCity_Integration_Registry::instance();
		$result   = $registry->save_channel_account( $code, $account_data );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => $result->get_error_message(),
			], 400 );
		}

		return new WP_REST_Response( [
			'success' => true,
			'account' => $result,
		], 200 );
	}

	/* ═══════════════════════════════════════════
	 *  DELETE /registry/{uid} — remove account
	 * ═══════════════════════════════════════════ */

	public function delete_account( WP_REST_Request $request ): WP_REST_Response {
		$uid  = sanitize_key( $request->get_param( 'uid' ) );
		$code = sanitize_key( $request->get_param( 'code' ) ?? '' );

		if ( ! $code ) {
			// Try to resolve code from uid: uid format is "{code}_{hash}"
			[ $code ] = explode( '_', $uid, 2 ) + [ '', '' ];
		}

		$registry = BizCity_Integration_Registry::instance();
		$deleted  = $registry->delete_channel_account( $code, $uid );

		return new WP_REST_Response( [
			'success' => $deleted,
		], $deleted ? 200 : 404 );
	}

	/* ═══════════════════════════════════════════
	 *  POST /test/{uid} — test connection
	 * ═══════════════════════════════════════════ */

	public function test_account( WP_REST_Request $request ): WP_REST_Response {
		$uid  = sanitize_key( $request->get_param( 'uid' ) );
		$code = sanitize_key( $request->get_param( 'code' ) );

		$registry = BizCity_Integration_Registry::instance();
		$integ    = $registry->get( $code );

		if ( ! $integ ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => "Integration '{$code}' not found." ], 404 );
		}

		$accounts = $registry->get_accounts( $code );
		$target   = null;
		foreach ( $accounts as $acc ) {
			if ( ( $acc['_uid'] ?? '' ) === $uid ) {
				$target = $acc;
				break;
			}
		}

		if ( $target === null ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => "Account '{$uid}' not found." ], 404 );
		}

		$clone = clone $integ;
		$clone->set_account( $target );
		$clone->do_test();
		$result_account = $clone->get_decrypted_params( false );

		// Persist test result back to storage.
		$registry->update_channel_account_status( $code, $uid, $clone->get_encrypted_params() );

		return new WP_REST_Response( [
			'success' => true,
			'status'  => (int) ( $result_account['_status'] ?? 0 ),
			'error'   => (string) ( $result_account['_status_error'] ?? '' ),
		], 200 );
	}

	/* ═══════════════════════════════════════════
	 *  GET /health — all adapter health
	 * ═══════════════════════════════════════════ */

	public function get_health( WP_REST_Request $request ): WP_REST_Response {
		$health = [];

		// From adapter bridge.
		if ( class_exists( 'BizCity_Gateway_Bridge' ) ) {
			foreach ( BizCity_Gateway_Bridge::instance()->get_all_adapters() as $adapter ) {
				$platform = is_object( $adapter ) && method_exists( $adapter, 'get_platform' )
					? $adapter->get_platform()
					: get_class( $adapter );

				$h = is_object( $adapter ) && method_exists( $adapter, 'health' )
					? $adapter->health()
					: [ 'ok' => null, 'note' => 'health() not implemented' ];

				$health[ $platform ] = $h;
			}
		}

		// From channel integrations.
		$registry = BizCity_Integration_Registry::instance();
		foreach ( $registry->get_all() as $code => $integ ) {
			if ( ( $integ instanceof BizCity_Channel_Integration ) ) {
				$h = $integ->health();
				$platform_key = strtolower( $integ->inbound_platform() ) . ':' . $code;
				if ( ! isset( $health[ $platform_key ] ) ) {
					$health[ $platform_key ] = $h;
				}
			}
		}

		$ok_count   = count( array_filter( $health, fn( $h ) => $h['ok'] === true ) );
		$fail_count = count( array_filter( $health, fn( $h ) => $h['ok'] === false ) );

		return new WP_REST_Response( [
			'success'    => true,
			'summary'    => [ 'ok' => $ok_count, 'fail' => $fail_count, 'unknown' => count( $health ) - $ok_count - $fail_count ],
			'adapters'   => $health,
		], 200 );
	}

	/* ═══════════════════════════════════════════
	 *  GET /logs — webhook logs (T-CG-SPA.3)
	 *  See: core/channel-gateway/PHASE-CG-SPA-WORKSPACE.md §3
	 * ═══════════════════════════════════════════ */

	public function list_logs( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Webhook_Log' ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => 'BizCity_Webhook_Log class not loaded',
				'logs'    => [],
				'count'   => 0,
			], 200 );
		}

		$platform    = (string) $request->get_param( 'platform' );
		$instance_id = (string) $request->get_param( 'instance_id' );
		$days        = (int) $request->get_param( 'days' );
		$limit       = (int) $request->get_param( 'limit' );
		$verify      = (string) $request->get_param( 'verify_status' );
		$http_min    = (int) $request->get_param( 'http_min' );
		$http_max    = (int) $request->get_param( 'http_max' );

		$filters = [
			'days'  => $days > 0 ? min( 7, $days ) : 3,
			'limit' => $limit > 0 ? min( 200, $limit ) : 100,
		];
		if ( $platform !== '' ) {
			$filters['platform'] = strtoupper( $platform );
		}
		if ( $verify !== '' ) {
			$filters['verify_status'] = $verify;
		}
		if ( $http_min > 0 ) {
			$filters['http_min'] = $http_min;
		}
		if ( $http_max > 0 ) {
			$filters['http_max'] = $http_max;
		}

		$logs = BizCity_Webhook_Log::query( $filters );

		// Optional client-side instance_id filter (endpoint contains /{platform}/{instance_id}).
		if ( $instance_id !== '' ) {
			$needle = '/' . $instance_id;
			$logs = array_values( array_filter( $logs, function ( $row ) use ( $needle ) {
				return is_string( $row['endpoint'] ?? null ) && strpos( $row['endpoint'], $needle ) !== false;
			} ) );
		}

		return new WP_REST_Response( [
			'success' => true,
			'filters' => $filters + [ 'instance_id' => $instance_id ],
			'count'   => count( $logs ),
			'logs'    => $logs,
		], 200 );
	}

	/* ═══════════════════════════════════════════
	 *  POST /send — unified outbound (R-CH-5)
	 * ═══════════════════════════════════════════ */

	public function send_message( WP_REST_Request $request ): WP_REST_Response {
		$envelope = [
			'platform'    => strtoupper( $request->get_param( 'platform' ) ),
			'instance_id' => $request->get_param( 'instance_id' ),
			'recipient'   => $request->get_param( 'recipient' ),
			'message'     => $request->get_param( 'message' ),
			'type'        => $request->get_param( 'type' ) ?: 'text',
			'meta'        => (array) ( $request->get_param( 'meta' ) ?: [] ),
		];

		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'Gateway Sender not available.' ], 503 );
		}

		$result = BizCity_Gateway_Sender::instance()->send_envelope( $envelope );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => $result->get_error_message(),
			], 400 );
		}

		return new WP_REST_Response( array_merge( [ 'success' => true ], $result ), 200 );
	}

	/* ═══════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════ */

	/**
	 * Mask credential fields in an account array for API response.
	 */
	private function mask_credentials( array $account, BizCity_Integration $integ ): array {
		$schema  = $integ->get_settings();
		$private = property_exists( $integ, 'private_params' )
			? ( ( fn() => $this->private_params ))->call( $integ )
			: [];

		$out = [];
		foreach ( $account as $key => $val ) {
			// Strip internal leading-underscore fields except _uid, _status, _status_error, _last_success_at.
			if ( str_starts_with( $key, '_' ) && ! in_array( $key, [ '_uid', '_status', '_status_error', '_last_success_at', '_token_expires_at' ], true ) ) {
				continue;
			}
			// Mask private encrypted fields.
			if ( in_array( $key, $private, true ) ) {
				$out[ $key ] = $val !== '' ? '••••••' : '';
				continue;
			}
			// Mask fields flagged as encrypt in schema.
			$field_schema = $schema[ $key ] ?? null;
			if ( $field_schema && ! empty( $field_schema['encrypt'] ) && $val !== '' ) {
				$out[ $key ] = '••••••';
				continue;
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/**
	 * Sanitize user-supplied account data to prevent injection.
	 */
	private function sanitize_account_data( array $data ): array {
		$out = [];
		foreach ( $data as $key => $val ) {
			$safe_key = sanitize_key( $key );
			if ( is_array( $val ) ) {
				$out[ $safe_key ] = array_map( 'sanitize_text_field', $val );
			} elseif ( is_string( $val ) ) {
				// Allow newlines for things like private keys, but strip control chars.
				$out[ $safe_key ] = wp_strip_all_tags( $val );
			} elseif ( is_bool( $val ) || is_int( $val ) || is_float( $val ) ) {
				$out[ $safe_key ] = $val;
			}
			// Skip objects/resources — discard.
		}
		return $out;
	}

	/* ═══════════════════════════════════════════
	 *  GET|POST /webhook/{platform}/{instance_id}
	 *  T-P0.37.4.1.1 — Unified inbound webhook
	 * ═══════════════════════════════════════════ */

	/**
	 * Handle an inbound webhook from any channel.
	 *
	 * GET  = platform challenge/verification (Zalo, Facebook verify token)
	 * POST = real event payload
	 *
	 * Verification is delegated to the registered adapter/channel via
	 * BizCity_Gateway_Bridge::instance().
	 * Normalization + routing is handled by BizCity_Universal_Channel_Listener.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|mixed
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$platform    = strtoupper( sanitize_key( $request->get_param( 'platform' ) ) );
		$instance_id = sanitize_text_field( $request->get_param( 'instance_id' ) );

		if ( ! $platform ) {
			return new WP_REST_Response( [ 'error' => 'Missing platform' ], 400 );
		}

		$bridge  = class_exists( 'BizCity_Gateway_Bridge' ) ? BizCity_Gateway_Bridge::instance() : null;
		$adapter = $bridge ? $bridge->get_adapter( $platform ) : null;

		// --- GET: challenge / verification ---
		if ( $request->get_method() === 'GET' ) {
			// Generic challenge echo (Zalo, FB use ?hub.challenge or ?verifyToken).
			$challenge = $request->get_param( 'hub.challenge' )
				?? $request->get_param( 'verifyToken' )
				?? $request->get_param( 'challenge' )
				?? null;

			// If adapter has a verify_challenge() method, delegate.
			if ( $adapter && method_exists( $adapter, 'verify_challenge' ) ) {
				$result = $adapter->verify_challenge( $request );
				if ( is_string( $result ) || is_int( $result ) ) {
					return new WP_REST_Response( $result, 200 );
				}
				return $result instanceof WP_REST_Response ? $result : new WP_REST_Response( $result, 200 );
			}

			if ( $challenge !== null ) {
				return new WP_REST_Response( (string) $challenge, 200 );
			}
			return new WP_REST_Response( [ 'ok' => true, 'platform' => $platform ], 200 );
		}

		// --- POST: inbound event ---
		// Verify signature if adapter supports it.
		if ( $adapter ) {
			$raw_request = [
				'headers' => $request->get_headers(),
				'body'    => $request->get_body(),
				'params'  => $request->get_params(),
			];
			if ( method_exists( $adapter, 'verify_webhook' ) && ! $adapter->verify_webhook( $raw_request ) ) {
				return new WP_REST_Response( [ 'error' => 'Webhook signature invalid' ], 403 );
			}

			// Normalize and fire via Bridge.
			if ( method_exists( $bridge, 'handle_inbound' ) ) {
				$endpoint_slug = 'webhook_' . strtolower( $platform ) . '_' . sanitize_key( $instance_id );
				$bridge->handle_inbound( $endpoint_slug, $raw_request );
			}
		}

		// Always 200 to acknowledge receipt (platform retries otherwise).
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}
}
