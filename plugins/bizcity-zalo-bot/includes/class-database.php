<?php
/**
 * Database Manager
 * Handles database table creation and queries
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_Database {
	
	private static $instance = null;
	private static $migration_checked = false;
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
			// Run migration check on every load
			#self::maybe_migrate();
		}
		return self::$instance;
	}
	
	/**
	 * Activation hook - create tables
	 */
	public static function activate() {
		global $wpdb;
		
		$charset_collate = $wpdb->get_charset_collate();
		
		// Bots table
		$table_bots = $wpdb->prefix . 'bizcity_zalo_bots';
		$sql_bots = "CREATE TABLE IF NOT EXISTS $table_bots (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			bot_name varchar(255) NOT NULL,
			bot_token varchar(500) NOT NULL,
			app_id varchar(100) DEFAULT '',
			app_secret varchar(255) DEFAULT '',
			oa_id varchar(100) DEFAULT '',
			webhook_url varchar(500) DEFAULT '',
			webhook_secret varchar(100) DEFAULT '',
			status varchar(20) DEFAULT 'active',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status)
		) $charset_collate;";
		
		// Logs table
		$table_logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		$sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			bot_id bigint(20) NOT NULL,
			event_name varchar(100) NOT NULL,
			event_data longtext,
			client_id varchar(100) DEFAULT '',
			user_id varchar(100) DEFAULT '',
			message_id varchar(100) DEFAULT '',
			display_name varchar(255) DEFAULT '',
			text text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY bot_id (bot_id),
			KEY event_name (event_name),
			KEY client_id (client_id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charset_collate;";
		
		// Use dbDelta for better table creation
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		
		$result_bots = dbDelta( $sql_bots );
		$result_logs = dbDelta( $sql_logs );
		
		// Log results for debugging
		if ( ! empty( $result_bots ) || ! empty( $result_logs ) ) {
			error_log( '[BizCity Zalo Bot] dbDelta results - Bots: ' . print_r( $result_bots, true ) . ' | Logs: ' . print_r( $result_logs, true ) );
		}
		
		// Flush rewrite rules
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
		}
		
		// Run migration if needed
		self::maybe_migrate();
		
		// Set option to track installation
		update_option( 'bizcity_zalo_bot_db_version', '1.3.0' );
	}
	
	/**
	 * Maybe run database migration
	 * NOTE: Sử dụng cache và truy vấn trực tiếp wp_options để tránh lặp
	 * - Static variable: Cache trong cùng request
	 * - Transient: Cache cross-request (24 hours)
	 * - Direct $wpdb queries: Tránh hook conflicts trong multisite
	 */
	private static function maybe_migrate() {
		global $wpdb;
		
		// Cache trong cùng request - tránh check nhiều lần
		if ( ! empty( self::$migration_checked ) ) {
			return;
		}
		
		// Cache cross-request - check 1 lần/24h bằng direct query
		$cache_key = '_transient_bizcity_zalo_bot_migration_ok_' . $wpdb->prefix;
		$migration_ok = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$cache_key
		) );
		
		if ( $migration_ok === 'yes' ) {
			self::$migration_checked = true;
			return; // Migration đã OK, skip
		}
		
		$table_logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_logs'" ) === $table_logs;
		if ( ! $table_exists ) {
			self::$migration_checked = true;
			return; // Table chưa tồn tại, skip migration
		}
		
		// Get current columns with their types - SINGLE QUERY
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM $table_logs" );
		$column_info = array();
		foreach ( $columns as $col ) {
			$column_info[ $col->Field ] = $col->Type;
		}
		
		$needs_migration = false;
		
		// Migration 1: Fix user_id column type if it's INT
		if ( isset( $column_info['user_id'] ) && stripos( $column_info['user_id'], 'int' ) !== false ) {
			$wpdb->query( "ALTER TABLE $table_logs MODIFY COLUMN user_id varchar(100) DEFAULT ''" );
			error_log( '[BizCity Zalo Bot] Migration: Changed user_id from INT to VARCHAR(100)' );
			$needs_migration = true;
		}
		
		// Migration 2: Add display_name column if not exists
		if ( ! isset( $column_info['display_name'] ) ) {
			$wpdb->query( "ALTER TABLE $table_logs ADD COLUMN display_name varchar(255) DEFAULT '' AFTER message_id" );
			error_log( '[BizCity Zalo Bot] Migration: Added display_name column' );
			$needs_migration = true;
		}
		
		// Migration 3: Add text column if not exists
		if ( ! isset( $column_info['text'] ) ) {
			$wpdb->query( "ALTER TABLE $table_logs ADD COLUMN text text AFTER display_name" );
			error_log( '[BizCity Zalo Bot] Migration: Added text column' );
			$needs_migration = true;
		}
		
		// Migration 4: Add client_id column if not exists
		if ( ! isset( $column_info['client_id'] ) ) {
			$wpdb->query( "ALTER TABLE $table_logs ADD COLUMN client_id varchar(100) DEFAULT '' AFTER event_data" );
			
			// Check if index exists before adding
			$indices = $wpdb->get_results( "SHOW INDEX FROM $table_logs WHERE Key_name = 'client_id'" );
			if ( empty( $indices ) ) {
				$wpdb->query( "ALTER TABLE $table_logs ADD KEY client_id (client_id)" );
			}
			
			// Copy data from user_id to client_id
			$wpdb->query( "UPDATE $table_logs SET client_id = user_id WHERE client_id = ''" );
			error_log( '[BizCity Zalo Bot] Migration: Added client_id column and migrated data' );
			$needs_migration = true;
		}
		
		// Migration 5: Create memory table if not exists
		$table_memory = $wpdb->base_prefix . 'bizcity_zalo_bot_memory';
		$memory_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_memory'" ) === $table_memory;
		
		if ( ! $memory_exists ) {
			$charset_collate = $wpdb->get_charset_collate();
			
			$sql = "CREATE TABLE IF NOT EXISTS {$table_memory} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				bot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				client_id VARCHAR(64) NOT NULL DEFAULT '',
				user_id VARCHAR(64) NOT NULL DEFAULT '',
				memory_type VARCHAR(32) NOT NULL DEFAULT 'fact',
				memory_key VARCHAR(190) NOT NULL DEFAULT '',
				memory_text TEXT NOT NULL,
				score INT NOT NULL DEFAULT 10,
				times_seen INT NOT NULL DEFAULT 1,
				last_seen DATETIME NULL,
				source_log_ids TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_user (blog_id, bot_id, client_id, user_id),
				KEY idx_key (blog_id, bot_id, client_id, user_id, memory_key(120)),
				KEY idx_type (memory_type),
				FULLTEXT KEY ft_text (memory_text)
			) ENGINE=InnoDB {$charset_collate};";
			
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			
			dbDelta( $sql );
			error_log( '[BizCity Zalo Bot] Migration: Created memory table' );
			$needs_migration = true;
		}
		
		// Set cache - sử dụng direct query vào wp_options
		// Insert transient timeout
		$timeout_key = '_transient_timeout_bizcity_zalo_bot_migration_ok_' . $wpdb->prefix;
		$expire_time = time() + DAY_IN_SECONDS;
		
		// Delete old values first (if exists)
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
			$cache_key,
			$timeout_key
		) );
		
		// Insert new transient values
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES 
			(%s, %s, 'no'),
			(%s, %s, 'no')",
			$cache_key,
			'yes',
			$timeout_key,
			$expire_time
		) );
		
		// Update version option trực tiếp
		$version_key = $wpdb->prefix . 'bizcity_zalo_bot_db_version';
		$wpdb->replace( $wpdb->options, array(
			'option_name' => $version_key,
			'option_value' => '1.3.0',
			'autoload' => 'yes'
		) );
		
		self::$migration_checked = true;
		
		if ( $needs_migration ) {
			error_log( '[BizCity Zalo Bot] All migrations completed and cached for blog_id: ' . get_current_blog_id() );
		}
	}
	
	/**
	 * Get all active bots
	 */
	public function get_active_bots() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		return $wpdb->get_results( "SELECT * FROM $table WHERE status = 'active' ORDER BY id DESC" );
	}
	
	/**
	 * Get bot by ID
	 */
	public function get_bot( $bot_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $bot_id ) );
	}
	
	/**
	 * Create or update bot
	 */
	public function save_bot( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		
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
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		return $wpdb->delete( $table, array( 'id' => $bot_id ) );
	}
	
	/**
	 * Log event
	 */
	public function log_event( $bot_id, $event_name, $event_data, $client_id = '', $message_id = '', $display_name = '', $text = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		
		// Ensure client_id is treated as string
		$client_id_str = (string) $client_id;
		
		return $wpdb->insert( $table, array(
			'bot_id' => $bot_id,
			'event_name' => $event_name,
			'event_data' => is_array( $event_data ) ? json_encode( $event_data ) : $event_data,
			'client_id' => $client_id_str,
			'user_id' => $client_id_str, // Keep for backward compatibility
			'message_id' => $message_id,
			'display_name' => $display_name,
			'text' => $text,
		), array(
			'%d', // bot_id
			'%s', // event_name
			'%s', // event_data
			'%s', // client_id - MUST be string
			'%s', // user_id - MUST be string
			'%s', // message_id
			'%s', // display_name
			'%s', // text
		) );
	}
	
	/**
	 * Get logs
	 */
	public function get_logs( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		
		$defaults = array(
			'bot_id' => 0,
			'event_name' => '',
			'limit' => 50,
			'offset' => 0,
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		$where = array( '1=1' );
		if ( $args['bot_id'] > 0 ) {
			$where[] = $wpdb->prepare( 'bot_id = %d', $args['bot_id'] );
		}
		if ( ! empty( $args['event_name'] ) ) {
			$where[] = $wpdb->prepare( 'event_name = %s', $args['event_name'] );
		}
		
		$where_sql = implode( ' AND ', $where );
		$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		
		return $wpdb->get_results( "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC $limit_sql" );
	}
	
	/**
	 * Get unique client IDs for a bot (for testing)
	 */
	public function get_user_ids( $bot_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		
		$where = "client_id != '' AND client_id IS NOT NULL";
		if ( $bot_id > 0 ) {
			$where .= $wpdb->prepare( ' AND bot_id = %d', $bot_id );
		}
		
		return $wpdb->get_results( 
			"SELECT DISTINCT client_id as user_id, MAX(created_at) as last_seen, MAX(display_name) as display_name
			 FROM $table 
			 WHERE $where 
			 GROUP BY client_id 
			 ORDER BY last_seen DESC 
			 LIMIT 50"
		);
	}
}
