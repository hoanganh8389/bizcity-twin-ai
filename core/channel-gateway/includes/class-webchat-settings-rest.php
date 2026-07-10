<?php
/**
 * WebChat Widget Settings REST — admin SPA read/write WP options.
 *
 * Namespace: bizcity-channel/v1 (R-CH-NS).
 * Routes:
 *   GET  /webchat/settings  — read all widget options.
 *   POST /webchat/settings  — update widget options (partial OK).
 *
 * Options written:
 *   bizcity_webchat_widget_enabled   (bool)
 *   bizcity_webchat_widget_position  (string)
 *   bizcity_webchat_primary_color    (string)
 *   bizcity_webchat_bot_name         (string)
 *   bizcity_webchat_bot_avatar       (string)
 *   bizcity_webchat_welcome          (textarea)
 *   bizcity_webchat_placeholder      (string)
 *   bizcity_webchat_show_mobile      (bool)
 *   bizcity_webchat_auto_open        (int seconds, 0 = off)
 *   bizcity_webchat_system_prompt    (textarea, NEW — fallback prompt)
 *   bizcity_webchat_excluded_pages   (array of int IDs)
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE-TWINWEB (2026-07-02)
 */

// [2026-07-02 Johnny Chu] PHASE-TWINWEB — WebChat widget settings REST endpoint.

defined( 'ABSPATH' ) || exit;

class BizCity_Webchat_Settings_REST {

	const NS = 'bizcity-channel/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/webchat/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /bizcity-channel/v1/webchat/settings
	 */
	public static function get_settings() {
		$excluded = get_option( 'bizcity_webchat_excluded_pages', array() );
		if ( ! is_array( $excluded ) ) {
			$excluded = array();
		}

		return rest_ensure_response( array(
			'ok'       => true,
			'settings' => array(
				'widget_enabled'  => (bool) get_option( 'bizcity_webchat_widget_enabled', true ),
				'widget_position' => (string) get_option( 'bizcity_webchat_widget_position', 'bottom-right' ),
				'primary_color'   => (string) get_option( 'bizcity_webchat_primary_color', '#3182f6' ),
				'bot_name'        => (string) get_option( 'bizcity_webchat_bot_name', 'BizChat AI' ),
				'bot_avatar'      => (string) get_option( 'bizcity_webchat_bot_avatar', '' ),
				'welcome'         => (string) get_option( 'bizcity_webchat_welcome', '' ),
				'placeholder'     => (string) get_option( 'bizcity_webchat_placeholder', '' ),
				'show_mobile'     => (bool) get_option( 'bizcity_webchat_show_mobile', true ),
				'auto_open'       => (int) get_option( 'bizcity_webchat_auto_open', 0 ),
				'system_prompt'   => (string) get_option( 'bizcity_webchat_system_prompt', '' ),
				'excluded_pages'  => array_map( 'intval', $excluded ),
			),
		) );
	}

	/**
	 * POST /bizcity-channel/v1/webchat/settings
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_settings( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_param', 'JSON body required.', array( 'status' => 400 ) );
		}

		// Plain text fields — sanitize_text_field.
		$text_fields = array(
			'widget_position' => 'bizcity_webchat_widget_position',
			'primary_color'   => 'bizcity_webchat_primary_color',
			'bot_name'        => 'bizcity_webchat_bot_name',
			'bot_avatar'      => 'bizcity_webchat_bot_avatar',
			'placeholder'     => 'bizcity_webchat_placeholder',
		);
		foreach ( $text_fields as $key => $option ) {
			if ( array_key_exists( $key, $body ) ) {
				update_option( $option, sanitize_text_field( (string) $body[ $key ] ) );
			}
		}

		// Textarea fields.
		$textarea_fields = array(
			'welcome'       => 'bizcity_webchat_welcome',
			'system_prompt' => 'bizcity_webchat_system_prompt',
		);
		foreach ( $textarea_fields as $key => $option ) {
			if ( array_key_exists( $key, $body ) ) {
				update_option( $option, sanitize_textarea_field( (string) $body[ $key ] ) );
			}
		}

		// Boolean fields.
		$bool_fields = array(
			'widget_enabled' => 'bizcity_webchat_widget_enabled',
			'show_mobile'    => 'bizcity_webchat_show_mobile',
		);
		foreach ( $bool_fields as $key => $option ) {
			if ( array_key_exists( $key, $body ) ) {
				update_option( $option, $body[ $key ] ? 1 : 0 );
			}
		}

		// Integer fields.
		if ( array_key_exists( 'auto_open', $body ) ) {
			update_option( 'bizcity_webchat_auto_open', max( 0, (int) $body['auto_open'] ) );
		}

		// Array fields.
		if ( array_key_exists( 'excluded_pages', $body ) ) {
			$pages = is_array( $body['excluded_pages'] ) ? array_map( 'intval', $body['excluded_pages'] ) : array();
			update_option( 'bizcity_webchat_excluded_pages', $pages );
		}

		return rest_ensure_response( array( 'ok' => true, 'message' => 'Đã lưu cài đặt WebChat.' ) );
	}
}
