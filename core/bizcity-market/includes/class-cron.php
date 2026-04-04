<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_Market
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;

class BizCity_Market_Cron {

    const HOOK = 'bizcity_market_daily_billing';

    public static function boot() {
        add_action(self::HOOK, [__CLASS__, 'run_daily_billing']);

        // schedule (nếu anh muốn tự schedule luôn)
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'daily', self::HOOK);
        }
    }

    public static function run_daily_billing() {
        // placeholder: sau này nối BizCity_Wallet trừ credit theo monthly entitlements
        BizCity_Market_Logger::info('cron_daily_billing_run', ['ts'=>time()]);
    }
}
