<?php
/**
 * BizCity TwinBrain Sessions Manager.
 *
 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — Mint / rename / archive / list
 * brain conversation threads. All state is derived from the canonical event
 * stream (R-EVT-3); this class never writes to a sessions table. Reads come
 * from the VIEW {prefix}bizcity_brain_sessions ({@see BizCity_TwinBrain_Schema::ensure_sessions_view()}).
 *
 * Mutations (mint / rename / archive) emit one event each via
 * `BizCity_Twin_Event_Bus::dispatch_v2()`:
 *   • brain_session_created   — when a new thread is minted
 *   • brain_session_renamed   — title change (user PATCH or auto LLM)
 *   • brain_session_archived  — soft-archive marker
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-06-03 (Phase BRAIN-SESSIONS BS-2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Sessions_Manager {

	const SESSION_ID_PREFIX = 'brain_sess_';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	// ------------------------------------------------------------------
	// ID utilities
	// ------------------------------------------------------------------

	/**
	 * [2026-06-03 Johnny Chu] BS-2 — Mint a fresh session_id matching the
	 * canonical pattern `^brain_sess_[0-9]+_[0-9]+_[0-9a-f]{4}$`.
	 *
	 * @param int $user_id Owning user (used in the id for human readability).
	 */
	public static function mint_session_id( int $user_id ): string {
		$ts_ms = (int) round( microtime( true ) * 1000 );
		try {
			$rand = bin2hex( random_bytes( 2 ) );
		} catch ( \Throwable $e ) {
			$rand = substr( md5( (string) mt_rand() . microtime( true ) ), 0, 4 );
		}
		return self::SESSION_ID_PREFIX . $ts_ms . '_' . max( 0, $user_id ) . '_' . $rand;
	}

	public static function is_valid_session_id( string $id ): bool {
		return (bool) preg_match( '/^brain_sess_[0-9]+_[0-9]+_[0-9a-f]{4}$/', $id );
	}

	// ------------------------------------------------------------------
	// Mutations
	// ------------------------------------------------------------------

	/**
	 * [2026-06-03 Johnny Chu] BS-2 — Mint a new session and emit
	 * brain_session_created. Idempotent: if a session_id is supplied and
	 * already has a `brain_session_created` event, returns it unchanged.
	 *
	 * @param array $args {
	 *   @type int    $user_id    REQUIRED. Owner user id.
	 *   @type string $session_id Optional pre-minted id (mostly for probe/import).
	 *   @type string $title      Optional initial title.
	 *   @type string $project_id Optional grouping.
	 *   @type string $source     'user' | 'twin_tool' | 'system'. Default 'user'.
	 * }
	 * @return array { session_id, created, event_uuid }
	 */
	public function create( array $args ): array {
		$user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
		if ( $user_id <= 0 ) {
			return [ 'error' => 'user_id_required' ];
		}

		$session_id = (string) ( $args['session_id'] ?? '' );
		if ( $session_id === '' ) {
			$session_id = self::mint_session_id( $user_id );
		} elseif ( ! self::is_valid_session_id( $session_id ) ) {
			return [ 'error' => 'invalid_session_id' ];
		}

		// Idempotency: if already created, no-op.
		if ( $this->has_event( $session_id, 'brain_session_created' ) ) {
			return [
				'session_id' => $session_id,
				'created'    => false,
				'event_uuid' => '',
			];
		}

		$payload = [
			'session_id' => $session_id,
			'created_at' => gmdate( 'c' ),
			'source'     => in_array( ( $args['source'] ?? 'user' ), [ 'user', 'twin_tool', 'system' ], true )
				? (string) $args['source']
				: 'user',
		];
		if ( isset( $args['title'] ) && $args['title'] !== '' ) {
			$payload['title'] = (string) $args['title'];
		}
		if ( isset( $args['project_id'] ) && $args['project_id'] !== '' ) {
			$payload['project_id'] = (string) $args['project_id'];
		}

		$event_uuid = '';
		try {
			$event_uuid = BizCity_Twin_Event_Bus::dispatch_v2(
				'brain_session_created',
				$payload,
				[
					'event_source' => 'system',
					'session_id'   => $session_id,
					'user_id'      => $user_id,
				]
			);
		} catch ( \Throwable $e ) {
			error_log( '[TwinBrain][Sessions] create dispatch failed: ' . $e->getMessage() );
			return [ 'error' => 'dispatch_failed', 'message' => $e->getMessage() ];
		}

		return [
			'session_id' => $session_id,
			'created'    => true,
			'event_uuid' => $event_uuid,
		];
	}

	/**
	 * [2026-06-03 Johnny Chu] BS-2 — Rename a session.
	 *
	 * @param string $session_id
	 * @param string $new_title
	 * @param array  $opts { reason: 'user'|'auto_llm', user_id }
	 * @return array { ok, event_uuid? , error? }
	 */
	public function rename( string $session_id, string $new_title, array $opts = [] ): array {
		if ( ! self::is_valid_session_id( $session_id ) ) {
			return [ 'ok' => false, 'error' => 'invalid_session_id' ];
		}
		$new_title = trim( $new_title );
		if ( $new_title === '' ) {
			return [ 'ok' => false, 'error' => 'empty_title' ];
		}
		if ( mb_strlen( $new_title ) > 200 ) {
			$new_title = mb_substr( $new_title, 0, 200 );
		}

		$reason = (string) ( $opts['reason'] ?? 'user' );
		if ( ! in_array( $reason, [ 'user', 'auto_llm' ], true ) ) {
			$reason = 'user';
		}

		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );

		$old_title = $this->latest_title( $session_id );
		$payload   = [
			'session_id' => $session_id,
			'new_title'  => $new_title,
			'reason'     => $reason,
		];
		if ( $old_title !== '' ) {
			$payload['old_title'] = $old_title;
		}

		try {
			$uuid = BizCity_Twin_Event_Bus::dispatch_v2(
				'brain_session_renamed',
				$payload,
				[
					'event_source' => 'system',
					'session_id'   => $session_id,
					'user_id'      => $user_id,
				]
			);
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'dispatch_failed', 'message' => $e->getMessage() ];
		}

		return [ 'ok' => true, 'event_uuid' => $uuid, 'new_title' => $new_title ];
	}

	/**
	 * [2026-06-03 Johnny Chu] BS-2 — Soft-archive a session. Idempotent.
	 */
	public function archive( string $session_id, array $opts = [] ): array {
		if ( ! self::is_valid_session_id( $session_id ) ) {
			return [ 'ok' => false, 'error' => 'invalid_session_id' ];
		}
		if ( $this->has_event( $session_id, 'brain_session_archived' ) ) {
			return [ 'ok' => true, 'already_archived' => true ];
		}
		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );
		$payload = [
			'session_id'  => $session_id,
			'archived_at' => gmdate( 'c' ),
		];
		if ( ! empty( $opts['reason'] ) ) {
			$payload['reason'] = (string) $opts['reason'];
		}
		try {
			$uuid = BizCity_Twin_Event_Bus::dispatch_v2(
				'brain_session_archived',
				$payload,
				[
					'event_source' => 'system',
					'session_id'   => $session_id,
					'user_id'      => $user_id,
				]
			);
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => 'dispatch_failed', 'message' => $e->getMessage() ];
		}
		return [ 'ok' => true, 'event_uuid' => $uuid ];
	}

	// ------------------------------------------------------------------
	// Reads (VIEW + payload enrichment)
	// ------------------------------------------------------------------

	/**
	 * [2026-06-03 Johnny Chu] BS-2 — List sessions for a user, newest activity
	 * first. Uses VIEW bizcity_brain_sessions for aggregates and a single
	 * targeted SELECT on event_stream to enrich title (latest renamed event).
	 *
	 * @param array $args {
	 *   @type int    $user_id  REQUIRED.
	 *   @type bool   $include_archived  Default false.
	 *   @type int    $limit    Default 50, max 200.
	 *   @type int    $offset   Default 0.
	 * }
	 * @return array { items[], total }
	 */
	public function list_for_user( array $args ): array {
		global $wpdb;
		$user_id  = (int) ( $args['user_id'] ?? get_current_user_id() );
		if ( $user_id <= 0 ) {
			return [ 'items' => [], 'total' => 0 ];
		}
		$include_archived = ! empty( $args['include_archived'] );
		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$view = BizCity_TwinBrain_Schema::sessions_view_name();
		$prev = $wpdb->suppress_errors( true );
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $view ) ) === $view );
		if ( ! $exists ) {
			$wpdb->suppress_errors( $prev );
			return [ 'items' => [], 'total' => 0, 'error' => 'view_missing' ];
		}

		$where = "user_id = %d";
		$params = [ $user_id ];
		if ( ! $include_archived ) {
			$where .= ' AND has_archived = 0';
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$view} WHERE {$where}",
			...$params
		) );

		$rows_sql = $wpdb->prepare(
			"SELECT session_id, blog_id, user_id, started_at, last_activity_at,
			        duration_ms, turn_count, assistant_count, k_total_events,
			        has_created, has_archived, has_renamed, has_mood, has_carry_forward
			 FROM {$view}
			 WHERE {$where}
			 ORDER BY last_activity_at DESC
			 LIMIT %d OFFSET %d",
			array_merge( $params, [ $limit, $offset ] )
		);
		$rows = $wpdb->get_results( $rows_sql, ARRAY_A ) ?: [];
		$wpdb->suppress_errors( $prev );

		$items = [];
		foreach ( $rows as $r ) {
			$sid   = (string) $r['session_id'];
			$title = $this->latest_title( $sid );
			$items[] = [
				'session_id'        => $sid,
				'title'             => $title,
				'started_at'        => (string) $r['started_at'],
				'last_activity_at'  => (string) $r['last_activity_at'],
				'duration_ms'       => (int)    $r['duration_ms'],
				'turn_count'        => (int)    $r['turn_count'],
				'assistant_count'   => (int)    $r['assistant_count'],
				'event_count'       => (int)    $r['k_total_events'],
				'archived'          => (bool) (int) $r['has_archived'],
				'renamed'           => (bool) (int) $r['has_renamed'],
				'has_mood'          => (bool) (int) $r['has_mood'],
				'has_carry_forward' => (bool) (int) $r['has_carry_forward'],
			];
		}

		return [ 'items' => $items, 'total' => $total ];
	}

	/**
	 * [2026-06-03 Johnny Chu] BS-2 — Get one session detail (aggregates +
	 * latest title + latest mood). Returns empty array if not found / not
	 * owned by $user_id (when supplied).
	 */
	public function get( string $session_id, int $user_id = 0 ): array {
		if ( ! self::is_valid_session_id( $session_id ) ) {
			return [];
		}
		global $wpdb;
		$view = BizCity_TwinBrain_Schema::sessions_view_name();
		$prev = $wpdb->suppress_errors( true );
		$row  = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$view} WHERE session_id = %s LIMIT 1",
			$session_id
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );

		if ( ! $row ) {
			return [];
		}
		if ( $user_id > 0 && (int) $row['user_id'] !== $user_id ) {
			return [];
		}

		$out = [
			'session_id'        => (string) $row['session_id'],
			'user_id'           => (int)    $row['user_id'],
			'blog_id'           => (int)    $row['blog_id'],
			'title'             => $this->latest_title( $session_id ),
			'started_at'        => (string) $row['started_at'],
			'last_activity_at'  => (string) $row['last_activity_at'],
			'duration_ms'       => (int)    $row['duration_ms'],
			'turn_count'        => (int)    $row['turn_count'],
			'assistant_count'   => (int)    $row['assistant_count'],
			'event_count'       => (int)    $row['k_total_events'],
			'archived'          => (bool) (int) $row['has_archived'],
			'renamed'           => (bool) (int) $row['has_renamed'],
			'has_mood'          => (bool) (int) $row['has_mood'],
			'has_carry_forward' => (bool) (int) $row['has_carry_forward'],
		];
		$mood = $this->latest_mood( $session_id );
		if ( $mood ) {
			$out['mood'] = $mood;
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	private function event_stream_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_twin_event_stream';
	}

	/**
	 * Return whether at least one event of the given type exists for the
	 * session. Defensive: returns false if table is missing.
	 */
	private function has_event( string $session_id, string $event_type ): bool {
		global $wpdb;
		$tbl  = $this->event_stream_table();
		$prev = $wpdb->suppress_errors( true );
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$tbl} WHERE session_id = %s AND event_type = %s LIMIT 1",
			$session_id, $event_type
		) );
		$wpdb->suppress_errors( $prev );
		return $exists === 1;
	}

	/**
	 * Latest title for a session. Falls back to brain_session_created.title
	 * when no rename event is present. Empty string when neither.
	 */
	public function latest_title( string $session_id ): string {
		global $wpdb;
		$tbl  = $this->event_stream_table();
		$prev = $wpdb->suppress_errors( true );

		// 1) Latest rename.
		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT payload_json FROM {$tbl}
			 WHERE session_id = %s AND event_type = 'brain_session_renamed'
			 ORDER BY id DESC LIMIT 1",
			$session_id
		) );
		if ( $row ) {
			$dec = json_decode( $row, true );
			if ( is_array( $dec ) && ! empty( $dec['new_title'] ) ) {
				$wpdb->suppress_errors( $prev );
				return (string) $dec['new_title'];
			}
		}
		// 2) Created title (if any).
		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT payload_json FROM {$tbl}
			 WHERE session_id = %s AND event_type = 'brain_session_created'
			 ORDER BY id ASC LIMIT 1",
			$session_id
		) );
		$wpdb->suppress_errors( $prev );
		if ( $row ) {
			$dec = json_decode( $row, true );
			if ( is_array( $dec ) && ! empty( $dec['title'] ) ) {
				return (string) $dec['title'];
			}
		}
		return '';
	}

	/**
	 * Latest empathic mood sample (BS-3 scaffold; safe before Memory_Writer Mode 4 ships).
	 */
	public function latest_mood( string $session_id ) {
		global $wpdb;
		$tbl  = $this->event_stream_table();
		$prev = $wpdb->suppress_errors( true );
		$row  = $wpdb->get_var( $wpdb->prepare(
			"SELECT payload_json FROM {$tbl}
			 WHERE session_id = %s AND event_type = 'brain_session_mood_sampled'
			 ORDER BY id DESC LIMIT 1",
			$session_id
		) );
		$wpdb->suppress_errors( $prev );
		if ( ! $row ) return null;
		$dec = json_decode( $row, true );
		if ( ! is_array( $dec ) ) return null;
		return [
			'turn_index' => (int)   ( $dec['turn_index'] ?? 0 ),
			'valence'    => (float) ( $dec['valence']    ?? 0 ),
			'label'      => (string)( $dec['label']      ?? '' ),
		];
	}
}
