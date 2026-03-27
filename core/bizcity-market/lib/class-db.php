<?php
if (!defined('ABSPATH')) exit;

class BizCity_Market_DB {

    /** @return wpdb|null */
    public static function globaldb() {
        if (!empty($GLOBALS['globaldb']) && $GLOBALS['globaldb'] instanceof wpdb) return $GLOBALS['globaldb'];
        if (!empty($GLOBALS['wpdb']) && isset($GLOBALS['wpdb']->gwpdb) && $GLOBALS['wpdb']->gwpdb instanceof wpdb) return $GLOBALS['wpdb']->gwpdb;
        // Fallback: single-DB sites without multi-shard — use standard wpdb
        if (!empty($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) return $GLOBALS['wpdb'];
        return null;
    }

    public static function table($name) {
        // ✅ Anh đã đưa vào global_tables dạng: bizcity_market_plugins / bizcity_entitlements...
        // => Default prefix nên là 'bizcity_' để table('market_plugins') => bizcity_market_plugins
        $prefix = defined('BIZCITY_GLOBAL_TABLE_PREFIX') ? BIZCITY_GLOBAL_TABLE_PREFIX : 'wp_bizcity_';
        return $prefix . $name;
    }

    public static function t_plugins() { return self::table('market_plugins'); }
    public static function t_votes()   { return self::table('market_plugin_votes'); }
    public static function t_ent()     { return self::table('entitlements'); }
    public static function t_hub_rollups()     { return self::table('market_hub_rollups'); }
    public static function t_plugins_meta() { return self::table('global_plugins_meta'); }

    // Wallet tables (GLOBAL)
    public static function t_wallets() { return self::table('wallets'); }          // bizcity_wallets
    public static function t_events()  { return self::table('ledger_events'); }    // bizcity_ledger_events
    public static function t_items()   { return self::table('ledger_items'); }     // bizcity_ledger_items
}

/* if (!defined('ABSPATH')) exit;

class BizCity_Market_DB {

    public static function globaldb() {
        // Ưu tiên handle globaldb mà anh đang dùng trong hệ sharding/router
        if (!empty($GLOBALS['globaldb']) && $GLOBALS['globaldb'] instanceof wpdb) {
            return $GLOBALS['globaldb'];
        }
        if (!empty($GLOBALS['wpdb']) && isset($GLOBALS['wpdb']->gwpdb) && $GLOBALS['wpdb']->gwpdb instanceof wpdb) {
            return $GLOBALS['wpdb']->gwpdb;
        }
        // fallback: nếu không có thì trả null để tránh chạy sai DB
        return null;
    }

    public static function table($name) {
        // Nếu needles của anh có prefix riêng thì chỉnh tại đây (vd: bc_, bizcity_, ...)
        // Mặc định: wp_bizcity_*
        $prefix = defined('BIZCITY_GLOBAL_TABLE_PREFIX') ? BIZCITY_GLOBAL_TABLE_PREFIX : 'wp_bizcity_';
        return $prefix . $name;
    }
    public static function t_plugins() { return self::table('market_plugins'); }
    public static function t_votes()   { return self::table('market_plugin_votes'); }

}
    */
