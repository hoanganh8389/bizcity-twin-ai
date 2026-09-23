<?php
/**
 * Bizcity Twin AI — Admin Widget Template (Modern Design)
 * Giao diện Widget quản trị / Admin Chat Widget Template
 *
 * Split layout: Chat panel + Milestones/Status panel.
 * Dark theme inspired by F2AI / Chation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

defined('ABSPATH') or die('OOPS...');

$widget = BizCity_WebChat_Widget::instance();
$config = $widget->get_config();

// Get brain levels for status panel
$brain_levels = [];
if (class_exists('BizCity_Brain_Levels')) {
    $brain_levels = BizCity_Brain_Levels::get_all_levels();
}
$overall = $brain_levels['overall'] ?? 0;

// Milestones data
$milestones = [
    ['key' => 'knowledge',  'icon' => '📚', 'label' => 'Knowledge Base',  'level' => $brain_levels['knowledge'] ?? 0],
    ['key' => 'workflow',   'icon' => '⚡', 'label' => 'Workflows',       'level' => $brain_levels['automation'] ?? 0],
    ['key' => 'triggers',   'icon' => '🔗', 'label' => 'Triggers',        'level' => $brain_levels['triggers'] ?? 0],
    ['key' => 'llm',        'icon' => '🧠', 'label' => 'LLM Model',       'level' => $brain_levels['llm_core'] ?? 0],
    ['key' => 'style',      'icon' => '🎭', 'label' => 'Personal Style',  'level' => $brain_levels['personal_style'] ?? 0],
    ['key' => 'memory',     'icon' => '💾', 'label' => 'Memory',          'level' => $brain_levels['memory'] ?? 0],
];

// Avatar
$avatar_url = '';
if (function_exists('bizcity_get_agent_avatar_url')) {
    $avatar_url = '';
}
?>

<style>
/* ===== FLOATING BUTTON ===== */
#bca-float-btn {
    position: fixed;
    right: 32px;
    bottom: 48px;
    z-index: 99999;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    font-size: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 24px rgba(99,102,241,0.45);
    transition: all 0.3s cubic-bezier(.4,0,.2,1);
}
#bca-float-btn:hover { transform: scale(1.08); box-shadow: 0 8px 32px rgba(99,102,241,0.6); }
#bca-float-btn img { width: 36px; height: 36px; border-radius: 50%; }
#bca-float-btn .bca-badge {
    position: absolute; top: -2px; right: -2px;
    width: 18px; height: 18px; border-radius: 50%;
    background: #ef4444; color: #fff; font-size: 10px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700;
}

/* ===== MAIN CONTAINER ===== */
#bca-container {
    display: none;
    position: fixed;
    right: 32px;
    bottom: 104px;
    z-index: 99999;
    width: 720px;
    max-width: calc(100vw - 64px);
    max-height: min(680px, calc(100vh - 140px));
    border-radius: 20px;
    overflow: hidden;
    background: #0f1019;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.06);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #fff;
    animation: bca-slide-up 0.35s cubic-bezier(.4,0,.2,1);
}
#bca-container.active { display: flex; flex-direction: column; }

