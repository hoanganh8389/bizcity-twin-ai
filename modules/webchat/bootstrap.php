<?php
/**
 * Bizcity Twin AI — WebChat Bot Module
 * Module WebChat Bot — Xử lý trò chuyện trực tiếp trên website
 *
 * Web chat trigger engine integrated with bizcity-workflow (WAIC).
 * Công cụ trigger web chat tích hợp bizcity-workflow (WAIC).
 *
 * Features / Tính năng:
 * - Trigger workflows từ tin nhắn web chat
 * - Human-in-the-Loop (HIL) support
 * - Conversation logging to database
 * - Timeline view (Relevance AI style)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @version    3.0.16
 * @since      2026-02-07
 *
 * This file is part of Bizcity Twin AI.
 * Unauthorized copying, modification, or distribution is prohibited.
 * Sao chép, chỉnh sửa hoặc phân phối trái phép bị nghiêm cấm.
 */

defined('ABSPATH') or die('OOPS...');

// Self-sufficient path constants (previously defined by mu-plugin loader)
if ( ! defined( 'BIZCITY_WEBCHAT_DIR' ) ) {
    define( 'BIZCITY_WEBCHAT_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_WEBCHAT_URL' ) ) {
    define( 'BIZCITY_WEBCHAT_URL', plugin_dir_url( __FILE__ ) );
}

// Constants — guarded to allow coexistence with legacy mu-plugin
if ( ! defined( 'BIZCITY_WEBCHAT_VERSION' ) ) {
    define('BIZCITY_WEBCHAT_VERSION', '3.0.16');
}
if ( ! defined( 'BIZCITY_WEBCHAT_INCLUDES' ) ) {
    define('BIZCITY_WEBCHAT_INCLUDES', BIZCITY_WEBCHAT_DIR . 'includes/');
}
if ( ! defined( 'BIZCITY_WEBCHAT_LIB' ) ) {
    define('BIZCITY_WEBCHAT_LIB', BIZCITY_WEBCHAT_DIR . 'lib/');
}
if ( ! defined( 'BIZCITY_WEBCHAT_ASSETS' ) ) {
    define('BIZCITY_WEBCHAT_ASSETS', BIZCITY_WEBCHAT_DIR . 'assets/');
}
if ( ! defined( 'BIZCITY_WEBCHAT_TEMPLATES' ) ) {
    define('BIZCITY_WEBCHAT_TEMPLATES', BIZCITY_WEBCHAT_DIR . 'templates/');
}
if ( ! defined( 'BIZCITY_WEBCHAT_LOGS' ) ) {
    define('BIZCITY_WEBCHAT_LOGS', BIZCITY_WEBCHAT_DIR . 'logs/');
}

// Skip class loading if already loaded by legacy mu-plugin
if ( class_exists( 'BizCity_WebChat_Database' ) ) {
    return;
}

// Translations — load .po files from /languages/ (text domain: bizcity-webchat)
add_action( 'init', function() {
    load_plugin_textdomain( 'bizcity-webchat', false, plugin_basename( BIZCITY_WEBCHAT_DIR ) . '/languages' );
}, 5 );

/* ═══════════════════════════════════════════════════════════════
 * AGENT IFRAME MODE
 * Khi URL co ?bizcity_iframe=1 (Touch Bar goi), an header/footer/adminbar
 * bang CSS. Cac plugin agent chi can include template binh thuong.
 * ═══════════════════════════════════════════════════════════════ */

/* Disable Query Monitor HTML output completely inside iframe and /chat/ page */
if ( ! empty( $_GET['bizcity_iframe'] ) || preg_match( '#^/chat(/|$|\?)#', $_SERVER['REQUEST_URI'] ?? '' ) ) {
    add_filter( 'qm/dispatch/html', '__return_false' );
}

/* ── Allow cross-origin iframe embedding when ?bizcity_iframe=1 ── */
if ( ! empty( $_GET['bizcity_iframe'] ) ) {
    /* Remove WordPress core X-Frame-Options header */
    remove_action( 'admin_init', 'send_frame_options_header' );
    remove_action( 'login_init', 'send_frame_options_header' );

    /* Strip X-Frame-Options set by server/plugins, allow multisite admin domains */
    add_action( 'send_headers', function() {
        header_remove( 'X-Frame-Options' );
    }, 99 );

    /* Also filter wp_headers for good measure */
    add_filter( 'wp_headers', function( $headers ) {
        unset( $headers['X-Frame-Options'] );
        return $headers;
    }, 99 );
}

/* ── Admin pages inside iframe: collapse admin menu for cleaner view ── */
if ( ! empty( $_GET['bizcity_iframe'] ) ) {
    /* Force admin menu folded via user setting (persistent) — no, use CSS only */
    add_action( 'admin_head', function() {
        ?>
        <style id="bizcity-iframe-admin-mode">
        /* Collapse admin menu completely */
        #adminmenumain, #adminmenuback, #adminmenuwrap,
        #adminmenu, #collapse-menu {
            display: none !important;
        }
        /* Remove left margin that admin menu normally occupies */
        #wpcontent, #wpfooter {
            margin-left: 0 !important;
        }
        /* Hide admin bar */
        #wpadminbar {
            display: none !important;
        }
        html.wp-toolbar {
            padding-top: 0 !important;
        }
        /* Hide footer */
        #wpfooter {
            display: none !important;
        }
        /* Let content fill viewport */
        #wpbody-content {
            padding-bottom: 0 !important;
        }
        /* Hide Query Monitor in admin iframe */
        #query-monitor-main, #qm, #qm-wrapper,
        .qm-no-js, [id^="qm-"] {
            display: none !important;
        }
        /* Hide screen options & help tabs */
        #screen-meta, #screen-meta-links {
            display: none !important;
        }
        </style>
        <?php
    }, 1 );
}

