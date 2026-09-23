<?php
/**
 * Bizcity Twin AI — Personalized AI Companion Platform
 * Module: Webchat — Minimal Dashboard Theme (Legacy)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      2.1.0
 *
 * Theme tối giản lấy cảm hứng từ ChatGPT / Minimal theme inspired by ChatGPT
 */

defined('ABSPATH') || exit;

class BizCity_WebChat_Dashboard_Minimal {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Assets loaded on demand
    }

    /**
     * Enqueue minimal theme assets: core dashboard CSS/JS + minimal override
     */
    public function enqueue_assets() {
        // Load core dashboard CSS & JS first
        BizCity_WebChat_Admin_Dashboard::instance()->do_enqueue_assets();

        // Minimal CSS override on top
        $css_file = BIZCITY_WEBCHAT_DIR . 'assets/css/chat-minimal.css';
        $css_version = file_exists($css_file) ? filemtime($css_file) : BIZCITY_WEBCHAT_VERSION;

        wp_enqueue_style(
            'bizcity-webchat-minimal',
            BIZCITY_WEBCHAT_URL . 'assets/css/chat-minimal.css',
            ['bizcity-admin-dashboard'],
            $css_version
        );
    }

    /**
     * Render minimal dashboard
     * 
     * Reuses legacy template with theme='minimal' parameter.
     * CSS override handles visual differences.
     * 
     * @param array $args Additional config (unused for now)
     */
    public function render($args = []) {
        // Get legacy dashboard instance
        $dashboard = BizCity_WebChat_Admin_Dashboard::instance();
        
        // Render with minimal theme - adds .bizc-theme-minimal class to wrapper
        $dashboard->render_dashboard('minimal');
    }
}

// Singleton accessor
function bizcity_webchat_minimal() {
    return BizCity_WebChat_Dashboard_Minimal::instance();
}
