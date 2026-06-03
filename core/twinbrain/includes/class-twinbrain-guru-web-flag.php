<?php
/**
 * TwinBrain — Guru Web Fallback Flag (TBR.W11-be-guru-flag).
 *
 * PHASE 0.36-UNIFIED §3.5 — Priority Stack hàng "@guru":
 *
 *   > **OFF mặc định** — guru = scope đóng (R-TG); chỉ bật nếu
 *   > `guru.allow_web_fallback = true`
 *
 * Module này thực thi rule đó bằng 3 component:
 *
 *   1. **Schema migration** (idempotent) — thêm cột
 *      `allow_web_fallback TINYINT(1) NOT NULL DEFAULT 0` vào
 *      `wp_bizcity_characters` qua `SHOW COLUMNS LIKE` guard.
 *
 *   2. **Runtime guard** (`gate_web_mode`) — filter
 *      `bizcity_twinbrain_web_mode_effective` để Web Research dispatcher
 *      có thể hỏi "guru này có cho phép web fallback không?" trước khi
 *      gọi Web_Quick / Web_Deep.
 *
 *   3. **REST PATCH** `/wp-json/bizcity-twinbrain/v1/guru/(?P<id>\d+)/web-fallback`
 *      để admin UI (hoặc curl) toggle flag mà không cần build full
 *      character editor UI lại — tab UI sẽ được wire ở sprint sau khi
 *      Knowledge Admin tab framework refactor xong.
 *
 * R-TG-1 nhắc: "guru" = brand name; KHÔNG dùng "character" trong UI strings.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-21 (Phase 0.36-UNIFIED TBR.W11)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Guru_Web_Flag {

	const COLUMN_NAME    = 'allow_web_fallback';
	const SCHEMA_OPTION  = 'bizcity_twinbrain_guru_web_flag_schema_v1';
	const CACHE_GROUP    = 'bizcity_twinbrain_guru_web_flag';
	const CACHE_TTL      = 300; // 5 min — read-mostly column.
	const REST_NS        = 'bizcity-twinbrain/v1';

	public static function register_hooks(): void {
		add_action( 'init',          [ __CLASS__, 'maybe_install_schema' ], 25 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );

		// W9 dispatcher consults this filter before dispatching Web_Quick/Deep.
		add_filter( 'bizcity_twinbrain_web_mode_effective', [ __CLASS__, 'gate_web_mode' ], 10, 3 );
	}

	/* =================================================================
	 *  Schema
	 * ================================================================ */

	public static function maybe_install_schema(): void {
		if ( get_option( self::SCHEMA_OPTION ) === '1' ) return;
		if ( self::install_schema() ) {
			update_option( self::SCHEMA_OPTION, '1', false );
		}
	}

	/**
	 * Force-add column if missing. Returns true when column exists after run.
	 */
	public static function install_schema(): bool {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_characters';

		// Verify base table exists (skips fresh installs without the guru module).
		$prev   = $wpdb->suppress_errors( true );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
		if ( $exists !== $tbl ) {
			$wpdb->suppress_errors( $prev );
			return false;
		}

		$col = $wpdb->get_results( $wpdb->prepare(
			"SHOW COLUMNS FROM `{$tbl}` LIKE %s",
			self::COLUMN_NAME
		) );

		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE `{$tbl}` ADD COLUMN `" . self::COLUMN_NAME . "` TINYINT(1) NOT NULL DEFAULT 0" );
		}

		$wpdb->suppress_errors( $prev );
		return true;
	}

	/* =================================================================
	 *  Gate
	 * ================================================================ */

	/**
	 * Effective web_mode after consulting guru flag.
	 *
	 * @param string $web_mode  Requested mode from FE ('off'|'quick'|'deep').
	 * @param int    $guru_id   Bound guru id (0 = no guru → no gate, pass-through).
	 * @param array  $context   Extra context (trace_id, prompt, etc.) — unused for now.
	 * @return string Effective mode ('off' if blocked).
	 */
	public static function gate_web_mode( string $web_mode, int $guru_id, array $context = [] ): string {
		if ( $web_mode === 'off' || $guru_id <= 0 ) {
			return $web_mode; // no gate needed
		}
		$allowed = self::is_allowed( $guru_id );
		if ( $allowed ) {
			return $web_mode;
		}
		// Blocked → emit telemetry so FE timeline can show "Web fallback blocked
		// by guru policy (allow_web_fallback=false)" instead of silent no-op.
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( 'web_research_blocked', [
					'trace_id' => (string) ( $context['trace_id'] ?? '' ),
					'guru_id'  => $guru_id,
					'requested'=> $web_mode,
					'reason'   => 'guru_policy_off',
				] );
			} catch ( \Throwable $e ) { /* swallow */ }
		}
		return 'off';
	}

	/**
	 * Read `allow_web_fallback` for a guru. Cached for 5 min per request via
	 * wp_cache_get (multisite-safe through BLOG_ID_CURRENT_SITE prefix).
	 */
	public static function is_allowed( int $guru_id ): bool {
		if ( $guru_id <= 0 ) return false;

		$cache_key = 'guru_' . $guru_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( $cached !== false ) {
			return (bool) (int) $cached;
		}

		global $wpdb;
		$tbl  = $wpdb->prefix . 'bizcity_characters';
		$prev = $wpdb->suppress_errors( true );
		$val  = $wpdb->get_var( $wpdb->prepare(
			"SELECT `" . self::COLUMN_NAME . "` FROM `{$tbl}` WHERE id = %d LIMIT 1",
			$guru_id
		) );
		$wpdb->suppress_errors( $prev );

		// Column missing or guru not found → default OFF (R-TG closed scope).
		$bool = ( $val !== null && (int) $val === 1 );
		wp_cache_set( $cache_key, $bool ? '1' : '0', self::CACHE_GROUP, self::CACHE_TTL );
		return $bool;
	}

	/**
	 * Toggle the flag (admin-only). Returns new value (true/false).
	 */
	public static function set_allowed( int $guru_id, bool $allowed ): bool {
		if ( $guru_id <= 0 ) return false;

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_characters';

		$updated = $wpdb->update(
			$tbl,
			[ self::COLUMN_NAME => $allowed ? 1 : 0 ],
			[ 'id' => $guru_id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( $updated !== false ) {
			wp_cache_delete( 'guru_' . $guru_id, self::CACHE_GROUP );
		}
		return $updated !== false;
	}

	/* =================================================================
	 *  REST
	 * ================================================================ */

	public static function register_rest_routes(): void {
		register_rest_route( self::REST_NS, '/guru/(?P<id>\d+)/web-fallback', [
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'args' => [
				'id'      => [ 'type' => 'integer', 'required' => true ],
				'allowed' => [ 'type' => 'boolean', 'required' => true ],
			],
			'callback' => [ __CLASS__, 'rest_set_flag' ],
		] );

		register_rest_route( self::REST_NS, '/guru/(?P<id>\d+)/web-fallback', [
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback' => [ __CLASS__, 'rest_get_flag' ],
		] );
	}

	public static function rest_set_flag( WP_REST_Request $req ) {
		$id      = (int) $req['id'];
		$allowed = (bool) $req->get_param( 'allowed' );
		$ok      = self::set_allowed( $id, $allowed );
		return rest_ensure_response( [
			'ok'       => $ok,
			'guru_id'  => $id,
			'allowed'  => $allowed,
		] );
	}

	public static function rest_get_flag( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		return rest_ensure_response( [
			'guru_id' => $id,
			'allowed' => self::is_allowed( $id ),
		] );
	}
}

BizCity_TwinBrain_Guru_Web_Flag::register_hooks();
