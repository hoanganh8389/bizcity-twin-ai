<?php
/**
 * Event-sourced repository for Twin Goal Loop snapshots.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_Repository {

	const CACHE_GROUP = 'twin_goal_loop';
	const CACHE_TTL   = 60;
	const IDENTITY_SCAN_BATCH = 200;
	const IDENTITY_SCAN_PAGES = 5;

	/**
	 * Read the newest goal snapshot for one tenant/identity/session scope.
	 *
	 * @return array<string,mixed>
	 */
	public static function latest( int $blog_id, string $identity_uuid, string $session_id ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — resolve newest snapshot by tenant, identity, and session.
		$blog_id = max( 0, $blog_id );
		$identity_uuid = strtolower( trim( $identity_uuid ) );
		$session_id = trim( $session_id );
		if ( $blog_id <= 0 || $identity_uuid === '' || $session_id === '' ) {
			return array();
		}
		$cache_key = 'latest_' . md5( $blog_id . '|' . $identity_uuid . '|' . $session_id );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}

		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$event_types = array( 'twin_goal_opened', 'twin_goal_progressed', 'twin_goal_closed' );
		$placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );
		$sql = "SELECT id, event_uuid, event_type, payload_json, created_at, created_epoch_ms
			FROM {$table}
			WHERE blog_id = %d AND session_id = %s
			AND event_type IN ({$placeholders})
			AND payload_json LIKE %s
			ORDER BY id DESC LIMIT 100";
		$params = array_merge( array( $blog_id, $session_id ), $event_types, array( '%"identity_uuid":"' . $wpdb->esc_like( $identity_uuid ) . '"%' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$latest = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
				if ( ! is_array( $payload ) || (string) ( $payload['identity_uuid'] ?? '' ) !== $identity_uuid ) {
					continue;
				}
				$state = isset( $payload['state'] ) && is_array( $payload['state'] ) ? $payload['state'] : $payload;
				$state['identity_uuid'] = $identity_uuid;
				$state['blog_id'] = $blog_id;
				$state['session_id'] = $session_id;
				$latest = class_exists( 'BizCity_TwinBrain_Goal_Loop_State' )
					? BizCity_TwinBrain_Goal_Loop_State::normalize( $state )
					: $state;
				// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — retain read-only event metadata after canonical state normalization so cross-session resume does not reopen an existing goal.
				$latest['_event_type'] = (string) $row['event_type'];
				$latest['_event_id'] = (int) ( $row['id'] ?? 0 );
				$latest['_event_uuid'] = sanitize_text_field( (string) ( $row['event_uuid'] ?? '' ) );
				$latest['_created_at'] = (string) ( $row['created_at'] ?? '' );
				break;
			}
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $latest, self::CACHE_TTL );
		}
		return $latest;
	}

	/**
	 * Read the newest non-terminal goal for an identity across all sessions.
	 *
	 * @return array<string,mixed>
	 */
	public static function latest_active_by_identity( int $blog_id, string $identity_uuid ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — resume active goals across channel/session boundaries.
		$blog_id = max( 0, $blog_id );
		$identity_uuid = strtolower( trim( $identity_uuid ) );
		if ( $blog_id <= 0 || $identity_uuid === '' ) {
			return array();
		}
		$cache_key = 'active_identity_' . md5( $blog_id . '|' . $identity_uuid );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}

		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$event_types = array( 'twin_goal_opened', 'twin_goal_progressed', 'twin_goal_closed' );
		$placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );
		$latest_by_goal = array();
		$cursor_id = PHP_INT_MAX;
		for ( $page = 0; $page < self::IDENTITY_SCAN_PAGES; $page++ ) {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P2 — bounded keyset scan avoids false misses from one LIMIT 200 window without unbounded HTTP work.
			$sql = "SELECT id, event_uuid, event_type, payload_json, session_id, created_at
				FROM {$table}
				WHERE blog_id = %d
				AND id < %d
				AND event_type IN ({$placeholders})
				AND payload_json LIKE %s
				ORDER BY id DESC LIMIT %d";
			$params = array_merge(
				array( $blog_id, $cursor_id ),
				$event_types,
				array( '%"identity_uuid":"' . $wpdb->esc_like( $identity_uuid ) . '"%', self::IDENTITY_SCAN_BATCH )
			);
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
				if ( ! is_array( $payload ) || strtolower( trim( (string) ( $payload['identity_uuid'] ?? '' ) ) ) !== $identity_uuid ) {
					continue;
				}
				$state = isset( $payload['state'] ) && is_array( $payload['state'] ) ? $payload['state'] : $payload;
				$state['identity_uuid'] = $identity_uuid;
				$state['blog_id'] = $blog_id;
				$state['session_id'] = (string) ( $row['session_id'] ?? $state['session_id'] ?? '' );
				$normalized = BizCity_TwinBrain_Goal_Loop_State::normalize( $state );
				// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — preserve the source event marker while rebasing an active goal onto a new channel session.
				$normalized['_event_type'] = (string) ( $row['event_type'] ?? '' );
				$normalized['_event_id'] = (int) ( $row['id'] ?? 0 );
				$normalized['_event_uuid'] = sanitize_text_field( (string) ( $row['event_uuid'] ?? '' ) );
				$normalized['_created_at'] = (string) ( $row['created_at'] ?? '' );
				$goal_id = (string) ( $normalized['goal_id'] ?? '' );
				if ( $goal_id === '' || isset( $latest_by_goal[ $goal_id ] ) ) {
					continue;
				}
				$latest_by_goal[ $goal_id ] = $normalized;
			}
			$last_row = end( $rows );
			$last_id = (int) ( $last_row['id'] ?? 0 );
			if ( $last_id <= 0 || count( $rows ) < self::IDENTITY_SCAN_BATCH ) {
				break;
			}
			$cursor_id = $last_id;
		}
		$latest = array();
		foreach ( $latest_by_goal as $state ) {
			if ( ! BizCity_TwinBrain_Goal_Loop_State::is_terminal( (string) ( $state['status'] ?? '' ) ) ) {
				$latest = $state;
				break;
			}
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $latest, self::CACHE_TTL );
		}
		return $latest;
	}

	public static function invalidate( int $blog_id, string $identity_uuid, string $session_id ): void {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — clear snapshot cache after a new goal event.
		if ( class_exists( 'BizCity_Cache' ) ) {
			$key = 'latest_' . md5( max( 0, $blog_id ) . '|' . strtolower( trim( $identity_uuid ) ) . '|' . trim( $session_id ) );
			BizCity_Cache::delete( self::CACHE_GROUP, $key );
			$identity_key = 'active_identity_' . md5( max( 0, $blog_id ) . '|' . strtolower( trim( $identity_uuid ) ) );
			BizCity_Cache::delete( self::CACHE_GROUP, $identity_key );
		}
	}

	public static function open( array $state, array $opts = array() ): string {
		$uuid = self::write_snapshot( 'twin_goal_opened', $state, $opts );
		return $uuid;
	}

	public static function progress( array $state, array $opts = array() ): string {
		$uuid = self::write_snapshot( 'twin_goal_progressed', $state, $opts );
		return $uuid;
	}

	public static function close( array $state, array $opts = array() ): string {
		$uuid = self::write_snapshot( 'twin_goal_closed', $state, $opts );
		return $uuid;
	}

	public static function trace_projection( $event, array $state, array $opts = array(), $reason = 'projection_updated' ) {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-MVP — trace non-canonical projection repair without pretending it is a Goal Event.
		self::trace( $event, $state, $opts, (string) ( $opts['event_uuid'] ?? '' ), $reason );
	}

	private static function trace( $event, array $state, array $opts, $event_uuid, $reason ) {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-MVP — JSONL trace is operational evidence only; never log prompts, full identity UUIDs, tokens, or PII.
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return;
		}
		$session_id = sanitize_text_field( (string) ( $state['session_id'] ?? $opts['session_id'] ?? '' ) );
		$identity_uuid = strtolower( trim( (string) ( $opts['identity_uuid'] ?? $state['identity_uuid'] ?? '' ) ) );
		$platform = sanitize_key( (string) ( $opts['platform'] ?? $state['platform'] ?? 'unknown' ) );
		$client_id = sanitize_text_field( (string) ( $opts['external_user_id'] ?? $opts['client_id'] ?? '' ) );
		$client_segment = $client_id !== '' ? $client_id : 'identity_' . substr( sha1( $identity_uuid ), 0, 12 );
		$event_id = 0;
		if ( $event_uuid !== '' && class_exists( 'BizCity_Twin_Event_Store' ) && method_exists( 'BizCity_Twin_Event_Store', 'id_for_uuid' ) ) {
			$event_id = (int) BizCity_Twin_Event_Store::id_for_uuid( (string) $event_uuid );
		}
		$ctx = array(
				'goal_id'          => sanitize_text_field( (string) ( $state['goal_id'] ?? '' ) ),
				'session_hash'     => $session_id !== '' ? substr( sha1( $session_id ), 0, 12 ) : '',
				'identity_hash'    => $identity_uuid !== '' ? substr( sha1( $identity_uuid ), 0, 12 ) : '',
				'client_hash'      => $client_id !== '' ? substr( sha1( $client_id ), 0, 12 ) : '',
				'platform'         => $platform,
				'case_id'          => sanitize_text_field( (string) ( $state['case_id'] ?? '' ) ),
				'memory_scope'     => sanitize_key( (string) ( $state['memory_scope'] ?? '' ) ),
				'case_scope_state' => sanitize_key( (string) ( $state['case_scope_state'] ?? '' ) ),
				'event_id'         => $event_id,
				'event_uuid'       => sanitize_text_field( (string) $event_uuid ),
				'trace_id'         => sanitize_text_field( (string) ( $opts['trace_id'] ?? '' ) ),
				'event_source'     => sanitize_key( (string) ( $opts['event_source'] ?? 'twinbrain' ) ),
				'status'           => sanitize_key( (string) ( $state['status'] ?? '' ) ),
				'reason'           => sanitize_key( (string) $reason ),
		);
		$written = method_exists( 'BizCity_JSONL_File_Logger', 'write_scoped' )
			? BizCity_JSONL_File_Logger::write_scoped(
				'bizcity-twinbrain-logs',
				array( 'twinbrain-goal-loop', $platform !== '' ? $platform : 'unknown', $client_segment ),
				$event_uuid !== '' ? 'info' : 'warn',
				(string) $event,
				'Goal Loop state transition observed.',
				$ctx
			)
			: BizCity_JSONL_File_Logger::write(
				'bizcity-twinbrain-logs',
				'twinbrain-goal-loop',
				$event_uuid !== '' ? 'info' : 'warn',
				(string) $event,
				'Goal Loop state transition observed.',
				$ctx
			);
		if ( ! $written ) {
			// [2026-08-03 Johnny Chu] R-TGL-CS — expose the effective scoped path when JSONL cannot be created.
			$location = method_exists( 'BizCity_JSONL_File_Logger', 'location_scoped' )
				? BizCity_JSONL_File_Logger::location_scoped( 'bizcity-twinbrain-logs', array( 'twinbrain-goal-loop', $platform !== '' ? $platform : 'unknown', $client_segment ) )
				: BizCity_JSONL_File_Logger::location( 'bizcity-twinbrain-logs', 'twinbrain-goal-loop' );
			error_log( '[TwinBrain][goal-loop] JSONL write failed: ' . wp_json_encode( $location ) );
		}
	}

	private static function write_snapshot( string $event_type, array $state, array $opts ): string {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — persist only normalized snapshots through the canonical Event Bus.
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) || ! method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) || ! class_exists( 'BizCity_TwinBrain_Goal_Loop_State' ) ) {
			return '';
		}
		$normalized = BizCity_TwinBrain_Goal_Loop_State::normalize( $state );
		$identity_uuid = strtolower( trim( (string) ( $opts['identity_uuid'] ?? $state['identity_uuid'] ?? '' ) ) );
		$blog_id = (int) ( $opts['blog_id'] ?? get_current_blog_id() );
		if ( $identity_uuid === '' || $blog_id <= 0 || (string) $normalized['goal_id'] === '' || (string) $normalized['session_id'] === '' ) {
			return '';
		}
		if ( $event_type !== 'twin_goal_opened' ) {
			// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — reject writes without an existing goal snapshot.
			$current = self::latest( $blog_id, $identity_uuid, (string) $normalized['session_id'] );
			if ( empty( $current ) && method_exists( __CLASS__, 'latest_active_by_identity' ) ) {
				// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G4 — allow a resumed goal to progress after session rebase.
				$current = self::latest_active_by_identity( $blog_id, $identity_uuid );
			}
			if ( empty( $current ) || (string) ( $current['goal_id'] ?? '' ) !== (string) $normalized['goal_id'] || ! BizCity_TwinBrain_Goal_Loop_State::can_transition(
				(string) ( $current['status'] ?? '' ),
				(string) $normalized['status'],
				$normalized
			) ) {
				error_log( '[TwinBrain][goal-loop] rejected invalid or orphaned state transition' );
				return '';
			}
		}
		$payload = array_merge(
			array(
				'goal_id' => (string) $normalized['goal_id'],
				'session_id' => (string) $normalized['session_id'],
				'status' => (string) $normalized['status'],
				'completion_score' => (float) $normalized['completion_score'],
				'state' => $normalized,
				'identity_uuid' => $identity_uuid,
			),
			$event_type === 'twin_goal_opened' ? array( 'primary_goal' => (string) $normalized['primary_goal'] ) : array(),
			$event_type === 'twin_goal_closed' ? array( 'closure_signal' => (array) ( $normalized['closure_signal'] ?? array() ) ) : array()
		);
		try {
			$uuid = BizCity_Twin_Event_Bus::dispatch_v2( $event_type, $payload, array(
				'event_source' => (string) ( $opts['event_source'] ?? 'twinbrain' ),
				'trace_id' => (string) ( $opts['trace_id'] ?? '' ),
				'session_id' => (string) $normalized['session_id'],
				'user_id' => (int) ( $opts['user_id'] ?? $normalized['user_id'] ?? 0 ),
				'blog_id' => $blog_id,
			) );
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — retain the numeric event cursor through the canonical Event Store without making event_id a second source of truth.
			if ( $uuid !== '' && class_exists( 'BizCity_Twin_Event_Store' ) && method_exists( 'BizCity_Twin_Event_Store', 'id_for_uuid' ) ) {
				$normalized['_event_id'] = (int) BizCity_Twin_Event_Store::id_for_uuid( (string) $uuid );
			}
			// [2026-08-03 Johnny Chu] G12.5 — project only after the canonical Event Stream append; projection/JSONL failures never replace the source event.
			if ( $uuid !== '' && class_exists( 'BizCity_TwinBrain_Goal_Contract_Store' ) && method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'upsert' ) ) {
				BizCity_TwinBrain_Goal_Contract_Store::upsert( $normalized, $opts, (string) $uuid, $event_type );
			}
			// [2026-08-03 Johnny Chu] R-TGL-CS — write operational JSONL only
			// after the canonical Event Stream append succeeds.
			self::trace( $event_type, $normalized, $opts, (string) $uuid, 'canonical_event_written' );
			self::invalidate( $blog_id, $identity_uuid, (string) $normalized['session_id'] );
			return (string) $uuid;
		} catch ( \Throwable $e ) {
			error_log( '[TwinBrain][goal-loop] snapshot write skipped: ' . get_class( $e ) . ' ' . $e->getMessage() );
			return '';
		}
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register(
		BizCity_TwinBrain_Goal_Loop_Repository::CACHE_GROUP,
		'core.twinbrain.goal-loop',
		array(
			'latest_{blog_id}_{identity_hash}_{session_hash}' => array(
				'ttl' => BizCity_TwinBrain_Goal_Loop_Repository::CACHE_TTL,
				'desc' => 'Latest Twin Goal Loop snapshot by tenant, identity, and session',
			),
			'active_identity_{blog_id}_{identity_hash}' => array(
				'ttl' => BizCity_TwinBrain_Goal_Loop_Repository::CACHE_TTL,
				'desc' => 'Latest active Twin Goal Loop snapshot across sessions for an identity',
			),
		)
	);
}