add_action( 'wp_head', function() {
    if ( empty( $_GET['bizcity_iframe'] ) ) return;
    ?>
    <style id="bizcity-iframe-mode">
    /* ── Hide header, footer, admin bar, breadcrumbs ── */
    #wpadminbar,
    header, .site-header, #masthead, #site-header,
    .header-main, .header-wrapper, .header-top, .header-bottom,
    .top-bar, #top-bar, .top-header,
    nav.main-navigation, .primary-navigation,
    footer, .site-footer, #colophon, #site-footer,
    .footer-main, .footer-wrapper, .footer-widgets,
    .breadcrumbs, .woocommerce-breadcrumb, .breadcrumb,
    .page-title-wrapper, .entry-title,
    .bizcity-float-widget, #bizcity-float-widget,
    #bizchat-float-btn, .nobi-fe-float-btn {
        display: none !important;
    }

    #button-contact-vr {display: none !important; }
    /* Hide Query Monitor output inside iframe */
    #query-monitor-main, #qm, #qm-wrapper,
    .qm-no-js, [id^="qm-"] {
        display: none !important;
    }
    /* Hide mobile sidebar (Flatsome) */
    .mobile-sidebar, .mfp-bg, .mfp-wrap {
        display: none !important;
    }
    /* Remove top spacing from admin bar */
    html { margin-top: 0 !important; background: #fff !important; }
    body { padding-top: 0 !important; margin-top: 0 !important; background: #fff !important; }
    /* Let content fill full width */
    .site-content, .content-area, #primary, #main, .main-content,
    .entry-content, .page-content {
        padding-top: 0 !important;
        margin-top: 0 !important;
        max-width: 100% !important;
    }
    #bct-profile-wrap input[type="text"],  select, #bct-profile-wrap textarea { height:unset !important; }

    </style>
    <?php
}, 1 );

// Load includes
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-webchat-database.php';
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-webchat-trigger.php';
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-webchat-widget.php';
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-webchat-timeline.php';
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-webchat-memory.php';
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-webchat-api.php';
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-admin-menu.php';
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-admin-dashboard.php'; // Admin dashboard with chat
// require_once BIZCITY_WEBCHAT_INCLUDES . 'class-working-panel.php'; // REMOVED — floating execution monitor disabled
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-chatbot-shortcode.php'; // New chatbot shortcode
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-ajax-handlers.php'; // V3 Project/Session AJAX handlers
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-plugin-suggestion-api.php'; // v3.1.0 Plugin @mention suggestions
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-plugin-gathering.php';     // v3.2.0 Plugin Gathering (@ mention slot filling)
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-automation-provider.php';   // v3.3.0 Automation Bridge (bc_ blocks + it_ intent tools)
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-auth-ajax.php';              // AJAX Login/Register for React modal
require_once BIZCITY_WEBCHAT_INCLUDES . 'class-login-page.php';             // AIQuill-style wp-login.php
require_once BIZCITY_WEBCHAT_INCLUDES . 'functions.php';

// Load lib
require_once BIZCITY_WEBCHAT_LIB . 'class-webchat-ai.php';

// Initialize classes
if ( is_admin() ) {
    BizCity_WebChat_Admin_Menu::instance();
    BizCity_WebChat_Admin_Dashboard::instance(); // Chat dashboard
    BizCity_WebChat_Ajax_Handlers::instance(); // V3 AJAX handlers
    BizCity_WebChat_Auth_Ajax::boot(); // AJAX Login/Register for guest modal
}

// Initialize REST API
BizCity_WebChat_API::instance();

// Initialize Automation Provider (bc_ blocks + it_ intent tools bridge)
BizCity_WebChat_Automation_Provider::instance();

// AIQuill-style login page (wp-login.php, not admin context)
BizCity_Login_Page::boot();

// Initialize Plugin Gathering (v3.2.0 — hooks into bizcity_chat_pre_ai_response @2)
BizCity_Plugin_Gathering::instance();

/* =====================================================================
 * Auth cookie lifetime: 1 year (365 days)
 * Default WP "remember me" = 14 days. Extend for AI Agent users.
 * =====================================================================*/
add_filter( 'auth_cookie_expiration', function( $length, $user_id, $remember ) {
    if ( $remember ) {
        return YEAR_IN_SECONDS; // 365 days
    }
    return $length;
}, 10, 3 );

/* =====================================================================
 * WooCommerce redirect fallback for /chat/ page
 * If JS fails and the WC form submits normally, redirect back to /chat/.
 * This runs at `init` — before WC's `wp_loaded` form handler.
 * =====================================================================*/
add_action( 'init', function() {
    $ref = $_POST['_wp_http_referer'] ?? '';
    if ( ! empty( $ref ) && strpos( $ref, '/chat' ) !== false ) {
        $chat_url = home_url( '/chat/' );
        add_filter( 'woocommerce_login_redirect',        function() use ( $chat_url ) { return $chat_url; }, 99 );
        add_filter( 'woocommerce_registration_redirect', function() use ( $chat_url ) { return $chat_url; }, 99 );
    }
}, 5 );

/* =====================================================================
 * AJAX Login / Register for AI Agent Frontend (nopriv)
 * =====================================================================*/
add_action( 'wp_ajax_nopriv_bizcity_aiagent_login', 'bizcity_aiagent_ajax_login' );
add_action( 'wp_ajax_nopriv_bizcity_aiagent_register', 'bizcity_aiagent_ajax_register' );

/**
 * AJAX Login — authenticate user without page reload.
 */
if ( ! function_exists( 'bizcity_aiagent_ajax_login' ) ) {
function bizcity_aiagent_ajax_login() {
    // Verify nonce
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'bizcity_aiagent_auth' ) ) {
        wp_send_json_error( [ 'message' => 'Phiên làm việc hết hạn, vui lòng tải lại trang.' ] );
    }

    $username = sanitize_user( $_POST['username'] ?? '' );
    $password = $_POST['password'] ?? '';

    if ( empty( $username ) || empty( $password ) ) {
        wp_send_json_error( [ 'message' => 'Vui lòng nhập tên đăng nhập và mật khẩu.' ] );
    }

    $user = wp_signon( [
        'user_login'    => $username,
        'user_password' => $password,
        'remember'      => true,
    ], is_ssl() );

    if ( is_wp_error( $user ) ) {
        $code = $user->get_error_code();
        $msg  = 'Tên đăng nhập hoặc mật khẩu không đúng.';
        if ( $code === 'invalid_username' || $code === 'invalid_email' ) {
            $msg = 'Tài khoản không tồn tại.';
        }
        wp_send_json_error( [ 'message' => $msg ] );
    }

    wp_set_current_user( $user->ID );

    wp_send_json_success( [
        'message'      => 'Đăng nhập thành công!',
        'display_name' => $user->display_name,
        'user_id'      => $user->ID,
        'redirect'     => '',
    ] );
}
} // end if function_exists bizcity_aiagent_ajax_login

/**
 * AJAX Register — create new user account without page reload.
 * Supports phone-based registration (from WC form on /chat/ page).
 */
