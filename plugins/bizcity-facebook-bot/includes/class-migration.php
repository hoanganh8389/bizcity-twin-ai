<?php
/**
 * BizCity Facebook Bot - Database Migration
 * Handles migration from legacy bizgpt_* tables to bizcity_facebook_* tables
 * 
 * Migration logic:
 * 1. If new tables don't exist → RENAME old tables to new names
 * 2. If new tables already exist → DROP old tables
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Facebook_Bot_Migration {
	
	/**
	 * Current migration version
	 */
	const VERSION = '2.3.0';
	
	/**
	 * Option key for tracking migration version
	 */
	const VERSION_OPTION = 'bizcity_facebook_bot_migration_version';
	
	/**
	 * Flag to prevent multiple runs per request
	 */
	private static $migration_run = false;
	
	/**
	 * Table mapping: old_name => new_name (without prefix)
	 */
	private static $table_mapping = array(
		'bizgpt_inbox_customer' => 'bizcity_facebook_customers',
		'bizgpt_inbox'          => 'bizcity_facebook_inbox',
		'bizgpt_inbox_comment'  => 'bizcity_facebook_comments',
	);
	
	/**
	 * Tables to drop after migration (no mapping, just cleanup)
	 */
	private static $tables_to_drop = array(
		'bizgpt_comment_flows',
	);
	
	/**
	 * Run migration if needed
	 */
	public static function maybe_migrate() {
		// Prevent multiple runs per request
		if ( self::$migration_run ) {
			return;
		}
		self::$migration_run = true;
		
		$current_version = get_option( self::VERSION_OPTION, '' );
		
		// Already at current version - skip all checks
		if ( $current_version === self::VERSION ) {
			return;
		}
		
		// Check customers table structure once (tracked by option)
		$customers_fixed = get_option( 'bizcity_fb_customers_table_fixed', false );
		if ( ! $customers_fixed ) {
			self::fix_customers_table_structure();
			update_option( 'bizcity_fb_customers_table_fixed', '2.1.0', true );
		}
		
		// Check inbox table structure once (add sender_type, attachment_url columns)
		$inbox_fixed = get_option( 'bizcity_fb_inbox_table_fixed', false );
		if ( ! $inbox_fixed ) {
			self::fix_inbox_table_structure();
			update_option( 'bizcity_fb_inbox_table_fixed', '2.2.0', true );
		}
		
		// Fix message_id column size (Facebook message IDs can be very long)
		$inbox_msgid_fixed = get_option( 'bizcity_fb_inbox_msgid_fixed', false );
		if ( ! $inbox_msgid_fixed ) {
			self::fix_inbox_message_id_column();
			update_option( 'bizcity_fb_inbox_msgid_fixed', '2.3.0', true );
		}
		
		// Run full migration only if version changed
		self::run_migration();
		
		// Update version
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}
	
	/**
	 * Force run migration (for manual trigger)
	 */
	public static function force_migrate() {
		// Reset flag to allow force run
		self::$migration_run = false;
		
		self::run_migration();
		update_option( self::VERSION_OPTION, self::VERSION, true );
		
		self::$migration_run = true;
	}
	
	/**
	 * Main migration logic
	 */
	private static function run_migration() {
		global $wpdb;
		
		// Log start
		self::log( 'Starting migration to version ' . self::VERSION );
		
		// Process each table mapping
		foreach ( self::$table_mapping as $old_suffix => $new_suffix ) {
			$old_table = $wpdb->prefix . $old_suffix;
			$new_table = $wpdb->prefix . $new_suffix;
			
			$old_exists = self::table_exists( $old_table );
			$new_exists = self::table_exists( $new_table );
			
			self::log( "Checking: $old_suffix → $new_suffix | Old exists: " . ( $old_exists ? 'YES' : 'NO' ) . " | New exists: " . ( $new_exists ? 'YES' : 'NO' ) );
			
			if ( $old_exists && ! $new_exists ) {
				// Case 1: Old exists, new doesn't → RENAME
				self::rename_table( $old_table, $new_table );
			} elseif ( $old_exists && $new_exists ) {
				// Case 2: Both exist → DROP old table
				self::drop_table( $old_table );
			}
			// Case 3: Old doesn't exist → nothing to do
		}
		
		// Drop tables that have no mapping (cleanup)
		foreach ( self::$tables_to_drop as $table_suffix ) {
			$table = $wpdb->prefix . $table_suffix;
			if ( self::table_exists( $table ) ) {
				self::drop_table( $table );
			}
		}
		
		// Check and fix bizcity_facebook_customers table structure
		self::fix_customers_table_structure();
		
		// Ensure new tables exist (create if not)
		self::ensure_new_tables();
		
		// Clean up old options
		self::cleanup_old_options();
		
		self::log( 'Migration completed' );
	}
	
	/**
	 * Fix bizcity_facebook_customers table if it has old structure
	 * Old structure is missing: page_id, phone, first_contact, last_contact, created_at
	 */
	private static function fix_customers_table_structure() {
		global $wpdb;
		
		$table = $wpdb->prefix . 'bizcity_facebook_customers';
		
		if ( ! self::table_exists( $table ) ) {
			return;
		}
		
		// Check if required columns exist
		$required_columns = array( 'page_id', 'phone', 'first_contact', 'last_contact', 'created_at' );
		$existing_columns = self::get_table_columns( $table );
		
		$missing_columns = array_diff( $required_columns, $existing_columns );
		
		if ( ! empty( $missing_columns ) ) {
			self::log( "bizcity_facebook_customers has old structure. Missing columns: " . implode( ', ', $missing_columns ) );
			self::log( "Dropping table to recreate with correct structure..." );
			self::drop_table( $table );
			
			// Recreate table with correct structure
			if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
				BizCity_Facebook_Bot_Database::activate();
				self::log( "✓ Recreated bizcity_facebook_customers with correct structure" );
			}
		}
	}
	
	/**
	 * Fix bizcity_facebook_inbox table if missing sender_type and attachment_url columns
	 * Old structure is missing: sender_type, attachment_url
	 */
	private static function fix_inbox_table_structure() {
		global $wpdb;
		
		$table = $wpdb->prefix . 'bizcity_facebook_inbox';
		
		if ( ! self::table_exists( $table ) ) {
			return;
		}
		
		// Check if required columns exist
		$required_columns = array( 'sender_type', 'attachment_url' );
		$existing_columns = self::get_table_columns( $table );
		
		$missing_columns = array_diff( $required_columns, $existing_columns );
		
		if ( ! empty( $missing_columns ) ) {
			self::log( "bizcity_facebook_inbox has old structure. Missing columns: " . implode( ', ', $missing_columns ) );
			
			// Add missing columns using ALTER TABLE (preserve existing data)
			$wpdb->suppress_errors( true );
			
			if ( in_array( 'sender_type', $missing_columns ) ) {
				$result = $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `sender_type` varchar(20) DEFAULT 'client' AFTER `message_type`" );
				if ( $result !== false ) {
					self::log( "✓ Added sender_type column to bizcity_facebook_inbox" );
				} else {
					self::log( "✗ Failed to add sender_type column: " . $wpdb->last_error );
				}
			}
			
			if ( in_array( 'attachment_url', $missing_columns ) ) {
				$result = $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `attachment_url` text AFTER `sender_type`" );
				if ( $result !== false ) {
					self::log( "✓ Added attachment_url column to bizcity_facebook_inbox" );
				} else {
					self::log( "✗ Failed to add attachment_url column: " . $wpdb->last_error );
				}
			}
			
			$wpdb->suppress_errors( false );
		} else {
			self::log( "bizcity_facebook_inbox table structure is up to date" );
		}
	}
	
	/**
	 * Fix message_id column size in bizcity_facebook_inbox table
	 * Facebook message IDs can be 80+ characters, need varchar(255)
	 */
	private static function fix_inbox_message_id_column() {
		global $wpdb;
		
		$table = $wpdb->prefix . 'bizcity_facebook_inbox';
		
		if ( ! self::table_exists( $table ) ) {
			return;
		}
		
		self::log( "Checking message_id column size in bizcity_facebook_inbox..." );
		
		// Get current column info
		$column_info = $wpdb->get_row( "SHOW COLUMNS FROM `$table` WHERE Field = 'message_id'" );
		
		if ( $column_info ) {
			$current_type = strtolower( $column_info->Type );
			self::log( "Current message_id type: " . $current_type );
			
			// Check if it's varchar with size less than 255
			if ( preg_match( '/varchar\((\d+)\)/', $current_type, $matches ) ) {
				$current_size = intval( $matches[1] );
				
				if ( $current_size < 255 ) {
					self::log( "message_id size is $current_size, upgrading to varchar(255)..." );
					
					$wpdb->suppress_errors( true );
					$result = $wpdb->query( "ALTER TABLE `$table` MODIFY COLUMN `message_id` varchar(255) DEFAULT NULL" );
					$wpdb->suppress_errors( false );
					
					if ( $result !== false ) {
						self::log( "✓ Upgraded message_id to varchar(255)" );
					} else {
						self::log( "✗ Failed to upgrade message_id: " . $wpdb->last_error );
					}
				} else {
					self::log( "message_id size is already $current_size (OK)" );
				}
			}
		} else {
			self::log( "message_id column not found" );
		}
	}
	
	/**
	 * Get column names of a table
	 */
	private static function get_table_columns( $table_name ) {
		global $wpdb;
		
		$wpdb->suppress_errors( true );
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table_name`", 0 );
		$wpdb->suppress_errors( false );
		
		return is_array( $columns ) ? $columns : array();
	}
	
	/**
	 * Check if a table exists
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;
		
		$wpdb->suppress_errors( true );
		$result = $wpdb->get_var( $wpdb->prepare(
			"SHOW TABLES LIKE %s",
			$table_name
		) );
		$wpdb->suppress_errors( false );
		
		return ! empty( $result );
	}
	
	/**
	 * Rename a table
	 */
	private static function rename_table( $old_name, $new_name ) {
		global $wpdb;
		
		$wpdb->suppress_errors( true );
		$result = $wpdb->query( "RENAME TABLE `$old_name` TO `$new_name`" );
		$wpdb->suppress_errors( false );
		
		if ( $result !== false ) {
			self::log( "✓ Renamed table: $old_name → $new_name" );
			return true;
		} else {
			self::log( "✗ Failed to rename table: $old_name → $new_name | Error: " . $wpdb->last_error );
			return false;
		}
	}
	
	/**
	 * Drop a table
	 */
	private static function drop_table( $table_name ) {
		global $wpdb;
		
		$wpdb->suppress_errors( true );
		$result = $wpdb->query( "DROP TABLE IF EXISTS `$table_name`" );
		$wpdb->suppress_errors( false );
		
		if ( $result !== false ) {
			self::log( "✓ Dropped table: $table_name" );
			return true;
		} else {
			self::log( "✗ Failed to drop table: $table_name | Error: " . $wpdb->last_error );
			return false;
		}
	}
	
	/**
	 * Ensure all new tables exist
	 */
	private static function ensure_new_tables() {
		// Call the Database class activate method to create any missing tables
		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			BizCity_Facebook_Bot_Database::activate();
			self::log( '✓ Ensured all new tables exist via BizCity_Facebook_Bot_Database::activate()' );
		}
	}
	
	/**
	 * Clean up old options
	 */
	private static function cleanup_old_options() {
		// Remove old version tracking options
		delete_option( 'bizgpt_inbox_db_version' );
		
		self::log( '✓ Cleaned up old options' );
	}
	
	/**
	 * Log migration events
	 */
	private static function log( $message ) {
		$log_file = dirname( __DIR__ ) . '/logs/migration-' . date( 'Y-m-d' ) . '.log';
		$log_dir = dirname( $log_file );
		
		// Create logs directory if not exists
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}
		
		$timestamp = date( 'Y-m-d H:i:s' );
		$blog_id = get_current_blog_id();
		
		$log_entry = "[{$timestamp}] [Blog: {$blog_id}] {$message}\n";
		
		file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
	}
	
	/**
	 * Get migration status for admin display
	 */
	public static function get_status() {
		global $wpdb;
		
		$status = array(
			'current_version' => get_option( self::VERSION_OPTION, 'Not migrated' ),
			'target_version'  => self::VERSION,
			'tables'          => array(),
		);
		
		// Check old tables
		foreach ( self::$table_mapping as $old_suffix => $new_suffix ) {
			$old_table = $wpdb->prefix . $old_suffix;
			$new_table = $wpdb->prefix . $new_suffix;
			
			$status['tables'][ $old_suffix ] = array(
				'old_exists' => self::table_exists( $old_table ),
				'new_exists' => self::table_exists( $new_table ),
				'new_name'   => $new_suffix,
			);
		}
		
		// Check cleanup tables
		foreach ( self::$tables_to_drop as $table_suffix ) {
			$table = $wpdb->prefix . $table_suffix;
			$status['tables'][ $table_suffix ] = array(
				'old_exists' => self::table_exists( $table ),
				'new_exists' => false,
				'new_name'   => '(will be dropped)',
			);
		}
		
		return $status;
	}
}
