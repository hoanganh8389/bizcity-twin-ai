<?php
/**
 * REST API endpoints + usage logging.
 *
 * Namespace: bizgpt-google/v1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BZGoogle_REST_API {

    const NAMESPACE = 'bizgpt-google/v1';

    public static function register_routes() {

        /* ── Account management ────────────────────────── */
        register_rest_route( self::NAMESPACE, '/accounts', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_accounts' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );

        register_rest_route( self::NAMESPACE, '/accounts/(?P<id>\d+)/disconnect', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'disconnect_account' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );

        /* ── Gmail ─────────────────────────────────────── */
        register_rest_route( self::NAMESPACE, '/gmail/list', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'gmail_list' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );

        register_rest_route( self::NAMESPACE, '/gmail/send', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'gmail_send' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );

        register_rest_route( self::NAMESPACE, '/gmail/(?P<id>[a-zA-Z0-9]+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'gmail_get' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );

        /* ── Calendar ──────────────────────────────────── */
        register_rest_route( self::NAMESPACE, '/calendar/events', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'calendar_list' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );

        register_rest_route( self::NAMESPACE, '/calendar/events', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'calendar_create' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );

        /* ── Drive ─────────────────────────────────────── */
        register_rest_route( self::NAMESPACE, '/drive/files', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'drive_list' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );

        /* ── Contacts ──────────────────────────────────── */
        register_rest_route( self::NAMESPACE, '/contacts', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'contacts_list' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );

        /* ── Hub admin (network admin only) ────────────── */
        register_rest_route( self::NAMESPACE, '/hub/stats', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'hub_stats' ],
            'permission_callback' => [ __CLASS__, 'check_admin' ],
        ] );

        /* ── Usage history (per user) ──────────────────── */
        register_rest_route( self::NAMESPACE, '/history', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_history' ],
            'permission_callback' => [ __CLASS__, 'check_logged_in' ],
        ] );
    }

    /* ── Permission callbacks ──────────────────────────────── */

    public static function check_logged_in() {
        return is_user_logged_in();
    }

    public static function check_admin() {
        return current_user_can( 'manage_options' );
    }

    /* ── Account endpoints ─────────────────────────────────── */

    public static function get_accounts( $request ) {
        $accounts = BZGoogle_Token_Store::get_accounts( get_current_blog_id(), get_current_user_id() );
        return rest_ensure_response( $accounts );
    }

    public static function disconnect_account( $request ) {
        $id = absint( $request['id'] );
        BZGoogle_Token_Store::disconnect( $id, get_current_user_id() );
        return rest_ensure_response( [ 'success' => true ] );
    }

    /* ── Gmail endpoints ───────────────────────────────────── */

    public static function gmail_list( $request ) {
        $blog_id = get_current_blog_id();
        $user_id = get_current_user_id();

        $result = BZGoogle_Google_Service::gmail_list( $blog_id, $user_id, [
            'max_results' => $request->get_param( 'max_results' ) ?: 10,
            'query'       => $request->get_param( 'query' ) ?: '',
        ] );

        self::log_usage( $blog_id, $user_id, 'gmail', 'list', '', is_wp_error( $result ) ? 'error' : 'success' );
        return self::respond( $result );
    }

    public static function gmail_send( $request ) {
        $blog_id = get_current_blog_id();
        $user_id = get_current_user_id();

        $result = BZGoogle_Google_Service::gmail_send( $blog_id, $user_id, [
            'to'      => $request->get_param( 'to' ),
            'subject' => $request->get_param( 'subject' ),
            'body'    => $request->get_param( 'body' ),
        ] );

        self::log_usage( $blog_id, $user_id, 'gmail', 'send', $request->get_param( 'to' ), is_wp_error( $result ) ? 'error' : 'success' );
        return self::respond( $result );
    }

    public static function gmail_get( $request ) {
        $blog_id = get_current_blog_id();
        $user_id = get_current_user_id();
        $msg_id  = sanitize_text_field( $request['id'] );

        $result = BZGoogle_Google_Service::gmail_get( $blog_id, $user_id, $msg_id );
        self::log_usage( $blog_id, $user_id, 'gmail', 'get', $msg_id, is_wp_error( $result ) ? 'error' : 'success' );
        return self::respond( $result );
    }

    /* ── Calendar endpoints ────────────────────────────────── */

    public static function calendar_list( $request ) {
        $blog_id = get_current_blog_id();
        $user_id = get_current_user_id();

        $result = BZGoogle_Google_Service::calendar_list( $blog_id, $user_id, [
            'time_min'    => $request->get_param( 'time_min' ) ?: '',
            'time_max'    => $request->get_param( 'time_max' ) ?: '',
            'max_results' => $request->get_param( 'max_results' ) ?: 10,
        ] );

        self::log_usage( $blog_id, $user_id, 'calendar', 'list', '', is_wp_error( $result ) ? 'error' : 'success' );
        return self::respond( $result );
    }

    public static function calendar_create( $request ) {
        $blog_id = get_current_blog_id();
        $user_id = get_current_user_id();

        $result = BZGoogle_Google_Service::calendar_create( $blog_id, $user_id, [
            'title'       => $request->get_param( 'title' ),
            'start_time'  => $request->get_param( 'start_time' ),
            'end_time'    => $request->get_param( 'end_time' ),
            'description' => $request->get_param( 'description' ) ?: '',
            'attendees'   => $request->get_param( 'attendees' ) ?: '',
        ] );

        self::log_usage( $blog_id, $user_id, 'calendar', 'create', $request->get_param( 'title' ), is_wp_error( $result ) ? 'error' : 'success' );
        return self::respond( $result );
    }

    /* ── Drive endpoints ───────────────────────────────────── */

    public static function drive_list( $request ) {
        $blog_id = get_current_blog_id();
        $user_id = get_current_user_id();

        $result = BZGoogle_Google_Service::drive_list( $blog_id, $user_id, [
            'query'       => $request->get_param( 'query' ) ?: '',
            'max_results' => $request->get_param( 'max_results' ) ?: 10,
        ] );

        self::log_usage( $blog_id, $user_id, 'drive', 'list', '', is_wp_error( $result ) ? 'error' : 'success' );
        return self::respond( $result );
    }

    /* ── Contacts endpoints ────────────────────────────────── */

    public static function contacts_list( $request ) {
        $blog_id = get_current_blog_id();
        $user_id = get_current_user_id();

        $result = BZGoogle_Google_Service::contacts_list( $blog_id, $user_id, [
            'max_results' => $request->get_param( 'max_results' ) ?: 20,
            'query'       => $request->get_param( 'query' ) ?: '',
        ] );

        self::log_usage( $blog_id, $user_id, 'contacts', 'list', '', is_wp_error( $result ) ? 'error' : 'success' );
        return self::respond( $result );
    }

    /* ── Hub stats (admin) ─────────────────────────────────── */

    public static function hub_stats( $request ) {
        global $wpdb;
        $t_acc  = BZGoogle_Installer::table_accounts();
        $t_logs = BZGoogle_Installer::table_logs();

        $total_accounts = $wpdb->get_var( "SELECT COUNT(*) FROM {$t_acc} WHERE status = 'active'" );
        $total_sites    = $wpdb->get_var( "SELECT COUNT(DISTINCT blog_id) FROM {$t_acc} WHERE status = 'active'" );
        $total_users    = $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$t_acc} WHERE status = 'active'" );
        $today_calls    = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_logs} WHERE created_at >= %s",
            gmdate( 'Y-m-d 00:00:00' )
        ) );

        return rest_ensure_response( [
            'total_accounts'  => (int) $total_accounts,
            'total_sites'     => (int) $total_sites,
            'total_users'     => (int) $total_users,
            'today_api_calls' => (int) $today_calls,
        ] );
    }

    /* ── Helpers ───────────────────────────────────────────── */

    private static function respond( $result ) {
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'error'   => $result->get_error_message(),
            ], 400 );
        }
        return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
    }

    /* ── Usage history ─────────────────────────────────── */

    public static function get_history( $request ) {
        global $wpdb;
        $table   = BZGoogle_Installer::table_logs();
        $blog_id = get_current_blog_id();
        $user_id = get_current_user_id();
        $limit   = min( absint( $request->get_param( 'limit' ) ?: 50 ), 200 );
        $offset  = absint( $request->get_param( 'offset' ) ?: 0 );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT service, action, request_summary, response_status, created_at
             FROM {$table}
             WHERE blog_id = %d AND user_id = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $blog_id, $user_id, $limit, $offset
        ) );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND user_id = %d",
            $blog_id, $user_id
        ) );

        return rest_ensure_response( [
            'items' => $rows ?: [],
            'total' => $total,
        ] );
    }

    /**
     * Log a usage event.
     */
    public static function log_usage( $blog_id, $user_id, $service, $action, $summary = '', $status = 'success' ) {
        global $wpdb;
        $wpdb->insert( BZGoogle_Installer::table_logs(), [
            'blog_id'         => $blog_id,
            'user_id'         => $user_id,
            'service'         => $service,
            'action'          => $action,
            'request_summary' => mb_substr( $summary, 0, 500 ),
            'response_status' => $status,
        ] );
    }
}
