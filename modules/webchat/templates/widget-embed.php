<?php
/**
 * Bizcity Twin AI — Embed Widget Template
 * Widget nhúng vào trang / Embed chat widget template (shortcode)
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

// Get shortcode attributes
$height = $atts['height'] ?? '500px';
$width = $atts['width'] ?? '100%';
?>

<!-- BizCity WebChat Embed -->
<div class="bizchat-embed-container" style="width: <?php echo esc_attr($width); ?>; height: <?php echo esc_attr($height); ?>;">
    <div class="bizchat-embed-wrapper">
        <!-- Header -->
        <div class="bizchat-embed-header">
            <div class="bizchat-header-info">
                <div class="bizchat-header-avatar">
                    <?php if ($config['bot_avatar']): ?>
                        <img src="<?php echo esc_url($config['bot_avatar']); ?>" alt="<?php echo esc_attr($config['bot_name']); ?>">
                    <?php else: ?>
                        🤖
                    <?php endif; ?>
                </div>
                <div>
                    <div class="bizchat-header-title"><?php echo esc_html($config['bot_name']); ?></div>
                    <div class="bizchat-header-status">
                        <span class="status-dot"></span>
                        <span>Online</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <div id="bizchat-messages" class="bizchat-messages bizchat-embed-messages">
            <!-- Welcome Message -->
            <div class="bizchat-welcome">
                <div class="bizchat-welcome-icon">💬</div>
                <div class="bizchat-welcome-title"><?php echo esc_html($config['bot_name']); ?></div>
                <div class="bizchat-welcome-text"><?php echo esc_html($config['welcome_message']); ?></div>
            </div>
        </div>

        <!-- Quick Replies -->
        <?php echo $widget->render_quick_replies(); ?>

        <!-- Input Area -->
        <div class="bizchat-input-area">
            <div class="bizchat-input-wrapper">
                <textarea 
                    id="bizchat-input" 
                    class="bizchat-input" 
                    placeholder="<?php echo esc_attr($config['placeholder']); ?>"
                    rows="1"
                ></textarea>
                <div class="bizchat-input-actions">
                    <?php if ($config['enable_file_upload']): ?>
                    <label class="bizchat-input-btn">
                        <input type="file" id="bizchat-file-input" hidden multiple>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"></path>
                        </svg>
                    </label>
                    <?php endif; ?>
                    
                    <?php if ($config['enable_voice']): ?>
                    <button id="bizchat-voice-btn" class="bizchat-input-btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3z"></path>
                            <path d="M19 10v2a7 7 0 01-14 0v-2"></path>
                            <line x1="12" y1="19" x2="12" y2="23"></line>
                            <line x1="8" y1="23" x2="16" y2="23"></line>
                        </svg>
                    </button>
                    <?php endif; ?>
                    
                    <button id="bizchat-send-btn" class="bizchat-input-btn bizchat-send-btn" disabled>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.bizchat-embed-container {
    border-radius: var(--bizchat-radius-l, 16px);
    overflow: hidden;
    box-shadow: var(--bizchat-shadow, 0 4px 24px rgba(0, 0, 0, 0.12));
}

.bizchat-embed-wrapper {
    display: flex;
    flex-direction: column;
    height: 100%;
    background: var(--bizchat-bg-primary, #fff);
}

.bizchat-embed-header {
    padding: 16px 20px;
    background: var(--bizchat-primary, #3182f6);
    color: #fff;
}

.bizchat-embed-header .bizchat-header-title {
    color: #fff;
}

.bizchat-embed-header .bizchat-header-status {
    color: rgba(255, 255, 255, 0.8);
}

.bizchat-embed-messages {
    flex: 1;
    min-height: 200px;
}

<?php echo $widget->get_inline_styles(); ?>
</style>
