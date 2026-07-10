<?php
/**
 * BizCity_User_Meta_Cache
 *
 * In-request static cache cho user meta.
 *
 * Vấn đề gốc rễ: bất kỳ `get_user_meta($uid, $key, true)` nào cũng trigger
 * `update_meta_cache('user', [$uid])` → SQL lấy TẤT CẢ user_meta rows về PHP
 * heap (gồm cả bztimg_templates 5MB+, bzvideo_tc_library...). Class này:
 *
 *   1. Dùng `$wpdb->get_var()` trực tiếp cho các key "heavy" (large blobs) để
 *      tránh meta cache prime.
 *   2. Giữ kết quả trong `static $cache[]` per uid+key → chỉ query 1 lần/request.
 *   3. Expose `invalidate()` sau write để caller tự bust.
 *
 * Usage:
 *   $tpl = BizCity_User_Meta_Cache::get( $uid, 'bztimg_templates', [] );
 *   BizCity_User_Meta_Cache::set( $uid, 'bztimg_templates', $new_val );
 *   BizCity_User_Meta_Cache::invalidate( $uid, 'bztimg_templates' );
 *
 * Heavy-key list (bypass WP meta prime, dùng direct SQL):
 *   bztimg_templates, bzvideo_tc_workflows, bzvideo_tc_library
 *
 * Normal-key list (có thể dùng get_user_meta vì nhỏ, chỉ cache để tránh dup call):
 *   bizcity_twinchat_notebook_id, bizcity_projects (legacy)
 *
 * @see    docs/rules/PHASE-0-RULE-PERF.md R-PERF
 * @since  1.14.0 (2026-06-11)
 * @author Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-11 Johnny Chu] R-PERF — Unified in-request user_meta cache (new file)
final class BizCity_User_Meta_Cache {

    /**
     * Keys stored as large blobs — bypass WP meta prime, use direct SQL.
     * Adding a key here makes get() use $wpdb->get_var() instead of get_user_meta().
     *
     * @var array<string, true>
     */
    private static $heavy_keys = array(
        'bztimg_templates'      => true,
        'bzvideo_tc_workflows'  => true,
        'bzvideo_tc_library'    => true,
        // [2026-06-22 Johnny Chu] R-PERF — astro full chart can be 50-100 KB JSON blob
        'bccm_astro_full_chart' => true,
        'bccm_astro_birth_data' => true,
        // [2026-06-22 Johnny Chu] R-PERF — small strings but ANY get_user_meta() primes ALL user meta
        // (including 5MB blobs). Force direct SQL to prevent WP meta prime on chat requests.
        'first_name'            => true,
        'last_name'             => true,
        'description'           => true,
        'billing_phone'         => true,
        '_bizcity_master_level' => true,
        // [2026-06-22 Johnny Chu] R-PERF — additional keys to prevent meta prime on REST/cron paths
        'phone'                             => true,
        'bizcity_phone'                     => true,
        'bizcity_projects'                  => true,
        'bizcity_app_settings'              => true,
        'bizcity_default_notify_channel'    => true,
        'bizcity_member_plan'               => true,
        'bizcity_member_valid_until'        => true,
        'bizcity_member_source'             => true,
        // [2026-06-22 Johnny Chu] R-PERF — admin dashboard plan badge — triggers 1.90 MB meta prime
        '_bizcity_plan'                     => true,
    );

    /**
     * Per-uid per-key runtime cache.
     * Shape: [ $uid => [ $meta_key => mixed ] ]
     *
     * @var array
     */
    private static $cache = array();

    /* ── Public API ──────────────────────────────────────────────────── */

    /**
     * Get a user meta value, at most one DB hit per uid+key per request.
     *
     * For heavy keys: uses $wpdb->get_var() — avoids WP priming all user meta.
     * For normal keys: uses get_user_meta() — benefits from WP cache if already primed.
     *
     * @param int    $uid       User ID.
     * @param string $meta_key  Meta key.
     * @param mixed  $default   Default value if key not found or not set.
     * @return mixed
     */
    public static function get( $uid, $meta_key, $default = null ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) {
            return $default;
        }

        if ( isset( self::$cache[ $uid ][ $meta_key ] ) ) {
            return self::$cache[ $uid ][ $meta_key ];
        }

        $value = self::fetch( $uid, $meta_key, $default );
        self::$cache[ $uid ][ $meta_key ] = $value;

        return $value;
    }

    /**
     * Persist a user meta value and update the in-request cache.
     *
     * @param int    $uid      User ID.
     * @param string $meta_key Meta key.
     * @param mixed  $value    Value to store (must be serializable).
     * @return bool  True on success.
     */
    public static function set( $uid, $meta_key, $value ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) {
            return false;
        }

        $result = update_user_meta( $uid, $meta_key, $value );

        // Update in-request cache regardless of DB result (value is in memory now)
        if ( ! isset( self::$cache[ $uid ] ) ) {
            self::$cache[ $uid ] = array();
        }
        self::$cache[ $uid ][ $meta_key ] = $value;

        return (bool) $result;
    }

    /**
     * Remove a key from the in-request cache without touching DB.
     * Call after an external update_user_meta() to force re-read.
     *
     * @param int         $uid      User ID (0 = invalidate for all users).
     * @param string|null $meta_key Specific key, or null to clear all keys for $uid.
     */
    public static function invalidate( $uid = 0, $meta_key = null ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) {
            // Nuke entire cache
            self::$cache = array();
            return;
        }
        if ( $meta_key === null ) {
            unset( self::$cache[ $uid ] );
        } else {
            unset( self::$cache[ $uid ][ $meta_key ] );
        }
    }

    /* ── Internal helpers ────────────────────────────────────────────── */

    /**
     * Fetch from DB — heavy keys via direct SQL, others via get_user_meta().
     *
     * @param int    $uid
     * @param string $meta_key
     * @param mixed  $default
     * @return mixed
     */
    private static function fetch( $uid, $meta_key, $default ) {
        if ( isset( self::$heavy_keys[ $meta_key ] ) ) {
            return self::fetch_direct_sql( $uid, $meta_key, $default );
        }
        $raw = get_user_meta( $uid, $meta_key, true );
        return ( $raw === '' || $raw === false ) ? $default : $raw;
    }

    /**
     * Fetch a single meta row via $wpdb->get_var() — no WP meta cache prime.
     *
     * @param int    $uid
     * @param string $meta_key
     * @param mixed  $default
     * @return mixed
     */
    private static function fetch_direct_sql( $uid, $meta_key, $default ) {
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
            $uid,
            $meta_key
        ) );

        if ( $raw === null ) {
            return $default;
        }

        $unserialized = maybe_unserialize( $raw );
        // Caller-side: if they want an array and got null/empty, return default
        if ( $unserialized === null || $unserialized === false ) {
            return $default;
        }

        return $unserialized;
    }
}
