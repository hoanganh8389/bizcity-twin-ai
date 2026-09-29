<?php
/**
 * Single HTTP seam for Remote Zalo Hub requests.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Http', false ) ) {
	return;
}

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-B1 — route all branch-3 HTTP through the checked transport seam.
final class BizCity_Remote_Zalo_Http {

	/** @var callable */
	public static $transport = null;

	/** Execute one policy-checked request; no redirects or unbounded bodies. */
	public static function request( string $method, string $url, array $args = array() ): array {
		$policy = self::validate_endpoint_url( $url );
		if ( empty( $policy['ok'] ) ) {
			return self::failure( (string) ( $policy['code'] ?? 'remote_host_blocked' ) );
		}
		$parts = wp_parse_url( (string) $policy['normalized'] );
		$dns   = BizCity_Remote_Zalo_Host_Policy::resolve_and_check( (string) $parts['host'] );
		if ( empty( $dns['ok'] ) ) {
			return self::failure( (string) ( $dns['code'] ?? 'remote_host_blocked' ) );
		}

		$is_send = 'send' === (string) ( $args['rzh_operation'] ?? '' ) || 'POST' === strtoupper( $method );
		unset( $args['rzh_operation'] );
		$args = array_merge(
			array(
				'method'               => strtoupper( $method ),
				'redirection'          => 0,
				'sslverify'             => true,
				'timeout'               => $is_send ? 15 : 10,
				'limit_response_size'   => 1048576,
				'reject_unsafe_urls'    => true,
			),
			$args
		);
		$args['redirection']        = 0;
		$args['sslverify']          = true;
		$args['limit_response_size'] = 1048576;
		$args['reject_unsafe_urls'] = true;

		$transport = self::$transport;
		if ( ! is_callable( $transport ) ) {
			$transport = 'wp_remote_request';
		}
		// [2026-09-29 12:00 PM GitHub Copilot] HOTFIX-RZH-CURL-IPV4 — prefer resolved IPv4 when A and AAAA both exist; some VPS IPv6 routes fail while direct curl falls back to A.
		$hook = static function ( $handle, $request_args, $request_url ) use ( $parts, $dns ) {
			if ( ! function_exists( 'curl_setopt' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
				return;
			}
			$port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
			$ipv4 = array_values( array_filter( (array) $dns['ips'], static function ( $ip ) { return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ); } ) );
			$ips = $ipv4 ? $ipv4 : (array) $dns['ips'];
			$resolve = array();
			foreach ( $ips as $ip ) {
				$resolve[] = $parts['host'] . ':' . $port . ':' . $ip;
			}
			curl_setopt( $handle, CURLOPT_RESOLVE, $resolve );
		};
		if ( function_exists( 'add_action' ) ) {
			add_action( 'http_api_curl', $hook, 10, 3 );
		}
		try {
			$response = call_user_func( $transport, $policy['normalized'], $args );
		} catch ( Throwable $e ) {
			$response = null;
		} finally {
			if ( function_exists( 'remove_action' ) ) {
				remove_action( 'http_api_curl', $hook, 10 );
			}
		}
		if ( self::is_wp_error_like( $response ) ) {
			$transport_code = is_object( $response ) && method_exists( $response, 'get_error_code' ) ? sanitize_key( (string) $response->get_error_code() ) : 'wp_http_error';
			return self::failure( 'remote_unreachable', 0, $transport_code );
		}
		$status = self::response_status( $response );
		$body   = self::response_body( $response );
		if ( $status >= 300 && $status < 400 ) {
			return self::failure( 'remote_redirect_refused', $status );
		}
		if ( strlen( $body ) > 1048576 ) {
			return self::failure( 'remote_response_too_large', $status );
		}
		return array(
			'ok'            => true,
			'code'          => null,
			'status'        => $status,
			'headers'       => self::response_headers( $response ),
			'body'          => $body,
			'response'      => $response,
			'resolved_ips'  => $dns['ips'],
		);
	}

	// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-B3 — validate the configured client base while retaining endpoint paths and queries.
	/** Validate the configured client base while retaining the endpoint path/query for the request. */
	private static function validate_endpoint_url( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return array( 'ok' => false, 'code' => 'remote_url_invalid' );
		}
		$path = '/' . trim( preg_replace( '#/+#', '/', (string) $parts['path'] ), '/' );
		$base_path = 0 === strpos( $path, '/client-api' ) ? '/client-api' : '/client/v1';
		if ( 0 !== strpos( $path, $base_path . '/' ) && $path !== $base_path ) {
			return array( 'ok' => false, 'code' => 'remote_path_invalid' );
		}
		$base = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( isset( $parts['port'] ) ) { $base .= ':' . (int) $parts['port']; }
		$base .= $base_path;
		$validated = BizCity_Remote_Zalo_Host_Policy::validate_base_url( $base );
		if ( empty( $validated['ok'] ) ) { return $validated; }
		$normalized = $validated['normalized'] . substr( $path, strlen( $base_path ) );
		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) { $normalized .= '?' . $parts['query']; }
		return array( 'ok' => true, 'code' => null, 'normalized' => $normalized );
	}

	public static function reset_transport(): void {
		self::$transport = null;
	}

	private static function failure( string $code, int $status = 0, string $transport_code = '' ): array {
		return array( 'ok' => false, 'code' => $code, 'status' => $status, 'transport_code' => $transport_code, 'headers' => array(), 'body' => '' );
	}

	private static function is_wp_error_like( $response ): bool {
		return ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) || ( is_object( $response ) && method_exists( $response, 'get_error_code' ) );
	}

	private static function response_status( $response ): int {
		if ( is_array( $response ) ) {
			return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : (int) ( $response['status'] ?? 0 );
		}
		if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
			return (int) wp_remote_retrieve_response_code( $response );
		}
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : (int) ( $response['status'] ?? 0 );
	}

	private static function response_body( $response ): string {
		if ( is_array( $response ) ) {
			return isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
		}
		if ( function_exists( 'wp_remote_retrieve_body' ) ) {
			return (string) wp_remote_retrieve_body( $response );
		}
		return isset( $response['body'] ) ? (string) $response['body'] : '';
	}

	private static function response_headers( $response ): array {
		if ( is_array( $response ) ) {
			return isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
		}
		if ( function_exists( 'wp_remote_retrieve_headers' ) ) {
			$headers = wp_remote_retrieve_headers( $response );
			return is_array( $headers ) ? $headers : array();
		}
		return isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
	}
}