<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_network_options')) wp_die('No permission');

global $wpdb;
$table = $wpdb->base_prefix . 'blogs';
$hubs = $wpdb->get_results("SELECT blog_id, domain, path FROM $table WHERE is_parent = 0 ORDER BY blog_id ASC");

// Đọc dữ liệu hoa hồng từ site_option
$commission_data = get_site_option('bizcity_hub_commissions', []);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_commissions'])) {
    check_admin_referer('save_hub_commissions');
    $new_data = [];
    foreach ($hubs as $hub) {
        $key = 'commission_' . $hub->blog_id;
        $percent = isset($_POST[$key]) ? floatval($_POST[$key]) : 0;
        $new_data[$hub->blog_id] = $percent;
    }
    update_site_option('bizcity_hub_commissions', $new_data);
    $commission_data = $new_data;
    echo '<div class="notice notice-success"><p>Đã lưu chính sách hoa hồng.</p></div>';
}
?>
<div class="bc-card" style="background:#fff; border-radius:16px; box-shadow:0 6px 16px rgba(0,0,0,.06); padding:24px; margin-top:24px;">
  <h1 style="font-size:22px; font-weight:800; margin-bottom:18px;">Chính sách hoa hồng các Hub</h1>
  <form method="post">
    <?php wp_nonce_field('save_hub_commissions'); ?>
    <table class="widefat striped" style="border-radius:12px; overflow:hidden; margin:0;">
      <thead>
        <tr>
          <th>Blog ID</th>
          <th>Domain</th>
          <th>Path</th>
          <th>% Hoa hồng</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($hubs as $hub): ?>
          <tr>
            <td><?php echo (int)$hub->blog_id; ?></td>
            <td><?php echo esc_html($hub->domain); ?></td>
            <td><?php echo esc_html($hub->path); ?></td>
            <td>
              <input type="number" name="commission_<?php echo (int)$hub->blog_id; ?>" value="<?php echo isset($commission_data[$hub->blog_id]) ? esc_attr($commission_data[$hub->blog_id]) : 0; ?>" min="0" max="100" step="0.1" style="width:80px; border-radius:8px; padding:4px 8px;">
              %
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button type="submit" name="save_commissions" class="button button-primary" style="margin-top:16px;">Lưu chính sách</button>
  </form>
</div>
