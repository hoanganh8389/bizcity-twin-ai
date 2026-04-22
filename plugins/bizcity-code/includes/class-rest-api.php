<?php
/**
 * REST API for Code Builder.
 *
 * SSE streaming endpoints use wp_ajax_ hooks (bypass WP REST output buffering).
 * CRUD endpoints use standard WP REST API.
 *
 * SSE Endpoints (via wp_ajax_):
 *   POST wp-admin/admin-ajax.php?action=bzcode_generate  — create (SSE)
 *   POST wp-admin/admin-ajax.php?action=bzcode_edit      — edit (SSE)
 *
 * REST Endpoints:
 *   GET  /bzcode/v1/projects    — list user projects
 *   GET  /bzcode/v1/project/:id — get project with pages + variants
 *   POST /bzcode/v1/project/:id/select-variant — select a variant
 *   DELETE /bzcode/v1/project/:id — delete project
 *   GET  /bzcode/v1/preview/:id — get preview HTML for variant
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Rest_API {

	const NAMESPACE = 'bzcode/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

		// SSE endpoints via wp_ajax_ — avoids WP REST output buffering
		add_action( 'wp_ajax_bzcode_generate', [ __CLASS__, 'handle_generate' ] );
		add_action( 'wp_ajax_bzcode_generate_sectional', [ __CLASS__, 'handle_generate_sectional' ] );
		add_action( 'wp_ajax_bzcode_edit', [ __CLASS__, 'handle_edit' ] );
		add_action( 'wp_ajax_bzcode_screenshot_url', [ __CLASS__, 'handle_screenshot_url' ] );
	}

	public static function register_routes(): void {

		/* ── Projects CRUD ── */
		register_rest_route( self::NAMESPACE, '/projects', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_list_projects' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE, '/project/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'handle_get_project' ],
				'permission_callback' => [ __CLASS__, 'check_logged_in' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ __CLASS__, 'handle_delete_project' ],
				'permission_callback' => [ __CLASS__, 'check_logged_in' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/project/(?P<id>\d+)/select-variant', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_select_variant' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		/* ── Publish to WP Page ── */
		register_rest_route( self::NAMESPACE, '/project/(?P<id>\d+)/publish-page', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_publish_page' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		/* ── Delete published WP Page ── */
		register_rest_route( self::NAMESPACE, '/project/(?P<id>\d+)/delete-page', [
			'methods'             => 'DELETE',
			'callback'            => [ __CLASS__, 'handle_delete_page' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		/* ── Save code (create project from raw code) ── */
		register_rest_route( self::NAMESPACE, '/save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_save_code' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		/* ── Preview ── */
		register_rest_route( self::NAMESPACE, '/preview/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_preview' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		/* ── Generation History (checkpoints) ── */
		register_rest_route( self::NAMESPACE, '/project/(?P<id>\d+)/generations', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_list_generations' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE, '/generation/(?P<gen_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_get_generation' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE, '/generation/restore', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_restore_generation' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		/* ── Source Management ── */
		register_rest_route( self::NAMESPACE, '/project/(?P<id>\d+)/sources', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_list_sources' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE, '/source/upload', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_upload' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE, '/source/add-text', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_add_text' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE, '/source/add-url', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_add_url' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE, '/source/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ __CLASS__, 'handle_source_delete' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE, '/source/search', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_search' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );

		register_rest_route( self::NAMESPACE, '/source/search/import', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_source_search_import' ],
			'permission_callback' => [ __CLASS__, 'check_logged_in' ],
		] );
	}

	/* ═══════════════════════════════════════════════
	 *  GENERATE (SSE via wp_ajax_)
	 * ═══════════════════════════════════════════════ */

	public static function handle_generate(): void {
		check_ajax_referer( 'bzcode_sse', '_nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not authenticated.', 401 );
		}

		$user_id = get_current_user_id();

		// P2: Rate limit (before SSE — return JSON on reject)
		$rate_err = self::check_rate_limit( $user_id, 'generate' );
		if ( $rate_err ) {
			wp_send_json_error( $rate_err->get_error_message(), 429 );
		}

		// P2: Balance pre-flight check (paid credit OR monthly free quota)
		if ( ! self::check_balance( $user_id ) ) {
			wp_send_json_error( 'Bạn đã dùng hết 5 lượt miễn phí trong tháng. Nạp credit tại /my-account/topup-credit/ để tiếp tục.', 402 );
		}

		// Read JSON body
		$input = json_decode( file_get_contents( 'php://input' ), true ) ?: [];

		// P2: Sanitize images (supports base64 data URLs + regular URLs)
		$images = self::sanitize_images( (array) ( $input['images'] ?? [] ) );

		// P2: Upload base64 images to R2 CDN (reduces LLM payload)
		$images = self::maybe_upload_images( $images, $user_id );

		self::start_sse();

		$params = [
			'mode'       => sanitize_text_field( $input['mode'] ?? 'text' ),
			'prompt'     => sanitize_textarea_field( $input['prompt'] ?? '' ),
			'images'     => $images,
			'stack'      => sanitize_text_field( $input['stack'] ?? 'html_tailwind' ),
			'project_id' => (int) ( $input['project_id'] ?? 0 ),
			'variants'   => (int) ( $input['variants'] ?? 2 ),
			'model'      => sanitize_text_field( $input['model'] ?? '' ),
			'user_id'    => $user_id,
		];

		// Validate stack
		if ( ! isset( BZCode_Engine::STACKS[ $params['stack'] ] ) ) {
			$params['stack'] = 'html_tailwind';
		}

		$result = BZCode_Engine::create(
			$params,
			function ( int $vi, string $delta ) {
				self::sse_event( 'chunk', [ 'variant' => $vi, 'delta' => $delta ] );
			},
			function ( int $vi, string $code, array $usage ) {
				self::sse_event( 'variant_complete', [ 'variant' => $vi, 'code' => $code, 'usage' => $usage ] );
			},
			function ( int $vi, string $error ) {
				self::sse_event( 'variant_error', [ 'variant' => $vi, 'error' => $error ] );
			}
		);

		self::sse_event( 'done', $result );
		self::end_sse();
	}

	/* ═══════════════════════════════════════════════
	 *  EDIT (SSE via wp_ajax_)
	 * ═══════════════════════════════════════════════ */

	public static function handle_edit(): void {
		check_ajax_referer( 'bzcode_sse', '_nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not authenticated.', 401 );
		}

		$user_id = get_current_user_id();

		// P2: Rate limit (before SSE)
		$rate_err = self::check_rate_limit( $user_id, 'edit' );
		if ( $rate_err ) {
			wp_send_json_error( $rate_err->get_error_message(), 429 );
		}

		// P2: Balance pre-flight check (paid credit OR monthly free quota)
		if ( ! self::check_balance( $user_id ) ) {
			wp_send_json_error( 'Bạn đã dùng hết 5 lượt miễn phí trong tháng. Nạp credit tại /my-account/topup-credit/ để tiếp tục.', 402 );
		}

		$input = json_decode( file_get_contents( 'php://input' ), true ) ?: [];

		$project_id = (int) ( $input['project_id'] ?? 0 );

		// Ownership check (before SSE — return JSON on reject)
		$project = BZCode_Project_Manager::get_by_id( $project_id );
		if ( ! $project || (int) $project->user_id !== $user_id ) {
			wp_send_json_error( 'Project not found or access denied.', 404 );
		}

		// P2: Sanitize + upload images to R2
		$images = self::sanitize_images( (array) ( $input['images'] ?? [] ) );
		$images = self::maybe_upload_images( $images, $user_id );

		self::start_sse();

		$params = [
			'project_id'  => $project_id,
			'instruction' => sanitize_textarea_field( $input['instruction'] ?? '' ),
			'images'      => $images,
			'model'       => sanitize_text_field( $input['model'] ?? '' ),
		];

		$result = BZCode_Engine::edit(
			$params,
			function ( int $vi, string $delta ) {
				self::sse_event( 'chunk', [ 'variant' => $vi, 'delta' => $delta ] );
			},
			function ( int $vi, string $code, array $usage ) {
				self::sse_event( 'variant_complete', [ 'variant' => $vi, 'code' => $code, 'usage' => $usage ] );
			},
			function ( int $vi, string $error ) {
				self::sse_event( 'variant_error', [ 'variant' => $vi, 'error' => $error ] );
			}
		);

		self::sse_event( 'done', $result );
		self::end_sse();
	}

	/* ═══════════════════════════════════════════════
	 *  URL → SCREENSHOT (via wp_ajax_)
	 *  Uses Google PageSpeed API (free) or falls back to placeholder screenshot services.
	 * ═══════════════════════════════════════════════ */

	public static function handle_screenshot_url(): void {
		check_ajax_referer( 'bzcode_sse', '_nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not authenticated.', 401 );
		}

		$input = json_decode( file_get_contents( 'php://input' ), true ) ?: [];
		$url   = isset( $input['url'] ) ? esc_url_raw( $input['url'] ) : '';

		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_send_json_error( 'URL không hợp lệ.' );
		}

		// Validate scheme (only http/https)
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], [ 'http', 'https' ], true ) ) {
			wp_send_json_error( 'Chỉ hỗ trợ URL http/https.' );
		}

		// Block private/internal IPs (SSRF protection)
		$host = $parsed['host'] ?? '';
		if ( self::is_private_host( $host ) ) {
			wp_send_json_error( 'Không thể chụp screenshot từ địa chỉ nội bộ.' );
		}

		// Strategy 1: Google PageSpeed Insights API (free, no key needed for basic)
		$screenshot_data = self::screenshot_via_pagespeed( $url );

		// Strategy 2: Fallback to screenshotone.com or similar free API
		if ( ! $screenshot_data ) {
			$screenshot_data = self::screenshot_via_fallback( $url );
		}

		if ( $screenshot_data ) {
			wp_send_json_success( [ 'image' => $screenshot_data ] );
		} else {
			wp_send_json_error( 'Không thể chụp screenshot. Vui lòng thử upload ảnh thủ công.' );
		}
	}

	/**
	 * Screenshot via Google PageSpeed Insights API.
	 * Returns base64 data URL or null.
	 */
	private static function screenshot_via_pagespeed( string $url ): ?string {
		$api_url = add_query_arg( [
			'url'      => $url,
			'strategy' => 'desktop',
			'category' => 'performance',
		], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' );

		$response = wp_remote_get( $api_url, [
			'timeout'   => 30,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Extract the full-page screenshot
		$screenshot = $body['lighthouseResult']['audits']['full-page-screenshot']['details']['screenshot'] ?? null;
		if ( ! $screenshot || empty( $screenshot['data'] ) ) {
			// Try final-screenshot (smaller)
			$screenshot = $body['lighthouseResult']['audits']['final-screenshot']['details'] ?? null;
		}

		if ( $screenshot && ! empty( $screenshot['data'] ) ) {
			$data = $screenshot['data'];
			// Already a data URL
			if ( str_starts_with( $data, 'data:image/' ) ) {
				return $data;
			}
			return 'data:image/jpeg;base64,' . $data;
		}

		return null;
	}

	/**
	 * Fallback screenshot API — uses free screenshot services.
	 */
	private static function screenshot_via_fallback( string $url ): ?string {
		// Use microlink.io free tier
		$api_url = add_query_arg( [
			'url'        => $url,
			'screenshot' => 'true',
			'meta'       => 'false',
			'embed'      => 'screenshot.url',
		], 'https://api.microlink.io' );

		$response = wp_remote_get( $api_url, [
			'timeout'   => 20,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$img_url = $body['data']['screenshot']['url'] ?? null;

		if ( ! $img_url ) {
			return null;
		}

		// Download image and convert to base64 data URL
		$img_response = wp_remote_get( $img_url, [
			'timeout'   => 15,
			'sslverify' => true,
		] );

		if ( is_wp_error( $img_response ) ) {
			return null;
		}

		$img_body = wp_remote_retrieve_body( $img_response );
		$img_type = wp_remote_retrieve_header( $img_response, 'content-type' ) ?: 'image/png';

		if ( strlen( $img_body ) < 100 ) {
			return null;
		}

		return 'data:' . $img_type . ';base64,' . base64_encode( $img_body );
	}

	/**
	 * SSRF protection: check if host resolves to private/internal IP.
	 */
	private static function is_private_host( string $host ): bool {
		// Block obvious internal hostnames
		$blocked = [ 'localhost', '127.0.0.1', '0.0.0.0', '::1', 'metadata.google.internal' ];
		if ( in_array( strtolower( $host ), $blocked, true ) ) {
			return true;
		}

		// Resolve and check IP ranges
		$ips = gethostbynamel( $host );
		if ( ! $ips ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			$long = ip2long( $ip );
			if ( $long === false ) {
				continue;
			}
			// 10.0.0.0/8
			if ( ( $long & 0xFF000000 ) === 0x0A000000 ) return true;
			// 172.16.0.0/12
			if ( ( $long & 0xFFF00000 ) === 0xAC100000 ) return true;
			// 192.168.0.0/16
			if ( ( $long & 0xFFFF0000 ) === 0xC0A80000 ) return true;
			// 127.0.0.0/8
			if ( ( $long & 0xFF000000 ) === 0x7F000000 ) return true;
			// 169.254.0.0/16 (link-local / cloud metadata)
			if ( ( $long & 0xFFFF0000 ) === 0xA9FE0000 ) return true;
		}

		return false;
	}

	/* ═══════════════════════════════════════════════
	 *  CRUD HANDLERS
	 * ═══════════════════════════════════════════════ */

	public static function handle_list_projects( \WP_REST_Request $request ): \WP_REST_Response {
		$projects = BZCode_Project_Manager::get_all( get_current_user_id() );
		return new \WP_REST_Response( [ 'ok' => true, 'data' => $projects ] );
	}

	public static function handle_get_project( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request['id'];
		$project = BZCode_Project_Manager::get_by_id( $id );

		if ( ! $project || (int) $project->user_id !== get_current_user_id() ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
		}

		$pages = BZCode_Page_Manager::get_by_project( $id );
		$project->pages = [];

		foreach ( $pages as $page ) {
			$page->variants  = BZCode_Variant_Manager::get_by_page( (int) $page->id );
			$project->pages[] = $page;
		}

		// Include published page URL if exists
		$pub_page = (int) get_option( 'bzcode_pub_page_' . $id, 0 );
		if ( $pub_page && get_post( $pub_page ) ) {
			$project->publish_url = get_permalink( $pub_page );
		}

		return new \WP_REST_Response( [ 'ok' => true, 'data' => $project ] );
	}

	public static function handle_delete_project( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request['id'];
		$project = BZCode_Project_Manager::get_by_id( $id );

		if ( ! $project || (int) $project->user_id !== get_current_user_id() ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
		}

		BZCode_Project_Manager::delete( $id );
		return new \WP_REST_Response( [ 'ok' => true ] );
	}

	public static function handle_select_variant( \WP_REST_Request $request ): \WP_REST_Response {
		$project_id = (int) $request['id'];
		$variant_id = (int) $request->get_param( 'variant_id' );
		$page_id    = (int) $request->get_param( 'page_id' );

		if ( ! $page_id || ! $variant_id ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Missing params' ], 400 );
		}

		// Ownership check
		$project = BZCode_Project_Manager::get_by_id( $project_id );
		if ( ! $project || (int) $project->user_id !== get_current_user_id() ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
		}

		BZCode_Variant_Manager::select_variant( $page_id, $variant_id );
		return new \WP_REST_Response( [ 'ok' => true ] );
	}

	public static function handle_preview( \WP_REST_Request $request ): void {
		$variant_id = (int) $request['id'];
		$variant    = BZCode_Variant_Manager::get_by_id( $variant_id );

		// Verify variant belongs to current user's project
		if ( $variant ) {
			$page = BZCode_Page_Manager::get_by_id( (int) $variant->page_id );
			if ( $page ) {
				$project = BZCode_Project_Manager::get_by_id( (int) $page->project_id );
				if ( ! $project || (int) $project->user_id !== get_current_user_id() ) {
					$variant = null;
				}
			} else {
				$variant = null;
			}
		}

		// Serve preview in a sandboxed context with CSP
		header( 'Content-Type: text/html; charset=utf-8' );
		header( "Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data: blob:; frame-ancestors 'self'" );
		header( 'X-Content-Type-Options: nosniff' );

		if ( ! $variant || empty( $variant->code ) ) {
			echo '<html><body><p style="padding:40px;font-family:sans-serif;color:#888;">No preview available.</p></body></html>';
		} else {
			echo $variant->code;
		}
		exit;
	}

	/* ═══════════════════════════════════════════════
	 *  SSE HELPERS
	 * ═══════════════════════════════════════════════ */

	private static function start_sse(): void {
		// Remove ALL output buffers (critical for SSE + LiteSpeed)
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		if ( function_exists( 'apache_setenv' ) ) {
			apache_setenv( 'no-gzip', '1' );
		}
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', '0' );

		// 4KB prelude for LiteSpeed/proxy buffering (same as llm-router pattern)
		echo ": sse-open\n" . str_repeat( ' ', 4096 ) . "\n\n";
		flush();
	}

	private static function sse_event( string $event, $data ): void {
		echo "event: {$event}\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		flush();
	}

	private static function end_sse(): void {
		echo "event: close\ndata: {}\n\n";
		flush();
		exit;
	}

	/* ═══════════════════════════════════════════════
	 *  P2: RATE LIMITING (transient-based, same pattern as llm-router)
	 * ═══════════════════════════════════════════════ */

	/**
	 * @return \WP_Error|null  WP_Error on reject, null on pass.
	 */
	private static function check_rate_limit( int $user_id, string $action ): ?\WP_Error {
		// Admins bypass rate limits
		if ( current_user_can( 'manage_options' ) ) {
			return null;
		}

		// Paid users: no rate limit at this layer (LLM router handles quota)
		if ( class_exists( 'BizCity_Ledger' ) ) {
			$balance = BizCity_Ledger::get_credit_usd( $user_id );
			if ( $balance >= 0.01 ) {
				return null;
			}
		}

		// Free-tier daily limits
		$daily_max = $action === 'generate' ? 5 : 10;
		$today     = gmdate( 'Y-m-d' );
		$tkey      = "bzcode_daily_{$action}_{$user_id}_{$today}";
		$count     = (int) get_transient( $tkey );

		if ( $count >= $daily_max ) {
			$label = $action === 'generate' ? 'lần tạo code' : 'lần chỉnh sửa';
			return new \WP_Error(
				'rate_limit_daily',
				sprintf( 'Bạn đã đạt giới hạn %d %s/ngày. Nạp credit để sử dụng không giới hạn.', $daily_max, $label ),
				[ 'status' => 429, 'limit' => $daily_max, 'used' => $count ]
			);
		}

		// Increment counter (TTL = seconds until midnight UTC)
		$ttl = strtotime( 'tomorrow midnight UTC' ) - time();
		set_transient( $tkey, $count + 1, max( $ttl, 60 ) );

		return null;
	}

	/* ═══════════════════════════════════════════════
	 *  P2: BALANCE PRE-FLIGHT CHECK
	 * ═══════════════════════════════════════════════ */

	private static function check_balance( int $user_id ): bool {
		// If BizCity_Ledger is available, check USD balance
		if ( class_exists( 'BizCity_Ledger' ) ) {
			if ( BizCity_Ledger::get_credit_usd( $user_id ) >= 0.001 ) {
				return true; // paid user — pass
			}
		}

		// Free-tier: allow 5 uses per calendar month
		$month = gmdate( 'Y-m' );
		$tkey  = "bzcode_free_month_{$user_id}_{$month}";
		$used  = (int) get_transient( $tkey );

		if ( $used < 5 ) {
			set_transient( $tkey, $used + 1, 32 * DAY_IN_SECONDS );
			return true;
		}

		return false;
	}

	/* ═══════════════════════════════════════════════
	 *  P2: IMAGE SANITIZATION + R2 UPLOAD
	 * ═══════════════════════════════════════════════ */

	/**
	 * Sanitize image array: accept base64 data URLs and http(s) URLs.
	 * esc_url_raw() strips data: protocol, so we handle base64 separately.
	 */
	private static function sanitize_images( array $raw ): array {
		$clean = [];
		foreach ( $raw as $img ) {
			if ( ! is_string( $img ) || empty( $img ) ) {
				continue;
			}
			// Base64 data URL — validate MIME + reasonable size (< 10MB encoded)
			if ( preg_match( '/^data:image\/(png|jpe?g|gif|webp);base64,/i', $img ) ) {
				if ( strlen( $img ) < 14 * 1024 * 1024 ) {
					$clean[] = $img;
				}
				continue;
			}
			// Regular URL
			$url = esc_url_raw( $img );
			if ( $url ) {
				$clean[] = $url;
			}
		}
		return $clean;
	}

	/**
	 * Upload base64 data URLs to R2 CDN, return CDN URLs.
	 * Falls back to original data URL if R2 unavailable / upload fails.
	 */
	private static function maybe_upload_images( array $images, int $user_id ): array {
		if ( empty( $images ) ) {
			return $images;
		}

		// MUCD_Files (bizcity-web mu-plugin) provides S3 client for R2
		if ( ! class_exists( 'MUCD_Files' ) || ! method_exists( 'MUCD_Files', 'r2_config_ok' ) || ! MUCD_Files::r2_config_ok() ) {
			return $images;
		}

		$uploaded = [];
		foreach ( $images as $img ) {
			// Only process base64 data URLs
			if ( strpos( $img, 'data:image/' ) !== 0 ) {
				$uploaded[] = $img;
				continue;
			}
			$cdn_url    = self::upload_base64_to_r2( $img, $user_id );
			$uploaded[] = $cdn_url ?: $img; // Fallback to base64 on failure
		}
		return $uploaded;
	}

	/**
	 * Decode base64 data URL → upload to R2 → return CDN URL.
	 */
	private static function upload_base64_to_r2( string $data_url, int $user_id ): ?string {
		if ( ! preg_match( '/^data:image\/(png|jpe?g|gif|webp);base64,(.+)$/s', $data_url, $m ) ) {
			return null;
		}

		$ext     = $m[1] === 'jpeg' ? 'jpg' : $m[1];
		$decoded = base64_decode( $m[2], true );
		if ( ! $decoded || strlen( $decoded ) < 100 ) {
			return null;
		}
		// Hard cap: 10 MB decoded
		if ( strlen( $decoded ) > 10 * 1024 * 1024 ) {
			return null;
		}

		$key = sprintf(
			'uploads/bzcode/%d/%s/%s.%s',
			$user_id,
			gmdate( 'Y/m' ),
			wp_generate_uuid4(),
			$ext
		);

		try {
			MUCD_Files::s3()->putObject( [
				'Bucket'       => BIZCITY_R2_BUCKET,
				'Key'          => $key,
				'Body'         => $decoded,
				'ContentType'  => 'image/' . ( $ext === 'jpg' ? 'jpeg' : $ext ),
				'CacheControl' => 'public, max-age=31536000, immutable',
			] );

			$cdn = defined( 'BIZCITY_MEDIA_CDN' ) ? rtrim( BIZCITY_MEDIA_CDN, '/' ) : '';
			if ( ! $cdn ) {
				return null;
			}
			return $cdn . '/' . $key;
		} catch ( \Exception $e ) {
			error_log( '[BZCODE] R2 upload failed: ' . $e->getMessage() );
			return null;
		}
	}

	/* ── Permission ── */

	public static function check_logged_in(): bool {
		return is_user_logged_in();
	}

	/* ═══════════════════════════════════════════════
	 *  SECTIONAL GENERATE (SSE via wp_ajax_)
	 * ═══════════════════════════════════════════════ */

	public static function handle_generate_sectional(): void {
		check_ajax_referer( 'bzcode_sse', '_nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not authenticated.', 401 );
		}

		$user_id = get_current_user_id();

		$rate_err = self::check_rate_limit( $user_id, 'generate' );
		if ( $rate_err ) {
			wp_send_json_error( $rate_err->get_error_message(), 429 );
		}

		if ( ! self::check_balance( $user_id ) ) {
			wp_send_json_error( 'Bạn đã dùng hết 5 lượt miễn phí trong tháng. Nạp credit tại /my-account/topup-credit/ để tiếp tục.', 402 );
		}

		$input = json_decode( file_get_contents( 'php://input' ), true ) ?: [];

		$images = self::sanitize_images( (array) ( $input['images'] ?? [] ) );
		$images = self::maybe_upload_images( $images, $user_id );

		self::start_sse();

		$params = [
			'mode'       => sanitize_text_field( $input['mode'] ?? 'text' ),
			'prompt'     => sanitize_textarea_field( $input['prompt'] ?? '' ),
			'images'     => $images,
			'stack'      => sanitize_text_field( $input['stack'] ?? 'html_css' ),
			'project_id' => (int) ( $input['project_id'] ?? 0 ),
			'sections'   => array_map( 'sanitize_text_field', (array) ( $input['sections'] ?? [] ) ),
			'model'      => sanitize_text_field( $input['model'] ?? '' ),
			'user_id'    => $user_id,
		];

		if ( ! isset( BZCode_Engine::STACKS[ $params['stack'] ] ) ) {
			$params['stack'] = 'html_css';
		}

		$result = BZCode_Engine::create_sectional(
			$params,
			function ( int $vi, string $delta ) {
				self::sse_event( 'chunk', [ 'variant' => $vi, 'delta' => $delta ] );
			},
			function ( int $vi, string $code, array $usage ) {
				self::sse_event( 'variant_complete', [ 'variant' => $vi, 'code' => $code, 'usage' => $usage ] );
			},
			function ( int $vi, string $error ) {
				self::sse_event( 'variant_error', [ 'variant' => $vi, 'error' => $error ] );
			},
			function ( int $si, int $total, string $name ) {
				self::sse_event( 'section_progress', [ 'index' => $si, 'total' => $total, 'name' => $name ] );
			}
		);

		self::sse_event( 'done', $result );
		self::end_sse();
	}

	/* ═══════════════════════════════════════════════
	 *  GENERATION HISTORY — list / get / restore
	 * ═══════════════════════════════════════════════ */

	public static function handle_list_generations( \WP_REST_Request $request ): \WP_REST_Response {
		$project_id = (int) $request['id'];
		$user_id    = get_current_user_id();

		// Ownership check
		$project = BZCode_Project_Manager::get_by_id( $project_id );
		if ( ! $project || (int) $project->user_id !== $user_id ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
		}

		global $wpdb;
		$table = BZCode_Installer::table_generations();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, project_id, variant_id, action, status, prompt, model,
			        tokens_used, duration_ms, error_message, created_at, completed_at,
			        (code_snapshot IS NOT NULL AND code_snapshot != '') AS has_snapshot
			 FROM {$table}
			 WHERE project_id = %d AND user_id = %d
			 ORDER BY created_at DESC
			 LIMIT 50",
			$project_id, $user_id
		) );

		return new \WP_REST_Response( [ 'ok' => true, 'data' => $rows ] );
	}

	public static function handle_get_generation( \WP_REST_Request $request ): \WP_REST_Response {
		$gen_id  = (int) $request['gen_id'];
		$user_id = get_current_user_id();

		global $wpdb;
		$table = BZCode_Installer::table_generations();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
			$gen_id, $user_id
		) );

		if ( ! $row ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Not found' ], 404 );
		}

		return new \WP_REST_Response( [ 'ok' => true, 'data' => $row ] );
	}

	public static function handle_restore_generation( \WP_REST_Request $request ): \WP_REST_Response {
		$body    = $request->get_json_params() ?: [];
		$gen_id  = (int) ( $body['gen_id'] ?? 0 );
		$user_id = get_current_user_id();

		if ( ! $gen_id ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Missing gen_id' ], 400 );
		}

		global $wpdb;
		$table = BZCode_Installer::table_generations();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
			$gen_id, $user_id
		) );

		if ( ! $row || empty( $row->code_snapshot ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Snapshot not found' ], 404 );
		}

		$project_id = (int) $row->project_id;

		// Get first page of project
		$pages = BZCode_Page_Manager::get_by_project( $project_id );
		if ( empty( $pages ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'No pages' ], 400 );
		}
		$page_id = (int) $pages[0]->id;

		// Create a new variant with restored code
		$variant_id = BZCode_Variant_Manager::insert( [
			'page_id'         => $page_id,
			'variant_index'   => 0,
			'code'            => $row->code_snapshot,
			'status'          => 'complete',
			'is_selected'     => 1,
			'generation_type' => 'restore',
			'model_used'      => $row->model ?: '',
		] );

		// Select this variant
		BZCode_Variant_Manager::select_variant( $page_id, $variant_id );

		// Log the restore action
		$wpdb->insert( $table, [
			'project_id' => $project_id,
			'variant_id' => $variant_id,
			'user_id'    => $user_id,
			'action'     => 'restore',
			'status'     => 'complete',
			'prompt'     => 'Restored from generation #' . $gen_id,
			'code_snapshot' => $row->code_snapshot,
			'created_at'    => current_time( 'mysql', true ),
			'completed_at'  => current_time( 'mysql', true ),
		] );

		return new \WP_REST_Response( [
			'ok'         => true,
			'variant_id' => $variant_id,
			'message'    => 'Đã khôi phục checkpoint thành công.',
		] );
	}

	/* ═══════════════════════════════════════════════
	 *  PUBLISH PAGE
	 * ═══════════════════════════════════════════════ */

	/* ═══════════════════════════════════════════════
	 *  SAVE CODE — create project from raw code (import / manual edit)
	 * ═══════════════════════════════════════════════ */

	public static function handle_save_code( \WP_REST_Request $request ): \WP_REST_Response {
		$body    = $request->get_json_params() ?: [];
		$code    = $body['code'] ?? '';
		$title   = sanitize_text_field( $body['title'] ?? 'Imported Code' );
		$stack   = sanitize_text_field( $body['stack'] ?? 'html_css' );
		$user_id = get_current_user_id();

		if ( empty( $code ) && empty( $body['empty_project'] ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'message' => 'No code provided.' ], 400 );
		}

		// Create project
		$project_id = BZCode_Project_Manager::insert( [
			'user_id' => $user_id,
			'title'   => mb_substr( $title, 0, 100 ),
			'stack'   => $stack,
		] );
		if ( ! $project_id ) {
			return new \WP_REST_Response( [ 'ok' => false, 'message' => 'Failed to create project.' ], 500 );
		}

		// Empty project — only need the project row (for attaching sources)
		if ( ! empty( $body['empty_project'] ) && empty( $code ) ) {
			return new \WP_REST_Response( [
				'ok'         => true,
				'project_id' => $project_id,
			], 200 );
		}

		// Create page
		$page_id = BZCode_Page_Manager::insert( [
			'project_id' => $project_id,
			'title'      => 'index',
			'slug'       => 'index',
		] );

		// Create variant with code
		$variant_id = BZCode_Variant_Manager::insert( [
			'page_id'         => $page_id,
			'variant_index'   => 0,
			'code'            => $code,
			'status'          => 'complete',
			'is_selected'     => 1,
			'generation_type' => 'import',
		] );

		return new \WP_REST_Response( [
			'ok'         => true,
			'project_id' => $project_id,
			'page_id'    => $page_id,
			'variant_id' => $variant_id,
		], 200 );
	}

	/* ═══════════════════════════════════════════════
	 *  PUBLISH TO WP PAGE
	 * ═══════════════════════════════════════════════ */

	public static function handle_publish_page( WP_REST_Request $request ): WP_REST_Response {
		$project_id = (int) $request->get_param( 'id' );
		$body       = $request->get_json_params() ?: [];
		$title      = sanitize_text_field( $body['title'] ?? 'Landing Page AI' );
		if ( ! $title ) $title = 'Landing Page AI';

		$project = BZCode_Project_Manager::get_by_id( $project_id );
		if ( ! $project || (int) $project->user_id !== get_current_user_id() ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => 'Project not found.' ], 404 );
		}

		// Get selected variant code from first page
		$pages = BZCode_Page_Manager::get_by_project( $project_id );
		if ( empty( $pages ) ) {
			// No pages in DB — use code from client if provided
			if ( empty( $body['code'] ?? '' ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'message' => 'No pages found.' ], 400 );
			}
		}

		$code = '';
		if ( ! empty( $pages ) ) {
			// Try DB variants — check all pages for non-empty code
			foreach ( $pages as $p ) {
				$page_id_db = (int) $p->id;
				$variant    = BZCode_Variant_Manager::get_selected( $page_id_db );
				if ( $variant && ! empty( $variant->code ) ) {
					$code = $variant->code;
					break;
				}
				$all     = BZCode_Variant_Manager::get_by_page( $page_id_db );
				foreach ( $all as $v ) {
					if ( ! empty( $v->code ) ) {
						$code = $v->code;
						break 2;
					}
				}
			}
		}

		// Fallback: use code sent from client (full HTML page — no kses filtering)
		if ( empty( $code ) && ! empty( $body['code'] ?? '' ) ) {
			$code = $body['code'];
		}

		if ( empty( $code ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => 'No code to publish.' ], 400 );
		}

		// Reuse existing WP Page if already published
		$option_key = 'bzcode_pub_page_' . $project_id;

		// Transient lock to prevent duplicate concurrent requests
		$lock_key = 'bzcode_pub_lock_' . $project_id;
		if ( get_transient( $lock_key ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => 'Đang xuất bản, vui lòng chờ...' ], 409 );
		}
		set_transient( $lock_key, 1, 30 ); // 30s lock

		$pub_page   = (int) get_option( $option_key, 0 );

		$post_data = [
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
			'meta_input'   => [
				'_bzcode_page_html' => $code,
				'_wp_page_template' => 'bzcode-fullpage',
			],
		];

		if ( $pub_page && get_post( $pub_page ) ) {
			$post_data['ID'] = $pub_page;
			wp_update_post( $post_data );
		} else {
			$pub_page = wp_insert_post( $post_data );
			if ( is_wp_error( $pub_page ) ) {
				delete_transient( $lock_key );
				return new WP_REST_Response( [ 'ok' => false, 'message' => $pub_page->get_error_message() ], 500 );
			}
			update_option( $option_key, $pub_page );
		}

		delete_transient( $lock_key );

		return new WP_REST_Response( [
			'ok'       => true,
			'page_id'  => $pub_page,
			'page_url' => get_permalink( $pub_page ),
		], 200 );
	}

	/* ═══════════════════════════════════════════════
	 *  DELETE PUBLISHED WP PAGE
	 * ═══════════════════════════════════════════════ */

	public static function handle_delete_page( WP_REST_Request $request ): WP_REST_Response {
		$project_id = (int) $request->get_param( 'id' );

		$project = BZCode_Project_Manager::get_by_id( $project_id );
		if ( ! $project || (int) $project->user_id !== get_current_user_id() ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => 'Project not found.' ], 404 );
		}

		$option_key = 'bzcode_pub_page_' . $project_id;
		$pub_page   = (int) get_option( $option_key, 0 );

		if ( ! $pub_page || ! get_post( $pub_page ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => 'Không có trang đã xuất bản.' ], 404 );
		}

		wp_delete_post( $pub_page, true ); // force delete (skip trash)
		delete_option( $option_key );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/* ═══════════════════════════════════════════════
	 *  SOURCE MANAGEMENT
	 * ═══════════════════════════════════════════════ */

	public static function handle_list_sources( \WP_REST_Request $request ): \WP_REST_Response {
		$project_id = (int) $request['id'];
		$sources    = BZCode_Source_Manager::list_by_project( $project_id );
		return new \WP_REST_Response( [ 'ok' => true, 'data' => $sources ] );
	}

	public static function handle_source_upload( \WP_REST_Request $request ): \WP_REST_Response {
		$project_id = (int) ( $request->get_param( 'project_id' ) ?: ( $_POST['project_id'] ?? 0 ) );
		if ( ! $project_id ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Missing project_id' ], 400 );
		}

		if ( empty( $_FILES['file'] ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'No file uploaded' ], 400 );
		}

		$result = BZCode_Source_Manager::upload( $project_id, $_FILES['file'] );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => $result->get_error_message() ], 400 );
		}

		return new \WP_REST_Response( [
			'ok'        => true,
			'source_id' => $result,
			'sources'   => BZCode_Source_Manager::list_by_project( $project_id ),
		] );
	}

	public static function handle_source_add_text( \WP_REST_Request $request ): \WP_REST_Response {
		$body       = $request->get_json_params() ?: [];
		$project_id = (int) ( $body['project_id'] ?? 0 );
		$text       = $body['text'] ?? '';
		$title      = sanitize_text_field( $body['title'] ?? 'Text source' );

		if ( ! $project_id || empty( trim( $text ) ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Missing project_id or text' ], 400 );
		}

		$source_id = BZCode_Source_Manager::create( $project_id, [
			'title'        => $title,
			'source_type'  => 'text',
			'content_text' => $text,
		] );

		return new \WP_REST_Response( [
			'ok'        => true,
			'source_id' => $source_id,
			'sources'   => BZCode_Source_Manager::list_by_project( $project_id ),
		] );
	}

	public static function handle_source_add_url( \WP_REST_Request $request ): \WP_REST_Response {
		$body       = $request->get_json_params() ?: [];
		$project_id = (int) ( $body['project_id'] ?? 0 );
		$url        = esc_url_raw( $body['url'] ?? '' );

		if ( ! $project_id || empty( $url ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Missing project_id or URL' ], 400 );
		}

		// Fetch URL content
		$response = wp_remote_get( $url, [ 'timeout' => 15, 'sslverify' => false ] );
		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Fetch failed: ' . $response->get_error_message() ], 400 );
		}

		$html = wp_remote_retrieve_body( $response );
		$text = wp_strip_all_tags( $html );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		if ( mb_strlen( $text ) < 10 ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'URL content too short or empty' ], 400 );
		}

		$title = '';
		if ( preg_match( '/<title>([^<]+)<\/title>/i', $html, $m ) ) {
			$title = sanitize_text_field( trim( $m[1] ) );
		}
		if ( empty( $title ) ) {
			$title = wp_parse_url( $url, PHP_URL_HOST ) ?: $url;
		}

		$source_id = BZCode_Source_Manager::create( $project_id, [
			'title'        => $title,
			'source_type'  => 'url',
			'source_url'   => $url,
			'content_text' => mb_substr( $text, 0, 200000 ),
		] );

		return new \WP_REST_Response( [
			'ok'        => true,
			'source_id' => $source_id,
			'sources'   => BZCode_Source_Manager::list_by_project( $project_id ),
		] );
	}

	public static function handle_source_delete( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		$ok = BZCode_Source_Manager::delete( $id );
		return new \WP_REST_Response( [ 'ok' => $ok ] );
	}

	public static function handle_source_search( \WP_REST_Request $request ): \WP_REST_Response {
		$body  = $request->get_json_params() ?: [];
		$query = sanitize_text_field( $body['query'] ?? '' );

		if ( empty( $query ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Missing query' ], 400 );
		}

		// Delegate to Tavily search if available (same as bizcity-doc)
		if ( function_exists( 'bizcity_web_search' ) ) {
			$results = bizcity_web_search( $query, [ 'max_results' => 5 ] );
			return new \WP_REST_Response( [ 'ok' => true, 'results' => $results ] );
		}

		return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Web search not available' ], 400 );
	}

	public static function handle_source_search_import( \WP_REST_Request $request ): \WP_REST_Response {
		$body       = $request->get_json_params() ?: [];
		$project_id = (int) ( $body['project_id'] ?? 0 );
		$items      = $body['items'] ?? [];

		if ( ! $project_id || empty( $items ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Missing data' ], 400 );
		}

		$imported = 0;
		foreach ( $items as $item ) {
			$title   = sanitize_text_field( $item['title'] ?? '' );
			$url     = esc_url_raw( $item['url'] ?? '' );
			$content = $item['content'] ?? $item['snippet'] ?? '';

			if ( ! empty( $content ) ) {
				BZCode_Source_Manager::create( $project_id, [
					'title'        => $title ?: $url,
					'source_type'  => 'search',
					'source_url'   => $url,
					'content_text' => $content,
				] );
				$imported++;
			}
		}

		return new \WP_REST_Response( [
			'ok'       => true,
			'imported' => $imported,
			'sources'  => BZCode_Source_Manager::list_by_project( $project_id ),
		] );
	}
}
