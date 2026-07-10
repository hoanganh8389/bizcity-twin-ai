<?php
/**
 * Webhook Handler
 * Processes incoming webhooks from Zalo Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_Webhook_Handler {
	
	private static $instance = null;
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( $this, 'handle_webhook' ) );
	}
	
	/**
	 * Add rewrite rules for webhook endpoint
	 */
	public function add_rewrite_rules() {
		// Zalo Bot webhook endpoint: /zalohook/
		add_rewrite_rule(
			'^zalohook/?$',
			'index.php?zalohook=1',
			'top'
		);
		
		add_filter( 'query_vars', function( $vars ) {
			$vars[] = 'zalohook';
			$vars[] = 'zalohook_test';
			return $vars;
		} );
	}
	
	/**
	 * Handle incoming webhook
	 */
	public function handle_webhook() {
		// Handle test endpoint  
		if ( get_query_var( 'zalohook_test' ) ) {
			wp_send_json_success( array( 
				'message' => 'Test endpoint working',
				'method' => $_SERVER['REQUEST_METHOD'],
				'timestamp' => current_time( 'mysql' ),
				'input_length' => strlen( file_get_contents( 'php://input' ) ),
				'json_test' => json_decode( file_get_contents( 'php://input' ), true )
			) );
			exit;
		}
		
		// Handle zalohook endpoint
		if ( get_query_var( 'zalohook' ) ) {
			$this->handle_zalohook();
			return;
		}
	}
	

	
	/**
	 * Handle new Zalo Bot webhook endpoint
	 */
	private function handle_zalohook() {
		$raw_data = $this->get_cached_raw_input();

		$data = array();

		if ( $raw_data !== '' ) {
			$decoded = json_decode( $raw_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				$this->log_zalohook_error(
					'JSON decode error: ' . json_last_error_msg() . '. Raw data length: ' . strlen( $raw_data ),
					array(
						'method'       => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
						'content_type' => isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '',
					)
				);
				wp_send_json_error( array( 'message' => 'JSON decode error: ' . json_last_error_msg() ), 400 );
				exit;
			}
			$data = $decoded;
		} elseif ( isset( $_POST['data'] ) && is_string( $_POST['data'] ) && $_POST['data'] !== '' ) {
			$decoded = json_decode( wp_unslash( $_POST['data'] ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				$this->log_zalohook_error( 'Invalid form payload in $_POST[data]' );
				wp_send_json_error( array( 'message' => 'Invalid form payload' ), 400 );
				exit;
			}
			$data = $decoded;
		} elseif ( isset( $_POST['update'] ) && is_string( $_POST['update'] ) && $_POST['update'] !== '' ) {
			$decoded = json_decode( wp_unslash( $_POST['update'] ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				$this->log_zalohook_error( 'Invalid form payload in $_POST[update]' );
				wp_send_json_error( array( 'message' => 'Invalid form payload' ), 400 );
				exit;
			}
			$data = $decoded;
		} elseif ( ! empty( $_POST ) && ( isset( $_POST['event_name'] ) || isset( $_POST['message'] ) || isset( $_POST['event'] ) ) ) {
			$data = wp_unslash( $_POST );
		}

		if ( ! is_array( $data ) || empty( $data ) ) {
			// [2026-07-08 Johnny Chu] HOTFIX — do not treat empty-body webhook ping as a hard error.
			$this->log_zalohook_info( 'Webhook ping/empty payload', array(
				'method'       => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
				'content_type' => isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '',
				'raw_length'   => strlen( $raw_data ),
				'post_keys'    => array_keys( (array) $_POST ),
			) );
			wp_send_json_success( array(
				'message'       => 'Webhook ping received',
				'empty_payload' => true,
			) );
			exit;
		}

		$this->log_zalohook_request( $data );
		
		// Verify secret token from header
		$secret_token = isset( $_SERVER['HTTP_X_BOT_API_SECRET_TOKEN'] ) ? $_SERVER['HTTP_X_BOT_API_SECRET_TOKEN'] : '';

		// Try to resolve bot from secret_token BEFORE firing intake — so log row
		// has bot_id and CG Debug Logger / UI per-bot filter can find it.
		$intake_bot = null;
		if ( ! empty( $secret_token ) ) {
			global $wpdb;
			$tbl  = $wpdb->prefix . 'bizcity_zalo_bots';
			$intake_bot = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, bot_name FROM {$tbl} WHERE webhook_secret = %s LIMIT 1",
				$secret_token
			) );
		}

		// Fire intake hook (CG Debug Logger taps this for visibility before processing).
		do_action( 'bizcity_zalo_webhook_intake', $data, $secret_token, $intake_bot );

		// Check if any bot is listening
		$this->check_and_store_listener_data( $data, $secret_token );
		
		// Process the webhook data
		$this->process_zalohook_data( $data, $secret_token );
		
		wp_send_json_success( array( 'message' => 'Webhook received' ) );
		exit;
	}
	
	/**
	 * Handle old encrypted zalohook endpoint (legacy)
	 */
	private function handle_zalohook_legacy() {
		// Only accept POST requests
		/*
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			$this->log_zalohook_error( 'Method not allowed: ' . ( $_SERVER['REQUEST_METHOD'] ?? 'unknown' ) );
			status_header( 405 );
			wp_send_json_error( array( 'message' => 'Method not allowed' ) );
		}
		
		// Verify secret token if provided in headers
		$provided_secret = $_SERVER['HTTP_X_ZALO_SECRET'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
		if ( $provided_secret ) {
			$this->verify_webhook_secret( $provided_secret );
		}
		*/
		// Get raw input first
		$raw_data = $this->get_cached_raw_input();
		
		// Log incoming request
		$this->log_zalohook_request( $raw_data, $provided_secret );
		
		// Try to decode JSON
		$data = json_decode( $raw_data, true );
		
		if ( ! is_array( $data ) ) {
			$this->log_zalohook_error( 'Invalid JSON payload. Length: ' . strlen( $raw_data ) . '. Content: ' . substr( $raw_data, 0, 500 ) );
			status_header( 400 );
			wp_send_json_error( array( 
				'message' => 'Invalid JSON payload',
				'raw_length' => strlen( $raw_data )
			) );
		}
		
		// Log parsed data
		$this->log_zalohook_data( 'Parsed JSON data', $data );
		
		// Check if data is encrypted
		if ( isset( $data['encrypted'] ) && $data['encrypted'] === true && isset( $data['payload'] ) ) {
			$this->log_zalohook_info( 'Processing encrypted payload' );
			// Decrypt payload using blog_id as key
			$decrypted_data = $this->decrypt_webhook_data( $data['payload'], get_current_blog_id() );
			if ( $decrypted_data === false ) {
				$this->log_zalohook_error( 'Decryption failed for blog_id: ' . get_current_blog_id() );
				status_header( 400 );
				wp_send_json_error( array( 'message' => 'Decryption failed' ) );
			}
			$data = $decrypted_data;
			$this->log_zalohook_data( 'Decrypted data', $data );
		}
		
		// Process the webhook data
		$result = $this->process_zalohook_data( $data );
		
		// Log processing result
		$this->log_zalohook_info( 'Processing result: ' . ( $result ? 'success' : 'skipped/failed' ) );
		
		// Return success
		$response = array( 'message' => 'Zalohook processed successfully', 'processed' => $result );
		$this->log_zalohook_response( $response );
		
		// Clean up old logs periodically (1% chance per request)
		if ( wp_rand( 1, 100 ) === 1 ) {
			$this->cleanup_old_logs();
		}
		
		status_header( 200 );
		wp_send_json_success( $response );
	}
	
	/**
	 * Process zalohook data (New Zalo Bot API format)
	 */
	private function process_zalohook_data( $data ) {
		global $wpdb;
		
		// Check if this is new Zalo Bot format
		$event_name = isset( $data['event_name'] ) ? $data['event_name'] : '';
		
		if ( ! empty( $event_name ) && isset( $data['message'] ) ) {
			// New Zalo Bot format (message.text.received, message.image.received, etc.)
			return $this->process_new_zalo_format( $data );
		}
		
		// Legacy encrypted format
		$platform_type = $data['platform_type'] ?? '';
		$event = $data['event'] ?? '';
		$client_id = $data['client_id'] ?? '';
		$page_id = $data['page_id'] ?? '';
		$conversation = $data['conversation'] ?? array();
		$message = $data['message'] ?? array();
		
		$this->log_zalohook_info( "Processing event: $event, platform: $platform_type, client: $client_id, page: $page_id" );
		
		// Only process message create events
		if ( $event !== 'message.create' ) {
			$this->log_zalohook_info( "Skipping non-message event: $event" );
			return false;
		}
		
		$message_type = $conversation['last_message_type'] ?? '';
		if ( $message_type !== 'client' ) {
			$this->log_zalohook_info( "Skipping non-client message type: $message_type" );
			return false;
		}
		
		$message_id = $message['message_id'] ?? '';
		if ( empty( $message_id ) ) {
			$this->log_zalohook_error( 'Empty message_id in webhook data' );
			return false;
		}
		
		// Prevent duplicate processing
		$lock_key = 'zalohook_lock_' . md5( $message_id . $client_id );
		if ( get_transient( $lock_key ) ) {
			$this->log_zalohook_info( "Duplicate message detected, skipping: $message_id" );
			return false;
		}
		set_transient( $lock_key, true, 300 ); // 5 minute lock
		
		// Find bot by page_id or use first active bot
		$db = BizCity_Zalo_Bot_Database::instance();
		$bots = $db->get_active_bots();
		$bot = null;
		
		$this->log_zalohook_info( 'Available bots: ' . count( $bots ) );
		
		// Try to match by oa_id/page_id first
		foreach ( $bots as $b ) {
			if ( $b->oa_id === $page_id ) {
				$bot = $b;
				$this->log_zalohook_info( "Matched bot by oa_id: {$b->bot_name} (ID: {$b->id})" );
				break;
			}
		}
		
		// Fallback to first active bot
		if ( ! $bot && ! empty( $bots ) ) {
			$bot = $bots[0];
			$this->log_zalohook_info( "Using fallback bot: {$bot->bot_name} (ID: {$bot->id})" );
		}
		
		if ( ! $bot ) {
			$this->log_zalohook_error( "No active bot found for page_id: $page_id" );
			return false;
		}
		
		// Log the event (client_id, message_id, display_name = '', text = '')
		$db->log_event( $bot->id, $event, $data, $client_id, $message_id, '', '' );
		
		// Build message data for bizcity_zalo_message_received action
		$message_data = array(
			// [2026-06-07 Johnny Chu] PHASE-0.40 G0.3 R-ZONE-2 — explicit platform discriminator
			// Universal Listener bails on ZALO_BOT so admin commands stay in Zone 2.
			'platform'        => 'ZALO_BOT',
			'code'            => 'zalo_bot',
			'bot_id'          => $bot->id,
			'bot_name'        => $bot->bot_name,
			'account_id'      => $bot->id, // For compatibility with wu_zalo_message_received trigger
			'account_name'    => $bot->bot_name,
			'event_name'      => $event,
			'from_user_id'    => $client_id,
			'from_user_name'  => $data['client_name'] ?? '',
			'message_id'      => $message_id,
			'conversation_id' => $page_id,
			'message_type'    => $this->determine_message_type( $data ),
			'message_text'    => sanitize_text_field( $conversation['last_message'] ?? '' ),
			'message_time'    => current_time( 'mysql' ),
			'image_url'       => $this->extract_image_url( $data ),
			'file_url'        => $this->extract_file_url( $data ),
			'file_name'       => $this->extract_file_name( $data ),
			'raw'             => $data,
		);
		
		$this->log_zalohook_data( 'Built message data for action', $message_data );
		
		// Fire the action that wu_zalo_message_received trigger listens to
		$this->log_zalohook_info( 'Firing bizcity_zalo_message_received action' );
		do_action( 'bizcity_zalo_message_received', $message_data );
		
		return true;
	}
	
	/**
	 * Helper methods for logging
	 */
	
	/**
	 * Process new Zalo Bot API webhook format
	 */
	private function process_new_zalo_format( $data ) {
		global $wpdb;
		
		$event_name = isset( $data['event_name'] ) ? $data['event_name'] : '';
		$message = isset( $data['message'] ) ? $data['message'] : array();
		
		if ( empty( $event_name ) || empty( $message ) ) {
			$this->log_zalohook_error( 'Missing event_name or message', $data );
			return false;
		}
		
		// Extract common fields
		$user_id = isset( $message['from']['id'] ) ? $message['from']['id'] : '';
		$chat_id = isset( $message['chat']['id'] ) ? $message['chat']['id'] : '';
		$message_id = isset( $message['message_id'] ) ? $message['message_id'] : '';
		$display_name = isset( $message['from']['display_name'] ) ? $message['from']['display_name'] : '';
		
		// Verify secret token from header
		$secret_token = isset( $_SERVER['HTTP_X_BOT_API_SECRET_TOKEN'] ) ? $_SERVER['HTTP_X_BOT_API_SECRET_TOKEN'] : '';
		
		// Find bot by secret token - scan ALL blogs in multisite
		$bot = null;
		$source_blog_id = get_current_blog_id();
		
		if ( ! empty( $secret_token ) ) {
			// First try current blog
			$table_bots = $wpdb->prefix . 'bizcity_zalo_bots';
			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_bots}'" ) === $table_bots;
			
			if ( $table_exists ) {
				$bots = $wpdb->get_results( "SELECT * FROM $table_bots WHERE status = 'active'" );
				foreach ( $bots as $b ) {
					if ( ! empty( $b->webhook_secret ) && $b->webhook_secret === $secret_token ) {
						$bot = $b;
						$source_blog_id = get_current_blog_id();
						$this->log_zalohook_info( "Matched bot by secret in blog #{$source_blog_id}: {$b->bot_name} (ID: {$b->id})" );
						break;
					}
				}
			}
			
			// If not found in current blog, scan all blogs (multisite)
			if ( ! $bot && is_multisite() ) {
				$blogs = $wpdb->get_col(
					"SELECT blog_id FROM {$wpdb->blogs} WHERE archived = 0 AND deleted = 0 ORDER BY blog_id DESC LIMIT 100"
				);
				
				foreach ( $blogs as $blog_id ) {
					if ( (int) $blog_id === get_current_blog_id() ) {
						continue; // Already checked
					}
					
					$table_name = $wpdb->get_blog_prefix( $blog_id ) . 'bizcity_zalo_bots';
					$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name;
					
					if ( ! $table_exists ) {
						continue;
					}
					
					$bots = $wpdb->get_results( "SELECT * FROM {$table_name} WHERE status = 'active'" );
					foreach ( $bots as $b ) {
						if ( ! empty( $b->webhook_secret ) && $b->webhook_secret === $secret_token ) {
							$bot = $b;
							$source_blog_id = (int) $blog_id;
							$this->log_zalohook_info( "Matched bot by secret in blog #{$source_blog_id}: {$b->bot_name} (ID: {$b->id})" );
							break 2; // Break both loops
						}
					}
				}
			}
		}
		
		// Fallback to first active bot in current blog
		if ( ! $bot ) {
			$table_bots = $wpdb->prefix . 'bizcity_zalo_bots';
			$bot = $wpdb->get_row( "SELECT * FROM $table_bots WHERE status = 'active' LIMIT 1" );
			if ( $bot ) {
				$source_blog_id = get_current_blog_id();
				$this->log_zalohook_info( "Using fallback bot in blog #{$source_blog_id}: {$bot->bot_name} (ID: {$bot->id})" );
			}
		}
		
		if ( ! $bot ) {
			$this->log_zalohook_error( 'No active bot found' );
			return false;
		}
		
		// Prevent duplicate processing
		$lock_key = 'zalobot_lock_' . md5( $message_id . $user_id );
		if ( get_transient( $lock_key ) ) {
			$this->log_zalohook_info( "Duplicate message detected, skipping: $message_id" );
			return false;
		}
		set_transient( $lock_key, true, 300 ); // 5 minute lock
		
		// Extract text from message
		$text = isset( $message['text'] ) ? $message['text'] : '';
		
		// Use user_id as client_id (canonical identifier)
		$client_id = $user_id;
		
		// Log the event with all fields
		$db = BizCity_Zalo_Bot_Database::instance();
		$db->log_event( $bot->id, $event_name, $data, $client_id, $message_id, $display_name, $text );
		
		// Check if listener is active and store webhook data
		$listening = get_transient( 'zalobot_listening_' . $bot->id );
		if ( $listening ) {
			set_transient( 'zalobot_webhook_data_' . $bot->id, $data, 300 );
			$this->log_zalohook_info( 'Stored webhook data for listener' );
		}
		
		// Store source_blog_id for this bot (used by gateway when sending messages)
		set_transient( 'zalobot_source_blog_' . $bot->id, $source_blog_id, 3600 ); // Cache for 1 hour
		$this->log_zalohook_info( "Cached source_blog_id={$source_blog_id} for bot #{$bot->id}" );
		
		// Prepare trigger data for workflow automation
		// chat_id format: zalobot_{bot_id}_{zalo_user_id} → enables gateway send override routing
		$bot_chat_id = 'zalobot_' . $bot->id . '_' . $chat_id;
		$client_id   = $bot_chat_id;
		$platform    = 'zalo_bot';
		
		// ── Resolve WordPress user_id: per-user link (Linker) → bot assignment fallback ──
		// PHASE-0-RULE-CHANNEL-UNIFY (2026-05-30) — adapter KHÔNG được auto-send
		// reply / login link trước khi fire envelope. CTA login (nếu cần) phải
		// đặt vào MỘT workflow chuyên biệt với keyword `login`/`đăng nhập`/`bind`.
		$wp_user_id = 0;
		if ( class_exists( 'BizCity_Zalobot_User_Linker' ) && $bot && ! empty( $user_id ) ) {
			$wp_user_id = BizCity_Zalobot_User_Linker::resolve_wp_user( $user_id, (int) $bot->id );
			// (đã bỏ) maybe_send_login_link — vi phạm R-CH-UNI 1.1.
		} elseif ( function_exists( 'bizcity_zalobot_resolve_wp_user' ) && $bot ) {
			// Legacy fallback: resolve via bot assignment (bot owner)
			$wp_user_id = bizcity_zalobot_resolve_wp_user( $bot->id );
		}
		
		// Process based on event type
		switch ( $event_name ) {
			case 'message.text.received':
				$text = isset( $message['text'] ) ? $message['text'] : '';
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => $text,
					'display_name'    => $display_name,
					'attachment_type'  => 'text',
					'attachment_url'   => '',
					'bot_id'          => $bot ? $bot->id : '',
					'bot_name'        => $bot ? $bot->bot_name : '',
					'source_blog_id'  => $source_blog_id,
					'raw'             => $data,
					// Backward-compat: twf_ prefix fields required by workflow actions
					'twf_platform'    => $platform,
					'twf_client_id'   => $client_id,
					'twf_chat_id'     => $bot_chat_id,
					'twf_text'        => $text,
					'twf_image_url'   => '',
					'twf_file_url'    => '',
				);
				
				// Fire workflow trigger (prefer gateway if available)
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				} else {
					do_action( 'waic_twf_process_flow', $trigger, $data );
				}
				
				$this->log_zalohook_info( 'Text message processed', array(
					'user_id'  => $user_id,
					'chat_id'  => $bot_chat_id,
					'text'     => $text,
				) );
				break;
				
			case 'message.image.received':
				$photo_url = isset( $message['photo_url'] ) ? $message['photo_url'] : '';
				$caption = isset( $message['caption'] ) ? $message['caption'] : '';
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => $caption,
					'display_name'    => $display_name,
					'attachment_type'  => 'image',
					'attachment_url'   => $photo_url,
					'image_url'        => $photo_url,
					'bot_id'          => $bot ? $bot->id : '',
					'bot_name'        => $bot ? $bot->bot_name : '',
					'source_blog_id'  => $source_blog_id,
					'raw'             => $data,
					// Backward-compat: twf_ prefix fields required by workflow actions
					'twf_platform'    => $platform,
					'twf_client_id'   => $client_id,
					'twf_chat_id'     => $bot_chat_id,
					'twf_text'        => $caption,
					'twf_image_url'   => $photo_url,
					'twf_file_url'    => '',
				);
				
				// Fire workflow trigger (prefer gateway if available)
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				} else {
					do_action( 'waic_twf_process_flow', $trigger, $data );
				}
				
				$this->log_zalohook_info( 'Image message processed', array(
					'user_id'   => $user_id,
					'chat_id'   => $bot_chat_id,
					'photo_url' => $photo_url,
				) );
				break;
				
			default:
				$this->log_zalohook_info( 'Unknown event type: ' . $event_name, $data );
				break;
		}
		
		// Fire generic action
		do_action( 'bizcity_zalo_bot_webhook_event', $bot, $event_name, $data );
		
		return true;
	}
	
	private function log_zalohook_request( $data ) {
		$this->write_zalohook_log( 'request', $data );
	}
	
	private function log_zalohook_error( $message, $data = null ) {
		$log_data = array( 'message' => $message );
		if ( $data !== null ) {
			$log_data['data'] = $data;
		}
		$this->write_zalohook_log( 'error', $log_data );
	}
	
	private function log_zalohook_info( $message, $data = null ) {
		$log_data = array( 'message' => $message );
		if ( $data !== null ) {
			$log_data['data'] = $data;
		}
		$this->write_zalohook_log( 'info', $log_data );
	}
	
	private function log_zalohook_data( $message, $data ) {
		$log_data = array( 
			'message' => $message,
			'data' => $data 
		);
		$this->write_zalohook_log( 'data', $log_data );
	}
	
	private function log_zalohook_response( $response_data, $http_status = 200 ) {
		$log_data = array(
			'status' => $http_status,
			'response' => $response_data,
			'timestamp' => gmdate( 'c' )
		);
		$this->write_zalohook_log( 'response', $log_data );
	}
	
	private function write_zalohook_log( $type, $data ) {
		$log_dir = WP_CONTENT_DIR . '/mu-plugins/logs';
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}
		
		$date_str = gmdate( 'Y-m-d' );
		$time_str = gmdate( 'H:i:s' );
		$blog_id = get_current_blog_id();
		
		// Single log file for all zalohook events
		$log_file = $log_dir . "/zalohook-{$date_str}.log";
		
		$log_entry = array(
			'time' => $time_str,
			'blog_id' => $blog_id,
			'type' => $type,
			'data' => $data
		);
		
		file_put_contents( 
			$log_file, 
			json_encode( $log_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n", 
			FILE_APPEND 
		);
	}

	/**
	 * Clean old log files (keep last 30 days)
	 */
	private function cleanup_old_logs() {
		$log_dir = WP_CONTENT_DIR . '/mu-plugins/logs';
		if ( ! file_exists( $log_dir ) ) {
			return;
		}
		
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$files = glob( $log_dir . '/zalohook-*.log' );
		
		foreach ( $files as $file ) {
			$filename = basename( $file );
			if ( preg_match( '/zalohook-(\d{4}-\d{2}-\d{2})\.log$/', $filename, $matches ) ) {
				if ( $matches[1] < $cutoff_date ) {
					unlink( $file );
				}
			}
		}
	}
	private function determine_message_type( $data ) {
		$message = $data['message'] ?? array();
		$attachments = $message['message_attachments'] ?? array();
		
		if ( ! empty( $attachments ) ) {
			$first_attachment = $attachments[0] ?? array();
			$payload = $first_attachment['payload'] ?? array();
			$url = $payload['url'] ?? '';
			
			if ( ! empty( $url ) ) {
				// Use enhanced classification (matches twf_classify_attachment logic)
				if ( function_exists( 'twf_classify_attachment' ) ) {
					return twf_classify_attachment( $url );
				}
				
				// Fallback to basic detection
				$extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				
				$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' );
				if ( in_array( $extension, $image_exts ) ) {
					return 'image';
				}
				
				$audio_exts = array( 'aac', 'm4a', 'mp3', 'wav', 'ogg', 'oga' );
				if ( in_array( $extension, $audio_exts ) ) {
					return 'audio';
				}
				
				// Additional image checks for URLs without clear extension
				$url_lower = strtolower( $url );
				if ( strpos( $url_lower, '/gif/' ) !== false 
					|| strpos( $url_lower, '/images/' ) !== false
					|| strpos( $url_lower, '/sticker/' ) !== false
					|| strpos( $url_lower, 'stc-' ) !== false ) {
					return 'image';
				}
				
				// Check for image extension with query params: .gif?v=123
				if ( preg_match( '#\.(jpg|jpeg|png|gif|webp|bmp)(\?|$)#i', $url ) ) {
					return 'image';
				}
				
				return 'file';
			}
		}
		
		return 'text';
	}
	
	/**
	 * Extract image URL from webhook data
	 */
	private function extract_image_url( $data ) {
		$message = $data['message'] ?? array();
		$attachments = $message['message_attachments'] ?? array();
		
		if ( ! empty( $attachments ) ) {
			$first_attachment = $attachments[0] ?? array();
			$payload = $first_attachment['payload'] ?? array();
			$url = $payload['url'] ?? '';
			
			if ( ! empty( $url ) ) {
				// Use enhanced classification
				if ( function_exists( 'twf_classify_attachment' ) ) {
					$type = twf_classify_attachment( $url );
					if ( $type === 'image' ) {
						return esc_url( $url );
					}
					return '';
				}
				
				// Fallback detection
				$extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' );
				if ( in_array( $extension, $image_exts ) ) {
					return esc_url( $url );
				}
				
				// Additional checks for images without clear extension
				$url_lower = strtolower( $url );
				if ( strpos( $url_lower, '/gif/' ) !== false 
					|| strpos( $url_lower, '/images/' ) !== false
					|| strpos( $url_lower, '/sticker/' ) !== false
					|| strpos( $url_lower, 'stc-' ) !== false ) {
					return esc_url( $url );
				}
				
				// Check for image extension with query params
				if ( preg_match( '#\.(jpg|jpeg|png|gif|webp|bmp)(\?|$)#i', $url ) ) {
					return esc_url( $url );
				}
			}
		}
		
		return '';
	}
	
	/**
	 * Extract file URL from webhook data
	 */
	private function extract_file_url( $data ) {
		$message = $data['message'] ?? array();
		$attachments = $message['message_attachments'] ?? array();
		
		if ( ! empty( $attachments ) ) {
			$first_attachment = $attachments[0] ?? array();
			$payload = $first_attachment['payload'] ?? array();
			$url = $payload['url'] ?? '';
			
			if ( ! empty( $url ) ) {
				// Use enhanced classification - only return if NOT an image
				if ( function_exists( 'twf_classify_attachment' ) ) {
					$type = twf_classify_attachment( $url );
					if ( $type !== 'image' && $type !== 'unknown' ) {
						return esc_url( $url );
					}
					return '';
				}
				
				// Fallback detection
				$extension = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' );
				if ( ! in_array( $extension, $image_exts ) ) {
					// Additional check: make sure it's really not an image
					$url_lower = strtolower( $url );
					if ( strpos( $url_lower, '/gif/' ) === false 
						&& strpos( $url_lower, '/images/' ) === false
						&& strpos( $url_lower, '/sticker/' ) === false
						&& strpos( $url_lower, 'stc-' ) === false
						&& ! preg_match( '#\.(jpg|jpeg|png|gif|webp|bmp)(\?|$)#i', $url ) ) {
						return esc_url( $url );
					}
				}
			}
		}
		
		return '';
	}
	
	/**
	 * Extract file name from webhook data
	 */
	private function extract_file_name( $data ) {
		$message = $data['message'] ?? array();
		$attachments = $message['message_attachments'] ?? array();
		
		if ( ! empty( $attachments ) ) {
			$first_attachment = $attachments[0] ?? array();
			$payload = $first_attachment['payload'] ?? array();
			$url = $payload['url'] ?? '';
			
			if ( ! empty( $url ) ) {
				return sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ) );
			}
		}
		
		return '';
	}
	
	/**
	 * Decrypt webhook data using blog_id as key
	 */
	private function decrypt_webhook_data( $encrypted_payload, $blog_id ) {
		// Generate encryption key from blog_id
		$key = $this->generate_encryption_key( $blog_id );
		
		// Try to base64 decode the payload
		$encrypted_data = base64_decode( $encrypted_payload );
		if ( $encrypted_data === false ) {
			error_log( '[ZaloHook] Base64 decode failed' );
			return false;
		}
		
		// Simple XOR decryption (you can implement AES here if needed)
		$decrypted = $this->xor_decrypt( $encrypted_data, $key );
		
		// Try to decode JSON
		$result = json_decode( $decrypted, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( '[ZaloHook] JSON decode failed after decryption: ' . json_last_error_msg() );
			return false;
		}
		
		return $result;
	}
	
	/**
	 * Generate encryption key from blog_id
	 */
	private function generate_encryption_key( $blog_id ) {
		// Create a consistent key based on blog_id and a secret salt
		$salt = 'bizcity_zalo_secret_2026'; // You can make this configurable
		return hash( 'sha256', $blog_id . '_' . $salt, true );
	}
	
	/**
	 * Simple XOR encryption/decryption
	 */
	private function xor_decrypt( $data, $key ) {
		$result = '';
		$key_length = strlen( $key );
		
		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$result .= $data[$i] ^ $key[$i % $key_length];
		}
		
		return $result;
	}
	
	/**
	 * Verify webhook secret
	 */
	private function verify_webhook_secret( $provided_secret ) {
		// Get all active bots and check their secrets
		$db = BizCity_Zalo_Bot_Database::instance();
		$bots = $db->get_active_bots();
		
		$valid_secret = false;
		
		foreach ( $bots as $bot ) {
			if ( ! empty( $bot->webhook_secret ) ) {
				// Decrypt stored secret and compare
				$decrypted_secret = BizCity_Zalo_Bot_Admin_Menu::decrypt_secret( $bot->webhook_secret );
				if ( hash_equals( $decrypted_secret, $provided_secret ) ) {
					$valid_secret = true;
					break;
				}
			}
		}
		
		// Also check default blog-based secret
		$default_secret = bizcity_generate_zalo_secret_token( get_current_blog_id() );
		if ( hash_equals( $default_secret, $provided_secret ) ) {
			$valid_secret = true;
		}
		
		if ( ! $valid_secret ) {
			status_header( 401 );
			wp_send_json_error( array( 'message' => 'Invalid webhook secret' ) );
		}
	}
	
	/**
	 * Check if any bot is listening and store webhook data
	 */
	private function check_and_store_listener_data( $data, $secret_token ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'bizcity_zalo_bots';
		
		// Get all active bots
		$bots = $wpdb->get_results( "SELECT * FROM {$table_name} WHERE status = 'active'" );
		
		if ( ! $bots ) {
			$this->log_zalohook_info( "No active bots found" );
			return;
		}
		
		$this->log_zalohook_info( "Checking " . count( $bots ) . " active bots. Secret token: " . ($secret_token ? 'provided' : 'empty') );
		
		foreach ( $bots as $bot ) {
			// Check if this bot is listening
			$is_listening = get_transient( 'zalobot_listening_' . $bot->id );
			
			$this->log_zalohook_info( "Bot #{$bot->id} - Listening: " . ($is_listening ? 'YES' : 'NO') . 
									", Secret: " . ($bot->webhook_secret ? 'has secret' : 'no secret') );
			
			if ( $is_listening ) {
				// If no secret required or secret matches
				if ( ! $bot->webhook_secret || hash_equals( $bot->webhook_secret, $secret_token ) ) {
					// Store the webhook data
					$webhook_data = array(
						'event_name' => $data['event_name'] ?? '',
						'message' => $data['message'] ?? array(),
						'raw_json' => json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
						'received_at' => current_time( 'mysql' ),
						'headers' => array(
							'X-Bot-Api-Secret-Token' => $secret_token,
							'Content-Type' => isset( $_SERVER['CONTENT_TYPE'] ) ? $_SERVER['CONTENT_TYPE'] : '',
							'User-Agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '',
						),
					);
					
					set_transient( 'zalobot_webhook_data_' . $bot->id, $webhook_data, 300 );
					$this->log_zalohook_info( "✅ Stored webhook data for listening bot #{$bot->id}" );
					break; // Only store for first matching bot
				} else {
					$this->log_zalohook_info( "❌ Bot #{$bot->id} listening but secret token mismatch" );
				}
			}
		}
	}

	/**
	 * Read raw request body from shared cache when available.
	 */
	private function get_cached_raw_input() {
		// [2026-07-08 Johnny Chu] HOTFIX — reuse router-cached raw body to avoid
		// empty payload when php://input has already been consumed upstream.
		if ( isset( $GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'] ) && is_string( $GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'] ) ) {
			return $GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'];
		}

		if ( class_exists( 'BizCity_Webhook_Router' ) && method_exists( 'BizCity_Webhook_Router', 'raw_body' ) ) {
			$cached = BizCity_Webhook_Router::raw_body();
			if ( is_string( $cached ) && $cached !== '' ) {
				$GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'] = $cached;
				return $cached;
			}
		}

		$raw = file_get_contents( 'php://input' );
		if ( ! is_string( $raw ) ) {
			$raw = '';
		}
		$GLOBALS['BIZCITY_WEBHOOK_RAW_INPUT'] = $raw;
		return $raw;
	}
}
