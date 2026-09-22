<?php
/**
 * TwinBrain — WordPress admin menu registration (lightweight).
 *
 * PHASE-0-SETTING-PANEL-G6-HOTFIX3 (2026-09-16).
 *
 * Why this file exists
 * --------------------
 * The Twin Brain parent menu and its submenus used to be registered inside
 * `core/twinbrain/bootstrap.php` (admin_menu @30). That bootstrap is gated in
 * `bizcity-twin-ai.php` behind `$_bizcity_admin_ctx && !$_bizcity_twinchat_admin_shell_request`,
 * so the menu MUST be registered before that gate to exist on every admin page.
 *
 * The failure mode: when the operator opened the TwinChat admin shell
 * (`?page=bizcity-twinchat`), `$_bizcity_twinchat_admin_shell_request` was true,
 * `core/twinbrain/bootstrap.php` was skipped, `admin_menu` never saw the
 * registration, and the whole **Twin Brain** menu disappeared from wp-admin.
 *
 * This file therefore owns *only* admin-menu registration. It declares no
 * runtime/REST/schema behaviour, performs no query, and is safe to load on any
 * request that can render wp-admin. The heavy TwinBrain runtime stays behind
 * the existing `$_bizcity_admin_ctx` gate in `bootstrap.php`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-09-16 (PHASE-0-SETTING-PANEL-G6-HOTFIX3)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Admin_Menu', false ) ) {
	return;
}

final class BizCity_TwinBrain_Admin_Menu {

	/** Visible top-level parent slug. Must differ from the legacy `bizcity-twinbrain` page. */
	const PARENT_SLUG = 'bizcity-twin-brain';

	/** Legacy page slug that still redirects to TwinChat brain mode. */
	const LEGACY_SLUG = 'bizcity-twinbrain';

	/**
	 * Wire the admin hooks. Idempotent: calling twice registers only once.
	 */
	public static function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		if ( ! has_action( 'admin_menu', array( __CLASS__, 'register_menus' ) ) ) {
			add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 30 );
		}
		if ( ! has_action( 'admin_init', array( __CLASS__, 'redirect_legacy_page' ) ) ) {
			add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_page' ) );
		}
	}

	/**
	 * Resolve the Control Panel URL without requiring TwinShell to be loaded.
	 *
	 * TwinShell may load after this file or against a stale/partial artifact;
	 * never fatal the whole wp-admin menu when its helper is unavailable.
	 */
	private static function panel_url(): string {
		if ( class_exists( 'BizCity_Twin_Shell_Page' ) && method_exists( 'BizCity_Twin_Shell_Page', 'panel_url' ) ) {
			return (string) BizCity_Twin_Shell_Page::panel_url();
		}
		return home_url( '/twin/panel/' );
	}

	/**
	 * Default landing for Twin Brain: the TwinChat workspace inside TwinShell.
	 *
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-HOTFIX3 — opening Twin Brain must land
	 * in TwinChat; only the Settings-type submenus go to the Control Panel. `add_query_arg()` does not encode
	 * values, so `_iurl` is encoded here, otherwise its own `?` would split the query string.
	 */
	private static function twinchat_url(): string {
		$args = array(
			'plugin' => 'twinchat',
			'_iurl'  => rawurlencode( '/twinchat/?bizcity_iframe=1' ),
		);
		if ( class_exists( 'BizCity_Twin_Shell_Page' ) && method_exists( 'BizCity_Twin_Shell_Page', 'shell_url' ) ) {
			return (string) BizCity_Twin_Shell_Page::shell_url( $args );
		}
		return add_query_arg( $args, home_url( '/twin/' ) );
	}

	/**
	 * A Control Panel destination opened inside TwinShell (`plugin=settings`), so the ActivityBar stays visible.
	 *
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — the panel's hash route rides in the encoded
	 * `_iurl`; linking to bare /twin/panel/ would cost an extra client-side hop into the shell.
	 */
	private static function panel_in_shell_url( string $destination ): string {
		$panel_path = (string) wp_parse_url( self::panel_url(), PHP_URL_PATH );
		$iurl       = ( '' !== $panel_path ? $panel_path : '/twin/panel/' ) . '?bizcity_iframe=1#/setting-panel/' . rawurlencode( $destination );
		$args       = array(
			'plugin' => 'settings',
			'_iurl'  => rawurlencode( $iurl ),
		);
		if ( class_exists( 'BizCity_Twin_Shell_Page' ) && method_exists( 'BizCity_Twin_Shell_Page', 'shell_url' ) ) {
			return (string) BizCity_Twin_Shell_Page::shell_url( $args );
		}
		return add_query_arg( $args, home_url( '/twin/' ) );
	}

	public static function register_menus(): void {
		// [2026-09-16 01:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-HOTFIX2 — the parent slug must differ from the legacy `bizcity-twinbrain` page, otherwise the admin_init compatibility redirect bounces every click on this new top-level menu straight to TwinChat.
		$parent    = self::PARENT_SLUG;

		add_menu_page(
			__( 'Twin Brain (Não tổng)', 'bizcity-twin-ai' ),
			__( 'Twin Brain', 'bizcity-twin-ai' ),
			'read',
			$parent,
			static function () {
				// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-HOTFIX3 — the parent page is a router; its default is TwinChat, not the Control Panel.
				wp_safe_redirect( self::twinchat_url() );
				exit;
			},
			'dashicons-format-chat',
			5
		);

		// [2026-09-16 10:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6 — expose the six canonical Control Panel destinations as stable Twin Brain submenu deep-links.
		$destinations = array(
			'workspace'        => array( 'Twin Brain', 'Twin Brain' ),
			'settings'         => array( 'Settings', 'Cài đặt' ),
			'control-panel'    => array( 'Modules & Extensions', 'Mô-đun & Tiện ích' ),
			'channel-settings' => array( 'Channel Settings', 'Cài đặt kênh' ),
			'crm-inbox'        => array( 'CRM Inbox', 'Hộp thư CRM' ),
			'plugins-store'    => array( 'Plugins Store', 'Kho tiện ích' ),
		);
		foreach ( $destinations as $destination => $labels ) {
			add_submenu_page(
				$parent,
				$labels[0],
				$labels[1],
				'manage_options',
				'bizcity-twinbrain-' . $destination,
				static function () use ( $destination ) {
					// WordPress links the top-level "Twin Brain" item to this first submenu, so the Brain entry
					// must open TwinChat too; Settings and the other destinations open the Control Panel in TwinShell.
					if ( 'workspace' === $destination ) {
						wp_safe_redirect( self::twinchat_url() );
						exit;
					}
					wp_safe_redirect( self::panel_in_shell_url( $destination ) );
					exit;
				}
			);
		}

		// [2026-09-16 10:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6 — add the member-facing Twin GPT entry without exposing credentials or a second Brain owner.
		$gpt_url = home_url( '/gpt/' );
		add_submenu_page(
			$parent,
			__( 'Twin GPT', 'bizcity-twin-ai' ),
			__( 'Twin GPT · Mở /gpt/', 'bizcity-twin-ai' ),
			'read',
			'bizcity-twin-gpt',
			'__return_null'
		);

		// Rewrite the submenu href to the real front-end URL, then mark it as a new tab.
		add_action( 'admin_menu', static function () use ( $parent, $gpt_url ) {
			global $submenu;
			if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
				return;
			}
			foreach ( $submenu[ $parent ] as $index => $item ) {
				if ( isset( $item[2] ) && 'bizcity-twin-gpt' === (string) $item[2] ) {
					$submenu[ $parent ][ $index ][2] = $gpt_url;
					break;
				}
			}
		}, 99 );

		add_action( 'admin_footer', static function () use ( $gpt_url ) {
			if ( ! is_admin() ) {
				return;
			}
			$encoded_url = wp_json_encode( esc_url_raw( $gpt_url ) );
			echo '<script>(function(){var url=' . $encoded_url . ';var links=document.querySelectorAll("#adminmenu a");for(var i=0;i<links.length;i++){if(links[i].href===url||links[i].getAttribute("href")===url){links[i].target="_blank";links[i].rel="noopener";break;}}})();</script>';
		}, 99 );
	}

	/**
	 * Keep bookmarks to the legacy `bizcity-twinbrain` page working.
	 */
	public static function redirect_legacy_page(): void {
		if ( ! is_admin() || empty( $_GET['page'] ) || sanitize_key( (string) $_GET['page'] ) !== self::LEGACY_SLUG ) {
			return;
		}
		$target = add_query_arg(
			array( 'page' => 'bizcity-twinchat', 'mode' => 'brain' ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $target );
		exit;
	}
}
