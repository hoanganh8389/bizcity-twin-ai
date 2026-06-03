<?php
/**
 * Action: HTTP request (wp_safe_remote_request).
 *
 * Validate method whitelist + url scheme + chặn private IP (SSRF basic guard).
 * Cấm: 0.0.0.0, 127.0.0.0/8, 169.254.0.0/16, 10/8, 172.16/12, 192.168/16.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_HTTP extends BizCity_Automation_Block_Base {

	const ALLOWED_METHODS = array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' );
	const TIMEOUT         = 10;

	public function id(): string   { return 'action.http_request'; }
	public function kind(): string { return 'action'; }
	public function meta(): array {
		return array(
			'label'    => 'HTTP request',
			'short'    => 'http',
			'category' => 'action',
			'color'    => '#1e40af',
			'icon'     => 'globe',
			'defaults' => array( 'label' => 'http_request', 'method' => 'GET', 'url' => '', 'body' => '' ),
			'fields'   => array(
				array( 'name' => 'label',  'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'method', 'label' => 'Method',       'type' => 'select', 'options' => array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ) ),
				array( 'name' => 'url',    'label' => 'URL',          'type' => 'text' ),
				array( 'name' => 'body',   'label' => 'Body (JSON)',  'type' => 'textarea' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$method = strtoupper( (string) ( $data['method'] ?? 'GET' ) );
		$url    = esc_url_raw( (string) $this->resolve( $data['url'] ?? '', $ctx ) );
		$body   = (string) $this->resolve( $data['body'] ?? '', $ctx );

		if ( ! in_array( $method, self::ALLOWED_METHODS, true ) ) {
			return new WP_Error( 'invalid_method', 'http: method không hợp lệ.', array( 'method' => $method ) );
		}
		if ( $url === '' ) {
			return new WP_Error( 'invalid_url', 'http: url rỗng.' );
		}
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) || ! in_array( strtolower( $parsed['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_url', 'http: chỉ chấp nhận http(s) URL.' );
		}
		if ( $this->is_private_host( $parsed['host'] ) ) {
			return new WP_Error( 'blocked_private_host', 'http: chặn IP nội bộ (SSRF guard).', array( 'host' => $parsed['host'] ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array( 'Content-Type' => 'application/json' ),
		);
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && $body !== '' ) {
			$args['body'] = $body;
		}

		// PG-S9 — dry-run mock (skip outbound HTTP).
		if ( ! empty( $ctx['_dry_run'] ) ) {
			return array( 'status' => 200, 'body' => '', 'headers' => array(), 'dry' => true, 'method' => $method, 'url' => $url );
		}

		$res = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'status'  => wp_remote_retrieve_response_code( $res ),
			'body'    => wp_remote_retrieve_body( $res ),
			'headers' => wp_remote_retrieve_headers( $res )->getAll(),
		);
	}

	private function is_private_host( string $host ): bool {
		// Resolve hostname → IP (single-shot). Loopback / link-local / RFC1918.
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		if ( $ip === $host && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false; // hostname không resolve được — để wp_safe_remote_request xử lý.
		}
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}
