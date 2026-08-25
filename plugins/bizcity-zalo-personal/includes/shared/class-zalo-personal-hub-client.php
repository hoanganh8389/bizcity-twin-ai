<?php
/**
 * BizCity Zalo Personal — Branch 19 client wrapper.
 *
 * All managed bridge calls cross the existing BizCity_LLM_Client boundary.
 * This class never reads Hub sitemeta and never exposes the Hub service secret.
 *
 * @package BizCity_Zalo_Personal
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Personal_Hub_Client', false ) ) {
	return;
}

final class BizCity_Zalo_Personal_Hub_Client {

	const CALLBACK_TOKENS_OPTION = 'bizcity_zalo_managed_callback_tokens';
	const CLIENT_INSTANCE_OPTION = 'bizcity_zalo_client_instance_id';
	const TENANT_KEY_OPTION      = 'bizcity_zalo_tenant_key';
	const CALLBACK_PREFIX        = 'zcb1_';
	const CALLBACK_CONTEXT       = 'zalo-personal-callback';

	private static $instance = null;

	/** Return the process-local managed Hub client singleton. */
	public static function instance(): self {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — provide the singleton boundary used by bridge, /gpt/, and diagnostics callsites.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Whether the client has the canonical 1API boundary and a key. */
	public function is_ready_fast(): bool {
		// [2026-08-22 Johnny Chu] R-GW-8/R-1API-AUTH — managed mode requires the canonical client and opaque BizCity key.
		return class_exists( 'BizCity_LLM_Client' ) && BizCity_LLM_Client::instance()->get_api_key( false ) !== '';
	}

	/** Get managed sidecar health through the Hub. */
	public function health(): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — managed health stays behind same-origin client proxy boundary.
		return $this->get( '/zalo-personal-bridge/health' );
	}

	/** Read redacted managed bridge diagnostics through the Hub relay. */
	public function diagnostics( array $args = array() ): array {
		// [2026-08-23 Johnny Chu] PHASE-0.39E — keep managed diagnostics behind the exact-key Hub boundary.
		$path = '/zalo-personal-bridge/diagnostics';
		$query = array();
		foreach ( array( 'account_id', 'before_id', 'since', 'level', 'phase', 'trace_id', 'limit' ) as $key ) {
			if ( isset( $args[ $key ] ) && $args[ $key ] !== '' ) {
				$query[ $key ] = sanitize_text_field( (string) $args[ $key ] );
			}
		}
		return $this->get( $query ? $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : $path );
	}

	/** Get the exact current-blog API key's managed Zalo capability. */
	public function capability(): array {
		// [2026-08-22 Johnny Chu] R-B2B2C — client capability projection must use current-blog key only.
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $this->degraded( 'llm_client_missing' );
		}
		$config = BizCity_LLM_Client::instance()->get_plan_config( array( 'allow_main_site_fallback' => false ) );
		if ( is_wp_error( $config ) ) {
			return array( 'success' => false, '_degraded' => true, 'code' => $config->get_error_code(), 'message' => 'Chưa đọc được quyền Zalo Personal từ BizCity Hub.', 'hint' => 'Cấu hình API key riêng trên blog hiện tại rồi thử lại.', 'help_code' => 'api_key_missing' );
		}
		$channel = isset( $config['channels']['zalo_personal'] ) && is_array( $config['channels']['zalo_personal'] )
			? $config['channels']['zalo_personal']
			: array( 'allowed' => false, 'account_limit' => 0, 'accounts_used' => 0, 'accounts_remaining' => 0, 'source' => 'missing' );
		return array( 'success' => true, 'capability' => $channel, 'master_level' => (string) ( $config['master_level'] ?? 'free' ) );
	}

	/** List only accounts assigned to this exact API key at the Hub. */
	public function list_accounts(): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — account list is key-scoped by Branch 19.
		return $this->get( '/zalo-personal-bridge/accounts' );
	}

	/** Create a managed Personal account and retain only its encrypted callback credential locally. */
	public function create_account( array $data ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — provision managed account and callback URL through 1API.
		$data['callback_url'] = rest_url( 'bizcity-channel/v1/zalo-bridge/inbound' );
		$data['client_request_id'] = sanitize_key( (string) ( $data['client_request_id'] ?? wp_generate_uuid4() ) );
		// [2026-08-23 Johnny Chu] R-GW-8 — preserve standalone B2 installation identity through Managed provisioning.
		$data['client_instance_id'] = $this->client_instance_id();
		$data['tenant_key'] = $this->tenant_key();
		$result = $this->post( '/zalo-personal-bridge/accounts', $data );
		$account = isset( $result['account'] ) && is_array( $result['account'] ) ? $result['account'] : array();
		$account_id = (string) ( $account['id'] ?? '' );
		$callback_token = (string) ( $result['callback_token'] ?? '' );
		if ( ! empty( $result['success'] ) && $account_id !== '' && $callback_token !== '' && ! $this->save_callback_token( $account_id, $callback_token ) ) {
			return array( 'success' => false, '_degraded' => true, 'code' => 'callback_token_store_failed', 'message' => 'Không lưu được quyền nhận tin Zalo managed.', 'hint' => 'Không tiếp tục sử dụng tài khoản này; liên hệ quản trị viên để dọn kết nối rồi thử lại.', 'help_code' => 'zalo_bridge_bad_response' );
		}
		if ( ! empty( $result['success'] ) && ( $account_id === '' || $callback_token === '' ) ) {
			return array( 'success' => false, '_degraded' => true, 'code' => 'managed_callback_secret_missing', 'message' => 'Managed bridge chưa cấp quyền nhận tin.', 'hint' => 'Kiểm tra trạng thái Branch 19 tại Hub rồi thử lại.', 'help_code' => 'zalo_bridge_bad_response' );
		}
		unset( $result['callback_token'] );
		return $result;
	}

	private function client_instance_id(): string {
		// [2026-08-23 Johnny Chu] R-B2B2C — use a stable per-installation identity independent of blog_id.
		$current = sanitize_key( (string) get_option( self::CLIENT_INSTANCE_OPTION, '' ) );
		if ( $current !== '' ) {
			return $current;
		}
		$value = 'b2i_' . str_replace( '-', '', wp_generate_uuid4() );
		update_option( self::CLIENT_INSTANCE_OPTION, $value, false );
		return $value;
	}

	private function tenant_key(): string {
		// [2026-08-23 Johnny Chu] R-B2B2C — generate an opaque tenant correlation key once per installation.
		$current = sanitize_key( (string) get_option( self::TENANT_KEY_OPTION, '' ) );
		if ( $current !== '' ) {
			return $current;
		}
		$value = 'tnt_' . str_replace( '-', '', wp_generate_uuid4() );
		update_option( self::TENANT_KEY_OPTION, $value, false );
		return $value;
	}

	/** Delete a key-owned managed account. */
	public function delete_account( string $account_id ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — revoke managed account through Hub ownership boundary.
		$result = $this->delete( '/zalo-personal-bridge/accounts/' . rawurlencode( $account_id ) );
		if ( ! empty( $result['success'] ) ) {
			$this->delete_callback_token( $account_id );
		}
		return $result;
	}

	/** Start a QR session for an owned managed account. */
	public function start_qr( string $account_id ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — QR command is key/account scoped at the Hub.
		return $this->post( '/zalo-personal-bridge/accounts/' . rawurlencode( $account_id ) . '/qr', array() );
	}

	/** Poll QR/session status for an owned managed account. */
	public function get_qr_status( string $account_id ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — status projection contains no bridge credential.
		return $this->get( '/zalo-personal-bridge/accounts/' . rawurlencode( $account_id ) . '/qr-status' );
	}

	/** Enqueue Personal outbound through the managed Hub. */
	public function enqueue_outbound( string $account_id, string $recipient, string $text, string $type = 'text', array $attachments = array() ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — outbound remains inside exact key/account scope.
		return $this->post( '/zalo-personal-bridge/outbound', array(
			'account_id'  => $account_id,
			'recipient'   => $recipient,
			'text'        => $text,
			'type'        => $type,
			'attachments' => $attachments,
		) );
	}

	/** Internal delegation for the shared bridge client. */
	public function get_managed_path( string $path ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — preserve one wrapper boundary for managed reads.
		return $this->get( $path );
	}

	/** Internal delegation for the shared bridge client. */
	public function post_managed_path( string $path, array $body ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — preserve one wrapper boundary for managed mutations.
		return $this->post( $path, $body );
	}

	/** Internal delegation for the shared bridge client. */
	public function delete_managed_path( string $path ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — preserve one wrapper boundary for managed revocations.
		return $this->delete( $path );
	}

	/** Resolve the callback token for the managed inbound relay. */
	public function get_callback_token( string $account_id ): string {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — decrypt only the local tenant callback token at the inbound boundary.
		$tokens = (array) get_option( self::CALLBACK_TOKENS_OPTION, array() );
		$encoded = (string) ( $tokens[ $account_id ] ?? '' );
		if ( $encoded === '' || ! class_exists( 'BizCity_Codec' ) ) {
			return '';
		}
		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		$decoded = BizCity_Codec::decrypt_json_payload( $encoded, $key, self::CALLBACK_PREFIX, self::CALLBACK_CONTEXT );
		return is_array( $decoded ) ? (string) ( $decoded['token'] ?? '' ) : '';
	}

	private function save_callback_token( string $account_id, string $token ): bool {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — persist callback credential encrypted, never plaintext.
		if ( $account_id === '' || $token === '' || ! class_exists( 'BizCity_Codec' ) ) {
			return false;
		}
		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		$encoded = BizCity_Codec::encrypt_json_payload( array( 'token' => $token, 'account_id' => $account_id ), $key, self::CALLBACK_PREFIX, self::CALLBACK_CONTEXT );
		if ( $encoded === '' ) {
			return false;
		}
		$tokens = (array) get_option( self::CALLBACK_TOKENS_OPTION, array() );
		$tokens[ $account_id ] = $encoded;
		return false !== update_option( self::CALLBACK_TOKENS_OPTION, $tokens, false );
	}

	private function delete_callback_token( string $account_id ): void {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W7 — remove local callback capability after managed revoke.
		$tokens = (array) get_option( self::CALLBACK_TOKENS_OPTION, array() );
		if ( isset( $tokens[ $account_id ] ) ) {
			unset( $tokens[ $account_id ] );
			update_option( self::CALLBACK_TOKENS_OPTION, $tokens, false );
		}
	}

	private function get( string $path ): array {
		// [2026-08-22 Johnny Chu] R-GW-8 — all managed reads use BizCity_LLM_Client gateway_get.
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'api_key_missing' );
		}
		$result = BizCity_LLM_Client::instance()->gateway_get( $path, array(), 'GET', 10, false );
		return is_array( $result ) ? $result : $this->degraded( 'managed_bridge_invalid_response' );
	}

	private function post( string $path, array $body ): array {
		// [2026-08-22 Johnny Chu] R-GW-8 — all managed mutations use BizCity_LLM_Client gateway_post.
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'api_key_missing' );
		}
		$result = BizCity_LLM_Client::instance()->gateway_post( $path, $body, 10, false );
		return is_array( $result ) ? $result : $this->degraded( 'managed_bridge_invalid_response' );
	}

	private function delete( string $path ): array {
		// [2026-08-22 Johnny Chu] R-GW-8 — managed revocation uses the existing generic DELETE gateway helper.
		if ( ! $this->is_ready_fast() ) {
			return $this->degraded( 'api_key_missing' );
		}
		$result = BizCity_LLM_Client::instance()->gateway_get( $path, array(), 'DELETE', 10, false );
		return is_array( $result ) ? $result : $this->degraded( 'managed_bridge_invalid_response' );
	}

	private function degraded( string $code ): array {
		return array( 'success' => false, '_degraded' => true, 'code' => $code, 'message' => 'BizCity managed Zalo chưa sẵn sàng.', 'hint' => 'Kiểm tra API key và trạng thái managed bridge rồi thử lại.', 'help_code' => 'zalo_bridge_unreachable' );
	}
}
