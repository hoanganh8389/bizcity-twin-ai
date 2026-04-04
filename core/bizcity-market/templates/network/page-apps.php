<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_Market
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */
 if (!defined('ABSPATH')) exit; ?>

<div class="wrap bc-wrap">
  <div class="bc-header">
    <h1 class="bc-title">BizCity Apps</h1>
    <div class="bc-actions">
      <a class="button" href="<?php echo esc_url(network_admin_url('sites.php')); ?>">Sites</a>
    </div>
  </div>

  <div class="bc-card">
    <p class="description">
      Quản lý danh sách ứng dụng (plugin) hiển thị cho khách. Dữ liệu lưu ở
      <code>site_option(<?php echo esc_html(BizCity_Market_Network_Admin::OPT_CATALOG); ?>)</code>.
    </p>

    <form method="post" id="bizcityAppsForm">
      <?php wp_nonce_field('bizcity_apps_save_action'); ?>

      <div class="bc-toolbar" style="display:flex;gap:8px;justify-content:flex-end;margin:10px 0 14px">
        <button type="button" class="button" id="bizcityAddRow">+ Thêm ứng dụng</button>
        <button type="submit" class="button button-primary" name="bizcity_apps_save" value="1">Lưu</button>
      </div>

      <table class="widefat fixed striped" id="bizcityAppsTable">
        <thead>
          <tr>
            <th style="width:44px"></th>
            <th style="width:140px">Key (slug)</th>
            <th style="width:220px">Tên</th>
            <th style="width:160px">Category</th>
            <th style="width:200px">Plugin file</th>
            <th style="width:240px">Link (admin)</th>
            <th style="width:190px">Icon (dashicon)</th>
            <th style="width:110px;text-align:center">Mặc định</th>
            <th style="width:90px;text-align:center">Core</th>
            <th style="width:80px;text-align:center">Xóa</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ((array)$catalog as $i => $app): ?>
          <?php $key = esc_attr($app['key'] ?? ''); $isWebsite = ($key === 'website'); ?>
          <tr class="bizcity-row" data-row="<?php echo (int)$i; ?>">
            <td class="bizcity-drag-handle" title="Kéo để sắp xếp">
              <span class="dashicons dashicons-move"></span>
            </td>

            <td>
              <input type="text" name="apps[<?php echo (int)$i; ?>][key]" value="<?php echo esc_attr($app['key'] ?? ''); ?>" class="regular-text" <?php echo $isWebsite ? 'readonly' : ''; ?> required>
              <div class="description">vd: <code>crm</code>, <code>pos</code></div>
            </td>

            <td>
              <input type="text" name="apps[<?php echo (int)$i; ?>][name]" value="<?php echo esc_attr($app['name'] ?? ''); ?>" class="regular-text" required>
            </td>

            <td>
              <input list="bizcityCategoryList" name="apps[<?php echo (int)$i; ?>][category]" value="<?php echo esc_attr($app['category'] ?? ''); ?>" class="regular-text">
            </td>

            <td>
              <input type="text" name="apps[<?php echo (int)$i; ?>][plugin_file]" value="<?php echo esc_attr($app['plugin_file'] ?? ''); ?>" class="regular-text" placeholder="folder/main.php">
              <div class="description">Để trống nếu là module “core”</div>
            </td>

            <td>
              <input type="text" name="apps[<?php echo (int)$i; ?>][link]" value="<?php echo esc_attr($app['link'] ?? ''); ?>" class="regular-text" placeholder="admin.php?page=... hoặc URL">
              <div class="description">VD: <code>admin.php?page=wc-admin</code></div>
            </td>

            <td>
              <input type="text" name="apps[<?php echo (int)$i; ?>][icon]" value="<?php echo esc_attr($app['icon'] ?? 'dashicons-admin-plugins'); ?>" class="regular-text" placeholder="dashicons-...">
              <div class="bizcity-icon-preview">
                <span class="dashicons <?php echo esc_attr($app['icon'] ?? 'dashicons-admin-plugins'); ?>"></span>
              </div>
            </td>

            <td style="text-align:center">
              <label class="bizcity-toggle">
                <input type="checkbox" name="apps[<?php echo (int)$i; ?>][default_checked]" value="1" <?php checked(!empty($app['default_checked'])); ?> <?php echo $isWebsite ? 'disabled' : ''; ?>>
                <span></span>
              </label>
            </td>

            <td style="text-align:center">
              <label class="bizcity-toggle">
                <input type="checkbox" name="apps[<?php echo (int)$i; ?>][is_core]" value="1" <?php checked(!empty($app['is_core'])); ?> <?php echo $isWebsite ? 'disabled' : ''; ?>>
                <span></span>
              </label>
            </td>

            <td style="text-align:center">
              <?php if ($isWebsite): ?>
                <span class="description">—</span>
              <?php else: ?>
                <button type="button" class="button-link-delete bizcityDelRow">Xóa</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <datalist id="bizcityCategoryList">
        <?php foreach ($category_suggestions as $c): ?>
          <option value="<?php echo esc_attr($c); ?>"></option>
        <?php endforeach; ?>
      </datalist>

    </form>
  </div>
</div>

