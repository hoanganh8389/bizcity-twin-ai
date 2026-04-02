<?php

// Menu được đăng ký qua class BizCity_AdminHook_AdminMenu (NO namespace)
// File này giữ lại các callback function legacy để tránh vỡ các trang admin đang gọi.

function twf_telegram_bot_guide_page() {
    ?>
    <div class="wrap">
        <h1>Hướng dẫn tạo Bot Telegram và thiết lập kết nối</h1>
        <ol style="font-size:16px;max-width:820px;">
            <li><b>Mở Telegram</b> và tìm BotFather (hoặc nhấn <a href="https://telegram.me/BotFather" target="_blank">tại đây</a>).</li>
            <li>Nhắn <code>/newbot</code> và làm theo hướng dẫn để đặt tên, username cho bot.</li>
            <li>Sau khi tạo xong, BotFather sẽ cung cấp cho bạn một <b>Access Token</b>. Sao chép token này.</li>
            <li>Truy cập trang <a href="<?php echo admin_url('admin.php?page=ai-telegram-api-settings'); ?>">Cài đặt API Telegram</a> và dán Access Token vào ô "Telegram Bot Token". Lưu lại.</li>
            <li>Gửi một tin nhắn bất kỳ cho bot bạn vừa tạo trên Telegram để khởi động kết nối.</li>
            <li>Truy cập link <code>/telegram-login/</code> trên website để đăng nhập và kết nối tài khoản quản trị với bot.</li>
        </ol>
        <p style="background: #f9f9f9; padding:10px; border:1px solid #ddd;">
            <b>Lưu ý:</b><br>
            - Nếu bạn muốn đăng nhập bằng Telegram với quyền quản trị, hãy chắc chắn quyền tài khoản bạn map trong admin.<br>
            - Sau khi liên kết, hãy kiểm tra lại mọi tính năng gửi/nhận tin nhắn giữa website và bot.
        </p>
    </div>
    <?php
}

function twf_telegram_usage_guide_page() {
    ?>
    <div class="wrap">
        <h1>Hướng dẫn sử dụng Telegram Bot đầy đủ</h1>
        <ul style="font-size:16px;">
            <li><b>Đăng sản phẩm:</b> <pre>Đăng sản phẩm: [Tên] | [Giá] | [Giá KM] | [Mô tả] | [Tên danh mục]</pre></li>
            <li><b>Sửa sản phẩm:</b> <pre>Sửa sản phẩm: [Tên/SKU] | [Thông tin muốn sửa]</pre></li>
            <li><b>Tạo đơn hàng:</b> <pre>Tạo đơn hàng: [Tên khách] | [SĐT] | [Sản phẩm] | ...</pre></li>
            <li><b>Nhập kho:</b> <pre>Tạo phiếu nhập kho [ID hoặc tên sản phẩm] số lượng [số] giá mua [giá] ghi chú [ghi chú]</pre></li>
            <li><b>Báo cáo xuất nhập tồn:</b> <pre>Báo cáo xuất nhập tồn/nhật ký xuất nhập tuần này/hôm nay</pre></li>
            <li><b>Báo cáo doanh số, khách hàng:</b> <pre>Thống kê khách hàng 7 ngày gần nhất</pre></li>
            <li><b>Viết bài:</b> <pre>Viết bài về [chủ đề]</pre></li>
            <li><b>Tạo video:</b> <pre>Tạo video [chủ đề]</pre></li>
            <li><b>Hỏi AI:</b> <pre>Bất kỳ câu hỏi tự nhiên nào</pre></li>
            <li><b>Hướng dẫn sử dụng:</b> <pre>hướng dẫn</pre> hoặc <pre>/help</pre> để xem hướng dẫn này qua Telegram</li>
        </ul>
        <p style="background: #f6f6ff; padding:8px; border-left: 3px solid #0073aa; max-width: 690px;">
            <b>Lưu ý:</b> Một số lệnh (ví dụ: xuất nhập tồn, báo cáo) có thể yêu cầu bot hỏi tiếp về số ngày hoặc khoảng thời gian tùy biến.
        </p>
    </div>
    <?php
}

