<?php if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_network_options')) wp_die('No permission');

$q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
$page = isset($_GET['paged']) ? (int)$_GET['paged'] : 1;
$base_url_early = network_admin_url('admin.php?page=bizcity-market');

/* ── Action: Force Sync ── */
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
  check_admin_referer('bc_market_sync');
  // Xoá transient cũ để force chạy lại ngay
  $sync_ver = '3';
  delete_site_transient('bizcity_agent_plugins_synced_v' . $sync_ver);
  BizCity_Market_Catalog::sync_agent_plugins( true );
  wp_redirect( add_query_arg('synced', '1', $base_url_early) );
  exit;
}
if (isset($_GET['synced'])) {
  echo '<div class="notice notice-success is-dismissible"><p>✅ Đã đồng bộ plugin agent – các bản ghi orphan đã được dọn dẹp.</p></div>';
}

/* ── Action: Delete ── */
if (isset($_GET['action'], $_GET['id']) && $_GET['action']==='delete') {
  check_admin_referer('bc_market_delete');
  BizCity_Market_Catalog::delete((int)$_GET['id']);
  echo '<div class="notice notice-success"><p>Đã xoá.</p></div>';
}

$res = BizCity_Market_Catalog::list(['q'=>$q, 'page'=>$page, 'per'=>20]);
$rows = $res['rows']; $total = $res['total']; $per = $res['per'];
$max_page = max(1, (int)ceil($total/$per));

$base_url = network_admin_url('admin.php?page=bizcity-market');
?>

<div class="wrap bc-wrap">
  <div class="bc-header">
    <h1 class="bc-title">BizCity Market – Danh sách plugin - [bizcity_market_plugins limit="12"]</h1>
    <div style="display:flex; gap:8px;">
      <a class="button" href="<?php echo esc_url(wp_nonce_url($base_url.'&action=sync', 'bc_market_sync')); ?>">🔄 Sync Agent Plugins</a>
      <a class="button button-primary" href="<?php echo esc_url(network_admin_url('admin.php?page=bizcity-market-add')); ?>">+ Thêm plugin</a>
    </div>
  </div>

  <div class="bc-card" style="margin-bottom:12px;">
    <form method="get" style="display:flex; gap:8px; align-items:center;">
      <input type="hidden" name="page" value="bizcity-market">
      <input class="regular-text" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Tìm theo tên / slug / directory...">
      <button class="button">Tìm</button>
      <a class="button" href="<?php echo esc_url($base_url); ?>">Reset</a>
      <div style="margin-left:auto; opacity:.7;">Tổng: <?php echo (int)$total; ?></div>
    </form>
  </div>

  <div class="bc-card" style="padding:0; overflow:hidden;">
    <table class="widefat striped" style="margin:0;">
      <thead>
        <tr>
          <th style="width:86px;">Ảnh</th>
          <th>Plugin</th>
          <th style="width:160px;">Giá</th>
          <th style="width:150px;">Views / Hữu ích</th>
          <th style="width:160px;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <?php if (!empty($r->image_url)): ?>
              <img src="<?php echo esc_url($r->image_url); ?>" style="width:72px;height:46px;object-fit:cover;border-radius:10px;border:1px solid #e5e7eb;">
            <?php else: ?>
              <div style="width:72px;height:46px;border-radius:10px;border:1px dashed #e5e7eb;"></div>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $file_on_disk = (strpos($r->plugin_file, '/') !== false)
                  ? file_exists(WP_PLUGIN_DIR . '/' . $r->plugin_file)
                  : null; // single-file = ko kiểm tra (manual entry)
            ?>
            <div style="font-weight:700;">
              <?php echo esc_html($r->title); ?>
              <?php if ($file_on_disk === false): ?>
                <span style="color:#d63638; font-size:11px;" title="File không tồn tại trên disk">⚠ orphan</span>
              <?php endif; ?>
            </div>
            <div style="opacity:.75; font-size:12px;">
              <code><?php echo esc_html($r->plugin_file); ?></code>
              <?php if ($file_on_disk === true): ?><span style="color:green;">✔</span><?php elseif ($file_on_disk === false): ?><span style="color:#d63638;">✘</span><?php endif; ?>
              · slug: <b><?php echo esc_html($r->plugin_slug); ?></b>
              · dir: <?php echo esc_html($r->directory); ?>
            </div>
            <div style="opacity:.8; font-size:12px; margin-top:4px;">
              Tác giả: <?php echo esc_html($r->author_name ?: '—'); ?>
              <?php if (!empty($r->after_active_url)): ?>
                · Link sau active: <a href="<?php echo esc_url($r->after_active_url); ?>" target="_blank">mở</a>
              <?php endif; ?>
              <?php if (!empty($r->demo_url)): ?>
                · Demo: <a href="<?php echo esc_url($r->demo_url); ?>" target="_blank">xem</a>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <div><b><?php echo (int)$r->credit_price; ?></b> credit</div>
            <div style="opacity:.75;"><?php echo number_format((int)$r->vnd_price); ?> đ</div>
          </td>
          <td>
            <div>👁 <?php echo (int)$r->views; ?></div>
            <div style="opacity:.75;">⭐ <?php echo esc_html($r->useful_score); ?> (<?php echo (int)$r->useful_count; ?>)</div>
          </td>
          <td>
            <a class="button" href="<?php echo esc_url(network_admin_url('admin.php?page=bizcity-market-add&id='.(int)$r->id)); ?>">Sửa</a>
            <a class="button button-link-delete"
               href="<?php echo esc_url(wp_nonce_url($base_url.'&action=delete&id='.(int)$r->id, 'bc_market_delete')); ?>"
               onclick="return confirm('Xoá plugin này?');">Xoá</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="5" style="padding:18px; opacity:.7;">Chưa có plugin nào.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($max_page > 1): ?>
    <div class="bc-card" style="margin-top:12px;">
      <?php
        $args = ['page'=>'bizcity-market'];
        if ($q!=='') $args['q']=$q;
        for ($p=1; $p<=$max_page; $p++){
          $url = add_query_arg(array_merge($args, ['paged'=>$p]), $base_url);
          $cls = ($p===$page) ? 'button button-primary' : 'button';
          echo '<a class="'.$cls.'" style="margin-right:6px;" href="'.esc_url($url).'">'.$p.'</a>';
        }
      ?>
    </div>
  <?php endif; ?>
</div>
