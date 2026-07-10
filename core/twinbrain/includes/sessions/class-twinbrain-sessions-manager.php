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
		$exists = bizcity_tbl_exists( $view ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
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
	 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-11 — Full-text search over a
	 * user's session pool. Matches against:
	 *   • title (latest brain_session_renamed.new_title or created.title),
	 *   • session_id substring,
	 *   • user_message.text / assistant_message.* payloads in event_stream.
	 *
	 * Pipeline:
	 *   1) Find distinct session_ids (this user) whose payload_json LIKE %q%
	 *      OR session_id LIKE %q% — capped to $limit candidates ordered by
	 *      most-recent matching event id.
	 *   2) Enrich each candidate with VIEW aggregates + latest_title +
	 *      a snippet around the first matching token (~120 chars on each side).
	 *
	 * Read-only. PHP 7.4 compatible.
	 *
	 * @param array $args {
	 *   @type int    $user_id REQUIRED.
	 *   @type string $q       REQUIRED. Trimmed; <2 chars → empty result.
	 *   @type int    $limit   Default 30, max 50.
	 * }
	 * @return array { items[], total, q, [error] }
	 */
	public function search( array $args ): array {
		// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-11 — entry point
		global $wpdb;
		$user_id = (int) ( $args['user_id'] ?? get_current_user_id() );
		$q       = trim( (string) ( $args['q'] ?? '' ) );
		$limit   = max( 1, min( 50, (int) ( $args['limit'] ?? 30 ) ) );
		$q_len   = function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q );
		if ( $user_id <= 0 || $q_len < 2 ) {
			return [ 'items' => [], 'total' => 0, 'q' => $q ];
		}

		$view = BizCity_TwinBrain_Schema::sessions_view_name();
		$tbl  = $this->event_stream_table();
		$prev = $wpdb->suppress_errors( true );
		$exists = bizcity_tbl_exists( $view ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( ! $exists ) {
			$wpdb->suppress_errors( $prev );
			return [ 'items' => [], 'total' => 0, 'error' => 'view_missing', 'q' => $q ];
		}

		$like  = '%' . $wpdb->esc_like( $q ) . '%';
		$event_types = [ 'user_message', 'assistant_message', 'brain_session_renamed', 'brain_session_created' ];
		$ph_evt = implode( ',', array_fill( 0, count( $event_types ), '%s' ) );

		$sql_candidates = $wpdb->prepare(
			"SELECT session_id, MAX(id) AS last_match_id
			 FROM {$tbl}
			 WHERE user_id = %d
			   AND session_id LIKE 'brain\\_sess\\_%'
			   AND event_type IN ({$ph_evt})
			   AND ( payload_json LIKE %s OR session_id LIKE %s )
			 GROUP BY session_id
			 ORDER BY last_match_id DESC
			 LIMIT %d",
			array_merge( [ $user_id ], $event_types, [ $like, $like, $limit ] )
		);
		$candidates = $wpdb->get_results( $sql_candidates, ARRAY_A ) ?: [];

		if ( empty( $candidates ) ) {
			$wpdb->suppress_errors( $prev );
			return [ 'items' => [], 'total' => 0, 'q' => $q ];
		}

		$sids = [];
		foreach ( $candidates as $c ) {
			$sids[] = (string) $c['session_id'];
		}

		$ph_sids  = implode( ',', array_fill( 0, count( $sids ), '%s' ) );
		$rows_sql = $wpdb->prepare(
			"SELECT session_id, blog_id, user_id, started_at, last_activity_at,
			        duration_ms, turn_count, assistant_count, k_total_events,
			        has_archived, has_renamed, has_mood, has_carry_forward
			 FROM {$view}
			 WHERE user_id = %d AND session_id IN ({$ph_sids})",
			array_merge( [ $user_id ], $sids )
		);
		$rows = $wpdb->get_results( $rows_sql, ARRAY_A ) ?: [];
		$wpdb->suppress_errors( $prev );

		$by_sid = [];
		foreach ( $rows as $r ) {
			$by_sid[ (string) $r['session_id'] ] = $r;
		}

		$items = [];
		foreach ( $candidates as $c ) {
			$sid = (string) $c['session_id'];
			$r   = $by_sid[ $sid ] ?? null;
			$snippet = $this->find_match_snippet( $sid, $q, $user_id );
			$items[] = [
				'session_id'        => $sid,
				'title'             => $this->latest_title( $sid ),
				'snippet'           => (string) ( $snippet['text']       ?? '' ),
				'snippet_event'     => (string) ( $snippet['event_type'] ?? '' ),
				'snippet_at'        => (string) ( $snippet['created_at'] ?? '' ),
				'started_at'        => $r ? (string) $r['started_at']        : '',
				'last_activity_at'  => $r ? (string) $r['last_activity_at']  : '',
				'duration_ms'       => $r ? (int)    $r['duration_ms']       : 0,
				'turn_count'        => $r ? (int)    $r['turn_count']        : 0,
				'assistant_count'   => $r ? (int)    $r['assistant_count']   : 0,
				'event_count'       => $r ? (int)    $r['k_total_events']    : 0,
				'archived'          => $r ? (bool) (int) $r['has_archived']      : false,
				'renamed'           => $r ? (bool) (int) $r['has_renamed']       : false,
				'has_mood'          => $r ? (bool) (int) $r['has_mood']          : false,
				'has_carry_forward' => $r ? (bool) (int) $r['has_carry_forward'] : false,
			];
		}

		return [ 'items' => $items, 'total' => count( $items ), 'q' => $q ];
	}

	/**
	 * [2026-06-03 Johnny Chu] BS-11 — return latest event matching $q in the
	 * given session, restricted to user/assistant message bodies. Returns
	 * a centred snippet (≤240 chars) with ellipsis flanks.
	 */
	private function find_match_snippet( string $session_id, string $q, int $user_id ): array {
		global $wpdb;
		$tbl  = $this->event_stream_table();
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$prev = $wpdb->suppress_errors( true );
		$row  = $wpdb->get_row( $wpdb->prepare(
			"SELECT event_type, payload_json, created_at FROM {$tbl}
			 WHERE session_id = %s AND user_id = %d
			   AND event_type IN ('user_message','assistant_message')
			   AND payload_json LIKE %s
			 ORDER BY id DESC LIMIT 1",
			$session_id, $user_id, $like
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		if ( ! $row ) {
			return [];
		}
		$dec  = json_decode( (string) $row['payload_json'], true );
		$text = '';
		if ( is_array( $dec ) ) {
			foreach ( [ 'text', 'content', 'message', 'final_text' ] as $k ) {
				if ( isset( $dec[ $k ] ) && is_string( $dec[ $k ] ) && $dec[ $k ] !== '' ) {
					$text = (string) $dec[ $k ];
					break;
				}
			}
		}
		return [
			'event_type' => (string) $row['event_type'],
			'text'       => $this->snippet_around( $text, $q, 120 ),
			'created_at' => (string) $row['created_at'],
		];
	}

	/**
	 * [2026-06-03 Johnny Chu] BS-11 — return up to ($window*2 + |needle|) chars
	 * centred on the first occurrence of $needle in $haystack. Pads with `…`
	 * when truncated. Multibyte-safe.
	 */
	private function snippet_around( string $haystack, string $needle, int $window ): string {
		if ( $haystack === '' || $needle === '' ) return $haystack;
		$mb      = function_exists( 'mb_strlen' );
		$hay_len = $mb ? mb_strlen( $haystack ) : strlen( $haystack );
		if ( $hay_len <= $window * 2 ) return $haystack;
		$pos = $mb ? mb_stripos( $haystack, $needle ) : stripos( $haystack, $needle );
		if ( $pos === false ) {
			$head = $mb ? mb_substr( $haystack, 0, $window * 2 ) : substr( $haystack, 0, $window * 2 );
			return $head . '…';
		}
		$start = max( 0, $pos - $window );
		$len   = $window * 2 + ( $mb ? mb_strlen( $needle ) : strlen( $needle ) );
		$cut   = $mb ? mb_substr( $haystack, $start, $len ) : substr( $haystack, $start, $len );
		if ( $start > 0 ) $cut = '…' . $cut;
		if ( $start + $len < $hay_len ) $cut .= '…';
		return $cut;
	}

	/**
	 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-12 — Hydrate the chat panel
	 * with prior turns of the given session. Returns ordered turn pairs
	 * derived from `user_message` + `assistant_message` events in
	 * `bizcity_twin_event_stream`.
	 *
	 * Pairing rule:
	 *   • Walk events ASC by id.
	 *   • Each `user_message` opens a new pending turn.
	 *   • The next `assistant_message` (with the same trace_id when present,
	 *     otherwise the next one chronologically) closes that turn.
	 *   • Trailing `user_message` with no assistant follow-up is returned
	 *     with status='pending' so FE can render it as in-flight history.
	 *
	 * Read-only. PHP 7.4 compatible.
	 *
	 * @param string $session_id
	 * @param int    $user_id  Owner guard.
	 * @param int    $limit    Max events to scan (default 200, max 500).
	 * @return array { items: array<int, array>, total: int, [error]: string }
	 */
	public function list_turns( string $session_id, int $user_id, int $limit = 200 ): array {
		if ( ! self::is_valid_session_id( $session_id ) ) {
			return [ 'items' => [], 'total' => 0, 'error' => 'invalid_session_id' ];
		}
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return [ 'items' => [], 'total' => 0, 'error' => 'no_user' ];
		}
		$limit = max( 1, min( 500, (int) $limit ) );

		global $wpdb;
		$tbl  = $this->event_stream_table();
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, trace_id, event_type, payload_json, created_at, created_epoch_ms
			 FROM {$tbl}
			 WHERE session_id = %s AND user_id = %d
			   AND event_type IN ('user_message','assistant_message')
			 ORDER BY id ASC LIMIT %d",
			$session_id, $user_id, $limit
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );

		$rows = is_array( $rows ) ? $rows : [];

		$items   = [];
		$pending = null;
		foreach ( $rows as $r ) {
			$type    = (string) $r['event_type'];
			$payload = json_decode( (string) $r['payload_json'], true );
			if ( ! is_array( $payload ) ) $payload = [];

			if ( $type === 'user_message' ) {
				if ( $pending !== null ) {
					$items[]  = $pending;
				}
				$pending = [
					'id'         => 'turn_' . (int) $r['id'],
					'trace_id'   => (string) ( $r['trace_id'] ?? '' ),
					'prompt'     => (string) ( $payload['text'] ?? '' ),
					'guru_id'    => (string) ( $payload['guru_id'] ?? '' ),
					'started_at' => (int) ( $r['created_epoch_ms'] ?? 0 ),
					'started_at_iso' => (string) $r['created_at'],
					'status'     => 'pending',
					'answer'     => '',
					'answer_at'  => '',
					'citations'  => [],
					'model'      => '',
				];
			} elseif ( $type === 'assistant_message' && $pending !== null ) {
				$pending['answer']    = (string) ( $payload['text'] ?? '' );
				$pending['answer_at'] = (string) $r['created_at'];
				$pending['status']    = 'done';
				$meta = isset( $payload['synthesis_metadata'] ) && is_array( $payload['synthesis_metadata'] )
					? $payload['synthesis_metadata'] : [];
				if ( ! empty( $meta['citations'] ) && is_array( $meta['citations'] ) ) {
					$pending['citations'] = array_values( $meta['citations'] );
				}
				$pending['model'] = (string) ( $meta['model'] ?? '' );
				// [2026-06-04 Johnny Chu] BS-12 — passthrough full result_snapshot
				// so FE session replay renders the turn identically to a live one.
				if ( isset( $payload['result_snapshot'] ) && is_array( $payload['result_snapshot'] ) ) {
					$pending['result_snapshot'] = $payload['result_snapshot'];
				}
				$items[]          = $pending;
				$pending          = null;
			}
		}
		if ( $pending !== null ) {
			$items[] = $pending;
		}

		return [ 'items' => $items, 'total' => count( $items ) ];
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
