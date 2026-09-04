<?php
/**
 * BizCity Channel Gateway - Managed Zalo OA client facade (Branch 20).
 *
 * CRM uses this same-origin facade. Hub calls are made by the PHP wrapper;
 * the browser never receives provider credentials or calls the Hub directly.
 *
 * @package BizCity_Twin_AI
 * @since PHASE-0.44-ZALO-OA-DUAL-MODE
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_OA_Hub_REST', false ) ) {
	return;
}

final class BizCity_Zalo_OA_Hub_REST {

	const NS = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$admin = array( __CLASS__, 'permission_admin' );
		register_rest_route( self::NS, '/zalo-oa-bridge/catalog', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'catalog' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/zalo-oa-bridge/connect-url', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'connect_url' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/zalo-oa-bridge/accounts', array(
			'methods'             => 'GET, POST',
			'callback'            => array( __CLASS__, 'accounts' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/zalo-oa-bridge/accounts/(?P<id>\d+)/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'account_status' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/zalo-oa-bridge/accounts/(?P<id>\d+)/test', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'account_test' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/zalo-oa-bridge/accounts/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'account_revoke' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/zalo-oa-bridge/inbound', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'inbound' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function permission_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function catalog(): WP_REST_Response {
		$client = self::client();
		$capability = $client ? $client->capability() : self::degraded( 'managed_client_missing' );
		return new WP_REST_Response( array(
			'success' => true,
			'channels' => array(
				array(
					'code' => 'zalo_oa',
					'label' => 'Zalo OA',
					'zone' => 'customer',
					'modes' => array(
						array( 'code' => 'self_managed', 'requires' => array( 'app_id', 'app_secret', 'oa_id' ) ),
						array( 'code' => 'managed_1api', 'requires' => array(), 'requires_entitlement' => true ),
					),
				),
				array(
					'code' => 'zalo_personal',
					'label' => 'Zalo Ca nhan',
					'zone' => 'customer',
					'modes' => array( 'custom_bridge', 'managed_1api' ),
				),
			),
			'capability' => $capability,
		), 200 );
	}

	public static function connect_url( WP_REST_Request $request ): WP_REST_Response {
		$client = self::client();
		if ( ! $client ) {
			return self::degraded_response( 'managed_client_missing' );
		}
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$uid = self::account_uid( $body['account_uid'] ?? '' );
		$result = $client->connect_url( array(
			'client_request_id' => (string) ( $body['client_request_id'] ?? '' ),
			'account_uid'       => $uid,
			'return_url'        => esc_url_raw( (string) ( $body['return_url'] ?? admin_url( 'admin.php?page=bizcity-twinchat&crm=1' ) ) ),
		) );
		if ( ! empty( $result['success'] ) ) {
			$projection = self::save_local_projection( $uid, $result );
			if ( is_wp_error( $projection ) ) {
				return self::degraded_response( 'crm_projection_failed' );
			}
		}
		unset( $result['callback_token_once'] );
		return new WP_REST_Response( $result, 200 );
	}

	public static function accounts( WP_REST_Request $request ): WP_REST_Response {
		$client = self::client();
		if ( 'POST' === strtoupper( $request->get_method() ) ) {
			$body = $request->get_json_params();
			$body = is_array( $body ) ? $body : array();
			$channel = sanitize_key( (string) ( $body['channel'] ?? 'zalo_oa' ) );
			if ( ! in_array( $channel, array( 'zalo_oa', 'zalo_personal' ), true ) || ! class_exists( 'BizCity_Integration_Registry' ) ) {
				return new WP_REST_Response( self::degraded( 'invalid_param' ), 200 );
			}
			unset( $body['channel'] );
			$saved = BizCity_Integration_Registry::instance()->save_channel_account( $channel, $body );
			return new WP_REST_Response( is_wp_error( $saved ) ? self::degraded( 'gateway_degraded' ) : array( 'success' => true, 'account' => $saved ), 200 );
		}
		$result = $client ? $client->list_accounts() : self::degraded( 'managed_client_missing' );
		if ( is_array( $result ) && ! empty( $result['success'] ) ) {
			$sync = self::sync_local_projections( (array) ( $result['accounts'] ?? array() ) );
			if ( is_wp_error( $sync ) ) {
				return self::degraded_response( 'crm_projection_failed' );
			}
		}
		return new WP_REST_Response( $result, 200 );
	}

	public static function account_status( WP_REST_Request $request ): WP_REST_Response {
		$client = self::client();
		return new WP_REST_Response( $client ? $client->account_status( (int) $request['id'] ) : self::degraded( 'managed_client_missing' ), 200 );
	}

	public static function account_test( WP_REST_Request $request ): WP_REST_Response {
		$client = self::client();
		return new WP_REST_Response( $client ? $client->test_account( (int) $request['id'] ) : self::degraded( 'managed_client_missing' ), 200 );
	}

	public static function account_revoke( WP_REST_Request $request ): WP_REST_Response {
		$client = self::client();
		$result = $client ? $client->revoke_account( (int) $request['id'] ) : self::degraded( 'managed_client_missing' );
		if ( ! empty( $result['success'] ) ) {
			self::mark_local_revoked( (int) $request['id'] );
		}
		return new WP_REST_Response( $result, 200 );
	}

	public static function inbound( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = (string) $request->get_body();
		$signature = sanitize_text_field( (string) $request->get_header( 'X-BizCity-Signature' ) );
		$event_id = sanitize_text_field( (string) $request->get_header( 'X-BizCity-Event-ID' ) );
		if ( $raw_body === '' || $signature === '' || $event_id === '' || ! class_exists( 'BizCity_Codec' ) ) {
			return self::callback_error( 'callback_signature_invalid', 'Quyền callback Zalo OA không hợp lệ.', 'Kiểm tra callback URL và kết nối lại OA từ CRM.', 'gateway_degraded', 401 );
		}
		$client = self::client();
		if ( ! $client ) {
			return self::callback_error( 'module_not_loaded', 'Managed Zalo OA chưa sẵn sàng trên site.', 'Nạp lại Channel Gateway rồi gửi lại callback.', 'module_not_loaded', 503 );
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_OA, BizCity_Channel_File_Logger::LEVEL_INFO, 'managed_callback_received', 'Managed Zalo OA callback received.', array( 'event_id' => $event_id ) );
		}
		$payload = json_decode( $raw_body, true );
		$payload = is_array( $payload ) ? $payload : array();
		$oa_id = sanitize_text_field( (string) ( $payload['oa_id'] ?? ( $payload['raw']['recipient']['id'] ?? '' ) ) );
		$matched_token = '';
		$matched_oa_id = '';
		$matched_pending_id = '';
		foreach ( $client->callback_tokens() as $binding ) {
			$token = is_array( $binding ) ? (string) ( $binding['token'] ?? '' ) : '';
			$binding_oa_id = is_array( $binding ) ? (string) ( $binding['oa_id'] ?? '' ) : '';
			if ( $token === '' ) {
				continue;
			}
			$expected = BizCity_Codec::hmac_sha256( $raw_body, $token, false );
			if ( hash_equals( $expected, $signature ) ) {
				$matched_token = $token;
				$matched_oa_id = $binding_oa_id;
				$matched_pending_id = (string) ( is_array( $binding ) ? ( $binding['pending_id'] ?? '' ) : '' );
				break;
			}
		}
		if ( $matched_token === '' ) {
			return self::callback_error( 'callback_signature_invalid', 'Chữ ký callback Zalo OA không hợp lệ.', 'Kiểm tra phiên kết nối và callback token rồi thử lại.', 'gateway_degraded', 401 );
		}
		// [2026-08-29 Johnny Chu] PHASE-0.44-ZALO-OA-DUAL-MODE — reconcile only after the callback HMAC is verified.
		if ( $oa_id !== '' ) {
			$remote_accounts = $client->list_accounts();
			if ( ! empty( $remote_accounts['success'] ) ) {
				$sync = self::sync_local_projections( (array) ( $remote_accounts['accounts'] ?? array() ) );
				if ( is_wp_error( $sync ) ) {
					return self::callback_error( 'gateway_degraded', 'Chưa đồng bộ được trạng thái Official Account.', 'Thử lại callback sau ít phút.', 'gateway_degraded', 503 );
				}
			}
		}
		foreach ( $client->callback_tokens() as $binding ) {
			if ( is_array( $binding ) && (string) ( $binding['pending_id'] ?? '' ) === $matched_pending_id ) {
				$matched_oa_id = (string) ( $binding['oa_id'] ?? '' );
				break;
			}
		}
		if ( $oa_id === '' || $matched_oa_id !== $oa_id || ! self::managed_oa_is_local( $oa_id ) ) {
			return self::callback_error( 'permission_denied', 'Official Account chưa được liên kết với site này.', 'Mở CRM và hoàn tất cấp quyền đúng OA trước khi nhận tin.', 'permission_denied', 403 );
		}
		if ( ! self::claim_callback_event( $event_id ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'accepted' => true, 'duplicate' => true ), 200 );
		}
		$raw = is_array( $payload['raw'] ?? null ) ? $payload['raw'] : array();
		$sender = (string) ( $raw['sender']['id'] ?? $raw['follower']['id'] ?? '' );
		$message = is_array( $raw['message'] ?? null ) ? $raw['message'] : array();
		$trigger = array(
			'account_id' => $oa_id,
			'account_name' => (string) ( $payload['oa_name'] ?? $oa_id ),
			'conversation_id' => $oa_id,
			'from_user_id' => $sender,
			'from_user_name' => (string) ( $raw['sender']['display_name'] ?? '' ),
			'message_id' => (string) ( $message['msg_id'] ?? '' ),
			'message_text' => (string) ( $message['text'] ?? '' ),
			'message_time' => current_time( 'mysql' ),
			'event_name' => (string) ( $raw['event_name'] ?? 'user_send_text' ),
			'raw' => $raw,
		);
		if ( class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
			BizCity_CRM_Facebook_Ingestor::instance()->on_workflow_trigger( 'bizcity_zalo_oa_message_received', $trigger );
		}
		return new WP_REST_Response( array( 'ok' => true, 'accepted' => true, 'event_id' => $event_id ), 200 );
	}

	private static function save_local_projection( string $uid, array $result ) {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) || $uid === '' ) {
			return new WP_Error( 'crm_projection_failed', 'Channel projection is unavailable.' );
		}
		$registry = BizCity_Integration_Registry::instance();
		$account = array( '_uid' => $uid, 'connection_mode' => 'managed_1api', 'managed_pending_id' => (string) ( $result['pending_id'] ?? '' ), 'managed_client_request_id' => (string) ( $result['_client_request_id'] ?? '' ), 'managed_status' => 'oauth_pending' );
		return $registry->save_channel_account( 'zalo_oa', $account, false );
	}

	private static function sync_local_projections( array $remote_accounts ) {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return new WP_Error( 'crm_projection_failed', 'Channel projection is unavailable.' );
		}
		$registry = BizCity_Integration_Registry::instance();
		$local_accounts = $registry->get_accounts( 'zalo_oa' );
		foreach ( $remote_accounts as $remote ) {
			$remote_request = (string) ( $remote['client_request_id'] ?? '' );
			$remote_id = (int) ( $remote['id'] ?? 0 );
			$remote_oa = (string) ( $remote['oa_id'] ?? '' );
			foreach ( $local_accounts as $local ) {
				if ( (string) ( $local['connection_mode'] ?? '' ) !== 'managed_1api' ) {
					continue;
				}
				if ( $remote_request !== '' && (string) ( $local['managed_client_request_id'] ?? '' ) !== $remote_request && (string) ( $local['managed_oa_id'] ?? '' ) !== $remote_oa ) {
					continue;
				}
				$local['managed_hub_account_id'] = $remote_id;
				$local['managed_oa_id'] = $remote_oa;
				$local['managed_oa_name'] = (string) ( $remote['oa_name'] ?? '' );
				$local['managed_status'] = (string) ( $remote['status'] ?? 'active' );
				$saved = $registry->save_channel_account( 'zalo_oa', $local, false );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				break;
			}
		}
		return true;
	}

	private static function managed_oa_is_local( string $oa_id ): bool {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return false;
		}
		foreach ( BizCity_Integration_Registry::instance()->get_accounts( 'zalo_oa' ) as $account ) {
			if ( (string) ( $account['connection_mode'] ?? '' ) === 'managed_1api' && (string) ( $account['managed_oa_id'] ?? '' ) === $oa_id ) {
				return true;
			}
		}
		return false;
	}

	private static function mark_local_revoked( int $account_id ): void {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return;
		}
		foreach ( BizCity_Integration_Registry::instance()->get_accounts( 'zalo_oa' ) as $account ) {
			if ( (int) ( $account['managed_hub_account_id'] ?? 0 ) === $account_id ) {
				$account['managed_status'] = 'revoked';
				BizCity_Integration_Registry::instance()->save_channel_account( 'zalo_oa', $account, false );
				return;
			}
		}
	}

	private static function account_uid( $value ): string {
		$value = sanitize_key( (string) $value );
		return $value !== '' ? $value : 'zalo_oa_' . substr( md5( uniqid( 'zalo_oa', true ) ), 0, 12 );
	}

	private static function client() {
		return class_exists( 'BizCity_Zalo_OA_Hub_Client' ) ? BizCity_Zalo_OA_Hub_Client::instance() : null;
	}

	private static function degraded( string $code ): array {
		return array( 'success' => false, '_degraded' => true, 'code' => 'gateway_degraded', 'message' => 'BizCity Managed Zalo OA chưa sẵn sàng.', 'hint' => 'Kiểm tra API key và cấu hình Hub rồi thử lại.', 'help_code' => 'gateway_degraded', 'context' => array( 'reason' => sanitize_key( $code ) ) );
	}

	private static function degraded_response( string $code ): WP_REST_Response {
		return new WP_REST_Response( self::degraded( $code ), 200 );
	}

	private static function callback_error( string $code, string $message, string $hint, string $help_code, int $status ): WP_REST_Response {
		// [2026-08-28 Johnny Chu] R-ERROR-UX — return the complete callback error envelope.
		return new WP_REST_Response( array( 'ok' => false, 'success' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), $status );
	}

	private static function claim_callback_event( string $event_id ): bool {
		// [2026-08-29 Johnny Chu] PHASE-0.44-ZALO-OA-DUAL-MODE — use a cross-request atomic fallback when no persistent object cache exists.
		$key = 'bizcity_zalo_oa_event_' . md5( $event_id );
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_add' ) ) {
			return (bool) wp_cache_add( $key, 1, 'bizcity_zalo_oa_callback', DAY_IN_SECONDS );
		}
		$existing = get_option( $key, 0 );
		if ( $existing && (int) $existing > time() - DAY_IN_SECONDS ) {
			return false;
		}
		if ( $existing ) {
			delete_option( $key );
		}
		return add_option( $key, time(), '', 'no' );
	}
}
