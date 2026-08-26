<?php
/**
 * Bizcity Twin AI — WebChat Widget Handler
 * Quản lý chat widget cho frontend và admin / Manage chat widget for frontend & admin
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

defined('ABSPATH') or die('OOPS...');

if (!class_exists('BizCity_WebChat_Widget')) {

class BizCity_WebChat_Widget {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get widget configuration
     */
    public function get_config() {
        return [
            'enabled' => get_option('bizcity_webchat_widget_enabled', true),
            'position' => get_option('bizcity_webchat_widget_position', 'bottom-right'),
            'primary_color' => get_option('bizcity_webchat_primary_color', '#3182f6'),
            'bot_name' => get_option('bizcity_webchat_bot_name', 'BizChat AI'),
            'bot_avatar' => get_option('bizcity_webchat_bot_avatar', ''),
            'welcome_message' => get_option('bizcity_webchat_welcome', 'Xin chào! Tôi có thể giúp gì cho bạn?'),
            'placeholder' => get_option('bizcity_webchat_placeholder', 'Nhập tin nhắn...'),
            'show_on_mobile' => get_option('bizcity_webchat_show_mobile', true),
            'show_typing_indicator' => get_option('bizcity_webchat_typing_indicator', true),
            'enable_file_upload' => get_option('bizcity_webchat_file_upload', true),
            'enable_voice' => get_option('bizcity_webchat_voice', true),
            'auto_open_delay' => get_option('bizcity_webchat_auto_open', 0), // 0 = không tự mở
        ];
    }
    
    /**
     * Check if widget should be displayed
     */
    public function should_display() {
        $config = $this->get_config();
        
        if (!$config['enabled']) {
            return false;
        }
        
        // Check mobile
        if (wp_is_mobile() && !$config['show_on_mobile']) {
            return false;
        }
        
        // Check excluded pages
        $excluded_pages = get_option('bizcity_webchat_excluded_pages', []);
        if (!empty($excluded_pages)) {
            $current_page_id = get_queried_object_id();
            if (in_array($current_page_id, $excluded_pages)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get inline styles based on config
     */
    public function get_inline_styles() {
        $config = $this->get_config();
        $position = $config['position'];
        $color = $config['primary_color'];
        
        $position_styles = '';
        switch ($position) {
            case 'bottom-left':
                $position_styles = 'left: 24px; right: auto;';
                break;
            case 'top-right':
                $position_styles = 'right: 24px; bottom: auto; top: 24px;';
                break;
            case 'top-left':
                $position_styles = 'left: 24px; right: auto; bottom: auto; top: 24px;';
                break;
            default: // bottom-right
                $position_styles = 'right: 24px; bottom: 24px;';
        }
        
        return "
            :root {
                --bizchat-primary: {$color};
                --bizchat-primary-hover: " . $this->adjust_brightness($color, -20) . ";
                --bizchat-primary-light: " . $this->adjust_brightness($color, 40) . ";
            }
            #bizchat-widget-container {
                {$position_styles}
            }
        ";
    }
    
    /**
     * Adjust color brightness
     */
    private function adjust_brightness($hex, $percent) {
        $hex = ltrim($hex, '#');
        
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        $r = max(0, min(255, $r + ($r * $percent / 100)));
        $g = max(0, min(255, $g + ($g * $percent / 100)));
        $b = max(0, min(255, $b + ($b * $percent / 100)));
        
        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }
    
    /**
     * Render quick replies (buttons)
     */
    public function render_quick_replies($replies = []) {
        if (empty($replies)) {
            $replies = $this->get_default_quick_replies();
        }
        
        $html = '<div class="bizchat-quick-replies">';
        foreach ($replies as $reply) {
            $html .= sprintf(
                '<button class="bizchat-quick-btn" data-value="%s">%s</button>',
                esc_attr($reply['value']),
                esc_html($reply['label'])
            );
        }
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get default quick replies
     */
    private function get_default_quick_replies() {
        return apply_filters('bizcity_webchat_quick_replies', [
            ['label' => '🔍 Tìm sản phẩm', 'value' => 'tìm sản phẩm'],
            ['label' => '📦 Theo dõi đơn hàng', 'value' => 'theo dõi đơn hàng'],
            ['label' => '💬 Liên hệ hỗ trợ', 'value' => 'liên hệ hỗ trợ'],
        ]);
    }
    
    /**
     * Format bot message with markdown support
     */
    public function format_message($message) {
        // Convert markdown to HTML
        $message = $this->parse_markdown($message);
        
        // Auto-link URLs
        $message = make_clickable($message);
        
        // Process custom tags
        $message = $this->process_custom_tags($message);
        
        return $message;
    }
    
    /**
     * Simple markdown parser
     */
    private function parse_markdown($text) {
        // Bold
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
        
        // Italic
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/_(.+?)_/', '<em>$1</em>', $text);
        
        // Code
        $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
        
        // Line breaks
        $text = nl2br($text);
        
        return $text;
    }
    
    /**
     * Process custom tags (e.g., [product:123], [order:456])
     */
    private function process_custom_tags($text) {
        // Product tag
        $text = preg_replace_callback('/\[product:(\d+)\]/', function($matches) {
            $product_id = (int) $matches[1];
            $product = wc_get_product($product_id);
            if ($product) {
                return sprintf(
                    '<a href="%s" class="bizchat-product-link" target="_blank">%s</a>',
                    get_permalink($product_id),
                    $product->get_name()
                );
            }
            return $matches[0];
        }, $text);
        
        // Order tag
        $text = preg_replace_callback('/\[order:(\d+)\]/', function($matches) {
            $order_id = (int) $matches[1];
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if ($order) {
                    return sprintf(
                        '<span class="bizchat-order-tag">#%s - %s</span>',
                        $order_id,
                        wc_get_order_status_name($order->get_status())
                    );
                }
            }
            return $matches[0];
        }, $text);
        
        return $text;
    }
}

} // End class_exists check
