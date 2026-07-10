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

		// GET /google/accounts — list user's Google accounts (BZGoogle Hub + legacy fallback)
		register_rest_route( self::API_NAMESPACE, '/google/accounts', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'google_accounts' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// POST /google/sync — manual sync (any logged-in user can trigger)
		register_rest_route( self::API_NAMESPACE, '/google/sync', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'google_sync' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'account_id' => [ 'type' => 'integer', 'required' => false ],
			],
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

		/* ── Automation Lab (Phase 0.37 — admin QA harness) ─────────── */

		// POST /automation/fire-now — bypass cron, dispatch reminder_fire immediately
		register_rest_route( self::API_NAMESPACE, '/automation/fire-now', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'automation_fire_now' ],
			'permission_callback' => [ $this, 'check_admin' ],
			'args'                => [
				'event_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		// GET /automation/recent — pull last N automation chain runs from cron meta
		register_rest_route( self::API_NAMESPACE, '/automation/recent', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'automation_recent' ],
			'permission_callback' => [ $this, 'check_admin' ],
			'args'                => [
				'limit' => [ 'type' => 'integer', 'default' => 20 ],
			],
		] );

		// POST /automation/validate — lint a chain JSON before saving
		register_rest_route( self::API_NAMESPACE, '/automation/validate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'automation_validate' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// GET /automation/tools — discover registered tool names + required slots
		register_rest_route( self::API_NAMESPACE, '/automation/tools', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'automation_tools' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// [2026-06-03 Johnny Chu] SCH-NC W7 — Stats dashboard endpoint.
		// GET /stats?from=&to=&scope=user|site → counters by status / type / source.
		register_rest_route( self::API_NAMESPACE, '/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'stats' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'from'  => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
				'to'    => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
				'scope' => [ 'type' => 'string', 'required' => false, 'default' => 'user', 'enum' => [ 'user', 'site' ] ],
			],
		] );

		// [2026-06-15 Johnny Chu] R-UNIFY — notify channel binding per user.
		// GET  /me/notify-channel  → trả về kênh thông báo mặc định của current user.
		// PUT  /me/notify-channel  → lưu kênh thông báo mặc định cho current user.
		// Dùng để admin bind Zalo Bot chat_id cho reminder_personal.
		register_rest_route( self::API_NAMESPACE, '/me/notify-channel', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_notify_channel' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
		register_rest_route( self::API_NAMESPACE, '/me/notify-channel', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'set_notify_channel' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		// [2026-06-15 Johnny Chu] R-UNIFY — quick reminder_personal creation.
		// POST /events/reminders  → tạo reminder_personal event nhanh (không cần JSON phức tạp).
		register_rest_route( self::API_NAMESPACE, '/events/reminders', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_reminder' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
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
			$req->get_param( 'status' ) ?: 'all',
			$req->get_param( 'event_type' ) ?: ''
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
		$google  = BizCity_Scheduler_Google::instance();
		$user_id = get_current_user_id();

		$status            = $google->get_connection_status();
		$status['accounts'] = $google->list_user_accounts( $user_id );
		$status['bzgoogle_available'] = $google->bzgoogle_available();
		$status['connected_for_user'] = $google->is_connected_for_user( $user_id );

		return new \WP_REST_Response( $status, 200 );
	}

	public function google_accounts( \WP_REST_Request $req ): \WP_REST_Response {
		$google = BizCity_Scheduler_Google::instance();
		return new \WP_REST_Response( [
			'accounts'           => $google->list_user_accounts( get_current_user_id() ),
			'bzgoogle_available' => $google->bzgoogle_available(),
		], 200 );
	}

	public function google_sync( \WP_REST_Request $req ): \WP_REST_Response {
		$google     = BizCity_Scheduler_Google::instance();
		$user_id    = get_current_user_id();
		$account_id = $req->get_param( 'account_id' );
		$account_id = $account_id ? (int) $account_id : null;

		if ( ! $google->is_connected_for_user( $user_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Google Calendar chưa kết nối. Vui lòng vào cài đặt và cấp lại quyền.', 'error_code' => 'not_connected' ], 400 );
		}

		$result = $google->sync_from_google( $user_id, $account_id );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$msg  = $result->get_error_message();
			// Token error → advise re-auth
			if ( in_array( $code, [ 'token_refresh_failed', 'no_refresh_token', 'not_connected', 'bzgoogle_no_token', 'bzgoogle_account_not_found' ], true ) ) {
				$msg .= ' — Vui lòng vào Cài đặt Google Calendar và kết nối lại.';
			}
			return new \WP_REST_Response( [ 'error' => $msg, 'error_code' => $code ], 400 );
		}

		return new \WP_REST_Response( [ 'synced' => $result, 'account_id' => $account_id ], 200 );
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
	 *  Stats (SCH-NC W7)
	 * ================================================================ */

	/**
	 * GET /stats — dashboard counters.
	 *
	 * @param \WP_REST_Request $req
	 * @return \WP_REST_Response
	 */
	public function stats( \WP_REST_Request $req ) {
		// [2026-06-03 Johnny Chu] SCH-NC W7 — counters dashboard endpoint.
		global $wpdb;
		$mgr = BizCity_Scheduler_Manager::instance();
		$tbl = $mgr->get_table();

		$scope = $req->get_param( 'scope' ) === 'site' ? 'site' : 'user';
		if ( $scope === 'site' && ! current_user_can( 'manage_options' ) ) {
			$scope = 'user';
		}

		$from = (string) ( $req->get_param( 'from' ) ?: gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) ) );
		$to   = (string) ( $req->get_param( 'to' )   ?: gmdate( 'Y-m-d 23:59:59' ) );

		$where_extras = array();
		$args         = array( $from, $to );
		if ( $scope === 'user' ) {
			$where_extras[] = 'user_id = %d';
			$args[]         = get_current_user_id();
		}
		$extra_sql = empty( $where_extras ) ? '' : ' AND ' . implode( ' AND ', $where_extras );

		// ── Totals by status ─────────────────────────────────────────
		$sql = "SELECT status, COUNT(*) AS c FROM {$tbl}
		        WHERE start_at BETWEEN %s AND %s {$extra_sql}
		        GROUP BY status";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$by_status = array( 'active' => 0, 'done' => 0, 'cancelled' => 0, 'draft' => 0 );
		$total = 0;
		foreach ( (array) $rows as $r ) {
			$st = (string) $r['status'];
			$c  = (int) $r['c'];
			$by_status[ $st ] = $c;
			$total += $c;
		}

		// ── Failed (cancelled với delivery.status='failed' hoặc reason in metadata.notify) ──
		$failed_sql = "SELECT COUNT(*) FROM {$tbl}
		               WHERE start_at BETWEEN %s AND %s {$extra_sql}
		                 AND status = 'cancelled'
		                 AND (
		                      JSON_EXTRACT(metadata, '$.delivery.status') = 'failed'
		                   OR JSON_EXTRACT(metadata, '$.hil.reason')      = 'timeout'
		                 )";
		$failed = (int) $wpdb->get_var( $wpdb->prepare( $failed_sql, $args ) );

		// ── By event_type ────────────────────────────────────────────
		$type_sql  = "SELECT event_type, COUNT(*) AS c FROM {$tbl}
		              WHERE start_at BETWEEN %s AND %s {$extra_sql}
		              GROUP BY event_type
		              ORDER BY c DESC
		              LIMIT 20";
		$type_rows = $wpdb->get_results( $wpdb->prepare( $type_sql, $args ), ARRAY_A );
		$by_type = array();
		foreach ( (array) $type_rows as $r ) {
			$by_type[ (string) $r['event_type'] ] = (int) $r['c'];
		}

		// ── By source ────────────────────────────────────────────────
		$src_sql  = "SELECT source, COUNT(*) AS c FROM {$tbl}
		             WHERE start_at BETWEEN %s AND %s {$extra_sql}
		             GROUP BY source
		             ORDER BY c DESC
		             LIMIT 20";
		$src_rows = $wpdb->get_results( $wpdb->prepare( $src_sql, $args ), ARRAY_A );
		$by_source = array();
		foreach ( (array) $src_rows as $r ) {
			$by_source[ (string) $r['source'] ] = (int) $r['c'];
		}

		// ── Inbox count (events có metadata.inbound) ─────────────────
		$inbox_sql = "SELECT COUNT(*) FROM {$tbl}
		              WHERE start_at BETWEEN %s AND %s {$extra_sql}
		                AND JSON_EXTRACT(metadata, '$.inbound.platform') IS NOT NULL";
		$inbox = (int) $wpdb->get_var( $wpdb->prepare( $inbox_sql, $args ) );

		$done    = (int) $by_status['done'];
		$active  = (int) $by_status['active'];
		$pending = (int) $by_status['draft'];
		$pct_done = $total > 0 ? (int) round( $done * 100 / $total ) : 0;

		return new \WP_REST_Response( array(
			'ok'    => true,
			'scope' => $scope,
			'range' => array( 'from' => $from, 'to' => $to ),
			'stats' => array(
				'total'      => $total,
				'done'       => $done,
				'active'     => $active,
				'pending'    => $pending,
				'cancelled'  => (int) $by_status['cancelled'],
				'failed'     => $failed,
				'inbox'      => $inbox,
				'percent_done' => $pct_done,
				'by_status'  => $by_status,
				'by_type'    => $by_type,
				'by_source'  => $by_source,
			),
		), 200 );
	}

	/* ================================================================
	 *  Automation Lab Callbacks (Phase 0.37)
	 * ================================================================ */

	/**
	 * Force-fire reminder for an event NOW (bypass 5-min cron).
	 * Admin-only — used by Automation Lab to test chain wiring.
	 */
	public function automation_fire_now( \WP_REST_Request $req ): \WP_REST_Response {
		$event_id = (int) $req->get_param( 'event_id' );
		if ( $event_id <= 0 ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_event_id' ], 400 );
		}

		$mgr   = BizCity_Scheduler_Manager::instance();
		$event = $mgr->get_event( $event_id );
		if ( ! $event ) {
			return new \WP_REST_Response( [ 'error' => 'event_not_found' ], 404 );
		}
		// get_event() returns stdClass; fire hook expects array (matches claim_due_reminders() format).
		if ( is_object( $event ) ) {
			$event = (array) $event;
		}

		// Wrap inside a synthetic cron run so the runner's note_event() calls
		// land in bizcity_cron_runs.meta (otherwise they're silent no-ops).
		$started = microtime( true );
		$ok      = true;
		$error   = '';
		$run_id  = 0;

		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			try {
				$run_id = $cron->with_synthetic_run( 'lab.automation.fire-now', function () use ( $cron, $event, $event_id ) {
					$cron->note( [
						'lab' => [
							'fire_now'     => true,
							'event_id'     => $event_id,
							'triggered_by' => get_current_user_id(),
							'triggered_at' => gmdate( 'c' ),
						],
					] );
					do_action( 'bizcity_scheduler_reminder_fire', $event );
				} );
			} catch ( \Throwable $e ) {
				$ok    = false;
				$error = $e->getMessage();
			}
		} else {
			try {
				do_action( 'bizcity_scheduler_reminder_fire', $event );
			} catch ( \Throwable $e ) {
				$ok    = false;
				$error = $e->getMessage();
			}
		}

		return new \WP_REST_Response( [
			'ok'       => $ok,
			'event_id' => $event_id,
			'run_id'   => $run_id,
			'ms'       => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'error'    => $error,
			'hint'     => $ok
				? 'Synthetic run #' . $run_id . ' created. Timeline sẽ refresh trong 3s.'
				: 'Reminder hook threw — xem error field.',
		], $ok ? 200 : 500 );
	}

	/**
	 * Pull last N automation chain runs from bizcity_cron_runs.meta.events[].
	 * Returns grouped chains: { event_id, started_at, steps[], counters, status }.
	 */
	public function automation_recent( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;

		$limit = max( 1, min( 100, (int) $req->get_param( 'limit' ) ) );
		$table = $wpdb->prefix . 'bizcity_cron_runs';

		// Pull recent runs (limit larger to find enough chain pairs).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, job_id, status, started_at, ended_at, meta
			   FROM {$table}
			  WHERE meta LIKE %s
			  ORDER BY id DESC
			  LIMIT %d",
			'%automation_%',
			$limit * 4
		), ARRAY_A );

		$chains = [];

		foreach ( (array) $rows as $row ) {
			$meta = json_decode( (string) $row['meta'], true );
			if ( ! is_array( $meta ) || empty( $meta['events'] ) ) {
				continue;
			}

			// Group events by automation_chain_started → automation_chain_done.
			$current = null;
			foreach ( $meta['events'] as $evt ) {
				$name = (string) ( $evt['name'] ?? '' );
				$data = is_array( $evt['data'] ?? null ) ? $evt['data'] : [];

				if ( $name === 'automation_chain_started' ) {
					$current = [
						'run_id'     => (int) $row['id'],
						'job_id'     => (string) $row['job_id'],
						'started_at' => (string) ( $evt['ts'] ?? $row['started_at'] ),
						'ended_at'   => '',
						'event_id'   => (int) ( $data['event_id'] ?? 0 ),
						'user_id'    => (int) ( $data['user_id'] ?? 0 ),
						'skill_ref'  => (string) ( $data['skill_ref'] ?? '' ),
						'step_count' => (int) ( $data['step_count'] ?? 0 ),
						'steps'      => [],
						'status'     => 'running',
					];
					continue;
				}

				if ( $current === null ) {
					continue;
				}

				if ( $name === 'automation_step_ok' ) {
					$current['steps'][] = [
						'idx'     => (int) ( $data['step_idx'] ?? -1 ),
						'tool'    => (string) ( $data['tool'] ?? '' ),
						'ok'      => true,
						'message' => (string) ( $data['message'] ?? '' ),
					];
				} elseif ( $name === 'automation_step_failed' ) {
					$current['steps'][] = [
						'idx'    => (int) ( $data['step_idx'] ?? -1 ),
						'tool'   => (string) ( $data['tool'] ?? '' ),
						'ok'     => false,
						'reason' => (string) ( $data['reason'] ?? '' ),
						'error'  => (string) ( $data['error'] ?? '' ),
					];
				} elseif ( $name === 'automation_chain_done' ) {
					$current['ended_at'] = (string) ( $evt['ts'] ?? '' );
					$failed = 0;
					foreach ( $current['steps'] as $s ) {
						if ( empty( $s['ok'] ) ) { $failed++; }
					}
					$current['status'] = $failed === 0 ? 'ok' : ( $failed === count( $current['steps'] ) ? 'failed' : 'partial' );
					$chains[] = $current;
					$current  = null;

					if ( count( $chains ) >= $limit ) {
						break 2;
					}
				}
			}
		}

		return new \WP_REST_Response( [ 'chains' => $chains, 'count' => count( $chains ) ], 200 );
	}

	/**
	 * Lint an automation chain. Returns warnings + errors without saving.
	 */
	public function automation_validate( \WP_REST_Request $req ): \WP_REST_Response {
		$body  = is_array( $req->get_json_params() ) ? $req->get_json_params() : [];
		$chain = isset( $body['automation'] ) && is_array( $body['automation'] ) ? $body['automation'] : $body;

		$errors   = [];
		$warnings = [];

		if ( ! isset( $chain['on_fire'] ) || ! is_array( $chain['on_fire'] ) ) {
			$errors[] = 'Thiếu trường `automation.on_fire[]`.';
		}

		$known_tools = $this->known_tool_names();
		$valid_err   = [ 'continue', 'stop', 'retry_once' ];

		foreach ( ( $chain['on_fire'] ?? [] ) as $idx => $step ) {
			$prefix = "Step #{$idx}: ";
			if ( ! is_array( $step ) ) {
				$errors[] = $prefix . 'phải là object.';
				continue;
			}
			$tool = (string) ( $step['tool'] ?? '' );
			if ( $tool === '' ) {
				$errors[] = $prefix . 'thiếu `tool`.';
			} elseif ( $known_tools && ! in_array( $tool, $known_tools, true ) ) {
				$warnings[] = $prefix . "tool `{$tool}` không có trong Intent Provider registry (chạy runtime sẽ fail).";
			}
			if ( isset( $step['on_error'] ) && ! in_array( $step['on_error'], $valid_err, true ) ) {
				$warnings[] = $prefix . "`on_error` không hợp lệ (mong: continue | stop | retry_once).";
			}
			if ( isset( $step['args'] ) && ! is_array( $step['args'] ) ) {
				$errors[] = $prefix . '`args` phải là object.';
			}
		}

		return new \WP_REST_Response( [
			'ok'       => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		], 200 );
	}

	/**
	 * Discover registered tool names from Intent Provider registry.
	 */
	public function automation_tools( \WP_REST_Request $req ): \WP_REST_Response {
		$tools = [];
		if ( class_exists( 'BizCity_Intent_Tools' ) ) {
			$reg = BizCity_Intent_Tools::instance();
			// BizCity_Intent_Tools stores tools in private $this->tools — reflect.
			try {
				$ref  = new \ReflectionClass( $reg );
				$prop = $ref->getProperty( 'tools' );
				$prop->setAccessible( true );
				$raw  = $prop->getValue( $reg );
				if ( is_array( $raw ) ) {
					foreach ( $raw as $name => $def ) {
						$schema = isset( $def['schema'] ) && is_array( $def['schema'] ) ? $def['schema'] : [];
						$tools[] = [
							'name'        => (string) $name,
							'label'       => (string) ( $def['label'] ?? $name ),
							'description' => (string) ( $schema['description'] ?? '' ),
							'required'    => $this->extract_required_fields( $schema ),
						];
					}
				}
			} catch ( \Throwable $e ) {
				// Ignore — return empty list.
			}
		}

		usort( $tools, static function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		return new \WP_REST_Response( [ 'tools' => $tools, 'count' => count( $tools ) ], 200 );
	}

	private function known_tool_names(): array {
		$resp = $this->automation_tools( new \WP_REST_Request( 'GET' ) );
		$data = $resp->get_data();
		$names = [];
		foreach ( $data['tools'] ?? [] as $t ) {
			$names[] = (string) $t['name'];
		}
		return $names;
	}

	private function extract_required_fields( array $schema ): array {
		$req    = [];
		$fields = $schema['input_fields'] ?? [];
		if ( ! is_array( $fields ) ) {
			return $req;
		}
		foreach ( $fields as $key => $def ) {
			if ( is_array( $def ) && ! empty( $def['required'] ) ) {
				$req[] = (string) $key;
			}
		}
		return $req;
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

	/* ================================================================
	 *  Notify Channel Callbacks (R-UNIFY 2026-06-15)
	 * ================================================================ */

	/**
	 * GET /me/notify-channel — current user's default notify channel binding.
	 *
	 * @param \WP_REST_Request $req
	 * @return \WP_REST_Response
	 */
	public function get_notify_channel( \WP_REST_Request $req ) {
		// [2026-06-15 Johnny Chu] R-UNIFY — admin đọc Zalo Bot binding.
		$user_id = get_current_user_id();
		// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
		$pref    = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $user_id, 'bizcity_default_notify_channel', array() )
			: get_user_meta( $user_id, 'bizcity_default_notify_channel', true );
		if ( ! is_array( $pref ) ) {
			$pref = array();
		}
		return new \WP_REST_Response( array(
			'ok'           => true,
			'user_id'      => $user_id,
			'notify_channel' => array(
				'platform' => isset( $pref['platform'] ) ? (string) $pref['platform'] : '',
				'chat_id'  => isset( $pref['chat_id'] ) ? (string) $pref['chat_id'] : '',
			),
		), 200 );
	}

	/**
	 * PUT /me/notify-channel — set current user's default notify channel binding.
	 *
	 * Request JSON:
	 *   { "platform": "ZALO_BOT", "chat_id": "zalobot_<bot_id>_<zalo_uid>" }
	 *
	 * @param \WP_REST_Request $req
	 * @return \WP_REST_Response
	 */
	public function set_notify_channel( \WP_REST_Request $req ) {
		// [2026-06-15 Johnny Chu] R-UNIFY — admin lưu Zalo Bot chat_id vào user_meta.
		$data     = is_array( $req->get_json_params() ) ? $req->get_json_params() : array();
		$platform = strtoupper( sanitize_text_field( (string) ( $data['platform'] ?? '' ) ) );
		$chat_id  = sanitize_text_field( (string) ( $data['chat_id'] ?? '' ) );

		$allowed = array( 'ZALO_BOT', 'ZALO', 'FACEBOOK', 'TELEGRAM', 'WEBCHAT', 'TWINBRAIN', 'ADMIN' );
		if ( $platform === '' || $chat_id === '' ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'platform và chat_id là bắt buộc.' ), 400 );
		}
		if ( ! in_array( $platform, $allowed, true ) ) {
			return new \WP_REST_Response( array(
				'ok'    => false,
				'error' => 'Platform không hợp lệ. Cho phép: ' . implode( ', ', $allowed ),
			), 400 );
		}

		$user_id = get_current_user_id();
		update_user_meta( $user_id, 'bizcity_default_notify_channel', array(
			'platform' => $platform,
			'chat_id'  => $chat_id,
		) );

		return new \WP_REST_Response( array(
			'ok'      => true,
			'message' => 'Đã lưu kênh thông báo mặc định.',
			'notify_channel' => array(
				'platform' => $platform,
				'chat_id'  => $chat_id,
			),
		), 200 );
	}

	/**
	 * POST /events/reminders — quick create a reminder_personal event.
	 *
	 * Request JSON:
	 *   {
	 *     "title":         "Dự tiệc",        // required
	 *     "start_at":      "2026-06-15 10:00:00", // required, MySQL datetime or strtotime
	 *     "reminder_text": "Nhắc: dự tiệc lúc 10h", // optional
	 *     "reminder_min":  0,                // optional, default 0
	 *     "inbound":       { "platform": "ZALO_BOT", "chat_id": "zalobot_..." } // optional, overrides user_meta
	 *   }
	 *
	 * Response: { event, event_id }
	 *
	 * @param \WP_REST_Request $req
	 * @return \WP_REST_Response
	 */
	public function create_reminder( \WP_REST_Request $req ) {
		// [2026-06-15 Johnny Chu] R-UNIFY — quick reminder_personal creation endpoint.
		$data  = is_array( $req->get_json_params() ) ? $req->get_json_params() : array();
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( $title === '' ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'title là bắt buộc.' ), 400 );
		}

		$start_raw = (string) ( $data['start_at'] ?? '' );
		if ( $start_raw === '' ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'start_at là bắt buộc.' ), 400 );
		}
		$ts       = strtotime( $start_raw, current_time( 'timestamp' ) );
		$start_at = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
		if ( $start_at === '' ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'start_at không hợp lệ.' ), 400 );
		}

		$reminder_text = sanitize_text_field( (string) ( $data['reminder_text'] ?? '' ) );
		$reminder_min  = max( 0, (int) ( $data['reminder_min'] ?? 0 ) );

		// Build metadata.
		$metadata = array(
			'notify' => array( 'enabled' => true ),
		);
		if ( $reminder_text !== '' ) {
			$metadata['reminder_text'] = $reminder_text;
		}
		// Explicit inbound override (optional).
		if ( isset( $data['inbound'] ) && is_array( $data['inbound'] ) ) {
			$inbound_platform = strtoupper( sanitize_text_field( (string) ( $data['inbound']['platform'] ?? '' ) ) );
			$inbound_chat_id  = sanitize_text_field( (string) ( $data['inbound']['chat_id'] ?? '' ) );
			if ( $inbound_platform !== '' && $inbound_chat_id !== '' ) {
				$metadata['inbound'] = array(
					'platform' => $inbound_platform,
					'chat_id'  => $inbound_chat_id,
					'user_id'  => (string) ( $data['inbound']['user_id'] ?? get_current_user_id() ),
				);
			}
		}

		$payload = array(
			'event_type'   => 'reminder_personal',
			'title'        => $title,
			'start_at'     => $start_at,
			'reminder_min' => $reminder_min,
			'status'       => 'active',
			'source'       => 'user',
			'user_id'      => get_current_user_id(),
			'metadata'     => $metadata,
		);

		$mgr    = BizCity_Scheduler_Manager::instance();
		$result = $mgr->create_event( $payload );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => $result->get_error_message() ), 400 );
		}

		$event = $mgr->get_event( (int) $result );
		return new \WP_REST_Response( array(
			'ok'       => true,
			'event_id' => (int) $result,
			'event'    => $event,
			'hint'     => 'Reminder sẽ được gửi vào ' . $start_at . '. Đảm bảo kênh thông báo đã được cấu hình (PUT /me/notify-channel).',
		), 201 );
	}
}
