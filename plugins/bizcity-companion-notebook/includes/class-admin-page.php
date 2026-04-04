<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

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

        // Remove admin notices on notebook page for clean SPA.
        remove_all_actions( 'admin_notices' );
        
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
        echo '<div id="bcn-app" class="bcn-wrap" style="min-height:100vh;"></div>';
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
