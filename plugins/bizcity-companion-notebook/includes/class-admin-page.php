<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin Page + Frontend /note/ page — Registers WP admin menu, rewrite rules, and enqueues React SPA.
 */
class BCN_Admin_Page {

    public function register() {
        add_menu_page(
            'Notebook',
            'Notebook',
            'read',
            'bizcity-notebook',
            [ $this, 'render_page' ],
            'dashicons-book-alt',
            3
        );
    }

    /**
     * Enqueue React SPA assets for frontend /note/ page.
     */
    public static function enqueue_note_assets() {
        $base_url = BCN_PLUGIN_URL;
        $base_dir = BCN_PLUGIN_DIR;
        $js_file  = 'assets/react/js/bizcity-notebook-app.js';
        $css_file = 'assets/react/css/bizcity-notebook-app.css';

        // Inherit base CSS from webchat React app.
        self::enqueue_webchat_base_css();

        if ( file_exists( $base_dir . $js_file ) ) {
            wp_enqueue_script( 'bcn-react-app', $base_url . $js_file, [], filemtime( $base_dir . $js_file ), true );
            // Vite outputs ESM — needs type="module".
            add_filter( 'script_loader_tag', function( $tag, $handle ) {
                if ( $handle === 'bcn-react-app' ) {
                    return str_replace( '<script ', '<script type="module" ', $tag );
                }
                return $tag;
            }, 10, 2 );
        }
        if ( file_exists( $base_dir . $css_file ) ) {
            wp_enqueue_style( 'bcn-react-styles', $base_url . $css_file, [], filemtime( $base_dir . $css_file ) );
        }

        wp_localize_script( 'bcn-react-app', 'bizcNotebookConfig', self::get_full_config() );

    }

    /* ─── Admin page assets ─── */

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_bizcity-notebook' ) return;

        $base_url = BCN_PLUGIN_URL;
        $base_dir = BCN_PLUGIN_DIR;

        // Inherit base CSS from webchat React app.
        self::enqueue_webchat_base_css();

        $js_file = 'assets/react/js/bizcity-notebook-app.js';
        $css_file = 'assets/react/css/bizcity-notebook-app.css';

        if ( file_exists( $base_dir . $js_file ) ) {
            wp_enqueue_script(
                'bcn-react-app',
                $base_url . $js_file,
                [],
                filemtime( $base_dir . $js_file ),
                true
            );
            // Vite outputs ESM — needs type="module".
            add_filter( 'script_loader_tag', function( $tag, $handle ) {
                if ( $handle === 'bcn-react-app' ) {
                    return str_replace( '<script ', '<script type="module" ', $tag );
                }
                return $tag;
            }, 10, 2 );
        }

        if ( file_exists( $base_dir . $css_file ) ) {
            wp_enqueue_style(
                'bcn-react-styles',
                $base_url . $css_file,
                [],
                filemtime( $base_dir . $css_file )
            );
        }

        wp_localize_script( 'bcn-react-app', 'bizcNotebookConfig', self::get_full_config() );
        $keep_styles  = [ 'bcn-react-styles', 'bizcity-webchat-react-css' ];
        $keep_scripts = [ 'jquery', 'jquery-core', 'jquery-migrate', 'bcn-react-app' ];

        if ( $wp_styles instanceof WP_Styles ) {
            foreach ( $wp_styles->registered as $handle => $obj ) {
                if ( ! in_array( $handle, $keep_styles, true ) ) {
                    wp_dequeue_style( $handle );
                    wp_deregister_style( $handle );
                }
            }
        }
        if ( $wp_scripts instanceof WP_Scripts ) {
            foreach ( $wp_scripts->registered as $handle => $obj ) {
                if ( ! in_array( $handle, $keep_scripts, true ) ) {
                    wp_dequeue_script( $handle );
                    wp_deregister_script( $handle );
                }
            }
        }

