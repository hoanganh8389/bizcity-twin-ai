<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Scheduler — REST API
 *
 * Namespace: bizcity-scheduler/v1
 *
 * Endpoints:
 *   GET    /events          — List events (from, to, status)
 *   POST   /events          — Create event
 *   PATCH  /events/(?P<id>) — Update event
 *   DELETE /events/(?P<id>) — Delete event
 *   POST   /events/quick    — Quick-add from natural text (AI parse)
 *   GET    /today           — Today's events for current user
 *   GET    /google/status   — Google Calendar connection status
 *   POST   /google/sync     — Trigger Google Calendar sync
 *
 * @package  BizCity_Scheduler
 * @since    2026-04-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Scheduler_REST_API {

	const API_NAMESPACE = 'bizcity-scheduler/v1';

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {

		// GET /events — list events for date range
		register_rest_route( self::API_NAMESPACE, '/events', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_events' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'from'   => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				'to'     => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				'status' => [ 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		// POST /events — create event
		register_rest_route( self::API_NAMESPACE, '/events', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_event' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// PATCH /events/<id> — update event
		register_rest_route( self::API_NAMESPACE, '/events/(?P<id>\d+)', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'update_event' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// DELETE /events/<id> — delete event
		register_rest_route( self::API_NAMESPACE, '/events/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_event' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// POST /events/quick — natural language quick-add
		register_rest_route( self::API_NAMESPACE, '/events/quick', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'quick_add' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// GET /today — today's events
		register_rest_route( self::API_NAMESPACE, '/today', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'today_events' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// GET /google/status — connection status (any logged-in user can check)
		register_rest_route( self::API_NAMESPACE, '/google/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'google_status' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// POST /google/sync — manual sync (any logged-in user can trigger)
		register_rest_route( self::API_NAMESPACE, '/google/sync', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'google_sync' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( self::API_NAMESPACE, '/google/settings', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'google_settings' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		register_rest_route( self::API_NAMESPACE, '/google/settings', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'save_google_settings' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		register_rest_route( self::API_NAMESPACE, '/google/disconnect', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'google_disconnect' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// GET /google/callback — OAuth callback target
		register_rest_route( self::API_NAMESPACE, '/google/callback', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'google_callback' ],
			'permission_callback' => '__return_true',
		] );
	}

	/* ================================================================
	 *  Callbacks
	 * ================================================================ */

	public function list_events( \WP_REST_Request $req ): \WP_REST_Response {
		$mgr    = BizCity_Scheduler_Manager::instance();
		$events = $mgr->get_events(
			get_current_user_id(),
			$req->get_param( 'from' ),
			$req->get_param( 'to' ),
			$req->get_param( 'status' ) ?: 'all'
		);

		return new \WP_REST_Response( [ 'events' => $events ], 200 );
	}

	public function create_event( \WP_REST_Request $req ): \WP_REST_Response {
		$mgr    = BizCity_Scheduler_Manager::instance();
		$data   = is_array( $req->get_json_params() ) ? $req->get_json_params() : [];
		$data['user_id'] = get_current_user_id();
		$result = $mgr->create_event( $data );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
		}

		$event = $mgr->get_event( $result );
		return new \WP_REST_Response( [ 'event' => $event ], 201 );
	}

	public function update_event( \WP_REST_Request $req ): \WP_REST_Response {
		$mgr    = BizCity_Scheduler_Manager::instance();
		$id     = (int) $req->get_param( 'id' );
		$data   = $req->get_json_params();

		$current_uid = get_current_user_id();

		// Single query with user_id filter — no separate lookup needed
		$event = $mgr->get_event( $id, $current_uid );
		if ( ! $event ) {
			return new \WP_REST_Response( [ 'error' => 'Not found or forbidden.' ], 404 );
		}

		$result = $mgr->update_event( $id, $data, $current_uid );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
		}

		$event = $mgr->get_event( $id, $current_uid );
		return new \WP_REST_Response( [ 'event' => $event ], 200 );
	}

	public function delete_event( \WP_REST_Request $req ): \WP_REST_Response {
		$mgr         = BizCity_Scheduler_Manager::instance();
		$id          = (int) $req->get_param( 'id' );
		$current_uid = get_current_user_id();

		// Single query with user_id filter
		$event = $mgr->get_event( $id, $current_uid );
		if ( ! $event ) {
			return new \WP_REST_Response( [ 'error' => 'Not found or forbidden.' ], 404 );
		}

		$result = $mgr->delete_event( $id, $current_uid );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
		}

		return new \WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/**
	 * Quick-add: parse natural language text into an event.
	 *
	 * Integration point: uses LLM Router to extract structured data.
	 * Falls back to simple title + now+1h if LLM unavailable.
	 */
	public function quick_add( \WP_REST_Request $req ): \WP_REST_Response {
		$text = sanitize_text_field( $req->get_param( 'text' ) ?? '' );
		if ( empty( $text ) ) {
			return new \WP_REST_Response( [ 'error' => 'Text is required.' ], 400 );
		}

		$parsed = $this->parse_quick_text( $text );
		$parsed['user_id'] = get_current_user_id();
		$mgr    = BizCity_Scheduler_Manager::instance();
		$result = $mgr->create_event( $parsed );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
		}

		$event = $mgr->get_event( $result );
		return new \WP_REST_Response( [ 'event' => $event, 'parsed' => $parsed ], 201 );
	}

	public function today_events( \WP_REST_Request $req ): \WP_REST_Response {
		$mgr    = BizCity_Scheduler_Manager::instance();
		$events = $mgr->get_today_events( get_current_user_id() );
		return new \WP_REST_Response( [ 'events' => $events ], 200 );
	}

	public function google_status( \WP_REST_Request $req ): \WP_REST_Response {
		$google = BizCity_Scheduler_Google::instance();
		return new \WP_REST_Response( $google->get_connection_status(), 200 );
	}

	public function google_sync( \WP_REST_Request $req ): \WP_REST_Response {
		$google = BizCity_Scheduler_Google::instance();

		if ( ! $google->is_connected() ) {
			return new \WP_REST_Response( [ 'error' => 'Google Calendar chưa kết nối. Vui lòng vào cài đặt và cấp lại quyền.', 'error_code' => 'not_connected' ], 400 );
		}

		$result = $google->sync_from_google( get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$msg  = $result->get_error_message();
			// Token error → advise re-auth
			if ( in_array( $code, [ 'token_refresh_failed', 'no_refresh_token', 'not_connected' ], true ) ) {
				$msg .= ' — Vui lòng vào Cài đặt Google Calendar và kết nối lại.';
			}
			return new \WP_REST_Response( [ 'error' => $msg, 'error_code' => $code ], 400 );
		}

		return new \WP_REST_Response( [ 'synced' => $result ], 200 );
	}

	public function google_settings( \WP_REST_Request $req ): \WP_REST_Response {
		$google = BizCity_Scheduler_Google::instance();
		return new \WP_REST_Response( $google->get_settings_payload(), 200 );
	}

	public function save_google_settings( \WP_REST_Request $req ): \WP_REST_Response {
		$google = BizCity_Scheduler_Google::instance();
		$data   = is_array( $req->get_json_params() ) ? $req->get_json_params() : [];
		return new \WP_REST_Response( $google->save_settings( $data ), 200 );
	}

	public function google_disconnect( \WP_REST_Request $req ): \WP_REST_Response {
		$google = BizCity_Scheduler_Google::instance();
		return new \WP_REST_Response( $google->disconnect(), 200 );
	}

	public function google_callback( \WP_REST_Request $req ) {
		$google = BizCity_Scheduler_Google::instance();
		$result = $google->handle_oauth_callback( $req );
		$target = admin_url( 'admin.php?page=bizcity-scheduler' );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'google_error', rawurlencode( $result->get_error_message() ), $target ) );
			exit;
		}

		if ( ! empty( $result['redirect_to'] ) ) {
			$target = $result['redirect_to'];
		}

		wp_safe_redirect( add_query_arg( 'google_connected', '1', $target ) );
		exit;
	}

	/* ================================================================
	 *  Permission Callbacks
	 * ================================================================ */

	public function check_logged_in(): bool {
		return is_user_logged_in();
	}

	public function check_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/* ================================================================
	 *  Quick-Add Parser
	 * ================================================================ */

	/**
	 * Parse natural language into event fields.
	 *
	 * Phase 1 (P0): simple fallback — title = text, start = now+1h.
	 * Phase 4 (P4): LLM Router parses "Họp team 3h chiều mai" → structured data.
	 *
	 * Extension: apply_filters('bizcity_scheduler_parse_quick') lets other
	 * modules (Intent, LLM) override with smarter parsing.
	 */
	private function parse_quick_text( string $text ): array {
		$now = current_time( 'Y-m-d H:i:s' );

		// Default fallback: event in 1 hour, duration 1 hour
		$start = date( 'Y-m-d H:i:s', strtotime( '+1 hour', strtotime( $now ) ) );
		$end   = date( 'Y-m-d H:i:s', strtotime( '+2 hours', strtotime( $now ) ) );

		$parsed = [
			'title'    => $text,
			'start_at' => $start,
			'end_at'   => $end,
			'source'   => 'user',
		];

		/**
		 * Filter: let LLM Router or Intent module provide smarter parsing.
		 *
		 * @param array  $parsed  Default parsed result.
		 * @param string $text    Original input text.
		 * @return array  Parsed event data.
		 */
		return apply_filters( 'bizcity_scheduler_parse_quick', $parsed, $text );
	}
}