if ( ! function_exists( 'bizcity_aiagent_ajax_register' ) ) {
function bizcity_aiagent_ajax_register() {
    // Verify nonce
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'bizcity_aiagent_auth' ) ) {
        wp_send_json_error( [ 'message' => 'Phiên làm việc hết hạn, vui lòng tải lại trang.' ] );
    }

    if ( ! get_option( 'users_can_register' ) ) {
        wp_send_json_error( [ 'message' => 'Đăng ký tài khoản hiện đang bị tắt.' ] );
    }

    $phone    = preg_replace( '/\D/', '', $_POST['phone'] ?? '' );
    $email    = sanitize_email( $_POST['email'] ?? '' );
    $username = sanitize_user( $_POST['username'] ?? '' );
    $password = $_POST['password'] ?? '';

    // Phone-based registration: phone → username
    if ( empty( $username ) && ! empty( $phone ) ) {
        // Normalize: strip leading 84 → 0
        if ( preg_match( '/^84(\d{9,})$/', $phone, $m ) ) {
            $phone = '0' . $m[1];
        }
        $username = $phone;
    }

    // Auto-generate email from phone if empty/invalid
    if ( empty( $email ) || ! is_email( $email ) ) {
        if ( ! empty( $phone ) ) {
            $email = $phone . '@bizcity.vn';
        }
    }

    // Auto-generate username from email if still empty
    if ( empty( $username ) && ! empty( $email ) ) {
        $username = sanitize_user( strstr( $email, '@', true ) );
        $base = $username;
        $i    = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $i;
            $i++;
        }
    }

    if ( empty( $email ) || ! is_email( $email ) ) {
        wp_send_json_error( [ 'message' => 'Vui lòng nhập email hoặc số điện thoại hợp lệ.' ] );
    }
    if ( email_exists( $email ) ) {
        wp_send_json_error( [ 'message' => 'Email hoặc số điện thoại này đã được sử dụng. Hãy đăng nhập.' ] );
    }
    if ( empty( $username ) ) {
        wp_send_json_error( [ 'message' => 'Vui lòng nhập số điện thoại.' ] );
    }
    if ( username_exists( $username ) ) {
        wp_send_json_error( [ 'message' => 'Số điện thoại này đã được đăng ký. Hãy đăng nhập.' ] );
    }

    // Auto-generate password if empty
    if ( empty( $password ) ) {
        $password = wp_generate_password( 12, true );
    }

    $user_id = wp_create_user( $username, $password, $email );

    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( [ 'message' => $user_id->get_error_message() ] );
    }

    // Set role
    $user = new WP_User( $user_id );
    $user->set_role( 'subscriber' );

    // Auto-login
    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true, is_ssl() );

    // Send notification email
    wp_new_user_notification( $user_id, null, 'both' );

    wp_send_json_success( [
        'message'      => 'Đăng ký thành công! Đang đăng nhập...',
        'display_name' => $user->display_name,
        'user_id'      => $user_id,
        'redirect'     => '',
    ] );
}
} // end if function_exists bizcity_aiagent_ajax_register

/**
 * Buffer raw input sớm (để webhook có thể đọc)
 */
if (!isset($GLOBALS['BIZCITY_WEBCHAT_RAW_INPUT'])) {
    $GLOBALS['BIZCITY_WEBCHAT_RAW_INPUT'] = file_get_contents('php://input');
}

if (!function_exists('bizcity_webchat_get_raw_input')) {
    function bizcity_webchat_get_raw_input(): string {
        return (string)($GLOBALS['BIZCITY_WEBCHAT_RAW_INPUT'] ?? '');
    }
}

/**
 * Initialize Plugin
 */
