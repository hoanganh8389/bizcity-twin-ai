<?php
/**
 * BizCity Tool WooCommerce — Admin Menu (WP Dashboard Page)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'BizCity Woo',
        'BizCity Woo',
        'manage_options',
        'bizcity-tool-woo',
        'bztw_render_admin_page',
        'dashicons-cart',
        59
    );
} );

function bztw_render_admin_page() {
    $query_total = new WP_Query( [
        'post_type'      => BizCity_Woo_Post_Type::POST_TYPE,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );
    $total = $query_total->found_posts;

    $query_done = new WP_Query( [
        'post_type'      => BizCity_Woo_Post_Type::POST_TYPE,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => '_bza_status', 'value' => 'completed' ] ],
    ] );
    $done = $query_done->found_posts;

    $recent = BizCity_Woo_Post_Type::get_history( 0, 10 );
    ?>
    <div class="wrap">
        <h1>BizCity Tool WooCommerce</h1>
        <div style="display:flex;gap:16px;margin:16px 0;">
            <div style="background:#fff;padding:20px 28px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);"><strong style="font-size:28px;color:#059669;"><?php echo $done; ?></strong><br>Sản phẩm đã tạo</div>
            <div style="background:#fff;padding:20px 28px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);"><strong style="font-size:28px;color:#6366f1;"><?php echo $total; ?></strong><br>Tổng prompt</div>
        </div>
        <?php if ( $recent ) : ?>
        <table class="widefat fixed striped" style="max-width:900px;">
            <thead><tr><th>ID</th><th>Prompt</th><th>Sản phẩm</th><th>Giá</th><th>Trạng thái</th><th>Ngày</th></tr></thead>
            <tbody>
            <?php foreach ( $recent as $r ) : ?>
                <tr>
                    <td><?php echo esc_html( $r->id ); ?></td>
                    <td><?php echo esc_html( mb_strimwidth( $r->prompt, 0, 60, '…' ) ); ?></td>
                    <td>
                        <?php if ( $r->product_url ) : ?>
                            <a href="<?php echo esc_url( $r->product_url ); ?>" target="_blank"><?php echo esc_html( $r->ai_title ); ?></a>
                        <?php else : ?>
                            <?php echo esc_html( $r->ai_title ); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $r->product_id ); ?></td>
                    <td><?php echo esc_html( $r->status ); ?></td>
                    <td><?php echo esc_html( $r->created_at ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p style="color:#888;">Chưa có prompt nào.</p>
        <?php endif; ?>
    </div>
    <?php
}
