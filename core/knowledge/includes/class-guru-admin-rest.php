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
 * Errors (WP-10 B1, R-ERROR-UX, owner decision D-15): every error body carries `code`, `message`
 * (English), `hint` and `help_code`, keeps the real HTTP status, and is built through
 * BizCity_Error_Payload. Faults (5xx) are recorded by the reporter and flagged `_degraded`;
 * user-input errors (4xx) are not recorded, so validation noise cannot push real faults out.
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

	/**
	 * Route permission: same gate as can_manage(), but a refusal carries hint + help_code
	 * (R-ERROR-UX, added by error_response) instead of WordPress's bare `rest_forbidden`.
	 *
	 * @return true|WP_Error
	 */
	public static function permission() {
		if ( self::can_manage() ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'guru_not_logged_in', 'You are not logged in.', array( 'status' => 401, 'hint' => self::help_for( 'guru_not_logged_in' )['hint'], 'help_code' => 'guru_not_logged_in' ) );
		}
		return new WP_Error( 'guru_admin_forbidden', 'Only site administrators can manage Gurus.', array( 'status' => 403, 'hint' => self::help_for( 'guru_admin_forbidden' )['hint'], 'help_code' => 'guru_admin_forbidden' ) );
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'permission' );

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
			return self::error_response( new WP_Error( 'invalid_body', 'The body must be { data, overwrite?, apply_profile? }.', array( 'status' => 400 ) ) );
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
			return self::error_response( new WP_Error( 'invalid_body', 'The body must be a JSON object.', array( 'status' => 400 ) ) );
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
	 * Wrap a service result: a WP_Error becomes the R-ERROR-UX payload (real HTTP status kept);
	 * arrays gain `ok: true`, optionally nested.
	 */
	private static function respond( $result, string $key = '', int $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}
		$payload = '' === $key ? array_merge( array( 'ok' => true ), (array) $result ) : array( 'ok' => true, $key => $result );
		return new WP_REST_Response( $payload, $status );
	}

	/**
	 * Convert a WP_Error into the R-ERROR-UX body (`code`, `message`, `hint`, `help_code`) with the
	 * real HTTP status. 5xx goes through BizCity_Error_Payload::from_wp_error (recorded, degraded);
	 * 4xx is the same shape without the reporter write.
	 */
	public static function error_response( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		$code   = (string) $error->get_error_code();
		$help   = self::help_for( $code );

		if ( $status >= 500 && class_exists( 'BizCity_Error_Payload' ) ) {
			$payload = BizCity_Error_Payload::from_wp_error( $error, $help['hint'], $help['help_code'] );
		} else {
			$payload = array(
				'success'   => false,
				'_degraded' => $status >= 500,
				'code'      => $code,
				'message'   => (string) $error->get_error_message(),
				'hint'      => $help['hint'],
				'help_code' => $help['help_code'],
				'context'   => array(),
			);
		}
		return new WP_REST_Response( $payload, $status );
	}

	/**
	 * Action-oriented hint and help code per error code. `help_code` equals the code so the FE
	 * help catalog can key on it. Unknown codes fall back to a generic retry hint.
	 *
	 * @return array{hint:string,help_code:string}
	 */
	private static function help_for( string $code ): array {
		$hints = array(
			'guru_not_logged_in'        => 'Log in again, then retry.',
			'guru_admin_forbidden'      => 'Ask a site administrator to make this change.',
			'invalid_id'                => 'Reopen the Guru from the list and retry.',
			'not_found'                 => 'Refresh the list; the Guru may have been deleted.',
			'module_not_loaded'         => 'Check that the bizcity-twin-ai plugin is active and has no PHP fatal error.',
			'invalid_body'              => 'Reload the page and retry; if it repeats, report it to support.',
			'invalid_name'              => 'Enter a name for the Guru.',
			'invalid_slug'              => 'Enter a slug made of letters, numbers and dashes.',
			'slug_taken'                => 'Choose a different slug.',
			'invalid_status'            => 'Pick one of the listed statuses.',
			'invalid_greeting_messages' => 'Send greeting_messages as a list.',
			'invalid_capabilities'      => 'Send capabilities as a list.',
			'invalid_notebook_policy'   => 'Pick augment or restrict.',
			'invalid_min_role'          => 'Pick one of the listed roles.',
			'invalid_min_plan'          => 'Pick one of the listed plans.',
			'guru_on_channel'           => 'Switch the listed channels to another Guru in Bot Studio, then delete again.',
			'db_error'                  => 'Retry in a moment; if it repeats, contact support.',
			'gateway_missing'           => 'Check that the bizcity-twin-ai plugin is active, then reload.',
			'gateway_not_ready'         => 'Set the BizCity API key in the gateway settings, then reload.',
			'gateway_bad_response'      => 'Retry in a few minutes; if it repeats, contact support.',
			'empty_row'                 => 'Fill in the title or the content.',
			'faq_not_found'             => 'Reload the FAQ list; the row may have been removed.',
			'invalid_import'            => 'Export a Guru file from this page and import that file.',
			'import_too_large'          => 'Split the file into smaller imports.',
		);
		return array(
			'hint'      => isset( $hints[ $code ] ) ? $hints[ $code ] : 'Retry; if it repeats, contact support.',
			'help_code' => isset( $hints[ $code ] ) ? $code : 'guru_generic',
		);
	}
}

BizCity_Guru_Admin_REST::init();
