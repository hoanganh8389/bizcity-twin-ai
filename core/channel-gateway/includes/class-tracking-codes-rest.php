<?php
/**
 * BizCity Tracking Codes — REST + Frontend Injector
 *
 * Cho phép admin chèn snippet theo dõi (Meta Pixel, GA4, GTM, TikTok Pixel,
 * Custom Head/Body/Footer) vào toàn bộ frontend mà không cần chỉnh theme.
 *
 * REST namespace : bizcity-channel/v1
 * Routes         :
 *   GET  /tracking          — Lấy danh sách snippets đã cấu hình
 *   POST /tracking          — Lưu toàn bộ danh sách snippets
 *
 * Storage        : wp_options key `bizcity_tracking_codes` (per-blog, JSON array)
 * Injection      : wp_head (position=head) · wp_body_open (position=body) · wp_footer (position=footer)
 *
 * Cache Contract :
 *   Group  : bzcc_tracking
 *   Key    : snippets_active
 *   TTL    : BizCity_Cache::TTL_LONG (24h)
 *   Flush  : on POST /tracking save
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      2026-06-27
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-27 Johnny Chu] PHASE-CG-TRACKING — Tracking Codes REST + frontend injector.
class BizCity_Tracking_Codes_REST {

	private const NS          = 'bizcity-channel/v1';
	private const OPTION_KEY  = 'bizcity_tracking_codes';
	private const CACHE_GROUP = 'bzcc_tracking';
	private const CACHE_KEY   = 'snippets_active';

	/** Allowed snippet types. */
	private const VALID_TYPES = array(
		'meta_pixel',
		'google_analytics',
		'google_tag_manager',
		'tiktok_pixel',
		'custom_head',
		'custom_body',
		'custom_footer',
	);

	// ------------------------------------------------------------------
	// Boot
	// ------------------------------------------------------------------

	/**
	 * Register REST routes + frontend injection hooks.
	 * Called once from bootstrap.php.
	 */
	public static function init(): void {
		// REST routes.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Frontend injection — only on public-facing requests.
		add_action( 'wp_head',       array( __CLASS__, 'inject_head' ),   1 );
		add_action( 'wp_body_open',  array( __CLASS__, 'inject_body' ),   1 );
		add_action( 'wp_footer',     array( __CLASS__, 'inject_footer' ), 1 );
	}

	// ------------------------------------------------------------------
	// REST
	// ------------------------------------------------------------------

	public static function register_routes(): void {
		register_rest_route( self::NS, '/tracking', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_snippets' ),
				'permission_callback' => array( __CLASS__, 'require_manage_options' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_snippets' ),
				'permission_callback' => array( __CLASS__, 'require_manage_options' ),
				'args'                => array(
					'snippets' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			),
		) );
	}

	/** GET /tracking */
	public static function get_snippets(): WP_REST_Response {
		return new WP_REST_Response( array(
			'success'  => true,
			'snippets' => self::load_snippets(),
		), 200 );
	}

	/** POST /tracking */
	public static function save_snippets( WP_REST_Request $req ) {
		$raw = $req->get_param( 'snippets' );
		if ( ! is_array( $raw ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'snippets phải là array.' ), 400 );
		}

		$clean = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : '';
			if ( ! in_array( $type, self::VALID_TYPES, true ) ) {
				continue;
			}
			// Admin-only: allow raw HTML/JS snippet (Đoạn mã Meta Pixel, GA4, GTM chứa <script>).
			// wp_kses_post() loại bỏ <script>, dùng wp_unslash() thay thế.
			$snippet = isset( $item['snippet'] ) ? wp_unslash( (string) $item['snippet'] ) : '';

			// Scope: global | page_ids | url_contains.
			$scope_type          = in_array( $item['scope_type'] ?? '', array( 'global', 'page_ids', 'url_contains' ), true )
				? $item['scope_type'] : 'global';
			$scope_page_ids      = isset( $item['scope_page_ids'] ) ? sanitize_text_field( (string) $item['scope_page_ids'] ) : '';
			$scope_url_contains  = isset( $item['scope_url_contains'] ) ? sanitize_text_field( (string) $item['scope_url_contains'] ) : '';

			$clean[] = array(
				'type'               => $type,
				'enabled'            => ! empty( $item['enabled'] ),
				'label'              => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '',
				'snippet'            => $snippet,
				'scope_type'         => $scope_type,
				'scope_page_ids'     => $scope_page_ids,
				'scope_url_contains' => $scope_url_contains,
			);
		}

		update_option( self::OPTION_KEY, wp_json_encode( $clean ), false );

		// Flush cache.
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}

		return new WP_REST_Response( array(
			'success'  => true,
			'message'  => 'Đã lưu ' . count( $clean ) . ' snippet(s).',
			'snippets' => $clean,
		), 200 );
	}

	public static function require_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	// ------------------------------------------------------------------
	// Storage helpers
	// ------------------------------------------------------------------

	/**
	 * Load snippets — check cache first, fallback to wp_options.
	 *
	 * @return array<int, array{type:string, enabled:bool, label:string, snippet:string}>
	 */
	private static function load_snippets(): array {
		// Try cache.
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, self::CACHE_KEY );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}

		$raw  = get_option( self::OPTION_KEY, '[]' );
		$data = json_decode( (string) $raw, true );
		$out  = is_array( $data ) ? $data : array();

		// Store in cache.
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, self::CACHE_KEY, $out, BizCity_Cache::TTL_LONG );
		}

		return $out;
	}

	/**
	 * Return only enabled snippets for a given position bucket.
	 *
	 * @param string $position  head | body | footer
	 * @return array<int, array>
	 */
	private static function get_active_for_position( string $position ): array {
		$map = array(
			'head'   => array( 'meta_pixel', 'google_analytics', 'google_tag_manager', 'tiktok_pixel', 'custom_head' ),
			'body'   => array( 'custom_body' ),
			'footer' => array( 'custom_footer' ),
		);

		// GTM also outputs a noscript body snippet — output it in body position too.
		if ( 'body' === $position ) {
			$map['body'][] = 'google_tag_manager';
		}

		$bucket = isset( $map[ $position ] ) ? $map[ $position ] : array();

		$out = array();
		foreach ( self::load_snippets() as $s ) {
			if ( empty( $s['enabled'] ) ) {
				continue;
			}
			if ( ! in_array( $s['type'], $bucket, true ) ) {
				continue;
			}
			if ( '' === trim( $s['snippet'] ) ) {
				continue;
			}

			// [2026-06-27 Johnny Chu] PHASE-CG-TRACKING — Per-page/campaign scope filter.
			$scope_type = isset( $s['scope_type'] ) ? (string) $s['scope_type'] : 'global';
			if ( 'page_ids' === $scope_type ) {
				$raw_ids = isset( $s['scope_page_ids'] ) ? (string) $s['scope_page_ids'] : '';
				$ids     = array_values( array_filter( array_map( 'intval', array_map( 'trim', explode( ',', $raw_ids ) ) ) ) );
				if ( ! empty( $ids ) && ! in_array( (int) get_the_ID(), $ids, true ) ) {
					continue;
				}
			} elseif ( 'url_contains' === $scope_type ) {
				$pattern = isset( $s['scope_url_contains'] ) ? (string) $s['scope_url_contains'] : '';
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
				if ( '' !== $pattern && false === strpos( $uri, $pattern ) ) {
					continue;
				}
			}

			$out[] = $s;
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Frontend injection
	// ------------------------------------------------------------------

	/** Outputs snippets that belong in <head>. */
	public static function inject_head(): void {
		$items = self::get_active_for_position( 'head' );
		if ( empty( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			// For GTM in head, skip the noscript variant (outputted in body).
			echo "\n<!-- BizCity Tracking: " . esc_html( $item['type'] ) . " -->\n";
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $item['snippet'] . "\n";
		}
	}

	/** Outputs snippets that belong right after <body> opening (wp_body_open). */
	public static function inject_body(): void {
		$items = self::get_active_for_position( 'body' );
		if ( empty( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			// For GTM: only output noscript iframe here, not the head JS.
			if ( 'google_tag_manager' === $item['type'] ) {
				// Extract <noscript> block from the snippet if present.
				if ( preg_match( '#<noscript>.*?</noscript>#si', $item['snippet'], $m ) ) {
					echo "\n<!-- BizCity Tracking: gtm_noscript -->\n";
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $m[0] . "\n";
				}
				continue;
			}
			echo "\n<!-- BizCity Tracking: " . esc_html( $item['type'] ) . " -->\n";
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $item['snippet'] . "\n";
		}
	}

	/** Outputs snippets that belong before </body> (wp_footer). */
	public static function inject_footer(): void {
		$items = self::get_active_for_position( 'footer' );
		if ( empty( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			echo "\n<!-- BizCity Tracking: " . esc_html( $item['type'] ) . " -->\n";
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $item['snippet'] . "\n";
		}
	}
}

// [2026-06-27 Johnny Chu] PHASE-CG-TRACKING — Register cache group.
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzcc_tracking', 'modules.tracking-codes', array(
		'snippets_active' => array( 'ttl' => DAY_IN_SECONDS, 'desc' => 'Active tracking snippets (all positions)' ),
	) );
}
