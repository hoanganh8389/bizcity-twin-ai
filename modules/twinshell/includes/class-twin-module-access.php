<?php
/**
 * Twin Shell — module access resolver (contract module-access@1.0.0, PHASE-0.84).
 *
 * "Who may use module X" has one answer: current_user_can( 'bizcity_use_X' ), a meta capability
 * mapped here by the entry's `access.mode`:
 *   grantable  → primitive cap 'bizcity_access_X' on the user's roles / the user (WordPress already
 *                lets a user-level cap, including an explicit false, override the role).
 *   delegated  → the owner answers through the 'bizcity_module_access_delegate' filter (no owner ⇒ deny).
 *   admin_only → site admin (network super admin on multisite).
 * Site admins always pass. Visibility never grants authorization: module pages and REST call
 * self::can() / self::require_page() themselves.
 *
 * Scope: site (per blog). Storage: WordPress role/user capabilities of the current blog only (R-SCOPE-SYM).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Module_Access {

	const META_PREFIX  = 'bizcity_use_';
	const PRIM_PREFIX  = 'bizcity_access_';
	const SEEDED_OPTION = 'bizcity_twin_module_access_seeded';
	const SIGNATURE    = '0.84.1';
	const MODES        = [ 'grantable', 'delegated', 'admin_only' ];

	private static $registered = false;

	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'map_meta_cap', [ __CLASS__, 'map_meta_cap' ], 10, 4 );
		add_action( 'init', [ __CLASS__, 'ensure_seeded' ], 20 );
	}

	/**
	 * @param string[] $caps
	 * @param string   $cap
	 * @param int      $user_id
	 * @param array    $args
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! is_string( $cap ) || 0 !== strpos( $cap, self::META_PREFIX ) ) {
			return $caps;
		}
		$user_id = (int) $user_id;
		$id      = substr( $cap, strlen( self::META_PREFIX ) );
		$entry   = class_exists( 'BizCity_Twin_Shell_Registry' ) ? BizCity_Twin_Shell_Registry::instance()->get( $id ) : null;
		if ( ! $entry || empty( $entry['access'] ) || $user_id <= 0 ) {
			return [ 'do_not_allow' ];
		}
		if ( self::is_site_admin( $user_id ) ) {
			return [ 'exist' ];
		}
		switch ( $entry['access']['mode'] ) {
			case 'admin_only':
				return [ 'do_not_allow' ];
			case 'delegated':
				$ok = (bool) apply_filters( 'bizcity_module_access_delegate', false, $id, $user_id );
				return $ok ? [ 'exist' ] : [ 'do_not_allow' ];
			default:
				return [ self::PRIM_PREFIX . $id ];
		}
	}

	/**
	 * @param string $module_id
	 * @param int    $user_id   0 = current user.
	 * @return bool
	 */
	public static function can( $module_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
		return $user_id > 0 && user_can( $user_id, self::META_PREFIX . sanitize_key( (string) $module_id ) );
	}

	/**
	 * Whether module access is governed here (the entry declares `access`). Entries registered by
	 * third parties without `access` keep their own capability and are not guarded by this class.
	 *
	 * @param string $module_id
	 * @return bool
	 */
	public static function governs( $module_id ) {
		if ( ! class_exists( 'BizCity_Twin_Shell_Registry' ) ) {
			return false;
		}
		$entry = BizCity_Twin_Shell_Registry::instance()->get( $module_id );
		return $entry && ! empty( $entry['access'] );
	}

	/**
	 * Guard for a module's own page (embed/link target). Logged-out ⇒ login; denied ⇒ 403 page.
	 * No-op when the module is not governed here.
	 *
	 * @param string $module_id
	 */
	public static function require_page( $module_id ) {
		if ( ! self::governs( $module_id ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		if ( self::can( $module_id ) ) {
			return;
		}
		self::render_denied( $module_id );
		exit;
	}

	/**
	 * REST permission helper returning the R-ERROR-UX error.
	 *
	 * @param string $module_id
	 * @return true|WP_Error
	 */
	public static function rest_permission( $module_id ) {
		if ( ! self::governs( $module_id ) || self::can( $module_id ) ) {
			return true;
		}
		return new WP_Error(
			'capability_denied',
			__( 'You have not been given access to this module.', 'bizcity-twin-ai' ),
			[
				'status'    => is_user_logged_in() ? 403 : 401,
				'hint'      => __( 'Ask the site administrator to grant access.', 'bizcity-twin-ai' ),
				'help_code' => 'capability_denied',
				'module_id' => sanitize_key( (string) $module_id ),
			]
		);
	}

	/**
	 * Standalone 403 page shared by the shell and module pages (mockup screen ④).
	 *
	 * @param string $module_id
	 * @param string $home_url  Where "Back" goes; default = /twin/.
	 */
	public static function render_denied( $module_id, $home_url = '' ) {
		$entry = class_exists( 'BizCity_Twin_Shell_Registry' ) ? BizCity_Twin_Shell_Registry::instance()->get( $module_id ) : null;
		$label = $entry ? (string) $entry['label'] : (string) $module_id;
		if ( '' === $home_url ) {
			$home_url = class_exists( 'BizCity_Twin_Shell_Page' ) ? BizCity_Twin_Shell_Page::shell_url() : home_url( '/twin/' );
		}
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		$title = sprintf(
			/* translators: %s: module name */
			__( 'You cannot use %s yet', 'bizcity-twin-ai' ),
			$label
		);
		echo '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<style>:root{color-scheme:light dark}body{margin:0;font:14px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f8fafc;color:#111827;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}'
			. '.c{max-width:460px;width:100%;text-align:center;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:26px}.i{font-size:38px}h1{font-size:19px;margin:8px 0}p{color:#6b7280;margin:0 0 16px}'
			. 'a{display:inline-block;min-height:40px;line-height:40px;padding:0 16px;border-radius:10px;background:#4f46e5;color:#fff;text-decoration:none;font-weight:600}small{display:block;margin-top:14px;color:#9ca3af}'
			. '@media(prefers-color-scheme:dark){body{background:#0f172a;color:#e5e7eb}.c{background:#111827;border-color:#1f2937}p{color:#9ca3af}}</style></head><body>'
			. '<div class="c" role="alert"><div class="i" aria-hidden="true">🔒</div><h1>' . esc_html( $title ) . '</h1>'
			. '<p>' . esc_html__( 'The site administrator has not given your account access to this module.', 'bizcity-twin-ai' ) . '</p>'
			. '<a href="' . esc_url( $home_url ) . '" target="_top">' . esc_html__( 'Back', 'bizcity-twin-ai' ) . '</a>'
			. '<small>capability_denied</small></div></body></html>';
	}

	/**
	 * Seed primitive caps once per (blog, module) so turning the resolver on changes nobody's access:
	 * each role gets bizcity_access_<id> iff it held the entry's previous capability (or is listed in
	 * `access.default_roles`). Administrators always get it.
	 */
	public static function ensure_seeded() {
		if ( ! class_exists( 'BizCity_Twin_Shell_Registry' ) || ! function_exists( 'wp_roles' ) ) {
			return;
		}
		$seeded = get_option( self::SEEDED_OPTION, [] );
		$seeded = is_array( $seeded ) ? $seeded : [];
		$todo   = [];
		foreach ( BizCity_Twin_Shell_Registry::instance()->all() as $entry ) {
			if ( empty( $entry['access'] ) || 'grantable' !== $entry['access']['mode'] ) {
				continue;
			}
			if ( isset( $seeded[ $entry['id'] ] ) && self::SIGNATURE === $seeded[ $entry['id'] ] ) {
				continue;
			}
			$todo[] = $entry;
		}
		if ( empty( $todo ) ) {
			return;
		}
		foreach ( $todo as $entry ) {
			self::seed_entry( $entry );
			$seeded[ $entry['id'] ] = self::SIGNATURE;
		}
		update_option( self::SEEDED_OPTION, $seeded, true );
	}

	/**
	 * @param array $entry Normalized registry entry.
	 */
	private static function seed_entry( array $entry ) {
		$prim     = self::PRIM_PREFIX . $entry['id'];
		$defaults = isset( $entry['access']['default_roles'] ) ? (array) $entry['access']['default_roles'] : [];
		$legacy   = isset( $entry['access']['legacy_capability'] ) ? (string) $entry['access']['legacy_capability'] : 'read';
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			$grant = 'administrator' === $slug
				|| ( ! empty( $defaults ) ? in_array( $slug, $defaults, true ) : ( '' === $legacy || $role->has_cap( $legacy ) ) );
			if ( $grant && ! $role->has_cap( $prim ) ) {
				$role->add_cap( $prim );
			}
		}
	}

	/**
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_site_admin( $user_id ) {
		if ( class_exists( 'BizCity_Network_Admin_Capability' ) ) {
			return BizCity_Network_Admin_Capability::can_manage( (int) $user_id );
		}
		return user_can( (int) $user_id, 'manage_options' );
	}
}
