<?php
if (!defined('ABSPATH')) exit;

class BizCity_Market_Woo_Sync {

    const META_SLUG = '_bizcity_plugin_slug';
    const META_ID   = '_bizcity_plugin_id';

    public static function boot() {
        if (!class_exists('WooCommerce')) return;

        // UI field trong product
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'product_fields'], 40);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product_fields'], 40);

        // Grant entitlement when order paid/completed
        add_action('woocommerce_payment_complete', [__CLASS__, 'grant_from_order'], 20);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'grant_from_order'], 20);
    }

    public static function product_fields() {
        echo '<div class="options_group">';

        woocommerce_wp_text_input([
            'id'          => self::META_SLUG,
            'label'       => 'BizCity Plugin Slug',
            'description' => 'VD: contact-form-7 (ưu tiên slug).',
            'desc_tip'    => true,
        ]);

        woocommerce_wp_text_input([
            'id'          => self::META_ID,
            'label'       => 'BizCity Plugin ID',
            'description' => 'ID trong bizcity_market_plugins (nếu không dùng slug).',
            'desc_tip'    => true,
            'type'        => 'number',
            'custom_attributes' => ['min' => 0],
        ]);

        echo '</div>';
    }

    public static function save_product_fields($product) {
        if (!$product) return;

        $slug = isset($_POST[self::META_SLUG]) ? sanitize_key(wp_unslash($_POST[self::META_SLUG])) : '';
        $id   = isset($_POST[self::META_ID]) ? (int)($_POST[self::META_ID]) : 0;

        if ($slug) $product->update_meta_data(self::META_SLUG, $slug);
        else $product->delete_meta_data(self::META_SLUG);

        if ($id > 0) $product->update_meta_data(self::META_ID, $id);
        else $product->delete_meta_data(self::META_ID);
    }

    public static function resolve_hub_blog_id($user_id) {
        global $wpdb;

        $current_blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        if ($current_blog <= 0) {
            return 0;
        }

        // Lấy trực tiếp cột is_parent từ wp_blogs cho blog hiện tại
        $is_parent = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT is_parent FROM {$wpdb->blogs} WHERE blog_id = %d LIMIT 1", $current_blog)
        );
        if ($is_parent <= 0) {
            return $current_blog;
        }

        return $is_parent > 0 ? $is_parent : 0;
    }

    public static function grant_from_order($order_id) {
        $order_id = (int)$order_id;
        if ($order_id <= 0) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $user_id = (int)$order->get_user_id();
        if ($user_id <= 0) return;

        $db = BizCity_Market_DB::globaldb();
        if (!$db) return;

        
        $blog_id     =  (int)get_current_blog_id();
        $hub_blog_id =  self::resolve_hub_blog_id($user_id);
        $t_ent = BizCity_Market_DB::t_ent();
        $t_plg = BizCity_Market_DB::t_plugins();
        $now   = current_time('mysql');

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $pid = (int)$product->get_id();

            $plugin_slug = sanitize_key((string)get_post_meta($pid, self::META_SLUG, true));
            $plugin_id   = (int)get_post_meta($pid, self::META_ID, true);

            if (!$plugin_slug && $plugin_id > 0) {
                $plugin_slug = (string)$db->get_var($db->prepare("SELECT plugin_slug FROM {$t_plg} WHERE id=%d LIMIT 1", $plugin_id));
                $plugin_slug = sanitize_key($plugin_slug);
            }

            if (!$plugin_slug) continue;

            // already has?
            $exists = (int)$db->get_var($db->prepare("
                SELECT id FROM {$t_ent}
                WHERE blog_id=%d AND product_type='plugin' AND product_slug=%s AND status IN ('active','paused')
                ORDER BY id DESC LIMIT 1
            ", $blog_id, $plugin_slug));
            if ($exists) continue;

            $meta = wp_json_encode([
                'order_id' => $order_id,
                'wc_product_id' => $pid,
                'hub_blog_id' => $hub_blog_id,
                'blog_id' => $blog_id,
                'user_id' => $user_id,
            ], JSON_UNESCAPED_UNICODE);

            $db->insert($t_ent, [
                'hub_blog_id'   => $hub_blog_id,
                'blog_id'       => $blog_id,
                'user_id'       => $user_id,
                'product_type'  => 'plugin',
                'product_slug'  => $plugin_slug,
                'mode'          => 'checkout',
                'status'        => 'active',
                'credit_cost'   => 0,
                'period_start'  => $now,
                'period_end'    => null,
                'next_charge_at'=> null,
                'meta'          => $meta,
                'created_at'    => $now,
                'updated_at'    => null,
            ]);
        }
    }
}