if (!class_exists('BizCity_WebChat_Bot')) {

class BizCity_WebChat_Bot {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->init_hooks();
        
        // Ensure tables exist (auto-create if missing)
        add_action('init', [$this, 'ensure_tables_exist'], 0);

        // Auto-create /chat/ page with AI Agent template (run once per site)
        add_action('init', [$this, 'ensure_chat_page_exists'], 20);
    }

    /**
     * Auto-create /chat/ page với template AI Agent.
     * Luôn kiểm tra page tồn tại — tự khôi phục nếu bị xóa/trash.
     */
    public function ensure_chat_page_exists() {
        $slugs = [ 'chat' => 'Chat', 'app' => 'App' ];
        $needs_flush = false;

        foreach ( $slugs as $slug => $title ) {
            $existing = get_page_by_path( $slug );
            if ( $existing ) {
                if ( $existing->post_status !== 'publish' ) {
                    wp_update_post( [
                        'ID'          => $existing->ID,
                        'post_status' => 'publish',
                    ] );
                    $needs_flush = true;
                }
                if ( get_post_meta( $existing->ID, '_wp_page_template', true ) !== 'bizcity-aiagent-home' ) {
                    update_post_meta( $existing->ID, '_wp_page_template', 'bizcity-aiagent-home' );
                }
            } else {
                $page_id = wp_insert_post( [
                    'post_title'   => $title,
                    'post_name'    => $slug,
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_content' => '<!-- AI Agent page managed by BizCity WebChat -->',
                ] );
                if ( $page_id && ! is_wp_error( $page_id ) ) {
                    update_post_meta( $page_id, '_wp_page_template', 'bizcity-aiagent-home' );
                    $needs_flush = true;
                }
            }
        }

        if ( $needs_flush ) {
            flush_rewrite_rules();
        }
    }
    
    private function init_hooks() {
        // Register rewrite rules
        add_action('init', [$this, 'register_rewrite_rules'], 1);
        
        // Query vars
        add_filter('query_vars', [$this, 'add_query_vars']);
        
        // Webhook handler
        add_action('template_redirect', [$this, 'handle_webhook'], 0);
        
        // Frontend widget only (chủ yếu phục vụ trigger automation)
        add_action('wp_footer', [$this, 'render_chat_widget']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
            // Admin widget – disabled (BCA floating icon tạm tắt để gọn admin)
            // add_action('admin_footer', [$this, 'render_admin_chat_widget']);
            // add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            
        // AJAX handlers — DEPRECATED: Chat send/history/clear now handled by BizCity_Chat_Gateway
        // Old action names bizcity_webchat_send_message are registered as backward-compat in the gateway.
        // Only keep non-chat AJAX handlers here:
        add_action('wp_ajax_bizcity_webchat_timeline', [$this, 'ajax_get_timeline']);
        
        // Shortcodes
        add_shortcode('bizcity_webchat', [$this, 'shortcode_webchat']);
        add_shortcode('bizcity_webchat_timeline', [$this, 'shortcode_timeline']);
        
        // Register AI Agent page template (mu-plugin template)
        add_filter('theme_page_templates', [$this, 'register_aiagent_template']);
        add_filter('template_include', [$this, 'load_aiagent_template']);
        
        // Activation/Deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }
    
    /**
     * Register AI Agent page template in the page template dropdown.
     */
    public function register_aiagent_template( $templates ) {
        $templates['bizcity-aiagent-home'] = 'Trang chủ AI Agent (BizCity)';
        return $templates;
    }

    /**
     * Load AI Agent page template file when selected.
     */
    public function load_aiagent_template( $template ) {
        $page_template = get_post_meta( get_the_ID(), '_wp_page_template', true );
        if ( 'bizcity-aiagent-home' === $page_template ) {
            $custom = BIZCITY_WEBCHAT_TEMPLATES . 'page-aiagent-home.php';
            if ( file_exists( $custom ) ) {
                return $custom;
            }
        }
        return $template;
    }

    /**
     * Register rewrite rules cho webhook endpoint: /webchat-hook/
     */
    public function register_rewrite_rules() {
        add_rewrite_tag('%webchat_hook%', '([0-1])');
        add_rewrite_rule('^webchat-hook/?$', 'index.php?webchat_hook=1', 'top');

        // SPA routing: /app/* and /chat/* sub-paths → same parent page
        // Enables /app/new-chat, /app/session/123, /chat/new-chat, etc.
        add_rewrite_rule('^app/(.+?)/?$', 'index.php?pagename=app', 'top');
        add_rewrite_rule('^chat/(.+?)/?$', 'index.php?pagename=chat', 'top');
    }
    
    public function add_query_vars($vars) {
        $vars[] = 'webchat_hook';
        return $vars;
    }
    
    /**
     * Handle webhook từ external sources hoặc internal AJAX
     */
    public function handle_webhook() {
        if ((int) get_query_var('webchat_hook') !== 1) return;
        
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            status_header(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
            exit;
        }
        
        // Log raw input
        $log_file = BIZCITY_WEBCHAT_LOGS . 'webhook-raw.log';
        if (!file_exists(dirname($log_file))) {
            wp_mkdir_p(dirname($log_file));
        }
        
        $raw = bizcity_webchat_get_raw_input();
        
        $meta = [
            'time'   => gmdate('c'),
            'uri'    => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'ctype'  => $_SERVER['CONTENT_TYPE'] ?? '',
            'len'    => strlen($raw),
        ];
        
        file_put_contents($log_file, "==" . json_encode($meta, JSON_UNESCAPED_SLASHES) . "==\n" . $raw . "\n\n", FILE_APPEND);
        
        $data = json_decode($raw, true);
        
        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        
        if (!is_array($data)) {
            echo json_encode([
                'ok' => false,
                'error' => 'Invalid JSON',
                'json_error' => json_last_error_msg(),
            ]);
            exit;
        }
        
        // Process webhook
        $result = BizCity_WebChat_Trigger::instance()->process_webhook($data);
        
        echo json_encode(['ok' => true, 'result' => $result]);
        exit;
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        // Chatbot shortcode CSS - luôn enqueue để sẵn sàng cho shortcode
        wp_enqueue_style(
            'bizcity-webchat-chatbot',
            $this->get_asset_url('css/webchat-widget.css'),
            [],
            BIZCITY_WEBCHAT_VERSION
        );
        
        // Widget JS - luôn enqueue cho shortcode
        wp_enqueue_script(
            'bizcity-webchat-widget',
            $this->get_asset_url('js/widget.js'),
            ['jquery'],
            $this->get_asset_version('js/widget.js'),
            true
        );
        
        // Bot setup từ pmfacebook_options (tương thích với bizgpt-agent)
        $bot_setup = wp_parse_args(get_option('pmfacebook_options', []));
        $using_ai = isset($bot_setup['using_ai']) ? (bool) $bot_setup['using_ai'] : true;
        
        // Get default character_id
        $character_id = intval(get_option('bizcity_webchat_default_character_id', 0));
        if (empty($character_id) && isset($bot_setup['default_character_id'])) {
            $character_id = intval($bot_setup['default_character_id']);
        }
        
        wp_localize_script('bizcity-webchat-widget', 'bizcity_webchat_vars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('bizcity-webchat/v1'),
            'nonce' => wp_create_nonce('bizcity_webchat'),
            'session_id' => $this->get_session_id(),
            'user_id' => get_current_user_id(),
            'character_id' => $character_id, // Add character_id for bizcity-knowledge integration
            'site_name' => get_bloginfo('name'),
            'avatar_bot' => $this->get_bot_avatar(),
            'avatar_user' => $this->get_user_avatar(),
            'welcome_message' => $this->get_welcome_message(),
            'alert_sound_url' => content_url('uploads/alert.mp3'),
            'enable_polling' => $using_ai,
            'poll_interval' => 4000,
        ]);
        
        // Widget CSS cho floating chat - chỉ load nếu widget được bật
        if ($this->is_widget_enabled()) {
            wp_enqueue_style(
                'bizcity-webchat-widget',
                $this->get_asset_url('css/widget.css'),
                [],
                $this->get_asset_version('css/widget.css')
            );
        }
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (!is_admin()) return;
        
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'bizcity_webchat_admin_vars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bizcity_webchat'),
            'session_id' => $this->get_session_id(),
            'user_id' => get_current_user_id(),
        ]);
    }
    
    /**
     * Render chat widget on frontend
     */
    public function render_chat_widget() {
        if (!$this->is_widget_enabled()) return;
        
        // AI Agent blogs use their own full-page chat — hide the float widget
        if (get_option('blog_type', 'web') === 'aiagent') return;
        
        // /chat/ page uses full-page AI Agent template — skip float widget
        if (is_page() && get_post_meta(get_the_ID(), '_wp_page_template', true) === 'bizcity-aiagent-home') return;
        
        include BIZCITY_WEBCHAT_TEMPLATES . 'widget-float.php';
    }
    
    /**
     * Render admin chat widget
     */
    public function render_admin_chat_widget() {
        if (!current_user_can('edit_posts')) return;

        // Hide on admin chat dashboard (already has full chat UI)
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'toplevel_page_bizcity-webchat-dashboard') return;

        include BIZCITY_WEBCHAT_TEMPLATES . 'widget-admin.php';
    }
    
    /**
     * AJAX: Send message
     */
    public function ajax_send_message() {
        check_ajax_referer('bizcity_webchat');
        
        $message = sanitize_text_field($_POST['message'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $attachments = $_POST['attachments'] ?? [];
        
        // Handle images sent from new preview workflow
        if (isset($_POST['images']) && is_array($_POST['images'])) {
            foreach ($_POST['images'] as $image_data) {
                if (isset($image_data['data']) && isset($image_data['name'])) {
                    // Convert base64 image data to attachment format
                    $attachments[] = [
                        'type' => 'image',
                        'data' => $image_data['data'],
                        'name' => sanitize_file_name($image_data['name'])
                    ];
                }
            }
        }
        
        if (empty($message) && empty($attachments)) {
            wp_send_json_error(['message' => 'Empty message']);
            return;
        }
        
        $trigger = BizCity_WebChat_Trigger::instance();
        $result = $trigger->process_message([
            'platform_type' => 'WEBCHAT',
            'event' => 'message.create',
            'session_id' => $session_id ?: $this->get_session_id(),
            'user_id' => get_current_user_id(),
            'message' => [
                'text' => $message,
                'attachments' => $attachments,
                'message_id' => uniqid('wcm_'),
            ],
            'client_name' => $this->get_client_name(),
        ]);
        
        // Only return success status, let polling handle bot replies
        wp_send_json_success(['status' => 'sent']);
    }
    
    /**
     * AJAX: Send message via chatbot shortcode
     * 
     * Unified path: delegates to BizCity_Admin_Chat::get_ai_response()
     * so all chat interfaces share the same knowledge pipeline:
     *   Context API (embeddings + quick knowledge) + keyword search (bizcity_knowledge_search_character)
     */
    public function ajax_chatbot_send_message() {
        // No nonce check for public chatbot
        
        $message = sanitize_text_field($_POST['message'] ?? '');
        $character_id = isset($_POST['character_id']) ? intval($_POST['character_id']) : 0;
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $image_data = $_POST['image_data'] ?? ''; // Base64 image data
        
        if (empty($message) && empty($image_data)) {
            wp_send_json_error(['message' => 'Empty message']);
            return;
        }
        
        // Log user message to database
        $db = BizCity_WebChat_Database::instance();
        $db->log_message([
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'message_text' => $message ?: '[Image]',
            'message_from' => 'user',
        ]);
        
        // ── Unified path: delegate to BizCity_Admin_Chat (same as widget + knowledge chat) ──
        if (class_exists('BizCity_Admin_Chat')) {
            try {
                $admin_chat = BizCity_Admin_Chat::instance();
                
                // Prepare images array (Admin Chat expects array of base64 strings)
                $images = [];
                if (!empty($image_data)) {
                    $images[] = $image_data;
                }
                
                // Call the shared get_ai_response method
                $reply_data = $admin_chat->get_ai_response_public($character_id, $message, $images, $session_id);
                $reply = $reply_data['message'] ?? '';
                
                // Log bot reply
                if (!empty($reply)) {
                    $db->log_message([
                        'session_id' => $session_id,
                        'user_id' => get_current_user_id(),
                        'message_text' => $reply,
                        'message_from' => 'bot',
                    ]);
                }
                
                wp_send_json_success(['reply' => $reply]);
                exit;
            } catch (Exception $e) {
                error_log('BizCity WebChat Error (unified path): ' . $e->getMessage());
                wp_send_json_error(['message' => 'Có lỗi xảy ra: ' . $e->getMessage()]);
                exit;
            }
        }
        
        // Fallback: legacy bizcity_knowledge_chat (only if Admin Chat class unavailable)
        if (function_exists('bizcity_knowledge_chat')) {
            try {
                $reply = bizcity_knowledge_chat($message, $character_id, $session_id, $image_data);
                
                if (!empty($reply)) {
                    $db->log_message([
                        'session_id' => $session_id,
                        'user_id' => get_current_user_id(),
                        'message_text' => $reply,
                        'message_from' => 'bot',
                    ]);
                }
                
                wp_send_json_success(['reply' => $reply]);
                exit;
            } catch (Exception $e) {
                error_log('BizCity WebChat Error (legacy): ' . $e->getMessage());
                wp_send_json_error(['message' => 'Có lỗi xảy ra: ' . $e->getMessage()]);
                exit;
            }
        }
        
        wp_send_json_error(['message' => 'Hệ thống chat chưa sẵn sàng. Vui lòng liên hệ quản trị viên.']);
    }

    
    /**
     * Call OpenAI Chat API
     */
    private function call_openai_chat($api_key, $messages) {
        $url = 'https://api.openai.com/v1/chat/completions';
        
        $body = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1000,
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => json_encode($body),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            bizcity_webchat_log('OpenAI API error', ['error' => $response->get_error_message()], 'error');
            return '';
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }
        
        if (isset($data['error'])) {
            bizcity_webchat_log('OpenAI API error', $data['error'], 'error');
        }
        
        return '';
    }
    
    /**
     * AJAX: Get chat history
     */
    public function ajax_get_history() {
        check_ajax_referer('bizcity_webchat');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $session_id = $session_id ?: $this->get_session_id();
        
        $db = BizCity_WebChat_Database::instance();
        $history = $db->get_conversation_history($session_id, 50);
        
        wp_send_json_success($history);
    }
    
    /**
     * AJAX: Get timeline (Relevance AI style)
     */
    public function ajax_get_timeline() {
        check_ajax_referer('bizcity_webchat');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $task_id = sanitize_text_field($_POST['task_id'] ?? '');
        
        $timeline = BizCity_WebChat_Timeline::instance();
        $data = $timeline->get_timeline($session_id, $task_id);
        
        wp_send_json_success($data);
    }
    
    /**
     * Shortcode: [bizcity_webchat]
     */
    public function shortcode_webchat($atts) {
        $atts = shortcode_atts([
            'style' => 'embed', // embed | float
            'height' => '500px',
            'width' => '100%',
        ], $atts);
        
        ob_start();
        
        if ($atts['style'] === 'embed') {
            include BIZCITY_WEBCHAT_TEMPLATES . 'widget-embed.php';
        } else {
            include BIZCITY_WEBCHAT_TEMPLATES . 'widget-float.php';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Shortcode: [bizcity_webchat_timeline] - Timeline view giống Relevance AI
     */
    public function shortcode_timeline($atts) {
        $atts = shortcode_atts([
            'session_id' => '',
            'task_id' => '',
        ], $atts);
        
        ob_start();
        include BIZCITY_WEBCHAT_TEMPLATES . 'timeline.php';
        return ob_get_clean();
    }
    
    /**
     * Activation hook
     */
    public function activate() {
        $this->register_rewrite_rules();
        flush_rewrite_rules();
        
        // Create database tables
        BizCity_WebChat_Database::instance()->create_tables();
    }
    
    /**
     * Ensure tables exist + run migrations on schema version bump.
     */
    public function ensure_tables_exist() {
        if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
            return; // Class not loaded yet — skip silently
        }
        $db = BizCity_WebChat_Database::instance();
        $current_version = get_option( 'bizcity_webchat_db_version', '' );

        // Full table creation if needed
        if ( empty( $current_version ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'bizcity_webchat_messages';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
                $db->create_tables();
            } else {
                // Tables exist but no version tracked — run migration + set version
                $db->create_tables(); // V3: Also create new tables (projects, sessions)
                $db->maybe_upgrade_conversations();
            }
            update_option( 'bizcity_webchat_db_version', BizCity_WebChat_Database::SCHEMA_VERSION );
            return;
        }

        // Schema version mismatch → run migration + create new tables
        if ( $current_version !== BizCity_WebChat_Database::SCHEMA_VERSION ) {
            $db->create_tables(); // V3: dbDelta will add new tables/columns
            $db->maybe_upgrade_conversations();
            update_option( 'bizcity_webchat_db_version', BizCity_WebChat_Database::SCHEMA_VERSION );
        }
    }
    
    /**
     * Deactivation hook
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * Helper methods
     */
    private function is_widget_enabled() {
        return get_option('bizcity_webchat_widget_enabled', true);
    }
    
    private function get_session_id() {
        // Use cookie instead of PHP session to avoid "headers already sent" warning
        if (isset($_COOKIE['bizcity_session_id'])) {
            return sanitize_text_field($_COOKIE['bizcity_session_id']);
        }
        
        // Generate new session ID
        $session_id = 'sess_' . wp_generate_uuid4();
        
        // Only set cookie if headers not sent yet
        if (!headers_sent()) {
            setcookie('bizcity_session_id', $session_id, time() + (86400 * 30), '/');
        }
        
        return $session_id;
    }
    
    private function get_client_name() {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            return $user->display_name;
        }
        return 'Guest';
    }
    
    private function get_asset_url($path) {
        return plugins_url('assets/' . $path, __FILE__);
    }
    
    private function get_asset_version($path) {
        $file_path = BIZCITY_WEBCHAT_ASSETS . $path;
        if (file_exists($file_path)) {
            return filemtime($file_path);
        }
        return BIZCITY_WEBCHAT_VERSION;
    }
    
    private function get_bot_avatar() {
        return get_option('bizcity_webchat_bot_avatar', 'https://bizgpt.vn/wp-content/uploads/sites/583/agent.png');
    }
    
    private function get_user_avatar() {
        if (is_user_logged_in()) {
            return get_avatar_url(get_current_user_id());
        }
        return '';
    }
    
    private function get_welcome_message() {
        return get_option('bizcity_webchat_welcome', 'Xin chào! Tôi có thể giúp gì cho bạn?');
    }
}

} // End class_exists check

