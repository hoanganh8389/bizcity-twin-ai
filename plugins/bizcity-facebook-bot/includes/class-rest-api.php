<?php
/**
 * REST API Endpoints for Facebook Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Facebook_Bot_REST_API {
	
	private static $instance = null;
	
	/**
	 * API namespace
	 */
	const NAMESPACE = 'bizcity-facebook-bot/v1';
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}
	
	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// Send message
		register_rest_route( self::NAMESPACE, '/send-message', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'send_message' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'bot_id'  => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'user_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		) );
		
		// Send photo
		register_rest_route( self::NAMESPACE, '/send-photo', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'send_photo' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'bot_id'    => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'user_id'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'photo_url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'caption'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
		
		// Reply comment
		register_rest_route( self::NAMESPACE, '/reply-comment', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'reply_comment' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'bot_id'     => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'comment_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		) );
		
		// Get bots
		register_rest_route( self::NAMESPACE, '/bots', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_bots' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
		
		// Get inbox
		register_rest_route( self::NAMESPACE, '/inbox', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_inbox' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'bot_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'limit'  => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 50,
					'sanitize_callback' => 'absint',
				),
			),
		) );
		
		// Get customers
		register_rest_route( self::NAMESPACE, '/customers', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_customers' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'bot_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );
		
		// Get page info
		register_rest_route( self::NAMESPACE, '/page-info', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_page_info' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'bot_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );
	}
	
	/**
	 * Check permission
	 */
	public function check_permission( $request ) {
		// Check for API key in header
		$api_key = $request->get_header( 'X-API-Key' );
		
		if ( ! empty( $api_key ) ) {
			$stored_key = get_option( 'bizcity_facebook_bot_api_key' );
			if ( $api_key === $stored_key ) {
				return true;
			}
		}
		
		// Check for logged in admin
		return current_user_can( 'manage_options' );
	}
	
	/**
	 * Send message endpoint
	 */
	public function send_message( $request ) {
		$bot_id = $request->get_param( 'bot_id' );
		$user_id = $request->get_param( 'user_id' );
		$message = $request->get_param( 'message' );
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot không tồn tại', array( 'status' => 404 ) );
		}
		
		$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
		$result = $api->send_message( $user_id, $message );
		
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'send_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}
		
		// Log
		$db->insert_log( $bot_id, 'api_send_message', json_encode( array(
			'user_id' => $user_id,
			'message' => $message,
			'result'  => $result,
		) ) );
		
		return rest_ensure_response( array(
			'success' => true,
			'data'    => $result,
		) );
	}
	
	/**
	 * Send photo endpoint
	 */
	public function send_photo( $request ) {
		$bot_id = $request->get_param( 'bot_id' );
		$user_id = $request->get_param( 'user_id' );
		$photo_url = $request->get_param( 'photo_url' );
		$caption = $request->get_param( 'caption' );
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot không tồn tại', array( 'status' => 404 ) );
		}
		
		$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
		$result = $api->send_photo( $user_id, $photo_url, $caption );
		
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'send_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}
		
		// Log
		$db->insert_log( $bot_id, 'api_send_photo', json_encode( array(
			'user_id'   => $user_id,
			'photo_url' => $photo_url,
			'caption'   => $caption,
			'result'    => $result,
		) ) );
		
		return rest_ensure_response( array(
			'success' => true,
			'data'    => $result,
		) );
	}
	
	/**
	 * Reply comment endpoint
	 */
	public function reply_comment( $request ) {
		$bot_id = $request->get_param( 'bot_id' );
		$comment_id = $request->get_param( 'comment_id' );
		$message = $request->get_param( 'message' );
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot không tồn tại', array( 'status' => 404 ) );
		}
		
		$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
		$result = $api->reply_comment( $comment_id, $message );
		
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'reply_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}
		
		// Log
		$db->insert_log( $bot_id, 'api_reply_comment', json_encode( array(
			'comment_id' => $comment_id,
			'message'    => $message,
			'result'     => $result,
		) ) );
		
		return rest_ensure_response( array(
			'success' => true,
			'data'    => $result,
		) );
	}
	
	/**
	 * Get bots endpoint
	 */
	public function get_bots( $request ) {
		$db = BizCity_Facebook_Bot_Database::instance();
		$bots = $db->get_active_bots();
		
		// Remove sensitive data
		$safe_bots = array();
		foreach ( $bots as $bot ) {
			$safe_bots[] = array(
				'id'       => $bot->id,
				'bot_name' => $bot->bot_name,
				'page_id'  => $bot->page_id,
				'status'   => $bot->status,
			);
		}
		
		return rest_ensure_response( array(
			'success' => true,
			'data'    => $safe_bots,
		) );
	}
	
	/**
	 * Get inbox endpoint
	 */
	public function get_inbox( $request ) {
		$bot_id = $request->get_param( 'bot_id' );
		$limit = $request->get_param( 'limit' );
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$messages = $db->get_inbox_messages( $bot_id, $limit );
		
		return rest_ensure_response( array(
			'success' => true,
			'data'    => $messages,
		) );
	}
	
	/**
	 * Get customers endpoint
	 */
	public function get_customers( $request ) {
		$bot_id = $request->get_param( 'bot_id' );
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$customers = $db->get_customers( $bot_id );
		
		return rest_ensure_response( array(
			'success' => true,
			'data'    => $customers,
		) );
	}
	
	/**
	 * Get page info endpoint
	 */
	public function get_page_info( $request ) {
		$bot_id = $request->get_param( 'bot_id' );
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot không tồn tại', array( 'status' => 404 ) );
		}
		
		$api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
		$result = $api->get_me();
		
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'api_error', $result->get_error_message(), array( 'status' => 400 ) );
		}
		
		return rest_ensure_response( array(
			'success' => true,
			'data'    => $result,
		) );
	}
}

// Initialize
BizCity_Facebook_Bot_REST_API::instance();
