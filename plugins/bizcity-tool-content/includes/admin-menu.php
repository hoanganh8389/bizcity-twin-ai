<?php
/**
 * BizCity Tool Content — WP Admin Dashboard
 *
 * Shows stats + recent articles created by the tool.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
    add_menu_page(
        'Tool Content',
        'Tool Content',
        'edit_posts',
        'bizcity-tool-content',
        'bztc_admin_dashboard',
        'dashicons-edit-page',
        58
    );
}, 20 );

function bztc_admin_dashboard() {
    $user_id = get_current_user_id();

    $query_total = new WP_Query( [
        'post_type'      => BizCity_Content_Post_Type::POST_TYPE,
        'author'         => $user_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );
    $total = $query_total->found_posts;

    $query_done = new WP_Query( [
        'post_type'      => BizCity_Content_Post_Type::POST_TYPE,
        'author'         => $user_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => '_bza_status', 'value' => 'completed' ] ],
    ] );
    $completed = $query_done->found_posts;

    $recent = BizCity_Content_Post_Type::get_history( $user_id, 10 );
    ?>
    <div class="wrap">
        <h1>✍️ BizCity Tool Content</h1>

        <div style="display:flex;gap:16px;margin:20px 0;">
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);flex:1;text-align:center;">
                <div style="font-size:36px;font-weight:700;color:#4f46e5;"><?php echo esc_html( $total ); ?></div>
                <div style="color:#6b7280;">Tổng bài tạo</div>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);flex:1;text-align:center;">
                <div style="font-size:36px;font-weight:700;color:#059669;"><?php echo esc_html( $completed ); ?></div>
                <div style="color:#6b7280;">Thành công</div>
            </div>
        </div>

        <?php if ( $recent ) : ?>
        <h2>Bài viết gần đây</h2>
        <table class="widefat striped" style="margin-top:8px;">
            <thead><tr><th>ID</th><th>Prompt</th><th>Tiêu đề</th><th>Trạng thái</th><th>Ngày</th></tr></thead>
            <tbody>
            <?php foreach ( $recent as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row->id ); ?></td>
                    <td><?php echo esc_html( wp_trim_words( $row->prompt, 10 ) ); ?></td>
                    <td>
                        <?php if ( $row->post_url ) : ?>
                            <a href="<?php echo esc_url( $row->post_url ); ?>" target="_blank"><?php echo esc_html( $row->ai_title ?: '(no title)' ); ?></a>
                        <?php else : ?>
                            <?php echo esc_html( $row->ai_title ?: '—' ); ?>
                        <?php endif; ?>
                    </td>
                    <td><span style="padding:2px 8px;border-radius:8px;font-size:12px;background:<?php echo $row->status === 'completed' ? '#d4edda' : '#f8d7da'; ?>;color:<?php echo $row->status === 'completed' ? '#155724' : '#721c24'; ?>;"><?php echo esc_html( $row->status ); ?></span></td>
                    <td><?php echo esc_html( $row->created_at ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p style="color:#9ca3af;margin-top:20px;">Chưa có bài viết nào. Vào trang <strong>Tool Content</strong> trên frontend để bắt đầu.</p>
        <?php endif; ?>
    </div>
    <?php
}
