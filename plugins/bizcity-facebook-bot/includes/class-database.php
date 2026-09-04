<?php
/**
 * BizCity Facebook Bot - Database Manager
 * Handles database table creation and queries
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Facebook_Bot_Database {
	
	private static $instance = null;
	private static $migration_checked = false;
	private static $tables_created = false;
	
	/**
	 * Current schema version for tables
	 */
	const SCHEMA_VERSION = '1.2.0';
	
	/**
	 * Get singleton instance
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	// [2026-06-21 Johnny Chu] HOTFIX — per-blog guard for multisite cron/switch_to_blog contexts.
	private static $activated_blog_ids = array();

	/**
	 * Activation hook - create tables
	 * Only runs dbDelta if schema version changed
	 */
	public static function activate() {
		// [2026-06-21 Johnny Chu] HOTFIX — use per-blog guard instead of request-wide bool
		// so switch_to_blog() in multisite does not skip un-provisioned sub-sites.
		$blog_id = (int) get_current_blog_id();
		if ( isset( self::$activated_blog_ids[ $blog_id ] ) ) {
			return;
		}
		self::$activated_blog_ids[ $blog_id ] = true;
		
		global $wpdb;
		
		$charset_collate = $wpdb->get_charset_collate();
		
		// Bots/Pages table
		$table_bots = $wpdb->prefix . 'bizcity_facebook_bots';
		$sql_bots = "CREATE TABLE IF NOT EXISTS $table_bots (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			bot_name varchar(255) NOT NULL,
			page_id varchar(100) NOT NULL,
			page_access_token text NOT NULL,
			app_id varchar(100) DEFAULT '',
			app_secret varchar(255) DEFAULT '',
			verify_token varchar(100) DEFAULT 'bizgpt',
			user_id bigint(20) DEFAULT 0,
			ai_enabled tinyint(1) DEFAULT 0,
			openai_api_key varchar(255) DEFAULT '',
			ai_prompt text,
			status varchar(20) DEFAULT 'active',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY page_id (page_id),
			KEY user_id (user_id)
		) $charset_collate;";
		
		// Inbox table for conversations
		$table_inbox = $wpdb->prefix . 'bizcity_facebook_inbox';
		$sql_inbox = "CREATE TABLE IF NOT EXISTS $table_inbox (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			bot_id bigint(20) NOT NULL DEFAULT 0,
			client_id varchar(100) DEFAULT NULL,
			client_name varchar(255) DEFAULT NULL,
			platform_type varchar(20) DEFAULT 'FB_MESS',
			page_id varchar(100) DEFAULT NULL,
			message_id varchar(255) DEFAULT NULL,
			message_text text DEFAULT NULL,
			message_type varchar(20) DEFAULT 'text',
			sender_type varchar(20) DEFAULT 'client',
			attachment_url text,
			blog_id int(11) DEFAULT 0,
			flow_id int(11) DEFAULT 0,
			reminded_at datetime DEFAULT NULL,
			reminder_msg_id int(11) DEFAULT 0,
			meta text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY bot_id (bot_id),
			KEY client_id (client_id),
			KEY page_id (page_id),
			KEY flow_id (flow_id),
			KEY created_at (created_at)
		) $charset_collate;";
		
		// Customer profiles table
		$table_customers = $wpdb->prefix . 'bizcity_facebook_customers';
		$sql_customers = "CREATE TABLE IF NOT EXISTS $table_customers (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			client_id varchar(100) NOT NULL,
			page_id varchar(100) DEFAULT '',
			name varchar(255) DEFAULT '',
			email varchar(255) DEFAULT '',
			phone varchar(50) DEFAULT '',
			profile_pic text,
			fb_link varchar(500) DEFAULT '',
			first_contact datetime DEFAULT NULL,
			last_contact datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY client_page (client_id, page_id),
			KEY client_id (client_id),
			KEY page_id (page_id)
		) $charset_collate;";
		
		// Comment logs table
		$table_comments = $wpdb->prefix . 'bizcity_facebook_comments';
		$sql_comments = "CREATE TABLE IF NOT EXISTS $table_comments (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			bot_id bigint(20) NOT NULL DEFAULT 0,
			page_id varchar(100) DEFAULT '',
			post_id varchar(200) DEFAULT '',
			post_type varchar(50) DEFAULT 'feed',
			comment_id varchar(200) DEFAULT '',
			parent_comment_id varchar(200) DEFAULT NULL,
			sender_id varchar(100) DEFAULT '',
			sender_name varchar(255) DEFAULT '',
			message text,
			ai_reply text,
			is_replied tinyint(1) DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY page_id (page_id),
			KEY post_id (post_id),
			KEY comment_id (comment_id),
			KEY sender_id (sender_id)
		) $charset_collate;";
		
		// Use dbDelta for better table creation
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		
		dbDelta( $sql_bots );
		dbDelta( $sql_inbox );
		dbDelta( $sql_customers );
		dbDelta( $sql_comments );
		
		// Run migrations for existing installations
		self::run_migrations();
		
		// Set option to track installation
		update_option( 'bizcity_facebook_bot_db_version', self::SCHEMA_VERSION );
	}
	
	/**
	 * Run database migrations
	 */
	public static function run_migrations() {
		if ( self::$migration_checked ) {
			return;
		}
		self::$migration_checked = true;
		
		$current_version = get_option( 'bizcity_facebook_bot_db_version', '1.0.0' );
		
		// Migrate to 1.1.0 - add missing columns to inbox table
		if ( version_compare( $current_version, '1.1.0', '<' ) ) {
			self::migrate_to_1_1_0();
		}
		
		// Migrate to 1.2.0 - add user_id column to bots table
		if ( version_compare( $current_version, '1.2.0', '<' ) ) {
			self::migrate_to_1_2_0();
		}
	}
	
	/**
	 * Migration to v1.1.0
	 * Add platform_type, blog_id, flow_id, reminded_at, reminder_msg_id, meta columns to inbox table
	 */
	private static function migrate_to_1_1_0() {
		global $wpdb;
		
		$table_inbox = $wpdb->prefix . 'bizcity_facebook_inbox';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_inbox'" );
		if ( ! $table_exists ) {
			return;
		}
		
		// Get existing columns
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table_inbox" );
		
		// Add platform_type column after client_name
		if ( ! in_array( 'platform_type', $columns ) ) {
			$wpdb->query( "ALTER TABLE $table_inbox ADD COLUMN platform_type varchar(20) DEFAULT 'FB_MESS' AFTER client_name" );
		}
		
		// Add blog_id column after attachment_url
		if ( ! in_array( 'blog_id', $columns ) ) {
			$wpdb->query( "ALTER TABLE $table_inbox ADD COLUMN blog_id int(11) DEFAULT 0 AFTER attachment_url" );
		}
		
		// Add flow_id column after blog_id
		if ( ! in_array( 'flow_id', $columns ) ) {
			$wpdb->query( "ALTER TABLE $table_inbox ADD COLUMN flow_id int(11) DEFAULT 0 AFTER blog_id" );
			$wpdb->query( "ALTER TABLE $table_inbox ADD KEY flow_id (flow_id)" );
		}
		
		// Add reminded_at column after flow_id
		if ( ! in_array( 'reminded_at', $columns ) ) {
			$wpdb->query( "ALTER TABLE $table_inbox ADD COLUMN reminded_at datetime DEFAULT NULL AFTER flow_id" );
		}
		
		// Add reminder_msg_id column after reminded_at
		if ( ! in_array( 'reminder_msg_id', $columns ) ) {
			$wpdb->query( "ALTER TABLE $table_inbox ADD COLUMN reminder_msg_id int(11) DEFAULT 0 AFTER reminded_at" );
		}
		
		// Add meta column after reminder_msg_id
		if ( ! in_array( 'meta', $columns ) ) {
			$wpdb->query( "ALTER TABLE $table_inbox ADD COLUMN meta text AFTER reminder_msg_id" );
		}
		
		update_option( 'bizcity_facebook_bot_db_version', '1.1.0' );
	}
	
	/**
	 * Migration to v1.2.0
	 * Add user_id column to bots table for per-user Facebook Developer apps
	 */
	private static function migrate_to_1_2_0() {
		global $wpdb;
		
		$table_bots = $wpdb->prefix . 'bizcity_facebook_bots';
		/*
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_bots'" );
		if ( ! $table_exists ) {
			return;
		}*/
		
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table_bots" );
		
		if ( ! in_array( 'user_id', $columns ) ) {
			$wpdb->query( "ALTER TABLE $table_bots ADD COLUMN user_id bigint(20) DEFAULT 0 AFTER page_access_token" );
			$wpdb->query( "ALTER TABLE $table_bots ADD KEY user_id (user_id)" );
		}
		
		update_option( 'bizcity_facebook_bot_db_version', '1.2.0' );
	}
	
	/**
	 * Get all active bots/pages
	 */
	public function get_active_bots() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		return $wpdb->get_results( "SELECT * FROM $table WHERE status = 'active' ORDER BY id DESC" );
	}
	
	/**
	 * Get all bots including inactive
	 */
	public function get_all_bots() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		return $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC" );
	}
	
	/**
	 * Get bot by ID
	 */
	public function get_bot( $bot_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $bot_id ) );
	}
	
	/**
	 * Get bot by Page ID
	 */
	public function get_bot_by_page_id( $page_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE page_id = %s AND status = 'active'", $page_id ) );
	}
	
	/**
	 * Create or update bot
	 */
	public function save_bot( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		
		if ( isset( $data['id'] ) && $data['id'] > 0 ) {
			// Update
			$wpdb->update( $table, $data, array( 'id' => $data['id'] ) );
			return $data['id'];
		} else {
			// Insert
			unset( $data['id'] );
			$wpdb->insert( $table, $data );
			return $wpdb->insert_id;
		}
	}
	
	/**
	 * Delete bot
	 */
	public function delete_bot( $bot_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		return $wpdb->delete( $table, array( 'id' => $bot_id ) );
	}
	
	/**
	 * Insert new bot (alias for save_bot)
	 */
	public function insert_bot( $data ) {
		unset( $data['id'] );
		return $this->save_bot( $data );
	}
	
	/**
	 * Update existing bot (alias for save_bot)
	 */
	public function update_bot( $bot_id, $data ) {
		$data['id'] = $bot_id;
		return $this->save_bot( $data );
	}
	
	/**
	 * Get active bots/pages owned by a specific user
	 */
	public function get_bots_by_user( $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE user_id = %d AND status = 'active' ORDER BY id DESC",
			$user_id
		) );
	}
	
	/**
	 * Get bot by user_id and page_id
	 */
	public function get_bot_by_user_page( $user_id, $page_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table WHERE user_id = %d AND page_id = %s AND status = 'active'",
			$user_id, $page_id
		) );
	}
	
	/**
	 * Get site-admin bots (user_id = 0)
	 */
	public function get_admin_bots() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		return $wpdb->get_results(
			"SELECT * FROM $table WHERE (user_id = 0 OR user_id IS NULL) AND status = 'active' ORDER BY id DESC"
		);
	}
	
	/**
	 * Insert log entry (alias for log_event)
	 */
	public function insert_log( $bot_id, $log_type, $log_data ) {
		// [2026-08-27 Johnny Chu] PHASE-1.30-LIFECYCLE — write legacy Facebook log owner path to canonical channel JSONL first.
		$event_data = is_string( $log_data ) ? $log_data : wp_json_encode( $log_data );
		$jsonl_ok   = $this->write_channel_log( array(
			'bot_id'          => (int) $bot_id,
			'event_name'      => (string) $log_type,
			'event_data'      => $event_data,
			'client_id'       => '',
			'user_id'         => '',
			'message_id'      => '',
			'display_name'    => '',
			'text'            => '',
			'attachment_type' => '',
			'attachment_url'  => '',
		) );
		if ( $jsonl_ok ) {
			return 1;
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — JSONL failure is fail-closed; the retired SQL projection is never written.
		return 0;
	}
	
	/**
	 * Log event
	 */
	public function log_event( $bot_id, $event_name, $event_data, $client_id = '', $message_id = '', $display_name = '', $text = '', $attachment_type = '', $attachment_url = '' ) {
		// [2026-08-27 Johnny Chu] PHASE-1.30-LIFECYCLE — use canonical channel JSONL contract before legacy SQL fallback.
		$event_data_json = is_array( $event_data ) ? wp_json_encode( $event_data ) : (string) $event_data;
		$jsonl_ok = $this->write_channel_log( array(
			'bot_id'          => (int) $bot_id,
			'event_name'      => (string) $event_name,
			'event_data'      => $event_data_json,
			'client_id'       => (string) $client_id,
			'user_id'         => (string) $client_id,
			'message_id'      => (string) $message_id,
			'display_name'    => (string) $display_name,
			'text'            => (string) $text,
			'attachment_type' => (string) $attachment_type,
			'attachment_url'  => (string) $attachment_url,
		) );
		if ( $jsonl_ok ) {
			return 1;
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — JSONL failure is fail-closed; the retired SQL projection is never written.
		return 0;
	}
	
	/**
	 * Get logs
	 * @param int|array $args Either bot_id (int) for simple call, or array of args
	 * @param int $limit Optional limit when first param is bot_id
	 */
	public function get_logs( $args = array(), $limit = 50 ) {
		// Support simple call: get_logs( $bot_id, $limit )
		if ( is_numeric( $args ) ) {
			$args = array(
				'bot_id' => intval( $args ),
				'limit'  => intval( $limit ),
			);
		}
		
		$defaults = array(
			'bot_id'     => 0,
			'event_name' => '',
			'client_id'  => '',
			'limit'      => 50,
			'offset'     => 0,
		);
		
		$args = wp_parse_args( $args, $defaults );

		// [2026-08-27 Johnny Chu] PHASE-1.30-LIFECYCLE — read canonical channel JSONL history first for admin/history parity.
		$jsonl_rows = $this->read_channel_logs( $args );
		if ( is_array( $jsonl_rows ) ) {
			return $jsonl_rows;
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — logger unavailable means stable empty history, never SQL fallback.
		return array();
	}
	
	/**
	 * Get unique user IDs (clients who messaged)
	 */
	public function get_user_ids( $bot_id = 0 ) {
		// [2026-08-27 Johnny Chu] PHASE-1.30-LIFECYCLE — aggregate user list from canonical channel JSONL when available.
		$jsonl_rows = $this->get_logs( array( 'bot_id' => (int) $bot_id, 'limit' => 2000 ) );
		if ( is_array( $jsonl_rows ) && ! empty( $jsonl_rows ) ) {
			$agg = array();
			foreach ( $jsonl_rows as $row ) {
				$client_id = isset( $row->client_id ) ? (string) $row->client_id : '';
				if ( $client_id === '' ) {
					$client_id = isset( $row->user_id ) ? (string) $row->user_id : '';
				}
				if ( $client_id === '' ) {
					continue;
				}
				$created_at = isset( $row->created_at ) ? (string) $row->created_at : '';
				if ( ! isset( $agg[ $client_id ] ) || strcmp( $created_at, (string) $agg[ $client_id ]['last_seen'] ) > 0 ) {
					$agg[ $client_id ] = array(
						'user_id'      => $client_id,
						'last_seen'    => $created_at,
						'display_name' => isset( $row->display_name ) ? (string) $row->display_name : '',
					);
				}
			}
			if ( ! empty( $agg ) ) {
				usort( $agg, static function ( $left, $right ) {
					return strcmp( (string) $right['last_seen'], (string) $left['last_seen'] );
				} );
				$agg = array_slice( $agg, 0, 50 );
				return array_map( static function ( $item ) {
					return (object) $item;
				}, $agg );
			}
		}
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — do not reconstruct user lists from retired SQL.
		return array();
	}

	private function write_channel_log( array $payload ) {
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || ! method_exists( 'BizCity_Channel_File_Logger', 'write' ) ) {
			return false;
		}
		$event_name = sanitize_text_field( (string) ( $payload['event_name'] ?? '' ) );
		if ( $event_name === '' ) {
			$event_name = 'facebook_bot_event';
		}
		$event_data = json_decode( (string) ( $payload['event_data'] ?? '' ), true );
		$page_id = is_array( $event_data ) ? (string) ( $event_data['page_id'] ?? $event_data['page']['id'] ?? '' ) : '';
		$ctx = array(
			'bot_id'          => (int) ( $payload['bot_id'] ?? 0 ),
			'account_id'      => $page_id !== '' ? $page_id : 'facebook_bot_' . (int) ( $payload['bot_id'] ?? 0 ),
			'event_name'      => $event_name,
			'event_data'      => (string) ( $payload['event_data'] ?? '' ),
			'client_id'       => (string) ( $payload['client_id'] ?? '' ),
			'user_id'         => (string) ( $payload['user_id'] ?? '' ),
			'message_id'      => (string) ( $payload['message_id'] ?? '' ),
			'display_name'    => (string) ( $payload['display_name'] ?? '' ),
			'text'            => (string) ( $payload['text'] ?? '' ),
			'attachment_type' => (string) ( $payload['attachment_type'] ?? '' ),
			'attachment_url'  => (string) ( $payload['attachment_url'] ?? '' ),
			'legacy_schema'   => 'bizcity_facebook_bot_logs',
		);
		return (bool) BizCity_Channel_File_Logger::write(
			BizCity_Channel_File_Logger::CH_FACEBOOK,
			BizCity_Channel_File_Logger::LEVEL_INFO,
			$event_name,
			'facebook_bot_event',
			$ctx
		);
	}

	private function read_channel_logs( array $args ) {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! method_exists( 'BizCity_JSONL_File_Logger', 'query_contract' ) ) {
			return null;
		}
		$limit  = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$scan   = min( 5000, $limit + $offset + 300 );
		$bot_id = (int) ( $args['bot_id'] ?? 0 );
		$event_name = (string) ( $args['event_name'] ?? '' );
		$client_id  = (string) ( $args['client_id'] ?? '' );

		$rows = BizCity_JSONL_File_Logger::query_contract( 'core.channel_gateway.facebook', array(
			'days'  => 45,
			'limit' => $scan,
			'filter' => static function ( $row ) use ( $bot_id, $event_name, $client_id ) {
				$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				if ( $bot_id > 0 && (int) ( $ctx['bot_id'] ?? 0 ) !== $bot_id ) {
					return false;
				}
				if ( $event_name !== '' && (string) ( $row['event'] ?? '' ) !== $event_name ) {
					return false;
				}
				if ( $client_id !== '' ) {
					$row_client = (string) ( $ctx['client_id'] ?? $ctx['user_id'] ?? '' );
					if ( $row_client !== $client_id ) {
						return false;
					}
				}
				return true;
			},
		) );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$rows = array_slice( $rows, $offset, $limit );
		$out  = array();
		foreach ( $rows as $row ) {
			$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
			$event_uuid = (string) ( $row['event_uuid'] ?? $ctx['event_uuid'] ?? '' );
			$ts = (string) ( $row['ts'] ?? '' );
			$created_at = str_replace( 'T', ' ', substr( $ts, 0, 19 ) );
			$id = (int) ( $ctx['legacy_log_id'] ?? 0 );
			if ( $id <= 0 ) {
				$id = abs( (int) crc32( 'fb|' . $event_uuid . '|' . $ts ) );
				if ( $id <= 0 ) {
					$id = 1;
				}
			}
			$event_data = (string) ( $ctx['event_data'] ?? '' );
			if ( $event_data === '' ) {
				$event_data = (string) ( $row['msg'] ?? '' );
			}
			$out[] = (object) array(
				'id'              => $id,
				'bot_id'          => (int) ( $ctx['bot_id'] ?? 0 ),
				'event_name'      => (string) ( $row['event'] ?? '' ),
				'event_data'      => $event_data,
				'client_id'       => (string) ( $ctx['client_id'] ?? '' ),
				'user_id'         => (string) ( $ctx['user_id'] ?? ( $ctx['client_id'] ?? '' ) ),
				'message_id'      => (string) ( $ctx['message_id'] ?? '' ),
				'display_name'    => (string) ( $ctx['display_name'] ?? '' ),
				'text'            => (string) ( $ctx['text'] ?? '' ),
				'attachment_type' => (string) ( $ctx['attachment_type'] ?? '' ),
				'attachment_url'  => (string) ( $ctx['attachment_url'] ?? '' ),
				'created_at'      => $created_at,
			);
		}
		return $out;
	}
	
	/**
	 * Save inbox message
	 */
	public function save_inbox_message( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_inbox';
		back_trace('NOTICE', 'Inserting inbox message for client_id: ' . (isset($data['client_id']) ? $data['client_id'] : '') . ', message_text: ' . (isset($data['message_text']) ? $data['message_text'] : ''));
		$wpdb->insert( $table, array(
			'client_id'       => isset( $data['client_id'] ) ? $data['client_id'] : '',
			'client_name'     => isset( $data['client_name'] ) ? $data['client_name'] : '',
			'platform_type'   => isset( $data['platform_type'] ) ? $data['platform_type'] : 'FB_MESS',
			'page_id'         => isset( $data['page_id'] ) ? $data['page_id'] : '',
			'message_id'      => isset( $data['message_id'] ) ? $data['message_id'] : '',
			'message_text'    => isset( $data['message_text'] ) ? $data['message_text'] : '',
			'message_type'    => isset( $data['message_type'] ) ? $data['message_type'] : 'text',
			'sender_type'     => isset( $data['sender_type'] ) ? $data['sender_type'] : 'client',
			'attachment_url'  => isset( $data['attachment_url'] ) ? $data['attachment_url'] : '',
			'blog_id'         => isset( $data['blog_id'] ) ? intval( $data['blog_id'] ) : 0,
			'flow_id'         => isset( $data['flow_id'] ) ? intval( $data['flow_id'] ) : 0,
			'reminded_at'     => isset( $data['reminded_at'] ) ? $data['reminded_at'] : null,
			'reminder_msg_id' => isset( $data['reminder_msg_id'] ) ? intval( $data['reminder_msg_id'] ) : 0,
			'meta'            => isset( $data['meta'] ) ? ( is_array( $data['meta'] ) ? json_encode( $data['meta'] ) : $data['meta'] ) : '',
			'created_at'      => current_time( 'mysql' ),
		) );
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Get inbox messages
	 * @param int|string $id_or_client Either bot_id (int) or client_id (string)
	 * @param int|string $limit_or_page Limit (int) when first param is bot_id, or page_id (string) when first is client_id
	 * @param int $limit Limit when using client_id + page_id
	 */
	public function get_inbox_messages( $id_or_client, $limit_or_page = 50, $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_inbox';
		
		// If first param is integer type, treat as bot_id (legacy call)
		if ( is_int( $id_or_client ) ) {
			$bot_id = intval( $id_or_client );
			$msg_limit = is_numeric( $limit_or_page ) ? intval( $limit_or_page ) : 50;
			
			$where = $bot_id > 0 ? $wpdb->prepare( "bot_id = %d", $bot_id ) : "1=1";
			
			return $wpdb->get_results( 
				$wpdb->prepare(
					"SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d",
					$msg_limit
				)
			);
		}
		
		// Otherwise treat as client_id + page_id (new call)
		$client_id = (string) $id_or_client;
		$page_id = (string) $limit_or_page;
		$msg_limit = intval( $limit );
		
		$where = $wpdb->prepare( "client_id = %s", $client_id );
		if ( ! empty( $page_id ) && $page_id !== '50' ) {
			$where .= $wpdb->prepare( " AND page_id = %s", $page_id );
		}
		
		return $wpdb->get_results( 
			$wpdb->prepare(
				"SELECT * FROM $table WHERE $where ORDER BY created_at ASC LIMIT %d",
				$msg_limit
			)
		);
	}
	
	/**
	 * Get inbox contacts (unique clients)
	 */
	public function get_inbox_contacts( $bot_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_inbox';
		
		$where = "1=1";
		if ( $bot_id > 0 ) {
			$where .= $wpdb->prepare( " AND bot_id = %d", $bot_id );
		}
		
		return $wpdb->get_results(
			"SELECT client_id, client_name, page_id, MAX(created_at) as last_message_time,
			 (SELECT message_text FROM $table t2 WHERE t2.client_id = t1.client_id ORDER BY created_at DESC LIMIT 1) as last_message
			 FROM $table t1
			 WHERE $where
			 GROUP BY client_id, page_id
			 ORDER BY last_message_time DESC
			 LIMIT 100"
		);
	}
	
	/**
	 * Save or update customer profile
	 */
	public function save_customer( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_customers';
		
		$client_id = isset( $data['client_id'] ) ? $data['client_id'] : '';
		$page_id = isset( $data['page_id'] ) ? $data['page_id'] : '';
		
		if ( empty( $client_id ) ) {
			return false;
		}
		
		// Check if exists
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE client_id = %s AND page_id = %s",
			$client_id, $page_id
		) );
		
		$now = current_time( 'mysql' );
		
		if ( $exists ) {
			// Update
			$update_data = array(
				'last_contact' => $now,
				'updated_at'   => $now,
			);
			if ( isset( $data['name'] ) && ! empty( $data['name'] ) ) {
				$update_data['name'] = $data['name'];
			}
			if ( isset( $data['profile_pic'] ) ) {
				$update_data['profile_pic'] = $data['profile_pic'];
			}
			
			$wpdb->update( $table, $update_data, array( 'id' => $exists ) );
			return $exists;
		} else {
			// Insert
			$insert_data = array(
				'client_id'     => $client_id,
				'page_id'       => $page_id,
				'name'          => isset( $data['name'] ) ? $data['name'] : '',
				'email'         => isset( $data['email'] ) ? $data['email'] : '',
				'profile_pic'   => isset( $data['profile_pic'] ) ? $data['profile_pic'] : '',
				'fb_link'       => ! empty( $client_id ) ? 'https://facebook.com/' . $client_id : '',
				'first_contact' => $now,
				'last_contact'  => $now,
			);
			$wpdb->insert( $table, $insert_data );
			return $wpdb->insert_id;
		}
	}
	
	/**
	 * Get customer by client ID
	 */
	public function get_customer( $client_id, $page_id = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_customers';
		
		if ( ! empty( $page_id ) ) {
			return $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $table WHERE client_id = %s AND page_id = %s",
				$client_id, $page_id
			) );
		}
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table WHERE client_id = %s ORDER BY last_contact DESC LIMIT 1",
			$client_id
		) );
	}
	
	/**
	 * Update customer by ID
	 */
	public function update_customer( $customer_id, $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_customers';
		
		$data['updated_at'] = current_time( 'mysql' );
		
		return $wpdb->update( $table, $data, array( 'id' => $customer_id ) );
	}
	
	/**
	 * Get all customers (for bot or all)
	 */
	public function get_customers( $bot_id = 0, $limit = 100 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_customers';
		
		$where = "1=1";
		if ( $bot_id > 0 ) {
			// Get customers who messaged this bot
			$inbox_table = $wpdb->prefix . 'bizcity_facebook_inbox';
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT c.* FROM $table c 
				 INNER JOIN (SELECT DISTINCT client_id FROM $inbox_table WHERE bot_id = %d) i 
				 ON c.client_id = i.client_id 
				 ORDER BY c.last_contact DESC LIMIT %d",
				$bot_id, $limit
			) );
		}
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table ORDER BY last_contact DESC LIMIT %d",
			$limit
		) );
	}
	
	/**
	 * Log comment (alias for save_comment)
	 */
	public function log_comment( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_comments';
		
		return $wpdb->insert( $table, array(
			'bot_id'            => isset( $data['bot_id'] ) ? $data['bot_id'] : 0,
			'page_id'           => isset( $data['page_id'] ) ? $data['page_id'] : '',
			'post_id'           => isset( $data['post_id'] ) ? $data['post_id'] : '',
			'post_type'         => isset( $data['post_type'] ) ? $data['post_type'] : 'feed',
			'comment_id'        => isset( $data['comment_id'] ) ? $data['comment_id'] : '',
			'parent_comment_id' => isset( $data['parent_comment_id'] ) ? $data['parent_comment_id'] : null,
			'sender_id'         => isset( $data['sender_id'] ) ? $data['sender_id'] : '',
			'sender_name'       => isset( $data['sender_name'] ) ? $data['sender_name'] : '',
			'message'           => isset( $data['message'] ) ? $data['message'] : '',
			'ai_reply'          => isset( $data['ai_reply'] ) ? $data['ai_reply'] : '',
			'is_replied'        => isset( $data['is_replied'] ) ? $data['is_replied'] : 0,
		) );
	}
	
	/**
	 * Save comment (alias for log_comment)
	 */
	public function save_comment( $data ) {
		return $this->log_comment( $data );
	}
	
	/**
	 * Get comments
	 */
	public function get_comments( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_comments';
		
		$defaults = array(
			'bot_id'  => 0,
			'page_id' => '',
			'post_id' => '',
			'limit'   => 100,
			'offset'  => 0,
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		$where = array( '1=1' );
		if ( $args['bot_id'] > 0 ) {
			$where[] = $wpdb->prepare( 'bot_id = %d', $args['bot_id'] );
		}
		if ( ! empty( $args['page_id'] ) ) {
			$where[] = $wpdb->prepare( 'page_id = %s', $args['page_id'] );
		}
		if ( ! empty( $args['post_id'] ) ) {
			$where[] = $wpdb->prepare( 'post_id = %s', $args['post_id'] );
		}
		
		$where_sql = implode( ' AND ', $where );
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$args['limit'], $args['offset']
		) );
	}
	
	/**
	 * Get recent clients by page_id
	 * Returns unique clients who recently messaged the page
	 */
	public function get_recent_clients_by_page( $page_id, $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_inbox';
		$customers_table = $wpdb->prefix . 'bizcity_facebook_customers';
		
		if ( empty( $page_id ) ) {
			return array();
		}
		
		// Get unique clients with their last message
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT 
				i.client_id,
				i.client_name,
				i.page_id,
				MAX(i.created_at) as last_message_time,
				(SELECT message_text FROM $table t2 WHERE t2.client_id = i.client_id AND t2.page_id = i.page_id ORDER BY created_at DESC LIMIT 1) as last_message,
				c.name as customer_name,
				c.profile_pic
			FROM $table i
			LEFT JOIN $customers_table c ON i.client_id = c.client_id AND i.page_id = c.page_id
			WHERE i.page_id = %s AND i.sender_type = 'client'
			GROUP BY i.client_id, i.page_id
			ORDER BY last_message_time DESC
			LIMIT %d",
			$page_id, $limit
		) );
	}
	
	/**
	 * Get all unique pages from connected bots and legacy fb_pages_connected option
	 */
	public function get_connected_pages() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		$pages = array();
		$seen_page_ids = array();
		
		// 1. Get from bizcity_facebook_bots table (suppress errors if table doesn't exist)
		$wpdb->suppress_errors( true );
		$bots = $wpdb->get_results(
			"SELECT id as bot_id, bot_name, page_id, page_access_token 
			 FROM $table 
			 WHERE status = 'active' AND page_id IS NOT NULL AND page_id != ''
			 ORDER BY bot_name ASC"
		);
		$wpdb->suppress_errors( false );
		
		if ( ! empty( $bots ) && ! is_wp_error( $bots ) ) {
			foreach ( $bots as $bot ) {
				if ( ! empty( $bot->page_id ) && ! in_array( $bot->page_id, $seen_page_ids ) ) {
					$pages[] = $bot;
					$seen_page_ids[] = $bot->page_id;
				}
			}
		}
		
		// 2. Get from legacy fb_pages_connected option
		$legacy_pages = get_option( 'fb_pages_connected', array() );
		if ( ! empty( $legacy_pages ) && is_array( $legacy_pages ) ) {
			foreach ( $legacy_pages as $lp ) {
				$page_id = isset( $lp['id'] ) ? $lp['id'] : '';
				if ( ! empty( $page_id ) && ! in_array( $page_id, $seen_page_ids ) ) {
					$pages[] = (object) array(
						'bot_id'            => 0,
						'bot_name'          => isset( $lp['name'] ) ? $lp['name'] : 'Page ' . $page_id,
						'page_id'           => $page_id,
						'page_access_token' => isset( $lp['access_token'] ) ? $lp['access_token'] : '',
					);
					$seen_page_ids[] = $page_id;
				}
			}
		}
		
		// 3. Get from legacy messenger_page_id option (single page config)
		$messenger_page_id = get_option( 'messenger_page_id', '' );
		$messenger_page_token = get_option( 'messenger_page_token', '' );
		if ( ! empty( $messenger_page_id ) && ! in_array( $messenger_page_id, $seen_page_ids ) ) {
			$pages[] = (object) array(
				'bot_id'            => 0,
				'bot_name'          => 'Messenger Page ' . $messenger_page_id,
				'page_id'           => $messenger_page_id,
				'page_access_token' => $messenger_page_token,
			);
			$seen_page_ids[] = $messenger_page_id;
		}
		
		return $pages;
	}
}

// No auto-init here — plugin bootstrap handles initialization
