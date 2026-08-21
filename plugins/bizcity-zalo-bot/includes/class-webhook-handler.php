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

		// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — fail closed: inbound without sender identity must not continue into automation owner chain.
		if ( $user_id === '' ) {
			$this->log_zalohook_error( 'Missing message.from.id in webhook payload', $data );
			return false;
		}
		
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
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — route replies by conversation target, not sender identity.
		$identity_user_id     = (string) $user_id;
		$provider_chat_id     = $chat_id !== '' ? (string) $chat_id : $identity_user_id;
		$provider_chat_type   = isset( $message['chat']['chat_type'] ) ? strtoupper( (string) $message['chat']['chat_type'] ) : 'PRIVATE';
		$chat_kind            = $provider_chat_type === 'GROUP' ? 'group' : 'private';
		$conversation_chat_id = 'zalobot_' . $bot->id . '_' . ( $chat_kind === 'group' ? 'group_' : 'private_' ) . $provider_chat_id;
		$bot_chat_id          = $conversation_chat_id;
		$client_id            = $conversation_chat_id;
		$platform             = 'zalo_bot';
		
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
				$mention_detected     = $this->zalobot_text_mentions_bot( $text, $bot );
				$reply_to_bot_message = $this->zalobot_message_replies_to_bot( $message );
				$message_text_clean   = $mention_detected ? $this->zalobot_strip_bot_mention( $text, $bot ) : $text;
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'provider_chat_type' => $provider_chat_type,
					'chat_kind'       => $chat_kind,
					'sender_user_id'  => $identity_user_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => $message_text_clean,
					'raw_text'        => $text,
					'message_text_clean' => $message_text_clean,
					'mention_detected' => $mention_detected,
					'reply_to_bot_message' => $reply_to_bot_message,
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
					'twf_text'        => $message_text_clean,
					'twf_image_url'   => '',
					'twf_file_url'    => '',
				);
				
				// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — new Bot Platform payloads must also fire the canonical ZaloBot action so command router /link can bind users without duplicating the workflow path.
				do_action( 'bizcity_zalo_message_received', array_merge( $trigger, array(
					'platform'       => 'ZALO_BOT',
					'code'           => 'zalo_bot',
					'account_id'     => (string) ( $bot ? $bot->id : '' ),
					'account_name'   => $bot ? (string) $bot->bot_name : '',
					'from_user_id'   => $identity_user_id,
					'from_user_name' => $display_name,
					'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
					'message_text'   => $message_text_clean,
				) ) );
				
				// Fire workflow trigger (prefer gateway if available)
				// [2026-08-09 Johnny Chu] R-CH-UNI — raw fallback dispatch is no longer needed; the canonical Zone 2 envelope and automation intake already handled this event.
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				}
				
				$this->log_zalohook_info( 'Text message processed', array(
					'user_id'  => $user_id,
					'chat_id'  => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'chat_kind' => $chat_kind,
					'text'     => $text,
				) );
				break;
				
			case 'message.image.received':
				$photo_url = isset( $message['photo_url'] ) ? $message['photo_url'] : '';
				$caption = isset( $message['caption'] ) ? $message['caption'] : '';
				$mention_detected     = $this->zalobot_text_mentions_bot( $caption, $bot );
				$reply_to_bot_message = $this->zalobot_message_replies_to_bot( $message );
				$message_text_clean   = $mention_detected ? $this->zalobot_strip_bot_mention( $caption, $bot ) : $caption;
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'provider_chat_type' => $provider_chat_type,
					'chat_kind'       => $chat_kind,
					'sender_user_id'  => $identity_user_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => $message_text_clean,
					'raw_text'        => $caption,
					'message_text_clean' => $message_text_clean,
					'mention_detected' => $mention_detected,
					'reply_to_bot_message' => $reply_to_bot_message,
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
					'twf_text'        => $message_text_clean,
					'twf_image_url'   => $photo_url,
					'twf_file_url'    => '',
				);
				
				// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — keep image/caption payloads on the same canonical ZaloBot listener bus as text messages without duplicating the workflow path.
				do_action( 'bizcity_zalo_message_received', array_merge( $trigger, array(
					'platform'       => 'ZALO_BOT',
					'code'           => 'zalo_bot',
					'account_id'     => (string) ( $bot ? $bot->id : '' ),
					'account_name'   => $bot ? (string) $bot->bot_name : '',
					'from_user_id'   => $identity_user_id,
					'from_user_name' => $display_name,
					'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
					'message_text'   => $message_text_clean,
				) ) );
				
				// Fire workflow trigger (prefer gateway if available)
				// [2026-08-19 Johnny Chu] R-CH-UNI - canonical gateway dispatch is the only active workflow path.
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				}
				
				$this->log_zalohook_info( 'Image message processed', array(
					'user_id'   => $user_id,
					'chat_id'   => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'chat_kind' => $chat_kind,
					'photo_url' => $photo_url,
				) );
				break;
				
			// [2026-07-24 Johnny Chu] PHASE-0.46 W1 — HOTFIX: message.file.received
			// previously fell through to `default:` ("Unknown event type") and
			// produced ZERO downstream action — uploaded PDF/Word/Excel/MD files
			// never reached automation, command router, or Guru AI. Mirrors the
			// message.image.received case above. Field names (file_url/file_name)
			// are inferred from the existing (previously-unused) extract_file_url()/
			// extract_file_name() helper naming and the photo_url convention used
			// by message.image.received; confirm against a live payload sample and
			// adjust the isset() fallbacks below if the real Bot API uses different
			// keys (e.g. document_url/document_name).
			case 'message.file.received':
				$file_url  = isset( $message['file_url'] ) ? $message['file_url'] : ( isset( $message['document_url'] ) ? $message['document_url'] : '' );
				$file_name = isset( $message['file_name'] ) ? $message['file_name'] : ( isset( $message['document_name'] ) ? $message['document_name'] : '' );
				$caption   = isset( $message['caption'] ) ? $message['caption'] : '';
				$mention_detected     = $this->zalobot_text_mentions_bot( $caption, $bot );
				$reply_to_bot_message = $this->zalobot_message_replies_to_bot( $message );
				$message_text_clean   = $mention_detected ? $this->zalobot_strip_bot_mention( $caption, $bot ) : $caption;
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'provider_chat_type' => $provider_chat_type,
					'chat_kind'       => $chat_kind,
					'sender_user_id'  => $identity_user_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => $message_text_clean,
					'raw_text'        => $caption,
					'message_text_clean' => $message_text_clean,
					'mention_detected' => $mention_detected,
					'reply_to_bot_message' => $reply_to_bot_message,
					'display_name'    => $display_name,
					'attachment_type'  => 'file',
					'attachment_url'   => $file_url,
					'file_url'         => $file_url,
					'file_name'        => $file_name,
					'bot_id'          => $bot ? $bot->id : '',
					'bot_name'        => $bot ? $bot->bot_name : '',
					'source_blog_id'  => $source_blog_id,
					'raw'             => $data,
					// Backward-compat: twf_ prefix fields required by workflow actions
					'twf_platform'    => $platform,
					'twf_client_id'   => $client_id,
					'twf_chat_id'     => $bot_chat_id,
					'twf_text'        => $message_text_clean,
					'twf_image_url'   => '',
					'twf_file_url'    => $file_url,
				);
				
				do_action( 'bizcity_zalo_message_received', array_merge( $trigger, array(
					'platform'       => 'ZALO_BOT',
					'code'           => 'zalo_bot',
					'account_id'     => (string) ( $bot ? $bot->id : '' ),
					'account_name'   => $bot ? (string) $bot->bot_name : '',
					'from_user_id'   => $identity_user_id,
					'from_user_name' => $display_name,
					'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
					'message_text'   => $message_text_clean,
				) ) );
				
				// Fire workflow trigger (prefer gateway if available)
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				}
				
				$this->log_zalohook_info( 'File message processed', array(
					'user_id'   => $user_id,
					'chat_id'   => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'chat_kind' => $chat_kind,
					'file_url'  => $file_url,
					'file_name' => $file_name,
				) );
				break;
				
			// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.1 — real payload confirmed
			// from live bizcity-channel-logs/zalo_bot/2026-07-24.jsonl (log_row_id=24):
			//   { event_name:"message.voice.received", message:{ voice_url, chat:{chat_type,id},
			//     message_id, message_type:"CHAT_VOICE", from:{id,is_bot,display_name} } }
			// NOTE: unlike message.image.received, there is NO caption field on voice
			// messages — confirmed absent in the live payload. Do not invent one.
			case 'message.voice.received':
				$voice_url = isset( $message['voice_url'] ) ? $message['voice_url'] : '';
				
				$trigger = array(
					'platform'        => $platform,
					'client_id'       => $client_id,
					'chat_id'         => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'provider_chat_type' => $provider_chat_type,
					'chat_kind'       => $chat_kind,
					'sender_user_id'  => $identity_user_id,
					'user_id'         => $user_id,
					'wp_user_id'      => $wp_user_id,
					'message_id'      => $message_id,
					'text'            => '',
					'raw_text'        => '',
					'message_text_clean' => '',
					'mention_detected' => false,
					'reply_to_bot_message' => false,
					'display_name'    => $display_name,
					'attachment_type'  => 'audio',
					'attachment_url'   => $voice_url,
					'voice_url'        => $voice_url,
					'bot_id'          => $bot ? $bot->id : '',
					'bot_name'        => $bot ? $bot->bot_name : '',
					'source_blog_id'  => $source_blog_id,
					'raw'             => $data,
					// Backward-compat: twf_ prefix fields required by workflow actions
					'twf_platform'    => $platform,
					'twf_client_id'   => $client_id,
					'twf_chat_id'     => $bot_chat_id,
					'twf_text'        => '',
					'twf_image_url'   => '',
					'twf_file_url'    => '',
				);
				
				do_action( 'bizcity_zalo_message_received', array_merge( $trigger, array(
					'platform'       => 'ZALO_BOT',
					'code'           => 'zalo_bot',
					'account_id'     => (string) ( $bot ? $bot->id : '' ),
					'account_name'   => $bot ? (string) $bot->bot_name : '',
					'from_user_id'   => $identity_user_id,
					'from_user_name' => $display_name,
					'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
					'message_text'   => '',
				) ) );
				
				// Fire workflow trigger (prefer gateway if available)
				if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
					bizcity_gateway_fire_trigger( $trigger, $data );
				}
				
				$this->log_zalohook_info( 'Voice message processed', array(
					'user_id'   => $user_id,
					'chat_id'   => $bot_chat_id,
					'conversation_chat_id' => $conversation_chat_id,
					'provider_chat_id' => $provider_chat_id,
					'chat_kind' => $chat_kind,
					'voice_url' => $voice_url,
				) );
				break;
				
			default:
				// [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — Bot Platform can emit
				// `message.unsupported.received` for new/rolling attachment shapes.
				// Fail-open by mapping any detectable media URL into the canonical
				// trigger envelope so pending media queues + @ghichu capture still work.
				$fallback = $this->build_unknown_message_trigger_payload(
					$message,
					$event_name,
					$platform,
					$client_id,
					$bot_chat_id,
					$conversation_chat_id,
					$provider_chat_id,
					$provider_chat_type,
					$chat_kind,
					$identity_user_id,
					$user_id,
					$wp_user_id,
					$message_id,
					$display_name,
					$bot,
					$source_blog_id,
					$data
				);
				if ( is_array( $fallback ) && ! empty( $fallback['attachment_url'] ) ) {
					do_action( 'bizcity_zalo_message_received', array_merge( $fallback, array(
						'platform'       => 'ZALO_BOT',
						'code'           => 'zalo_bot',
						'account_id'     => (string) ( $bot ? $bot->id : '' ),
						'account_name'   => $bot ? (string) $bot->bot_name : '',
						'from_user_id'   => $identity_user_id,
						'from_user_name' => $display_name,
						'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
						'message_text'   => (string) ( $fallback['message_text_clean'] ?? '' ),
					) ) );

					if ( function_exists( 'bizcity_gateway_fire_trigger' ) ) {
						bizcity_gateway_fire_trigger( $fallback, $data );
					}

					$this->log_zalohook_info( 'Unknown message event fallback processed', array(
						'event_name'      => $event_name,
						'attachment_type' => (string) ( $fallback['attachment_type'] ?? '' ),
						'attachment_url'  => (string) ( $fallback['attachment_url'] ?? '' ),
						'chat_id'         => $bot_chat_id,
						'provider_chat_id'=> $provider_chat_id,
						'message_id'      => $message_id,
					) );
					break;
				}

				// [2026-07-26 Johnny Chu] PHASE-0.46 W5 HOTFIX — when Zalo emits
				// `message.unsupported.received` WITHOUT any media URL (common for
				// desktop/rolling file payloads), still emit a canonical internal
				// event so the notebook listener can proactively reply "chưa lấy
				// được file" instead of failing silently.
				if ( $event_name === 'message.unsupported.received' ) {
					$unsupported = array(
						'platform'        => $platform,
						'client_id'       => $client_id,
						'chat_id'         => $bot_chat_id,
						'conversation_chat_id' => $conversation_chat_id,
						'provider_chat_id' => $provider_chat_id,
						'provider_chat_type' => $provider_chat_type,
						'chat_kind'       => $chat_kind,
						'sender_user_id'  => $identity_user_id,
						'user_id'         => $user_id,
						'wp_user_id'      => $wp_user_id,
						'message_id'      => $message_id,
						'text'            => '',
						'raw_text'        => '',
						'message_text_clean' => '',
						'mention_detected' => false,
						'reply_to_bot_message' => false,
						'display_name'    => $display_name,
						'attachment_type'  => 'unsupported',
						'attachment_url'   => '',
						'unsupported_event' => $event_name,
						'bot_id'          => $bot ? $bot->id : '',
						'bot_name'        => $bot ? $bot->bot_name : '',
						'source_blog_id'  => $source_blog_id,
						'raw'             => $data,
						'twf_platform'    => $platform,
						'twf_client_id'   => $client_id,
						'twf_chat_id'     => $bot_chat_id,
						'twf_text'        => '',
						'twf_image_url'   => '',
						'twf_file_url'    => '',
					);

					do_action( 'bizcity_zalo_message_received', array_merge( $unsupported, array(
						'platform'       => 'ZALO_BOT',
						'code'           => 'zalo_bot',
						'account_id'     => (string) ( $bot ? $bot->id : '' ),
						'account_name'   => $bot ? (string) $bot->bot_name : '',
						'from_user_id'   => $identity_user_id,
						'from_user_name' => $display_name,
						'conversation_id'=> (string) ( $bot ? $bot->id : '' ),
						'message_text'   => '',
					) ) );

					// [2026-07-26 Johnny Chu] PHASE-0.46 W5 HOTFIX — fail-open UX:
					// if listener chain is skipped for any reason, still send one
					// direct guidance reply for unsupported file payloads.
					$this->maybe_send_unsupported_file_guidance( $bot, $provider_chat_id, $message_id );

					$this->log_zalohook_info( 'Unsupported message emitted to internal listener bus', array(
						'event_name'       => $event_name,
						'chat_id'          => $bot_chat_id,
						'provider_chat_id' => $provider_chat_id,
						'message_id'       => $message_id,
					) );
					break;
				}

				$this->log_zalohook_info( 'Unknown event type: ' . $event_name, $data );
				break;
		}
		
		// Fire generic action
		do_action( 'bizcity_zalo_bot_webhook_event', $bot, $event_name, $data );
		
		return true;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — normalize unknown Bot Platform
	 * message events that still carry media URLs (e.g. message.unsupported.received)
	 * into the canonical trigger envelope so pending-media and notebook capture
	 * pipelines keep working.
	 */
	private function build_unknown_message_trigger_payload(
		array $message,
		$event_name,
		$platform,
		$client_id,
		$bot_chat_id,
		$conversation_chat_id,
		$provider_chat_id,
		$provider_chat_type,
		$chat_kind,
		$identity_user_id,
		$user_id,
		$wp_user_id,
		$message_id,
		$display_name,
		$bot,
		$source_blog_id,
		array $raw_data
	) {
		$attachment_type = '';
		$attachment_url  = '';
		$file_name       = '';
		$voice_url       = '';

		if ( ! empty( $message['photo_url'] ) ) {
			$attachment_type = 'image';
			$attachment_url  = (string) $message['photo_url'];
		} elseif ( ! empty( $message['file_url'] ) || ! empty( $message['document_url'] ) ) {
			$attachment_type = 'file';
			$attachment_url  = (string) ( $message['file_url'] ?? $message['document_url'] );
			$file_name       = sanitize_file_name( (string) ( $message['file_name'] ?? $message['document_name'] ?? '' ) );
		} elseif ( ! empty( $message['voice_url'] ) ) {
			$attachment_type = 'audio';
			$attachment_url  = (string) $message['voice_url'];
			$voice_url       = (string) $message['voice_url'];
		} else {
			// Legacy OA-style payload fallback (`message_attachments[].payload.url`).
			$image_fallback = (string) $this->extract_image_url( $raw_data );
			$file_fallback  = (string) $this->extract_file_url( $raw_data );
			if ( $image_fallback !== '' ) {
				$attachment_type = 'image';
				$attachment_url  = $image_fallback;
			} elseif ( $file_fallback !== '' ) {
				$attachment_type = 'file';
				$attachment_url  = $file_fallback;
				$file_name       = sanitize_file_name( (string) $this->extract_file_name( $raw_data ) );
			}
		}

		$attachment_url = trim( $attachment_url );
		if ( $attachment_url === '' ) {
			return array();
		}

		$text                = (string) ( $message['caption'] ?? $message['text'] ?? '' );
		$mention_detected    = $this->zalobot_text_mentions_bot( $text, $bot );
		$reply_to_bot_message = $this->zalobot_message_replies_to_bot( $message );
		$message_text_clean  = $mention_detected ? $this->zalobot_strip_bot_mention( $text, $bot ) : $text;

		$payload = array(
			'platform'            => $platform,
			'client_id'           => $client_id,
			'chat_id'             => $bot_chat_id,
			'conversation_chat_id'=> $conversation_chat_id,
			'provider_chat_id'    => $provider_chat_id,
			'provider_chat_type'  => $provider_chat_type,
			'chat_kind'           => $chat_kind,
			'sender_user_id'      => $identity_user_id,
			'user_id'             => $user_id,
			'wp_user_id'          => $wp_user_id,
			'message_id'          => $message_id,
			'text'                => $message_text_clean,
			'raw_text'            => $text,
			'message_text_clean'  => $message_text_clean,
			'mention_detected'    => $mention_detected,
			'reply_to_bot_message'=> $reply_to_bot_message,
			'display_name'        => $display_name,
			'attachment_type'     => $attachment_type,
			'attachment_url'      => $attachment_url,
			'bot_id'              => $bot ? $bot->id : '',
			'bot_name'            => $bot ? $bot->bot_name : '',
			'source_blog_id'      => $source_blog_id,
			'raw'                 => $raw_data,
			'twf_platform'        => $platform,
			'twf_client_id'       => $client_id,
			'twf_chat_id'         => $bot_chat_id,
			'twf_text'            => $message_text_clean,
			'twf_image_url'       => $attachment_type === 'image' ? $attachment_url : '',
			'twf_file_url'        => $attachment_type === 'file' ? $attachment_url : '',
			'event_name'          => (string) $event_name,
		);

		if ( $attachment_type === 'image' ) {
			$payload['image_url'] = $attachment_url;
		}
		if ( $attachment_type === 'file' ) {
			$payload['file_url']  = $attachment_url;
			$payload['file_name'] = $file_name;
		}
		if ( $attachment_type === 'audio' ) {
			$payload['voice_url'] = $voice_url !== '' ? $voice_url : $attachment_url;
		}

		return $payload;
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

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 HOTFIX — direct webhook-level
	 * fallback reply for unsupported payloads with no media URL.
	 */
	private function maybe_send_unsupported_file_guidance( $bot, $provider_chat_id, $message_id ) {
		$bot_id   = is_object( $bot ) && isset( $bot->id ) ? (int) $bot->id : 0;
		$chat_id  = (string) $provider_chat_id;
		$msg_id   = (string) $message_id;

		if ( $bot_id <= 0 || $chat_id === '' || $msg_id === '' ) {
			return;
		}

		// Shared key with notebook listener to prevent duplicate notices.
		$key = 'bizcity_nb_unsupported_notice_' . md5( $bot_id . '|' . $msg_id );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );

		if ( ! function_exists( 'bizcity_get_zalo_bot_api' ) ) {
			$this->log_zalohook_info( 'Unsupported guidance skipped: API factory missing', array(
				'bot_id'      => $bot_id,
				'chat_id'     => $chat_id,
				'message_id'  => $msg_id,
			) );
			return;
		}

		$api = bizcity_get_zalo_bot_api( $bot_id );
		if ( ! $api || ! method_exists( $api, 'send_message' ) ) {
			$this->log_zalohook_info( 'Unsupported guidance skipped: API unavailable', array(
				'bot_id'      => $bot_id,
				'chat_id'     => $chat_id,
				'message_id'  => $msg_id,
			) );
			return;
		}

		$result = $api->send_message(
			$chat_id,
			"⚠️ Em nhận được file nhưng Zalo chưa cung cấp link tải hợp lệ (message.unsupported.received), nên chưa thể lưu vào hàng đợi học.\nSếp thử gửi lại dưới dạng ảnh/PDF/ghi âm hoặc gửi lại file trực tiếp từ điện thoại, rồi nhắn @ghichu để em lưu tiếp nhé."
		);
		if ( is_wp_error( $result ) ) {
			$this->log_zalohook_error( 'Unsupported guidance send failed', array(
				'bot_id'      => $bot_id,
				'chat_id'     => $chat_id,
				'message_id'  => $msg_id,
				'error_code'  => $result->get_error_code(),
				'error_msg'   => $result->get_error_message(),
			) );
			if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
				$db = BizCity_Zalo_Bot_Database::instance();
				$db->log_event( $bot_id, 'unsupported.guidance.failed', array(
					'chat_id'    => $chat_id,
					'message_id' => $msg_id,
					'error_code' => $result->get_error_code(),
					'error_msg'  => $result->get_error_message(),
				), $chat_id, $msg_id, '', '' );
			}
			return;
		}

		$this->log_zalohook_info( 'Unsupported guidance sent to user', array(
			'bot_id'      => $bot_id,
			'chat_id'     => $chat_id,
			'message_id'  => $msg_id,
		) );
		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			$db = BizCity_Zalo_Bot_Database::instance();
			$db->log_event( $bot_id, 'unsupported.guidance.sent', array(
				'chat_id'    => $chat_id,
				'message_id' => $msg_id,
			), $chat_id, $msg_id, '', '' );
		}
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

	private function zalobot_text_mentions_bot( $text, $bot ) {
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — detect group mention before selector matching.
		$text = (string) $text;
		if ( strpos( $text, '@' ) === false ) { return false; }
		$bot_name = is_object( $bot ) && isset( $bot->bot_name ) ? trim( (string) $bot->bot_name ) : '';
		if ( $bot_name !== '' && mb_stripos( $text, '@' . $bot_name, 0, 'UTF-8' ) !== false ) { return true; }
		return preg_match( '/@\s*bot\b/iu', $text ) === 1;
	}

	private function zalobot_strip_bot_mention( $text, $bot ) {
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — remove bot mention from prompt text but keep raw_text for audit.
		$text = (string) $text;
		$bot_name = is_object( $bot ) && isset( $bot->bot_name ) ? trim( (string) $bot->bot_name ) : '';
		if ( $bot_name !== '' ) {
			$text = preg_replace( '/@\s*' . preg_quote( $bot_name, '/' ) . '\b\s*/iu', '', $text );
		}
		$text = preg_replace( '/@\s*bot\s+[^\s]+\s*/iu', '', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) $text );
	}

	private function zalobot_message_replies_to_bot( $message ) {
		// [2026-07-21 Johnny Chu] PHASE-ZALOBOT-GROUP W6 — Bot Platform only delivers group quote/reply events to the bot.
		if ( ! is_array( $message ) ) { return false; }
		return ! empty( $message['reply_to_message'] ) || ! empty( $message['quoted_message'] ) || ! empty( $message['quote'] );
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
		// [2026-08-20 Johnny Chu] CODEC-CORE — preserve Zalo webhook XOR/Base64 decoding through shared primitives.
		$encrypted_data = BizCity_Codec::base64_decode( $encrypted_payload, false );
		if ( $encrypted_data === false ) {
			error_log( '[ZaloHook] Base64 decode failed' );
			return false;
		}
		
		// Simple XOR decryption (you can implement AES here if needed)
		$decrypted = BizCity_Codec::xor_bytes( $encrypted_data, $key );
		
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
