<?php
/**
 * Central Facebook Webhook Router
 *
 * Endpoint: /facehook/ (hoặc ?facehook=1)
 * Mục đích: Single webhook URL cho toàn bộ WordPress multisite.
 *
 * Flow:
 *   1. Facebook App chỉ cần đăng ký 1 webhook URL: bizcity.vn/facehook/
 *   2. Khi Facebook gửi webhook (message/comment), router parse page_id
 *   3. Lookup bảng bizcity_facebook_page_routes → tìm blog_id tương ứng
 *   4. switch_to_blog($blog_id) → xử lý webhook như bình thường trên subsite đó
 *   5. Nếu không tìm thấy route → fallback xử lý trên main site
 *
 * Bảng route (GLOBAL DB — routed bởi db.php):
 *   wp_bizcity_facebook_page_routes: page_id → blog_id, access_token, page_name
 *   Khai báo trong db.php global_tables → luôn query trên global DB.
 *
 * Subsite registration:
 *   Khi subsite kết nối Page qua bizcity-tool-facebook plugin,
 *   tự động ghi vào bảng route trên main site.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Facebook_Central_Webhook {

    private static $instance = null;

    const DEFAULT_VERIFY_TOKEN = 'bizgpt';

    /**
     * Get verify token (from network option or fallback to default).
     */
    private function get_verify_token(): string {
        $token = get_site_option( 'bizcity_fb_verify_token', '' );
        return ! empty( $token ) ? $token : self::DEFAULT_VERIFY_TOKEN;
    }

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Register rewrite rule for /facehook/
        add_action( 'init', array( $this, 'register_rewrite_rules' ), 0 );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_facehook_rewrite' ), 0 );

        // Also handle ?facehook=1 query param (fallback)
        add_action( 'init', array( $this, 'handle_facehook_query' ), 0 );

        // Create route table on activation
        add_action( 'init', array( $this, 'maybe_create_route_table' ), 5 );
    }

    /**
     * Register /facehook/ rewrite rule.
     */
    public function register_rewrite_rules() {
        add_rewrite_rule( '^facehook/?$', 'index.php?facehook_route=1', 'top' );
    }

    /**
     * Add custom query var.
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'facehook_route';
        return $vars;
    }

    /**
     * Handle /facehook/ via rewrite rule.
     */
    public function handle_facehook_rewrite() {
        if ( get_query_var( 'facehook_route' ) !== '1' ) {
            return;
        }
        $this->process_central_webhook();
    }

    /**
     * Handle ?facehook=1 query param (fallback for non-pretty-permalink setups).
     */
    public function handle_facehook_query() {
        if ( ! isset( $_GET['facehook'] ) || (string) $_GET['facehook'] !== '1' ) {
            return;
        }
        $this->process_central_webhook();
    }

    /**
     * Main webhook processing logic.
     */
    private function process_central_webhook() {
        // Disable cache
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEDB' ) ) define( 'DONOTCACHEDB', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );

        $this->log_info( 'Central webhook hit', array(
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'host'   => $_SERVER['HTTP_HOST'] ?? '',
        ) );

        $method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );

        if ( $method === 'GET' ) {
            $this->verify_webhook();
            exit;
        }

        if ( $method === 'POST' ) {
            $this->route_webhook();
            exit;
        }

        http_response_code( 405 );
        echo 'Method Not Allowed';
        exit;
    }

    /**
     * Verify webhook subscription (same as standard Facebook verification).
     */
    private function verify_webhook() {
        $mode      = (string) ( $_GET['hub_mode'] ?? ( $_GET['hub.mode'] ?? '' ) );
        $token     = (string) ( $_GET['hub_verify_token'] ?? ( $_GET['hub.verify_token'] ?? '' ) );
        $challenge = (string) ( $_GET['hub_challenge'] ?? ( $_GET['hub.challenge'] ?? '' ) );

        $this->log_info( 'Central verify attempt', array( 'mode' => $mode, 'token' => $token ) );

        if ( $mode === 'subscribe' && hash_equals( $this->get_verify_token(), $token ) && $challenge !== '' ) {
            while ( ob_get_level() ) ob_end_clean();

            http_response_code( 200 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            echo $challenge;

            $this->log_info( 'Central verify SUCCESS' );
            exit;
        }

        $this->log_error( 'Central verify FAILED' );
        http_response_code( 403 );
        echo 'Forbidden';
        exit;
    }

    /**
     * Route incoming webhook data to the correct blog/subsite.
     *
     * Strategy:
     *   1. Parse all entries from Facebook webhook payload
     *   2. Group entries by page_id
     *   3. For each page_id, lookup the associated blog_id
     *   4. switch_to_blog($blog_id) and process the entries there
     */
    private function route_webhook() {
        $input = file_get_contents( 'php://input' );
        $this->log_info( 'Central webhook POST received', array( 'length' => strlen( $input ) ) );

        if ( empty( $input ) ) {
            status_header( 200 );
            echo 'OK';
            exit;
        }

        $data = json_decode( $input, true );
        if ( ! is_array( $data ) || empty( $data['entry'] ) ) {
            status_header( 200 );
            echo 'OK';
            exit;
        }

        // Group entries by page_id
        $entries_by_page = array();
        foreach ( $data['entry'] as $entry ) {
            $page_id = $entry['id'] ?? '';
            if ( empty( $page_id ) ) continue;
            $entries_by_page[ $page_id ][] = $entry;
        }

        $this->log_info( 'Routing webhook', array(
            'page_ids'    => array_keys( $entries_by_page ),
            'entry_count' => count( $data['entry'] ),
        ) );

        // Process each page_id on its corresponding blog
        foreach ( $entries_by_page as $page_id => $entries ) {
            $route = $this->get_route( $page_id );

            if ( $route ) {
                $target_blog_id = (int) $route->blog_id;
                $this->log_info( "Routing page {$page_id} to blog {$target_blog_id}" );

                // Switch to target blog and process
                if ( is_multisite() && $target_blog_id !== get_current_blog_id() ) {
                    switch_to_blog( $target_blog_id );
                }

                $this->process_entries( $page_id, $entries );

                if ( is_multisite() && ms_is_switched() ) {
                    restore_current_blog();
                }
            } else {
                // No route found — process on current (main) site
                $this->log_info( "No route for page {$page_id}, processing on main site" );
                $this->process_entries( $page_id, $entries );
            }
        }

        status_header( 200 );
        echo 'OK';
        exit;
    }

    /**
     * Process webhook entries for a specific page_id.
     * This delegates to the existing BizCity_Facebook_Bot_Webhook_Handler methods.
     */
    private function process_entries( string $page_id, array $entries ) {
        // If the existing webhook handler class is available, delegate to it
        if ( class_exists( 'BizCity_Facebook_Bot_Webhook_Handler' ) ) {
            $handler = BizCity_Facebook_Bot_Webhook_Handler::instance();

            foreach ( $entries as $entry ) {
                // Handle messaging events
                if ( isset( $entry['messaging'] ) && is_array( $entry['messaging'] ) ) {
                    foreach ( $entry['messaging'] as $messaging ) {
                        // Use reflection or public method to handle messages
                        if ( method_exists( $handler, 'handle_webhook_entry_messaging' ) ) {
                            $handler->handle_webhook_entry_messaging( $page_id, $messaging );
                        } else {
                            // Fire action for other handlers to pick up
                            do_action( 'bizcity_facebook_central_messaging', $page_id, $messaging );
                        }
                    }
                }

                // Handle feed/comment events
                if ( isset( $entry['changes'] ) && is_array( $entry['changes'] ) ) {
                    foreach ( $entry['changes'] as $change ) {
                        if ( method_exists( $handler, 'handle_webhook_entry_change' ) ) {
                            $handler->handle_webhook_entry_change( $page_id, $change );
                        } else {
                            do_action( 'bizcity_facebook_central_change', $page_id, $change );
                        }
                    }
                }
            }

            return;
        }

        // Fallback: fire generic hooks for any listener
        foreach ( $entries as $entry ) {
            if ( isset( $entry['messaging'] ) && is_array( $entry['messaging'] ) ) {
                foreach ( $entry['messaging'] as $messaging ) {
                    do_action( 'bizcity_facebook_central_messaging', $page_id, $messaging );
                }
            }
            if ( isset( $entry['changes'] ) && is_array( $entry['changes'] ) ) {
                foreach ( $entry['changes'] as $change ) {
                    do_action( 'bizcity_facebook_central_change', $page_id, $change );
                }
            }
        }
    }

    /**
     * Get route for a Facebook page_id.
     * Table is global (registered in db.php global_tables) — no switch_to_blog needed.
     *
     * @param string $page_id Facebook Page ID
     * @return object|null Route object with blog_id, page_name, access_token
     */
    private function get_route( string $page_id ) {
        global $wpdb;
        $table = $wpdb->base_prefix . 'bizcity_facebook_page_routes';

        // Suppress DB error when table missing on shard — we silently fall back
        // to main-site processing and let maybe_create_route_table() retry on next init.
        $prev_suppress = $wpdb->suppress_errors( true );
        $prev_show     = $wpdb->show_errors( false );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE page_id = %s AND status = 'active' LIMIT 1",
            $page_id
        ) );

        $wpdb->suppress_errors( $prev_suppress );
        $wpdb->show_errors( $prev_show );

        // If query failed because table missing, force-recreate next request.
        if ( $row === null && ! empty( $wpdb->last_error ) && false !== stripos( $wpdb->last_error, "doesn't exist" ) ) {
            delete_site_option( 'bizcity_facebook_route_table_version' );
            $wpdb->last_error = '';
        }

        return $row;
    }

    /**
     * Create the global route table (once).
     * Table is in global DB via db.php global_tables registration.
     */
    public function maybe_create_route_table() {
        $version_key = 'bizcity_facebook_route_table_version';
        $current_version = '1.2.0'; // bumped: force re-create when table is missing on shard.

        if ( get_site_option( $version_key ) === $current_version ) {
            return;
        }

        global $wpdb;
        $table   = $wpdb->base_prefix . 'bizcity_facebook_page_routes';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            page_id       VARCHAR(100) NOT NULL COMMENT 'Facebook Page ID',
            blog_id       BIGINT(20) UNSIGNED NOT NULL COMMENT 'WordPress blog/site ID',
            page_name     VARCHAR(255) NOT NULL DEFAULT '',
            access_token  TEXT NOT NULL COMMENT 'Page Access Token',
            status        VARCHAR(20) NOT NULL DEFAULT 'active',
            registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_page_id (page_id),
            KEY idx_blog_id (blog_id),
            KEY idx_status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_site_option( $version_key, $current_version );
        $this->log_info( 'Route table created/updated (global)' );
    }

    /**
     * Static helper: Register a page route.
     * Table is global (db.php) — no switch_to_blog needed.
     */
    public static function register_route( string $page_id, int $blog_id, string $page_name, string $access_token ): bool {
        global $wpdb;
        $table = $wpdb->base_prefix . 'bizcity_facebook_page_routes';

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE page_id = %s",
            $page_id
        ) );

        if ( $existing ) {
            $result = $wpdb->update( $table, array(
                'blog_id'      => $blog_id,
                'page_name'    => $page_name,
                'access_token' => $access_token,
                'status'       => 'active',
            ), array( 'page_id' => $page_id ) );
        } else {
            $result = $wpdb->insert( $table, array(
                'page_id'      => $page_id,
                'blog_id'      => $blog_id,
                'page_name'    => $page_name,
                'access_token' => $access_token,
                'status'       => 'active',
            ) );
        }

        return $result !== false;
    }

    /**
     * Static helper: Unregister a page route.
     * Table is global (db.php) — no switch_to_blog needed.
     */
    public static function unregister_route( string $page_id ): bool {
        global $wpdb;
        $table = $wpdb->base_prefix . 'bizcity_facebook_page_routes';
        $result = $wpdb->delete( $table, array( 'page_id' => $page_id ) );

        return $result !== false;
    }

    /**
     * Static helper: Get all routes (for admin display).
     * Table is global (db.php) — no switch_to_blog needed.
     */
    public static function get_all_routes(): array {
        global $wpdb;
        $table = $wpdb->base_prefix . 'bizcity_facebook_page_routes';

        $routes = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY registered_at DESC" );
        return $routes ?: array();
    }

    /**
     * Static helper: Get all routes for a specific blog.
     */
    public static function get_routes_by_blog( int $blog_id ): array {
        global $wpdb;
        $table = $wpdb->base_prefix . 'bizcity_facebook_page_routes';

        $routes = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE blog_id = %d ORDER BY registered_at DESC",
            $blog_id
        ) );
        return $routes ?: array();
    }

    // ==========================================
    // HUB SITE HELPERS
    // ==========================================

    /**
     * Get the Hub Blog ID — the site that acts as the Facebook gateway.
     * Default: main site. Override via Network Admin → Facebook Central.
     *
     * @return int Blog ID
     */
    public static function get_hub_blog_id(): int {
        $hub = (int) get_site_option( 'bizcity_fb_hub_blog_id', 0 );
        return $hub > 0 ? $hub : get_main_site_id();
    }

    /**
     * Get the Hub Site URL (for webhook & OAuth redirect_uri).
     *
     * @return string URL like https://bizcity.vn
     */
    public static function get_hub_site_url(): string {
        return get_site_url( self::get_hub_blog_id() );
    }

    /**
     * Get the Central Webhook URL served by the Hub Site.
     *
     * @return string URL like https://bizcity.vn/facehook/
     */
    public static function get_webhook_url(): string {
        return trailingslashit( self::get_hub_site_url() ) . 'facehook/';
    }

    // ==========================================
    // LOGGING
    // ==========================================

    private function log_error( $message, $data = null ) {
        $this->write_log( 'error', array( 'message' => $message, 'data' => $data ) );
    }

    private function log_info( $message, $data = null ) {
        $this->write_log( 'info', array( 'message' => $message, 'data' => $data ) );
    }

    private function write_log( $type, $data ) {
        $log_dir = WP_CONTENT_DIR . '/mu-plugins/logs';
        if ( ! file_exists( $log_dir ) ) {
            @mkdir( $log_dir, 0755, true );
        }

        $date_str = gmdate( 'Y-m-d' );
        $time_str = gmdate( 'H:i:s' );
        $blog_id  = get_current_blog_id();

        $log_file = $log_dir . "/facehook-{$date_str}.log";
        $log_entry = array(
            'time'    => $time_str,
            'blog_id' => $blog_id,
            'type'    => $type,
            'data'    => $data,
        );

        @file_put_contents(
            $log_file,
            json_encode( $log_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

// Initialize
BizCity_Facebook_Central_Webhook::instance();
