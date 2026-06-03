<?php
/**
 * Bizcity Twin AI — Notebook Skeleton REST controller
 *
 * Exposes the 4 endpoints mandated by PHASE-0-RULE-SKELETON RULE-6,
 * registered under the canonical brand namespace `bizcity/kg/v1`
 * (PHASE-0-RULE-NAMESPACE §1) plus a deprecated alias `bzkg/v1` that
 * will be removed after 2 releases (PHASE-0-RULE-NAMESPACE §7).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-11
 * @see        PHASE-0-RULE-SKELETON.md   RULE-6
 * @see        PHASE-0-RULE-NAMESPACE.md  §1, §7
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Skeleton_REST {

	/** Canonical namespace per PHASE-0-RULE-NAMESPACE §1.1. */
	const NS = 'bizcity/kg/v1';

	/** Legacy namespace — kept 2 releases for back-compat. */
	const NS_LEGACY = 'bzkg/v1';

	public static function bind(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$routes = [
			[
				'route'   => '/notebooks',
				'methods' => 'GET',
				'cb'      => [ __CLASS__, 'list_notebooks' ],
				'perm'    => [ __CLASS__, 'require_login' ],
				'args'    => [
					'has_skeleton' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'search'       => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'limit'        => [
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					],
				],
			],
			[
				'route'   => '/notebook/(?P<id>\d+)/skeleton',
				'methods' => 'GET',
				'cb'      => [ __CLASS__, 'get_skeleton' ],
				'perm'    => [ __CLASS__, 'require_owner' ],
			],
			[
				'route'   => '/notebook/(?P<id>\d+)/skeleton/status',
				'methods' => 'GET',
				'cb'      => [ __CLASS__, 'get_status' ],
				'perm'    => [ __CLASS__, 'require_owner' ],
			],
			[
				'route'   => '/notebook/(?P<id>\d+)/skeleton/rebuild',
				'methods' => 'POST',
				'cb'      => [ __CLASS__, 'post_rebuild' ],
				'perm'    => [ __CLASS__, 'require_owner' ],
			],
		];

		foreach ( [ self::NS, self::NS_LEGACY ] as $ns ) {
			$is_legacy = ( $ns === self::NS_LEGACY );
			foreach ( $routes as $r ) {
				register_rest_route( $ns, $r['route'], [
					'methods'             => $r['methods'],
					'callback'            => $is_legacy
						? function ( $request ) use ( $r ) {
							$resp = call_user_func( $r['cb'], $request );
							if ( $resp instanceof WP_REST_Response ) {
								$resp->header( 'X-Deprecated-Namespace',
									self::NS_LEGACY . '; use=' . self::NS );
							}
							return $resp;
						}
						: $r['cb'],
					'permission_callback' => $r['perm'],
					'args'                => $r['args'] ?? [],
				] );
			}
		}
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Permission callbacks — F-5 pluggable
	 * ──────────────────────────────────────────────────────────────── */

	public static function require_login(): bool {
		return is_user_logged_in();
	}

	public static function require_owner( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$nb = (int) $request->get_param( 'id' );
		try {
			return BizCity_KG_Skeleton_Adapter::user_can_read( $nb, get_current_user_id() );
		} catch ( \Throwable $e ) {
			error_log( '[KG Skeleton REST] require_owner error: ' . $e->getMessage() );
			return false;
		}
	}

	/* ──────────────────────────────────────────────────────────────────
	 *  Endpoint handlers
	 * ──────────────────────────────────────────────────────────────── */

	public static function list_notebooks( WP_REST_Request $req ) {
		$opts = [
			'limit'  => (int) ( $req->get_param( 'limit' ) ?: 50 ),
			'search' => (string) ( $req->get_param( 'search' ) ?: '' ),
		];
		$has = $req->get_param( 'has_skeleton' );
		if ( null !== $has && '' !== $has ) {
			$opts['has_skeleton'] = filter_var( $has, FILTER_VALIDATE_BOOLEAN );
		}

		$rows = BizCity_KG_Skeleton_Adapter::get_notebook_list( get_current_user_id(), $opts );
		return new WP_REST_Response( [ 'items' => $rows ], 200 );
	}

	public static function get_skeleton( WP_REST_Request $req ) {
		$nb       = (int) $req->get_param( 'id' );
		$skeleton = BizCity_KG_Skeleton_Adapter::get_skeleton( $nb );
		if ( ! $skeleton ) {
			return new WP_REST_Response( [
				'ready'        => false,
				'skeleton'     => null,
				'prompt_block' => '',
				'status'       => self::status_string( $nb ),
			], 200 );
		}
		// PHASE-0-RULE-SKELETON Sprint 0★ — also surface the formatted Markdown
		// prompt block so the FE can pre-fill the editable “summary” textarea
		// (Sprint 0★ FE handoff) without re-implementing the formatter in JS.
		return new WP_REST_Response( [
			'ready'        => true,
			'skeleton'     => $skeleton,
			'prompt_block' => (string) BizCity_KG_Skeleton_Adapter::get_prompt_block( $nb ),
			'status'       => 'ready',
		], 200 );
	}

	public static function get_status( WP_REST_Request $req ) {
		$nb = (int) $req->get_param( 'id' );
		try {
			return new WP_REST_Response( [
				'notebook_id' => $nb,
				'status'      => self::status_string( $nb ),
				'version'     => BizCity_KG_Skeleton_Adapter::get_version( $nb ),
				'ready'       => BizCity_KG_Skeleton_Adapter::is_ready( $nb ),
			], 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [
				'notebook_id' => $nb,
				'error'       => $e->getMessage(),
				'error_class' => get_class( $e ),
				'error_file'  => $e->getFile() . ':' . $e->getLine(),
			], 500 );
		}
	}

	public static function post_rebuild( WP_REST_Request $req ) {
		$nb = (int) $req->get_param( 'id' );
		BizCity_KG_Skeleton_Adapter::mark_dirty( $nb );
		return new WP_REST_Response( [ 'queued' => true, 'notebook_id' => $nb ], 202 );
	}

	private static function status_string( int $notebook_id ): string {
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
		$s   = $wpdb->get_var( $wpdb->prepare(
			"SELECT skeleton_status FROM {$tbl} WHERE id = %d", $notebook_id
		) );
		return (string) ( $s ?: '' );
	}
}

// Back-compat alias — PHASE-0-RULE-NAMESPACE §2.2.
if ( ! class_exists( 'BZKG_Skeleton_REST' ) ) {
	class_alias( 'BizCity_KG_Skeleton_REST', 'BZKG_Skeleton_REST' );
}
