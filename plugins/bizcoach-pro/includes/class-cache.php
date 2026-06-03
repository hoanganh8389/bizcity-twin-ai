<?php
/**
 * BizCoach Pro — Cache helper (WP Object Cache wrapper).
 *
 * Implements the strategy in CACHE-STRATEGY.md:
 *   - Single `remember( $group, $key, $ttl, $producer )` read API.
 *   - Sentinel-based null caching to prevent stampede on "not found" reads.
 *   - Single `bcpro/cache/invalidate` action that downstream code can fire
 *     without knowing the cache key shape.
 *   - Ring-buffer log of last 10 invalidations (transient) for F.15 diag.
 *
 * @package BizCoach_Pro
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'BizCoach_Pro_Cache' ) ) { return; }

class BizCoach_Pro_Cache {

	/** Sentinel value stored in cache to represent a producer that returned null/false. */
	const NULL_SENTINEL = '__BCPRO_NULL__';

	/** Transient key for the diagnostic ring buffer (last 10 invalidations). */
	const LOG_TRANSIENT  = 'bcpro_cache_invalidation_log';
	const LOG_KEEP       = 10;

	/** Map: entity name → list of (group, key-pattern-callback) pairs. */
	private static $invalidators = array();

	/** True after `init()` has run, so we don't double-register hooks. */
	private static $booted = false;

	/**
	 * Wire the single global `bcpro/cache/invalidate` listener.
	 * Idempotent — safe to call multiple times.
	 */
	public static function init() {
		if ( self::$booted ) { return; }
		self::$booted = true;

		self::register_default_invalidators();

		add_action( 'bcpro/cache/invalidate', array( __CLASS__, 'on_invalidate' ), 10, 2 );
	}

	/**
	 * Read-through cache helper.
	 *
	 * @param string   $group     Cache group (e.g. 'bcpro_coachees').
	 * @param string   $key       Cache key inside the group.
	 * @param int      $ttl       TTL in seconds.
	 * @param callable $producer  Closure that returns fresh value on miss.
	 * @return mixed              Producer return value (cached on subsequent calls).
	 */
	public static function remember( $group, $key, $ttl, $producer ) {
		$found = false;
		$hit   = wp_cache_get( $key, $group, false, $found );
		if ( $found ) {
			return ( $hit === self::NULL_SENTINEL ) ? null : $hit;
		}
		$value = call_user_func( $producer );
		$store = ( $value === null || $value === false ) ? self::NULL_SENTINEL : $value;
		wp_cache_set( $key, $store, $group, (int) $ttl );
		return $value;
	}

	/**
	 * Manually delete one cache entry (escape hatch for callers that know the
	 * key shape and don't want to fire the global invalidate action).
	 */
	public static function forget( $group, $key ) {
		return wp_cache_delete( $key, $group );
	}

	/**
	 * Listener for the `bcpro/cache/invalidate` action.
	 *
	 * @param string $entity  One of: coachee, gens, astro, plan, template.
	 * @param array  $context Free-form context (typically [ 'id' => int, 'user_id' => int ]).
	 */
	public static function on_invalidate( $entity, $context = array() ) {
		$entity  = is_string( $entity ) ? $entity : '';
		$context = is_array( $context ) ? $context : array();
		if ( $entity === '' || ! isset( self::$invalidators[ $entity ] ) ) {
			return;
		}
		foreach ( self::$invalidators[ $entity ] as $rule ) {
			list( $group, $key_cb ) = $rule;
			$keys = call_user_func( $key_cb, $context );
			if ( ! is_array( $keys ) ) { $keys = array( $keys ); }
			foreach ( $keys as $k ) {
				if ( is_string( $k ) && $k !== '' ) {
					wp_cache_delete( $k, $group );
				}
			}
		}
		self::log_invalidation( $entity, $context );
	}

	/**
	 * Default invalidator rules. Mirrors §4 of CACHE-STRATEGY.md.
	 *
	 * Each rule: array( 'group_name', function( $ctx ) { return [keys...]; } )
	 */
	private static function register_default_invalidators() {
		self::$invalidators = array(
			'coachee' => array(
				array( 'bcpro_coachees', function ( $ctx ) {
					$id = isset( $ctx['id'] ) ? (int) $ctx['id'] : 0;
					return $id ? array( 'id:' . $id ) : array();
				} ),
				// User-scoped index — flush all variants for that user. We
				// can't enumerate sub-keys in WP object cache, so we bump a
				// per-user version stamp instead. Readers should compose keys
				// as "user:{uid}:v:{ver}:type:{t}" and consult get_user_version().
				array( 'bcpro_coachee_idx', function ( $ctx ) {
					$uid = isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : 0;
					if ( ! $uid ) { return array(); }
					self::bump_user_version( $uid );
					return array(); // version bump itself flushes logical keys
				} ),
			),
			'gens' => array(
				array( 'bcpro_gens', function ( $ctx ) {
					$id = isset( $ctx['id'] ) ? (int) $ctx['id'] : 0;
					return $id ? array( 'coachee:' . $id ) : array();
				} ),
			),
			'astro' => array(
				array( 'bcpro_astro', function ( $ctx ) {
					$id   = isset( $ctx['id'] ) ? (int) $ctx['id'] : 0;
					$type = isset( $ctx['extra']['chart_type'] ) ? sanitize_key( $ctx['extra']['chart_type'] ) : '';
					if ( ! $id ) { return array(); }
					return $type
						? array( 'coachee:' . $id . ':type:' . $type )
						: array( 'coachee:' . $id . ':type:western', 'coachee:' . $id . ':type:vedic' );
				} ),
			),
			'plan' => array(
				array( 'bcpro_plans', function ( $ctx ) {
					$id = isset( $ctx['id'] ) ? (int) $ctx['id'] : 0;
					return $id ? array( 'coachee:' . $id . ':public_url' ) : array();
				} ),
			),
			'template' => array(
				array( 'bcpro_templates', function ( $ctx ) {
					$slug = isset( $ctx['extra']['slug'] ) ? sanitize_key( $ctx['extra']['slug'] ) : '';
					$keys = array( 'all:active' );
					if ( $slug ) { $keys[] = 'slug:' . $slug; }
					return $keys;
				} ),
			),
		);
	}

	/* ----------------------- User version stamp ------------------------- */

	/**
	 * Get the current "version" for a user-scoped index group. Readers compose
	 * cache keys as `user:{uid}:v:{version}:...`. Bumping the version makes
	 * all old keys orphan (they expire by TTL), achieving wildcard delete.
	 */
	public static function get_user_version( $user_id, $group = 'bcpro_coachee_idx' ) {
		$user_id = (int) $user_id;
		$ver     = wp_cache_get( 'ver:user:' . $user_id, $group );
		if ( ! is_numeric( $ver ) ) {
			$ver = 1;
			wp_cache_set( 'ver:user:' . $user_id, $ver, $group, 3600 );
		}
		return (int) $ver;
	}

	private static function bump_user_version( $user_id, $group = 'bcpro_coachee_idx' ) {
		$cur = self::get_user_version( $user_id, $group );
		wp_cache_set( 'ver:user:' . $user_id, $cur + 1, $group, 3600 );
	}

	/* ----------------------- Diagnostic log ----------------------------- */

	private static function log_invalidation( $entity, $context ) {
		$log = get_transient( self::LOG_TRANSIENT );
		if ( ! is_array( $log ) ) { $log = array(); }
		$log[] = array(
			't'      => time(),
			'entity' => $entity,
			'ctx'    => $context,
		);
		if ( count( $log ) > self::LOG_KEEP ) {
			$log = array_slice( $log, -self::LOG_KEEP );
		}
		set_transient( self::LOG_TRANSIENT, $log, HOUR_IN_SECONDS );
	}

	public static function get_log() {
		$log = get_transient( self::LOG_TRANSIENT );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Flush ALL bcpro cache groups. Used by admin "flush" button or upgrade.
	 */
	public static function flush_all() {
		$groups = array(
			'bcpro_templates', 'bcpro_coachees', 'bcpro_coachee_idx',
			'bcpro_gens', 'bcpro_astro', 'bcpro_plans',
		);
		// WP core has no per-group flush in fallback cache; bump a global
		// version stamp instead. Backends (Redis/Memcached) usually expose
		// wp_cache_flush_group(); use it when available.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			foreach ( $groups as $g ) { wp_cache_flush_group( $g ); }
			return true;
		}
		// Fallback: bump a sentinel that readers don't consult — so this is
		// a no-op without a persistent backend. Acceptable: per-key TTL
		// guarantees worst-case staleness.
		return false;
	}
}

BizCoach_Pro_Cache::init();