        // Remove admin notices on notebook page for clean SPA.
        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
        remove_action( 'wp_head', 'wp_custom_css_cb', 101 );
        remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
        remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );

        // ── Strip all WP admin CSS/JS right before print — whitelist approach (like page-note.php) ──
        // admin_print_styles/scripts fire immediately before output, so NOTHING can re-enqueue after this.
        add_action( 'admin_print_styles',  [ __CLASS__, 'isolate_admin_styles'  ], 99999 );
        add_action( 'admin_print_scripts', [ __CLASS__, 'isolate_admin_scripts' ], 99999 );
    }

    /**
     * Filter the print queue to only our SPA styles — runs right before WP outputs <link> tags.
     */
    public static function isolate_admin_styles() {
        global $wp_styles;
        if ( ! $wp_styles instanceof WP_Styles ) return;

        $keep = [ 'bcn-react-styles', 'bizcity-webchat-react-css' ];
        $wp_styles->queue = array_values(
            array_filter( $wp_styles->queue, fn( $h ) => in_array( $h, $keep, true ) )
        );
    }

    /**
     * Filter the print queue to only our SPA scripts — runs right before WP outputs <script> tags.
     */
    public static function isolate_admin_scripts() {
        global $wp_scripts;
        if ( ! $wp_scripts instanceof WP_Scripts ) return;

        $keep = [ 'jquery', 'jquery-core', 'jquery-migrate', 'bcn-react-app', 'wp-api-fetch' ];
        $wp_scripts->queue = array_values(
            array_filter( $wp_scripts->queue, fn( $h ) => in_array( $h, $keep, true ) )
        );
    }

    /**
     * Enqueue the webchat React app base CSS for consistent styling.
     */
    private static function enqueue_webchat_base_css() {
        $webchat_dir = WPMU_PLUGIN_DIR . '/bizcity-bot-webchat/';
        $webchat_url = WPMU_PLUGIN_URL . '/bizcity-bot-webchat/';
        $css_path    = 'assets/react/css/bizcity-react-app.css';

        if ( file_exists( $webchat_dir . $css_path ) ) {
            wp_enqueue_style(
                'bizcity-webchat-react-css',
                $webchat_url . $css_path,
                [],
                filemtime( $webchat_dir . $css_path )
            );
        }
    }

    public function render_page() {
        ?>
        <style>
        /* Hide WP admin chrome so the SPA fills the viewport without conflicts */
        #adminmenuwrap, #adminmenuback, #adminmenumain,
        #wpadminbar, #wpfooter, #screen-meta, #screen-meta-links,
        .update-nag, .notice, .updated, .error,
        #wpbody-content > .wrap > h1 { display: none !important; }
        #wpcontent { margin-left: 0 !important; padding-left: 0 !important; }
        #wpbody-content { padding-bottom: 0 !important; }
        html.wp-toolbar { padding-top: 0 !important; }
        body.bcn-admin-body > *:not(.bcn-wrap):not(script):not(style):not(link):not(noscript) { }
        .bcn-wrap { position: fixed; inset: 0; z-index: 99999; background: #fff; }
        #bcn-app { width: 100%; height: 100%; }
        .bcn-markdown p, #bcn-app p { line-height: 2.2 !important; }
        .text-lg { margin-bottom: 0; }
        .border { border: 1px solid #eee !important; }
        .absolute button { text-align: left !important; }
        #bcn-app button { font: inherit !important; }
        .border-t { border-top: 1px solid #eee !important; }
        /* Restore borders overridden by bizcity-notebook-app.css reset rule */
        #bcn-app input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=range]),
        #bcn-app textarea, 
        #bcn-app input[type=text], #bcn-app input[type=url], #bcn-app input[type=search], #bcn-app input[type=email]
        #bcn-app select { border: 1px solid #e2e8f0 !important; }
        /* Restore borders overridden by WP admin forms.css */
        #bcn-app input[type=text],
        #bcn-app input[type=search],
        #bcn-app input[type=email],
        #bcn-app input[type=password],
        #bcn-app input[type=number],
        #bcn-app input[type=url] { padding: revert; line-height: revert; min-height: revert; }
        </style>
        <div id="bcn-app" class="bcn-wrap" style="min-height:100vh;"></div>
        <script>document.body.classList.add('bcn-admin-body');</script>
        <?php
    }

    /* ─── Agent profile ─── */

    /**
     * Get agent profile data (icon, name, plan, credits).
     */
    public static function get_agent_profile() {
        $user_id = get_current_user_id();

        // User plan
        $plan = $user_id ? get_user_meta( $user_id, '_bizcity_plan', true ) : '';
        if ( ! $plan ) $plan = 'free';

        // User avatar
        $avatar = get_avatar_url( $user_id, [ 'size' => 96 ] );

        // Bot/Agent character
        $agent_name   = get_bloginfo( 'name' ) ?: 'AI Notebook';
        $agent_avatar = '';
        if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
            $db = BizCity_Knowledge_Database::instance();
            $char_id = intval( get_option( 'bizcity_webchat_default_character_id', 0 ) );
            if ( ! $char_id ) {
                $opts = get_option( 'pmfacebook_options', [] );
                $char_id = isset( $opts['default_character_id'] ) ? intval( $opts['default_character_id'] ) : 0;
            }
            $char = $char_id ? $db->get_character( $char_id ) : null;
            if ( ! $char ) {
                $chars = $db->get_characters( [ 'status' => 'active', 'limit' => 1 ] );
                $char  = ! empty( $chars ) ? $chars[0] : null;
            }
            if ( $char ) {
                $agent_name   = $char->name;
                $agent_avatar = $char->avatar ?? '';
            }
        }

        // Credits from wallet
        $credits = 0;
        if ( $user_id && class_exists( 'BizCity_Wallet' ) ) {
            $credits = (float) get_user_meta( $user_id, '_bizcity_wallet_balance', true );
        }

        return [
            'userName'    => wp_get_current_user()->display_name ?? '',
            'userAvatar'  => $avatar,
            'userPlan'    => $plan,
            'credits'     => $credits,
            'agentName'   => $agent_name,
            'agentAvatar' => $agent_avatar,
            'isLoggedIn'  => is_user_logged_in(),
        ];
    }

    /* ─── JS Config ─── */

    private static function get_full_config() {
        $agent = self::get_agent_profile();

        return array_merge( [
            'restBase'      => esc_url_raw( rest_url( 'notebook/v1' ) ),
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'ajaxNonce'     => wp_create_nonce( 'bcn_ajax' ),
            'userId'        => get_current_user_id(),
            'blogId'        => get_current_blog_id(),
            'hasKnowledge'  => bcn_has_knowledge(),
            'hasIntent'     => bcn_has_intent(),
            'studioTools'   => BCN_Notebook_Tool_Registry::get_all(),
            'uploadMaxSize' => wp_max_upload_size(),
            'noteUrl'       => home_url( '/note/' ),
            'chatUrl'       => home_url( '/chat/' ),
            'loginUrl'      => wp_login_url( home_url( '/note/' ) ),
            'logoutUrl'     => wp_logout_url( home_url( '/note/' ) ),
            'profileUrl'    => admin_url( 'profile.php' ),
            'blogName'      => get_bloginfo( 'name' ) ?: 'Notebook',
            'sidebarNav'    => apply_filters( 'bizcity_sidebar_nav', [
                [ 'slug' => 'explore',    'label' => 'Khám phá',              'icon' => '🔍', 'src' => admin_url( 'admin.php?page=bizcity-marketplace' ) ],
                [ 'slug' => 'tools',      'label' => 'Công cụ',               'icon' => '🛠️', 'src' => home_url( 'tools-map/' ) ],
                [ 'slug' => 'training',   'label' => 'Đào tạo',               'icon' => '📖', 'src' => admin_url( 'admin.php?page=bizcity-knowledge' ) ],
                [ 'slug' => 'settings',   'label' => 'Cài đặt',               'icon' => '⚙️', 'src' => home_url( '/tool-control-panel/' ) ],
                [ 'slug' => 'automation', 'label' => 'Phối hợp nhiều Agents', 'icon' => '⚡', 'src' => admin_url( 'admin.php?page=bizcity-workspace&tab=workflow' ) ],
            ] ),
        ], $agent );
    }
}
