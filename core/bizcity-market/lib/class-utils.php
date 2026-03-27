<?php
if (!defined('ABSPATH')) exit;

class BizCity_Market_Utils {

    public static function set_admin_flash($key, $data) {
        if (!function_exists('get_current_user_id')) return;
        $uid = (int)get_current_user_id();
        if (!$uid) return;
        set_transient("bcflash_{$uid}_{$key}", $data, 60);
    }

    public static function get_admin_flash($key) {
        if (!function_exists('get_current_user_id')) return null;
        $uid = (int)get_current_user_id();
        if (!$uid) return null;
        $tkey = "bcflash_{$uid}_{$key}";
        $data = get_transient($tkey);
        if ($data !== false) delete_transient($tkey);
        return ($data === false) ? null : $data;
    }
}
