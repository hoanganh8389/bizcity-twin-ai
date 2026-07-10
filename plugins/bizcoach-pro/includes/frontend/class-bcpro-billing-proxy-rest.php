<?php
/**
 * BizCoach Pro — Billing proxy REST for client-side pricing UX.
 *
 * @package BizCoach_Pro
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Billing_Proxy_REST', false ) ) {
	return;
}

class BizCoach_Pro_Billing_Proxy_REST {

	const NS = 'bizcity-client/v1';

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — register billing/plan routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — route map.
	 */
	public static function register_routes() {
		register_rest_route( self::NS, '/billing/packages', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'billing_packages' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/billing/checkout', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'billing_checkout' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( self::NS, '/billing/orders/(?P<order_id>\d+)/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'billing_order_status' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( self::NS, '/billing/callback', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'billing_callback' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( self::NS, '/billing/callback/recent', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'billing_callback_recent' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( self::NS, '/entitlement/sync', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'entitlement_sync' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( self::NS, '/me/plan', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'me_plan' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — public pricing package list.
	 */
	public static function billing_packages( $request ) {
		if ( ! class_exists( 'BizCoach_Pro_Plan_Service' ) ) {
			return rest_ensure_response( self::module_missing_payload() );
		}
		return rest_ensure_response( BizCoach_Pro_Plan_Service::fetch_packages() );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — authenticated checkout creation.
	 */
	public static function billing_checkout( $request ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return rest_ensure_response( self::auth_required_payload() );
		}
		if ( ! class_exists( 'BizCoach_Pro_Plan_Service' ) ) {
			return rest_ensure_response( self::module_missing_payload() );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
		}

		return rest_ensure_response( BizCoach_Pro_Plan_Service::create_checkout( $uid, $payload ) );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — order polling status.
	 */
	public static function billing_order_status( $request ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return rest_ensure_response( self::auth_required_payload() );
		}
		if ( ! class_exists( 'BizCoach_Pro_Plan_Service' ) ) {
			return rest_ensure_response( self::module_missing_payload() );
		}

		$order_id = (int) $request->get_param( 'order_id' );
		return rest_ensure_response( BizCoach_Pro_Plan_Service::get_order_status( $uid, $order_id ) );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — UX callback recorder.
	 */
	public static function billing_callback( $request ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return rest_ensure_response( self::auth_required_payload() );
		}
		if ( ! class_exists( 'BizCoach_Pro_Plan_Service' ) ) {
			return rest_ensure_response( self::module_missing_payload() );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
		}

		$saved = BizCoach_Pro_Plan_Service::record_billing_callback( $uid, $payload );

		$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
		if ( in_array( $status, array( 'paid', 'processing', 'completed' ), true ) ) {
			$sync = BizCoach_Pro_Plan_Service::sync_from_hub( $uid );
			return rest_ensure_response( array(
				'success'  => ! empty( $saved['success'] ),
				'callback' => $saved,
				'sync'     => $sync,
			) );
		}

		return rest_ensure_response( $saved );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — read recent callback for bootstrap fallback.
	 */
	public static function billing_callback_recent( $request ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return rest_ensure_response( self::auth_required_payload() );
		}
		if ( ! class_exists( 'BizCoach_Pro_Plan_Service' ) ) {
			return rest_ensure_response( self::module_missing_payload() );
		}

		return rest_ensure_response( BizCoach_Pro_Plan_Service::get_recent_billing_callback( $uid ) );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — force entitlement sync.
	 */
	public static function entitlement_sync( $request ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return rest_ensure_response( self::auth_required_payload() );
		}
		if ( ! class_exists( 'BizCoach_Pro_Plan_Service' ) ) {
			return rest_ensure_response( self::module_missing_payload() );
		}

		return rest_ensure_response( BizCoach_Pro_Plan_Service::sync_from_hub( $uid ) );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — read my current plan.
	 */
	public static function me_plan( $request ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return rest_ensure_response( self::auth_required_payload() );
		}
		if ( ! class_exists( 'BizCoach_Pro_Plan_Service' ) ) {
			return rest_ensure_response( self::module_missing_payload() );
		}

		$fresh_raw = $request->get_param( 'fresh' );
		$fresh = false;
		if ( is_bool( $fresh_raw ) ) {
			$fresh = $fresh_raw;
		} elseif ( is_string( $fresh_raw ) ) {
			$fresh = in_array( strtolower( $fresh_raw ), array( '1', 'true', 'yes' ), true );
		}

		return rest_ensure_response( BizCoach_Pro_Plan_Service::get_plan( $uid, $fresh ) );
	}

	/**
	 * @return array
	 */
	private static function auth_required_payload() {
		return array(
			'success'   => false,
			'code'      => 'auth_required',
			'message'   => 'Vui lòng đăng nhập để dùng chức năng thanh toán.',
			'hint'      => 'Đăng nhập rồi thử lại.',
			'help_code' => 'auth_required',
		);
	}

	/**
	 * @return array
	 */
	private static function module_missing_payload() {
		return array(
			'success'   => false,
			'code'      => 'module_not_loaded',
			'message'   => 'Plan service chưa load.',
			'hint'      => 'Liên hệ admin để kiểm tra bootstrap plugin.',
			'help_code' => 'module_not_loaded',
		);
	}
}
