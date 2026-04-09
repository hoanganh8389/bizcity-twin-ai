<?php
/**
 * Integration: Facebook — Facebook Page Messaging via Graph API.
 *
 * Supports Facebook Page access token for sending messages via Messenger API.
 * Credentials obtained at https://developers.facebook.com/
 *
 * @package BizCity_Twin_AI
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Integration_Facebook extends BizCity_Integration {

	protected string $code     = 'facebook';
	protected string $category = 'messenger';
	protected string $logo     = 'FB';
	protected string $name     = 'Facebook';
	protected string $desc     = 'Kết nối Facebook Page qua Graph API';
	protected int    $order    = 6;

	protected array $private_params = [ '_user_token', '_page_token' ];
	protected array $signal_params  = [ 'fb_code' ];

	public function get_settings(): array {
		$redirect_uri = home_url( '/wp-json/bizcity/v1/oauth2callback?provider=facebook' );
		$scope        = 'pages_messaging,pages_show_list,pages_read_engagement';

		return [
			'name'          => [ 'type' => 'input',  'label' => 'Tên Page',      'plh' => 'Ví dụ: Fanpage Chính', 'default' => '' ],
			'app_id'        => [ 'type' => 'input',  'label' => 'App ID *',      'plh' => 'Facebook App ID', 'default' => '' ],
			'app_secret'    => [ 'type' => 'input',  'label' => 'App Secret *',  'encrypt' => true, 'plh' => 'Facebook App Secret', 'default' => '' ],
			'page_id'       => [ 'type' => 'input',  'label' => 'Page ID',       'plh' => 'Numeric Facebook Page ID', 'default' => '' ],
			'redirect_uri'  => [ 'type' => 'input',  'label' => 'Redirect URI',  'readonly' => true, 'default' => $redirect_uri ],
			'oauth2'        => [
				'type'      => 'button',
				'label'     => '',
				'btn_label' => 'Đăng nhập Facebook →',
				'link'      => 'https://www.facebook.com/v19.0/dialog/oauth?client_id={app_id}&redirect_uri={redirect_uri}&scope=' . rawurlencode( $scope ) . '&response_type=code&state=facebook_page',
			],
			'fb_code'          => [ 'type' => 'hidden', 'label' => '', 'default' => '' ],
			'verify_token'     => [ 'type' => 'input',  'label' => 'Webhook Verify Token', 'plh' => 'Chuỗi bí mật bạn tự đặt cho webhook', 'default' => '' ],
			'_info_webhook'    => [
				'type'    => 'html',
				'label'   => 'Webhook URL',
				'content' => '<code>' . esc_url( home_url( '/wp-json/bizcity/v1/webhook/facebook' ) ) . '</code>',
			],
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

		$page_token = $this->get_decrypted_param( '_page_token' );
		$fb_code    = $this->get_param( 'fb_code' );

		if ( empty( $page_token ) && empty( $fb_code ) ) {
			$this->account['_status']       = 0;
			$this->account['_status_error'] = 'Chưa kết nối. Nhấn "Đăng nhập Facebook" ở trên.';
			return;
		}

		// Exchange code → user token → page token.
		if ( ! empty( $fb_code ) ) {
			$redirect_uri = $this->get_param( 'redirect_uri', home_url( '/wp-json/bizcity/v1/oauth2callback?provider=facebook' ) );
			$user_token   = $this->exchange_user_token( $app_id, $app_secret, $fb_code, $redirect_uri );
			if ( is_string( $user_token ) ) {
				$this->account['_status']       = 7;
				$this->account['_status_error'] = $user_token;
				return;
			}

			$page_id = $this->get_param( 'page_id' );
			if ( ! empty( $page_id ) ) {
				$pt = $this->get_page_token( $user_token, $page_id );
				if ( ! is_string( $pt ) ) {
					$this->account['_page_token'] = $pt['access_token'] ?? '';
					$page_token                  = $this->account['_page_token'];
				}
			}

			$this->account['_user_token'] = $user_token;
			$this->account['fb_code']     = ''; // consumed
		}

		// Verify page token by calling /me.
		if ( ! empty( $page_token ) ) {
			$resp = wp_remote_get( 'https://graph.facebook.com/v19.0/me?fields=id,name&access_token=' . rawurlencode( $page_token ), [
				'timeout' => 10,
			] );
			if ( is_wp_error( $resp ) ) {
				$this->account['_status']       = 7;
				$this->account['_status_error'] = $resp->get_error_message();
				return;
			}
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( empty( $body['id'] ) ) {
				$this->account['_status']       = 7;
				$this->account['_status_error'] = $body['error']['message'] ?? 'Invalid page token';
				return;
			}
			if ( empty( $this->account['page_id'] ) ) {
				$this->account['page_id'] = $body['id'];
			}
		}

		$this->account['_status']       = 1;
		$this->account['_status_error'] = '';
	}

	/**
	 * Exchange authorization code for a short-lived user access token.
	 */
	private function exchange_user_token( string $app_id, string $app_secret, string $code, string $redirect_uri ): string|array {
		$resp = wp_remote_get( add_query_arg( [
			'client_id'     => $app_id,
			'redirect_uri'  => $redirect_uri,
			'client_secret' => $app_secret,
			'code'          => $code,
		], 'https://graph.facebook.com/v19.0/oauth/access_token' ), [ 'timeout' => 15 ] );

		if ( is_wp_error( $resp ) ) {
			return $resp->get_error_message();
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			return $data['error']['message'] ?? 'User token exchange failed';
		}
		return $data['access_token'];
	}

	/**
	 * Get page-scoped token for the given Page ID.
	 */
	private function get_page_token( string $user_token, string $page_id ): array|string {
		$resp = wp_remote_get( "https://graph.facebook.com/v19.0/{$page_id}?fields=access_token&access_token=" . rawurlencode( $user_token ), [
			'timeout' => 10,
		] );
		if ( is_wp_error( $resp ) ) {
			return $resp->get_error_message();
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			return $data['error']['message'] ?? 'Page token fetch failed';
		}
		return $data;
	}
}
