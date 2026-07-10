<?php
/**
 * CF7 ZNS Templates — REST API
 *
 * Namespace: bizcity-channel/v1
 *
 * Endpoints:
 *   GET    /cf7/zns-templates           — list templates (?status=all)
 *   POST   /cf7/zns-templates           — create / upsert template
 *   GET    /cf7/zns-templates/{temp_id} — single template
 *   POST   /cf7/zns-templates/{temp_id} — update template
 *   DELETE /cf7/zns-templates/{temp_id} — delete template
 *
 * R-CH-NS: namespace = bizcity-channel/v1
 * R-GW-8:  all endpoints return HTTP 200 even on error.
 *
 * [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — new REST class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-ZNS-TEMPLATE-CATALOG (2026-06-28)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CF7_ZNS_Templates_REST' ) ) {
	return;
}

class BizCity_CF7_ZNS_Templates_REST {

	const NS = 'bizcity-channel/v1';

	public static function init() {
		// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — register routes
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		// List + create
		register_rest_route( self::NS, '/cf7/zns-templates', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_templates' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array(
					'status' => array(
						'type'              => 'string',
						'default'           => 'active',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_template' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
		) );

		// Single: get / update / delete
		register_rest_route( self::NS, '/cf7/zns-templates/(?P<temp_id>[a-zA-Z0-9_\-]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_template' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array(
					'temp_id' => array( 'type' => 'string', 'required' => true ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_template' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array(
					'temp_id' => array( 'type' => 'string', 'required' => true ),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_template' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array(
					'temp_id' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	public static function list_templates( WP_REST_Request $req ) {
		// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG
		$status    = $req->get_param( 'status' ) ? (string) $req->get_param( 'status' ) : 'active';
		$templates = BizCity_CF7_ZNS_Templates::get_all( $status );
		return rest_ensure_response( array(
			'ok'        => true,
			'templates' => $templates,
			'count'     => count( $templates ),
		) );
	}

	public static function save_template( WP_REST_Request $req ) {
		// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG
		$body = $req->get_json_params();
		if ( empty( $body ) ) {
			$body = $req->get_body_params();
		}
		if ( empty( $body['temp_id'] ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => 'temp_id là bắt buộc.' ) );
		}
		$ok = BizCity_CF7_ZNS_Templates::save( $body );
		if ( ! $ok ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => 'Lưu template thất bại.' ) );
		}
		$item = BizCity_CF7_ZNS_Templates::get( $body['temp_id'] );
		return rest_ensure_response( array( 'ok' => true, 'template' => $item ) );
	}

	public static function get_template( WP_REST_Request $req ) {
		// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG
		$temp_id = sanitize_text_field( (string) $req->get_param( 'temp_id' ) );
		$item    = BizCity_CF7_ZNS_Templates::get( $temp_id );
		if ( null === $item ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => 'Template không tồn tại.' ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'template' => $item ) );
	}

	public static function update_template( WP_REST_Request $req ) {
		// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG
		$temp_id = sanitize_text_field( (string) $req->get_param( 'temp_id' ) );
		$body    = $req->get_json_params();
		if ( empty( $body ) ) {
			$body = $req->get_body_params();
		}
		// Override temp_id from URL — URL wins
		$body['temp_id'] = $temp_id;
		$ok  = BizCity_CF7_ZNS_Templates::save( $body );
		$item = BizCity_CF7_ZNS_Templates::get( $temp_id );
		return rest_ensure_response( array( 'ok' => $ok, 'template' => $item ) );
	}

	public static function delete_template( WP_REST_Request $req ) {
		// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG
		$temp_id = sanitize_text_field( (string) $req->get_param( 'temp_id' ) );
		$ok      = BizCity_CF7_ZNS_Templates::delete( $temp_id );
		return rest_ensure_response( array( 'ok' => $ok ) );
	}

	// ── Auth ──────────────────────────────────────────────────────────────────

	public static function admin_only() {
		return current_user_can( 'manage_options' );
	}
}
