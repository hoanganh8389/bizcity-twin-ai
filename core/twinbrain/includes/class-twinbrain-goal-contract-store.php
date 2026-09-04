<?php
/**
 * Current Goal Contract projection and fine-grained JSONL trace.
 *
 * The canonical Goal State remains in bizcity_twin_event_stream. This class is
 * a rebuildable read model and never creates schema during a normal request.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-03
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Contract_Store {

	const TABLE_BASE       = 'bizcity_twin_goal_contracts';
	const MODULE_ID        = 'core.twinbrain.goal-contracts';
	const DB_VERSION       = '1.2.0';
	const DB_VERSION_OPTION = 'bizcity_twinbrain_goal_contracts_db_version';
	const CACHE_GROUP      = 'twin_goal_contracts';
	const CACHE_TTL        = 60;
	const JSONL_FOLDER     = 'bizcity-twinbrain-logs';
	const JSONL_MODULE     = 'goal-contracts';

	private static $table_exists = array();

	/**
	 * Return the current-blog projection table.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	/**
	 * Provisioning entry point for Site Provisioner/Diagnostics only.
	 *
	 * No caller in a normal chat request should invoke this method.
	 *
	 * @return array<string,mixed>
	 */
	public static function ensure_schema(): array {
		// [2026-08-03 Johnny Chu] G12.1 — expose changelog-gated provisioning without running DDL at request file scope.
		if ( ! class_exists( 'BizCity_Diagnostics_Auto_Create' ) || ! method_exists( 'BizCity_Diagnostics_Auto_Create', 'run' ) ) {
			return array( 'ok' => false, 'reason' => 'diagnostics_auto_create_unavailable' );
		}
		$result = BizCity_Diagnostics_Auto_Create::run( self::TABLE_BASE );
		self::invalidate_table_exists_cache();
		if ( is_array( $result ) && ! empty( $result['ok'] ) ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
		return is_array( $result ) ? $result : array( 'ok' => false, 'reason' => 'provisioning_unknown' );
	}

	/**
	 * Get one current projection by tenant and goal.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_by_goal_id( int $blog_id, string $goal_id ): array {
		// [2026-08-03 Johnny Chu] G12.3 — fast current-state read is tenant and goal scoped; event_stream remains recovery source.
		$blog_id = max( 0, $blog_id );
		$goal_id = trim( $goal_id );
		if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() || $goal_id === '' || ! self::table_exists() ) {
			return array();
		}
		$cache_key = self::cache_key( $blog_id, $goal_id );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE blog_id = %d AND goal_id = %s LIMIT 1',
			$blog_id,
			$goal_id
		),
		ARRAY_A
		);
		$contract = self::decode_row( is_array( $row ) ? $row : array() );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $contract, self::CACHE_TTL );
		}
		return $contract;
	}

	/**
	 * List current active projections for Diagnostics/admin read paths.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_active( int $blog_id, int $limit = 100 ): array {
		// [2026-08-03 Johnny Chu] G12.3 — bounded active projection read uses the status/updated index.
		$blog_id = max( 0, $blog_id );
		$limit = max( 1, min( 500, $limit ) );
		if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() || ! self::table_exists() ) {
			return array();
		}
		$cache_key = 'active_list_' . $blog_id . '_' . $limit;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE blog_id = %d AND contract_status = %s ORDER BY updated_at DESC LIMIT %d',
			$blog_id,
			'active',
			$limit
		),
		ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$decoded = self::decode_row( is_array( $row ) ? $row : array() );
			if ( ! empty( $decoded ) ) {
				$out[] = $decoded;
			}
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $out, self::CACHE_TTL );
		}
		return $out;
	}

	/**
	 * Read the current contract for one identity/session scope.
	 *
	 * This is deliberately a contract read, not a Goal State read. Lifecycle,
	 * DoD, and ownership decisions remain delegated to the canonical repository.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_by_scope( int $blog_id, string $identity_uuid, string $session_id ): array {
		// [2026-08-03 Johnny Chu] G12.3 — indexed contract lookup for pre_turn/MPR consumers; never substitutes for canonical Goal State.
		$blog_id = max( 0, $blog_id );
		$identity_uuid = strtolower( trim( $identity_uuid ) );
		$session_id = trim( $session_id );
		if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() || $identity_uuid === '' || $session_id === '' || ! self::table_exists() ) {
			return array();
		}
		$cache_key = 'scope_' . $blog_id . '_' . md5( $identity_uuid . '|' . $session_id );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE blog_id = %d AND identity_uuid = %s AND session_id = %s ORDER BY event_seq DESC, updated_at DESC LIMIT 1',
				$blog_id,
				$identity_uuid,
				$session_id
			),
			ARRAY_A
		);
		$contract = self::decode_row( is_array( $row ) ? $row : array() );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $contract, self::CACHE_TTL );
		}
		return $contract;
	}

	/**
	 * Append trace events and update the current projection after a canonical event.
	 */
	public static function upsert( array $state, array $opts, string $source_event_uuid, string $source_event_type ): bool {
		// [2026-08-03 Johnny Chu] G12.5 — project only after dispatch_v2() returned a durable event UUID.
		if ( $source_event_uuid === '' || $source_event_type === '' ) {
			return false;
		}
		$normalized = class_exists( 'BizCity_TwinBrain_Goal_Loop_State' )
			? BizCity_TwinBrain_Goal_Loop_State::normalize( $state )
			: $state;
		$blog_id = max( 0, (int) ( $opts['blog_id'] ?? $normalized['blog_id'] ?? get_current_blog_id() ) );
		$goal_id = trim( (string) ( $normalized['goal_id'] ?? '' ) );
		$identity_uuid = strtolower( trim( (string) ( $opts['identity_uuid'] ?? $normalized['identity_uuid'] ?? '' ) ) );
		if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() || $goal_id === '' || $identity_uuid === '' ) {
			return false;
		}

		$source_event_id = self::event_id( $source_event_uuid );
		$existing = self::get_by_goal_id( $blog_id, $goal_id );
		$event_seq = $source_event_id;
		if ( ! empty( $existing ) && $event_seq > 0 && (int) ( $existing['event_seq'] ?? 0 ) >= $event_seq ) {
			// [2026-08-03 Johnny Chu] G12.3 — stale/out-of-order canonical events cannot move the projection backwards.
			return true;
		}

		$merged = self::merge_state( $existing, $normalized );
		$turn_id = self::turn_id( $opts, $event_seq );
		$envelope_context = array(
			'blog_id'           => $blog_id,
			'goal_id'           => $goal_id,
			'identity_uuid'     => $identity_uuid,
			'turn_id'           => $turn_id,
			'source_event_uuid' => $source_event_uuid,
			'source_event_id'   => $source_event_id,
			'event_seq'         => $event_seq,
		);

		// [2026-08-03 Johnny Chu] G12.4 — append immutable trace before the rebuildable SQL projection.
		self::append_events( $source_event_type, $merged, $existing, $opts, $envelope_context );
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;
		$row = self::row_from_state( $merged, $existing, $opts, $envelope_context );
		$ok = ! empty( $existing )
			? $wpdb->update( self::table(), $row, array( 'blog_id' => $blog_id, 'goal_id' => $goal_id ), null, array( '%d', '%s' ) )
			: $wpdb->insert( self::table(), $row );
		if ( false === $ok ) {
			self::operational_log( 'projection_stale', $envelope_context, $wpdb->last_error );
			return false;
		}
		self::invalidate_cache( $blog_id, $goal_id );
		return true;
	}

	/**
	 * Mark a goal terminal through the same canonical projection path.
	 */
	public static function close( array $state, array $opts, string $source_event_uuid ): bool {
		// [2026-08-03 Johnny Chu] G12.3 — close is a projection of the canonical terminal event, never a direct status authority.
		return self::upsert( $state, $opts, $source_event_uuid, 'twin_goal_closed' );
	}

	/**
	 * Rebuild one projection from the canonical Goal events.
	 *
	 * This is an explicit repair operation. It is never called from a chat read
	 * path and it does not create a new canonical Event Stream event.
	 *
	 * @return array<string,mixed>
	 */
	public static function rebuild_from_event_stream( int $blog_id, string $goal_id, string $identity_uuid = '', bool $rebuild_trace = false ): array {
		// [2026-08-03 Johnny Chu] G12.6 — replay canonical Goal snapshots in event order to repair stale projection/trace state.
		$blog_id = max( 0, $blog_id );
		$goal_id = trim( $goal_id );
		$identity_uuid = strtolower( trim( $identity_uuid ) );
		if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() || $goal_id === '' ) {
			return array( 'ok' => false, 'reason' => 'invalid_or_cross_blog_scope' );
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) {
			return array( 'ok' => false, 'reason' => 'event_stream_schema_unavailable' );
		}

		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$types = array( 'twin_goal_opened', 'twin_goal_progressed', 'twin_goal_closed' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_uuid, event_type, payload_json, session_id, trace_id, created_at FROM {$table} WHERE blog_id = %d AND event_type IN (%s, %s, %s) AND payload_json LIKE %s ORDER BY id ASC LIMIT 500",
				$blog_id,
				$types[0],
				$types[1],
				$types[2],
				'%\"goal_id\":\"' . $wpdb->esc_like( $goal_id ) . '\"%'
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return array( 'ok' => false, 'reason' => 'canonical_goal_events_not_found' );
		}

		$projection = array();
		$trace_rebuilt = 0;
		$valid_events = 0;
		$last_ctx = array();
		foreach ( $rows as $row ) {
			$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
			if ( ! is_array( $payload ) || (string) ( $payload['goal_id'] ?? '' ) !== $goal_id ) {
				continue;
			}
			$event_identity = strtolower( trim( (string) ( $payload['identity_uuid'] ?? '' ) ) );
			if ( $identity_uuid !== '' && $event_identity !== $identity_uuid ) {
				continue;
			}
			if ( $identity_uuid === '' ) {
				$identity_uuid = $event_identity;
			}
			$state = isset( $payload['state'] ) && is_array( $payload['state'] ) ? $payload['state'] : $payload;
			$state['goal_id'] = $goal_id;
			$state['identity_uuid'] = $event_identity;
			$state['blog_id'] = $blog_id;
			$state['session_id'] = (string) ( $payload['session_id'] ?? $row['session_id'] ?? $state['session_id'] ?? '' );
			$state = class_exists( 'BizCity_TwinBrain_Goal_Loop_State' )
				? BizCity_TwinBrain_Goal_Loop_State::normalize( $state )
				: $state;
			$previous = $projection;
			$projection = self::merge_state( $projection, $state );
			$event_id = (int) ( $row['id'] ?? 0 );
			$ctx = array(
				'blog_id' => $blog_id,
				'goal_id' => $goal_id,
				'identity_uuid' => $identity_uuid,
				'turn_id' => 'event_' . max( 0, $event_id ),
				'source_event_uuid' => sanitize_text_field( (string) ( $row['event_uuid'] ?? '' ) ),
				'source_event_id' => $event_id,
				'event_seq' => $event_id,
			);
			$last_ctx = $ctx;
			if ( $rebuild_trace && $ctx['source_event_uuid'] !== '' ) {
				self::append_events( (string) ( $row['event_type'] ?? '' ), $projection, $previous, array( 'turn_id' => $ctx['turn_id'] ), $ctx );
				$trace_rebuilt++;
			}
			$valid_events++;
		}
		if ( $valid_events === 0 || empty( $last_ctx ) || ! self::table_exists() ) {
			return array( 'ok' => false, 'reason' => $valid_events === 0 ? 'canonical_goal_events_invalid' : 'projection_table_unavailable', 'event_count' => $valid_events );
		}

		$projection['identity_uuid'] = $identity_uuid;
		$projection['turn_count'] = $valid_events;
		global $wpdb;
		$existing_projection = self::get_by_goal_id( $blog_id, $goal_id );
		$row_data = self::row_from_state( $projection, $existing_projection, array( 'session_id' => $projection['session_id'] ), $last_ctx );
		$write_ok = ! empty( $existing_projection )
			? $wpdb->update( self::table(), $row_data, array( 'blog_id' => $blog_id, 'goal_id' => $goal_id ), null, array( '%d', '%s' ) )
			: $wpdb->insert( self::table(), $row_data );
		if ( false === $write_ok ) {
			self::operational_log( 'projection_stale', $last_ctx, $wpdb->last_error );
			return array( 'ok' => false, 'reason' => 'projection_rebuild_failed', 'event_count' => $valid_events, 'trace_rebuilt' => $trace_rebuilt );
		}
		self::invalidate_cache( $blog_id, $goal_id );
		return array(
			'ok' => true,
			'reason' => 'projection_rebuilt',
			'event_count' => $valid_events,
			'trace_rebuilt' => $trace_rebuilt,
			'projection' => self::get_by_goal_id( $blog_id, $goal_id ),
		);
	}

	/**
	 * Reconcile a projection; repair only when it is missing or behind source.
	 *
	 * @return array<string,mixed>
	 */
	public static function reconcile( int $blog_id, string $goal_id, string $identity_uuid = '', bool $repair_trace = false ): array {
		// [2026-08-03 Johnny Chu] G12.6 — explicit stale/missing projection reconciliation, never an implicit runtime fallback scan.
		$current = self::get_by_goal_id( $blog_id, $goal_id );
		$latest_event_id = self::latest_source_event_id( $blog_id, $goal_id );
		$current_event_id = (int) ( $current['event_seq'] ?? 0 );
		if ( ! empty( $current ) && ! $repair_trace && ( $latest_event_id <= 0 || $current_event_id >= $latest_event_id ) ) {
			return array( 'ok' => true, 'reason' => 'projection_present', 'repaired' => false, 'projection' => $current );
		}
		$result = self::rebuild_from_event_stream( $blog_id, $goal_id, $identity_uuid, $repair_trace );
		$result['repaired'] = ! empty( $result['ok'] );
		return $result;
	}

	private static function latest_source_event_id( int $blog_id, string $goal_id ): int {
		if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() || ! class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) {
			return 0;
		}
		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE blog_id = %d AND event_type IN (%s, %s, %s) AND payload_json LIKE %s ORDER BY id DESC LIMIT 1",
			$blog_id,
			'twin_goal_opened',
			'twin_goal_progressed',
			'twin_goal_closed',
			'%\"goal_id\":\"' . $wpdb->esc_like( $goal_id ) . '\"%'
		) );
		return (int) ( $id ?: 0 );
	}

	/**
	 * Return the stable per-goal JSONL path and create its directory when possible.
	 */
	public static function jsonl_path( string $goal_id ): string {
		$uploads = wp_upload_dir( null, false );
		$base = (string) ( $uploads['basedir'] ?? '' );
		if ( $base === '' || trim( $goal_id ) === '' ) {
			return '';
		}
		$path_key = self::goal_path_key( $goal_id );
		$directory = rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . self::JSONL_FOLDER . DIRECTORY_SEPARATOR . self::JSONL_MODULE . DIRECTORY_SEPARATOR . $path_key;
		if ( ! wp_mkdir_p( $directory ) ) {
			return '';
		}
		$root = rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . self::JSONL_FOLDER;
		if ( ! file_exists( $root . DIRECTORY_SEPARATOR . '.htaccess' ) ) {
			// [2026-08-03 Johnny Chu] G12.4 — prevent direct web access to contract trace files, matching BizCity_JSONL_File_Logger policy.
			@file_put_contents( $root . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all\nOptions -Indexes\n" );
		}
		return $directory . DIRECTORY_SEPARATOR . 'events.jsonl';
	}

	private static function merge_state( array $existing, array $state ): array {
		// [2026-08-03 Johnny Chu] G12.3 — preserve immutable owner/goal fields and merge obligations monotonically.
		if ( ! empty( $existing ) ) {
			$state['identity_uuid'] = (string) ( $existing['identity_uuid'] ?? $state['identity_uuid'] ?? '' );
			$state['conversation_goal'] = is_array( $existing['conversation_goal'] ?? null )
				? $existing['conversation_goal']
				: (array) ( $state['conversation_goal'] ?? array() );
		}
		$old = self::obligation_map( (array) ( $existing['obligations'] ?? $existing['answer_obligations'] ?? array() ) );
		$new = self::obligation_map( (array) ( $state['answer_obligations'] ?? array() ) );
		foreach ( $new as $id => $item ) {
			if ( ! isset( $old[ $id ] ) ) {
				$old[ $id ] = $item;
				continue;
			}
			$old[ $id ]['status'] = self::merge_status( (string) ( $old[ $id ]['status'] ?? 'open' ), (string) ( $item['status'] ?? 'open' ) );
			$old[ $id ]['priority'] = self::merge_priority( (string) ( $old[ $id ]['priority'] ?? 'should' ), (string) ( $item['priority'] ?? 'should' ) );
			$old[ $id ]['updated_at'] = (string) ( $item['updated_at'] ?? $old[ $id ]['updated_at'] ?? '' );
		}
		$state['answer_obligations'] = array_values( $old );
		return $state;
	}

	private static function row_from_state( array $state, array $existing, array $opts, array $ctx ): array {
		$goal_id = (string) ( $state['goal_id'] ?? '' );
		$status = (string) ( $state['status'] ?? 'active' );
		$contract_status = $status === 'superseded' ? 'superseded' : ( in_array( $status, array( 'completed', 'cancelled', 'abandoned' ), true ) ? 'closed' : 'active' );
		$scoreboard = $state['resolution_scoreboard'] ?? null;
		return array(
			'blog_id'            => (int) $ctx['blog_id'],
			'goal_id'            => $goal_id,
			'identity_uuid'      => (string) ( $state['identity_uuid'] ?? '' ),
			'session_id'         => (string) ( $state['session_id'] ?? $opts['session_id'] ?? '' ),
			'current_turn_id'    => (string) $ctx['turn_id'],
			'scoreboard_version' => (string) ( is_array( $scoreboard ) ? ( $scoreboard['scoreboard_version'] ?? 'v1' ) : 'v1' ),
			'conversation_goal'  => self::encode( (array) ( $state['conversation_goal'] ?? array() ) ),
			'obligations_json'   => self::encode( array_values( (array) ( $state['answer_obligations'] ?? array() ) ) ),
			'scoreboard_json'    => is_array( $scoreboard ) && ! empty( $scoreboard ) ? self::encode( $scoreboard ) : null,
			'contract_status'    => $contract_status,
			'retrieve_round'     => (int) ( is_array( $scoreboard ) ? ( $scoreboard['retrieve_round'] ?? 0 ) : 0 ),
			'turn_count'         => max( 1, (int) ( $state['turn_count'] ?? ( (int) ( $existing['turn_count'] ?? 0 ) + 1 ) ) ),
			'event_stream_id'    => (int) $ctx['source_event_id'],
			'source_event_uuid'  => (string) $ctx['source_event_uuid'],
			'event_seq'         => (int) $ctx['event_seq'],
			'jsonl_path'        => self::jsonl_path( $goal_id ),
		);
	}

	private static function append_events( string $source_type, array $state, array $existing, array $opts, array $ctx ): void {
		$events = array();
		if ( $source_type === 'twin_goal_opened' ) {
			$events[] = array( 'type' => 'goal.parsed', 'payload' => array(
				'user_outcome_hash' => self::hash_value( (string) ( $state['conversation_goal']['user_outcome'] ?? '' ) ),
				'conversation_mode' => sanitize_key( (string) ( $state['conversation_goal']['conversation_mode'] ?? '' ) ),
				'obligation_count' => count( (array) ( $state['answer_obligations'] ?? array() ) ),
				'method' => 'state_parser',
			) );
			$events[] = array( 'type' => 'contract.created', 'payload' => array(
				'scoreboard_version' => 'v1',
				'obligations' => self::safe_obligations( (array) ( $state['answer_obligations'] ?? array() ) ),
			) );
		}
		if ( $source_type === 'twin_goal_progressed' ) {
			$added = self::added_obligations( (array) ( $existing['obligations'] ?? $existing['answer_obligations'] ?? array() ), (array) ( $state['answer_obligations'] ?? array() ) );
			if ( ! empty( $added ) ) {
				$events[] = array( 'type' => 'contract.patched', 'payload' => array(
					'operation' => 'ADD',
					'obligations' => self::safe_obligations( $added ),
					'patch_reason' => 'goal_state_progressed',
				) );
			}
		}
		if ( ! empty( $state['resolution_scoreboard'] ) ) {
			$scoreboard = (array) $state['resolution_scoreboard'];
			$events[] = array( 'type' => 'scoreboard.scored', 'payload' => array(
				'scoreboard_version' => (string) ( $scoreboard['scoreboard_version'] ?? 'v1' ),
				'rows' => self::safe_scoreboard_rows( (array) ( $scoreboard['rows'] ?? array() ) ),
				'overall_ready_for_final' => ! empty( $scoreboard['overall_ready_for_final'] ),
				'retrieve_round' => (int) ( $scoreboard['retrieve_round'] ?? 0 ),
				'method' => sanitize_key( (string) ( $scoreboard['method'] ?? 'deterministic_v1' ) ),
			) );
		}
		if ( ! empty( $opts['reflection_result'] ) && is_array( $opts['reflection_result'] ) ) {
			$reflection = $opts['reflection_result'];
			$events[] = array( 'type' => 'reflection.completed', 'payload' => array(
				'verdict' => sanitize_key( (string) ( $reflection['verdict'] ?? 'revise' ) ),
				'completion_score' => (float) ( $reflection['completion_score'] ?? 0 ),
				'route' => self::reflection_route( $reflection ),
				'retrieve_round' => (int) ( $reflection['retrieve_round'] ?? 0 ),
			) );
		}
		if ( $source_type === 'twin_goal_closed' ) {
			$events[] = array( 'type' => 'contract.closed', 'payload' => array(
				'reason' => sanitize_key( (string) ( $state['closure_signal']['type'] ?? 'terminal_state' ) ),
				'completion_score' => (float) ( $state['completion_score'] ?? 0 ),
				'turn_count' => (int) ( $existing['turn_count'] ?? 0 ) + 1,
			) );
		}
		foreach ( $events as $event ) {
			self::append_jsonl_event( (string) $event['type'], (array) $event['payload'], $ctx );
		}
	}

	private static function append_jsonl_event( string $event_type, array $payload, array $ctx ): bool {
		// [2026-08-03 Johnny Chu] G12.4 — immutable per-goal JSONL envelope with stable retry identity and scrubbed payload.
		$payload = self::scrub_payload( $payload );
		$event_id = sha1( (string) $ctx['source_event_uuid'] . '|' . $event_type . '|' . self::encode( $payload ) );
		$now = function_exists( 'wp_date' ) ? wp_date( 'c' ) : gmdate( 'c' );
		$row = array(
			'schema_version' => 'goal_contract.v1',
			'event_id' => $event_id,
			'event_type' => $event_type,
			'goal_id' => (string) $ctx['goal_id'],
			'turn_id' => (string) $ctx['turn_id'],
			'source_event_id' => (string) $ctx['source_event_uuid'],
			'event_seq' => (int) $ctx['event_seq'],
			'occurred_at' => $now,
			'recorded_at' => $now,
			'payload' => $payload,
		);
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'write_scoped_contract' ) ) {
			$written = BizCity_JSONL_File_Logger::write_scoped_contract( 'core.twinbrain.goal_contract_trace', array( self::JSONL_MODULE, self::goal_path_key( (string) $ctx['goal_id'] ) ), 'info', $event_type, 'Goal Contract event observed.', array(
				'event_id' => $event_id,
				'event_identity' => $event_id,
				'event_uuid' => self::event_uuid_from_identity( $event_id ),
				'goal_id' => (string) $ctx['goal_id'],
				'source_event_id' => (string) $ctx['source_event_uuid'],
			) );
			if ( ! $written ) {
				self::operational_log( 'trace_missing', $ctx, 'jsonl_append_failed' );
			}
			return (bool) $written;
		}
		self::operational_log( 'trace_missing', $ctx, 'shared_logger_unavailable' );
		return false;
	}

	private static function event_uuid_from_identity( string $identity ): string {
		$hash = sha1( $identity );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-5' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
	}

	private static function decode_row( array $row ): array {
		if ( empty( $row ) ) {
			return array();
		}
		$row['conversation_goal'] = json_decode( (string) ( $row['conversation_goal'] ?? '{}' ), true );
		$row['obligations'] = json_decode( (string) ( $row['obligations_json'] ?? '[]' ), true );
		$row['scoreboard'] = json_decode( (string) ( $row['scoreboard_json'] ?? '' ), true );
		$row['event_seq'] = (int) ( $row['event_seq'] ?? 0 );
		return $row;
	}

	private static function obligation_map( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$out[ (string) $item['id'] ] = $item;
		}
		return $out;
	}

	private static function added_obligations( array $old, array $new ): array {
		$old_map = self::obligation_map( $old );
		$out = array();
		foreach ( self::obligation_map( $new ) as $id => $item ) {
			if ( ! isset( $old_map[ $id ] ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	private static function merge_status( string $old, string $new ): string {
		if ( $old === 'answered' ) {
			return 'answered';
		}
		if ( $old === 'deferred' ) {
			return $new === 'answered' ? 'answered' : 'deferred';
		}
		return in_array( $new, array( 'open', 'answered', 'deferred' ), true ) ? $new : $old;
	}

	private static function merge_priority( string $old, string $new ): string {
		$rank = array( 'nice_to_have' => 1, 'should' => 2, 'must' => 3 );
		return ( $rank[ $new ] ?? 2 ) > ( $rank[ $old ] ?? 2 ) ? $new : $old;
	}

	private static function safe_obligations( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id' => sanitize_key( (string) $item['id'] ),
				'question_hash' => self::hash_value( (string) ( $item['question'] ?? '' ) ),
				'type' => sanitize_key( (string) ( $item['type'] ?? '' ) ),
				'priority' => sanitize_key( (string) ( $item['priority'] ?? 'should' ) ),
			);
		}
		return $out;
	}

	private static function safe_scoreboard_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['obligation_id'] ) ) {
				continue;
			}
			$out[] = array(
				'obligation_id' => sanitize_key( (string) $row['obligation_id'] ),
				'coverage' => max( 0.0, min( 1.0, (float) ( $row['coverage'] ?? 0 ) ) ),
				'route' => strtoupper( sanitize_key( (string) ( $row['route'] ?? 'PATCH' ) ) ),
				'evidence_count' => count( (array) ( $row['evidence_ref'] ?? array() ) ),
			);
		}
		return $out;
	}

	private static function reflection_route( array $reflection ): string {
		$route = strtoupper( sanitize_key( (string) ( $reflection['route'] ?? '' ) ) );
		if ( in_array( $route, array( 'PASS', 'PATCH', 'RETRIEVE' ), true ) ) {
			return $route;
		}
		$has_retrieve = false;
		$has_patch = false;
		foreach ( (array) ( $reflection['resolution_scoreboard']['rows'] ?? array() ) as $row ) {
			$row_route = strtoupper( sanitize_key( (string) ( is_array( $row ) ? ( $row['route'] ?? '' ) : '' ) ) );
			$has_retrieve = $has_retrieve || $row_route === 'RETRIEVE';
			$has_patch = $has_patch || $row_route === 'PATCH';
		}
		return $has_retrieve ? 'RETRIEVE' : ( $has_patch ? 'PATCH' : 'PASS' );
	}

	private static function scrub_payload( array $payload ): array {
		foreach ( array( 'prompt', 'raw_text', 'answer', 'token', 'api_key', 'authorization', 'question', 'user_outcome', 'label', 'evidence_needed' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				unset( $payload[ $key ] );
			}
		}
		return $payload;
	}

	private static function event_id( string $uuid ): int {
		if ( class_exists( 'BizCity_Twin_Event_Store' ) && method_exists( 'BizCity_Twin_Event_Store', 'id_for_uuid' ) ) {
			return (int) BizCity_Twin_Event_Store::id_for_uuid( $uuid );
		}
		return 0;
	}

	private static function turn_id( array $opts, int $event_seq ): string {
		$turn_id = sanitize_text_field( (string) ( $opts['turn_id'] ?? $opts['trace_id'] ?? '' ) );
		return $turn_id !== '' ? substr( $turn_id, 0, 64 ) : 'event_' . max( 0, $event_seq );
	}

	private static function goal_path_key( string $goal_id ): string {
		$slug = preg_replace( '/[^a-zA-Z0-9_-]/', '', $goal_id );
		return substr( (string) $slug, 0, 48 ) . '-' . substr( sha1( $goal_id ), 0, 12 );
	}

	private static function hash_value( string $value ): string {
		return $value !== '' ? substr( sha1( $value ), 0, 16 ) : '';
	}

	private static function encode( $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '{}';
	}

	private static function cache_key( int $blog_id, string $goal_id ): string {
		return 'contract_' . $blog_id . '_' . sanitize_key( $goal_id );
	}

	private static function invalidate_cache( int $blog_id, string $goal_id ): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::delete( self::CACHE_GROUP, self::cache_key( $blog_id, $goal_id ) );
			BizCity_Cache::delete( self::CACHE_GROUP, 'active_list_' . $blog_id . '_100' );
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}

	private static function table_exists(): bool {
		$blog_id = (int) get_current_blog_id();
		if ( isset( self::$table_exists[ $blog_id ] ) ) {
			return self::$table_exists[ $blog_id ];
		}
		$table = self::table();
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			self::$table_exists[ $blog_id ] = (bool) bizcity_tbl_exists( $table );
			return self::$table_exists[ $blog_id ];
		}
		global $wpdb;
		$present = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1', $table ) );
		self::$table_exists[ $blog_id ] = (bool) $present;
		return self::$table_exists[ $blog_id ];
	}

	private static function invalidate_table_exists_cache(): void {
		unset( self::$table_exists[ (int) get_current_blog_id() ] );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( self::table() ), 'bizcity_tbl' );
		}
	}

	private static function operational_log( string $event, array $ctx, string $detail = '' ): void {
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'write_contract' ) ) {
			$ctx['detail'] = sanitize_key( $detail );
			// [2026-08-27 Johnny Chu] R-LOG-HYBRID — Goal Contract diagnostics use the registered contract identity.
			BizCity_JSONL_File_Logger::write_contract( 'core.twinbrain.goal_contract_trace', 'warn', $event, 'Goal Contract projection degraded.', $ctx );
		}
	}
}

