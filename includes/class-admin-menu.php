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
	const SLUG_GATEWAY   = 'bizchat-gateway';           // Đào tạo kết nối — tích hợp kênh (PHASE 0.31 T-S4.2: demoted → read-only deep-link dashboard)
	const SLUG_CHANNELS  = 'bizcity-channels';          // PHASE 0.31 T-S4.1 — Channel admin parent (Zalo Bot, FB Bot, Zalo Hotline)
	const SLUG_INTEGRATIONS = 'bizcity-integrations';  // PHASE 0.31 Sprint 6 — Standalone integration config page (moved from Workflow tab)
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

		/* ── PHASE 0.31 T-S4.1 — Channels parent (Zalo Bot, FB Bot, Zalo Hotline) (pos 29.5) ── */
		add_menu_page(
			__( 'Channels', $td ),
			__( 'Channels', $td ),
			'manage_options',
			self::SLUG_CHANNELS,
			[ __CLASS__, 'render_channels_page' ],
			'dashicons-networking',
			29
		);

		/* ── Cài đặt Twin AI (pos 88 — phía cuối, gần Settings của WP)
		 *     Phase G (2026-05-19): repurposed from "BizCity AI" hub →
		 *     trang cài đặt chung của bộ plugin bizcity-twin-ai.
		 *     Slug giữ nguyên (`bizcity-ai`) để không phá deep-links cũ.
		 */
		add_menu_page(
			__( 'Cài đặt Twin AI', $td ),
			__( 'Cài đặt Twin AI', $td ),
			'manage_options',
			self::SLUG_ADMIN,
			[ __CLASS__, 'render_overview_page' ],
			'dashicons-admin-generic',
			88
		);

		/* ── Intent Monitor — DISABLED 2026-05-19 (Phase G).
		 * Comment out top-level menu registration and all related submenus.
		 * AJAX handlers, internal services, and direct ?page=... access remain
		 * functional for backward compatibility but the menu is hidden from UI.
		 */
		// if ( class_exists( 'BizCity_Intent_Monitor', false ) ) {
		// 	add_menu_page(
		// 		'Intent Monitor',
		// 		'Intent Monitor',
		// 		'manage_options',
		// 		self::SLUG_INTENT,
		// 		[ BizCity_Intent_Monitor::instance(), 'render_page' ],
		// 		'dashicons-analytics',
		// 		72
		// 	);
		// }
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
			__( 'Cài đặt Twin AI — Tổng quan', $td ),
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
		 *  B. Đào tạo kết nối — Gateway dashboard (read-only)
		 *
		 *  PHASE 0.31 T-S4.1/T-S4.2:
		 *   - Channel admin pages (Zalo Bot, FB Bot, Zalo Hotline) live under
		 *     SLUG_CHANNELS now. SLUG_GATEWAY only holds the read-only
		 *     deep-link dashboard + a few cross-cutting tools (Google Tools,
		 *     Scheduler) until Sprint 6 fully demotes those.
		 * ───────────────────────────────────────────── */

		add_submenu_page(
			self::SLUG_GATEWAY,
			__( 'Đào tạo kết nối', $td ),
			__( 'Tổng quan', $td ),
			'read',
			self::SLUG_GATEWAY,
			[ __CLASS__, 'render_gateway_page' ]
		);

		// PHASE 0.31 Sprint 6 — Standalone Integrations page (moved from Workflow tab)
		add_submenu_page(
			self::SLUG_GATEWAY,
			__( 'Tích hợp bên ngoài', $td ),
			__( 'Tích hợp', $td ),
			'manage_options',
			self::SLUG_INTEGRATIONS,
			[ __CLASS__, 'render_integrations_page' ]
		);

		/* ── Channel submenus → moved to SLUG_CHANNELS (T-S4.1) ── */
		if ( class_exists( 'BizCity_Zalo_Bot_Dashboard', false ) ) {
			$zalo_dashboard = BizCity_Zalo_Bot_Dashboard::instance();
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo Bot Dashboard', $td ), __( 'Zalo Bot', $td ),
				'manage_options', 'bizcity-zalo-bot-dashboard',
				[ $zalo_dashboard, 'render_dashboard' ] );
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo Bot Assign', $td ), __( 'Zalo Connections', $td ),
				'manage_options', 'bizcity-zalo-bot-assign',
				[ $zalo_dashboard, 'render_assign_page' ] );
		}

		if ( class_exists( 'BizCity_Zalo_Bot_Admin_Menu', false ) ) {
			$zb = BizCity_Zalo_Bot_Admin_Menu::instance();
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'All Zalo Bots', $td ), __( 'Zalo Bots', $td ),
				'manage_options', 'bizcity-zalo-bots',
				[ $zb, 'render_page' ] );
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo Listener', $td ), __( 'Zalo Webhook', $td ),
				'manage_options', 'bizcity-zalo-bot-listener',
				[ $zb, 'render_listener_page' ] );
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo Test API', $td ), __( 'Zalo Test API', $td ),
				'manage_options', 'bizcity-zalo-bot-test-api',
				[ $zb, 'render_test_api_page' ] );
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo Logs', $td ), __( 'Zalo Logs', $td ),
				'manage_options', 'bizcity-zalo-bot-logs',
				[ $zb, 'render_logs_page' ] );
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo Memory', $td ), __( 'Zalo Memory', $td ),
				'manage_options', 'bizcity-zalo-bot-memory',
				[ $zb, 'render_memory_page' ] );
		}

		if ( function_exists( 'bizcity_guides_admin_page' ) ) {
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo BizCity Guide', $td ), __( 'Zalo BizCity', $td ),
				'manage_options', 'zalo-video-guider',
				'bizcity_guides_admin_page' );
		}

		if ( function_exists( 'twf_zalo_users_admin_page' ) ) {
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo BizCity Users', $td ), __( 'Zalo User Mapping', $td ),
				'manage_options', 'zalo-users-admin',
				'twf_zalo_users_admin_page' );
		}

		if ( function_exists( 'twf_telegram_command_widget_content' ) ) {
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo BizCity Connection Guide', $td ), __( 'Zalo Legacy Guide', $td ),
				'manage_options', 'zalo-guider',
				'twf_telegram_command_widget_content' );
		}

		if ( class_exists( 'BizCity_Facebook_Bot_Admin_Menu', false ) ) {
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Facebook Bots', $td ), __( 'Facebook Bots', $td ),
				'manage_options', 'bizcity-facebook-bots',
				[ BizCity_Facebook_Bot_Admin_Menu::instance(), 'render_page' ] );
		}

		if ( class_exists( 'BizCity_Facebook_Bot_Admin_Menu', false ) ) {
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'FB Connect Legacy', $td ), __( 'FB Connect', $td ),
				'manage_options', 'bizcity-facebook-bot-connect',
				[ BizCity_Facebook_Bot_Admin_Menu::instance(), 'render_connect_page' ] );
		}

		/* ── Zalo Hotline (mu-plugins/bizcity-admin-hook-zalo) submenu under Channels ── */
		if ( class_exists( 'BizCity_Zalo_Hotline_Admin_Menu', false ) ) {
			add_submenu_page( self::SLUG_CHANNELS,
				__( 'Zalo Hotline (ZNS)', $td ), __( 'Zalo Hotline', $td ),
				'manage_options', 'bizcity-zalo-hotline',
				[ BizCity_Zalo_Hotline_Admin_Menu::instance(), 'render_page' ] );
		}

		if ( class_exists( 'BZGoogle_Admin', false ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Google Tools', $td ), __( 'Google Tools', $td ),
				'read', self::SLUG_GOOGLE,
				[ 'BZGoogle_Admin', 'render_page' ] );
		}

		if ( class_exists( 'BizCity_Scheduler_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Scheduler', $td ), __( 'Scheduler', $td ),
				'read', 'bizcity-scheduler',
				[ BizCity_Scheduler_Admin_Page::instance(), 'render_page' ] );
		}

		if ( class_exists( 'BizCity_Knowledge_Admin_Menu', false ) ) {
			$km = BizCity_Knowledge_Admin_Menu::instance();
			add_submenu_page( self::SLUG_GATEWAY,
				__( 'Chat Monitor', $td ), __( 'Chat Monitor', $td ),
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

			// Twin Guru — 2026-05-21: moved to Twin (bizcity-twinchat) parent so
			// the Guru list is visible alongside Knowledge Graph & Phong cấp Guru.
			// Slug `bizcity-knowledge-characters` preserved verbatim.
			add_submenu_page( self::SLUG_CHAT,
				__( 'Twin Guru', $td ), __( 'Guru list', $td ),
				'manage_options', 'bizcity-knowledge-characters',
				[ $km, 'render_characters_page' ] );

			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Lưu trữ tài liệu, ghi nhớ', $td ), __( 'Tài liệu & Ghi nhớ', $td ),
				'manage_options', 'bizcity-knowledge-memory-hub',
				[ $km, 'render_memory_hub_page' ] );

			// Hidden legacy direct-URL pages
			add_submenu_page( null, __( 'Training FAQ', $td ), __( 'Training FAQ', $td ),
				'manage_options', 'bizcity-knowledge-training', [ $km, 'render_training_page' ] );
			add_submenu_page( null, __( 'Dạy AI bằng sổ tay', $td ), __( 'Dạy AI bằng sổ tay', $td ),
				'read', 'bizcity-knowledge-notebook', [ $km, 'render_notebook_page' ] );
			add_submenu_page( null, __( 'Edit Twin Guru', $td ), __( 'Edit Twin Guru', $td ),
				'manage_options', 'bizcity-knowledge-character-edit', [ $km, 'render_character_edit_page' ] );
		}

		// Memory Specs
		if ( class_exists( 'BizCity_Memory_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Chỉnh sửa trí nhớ', $td ), __( 'Chỉnh sửa trí nhớ', $td ),
				'manage_options', 'bizcity-memory',
				[ BizCity_Memory_Admin_Page::instance(), 'render_page' ] );
		}

		// Legal module — removed 2026-05-21 (core/knowledge/legal/ deleted).

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
				'Notebook', 'Notebook',
				'read', self::SLUG_NOTEBOOK,
				[ new BCN_Admin_Page(), 'render_page' ] );
		}

		// Content Creator
		if ( class_exists( 'BZCC_Admin_Menu', false ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				'Content Creator', 'Content Creator',
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
				__( 'Kỹ năng thiết kế', $td ), __( 'Kỹ năng thiết kế', $td ),
				'manage_options', 'bztimg-editor-templates',
				'bztimg_admin_editor_templates_page' );
		}
		if ( function_exists( 'bztimg_admin_templates_page' ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				__( 'Kỹ năng ảnh sản phẩm', $td ), __( 'Kỹ năng ảnh sản phẩm', $td ),
				'manage_options', 'bztimg-templates',
				'bztimg_admin_templates_page' );
		}
		if ( function_exists( 'bztimg_admin_profile_templates_page' ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				__( 'Kỹ năng ảnh chân dung', $td ), __( 'Kỹ năng ảnh chân dung', $td ),
				'manage_options', 'bztimg-profile-templates',
				'bztimg_admin_profile_templates_page' );
		}

		// Skills library (task delegation)
		if ( class_exists( 'BizCity_Skill_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_SKILLS,
				__( 'Kỹ năng chia việc', $td ), __( 'Kỹ năng chia việc', $td ),
				'manage_options', 'bizcity-skills',
				[ BizCity_Skill_Admin_Page::instance(), 'render_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  F. Intent Monitor submenus — DISABLED 2026-05-19 (Phase G).
		 *     Parent menu (bizcity-intent-monitor) is no longer registered;
		 *     these submenus are also commented out so they don't orphan.
		 * ───────────────────────────────────────────── */

		// // Tool Control Panel
		// if ( class_exists( 'BizCity_Tool_Control_Panel', false ) ) {
		// 	add_submenu_page( self::SLUG_INTENT,
		// 		'Tool Control Panel', 'Control Panel',
		// 		'manage_options', 'bizcity-tool-control',
		// 		[ BizCity_Tool_Control_Panel::instance(), 'render_page' ] );
		// }

		// // Intent Data Browser — dynamic submenus from page definitions
		// if ( class_exists( 'BizCity_Intent_Data_Browser', false ) ) {
		// 	$browser = BizCity_Intent_Data_Browser::instance();
		// 	foreach ( BizCity_Intent_Data_Browser::get_browser_pages() as $slug => $page ) {
		// 		add_submenu_page(
		// 			self::SLUG_INTENT,
		// 			$page['title'],
		// 			$page['menu'],
		// 			'manage_options',
		// 			'bizcity-idb-' . $slug,
		// 			[ $browser, 'render_page' ]
		// 		);
		// 	}
		// }

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
		self::move_menu_item( self::SLUG_CHANNELS,  6 );
		self::move_menu_item( self::SLUG_KNOWLEDGE, 7 );
		self::move_menu_item( self::SLUG_SKILLS,    8 );
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
	 * Gateway landing page — PHASE 0.31 T-S4.2 + PHASE 0.35.S1 (SMTP option migration).
	 *
	 * Cards now deep-link directly to each module's native admin page (real URLs,
	 * not generic Integrations popup). Adds 2 new cards: Scheduler + SMTP.
	 * SMTP card opens an inline form section that writes to option
	 * `bizcity_smtp_settings` — replacing legacy `define('BIZCITY_SMTP_*')` in
	 * `mu-plugins/bizcity-smtp-gmail.php`. The `core/smtp/bootstrap.php` bridge
	 * still respects `wp-config.php` constants if present (constants > option > none).
	 */
	public static function render_gateway_page(): void {
		$td = 'bizcity-twin-ai';

		// PHASE 0.37 M1.W2 — Delegate to Channel Menu Registry when navigating
		// inside the hub (?group=...&sub=...). The legacy cards-overview only
		// shows on the bare ?page=bizchat-gateway URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ( isset( $_GET['group'] ) || isset( $_GET['sub'] ) )
		     && class_exists( 'BizCity_Channel_Menu_Registry' ) ) {
			BizCity_Channel_Menu_Registry::instance()->render();
			return;
		}

		$cards = [
			[ 'zalo_bot',     '🤖 Zalo Bot',          __( 'Zalo Official Account bot — webhook + outbound', $td ),       admin_url( 'admin.php?page=bizchat-gateway&group=channels&sub=zalo-bot' ) ],
			[ 'facebook',     '📘 Facebook Page',     __( 'Messenger DM + Page post', $td ),                              admin_url( 'admin.php?page=bizchat-gateway&group=channels&sub=facebook-page' ) ],
			[ 'zalo_hotline', '📞 Zalo Hotline (ZNS)',__( 'ZNS template / hotline — Zalo users management', $td ),        admin_url( 'admin.php?page=bizchat-gateway&group=channels&sub=zalo-hotline' ) ],
			[ 'gmail',        '📧 Gmail',             __( 'Gmail OAuth — đọc/gửi mail (Google Tools)', $td ),             admin_url( 'admin.php?page=bizchat-gateway&group=integrations&sub=google' ) ],
			[ 'scheduler',    '📅 Scheduler',         __( 'Lịch hẹn — Google Calendar sync, slot booking', $td ),         admin_url( 'admin.php?page=bizchat-gateway&group=integrations&sub=scheduler' ) ],
			[ 'smtp',         '✉️ SMTP / Gmail relay',__( 'Cấu hình SMTP outbound (mở trang riêng)', $td ),                 admin_url( 'admin.php?page=bizcity-smtp-settings' ) ],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Đào tạo kết nối — Channel Gateway', $td ); ?></h1>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-top:24px;">
				<?php foreach ( $cards as [ $code, $title, $desc, $url ] ) : ?>
					<a href="<?php echo esc_url( $url ); ?>"
					   style="text-decoration:none;color:inherit;display:block;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.04);transition:box-shadow .2s,transform .2s;"
					   onmouseover="this.style.boxShadow='0 6px 18px rgba(0,0,0,.10)';this.style.transform='translateY(-2px)'"
					   onmouseout="this.style.boxShadow='0 2px 6px rgba(0,0,0,.04)';this.style.transform='none'">
						<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
							<h3 style="margin:0;font-size:16px;"><?php echo esc_html( $title ); ?></h3>
							<code style="background:#f0f0f1;padding:2px 6px;border-radius:4px;font-size:11px;color:#646970;"><?php echo esc_html( $code ); ?></code>
						</div>
						<p style="margin:0;color:#666;font-size:13px;line-height:1.5;"><?php echo esc_html( $desc ); ?></p>
						<div style="margin-top:10px;color:#2271b1;font-size:12px;">
							<?php esc_html_e( 'Mở trang quản trị →', $td ); ?>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle SMTP settings form POST → write to option `bizcity_smtp_settings`.
	 *
	 * Called from render_gateway_page() before output. Uses nonce + manage_options.
	 * Picked up automatically on next request by `core/smtp/bootstrap.php`
	 * (option-level config, lower precedence than wp-config.php constants).
	 */
	private static function handle_smtp_settings_post(): void {
		if ( ! isset( $_POST['bizcity_smtp_settings_submit'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bizcity_smtp_settings' ) ) {
			return;
		}

		$existing = get_option( 'bizcity_smtp_settings', array() );
		$existing = is_array( $existing ) ? $existing : array();

		$pass_in    = isset( $_POST['smtp_pass'] ) ? (string) wp_unslash( $_POST['smtp_pass'] ) : '';
		$keep_pass  = isset( $_POST['smtp_keep_pass'] ) && $_POST['smtp_keep_pass'] === '1';

		$new = array(
			'host'      => isset( $_POST['smtp_host'] )      ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) )      : '',
			'port'      => isset( $_POST['smtp_port'] )      ? (int)               wp_unslash( $_POST['smtp_port'] )         : 587,
			'user'      => isset( $_POST['smtp_user'] )      ? sanitize_text_field( wp_unslash( $_POST['smtp_user'] ) )      : '',
			'pass'      => $keep_pass ? (string) ( $existing['pass'] ?? '' ) : $pass_in,
			'from'      => isset( $_POST['smtp_from'] )      ? sanitize_email(      wp_unslash( $_POST['smtp_from'] ) )      : '',
			'from_name' => isset( $_POST['smtp_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_from_name'] ) ) : '',
			'secure'    => isset( $_POST['smtp_secure'] )    ? sanitize_key(        wp_unslash( $_POST['smtp_secure'] ) )    : 'tls',
			'auth'      => isset( $_POST['smtp_auth'] ) && $_POST['smtp_auth'] === '1',
		);

		// Sanitize secure to known values.
		if ( ! in_array( $new['secure'], array( 'tls', 'ssl', '' ), true ) ) {
			$new['secure'] = 'tls';
		}

		update_option( 'bizcity_smtp_settings', $new, false );

		// Optional: send a test email if requested.
		if ( isset( $_POST['smtp_send_test'] ) && $_POST['smtp_send_test'] === '1' ) {
			$test_to   = isset( $_POST['smtp_test_to'] ) ? sanitize_email( wp_unslash( $_POST['smtp_test_to'] ) ) : wp_get_current_user()->user_email;
			$test_to   = $test_to ?: get_option( 'admin_email' );
			$ok        = wp_mail( $test_to, '[BizCity] SMTP test ' . current_time( 'mysql' ), 'SMTP relay test thành công nếu bạn nhận được email này.' );
			set_transient( 'bizcity_smtp_settings_notice', array(
				'type' => $ok ? 'success' : 'error',
				'msg'  => $ok
					? sprintf( /* translators: %s = recipient email */ __( '✅ Đã lưu + gửi test tới %s. Kiểm tra inbox.', 'bizcity-twin-ai' ), $test_to )
					: __( '⚠️ Đã lưu nhưng gửi test thất bại. Kiểm tra log error_log() / lỗi PHPMailer.', 'bizcity-twin-ai' ),
			), 30 );
		} else {
			set_transient( 'bizcity_smtp_settings_notice', array(
				'type' => 'success',
				'msg'  => __( '✅ Đã lưu cấu hình SMTP.', 'bizcity-twin-ai' ),
			), 30 );
		}

		// PRG: redirect to avoid re-submit on refresh.
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_GATEWAY . '#bizcity-smtp-settings' ) );
		exit;
	}

	/**
	 * Render SMTP settings form section (option-driven, replaces legacy mu-plugin defines).
	 *
	 * Precedence (resolved by `core/smtp/bootstrap.php::BizCity_SMTP::resolve_config()`):
	 *   1. `wp-config.php` constants `BIZCITY_SMTP_*`  ← read-only override (shown locked)
	 *   2. This option `bizcity_smtp_settings`         ← editable here
	 *   3. None → `wp_mail()` falls back to PHP mail()
	 */
	private static function render_smtp_settings_form(): void {
		$td       = 'bizcity-twin-ai';
		$opt      = get_option( 'bizcity_smtp_settings', array() );
		$opt      = is_array( $opt ) ? $opt : array();
		$has_pass = ! empty( $opt['pass'] );

		$constants = array(
			'BIZCITY_SMTP_HOST'      => defined( 'BIZCITY_SMTP_HOST' ),
			'BIZCITY_SMTP_PORT'      => defined( 'BIZCITY_SMTP_PORT' ),
			'BIZCITY_SMTP_USER'      => defined( 'BIZCITY_SMTP_USER' ),
			'BIZCITY_SMTP_PASS'      => defined( 'BIZCITY_SMTP_PASS' ),
			'BIZCITY_SMTP_FROM'      => defined( 'BIZCITY_SMTP_FROM' ),
			'BIZCITY_SMTP_FROM_NAME' => defined( 'BIZCITY_SMTP_FROM_NAME' ),
			'BIZCITY_SMTP_SECURE'    => defined( 'BIZCITY_SMTP_SECURE' ),
			'BIZCITY_SMTP_AUTH'      => defined( 'BIZCITY_SMTP_AUTH' ),
		);
		$has_const_override = in_array( true, $constants, true );

		$notice = get_transient( 'bizcity_smtp_settings_notice' );
		if ( $notice ) {
			delete_transient( 'bizcity_smtp_settings_notice' );
		}

		?>
		<div id="bizcity-smtp-settings" style="margin-top:36px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:24px;box-shadow:0 2px 6px rgba(0,0,0,.04);">
			<h2 style="margin-top:0;">✉️ <?php esc_html_e( 'SMTP / Gmail Relay', $td ); ?></h2>
			<p style="color:#50575e;max-width:760px;">
				<?php esc_html_e( 'Cấu hình SMTP để wp_mail() (đăng ký tài khoản, reset password, hoá đơn, thông báo…) gửi qua relay riêng thay vì PHP mail(). Module bridge ở core/smtp/bootstrap.php sẽ tự áp dụng config bên dưới — không cần restart.', $td ); ?>
			</p>

			<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>" style="margin:12px 0;">
				<p><?php echo esc_html( $notice['msg'] ); ?></p>
			</div>
			<?php endif; ?>

			<?php if ( $has_const_override ) : ?>
			<div class="notice notice-warning" style="margin:12px 0;">
				<p>
					<strong>⚠️ <?php esc_html_e( 'Đang có override từ wp-config.php', $td ); ?></strong> —
					<?php esc_html_e( 'các define BIZCITY_SMTP_* ưu tiên hơn option. Khi cả hai cùng tồn tại, constant thắng. Để dùng form bên dưới, gỡ define trong wp-config.php.', $td ); ?>
				</p>
				<p style="font-family:monospace;font-size:12px;color:#666;">
				<?php foreach ( $constants as $name => $defined ) : ?>
					<span style="display:inline-block;margin-right:14px;"><?php echo esc_html( $name ); ?>: <?php echo $defined ? '<span style="color:#1a7f37">defined</span>' : '<span style="color:#999">not set</span>'; ?></span>
				<?php endforeach; ?>
				</p>
			</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'bizcity_smtp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row"><label for="smtp_host"><?php esc_html_e( 'SMTP Host', $td ); ?></label></th>
						<td>
							<input type="text" id="smtp_host" name="smtp_host" value="<?php echo esc_attr( $opt['host'] ?? 'smtp.gmail.com' ); ?>" class="regular-text" placeholder="smtp.gmail.com" />
							<p class="description"><?php esc_html_e( 'Ví dụ: smtp.gmail.com (Google Workspace), smtp.mailgun.org, smtp.sendgrid.net.', $td ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_port"><?php esc_html_e( 'Port', $td ); ?></label></th>
						<td>
							<input type="number" id="smtp_port" name="smtp_port" value="<?php echo esc_attr( (string) ( $opt['port'] ?? 587 ) ); ?>" class="small-text" min="1" max="65535" />
							<p class="description"><?php esc_html_e( '587 (TLS, đa số) · 465 (SSL) · 25 (plain, ít dùng)', $td ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_secure"><?php esc_html_e( 'Encryption', $td ); ?></label></th>
						<td>
							<select id="smtp_secure" name="smtp_secure">
								<?php $cur = $opt['secure'] ?? 'tls'; ?>
								<option value="tls" <?php selected( $cur, 'tls' ); ?>>TLS (port 587)</option>
								<option value="ssl" <?php selected( $cur, 'ssl' ); ?>>SSL (port 465)</option>
								<option value=""    <?php selected( $cur, '' );    ?>><?php esc_html_e( '(không mã hoá)', $td ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_auth"><?php esc_html_e( 'Yêu cầu auth', $td ); ?></label></th>
						<td>
							<label><input type="checkbox" id="smtp_auth" name="smtp_auth" value="1" <?php checked( ! empty( $opt['auth'] ) || ! isset( $opt['auth'] ) ); ?> /> <?php esc_html_e( 'SMTP server yêu cầu username + password (mặc định bật).', $td ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_user"><?php esc_html_e( 'Username', $td ); ?></label></th>
						<td>
							<input type="text" id="smtp_user" name="smtp_user" value="<?php echo esc_attr( $opt['user'] ?? '' ); ?>" class="regular-text" placeholder="hoanganh.itm@gmail.com" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Với Gmail: full email address. Với Workspace: account dùng để gửi.', $td ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_pass"><?php esc_html_e( 'Password / App Password', $td ); ?></label></th>
						<td>
							<input type="password" id="smtp_pass" name="smtp_pass" value="" class="regular-text" placeholder="<?php echo $has_pass ? esc_attr__( '(để trống = giữ nguyên password đã lưu)', $td ) : 'gfsp pxcc xytz svnq'; ?>" autocomplete="new-password" />
							<?php if ( $has_pass ) : ?>
								<label style="display:block;margin-top:6px;"><input type="checkbox" name="smtp_keep_pass" value="1" checked /> <?php esc_html_e( 'Giữ password hiện tại (đã có)', $td ); ?></label>
							<?php endif; ?>
							<p class="description"><?php
								printf(
									/* translators: %s = link to Google App Password */
									wp_kses_post( __( 'Với Gmail bật 2FA: dùng %s thay vì mật khẩu thường.', $td ) ),
									'<a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">App Password</a>'
								);
							?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_from"><?php esc_html_e( 'From Email', $td ); ?></label></th>
						<td>
							<input type="email" id="smtp_from" name="smtp_from" value="<?php echo esc_attr( $opt['from'] ?? '' ); ?>" class="regular-text" placeholder="no-reply@bizcity.vn" />
							<p class="description"><?php esc_html_e( 'Địa chỉ hiển thị ở “From:” — thường = SMTP user (Gmail bắt buộc). Với Workspace có thể đặt khác nếu là alias.', $td ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_from_name"><?php esc_html_e( 'From Name', $td ); ?></label></th>
						<td>
							<input type="text" id="smtp_from_name" name="smtp_from_name" value="<?php echo esc_attr( $opt['from_name'] ?? get_bloginfo( 'name' ) ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_test_to"><?php esc_html_e( 'Gửi email test (optional)', $td ); ?></label></th>
						<td>
							<label><input type="checkbox" name="smtp_send_test" value="1" /> <?php esc_html_e( 'Gửi email test ngay sau khi lưu tới:', $td ); ?></label>
							<input type="email" id="smtp_test_to" name="smtp_test_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ?: get_option( 'admin_email' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Để trống = email admin', $td ); ?>" />
						</td>
					</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" name="bizcity_smtp_settings_submit" value="1" class="button button-primary"><?php esc_html_e( '💾 Lưu cấu hình SMTP', $td ); ?></button>
					<span style="margin-left:14px;color:#666;font-size:12px;">
						<?php
						$loaded = defined( 'BIZCITY_SMTP_LOADED' );
						$cfg    = ( $loaded && class_exists( 'BizCity_SMTP' ) ) ? BizCity_SMTP::resolve_config() : null;
						if ( $cfg ) {
							echo '🟢 ' . esc_html__( 'SMTP bridge ACTIVE — gửi qua', $td ) . ' <code>' . esc_html( $cfg['host'] . ':' . $cfg['port'] ) . '</code>';
						} elseif ( $loaded ) {
							echo '⚪ ' . esc_html__( 'SMTP bridge loaded nhưng chưa đủ config (cần host + user + pass + from).', $td );
						} else {
							echo '⚠️ ' . esc_html__( 'SMTP module chưa load (kiểm tra core/smtp/bootstrap.php).', $td );
						}
						?>
					</span>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * PHASE 0.31 Sprint 6 — Standalone Integrations admin page.
	 *
	 * Renders the integration list (previously the "Tích hợp bên ngoài" tab
	 * of the Workflow Builder) as an independent WP admin page accessible via
	 * ?page=bizcity-integrations in the "Đào tạo kết nối" sidebar.
	 */
	public static function render_integrations_page(): void {
		if ( ! class_exists( 'WaicFrame', false ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>Automation (WAIC) module not loaded.</p></div></div>';
			return;
		}
		$workflow_mod = WaicFrame::_()->getModule( 'workflow' );
		if ( ! $workflow_mod ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>Workflow module not available.</p></div></div>';
			return;
		}
		echo $workflow_mod->getView()->showIntegrationsPage(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * PHASE 0.31 T-S4.1 — Channels parent landing page.
	 * Shows index of channel bot admin pages registered as submenus.
	 */
	public static function render_channels_page(): void {
		$td = 'bizcity-twin-ai';

		// ── Channel cards (single source of truth) ───────────────────
		// Each entry: id, emoji, brand_color, name, subtitle, ready (bool),
		// primary { url, label, target }, secondary[] (optional extra btns).
		$support_zalo = (string) apply_filters(
			'bizcity_support_zalo_url',
			get_option( 'bizcity_support_zalo_url', 'https://zalo.me/0562608899' )
		);
		$channels = [
			[
				'id'        => 'zalo_bot',
				'emoji'     => '🤖',
				'brand'     => '#0068FF',
				'name'      => __( 'Zalo Official Account', $td ),
				'subtitle'  => __( 'Bot OA — gửi/nhận tin nhắn, gán bot vào user, memory & logs.', $td ),
				'ready'     => class_exists( 'BizCity_Zalo_Bot_Dashboard', false ),
				'install_hint' => __( 'Plugin "BizCity Zalo Bot" chưa được kích hoạt.', $td ),
				'primary'   => [
					'url'   => admin_url( 'admin.php?page=bizcity-zalo-bot-dashboard' ),
					'label' => __( 'Mở Dashboard', $td ),
				],
				'secondary' => [
					[ 'url' => admin_url( 'admin.php?page=bizcity-zalo-bots' ),         'label' => __( 'Bots', $td ) ],
					[ 'url' => admin_url( 'admin.php?page=bizcity-zalo-bot-assign' ),   'label' => __( 'Assign', $td ) ],
					[ 'url' => admin_url( 'admin.php?page=bizcity-zalo-bot-listener' ), 'label' => __( 'Listener', $td ) ],
				],
			],
			[
				'id'        => 'facebook',
				'emoji'     => '📘',
				'brand'     => '#1877F2',
				'name'      => __( 'Facebook Pages', $td ),
				'subtitle'  => __( 'Kết nối Page tokens qua OAuth, quản lý connected pages.', $td ),
				'ready'     => class_exists( 'BizCity_Facebook_Bot_Admin_Menu', false ),
				'install_hint' => __( 'Plugin "BizCity Facebook Bot" chưa được kích hoạt.', $td ),
				'primary'   => [
					'url'   => admin_url( 'admin.php?page=bizcity-facebook-bots' ),
					'label' => __( 'Quản lý Pages', $td ),
				],
				'secondary' => [
					[ 'url' => admin_url( 'admin.php?page=bizcity-facebook-bot-connect' ), 'label' => __( 'Kết nối Page', $td ) ],
				],
			],
			[
				'id'        => 'zalo_hotline',
				'emoji'     => '📞',
				'brand'     => '#00B0FF',
				'name'      => __( 'Zalo Hotline (ZNS)', $td ),
				'subtitle'  => __( 'Gửi ZNS template tới khách (OTP, xác nhận đơn, nhắc lịch).', $td ),
				'ready'     => class_exists( 'BizCity_Zalo_Hotline_Admin_Menu', false ),
				'install_hint' => __( 'Plugin "BizCity Zalo Hotline" (mu-plugins) chưa được kích hoạt.', $td ),
				'primary'   => [
					'url'   => admin_url( 'admin.php?page=bizcity-zalo-hotline' ),
					'label' => __( 'Cấu hình ZNS', $td ),
				],
			],
			[
				'id'        => 'google',
				'emoji'     => '🔍',
				'brand'     => '#4285F4',
				'name'      => __( 'Google Workspace', $td ),
				'subtitle'  => __( 'Calendar, Gmail, Drive — OAuth kết nối Google account.', $td ),
				'ready'     => class_exists( 'BZGoogle_Admin', false ),
				'install_hint' => __( 'Plugin "BizGPT Tool Google" chưa được kích hoạt.', $td ),
				'primary'   => [
					'url'   => admin_url( 'admin.php?page=' . self::SLUG_GOOGLE ),
					'label' => __( 'Kết nối Google', $td ),
				],
			],
			[
				'id'        => 'bizcity_hotline',
				'emoji'     => '☎️',
				'brand'     => '#1A5276',
				'name'      => __( 'Hotline BizCity (Hỗ trợ)', $td ),
				'subtitle'  => __( 'Liên hệ trực tiếp đội hỗ trợ BizCity qua Zalo OA chính thức.', $td ),
				'ready'     => true,
				'primary'   => [
					'url'    => $support_zalo,
					'label'  => __( 'Mở Zalo BizCity', $td ),
					'target' => '_blank',
				],
				'secondary' => [
					[ 'url' => 'mailto:support@bizcity.vn', 'label' => __( 'Email', $td ), 'target' => '_blank' ],
				],
			],
		];

		// Default active tab from URL (?tab=facebook etc).
		$req_tab    = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'zalo_bot';
		$active_tab = 'zalo_bot';
		foreach ( $channels as $c ) {
			if ( $c['id'] === $req_tab ) { $active_tab = $req_tab; break; }
		}
		?>
		<style>
			/* ── Channels — single-screen tabbed layout (no body scroll) ── */
			html.bz-ch-lock, html.bz-ch-lock body { overflow: hidden !important; }
			#wpbody-content { padding-bottom: 0 !important; }
			#wpfooter { display: none !important; }
			.bz-ch {
				position: fixed;
				top: 32px; left: 160px; right: 0; bottom: 0;
				display: flex; flex-direction: column;
				background: #f6f7f9;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			}
			body.folded .bz-ch { left: 36px; }
			@media (max-width: 782px) {
				.bz-ch { top: 46px; left: 0; }
			}
			.bz-ch__head {
				flex: 0 0 auto;
				padding: 18px 28px 0;
			}
			.bz-ch__head h1 { margin: 0 0 4px; font-size: 22px; font-weight: 600; color: #1d2327; }
			.bz-ch__head p  { margin: 0 0 12px; color: #50575e; font-size: 13px; max-width: 880px; line-height: 1.5; }
			.bz-ch__tabs {
				flex: 0 0 auto;
				display: flex; gap: 2px;
				padding: 0 28px;
				border-bottom: 1px solid #dcdcde;
				background: #f6f7f9;
				overflow-x: auto; scrollbar-width: thin;
			}
			.bz-ch__tab {
				display: inline-flex; align-items: center; gap: 8px;
				padding: 10px 18px;
				border: 1px solid transparent; border-bottom: none;
				border-radius: 8px 8px 0 0;
				background: transparent;
				color: #50575e; font-size: 13px; font-weight: 500;
				text-decoration: none; cursor: pointer; white-space: nowrap;
				transition: background .12s, color .12s;
			}
			.bz-ch__tab:hover { color: #1d2327; background: #fff; }
			.bz-ch__tab.is-active {
				background: #fff;
				border-color: #dcdcde;
				color: #1d2327;
				margin-bottom: -1px;
				border-bottom: 1px solid #fff;
			}
			.bz-ch__tab .bz-ch__tab-emoji { font-size: 16px; line-height: 1; }
			.bz-ch__tab .bz-ch__tab-dot {
				width: 8px; height: 8px; border-radius: 50%;
				background: #d63638;
			}
			.bz-ch__tab.is-ready .bz-ch__tab-dot { background: #00a32a; }
			.bz-ch__body {
				flex: 1 1 auto;
				display: flex; align-items: center; justify-content: center;
				padding: 28px;
				overflow: hidden;
			}
			.bz-ch__panel { display: none; width: 100%; max-width: 720px; }
			.bz-ch__panel.is-active { display: block; }
			.bz-ch__card {
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 14px;
				padding: 32px;
				box-shadow: 0 4px 16px rgba(0,0,0,.04);
				text-align: center;
			}
			.bz-ch__brand {
				width: 72px; height: 72px;
				margin: 0 auto 18px;
				border-radius: 18px;
				display: flex; align-items: center; justify-content: center;
				font-size: 36px;
				background: var(--bz-brand, #0068FF);
				color: #fff;
				box-shadow: 0 6px 18px color-mix(in srgb, var(--bz-brand, #0068FF) 32%, transparent);
			}
			.bz-ch__name { margin: 0 0 6px; font-size: 20px; font-weight: 600; color: #1d2327; }
			.bz-ch__sub  { margin: 0 0 20px; color: #50575e; font-size: 13px; line-height: 1.55; }
			.bz-ch__status {
				display: inline-flex; align-items: center; gap: 6px;
				padding: 4px 10px;
				border-radius: 999px;
				font-size: 11px; font-weight: 600; letter-spacing: .02em;
				margin-bottom: 18px;
			}
			.bz-ch__status--ready  { background: #edfaef; color: #00723b; }
			.bz-ch__status--missing{ background: #fdecec; color: #b32d2e; }
			.bz-ch__status::before {
				content: ""; width: 6px; height: 6px; border-radius: 50%;
				background: currentColor;
			}
			.bz-ch__actions {
				display: flex; flex-wrap: wrap; gap: 10px; justify-content: center;
				margin-top: 8px;
			}
			.bz-ch__btn {
				display: inline-flex; align-items: center; gap: 6px;
				padding: 10px 20px;
				border-radius: 8px;
				font-size: 13px; font-weight: 600;
				text-decoration: none; cursor: pointer;
				transition: filter .12s, transform .04s;
				border: 1px solid transparent;
			}
			.bz-ch__btn:active { transform: translateY(1px); }
			.bz-ch__btn--primary {
				background: var(--bz-brand, #0068FF);
				color: #fff;
			}
			.bz-ch__btn--primary:hover { filter: brightness(.92); color: #fff; }
			.bz-ch__btn--ghost {
				background: #fff;
				color: #50575e;
				border-color: #c3c4c7;
			}
			.bz-ch__btn--ghost:hover { background: #f6f7f9; color: #1d2327; }
			.bz-ch__btn[disabled],
			.bz-ch__btn.is-disabled {
				opacity: .5; pointer-events: none;
			}
			.bz-ch__hint {
				margin-top: 18px;
				font-size: 12px; color: #b32d2e;
			}
		</style>
		<div class="bz-ch" id="bz-ch-root">
			<div class="bz-ch__head">
				<h1><?php esc_html_e( 'Channels', $td ); ?></h1>
				<p><?php esc_html_e( 'Trung tâm kết nối các kênh giao tiếp: Zalo OA, Facebook Pages, ZNS Hotline, Google Workspace và Hotline hỗ trợ BizCity. Mỗi tab dẫn tới trang cấu hình chi tiết của plugin tương ứng.', $td ); ?></p>
			</div>
			<nav class="bz-ch__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Channel tabs', $td ); ?>">
				<?php foreach ( $channels as $c ) :
					$is_active = ( $c['id'] === $active_tab );
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_CHANNELS . '&tab=' . $c['id'] ) ); ?>"
					   class="bz-ch__tab <?php echo $is_active ? 'is-active' : ''; ?> <?php echo $c['ready'] ? 'is-ready' : ''; ?>"
					   data-bz-tab="<?php echo esc_attr( $c['id'] ); ?>"
					   role="tab"
					   aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
						<span class="bz-ch__tab-emoji"><?php echo esc_html( $c['emoji'] ); ?></span>
						<span><?php echo esc_html( $c['name'] ); ?></span>
						<span class="bz-ch__tab-dot" title="<?php echo $c['ready'] ? esc_attr__( 'Plugin sẵn sàng', $td ) : esc_attr__( 'Chưa cài plugin', $td ); ?>"></span>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="bz-ch__body">
				<?php foreach ( $channels as $c ) :
					$is_active = ( $c['id'] === $active_tab );
					$primary   = $c['primary'] ?? [];
					$secondary = $c['secondary'] ?? [];
					?>
					<section class="bz-ch__panel <?php echo $is_active ? 'is-active' : ''; ?>"
					         data-bz-panel="<?php echo esc_attr( $c['id'] ); ?>"
					         role="tabpanel">
						<div class="bz-ch__card" style="--bz-brand: <?php echo esc_attr( $c['brand'] ); ?>;">
							<div class="bz-ch__brand"><?php echo esc_html( $c['emoji'] ); ?></div>
							<h2 class="bz-ch__name"><?php echo esc_html( $c['name'] ); ?></h2>
							<p class="bz-ch__sub"><?php echo esc_html( $c['subtitle'] ); ?></p>
							<?php if ( $c['ready'] ) : ?>
								<span class="bz-ch__status bz-ch__status--ready"><?php esc_html_e( 'Sẵn sàng', $td ); ?></span>
							<?php else : ?>
								<span class="bz-ch__status bz-ch__status--missing"><?php esc_html_e( 'Chưa cài đặt', $td ); ?></span>
							<?php endif; ?>
							<div class="bz-ch__actions">
								<?php if ( ! empty( $primary['url'] ) ) :
									$tgt = $primary['target'] ?? ''; ?>
									<a class="bz-ch__btn bz-ch__btn--primary <?php echo $c['ready'] ? '' : 'is-disabled'; ?>"
									   href="<?php echo esc_url( $primary['url'] ); ?>"
									   <?php if ( $tgt ) : ?>target="<?php echo esc_attr( $tgt ); ?>" rel="noopener noreferrer"<?php endif; ?>>
										<?php echo esc_html( $primary['label'] ?? __( 'Mở', $td ) ); ?>
									</a>
								<?php endif; ?>
								<?php foreach ( $secondary as $sec ) :
									$tgt = $sec['target'] ?? ''; ?>
									<a class="bz-ch__btn bz-ch__btn--ghost <?php echo $c['ready'] ? '' : 'is-disabled'; ?>"
									   href="<?php echo esc_url( $sec['url'] ); ?>"
									   <?php if ( $tgt ) : ?>target="<?php echo esc_attr( $tgt ); ?>" rel="noopener noreferrer"<?php endif; ?>>
										<?php echo esc_html( $sec['label'] ); ?>
									</a>
								<?php endforeach; ?>
							</div>
							<?php if ( ! $c['ready'] && ! empty( $c['install_hint'] ) ) : ?>
								<p class="bz-ch__hint"><?php echo esc_html( $c['install_hint'] ); ?></p>
							<?php endif; ?>
						</div>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<script>
			(function () {
				document.documentElement.classList.add('bz-ch-lock');
				var root = document.getElementById('bz-ch-root');
				if (!root) return;
				root.querySelectorAll('[data-bz-tab]').forEach(function (tab) {
					tab.addEventListener('click', function (e) {
						// Allow Ctrl/Cmd-click to open in new tab as URL
						if (e.metaKey || e.ctrlKey || e.shiftKey) return;
						e.preventDefault();
						var id = tab.getAttribute('data-bz-tab');
						root.querySelectorAll('[data-bz-tab]').forEach(function (t) {
							var on = t.getAttribute('data-bz-tab') === id;
							t.classList.toggle('is-active', on);
							t.setAttribute('aria-selected', on ? 'true' : 'false');
						});
						root.querySelectorAll('[data-bz-panel]').forEach(function (p) {
							p.classList.toggle('is-active', p.getAttribute('data-bz-panel') === id);
						});
						// Sync URL without reload.
						try {
							var u = new URL(window.location.href);
							u.searchParams.set('tab', id);
							window.history.replaceState({}, '', u.toString());
						} catch (_) {}
					});
				});
				window.addEventListener('beforeunload', function () {
					document.documentElement.classList.remove('bz-ch-lock');
				});
			})();
		</script>
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


