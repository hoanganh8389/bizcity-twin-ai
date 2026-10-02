<?php
/**
 * Twin CRM — WordPress admin menu registration (lightweight, navigation-only).
 *
 * PHASE-0-SETTING-PANEL-G6-HOTFIX3 (2026-09-16); relocated CORE-REDUCTION WP-16 B-4 follow-up (2026-10-01).
 *
 * Why this file lives in core, not the add-on
 * --------------------------------------------
 * CORE-REDUCTION WP-16 B-4 moved the TwinBrain runtime (REST, schema, MPR orchestration) into the
 * sibling plugin `bizcity-twin-brain-addon`, resolved through `BizCity_Addon_Locator`. This ONE file
 * stayed behind in core by owner instruction: it is pure navigation (every entry is a redirect into
 * TwinShell or `/gpt/`), so it must render on every wp-admin page regardless of whether the add-on
 * plugin folder has been deployed. Routing it through the add-on locator made the entire "Twin CRM"
 * top-level menu silently vanish (no fatal, no notice) whenever the add-on wasn't present on the
 * server actually serving the request — the add-on is optional runtime, the menu entry is not.
 *
 * Original history (kept for context): the Twin Brain parent menu and its submenus used to be
 * registered inside `core/twinbrain/bootstrap.php` (admin_menu @30). That bootstrap is gated behind
 * `$_bizcity_admin_ctx && !$_bizcity_twinchat_admin_shell_request` in `bizcity-twin-ai.php`, so the
 * menu MUST be registered before that gate to exist on every admin page. The failure mode: when the
 * operator opened the TwinChat admin shell (`?page=bizcity-twinchat`),
 * `$_bizcity_twinchat_admin_shell_request` was true, the gated bootstrap was skipped, `admin_menu`
 * never saw the registration, and the whole menu disappeared from wp-admin.
 *
 * This file therefore owns *only* admin-menu registration. It declares no runtime/REST/schema
 * behaviour, performs no query, and is safe to load on any request that can render wp-admin. The
 * heavy TwinBrain runtime stays behind the existing `$_bizcity_admin_ctx` gate in the add-on's own
 * `twinbrain/bootstrap.php`, loaded separately through `BizCity_Addon_Locator`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core
 * @since      2026-09-16 (PHASE-0-SETTING-PANEL-G6-HOTFIX3); moved to core 2026-10-01
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
	 * Default landing for the Twin CRM menu: bare /twin/, so TwinShell opens its own
	 * default entry (Twin GPT — PHASE-0.84 D-84-2), instead of forcing TwinChat.
	 *
	 * [2026-09-30 Claude Sonnet 5] Owner directive — renamed from twinchat_url(); no longer forces TwinChat.
	 */
	private static function twin_url(): string {
		if ( class_exists( 'BizCity_Twin_Shell_Page' ) && method_exists( 'BizCity_Twin_Shell_Page', 'shell_url' ) ) {
			return (string) BizCity_Twin_Shell_Page::shell_url();
		}
		return home_url( '/twin/' );
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
			__( 'Twin CRM', 'bizcity-twin-ai' ),
			__( 'Twin CRM', 'bizcity-twin-ai' ),
			'read',
			$parent,
			static function () {
				// [2026-09-30 Claude Sonnet 5] Owner directive — the parent page is a router into TwinShell at bare /twin/.
				wp_safe_redirect( self::twin_url() );
				exit;
			},
			'dashicons-format-chat',
			5
		);

		// [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — manage_options wrongly denied a Network Super Admin with no local blog role on every destination in this loop.
		$capability = class_exists( 'BizCity_Network_Admin_Capability' )
			? BizCity_Network_Admin_Capability::menu_cap()
			: 'manage_options';
		// [2026-09-16 10:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6 — expose the five canonical
		// Control Panel destinations as stable Twin CRM submenu deep-links. WordPress auto-adds a first submenu
		// row for the parent slug itself (labelled "Twin CRM", opening twin_url()); no explicit 'workspace' entry
		// is needed for that any more (it used to force TwinChat and duplicated that auto row).
		$destinations = array(
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
				$capability,
				'bizcity-twinbrain-' . $destination,
				static function () use ( $destination ) {
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
