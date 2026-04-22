<?php
/**
 * Admin View: AI Template Generator (Phase 3.6)
 *
 * Two generation modes:
 *   PA1: Vision-to-Template — upload reference image → AI recreates as lidojs template
 *   PA2: Variation Engine — pick skeleton + prompt → AI generates variations
 *
 * @package BizCity_Tool_Image
 * @since   3.6.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$rest_ns    = rest_url( BizCity_REST_API_Editor_Assets::NS );
$nonce      = wp_create_nonce( 'wp_rest' );
$llm_ready  = BizCity_AI_Template_Generator::llm_ready();
$presets    = BizCity_AI_Template_Generator::CANVAS_PRESETS;
?>
<div class="wrap">
    <h1>🤖 AI Template Generator — Phase 3.6</h1>

    <?php if ( ! $llm_ready ) : ?>
    <div class="notice notice-error"><p><strong>⚠️ BizCity LLM chưa sẵn sàng.</strong> Cấu hình API key tại <em>BizCity LLM Settings</em> (gateway hoặc OpenRouter key).</p></div>
    <?php endif; ?>

    <p>Tạo template cho Design Editor bằng AI. Chọn một trong hai phương án:</p>

    <!-- ═══ Tab Navigation ═══ -->
    <nav class="nav-tab-wrapper" id="ai-tabs">
        <a href="#" class="nav-tab nav-tab-active" data-tab="vision">🖼️ PA1 — Vision to Template</a>
        <a href="#" class="nav-tab" data-tab="variation">🔄 PA2 — Variation Engine</a>
        <a href="#" class="nav-tab" data-tab="results">📋 Kết quả (<span id="result-count">0</span>)</a>
    </nav>

    <!-- ═══ PA1: Vision to Template ═══ -->
    <div class="ai-tab-content" id="tab-vision" style="margin-top:20px;">
        <div style="background:#fff;padding:20px;border:1px solid #e5e7eb;border-radius:8px;">
            <h2>🖼️ PA1 — Vision to Template</h2>
            <p>Upload hoặc paste URL ảnh mẫu → AI (Claude Sonnet) sẽ phân tích layout và tạo template tương ứng.</p>

            <table class="form-table">
                <tr>
                    <th><label>Ảnh mẫu</label></th>
                    <td>
                        <input type="url" id="vision-image-url" class="regular-text" placeholder="https://... hoặc paste base64 data URI" style="width:70%;">
                        <button type="button" class="button" id="vision-upload-btn">📎 Upload</button>
                        <div id="vision-preview" style="margin-top:10px;"></div>
                    </td>
                </tr>
                <tr>
                    <th><label>Canvas preset</label></th>
                    <td>
                        <select id="vision-preset">
                            <?php foreach ( $presets as $key => $size ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>"
                                    <?php selected( $key, 'square' ); ?>>
                                    <?php echo esc_html( $key . " ({$size['width']}×{$size['height']})" ); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="custom">Custom...</option>
                        </select>
                        <span id="vision-custom-size" style="display:none;">
                            <input type="number" id="vision-width" value="900" min="100" max="5000" style="width:80px;">
                            ×
                            <input type="number" id="vision-height" value="900" min="100" max="5000" style="width:80px;"> px
                        </span>
                    </td>
                </tr>
                <tr>
                    <th><label>Mô tả thêm</label></th>
                    <td>
                        <textarea id="vision-description" rows="3" class="large-text" placeholder="VD: Banner khuyến mãi Tết 2026, phong cách hiện đại, màu đỏ vàng..."></textarea>
                    </td>
                </tr>
                <tr>
                    <th><label>Ngôn ngữ text</label></th>
                    <td>
                        <select id="vision-language">
                            <option value="vi" selected>Tiếng Việt</option>
                            <option value="en">English</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary button-hero" id="btn-vision-generate" <?php echo $llm_ready ? '' : 'disabled'; ?>>
                    🚀 Tạo Template từ Ảnh
                </button>
                <span class="spinner" id="spinner-vision" style="float:none;"></span>
            </p>
        </div>
    </div>

    <!-- ═══ PA2: Variation Engine ═══ -->
    <div class="ai-tab-content" id="tab-variation" style="display:none;margin-top:20px;">
        <div style="background:#fff;padding:20px;border:1px solid #e5e7eb;border-radius:8px;">
            <h2>🔄 PA2 — Variation Engine</h2>
            <p>Chọn template gốc (skeleton) → AI tạo ra nhiều biến thể với text, màu sắc, font khác nhau.</p>

            <table class="form-table">
                <tr>
                    <th><label>Template gốc</label></th>
                    <td>
                        <div id="skeleton-list" style="display:flex;flex-wrap:wrap;gap:12px;max-height:300px;overflow-y:auto;border:1px solid #ddd;padding:12px;border-radius:6px;">
                            <em>Đang tải...</em>
                        </div>
                        <input type="hidden" id="selected-skeleton-id" value="">
                    </td>
                </tr>
                <tr>
                    <th><label>Prompt mô tả</label></th>
                    <td>
                        <textarea id="variation-prompt" rows="4" class="large-text" placeholder="VD: Tạo 3 biến thể cho quán cà phê, phong cách minimalist, tone pastel..."></textarea>
                    </td>
                </tr>
                <tr>
                    <th><label>Số biến thể</label></th>
                    <td>
                        <input type="number" id="variation-count" value="3" min="1" max="10" style="width:80px;">
                    </td>
                </tr>
                <tr>
                    <th><label>Thay đổi</label></th>
                    <td>
                        <label><input type="checkbox" name="vary_fields[]" value="text" checked> Text</label>&nbsp;
                        <label><input type="checkbox" name="vary_fields[]" value="colors" checked> Colors</label>&nbsp;
                        <label><input type="checkbox" name="vary_fields[]" value="fonts" checked> Fonts</label>&nbsp;
                        <label><input type="checkbox" name="vary_fields[]" value="effects"> Effects</label>
                    </td>
                </tr>
                <tr>
                    <th><label>Ngôn ngữ</label></th>
                    <td>
                        <select id="variation-language">
                            <option value="vi" selected>Tiếng Việt</option>
                            <option value="en">English</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary button-hero" id="btn-variation-generate" <?php echo $llm_ready ? '' : 'disabled'; ?>>
                    🔄 Tạo Biến Thể
                </button>
                <span class="spinner" id="spinner-variation" style="float:none;"></span>
            </p>
        </div>
    </div>

    <!-- ═══ Results Panel ═══ -->
    <div class="ai-tab-content" id="tab-results" style="display:none;margin-top:20px;">
        <div style="background:#fff;padding:20px;border:1px solid #e5e7eb;border-radius:8px;">
            <h2>📋 Kết quả AI</h2>
            <div id="ai-results-container">
                <p><em>Chưa có kết quả. Hãy chạy PA1 hoặc PA2.</em></p>
            </div>
        </div>
    </div>
</div>

<style>
.ai-tab-content { animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
.skeleton-card { border:2px solid #ddd; border-radius:8px; padding:8px; cursor:pointer;
    width:150px; text-align:center; transition:border-color 0.2s; }
.skeleton-card:hover { border-color:#2271b1; }
.skeleton-card.selected { border-color:#2271b1; background:#f0f6fc; }
.skeleton-card img { max-width:130px; max-height:90px; object-fit:contain; }
.skeleton-card .desc { font-size:11px; color:#666; margin-top:4px; white-space:nowrap;
    overflow:hidden; text-overflow:ellipsis; }
.ai-result-card { border:1px solid #e5e7eb; border-radius:8px; padding:16px; margin-bottom:12px;
    display:flex; align-items:flex-start; gap:16px; }
.ai-result-card pre { background:#f6f6f6; padding:10px; border-radius:4px; max-height:200px;
    overflow:auto; font-size:11px; flex:1; }
.ai-result-card .actions { flex-shrink:0; display:flex; flex-direction:column; gap:6px; }
</style>

<script>
(function($){
    const NS = <?php echo wp_json_encode( $rest_ns ); ?>;
    const NONCE = <?php echo wp_json_encode( $nonce ); ?>;
    const apiHeaders = { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE };
    let allResults = [];

    /* ── Tab switching ── */
    $('#ai-tabs .nav-tab').on('click', function(e){
        e.preventDefault();
        const tab = $(this).data('tab');
        $('#ai-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.ai-tab-content').hide();
        $('#tab-' + tab).show();
    });

    /* ── Custom canvas size toggle ── */
    $('#vision-preset').on('change', function(){
        $('#vision-custom-size').toggle($(this).val() === 'custom');
    });

    /* ── Image preview ── */
    $('#vision-image-url').on('input', function(){
        const url = $(this).val();
        if (url.match(/^(https?:\/\/|data:image\/)/i)) {
            $('#vision-preview').html('<img src="' + $('<div>').text(url).html() + '" style="max-width:300px;max-height:200px;border:1px solid #ddd;border-radius:4px;">');
        }
    });

    /* ── WP Media uploader for vision ── */
    $('#vision-upload-btn').on('click', function(){
        const frame = wp.media({ title: 'Chọn ảnh mẫu', multiple: false, library: {type:'image'} });
        frame.on('select', function(){
            const url = frame.state().get('selection').first().toJSON().url;
            $('#vision-image-url').val(url).trigger('input');
        });
        frame.open();
    });

    /* ── Helper: escape HTML for safe insertion ── */
    function esc(str) { return $('<span>').text(str || '').html(); }

    /* ── Load skeletons for PA2 ── */
    function loadSkeletons() {
        $.ajax({
            url: NS + '/ai/skeletons',
            headers: { 'X-WP-Nonce': NONCE },
            success: function(resp) {
                const list = $('#skeleton-list').empty();
                if (!resp.data || !resp.data.length) {
                    list.html('<em>Chưa có template. Import Editor Assets trước.</em>');
                    return;
                }
                resp.data.forEach(function(t) {
                    const card = $('<div class="skeleton-card">').attr('data-id', t.id);
                    if (t.thumb_url) card.append($('<img>').attr('src', t.thumb_url));
                    card.append($('<div class="desc">').text('#' + t.id + ' — ' + (t.description || 'Template')));
                    card.on('click', function(){
                        $('.skeleton-card').removeClass('selected');
                        $(this).addClass('selected');
                        $('#selected-skeleton-id').val(t.id);
                    });
                    list.append(card);
                });
            }
        });
    }
    loadSkeletons();

    /* ── PA1: Generate from vision ── */
    $('#btn-vision-generate').on('click', function(){
        const imageUrl = $('#vision-image-url').val().trim();
        if (!imageUrl) { alert('Hãy nhập URL ảnh mẫu.'); return; }

        const preset = $('#vision-preset').val();
        const body = {
            image_url: imageUrl,
            canvas_preset: preset !== 'custom' ? preset : 'square',
            canvas_width: preset === 'custom' ? parseInt($('#vision-width').val()) : 0,
            canvas_height: preset === 'custom' ? parseInt($('#vision-height').val()) : 0,
            description: $('#vision-description').val(),
            language: $('#vision-language').val(),
        };

        const btn = $(this).prop('disabled', true);
        $('#spinner-vision').addClass('is-active');

        fetch(NS + '/ai/vision-to-template', {
            method: 'POST', headers: apiHeaders, body: JSON.stringify(body)
        }).then(r => r.json()).then(data => {
            btn.prop('disabled', false);
            $('#spinner-vision').removeClass('is-active');

            if (data.success && data.template) {
                addResult('vision', data);
                switchToResults();
            } else {
                alert('Lỗi: ' + (data.error || 'Không rõ'));
            }
        }).catch(err => {
            btn.prop('disabled', false);
            $('#spinner-vision').removeClass('is-active');
            alert('Request failed: ' + err.message);
        });
    });

    /* ── PA2: Generate variations ── */
    $('#btn-variation-generate').on('click', function(){
        const skeletonId = $('#selected-skeleton-id').val();
        if (!skeletonId) { alert('Hãy chọn một template gốc.'); return; }

        const varyFields = [];
        $('input[name="vary_fields[]"]:checked').each(function(){ varyFields.push($(this).val()); });

        const body = {
            skeleton_id: parseInt(skeletonId),
            prompt: $('#variation-prompt').val() || 'Create diverse variations',
            count: parseInt($('#variation-count').val()) || 3,
            language: $('#variation-language').val(),
            vary_fields: varyFields,
        };

        const btn = $(this).prop('disabled', true);
        $('#spinner-variation').addClass('is-active');

        fetch(NS + '/ai/generate-variations', {
            method: 'POST', headers: apiHeaders, body: JSON.stringify(body)
        }).then(r => r.json()).then(data => {
            btn.prop('disabled', false);
            $('#spinner-variation').removeClass('is-active');

            if (data.success && data.variations) {
                data.variations.forEach((v, i) => addResult('variation', {
                    template: [v],
                    packed: data.packed ? [data.packed[i]] : null,
                    model: data.model,
                }, i));
                switchToResults();
            } else {
                alert('Lỗi: ' + (data.error || 'Không rõ'));
            }
        }).catch(err => {
            btn.prop('disabled', false);
            $('#spinner-variation').removeClass('is-active');
            alert('Request failed: ' + err.message);
        });
    });

    /* ── Results management ── */
    function addResult(type, data, idx) {
        idx = idx || 0;
        const id = allResults.length;
        allResults.push({ type, data });
        $('#result-count').text(allResults.length);
        renderResult(id, type, data);
    }

    function renderResult(id, type, data) {
        const container = $('#ai-results-container');
        if (id === 0) container.empty();

        const label = type === 'vision' ? '🖼️ Vision' : '🔄 Variation';
        const json = JSON.stringify(data.template, null, 2);
        const card = $(`
            <div class="ai-result-card" data-id="${id}">
                <pre>${$('<span>').text(json.substring(0,2000) + (json.length>2000?'\n...':'') ).html()}</pre>
                <div class="actions">
                    <strong>${esc(label)}</strong>
                    <small>Model: ${esc(data.model)}</small>
                    <button class="button button-primary btn-save-template" data-id="${id}">💾 Lưu Template</button>
                    <button class="button btn-copy-json" data-id="${id}">📋 Copy JSON</button>
                    <button class="button btn-open-editor" data-id="${id}">🖌️ Mở Editor</button>
                </div>
            </div>
        `);
        container.append(card);
    }

    function switchToResults() {
        $('#ai-tabs .nav-tab').removeClass('nav-tab-active');
        $('#ai-tabs .nav-tab[data-tab="results"]').addClass('nav-tab-active');
        $('.ai-tab-content').hide();
        $('#tab-results').show();
    }

    /* ── Save template to DB ── */
    $(document).on('click', '.btn-save-template', function(){
        const id = $(this).data('id');
        const r = allResults[id];
        if (!r) return;

        const desc = prompt('Mô tả template:', r.type === 'vision' ? 'AI Vision template' : 'AI Variation template');
        if (desc === null) return;

        fetch(NS + '/ai/save-template', {
            method: 'POST', headers: apiHeaders,
            body: JSON.stringify({
                template: r.data.template,
                description: desc,
                source: r.type === 'vision' ? 'ai_vision' : 'ai_variation',
            }),
        }).then(r => r.json()).then(data => {
            if (data.success) {
                alert('✅ Đã lưu! ID: ' + data.id);
            } else {
                alert('Lỗi: ' + (data.error || 'Không rõ'));
            }
        });
    });

    /* ── Copy JSON ── */
    $(document).on('click', '.btn-copy-json', function(){
        const id = $(this).data('id');
        const r = allResults[id];
        if (!r) return;
        navigator.clipboard.writeText(JSON.stringify(r.data.template, null, 2)).then(() => {
            alert('📋 Đã copy JSON!');
        });
    });

    /* ── Open in Editor ── */
    $(document).on('click', '.btn-open-editor', function(){
        const id = $(this).data('id');
        const r = allResults[id];
        if (!r) return;
        // Store template in sessionStorage, editor will pick it up
        sessionStorage.setItem('bztimg_ai_template', JSON.stringify(r.data.packed || r.data.template));
        window.open(<?php echo wp_json_encode( admin_url( 'admin.php?page=bztimg-editor&ai_template=1' ) ); ?>, '_blank');
    });

})(jQuery);
</script>
