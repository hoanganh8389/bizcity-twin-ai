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
	const SLUG_CHAT      = 'bizcity-twinchat';          // End-user: Twin (TwinChat) React SPA — default dashboard since 2026-05-06
	const SLUG_NOTEBOOK  = 'bizcity-notebook';          // End-user: React Notebook SPA
	const SLUG_CREATOR   = 'bizcity-creator';           // End-user: Content Creator
	const SLUG_GATEWAY   = 'bizchat-gateway';           // Đào tạo kết nối — tích hợp kênh
	const SLUG_ADMIN     = 'bizcity-ai';                // Admin hub: settings / logs
	const SLUG_KNOWLEDGE = 'bizcity-knowledge';         // Đào tạo kiến thức — characters, memory, legal
	const SLUG_SKILLS    = 'bizcity-skills-hub';        // Đào tạo kỹ năng — content, image, notebook
	const SLUG_INTENT    = 'bizcity-intent-monitor';    // Intent Monitor
	const SLUG_GOOGLE    = 'bzgoogle-settings';         // Google Tools
	const SLUG_LEGACY    = 'bizlife_dashboard';         // Legacy Zalo

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

		/* ── End-user: Chat React SPA — DISABLED 2026-05-06 ──
		 * TwinChat (modules/twinchat) is now the default dashboard at position 2.
		 * WebChat settings remain accessible under "BizCity AI" submenus.
		 */
		// if ( class_exists( 'BizCity_WebChat_Admin_Dashboard', false ) ) {
		// 	add_menu_page(
		// 		__( 'Chat với Trợ lý', $td ),
		// 		__( 'Chat', $td ),
		// 		'read',
		// 		self::SLUG_CHAT,
		// 		[ BizCity_WebChat_Admin_Dashboard::instance(), 'render_dashboard_react' ],
		// 		defined( 'BIZCITY_WEBCHAT_URL' )
		// 			? BIZCITY_WEBCHAT_URL . 'assets/icon/Bell.png'
		// 			: 'dashicons-format-chat',
		// 		2
		// 	);
		// }

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

		/* ── Đào tạo kiến thức — Characters, Memory, Legal (pos 27) ── */
		add_menu_page(
			__( 'Đào tạo kiến thức', $td ),
			__( 'Đào tạo kiến thức', $td ),
			'manage_options',
			self::SLUG_KNOWLEDGE,
			class_exists( 'BizCity_Knowledge_Admin_Menu', false )
				? [ BizCity_Knowledge_Admin_Menu::instance(), 'render_training_page' ]
				: [ __CLASS__, 'render_knowledge_hub_page' ],
			'dashicons-book-alt',
			27
		);

		/* ── Đào tạo kỹ năng — Content, Image, Video, Notebook (pos 28) ── */
		add_menu_page(
			__( 'Đào tạo kỹ năng', $td ),
			__( 'Đào tạo kỹ năng', $td ),
			'manage_options',
			self::SLUG_SKILLS,
			[ __CLASS__, 'render_skills_hub_page' ],
			'dashicons-hammer',
			28
		);

		/* ── Đào tạo kết nối — Zalo, Facebook, Google, Scheduler (pos 29 → reorder to 5) ── */
		add_menu_page(
			__( 'Đào tạo kết nối', $td ),
			__( 'Đào tạo kết nối', $td ),
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
		 *  B. Đào tạo kết nối — Zalo, Facebook, Google, Scheduler
		 * ───────────────────────────────────────────── */

		add_submenu_page(
			self::SLUG_GATEWAY,
			__( 'Đào tạo kết nối', $td ),
			__( 'Tổng quan', $td ),
			'read',
			self::SLUG_GATEWAY,
			[ __CLASS__, 'render_gateway_page' ]
		);

		if ( class_exists( 'BizCity_Zalo_Bot_Dashboard', false ) ) {
			$zalo_dashboard = BizCity_Zalo_Bot_Dashboard::instance();
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Zalo Bot Dashboard', $td ), __( '🤖 Zalo Bot', $td ),
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

		if ( function_exists( 'bztfb_render_admin_page' ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Facebook AI Posting', $td ), __( '📘 Facebook Tools', $td ),
				'manage_options', 'bizcity-tool-facebook',
				'bztfb_render_admin_page' );
		}

		if ( function_exists( 'bztfb_render_settings_page' ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Facebook Settings', $td ), __( 'Facebook Settings', $td ),
				'manage_options', 'bizcity-facebook-settings',
				'bztfb_render_settings_page' );
		}

		if ( class_exists( 'BZGoogle_Admin', false ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Google Tools', $td ), __( '🔍 Google Tools', $td ),
				'read', self::SLUG_GOOGLE,
				[ 'BZGoogle_Admin', 'render_page' ] );
		}

		if ( class_exists( 'BizCity_Scheduler_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Scheduler', $td ), __( '📅 Scheduler', $td ),
				'read', 'bizcity-scheduler',
				[ BizCity_Scheduler_Admin_Page::instance(), 'render_page' ] );
		}

		if ( class_exists( 'BizCity_Knowledge_Admin_Menu', false ) ) {
			$km = BizCity_Knowledge_Admin_Menu::instance();
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Chat Monitor', $td ), __( '📊 Chat Monitor', $td ),
				'manage_options', 'bizcity-knowledge-monitor',
				[ $km, 'render_monitor_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  C. Đào tạo kiến thức — Characters, Memory, Legal Library
		 * ───────────────────────────────────────────── */
		if ( class_exists( 'BizCity_Knowledge_Admin_Menu', false ) ) {
			$km = BizCity_Knowledge_Admin_Menu::instance();

			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Đào tạo kiến thức', $td ), __( 'Tổng quan', $td ),
				'manage_options', self::SLUG_KNOWLEDGE,
				[ $km, 'render_training_page' ] );

			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Twin Guru', $td ), __( '🦾 Twin Guru', $td ),
				'manage_options', 'bizcity-knowledge-characters',
				[ $km, 'render_characters_page' ] );

			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Lưu trữ tài liệu, ghi nhớ', $td ), __( '📚 Tài liệu & Ghi nhớ', $td ),
				'manage_options', 'bizcity-knowledge-memory-hub',
				[ $km, 'render_memory_hub_page' ] );

			// Hidden legacy direct-URL pages
			add_submenu_page( null, __( 'Training FAQ', $td ), __( 'Training FAQ', $td ),
				'manage_options', 'bizcity-knowledge-training', [ $km, 'render_training_page' ] );
			add_submenu_page( null, __( 'Knowledge Dashboard', $td ), __( 'Knowledge Dashboard', $td ),
				'manage_options', 'bizcity-knowledge-dashboard', [ $km, 'render_maturity_dashboard' ] );
			add_submenu_page( null, __( 'Dạy AI bằng sổ tay', $td ), __( 'Dạy AI bằng sổ tay', $td ),
				'read', 'bizcity-knowledge-notebook', [ $km, 'render_notebook_page' ] );
			add_submenu_page( null, __( 'Edit Twin Guru', $td ), __( 'Edit Twin Guru', $td ),
				'manage_options', 'bizcity-knowledge-character-edit', [ $km, 'render_character_edit_page' ] );
		}

		// Memory Specs
		if ( class_exists( 'BizCity_Memory_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Chỉnh sửa trí nhớ', $td ), __( '🧩 Chỉnh sửa trí nhớ', $td ),
				'manage_options', 'bizcity-memory',
				[ BizCity_Memory_Admin_Page::instance(), 'render_page' ] );
		}

		// Thư viện Pháp luật (Phase 1 Legal Library — dữ liệu crawl)
		if ( class_exists( 'BizCity_Legal_Database', false ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Thư viện Pháp luật', $td ), __( '⚖️ Thư viện Pháp luật', $td ),
				'manage_options', 'bizcity-legal-library',
				[ __CLASS__, 'render_legal_library_page' ] );
			// Legal Graph (Phase 2+)
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Legal Knowledge Graph', $td ), __( 'Pháp lý — Graph', $td ),
				'manage_options', 'bizcity-knowledge-legal-graph',
				static function () {
					if ( defined( 'BIZCITY_LEGAL_DIR' ) ) {
						include dirname( BIZCITY_LEGAL_DIR ) . '/views/legal-graph.php';
					}
				} );
		}

		/* ─────────────────────────────────────────────
		 *  D. Đào tạo kỹ năng — Content, Image, Video, Notebook
		 * ───────────────────────────────────────────── */

		add_submenu_page( self::SLUG_SKILLS,
			__( 'Đào tạo kỹ năng', $td ), __( 'Tổng quan', $td ),
			'manage_options', self::SLUG_SKILLS,
			[ __CLASS__, 'render_skills_hub_page' ] );

		// Notebook (end-user page accessible from Skills admin)
		if ( class_exists( 'BCN_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				'Notebook', '📓 Notebook',
				'read', self::SLUG_NOTEBOOK,
				[ new BCN_Admin_Page(), 'render_page' ] );
		}

		// Content Creator
		if ( class_exists( 'BZCC_Admin_Menu', false ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				'Content Creator', '✍️ Content Creator',
				'read', self::SLUG_CREATOR,
				[ 'BZCC_Admin_Menu', 'render_page' ] );
			add_submenu_page( self::SLUG_SKILLS,
				__( 'Templates nội dung', $td ), __( 'Templates nội dung', $td ),
				'manage_options', 'bizcity-creator-templates',
				[ 'BZCC_Admin_Menu', 'render_templates_page' ] );
			add_submenu_page( self::SLUG_SKILLS,
				__( 'Danh mục nội dung', $td ), __( 'Danh mục nội dung', $td ),
				'manage_options', 'bizcity-creator-categories',
				[ 'BZCC_Admin_Menu', 'render_categories_page' ] );
		}

		// Image skills
		if ( function_exists( 'bztimg_admin_editor_templates_page' ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				__( 'Kỹ năng thiết kế', $td ), __( '🎨 Kỹ năng thiết kế', $td ),
				'manage_options', 'bztimg-editor-templates',
				'bztimg_admin_editor_templates_page' );
		}
		if ( function_exists( 'bztimg_admin_templates_page' ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				__( 'Kỹ năng ảnh sản phẩm', $td ), __( '📸 Kỹ năng ảnh sản phẩm', $td ),
				'manage_options', 'bztimg-templates',
				'bztimg_admin_templates_page' );
		}
		if ( function_exists( 'bztimg_admin_profile_templates_page' ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				__( 'Kỹ năng ảnh chân dung', $td ), __( '🧑‍🎨 Kỹ năng ảnh chân dung', $td ),
				'manage_options', 'bztimg-profile-templates',
				'bztimg_admin_profile_templates_page' );
		}

		// Skills library (task delegation)
		if ( class_exists( 'BizCity_Skill_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				__( 'Kỹ năng chia việc', $td ), __( '🔀 Kỹ năng chia việc', $td ),
				'manage_options', 'bizcity-skills',
				[ BizCity_Skill_Admin_Page::instance(), 'render_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  E. Chat submenus (under Chat React SPA)
		 * ───────────────────────────────────────────── */

		// Maturity Dashboard
		if ( class_exists( 'BizCity_Maturity_Dashboard', false ) ) {
			add_submenu_page( self::SLUG_CHAT,
				__( 'Độ trưởng thành', $td ), __( 'Độ trưởng thành', $td ),
				'read', 'bizcity-twin-maturity',
				[ BizCity_Maturity_Dashboard::instance(), 'render_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  F. Intent Monitor submenus
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
		 *  G. WP Dashboard submenus
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
		 *  H. External / non-bundled hooks
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
		self::move_menu_item( self::SLUG_ADMIN,     4 );
		self::move_menu_item( self::SLUG_GATEWAY,   5 );
		self::move_menu_item( self::SLUG_KNOWLEDGE, 6 );
		self::move_menu_item( self::SLUG_SKILLS,    7 );
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
		// Remove legacy standalone top-level menus
		remove_menu_page( self::SLUG_LEGACY );
		remove_menu_page( self::SLUG_GOOGLE );
		remove_menu_page( 'bizcity-zalo-bots' );
		remove_menu_page( 'bizcity-facebook-bots' );

		// Chat menu: remove items already in Đào tạo kết nối
		remove_submenu_page( self::SLUG_CHAT, self::SLUG_GATEWAY );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-scheduler' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-dashboard' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-assign' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-test-api' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-logs' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-zalo-bot-memory' );
		remove_submenu_page( self::SLUG_CHAT, 'bizcity-knowledge-monitor' );
	}

	/**
	 * ══════════════════════════════════════
	 *  Render: Đào tạo kiến thức — overview
	 * ══════════════════════════════════════
	 */
	public static function render_knowledge_hub_page(): void {
		$td = 'bizcity-twin-ai';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Đào tạo kiến thức', $td ); ?></h1>
			<p><?php esc_html_e( 'Quản lý Trợ lý AI, trí nhớ, tài liệu học và thư viện pháp luật.', $td ); ?></p>
		</div>
		<?php
	}

	/**
	 * ══════════════════════════════════════
	 *  Render: Đào tạo kỹ năng — overview
	 * ══════════════════════════════════════
	 */
	public static function render_skills_hub_page(): void {
		$td = 'bizcity-twin-ai';
		$items = [
			[ 'bizcity-notebook',          '📓 Notebook',              'Dạy AI bằng sổ tay ghi chú, tư duy' ],
			[ 'bizcity-creator',           '✍️ Content Creator',       'Tạo nội dung, bài viết, kịch bản' ],
			[ 'bizcity-creator-templates', 'Templates nội dung',       'Quản lý template AI viết nội dung' ],
			[ 'bizcity-creator-categories','Danh mục nội dung',        'Phân loại templates theo chủ đề' ],
			[ 'bztimg-editor-templates',   '🎨 Kỹ năng thiết kế',      'Template chỉnh sửa ảnh AI' ],
			[ 'bztimg-templates',          '📸 Kỹ năng ảnh sản phẩm',  'Template ảnh sản phẩm AI' ],
			[ 'bztimg-profile-templates',  '🧑‍🎨 Kỹ năng ảnh chân dung','Template ảnh chân dung AI' ],
			[ 'bizcity-skills',            '🔀 Kỹ năng chia việc',     'Phân công nhiệm vụ theo kỹ năng AI' ],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Đào tạo kỹ năng', $td ); ?></h1>
			<p><?php esc_html_e( 'Các kỹ năng nghiệp vụ: viết nội dung, thiết kế ảnh, video, notebook, chia việc.', $td ); ?></p>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-top:20px;">
				<?php foreach ( $items as [ $slug, $title, $desc ] ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" style="text-decoration:none;color:inherit;">
					<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.04);">
						<h3 style="margin:0 0 4px;font-size:14px;"><?php echo esc_html( $title ); ?></h3>
						<p style="margin:0;color:#666;font-size:12px;"><?php echo esc_html( $desc ); ?></p>
					</div>
				</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * ══════════════════════════════════════
	 *  Render: Thư viện Pháp luật — Phase 1
	 *  Dữ liệu crawl từ luatvietnam.vn + vbpl.vn
	 * ══════════════════════════════════════
	 */
	public static function render_legal_library_page(): void {
		$td      = 'bizcity-twin-ai';
		$api_url = rest_url( 'bizcity/v1/legal' );
		$nonce   = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap" id="biz-legal-lib">
			<h1>⚖️ <?php esc_html_e( 'Thư viện Pháp luật', $td ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-legal-graph' ) ); ?>"
				   class="page-title-action"><?php esc_html_e( 'Knowledge Graph →', $td ); ?></a>
			</h1>

			<!-- Stats bar -->
			<div id="biz-legal-stats" style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0 20px;"></div>

			<!-- Toolbar -->
			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
				<input id="biz-legal-q" type="search" placeholder="<?php esc_attr_e( 'Tìm kiếm văn bản pháp luật…', $td ); ?>"
					class="regular-text" style="min-width:280px;">
				<select id="biz-legal-linh-vuc"><option value=""><?php esc_html_e( '— Lĩnh vực —', $td ); ?></option></select>
				<select id="biz-legal-loai"><option value=""><?php esc_html_e( '— Loại VB —', $td ); ?></option></select>
				<select id="biz-legal-priority">
					<option value=""><?php esc_html_e( '— Mức ưu tiên —', $td ); ?></option>
					<option value="1">P1 — Bộ luật / Luật</option>
					<option value="2">P2 — Nghị định / Thông tư</option>
					<option value="3">P3 — Khác</option>
				</select>
				<button id="biz-legal-search" class="button button-primary"><?php esc_html_e( 'Tìm', $td ); ?></button>
				<button id="biz-legal-crawl-btn" class="button" style="margin-left:auto;">
					<?php esc_html_e( '+ Crawl URL', $td ); ?>
				</button>
			</div>

			<!-- Crawl form (hidden by default) -->
			<div id="biz-legal-crawl-form"
				 style="display:none;background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin-bottom:14px;">
				<strong><?php esc_html_e( 'Crawl URL văn bản:', $td ); ?></strong>
				<div style="display:flex;gap:8px;margin-top:8px;">
					<input id="biz-crawl-url" type="url" class="regular-text"
						placeholder="https://luatvietnam.vn/lao-dong/bo-luat-lao-dong-2019-…" style="flex:1;">
					<button id="biz-crawl-submit" class="button button-primary"><?php esc_html_e( 'Crawl ngay', $td ); ?></button>
				</div>
				<div id="biz-crawl-result" style="margin-top:8px;font-size:13px;"></div>
			</div>

			<!-- Results table -->
			<div id="biz-legal-loading" style="display:none;padding:12px;color:#666;">Đang tải...</div>
			<table class="wp-list-table widefat fixed striped" style="margin-top:4px;">
				<thead><tr>
					<th style="width:35%">Tên văn bản</th>
					<th style="width:14%">Số hiệu</th>
					<th style="width:16%">Cơ quan</th>
					<th style="width:8%">Năm</th>
					<th style="width:10%">Hiệu lực</th>
					<th style="width:8%">Ưu tiên</th>
					<th style="width:9%">Từ / Trạng thái</th>
				</tr></thead>
				<tbody id="biz-legal-tbody"><tr><td colspan="7" style="text-align:center;color:#999;">Nhập từ khoá và nhấn Tìm…</td></tr></tbody>
			</table>

			<!-- Pagination -->
			<div id="biz-legal-pager" style="margin-top:12px;display:flex;gap:8px;align-items:center;"></div>
		</div>
		<script>
		(function(){
			const API  = <?php echo wp_json_encode( $api_url ); ?>;
			const NON  = <?php echo wp_json_encode( $nonce ); ?>;
			const HEADS = { 'X-WP-Nonce': NON, 'Content-Type': 'application/json' };
			let offset = 0, limit = 30, curArgs = {};

			// Load taxonomy dropdowns
			function loadTaxonomy(type, selId) {
				fetch(API + '/taxonomy?type=' + type, { headers: HEADS })
					.then(r => r.json()).then(data => {
						const sel = document.getElementById(selId);
						(data.terms || []).forEach(t => {
							const o = document.createElement('option');
							o.value = t.slug; o.textContent = t.name + (t.doc_count ? ' (' + t.doc_count + ')' : '');
							sel.appendChild(o);
						});
					}).catch(() => {});
			}
			loadTaxonomy('linh_vuc', 'biz-legal-linh-vuc');
			loadTaxonomy('loai',     'biz-legal-loai');

			// Load stats
			function loadStats() {
				fetch(API + '/legal-graph/stats', { headers: HEADS })
					.then(r => r.json()).then(s => {
						const bar = document.getElementById('biz-legal-stats');
						const items = [
							['Tổng văn bản', s.docs ?? '—'],
							['Sẵn sàng', s.docs_ready ?? '—'],
							['P1 (Luật)', s.docs_p1 ?? '—'],
							['P2 (NĐ/TT)', s.docs_p2 ?? '—'],
							['Queue', s.queue_pending ?? '—'],
						];
						bar.innerHTML = items.map(([l,v]) =>
							`<div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:8px 16px;">
								<div style="font-size:22px;font-weight:600;color:#1d2327;">${v}</div>
								<div style="font-size:11px;color:#666;">${l}</div>
							</div>`
						).join('');
					}).catch(() => {});
			}
			loadStats();

			// Search / list docs
			function loadDocs() {
				const args = {
					search:        document.getElementById('biz-legal-q').value.trim(),
					linh_vuc_slug: document.getElementById('biz-legal-linh-vuc').value,
					loai:          document.getElementById('biz-legal-loai').value,
					priority:      document.getElementById('biz-legal-priority').value,
					limit, offset,
				};
				curArgs = args;
				const qs = Object.entries(args).filter(([,v]) => v !== '' && v !== 0)
					.map(([k,v]) => k + '=' + encodeURIComponent(v)).join('&');
				document.getElementById('biz-legal-loading').style.display = 'block';
				document.getElementById('biz-legal-tbody').innerHTML = '';
				fetch(API + '/docs?' + qs, { headers: HEADS })
					.then(r => r.json()).then(data => {
						document.getElementById('biz-legal-loading').style.display = 'none';
						renderDocs(data.docs || [], data.total || 0);
					}).catch(e => {
						document.getElementById('biz-legal-loading').style.display = 'none';
						document.getElementById('biz-legal-tbody').innerHTML =
							'<tr><td colspan="7" style="color:red;">Lỗi: ' + e.message + '</td></tr>';
					});
			}

			const PRIORITY_LABELS = { '1':'🔴 P1', '2':'🟡 P2', '3':'⚪ P3' };
			const STATUS_LABELS   = { 'con_hieu_luc':'✅ Còn', 'het_hieu_luc':'❌ Hết', 'unknown':'—' };

			function renderDocs(docs, total) {
				const tb = document.getElementById('biz-legal-tbody');
				if (!docs.length) {
					tb.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#999;">Không tìm thấy văn bản nào.</td></tr>';
					document.getElementById('biz-legal-pager').innerHTML = '';
					return;
				}
				tb.innerHTML = docs.map(d => {
					const name  = d.ten_van_ban || '(chưa có tên)';
					const words = d.text_word_count > 0
						? d.text_word_count.toLocaleString() + ' từ'
						: d.status === 'ready' ? '✓' : d.status;
					const link = d.lvn_url || d.file_url || '#';
					return `<tr>
						<td><a href="${link}" target="_blank" rel="noopener" title="${name}">${name}</a>`
						+ (d.excerpt ? `<br><small style="color:#888;">${d.excerpt.substring(0,120)}…</small>` : '')
						+ `</td>
						<td style="font-size:12px;">${d.so_hieu || '—'}</td>
						<td style="font-size:12px;">${d.co_quan || '—'}</td>
						<td>${d.nam_ban_hanh > 0 ? d.nam_ban_hanh : (d.ngay_ban_hanh ? d.ngay_ban_hanh.substring(0,4) : '—')}</td>
						<td>${STATUS_LABELS[d.hieu_luc] ?? d.hieu_luc}</td>
						<td>${PRIORITY_LABELS[String(d.crawl_priority)] ?? d.crawl_priority}</td>
						<td style="font-size:11px;color:#555;">${words}</td>
					</tr>`;
				}).join('');

				// Pagination
				const pager = document.getElementById('biz-legal-pager');
				const totalPages = Math.ceil(total / limit);
				const curPage    = Math.floor(offset / limit) + 1;
				pager.innerHTML = `<span style="color:#666;">Tìm thấy <strong>${total}</strong> văn bản — trang ${curPage}/${totalPages}</span>`
					+ (offset > 0 ? ' <button id="biz-pg-prev" class="button">← Trước</button>' : '')
					+ (offset + limit < total ? ' <button id="biz-pg-next" class="button">Sau →</button>' : '');
				pager.querySelector('#biz-pg-prev')?.addEventListener('click', () => { offset -= limit; loadDocs(); });
				pager.querySelector('#biz-pg-next')?.addEventListener('click', () => { offset += limit; loadDocs(); });
			}

			// Search button
			document.getElementById('biz-legal-search').addEventListener('click', () => { offset = 0; loadDocs(); });
			document.getElementById('biz-legal-q').addEventListener('keydown', e => { if (e.key === 'Enter') { offset = 0; loadDocs(); } });

			// Crawl form toggle
			document.getElementById('biz-legal-crawl-btn').addEventListener('click', () => {
				const f = document.getElementById('biz-legal-crawl-form');
				f.style.display = f.style.display === 'none' ? 'block' : 'none';
			});

			// Crawl submit
			document.getElementById('biz-crawl-submit').addEventListener('click', () => {
				const url = document.getElementById('biz-crawl-url').value.trim();
				const res = document.getElementById('biz-crawl-result');
				if (!url) { res.textContent = 'Nhập URL trước.'; return; }
				res.textContent = '⏳ Đang crawl…';
				const btn = document.getElementById('biz-crawl-submit');
				btn.disabled = true;
				fetch(API + '/crawl-now', {
					method: 'POST', headers: HEADS,
					body: JSON.stringify({ url }),
				}).then(r => r.json()).then(data => {
					btn.disabled = false;
					if (data.ok) {
						const d = data.doc;
						res.innerHTML = '✅ OK — <strong>' + (d?.ten_van_ban || 'doc_id=' + data.doc_id) + '</strong>'
							+ ' | ' + (d?.text_word_count || 0).toLocaleString() + ' từ'
							+ ' | nguồn: ' + (d?.text_source || '?');
						loadStats();
					} else {
						res.textContent = '❌ ' + (data.error || 'Lỗi không xác định');
					}
				}).catch(e => { btn.disabled = false; res.textContent = '❌ ' + e.message; });
			});

			// Auto-load first page on open
			loadDocs();
		})();
		</script>
		<?php
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
