<?php
/**
 * BizCity Twin AI — Centralized Admin Menu
 *
 * Quản lý tập trung TOÀN BỘ admin menu của nền tảng BizCity Twin AI.
 * Tất cả add_menu_page / add_submenu_page (site-level) được tập hợp tại đây.
 * Render callbacks vẫn nằm ở các class gốc — chỉ centralize registrations.
 *
 * Bao gồm:
 *   • End-user (React SPA): Chat, Notebook, Content Creator, Google Tools
 *   • Admin hub:  BizCity AI — cài đặt chatbot, LLM, nội dung
 *   • Knowledge:  Teach AI — đào tạo, characters, memory, skills
 *   • Chat subs:  Maturity, Scheduler
 *   • Intent:     Intent Monitor, Data Browser, Tool Control Panel
 *   • Dashboard:  Marketplace, Site Apps
 *   • Legacy:     Zalo BizCity
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
	}

	/* ══════════════════════════════════════════════════════════
	 *  ① ALL TOP-LEVEL MENUS (priority 5)
	 *     Đăng ký parents trước — children đăng ký ở priority 10.
	 * ══════════════════════════════════════════════════════════ */
	public static function register_toplevel_menus(): void {
		$td = 'bizcity-twin-ai';

		/* ── Legacy Zalo (pos 1) ── */
		if ( class_exists( 'BizCity_AdminHook_AdminMenu', false ) ) {
			add_menu_page(
				'Biz-life',
				'Bots - Zalo BizCity',
				'manage_options',
				self::SLUG_LEGACY,
				'zalo-video-guider',
				'dashicons-format-status',
				1
			);
		}

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

		/* ── Knowledge: Teach AI (pos 28) ── */
		if ( class_exists( 'BizCity_Knowledge_Admin_Menu', false ) ) {
			add_menu_page(
				__( 'Teach AI', $td ),
				__( 'Teach AI', $td ),
				'manage_options',
				self::SLUG_KNOWLEDGE,
				[ BizCity_Knowledge_Admin_Menu::instance(), 'render_maturity_dashboard' ],
				defined( 'BIZCITY_KNOWLEDGE_DIR' )
					? plugins_url( 'assets/icon/joy.png', BIZCITY_KNOWLEDGE_DIR . 'bootstrap.php' )
					: 'dashicons-welcome-learn-more',
				28
			);
		}

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

		/* ── End-user: Google Tools (pos 72) ── */
		if ( class_exists( 'BZGoogle_Admin', false ) ) {
			add_menu_page(
				'Google Tools',
				'Google Tools',
				'read',
				self::SLUG_GOOGLE,
				[ 'BZGoogle_Admin', 'render_page' ],
				'dashicons-google',
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
		 *  B. Knowledge (Teach AI) submenus
		 * ───────────────────────────────────────────── */
		if ( class_exists( 'BizCity_Knowledge_Admin_Menu', false ) ) {
			$km = BizCity_Knowledge_Admin_Menu::instance();

			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Knowledge Dashboard', $td ), __( 'Dashboard', $td ),
				'manage_options', self::SLUG_KNOWLEDGE,
				[ $km, 'render_maturity_dashboard' ] );
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Trợ lý AI', $td ), __( 'Trợ lý AI', $td ),
				'manage_options', 'bizcity-knowledge-characters',
				[ $km, 'render_characters_page' ] );
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Training', $td ), __( 'Training', $td ),
				'manage_options', 'bizcity-knowledge-training',
				[ $km, 'render_training_page' ] );
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Memory Hub', $td ), __( 'Memory', $td ),
				'manage_options', 'bizcity-knowledge-memory-hub',
				[ $km, 'render_memory_hub_page' ] );
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Chat Monitor', $td ), __( 'Chat Monitor', $td ),
				'manage_options', 'bizcity-knowledge-monitor',
				[ $km, 'render_monitor_page' ] );
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Dạy AI bằng sổ tay', $td ), __( 'Dạy AI bằng sổ tay', $td ),
				'read', 'bizcity-knowledge-notebook',
				[ $km, 'render_notebook_page' ] );
			add_submenu_page( null,
				__( 'Chỉnh sửa Trợ lý AI', $td ), __( 'Chỉnh sửa Trợ lý AI', $td ),
				'manage_options', 'bizcity-knowledge-character-edit',
				[ $km, 'render_character_edit_page' ] );
		}

		// Skills Library — under Knowledge
		if ( class_exists( 'BizCity_Skill_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Skill Library', $td ), __( 'Skill Library', $td ),
				'manage_options', 'bizcity-skills',
				[ BizCity_Skill_Admin_Page::instance(), 'render_page' ] );
		}

		// Memory Specs — under Knowledge
		if ( class_exists( 'BizCity_Memory_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_KNOWLEDGE,
				__( 'Memory Specs', $td ), __( 'Memory Specs', $td ),
				'manage_options', 'bizcity-memory',
				[ BizCity_Memory_Admin_Page::instance(), 'render_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  C. Chat submenus (under Chat React SPA)
		 * ───────────────────────────────────────────── */

		// Maturity Dashboard
		if ( class_exists( 'BizCity_Maturity_Dashboard', false ) ) {
			add_submenu_page( self::SLUG_CHAT,
				'Twin AI Maturity', 'Maturity',
				'read', 'bizcity-twin-maturity',
				[ BizCity_Maturity_Dashboard::instance(), 'render_page' ] );
		}

		// Scheduler
		if ( class_exists( 'BizCity_Scheduler_Admin_Page', false ) ) {
			add_submenu_page( self::SLUG_CHAT,
				__( 'Scheduler', $td ), __( 'Lịch & Nhắc việc', $td ),
				'read', 'bizcity-scheduler',
				[ BizCity_Scheduler_Admin_Page::instance(), 'render_page' ] );
		}

		/* ─────────────────────────────────────────────
		 *  D. Intent Monitor submenus
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
		 *  E. WP Dashboard submenus
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
		 *  F. Legacy Zalo submenus
		 * ───────────────────────────────────────────── */
		if ( class_exists( 'BizCity_AdminHook_AdminMenu', false ) ) {
			if ( function_exists( 'bizcity_guides_admin_page' ) ) {
				add_submenu_page( self::SLUG_LEGACY,
					'Hướng dẫn ra lệnh qua zalo BizCity',
					'Hướng dẫn ra lệnh qua zalo BizCity',
					'manage_options', 'zalo-video-guider',
					'bizcity_guides_admin_page' );
			}
			if ( function_exists( 'twf_zalo_users_admin_page' ) ) {
				add_submenu_page( self::SLUG_LEGACY,
					'Tài khoản quản trị qua Zalo BizCity',
					'Tài khoản quản trị qua Zalo BizCity',
					'manage_options', 'zalo-users-admin',
					'twf_zalo_users_admin_page' );
			}
			if ( function_exists( 'twf_telegram_command_widget_content' ) ) {
				add_submenu_page( self::SLUG_LEGACY,
					'Hướng dẫn kết nối Zalo BizCity',
					'Hướng dẫn kết nối Zalo BizCity',
					'manage_options', 'zalo-guider',
					'twf_telegram_command_widget_content' );
			}
		}

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
		global $menu;
		if ( ! is_array( $menu ) ) {
			return;
		}

		// Move BizCity AI admin hub right after Chat & Notebook (position 4)
		foreach ( $menu as $key => $item ) {
			if ( isset( $item[2] ) && $item[2] === self::SLUG_ADMIN ) {
				$extracted = $menu[ $key ];
				unset( $menu[ $key ] );
				$menu = array_slice( $menu, 0, 4, true )
					+ [ $key => $extracted ]
					+ array_slice( $menu, 4, null, true );
				break;
			}
		}
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
					// Chatbot & Settings
					[ 'bizcity-webchat',             __( 'Cài đặt Chatbot', $td ),   __( 'Cấu hình bot name, model, welcome message', $td ) ],
					[ 'bizcity-webchat-appearance',  __( 'Giao diện Widget', $td ),   __( 'Tùy chỉnh màu sắc, vị trí, style chatbot', $td ) ],
					[ 'bizcity-llm',                 __( 'LLM Settings', $td ),        __( 'API keys, gateway, model selection', $td ) ],
					// Knowledge & Training
					[ 'bizcity-knowledge',           __( 'Teach AI', $td ),            __( 'Dashboard đào tạo AI, characters, memory', $td ) ],
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
