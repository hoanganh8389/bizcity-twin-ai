<?php
/**
 * BizCity Twin AI — Event Stream REST (Phase 0.12 Wave F)
 *
 * Read-only access to `bizcity_twin_event_stream` for the admin
 * Inspector drawer. Capability-gated: only `manage_options` users (or
 * the trace owner) can fetch events. Append-only stream → no write
 * endpoints exposed here.
 *
 * Endpoints (namespace `bizcity-twin/v1`):
 *   GET  /events?trace_id=&limit=&after_uuid=&event_type=&event_source=
 *        Returns the chronological list of envelopes for one trace.
 *   GET  /events/recent_traces?limit=20
 *        Returns the last N traces with summary counts (for the picker).
 *
 * @package BizCity_Twin_AI
 * @since   2026-04-29 (Phase 0.12 Wave F)
 */

defined( 'ABSPATH' ) or die( 'Direct access denied.' );

if ( ! class_exists( 'BizCity_Twin_Event_Stream_REST' ) ) :

class BizCity_Twin_Event_Stream_REST {

	const NAMESPACE = 'bizcity-twin/v1';

	/**
	 * Boot — wire on rest_api_init.
	 */
	public static function boot(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/events', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_list_for_trace' ],
			'permission_callback' => [ __CLASS__, 'permission_inspector' ],
			'args'                => [
				'trace_id'     => [ 'type' => 'string', 'required' => true ],
				'limit'        => [ 'type' => 'integer', 'default' => 500 ],
				'after_uuid'   => [ 'type' => 'string' ],
				'event_type'   => [ 'type' => 'string' ],
				'event_source' => [ 'type' => 'string' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/events/recent_traces', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_recent_traces' ],
			'permission_callback' => [ __CLASS__, 'permission_inspector' ],
			'args'                => [
				'limit' => [ 'type' => 'integer', 'default' => 20 ],
			],
		] );
	}

	/**
	 * Capability gate. `manage_options` always allowed. Owner of the
	 * trace (matched via user_id of any event in the trace) is also
	 * allowed when a trace_id is in the query.
	 */
	public static function permission_inspector( WP_REST_Request $req ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		$trace_id = (string) $req->get_param( 'trace_id' );
		if ( $trace_id === '' ) {
			return new WP_Error( 'rest_forbidden', 'Admin only.', [ 'status' => 403 ] );
		}
		// Cheap check: any event in the trace belongs to this user?
		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$owner = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$table} WHERE trace_id = %s LIMIT 1",
			$trace_id
		) );
		if ( $owner === $user_id ) {
			return true;
		}
		return new WP_Error( 'rest_forbidden', 'Not your trace.', [ 'status' => 403 ] );
	}

	/**
	 * GET /events?trace_id=...
	 */
	public static function handle_list_for_trace( WP_REST_Request $req ): WP_REST_Response {
		$trace_id = (string) $req->get_param( 'trace_id' );
		$opts     = [
			'limit'        => (int) $req->get_param( 'limit' ),
			'after_uuid'   => (string) $req->get_param( 'after_uuid' ),
			'event_type'   => (string) $req->get_param( 'event_type' ),
			'event_source' => (string) $req->get_param( 'event_source' ),
		];
		$rows = BizCity_Twin_Event_Store::fetch_for_trace( $trace_id, $opts );

		// Slim envelope for transport (drop the redundant payload_json string).
		foreach ( $rows as &$r ) {
			unset( $r['payload_json'] );
		}

		return new WP_REST_Response( [
			'success'  => true,
			'trace_id' => $trace_id,
			'count'    => count( $rows ),
			'events'   => $rows,
		], 200 );
	}

	/**
	 * GET /events/recent_traces?limit=20
	 *
	 * Returns the most recent traces with min/max timestamps + counts
	 * + first event_source so the admin picker can choose a trace.
	 */
	public static function handle_recent_traces( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $req->get_param( 'limit' ) ?: 20 ) );
		$table = BizCity_Twin_Event_Stream_Schema::table();

		$sql = $wpdb->prepare(
			"SELECT trace_id,
			        COUNT(*) AS event_count,
			        MIN(created_epoch_ms) AS started_ms,
			        MAX(created_epoch_ms) AS ended_ms,
			        MIN(user_id) AS user_id,
			        MAX(CASE WHEN event_type = 'turn_complete' THEN 1 ELSE 0 END) AS has_complete,
			        MAX(CASE WHEN event_type = 'turn_complete'
			                 AND payload_json LIKE '%\"success\":false%' THEN 1 ELSE 0 END) AS had_error
			   FROM {$table}
			  GROUP BY trace_id
			  ORDER BY MAX(created_epoch_ms) DESC
			  LIMIT %d",
			$limit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = [];
		}

		// Coerce types for clean JSON.
		foreach ( $rows as &$r ) {
			$r['event_count']  = (int) $r['event_count'];
			$r['started_ms']   = (int) $r['started_ms'];
			$r['ended_ms']     = (int) $r['ended_ms'];
			$r['duration_ms']  = max( 0, (int) $r['ended_ms'] - (int) $r['started_ms'] );
			$r['user_id']      = (int) $r['user_id'];
			$r['has_complete'] = (bool) $r['has_complete'];
			$r['had_error']    = (bool) $r['had_error'];
		}

		return new WP_REST_Response( [
			'success' => true,
			'count'   => count( $rows ),
			'traces'  => $rows,
		], 200 );
	}
}

endif;
