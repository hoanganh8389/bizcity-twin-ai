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
 * BizCity_Market_Credit
 *
 * Lớp xử lý các thao tác liên quan đến credit/wallet cho Marketplace:
 * - Mua plugin bằng credit (ghi ledger, items, entitlement, cập nhật ví).
 * - Tất cả các thao tác quan trọng đều nằm trong transaction để đảm bảo ACID.
 *
 * Ghi chú tổng quát:
 * - Hàm trả về mảng ['ok'=>true,'msg'=>...] khi thành công, hoặc WP_Error khi có lỗi.
 * - Không thực hiện echo/print trong lớp này — caller chịu trách nhiệm hiển thị/điều hướng.
 * - Các tham số đầu vào được cast/sanitize cơ bản trước khi sử dụng.
 * - Mọi thao tác DB dùng BizCity_Market_DB::globaldb() (handle DB toàn cục của hệ thống).
 */
class BizCity_Market_Credit {

    /**
     * buy_plugin($user_id, $blog_id, $plugin_slug)
     *
     * Mục đích:
     * - Thực hiện mua 1 plugin bằng credit của user.
     * - Quy trình:
     *   1) Kiểm tra hợp lệ input, kiểm tra plugin tồn tại và có giá credit.
     *   2) Kiểm tra user đã sở hữu plugin chưa (active/paused), nếu đã sở hữu -> trả về ok.
     *   3) Bắt đầu transaction.
     *   4) LOCK row ví (SELECT ... FOR UPDATE) để tránh race condition.
     *   5) Trừ credit, lưu ledger event, ledger item và ghi entitlement.
     *   6) COMMIT transaction; nếu lỗi -> ROLLBACK và trả WP_Error.
     *
     * Trả về:
     * - ['ok'=>true, 'msg'=>string] khi thành công.
     * - WP_Error khi thất bại (các mã lỗi chuẩn: not_logged_in, invalid_blog, invalid_slug,
     *   globaldb_missing, not_found, no_price, insufficient_credit, buy_failed).
     *
     * Lưu ý về đồng bộ & concurrency:
     * - Sử dụng START TRANSACTION + SELECT ... FOR UPDATE trên wallet để khóa balance của user.
     * - Nếu có nhiều request trừ credit song song, chỉ request đầu sẽ thành công nếu balance vừa đủ.
     * - Trong catch Exception phải gọi ROLLBACK để trả DB về trạng thái an toàn.
     *
     * Lưu ý về bảo mật & sanitize:
     * - user_id và blog_id được ép int; plugin_slug được sanitize_key trước khi tham chiếu DB.
     * - Không log trực tiếp dữ liệu nhạy cảm (chỉ lưu meta JSON mô tả event).
     *
     * Tổ chức bảng/handle DB:
     * - Biến $tP, $tE, $tW, $tLE, $tLI tham chiếu tới bảng plugins, entitlements, wallets, ledger events, ledger items.
     * - BizCity_Market_DB::globaldb() trả về object DB có phương thức get_row/get_var/insert/query/prepare.
     */
    public static function buy_plugin($user_id, $blog_id, $plugin_slug) {
        $user_id = (int)$user_id;
        $blog_id = (int)$blog_id;
        $plugin_slug = sanitize_key($plugin_slug);

        // Validate cơ bản đầu vào
        if ($user_id <= 0) return new WP_Error('not_logged_in', __( 'Bạn chưa đăng nhập.', 'bizcity-twin-ai' ));
        if ($blog_id <= 0) return new WP_Error('invalid_blog', __( 'Blog không hợp lệ.', 'bizcity-twin-ai' ));
        if (!$plugin_slug) return new WP_Error('invalid_slug', __( 'Thiếu plugin_slug.', 'bizcity-twin-ai' ));

        // Lấy handle DB toàn cục (cấu hình shard/remote đã được BizCity_Market_DB xử lý)
        $db = BizCity_Market_DB::globaldb();
        if (!$db) return new WP_Error('globaldb_missing', 'Global DB not ready');

        $blog_id = (int)get_current_blog_id(); // blog hiện tại
        $hub_blog_id = BizCity_Market_Woo_Sync::resolve_hub_blog_id($blog_id); // Hub blog của user mua
        $now = current_time('mysql');

        // Table alias/handles (helper functions cung cấp tên bảng đầy đủ)
        $tP  = BizCity_Market_DB::t_plugins();
        $tE  = BizCity_Market_DB::t_ent();
        $tW  = BizCity_Market_DB::t_wallets();
        $tLE = BizCity_Market_DB::t_events();
        $tLI = BizCity_Market_DB::t_items();

        // 1) Kiểm tra plugin có tồn tại & đang active không
        $plugin = $db->get_row($db->prepare("SELECT * FROM {$tP} WHERE plugin_slug=%s AND is_active=1 LIMIT 1", $plugin_slug));
        if (!$plugin) return new WP_Error('not_found', __( 'Plugin không tồn tại hoặc đang tắt.', 'bizcity-twin-ai' ));

        // 2) Kiểm tra có giá credit hợp lệ không
        $cost = (int)$plugin->credit_price;
        if ($cost <= 0) return new WP_Error('no_price', __( 'Plugin này chưa có giá credit.', 'bizcity-twin-ai' ));

        // 3) Kiểm tra đã sở hữu plugin chưa (tránh mua trùng)
        $owned = (int)$db->get_var($db->prepare("
            SELECT id FROM {$tE}
            WHERE blog_id=%d AND product_type='plugin' AND product_slug=%s AND status IN ('active','paused')
            ORDER BY id DESC LIMIT 1
        ", $blog_id, $plugin_slug));
        if ($owned) return ['ok'=>true, 'msg'=> __( 'Bạn đã sở hữu plugin này.', 'bizcity-twin-ai' )];

        // 4) Transaction để đảm bảo atomicity
        $db->query('START TRANSACTION');

        try {
            // Lock wallet row để tránh race condition khi update balance
            $bal = (int)$db->get_var($db->prepare("SELECT balance_credit FROM {$tW} WHERE user_id=%d FOR UPDATE", $user_id));
            if ($bal < $cost) {
                // Không đủ tiền -> rollback và trả lỗi
                $db->query('ROLLBACK');
                return new WP_Error('insufficient_credit', __( 'Không đủ credit.', 'bizcity-twin-ai' ));
            }

            // 5) Cập nhật ví (balance)
            $new_bal = $bal - $cost;
            $ok = $db->query($db->prepare("UPDATE {$tW} SET balance_credit=%d, updated_at=%s WHERE user_id=%d", $new_bal, $now, $user_id));
            if ($ok === false) throw new Exception('Không thể cập nhật ví credit.');

            // 6) Chuẩn bị meta mô tả event (dạng JSON, không chứa sensitive info)
            $meta = wp_json_encode([
                'hub_blog_id' => $hub_blog_id,
                'blog_id' => $blog_id,
                'user_id' => $user_id,
                'plugin_slug' => $plugin_slug,
                'plugin_id' => (int)$plugin->id,
                'credit_cost' => $cost,
                'type' => 'market_buy_plugin_credit'
            ], JSON_UNESCAPED_UNICODE);

            // 7) Ghi ledger event (bản ghi tổng)
            $db->insert($tLE, [
                'user_id'       => $user_id,
                'hub_blog_id'   => $hub_blog_id,
                'blog_id'       => $blog_id,
                'type'          => 'market_buy_plugin_credit',
                'amount_money'  => 0,
                'amount_credit' => -$cost,
                'ref'           => 'plugin:' . $plugin_slug,
                'created_at'    => $now,
                'meta'          => $meta,
            ]);

            $event_id = (int)$db->insert_id;
            if ($event_id <= 0) throw new Exception('Không thể ghi ledger event.');

            // 8) Ghi ledger item chi tiết theo schema ví (item liên quan đến product)
            $db->insert($tLI, [
                'event_id'        => $event_id,
                'user_id'         => $user_id,
                'hub_blog_id'     => $hub_blog_id,
                'item_type'       => 'market_plugin',
                'product_id'      => (int)$plugin->id,
                'blog_id'         => $blog_id,
                'subscription_key'=> null,
                'period_start'    => $now,
                'period_end'      => null,
                'monthly_fee'     => 0,
                'next_charge_at'  => null,
                'status'          => 'active',
                'meta'            => $meta,
                'created_at'      => $now,
            ]);

            // 9) Ghi entitlement (ghi quyền sở hữu plugin cho blog)
            $db->insert($tE, [
                'hub_blog_id'   => $hub_blog_id,
                'blog_id'       => $blog_id,
                'user_id'       => $user_id,
                'product_type'  => 'plugin',
                'product_slug'  => $plugin_slug,
                'mode'          => 'credit',
                'status'        => 'active',
                'credit_cost'   => $cost,
                'period_start'  => $now,
                'period_end'    => null,
                'next_charge_at'=> null,
                'meta'          => $meta,
                'created_at'    => $now,
                'updated_at'    => null,
            ]);

            if ((int)$db->insert_id <= 0) throw new Exception('Không thể ghi entitlement.');
            
            // ✅ 9.5) Rollup lại balance_credit = SUM(events.amount_credit)
            if (class_exists('BizCity_Ledger') && method_exists('BizCity_Ledger', 'rollup_wallet_credit')) {
                back_trace('NOTICE', 'BizCity_Ledger::rollup_wallet_credit not found, using fallback inline.');
                BizCity_Ledger::rollup_wallet_credit($db, $user_id, $now);
            } else {
                back_trace('NOTICE', 'Tự xử BizCity_Ledger::rollup_wallet_credit not found, using fallback inline.');
                // fallback inline (nếu anh chưa đặt helper)
                $sum = (int)$db->get_var($db->prepare("
                    SELECT COALESCE(SUM(amount_credit), 0)
                    FROM {$tLE}
                    WHERE user_id=%d
                ", $user_id));
                back_trace('NOTICE', 'Tự xử BizCity_Ledger::rollup_wallet_credit fallback sum=' . $sum);
                $db->query($db->prepare("
                    UPDATE {$tW}
                    SET balance_credit=%d, updated_at=%s
                    WHERE user_id=%d
                ", $sum, $now, $user_id));
            }

            self::rollup_hub_revenue($hub_blog_id, $cost, $now);
            
            // 10) Commit transaction (tất cả OK)
            $db->query('COMMIT');

            // 11) Clear cache liên quan (đảm bảo dữ liệu kịp thời)
            $cg = 'bizcity_market';

            // Xóa cache những thứ hay dùng lại (tùy anh đang cache gì)
            $keys = [
                'market_wallet_credit_u:' . (int)$user_id,
                'market_ent_plugins_b:' . (int)$blog_id,
                'market_ent_plugin_b:' . (int)$blog_id . '_s:' . $plugin_slug,
                'market_plugin_row_s:' . $plugin_slug,
            ];
            

            // Xóa an toàn
            if (class_exists('BizCity_Market_Cache') && method_exists('BizCity_Market_Cache', 'del')) {
                foreach ($keys as $ck) {
                    BizCity_Market_Cache::del($ck, $cg);
                }
            } else {
                foreach ($keys as $ck) {
                    wp_cache_delete($ck, $cg);
                }
            }


            return ['ok'=>true, 'msg'=> __( 'Mua plugin bằng credit thành công.', 'bizcity-twin-ai' )];

        } catch (Exception $e) {
            // Rollback on error để tránh trạng thái nửa chừng
            $db->query('ROLLBACK');
            // Trả lại WP_Error để caller có thể log/hiện thông báo
            return new WP_Error('buy_failed', $e->getMessage());
        }
        
    }

    /**
     * rollup_hub_revenue($hub_blog_id, $amount_money_vnd, $date)
     *
     * Mục đích:
     * - Ghi nhận doanh thu (revenue) của hub từ việc bán plugin, phục vụ báo cáo.
     * - Quy trình:
     *   1) Phân tích date để lấy ra day, month, year.
     *   2) Thực hiện INSERT vào bảng bizcity_market_hub_rollup với dữ liệu tương ứng.
     *   3) Sử dụng ON DUPLICATE KEY UPDATE để gộp doanh thu nếu đã có bản ghi cho ngày đó.
     *
     * Trả về:
     * - Không có (chỉ ghi nhận dữ liệu vào DB).
     *
     * Lưu ý về bảo mật & sanitize:
     * - hub_blog_id được ép int; amount_money_vnd được ép float.
     * - Không log trực tiếp dữ liệu nhạy cảm.
     */
    public static function rollup_hub_revenue($hub_blog_id, $amount_credit, $date) {
        global $wpdb;
        $table =  BizCity_Market_DB::t_hub_rollups();

        $day = (int) date('d', strtotime($date));
        $month = (int) date('m', strtotime($date));
        $year = (int) date('Y', strtotime($date));

        $wpdb->query($wpdb->prepare("
            INSERT INTO $table (hub_blog_id, type, day, month, year, amount_credit)
            VALUES (%d, 'plugin', %d, %d, %d, %d)
            ON DUPLICATE KEY UPDATE amount_credit = amount_credit + VALUES(amount_credit)
        ", $hub_blog_id, $day, $month, $year, $amount_credit));
    }
}
