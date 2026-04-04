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
if (!current_user_can('manage_network_options')) wp_die('No permission');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = $id ? BizCity_Market_Catalog::get($id) : null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_admin_referer('bc_market_save');

  $payload = $_POST;
  $payload['id'] = $id;

  $res = BizCity_Market_Catalog::upsert($payload);
  if (is_wp_error($res)) {
    echo '<div class="notice notice-error"><p>'.esc_html($res->get_error_message()).'</p></div>';
  } else {
    $id = (int)$res;
    $row = BizCity_Market_Catalog::get($id);
    echo '<div class="notice notice-success"><p>Đã lưu.</p></div>';
  }
}

$val = function($k, $default='') use ($row){
  return $row && isset($row->$k) ? $row->$k : $default;
};

?>
<div class="wrap bc-wrap">
  <div class="bc-header">
    <h1 class="bc-title"><?php echo $id ? 'Sửa plugin' : 'Thêm plugin'; ?></h1>
    <div>
      <a class="button" href="<?php echo esc_url(network_admin_url('admin.php?page=bizcity-market')); ?>">← Danh sách</a>
    </div>
  </div>

  <form method="post" class="bc-card" style="max-width:980px;">
    <?php wp_nonce_field('bc_market_save'); ?>

    <table class="form-table">
      <tr>
        <th>Tiêu đề</th>
        <td><input class="regular-text" name="title" value="<?php echo esc_attr($val('title')); ?>" required></td>
      </tr>

      <tr>
        <th>plugin_slug</th>
        <td><input class="regular-text" name="plugin_slug" value="<?php echo esc_attr($val('plugin_slug')); ?>" placeholder="vd: woocommerce" required></td>
      </tr>

      <tr>
        <th>plugin_file</th>
        <td><input class="regular-text" name="plugin_file" value="<?php echo esc_attr($val('plugin_file')); ?>" placeholder="vd: woocommerce/woocommerce.php" required></td>
      </tr>

      <tr>
        <th>Directory</th>
        <td><input class="regular-text" name="directory" value="<?php echo esc_attr($val('directory')); ?>" placeholder="vd: woocommerce"></td>
      </tr>

      <tr>
        <th>Ảnh</th>
        <td>
          <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <input class="regular-text" id="bc_image_url" name="image_url"
                  value="<?php echo esc_attr($val('image_url')); ?>" placeholder="https://..." style="min-width:360px;">
            <button type="button" class="button" id="bc_pick_image">Chọn ảnh</button>
            <button type="button" class="button" id="bc_clear_image">Xoá</button>
          </div>
          <div style="margin-top:10px;">
            <img id="bc_image_preview"
                src="<?php echo esc_url($val('image_url')); ?>"
                style="width:240px; height:150px; object-fit:cover; border-radius:14px; border:1px solid #e5e7eb; <?php echo $val('image_url') ? '' : 'display:none;'; ?>">
          </div>
        </td>
      </tr>


      <tr>
        <th>Tác giả</th>
        <td style="display:flex; gap:8px; flex-wrap:wrap;">
          <input class="regular-text" name="author_name" value="<?php echo esc_attr($val('author_name')); ?>" placeholder="Tên tác giả">
          <input class="regular-text" name="author_url" value="<?php echo esc_attr($val('author_url')); ?>" placeholder="Link tác giả">
        </td>
      </tr>

      <tr>
        <th>Giá</th>
        <td style="display:flex; gap:8px; flex-wrap:wrap;">
          <input type="number" min="0" name="credit_price" value="<?php echo esc_attr((int)$val('credit_price',0)); ?>" placeholder="Credit">
          <input type="number" min="0" name="vnd_price" value="<?php echo esc_attr((int)$val('vnd_price',0)); ?>" placeholder="VND">
        </td>
      </tr>

      <tr>
        <th>Link</th>
        <td style="display:flex; gap:8px; flex-wrap:wrap;">
          <input class="regular-text" name="demo_url" value="<?php echo esc_attr($val('demo_url')); ?>" placeholder="Link demo">
          <input class="regular-text" name="download_url" value="<?php echo esc_attr($val('download_url')); ?>" placeholder="Link tải nếu không dùng hệ thống">
          <input class="regular-text" name="after_active_url" value="<?php echo esc_attr($val('after_active_url')); ?>" placeholder="Link sau khi active (settings page)">
        </td>
      </tr>
      <tr>
        <th>Quickview</th>
        <td>
          <textarea name="quickview" rows="6" class="large-text" placeholder="Mô tả ngắn / bullet / quick pitch..."><?php
            echo esc_textarea($val('quickview'));
          ?></textarea>
        </td>
      </tr>

      <tr>
        <th>Description</th>
        <td>
          <?php
          wp_editor(
            (string)$val('description'),
            'bc_market_desc',
            [
              'textarea_name' => 'description',
              'textarea_rows' => 10,
              'media_buttons' => true,
              'teeny'         => false,
              'quicktags'     => true,
            ]
          );
          ?>
        </td>
      </tr>

      <tr>
        <th>Chỉ số (có thể fake)</th>
        <td style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <label>Views
            <input type="number" min="0" name="views" value="<?php echo esc_attr((int)$val('views',0)); ?>" style="width:140px;">
          </label>
          <label>Useful score (0..5)
            <input type="number" step="0.01" min="0" max="5" name="useful_score" value="<?php echo esc_attr((float)$val('useful_score',0)); ?>" style="width:160px;">
          </label>
          <label>Useful count
            <input type="number" min="0" name="useful_count" value="<?php echo esc_attr((int)$val('useful_count',0)); ?>" style="width:160px;">
          </label>
          <div style="opacity:.7; font-size:12px;">(Sau này hệ thống sẽ auto cộng theo lượt xem / vote thật.)</div>
        </td>
      </tr>
    
      <tr>
        <th>Hiển thị</th>
        <td style="display:flex; gap:14px; align-items:center;">
          <label><input type="checkbox" name="is_active" value="1" <?php checked((int)$val('is_active',1),1); ?>> Active</label>
          <label><input type="checkbox" name="is_featured" value="1" <?php checked((int)$val('is_featured',0),1); ?>> Featured</label>
          <label>Sort: <input type="number" name="sort_order" value="<?php echo esc_attr((int)$val('sort_order',0)); ?>" style="width:90px;"></label>
        </td>
      </tr>
    </table>

    <p style="margin-top:16px;">
      <button class="button button-primary">Lưu</button>
    </p>
  </form>
</div>
