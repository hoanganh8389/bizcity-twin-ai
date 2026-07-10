<?php
/**
 * BizCity Zalo Bot Bootstrap
 * Handles plugin initialization, database setup, and includes
 * 
 * Plugin Name: BizCity Zalo Bot
 * Description: Zalo Bot integration for WordPress with webhook listener and workflow automation
 * Version: 1.4.0
 * Author: BizCity
 * Role: tool
 * Category: Tools
 * Icon Path: assets/css/admin.css
 * Credit: 0
 * Plan: free
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
if ( ! defined( 'BIZCITY_ZALO_BOT_FILE' ) ) {
	define( 'BIZCITY_ZALO_BOT_FILE', __FILE__ );
}

if ( ! defined( 'BIZCITY_ZALO_BOT_DIR' ) ) {
	define( 'BIZCITY_ZALO_BOT_DIR', __DIR__ );
}

if ( ! defined( 'BIZCITY_ZALO_BOT_URL' ) ) {
	define( 'BIZCITY_ZALO_BOT_URL', plugins_url( '', __FILE__ ) );
}

if ( ! defined( 'ZALO_BOT_VERSION' ) ) {
	define( 'ZALO_BOT_VERSION', '1.4.0' );
}

/**
 * Main Plugin Class
 */
class BizCity_Zalo_Bot_Plugin {
	
	private static $instance = null;
	
