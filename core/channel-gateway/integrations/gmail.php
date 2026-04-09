<?php
/**
 * Integration: Gmail — Google Gmail API (OAuth2).
 *
 * @package BizCity_Twin_AI
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Integration_Gmail extends BizCity_Integration {

	protected string $code     = 'gmail';
	protected string $category = 'email';
	protected string $logo     = 'GM';
	protected string $name     = 'Gmail';
	protected string $desc     = 'Kết nối Gmail qua Google API';
	protected int    $order    = 2;

	protected array $private_params = [ '_refresh_token', '_access_token', '_expires_at' ];
	protected array $signal_params  = [ 'access_code' ];

	public function get_settings(): array {
		$redirect_uri = home_url( '/wp-json/bizcity/v1/oauth2callback?provider=gmail' );
		$scope        = 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly';

		return [
			'name'          => [ 'type' => 'input',  'label' => 'Tên cấu hình',  'plh' => 'Tên nội bộ để phân biệt', 'default' => '' ],
			'client_id'     => [ 'type' => 'input',  'label' => 'Client ID',     'default' => '' ],
			'client_secret' => [ 'type' => 'input',  'label' => 'Client Secret', 'encrypt' => true, 'default' => '' ],
			'redirect_uri'  => [ 'type' => 'input',  'label' => 'Redirect URI',  'readonly' => true, 'default' => $redirect_uri ],
			'from_email'    => [ 'type' => 'input',  'label' => 'From Email',    'plh' => 'your-email@gmail.com', 'default' => '' ],
			'from_name'     => [ 'type' => 'input',  'label' => 'From Name',     'default' => '' ],
			'oauth2'        => [
				'type'      => 'button',
				'label'     => '',
				'btn_label' => 'Kết nối tài khoản →',
				'link'      => 'https://accounts.google.com/o/oauth2/auth?client_id={client_id}&redirect_uri={redirect_uri}&response_type=code&scope=' . rawurlencode( $scope ) . '&access_type=offline&prompt=consent',
			],
			'access_code'   => [ 'type' => 'hidden', 'label' => '', 'default' => '' ],
		];
	}

	public function do_test(): void {
		$client_id     = $this->get_param( 'client_id' );
		$client_secret = $this->get_decrypted_param( 'client_secret' );
		$access_code   = $this->get_param( 'access_code' );
		$refresh_token = $this->get_decrypted_param( '_refresh_token' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = 'Client ID và Client Secret là bắt buộc';
			return;
		}

		if ( empty( $access_code ) && empty( $refresh_token ) ) {
			$this->account['_status']       = 0;
			$this->account['_status_error'] = 'Chưa kết nối OAuth';
			return;
		}

		// Try token exchange or refresh.
		$token = $this->get_access_token( $client_id, $client_secret, $access_code, $refresh_token );
		if ( is_string( $token ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = $token;
			return;
		}

		$this->account['_access_token']  = $token['access_token'];
		$this->account['_expires_at']    = time() + ( $token['expires_in'] ?? 3600 );
		if ( ! empty( $token['refresh_token'] ) ) {
			$this->account['_refresh_token'] = $token['refresh_token'];
		}
		$this->account['_status']       = 1;
		$this->account['_status_error'] = '';
	}

	private function get_access_token( string $client_id, string $secret, string $code, string $refresh ): array|string {
		$redirect_uri = $this->get_param( 'redirect_uri', home_url( '/wp-json/bizcity/v1/oauth2callback?provider=gmail' ) );

		if ( ! empty( $refresh ) ) {
			$body = [
				'client_id'     => $client_id,
				'client_secret' => $secret,
				'refresh_token' => $refresh,
				'grant_type'    => 'refresh_token',
			];
		} else {
			$body = [
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $secret,
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
			];
		}

		$resp = wp_remote_post( 'https://oauth2.googleapis.com/token', [
			'body'    => $body,
			'timeout' => 15,
		] );

		if ( is_wp_error( $resp ) ) {
			return $resp->get_error_message();
		}

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! empty( $data['error'] ) ) {
			return $data['error_description'] ?? $data['error'];
		}
		if ( empty( $data['access_token'] ) ) {
			return 'No access_token in response';
		}

		return $data;
	}
}
