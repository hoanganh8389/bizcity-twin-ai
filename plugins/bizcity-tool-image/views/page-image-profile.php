<?php
/**
 * BizCity Tool Image — Agent Profile View: /tool-image/
 *
 * Type B: 4 Tabs — Create (+ Prompt Library), Monitor, Chat, Settings
 *
 * @package BizCity_Tool_Image
 * @since   2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id      = get_current_user_id();
$is_logged_in = is_user_logged_in();

// Stats
$stats       = [ 'total' => 0, 'done' => 0, 'active' => 0 ];
$recent_jobs = [];
if ( $is_logged_in ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_jobs';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
        $stats['total']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
        $stats['done']   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'completed'", $user_id ) );
        $stats['active'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'processing'", $user_id ) );

        $recent_jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, prompt, model, size, style, status, image_url, attachment_id, ref_image, error_message, created_at
             FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 30",
            $user_id
        ), ARRAY_A );
    }
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'create';
$allowed_tabs = [ 'create', 'monitor', 'chat', 'settings' ];
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) $active_tab = 'create';

$cfg_model    = get_option( 'bztimg_default_model', 'flux-pro' );
$cfg_size     = get_option( 'bztimg_default_size', '1024x1024' );
$cfg_api_key  = get_option( 'bztimg_api_key', '' );
$cfg_endpoint = get_option( 'bztimg_api_endpoint', 'https://openrouter.ai/api/v1' );
$cfg_openai   = get_option( 'bztimg_openai_key', '' );
$is_admin     = current_user_can( 'manage_options' );

// Prompt library
$prompt_lib = class_exists( 'BizCity_Tool_Image' ) ? BizCity_Tool_Image::get_prompt_library() : [];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Image AI — Tạo ảnh AI</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#f9fafb;font-family:system-ui,-apple-system,sans-serif;color:#1a1a2e;line-height:1.5;min-height:100vh;}

.bti-app{max-width:100%;padding:0 0 72px;position:relative;}

/* ── Bottom Nav ── */
.bti-nav{position:fixed;bottom:0;left:0;right:0;display:flex;background:#fff;border-top:1px solid #e5e7eb;z-index:100;padding:6px 0 env(safe-area-inset-bottom, 4px);}
.bti-nav-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 0;text-decoration:none;color:#9ca3af;font-size:10px;font-weight:600;transition:color .2s;cursor:pointer;border:none;background:none;}
.bti-nav-item.active{color:#8b5cf6;}
.bti-nav-icon{font-size:20px;}

/* ── Tab ── */
.bti-tab{display:none;}
.bti-tab.active{display:block;}

/* ── Hero ── */
.bti-hero{background:linear-gradient(135deg,#7c3aed 0%,#8b5cf6 40%,#a78bfa 70%,#c4b5fd 100%);padding:24px 16px;color:#fff;position:relative;overflow:hidden;}
.bti-hero::before{content:'';position:absolute;top:-50%;right:-20%;width:200px;height:200px;background:rgba(255,255,255,0.08);border-radius:50%;}
.bti-hero-icon{font-size:36px;margin-bottom:4px;}
.bti-hero h2{font-size:20px;font-weight:800;margin-bottom:2px;}
.bti-hero p{opacity:0.9;font-size:12px;}
.bti-hero-stats{display:flex;gap:12px;margin-top:10px;flex-wrap:wrap;}
.bti-hero-stat{background:rgba(255,255,255,0.18);backdrop-filter:blur(4px);border-radius:8px;padding:6px 12px;font-size:11px;font-weight:600;}

/* ── Card ── */
.bti-card{background:#fff;border-radius:16px;padding:20px 16px;margin:12px 12px 0;box-shadow:0 1px 4px rgba(0,0,0,0.04);}
.bti-card h3{font-size:16px;font-weight:700;margin-bottom:12px;}

/* ── Photo Zone ── */
.bti-photo-zone{display:block;border:2px dashed #d1d5db;border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:12px;position:relative;overflow:hidden;}
.bti-photo-zone:hover{border-color:#8b5cf6;}
.bti-photo-placeholder{color:#9ca3af;}
.bti-photo-placeholder span{font-size:36px;display:block;margin-bottom:4px;}
.bti-photo-placeholder p{font-size:13px;}
.bti-photo-placeholder small{font-size:11px;color:#d1d5db;}
#bti-photo-preview{display:none;}
#bti-photo-preview img{max-width:100%;max-height:180px;border-radius:8px;object-fit:contain;}

/* ── Fields ── */
.bti-field{margin-bottom:12px;}
.bti-field label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;}
.bti-field textarea,.bti-field input,.bti-field select{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:13px;font-family:inherit;resize:vertical;background:#fff;color:#1a1a2e;}
.bti-field textarea:focus,.bti-field input:focus,.bti-field select:focus{outline:none;border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(139,92,246,0.1);}

/* ── Pills ── */
.bti-pill-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.bti-pill{display:inline-flex;align-items:center;gap:4px;padding:8px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;background:#fff;}
.bti-pill:has(input:checked){border-color:#8b5cf6;background:#f5f3ff;color:#8b5cf6;}
.bti-pill input{display:none;}

/* ── Buttons ── */
.bti-btn{width:100%;padding:12px;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;}
.bti-btn-primary{background:linear-gradient(135deg,#7c3aed,#8b5cf6);color:#fff;}
.bti-btn-primary:hover{opacity:0.9;transform:translateY(-1px);}
.bti-btn-primary:disabled{opacity:0.5;cursor:not-allowed;transform:none;}
.bti-btn-secondary{background:#fff;border:1.5px solid #e5e7eb;color:#374151;padding:8px 14px;width:auto;font-size:12px;border-radius:8px;}
.bti-btn-secondary:hover{border-color:#8b5cf6;color:#8b5cf6;}

/* ── Status ── */
.bti-status{margin-top:10px;padding:10px 14px;border-radius:10px;font-size:12px;font-weight:600;display:none;}
.bti-status.success{display:block;background:#dcfce7;color:#166534;}
.bti-status.error{display:block;background:#fef2f2;color:#991b1b;}
.bti-status.loading{display:block;background:#ede9fe;color:#5b21b6;}

/* ── Result ── */
.bti-result{display:none;margin-top:12px;border-radius:12px;overflow:hidden;background:#fff;border:1px solid #e5e7eb;}
.bti-result.show{display:block;}
.bti-result img{width:100%;max-height:400px;object-fit:contain;background:#f9fafb;}
.bti-result-info{padding:12px;font-size:12px;}
.bti-result-actions{display:flex;gap:6px;padding:0 12px 12px;flex-wrap:wrap;}

/* ── Prompt Library ── */
.bti-lib{margin:12px 12px 0;}
.bti-lib h3{font-size:15px;font-weight:700;margin-bottom:10px;padding:0 4px;}
.bti-lib-cats{display:flex;gap:6px;overflow-x:auto;padding:0 4px 8px;-webkit-overflow-scrolling:touch;}
.bti-lib-cats::-webkit-scrollbar{height:0;}
.bti-lib-cat{flex-shrink:0;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;background:#f3f4f6;color:#6b7280;border:1.5px solid transparent;transition:all .2s;white-space:nowrap;}
.bti-lib-cat.active{background:#f5f3ff;color:#8b5cf6;border-color:#8b5cf6;}
.bti-lib-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;padding:4px;max-height:300px;overflow-y:auto;}
.bti-lib-item{padding:10px;border:1px solid #f3f4f6;border-radius:10px;cursor:pointer;transition:all .2s;background:#fff;}
.bti-lib-item:hover{border-color:#8b5cf6;background:#f5f3ff;}
.bti-lib-item h4{font-size:12px;font-weight:600;margin-bottom:2px;}
.bti-lib-item p{font-size:10px;color:#9ca3af;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}

/* ── Monitor ── */
.bti-section{padding:12px;}
.bti-section-title{font-size:15px;font-weight:700;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;}
.bti-job-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;}
.bti-job{border:1px solid #f3f4f6;border-radius:12px;background:#fff;overflow:hidden;}
.bti-job-img{width:100%;aspect-ratio:1;object-fit:cover;background:#f9fafb;display:block;}
.bti-job-img-placeholder{width:100%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:#f3f4f6;font-size:36px;color:#d1d5db;}
.bti-job-body{padding:8px;}
.bti-job-prompt{font-size:11px;color:#374151;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:4px;}
.bti-job-meta{display:flex;gap:4px;align-items:center;font-size:10px;color:#9ca3af;flex-wrap:wrap;}
.bti-job-status{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;}
.st-completed{background:#dcfce7;color:#166534;}
.st-processing{background:#ede9fe;color:#5b21b6;}
.st-failed{background:#fef2f2;color:#991b1b;}
.bti-job-actions{display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;}
.bti-job-actions button,.bti-job-actions a{padding:4px 8px;border-radius:6px;font-size:10px;font-weight:600;cursor:pointer;border:1px solid #e5e7eb;background:#fff;color:#374151;text-decoration:none;transition:all .15s;}
.bti-job-actions button:hover,.bti-job-actions a:hover{border-color:#8b5cf6;color:#8b5cf6;}

/* ── Chat Commands ── */
.bti-cmd{padding:14px;border:1px solid #f3f4f6;border-radius:12px;cursor:pointer;transition:all .2s;background:#fff;margin-bottom:8px;display:flex;align-items:center;gap:12px;}
.bti-cmd:hover{border-color:#8b5cf6;background:#f5f3ff;}
.bti-cmd.primary{border-color:#8b5cf6;background:#f5f3ff;}
.bti-cmd-icon{font-size:24px;flex-shrink:0;}
.bti-cmd-text h4{font-size:13px;font-weight:700;margin-bottom:1px;}
.bti-cmd-text p{font-size:11px;color:#9ca3af;}

/* ── Settings ── */
.bti-settings-group{margin-bottom:16px;}
.bti-settings-group h4{font-size:13px;font-weight:700;margin-bottom:8px;color:#374151;display:flex;align-items:center;gap:6px;}
.bti-settings-row{margin-bottom:10px;}
.bti-settings-row label{display:block;font-size:11px;font-weight:600;color:#6b7280;margin-bottom:3px;}
.bti-settings-row input,.bti-settings-row select{width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;}
.bti-settings-info{margin-top:16px;padding:12px;background:#f3f4f6;border-radius:10px;font-size:11px;color:#6b7280;}

/* ── Tip Cards ── */
.bti-tips{display:flex;flex-direction:column;gap:6px;margin-top:10px;}
.bti-tip{padding:10px 14px;background:#f5f3ff;border-radius:10px;font-size:12px;cursor:pointer;transition:background .2s;}
.bti-tip:hover{background:#ede9fe;}

/* ── Loading spinner ── */
@keyframes bti-spin{to{transform:rotate(360deg)}}
.bti-spinner{display:inline-block;width:16px;height:16px;border:2px solid #e5e7eb;border-top-color:#8b5cf6;border-radius:50%;animation:bti-spin .6s linear infinite;vertical-align:middle;margin-right:6px;}
</style>
</head>
<body>
<div class="bti-app">

<!-- ═══════════ TAB 1: TẠO ẢNH ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'create' ? 'active' : ''; ?>" id="tab-create">

    <div class="bti-hero">
        <div class="bti-hero-icon">🎨</div>
        <h2>Image AI</h2>
        <p>Tạo ảnh AI chuyên nghiệp — FLUX.2 · Gemini · Seedream · GPT-5</p>
        <div class="bti-hero-stats">
            <div class="bti-hero-stat">🖼️ <?php echo esc_html( $stats['total'] ); ?> ảnh</div>
            <div class="bti-hero-stat">✅ <?php echo esc_html( $stats['done'] ); ?> xong</div>
            <?php if ( $stats['active'] > 0 ) : ?>
            <div class="bti-hero-stat">⏳ <?php echo esc_html( $stats['active'] ); ?> đang tạo</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bti-card">
        <h3>🎨 Tạo ảnh mới</h3>

        <!-- Upload ảnh tham chiếu -->
        <label class="bti-photo-zone" id="bti-photo-zone">
            <input type="file" id="bti-photo-input" accept="image/*" style="display:none">
            <div class="bti-photo-placeholder" id="bti-photo-placeholder">
                <span>📷</span>
                <p>Ảnh tham chiếu (tùy chọn)</p>
                <small>Kéo thả hoặc nhấn để chọn ảnh</small>
            </div>
            <div id="bti-photo-preview">
                <img id="bti-photo-img" src="" alt="">
                <p style="font-size:11px;color:#6b7280;margin-top:4px;">Nhấn để đổi ảnh</p>
            </div>
        </label>

        <!-- Prompt -->
        <div class="bti-field">
            <label>✍️ Mô tả ảnh (prompt)</label>
            <textarea id="bti-prompt" rows="3" placeholder="Mô tả chi tiết ảnh bạn muốn tạo..."></textarea>
        </div>

        <!-- Model pills -->
        <div class="bti-field">
            <label>🤖 Model AI</label>
            <div class="bti-pill-row" id="bti-models">
                <label class="bti-pill"><input type="radio" name="model" value="flux-pro" <?php echo $cfg_model === 'flux-pro' ? 'checked' : ''; ?>> 🔥 FLUX.2 Pro</label>
                <label class="bti-pill"><input type="radio" name="model" value="flux-flex" <?php echo $cfg_model === 'flux-flex' ? 'checked' : ''; ?>> ⚡ FLUX.2 Flex</label>
                <label class="bti-pill"><input type="radio" name="model" value="flux-max" <?php echo $cfg_model === 'flux-max' ? 'checked' : ''; ?>> 💎 FLUX.2 Max</label>
                <label class="bti-pill"><input type="radio" name="model" value="flux-klein" <?php echo $cfg_model === 'flux-klein' ? 'checked' : ''; ?>> ⚡ FLUX.2 Klein</label>
                <label class="bti-pill"><input type="radio" name="model" value="gemini-image" <?php echo $cfg_model === 'gemini-image' ? 'checked' : ''; ?>> ✨ Gemini Flash</label>
                <label class="bti-pill"><input type="radio" name="model" value="gemini-pro" <?php echo $cfg_model === 'gemini-pro' ? 'checked' : ''; ?>> ✨ Gemini Pro</label>
                <label class="bti-pill"><input type="radio" name="model" value="seedream" <?php echo $cfg_model === 'seedream' ? 'checked' : ''; ?>> 🎭 Seedream</label>
                <label class="bti-pill"><input type="radio" name="model" value="gpt-image" <?php echo $cfg_model === 'gpt-image' ? 'checked' : ''; ?>> 🧠 GPT-5 Image</label>
                <label class="bti-pill"><input type="radio" name="model" value="gpt-image-mini" <?php echo $cfg_model === 'gpt-image-mini' ? 'checked' : ''; ?>> 🧠 GPT-5 Mini</label>
            </div>
        </div>

        <!-- Size pills -->
        <div class="bti-field">
            <label>📐 Kích thước</label>
            <div class="bti-pill-row" id="bti-sizes">
                <label class="bti-pill"><input type="radio" name="size" value="1024x1024" <?php echo $cfg_size === '1024x1024' ? 'checked' : ''; ?>> 1:1 Vuông</label>
                <label class="bti-pill"><input type="radio" name="size" value="1024x1536" <?php echo $cfg_size === '1024x1536' ? 'checked' : ''; ?>> 2:3 Dọc</label>
                <label class="bti-pill"><input type="radio" name="size" value="1536x1024" <?php echo $cfg_size === '1536x1024' ? 'checked' : ''; ?>> 3:2 Ngang</label>
                <label class="bti-pill"><input type="radio" name="size" value="768x1344" <?php echo $cfg_size === '768x1344' ? 'checked' : ''; ?>> 9:16 Story</label>
                <label class="bti-pill"><input type="radio" name="size" value="1344x768" <?php echo $cfg_size === '1344x768' ? 'checked' : ''; ?>> 16:9 Landscape</label>
            </div>
        </div>

        <!-- Style pills -->
        <div class="bti-field">
            <label>🎭 Phong cách</label>
            <div class="bti-pill-row">
                <label class="bti-pill"><input type="radio" name="style" value="auto" checked> Tự động</label>
                <label class="bti-pill"><input type="radio" name="style" value="photorealistic"> Chân thực</label>
                <label class="bti-pill"><input type="radio" name="style" value="artistic"> Nghệ thuật</label>
                <label class="bti-pill"><input type="radio" name="style" value="anime"> Anime</label>
                <label class="bti-pill"><input type="radio" name="style" value="illustration"> Minh họa</label>
            </div>
        </div>

        <!-- Submit -->
        <button class="bti-btn bti-btn-primary" id="bti-submit" onclick="btiGenerate()">
            🎨 Tạo ảnh
        </button>

        <div class="bti-status" id="bti-create-status"></div>

        <!-- Result -->
        <div class="bti-result" id="bti-result">
            <img id="bti-result-img" src="" alt="AI Generated Image">
            <div class="bti-result-info" id="bti-result-info"></div>
            <div class="bti-result-actions" id="bti-result-actions"></div>
        </div>
    </div>

    <!-- ── PROMPT LIBRARY ── -->
    <div class="bti-lib">
        <h3>📚 Thư viện Prompt</h3>
        <div class="bti-lib-cats" id="bti-lib-cats">
            <?php $first = true; foreach ( $prompt_lib as $cat_key => $cat ) : ?>
            <div class="bti-lib-cat <?php echo $first ? 'active' : ''; ?>" data-cat="<?php echo esc_attr( $cat_key ); ?>">
                <?php echo esc_html( $cat['icon'] . ' ' . $cat['label'] ); ?>
            </div>
            <?php $first = false; endforeach; ?>
        </div>

        <?php foreach ( $prompt_lib as $cat_key => $cat ) : ?>
        <div class="bti-lib-grid" id="lib-<?php echo esc_attr( $cat_key ); ?>" style="<?php echo $cat_key === array_key_first( $prompt_lib ) ? '' : 'display:none'; ?>">
            <?php foreach ( $cat['prompts'] as $p ) : ?>
            <div class="bti-lib-item" data-prompt="<?php echo esc_attr( $p['prompt'] ); ?>">
                <h4><?php echo esc_html( $p['title'] ); ?></h4>
                <p><?php echo esc_html( mb_strimwidth( $p['prompt'], 0, 80, '...' ) ); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>

</div>

<!-- ═══════════ TAB 2: MONITOR ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'monitor' ? 'active' : ''; ?>" id="tab-monitor">

    <div class="bti-hero">
        <div class="bti-hero-icon">📊</div>
        <h2>Lịch sử tạo ảnh</h2>
        <p>Xem, tải về, up Media, chia sẻ ảnh đã tạo</p>
        <div class="bti-hero-stats" id="bti-monitor-stats">
            <div class="bti-hero-stat">🖼️ <span id="ms-total"><?php echo esc_html( $stats['total'] ); ?></span> ảnh</div>
            <div class="bti-hero-stat">✅ <span id="ms-done"><?php echo esc_html( $stats['done'] ); ?></span> xong</div>
            <div class="bti-hero-stat">⏳ <span id="ms-active"><?php echo esc_html( $stats['active'] ); ?></span> đang tạo</div>
        </div>
    </div>

    <div class="bti-section">
        <div class="bti-section-title">
            <span>🖼️ Ảnh gần đây</span>
            <button class="bti-btn-secondary" onclick="btiPollJobs()">🔄 Làm mới</button>
        </div>

        <div class="bti-job-grid" id="bti-job-grid">
            <?php if ( empty( $recent_jobs ) ) : ?>
                <p style="grid-column:1/-1;text-align:center;padding:30px;color:#9ca3af;font-size:13px;">📭 Chưa tạo ảnh nào. Qua tab Tạo ảnh để bắt đầu!</p>
            <?php else : ?>
                <?php foreach ( $recent_jobs as $job ) : ?>
                <div class="bti-job" data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
                    <?php if ( ! empty( $job['image_url'] ) ) : ?>
                    <img class="bti-job-img" src="<?php echo esc_url( $job['image_url'] ); ?>" alt="" loading="lazy">
                    <?php else : ?>
                    <div class="bti-job-img-placeholder"><?php echo $job['status'] === 'processing' ? '⏳' : '❌'; ?></div>
                    <?php endif; ?>
                    <div class="bti-job-body">
                        <div class="bti-job-prompt"><?php echo esc_html( mb_strimwidth( $job['prompt'], 0, 80, '...' ) ); ?></div>
                        <div class="bti-job-meta">
                            <span class="bti-job-status st-<?php echo esc_attr( $job['status'] ); ?>"><?php echo esc_html( ucfirst( $job['status'] ) ); ?></span>
                            <span><?php echo esc_html( $job['model'] ); ?></span>
                            <span><?php echo esc_html( $job['size'] ); ?></span>
                            <span><?php echo esc_html( wp_date( 'd/m H:i', strtotime( $job['created_at'] ) ) ); ?></span>
                        </div>
                        <?php if ( $job['status'] === 'completed' && ! empty( $job['image_url'] ) ) : ?>
                        <div class="bti-job-actions">
                            <a href="<?php echo esc_url( $job['image_url'] ); ?>" target="_blank">👁️ Xem</a>
                            <a href="<?php echo esc_url( $job['image_url'] ); ?>" download>⬇️ Tải</a>
                            <?php if ( empty( $job['attachment_id'] ) || $job['attachment_id'] == 0 ) : ?>
                            <button onclick="btiUploadMedia(<?php echo esc_attr( $job['id'] ); ?>,'<?php echo esc_url( $job['image_url'] ); ?>')">💾 Media</button>
                            <?php else : ?>
                            <span style="font-size:10px;color:#166534;">✅ Saved</span>
                            <?php endif; ?>
                            <button onclick="btiShareImage('<?php echo esc_url( $job['image_url'] ); ?>')">🔗 Share</button>
                        </div>
                        <?php endif; ?>
                        <?php if ( $job['status'] === 'failed' && ! empty( $job['error_message'] ) ) : ?>
                        <div style="font-size:10px;color:#991b1b;margin-top:4px;"><?php echo esc_html( mb_strimwidth( $job['error_message'], 0, 100, '...' ) ); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ═══════════ TAB 3: CHAT ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'chat' ? 'active' : ''; ?>" id="tab-chat">

    <div class="bti-hero">
        <div class="bti-hero-icon">💬</div>
        <h2>Chat AI — Tạo ảnh</h2>
        <p>Gửi lệnh qua chat để tạo ảnh AI</p>
    </div>

    <div style="padding:12px;">
        <!-- Primary -->
        <div class="bti-cmd primary" data-msg="Tạo ảnh AI từ prompt">
            <div class="bti-cmd-icon">🎨</div>
            <div class="bti-cmd-text">
                <h4>Tạo ảnh AI</h4>
                <p>Mô tả prompt → AI tạo ảnh chuyên nghiệp (FLUX.2/Gemini/GPT-5)</p>
            </div>
        </div>
        <!-- Secondary -->
        <div class="bti-cmd" data-msg="Tạo ảnh sản phẩm cho bán hàng">
            <div class="bti-cmd-icon">📦</div>
            <div class="bti-cmd-text">
                <h4>Ảnh sản phẩm</h4>
                <p>Tạo ảnh sản phẩm nền trắng, lifestyle, studio cho e-commerce</p>
            </div>
        </div>
        <div class="bti-cmd" data-msg="Xem danh sách ảnh đã tạo gần đây">
            <div class="bti-cmd-icon">🖼️</div>
            <div class="bti-cmd-text">
                <h4>Xem ảnh đã tạo</h4>
                <p>Liệt kê các ảnh AI gần đây với link xem và tải</p>
            </div>
        </div>
        <div class="bti-cmd" data-msg="Tạo ảnh chân dung nghệ thuật, phong cách cinematic">
            <div class="bti-cmd-icon">👤</div>
            <div class="bti-cmd-text">
                <h4>Chân dung nghệ thuật</h4>
                <p>Ảnh portrait phong cách cinematic, studio, vintage</p>
            </div>
        </div>
        <div class="bti-cmd" data-msg="Tạo ảnh phong cảnh hoàng hôn bãi biển Việt Nam">
            <div class="bti-cmd-icon">🌄</div>
            <div class="bti-cmd-text">
                <h4>Phong cảnh</h4>
                <p>Chụp bãi biển, núi non, phố cổ, ruộng bậc thang</p>
            </div>
        </div>
        <div class="bti-cmd" data-msg="Tạo ảnh thumbnail YouTube bắt mắt">
            <div class="bti-cmd-icon">📱</div>
            <div class="bti-cmd-text">
                <h4>Social Media</h4>
                <p>Thumbnail YouTube, Instagram Story, Facebook Post</p>
            </div>
        </div>

        <div class="bti-tips">
            <h4 style="font-size:13px;font-weight:700;margin-bottom:2px;">💡 Gợi ý prompt</h4>
            <div class="bti-tip" data-msg="Tạo ảnh cô gái áo dài đi trong rừng tre, phong cách chụp film Kodak Portra">💡 "Cô gái áo dài đi trong rừng tre, phong cách Kodak Portra"</div>
            <div class="bti-tip" data-msg="Tạo ảnh sản phẩm túi xách da trên nền đá cẩm thạch, studio lighting">💡 "Sản phẩm túi xách da trên nền đá cẩm thạch, studio"</div>
            <div class="bti-tip" data-msg="Tạo ảnh thiết kế nội thất phòng khách Scandinavian, tông gỗ ấm">💡 "Nội thất phòng khách Scandinavian, tông gỗ ấm"</div>
            <div class="bti-tip" data-msg="Tạo ảnh game character chiến binh fantasy, concept art chất lượng cao">💡 "Game character chiến binh fantasy, concept art"</div>
            <div class="bti-tip" data-msg="Tạo ảnh flat lay món ăn Việt Nam, food photography chuyên nghiệp">💡 "Flat lay món ăn Việt Nam, food photography"</div>
        </div>
    </div>

</div>

<!-- ═══════════ TAB 4: CÀI ĐẶT ═══════════ -->
<div class="bti-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" id="tab-settings">

    <div class="bti-hero">
        <div class="bti-hero-icon">⚙️</div>
        <h2>Cài đặt</h2>
        <p>API, model mặc định và tùy chỉnh</p>
    </div>

    <div class="bti-card">
        <?php if ( $is_admin ) : ?>
        <div class="bti-settings-group">
            <h4>🔑 API Configuration (Admin only)</h4>
            <div class="bti-settings-row">
                <label>OpenRouter API Key (FLUX.2 / Gemini / Seedream / GPT-5) — hoặc dùng key OpenRouter chung</label>
                <input type="password" id="bti-cfg-api-key" value="<?php echo esc_attr( $cfg_api_key ); ?>" placeholder="sk-or-...">
            </div>
            <div class="bti-settings-row">
                <label>API Endpoint</label>
                <input type="url" id="bti-cfg-endpoint" value="<?php echo esc_attr( $cfg_endpoint ); ?>" placeholder="https://openrouter.ai/api/v1">
            </div>
            <div class="bti-settings-row">
                <label>OpenAI API Key (GPT-Image trực tiếp, tùy chọn)</label>
                <input type="password" id="bti-cfg-openai" value="<?php echo esc_attr( $cfg_openai ); ?>" placeholder="sk-...">
            </div>
        </div>
        <?php endif; ?>

        <div class="bti-settings-group">
            <h4>🎛️ Mặc định tạo ảnh</h4>
            <div class="bti-settings-row">
                <label>Model mặc định</label>
                <select id="bti-cfg-model">
                    <option value="flux-pro" <?php selected( $cfg_model, 'flux-pro' ); ?>>🔥 FLUX.2 Pro (chất lượng cao)</option>
                    <option value="flux-flex" <?php selected( $cfg_model, 'flux-flex' ); ?>>⚡ FLUX.2 Flex (linh hoạt)</option>
                    <option value="flux-max" <?php selected( $cfg_model, 'flux-max' ); ?>>💎 FLUX.2 Max (premium)</option>
                    <option value="flux-klein" <?php selected( $cfg_model, 'flux-klein' ); ?>>⚡ FLUX.2 Klein (nhanh)</option>
                    <option value="gemini-image" <?php selected( $cfg_model, 'gemini-image' ); ?>>✨ Gemini Flash Image</option>
                    <option value="gemini-pro" <?php selected( $cfg_model, 'gemini-pro' ); ?>>✨ Gemini Pro Image</option>
                    <option value="seedream" <?php selected( $cfg_model, 'seedream' ); ?>>🎭 Seedream 4.5</option>
                    <option value="gpt-image" <?php selected( $cfg_model, 'gpt-image' ); ?>>🧠 GPT-5 Image</option>
                    <option value="gpt-image-mini" <?php selected( $cfg_model, 'gpt-image-mini' ); ?>>🧠 GPT-5 Image Mini</option>
                </select>
            </div>
            <div class="bti-settings-row">
                <label>Kích thước mặc định</label>
                <select id="bti-cfg-size">
                    <option value="1024x1024" <?php selected( $cfg_size, '1024x1024' ); ?>>1:1 Vuông (1024×1024)</option>
                    <option value="1024x1536" <?php selected( $cfg_size, '1024x1536' ); ?>>2:3 Dọc (1024×1536)</option>
                    <option value="1536x1024" <?php selected( $cfg_size, '1536x1024' ); ?>>3:2 Ngang (1536×1024)</option>
                    <option value="768x1344" <?php selected( $cfg_size, '768x1344' ); ?>>9:16 Story (768×1344)</option>
                    <option value="1344x768" <?php selected( $cfg_size, '1344x768' ); ?>>16:9 Landscape (1344×768)</option>
                </select>
            </div>
        </div>

        <button class="bti-btn bti-btn-primary" onclick="btiSaveSettings()">💾 Lưu cài đặt</button>
        <div class="bti-status" id="bti-settings-status"></div>

        <div class="bti-settings-info">
            ℹ️ Plugin: BizCity Tool Image v<?php echo esc_html( BZTIMG_VERSION ); ?><br>
            Models: FLUX.2 Pro, Flex, Max, Klein · Gemini Flash/Pro · Seedream 4.5 · GPT-5 Image<br>
            Gateway: OpenRouter<br>
            Ảnh đã tạo: <?php echo esc_html( $stats['total'] ); ?>
        </div>
    </div>

</div>

<!-- ═══════════ BOTTOM NAV ═══════════ -->
<nav class="bti-nav">
    <button class="bti-nav-item <?php echo $active_tab === 'create' ? 'active' : ''; ?>" data-tab="create">
        <span class="bti-nav-icon">🎨</span>
        <span>Tạo ảnh</span>
    </button>
    <button class="bti-nav-item <?php echo $active_tab === 'monitor' ? 'active' : ''; ?>" data-tab="monitor">
        <span class="bti-nav-icon">📊</span>
        <span>Lịch sử</span>
    </button>
    <button class="bti-nav-item <?php echo $active_tab === 'chat' ? 'active' : ''; ?>" data-tab="chat">
        <span class="bti-nav-icon">💬</span>
        <span>Chat</span>
    </button>
    <button class="bti-nav-item <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
        <span class="bti-nav-icon">⚙️</span>
        <span>Cài đặt</span>
    </button>
</nav>

</div><!-- .bti-app -->

<script>
/* ═══════════════════════════════════════════════
   GLOBAL CONFIG
   ═══════════════════════════════════════════════ */
var BTI = {
    ajax: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
    nonce: '<?php echo esc_attr( wp_create_nonce( 'bztimg_nonce' ) ); ?>',
    photoUrl: '',
};

/* ═══════════════════════════════════════════════
   TAB NAVIGATION
   ═══════════════════════════════════════════════ */
document.querySelectorAll('.bti-nav-item').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var tab = this.dataset.tab;
        document.querySelectorAll('.bti-tab').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('.bti-nav-item').forEach(function(b) { b.classList.remove('active'); });
        document.getElementById('tab-' + tab).classList.add('active');
        this.classList.add('active');
        if (tab === 'monitor') btiPollJobs();
    });
});

/* ═══════════════════════════════════════════════
   PHOTO UPLOAD
   ═══════════════════════════════════════════════ */
document.getElementById('bti-photo-input').addEventListener('change', function(e) {
    var file = e.target.files[0];
    if (!file) return;

    // Preview
    var reader = new FileReader();
    reader.onload = function(ev) {
        document.getElementById('bti-photo-img').src = ev.target.result;
        document.getElementById('bti-photo-preview').style.display = 'block';
        document.getElementById('bti-photo-placeholder').style.display = 'none';
    };
    reader.readAsDataURL(file);

    // Upload
    var fd = new FormData();
    fd.append('action', 'bztimg_upload_photo');
    fd.append('nonce', BTI.nonce);
    fd.append('photo', file);

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                BTI.photoUrl = res.data.url;
            }
        });
});

/* ═══════════════════════════════════════════════
   GENERATE IMAGE
   ═══════════════════════════════════════════════ */
function btiGenerate() {
    var prompt = document.getElementById('bti-prompt').value.trim();
    if (!prompt && !BTI.photoUrl) {
        btiShowStatus('bti-create-status', 'Vui lòng nhập mô tả ảnh hoặc upload ảnh tham chiếu.', 'error');
        return;
    }

    var model = document.querySelector('input[name="model"]:checked');
    var size  = document.querySelector('input[name="size"]:checked');
    var style = document.querySelector('input[name="style"]:checked');

    var btn = document.getElementById('bti-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="bti-spinner"></span> Đang tạo ảnh...';
    btiShowStatus('bti-create-status', '⏳ Đang gửi yêu cầu tạo ảnh...', 'loading');

    var fd = new FormData();
    fd.append('action', 'bztimg_generate');
    fd.append('nonce', BTI.nonce);
    fd.append('prompt', prompt);
    fd.append('image_url', BTI.photoUrl || '');
    fd.append('model', model ? model.value : 'flux-pro');
    fd.append('size', size ? size.value : '1024x1024');
    fd.append('style', style ? style.value : 'auto');

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            btn.innerHTML = '🎨 Tạo ảnh';

            if (res.success && res.data) {
                var d = res.data.data || res.data;
                btiShowStatus('bti-create-status', '✅ Tạo ảnh thành công!', 'success');

                // Show result
                var imgUrl = d.image_url || d.url || '';
                if (imgUrl) {
                    document.getElementById('bti-result-img').src = imgUrl;
                    document.getElementById('bti-result-info').innerHTML =
                        '<strong>' + (d.model || '') + '</strong> · ' + (d.size || '') + ' · Job #' + (d.job_id || '');
                    document.getElementById('bti-result-actions').innerHTML =
                        '<a href="' + imgUrl + '" target="_blank" class="bti-btn-secondary">👁️ Xem</a>' +
                        '<a href="' + imgUrl + '" download class="bti-btn-secondary">⬇️ Tải về</a>' +
                        '<button class="bti-btn-secondary" onclick="btiShareImage(\'' + imgUrl + '\')">🔗 Share</button>';
                    document.getElementById('bti-result').classList.add('show');
                }
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : 'Lỗi tạo ảnh.';
                btiShowStatus('bti-create-status', msg, 'error');
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = '🎨 Tạo ảnh';
            btiShowStatus('bti-create-status', 'Lỗi kết nối: ' + err.message, 'error');
        });
}

/* ═══════════════════════════════════════════════
   POLL JOBS (Monitor tab)
   ═══════════════════════════════════════════════ */
function btiPollJobs() {
    var fd = new FormData();
    fd.append('action', 'bztimg_poll_jobs');
    fd.append('nonce', BTI.nonce);

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) return;
            var d = res.data;

            // Update stats
            if (d.stats) {
                var el;
                el = document.getElementById('ms-total');  if(el) el.textContent = d.stats.total;
                el = document.getElementById('ms-done');   if(el) el.textContent = d.stats.done;
                el = document.getElementById('ms-active'); if(el) el.textContent = d.stats.active;
            }

            // Render jobs
            var grid = document.getElementById('bti-job-grid');
            if (!d.jobs || d.jobs.length === 0) {
                grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;padding:30px;color:#9ca3af;font-size:13px;">📭 Chưa tạo ảnh nào.</p>';
                return;
            }

            var html = '';
            d.jobs.forEach(function(job) {
                var hasImg = job.status === 'completed' && job.image_url;
                html += '<div class="bti-job" data-job-id="' + job.id + '">';
                if (hasImg) {
                    html += '<img class="bti-job-img" src="' + job.image_url + '" alt="" loading="lazy">';
                } else {
                    html += '<div class="bti-job-img-placeholder">' + (job.status === 'processing' ? '⏳' : '❌') + '</div>';
                }
                html += '<div class="bti-job-body">';
                html += '<div class="bti-job-prompt">' + escHtml(job.prompt || '').substring(0, 80) + '</div>';
                html += '<div class="bti-job-meta">';
                html += '<span class="bti-job-status st-' + job.status + '">' + ucfirst(job.status) + '</span>';
                html += '<span>' + escHtml(job.model) + '</span>';
                html += '<span>' + escHtml(job.size) + '</span>';
                html += '</div>';
                if (hasImg) {
                    html += '<div class="bti-job-actions">';
                    html += '<a href="' + job.image_url + '" target="_blank">👁️ Xem</a>';
                    html += '<a href="' + job.image_url + '" download>⬇️ Tải</a>';
                    if (!job.attachment_id || job.attachment_id == 0) {
                        html += '<button onclick="btiUploadMedia(' + job.id + ',\'' + job.image_url + '\')">💾 Media</button>';
                    } else {
                        html += '<span style="font-size:10px;color:#166534;">✅ Saved</span>';
                    }
                    html += '<button onclick="btiShareImage(\'' + job.image_url + '\')">🔗 Share</button>';
                    html += '</div>';
                }
                if (job.status === 'failed' && job.error_message) {
                    html += '<div style="font-size:10px;color:#991b1b;margin-top:4px;">' + escHtml(job.error_message).substring(0, 100) + '</div>';
                }
                html += '</div></div>';
            });
            grid.innerHTML = html;
        });
}

/* ═══════════════════════════════════════════════
   UPLOAD TO MEDIA
   ═══════════════════════════════════════════════ */
function btiUploadMedia(jobId, imageUrl) {
    var fd = new FormData();
    fd.append('action', 'bztimg_upload_to_media');
    fd.append('nonce', BTI.nonce);
    fd.append('job_id', jobId);
    fd.append('image_url', imageUrl);

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                btiPollJobs(); // Refresh
            } else {
                alert(res.data && res.data.message ? res.data.message : 'Lỗi upload.');
            }
        });
}

/* ═══════════════════════════════════════════════
   SHARE
   ═══════════════════════════════════════════════ */
function btiShareImage(url) {
    if (navigator.share) {
        navigator.share({ title: 'AI Generated Image', url: url });
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            alert('Đã copy link ảnh!');
        });
    } else {
        prompt('Copy link:', url);
    }
}

/* ═══════════════════════════════════════════════
   SAVE SETTINGS
   ═══════════════════════════════════════════════ */
function btiSaveSettings() {
    var fd = new FormData();
    fd.append('action', 'bztimg_save_settings');
    fd.append('nonce', BTI.nonce);
    fd.append('default_model', document.getElementById('bti-cfg-model').value);
    fd.append('default_size', document.getElementById('bti-cfg-size').value);

    var apiKeyEl = document.getElementById('bti-cfg-api-key');
    if (apiKeyEl) fd.append('api_key', apiKeyEl.value);
    var endpointEl = document.getElementById('bti-cfg-endpoint');
    if (endpointEl) fd.append('api_endpoint', endpointEl.value);
    var openaiEl = document.getElementById('bti-cfg-openai');
    if (openaiEl) fd.append('openai_key', openaiEl.value);

    fetch(BTI.ajax, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                btiShowStatus('bti-settings-status', '✅ Đã lưu cài đặt!', 'success');
            } else {
                btiShowStatus('bti-settings-status', '❌ Lỗi lưu cài đặt.', 'error');
            }
        });
}

/* ═══════════════════════════════════════════════
   PROMPT LIBRARY
   ═══════════════════════════════════════════════ */
document.querySelectorAll('.bti-lib-cat').forEach(function(cat) {
    cat.addEventListener('click', function() {
        document.querySelectorAll('.bti-lib-cat').forEach(function(c) { c.classList.remove('active'); });
        this.classList.add('active');
        document.querySelectorAll('.bti-lib-grid').forEach(function(g) { g.style.display = 'none'; });
        var grid = document.getElementById('lib-' + this.dataset.cat);
        if (grid) grid.style.display = 'grid';
    });
});

document.querySelectorAll('.bti-lib-item').forEach(function(item) {
    item.addEventListener('click', function() {
        document.getElementById('bti-prompt').value = this.dataset.prompt;
        document.getElementById('bti-prompt').focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});

/* ═══════════════════════════════════════════════
   POSTMESSAGE TO PARENT (Chat tab)
   ═══════════════════════════════════════════════ */
document.querySelectorAll('[data-msg]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        var msg = this.dataset.msg;
        if (!msg) return;

        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'bizcity_agent_command',
                message: msg,
                source: 'tool-image'
            }, '*');
        } else {
            // Standalone — copy to clipboard
            if (navigator.clipboard) {
                navigator.clipboard.writeText(msg);
                alert('Đã copy: ' + msg);
            }
        }
    });
});

/* ═══════════════════════════════════════════════
   HELPERS
   ═══════════════════════════════════════════════ */
function btiShowStatus(id, msg, type) {
    var el = document.getElementById(id);
    if (!el) return;
    el.className = 'bti-status ' + type;
    el.textContent = msg;
    if (type === 'success' || type === 'error') {
        setTimeout(function() { el.className = 'bti-status'; }, 5000);
    }
}

function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s || ''));
    return d.innerHTML;
}

function ucfirst(s) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
}
</script>
</body>
</html>