// Initialize
BizCity_WebChat_Bot::instance();

// ==========================================
// AJAX Handlers bổ sung (tương thích với bizgpt-agent)
// ==========================================

/**
 * AJAX: Pull new messages (polling)
 */
add_action('wp_ajax_bizcity_webchat_pull', 'bizcity_webchat_ajax_pull');
add_action('wp_ajax_nopriv_bizcity_webchat_pull', 'bizcity_webchat_ajax_pull');
if ( ! function_exists( 'bizcity_webchat_ajax_pull' ) ) {
function bizcity_webchat_ajax_pull() {
    check_ajax_referer('bizcity_webchat');
    
    global $wpdb;
    
    $last_id = intval($_POST['last_id'] ?? 0);
    $session_id = sanitize_text_field($_POST['session_id'] ?? '');
    $user_id = intval($_POST['user_id'] ?? 0);
    
    $table = $wpdb->prefix . 'bizcity_webchat_messages';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        // Fallback to bizgpt table if our table doesn't exist
        $table = $wpdb->prefix . 'bizgpt_chat_history';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            wp_send_json_success([]);
            return;
        }
        
        // Using bizgpt_chat_history table format
        if ($user_id) {
            $where = $wpdb->prepare("(user_id = %d OR session_id = %s) AND id > %d", $user_id, $session_id, $last_id);
        } else {
            $where = $wpdb->prepare("session_id = %s AND id > %d", $session_id, $last_id);
        }
        
        $rows = $wpdb->get_results("SELECT id, msg_text as message_text, msg_from as message_from, time FROM $table WHERE $where ORDER BY id ASC LIMIT 10");
    } else {
        // Using our table format
        if ($user_id) {
            $where = $wpdb->prepare("(user_id = %d OR session_id = %s) AND id > %d", $user_id, $session_id, $last_id);
        } else {
            $where = $wpdb->prepare("session_id = %s AND id > %d", $session_id, $last_id);
        }
        
        $rows = $wpdb->get_results("SELECT id, message_text, message_from, created_at as time FROM $table WHERE $where ORDER BY id ASC LIMIT 10");
    }
    
    $messages = [];
    foreach ($rows as $r) {
        $messages[] = [
            'id' => (int) $r->id,
            'msg' => $r->message_text,
            'from' => $r->message_from,
            'time' => $r->time
        ];
    }
    
    wp_send_json_success($messages);
}
} // end if function_exists bizcity_webchat_ajax_pull

