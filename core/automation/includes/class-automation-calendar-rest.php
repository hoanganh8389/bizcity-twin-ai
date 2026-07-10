<?php
/**
 * BizCity_Automation_Calendar_REST
 *
 * REST routes for the Automation Calendar feature (AUTOMATION-CAL).
 *
 * Namespace: bizcity-automation/v1  (same as Automation SPA — R-NS compliant).
 *
 * Routes:
 *   GET    /calendar/events              → list events (filter: from, to, workflow_id, status)
 *   POST   /calendar/events              → create manual event
 *   PATCH  /calendar/events/(?P<id>\d+)  → update event (title, start_at, status, config)
 *   DELETE /calendar/events/(?P<id>\d+)  → delete single event
 *   POST   /calendar/events/bulk-delete  → delete { ids: [1,2,…] }
 *   POST   /calendar/sync/(?P<wf_id>\d+) → re-sync next 30 events for a workflow
 *
 * PHP 7.4 compatible.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION-CAL (2026-06-14)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Calendar_REST {

	const NS = 'bizcity-automation/v1';

	public static function init() {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — register calendar REST routes
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		// List + Create
		register_rest_route( self::NS, '/calendar/events', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_events' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_event' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
		) );

		// Single event: update + delete
		register_rest_route( self::NS, '/calendar/events/(?P<id>\d+)', array(
			array(
				'methods'             => array( 'PATCH', 'PUT' ),
				'callback'            => array( __CLASS__, 'update_event' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_event' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
		) );

		// Bulk delete
		register_rest_route( self::NS, '/calendar/events/bulk-delete', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'bulk_delete' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// Re-sync events for a workflow
		register_rest_route( self::NS, '/calendar/sync/(?P<wf_id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'sync_workflow' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
	}

	// ─── Handlers ────────────────────────────────────────────────────────

	public static function list_events( WP_REST_Request $req ) {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — GET /calendar/events
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_crm_events';

		if ( ! self::table_exists( $table ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'rows' => array() ), 200 );
		}

		$from        = (string) ( $req->get_param( 'from' ) ?: '' );
		$to          = (string) ( $req->get_param( 'to' ) ?: '' );
		$workflow_id = (int) ( $req->get_param( 'workflow_id' ) ?: 0 );
		$status      = (string) ( $req->get_param( 'status' ) ?: '' );
		$limit       = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 200 ) ) );

		$where  = array( 'event_type = %s', 'source = %s' );
		$params = array( BizCity_Automation_Schedule_Manager::EVENT_TYPE, BizCity_Automation_Schedule_Manager::EVENT_SOURCE );

		if ( $from !== '' ) {
			$where[]  = 'start_at >= %s';
			$params[] = $from;
		}
		if ( $to !== '' ) {
			$where[]  = 'start_at <= %s';
			$params[] = $to;
		}
		if ( $workflow_id > 0 ) {
			$where[]  = "JSON_EXTRACT(metadata, '$.workflow_id') = %d";
			$params[] = $workflow_id;
		}
		if ( $status !== '' ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$params[] = $limit;
		$sql      = "SELECT * FROM `{$table}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_at ASC LIMIT %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		// Decode metadata for FE consumption.
		foreach ( $rows as &$row ) {
			if ( isset( $row['metadata'] ) && is_string( $row['metadata'] ) ) {
				$decoded = json_decode( $row['metadata'], true );
				if ( is_array( $decoded ) ) {
					$row['metadata'] = $decoded;
				}
			}
		}
		unset( $row );

		return new WP_REST_Response( array( 'ok' => true, 'rows' => $rows, 'total' => count( $rows ) ), 200 );
	}

	public static function create_event( WP_REST_Request $req ) {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — POST /calendar/events (manual)
		$body = (array) $req->get_json_params();

		$workflow_id = (int) ( $body['workflow_id'] ?? 0 );
		$start_at    = (string) ( $body['start_at'] ?? '' );
		$title       = sanitize_text_field( (string) ( $body['title'] ?? '' ) );
		$recurrence  = sanitize_key( (string) ( $body['recurrence'] ?? 'once' ) );
		$occurrences = max( 1, min( 60, (int) ( $body['occurrences'] ?? 1 ) ) );

		if ( $workflow_id <= 0 ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_param', 'message' => 'workflow_id bắt buộc.' ), 400 );
		}
		if ( $start_at === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_param', 'message' => 'start_at bắt buộc (ISO datetime).' ), 400 );
		}

		// Load workflow name if title not provided.
		if ( $title === '' ) {
			$wf = BizCity_Automation_Repo_Workflows::find( $workflow_id );
			$title = $wf ? (string) ( $wf['name'] ?? ( 'Workflow #' . $workflow_id ) ) : 'Workflow #' . $workflow_id;
		}

		$scheduler = self::get_scheduler();
		if ( ! $scheduler ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'module_not_loaded', 'message' => 'Scheduler chưa load.' ), 503 );
		}

		$user_id     = get_current_user_id();
		$start_ts    = strtotime( $start_at );
		$created_ids = array();

		for ( $i = 0; $i < $occurrences; $i++ ) {
			$ts = $start_ts + self::recurrence_offset( $recurrence, $i );
			if ( $ts <= 0 ) {
				continue;
			}
			$meta = array(
				'workflow_id'    => $workflow_id,
				'workflow_name'  => $title,
				'cron_expr'      => '',
				'recurrence'     => $recurrence,
				'occurrence'     => $i + 1,
				'run_status'     => 'pending',
				'prompt_before'  => (bool) ( $body['prompt_before'] ?? false ),
				'prompt_channel' => (string) ( $body['prompt_channel'] ?? '' ),
				'prompt_text'    => sanitize_text_field( (string) ( $body['prompt_text'] ?? '' ) ),
				'inbound'        => array(
					'platform'   => 'ADMIN',
					'chat_id'    => '',
					'user_id'    => (string) $user_id,
					'intent_tag' => 'workflow_manual',
				),
			);
			$event_id = $scheduler->create_event( array(
				'user_id'     => $user_id,
				'title'       => $title,
				'description' => sanitize_textarea_field( (string) ( $body['description'] ?? '' ) ),
				'start_at'    => gmdate( 'Y-m-d H:i:s', $ts ),
				'status'      => 'active',
				'event_type'  => BizCity_Automation_Schedule_Manager::EVENT_TYPE,
				'source'      => BizCity_Automation_Schedule_Manager::EVENT_SOURCE,
				'metadata'    => $meta,
			) );
			if ( is_wp_error( $event_id ) ) {
				continue;
			}
			$created_ids[] = $event_id;
		}

		return new WP_REST_Response( array(
			'ok'          => true,
			'created_ids' => $created_ids,
			'count'       => count( $created_ids ),
		), 201 );
	}

	public static function update_event( WP_REST_Request $req ) {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — PATCH /calendar/events/{id}
		$id   = (int) $req['id'];
		$body = (array) $req->get_json_params();

		$scheduler = self::get_scheduler();
		if ( ! $scheduler ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'module_not_loaded', 'message' => 'Scheduler chưa load.' ), 503 );
		}

		$update = array();
		if ( isset( $body['title'] ) ) {
			$update['title'] = sanitize_text_field( (string) $body['title'] );
		}
		if ( isset( $body['start_at'] ) ) {
			$update['start_at'] = (string) $body['start_at'];
		}
		if ( isset( $body['description'] ) ) {
			$update['description'] = sanitize_textarea_field( (string) $body['description'] );
		}
		if ( isset( $body['status'] ) ) {
			$allowed_statuses = array( 'active', 'cancelled', 'done' );
			$st = (string) $body['status'];
			$update['status'] = in_array( $st, $allowed_statuses, true ) ? $st : 'active';
		}

		$result = $scheduler->update_event( $id, $update, 0 ); // 0 = admin bypass
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ), 400 );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function delete_event( WP_REST_Request $req ) {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — DELETE /calendar/events/{id}
		$id = (int) $req['id'];

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_crm_events';
		if ( ! self::table_exists( $table ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'no_table' ), 500 );
		}

		$ok = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		return new WP_REST_Response( array( 'ok' => $ok !== false ), 200 );
	}

	public static function bulk_delete( WP_REST_Request $req ) {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — POST /calendar/events/bulk-delete
		$body = (array) $req->get_json_params();
		$ids  = array_filter( (array) ( $body['ids'] ?? array() ), 'is_numeric' );
		$ids  = array_map( 'intval', $ids );

		if ( empty( $ids ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_param', 'message' => 'ids array bắt buộc.' ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_crm_events';
		if ( ! self::table_exists( $table ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'no_table' ), 500 );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted      = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id IN ({$placeholders})", $ids ) );

		return new WP_REST_Response( array( 'ok' => true, 'deleted' => (int) $deleted ), 200 );
	}

	public static function sync_workflow( WP_REST_Request $req ) {
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — POST /calendar/sync/{wf_id}
		$wf_id = (int) $req['wf_id'];
		$wf    = BizCity_Automation_Repo_Workflows::find( $wf_id );
		if ( ! $wf ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_found', 'message' => 'Workflow không tồn tại.' ), 404 );
		}

		$mgr = BizCity_Automation_Schedule_Manager::instance();
		$mgr->sync_workflow_events( $wf );

		return new WP_REST_Response( array( 'ok' => true, 'workflow_id' => $wf_id ), 200 );
	}

	// ─── Permission ───────────────────────────────────────────────────────

	public static function admin_only() {
		return current_user_can( 'manage_options' );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	private static function get_scheduler() {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return null;
		}
		return BizCity_Scheduler_Manager::instance();
	}

	private static function table_exists( string $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Return the time offset (in seconds) for a given recurrence index.
	 *
	 * @param string $recurrence  'once'|'daily'|'weekly'|'monthly'.
	 * @param int    $index       0-based occurrence index.
	 * @return int
	 */
	private static function recurrence_offset( string $recurrence, int $index ) {
		if ( $index === 0 ) {
			return 0;
		}
		switch ( $recurrence ) {
			case 'daily':
				return $index * DAY_IN_SECONDS;
			case 'weekly':
				return $index * WEEK_IN_SECONDS;
			case 'monthly':
				// Approximate 30 days.
				return $index * 30 * DAY_IN_SECONDS;
			default:
				return 0; // 'once' — only first occurrence
		}
	}
}
