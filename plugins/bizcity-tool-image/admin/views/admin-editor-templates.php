<?php
/**
 * Admin View — Editor Templates Management (CRUD)
 *
 * List, edit, delete, upload thumbnail for canva-editor design templates.
 * REST namespace: image-editor/v1/editor-templates
 * Table: bztimg_editor_templates
 *
 * @package BizCity_Tool_Image
 * @since   3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table = $wpdb->prefix . 'bztimg_editor_templates';
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

// Pagination
$per_page   = 20;
$current    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset     = ( $current - 1 ) * $per_page;
$total_pages = ceil( $total / $per_page );

// Search
$search = sanitize_text_field( $_GET['s'] ?? '' );
$where  = '1=1';
$params = array();
if ( $search ) {
    $where   .= ' AND description LIKE %s';
    $params[] = '%' . $wpdb->esc_like( $search ) . '%';
}
$params[] = $per_page;
$params[] = $offset;

$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$table} WHERE {$where} ORDER BY sort_order ASC, id DESC LIMIT %d OFFSET %d",
    $params
) );

$editor_page_url = admin_url( 'admin.php?page=bztimg-editor' );
$import_page_url = admin_url( 'admin.php?page=bztimg-editor-assets' );
?>
<div class="wrap">
    <h1 class="wp-heading-inline">🎨 Editor Templates</h1>
    <a href="<?php echo esc_url( $import_page_url ); ?>" class="page-title-action">📦 Import từ JSON</a>
    <hr class="wp-header-end">

    <p>Quản lý design templates cho canva-editor. Table: <code>bztimg_editor_templates</code> — Tổng: <strong><?php echo esc_html( $total ); ?></strong> templates</p>

    <!-- Search -->
    <form method="get" style="margin-bottom:16px;">
        <input type="hidden" name="page" value="bztimg-editor-templates">
        <p class="search-box">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Tìm theo mô tả...">
            <input type="submit" class="button" value="Tìm kiếm">
            <?php if ( $search ): ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bztimg-editor-templates' ) ); ?>" class="button">Xóa bộ lọc</a>
            <?php endif; ?>
        </p>
    </form>

    <?php if ( empty( $rows ) ): ?>
        <div class="notice notice-info"><p>Chưa có template nào. <a href="<?php echo esc_url( $import_page_url ); ?>">Import từ JSON →</a></p></div>
    <?php else: ?>

    <!-- Template Grid -->
    <div id="tpl-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-top:12px;">
        <?php foreach ( $rows as $row ):
            $thumb = '';
            if ( ! empty( $row->attachment_id ) ) {
                $thumb = wp_get_attachment_image_url( (int) $row->attachment_id, 'medium' );
            }
            if ( ! $thumb && ! empty( $row->img_url ) ) {
                $thumb = $row->img_url;
            }
            $desc = $row->description ?: '(không có mô tả)';
            $dims = $row->width . '×' . $row->height;
        ?>
        <div class="tpl-card" data-id="<?php echo (int) $row->id; ?>" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;position:relative;">
            <!-- Thumbnail -->
            <div class="tpl-thumb" style="height:180px;background:#f0f0f1;display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative;" onclick="bztimgEditThumb(<?php echo (int) $row->id; ?>)">
                <?php if ( $thumb ): ?>
                    <img src="<?php echo esc_url( $thumb ); ?>" style="max-width:100%;max-height:100%;object-fit:contain;" alt="">
                <?php else: ?>
                    <span style="color:#999;font-size:48px;">🎨</span>
                <?php endif; ?>
                <div style="position:absolute;bottom:4px;right:4px;background:rgba(0,0,0,.6);color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">
                    <?php echo esc_html( $dims ); ?> · <?php echo (int) $row->pages; ?>p
                </div>
                <div class="tpl-thumb-overlay" style="position:absolute;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;color:#fff;font-size:14px;">
                    📷 Đổi thumbnail
                </div>
            </div>

            <!-- Info -->
            <div style="padding:12px;">
                <div class="tpl-desc" style="font-weight:500;margin-bottom:8px;word-break:break-word;min-height:20px;">
                    <?php echo esc_html( $desc ); ?>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="button button-small" onclick="bztimgEditTpl(<?php echo (int) $row->id; ?>)" title="Chỉnh sửa">✏️ Sửa</button>
                    <button class="button button-small" onclick="bztimgPreviewTpl(<?php echo (int) $row->id; ?>)" title="Xem JSON data">👁️ Xem</button>
                    <a href="<?php echo esc_url( $editor_page_url . '&template_id=' . $row->id ); ?>" class="button button-small button-primary" title="Mở trong editor" target="_blank">🖌️ Editor</a>
                    <button class="button button-small" onclick="bztimgDeleteTpl(<?php echo (int) $row->id; ?>)" title="Xóa" style="color:#d63638;">🗑️</button>
                </div>
                <div style="margin-top:6px;color:#999;font-size:11px;">
                    ID: <?php echo (int) $row->id; ?> · <?php echo esc_html( $row->created_at ); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ( $total_pages > 1 ): ?>
    <div class="tablenav bottom" style="margin-top:16px;">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html( $total ); ?> mục</span>
            <?php
            $page_links = paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'current'   => $current,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            echo $page_links;
            ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="tpl-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;width:90%;max-width:700px;max-height:90vh;overflow:auto;padding:24px;position:relative;">
        <button onclick="bztimgCloseModal()" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:24px;cursor:pointer;">×</button>
        <h2 id="tpl-modal-title">Chỉnh sửa Template</h2>
        <input type="hidden" id="tpl-edit-id">

        <table class="form-table">
            <tr>
                <th>Mô tả</th>
                <td><input type="text" id="tpl-edit-desc" class="regular-text" style="width:100%;"></td>
            </tr>
            <tr>
                <th>Kích thước</th>
                <td>
                    <input type="number" id="tpl-edit-width" style="width:80px;" min="1"> ×
                    <input type="number" id="tpl-edit-height" style="width:80px;" min="1"> px
                    · Pages: <input type="number" id="tpl-edit-pages" style="width:60px;" min="1">
                </td>
            </tr>
            <tr>
                <th>Thumbnail</th>
                <td>
                    <div id="tpl-edit-thumb-preview" style="margin-bottom:8px;"></div>
                    <button class="button" onclick="bztimgPickThumb()">📷 Chọn từ Media Library</button>
                    <input type="hidden" id="tpl-edit-attachment-id" value="0">
                    <input type="text" id="tpl-edit-img-url" class="regular-text" style="width:100%;margin-top:4px;" placeholder="hoặc nhập URL thumbnail">
                </td>
            </tr>
            <tr>
                <th>Sort Order</th>
                <td><input type="number" id="tpl-edit-sort" style="width:80px;" min="0"></td>
            </tr>
        </table>

        <p>
            <button class="button button-primary button-large" onclick="bztimgSaveTpl()">💾 Lưu</button>
            <button class="button button-large" onclick="bztimgCloseModal()">Hủy</button>
            <span id="tpl-edit-status" style="margin-left:12px;"></span>
        </p>
    </div>
</div>

<!-- Preview Modal -->
<div id="tpl-preview-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;width:90%;max-width:900px;max-height:90vh;overflow:auto;padding:24px;position:relative;">
        <button onclick="document.getElementById('tpl-preview-modal').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:24px;cursor:pointer;">×</button>
        <h2>Template Data (JSON)</h2>
        <pre id="tpl-preview-json" style="background:#f0f0f1;padding:16px;border-radius:8px;overflow:auto;max-height:70vh;font-size:12px;line-height:1.4;"></pre>
    </div>
</div>

<style>
.tpl-card:hover .tpl-thumb-overlay { display:flex !important; }
.tpl-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.12); }
</style>

<script>
(function(){
    const restUrl = <?php echo wp_json_encode( rest_url( 'image-editor/v1/' ) ); ?>;
    const nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };

    /* ── Edit Template ── */
    window.bztimgEditTpl = function(id) {
        document.getElementById('tpl-edit-status').textContent = '⏳ Đang tải...';
        document.getElementById('tpl-modal').style.display = 'flex';
        document.getElementById('tpl-modal-title').textContent = 'Chỉnh sửa Template #' + id;

        fetch(restUrl + 'editor-templates/' + id, { headers: { 'X-WP-Nonce': nonce } })
            .then(r => r.json())
            .then(tpl => {
                if (tpl.code) { alert('Lỗi: ' + tpl.message); return; }
                document.getElementById('tpl-edit-id').value = tpl.id;
                document.getElementById('tpl-edit-desc').value = tpl.description || '';
                document.getElementById('tpl-edit-width').value = tpl.width;
                document.getElementById('tpl-edit-height').value = tpl.height;
                document.getElementById('tpl-edit-pages').value = tpl.pages;
                document.getElementById('tpl-edit-sort').value = tpl.sort_order;
                document.getElementById('tpl-edit-attachment-id').value = tpl.attachment_id || 0;
                document.getElementById('tpl-edit-img-url').value = tpl.img_url || '';

                const preview = document.getElementById('tpl-edit-thumb-preview');
                if (tpl.img_url) {
                    preview.innerHTML = '<img src="' + tpl.img_url + '" style="max-width:200px;max-height:120px;border-radius:4px;border:1px solid #ddd;">';
                } else {
                    preview.innerHTML = '<span style="color:#999;">Chưa có thumbnail</span>';
                }
                document.getElementById('tpl-edit-status').textContent = '';
            })
            .catch(err => {
                document.getElementById('tpl-edit-status').innerHTML = '<span style="color:red;">❌ ' + err.message + '</span>';
            });
    };

    /* ── Save Template ── */
    window.bztimgSaveTpl = function() {
        const id = document.getElementById('tpl-edit-id').value;
        const status = document.getElementById('tpl-edit-status');
        status.textContent = '⏳ Đang lưu...';

        const body = {
            description:   document.getElementById('tpl-edit-desc').value,
            width:         parseInt(document.getElementById('tpl-edit-width').value) || 256,
            height:        parseInt(document.getElementById('tpl-edit-height').value) || 256,
            pages:         parseInt(document.getElementById('tpl-edit-pages').value) || 1,
            sort_order:    parseInt(document.getElementById('tpl-edit-sort').value) || 0,
            attachment_id: parseInt(document.getElementById('tpl-edit-attachment-id').value) || 0,
            img_url:       document.getElementById('tpl-edit-img-url').value,
        };

        fetch(restUrl + 'editor-templates/' + id, {
            method: 'PUT',
            headers: headers,
            body: JSON.stringify(body)
        })
        .then(r => r.json())
        .then(result => {
            if (result.code) {
                status.innerHTML = '<span style="color:red;">❌ ' + result.message + '</span>';
                return;
            }
            status.innerHTML = '<span style="color:green;">✅ Đã lưu!</span>';
            // Update the card in the grid
            const card = document.querySelector('.tpl-card[data-id="' + id + '"]');
            if (card) {
                const descEl = card.querySelector('.tpl-desc');
                if (descEl) descEl.textContent = body.description || '(không có mô tả)';
                if (result.img_url) {
                    const thumbEl = card.querySelector('.tpl-thumb img');
                    if (thumbEl) {
                        thumbEl.src = result.img_url;
                    } else {
                        const thumbDiv = card.querySelector('.tpl-thumb');
                        const span = thumbDiv.querySelector('span');
                        if (span) span.remove();
                        const img = document.createElement('img');
                        img.src = result.img_url;
                        img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;';
                        thumbDiv.insertBefore(img, thumbDiv.firstChild);
                    }
                }
            }
            setTimeout(function(){ bztimgCloseModal(); }, 800);
        })
        .catch(err => {
            status.innerHTML = '<span style="color:red;">❌ ' + err.message + '</span>';
        });
    };

    /* ── Delete Template ── */
    window.bztimgDeleteTpl = function(id) {
        if (!confirm('Xóa template #' + id + '? Thao tác không thể hoàn tác.')) return;

        fetch(restUrl + 'editor-templates/' + id, {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                const card = document.querySelector('.tpl-card[data-id="' + id + '"]');
                if (card) card.remove();
            } else {
                alert('Lỗi: ' + (result.message || 'Unknown'));
            }
        })
        .catch(err => alert('Lỗi: ' + err.message));
    };

    /* ── Preview JSON ── */
    window.bztimgPreviewTpl = function(id) {
        document.getElementById('tpl-preview-modal').style.display = 'flex';
        document.getElementById('tpl-preview-json').textContent = '⏳ Đang tải...';

        fetch(restUrl + 'editor-templates/' + id, { headers: { 'X-WP-Nonce': nonce } })
            .then(r => r.json())
            .then(tpl => {
                if (tpl.code) {
                    document.getElementById('tpl-preview-json').textContent = 'Lỗi: ' + tpl.message;
                    return;
                }
                try {
                    const data = JSON.parse(tpl.data_json);
                    document.getElementById('tpl-preview-json').textContent = JSON.stringify(data, null, 2);
                } catch(e) {
                    document.getElementById('tpl-preview-json').textContent = tpl.data_json;
                }
            })
            .catch(err => {
                document.getElementById('tpl-preview-json').textContent = 'Lỗi: ' + err.message;
            });
    };

    /* ── Thumbnail via WP Media Library ── */
    window.bztimgPickThumb = function() {
        if (typeof wp === 'undefined' || !wp.media) {
            alert('WP Media Library không khả dụng.');
            return;
        }
        const frame = wp.media({
            title: 'Chọn thumbnail cho template',
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function() {
            const att = frame.state().get('selection').first().toJSON();
            document.getElementById('tpl-edit-attachment-id').value = att.id;
            document.getElementById('tpl-edit-img-url').value = att.url;
            document.getElementById('tpl-edit-thumb-preview').innerHTML =
                '<img src="' + (att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url) + '" style="max-width:200px;max-height:120px;border-radius:4px;border:1px solid #ddd;">';
        });
        frame.open();
    };

    /* ── Change thumbnail from grid card ── */
    window.bztimgEditThumb = function(id) {
        if (typeof wp === 'undefined' || !wp.media) {
            bztimgEditTpl(id);
            return;
        }
        const frame = wp.media({
            title: 'Chọn thumbnail cho template #' + id,
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function() {
            const att = frame.state().get('selection').first().toJSON();
            fetch(restUrl + 'editor-templates/' + id, {
                method: 'PUT',
                headers: headers,
                body: JSON.stringify({
                    attachment_id: att.id,
                    img_url: att.url
                })
            })
            .then(r => r.json())
            .then(result => {
                if (result.code) { alert('Lỗi: ' + result.message); return; }
                const card = document.querySelector('.tpl-card[data-id="' + id + '"]');
                if (card) {
                    const thumbDiv = card.querySelector('.tpl-thumb');
                    let img = thumbDiv.querySelector('img');
                    const url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                    if (img) {
                        img.src = url;
                    } else {
                        const span = thumbDiv.querySelector('span');
                        if (span) span.remove();
                        img = document.createElement('img');
                        img.src = url;
                        img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;';
                        thumbDiv.insertBefore(img, thumbDiv.firstChild);
                    }
                }
            })
            .catch(err => alert('Lỗi: ' + err.message));
        });
        frame.open();
    };

    /* ── Modal helpers ── */
    window.bztimgCloseModal = function() {
        document.getElementById('tpl-modal').style.display = 'none';
    };

    // Close modals on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.getElementById('tpl-modal').style.display = 'none';
            document.getElementById('tpl-preview-modal').style.display = 'none';
        }
    });
})();
</script>
