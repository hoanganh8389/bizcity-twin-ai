<?php
/**
 * Admin View — Editor Assets Marketplace
 *
 * Hub URL config, JSON import, WP Media Library picker for thumbnails.
 * REST namespace: image-editor/v1
 *
 * @package BizCity_Tool_Image
 * @since   3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Handle POST actions directly (avoid admin-post.php multisite issues) ── */
if ( isset( $_POST['bztimg_action'] ) && current_user_can( 'manage_options' ) ) {
    $action = sanitize_text_field( $_POST['bztimg_action'] );

    // Reseed shapes (upsert)
    if ( $action === 'reseed_shapes' && check_admin_referer( 'bztimg_reseed_shapes' ) ) {
        @set_time_limit( 600 );
        @ini_set( 'memory_limit', '512M' );

        if ( function_exists( 'bztimg_reseed_editor_shapes' ) ) {
            $reseed_result = bztimg_reseed_editor_shapes( true );
            if ( ! empty( $reseed_result['error'] ) ) {
                $reseed_error = $reseed_result['error'];
            } else {
                $reseed_done = true;
            }
        } else {
            $reseed_error = 'Reseed function not available.';
        }
    }

    // Seed assets
    if ( $action === 'seed_assets' && check_admin_referer( 'bztimg_seed_assets' ) ) {
        @set_time_limit( 600 );
        @ini_set( 'memory_limit', '512M' );

        if ( function_exists( 'bztimg_seed_all_editor_assets' ) ) {
            $seed_result = bztimg_seed_all_editor_assets();
            if ( ! empty( $seed_result['error'] ) ) {
                $seed_error = $seed_result['error'];
            } else {
                $seed_done  = true;
                $seed_total = array_sum( $seed_result );
            }
        } else {
            $seed_error = 'Seed function not available.';
        }
    }

    // Rewrite localhost URLs
    if ( $action === 'rewrite_urls' && check_admin_referer( 'bztimg_rewrite_urls' ) ) {
        @set_time_limit( 300 );
        global $wpdb;
        $rewrite_count = 0;

        $base_url         = BZTIMG_URL . 'design-editor-build/mock-api/public/';
        $escaped_base_url = str_replace( '/', '\/', $base_url ); // JSON-escaped version

        // Rewrite templates
        $tpl_table = $wpdb->prefix . 'bztimg_editor_templates';
        $tpl_rows = $wpdb->get_results( "SELECT id, data_json FROM {$tpl_table} WHERE data_json LIKE '%localhost:4000%'" );
        foreach ( $tpl_rows as $row ) {
            $new_data = $row->data_json;
            // Replace JSON-escaped URLs first: http:\/\/localhost:4000\/
            $new_data = str_replace( 'http:\/\/localhost:4000\/', $escaped_base_url, $new_data );
            // Replace unescaped URLs too (if any)
            $new_data = str_replace( 'http://localhost:4000/', $base_url, $new_data );
            if ( $new_data !== $row->data_json ) {
                $wpdb->update( $tpl_table, array(
                    'data_json'    => $new_data,
                    'content_hash' => md5( $new_data ),
                ), array( 'id' => $row->id ), array( '%s', '%s' ), array( '%d' ) );
                $rewrite_count++;
            }
        }

        // Rewrite text presets
        $txt_table = $wpdb->prefix . 'bztimg_editor_text_presets';
        $txt_rows = $wpdb->get_results( "SELECT id, data_json FROM {$txt_table} WHERE data_json LIKE '%localhost:4000%'" );
        foreach ( $txt_rows as $row ) {
            $new_data = $row->data_json;
            $new_data = str_replace( 'http:\/\/localhost:4000\/', $escaped_base_url, $new_data );
            $new_data = str_replace( 'http://localhost:4000/', $base_url, $new_data );
            if ( $new_data !== $row->data_json ) {
                $wpdb->update( $txt_table, array(
                    'data_json'    => $new_data,
                    'content_hash' => md5( $new_data ),
                ), array( 'id' => $row->id ), array( '%s', '%s' ), array( '%d' ) );
                $rewrite_count++;
            }
        }

        $rewrite_done = true;
    }
}

