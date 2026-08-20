<?php
/**
 * Profile public chat adapter to canonical TwinBrain WebChat policy.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_Chat_Handler' ) ) { return; }

final class BizCity_Personal_Profile_Chat_Handler {

	public static function handle( $card_id, $context_token, $channel_code, $presentation, $message, $session_id = '' ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — public Profile chat enters the canonical WebChat TwinBrain adapter only.
		$claims = BizCity_Personal_Profile_Channel_Resolver::verify_context( $context_token, $card_id, $channel_code, $presentation );
		if ( is_wp_error( $claims ) ) { return $claims; }
		if ( 'webchat' !== sanitize_key( (string) $channel_code ) ) { return new WP_Error( 'invalid_param', 'Profile Chat chỉ hỗ trợ channel WebChat.', array( 'status' => 400 ) ); }
		$message = sanitize_textarea_field( (string) $message );
		if ( '' === $message ) { return new WP_Error( 'invalid_param', 'Tin nhắn không được để trống.', array( 'status' => 400 ) ); }
		$session_id = sanitize_key( (string) $session_id );
		$prefix = 'profile_webchat_' . (int) $card_id . '_';
		if ( 0 !== strpos( $session_id, $prefix ) ) { $session_id = $prefix . substr( md5( wp_generate_uuid4() ), 0, 24 ); }
		$payload = array(
			'platform'        => 'WEBCHAT',
			'channel_class'   => 'guest_channel',
			'account_id'      => (string) get_current_blog_id(),
			'chat_id'         => $session_id,
			'external_user_id' => $session_id,
			'text'            => $message,
			'surface'         => 'profile',
			'presentation'    => sanitize_key( (string) $presentation ),
			'profile_card_id' => (int) $card_id,
		);
		if ( ! class_exists( 'BizCity_TwinBrain_Adapter_WebChat' ) ) { return new WP_Error( 'module_not_loaded', 'TwinBrain WebChat adapter chưa sẵn sàng.', array( 'status' => 503 ) ); }
		$result = ( new BizCity_TwinBrain_Adapter_WebChat() )->handle( $payload );
		if ( empty( $result['ok'] ) ) { return new WP_Error( 'twin_agent_exception', 'Twin Brain chưa thể trả lời lúc này.', array( 'status' => 503 ) ); }
		$result['session_id'] = $session_id;
		$result['presentation'] = sanitize_key( (string) $presentation );
		return $result;
	}
}