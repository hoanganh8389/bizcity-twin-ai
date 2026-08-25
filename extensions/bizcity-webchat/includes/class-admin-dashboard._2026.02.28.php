<?php
/**
 * Bizcity Twin AI — Personalized AI Companion Platform
 * Module: Webchat — Admin Dashboard Chat Interface (Legacy 2026.02.28)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      1.2.0
 */

class BizCity_WebChat_Admin_Dashboard {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Replace default dashboard
        add_action('admin_menu', [$this, 'reorder_menu'], 999);
        add_action('admin_head', [$this, 'redirect_dashboard']);
        
        // Add dashboard page
        add_action('admin_menu', [$this, 'add_dashboard_page'], 5);
        
        // Enqueue assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    
    /**
     * Redirect default dashboard to chat dashboard
     */
    public function redirect_dashboard() {
        global $pagenow;
        
        if ($pagenow === 'index.php' && !isset($_GET['page'])) {
            wp_redirect(admin_url('admin.php?page=bizcity-webchat-dashboard'));
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
            'manage_options', // All users can access
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
     */
    public function render_dashboard() {
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
        
        $session_id = 'adminchat_' . get_current_blog_id() . '_' . get_current_user_id();
        $nonce = wp_create_nonce('bizcity_webchat');

        // Only show Mode Router Console for dev admins
        $current_user = wp_get_current_user();
        $is_dev_admin = in_array( $current_user->user_login, [ 'admin1', 'hoanganh.itm' ], true );
        
        ?>
        <style>
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
            border-radius: 24px;
            box-shadow: 0 8px 32px rgba(99, 102, 241, 0.12);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .bizc-new-chat-btn {
            margin: 16px;
            padding: 14px 20px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border: none;
            border-radius: 16px;
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
            padding: 6px 12px;
            align-items: center;
        }
        .bizc-proj-add-form input {
            flex: 1;
            border: 1.5px solid rgba(99,102,241,0.25);
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 12px;
            outline: none;
            background: #fafbff;
            color: #1a1a2e;
        }
        .bizc-proj-add-form input:focus {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 2px rgba(139,92,246,0.1);
        }
        .bizc-proj-add-form button {
            padding: 5px 10px;
            border-radius: 8px;
            border: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .bizc-proj-add-form .bizc-proj-save {
            background: #6366f1;
            color: #fff;
        }
        .bizc-proj-add-form .bizc-proj-cancel {
            background: #e5e7eb;
            color: #374151;
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
            padding: 6px 12px 0;
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
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
        }
        .bizc-msg.user .bizc-msg-av { 
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
        }
        .bizc-msg-av img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        
        .bizc-msg-bubble {
            max-width: 65%;
            padding: 14px 18px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.7;
            word-break: break-word;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .bizc-msg.bot .bizc-msg-bubble {
            background: #f3f4f6;
            color: #1a1a2e;
            border-bottom-left-radius: 6px;
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
            margin-top: 6px;
            font-weight: 500;
        }
        .bizc-msg.user .bizc-msg-time { text-align: right; }
        
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
        
        /* Input */
        .bizc-input-area {
            padding: 16px 24px 20px;
            background: #ffffff;
            border-top: 1px solid rgba(99,102,241,0.08);
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }
        
        .bizc-attach-btn {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #f3f4f6;
            border: 1.5px solid rgba(99,102,241,0.15);
            color: #6366f1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .bizc-attach-btn:hover { 
            background: linear-gradient(135deg, rgba(99,102,241,0.1), rgba(139,92,246,0.1));
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(99,102,241,0.2);
        }
        
        .bizc-input {
            flex: 1;
            background: #f9fafb;
            border: 1.5px solid rgba(99,102,241,0.15);
            border-radius: 22px;
            padding: 12px 20px;
            font-size: 14px;
            color: #1a1a2e;
            outline: none;
            resize: none;
            min-height: 20px;
            max-height: 150px;
            transition: all 0.2s;
            line-height: 1.5;
        }
        .bizc-input::placeholder { color: #9ca3af; }
        .bizc-input:focus { 
            border-color: #8b5cf6;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }
        
        .bizc-send-btn {
            width: 42px;
            height: 42px;
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
        }
        .bizc-send-btn:hover { 
            transform: scale(1.08);
            box-shadow: 0 6px 24px rgba(99,102,241,0.4);
        }
        .bizc-send-btn:disabled { 
            background: #e5e7eb;
            cursor: not-allowed;
            box-shadow: none;
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
            font-size: 10px;
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
        }
        @media (max-width: 600px) {
            .bizc-sidebar { display: none; }
            /* .bizc-tools responsive — single col (already) */
            .bizc-dash { padding: 8px; }
        }
        </style>
        
        <div class="bizc-dash">
            <!-- Sidebar -->
            <div class="bizc-sidebar">
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
                    <a href="?page=bizcity-webchat" class="bizc-settings-link">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M12 1v6m0 6v6m8.66-10l-5.2 3m-5.92 3.4l-5.2 3M20.66 19l-5.2-3m-5.92-3.4l-5.2-3"></path>
                        </svg>
                        Cấu hình & Settings
                    </a>
                </div>
            </div>
            
            <!-- Main -->
            <div class="bizc-main">
                <?php if ($character): ?>
                
                <?php if ($is_dev_admin): ?>
                <!-- Mode Router Console (full width, compact) — dev admin only -->
                <div class="bizc-tools">
                    <div class="bizc-tool-card">
                        <div id="bizc-router-console" style="background:#1e1e2e;color:#cdd6f4;border-radius:14px;font-family:'JetBrains Mono',Consolas,monospace;font-size:11px;max-height:180px;display:flex;flex-direction:column;">
                            <div style="padding:6px 12px;background:#313244;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
                                <span style="font-weight:600;color:#89b4fa;font-size:12px;">
                                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#a6e3a1;margin-right:6px;vertical-align:middle;" id="bizc-poll-dot"></span>
                                    Tư duy phân tích & Nhận diện (Router Console)
                                </span>
                                <span style="display:flex;gap:4px;align-items:center;">
                                    <a href="<?php echo admin_url('admin.php?page=bccm_my_profile'); ?>" style="background:#45475a;color:#f9e2af;border:none;padding:3px 8px;border-radius:4px;font-size:10px;text-decoration:none;white-space:nowrap;" title="Cài hồ sơ & chiêm tinh">🌟 Hồ sơ</a>
                                    <button id="bizc-router-poll-btn" onclick="bizcRouterPoll(event)" style="background:#45475a;color:#cdd6f4;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="Start/Stop polling">▶ Poll</button>
                                    <button onclick="bizcRouterClear(event)" style="background:#45475a;color:#cdd6f4;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="Clear logs">🗑 Clear</button>
                                    <button onclick="bizcRouterFullscreen(event)" id="bizc-fs-btn" style="background:#45475a;color:#cdd6f4;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="Phóng to / Thu nhỏ">⛶ Expand</button>
                                </span>
                            </div>
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
                </style>
                <script>
                /* ── Console session must match the chat session so poll reads the correct transient ── */
                window.bizcSessionId = '<?php echo esc_js( $session_id ); ?>';
                var _bizcRouterInterval = null;
                var _bizcIsFullscreen = false;

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
                    _fetchRouterLogs();
                    _bizcRouterInterval = setInterval(_fetchRouterLogs, 2000);
                }

                function bizcRouterClear(e) {
                    if (e) e.stopPropagation();
                    document.getElementById('bizc-router-logs').innerHTML = '<div style="color:#6c7086;">Cleared.</div>';
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
                    if (log.mode_ms || log.classify_ms || log.duration_ms) {
                        h += '<span class="bizc-rlog-ms">' + (log.mode_ms || log.classify_ms || log.duration_ms) + 'ms</span>';
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
                    if (log.context_length) h += '<div class="bizc-rlog-context">📚 ' + log.context_length + ' chars (profile=' + (log.has_profile?'✓':'✗') + ' transit=' + (log.has_transit?'✓':'✗') + ' knowledge=' + (log.has_knowledge?'✓':'✗') + ')</div>';
                    if (log.response_preview) h += '<div class="bizc-rlog-response">✅ ' + _esc(log.response_preview).substring(0,200) + '</div>';
                    if (log.prompt_preview) h += '<div class="bizc-rlog-prompt">📝 ' + _esc(log.prompt_preview).substring(0,300) + '</div>';
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
                <?php else: ?>
                <!-- Feature cards for non-dev-admin users -->
                <div class="bizc-tools">
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
                        <a href="<?php echo admin_url('admin.php?page=bccm_my_profile'); ?>" class="bizc-feature-card" style="text-decoration:none;">
                            <div class="bizc-fc-icon">🌟</div>
                            <div class="bizc-fc-title">Cài Hồ sơ</div>
                            <div class="bizc-fc-desc">Tạo hồ sơ & chiêm tinh</div>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-characters'); ?>" class="bizc-feature-card" style="text-decoration:none;">
                            <div class="bizc-fc-icon">📚</div>
                            <div class="bizc-fc-title">Cài Kiến thức</div>
                            <div class="bizc-fc-desc">Nạp kiến thức cho trợ lý AI</div>
                        </a>
                        <a href="<?php echo admin_url('index.php?page=bizcity-marketplace'); ?>" class="bizc-feature-card" style="text-decoration:none;">
                            <div class="bizc-fc-icon">🏪</div>
                            <div class="bizc-fc-title">Chợ AI Agent</div>
                            <div class="bizc-fc-desc">Khám phá & tải AI Agent</div>
                        </a>
                    </div>
                </div>
                <style>
                    .bizc-feature-card {
                        background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(139,92,246,0.06));
                        border: 1px solid rgba(99,102,241,0.15);
                        border-radius: 14px;
                        padding: 16px 14px;
                        text-align: center;
                        transition: all 0.2s;
                        color: #1a1a2e;
                        cursor: pointer;
                    }
                    .bizc-feature-card:hover {
                        background: linear-gradient(135deg, rgba(99,102,241,0.16), rgba(139,92,246,0.12));
                        border-color: rgba(99,102,241,0.3);
                        transform: translateY(-2px);
                        box-shadow: 0 4px 12px rgba(99,102,241,0.15);
                    }
                    .bizc-fc-icon { font-size: 28px; margin-bottom: 6px; }
                    .bizc-fc-title { font-weight: 700; font-size: 13px; color: #312e81; }
                    .bizc-fc-desc { font-size: 11px; color: #6b7280; margin-top: 3px; }
                </style>
                <?php endif; ?>
                
                <!-- Project Detail Panel (hidden by default - shown when clicking a project) -->
                <div id="bizc-project-detail" style="display:none;flex:1;flex-direction:column;overflow:hidden;">
                    <div style="padding:20px 28px 12px;border-bottom:1px solid #e5e7eb;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                            <button id="bizc-proj-back" style="background:none;border:none;cursor:pointer;font-size:18px;color:#6366f1;padding:4px;" title="Quay lại chat">←</button>
                            <span id="bizc-proj-detail-icon" style="font-size:24px;">📁</span>
                            <h2 id="bizc-proj-detail-name" style="margin:0;font-size:18px;font-weight:700;color:#1a1a2e;"></h2>
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

                <!-- Header -->
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
                            <h2><?php echo esc_html($char_name); ?></h2>
                            <span><?php echo esc_html($char_desc); ?> • Online</span>
                        </div>
                    </div>
                </div>
                
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
                
                <!-- Input -->
                <div class="bizc-input-area">
                    <input type="file" id="bizc-file-input" accept="image/*" multiple style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;">
                    <label for="bizc-file-input" class="bizc-attach-btn" id="bizc-attach" title="Đính kèm ảnh">
                        📎
                    </label>
                    <textarea class="bizc-input" id="bizc-input" placeholder="Nhập tin nhắn..." rows="1"></textarea>
                    <button class="bizc-send-btn" id="bizc-send" type="button" disabled>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </div>
                </div><!-- /bizc-chat-panel -->
                
                <?php else: ?>
                <div style="flex:1;display:flex;align-items:center;justify-content:center;color:#9ca3af;">
                    <div style="text-align:center;">
                        <div style="font-size:48px;margin-bottom:12px;">🤖</div>
                        <div>Chưa có character nào</div>
                        <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-characters'); ?>" style="display:inline-block;margin-top:12px;padding:8px 20px;background:#6366f1;color:#fff;border-radius:10px;text-decoration:none;">Tạo Character</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($character): ?>
        <script>
        jQuery(function($) {
            // Prevent multiple initialization
            if (window.bizcDashInitialized) return;
            window.bizcDashInitialized = true;
            
            var charId = <?php echo $character_id; ?>,
                baseSessionId = '<?php echo esc_js($session_id); ?>',
                sessionId = '<?php echo esc_js($session_id); ?>',
                nonce = '<?php echo esc_js($nonce); ?>',
                ajaxurl = '<?php echo admin_url("admin-ajax.php"); ?>',
                $msgs = $('#bizc-messages'),
                $input = $('#bizc-input'),
                $send = $('#bizc-send'),
                botAvatar = <?php echo wp_json_encode($char_avatar ?: ''); ?>,
                messages = [],
                wcSessions = [],
                projects = [],
                currentWcId = null,        // webchat_conversations primary key
                currentProjectId = null,
                openProjects = {},
                pendingImages = [],
                dragSrcId = null;   // for drag & drop
            
            // Init
            loadProjects();
            loadSessions();
            loadIntentConversations();
            
            // Event delegation for sessions in "Gần đây" section
            $('#bizc-convs-list').off('click').on('click', '.bizc-conv', function(e) {
                var wcId = $(this).data('wc-id');
                if (wcId) loadSession(wcId);
            });

            // Event delegation for sessions inside projects
            $('#bizc-proj-list').off('click', '.bizc-proj-conv').on('click', '.bizc-proj-conv', function(e) {
                var wcId = $(this).data('wc-id');
                if (wcId) loadSession(wcId);
            });

            // Context menu on session right-click (move to project) - only for convs-list, NOT intent-list
            $(document).off('contextmenu', '#bizc-convs-list .bizc-conv, .bizc-proj-conv').on('contextmenu', '#bizc-convs-list .bizc-conv, .bizc-proj-conv', function(e) {
                e.preventDefault();
                var wcId = $(this).data('wc-id');
                if (!wcId) return; // skip if no wc-id (safety)
                showSessionContextMenu(e.pageX, e.pageY, wcId);
            });
            
            // Events
            $('#bizc-new-chat').off('click').on('click', startNewChat);
            $('#bizc-clear-all').off('click').on('click', clearAllSessions);
            $('#bizc-add-project').off('click').on('click', showAddProjectForm);

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
            $input.off('keydown').on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMsg();
                }
            });
            $input.off('input').on('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 150) + 'px';
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
                        '<span class="bizc-proj-count">' + (proj.conv_count || 0) + '</span>' +
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
                        var wcId = parseInt(e.dataTransfer.getData('text/plain'));
                        if (wcId && wcId > 0) {
                            moveSessionToProject(wcId, proj.id);
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
                            var $c = $('<div class="bizc-proj-conv' + (s.id === currentWcId ? ' active' : '') + '" data-wc-id="' + s.id + '">' +
                                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"></path></svg> ' +
                                '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(s.title) + '</span>' +
                                '</div>');
                            $container.append($c);
                        });
                    }
                });
            }

            function showAddProjectForm() {
                var $list = $('#bizc-proj-list');
                if ($list.find('.bizc-proj-add-form').length) { $list.find('.bizc-proj-add-form input').focus(); return; }
                var $form = $('<div class="bizc-proj-add-form" style="display:flex;gap:6px;padding:6px 8px;align-items:center;">' +
                    '<input type="text" placeholder="Tên dự án..." style="flex:1;background:#1e1e2e;border:1px solid #374151;border-radius:6px;padding:4px 8px;color:#e5e7eb;font-size:12px;outline:none;" />' +
                    '<button type="button" style="background:#6366f1;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer;">OK</button>' +
                    '<button type="button" style="background:transparent;color:#9ca3af;border:none;padding:4px 6px;font-size:11px;cursor:pointer;">✕</button>' +
                    '</div>');
                $list.prepend($form);
                var $inp = $form.find('input').focus();
                var doCreate = function() {
                    var name = $inp.val().trim();
                    if (!name) { $form.remove(); return; }
                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: { action: 'bizcity_project_create', name: name, _wpnonce: nonce },
                        dataType: 'json',
                        success: function(res) { $form.remove(); if (res.success) loadProjects(); },
                        error: function() { $form.remove(); }
                    });
                };
                $form.find('button').eq(0).on('click', doCreate);
                $form.find('button').eq(1).on('click', function() { $form.remove(); });
                $inp.on('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); doCreate(); }
                    if (e.key === 'Escape') { $form.remove(); }
                });
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

            // ── Context menu: right-click session → move to project ──
            function showSessionContextMenu(x, y, wcId) {
                $('.bizc-ctx-menu').remove();
                if (!projects.length) return;
                var $menu = $('<div class="bizc-ctx-menu" style="left:' + x + 'px;top:' + y + 'px;"></div>');
                $menu.append('<div style="padding:4px 12px;font-size:11px;color:#9ca3af;border-bottom:1px solid #374151;">Chuyển vào dự án</div>');
                projects.forEach(function(proj) {
                    $menu.append('<div class="bizc-ctx-menu-item" data-project-id="' + esc(proj.id) + '">' + esc(proj.icon || '📁') + ' ' + esc(proj.name) + '</div>');
                });
                $menu.append('<div style="border-top:1px solid #374151;"></div>');
                $menu.append('<div class="bizc-ctx-menu-item" data-project-id="">📤 Bỏ khỏi dự án</div>');
                $menu.on('click', '.bizc-ctx-menu-item', function() {
                    var pid = $(this).data('project-id');
                    $menu.remove();
                    moveSessionToProject(wcId, pid || '');
                });
                $('body').append($menu);
            }

            function moveSessionToProject(wcId, projectId) {
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_webchat_session_move', session_id: wcId, project_id: projectId, _wpnonce: nonce },
                    dataType: 'json',
                    success: function() { loadProjects(); loadSessions(); }
                });
            }

