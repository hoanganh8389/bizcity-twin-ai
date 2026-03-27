<?php
if (!defined('ABSPATH')) exit;

class BizCity_Market_Cache {

    /**
     * Normalize cache key:
     * - allow int
     * - allow non-empty string
     * - return null if invalid
     */
    private static function norm_key($key) {
        if (is_int($key)) return $key;
        if (is_string($key)) {
            $k = trim($key);
            return ($k !== '') ? $k : null;
        }
        // allow numeric strings? (optional)
        if (is_numeric($key)) {
            $k = trim((string)$key);
            return ($k !== '') ? $k : null;
        }
        return null;
    }

    private static function norm_group($group) : string {
        $g = is_string($group) ? trim($group) : 'default';
        return $g !== '' ? $g : 'default';
    }

    /**
     * Get cache value.
     * - return null nếu cache miss (để phân biệt với false/0)
     */
    public static function get($key, $group='default') {
        $key = self::norm_key($key);
        if ($key === null) return null;

        $group = self::norm_group($group);

        $v = wp_cache_get($key, $group);
        return ($v === false) ? null : $v;
    }

    /**
     * Set cache value.
     */
    public static function set($key, $value, $group='default', $ttl=60) {
        $key = self::norm_key($key);
        if ($key === null) return false;

        $group = self::norm_group($group);

        return wp_cache_set($key, $group ? $group : 'default', $value, (int)$ttl);
    }

    /**
     * Delete a cache key in group.
     * - MUST NOT pass null/empty key to wp_cache_delete()
     */
    public static function del($key, $group='default') {
        $key = self::norm_key($key);
        if ($key === null) {
            // im lặng bỏ qua để tránh objectcache.error
            return false;
        }

        $group = self::norm_group($group);

        return wp_cache_delete($key, $group);
    }

    /**
     * Flush group (fallback: flush all nếu object cache không support group flush).
     */
    public static function flush_group($group='default') {
        return wp_cache_flush();
    }
}
