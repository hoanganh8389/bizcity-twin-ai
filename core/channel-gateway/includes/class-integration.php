<?php
/**
 * BizCity Integration — Abstract Base Class
 *
 * Foundation for all 3rd-party service integrations (email, calendar, messenger, DB).
 * Each integration defines its settings schema, connection test, and encrypted storage.
 *
 * Plugins register integrations via:
 *   add_action('bizcity_register_integrations', function($registry){ $registry->register(new My_Integration()); });
 *
 * Design inspired by WAIC's integration system but standardized for the BizCity platform.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;

abstract class BizCity_Integration {

	protected string $code     = '';
	protected string $category = '';
	protected string $logo     = '';
	protected string $name     = '';
	protected string $desc     = '';
	protected int    $order    = 50;

	/** @var array Field schema {type, label, default, encrypt?, show?, options?, plh?, readonly?} */
	protected array $settings = [];

	/** @var array Fields stored privately (never exposed to front-end) */
	protected array $private_params = [];

	/** @var array Fields whose change signals a re-auth (clear private params) */
	protected array $signal_params = [];

	/** @var array|null Current account data */
	protected ?array $account = null;

	/* ── Encryption ── */
	private string $cipher = 'AES-256-CBC';

	/* ─── Getters ─── */

	public function get_code(): string     { return $this->code; }
	public function get_category(): string { return $this->category; }
	public function get_logo(): string     { return $this->logo; }
	public function get_name(): string     { return $this->name; }
	public function get_desc(): string     { return $this->desc; }
	public function get_order(): int       { return $this->order; }

	/**
	 * Return settings schema.
	 * Override this to build dynamic settings (e.g. redirect_uri based on home_url).
	 */
	public function get_settings(): array {
		return $this->settings;
	}

	/* ─── Account Binding ─── */

	public function set_account( ?array $account ): void {
		$this->account = $account;
	}

	public function get_account(): array {
		return $this->account ?? [];
	}

	public function get_param( string $key, $default = '' ) {
		return $this->account[ $key ] ?? $default;
	}

	/* ─── Encryption ─── */

	private function get_encryption_key(): string {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'bizcity-integ-default-salt';
		return substr( hash( 'sha256', $this->code . $salt, true ), 0, 32 );
	}

	private function get_encryption_iv(): string {
		$salt = defined( 'NONCE_SALT' ) ? NONCE_SALT : 'bizcity-integ-default-iv';
		return substr( hash( 'sha256', $salt . $this->code, true ), 0, 16 );
	}

	public function encrypt_value( string $value ): string {
		if ( $value === '' ) {
			return '';
		}
		$encrypted = openssl_encrypt( $value, $this->cipher, $this->get_encryption_key(), 0, $this->get_encryption_iv() );
		return $encrypted !== false ? base64_encode( $encrypted ) : '';
	}

	public function decrypt_value( string $value ): string {
		if ( $value === '' ) {
			return '';
		}
		$decoded = base64_decode( $value, true );
		if ( $decoded === false ) {
			return $value; // Already plain text
		}
		$decrypted = openssl_decrypt( $decoded, $this->cipher, $this->get_encryption_key(), 0, $this->get_encryption_iv() );
		return $decrypted !== false ? $decrypted : $value;
	}

	/**
	 * Get account params with encrypted fields encrypted for storage.
	 */
	public function get_encrypted_params(): array {
		$params   = $this->get_account();
		$settings = $this->get_settings();

		if ( ! empty( $params['_encrypted'] ) ) {
			return $params;
		}

		foreach ( $params as $key => $value ) {
			if ( ! is_string( $value ) || $value === '' ) {
				continue;
			}
			$needs_encrypt = in_array( $key, $this->private_params, true )
			              || ( isset( $settings[ $key ]['encrypt'] ) && $settings[ $key ]['encrypt'] );
			if ( $needs_encrypt ) {
				$params[ $key ] = $this->encrypt_value( $value );
			}
		}
		$params['_encrypted'] = 1;
		return $params;
	}

	/**
	 * Get account params with encrypted fields decrypted.
	 *
	 * @param bool $include_private Whether to include private params.
	 */
	public function get_decrypted_params( bool $include_private = true ): array {
		$params   = $this->get_account();
		$settings = $this->get_settings();

		if ( empty( $params['_encrypted'] ) ) {
			$result = $params;
		} else {
			foreach ( $params as $key => $value ) {
				if ( ! is_string( $value ) || $value === '' ) {
					continue;
				}
				$needs_decrypt = in_array( $key, $this->private_params, true )
				              || ( isset( $settings[ $key ]['encrypt'] ) && $settings[ $key ]['encrypt'] );
				if ( $needs_decrypt ) {
					$params[ $key ] = $this->decrypt_value( $value );
				}
			}
			$params['_encrypted'] = 0;
			$result = $params;
		}

		if ( ! $include_private ) {
			foreach ( $this->private_params as $key ) {
				unset( $result[ $key ] );
			}
		}

		return $result;
	}

	/**
	 * Get a decrypted param value.
	 */
	public function get_decrypted_param( string $key, string $default = '' ): string {
		$params = $this->get_decrypted_params();
		return $params[ $key ] ?? $default;
	}

	/* ─── Connection Test ─── */

	/**
	 * Test the connection. Override for real testing (API call, SMTP handshake, etc.)
	 * Must set _status (1=connected, 7=error) and _status_error.
	 */
	public function do_test(): void {
		$this->account['_status']       = 1;
		$this->account['_status_error'] = '';
	}

	/**
	 * Check if account is connected.
	 */
	public function is_connected(): bool {
		return ( (int) ( $this->account['_status'] ?? 0 ) ) === 1;
	}

	/* ─── Signal Params (Re-auth Detection) ─── */

	/**
	 * Merge private params from old account when signal params haven't changed.
	 */
	public function merge_private_from_old( array $old_account ): void {
		if ( empty( $this->signal_params ) || empty( $this->private_params ) ) {
			return;
		}

		$changed = false;
		foreach ( $this->signal_params as $key ) {
			if ( ( $this->get_param( $key ) ) !== ( $old_account[ $key ] ?? '' ) ) {
				$changed = true;
				break;
			}
		}

		if ( ! $changed ) {
			foreach ( $this->private_params as $key ) {
				if ( isset( $old_account[ $key ] ) && ! isset( $this->account[ $key ] ) ) {
					$this->account[ $key ] = $old_account[ $key ];
				}
			}
		}
	}

	/* ─── Serialization ─── */

	/**
	 * Export integration metadata (for admin UI rendering).
	 */
	public function to_admin_array(): array {
		return [
			'code'     => $this->code,
			'category' => $this->category,
			'logo'     => $this->logo,
			'name'     => $this->name,
			'desc'     => $this->desc,
			'settings' => $this->get_settings(),
			'order'    => $this->order,
		];
	}
}
