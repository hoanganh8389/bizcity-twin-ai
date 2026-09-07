<?php
/**
 * Bizcity Twin AI — Float Widget Template
 * Widget nổi trên trang / Floating chat widget template
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

// Lấy thông tin bot tương tự style_float.php cũ
global $bot_setup;
$bot_setup = wp_parse_args(get_option('pmfacebook_options', []));
$bot_name  = isset($bot_setup['bot_name']) && $bot_setup['bot_name']
    ? $bot_setup['bot_name']
    : ('Trợ lý ảo của ' . get_bloginfo('name'));


$chat_ids = function_exists('twf_get_admin_telegram_chat_ids_by_blog') ? twf_get_admin_telegram_chat_ids_by_blog() : [];
$has_admin_telegram = (is_array($chat_ids) && count($chat_ids)) ? 'true' : 'false';
$using_ai = isset($bot_setup['using_ai']) ? (string)$bot_setup['using_ai'] : '1';
?>

<!-- BizCity WebChat Widget - Style giống bizgpt-agent -->
<div id="bizchat-float-btn" title="Chat với <?php echo esc_attr($bot_name); ?>">
    <?php if ($config['bot_avatar']): ?>
        <img src="<?php echo esc_url($config['bot_avatar']); ?>" alt="<?php echo esc_attr($bot_name); ?>">
    <?php else: ?>
        <span>💬</span>
    <?php endif; ?>
</div>

<div id="bizchat-window" class="bizchat-hidden" role="dialog" aria-labelledby="bizchat-title">
    <!-- Header -->
    <div class="bizchat-header">
        <span>🤖 <span id="bizchat-title"><?php echo esc_html($bot_name); ?></span></span>
        <div class="bizchat-header-actions">
            <button id="bizchat-expand-btn" class="bizchat-header-btn" title="Mở rộng chat">🗗</button>
            <button id="bizchat-close-btn" class="bizchat-header-btn" title="Đóng">&times;</button>
        </div>
    </div>

    <!-- Messages -->
    <div id="bizchat-messages" class="bizchat-messages">
        <div class="bizchat-message bot">
            <div class="bizchat-message-avatar">🤖</div>
            <div class="bizchat-message-content">
                <div class="bizchat-message-bubble">
                    Chào bạn, tôi là <?php echo esc_html($bot_name); ?>. Bạn cần hỗ trợ gì ạ?
                </div>
            </div>
        </div>
    </div>

    <!-- Input Area -->
    <div class="bizchat-input-area">
        <?php /* [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-0.41A — render the configured pre-chat fields through the existing float widget. */ ?>
        <?php $pre_chat_form = isset($config['pre_chat_form']) && is_array($config['pre_chat_form']) ? $config['pre_chat_form'] : []; ?>
        <?php if (!empty($pre_chat_form['enabled']) && !empty($pre_chat_form['fields'])): ?>
            <form id="bizchat-pre-chat-form" class="bizchat-pre-chat-form" novalidate>
                <p class="bizchat-pre-chat-title">Trước khi bắt đầu</p>
                <?php if (in_array('name', $pre_chat_form['fields'], true)): ?><input id="bizchat-pre-chat-name" name="name" type="text" placeholder="Họ tên" autocomplete="name"><?php endif; ?>
                <?php if (in_array('email', $pre_chat_form['fields'], true)): ?><input id="bizchat-pre-chat-email" name="email" type="email" placeholder="Email" autocomplete="email"><?php endif; ?>
                <?php if (in_array('phone', $pre_chat_form['fields'], true)): ?><input id="bizchat-pre-chat-phone" name="phone" type="tel" placeholder="Số điện thoại" autocomplete="tel"><?php endif; ?>
                <button type="submit" class="bizchat-pre-chat-submit">Bắt đầu trò chuyện</button>
            </form>
        <?php endif; ?>
        <!-- Image Preview Area -->
        <div id="bizchat-image-preview" style="display: none;">
            <div class="bizchat-preview-images"></div>
        </div>
        
        <div class="bizchat-input-wrapper">
            <textarea 
                id="bizchat-input" 
                class="bizchat-input" 
                placeholder="<?php echo esc_attr($config['placeholder'] ?: 'Bạn cần tư vấn điều gì?'); ?>"
                rows="1"
                aria-label="Nhập tin nhắn"
            ></textarea>
            <button id="bizchat-send-btn" class="bizchat-send-btn" title="Gửi">
                <span>➡</span>
            </button>
        </div>
        <div class="bizchat-icon-row">
            <button id="bizchat-voice-btn" class="bizchat-bar-btn" title="Nhấn và nói">
                <span>🎤</span>
            </button>
            <input type="file" id="bizchat-file-input" class="bizchat-file-input" accept="image/*,audio/*" multiple>
            <button type="button" id="bizchat-upload-btn" class="bizchat-bar-btn" title="Gửi ảnh/giọng nói">
                <span>📷</span>
            </button>
            <button type="button" id="bizchat-clear-btn" class="bizchat-bar-btn" title="Xoá hội thoại">
                <span>🗑</span>
            </button>
        </div>
    </div>
</div>

<script>
// Biến toàn cục để tương thích với code cũ
window.bizchat_has_admin_telegram = <?php echo $has_admin_telegram; ?>;
window.bizchat_using_ai = <?php echo $using_ai === '1' ? 'true' : 'false'; ?>;
</script>
