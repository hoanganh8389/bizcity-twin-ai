<?php
/**
 * Bizcity Twin AI — Personalized AI Companion Platform
 * Module: Webchat — Admin Dashboard Chat Interface (Legacy 2026.03.0)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BizCity_WebChat_Admin_Dashboard {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Replace default dashboard - use admin_init for redirects (before output)
        add_action('admin_init', [$this, 'redirect_dashboard']);
        add_action('admin_menu', [$this, 'reorder_menu'], 999);
        
        // Add dashboard page
        add_action('admin_menu', [$this, 'add_dashboard_page'], 5);
        
        // Enqueue assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

    }
    
    /**
     * Redirect default dashboard to chat dashboard
     * Also handle ?chat=wcs_xxx without page= param
     */
    public function redirect_dashboard() {
        global $pagenow;
        
        // Redirect index.php to chat dashboard
        if ($pagenow === 'index.php' && !isset($_GET['page'])) {
            wp_redirect(admin_url('admin.php?page=bizcity-webchat-dashboard'));
            exit;
        }
        
        // Fix: /wp-admin/admin.php?chat=wcs_xxx → add page= param
        if ($pagenow === 'admin.php' && isset($_GET['chat']) && !isset($_GET['page'])) {
            $chat_id = sanitize_text_field($_GET['chat']);
            wp_redirect(admin_url('admin.php?page=bizcity-webchat-dashboard&chat=' . urlencode($chat_id)));
            exit;
        }
    }
    
    /**
     * Add dashboard menu page
     */
    public function add_dashboard_page() {
        add_menu_page(
            'Nói chuyện với trợ lý',
            'Nói chuyện với trợ lý',
            'read', // All logged-in users can access
            'bizcity-webchat-dashboard',
            [$this, 'render_dashboard'],
            plugins_url('assets/icon/Bell.png', dirname(__FILE__)),
            2 // Position after dashboard
        );
    }
    
    /**
     * Reorder admin menu to prioritize chat
     */
    public function reorder_menu() {
        global $menu;
        
        // Find and move Bots - Web Chat to position 3 (after Dashboard, before Posts)
        if (isset($menu)) {
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && $item[2] === 'bizcity-webchat') {
                    $menu_item = $menu[$key];
                    unset($menu[$key]);
                    
                    // Insert at position 3
                    $menu = array_slice($menu, 0, 3, true) +
                            [$key => $menu_item] +
                            array_slice($menu, 3, null, true);
                    break;
                }
            }
        }
    }
    
    /**
     * Enqueue dashboard assets
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_bizcity-webchat-dashboard') {
            return;
        }
        
        // jQuery
        wp_enqueue_script('jquery');
        
        // Custom dashboard styles
        wp_add_inline_style('wp-admin', $this->get_dashboard_styles());
    }
    
    /**
     * Get dashboard CSS
     */
    private function get_dashboard_styles() {
        return '
        /* Hide admin notices on dashboard */
        .toplevel_page_bizcity-webchat-dashboard .notice,
        .toplevel_page_bizcity-webchat-dashboard .update-nag {
            display: none !important;
        }
        ';
    }
    
    /**
     * Render dashboard page
     * 
     * @param string $theme Theme variant: 'legacy', 'minimal', etc.
     */
    public function render_dashboard($theme = 'legacy') {
        // Get character data
        $character_id = 0;
        $character = null;
        $characters = [];
        
        if (class_exists('BizCity_Knowledge_Database')) {
            $db = BizCity_Knowledge_Database::instance();
            
            // Get default character
            $character_id = intval(get_option('bizcity_webchat_default_character_id', 0));
            if (empty($character_id)) {
                $bot_setup = get_option('pmfacebook_options', []);
                $character_id = isset($bot_setup['default_character_id']) ? intval($bot_setup['default_character_id']) : 0;
            }
            
            $character = $character_id ? $db->get_character($character_id) : null;
            $characters = $db->get_characters(['status' => 'active', 'limit' => 100]);
            
            if (!$character && !empty($characters)) {
                $character = $characters[0];
                $character_id = $character->id;
            }
        }
        
        $greeting_messages = [];
        if ($character && !empty($character->greeting_messages)) {
            $greeting_messages = json_decode($character->greeting_messages, true) ?: [];
        }
        $random_greeting = !empty($greeting_messages) ? $greeting_messages[array_rand($greeting_messages)] : 'Xin chào! Tôi có thể giúp gì cho bạn?';
        
        $char_name = $character ? $character->name : 'AI Assistant';
        $char_model = ($character && !empty($character->model_id)) ? $character->model_id : 'GPT-4o-mini';
        $char_desc = ($character && !empty($character->description)) ? $character->description : 'Trợ lý AI thông minh của bạn';
        $char_avatar = ($character && !empty($character->avatar)) ? $character->avatar : '';
        
        // Blog name for header display
        $blog_name = get_bloginfo('name') ?: 'AI Assistant';
        $header_name = 'Trợ lý ' . $blog_name;
        $header_desc = 'Team leader điều hành công việc, điều phối các AI Agents khác';
        
        $current_uid = get_current_user_id();
        $session_id = 'adminchat_' . get_current_blog_id() . '_' . ( $current_uid ? $current_uid : 'guest_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $nonce = wp_create_nonce('bizcity_webchat');

        // Only show Mode Router Console for dev admins
        $current_user = wp_get_current_user();
        $is_dev_admin = in_array( $current_user->user_login, [ 'admin1', 'hoanganh.itm' ], true );

        // Get active agent plugins for Touch Bar
        $agent_plugins = [];
        if ( class_exists( 'BizCity_Market_Catalog' ) && method_exists( 'BizCity_Market_Catalog', 'get_agent_plugins_with_headers' ) ) {
            $agent_plugins = BizCity_Market_Catalog::get_agent_plugins_with_headers();
        }
        
        ?>
        <style>
        /* ===== SCOPED RESET — prevent theme CSS from overriding chat UI ===== */
        .bizc-dash *, .bizc-dash *::before, .bizc-dash *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            text-transform: none;
        }
        .bizc-dash button, .bizc-dash input, .bizc-dash textarea, .bizc-dash select {
            font-family: inherit;
            font-size: inherit;
            line-height: inherit;
            color: inherit;
            text-transform: none;
            letter-spacing: normal;
        }
        .bizc-dash button {
            cursor: pointer;
        }
        .bizc-dash img {
            max-width: 100%;
            height: auto;
        }
        .bizc-dash a {
            text-decoration: none;
        }
        /* ===== ADMIN DASHBOARD CHAT - LIGHT THEME ===== */
        #wpbody-content { padding: 0 !important; }
        #wpbody-content > .wrap:first-child { margin: 0 !important; padding: 0 !important; }
        #wpcontent { padding-left: 5px !important; }
        .bizc-dash {
            display: flex;
            height: calc(100vh - 32px);
            /*background: linear-gradient(135deg, #e5deff 0%, #d5e5ff 50%, #e0f0ff 100%);*/
            color: #1a1a2e;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            overflow: hidden;
            padding: 10px;
            gap: 5px;
        }
        
        /* ========== SIDEBAR ========== */
        .bizc-sidebar {
            width: 250px;
            min-width: 250px;
            background: #ffffff;
            border-radius: 0;
            box-shadow: none;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        /* Sidebar Header */
        .bizc-sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
        }
        .bizc-sidebar-logo {
            font-size: 15px;
            font-weight: 700;
            color: #1a1a2e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 1;
        }
        .bizc-sidebar-collapse {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            color: #9ca3af;
            transition: background 0.2s, color 0.2s;
            font-size: 18px;
        }
        .bizc-sidebar-collapse:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        
        /* Guest Login Button */
        .bizc-guest-login-wrap {
            padding: 0;
            margin: 0px;
            margin-left: 00px !important;
        }
        .bizc-guest-login-btn {
                padding: 0px 20px;
                background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
                border: none;
                border-radius: 8px;
                color: #fff;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                transition: all 0.3s ease;
                box-shadow: 0 4px 16px rgba(99, 102, 241, 0.2);
                margin-left: 10px !important;
                width:93%;
                min-height: 35px;
        }
        .bizc-guest-login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.35);
        }
        .bizc-guest-login-btn svg {
            flex-shrink: 0;
        }
        
        /* Search Chat */
        .bizc-search-wrap {
            padding: 0px;
            border-bottom: 0px solid #f3f4f6;
        }
        .bizc-search-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 93%;
            padding: 3x 12px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            color: #9ca3af;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s;
            margin-left:10px !important;
        }
        .bizc-search-btn:hover {
            background: #e5e7eb;
            border-color: #d1d5db;
            color: #6b7280;
        }
        .bizc-search-btn svg {
            flex-shrink: 0;
        }
        
        /* Search Modal - ChatGPT style */
        .bizc-search-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            justify-content: center;
            align-items: flex-start;
            padding-top: 8vh;
        }
        .bizc-search-modal.active {
            display: flex;
        }
        .bizc-search-modal-content {
            background: #fff;
            width: 90%;
            max-width: 480px;
            min-height: 440px;
            max-height: 440px;
            border-radius: 16px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .bizc-search-modal-header {
            display: block;
            align-items: center;
            justify-content: space-between;
            min-height: 64px;
            max-height: 64px;
            padding: 0 16px 0 12px;
        }
        .bizc-search-modal-header input {
            flex: 1;
            padding: 8px 20px;
            margin:10px auto;
            border: none;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            color: #9ca3af;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s;
            width: 100%;
            font-size: 15px;
            outline: none;
            color: #1a1a2e;
        }
        .bizc-search-modal-header input::placeholder {
            color: #9ca3af;
        }
        .bizc-search-close {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            border-radius: 50%;
            color: #9ca3af;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            margin-left: 12px;
        }
        .bizc-search-close:hover {
            background: #f3f4f6;
            color: #1a1a2e;
        }
        .bizc-search-close svg {
            width: 20px;
            height: 20px;
        }
        .bizc-search-hr {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 0;
        }
        .bizc-search-results {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
        }
        .bizc-search-list {
            list-style: none;
            margin: 0;
            padding: 0 8px;
        }
        .bizc-search-group-label {
            padding: 8px 16px 4px;
            font-size: 12px;
            color: #9ca3af;
            font-weight: 400;
        }
        .bizc-search-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.15s;
            text-decoration: none;
            color: inherit;
        }
        .bizc-search-item:hover {
            background: #f3f4f6;
        }
        .bizc-search-item-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            flex-shrink: 0;
        }
        .bizc-search-item-icon svg {
            width: 20px;
            height: 20px;
        }
        .bizc-search-item-title {
            flex: 1;
            padding-left: 8px;
            font-size: 14px;
            color: #1a1a2e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .bizc-search-empty {
            padding: 60px 20px;
            text-align: center;
            color: #9ca3af;
            font-size: 14px;
        }
        
        .bizc-new-chat-btn {
            margin: 16px;
            padding: 0px 20px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border: none;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(99, 102, 241, 0.2);
            margin-left:10px !important;
            min-height: 35px;
        }
        
        .bizc-new-chat-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.35);
        }
        
        /* Characters Section → Projects Section */
        .bizc-section {
            padding: 0 12px;
            margin-bottom: 4px;
        }
        
        .bizc-section-hdr {
            padding: 10px 12px 6px;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* Project list */
        .bizc-proj-list {
            max-height: calc(100vh - 380px);
            overflow-y: auto;
        }
        
        .bizc-proj-add-btn {
            color: #6366f1;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
            line-height: 1;
            padding: 2px 6px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .bizc-proj-add-btn:hover {
            background: rgba(99,102,241,0.12);
            transform: scale(1.1);
        }
        
        /* Project item */
        .bizc-proj-item {
            margin-bottom: 2px;
            transition: all 0.2s ease;
        }
        /* Drag-drop visual feedback */
        .bizc-proj-item.drag-over {
            outline: 2px dashed #6366f1 !important;
            background: rgba(99,102,241,0.08);
            border-radius: 12px;
        }
        .bizc-conv.dragging {
            opacity: 0.4;
            transform: scale(0.95);
        }
        .bizc-conv {
            cursor: grab;
        }
        .bizc-conv:active {
            cursor: grabbing;
        }
        .bizc-proj-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #1a1a2e;
            user-select: none;
        }
        .bizc-proj-header:hover {
            background: linear-gradient(135deg, rgba(99,102,241,0.06), rgba(139,92,246,0.06));
        }
        .bizc-proj-header.active {
            background: linear-gradient(135deg, rgba(99,102,241,0.12), rgba(139,92,246,0.12));
        }
        .bizc-proj-icon {
            font-size: 16px;
            width: 22px;
            text-align: center;
            flex-shrink: 0;
        }
        .bizc-proj-arrow {
            font-size: 10px;
            color: #9ca3af;
            transition: transform 0.2s;
            flex-shrink: 0;
            width: 12px;
        }
        .bizc-proj-arrow.open { transform: rotate(90deg); }
        .bizc-proj-name {
            flex: 1;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .bizc-proj-count {
            font-size: 10px;
            color: #9ca3af;
            background: rgba(107,114,128,0.1);
            padding: 1px 6px;
            border-radius: 8px;
            flex-shrink: 0;
        }
        .bizc-proj-menu-btn {
            opacity: 0;
            font-size: 14px;
            color: #9ca3af;
            cursor: pointer;
            padding: 0 4px;
            transition: opacity 0.15s;
            flex-shrink: 0;
        }
        .bizc-proj-header:hover .bizc-proj-menu-btn { opacity: 1; }
        .bizc-proj-menu-btn:hover { color: #6366f1; }
        
        /* Project sub-conversations */
        .bizc-proj-convs {
            padding-left: 12px;
            overflow: hidden;
            transition: max-height 0.25s ease;
        }
        .bizc-proj-convs.collapsed {
            max-height: 0 !important;
        }
        
        .bizc-proj-conv {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px 6px 22px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
            margin-bottom: 1px;
            color: #4b5563;
            font-size: 12px;
        }
        .bizc-proj-conv:hover {
            background: rgba(99,102,241,0.06);
            color: #1a1a2e;
        }
        .bizc-proj-conv.active {
            background: rgba(99,102,241,0.12);
            color: #4c1d95;
            font-weight: 500;
        }
        .bizc-proj-conv-title {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Context menu (dropdown) */
        .bizc-ctx-menu {
            position: fixed;
            background: #fff;
            border: 1px solid rgba(99,102,241,0.15);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            padding: 4px;
            z-index: 10000;
            min-width: 160px;
        }
        .bizc-ctx-menu-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            color: #374151;
            transition: background 0.1s;
        }
        .bizc-ctx-menu-item:hover { background: rgba(99,102,241,0.08); }
        .bizc-ctx-menu-item.danger { color: #ef4444; }
        .bizc-ctx-menu-item.danger:hover { background: rgba(239,68,68,0.08); }

        /* Project Detail Panel (ChatGPT-style) */
        .bizc-proj-detail-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            transition: background 0.15s;
        }
        .bizc-proj-detail-item:hover {
            background: rgba(99,102,241,0.04);
        }
        .bizc-proj-detail-item .pdi-title {
            font-weight: 600;
            font-size: 14px;
            color: #1a1a2e;
            margin-bottom: 3px;
        }
        .bizc-proj-detail-item .pdi-desc {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .bizc-proj-detail-item .pdi-date {
            font-size: 11px;
            color: #9ca3af;
            white-space: nowrap;
            margin-left: auto;
            flex-shrink: 0;
        }
        .bizc-proj-detail-item .pdi-status {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 8px;
            font-weight: 600;
            white-space: nowrap;
        }
        .bizc-proj-detail-item .pdi-status.active { background: #dcfce7; color: #16a34a; }
        .bizc-proj-detail-item .pdi-status.completed { background: #f3f4f6; color: #6b7280; }
        .bizc-proj-detail-item .pdi-status.waiting { background: #fef3c7; color: #d97706; }
        
        /* Add project inline form */
        .bizc-proj-add-form {
            display: flex;
            gap: 6px;
            padding: 8px 12px;
            align-items: center;
        }
        .bizc-proj-add-form input {
            flex: 1;
            border: 1.5px solid rgba(99,102,241,0.3);
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 12px;
            outline: none;
            background: #fafbff;
            color: #1a1a2e;
            min-width: 0;
        }
        .bizc-proj-add-form input:focus {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 2px rgba(139,92,246,0.15);
        }
        .bizc-proj-add-form button {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .bizc-proj-add-form .bizc-proj-save {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            box-shadow: 0 2px 6px rgba(99,102,241,0.25);
        }
        .bizc-proj-add-form .bizc-proj-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99,102,241,0.35);
        }
        .bizc-proj-add-form .bizc-proj-save:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .bizc-proj-add-form .bizc-proj-cancel {
            background: #e5e7eb;
            color: #374151;
        }
        .bizc-proj-add-form .bizc-proj-cancel:hover {
            background: #d1d5db;
        }
        
        /* Conversations */
        .bizc-convs {
            flex: 1;
            overflow-y: auto;
            padding: 0 12px;
        }
        
        .bizc-conv {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 2px;
            color: #4b5563;
        }
        
        .bizc-conv:hover { 
            background: rgba(99,102,241,0.08);
            transform: translateX(4px);
        }
        .bizc-conv.active { 
            background: linear-gradient(135deg, rgba(99,102,241,0.15), rgba(139,92,246,0.15));
            color: #1a1a2e;
        }
        
        .bizc-conv-title {
            flex: 1;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Settings at bottom */
        .bizc-sidebar-footer {
            padding: 12px;
            border-top: 1px solid rgba(99,102,241,0.1);
        }
        
        .bizc-settings-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            color: #6b7280;
            text-decoration: none;
            font-size: 13px;
            transition: 0.2s;
        }
        
        .bizc-settings-link:hover {
            background: rgba(99,102,241,0.08);
            color: #6366f1;
        }
        
        /* ========== MAIN ========== */
        .bizc-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 8px 32px rgba(99, 102, 241, 0.12);
        }
        
        /* Console Section */
        .bizc-tools {
            padding: 0px;
            flex-shrink: 0;
        }
        
        .bizc-tool-card {
            background: transparent;
            border: none;
            border-radius: 14px;
            padding: 0;
            text-align: left;
        }

        /* Drag resize handle between console and messages */
        .bizc-resize-handle {
            height: 6px;
            background: linear-gradient(90deg, transparent 30%, rgba(99,102,241,0.2) 50%, transparent 70%);
            cursor: ns-resize;
            flex-shrink: 0;
            position: relative;
            user-select: none;
        }
        .bizc-resize-handle::after {
            content: '';
            position: absolute;
            left: 50%; top: 50%;
            transform: translate(-50%, -50%);
            width: 30px; height: 3px;
            background: rgba(99,102,241,0.35);
            border-radius: 2px;
        }
        .bizc-resize-handle:hover, .bizc-resize-handle:active {
            background: linear-gradient(90deg, transparent 20%, rgba(99,102,241,0.35) 50%, transparent 80%);
        }
        
        /* Header */
        .bizc-header {
            background: linear-gradient(135deg, rgba(165,180,252,0.2), rgba(196,181,253,0.2));
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(99,102,241,0.1);
        }
        
        .bizc-hdr-left { display: flex; align-items: center; gap: 14px; }
        .bizc-hdr-av {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: 2px solid rgba(139,92,246,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            font-size: 20px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(99,102,241,0.2);
        }
        .bizc-hdr-av img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .bizc-hdr-info h2 { margin: 0; font-size: 17px; color: #4c1d95; font-weight: 700; }
        .bizc-hdr-info span { 
            font-size: 12px; 
            color: #10b981; 
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .bizc-hdr-info span::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
            animation: pulse-dot 2s ease-in-out infinite;
        }
        @keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:0.5} }
        
        /* Messages */
        .bizc-messages {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            background: linear-gradient(180deg, rgba(255,255,255,0) 0%, rgba(249,250,251,0.5) 100%);
        }
        
        .bizc-msg {
            display: flex;
            margin-bottom: 16px;
            gap: 12px;
            animation: bizc-msg-in 0.4s ease;
        }
        .bizc-msg.user { flex-direction: row-reverse; }
        .bizc-msg.bot {
            flex-direction: column;
            gap: 0;
        }
        @keyframes bizc-msg-in { from { opacity:0; transform: translateY(10px); } }
        
        .bizc-msg-av {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(99,102,241,0.15);
        }
        .bizc-msg.bot .bizc-msg-av { 
            display: none;
        }
        .bizc-msg.user .bizc-msg-av { 
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
        }
        .bizc-msg-av img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        
        .bizc-msg-bubble {
            padding: 14px 18px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.7;
            word-break: break-word;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            position: relative;
        }
        .bizc-msg.bot .bizc-msg-bubble {
            background: #f3f4f6;
            color: #1a1a2e;
            border-radius: 12px;
            width: 100%;
        }
        .bizc-msg.bot > div { width: 100%; }
        /* ── Markdown in bot messages ── */
        .bizc-msg-bubble h2, .bizc-msg-bubble h3, .bizc-msg-bubble h4 {
            margin: 12px 0 6px;
            font-weight: 700;
            line-height: 1.4;
        }
        .bizc-msg-bubble h2 { font-size: 16px; }
        .bizc-msg-bubble h3 { font-size: 15px; }
        .bizc-msg-bubble h4 { font-size: 14px; }
        .bizc-msg-bubble ul, .bizc-msg-bubble ol {
            margin: 6px 0;
            padding-left: 20px;
        }
        .bizc-msg-bubble li { margin-bottom: 2px; }
        .bizc-msg-bubble pre {
            background: #1e1e2e;
            color: #cdd6f4;
            padding: 14px 16px;
            border-radius: 10px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.5;
            margin: 10px 0;
            position: relative;
        }
        .bizc-msg-bubble pre code {
            background: none !important;
            padding: 0 !important;
            border-radius: 0 !important;
            color: inherit !important;
            font-size: inherit;
        }
        .bizc-msg-bubble code {
            background: #e5e7eb;
            padding: 2px 6px;
            border-radius: 4px;
            color: #4c1d95;
            font-size: 12px;
        }
        /* Copy button on code blocks */
        .bizc-code-wrap {
            position: relative;
        }
        .bizc-copy-btn {
            position: absolute;
            top: 6px;
            right: 6px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.15);
            color: #cdd6f4;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 6px;
            cursor: pointer;
            z-index: 2;
            transition: all 0.2s;
        }
        .bizc-copy-btn:hover {
            background: rgba(255,255,255,0.22);
        }
        .bizc-copy-btn.copied {
            color: #a6e3a1;
            border-color: #a6e3a1;
        }
        /* Copy button for entire bot message */
        .bizc-msg-actions {
            position: absolute;
            top: 6px;
            right: 6px;
            display: flex;
            gap: 2px;
            opacity: 0;
            transition: opacity 0.15s;
            z-index: 2;
        }
        .bizc-msg.bot:hover .bizc-msg-actions,
        .bizc-msg-bubble:hover .bizc-msg-actions { opacity: 1; }
        .bizc-msg-action-btn {
            background: rgba(255,255,255,0.85);
            border: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 12px;
            width: 26px;
            height: 26px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
            backdrop-filter: blur(4px);
        }
        .bizc-msg-action-btn:hover {
            background: rgba(99,102,241,0.1);
            color: #6366f1;
            border-color: #c7d2fe;
        }
        .bizc-msg-action-btn.copied {
            color: #10b981;
            border-color: #a7f3d0;
        }
        .bizc-msg.user .bizc-msg-bubble {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            border-bottom-right-radius: 6px;
            box-shadow: 0 4px 16px rgba(99,102,241,0.25);
        }
        
        .bizc-msg-time {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 4px;
            font-weight: 500;
        }
        .bizc-msg.user .bizc-msg-time { text-align: right; }
        .bizc-msg.bot .bizc-msg-time { margin-left: 2px; }
        
        /* Plugin Debug Badge */
        .bizc-plugin-badge {
            display: inline-block;
            background: linear-gradient(135deg, #059669, #10b981);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 8px;
            margin-top: 4px;
            margin-left: 2px;
            box-shadow: 0 1px 3px rgba(5, 150, 105, 0.3);
        }
        .bizc-tool-badge {
            display: inline-block;
            background: linear-gradient(135deg, #7c3aed, #a78bfa);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 8px;
            margin-top: 4px;
            margin-left: 4px;
            box-shadow: 0 1px 3px rgba(124, 58, 237, 0.3);
        }
        
        /* Images */
        .bizc-msg-images { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
        .bizc-msg-images img {
            max-width: 200px;
            max-height: 160px;
            border-radius: 12px;
            cursor: pointer;
            border: 2px solid rgba(99,102,241,0.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: all 0.2s ease;
        }
        .bizc-msg-images img:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 24px rgba(99,102,241,0.2);
        }
        
        /* Typing */
        .bizc-typing {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .bizc-typing-body {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-width: calc(100% - 48px);
        }
        .bizc-typing-dots {
            display: flex;
            gap: 5px;
            padding: 16px 20px;
            background: #f3f4f6;
            border-radius: 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .bizc-typing-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #8b5cf6;
            animation: bizc-dot-pulse 1.4s infinite ease-in-out;
        }
        .bizc-typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .bizc-typing-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes bizc-dot-pulse { 0%,80%,100%{opacity:0.3;transform:scale(0.8)} 40%{opacity:1;transform:scale(1.3)} }
        
        /* Input Area - ChatGPT Style */
        .bizc-input-area {
            padding: 16px 24px;
            padding-bottom: clamp(16px, 4vh, 40px) !important;
            padding-top: 0 !important;
            background: #ffffff;
            border-top: 1px solid rgba(99,102,241,0.08);
            position: relative;
        }
        
        /* ═══ Pre-Intent Plugin Chips Bar ═══ */
        .bizc-plugin-chips-bar {
            padding: 8px 0 6px 0;
            overflow: hidden;
        }
        .bizc-chips-scroll {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 2px 2px;
        }
        .bizc-chips-scroll::-webkit-scrollbar { display: none; }
        .bizc-chips-loading {
            font-size: 11px;
            color: #9ca3af;
            padding: 4px 0;
        }
        .bizc-plugin-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px !important;
            margin: 0 !important;
            border-radius: 999px !important;
            border: 1px solid rgba(99,102,241,0.12) !important;
            background: #f9fafb !important;
            color: #6b7280 !important;
            font-size: 12px !important;
            line-height: 1.3 !important;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.25s ease;
            flex-shrink: 0;
            user-select: none;
            text-transform: none !important;
            letter-spacing: normal !important;
        }
        .bizc-plugin-chip:hover {
            border-color: rgba(99,102,241,0.4);
            background: rgba(99,102,241,0.06);
            color: #4f46e5;
        }
        .bizc-plugin-chip.suggested {
            border-color: rgba(99,102,241,0.5);
            background: rgba(99,102,241,0.08);
            color: #4f46e5;
            box-shadow: 0 0 0 2px rgba(99,102,241,0.12);
            animation: bizc-chip-pulse 1.5s ease-in-out;
        }
        .bizc-plugin-chip.active {
            border-color: #6366f1 !important;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%) !important;
            color: #fff !important;
            box-shadow: 0 2px 8px rgba(99,102,241,0.3);
        }
        .bizc-plugin-chip.active:hover {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%) !important;
            color: #fff !important;
        }
        .bizc-chip-icon {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            object-fit: cover;
            flex-shrink: 0;
        }
        .bizc-chip-icon-emoji {
            font-size: 13px;
            line-height: 1;
        }
        .bizc-chip-suggest-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #6366f1;
            flex-shrink: 0;
            display: none;
        }
        .bizc-plugin-chip.suggested .bizc-chip-suggest-dot {
            display: inline-block;
        }
        @keyframes bizc-chip-pulse {
            0% { transform: scale(1); }
            30% { transform: scale(1.05); }
            60% { transform: scale(1); }
        }
        
        /* Simple input container like ChatGPT */
        .bizc-input-container {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            position: relative;
            background: #f9fafb;
            border: 1.5px solid rgba(99,102,241,0.15);
            border-radius: 24px;
            padding: 4px;
            transition: all 0.2s ease;
        }
        
        .bizc-input-container:focus-within {
            border-color: #8b5cf6;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }
        
        .bizc-attach-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: transparent;
            border: none;
            color: #6b7280;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .bizc-attach-btn:hover { 
            background: rgba(99,102,241,0.1);
            color: #6366f1;
        }
        
        .bizc-input {
            flex: 1;
            background: transparent;
            border: none;
            padding: 8px 12px;
            font-size: 14px;
            color: #1a1a2e;
            outline: none;
            resize: none;
            min-height: 24px;
            max-height: 120px;
            line-height: 1.5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
        }
        .bizc-input::placeholder { 
            color: #9ca3af; 
        }
        
        .bizc-send-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #6366f1;
            border: none;
            color: #ffffff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .bizc-send-btn:hover {
            background: #5a67d8;
            transform: scale(1.05);
        }
        .bizc-send-btn:disabled {
            background: #e5e7eb;
            color: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }

        /* @mention autocomplete dropdown - ChatGPT Style */
        .bizc-mention-dropdown {
            display: none;
            position: absolute;
            bottom: 100%;
            left: 0;
            right: 0;
            margin-bottom: 8px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            max-height: 300px;
            overflow: hidden;
            z-index: 1000;
        }
        
        .bizc-mention-dropdown.active {
            display: block;
        }
        
        .bizc-mention-header {
            padding: 12px 16px 8px;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .bizc-mention-list {
            max-height: 240px;
            overflow-y: auto;
            padding: 4px 0;
        }
        
        .bizc-mention-item {
            display: flex;
            align-items: center;
            padding: 8px 16px;
            cursor: pointer;
            transition: background-color 0.15s ease;
            text-decoration: none;
            color: inherit;
        }
        
        .bizc-mention-item:hover,
        .bizc-mention-item.selected {
            background: #f3f4f6;
        }
        
        .bizc-mention-item-icon {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            margin-right: 12px;
            background: #f9fafb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }
        
        .bizc-mention-item-icon img {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            object-fit: cover;
        }
        
        .bizc-mention-item-info {
            flex: 1;
            min-width: 0;
        }
        
        .bizc-mention-item-name {
            font-size: 14px;
            font-weight: 500;
            color: #111827;
            margin-bottom: 2px;
        }
        
        .bizc-mention-item-slug {
            font-size: 12px;
            color: #6b7280;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* @mention tag badge in input area */
        .bizc-mention-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px 3px 8px;
            background: linear-gradient(135deg, rgba(99,102,241,0.12), rgba(139,92,246,0.12));
            border: 1px solid rgba(99,102,241,0.25);
            border-radius: 14px;
            font-size: 12px;
            font-weight: 600;
            color: #6366f1;
            position: absolute;
            top: -32px;
            left: 56px;
            z-index: 10;
            cursor: default;
            animation: bizcMentionIn 0.2s ease;
        }
        .bizc-mention-tag .bizc-mt-remove {
            cursor: pointer;
            opacity: 0.6;
            font-size: 13px;
            margin-left: 2px;
            transition: opacity 0.15s;
        }
        .bizc-mention-tag .bizc-mt-remove:hover { opacity: 1; }
        @keyframes bizcMentionIn {
            from { opacity: 0; transform: translateY(4px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        button {margin: 5px !important;}
        .aiagent-newchat-btn { background: rgb(78 99 255 / 20%) !important;}
        .bizc-send-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(99,102,241,0.25);
            font-size: 0;
            margin:0px !important;
        }
        .bizc-send-btn .dashicons {
            font-size: 26px;
            width: 26px;
            height: 26px;
            line-height: 1;
        }
        .bizc-send-btn:hover { 
            transform: scale(1.08);
            box-shadow: 0 6px 24px rgba(99,102,241,0.4);
        }
        
        /* Guest Trial Hint */
        .bizc-guest-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px !important;
            margin: 0 16px 8px 16px !important;
            background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(139,92,246,0.08)) !important;
            border: 1px solid rgba(99,102,241,0.15) !important;
            border-radius: 10px !important;
            font-size: 12px !important;
            color: #6b7280;
            line-height: 1.5 !important;
        }
        .bizc-guest-hint-icon {
            font-size: 14px;
        }
        .bizc-guest-hint-text strong {
            color: #6366f1;
            font-weight: 600;
        }
        .bizc-guest-hint-text a {
            color: #8b5cf6;
            font-weight: 600;
            text-decoration: none;
        }
        .bizc-guest-hint-text a:hover {
            text-decoration: underline;
        }
        .bizc-guest-hint.exhausted {
            background: linear-gradient(135deg, rgba(239,68,68,0.08), rgba(220,38,38,0.08));
            border-color: rgba(239,68,68,0.2);
        }
        .bizc-guest-hint.exhausted .bizc-guest-hint-text strong {
            color: #ef4444;
        }
        
        /* Image preview area */
        .bizc-img-preview {
            padding: 10px 24px;
            background: rgba(99,102,241,0.04);
            border-top: 1px solid rgba(99,102,241,0.08);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .bizc-img-thumb {
            position: relative;
            width: 70px;
            height: 70px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid rgba(99,102,241,0.2);
            box-shadow: 0 2px 8px rgba(99,102,241,0.1);
            background: #f9fafb;
        }
        .bizc-img-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .bizc-img-thumb .bizc-img-rm {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #ef4444;
            color: #fff;
            border: 2px solid #fff;
            font-size: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            z-index: 1;
            transition: all 0.2s;
        }
        .bizc-img-thumb .bizc-img-rm:hover {
            background: #dc2626;
            transform: scale(1.1);
        }
        .bizc-vision-hint {
            font-size: 12px;
            color: #8b5cf6;
            padding: 4px 24px 0;
            font-weight: 500;
        }
        
        /* Scrollbar */
        .bizc-dash ::-webkit-scrollbar { width: 6px; }
        .bizc-dash ::-webkit-scrollbar-track { background: rgba(99,102,241,0.05); border-radius: 10px; }
        .bizc-dash ::-webkit-scrollbar-thumb { 
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            border-radius: 10px;
        }
        .bizc-dash ::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
        
        /* Responsive */
        @media (max-width: 1200px) {
            /* .bizc-tools responsive — single col */
        }
        @media (max-width: 900px) {
            .bizc-sidebar { width: 200px; min-width: 200px; }
            .bizc-dash { padding: 12px; gap: 12px; }
            .bizc-tools { display: none !important; } /* Hide console on tablet/mobile */
            .bizc-header { display: none !important; } /* Hide inner header on tablet/mobile */
        }
        @media (max-width: 600px) {
            .bizc-resize-handle {display: none !important;}
            /* Sidebar becomes fixed drawer on mobile — hidden by default, shown via .mobile-open */
            .bizc-sidebar {
                display: flex !important;
                position: fixed;
                top: 0;
                left: 0;
                width: 280px;
                min-width: 280px;
                height: 96vh;
                z-index: 9999;
                border-radius: 0 20px 20px 0;
                box-shadow: 4px 0 24px rgba(0,0,0,0.15);
                transform: translateX(-110%);
                transition: transform 0.3s ease;
            }
            .bizc-sidebar.mobile-open {
                transform: translateX(0);
            }
            .bizc-dash { padding: 8px; }
            .bizc-tools { display: none !important; }
            .bizc-header { display: none !important; }
        }
        
        /* ── Mobile Drawer Backdrop ── */
        .bizc-drawer-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 9998;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .bizc-drawer-backdrop.active {
            display: block;
            opacity: 1;
        }
        </style>
        
        <!-- Mobile Drawer Backdrop -->
        <div class="bizc-drawer-backdrop" id="bizc-drawer-backdrop"></div>
        
        <div class="bizc-dash<?php echo $theme !== 'legacy' ? ' bizc-theme-' . esc_attr($theme) : ''; ?>">
            <!-- Sidebar -->
            <div class="bizc-sidebar">
                <!-- Header with logo and collapse -->
                <div class="bizc-sidebar-header">
                    <span class="bizc-sidebar-logo"><?php echo esc_html($blog_name); ?></span>
                    <button class="bizc-sidebar-collapse" id="bizc-sidebar-collapse" title="Thu gọn sidebar">
                        <span class="dashicons dashicons-menu-alt3"></span>
                    </button>
                </div>
                
                <?php if ( ! $current_uid ) : ?>
                <!-- Guest Login Button -->
                <div class="bizc-guest-login-wrap">
                    <button class="bizc-guest-login-btn" id="bizc-guest-login-btn" type="button">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                            <polyline points="10 17 15 12 10 7"></polyline>
                            <line x1="15" y1="12" x2="3" y2="12"></line>
                        </svg>
                        Đăng nhập/Đăng ký
                    </button>
                </div>
                <?php endif; ?>
                
                <!-- Search Chat -->
                <div class="bizc-search-wrap">
                    <button class="bizc-search-btn" id="bizc-search-btn" type="button">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        <span>Tìm kiếm chat...</span>
                    </button>
                </div>
                
                <button class="bizc-new-chat-btn" id="bizc-new-chat">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    NEW CHAT
                </button>
                
                <!-- Projects (ChatGPT-style) -->
                <div class="bizc-section">
                    <div class="bizc-section-hdr">
                        <span>📁 DỰ ÁN</span>
                        <span class="bizc-proj-add-btn" id="bizc-add-project" title="Thêm dự án">＋</span>
                    </div>
                    <div class="bizc-proj-list" id="bizc-proj-list">
                        <!-- Loaded by JS -->
                    </div>
                </div>
                
                <!-- Recent Conversations (not in any project) -->
                <div class="bizc-section">
                    <div class="bizc-section-hdr">
                        <span>💬 Gần đây</span>
                        <span style="color:#ef4444;cursor:pointer;font-size:11px;" id="bizc-clear-all">Xóa hết</span>
                    </div>
                </div>
                <div class="bizc-convs" id="bizc-convs-list">
                    <!-- Loaded by JS -->
                </div>
                
                <!-- Intent Conversations (Tasks) -->
                <div class="bizc-section">
                    <div class="bizc-section-hdr">
                        <span>🎯 Nhiệm vụ</span>
                        <span style="color:#9ca3af;font-size:10px;margin-right:auto;margin-left:4px;" id="bizc-intent-count">0</span>
                        <span style="color:#ef4444;cursor:pointer;font-size:11px;" id="bizc-intent-clear-all">CLEAR ALL</span>
                    </div>
                </div>
                <div class="bizc-convs" id="bizc-intent-list" style="max-height:150px;">
                    <!-- Loaded by JS -->
                </div>
                
                <!-- Settings at bottom -->
                <div class="bizc-sidebar-footer">
                    <a href="<?php echo admin_url( '' ); ?>" class="bizc-settings-link">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M12 1v6m0 6v6m8.66-10l-5.2 3m-5.92 3.4l-5.2 3M20.66 19l-5.2-3m-5.92-3.4l-5.2-3"></path>
                        </svg>
                        Cấu hình & Settings
                    </a>
                </div>
            </div>
            
            <!-- Search Modal - ChatGPT style -->
            <div class="bizc-search-modal" id="bizc-search-modal">
                <div class="bizc-search-modal-content">
                    <div class="bizc-search-modal-header">
                        <input type="text" placeholder="Search chats..." id="bizc-search-input" autocomplete="off">
                        <button class="bizc-search-close" id="bizc-search-close" aria-label="Close">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>
                    <hr class="bizc-search-hr">
                    <div class="bizc-search-results" id="bizc-search-results">
                        <!-- Rendered by JS -->
                    </div>
                </div>
            </div>
            
            <!-- Main -->
            <div class="bizc-main">
                <!-- Mode Router Console (full width, compact) — dev admin only -->
                <div class="bizc-tools">
                    <div class="bizc-tool-card">
                        <div id="bizc-router-console" style="background:#1e1e2e;color:#cdd6f4;border-radius:14px;font-family:'JetBrains Mono',Consolas,monospace;font-size:11px;max-height:180px;display:flex;flex-direction:column;">
                            <!-- Tab Header -->
                            <div style="padding:6px 12px;background:#313244;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
                                <span style="display:flex;gap:2px;align-items:center;">
                                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#a6e3a1;margin-right:6px;vertical-align:middle;" id="bizc-poll-dot"></span>
                                    <button onclick="bizcSwitchLogTab('router')" id="bizc-tab-router" class="bizc-log-tab bizc-log-tab-active" style="background:#45475a;color:#89b4fa;border:none;padding:3px 10px;border-radius:4px 4px 0 0;cursor:pointer;font-size:10px;font-weight:600;">🧠 Tư duy</button>
                                </span>
                                <span style="display:flex;gap:4px;align-items:center;">
                                    <a href="<?php echo admin_url('admin.php?page=bccm_my_profile'); ?>" style="background:#45475a;color:#f9e2af;border:none;padding:3px 8px;border-radius:4px;font-size:10px;text-decoration:none;white-space:nowrap;" title="Cài hồ sơ & chiêm tinh">🌟 Hồ sơ</a>
                                    <button id="bizc-router-poll-btn" onclick="bizcRouterPoll(event)" style="background:#45475a;color:#cdd6f4;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="Start/Stop polling">‖ Stop</button>
                                    <button id="bizc-export-router-btn" onclick="bizcExportJSON('router', event)" style="background:#45475a;color:#89b4fa;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="Export router logs">📋 Export JSON</button>
                                    <button onclick="bizcRouterClear(event)" style="background:#45475a;color:#cdd6f4;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="Clear logs">🗑 Clear</button>
                                    <button onclick="bizcRouterFullscreen(event)" id="bizc-fs-btn" style="background:#45475a;color:#cdd6f4;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="Phóng to / Thu nhỏ">⛶ Expand</button>
                                </span>
                            </div>
                            <!-- Router Log Panel -->
                            <div id="bizc-router-logs" style="padding:8px 10px;overflow-y:auto;flex:1;min-height:40px;">
                                <div style="color:#6c7086;">Nhấn Poll hoặc gửi tin nhắn để xem log nhận diện...</div>
                            </div>


                        </div>
                    </div>
                </div>

                <style>
                    /* Router Console Styles */
                    #bizc-router-console .bizc-rlog { padding:5px 0; border-bottom:1px solid #313244; }
                    #bizc-router-console .bizc-rlog:last-child { border-bottom:none; }
                    #bizc-router-console .bizc-rlog-header { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
                    #bizc-router-console .bizc-rlog-step { color:#f38ba8; font-weight:700; font-size:10px; background:#45475a; padding:1px 6px; border-radius:3px; }
                    #bizc-router-console .bizc-rlog-mode { color:#a6e3a1; font-weight:700; }
                    #bizc-router-console .bizc-rlog-conf { color:#fab387; }
                    #bizc-router-console .bizc-rlog-method { color:#89dceb; font-style:italic; }
                    #bizc-router-console .bizc-rlog-time { color:#6c7086; font-size:9px; }
                    #bizc-router-console .bizc-rlog-ms { color:#f9e2af; font-size:9px; }
                    #bizc-router-console .bizc-rlog-detail { color:#9399b2; margin:2px 0 2px 16px; font-size:10px; line-height:1.4; }
                    #bizc-router-console .bizc-rlog-pipeline { color:#cba6f7; margin:2px 0 2px 16px; font-size:10px; }
                    #bizc-router-console .bizc-rlog-fn { color:#74c7ec; margin:2px 0 2px 16px; font-size:10px; }
                    #bizc-router-console .bizc-rlog-prompt { display:none; } /* hidden by default */
                    #bizc-router-console .bizc-rlog-response { color:#a6e3a1; margin:2px 0 2px 16px; font-size:10px; }
                    #bizc-router-console .bizc-rlog-memory { color:#f5c2e7; margin:2px 0 2px 16px; font-size:10px; }
                    #bizc-router-console .bizc-rlog-context { color:#94e2d5; margin:2px 0 2px 16px; font-size:10px; }
                    #bizc-router-console .bizc-rlog-line { color:#585b70; margin:1px 0 1px 16px; font-size:9px; font-style:italic; }
                    #bizc-router-console .bizc-rlog-collapse { margin:4px 0 4px 16px; }
                    #bizc-router-console .bizc-rlog-collapse-btn { background:#313244; color:#89b4fa; border:1px solid #45475a; border-radius:3px; padding:2px 8px; font-size:10px; cursor:pointer; font-family:monospace; }
                    #bizc-router-console .bizc-rlog-collapse-btn:hover { background:#45475a; color:#f9e2af; }
                    #bizc-router-console .bizc-rlog-error { color:#f38ba8; margin:2px 0 2px 16px; font-size:10px; }

                    /* Message group */
                    #bizc-router-console .bizc-rlog-group { border-left:2px solid #89b4fa; margin:4px 0; padding-left:8px; }
                    #bizc-router-console .bizc-rlog-group-header { color:#f9e2af; font-weight:700; font-size:11px; padding:3px 0; cursor:pointer; user-select:none; display:flex; align-items:center; gap:6px; }
                    #bizc-router-console .bizc-rlog-group-header:hover { color:#fab387; }
                    #bizc-router-console .bizc-rlog-group-body { }
                    #bizc-router-console .bizc-rlog-group.collapsed .bizc-rlog-group-body { display:none; }

                    /* Expanded dialog mode — 50% width centered */
                    .bizc-router-expanded {
                        position: fixed !important;
                        top: 50% !important;
                        left: 50% !important;
                        transform: translate(-50%, -50%) !important;
                        width: 50vw !important;
                        max-width: 800px !important;
                        min-width: 400px !important;
                        height: 70vh !important;
                        z-index: 99999 !important;
                        border-radius: 14px !important;
                        box-shadow: 0 8px 40px rgba(0,0,0,.6) !important;
                    }
                    .bizc-router-expanded #bizc-router-logs {
                        max-height: none !important;
                        font-size: 12px !important;
                    }
                    .bizc-router-expanded .bizc-rlog-prompt {
                        display: block !important;
                        color:#89dceb; margin:3px 0 2px 16px; white-space:pre-wrap; max-height:200px; overflow-y:auto; font-size:10px; background:#181825; padding:4px 6px; border-radius:4px; border-left:2px solid #89b4fa;
                    }
                    .bizc-router-overlay {
                        position: fixed; top:0; left:0; right:0; bottom:0;
                        background: rgba(0,0,0,.45);
                        z-index: 99998;
                    }
                    .bizc-log-tab { transition: background .2s, color .2s; }
                    .bizc-log-tab:hover { background:#45475a !important; color:#cdd6f4 !important; }
                    
                    /* ===== REMOVED PLUGIN PILLS - KEEPING ONLY @ MENTIONS ===== */
                    /* Pills system removed for simplicity, following ChatGPT clean design */
                    
                    /* ===== @ MENTION AUTOCOMPLETE DROPDOWN - ENHANCED ===== */
                    .bizc-mention-dropdown {
                        position: absolute;
                        bottom: 100%;
                        left: 0;
                        right: 0;
                        max-height: 300px;
                        background: #fff;
                        border: 1px solid #e5e7eb;
                        border-radius: 12px;
                        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
                        z-index: 1000;
                        display: none;
                        overflow: hidden;
                        margin-bottom: 8px;
                    }
                    
                    .bizc-mention-dropdown.active {
                        display: block;
                    }
                    
                    .bizc-mention-header {
                        padding: 8px 12px 6px;
                        font-size: 11px;
                        font-weight: 600;
                        color: #6b7280;
                        background: #f9fafb;
                        border-bottom: 1px solid #e5e7eb;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    
                    .bizc-mention-footer {
                        padding: 6px 12px 8px;
                        font-size: 10px;
                        color: #9ca3af;
                        background: #f9fafb;
                        border-top: 1px solid #f3f4f6;
                        text-align: center;
                    }
                    
                    .bizc-mention-item {
                        display: flex;
                        align-items: flex-start;
                        padding: 8px 12px;
                        cursor: pointer;
                        transition: background 0.15s ease;
                        border-bottom: 1px solid #f3f4f6;
                        gap: 10px;
                    }
                    
                    .bizc-mention-item:last-of-type {
                        border-bottom: none;
                    }
                    
                    .bizc-mention-item:hover,
                    .bizc-mention-item.selected {
                        background: rgba(99, 102, 241, 0.08);
                    }
                    
                    .bizc-mention-icon {
                        width: 28px;
                        height: 28px;
                        border-radius: 8px;
                        object-fit: cover;
                        flex-shrink: 0;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        background: rgba(99, 102, 241, 0.1);
                        font-size: 14px;
                    }
                    
                    .bizc-mention-icon img {
                        width: 100%;
                        height: 100%;
                        border-radius: 6px;
                        object-fit: cover;
                    }
                    
                    .bizc-mention-content {
                        flex: 1;
                        min-width: 0;
                    }
                    
                    .bizc-mention-name {
                        font-weight: 600;
                        font-size: 13px;
                        color: #1a1a2e;
                        margin-bottom: 2px;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                    }
                    
                    .bizc-mention-slug {
                        font-size: 11px;
                        color: #6366f1;
                        font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
                        margin-bottom: 3px;
                    }
                    
                    .bizc-mention-desc {
                        font-size: 10px;
                        color: #6b7280;
                        line-height: 1.3;
                        margin-top: 2px;
                    }
                    
                    /* Selected mention tag (enhanced) */
                    .bizc-mention-tag {
                        display: none;
                        align-items: center;
                        gap: 6px;
                        padding: 4px 8px;
                        margin-bottom: 6px;
                        background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
                        border: 1px solid rgba(99, 102, 241, 0.3);
                        border-radius: 20px;
                        font-size: 12px;
                        font-weight: 500;
                        color: #6366f1;
                    }
                    
                    .bizc-mention-tag img {
                        width: 16px;
                        height: 16px;
                        border-radius: 4px;
                        object-fit: cover;
                    }
                    
                    .bizc-mt-remove {
                        margin-left: 4px;
                        cursor: pointer;
                        opacity: 0.7;
                        transition: opacity 0.2s;
                        font-weight: bold;
                    }
                    
                    .bizc-mt-remove:hover {
                        opacity: 1;
                        color: #dc2626;
                    }
                    
                    /* Mobile mention dropdown */
                    @media (max-width: 768px) {
                        .bizc-mention-dropdown {
                            max-height: 250px;
                            border-radius: 10px;
                        }
                        
                        .bizc-mention-item {
                            padding: 10px;
                        }
                        
                        .bizc-mention-icon {
                            width: 24px;
                            height: 24px;
                        }
                        
                        .bizc-mention-name {
                            font-size: 14px;
                        }
                    }
                    
                    /* ===== PLUGIN CONTEXT MODE UI/UX - HORIZONTAL LAYOUT ===== */
                    /* When a plugin is selected, show visual context indicators */
                    .bizc-input-area.plugin-context-mode {
                        background: linear-gradient(135deg, rgba(99, 102, 241, 0.02), rgba(139, 92, 246, 0.02));
                        border: 1px solid rgba(99, 102, 241, 0.15);
                        border-radius: 12px;
                        padding: 8px;
                        transition: all 0.3s ease;
                    }
                    
                    .bizc-input-area.plugin-context-mode::before {
                        content: '';
                        position: absolute;
                        top: -1px;
                        left: -1px;
                        right: -1px;
                        bottom: -1px;
                        background: linear-gradient(45deg, #6366f1, #8b5cf6, #ec4899, #f59e0b);
                        border-radius: 12px;
                        z-index: -1;
                        opacity: 0.1;
                        animation: pluginContextGlow 3s ease-in-out infinite alternate;
                    }
                    
                    @keyframes pluginContextGlow {
                        0% { opacity: 0.05; }
                        100% { opacity: 0.15; }
                    }
                    
                    /* Plugin context header - single row with icon + tool chips + close */
                    .bizc-plugin-context-header {
                        display: none !important;
                        flex-direction: row;
                        align-items: center;
                        gap: 6px;
                        padding: 4px 8px !important;
                        margin: 0 0 6px 0 !important;
                        background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(139, 92, 246, 0.08)) !important;
                        border: 1px solid rgba(99, 102, 241, 0.15) !important;
                        border-radius: 8px !important;
                        font-size: 11px !important;
                        font-weight: 600;
                        line-height: 1.4 !important;
                        text-transform: none !important;
                    }
                    
                    .bizc-plugin-context-header.active {
                        display: flex !important;
                    }
                    
                    .bizc-context-tools-row {
                        display: flex;
                        gap: 4px;
                        flex: 1;
                        min-width: 0;
                        overflow-x: auto;
                        scrollbar-width: none;
                        -ms-overflow-style: none;
                        align-items: center;
                    }
                    .bizc-context-tools-row::-webkit-scrollbar { display: none; }
                    
                    .bizc-tool-chip {
                        display: inline-flex;
                        align-items: center;
                        gap: 3px;
                        padding: 2px 8px;
                        background: rgba(255,255,255,0.8);
                        border: 1px solid rgba(99, 102, 241, 0.2);
                        border-radius: 12px;
                        font-size: 10px !important;
                        font-weight: 500;
                        color: #4f46e5;
                        cursor: pointer;
                        white-space: nowrap;
                        transition: all 0.15s ease;
                        flex-shrink: 0;
                    }
                    .bizc-tool-chip:hover {
                        background: rgba(99, 102, 241, 0.12);
                        border-color: #6366f1;
                    }
                    .bizc-tool-chip.active {
                        background: #6366f1;
                        color: #fff;
                        border-color: #6366f1;
                    }
                    
                    .bizc-context-plugin-icon {
                        width: 16px !important;
                        height: 16px !important;
                        border-radius: 3px;
                        object-fit: cover;
                        flex-shrink: 0;
                    }
                    
                    .bizc-context-close-btn {
                        background: rgba(239, 68, 68, 0.1) !important;
                        border: 1px solid rgba(239, 68, 68, 0.2) !important;
                        border-radius: 4px !important;
                        padding: 1px 4px !important;
                        color: #ef4444 !important;
                        cursor: pointer;
                        font-size: 9px !important;
                        line-height: 1.4 !important;
                        text-transform: none !important;
                        transition: all 0.2s ease;
                        flex-shrink: 0;
                    }
                    
                    .bizc-context-close-btn:hover {
                        background: rgba(239, 68, 68, 0.2);
                        border-color: rgba(239, 68, 68, 0.4);
                    }
                    
                    /* Input styling in context mode */
                    .bizc-input-area.plugin-context-mode .bizc-input {
                        border-color: rgba(99, 102, 241, 0.2);
                        background: rgba(255, 255, 255, 0.9);
                    }
                    
                    .bizc-input-area.plugin-context-mode .bizc-input:focus {
                        border-color: #6366f1;
                        box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.1);
                    }
                    
                    /* Send button in context mode */
                    .bizc-input-area.plugin-context-mode .bizc-send-btn {
                        background: linear-gradient(135deg, #6366f1, #8b5cf6);
                        border-color: #6366f1;
                    }
                    
                    .bizc-input-area.plugin-context-mode .bizc-send-btn:hover {
                        background: linear-gradient(135deg, #5a67d8, #7c3aed);
                        transform: translateY(-1px);
                        box-shadow: 0 2px 6px rgba(99, 102, 241, 0.3);
                    }
                    
                    /* Plugin Pills context mode styling - horizontal */
                    .plugin-context-mode .bizc-plugin-pills {
                        background: rgba(99, 102, 241, 0.06);
                        border-color: rgba(99, 102, 241, 0.2);
                        box-shadow: 0 1px 4px rgba(99, 102, 241, 0.1);
                    }
                    
                    /* Breathing animation for active pill in context mode */
                    .plugin-context-mode .bizc-pill.active {
                        animation: pillContextBreathe 2s ease-in-out infinite;
                    }
                    
                    @keyframes pillContextBreathe {
                        0%, 100% { 
                            transform: scale(1);
                            box-shadow: 0 1px 3px rgba(99, 102, 241, 0.2);
                        }
                        50% { 
                            transform: scale(1.03);
                            box-shadow: 0 2px 6px rgba(99, 102, 241, 0.3);
                        }
                    }
                    
                    /* ===== DUAL-PATH ROUTING INDICATORS ===== */
                    .bizc-routing-indicator {
                        font-size: 10px;
                        color: #9ca3af;
                        margin-top: 4px;
                        padding: 2px 6px;
                        background: rgba(156, 163, 175, 0.1);
                        border-radius: 8px;
                        text-align: center;
                        font-weight: 500;
                    }
                    
                    /* Routing mode badges in message bubbles */
                    .bizc-msg-routing-badge {
                        display: inline-block;
                        font-size: 9px;
                        padding: 1px 4px;
                        border-radius: 4px;
                        margin-left: 6px;
                        font-weight: 600;
                        vertical-align: middle;
                    }
                    
                    .bizc-msg-routing-badge.manual {
                        background: rgba(99, 102, 241, 0.2);
                        color: #6366f1;
                    }
                    
                    .bizc-msg-routing-badge.automatic {
                        background: rgba(34, 197, 94, 0.2);
                        color: #22c55e;
                    }
                    
                    /* Success routing confirmation */
                    .bizc-routing-success {
                        display: flex;
                        align-items: center;
                        gap: 6px;
                        padding: 6px 10px;
                        margin: 4px 0;
                        background: rgba(34, 197, 94, 0.1);
                        border: 1px solid rgba(34, 197, 94, 0.2);
                        border-radius: 8px;
                        font-size: 11px;
                        color: #22c55e;
                        animation: routingFadeIn 0.3s ease-in;
                    }
                    
                    @keyframes routingFadeIn {
                        from { opacity: 0; transform: translateY(-5px); }
                        to { opacity: 1; transform: translateY(0); }
                    }
                    
                    .bizc-routing-success-icon {
                        width: 14px;
                        height: 14px;
                        background: #22c55e;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: #fff;
                        font-size: 8px;
                        flex-shrink: 0;
                    }

                </style>
                <script>
                /* ── Ensure ajaxurl is available on frontend ── */
                if (typeof ajaxurl === 'undefined') {
                    var ajaxurl = '<?php echo admin_url("admin-ajax.php"); ?>';
                }
                /* ── Console session must match the chat session so poll reads the correct transient ── */
                window.bizcSessionId = '<?php echo esc_js( $session_id ); ?>';
                
                // Initialize caches for Plugin Suggestion API
                window.bizcMentionAgentsCache = null;
                window.bizcMentionAgentsCacheTime = 0;
                
                var _bizcRouterInterval = null;
                var _bizcIsFullscreen = false;
                var _bizcRouterRawLogs = []; /* raw log data for export */

                function bizcRouterPoll(e) {
                    if (e) e.stopPropagation();
                    var btn = document.getElementById('bizc-router-poll-btn');
                    var dot = document.getElementById('bizc-poll-dot');
                    if (_bizcRouterInterval) {
                        clearInterval(_bizcRouterInterval);
                        _bizcRouterInterval = null;
                        btn.textContent = '▶ Poll';
                        dot.style.background = '#a6e3a1';
                        return;
                    }
                    btn.textContent = '⏸ Stop';
                    dot.style.background = '#f38ba8';
                    dot.style.animation = 'pulse 1s infinite';
                    _fetchAllLogs();
                    _bizcRouterInterval = setInterval(_fetchAllLogs, 2000);
                }

                /* ── Fetch Router logs ── */
                function _fetchAllLogs() {
                    _fetchRouterLogs();
                }

                function bizcRouterClear(e) {
                    if (e) e.stopPropagation();
                    document.getElementById('bizc-router-logs').innerHTML = '<div style="color:#6c7086;">Cleared.</div>';
                    _bizcRouterRawLogs = [];
                }

                /* ── Log state ── */
                var _bizcCurrentLogTab = 'router';

                /* ── Tab Switching ── */
                function bizcSwitchLogTab(tab) {
                    _bizcCurrentLogTab = tab;
                    var tabs = {
                        router:   document.getElementById('bizc-tab-router')
                    };
                    var panels = {
                        router:   document.getElementById('bizc-router-logs')
                    };
                    var exportBtn = document.getElementById('bizc-export-router-btn');

                    // Deactivate all tabs + hide all panels
                    Object.keys(tabs).forEach(function(k) {
                        if (tabs[k]) { tabs[k].style.background = 'transparent'; tabs[k].style.color = '#6c7086'; }
                        if (panels[k]) panels[k].style.display = 'none';
                    });

                    // Activate selected
                    if (tabs[tab]) { tabs[tab].style.background = '#45475a'; tabs[tab].style.color = '#89b4fa'; }
                    if (panels[tab]) panels[tab].style.display = 'block';

                    // Tab-specific actions
                    if (tab === 'console') {
                        if (exportBtn) { exportBtn.onclick = function(e) { bizcExportJSON('console', e); }; exportBtn.title = 'Export console logs'; }
                    } else {
                        if (exportBtn) { exportBtn.onclick = function(e) { bizcExportJSON('router', e); }; exportBtn.title = 'Export router logs'; }
                    }
                }

                /* ── Nonce for AJAX calls ── */
                var _bizcChatNonce = '<?php echo wp_create_nonce("bizcity_chat"); ?>';
                /* ── Fetch Execution Logs ── */
                function _fetchExecLogs() {
                    var pollSessionId = window.bizcCurrentSessionId || window.bizcSessionId || '';
                    jQuery.post(ajaxurl, {
                        action: 'bizcity_poll_execution_log',
                        nonce: '<?php echo wp_create_nonce("bizcity_chat"); ?>',
                        session_id: pollSessionId
                    }, function(r) {
                        if (!r.success) return;
                        var logs = r.data.logs || [];
                        var stats = r.data.stats || {};
                        _bizcExecRawLogs = logs;

                        if (!logs.length) {
                            document.getElementById('bizc-exec-logs').innerHTML = 
                                '<div style="color:#6c7086;">Chưa có log thực thi. Tool sẽ ghi log khi được gọi.</div>';
                            return;
                        }

                        // Render execution logs
                        var html = '';
                        
                        // Stats header
                        if (stats.tools_invoked > 0) {
                            html += '<div style="color:#89b4fa;padding:4px 0;margin-bottom:6px;border-bottom:1px solid #313244;">';
                            html += '📊 <strong>' + stats.tools_invoked + '</strong> tools called';
                            if (stats.tools_succeeded > 0) html += ' • <span style="color:#a6e3a1;">✓ ' + stats.tools_succeeded + '</span>';
                            if (stats.tools_failed > 0) html += ' • <span style="color:#f38ba8;">✗ ' + stats.tools_failed + '</span>';
                            if (stats.errors > 0) html += ' • <span style="color:#f38ba8;">⚠ ' + stats.errors + ' errors</span>';
                            html += '</div>';
                        }

                        // Render each log entry
                        logs.forEach(function(log) {
                            html += _renderExecLogEntry(log);
                        });

                        document.getElementById('bizc-exec-logs').innerHTML = html;
                    });
                }

                /* ── Render single execution log entry ── */
                function _renderExecLogEntry(log) {
                    var h = '<div class="bizc-elog">';
                    var step = log.step || 'unknown';
                    var time = (log.timestamp || '').replace(/^\d{4}-\d{2}-\d{2}\s/, '');
                    
                    // Step badge with color
                    var stepColors = {
                        'pipeline_start': '#cba6f7',
                        'pipeline_step': '#89b4fa',
                        'pipeline_complete': '#a6e3a1',
                        'tool_invoke': '#f9e2af',
                        'tool_result': '#a6e3a1',
                        'tool_step': '#fab387',
                        'slot_resolve': '#94e2d5',
                        'goal_update': '#f5c2e7',
                        'error': '#f38ba8'
                    };
                    var stepColor = stepColors[step] || '#cdd6f4';
                    
                    h += '<div class="bizc-elog-header">';
                    h += '<span class="bizc-elog-step" style="color:' + stepColor + ';">' + _esc(step) + '</span>';
                    h += '<span class="bizc-elog-time">' + time + '</span>';
                    
                    // Duration
                    if (log.duration_ms) {
                        h += '<span class="bizc-rlog-ms">' + log.duration_ms + 'ms</span>';
                    }
                    h += '</div>';

                    // Content based on step type
                    switch (step) {
                        case 'pipeline_start':
                            h += '<div class="bizc-elog-detail">🚀 Template: <strong>' + _esc(log.template || 'unknown') + '</strong></div>';
                            if (log.steps && log.steps.length) {
                                h += '<div class="bizc-elog-detail">Steps: ' + log.steps.map(_esc).join(' → ') + '</div>';
                            }
                            break;

                        case 'tool_invoke':
                            h += '<div class="bizc-elog-detail">🔧 <strong style="color:#f9e2af;">' + _esc(log.tool_name) + '</strong>';
                            if (log.source) h += ' <span style="color:#6c7086;">(' + log.source + ')</span>';
                            h += '</div>';
                            if (log.params && typeof log.params === 'object' && !log.params._truncated) {
                                h += '<div class="bizc-elog-detail" style="color:#89dceb;">Params: ' + _esc(JSON.stringify(log.params).substring(0, 200)) + '</div>';
                            }
                            break;

                        case 'tool_result':
                            var icon = log.success ? '✅' : '❌';
                            var resultColor = log.success ? '#a6e3a1' : '#f38ba8';
                            h += '<div class="bizc-elog-detail">' + icon + ' <strong style="color:' + resultColor + ';">' + _esc(log.tool_name) + '</strong></div>';
                            if (log.message) {
                                h += '<div class="bizc-elog-detail" style="color:#cdd6f4;">' + _esc(log.message.substring(0, 150)) + '</div>';
                            }
                            if (log.data_type) {
                                h += '<div class="bizc-elog-detail" style="color:#94e2d5;">data.type: ' + _esc(log.data_type) + '</div>';
                            }
                            if (log.data_id) {
                                h += '<div class="bizc-elog-detail" style="color:#94e2d5;">data.id: ' + _esc(log.data_id) + '</div>';
                            }
                            break;

                        case 'tool_step':
                            var tsIcon = log.status === 'success' ? '✅' : (log.status === 'error' ? '❌' : (log.status === 'skipped' ? '⏭' : '⋯'));
                            var tsColor = log.status === 'success' ? '#a6e3a1' : (log.status === 'error' ? '#f38ba8' : '#fab387');
                            h += '<div class="bizc-elog-detail">' + tsIcon + ' <span style="color:' + tsColor + ';font-weight:bold;">' + _esc(log.sub_step || log.step_name || '—') + '</span>';
                            if (log.status) h += ' <span style="color:#6c7086;">(' + _esc(log.status) + ')</span>';
                            h += '</div>';
                            if (log.title) {
                                h += '<div class="bizc-elog-detail" style="color:#cdd6f4;">📝 ' + _esc(log.title.substring(0, 120)) + '</div>';
                            }
                            if (log.content_len) {
                                h += '<div class="bizc-elog-detail" style="color:#89dceb;">' + log.content_len + ' chars generated</div>';
                            }
                            if (log.post_id) {
                                h += '<div class="bizc-elog-detail" style="color:#94e2d5;">post_id: ' + log.post_id;
                                if (log.url) h += ' · <a href="' + _esc(log.url) + '" target="_blank" style="color:#89b4fa;">view</a>';
                                h += '</div>';
                            }
                            if (log.message && log.status === 'error') {
                                h += '<div class="bizc-elog-detail" style="color:#f38ba8;">' + _esc(log.message.substring(0, 150)) + '</div>';
                            }
                            break;

                        case 'pipeline_complete':
                            var statusIcon = log.status === 'success' ? '✅' : (log.status === 'partial' ? '⚠️' : '❌');
                            h += '<div class="bizc-elog-detail">' + statusIcon + ' Status: <strong>' + _esc(log.status) + '</strong></div>';
                            if (log.duration_ms) {
                                h += '<div class="bizc-elog-detail">Total: ' + log.duration_ms + 'ms</div>';
                            }
                            break;

                        case 'goal_update':
                            h += '<div class="bizc-elog-detail">🎯 Goal: <strong>' + _esc(log.goal_id) + '</strong> → ' + _esc(log.status) + '</div>';
                            if (log.missing_info && log.missing_info.length) {
                                h += '<div class="bizc-elog-detail">Missing: ' + log.missing_info.join(', ') + '</div>';
                            }
                            break;

                        case 'error':
                            h += '<div class="bizc-elog-detail" style="color:#f38ba8;">⚠️ ' + _esc(log.error_type) + ': ' + _esc(log.message) + '</div>';
                            break;

                        default:
                            h += '<div class="bizc-elog-detail">' + _esc(JSON.stringify(log).substring(0, 200)) + '</div>';
                    }

                    h += '</div>';
                    return h;
                }

                function bizcExportJSON(type, e) {
                    if (e) e.stopPropagation();
                    var logs, filename, label;
                    logs = _bizcRouterRawLogs || [];
                    filename = 'router-logs';
                    label = 'Tư duy';
                    if (!logs.length) {
                        alert('Chưa có log ' + label + '. Hãy Poll trước.');
                        return;
                    }
                    var json = JSON.stringify(logs, null, 2);
                    // Copy to clipboard
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(json).then(function() {
                            _bizcRouterExportNotify('✅ Copied ' + label + '! (' + logs.length + ' logs)');
                        }).catch(function() {
                            _bizcExportFallback(json, filename);
                        });
                    } else {
                        _bizcExportFallback(json, filename);
                    }
                }

                // Backward compat
                function bizcRouterExportJSON(e) {
                    bizcExportJSON('router', e);
                }

                function _bizcExportFallback(json, filename) {
                    // Fallback: download as file
                    var blob = new Blob([json], {type: 'application/json'});
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = (filename || 'logs') + '-' + new Date().toISOString().slice(0,19).replace(/:/g,'-') + '.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    _bizcRouterExportNotify('📥 Downloaded JSON file');
                }

                // Backward compat
                function _bizcRouterExportFallback(json) {
                    _bizcExportFallback(json, 'router-logs');
                }

                function _bizcRouterExportNotify(msg) {
                    var el = document.createElement('div');
                    el.textContent = msg;
                    el.style.cssText = 'position:fixed;top:20px;right:20px;background:#313244;color:#a6e3a1;padding:8px 16px;border-radius:8px;font-size:12px;z-index:999999;font-family:monospace;box-shadow:0 4px 12px rgba(0,0,0,.4);transition:opacity .3s;';
                    document.body.appendChild(el);
                    setTimeout(function(){ el.style.opacity='0'; }, 2000);
                    setTimeout(function(){ el.remove(); }, 2500);
                }

                function bizcRouterFullscreen(e) {
                    if (e) e.stopPropagation();
                    var el = document.getElementById('bizc-router-console');
                    var btn = document.getElementById('bizc-fs-btn');
                    _bizcIsFullscreen = !_bizcIsFullscreen;
                    if (_bizcIsFullscreen) {
                        // Move console to body so no parent overflow:hidden clips it
                        el._origParent = el.parentNode;
                        el._origMaxH = el.style.maxHeight;
                        // Overlay
                        var ov = document.createElement('div');
                        ov.className = 'bizc-router-overlay';
                        ov.id = 'bizc-router-overlay';
                        ov.onclick = function(){ bizcRouterFullscreen(null); };
                        document.body.appendChild(ov);
                        // Move to body + expand
                        document.body.appendChild(el);
                        el.style.maxHeight = 'none';
                        el.classList.add('bizc-router-expanded');
                        btn.textContent = '⛶ Collapse';
                    } else {
                        // Remove overlay
                        var ov = document.getElementById('bizc-router-overlay');
                        if (ov) ov.remove();
                        // Restore to original parent
                        el.classList.remove('bizc-router-expanded');
                        el.style.maxHeight = el._origMaxH || '180px';
                        if (el._origParent) el._origParent.appendChild(el);
                        btn.textContent = '⛶ Expand';
                    }
                }

                function _renderLogEntry(log) {
                    var h = '<div class="bizc-rlog">';
                    h += '<div class="bizc-rlog-header">';
                    h += '<span class="bizc-rlog-step">' + (log.step || 'event') + '</span>';
                    h += '<span class="bizc-rlog-mode">[' + (log.mode || '?') + ']</span>';
                    if (log.confidence) h += '<span class="bizc-rlog-conf">conf=' + log.confidence + '</span>';
                    if (log.method) h += '<span class="bizc-rlog-method">via ' + log.method + '</span>';
                    var ms = log.mode_ms || log.classify_ms || log.duration_ms || log.profile_ms || log.transit_ms || log.build_ms || log.context_ms || log.chain_ms || log.search_ms;
                    if (ms) {
                        h += '<span class="bizc-rlog-ms">' + ms + 'ms</span>';
                    }
                    h += '<span class="bizc-rlog-time">' + (log.timestamp || '').replace(/^\d{4}-\d{2}-\d{2}\s/, '') + '</span>';
                    h += '</div>';
                    if (log.pipeline) {
                        var steps = Array.isArray(log.pipeline) ? log.pipeline : [log.pipeline];
                        h += '<div class="bizc-rlog-pipeline">📋 ' + steps.map(function(s,i){
                            return '<span style="background:#313244;padding:1px 5px;border-radius:3px;margin-right:2px;">'+(i+1)+'. '+_esc(s)+'</span>';
                        }).join(' → ') + '</div>';
                    }
                    if (log.functions_called) h += '<div class="bizc-rlog-fn">⚙️ ' + _esc(log.functions_called) + '</div>';
                    if (log.file_line) h += '<div class="bizc-rlog-line">📍 ' + _esc(log.file_line) + '</div>';
                    if (log.memory_count !== undefined) h += '<div class="bizc-rlog-memory">🧠 Memory: ' + log.memory_count + ' items (user=' + (log.memory_user_id||'?') + ')</div>';
                    // Layer 6: Plugin context (profile, transit, knowledge)
                    if (log.step === 'context_build' && log.context_length) {
                        h += '<div class="bizc-rlog-context">📚 L6 Base: ' + log.context_length + ' chars (profile=' + (log.has_profile?'✓':'✗') + ' transit=' + (log.has_transit?'✓':'✗') + ' knowledge=' + (log.has_knowledge?'✓':'✗') + ')</div>';
                        if (log.profile_preview) h += '<div class="bizc-rlog-preview">👤 ' + _esc(log.profile_preview).substring(0,150) + '</div>';
                        if (log.transit_preview) h += '<div class="bizc-rlog-preview">⭐ ' + _esc(log.transit_preview).substring(0,150) + '</div>';
                    }
                    // Layer 2-5: Context chain (intent, session, cross, project)
                    else if (log.step === 'context_chain' && log.context_length) {
                        h += '<div class="bizc-rlog-context">🔗 Chain: ' + log.context_length + ' chars (intent=' + (log.has_intent?'✓':'✗') + ' session=' + (log.has_session?'✓':'✗') + ' cross=' + (log.has_cross?'✓':'✗') + ' project=' + (log.has_project?'✓':'✗') + ')</div>';
                    }
                    // BizCoach context injection at priority 95
                    else if (log.step === 'bizcoach_inject') {
                        h += '<div class="bizc-rlog-context">🌟 <b>BizCoach Injected</b> (pri ' + (log.priority||95) + '): profile=' + (log.has_profile?'✓':'✗') + ' (' + (log.profile_length||0) + ' chars) | transit=' + (log.has_transit?'✓':'✗') + ' (' + (log.transit_length||0) + ' chars) → ' + (log.total_injection||0) + ' chars total</div>';
                    }
                    // BizCoach precheck (debug - shows if profile/transit available before filter)
                    else if (log.step === 'bizcoach_precheck') {
                        var willIcon = log.will_inject ? '✅' : '⛔';
                        h += '<div class="bizc-rlog-context">🔍 <b>BizCoach Pre-check</b>: profile=' + (log.has_profile?'✓':'✗') + ' (' + (log.profile_length||0) + ') transit=' + (log.has_transit?'✓':'✗') + ' (' + (log.transit_length||0) + ') → will_inject=' + willIcon + '</div>';
                    }
                    // 💓 INTENSITY DETECTION — emotional routing decision
                    else if (log.step === 'intensity_detect') {
                        var intLvl = log.intensity || 1;
                        var intColors = ['#6c7086','#89dceb','#a6e3a1','#f9e2af','#fab387','#f38ba8'];
                        var intLabels = ['neutral','calm','mild','moderate','high','critical'];
                        var intColor = intColors[Math.min(intLvl, 5)];
                        var intLabel = intLabels[Math.min(intLvl, 5)];
                        var empIcon = log.empathy_flag ? '💛' : '🤍';
                        var branchIcons = {execution:'⚡',knowledge:'📚',reflection:'🪞',emotion_low:'💬',emotion_high:'💓',emotion_critical:'🚨'};
                        var branchIcon = branchIcons[log.routing_branch] || '🔀';
                        h += '<div class="bizc-rlog-context">💓 <b>Intensity</b>: ';
                        h += '<span style="display:inline-block;width:60px;height:8px;background:#313244;border-radius:4px;overflow:hidden;vertical-align:middle;margin:0 6px;">';
                        h += '<span style="display:block;width:' + (intLvl*20) + '%;height:100%;background:' + intColor + ';"></span></span>';
                        h += '<span style="color:' + intColor + ';font-weight:700;">' + intLvl + '/5 (' + intLabel + ')</span> ';
                        h += empIcon + ' empathy=' + (log.empathy_flag?'ON':'off') + ' ';
                        h += branchIcon + ' <span style="color:#cba6f7;font-weight:700;">' + (log.routing_branch||'?') + '</span>';
                        if (log.intensity_ms) h += ' <span style="color:#6c7086;font-size:9px;">' + log.intensity_ms + 'ms</span>';
                        h += '</div>';
                    }
                    // 🎀 EMOTIONAL SMOOTHING — wrap tool ask with empathy
                    else if (log.step === 'emotional_smooth') {
                        h += '<div class="bizc-rlog-context">🎀 <b>Emotional Smooth</b> (intensity=' + (log.intensity||'?') + '):</div>';
                        if (log.raw_prompt) {
                            h += '<div class="bizc-rlog-detail" style="color:#f38ba8;">❌ Raw: "' + _esc(log.raw_prompt).substring(0,100) + '"</div>';
                        }
                        if (log.smoothed_prompt) {
                            h += '<div class="bizc-rlog-detail" style="color:#a6e3a1;">✅ Smoothed: "' + _esc(log.smoothed_prompt).substring(0,150) + '"</div>';
                        }
                    }
                    // 📋 FINAL PROMPT — collapsible textarea showing full system prompt
                    else if (log.step === 'final_prompt') {
                        var chkBiz = log.has_bizcoach ? '✅' : '❌';
                        var chkMem = log.has_memory ? '✅' : '❌';
                        var chkCtx = log.has_context_chain ? '✅' : '❌';
                        h += '<div class="bizc-rlog-context">📋 <b>Final Prompt</b>: ' + (log.prompt_length||0) + ' chars (~' + (log.word_count||0) + ' words) | BizCoach=' + chkBiz + ' Memory=' + chkMem + ' Context=' + chkCtx + '</div>';
                        // Collapsible head preview
                        if (log.prompt_head) {
                            h += '<div class="bizc-rlog-prompt-preview">';
                            h += '<div class="bizc-rlog-preview" style="max-height:60px;overflow:hidden;">📝 ' + _esc(log.prompt_head).substring(0,300) + '</div>';
                            h += '</div>';
                        }
                        // Full prompt in collapsible textarea
                        if (log.full_prompt) {
                            var uid = 'fp_' + Date.now() + '_' + Math.random().toString(36).substr(2,5);
                            h += '<div class="bizc-rlog-collapse">';
                            h += '<button type="button" class="bizc-rlog-collapse-btn" onclick="var t=document.getElementById(\'' + uid + '\');var b=this;if(t.style.display===\'none\'){t.style.display=\'block\';b.textContent=\'▼ Thu gọn\'}else{t.style.display=\'none\';b.textContent=\'▶ Xem full prompt (' + (log.prompt_length||0) + ' chars)\'}">▶ Xem full prompt (' + (log.prompt_length||0) + ' chars)</button>';
                            h += '<textarea id="' + uid + '" class="bizc-rlog-full-prompt" style="display:none;width:100%;min-height:200px;max-height:400px;overflow:auto;font-size:11px;font-family:monospace;background:#1a1a2e;color:#e0e0e0;border:1px solid #444;border-radius:4px;padding:8px;margin-top:4px;resize:vertical;white-space:pre-wrap;" readonly>' + _esc(log.full_prompt) + '</textarea>';
                            h += '</div>';
                        }
                    }
                    // Transit build debug
                    else if (log.step === 'transit_build') {
                        var statusIcon = log.status === 'success' ? '✅' : '⚠️';
                        h += '<div class="bizc-rlog-context">⭐ Transit: ' + statusIcon + ' ' + (log.status||'?') + ' (coachee=' + (log.coachee_id||'0') + ')';
                        if (log.intent_type) h += ' intent=' + log.intent_type + '/' + (log.intent_period||'?');
                        if (log.context_length) h += ' → ' + log.context_length + ' chars';
                        h += '</div>';
                        if (log.context_preview) h += '<div class="bizc-rlog-preview">🌟 ' + _esc(log.context_preview).substring(0,150) + '</div>';
                    }
                    // Session/Project CRUD operations
                    else if (log.step && log.step.match(/^(session|project)_(create|rename|move|delete|update|auto_create|stats_update)/)) {
                        var opIcon = {'create':'➕','rename':'✏️','move':'📦','delete':'🗑️','update':'⚙️','auto_create':'🆕','stats_update':'📊'}[log.step.split('_').slice(1).join('_')] || '📋';
                        h += '<div class="bizc-rlog-context">' + opIcon + ' ' + _esc(log.step);
                        if (log.status) h += ' → ' + log.status;
                        if (log.session_uuid) h += ' [' + _esc(log.session_uuid).substring(0,20) + '...]';
                        if (log.title_generated === 'yes' && log.new_title) h += ' 📝"' + _esc(log.new_title) + '"';
                        else if (log.session_title || log.new_title) h += ' "' + _esc(log.session_title || log.new_title) + '"';
                        if (log.from_project || log.to_project) h += ' ' + _esc(log.from_project||'') + '→' + _esc(log.to_project||'');
                        if (log.message_count) h += ' #' + log.message_count;
                        h += '</div>';
                        if (log.db_error) h += '<div class="bizc-rlog-error">❌ ' + _esc(log.db_error) + '</div>';
                    }
                    // Legacy fallback for other steps with context_length
                    else if (log.context_length) {
                        h += '<div class="bizc-rlog-context">📚 ' + log.context_length + ' chars</div>';
                    }
                    if (log.response_preview) h += '<div class="bizc-rlog-response">✅ ' + _esc(log.response_preview).substring(0,200) + '</div>';
                    if (log.prompt_preview) h += '<div class="bizc-rlog-prompt">📝 ' + _esc(log.prompt_preview).substring(0,300) + '</div>';

                    // Router debug: matched pattern, candidates, provider info
                    if (log.matched_pattern) {
                        h += '<div class="bizc-rlog-detail">🎯 Pattern: <code style="background:#313244;padding:1px 4px;border-radius:2px;color:#f9e2af;font-size:9px;">' + _esc(log.matched_pattern) + '</code>';
                        if (log.pattern_source) h += ' <span style="color:#89dceb;">[' + _esc(log.pattern_source) + ']</span>';
                        h += '</div>';
                    }
                    if (log.classify_step) {
                        h += '<div class="bizc-rlog-detail">📌 Router step: <span style="color:#f9e2af;">' + _esc(log.classify_step) + '</span></div>';
                    }
                    if (log.active_goal) {
                        h += '<div class="bizc-rlog-detail">🔄 Active goal: <span style="color:#cba6f7;">' + _esc(log.active_goal) + '</span> [' + _esc(log.active_goal_status || '?') + ']</div>';
                    }
                    if (log.provider_override) {
                        var po = log.provider_override;
                        h += '<div class="bizc-rlog-detail" style="color:#f9e2af;">⚡ Provider override: <span style="color:#f38ba8;">' + _esc(po.original_mode) + '</span> → <span style="color:#a6e3a1;">execution</span>';
                        if (po.matched_goal) h += ' (goal=' + _esc(po.matched_goal) + ')';
                        h += '</div>';
                    }
                    if (log.all_goal_candidates && log.all_goal_candidates.length) {
                        h += '<div class="bizc-rlog-detail">🔍 Candidates tested: ';
                        h += log.all_goal_candidates.map(function(c){
                            var style = c.matched ? 'color:#a6e3a1;font-weight:700;' : 'color:#6c7086;';
                            return '<span style="'+style+'">' + _esc(c.goal) + (c.source ? ' ['+_esc(c.source)+']' : '') + '</span>';
                        }).join(', ');
                        h += '</div>';
                    }
                    if (log.registered_providers && log.registered_providers.length) {
                        h += '<div class="bizc-rlog-detail">🔌 Providers: ' + log.registered_providers.map(function(p){
                            return '<span style="color:#89b4fa;">' + _esc(p) + '</span>';
                        }).join(', ') + '</div>';
                    }
                    if (log.goal_map && Object.keys(log.goal_map).length) {
                        var gKeys = Object.keys(log.goal_map);
                        h += '<div class="bizc-rlog-detail">📑 Goal map (' + gKeys.length + '): ';
                        h += gKeys.slice(0,15).map(function(k){
                            return '<span style="color:#cba6f7;">' + _esc(k) + '</span>';
                        }).join(', ');
                        if (gKeys.length > 15) h += '… +' + (gKeys.length-15);
                        h += '</div>';
                    }
                    if (log.pattern_count !== undefined) {
                        h += '<div class="bizc-rlog-detail">📊 Patterns: ' + log.pattern_count + ' total';
                        if (log.provider_pattern_count !== undefined) h += ' (' + log.provider_pattern_count + ' from providers)';
                        h += '</div>';
                    }

                    h += '</div>';
                    return h;
                }

                function _fetchRouterLogs() {
                    // Use current sessionId (dynamically updated) for polling
                    var pollSessionId = window.bizcCurrentSessionId || window.bizcSessionId || '';
                    jQuery.post(ajaxurl, {
                        action: 'bizcity_memory_poll_router',
                        nonce: '<?php echo wp_create_nonce("bizcity_chat"); ?>',
                        session_id: pollSessionId
                    }, function(r) {
                        if (!r.success || !r.data.logs || !r.data.logs.length) return;
                        // Store logs for export — strip full_prompt to keep JSON lightweight
                        _bizcRouterRawLogs = r.data.logs.map(function(l) {
                            var cleaned = Object.assign({}, l);
                            delete cleaned.full_prompt;
                            delete cleaned.prompt_head;
                            delete cleaned.prompt_tail;
                            return cleaned;
                        });

                        // Group logs by user message (detect by mode_classify or first step per timestamp cluster)
                        var groups = [], curGroup = null;
                        // Logs are newest-first from server, reverse for chronological grouping
                        var chronoLogs = r.data.logs.slice().reverse();
                        chronoLogs.forEach(function(log) {
                            var msg = _esc((log.message || '').substring(0, 80));
                            // A new group starts at each mode_classify step (= beginning of a user message pipeline)
                            if (log.step === 'mode_classify') {
                                curGroup = { message: msg, time: log.timestamp || '', logs: [] };
                                groups.push(curGroup);
                            }
                            if (!curGroup) {
                                curGroup = { message: msg || '...', time: log.timestamp || '', logs: [] };
                                groups.push(curGroup);
                            }
                            curGroup.logs.push(log);
                        });

                        // Render newest group first
                        groups.reverse();
                        var html = '';
                        groups.forEach(function(g, gi) {
                            var collapsed = gi > 0 ? ' collapsed' : ''; // only latest group open
                            var arrow = gi > 0 ? '▸' : '▾';
                            var stepCount = g.logs.length;
                            html += '<div class="bizc-rlog-group' + collapsed + '">';
                            html += '<div class="bizc-rlog-group-header" onclick="this.parentNode.classList.toggle(\'collapsed\');var a=this.querySelector(\'span\');a.textContent=a.textContent===\'▸\'?\'▾\':\'▸\'">';
                            html += '<span>' + arrow + '</span> 💬 ' + g.message + ' <span style="color:#6c7086;font-weight:400;font-size:9px;">(' + stepCount + ' steps • ' + (g.time || '').replace(/^\d{4}-\d{2}-\d{2}\s/, '') + ')</span>';
                            html += '</div>';
                            html += '<div class="bizc-rlog-group-body">';
                            g.logs.forEach(function(log) { html += _renderLogEntry(log); });
                            html += '</div></div>';
                        });

                        document.getElementById('bizc-router-logs').innerHTML = html;
                        document.getElementById('bizc-router-logs').scrollTop = 0;
                    });
                }

                function _esc(s) { if (typeof s !== 'string') s = String(s||''); var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

                /* ── Drag-resize handle logic ── */
                document.addEventListener('DOMContentLoaded', function() {
                    var handle = document.getElementById('bizc-resize-handle');
                    if (!handle) return;
                    var consoleEl = document.getElementById('bizc-router-console');
                    var startY, startH;
                    handle.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        startY = e.clientY;
                        startH = consoleEl.offsetHeight;
                        document.addEventListener('mousemove', onMove);
                        document.addEventListener('mouseup', onUp);
                        document.body.style.cursor = 'ns-resize';
                        document.body.style.userSelect = 'none';
                    });
                    // Touch support for mobile/tablet
                    handle.addEventListener('touchstart', function(e) {
                        var t = e.touches[0];
                        startY = t.clientY;
                        startH = consoleEl.offsetHeight;
                        document.addEventListener('touchmove', onTouchMove, {passive:false});
                        document.addEventListener('touchend', onTouchEnd);
                    });
                    function onMove(e) {
                        var newH = Math.max(60, Math.min(600, startH + (e.clientY - startY)));
                        consoleEl.style.maxHeight = newH + 'px';
                    }
                    function onUp() {
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                    }
                    function onTouchMove(e) {
                        e.preventDefault();
                        var t = e.touches[0];
                        var newH = Math.max(60, Math.min(600, startH + (t.clientY - startY)));
                        consoleEl.style.maxHeight = newH + 'px';
                    }
                    function onTouchEnd() {
                        document.removeEventListener('touchmove', onTouchMove);
                        document.removeEventListener('touchend', onTouchEnd);
                    }
                });

                /* Pulse animation */
                var _style = document.createElement('style');
                _style.textContent = '@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}';
                document.head.appendChild(_style);
                </script>
                
                <!-- Drag resize handle -->
                <div class="bizc-resize-handle" id="bizc-resize-handle" title="Kéo để thay đổi chiều cao console"></div>
               

                <!-- Touch Bar — iPhone App Drawer Style (Virtual Render) -->
                <!-- Agent plugins data for lazy/virtual rendering -->
                <script id="bizc-tb-data" type="application/json">
                <?php
                // Core items (always rendered)
                $core_items = [];
                $core_items[] = ['type' => 'chat', 'slug' => 'chat', 'icon' => '💬', 'label' => 'Chat', 'src' => '', 'title' => 'Quay lại Chat'];
                // Tools Map — default for all users: shows all available AI tools with prompt commands
                $core_items[] = ['type' => 'link', 'slug' => 'tools-map', 'icon' => '🧰', 'label' => 'Công cụ AI', 'src' => home_url('/tools-map/'), 'title' => 'Danh sách Công cụ AI'];
                if (current_user_can('manage_options')) {
                    $core_items[] = ['type' => 'link', 'slug' => 'control-panel', 'icon' => '🎛️', 'label' => 'Control Panel', 'src' => home_url('/tool-control-panel/'), 'title' => 'Cấu hình Tool Routing'];
                    $core_items[] = ['type' => 'link', 'slug' => 'tool-stats', 'icon' => '📊', 'label' => 'Tool Stats', 'src' => home_url('/tool-stats/'), 'title' => 'Thống kê Tool'];
                    $core_items[] = ['type' => 'link', 'slug' => 'profile', 'icon' => '🌟', 'label' => 'Hồ sơ', 'src' => admin_url('admin.php?page=bccm_my_profile'), 'title' => 'Cài Hồ sơ'];
                    $core_items[] = ['type' => 'link', 'slug' => 'knowledge', 'icon' => '📚', 'label' => 'Kiến thức', 'src' => admin_url('admin.php?page=bizcity-knowledge-characters'), 'title' => 'Cài Kiến thức'];
                    $core_items[] = ['type' => 'link', 'slug' => 'marketplace', 'icon' => '🏪', 'label' => 'Chợ AI', 'src' => admin_url('index.php?page=bizcity-marketplace'), 'title' => 'Chợ AI Agent'];
                    
                }
                // Agent plugins (lazy rendered)
                $agent_items = [];
                if (!empty($agent_plugins)) {
                    foreach ($agent_plugins as $ap) {
                        $agent_items[] = [
                            'type' => 'agent',
                            'slug' => $ap['slug'],
                            'icon' => $ap['icon_url'] ?: '',
                            'label' => mb_strimwidth($ap['name'], 0, 12, '…'),
                            'src' => $ap['template_url'],
                            'title' => $ap['name']
                        ];
                    }
                }
                echo wp_json_encode(['core' => $core_items, 'agents' => $agent_items]);
                ?>
                </script>
                <div class="bizc-touchbar-wrap" id="bizc-touchbar-wrap">
                    <!-- Hamburger button (fixed left) -->
                    <button class="bizc-tb-edge bizc-tb-hamburger" id="bizc-tb-hamburger" type="button" aria-label="Menu">
                        <span></span><span></span><span></span>
                    </button>
                    <div class="bizc-touchbar" id="bizc-touchbar">
                        <!-- Items rendered by JS virtual renderer -->
                    </div>
                    <!-- Profile button (fixed right) -->
                    <button class="bizc-tb-edge bizc-tb-profile" id="bizc-tb-profile" type="button" aria-label="Tài khoản">
                        <img src="<?php echo esc_url(get_avatar_url(get_current_user_id(), ['size' => 64])); ?>" alt="" class="bizc-tb-profile-img">
                    </button>
                    <div class="bizc-tb-dots" id="bizc-tb-dots"></div>
                </div>

                <!-- Touchbar Resize Handle — drag DOWN to expand app drawer -->
                <div class="bizc-tb-resize" id="bizc-tb-resize"></div>

                <style>
                    /* ── Touchbar Resize Handle ── */
                    .bizc-tb-resize {
                        height: 22px;
                        display: flex; align-items: center; justify-content: center;
                        cursor: ns-resize; flex-shrink: 0;
                        user-select: none; -webkit-user-select: none;
                        touch-action: none;
                        background: linear-gradient(180deg, rgba(99,102,241,0.08), transparent);
                    }
                    .bizc-tb-resize::after {
                        content: '';
                        width: 40px; height: 5px;
                        background: rgba(99,102,241,0.4);
                        border-radius: 3px;
                        transition: background 0.2s, width 0.2s;
                    }
                    .bizc-tb-resize:hover::after,
                    .bizc-tb-resize:active::after {
                        background: rgba(99,102,241,0.7);
                        width: 50px;
                    }

                    /* ═══════════════════════════════════════════
                       TOUCHBAR WRAP — frosted glass
                       Default = compact strip
                       .expanded = iPhone app-drawer grid
                       ═══════════════════════════════════════════ */
                    .bizc-touchbar-wrap {
                        flex-shrink: 0;
                        display: flex;
                        align-items: stretch;
                        background: rgba(30, 30, 46, 0.88);
                        backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
                        border-radius: 14px 14px 0 0;
                        box-shadow: 0 -1px 12px rgba(0,0,0,0.15), inset 0 1px 0 rgba(255,255,255,0.06);
                        overflow: hidden;
                        transition: border-radius 0.25s, background 0.25s, box-shadow 0.25s;
                        position: relative;
                    }
                    /* Expanded wrap — more iPhone-like */
                    .bizc-touchbar-wrap.expanded {
                        flex-wrap: wrap;
                        background: rgba(20, 20, 38, 0.94);
                        backdrop-filter: blur(28px); -webkit-backdrop-filter: blur(28px);
                        border-radius: 22px 22px 0 0;
                        box-shadow: 0 -2px 24px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.08);
                    }

                    /* ── EDGE BUTTONS: Hamburger (left) & Profile (right) ── */
                    .bizc-tb-edge {
                        display: flex;
                        flex-direction: column;
                        justify-content: center;
                        align-items: center;
                        width: 44px;
                        flex-shrink: 0;
                        background: none;
                        border: none;
                        cursor: pointer;
                        padding: 8px 6px;
                        -webkit-tap-highlight-color: transparent;
                        transition: background 0.2s;
                    }
                    .bizc-tb-edge:hover {
                        background: rgba(255,255,255,0.08);
                    }
                    .bizc-tb-edge:active {
                        background: rgba(255,255,255,0.15);
                    }
                    /* Hamburger icon */
                    .bizc-tb-hamburger {
                        gap: 4px;
                    }
                    .bizc-tb-hamburger span {
                        display: block;
                        width: 18px;
                        height: 2px;
                        background: #cdd6f4;
                        border-radius: 1px;
                        transition: transform 0.3s, opacity 0.3s;
                    }
                    .bizc-tb-hamburger.open span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
                    .bizc-tb-hamburger.open span:nth-child(2) { opacity: 0; }
                    .bizc-tb-hamburger.open span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }
                    /* Profile button */
                    .bizc-tb-profile {
                        padding: 6px;
                    }
                    .bizc-tb-profile-img {
                        width: 32px;
                        height: 32px;
                        border-radius: 50%;
                        object-fit: cover;
                        border: 2px solid rgba(255,255,255,0.2);
                        transition: border-color 0.2s;
                    }
                    .bizc-tb-profile:hover .bizc-tb-profile-img {
                        border-color: rgba(99,102,241,0.6);
                    }

                    /* ── COMPACT (default): horizontal scrollable strip ── */
                    .bizc-touchbar {
                        flex: 1;
                        display: flex; align-items: center; gap: 2px;
                        padding: 6px 4px;
                        overflow-x: auto; overflow-y: hidden;
                        scrollbar-width: none;
                        -webkit-overflow-scrolling: touch;
                    }
                    .bizc-touchbar::-webkit-scrollbar { display: none; }
                    
                    /* Dots in expanded mode - full width row */
                    .bizc-touchbar-wrap .bizc-tb-dots {
                        flex-basis: 100%;
                        order: 10;
                    }

                    .bizc-tb-page {
                        display: flex; align-items: center; gap: 2px;
                        flex-shrink: 0;
                        min-width: auto;
                    }

                    .bizc-tb-item {
                        display: flex; flex-direction: column; align-items: center;
                        gap: 2px; padding: 4px 6px;
                        background: none; border: none; cursor: pointer;
                        color: #cdd6f4; text-decoration: none; margin: 0;
                        transition: all 0.2s; flex-shrink: 0; min-width: 52px;
                        border-radius: 10px;
                        -webkit-tap-highlight-color: transparent;
                    }
                    .bizc-tb-item:hover {
                        background: rgba(99,102,241,0.2); color: #fff; text-decoration: none;
                    }
                    .bizc-tb-item:active { opacity: 0.7; }

                    .bizc-tb-icon {
                        width: 28px; height: 28px;
                        display: flex; align-items: center; justify-content: center;
                        font-size: 20px; line-height: 1;
                        background: transparent; border-radius: 8px;
                        transition: all 0.25s ease;
                    }
                    .bizc-tb-icon img {
                        width: 28px; height: 28px;
                        border-radius: 8px; object-fit: cover;
                        transition: all 0.25s ease;
                    }

                    .bizc-tb-label {
                        font-family: -apple-system, 'SF Pro Text', 'SF Pro Display',
                                     BlinkMacSystemFont, system-ui, 'Segoe UI', Roboto, sans-serif;
                        font-size: 10px; font-weight: 500; line-height: 1.2;
                        white-space: nowrap; max-width: 56px;
                        overflow: hidden; text-overflow: ellipsis;
                        color: #a6adc8;
                        transition: all 0.25s ease;
                    }

                    /* Active item (compact) */
                    .bizc-tb-item.active {
                        background: rgba(99,102,241,0.35);
                        box-shadow: 0 0 10px rgba(99,102,241,0.2);
                        color: #fff;
                    }
                    .bizc-tb-item.active .bizc-tb-label { color: #fff; }

                    /* "More" button — shows count of hidden items */
                    .bizc-tb-item.bizc-tb-more {
                        background: rgba(99,102,241,0.15);
                        border: 1px dashed rgba(99,102,241,0.4);
                    }
                    .bizc-tb-item.bizc-tb-more:hover {
                        background: rgba(99,102,241,0.25);
                        border-color: rgba(99,102,241,0.6);
                    }
                    .bizc-tb-item.bizc-tb-more .bizc-tb-icon {
                        font-size: 16px;
                        color: #a5b4fc;
                    }
                    .bizc-tb-item.bizc-tb-more .bizc-tb-label {
                        color: #a5b4fc;
                        font-weight: 600;
                    }

                    /* Dots — hidden in compact, clickable in expanded */
                    .bizc-tb-dots { display: none; justify-content: center; gap: 6px; padding: 4px 0 8px; }
                    .bizc-tb-dot {
                        width: 6px; height: 6px; border-radius: 50%;
                        background: rgba(255,255,255,0.22);
                        transition: all 0.3s ease;
                        cursor: pointer;
                    }
                    .bizc-tb-dot:hover {
                        background: rgba(255,255,255,0.4);
                    }
                    .bizc-tb-dot.active {
                        width: 18px; border-radius: 3px;
                        background: rgba(99,102,241,0.75);
                    }

                    /* Dividers hidden */
                    .bizc-tb-divider { display: none; }

                    /* ═══════════════════════════════════════════
                       EXPANDED: iPhone app-drawer grid
                       ═══════════════════════════════════════════ */
                    .bizc-touchbar-wrap.expanded .bizc-touchbar {
                        gap: 0; padding: 0;
                        scroll-snap-type: x mandatory;
                        scroll-behavior: smooth;
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-page {
                        display: grid;
                        grid-template-columns: repeat(4, 1fr);
                        gap: 10px 0;
                        padding: 14px 12px 6px;
                        justify-items: center;
                        align-content: start;
                        min-width: 100%;
                        scroll-snap-align: start;
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-item {
                        padding: 4px 2px; gap: 5px;
                        border-radius: 0; background: none;
                        min-width: auto;
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-item:hover {
                        background: none;
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-item:active {
                        transform: scale(0.86); opacity: 0.7;
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-icon {
                        width: 54px; height: 54px;
                        font-size: 26px; border-radius: 15px;
                        background: linear-gradient(145deg, rgba(99,102,241,0.3), rgba(139,92,246,0.18));
                        box-shadow: 0 2px 10px rgba(0,0,0,0.18),
                                    inset 0 1px 0 rgba(255,255,255,0.1);
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-icon img {
                        width: 54px; height: 54px;
                        border-radius: 15px;
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-label {
                        font-size: 11px; max-width: 68px;
                        color: #cbd5e1;
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-item.active .bizc-tb-icon {
                        background: linear-gradient(145deg, rgba(99,102,241,0.55), rgba(139,92,246,0.4));
                        box-shadow: 0 0 18px rgba(99,102,241,0.35),
                                    inset 0 1px 0 rgba(255,255,255,0.15);
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-item.active {
                        background: none; box-shadow: none;
                    }
                    .bizc-touchbar-wrap.expanded .bizc-tb-item.active .bizc-tb-label { color: #fff; }

                    /* Dots visible only in expanded + multiple pages */
                    .bizc-touchbar-wrap.expanded .bizc-tb-dots.has-dots { display: flex; }

                    /* ── Responsive: smaller icons on very small screens ── */
                    @media (max-width: 374px) {
                        .bizc-touchbar-wrap.expanded .bizc-tb-icon,
                        .bizc-touchbar-wrap.expanded .bizc-tb-icon img { width: 48px; height: 48px; border-radius: 13px; }
                        .bizc-touchbar-wrap.expanded .bizc-tb-label { font-size: 10px; max-width: 58px; }
                        .bizc-touchbar-wrap.expanded .bizc-tb-page { gap: 8px 0; padding: 10px 8px 4px; }
                    }
                </style>

                <!-- Touchbar: Virtual Rendering + pagination + resize handle logic -->
                <script>
                (function() {
                    var COLS = 4;
                    var ROW_H = 88;           /* ~px per grid row */
                    var PAGE_PAD = 20;        /* grid page padding */
                    var DOTS_H = 28;          /* dots bar height */
                    var EXPAND_THRESHOLD = 90; /* px to switch to grid mode */
                    var COMPACT_MAX_ITEMS = 8; /* max items to render in compact mode */
                    var POOL_SIZE = 16;       /* DOM element pool size (recycle instead of create) */

                    var touchbar = document.getElementById('bizc-touchbar');
                    var dotsEl  = document.getElementById('bizc-tb-dots');
                    var wrap    = document.getElementById('bizc-touchbar-wrap');
                    var handle  = document.getElementById('bizc-tb-resize');
                    var dataEl  = document.getElementById('bizc-tb-data');
                    if (!touchbar || !dataEl) return;

                    /* Parse JSON data */
                    var tbData;
                    try { tbData = JSON.parse(dataEl.textContent); } catch(e) { return; }
                    var coreItems = tbData.core || [];
                    var agentItems = tbData.agents || [];
                    var allItemsData = coreItems.concat(agentItems);
                    if (!allItemsData.length) return;

                    /* ═══════════════════════════════════════════
                       DOM POOL — recycle elements instead of creating new ones
                       ═══════════════════════════════════════════ */
                    var domPool = [];
                    var activeElements = {}; /* slug → element */

                    function createButton() {
                        var btn = document.createElement('button');
                        btn.className = 'bizc-tb-item';
                        btn.innerHTML = '<span class="bizc-tb-icon"></span><span class="bizc-tb-label"></span>';
                        return btn;
                    }

                    function acquireButton() {
                        return domPool.length ? domPool.pop() : createButton();
                    }

                    function releaseButton(btn) {
                        if (btn.parentNode) btn.parentNode.removeChild(btn);
                        btn.className = 'bizc-tb-item';
                        btn.removeAttribute('data-slug');
                        btn.removeAttribute('data-src');
                        btn.title = '';
                        var iconEl = btn.querySelector('.bizc-tb-icon');
                        if (iconEl) iconEl.innerHTML = '';
                        var labelEl = btn.querySelector('.bizc-tb-label');
                        if (labelEl) labelEl.textContent = '';
                        if (domPool.length < POOL_SIZE) domPool.push(btn);
                    }

                    function renderButton(item) {
                        var btn = acquireButton();
                        var cssClass = 'bizc-tb-item';
                        if (item.type === 'chat') cssClass += ' bizc-tb-chat';
                        else if (item.type === 'link') cssClass += ' bizc-tb-link';
                        else if (item.type === 'agent') cssClass += ' bizc-tb-agent';
                        btn.className = cssClass;
                        btn.setAttribute('data-slug', item.slug);
                        if (item.src) btn.setAttribute('data-src', item.src);
                        btn.title = item.title || item.label || '';
                        
                        var iconEl = btn.querySelector('.bizc-tb-icon');
                        if (iconEl) {
                            if (item.type === 'agent' && item.icon && item.icon.indexOf('http') === 0) {
                                iconEl.innerHTML = '<img src="' + item.icon + '" alt="" loading="lazy">';
                            } else {
                                iconEl.textContent = item.icon || '🤖';
                            }
                        }
                        var labelEl = btn.querySelector('.bizc-tb-label');
                        if (labelEl) labelEl.textContent = item.label || '';
                        
                        activeElements[item.slug] = btn;
                        return btn;
                    }

                    /* ═══════════════════════════════════════════
                       VIRTUAL PAGINATION — only render visible page
                       ═══════════════════════════════════════════ */
                    var _currentRows = 0;
                    var _currentPage = 0;
                    var _totalPages = 1;
                    var _perPage = 8;
                    var _isExpanded = false;

                    function clearTouchbar() {
                        /* Release all active elements back to pool */
                        for (var slug in activeElements) {
                            releaseButton(activeElements[slug]);
                        }
                        activeElements = {};
                        touchbar.innerHTML = '';
                    }

                    function renderCompact() {
                        /* Compact mode: render up to COMPACT_MAX_ITEMS in single strip */
                        clearTouchbar();
                        var count = Math.min(allItemsData.length, COMPACT_MAX_ITEMS);
                        for (var i = 0; i < count; i++) {
                            var btn = renderButton(allItemsData[i]);
                            touchbar.appendChild(btn);
                        }
                        /* If more items exist, add "more" indicator */
                        if (allItemsData.length > COMPACT_MAX_ITEMS) {
                            var moreBtn = acquireButton();
                            moreBtn.className = 'bizc-tb-item bizc-tb-more';
                            moreBtn.title = 'Xem thêm ' + (allItemsData.length - COMPACT_MAX_ITEMS) + ' ứng dụng';
                            var iconEl = moreBtn.querySelector('.bizc-tb-icon');
                            if (iconEl) iconEl.textContent = '➕';
                            var labelEl = moreBtn.querySelector('.bizc-tb-label');
                            if (labelEl) labelEl.textContent = '+' + (allItemsData.length - COMPACT_MAX_ITEMS);
                            moreBtn.onclick = function() { expandTouchbar(); };
                            touchbar.appendChild(moreBtn);
                            activeElements['__more__'] = moreBtn;
                        }
                        updateDots(1, 0);
                    }

                    function renderPage(pageIndex) {
                        /* Expanded mode: render only items for current page */
                        clearTouchbar();
                        _currentPage = pageIndex;
                        
                        var start = pageIndex * _perPage;
                        var end = Math.min(start + _perPage, allItemsData.length);
                        
                        var pg = document.createElement('div');
                        pg.className = 'bizc-tb-page';
                        
                        for (var i = start; i < end; i++) {
                            var btn = renderButton(allItemsData[i]);
                            pg.appendChild(btn);
                        }
                        touchbar.appendChild(pg);
                        updateDots(_totalPages, pageIndex);
                    }

                    function updateDots(total, current) {
                        if (!dotsEl) return;
                        dotsEl.innerHTML = '';
                        if (total > 1) {
                            dotsEl.classList.add('has-dots');
                            for (var d = 0; d < total; d++) {
                                var dot = document.createElement('span');
                                dot.className = 'bizc-tb-dot' + (d === current ? ' active' : '');
                                dot.setAttribute('data-page', d);
                                dotsEl.appendChild(dot);
                            }
                        } else {
                            dotsEl.classList.remove('has-dots');
                        }
                    }

                    function expandTouchbar() {
                        if (!wrap) return;
                        wrap.style.height = '200px';
                        syncExpandState();
                    }

                    /* ── Calculate rows and pages ── */
                    function calcLayout(wrapH) {
                        if (!wrapH || wrapH <= EXPAND_THRESHOLD) {
                            return { rows: 1, perPage: COMPACT_MAX_ITEMS, expanded: false };
                        }
                        var available = wrapH - PAGE_PAD - DOTS_H;
                        var rows = Math.max(2, Math.floor(available / ROW_H));
                        return { rows: rows, perPage: COLS * rows, expanded: true };
                    }

                    function syncExpandState() {
                        if (!wrap) return;
                        var h = wrap.offsetHeight;
                        var layout = calcLayout(h);
                        var wasExpanded = _isExpanded;
                        _isExpanded = layout.expanded;
                        
                        wrap.classList.toggle('expanded', _isExpanded);
                        
                        if (_isExpanded) {
                            _perPage = layout.perPage;
                            _totalPages = Math.ceil(allItemsData.length / _perPage);
                            /* Re-render current page if layout changed */
                            if (layout.rows !== _currentRows || !wasExpanded) {
                                _currentRows = layout.rows;
                                renderPage(_currentPage);
                            }
                        } else {
                            if (wasExpanded) {
                                _currentRows = 1;
                                renderCompact();
                            }
                        }
                    }

                    /* ── Dot click → navigate pages ── */
                    if (dotsEl) {
                        dotsEl.addEventListener('click', function(e) {
                            var dot = e.target.closest('.bizc-tb-dot');
                            if (dot && _isExpanded) {
                                var pg = parseInt(dot.getAttribute('data-page'), 10);
                                if (!isNaN(pg) && pg !== _currentPage) {
                                    renderPage(pg);
                                }
                            }
                        });
                    }

                    /* ── Swipe navigation for expanded pages ── */
                    var _touchStartX = 0;
                    touchbar.addEventListener('touchstart', function(e) {
                        _touchStartX = e.touches[0].clientX;
                    }, {passive: true});
                    touchbar.addEventListener('touchend', function(e) {
                        if (!_isExpanded || _totalPages <= 1) return;
                        var diffX = e.changedTouches[0].clientX - _touchStartX;
                        if (Math.abs(diffX) > 50) {
                            if (diffX < 0 && _currentPage < _totalPages - 1) {
                                renderPage(_currentPage + 1);
                            } else if (diffX > 0 && _currentPage > 0) {
                                renderPage(_currentPage - 1);
                            }
                        }
                    }, {passive: true});

                    /* ── Initial render (compact mode) ── */
                    renderCompact();

                    /* ── Resize handle: drag to expand/collapse ── */
                    if (!handle || !wrap) return;
                    var _startY, _startH;
                    var _minH = 56, _maxH = Math.min(520, window.innerHeight * 0.6);

                    function rStart(y) {
                        _startY = y; _startH = wrap.offsetHeight;
                        wrap.style.transition = 'none';
                        document.body.style.cursor = 'ns-resize';
                        document.body.style.userSelect = 'none';
                        document.body.style.webkitUserSelect = 'none';
                    }
                    function rMove(y) {
                        var newH = Math.max(_minH, Math.min(_maxH, _startH + (y - _startY)));
                        wrap.style.height = newH + 'px';
                        syncExpandState();
                    }
                    function rEnd() {
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                        document.body.style.webkitUserSelect = '';
                        var h = wrap.offsetHeight;
                        if (h <= EXPAND_THRESHOLD) {
                            wrap.style.height = '';
                        }
                        wrap.style.transition = '';
                        syncExpandState();
                    }

                    handle.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        rStart(e.clientY);
                        function mm(ev) { rMove(ev.clientY); }
                        function mu() { document.removeEventListener('mousemove', mm); document.removeEventListener('mouseup', mu); rEnd(); }
                        document.addEventListener('mousemove', mm);
                        document.addEventListener('mouseup', mu);
                    });
                    handle.addEventListener('touchstart', function(e) {
                        rStart(e.touches[0].clientY);
                        function tm(ev) { ev.preventDefault(); rMove(ev.touches[0].clientY); }
                        function te() { document.removeEventListener('touchmove', tm); document.removeEventListener('touchend', te); rEnd(); }
                        document.addEventListener('touchmove', tm, {passive:false});
                        document.addEventListener('touchend', te);
                    }, {passive:true});
                    
                    /* Expose for external access (e.g., jQuery handlers) */
                    window.bizcTouchbarData = allItemsData;
                    window.bizcTouchbarRefresh = function() { _isExpanded ? renderPage(_currentPage) : renderCompact(); };
                    
                    /* ── Helper: Close mobile sidebar ── */
                    function closeMobileSidebar() {
                        var sidebar = document.querySelector('.bizc-sidebar');
                        var backdrop = document.getElementById('bizc-drawer-backdrop') || document.getElementById('aiagent-drawer-backdrop');
                        var hamburgerBtn = document.getElementById('bizc-tb-hamburger');
                        if (sidebar && sidebar.classList.contains('mobile-open')) {
                            sidebar.classList.remove('mobile-open');
                            if (hamburgerBtn) hamburgerBtn.classList.remove('open');
                            if (backdrop) backdrop.classList.remove('active');
                        }
                    }
                    // Expose globally for jQuery handlers
                    window.closeMobileSidebar = closeMobileSidebar;
                    
                    /* ── Backdrop click → close sidebar ── */
                    var backdrop = document.getElementById('bizc-drawer-backdrop') || document.getElementById('aiagent-drawer-backdrop');
                    if (backdrop) {
                        backdrop.addEventListener('click', function() {
                            closeMobileSidebar();
                            // Also close right drawer if open
                            var rightDrawer = document.getElementById('aiagent-right-drawer');
                            if (rightDrawer) rightDrawer.classList.remove('mobile-open');
                            backdrop.classList.remove('active');
                        });
                    }
                    
                    /* ── Hamburger button → toggle sidebar ── */
                    var hamburgerBtn = document.getElementById('bizc-tb-hamburger');
                    if (hamburgerBtn) {
                        hamburgerBtn.addEventListener('click', function() {
                            var sidebar = document.querySelector('.bizc-sidebar');
                            var backdrop = document.getElementById('bizc-drawer-backdrop') || document.getElementById('aiagent-drawer-backdrop');
                            var isMobile = window.innerWidth <= 768;
                            
                            if (sidebar) {
                                if (isMobile) {
                                    /* Mobile: show/hide with mobile-open + overlay */
                                    var isOpen = sidebar.classList.contains('mobile-open');
                                    if (isOpen) {
                                        sidebar.classList.remove('mobile-open');
                                        hamburgerBtn.classList.remove('open');
                                        if (backdrop) backdrop.classList.remove('active');
                                    } else {
                                        sidebar.classList.add('mobile-open');
                                        hamburgerBtn.classList.add('open');
                                        if (backdrop) backdrop.classList.add('active');
                                        // Close right drawer if open
                                        var rightDrawer = document.getElementById('aiagent-right-drawer');
                                        if (rightDrawer) rightDrawer.classList.remove('mobile-open');
                                    }
                                } else {
                                    /* Desktop: toggle desktop-hidden (slide left to hide) */
                                    var isHidden = sidebar.classList.contains('desktop-hidden');
                                    if (isHidden) {
                                        sidebar.classList.remove('desktop-hidden');
                                        hamburgerBtn.classList.remove('open');
                                    } else {
                                        sidebar.classList.add('desktop-hidden');
                                        hamburgerBtn.classList.add('open');
                                    }
                                }
                            }
                        });
                    }
                    
                    /* ── Profile button → toggle right drawer (or login dialog for guests) ── */
                    var profileBtn = document.getElementById('bizc-tb-profile');
                    var isGuestUser = <?php echo $current_uid ? 'false' : 'true'; ?>;
                    if (profileBtn) {
                        profileBtn.addEventListener('click', function() {
                            // If guest, show login dialog instead
                            if (isGuestUser) {
                                if (typeof window.aiagentShowAuth === 'function') {
                                    window.aiagentShowAuth('login');
                                } else {
                                    window.location.href = '<?php echo wp_login_url( get_permalink() ); ?>';
                                }
                                return;
                            }
                            
                            var rightDrawer = document.getElementById('aiagent-right-drawer');
                            var backdrop = document.getElementById('bizc-drawer-backdrop') || document.getElementById('aiagent-drawer-backdrop');
                            if (rightDrawer) {
                                var isOpen = rightDrawer.classList.contains('mobile-open');
                                if (isOpen) {
                                    rightDrawer.classList.remove('mobile-open');
                                    if (backdrop) backdrop.classList.remove('active');
                                } else {
                                    rightDrawer.classList.add('mobile-open');
                                    if (backdrop) backdrop.classList.add('active');
                                    // Close sidebar if open
                                    var sidebar = document.querySelector('.bizc-sidebar');
                                    if (sidebar) sidebar.classList.remove('mobile-open');
                                    if (hamburgerBtn) hamburgerBtn.classList.remove('open');
                                }
                            }
                        });
                    }
                    
                    /* ── Sidebar Collapse button (desktop) ── */
                    var collapseBtn = document.getElementById('bizc-sidebar-collapse');
                    if (collapseBtn) {
                        collapseBtn.addEventListener('click', function() {
                            var sidebar = document.querySelector('.bizc-sidebar');
                            var hamburgerBtn = document.getElementById('bizc-tb-hamburger');
                            if (sidebar) {
                                var isHidden = sidebar.classList.contains('desktop-hidden');
                                if (isHidden) {
                                    sidebar.classList.remove('desktop-hidden');
                                    if (hamburgerBtn) hamburgerBtn.classList.remove('open');
                                } else {
                                    sidebar.classList.add('desktop-hidden');
                                    if (hamburgerBtn) hamburgerBtn.classList.add('open');
                                }
                            }
                        });
                    }
                    
                    /* ── Guest Login Button ── */
                    var guestLoginBtn = document.getElementById('bizc-guest-login-btn');
                    if (guestLoginBtn) {
                        guestLoginBtn.addEventListener('click', function() {
                            closeMobileSidebar(); // Close sidebar first
                            if (typeof window.aiagentShowAuth === 'function') {
                                window.aiagentShowAuth('login');
                            } else {
                                // Fallback: redirect to login page
                                window.location.href = '<?php echo wp_login_url( get_permalink() ); ?>';
                            }
                        });
                    }
                    
                    /* ── Search Chat Modal ── */
                    var searchBtn = document.getElementById('bizc-search-btn');
                    var searchModal = document.getElementById('bizc-search-modal');
                    var searchClose = document.getElementById('bizc-search-close');
                    var searchInput = document.getElementById('bizc-search-input');
                    var searchResults = document.getElementById('bizc-search-results');
                    var searchTimeout = null;
                    var searchAjaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
                    var searchNonce = '<?php echo wp_create_nonce("bizcity_webchat"); ?>';
                    
                    function showSearchModal() {
                        if (searchModal) {
                            searchModal.classList.add('active');
                            if (searchInput) {
                                searchInput.value = '';
                                searchInput.focus();
                            }
                            // Load all recent chats on open
                            loadAllChats();
                        }
                    }
                    
                    function hideSearchModal() {
                        if (searchModal) searchModal.classList.remove('active');
                    }
                    
                    function loadAllChats() {
                        if (!searchResults) return;
                        searchResults.innerHTML = '<div class="bizc-search-empty">Loading...</div>';
                        
                        fetch(searchAjaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=bizcity_webchat_sessions&_wpnonce=' + searchNonce
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(res) {
                            if (!res.success || !res.data) {
                                renderSearchList([]);
                                return;
                            }
                            renderSearchList(res.data);
                        })
                        .catch(function() {
                            searchResults.innerHTML = '<div class="bizc-search-empty">Connection error</div>';
                        });
                    }
                    
                    function renderSearchList(sessions, filterQuery) {
                        if (!searchResults) return;
                        
                        // Filter if query provided
                        var items = sessions;
                        if (filterQuery && filterQuery.trim()) {
                            var q = filterQuery.toLowerCase();
                            items = sessions.filter(function(s) {
                                return (s.title || '').toLowerCase().indexOf(q) !== -1;
                            });
                        }
                        
                        // Group by date
                        var today = new Date();
                        today.setHours(0,0,0,0);
                        var yesterday = new Date(today);
                        yesterday.setDate(yesterday.getDate() - 1);
                        var weekAgo = new Date(today);
                        weekAgo.setDate(weekAgo.getDate() - 7);
                        var monthAgo = new Date(today);
                        monthAgo.setDate(monthAgo.getDate() - 30);
                        
                        var groups = { today: [], yesterday: [], week: [], month: [], older: [] };
                        
                        items.forEach(function(s) {
                            var d = new Date(s.last_activity || s.started_at);
                            d.setHours(0,0,0,0);
                            if (d >= today) groups.today.push(s);
                            else if (d >= yesterday) groups.yesterday.push(s);
                            else if (d >= weekAgo) groups.week.push(s);
                            else if (d >= monthAgo) groups.month.push(s);
                            else groups.older.push(s);
                        });
                        
                        var html = '<ol class="bizc-search-list">';
                        
                        // New chat option
                        html += '<li><div class="bizc-search-item" data-action="new-chat">' +
                            '<div class="bizc-search-item-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>' +
                            '<div class="bizc-search-item-title">New chat</div>' +
                            '</div></li>';
                        
                        // Render groups
                        var groupLabels = [
                            { key: 'today', label: 'Today' },
                            { key: 'yesterday', label: 'Yesterday' },
                            { key: 'week', label: 'Previous 7 Days' },
                            { key: 'month', label: 'Previous 30 Days' },
                            { key: 'older', label: 'Older' }
                        ];
                        
                        groupLabels.forEach(function(g) {
                            if (groups[g.key].length === 0) return;
                            html += '<li><div class="bizc-search-group-label">' + g.label + '</div></li>';
                            groups[g.key].forEach(function(s) {
                                var title = escapeHtml(s.title || 'New chat');
                                html += '<li><div class="bizc-search-item" data-wc-id="' + s.id + '">' +
                                    '<div class="bizc-search-item-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>' +
                                    '<div class="bizc-search-item-title">' + title + '</div>' +
                                    '</div></li>';
                            });
                        });
                        
                        html += '</ol>';
                        
                        if (items.length === 0 && filterQuery) {
                            html = '<div class="bizc-search-empty">No results found</div>';
                        }
                        
                        searchResults.innerHTML = html;
                        
                        // Click handlers
                        searchResults.querySelectorAll('.bizc-search-item').forEach(function(item) {
                            item.addEventListener('click', function() {
                                var action = this.dataset.action;
                                var wcId = this.dataset.wcId;
                                hideSearchModal();
                                closeMobileSidebar(); // Close sidebar on mobile
                                
                                if (action === 'new-chat') {
                                    if (window.bizcStartNewChat) window.bizcStartNewChat();
                                    else if (document.getElementById('bizc-new-chat')) document.getElementById('bizc-new-chat').click();
                                } else if (wcId) {
                                    if (window.bizcLoadSession) window.bizcLoadSession(parseInt(wcId));
                                    else {
                                        var convItem = document.querySelector('.bizc-conv[data-wc-id="' + wcId + '"]');
                                        if (convItem) convItem.click();
                                    }
                                }
                            });
                        });
                        
                        // Store sessions for filtering
                        searchResults._sessions = sessions;
                    }
                    
                    function doSearch(query) {
                        if (!searchResults || !searchResults._sessions) return;
                        renderSearchList(searchResults._sessions, query);
                    }
                    
                    function escapeHtml(str) {
                        var div = document.createElement('div');
                        div.textContent = str;
                        return div.innerHTML;
                    }
                    
                    if (searchBtn) {
                        searchBtn.addEventListener('click', showSearchModal);
                    }
                    
                    if (searchClose) {
                        searchClose.addEventListener('click', hideSearchModal);
                    }
                    
                    if (searchModal) {
                        searchModal.addEventListener('click', function(e) {
                            if (e.target === searchModal) hideSearchModal();
                        });
                    }
                    
                    if (searchInput) {
                        searchInput.addEventListener('input', function() {
                            var val = this.value;
                            clearTimeout(searchTimeout);
                            searchTimeout = setTimeout(function() {
                                doSearch(val);
                            }, 300);
                        });
                    }
                })();
                </script>
                
                <!-- Project Detail Panel (hidden by default - shown when clicking a project) -->
                <div id="bizc-project-detail" style="display:none;flex:1;flex-direction:column;overflow:hidden;">
                    <div style="padding:20px 28px 12px;border-bottom:1px solid #e5e7eb;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                            <button id="bizc-proj-back" style="background:none;border:none;cursor:pointer;font-size:18px;color:#6366f1;padding:4px;" title="Quay lại chat">←</button>
                            <span id="bizc-proj-detail-icon" style="font-size:24px;">📁</span>
                            <h2 id="bizc-proj-detail-name" style="margin:0;font-size:18px;font-weight:700;color:#1a1a2e;flex:1;"></h2>
                        </div>
                        <!-- Character Binding -->
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding:8px 12px;background:#f3f4f6;border-radius:8px;">
                            <label style="font-size:12px;color:#6b7280;white-space:nowrap;">🎭 Agent:</label>
                            <select id="bizc-proj-character-select" style="flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;background:#fff;outline:none;">
                                <option value="0">— Mặc định —</option>
                                <?php foreach ($characters as $ch): ?>
                                <option value="<?php echo esc_attr($ch->id); ?>"><?php echo esc_html($ch->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span id="bizc-proj-char-status" style="font-size:11px;color:#9ca3af;"></span>
                        </div>
                        <div style="display:flex;gap:12px;align-items:center;">
                            <input type="text" id="bizc-proj-new-chat-input" placeholder="+ New chat in this project" style="flex:1;padding:8px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:13px;outline:none;background:#f9fafb;">
                        </div>
                        <div style="display:flex;gap:16px;margin-top:12px;border-bottom:2px solid transparent;">
                            <span class="bizc-proj-tab active" data-tab="chats" style="padding:6px 0;font-size:13px;font-weight:600;color:#6366f1;border-bottom:2px solid #6366f1;cursor:pointer;">Chats</span>
                            <span class="bizc-proj-tab" data-tab="sources" style="padding:6px 0;font-size:13px;color:#9ca3af;cursor:pointer;">Sources</span>
                        </div>
                    </div>
                    <div id="bizc-proj-detail-list" style="flex:1;overflow-y:auto;padding:8px 16px;"></div>
                </div>

                <!-- Chat Panel (shown by default) -->
                <div id="bizc-chat-panel" style="display:flex;flex-direction:column;flex:1;overflow:hidden;">

                <!-- Header --
                <div class="bizc-header">
                    <div class="bizc-hdr-left">
                        <div class="bizc-hdr-av">
                            <?php if ($char_avatar): ?>
                                <img src="<?php echo esc_url($char_avatar); ?>" alt="">
                            <?php else: ?>
                                <span>🤖</span>
                            <?php endif; ?>
                        </div>
                        <div class="bizc-hdr-info">
                            <h2><?php echo esc_html($header_name); ?></h2>
                            <span><?php echo esc_html($header_desc); ?> • Online</span>
                        </div>
                    </div>
                </div>
                            -->
                <!-- Messages -->
                <div class="bizc-messages" id="bizc-messages">
                    <div class="bizc-msg bot">
                        <div class="bizc-msg-av">
                            <?php if ($char_avatar): ?>
                                <img src="<?php echo esc_url($char_avatar); ?>" alt="">
                            <?php else: ?>
                                <span>🤖</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="bizc-msg-bubble"><?php echo esc_html($random_greeting); ?></div>
                            <div class="bizc-msg-time"><?php echo date('H:i'); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Image Preview -->
                <div class="bizc-img-preview" id="bizc-img-preview" style="display:none;"></div>
                
                <!-- Vision hint -->
                <div class="bizc-vision-hint" id="bizc-vision-hint" style="display:none;">
                    👁️ Vision model sẽ phân tích hình ảnh
                </div>
                
                <?php if ( ! $current_uid ) : ?>
                <!-- Guest trial hint -->
                <div class="bizc-guest-hint" id="bizc-guest-hint">
                    <span class="bizc-guest-hint-icon">🌟</span>
                    <span class="bizc-guest-hint-text">Bạn có <strong id="bizc-guest-remaining">3</strong> tin nhắn thử nghiệm. <a href="#" id="bizc-guest-signup-link">Đăng ký</a> để dùng không giới hạn!</span>
                </div>
                <?php endif; ?>
                
                <!-- Input -->
                <div class="bizc-input-area" id="bizc-input-area">
                    <input type="file" id="bizc-file-input" accept="image/*" multiple style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;">
                    
                    <!-- ═══ Pre-Intent Plugin Chips Bar ═══ -->
                    <div class="bizc-plugin-chips-bar" id="bizc-plugin-chips">
                        <div class="bizc-chips-scroll" id="bizc-chips-scroll">
                            <div class="bizc-chips-loading">Đang tải agents...</div>
                        </div>
                    </div>
                    
                    <!-- Plugin Context Header (shown when plugin selected) -->
                    <div class="bizc-plugin-context-header" id="bizc-context-header">
                        <span class="bizc-context-plugin-icon" id="bizc-context-icon">🤖</span>
                        <div class="bizc-context-tools-row" id="bizc-context-tools"></div>
                        <button class="bizc-context-close-btn" id="bizc-context-close" title="Thoát khỏi plugin mode">✕ Thoát</button>
                    </div>
                    
                    <!-- @mention autocomplete dropdown (ChatGPT style) -->
                    <div class="bizc-mention-dropdown" id="bizc-mention-dropdown"></div>
                    
                    <!-- Simple input container (like ChatGPT) -->
                    <div class="bizc-input-container">
                        <label for="bizc-file-input" class="bizc-attach-btn" id="bizc-attach" title="Đính kèm ảnh">📎</label>
                        <textarea class="bizc-input" id="bizc-input" placeholder="Nhập tin nhắn... (@ chọn agent · / tìm tool)" rows="1"></textarea>
                        <button class="bizc-send-btn" id="bizc-send" type="button">
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                </div><!-- /bizc-chat-panel -->

                <!-- Agent Template Panel (hidden, shown when Touch Bar agent clicked) -->
                <div id="bizc-agent-panel" style="display:none;flex:1;flex-direction:column;overflow:hidden;border-radius:18px;background:#fff;">
                    <div style="padding:0px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #e5e7eb;flex-shrink:0;">
                        <button id="bizc-agent-back" style="margin:5px !important; margin-left: 10px;width:36px;height:36px;border-radius:50%;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.3);cursor:pointer;font-size:18px;color:#6366f1;display:flex;align-items:center;justify-content:center;transition:all 0.2s;flex-shrink:0;" title="Quay lại chat" onmouseover="this.style.background='rgba(99,102,241,0.2)'; this.style.borderColor='rgba(99,102,241,0.5)';" onmouseout="this.style.background='rgba(99,102,241,0.1)'; this.style.borderColor='rgba(99,102,241,0.3)';">←</button>
                        <img id="bizc-agent-icon" src="" alt="" style="width:24px;height:24px;border-radius:6px;object-fit:cover;display:none;">
                        <span id="bizc-agent-title" style="font-weight:600;font-size:14px;color:#1a1a2e;flex:1;"></span>
                        <button id="bizc-agent-external" style="margin:5px !important;margin-right:10px;width:36px;height:36px;border-radius:50%;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.3);cursor:pointer;font-size:18px;color:#6366f1;display:flex;align-items:center;justify-content:center;transition:all 0.2s;flex-shrink:0;" title="Mở tab mới" onmouseover="this.style.background='rgba(99,102,241,0.2)'; this.style.borderColor='rgba(99,102,241,0.5)';" onmouseout="this.style.background='rgba(99,102,241,0.1)'; this.style.borderColor='rgba(99,102,241,0.3)';">↗</button>
                    </div>
                    <iframe id="bizc-agent-iframe" src="about:blank" style="flex:1;border:none;width:100%;"></iframe>
                </div>
                
            </div>
        </div>
        
        <?php if ($character): ?>
        <script>
        jQuery(function($) {
            // Prevent multiple initialization
            if (window.bizcDashInitialized) return;
            window.bizcDashInitialized = true;
            
            var baseSessionId = '<?php echo esc_js($session_id); ?>',
                sessionId = '<?php echo esc_js($session_id); ?>',
                nonce = '<?php echo esc_js($nonce); ?>',
                ajaxurl = '<?php echo admin_url("admin-ajax.php"); ?>',
                isGuest = <?php echo $current_uid ? 'false' : 'true'; ?>,
                guestMsgLimit = 3,
                guestMsgKey = 'bizc_guest_msg_count',
                $msgs = $('#bizc-messages'),
                $input = $('#bizc-input'),
                $send = $('#bizc-send'),
                botAvatar = <?php echo wp_json_encode($char_avatar ?: ''); ?>,
                messages = [],
                wcSessions = [],
                projects = [],
                currentWcId = null,        // webchat_conversations primary key
                lastMsgId = 0,             // last message ID for polling
                _renderedMsgIds = {},      // DB message IDs already rendered (dedup)
                _msgPollTimer = null,      // message polling interval
                currentProjectId = null,
                openProjects = {},
                pendingImages = [],
                dragSrcId = null,   // for drag & drop
                /* ═══ REST API Config (Phase 5.0 — AJAX→REST migration) ═══ */
                restUrl = '<?php echo esc_url( rest_url( "bizcity-chat/v1/" ) ); ?>',
                wpRestNonce = '<?php echo wp_create_nonce( "wp_rest" ); ?>',
                useRestApi = true;   // Feature flag: true = REST primary + AJAX fallback
            
            /**
             * Update page header (aiagent-header) with title and back button visibility
             * Used when switching between chat/agent views on mobile
             */
            function updatePageHeader(title, showBack) {
                var $headerTitle = $('#aiagent-header-title');
                var $backBtn = $('#aiagent-back-btn');
                if ($headerTitle.length) {
                    $headerTitle.text(title || 'Trò chuyện gần đây');
                }
                if ($backBtn.length) {
                    if (showBack) {
                        $backBtn.show();
                    } else {
                        $backBtn.hide();
                    }
                }
            }
            
            // Back button click handler
            $('#aiagent-back-btn').on('click', function() {
                hideAgentPanel();
                updatePageHeader('Trò chuyện gần đây', false);
            });
            
            // Init
            loadProjects();
            loadSessions(true); // true = initial load, auto-select most recent
            loadIntentConversations();
            _loadPluginChips(); // Pre-Intent: load plugin chips bar
            
            // Set Chat button as active initially (default view)
            $('.bizc-tb-chat').addClass('active');
            
            // Event delegation for sessions in "Gần đây" section
            $('#bizc-convs-list').off('click').on('click', '.bizc-conv', function(e) {
                var wcId = $(this).data('wc-id');
                if (wcId) {
                    if (typeof window.closeMobileSidebar === 'function') window.closeMobileSidebar();
                    loadSession(wcId);
                }
            });

            // Event delegation for sessions inside projects
            $('#bizc-proj-list').off('click', '.bizc-proj-conv').on('click', '.bizc-proj-conv', function(e) {
                var wcId = $(this).data('wc-id');
                if (wcId) {
                    if (typeof window.closeMobileSidebar === 'function') window.closeMobileSidebar();
                    loadSession(wcId);
                }
            });
            
            // Events
            $('#bizc-new-chat').off('click').on('click', function() {
                if (typeof window.closeMobileSidebar === 'function') window.closeMobileSidebar();
                startNewChat();
            });
            $('#bizc-clear-all').off('click').on('click', clearAllSessions);
            $('#bizc-add-project').off('click').on('click', showAddProjectForm);

            // ── Touch Bar: Chat button → return to chat ──
            $('#bizc-touchbar').on('click', '.bizc-tb-chat', function(e) {
                e.preventDefault();
                hideAgentPanel();
            });

            // ── Touch Bar: agent plugin clicks (lazy-load iframe) ──
            $('#bizc-touchbar').on('click', '.bizc-tb-agent', function(e) {
                e.preventDefault();
                var $btn = $(this),
                    slug = $btn.data('slug'),
                    rawSrc = $btn.data('src') || '',
                    templateUrl = rawSrc + (rawSrc.indexOf('?') > -1 ? '&' : '?') + 'bizcity_iframe=1';

                // Toggle: if already showing this agent, go back to chat
                if ($('#bizc-agent-panel').is(':visible') && $('#bizc-agent-panel').data('slug') === slug) {
                    hideAgentPanel();
                    return;
                }

                showAgentBySlug(slug, $btn.attr('title') || slug, $btn.find('.bizc-tb-icon img').attr('src') || '', templateUrl);

                // Remove chat button active state
                $('.bizc-tb-chat').removeClass('active');

                // Update URL
                bizcPushUrl('agent', slug);
            });

            // ── Touch Bar: non-agent link clicks (Hồ sơ, Kiến thức, Chợ AI) → open in iframe (lazy-load) ──
            $('#bizc-touchbar').on('click', '.bizc-tb-link', function(e) {
                e.preventDefault();
                var $btn = $(this),
                    rawSrc = $btn.data('src') || '',
                    iframeUrl = rawSrc + (rawSrc.indexOf('?') > -1 ? '&' : '?') + 'bizcity_iframe=1',
                    key = 'link_' + rawSrc;

                // Toggle: if already showing this link, go back to chat
                if ($('#bizc-agent-panel').is(':visible') && $('#bizc-agent-panel').data('slug') === key) {
                    hideAgentPanel();
                    return;
                }

                // Set active state
                $('.bizc-tb-agent').removeClass('active');
                $('.bizc-tb-link').removeClass('active');
                $('.bizc-tb-chat').removeClass('active');
                $btn.addClass('active');

                // Populate header
                var title = $btn.attr('title') || 'Page';
                $('#bizc-agent-title').text(title);
                $('#bizc-agent-icon').hide();

                // Lazy-load iframe: clear old content first, then load new
                loadAgentIframe(iframeUrl);
                $('#bizc-agent-panel').data('slug', key).css('display', 'flex');
                $('#bizc-chat-panel').hide();
                $('#bizc-project-detail').hide();

                // Update URL with panel param
                var pageName = rawSrc.replace(/.*[?&]page=([^&]+).*/, '$1');
                bizcPushUrl('panel', pageName || 'page');
            });

            $('#bizc-agent-back').on('click', function() { hideAgentPanel(); });
            $('#bizc-agent-external').on('click', function() {
                var src = $('#bizc-agent-iframe').attr('src');
                if (src && src !== 'about:blank') window.open(src, '_blank');
            });

            // Close context menu on click elsewhere
            $(document).off('click.ctxmenu').on('click.ctxmenu', function() {
                $('.bizc-ctx-menu').remove();
            });
            
            // File input handler (label will auto-trigger via for="bizc-file-input")
            var fileInput = document.getElementById('bizc-file-input');
            
            if (fileInput) {
                var handleFileChange = function(e) {
                    console.log('File input changed:', e.target.files.length, 'file(s)');
                    if (e.target.files.length > 0) {
                        handleImages(e.target.files);
                    }
                    this.value = ''; // Clear for reselection
                };
                
                if (!window.bizcFileHandler) {
                    window.bizcFileHandler = handleFileChange;
                    fileInput.addEventListener('change', handleFileChange);
                    console.log('File input handler initialized');
                }
            } else {
                console.error('File input not found!');
            }
            
            $send.off('click').on('click', sendMsg);
            
            // Combined handler for keydown - handles both @mention and regular Enter
            // (This replaces the old simple keydown handler)
            
            // Combined handler for input - handles both @mention and auto-resize  
            // (This replaces the old simple input handler)

            // ════════════════════════════════════════════════════════════
            //  PRE-INTENT — Plugin Chips Bar + Auto-Suggest
            //
            //  Always-visible horizontal plugin chips above the input.
            //  On typing (debounced 400ms), calls bizcity_pre_intent_estimate
            //  to highlight which plugin(s) match the draft message.
            //  Click a chip → enters Logic 2 (manual routing) directly.
            //  Reduces Intent Engine load by pre-selecting plugin.
            // ════════════════════════════════════════════════════════════
            var _preIntentTimer = null;
            var _preIntentLastQuery = '';
            var _preIntentChipsLoaded = false;
            var $chipsBar = $('#bizc-plugin-chips');
            var $chipsScroll = $('#bizc-chips-scroll');

            // Load plugin chips on init
            function _loadPluginChips() {
                // Use the same agent list from @mention API
                _loadMentionAgents(function(agents) {
                    if (!agents || !agents.length) {
                        $chipsScroll.html('<div class="bizc-chips-loading">Không có agent nào</div>');
                        return;
                    }

                    var html = '';
                    agents.forEach(function(a) {
                        var iconHtml = (a.icon && a.icon.indexOf('/') > -1) 
                            ? '<img class="bizc-chip-icon" src="' + esc(a.icon) + '" alt="">'
                            : '<span class="bizc-chip-icon-emoji">' + (a.icon || '🤖') + '</span>';
                        
                        html += '<div class="bizc-plugin-chip" '
                            + 'data-slug="' + esc(a.slug) + '" '
                            + 'data-label="' + esc(a.label || a.title) + '" '
                            + 'data-icon="' + esc(a.icon || '🤖') + '">'
                            + '<span class="bizc-chip-suggest-dot"></span>'
                            + iconHtml + ' '
                            + esc(a.label || a.title)
                            + '</div>';
                    });

                    $chipsScroll.html(html);
                    _preIntentChipsLoaded = true;
                });
            }

            // Auto-suggest: call pre_intent_estimate on typing
            function _preIntentEstimate(text) {
                if (!text || text.length < 3) {
                    // Reset all chip states
                    $chipsScroll.find('.bizc-plugin-chip').removeClass('suggested');
                    return;
                }

                // Skip if text unchanged
                if (text === _preIntentLastQuery) return;
                _preIntentLastQuery = text;

                // Skip if already in manual mode (user already selected a chip)
                if (_mentionProvider) return;

                $.post(ajaxurl, {
                    action: 'bizcity_pre_intent_estimate',
                    message: text,
                    _wpnonce: nonce
                }, function(response) {
                    if (!response.success || !response.data) return;
                    
                    var suggestions = response.data.suggestions || [];
                    var highlight = response.data.highlight || '';

                    // Reset all chips
                    $chipsScroll.find('.bizc-plugin-chip').removeClass('suggested');

                    // Highlight matched chips
                    suggestions.forEach(function(s) {
                        $chipsScroll.find('.bizc-plugin-chip[data-slug="' + s.slug + '"]')
                            .addClass('suggested');
                    });

                    // Reorder: move suggested chips to front
                    if (suggestions.length > 0) {
                        var slugOrder = suggestions.map(function(s) { return s.slug; });
                        var $chips = $chipsScroll.find('.bizc-plugin-chip').detach();
                        var sorted = [];
                        var rest = [];
                        
                        $chips.each(function() {
                            var idx = slugOrder.indexOf($(this).data('slug'));
                            if (idx >= 0) {
                                sorted[idx] = this;
                            } else {
                                rest.push(this);
                            }
                        });
                        
                        // Filter nulls from sorted and append
                        sorted = sorted.filter(function(el) { return !!el; });
                        $chipsScroll.append(sorted).append(rest);

                        // Scroll to beginning
                        $chipsScroll[0].scrollLeft = 0;
                    }

                    console.log('🎯 [Pre-Intent] Estimate:', suggestions.length, 'matches, highlight:', highlight);
                });
            }

            // Chip click → select plugin (enters Logic 2 manual routing)
            $chipsScroll.on('click', '.bizc-plugin-chip', function() {
                var $chip = $(this);
                var slug = $chip.data('slug');
                var label = $chip.data('label');
                var icon = $chip.data('icon');

                // Toggle: if already active, deselect
                if ($chip.hasClass('active')) {
                    $chipsScroll.find('.bizc-plugin-chip').removeClass('active');
                    _clearMention();
                    $input.attr('placeholder', 'Nhập tin nhắn... (@ chọn agent · / tìm tool)');
                    $input.focus();
                    return;
                }

                // Deactivate all, activate this one
                $chipsScroll.find('.bizc-plugin-chip').removeClass('active');
                $chip.addClass('active');

                // Reuse @mention selection flow → enters plugin-context-mode
                _selectMention(slug, label, icon);
                $input.attr('placeholder', 'Nhắn với ' + label + '...');
                $input.focus();
            });

            // Debounced input handler for pre-intent estimate
            $input.on('input', function() {
                // Don't run pre-intent if @mention dropdown is active
                if (_mentionActive) return;

                clearTimeout(_preIntentTimer);
                var text = $input.val().trim();

                _preIntentTimer = setTimeout(function() {
                    _preIntentEstimate(text);
                }, 400);
            });

            // Sync chip active state with _clearMention
            var _origClearMention; // will be wrapped after _clearMention is defined

            // ════════════════════════════════════════════════════════════
            //  @Mention — Autocomplete Agent Targeting
            //
            //  Type @ in the input to see a dropdown of available agents.
            //  Select an agent to target commands directly to that plugin.
            //  The provider_hint is sent to the Intent Engine to bias
            //  classification toward the selected agent's goals.
            // ════════════════════════════════════════════════════════════
            var _mentionProvider = null; // { slug, label, icon }
            var _mentionQuery = '';
            var _mentionActive = false;
            var _mentionIdx = 0; // selected index in dropdown
            var $mentionDrop = $('#bizc-mention-dropdown');
            var $mentionTag = $('#bizc-mention-tag');
            
            console.log('Mention system initialized. $mentionDrop found:', $mentionDrop.length > 0);

            // Load agent list from Plugin Suggestion API
            function _getMentionAgents() {
                // Return cached agents if available
                if (window.bizcMentionAgentsCache && window.bizcMentionAgentsCache.length > 0) {
                    return window.bizcMentionAgentsCache;
                }
                return [];
            }
            
            // Load agents from Plugin Suggestion API (async)
            function _loadMentionAgents(callback) {
                console.log('_loadMentionAgents called');
                
                // Check cache first (disabled for debugging)
                // if (window.bizcMentionAgentsCache && Date.now() - window.bizcMentionAgentsCacheTime < 30000) {
                //     console.log('Using cached agents');
                //     callback(window.bizcMentionAgentsCache);
                //     return;
                // }
                
                console.log('Fetching agents from API, ajaxurl:', ajaxurl, 'nonce:', nonce);
                
                // Fetch from API
                $.post(ajaxurl, {
                    action: 'bizcity_get_plugin_suggestions',
                    search: '',
                    _wpnonce: nonce
                }, function(response) {
                    console.log('API Response received:', response);
                    
                    // Handle both response formats:
                    // 1. response.data.suggestions (new format)
                    // 2. response.data (array directly)
                    var plugins = [];
                    if (response.success && response.data) {
                        if (response.data.suggestions && Array.isArray(response.data.suggestions)) {
                            plugins = response.data.suggestions;
                            console.log('Found suggestions array:', plugins.length, 'items');
                        } else if (Array.isArray(response.data)) {
                            plugins = response.data;
                            console.log('Found data array:', plugins.length, 'items');
                        } else {
                            console.log('Unexpected data format:', typeof response.data);
                        }
                    } else {
                        console.log('Response not successful or no data:', response);
                    }
                    
                    if (plugins.length > 0) {
                        var agents = plugins.map(function(plugin) {
                            return {
                                slug: plugin.slug,
                                label: plugin.name,
                                title: plugin.name,
                                icon: plugin.icon_url || plugin.icon || '🤖',
                                description: plugin.description
                            };
                        });
                        
                        // Cache results
                        window.bizcMentionAgentsCache = agents;
                        window.bizcMentionAgentsCacheTime = Date.now();
                        
                        console.log('Processed agents:', agents);
                        callback(agents);
                    } else {
                        console.log('No plugins found in response, returning empty');
                        callback([]);
                    }
                }).fail(function(xhr, status, error) {
                    console.error('AJAX Failed:', status, error, xhr.responseText);
                    callback([]);
                });
            }

            // Render dropdown with ChatGPT-style UI
            function _renderMentionDropdown(items) {
                console.log('Rendering mention dropdown with items:', items);
                
                if (!items || !items.length) {
                    console.log('No items to render, hiding dropdown');
                    $mentionDrop.html('<div class="bizc-mention-header">Không tìm thấy agent</div>').addClass('active');
                    return;
                }
                
                _mentionIdx = 0;
                
                var html = '<div class="bizc-mention-header">Chọn Agent</div>';
                html += '<div class="bizc-mention-list">';
                
                items.forEach(function(a, i) {
                    var iconHtml = (a.icon && a.icon.indexOf('/') > -1)
                        ? '<img src="' + a.icon + '" alt="">'
                        : (a.icon || '🤖');
                    
                    html += '<div class="bizc-mention-item' + (i === 0 ? ' selected' : '') + '" data-idx="' + i + '" data-slug="' + a.slug + '" data-label="' + (a.title || a.label) + '" data-icon="' + (a.icon || '🤖') + '">'
                        + '<div class="bizc-mention-item-icon">' + iconHtml + '</div>'
                        + '<div class="bizc-mention-item-info">'
                        + '<div class="bizc-mention-item-name">' + (a.title || a.label) + '</div>'
                        + '<div class="bizc-mention-item-slug">@' + a.slug + '</div>'
                        + '</div></div>';
                });
                
                html += '</div>';
                
                console.log('Dropdown HTML generated, setting active');
                $mentionDrop.html(html).addClass('active');
                _mentionActive = true;
            }

            // Enhanced mention search with debouncing
            var _mentionSearchTimer = null;
            function _searchMentionAgents(query) {
                console.log('Searching agents with query:', query);
                clearTimeout(_mentionSearchTimer);
                _mentionSearchTimer = setTimeout(function() {
                    console.log('Debounce complete, loading agents...');
                    _loadMentionAgents(function(agents) {
                        console.log('Got agents callback:', agents);
                        if (query && query.trim()) {
                            var filtered = _filterMentionAgents(query, agents);
                            console.log('Filtered agents:', filtered);
                            _renderMentionDropdown(filtered);
                        } else {
                            _renderMentionDropdown(agents);
                        }
                    });
                }, 150); // Debounce 150ms
            }

            // Improved filter function
            function _filterMentionAgents(query, agents) {
                if (!query) return agents || [];
                var q = query.toLowerCase();
                return (agents || []).filter(function(a) {
                    return (a.slug && a.slug.toLowerCase().indexOf(q) > -1)
                        || (a.label && a.label.toLowerCase().indexOf(q) > -1)
                        || (a.title && a.title.toLowerCase().indexOf(q) > -1)
                        || (a.description && a.description.toLowerCase().indexOf(q) > -1);
                }).sort(function(a, b) {
                    // Sort by relevance - exact matches first
                    var aScore = 0, bScore = 0;
                    if (a.slug.toLowerCase().indexOf(q) === 0) aScore += 10;
                    if (a.label.toLowerCase().indexOf(q) === 0) aScore += 8;
                    if (b.slug.toLowerCase().indexOf(q) === 0) bScore += 10;
                    if (b.label.toLowerCase().indexOf(q) === 0) bScore += 8;
                    return bScore - aScore;
                });
            }

            // Select a mention agent
            function _selectMention(slug, label, icon) {
                _mentionProvider = { slug: slug, label: label, icon: icon };
                _mentionActive = false;
                _mentionIdx = 0;
                $mentionDrop.removeClass('active').empty();

                // Remove @query from textarea
                var val = $input.val();
                var atMatch = val.match(/@[\w-]*$/);
                if (atMatch) {
                    $input.val(val.substring(0, val.length - atMatch[0].length));
                }

                // Show tag badge
                var iconHtml = (icon && icon.indexOf('/') > -1)
                    ? '<img src="' + icon + '" style="width:16px;height:16px;border-radius:4px;vertical-align:middle;" alt="">'
                    : (icon || '🤖');
                $mentionTag.html(iconHtml + ' ' + label + ' <span class="bizc-mt-remove" title="Bỏ chọn agent">✕</span>').show();
                
                // Enter plugin context mode (if not called from pill selection)
                if (!$('.bizc-pill[data-slug="' + slug + '"]').hasClass('active')) {
                    enterPluginContextMode(slug, label, icon);
                    
                    // Also activate the corresponding pill if exists
                    $('.bizc-pill[data-slug="' + slug + '"]').addClass('active');
                }
                
                // Sync Pre-Intent chips bar: activate the matching chip
                $chipsScroll.find('.bizc-plugin-chip').removeClass('active suggested');
                $chipsScroll.find('.bizc-plugin-chip[data-slug="' + slug + '"]').addClass('active');
                
                $input.focus();
            }

            // Clear mention
            function _clearMention() {
                _mentionProvider = null;
                $mentionTag.hide().empty();
                exitPluginContextMode();
                // Sync: deactivate all chips + reset placeholder
                $chipsScroll.find('.bizc-plugin-chip').removeClass('active');
                $input.attr('placeholder', 'Nhập tin nhắn... (@ chọn agent · / tìm tool)');
                _preIntentLastQuery = ''; // allow re-estimate
            }

            // ════════════════════════════════════════════════════════════
            //  / Slash Command — Tool-level Search & Selection
            //
            //  Type / in the input to search tools from bizcity_tool_registry.
            //  Reuses the @mention dropdown UI with tool-specific rendering.
            //  When user selects a tool:
            //    1. Auto-select the tool's plugin (enter plugin-context-mode)
            //    2. Set the specific goal for the Intent Engine
            //    3. Update context header with tool label
            //
            //  @since v4.0.0 (Phase 13 — Dual Context Architecture)
            // ════════════════════════════════════════════════════════════
            var _slashActive = false;
            var _slashQuery = '';
            var _slashIdx = 0;
            var _slashSearchTimer = null;
            var _selectedTool = null; // { goal, tool_name, title, goal_label, plugin_slug }
            var _contextToolsCache = {}; // { slug: [tools] }

            /**
             * Load tools for a plugin and render as inline chips in the context header.
             * Cached per slug so repeated enters don't re-fetch.
             * @param {string} pluginSlug
             */
            function _loadContextTools(pluginSlug) {
                var $row = $('#bizc-context-tools');
                if (!pluginSlug) {
                    $row.html('<span style="color:#9ca3af;font-size:10px;">Nhấn / để tìm tools</span>');
                    return;
                }
                // Use cache
                if (_contextToolsCache[pluginSlug]) {
                    _renderContextToolChips(_contextToolsCache[pluginSlug], pluginSlug);
                    return;
                }
                $row.html('<span style="color:#9ca3af;font-size:10px;">Đang tải tools...</span>');
                $.post(ajaxurl, {
                    action: 'bizcity_search_tools',
                    query: '',
                    plugin_slug: pluginSlug,
                    limit: 20,
                    _wpnonce: nonce
                }, function(resp) {
                    var tools = (resp.success && resp.data && resp.data.tools) ? resp.data.tools : [];
                    _contextToolsCache[pluginSlug] = tools;
                    _renderContextToolChips(tools, pluginSlug);
                }).fail(function() {
                    $row.html('<span style="color:#9ca3af;font-size:10px;">Nhấn / để tìm tools</span>');
                });
            }

            /**
             * Render tool chips inside the context header tools row.
             * @param {Array}  tools
             * @param {string} pluginSlug
             */
            function _renderContextToolChips(tools, pluginSlug) {
                var $row = $('#bizc-context-tools');
                if (!tools || !tools.length) {
                    $row.html('<span style="color:#9ca3af;font-size:10px;">Nhấn / để tìm tools</span>');
                    return;
                }
                var html = '';
                tools.forEach(function(t) {
                    var label = t.goal_label || t.title || t.goal;
                    var activeClass = (_selectedTool && _selectedTool.goal === t.goal) ? ' active' : '';
                    html += '<span class="bizc-tool-chip' + activeClass + '" '
                        + 'data-goal="' + esc(t.goal) + '" '
                        + 'data-tool-name="' + esc(t.tool_name) + '" '
                        + 'data-title="' + esc(t.title || t.goal_label) + '" '
                        + 'data-goal-label="' + esc(t.goal_label) + '" '
                        + 'data-plugin-slug="' + esc(t.plugin_slug || pluginSlug) + '" '
                        + 'data-plugin-name="' + esc(t.plugin_name) + '" '
                        + 'data-icon="' + esc(t.icon || '🔧') + '">'
                        + esc(label)
                        + '</span>';
                });
                $row.html(html);
            }

            /**
             * Search tools via AJAX (debounced).
             * @param {string} query  Keyword from /command
             */
            function _searchTools(query) {
                clearTimeout(_slashSearchTimer);
                _slashSearchTimer = setTimeout(function() {
                    var params = {
                        action: 'bizcity_search_tools',
                        query: query || '',
                        _wpnonce: nonce
                    };
                    // If already in plugin context, scope to that plugin
                    if (_mentionProvider && _mentionProvider.slug) {
                        params.plugin_slug = _mentionProvider.slug;
                    }

                    $.post(ajaxurl, params, function(response) {
                        if (!response.success || !response.data) {
                            _renderSlashDropdown([]);
                            return;
                        }
                        _renderSlashDropdown(response.data.tools || []);
                    }).fail(function() {
                        _renderSlashDropdown([]);
                    });
                }, 150); // Debounce 150ms
            }

            /**
             * Render tool search results in the mention dropdown.
             * @param {Array} tools  Array of tool objects from API
             */
            function _renderSlashDropdown(tools) {
                if (!tools || !tools.length) {
                    $mentionDrop.html(
                        '<div class="bizc-mention-header">🔍 Tìm kiếm Tools</div>' +
                        '<div style="padding:12px 16px;color:#9ca3af;font-size:12px;">Không tìm thấy tool nào' +
                        (_slashQuery ? ' cho "' + esc(_slashQuery) + '"' : '') + '</div>'
                    ).addClass('active');
                    _slashActive = true;
                    return;
                }

                _slashIdx = 0;

                var html = '<div class="bizc-mention-header">🔧 Chọn Tool</div>';
                html += '<div class="bizc-mention-list">';

                tools.forEach(function(t, i) {
                    var iconHtml = (t.icon && t.icon.indexOf('/') > -1)
                        ? '<img src="' + esc(t.icon) + '" alt="" style="width:20px;height:20px;border-radius:4px;">'
                        : '🔧';

                    var desc = t.goal_description || t.title || '';
                    if (desc.length > 60) desc = desc.substring(0, 57) + '...';

                    html += '<div class="bizc-mention-item' + (i === 0 ? ' selected' : '') + '" '
                        + 'data-idx="' + i + '" '
                        + 'data-goal="' + esc(t.goal) + '" '
                        + 'data-tool-name="' + esc(t.tool_name) + '" '
                        + 'data-title="' + esc(t.title || t.goal_label) + '" '
                        + 'data-goal-label="' + esc(t.goal_label) + '" '
                        + 'data-plugin-slug="' + esc(t.plugin_slug) + '" '
                        + 'data-plugin-name="' + esc(t.plugin_name) + '" '
                        + 'data-icon="' + esc(t.icon || '🔧') + '" '
                        + 'data-type="tool">'
                        + '<div class="bizc-mention-item-icon">' + iconHtml + '</div>'
                        + '<div class="bizc-mention-item-info">'
                        + '<div class="bizc-mention-item-name">' + esc(t.goal_label || t.title) + '</div>'
                        + '<div class="bizc-mention-item-slug">'
                        + '<span style="color:#6366f1;">/' + esc(t.goal) + '</span>'
                        + (t.plugin_name ? ' · ' + esc(t.plugin_name) : '')
                        + '</div>'
                        + (desc ? '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">' + esc(desc) + '</div>' : '')
                        + '</div></div>';
                });

                html += '</div>';

                $mentionDrop.html(html).addClass('active');
                _slashActive = true;
            }

            /**
             * User selects a tool from the / dropdown.
             * Auto-selects the parent plugin and enters focused tool mode.
             */
            function _selectTool(goal, toolName, title, goalLabel, pluginSlug, pluginName, icon) {
                _selectedTool = {
                    goal: goal,
                    tool_name: toolName,
                    title: title,
                    goal_label: goalLabel,
                    plugin_slug: pluginSlug
                };

                // Close dropdown
                _slashActive = false;
                _slashIdx = 0;
                $mentionDrop.removeClass('active').empty();

                // Remove / query from textarea
                var val = $input.val();
                var slashMatch = val.match(/\/[\S]*$/);
                if (slashMatch) {
                    $input.val(val.substring(0, val.length - slashMatch[0].length));
                }

                // Auto-select plugin (enters plugin-context-mode)
                if (pluginSlug && (!_mentionProvider || _mentionProvider.slug !== pluginSlug)) {
                    _selectMention(pluginSlug, pluginName || pluginSlug, icon || '🔧');
                }

                // Update placeholder to hint tool usage
                var toolLabel = goalLabel || title || goal;
                $input.attr('placeholder', 'Mô tả yêu cầu cho ' + toolLabel + '...');

                // Show mention tag with tool info
                var iconHtml = (icon && icon.indexOf('/') > -1)
                    ? '<img src="' + esc(icon) + '" style="width:16px;height:16px;border-radius:4px;vertical-align:middle;" alt="">'
                    : '🔧';
                $mentionTag.html(iconHtml + ' ' + esc(toolLabel)
                    + ' <span class="bizc-mt-remove" title="Thoát tool">✕</span>').show();

                console.log('🔧 [Slash] Tool selected:', goal, 'plugin:', pluginSlug);
                $input.focus();
            }

            /**
             * Clear tool selection (but may keep plugin selection).
             */
            function _clearToolSelection() {
                _selectedTool = null;
                _slashActive = false;
                _slashIdx = 0;
                $mentionDrop.removeClass('active').empty();
                // Clear active tool chip highlight
                $('.bizc-tool-chip').removeClass('active');
            }

            /**
             * Get currently selected tool (for sendMsg to include in request).
             * @return {object|null} { goal, tool_name, plugin_slug }
             */
            function _getSelectedTool() {
                return _selectedTool;
            }

            // Click handler for tool items in dropdown (delegated from mention dropdown)
            $mentionDrop.on('click', '.bizc-mention-item[data-type="tool"]', function(e) {
                e.stopImmediatePropagation(); // Prevent @mention click handler
                var $el = $(this);
                _selectTool(
                    $el.data('goal'),
                    $el.data('tool-name'),
                    $el.data('title'),
                    $el.data('goal-label'),
                    $el.data('plugin-slug'),
                    $el.data('plugin-name'),
                    $el.data('icon')
                );
            });

            // Click handler for dropdown items
            $mentionDrop.on('click', '.bizc-mention-item', function() {
                var $el = $(this);
                _selectMention($el.data('slug'), $el.data('label'), $el.data('icon'));
            });

            // Click handler for removing mention tag
            $mentionTag.on('click', '.bizc-mt-remove', function() {
                _clearMention();
                _clearToolSelection();
                $input.focus();
            });

            // Close dropdown on outside click
            $(document).on('mousedown', function(e) {
                if (!$(e.target).closest('#bizc-mention-dropdown, #bizc-input').length) {
                    $mentionDrop.removeClass('active').empty();
                    _mentionActive = false;
                }
            });

            // Enhanced keyboard navigation for @mention and /slash dropdown
            $input.on('keydown', function(e) {
                // Handle /slash command navigation (same UI as @mention)
                if (_slashActive) {
                    var items = $mentionDrop.find('.bizc-mention-item');
                    if (!items.length) return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        _slashIdx = Math.min(_slashIdx + 1, items.length - 1);
                        items.removeClass('selected').eq(_slashIdx).addClass('selected');
                        var $selected = items.eq(_slashIdx);
                        var dropdown = $mentionDrop[0];
                        var itemTop = $selected.position().top;
                        var itemBottom = itemTop + $selected.outerHeight();
                        var dropdownHeight = $mentionDrop.height();
                        if (itemBottom > dropdownHeight) dropdown.scrollTop += itemBottom - dropdownHeight;
                        else if (itemTop < 0) dropdown.scrollTop += itemTop;
                        return;
                    }
                    if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        _slashIdx = Math.max(_slashIdx - 1, 0);
                        items.removeClass('selected').eq(_slashIdx).addClass('selected');
                        var $sel2 = items.eq(_slashIdx);
                        if ($sel2.position().top < 0) $mentionDrop[0].scrollTop += $sel2.position().top;
                        return;
                    }
                    if (e.key === 'Enter' || e.key === 'Tab') {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        var $sel = items.eq(_slashIdx);
                        if ($sel.data('type') === 'tool') {
                            _selectTool(
                                $sel.data('goal'),
                                $sel.data('tool-name'),
                                $sel.data('title'),
                                $sel.data('goal-label'),
                                $sel.data('plugin-slug'),
                                $sel.data('plugin-name'),
                                $sel.data('icon')
                            );
                        }
                        return;
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        $mentionDrop.removeClass('active').empty();
                        _slashActive = false;
                        _slashIdx = 0;
                        return;
                    }
                }

                // Handle existing @ mention navigation
                if (_mentionActive) {
                    var items = $mentionDrop.find('.bizc-mention-item');
                    if (!items.length) return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        _mentionIdx = Math.min(_mentionIdx + 1, items.length - 1);
                        items.removeClass('selected').eq(_mentionIdx).addClass('selected');
                        
                        // Scroll into view
                        var $selected = items.eq(_mentionIdx);
                        var dropdown = $mentionDrop[0];
                        var itemTop = $selected.position().top;
                        var itemBottom = itemTop + $selected.outerHeight();
                        var dropdownHeight = $mentionDrop.height();
                        
                        if (itemBottom > dropdownHeight) {
                            dropdown.scrollTop += itemBottom - dropdownHeight;
                        } else if (itemTop < 0) {
                            dropdown.scrollTop += itemTop;
                        }
                        return;
                    }
                    if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        _mentionIdx = Math.max(_mentionIdx - 1, 0);
                        items.removeClass('selected').eq(_mentionIdx).addClass('selected');
                        
                        // Scroll into view
                        var $selected = items.eq(_mentionIdx);
                        var itemTop = $selected.position().top;
                        if (itemTop < 0) {
                            $mentionDrop[0].scrollTop += itemTop;
                        }
                        return;
                    }
                    if (e.key === 'Enter' || e.key === 'Tab') {
                        e.preventDefault();
                        e.stopImmediatePropagation(); // Prevent sendMsg() from firing
                        var $sel = items.eq(_mentionIdx);
                        _selectMention($sel.data('slug'), $sel.data('label'), $sel.data('icon'));
                        return;
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        $mentionDrop.removeClass('active').empty();
                        _mentionActive = false;
                        _mentionIdx = 0;
                        return;
                    }
                }
                
                // Handle regular Enter for sending message (when not in mention/slash mode)  
                if (e.key === 'Enter' && !e.shiftKey && !_mentionActive && !_slashActive) {
                    e.preventDefault();
                    sendMsg();
                }
            });

// Watch input for @ and / triggers with async loading
            $input.on('input', function() {
                var val = $input.val();

                // ── / Slash command detection ──
                // Match /keyword at start of input OR after whitespace
                var slashMatch = val.match(/(?:^|\s)\/([\S]*)$/);
                if (slashMatch) {
                    _slashQuery = slashMatch[1] || '';
                    console.log('/ detected, query:', _slashQuery);
                    
                    // Close @mention if active
                    if (_mentionActive) {
                        _mentionActive = false;
                    }

                    // Show loading state
                    $mentionDrop.html(
                        '<div class="bizc-mention-header">🔍 Tìm kiếm Tools...</div>' +
                        '<div style="padding:12px 16px;text-align:center;color:#9ca3af;font-size:12px;">⏳ Đang tải...</div>'
                    ).addClass('active');
                    _slashActive = true;

                    // Search tools
                    _searchTools(_slashQuery);
                }
                // ── @ Mention detection ──
                else {
                    var atMatch = val.match(/@([\w-]*)$/);
                    if (atMatch) {
                        _mentionQuery = atMatch[1];
                        console.log('@ detected, query:', _mentionQuery);
                        
                        // Close /slash if active
                        if (_slashActive) {
                            _slashActive = false;
                        }

                        // Show loading state
                        $mentionDrop.html('<div class="bizc-mention-header">Đang tải...</div>' +
                            '<div style="padding: 16px; text-align: center; color: #9ca3af; font-size: 12px;">⏳ Loading agents...</div>').addClass('active');
                        _mentionActive = true;
                        
                        // Load and filter agents
                        _searchMentionAgents(_mentionQuery);
                    } else if (_mentionActive || _slashActive) {
                        $mentionDrop.removeClass('active').empty();
                        _mentionActive = false;
                        _slashActive = false;
                    }
                }
                
                // Also handle auto-resize
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                updateBtn();
            });
            
            // Tool cards are now links to BizCoach Map steps
            // No click handler needed

            // ── Projects (ChatGPT-style folders) ──
            function loadProjects() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'bizcity_project_list', _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        projects = (res.success && res.data) ? res.data : [];
                        renderProjects();
                    },
                    error: function() { projects = []; renderProjects(); }
                });
            }

            function renderProjects() {
                var $list = $('#bizc-proj-list').empty();
                if (!projects.length) {
                    $list.append('<div style="padding:8px 12px;color:#6b7280;font-size:12px;">Chưa có dự án</div>');
                    return;
                }
                projects.forEach(function(proj) {
                    var isOpen = openProjects[proj.id] || false;
                    var $item = $('<div class="bizc-proj-item" data-project-id="' + esc(proj.id) + '"></div>');

                    var $header = $('<div class="bizc-proj-header' + (isOpen ? ' active' : '') + '">' +
                        '<span class="bizc-proj-arrow' + (isOpen ? ' open' : '') + '">▶</span>' +
                        '<span class="bizc-proj-icon">' + esc(proj.icon || '📁') + '</span>' +
                        '<span class="bizc-proj-name">' + esc(proj.name) + '</span>' +
                        '<span class="bizc-proj-count">' + (proj.session_count || proj.conv_count || 0) + '</span>' +
                        '<span class="bizc-proj-menu-btn" title="Menu">⋯</span>' +
                        '</div>');

                    var $convs = $('<div class="bizc-proj-convs' + (isOpen ? '' : ' collapsed') + '"></div>');

                    // Toggle + show project detail
                    $header.on('click', function(e) {
                        if ($(e.target).hasClass('bizc-proj-menu-btn')) return;
                        var wasOpen = openProjects[proj.id] || false;
                        openProjects[proj.id] = !wasOpen;
                        $header.toggleClass('active');
                        $header.find('.bizc-proj-arrow').toggleClass('open');
                        $convs.toggleClass('collapsed');
                        if (!wasOpen) {
                            loadProjectSessions(proj.id, $convs);
                            showProjectDetail(proj);
                        } else {
                            hideProjectDetail();
                        }
                    });

                    // Menu button
                    $header.find('.bizc-proj-menu-btn').on('click', function(e) {
                        e.stopPropagation();
                        showProjectMenu(e.pageX, e.pageY, proj);
                    });

                    // Drop target for drag & drop (improved visual feedback)
                    $item[0].addEventListener('dragover', function(e) { 
                        e.preventDefault(); 
                        e.dataTransfer.dropEffect = 'move';
                        $item.addClass('drag-over');
                    });
                    $item[0].addEventListener('dragleave', function(e) { 
                        // Only remove highlight if leaving the item entirely
                        if (!$item[0].contains(e.relatedTarget)) {
                            $item.removeClass('drag-over');
                        }
                    });
                    $item[0].addEventListener('drop', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        $item.removeClass('drag-over');
                        var rawData = e.dataTransfer.getData('text/plain');
                        var wcId = parseInt(rawData);
                        if (wcId && wcId > 0) {
                            moveSessionToProject(wcId, proj.id);
                        } else {
                            console.warn('[bizc-dash] DROP: invalid wcId, skipping move');
                        }
                    });

                    $item.append($header).append($convs);
                    $list.append($item);
                    if (isOpen) loadProjectSessions(proj.id, $convs);
                });
            }

            function loadProjectSessions(projectId, $container) {
                $container.html('<div style="padding:6px 12px 6px 32px;color:#9ca3af;font-size:11px;">Đang tải...</div>');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'bizcity_webchat_sessions', project_id: projectId, _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        var sessions = (res.success && res.data) ? res.data : [];
                        $container.empty();
                        if (!sessions.length) {
                            $container.html('<div style="padding:6px 12px 6px 32px;color:#9ca3af;font-size:11px;">Trống</div>');
                            return;
                        }
                        sessions.forEach(function(s) {
                            var displayTitle = s.title && s.title.trim() ? s.title : 'Hội thoại mới';
                            var $c = $('<div class="bizc-proj-conv' + (s.id === currentWcId ? ' active' : '') + '" data-wc-id="' + s.id + '">' +
                                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"></path></svg> ' +
                                '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(displayTitle) + '</span>' +
                                '</div>');
                            $c.on('click', function() { loadSession(s.id); });
                            $container.append($c);
                        });
                    }
                });
            }

            function showAddProjectForm() {
                console.log('[bizc-dash] showAddProjectForm() called');
                var $list = $('#bizc-proj-list');
                if ($list.find('.bizc-proj-add-form').length) {
                    $list.find('.bizc-proj-add-form input').focus();
                    return;
                }
                // Build form elements individually for reliable event binding
                var $form = $('<div class="bizc-proj-add-form"></div>');
                var $inp = $('<input type="text" placeholder="Tên dự án..." />');
                var $btnOk = $('<button type="button" class="bizc-proj-save">OK</button>');
                var $btnCancel = $('<button type="button" class="bizc-proj-cancel">✕</button>');
                $form.append($inp).append($btnOk).append($btnCancel);
                $list.prepend($form);
                $inp.focus();

                var _creating = false;
                var doCreate = function() {
                    var name = $inp.val().trim();
                    console.log('[bizc-dash] doCreate() name="' + name + '"');
                    if (!name) { $form.remove(); return; }
                    if (_creating) return;
                    _creating = true;
                    $btnOk.prop('disabled', true).text('...');
                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: { action: 'bizcity_project_create', name: name, _wpnonce: nonce },
                        dataType: 'json',
                        success: function(res) {
                            console.log('[bizc-dash] project_create response:', res);
                            $form.remove();
                            if (res.success) {
                                // Add auto-created character to the select dropdown
                                if (res.data && res.data.character_id && res.data.character_id > 0) {
                                    var $sel = $('#bizc-proj-character-select');
                                    if (!$sel.find('option[value="' + res.data.character_id + '"]').length) {
                                        var charLabel = (res.data.icon || '📁') + ' ' + (res.data.name || 'Dự án');
                                        $sel.append('<option value="' + res.data.character_id + '">' + esc(charLabel) + '</option>');
                                    }
                                }
                                loadProjects();
                            }
                            else alert(res.data && res.data.message ? res.data.message : 'Lỗi tạo dự án');
                        },
                        error: function(xhr, status, err) {
                            console.error('[bizc-dash] project_create AJAX error:', status, err);
                            $form.remove();
                            alert('Lỗi kết nối khi tạo dự án');
                        }
                    });
                };

                // Bind events directly on jQuery elements
                $btnOk.on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    doCreate();
                });
                $btnCancel.on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $form.remove();
                });
                $inp.on('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); doCreate(); }
                    if (e.key === 'Escape') { e.preventDefault(); $form.remove(); }
                });
                // Prevent clicks inside form from bubbling to parent handlers
                $form.on('click', function(e) { e.stopPropagation(); });
            }

            function showProjectMenu(x, y, proj) {
                $('.bizc-ctx-menu').remove();
                var $menu = $('<div class="bizc-ctx-menu" style="left:' + x + 'px;top:' + y + 'px;"></div>');
                $menu.append('<div class="bizc-ctx-menu-item" data-action="rename">✏️ Đổi tên</div>');
                $menu.append('<div class="bizc-ctx-menu-item danger" data-action="delete">🗑️ Xóa dự án</div>');
                $menu.on('click', '.bizc-ctx-menu-item', function() {
                    var act = $(this).data('action');
                    $menu.remove();
                    if (act === 'rename') {
                        var newName = prompt('Tên mới:', proj.name);
                        if (newName && newName.trim()) {
                            $.ajax({ url: ajaxurl, type: 'POST', data: { action: 'bizcity_project_rename', project_id: proj.id, name: newName.trim(), _wpnonce: nonce }, dataType: 'json', success: function() { loadProjects(); } });
                        }
                    } else if (act === 'delete') {
                        if (confirm('Xóa dự án "' + proj.name + '"? Hội thoại bên trong sẽ chuyển về Gần đây.')) {
                            $.ajax({ url: ajaxurl, type: 'POST', data: { action: 'bizcity_project_delete', project_id: proj.id, _wpnonce: nonce }, dataType: 'json', success: function() { loadProjects(); loadSessions(); } });
                        }
                    }
                });
                $('body').append($menu);
            }

            function moveSessionToProject(wcId, projectId) {
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_webchat_session_move', session_id: wcId, project_id: projectId, _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            loadProjects(); loadSessions();
                        } else {
                            console.warn('[bizc-dash] session_move failed:', res.data);
                        }
                    },
                    error: function(xhr, status, err) {
                        console.error('[bizc-dash] session_move error:', status, err);
                    }
                });
            }

            // ── Project Detail Panel (ChatGPT-style) ──
            var currentProjectData = null; // Store full project data
            
            function showProjectDetail(proj) {
                currentProjectId = proj.id;
                currentProjectData = proj;
                $('#bizc-proj-detail-icon').text(proj.icon || '📁');
                $('#bizc-proj-detail-name').text(proj.name);
                // Set character binding value
                $('#bizc-proj-character-select').val(proj.character_id || 0);
                $('#bizc-proj-char-status').text('');
                $('#bizc-chat-panel').hide();
                $('#bizc-project-detail').css('display', 'flex');
                loadProjectDetailList(proj.id);
                $('.bizc-proj-header').removeClass('active');
                $('.bizc-proj-item[data-project-id="' + proj.id + '"] .bizc-proj-header').addClass('active');
            }
            
            // Character binding change handler
            $('#bizc-proj-character-select').on('change', function() {
                var charId = parseInt($(this).val()) || 0;
                if (!currentProjectId) return;
                var $status = $('#bizc-proj-char-status');
                $status.text('Đang lưu...').css('color', '#9ca3af');
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: {
                        action: 'bizcity_project_update',
                        project_id: currentProjectId,
                        character_id: charId,
                        _wpnonce: nonce
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            $status.text('✓ Đã lưu').css('color', '#22c55e');
                            // Update cached project data
                            if (currentProjectData) currentProjectData.character_id = charId;
                            loadProjects(); // Refresh sidebar
                        } else {
                            $status.text('❌ Lỗi').css('color', '#ef4444');
                        }
                        setTimeout(function() { $status.text(''); }, 2000);
                    },
                    error: function() {
                        $status.text('❌ Lỗi kết nối').css('color', '#ef4444');
                        setTimeout(function() { $status.text(''); }, 2000);
                    }
                });
            });

            function hideProjectDetail() {
                currentProjectId = null;
                $('#bizc-project-detail').hide();
                // Also dismiss agent panel if open (lazy-unload iframe)
                if ($('#bizc-agent-panel').is(':visible')) {
                    $('#bizc-agent-panel').hide().data('slug', '');
                    loadAgentIframe(''); // Clear iframe to release memory
                    $('.bizc-tb-agent').removeClass('active');
                }
                $('#bizc-chat-panel').css('display', 'flex');
                $('.bizc-proj-header').removeClass('active');
            }

            /**
             * Lazy-load iframe helper: clear old iframe first, then load new URL.
             * Ensures only 1 iframe content is loaded at a time to reduce DOM load.
             * @param {string} url - The URL to load (or empty/blank to clear)
             */
            function loadAgentIframe(url) {
                var $iframe = $('#bizc-agent-iframe');
                // Always clear first (unload any existing content)
                $iframe.attr('src', 'about:blank');
                // If a valid URL is provided, load it after a micro-tick
                if (url && url !== 'about:blank') {
                    setTimeout(function() {
                        $iframe.attr('src', url);
                    }, 10);
                }
            }

            function hideAgentPanel() {
                $('#bizc-agent-panel').hide().data('slug', '');
                // Clear iframe to release memory (lazy-unload)
                loadAgentIframe('');
                $('.bizc-tb-agent').removeClass('active');
                $('.bizc-tb-link').removeClass('active');
                $('.bizc-tb-chat').addClass('active');
                $('#bizc-chat-panel').css('display', 'flex');
                // Reset URL to base (no agent/panel param)
                bizcPushUrl();
                // Reset page header for mobile
                updatePageHeader('Trò chuyện gần đây', false);
            }

            /**
             * Listen for postMessage from agent iframes (guided commands).
             * When an agent profile page sends { type:'bizcity_agent_command', text:'...' },
             * hide the agent panel, switch to chat, and send the message.
             */
            window.addEventListener('message', function(event) {
                if (!event.data || event.data.type !== 'bizcity_agent_command') return;
                var text = (event.data.text || '').trim();
                if (!text) return;

                // Hide agent panel → switch to chat
                hideAgentPanel();

                // Set input text and trigger send
                setTimeout(function() {
                    $input.val(text);
                    sendMsg();
                }, 150);
            });

            /**
             * Show agent panel by slug — reusable for click + URL restore (lazy-load)
             */
            function showAgentBySlug(slug, title, iconSrc, iframeUrl) {
                $('.bizc-tb-agent').removeClass('active');
                $('.bizc-tb-link').removeClass('active');
                $('.bizc-tb-chat').removeClass('active');

                // Find & activate the matching touch bar button
                var $btn = $('#bizc-touchbar .bizc-tb-agent[data-slug="' + slug + '"]');
                if ($btn.length) {
                    $btn.addClass('active');
                    if (!title || title === slug) title = $btn.attr('title') || slug;
                    if (!iconSrc) iconSrc = $btn.find('.bizc-tb-icon img').attr('src') || '';
                    if (!iframeUrl) {
                        var u = $btn.data('src') || '';
                        iframeUrl = u + (u.indexOf('?') > -1 ? '&' : '?') + 'bizcity_iframe=1';
                    }
                }

                // Populate header
                $('#bizc-agent-title').text(title || slug);
                if (iconSrc) { $('#bizc-agent-icon').attr('src', iconSrc).show(); }
                else { $('#bizc-agent-icon').hide(); }

                // Update page header for mobile (aiagent-header)
                updatePageHeader(title || slug, true);

                // Lazy-load iframe: clear old, then load new
                loadAgentIframe(iframeUrl);
                $('#bizc-agent-panel').data('slug', slug).css('display', 'flex');
                $('#bizc-chat-panel').hide();
                $('#bizc-project-detail').hide();
            }

            /**
             * Push URL state — SPA-style routing
             * In admin: /wp-admin/admin.php?page=bizcity-webchat-dashboard&chat=wcs_xxx
             * In frontend: /chat/?chat=wcs_xxx
             * bizcPushUrl()  →  reset to base
             */
            function bizcPushUrl(key, value) {
                var base = window.location.pathname;
                var url = base;
                
                // In wp-admin, preserve the page= param
                var isAdmin = base.indexOf('/wp-admin/') !== -1;
                var pageParam = '';
                if (isAdmin) {
                    var urlParams = new URLSearchParams(window.location.search);
                    pageParam = urlParams.get('page') || 'bizcity-webchat-dashboard';
                    url = base + '?page=' + encodeURIComponent(pageParam);
                    if (key && value) {
                        url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(value);
                    }
                } else {
                    // Frontend: /chat/?chat=wcs_xxx
                    if (key && value) {
                        url = base + '?' + encodeURIComponent(key) + '=' + encodeURIComponent(value);
                    }
                }
                
                if (window.location.href !== window.location.origin + url) {
                    history.pushState({ bizcKey: key || '', bizcVal: value || '' }, '', url);
                }
            }

            /**
             * Handle browser back/forward buttons
             */
            $(window).on('popstate', function(e) {
                var state = e.originalEvent.state;
                if (state && state.bizcKey === 'agent' && state.bizcVal) {
                    showAgentBySlug(state.bizcVal, '', '', '');
                } else if (state && state.bizcKey === 'panel' && state.bizcVal) {
                    var $link = $('#bizc-touchbar .bizc-tb-link').filter(function() {
                        return ($(this).data('src') || '').indexOf('page=' + state.bizcVal) > -1;
                    });
                    if ($link.length) $link.trigger('click');
                } else if (state && state.bizcKey === 'chat' && state.bizcVal) {
                    // Restore a specific chat session
                    var $conv = $('#bizc-convs-list .bizc-conv').filter(function() {
                        return $(this).data('session-id') === state.bizcVal;
                    });
                    var wcId = $conv.length ? $conv.data('wc-id') : null;
                    if (wcId) { loadSession(wcId, true); }
                    else { _loadSessionBySessionId(state.bizcVal); }
                } else {
                    // Back to chat (default / new chat) - lazy-unload iframe
                    $('#bizc-agent-panel').hide().data('slug', '');
                    loadAgentIframe(''); // Clear iframe to release memory
                    $('.bizc-tb-agent').removeClass('active');
                    $('.bizc-tb-link').removeClass('active');
                    $('#bizc-chat-panel').css('display', 'flex');
                }
            });

            /**
             * On page load: auto-open agent/panel from URL params
             */
            /**
             * Helper: load session by session_id string (for URL restore)
             */
            function _loadSessionBySessionId(sid) {
                // Try to find wcId from sidebar
                var found = false;
                wcSessions.forEach(function(s) {
                    if (s.session_id === sid && !found) {
                        found = true;
                        loadSession(s.id, true);
                    }
                });
                if (!found) {
                    // Sessions not loaded yet (race) — try AJAX directly with UUID
                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: { action: 'bizcity_webchat_session_messages', session_id: sid, _wpnonce: nonce },
                        dataType: 'json',
                        success: function(res) {
                            if (!res.success || !res.data) return;
                            currentWcId = res.data.id || 0;
                            sessionId = res.data.session_id;
                            window.bizcSessionId = sessionId;
                            window.bizcCurrentSessionId = sessionId;
                            messages = [];
                            $msgs.empty();
                            var msgs = res.data.messages || [];
                            msgs.forEach(function(m) {
                                if (m.from === 'system') return;
                                var from = (m.from === 'bot') ? 'bot' : 'user';
                                var imgs = (m.attachments && m.attachments.length) ? m.attachments : [];
                                var mTs = m.created_ts ? m.created_ts * 1000 : new Date(m.created_at).getTime();
                                messages.push({ role: from, content: m.text, timestamp: mTs, images: imgs });
                                appendMsg(m.text, from, mTs, false, imgs);
                            });
                            scrollBottom();
                            renderSessions();
                        }
                    });
                }
            }

            (function bizcRestoreFromUrl() {
                var params = new URLSearchParams(window.location.search);
                var agent = params.get('agent');
                var panel = params.get('panel');
                var chat  = params.get('chat');
                if (agent) {
                    history.replaceState({ bizcKey: 'agent', bizcVal: agent }, '', window.location.href);
                    showAgentBySlug(agent, '', '', '');
                } else if (panel) {
                    history.replaceState({ bizcKey: 'panel', bizcVal: panel }, '', window.location.href);
                    var $link = $('#bizc-touchbar .bizc-tb-link').filter(function() {
                        return ($(this).data('src') || '').indexOf('page=' + panel) > -1;
                    });
                    if ($link.length) $link.trigger('click');
                } else if (chat) {
                    history.replaceState({ bizcKey: 'chat', bizcVal: chat }, '', window.location.href);
                    // Wait a tick for sessions to load, then restore
                    setTimeout(function() { _loadSessionBySessionId(chat); }, 500);
                }
            })();

            function loadProjectDetailList(projectId) {
                var $list = $('#bizc-proj-detail-list');
                $list.html('<div style="padding:20px;text-align:center;color:#9ca3af;">Đang tải...</div>');
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_webchat_sessions', project_id: projectId, _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        var sessions = (res.success && res.data) ? res.data : [];
                        $list.empty();
                        if (!sessions.length) {
                            $list.html('<div style="padding:40px;text-align:center;color:#9ca3af;font-size:13px;">Chưa có chat nào trong dự án này.<br>Nhấn "+ New chat" hoặc kéo chat từ "Gần đây" vào.</div>');
                            return;
                        }
                        sessions.forEach(function(s) {
                            var dateStr = '';
                            if (s.started_at) {
                                var d = new Date(s.started_at);
                                var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                                dateStr = months[d.getMonth()] + ' ' + d.getDate();
                            }
                            var displayTitle = s.title && s.title.trim() ? s.title : 'Hội thoại mới';
                            var $item = $('<div class="bizc-proj-detail-item" data-wc-id="' + s.id + '">' +
                                '<div style="flex:1;min-width:0;">' +
                                    '<div class="pdi-title">' + esc(displayTitle) + '</div>' +
                                '</div>' +
                                '<span class="pdi-date">' + dateStr + '</span>' +
                                '</div>');
                            $item.on('click', function() { hideProjectDetail(); loadSession(s.id); });
                            $list.append($item);
                        });
                    }
                });
            }

            // Back button from project detail
            $('#bizc-proj-back').on('click', hideProjectDetail);

            // New chat in project
            $('#bizc-proj-new-chat-input').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var text = $(this).val().trim();
                    $(this).val('');
                    var projId = currentProjectId;
                    hideProjectDetail();
                    // Create session → move to project → send message
                    createNewSession(function() {
                        if (projId && currentWcId) {
                            moveSessionToProject(currentWcId, projId);
                        }
                        if (text) { $input.val(text); sendMsg(); }
                    });
                }
            });

            // Tab switching in project detail
            $(document).on('click', '.bizc-proj-tab', function() {
                $('.bizc-proj-tab').css({color:'#9ca3af', borderBottom:'2px solid transparent', fontWeight:'400'});
                $(this).css({color:'#6366f1', borderBottom:'2px solid #6366f1', fontWeight:'600'});
            });

            // ── Webchat Sessions (replace intent conversations in sidebar) ──
            function loadSessions(isInitial) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'bizcity_webchat_sessions', _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        wcSessions = (res.success && res.data) ? res.data : [];
                        renderSessions();

                        // On initial page load, auto-select the most recent session
                        if (isInitial && !currentWcId) {
                            var recent = wcSessions.filter(function(s) { return !s.project_id || s.project_id === ''; });
                            if (recent.length > 0) {
                                sessionId = recent[0].session_id;
                                window.bizcSessionId = sessionId;
                                window.bizcCurrentSessionId = sessionId;
                                console.log('[bizc-dash] Auto-selected session:', recent[0].id, sessionId);
                                // Load messages for the auto-selected session
                                loadSession(recent[0].id);
                            } else {
                                // No sessions yet — that's OK, lazy-create on first message
                                console.log('[bizc-dash] No sessions yet, will create on first message');
                            }
                        }
                    },
                    error: function() {
                        wcSessions = [];
                        renderSessions();
                    }
                });
            }

            function renderSessions() {
                var $list = $('#bizc-convs-list').empty();
                // Only show sessions NOT in a project
                var recent = wcSessions.filter(function(s) { return !s.project_id || s.project_id === ''; });
                if (!recent.length) {
                    $list.append('<div style="padding:12px;text-align:center;color:#9ca3af;font-size:12px;">Chưa có hội thoại</div>');
                    return;
                }
                recent.forEach(function(s) {
                    var displayTitle = s.title && s.title.trim() ? s.title : 'Hội thoại mới';
                    var $conv = $('<div class="bizc-conv' + (s.id === currentWcId ? ' active' : '') + '" data-wc-id="' + s.id + '" data-session-id="' + esc(s.session_id || '') + '" draggable="true" title="Kéo thả vào dự án để di chuyển">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"></path></svg>' +
                        '<span class="bizc-conv-title">' + esc(displayTitle) + '</span>' +
                        '</div>');

                    // Drag & drop support (improved)
                    $conv[0].addEventListener('dragstart', function(e) {
                        e.dataTransfer.setData('text/plain', String(s.id));
                        e.dataTransfer.effectAllowed = 'move';
                        dragSrcId = s.id;
                        $conv.addClass('dragging');
                        // Highlight all project drop zones
                        $('.bizc-proj-item').css('border', '2px dashed rgba(99,102,241,0.3)');
                    });
                    $conv[0].addEventListener('dragend', function() { 
                        $conv.removeClass('dragging'); 
                        dragSrcId = null; 
                        // Remove highlight from project drop zones
                        $('.bizc-proj-item').css('border', 'none').removeClass('drag-over');
                    });

                    $list.append($conv);
                });
            }

            /**
             * Ensure a V3 session exists before sending a message.
             * Uses mutex to prevent concurrent AJAX calls.
             * callback(ok) — true if session is ready, false on failure.
             */
            var _creatingSession = false;
            var _isFirstMessage = true; // track for gen-title
            function ensureSession(callback) {
                // Already have a valid session
                if (currentWcId && currentWcId > 0) {
                    if (callback) callback(true);
                    return;
                }
                // Concurrent guard
                if (_creatingSession) {
                    console.log('[bizc-dash] ensureSession() — waiting for in-flight creation');
                    var _wait = setInterval(function() {
                        if (!_creatingSession) {
                            clearInterval(_wait);
                            if (callback) callback(currentWcId > 0);
                        }
                    }, 150);
                    return;
                }
                _creatingSession = true;
                _isFirstMessage = true;
                console.log('[bizc-dash] ensureSession() → creating session...');
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_webchat_session_create', _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        _creatingSession = false;
                        if (res.success && res.data && res.data.id) {
                            currentWcId = res.data.id;
                            sessionId = res.data.session_id;
                            window.bizcSessionId = sessionId;
                            window.bizcCurrentSessionId = sessionId;
                            console.log('[bizc-dash] Session created OK:', currentWcId, sessionId);
                            if (callback) callback(true);
                        } else {
                            console.error('[bizc-dash] Session create response error:', res);
                            // Fallback: use legacy base session so chat still works
                            sessionId = baseSessionId;
                            window.bizcSessionId = baseSessionId;
                            window.bizcCurrentSessionId = baseSessionId;
                            currentWcId = -1; // sentinel: no real PK but won't re-trigger create
                            if (callback) callback(true); // let message through anyway
                        }
                    },
                    error: function(xhr, status, err) {
                        _creatingSession = false;
                        console.error('[bizc-dash] Session create AJAX error:', status, err);
                        sessionId = baseSessionId;
                        window.bizcSessionId = baseSessionId;
                        window.bizcCurrentSessionId = baseSessionId;
                        currentWcId = -1;
                        if (callback) callback(true); // let message through anyway
                    }
                });
            }

            function startNewChat() {
                messages = [];
                currentWcId = null;
                lastMsgId = 0;
                _renderedMsgIds = {}; // Reset dedup tracker
                stopMsgPoll(); // Stop any active polling from previous session
                sessionId = baseSessionId;
                window.bizcSessionId = baseSessionId;
                window.bizcCurrentSessionId = baseSessionId;
                window.dispatchEvent(new CustomEvent('bizcitySessionChanged', { detail: { sessionId: baseSessionId } }));
                _isFirstMessage = true;
                $msgs.find('.bizc-msg').remove();
                hideProjectDetail();
                // Close agent panel if open (lazy-unload iframe)
                if ($('#bizc-agent-panel').is(':visible')) {
                    $('#bizc-agent-panel').hide().data('slug', '');
                    loadAgentIframe(''); // Clear iframe to release memory
                    $('.bizc-tb-agent').removeClass('active');
                    $('.bizc-tb-link').removeClass('active');
                    $('.bizc-tb-chat').addClass('active');
                    $('#bizc-chat-panel').css('display', 'flex');
                }
                // Show greeting immediately — session will be created on first message
                var greetingHtml = <?php echo wp_json_encode($random_greeting); ?>;
                if (greetingHtml) appendMsg(greetingHtml, 'bot', Date.now(), false, []);
                // Deselect all sessions in sidebar
                $('#bizc-convs-list .bizc-conv').removeClass('active');
                $input.focus();
                // Reset URL
                bizcPushUrl();
                console.log('[bizc-dash] New chat ready (lazy session)');
            }

            /**
             * After first bot reply, ask server to AI-generate a better title.
             */
            function maybeGenTitle(userText, botReply) {
                if (!_isFirstMessage || !currentWcId || currentWcId < 0) return;
                _isFirstMessage = false;
                console.log('[bizc-dash] Requesting AI gen-title for session', currentWcId);
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: {
                        action: 'bizcity_webchat_session_gen_title',
                        session_id: currentWcId,
                        user_message: (userText || '').substring(0, 300),
                        bot_reply: (botReply || '').substring(0, 300),
                        _wpnonce: nonce
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success && res.data && res.data.title) {
                            console.log('[bizc-dash] Title generated:', res.data.title);
                        }
                        loadSessions(); // refresh sidebar with new title
                    },
                    error: function() {
                        loadSessions(); // still refresh to show truncated title
                    }
                });
            }

            function loadSession(wcId, skipPushUrl) {
                if (wcId === currentWcId && $('#bizc-chat-panel').is(':visible')) return;
                hideProjectDetail();
                // Close agent/panel iframe if open → return to chat (lazy-unload iframe)
                if ($('#bizc-agent-panel').is(':visible')) {
                    $('#bizc-agent-panel').hide().data('slug', '');
                    loadAgentIframe(''); // Clear iframe to release memory
                    $('.bizc-tb-agent').removeClass('active');
                    $('.bizc-tb-link').removeClass('active');
                    $('.bizc-tb-chat').addClass('active');
                    $('#bizc-chat-panel').css('display', 'flex');
                    // Reset page header for mobile
                    updatePageHeader('Trò chuyện gần đây', false);
                }
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_webchat_session_messages', session_id: wcId, _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        if (!res.success || !res.data) return;
                        currentWcId = res.data.id || wcId;
                        sessionId = res.data.session_id;  // switch SSE/AJAX session to this conversation
                        window.bizcSessionId = sessionId;  // sync for Router Console poll
                        window.bizcCurrentSessionId = sessionId;
                        messages = [];
                        $msgs.empty();
                        var msgs = res.data.messages || [];
                        lastMsgId = 0;
                        _renderedMsgIds = {}; // Reset dedup tracker on session switch
                        msgs.forEach(function(m) {
                            if (m.id && m.id > lastMsgId) lastMsgId = m.id;
                            if (m.id) _renderedMsgIds[m.id] = true; // Track as rendered
                            if (m.from === 'system') return;
                            var from = (m.from === 'bot') ? 'bot' : 'user';
                            var imgs = (m.attachments && m.attachments.length) ? m.attachments : [];
                            var mTs = m.created_ts ? m.created_ts * 1000 : new Date(m.created_at).getTime();
                            messages.push({ role: from, content: m.text, timestamp: mTs, images: imgs });
                            appendMsg(m.text, from, mTs, false, imgs);
                        });
                        scrollBottom();
                        renderSessions();
                        // Push URL: /chat/?chat=wcs_xxx
                        if (!skipPushUrl) {
                            bizcPushUrl('chat', sessionId);
                        }
                    }
                });
            }
            
            // Expose loadSession and startNewChat globally for search modal
            window.bizcLoadSession = loadSession;
            window.bizcStartNewChat = startNewChat;

            function updateCurrentSession() {
                // Refresh sidebar to reflect new titles (auto-title after first message)
                loadSessions();
                loadIntentConversations();
            }

            // Intent polling for in-progress tasks (poll every 5s)
            var _intentPollInterval = null;
            function startIntentPolling() {
                if (_intentPollInterval) return;
                _intentPollInterval = setInterval(function() {
                    loadIntentConversations(true); // silent poll
                }, 15000);
            }
            function stopIntentPolling() {
                if (_intentPollInterval) {
                    clearInterval(_intentPollInterval);
                    _intentPollInterval = null;
                }
            }

            // Clear all intents button
            $('#bizc-intent-clear-all').on('click', function() {
                if (!confirm('Xoá tất cả nhiệm vụ?')) return;
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_intent_close_all', _wpnonce: nonce },
                    dataType: 'json',
                    success: function() { loadIntentConversations(); }
                });
            });

            // ── Intent Conversations (Tasks / Nhiệm vụ) ──
            // Note: không gửi session_id để load TẤT CẢ nhiệm vụ của user (không chỉ session hiện tại)
            function loadIntentConversations(silent) {
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_intent_conversations', _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        var intents = (res.success && res.data) ? res.data : [];
                        renderIntentConversations(intents);
                        // Auto-poll if any intent is ACTIVE (in-progress)
                        var hasActive = intents.some(function(i) {
                            var s = (i.status || '').toLowerCase();
                            return s === 'active' || s === 'in_progress' || s === 'pending';
                        });
                        if (hasActive) startIntentPolling();
                        else stopIntentPolling();
                    },
                    error: function() { renderIntentConversations([]); stopIntentPolling(); }
                });
            }

            function renderIntentConversations(intents) {
                var $list = $('#bizc-intent-list').empty();
                $('#bizc-intent-count').text(intents.length);
                if (!intents.length) {
                    $list.append('<div style="padding:8px 12px;color:#9ca3af;font-size:11px;">Chưa có nhiệm vụ</div>');
                    return;
                }
                intents.slice(0, 10).forEach(function(intent) {
                    var status = (intent.status || '').toLowerCase();
                    var goal = (intent.goal || '').toLowerCase();
                    var statusIcon = '⏳';
                    var statusColor = '#f59e0b';
                    if (status === 'completed') { statusIcon = '✅'; statusColor = '#10b981'; }
                    else if (status === 'failed' || status === 'cancelled') { statusIcon = '❌'; statusColor = '#ef4444'; }
                    else if (status === 'active' || status === 'in_progress') { statusIcon = '🔄'; statusColor = '#3b82f6'; }
                    // Override icon for knowledge goals
                    if (goal.indexOf('knowledge') === 0 || goal.indexOf('mode:knowledge') === 0) { statusIcon = '📚'; statusColor = '#8b5cf6'; }
                    else if (goal.indexOf('mode:emotion') === 0) { statusIcon = '💛'; statusColor = '#f59e0b'; }
                    else if (goal.indexOf('mode:reflection') === 0) { statusIcon = '🪞'; statusColor = '#06b6d4'; }
                    else if (goal.indexOf('mode:planning') === 0) { statusIcon = '📋'; statusColor = '#8b5cf6'; }
                    var $item = $('<div class="bizc-conv bizc-intent-item" data-conv-id="' + esc(intent.id) + '" style="font-size:11px;padding:6px 10px;cursor:pointer;" title="Goal: ' + esc(intent.goal || '?') + '\nTrạng thái: ' + esc(intent.status) + '\nNhấn để xem lịch sử">' +
                        '<span style="color:' + statusColor + ';margin-right:4px;">' + statusIcon + '</span>' +
                        '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(intent.title || intent.goal || 'Nhiệm vụ') + '</span>' +
                        '</div>');
                    $list.append($item);
                });
            }

            // Click handler for intent items (load turns)
            $('#bizc-intent-list').on('click', '.bizc-intent-item', function() {
                var convId = $(this).data('conv-id');
                if (convId) loadIntentTurns(convId);
            });

            function loadIntentTurns(convId) {
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_intent_turns', conversation_id: convId, _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        if (!res.success || !res.data) return;
                        currentWcId = null; // not a webchat session
                        messages = [];
                        $msgs.empty();
                        // Show goal header
                        var goalLabel = res.data.goal_label || res.data.goal || 'Nhiệm vụ';
                        $msgs.append('<div style="text-align:center;padding:12px;color:#9ca3af;font-size:12px;border-bottom:1px solid #374151;margin-bottom:8px;">🎯 ' + esc(goalLabel) + ' — ' + esc(res.data.status) + '</div>');
                        // Render turns
                        var turns = res.data.turns || [];
                        turns.forEach(function(t) {
                            // Skip 'tool' turns - their content is already in the following assistant turn
                            if (t.role === 'tool') return;
                            var from = t.role === 'assistant' ? 'bot' : 'user';
                            var tTs = t.created_ts ? t.created_ts * 1000 : new Date(t.created_at).getTime();
                            messages.push({ role: from, content: t.content, timestamp: tTs });
                            appendMsg(t.content, from, tTs, false);
                        });
                        scrollBottom();
                        // Highlight active intent in sidebar
                        $('#bizc-intent-list .bizc-intent-item').removeClass('active');
                        $('#bizc-intent-list .bizc-intent-item[data-conv-id="' + convId + '"]').addClass('active');
                        $('#bizc-convs-list .bizc-conv').removeClass('active');
                    }
                });
            }

            function clearAllSessions() {
                if (!confirm('Đóng tất cả hội thoại đang mở?')) return;
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_webchat_close_all', _wpnonce: nonce },
                    dataType: 'json',
                    success: function() { startNewChat(); loadSessions(); }
                });
            }
            
            // Send message — ensures session exists, then SSE stream, falls back to AJAX
            var _lastUserText = ''; // for gen-title
            
            // Guest trial: get message count from localStorage
            function getGuestMsgCount() {
                try { return parseInt(localStorage.getItem(guestMsgKey) || '0', 10); } catch(e) { return 0; }
            }
            function incrementGuestMsgCount() {
                try { localStorage.setItem(guestMsgKey, (getGuestMsgCount() + 1).toString()); } catch(e) {}
                updateGuestHint();
            }
            function updateGuestHint() {
                var $hint = $('#bizc-guest-hint');
                var $remaining = $('#bizc-guest-remaining');
                if (!$hint.length) return;
                var count = getGuestMsgCount();
                var left = Math.max(0, guestMsgLimit - count);
                $remaining.text(left);
                if (left <= 0) {
                    $hint.addClass('exhausted');
                    $hint.find('.bizc-guest-hint-text').html('<strong>Hết tin nhắn thử nghiệm!</strong> <a href="#" id="bizc-guest-signup-link">Đăng nhập ngay</a> để tiếp tục.');
                }
            }
            // Init guest hint on load
            if (isGuest) {
                updateGuestHint();
                $(document).on('click', '#bizc-guest-signup-link', function(e) {
                    e.preventDefault();
                    if (typeof window.aiagentShowAuth === 'function') {
                        window.aiagentShowAuth('register');
                    } else {
                        window.location.href = '<?php echo wp_login_url( get_permalink() ); ?>';
                    }
                });
            }
            
            function sendMsg() {
                var text = $input.val().trim();
                if (!text && !pendingImages.length) return;
                
                // Guest trial limit check
                if (isGuest) {
                    var count = getGuestMsgCount();
                    if (count >= guestMsgLimit) {
                        // Show login dialog
                        if (typeof window.aiagentShowAuth === 'function') {
                            window.aiagentShowAuth('login');
                        } else {
                            alert('Bạn đã dùng hết ' + guestMsgLimit + ' tin nhắn thử nghiệm. Vui lòng đăng nhập để tiếp tục.');
                            window.location.href = '<?php echo wp_login_url( get_permalink() ); ?>';
                        }
                        return;
                    }
                }
                
                _lastUserText = text;

                // Step 1: Ensure session exists (lazy create on first message)
                ensureSession(function(ok) {
                    if (!ok) {
                        console.error('[bizc-dash] Cannot create session — aborting send');
                        return;
                    }
                    _doSend(text);
                });
            }

            function _doSend(text) {
                $input.val('').css('height', 'auto');
                $send.prop('disabled', true);
                
                // Increment guest message count
                if (isGuest) incrementGuestMsgCount();
                
                var timestamp = Date.now();
                var images = pendingImages.map(function(img) { return img.data; });
                
                // ════════════════════════════════════════════════════════════
                //  DUAL-PATH ROUTING SYSTEM
                //  
                //  Determine routing path based on user selection:
                //  - Manual: User selected plugin via @mention or pills → direct routing
                //  - Automatic: No selection → intent detection routing
                // ════════════════════════════════════════════════════════════
                var routingMode = 'automatic';
                var selectedPlugin = null;
                var routingInfo = '';
                
                if (_mentionProvider && _mentionProvider.slug) {
                    routingMode = 'manual';
                    selectedPlugin = _mentionProvider.slug;
                    routingInfo = '🎯 Manual routing to: ' + _mentionProvider.label + ' (' + selectedPlugin + ')';
                } else {
                    routingInfo = '🤖 Automatic intent detection routing';
                }
                
                console.log('📍 [Dual-Path Routing]', routingInfo);
                
                var messageData = { 
                    role: 'user', 
                    content: text, 
                    timestamp: timestamp, 
                    images: images,
                    routing_mode: routingMode,
                    selected_plugin: selectedPlugin
                };
                
                messages.push(messageData);
                appendMsg(text, 'user', timestamp, true, images);
                updateCurrentSession();
                clearImages();
                
                // Typing indicator
                var typId = 'typ-' + Math.random().toString(36).substr(2, 6);
                var _sendPluginSlug = (routingMode === 'manual' && selectedPlugin) ? selectedPlugin : '';
                $msgs.append(
                    '<div class="bizc-typing" id="' + typId + '">' +
                    '<div class="bizc-msg-av">' + avHtml('bot') + '</div>' +
                    '<div class="bizc-typing-body">' +
                    '<div class="bizc-typing-dots">' +
                    '<div class="bizc-typing-dot"></div><div class="bizc-typing-dot"></div><div class="bizc-typing-dot"></div>' +
                    '</div>' +
                    '<div class="bizc-routing-indicator">' + 
                    (routingMode === 'manual' ? 
                        '🎯 ' + _mentionProvider.label + ' mode' : 
                        '🤖 Auto-routing') + 
                    '</div>' +
                    (_sendPluginSlug ? '<div class="bizc-plugin-badge">🔌 ' + esc(_sendPluginSlug) + '</div>' : '') +
                    ((_selectedTool && _selectedTool.goal_label) ? '<div class="bizc-tool-badge">🛠️ ' + esc(_selectedTool.goal_label) + '</div>' : '') +
                    '</div></div>'
                );
                scrollBottom();
                
                // SSE streaming (falls back to AJAX inside sendMsgStream)
                sendMsgStream(text, images, typId);

                // Auto-start console polling on first message
                if (!_bizcRouterInterval) bizcRouterPoll(null);
            }
            
            // ── SSE Streaming via fetch + ReadableStream ──
            function sendMsgStream(text, images, typId) {
                var formData = new FormData();
                formData.append('action', 'bizcity_chat_stream');
                formData.append('message', text);
                formData.append('session_id', sessionId);
                formData.append('platform_type', 'ADMINCHAT');
                formData.append('_wpnonce', nonce);
                if (images && images.length) {
                    formData.append('images', JSON.stringify(images));
                }
                
                // ═══ DUAL-PATH ROUTING PARAMETERS ═══
                // Send both provider_hint (for Intent Engine biasing) and plugin_slug (for message logging)
                var _ssePluginSlug = ''; // Persist for badge display on bot bubble
                var _sseToolLabel = '';  // Persist tool label for badge
                if (_mentionProvider && _mentionProvider.slug) {
                    formData.append('provider_hint', _mentionProvider.slug);  // Intent Engine hint
                    formData.append('plugin_slug', _mentionProvider.slug);   // Message logging
                    formData.append('routing_mode', 'manual');               // Routing mode
                    _ssePluginSlug = _mentionProvider.slug;
                    
                    // ═══ SLASH COMMAND: include selected tool goal ═══
                    var _selTool = _getSelectedTool();
                    if (_selTool) {
                        _sseToolLabel = _selTool.goal_label || _selTool.title || _selTool.tool_name || '';
                    }
                    if (_selTool && _selTool.goal) {
                        formData.append('tool_goal', _selTool.goal);         // Direct tool targeting
                        formData.append('tool_name', _selTool.tool_name);    // Tool registry name
                        console.log('📤 [Slash] Sending tool_goal:', _selTool.goal, 'tool_name:', _selTool.tool_name);
                    }
                    
                    console.log('📤 [Dual-Path] Sending manual routing params:', {
                        provider_hint: _mentionProvider.slug,
                        plugin_slug: _mentionProvider.slug,
                        routing_mode: 'manual'
                    });
                    
                    // Only clear mention if NOT in HIL focus mode
                    // (focus mode lifecycle is controlled by server focus_mode signal)
                    if (!$('#bizc-context-header').hasClass('active')) {
                        _clearMention();
                    }
                } else {
                    formData.append('routing_mode', 'automatic');            // Automatic intent detection
                    console.log('📤 [Dual-Path] Sending automatic routing params');
                }
                
                // Create streaming bot bubble
                var bubbleId = 'stream-' + Math.random().toString(36).substr(2, 6);
                var fullText = '';
                var bubbleCreated = false;
                
                fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(function(response) {
                    if (!response.ok || !response.body) {
                        throw new Error('Stream not available');
                    }
                    
                    var reader = response.body.getReader();
                    var decoder = new TextDecoder();
                    var buffer = '';
                    
                    function processStream() {
                        return reader.read().then(function(result) {
                            if (result.done) {
                                // Stream finished
                                $('#' + typId).remove();
                                if (!bubbleCreated && fullText) {
                                    appendMsg(fullText, 'bot', Date.now(), true, []);
                                } else if (bubbleCreated && fullText) {
                                    // Re-format the final text and add copy button
                                    $('#' + bubbleId).html(formatMsg(fullText));
                                    var $msgDiv = $('#' + bubbleId).closest('.bizc-msg');
                                    if (!$msgDiv.find('.bizc-msg-actions').length) {
                                        $('#' + bubbleId).append(
                                            '<div class="bizc-msg-actions">' +
                                            '<button class="bizc-msg-action-btn" onclick="bizcCopyMsg(this)" title="Copy">📋</button>' +
                                            '</div>'
                                        );
                                    }
                                }
                                if (fullText) {
                                    messages.push({ role: 'assistant', content: fullText, timestamp: Date.now() });
                                    updateCurrentSession();
                                    // Sync lastMsgId BEFORE starting poll to avoid
                                    // re-fetching messages that SSE already rendered.
                                    syncLastMsgId(function() {
                                        // Start polling for executor async messages
                                        // (task progress, completion, etc.)
                                        startMsgPoll();
                                    });
                                }
                                updateBtn();
                                // Gen AI title + refresh sidebar
                                maybeGenTitle(_lastUserText, fullText);
                                return;
                            }
                            
                            buffer += decoder.decode(result.value, { stream: true });
                            var lines = buffer.split('\n');
                            buffer = lines.pop(); // Keep incomplete line in buffer
                            
                            for (var i = 0; i < lines.length; i++) {
                                var line = lines[i].trim();
                                
                                // Parse SSE event type
                                if (line.startsWith('event:')) {
                                    var evType = line.substring(6).trim();
                                    if (evType === 'close') continue;
                                    // Check if next line is data for status event
                                    if (evType === 'status' && i + 1 < lines.length) {
                                        var nextLine = lines[i + 1].trim();
                                        if (nextLine.startsWith('data: ')) {
                                            try {
                                                var statusData = JSON.parse(nextLine.substring(6));
                                                if (statusData.text) {
                                                    $('#' + typId).find('.bizc-typing-dots').html(
                                                        '<span style="font-size:13px;opacity:.85">' + statusData.text + '</span>'
                                                    );
                                                    scrollBottom();
                                                }
                                            } catch(e) {}
                                            i++; // skip the data line
                                        }
                                    }
                                    continue;
                                }
                                
                                if (!line.startsWith('data: ')) continue;
                                
                                try {
                                    var data = JSON.parse(line.substring(6));
                                } catch(e) { continue; }
                                
                                // Handle chunk — stream text into bubble
                                if (data.delta) {
                                    // Remove typing indicator on first chunk
                                    if (!bubbleCreated) {
                                        $('#' + typId).remove();
                                        var t = new Date().toLocaleTimeString('vi-VN', {hour:'2-digit', minute:'2-digit'});
                                        var _badgeSlug = data.plugin_slug || _ssePluginSlug;
                                        var _badgeTool = data.tool_name || _sseToolLabel;
                                        $msgs.append(
                                            '<div class="bizc-msg bot">' +
                                            '<div class="bizc-msg-av">' + avHtml('bot') + '</div>' +
                                            '<div>' +
                                            '<div class="bizc-msg-bubble" id="' + bubbleId + '"></div>' +
                                            (_badgeSlug ? '<div class="bizc-plugin-badge" id="badge-' + bubbleId + '">🔌 ' + esc(_badgeSlug) + '</div>' : '') +
                                            (_badgeTool ? '<div class="bizc-tool-badge" id="toolbadge-' + bubbleId + '">🛠️ ' + esc(_badgeTool) + '</div>' : '') +
                                            '<div class="bizc-msg-time">' + t + '</div>' +
                                            '</div></div>'
                                        );
                                        bubbleCreated = true;
                                    }
                                    fullText = data.full || (fullText + data.delta);
                                    $('#' + bubbleId).html(formatMsg(fullText));
                                    scrollBottom();
                                }
                                
                                // Handle done event (full message + conversation_id)
                                if (data.message && !data.delta) {
                                    fullText = data.message;
                                    if (bubbleCreated) {
                                        $('#' + bubbleId).html(formatMsg(fullText));
                                    }
                                    // Intent conversation_id captured (internal only)
                                    if (data.conversation_id) {
                                        window._bizcIntentConvId = data.conversation_id;
                                    }
                                    // ── Dedup: capture bot DB message ID immediately ──
                                    // This prevents pollNewMessages from re-displaying
                                    // the same message fetched from DB.
                                    if (data.bot_message_id) {
                                        _renderedMsgIds[data.bot_message_id] = true;
                                    }
                                    // ═══ Show plugin_slug badge (SSE done) ═══
                                    var _doneSlug = data.plugin_slug || _ssePluginSlug;
                                    if (_doneSlug && bubbleCreated) {
                                        var $bubble = $('#' + bubbleId);
                                        // Update existing badge or create new one
                                        var $existBadge = $('#badge-' + bubbleId);
                                        if ($existBadge.length) {
                                            $existBadge.html('🔌 ' + esc(_doneSlug));
                                        } else if ($bubble.length && !$bubble.next('.bizc-plugin-badge').length) {
                                            $bubble.after('<div class="bizc-plugin-badge">🔌 ' + esc(_doneSlug) + '</div>');
                                        }
                                        console.log('🏷️ [SSE] Bot message tagged with plugin:', _doneSlug);
                                    }
                                    // ═══ Show tool_name badge (SSE done) ═══
                                    var _doneTool = data.tool_name || _sseToolLabel;
                                    if (_doneTool && bubbleCreated) {
                                        var $existToolBadge = $('#toolbadge-' + bubbleId);
                                        if ($existToolBadge.length) {
                                            $existToolBadge.html('🛠️ ' + esc(_doneTool));
                                        } else {
                                            var $plugBadge = $('#badge-' + bubbleId);
                                            var $anchor = $plugBadge.length ? $plugBadge : $('#' + bubbleId);
                                            if ($anchor.length && !$('#toolbadge-' + bubbleId).length) {
                                                $anchor.after('<div class="bizc-tool-badge" id="toolbadge-' + bubbleId + '">🛠️ ' + esc(_doneTool) + '</div>');
                                            }
                                        }
                                    }

                                    // ═══ HIL Focus Mode lifecycle (SSE) ═══
                                    _handleFocusMode(data);
                                }
                            }
                            
                            return processStream();
                        });
                    }
                    
                    return processStream();
                })
                .catch(function(err) {
                    console.log('SSE stream failed, falling back to AJAX:', err.message);
                    // ── Fallback: regular AJAX ──
                    sendMsgAjax(text, images, typId);
                });
            }
            
            // ── Fallback: regular AJAX (non-streaming) ──
            function sendMsgAjax(text, images, typId) {
                // ═══ REST API primary path (Phase 5.0) ═══
                if (useRestApi) {
                    var restBody = {
                        message: text,
                        session_id: sessionId,
                        platform_type: 'ADMINCHAT',
                        character_id: <?php echo intval( $character->id ?? 0 ); ?>,
                        images: images || [],
                        routing_mode: 'automatic'
                    };

                    var _restPluginSlug = '';
                    var _restToolLabel = '';
                    if (_mentionProvider && _mentionProvider.slug) {
                        restBody.provider_hint = _mentionProvider.slug;
                        restBody.plugin_slug   = _mentionProvider.slug;
                        restBody.routing_mode  = 'manual';
                        _restPluginSlug = _mentionProvider.slug;

                        // ═══ SLASH COMMAND: include selected tool goal ═══
                        var _restTool = _getSelectedTool();
                        if (_restTool && _restTool.goal) {
                            restBody.tool_goal = _restTool.goal;
                            restBody.tool_name = _restTool.tool_name;
                            _restToolLabel = _restTool.goal_label || _restTool.title || _restTool.tool_name || '';
                            console.log('📤 [REST] Sending tool_goal:', _restTool.goal, 'tool_name:', _restTool.tool_name);
                        }

                        console.log('📤 [REST] manual routing:', restBody.plugin_slug);
                        if (!$('#bizc-context-header').hasClass('active')) {
                            _clearMention();
                        }
                    } else {
                        console.log('📤 [REST] automatic routing');
                    }

                    fetch(restUrl + 'send', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': wpRestNonce
                        },
                        body: JSON.stringify(restBody),
                        credentials: 'same-origin'
                    })
                    .then(function(r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function(response) {
                        $('#' + typId).remove();

                        if (response.success && response.data) {
                            var reply = response.data.message || response.data.reply || '';
                            var replyTime = Date.now();

                            if (response.data.conversation_id) {
                                window._bizcIntentConvId = response.data.conversation_id;
                            }

                            messages.push({ role: 'assistant', content: reply, timestamp: replyTime });
                            appendMsg(reply, 'bot', replyTime, true, []);

                            // Plugin badge
                            var doneSlug = response.data.plugin_slug || _restPluginSlug;
                            if (doneSlug) {
                                var $lastBotMsg = $msgs.find('.bizc-msg.bot').last();
                                if ($lastBotMsg.length) {
                                    var $bubble = $lastBotMsg.find('.bizc-msg-bubble');
                                    if ($bubble.length && !$bubble.next('.bizc-plugin-badge').length) {
                                        $bubble.after('<div class="bizc-plugin-badge">🔌 ' + esc(doneSlug) + '</div>');
                                    }
                                }
                                console.log('🏷️ [REST] plugin:', doneSlug);
                            }
                            // Tool badge
                            var doneToolRest = response.data.tool_name || _restToolLabel;
                            if (doneToolRest) {
                                var $lastBotMsgT = $msgs.find('.bizc-msg.bot').last();
                                if ($lastBotMsgT.length) {
                                    var $plugB = $lastBotMsgT.find('.bizc-plugin-badge');
                                    var $anchorT = $plugB.length ? $plugB : $lastBotMsgT.find('.bizc-msg-bubble');
                                    if ($anchorT.length && !$lastBotMsgT.find('.bizc-tool-badge').length) {
                                        $anchorT.after('<div class="bizc-tool-badge">🛠️ ' + esc(doneToolRest) + '</div>');
                                    }
                                }
                            }

                            // ═══ HIL Focus Mode lifecycle (REST) ═══
                            _handleFocusMode(response.data);

                            updateCurrentSession();
                            syncLastMsgId(function() { startMsgPoll(); });
                            maybeGenTitle(_lastUserText, reply);
                        } else {
                            var errMsg = (response.data && response.data.message) || 'Có lỗi xảy ra';
                            appendMsg('❌ ' + errMsg, 'bot', Date.now(), true, []);
                        }
                    })
                    .catch(function(err) {
                        console.warn('⚠️ REST send failed, falling back to AJAX:', err.message);
                        _sendMsgAjaxLegacy(text, images, typId);
                    });

                    return;
                }

                // ═══ AJAX legacy path ═══
                _sendMsgAjaxLegacy(text, images, typId);
            }

            function _sendMsgAjaxLegacy(text, images, typId) {
                var requestData = {
                    action: 'bizcity_chat_send',
                    platform_type: 'ADMINCHAT',
                    message: text,
                    session_id: sessionId,
                    image_data: images && images.length > 0 ? images[0] : '',
                    _wpnonce: nonce
                };
                
                // ═══ DUAL-PATH ROUTING PARAMETERS (AJAX FALLBACK) ═══
                var _ajaxPluginSlug = ''; // Persist for badge display
                var _ajaxToolLabel = ''; // Persist tool label for badge
                if (_mentionProvider && _mentionProvider.slug) {
                    requestData.provider_hint = _mentionProvider.slug;  // Intent Engine hint
                    requestData.plugin_slug = _mentionProvider.slug;   // Message logging
                    requestData.routing_mode = 'manual';               // Routing mode
                    _ajaxPluginSlug = _mentionProvider.slug;

                    // ═══ SLASH COMMAND: include selected tool goal ═══
                    var _ajaxTool = _getSelectedTool();
                    if (_ajaxTool && _ajaxTool.goal) {
                        requestData.tool_goal = _ajaxTool.goal;
                        requestData.tool_name = _ajaxTool.tool_name;
                        _ajaxToolLabel = _ajaxTool.goal_label || _ajaxTool.title || _ajaxTool.tool_name || '';
                        console.log('📤 [AJAX] Sending tool_goal:', _ajaxTool.goal, 'tool_name:', _ajaxTool.tool_name);
                    }
                    
                    console.log('📤 [Dual-Path AJAX] Sending manual routing params:', {
                        provider_hint: _mentionProvider.slug,
                        plugin_slug: _mentionProvider.slug,
                        routing_mode: 'manual'
                    });
                    
                    if (!$('#bizc-context-header').hasClass('active')) {
                        _clearMention();
                    }
                } else {
                    requestData.routing_mode = 'automatic';            // Automatic intent detection
                    console.log('📤 [Dual-Path AJAX] Sending automatic routing params');
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: requestData,
                    dataType: 'text',
                    success: function(response) {
                        $('#' + typId).remove();
                        
                        try {
                            if (typeof response === 'string') {
                                response = response.replace(/^\uFEFF/, '');
                                var jsonStart = response.indexOf('{');
                                if (jsonStart > 0) response = response.substring(jsonStart);
                                var jsonEnd = response.lastIndexOf('}');
                                if (jsonEnd > 0 && jsonEnd < response.length - 1) {
                                    response = response.substring(0, jsonEnd + 1);
                                }
                                response = JSON.parse(response);
                            }
                            
                            if (response.success && response.data && response.data.reply) {
                                var reply = response.data.reply;
                                var replyTime = Date.now();
                                
                                // Capture conversation_id from intent engine
                                // Intent conversation_id (internal)
                                if (response.data.conversation_id) {
                                    window._bizcIntentConvId = response.data.conversation_id;
                                }
                                
                                messages.push({
                                    role: 'assistant',
                                    content: reply,
                                    timestamp: replyTime
                                });
                                
                                appendMsg(reply, 'bot', replyTime, true, []);
                                
                                // ═══ Show plugin_slug badge (AJAX done) ═══
                                var _ajaxDoneSlug = response.data.plugin_slug || _ajaxPluginSlug;
                                if (_ajaxDoneSlug) {
                                    var $lastBotMsg = $msgs.find('.bizc-msg.bot').last();
                                    if ($lastBotMsg.length) {
                                        var $bubble = $lastBotMsg.find('.bizc-msg-bubble');
                                        if ($bubble.length && !$bubble.next('.bizc-plugin-badge').length) {
                                            $bubble.after('<div class="bizc-plugin-badge">🔌 ' + esc(_ajaxDoneSlug) + '</div>');
                                        }
                                    }
                                    console.log('🏷️ [AJAX] Bot message tagged with plugin:', _ajaxDoneSlug);
                                }
                                // ═══ Show tool_name badge (AJAX done) ═══
                                var _ajaxDoneTool = response.data.tool_name || _ajaxToolLabel;
                                if (_ajaxDoneTool) {
                                    var $lastBotMsgT = $msgs.find('.bizc-msg.bot').last();
                                    if ($lastBotMsgT.length) {
                                        var $plugB = $lastBotMsgT.find('.bizc-plugin-badge');
                                        var $anchorT = $plugB.length ? $plugB : $lastBotMsgT.find('.bizc-msg-bubble');
                                        if ($anchorT.length && !$lastBotMsgT.find('.bizc-tool-badge').length) {
                                            $anchorT.after('<div class="bizc-tool-badge">🛠️ ' + esc(_ajaxDoneTool) + '</div>');
                                        }
                                    }
                                }

                                // ═══ HIL Focus Mode lifecycle (AJAX) ═══
                                _handleFocusMode(response.data);
                                
                                updateCurrentSession();
                                // Always start polling for async executor/tool messages
                                syncLastMsgId(function() { startMsgPoll(); });
                                // Gen AI title + refresh sidebar
                                maybeGenTitle(_lastUserText, reply);
                            } else {
                                var errorMsg = response.data?.message || 'Có lỗi xảy ra';
                                appendMsg('❌ ' + errorMsg, 'bot', Date.now(), true, []);
                            }
                        } catch(e) {
                            console.error('Error:', e);
                            appendMsg('❌ Lỗi xử lý phản hồi', 'bot', Date.now(), true, []);
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#' + typId).remove();
                        console.error('AJAX Error:', status, error);
                        appendMsg('❌ Không thể kết nối server', 'bot', Date.now(), true, []);
                    }
                });
            }
            
            function appendMsg(text, from, time, scroll, imgs) {
                var t = new Date(time).toLocaleTimeString('vi-VN', {hour:'2-digit', minute:'2-digit'});
                
                var imgHtml = '';
                if (imgs && imgs.length) {
                    imgHtml = '<div class="bizc-msg-images">';
                    imgs.forEach(function(img) {
                        imgHtml += '<img src="' + esc(img) + '" alt="">';
                    });
                    imgHtml += '</div>';
                }
                
                var formatted = from === 'bot' ? formatMsg(text) : esc(text);
                
                var actionsHtml = '';
                if (from === 'bot' && text && text.length > 20) {
                    actionsHtml = '<div class="bizc-msg-actions">' +
                        '<button class="bizc-msg-action-btn" onclick="bizcCopyMsg(this)" title="Copy">📋</button>' +
                        '</div>';
                }
                
                $msgs.append(
                    '<div class="bizc-msg ' + from + '">' +
                    '<div class="bizc-msg-av">' + avHtml(from) + '</div>' +
                    '<div>' +
                    imgHtml +
                    '<div class="bizc-msg-bubble">' + formatted + actionsHtml + '</div>' +
                    '<div class="bizc-msg-time">' + t + '</div>' +
                    '</div></div>'
                );
                
                if (scroll) scrollBottom();
            }
            
            function avHtml(from) {
                if (from === 'user') return '👤';
                if (botAvatar) return '<img src="' + esc(botAvatar) + '" alt="">';
                return '🤖';
            }
            
            function handleImages(files) {
                if (!files || !files.length) return;
                console.log('Handling', files.length, 'file(s)');
                Array.from(files).forEach(function(f) {
                    if (!f.type.startsWith('image/')) {
                        console.warn('Skipped non-image file:', f.name);
                        return;
                    }
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        pendingImages.push({ name: f.name, data: e.target.result });
                        console.log('Image loaded:', f.name);
                        renderPreviews();
                        updateBtn();
                    };
                    reader.onerror = function(e) {
                        console.error('Error reading file:', f.name, e);
                    };
                    reader.readAsDataURL(f);
                });
            }
            
            function renderPreviews() {
                var $preview = $('#bizc-img-preview');
                var $hint = $('#bizc-vision-hint');
                $preview.empty();
                
                if (!pendingImages.length) {
                    $preview.hide();
                    $hint.hide();
                    return;
                }
                
                pendingImages.forEach(function(img, idx) {
                    var $thumb = $(
                        '<div class="bizc-img-thumb">' +
                        '<img src="' + img.data + '" alt="' + esc(img.name) + '">' +
                        '<button class="bizc-img-rm" data-idx="' + idx + '" type="button">&times;</button>' +
                        '</div>'
                    );
                    $preview.append($thumb);
                });
                
                $preview.show();
                $hint.show();
                
                // Bind remove buttons
                $preview.find('.bizc-img-rm').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var idx = parseInt($(this).data('idx'));
                    pendingImages.splice(idx, 1);
                    renderPreviews();
                    updateBtn();
                });
            }
            
            function clearImages() {
                pendingImages = [];
                renderPreviews();
            }
            
            function updateBtn() {
                $send.prop('disabled', !$input.val().trim() && !pendingImages.length);
            }
            
            function scrollBottom() {
                $msgs.scrollTop($msgs[0].scrollHeight);
            }
            
            function esc(t) {
                return $('<div>').text(t).html();
            }
            
            function formatMsg(t) {
                if (!t) return '';
                // If already HTML, return as-is
                if (/<\/?(?:div|p|br|h[1-6]|ul|ol|li|strong|em|table|tr|td|th|blockquote|pre|code|span|a|img)[\s>]/i.test(t)) {
                    return t;
                }
                t = esc(t);
                // URL auto-link (after escape, before markdown)
                t = t.replace(/(https?:\/\/[^\s<)\]]+)/g, '<a href="$1" target="_blank" rel="noopener" style="color:#7c3aed;text-decoration:underline;">$1</a>');
                // Fenced code blocks: ```lang\n...\n```
                t = t.replace(/```(\w*)\n([\s\S]*?)```/g, function(m, lang, code) {
                    var langLabel = lang ? '<span style="position:absolute;top:6px;left:12px;font-size:10px;color:#89b4fa;text-transform:uppercase;">' + lang + '</span>' : '';
                    return '<div class="bizc-code-wrap">' + langLabel +
                        '<button class="bizc-copy-btn" onclick="bizcCopyCode(this)">Copy</button>' +
                        '<pre><code>' + code + '</code></pre></div>';
                });
                // Fenced code blocks without newline: ```...```
                t = t.replace(/```([\s\S]*?)```/g, function(m, code) {
                    return '<div class="bizc-code-wrap">' +
                        '<button class="bizc-copy-btn" onclick="bizcCopyCode(this)">Copy</button>' +
                        '<pre><code>' + code + '</code></pre></div>';
                });
                // Headings: #### / ### / ## / #
                t = t.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
                t = t.replace(/^### (.+)$/gm, '<h4>$1</h4>');
                t = t.replace(/^## (.+)$/gm, '<h3>$1</h3>');
                t = t.replace(/^# (.+)$/gm, '<h2>$1</h2>');
                // Bold + Italic: ***text***
                t = t.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
                // Bold: **text**
                t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                // Italic: *text*
                t = t.replace(/\*(.+?)\*/g, '<em>$1</em>');
                // Inline code: `text`
                t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
                // Unordered list: - item
                t = t.replace(/((?:^|\n)- .+(?:\n- .+)*)/g, function(block) {
                    var items = block.trim().split('\n').map(function(line) {
                        return '<li>' + line.replace(/^- /, '') + '</li>';
                    }).join('');
                    return '<ul>' + items + '</ul>';
                });
                // Ordered list: 1. item
                t = t.replace(/((?:^|\n)\d+\. .+(?:\n\d+\. .+)*)/g, function(block) {
                    var items = block.trim().split('\n').map(function(line) {
                        return '<li>' + line.replace(/^\d+\.\s*/, '') + '</li>';
                    }).join('');
                    return '<ol>' + items + '</ol>';
                });
                // Line breaks
                t = t.replace(/\n/g, '<br>');
                // Clean up <br> around block elements
                t = t.replace(/(<\/(?:h[2-4]|ul|ol|pre|li|div)>)<br>/g, '$1');
                t = t.replace(/<br>(<(?:h[2-4]|ul|ol|pre|div))/g, '$1');
                return t;
            }

            // Copy code block content
            window.bizcCopyCode = function(btn) {
                var code = btn.parentElement.querySelector('code');
                if (!code) return;
                navigator.clipboard.writeText(code.innerText).then(function() {
                    btn.textContent = '✓ Copied';
                    btn.classList.add('copied');
                    setTimeout(function() {
                        btn.textContent = 'Copy';
                        btn.classList.remove('copied');
                    }, 2000);
                });
            };

            // ── Message polling — for async push-back (tarot result, etc.) ──
            var _msgPollStartTime = 0;
            var _msgPollMaxDuration = 5 * 60 * 1000; // 5 minutes max
            var _msgPollGraceTimer = null;
            function syncLastMsgId(cb) {
                // Quick fetch to get current max message ID for this session.
                // This ensures poll starts AFTER the last known message,
                // preventing re-fetching messages already rendered by SSE/AJAX.
                var syncId = sessionId || currentWcId;
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_webchat_session_messages', session_id: syncId, _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success && res.data && res.data.messages) {
                            var msgs = res.data.messages;
                            if (msgs.length) {
                                lastMsgId = msgs[msgs.length - 1].id || lastMsgId;
                                // Mark all existing messages as rendered to prevent duplicates
                                msgs.forEach(function(m) {
                                    if (m.id) _renderedMsgIds[m.id] = true;
                                });
                            }
                        }
                        if (cb) cb();
                    },
                    error: function() { if (cb) cb(); }
                });
            }
            function startMsgPoll() {
                if (_msgPollTimer) return;
                _msgPollStartTime = Date.now();
                _msgPollTimer = setInterval(pollNewMessages, 5000);
                // Initial grace: stop after 60s if no new messages arrive at all
                if (_msgPollGraceTimer) clearTimeout(_msgPollGraceTimer);
                _msgPollGraceTimer = setTimeout(function() { stopMsgPoll(); }, 60000);
            }
            // Expose for executor panel (different script scope)
            window._bizcStartMsgPoll = startMsgPoll;
            window._bizcStopMsgPoll  = stopMsgPoll;
            function stopMsgPoll() {
                if (_msgPollTimer) {
                    clearInterval(_msgPollTimer);
                    _msgPollTimer = null;
                }
                if (_msgPollGraceTimer) {
                    clearTimeout(_msgPollGraceTimer);
                    _msgPollGraceTimer = null;
                }
            }
            function pollNewMessages() {
                if (!sessionId || !lastMsgId) return;
                // Auto-stop after max duration
                if (Date.now() - _msgPollStartTime > _msgPollMaxDuration) {
                    stopMsgPoll();
                    return;
                }
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: {
                        action: 'bizcity_webchat_session_poll',
                        session_id: sessionId,
                        since_id: lastMsgId,
                        _wpnonce: nonce
                    },
                    dataType: 'json',
                    success: function(res) {
                        if (!res.success || !res.data || !res.data.messages) return;
                        var newMsgs = res.data.messages;
                        if (!newMsgs.length) return;
                        newMsgs.forEach(function(m) {
                            if (m.id && m.id > lastMsgId) lastMsgId = m.id;

                            // ── Dedup layer 1: skip if this DB id was already rendered ──
                            if (m.id && _renderedMsgIds[m.id]) return;

                            if (m.from === 'system') return;
                            var from = (m.from === 'bot') ? 'bot' : 'user';

                            // ── Dedup layer 2: skip user messages from poll ──
                            // User messages are always rendered locally by _doSend().
                            // The poll should only bring in async bot messages (executor, tools, etc.)
                            if (from === 'user') {
                                if (m.id) _renderedMsgIds[m.id] = true;
                                return;
                            }

                            // ── Dedup layer 3: text + time fuzzy match ──
                            // Catch SSE-rendered bot replies that arrive again via poll.
                            var mRole = 'assistant';
                            var mText = (m.text || '').trim();
                            var mTime = m.created_ts ? m.created_ts * 1000 : new Date(m.created_at.replace(' ', 'T') + 'Z').getTime();
                            var dominated = messages.some(function(existing) {
                                if (existing.role !== mRole && existing.role !== 'bot') return false;
                                var existText = (existing.content || '').trim();
                                // Exact text match — always dedup regardless of time
                                if (existText === mText) return true;
                                // Partial match: first 100 chars match (covers LLM enrichment differences)
                                if (mText.length > 50 && existText.substring(0, 100) === mText.substring(0, 100)) return true;
                                return false;
                            });
                            if (dominated) {
                                if (m.id) _renderedMsgIds[m.id] = true;
                                return;
                            }

                            // Mark as rendered and display
                            if (m.id) _renderedMsgIds[m.id] = true;
                            var imgs = (m.attachments && m.attachments.length) ? m.attachments : [];
                            messages.push({ role: from, content: m.text, timestamp: mTime, images: imgs });
                            appendMsg(m.text, from, mTime, true, imgs);
                        });
                        // Keep polling 120s more in case multi-step executor workflow
                        if (_msgPollGraceTimer) clearTimeout(_msgPollGraceTimer);
                        _msgPollGraceTimer = setTimeout(function() { stopMsgPoll(); }, 120000);
                    }
                });
            }

            // Copy entire bot message
            window.bizcCopyMsg = function(btn) {
                var bubble = btn.closest('.bizc-msg').querySelector('.bizc-msg-bubble');
                if (!bubble) return;
                // Clone bubble, remove action buttons, then get text
                var clone = bubble.cloneNode(true);
                var acts = clone.querySelector('.bizc-msg-actions');
                if (acts) acts.remove();
                var text = clone.innerText || clone.textContent;
                navigator.clipboard.writeText(text).then(function() {
                    btn.innerHTML = '✓';
                    btn.classList.add('copied');
                    setTimeout(function() {
                        btn.innerHTML = '📋';
                        btn.classList.remove('copied');
                    }, 2000);
                });
            };

            // ════════════════════════════════════════════════════════════
            //  PLUGIN PILLS SYSTEM
            //  
            //  Load and display available agent plugins as pills above input
            //  Uses Plugin Suggestion API to get active agents with icons
            // ════════════════════════════════════════════════════════════
            
            function loadPluginPills() {
                // DISABLED: Pills system removed for ChatGPT-style simplicity
                // Only @ mention dropdown is used now
                console.log('Pills system disabled - using @ mentions only');
                return;
            }
            
            function renderPluginPills(plugins) {
                var $pillsContainer = $('#bizc-pills-container');
                var html = '';
                
                plugins.forEach(function(plugin) {
                    var iconSrc = plugin.icon || '';
                    var iconHtml = iconSrc ? 
                        '<img src="' + iconSrc + '" alt="" class="bizc-pill-icon" onerror="this.style.display=\'none\'">' :
                        '<span class="bizc-pill-icon">🤖</span>';
                    
                    html += '<div class="bizc-pill" data-slug="' + plugin.slug + '" data-name="' + plugin.name + '" title="' + plugin.description + '">' +
                                iconHtml +
                                '<span class="bizc-pill-name">' + plugin.name + '</span>' +
                            '</div>';
                });
                
                $pillsContainer.html(html);
                
                // Add click handlers for pills
                $pillsContainer.on('click', '.bizc-pill', function() {
                    var slug = $(this).data('slug');
                    var name = $(this).data('name');
                    selectPluginPill(slug, name);
                });
            }
            
            function selectPluginPill(slug, name) {
                // Toggle active state
                var $pill = $('.bizc-pill[data-slug="' + slug + '"]');
                var wasActive = $pill.hasClass('active');
                
                // Clear all active states
                $('.bizc-pill').removeClass('active');
                
                if (!wasActive) {
                    // Activate this pill
                    $pill.addClass('active');
                    
                    // Set mention provider (same as @mention system)
                    var icon = $pill.find('.bizc-pill-icon img').attr('src') || 
                               $pill.find('.bizc-pill-icon').text() || '🤖';
                    _selectMention(slug, name, icon);
                    
                    // Enter plugin context mode
                    enterPluginContextMode(slug, name, icon);
                    
                    // Update input placeholder
                    $input.attr('placeholder', 'Nhập tin nhắn cho ' + name + '...');
                    
                    // Trigger visual feedback
                    $pill.css('transform', 'scale(0.95)');
                    setTimeout(function() {
                        $pill.css('transform', '');
                    }, 150);
                } else {
                    // Deactivate - clear selection
                    clearPluginSelection();
                }
            }
            
            function clearPluginSelection() {
                $('.bizc-pill').removeClass('active');
                _clearMention(); // Also clear @mention system
                $input.attr('placeholder', 'Nhập tin nhắn... (@ chọn agent · / tìm tool)');
                exitPluginContextMode();
            }
            
            // ════════════════════════════════════════════════════════════
            //  PLUGIN CONTEXT MODE UI/UX FUNCTIONS
            //  
            //  Visual indicators when user is in plugin-specific context
            // ════════════════════════════════════════════════════════════
            
            function enterPluginContextMode(pluginSlug, pluginName, pluginIcon) {
                var $inputArea = $('#bizc-input-area');
                var $contextHeader = $('#bizc-context-header');
                var $contextIcon = $('#bizc-context-icon');
                var $messages = $('#bizc-messages');
                
                // Add context mode class to input area
                $inputArea.addClass('plugin-context-mode');
                
                // Update plugin icon
                if (pluginIcon && pluginIcon.indexOf('/') > -1) {
                    $contextIcon.html('<img src="' + pluginIcon + '" class="bizc-context-plugin-icon" alt="">');
                } else {
                    $contextIcon.text(pluginIcon || '🤖');
                }
                
                // Show context header with slide animation
                $contextHeader.addClass('active');
                
                // Load inline tool chips for this plugin
                _loadContextTools(pluginSlug);
                
                // Add context mode to messages container for styling
                $messages.addClass('plugin-context-mode');
                
                // Visual feedback - brief highlight
                $inputArea.css({
                    'transform': 'scale(1.01)',
                    'transition': 'transform 0.3s ease'
                });
                
                setTimeout(function() {
                    $inputArea.css('transform', 'scale(1)');
                }, 300);
                
                console.log('🎯 Entered plugin context mode:', pluginSlug);
            }
            
            function exitPluginContextMode() {
                var $inputArea = $('#bizc-input-area');
                var $contextHeader = $('#bizc-context-header');
                var $messages = $('#bizc-messages');
                
                // Remove context mode classes
                $inputArea.removeClass('plugin-context-mode');
                $contextHeader.removeClass('active');
                $messages.removeClass('plugin-context-mode');
                
                // Clear tool chips row
                $('#bizc-context-tools').empty();
                
                console.log('↩️ Exited plugin context mode');
            }
            
            // ═══ HIL FOCUS MODE — handle focus_mode from SSE/REST/AJAX done ═══
            // Re-enters or exits plugin context mode based on server signal.
            // 'active'    → keep/enter HIL loop focus
            // 'completed' → goal achieved, exit focus
            // 'none'      → no goal context, no change
            function _handleFocusMode(data) {
                var fm = data.focus_mode || 'none';
                var ps = data.plugin_slug || '';

                if (fm === 'active' && ps) {
                    // Look up label + icon from Pre-Intent chips bar
                    var $chip = $chipsScroll.find('.bizc-plugin-chip[data-slug="' + ps + '"]');
                    var chipLabel = $chip.length ? ($chip.data('label') || ps) : ps;
                    var chipIcon  = $chip.length ? ($chip.data('icon') || '🤖') : '🤖';

                    // Restore _mentionProvider so next send still includes provider_hint
                    _mentionProvider = { slug: ps, label: chipLabel, icon: chipIcon };

                    // Enter/maintain visual context mode
                    enterPluginContextMode(ps, chipLabel, chipIcon);

                    // Sync chip bar
                    $chipsScroll.find('.bizc-plugin-chip').removeClass('active suggested');
                    $chipsScroll.find('.bizc-plugin-chip[data-slug="' + ps + '"]').addClass('active');

                    // Show mention tag
                    var iconHtml = (chipIcon && chipIcon.indexOf('/') > -1)
                        ? '<img src="' + chipIcon + '" style="width:16px;height:16px;border-radius:4px;vertical-align:middle;" alt="">'
                        : (chipIcon || '🤖');
                    $mentionTag.html(iconHtml + ' ' + chipLabel + ' <span class="bizc-mt-remove" title="Bỏ chọn agent">✕</span>').show();

                    console.log('🎯 [Focus] Maintaining plugin context:', ps);
                } else if (fm === 'completed') {
                    _clearMention();
                    console.log('✅ [Focus] Goal completed — exiting plugin context');
                }
                // 'none' — no action needed (already cleared or general conversation)
            }

            // Context mode event handlers — cancel active intent conversation on close
            $('#bizc-context-close').on('click', function() {
                // Cancel the active intent conversation on server
                if (window._bizcIntentConvId) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'bizcity_intent_cancel',
                            conversation_id: window._bizcIntentConvId,
                            _wpnonce: nonce
                        }
                    });
                    console.log('🚫 [Focus] Cancelled intent conversation:', window._bizcIntentConvId);
                    window._bizcIntentConvId = null;
                }
                clearPluginSelection();
            });

            // ═══ Tool Chip Click — select tool from inline context header chips ═══
            $(document).on('click', '.bizc-tool-chip', function() {
                var $chip = $(this);
                var goal      = $chip.data('goal');
                var toolName  = $chip.data('tool-name');
                var title     = $chip.data('title');
                var goalLabel = $chip.data('goal-label');
                var slug      = $chip.data('plugin-slug');
                var name      = $chip.data('plugin-name');
                var icon      = $chip.data('icon');

                // Highlight active chip
                $('.bizc-tool-chip').removeClass('active');
                $chip.addClass('active');

                _selectTool(goal, toolName, title, goalLabel, slug, name, icon);
            });
            
            // (Floating indicator removed — only context header in input area is used)
            
            // Load pills on initialization
            // loadPluginPills(); // DISABLED: Using @mentions only for ChatGPT-style simplicity
            
            // Refresh pills every 30 seconds to catch newly activated plugins
            // setInterval(loadPluginPills, 30000); // DISABLED: Pills system removed
            
            // ════════════════════════════════════════════════════════════
            //  ENHANCED CONTEXT MODE FEEDBACK
            //  
            //  Audio and haptic feedback for better user experience
            // ════════════════════════════════════════════════════════════
            
            // Audio feedback (subtle beeps)
            function playContextEnterSound() {
                try {
                    // Create a short, pleasant beep
                    var audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    var oscillator = audioContext.createOscillator();
                    var gainNode = audioContext.createGain();
                    
                    oscillator.connect(gainNode);
                    gainNode.connect(audioContext.destination);
                    
                    oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
                    oscillator.frequency.setValueAtTime(1000, audioContext.currentTime + 0.1);
                    
                    gainNode.gain.setValueAtTime(0, audioContext.currentTime);
                    gainNode.gain.linearRampToValueAtTime(0.1, audioContext.currentTime + 0.01);
                    gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.2);
                    
                    oscillator.start(audioContext.currentTime);
                    oscillator.stop(audioContext.currentTime + 0.2);
                } catch (e) {
                    // Audio not supported, ignore
                }
            }
            
            function playContextExitSound() {
                try {
                    var audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    var oscillator = audioContext.createOscillator();
                    var gainNode = audioContext.createGain();
                    
                    oscillator.connect(gainNode);
                    gainNode.connect(audioContext.destination);
                    
                    oscillator.frequency.setValueAtTime(600, audioContext.currentTime);
                    oscillator.frequency.setValueAtTime(400, audioContext.currentTime + 0.1);
                    
                    gainNode.gain.setValueAtTime(0, audioContext.currentTime);
                    gainNode.gain.linearRampToValueAtTime(0.05, audioContext.currentTime + 0.01);
                    gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.15);
                    
                    oscillator.start(audioContext.currentTime);
                    oscillator.stop(audioContext.currentTime + 0.15);
                } catch (e) {
                    // Audio not supported, ignore
                }
            }
            
            // Haptic feedback (mobile)
            function triggerHapticFeedback(type) {
                if (navigator.vibrate) {
                    if (type === 'enter') {
                        navigator.vibrate([50, 50, 100]); // short-pause-long
                    } else if (type === 'exit') {
                        navigator.vibrate([100, 50, 50]); // long-pause-short
                    } else {
                        navigator.vibrate(50); // single short vibration
                    }
                }
            }
            
            // Enhanced enterPluginContextMode with feedback
            var originalEnterPluginContextMode = enterPluginContextMode;
            enterPluginContextMode = function(pluginSlug, pluginName, pluginIcon) {
                originalEnterPluginContextMode(pluginSlug, pluginName, pluginIcon);
                
                // Add feedback
                playContextEnterSound();
                triggerHapticFeedback('enter');
            };
            
            // Enhanced exitPluginContextMode with feedback
            var originalExitPluginContextMode = exitPluginContextMode;
            exitPluginContextMode = function() {
                originalExitPluginContextMode();
                
                // Add feedback
                playContextExitSound();
                triggerHapticFeedback('exit');
            };
            
            // ════════════════════════════════════════════════════════════
            //  ROUTING CONFIRMATION SYSTEM
            //  
            //  Show confirmation of which routing path was used
            // ════════════════════════════════════════════════════════════
            
            function showRoutingConfirmation(mode, pluginName) {
                var icon = mode === 'manual' ? '🎯' : '🤖';
                var text = mode === 'manual' ? 
                    'Đã gửi đến ' + (pluginName || 'Plugin') :
                    'Đang phân tích intent tự động';
                
                var confirmationHtml = 
                    '<div class="bizc-routing-success" id="bizc-routing-confirm">' +
                    '<div class="bizc-routing-success-icon">✓</div>' +
                    '<span>' + icon + ' ' + text + '</span>' +
                    '</div>';
                
                // Insert before messages
                $('#bizc-messages').prepend(confirmationHtml);
                
                // Auto-remove after 3 seconds
                setTimeout(function() {
                    $('#bizc-routing-confirm').fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 3000);
            }
            
            // Routing confirmation disabled — no longer showing "Đang phân tích intent tự động"
        });
        </script>
        <?php endif; ?>
        <?php
    }

}
