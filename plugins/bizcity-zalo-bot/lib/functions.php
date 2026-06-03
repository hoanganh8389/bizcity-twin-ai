<?php
/**
 * Helper Functions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Zalo Bot API instance
 */
function bizcity_get_zalo_bot_api( $bot_id ) {
	$db = BizCity_Zalo_Bot_Database::instance();
	$bot = $db->get_bot( $bot_id );
	
	if ( ! $bot ) {
		return false;
	}
	
	return new BizCity_Zalo_Bot_API( $bot->bot_token );
}

/**
 * Send Zalo message (helper function)
 */
function bizcity_send_zalo_message( $bot_id, $user_id, $message, $type = 'text' ) {
	$api = bizcity_get_zalo_bot_api( $bot_id );
	
	if ( ! $api ) {
		return new WP_Error( 'bot_not_found', 'Bot not found' );
	}
	
	if ( $type === 'image' ) {
		return $api->send_image_message( $user_id, $message );
	} elseif ( $type === 'file' ) {
		return $api->send_file_message( $user_id, $message );
	} else {
		return $api->send_text_message( $user_id, $message );
	}
}

/**
 * Get Zalo user profile
 */
function bizcity_get_zalo_user_profile( $bot_id, $user_id ) {
	$api = bizcity_get_zalo_bot_api( $bot_id );
	
	if ( ! $api ) {
		return new WP_Error( 'bot_not_found', 'Bot not found' );
	}
	
	return $api->get_user_profile( $user_id );
}

/**
 * Get webhook URL for bot
 */
function bizcity_get_zalo_webhook_url( $bot_id ) {
	return home_url( '/bizcity/zalo-bot/webhook/' . $bot_id );
}

/**
 * Format Zalo timestamp
 */
function bizcity_format_zalo_timestamp( $timestamp ) {
	if ( strlen( $timestamp ) > 10 ) {
		// Milliseconds to seconds
		$timestamp = intval( $timestamp / 1000 );
	}
	
	return date( 'Y-m-d H:i:s', $timestamp );
}

/**
 * Encrypt webhook data for zalohook endpoint
 */
function bizcity_encrypt_webhook_data( $data, $blog_id ) {
	// Generate encryption key from blog_id
	$salt = 'bizcity_zalo_secret_2026';
	$key = hash( 'sha256', $blog_id . '_' . $salt, true );
	
	// Convert data to JSON
	$json_data = json_encode( $data );
	
	// Simple XOR encryption
	$encrypted = '';
	$key_length = strlen( $key );
	
	for ( $i = 0; $i < strlen( $json_data ); $i++ ) {
		$encrypted .= $json_data[$i] ^ $key[$i % $key_length];
	}
	
	// Return base64 encoded
	return base64_encode( $encrypted );
}

/**
 * Get zalohook URL for current blog
 */
function bizcity_get_zalohook_url() {
	return home_url( '/zalohook/' );
}

/**
 * Generate secret token for Zalo Bot Creator
 */
function bizcity_generate_zalo_secret_token( $blog_id ) {
	$salt = 'bizcity_zalo_secret_2026';
	return substr( hash( 'sha256', $blog_id . '_' . $salt ), 0, 16 );
}
