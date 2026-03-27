<?php
if (!defined('ABSPATH')) exit;

class BizCity_Market_Logger {
    public static function info($event, $ctx=[]) { self::log('INFO', $event, $ctx); }
    public static function warn($event, $ctx=[]) { self::log('WARN', $event, $ctx); }
    public static function error($event, $ctx=[]) { self::log('ERROR', $event, $ctx); }

    protected static function log($level, $event, $ctx) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            backtrace('NOTICE','[BizCityMarket]['.$level.'] '.$event.' '.wp_json_encode($ctx, JSON_UNESCAPED_UNICODE));
        }
    }
}
