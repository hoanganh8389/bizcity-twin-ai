<?php
/**
 * BizCity Facebook Bot Bootstrap
 * Handles plugin initialization, database setup, and includes
 * 
 * Plugin Name: BizCity Facebook Bot
 * Description: Facebook Messenger integration for WordPress with webhook listener and workflow automation
 * Version: 1.0.0
 * Author: BizCity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
if ( ! defined( 'BIZCITY_FACEBOOK_BOT_FILE' ) ) {
	define( 'BIZCITY_FACEBOOK_BOT_FILE', __FILE__ );
}

if ( ! defined( 'BIZCITY_FACEBOOK_BOT_DIR' ) ) {
	define( 'BIZCITY_FACEBOOK_BOT_DIR', __DIR__ );
}

if ( ! defined( 'BIZCITY_FACEBOOK_BOT_URL' ) ) {
	define( 'BIZCITY_FACEBOOK_BOT_URL', plugins_url( '', __FILE__ ) );
}

if ( ! defined( 'BIZCITY_FACEBOOK_BOT_VERSION' ) ) {
	define( 'BIZCITY_FACEBOOK_BOT_VERSION', '1.0.0' );
}

// Default Facebook App credentials (can be overridden in settings)
if ( ! defined( 'BIZCITY_FB_APP_ID' ) ) {
	define( 'BIZCITY_FB_APP_ID', '' );
}

if ( ! defined( 'BIZCITY_FB_APP_SECRET' ) ) {
	define( 'BIZCITY_FB_APP_SECRET', '' );
}

/**
 * Main Plugin Class
 */
class BizCity_Facebook_Bot_Plugin {
	
	private static $instance = null;
	
	/**
	 * Database version - should match BizCity_Facebook_Bot_Database::SCHEMA_VERSION
	 */
	const DB_VERSION = '1.2.0';
	
	/**
	 * Get singleton instance
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->includes();
		$this->init_hooks();
	}
	
	/**
	 * Include required files
	 */
	private function includes() {
		$base_dir = BIZCITY_FACEBOOK_BOT_DIR;
		
		// Core classes - includes
		$include_files = array(
			'/includes/class-database.php',
			'/includes/class-migration.php',
			'/includes/class-admin-menu.php',
			'/includes/class-rest-api.php',
			'/includes/class-webhook-handler.php',
			'/includes/class-central-webhook.php',
			'/includes/class-network-admin-facebook.php',
			'/includes/class-facebook-oauth.php',
			// PHASE 0.31 T-S1.4: gateway adapter (migrated from
			// plugins/bizcity-tool-facebook to fix BUG-4)
			'/includes/class-channel-adapter.php',
			// PHASE 0.31 T-S1.3: WaicChannelIntegration_facebook +
			// bizcity_register_channel_integrations filter wiring
			'/includes/integration-facebook.php',
		);
		
		foreach ( $include_files as $file ) {
			if ( file_exists( $base_dir . $file ) ) {
				require_once $base_dir . $file;
			}
		}
		
		// Library files
		$lib_files = array(
			'/lib/class-facebook-bot-api.php',
			'/lib/functions.php',
			'/lib/legacy-old-functions.php',
			'/lib/legacy-functions.php',
			'/lib/legacy-poster.php',
		);
		
		foreach ( $lib_files as $file ) {
			if ( file_exists( $base_dir . $file ) ) {
				require_once $base_dir . $file;
			}
		}
	}
	
	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Activation hook
		register_activation_hook( BIZCITY_FACEBOOK_BOT_FILE, array( $this, 'activate' ) );
		
		// Init hook for early setup — no longer runs CREATE TABLE here
		add_action( 'init', array( $this, 'init' ), 0 );
		
		// Admin scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		
		// [2026-03-17] DB check on admin only, version-gated (skips instantly when up to date)
		add_action( 'admin_init', array( $this, 'maybe_create_tables' ) );

