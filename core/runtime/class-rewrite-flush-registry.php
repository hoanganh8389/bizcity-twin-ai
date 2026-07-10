<?php
/**
 * BizCity_Rewrite_Flush_Registry
 *
 * Central registry for all rewrite-rule flush registrations across the plugin
 * bundle. Every module/plugin that registers custom rewrite rules with
 * add_rewrite_rule() MUST call ::register() instead of calling
 * flush_rewrite_rules() directly from any init hook.
 *
 * Architecture:
 *  1. At file-load time, modules call ::register('module-id', 'stable-version').
 *  2. At plugins_loaded:5, ::check_all_versions() compares stored vs registered.
 *     Any version mismatch → marks global pending flag (ONE DB write, not N).
 *  3. At admin_init:1, ::flush_if_pending() fires ONE flush_rewrite_rules(false).
 *     By admin_init, ALL plugins' init hooks have run — Transposh, WooCommerce
 *     endpoints, every add_rewrite_rule() call is registered. The saved rules
 *     are complete, so WC/Transposh will NOT detect a mismatch on wp_loaded.
 *
 * Key properties:
 *  - ONE flush_rewrite_rules() call per admin request regardless of how many
 *    modules have version bumps (instead of N per-module calls).
 *  - Version map written BEFORE the flush → no infinite retry on fatal error.
 *  - Pending flag cleared BEFORE the flush → same idempotency guarantee.
 *  - No time(), rand(), or runtime-variable values allowed in version strings.
 *
 * @see    docs/rules/PHASE-0-RULE-CENTRAL-REGISTRY.md §2 R-RFR
 * @since  1.3.8
 * @author Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-09 Johnny Chu] R-CR — Central Rewrite Flush Registry (new file)
final class BizCity_Rewrite_Flush_Registry {

	/**
	 * WP option storing JSON-serialized { module_id => last_flushed_version } map.
	 * Written BEFORE flush so version guard persists even if flush throws.
	 */
	const MODULES_OPTION = 'bizcity_rewrite_flush_versions';

	/**
	 * WP flag option — truthy (1) when at least one module needs a flush.
	 * Cleared before flush_rewrite_rules() is called.
	 */
	const PENDING_OPTION = 'bizcity_rewrite_flush_pending';

	/** @var array<string, string> In-memory: module_id => stable_version */
	private static $registrations = array();

	/** @var bool Prevent duplicate boot (safe in mu-plugin + regular plugin load). */
	private static $booted = false;

	/* ── Public API ──────────────────────────────────────────────────── */

	/**
	 * Boot registry hooks.
	 *
	 * Call ONCE from core/runtime/bootstrap.php at file scope.
	 * Idempotent — safe to call more than once (guarded by $booted flag).
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'plugins_loaded', array( __CLASS__, 'check_all_versions' ), 5 );
		add_action( 'admin_init',     array( __CLASS__, 'flush_if_pending' ), 1 );
		// [2026-06-26 Johnny Chu] R-PERF — wp_loaded hook registered conditionally
		// in check_all_versions() and queue_flush() only when flush needed.
		// Avoids 1 DB query (get_option PENDING_OPTION, not autoloaded) per frontend
		// request on sites without Redis.
	}

	/**
	 * Register a module's stable rewrite version.
	 *
	 * Call at FILE-LOAD TIME (outside any hook) in the module/plugin main file
	 * or its bootstrap.php. The registry aggregates all ::register() calls
	 * before plugins_loaded fires, then resolves them in a single pass.
	 *
	 * RULE: $stable_version MUST be a static string constant or literal.
	 *       NEVER pass time(), rand(), microtime(), or any runtime-variable value.
	 *       A version that changes on every request defeats the guard completely.
	 *
	 * @param string $module_id      Unique module/plugin ID, e.g. 'bizcity-doc'
	 * @param string $stable_version Static version string, e.g. '0.4.83'
	 */
	public static function register( $module_id, $stable_version ) {
		self::$registrations[ (string) $module_id ] = (string) $stable_version;
	}

	/**
	 * Queue a one-time flush on next admin_init, bypassing version comparison.
	 *
	 * For use ONLY in:
	 *  - Plugin activation/deactivation hooks (register_activation_hook)
	 *  - AJAX handlers that activate/deactivate sub-plugins at runtime
	 *
	 * NOT for version-bump based flushes — use ::register() for those.
	 *
	 * @param string $module_id Caller module ID for audit trail.
	 */
	public static function queue_flush( $module_id ) {
		$stored = get_option( self::MODULES_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		// Stamp sentinel so next check_all_versions() also schedules flush.
		$stored[ (string) $module_id ] = '__queued__';
		update_option( self::MODULES_OPTION, $stored, false );
		update_option( self::PENDING_OPTION, 1, false );

		// [2026-06-26 Johnny Chu] R-PERF — register wp_loaded hook ONLY when needed.
		if ( ! is_admin() && ! did_action( 'wp_loaded' ) ) {
			add_action( 'wp_loaded', array( __CLASS__, 'flush_if_pending' ), 99 );
		}
	}

	/* ── Internal hooks (public for WP add_action compatibility) ─────── */

	/**
	 * Compare all registered module versions against persisted values.
	 * Sets PENDING_OPTION when any version has changed.
	 * Hooked to plugins_loaded:5 by ::boot().
	 */
	public static function check_all_versions() {
		if ( empty( self::$registrations ) ) {
			return;
		}

		$stored = get_option( self::MODULES_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$needs_flush = false;
		foreach ( self::$registrations as $module_id => $version ) {
			$current = isset( $stored[ $module_id ] ) ? (string) $stored[ $module_id ] : '';
			if ( $current !== $version ) {
				$stored[ $module_id ] = $version;
				$needs_flush          = true;
			}
		}

		if ( $needs_flush ) {
			// Write new version map FIRST — stops retry on next request even if flush throws.
			update_option( self::MODULES_OPTION, $stored, false );
			update_option( self::PENDING_OPTION, 1, false );

			// [2026-06-26 Johnny Chu] R-PERF — register wp_loaded hook ONLY when needed.
			// Frontend heal: flush on wp_loaded:99 when pending (admin_init doesn't fire
			// on frontend). wp_loaded is post-parse, doesn't violate R-CR.1 (no init flush).
			if ( ! is_admin() ) {
				add_action( 'wp_loaded', array( __CLASS__, 'flush_if_pending' ), 99 );
			}
		}
	}

	/**
	 * Execute ONE consolidated flush_rewrite_rules() on admin_init:1.
	 * By admin_init, all plugins' add_rewrite_rule() calls are registered.
	 * Transposh and WooCommerce endpoints are fully in place → saved rules
	 * are complete → WC::maybe_flush_rewrite_rules() on wp_loaded is a no-op.
	 */
	public static function flush_if_pending() {
		if ( ! get_option( self::PENDING_OPTION ) ) {
			return;
		}
		delete_option( self::PENDING_OPTION ); // clear BEFORE flush — idempotency
		flush_rewrite_rules( false );
	}
}
