<?php
/**
 * REST API Endpoints
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_REST_API {

	/**
	 * REST namespace: bizcity-channel/v1 (CANONICAL — đồng nhất toàn bộ channel-gateway).
	 * ❌ KHÔNG dùng 'bizcity/v1' cho bất kỳ route nào trong sub-plugin này.
	 */
	const NS = 'bizcity-channel/v1';

	private static $instance = null;
	
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
		register_rest_route( self::NS, '/zalo-bot/send-message', array(
			'methods' => 'POST',
			'callback' => array( $this, 'send_message' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
		
		// Get bot info
		register_rest_route( self::NS, '/zalo-bot/info/(?P<id>\d+)', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_bot_info' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
		
		// Get user profile
		register_rest_route( self::NS, '/zalo-bot/user/(?P<user_id>[a-zA-Z0-9]+)', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_user_profile' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		/* ── Management endpoints (manage_options) ── */

		// List all bots
		register_rest_route( self::NS, '/zalo-bot/bots', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'mgmt_list_bots' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mgmt_save_bot' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
		) );

		// Single bot CRUD
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'mgmt_save_bot' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'mgmt_delete_bot' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
		) );

		// User links (bind list)
		register_rest_route( self::NS, '/zalo-bot/user-links', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'mgmt_list_links' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mgmt_create_link' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
		) );

		register_rest_route( self::NS, '/zalo-bot/user-links/(?P<id>\d+)', array(
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'mgmt_delete_link' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
		) );

		// Set role for linked wp_user
		register_rest_route( self::NS, '/zalo-bot/user-links/(?P<id>\d+)/role', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'mgmt_set_link_role' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Set notebook (KG notebook scope) for a link — drives per-user notebook
		// routing when Zalo user chats bot (twinbrain `Notebook_Selector`).
		register_rest_route( self::NS, '/zalo-bot/user-links/(?P<id>\d+)/notebook', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'mgmt_set_link_notebook' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// [2026-07-02 Johnny Chu] HOTFIX — manual repair-tables endpoint (for post-clone recovery)
		register_rest_route( self::NS, '/zalo-bot/repair-tables', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'mgmt_repair_tables' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// List notebooks (site-scoped, read-only) for binding dropdown.
		register_rest_route( self::NS, '/zalo-bot/notebooks', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mgmt_list_notebooks' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Settings (option-backed)
		register_rest_route( self::NS, '/zalo-bot/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'mgmt_get_settings' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mgmt_save_settings' ),
				'permission_callback' => array( $this, 'check_admin' ),
			),
		) );

		// Search WP users for binding
		register_rest_route( self::NS, '/zalo-bot/wp-users', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mgmt_search_users' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		/* ── Diagnostics endpoints (admin only) ── */

		// Test ping a bot — calls Zalo getMe to verify token + returns OA info.
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/ping', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'mgmt_ping_bot' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Inspect current webhook info (Zalo getWebhookInfo).
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/webhook-info', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mgmt_get_webhook_info' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Register / re-register webhook with Zalo (POST setWebhook).
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/set-webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'mgmt_set_webhook' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Delete webhook (POST deleteWebhook).
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/webhook', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'mgmt_delete_webhook' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Setup status — runs all sanity checks (token, oa_info, webhook_info, recent log counts).
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/setup-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mgmt_setup_status' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Recent chatters — group inbound logs by zalo_user_id with link status.
		// Used by FE "Người mới nhắn" panel for quick-bind UX.
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/recent-users', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mgmt_recent_users' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Conversation history for one zalo_user_id within a bot — chronological
		// list of inbound messages + bot replies for inbox-style "check lại" UX.
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/conversation', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mgmt_conversation' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Admin-initiated outgoing message — admin gửi tin trực tiếp từ inbox
		// SPA để giám sát/trợ lý. Log lại event_name=bot.reply với meta from=admin
		// để timeline conversation hiển thị đúng phía bot bubble.
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/admin-send', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'mgmt_admin_send' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// Phase CG-Listener S2 (2026-05-30) — recipient pre-flight diagnose.
		// Trả structured evidence: webhook history, chat_id resolution, eligibility.
		// FE composer dùng để bật/tắt nút Send + hiển thị hint chính xác.
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/recipient-diagnose', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mgmt_recipient_diagnose' ),
			'permission_callback' => array( $this, 'check_admin' ),
		) );

		// [2026-06-29 Johnny Chu] PHASE-0 — Admin force-resend login link to unlinked Zalo user.
		// Deletes cooldown transient first so admin can bypass the 5-min rate limit.
		register_rest_route( self::NS, '/zalo-bot/bots/(?P<id>\d+)/resend-link', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'mgmt_resend_link' ),
			'permission_callback' => array( $this, 'check_admin' ),
			'args'                => array(
				'zalo_user_id'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'display_name'  => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	public function check_admin() {
		return current_user_can( 'manage_options' );
	}
	
	/**
	 * Permission check
	 */
	public function check_permission() {
		return current_user_can( 'edit_posts' );
	}
	
	/**
	 * Send message endpoint
	 */
	public function send_message( $request ) {
		$bot_id = $request->get_param( 'bot_id' );
		$user_id = $request->get_param( 'user_id' );
		$message = $request->get_param( 'message' );
		$type = $request->get_param( 'type' ) ?: 'text';
		
		if ( ! $bot_id || ! $user_id || ! $message ) {
			return new WP_Error( 'missing_params', 'Bot ID, User ID, and Message are required', array( 'status' => 400 ) );
		}
		
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot not found', array( 'status' => 404 ) );
		}
		
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		
		if ( $type === 'image' ) {
			$response = $api->send_image_message( $user_id, $message );
		} else {
			$response = $api->send_text_message( $user_id, $message );
		}
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		return rest_ensure_response( array(
			'success' => true,
			'data' => $response,
		) );
	}
	
	/**
	 * Get bot info
	 */
	public function get_bot_info( $request ) {
		$bot_id = $request->get_param( 'id' );
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot not found', array( 'status' => 404 ) );
		}
		
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		$oa_info = $api->get_oa_info();
		
		return rest_ensure_response( array(
			'bot' => $bot,
			'oa_info' => $oa_info,
		) );
	}
	
	/**
	 * Get user profile
	 */
	public function get_user_profile( $request ) {
		$user_id = $request->get_param( 'user_id' );
		$bot_id = $request->get_param( 'bot_id' );
		
		if ( ! $bot_id ) {
			return new WP_Error( 'missing_bot_id', 'Bot ID is required', array( 'status' => 400 ) );
		}
		
		$db = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot not found', array( 'status' => 404 ) );
		}
		
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		$profile = $api->get_user_profile( $user_id );
		
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		
		return rest_ensure_response( $profile );
	}

	/* ════════════════════════════════════════════════
	 * Management handlers (admin only)
	 * ════════════════════════════════════════════════ */

	/**
	 * [2026-07-02 Johnny Chu] HOTFIX — manual repair endpoint: force-recreate all tables.
	 * Used by admin "Tạo lại bảng" button in post-clone recovery flow.
	 */
	public function mgmt_repair_tables( $request ) {
		if ( ! class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			return new WP_Error( 'unavailable', 'BizCity_Zalo_Bot_Database class not found.', array( 'status' => 500 ) );
		}
		BizCity_Zalo_Bot_Database::activate();
		delete_option( 'bizcity_zalo_bot_db_version' );
		error_log( '[BizCity Zalo Bot] mgmt_repair_tables: tables recreated on blog ' . get_current_blog_id() . ' by user ' . get_current_user_id() );
		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Bảng dữ liệu đã được tạo lại thành công. Vui lòng tải lại trang.',
		) );
	}

	/**
	 * [2026-07-02 Johnny Chu] HOTFIX — auto-create tables when missing (clone without version reset)
	 * Returns setup_required:true so FE shows "table created, F5 to continue" banner.
	 */
	private function ensure_tables_exist() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			)
		);
		if ( $exists ) {
			return false; // all good
		}
		// Table missing — auto-create
		error_log( '[BizCity Zalo Bot] Table ' . $table . ' missing on blog ' . get_current_blog_id() . ' — running auto-install.' );
		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			BizCity_Zalo_Bot_Database::activate();
		}
		// Reset version gate so next admin_init will also re-verify
		delete_option( 'bizcity_zalo_bot_db_version' );
		return true; // caller should return setup_required response
	}

	public function mgmt_list_bots( $request ) {
		// [2026-07-02 Johnny Chu] HOTFIX — guard missing table (post-clone)
		if ( $this->ensure_tables_exist() ) {
			return rest_ensure_response( array(
				'bots'           => array(),
				'setup_required' => true,
				'message'        => 'Bảng dữ liệu Zalo Bot chưa tồn tại và đã được tự động tạo. Vui lòng tải lại trang (F5) để tiếp tục.',
			) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		$logs  = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		$rows  = $wpdb->get_results( "SELECT id, bot_name, app_id, oa_id, webhook_url, status, created_at, updated_at, CHAR_LENGTH(bot_token) AS token_len FROM $table ORDER BY id DESC" );
		if ( $rows ) {
			// Annotate with last activity timestamp from logs table so FE can
			// auto-select the bot with most recent traffic (UX: avoid landing on
			// an empty bot when admin has multiple bots configured).
			$activity = $wpdb->get_results(
				"SELECT bot_id, MAX(created_at) AS last_event_at, COUNT(*) AS event_count FROM {$logs} GROUP BY bot_id",
				OBJECT_K
			);
			foreach ( $rows as $r ) {
				$bid = (string) $r->id;
				$r->last_event_at = isset( $activity[ $bid ] ) ? $activity[ $bid ]->last_event_at : null;
				$r->event_count   = isset( $activity[ $bid ] ) ? (int) $activity[ $bid ]->event_count : 0;
			}
		}
		return rest_ensure_response( array( 'bots' => $rows ?: array() ) );
	}

	public function mgmt_save_bot( $request ) {
		// [2026-07-02 Johnny Chu] HOTFIX — guard missing table (post-clone)
		if ( $this->ensure_tables_exist() ) {
			return new WP_Error(
				'setup_required',
				'Bảng dữ liệu Zalo Bot vừa được tự động tạo. Vui lòng tải lại trang (F5) và thử lại.',
				array( 'status' => 503, 'setup_required' => true )
			);
		}
		$id    = (int) $request->get_param( 'id' );
		$body  = (array) $request->get_json_params();
		if ( empty( $body ) ) { $body = $request->get_params(); }

		// PATCH semantics for updates (id > 0): only touch fields present in body.
		// Avoids wiping app_id/webhook_url/webhook_secret when caller only sends bot_name.
		$is_update = $id > 0;
		$data      = array();

		if ( ! $is_update || array_key_exists( 'bot_name', $body ) ) {
			$data['bot_name'] = sanitize_text_field( (string) $request->get_param( 'bot_name' ) );
		}
		if ( array_key_exists( 'app_id', $body ) ) {
			$data['app_id'] = sanitize_text_field( (string) $request->get_param( 'app_id' ) );
		}
		if ( array_key_exists( 'app_secret', $body ) ) {
			$data['app_secret'] = sanitize_text_field( (string) $request->get_param( 'app_secret' ) );
		}
		if ( array_key_exists( 'oa_id', $body ) ) {
			$data['oa_id'] = sanitize_text_field( (string) $request->get_param( 'oa_id' ) );
		}
		if ( array_key_exists( 'webhook_url', $body ) ) {
			$data['webhook_url'] = esc_url_raw( (string) $request->get_param( 'webhook_url' ) );
		}
		if ( array_key_exists( 'webhook_secret', $body ) ) {
			$data['webhook_secret'] = sanitize_text_field( (string) $request->get_param( 'webhook_secret' ) );
		}
		if ( array_key_exists( 'status', $body ) ) {
			$data['status'] = in_array( $request->get_param( 'status' ), array( 'active', 'inactive' ), true ) ? $request->get_param( 'status' ) : 'active';
		} elseif ( ! $is_update ) {
			$data['status'] = 'active';
		}

		$token = (string) $request->get_param( 'bot_token' );
		if ( $token !== '' ) {
			$data['bot_token'] = sanitize_text_field( $token );
		}

		if ( ! $is_update && empty( $data['bot_name'] ) ) {
			return new WP_Error( 'invalid_input', 'bot_name is required', array( 'status' => 400 ) );
		}
		if ( $is_update && isset( $data['bot_name'] ) && $data['bot_name'] === '' ) {
			return new WP_Error( 'invalid_input', 'bot_name cannot be empty', array( 'status' => 400 ) );
		}

		$db = BizCity_Zalo_Bot_Database::instance();
		if ( $is_update ) {
			$data['id'] = $id;
		} else {
			if ( empty( $data['bot_token'] ) ) {
				return new WP_Error( 'invalid_input', 'bot_token is required when creating a new bot', array( 'status' => 400 ) );
			}
		}
		$saved_id = $db->save_bot( $data );
		return rest_ensure_response( array( 'success' => true, 'id' => (int) $saved_id ) );
	}

	public function mgmt_delete_bot( $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_input', 'id required', array( 'status' => 400 ) );
		}
		$db = BizCity_Zalo_Bot_Database::instance();
		$db->delete_bot( $id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Recent chatters for a bot — distinct zalo_user_id from logs table grouped
	 * with last-seen + msg_count + link status. Used by FE "Người mới nhắn"
	 * panel to let admin quick-bind a chat ID → WP user without typing.
	 */
	public function mgmt_recent_users( $request ) {
		global $wpdb;
		$bot_id = (int) $request->get_param( 'id' );
		if ( $bot_id <= 0 ) {
			return new WP_Error( 'invalid_input', 'bot id required', array( 'status' => 400 ) );
		}
		$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 30 ) ) );

		$logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		// Pick latest non-empty display_name per user (MAX over GROUP works because
		// rows with the actual name will sort > NULL/empty alphabetically).
		$sql = $wpdb->prepare(
			"SELECT user_id            AS zalo_user_id,
			        MAX( display_name ) AS display_name,
			        COUNT(*)            AS msg_count,
			        MAX( created_at )   AS last_seen,
			        MAX( text )         AS last_text
			   FROM {$logs}
			  WHERE bot_id = %d
			    AND user_id <> ''
			    -- [2026-07-08 Johnny Chu] HOTFIX — avoid strict whitelist that can
			    -- hide valid inbound rows when Zalo sends alternative message event names.
			    AND event_name <> 'bot.reply'
			    AND (
			        event_name IN ( 'message.text.received', 'message.image.received' )
			        OR event_name LIKE 'message.%'
			        OR event_name LIKE 'user.send.%'
			    )
			  GROUP BY user_id
			  ORDER BY last_seen DESC
			  LIMIT %d",
			$bot_id,
			$limit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: array();

		// Annotate with link status + notebook
		$nb_table  = $wpdb->prefix . 'bizcity_kg_notebooks';
		// [2026-06-29 Johnny Chu] R-SHOW-TABLES — SHOW TABLES forbidden on multisite; use information_schema
		$nb_exists = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$nb_table
		) );
		$link_tbl  = class_exists( 'BizCity_Zalobot_User_Linker' ) ? BizCity_Zalobot_User_Linker::table() : '';

		$out = array();
		foreach ( $rows as $r ) {
			$wp_user_id  = 0;
			$wp_user     = null;
			$link_id     = 0;
			$notebook_id = 0;
			$notebook_title = '';
			if ( $link_tbl ) {
				$link = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, wp_user_id, notebook_id FROM {$link_tbl} WHERE bot_id = %d AND zalo_user_id = %s LIMIT 1",
					$bot_id,
					(string) $r['zalo_user_id']
				) );
				if ( $link ) {
					$link_id     = (int) $link->id;
					$wp_user_id  = (int) $link->wp_user_id;
					$notebook_id = (int) $link->notebook_id;
				}
			}
			if ( $wp_user_id > 0 ) {
				$u = get_userdata( $wp_user_id );
				if ( $u ) {
					$wp_user = array(
						'id'           => $u->ID,
						'user_login'   => $u->user_login,
						'display_name' => $u->display_name,
						'user_email'   => $u->user_email,
						'roles'        => array_values( (array) $u->roles ),
					);
				}
			}
			if ( $nb_exists && $notebook_id > 0 ) {
				$notebook_title = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT name FROM {$nb_table} WHERE id = %d LIMIT 1",
					$notebook_id
				) );
			}
			$r['link_id']        = $link_id;
			$r['wp_user_id']     = $wp_user_id;
			$r['wp_user']        = $wp_user;
			$r['is_linked']      = $wp_user_id > 0;
			$r['notebook_id']    = $notebook_id;
			$r['notebook_title'] = $notebook_title;
			$r['msg_count']      = (int) $r['msg_count'];
			// Truncate last_text for preview
			if ( is_string( $r['last_text'] ) && mb_strlen( $r['last_text'] ) > 80 ) {
				$r['last_text'] = mb_substr( $r['last_text'], 0, 80 ) . '…';
			}
			$out[] = $r;
		}

		$debug = array(
			'selected_bot_id' => $bot_id,
			'user_count'      => count( $out ),
		);

		if ( empty( $out ) ) {
			// [2026-07-08 Johnny Chu] HOTFIX — when selected bot has no rows, expose
			// inbound activity from other bots so admin can spot wrong-bot listening.
			$other_rows = $wpdb->get_results(
				"SELECT bot_id,
				        COUNT(*) AS inbound_count,
				        MAX(created_at) AS last_seen
				   FROM {$logs}
				  WHERE event_name <> 'bot.reply'
				    AND ( event_name LIKE 'message.%' OR event_name LIKE 'user.send.%' )
				  GROUP BY bot_id
				  ORDER BY last_seen DESC
				  LIMIT 5",
				ARRAY_A
			) ?: array();

			$debug['other_bot_activity'] = array_map(
				static function( $row ) {
					return array(
						'bot_id'        => (int) ( $row['bot_id'] ?? 0 ),
						'inbound_count' => (int) ( $row['inbound_count'] ?? 0 ),
						'last_seen'     => (string) ( $row['last_seen'] ?? '' ),
					);
				},
				$other_rows
			);
		}

		return rest_ensure_response( array(
			'users'  => $out,
			'debug'  => $debug,
		) );
	}

	public function mgmt_list_links( $request ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return rest_ensure_response( array( 'links' => array() ) );
		}
		$table  = BizCity_Zalobot_User_Linker::table();
		$bot_id = (int) $request->get_param( 'bot_id' );
		$blog   = (int) ( $request->get_param( 'blog_id' ) ?: get_current_blog_id() );
		$status = (string) $request->get_param( 'status' );

		$where  = array( 'blog_id = %d' );
		$params = array( $blog );
		if ( $bot_id > 0 ) {
			$where[]   = 'bot_id = %d';
			$params[]  = $bot_id;
		}
		if ( $status !== '' ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT 500";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		// Hydrate WP user info + notebook info
		$nb_table = $wpdb->prefix . 'bizcity_kg_notebooks';
		$nb_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $nb_table ) ) === $nb_table;
		$out = array();
		foreach ( (array) $rows as $r ) {
			$entry = (array) $r;
			$entry['wp_user'] = null;
			if ( ! empty( $r->wp_user_id ) ) {
				$u = get_userdata( (int) $r->wp_user_id );
				if ( $u ) {
					$entry['wp_user'] = array(
						'id'           => $u->ID,
						'user_login'   => $u->user_login,
						'display_name' => $u->display_name,
						'user_email'   => $u->user_email,
						'roles'        => array_values( (array) $u->roles ),
					);
				}
			}
			$entry['notebook_title'] = '';
			if ( $nb_exists && ! empty( $r->notebook_id ) ) {
				$entry['notebook_title'] = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT name FROM {$nb_table} WHERE id = %d LIMIT 1",
					(int) $r->notebook_id
				) );
			}
			$out[] = $entry;
		}
		return rest_ensure_response( array( 'links' => $out ) );
	}

	public function mgmt_create_link( $request ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return new WP_Error( 'no_linker', 'User linker not available', array( 'status' => 500 ) );
		}
		$zalo_user_id = sanitize_text_field( (string) $request->get_param( 'zalo_user_id' ) );
		$bot_id       = (int) $request->get_param( 'bot_id' );
		$wp_user_id   = (int) $request->get_param( 'wp_user_id' );
		$display_name = sanitize_text_field( (string) $request->get_param( 'display_name' ) );
		$blog_id      = (int) ( $request->get_param( 'blog_id' ) ?: get_current_blog_id() );

		if ( ! $zalo_user_id || ! $bot_id || ! $wp_user_id ) {
			return new WP_Error( 'invalid_input', 'zalo_user_id, bot_id, wp_user_id are required', array( 'status' => 400 ) );
		}
		if ( ! get_userdata( $wp_user_id ) ) {
			return new WP_Error( 'invalid_input', 'wp_user_id not found', array( 'status' => 404 ) );
		}

		$table = BizCity_Zalobot_User_Linker::table();
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE zalo_user_id = %s AND bot_id = %d LIMIT 1",
			$zalo_user_id, $bot_id
		) );

		// [2026-06-29 Johnny Chu] PHASE-0 — check DB errors so FE can detect failures.
		if ( $existing ) {
			$result = $wpdb->update( $table, array(
				'wp_user_id'   => $wp_user_id,
				'display_name' => $display_name,
				'status'       => 'linked',
				'linked_at'    => current_time( 'mysql' ),
			), array( 'id' => $existing->id ) );
			if ( false === $result ) {
				return new WP_Error( 'db_error', 'Không thể cập nhật link: ' . $wpdb->last_error, array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'success' => true, 'id' => (int) $existing->id, 'action' => 'updated' ) );
		}

		$rows = $wpdb->insert( $table, array(
			'zalo_user_id' => $zalo_user_id,
			'bot_id'       => $bot_id,
			'blog_id'      => $blog_id,
			'wp_user_id'   => $wp_user_id,
			'status'       => 'linked',
			'display_name' => $display_name,
			'linked_at'    => current_time( 'mysql' ),
		) );
		if ( false === $rows ) {
			return new WP_Error( 'db_error', 'Không thể tạo link: ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'id' => (int) $wpdb->insert_id, 'action' => 'created' ) );
	}

	public function mgmt_delete_link( $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_input', 'id required', array( 'status' => 400 ) );
		}
		$table = BizCity_Zalobot_User_Linker::table();
		$wpdb->delete( $table, array( 'id' => $id ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * [2026-06-29 Johnny Chu] PHASE-0 — Admin force-resend login link to unlinked Zalo user.
	 * Bypasses the 5-minute cooldown by deleting the cooldown transient first.
	 */
	public function mgmt_resend_link( $request ) {
		global $wpdb;
		$bot_id       = (int) $request->get_param( 'id' );
		$zalo_user_id = sanitize_text_field( (string) $request->get_param( 'zalo_user_id' ) );
		$display_name = sanitize_text_field( (string) ( $request->get_param( 'display_name' ) ?: '' ) );

		if ( ! $bot_id || ! $zalo_user_id ) {
			return new WP_Error( 'invalid_input', 'bot_id and zalo_user_id required', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return new WP_Error( 'no_linker', 'User linker not available', array( 'status' => 500 ) );
		}

		$bot = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bizcity_zalo_bots WHERE id = %d LIMIT 1",
			$bot_id
		) );
		if ( ! $bot ) {
			return new WP_Error( 'not_found', 'Bot not found', array( 'status' => 404 ) );
		}

		// Admin force-resend: delete cooldown transient so maybe_send_login_link proceeds.
		$cooldown_key = 'bzzalolink_cd_' . md5( $zalo_user_id . '_' . $bot_id );
		delete_transient( $cooldown_key );

		$sent = BizCity_Zalobot_User_Linker::maybe_send_login_link(
			$zalo_user_id,
			$bot_id,
			$bot,
			$display_name
		);

		return rest_ensure_response( array( 'success' => true, 'sent' => $sent ) );
	}

	public function mgmt_set_link_role( $request ) {
		global $wpdb;
		$id   = (int) $request->get_param( 'id' );
		$role = sanitize_text_field( (string) $request->get_param( 'role' ) );
		if ( $id <= 0 || $role === '' ) {
			return new WP_Error( 'invalid_input', 'id and role required', array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'get_role' ) || ! get_role( $role ) ) {
			return new WP_Error( 'invalid_role', 'Role does not exist', array( 'status' => 400 ) );
		}
		$table = BizCity_Zalobot_User_Linker::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT wp_user_id FROM {$table} WHERE id = %d LIMIT 1", $id ) );
		if ( ! $row || ! $row->wp_user_id ) {
			return new WP_Error( 'not_found', 'Link not found or no wp_user', array( 'status' => 404 ) );
		}
		$user = get_userdata( (int) $row->wp_user_id );
		if ( ! $user ) {
			return new WP_Error( 'not_found', 'WP user not found', array( 'status' => 404 ) );
		}
		$user->set_role( $role );
		return rest_ensure_response( array( 'success' => true, 'wp_user_id' => (int) $row->wp_user_id, 'role' => $role ) );
	}

	/**
	 * Set notebook_id for a user-link row. notebook_id = 0 = unset.
	 */
	public function mgmt_set_link_notebook( $request ) {
		global $wpdb;
		$id          = (int) $request->get_param( 'id' );
		$notebook_id = (int) $request->get_param( 'notebook_id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_input', 'id required', array( 'status' => 400 ) );
		}
		$table = BizCity_Zalobot_User_Linker::table();
		$ok = $wpdb->update(
			$table,
			array( 'notebook_id' => max( 0, $notebook_id ) ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
		if ( $ok === false ) {
			return new WP_Error( 'db_error', 'Update failed', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'id' => $id, 'notebook_id' => max( 0, $notebook_id ) ) );
	}

	/**
	 * List notebooks on the current site (for binding dropdown).
	 * Reads `bizcity_kg_notebooks` directly — table is created by core/knowledge/kg-hub.
	 */
	public function mgmt_list_notebooks( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_kg_notebooks';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		if ( ! $exists ) {
			return rest_ensure_response( array( 'notebooks' => array() ) );
		}
		// Probe optional columns so we don't error on older schemas.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ?: array();
		$has_label = in_array( 'perspective_label', $cols, true );

		$select = 'id, name'
			. ( $has_label ? ', perspective_label' : '' );
		$rows = $wpdb->get_results( "SELECT {$select} FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A ) ?: array();

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'    => (int) $r['id'],
				'title' => (string) ( $r['name'] ?? '' ),
				'label' => isset( $r['perspective_label'] ) ? (string) $r['perspective_label'] : '',
			);
		}
		return rest_ensure_response( array( 'notebooks' => $out ) );
	}

	/**
	 * Conversation history for one zalo_user_id within a bot.
	 * Returns ALL relevant rows (inbound + bot replies if logged) chronologically
	 * ascending so the FE can render an inbox-style thread.
	 */
	public function mgmt_conversation( $request ) {
		global $wpdb;
		$bot_id       = (int) $request->get_param( 'id' );
		$zalo_user_id = sanitize_text_field( (string) $request->get_param( 'zalo_user_id' ) );
		$limit        = max( 1, min( 500, (int) ( $request->get_param( 'limit' ) ?: 200 ) ) );
		if ( $bot_id <= 0 || $zalo_user_id === '' ) {
			return new WP_Error( 'invalid_input', 'bot id + zalo_user_id required', array( 'status' => 400 ) );
		}

		$logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		$sql  = $wpdb->prepare(
			"SELECT id, event_name, text, display_name, message_id, created_at
			   FROM {$logs}
			  WHERE bot_id = %d
			    AND ( user_id = %s OR client_id = %s )
			  ORDER BY id ASC
			  LIMIT %d",
			$bot_id,
			$zalo_user_id,
			$zalo_user_id,
			$limit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: array();

		// Map event_name → role for FE rendering
		foreach ( $rows as &$r ) {
			$r['role'] = ( isset( $r['event_name'] ) && $r['event_name'] === 'bot.reply' ) ? 'assistant' : 'user';
			$r['id']   = (int) $r['id'];
		}
		unset( $r );

		return rest_ensure_response( array(
			'messages'     => $rows,
			'zalo_user_id' => $zalo_user_id,
			'bot_id'       => $bot_id,
		) );
	}

	/**
	 * Admin-initiated outgoing message.
	 *
	 * Flow:
	 *   1. Validate bot + target zalo_user_id + non-empty text.
	 *   2. Call Zalo Bot Platform API sendMessage via BizCity_Zalo_Bot_API.
	 *   3. Log result into bizcity_zalo_bot_logs với event_name = 'bot.reply'
	 *      và event_data marker {from: 'admin', wp_user_id: X} để timeline
	 *      conversation hiển thị đúng phía bot, và analytics phân biệt được
	 *      tin do admin gửi tay vs auto-reply của workflow.
	 *   4. Trả về row vừa insert để FE có thể optimistic-append vào thread
	 *      mà không cần round-trip refetch.
	 *
	 * Lưu ý ko-ngoại lệ: KHÔNG tự gắn admin reply vào workflow trigger; flow
	 * này thuần "monitor / assist" của admin — không trigger lại pipeline.
	 */
	public function mgmt_admin_send( $request ) {
		global $wpdb;
		$bot_id       = (int) $request->get_param( 'id' );
		$zalo_user_id = sanitize_text_field( (string) $request->get_param( 'zalo_user_id' ) );
		$text         = (string) $request->get_param( 'text' );
		if ( $bot_id <= 0 || $zalo_user_id === '' || trim( $text ) === '' ) {
			return new WP_Error( 'invalid_input', 'bot id + zalo_user_id + text required', array( 'status' => 400 ) );
		}

		$db  = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot not found', array( 'status' => 404 ) );
		}
		if ( empty( $bot->bot_token ) ) {
			return new WP_Error( 'no_token', 'Bot token missing', array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'BizCity_Zalo_Bot_API' ) ) {
			require_once dirname( __DIR__ ) . '/lib/class-zalo-bot-api.php';
		}
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );

		// Phase CG-Listener S2 (2026-05-30) — resolve REAL chat_id from latest inbound
		// webhook row. Zalo Bot Platform yêu cầu chat_id (dialog id) khi sendMessage,
		// KHÔNG phải from.id (user identity). Webhook handler hiện stores user_id vào
		// column client_id, còn chat_id nằm trong event_data JSON. Nếu không tìm được
		// chat_id từ history → fallback user_id (cũ — đôi khi bằng nhau ở DM mới).
		$chat_resolution = $this->resolve_chat_id_for_user( $bot_id, $zalo_user_id );
		$send_chat_id    = $chat_resolution['chat_id'] ?: $zalo_user_id;

		$response = $api->send_message( $send_chat_id, $text );

		// Phase CG-Listener S2 — nếu chat_id resolved khác fail 410, thử lại 1 lần với user_id.
		if ( is_wp_error( $response )
			&& $chat_resolution['chat_id']
			&& $send_chat_id !== $zalo_user_id
			&& (int) ( ( $response->get_error_data()['error_code'] ?? 0 ) ) === 410
		) {
			$retry_chat_id = $zalo_user_id;
			$retry         = $api->send_message( $retry_chat_id, $text );
			if ( ! is_wp_error( $retry ) ) {
				$response                  = $retry;
				$send_chat_id              = $retry_chat_id;
				$chat_resolution['source'] = 'fallback_user_id_after_410';
			}
		}

		if ( is_wp_error( $response ) ) {
			// Phase CG-Listener S2 (2026-05-30) — rich diagnosis for any admin-send failure.
			$err_data    = $response->get_error_data();
			$err_arr     = is_array( $err_data ) ? $err_data : array();
			$zalo_code   = isset( $err_arr['error_code'] ) ? (int) $err_arr['error_code'] : 0;
			$friendly    = $response->get_error_message();
			$reason      = 'send_failed';
			$http_status = isset( $err_arr['status'] ) ? (int) $err_arr['status'] : 500;
			$diagnosis   = $this->build_recipient_diagnosis( $bot, $zalo_user_id, array(
				'tried_chat_id'   => $send_chat_id,
				'chat_resolution' => $chat_resolution,
				'zalo_code'       => $zalo_code,
				'zalo_message'    => isset( $err_arr['description'] ) ? (string) $err_arr['description'] : (string) ( $err_arr['message'] ?? '' ),
			) );

			if ( $zalo_code === 410 ) {
				$reason      = 'invalid_chat_id';
				$http_status = 422;
				$friendly    = $diagnosis['verdict']['title'] . ' — ' . $diagnosis['verdict']['summary'];
			}

			do_action( 'bizcity_listener_emit', array(
				'kind'       => 'outbound',
				'platform'   => 'ZALO_BOT',
				'account_id' => (string) $bot_id,
				'user_id'    => $zalo_user_id,
				'chat_id'    => 'zalobot_' . $bot_id . '_' . $zalo_user_id,
				'event_type' => 'bot.reply.failed',
				'direction'  => 'out',
				'message'    => $text,
				'status'     => 'fail',
				'meta'       => array(
					'source'        => 'zalo_admin_send_failed',
					'reason'        => $reason,
					'zalo_code'     => $zalo_code,
					'tried_chat_id' => $send_chat_id,
					'verdict'       => $diagnosis['verdict']['code'],
					'error_code'    => $response->get_error_code(),
					'wp_user_id'    => get_current_user_id(),
				),
			) );

			return new WP_Error(
				$response->get_error_code(),
				$friendly,
				array_merge( $err_arr, array(
					'status'        => $http_status,
					'reason'        => $reason,
					'zalo_code'     => $zalo_code,
					'bot_id'        => $bot_id,
					'bot_name'      => (string) ( $bot->bot_name ?? '' ),
					'tried_chat_id' => $send_chat_id,
					'diagnosis'     => $diagnosis,
				) )
			);
		}

		// Used real chat_id from history? Persist it back so future sends skip lookup.
		if ( $chat_resolution['chat_id'] && $send_chat_id === $chat_resolution['chat_id'] ) {
			set_transient(
				'zalobot_chatid_' . $bot_id . '_' . md5( $zalo_user_id ),
				$send_chat_id,
				DAY_IN_SECONDS * 7
			);
		}

		// Log into logs table so the conversation timeline picks it up.
		$wp_user_id  = get_current_user_id();
		$event_data  = array(
			'from'        => 'admin',
			'wp_user_id'  => $wp_user_id,
			'sent_at'     => current_time( 'mysql', true ),
			'api_result'  => is_array( $response ) ? $response : array( 'raw' => $response ),
		);
		$display     = $wp_user_id ? ( wp_get_current_user()->display_name ?: 'admin' ) : 'admin';
		$message_id  = is_array( $response ) && isset( $response['result']['message_id'] )
			? (string) $response['result']['message_id']
			: ( 'admin-' . wp_generate_uuid4() );

		$db->log_event(
			$bot_id,
			'bot.reply',
			$event_data,
			$zalo_user_id,
			$message_id,
			$display,
			$text
		);
		$inserted_id = (int) $wpdb->insert_id;

		// Phase CG-Listener S2 (2026-05-30) — surface admin reply in live tail.
		do_action( 'bizcity_listener_emit', array(
			'kind'       => 'outbound',
			'platform'   => 'ZALO_BOT',
			'account_id' => (string) $bot_id,
			'user_id'    => $zalo_user_id,
			'chat_id'    => 'zalobot_' . $bot_id . '_' . $zalo_user_id,
			'event_type' => 'bot.reply',
			'direction'  => 'out',
			'message'    => $text,
			'status'     => 'ok',
			'meta'       => array(
				'source'       => 'zalo_admin_send',
				'message_id'   => $message_id,
				'display_name' => $display,
				'wp_user_id'   => $wp_user_id,
				'log_id'       => $inserted_id,
			),
		) );

		return rest_ensure_response( array(
			'success'    => true,
			'message_id' => $message_id,
			'log_id'     => $inserted_id,
			'used_chat_id' => $send_chat_id,
			'chat_resolution' => $chat_resolution,
			'message'    => array(
				'id'           => $inserted_id,
				'role'         => 'assistant',
				'event_name'   => 'bot.reply',
				'text'         => $text,
				'display_name' => $display,
				'message_id'   => $message_id,
				'created_at'   => current_time( 'mysql' ),
			),
		) );
	}

	/**
	 * Phase CG-Listener S2 — Live ground-truth identity of the Bot Token.
	 *
	 * Calls Zalo `getMe` to identify which OA the token actually belongs to.
	 * If this fails or returns an identity that doesn't match `bot_name` in DB,
	 * the diagnose verdict surfaces it as `bot_token_invalid` or
	 * `replies_never_succeeded_likely_misattribution`.
	 *
	 * Cached 60s in transient to keep diagnose endpoint cheap (FE may poll on
	 * focus / user selection).
	 *
	 * @param object $bot   Row from bizcity_zalo_bots.
	 * @return array{ok:bool,identity:array,raw:?array,error:?string,checked_at:string}
	 */
	private function fetch_live_bot_identity( $bot ) {
		$bot_id = (int) ( $bot->id ?? 0 );
		$cache_key = 'zalobot_live_identity_' . $bot_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = array(
			'ok'         => false,
			'identity'   => array(),
			'raw'        => null,
			'error'      => null,
			'checked_at' => current_time( 'mysql' ),
		);

		if ( empty( $bot->bot_token ) ) {
			$out['error'] = 'Bot token rỗng trong DB';
			set_transient( $cache_key, $out, 60 );
			return $out;
		}

		if ( ! class_exists( 'BizCity_Zalo_Bot_API' ) ) {
			require_once dirname( __DIR__ ) . '/lib/class-zalo-bot-api.php';
		}
		$api = new BizCity_Zalo_Bot_API( $bot->bot_token );
		$res = $api->get_me();
		if ( is_wp_error( $res ) ) {
			$out['error'] = (string) $res->get_error_message();
			$out['raw']   = $res->get_error_data();
		} elseif ( is_array( $res ) ) {
			$out['raw']      = $res;
			$out['ok']       = ! empty( $res['ok'] );
			$result          = isset( $res['result'] ) && is_array( $res['result'] ) ? $res['result'] : array();
			// Zalo Bot Platform getMe trả `account_name` (handle/username) và
			// `display_name` (tên hiển thị). Telegram-style trả `username` /
			// `first_name`. Hỗ trợ cả hai schema cho an toàn.
			$out['identity'] = array(
				'id'           => isset( $result['id'] ) ? (string) $result['id'] : '',
				'username'     => (string) ( $result['account_name'] ?? $result['username'] ?? '' ),
				'first_name'   => (string) ( $result['display_name'] ?? $result['first_name'] ?? '' ),
				'account_type' => isset( $result['account_type'] ) ? (string) $result['account_type'] : '',
				'is_bot'       => ! empty( $result['is_bot'] ),
			);
			if ( ! $out['ok'] ) {
				$out['error'] = isset( $res['description'] ) ? (string) $res['description'] : 'getMe returned ok=false';
			}
		} else {
			$out['error'] = 'Phản hồi getMe không hợp lệ';
		}

		set_transient( $cache_key, $out, 60 );
		return $out;
	}

	/**
	 * Phase CG-Listener S2 — Resolve real Zalo `chat_id` for a logical user.
	 *
	 * Background: webhook handler stores `message.from.id` (user identity) into
	 * column `client_id` (and dups into `user_id`). The Zalo Bot Platform
	 * `sendMessage` endpoint, however, requires `chat_id` (= `message.chat.id`)
	 * which is the dialog identifier. In a 1-1 chat the two are often equal,
	 * but Zalo Bot Platform may mint a separate chat_id (e.g. when a user is
	 * re-onboarded after a token reset or when the bot is added to a group).
	 *
	 * Resolution order:
	 *   1. Transient cache `zalobot_chatid_<bot_id>_<md5(user_id)>` (warm fast-path)
	 *   2. Latest inbound log row for this bot + user where event_data JSON
	 *      contains `message.chat.id` (decode + extract)
	 *   3. Empty → caller falls back to user_id as before
	 *
	 * @param int    $bot_id
	 * @param string $zalo_user_id  message.from.id from earlier webhook
	 * @return array{chat_id:string,source:string,sample_log_id:int,inbound_count:int,last_event:?string,last_seen:?string}
	 */
	private function resolve_chat_id_for_user( $bot_id, $zalo_user_id ) {
		global $wpdb;
		$out = array(
			'chat_id'       => '',
			'source'        => 'none',
			'sample_log_id' => 0,
			'inbound_count' => 0,
			'last_event'    => null,
			'last_seen'     => null,
		);

		// Cache fast-path.
		$cache_key = 'zalobot_chatid_' . (int) $bot_id . '_' . md5( (string) $zalo_user_id );
		$cached    = get_transient( $cache_key );
		if ( $cached && is_string( $cached ) && $cached !== '' ) {
			$out['chat_id'] = $cached;
			$out['source']  = 'transient_cache';
		}

		$logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_name, event_data, created_at
				   FROM {$logs}
				  WHERE bot_id = %d
				    AND ( user_id = %s OR client_id = %s )
				    AND event_name != 'bot.reply'
				  ORDER BY id DESC
				  LIMIT 25",
				$bot_id,
				$zalo_user_id,
				$zalo_user_id
			),
			ARRAY_A
		) ?: array();

		$out['inbound_count'] = count( $rows );
		if ( ! empty( $rows ) ) {
			$out['last_event'] = (string) ( $rows[0]['event_name'] ?? '' );
			$out['last_seen']  = (string) ( $rows[0]['created_at'] ?? '' );
		}

		if ( $out['chat_id'] === '' ) {
			foreach ( $rows as $r ) {
				$data = isset( $r['event_data'] ) ? json_decode( (string) $r['event_data'], true ) : null;
				if ( ! is_array( $data ) ) {
					continue;
				}
				$cid = '';
				if ( isset( $data['message']['chat']['id'] ) ) {
					$cid = (string) $data['message']['chat']['id'];
				} elseif ( isset( $data['chat']['id'] ) ) {
					$cid = (string) $data['chat']['id'];
				}
				if ( $cid !== '' ) {
					$out['chat_id']       = $cid;
					$out['source']        = 'webhook_history';
					$out['sample_log_id'] = (int) $r['id'];
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Phase CG-Listener S2 — Build rich diagnosis explaining WHY a recipient is
	 * (in)eligible for admin-send. Returns 3 layers of evidence + verdict so the
	 * FE can render an actionable card instead of a one-line error toast.
	 *
	 * @param object $bot
	 * @param string $zalo_user_id
	 * @param array  $ctx   Extra context from caller: tried_chat_id, chat_resolution, zalo_code, zalo_message
	 * @return array
	 */
	private function build_recipient_diagnosis( $bot, $zalo_user_id, $ctx = array() ) {
		global $wpdb;
		$bot_id = (int) ( $bot->id ?? 0 );

		// Layer A — Bot identity / token sanity.
		$bot_info = array(
			'id'             => $bot_id,
			'name'           => (string) ( $bot->bot_name ?? '' ),
			'has_token'      => ! empty( $bot->bot_token ),
			'webhook_secret' => ! empty( $bot->webhook_secret ),
			'updated_at'     => (string) ( $bot->updated_at ?? '' ),
		);

		// Layer B — Inbound history for this user in this bot.
		$logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*)                                                                   AS total,
					SUM( CASE WHEN event_name = 'message.text.received' THEN 1 ELSE 0 END )    AS text_msgs,
					SUM( CASE WHEN event_name LIKE 'message.%.received' THEN 1 ELSE 0 END )    AS any_msgs,
					SUM( CASE WHEN event_name = 'bot.reply' THEN 1 ELSE 0 END )                AS bot_replies,
					MAX( created_at )                                                          AS last_seen,
					MIN( created_at )                                                          AS first_seen
				   FROM {$logs}
				  WHERE bot_id = %d
				    AND ( user_id = %s OR client_id = %s )",
				$bot_id,
				$zalo_user_id,
				$zalo_user_id
			),
			ARRAY_A
		) ?: array();
		$history = array(
			'total_events'  => (int) ( $counts['total'] ?? 0 ),
			'text_messages' => (int) ( $counts['text_msgs'] ?? 0 ),
			'any_messages'  => (int) ( $counts['any_msgs'] ?? 0 ),
			'bot_replies'   => (int) ( $counts['bot_replies'] ?? 0 ),
			'first_seen'    => (string) ( $counts['first_seen'] ?? '' ),
			'last_seen'     => (string) ( $counts['last_seen'] ?? '' ),
		);

		// Layer C — Is this user_id seen on OTHER bots? (cross-bot orphan detection)
		$other = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot_id, COUNT(*) AS cnt, MAX( created_at ) AS last_seen
				   FROM {$logs}
				  WHERE ( user_id = %s OR client_id = %s )
				    AND bot_id != %d
				  GROUP BY bot_id
				  ORDER BY cnt DESC
				  LIMIT 5",
				$zalo_user_id,
				$zalo_user_id,
				$bot_id
			),
			ARRAY_A
		) ?: array();
		$cross_bot = array(
			'seen_on_other_bots' => count( $other ) > 0,
			'bots'               => array_map(
				function ( $r ) {
					return array(
						'bot_id'    => (int) $r['bot_id'],
						'count'     => (int) $r['cnt'],
						'last_seen' => (string) $r['last_seen'],
					);
				},
				$other
			),
		);

		// Layer D — Bot-level webhook health (any inbound messages on this bot AT ALL?).
		$bot_health = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*)                                                                   AS total,
					SUM( CASE WHEN event_name LIKE 'message.%.received' THEN 1 ELSE 0 END )    AS msg_in,
					SUM( CASE WHEN event_name = 'bot.reply' THEN 1 ELSE 0 END )                AS msg_out,
					COUNT( DISTINCT CASE WHEN event_name LIKE 'message.%.received' THEN client_id END ) AS distinct_senders,
					MAX( created_at )                                                          AS last_event_at
				   FROM {$logs}
				  WHERE bot_id = %d",
				$bot_id
			),
			ARRAY_A
		) ?: array();
		$health = array(
			'total_events'     => (int) ( $bot_health['total'] ?? 0 ),
			'msg_in'           => (int) ( $bot_health['msg_in'] ?? 0 ),
			'msg_out'          => (int) ( $bot_health['msg_out'] ?? 0 ),
			'distinct_senders' => (int) ( $bot_health['distinct_senders'] ?? 0 ),
			'last_event_at'    => (string) ( $bot_health['last_event_at'] ?? '' ),
		);

		// Verdict logic — ordered by specificity.
		$resolution = isset( $ctx['chat_resolution'] ) && is_array( $ctx['chat_resolution'] )
			? $ctx['chat_resolution']
			: array( 'source' => 'none', 'chat_id' => '' );
		$tried_chat_id = (string) ( $ctx['tried_chat_id'] ?? $zalo_user_id );
		$zalo_code     = (int) ( $ctx['zalo_code'] ?? 0 );

		// Phase CG-Listener S2 — Live ground-truth: Zalo getMe identifies the OA
		// behind the Bot Token. If our DB rows for this bot were misattributed
		// (handler fallback when X-Bot-Api-Secret-Token header missing/mismatch),
		// the live identity will not match the rows → we surface that explicitly.
		// Cache 60s to keep diagnose endpoint cheap.
		$live = $this->fetch_live_bot_identity( $bot );

		// Cross-signal flags used by verdicts below.
		$has_other_replying_bot = false;
		foreach ( $cross_bot['bots'] as $cb ) {
			// Heuristic: another bot has noticeably more events with same user
			// (10x or 50+) → that bot is likely the REAL recipient owner.
			if ( $cb['count'] >= max( 10, $history['any_messages'] * 2 ) ) {
				$has_other_replying_bot = true;
				break;
			}
		}

		if ( ! $bot_info['webhook_secret'] && $cross_bot['seen_on_other_bots'] ) {
			// CRITICAL — bot has NO webhook_secret → handler cannot route updates
			// to this bot reliably. Past msg_in rows were likely misattributed
			// from "first active bot" fallback path in webhook handler.
			$verdict = array(
				'code'     => 'webhook_secret_missing_misattribution',
				'severity' => 'block',
				'title'    => 'Bot CHƯA có webhook secret → events có thể bị misattributed',
				'summary'  => sprintf(
					'Bot "%s" không có `webhook_secret` trong DB. Khi Zalo POST tới /zalohook/, handler không match được bot bằng header X-Bot-Api-Secret-Token → fallback "first active bot" → events có thể đã được lưu nhầm vào bot này từ bot khác. Đây là lý do user_id "%s" xuất hiện ở %d bot trong DB. chat_id `%s` là VALID cho bot khác chứ không phải bot này → Zalo 410 khi gửi từ token bot này.',
					$bot_info['name'],
					$zalo_user_id,
					count( $cross_bot['bots'] ) + 1,
					$tried_chat_id
				),
				'actions'  => array(
					array( 'label' => 'Vào tab Setup → bước "Đăng ký Webhook với Zalo" → để trống Secret token (auto-gen) → bấm "Set Webhook" lại cho bot này', 'type' => 'navigate', 'target' => 'setup' ),
					array( 'label' => 'Kiểm tra cross_bot list bên dưới — bot có msg_out > 0 chính là chủ thật của user_id này', 'type' => 'instruction' ),
					array( 'label' => 'Sau khi setWebhook xong: yêu cầu user gửi tin mới để webhook lưu đúng bot_id', 'type' => 'instruction' ),
				),
			);
		} elseif ( is_array( $live ) && ! empty( $live['error'] ) ) {
			// Bot Token is broken — getMe failed. This is the most fatal cause.
			$verdict = array(
				'code'     => 'bot_token_invalid',
				'severity' => 'block',
				'title'    => 'Bot Token không hợp lệ với Zalo Bot Platform',
				'summary'  => sprintf(
					'Zalo getMe trả lỗi: %s. Token đang lưu trong DB không xác thực được. Mọi sendMessage sẽ fail. Cần lấy lại Bot Token mới từ Zalo Bot Creator.',
					(string) $live['error']
				),
				'actions'  => array(
					array( 'label' => 'Vào tab Bots → bấm "Sửa" bot "' . $bot_info['name'] . '" → paste Bot Token mới', 'type' => 'navigate', 'target' => 'bots' ),
					array( 'label' => 'Sau khi cập nhật token → Run all checks → Set Webhook lại', 'type' => 'instruction' ),
				),
			);
		} elseif ( $history['bot_replies'] === 0 && $has_other_replying_bot && $resolution['source'] === 'webhook_history' ) {
			// Bot ROW has msg_in nhưng 0 reply, while CROSS-BOT has 10x+ events
			// → strongly suggests misattribution OR token mismatch (token in DB
			// not for the OA the user actually chat with).
			$other_bot_summary = implode( ', ', array_map(
				function ( $b ) {
					return sprintf( 'bot #%d (%d ev)', $b['bot_id'], $b['count'] );
				},
				$cross_bot['bots']
			) );
			$verdict = array(
				'code'     => 'replies_never_succeeded_likely_misattribution',
				'severity' => 'block',
				'title'    => 'Bot này chưa từng reply thành công user nào — nghi misattribution / token mismatch',
				'summary'  => sprintf(
					'Bot "%s" có %d msg_in nhưng %d msg_out. Cùng user_id "%s" xuất hiện ở: %s. Khi gửi với token bot này → Zalo 410 vì chat_id thực ra thuộc về OA khác. Nguyên nhân thường gặp: (1) Bot Token trong DB sai/đã reset, (2) webhook_secret thiếu → events misattributed, (3) cùng URL /zalohook/ phục vụ nhiều bot mà routing không nhất quán.',
					$bot_info['name'],
					$history['any_messages'],
					$history['bot_replies'],
					$zalo_user_id,
					$other_bot_summary ?: 'không'
				),
				'actions'  => array(
					array( 'label' => 'Vào tab Setup → Run all checks (so sánh getMe identity với bot_name trong DB)', 'type' => 'navigate', 'target' => 'setup' ),
					array( 'label' => 'Nếu getMe trả bot identity khác bot_name DB → Bot Token sai, cần paste token mới', 'type' => 'instruction' ),
					array( 'label' => 'Nếu getMe khớp → setWebhook lại với secret_token để cố định routing', 'type' => 'instruction' ),
					array( 'label' => 'Xem cross_bot bên dưới: bot có msg_out > 0 với cùng user_id chính là chủ thật', 'type' => 'instruction' ),
				),
			);
		} elseif ( $history['any_messages'] === 0 && $health['msg_in'] === 0 ) {
			$verdict = array(
				'code'     => 'bot_never_received_any_message',
				'severity' => 'block',
				'title'    => 'Bot này chưa từng nhận tin nhắn thật',
				'summary'  => sprintf(
					'Bot "%s" có %d sự kiện webhook nhưng %d tin nhắn chat → Zalo Bot Platform không có chat_id hợp lệ để gửi. User PHẢI mở chính bot này trên Zalo và gửi tin nhắn (vd "hi") trước.',
					$bot_info['name'],
					$health['total_events'],
					$health['msg_in']
				),
				'actions'  => array(
					array( 'label' => 'Yêu cầu user mở app Zalo → tìm bot "' . $bot_info['name'] . '" → gửi "hi"', 'type' => 'instruction' ),
					array( 'label' => 'Mở tab "Webhook Listener" để xác nhận tin nhắn vào realtime', 'type' => 'navigate', 'target' => 'listener' ),
				),
			);
		} elseif ( $history['any_messages'] === 0 && $cross_bot['seen_on_other_bots'] ) {
			$verdict = array(
				'code'     => 'user_id_belongs_to_other_bot',
				'severity' => 'block',
				'title'    => 'user_id này thuộc về bot khác, không phải bot hiện tại',
				'summary'  => sprintf(
					'user_id "%s" có lịch sử ở %d bot khác nhưng CHƯA bao giờ chat với bot "%s". Zalo Bot Platform cấp user_id RIÊNG cho mỗi bot — không dùng chéo được. Đây là trường hợp inbox view bị "vay mượn" user_id từ bot khác.',
					$zalo_user_id,
					count( $cross_bot['bots'] ),
					$bot_info['name']
				),
				'actions'  => array(
					array( 'label' => 'Chuyển sang đúng tab inbox của bot kia (xem danh sách bên dưới)', 'type' => 'instruction' ),
					array( 'label' => 'Yêu cầu user mở bot "' . $bot_info['name'] . '" và chat trước nếu muốn nhận tin từ bot này', 'type' => 'instruction' ),
				),
			);
		} elseif ( $zalo_code === 410 && $resolution['source'] === 'webhook_history' ) {
			// Generic 410 with chat_id from history (no other strong signals matched).
			$verdict = array(
				'code'     => 'chat_id_from_history_rejected',
				'severity' => 'block',
				'title'    => 'chat_id lấy từ webhook history đã bị Zalo từ chối',
				'summary'  => sprintf(
					'Đã thử với chat_id `%s` (lấy từ webhook log #%d ngày %s) nhưng Zalo trả 410. Có thể Bot Token vừa reset → toàn bộ chat_id cũ invalidate, hoặc events trong DB vốn dĩ đã bị misattributed.',
					$tried_chat_id,
					(int) ( $resolution['sample_log_id'] ?? 0 ),
					(string) ( $resolution['last_seen'] ?? 'gần đây' )
				),
				'actions'  => array(
					array( 'label' => 'Hỏi user mở lại bot "' . $bot_info['name'] . '" trên Zalo và gửi 1 tin', 'type' => 'instruction' ),
					array( 'label' => 'Nếu vừa reset Bot Token → đợi user gửi tin mới rồi thử lại', 'type' => 'instruction' ),
					array( 'label' => 'Mở tab Setup → setWebhook lại nếu webhook_secret thiếu', 'type' => 'navigate', 'target' => 'setup' ),
				),
			);
		} elseif ( $zalo_code === 410 ) {
			$verdict = array(
				'code'     => 'chat_id_invalid_unknown',
				'severity' => 'block',
				'title'    => 'Zalo từ chối chat_id (410 invalid)',
				'summary'  => sprintf(
					'Đã thử gửi với chat_id "%s" nhưng Zalo Bot Platform trả "The chat_id is invaild" (error_code 410). Nguồn chat_id: %s. Mẫu webhook trước đó có thể đã hết hạn hoặc user_id chưa từng tương tác qua bot này.',
					$tried_chat_id,
					$resolution['source']
				),
				'actions'  => array(
					array( 'label' => 'Kiểm tra Bot Token có vừa reset không', 'type' => 'instruction' ),
					array( 'label' => 'Yêu cầu user chat lại với bot này để webhook mint chat_id mới', 'type' => 'instruction' ),
				),
			);
		} else {
			$verdict = array(
				'code'     => 'send_failed_other',
				'severity' => 'warn',
				'title'    => 'Gửi thất bại (không phải lỗi chat_id)',
				'summary'  => sprintf(
					'Zalo trả lỗi: %s (code %d). Kiểm tra Bot Token, kết nối mạng, hoặc rate limit.',
					(string) ( $ctx['zalo_message'] ?? 'unknown' ),
					$zalo_code
				),
				'actions'  => array(
					array( 'label' => 'Vào tab Setup → Run all checks', 'type' => 'navigate', 'target' => 'setup' ),
				),
			);
		}

		return array(
			'verdict'         => $verdict,
			'bot'             => $bot_info,
			'live_identity'   => $live,
			'user_history'    => $history,
			'cross_bot'       => $cross_bot,
			'bot_health'      => $health,
			'chat_resolution' => $resolution,
			'tried_chat_id'   => $tried_chat_id,
			'zalo_code'       => $zalo_code,
			'generated_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Phase CG-Listener S2 — REST: pre-flight recipient diagnose.
	 * FE composer calls this on user selection / focus to enable/disable Send button
	 * and surface a precise hint BEFORE the admin types.
	 */
	public function mgmt_recipient_diagnose( $request ) {
		$bot_id       = (int) $request->get_param( 'id' );
		$zalo_user_id = sanitize_text_field( (string) $request->get_param( 'zalo_user_id' ) );
		if ( $bot_id <= 0 || $zalo_user_id === '' ) {
			return new WP_Error( 'invalid_input', 'bot id + zalo_user_id required', array( 'status' => 400 ) );
		}
		$db  = BizCity_Zalo_Bot_Database::instance();
		$bot = $db->get_bot( $bot_id );
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot not found', array( 'status' => 404 ) );
		}
		$chat_resolution = $this->resolve_chat_id_for_user( $bot_id, $zalo_user_id );
		$diagnosis       = $this->build_recipient_diagnosis( $bot, $zalo_user_id, array(
			'tried_chat_id'   => $chat_resolution['chat_id'] ?: $zalo_user_id,
			'chat_resolution' => $chat_resolution,
			'zalo_code'       => 0,
			'zalo_message'    => '',
		) );

		// Pre-flight verdict slightly differs from post-failure: an empty user
		// history is a warning but not a hard 410 yet — caller may still try.
		$can_send = $diagnosis['user_history']['any_messages'] > 0
			|| $chat_resolution['chat_id'] !== '';

		return rest_ensure_response( array(
			'can_send'        => $can_send,
			'chat_resolution' => $chat_resolution,
			'diagnosis'       => $diagnosis,
		) );
	}

	const SETTINGS_OPTION = 'bizcity_zalo_bot_settings';

	public function mgmt_get_settings( $request ) {
		$defaults = array(
			'default_reply'  => 'Xin chào, bot đang tiếp nhận tin nhắn của bạn.',
			'login_cooldown' => 300,
			'auto_reply'     => true,
			'log_retention'  => 30,
		);
		$opts = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		return rest_ensure_response( array_merge( $defaults, $opts ) );
	}

	public function mgmt_save_settings( $request ) {
		$opts = array(
			'default_reply'  => sanitize_textarea_field( (string) $request->get_param( 'default_reply' ) ),
			'login_cooldown' => max( 30, (int) $request->get_param( 'login_cooldown' ) ),
			'auto_reply'     => (bool) $request->get_param( 'auto_reply' ),
			'log_retention'  => max( 1, (int) $request->get_param( 'log_retention' ) ),
		);
		update_option( self::SETTINGS_OPTION, $opts, false );
		return rest_ensure_response( array( 'success' => true, 'settings' => $opts ) );
	}

	public function mgmt_search_users( $request ) {
		$q = trim( (string) $request->get_param( 'q' ) );
		$args = array(
			'number' => 20,
			'fields' => array( 'ID', 'user_login', 'display_name', 'user_email' ),
		);
		if ( $q !== '' ) {
			$args['search']         = '*' . esc_attr( $q ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		$users = get_users( $args );
		$out = array();
		foreach ( $users as $u ) {
			$ud = get_userdata( $u->ID );
			$out[] = array(
				'id'           => (int) $u->ID,
				'user_login'   => $u->user_login,
				'display_name' => $u->display_name,
				'user_email'   => $u->user_email,
				'roles'        => $ud ? array_values( (array) $ud->roles ) : array(),
			);
		}
		return rest_ensure_response( array( 'users' => $out ) );
	}

	/* ════════════════════════════════════════════════
	 * Diagnostics handlers
	 * ════════════════════════════════════════════════ */

	/** Helper: load bot row + API client; returns WP_Error on failure. */
	private function load_bot_with_api( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_input', 'id required', array( 'status' => 400 ) );
		}
		$bot = BizCity_Zalo_Bot_Database::instance()->get_bot( $id );
		if ( ! $bot ) {
			return new WP_Error( 'bot_not_found', 'Bot not found', array( 'status' => 404 ) );
		}
		if ( empty( $bot->bot_token ) ) {
			return new WP_Error( 'no_token', 'Bot has no token', array( 'status' => 400 ) );
		}
		return array(
			'bot' => $bot,
			'api' => new BizCity_Zalo_Bot_API( $bot->bot_token ),
		);
	}

	public function mgmt_ping_bot( $request ) {
		$ctx = $this->load_bot_with_api( $request->get_param( 'id' ) );
		if ( is_wp_error( $ctx ) ) { return $ctx; }

		$t0 = microtime( true );
		$me = $ctx['api']->get_me();
		$elapsed_ms = (int) ( ( microtime( true ) - $t0 ) * 1000 );

		$ok = ! is_wp_error( $me ) && is_array( $me ) && empty( $me['error'] );
		return rest_ensure_response( array(
			'ok'         => $ok,
			'bot_id'     => (int) $ctx['bot']->id,
			'bot_name'   => $ctx['bot']->bot_name,
			'elapsed_ms' => $elapsed_ms,
			'response'   => is_wp_error( $me ) ? array( 'error' => $me->get_error_message() ) : $me,
		) );
	}

	public function mgmt_get_webhook_info( $request ) {
		$ctx = $this->load_bot_with_api( $request->get_param( 'id' ) );
		if ( is_wp_error( $ctx ) ) { return $ctx; }

		$info = $ctx['api']->get_webhook_info();
		return rest_ensure_response( array(
			'ok'       => ! is_wp_error( $info ),
			'response' => is_wp_error( $info ) ? array( 'error' => $info->get_error_message() ) : $info,
		) );
	}

	public function mgmt_set_webhook( $request ) {
		$ctx = $this->load_bot_with_api( $request->get_param( 'id' ) );
		if ( is_wp_error( $ctx ) ) { return $ctx; }

		$bot          = $ctx['bot'];
		$webhook_url  = (string) $request->get_param( 'webhook_url' );
		$secret_token = (string) $request->get_param( 'secret_token' );

		// Default to the site-local `/zalohook/` endpoint when caller omits a URL.
		if ( $webhook_url === '' ) {
			$webhook_url = ! empty( $bot->webhook_url ) ? $bot->webhook_url : home_url( '/zalohook/' );
		}
		if ( $secret_token === '' ) {
			$secret_token = ! empty( $bot->webhook_secret ) ? $bot->webhook_secret : wp_generate_password( 24, false );
		}

		$result = $ctx['api']->set_webhook( $webhook_url, $secret_token );
		$ok = ! is_wp_error( $result );

		// Persist URL/secret back to DB on success.
		if ( $ok ) {
			BizCity_Zalo_Bot_Database::instance()->save_bot( array(
				'id'             => (int) $bot->id,
				'webhook_url'    => esc_url_raw( $webhook_url ),
				'webhook_secret' => sanitize_text_field( $secret_token ),
			) );
		}

		// Phase CG-Listener S2 (2026-05-30) — Verify by re-reading webhook info
		// from Zalo. Some calls return ok=true echoing previous state when Zalo
		// silently rejects (URL not yet propagated, OA not active, etc.). FE
		// needs a hard verification: getWebhookInfo right after must show our
		// URL + recent updated_at to be considered truly applied.
		$verified         = false;
		$verify_url       = '';
		$verify_updated   = 0;
		$verify_response  = null;
		$verify_error     = null;
		$now_ms           = (int) round( microtime( true ) * 1000 );
		if ( $ok ) {
			$wh_info = $ctx['api']->get_webhook_info();
			if ( is_wp_error( $wh_info ) ) {
				$verify_error = $wh_info->get_error_message();
			} elseif ( is_array( $wh_info ) ) {
				$verify_response = $wh_info;
				$wh_result       = isset( $wh_info['result'] ) && is_array( $wh_info['result'] ) ? $wh_info['result'] : $wh_info;
				$verify_url      = isset( $wh_result['url'] ) ? (string) $wh_result['url'] : '';
				$verify_updated  = isset( $wh_result['updated_at'] ) ? (int) $wh_result['updated_at'] : 0;
				// "Recent" = updated_at within last 60s of server clock.
				$is_recent = $verify_updated > 0 && ( $now_ms - $verify_updated ) < 60000;
				$verified  = ( $verify_url === $webhook_url ) && $is_recent;
			}
		}

		return rest_ensure_response( array(
			'ok'              => $ok,
			'verified'        => $verified,
			'webhook_url'     => $webhook_url,
			'secret_token'    => $secret_token,
			'response'        => is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result,
			'verify' => array(
				'url'              => $verify_url,
				'updated_at'       => $verify_updated,
				'now_ms'           => $now_ms,
				'age_ms'           => $verify_updated > 0 ? ( $now_ms - $verify_updated ) : null,
				'matches_url'      => ( $verify_url === $webhook_url ),
				'is_recent'        => isset( $is_recent ) ? $is_recent : false,
				'response'         => $verify_response,
				'error'            => $verify_error,
			),
		) );
	}

	public function mgmt_delete_webhook( $request ) {
		$ctx = $this->load_bot_with_api( $request->get_param( 'id' ) );
		if ( is_wp_error( $ctx ) ) { return $ctx; }

		$result = $ctx['api']->delete_webhook();
		return rest_ensure_response( array(
			'ok'       => ! is_wp_error( $result ),
			'response' => is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result,
		) );
	}

	/**
	 * Setup status — single endpoint the FE wizard calls to render checklist.
	 * Returns 5 atomic checks + recent log count, so the UI can paint pass/fail badges.
	 */
	public function mgmt_setup_status( $request ) {
		$ctx = $this->load_bot_with_api( $request->get_param( 'id' ) );
		if ( is_wp_error( $ctx ) ) { return $ctx; }
		global $wpdb; // [2026-07-08 Johnny Chu] HOTFIX — required for db_counts queries below.
		$bot = $ctx['bot'];
		$api = $ctx['api'];

		$out = array(
			'bot_id'     => (int) $bot->id,
			'bot_name'   => $bot->bot_name,
			'site_https' => is_ssl(),
			'expected_webhook_url' => home_url( '/zalohook/' ),
			'checks'     => array(),
		);

		// 1. Token validity → getMe
		$me = $api->get_me();
		$me_ok = ! is_wp_error( $me ) && is_array( $me ) && empty( $me['error'] );
		$out['checks'][] = array(
			'id'      => 'token',
			'label'   => 'Bot token hợp lệ (getMe)',
			'status'  => $me_ok ? 'pass' : 'fail',
			'detail'  => is_wp_error( $me ) ? $me->get_error_message() : ( $me['result']['username'] ?? ( $me['result']['id'] ?? 'OK' ) ),
		);

		// 2. OA info
		$oa = method_exists( $api, 'get_oa_info' ) ? $api->get_oa_info() : null;
		$oa_ok = ! is_wp_error( $oa ) && is_array( $oa );
		$out['checks'][] = array(
			'id'     => 'oa_info',
			'label'  => 'OA info đọc được',
			'status' => $oa_ok ? 'pass' : 'warn',
			'detail' => is_wp_error( $oa ) ? $oa->get_error_message() : ( $oa['name'] ?? '—' ),
		);

		// 3. Webhook info
		$wh = $api->get_webhook_info();
		$wh_ok    = ! is_wp_error( $wh ) && is_array( $wh );
		$wh_url   = $wh_ok ? ( $wh['result']['url'] ?? ( $wh['url'] ?? '' ) ) : '';
		$wh_match = $wh_url && stripos( $wh_url, '/zalohook' ) !== false;
		$out['checks'][] = array(
			'id'     => 'webhook_registered',
			'label'  => 'Webhook đã đăng ký với Zalo',
			'status' => $wh_ok ? ( $wh_url ? 'pass' : 'warn' ) : 'fail',
			'detail' => $wh_ok ? ( $wh_url ?: '(chưa set)' ) : $wh->get_error_message(),
		);
		$out['checks'][] = array(
			'id'     => 'webhook_url_match',
			'label'  => 'Webhook URL trỏ về site này',
			'status' => $wh_match ? 'pass' : ( $wh_url ? 'warn' : 'fail' ),
			'detail' => $wh_url ?: 'chưa có',
		);

		// 4. HTTPS
		$out['checks'][] = array(
			'id'     => 'https',
			'label'  => 'Site HTTPS (Zalo yêu cầu)',
			'status' => is_ssl() ? 'pass' : 'fail',
			'detail' => home_url(),
		);

		// 5. Recent webhook log count (today)
		$today = gmdate( 'Y-m-d' );
		$dir   = wp_upload_dir();
		$file  = trailingslashit( $dir['basedir'] ) . 'bizcity-cg-logs/' . $today . '.jsonl';
		$counts = array( 'zalo_webhook_raw' => 0, 'zalo_message_in' => 0, 'zalo_event' => 0 );
		if ( file_exists( $file ) ) {
			$fp = @fopen( $file, 'rb' );
			if ( $fp ) {
				while ( ( $line = fgets( $fp ) ) !== false ) {
					foreach ( $counts as $ch => $_n ) {
						if ( strpos( $line, '"channel":"' . $ch . '"' ) !== false ) {
							$counts[ $ch ]++;
						}
					}
				}
				fclose( $fp );
			}
		}
		$log_total = array_sum( $counts );
		$out['checks'][] = array(
			'id'     => 'logs_today',
			'label'  => 'Có log webhook hôm nay (' . $today . ')',
			'status' => $log_total > 0 ? 'pass' : 'warn',
			'detail' => 'raw=' . $counts['zalo_webhook_raw']
				. ', msg_in=' . $counts['zalo_message_in']
				. ', event=' . $counts['zalo_event'],
		);
		$out['log_counts'] = $counts;
		$out['log_total']  = $log_total;
		$out['log_date']   = $today;

		// [2026-07-08 Johnny Chu] HOTFIX — per-bot inbound counters from DB so
		// admin can detect "UI polling works but selected bot has no inbound rows".
		$tbl_logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		$db_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl_logs} WHERE bot_id = %d",
			(int) $bot->id
		) );
		$db_inbound = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			   FROM {$tbl_logs}
			  WHERE bot_id = %d
			    AND event_name <> 'bot.reply'
			    AND ( event_name LIKE 'message.%%' OR event_name LIKE 'user.send.%%' )",
			(int) $bot->id
		) );
		$db_last_at = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(created_at) FROM {$tbl_logs} WHERE bot_id = %d",
			(int) $bot->id
		) );

		$out['checks'][] = array(
			'id'     => 'bot_inbound_db',
			'label'  => 'Inbound message vào DB theo bot đang chọn',
			'status' => $db_inbound > 0 ? 'pass' : 'warn',
			'detail' => 'inbound=' . $db_inbound . ', total=' . $db_total . ', last=' . ( $db_last_at ?: 'never' ),
		);
		$out['db_counts'] = array(
			'bot_total'   => $db_total,
			'bot_inbound' => $db_inbound,
			'bot_last_at' => $db_last_at,
		);

		// Overall verdict
		$has_fail = false;
		foreach ( $out['checks'] as $c ) { if ( $c['status'] === 'fail' ) { $has_fail = true; break; } }
		$out['overall'] = $has_fail ? 'fail' : 'pass';

		return rest_ensure_response( $out );
	}
}
