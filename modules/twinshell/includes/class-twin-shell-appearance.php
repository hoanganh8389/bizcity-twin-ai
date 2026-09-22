<?php
/**
 * TwinShell — Appearance (site) and User Preferences (user) owner.
 *
 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6-03 — `core.twinshell.appearance`
 * (scope=site) and `core.twinshell.user_preferences` (scope=user) were registered with no renderer and
 * no storage: the only theme state lived in one browser's localStorage. This class is the value owner
 * for both, and the Control Panel renders them in-panel through the routes below.
 *
 * Storage:
 *   option    bizcity_twin_shell_appearance   { theme, density, reduce_motion }            (site)
 *   user meta bizcity_twin_shell_preferences  { theme, density, reduce_motion }            (user)
 *   user meta locale                          WordPress core per-user language (core owns it)
 *
 * Site language (WPLANG) is owned by WordPress core General Settings and is only reported here.
 *
 * Routes (bizcity-twinchat/v1, cookie + X-WP-Nonce):
 *   GET|POST /settings/appearance        manage_options
 *   GET|POST /settings/user-preferences  logged-in user, own record only
 *
 * PHP 7.4 compat.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 * @since      2026-09-16
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Shell_Appearance' ) ) {
	return;
}

final class BizCity_Twin_Shell_Appearance {

	const NS          = 'bizcity-twinchat/v1';
	const SITE_OPTION = 'bizcity_twin_shell_appearance';
	const USER_META   = 'bizcity_twin_shell_preferences';

	const THEMES    = array( 'light', 'dark', 'auto' );
	const DENSITIES = array( 'comfortable', 'compact' );

	const SITE_DEFAULTS = array(
		'theme'         => 'light',
		'density'       => 'comfortable',
		'reduce_motion' => false,
	);

	const USER_DEFAULTS = array(
		'theme'         => 'inherit',
		'density'       => 'inherit',
		'reduce_motion' => 'inherit',
	);

	public static function boot() {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/settings/appearance', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get_site' ),
				'permission_callback' => array( __CLASS__, 'can_manage_site' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_save_site' ),
				'permission_callback' => array( __CLASS__, 'can_manage_site' ),
			),
		) );

		register_rest_route( self::NS, '/settings/user-preferences', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get_user' ),
				'permission_callback' => array( __CLASS__, 'is_logged_in' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_save_user' ),
				'permission_callback' => array( __CLASS__, 'is_logged_in' ),
			),
		) );
	}

	public static function can_manage_site() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', 'You cannot change the site appearance.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function is_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
		}
		return true;
	}

	// ── Values ───────────────────────────────────────────────────────────

	/**
	 * @return array{theme:string,density:string,reduce_motion:bool}
	 */
	public static function site_values() {
		$raw = get_option( self::SITE_OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'theme'         => in_array( $raw['theme'] ?? '', self::THEMES, true ) ? (string) $raw['theme'] : self::SITE_DEFAULTS['theme'],
			'density'       => in_array( $raw['density'] ?? '', self::DENSITIES, true ) ? (string) $raw['density'] : self::SITE_DEFAULTS['density'],
			'reduce_motion' => ! empty( $raw['reduce_motion'] ),
		);
	}

	/**
	 * @param int $user_id
	 * @return array{theme:string,density:string,reduce_motion:string}
	 */
	public static function user_values( $user_id ) {
		$raw = $user_id > 0 ? get_user_meta( (int) $user_id, self::USER_META, true ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'theme'         => in_array( $raw['theme'] ?? '', array_merge( array( 'inherit' ), self::THEMES ), true ) ? (string) $raw['theme'] : 'inherit',
			'density'       => in_array( $raw['density'] ?? '', array_merge( array( 'inherit' ), self::DENSITIES ), true ) ? (string) $raw['density'] : 'inherit',
			'reduce_motion' => in_array( $raw['reduce_motion'] ?? '', array( 'inherit', 'on', 'off' ), true ) ? (string) $raw['reduce_motion'] : 'inherit',
		);
	}

	/**
	 * What the shell should actually apply for this user: user choice over site default.
	 *
	 * @param int $user_id
	 * @return array{theme:string,density:string,reduce_motion:bool}
	 */
	public static function effective( $user_id ) {
		$site = self::site_values();
		$user = self::user_values( (int) $user_id );
		return array(
			'theme'         => 'inherit' !== $user['theme'] ? $user['theme'] : $site['theme'],
			'density'       => 'inherit' !== $user['density'] ? $user['density'] : $site['density'],
			'reduce_motion' => 'inherit' !== $user['reduce_motion'] ? ( 'on' === $user['reduce_motion'] ) : $site['reduce_motion'],
		);
	}

	// ── Site appearance ──────────────────────────────────────────────────

	public static function handle_get_site( WP_REST_Request $request ) {
		return new WP_REST_Response( self::site_projection(), 200 );
	}

	public static function handle_save_site( WP_REST_Request $request ) {
		$params = self::params( $request );
		$values = self::site_values();
		$errors = array();

		if ( array_key_exists( 'theme', $params ) ) {
			if ( in_array( (string) $params['theme'], self::THEMES, true ) ) {
				$values['theme'] = (string) $params['theme'];
			} else {
				$errors['theme'] = __( 'Choose light, dark or automatic.', 'bizcity-twin-ai' );
			}
		}
		if ( array_key_exists( 'density', $params ) ) {
			if ( in_array( (string) $params['density'], self::DENSITIES, true ) ) {
				$values['density'] = (string) $params['density'];
			} else {
				$errors['density'] = __( 'Choose comfortable or compact.', 'bizcity-twin-ai' );
			}
		}
		if ( array_key_exists( 'reduce_motion', $params ) ) {
			$values['reduce_motion'] = (bool) rest_sanitize_boolean( $params['reduce_motion'] );
		}

		if ( ! empty( $errors ) ) {
			return self::invalid( $errors );
		}

		update_option( self::SITE_OPTION, $values, false );

		$payload            = self::site_projection();
		$payload['saved']   = true;
		$payload['message'] = __( 'Site appearance saved.', 'bizcity-twin-ai' );
		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function site_projection() {
		$site_locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		return array(
			'success'     => true,
			'contract'    => 'twinshell-appearance',
			'version'     => '1.0.0',
			'owner'       => 'modules/twinshell',
			'scope'       => 'site',
			'values'      => self::site_values(),
			'defaults'    => self::SITE_DEFAULTS,
			'options'     => array(
				'theme'   => self::THEMES,
				'density' => self::DENSITIES,
			),
			'site_locale' => array(
				'code'       => $site_locale,
				'label'      => self::language_label( $site_locale ),
				'manage_url' => admin_url( 'options-general.php' ),
			),
			'effective'   => self::effective( (int) get_current_user_id() ),
		);
	}

	// ── User preferences ─────────────────────────────────────────────────

	public static function handle_get_user( WP_REST_Request $request ) {
		return new WP_REST_Response( self::user_projection( (int) get_current_user_id() ), 200 );
	}

	public static function handle_save_user( WP_REST_Request $request ) {
		$user_id = (int) get_current_user_id();
		$params  = self::params( $request );
		$values  = self::user_values( $user_id );
		$errors  = array();

		if ( array_key_exists( 'theme', $params ) ) {
			if ( in_array( (string) $params['theme'], array_merge( array( 'inherit' ), self::THEMES ), true ) ) {
				$values['theme'] = (string) $params['theme'];
			} else {
				$errors['theme'] = __( 'Choose site default, light, dark or automatic.', 'bizcity-twin-ai' );
			}
		}
		if ( array_key_exists( 'density', $params ) ) {
			if ( in_array( (string) $params['density'], array_merge( array( 'inherit' ), self::DENSITIES ), true ) ) {
				$values['density'] = (string) $params['density'];
			} else {
				$errors['density'] = __( 'Choose site default, comfortable or compact.', 'bizcity-twin-ai' );
			}
		}
		if ( array_key_exists( 'reduce_motion', $params ) ) {
			if ( in_array( (string) $params['reduce_motion'], array( 'inherit', 'on', 'off' ), true ) ) {
				$values['reduce_motion'] = (string) $params['reduce_motion'];
			} else {
				$errors['reduce_motion'] = __( 'Choose site default, on or off.', 'bizcity-twin-ai' );
			}
		}

		$locale = null;
		if ( array_key_exists( 'locale', $params ) ) {
			$candidate = (string) $params['locale'];
			$allowed   = wp_list_pluck( self::languages(), 'code' );
			if ( '' === $candidate || in_array( $candidate, $allowed, true ) ) {
				$locale = $candidate;
			} else {
				$errors['locale'] = __( 'That language is not installed on this site.', 'bizcity-twin-ai' );
			}
		}

		if ( ! empty( $errors ) ) {
			return self::invalid( $errors );
		}

		update_user_meta( $user_id, self::USER_META, $values );
		if ( null !== $locale ) {
			// WordPress core owns the per-user language; write the same meta profile.php writes.
			update_user_meta( $user_id, 'locale', $locale );
		}

		$payload                   = self::user_projection( $user_id );
		$payload['saved']          = true;
		$payload['locale_changed'] = null !== $locale;
		$payload['message']        = __( 'Your preferences were saved.', 'bizcity-twin-ai' );
		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * @param int $user_id
	 * @return array<string,mixed>
	 */
	public static function user_projection( $user_id ) {
		$user_locale = (string) get_user_meta( (int) $user_id, 'locale', true );
		return array(
			'success'   => true,
			'contract'  => 'twinshell-user-preferences',
			'version'   => '1.0.0',
			'owner'     => 'modules/twinshell',
			'scope'     => 'user',
			'values'    => array_merge( self::user_values( (int) $user_id ), array( 'locale' => $user_locale ) ),
			'site'      => self::site_values(),
			'effective' => self::effective( (int) $user_id ),
			'options'   => array(
				'theme'         => array_merge( array( 'inherit' ), self::THEMES ),
				'density'       => array_merge( array( 'inherit' ), self::DENSITIES ),
				'reduce_motion' => array( 'inherit', 'on', 'off' ),
			),
			'languages' => self::languages(),
			'site_locale' => array(
				'code'  => function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US',
				'label' => self::language_label( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' ),
			),
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Installed languages the user may pick. English (en_US) is always available.
	 *
	 * @return array<int,array{code:string,label:string}>
	 */
	public static function languages() {
		$codes = function_exists( 'get_available_languages' ) ? (array) get_available_languages() : array();
		array_unshift( $codes, 'en_US' );
		$codes = array_values( array_unique( array_filter( array_map( 'strval', $codes ) ) ) );
		$out   = array();
		foreach ( $codes as $code ) {
			$out[] = array(
				'code'  => $code,
				'label' => self::language_label( $code ),
			);
		}
		return $out;
	}

	/**
	 * @param string $code
	 * @return string
	 */
	private static function language_label( $code ) {
		$known = array(
			'en_US' => 'English (United States)',
			'vi'    => 'Tiếng Việt',
			'vi_VN' => 'Tiếng Việt',
		);
		return isset( $known[ $code ] ) ? $known[ $code ] : (string) $code;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function params( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		return is_array( $params ) ? $params : array();
	}

	/**
	 * @param array<string,string> $fields
	 * @return WP_REST_Response
	 */
	private static function invalid( array $fields ) {
		return new WP_REST_Response( array(
			'success'   => false,
			'code'      => 'invalid_settings',
			'message'   => __( 'Some fields need attention before saving.', 'bizcity-twin-ai' ),
			'hint'      => __( 'Fix the highlighted fields; nothing was saved.', 'bizcity-twin-ai' ),
			'help_code' => 'appearance_invalid',
			'fields'    => $fields,
		), 400 );
	}
}
