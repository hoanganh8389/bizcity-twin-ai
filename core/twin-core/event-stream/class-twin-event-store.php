<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity_Twin_Event_Store — Thin persistence layer for `bizcity_twin_event_stream`.
 *
 * Phase 0.12 Wave A — separated from Event_Bus so projectors / CLI / replay can
 * also read without depending on dispatch logic. ONLY this class is allowed to
 * INSERT into the stream table (Event_Bus calls it).
 *
 * Per R-EVT-1, R-EVT-6.
 *
 * @since 2026-04-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Event_Store {

	/**
	 * Persist an event row. Returns the inserted DB id (or 0 on failure).
	 *
	 * Resolves parent_event_id from parent_event_uuid if needed.
	 *
	 * @param array $event Fully-built envelope (see Event_Bus::build_envelope).
	 * @return int Inserted id, 0 on failure.
	 */
	public static function persist( array $event ): int {
		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();

		// Resolve parent_event_id from parent_event_uuid if not yet set
		if ( empty( $event['parent_event_id'] ) && ! empty( $event['parent_event_uuid'] ) ) {
			$event['parent_event_id'] = self::id_for_uuid( $event['parent_event_uuid'] );
		}

		$row = [
			'event_uuid'        => $event['event_uuid'],
			'trace_id'          => $event['trace_id'],
			'conversation_id'   => $event['conversation_id'] ?? null,
			'session_id'        => $event['session_id'] ?? null,
			'user_id'           => (int) ( $event['user_id'] ?? 0 ),
			'blog_id'           => (int) ( $event['blog_id'] ?? get_current_blog_id() ),
			'event_type'        => $event['event_type'],
			'event_source'      => $event['event_source'],
			'parent_event_id'   => $event['parent_event_id'] ?? null,
			'parent_event_uuid' => $event['parent_event_uuid'] ?? null,
			'payload_json'      => is_string( $event['payload_json'] ?? null )
				? $event['payload_json']
				: wp_json_encode( $event['payload'] ?? [] ),
			'schema_version'    => (int) ( $event['schema_version'] ?? 1 ),
			'created_at'        => $event['created_at'],
			'created_epoch_ms'  => (int) $event['created_epoch_ms'],
		];

		$ok = $wpdb->insert( $table, $row );
		if ( $ok === false ) {
			// Duplicate UUID (idempotency for ingest_remote) — return existing id silently.
			$existing = self::id_for_uuid( $event['event_uuid'] );
			if ( $existing > 0 ) return $existing;
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Look up the DB id for an event_uuid. Returns 0 if not found.
	 */
	public static function id_for_uuid( string $event_uuid ): int {
		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE event_uuid = %s LIMIT 1",
			$event_uuid
		) );
		return (int) ( $id ?: 0 );
	}

	/**
	 * True if an event with this UUID already exists (used for ingest dedupe).
	 */
	public static function exists( string $event_uuid ): bool {
		return self::id_for_uuid( $event_uuid ) > 0;
	}

	/**
	 * Fetch events for a trace_id, chronologically ordered.
	 *
	 * @param string $trace_id
	 * @param array  $opts {
	 *   @type int    $limit       Max rows (default 500).
	 *   @type string $after_uuid  Only return events after this UUID (cursor).
	 *   @type string $event_type  Filter by event_type.
	 *   @type string $event_source Filter by event_source.
	 * }
	 * @return array<int, array> Rows with payload_json decoded into payload.
	 */
	public static function fetch_for_trace( string $trace_id, array $opts = [] ): array {
		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();

		$limit  = max( 1, min( 5000, (int) ( $opts['limit'] ?? 500 ) ) );
		$where  = [ 'trace_id = %s' ];
		$params = [ $trace_id ];

		if ( ! empty( $opts['after_uuid'] ) ) {
			$cursor_id = self::id_for_uuid( $opts['after_uuid'] );
			if ( $cursor_id > 0 ) {
				$where[]  = 'id > %d';
				$params[] = $cursor_id;
			}
		}
		if ( ! empty( $opts['event_type'] ) ) {
			$where[]  = 'event_type = %s';
			$params[] = $opts['event_type'];
		}
		if ( ! empty( $opts['event_source'] ) ) {
			$where[]  = 'event_source = %s';
			$params[] = $opts['event_source'];
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			 . " ORDER BY id ASC LIMIT %d";
		$params[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) return [];

		foreach ( $rows as &$r ) {
			$r['payload'] = json_decode( (string) $r['payload_json'], true ) ?: [];
		}
		return $rows;
	}
}
