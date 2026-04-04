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

/**
 * BizCity_Market_Hooks
 *
 * Market hooks for plugin visibility and agent discovery.
 *
 * REFACTORED (Sprint 0F): Removed credit-gated activation entirely.
 * All plugins are freely activatable — Credit/Price in plugin headers
 * now represent per-use cost, not activation cost.
 * The marketplace UI (Site Apps picker, /chat/ discover) still handles
 * discoverability and guided activation.
 */
class BizCity_Market_Hooks {

    public static function boot() {
        // Plugin visibility filter — show all catalog plugins (agent discovery)
        add_filter('all_plugins', [__CLASS__, 'filter_all_plugins'], 50);
    }

    // Lấy blog ID hiện tại (cho multisite)
    public static function current_blog_id() : int {
        return function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1;
    }

    /**
     * Filter plugin list — NO LONGER hides plugins based on entitlements.
     * All plugins are visible and activatable.
     * This filter is kept for future use (e.g., sorting, categorizing).
     */
    public static function filter_all_plugins($plugins) {
        if (!is_admin()) return $plugins;
        return $plugins;
    }
}
