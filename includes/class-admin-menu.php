<?php
/**
 * BizCity Twin AI — Centralized Admin Menu
 *
 * Quản lý tập trung TOÀN BỘ admin menu của nền tảng BizCity Twin AI.
 * Tất cả add_menu_page / add_submenu_page (site-level) được tập hợp tại đây.
 * Render callbacks vẫn nằm ở các class gốc — chỉ centralize registrations.
 *
 * Bao gồm:
 *   • End-user (React SPA): Chat, Notebook, Content Creator
 *   • Gateway: Zalo, Facebook, Google Tools, Scheduler, channel connections
 *   • Admin hub:  BizCity AI — cài đặt chatbot, LLM, nội dung
 *   • Knowledge:  Teach AI — đào tạo, characters, memory, skills
 *   • Chat subs:  Độ trưởng thành
 *   • Intent:     Intent Monitor, Data Browser, Tool Control Panel
 *   • Dashboard:  Marketplace, Site Apps
 *   • Legacy pages: Zalo BizCity direct URLs preserved via Gateway
 *
 * Không bao gồm:
 *   • network_admin_menu (LLM, Market, Google) — giữ nguyên tại class gốc
 *   • BizChat_Menu registry (bizchat_register_menus hook) — hệ thống riêng
 *   • Template Guard (admin_menu @99999) — utility, giữ nguyên
 *   • Non-bundled plugins (video-kling, tool-woo, …) — tự register hoặc dùng hook
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Admin_Menu {

	/* ══════════════════════════════════════
	 *  Menu slug constants
	 * ══════════════════════════════════════ */
	const SLUG_CHAT      = 'bizcity-webchat-dashboard'; // End-user: React Chat SPA
	const SLUG_NOTEBOOK  = 'bizcity-notebook';          // End-user: React Notebook SPA
	const SLUG_CREATOR   = 'bizcity-creator';           // End-user: Content Creator
	const SLUG_GATEWAY   = 'bizchat-gateway';           // Unified connections hub
	const SLUG_ADMIN     = 'bizcity-ai';                // Admin hub: settings / logs
	const SLUG_KNOWLEDGE = 'bizcity-knowledge';         // Knowledge: training / characters
	const SLUG_INTENT    = 'bizcity-intent-monitor';    // Intent Monitor
	const SLUG_GOOGLE    = 'bzgoogle-settings';         // Google Tools
	const SLUG_LEGACY    = 'bizlife_dashboard';          // Legacy Zalo

	/**
	 * Boot — wire all admin_menu hooks.
	 */
	public static function boot(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', [ __CLASS__, 'register_toplevel_menus' ], 5 );
		add_action( 'admin_menu', [ __CLASS__, 'register_all_submenus' ], 10 );
		add_action( 'admin_menu', [ __CLASS__, 'reorder_sidebar' ], 999 );
		add_action( 'admin_menu', [ __CLASS__, 'cleanup_duplicate_gateway_menus' ], 99999 );
	}

	/* ══════════════════════════════════════════════════════════
	 *  ① ALL TOP-LEVEL MENUS (priority 5)
	 *     Đăng ký parents trước — children đăng ký ở priority 10.
	 * ══════════════════════════════════════════════════════════ */
	public static function register_toplevel_menus(): void {
		$td = 'bizcity-twin-ai';

		/* ── End-user: Chat React SPA (pos 2) ── */
		if ( class_exists( 'BizCity_WebChat_Admin_Dashboard', false ) ) {
			add_menu_page(
				__( 'Chat với Trợ lý', $td ),
				__( 'Chat', $td ),
				'read',
				self::SLUG_CHAT,
				[ BizCity_WebChat_Admin_Dashboard::instance(), 'render_dashboard_react' ],
				defined( 'BIZCITY_WEBCHAT_URL' )
					? BIZCITY_WEBCHAT_URL . 'assets/icon/Bell.png'
					: 'dashicons-format-chat',
				2
			);
		}

		/* ── End-user: Notebook React SPA (pos 3) ── */
		if ( class_exists( 'BCN_Admin_Page', false ) ) {
			add_menu_page(
				'Notebook',
				'Notebook',
				'read',
				self::SLUG_NOTEBOOK,
				[ new BCN_Admin_Page(), 'render_page' ],
				'dashicons-book-alt',
				3
			);
		}

		/* ── Knowledge: AI Training (pos 28) ── */
		if ( class_exists( 'BizCity_Knowledge_Admin_Menu', false ) ) {
			add_menu_page(
				__( 'Đào tạo AI', $td ),
				__( 'Đào tạo AI', $td ),
				'manage_options',
				self::SLUG_KNOWLEDGE,
				[ BizCity_Knowledge_Admin_Menu::instance(), 'render_training_page' ],
				defined( 'BIZCITY_KNOWLEDGE_DIR' )
					? plugins_url( 'assets/icon/joy.png', BIZCITY_KNOWLEDGE_DIR . 'bootstrap.php' )
					: 'dashicons-welcome-learn-more',
				28
			);
		}

		/* ── Unified Gateway Hub (pos 29 → reorder to 5) ── */
		add_menu_page(
			__( 'Gateway', $td ),
			__( 'Gateway', $td ),
			'read',
			self::SLUG_GATEWAY,
			[ __CLASS__, 'render_gateway_page' ],
			'dashicons-share-alt2',
			29
		);

		/* ── Admin hub: BizCity AI (pos 30 → reorder to 4) ── */
		add_menu_page(
			__( 'BizCity AI', $td ),
			__( 'BizCity AI', $td ),
			'manage_options',
			self::SLUG_ADMIN,
			[ __CLASS__, 'render_overview_page' ],
			'dashicons-superhero-alt',
			30
		);

		/* ── Intent Monitor (pos 72) ── */
		if ( class_exists( 'BizCity_Intent_Monitor', false ) ) {
			add_menu_page(
				'Intent Monitor',
				'Intent Monitor',
				'manage_options',
				self::SLUG_INTENT,
				[ BizCity_Intent_Monitor::instance(), 'render_page' ],
				'dashicons-analytics',
				72
			);
		}

		/* ── End-user: Content Creator (pos 73) ── */
		if ( class_exists( 'BZCC_Admin_Menu', false ) ) {
			add_menu_page(
				'Nội dung sáng tạo',
				'Nội dung sáng tạo',
				'read',
				self::SLUG_CREATOR,
				[ 'BZCC_Admin_Menu', 'render_page' ],
				'dashicons-media-document',
				73
			);
		}
	}

	/* ══════════════════════════════════════════════════════════
	 *  ② ALL SUBMENUS (priority 10)
	 *     Grouped by parent menu.
	 * ══════════════════════════════════════════════════════════ */
	public static function register_all_submenus(): void {
		$td = 'bizcity-twin-ai';

		/* ─────────────────────────────────────────────
		 *  A. BizCity AI admin hub submenus
		 * ───────────────────────────────────────────── */

		// First item replaces top-level label
		add_submenu_page(
			self::SLUG_ADMIN,
			__( 'BizCity AI — Tổng quan', $td ),
			__( 'Tổng quan', $td ),
			'manage_options',
			self::SLUG_ADMIN,
			[ __CLASS__, 'render_overview_page' ]
		);

		// WebChat Settings
		if ( class_exists( 'BizCity_WebChat_Admin_Menu', false ) ) {
			$wc = BizCity_WebChat_Admin_Menu::instance();
			add_submenu_page( self::SLUG_ADMIN,
				__( 'Cài đặt Chatbot', $td ), __( 'Cài đặt Chatbot', $td ),
				'manage_options', 'bizcity-webchat',
				[ $wc, 'render_settings_page' ] );
			add_submenu_page( self::SLUG_ADMIN,
				__( 'Giao diện Widget', $td ), __( 'Giao diện Widget', $td ),
				'manage_options', 'bizcity-webchat-appearance',
				[ $wc, 'render_appearance_page' ] );
			add_submenu_page( self::SLUG_ADMIN,
				__( 'Chat Logs', $td ), __( 'Chat Logs', $td ),
				'manage_options', 'bizcity-webchat-logs',
				[ $wc, 'render_logs_page' ] );
			add_submenu_page( self::SLUG_ADMIN,
				__( 'Timeline', $td ), __( 'Timeline', $td ),
				'manage_options', 'bizcity-webchat-timeline',
				[ $wc, 'render_timeline_page' ] );
			add_submenu_page( self::SLUG_ADMIN,
				__( 'Session Memory', $td ), __( 'Memory', $td ),
				'manage_options', 'bizcity-webchat-memory',
				[ $wc, 'render_memory_page' ] );
			add_submenu_page( self::SLUG_ADMIN,
				__( 'Trigger Guide', $td ), __( 'Trigger Guide', $td ),
				'manage_options', 'bizcity-webchat-trigger-guide',
				[ $wc, 'render_trigger_guide_page' ] );
			add_submenu_page( self::SLUG_ADMIN,
				__( 'Shortcode Guide', $td ), __( 'Shortcode Guide', $td ),
				'manage_options', 'bizcity-webchat-shortcode-guide',
				[ $wc, 'render_shortcode_guide_page' ] );
		}

		// LLM Settings
		if ( class_exists( 'BizCity_LLM_Settings', false ) ) {
			add_submenu_page( self::SLUG_ADMIN,
				'BizCity LLM — ' . __( 'AI Gateway', $td ), 'LLM Settings',
				'manage_options', 'bizcity-llm',
				[ BizCity_LLM_Settings::instance(), 'render_page' ] );
		}

		// Content Creator admin pages
		if ( class_exists( 'BZCC_Admin_Menu', false ) ) {
			add_submenu_page( self::SLUG_ADMIN,
				'Templates', 'Templates',
				'manage_options', 'bizcity-creator-templates',
				[ 'BZCC_Admin_Menu', 'render_templates_page' ] );
			add_submenu_page( self::SLUG_ADMIN,
				'Danh mục nội dung', 'Danh mục',
				'manage_options', 'bizcity-creator-categories',
				[ 'BZCC_Admin_Menu', 'render_categories_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  B. Gateway submenus
		 * ───────────────────────────────────────────── */

		add_submenu_page(
			self::SLUG_GATEWAY,
			__( 'Gateway', $td ),
			__( 'Tổng quan Gateway', $td ),
			'read',
			self::SLUG_GATEWAY,
			[ __CLASS__, 'render_gateway_page' ]
		);

		if ( class_exists( 'BizCity_Zalo_Bot_Dashboard', false ) ) {
			$zalo_dashboard = BizCity_Zalo_Bot_Dashboard::instance();
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo Bot Dashboard', $td ), __( 'Zalo Bot', $td ),
				'manage_options', 'bizcity-zalo-bot-dashboard',
				[ $zalo_dashboard, 'render_dashboard' ] );
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo Bot Assign', $td ), __( 'Zalo Connections', $td ),
				'manage_options', 'bizcity-zalo-bot-assign',
				[ $zalo_dashboard, 'render_assign_page' ] );
		}

		if ( class_exists( 'BizCity_Zalo_Bot_Admin_Menu', false ) ) {
			$zb = BizCity_Zalo_Bot_Admin_Menu::instance();
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'All Zalo Bots', $td ), __( 'Zalo Bots', $td ),
				'manage_options', 'bizcity-zalo-bots',
				[ $zb, 'render_page' ] );
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo Listener', $td ), __( 'Zalo Webhook', $td ),
				'manage_options', 'bizcity-zalo-bot-listener',
				[ $zb, 'render_listener_page' ] );
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo Test API', $td ), __( 'Zalo Test API', $td ),
				'manage_options', 'bizcity-zalo-bot-test-api',
				[ $zb, 'render_test_api_page' ] );
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo Logs', $td ), __( 'Zalo Logs', $td ),
				'manage_options', 'bizcity-zalo-bot-logs',
				[ $zb, 'render_logs_page' ] );
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo Memory', $td ), __( 'Zalo Memory', $td ),
				'manage_options', 'bizcity-zalo-bot-memory',
				[ $zb, 'render_memory_page' ] );
		}

		if ( function_exists( 'bizcity_guides_admin_page' ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo BizCity Guide', $td ), __( 'Zalo BizCity', $td ),
				'manage_options', 'zalo-video-guider',
				'bizcity_guides_admin_page' );
		}

		if ( function_exists( 'twf_zalo_users_admin_page' ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo BizCity Users', $td ), __( 'Zalo User Mapping', $td ),
				'manage_options', 'zalo-users-admin',
				'twf_zalo_users_admin_page' );
		}

		if ( function_exists( 'twf_telegram_command_widget_content' ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo BizCity Connection Guide', $td ), __( 'Zalo Legacy Guide', $td ),
				'manage_options', 'zalo-guider',
				'twf_telegram_command_widget_content' );
		}

		if ( class_exists( 'BZGoogle_Admin', false ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Google Tools', $td ), __( 'Google Tools', $td ),
				'read', self::SLUG_GOOGLE,
				[ 'BZGoogle_Admin', 'render_page' ] );
		}

		if ( function_exists( 'bztfb_render_admin_page' ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Facebook AI Posting', $td ), __( 'Facebook Tools', $td ),
				'manage_options', 'bizcity-tool-facebook',
				'bztfb_render_admin_page' );
		}

		if ( function_exists( 'bztfb_render_settings_page' ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Facebook Settings', $td ), __( 'Facebook Settings', $td ),
				'manage_options', 'bizcity-facebook-settings',
				'bztfb_render_settings_page' );
		}

		if ( class_exists( 'BizCity_Scheduler_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Scheduler', $td ), __( 'Scheduler', $td ),
				'read', 'bizcity-scheduler',
				[ BizCity_Scheduler_Admin_Page::instance(), 'render_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  C. AI Training submenus
		 * ───────────────────────────────────────────── */
		if ( class_exists( 'BizCity_Knowledge_Admin_Menu', false ) ) {
			$km = BizCity_Knowledge_Admin_Menu::instance();

			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Khai sinh AI', $td ), __( 'Khai sinh AI', $td ),
				'manage_options', self::SLUG_KNOWLEDGE,
				[ $km, 'render_training_page' ] );

			// Hidden legacy knowledge pages — keep direct URLs working.
			add_submenu_page( null,
				__( 'Training FAQ', $td ), __( 'Training FAQ', $td ),
				'manage_options', 'bizcity-knowledge-training',
				[ $km, 'render_training_page' ] );
			add_submenu_page( null,
				__( 'Knowledge Dashboard', $td ), __( 'Knowledge Dashboard', $td ),
				'manage_options', 'bizcity-knowledge-dashboard',
				[ $km, 'render_maturity_dashboard' ] );
			add_submenu_page( null,
				__( 'Trợ lý AI', $td ), __( 'Trợ lý AI', $td ),
				'manage_options', 'bizcity-knowledge-characters',
				[ $km, 'render_characters_page' ] );
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Chat Monitor', $td ), __( 'Chat Monitor', $td ),
				'manage_options', 'bizcity-knowledge-monitor',
				[ $km, 'render_monitor_page' ] );
			add_submenu_page( null,
				__( 'Dạy AI bằng sổ tay', $td ), __( 'Dạy AI bằng sổ tay', $td ),
				'read', 'bizcity-knowledge-notebook',
				[ $km, 'render_notebook_page' ] );
			add_submenu_page( null,
				__( 'Chỉnh sửa Trợ lý AI', $td ), __( 'Chỉnh sửa Trợ lý AI', $td ),
				'manage_options', 'bizcity-knowledge-character-edit',
				[ $km, 'render_character_edit_page' ] );
		}

		if ( class_exists( 'BZCC_Admin_Menu', false ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Kỹ năng làm nội dung, kịch bản', $td ), __( 'Kỹ năng làm nội dung, kịch bản', $td ),
				'manage_options', 'bizcity-creator-templates',
				[ 'BZCC_Admin_Menu', 'render_templates_page' ] );
		}

		if ( function_exists( 'bztimg_admin_editor_templates_page' ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Kỹ năng thiết kế', $td ), __( 'Kỹ năng thiết kế', $td ),
				'manage_options', 'bztimg-editor-templates',
				'bztimg_admin_editor_templates_page' );
		}

		if ( function_exists( 'bztimg_admin_templates_page' ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Kỹ năng ảnh sản phẩm', $td ), __( 'Kỹ năng ảnh sản phẩm', $td ),
				'manage_options', 'bztimg-templates',
				'bztimg_admin_templates_page' );
		}

		if ( function_exists( 'bztimg_admin_profile_templates_page' ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Kỹ năng ảnh chân dung', $td ), __( 'Kỹ năng ảnh chân dung', $td ),
				'manage_options', 'bztimg-profile-templates',
				'bztimg_admin_profile_templates_page' );
		}

		// Skills Library — under Knowledge
		if ( class_exists( 'BizCity_Skill_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Kỹ năng chia việc', $td ), __( 'Kỹ năng chia việc', $td ),
				'manage_options', 'bizcity-skills',
				[ BizCity_Skill_Admin_Page::instance(), 'render_page' ] );
		}

		if ( class_exists( 'BizCity_Knowledge_Admin_Menu', false ) ) {
			$km = BizCity_Knowledge_Admin_Menu::instance();

			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Lưu trữ tài liệu, ghi nhớ', $td ), __( 'Lưu trữ tài liệu, ghi nhớ', $td ),
				'manage_options', 'bizcity-knowledge-memory-hub',
				[ $km, 'render_memory_hub_page' ] );
		}

		// Memory Specs — under Knowledge
		if ( class_exists( 'BizCity_Memory_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Chỉnh sửa trí nhớ', $td ), __( 'Chỉnh sửa trí nhớ', $td ),
				'manage_options', 'bizcity-memory',
				[ BizCity_Memory_Admin_Page::instance(), 'render_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  D. Chat submenus (under Chat React SPA)
		 * ───────────────────────────────────────────── */

		// Maturity Dashboard
		if ( class_exists( 'BizCity_Maturity_Dashboard', false ) ) {
			add_submenu_page( self::SLUG_CHAT,
				__( 'Độ trưởng thành', $td ), __( 'Độ trưởng thành', $td ),
				'read', 'bizcity-twin-maturity',
				[ BizCity_Maturity_Dashboard::instance(), 'render_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  E. Intent Monitor submenus
		 * ───────────────────────────────────────────── */

		// Tool Control Panel
		if ( class_exists( 'BizCity_Tool_Control_Panel', false ) ) {
			add_submenu_page( self::SLUG_INTENT,
				'Tool Control Panel', 'Control Panel',
				'manage_options', 'bizcity-tool-control',
				[ BizCity_Tool_Control_Panel::instance(), 'render_page' ] );
		}

		// Intent Data Browser — dynamic submenus from page definitions
		if ( class_exists( 'BizCity_Intent_Data_Browser', false ) ) {
			$browser = BizCity_Intent_Data_Browser::instance();
			foreach ( BizCity_Intent_Data_Browser::get_browser_pages() as $slug => $page ) {
				add_submenu_page(
					self::SLUG_INTENT,
					$page['title'],
					$page['menu'],
					'manage_options',
					'bizcity-idb-' . $slug,
					[ $browser, 'render_page' ]
				);
			}
		}

		/* ─────────────────────────────────────────────
		 *  F. WP Dashboard submenus
		 * ───────────────────────────────────────────── */

		// Marketplace
		if ( class_exists( 'BizCity_Market_Marketplace', false ) ) {
			$cap = is_multisite() ? 'manage_network' : 'read';
			add_submenu_page(
				'index.php',
				'BizCity Apps - Chợ ứng dụng',
				'Chợ ứng dụng',
				$cap,
				'bizcity-marketplace',
				[ 'BizCity_Market_Marketplace', 'render' ],
				2
			);
		}

		// Site Apps
		if ( class_exists( 'BizCity_Market_Site_Apps', false ) ) {
			add_submenu_page(
				'index.php',
				'Ứng dụng mặc định',
				'Ứng dụng mặc định',
				'manage_options',
				'bizcity-site-apps',
				[ 'BizCity_Market_Site_Apps', 'render_site_apps_page' ],
				61
			);
		}

		/* ─────────────────────────────────────────────
		 *  G. External / non-bundled hooks
		 * ───────────────────────────────────────────── */
		/**
		 * Hook for external / non-bundled plugins to register submenus.
		 *
		 * Usage:
		 *   add_action( 'bizcity_register_admin_menus', function () {
		 *       add_submenu_page( BizCity_Admin_Menu::SLUG_ADMIN, ... );
		 *   } );
		 *
		 * @since 1.4.0
		 */
		do_action( 'bizcity_register_admin_menus' );
	}

	/* ══════════════════════════════════════
	 *  ③ Reorder sidebar
	 *     Chat → Notebook → BizCity AI → rest of WP
	 * ══════════════════════════════════════ */
	public static function reorder_sidebar(): void {
		self::move_menu_item( self::SLUG_ADMIN, 4 );
		self::move_menu_item( self::SLUG_GATEWAY, 5 );
	}

	/**
	 * Move a top-level menu item to a target offset.
	 */
	private static function move_menu_item( string $slug, int $target_offset ): void {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		$found_key = null;
		$found_item = null;

		foreach ( $menu as $key => $item ) {
			if ( isset( $item[2] ) && $item[2] === $slug ) {
				$found_key = $key;
				$found_item = $item;
				break;
			}
		}

		if ( null === $found_key || null === $found_item ) {
			return;
		}

		unset( $menu[ $found_key ] );
		$menu = array_values( $menu );
		$target_offset = max( 0, min( $target_offset, count( $menu ) ) );
		array_splice( $menu, $target_offset, 0, [ $found_item ] );
	}

	/**
	 * Hide legacy top-level menus once their pages are available under Gateway.
	 */
	public static function cleanup_duplicate_gateway_menus(): void {
		remove_menu_page( self::SLUG_LEGACY );
		remove_menu_page( self::SLUG_GOOGLE );
		remove_menu_page( 'bizcity-zalo-bots' );
		remove_menu_page( 'bizcity-facebook-bots' );

		remove_submenu_page( self::SLUG_CHAT, self::SLUG_GATEWAY );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-scheduler' );

		// Remove Zalo & Chat Monitor duplicates from Chat menu (already in Gateway).
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-dashboard' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-assign' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-test-api' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-logs' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-memory' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-knowledge-monitor' );
	}

	/**
	 * Gateway landing page delegates to the channel gateway module when available.
	 */
	public static function render_gateway_page(): void {
		if ( class_exists( 'BizCity_Gateway_Admin', false ) ) {
			BizCity_Gateway_Admin::instance()->render_overview();
			return;
		}

		$td = 'bizcity-twin-ai';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Gateway', $td ); ?></h1>
			<p><?php esc_html_e( 'Trung tâm quản lý các đầu kết nối như Zalo, Facebook, Google Tools và Scheduler.', $td ); ?></p>
		</div>
		<?php
	}

	/* ══════════════════════════════════════
	 *  Overview page — admin hub landing
	 * ══════════════════════════════════════ */
	public static function render_overview_page(): void {
		$td = 'bizcity-twin-ai';
		?>
		<div class="wrap">
			<h1>BizCity AI — <?php esc_html_e( 'Quản trị & Cài đặt', $td ); ?></h1>
			<p><?php esc_html_e( 'Trung tâm điều khiển AI: cài đặt chatbot, quản lý nội dung, theo dõi logs và đào tạo AI.', $td ); ?></p>

			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-top:24px;">
				<?php
				$cards = [
					[ self::SLUG_GATEWAY,               __( 'Gateway', $td ),             __( 'Quản lý tập trung Zalo, Facebook, Google Tools và Scheduler', $td ) ],
					// Chatbot & Settings
					[ 'bizcity-webchat',             __( 'Cài đặt Chatbot', $td ),   __( 'Cấu hình bot name, model, welcome message', $td ) ],
					[ 'bizcity-webchat-appearance',  __( 'Giao diện Widget', $td ),   __( 'Tùy chỉnh màu sắc, vị trí, style chatbot', $td ) ],
					[ 'bizcity-llm',                 __( 'LLM Settings', $td ),        __( 'API keys, gateway, model selection', $td ) ],
					// Knowledge & Training
					[ 'bizcity-knowledge',           __( 'Đào tạo AI', $td ),          __( 'Dashboard đào tạo AI, characters, memory', $td ) ],
					[ 'bizcity-knowledge-training',  __( 'Training', $td ),            __( 'FAQ, tài liệu, website — dạy AI kiến thức', $td ) ],
					[ 'bizcity-knowledge-characters', __( 'Trợ lý AI', $td ),          __( 'Quản lý characters / AI assistants', $td ) ],
					// Content
					[ 'bizcity-creator-templates',   __( 'Templates nội dung', $td ),  __( 'Quản lý templates AI content creator', $td ) ],
					[ 'bizcity-creator-categories',  __( 'Danh mục', $td ),            __( 'Phân loại templates theo danh mục', $td ) ],
					// Monitoring
					[ 'bizcity-webchat-logs',        __( 'Chat Logs', $td ),           __( 'Xem lịch sử hội thoại AI', $td ) ],
					[ 'bizcity-webchat-timeline',    __( 'Timeline', $td ),            __( 'Dòng thời gian hoạt động', $td ) ],
					[ 'bizcity-webchat-memory',      __( 'Memory', $td ),              __( 'Session memory & context specs', $td ) ],
					[ 'bizcity-intent-monitor',      __( 'Intent Monitor', $td ),      __( 'Theo dõi intent, conversations, tools', $td ) ],
					// System
					[ 'bizcity-marketplace',         __( 'Marketplace', $td ),         __( 'Chợ ứng dụng & plugin', $td ) ],
				];
				foreach ( $cards as [ $slug, $title, $desc ] ) :
					$url = admin_url( 'admin.php?page=' . $slug );
					// Marketplace is under Dashboard
					if ( $slug === 'bizcity-marketplace' ) {
						$url = admin_url( 'index.php?page=' . $slug );
					}
					?>
					<a href="<?php echo esc_url( $url ); ?>" style="text-decoration:none;color:inherit;">
						<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;box-shadow:0 2px 6px rgba(0,0,0,.04);transition:box-shadow .2s;"
							onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.1)'"
							onmouseout="this.style.boxShadow='0 2px 6px rgba(0,0,0,.04)'">
							<h3 style="margin:0 0 4px;font-size:15px;"><?php echo esc_html( $title ); ?></h3>
							<p style="margin:0;color:#666;font-size:13px;"><?php echo esc_html( $desc ); ?></p>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
