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
		
		// [2026-09-01 Johnny Chu] PHASE-1.30-ZALO-MEMORY-REMOVE — legacy memory builder is intentionally not loaded.
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

		// Gateway Bridge (standalone Channel Gateway compat)
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

		// [2026-07-24 Johnny Chu] PHASE-0.46 W1 — "@notebook" channel capture bridge listener.
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-notebook-bridge-listener.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-notebook-bridge-listener.php';
		}

		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 — instant upload-link fallback
		// capture route (`/zalo-upload/{token}/`) for unsupported/no-URL events.
		if ( file_exists( BIZCITY_ZALO_BOT_DIR . '/includes/class-upload-link-handler.php' ) ) {
			require_once BIZCITY_ZALO_BOT_DIR . '/includes/class-upload-link-handler.php';
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
		register_activation_hook( BIZCITY_ZALO_BOT_FILE, array( 'BizCity_Zalo_Bot_Database', 'activate' ) );
		// [2026-08-21 Johnny Chu] DIAGNOSTICS-CLI-SCHEMA-ORCHESTRATION — this
		// bundled sub-plugin is require_once'd by bizcity-twin-ai, so its own
		// register_activation_hook() above never fires (WP only calls
		// activation hooks for plugins passed to activate_plugin()). Expose
		// table provisioning to BizCity_Site_Provisioner so headless CI
		// diagnostics, new-blog multisite provisioning and manual self-heal
		// still create the active bizcity_zalo_bots configuration table.
		add_filter( 'bizcity_register_installers', array( $this, 'register_site_provisioner_installer' ) );
	}

	/**
	 * bizcity_register_installers callback — see init_hooks() note above.
	 * @since 2026-08-21
	 */
	public function register_site_provisioner_installer( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'id'           => 'zalo_bot',
			'label'        => 'Zalo Bot (configuration)',
			'callback'     => array( $this, 'maybe_create_tables' ),
			'version_opt'  => 'bizcity_zalo_bot_db_version',
			'expected_ver' => self::DB_VERSION,
		);
		return $list;
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
		
		// [2026-09-01 Johnny Chu] PHASE-1.30-ZALO-MEMORY-REMOVE — Zalo initialization now has no legacy memory component.
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
			BizCity_Zalo_Bot_Channel_Adapter::init_normalized_bridge();
			add_action( 'bizcity_register_channel', function( $bridge ) {
				$bridge->register_adapter( new BizCity_Zalo_Bot_Channel_Adapter() );
			} );
		}

		// Gateway Bridge (standalone Channel Gateway compat)
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

		// [2026-07-24 Johnny Chu] PHASE-0.46 W1 — "@notebook" channel capture bridge (priority 4)
		if ( class_exists( 'BizCity_Zalobot_Notebook_Bridge_Listener' ) ) {
			BizCity_Zalobot_Notebook_Bridge_Listener::boot();
		}

		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 — instant upload-link route.
		if ( class_exists( 'BizCity_Zalobot_Upload_Link_Handler' ) ) {
			BizCity_Zalobot_Upload_Link_Handler::boot();
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
		// [2026-08-21 Johnny Chu] DIAGNOSTICS-SCHEMA-SELF-HEAL — a matching option is insufficient when a shard table was dropped or never provisioned.
		$installed_version = get_option( 'bizcity_zalo_bot_db_version' );
		$bots_table = $GLOBALS['wpdb']->prefix . 'bizcity_zalo_bots';
		$tables_ready = function_exists( 'bizcity_tbl_exists' )
			? bizcity_tbl_exists( $bots_table )
			: false;
		if ( $installed_version === self::DB_VERSION && $tables_ready ) {
			return;
		}

		// Version mismatch or first install — create/update tables
		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			BizCity_Zalo_Bot_Database::activate();
			error_log( '[BizCity Zalo Bot] Database tables created/updated for blog_id: ' . get_current_blog_id() );
		}

		update_option( 'bizcity_zalo_bot_db_version', self::DB_VERSION, false );
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