@keyframes bca-slide-up {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ===== HEADER ===== */
.bca-header {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    padding: 14px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.bca-header-left { display: flex; align-items: center; gap: 12px; }
.bca-header-avatar { width: 36px; height: 36px; border-radius: 50%; border: 2px solid rgba(139,92,246,0.5); }
.bca-header-info h3 { margin: 0; font-size: 15px; font-weight: 600; color: #fff; }
.bca-header-info span { font-size: 11px; color: rgba(255,255,255,0.5); }
.bca-header-actions { display: flex; gap: 6px; }
.bca-header-actions button {
    width: 28px; height: 28px; border-radius: 50%;
    background: rgba(255,255,255,0.08); border: none;
    color: rgba(255,255,255,0.6); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; transition: all 0.2s;
}
.bca-header-actions button:hover { background: rgba(255,255,255,0.15); color: #fff; }

/* ===== TAB BAR ===== */
.bca-tabs {
    display: flex;
    background: rgba(255,255,255,0.03);
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.bca-tab {
    flex: 1;
    padding: 10px;
    font-size: 12px;
    font-weight: 500;
    color: rgba(255,255,255,0.4);
    background: none;
    border: none;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
    text-align: center;
}
.bca-tab:hover { color: rgba(255,255,255,0.7); }
.bca-tab.active {
    color: #8b5cf6;
    border-bottom-color: #8b5cf6;
}

/* ===== BODY ===== */
.bca-body { flex: 1; display: flex; overflow: hidden; min-height: 380px; }

/* ===== CHAT PANEL ===== */
.bca-panel { display: none; flex-direction: column; width: 100%; }
.bca-panel.active { display: flex; }

.bca-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: #0f1019;
}
.bca-messages::-webkit-scrollbar { width: 4px; }
.bca-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

.bca-msg { display: flex; margin-bottom: 12px; animation: bca-msg-in 0.3s ease; gap: 8px; }
.bca-msg.user { flex-direction: row-reverse; }

@keyframes bca-msg-in { from { opacity: 0; transform: translateY(6px); } }

.bca-msg-avatar {
    width: 28px; height: 28px; border-radius: 50%;
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
}
.bca-msg.bot .bca-msg-avatar { background: rgba(139,92,246,0.2); color: #a78bfa; }
.bca-msg.user .bca-msg-avatar { background: rgba(99,102,241,0.2); color: #818cf8; }

.bca-msg-bubble {
    max-width: 280px;
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 13px;
    line-height: 1.6;
    word-break: break-word;
}
.bca-msg.bot .bca-msg-bubble {
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.85);
    border-bottom-left-radius: 4px;
}

/* Markdown rendered content */
.bca-md { white-space: normal; }
.bca-md h2, .bca-md h3, .bca-md h4 { line-height: 1.3; }
.bca-md h2:first-child, .bca-md h3:first-child, .bca-md h4:first-child { margin-top: 0; }
.bca-md strong { font-weight: 700; }
.bca-md em { font-style: italic; }
.bca-md ul, .bca-md ol { line-height: 1.6; }
.bca-md li { margin-bottom: 2px; }
.bca-md pre { white-space: pre-wrap; word-break: break-word; }
.bca-md pre code { background: none; padding: 0; }
.bca-md code { font-family: 'SFMono-Regular', Consolas, monospace; }
.bca-md a { color: #a78bfa; text-decoration: underline; }
.bca-md img { max-width: 100%; border-radius: 8px; }
.bca-md table { border-collapse: collapse; width: 100%; margin: 4px 0; font-size: 12px; }
.bca-md th, .bca-md td { border: 1px solid rgba(255,255,255,0.1); padding: 4px 8px; text-align: left; }
.bca-md th { background: rgba(255,255,255,0.05); font-weight: 600; }
.bca-msg.user .bca-msg-bubble {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    border-bottom-right-radius: 4px;
}

.bca-input-area {
    padding: 12px 16px;
    background: rgba(255,255,255,0.03);
    border-top: 1px solid rgba(255,255,255,0.06);
    display: flex;
    gap: 8px;
    align-items: flex-end;
}
.bca-input {
    flex: 1;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 18px;
    padding: 10px 16px;
    font-size: 13px;
    color: #fff;
    outline: none;
    resize: none;
    min-height: 18px;
    max-height: 90px;
    transition: border-color 0.2s;
}
.bca-input::placeholder { color: rgba(255,255,255,0.3); }
.bca-input:focus { border-color: rgba(139,92,246,0.5); }
.bca-send-btn {
    width: 34px; height: 34px; border-radius: 50%;
    background: #6366f1; color: #fff; border: none;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; transition: all 0.2s;
}
.bca-send-btn:hover { background: #8b5cf6; }
.bca-send-btn:disabled { background: rgba(255,255,255,0.1); cursor: not-allowed; }

/* ===== IMAGE ATTACH ===== */
.bca-attach-btn {
    width: 34px; height: 34px; border-radius: 50%;
    background: rgba(255,255,255,0.06); border: none;
    color: rgba(255,255,255,0.4); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; transition: 0.2s; flex-shrink: 0;
}
.bca-attach-btn:hover { background: rgba(255,255,255,0.12); color: #a78bfa; }
.bca-img-preview {
    padding: 6px 16px;
    background: rgba(255,255,255,0.03);
    border-top: 1px solid rgba(255,255,255,0.06);
    display: flex; gap: 6px; flex-wrap: wrap;
}
.bca-img-thumb {
    position: relative; width: 48px; height: 48px;
    border-radius: 6px; overflow: hidden;
    border: 1px solid rgba(255,255,255,0.1);
}
.bca-img-thumb img { width: 100%; height: 100%; object-fit: cover; }
.bca-img-thumb .bca-img-rm {
    position: absolute; top: -3px; right: -3px;
    width: 16px; height: 16px; border-radius: 50%;
    background: #ef4444; color: #fff; border: none;
    font-size: 9px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}
.bca-vision-hint {
    font-size: 10px; color: rgba(139,92,246,0.6);
    padding: 2px 16px 0;
}
.bca-msg-images { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 6px; }
.bca-msg-images img {
    max-width: 120px; max-height: 90px;
    border-radius: 6px; cursor: pointer;
    border: 1px solid rgba(255,255,255,0.08);
}

/* ===== CHARACTER SELECT IN TAB ===== */
.bca-char-select {
    flex: 1;
    padding: 8px 10px;
    font-size: 12px;
    font-weight: 500;
    color: #8b5cf6;
    background: transparent;
    border: none;
    border-bottom: 2px solid #8b5cf6;
    cursor: pointer;
    text-align: center;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b5cf6' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    padding-right: 24px;
}
.bca-char-select option {
    background: #1a1a2e;
    color: #fff;
    padding: 6px;
}

.bca-typing {
    color: rgba(139,92,246,0.7);
    font-size: 12px;
    padding: 6px 16px;
    text-align: center;
}

/* ===== MILESTONES PANEL ===== */
.bca-milestones {
    padding: 16px;
    overflow-y: auto;
}
.bca-milestones::-webkit-scrollbar { width: 4px; }
.bca-milestones::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

.bca-progress-ring {
    display: flex;
    justify-content: center;
    margin-bottom: 20px;
}
.bca-ring-wrap {
    position: relative;
    width: 100px;
    height: 100px;
}
.bca-ring-wrap svg { width: 100px; height: 100px; transform: rotate(-90deg); }
.bca-ring-bg { fill: none; stroke: rgba(255,255,255,0.06); stroke-width: 8; }
.bca-ring-fg {
    fill: none;
    stroke: url(#bca-ring-grad);
    stroke-width: 8;
    stroke-linecap: round;
    transition: stroke-dashoffset 1s ease;
}
.bca-ring-text {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
}
.bca-ring-pct { font-size: 22px; font-weight: 700; color: #fff; display: block; }
.bca-ring-label { font-size: 10px; color: rgba(255,255,255,0.4); }

.bca-milestone-list { display: flex; flex-direction: column; gap: 8px; }
.bca-milestone-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.05);
    border-radius: 12px;
    transition: all 0.2s;
    cursor: pointer;
    text-decoration: none;
    color: #fff;
}
.bca-milestone-item:hover {
    background: rgba(255,255,255,0.06);
    border-color: rgba(255,255,255,0.1);
}
.bca-ms-icon { font-size: 18px; flex-shrink: 0; }
.bca-ms-info { flex: 1; }
.bca-ms-name { font-size: 12px; font-weight: 500; color: rgba(255,255,255,0.8); }
.bca-ms-bar {
    height: 4px;
    background: rgba(255,255,255,0.06);
    border-radius: 2px;
    margin-top: 6px;
    overflow: hidden;
}
.bca-ms-bar-fill {
    height: 100%;
    border-radius: 2px;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
    transition: width 0.8s ease;
}
.bca-ms-pct {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255,255,255,0.4);
    flex-shrink: 0;
    min-width: 32px;
    text-align: right;
}

/* ===== INBOX PANEL ===== */
.bca-inbox { padding: 16px; overflow-y: auto; }
.bca-inbox-empty {
    text-align: center;
    padding: 40px 20px;
    color: rgba(255,255,255,0.3);
    font-size: 13px;
}
.bca-inbox-empty span { font-size: 36px; display: block; margin-bottom: 12px; }

/* ===== TWIN CORE SETTING SLIDER ===== */
.bca-kci-bar {
    padding: 6px 12px 4px;
    background: rgba(255,255,255,0.03);
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.bca-kci-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.bca-kci-label {
    font-size: 11px;
    color: rgba(255,255,255,0.5);
    white-space: nowrap;
    min-width: 42px;
}
.bca-kci-range {
    flex: 1;
    -webkit-appearance: none;
    appearance: none;
    height: 4px;
    border-radius: 2px;
    background: linear-gradient(90deg, #6366f1 0%, #a855f7 50%, #f59e0b 100%);
    outline: none;
    cursor: pointer;
}
.bca-kci-range::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,0.3);
    cursor: pointer;
}
.bca-kci-range::-moz-range-thumb {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,0.3);
    cursor: pointer;
    border: none;
}
.bca-kci-presets {
    display: flex;
    gap: 4px;
    margin-top: 4px;
    flex-wrap: wrap;
}
.bca-kci-preset, .bca-kci-nuoi {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.04);
    color: rgba(255,255,255,0.5);
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
}
.bca-kci-preset:hover, .bca-kci-nuoi:hover {
    background: rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.8);
}
.bca-kci-preset.active {
    background: rgba(99,102,241,0.2);
    border-color: rgba(99,102,241,0.4);
    color: #a5b4fc;
}
.bca-kci-nuoi {
    margin-left: auto;
    border-color: rgba(34,197,94,0.3);
    color: rgba(34,197,94,0.7);
}
.bca-kci-nuoi:hover {
    background: rgba(34,197,94,0.15);
    color: #4ade80;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    /* Ẩn hoàn toàn admin widget trên mobile */
    #bca-float-btn { display: none !important; }
    #bca-container { display: none !important; }
}
</style>

<!-- Float Button -->
<button id="bca-float-btn" title="Chat với <?php echo esc_attr($config['bot_name']); ?>">
    <img src="<?php echo esc_url( content_url('mu-plugins/bizcity-brain-level/assets/icon/Bell.png') ); ?>" alt="Bot">
</button>

<!-- Main Container -->
<div id="bca-container">
    <!-- Header -->
    <div class="bca-header">
        <div class="bca-header-left">
            <?php if ($avatar_url): ?>
                <img src="<?php echo esc_url($avatar_url); ?>" class="bca-header-avatar" alt="">
            <?php endif; ?>
            <div class="bca-header-info">
                <h3><?php echo esc_html($config['bot_name'] ?: 'AI Agent'); ?></h3>
                <span>Online · Sẵn sàng hỗ trợ</span>
            </div>
        </div>
        <div class="bca-header-actions">
            <a id="bca-expand" href="<?php echo esc_url(admin_url('admin.php?page=bizcity-knowledge-chat')); ?>" title="Mở rộng" style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,0.08);border:none;color:rgba(255,255,255,0.6);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;transition:all 0.2s;text-decoration:none;">⛶</a>
            <button id="bca-minimize" title="Thu nhỏ">─</button>
            <button id="bca-close" title="Đóng">✕</button>
        </div>
    </div>

    <!-- Tab Bar -->
    <div class="bca-tabs">
        <select class="bca-char-select" id="bca-char-select">
            <option value="0">💬 Chat (Default)</option>
        </select>
        <button class="bca-tab" data-panel="milestones">📊 Milestones</button>
        <button class="bca-tab" data-panel="inbox">📥 Inbox</button>
    </div>

    <!-- Twin Core Setting (Execution Intent) -->
    <div class="bca-kci-bar" id="bca-kci-bar">
        <div class="bca-kci-row">
            <span class="bca-kci-label">🧠 <span id="bca-kci-val">80</span>%</span>
            <input type="range" id="bca-kci-slider" min="0" max="100" step="10" value="80" class="bca-kci-range">
            <span class="bca-kci-label">⚡ <span id="bca-kci-exec">20</span>%</span>
        </div>
        <div class="bca-kci-status" id="bca-kci-status" style="font-size:10px;color:rgba(255,255,255,0.4);margin:3px 0 2px;text-align:center;">Priority: Knowledge: 80%, Execution: 20%</div>
        <div class="bca-kci-presets">
            <button class="bca-kci-preset" data-val="100" title="100% Kiến thức">📚 Học</button>
            <button class="bca-kci-preset active" data-val="80" title="80% Kiến thức, 20% Thực thi">🧠 Mặc định</button>
            <button class="bca-kci-preset" data-val="50" title="Cân bằng">⚖️ Cân bằng</button>
            <button class="bca-kci-preset" data-val="20" title="80% Thực thi">🚀 Thực thi</button>
            <button class="bca-kci-nuoi" id="bca-kci-nuoi" title="Teach AI (Nuôi dậy AI) — mở Notebook để training">🌱 Teach AI</button>
        </div>
    </div>

    <!-- Body -->
    <div class="bca-body">
        <!-- Chat Panel -->
        <div class="bca-panel active" data-panel="chat">
            <div class="bca-messages" id="bca-messages"></div>
            <div class="bca-img-preview" id="bca-img-preview" style="display:none;"></div>
            <div class="bca-input-area">
                <button type="button" class="bca-attach-btn" id="bca-attach" title="Đính kèm hình ảnh">📎</button>
                <input type="file" id="bca-file-input" accept="image/*" multiple style="display:none;">
                <textarea id="bca-input" class="bca-input" placeholder="Nhập lệnh hoặc câu hỏi..." rows="1"></textarea>
                <button id="bca-send" class="bca-send-btn" disabled>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                </button>
            </div>
            <div class="bca-vision-hint" id="bca-vision-hint" style="display:none;">👁 Vision model sẽ phân tích hình ảnh</div>
        </div>

        <!-- Milestones Panel -->
        <div class="bca-panel" data-panel="milestones">
            <div class="bca-milestones">
                <!-- Progress Ring -->
                <div class="bca-progress-ring">
                    <div class="bca-ring-wrap">
                        <svg viewBox="0 0 120 120">
                            <defs>
                                <linearGradient id="bca-ring-grad" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" stop-color="#6366f1"/>
                                    <stop offset="100%" stop-color="#ec4899"/>
                                </linearGradient>
                            </defs>
                            <circle class="bca-ring-bg" cx="60" cy="60" r="48"/>
                            <circle class="bca-ring-fg" cx="60" cy="60" r="48"
                                stroke-dasharray="301.6"
                                stroke-dashoffset="<?php echo 301.6 - (301.6 * $overall / 100); ?>"/>
                        </svg>
                        <div class="bca-ring-text">
                            <span class="bca-ring-pct"><?php echo (int)$overall; ?>%</span>
                            <span class="bca-ring-label">Hoàn thành</span>
                        </div>
                    </div>
                </div>

                <!-- Milestone List -->
                <div class="bca-milestone-list">
                    <?php foreach ($milestones as $ms): ?>
                    <div class="bca-milestone-item" data-key="<?php echo esc_attr($ms['key']); ?>">
                        <span class="bca-ms-icon"><?php echo $ms['icon']; ?></span>
                        <div class="bca-ms-info">
                            <div class="bca-ms-name"><?php echo esc_html($ms['label']); ?></div>
                            <div class="bca-ms-bar">
                                <div class="bca-ms-bar-fill" style="width: <?php echo (int)$ms['level']; ?>%"></div>
                            </div>
                        </div>
                        <span class="bca-ms-pct"><?php echo (int)$ms['level']; ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Inbox Panel -->
        <div class="bca-panel" data-panel="inbox">
            <div class="bca-inbox">
                <div class="bca-inbox-empty">
                    <span>📭</span>
                    Chưa có tin nhắn mới.<br>
                    Hệ thống sẽ thông báo khi có cập nhật.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    var $btn = $('#bca-float-btn');
    var $container = $('#bca-container');
    var $messages = $('#bca-messages');
    var $input = $('#bca-input');
    var $send = $('#bca-send');
    var pendingImages = [];

    /* ── Use unified admin chat vars from knowledge plugin ── */
    var vars = typeof bizcity_admin_chat_vars !== 'undefined' ? bizcity_admin_chat_vars : {
        ajaxurl: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('bizcity_admin_chat'); ?>',
        session_id: 'adminchat_<?php echo get_current_blog_id() . '_' . get_current_user_id(); ?>',
        character_id: 0,
        characters: []
    };

    /* ── Populate character selector ── */
    var $charSelect = $('#bca-char-select');
    if (vars.characters && vars.characters.length) {
        $charSelect.empty();
        vars.characters.forEach(function(ch) {
            var sel = (ch.id == vars.character_id) ? ' selected' : '';
            $charSelect.append('<option value="' + ch.id + '"' + sel + '>💬 ' + escHtml(ch.name) + '</option>');
        });
    }

    // Toggle
    $btn.on('click', function() {
        if ($container.hasClass('active')) {
            $container.removeClass('active');
        } else {
            $container.addClass('active');
            showChatPanel();
            if (!$messages.children().length) loadHistory();
            $input.focus();
        }
    });

    $('#bca-close, #bca-minimize').on('click', function() {
        $container.removeClass('active');
    });

    // Character select = switch to chat panel + reload
    $charSelect.on('change', function() {
        vars.character_id = parseInt($(this).val()) || 0;
        showChatPanel();
        $messages.empty();
        loadHistory();
    });

    // Tabs (milestones, inbox)
    $('.bca-tab').on('click', function() {
        var panel = $(this).data('panel');
        $charSelect.removeClass('active');
        $('.bca-tab').removeClass('active');
        $(this).addClass('active');
        $('.bca-panel').removeClass('active');
        $('.bca-panel[data-panel="' + panel + '"]').addClass('active');
    });

    function showChatPanel() {
        $charSelect.addClass('active');
        $('.bca-tab').removeClass('active');
        $('.bca-panel').removeClass('active');
        $('.bca-panel[data-panel="chat"]').addClass('active');
    }

    // Click outside
    $(document).on('click', function(e) {
        if ($container.hasClass('active') && !$(e.target).closest('#bca-container, #bca-float-btn').length) {
            $container.removeClass('active');
        }
    });

    /* ── Image attach ── */
    $('#bca-attach').on('click', function() { $('#bca-file-input').click(); });
    $('#bca-file-input').on('change', function(e) { handleImages(e.target.files); $(this).val(''); });

    function handleImages(files) {
        if (!files) return;
        Array.from(files).forEach(function(f) {
            if (!f.type.startsWith('image/') || pendingImages.length >= 5) return;
            var reader = new FileReader();
            reader.onload = function(e) {
                pendingImages.push({ data: e.target.result, name: f.name });
                renderPreviews();
                updateBtn();
            };
            reader.readAsDataURL(f);
        });
    }

    function renderPreviews() {
        var $p = $('#bca-img-preview').empty();
        if (!pendingImages.length) { $p.hide(); $('#bca-vision-hint').hide(); return; }
        $p.show(); $('#bca-vision-hint').show();
        pendingImages.forEach(function(img, i) {
            $p.append(
                '<div class="bca-img-thumb">' +
                '  <img src="' + img.data + '" alt="">' +
                '  <button class="bca-img-rm" data-idx="' + i + '">✕</button>' +
                '</div>'
            );
        });
        $p.find('.bca-img-rm').on('click', function() {
            pendingImages.splice($(this).data('idx'), 1);
            renderPreviews(); updateBtn();
        });
    }

    function clearImages() { pendingImages = []; renderPreviews(); }

    // Load history from unified admin chat endpoint
    function loadHistory() {
        $.post(vars.ajaxurl, {
            action: 'bizcity_chat_history',
            platform_type: 'ADMINCHAT',
            session_id: vars.session_id,
            character_id: vars.character_id || 0,
            nonce: vars.nonce
        }, function(res) {
            if (res.success && Array.isArray(res.data)) {
                res.data.forEach(function(msg) {
                    var imgs = msg.images || [];
                    appendMsg(msg.msg || msg.message_text, msg.from || msg.message_from, imgs);
                });
                scrollBottom();
            }
        });
    }

    // Send via SSE streaming (fallback to regular AJAX)
    function sendMsg() {
        var msg = $input.val().trim();
        if (!msg && !pendingImages.length) return;
        $input.val('').css('height', 'auto');
        $send.prop('disabled', true);

        var imgs = pendingImages.map(function(i) { return i.data; });
        appendMsg(escHtml(msg || '📷 Hình ảnh'), 'user', imgs);
        clearImages();

        // Create bot bubble for streaming
        var bubbleId = 'b-' + Math.random().toString(36).substr(2, 7);
        $messages.append(
            '<div class="bca-msg bot">' +
            '  <div class="bca-msg-avatar">🤖</div>' +
            '  <div class="bca-msg-bubble bca-md" id="' + bubbleId + '"><span class="bca-typing-dots">đang xử lý...</span></div>' +
            '</div>'
        );
        scrollBottom();

        // Build FormData for SSE stream
        var formData = new FormData();
        formData.append('action', 'bizcity_chat_stream');
        formData.append('message', msg);
        formData.append('character_id', vars.character_id || 0);
        formData.append('session_id', vars.session_id || '');
        formData.append('platform_type', 'ADMINCHAT');
        formData.append('_wpnonce', vars.nonce);
        if (imgs.length) {
            formData.append('images', JSON.stringify(imgs));
        }

        var fullText = '';
        var $bubble = $('#' + bubbleId);

        fetch(vars.ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(response) {
            if (!response.ok || !response.body) throw new Error('No stream');
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            function processStream() {
                return reader.read().then(function(result) {
                    if (result.done) {
                        if (fullText) $bubble.html(formatMsg(fullText));
                        scrollBottom();
                        updateBtn();
                        return;
                    }
                    buffer += decoder.decode(result.value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        if (line.startsWith('event:')) {
                            var evType = line.substring(6).trim();
                            if (evType === 'error') continue;
                            // Handle status event — show thinking text in bubble
                            if (evType === 'status' && i + 1 < lines.length) {
                                var nextLine = lines[i + 1].trim();
                                if (nextLine.startsWith('data: ')) {
                                    try {
                                        var statusData = JSON.parse(nextLine.substring(6));
                                        if (statusData.text) {
                                            $bubble.html('<span style="font-size:13px;opacity:.85">' + statusData.text + '</span>');
                                            scrollBottom();
                                        }
                                    } catch(e) {}
                                    i++;
                                }
                            }
                            continue;
                        }
                        if (line.indexOf('data: ') !== 0) continue;

                        try {
                            var data = JSON.parse(line.substring(6));
                        } catch(e) { continue; }

                        if (data.delta) {
                            fullText = data.full || (fullText + data.delta);
                            $bubble.html(formatMsg(fullText));
                            scrollBottom();
                        }
                        if (data.message && !data.delta) {
                            fullText = data.message;
                            $bubble.html(formatMsg(fullText));
                            scrollBottom();
                        }
                    }
                    return processStream();
                });
            }
            return processStream();
        })
        .catch(function() {
            // Fallback: regular AJAX
            $bubble.html('<span class="bca-typing-dots">đang xử lý...</span>');
            fullText = '';
            $.ajax({
                url: vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_chat_send',
                    platform_type: 'ADMINCHAT',
                    message: msg,
                    character_id: vars.character_id || 0,
                    session_id: vars.session_id || '',
                    nonce: vars.nonce,
                    images: imgs.length ? JSON.stringify(imgs) : ''
                },
                success: function(res) {
                    if (res.success && res.data && res.data.message) {
                        $bubble.html(formatMsg(res.data.message));
                    } else {
                        $bubble.html('Xin lỗi, có lỗi xảy ra.');
                    }
                    scrollBottom();
                },
                error: function() {
                    $bubble.html('Không thể kết nối server.');
                    scrollBottom();
                }
            });
        });
    }

    function appendMsg(msg, from, imgs) {
        var av = from === 'user' ? '👤' : '🤖';
        var imgHtml = '';
        if (imgs && imgs.length) {
            imgHtml = '<div class="bca-msg-images">';
            imgs.forEach(function(u) { imgHtml += '<img src="' + u + '" alt="">'; });
            imgHtml += '</div>';
        }
        var rendered = (from === 'bot') ? formatMsg(msg) : msg;
        $messages.append(
            '<div class="bca-msg ' + from + '">' +
            '  <div class="bca-msg-avatar">' + av + '</div>' +
            '  <div>' + imgHtml + '<div class="bca-msg-bubble bca-md">' + rendered + '</div></div>' +
            '</div>'
        );
        scrollBottom();
    }

    function typeMsg(msg, from) {
        var av = '🤖';
        var id = 'b-' + Math.random().toString(36).substr(2, 7);
        $messages.append(
            '<div class="bca-msg ' + from + '">' +
            '  <div class="bca-msg-avatar">' + av + '</div>' +
            '  <div class="bca-msg-bubble bca-md" id="' + id + '"></div>' +
            '</div>'
        );
        var formatted = formatMsg(msg);
        var isHtml = /<\/?[a-z][\s\S]*>/i.test(formatted);
        var plain = isHtml ? $('<div>').html(formatted).text() : msg;
        var i = 0, $el = $('#' + id);
        var t = setInterval(function() {
            if (i <= plain.length) {
                $el.text(plain.substring(0, i++));
                scrollBottom();
            } else {
                $el.html(formatted);
                clearInterval(t);
            }
        }, 12);
    }

    function updateBtn() { $send.prop('disabled', !$input.val().trim() && !pendingImages.length); }
    function scrollBottom() { $messages.scrollTop($messages[0].scrollHeight); }
    function escHtml(t) { return $('<div>').text(t).html(); }

    /**
     * Convert markdown-like text to HTML for bot messages.
     */
    function formatMsg(text) {
        if (!text) return '';
        // If already contains HTML tags, return as-is
        if (/<\/?(?:div|p|br|h[1-6]|ul|ol|li|strong|em|table|tr|td|th|blockquote|pre|code|span|a|img)[\s>]/i.test(text)) {
            return text;
        }
        var t = escHtml(text);
        // Code blocks
        t = t.replace(/```([\s\S]*?)```/g, '<pre style="background:#1e1e2e;color:#cdd6f4;padding:10px;border-radius:8px;overflow-x:auto;font-size:11px;margin:6px 0"><code>$1</code></pre>');
        // Headings
        t = t.replace(/^### (.+)$/gm, '<h4 style="margin:6px 0 3px;font-size:13px;font-weight:700;color:#e2e8f0">$1</h4>');
        t = t.replace(/^## (.+)$/gm, '<h3 style="margin:6px 0 3px;font-size:14px;font-weight:700;color:#e2e8f0">$1</h3>');
        t = t.replace(/^# (.+)$/gm, '<h2 style="margin:6px 0 3px;font-size:15px;font-weight:700;color:#e2e8f0">$1</h2>');
        // Bold + Italic
        t = t.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
        // Bold
        t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        // Italic
        t = t.replace(/\*(.+?)\*/g, '<em>$1</em>');
        // Inline code
        t = t.replace(/`([^`]+)`/g, '<code style="background:rgba(255,255,255,0.1);padding:1px 4px;border-radius:3px;font-size:11px">$1</code>');
        // Unordered list
        t = t.replace(/((?:^|\n)- .+(?:\n- .+)*)/g, function(block) {
            var items = block.trim().split('\n').map(function(line) {
                return '<li>' + line.replace(/^- /, '') + '</li>';
            }).join('');
            return '<ul style="margin:4px 0;padding-left:18px">' + items + '</ul>';
        });
        // Ordered list
        t = t.replace(/((?:^|\n)\d+\. .+(?:\n\d+\. .+)*)/g, function(block) {
            var items = block.trim().split('\n').map(function(line) {
                return '<li>' + line.replace(/^\d+\.\s*/, '') + '</li>';
            }).join('');
            return '<ol style="margin:4px 0;padding-left:18px">' + items + '</ol>';
        });
        // Line breaks
        t = t.replace(/\n/g, '<br>');
        // Clean up double <br> around block elements
        t = t.replace(/(<\/(?:h[2-4]|ul|ol|pre|li)>)<br>/g, '$1');
        t = t.replace(/<br>(<(?:h[2-4]|ul|ol|pre))/g, '$1');
        return t;
    }

    $('#bca-send').on('click', sendMsg);
    $input.on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
    });
    // Auto-resize + update button
    $input.on('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 90) + 'px';
        updateBtn();
    });

    /* ── KCI Ratio Slider ── */
    (function() {
        var $slider = $('#bca-kci-slider');
        var $valLabel = $('#bca-kci-val');
        var $execLabel = $('#bca-kci-exec');
        var $presets = $('.bca-kci-preset');
        var kciTimer = null;

        function updateKciLabels(val) {
            var exec = 100 - val;
            $valLabel.text(val);
            $execLabel.text(exec);
            $presets.removeClass('active');
            $presets.filter('[data-val="' + val + '"]').addClass('active');
            $('#bca-kci-status').text('Priority: Knowledge: ' + val + '%, Execution: ' + exec + '%');
        }

        function saveKciRatio(val) {
            console.log('[KCI-TRACE] ajax_save:', { value: val, exec: 100 - val, sessionId: vars.session_id });
            $.ajax({
                url: vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_chat_set_kci_ratio',
                    session_id: vars.session_id,
                    kci_ratio: val,
                    _wpnonce: vars.nonce
                },
                success: function(r) {
                    console.log('[KCI-TRACE] ajax_response:', r);
                }
            });
        }

        $slider.on('input', function() {
            var val = parseInt(this.value);
            console.log('[KCI-TRACE] slider_change:', { value: val, exec: 100 - val });
            updateKciLabels(val);
            clearTimeout(kciTimer);
            kciTimer = setTimeout(function() { saveKciRatio(val); }, 500);
        });

        $presets.on('click', function() {
            var val = parseInt($(this).data('val'));
            $slider.val(val);
            updateKciLabels(val);
            clearTimeout(kciTimer);
            saveKciRatio(val);
        });

        // Teach AI (Nuôi dậy AI) button → open notebook page
        $('#bca-kci-nuoi').on('click', function() {
            var noteUrl = vars.chat_page_url ? vars.chat_page_url.replace('bizcity-knowledge-chat', 'bizcity-notebook') : '';
            if (noteUrl) {
                window.open(noteUrl, '_blank');
            }
        });
    })();
});
</script>
