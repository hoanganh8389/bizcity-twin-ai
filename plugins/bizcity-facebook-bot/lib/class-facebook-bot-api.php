<?php
/**
 * BizCity Facebook Bot API Client
 * Official Facebook Graph API integration for Messenger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Facebook_Bot_API {
	
	/**
	 * Page access token
	 */
	private $access_token;
	
	/**
	 * Page ID
	 */
	private $page_id;
	
	/**
	 * Facebook Graph API base URL
	 */
	private $api_base = 'https://graph.facebook.com/v18.0/';
	
	/**
	 * Constructor
	 * 
	 * @param string $access_token Page access token
	 * @param string $page_id Optional page ID
	 */
	public function __construct( $access_token, $page_id = '' ) {
		$this->access_token = $access_token;
		$this->page_id = $page_id;
	}
	
	/**
	 * Send text message to user
	 * 
	 * @param string $user_id Recipient PSID
	 * @param string $text Message text
	 * @return array|WP_Error Response
	 */
	public function send_message( $user_id, $text ) {
		$endpoint = 'me/messages';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'message' => array(
				'text' => $text,
			),
			'messaging_type' => 'RESPONSE',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Send text message (alias)
	 */
	public function send_text_message( $user_id, $text ) {
		return $this->send_message( $user_id, $text );
	}
	
	/**
	 * Send image/photo message
	 * 
	 * @param string $user_id Recipient PSID
	 * @param string $image_url URL of image
	 * @param string $caption Optional caption (sent as separate message)
	 * @return array|WP_Error Response
	 */
	public function send_photo( $user_id, $image_url, $caption = '' ) {
		$endpoint = 'me/messages';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'message' => array(
				'attachment' => array(
					'type' => 'image',
					'payload' => array(
						'url' => $image_url,
						'is_reusable' => true,
					),
				),
			),
			'messaging_type' => 'RESPONSE',
		);
		
		$result = $this->request( $endpoint, $data );
		
		// Send caption as separate message if provided
		if ( ! is_wp_error( $result ) && ! empty( $caption ) ) {
			$this->send_message( $user_id, $caption );
		}
		
		return $result;
	}
	
	/**
	 * Send image message (alias)
	 */
	public function send_image_message( $user_id, $image_url, $caption = '' ) {
		return $this->send_photo( $user_id, $image_url, $caption );
	}
	
	/**
	 * Send file message
	 * 
	 * @param string $user_id Recipient PSID
	 * @param string $file_url URL of file
	 * @return array|WP_Error Response
	 */
	public function send_file_message( $user_id, $file_url ) {
		$endpoint = 'me/messages';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'message' => array(
				'attachment' => array(
					'type' => 'file',
					'payload' => array(
						'url' => $file_url,
						'is_reusable' => true,
					),
				),
			),
			'messaging_type' => 'RESPONSE',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Send video message
	 * 
	 * @param string $user_id Recipient PSID
	 * @param string $video_url URL of video
	 * @return array|WP_Error Response
	 */
	public function send_video_message( $user_id, $video_url ) {
		$endpoint = 'me/messages';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'message' => array(
				'attachment' => array(
					'type' => 'video',
					'payload' => array(
						'url' => $video_url,
						'is_reusable' => true,
					),
				),
			),
			'messaging_type' => 'RESPONSE',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Send button template
	 * 
	 * @param string $user_id Recipient PSID
	 * @param string $text Button text
	 * @param array $buttons Array of buttons
	 * @return array|WP_Error Response
	 */
	public function send_button_template( $user_id, $text, $buttons ) {
		$endpoint = 'me/messages';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'message' => array(
				'attachment' => array(
					'type' => 'template',
					'payload' => array(
						'template_type' => 'button',
						'text' => $text,
						'buttons' => $buttons,
					),
				),
			),
			'messaging_type' => 'RESPONSE',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Send generic template (carousel)
	 * 
	 * @param string $user_id Recipient PSID
	 * @param array $elements Array of elements
	 * @return array|WP_Error Response
	 */
	public function send_generic_template( $user_id, $elements ) {
		$endpoint = 'me/messages';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'message' => array(
				'attachment' => array(
					'type' => 'template',
					'payload' => array(
						'template_type' => 'generic',
						'elements' => $elements,
					),
				),
			),
			'messaging_type' => 'RESPONSE',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Send quick replies
	 * 
	 * @param string $user_id Recipient PSID
	 * @param string $text Message text
	 * @param array $quick_replies Array of quick reply options
	 * @return array|WP_Error Response
	 */
	public function send_quick_replies( $user_id, $text, $quick_replies ) {
		$endpoint = 'me/messages';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'message' => array(
				'text' => $text,
				'quick_replies' => $quick_replies,
			),
			'messaging_type' => 'RESPONSE',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Mark message as seen
	 * 
	 * @param string $user_id Recipient PSID
	 * @return array|WP_Error Response
	 */
	public function mark_seen( $user_id ) {
		$endpoint = 'me/messages';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'sender_action' => 'mark_seen',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Send typing indicator
	 * 
	 * @param string $user_id Recipient PSID
	 * @param bool $on Turn typing on/off
	 * @return array|WP_Error Response
	 */
	public function typing_indicator( $user_id, $on = true ) {
		$endpoint = 'me/messages';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'sender_action' => $on ? 'typing_on' : 'typing_off',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Get user profile
	 * 
	 * @param string $user_id User PSID
	 * @param array $fields Fields to request
	 * @return array|WP_Error Response
	 */
	public function get_user_profile( $user_id, $fields = array( 'first_name', 'last_name', 'profile_pic' ) ) {
		$endpoint = $user_id;
		
		$params = array(
			'fields' => implode( ',', $fields ),
		);
		
		return $this->request( $endpoint, null, 'GET', $params );
	}
	
	/**
	 * Get page info
	 * 
	 * @return array|WP_Error Response
	 */
	public function get_me() {
		$endpoint = 'me';
		
		$params = array(
			'fields' => 'id,name,about,category,fan_count,link,picture',
		);
		
		return $this->request( $endpoint, null, 'GET', $params );
	}
	
	/**
	 * Get page info (alias)
	 */
	public function get_page_info() {
		return $this->get_me();
	}
	
	/**
	 * Reply to a comment
	 * 
	 * @param string $comment_id Comment ID
	 * @param string $message Reply message
	 * @return array|WP_Error Response
	 */
	public function reply_comment( $comment_id, $message ) {
		$endpoint = $comment_id . '/comments';
		
		$data = array(
			'message' => $message,
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Send private reply to comment
	 * 
	 * @param string $comment_id Comment ID
	 * @param string $message Reply message
	 * @return array|WP_Error Response
	 */
	public function reply_comment_private( $comment_id, $message ) {
		$endpoint = $comment_id . '/private_replies';
		
		$data = array(
			'message' => $message,
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Take thread control
	 * 
	 * @param string $user_id User PSID
	 * @return array|WP_Error Response
	 */
	public function take_thread_control( $user_id ) {
		$endpoint = 'me/take_thread_control';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'metadata' => 'BizCity Facebook Bot taking control',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Pass thread control to another app
	 * 
	 * @param string $user_id User PSID
	 * @param string $target_app_id Target app ID
	 * @return array|WP_Error Response
	 */
	public function pass_thread_control( $user_id, $target_app_id ) {
		$endpoint = 'me/pass_thread_control';
		
		$data = array(
			'recipient' => array(
				'id' => $user_id,
			),
			'target_app_id' => $target_app_id,
			'metadata' => 'Passing to another app',
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Get page conversations
	 * 
	 * @param int $limit Number of conversations
	 * @return array|WP_Error Response
	 */
	public function get_conversations( $limit = 20 ) {
		$endpoint = 'me/conversations';
		
		$params = array(
			'fields' => 'participants,updated_time,message_count',
			'limit' => $limit,
		);
		
		return $this->request( $endpoint, null, 'GET', $params );
	}
	
	/**
	 * Get conversation messages
	 * 
	 * @param string $conversation_id Conversation ID
	 * @param int $limit Number of messages
	 * @return array|WP_Error Response
	 */
	public function get_conversation_messages( $conversation_id, $limit = 25 ) {
		$endpoint = $conversation_id . '/messages';
		
		$params = array(
			'fields' => 'message,from,to,created_time,attachments',
			'limit' => $limit,
		);
		
		return $this->request( $endpoint, null, 'GET', $params );
	}
	
	/**
	 * Get page feed (posts)
	 * 
	 * @param int $limit Number of posts
	 * @return array|WP_Error Response
	 */
	public function get_feed( $limit = 20 ) {
		$page_id = ! empty( $this->page_id ) ? $this->page_id : 'me';
		$endpoint = $page_id . '/feed';
		
		$params = array(
			'fields' => 'id,message,created_time,permalink_url,full_picture,type,status_type',
			'limit' => $limit,
		);
		
		return $this->request( $endpoint, null, 'GET', $params );
	}
	
	/**
	 * Create page post
	 * 
	 * @param string $message Post message
	 * @param string $link Optional link
	 * @param string $photo_url Optional photo URL
	 * @return array|WP_Error Response
	 */
	public function create_post( $message, $link = '', $photo_url = '' ) {
		$page_id = ! empty( $this->page_id ) ? $this->page_id : 'me';
		
		if ( ! empty( $photo_url ) ) {
			$endpoint = $page_id . '/photos';
			$data = array(
				'url' => $photo_url,
				'caption' => $message,
			);
		} else {
			$endpoint = $page_id . '/feed';
			$data = array(
				'message' => $message,
			);
			if ( ! empty( $link ) ) {
				$data['link'] = $link;
			}
		}
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Get post comments
	 * 
	 * @param string $post_id Post ID
	 * @param int $limit Number of comments
	 * @return array|WP_Error Response
	 */
	public function get_post_comments( $post_id, $limit = 25 ) {
		$endpoint = $post_id . '/comments';
		
		$params = array(
			'fields' => 'from,message,created_time,like_count,comment_count,permalink_url',
			'limit' => $limit,
		);
		
		return $this->request( $endpoint, null, 'GET', $params );
	}
	
	/**
	 * Subscribe to webhooks
	 * 
	 * @param array $subscribed_fields Fields to subscribe
	 * @return array|WP_Error Response
	 */
	public function subscribe_webhooks( $subscribed_fields = array( 'messages', 'messaging_postbacks', 'feed' ) ) {
		$page_id = ! empty( $this->page_id ) ? $this->page_id : 'me';
		$endpoint = $page_id . '/subscribed_apps';
		
		$data = array(
			'subscribed_fields' => implode( ',', $subscribed_fields ),
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Make API request
	 * 
	 * @param string $endpoint API endpoint
	 * @param array|null $data POST data
	 * @param string $method HTTP method
	 * @param array $params Query parameters
	 * @return array|WP_Error Response
	 */
	private function request( $endpoint, $data = null, $method = 'POST', $params = array() ) {
		$url = $this->api_base . $endpoint;
		
		// Add access token
		$params['access_token'] = $this->access_token;
		
		// Build URL with params
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}
		
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		);
		
		if ( $method === 'POST' && ! empty( $data ) ) {
			$args['body'] = json_encode( $data );
		}
		
		$response = wp_remote_request( $url, $args );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		$http_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );
		
		// Check for Facebook API errors
		if ( isset( $result['error'] ) ) {
			$error_message = isset( $result['error']['message'] ) ? $result['error']['message'] : 'Unknown Facebook API error';
			$error_code = isset( $result['error']['code'] ) ? $result['error']['code'] : 0;
			
			return new WP_Error( 
				'facebook_api_error', 
				$error_message,
				array(
					'code' => $error_code,
					'http_code' => $http_code,
					'response' => $result,
				)
			);
		}
		
		if ( $http_code !== 200 && ! isset( $result['message_id'] ) && ! isset( $result['recipient_id'] ) ) {
			return new WP_Error(
				'http_error',
				'Facebook API HTTP error: ' . $http_code,
				array(
					'http_code' => $http_code,
					'response' => $result,
				)
			);
		}
		
		return $result;
	}
	
	/**
	 * Verify webhook signature
	 * 
	 * @param string $signature X-Hub-Signature header value
	 * @param string $payload Request body
	 * @param string $app_secret App secret
	 * @return bool Is valid
	 */
	public static function verify_signature( $signature, $payload, $app_secret ) {
		if ( empty( $signature ) || empty( $payload ) || empty( $app_secret ) ) {
			return false;
		}
		
		// Signature format: sha256=xxxxx
		$parts = explode( '=', $signature );
		if ( count( $parts ) !== 2 || $parts[0] !== 'sha256' ) {
			return false;
		}
		
		$expected = hash_hmac( 'sha256', $payload, $app_secret );
		
		return hash_equals( $expected, $parts[1] );
	}
}