		// Flush rewrite rules when /bizfbhook/ rule is not yet registered
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ) );
	}
	
	/**
	 * Plugin activation
	 */
	public function activate() {
		// Run migration first (rename old tables or drop duplicates)
		if ( class_exists( 'BizCity_Facebook_Bot_Migration' ) ) {
			BizCity_Facebook_Bot_Migration::force_migrate();
		}
		
		// Then create/update tables
		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			BizCity_Facebook_Bot_Database::activate();
		}
		
		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Auto-flush rewrite rules once when /bizfbhook/ rule is not yet registered.
	 */
	public function maybe_flush_rewrite_rules() {
		$rules = get_option( 'rewrite_rules', array() );
		if ( ! isset( $rules['bizfbhook/?$'] ) && ! isset( $rules['^bizfbhook/?$'] ) ) {
			flush_rewrite_rules();
		}
	}
	
	/**
	 * Init callback
	 */
	public function init() {
		// Ensure tables exist on init (for webhook requests)
		$this->maybe_create_tables_on_init();
		
		// Handle Facebook OAuth callback
		if ( function_exists( 'bizcity_fb_handle_oauth_callback' ) ) {
			bizcity_fb_handle_oauth_callback();
		}
	}
	
	/**
	 * Maybe create tables on init — version-gated, no SHOW TABLES overhead.
	 * Only runs DB operations when version option is missing (first install).
	 * @since 1.0.1 — optimized: skips instantly when db_version already set
	 */
	private function maybe_create_tables_on_init() {
		static $checked = false;
		if ( $checked ) {
			return;
		}
		$checked = true;
		
		// Fast bail if already installed
		$db_version = get_option( 'bizcity_facebook_bot_db_version', '' );
		if ( $db_version === self::DB_VERSION ) {
			return;
		}
		
		if ( empty( $db_version ) ) {
			// First install — run migration + create tables
			if ( class_exists( 'BizCity_Facebook_Bot_Migration' ) ) {
				BizCity_Facebook_Bot_Migration::maybe_migrate();
			}
			if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
				BizCity_Facebook_Bot_Database::activate();
			}
			update_option( 'bizcity_facebook_bot_db_version', self::DB_VERSION );
		}
	}
	
	/**
	 * Maybe create tables on admin init
	 */
	public function maybe_create_tables() {
		static $checked = false;
		
		// Only run once per request
		if ( $checked ) {
			return;
		}
		$checked = true;
		
		// Check if already at current version - skip everything
		$db_version = get_option( 'bizcity_facebook_bot_db_version', '' );
		$migration_version = get_option( 'bizcity_facebook_bot_migration_version', '' );
		
		// If both versions match, no need to run anything
		if ( $db_version === self::DB_VERSION && class_exists( 'BizCity_Facebook_Bot_Migration' ) && $migration_version === BizCity_Facebook_Bot_Migration::VERSION ) {
			return;
		}
		
		// Run migration to handle old tables or schema updates
		if ( class_exists( 'BizCity_Facebook_Bot_Migration' ) ) {
			BizCity_Facebook_Bot_Migration::maybe_migrate();
		}
		
		// Check for table schema updates
		if ( $db_version !== self::DB_VERSION ) {
			if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
				BizCity_Facebook_Bot_Database::activate();
			}
			update_option( 'bizcity_facebook_bot_db_version', self::DB_VERSION );
		}
	}
	
	/**
	 * Enqueue admin scripts and styles
	 */
	public function admin_scripts( $hook ) {
		// Only load on plugin pages
		$screen = get_current_screen();
		$is_plugin_page = ( 
			strpos( $hook, 'bizcity-facebook' ) !== false || 
			( $screen && strpos( $screen->id, 'bizcity-facebook' ) !== false )
		);
		
		if ( ! $is_plugin_page ) {
			return;
		}
		
		wp_enqueue_style(
			'bizcity-facebook-bot-admin',
			BIZCITY_FACEBOOK_BOT_URL . '/assets/css/admin.css',
			array(),
			BIZCITY_FACEBOOK_BOT_VERSION
		);
		
		wp_enqueue_script(
			'bizcity-facebook-bot-admin',
			BIZCITY_FACEBOOK_BOT_URL . '/assets/js/admin.js',
			array( 'jquery' ),
			BIZCITY_FACEBOOK_BOT_VERSION,
			true
		);
		
		wp_localize_script( 'bizcity-facebook-bot-admin', 'bizcityFBBot', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bizcity_facebook_bot_nonce' ),
			'strings' => array(
				'saving'   => __( 'Đang lưu...', 'bizcity-facebook-bot' ),
				'saved'    => __( 'Đã lưu', 'bizcity-facebook-bot' ),
				'error'    => __( 'Có lỗi xảy ra', 'bizcity-facebook-bot' ),
				'confirm'  => __( 'Bạn có chắc chắn?', 'bizcity-facebook-bot' ),
			),
		) );
	}
}

// Initialize plugin
add_action( 'plugins_loaded', function() {
	BizCity_Facebook_Bot_Plugin::instance();
}, 5 );
