<?php
/**
 * BizCity Plugin Feature Gate — Pro/Premium menu lock system.
 *
 * Reads `bizcity_hub_plugins_enabled` (synced from hub via BizCity_LLM_Client::get_entitlement())
 * and:
 *  1. Adds a 🔒 PRO / PREMIUM badge to admin menu items for locked plugins.
 *  2. Shows an upgrade notice on the locked plugin's admin page.
 *  3. Provides `is_enabled( $slug )` helper for bundled plugins to self-gate.
 *
 * Works on single-site and multisite (uses get_site_option).
 *
 * Design rules:
 *  - ALWAYS loads the plugin (register_activation_hook, enqueue, etc. still run).
 *  - NEVER removes the menu item — user sees it but can't use the features.
 *  - Badge is injected via CSS-class span, not JS, so screen readers skip gracefully.
 *  - If `bizcity_hub_plugins_enabled` is empty (no plan synced yet) → ALL plugins
 *    are treated as enabled (fail-open — no plan info = no gate).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-09 (PHASE-MASTER-PLANS client gate)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Plugin_Feature_Gate {

    /**
     * Map: plugin_slug => [ 'menu_slug' => string, 'tier' => 'pro'|'premium' ]
     *
     * `menu_slug` is the first argument to add_menu_page() (or the page slug
     * that appears in ?page= URL param) for the plugin's top-level menu entry.
     * If a plugin has no top-level page (only submenu under another), set
     * `menu_slug` to the submenu page slug for badge injection on the parent.
     */
    private static $plugin_map = [
        // Free tier (no gate needed — listed here for completeness)
        // Pro tier — shown with 🔒 badge on Free
        'bizcity-video-kling'      => [ 'menu_slug' => 'bizcity-video-kling',     'tier' => 'pro' ],
        'bizgpt-tool-google'       => [ 'menu_slug' => 'bizgpt-tool-google',       'tier' => 'pro' ],
        'bizcity-pagebuilder'      => [ 'menu_slug' => 'bizcity-pagebuilder',      'tier' => 'pro' ],
        'bizcoach-pro'             => [ 'menu_slug' => 'bizcoach-pro',             'tier' => 'pro' ],
        'bizcity-zalo-bizcity'     => [ 'menu_slug' => 'bizcity-zalo-bizcity',     'tier' => 'pro' ],
        'bizcity-tool-content'     => [ 'menu_slug' => 'bizcity-tool-content',     'tier' => 'pro' ],
        // Premium tier — shown with 🔒 PREMIUM badge on Free + Pro
        'bizcity-twin-crm'         => [ 'menu_slug' => 'bizcity-twin-crm',         'tier' => 'premium' ],
        'bizcity-zalo-personal'    => [ 'menu_slug' => 'bizcity-zalo-personal',    'tier' => 'premium' ],
        'bizcity-tarot'            => [ 'menu_slug' => 'bizcity-tarot',            'tier' => 'premium' ],
    ];

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // [2026-06-09 Johnny Chu] PHASE-MASTER-PLANS — boot hooks
    public static function boot() {
        $inst = self::instance();
        // Badge injection — after ALL plugins register their menus (priority 999).
        add_action( 'admin_menu',     [ $inst, 'inject_badges' ], 999 );
        // Upgrade notice on locked pages.
        add_action( 'current_screen', [ $inst, 'maybe_upgrade_notice' ] );
        // Inline CSS for badges (only admin).
        add_action( 'admin_head',     [ $inst, 'print_badge_css' ] );
    }

    /* ================================================================
     *  Public API
     * ================================================================ */

    /**
     * Check if a bundled plugin slug is enabled by the current hub plan.
     *
     * @param string $slug e.g. 'bizcity-video-kling'
     * @return bool  true = allowed; false = locked. Always true when no plan synced.
     */
    public static function is_enabled( $slug ) {
        $enabled = self::get_enabled_slugs();
        if ( empty( $enabled ) ) {
            return true; // fail-open: no plan info yet
        }
        return in_array( $slug, $enabled, true );
    }

    /**
     * Current hub master level (string: 'free' | 'master_pro' | 'master_premium').
     */
    public static function current_level() {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option
        return (string) get_option( 'bizcity_hub_master_level', 'free' );
    }

    /* ================================================================
     *  Internal helpers
     * ================================================================ */

    /**
     * Returns array of enabled plugin slugs, or [] if not yet synced.
     */
    private static function get_enabled_slugs() {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option
        $raw = get_option( 'bizcity_hub_plugins_enabled', '' );
        if ( $raw === '' ) {
            return [];
        }
        $arr = json_decode( $raw, true );
        return is_array( $arr ) ? $arr : [];
    }

    /* ================================================================
     *  Hooks
     * ================================================================ */

    /**
     * Inject lock badge into admin menu titles for gated plugins.
     * Modifies global $menu and $submenu directly (WP pattern).
     */
    public function inject_badges() {
        global $menu, $submenu;

        $enabled = self::get_enabled_slugs();
        if ( empty( $enabled ) ) {
            return; // no plan info yet — show all clean
        }

        foreach ( self::$plugin_map as $slug => $cfg ) {
            if ( in_array( $slug, $enabled, true ) ) {
                continue; // plan allows this plugin
            }

            $badge_class = ( $cfg['tier'] === 'premium' ) ? 'bzgate-badge-premium' : 'bzgate-badge-pro';
            $badge_label = ( $cfg['tier'] === 'premium' ) ? 'PREMIUM' : 'PRO';
            $badge_html  = ' <span class="bzgate-badge ' . $badge_class . '">' . $badge_label . '</span>';
            $page_slug   = $cfg['menu_slug'];

            // Scan top-level menu
            if ( is_array( $menu ) ) {
                foreach ( $menu as &$item ) {
                    if ( isset( $item[2] ) && $item[2] === $page_slug ) {
                        // Avoid double-badge
                        if ( strpos( $item[0], 'bzgate-badge' ) === false ) {
                            $item[0] .= $badge_html;
                        }
                        break;
                    }
                }
                unset( $item );
            }

            // Scan submenus (in case top-level slug differs)
            if ( is_array( $submenu ) ) {
                foreach ( $submenu as &$sub_group ) {
                    foreach ( $sub_group as &$sub_item ) {
                        if ( isset( $sub_item[2] ) && $sub_item[2] === $page_slug ) {
                            if ( strpos( $sub_item[0], 'bzgate-badge' ) === false ) {
                                $sub_item[0] .= $badge_html;
                            }
                        }
                    }
                    unset( $sub_item );
                }
                unset( $sub_group );
            }
        }
    }

    /**
     * Show upgrade admin notice when the user visits a locked plugin page.
     *
     * @param WP_Screen $screen
     */
    public function maybe_upgrade_notice( $screen ) {
        $enabled = self::get_enabled_slugs();
        if ( empty( $enabled ) ) {
            return;
        }
        foreach ( self::$plugin_map as $slug => $cfg ) {
            if ( in_array( $slug, $enabled, true ) ) {
                continue;
            }
            $page_slug = $cfg['menu_slug'];
            // Match by screen id (WP converts page slug to screen id with dashes/underscores)
            $screen_id_expected = str_replace( '-', '_', $page_slug );
            if ( $screen->id === $page_slug
                || $screen->id === 'toplevel_page_' . $page_slug
                || $screen->id === $screen_id_expected
                || ( isset( $screen->base ) && $screen->base === $page_slug )
            ) {
                $tier  = $cfg['tier'];
                $level = ( $tier === 'premium' ) ? 'Master Premium' : 'Master Pro';
                add_action( 'admin_notices', function() use ( $level, $slug ) {
                    $settings_url = admin_url( 'admin.php?page=bizcity-settings' );
                    echo '<div class="notice notice-warning" style="border-left-color:#f0ad4e;">';
                    echo '<p>';
                    echo '<strong>🔒 ' . esc_html( $level ) . ' required</strong> &mdash; ';
                    echo 'Plugin <code>' . esc_html( $slug ) . '</code> ';
                    echo 'chỉ hoạt động khi API key của bạn được cấp phép <strong>' . esc_html( $level ) . '</strong>.';
                    echo '<br><small style="color:#666;">Hiện tại: gói <strong>' . esc_html( self::current_level() ) . '</strong>. ';
                    echo 'Liên hệ <a href="https://bizcity.vn" target="_blank">bizcity.vn</a> để nâng cấp.</small>';
                    echo '</p>';
                    echo '</div>';
                } );
                break;
            }
        }
    }

    /**
     * Print minimal badge CSS in <head> — only if any plugin is gated.
     */
    public function print_badge_css() {
        $enabled = self::get_enabled_slugs();
        if ( empty( $enabled ) ) {
            return;
        }
        // Check if any gated plugin is actually locked.
        $has_locked = false;
        foreach ( array_keys( self::$plugin_map ) as $slug ) {
            if ( ! in_array( $slug, $enabled, true ) ) {
                $has_locked = true;
                break;
            }
        }
        if ( ! $has_locked ) {
            return;
        }
        ?>
        <style id="bzgate-badge-styles">
            .bzgate-badge {
                display: inline-block;
                font-size: 9px;
                font-weight: 700;
                line-height: 1;
                padding: 2px 5px;
                border-radius: 3px;
                vertical-align: middle;
                margin-left: 4px;
                letter-spacing: .3px;
            }
            .bzgate-badge-pro {
                background: #0073aa;
                color: #fff;
            }
            .bzgate-badge-premium {
                background: #d63638;
                color: #fff;
            }
            /* Sidebar folded — hide text badge */
            .folded .bzgate-badge { display: none; }
        </style>
        <?php
    }
}
