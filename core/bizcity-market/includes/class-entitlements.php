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

class BizCity_Market_Entitlements {

    // cache group
    const CG = 'bizcity_market';

    public static function has($blog_id, $product_slug, $product_type = 'plugin') : bool {
        $blog_id = (int)$blog_id;
        $product_slug = sanitize_key($product_slug);
        $product_type = sanitize_key($product_type);

        $ck = "ent:{$blog_id}:{$product_type}:{$product_slug}";
        $cached = BizCity_Market_Cache::get($ck, self::CG);
        if ($cached !== null) return (bool)$cached;

        $db = BizCity_Market_DB::globaldb();
        if (!$db) {
            BizCity_Market_Logger::warn('globaldb_missing', ['blog_id'=>$blog_id]);
            BizCity_Market_Cache::set($ck, 0, self::CG, 60);
            return false;
        }

        // bảng needles (anh đang đưa vào global): bizcity_entitlements (ví dụ)
        $t = BizCity_Market_DB::table('entitlements');

        $row = $db->get_row($db->prepare("
            SELECT id, status, period_end
            FROM {$t}
            WHERE blog_id=%d
              AND product_type=%s
              AND product_slug=%s
              AND status IN ('active','paused')
            ORDER BY id DESC
            LIMIT 1
        ", $blog_id, $product_type, $product_slug));

        $ok = false;
        if ($row) {
            // nếu có period_end thì check expire
            if (!empty($row->period_end)) {
                $ts = strtotime($row->period_end);
                $ok = ($ts === false) ? true : ($ts >= time());
            } else {
                $ok = true;
            }
        }

        BizCity_Market_Cache::set($ck, $ok ? 1 : 0, self::CG, 120);
        return $ok;
    }

    /**
     * Map plugin file => slug (quy ước)
     * - slug mặc định: folder plugin (dirname)
     * - có thể override qua filter
     */
    public static function plugin_slug_from_file($plugin_file) : string {
        $plugin_file = (string)$plugin_file; // ex: woocommerce/woocommerce.php
        $slug = strtolower(trim(dirname($plugin_file), '/'));
        if ($slug === '.' || $slug === '') $slug = strtolower(basename($plugin_file, '.php'));

        return apply_filters('bizcity_market_plugin_slug', $slug, $plugin_file);
    }
}
