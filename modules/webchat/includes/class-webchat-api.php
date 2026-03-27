<?php
/**
 * Bizcity Twin AI — WebChat REST API
 * API chuẩn cho tích hợp với bizcity-automation / Standard API for automation integration
 *
 * Provides: inbox, send, list, ready endpoints.
 * Compatible with bizcity-automation triggers and actions.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_WebChat_API {
	
	private static $instance = null;
	
	/**
	 * API namespace for REST routes
	 */
	const NAMESPACE = 'bizcity-webchat/v1';
	
	/**
	 * Platform identifier
	 */
	const PLATFORM = 'webchat';
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		// REST API routes
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		
		// Hooks for bizcity-automation integration
		$this->register_automation_hooks();
	}
	
	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// Ready check - kiểm tra trạng thái sẵn sàng
		register_rest_route( self::NAMESPACE, '/ready', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_ready' ),
			'permission_callback' => '__return_true',
		) );
		
		// Send message - gửi tin nhắn đến session
		register_rest_route( self::NAMESPACE, '/send', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_send_message' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Session ID của client',
				),
				'message' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'description'       => 'Nội dung tin nhắn',
				),
				'attachments' => array(
					'required'          => false,
					'type'              => 'array',
					'description'       => 'Danh sách attachments',
				),
			),
		) );
		
		// Inbox - lấy tin nhắn của một session
		register_rest_route( self::NAMESPACE, '/inbox', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_get_inbox' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 50,
					'sanitize_callback' => 'absint',
				),
				'offset' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		) );
		
		// List conversations - liệt kê tất cả conversations
		register_rest_route( self::NAMESPACE, '/list', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_list_conversations' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'status' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'active',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
				'page' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
			),
		) );
		
		// Get conversation detail
		register_rest_route( self::NAMESPACE, '/conversation/(?P<session_id>[a-zA-Z0-9_-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_get_conversation' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
		
		// Close conversation
		register_rest_route( self::NAMESPACE, '/conversation/(?P<session_id>[a-zA-Z0-9_-]+)/close', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_close_conversation' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
		
		// Get timeline/tasks
		register_rest_route( self::NAMESPACE, '/timeline', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_get_timeline' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'session_id' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'task_id' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}
	
	/**
	 * Register hooks for bizcity-automation integration
	 */
	private function register_automation_hooks() {
		// Action: Send message via webchat
		add_filter( 'bizcity_automation_webchat_send', array( $this, 'action_send_message' ), 10, 2 );
		
		// Action: Get inbox
		add_filter( 'bizcity_automation_webchat_inbox', array( $this, 'action_get_inbox' ), 10, 2 );
		
		// Action: List conversations
		add_filter( 'bizcity_automation_webchat_list', array( $this, 'action_list_conversations' ), 10, 2 );
		
		// Action: Ready check
		add_filter( 'bizcity_automation_webchat_ready', array( $this, 'action_ready' ), 10, 1 );
		
		// Trigger: New message received - registered in class-webchat-trigger.php
		// Hook: bizcity_webchat_message_received
		
		// Register integration info
		add_filter( 'bizcity_automation_integrations', array( $this, 'register_integration' ) );
		
		// Register available actions
		add_filter( 'bizcity_automation_actions', array( $this, 'register_actions' ) );
		
		// Register available triggers
		add_filter( 'bizcity_automation_triggers', array( $this, 'register_triggers' ) );
	}
	
	/**
	 * Permission check for REST API
	 */
	public function check_permission( $request ) {
		// Check for API key in header
		$api_key = $request->get_header( 'X-BizCity-API-Key' );
		if ( ! empty( $api_key ) ) {
			$stored_key = get_option( 'bizcity_webchat_api_key', '' );
			if ( ! empty( $stored_key ) && hash_equals( $stored_key, $api_key ) ) {
				return true;
			}
		}
		
		// Check WordPress user permission
		return current_user_can( 'edit_posts' );
	}
	
	// ==========================================
	// REST API Endpoints
	// ==========================================
	
	/**
	 * API: Ready check
	 */
	public function api_ready( $request ) {
		return rest_ensure_response( array(
			'ready'    => true,
			'platform' => self::PLATFORM,
			'version'  => BIZCITY_WEBCHAT_VERSION,
			'time'     => current_time( 'mysql' ),
			'features' => array(
				'send_message'       => true,
				'inbox'              => true,
				'list_conversations' => true,
				'timeline'           => true,
				'ai_response'        => $this->is_ai_enabled(),
			),
		) );
	}
	
	/**
	 * API: Send message
	 */
	public function api_send_message( $request ) {
		$session_id  = $request->get_param( 'session_id' );
		$message     = $request->get_param( 'message' );
		$attachments = $request->get_param( 'attachments' ) ?: array();
		
		$result = $this->send_message( $session_id, $message, array(
			'attachments' => $attachments,
			'from'        => 'api',
		) );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		return rest_ensure_response( array(
			'success'    => true,
			'session_id' => $session_id,
			'message_id' => $result['message_id'] ?? '',
			'sent_at'    => current_time( 'mysql' ),
		) );
	}
	
	/**
	 * API: Get inbox (messages)
	 */
	public function api_get_inbox( $request ) {
		$session_id = $request->get_param( 'session_id' );
		$limit      = $request->get_param( 'limit' ) ?: 50;
		$offset     = $request->get_param( 'offset' ) ?: 0;
		
		$result = $this->get_inbox( $session_id, $limit, $offset );
		
		return rest_ensure_response( array(
			'success'    => true,
			'session_id' => $session_id,
			'messages'   => $result['messages'] ?? array(),
			'total'      => $result['total'] ?? 0,
			'has_more'   => $result['has_more'] ?? false,
		) );
	}
	
	/**
	 * API: List conversations
	 */
	public function api_list_conversations( $request ) {
		$status = $request->get_param( 'status' ) ?: 'active';
		$limit  = $request->get_param( 'limit' ) ?: 20;
		$page   = $request->get_param( 'page' ) ?: 1;
		
		$result = $this->list_conversations( $status, $limit, $page );
		
		return rest_ensure_response( array(
			'success'       => true,
			'conversations' => $result['conversations'] ?? array(),
			'total'         => $result['total'] ?? 0,
			'page'          => $page,
			'pages'         => $result['pages'] ?? 1,
		) );
	}
	
	/**
	 * API: Get conversation detail
	 */
	public function api_get_conversation( $request ) {
		$session_id = $request->get_param( 'session_id' );
		
		$db           = BizCity_WebChat_Database::instance();
		$conversation = $db->get_conversation_by_session( $session_id );
		$messages     = $db->get_conversation_history( $session_id, 100 );
		
		if ( ! $conversation ) {
			return new WP_Error( 'not_found', 'Conversation not found', array( 'status' => 404 ) );
		}
		
		return rest_ensure_response( array(
			'success'      => true,
			'conversation' => $conversation,
			'messages'     => $messages,
		) );
	}
	
	/**
	 * API: Close conversation
	 */
	public function api_close_conversation( $request ) {
		$session_id = $request->get_param( 'session_id' );
		
		$db     = BizCity_WebChat_Database::instance();
		$result = $db->close_conversation( $session_id );
		
		return rest_ensure_response( array(
			'success'    => (bool) $result,
			'session_id' => $session_id,
			'closed_at'  => current_time( 'mysql' ),
		) );
	}
	
	/**
	 * API: Get timeline/tasks
	 */
	public function api_get_timeline( $request ) {
		$session_id = $request->get_param( 'session_id' );
		$task_id    = $request->get_param( 'task_id' );
		
		$db = BizCity_WebChat_Database::instance();
		
		if ( ! empty( $task_id ) ) {
			$timeline = $db->get_task_timeline( $task_id );
			return rest_ensure_response( array(
				'success'  => true,
				'timeline' => $timeline,
			) );
		}
		
		if ( ! empty( $session_id ) ) {
			$tasks = $db->get_session_tasks( $session_id, 20 );
		} else {
			$tasks = $db->get_recent_tasks( 20 );
		}
		
		return rest_ensure_response( array(
			'success' => true,
			'tasks'   => $tasks,
		) );
	}
	
	// ==========================================
	// Core API Methods (for internal use & automation)
	// ==========================================
	
	/**
	 * Send message to session
	 * 
	 * @param string $session_id Session ID
	 * @param string $message    Message content
	 * @param array  $options    Additional options
	 * @return array|WP_Error
	 */
	public function send_message( $session_id, $message, $options = array() ) {
		if ( empty( $session_id ) || empty( $message ) ) {
			return new WP_Error( 'invalid_params', 'Session ID and message are required' );
		}
		
		$db         = BizCity_WebChat_Database::instance();
		$message_id = uniqid( 'bcm_' );
		
		// Log message to database
		$db->log_message( array(
			'session_id'    => $session_id,
			'user_id'       => 0,
			'client_name'   => $options['sender_name'] ?? 'BizChat Bot',
			'message_id'    => $message_id,
			'message_text'  => $message,
			'message_from'  => 'bot',
			'attachments'   => $options['attachments'] ?? array(),
			'platform_type' => 'WEBCHAT',
			'meta'          => array(
				'source' => $options['from'] ?? 'api',
			),
		) );
		
		// Fire hook for realtime push
		do_action( 'bizcity_webchat_push_message', $session_id, $message, $options );
		
		// Log to bizgpt_chat_history if available (backward compatibility)
		if ( function_exists( 'bizgpt_log_chat_message' ) ) {
			bizgpt_log_chat_message( 0, $message, 'bot', $session_id );
		}
		
		return array(
			'success'    => true,
			'message_id' => $message_id,
			'session_id' => $session_id,
		);
	}
	
	/**
	 * Get inbox messages for session
	 * 
	 * @param string $session_id Session ID
	 * @param int    $limit      Limit
	 * @param int    $offset     Offset
	 * @return array
	 */
	public function get_inbox( $session_id, $limit = 50, $offset = 0 ) {
		$db       = BizCity_WebChat_Database::instance();
		$messages = $db->get_conversation_history( $session_id, $limit, $offset );
		$total    = $db->count_messages( $session_id );
		
		return array(
			'messages' => $messages,
			'total'    => $total,
			'has_more' => ( $offset + $limit ) < $total,
		);
	}
	
	/**
	 * List all conversations
	 * 
	 * @param string $status Status filter (active, closed, all)
	 * @param int    $limit  Limit per page
	 * @param int    $page   Page number
	 * @return array
	 */
	public function list_conversations( $status = 'active', $limit = 20, $page = 1 ) {
		$db            = BizCity_WebChat_Database::instance();
		$offset        = ( $page - 1 ) * $limit;
		$conversations = $db->get_conversations( $status, $limit, $offset );
		$total         = $db->count_conversations( $status );
		
		return array(
			'conversations' => $conversations,
			'total'         => $total,
			'pages'         => ceil( $total / $limit ),
		);
	}
	
	/**
	 * Check if webchat is ready
	 * 
	 * @return array
	 */
	public function is_ready() {
		return array(
			'ready'      => true,
			'platform'   => self::PLATFORM,
			'version'    => BIZCITY_WEBCHAT_VERSION,
			'ai_enabled' => $this->is_ai_enabled(),
		);
	}
	
	/**
	 * Check if AI is enabled
	 */
	private function is_ai_enabled() {
		$webchat_key = get_option( 'bizcity_webchat_openai_api_key', '' );
		$system_key  = get_option( 'twf_openai_api_key', '' );
		return ! empty( $webchat_key ) || ! empty( $system_key );
	}
	
	// ==========================================
	// Automation Action Handlers
	// ==========================================
	
	/**
	 * Action handler: Send message
	 */
	public function action_send_message( $result, $params ) {
		$session_id = $params['session_id'] ?? '';
		$message    = $params['message'] ?? '';
		$options    = $params['options'] ?? array();
		
		return $this->send_message( $session_id, $message, $options );
	}
	
	/**
	 * Action handler: Get inbox
	 */
	public function action_get_inbox( $result, $params ) {
		$session_id = $params['session_id'] ?? '';
		$limit      = $params['limit'] ?? 50;
		$offset     = $params['offset'] ?? 0;
		
		return $this->get_inbox( $session_id, $limit, $offset );
	}
	
	/**
	 * Action handler: List conversations
	 */
	public function action_list_conversations( $result, $params ) {
		$status = $params['status'] ?? 'active';
		$limit  = $params['limit'] ?? 20;
		$page   = $params['page'] ?? 1;
		
		return $this->list_conversations( $status, $limit, $page );
	}
	
	/**
	 * Action handler: Ready check
	 */
	public function action_ready( $result ) {
		return $this->is_ready();
	}
	
	// ==========================================
	// Automation Integration Registration
	// ==========================================
	
	/**
	 * Register webchat as an integration
	 */
	public function register_integration( $integrations ) {
		$integrations['webchat'] = array(
			'name'        => 'WebChat',
			'description' => 'BizCity WebChat Bot - Chat trực tiếp trên website',
			'icon'        => 'dashicons-format-chat',
			'category'    => 'messenger',
			'platform'    => self::PLATFORM,
			'ready'       => true,
		);
		return $integrations;
	}
	
	/**
	 * Register available actions for automation
	 */
	public function register_actions( $actions ) {
		// Send message action
		$actions['webchat_send_message'] = array(
			'name'        => 'Gửi tin nhắn WebChat',
			'description' => 'Gửi tin nhắn đến khách hàng qua WebChat',
			'platform'    => self::PLATFORM,
			'callback'    => array( $this, 'action_send_message' ),
			'params'      => array(
				'session_id' => array(
					'type'        => 'string',
					'label'       => 'Session ID',
					'required'    => true,
					'description' => 'ID phiên chat của khách hàng',
				),
				'message' => array(
					'type'        => 'text',
					'label'       => 'Nội dung tin nhắn',
					'required'    => true,
					'description' => 'Tin nhắn cần gửi',
				),
			),
			'outputs' => array(
				'message_id' => array(
					'type'        => 'string',
					'description' => 'ID tin nhắn đã gửi',
				),
				'success' => array(
					'type'        => 'boolean',
					'description' => 'Trạng thái gửi',
				),
			),
		);
		
		// Get inbox action
		$actions['webchat_get_inbox'] = array(
			'name'        => 'Lấy tin nhắn WebChat',
			'description' => 'Lấy danh sách tin nhắn từ một phiên chat',
			'platform'    => self::PLATFORM,
			'callback'    => array( $this, 'action_get_inbox' ),
			'params'      => array(
				'session_id' => array(
					'type'        => 'string',
					'label'       => 'Session ID',
					'required'    => true,
				),
				'limit' => array(
					'type'        => 'number',
					'label'       => 'Số lượng',
					'required'    => false,
					'default'     => 50,
				),
			),
			'outputs' => array(
				'messages' => array(
					'type'        => 'array',
					'description' => 'Danh sách tin nhắn',
				),
				'total' => array(
					'type'        => 'number',
					'description' => 'Tổng số tin nhắn',
				),
			),
		);
		
		// List conversations action
		$actions['webchat_list_conversations'] = array(
			'name'        => 'Liệt kê cuộc hội thoại',
			'description' => 'Lấy danh sách tất cả cuộc hội thoại WebChat',
			'platform'    => self::PLATFORM,
			'callback'    => array( $this, 'action_list_conversations' ),
			'params'      => array(
				'status' => array(
					'type'        => 'select',
					'label'       => 'Trạng thái',
					'required'    => false,
					'default'     => 'active',
					'options'     => array(
						'active' => 'Đang hoạt động',
						'closed' => 'Đã đóng',
						'all'    => 'Tất cả',
					),
				),
				'limit' => array(
					'type'        => 'number',
					'label'       => 'Số lượng',
					'required'    => false,
					'default'     => 20,
				),
			),
			'outputs' => array(
				'conversations' => array(
					'type'        => 'array',
					'description' => 'Danh sách cuộc hội thoại',
				),
				'total' => array(
					'type'        => 'number',
					'description' => 'Tổng số cuộc hội thoại',
				),
			),
		);
		
		return $actions;
	}
	
	/**
	 * Register available triggers for automation
	 */
	public function register_triggers( $triggers ) {
		// New message trigger
		$triggers['webchat_new_message'] = array(
			'name'        => 'Tin nhắn WebChat mới',
			'description' => 'Kích hoạt khi có tin nhắn mới từ khách hàng qua WebChat',
			'platform'    => self::PLATFORM,
			'hook'        => 'bizcity_webchat_message_received',
			'outputs'     => array(
				'session_id' => array(
					'type'        => 'string',
					'description' => 'Session ID của khách hàng',
				),
				'user_id' => array(
					'type'        => 'number',
					'description' => 'WordPress User ID (0 nếu guest)',
				),
				'message' => array(
					'type'        => 'string',
					'description' => 'Nội dung tin nhắn',
				),
				'client_name' => array(
					'type'        => 'string',
					'description' => 'Tên khách hàng',
				),
				'attachments' => array(
					'type'        => 'array',
					'description' => 'File đính kèm',
				),
			),
		);
		
		// Image received trigger
		$triggers['webchat_image_received'] = array(
			'name'        => 'Nhận hình ảnh từ WebChat',
			'description' => 'Kích hoạt khi khách hàng gửi hình ảnh qua WebChat',
			'platform'    => self::PLATFORM,
			'hook'        => 'bizcity_webchat_image_received',
			'outputs'     => array(
				'session_id' => array(
					'type'        => 'string',
					'description' => 'Session ID',
				),
				'image_url' => array(
					'type'        => 'string',
					'description' => 'URL hình ảnh',
				),
				'message' => array(
					'type'        => 'string',
					'description' => 'Caption (nếu có)',
				),
			),
		);
		
		return $triggers;
	}
}

// ==========================================
// Global Helper Functions
// ==========================================

if ( ! function_exists( 'bizcity_webchat_api' ) ) {
	/**
	 * Get WebChat API instance
	 * 
	 * @return BizCity_WebChat_API
	 */
	function bizcity_webchat_api() {
		return BizCity_WebChat_API::instance();
	}
}

if ( ! function_exists( 'bizcity_webchat_send' ) ) {
	/**
	 * Send message via WebChat
	 * 
	 * @param string $session_id Session ID
	 * @param string $message    Message content
	 * @param array  $options    Additional options
	 * @return array|WP_Error
	 */
	function bizcity_webchat_send( $session_id, $message, $options = array() ) {
		return bizcity_webchat_api()->send_message( $session_id, $message, $options );
	}
}

if ( ! function_exists( 'bizcity_webchat_inbox' ) ) {
	/**
	 * Get inbox for session
	 * 
	 * @param string $session_id Session ID
	 * @param int    $limit      Limit
	 * @return array
	 */
	function bizcity_webchat_inbox( $session_id, $limit = 50 ) {
		return bizcity_webchat_api()->get_inbox( $session_id, $limit );
	}
}

if ( ! function_exists( 'bizcity_webchat_list' ) ) {
	/**
	 * List conversations
	 * 
	 * @param string $status Status filter
	 * @param int    $limit  Limit
	 * @return array
	 */
	function bizcity_webchat_list( $status = 'active', $limit = 20 ) {
		return bizcity_webchat_api()->list_conversations( $status, $limit );
	}
}

if ( ! function_exists( 'bizcity_webchat_ready' ) ) {
	/**
	 * Check if webchat is ready
	 * 
	 * @return array
	 */
	function bizcity_webchat_ready() {
		return bizcity_webchat_api()->is_ready();
	}
}