	/**
	 * Database version
	 */
	const DB_VERSION = '1.0.0';
	#const BIZCITY_ZALO_BOT_URL = plugins_url( '', __FILE__ );
	const BIZCITY_ZALO_BOT_DIR = __DIR__;
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		$this->includes();
		$this->init_hooks();
	}
	
	/**
	 * Include required files
	 */
	private function includes() {
		// Define base directory if not defined
		if ( ! defined( 'BIZCITY_ZALO_BOT_DIR' ) ) {
			define( 'BIZCITY_ZALO_BOT_DIR', __DIR__ );
		}
		
		// i18n first
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/i18n.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/i18n.php';
		}
		
		// Core classes
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-database.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-database.php';
		}
		
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-memory.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-memory.php';
		}
		
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-webhook-handler.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-webhook-handler.php';
		}
		
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-admin-menu.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-admin-menu.php';
		}
		
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-rest-api.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-rest-api.php';
		}
		
		// Dashboard & Assign Bots (Step workflow)
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-dashboard.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-dashboard.php';
		}
		
		// User Linker — Zalo user_id ↔ WP user_id binding
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-user-linker.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-user-linker.php';
		}

		// Channel Adapter (twin-ai gateway integration)
		// Guard: interface must be loaded by channel-gateway core before we can implement it
		if ( interface_exists( 'BizCity_Channel_Adapter' ) && file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-channel-adapter.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-channel-adapter.php';
		}

		// PHASE 0.31 T-S2.1 — WaicChannelIntegration_zalobot (filter-discovered).
		// Lưu ý: file SỐNG ở plugins/bizcity-twin-ai/plugins/bizcity-zalo-bot/, KHÔNG ở mu-plugins/.
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/integration-zalo.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/integration-zalo.php';
		}

		// PHASE 0.31 Sprint 6 follow-up — frontend /tool-zalo-bizcity/ profile
		// page (mirrors bizcity-tool-facebook /tool-facebook/). Provides 2-tab
		// UI (Bot OA + Hotline ZNS) editing the same WAIC integration rows
		// as the dialog, but at a public-facing slug users can bookmark.
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-tool-zalo-page.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-tool-zalo-page.php';
		}

		// Gateway Bridge (integration with bizcity-admin-hook-zalo)
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-gateway-bridge.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-gateway-bridge.php';
		}

		// PHASE-0.35 GURU-ZALO-BOT §1.6 — Guru Runtime override (opt-in).
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-guru-bridge.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-guru-bridge.php';
		}

		// [2026-06-19 Johnny Chu] ADMIN-GUIDE — explicit keyword command triggers.
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-command-router.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-command-router.php';
		}

		// Library files
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/lib/class-zalo-bot-api.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/lib/class-zalo-bot-api.php';
		}
		
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/lib/functions.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/lib/functions.php';
		}
	}
	
	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// [2026-03-17] Moved to version-gated check only — no longer runs SHOW TABLES on every init
		// Tables are created via register_activation_hook or auto-provisioned on first admin visit
		add_action( 'admin_init', array( $this, 'maybe_create_tables' ), 20 );
		// [2026-03-12] Tắt cron memory extraction — chưa cần thiết, gây nặng hệ thống
		// add_action( 'init', array( $this, 'setup_cron' ) );
		// add_action( 'bizcity_zalo_bot_daily_memory', array( $this, 'run_daily_memory_extraction' ) );
		// Xoá cron nếu đã schedule trước đó
		$ts = wp_next_scheduled( 'bizcity_zalo_bot_daily_memory' );
		if ( $ts ) { wp_unschedule_event( $ts, 'bizcity_zalo_bot_daily_memory' ); }
		register_activation_hook( BIZCITY_ZALO_BOT_FILE, array( 'BizCity_Zalo_Bot_Database', 'activate' ) );
	}
	/**
	 * Enqueue admin assets
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'bizcity-zalo-bot' ) === false && strpos( $hook, 'bizchat-zalobot' ) === false ) {
			return;
		}
		
		wp_enqueue_style( 'bizcity-zalo-bot-admin', BIZCITY_ZALO_BOT_URL . '/assets/css/admin.css', array(), ZALO_BOT_VERSION );
		wp_enqueue_script( 'bizcity-zalo-bot-admin', BIZCITY_ZALO_BOT_URL . '/assets/js/admin.js', array( 'jquery' ), ZALO_BOT_VERSION, true );
		
		wp_localize_script( 'bizcity-zalo-bot-admin', 'bizcityZaloBot', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'bizcity_zalo_bot_nonce' ),
		) );
	}
	
	/**
	 * Initialize plugin
	 */
	public function init() {
		// Initialize components with error checking
		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			BizCity_Zalo_Bot_Database::instance();
		}
		
		if ( class_exists( 'BizCity_Zalo_Bot_Memory' ) ) {
			BizCity_Zalo_Bot_Memory::instance();
		}
		
		if ( class_exists( 'BizCity_Zalo_Bot_Webhook_Handler' ) ) {
			BizCity_Zalo_Bot_Webhook_Handler::instance();
		}
		
		if ( class_exists( 'BizCity_Zalo_Bot_Admin_Menu' ) ) {
			BizCity_Zalo_Bot_Admin_Menu::instance();
		} else {
			error_log( 'BizCity Zalo Bot: Admin Menu class not found' );
		}
		
		if ( class_exists( 'BizCity_Zalo_Bot_REST_API' ) ) {
			BizCity_Zalo_Bot_REST_API::instance();
		}
		
		// Dashboard & Assign Bots (Step workflow)
		if ( class_exists( 'BizCity_Zalo_Bot_Dashboard' ) ) {
			BizCity_Zalo_Bot_Dashboard::instance();
		}
		
		// Channel Adapter — register with twin-ai Gateway Bridge
		if ( class_exists( 'BizCity_Zalo_Bot_Channel_Adapter' ) ) {
			add_action( 'bizcity_register_channel', function( $bridge ) {
				$bridge->register_adapter( new BizCity_Zalo_Bot_Channel_Adapter() );
			} );
		}

		// Gateway Bridge (integration with bizcity-admin-hook-zalo)
		if ( class_exists( 'BizCity_Zalo_Bot_Gateway_Bridge' ) ) {
			BizCity_Zalo_Bot_Gateway_Bridge::instance();
		}

		// PHASE-0.35 GURU-ZALO-BOT §1.6 — Guru-first reply path (opt-in via
		// option bizcity_zalo_guru_enabled). Must boot AFTER the legacy
		// Gateway Bridge so we can remove_action() its priority-10 hook.
		if ( class_exists( 'BizCity_Zalo_Bot_Guru_Bridge' ) ) {
			BizCity_Zalo_Bot_Guru_Bridge::instance();
		}

		// User Linker — install table + boot login callback handler
		if ( class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			BizCity_Zalobot_User_Linker::install();
			BizCity_Zalobot_User_Linker::boot_callback();
			// [2026-06-18 Johnny Chu] ADMIN-GUIDE — auto login-link + welcome message hooks
			BizCity_Zalobot_User_Linker::boot_auto_login_link();
		}

		// [2026-06-19 Johnny Chu] ADMIN-GUIDE — keyword command router (priority 4)
		if ( class_exists( 'BizCity_Zalobot_Command_Router' ) ) {
			BizCity_Zalobot_Command_Router::boot();
		}
		
		// Load text domain
		load_plugin_textdomain( 'bizcity-zalo-bot', false, dirname( plugin_basename( BIZCITY_ZALO_BOT_FILE ) ) . '/languages' );
		
		do_action( 'bizcity_zalo_bot_loaded' );
	}
	
	/**
	 * Check and create database tables if version mismatch.
	 * Only runs on admin_init (not every frontend request).
	 * Skips instantly if DB version matches — no SHOW TABLES overhead.
	 * @since 1.4.1 — moved from init to admin_init, removed SHOW TABLES per-request
	 */
	public function maybe_create_tables() {
		// Fast bail: version already matches — no DB queries needed
		$installed_version = get_option( 'bizcity_zalo_bot_db_version' );
		if ( $installed_version === self::DB_VERSION ) {
			return;
		}

		// Version mismatch or first install — create/update tables
		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			BizCity_Zalo_Bot_Database::activate();
			error_log( '[BizCity Zalo Bot] Database tables created/updated for blog_id: ' . get_current_blog_id() );
		}

		update_option( 'bizcity_zalo_bot_db_version', self::DB_VERSION, false );
	}
	
	/**
	 * Setup cron job for daily memory extraction
	 */
	public function setup_cron() {
		if ( ! wp_next_scheduled( 'bizcity_zalo_bot_daily_memory' ) ) {
			wp_schedule_event( time(), 'daily', 'bizcity_zalo_bot_daily_memory' );
		}
	}
	
	/**
	 * Run daily memory extraction for all active bots
	 */
	public function run_daily_memory_extraction() {
		error_log( '[BizCity Zalo Bot] Running daily memory extraction' );
		
		$db = BizCity_Zalo_Bot_Database::instance();
		$bots = $db->get_active_bots();
		
		if ( empty( $bots ) ) {
			error_log( '[BizCity Zalo Bot] No active bots found for memory extraction' );
			return;
		}
		
		$memory = BizCity_Zalo_Bot_Memory::instance();
		$total_inserted = 0;
		$total_updated = 0;
		
		foreach ( $bots as $bot ) {
			$result = $memory->build_from_logs( array(
				'bot_id' => $bot->id,
				'limit' => 200, // Process last 200 logs per bot
			) );
			
			if ( $result['ok'] ) {
				$total_inserted += $result['inserted'];
				$total_updated += $result['updated'];
				error_log( sprintf(
					'[BizCity Zalo Bot] Bot %s: %d logs processed, %d inserted, %d updated',
					$bot->bot_name,
					$result['count'],
					$result['inserted'],
					$result['updated']
				) );
			}
		}
		
		error_log( sprintf(
			'[BizCity Zalo Bot] Daily extraction complete: %d inserted, %d updated',
			$total_inserted,
			$total_updated
		) );
	}
}

// Initialize the plugin
BizCity_Zalo_Bot_Plugin::instance();

// Load workflow automation triggers if bizcity-automation is active
add_action( 'plugins_loaded', function() {
	// Check if WaicTrigger class exists (from bizcity-automation)
	if ( class_exists( 'WaicTrigger' ) ) {
		$trigger_dir = BIZCITY_ZALO_BOT_DIR . '/triggers/';
		
		if ( file_exists( $trigger_dir . 'wu_zalobot_message_received.php' ) ) {
			require_once $trigger_dir . 'wu_zalobot_message_received.php';
		}
		
		if ( file_exists( $trigger_dir . 'wu_zalobot_image_received.php' ) ) {
			require_once $trigger_dir . 'wu_zalobot_image_received.php';
		}
	}
}, 20 );