            // ── Project Detail Panel (ChatGPT-style) ──
            function showProjectDetail(proj) {
                currentProjectId = proj.id;
                $('#bizc-proj-detail-icon').text(proj.icon || '📁');
                $('#bizc-proj-detail-name').text(proj.name);
                $('#bizc-chat-panel').hide();
                $('#bizc-project-detail').css('display', 'flex');
                loadProjectDetailList(proj.id);
                $('.bizc-proj-header').removeClass('active');
                $('.bizc-proj-item[data-project-id="' + proj.id + '"] .bizc-proj-header').addClass('active');
            }

            function hideProjectDetail() {
                currentProjectId = null;
                $('#bizc-project-detail').hide();
                $('#bizc-chat-panel').css('display', 'flex');
                $('.bizc-proj-header').removeClass('active');
            }

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
                            var $item = $('<div class="bizc-proj-detail-item" data-wc-id="' + s.id + '">' +
                                '<div style="flex:1;min-width:0;">' +
                                    '<div class="pdi-title">' + esc(s.title) + '</div>' +
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
            function loadSessions() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'bizcity_webchat_sessions', _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        wcSessions = (res.success && res.data) ? res.data : [];
                        renderSessions();
                    },
                    error: function() { wcSessions = []; renderSessions(); }
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
                    var $conv = $('<div class="bizc-conv' + (s.id === currentWcId ? ' active' : '') + '" data-wc-id="' + s.id + '" draggable="true" title="Kéo thả vào dự án để di chuyển">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"></path></svg>' +
                        '<span class="bizc-conv-title">' + esc(s.title) + '</span>' +
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
             * Create a new webchat session via AJAX, then call callback.
             */
            function createNewSession(callback) {
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_webchat_session_create', _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success && res.data) {
                            currentWcId = res.data.id;
                            sessionId = res.data.session_id;
                            // Update global sessionId for router polling
                            window.bizcCurrentSessionId = sessionId;
                        }
                        if (callback) callback();
                    },
                    error: function() { if (callback) callback(); }
                });
            }

            function startNewChat() {
                messages = [];
                $msgs.find('.bizc-msg').remove();
                hideProjectDetail();
                // Create a fresh webchat session
                createNewSession(function() {
                    var greetingHtml = <?php echo wp_json_encode($random_greeting); ?>;
                    if (greetingHtml) appendMsg(greetingHtml, 'bot', Date.now(), false, []);
                    loadSessions(); // Refresh from server so new session appears in sidebar
                    $input.focus();
                });
            }

            function loadSession(wcId) {
                if (wcId === currentWcId) return;
                hideProjectDetail();
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_webchat_session_messages', session_id: wcId, _wpnonce: nonce },
                    dataType: 'json',
                    success: function(res) {
                        if (!res.success || !res.data) return;
                        currentWcId = wcId;
                        sessionId = res.data.session_id;  // switch SSE/AJAX session to this conversation
                        window.bizcSessionId = sessionId;  // sync for Router Console poll
                        // Update global sessionId for router polling
                        window.bizcCurrentSessionId = sessionId;
                        messages = [];
                        $msgs.empty();
                        var msgs = res.data.messages || [];
                        msgs.forEach(function(m) {
                            if (m.from === 'system') return;
                            var from = (m.from === 'bot') ? 'bot' : 'user';
                            var imgs = (m.attachments && m.attachments.length) ? m.attachments : [];
                            messages.push({ role: from, content: m.text, timestamp: new Date(m.created_at).getTime(), images: imgs });
                            appendMsg(m.text, from, new Date(m.created_at).getTime(), false, imgs);
                        });
                        scrollBottom();
                        renderSessions();
                    }
                });
            }

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
                }, 5000);
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
            function loadIntentConversations(silent) {
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'bizcity_intent_conversations', _wpnonce: nonce, session_id: sessionId },
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
                    var statusIcon = '⏳';
                    var statusColor = '#f59e0b';
                    if (status === 'completed') { statusIcon = '✅'; statusColor = '#10b981'; }
                    else if (status === 'failed' || status === 'cancelled') { statusIcon = '❌'; statusColor = '#ef4444'; }
                    else if (status === 'active' || status === 'in_progress') { statusIcon = '🔄'; statusColor = '#3b82f6'; }
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
                            var from = t.role === 'assistant' ? 'bot' : 'user';
                            messages.push({ role: from, content: t.content, timestamp: new Date(t.created_at).getTime() });
                            appendMsg(t.content, from, new Date(t.created_at).getTime(), false);
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
            
            // Send message — tries SSE streaming first, falls back to regular AJAX
            function sendMsg() {
                var text = $input.val().trim();
                if (!text && !pendingImages.length) return;

                // If no active webchat session, create one first then send
                if (!currentWcId || !sessionId || sessionId === baseSessionId) {
                    $input.val('');
                    createNewSession(function() {
                        $input.val(text);
                        sendMsg();
                    });
                    return;
                }
                
                $input.val('').css('height', 'auto');
                $send.prop('disabled', true);
                
                // Add user message
                var timestamp = Date.now();
                var messageData = {
                    role: 'user',
                    content: text,
                    timestamp: timestamp,
                    images: pendingImages.map(function(img) { return img.data; })
                };
                
                messages.push(messageData);
                appendMsg(text, 'user', timestamp, true, messageData.images);
                updateCurrentSession();
                
                // Clear images
                clearImages();
                
                // Show typing indicator
                var typId = 'typ-' + Math.random().toString(36).substr(2, 6);
                $msgs.append(
                    '<div class="bizc-typing" id="' + typId + '">' +
                    '<div class="bizc-msg-av">' + avHtml('bot') + '</div>' +
                    '<div class="bizc-typing-dots">' +
                    '<div class="bizc-typing-dot"></div><div class="bizc-typing-dot"></div><div class="bizc-typing-dot"></div>' +
                    '</div></div>'
                );
                scrollBottom();
                
                // Try SSE streaming
                sendMsgStream(text, messageData.images, typId);

                // Auto-start console polling on first message
                if (!_bizcRouterInterval) bizcRouterPoll(null);
            }
            
            // ── SSE Streaming via fetch + ReadableStream ──
            function sendMsgStream(text, images, typId) {
                var formData = new FormData();
                formData.append('action', 'bizcity_chat_stream');
                formData.append('message', text);
                formData.append('character_id', charId);
                formData.append('session_id', sessionId);
                formData.append('platform_type', 'ADMINCHAT');
                formData.append('_wpnonce', nonce);
                if (images && images.length) {
                    formData.append('images', JSON.stringify(images));
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
                                }
                                if (fullText) {
                                    messages.push({ role: 'assistant', content: fullText, timestamp: Date.now() });
                                    updateCurrentSession();
                                }
                                updateBtn();
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
                                        $msgs.append(
                                            '<div class="bizc-msg bot">' +
                                            '<div class="bizc-msg-av">' + avHtml('bot') + '</div>' +
                                            '<div>' +
                                            '<div class="bizc-msg-bubble" id="' + bubbleId + '"></div>' +
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
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bizcity_chat_send',
                        platform_type: 'ADMINCHAT',
                        message: text,
                        session_id: sessionId,
                        character_id: charId,
                        image_data: images && images.length > 0 ? images[0] : '',
                        _wpnonce: nonce
                    },
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
                                updateCurrentSession();
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
                
                $msgs.append(
                    '<div class="bizc-msg ' + from + '">' +
                    '<div class="bizc-msg-av">' + avHtml(from) + '</div>' +
                    '<div>' +
                    imgHtml +
                    '<div class="bizc-msg-bubble">' + formatted + '</div>' +
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
                t = esc(t);
                t = t.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                t = t.replace(/\*(.*?)\*/g, '<em>$1</em>');
                t = t.replace(/`(.*?)`/g, '<code style="background:#e5e7eb;padding:2px 6px;border-radius:4px;color:#4c1d95;">$1</code>');
                t = t.replace(/\n/g, '<br>');
                return t;
            }
        });
        </script>
        <?php endif; ?>
        <?php
    }
}
