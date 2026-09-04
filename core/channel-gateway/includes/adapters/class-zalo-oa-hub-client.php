<?php
/**
 * BizCity Channel Gateway - Managed Zalo OA Hub client.
 *
 * B2 wrapper for Branch 20. It calls only BizCity_LLM_Client and stores only
 * an authenticated local callback-token envelope; provider tokens stay at B1.
 *
 * @package BizCity_Twin_AI
 * @since PHASE-0.44-ZALO-OA-DUAL-MODE
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_OA_Hub_Client', false ) ) {
	return;
}

final class BizCity_Zalo_OA_Hub_Client {

	const CALLBACK_TOKENS_OPTION = 'bizcity_zalo_oa_managed_callback_tokens';
	const CALLBACK_PREFIX        = 'bzoa_cb_';
	const CALLBACK_CONTEXT       = 'bizcity-zalo-oa-managed-callback';
	const CALLBACK_TOKEN_TTL     = 900;
	const HUB_PREFIX             = '/bizcity-hub/v1/zalo-oa-bridge';

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_ready_fast(): bool {
		// [2026-08-28 Johnny Chu] R-GW-8 — managed OA requires the current blog's explicit key.
		return class_exists( 'BizCity_LLM_Client' )
			&& BizCity_LLM_Client::instance()->get_api_key( false ) !== '';
	}

	public function health(): array {
		return $this->get( self::HUB_PREFIX . '/health' );
	}

	public function capability(): array {
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'api_key_missing' );
		}
		$config = BizCity_LLM_Client::instance()->get_plan_config( array( 'allow_main_site_fallback' => false ) );
		if ( is_wp_error( $config ) ) {
			return $this->degraded( 'entitlement_unavailable' );
		}
		$channel = isset( $config['channels']['zalo_oa_managed'] ) && is_array( $config['channels']['zalo_oa_managed'] )
			? $config['channels']['zalo_oa_managed']
			: array( 'allowed' => false, 'account_limit' => 0, 'accounts_used' => 0, 'accounts_remaining' => 0, 'source' => 'missing' );
		return array( 'success' => true, 'capability' => $channel, 'master_level' => (string) ( $config['master_level'] ?? 'free' ) );
	}

	public function connect_url( array $data ): array {
		// [2026-08-28 Johnny Chu] PHASE-0.44-ZALO-OA-DUAL-MODE — keep callback-token handoff server-side.
		$data['callback_url'] = rest_url( 'bizcity-channel/v1/zalo-oa-bridge/inbound' );
		$data['site_url'] = home_url( '/' );
		$data['client_instance_id'] = $this->client_instance_id();
		$data['tenant_key'] = $this->tenant_key();
		$data['client_request_id'] = $this->request_id( $data['client_request_id'] ?? '' );
		$result = $this->post( self::HUB_PREFIX . '/connect-url', $data );
		$pending_id = (int) ( $result['pending_id'] ?? 0 );
		$callback_token = (string) ( $result['callback_token_once'] ?? '' );
		if ( ! empty( $result['success'] ) && ( $pending_id <= 0 || $callback_token === '' || ! $this->save_callback_token( (string) $pending_id, $callback_token ) ) ) {
			return array( 'success' => false, '_degraded' => true, 'code' => 'gateway_degraded', 'message' => 'Không lưu được quyền callback Zalo OA.', 'hint' => 'Không tiếp tục cấp quyền; kiểm tra Codec và thử lại.', 'help_code' => 'gateway_degraded' );
		}
		$result['_client_request_id'] = $data['client_request_id'];
		if ( ! empty( $result['authorization_url'] ) ) {
			$result['qr_url'] = rtrim( BizCity_LLM_Client::instance()->get_gateway_url(), '/' ) . '/create-qr-code/?data=' . rawurlencode( (string) $result['authorization_url'] );
		}
		unset( $result['callback_token_once'] );
		return $result;
	}

	public function list_accounts(): array {
		return $this->get( self::HUB_PREFIX . '/accounts' );
	}

	public function account_status( int $account_id ): array {
		return $this->get( self::HUB_PREFIX . '/accounts/' . absint( $account_id ) . '/status' );
	}

	public function test_account( int $account_id ): array {
		return $this->post( self::HUB_PREFIX . '/accounts/' . absint( $account_id ) . '/test', array() );
	}

	public function revoke_account( int $account_id ): array {
		$result = $this->delete( self::HUB_PREFIX . '/accounts/' . absint( $account_id ) );
		if ( ! empty( $result['success'] ) ) {
			// [2026-08-28 Johnny Chu] PHASE-0.44-ZALO-OA-DUAL-MODE — remove only the revoked account's callback token.
			$this->delete_callback_token_for_account( $account_id );
		}
		return $result;
	}

	public function send( int $account_id, string $recipient_uid, array $message ): array {
		return $this->post( self::HUB_PREFIX . '/outbound', array(
			'account_id'   => absint( $account_id ),
			'recipient_uid' => sanitize_text_field( $recipient_uid ),
			'text'         => (string) ( $message['content'] ?? $message['text'] ?? '' ),
		) );
	}

	/** Return encrypted-token candidates for the B2 callback verifier. */
	public function callback_tokens(): array {
		$stored = get_option( self::CALLBACK_TOKENS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$accounts = class_exists( 'BizCity_Integration_Registry' ) ? BizCity_Integration_Registry::instance()->get_accounts( 'zalo_oa' ) : array();
		$out = array();
		foreach ( $stored as $pending_id => $encoded ) {
			$token = $this->decode_callback_token( (string) $encoded, (string) $pending_id );
			if ( $token !== '' ) {
				$oa_id = '';
				foreach ( $accounts as $account ) {
					if ( (string) ( $account['managed_pending_id'] ?? '' ) === (string) $pending_id && ! in_array( (string) ( $account['managed_status'] ?? 'oauth_pending' ), array( 'revoked', 'expired' ), true ) ) {
						$oa_id = (string) ( $account['managed_oa_id'] ?? $account['oa_id'] ?? '' );
						break;
					}
				}
				$out[ (string) $pending_id ] = array( 'pending_id' => (string) $pending_id, 'token' => $token, 'oa_id' => $oa_id );
			}
		}
		return $out;
	}

	private function save_callback_token( string $pending_id, string $token ): bool {
		if ( $pending_id === '' || $token === '' || ! class_exists( 'BizCity_Codec' ) ) {
			return false;
		}
		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		$encoded = BizCity_Codec::encrypt_json_payload( array( 'v' => 1, 'pending_id' => $pending_id, 'token' => $token, 'expires_at' => time() + self::CALLBACK_TOKEN_TTL ), $key, self::CALLBACK_PREFIX, self::CALLBACK_CONTEXT );
		if ( $encoded === '' ) {
			return false;
		}
		$stored = get_option( self::CALLBACK_TOKENS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored[ $pending_id ] = $encoded;
		return false !== update_option( self::CALLBACK_TOKENS_OPTION, $stored, false );
	}

	private function decode_callback_token( string $encoded, string $pending_id ): string {
		if ( $encoded === '' || $pending_id === '' || ! class_exists( 'BizCity_Codec' ) ) {
			return '';
		}
		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		$data = BizCity_Codec::decrypt_json_payload( $encoded, $key, self::CALLBACK_PREFIX, self::CALLBACK_CONTEXT );
		if ( ! is_array( $data ) || (string) ( $data['pending_id'] ?? '' ) !== $pending_id ) {
			return '';
		}
		return (int) ( $data['expires_at'] ?? 0 ) >= time() ? (string) ( $data['token'] ?? '' ) : '';
	}

	private function delete_callback_token_for_account( int $account_id ): void {
		// [2026-08-28 Johnny Chu] PHASE-0.44-ZALO-OA-DUAL-MODE — clean up only the revoked account callback token.
		if ( $account_id <= 0 || ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return;
		}
		$pending_id = '';
		foreach ( BizCity_Integration_Registry::instance()->get_accounts( 'zalo_oa' ) as $account ) {
			if ( (int) ( $account['managed_hub_account_id'] ?? 0 ) === $account_id ) {
				$pending_id = (string) ( $account['managed_pending_id'] ?? '' );
				break;
			}
		}
		if ( $pending_id === '' ) {
			return;
		}
		$stored = get_option( self::CALLBACK_TOKENS_OPTION, array() );
		if ( ! is_array( $stored ) || ! array_key_exists( $pending_id, $stored ) ) {
			return;
		}
		unset( $stored[ $pending_id ] );
		update_option( self::CALLBACK_TOKENS_OPTION, $stored, false );
	}

	private function client_instance_id(): string {
		$current = sanitize_key( (string) get_option( 'bizcity_zalo_oa_client_instance_id', '' ) );
		if ( $current !== '' ) {
			return $current;
		}
		$value = 'b2oa_' . str_replace( '-', '', wp_generate_uuid4() );
		update_option( 'bizcity_zalo_oa_client_instance_id', $value, false );
		return $value;
	}

	private function tenant_key(): string {
		$current = sanitize_key( (string) get_option( 'bizcity_zalo_oa_tenant_key', '' ) );
		if ( $current !== '' ) {
			return $current;
		}
		$value = 'tnt_' . str_replace( '-', '', wp_generate_uuid4() );
		update_option( 'bizcity_zalo_oa_tenant_key', $value, false );
		return $value;
	}

	private function request_id( string $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9_:.\-]/', '', $value );
		return substr( $value !== '' ? $value : 'req_' . wp_generate_uuid4(), 0, 128 );
	}

	private function get( string $path ): array {
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'api_key_missing' );
		}
		$result = BizCity_LLM_Client::instance()->gateway_get( $path, array(), 'GET', 10, false );
		return is_array( $result ) ? $result : $this->degraded( 'managed_invalid_response' );
	}

	private function post( string $path, array $body ): array {
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'api_key_missing' );
		}
		$result = BizCity_LLM_Client::instance()->gateway_post( $path, $body, 15, false );
		return is_array( $result ) ? $result : $this->degraded( 'managed_invalid_response' );
	}

	private function delete( string $path ): array {
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'api_key_missing' );
		}
		$result = BizCity_LLM_Client::instance()->gateway_get( $path, array(), 'DELETE', 15, false );
		return is_array( $result ) ? $result : $this->degraded( 'managed_invalid_response' );
	}

	private function degraded( string $code ): array {
		return array( 'success' => false, '_degraded' => true, 'code' => 'gateway_degraded', 'message' => 'BizCity Managed Zalo OA chưa sẵn sàng.', 'hint' => 'Kiểm tra API key và trạng thái Branch 20 rồi thử lại.', 'help_code' => 'gateway_degraded', 'context' => array( 'reason' => sanitize_key( $code ) ) );
	}
}
