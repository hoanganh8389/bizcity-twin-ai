<?php
/**
 * Webhook Handler for Facebook Bot
 * Handles incoming Facebook webhook requests (Messenger & Comments)
 * 
 * Simplified version - directly calls legacy functions from fb-messenger-hook.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Facebook_Bot_Webhook_Handler {
	
	private static $instance = null;
	
	const DEFAULT_VERIFY_TOKEN = 'bizgpt';
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		// Register /bizfbhook/ pretty-URL endpoint
		add_action( 'init',              array( $this, 'register_rewrite' ), 0 );
		add_filter( 'query_vars',        array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'handle_rewrite_request' ), 0 );
		// Handle webhook via query param ?fbhook=1 - Priority 0
		add_action( 'init', array( $this, 'handle_webhook' ), 0 );
	}

	/**
	 * Register /bizfbhook/ rewrite rule.
	 */
	public function register_rewrite(): void {
		add_rewrite_rule( '^bizfbhook/?$', 'index.php?bztfb_webhook_route=1', 'top' );
	}

	/**
	 * Add bztfb_webhook_route to recognised query vars.
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = 'bztfb_webhook_route';
		return $vars;
	}

	/**
	 * Handle request arriving via the /bizfbhook/ pretty URL.
	 */
	public function handle_rewrite_request(): void {
		if ( get_query_var( 'bztfb_webhook_route' ) !== '1' ) {
			return;
		}
		// Disable cache
		if ( ! defined( 'DONOTCACHEPAGE' ) )  define( 'DONOTCACHEPAGE',  true );
		if ( ! defined( 'DONOTCACHEDB' ) )    define( 'DONOTCACHEDB',    true );
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );

		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		if ( $method === 'GET' ) {
			$this->verify_webhook();
			exit;
		}
		if ( $method === 'POST' ) {
			$this->process_webhook();
			exit;
		}
		http_response_code( 405 );
		echo 'Method Not Allowed';
		exit;
	}
	
	/**
	 * Handle incoming webhook request.
	 * Responds to both ?fbhook=1 (legacy) and /bizfbhook/ pretty URL.
	 * The URI-based check works WITHOUT needing flush_rewrite_rules().
	 */
	public function handle_webhook() {
		$is_fbhook_param  = isset( $_GET['fbhook'] ) && (string) $_GET['fbhook'] === '1';
		$request_uri      = $_SERVER['REQUEST_URI'] ?? '';
		$is_bizfbhook_uri = (bool) preg_match( '#^/bizfbhook/?(\?|$)#', $request_uri );

		if ( ! $is_fbhook_param && ! $is_bizfbhook_uri ) {
			return;
		}
		
		$this->log_info( 'Webhook endpoint hit', array(
			'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
			'host' => $_SERVER['HTTP_HOST'] ?? '',
		) );
		
		// Disable cache
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		if ( ! defined( 'DONOTCACHEDB' ) ) define( 'DONOTCACHEDB', true );
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
		
		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		
		if ( $method === 'GET' ) {
			$this->verify_webhook();
			exit;
		}
		
		if ( $method === 'POST' ) {
			$this->process_webhook();
			exit;
		}
		
		http_response_code( 405 );
		echo 'Method Not Allowed';
		exit;
	}
	
	/**
	 * Verify webhook subscription
	 */
	private function verify_webhook() {
		$mode      = (string) ( $_GET['hub_mode'] ?? ( $_GET['hub.mode'] ?? '' ) );
		$token     = (string) ( $_GET['hub_verify_token'] ?? ( $_GET['hub.verify_token'] ?? '' ) );
		$challenge = (string) ( $_GET['hub_challenge'] ?? ( $_GET['hub.challenge'] ?? '' ) );
		
		$this->log_info( 'Verify attempt', array( 'mode' => $mode, 'token' => $token ) );
		
		$verify_token = get_option( 'bztfb_verify_token', 'bizfbhook' );
		if ( $mode === 'subscribe' && hash_equals( $verify_token, $token ) && $challenge !== '' ) {
			while ( ob_get_level() ) ob_end_clean();
			
			http_response_code( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			echo $challenge;
			
			$this->log_info( 'Verify SUCCESS' );
			exit;
		}
		
		$this->log_error( 'Verify FAILED' );
		http_response_code( 403 );
		echo 'Forbidden';
		exit;
	}
	
	/**
	 * Process incoming webhook data
	 */
	private function process_webhook() {
		$input = file_get_contents( 'php://input' );
		$this->log_request( $input );
		
		if ( empty( $input ) ) {
			status_header( 200 );
			echo 'OK';
			exit;
		}
		
		$data = json_decode( $input, true );
		
		if ( ! is_array( $data ) || empty( $data['entry'] ) ) {
			status_header( 200 );
			echo 'OK';
			exit;
		}
		
		#back_trace( 'NOTICE', 'Facebook Webhook Data Received: ' . print_r( $data, true ) );
		
		foreach ( $data['entry'] as $entry ) {
			$page_id = $entry['id'] ?? '';
			
			// Handle messaging events
			if ( isset( $entry['messaging'] ) && is_array( $entry['messaging'] ) ) {
				foreach ( $entry['messaging'] as $messaging ) {
					$this->handle_messenger_message( $page_id, $messaging );
				}
			}
			
			// Handle feed/comment events
			if ( isset( $entry['changes'] ) && is_array( $entry['changes'] ) ) {
				foreach ( $entry['changes'] as $change ) {
					$this->handle_facebook_feed_event( $page_id, $change );
				}
			}
		}
		
		status_header( 200 );
		echo 'OK';
		exit;
	}
	
	/**
	 * Handle Messenger Message - Call legacy functions directly
	 */
	private function handle_messenger_message( $page_id, $messaging ) {
		$client_id    = $messaging['sender']['id'] ?? '';
		$message      = $messaging['message'] ?? array();
		$message_text = $message['text'] ?? '';
		$attachments  = $message['attachments'] ?? array();
		$timestamp    = $messaging['timestamp'] ?? '';
		$referral     = $messaging['referral'] ?? array();
		$message_id   = $message['mid'] ?? '';
		
		$this->log_info( 'Processing messenger message', array(
			'page_id'    => $page_id,
			'client_id'  => $client_id,
			'message_id' => $message_id,
			'has_text'   => ! empty( $message_text ),
			'has_attach' => ! empty( $attachments ),
			'has_ref'    => ! empty( $referral ),
		) );
		
		// Skip if sender is page itself
		if ( $client_id === $page_id ) {
			$this->log_info( 'Skipping - sender is page itself' );
			return;
		}
		
		// Prevent duplicate processing
		if ( $message_id && get_transient( "fb_msg_{$client_id}_{$message_id}" ) ) {
			$this->log_info( 'Skipping - duplicate message' );
			return;
		}
		set_transient( "fb_msg_{$client_id}_{$message_id}", 1, 2 * MINUTE_IN_SECONDS );
		
		// Get customer profile
		$client_profile = array( 'name' => '' );
		if ( function_exists( 'messenger_get_fb_customer' ) ) {
			$client_profile = messenger_get_fb_customer( $page_id, $client_id );
		}
		
		// Process attachments
		$attachment_urls = array();
		foreach ( $attachments as $att ) {
			if ( ! empty( $att['payload']['url'] ) ) {
				$attachment_urls[] = $att['payload']['url'];
			}
		}
		
		// Format input data
		$input_data = array();
		if ( function_exists( 'bizgpt_format_input_data' ) ) {
			$input_data = bizgpt_format_input_data( $page_id, $client_id, $client_profile, $message_text, $message_id, $timestamp, $attachment_urls );
		}
		
		// Set hook_data transient
		set_transient( 'hook_data', array(
			'user_id'     => 0,
			'client_id'   => $client_id,
			'session_id'  => '',
			'page_id'     => $page_id,
			'platform'    => 'FB_MESS',
			'client_name' => $client_profile['name'] ?? '',
		), 10 * MINUTE_IN_SECONDS );
		
		// Handle referral only (no message)
		if ( ! empty( $referral ) && empty( $message ) ) {
			$this->log_info( 'Processing referral only (no message)' );
			$this->handle_referral( $page_id, $client_id, $messaging, $input_data );
			return;
		}
		
		// Log inbox message
		/*if ( function_exists( 'bizgpt_log_inbox_msg' ) && ! empty( $input_data ) ) {
			bizgpt_log_inbox_msg( $input_data );
		}*/
		
		// Save to bizcity_facebook_inbox table
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot_id = $this->get_bot_id_by_page( $page_id );
		
		// Save text message
		if ( ! empty( $message_text ) ) {
			$db->save_inbox_message( array(
				'bot_id'       => $bot_id,
				'client_id'    => $client_id,
				'client_name'  => $client_profile['name'] ?? '',
				'page_id'      => $page_id,
				'message_id'   => $message_id,
				'message_text' => $message_text,
				'message_type' => 'text',
				'sender_type'  => 'client',
			) );
		}
		
		// Save image messages
		if ( ! empty( $attachment_urls ) ) {
			foreach ( $attachment_urls as $img_url ) {
				$db->save_inbox_message( array(
					'bot_id'       => $bot_id,
					'client_id'    => $client_id,
					'client_name'  => $client_profile['name'] ?? '',
					'page_id'      => $page_id,
					'message_id'   => $message_id,
					'message_text' => '[Hình ảnh]',
					'message_type' => 'image',
					'sender_type'  => 'client',
					'attachment_url' => $img_url,
				) );
			}
		}
        
		// Save/update customer profile
		$db->save_customer( array(
			'client_id'   => $client_id,
			'page_id'     => $page_id,
			'name'        => $client_profile['name'] ?? '',
			'profile_pic' => $client_profile['profile_pic'] ?? '',
		) );
		
		// Handle image attachments
		if ( ! empty( $attachment_urls ) ) {
			$this->log_info( 'Processing image attachments', array( 'count' => count( $attachment_urls ) ) );
			foreach ( $attachment_urls as $img_url ) {
				$this->handle_messenger_image( $page_id, $client_id, $img_url );
			}
			return;
		}
		
		// Handle text message
		if ( ! empty( $message_text ) ) {
			$this->log_info( 'Processing text message', array( 'text' => substr( $message_text, 0, 50 ) ) );
			$this->handle_messenger_text( $page_id, $client_id, $message_text, $input_data );
		}
		
		// Handle referral (with message)
		if ( ! empty( $referral ) ) {
			$this->handle_referral( $page_id, $client_id, $messaging, $input_data );
		}
	}
	
	/**
	 * Handle text message - Fire workflow trigger TRƯỚC, sau đó mới gọi bizgpt_chatbot_run_guest_flows nếu cần
	 */
	private function handle_messenger_text( $page_id, $client_id, $message_text, $input_data ) {
        
		$this->log_info( '=== HANDLE TEXT START ===' );
		
		// Check functions exist
		if ( ! function_exists( 'fb_messenger_reply' ) ) {
			$this->log_error( 'fb_messenger_reply NOT FOUND' );
			return;
		}
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot_id = $this->get_bot_id_by_page( $page_id );
		
		// 1. Fire workflow trigger for automation TRƯỚC
		$trigger_data = array(
			'bot_id'    => $bot_id,
			'user_id'   => $client_id,
			'message'   => $message_text,
			'timestamp' => time() * 1000,
			'page_id'   => $page_id,
			'event'     => $input_data,
            'platform'  => 'FB_MESS',
		);
		
		// Filter cho phép workflow báo hiệu đã xử lý message
		$workflow_handled = apply_filters( 'bizcity_facebook_workflow_handle_message', false, $trigger_data, $input_data );
		
		do_action( 'waic_twf_process_flow', 'bizcity_facebook_message_received', $trigger_data );
		$this->log_info( 'Fired waic_twf_process_flow trigger: bizcity_facebook_message_received' );
		
		// 2. Nếu workflow đã xử lý, không cần gọi bizgpt_chatbot_run_guest_flows
		if ( $workflow_handled ) {
			$this->log_info( 'Workflow handled message, skipping bizgpt_chatbot_run_guest_flows' );
			$this->log_info( '=== HANDLE TEXT END ===' );
			return;
		}
		
		// 3. Nếu không có workflow nào xử lý, gọi bizgpt_chatbot_run_guest_flows
		if ( ! function_exists( 'bizgpt_chatbot_run_guest_flows' ) ) {
			$this->log_error( 'bizgpt_chatbot_run_guest_flows NOT FOUND' );
			return;
		}
		
		$this->log_info( 'Calling bizgpt_chatbot_run_guest_flows', array(
			'message' => substr( $message_text, 0, 100 ),
			'platform' => 'FB_MESS',
		) );
		
		$client_context = '';
		$arr = bizgpt_chatbot_run_guest_flows( $message_text, 'FB_MESS', $input_data, $client_context );
		
		$this->log_info( 'Chatbot response', array(
			'type'  => gettype( $arr ),
			'count' => is_array( $arr ) ? count( $arr ) : 0,
		) );
        #back_trace('NOTICE', 'Chatbot response  response: ' . print_r( $arr, true ));  
		
		$this->log_info( 'Sending arr', array( 'arr' => print_r( $arr, true ) ) );
					
		if ( is_array( $arr ) ) {
			foreach ( $arr as $item ) {
				if ( ! empty( $item['msg'] ) ) {
					$msg_text = html_entity_decode( wp_strip_all_tags( str_replace( '<br>', "\n", $item['msg'] ) ), ENT_QUOTES, 'UTF-8' );
					$this->log_info( 'Sending reply', array( 'msg' => substr( $msg_text, 0, 100 ) ) );
					fb_messenger_reply( $page_id, $client_id, 'AI: ' . $msg_text );
					
					// Save bot reply to inbox
					$db->save_inbox_message( array(
						'bot_id'       => $bot_id,
						'client_id'    => $client_id,
						'client_name'  => '',
						'page_id'      => $page_id,
						'message_id'   => '',
						'message_text' => 'AI: ' . $msg_text,
						'message_type' => 'text',
						'sender_type'  => 'bot',
                       // 'platform_type'  => 'FB_MESS',
					) );
				}
			}
		}
		
		$this->log_info( '=== HANDLE TEXT END ===' );
	}
	
	/**
	 * Handle image message - Call GPT Vision
	 */
	private function handle_messenger_image( $page_id, $client_id, $img_url ) {
		$this->log_info( '=== HANDLE IMAGE START ===' );
		
		if ( ! function_exists( 'fb_messenger_reply' ) ) {
			$this->log_error( 'fb_messenger_reply NOT FOUND' );
			return;
		}
		
		// Check duplicate image
		$img_hash = md5( $img_url );
		$transient_key = 'fb_img_' . $client_id . '_' . $img_hash;
		
		if ( get_transient( $transient_key ) ) {
			$reply_text = 'Bạn vừa gửi 1 hình ảnh trùng lặp trong vòng dưới 2 phút. Tôi chưa được cho phép để giải thích về ảnh liên tục.';
			fb_messenger_reply( $page_id, $client_id, 'AI: ' . $reply_text );
			return;
		}
		set_transient( $transient_key, 1, 2 * MINUTE_IN_SECONDS );
		
		// Send acknowledgment
		$reply_text = 'Dạ. Bạn vừa gửi 1 hình ảnh. ';
		fb_messenger_reply( $page_id, $client_id, 'AI: ' . $reply_text );
		
		// Save bot reply to inbox
		$db = BizCity_Facebook_Bot_Database::instance();
		$bot_id = $this->get_bot_id_by_page( $page_id );
		$db->save_inbox_message( array(
			'bot_id'       => $bot_id,
			'client_id'    => $client_id,
			'client_name'  => '',
			'page_id'      => $page_id,
			'message_id'   => '',
			'message_text' => 'AI: ' . $reply_text,
			'message_type' => 'text',
			'sender_type'  => 'bot',
           // 'platform_type'  => 'FB_MESS',
		) );
		
		// Process with GPT Vision if functions exist
		$api_key = get_option( 'twf_openai_api_key' );
		
		if ( function_exists( 'send_chatbot_chatgpt_upload_files' ) && function_exists( 'chatbot_chatgpt_custom_gpt_call_api' ) && ! empty( $api_key ) ) {
			$this->log_info( 'Processing image with GPT Vision' );
			
			$blog_info = get_blog_details( get_current_blog_id() );
			$facebook_access_token = esc_attr( get_option( 'twf_facebook_access_token', '' ) );
			$facebook_page_id = esc_attr( get_option( 'twf_facebook_page_id', '' ) );
			
			$img_response = send_chatbot_chatgpt_upload_files( $img_url, $api_key, $facebook_page_id, $facebook_access_token );
			$file_id = $img_response['id'] ?? '';
			$file_type = 'vision';
			$file_ids = array();
			$file_ids[] = $file_id;
			$file_ids[ $file_id ] = $file_type;
			
			$assistant_id = 'asst_O85cidOL5HdRvUaSOETinlEE';
			$message = 'Ảnh gì đây: ' . $file_id;
			$client_context = '';
			$thread_id = '';
			$session_id = '';
			$user_id = 0;
			
			$response = chatbot_chatgpt_custom_gpt_call_api( $api_key, $client_context . $message, $assistant_id, $thread_id, $session_id, $user_id, $page_id, $file_ids );
			
			if ( ! empty( $response ) ) {
				fb_messenger_reply( $page_id, $client_id, 'AI: ' . $response );
				
				// Save GPT Vision reply to inbox
				$db->save_inbox_message( array(
					'bot_id'       => $bot_id,
					'client_id'    => $client_id,
					'client_name'  => '',
					'page_id'      => $page_id,
					'message_id'   => '',
					'message_text' => 'AI: ' . $response,
					'message_type' => 'text',
					'sender_type'  => 'bot',
				) );
			}
		}
		
		// Fire workflow trigger for automation
		$bot_id = $this->get_bot_id_by_page( $page_id );
		$trigger_data = array(
			'bot_id'    => $bot_id,
			'user_id'   => $client_id,
			'image_url' => $img_url,
			'message'   => '',
			'timestamp' => time() * 1000,
			'page_id'   => $page_id,
		);
		do_action( 'waic_twf_process_flow', 'bizcity_facebook_image_received', $trigger_data );
		$this->log_info( 'Fired waic_twf_process_flow trigger: bizcity_facebook_image_received' );
		
		$this->log_info( '=== HANDLE IMAGE END ===' );
	}
	
	/**
	 * Handle referral - Call bizgpt_run_flow_steps
	 */
	private function handle_referral( $page_id, $client_id, $messaging, $input_data = array() ) {
		$ref = $messaging['referral']['ref'] ?? $messaging['postback']['referral']['ref'] ?? '';
		
		if ( empty( $ref ) ) {
			return;
		}

		// [2026-06-09 Johnny Chu] R-CG-FB-WEBHOOK — Always error_log so referral
		// events leave a trace even before the CG debug logger is initialized.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[bizcity-fb-bot] handle_referral page_id=' . $page_id . ' client_id=' . $client_id . ' ref=' . $ref );
		}
		
		$this->log_info( '=== HANDLE REFERRAL START ===' );
		
		// Decrypt referral
		$ref_decrypted = $ref;
		if ( function_exists( 'twf_decrypt_chat_id' ) ) {
			$ref_decrypted = twf_decrypt_chat_id( $ref );
			if ( ! $ref_decrypted ) {
				$ref_decrypted = $ref;
			}
		}
		
		$this->log_info( 'Referral', array( 'ref' => $ref, 'decrypted' => $ref_decrypted ) );
		
		// Check spam lock (3 minutes)
		$lock_key = 'bizgpt_ref_lock_' . md5( $page_id . '|' . $client_id . '|' . $ref_decrypted );
		if ( get_transient( $lock_key ) && get_current_blog_id() === '418' ) {
			$this->log_info( 'Referral lock active (skip)' );
			return;
		}
		set_transient( $lock_key, 1, 3 * MINUTE_IN_SECONDS );
		
		// Fire action cho sub-systems (loyalty scenarios, analytics…) lắng nghe referral.
		// Listeners có lock 60s riêng → vẫn an toàn nếu hook gọi nhiều lần.
		do_action( 'bizcity_facebook_referral_received', array(
			'page_id'       => $page_id,
			'client_id'     => $client_id,
			'ref'           => $ref,
			'ref_decrypted' => $ref_decrypted,
			'input_data'    => $input_data,
		) );
		
		// Run flow steps
		if ( function_exists( 'bizgpt_run_flow_steps' ) && function_exists( 'fb_messenger_reply' ) ) {
			// Guard: bizgpt_run_flow_steps( int $flow_id, array $ctx ). When ref is
			// a Ref_Codec token like `camp_xxxxx` (not an int flow_id), skip — the
			// CampaignTracker / Scenario Dispatcher already handles it via the
			// `bizcity_facebook_referral_received` action above.
			$flow_id_int = is_numeric( $ref_decrypted ) ? (int) $ref_decrypted : 0;
			if ( $flow_id_int <= 0 ) {
				$this->log_info( 'Ref is not a numeric flow_id (likely camp_<token>) — skip bizgpt_run_flow_steps', array( 'ref' => $ref ) );
				$this->log_info( '=== HANDLE REFERRAL END ===' );
				return;
			}

			// R-DCL-NAME 2026-05-26: when the decrypted id matches a Campaign row
			// (direct id OR imported_from_bizgpt_flow_id), the Campaign Tracker +
			// Scenario Dispatcher pipeline already owns the response. Skip the
			// legacy bizgpt_run_flow_steps path to avoid "table doesn't exist"
			// DB errors on sites that never had `wp_*_bizcity_crm_flows` (eg.
			// bizcity.vn blog 1258 where campaigns own all scenarios).
			$campaign_matched = false;
			if ( class_exists( 'BizCity_CRM_Campaign_Ref_Codec' ) ) {
				$cid = BizCity_CRM_Campaign_Ref_Codec::decode( (string) $ref_decrypted );
				if ( $cid > 0 ) {
					$campaign_matched = true;
				}
			}
			if ( ! $campaign_matched && class_exists( 'BizCity_CRM_Campaign_Repository' ) ) {
				// Last-ditch direct lookup (covers raw ciphertext case).
				$cid2 = class_exists( 'BizCity_CRM_Campaign_Ref_Codec' )
					? BizCity_CRM_Campaign_Ref_Codec::decode( (string) $ref )
					: 0;
				if ( $cid2 > 0 ) { $campaign_matched = true; }
			}
			if ( $campaign_matched ) {
				$this->log_info( 'Ref resolves to a Campaign — skip legacy bizgpt_run_flow_steps (Scenario Dispatcher will handle)', array( 'ref' => $ref, 'decrypted' => $ref_decrypted ) );
				$this->log_info( '=== HANDLE REFERRAL END ===' );
				return;
			}

			$this->log_info( 'Calling bizgpt_run_flow_steps' );
			$arr = bizgpt_run_flow_steps( $flow_id_int, $input_data );
			
			$this->log_info( 'Flow steps response', array( 'count' => is_array( $arr ) ? count( $arr ) : 0 ) );
			
			if ( is_array( $arr ) ) {
				foreach ( $arr as $item ) {
					if ( ! empty( $item['msg'] ) ) {
						$this->log_info( 'Sending referral reply', array( 'msg' => substr( $item['msg'], 0, 100 ) ) );
						fb_messenger_reply( $page_id, $client_id, 'AI: ' . $item['msg'] );
					}
				}
			}
		} else {
			$this->log_error( 'bizgpt_run_flow_steps or fb_messenger_reply NOT FOUND' );
		}
		
		$this->log_info( '=== HANDLE REFERRAL END ===' );
	}
	
	/**
	 * Handle Facebook Feed Event (Comments)
	 */
	private function handle_facebook_feed_event( $page_id, $change ) {
		$value = $change['value'] ?? array();
		
		if ( ( $value['item'] ?? '' ) !== 'comment' || empty( $value['message'] ) ) {
			return;
		}
		
		$this->log_info( '=== HANDLE COMMENT START ===' );
		
		$comment_id_parts = explode( '_', $value['comment_id'] ?? '' );
		$comment_id = end( $comment_id_parts );
		$message    = $value['message'];
		$from_name  = $value['from']['name'] ?? 'Người dùng';
		$from_id    = $value['from']['id'] ?? '';
		$post_id    = $value['post_id'] ?? '';
		
		$this->log_info( 'Comment data', array(
			'comment_id' => $comment_id,
			'from_name'  => $from_name,
			'from_id'    => $from_id,
			'message'    => substr( $message, 0, 50 ),
		) );
		
		// Prevent duplicate
		$transient_key = 'fb_comment_' . $comment_id;
		if ( get_transient( $transient_key ) ) {
			$this->log_info( 'Skipping - duplicate comment' );
			return;
		}
		set_transient( $transient_key, 1, 3 * MINUTE_IN_SECONDS );
		
		// Skip if comment from page itself
		if ( $from_id === $page_id ) {
			$this->log_info( 'Skipping - comment from page itself' );
			return;
		}
		
		// Get access token
		$pages = get_option( 'fb_pages_connected' );
		$access_token = null;
		
		if ( is_array( $pages ) ) {
			foreach ( $pages as $page ) {
				if ( ( $page['id'] ?? '' ) === $page_id ) {
					$access_token = $page['access_token'] ?? null;
					break;
				}
			}
		}
		
		if ( ! $access_token ) {
			$this->log_error( 'No access token found for page_id: ' . $page_id );
			return;
		}
		
		// Check post type (livestream or normal)
		$post_type = 'feed';
		$check_url = "https://graph.facebook.com/v18.0/{$post_id}?fields=id,permalink_url,type,message,description,story&access_token={$access_token}";
		$check_response = wp_remote_get( $check_url );
		$check_data = json_decode( wp_remote_retrieve_body( $check_response ), true );
		
		if ( ! empty( $check_data['type'] ) && $check_data['type'] === 'video' ) {
			$post_type = 'live_video';
		}
		
		// Get post caption for AI context
		$post_caption = $check_data['message'] ?? $check_data['description'] ?? $check_data['story'] ?? '';
		
		$this->log_info( 'Post info', array( 'type' => $post_type, 'caption' => substr( $post_caption, 0, 50 ) ) );
		
		// Generate AI reply
		$ai_reply = '';
		if ( function_exists( 'bizgpt_router_comment_flow' ) ) {
			$ai_reply = bizgpt_router_comment_flow( $message, $post_caption, $page_id, $from_id, $from_name );
		}
		
		if ( empty( $ai_reply ) ) {
			$ai_reply = 'Cảm ơn bạn đã để lại bình luận! Chúng tôi sẽ hỗ trợ bạn ngay.';
		}
		
		$this->log_info( 'AI reply', array( 'reply' => substr( $ai_reply, 0, 100 ) ) );
		
		// Send comment reply
		if ( function_exists( 'fb_messenger_reply_comment' ) ) {
			fb_messenger_reply_comment( $comment_id, $ai_reply, $access_token );
		}
		
		// Send notification to admin via Zalo/Telegram
		$notification = 'Khách đã nhắn bình luận: ' . $message . "\n\n" . 'AI đã trả lời: ' . $ai_reply;
		$blog_domain = $_SERVER['HTTP_HOST'] ?? '';
		
		if ( function_exists( 'send_notice_to_zalo_admin' ) ) {
			send_notice_to_zalo_admin( $notification, $from_id, $from_name, $blog_domain, $post_type . ' Comment' );
		}
		
		// Log comment and AI reply
		if ( function_exists( 'bizgpt_log_comment_ai' ) ) {
			bizgpt_log_comment_ai( array(
				'page_id'     => $page_id,
				'post_id'     => $post_id,
				'post_type'   => $post_type,
				'comment_id'  => $value['comment_id'] ?? '',
				'parent_id'   => null,
				'sender_id'   => $from_id,
				'sender_name' => $from_name,
				'message'     => $message,
				'ai_reply'    => $ai_reply,
				'client_id'   => $_SERVER['HTTP_HOST'] ?? null,
			) );
		}
		
		// Fire workflow trigger for automation
		$bot_id = $this->get_bot_id_by_page( $page_id );
		$trigger_data = array(
			'bot_id'     => $bot_id,
			'comment_id' => $value['comment_id'] ?? '',
			'post_id'    => $post_id,
			'user_id'    => $from_id,
			'user_name'  => $from_name,
			'message'    => $message,
			'page_id'    => $page_id,
			'post_type'  => $post_type,
			'ai_reply'   => $ai_reply,
		);
		do_action( 'waic_twf_process_flow', 'bizcity_facebook_comment_received', $trigger_data );
		$this->log_info( 'Fired waic_twf_process_flow trigger: bizcity_facebook_comment_received' );
		
		$this->log_info( '=== HANDLE COMMENT END ===' );
	}
	
	// ==========================================
	// PUBLIC DELEGATES (for Central Webhook Router)
	// ==========================================
	
	/**
	 * Public method for central webhook to delegate messaging events.
	 */
	public function handle_webhook_entry_messaging( $page_id, $messaging ) {
		$this->handle_messenger_message( $page_id, $messaging );
	}
	
	/**
	 * Public method for central webhook to delegate feed/change events.
	 */
	public function handle_webhook_entry_change( $page_id, $change ) {
		$this->handle_facebook_feed_event( $page_id, $change );
	}
	
	// ==========================================
	// HELPER METHODS
	// ==========================================
	
	/**
	 * Get bot ID by page_id
	 */
	private function get_bot_id_by_page( $page_id ) {
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return 0;
		}
		
		$db = BizCity_Facebook_Bot_Database::instance();
		$bots = $db->get_active_bots();
		
		// Try to match by page_id
		foreach ( $bots as $bot ) {
			if ( $bot->page_id === $page_id ) {
				return $bot->id;
			}
		}
		
		// Fallback to first active bot
		if ( ! empty( $bots ) ) {
			return $bots[0]->id;
		}
		
		return 0;
	}
	
	// ==========================================
	// LOGGING METHODS
	// ==========================================
	
	private function log_request( $data ) {
		$this->write_log( 'request', $data );
	}
	
	private function log_error( $message, $data = null ) {
		$log = array( 'message' => $message );
		if ( $data !== null ) $log['data'] = $data;
		$this->write_log( 'error', $log );
	}
	
	private function log_info( $message, $data = null ) {
		$log = array( 'message' => $message );
		if ( $data !== null ) $log['data'] = $data;
		$this->write_log( 'info', $log );
	}
	
	private function write_log( $type, $data ) {
		$log_dir = WP_CONTENT_DIR . '/mu-plugins/logs';
		
		if ( ! file_exists( $log_dir ) ) {
			@mkdir( $log_dir, 0755, true );
		}
		
		$date_str = gmdate( 'Y-m-d' );
		$time_str = gmdate( 'H:i:s' );
		$blog_id = get_current_blog_id();
		
		$log_file = $log_dir . "/fbhook-{$date_str}.log";
		
		$log_entry = array(
			'time'    => $time_str,
			'blog_id' => $blog_id,
			'type'    => $type,
			'data'    => $data,
		);
		
		@file_put_contents( 
			$log_file, 
			json_encode( $log_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n", 
			FILE_APPEND | LOCK_EX 
		);
	}
}

// Initialize
BizCity_Facebook_Bot_Webhook_Handler::instance();