/**
 * AJAX: Clear chat history
 */
add_action('wp_ajax_bizcity_webchat_clear', 'bizcity_webchat_ajax_clear');
add_action('wp_ajax_nopriv_bizcity_webchat_clear', 'bizcity_webchat_ajax_clear');
if ( ! function_exists( 'bizcity_webchat_ajax_clear' ) ) {
function bizcity_webchat_ajax_clear() {
    check_ajax_referer('bizcity_webchat');
    
    global $wpdb;
    
    $session_id = sanitize_text_field($_POST['session_id'] ?? '');
    $user_id = intval($_POST['user_id'] ?? 0);
    
    // Clear from our table
    $table = $wpdb->prefix . 'bizcity_webchat_messages';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
        if ($user_id) {
            $wpdb->delete($table, ['user_id' => $user_id]);
        } elseif ($session_id) {
            $wpdb->delete($table, ['session_id' => $session_id]);
        }
    }
    
    // Also clear from bizgpt table if exists
    $bizgpt_table = $wpdb->prefix . 'bizgpt_chat_history';
    if ($wpdb->get_var("SHOW TABLES LIKE '$bizgpt_table'") === $bizgpt_table) {
        if ($user_id) {
            $wpdb->delete($bizgpt_table, ['user_id' => $user_id]);
        } elseif ($session_id) {
            $wpdb->delete($bizgpt_table, ['session_id' => $session_id]);
        }
    }
    
    // Clear flow context transient
    if ($session_id) {
        delete_transient("bizcity_flow_ctx_$session_id");
        delete_transient("bizgpt_flow_ctx_$session_id");
    }
    
    wp_send_json_success();
}
} // end if function_exists bizcity_webchat_ajax_clear

/**
 * Upload file to WordPress Media Library
 * 
 * @param array $file $_FILES array element
 * @return array|WP_Error {
 *   'attachment_id' => int,
 *   'url' => string,
 *   'file' => string (path),
 *   'type' => string (mime type)
 * }
 */