if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		BizCity_TwinBrain_Goal_Contract_Store::TABLE_BASE,
		BizCity_TwinBrain_Goal_Contract_Store::MODULE_ID,
		BizCity_TwinBrain_Goal_Contract_Store::DB_VERSION,
		BizCity_TwinBrain_Goal_Contract_Store::DB_VERSION_OPTION,
		array( 'BizCity_TwinBrain_Goal_Contract_Store', 'ensure_schema' )
	);
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register(
		BizCity_TwinBrain_Goal_Contract_Store::CACHE_GROUP,
		BizCity_TwinBrain_Goal_Contract_Store::MODULE_ID,
		array(
			'contract_{blog_id}_{goal_id}' => array(
				'ttl' => BizCity_TwinBrain_Goal_Contract_Store::CACHE_TTL,
				'desc' => 'Current Goal Contract projection by tenant and goal ID',
			),
			'active_list_{blog_id}' => array(
				'ttl' => 30,
				'desc' => 'Active Goal Contract projections for Diagnostics/admin reads',
			),
			'scope_{blog_id}_{identity_hash}_{session_hash}' => array(
				'ttl' => BizCity_TwinBrain_Goal_Contract_Store::CACHE_TTL,
				'desc' => 'Current Goal Contract projection by tenant, identity, and session',
			),
		)
	);
}
