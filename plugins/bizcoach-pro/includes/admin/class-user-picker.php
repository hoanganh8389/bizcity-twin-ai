<?php
/**
 * BizCoach Pro — Phase 0.3 H.2 · Multisite-safe user picker
 *
 * Exposes REST `GET /bizcoach-pro/v1/users/search?q=…` for the H.3 add/edit
 * form so admins can attach a chart to an EXISTING user instead of
 * creating duplicates. Hard-scoped to `get_current_blog_id()` so a
 * subsite admin never sees users from other subsites of the multisite
 * network.
 *
 * Threat model:
 *   - REST endpoint requires `manage_options` (admin-only).
 *   - Query string `q` minimum 2 chars; trimmed; capped at 80 chars.
 *   - Max 20 results per request.
 *   - Rate-limit: 60 requests / minute / user (transient counter).
 *   - Email is OMITTED unless caller has `edit_users` capability.
 *
 * Companion asset: `assets/admin-user-picker.js` (+ .css). Caller pages
 * enqueue via `BizCoach_Pro_User_Picker::enqueue()` then mount with:
 *   <input type="text" data-bcpro-user-picker data-target="#user_id_input">
 *   <input type="hidden" name="user_id" id="user_id_input" value="">
 *
 * @package BizCoach_Pro
 * @since   0.3.0 (PHASE-0.3-ASTRO-MULTI-SYSTEM-ADMIN — H.2)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_User_Picker' ) ) { return; }

final class BizCoach_Pro_User_Picker {

	const REST_NAMESPACE = 'bizcoach-pro/v1';
	const REST_ROUTE     = '/users/search';

	const MAX_RESULTS    = 20;
	const MIN_QUERY_LEN  = 2;
	const MAX_QUERY_LEN  = 80;
	const RATE_PER_MIN   = 60;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_search' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
			'args'                => array(
				'q' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'system' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
					'enum'              => array( 'western', 'vedic', 'chinese' ),
				),
			),
		) );
	}

	public static function rest_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function rest_search( WP_REST_Request $req ) {
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( strlen( $q ) < self::MIN_QUERY_LEN ) {
			return new WP_REST_Response( array( 'results' => array(), 'error' => 'q_too_short' ), 200 );
		}
		if ( strlen( $q ) > self::MAX_QUERY_LEN ) {
			$q = substr( $q, 0, self::MAX_QUERY_LEN );
		}

		// Rate-limit per-user.
		$uid = get_current_user_id();
		$key = 'bcpro_picker_rl_' . $uid;
		$hit = (int) get_transient( $key );
		if ( $hit >= self::RATE_PER_MIN ) {
			return new WP_REST_Response( array( 'results' => array(), 'error' => 'rate_limited' ), 429 );
		}
		set_transient( $key, $hit + 1, MINUTE_IN_SECONDS );

		$system   = (string) $req->get_param( 'system' );
		$show_email = current_user_can( 'edit_users' );

		$args = array(
			'search'         => '*' . $q . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
			'number'         => self::MAX_RESULTS,
			'fields'         => array( 'ID', 'user_login', 'display_name', 'user_email' ),
			'orderby'        => 'display_name',
			'order'          => 'ASC',
		);
		if ( is_multisite() ) {
			$args['blog_id'] = get_current_blog_id();
		}

		$users = get_users( $args );

		// Look up which systems each user already has a chart for (current blog).
		$existing = self::existing_charts_for_users( wp_list_pluck( $users, 'ID' ) );

		$out = array();
		foreach ( $users as $u ) {
			$uid_i = (int) $u->ID;
			$row   = array(
				'id'        => $uid_i,
				'login'     => (string) $u->user_login,
				'display'   => (string) $u->display_name,
				'has'       => isset( $existing[ $uid_i ] ) ? $existing[ $uid_i ] : array(),
			);
			if ( $show_email ) {
				$row['email'] = (string) $u->user_email;
			}
			$out[] = $row;
		}

		return new WP_REST_Response( array(
			'q'         => $q,
			'count'     => count( $out ),
			'results'   => $out,
			'requested' => $system ?: null,
			'blog_id'   => is_multisite() ? get_current_blog_id() : 0,
		), 200 );
	}

	/**
	 * Return [user_id => ['western'=>bool,'vedic'=>bool,'chinese'=>bool]]
	 * for the given user ids. Limited to coachees on the current blog
	 * (bccm_profiles has a one-to-many relation; bccm_astro keys by
	 * coachee_id which is local to this blog's bccm_coachees table).
	 */
	private static function existing_charts_for_users( array $user_ids ): array {
		if ( ! $user_ids ) { return array(); }
		global $wpdb;
		$t_astro = $wpdb->prefix . 'bccm_astro';
		$t_prof  = $wpdb->prefix . 'bccm_coachees';

		// Defensive: schema may not yet have chart_type column on a subsite.
		if ( function_exists( 'bccm_astro_supports_chart_type' ) && ! bccm_astro_supports_chart_type() ) {
			return array();
		}

		$ids_in = implode( ',', array_map( 'intval', $user_ids ) );
		$sql = "SELECT p.user_id, a.chart_type
		        FROM {$t_astro} a
		        INNER JOIN {$t_prof} p ON p.id = a.coachee_id
		        WHERE p.user_id IN ({$ids_in})";
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$uid = (int) $r['user_id'];
			$sys = (string) $r['chart_type'];
			if ( ! isset( $out[ $uid ] ) ) { $out[ $uid ] = array(); }
			$out[ $uid ][ $sys ] = true;
		}
		return $out;
	}

	/* ============================================================
	 * Asset enqueue helper — call from any admin page that mounts
	 * the picker. Idempotent (WP de-dupes registrations).
	 * ============================================================ */
	public static function enqueue(): void {
		$base_url = defined( 'BCPRO_URL' ) ? BCPRO_URL : plugins_url( '/', dirname( __DIR__ ) );
		$ver      = defined( 'BCPRO_VERSION' ) ? BCPRO_VERSION : '0.3.0';

		wp_register_script(
			'bcpro-user-picker',
			$base_url . 'assets/admin-user-picker.js',
			array(),
			$ver,
			true
		);
		wp_register_style(
			'bcpro-user-picker',
			$base_url . 'assets/admin-user-picker.css',
			array(),
			$ver
		);
		wp_localize_script( 'bcpro-user-picker', 'bcproUserPicker', array(
			'restUrl' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				'placeholder' => __( 'Gõ tên / login / email để tìm user…', 'bcpro' ),
				'searching'   => __( 'Đang tìm…', 'bcpro' ),
				'no_results'  => __( 'Không tìm thấy user nào.', 'bcpro' ),
				'rate_limit'  => __( 'Tìm quá nhanh — chờ một chút.', 'bcpro' ),
				'has_chart'   => __( 'đã có', 'bcpro' ),
			),
		) );
		wp_enqueue_script( 'bcpro-user-picker' );
		wp_enqueue_style( 'bcpro-user-picker' );
	}
}
