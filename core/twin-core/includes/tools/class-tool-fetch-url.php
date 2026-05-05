<?php
/**
 * Bizcity Twin AI — Tool: fetch_url
 *
 * Sprint 4.7b — Tool fetch a URL on demand. SSRF-guarded, size-capped.
 * Khác với ingest: KHÔNG lưu vào KG, chỉ trả raw text snippet cho LLM trong loop.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Tools
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_Fetch_Url implements BizCity_Twin_Tool {

	const MAX_BYTES   = 200000;   // 200KB cap — đủ trang HTML thường
	const MAX_TEXT    = 12000;    // 12K chars sang LLM
	const TIMEOUT_SEC = 12;

	public function name(): string {
		return 'fetch_url';
	}

	public function description(): string {
		return 'Fetch the visible text content of a public HTTP/HTTPS URL. Use ONLY when the user explicitly provides a URL, or when search_kg returned a source URL whose details you need to read further. Do NOT browse the web speculatively.';
	}

	public function parameters_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'url' => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Absolute http(s) URL.' ],
			],
			'required'   => [ 'url' ],
		];
	}

	public function execute( array $args, array $context ): array {
		$url = isset( $args['url'] ) ? trim( (string) $args['url'] ) : '';
		if ( '' === $url ) {
			return [ 'ok' => false, 'error' => 'url is required' ];
		}
		if ( ! $this->is_safe_url( $url ) ) {
			return [ 'ok' => false, 'error' => 'URL is not allowed (must be public http/https, not private/loopback).' ];
		}

		$resp = wp_remote_get( $url, [
			'timeout'     => self::TIMEOUT_SEC,
			'redirection' => 3,
			'user-agent'  => 'BizCityTwin/1.0 (+https://bizcity.vn)',
			'headers'     => [ 'Accept' => 'text/html,text/plain,application/xhtml+xml' ],
		] );
		if ( is_wp_error( $resp ) ) {
			return [ 'ok' => false, 'error' => 'fetch failed: ' . $resp->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return [ 'ok' => false, 'error' => 'HTTP ' . $code ];
		}
		if ( strlen( $body ) > self::MAX_BYTES ) {
			$body = substr( $body, 0, self::MAX_BYTES );
		}

		$ctype = (string) wp_remote_retrieve_header( $resp, 'content-type' );
		if ( false !== stripos( $ctype, 'html' ) || preg_match( '/<\s*html/i', $body ) ) {
			$text = $this->html_to_text( $body );
		} else {
			$text = $body;
		}
		$text = trim( preg_replace( '/[\r\n]{3,}/', "\n\n", preg_replace( '/[ \t]+/', ' ', $text ) ) );
		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT ) . "\n\n[...truncated]";
		}

		$cite_id = BizCity_Twin_Citation_Id_Generator::generate_one();
		return [
			'ok'           => true,
			'result'       => [
				'cite_id' => $cite_id,
				'url'     => $url,
				'text'    => $text,
				'instruction' => 'If you use this content in your answer, cite it as [' . $cite_id . '].',
			],
			'summary'      => sprintf( 'fetch_url ok: %d chars from %s', mb_strlen( $text ), parse_url( $url, PHP_URL_HOST ) ?: $url ),
			'sources'      => [ [ 'cite_id' => $cite_id, 'url' => $url, 'snippet' => mb_substr( $text, 0, 240 ) ] ],
			'citation_ids' => [ $cite_id ],
		];
	}

	/** SSRF guard — block private IPs, loopback, link-local, file://, etc. */
	private function is_safe_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return false;
		if ( ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) return false;

		$host = strtolower( $parts['host'] );
		if ( in_array( $host, [ 'localhost', '127.0.0.1', '0.0.0.0', '::1' ], true ) ) return false;

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ?: gethostbyname( $host );
		if ( $ip && $ip !== $host ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}

	private function html_to_text( string $html ): string {
		// Strip script/style, then tags.
		$html = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );
		$text = wp_strip_all_tags( $html );
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