if ( ! function_exists( 'bizcity_upload_to_media_library' ) ) {
function bizcity_upload_to_media_library( $file ) {
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    
    // Upload to uploads folder
    $uploaded = wp_handle_upload( $file, [ 'test_form' => false ] );
    
    if ( isset( $uploaded['error'] ) ) {
        return new WP_Error( 'upload_error', $uploaded['error'] );
    }
    
    $file_path = $uploaded['file'];
    $file_type = $uploaded['type'];
    $file_name = basename( $file_path );
    
    // Create attachment post
    $attachment = [
        'post_mime_type' => $file_type,
        'post_title'     => preg_replace( '/\.[^.]+$/', '', $file_name ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    
    $attachment_id = wp_insert_attachment( $attachment, $file_path, 0 );
    
    if ( is_wp_error( $attachment_id ) ) {
        return $attachment_id;
    }
    
    // Generate and save attachment metadata
    $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
    wp_update_attachment_metadata( $attachment_id, $metadata );
    
    // Get the permanent media library URL
    $url = wp_get_attachment_url( $attachment_id );
    
    return [
        'attachment_id' => $attachment_id,
        'url'           => $url,
        'file'          => $file_path,
        'type'          => $file_type,
    ];
}
} // end if function_exists bizcity_upload_to_media_library

/**
 * Save base64 data URL image to WordPress Media Library.
 * 
 * Converts base64 encoded image (from frontend) to a proper Media attachment.
 * This ensures all uploaded images go through Media Library for consistent URLs.
 *
 * @param string $base64_data Base64 data URL (e.g., "data:image/png;base64,...")
 * @param string $filename    Optional filename (default: auto-generated)
 * @return array|WP_Error {
 *   'attachment_id' => int,
 *   'url'           => string (permanent Media Library URL),
 *   'file'          => string (file path),
 *   'type'          => string (mime type)
 * }
 */
if ( ! function_exists( 'bizcity_save_base64_to_media' ) ) {
function bizcity_save_base64_to_media( $base64_data, $filename = '' ) {
    // Parse data URL: "data:image/png;base64,iVBORw0..."
    if ( ! preg_match( '/^data:image\/(\w+);base64,(.+)$/i', $base64_data, $matches ) ) {
        return new WP_Error( 'invalid_format', 'Invalid base64 data URL format' );
    }
    
    $ext = strtolower( $matches[1] );
    $data = base64_decode( $matches[2] );
    
    if ( $data === false ) {
        return new WP_Error( 'decode_error', 'Failed to decode base64 data' );
    }
    
    // Map extension to mime type
    $mime_map = [
        'jpeg' => 'image/jpeg',
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];
    $mime_type = $mime_map[ $ext ] ?? 'image/' . $ext;
    
    // Generate filename
    if ( empty( $filename ) ) {
        $filename = 'upload_' . date( 'Ymd_His' ) . '_' . wp_generate_password( 6, false ) . '.' . $ext;
    }
    
    // Get upload directory
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['path'] . '/' . $filename;
    
    // Save to file
    if ( file_put_contents( $file_path, $data ) === false ) {
        return new WP_Error( 'write_error', 'Failed to write file to uploads' );
    }
    
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    
    // Create attachment post
    $attachment = [
        'post_mime_type' => $mime_type,
        'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    
    $attachment_id = wp_insert_attachment( $attachment, $file_path, 0 );
    
    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $file_path );
        return $attachment_id;
    }
    
    // Generate and save attachment metadata
    $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
    wp_update_attachment_metadata( $attachment_id, $metadata );
    
    // Get permanent URL
    $url = wp_get_attachment_url( $attachment_id );
    
    return [
        'attachment_id' => $attachment_id,
        'url'           => $url,
        'file'          => $file_path,
        'type'          => $mime_type,
    ];
}
} // end if function_exists bizcity_save_base64_to_media

/**
 * Convert array of base64 images to Media Library URLs.
 * 
 * Helper function to process multiple images at once.
 *
 * @param array $images Array of base64 data URLs or existing URLs
 * @return array Array of Media Library URLs
 */
if ( ! function_exists( 'bizcity_convert_images_to_media_urls' ) ) {
function bizcity_convert_images_to_media_urls( $images ) {
    if ( empty( $images ) || ! is_array( $images ) ) {
        return [];
    }
    
    $result = [];
    foreach ( $images as $img ) {
        // Already a URL (http/https) - keep as-is
        if ( is_string( $img ) && preg_match( '/^https?:\/\//i', $img ) ) {
            $result[] = $img;
            continue;
        }
        
        // Base64 data URL - convert to Media
        if ( is_string( $img ) && strpos( $img, 'data:image/' ) === 0 ) {
            $media = bizcity_save_base64_to_media( $img );
            if ( ! is_wp_error( $media ) ) {
                $result[] = $media['url'];
            }
            continue;
        }
        
        // Unknown format - skip
    }
    
    return $result;
}
} // end if function_exists bizcity_convert_images_to_media_urls

/**
 * AJAX: Upload file (audio/image)
 */
add_action('wp_ajax_bizcity_webchat_upload', 'bizcity_webchat_ajax_upload');
add_action('wp_ajax_nopriv_bizcity_webchat_upload', 'bizcity_webchat_ajax_upload');
if ( ! function_exists( 'bizcity_webchat_ajax_upload' ) ) {
function bizcity_webchat_ajax_upload() {
    check_ajax_referer('bizcity_webchat');
    
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] != 0) {
        wp_send_json_error(['message' => 'Không nhận được file!']);
        return;
    }
    
    // Upload to Media Library (unified flow)
    $media = bizcity_upload_to_media_library( $file );
    
    if ( is_wp_error( $media ) ) {
        wp_send_json_error(['message' => 'Lỗi upload: ' . $media->get_error_message()]);
        return;
    }
    
    $file_path     = $media['file'];
    $file_url      = $media['url'];
    $attachment_id = $media['attachment_id'];
    $mime          = $media['type'];
    
    $session_id = sanitize_text_field($_POST['session_id'] ?? '');
    $transcript = '';
    $reply = '';
    
    // Process audio file (speech-to-text)
    if ($mime && strpos($mime, 'audio/') === 0) {
        $api_key = get_option('twf_openai_api_key');
        
        if ($api_key) {
            // Convert audio if needed
            $converted_file = bizcity_webchat_convert_audio_if_needed($file_path);
            if ($converted_file) {
                $file_path = $converted_file;
            }
            
            // Speech to text
            $transcript = bizcity_webchat_audio_to_text($api_key, $file_path);
        }
        
        if (!empty($transcript)) {
            // Process the transcribed text
            $trigger = BizCity_WebChat_Trigger::instance();
            $result = $trigger->process_message([
                'platform_type' => 'WEBCHAT',
                'event' => 'message.create',
                'session_id' => $session_id,
                'user_id' => get_current_user_id(),
                'message' => [
                    'text' => $transcript,
                    'attachments' => [['url' => $file_url, 'type' => 'audio', 'attachment_id' => $attachment_id]],
                    'message_id' => uniqid('wcm_'),
                ],
                'client_name' => is_user_logged_in() ? wp_get_current_user()->display_name : 'Guest',
            ]);
            
            wp_send_json_success([
                'transcript' => $transcript,
                'messages' => $result['replies'] ?? [],
                'file_url' => $file_url,
                'attachment_id' => $attachment_id,
            ]);
            return;
        } else {
            wp_send_json_error(['message' => 'Không nhận diện được nội dung voice!']);
            return;
        }
    }
    
    // Process image file (optional: vision AI)
    if ($mime && strpos($mime, 'image/') === 0) {
        $reply = 'Đã nhận được ảnh của bạn. Xin chờ trong giây lát...';
        
        // Log the upload with attachment_id
        $db = BizCity_WebChat_Database::instance();
        $db->log_message([
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'message_text' => '[Ảnh: ' . basename($file_url) . ']',
            'message_from' => 'user',
            'attachments' => [['url' => $file_url, 'type' => 'image', 'attachment_id' => $attachment_id]],
        ]);
        
        wp_send_json_success([
            'reply' => $reply,
            'file_url' => $file_url,
            'attachment_id' => $attachment_id,
        ]);
        return;
    }
    
    wp_send_json_success([
        'message' => 'File đã được upload',
        'file_url' => $file_url,
        'attachment_id' => $attachment_id,
    ]);
}
} // end if function_exists bizcity_webchat_ajax_upload

