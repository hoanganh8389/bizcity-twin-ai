<?php
/**
 * Bizcity Twin AI — Guru admin REST (bizcity-knowledge/v2/gurus/...).
 *
 * Thin controller over BizCity_Knowledge_Guru_Service for the Guru editor on `/twinkg/`
 * (CORE-REDUCTION-WP-09 §17.2, routes R1–R9, approved 2026-09-24 as T5-D1).
 *
 *   GET    /gurus/admin               R1  admin list
 *   GET    /gurus/{id}/profile        R2  profile + extension sections
 *   PATCH  /gurus/{id}/profile        R3  partial update
 *   DELETE /gurus/{id}                R4  delete (+ detach notebooks)
 *   POST   /gurus/{id}/duplicate      R5  duplicate
 *   GET    /gurus/slug-check          R6  slug availability
 *   GET    /gurus/models              R7  model list via the LLM gateway
 *   POST   /gurus/{id}/quick-faq      R8  create/update one quick-FAQ row
 *   DELETE /gurus/{id}/quick-faq/{s}  R8  delete one quick-FAQ row
 *   GET    /gurus/{id}/export         R9  portable Guru file (no embeddings)
 *   POST   /gurus/{id}/import         R9  import a Guru file (sources queued, then embedded via v1 quick-edit)
 *
 * Every route is admin-only (WP-08 U-2): BizCity_Network_Admin_Capability::can_manage(),
 * falling back to manage_options — the same gate as the legacy AJAX handlers and the v1
 * quick-edit REST. The existing logged-in `GET /gurus` (attach picker) is not touched.
 *
 * PHP 7.4 compatible — no match, no enums, no nullsafe, no readonly.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @since      2026-09-24
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Guru_Admin_REST {

	/** Same namespace as BizCity_KG_Rest_Controller::NAMESPACE_V2 (kg-hub may load later). */
	const NS = 'bizcity-knowledge/v2';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function can_manage(): bool {
		return class_exists( 'BizCity_Network_Admin_Capability' )
			? BizCity_Network_Admin_Capability::can_manage()
			: current_user_can( 'manage_options' );
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'can_manage' );

		register_rest_route( self::NS, '/gurus/admin', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_admin' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/gurus/slug-check', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'slug_check' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/gurus/models', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'models' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/gurus/(?P<id>\d+)/profile', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_profile' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'patch_profile' ),
				'permission_callback' => $perm,
			),
		) );
		register_rest_route( self::NS, '/gurus/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/gurus/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'duplicate' ),
			'permission_callback' => $perm,
		) );
		// R8 (WP-09 T5b) — per-row quick FAQ; the v1 quick-edit full replace stays for Bot Studio.
		register_rest_route( self::NS, '/gurus/(?P<id>\d+)/quick-faq', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'quick_faq_upsert' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/gurus/(?P<id>\d+)/quick-faq/(?P<source_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'quick_faq_delete' ),
			'permission_callback' => $perm,
		) );
		// R9 (WP-09 T5c) — portable Guru file; never embeddings (T5-D4).
		register_rest_route( self::NS, '/gurus/(?P<id>\d+)/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/gurus/(?P<id>\d+)/import', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import' ),
			'permission_callback' => $perm,
		) );
	}

	public static function export( WP_REST_Request $req ) {
		return self::respond( self::service()->export( (int) $req['id'] ) );
	}

	/** Body: { data: <file JSON>, overwrite?: bool, apply_profile?: bool }. */
	public static function import( WP_REST_Request $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['data'] ) ) {
			return new WP_Error( 'invalid_body', 'Body cần { data, overwrite?, apply_profile? }.', array( 'status' => 400 ) );
		}
		return self::respond( self::service()->import( (int) $req['id'], $body['data'], array(
			'overwrite'     => ! empty( $body['overwrite'] ),
			'apply_profile' => ! empty( $body['apply_profile'] ),
		) ) );
	}

	public static function quick_faq_upsert( WP_REST_Request $req ) {
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : array();
		return self::respond( self::service()->quick_faq_upsert(
			(int) $req['id'],
			(int) ( $body['source_id'] ?? 0 ),
			(string) ( $body['title'] ?? '' ),
			(string) ( $body['content'] ?? '' )
		), 'row' );
	}

	public static function quick_faq_delete( WP_REST_Request $req ) {
		return self::respond( self::service()->quick_faq_delete( (int) $req['id'], (int) $req['source_id'] ) );
	}

	public static function list_admin( WP_REST_Request $req ) {
		return rest_ensure_response( array_merge(
			array( 'ok' => true ),
			self::service()->list_admin( array(
				'search'   => $req->get_param( 'search' ),
				'status'   => $req->get_param( 'status' ),
				'page'     => $req->get_param( 'page' ),
				'per_page' => $req->get_param( 'per_page' ),
			) )
		) );
	}

	public static function get_profile( WP_REST_Request $req ) {
		return self::respond( self::service()->get_profile( (int) $req['id'] ), 'profile' );
	}

	public static function patch_profile( WP_REST_Request $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_body', 'Body phải là JSON object.', array( 'status' => 400 ) );
		}
		return self::respond( self::service()->patch_profile( (int) $req['id'], $body ), 'profile' );
	}

	public static function delete( WP_REST_Request $req ) {
		return self::respond( self::service()->delete( (int) $req['id'] ) );
	}

	public static function duplicate( WP_REST_Request $req ) {
		return self::respond( self::service()->duplicate( (int) $req['id'] ), 'guru', 201 );
	}

	public static function slug_check( WP_REST_Request $req ) {
		return self::respond( self::service()->slug_check(
			(string) $req->get_param( 'name' ),
			(string) $req->get_param( 'slug' ),
			(int) $req->get_param( 'exclude_id' )
		) );
	}

	public static function models( WP_REST_Request $req ) {
		return self::respond( self::service()->models() );
	}

	private static function service(): BizCity_Knowledge_Guru_Service {
		return BizCity_Knowledge_Guru_Service::instance();
	}

	/**
	 * Wrap a service result: WP_Error passes through (WP REST renders it as the standard
	 * `{ code, message, data.status }` envelope); arrays gain `ok: true`, optionally nested.
	 */
	private static function respond( $result, string $key = '', int $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$payload = '' === $key ? array_merge( array( 'ok' => true ), (array) $result ) : array( 'ok' => true, $key => $result );
		return new WP_REST_Response( $payload, $status );
	}
}

BizCity_Guru_Admin_REST::init();
