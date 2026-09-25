<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Shell
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Task 4.16.8
 * Feature-flag façade for the Intent → TwinShell migration rollout.
 *
 * Stored in a single wp_option for atomic updates:
 *
 *   update_option('bizcity_intent_shell_config', [
 *       'enabled'     => true,    // load the shell at all
 *       'shadow_mode' => true,    // run shell in parallel, return legacy
 *       'rollout_pct' => 5,       // % of traffic where shell is primary
 *       'allow_users' => [123],   // whitelist user_id (always shell)
 *       'deny_users'  => [],      // blacklist user_id (always legacy)
 *   ]);
 *
 * The `should_use_shell()` decision is deterministic per request so the same
 * (user_id, message_hash) always picks the same path within a rollout window —
 * makes diff comparison meaningful.
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Shell_Config {

	const OPTION_KEY = 'bizcity_intent_shell_config';

	const DEFAULTS = [
		'enabled'     => false,
		'shadow_mode' => false,
		'rollout_pct' => 0,
		'allow_users' => [],
		'deny_users'  => [],
	];

	/** Read the merged config (defaults overlaid by stored option). */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return array_merge( self::DEFAULTS, $stored );
	}

	public static function is_enabled(): bool {
		return (bool) self::get()['enabled'];
	}

	public static function is_shadow_enabled(): bool {
		// Shadow runs INDEPENDENTLY of `enabled`. Whole point of shadow is to
		// validate the new shell BEFORE flipping `enabled` for real users.
		return (bool) self::get()['shadow_mode'];
	}

	/**
	 * Decide whether THIS request should be served by the shell as primary
	 * (vs legacy with optional shadow). Deterministic per (user_id, message).
	 *
	 * @param array $params  Original Intent_Engine::process() params.
	 */
	public static function should_use_shell( array $params ): bool {
		$cfg = self::get();
		if ( empty( $cfg['enabled'] ) ) {
			return false;
		}

		$user_id = (int) ( $params['user_id'] ?? 0 );

		if ( in_array( $user_id, (array) $cfg['deny_users'], true ) ) {
			return false;
		}
		if ( in_array( $user_id, (array) $cfg['allow_users'], true ) ) {
			return true;
		}

		$pct = max( 0, min( 100, (int) $cfg['rollout_pct'] ) );
		if ( $pct <= 0 ) {
			return false;
		}
		if ( $pct >= 100 ) {
			return true;
		}

		// Deterministic bucket — hash of (user_id + message) mod 100.
		$key    = $user_id . '|' . ( $params['message'] ?? '' );
		$bucket = hexdec( substr( sha1( $key ), 0, 8 ) ) % 100;
		return $bucket < $pct;
	}

	/**
	 * Programmatic update (for admin UI / WP-CLI).
	 *
	 * @param array $patch  Partial config to merge over current.
	 */
	public static function update( array $patch ): bool {
		$next = array_merge( self::get(), $patch );
		return (bool) update_option( self::OPTION_KEY, $next, false );
	}
}
