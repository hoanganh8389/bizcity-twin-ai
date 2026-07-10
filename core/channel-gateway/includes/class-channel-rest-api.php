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

		// [2026-06-20 Johnny Chu] PHASE-0.39 — Per-channel JSONL file log reader.
		// Reads BizCity_Channel_File_Logger files: bizcity-channel-logs/{channel}/YYYY-MM-DD.jsonl
		register_rest_route( self::NS, '/channel-logs', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $instance, 'get_channel_file_logs' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
				'args'                => [
					'channel' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
					'date'    => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'limit'   => [ 'default' => 200, 'sanitize_callback' => 'absint' ],
					'level'   => [ 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
				],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $instance, 'clear_channel_file_logs' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
				'args'                => [
					'channel' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
					'date'    => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		// [2026-06-20 Johnny Chu] PHASE-0.39 — Zalo OA JSONL-based inbox: conversations + messages.
		register_rest_route( self::NS, '/zalo-oa-inbox', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $instance, 'get_zalo_oa_inbox' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
				'args'                => [
					'oa_uid'    => [ 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
					'days'      => [ 'default' => 7,  'sanitize_callback' => 'absint' ],
					'sender_id' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		// [2026-06-22 Johnny Chu] GURU-FINISH W1.2 — Platform account picker for character-edit Channels tab.
		// Returns configured accounts (fanpages/OA/bots) for a given platform so UI can auto-populate
		// a dropdown instead of requiring manual ID entry.
		register_rest_route( self::NS, '/platform-accounts', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $instance, 'get_platform_accounts' ],
				'permission_callback' => [ $instance, 'require_manage_options' ],
				'args'                => [
					'platform' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
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

		// [2026-06-12 Johnny Chu] R-KG-FILE-TYPES — master plans proxy (public; same handler as
		// bizcity-wallet mu-plugin; belt-and-suspenders registration ensures route is always available
		// on REST requests even if bizcity-wallet mu-plugin fails to register it).
		register_rest_route( self::NS, '/master/plans', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'master_plans' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NS, '/master/my-plan', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'master_my_plan' ],
			'permission_callback' => [ $instance, 'require_logged_in' ],
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

	public function require_logged_in(): bool {
		return is_user_logged_in();
	}

	/* ═══════════════════════════════════════════
	 *  GET /master/plans — public plan list
	 *  [2026-06-12 Johnny Chu] R-KG-FILE-TYPES
	 * ═══════════════════════════════════════════ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function master_plans( WP_REST_Request $request ) {
		// Delegate to wallet proxy when available (it owns transient cache + hub fetch).
		if ( class_exists( 'BizCity_Wallet_Plans_Proxy_REST' ) ) {
			return BizCity_Wallet_Plans_Proxy_REST::handle( $request );
		}

		// [2026-06-12 Johnny Chu] R-KG-FILE-TYPES — belt-and-suspenders fallback.
		// [2026-06-13 Johnny Chu] R-KG-FILE-TYPES — POST + Bearer (explicit auth, hub accepts POST).
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$llm = BizCity_LLM_Client::instance();
			if ( $llm->is_ready() ) {
				$endpoint = rtrim( $llm->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1/master/plans';
				$response = wp_remote_post( $endpoint, array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $llm->get_api_key(),
						'Content-Type'  => 'application/json',
						'X-Site-URL'    => home_url(),
					),
					'body'    => '{}',
					'timeout' => 8,
				) );
				if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
					$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
					// Hub returns a plain array of plan objects.
					if ( is_array( $decoded ) && isset( $decoded[0] ) ) {
						return new WP_REST_Response( array( 'ok' => true, 'plans' => $decoded ), 200 );
					} elseif ( is_array( $decoded ) && ! empty( $decoded['plans'] ) ) {
						return new WP_REST_Response( array( 'ok' => true, 'plans' => $decoded['plans'] ), 200 );
					}
				}
			}
		}

		// Final fallback — return degraded so FE uses hardcoded pricing cards.
		return new WP_REST_Response( array( 'ok' => false, '_degraded' => true, 'plans' => array() ), 200 );
	}

	/* ═══════════════════════════════════════════
	 *  GET /master/my-plan — current user's plan
	 *  [2026-06-12 Johnny Chu] R-KG-FILE-TYPES
	 * ═══════════════════════════════════════════ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function master_my_plan( WP_REST_Request $request ) {
		if ( class_exists( 'BizCity_Wallet_Plans_Proxy_REST' ) ) {
			return BizCity_Wallet_Plans_Proxy_REST::handle_my_plan( $request );
		}

		$uid   = get_current_user_id();
		// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
		$level = class_exists( 'BizCity_User_Meta_Cache' )
			? (string) BizCity_User_Meta_Cache::get( $uid, '_bizcity_master_level', '' )
			: (string) get_user_meta( $uid, '_bizcity_master_level', true );
		if ( $level === '' ) {
			$level = 'free';
		}
		$defaults = array(
			'free'           => 'Free',
			'master_pro'     => 'Master Pro',
			'master_premium' => 'Master Premium',
		);
		$label = isset( $defaults[ $level ] ) ? $defaults[ $level ] : ucwords( str_replace( '_', ' ', $level ) );

		return new WP_REST_Response( array(
			'ok'      => true,
			'level'   => $level,
			'label'   => $label,
			'is_paid' => $level !== 'free',
		), 200 );
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
	 *  GET /platform-accounts — account picker for Channels tab
	 *  [2026-06-22 Johnny Chu] GURU-FINISH W1.2
	 *
	 *  Returns configured accounts for a given platform so the character-edit
	 *  Channels tab can show a dropdown instead of requiring manual ID entry.
	 *
	 *  Response: { success, platform, accounts: [{account_id, label, meta}] }
	 *  account_id is the value that goes into bizcity_channel_bindings.account_id.
	 * ═══════════════════════════════════════════ */

	public function get_platform_accounts( WP_REST_Request $request ): WP_REST_Response {
		// [2026-06-22 Johnny Chu] GURU-FINISH W1.2
		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );
		$accounts = array();

		if ( $platform === 'facebook' || $platform === 'messenger' ) {
			// Facebook fanpages configured in bizcity-facebook-bot plugin.
			if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
				$bots = BizCity_Facebook_Bot_Database::instance()->get_active_bots();
				foreach ( (array) $bots as $bot ) {
					$page_id   = (string) ( is_object( $bot ) ? ( $bot->page_id   ?? '' ) : ( $bot['page_id']   ?? '' ) );
					$page_name = (string) ( is_object( $bot ) ? ( $bot->page_name ?? '' ) : ( $bot['page_name'] ?? '' ) );
					if ( $page_id === '' ) {
						continue;
					}
					$accounts[] = array(
						'account_id' => $page_id,
						'label'      => $page_name !== '' ? $page_name . ' (' . $page_id . ')' : 'Page #' . $page_id,
						'meta'       => array( 'db_id' => (int) ( is_object( $bot ) ? ( $bot->id ?? 0 ) : ( $bot['id'] ?? 0 ) ) ),
					);
				}
			}
		} elseif ( $platform === 'zalo_bot' ) {
			// Zalo Bot accounts configured in bizcity-zalo-bot plugin.
			if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
				$bots = BizCity_Zalo_Bot_Database::instance()->get_active_bots();
				foreach ( (array) $bots as $bot ) {
					$bot_id   = (int)    ( is_object( $bot ) ? ( $bot->id       ?? 0  ) : ( $bot['id']       ?? 0  ) );
					$bot_name = (string) ( is_object( $bot ) ? ( $bot->bot_name ?? '' ) : ( $bot['bot_name'] ?? '' ) );
					$oa_id    = (string) ( is_object( $bot ) ? ( $bot->oa_id    ?? '' ) : ( $bot['oa_id']    ?? '' ) );
					if ( $bot_id <= 0 ) {
						continue;
					}
					// For zalo_bot binding, account_id = row.id (internal bot ID used by webhook router).
					$label = $bot_name !== '' ? $bot_name : 'Zalo Bot #' . $bot_id;
					if ( $oa_id !== '' ) {
						$label .= ' (OA: ' . $oa_id . ')';
					}
					$accounts[] = array(
						'account_id' => (string) $bot_id,
						'label'      => $label,
						'meta'       => array( 'oa_id' => $oa_id ),
					);
				}
			}
		} elseif ( $platform === 'zalo_oa' ) {
			// Zalo OA — accounts pulled from Zalo Bot table (oa_id field).
			if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
				$bots = BizCity_Zalo_Bot_Database::instance()->get_active_bots();
				foreach ( (array) $bots as $bot ) {
					$oa_id    = (string) ( is_object( $bot ) ? ( $bot->oa_id    ?? '' ) : ( $bot['oa_id']    ?? '' ) );
					$bot_name = (string) ( is_object( $bot ) ? ( $bot->bot_name ?? '' ) : ( $bot['bot_name'] ?? '' ) );
					if ( $oa_id === '' ) {
						continue;
					}
					$accounts[] = array(
						'account_id' => $oa_id,
						'label'      => ( $bot_name !== '' ? $bot_name . ' — ' : '' ) . 'OA: ' . $oa_id,
						'meta'       => array( 'db_id' => (int) ( is_object( $bot ) ? ( $bot->id ?? 0 ) : ( $bot['id'] ?? 0 ) ) ),
					);
				}
			}
		} elseif ( $platform === 'webchat' ) {
			$accounts[] = array(
				'account_id' => '*',
				'label'      => 'Tất cả khách guest (wildcard *)',
				'meta'       => array(),
			);
		}
		// email / telegram / twinchat_be → no pre-configured accounts to enumerate; fallback to manual.

		return new WP_REST_Response( array(
			'success'  => true,
			'platform' => $platform,
			'accounts' => $accounts,
		), 200 );
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
	 *  GET|DELETE /channel-logs
	 *  [2026-06-20 Johnny Chu] PHASE-0.39
	 *  Reads BizCity_Channel_File_Logger JSONL files.
	 * ═══════════════════════════════════════════ */

	/** Allowed channel names — whitelist to prevent path traversal. */
	private static $allowed_file_log_channels = array(
		'email', 'facebook', 'messenger', 'zalo_oa', 'zalo_bot',
		'telegram', 'webchat', 'cf7', 'channel_gateway',
	);

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_channel_file_logs( WP_REST_Request $request ): WP_REST_Response {
		$channel = (string) $request->get_param( 'channel' );
		$date    = (string) $request->get_param( 'date' );
		$limit   = (int)    $request->get_param( 'limit' );
		$level   = (string) $request->get_param( 'level' );

		if ( ! in_array( $channel, self::$allowed_file_log_channels, true ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'rows' => array(), 'error' => 'Unknown channel' ], 200 );
		}
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'rows' => array(), 'error' => 'BizCity_Channel_File_Logger not loaded' ], 200 );
		}

		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$date  = $date !== '' ? $date : $today;
		$limit = $limit > 0 ? min( 500, $limit ) : 200;
		$rows  = BizCity_Channel_File_Logger::read( $channel, $date, $limit, $level );

		return new WP_REST_Response( array(
			'ok'      => true,
			'channel' => $channel,
			'date'    => $date,
			'total'   => count( $rows ),
			'rows'    => $rows,
		), 200 );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function clear_channel_file_logs( WP_REST_Request $request ): WP_REST_Response {
		$channel = (string) $request->get_param( 'channel' );
		$date    = (string) $request->get_param( 'date' );

		if ( ! in_array( $channel, self::$allowed_file_log_channels, true ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Unknown channel' ), 200 );
		}

		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$date  = $date !== '' ? $date : $today;

		$upload        = wp_upload_dir();
		$basedir       = (string) ( $upload['basedir'] ?? '' );
		if ( $basedir === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'upload_dir not available' ), 200 );
		}

		$channel_safe  = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $channel ) );
		$file          = $basedir . DIRECTORY_SEPARATOR . 'bizcity-channel-logs'
		               . DIRECTORY_SEPARATOR . $channel_safe
		               . DIRECTORY_SEPARATOR . $date . '.jsonl';

		if ( ! file_exists( $file ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'cleared' => false, 'note' => 'File not found' ), 200 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$deleted = (bool) @unlink( $file );
		return new WP_REST_Response( array( 'ok' => $deleted, 'cleared' => $deleted ), 200 );
	}

	/* ═══════════════════════════════════════════
	 *  GET /zalo-oa-inbox
	 *  [2026-06-20 Johnny Chu] PHASE-0.39
	 *  JSONL-based inbox: reads bizcity-channel-logs/zalo_oa/*.jsonl,
	 *  groups inbound webhook_post entries by sender_id → conversations.
	 *  If sender_id param provided, also returns message thread.
	 * ═══════════════════════════════════════════ */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_zalo_oa_inbox( WP_REST_Request $request ) {
		// [2026-06-20 Johnny Chu] PHASE-0.39 — Zalo OA JSONL inbox handler
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'File logger not available', 'conversations' => array() ), 200 );
		}

		$oa_uid    = (string) $request->get_param( 'oa_uid' );
		$days      = max( 1, min( 30, (int) $request->get_param( 'days' ) ) );
		$sender_id = (string) $request->get_param( 'sender_id' );

		// Cutoff date: ignore files older than $days days.
		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );

		// List available dates for zalo_oa channel (most recent first).
		$dates = BizCity_Channel_File_Logger::list_dates( 'zalo_oa', 60 );

		// Collect and parse inbound message entries from webhook_post events.
		$messages_by_sender = array();
		$oa_id_map          = array(); // oa_id → oa_uid

		foreach ( $dates as $date ) {
			if ( $date < $cutoff ) {
				break; // dates are sorted desc, stop when too old
			}
			// Read up to 2000 entries per day (newest-first per read()).
			$entries = BizCity_Channel_File_Logger::read( 'zalo_oa', $date, 2000 );
			foreach ( $entries as $entry ) {
				$event = (string) ( $entry['event'] ?? '' );

				// Only process raw webhook_post entries (inbound from Zalo OA webhook).
				// Event field is like: "POST /bizcity-channel/v1/webhook/zalo_oa/zalo_oa_9867ab73"
				if ( strpos( $event, '/webhook/zalo_oa/' ) === false ) {
					continue;
				}

				$params = isset( $entry['ctx']['params'] ) && is_array( $entry['ctx']['params'] )
					? $entry['ctx']['params']
					: array();
				if ( empty( $params ) ) {
					continue;
				}

				$this_sender    = (string) ( $params['sender']['id'] ?? '' );
				$this_oa_id     = (string) ( $params['recipient']['id'] ?? '' );
				$this_event     = (string) ( $params['event_name'] ?? 'webhook' );
				$this_text      = (string) ( $params['message']['text'] ?? '' );
				$this_msg_id    = (string) ( $params['message']['msg_id'] ?? '' );
				$this_ts        = (string) ( $entry['ts'] ?? '' );
				$this_timestamp = (string) ( $params['timestamp'] ?? '' );

				if ( $this_sender === '' ) {
					continue;
				}

				// Parse oa_uid from URL: /webhook/zalo_oa/{oa_uid}
				$this_oa_uid = '';
				if ( preg_match( '#/webhook/zalo_oa/([a-z0-9_\-]+)#i', $event, $m ) ) {
					$this_oa_uid = $m[1];
				}

				// Filter by oa_uid if caller specified one.
				if ( $oa_uid !== '' && $this_oa_uid !== '' && $this_oa_uid !== $oa_uid ) {
					continue;
				}

				// Filter by sender_id if caller specified one.
				if ( $sender_id !== '' && $this_sender !== $sender_id ) {
					continue;
				}

				if ( ! isset( $messages_by_sender[ $this_sender ] ) ) {
					$messages_by_sender[ $this_sender ] = array();
				}

				// Normalize display text for special events.
				$display_text = $this_text;
				if ( $display_text === '' ) {
					if ( $this_event === 'follow' || $this_event === 'oa_follow' ) {
						$display_text = '📌 Người dùng đã theo dõi OA';
					} elseif ( $this_event === 'unfollow' || $this_event === 'oa_unfollow' ) {
						$display_text = '📌 Người dùng đã hủy theo dõi OA';
					} else {
						$display_text = '[' . $this_event . ']';
					}
				}

				$messages_by_sender[ $this_sender ][] = array(
					'ts'         => $this_ts,
					'timestamp'  => $this_timestamp,
					'direction'  => 'in',
					'event_name' => $this_event,
					'text'       => $display_text,
					'msg_id'     => $this_msg_id,
					'oa_id'      => $this_oa_id,
					'oa_uid'     => $this_oa_uid,
				);

				if ( $this_oa_id !== '' && ! isset( $oa_id_map[ $this_oa_id ] ) ) {
					$oa_id_map[ $this_oa_id ] = $this_oa_uid;
				}
			}
		}

		// Build conversation list: sort messages within each conversation chronologically.
		$conversations = array();
		foreach ( $messages_by_sender as $sid => $msgs ) {
			// Sort messages: oldest-first by ts string (ISO 8601 sorts lexicographically).
			usort( $msgs, function ( $a, $b ) { return strcmp( $a['ts'], $b['ts'] ); } );

			$last        = end( $msgs );
			$last_ts     = isset( $last['ts'] ) ? $last['ts'] : '';
			$last_text   = isset( $last['text'] ) ? $last['text'] : '';
			$conv_oa_id  = isset( $last['oa_id'] )  ? $last['oa_id']  : '';
			$conv_oa_uid = isset( $last['oa_uid'] ) ? $last['oa_uid'] : $oa_uid;

			$conversations[ $sid ] = array(
				'sender_id'    => $sid,
				'oa_id'        => $conv_oa_id,
				'oa_uid'       => $conv_oa_uid,
				'last_message' => $last_text,
				'last_ts'      => $last_ts,
				'msg_count'    => count( $msgs ),
			);

			// If specific sender requested, embed the messages too.
			if ( $sender_id !== '' && $sid === $sender_id ) {
				$conversations[ $sid ]['messages'] = $msgs;
			}
		}

		// Sort conversations newest-first by last_ts.
		usort( $conversations, function ( $a, $b ) { return strcmp( $b['last_ts'], $a['last_ts'] ); } );

		// If a specific sender_id was requested, pull the messages from the matched conversation.
		$thread = array();
		if ( $sender_id !== '' && isset( $messages_by_sender[ $sender_id ] ) ) {
			$thread = $messages_by_sender[ $sender_id ];
			usort( $thread, function ( $a, $b ) { return strcmp( $a['ts'], $b['ts'] ); } );
		}

		$response = array(
			'ok'            => true,
			'conversations' => array_values( $conversations ),
			'total'         => count( $conversations ),
			'oa_ids'        => $oa_id_map,
		);
		if ( $sender_id !== '' ) {
			$response['messages'] = $thread;
			$response['sender_id'] = $sender_id;
		}

		return new WP_REST_Response( $response, 200 );
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

		// [2026-06-20 Johnny Chu] PHASE-0.39 — Fallback: new-style integrations (e.g. ZALO_OA)
		// are registered in BizCity_Integration_Registry, NOT in BizCity_Gateway_Bridge.
		// bridge->get_adapter('ZALO_OA') returns null → entire webhook handler does nothing.
		// Fix: check registry if bridge has no match.
		if ( ! $adapter && class_exists( 'BizCity_Integration_Registry' ) ) {
			$reg_integ = BizCity_Integration_Registry::instance()->get( strtolower( $platform ) );
			if ( $reg_integ instanceof BizCity_Channel_Integration ) {
				$adapter = $reg_integ;
			}
		}

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
		// [2026-06-19 Johnny Chu] R-CH-FILE-LOG — write BizCity_Webhook_Log so
		// admin SPA (GET /bizcity-channel/v1/logs?platform=...) shows REST-based
		// channel events (Zalo OA, Telegram) alongside Router-based channels.
		// File-log FIRST (before any processing) per R-CH-FILE-LOG rule.
		if ( class_exists( 'BizCity_Webhook_Log', false ) ) {
			BizCity_Webhook_Log::log( array(
				'platform'      => $platform,
				'endpoint'      => '/wp-json/bizcity-channel/v1/webhook/' . strtolower( $platform ) . '/' . $instance_id,
				'method'        => 'POST',
				'http_status'   => 200,
				'verify_status' => 'pending',
				'remote_ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
				'user_agent'    => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 200 ),
				'body_raw'      => wp_json_encode( $request->get_params() ),
			) );
		}

		// [2026-06-13 Johnny Chu] ZA-1.2 — bifurcate BizCity_Channel_Integration (new API) vs
		// legacy adapter (old array-based API). The old path passed an array to verify_webhook()
		// which is typed WP_REST_Request → TypeError on PHP 7.4 for new-style integrations.
		if ( $adapter ) {
			if ( $adapter instanceof BizCity_Channel_Integration ) {
				// ── New path: BizCity_Channel_Integration (WP_REST_Request-based) ──────────

				// [2026-06-20 Johnny Chu] PHASE-0.39 — Load account by _uid FIRST so that
				// verify_webhook can access the real app_secret for MAC verification.
				// instance_id in webhook URL = account _uid (e.g. zalo_oa_9867ab73).
				// Fallback: match by oa_id / page_id, then use first account.
				// error_log( '[bizcity-cg] TRACE handle_webhook platform=' . $platform . ' instance_id=' . $instance_id . ' adapter=' . get_class( $adapter ) );
				$account = [];
				if ( class_exists( 'BizCity_Integration_Registry' ) ) {
					$raw_accts = BizCity_Integration_Registry::instance()->get_accounts( $adapter->get_code() );
					// error_log( '[bizcity-cg] TRACE raw_accts count=' . count( $raw_accts ) . ' for code=' . $adapter->get_code() );
					foreach ( $raw_accts as $_raw_acc ) {
						if ( isset( $_raw_acc['_uid'] ) && (string) $_raw_acc['_uid'] === $instance_id ) {
							// error_log( '[bizcity-cg] TRACE matched account by _uid=' . $_raw_acc['_uid'] . ' oa_id=' . ( $_raw_acc['oa_id'] ?? '?' ) );
							$_clone = clone $adapter;
							$_clone->set_account( $_raw_acc );
							$account = $_clone->get_decrypted_params( true );
							break;
						}
					}
					if ( empty( $account ) ) {
						// error_log( '[bizcity-cg] TRACE no _uid match, trying oa_id/page_id fallback for instance_id=' . $instance_id );
						foreach ( $raw_accts as $_raw_acc ) {
							if ( isset( $_raw_acc['oa_id'] ) && (string) $_raw_acc['oa_id'] === $instance_id ) {
								$_clone = clone $adapter;
								$_clone->set_account( $_raw_acc );
								$account = $_clone->get_decrypted_params( true );
								break;
							}
							if ( isset( $_raw_acc['page_id'] ) && (string) $_raw_acc['page_id'] === $instance_id ) {
								$_clone = clone $adapter;
								$_clone->set_account( $_raw_acc );
								$account = $_clone->get_decrypted_params( true );
								break;
							}
						}
					}
					if ( empty( $account ) && ! empty( $raw_accts ) ) {
						// error_log( '[bizcity-cg] TRACE fallback to first account (no uid/oa_id match)' );
						$_clone = clone $adapter;
						$_clone->set_account( $raw_accts[0] );
						$account = $_clone->get_decrypted_params( true );
					}
				}

				// error_log( '[bizcity-cg] TRACE account_resolved empty=' . ( empty( $account ) ? 'yes' : 'no' ) . ' oa_id=' . ( $account['oa_id'] ?? '?' ) . ' has_app_secret=' . ( ! empty( $account['app_secret'] ) ? 'yes' : 'no' ) );

				// [2026-06-20 Johnny Chu] PHASE-0.39 R-CH-FILE-LOG — write file log HERE (before verify)
				// per R-CH-FILE-LOG: evidence must exist even if verify fails or DB throws.
				if ( $platform === 'ZALO_OA' && class_exists( 'BizCity_Channel_File_Logger' ) ) {
					BizCity_Channel_File_Logger::write(
						BizCity_Channel_File_Logger::CH_ZALO_OA,
						BizCity_Channel_File_Logger::LEVEL_INFO,
						'webhook_post',
						'Zalo OA inbound POST received',
						array(
							'uid'            => $instance_id,
							'account_found'  => ! empty( $account ),
							'has_app_secret' => ! empty( $account['app_secret'] ?? '' ),
							'body_len'       => strlen( $request->get_body() ),
						)
					);
				}

				// Clone adapter + load account so verify_webhook has the real app_secret.
				$loaded_adapter = clone $adapter;
				if ( ! empty( $account ) ) {
					$loaded_adapter->set_account( $account );
				}

				if ( method_exists( $loaded_adapter, 'verify_webhook' ) ) {
					$verify_result = $loaded_adapter->verify_webhook( $request );
					// error_log( '[bizcity-cg] TRACE verify_webhook result=' . ( is_wp_error( $verify_result ) ? 'WP_Error(' . $verify_result->get_error_code() . ')' : ( $verify_result ? 'true' : 'false' ) ) );

					// [2026-06-20 Johnny Chu] PHASE-0.39 — Fallback for ZALO_OA:
					// BizCity_Zalo_OA_Integration (bizcity-zalo-personal) checks Bearer token
					// for zca-bridge sidecar. When no bridge is configured, stored_token='' and
					// it returns false — even though this is a legitimate direct Zalo webhook POST.
					// Override: apply correct MAC logic from the loaded account credentials.
					if ( ( false === $verify_result || is_wp_error( $verify_result ) ) && $platform === 'ZALO_OA' ) {
						$_mac_param  = $request->get_param( 'mac' );
						$_app_secret = (string) ( $account['app_secret'] ?? '' );
						if ( ! $_mac_param || $_app_secret === '' ) {
							// No mac param or no secret configured → pass through.
							$verify_result = true;
							// error_log( '[bizcity-cg] TRACE zalooa_fallback pass (no mac or no secret)' );
						} else {
							$_expected     = hash_hmac( 'sha256', $request->get_body(), $_app_secret );
							$verify_result = hash_equals( $_expected, (string) $_mac_param );
							// error_log( '[bizcity-cg] TRACE zalooa_fallback mac_check=' . ( $verify_result ? 'pass' : 'FAIL' ) );
						}
					}

					if ( false === $verify_result || is_wp_error( $verify_result ) ) {
						return new WP_REST_Response( [ 'error' => 'Webhook signature invalid' ], 403 );
					}
				}

				// Normalize inbound into canonical envelope.
				// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — ZALO_OA: Zalo webhook may omit
				// or mismatch Content-Type header → WP_REST_Request::get_json_params() returns null
				// → normalize_inbound returns empty. Force Content-Type before normalize so
				// get_json_params() parses the raw body correctly. No side-effect if body is empty.
				if ( $platform === 'ZALO_OA' ) {
					$request->set_header( 'Content-Type', 'application/json' );
				}
				$envelope = [];
				if ( method_exists( $loaded_adapter, 'normalize_inbound' ) ) {
					$envelope = $loaded_adapter->normalize_inbound( $request, $account );
				}
				// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P0: trace envelope after normalize.
				if ( $platform === 'ZALO_OA' ) {
					error_log( '[bizcity-cg-trace] P0 ZALO_OA normalize_inbound result: has_sender=' . ( ! empty( $envelope['sender_id'] ) ? '1 sender=' . $envelope['sender_id'] : '0(empty)' ) . ' adapter=' . get_class( $loaded_adapter ) );
				}

				// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — FALLBACK: when normalize_inbound
				// returns empty for ZALO_OA (e.g. get_json_params() null due to WP parse quirk),
				// manually build envelope from get_params() which always has the merged data.
				// Evidence: JSONL always shows params.sender.id present even when P0 shows empty.
				if ( $platform === 'ZALO_OA' && empty( $envelope['sender_id'] ) ) {
					$_fb = $request->get_params();
					$_fb_sender_id  = (string) ( $_fb['sender']['id'] ?? '' );
					$_fb_oa_id      = (string) ( $_fb['recipient']['id'] ?? ( $account['oa_id'] ?? '' ) );
					$_fb_event      = (string) ( $_fb['event_name'] ?? '' );
					$_fb_message    = $_fb['message'] ?? array();
					$_fb_text       = (string) ( $_fb_message['text'] ?? '' );
					$_fb_mid        = (string) ( $_fb_message['msg_id'] ?? '' );
					$_fb_ts         = (int) ( $_fb['timestamp'] ?? time() );
					// Map event_name to type (mirror of normalize_inbound switch).
					$_fb_type_map   = array(
						'user_send_text'    => 'text',
						'user_send_image'   => 'image',
						'user_send_file'    => 'file',
						'user_send_gif'     => 'gif',
						'user_send_sticker' => 'sticker',
						'follow'            => 'follow',
						'unfollow'          => 'unfollow',
					);
					$_fb_type = isset( $_fb_type_map[ $_fb_event ] ) ? $_fb_type_map[ $_fb_event ] : ( $_fb_event ?: 'unknown' );
					// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — Skip oa_* events (OA outbound echo).
					// oa_send_text/oa_send_image fires when OA sends a message OUT — NOT inbound from user.
					if ( strpos( $_fb_event, 'oa_' ) === 0 ) {
						error_log( '[bizcity-cg-trace] P0-FB-SKIP oa_echo event=' . $_fb_event . ' sender=' . $_fb_sender_id );
						return new WP_REST_Response( array( 'ok' => true ), 200 );
					}
					error_log( '[bizcity-cg-trace] P0-FB ZALO_OA fallback get_params sender_id=' . $_fb_sender_id . ' event=' . $_fb_event );
					if ( $_fb_sender_id ) {
						$envelope = array(
							'platform'    => 'ZALO_OA',
							'code'        => 'zalo_oa',
							'instance_id' => $_fb_oa_id,
							'chat_id'     => 'zalooa_' . $_fb_oa_id . '_' . $_fb_sender_id,
							'sender_id'   => $_fb_sender_id,
							'text'        => $_fb_text,
							'type'        => $_fb_type,
							'media_url'   => '',
							'mid'         => $_fb_mid,
							'raw'         => $_fb,
							'timestamp'   => $_fb_ts,
						);
					}
				}

				if ( ! empty( $envelope['sender_id'] ) ) {
					$platform_uc = strtoupper( (string) ( $envelope['platform'] ?? $platform ) );
					$chat_id     = (string) ( $envelope['chat_id'] ?? '' );
					$sender_id   = (string) ( $envelope['sender_id'] ?? '' );
					$msg_type    = (string) ( $envelope['type'] ?? 'unknown' );
					$text        = (string) ( $envelope['text'] ?? '' );
					$mid         = (string) ( $envelope['mid'] ?? '' );
					$code_lc     = strtolower( (string) ( $envelope['code'] ?? '' ) );

					// [2026-06-13 Johnny Chu] ZA-1.2 — Build a payload compatible with UCL map
					// key 'wu_zalobot_message_received' so Universal_Channel_Listener fires
					// bizcity_channel_normalized → Listener_Bus + Automation_Listener all see it.
					// UCL also logs bizcity_channel_messages + resolves character_id (Guru).
					$_twf_payload = [
						'platform'    => $platform_uc,
						'instance_id' => (string) ( $envelope['instance_id'] ?? '' ),
						'sender_id'   => $sender_id,
						'chat_id'     => $chat_id,
						'text'        => $text,
						'mid'         => $mid,
						'type'        => $msg_type,
						'media_url'   => (string) ( $envelope['media_url'] ?? '' ),
						'timestamp'   => (int) ( $envelope['timestamp'] ?? time() ),
						'raw'         => $envelope['raw'] ?? [],
					];

					// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — webhook_received log BEFORE do_action
					// so the evidence survives even when a subscriber throws a PHP exception.
					if ( ( $platform_uc === 'ZALO_OA' || $code_lc === 'zalo_oa' ) && class_exists( 'BizCity_Channel_File_Logger' ) ) {
						BizCity_Channel_File_Logger::write(
							BizCity_Channel_File_Logger::CH_ZALO_OA,
							BizCity_Channel_File_Logger::LEVEL_INFO,
							'webhook_received',
							'Zalo OA inbound: ' . $msg_type . ' from ' . $sender_id,
							array(
								'uid'       => $instance_id,
								'sender_id' => $sender_id,
								'mid'       => $mid,
								'type'      => $msg_type,
								'text_len'  => strlen( $text ),
							)
						);
					}

					// bizcity_channel_message_received — consumed by BizCity_Automation_Listener
					// + any other direct subscriber (same as BizCity_Gateway_Bridge::handle_inbound).
					// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — wrap in try/catch so a throwing
					// subscriber (e.g. BizCity_CRM_Inbox_Bridge) does NOT kill the legacy Zalo pipeline
					// below (bizcity_zalo_oa_message_received → BizCity_CRM_Facebook_Ingestor → AI reply).
					try {
						do_action( 'bizcity_channel_message_received', $_twf_payload );
					} catch ( \Throwable $_cg_ex ) {
						if ( class_exists( 'BizCity_Channel_File_Logger' ) && ( $platform_uc === 'ZALO_OA' || $code_lc === 'zalo_oa' ) ) {
							BizCity_Channel_File_Logger::write(
								BizCity_Channel_File_Logger::CH_ZALO_OA,
								BizCity_Channel_File_Logger::LEVEL_ERROR,
								'channel_msg_action_exception',
								$_cg_ex->getMessage(),
								array( 'class' => get_class( $_cg_ex ) )
							);
						}
						error_log( '[bizcity-cg] bizcity_channel_message_received threw: ' . $_cg_ex->getMessage() );
						unset( $_cg_ex );
					}

					// [2026-06-19 Johnny Chu] PHASE-0.39 — Zalo OA direct path must also feed
					// legacy CRM/admin listeners expecting `bizcity_zalo_message_received` shape.
					if ( $platform_uc === 'ZALO_OA' || $code_lc === 'zalo_oa' ) {

					$_legacy_name = (string) ( $envelope['sender_name'] ?? '' );
						if ( $_legacy_name === '' && isset( $envelope['raw']['sender']['display_name'] ) ) {
							$_legacy_name = (string) $envelope['raw']['sender']['display_name'];
						}

						$_legacy_payload = array(
							'platform'       => 'ZALO_OA',
							'code'           => 'zalo_oa',
							'bot_id'         => (string) ( $envelope['instance_id'] ?? '' ),
							'bot_name'       => (string) ( $account['label'] ?? ( $account['oa_name'] ?? '' ) ),
							'account_id'     => (string) ( $envelope['instance_id'] ?? '' ),
							'account_name'   => (string) ( $account['label'] ?? ( $account['oa_name'] ?? '' ) ),
							'event_name'     => $msg_type,
							'from_user_id'   => $sender_id,
							'from_user_name' => $_legacy_name,
							'message_id'     => $mid,
							'conversation_id'=> (string) ( $envelope['instance_id'] ?? '' ),
							'message_type'   => $msg_type,
							'message_text'   => $text,
							'message_time'   => gmdate( 'Y-m-d H:i:s', (int) ( $envelope['timestamp'] ?? time() ) ),
							'image_url'      => ( $msg_type === 'image' ) ? (string) ( $envelope['media_url'] ?? '' ) : '',
							'raw'            => $envelope['raw'] ?? array(),
						);

						// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND R-ZONE — Zalo OA is Zone 1 (customer CRM).
					// DO NOT fire 'bizcity_zalo_message_received' — that action is Zone 2 (Zalo Bot admin).
					// Firing it caused BizCity_Zalo_Bot_Gateway_Bridge to process OA messages as ZALO_BOT,
					// creating inbox with channel_type='zalo' instead of 'zalo_oa', breaking Guru binding.
					// Only fire the OA-specific action and waic_twf_process_flow.
					do_action( 'bizcity_zalo_oa_message_received', $_legacy_payload );
					// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — trace: confirm waic_twf fires.
					// [2026-06-21 Johnny Chu] PHASE-0.39 GURU-BIND — P1b: count subscribers to waic_twf_process_flow before firing.
					global $wp_filter;
					$_p1b_count = isset( $wp_filter['waic_twf_process_flow'] ) ? count( $wp_filter['waic_twf_process_flow']->callbacks ) : 0;
					$_p1b_ingestor = class_exists( 'BizCity_CRM_Facebook_Ingestor', false ) ? 'loaded' : 'NOT_LOADED';
					error_log( '[bizcity-cg-trace] P1 firing waic_twf_process_flow(bizcity_zalo_oa_message_received) sender=' . $sender_id . ' oa=' . $instance_id . ' subscribers=' . $_p1b_count . ' ingestor=' . $_p1b_ingestor );
					do_action( 'waic_twf_process_flow', 'bizcity_zalo_oa_message_received', $_legacy_payload );
					error_log( '[bizcity-cg-trace] P1-done waic_twf fired' );
					unset( $_p1b_count, $_p1b_ingestor );
						unset( $_legacy_name, $_legacy_payload );
					} else {
						// waic_twf_process_flow with correct trigger_key string → UCL::on_trigger()
						// picks it up via 'wu_zalobot_message_received' entry in $map:
						//   priority 5: bizcity_channel_messages log + character_id/Guru binding
						//   priority 6: bizcity_channel_normalized (Listener_Bus)
						//   priority 9: on_normalized_crm_ingest → bizcity_crm_events (R-SCH-REPLY)
						$_trigger_key = 'wu_zalobot_message_received';
						do_action( 'waic_twf_process_flow', $_trigger_key, $_twf_payload );
					}
					unset( $_twf_payload );
				}
			} else {
				// ── Legacy path: old adapter interface (array-based request) ─────────────
				$raw_request = [
					'headers' => $request->get_headers(),
					'body'    => $request->get_body(),
					'params'  => $request->get_params(),
				];
				if ( method_exists( $adapter, 'verify_webhook' ) && ! $adapter->verify_webhook( $raw_request ) ) {
					return new WP_REST_Response( [ 'error' => 'Webhook signature invalid' ], 403 );
				}
				if ( method_exists( $bridge, 'handle_inbound' ) ) {
					$endpoint_slug = 'webhook_' . strtolower( $platform ) . '_' . sanitize_key( $instance_id );
					$bridge->handle_inbound( $endpoint_slug, $raw_request );
				}
			}
		}

		// Always 200 to acknowledge receipt (platform retries otherwise).
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}
}
