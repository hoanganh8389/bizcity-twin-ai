<?php
/**
 * Legacy compat wrappers — bridge old function names to class methods.
 *
 * Migrated from: mu-plugins/bizcity-admin-hook/lib/functions-compat.php
 * Date: 2026-03-30
 *
 * @package BizCity_Twin_AI
 * @subpackage Helper_Legacy
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'twf_register_telegram_webhook' ) ) {
	function twf_register_telegram_webhook() {
		return class_exists( 'BizCity_AdminHook_Telegram' )
			? BizCity_AdminHook_Telegram::registerWebhook()
			: 'Telegram module not loaded.';
	}
}

if ( ! function_exists( 'bizgpt_chatbot_tele_ai_response' ) ) {
	function bizgpt_chatbot_tele_ai_response( $api_key, $question ) {
		return class_exists( 'BizCity_AdminHook_AI' )
			? BizCity_AdminHook_AI::bizgptChatbotTeleResponse( $api_key, $question )
			: '';
	}
}

if ( ! function_exists( 'twf_ai_detect_message_type_prompt' ) ) {
	function twf_ai_detect_message_type_prompt( $user_text ) {
		return class_exists( 'BizCity_AdminHook_AI' )
			? BizCity_AdminHook_AI::detectMessageTypePrompt( $user_text )
			: '';
	}
}

if ( ! function_exists( 'twf_webchat_send_message_to_session' ) ) {
	function twf_webchat_send_message_to_session( $user_id, $session_id, $reply_content ) {
		if ( class_exists( 'BizCity_AdminHook_Webchat' ) ) {
			BizCity_AdminHook_Webchat::sendMessageToSession( $user_id, $session_id, $reply_content );
		}
	}
}
