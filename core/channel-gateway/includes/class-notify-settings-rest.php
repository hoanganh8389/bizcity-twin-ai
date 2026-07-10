<?php
/**
 * Notification Center — REST Controller (PHASE-CG-NOTIFY-BINDINGS)
 *
 * Namespace: bizcity-channel/v1
 * Routes:
 *   GET  /notify-settings  — trả về cài đặt thông báo hiện tại
 *   POST /notify-settings  — lưu cài đặt thông báo
 *
 * Option key: bizcity_cg_notify_settings
 * Cache group: notify (TTL 3600)
 *
 * R-GW-8: luôn trả HTTP 200, không bao giờ 4xx/5xx về Cloudflare.
 * R-CACHE: flush group 'notify' sau POST thành công.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE-CG-NOTIFY-BINDINGS (2026-06-13)
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — Notification Center REST controller.
class BizCity_Notify_Settings_REST {

	const NS         = 'bizcity-channel/v1';
	const OPTION_KEY = 'bizcity_cg_notify_settings';
	const CACHE_GRP  = 'notify';

	/** Known event codes — tránh lưu giá trị lạ vào option. */
	const KNOWN_EVENTS = [
		'order_new',
		'order_payment_complete',
		'order_cancelled',
		'low_stock',
		'cf7_submit',
		'user_register',
		'comment_new',
		'post_published',
	];

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route( self::NS, '/notify-settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_settings' ),
				'permission_callback' => array( __CLASS__, 'require_manage' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_settings' ),
				'permission_callback' => array( __CLASS__, 'require_manage' ),
				'args'                => array(
					'zalo_bot_id'          => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					'zalo_notify_chat_id'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'email_smtp_uid'       => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'email_recipients'     => array( 'required' => false ),
					'notify_events'        => array( 'required' => false ),
				),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// GET /notify-settings
	// -------------------------------------------------------------------------

	/**
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function get_settings( $req ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — R-CACHE: check cache first.
		$cached = class_exists( 'BizCity_Cache' )
			? BizCity_Cache::get( self::CACHE_GRP, 'settings' )
			: false;

		if ( false !== $cached ) {
			return rest_ensure_response( array( 'success' => true, 'data' => $cached ) );
		}

		$settings = self::load_defaults( get_option( self::OPTION_KEY, array() ) );

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GRP, 'settings', $settings, 3600 );
		}

		return rest_ensure_response( array( 'success' => true, 'data' => $settings ) );
	}

	// -------------------------------------------------------------------------
	// POST /notify-settings
	// -------------------------------------------------------------------------

	/**
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function save_settings( $req ) {
		$current = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		// Merge incoming fields over current.
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		// zalo_bot_id
		if ( array_key_exists( 'zalo_bot_id', $body ) ) {
			$current['zalo_bot_id'] = absint( $body['zalo_bot_id'] );
		}

		// zalo_notify_chat_id
		if ( array_key_exists( 'zalo_notify_chat_id', $body ) ) {
			$current['zalo_notify_chat_id'] = sanitize_text_field( (string) $body['zalo_notify_chat_id'] );
		}

		// email_smtp_uid
		if ( array_key_exists( 'email_smtp_uid', $body ) ) {
			$current['email_smtp_uid'] = sanitize_text_field( (string) $body['email_smtp_uid'] );
		}

		// email_recipients — array of emails
		if ( array_key_exists( 'email_recipients', $body ) ) {
			$raw = is_array( $body['email_recipients'] ) ? $body['email_recipients'] : array();
			$filtered = array();
			foreach ( $raw as $em ) {
				$em = sanitize_email( (string) $em );
				if ( is_email( $em ) ) {
					$filtered[] = $em;
				}
			}
			$current['email_recipients'] = $filtered;
		}

		// notify_events — only known codes
		if ( array_key_exists( 'notify_events', $body ) ) {
			$raw = is_array( $body['notify_events'] ) ? $body['notify_events'] : array();
			$current['notify_events'] = array_values(
				array_intersect( array_map( 'sanitize_key', $raw ), self::KNOWN_EVENTS )
			);
		}

		update_option( self::OPTION_KEY, $current, false );

		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — R-CACHE: flush after write.
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GRP );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => self::load_defaults( $current ),
		) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Trả về settings đã merge với defaults, không expose
	 * credentials thô (option lưu toàn bộ, REST trả về UID thôi).
	 *
	 * @param array $raw
	 * @return array
	 */
	private static function load_defaults( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'zalo_bot_id'         => isset( $raw['zalo_bot_id'] )         ? (int) $raw['zalo_bot_id']             : 0,
			'zalo_notify_chat_id' => isset( $raw['zalo_notify_chat_id'] ) ? (string) $raw['zalo_notify_chat_id']  : '',
			'email_smtp_uid'      => isset( $raw['email_smtp_uid'] )      ? (string) $raw['email_smtp_uid']       : '',
			'email_recipients'    => isset( $raw['email_recipients'] )    ? (array) $raw['email_recipients']      : array(),
			// Default: chỉ bật 3 sự kiện quan trọng nhất, tránh notification spam.
			'notify_events'       => isset( $raw['notify_events'] )       ? (array) $raw['notify_events']
				: array( 'order_new', 'order_payment_complete', 'cf7_submit' ),
		);
	}

	/**
	 * @return bool
	 */
	public static function require_manage() {
		return current_user_can( 'manage_options' );
	}
}