$hub_url = get_option( 'bztimg_editor_hub_url', '' );
?>
<div class="wrap">
    <h1>📦 Editor Assets — Marketplace</h1>
    <p>Quản lý tài nguyên Design Editor: shapes, frames, fonts, text presets, templates. Namespace: <code>image-editor/v1</code></p>

    <div id="bztimg-import-app" style="max-width:960px;">

        <!-- Hub URL Config -->
        <div class="card" style="padding:20px;margin-top:12px;border-left:4px solid #2271b1;">
            <h2>🌐 Hub URL (Marketplace)</h2>
            <p>Khi DB local trống, editor sẽ tự động proxy request đến Hub (ví dụ: <code>https://bizcity.vn</code>) để lấy shapes, frames, fonts, templates chung.</p>
            <table class="form-table">
                <tr>
                    <th>Hub URL</th>
                    <td>
                        <input type="url" id="hub-url" value="<?php echo esc_attr( $hub_url ); ?>" style="width:100%;max-width:500px;" class="regular-text" placeholder="https://bizcity.vn">
                        <p class="description">Để trống = chỉ dùng dữ liệu local. Nhập URL hub để proxy khi local trống.</p>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td>
                        <button class="button button-primary" onclick="bztimgSaveHub()">💾 Lưu Hub URL</button>
                        <button class="button" onclick="bztimgTestHub()">🔗 Test kết nối</button>
                        <span id="hub-status" style="margin-left:12px;"></span>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Asset Cards -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:20px;">

            <!-- Shapes -->
            <div class="card" style="padding:20px;">
                <h2>🔷 Shapes</h2>
                <p>Import shapes với clipPath SVG, thumbnail.<br>
                <strong>API:</strong> <code>/wp-json/image-editor/v1/shapes</code></p>
                <p id="shapes-count" style="color:#666;"></p>
                <div style="margin:12px 0;">
                    <input type="file" id="file-shapes" accept=".json" style="margin-bottom:8px;display:block;">
                    <button class="button button-primary" onclick="bztimgImport('shapes')">Import JSON</button>
                    <button class="button" onclick="bztimgUploadSingle('shapes')">📸 Upload Media</button>
                    <button class="button" onclick="bztimgClear('shapes')" style="color:#d63638;">Xóa tất cả</button>
                </div>
                <div id="result-shapes"></div>
            </div>

            <!-- Frames -->
            <div class="card" style="padding:20px;">
                <h2>🖼️ Frames</h2>
                <p>Import frames với clipPath SVG, thumbnail.<br>
                <strong>API:</strong> <code>/wp-json/image-editor/v1/frames</code></p>
                <p id="frames-count" style="color:#666;"></p>
                <div style="margin:12px 0;">
                    <input type="file" id="file-frames" accept=".json" style="margin-bottom:8px;display:block;">
                    <button class="button button-primary" onclick="bztimgImport('frames')">Import JSON</button>
                    <button class="button" onclick="bztimgUploadSingle('frames')">📸 Upload Media</button>
                    <button class="button" onclick="bztimgClear('frames')" style="color:#d63638;">Xóa tất cả</button>
                </div>
                <div id="result-frames"></div>
            </div>

            <!-- Fonts -->
            <div class="card" style="padding:20px;">
                <h2>🔤 Fonts</h2>
                <p>Import Google Fonts list (family + styles).<br>
                <strong>API:</strong> <code>/wp-json/image-editor/v1/fonts</code></p>
                <p id="fonts-count" style="color:#666;"></p>
                <div style="margin:12px 0;">
                    <input type="file" id="file-fonts" accept=".json" style="margin-bottom:8px;display:block;">
                    <button class="button button-primary" onclick="bztimgImport('fonts')">Import JSON</button>
                    <button class="button" onclick="bztimgClear('fonts')" style="color:#d63638;">Xóa tất cả</button>
                </div>
                <div id="result-fonts"></div>
            </div>

            <!-- Text Presets -->
            <div class="card" style="padding:20px;">
                <h2>📝 Text Presets</h2>
                <p>Import text presets (layer tree + thumbnail).<br>
                <strong>API:</strong> <code>/wp-json/image-editor/v1/text-presets</code></p>
                <p id="texts-count" style="color:#666;"></p>
                <div style="margin:12px 0;">
                    <input type="file" id="file-texts" accept=".json" style="margin-bottom:8px;display:block;">
                    <button class="button button-primary" onclick="bztimgImport('texts')">Import JSON</button>
                    <button class="button" onclick="bztimgUploadSingle('texts')">📸 Upload Media</button>
                    <button class="button" onclick="bztimgClear('texts')" style="color:#d63638;">Xóa tất cả</button>
                </div>
                <div id="result-texts"></div>
            </div>
        </div>

        <!-- Templates Card (full width) -->
        <div style="max-width:960px;margin-top:16px;">
            <div class="card" style="padding:20px;border-left:4px solid #8c6bb1;">
                <h2>🎨 Design Templates</h2>
                <p>Import canva-editor design templates (canvas JSON + thumbnail). Bảng riêng — <strong>không dùng chung</strong> với AI prompt templates.<br>
                <strong>API:</strong> <code>/wp-json/image-editor/v1/editor-templates</code> &nbsp;|&nbsp; <strong>Table:</strong> <code>bztimg_editor_templates</code></p>
                <p id="templates-count" style="color:#666;"></p>
                <div style="margin:12px 0;">
                    <input type="file" id="file-templates" accept=".json" style="margin-bottom:8px;display:block;">
                    <button class="button button-primary" onclick="bztimgImport('templates')">Import JSON</button>
                    <button class="button" onclick="bztimgUploadSingle('templates')">📸 Upload Media</button>
                    <button class="button" onclick="bztimgClear('templates')" style="color:#d63638;">Xóa tất cả</button>
                </div>
                <div id="result-templates"></div>
            </div>
        </div>

        <!-- Auto Seed from mock-api -->
        <?php
        $mock_dir    = defined( 'BZTIMG_DIR' ) ? BZTIMG_DIR . 'design-editor-build/mock-api/' : '';
        $json_dir    = $mock_dir . 'src/json/';
        $images_dir  = $mock_dir . 'public/images/';
        $dir_ok      = is_dir( $mock_dir );
        $json_ok     = is_dir( $json_dir );
        $images_ok   = is_dir( $images_dir );
        ?>
        <div class="card" style="padding:20px;margin-top:20px;border-left:4px solid <?php echo $json_ok ? '#22c55e' : '#ef4444'; ?>;">
            <h2>🌱 Auto Seed từ Mock-API</h2>
            <p>Tự động đọc JSON từ <code>design-editor-build/mock-api/src/json/</code>, upload ảnh thumbnail vào WP Media Library, rồi insert vào DB.<br>
            An toàn gọi nhiều lần — dedup bằng <code>content_hash</code>/<code>family</code>.</p>

            <p>
                Mock-API: <?php echo $dir_ok ? '<span style="color:#22c55e;">✅ Ready</span>' : '<span style="color:#ef4444;">❌ NOT FOUND</span>'; ?>
                &nbsp;|&nbsp; JSON: <?php echo $json_ok ? '<span style="color:#22c55e;">✅</span>' : '<span style="color:#ef4444;">❌</span>'; ?>
                &nbsp;|&nbsp; Images: <?php echo $images_ok ? '<span style="color:#22c55e;">✅</span>' : '<span style="color:#ef4444;">❌</span>'; ?>
            </p>

            <?php if ( ! $json_ok ) : ?>
                <div class="notice notice-error inline" style="margin:12px 0;">
                    <p>⚠️ Thư mục mock-api chưa có trên server. Cần upload thư mục <code>mock-api/</code> vào <code>design-editor-build/</code>.<br>
                    Cấu trúc cần thiết:<br>
                    <code>design-editor-build/mock-api/src/json/*.json</code><br>
                    <code>design-editor-build/mock-api/public/images/</code></p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $seed_done ) ) : ?>
                <div class="notice notice-success inline" style="margin:12px 0;">
                    <p>✅ Seed hoàn tất! Tổng: <strong><?php echo intval( $seed_total ); ?></strong> items mới<br>
                    🔷 Shapes: <?php echo intval( $seed_result['shapes'] ?? 0 ); ?> ·
                    🖼️ Frames: <?php echo intval( $seed_result['frames'] ?? 0 ); ?> ·
                    🔤 Fonts: <?php echo intval( $seed_result['fonts'] ?? 0 ); ?> ·
                    📝 Texts: <?php echo intval( $seed_result['texts'] ?? 0 ); ?> ·
                    🎨 Templates: <?php echo intval( $seed_result['templates'] ?? 0 ); ?>
                    </p>
                </div>
            <?php elseif ( ! empty( $seed_error ) ) : ?>
                <div class="notice notice-error inline" style="margin:12px 0;">
                    <p>❌ <?php echo esc_html( $seed_error ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" onsubmit="return confirm('Auto Seed sẽ đọc JSON từ mock-api, upload ảnh vào Media Library, rồi insert vào DB.\nQuá trình có thể mất vài phút. Tiếp tục?');">
                <?php wp_nonce_field( 'bztimg_seed_assets' ); ?>
                <input type="hidden" name="bztimg_action" value="seed_assets" />
                <div style="margin:12px 0;">
                    <button type="submit" class="button button-primary button-hero" id="btn-auto-seed" <?php echo $json_ok ? '' : 'disabled'; ?>>🌱 Auto Seed Tất Cả</button>
                    <?php if ( ! $json_ok ) : ?><span style="margin-left:8px;color:#ef4444;">Upload mock-api/ trước khi seed</span><?php endif; ?>
                </div>
            </form>

            <!-- Reseed Shapes (upsert) -->
            <?php if ( ! empty( $reseed_done ) ) : ?>
                <div class="notice notice-success inline" style="margin:12px 0;">
                    <p>✅ Reseed shapes hoàn tất! ➕ Mới: <strong><?php echo intval( $reseed_result['inserted'] ?? 0 ); ?></strong> · 🔄 Cập nhật: <strong><?php echo intval( $reseed_result['updated'] ?? 0 ); ?></strong></p>
                </div>
            <?php elseif ( ! empty( $reseed_error ) ) : ?>
                <div class="notice notice-error inline" style="margin:12px 0;">
                    <p>❌ <?php echo esc_html( $reseed_error ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" style="margin-top:8px;" onsubmit="return confirm('Sẽ re-upload ảnh hình dạng từ mock-api và cập nhật link trong DB.\nTiếp tục?');">
                <?php wp_nonce_field( 'bztimg_reseed_shapes' ); ?>
                <input type="hidden" name="bztimg_action" value="reseed_shapes" />
                <div style="margin:8px 0;">
                    <button type="submit" class="button button-secondary" <?php echo $json_ok ? '' : 'disabled'; ?>>🔷 Reseed Shapes (cập nhật ảnh + link)</button>
                    <?php if ( ! $json_ok ) : ?><span style="margin-left:8px;color:#ef4444;">Cần mock-api/</span><?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Rewrite localhost URLs in existing DB data -->
        <?php
        global $wpdb;
        $tpl_localhost = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bztimg_editor_templates WHERE data_json LIKE '%localhost:4000%'" );
        $txt_localhost = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bztimg_editor_text_presets WHERE data_json LIKE '%localhost:4000%'" );
        $total_localhost = $tpl_localhost + $txt_localhost;
        ?>
        <?php if ( $total_localhost > 0 ) : ?>
        <div class="card" style="padding:20px;margin-top:20px;border-left:4px solid #f59e0b;">
            <h2>🔄 Rewrite localhost URLs</h2>
            <p>Phát hiện <strong><?php echo $total_localhost; ?></strong> records cần rewrite URLs.</p>

            <?php if ( ! empty( $rewrite_done ) ) : ?>
                <div class="notice notice-success inline" style="margin:12px 0;">
                    <p>✅ Rewrite hoàn tất! Đã cập nhật <strong><?php echo intval( $rewrite_count ); ?></strong> records.</p>
                </div>
            <?php endif; ?>

            <form method="post" onsubmit="return confirm('Sẽ rewrite tất cả localhost:4000 URLs trong data_json.\nTiếp tục?');">
                <?php wp_nonce_field( 'bztimg_rewrite_urls' ); ?>
                <input type="hidden" name="bztimg_action" value="rewrite_urls" />
                <div style="margin:12px 0;">
                    <button type="submit" class="button button-hero" style="background:#f59e0b;border-color:#d97706;color:#fff;">🔄 Rewrite <?php echo $total_localhost; ?> Records</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- URL Rewrite Settings -->
        <div class="card" style="padding:20px;margin-top:20px;">
            <h2>⚙️ URL Rewrite (Thumbnail — khi import JSON)</h2>
            <p>Khi import JSON từ mock-api, URL thumbnail sẽ được thay thế. Dùng cho trường hợp import lần đầu.</p>
            <table class="form-table">
                <tr>
                    <th>URL gốc (mock-api)</th>
                    <td><input type="text" id="url-from" value="http://localhost:4000" style="width:100%;" class="regular-text"></td>
                </tr>
                <tr>
                    <th>URL thay thế</th>
                    <td>
                        <input type="text" id="url-to" value="<?php echo esc_url( home_url( '/wp-content/uploads/editor-assets' ) ); ?>" style="width:100%;" class="regular-text">
                        <p class="description">Ví dụ: <code>https://bizcity.vn/wp-content/uploads/editor-assets</code> — không dùng đường dẫn plugin!</p>
                    </td>
                </tr>
            </table>
        </div>


    </div>

    <script>
    (function(){
        const restUrl = <?php echo wp_json_encode( rest_url( 'image-editor/v1/' ) ); ?>;
        const nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

        // Load current counts
        loadCounts();

        function loadCounts() {
            fetch(restUrl + 'editor-assets/counts', {headers: {'X-WP-Nonce': nonce}})
                .then(r => r.ok ? r.json() : {})
                .then(counts => {
                    if (counts.shapes !== undefined) document.getElementById('shapes-count').textContent = 'Hiện có: ' + counts.shapes + ' shapes';
                    if (counts.frames !== undefined) document.getElementById('frames-count').textContent = 'Hiện có: ' + counts.frames + ' frames';
                    if (counts.fonts !== undefined) document.getElementById('fonts-count').textContent = 'Hiện có: ' + counts.fonts + ' fonts';
                    if (counts.texts !== undefined) document.getElementById('texts-count').textContent = 'Hiện có: ' + counts.texts + ' text presets';
                    if (counts.templates !== undefined) document.getElementById('templates-count').textContent = 'Hiện có: ' + counts.templates + ' templates';
                })
                .catch(() => {});
        }

        /* ── Hub URL management ── */
        window.bztimgSaveHub = function() {
            const url = document.getElementById('hub-url').value.trim();
            const status = document.getElementById('hub-status');
            status.textContent = '⏳ Đang lưu...';
            fetch(ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=bztimg_save_hub_url&_wpnonce=' + encodeURIComponent(<?php echo wp_json_encode( wp_create_nonce( 'bztimg_hub' ) ); ?>) + '&hub_url=' + encodeURIComponent(url)
            })
            .then(r => r.json())
            .then(d => {
                status.innerHTML = d.success
                    ? '<span style="color:green;">✅ Đã lưu!</span>'
                    : '<span style="color:red;">❌ Lỗi</span>';
            })
            .catch(() => { status.innerHTML = '<span style="color:red;">❌ Network error</span>'; });
        };

        window.bztimgTestHub = function() {
            const url = document.getElementById('hub-url').value.trim();
            const status = document.getElementById('hub-status');
            if (!url) { status.innerHTML = '<span style="color:orange;">⚠️ Nhập Hub URL trước</span>'; return; }
            status.textContent = '⏳ Đang test...';
            fetch(url.replace(/\/$/, '') + '/wp-json/image-editor/v1/editor-assets/counts')
                .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
                .then(counts => {
                    const parts = [];
                    if (counts.shapes) parts.push(counts.shapes + ' shapes');
                    if (counts.frames) parts.push(counts.frames + ' frames');
                    if (counts.fonts) parts.push(counts.fonts + ' fonts');
                    if (counts.texts) parts.push(counts.texts + ' texts');
                    if (counts.templates) parts.push(counts.templates + ' templates');
                    let schemaInfo = counts.schema_version ? ' (schema ' + counts.schema_version + ')' : '';
                    const needsUpgrade = counts.schema_version && parseFloat(counts.schema_version) < 5.0;
                    if (needsUpgrade) {
                        status.innerHTML = '<span style="color:orange;">⚠️ Hub schema ' + counts.schema_version + ' &lt; 5.0 — Cần upgrade hub trước khi proxy templates. Assets: ' + (parts.join(', ') || 'trống') + '</span>';
                    } else {
                        status.innerHTML = '<span style="color:green;">✅ OK' + schemaInfo + ' — Hub có: ' + (parts.join(', ') || 'trống') + '</span>';
                    }
                })
                .catch(err => {
                    status.innerHTML = '<span style="color:red;">❌ Không kết nối được (' + err + ')</span>';
                });
        };

        /* ── WP Media Library picker ── */
        window.bztimgUploadSingle = function(type) {
            if (typeof wp === 'undefined' || !wp.media) {
                alert('WP Media Library không sẵn có. Đảm bảo wp_enqueue_media() đã được gọi.');
                return;
            }
            const frame = wp.media({
                title: 'Chọn thumbnail cho ' + type,
                multiple: true,
                library: { type: 'image' }
            });
            frame.on('select', function() {
                const attachments = frame.state().get('selection').toJSON();
                const resultDiv = document.getElementById('result-' + type);
                resultDiv.innerHTML = '<p>⏳ Đang lưu ' + attachments.length + ' thumbnails...</p>';

                // Save attachment IDs to existing items that have no thumbnail
                const ids = attachments.map(a => ({ id: a.id, url: a.url }));
                fetch(restUrl + 'editor-assets/attach-media', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    body: JSON.stringify({ type: type, attachments: ids })
                })
                .then(r => r.json())
                .then(d => {
                    if (d.updated !== undefined) {
                        resultDiv.innerHTML = '<div class="notice notice-success inline"><p>✅ Đã gắn ' + d.updated + ' thumbnail từ Media Library</p></div>';
                    } else {
                        resultDiv.innerHTML = '<div class="notice notice-warning inline"><p>' + (d.message || 'Không có item nào cần thumbnail') + '</p></div>';
                    }
                })
                .catch(err => {
                    resultDiv.innerHTML = '<div class="notice notice-error inline"><p>❌ ' + err.message + '</p></div>';
                });
            });
            frame.open();
        };

        /* ── JSON Import ── */
        window.bztimgImport = function(type) {
            const fileInput = document.getElementById('file-' + type);
            const resultDiv = document.getElementById('result-' + type);

            if (!fileInput.files.length) {
                resultDiv.innerHTML = '<div class="notice notice-warning inline"><p>Chọn file JSON trước.</p></div>';
                return;
            }

            resultDiv.innerHTML = '<p>⏳ Đang đọc file...</p>';

            const reader = new FileReader();
            reader.onload = function(e) {
                let parsed;
                try {
                    parsed = JSON.parse(e.target.result);
                } catch (err) {
                    resultDiv.innerHTML = '<div class="notice notice-error inline"><p>JSON không hợp lệ: ' + err.message + '</p></div>';
                    return;
                }

                let items = Array.isArray(parsed) ? parsed : (parsed.data || []);
                if (!items.length) {
                    resultDiv.innerHTML = '<div class="notice notice-warning inline"><p>Không tìm thấy dữ liệu</p></div>';
                    return;
                }

                // URL rewrite
                const urlFrom = document.getElementById('url-from').value.trim();
                const urlTo   = document.getElementById('url-to').value.trim();
                if (urlFrom && urlTo) {
                    const json = JSON.stringify(items);
                    items = JSON.parse(json.split(urlFrom).join(urlTo));
                }

                resultDiv.innerHTML = '<p>⏳ Đang import ' + items.length + ' items...</p>';
                importBatch(type, items, 0, 100, resultDiv);
            };
            reader.readAsText(fileInput.files[0]);
        };

        function importBatch(type, allItems, offset, batchSize, resultDiv) {
            const batch = allItems.slice(offset, offset + batchSize);
            if (!batch.length) {
                resultDiv.innerHTML += '<div class="notice notice-success inline"><p>✅ Import hoàn tất! Tổng: ' + allItems.length + ' items (duplicates tự động bỏ qua)</p></div>';
                loadCounts();
                return;
            }

            fetch(restUrl + 'editor-assets/import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ type: type, data: batch })
            })
            .then(r => r.json())
            .then(result => {
                if (result.code) {
                    resultDiv.innerHTML = '<div class="notice notice-error inline"><p>❌ Lỗi: ' + (result.message || result.code) + '</p></div>';
                    return;
                }
                const progress = Math.min(offset + batchSize, allItems.length);
                resultDiv.innerHTML = '<p>⏳ Đã import ' + progress + '/' + allItems.length + '... (batch: +' + result.imported + ', errors: ' + result.errors + ')</p>';
                importBatch(type, allItems, offset + batchSize, batchSize, resultDiv);
            })
            .catch(err => {
                resultDiv.innerHTML = '<div class="notice notice-error inline"><p>❌ Network error: ' + err.message + '</p></div>';
            });
        }

        /* ── Clear ── */
        window.bztimgClear = function(type) {
            if (!confirm('Bạn có chắc muốn xóa TẤT CẢ ' + type + ' khỏi database?')) return;
            const resultDiv = document.getElementById('result-' + type);
            resultDiv.innerHTML = '<p>⏳ Đang xóa...</p>';

            fetch(restUrl + 'editor-assets/clear', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ type: type })
            })
            .then(r => r.json())
            .then(result => {
                if (result.code) {
                    resultDiv.innerHTML = '<div class="notice notice-error inline"><p>❌ ' + (result.message || result.code) + '</p></div>';
                    return;
                }
                resultDiv.innerHTML = '<div class="notice notice-success inline"><p>✅ Đã xóa ' + result.deleted + ' items</p></div>';
                loadCounts();
            })
            .catch(err => {
                resultDiv.innerHTML = '<div class="notice notice-error inline"><p>❌ ' + err.message + '</p></div>';
            });
        };
    })();
    </script>
</div>
