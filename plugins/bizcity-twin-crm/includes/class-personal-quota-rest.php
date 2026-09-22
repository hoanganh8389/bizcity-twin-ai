<?php
/**
 * PHASE-0.50 UID-02 — site-level cap on Zalo Personal numbers per user.
 *
 *   GET  /crm-settings/personal-phone-quota   lead+ (Staff_Policy rank ≥ 2) or site admin: read the cap.
 *   POST /crm-settings/personal-phone-quota   site admin (`manage_options`) only: {quota: 0..50}, 0 = unlimited.
 *
 * The value lives in the Channel Gateway option read by
 * {@see BizCity_Channel_User_Grant::personal_account_quota()}, so the grant layer and
 * the pre-QR check in the Zalo bridge enforce the same number. Lowering the cap never
 * removes numbers a user already owns; it only blocks connecting new ones.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.50 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Personal_Quota_REST', false ) ) {
	return;
}

final class BizCity_CRM_Personal_Quota_REST {

	public static function register_routes(): void {
		$ns = defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';
		register_rest_route( $ns, '/crm-settings/personal-phone-quota', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_quota' ),
				'permission_callback' => array( __CLASS__, 'can_view_quota' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_quota' ),
				'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
				'args'                => array(
					'quota' => array( 'type' => 'integer', 'required' => true, 'minimum' => 0, 'maximum' => self::max_quota() ),
				),
			),
		) );
	}

	/**
	 * [2026-09-19] PHASE-0.60 C60-A05 — renamed from `can_read()`: same method
	 * name as `BizCity_CRM_REST_Controller::can_read()` (admin-only) meant "lead+"
	 * here, a name collision with two different meanings (§3.2 of the phase doc).
	 */
	public static function can_view_quota(): bool {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		if ( ! class_exists( 'BizCity_CRM_Staff_Policy' ) ) { return false; }
		return BizCity_CRM_Staff_Policy::rank( BizCity_CRM_Staff_Policy::role( (int) get_current_user_id() ) ) >= 2;
	}

	public static function get_quota( WP_REST_Request $request ) {
		if ( ! self::grant_ready() ) { return self::unavailable(); }
		return new WP_REST_Response( self::payload(), 200 );
	}

	public static function set_quota( WP_REST_Request $request ) {
		if ( ! self::grant_ready() ) { return self::unavailable(); }
		$quota = (int) $request->get_param( 'quota' );
		if ( $quota < 0 || $quota > self::max_quota() ) {
			return new WP_REST_Response( array(
				'ok'        => false,
				'code'      => 'invalid_param',
				'message'   => sprintf( 'Hạn mức phải từ 0 đến %d.', self::max_quota() ),
				'hint'      => 'Nhập 0 nếu không giới hạn số SĐT mỗi nhân viên.',
				'help_code' => 'invalid_param_generic',
			), 400 );
		}
		$before = (int) get_option( BizCity_Channel_User_Grant::OPTION_PERSONAL_QUOTA, 0 );
		update_option( BizCity_Channel_User_Grant::OPTION_PERSONAL_QUOTA, $quota, false );
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) && $before !== $quota ) {
			BizCity_CRM_Audit_Log::log( 'crm_setting', 0, 'updated', array( 'personal_phone_quota' => $before ), array( 'personal_phone_quota' => $quota ), array( 'user_id' => (int) get_current_user_id() ) );
		}
		return new WP_REST_Response( self::payload(), 200 );
	}

	private static function payload(): array {
		$quota = max( 0, (int) get_option( BizCity_Channel_User_Grant::OPTION_PERSONAL_QUOTA, 0 ) );
		return array(
			'ok'        => true,
			'quota'     => $quota,
			'unlimited' => 0 === $quota,
			'max'       => self::max_quota(),
			'can_edit'  => current_user_can( 'manage_options' ),
			// The per-user filter may still override the site value (e.g. a plan add-on).
			'effective_for_me' => BizCity_Channel_User_Grant::personal_account_quota( (int) get_current_user_id() ),
		);
	}

	private static function max_quota(): int {
		return class_exists( 'BizCity_Channel_User_Grant' ) ? (int) BizCity_Channel_User_Grant::MAX_PERSONAL_QUOTA : 50;
	}

	private static function grant_ready(): bool {
		return class_exists( 'BizCity_Channel_User_Grant' ) && defined( 'BizCity_Channel_User_Grant::OPTION_PERSONAL_QUOTA' );
	}

	private static function unavailable(): WP_REST_Response {
		return new WP_REST_Response( array(
			'ok'        => false,
			'code'      => 'module_not_loaded',
			'message'   => 'Channel Gateway chưa sẵn sàng.',
			'hint'      => 'Bật Channel Gateway rồi tải lại trang.',
			'help_code' => 'module_not_loaded',
		), 503 );
	}
}
