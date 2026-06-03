<?php
/**
 * BizCity Twin AI — Guru Bridge REST controller (Phase B / F7.B4).
 *
 * Namespace: bizcity-guru/v1
 *   (Tach khoi `bizcity/v1` — namespace cua LLM Router gateway, R-GW.
 *    Guru Bridge la admin internal, khong di qua gateway.)
 *
 * Routes:
 *   GET    /guru/{id}/skills
 *   POST   /guru/{id}/skills           body: { tool_id, tool_class?, priority? }
 *   DELETE /guru/{id}/skills/{tool}
 *   GET    /guru/{id}/skills/unbound   diff list
 *   GET    /guru/{id}/providers
 *   POST   /guru/{id}/providers        body: { provider_class, scope? }
 *   DELETE /guru/{id}/providers/{class}
 *   GET    /guru/{id}/providers/unbound
 *
 * All routes require `manage_options`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Bridge_REST', false ) ) {
	return;
}

class BizCity_Guru_Bridge_REST {

	const NS = 'bizcity-guru/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$auth = array( __CLASS__, 'check_admin' );

		register_rest_route( self::NS, '/guru/(?P<id>\d+)/skills', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_skills' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'post_skill' ),
				'permission_callback' => $auth,
				'args'                => array(
					'tool_id'    => array( 'required' => true,  'type' => 'string' ),
					'tool_class' => array( 'required' => false, 'type' => 'string' ),
					'priority'   => array( 'required' => false, 'type' => 'integer' ),
				),
			),
		) );

		register_rest_route( self::NS, '/guru/(?P<id>\d+)/skills/unbound', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_skills_unbound' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NS, '/guru/(?P<id>\d+)/skills/(?P<tool>[A-Za-z0-9_\-\.]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_skill' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NS, '/guru/(?P<id>\d+)/providers', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_providers' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'post_provider' ),
				'permission_callback' => $auth,
				'args'                => array(
					'provider_class' => array( 'required' => true,  'type' => 'string' ),
					'scope'          => array( 'required' => false, 'type' => 'object' ),
				),
			),
		) );

		register_rest_route( self::NS, '/guru/(?P<id>\d+)/providers/unbound', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_providers_unbound' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NS, '/guru/(?P<id>\d+)/providers/(?P<class>[A-Za-z0-9_\\\\]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_provider' ),
			'permission_callback' => $auth,
		) );
	}

	public static function check_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/* ── Skills ────────────────────────────────────────────── */

	public static function get_skills( WP_REST_Request $req ): WP_REST_Response {
		$gid = (int) $req['id'];
		return rest_ensure_response( array(
			'guru_id' => $gid,
			'count'   => BizCity_Guru_Skill_Bridge::count_for_guru( $gid ),
			'skills'  => BizCity_Guru_Skill_Bridge::list_for_guru( $gid ),
		) );
	}

	public static function get_skills_unbound( WP_REST_Request $req ): WP_REST_Response {
		$gid = (int) $req['id'];
		return rest_ensure_response( array(
			'guru_id' => $gid,
			'unbound' => BizCity_Guru_Skill_Bridge::list_unbound_for_guru( $gid ),
		) );
	}

	public static function post_skill( WP_REST_Request $req ) {
		$gid      = (int) $req['id'];
		$tool_id  = (string) $req->get_param( 'tool_id' );
		$tool_cls = (string) ( $req->get_param( 'tool_class' ) ?: 'producer' );
		$prio     = (int) ( $req->get_param( 'priority' ) ?: 100 );

		$result = BizCity_Guru_Skill_Bridge::attach( $gid, $tool_id, $tool_cls, $prio );
		if ( ! $result ) {
			return new WP_Error( 'attach_failed', 'Failed to attach skill', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $result ) );
	}

	public static function delete_skill( WP_REST_Request $req ) {
		$gid  = (int) $req['id'];
		$tool = (string) $req['tool'];
		$ok   = BizCity_Guru_Skill_Bridge::detach( $gid, $tool );
		return rest_ensure_response( array( 'ok' => $ok ) );
	}

	/* ── Providers ─────────────────────────────────────────── */

	public static function get_providers( WP_REST_Request $req ): WP_REST_Response {
		$gid = (int) $req['id'];
		return rest_ensure_response( array(
			'guru_id'   => $gid,
			'count'     => BizCity_Guru_Provider_Bridge::count_for_guru( $gid ),
			'providers' => BizCity_Guru_Provider_Bridge::list_for_guru( $gid ),
		) );
	}

	public static function get_providers_unbound( WP_REST_Request $req ): WP_REST_Response {
		$gid = (int) $req['id'];
		return rest_ensure_response( array(
			'guru_id' => $gid,
			'unbound' => BizCity_Guru_Provider_Bridge::list_unbound_for_guru( $gid ),
		) );
	}

	public static function post_provider( WP_REST_Request $req ) {
		$gid   = (int) $req['id'];
		$cls   = (string) $req->get_param( 'provider_class' );
		$scope = (array) ( $req->get_param( 'scope' ) ?: array() );

		$result = BizCity_Guru_Provider_Bridge::attach( $gid, $cls, $scope );
		if ( ! $result ) {
			return new WP_Error( 'attach_failed', 'Failed to attach provider', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $result ) );
	}

	public static function delete_provider( WP_REST_Request $req ) {
		$gid = (int) $req['id'];
		$cls = (string) $req['class'];
		$ok  = BizCity_Guru_Provider_Bridge::detach( $gid, $cls );
		return rest_ensure_response( array( 'ok' => $ok ) );
	}
}
