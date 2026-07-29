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
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — normalize event payload contract
	 * across pipeline/bridge/notifier writers. Unknown dimensions stay null.
	 */
	private function normalize_payload( $notebook_id, $event, array $payload, $job_id ) {
		$payload['notebook_id'] = (int) $notebook_id;

		$payload_job_id = isset( $payload['job_id'] ) ? (int) $payload['job_id'] : 0;
		$resolved_job_id = $payload_job_id > 0 ? $payload_job_id : (int) $job_id;
		$payload['job_id'] = $resolved_job_id > 0 ? $resolved_job_id : null;

		if ( ! array_key_exists( 'source_id', $payload ) ) {
			$payload['source_id'] = null;
		}
		if ( ! array_key_exists( 'passage_id', $payload ) ) {
			$payload['passage_id'] = null;
		}
		if ( ! array_key_exists( 'phase', $payload ) ) {
			$payload['phase'] = null;
		}
		if ( ! array_key_exists( 'status', $payload ) ) {
			if ( $event === 'progress' ) {
				$payload['status'] = 'processing';
			} elseif ( $event === 'done' ) {
				$payload['status'] = 'done';
			} else {
				$payload['status'] = null;
			}
		}

		if ( ! array_key_exists( 'progress', $payload ) ) {
			$progress = null;
			if ( isset( $payload['processed'], $payload['remaining'] ) ) {
				$processed = (int) $payload['processed'];
				$remaining = (int) $payload['remaining'];
				$total = $processed + $remaining;
				if ( $total > 0 ) {
					$progress = (float) $processed / (float) $total;
				}
			}
			$payload['progress'] = $progress;
		}

		if ( ! array_key_exists( 'reason', $payload ) ) {
			$reason = null;
			if ( ! empty( $payload['reason_code'] ) ) {
				$reason = sanitize_key( (string) $payload['reason_code'] );
			} elseif ( ! empty( $payload['error_code'] ) ) {
				$reason = sanitize_key( (string) $payload['error_code'] );
			}
			$payload['reason'] = $reason;
		}

		if ( isset( $payload['source_id'] ) && $payload['source_id'] !== null ) {
			$payload['source_id'] = (int) $payload['source_id'] > 0 ? (int) $payload['source_id'] : null;
		}
		if ( isset( $payload['passage_id'] ) && $payload['passage_id'] !== null ) {
			$payload['passage_id'] = (int) $payload['passage_id'] > 0 ? (int) $payload['passage_id'] : null;
		}
		if ( isset( $payload['reason'] ) && $payload['reason'] !== null ) {
			$payload['reason'] = sanitize_key( (string) $payload['reason'] );
		}

		return $payload;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — canonical allowlist for event
	 * payload fields exposed through REST/SSE/share surfaces.
	 */
	private static function payload_allowlist( $event, $scope = 'private' ) {
		$event = sanitize_key( (string) $event );
		$common = array(
			'notebook_id', 'job_id', 'source_id', 'passage_id',
			'phase', 'status', 'progress', 'reason',
			'level', 'msg', 'message', 'code', 'retry_after',
		);

		$map = array(
			'progress' => array( 'processed', 'total_triplets', 'errors', 'remaining', 'elapsed_s', 'triplets', 'cache_hit', 'in_flight', 'waiting', 'throttled', 'throttle_bail' ),
			'log'      => array( 'processed', 'total_triplets', 'errors', 'remaining', 'triplets', 'cache_hit', 'merged_into' ),
			'job'      => array( 'source_title', 'owner', 'merged_into' ),
			'done'     => array( 'entity_ids', 'duration_ms', 'stats', 'failed', 'error' ),
			'chat'     => array( 'message_id', 'role', 'content', 'meta' ),
			'quota_exhausted' => array( 'layer', 'used', 'cap', 'user_plan', 'plan_label', 'upgrade_url', 'admin_url', 'hub_url', 'diag' ),
		);

		$allowed = array_merge( $common, isset( $map[ $event ] ) ? $map[ $event ] : array() );
		if ( $scope === 'public' ) {
			$allowed = array_diff( $allowed, array( 'owner' ) );
		}
		return array_values( array_unique( $allowed ) );
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — redact credential-like substrings
	 * from user-visible message fields.
	 */
	private static function redact_text( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/=]+/i', '$1[redacted]', $value );
		$value = preg_replace( '/\b(?:biz|sk|pk|rk)-[A-Za-z0-9_\-]{8,}\b/i', '[redacted-key]', $value );
		$value = preg_replace( '/(?i)(api[_-]?key|token|secret|password)\s*[:=]\s*[^\s,;]+/', '$1=[redacted]', $value );
		return $value;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — sanitize nested payload blocks.
	 */
	private static function sanitize_nested_payload( $key, $value, $scope = 'private' ) {
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || $value === null ) {
			return $value;
		}

		if ( $key === 'entity_ids' ) {
			if ( ! is_array( $value ) ) {
				return array();
			}
			return array_values( array_map( 'intval', $value ) );
		}

		if ( $key === 'stats' && is_array( $value ) ) {
			$allowed = array( 'processed', 'total_triplets', 'errors', 'remaining', 'triplets_extracted', 'entities_approved', 'passages_processed', 'duration_ms' );
			$out = array();
			foreach ( $allowed as $k ) {
				if ( isset( $value[ $k ] ) ) {
					$out[ $k ] = is_numeric( $value[ $k ] ) ? (int) $value[ $k ] : self::redact_text( $value[ $k ] );
				}
			}
			return $out;
		}

		if ( $key === 'meta' && is_array( $value ) ) {
			$allowed = array( 'kind', 'job_id', 'source_id', 'notebook_id', 'status', 'phase', 'progress', 'reason' );
			$out = array();
			foreach ( $allowed as $k ) {
				if ( isset( $value[ $k ] ) ) {
					$vv = $value[ $k ];
					$out[ $k ] = ( is_int( $vv ) || is_float( $vv ) || is_bool( $vv ) || $vv === null ) ? $vv : self::redact_text( $vv );
				}
			}
			return $out;
		}

		if ( $key === 'diag' && is_array( $value ) ) {
			$allowed = array(
				'quota_per_user', 'quota_filter_hooked', 'exempt_filter_hooked',
				'entitlement_status', 'entitlement_tier', 'entitlement_balance', 'entitlement_bypass',
			);
			$out = array();
			foreach ( $allowed as $k ) {
				if ( isset( $value[ $k ] ) ) {
					$vv = $value[ $k ];
					$out[ $k ] = ( is_int( $vv ) || is_float( $vv ) || is_bool( $vv ) || $vv === null ) ? $vv : self::redact_text( $vv );
				}
			}
			return $out;
		}

		if ( is_scalar( $value ) ) {
			return self::redact_text( $value );
		}

		return $scope === 'public' ? array() : $value;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — centralized payload allowlist +
	 * redaction for REST/SSE/share read surfaces.
	 */
	public static function sanitize_payload_for_output( $event, array $payload, $scope = 'private' ) {
		$allowed = self::payload_allowlist( $event, $scope );
		$out = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}
			$out[ $key ] = self::sanitize_nested_payload( $key, $payload[ $key ], $scope );
		}

		if ( isset( $out['job_id'] ) ) {
			$out['job_id'] = (int) $out['job_id'] > 0 ? (int) $out['job_id'] : null;
		}
		if ( isset( $out['source_id'] ) ) {
			$out['source_id'] = (int) $out['source_id'] > 0 ? (int) $out['source_id'] : null;
		}
		if ( isset( $out['passage_id'] ) ) {
			$out['passage_id'] = (int) $out['passage_id'] > 0 ? (int) $out['passage_id'] : null;
		}
		if ( isset( $out['notebook_id'] ) ) {
			$out['notebook_id'] = (int) $out['notebook_id'] > 0 ? (int) $out['notebook_id'] : null;
		}
		if ( isset( $out['progress'] ) && is_numeric( $out['progress'] ) ) {
			$out['progress'] = (float) $out['progress'];
		}
		if ( isset( $out['reason'] ) && $out['reason'] !== null ) {
			$out['reason'] = sanitize_key( (string) $out['reason'] );
		}
		if ( isset( $out['status'] ) && $out['status'] !== null ) {
			$out['status'] = sanitize_key( (string) $out['status'] );
		}
		if ( isset( $out['phase'] ) && $out['phase'] !== null ) {
			$out['phase'] = sanitize_key( (string) $out['phase'] );
		}
		if ( isset( $out['level'] ) && $out['level'] !== null ) {
			$out['level'] = sanitize_key( (string) $out['level'] );
		}

		return $out;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — sanitize one event row for
	 * outbound API streams.
	 */
	public static function sanitize_event_row_for_output( array $row, $scope = 'private' ) {
		$event = isset( $row['event'] ) ? (string) $row['event'] : 'log';
		$raw_payload = isset( $row['payload'] ) ? $row['payload'] : array();
		$payload = array();
		if ( is_array( $raw_payload ) ) {
			$payload = $raw_payload;
		} elseif ( is_string( $raw_payload ) && $raw_payload !== '' ) {
			$dec = json_decode( $raw_payload, true );
			if ( is_array( $dec ) ) {
				$payload = $dec;
			} else {
				$payload = array( 'msg' => $raw_payload );
			}
		}

		$safe_payload = self::sanitize_payload_for_output( $event, $payload, $scope );
		return array(
			'id'      => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'job_id'  => isset( $row['job_id'] ) ? (int) $row['job_id'] : 0,
			'ts'      => isset( $row['ts'] ) ? (string) $row['ts'] : '',
			'event'   => $event,
			'payload' => $safe_payload,
		);
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
		$payload = $this->normalize_payload( $notebook_id, $event, $payload, $job_id );
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
	public function read_since( $notebook_id, $last_id = 0, $limit = 200, $scope = 'private' ) {
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
			$out[] = self::sanitize_event_row_for_output( $r, $scope );
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