function twf_video_settings_page() {
    echo '<div class="wrap"><h1>Cấu hình video</h1></div>';
	$fields = [
        'openai_api_key'     => 'OpenAI API Key',
        'elevenlabs_api_key' => 'ElevenLabs API Key',
        'creatomate_api_key' => 'Creatomate API Key',
        'creatomate_template_id' => 'Creatomate Template ID'
    ];
    if ($_SERVER['REQUEST_METHOD']=='POST' && check_admin_referer('ais_opt')) {
        foreach($fields as $key=>$label)
            update_option('ais_'.$key, sanitize_text_field($_POST[$key]??''));
        echo '<div class="updated notice"><p>Đã Lưu.</p></div>';
    }
    echo '<div class="wrap"><h2>AI Story Automation Setting</h2><form method="post">';
    wp_nonce_field('ais_opt');
    echo '<table class="form-table">';
    foreach($fields as $key=>$label)
        echo '<tr><th>'.$label.'</th><td><input type="text" style="width:400px" name="'.$key.'" value="'.esc_attr(get_option('ais_'.$key,'')).'"></td></tr>';
    echo '</table><input type="submit" class="button button-primary" value="Lưu" /></form></div>';
}
function twf_job_schedule_page() {
    if (!current_user_can('publish_posts')) {
        wp_die('Bạn không có quyền truy cập chức năng này.');
    }

    global $wpdb;

    // Xử lý xóa bài
    if (isset($_GET['delete_post']) && is_numeric($_GET['delete_post'])) {
        $delete_id = intval($_GET['delete_post']);
        wp_delete_post($delete_id, true);
        echo '<div class="updated notice"><p>Đã xóa bài viết có ID: '.$delete_id.'</p></div>';
    }

    // Xử lý sửa (gợi ý: bạn có thể cho chuyển về trang sửa bài gốc của WP)
    if (isset($_GET['edit_post']) && is_numeric($_GET['edit_post'])) {
        $edit_id = intval($_GET['edit_post']);
        // Chuyển đến trang sửa bài gốc WP
        wp_redirect(admin_url("post.php?post=$edit_id&action=edit"));
        exit;
    }

    // Tìm kiếm
    $search = isset($_GET['twf_s']) ? sanitize_text_field($_GET['twf_s']) : '';
    $where = array(
        'post_type'      => 'post',
        'post_status'    => 'future',
        'posts_per_page' => 100,
        'orderby'        => 'post_date',
        'order'          => 'ASC'
    );
    if ($search) {
        $where['s'] = $search;
    }

    $future_posts = get_posts($where);

    ?>
    <div class="wrap">
        <h1>Bài viết đã lên lịch (future)</h1>

        <form method="get" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="ai-job-schedule">
            <input type="text" name="twf_s" value="<?php echo esc_attr($search) ?>" placeholder="Tìm kiếm tiêu đề...">
            <button class="button">Tìm kiếm</button>
        </form>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tiêu đề</th>
                    <th>Thời gian đăng</th>
                    <th>Người lên lịch</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($future_posts): foreach ($future_posts as $post): ?>
                <tr>
                    <td><?php echo $post->ID; ?></td>
                    <td><?php echo esc_html($post->post_title); ?></td>
                    <td><?php echo date_i18n('d/m/Y H:i', strtotime($post->post_date)); ?></td>
                    <td>
                        <?php
                        $u = get_userdata($post->post_author);
                        echo $u ? esc_html($u->display_name) : '-';
                        ?>
                    </td>
                    <td>
                        <a href="<?php echo admin_url("admin.php?page=ai-job-schedule&edit_post={$post->ID}"); ?>" class="button">Sửa</a>
                        <a href="<?php echo admin_url("admin.php?page=ai-job-schedule&delete_post={$post->ID}"); ?>" class="button" onclick="return confirm('Xóa bài?');">Xóa</a>
                        <a href="<?php echo get_permalink($post); ?>" class="button" target="_blank">Xem</a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="5">Không có bài nào đang lên lịch</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}


//Widget hướng dẫn kết nối Telegram Bot
/*
add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'twf_telegram_setup_widget',
        'Hướng dẫn tạo 1 Bot riêng trên Telegram',
        'twf_telegram_setup_widget_content'
    );
});*/

function twf_telegram_setup_widget_content() {
    ?>
    <ol>
        <li>Truy cập <a href="https://telegram.me/BotFather" target="_blank">BotFather trên Telegram</a> để tạo mới một Bot.</li>
        <li>Sao chép <strong>access token</strong> Telegram Bot và truy cập 
            <a href="<?php echo admin_url('admin.php?page=admin-ai-telegram'); ?>">Cài đặt AI Telegram</a> để lưu.</li>
        <li>Nhắn tin (bất kỳ) cho Bot bạn đã tạo trên Telegram app và truy cập đường link theo hướng dẫn, hoặc vào mục <A href="<?php echo admin_url('admin.php?page=admin-ai-telegram');?>"><strong>Admin AI</strong></A> của website để đăng nhập, liên kết tài khoản.</li>
        <li>Sau khi kết nối thành công với tài khoản đăng nhập của bạn thông qua Chatbot Admin AI, bạn cũng có thể soạn: <code>HDSD</code> hoặc <code>hướng dẫn</code> trong Telegram bot để nhận hướng dẫn sử dụng các lệnh.</li>
    </ol>
    <?php
}


