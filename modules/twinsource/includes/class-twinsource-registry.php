<?php
/**
 * Twinsource Registry — per-plugin default capabilities.
 *
 * Plugins register themselves so Twinsource knows the right defaults
 * when host omits `capabilities`.
 *
 * @package Bizcity_Twin_AI\Twinsource
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Twinsource_Registry {

	/** @var array<string,array> plugin_id => capability map */
	private static $plugins = [];

	public static function register( string $plugin_id, array $capabilities = [] ): void {
		self::$plugins[ sanitize_key( $plugin_id ) ] = $capabilities;
	}

	public static function get( string $plugin_id ): array {
		return self::$plugins[ sanitize_key( $plugin_id ) ] ?? [];
	}

	public static function all(): array {
		return self::$plugins;
	}
}

// Built-in defaults for known plugins. Other plugins call ::register() in their bootstrap.
add_action( 'init', static function () {
	BizCity_Twinsource_Registry::register( 'twinchat', [
		'add_file' => true, 'add_url' => true, 'add_text' => true,
		'web_search' => true, 'borrow' => true, 'delete' => true, 'select_filter' => true,
	] );
	BizCity_Twinsource_Registry::register( 'bzdoc', [
		'add_file' => true, 'add_url' => true, 'add_text' => true,
		'web_search' => true, 'borrow' => true, 'delete' => true, 'select_filter' => false, // doc gen dùng all sources
	] );
	BizCity_Twinsource_Registry::register( 'creator', [
		'add_file' => true, 'add_url' => true, 'add_text' => true,
		'web_search' => true, 'borrow' => true, 'delete' => true, 'select_filter' => true,
	] );
}, 5 );
