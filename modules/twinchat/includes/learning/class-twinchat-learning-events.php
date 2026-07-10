<?php
/**
 * Bizcity TwinChat — Learning Events
 *
 * Phase 4.9 — append-only ring buffer powering the SSE stream.
 * Each event row is { notebook_id, job_id?, ts, event, payload(JSON) }.
 *
 * Event names emitted by the pipeline:
 *   - log         { level: 'info'|'ok'|'warn'|'error'|'step', msg: string }
 *   - progress    { processed:int, total_triplets:int, errors:int, remaining?:int }
 *   - job         { job_id, status, source_title? }
 *   - done        { job_id, source_id?, entity_ids[], duration_ms, stats:{...} }
 *   - chat        { message_id, role:'system', content, meta:{kind:'learning_done', ...} }
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since 2026-04-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Events {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Append a single event row.
	 *
	 * @param int    $notebook_id
	 * @param string $event   short event name (log|progress|done|job|chat)
	 * @param array  $payload arbitrary JSON-able payload
	 * @param int    $job_id  optional
	 * @return int inserted row id (0 on failure)
	 */
	public function push( $notebook_id, $event, array $payload = [], $job_id = 0 ) {
		global $wpdb;
		$db = BizCity_TwinChat_Learning_Database::instance();
		if ( ! $db->is_ready() ) {
			return 0;
		}
		$notebook_id = (int) $notebook_id;
		$event       = substr( (string) $event, 0, 32 );
		if ( $notebook_id <= 0 || $event === '' ) {
			return 0;
		}
		$tbl = $db->table_events();
		$ok  = $wpdb->insert( $tbl, [
			'notebook_id' => $notebook_id,
			'job_id'      => $job_id > 0 ? (int) $job_id : null,
			'ts'          => current_time( 'mysql', true ),
			'event'       => $event,
			'payload'     => wp_json_encode( $payload ),
		] );
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		// Opportunistic ring trim — once every ~50 inserts, sample by id.
		if ( $id % 50 === 0 ) {
			BizCity_TwinChat_Learning_Database::instance()->trim_events( $notebook_id );
		}
		return $id;
	}

	/**
	 * Read events with id > $last_id for a notebook, oldest first.
	 *
	 * @param int $notebook_id
	 * @param int $last_id
	 * @param int $limit
	 * @return array<int,array{ id:int, ts:string, event:string, payload:mixed, job_id:int }>
	 */
	public function read_since( $notebook_id, $last_id = 0, $limit = 200 ) {
		global $wpdb;
		$db = BizCity_TwinChat_Learning_Database::instance();
		if ( ! $db->is_ready() ) {
			return [];
		}
		$tbl = $db->table_events();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, job_id, ts, event, payload
			   FROM {$tbl}
			  WHERE notebook_id=%d AND id > %d
			  ORDER BY id ASC
			  LIMIT %d",
			(int) $notebook_id, (int) $last_id, max( 1, min( 1000, (int) $limit ) )
		), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$payload = null;
			if ( ! empty( $r['payload'] ) ) {
				$dec     = json_decode( $r['payload'], true );
				$payload = ( JSON_ERROR_NONE === json_last_error() ) ? $dec : $r['payload'];
			}
			$out[] = [
				'id'      => (int) $r['id'],
				'job_id'  => (int) $r['job_id'],
				'ts'      => (string) $r['ts'],
				'event'   => (string) $r['event'],
				'payload' => $payload,
			];
		}
		return $out;
	}

	/** Latest event id for a notebook (for "since" cursor on first connect). */
	public function latest_id( $notebook_id ) {
		global $wpdb;
		$db = BizCity_TwinChat_Learning_Database::instance();
		if ( ! $db->is_ready() ) {
			return 0;
		}
		$tbl = $db->table_events();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT IFNULL(MAX(id),0) FROM {$tbl} WHERE notebook_id=%d",
			(int) $notebook_id
		) );
	}
}
