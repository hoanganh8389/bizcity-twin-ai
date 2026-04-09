<?php
/**
 * Integration: Zalo OA — Zalo Official Account API.
 *
 * Connects a Zalo OA for sending messages, broadcasts, and receiving webhooks.
 * Credentials obtained at https://developers.zalo.me/
 *
 * @package BizCity_Twin_AI
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Integration_Zalo extends BizCity_Integration {

	protected string $code     = 'zalo';
	protected string $category = 'messenger';
	protected string $logo     = 'ZL';
	protected string $name     = 'Zalo OA';
	protected string $desc     = 'Kết nối Zalo Official Account qua Zalo API';
	protected int    $order    = 5;

	protected array $private_params = [ '_refresh_token', '_access_token', '_expires_at' ];
	protected array $signal_params  = [ 'oa_code' ];

	public function get_settings(): array {
		$redirect_uri = home_url( '/wp-json/bizcity/v1/oauth2callback?provider=zalo' );

		return [
			'name'          => [ 'type' => 'input',  'label' => 'Tên OA',       'plh' => 'Ví dụ: OA Chính', 'default' => '' ],
			'app_id'        => [ 'type' => 'input',  'label' => 'App ID *',     'plh' => 'App ID trên Zalo Developers', 'default' => '' ],
			'app_secret'    => [ 'type' => 'input',  'label' => 'App Secret *', 'encrypt' => true, 'plh' => 'App Secret trên Zalo Developers', 'default' => '' ],
			'oa_id'         => [ 'type' => 'input',  'label' => 'OA ID',        'plh' => 'ID OA (lấy từ Zalo Dashboard)', 'default' => '' ],
			'redirect_uri'  => [ 'type' => 'input',  'label' => 'Redirect URI', 'readonly' => true, 'default' => $redirect_uri ],
			'oauth2'        => [
				'type'      => 'button',
				'label'     => '',
				'btn_label' => 'Cấp quyền OA →',
				'link'      => 'https://oauth.zaloapp.com/v4/oa/permission?app_id={app_id}&redirect_uri={redirect_uri}&state=zalo_oa',
			],
			'oa_code'       => [ 'type' => 'hidden', 'label' => '', 'default' => '' ],
		];
	}

	public function do_test(): void {
		$app_id     = $this->get_param( 'app_id' );
		$app_secret = $this->get_decrypted_param( 'app_secret' );

		if ( empty( $app_id ) || empty( $app_secret ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = 'App ID và App Secret là bắt buộc';
			return;
		}

		// Try to get/refresh OA access token.
		$access_token  = $this->get_decrypted_param( '_access_token' );
		$refresh_token = $this->get_decrypted_param( '_refresh_token' );
		$oa_code       = $this->get_param( 'oa_code' );

		if ( empty( $access_token ) && empty( $oa_code ) && empty( $refresh_token ) ) {
			$this->account['_status']       = 0;
			$this->account['_status_error'] = 'Chưa cấp quyền OA. Nhấn "Cấp quyền OA" ở trên.';
			return;
		}

		// If we have a fresh oa_code, exchange it for tokens.
		if ( ! empty( $oa_code ) ) {
			$token_result = $this->exchange_code( $app_id, $app_secret, $oa_code );
			if ( is_string( $token_result ) ) {
				$this->account['_status']       = 7;
				$this->account['_status_error'] = $token_result;
				return;
			}
			$access_token                    = $token_result['access_token'];
			$this->account['_access_token']  = $access_token;
			$this->account['_refresh_token'] = $token_result['refresh_token'] ?? '';
			$this->account['_expires_at']    = time() + ( $token_result['expires_in'] ?? 86400 );
			$this->account['oa_code']        = ''; // consumed
		}

		// Verify the access token by calling getprofile.
		$resp = wp_remote_get( 'https://openapi.zalo.me/v3.0/oa/getprofile', [
			'headers' => [ 'access_token' => $access_token ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $resp ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = $resp->get_error_message();
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! isset( $body['data']['oa_id'] ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = $body['message'] ?? 'Invalid access token';
			return;
		}

		// Store discovered OA ID if not yet set.
		if ( empty( $this->account['oa_id'] ) ) {
			$this->account['oa_id'] = $body['data']['oa_id'];
		}

		$this->account['_status']       = 1;
		$this->account['_status_error'] = '';
	}

	/**
	 * Exchange authorization code for OA access/refresh tokens.
	 *
	 * @param string $app_id
	 * @param string $app_secret
	 * @param string $code
	 * @return array|string  Token array on success, error string on failure.
	 */
	private function exchange_code( string $app_id, string $app_secret, string $code ): array|string {
		$resp = wp_remote_post( 'https://oauth.zaloapp.com/v4/oa/access_token', [
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'secret_key'   => $app_secret,
			],
			'body' => [
				'app_id'       => $app_id,
				'code'         => $code,
				'grant_type'   => 'authorization_code',
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $resp ) ) {
			return $resp->get_error_message();
		}

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			return $data['error_description'] ?? $data['message'] ?? 'Token exchange failed';
		}

		return $data;
	}
}
