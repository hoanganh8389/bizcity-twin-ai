<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent Settings REST API
 *
 * Provides REST API endpoints for reading/writing intent settings.
 * Ready for future mobile app integration.
 *
 * Endpoints:
 *   GET  /bizcity-intent/v1/settings           — Read all settings
 *   POST /bizcity-intent/v1/settings           — Update settings
 *   GET  /bizcity-intent/v1/settings/routing   — Read routing priority only
 *   GET  /bizcity-intent/v1/tools/active       — List active tools (for app)
 *
 * @package BizCity_Intent
 * @since   3.9.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Settings_API {

    private static $instance = null;

    /** @var array Valid routing priority values */
    private $valid_priorities = array( 'conversation', 'balanced', 'tools' );

    /** @var array Valid image default goals */
    private $valid_image_goals = array( 'tarot_interpret', 'image_describe', 'image_analyze', 'passthrough' );

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_action_bizcity_shell_cutover', array( $this, 'admin_action_cutover' ) );
    }

    /* ================================================================
     * REST API Routes
     * ================================================================ */

    public function register_rest_routes() {

        // GET /bizcity-intent/v1/settings — Read all settings
        register_rest_route( 'bizcity-intent/v1', '/settings', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_get_settings' ),
            'permission_callback' => array( $this, 'check_read_permission' ),
        ) );

        // POST /bizcity-intent/v1/settings — Update settings
        register_rest_route( 'bizcity-intent/v1', '/settings', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_update_settings' ),
            'permission_callback' => array( $this, 'check_write_permission' ),
            'args'                => array(
                'routing_priority' => array(
                    'description'       => 'Routing priority mode: conversation, balanced, tools',
                    'type'              => 'string',
                    'enum'              => array( 'conversation', 'balanced', 'tools' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'image_default_goal' => array(
                    'description'       => 'Default goal when user sends image without context',
                    'type'              => 'string',
                    'enum'              => array( 'tarot_interpret', 'image_describe', 'image_analyze', 'passthrough' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'top_n_tools' => array(
                    'description'       => 'Max tools injected into LLM prompt',
                    'type'              => 'integer',
                    'minimum'           => 3,
                    'maximum'           => 50,
                    'sanitize_callback' => 'absint',
                ),
                'shell_percentage' => array(
                    'description'       => 'Shell Engine traffic percentage (0=legacy, 100=shell, N=A/B)',
                    'type'              => 'integer',
                    'minimum'           => 0,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ),
                'shell_shadow' => array(
                    'description'       => 'Enable shadow comparison mode (legacy runs shell in parallel)',
                    'type'              => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
            ),
        ) );

        // GET /bizcity-intent/v1/settings/routing — Routing priority only (lightweight)
        register_rest_route( 'bizcity-intent/v1', '/settings/routing', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_get_routing' ),
            'permission_callback' => array( $this, 'check_read_permission' ),
        ) );

        // GET /bizcity-intent/v1/settings/shell — Shell Engine cutover status (S6)
        register_rest_route( 'bizcity-intent/v1', '/settings/shell', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_get_shell_status' ),
            'permission_callback' => array( $this, 'check_read_permission' ),
        ) );

        // POST /bizcity-intent/v1/settings/shell/cutover — Quick cutover action (S6/S7)
        register_rest_route( 'bizcity-intent/v1', '/settings/shell/cutover', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_shell_cutover' ),
            'permission_callback' => array( $this, 'check_write_permission' ),
            'args'                => array(
                'action' => array(
                    'description'       => 'Cutover action: shadow, ab_10, ab_25, ab_50, full, rollback',
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );

        // GET /bizcity-intent/v1/tools/active — Active tools list (for mobile app)
        register_rest_route( 'bizcity-intent/v1', '/tools/active', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_get_active_tools' ),
            'permission_callback' => array( $this, 'check_read_permission' ),
            'args'                => array(
                'limit' => array(
                    'description' => 'Maximum number of tools to return',
                    'type'        => 'integer',
                    'default'     => 50,
                    'minimum'     => 1,
                    'maximum'     => 200,
                ),
            ),
        ) );
    }

    /* ================================================================
     * Permission Callbacks
     * ================================================================ */

    /**
     * Read access — any logged-in user can read settings.
     */
    public function check_read_permission( WP_REST_Request $request ) {
        return is_user_logged_in();
    }

    /**
     * Write access — only admins can update settings.
     */
    public function check_write_permission( WP_REST_Request $request ) {
        return current_user_can( 'manage_options' );
    }

    /* ================================================================
     * REST Handlers
     * ================================================================ */

    /**
     * GET /settings — Return all intent configuration settings.
     */
    public function rest_get_settings( WP_REST_Request $request ) {
        return new WP_REST_Response( array(
            'routing_priority'      => get_option( 'bizcity_tcp_routing_priority', 'balanced' ),
            'image_default_goal'    => get_option( 'bizcity_tcp_image_default_goal', 'tarot_interpret' ),
            'top_n_tools'           => (int) get_option( 'bizcity_tcp_top_n_tools', 10 ),
            'shell_percentage'      => (int) get_option( 'bizcity_shell_percentage', 0 ),
            'shell_shadow'          => (bool) get_option( 'bizcity_shell_shadow', false ),
            'version'               => defined( 'BIZCITY_INTENT_VERSION' ) ? BIZCITY_INTENT_VERSION : 'unknown',
        ), 200 );
    }

    /**
     * POST /settings — Update one or more settings.
     */
    public function rest_update_settings( WP_REST_Request $request ) {
        $updated = array();

        // Routing priority
        $routing = $request->get_param( 'routing_priority' );
        if ( $routing !== null ) {
            if ( ! in_array( $routing, $this->valid_priorities, true ) ) {
                return new WP_Error(
                    'invalid_routing_priority',
                    'Invalid routing priority. Use: conversation, balanced, tools.',
                    array( 'status' => 400 )
                );
            }
            update_option( 'bizcity_tcp_routing_priority', $routing );
            $updated['routing_priority'] = $routing;
        }

        // Image default goal
        $image_goal = $request->get_param( 'image_default_goal' );
        if ( $image_goal !== null ) {
            if ( ! in_array( $image_goal, $this->valid_image_goals, true ) ) {
                return new WP_Error(
                    'invalid_image_goal',
                    'Invalid image default goal.',
                    array( 'status' => 400 )
                );
            }
            update_option( 'bizcity_tcp_image_default_goal', $image_goal );
            $updated['image_default_goal'] = $image_goal;
        }

        // Top N tools
        $top_n = $request->get_param( 'top_n_tools' );
        if ( $top_n !== null ) {
            $top_n = max( 3, min( 50, absint( $top_n ) ) );
            update_option( 'bizcity_tcp_top_n_tools', $top_n );
            $updated['top_n_tools'] = $top_n;
        }

        // Shell Engine percentage (S6 cutover)
        $shell_pct = $request->get_param( 'shell_percentage' );
        if ( $shell_pct !== null ) {
            $shell_pct = max( 0, min( 100, absint( $shell_pct ) ) );
            update_option( 'bizcity_shell_percentage', $shell_pct );
            $updated['shell_percentage'] = $shell_pct;
        }

        // Shell shadow mode (S6 comparison)
        $shell_shadow = $request->get_param( 'shell_shadow' );
        if ( $shell_shadow !== null ) {
            update_option( 'bizcity_shell_shadow', (bool) $shell_shadow );
            $updated['shell_shadow'] = (bool) $shell_shadow;
        }

        // Clear cached context
        if ( ! empty( $updated ) ) {
            delete_transient( 'bizcity_intent_context' );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'updated' => $updated,
            'message' => 'Settings updated successfully.',
        ), 200 );
    }

    /**
     * GET /settings/routing — Lightweight routing priority endpoint.
     */
    public function rest_get_routing( WP_REST_Request $request ) {
        $priority = get_option( 'bizcity_tcp_routing_priority', 'balanced' );

        $descriptions = array(
            'conversation' => 'Ưu tiên cảm xúc/trò chuyện. Tool chỉ chạy khi @mention rõ ràng.',
            'balanced'     => 'AI tự phân loại. Gợi ý tool khi phù hợp.',
            'tools'        => 'Ưu tiên phát hiện & thực thi tool. Ngưỡng thấp hơn.',
        );

        return new WP_REST_Response( array(
            'routing_priority' => $priority,
            'description'      => isset( $descriptions[ $priority ] ) ? $descriptions[ $priority ] : '',
            'options'          => array(
                array( 'value' => 'conversation', 'label' => 'Trò chuyện',  'icon' => '💬' ),
                array( 'value' => 'balanced',     'label' => 'Cân bằng',    'icon' => '⚖️' ),
                array( 'value' => 'tools',        'label' => 'Công cụ',     'icon' => '🔧' ),
            ),
        ), 200 );
    }

    /**
     * GET /tools/active — Return active tools list for mobile app.
     */
    public function rest_get_active_tools( WP_REST_Request $request ) {
        global $wpdb;

        $limit = absint( $request->get_param( 'limit' ) );
        if ( $limit < 1 ) {
            $limit = 50;
        }

        $table = $wpdb->prefix . 'bizcity_tool_registry';

        // Check table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );

        if ( ! $table_exists ) {
            return new WP_REST_Response( array(
                'tools' => array(),
                'total' => 0,
            ), 200 );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, tool_name, goal, goal_label, goal_description, plugin,
                    required_slots, optional_slots, priority
             FROM {$table}
             WHERE active = 1
             ORDER BY priority ASC, id ASC
             LIMIT %d",
            $limit
        ), ARRAY_A );

        $tools = array();
        foreach ( $rows as $row ) {
            $tools[] = array(
                'id'          => (int) $row['id'],
                'tool_name'   => $row['tool_name'],
                'goal'        => $row['goal'],
                'goal_label'  => $row['goal_label'],
                'description' => $row['goal_description'],
                'plugin'      => $row['plugin'],
                'required'    => json_decode( $row['required_slots'], true ) ?: array(),
                'optional'    => json_decode( $row['optional_slots'], true ) ?: array(),
                'priority'    => (int) $row['priority'],
            );
        }

        return new WP_REST_Response( array(
            'tools' => $tools,
            'total' => count( $tools ),
        ), 200 );
    }

    /* ================================================================
     * Phase 1.11 S6/S7 — Shell Engine Cutover
     * ================================================================ */

    /**
     * GET /settings/shell — Shell Engine status dashboard.
     */
    public function rest_get_shell_status( WP_REST_Request $request ) {
        $pct    = (int) get_option( 'bizcity_shell_percentage', 0 );
        $shadow = (bool) get_option( 'bizcity_shell_shadow', false );

        // Determine phase
        if ( $pct <= 0 && ! $shadow ) {
            $phase = 'legacy';
        } elseif ( $pct <= 0 && $shadow ) {
            $phase = 'shadow';
        } elseif ( $pct < 100 ) {
            $phase = 'ab_test';
        } else {
            $phase = 'shell_full';
        }

        $phases = array(
            'legacy'     => '🔴 Legacy 100% — Shell inactive',
            'shadow'     => '🟡 Shadow — Legacy handles, Shell logs in parallel',
            'ab_test'    => "🟠 A/B Test — Shell {$pct}%, Legacy " . ( 100 - $pct ) . '%',
            'shell_full' => '🟢 Shell 100% — Legacy archived',
        );

        return new WP_REST_Response( array(
            'phase'           => $phase,
            'description'     => $phases[ $phase ] ?? '',
            'shell_percentage' => $pct,
            'shadow_enabled'  => $shadow,
            'shell_available' => class_exists( 'BizCity_Intent_Engine_Shell' ),
            'cutover_actions' => array(
                'shadow'   => 'Enable shadow comparison (0% shell, log both)',
                'ab_10'    => 'A/B test: 10% shell',
                'ab_25'    => 'A/B test: 25% shell',
                'ab_50'    => 'A/B test: 50% shell',
                'full'     => 'Full cutover: 100% shell',
                'rollback' => 'Instant rollback: 0% shell',
            ),
        ), 200 );
    }

    /**
     * POST /settings/shell/cutover — Execute cutover action.
     */
    public function rest_shell_cutover( WP_REST_Request $request ) {
        $action = $request->get_param( 'action' );

        $actions = array(
            'shadow'   => array( 'pct' => 0,   'shadow' => true  ),
            'ab_10'    => array( 'pct' => 10,  'shadow' => false ),
            'ab_25'    => array( 'pct' => 25,  'shadow' => false ),
            'ab_50'    => array( 'pct' => 50,  'shadow' => false ),
            'full'     => array( 'pct' => 100, 'shadow' => false ),
            'rollback' => array( 'pct' => 0,   'shadow' => false ),
        );

        if ( ! isset( $actions[ $action ] ) ) {
            return new WP_Error(
                'invalid_cutover_action',
                'Invalid action. Use: shadow, ab_10, ab_25, ab_50, full, rollback.',
                array( 'status' => 400 )
            );
        }

        $cfg = $actions[ $action ];
        update_option( 'bizcity_shell_percentage', $cfg['pct'] );
        update_option( 'bizcity_shell_shadow', $cfg['shadow'] );

        error_log( '[Shell:Cutover] Action=' . $action . ' → pct=' . $cfg['pct'] . ', shadow=' . ( $cfg['shadow'] ? 'ON' : 'OFF' ) );

        return new WP_REST_Response( array(
            'success'          => true,
            'action'           => $action,
            'shell_percentage' => $cfg['pct'],
            'shell_shadow'     => $cfg['shadow'],
            'message'          => 'Cutover action "' . $action . '" applied. Effect: immediate.',
        ), 200 );
    }

    /* ================================================================
     *  Admin Action: /wp-admin/admin.php?action=bizcity_shell_cutover&cutover=shadow
     *
     *  Handles cutover via admin URL (no REST nonce needed).
     *  Uses WordPress admin_action_ hook with nonce verification.
     * ================================================================ */

    /**
     * Handle shell cutover via admin action URL.
     *
     * Usage (paste in browser address bar while logged in as admin):
     *   /wp-admin/admin.php?action=bizcity_shell_cutover&cutover=shadow&_wpnonce=NONCE
     *
     * Generate link: admin_url('admin.php?action=bizcity_shell_cutover&cutover=shadow&_wpnonce=' . wp_create_nonce('bizcity_shell_cutover'))
     */
    public function admin_action_cutover() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 'Error', array( 'response' => 403 ) );
        }

        $cutover = sanitize_text_field( $_GET['cutover'] ?? '' );

        $actions = array(
            'shadow'   => array( 'pct' => 0,   'shadow' => true  ),
            'ab_10'    => array( 'pct' => 10,  'shadow' => false ),
            'ab_25'    => array( 'pct' => 25,  'shadow' => false ),
            'ab_50'    => array( 'pct' => 50,  'shadow' => false ),
            'full'     => array( 'pct' => 100, 'shadow' => false ),
            'rollback' => array( 'pct' => 0,   'shadow' => false ),
            'status'   => null,
        );

        if ( ! isset( $actions[ $cutover ] ) && $cutover !== 'status' ) {
            wp_die(
                'Invalid cutover action. Use: shadow, ab_10, ab_25, ab_50, full, rollback, status.',
                'Shell Cutover Error',
                array( 'response' => 400 )
            );
        }

        // Status-only mode
        if ( $cutover === 'status' ) {
            $pct    = (int) get_option( 'bizcity_shell_percentage', 0 );
            $shadow = (bool) get_option( 'bizcity_shell_shadow', false );
            wp_die(
                '<h2>Shell Engine Status</h2>'
                . '<p><strong>shell_percentage:</strong> ' . $pct . '%</p>'
                . '<p><strong>shell_shadow:</strong> ' . ( $shadow ? 'ON' : 'OFF' ) . '</p>'
                . '<p><a href="' . esc_url( admin_url( 'admin.php?action=bizcity_shell_cutover&cutover=shadow' ) ) . '">Activate Shadow</a>'
                . ' | <a href="' . esc_url( admin_url( 'admin.php?action=bizcity_shell_cutover&cutover=rollback' ) ) . '">Rollback</a></p>',
                'Shell Status',
                array( 'response' => 200 )
            );
        }

        $cfg = $actions[ $cutover ];
        update_option( 'bizcity_shell_percentage', $cfg['pct'] );
        update_option( 'bizcity_shell_shadow', $cfg['shadow'] );

        error_log( '[Shell:Cutover] Action=' . $cutover . ' → pct=' . $cfg['pct'] . ', shadow=' . ( $cfg['shadow'] ? 'ON' : 'OFF' ) . ' by user=' . get_current_user_id() );

        wp_die(
            '<h2>Shell Cutover: ' . esc_html( $cutover ) . '</h2>'
            . '<p><strong>shell_percentage:</strong> ' . $cfg['pct'] . '%</p>'
            . '<p><strong>shell_shadow:</strong> ' . ( $cfg['shadow'] ? 'ON' : 'OFF' ) . '</p>'
            . '<p>Effect: immediate.</p>'
            . '<p><a href="' . esc_url( admin_url( 'admin.php?action=bizcity_shell_cutover&cutover=status' ) ) . '">← View Status</a></p>',
            'Shell Cutover Applied',
            array( 'response' => 200 )
        );
    }
}
