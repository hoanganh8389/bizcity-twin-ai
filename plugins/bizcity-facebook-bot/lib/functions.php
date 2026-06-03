<?php
/**
 * Helper functions for Facebook Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Facebook Bot instance by ID
 * 
 * @param int $bot_id Bot ID
 * @return object|null Bot object or null
 */
function bizcity_facebook_bot_get( $bot_id ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	return $db->get_bot( $bot_id );
}

/**
 * Get Facebook Bot by Page ID
 * 
 * @param string $page_id Page ID
 * @return object|null Bot object or null
 */
function bizcity_facebook_bot_get_by_page( $page_id ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	return $db->get_bot_by_page_id( $page_id );
}

/**
 * Get all active Facebook Bots
 * 
 * @return array Array of bot objects
 */
function bizcity_facebook_bot_get_all() {
	$db = BizCity_Facebook_Bot_Database::instance();
	return $db->get_active_bots();
}

/**
 * Send text message via Facebook Messenger
 * 
 * @param int $bot_id Bot ID
 * @param string $user_id Recipient PSID
 * @param string $message Message text
 * @return array|WP_Error Response
 */
function bizcity_facebook_bot_send_message( $bot_id, $user_id, $message ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return new WP_Error( 'bot_not_found', 'Bot không tồn tại' );
	}
	
	$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
	$result = $api->send_message( $user_id, $message );
	
	// Log
	$db->insert_log( $bot_id, 'send_message', json_encode( array(
		'user_id' => $user_id,
		'message' => $message,
		'result'  => is_wp_error( $result ) ? $result->get_error_message() : $result,
	) ) );
	
	return $result;
}

/**
 * Send photo via Facebook Messenger
 * 
 * @param int $bot_id Bot ID
 * @param string $user_id Recipient PSID
 * @param string $photo_url Image URL
 * @param string $caption Optional caption
 * @return array|WP_Error Response
 */
function bizcity_facebook_bot_send_photo( $bot_id, $user_id, $photo_url, $caption = '' ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return new WP_Error( 'bot_not_found', 'Bot không tồn tại' );
	}
	
	$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
	$result = $api->send_photo( $user_id, $photo_url, $caption );
	
	// Log
	$db->insert_log( $bot_id, 'send_photo', json_encode( array(
		'user_id'   => $user_id,
		'photo_url' => $photo_url,
		'caption'   => $caption,
		'result'    => is_wp_error( $result ) ? $result->get_error_message() : $result,
	) ) );
	
	return $result;
}

/**
 * Send file via Facebook Messenger
 * 
 * @param int $bot_id Bot ID
 * @param string $user_id Recipient PSID
 * @param string $file_url File URL
 * @return array|WP_Error Response
 */
function bizcity_facebook_bot_send_file( $bot_id, $user_id, $file_url ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return new WP_Error( 'bot_not_found', 'Bot không tồn tại' );
	}
	
	$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
	$result = $api->send_file_message( $user_id, $file_url );
	
	// Log
	$db->insert_log( $bot_id, 'send_file', json_encode( array(
		'user_id'  => $user_id,
		'file_url' => $file_url,
		'result'   => is_wp_error( $result ) ? $result->get_error_message() : $result,
	) ) );
	
	return $result;
}

/**
 * Reply to a Facebook comment
 * 
 * @param int $bot_id Bot ID
 * @param string $comment_id Comment ID
 * @param string $message Reply message
 * @return array|WP_Error Response
 */
function bizcity_facebook_bot_reply_comment( $bot_id, $comment_id, $message ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return new WP_Error( 'bot_not_found', 'Bot không tồn tại' );
	}
	
	$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
	$result = $api->reply_comment( $comment_id, $message );
	
	// Log
	$db->insert_log( $bot_id, 'reply_comment', json_encode( array(
		'comment_id' => $comment_id,
		'message'    => $message,
		'result'     => is_wp_error( $result ) ? $result->get_error_message() : $result,
	) ) );
	
	return $result;
}

/**
 * Get user profile from Facebook
 * 
 * @param int $bot_id Bot ID
 * @param string $user_id User PSID
 * @return array|WP_Error User profile data
 */
function bizcity_facebook_bot_get_user( $bot_id, $user_id ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return new WP_Error( 'bot_not_found', 'Bot không tồn tại' );
	}
	
	$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
	return $api->get_user_profile( $user_id );
}

/**
 * Get Page info
 * 
 * @param int $bot_id Bot ID
 * @return array|WP_Error Page info
 */
function bizcity_facebook_bot_get_page_info( $bot_id ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return new WP_Error( 'bot_not_found', 'Bot không tồn tại' );
	}
	
	$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
	return $api->get_me();
}

/**
 * Create a post on Facebook Page
 * 
 * @param int $bot_id Bot ID
 * @param string $message Post message
 * @param string $link Optional link
 * @param string $photo_url Optional photo URL
 * @return array|WP_Error Response
 */
function bizcity_facebook_bot_create_post( $bot_id, $message, $link = '', $photo_url = '' ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return new WP_Error( 'bot_not_found', 'Bot không tồn tại' );
	}
	
	$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
	$result = $api->create_post( $message, $link, $photo_url );
	
	// Log
	$db->insert_log( $bot_id, 'create_post', json_encode( array(
		'message'   => $message,
		'link'      => $link,
		'photo_url' => $photo_url,
		'result'    => is_wp_error( $result ) ? $result->get_error_message() : $result,
	) ) );
	
	return $result;
}

/**
 * Log Facebook Bot activity
 * 
 * @param int $bot_id Bot ID
 * @param string $type Log type
 * @param mixed $data Log data
 */
function bizcity_facebook_bot_log( $bot_id, $type, $data ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	
	if ( ! is_string( $data ) ) {
		$data = json_encode( $data );
	}
	
	$db->insert_log( $bot_id, $type, $data );
}

/**
 * Get first active bot
 * 
 * @return object|null Bot object or null
 */
function bizcity_facebook_bot_get_default() {
	$bots = bizcity_facebook_bot_get_all();
	return ! empty( $bots ) ? $bots[0] : null;
}

/**
 * Check if Facebook Bot plugin is active
 * 
 * @return bool
 */
function bizcity_facebook_bot_is_active() {
	return class_exists( 'BizCity_Facebook_Bot_Plugin' );
}

/**
 * Get webhook URL
 * 
 * @return string
 */
function bizcity_facebook_bot_webhook_url() {
	return home_url( '/?fbhook=1' );
}

/**
 * Send button template
 * 
 * @param int $bot_id Bot ID
 * @param string $user_id Recipient PSID
 * @param string $text Button text
 * @param array $buttons Array of buttons
 * @return array|WP_Error Response
 */
function bizcity_facebook_bot_send_buttons( $bot_id, $user_id, $text, $buttons ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return new WP_Error( 'bot_not_found', 'Bot không tồn tại' );
	}
	
	$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
	return $api->send_button_template( $user_id, $text, $buttons );
}

/**
 * Send quick replies
 * 
 * @param int $bot_id Bot ID
 * @param string $user_id Recipient PSID
 * @param string $text Message text
 * @param array $quick_replies Array of quick reply options
 * @return array|WP_Error Response
 */
function bizcity_facebook_bot_send_quick_replies( $bot_id, $user_id, $text, $quick_replies ) {
	$db = BizCity_Facebook_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return new WP_Error( 'bot_not_found', 'Bot không tồn tại' );
	}
	
	$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
	return $api->send_quick_replies( $user_id, $text, $quick_replies );
}
