<?php
/**
 * Remote Zalo Hub API client — LC-5/LC-6/B3.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Hub_Client', false ) ) {
	return;
}

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-B3 — normalize every remote response through one LC-5/LC-6 client boundary.
final class BizCity_Remote_Zalo_Hub_Client {

	const PROFILE_VERSION = 'client-v1@2026-09-28';

	/** @var array{base_url:string,key:string}|null Candidate credentials for E3 (probe-before-persist); null reads the saved store. */
	private $override = null;

	/**
	 * [2026-09-29 Claude Sonnet 5] PHASE-0.82 E3 — build a client that authenticates with a CANDIDATE base
	 * URL + key, never with what is currently saved. Used by the settings REST owner to probe an in-flight
	 * "save and check" attempt before persisting it, so a failed probe never touches the working credentials.
	 */
	public static function with_credentials( string $base_url, string $key ): self {
		$client = new self();
		$client->override = array( 'base_url' => rtrim( $base_url, '/' ), 'key' => $key );
		return $client;
	}

	public function list_accounts(): array {
		$result = $this->request( 'GET', '/accounts' );
		if ( ! empty( $result['ok'] ) && ( ! is_array( $result['data'] ?? null ) || ! is_array( $result['data']['items'] ?? null ) ) ) {
			$result['ok'] = false;
			$result['error'] = array( 'upstream_code' => 'invalid_json_profile', 'code' => 'remote_profile_drift', 'retryable' => false, 'retry_after' => null );
		}
		return $result;
	}

	public function list_threads( string $account, array $query = array() ): array {
		return $this->request( 'GET', '/accounts/' . rawurlencode( $this->account_id( $account ) ) . '/threads', array( 'query' => $query ) );
	}

	public function list_messages( string $account, string $thread, ?string $before = null, int $limit = 50 ): array {
		$query = array( 'limit' => max( 1, min( 100, $limit ) ) );
		if ( null !== $before && '' !== $before ) { $query['before'] = $before; }
		return $this->request( 'GET', '/accounts/' . rawurlencode( $this->account_id( $account ) ) . '/threads/' . rawurlencode( $thread ) . '/messages', array( 'query' => $query ) );
	}

	public function poll_events( $after = 'latest', int $limit = 100, array $filters = array() ): array {
		$query = array( 'after' => (string) $after, 'limit' => max( 1, min( 100, $limit ) ) );
		foreach ( array( 'account_id', 'event_type' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) { $query[ $key ] = (string) $filters[ $key ]; }
		}
		return $this->request( 'GET', '/events', array( 'query' => $query ) );
	}

	/** Start or force a provider-side QR login session. */
	public function login( string $account, bool $force = false ): array {
		// [2026-09-29 12:00 PM GitHub Copilot] HOTFIX-RZH-LOGIN-BODY — send an explicit boolean because the provider rejects an empty JSON object as invalid_body.
		return $this->request( 'POST', '/accounts/' . rawurlencode( $this->account_id( $account ) ) . '/login', array( 'body' => array( 'force' => $force ), 'rzh_operation' => 'send' ) );
	}

	/** Read the current provider-side QR login state. */
	public function login_status( string $account ): array {
		return $this->request( 'GET', '/accounts/' . rawurlencode( $this->account_id( $account ) ) . '/login' );
	}

	/**
	 * [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS4 (51 G1) — read the nick's bot config (needs scope agents:config).
	 * The envelope carries 'etag'; the body carries 'version' + 'editableFields'.
	 */
	public function get_agent( string $account ): array {
		return $this->request( 'GET', '/accounts/' . rawurlencode( $this->account_id( $account ) ) . '/agent' );
	}

	/**
	 * [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS4 (51 G1) — change ONLY the given fields, guarded by If-Match.
	 * Refused locally (no HTTP) without a version or with nothing to send: the provider would answer 428.
	 */
	public function patch_agent( string $account, array $fields, string $version ): array {
		if ( '' === trim( $version ) || ! $fields ) {
			return self::local_error( 'remote_contract_rejected', false );
		}
		return $this->request( 'PATCH', '/accounts/' . rawurlencode( $this->account_id( $account ) ) . '/agent', array(
			'body'          => $fields,
			'if_match'      => $version,
			'rzh_operation' => 'send',
		) );
	}

	public function send_message( string $account, string $thread, array $body, string $idempotency_key, string $request_id = '' ): array {
		// [2026-09-29 12:15 PM GitHub Copilot] PHASE-0.82-B3 — preserve the caller's stable idempotency key exactly and reject missing/oversized keys before HTTP.
		if ( '' === $idempotency_key || strlen( $idempotency_key ) > 200 ) {
			return self::local_error( 'remote_contract_rejected', false );
		}
		$payload = array( 'text' => (string) ( $body['text'] ?? '' ) );
		if ( isset( $body['mentions'] ) && is_array( $body['mentions'] ) && $body['mentions'] ) { $payload['mentions'] = $body['mentions']; }
		$pause = isset( $body['pauseBotMinutes'] ) ? (int) $body['pauseBotMinutes'] : 0;
		if ( $pause > 0 ) { $payload['pauseBotMinutes'] = min( 1440, $pause ); }
		return $this->request( 'POST', '/accounts/' . rawurlencode( $this->account_id( $account ) ) . '/threads/' . rawurlencode( $thread ) . '/messages', array(
			'body'            => $payload,
			'idempotency_key' => $idempotency_key,
			'request_id'      => $request_id,
			'rzh_operation'   => 'send',
		) );
	}

	private function request( string $method, string $path, array $options = array() ): array {
		if ( ! class_exists( 'BizCity_Remote_Zalo_Credentials' ) || ! class_exists( 'BizCity_Remote_Zalo_Http' ) ) {
			return self::local_error( 'remote_client_unavailable', false );
		}
		$base = null !== $this->override ? $this->override['base_url'] : rtrim( BizCity_Remote_Zalo_Credentials::base_url(), '/' );
		$key  = null !== $this->override ? $this->override['key'] : BizCity_Remote_Zalo_Credentials::key();
		if ( '' === $base || '' === $key ) { return self::local_error( 'remote_not_configured', false ); }
		$query = is_array( $options['query'] ?? null ) ? $options['query'] : array();
		$url = $base . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$request_id = self::request_id( (string) ( $options['request_id'] ?? '' ) );
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
				'X-Request-Id'  => $request_id,
			),
			'rzh_operation' => $options['rzh_operation'] ?? '',
		);
		$verb = strtoupper( $method );
		if ( in_array( $verb, array( 'POST', 'PATCH' ), true ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $options['body'] ?? array() );
		}
		if ( 'POST' === $verb ) {
			$args['headers']['Idempotency-Key'] = (string) ( $options['idempotency_key'] ?? '' );
		}
		// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS4 — optimistic concurrency for PATCH /agent (guide (2) §3.3).
		if ( isset( $options['if_match'] ) && '' !== (string) $options['if_match'] ) {
			$args['headers']['If-Match'] = '"' . trim( (string) $options['if_match'], '"' ) . '"';
		}
		$response = BizCity_Remote_Zalo_Http::request( $method, $url, $args );
		if ( empty( $response['ok'] ) ) {
			$error = self::error( (string) ( $response['code'] ?? 'remote_unreachable' ), (int) ( $response['status'] ?? 0 ), '' );
			if ( '' !== (string) ( $response['transport_code'] ?? '' ) ) { $error['transport_code'] = (string) $response['transport_code']; }
			return self::envelope( false, (int) ( $response['status'] ?? 0 ), null, $error, $request_id, false );
		}
		$status = (int) ( $response['status'] ?? 0 );
		$headers = is_array( $response['headers'] ?? null ) ? $response['headers'] : array();
		$data = json_decode( (string) ( $response['body'] ?? '' ), true );
		$data = is_array( $data ) ? ( $data['body'] ?? $data ) : null;
		$replayed = 'true' === strtolower( self::header( $headers, 'Idempotency-Replayed' ) );
		$reply_id = self::header( $headers, 'X-Request-Id' );
		$request_id = '' !== $reply_id ? substr( $reply_id, 0, 100 ) : $request_id;
		// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS4 — additive: the ETag of GET/PATCH /agent (quotes stripped).
		$etag = trim( self::header( $headers, 'ETag' ), " \"" );
		if ( $status >= 200 && $status < 300 ) {
			return array( 'etag' => $etag ) + self::envelope( true, $status, $data, null, $request_id, $replayed );
		}
		$upstream = is_array( $data['error'] ?? null ) ? (string) ( $data['error']['code'] ?? '' ) : '';
		return array( 'etag' => $etag ) + self::envelope( false, $status, $data, self::error( $upstream, $status, self::header( $headers, 'Retry-After' ) ), $request_id, $replayed );
	}

	private function account_id( string $account ): string { return 0 === strpos( $account, 'rzh:' ) ? substr( $account, 4 ) : $account; }
	private static function request_id( string $id ): string { $id = substr( preg_replace( '/[^A-Za-z0-9._:-]/', '', $id ), 0, 100 ); return '' !== $id ? $id : 'rzh-' . substr( hash( 'sha256', uniqid( '', true ) ), 0, 24 ); }
	private static function header( array $headers, string $name ): string { foreach ( $headers as $key => $value ) { if ( strtolower( (string) $key ) === strtolower( $name ) ) { return is_array( $value ) ? (string) reset( $value ) : (string) $value; } } return ''; }
	private static function envelope( bool $ok, int $status, ?array $data, ?array $error, string $request_id, bool $replayed ): array { return array( 'ok' => $ok, 'http_status' => $status, 'data' => $data, 'error' => $error, 'request_id' => $request_id, 'replayed' => $replayed ); }
	private static function local_error( string $code, bool $retryable ): array { return self::envelope( false, 0, null, array( 'upstream_code' => '', 'code' => $code, 'retryable' => $retryable, 'retry_after' => null ), '', false ); }
	private static function error( string $upstream, int $status, string $retry_after ): array {
		$map = array(
			'401' => array( 'remote_auth_failed', false ), '403' => array( 'remote_scope_missing', false ), '404' => array( 'remote_not_found', false ),
			'410' => array( 'remote_cursor_expired', false ), '429' => array( 'remote_throttled', true ), '500' => array( 'remote_server_error', true ), '502' => array( 'remote_send_failed', true ),
			'400' => array( 'remote_contract_rejected', false ), 'idempotency_in_progress' => array( 'remote_send_in_progress', true ), 'idempotency_outcome_unknown' => array( 'remote_outcome_unknown', false ),
			'idempotency_conflict' => array( 'remote_idempotency_conflict', false ), 'account_not_running' => array( 'remote_account_stopped', true ),
			'remote_unreachable' => array( 'remote_unreachable', true ), 'remote_response_too_large' => array( 'remote_unreachable', true ), 'remote_redirect_refused' => array( 'remote_unreachable', false ),
			'invalid_api_key' => array( 'remote_auth_failed', false ), 'invalid_key' => array( 'remote_auth_failed', false ), 'missing_api_key' => array( 'remote_auth_failed', false ), 'bad_api_key' => array( 'remote_auth_failed', false ),
			'invalid_body' => array( 'remote_contract_rejected', false ), 'invalid_query' => array( 'remote_contract_rejected', false ), 'invalid_request' => array( 'remote_contract_rejected', false ),
			// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS4 — agent config errors (guide (2) §3.3/§8, 51 §2).
			'field_not_editable' => array( 'remote_agent_field_locked', false ), 'agent_shared' => array( 'remote_agent_shared', false ),
			'version_conflict' => array( 'remote_agent_version_conflict', true ), 'precondition_required' => array( 'remote_contract_rejected', false ),
			'412' => array( 'remote_agent_version_conflict', true ), '428' => array( 'remote_contract_rejected', false ),
			'bad_request' => array( 'remote_contract_rejected', false ), 'remote_host_blocked' => array( 'remote_host_blocked', false ), 'remote_dns_failed' => array( 'remote_host_blocked', false ), 'remote_host_unsafe' => array( 'remote_host_blocked', false ),
		);
		$chosen = $map[ $upstream ] ?? $map[ (string) $status ] ?? ( 400 === $status ? array( 'remote_contract_rejected', false ) : array( 'remote_unknown_error', false ) );
		$retry = is_numeric( $retry_after ) ? max( 1, min( 3600, (int) $retry_after ) ) : null;
		return array( 'upstream_code' => substr( preg_replace( '/[^A-Za-z0-9_.-]/', '', $upstream ), 0, 80 ), 'code' => $chosen[0], 'retryable' => $chosen[1], 'retry_after' => $retry );
	}
}
