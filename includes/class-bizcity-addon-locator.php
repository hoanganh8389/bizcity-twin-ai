<?php
/**
 * Add-on locator — where code that left the main plugin now lives (R-LEAN-4, Q-W16-1).
 *
 * [2026-10-01 Claude Opus 5.5] CORE-REDUCTION WP-16 B-4 S1 — TwinBrain / Automation leave bizcity-twin-ai for the
 * sibling plugin bizcity-twin-brain-addon. The main plugin keeps its request gates (it decides WHEN a part loads);
 * this class only answers WHERE the part's files are.
 *
 * Load mode (wp-config.php, optional): define( 'BIZCITY_BRAIN_ADDON_LOAD', 'auto' | 'active' | 'off' );
 *   auto   (default) — load from the add-on folder when it is present, activated or not, so a deploy that uploads
 *                      both folders changes nothing for users;
 *   active — load only when the add-on is activated (site or network);
 *   off    — never load the add-on parts (the main plugin runs without TwinBrain / Automation).
 * WordPress loads bizcity-twin-ai before bizcity-twin-brain-addon (alphabetical), so the main plugin cannot wait
 * for an add-on constant; it checks the folder directly.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Addon_Locator', false ) ) {
	return;
}

final class BizCity_Addon_Locator {

	const SLUG = 'bizcity-twin-brain-addon';

	/** @var array<string,string> */
	private static $cache = array();

	public static function mode(): string {
		$mode = defined( 'BIZCITY_BRAIN_ADDON_LOAD' ) ? strtolower( (string) BIZCITY_BRAIN_ADDON_LOAD ) : 'auto';
		return in_array( $mode, array( 'auto', 'active', 'off' ), true ) ? $mode : 'auto';
	}

	public static function dir(): string {
		$base = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( __DIR__, 2 );
		return rtrim( str_replace( '\\', '/', $base ), '/' ) . '/' . self::SLUG . '/';
	}

	public static function is_active(): bool {
		$main = self::SLUG . '/' . self::SLUG . '.php';
		if ( function_exists( 'get_option' ) && in_array( $main, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			return isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $main ] );
		}
		return false;
	}

	public static function available(): bool {
		$mode = self::mode();
		if ( 'off' === $mode || ! is_dir( self::dir() ) ) {
			return false;
		}
		return 'auto' === $mode || self::is_active();
	}

	/**
	 * Absolute path of a file inside the add-on ('' when the add-on is unavailable or the file is missing).
	 *
	 * @param string $relative e.g. 'automation/bootstrap.php'
	 */
	public static function file( string $relative ): string {
		if ( isset( self::$cache[ $relative ] ) ) {
			return self::$cache[ $relative ];
		}
		$path = '';
		if ( self::available() ) {
			$candidate = self::dir() . ltrim( $relative, '/' );
			$path      = is_file( $candidate ) ? $candidate : '';
		}
		return self::$cache[ $relative ] = $path;
	}
}
