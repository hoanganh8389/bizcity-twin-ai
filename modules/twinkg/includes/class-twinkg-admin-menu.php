<?php
/**
 * Bizcity Twin AI — TwinKG Admin Menu
 *
 * Two jobs, both thin:
 *   1. `admin.php?page=bizcity-twinkg` — a Twin Shell iframe, so an operator who
 *      lives in wp-admin keeps the WordPress admin bar and sidebar. The React
 *      bundle is NEVER enqueued into wp-admin; the iframe loads `/twinkg/`
 *      through the shell, which is the one place the app is served.
 *   2. Compatibility redirects for the slugs the KG UI used to answer on.
 *
 * Ownership (WP-09 §3 / proposed rule R-KG-UI): VIEW only — no table, option,
 * cron or REST route.
 *
 * PHP 7.4 compatible — no match, no enums, no nullsafe, no readonly.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinKG
 * @since      2026-09-24
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinKG_Admin_Menu {

	const PAGE_SLUG   = 'bizcity-twinkg';
	const PARENT_SLUG = 'bizcity-twin-workspace';

	/**
	 * Slugs that used to render the KG React app from `core/knowledge`.
	 *
	 * `bizcity-kg-hub`         — the main mount (BizCity_KG_Admin_Menu::PAGE_SLUG).
	 * `bizcity-twinchat-gurus` — "Nâng cấp Connector", removed from the menu in
	 *                            includes/class-admin-menu.php but still bookmarked.
	 * `bizcity-kg-hub-settings` — the PHP Cost Guard form (BizCity_KG_Settings_Page),
	 *                            retired in WP-09 T4; still linked from quota errors
	 *                            recorded before the change.
	 *
	 * Value = the `view` the app should open on, or '' to keep its own state.
	 */
	const LEGACY_SLUGS = array(
		'bizcity-kg-hub'          => '',
		'bizcity-twinchat-gurus'  => 'gurus',
		'bizcity-kg-hub-settings' => 'settings',
	);

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Knowledge Graph', 'bizcity-twin-ai' ),
			__( 'Knowledge Graph', 'bizcity-twin-ai' ),
			self::menu_cap(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Capability for the wp-admin entry. Network Super Admins without a local
	 * admin role would otherwise lose the menu item on a sub-site.
	 *
	 * @return string
	 */
	public static function menu_cap(): string {
		return class_exists( 'BizCity_Network_Admin_Capability' )
			? BizCity_Network_Admin_Capability::menu_cap()
			: 'manage_options';
	}

	/**
	 * Send the legacy KG admin slugs to the canonical `/twinkg/` surface.
	 *
	 * Hooked on admin_init@0 — before wp-admin emits any HTML, so the redirect is
	 * never "headers already sent".
	 *
	 * While `core/knowledge` still registers its own `BizCity_KG_Admin_Menu`
	 * (the overlap window of WP-09 steps T1-T2) this does nothing: two owners
	 * answering one slug, one of them with a redirect, would make the old page
	 * unreachable before the new surface is accepted. Once that class is retired
	 * in step T3 the redirect takes over by itself, which also keeps a partial
	 * deploy (new module, old core) working.
	 */
	public function redirect_legacy_slugs(): void {
		if ( class_exists( 'BizCity_KG_Admin_Menu' ) ) {
			return;
		}
		if ( ! is_admin() || empty( $_GET['page'] ) || ! is_user_logged_in() ) {
			return;
		}
		// Never interfere with an async request: admin-ajax.php and the REST
		// bridge both run in admin context, and a 302 there breaks the caller.
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		if ( ! array_key_exists( $page, self::LEGACY_SLUGS ) ) {
			return;
		}

		$view = self::LEGACY_SLUGS[ $page ];
		if ( '' === $view && ! empty( $_GET['view'] ) ) {
			$view = sanitize_key( wp_unslash( $_GET['view'] ) );
		}

		$args = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $view ) {
			$args['view'] = $view;
		}
		if ( ! empty( $_GET['notebook_id'] ) ) {
			$args['notebook_id'] = (int) $_GET['notebook_id'];
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 302 );
		exit;
	}

	/**
	 * Render the Twin Shell iframe. No bundle, no bootstrap object, no KG call —
	 * everything the app needs is served by `/twinkg/` inside the frame.
	 */
	public function render_page() {
		$shell_url = class_exists( 'BizCity_Twin_Shell_Page' )
			? BizCity_Twin_Shell_Page::shell_url( array( 'plugin' => 'twinkg' ) )
			: add_query_arg( 'plugin', 'twinkg', home_url( '/twin/' ) );

		foreach ( array( 'view', 'notebook_id', 'notebook', 'guru' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$shell_url = add_query_arg(
					$key,
					sanitize_text_field( wp_unslash( $_GET[ $key ] ) ),
					$shell_url
				);
			}
		}

		// `bizcity_admin_wrapper=1` is the structural loop-breaker: while it is
		// present the shell can never hand the top window back to admin.php, so a
		// /twin/ <-> admin.php redirect loop cannot form.
		$shell_url = esc_url( add_query_arg(
			array( 'bizcity_embed' => '1', 'bizcity_admin_wrapper' => '1' ),
			$shell_url
		) );
		?>
		<div class="wrap bizcity-twinkg-admin-shell" style="margin:0;">
			<iframe
				title="<?php echo esc_attr__( 'Knowledge Graph', 'bizcity-twin-ai' ); ?>"
				src="<?php echo $shell_url; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_url() above. ?>"
				style="display:block;width:calc(100% + 20px);height:calc(100vh - 32px);min-height:680px;border:0;background:#0f1115;"
				allow="clipboard-read; clipboard-write; fullscreen"
			></iframe>
		</div>
		<?php
	}
}
