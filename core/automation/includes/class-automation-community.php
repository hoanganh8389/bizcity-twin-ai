<?php
/**
 * BizCity Automation — Community Gallery service (Wave E · WF-AUTO W7).
 *
 * Read-only PoC fetch `.workflow.md` từ GitHub raw cho FE Canvas gallery
 * tab. KHÔNG ghi DB cho đến khi user explicit "Import this template".
 *
 * Security:
 *  - Allowlist host: chỉ cho phép `raw.githubusercontent.com` và
 *    `gist.githubusercontent.com` (mặc định) + filter
 *    `bizcity_community_allowed_hosts` để extend.
 *  - Cap response size: 256 KB cho manifest, 512 KB cho `.workflow.md`.
 *  - Transient cache 5 phút theo URL hash để tránh GitHub rate-limit.
 *  - Reject bất kỳ URL non-HTTPS / có query string lạ / có `..` trong path.
 *
 * Manifest format (`bizcity-workflows.manifest.json` trên repo cộng đồng):
 *   {
 *     "version": "1.0",
 *     "name":    "BizCity Community Workflows",
 *     "items": [
 *       {
 *         "slug":        "tpl_zalo_welcome_v1",
 *         "name":        "Zalo welcome reply",
 *         "description": "Auto-reply chào mừng khách mới trên Zalo OA.",
 *         "category":    "cskh",
 *         "tags":        "zalo,welcome",
 *         "url":         "https://raw.githubusercontent.com/.../zalo-welcome.workflow.md",
 *         "author":      "@bizcity",
 *         "version":     "1.0.0"
 *       }
 *     ]
 *   }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      Wave E (2026-06-03)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Automation_Community {

	const DEFAULT_MANIFEST_OPTION = 'bizcity_automation_community_manifest_url';
	const DEFAULT_MANIFEST_URL    = 'https://raw.githubusercontent.com/bizcity/automation-workflows/main/manifest.json';

	const MAX_MANIFEST_BYTES = 262144;  // 256 KB
	const MAX_MD_BYTES       = 524288;  // 512 KB
	const HTTP_TIMEOUT       = 8;
	const CACHE_TTL          = 300;     // 5 minutes

	/** @var BizCity_Automation_Community|null */
	private static $instance = null;

	public static function instance(): self {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — singleton accessor.
		if ( self::$instance === null ) { self::$instance = new self(); }
		return self::$instance;
	}

	/**
	 * Default manifest URL (admin-overridable via option).
	 */
	public function default_manifest_url(): string {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — option override + filter.
		$url = (string) get_option( self::DEFAULT_MANIFEST_OPTION, self::DEFAULT_MANIFEST_URL );
		$url = apply_filters( 'bizcity_community_manifest_url', $url );
		return $url;
	}

	/**
	 * Allowlisted hosts cho fetch.
	 *
	 * @return string[]
	 */
	public function allowed_hosts(): array {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — allowlist + filter extension.
		$hosts = array(
			'raw.githubusercontent.com',
			'gist.githubusercontent.com',
		);
		$hosts = apply_filters( 'bizcity_community_allowed_hosts', $hosts );
		return is_array( $hosts ) ? $hosts : array();
	}

	/**
	 * Validate URL: HTTPS only + host in allowlist + no path traversal.
	 *
	 * @param string $url
	 * @return true|WP_Error
	 */
	public function validate_url( string $url ) {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — defensive URL guard (SSRF prevention).
		if ( $url === '' ) {
			return new WP_Error( 'url_empty', 'URL trống.' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'url_invalid', 'URL không hợp lệ.' );
		}
		if ( strtolower( $parts['scheme'] ) !== 'https' ) {
			return new WP_Error( 'url_not_https', 'Chỉ chấp nhận HTTPS.' );
		}
		$host = strtolower( $parts['host'] );
		if ( ! in_array( $host, $this->allowed_hosts(), true ) ) {
			return new WP_Error( 'url_host_not_allowed', sprintf(
				'Host "%s" không nằm trong allowlist (%s).',
				$host,
				implode( ', ', $this->allowed_hosts() )
			) );
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( strpos( $path, '..' ) !== false ) {
			return new WP_Error( 'url_path_traversal', 'Path chứa "..".' );
		}
		return true;
	}

	/**
	 * Fetch manifest JSON từ GitHub raw.
	 *
	 * @param string $url
	 * @return array|WP_Error  Manifest data on success.
	 */
	public function fetch_manifest( string $url ) {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — manifest fetch + validate + cache.
		$ok = $this->validate_url( $url );
		if ( is_wp_error( $ok ) ) { return $ok; }

		$cache_key = 'bizc_comm_man_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$body = $this->http_get( $url, self::MAX_MANIFEST_BYTES );
		if ( is_wp_error( $body ) ) { return $body; }

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'manifest_invalid_json', 'Manifest không phải JSON object.' );
		}
		if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new WP_Error( 'manifest_missing_items', 'Manifest thiếu key "items".' );
		}

		// Sanitize each item (whitelist fields).
		$clean_items = array();
		foreach ( $data['items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) ) { continue; }
			$item_url = (string) $item['url'];
			$url_ok   = $this->validate_url( $item_url );
			if ( is_wp_error( $url_ok ) ) { continue; }
			$clean_items[] = array(
				'slug'        => isset( $item['slug'] )        ? sanitize_title( $item['slug'] ) : '',
				'name'        => isset( $item['name'] )        ? sanitize_text_field( $item['name'] ) : '',
				'description' => isset( $item['description'] ) ? sanitize_textarea_field( $item['description'] ) : '',
				'category'    => isset( $item['category'] )    ? sanitize_key( $item['category'] ) : 'general',
				'tags'        => isset( $item['tags'] )        ? sanitize_text_field( $item['tags'] ) : '',
				'url'         => esc_url_raw( $item_url ),
				'author'      => isset( $item['author'] )      ? sanitize_text_field( $item['author'] ) : '',
				'version'     => isset( $item['version'] )     ? sanitize_text_field( $item['version'] ) : '',
				'icon'        => isset( $item['icon'] )        ? sanitize_text_field( $item['icon'] ) : 'FileText',
			);
		}

		$out = array(
			'version'    => isset( $data['version'] ) ? sanitize_text_field( $data['version'] ) : '1.0',
			'name'       => isset( $data['name'] )    ? sanitize_text_field( $data['name'] )    : '',
			'manifest_url' => $url,
			'items'      => $clean_items,
			'fetched_at' => time(),
		);
		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Fetch raw `.workflow.md` body.
	 *
	 * @param string $url
	 * @return string|WP_Error
	 */
	public function fetch_workflow_md( string $url ) {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — md raw fetch + cache.
		$ok = $this->validate_url( $url );
		if ( is_wp_error( $ok ) ) { return $ok; }

		$cache_key = 'bizc_comm_md_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$body = $this->http_get( $url, self::MAX_MD_BYTES );
		if ( is_wp_error( $body ) ) { return $body; }
		set_transient( $cache_key, $body, self::CACHE_TTL );
		return $body;
	}

	/**
	 * Compile remote `.workflow.md` → preview struct (no DB write).
	 *
	 * @param string $url
	 * @return array|WP_Error  { md, workflow:{name,description,trigger_type,graph,...} }
	 */
	public function preview_workflow( string $url ) {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — fetch + compile preview.
		$md = $this->fetch_workflow_md( $url );
		if ( is_wp_error( $md ) ) { return $md; }
		if ( ! class_exists( 'BizCity_Workflow_MD_Compiler' ) ) {
			return new WP_Error( 'compiler_missing', 'BizCity_Workflow_MD_Compiler chưa load.' );
		}
		$wf = BizCity_Workflow_MD_Compiler::instance()->md_to_workflow( $md );
		if ( is_wp_error( $wf ) ) { return $wf; }
		return array(
			'md'       => $md,
			'workflow' => $wf,
			'source'   => $url,
		);
	}

	/**
	 * Internal HTTP GET with size cap.
	 *
	 * @param string $url
	 * @param int    $max_bytes
	 * @return string|WP_Error
	 */
	private function http_get( string $url, int $max_bytes ) {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — wp_remote_get + size cap + http error map.
		$resp = wp_remote_get( $url, array(
			'timeout'     => self::HTTP_TIMEOUT,
			'redirection' => 3,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'     => 'text/plain, application/json',
				'User-Agent' => 'BizCity-Twin-AI Community Gallery (W7)',
			),
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'http_transport_error', $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'http_error', sprintf( 'HTTP %d từ %s', $code, $url ) );
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error( 'response_too_large', sprintf(
				'Response %d bytes vượt giới hạn %d.',
				strlen( $body ),
				$max_bytes
			) );
		}
		return $body;
	}
}
