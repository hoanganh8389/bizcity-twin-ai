<?php
/**
 * BizCity_Cache — Unified cache helper for bizcity-twin-ai bundle.
 *
 * Provides a consistent two-tier caching strategy across all modules:
 *   Tier 1 — WP Object Cache (in-memory, current request only, always fast).
 *             Uses wp_cache_get/set. On object-cache-enabled hosts this
 *             persists in Redis/Memcached across requests.
 *   Tier 2 — WP Transient (persistent, survives across requests even without
 *             object cache). Optional; use for expensive queries that benefit
 *             from cross-request persistence.
 *
 * Usage pattern for a read function:
 *
 *   public static function get_all(): array {
 *       // 1. Declare cache contract in docblock (see R-CACHE rule).
 *       $cached = BizCity_Cache::get( 'bzcc', 'templates_all' );
 *       if ( $cached !== false ) { return $cached; }
 *
 *       global $wpdb;
 *       $result = $wpdb->get_results( "SELECT * FROM ..." );
 *
 *       BizCity_Cache::set( 'bzcc', 'templates_all', $result );
 *       return $result;
 *   }
 *
 *   public static function insert( array $data ): int {
 *       // ... write to DB ...
 *       BizCity_Cache::flush_group( 'bzcc' ); // invalidate ALL bzcc caches
 *       return $id;
 *   }
 *
 * Cache Key naming convention:
 *   group:  short plugin/module prefix, e.g. 'bzcc', 'bztimg', 'kg', 'twinchat'
 *   key:    descriptive snake_case name, e.g. 'templates_all', 'active_status',
 *           'by_id_42', 'by_category_7_active'
 *   Full cache key stored: "{group}_{key}" inside WP group "{group}".
 *
 * Transient key convention (when using persistent tier):
 *   "bizcity_{group}_{key}" — prefixed to avoid collision with other plugins.
 *   Max transient key length: 172 chars (WP limit is 172 for option name).
 *
 * @see    docs/rules/PHASE-0-RULE-CACHE.md
 * @since  1.3.8
 * @author Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-09 Johnny Chu] R-CACHE — Unified cache helper (new file)
final class BizCity_Cache {

	/**
	 * Default TTL for in-memory object cache (seconds).
	 * On Redis/Memcached hosts this is the expiry. On no-persistent-cache
	 * sites it is irrelevant (data lives only for the request).
	 */
	const TTL_SHORT  = 60;    // 1 minute  — frequently mutating data
	const TTL_MEDIUM = 300;   // 5 minutes — semi-stable lists
	const TTL_LONG   = 3600;  // 1 hour    — rarely mutating reference data

	/**
	 * Default TTL for transient (persistent) tier.
	 */
	const TRANSIENT_TTL = 3600; // 1 hour

	/* ── Read ─────────────────────────────────────────────────────────── */

	/**
	 * Get a value from the object cache.
	 *
	 * @param  string $group  Cache group prefix, e.g. 'bzcc'.
	 * @param  string $key    Cache key within the group.
	 * @return mixed  Cached value or false if not found.
	 */
	public static function get( $group, $key ) {
		$value = wp_cache_get( $key, $group );
		self::trace_cache( 'get', $group, $key, array( 'hit' => false !== $value ) );
		return $value;
	}

	/**
	 * Get a value from transient (persistent cross-request cache).
	 *
	 * Falls back to false if transient not set.
	 *
	 * @param  string $group  Cache group prefix.
	 * @param  string $key    Cache key.
	 * @return mixed  Cached value or false.
	 */
	public static function get_transient( $group, $key ) {
		return get_transient( self::transient_name( $group, $key ) );
	}

	/* ── Write ────────────────────────────────────────────────────────── */

	/**
	 * Store a value in the object cache.
	 *
	 * @param string $group  Cache group.
	 * @param string $key    Cache key.
	 * @param mixed  $value  Value to store.
	 * @param int    $ttl    Time-to-live in seconds. Defaults to TTL_MEDIUM.
	 */
	public static function set( $group, $key, $value, $ttl = self::TTL_MEDIUM ) {
		$result = wp_cache_set( $key, $value, $group, $ttl );
		self::trace_cache( 'set', $group, $key, array(
			'ttl_bucket' => self::ttl_bucket( $ttl ),
			'success'    => (bool) $result,
		) );
	}

	/**
	 * Store a value in the transient (persistent) tier.
	 *
	 * @param string $group  Cache group.
	 * @param string $key    Cache key.
	 * @param mixed  $value  Value to store.
	 * @param int    $ttl    Transient TTL in seconds. Defaults to TRANSIENT_TTL.
	 */
	public static function set_transient( $group, $key, $value, $ttl = self::TRANSIENT_TTL ) {
		set_transient( self::transient_name( $group, $key ), $value, $ttl );
	}

	/* ── Invalidation ─────────────────────────────────────────────────── */

	/**
	 * Delete a single cache entry from object cache.
	 *
	 * @param string $group  Cache group.
	 * @param string $key    Cache key.
	 */
	public static function delete( $group, $key ) {
		$result = wp_cache_delete( $key, $group );
		self::trace_cache( 'delete', $group, $key, array( 'success' => (bool) $result ) );
	}

	/**
	 * Delete a single transient.
	 *
	 * @param string $group  Cache group.
	 * @param string $key    Cache key.
	 */
	public static function delete_transient( $group, $key ) {
		delete_transient( self::transient_name( $group, $key ) );
	}

	/**
	 * Flush ALL cache entries for a group.
	 *
	 * Call this from ALL write operations (insert, update, delete) that affect
	 * the data covered by this group. This is the primary invalidation method.
	 *
	 * Object cache: uses wp_cache_flush_group() if available (WP 6.1+),
	 * falls back to wp_cache_flush() on older installs (full flush — acceptable
	 * since groups are rare and the data is cheap to re-build).
	 *
	 * Transients: iterates self::$group_transient_keys registry to delete
	 * all transients registered for this group. ALWAYS call
	 * ::register_transient_key() from the same file that calls set_transient().
	 *
	 * @param string $group  Cache group to flush.
	 */
	public static function flush_group( $group ) {
		self::trace_cache( 'flush_group', $group, '', array() );
		// Object cache group flush (WP 6.1+ supports group flush natively).
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( $group );
		} elseif ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime(); // WP 6.0 — only runtime (current request) cache
		}
		// else: no-persistent-cache sites — nothing needed, object cache is per-request.

		// Persistent transients — delete all registered keys for this group.
		if ( isset( self::$group_transient_keys[ $group ] ) ) {
			foreach ( self::$group_transient_keys[ $group ] as $key ) {
				delete_transient( self::transient_name( $group, $key ) );
			}
		}

		// Fire action so related caches in other modules can invalidate too.
		// Modules that cache derived data SHOULD listen to this hook.
		do_action( 'bizcity_cache_flushed', $group );
	}

	/* ── Group transient registry ─────────────────────────────────────── */

	/** @var array<string, string[]> group => list of transient keys */
	private static $group_transient_keys = array();

	/**
	 * Emit opt-in semantic cache evidence without logging raw keys or values.
	 *
	 * @param string $operation Cache operation.
	 * @param string $group Cache group.
	 * @param string $key Cache key, hashed before trace emission.
	 * @param array  $data Safe scalar metadata.
	 * @return void
	 */
	private static function trace_cache( $operation, $group, $key, array $data = array() ) {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W5 - cache tracing is
		// forensic opt-in to avoid adding a span to every normal cache hit.
		if ( ! class_exists( 'BizCity_Twin_Trace' ) || ! method_exists( 'BizCity_Twin_Trace', 'runtime_enter' ) ) {
			return;
		}
		if ( ! self::cache_trace_enabled() ) {
			return;
		}
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$span_id = BizCity_Twin_Trace::runtime_enter( 'cache', (string) $operation, array(
			'cache_group'    => (string) $group,
			'key_signature'  => substr( hash( 'sha256', $blog_id . '|' . (string) $group . '|' . (string) $key ), 0, 16 ),
			'blog_id'        => $blog_id,
		) );
		if ( $span_id !== '' ) {
			BizCity_Twin_Trace::runtime_exit( $span_id, 'pass', $data );
		}
	}

	private static function cache_trace_enabled() {
		if ( defined( 'BIZCITY_TWIN_CACHE_TRACE' ) && BIZCITY_TWIN_CACHE_TRACE ) {
			return true;
		}
		if ( ! empty( $_GET['bizcity_qm_probe'] ) && '1' === (string) $_GET['bizcity_qm_probe'] ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return false !== strpos( $uri, '/bizcity-diagnostics/' );
	}

	private static function ttl_bucket( $ttl ) {
		$ttl = (int) $ttl;
		if ( $ttl <= self::TTL_SHORT ) {
			return 'short';
		}
		if ( $ttl <= self::TTL_MEDIUM ) {
			return 'medium';
		}
		return 'long';
	}

	/**
	 * Register a transient key for group-level flush tracking.
	 *
	 * MUST be called at the same file-load time as ::set_transient() for that
	 * key, so ::flush_group() knows which transients to delete.
	 *
	 * Typically called at the top of the class file after class definition:
	 *
	 *   BizCity_Cache::register_transient_key( 'bzcc', 'templates_all' );
	 *   BizCity_Cache::register_transient_key( 'bzcc', 'templates_active' );
	 *
	 * @param string $group Cache group.
	 * @param string $key   Cache key.
	 */
	public static function register_transient_key( $group, $key ) {
		if ( ! isset( self::$group_transient_keys[ $group ] ) ) {
			self::$group_transient_keys[ $group ] = array();
		}
		if ( ! in_array( $key, self::$group_transient_keys[ $group ], true ) ) {
			self::$group_transient_keys[ $group ][] = $key;
		}
	}

	/**
	 * Return all registered groups and keys (for diagnostics/debug).
	 *
	 * @return array<string, string[]>
	 */
	public static function get_registry() {
		return self::$group_transient_keys;
	}

	/* ── Helpers ──────────────────────────────────────────────────────── */

	/**
	 * Build transient option name. Max 172 chars (WP limit).
	 *
	 * @param  string $group
	 * @param  string $key
	 * @return string
	 */
	private static function transient_name( $group, $key ) {
		return 'bizcity_' . $group . '_' . $key;
	}
}
