<?php
/**
 * Bizcity Twin AI — Admin Dashboard Chat Interface
 * Giao diện Chat Dashboard thay thế WP Dashboard mặc định
 *
 * Replace WordPress default dashboard with AI Chat interface.
 * Modern UI with AI tools, conversations, and chat.
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
        $td = 'bizcity-webchat';
        add_menu_page(
            __( 'Chat with Assistant', $td ),
            __( 'Chat', $td ),
            'read', // All logged-in users can access
            'bizcity-webchat-dashboard',
            [$this, 'render_dashboard_react'],
            BIZCITY_WEBCHAT_URL . 'assets/icon/Bell.png',
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
     * Enqueue dashboard assets (admin hook callback)
     */
    public function enqueue_assets($hook = '') {
        // When called from admin_enqueue_scripts, only load on our page
        if ($hook && $hook !== 'toplevel_page_bizcity-webchat-dashboard') {
            return;
        }
        
        $this->do_enqueue_react_assets();
    }
    
    /**
     * Enqueue all dashboard CSS & JS — callable from admin hook or frontend template
     */
    public function do_enqueue_assets() {
        static $enqueued = false;
        if ($enqueued) return;
        $enqueued = true;
        
        $assets_dir = BIZCITY_WEBCHAT_DIR . 'assets';
        $assets_url = BIZCITY_WEBCHAT_URL . 'assets';
        
        // jQuery
        wp_enqueue_script('jquery');
        
        // Dashboard CSS — versioned by file modification time
        $css_file = $assets_dir . '/css/admin-dashboard.css';
        $css_ver  = file_exists($css_file) ? filemtime($css_file) : '1.0.0';
        wp_enqueue_style(
            'bizcity-admin-dashboard',
            $assets_url . '/css/admin-dashboard.css',
            [],
            $css_ver
        );
        
        // Hide admin notices inline (tiny, must stay inline)
        wp_add_inline_style('bizcity-admin-dashboard', '
            .toplevel_page_bizcity-webchat-dashboard .notice,
            .toplevel_page_bizcity-webchat-dashboard .update-nag { display: none !important; }
        ');
        
        // Console JS (router, log panels)
        $console_file = $assets_dir . '/js/admin-dashboard-console.js';
        $console_ver  = file_exists($console_file) ? filemtime($console_file) : '1.0.0';
        wp_enqueue_script(
            'bizcity-admin-dashboard-console',
            $assets_url . '/js/admin-dashboard-console.js',
            ['jquery'],
            $console_ver,
            true
        );
        
        // Touchbar JS
        $touchbar_file = $assets_dir . '/js/admin-dashboard-touchbar.js';
        $touchbar_ver  = file_exists($touchbar_file) ? filemtime($touchbar_file) : '1.0.0';
        wp_enqueue_script(
            'bizcity-admin-dashboard-touchbar',
            $assets_url . '/js/admin-dashboard-touchbar.js',
            ['jquery', 'bizcity-admin-dashboard-console'],
            $touchbar_ver,
            true
        );
        
        // Main App JS (chat logic)
        $app_file = $assets_dir . '/js/admin-dashboard-app.js';
        $app_ver  = file_exists($app_file) ? filemtime($app_file) : '1.0.0';
        wp_enqueue_script(
            'bizcity-admin-dashboard-app',
            $assets_url . '/js/admin-dashboard-app.js',
            ['jquery', 'bizcity-admin-dashboard-console'],
            $app_ver,
            true
        );
    }
    
    /**
     * Localize JS scripts with PHP data (called from render_dashboard)
     */
    private function localize_dashboard_scripts($data) {
        wp_localize_script('bizcity-admin-dashboard-console', 'bizcDashConfig', $data);
    }

    /**
     * Localize React app with PHP data
     */
    private function localize_react_scripts($data) {
        wp_localize_script('bizcity-dashboard-react', 'bizcDashConfig', $data);
    }

    /**
     * Build the full tools catalog data for the React welcome screen.
     * Replicates the logic from page-tools-map.php.
     */
    private function build_tools_catalog() {
        // Gradient palette (same as page-tools-map.php)
        $palette = [
            ['#059669','#34D399'], ['#4F46E5','#818CF8'],
            ['#2563EB','#60A5FA'], ['#7C3AED','#A78BFA'],
            ['#DB2777','#F472B6'], ['#D97706','#FBBF24'],
            ['#DC2626','#F87171'], ['#0891B2','#22D3EE'],
            ['#9333EA','#C084FC'], ['#1D4ED8','#3B82F6'],
            ['#EA580C','#FB923C'], ['#16A34A','#4ADE80'],
        ];
        $palette_count = count($palette);
        $gradient_fn = function($slug) use ($palette, $palette_count) {
            $idx = abs(crc32($slug)) % $palette_count;
            return "linear-gradient(135deg, {$palette[$idx][0]}, {$palette[$idx][1]})";
        };

        // Load tools
        $all_tools = [];
        if (class_exists('BizCity_Intent_Tool_Index')) {
            $all_tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
        }
        if (empty($all_tools)) return ['totalTools' => 0, 'groups' => []];

        // Group by plugin
        $grouped = [];
        foreach ($all_tools as $tool) {
            $plugin = $tool['plugin'] ?: 'other';
            $grouped[$plugin][] = $tool;
        }

        // Build plugin metadata
        $plugin_meta = [];
        if (class_exists('BizCity_Market_Catalog') && method_exists('BizCity_Market_Catalog', 'get_agent_plugins_with_headers')) {
            foreach (BizCity_Market_Catalog::get_agent_plugins_with_headers() as $agent) {
                $slug = $agent['slug'] ?? '';
                if (!$slug) continue;
                $rs = $slug;
                if (preg_match('/^bizcity-agent-(.+)$/', $slug, $m)) $rs = $m[1];
                elseif (preg_match('/^bizcity-(tool-.+)$/', $slug, $m)) $rs = $m[1];
                elseif (preg_match('/^bizcity-(.+)$/', $slug, $m)) $rs = $m[1];
                elseif ($slug === 'bizcoach-map') $rs = 'bizcoach';
                $plugin_meta[$rs] = [
                    'icon'      => $agent['icon_url'] ?: '',
                    'iconIsUrl' => !empty($agent['icon_url']),
                    'name'      => $agent['name'] ?: ucfirst(str_replace(['-','_'], ' ', $rs)),
                    'category'  => $agent['category'] ?? '',
                    'gradient'  => $gradient_fn($rs),
                ];
            }
        }
        // Ensure all plugin slugs have meta
        foreach (array_keys($grouped) as $gs) {
            if (!isset($plugin_meta[$gs])) {
                $plugin_meta[$gs] = [
                    'icon'      => '',
                    'iconIsUrl' => false,
                    'name'      => ucfirst(str_replace(['-','_'], ' ', $gs)),
                    'category'  => '',
                    'gradient'  => $gradient_fn($gs),
                ];
            }
        }

        // Build output
        $groups_out = [];
        foreach ($grouped as $plugin_id => $tools) {
            $meta = $plugin_meta[$plugin_id];
            $tools_out = [];
            foreach ($tools as $t) {
                // Label
                $label = $t['goal_label'] ?? '';
                if (!$label || preg_match('/^[a-z0-9_]+$/i', $label)) {
                    $label = $t['title'] ?? '';
                    if (!$label || preg_match('/^[a-z0-9_]+$/i', $label)) {
                        $label = $t['goal_description'] ?? '';
                        if ($label) $label = mb_strimwidth($label, 0, 50, '…', 'UTF-8');
                        else $label = ucfirst(str_replace('_', ' ', $t['tool_name'] ?? 'Tool'));
                    }
                }
                // Prompt — always includes /tool_name prefix for quick intent detection
                $prompt = $t['goal_label'] ?? '';
                if (!$prompt || preg_match('/^[a-z0-9_]+$/i', $prompt)) {
                    $prompt = $t['goal_description'] ?? '';
                    if (!$prompt) $prompt = $label;
                    else $prompt = mb_strimwidth($prompt, 0, 100, '', 'UTF-8');
                }
                $tool_name = $t['tool_name'] ?? '';
                if ($tool_name && strpos($prompt, '/' . $tool_name) !== 0) {
                    $prompt = '/' . $tool_name . ' ' . $prompt;
                }
                // Slots
                $slots = [];
                $req_json = $t['required_slots'] ?? '';
                if ($req_json && $req_json !== '[]') {
                    $decoded = json_decode($req_json, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $k => $v) {
                            if (!is_numeric($k)) $slots[] = str_replace('_', ' ', $k);
                        }
                    }
                }
                // Examples
                $examples = [];
                $ex_json = $t['examples_json'] ?? '';
                if ($ex_json) {
                    $decoded = json_decode($ex_json, true);
                    if (is_array($decoded)) $examples = array_slice($decoded, 0, 3);
                }

                $tools_out[] = [
                    'toolName' => $t['tool_name'] ?? '',
                    'label'    => $label,
                    'desc'     => $t['goal_description'] ?? '',
                    'prompt'   => $prompt,
                    'slots'    => $slots,
                    'examples' => $examples,
                ];
            }
            $groups_out[] = [
                'plugin'    => $plugin_id,
                'name'      => $meta['name'],
                'icon'      => $meta['icon'],
                'iconIsUrl' => $meta['iconIsUrl'],
                'gradient'  => $meta['gradient'],
                'category'  => $meta['category'],
                'toolCount' => count($tools),
                'tools'     => $tools_out,
            ];
        }

        return [
            'totalTools' => count($all_tools),
            'groups'     => $groups_out,
        ];
    }

    /**
     * Fetch WC plan products from the shop blog via direct SQL (no WC dependency).
     *
     * @param int $shop_blog_id Blog ID where WooCommerce products live.
     * @return array
     */
    private function get_shop_plan_products($shop_blog_id) {
        if (!$shop_blog_id) return [];

        global $wpdb;
        $prefix = $wpdb->get_blog_prefix($shop_blog_id);

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_name,
                    MAX(CASE WHEN pm.meta_key = '_price'         THEN pm.meta_value END) AS price,
                    MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) AS regular_price,
                    MAX(CASE WHEN pm.meta_key = '_sale_price'    THEN pm.meta_value END) AS sale_price,
                    MAX(CASE WHEN pm.meta_key = '_bizcity_plan_id' THEN pm.meta_value END) AS plan_id,
                    MAX(CASE WHEN pm.meta_key = '_bizcity_credit'  THEN pm.meta_value END) AS credit
             FROM {$prefix}posts p
             INNER JOIN {$prefix}postmeta pm ON pm.post_id = p.ID
             WHERE p.post_type   = 'product'
               AND p.post_status = 'publish'
               AND pm.meta_key IN ('_price','_regular_price','_sale_price','_bizcity_plan_id','_bizcity_credit')
             GROUP BY p.ID
             ORDER BY p.menu_order ASC, p.ID ASC",
            ARRAY_A
        );

        if (!$rows) return [];

        $products = [];
        foreach ($rows as $r) {
            $plan_id = $r['plan_id'] ?: '';
            if (!$plan_id) {
                $title_lower = mb_strtolower($r['post_title'], 'UTF-8');
                if (strpos($title_lower, 'premium') !== false) {
                    $plan_id = 'premium';
                } elseif (strpos($title_lower, 'pro') !== false) {
                    $plan_id = 'pro';
                }
            }
            $products[] = [
                'wcId'         => (int) $r['ID'],
                'name'         => $r['post_title'],
                'slug'         => $r['post_name'],
                'price'        => (float) $r['price'],
                'regularPrice' => (float) $r['regular_price'],
                'salePrice'    => $r['sale_price'] !== null && $r['sale_price'] !== '' ? (float) $r['sale_price'] : null,
                'planId'       => $plan_id,
                'credit'       => (int) $r['credit'],
            ];
        }
        return $products;
    }

    /**
     * Enqueue the Vite-built React dashboard app (replaces legacy jQuery assets)
     */
    public function do_enqueue_react_assets() {
        static $enqueued = false;
        if ($enqueued) return;
        $enqueued = true;

        $react_dir = BIZCITY_WEBCHAT_DIR . 'assets/react';
        $react_url = BIZCITY_WEBCHAT_URL . 'assets/react';

        // CSS
        $css_file = $react_dir . '/css/bizcity-react-app.css';
        $css_ver  = file_exists($css_file) ? filemtime($css_file) : '1.0.0';
        wp_enqueue_style(
            'bizcity-dashboard-react',
            $react_url . '/css/bizcity-react-app.css',
            [],
            $css_ver
        );

        // JS (ES module)
        $js_file = $react_dir . '/js/bizcity-react-app.js';
        $js_ver  = file_exists($js_file) ? filemtime($js_file) : '1.0.0';
        wp_enqueue_script(
            'bizcity-dashboard-react',
            $react_url . '/js/bizcity-react-app.js',
            [],
            $js_ver,
            true
        );
        // Vite outputs ES modules
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'bizcity-dashboard-react') {
                $tag = str_replace(' src=', ' type="module" src=', $tag);
            }
            return $tag;
        }, 10, 2);
    }

    /**
     * Render the React-based dashboard (replacement for render_dashboard)
     *
     * Outputs only <div id="root"></div> for the React app to mount into,
     * plus the localized bizcDashConfig and TouchBar data JSON.
     *
     * @param string $theme Theme variant (passed to CSS class on root)
     */
    public function render_dashboard_react($theme = 'legacy') {
        // ── Gather same data as render_dashboard ──
        $character_id = 0;
        $character = null;
        if (class_exists('BizCity_Knowledge_Database')) {
            $db = BizCity_Knowledge_Database::instance();
            $character_id = intval(get_option('bizcity_webchat_default_character_id', 0));
            if (empty($character_id)) {
                $bot_setup = get_option('pmfacebook_options', []);
                $character_id = isset($bot_setup['default_character_id']) ? intval($bot_setup['default_character_id']) : 0;
            }
            $character = $character_id ? $db->get_character($character_id) : null;
            if (!$character) {
                $characters = $db->get_characters(['status' => 'active', 'limit' => 1]);
                if (!empty($characters)) {
                    $character    = $characters[0];
                    $character_id = $character->id;
                }
            }
        }

        $greeting_messages = [];
        if ($character && !empty($character->greeting_messages)) {
            $greeting_messages = json_decode($character->greeting_messages, true) ?: [];
        }
        $random_greeting = !empty($greeting_messages)
            ? $greeting_messages[array_rand($greeting_messages)]
            : 'Xin chào! Tôi có thể giúp gì cho bạn?';

        $char_avatar = ($character && !empty($character->avatar)) ? $character->avatar : '';
        $current_uid = get_current_user_id();
        $session_id  = 'adminchat_' . get_current_blog_id() . '_' . ($current_uid ? $current_uid : 'guest_' . md5($_SERVER['REMOTE_ADDR'] ?? ''));
        $nonce       = wp_create_nonce('bizcity_webchat');

        // User profile data
        $user_data = get_userdata($current_uid);
        $user_name   = $user_data ? $user_data->display_name : '';
        $user_avatar = $current_uid ? get_avatar_url($current_uid, ['size' => 96]) : '';
        $logout_url  = wp_logout_url(get_permalink());

        // ── Sidebar navigation items (configurable via filter) ──
        $sidebar_nav = apply_filters('bizcity_sidebar_nav', [
            ['slug' => 'explore',    'label' => __( 'Explore',                   'bizcity-webchat' ), 'icon' => '🔍', 'type' => 'link', 'src' => admin_url('admin.php?page=bizcity-marketplace')],
            ['slug' => 'tools',      'label' => __( 'Tools',                     'bizcity-webchat' ), 'icon' => '🛠️', 'type' => 'link', 'src' => home_url('tools-map/')],
            ['slug' => 'training',   'label' => __( 'Teach AI',                  'bizcity-webchat' ), 'icon' => '📖', 'type' => 'link', 'src' => home_url('note/')],
            ['slug' => 'maturity',   'label' => __( 'Maturity',                    'bizcity-webchat' ), 'icon' => '🧬', 'type' => 'link', 'src' => home_url('maturity/')],

            ['slug' => 'settings',   'label' => __( 'API Settings',          'bizcity-webchat' ), 'icon' => '⚙️', 'panel' => 'settings'],
            ['slug' => 'automation', 'label' => __( 'Automation Planner',  'bizcity-webchat' ), 'icon' => '⚡', 'type' => 'link', 'src' => admin_url('admin.php?page=bizcity-workspace&tab=workflow')],
            ['slug' => 'gateway',    'label' => __( 'Gateway',             'bizcity-webchat' ), 'icon' => '🔌', 'type' => 'link', 'src' => admin_url('admin.php?page=bizchat-gateway')],
        ]);

        // ── Welcome screen tool shortcuts (configurable via filter) ──
        $welcome_tools = apply_filters('bizcity_welcome_tools', [
            ['slug' => 'write_article',  'label' => __( 'Write Article',    'bizcity-webchat' ), 'icon' => '✍️',  'color' => '#4D6BFE', 'pluginSlug' => 'bizcity-tool-content', 'toolName' => 'write_article',    'prompt' => __( '/write_article Write an article for me', 'bizcity-webchat' )],
            ['slug' => 'gen_image',      'label' => __( 'Generate Image',   'bizcity-webchat' ), 'icon' => '🖼️',  'color' => '#FF5630', 'pluginSlug' => 'bizcity-tool-image',   'toolName' => 'generate_image',   'prompt' => __( '/generate_image Generate an image for me', 'bizcity-webchat' )],
            ['slug' => 'summarize',      'label' => __( 'Summarize',        'bizcity-webchat' ), 'icon' => '📝',  'color' => '#8E33FF', 'pluginSlug' => 'bizcity-tool-content', 'toolName' => 'summarize',        'prompt' => __( '/summarize Summarize this content for me', 'bizcity-webchat' )],
            ['slug' => 'consult',        'label' => __( 'Consult',          'bizcity-webchat' ), 'icon' => '💡',  'color' => '#FFAB00', 'pluginSlug' => 'bizcity-agent-calo',   'toolName' => 'consult',          'prompt' => __( '/consult Give me advice on', 'bizcity-webchat' )],
            ['slug' => 'order_list',     'label' => __( 'View Orders',      'bizcity-webchat' ), 'icon' => '🛒',  'color' => '#22C55E', 'pluginSlug' => 'bizcity-tool-woo',     'toolName' => 'order_list',       'prompt' => '/order_list'],
            ['slug' => 'report',         'label' => __( 'Reports',          'bizcity-webchat' ), 'icon' => '📊',  'color' => '#00B8D9', 'pluginSlug' => 'bizcity-tool-woo',     'toolName' => 'business_report',  'prompt' => __( '/business_report Generate a report', 'bizcity-webchat' )],
            ['slug' => 'mindmap',        'label' => __( 'Create Mindmap',   'bizcity-webchat' ), 'icon' => '🧠',  'color' => '#FF5630', 'pluginSlug' => 'bizcity-tool-mindmap', 'toolName' => 'create_mindmap',   'prompt' => '/create_mindmap'],
            ['slug' => 'task',           'label' => __( 'Create Reminder',  'bizcity-webchat' ), 'icon' => '📋',  'color' => '#8E33FF', 'pluginSlug' => 'bizcity-tool-slide',   'toolName' => 'create_task',      'prompt' => '/create_task'],
        ]);

        // ── Tools Catalog (full list for welcome screen) ──
        $tools_catalog = $this->build_tools_catalog();

        // ── User plan ──
        $user_plan = $current_uid ? get_user_meta($current_uid, '_bizcity_plan', true) : '';
        if (!$user_plan) $user_plan = 'free';

        // ── Shop products from blog 1065 ──
        $shop_blog_id   = (int) get_site_option('bizcity_shop_blog_id', 0);
        $shop_products  = $this->get_shop_plan_products($shop_blog_id);

        // myAccountUrl from shop blog so iframe shows WC orders
        $my_account_url = home_url('/my-account/');
        if ($shop_blog_id) {
            switch_to_blog($shop_blog_id);
            $my_account_url = function_exists('wc_get_page_permalink')
                ? wc_get_page_permalink('myaccount')
                : home_url('/my-account/');
            restore_current_blog();
        }

        // Localize config for React
        // Load KCI ratio for current session
        $kci_ratio_val = 80;
        if ( class_exists( 'BizCity_WebChat_Database' ) && $session_id ) {
            $sess_obj = BizCity_WebChat_Database::instance()->get_session_v3_by_session_id( $session_id );
            if ( $sess_obj && isset( $sess_obj->kci_ratio ) ) {
                $kci_ratio_val = (int) $sess_obj->kci_ratio;
            }
        }

        $this->localize_react_scripts([
            'ajaxurl'      => admin_url('admin-ajax.php'),
            'sessionId'    => $session_id,
            'nonce'        => $nonce,
            'chatNonce'    => wp_create_nonce('bizcity_chat'),
            'isGuest'      => !$current_uid,
            'botAvatar'    => $char_avatar,
            'restUrl'      => rest_url('bizcity-chat/v1/'),
            'wpRestNonce'  => wp_create_nonce('wp_rest'),
            'greeting'     => $random_greeting,
            'tasksUrl'     => home_url('/tasks/'),
            'loginUrl'     => wp_login_url(get_permalink()),
            'logoutUrl'    => $logout_url,
            'characterId'  => intval($character->id ?? 0),
            'userName'     => $user_name,
            'userAvatar'   => $user_avatar,
            'profileUrl'   => admin_url('profile.php'),
            'myAccountUrl' => $my_account_url,
            'sidebarNav'    => $sidebar_nav,
            'welcomeTools'  => $welcome_tools,
            'toolsCatalog'  => $tools_catalog,
            'userPlan'      => $user_plan,
            'blogName'      => get_bloginfo('name') ?: 'AI Chat',
            'paypalClientId'     => get_site_option('bizcity_paypal_client_id', ''),
            'fxUsdVnd'           => (int) get_site_option('bizcity_wallet_fx_usd_vnd', 25000),
            'shopBlogId'         => $shop_blog_id,
            'shopProducts'       => $shop_products,
            'ssoGoogleUrl'       => site_url('?auth=sso'),
            'ssoBizcityUrl'      => site_url('?auth=sso&provider=bizcity'),
            'locale'             => get_locale(),
            'kciRatio'           => $kci_ratio_val,
        ]);

        // TouchBar agent data (same as legacy)
        $agent_plugins = [];
        if (class_exists('BizCity_Market_Catalog') && method_exists('BizCity_Market_Catalog', 'get_agent_plugins_with_headers')) {
            $agent_plugins = BizCity_Market_Catalog::get_agent_plugins_with_headers();
        }

        $tb_core = [
            ['slug' => 'chat',       'label' => 'Chat',     'icon' => '💬', 'type' => 'chat'],
           // ['slug' => 'tools',      'label' => 'Tools',    'icon' => '🛠️', 'type' => 'link', 'src' => home_url('tools-map/?bizcity_iframe=1')],
           // ['slug' => 'knowledge',  'label' => 'KB',       'icon' => '📚', 'type' => 'link', 'src' => admin_url('admin.php?page=bizcity-knowledge')],
           // ['slug' => 'market',     'label' => 'Market',   'icon' => '🏪', 'type' => 'link', 'src' => admin_url('admin.php?page=bizcity-marketplace')],
        ];
        $tb_agents = [];
        foreach ($agent_plugins as $ap) {
            $tb_agents[] = [
                'slug'  => $ap['slug'] ?? '',
                'label' => $ap['name'] ?? $ap['slug'] ?? '',
                'icon'  => $ap['icon_url'] ?? '🤖',
                'type'  => 'agent',
                'src'   => $ap['template_url'] ?? '',
                'title' => $ap['name'] ?? '',
            ];
        }
        $tb_data = wp_json_encode(['core' => $tb_core, 'agents' => $tb_agents]);
        ?>
        <style>
            .bizc-touchbar-wrap {border-radius: 0px !important;}
            .hdr-tb-item { min-width: 40px !important; padding:0px 10px !important; line-height: unset !important; margin:0px 5px !important;}
            .bizc-msg.bot .bizc-msg-bubble {background: #ffffff !important; color: #1a1a2e !important;}
            .pf-chip  {font-size: 12px !important; border: 1px solid #e5e7eb !important; padding: 6px 12px !important;}
            .tc-tab {font-size: 12px !important; }
            #adminmenu .wp-menu-image img {padding: 9px 12px 0 !important;     opacity: .9 !important; max-width: inherit !important;}
            .tc-tab.active {color: var(--color-n30) !important; border-color: var(--color-primary) !important;  }
            .tc-search-input {padding: 10px 14px 10px 38px !important}
            .bizc-msg-slash { color: var(--color-n30) !important; font-size: 12px !important; font-style: italic !important; }
            /* ── Working indicator — force display on multisite hub ── */
            #root .bizc-working[data-bizc-working="1"] { display: block !important; visibility: visible !important; opacity: 1 !important; margin-bottom: 16px !important; width: min(560px,100%) !important; max-width: 100% !important; z-index: 6 !important; isolation: isolate !important; }
            #root .bizc-working[data-bizc-working="1"] .bizc-working-bubble { display: flex !important; flex-direction: column !important; gap: 4px !important; padding: 12px 16px !important; background: #f3f4f6 !important; border-radius: 12px !important; max-width: 100% !important; width: 100% !important; font-size: 13px !important; line-height: 1.5 !important; border: 1px solid #e5e7eb !important; }
            #root .bizc-working-bubble.compact { padding: 8px 14px !important; opacity: .7 !important; font-size: 12px !important; }
            #root .bizc-working[data-bizc-working="1"] .bizc-ws { display: flex !important; align-items: flex-start !important; gap: 8px !important; padding: 4px 0 !important; color: #6b7280 !important; }
            #root .bizc-ws.done { opacity: 0.5 !important; }
            #root .bizc-ws.active { color: #4f46e5 !important; font-weight: 500 !important; }
            #root .bizc-ws-icon { width: 16px !important; height: 16px !important; display: flex !important; align-items: center !important; justify-content: center !important; font-size: 11px !important; flex-shrink: 0 !important; }
            #root .bizc-ws.done .bizc-ws-icon { color: #22c55e !important; }
            #root .bizc-ws.active .bizc-ws-icon::after { content: '' !important; width: 6px !important; height: 6px !important; border-radius: 50% !important; background: #6366f1 !important; display: block !important; }
            #root .bizc-working[data-bizc-working="1"] .bizc-ws-main { flex: 1 !important; min-width: 0 !important; display: flex !important; flex-direction: column !important; gap: 1px !important; }
            #root .bizc-ws-text { flex: 1 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; font-weight: 600 !important; }
            #root .bizc-ws-detail { display: block !important; font-size: 11px !important; color: #6b7280 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; }
            #root .bizc-ws-stage { display: inline-flex !important; align-self: flex-start !important; font-size: 10px !important; line-height: 1.3 !important; color: #4f46e5 !important; background: #eef2ff !important; border: 1px solid #c7d2fe !important; border-radius: 10px !important; padding: 1px 7px !important; }
            #root .bizc-ws-ms { font-size: 10px !important; color: #9ca3af !important; flex-shrink: 0 !important; }
        </style>    
        <!-- TouchBar data for React -->
        <script id="bizc-tb-data" type="application/json"><?php echo $tb_data; ?></script>

        <!-- React app mount point -->
        <div id="root"></div>

        <!-- Twin Core Setting (Execution Intent) Slider — injected into React sidebar -->
        <style>
        .bizc-kci-sidebar {
            padding: 0 0 8px;
            border-bottom: 1px solid rgba(128,128,128,0.12);
            margin-bottom: 4px;
        }
        .bizc-kci-sidebar .bizc-kci-head {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }
        .bizc-kci-sidebar .bizc-kci-head span {
            font-size: 11px;
            color: var(--color-n300, #9ca3af);
        }
        .bizc-kci-sidebar .bizc-kci-head .bizc-kci-vals {
            margin-left: auto;
            font-size: 10px;
            color: var(--color-n200, #6b7280);
        }
        .bizc-kci-sidebar input[type=range] {
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            height: 3px;
            border-radius: 2px;
            background: linear-gradient(90deg, #6366f1 0%, #a855f7 50%, #f59e0b 100%);
            outline: none;
            cursor: pointer;
        }
        .bizc-kci-sidebar input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 12px; height: 12px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
            cursor: pointer;
        }
        .bizc-kci-sidebar input[type=range]::-moz-range-thumb {
            width: 12px; height: 12px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
            cursor: pointer;
            border: none;
        }
        .bizc-kci-sidebar .bizc-kci-presets {
            display: flex;
            gap: 4px;
            margin-top: 6px;
            flex-wrap: wrap;
        }
        .bizc-kci-sidebar .bizc-kci-pre {
            font-size: 10px;
            padding: 1px 6px;
            border-radius: 9px;
            border: 1px solid rgba(128,128,128,0.15);
            background: transparent;
            color: var(--color-n300, #9ca3af);
            cursor: pointer;
            transition: all 0.15s;
            line-height: 1.6;
        }
        .bizc-kci-sidebar .bizc-kci-pre:hover {
            border-color: rgba(99,102,241,0.4);
            color: var(--color-n200, #a5b4fc);
        }
        .bizc-kci-sidebar .bizc-kci-pre.active {
            background: rgba(99,102,241,0.15);
            border-color: rgba(99,102,241,0.4);
            color: #a5b4fc;
        }
        .bizc-kci-sidebar .bizc-kci-nuoi {
            margin-left: auto;
            border-color: rgba(34,197,94,0.25);
            color: rgba(34,197,94,0.6);
        }
        .bizc-kci-sidebar .bizc-kci-nuoi:hover {
            background: rgba(34,197,94,0.1);
            color: #4ade80;
        }
        </style>
        <script>
        (function() {
            var _cfg = null;
            function cfg() { if (!_cfg) _cfg = typeof bizcDashConfig !== 'undefined' ? bizcDashConfig : {}; return _cfg; }
            var kci = <?php echo intval($kci_ratio_val); ?>;
            var timer = null;

            function buildKciHtml(val) {
                var exec = 100 - val;
                return '<div class="bizc-kci-sidebar">' +
                    '<div class="bizc-kci-head">' +
                        '<span>📊 Twin Core</span>' +
                        '<span class="bizc-kci-vals">Knowledge:<b id="bizc-kci-k">' + val + '</b>% Execution:<b id="bizc-kci-e">' + exec + '</b>%</span>' +
                    '</div>' +
                    '<input type="range" id="bizc-kci-range" min="0" max="100" step="10" value="' + val + '">' +
                    '' +
                    '<div class="bizc-kci-presets">' +
                        '<button class="bizc-kci-pre' + (val===100?' active':'') + '" data-v="100">📚100</button>' +
                        '<button class="bizc-kci-pre' + (val===80?' active':'') + '" data-v="80">🧠80</button>' +
                        '<button class="bizc-kci-pre' + (val===50?' active':'') + '" data-v="50">⚖️50</button>' +
                        '<button class="bizc-kci-pre' + (val===20?' active':'') + '" data-v="20">🚀20</button>' +
                        '<button class="bizc-kci-pre bizc-kci-nuoi" title="Teach AI (Nuôi dậy AI)">🌱 Teach AI</button>' +
                    '</div>' +
                '</div>';
            }

            function saveKci(val) {
                var c = cfg();
                console.log('[KCI-TRACE] dash_save:', { value: val, exec: 100 - val, sessionId: c.sessionId });
                var x = new XMLHttpRequest();
                x.open('POST', c.ajaxurl || '/wp-admin/admin-ajax.php');
                x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                x.onload = function() { console.log('[KCI-TRACE] dash_response:', x.responseText); };
                x.send('action=bizcity_chat_set_kci_ratio&session_id=' + encodeURIComponent(c.sessionId || '') +
                       '&kci_ratio=' + val + '&_wpnonce=' + encodeURIComponent(c.chatNonce || c.nonce || ''));
            }

            function updateLabels(val) {
                var exec = 100 - val;
                var kEl = document.getElementById('bizc-kci-k');
                var eEl = document.getElementById('bizc-kci-e');
                if (kEl) kEl.textContent = val;
                if (eEl) eEl.textContent = exec;
                var sEl = document.getElementById('bizc-kci-status');
                if (sEl) sEl.textContent = 'Priority: Knowledge: ' + val + '%, Execution: ' + exec + '%';
                var btns = document.querySelectorAll('.bizc-kci-pre[data-v]');
                btns.forEach(function(b) {
                    b.classList.toggle('active', parseInt(b.getAttribute('data-v')) === val);
                });
            }

            function bindEvents() {
                var range = document.getElementById('bizc-kci-range');
                if (!range) return;
                range.addEventListener('input', function() {
                    var v = parseInt(this.value);
                    updateLabels(v);
                    clearTimeout(timer);
                    timer = setTimeout(function() { saveKci(v); }, 500);
                });
                document.querySelectorAll('.bizc-kci-pre[data-v]').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var v = parseInt(this.getAttribute('data-v'));
                        range.value = v;
                        updateLabels(v);
                        clearTimeout(timer);
                        saveKci(v);
                    });
                });
                document.querySelector('.bizc-kci-nuoi')?.addEventListener('click', function() {
                    var c = cfg();
                    window.open(c.tasksUrl ? c.tasksUrl.replace('/tasks/', '/note/') : '/note/', '_blank');
                });
            }

            function inject(sidebarCol) {
                if (document.getElementById('bizc-kci-range')) return; // already injected
                var navDiv = sidebarCol.querySelector('.flex.flex-col.pt-5, .flex.flex-col.pt-8');
                if (!navDiv) {
                    // Fallback: find the flex-col with New chat text
                    var allDivs = sidebarCol.querySelectorAll('.flex.flex-col');
                    for (var i = 0; i < allDivs.length; i++) {
                        if (allDivs[i].textContent.indexOf('New chat') !== -1) {
                            navDiv = allDivs[i]; break;
                        }
                    }
                }
                if (!navDiv) return;
                var wrapper = document.createElement('div');
                wrapper.innerHTML = buildKciHtml(kci);
                navDiv.insertBefore(wrapper.firstChild, navDiv.firstChild);
                bindEvents();
            }

            // Observe DOM for React sidebar render
            var obs = new MutationObserver(function(mutations) {
                var sidebar = document.querySelector('[class*="flex"][class*="flex-col"][class*="pt-5"], [class*="flex"][class*="flex-col"][class*="pt-8"]');
                if (!sidebar) return;
                // Look for "New chat" text to confirm this is the nav sidebar
                if (sidebar.textContent.indexOf('New chat') === -1) return;
                inject(sidebar.parentElement || sidebar);
            });
            var root = document.getElementById('root');
            if (root) {
                obs.observe(root, { childList: true, subtree: true });
                // Also try immediate inject (in case React already rendered)
                setTimeout(function() {
                    var sidebar = document.querySelector('[class*="flex"][class*="flex-col"][class*="pt-5"], [class*="flex"][class*="flex-col"][class*="pt-8"]');
                    if (sidebar && sidebar.textContent.indexOf('New chat') !== -1) {
                        inject(sidebar.parentElement || sidebar);
                    }
                }, 1500);
            }
        })();
        </script>
        <?php
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
        
        // Pass PHP variables to JS via wp_localize_script
        $this->localize_dashboard_scripts([
            'ajaxurl'     => admin_url('admin-ajax.php'),
            'sessionId'   => $session_id,
            'nonce'       => $nonce,
            'chatNonce'   => wp_create_nonce('bizcity_chat'),
            'isGuest'     => ! $current_uid,
            'botAvatar'   => $char_avatar ?: '',
            'restUrl'     => rest_url('bizcity-chat/v1/'),
            'wpRestNonce' => wp_create_nonce('wp_rest'),
            'greeting'    => $random_greeting,
            'tasksUrl'    => home_url('/tasks/'),
            'loginUrl'    => wp_login_url( get_permalink() ),
            'characterId' => intval( $character->id ?? 0 ),
        ]);
        
        ?>
            
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
                        <span id="bizc-sessions-view-all" style="color:#3b82f6;cursor:pointer;font-size:11px;" data-url="<?php echo esc_url( home_url( '/chat-sessions/' ) ); ?>" title="Xem toàn bộ phiên chat">Xem chi tiết →</span>
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
                        <span id="bizc-intent-view-all" style="color:#3b82f6;cursor:pointer;font-size:11px;" data-url="<?php echo esc_url( home_url( '/tasks/' ) ); ?>" title="Xem toàn bộ nhiệm vụ">Xem chi tiết →</span>
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

                
                <!-- Drag resize handle -->
                <div class="bizc-resize-handle" id="bizc-resize-handle" title="Kéo để thay đổi chiều cao console"></div>
               

                <!-- Touch Bar — iPhone App Drawer Style (Virtual Render) -->
                <!-- Agent plugins data for lazy/virtual rendering -->
                <script id="bizc-tb-data" type="application/json">
                <?php
                // Core items (always rendered)
                $core_items = [];
                $core_items[] = ['type' => 'chat', 'slug' => 'chat', 'icon' => '💬', 'label' => __( 'Chat', 'bizcity-webchat' ), 'src' => '', 'title' => __( 'Back to Chat', 'bizcity-webchat' )];
                $core_items[] = ['type' => 'link', 'slug' => 'tools-map', 'icon' => '🧰', 'label' => __( 'AI Tools', 'bizcity-webchat' ), 'src' => home_url('/tools-map/'), 'title' => __( 'AI Tools List', 'bizcity-webchat' )];
                
                if (current_user_can('manage_options')) {
                    $core_items[] = ['type' => 'link', 'slug' => 'control-panel', 'icon' => '🎛️', 'label' => __( 'Control Panel', 'bizcity-webchat' ), 'src' => home_url('/tool-control-panel/'), 'title' => __( 'Configure Tool Routing', 'bizcity-webchat' )];
                    $core_items[] = ['type' => 'link', 'slug' => 'profile', 'icon' => '🌟', 'label' => __( 'Profile', 'bizcity-webchat' ), 'src' => admin_url('admin.php?page=bccm_my_profile'), 'title' => __( 'Set Profile', 'bizcity-webchat' )];
                    $core_items[] = ['type' => 'link', 'slug' => 'knowledge', 'icon' => '📚', 'label' => __( 'Knowledge', 'bizcity-webchat' ), 'src' => admin_url('admin.php?page=bizcity-knowledge-characters'), 'title' => __( 'Set Knowledge', 'bizcity-webchat' )];
                    $core_items[] = ['type' => 'link', 'slug' => 'marketplace', 'icon' => '🏪', 'label' => __( 'AI Market', 'bizcity-webchat' ), 'src' => admin_url('index.php?page=bizcity-marketplace'), 'title' => __( 'AI Agent Market', 'bizcity-webchat' )];
                    
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


                <!-- Touchbar: Virtual Rendering + pagination + resize handle logic -->
                
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
                        <!-- Agent / Tool badge (positioned above input) -->
                        <span class="bizc-mention-tag" id="bizc-mention-tag" style="display:none;"></span>
                        <!-- Tool pill inside input row -->
                        <span class="bizc-tool-pill" id="bizc-tool-pill" style="display:none;"></span>
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
                        <button id="bizc-agent-back" style="margin:5px !important; margin-left: 10px;width:36px;height:36px;border-radius:50%;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.3);cursor:pointer;font-size:15px;color:#6366f1;display:flex;align-items:center;justify-content:center;transition:all 0.2s;flex-shrink:0;" title="Quay lại chat" onmouseover="this.style.background='rgba(99,102,241,0.2)'; this.style.borderColor='rgba(99,102,241,0.5)';" onmouseout="this.style.background='rgba(99,102,241,0.1)'; this.style.borderColor='rgba(99,102,241,0.3)';">←</button>
                        <img id="bizc-agent-icon" src="" alt="" style="width:24px;height:24px;border-radius:6px;object-fit:cover;display:none;">
                        <span id="bizc-agent-title" style="font-weight:600;font-size:14px;color:#1a1a2e;flex:1;"></span>
                        <button id="bizc-agent-external" style="margin:5px !important;margin-right:10px;width:36px;height:36px;border-radius:50%;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.3);cursor:pointer;font-size:15px;color:#6366f1;display:flex;align-items:center;justify-content:center;transition:all 0.2s;flex-shrink:0;" title="Mở tab mới" onmouseover="this.style.background='rgba(99,102,241,0.2)'; this.style.borderColor='rgba(99,102,241,0.5)';" onmouseout="this.style.background='rgba(99,102,241,0.1)'; this.style.borderColor='rgba(99,102,241,0.3)';">↗</button>
                    </div>
                    <iframe id="bizc-agent-iframe" src="about:blank" style="flex:1;border:none;width:100%;"></iframe>
                </div>
                
            </div>
        </div>
        <?php
    }

}
