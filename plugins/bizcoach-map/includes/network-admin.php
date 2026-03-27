<?php
/**
 * BizCoach Map – Network Admin Settings
 *
 * Trang cài đặt trong Network Admin (WordPress Multisite) cho phép
 * quản trị viên mạng thiết lập API key chiêm tinh dùng chung cho
 * toàn bộ các sub-site trong network.
 *
 * Khi site nào đó không có bccm_astro_api_key riêng, hệ thống sẽ
 * tự động dùng bccm_network_astro_api_key từ network option.
 *
 * @package BizCoach_Map
 * @since   0.1.0.36
 */
if (!defined('ABSPATH')) exit;

/* =====================================================================
 * REGISTER NETWORK ADMIN MENU
 * =====================================================================*/
add_action('network_admin_menu', 'bccm_network_admin_menu');

function bccm_network_admin_menu() {
    add_menu_page(
        'BizCoach Map – Network Settings',
        'BizCoach Map',
        'manage_network_options',
        'bccm_network_settings',
        'bccm_network_settings_page',
        'dashicons-star-filled',
        30
    );
}

/* =====================================================================
 * NETWORK SETTINGS PAGE
 * =====================================================================*/
function bccm_network_settings_page() {
    if (!current_user_can('manage_network_options')) {
        wp_die(__('Bạn không có quyền truy cập trang này.'));
    }

    $saved = false;

    /* ── Save ── */
    if (
        isset($_POST['bccm_network_save']) &&
        isset($_POST['_wpnonce']) &&
        wp_verify_nonce($_POST['_wpnonce'], 'bccm_network_save')
    ) {
        $new_key = sanitize_text_field(wp_unslash($_POST['bccm_network_astro_api_key'] ?? ''));
        update_site_option('bccm_network_astro_api_key', $new_key);
        $saved = true;
    }

    $network_key = get_site_option('bccm_network_astro_api_key', '');
    $masked      = bccm_network_mask_key($network_key);

    ?>
    <div class="wrap">
        <h1>🌐 BizCoach Map – Network Settings</h1>
        <p style="color:#555;margin-bottom:20px">
            Cài đặt này áp dụng cho <strong>toàn bộ các site</strong> trong WordPress Network.<br>
            Khi một site không tự cấu hình API key riêng (<em>Cài đặt → Chiêm tinh</em>), hệ thống sẽ dùng key này.
        </p>

        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible"><p>✅ Đã lưu cài đặt Network.</p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('bccm_network_save'); ?>

            <!-- ── Astrology API ── -->
            <h2 style="margin-top:24px">🌟 Free Astrology API Key (Network-wide)</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bccm_network_astro_api_key">API Key</label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="bccm_network_astro_api_key"
                            name="bccm_network_astro_api_key"
                            value="<?php echo esc_attr($network_key); ?>"
                            class="regular-text"
                            autocomplete="new-password"
                            placeholder="<?php echo $network_key ? esc_attr($masked) : 'Nhập API key…'; ?>"
                        />
                        <p class="description">
                            Đăng ký tại
                            <a href="https://freeastrologyapi.com/signup" target="_blank" rel="noopener">freeastrologyapi.com</a>
                            để lấy API key.<br>
                            <?php if ($network_key): ?>
                                <span style="color:#059669">✔ Hiện tại: <code><?php echo esc_html($masked); ?></code></span><br>
                            <?php else: ?>
                                <span style="color:#dc2626">✘ Chưa cấu hình network key.</span><br>
                            <?php endif; ?>
                            <em>Mỗi site vẫn có thể đặt key riêng (ưu tiên cao hơn).</em>
                        </p>
                    </td>
                </tr>
            </table>

            <hr style="margin:24px 0">

            <!-- ── Priority legend ── -->
            <h3>⚙️ Thứ tự ưu tiên khi lấy API key</h3>
            <ol style="margin-left:20px;color:#555;line-height:1.8">
                <li><strong>Site option</strong> <code>bccm_astro_api_key</code> — cài đặt riêng của từng site</li>
                <li><strong>Network option</strong> <code>bccm_network_astro_api_key</code> — key này (dùng chung toàn mạng)</li>
                <li><strong>PHP constant</strong> <code>BCCM_ASTRO_API_KEY</code> — định nghĩa trong <code>wp-config.php</code></li>
            </ol>

            <!-- ── Site overview ── -->
            <?php bccm_network_site_key_overview(); ?>

            <p class="submit">
                <button type="submit" name="bccm_network_save" value="1" class="button button-primary">
                    💾 Lưu Network Settings
                </button>
            </p>
        </form>
    </div>
    <?php
}

/* =====================================================================
 * HELPER: MASK API KEY FOR DISPLAY
 * =====================================================================*/
function bccm_network_mask_key($key) {
    if (empty($key)) return '';
    $len = strlen($key);
    if ($len <= 8) return str_repeat('*', $len);
    return substr($key, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($key, -4);
}

/* =====================================================================
 * HELPER: SHOW PER-SITE KEY STATUS TABLE
 * =====================================================================*/
function bccm_network_site_key_overview() {
    if (!is_multisite()) return;

    $sites = get_sites(['number' => 50, 'orderby' => 'id']);
    if (empty($sites)) return;

    echo '<hr style="margin:24px 0">';
    echo '<h3>🗂️ Trạng thái API key theo từng site</h3>';
    echo '<table class="widefat fixed striped" style="max-width:680px">';
    echo '<thead><tr><th style="width:40px">ID</th><th>Site</th><th style="width:200px">Key đang dùng</th><th style="width:80px">Nguồn</th></tr></thead>';
    echo '<tbody>';

    $network_key = get_site_option('bccm_network_astro_api_key', '');

    foreach ($sites as $site) {
        $site_key = get_blog_option($site->blog_id, 'bccm_astro_api_key', '');
        if ($site_key) {
            $display = bccm_network_mask_key($site_key);
            $source  = '<span style="color:#059669;font-weight:600">Site</span>';
        } elseif ($network_key) {
            $display = bccm_network_mask_key($network_key);
            $source  = '<span style="color:#2563eb;font-weight:600">Network</span>';
        } else {
            $display = '<span style="color:#dc2626">—</span>';
            $source  = '';
        }

        echo '<tr>';
        echo '<td>' . esc_html($site->blog_id) . '</td>';
        echo '<td>' . esc_html($site->domain . $site->path) . '</td>';
        echo '<td><code>' . wp_kses_post($display) . '</code></td>';
        echo '<td>' . wp_kses_post($source) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p class="description" style="margin-top:6px">Hiển thị tối đa 50 sites.</p>';
}
