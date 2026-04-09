<?php
/**
 * Integration: SMTP — Generic SMTP email sending.
 *
 * @package BizCity_Twin_AI
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Integration_Smtp extends BizCity_Integration {

	protected string $code     = 'smtp';
	protected string $category = 'email';
	protected string $logo     = 'SM';
	protected string $name     = 'SMTP';
	protected string $desc     = 'Kết nối máy chủ SMTP bất kỳ';
	protected int    $order    = 1;

	public function get_settings(): array {
		return [
			'name'       => [ 'type' => 'input',  'label' => 'Tên cấu hình',  'plh' => 'Tên nội bộ để phân biệt', 'default' => '' ],
			'host'       => [ 'type' => 'input',  'label' => 'SMTP Host *',   'plh' => 'smtp.gmail.com', 'default' => '' ],
			'port'       => [ 'type' => 'input',  'label' => 'Port *',        'default' => 587 ],
			'encryption' => [ 'type' => 'select', 'label' => 'Mã hóa',        'options' => [ '' => 'None', 'tls' => 'TLS', 'ssl' => 'SSL' ], 'default' => 'tls' ],
			'username'   => [ 'type' => 'input',  'label' => 'Username',      'plh' => 'your-email@domain.com', 'default' => '' ],
			'password'   => [ 'type' => 'input',  'label' => 'Password',      'encrypt' => true, 'default' => '' ],
			'from_email' => [ 'type' => 'input',  'label' => 'From Email',    'plh' => 'noreply@domain.com', 'default' => '' ],
			'from_name'  => [ 'type' => 'input',  'label' => 'From Name',     'default' => '' ],
		];
	}

	public function do_test(): void {
		$host     = $this->get_param( 'host' );
		$port     = (int) $this->get_param( 'port', '587' );
		$username = $this->get_param( 'username' );

		if ( empty( $host ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = 'SMTP Host is required';
			return;
		}

		// Quick socket test (non-blocking, 5s timeout).
		$fp = @fsockopen( $host, $port, $errno, $errstr, 5 );
		if ( $fp ) {
			fclose( $fp );
			$this->account['_status']       = 1;
			$this->account['_status_error'] = '';
		} else {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = "Cannot connect to {$host}:{$port} — {$errstr}";
		}
	}
}
