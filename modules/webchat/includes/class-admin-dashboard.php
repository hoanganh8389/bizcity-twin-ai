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

        // Cache invalidation — tools catalog
        add_action('bizcity_tool_registry_changed', [__CLASS__, 'invalidate_tools_catalog_cache']);
        add_action('activated_plugin',              [__CLASS__, 'invalidate_tools_catalog_cache']);
        add_action('deactivated_plugin',            [__CLASS__, 'invalidate_tools_catalog_cache']);

        // Skip wp_user_settings DB writes on React dashboard (no WP screen options used)
        add_filter('update_user_metadata', [$this, 'skip_user_settings_on_dashboard'], 10, 4);
    }

    /**
     * Short-circuit user-settings meta updates on BizCity dashboard.
     *
     * wp_user_settings() in admin-header.php always updates user-settings-time
     * with time(), causing 2 SELECT + 2 UPDATE queries per admin page load.
     * The React SPA dashboard never uses WP screen options, so skip them.
     *
     * @param null|bool $check  Return non-null to short-circuit.
     * @param int       $object_id  User ID.
     * @param string    $meta_key   Meta key being updated.
     * @param mixed     $meta_value Meta value.
     * @return null|bool
     */
    public function skip_user_settings_on_dashboard($check, $object_id, $meta_key, $meta_value) {
        if (isset($_GET['page']) && $_GET['page'] === 'bizcity-webchat-dashboard') {
            if (preg_match('/^wp_\d+_user-settings/', $meta_key)) {
                return true; // pretend update succeeded — skips SELECT + UPDATE
            }
        }
        return $check;
    }

    /**
     * Invalidate transient caches for tools catalog + agent plugins headers.
     */
    public static function invalidate_tools_catalog_cache() {
        delete_transient('bizcity_tools_catalog');
        delete_transient('bizcity_agent_plugins_headers');
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
        $td = 'bizcity-twin-ai';
        add_menu_page(
            __( 'Chat với Trợ lý', $td ),
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

        // Pipeline Working Panel (step-by-step execution UI for chat)
        $pipeline_js = defined( 'BIZCITY_INTENT_DIR' )
            ? BIZCITY_INTENT_DIR . '/assets/js/pipeline-working-panel.js' : '';
        if ( $pipeline_js && file_exists( $pipeline_js ) ) {
            $pipeline_url = defined( 'BIZCITY_INTENT_URL' )
                ? BIZCITY_INTENT_URL . 'assets/js/pipeline-working-panel.js' : '';
            wp_enqueue_script(
                'bizc-pipeline-working-panel',
                $pipeline_url,
                [ 'jquery', 'bizcity-admin-dashboard-app' ],
                filemtime( $pipeline_js ),
                true
            );
            wp_localize_script( 'bizc-pipeline-working-panel', 'BIZC_PIPELINE', [
                'nonce' => wp_create_nonce( 'bizc_pipeline_nonce' ),
            ] );

            $pipeline_css = BIZCITY_INTENT_DIR . '/assets/css/pipeline-working-panel.css';
            if ( file_exists( $pipeline_css ) ) {
                wp_enqueue_style(
                    'bizc-pipeline-working-panel',
                    BIZCITY_INTENT_URL . 'assets/css/pipeline-working-panel.css',
                    [],
                    filemtime( $pipeline_css )
                );
            }
        }

        /* ── Pipeline Monitor Sidebar (Phase 1.2 — SSE-based real-time) ── */
        $monitor_js = defined( 'BIZCITY_INTENT_DIR' )
            ? BIZCITY_INTENT_DIR . '/assets/js/pipeline-monitor-sidebar.js' : '';
        if ( $monitor_js && file_exists( $monitor_js ) ) {
            $monitor_url = BIZCITY_INTENT_URL . 'assets/js/pipeline-monitor-sidebar.js';
            wp_enqueue_script(
                'bizc-pipeline-monitor-sidebar',
                $monitor_url,
                [ 'jquery', 'bizcity-admin-dashboard-app' ],
                filemtime( $monitor_js ),
                true
            );
            wp_localize_script( 'bizc-pipeline-monitor-sidebar', 'BIZC_PIPELINE_MONITOR', [
                'nonce' => wp_create_nonce( 'bizc_pipeline_nonce' ),
            ] );

            $monitor_css = BIZCITY_INTENT_DIR . '/assets/css/pipeline-monitor-sidebar.css';
            if ( file_exists( $monitor_css ) ) {
                wp_enqueue_style(
                    'bizc-pipeline-monitor-sidebar',
                    BIZCITY_INTENT_URL . 'assets/css/pipeline-monitor-sidebar.css',
                    [],
                    filemtime( $monitor_css )
                );
            }
        }
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
     * Detect current frontend language code from Transposh URL structure.
     * Falls back to the site default language when no language slug is present.
     */
    private function get_current_frontend_lang_code() {
        $default_lang = 'vi';

        if ( defined( 'TRANSPOSH_OPTIONS' ) ) {
            $tp_options = get_option( TRANSPOSH_OPTIONS, [] );
            if ( ! empty( $tp_options['default_language'] ) ) {
                $default_lang = (string) $tp_options['default_language'];
            }
        }

        $tp_utils = WP_PLUGIN_DIR . '/transposh-translation-filter-for-wordpress/core/utils.php';
        $tp_const = WP_PLUGIN_DIR . '/transposh-translation-filter-for-wordpress/core/constants.php';
        if ( file_exists( $tp_utils ) && file_exists( $tp_const ) && ! class_exists( 'transposh_consts' ) ) {
            include_once $tp_utils;
            include_once $tp_const;
        }

        if ( class_exists( 'transposh_utils' ) && isset( $_SERVER['REQUEST_URI'] ) ) {
            $proto = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https://' : 'http://';
            $lang  = transposh_utils::get_language_from_url(
                sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ),
                $proto . ( $_SERVER['SERVER_NAME'] ?? '' )
            );
            if ( ! empty( $lang ) ) {
                return (string) $lang;
            }
        }

        return $default_lang;
    }

    /**
     * Map a short language code from the URL to a WordPress locale.
     */
    private function map_lang_code_to_locale( $lang_code ) {
        $lang_code = strtolower( (string) $lang_code );

        $known = [
            'vi' => 'vi_VN',
            'en' => 'en_US',
            'ja' => 'ja',
            'ko' => 'ko_KR',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'es' => 'es_ES',
            'it' => 'it_IT',
            'pt' => 'pt_PT',
            'ru' => 'ru_RU',
            'zh' => 'zh_CN',
        ];

        if ( isset( $known[ $lang_code ] ) ) {
            return $known[ $lang_code ];
        }

        if ( preg_match( '/^[a-z]{2}$/', $lang_code ) ) {
            return $lang_code . '_' . strtoupper( $lang_code );
        }

        return function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
    }

    /**
     * Load a specific MO file into the BizCity Twin AI text domain.
     */
    private function load_bizcity_textdomain_locale( $locale ) {
        $locale = (string) $locale;
        if ( $locale === '' ) {
            return false;
        }

        $mofile = BIZCITY_TWIN_AI_DIR . 'languages/bizcity-twin-ai-' . $locale . '.mo';
        if ( ! file_exists( $mofile ) ) {
            return false;
        }

        unload_textdomain( 'bizcity-twin-ai' );
        return load_textdomain( 'bizcity-twin-ai', $mofile );
    }

    /**
     * Build a runtime translation map for React using Vietnamese source strings.
     * This lets /en/chat/ and future /xx/chat/ routes reuse WordPress .po/.mo files.
     */
    private function build_react_i18n_map() {
        $source_strings = [
            'Mở Dấu vết Biz',
            'Dấu vết Biz',
            'TRỰC TIẾP',
            'Chưa có log nào',
            'Gửi tin nhắn để xem quá trình AI xử lý',
            '{count} bước',
            'Chat mới',
            'Dự án',
            'Tên dự án...',
            'Đăng nhập',
            'Tài khoản của tôi',
            'Cài đặt',
            'Chat lưu trữ',
            'Nâng cấp gói',
            'Đăng xuất',
            'Chưa đặt tên',
            'Không có chat đã lưu trữ.',
            'Chuyển giao diện sáng tối',
            'Nhập tin nhắn... @ chọn trợ lý · / tìm công cụ',
            'Chọn trợ lý',
            'Chọn công cụ',
            'Đính kèm ảnh',
            'Lõi đôi: T{k}% H{e}%',
            'Chế độ: {mode}',
            'tự động',
            'công cụ',
            'Công cụ',
            'Trợ lý: {name}',
        ];

        $original_locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        $current_lang    = $this->get_current_frontend_lang_code();
        $target_locale   = $this->map_lang_code_to_locale( $current_lang );

        if ( $target_locale && $target_locale !== $original_locale ) {
            $this->load_bizcity_textdomain_locale( $target_locale );
        }

        $translations = [];
        foreach ( $source_strings as $string ) {
            $translations[ $string ] = __( $string, 'bizcity-twin-ai' );
        }

        if ( $target_locale && $target_locale !== $original_locale ) {
            $this->load_bizcity_textdomain_locale( $original_locale );
        }

        return [
            'lang'         => $current_lang,
            'locale'       => $target_locale,
            'translations' => $translations,
        ];
    }

    /**
     * Build the full tools catalog data for the React welcome screen.
     * Replicates the logic from page-tools-map.php.
     */
    private function build_tools_catalog() {
        // ── Transient cache (invalidated by bizcity_tool_registry_changed) ──
        $cache_key = 'bizcity_tools_catalog';
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

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
                // Prompt — always includes @tool_name prefix for quick intent detection
                $prompt = $t['goal_label'] ?? '';
                if (!$prompt || preg_match('/^[a-z0-9_]+$/i', $prompt)) {
                    $prompt = $t['goal_description'] ?? '';
                    if (!$prompt) $prompt = $label;
                    else $prompt = mb_strimwidth($prompt, 0, 100, '', 'UTF-8');
                }
                $tool_name = $t['tool_name'] ?? '';
                if ($tool_name && strpos($prompt, '@' . $tool_name) !== 0) {
                    $prompt = '@' . $tool_name . ' ' . $prompt;
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
                'type'      => $plugin_id === 'builtin' ? 'atomic' : 'composite',
                'toolCount' => count($tools),
                'tools'     => $tools_out,
            ];
        }

        // ── S2.7: Supplement with BizCity_Tool_Registry at_enabled tools ──
        // Any tool registered in the unified registry with at_enabled=true that is
        // NOT already represented in the Intent_Tool_Index catalog gets its own
        // "BizCity Built-in" group so it appears in the @ mention dialog.
        if ( class_exists('BizCity_Tool_Registry') ) {
            // Collect tool names already in the catalog
            $catalog_tool_names = [];
            foreach ($groups_out as $g) {
                foreach ($g['tools'] as $t) {
                    $catalog_tool_names[] = $t['toolName'];
                }
            }
            $at_tools = BizCity_Tool_Registry::get_at_tools();
            $registry_extras = [];
            foreach ($at_tools as $slug => $tool) {
                if (in_array($slug, $catalog_tool_names, true)) continue;
                if (empty($tool['available'])) continue;
                $label  = $tool['label'] ?? ucwords(str_replace('_', ' ', $slug));
                $prompt = '@' . $slug . ' ' . ($tool['description'] ?? '');
                // input_fields → slots
                $slots = [];
                if (!empty($tool['input_fields']) && is_array($tool['input_fields'])) {
                    foreach ($tool['input_fields'] as $fk => $fv) {
                        if (!is_numeric($fk)) $slots[] = str_replace('_', ' ', $fk);
                    }
                }
                $registry_extras[] = [
                    'toolName' => $slug,
                    'label'    => $label,
                    'desc'     => $tool['description'] ?? '',
                    'prompt'   => trim($prompt),
                    'slots'    => $slots,
                    'examples' => [],
                ];
            }
            if (!empty($registry_extras)) {
                $idx = abs(crc32('bizcity-registry')) % $palette_count;
                $groups_out[] = [
                    'plugin'    => 'bizcity-registry',
                    'name'      => 'BizCity Registry',
                    'icon'      => '🔧',
                    'iconIsUrl' => false,
                    'gradient'  => "linear-gradient(135deg, {$palette[$idx][0]}, {$palette[$idx][1]})",
                    'category'  => 'builtin',
                    'type'      => 'atomic',
                    'toolCount' => count($registry_extras),
                    'tools'     => $registry_extras,
                ];
            }
        }

        $total = 0;
        foreach ($groups_out as $g) { $total += count($g['tools']); }

        $result = [
            'totalTools' => $total,
            'groups'     => $groups_out,
        ];

        // Persist for 1 hour — explicit invalidation via bizcity_tool_registry_changed
        set_transient($cache_key, $result, HOUR_IN_SECONDS);

        return $result;
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
     * Build language flags data by reading Transposh options directly.
     *
     * Avoids do_shortcode('[lsft_horizontal_flags]') which conflicts with React.
     * Returns array of { code, flag, name, url } for each viewable language.
     *
     * @return array
     */
    private function build_language_flags_data() {
        // Require Transposh constants
        $tp_utils = WP_PLUGIN_DIR . '/transposh-translation-filter-for-wordpress/core/utils.php';
        $tp_const = WP_PLUGIN_DIR . '/transposh-translation-filter-for-wordpress/core/constants.php';
        if ( ! file_exists( $tp_utils ) || ! file_exists( $tp_const ) ) {
            return [];
        }
        if ( ! class_exists( 'transposh_consts' ) ) {
            include_once $tp_utils;
            include_once $tp_const;
        }
        if ( ! defined( 'TRANSPOSH_OPTIONS' ) || ! class_exists( 'transposh_consts' ) ) {
            return [];
        }

        $tp_options = get_option( TRANSPOSH_OPTIONS );
        if ( empty( $tp_options['viewable_languages'] ) ) {
            return [];
        }

        $default_lang = $tp_options['default_language'] ?? 'en';
        $languages    = explode( ',', $tp_options['viewable_languages'] );
        if ( ! in_array( $default_lang, $languages, true ) ) {
            array_unshift( $languages, $default_lang );
        }
        if ( count( $languages ) < 2 ) {
            return [];
        }

        // LSFT options
        $lsft_opts = get_option( 'cfxlsft_options', [] );
        $use_orig  = ( $lsft_opts['original_lang_names'] ?? '' ) === 'on';

        // Flag path
        $flag_base = plugins_url( 'language-switcher-for-transposh/assets/flags' );
        if ( ( $lsft_opts['flag_type'] ?? '' ) === 'tp' && defined( 'TRANSPOSH_DIR_IMG' ) ) {
            $flag_base = plugins_url( 'transposh-translation-filter-for-wordpress/' . TRANSPOSH_DIR_IMG . '/flags' );
        }

        // English flag override
        $en_flag = 'gb';
        if ( ( $lsft_opts['usa_flag'] ?? '' ) === 'on' ) {
            $en_flag = 'us';
        }

        // Current lang from URL
        $current_lang = '';
        if ( class_exists( 'transposh_utils' ) && isset( $_SERVER['REQUEST_URI'] ) ) {
            $proto = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https://' : 'http://';
            $current_lang = transposh_utils::get_language_from_url(
                sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ),
                $proto . ( $_SERVER['SERVER_NAME'] ?? '' )
            );
        }
        if ( empty( $current_lang ) ) {
            $current_lang = $default_lang;
        }

        $site_url = get_site_url();
        $flags    = [];

        foreach ( $languages as $lang ) {
            $name = $use_orig
                ? ucfirst( transposh_consts::get_language_orig_name( $lang ) )
                : ucfirst( transposh_consts::get_language_name( $lang ) );

            $flag_code = transposh_consts::get_language_flag( $lang );
            if ( $lang === 'en' && ( $lsft_opts['flag_type'] ?? '' ) !== 'tp' ) {
                $flag_code = $en_flag;
            }

            // Build target URL
            $url = $site_url;
            if ( $lang !== $default_lang ) {
                $url = $site_url . '/' . $lang;
            }

            $flags[] = [
                'code'    => $lang,
                'flag'    => $flag_base . '/' . $flag_code . '.png',
                'name'    => $name,
                'url'     => $url,
                'active'  => $lang === $current_lang,
            ];
        }

        return $flags;
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
        $td = 'bizcity-twin-ai';
        $sidebar_nav = apply_filters('bizcity_sidebar_nav', [
            ['slug' => 'explore',    'label' => __( 'Khám phá',            $td ), 'icon' => '🔍', 'type' => 'link', 'src' => admin_url('admin.php?page=bizcity-marketplace')],
            ['slug' => 'tools',      'label' => __( 'Công cụ',             $td ), 'icon' => '🛠️', 'type' => 'link', 'src' => home_url('tools-map/')],
            ['slug' => 'skills',     'label' => __( 'Tạo kỹ năng',            $td ), 'icon' => '⚡', 'type' => 'link', 'src' => home_url('skills/')],
            ['slug' => 'training',   'label' => __( 'Dạy AI bằng sổ tay',     $td ), 'icon' => '📖', 'type' => 'link', 'src' => home_url('note/')],
            ['slug' => 'maturity',   'label' => __( 'Dạy AI bằng hỏi đáp',    $td ), 'icon' => '🧬', 'type' => 'link', 'src' => admin_url('admin.php?page=bizcity-knowledge-training')],
            ['slug' => 'automation', 'label' => __( 'Quy trình',              $td ), 'icon' => '🔄', 'type' => 'link', 'src' => admin_url('admin.php?page=bizcity-workspace&tab=workflow')],
            
            ['slug' => 'settings',   'label' => __( 'Cài đặt API',         $td ), 'icon' => '⚙️', 'panel' => 'settings'],
            ['slug' => 'gateway',    'label' => __( 'Cổng kết nối',        $td ), 'icon' => '🔌', 'type' => 'link', 'src' => admin_url('admin.php?page=bizchat-gateway')],
            ['slug' => 'scheduler',  'label' => __( 'Lịch biểu',           $td ), 'icon' => '📅', 'type' => 'link', 'src' => home_url('scheduler/')],
            
        ]);

        // ── Welcome screen tool shortcuts (configurable via filter) ──
        $welcome_tools = apply_filters('bizcity_welcome_tools', [
            ['slug' => 'write_article',  'label' => __( 'Viết bài',            $td ), 'icon' => '✍️',  'color' => '#4D6BFE', 'pluginSlug' => 'bizcity-tool-content', 'toolName' => 'write_article',    'prompt' => __( '@write_article Viết bài giúp tôi', $td )],
            ['slug' => 'gen_image',      'label' => __( 'Tạo hình ảnh',        $td ), 'icon' => '🖼️',  'color' => '#FF5630', 'pluginSlug' => 'bizcity-tool-image',   'toolName' => 'generate_image',   'prompt' => __( '@generate_image Tạo hình ảnh giúp tôi', $td )],
            ['slug' => 'summarize',      'label' => __( 'Tóm tắt',             $td ), 'icon' => '📝',  'color' => '#8E33FF', 'pluginSlug' => 'bizcity-tool-content', 'toolName' => 'summarize',        'prompt' => __( '@summarize Tóm tắt nội dung này giúp tôi', $td )],
            ['slug' => 'consult',        'label' => __( 'Tư vấn',              $td ), 'icon' => '💡',  'color' => '#FFAB00', 'pluginSlug' => 'bizcity-agent-calo',   'toolName' => 'consult',          'prompt' => __( '@consult Tư vấn giúp tôi về', $td )],
            ['slug' => 'order_list',     'label' => __( 'Xem đơn hàng',        $td ), 'icon' => '🛒',  'color' => '#22C55E', 'pluginSlug' => 'bizcity-tool-woo',     'toolName' => 'order_list',       'prompt' => '@order_list'],
            ['slug' => 'report',         'label' => __( 'Báo cáo',             $td ), 'icon' => '📊',  'color' => '#00B8D9', 'pluginSlug' => 'bizcity-tool-woo',     'toolName' => 'business_report',  'prompt' => __( '@business_report Tạo báo cáo', $td )],
            ['slug' => 'mindmap',        'label' => __( 'Tạo Mindmap',         $td ), 'icon' => '🧠',  'color' => '#FF5630', 'pluginSlug' => 'bizcity-tool-mindmap', 'toolName' => 'create_mindmap',   'prompt' => '@create_mindmap'],
            ['slug' => 'task',           'label' => __( 'Tạo nhắc nhở',        $td ), 'icon' => '📋',  'color' => '#8E33FF', 'pluginSlug' => 'bizcity-tool-slide',   'toolName' => 'create_task',      'prompt' => '@create_task'],
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

        $react_i18n = $this->build_react_i18n_map();

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
            'walletRestUrl'      => rest_url('bizcity/v1/'),
            'paypalClientId'     => get_site_option('bizcity_paypal_client_id', ''),
            'fxUsdVnd'           => (int) get_site_option('bizcity_wallet_fx_usd_vnd', 25000),
            'shopBlogId'         => $shop_blog_id,
            'shopProducts'       => $shop_products,
            'ssoGoogleUrl'       => site_url('?auth=sso'),
            'ssoBizcityUrl'      => site_url('?auth=sso&provider=bizcity'),
            'locale'             => $react_i18n['locale'] ?: get_locale(),
            'currentLang'        => $react_i18n['lang'],
            'currentLocale'      => $react_i18n['locale'],
            'reactI18n'          => $react_i18n['translations'],
            'kciRatio'           => $kci_ratio_val,
            'isSuperAdmin'       => current_user_can( 'manage_network' ),
            'languageFlags'      => $this->build_language_flags_data(),
            'studioTools'        => class_exists( 'BizCity_Tool_Registry' )
                                        ? BizCity_Tool_Registry::get_studio_tools()
                                        : ( class_exists( 'BCN_Notebook_Tool_Registry' ) ? BCN_Notebook_Tool_Registry::get_all() : [] ),
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
            .bizc-msg-slash { font-size: 12px !important; font-style: italic !important; }
            /* ── Working indicator — force display on multisite hub ── */
            @keyframes bizc-ws-in { from { opacity: 0; transform: translateX(-4px); } to { opacity: 1; transform: translateX(0); } }
            @keyframes bizc-dot-in { 0%,80%,100% { opacity: .3; transform: scale(.9); } 40% { opacity: 1; transform: scale(1.1); } }
            #root .bizc-working[data-bizc-working="1"] { display: block !important; visibility: visible !important; opacity: 1 !important; margin-bottom: 16px !important; width: min(560px,100%) !important; max-width: 100% !important; z-index: 6 !important; isolation: isolate !important; position: relative !important; }
            #root .bizc-working[data-bizc-working="1"] .bizc-working-bubble { display: flex !important; flex-direction: column !important; gap: 4px !important; padding: 12px 16px !important; background: #f3f4f6 !important; border-radius: 12px !important; box-shadow: 0 1px 4px #0000000a !important; max-width: 100% !important; width: 100% !important; font-size: 13px !important; line-height: 1.5 !important; border: 1px solid #e5e7eb !important; }
            #root .bizc-working-bubble.compact { padding: 8px 14px !important; gap: 0 !important; opacity: .7 !important; font-size: 12px !important; }
            #root .bizc-working[data-bizc-working="1"] .bizc-ws { display: flex !important; align-items: flex-start !important; gap: 8px !important; padding: 4px 0 !important; color: #6b7280 !important; animation: bizc-ws-in .15s ease !important; }
            #root .bizc-ws.done { opacity: 0.5 !important; }
            #root .bizc-ws.active { color: #4f46e5 !important; font-weight: 500 !important; }
            #root .bizc-ws-icon { width: 16px !important; height: 16px !important; display: flex !important; align-items: center !important; justify-content: center !important; font-size: 11px !important; flex-shrink: 0 !important; }
            #root .bizc-ws.done .bizc-ws-icon { color: #22c55e !important; }
            #root .bizc-ws.active .bizc-ws-icon::after { content: '' !important; width: 6px !important; height: 6px !important; border-radius: 50% !important; background: #6366f1 !important; animation: bizc-dot-in 1.4s infinite ease-in-out !important; display: block !important; }
            #root .bizc-working[data-bizc-working="1"] .bizc-ws-main { flex: 1 !important; min-width: 0 !important; display: flex !important; flex-direction: column !important; gap: 1px !important; }
            #root .bizc-ws-text { flex: 1 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; font-weight: 600 !important; }
            #root .bizc-ws-detail { display: block !important; font-size: 11px !important; color: #6b7280 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; }
            #root .bizc-ws-stage { display: inline-flex !important; align-self: flex-start !important; font-size: 10px !important; line-height: 1.3 !important; color: #4f46e5 !important; background: #eef2ff !important; border: 1px solid #c7d2fe !important; border-radius: 10px !important; padding: 1px 7px !important; }
            #root .bizc-ws-ms { font-size: 10px !important; color: #9ca3af !important; flex-shrink: 0 !important; }
            .flex-shrink-0 p { line-height:0.5em !important; font-size:13px}
            .text-n700  { font-size:13px}
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
            var kciI18n = <?php echo wp_json_encode([
                'twin_core' => __( '📊 Lõi đôi', $td ),
                'knowledge' => __( 'Tri thức', $td ),
                'execution' => __( 'Thực thi', $td ),
                'teach_ai_title' => __( 'Dạy AI (Nuôi dạy AI)', $td ),
                'teach_ai' => __( '🌱 Dạy AI', $td ),
                'priority' => __( 'Ưu tiên', $td ),
                'new_chat' => __( 'Chat mới', $td ),
            ]); ?>;
            var timer = null;

            function buildKciHtml(val) {
                var exec = 100 - val;
                return '<div class="bizc-kci-sidebar">' +
                    '<div class="bizc-kci-head">' +
                        '<span>' + kciI18n.twin_core + '</span>' +
                        '<span class="bizc-kci-vals">' + kciI18n.knowledge + ':<b id="bizc-kci-k">' + val + '</b>% ' + kciI18n.execution + ':<b id="bizc-kci-e">' + exec + '</b>%</span>' +
                    '</div>' +
                    '<input type="range" id="bizc-kci-range" min="0" max="100" step="10" value="' + val + '">' +
                    '' +
                    '<div class="bizc-kci-presets">' +
                        '<button class="bizc-kci-pre' + (val===100?' active':'') + '" data-v="100">📚100</button>' +
                        '<button class="bizc-kci-pre' + (val===80?' active':'') + '" data-v="80">🧠80</button>' +
                        '<button class="bizc-kci-pre' + (val===50?' active':'') + '" data-v="50">⚖️50</button>' +
                        '<button class="bizc-kci-pre' + (val===20?' active':'') + '" data-v="20">🚀20</button>' +
                        '<button class="bizc-kci-pre bizc-kci-nuoi" title="' + kciI18n.teach_ai_title + '">' + kciI18n.teach_ai + '</button>' +
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
                if (sEl) sEl.textContent = kciI18n.priority + ': ' + kciI18n.knowledge + ': ' + val + '%, ' + kciI18n.execution + ': ' + exec + '%';
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
                var navDiv = sidebarCol.querySelector('.flex.flex-col.pt-5');
                if (!navDiv) {
                    // Fallback: find the flex-col with localized new chat text.
                    var allDivs = sidebarCol.querySelectorAll('.flex.flex-col');
                    for (var i = 0; i < allDivs.length; i++) {
                        if (allDivs[i].textContent.indexOf(kciI18n.new_chat) !== -1) {
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
                var sidebar = document.querySelector('[class*="flex"][class*="flex-col"][class*="pt-5"]');
                if (!sidebar) return;
                // Look for localized new chat text to confirm this is the nav sidebar.
                if (sidebar.textContent.indexOf(kciI18n.new_chat) === -1) return;
                inject(sidebar.parentElement || sidebar);
            });
            var root = document.getElementById('root');
            if (root) {
                obs.observe(root, { childList: true, subtree: true });
                // Also try immediate inject (in case React already rendered)
                setTimeout(function() {
                    var sidebar = document.querySelector('[class*="flex"][class*="flex-col"][class*="pt-5"]');
                    if (sidebar && sidebar.textContent.indexOf(kciI18n.new_chat) !== -1) {
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
        $td = 'bizcity-twin-ai';
        
        $char_name = $character ? $character->name : __( 'Trợ lý AI', $td );
        $char_model = ($character && !empty($character->model_id)) ? $character->model_id : 'GPT-4o-mini';
        $char_desc = ($character && !empty($character->description)) ? $character->description : __( 'Trợ lý AI thông minh của bạn', $td );
        $char_avatar = ($character && !empty($character->avatar)) ? $character->avatar : '';
        
        // Blog name for header display
        $blog_name = get_bloginfo('name') ?: __( 'Trợ lý AI', $td );
        $header_name = __( 'Trợ lý', $td ) . ' ' . $blog_name;
        $header_desc = __( 'Trưởng nhóm điều hành công việc, điều phối các trợ lý AI khác', $td );
        
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
            'walletRestUrl' => rest_url('bizcity/v1/'),
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
                    <button class="bizc-sidebar-collapse" id="bizc-sidebar-collapse" title="<?php echo esc_attr__( 'Thu gọn thanh bên', $td ); ?>">
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
                        <?php echo esc_html__( 'Đăng nhập/Đăng ký', $td ); ?>
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
                        <span><?php echo esc_html__( 'Tìm kiếm chat...', $td ); ?></span>
                    </button>
                </div>
                
                <button class="bizc-new-chat-btn" id="bizc-new-chat">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    <?php echo esc_html__( 'CHAT MỚI', $td ); ?>
                </button>
                
                <!-- Projects (ChatGPT-style) -->
                <div class="bizc-section">
                    <div class="bizc-section-hdr">
                        <span><?php echo esc_html__( '📁 DỰ ÁN', $td ); ?></span>
                        <span class="bizc-proj-add-btn" id="bizc-add-project" title="<?php echo esc_attr__( 'Thêm dự án', $td ); ?>">＋</span>
                    </div>
                    <div class="bizc-proj-list" id="bizc-proj-list">
                        <!-- Loaded by JS -->
                    </div>
                </div>
                
                <!-- Recent Conversations (not in any project) -->
                <div class="bizc-section">
                    <div class="bizc-section-hdr">
                        <span><?php echo esc_html__( '💬 Gần đây', $td ); ?></span>
                        <span id="bizc-sessions-view-all" style="color:#3b82f6;cursor:pointer;font-size:11px;" data-url="<?php echo esc_url( home_url( '/chat-sessions/' ) ); ?>" title="<?php echo esc_attr__( 'Xem toàn bộ phiên chat', $td ); ?>"><?php echo esc_html__( 'Xem chi tiết →', $td ); ?></span>
                    </div>
                </div>
                <div class="bizc-convs" id="bizc-convs-list">
                    <!-- Loaded by JS -->
                </div>
                
                <!-- Intent Conversations (Tasks) -->
                <div class="bizc-section">
                    <div class="bizc-section-hdr">
                        <span><?php echo esc_html__( '🎯 Nhiệm vụ', $td ); ?></span>
                        <span style="color:#9ca3af;font-size:10px;margin-right:auto;margin-left:4px;" id="bizc-intent-count">0</span>
                        <span id="bizc-intent-view-all" style="color:#3b82f6;cursor:pointer;font-size:11px;" data-url="<?php echo esc_url( home_url( '/tasks/' ) ); ?>" title="<?php echo esc_attr__( 'Xem toàn bộ nhiệm vụ', $td ); ?>"><?php echo esc_html__( 'Xem chi tiết →', $td ); ?></span>
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
                        <?php echo esc_html__( 'Cấu hình & Cài đặt', $td ); ?>
                    </a>
                </div>
            </div>
            
            <!-- Search Modal - ChatGPT style -->
            <div class="bizc-search-modal" id="bizc-search-modal">
                <div class="bizc-search-modal-content">
                    <div class="bizc-search-modal-header">
                        <input type="text" placeholder="<?php echo esc_attr__( 'Tìm kiếm chat...', $td ); ?>" id="bizc-search-input" autocomplete="off">
                        <button class="bizc-search-close" id="bizc-search-close" aria-label="<?php echo esc_attr__( 'Đóng', $td ); ?>">
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
                                    <a href="<?php echo admin_url('admin.php?page=bccm_my_profile'); ?>" style="background:#45475a;color:#f9e2af;border:none;padding:3px 8px;border-radius:4px;font-size:10px;text-decoration:none;white-space:nowrap;" title="<?php echo esc_attr__( 'Cài hồ sơ & chiêm tinh', $td ); ?>">🌟 <?php echo esc_html__( 'Hồ sơ', $td ); ?></a>
                                    <button id="bizc-router-poll-btn" onclick="bizcRouterPoll(event)" style="background:#45475a;color:#cdd6f4;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="<?php echo esc_attr__( 'Bật/tắt theo dõi', $td ); ?>">‖ <?php echo esc_html__( 'Dừng', $td ); ?></button>
                                    <button id="bizc-export-router-btn" onclick="bizcExportJSON('router', event)" style="background:#45475a;color:#89b4fa;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="<?php echo esc_attr__( 'Xuất log router', $td ); ?>">📋 <?php echo esc_html__( 'Xuất JSON', $td ); ?></button>
                                    <button onclick="bizcRouterClear(event)" style="background:#45475a;color:#cdd6f4;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="<?php echo esc_attr__( 'Xóa log', $td ); ?>">🗑 <?php echo esc_html__( 'Xóa', $td ); ?></button>
                                    <button onclick="bizcRouterFullscreen(event)" id="bizc-fs-btn" style="background:#45475a;color:#cdd6f4;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:10px;" title="<?php echo esc_attr__( 'Phóng to / Thu nhỏ', $td ); ?>">⛶ <?php echo esc_html__( 'Mở rộng', $td ); ?></button>
                                </span>
                            </div>
                            <!-- Router Log Panel -->
                            <div id="bizc-router-logs" style="padding:8px 10px;overflow-y:auto;flex:1;min-height:40px;">
                                <div style="color:#6c7086;"><?php echo esc_html__( 'Nhấn Theo dõi hoặc gửi tin nhắn để xem log nhận diện...', $td ); ?></div>
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
                $core_items[] = ['type' => 'chat', 'slug' => 'chat', 'icon' => '💬', 'label' => __( 'Chat', 'bizcity-twin-ai' ), 'src' => '', 'title' => __( 'Quay về Chat', 'bizcity-twin-ai' )];
                $core_items[] = ['type' => 'link', 'slug' => 'tools-map', 'icon' => '🧰', 'label' => __( 'Công cụ AI', 'bizcity-twin-ai' ), 'src' => home_url('/tools-map/'), 'title' => __( 'Danh sách công cụ AI', 'bizcity-twin-ai' )];
                
                if (current_user_can('manage_options')) {
                    $core_items[] = ['type' => 'link', 'slug' => 'control-panel', 'icon' => '🎛️', 'label' => __( 'Bảng điều khiển', 'bizcity-twin-ai' ), 'src' => home_url('/tool-control-panel/'), 'title' => __( 'Cấu hình điều hướng Tool', 'bizcity-twin-ai' )];
                    $core_items[] = ['type' => 'link', 'slug' => 'profile', 'icon' => '🌟', 'label' => __( 'Hồ sơ', 'bizcity-twin-ai' ), 'src' => admin_url('admin.php?page=bccm_my_profile'), 'title' => __( 'Cài hồ sơ', 'bizcity-twin-ai' )];
                    $core_items[] = ['type' => 'link', 'slug' => 'knowledge', 'icon' => '📚', 'label' => __( 'Kiến thức', 'bizcity-twin-ai' ), 'src' => admin_url('admin.php?page=bizcity-knowledge-characters'), 'title' => __( 'Cài kiến thức', 'bizcity-twin-ai' )];
                    $core_items[] = ['type' => 'link', 'slug' => 'marketplace', 'icon' => '🏪', 'label' => __( 'Chợ AI', 'bizcity-twin-ai' ), 'src' => admin_url('index.php?page=bizcity-marketplace'), 'title' => __( 'Chợ AI Agent', 'bizcity-twin-ai' )];
                    
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
                            <button id="bizc-proj-back" style="background:none;border:none;cursor:pointer;font-size:18px;color:#6366f1;padding:4px;" title="<?php echo esc_attr__( 'Quay lại chat', $td ); ?>">←</button>
                            <span id="bizc-proj-detail-icon" style="font-size:24px;">📁</span>
                            <h2 id="bizc-proj-detail-name" style="margin:0;font-size:18px;font-weight:700;color:#1a1a2e;flex:1;"></h2>
                        </div>
                        <!-- Character Binding -->
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding:8px 12px;background:#f3f4f6;border-radius:8px;">
                            <label style="font-size:12px;color:#6b7280;white-space:nowrap;"><?php echo esc_html__( '🎭 Trợ lý:', $td ); ?></label>
                            <select id="bizc-proj-character-select" style="flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;background:#fff;outline:none;">
                                <option value="0"><?php echo esc_html__( '— Mặc định —', $td ); ?></option>
                                <?php foreach ($characters as $ch): ?>
                                <option value="<?php echo esc_attr($ch->id); ?>"><?php echo esc_html($ch->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span id="bizc-proj-char-status" style="font-size:11px;color:#9ca3af;"></span>
                        </div>
                        <div style="display:flex;gap:12px;align-items:center;">
                            <input type="text" id="bizc-proj-new-chat-input" placeholder="<?php echo esc_attr__( '+ Chat mới trong dự án này', $td ); ?>" style="flex:1;padding:8px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:13px;outline:none;background:#f9fafb;">
                        </div>
                        <div style="display:flex;gap:16px;margin-top:12px;border-bottom:2px solid transparent;">
                            <span class="bizc-proj-tab active" data-tab="chats" style="padding:6px 0;font-size:13px;font-weight:600;color:#6366f1;border-bottom:2px solid #6366f1;cursor:pointer;"><?php echo esc_html__( 'Trò chuyện', $td ); ?></span>
                            <span class="bizc-proj-tab" data-tab="sources" style="padding:6px 0;font-size:13px;color:#9ca3af;cursor:pointer;"><?php echo esc_html__( 'Nguồn', $td ); ?></span>
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
                    <?php echo esc_html__( '👁️ Mô hình thị giác sẽ phân tích hình ảnh', $td ); ?>
                </div>
                
                <?php if ( ! $current_uid ) : ?>
                <!-- Guest trial hint -->
                <div class="bizc-guest-hint" id="bizc-guest-hint">
                    <span class="bizc-guest-hint-icon">🌟</span>
                    <span class="bizc-guest-hint-text"><?php echo wp_kses_post( __( 'Bạn có <strong id="bizc-guest-remaining">3</strong> tin nhắn thử nghiệm. <a href="#" id="bizc-guest-signup-link">Đăng ký</a> để dùng không giới hạn!', $td ) ); ?></span>
                </div>
                <?php endif; ?>
                
                <!-- Input -->
                <div class="bizc-input-area" id="bizc-input-area">
                    <input type="file" id="bizc-file-input" accept="image/*" multiple style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;">
                    
                    <!-- ═══ Pre-Intent Plugin Chips Bar ═══ -->
                    <div class="bizc-plugin-chips-bar" id="bizc-plugin-chips">
                        <div class="bizc-chips-scroll" id="bizc-chips-scroll">
                            <div class="bizc-chips-loading"><?php echo esc_html__( 'Đang tải trợ lý...', $td ); ?></div>
                        </div>
                    </div>
                    
                    <!-- Plugin Context Header (shown when plugin selected) -->
                    <div class="bizc-plugin-context-header" id="bizc-context-header">
                        <span class="bizc-context-plugin-icon" id="bizc-context-icon">🤖</span>
                        <div class="bizc-context-tools-row" id="bizc-context-tools"></div>
                        <button class="bizc-context-close-btn" id="bizc-context-close" title="<?php echo esc_attr__( 'Thoát khỏi chế độ plugin', $td ); ?>">✕ <?php echo esc_html__( 'Thoát', $td ); ?></button>
                    </div>
                    
                    <!-- @mention autocomplete dropdown (ChatGPT style) -->
                    <div class="bizc-mention-dropdown" id="bizc-mention-dropdown"></div>
                    
                    <!-- Simple input container (like ChatGPT) -->
                    <div class="bizc-input-container">
                        <label for="bizc-file-input" class="bizc-attach-btn" id="bizc-attach" title="<?php echo esc_attr__( 'Đính kèm ảnh', $td ); ?>">📎</label>
                        <!-- Agent / Tool badge (positioned above input) -->
                        <span class="bizc-mention-tag" id="bizc-mention-tag" style="display:none;"></span>
                        <!-- Tool pill inside input row -->
                        <span class="bizc-tool-pill" id="bizc-tool-pill" style="display:none;"></span>
                        <textarea class="bizc-input" id="bizc-input" placeholder="<?php echo esc_attr__( 'Nhập tin nhắn... (@ chọn trợ lý · / tìm công cụ)', $td ); ?>" rows="1"></textarea>
                        <button class="bizc-send-btn" id="bizc-send" type="button">
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                </div><!-- /bizc-chat-panel -->

                <!-- Agent Template Panel (hidden, shown when Touch Bar agent clicked) -->
                <div id="bizc-agent-panel" style="display:none;flex:1;flex-direction:column;overflow:hidden;border-radius:18px;background:#fff;">
                    <div style="padding:0px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #e5e7eb;flex-shrink:0;">
                        <button id="bizc-agent-back" style="margin:5px !important; margin-left: 10px;width:36px;height:36px;border-radius:50%;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.3);cursor:pointer;font-size:15px;color:#6366f1;display:flex;align-items:center;justify-content:center;transition:all 0.2s;flex-shrink:0;" title="<?php echo esc_attr__( 'Quay lại chat', $td ); ?>" onmouseover="this.style.background='rgba(99,102,241,0.2)'; this.style.borderColor='rgba(99,102,241,0.5)';" onmouseout="this.style.background='rgba(99,102,241,0.1)'; this.style.borderColor='rgba(99,102,241,0.3)';">←</button>
                        <img id="bizc-agent-icon" src="" alt="" style="width:24px;height:24px;border-radius:6px;object-fit:cover;display:none;">
                        <span id="bizc-agent-title" style="font-weight:600;font-size:14px;color:#1a1a2e;flex:1;"></span>
                        <button id="bizc-agent-external" style="margin:5px !important;margin-right:10px;width:36px;height:36px;border-radius:50%;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.3);cursor:pointer;font-size:15px;color:#6366f1;display:flex;align-items:center;justify-content:center;transition:all 0.2s;flex-shrink:0;" title="<?php echo esc_attr__( 'Mở tab mới', $td ); ?>" onmouseover="this.style.background='rgba(99,102,241,0.2)'; this.style.borderColor='rgba(99,102,241,0.5)';" onmouseout="this.style.background='rgba(99,102,241,0.1)'; this.style.borderColor='rgba(99,102,241,0.3)';">↗</button>
                    </div>
                    <iframe id="bizc-agent-iframe" src="about:blank" style="flex:1;border:none;width:100%;"></iframe>
                </div>
                
            </div>
        </div>

        <!-- Pipeline Monitor Sidebar (Phase 1.2 — SSE-based, positioned fixed right) -->
        <div id="bc-pipeline-sidebar"></div>

        <?php
    }

}