<style>
  /* dùng đúng style gọn, bo tròn, giống file anh đưa */
  .bc-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;box-shadow:0 8px 24px rgba(0,0,0,.04)}
  #bizcityAppsTable input.regular-text{width:100%}
  .bizcity-icon-preview{margin-top:6px}
  .bizcity-icon-preview .dashicons{font-size:18px;width:18px;height:18px;color:#6b7280}
  .bizcity-toggle{position:relative;display:inline-block;width:44px;height:24px}
  .bizcity-toggle input{display:none}
  .bizcity-toggle span{position:absolute;cursor:pointer;inset:0;background:#e5e7eb;border-radius:999px;transition:.2s}
  .bizcity-toggle span:before{content:"";position:absolute;height:18px;width:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 2px 6px rgba(0,0,0,.15)}
  .bizcity-toggle input:checked + span{background:#7c3aed}
  .bizcity-toggle input:checked + span:before{transform:translateX(20px)}
  .bizcity-drag-handle{cursor:move;text-align:center;vertical-align:middle;color:#6b7280}
  .bizcity-drag-handle .dashicons{font-size:18px;width:18px;height:18px}
  .bizcity-row.ui-sortable-helper{background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.12)}
  tr.bizcity-sort-placeholder td{background:#f3f4f6;border:2px dashed #d1d5db}
</style>

<script>
(function(){
  const table = document.getElementById('bizcityAppsTable');
  const btnAdd = document.getElementById('bizcityAddRow');
  const $ = window.jQuery;
  if(!table || !btnAdd) return;

  if ($ && $.fn && $.fn.sortable) {
    $(table).find('tbody').sortable({
      items: 'tr.bizcity-row',
      handle: '.bizcity-drag-handle',
      axis: 'y',
      placeholder: 'bizcity-sort-placeholder',
      forcePlaceholderSize: true,
      tolerance: 'pointer',
      helper: function(e, tr){
        const $tr = $(tr);
        const $helper = $tr.clone();
        $helper.children().each(function(i){
          $(this).width($tr.children().eq(i).width());
        });
        return $helper;
      },
      update: function(){ renumberRows(); }
    });
  }

  function renumberRows(){
    const rows = Array.from(table.querySelectorAll('tbody tr.bizcity-row'));
    rows.forEach((tr, idx)=>{
      tr.dataset.row = idx;
      tr.querySelectorAll('input,textarea').forEach(el=>{
        const n = el.getAttribute('name');
        if(!n) return;
        el.setAttribute('name', n.replace(/apps\[\d+\]/, 'apps['+idx+']'));
      });
    });
  }

  function createRow(idx){
    const tr = document.createElement('tr');
    tr.className = 'bizcity-row';
    tr.dataset.row = idx;
    tr.innerHTML = `
      <td class="bizcity-drag-handle" title="Kéo để sắp xếp"><span class="dashicons dashicons-move"></span></td>
      <td><input type="text" name="apps[${idx}][key]" class="regular-text" required placeholder="vd: crm">
          <div class="description">vd: <code>crm</code>, <code>pos</code></div></td>
      <td><input type="text" name="apps[${idx}][name]" class="regular-text" required placeholder="Tên hiển thị"></td>
      <td><input list="bizcityCategoryList" name="apps[${idx}][category]" value="Khác" class="regular-text"></td>
      <td><input type="text" name="apps[${idx}][plugin_file]" class="regular-text" placeholder="folder/main.php">
          <div class="description">Để trống nếu là module “core”</div></td>
      <td><input type="text" name="apps[${idx}][link]" class="regular-text" placeholder="admin.php?page=... hoặc URL">
          <div class="description">VD: <code>admin.php?page=wc-admin</code></div></td>
      <td><input type="text" name="apps[${idx}][icon]" value="dashicons-admin-plugins" class="regular-text" placeholder="dashicons-...">
          <div class="bizcity-icon-preview"><span class="dashicons dashicons-admin-plugins"></span></div></td>
      <td style="text-align:center"><label class="bizcity-toggle"><input type="checkbox" name="apps[${idx}][default_checked]" value="1"><span></span></label></td>
      <td style="text-align:center"><label class="bizcity-toggle"><input type="checkbox" name="apps[${idx}][is_core]" value="1"><span></span></label></td>
      <td style="text-align:center"><button type="button" class="button-link-delete bizcityDelRow">Xóa</button></td>
    `;
    return tr;
  }

  btnAdd.addEventListener('click', ()=>{
    const tbody = table.querySelector('tbody');
    const idx = tbody.querySelectorAll('tr.bizcity-row').length;
    tbody.appendChild(createRow(idx));
    renumberRows();
  });

  table.addEventListener('click', (e)=>{
    const del = e.target.closest('.bizcityDelRow');
    if(del){
      const tr = del.closest('tr');
      if(tr) tr.remove();
      renumberRows();
    }
  });

  table.addEventListener('input', (e)=>{
    const iconInput = e.target.closest('input[name*="[icon]"]');
    if(!iconInput) return;
    const tr = iconInput.closest('tr');
    const preview = tr.querySelector('.bizcity-icon-preview .dashicons');
    if(preview){
      preview.className = 'dashicons ' + (iconInput.value || 'dashicons-admin-plugins');
    }
  });
})();
</script>