/**
 * Convert audio to proper format if needed
 */
if ( ! function_exists( 'bizcity_webchat_convert_audio_if_needed' ) ) {
function bizcity_webchat_convert_audio_if_needed($file_path) {
    $mime = mime_content_type($file_path);
    $allow = ['audio/flac', 'audio/x-flac', 'audio/wav', 'audio/x-wav', 'audio/mp3', 'audio/mpeg'];
    
    if (in_array($mime, $allow)) {
        return $file_path;
    }
    
    // Try to convert using ffmpeg
    $ffmpeg = @trim(shell_exec("which ffmpeg 2>/dev/null") ?: shell_exec("where ffmpeg 2>nul"));
    if (empty($ffmpeg)) {
        return $file_path;
    }
    
    $converted_file = preg_replace('/\.(webm|mp4|ogg|aac|m4a)$/i', '.wav', $file_path);
    $cmd = "$ffmpeg -y -i " . escapeshellarg($file_path) . " -ar 16000 -ac 1 " . escapeshellarg($converted_file) . " 2>&1";
    exec($cmd, $out, $ret);
    
    if ($ret == 0 && file_exists($converted_file)) {
        return $converted_file;
    }
    
    return $file_path;
}
} // end if function_exists bizcity_webchat_convert_audio_if_needed

/**
 * Audio to text using OpenAI Whisper
 */
if ( ! function_exists( 'bizcity_webchat_audio_to_text' ) ) {
function bizcity_webchat_audio_to_text($api_key, $audio_file_path, $lang = 'vi') {
    if (!file_exists($audio_file_path)) return '';
    
    $url = "https://api.openai.com/v1/audio/transcriptions";
    $filename = basename($audio_file_path);
    $mime = mime_content_type($audio_file_path);
    if (stripos($mime, 'audio/') !== 0) $mime = 'audio/wav';
    
    $post = [
        'file' => new CURLFile($audio_file_path, $mime, $filename),
        'model' => 'whisper-1',
        'response_format' => 'json',
        'language' => $lang
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $api_key"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        bizcity_webchat_log('Whisper API error', ['http_code' => $http_code, 'response' => $result], 'error');
        return '';
    }
    
    $data = json_decode($result, true);
    return trim($data['text'] ?? '');
}
} // end if function_exists bizcity_webchat_audio_to_text

/**
 * REST API: Pull messages
 */
add_action('rest_api_init', function() {
    register_rest_route('bizcity-webchat/v1', '/pull', [
        'methods' => 'GET',
        'callback' => 'bizcity_webchat_rest_pull',
        'permission_callback' => '__return_true'
    ]);
});

if ( ! function_exists( 'bizcity_webchat_rest_pull' ) ) {
function bizcity_webchat_rest_pull(WP_REST_Request $request) {
    $last_id = intval($request->get_param('last_id'));
    $user_id = intval($request->get_param('user_id'));
    $session_id = sanitize_text_field($request->get_param('session_id'));
    
    global $wpdb;
    $table = $wpdb->prefix . 'bizcity_webchat_messages';
    
    // Check if our table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        // Fallback to bizgpt table
        $table = $wpdb->prefix . 'bizgpt_chat_history';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return new WP_REST_Response(['success' => true, 'data' => ['messages' => []]]);
        }
        
        if ($user_id) {
            $where = $wpdb->prepare("(user_id = %d OR session_id = %s) AND id > %d", $user_id, $session_id, $last_id);
        } else {
            $where = $wpdb->prepare("session_id = %s AND id > %d", $session_id, $last_id);
        }
        
        $rows = $wpdb->get_results("SELECT id, msg_text as message_text, msg_from as message_from, time FROM $table WHERE $where ORDER BY id ASC LIMIT 5");
    } else {
        if ($user_id) {
            $where = $wpdb->prepare("(user_id = %d OR session_id = %s) AND id > %d", $user_id, $session_id, $last_id);
        } else {
            $where = $wpdb->prepare("session_id = %s AND id > %d", $session_id, $last_id);
        }
        
        $rows = $wpdb->get_results("SELECT id, message_text, message_from, created_at as time FROM $table WHERE $where ORDER BY id ASC LIMIT 5");
    }
    
    $messages = [];
    foreach ($rows as $r) {
        $messages[] = [
            'id' => (int) $r->id,
            'msg' => $r->message_text,
            'from' => $r->message_from,
            'time' => $r->time
        ];
    }
    
    return new WP_REST_Response([
        'success' => true,
        'data' => ['messages' => $messages]
    ]);
}
} // end if function_exists bizcity_webchat_rest_pull

/**
 * Auto-title webchat sessions after first message exchange.
 * Uses first user message truncated to 50 chars, or goal_label if available.
 */
add_action( 'bizcity_chat_message_processed', function( $args ) {
    $session_id = $args['session_id'] ?? '';
    $user_msg   = $args['user_message'] ?? '';
    $goal       = $args['goal'] ?? '';
    $goal_label = $args['goal_label'] ?? '';

    if ( empty( $session_id ) || empty( $user_msg ) ) {
        return;
    }

    // Only process ADMINCHAT for now
    $platform_type = $args['platform_type'] ?? '';
    if ( $platform_type !== 'ADMINCHAT' ) {
        return;
    }

    if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
        return;
    }

    $wc_db = BizCity_WebChat_Database::instance();
    $row   = $wc_db->get_session_by_session_id( $session_id );

    if ( ! $row ) {
        return;
    }

    // Only update if title is still default/empty
    if ( ! empty( $row->title ) && $row->title !== 'Hội thoại mới' ) {
        return;
    }

    // Determine title: prefer goal_label, then first user message
    $title = '';
    if ( ! empty( $goal_label ) ) {
        $title = $goal_label;
    } elseif ( ! empty( $goal ) ) {
        $title = ucfirst( str_replace( '_', ' ', $goal ) );
    } else {
        // Use first user message, truncated
        $title = $user_msg;
    }

    // Truncate to 50 chars
    if ( mb_strlen( $title ) > 50 ) {
        $title = mb_substr( $title, 0, 47 ) . '...';
    }

    $wc_db->update_session_title( (int) $row->id, $title );
}, 100 );
