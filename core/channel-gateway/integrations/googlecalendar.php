<?php
/**
 * Integration: Google Calendar — Google Calendar API (OAuth2).
 *
 * @package BizCity_Twin_AI
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Integration_Googlecalendar extends BizCity_Integration {

	protected string $code     = 'googlecalendar';
	protected string $category = 'calendar';
	protected string $logo     = 'GC';
	protected string $name     = 'Google Calendar';
	protected string $desc     = 'Kết nối Google Calendar API (+ Google Meet)';
	protected int    $order    = 10;

	protected array $private_params = [ '_refresh_token', '_access_token', '_expires_at' ];
	protected array $signal_params  = [ 'access_code' ];

	public function get_settings(): array {
		$redirect_uri = home_url( '/wp-json/bizcity/v1/oauth2callback?provider=googlecalendar' );
		$scope        = 'https://www.googleapis.com/auth/calendar';

		return [
			'name'          => [ 'type' => 'input',  'label' => 'Tên cấu hình',  'plh' => 'Tên nội bộ để phân biệt', 'default' => '' ],
			'client_id'     => [ 'type' => 'input',  'label' => 'Client ID',     'default' => '' ],
			'client_secret' => [ 'type' => 'input',  'label' => 'Client Secret', 'encrypt' => true, 'default' => '' ],
			'redirect_uri'  => [ 'type' => 'input',  'label' => 'Redirect URI',  'readonly' => true, 'default' => $redirect_uri ],
			'calendar_id'   => [ 'type' => 'input',  'label' => 'Calendar ID',   'plh' => 'primary', 'default' => 'primary' ],
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
		$client_id = $this->get_param( 'client_id' );
		if ( empty( $client_id ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = 'Client ID là bắt buộc';
			return;
		}

		$access_code   = $this->get_param( 'access_code' );
		$refresh_token = $this->get_decrypted_param( '_refresh_token' );

		if ( empty( $access_code ) && empty( $refresh_token ) ) {
			$this->account['_status']       = 0;
			$this->account['_status_error'] = 'Chưa kết nối OAuth';
			return;
		}

		$this->account['_status']       = 1;
		$this->account['_status_error'] = '';
	}
}
