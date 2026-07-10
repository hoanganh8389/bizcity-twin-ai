<?php
/**
 * Broadcast Manager — CRUD cho bizcity_cg_broadcasts + bizcity_cg_broadcast_recipients.
 * Mọi read đi qua BizCity_Cache (group: bzcast).
 * Mọi write flush_group('bzcast').
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-BROADCAST (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Broadcast_Manager' ) ) {
	return;
}

class BizCity_Broadcast_Manager {

	const CACHE_GROUP = 'bzcast';
	const TTL         = 300; // 5 min

	// ── Broadcasts CRUD ──────────────────────────────────────────────────────

	/**
	 * Create a new broadcast.
	 *
	 * @param  array $data { name, type, meta_json, batch_size, delay_sec, created_by }
	 * @return int   New broadcast ID, 0 on failure.
	 */
	public static function insert( array $data ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — insert broadcast
		global $wpdb;
		$row = array(
			'name'       => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'type'       => sanitize_key( (string) ( $data['type'] ?? 'zns' ) ),
			'status'     => 'draft',
			'meta_json'  => isset( $data['meta_json'] ) ? wp_json_encode( $data['meta_json'] ) : null,
			'batch_size' => max( 1, min( 100, (int) ( $data['batch_size'] ?? 10 ) ) ),
			'delay_sec'  => max( 0, (int) ( $data['delay_sec'] ?? 5 ) ),
			'created_by' => (int) ( $data['created_by'] ?? get_current_user_id() ),
			'created_at' => current_time( 'mysql' ),
		);
		$wpdb->insert( $wpdb->prefix . 'bizcity_cg_broadcasts', $row );
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			self::flush();
		}
		return $id;
	}

	/**
	 * Get paginated list.
	 *
	 * @param  array $args { status, page, per_page, q }
	 * @return array { items[], total }
	 */
	public static function get_list( array $args = array() ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — get list with cache
		$status   = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$q        = sanitize_text_field( (string) ( $args['q'] ?? '' ) );

		$cache_key = 'list_' . md5( $status . '|' . $page . '|' . $per_page . '|' . $q );

		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'bizcity_cg_broadcasts';
		$offset = ( $page - 1 ) * $per_page;

		$where_parts = array( '1=1' );
		$where_vals  = array();

		if ( $status ) {
			$where_parts[] = 'status = %s';
			$where_vals[]  = $status;
		}
		if ( $q ) {
			$where_parts[] = 'name LIKE %s';
			$where_vals[]  = '%' . $wpdb->esc_like( $q ) . '%';
		}

		$where_sql = implode( ' AND ', $where_parts );

		if ( $where_vals ) {
			$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $where_vals );
			$items_sql = $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				array_merge( $where_vals, array( $per_page, $offset ) )
			);
		} else {
			$count_sql = "SELECT COUNT(*) FROM `{$table}`";
			$items_sql = $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset );
		}

		$total = (int) $wpdb->get_var( $count_sql );
		$items = $wpdb->get_results( $items_sql, ARRAY_A );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		foreach ( $items as &$item ) {
			$item = self::decode_meta( $item );
		}
		unset( $item );

		$result = array( 'items' => $items, 'total' => $total );

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result, self::TTL );
		}

		return $result;
	}

	/**
	 * Get single broadcast by ID.
	 *
	 * @param  int $id
	 * @return array|null
	 */
	public static function get_one( $id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — get single with cache
		$id = (int) $id;
		if ( ! $id ) {
			return null;
		}
		$cache_key = 'one_' . $id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}bizcity_cg_broadcasts` WHERE id = %d", $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row = self::decode_meta( $row );

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $row, self::TTL );
		}
		return $row;
	}

	/**
	 * Update broadcast row.
	 *
	 * @param  int   $id
	 * @param  array $data
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — update broadcast
		$id = (int) $id;
		if ( ! $id ) {
			return false;
		}

		$allowed = array( 'name', 'status', 'meta_json', 'batch_size', 'delay_sec', 'total_count', 'sent_count', 'failed_count', 'started_at', 'done_at' );
		$row     = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$val = $data[ $key ];
				if ( 'meta_json' === $key && is_array( $val ) ) {
					$val = wp_json_encode( $val );
				}
				$row[ $key ] = $val;
			}
		}
		if ( empty( $row ) ) {
			return false;
		}

		global $wpdb;
		$result = $wpdb->update( $wpdb->prefix . 'bizcity_cg_broadcasts', $row, array( 'id' => $id ) );
		if ( false !== $result ) {
			self::flush();
		}
		return false !== $result;
	}

	/**
	 * Delete broadcast + all its recipients.
	 *
	 * @param  int $id
	 * @return bool
	 */
	public static function delete( $id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — delete broadcast + recipients
		$id = (int) $id;
		if ( ! $id ) {
			return false;
		}
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'bizcity_cg_broadcast_recipients', array( 'broadcast_id' => $id ) );
		$result = $wpdb->delete( $wpdb->prefix . 'bizcity_cg_broadcasts', array( 'id' => $id ) );
		if ( false !== $result ) {
			self::flush();
		}
		return false !== $result;
	}

	// ── Recipients ───────────────────────────────────────────────────────────

	/**
	 * Bulk-insert recipients for a broadcast.
	 *
	 * @param  int   $broadcast_id
	 * @param  array $rows  [ { name, phone, email, custom_data } ]
	 * @return int   Number of rows inserted.
	 */
	public static function add_recipients( $broadcast_id, array $rows ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — bulk insert recipients
		$broadcast_id = (int) $broadcast_id;
		if ( ! $broadcast_id || empty( $rows ) ) {
			return 0;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$count   = 0;
		$chunks  = array_chunk( $rows, 200 );
		foreach ( $chunks as $chunk ) {
			$values = array();
			$placeholders = array();
			foreach ( $chunk as $r ) {
				$name    = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
				$phone   = sanitize_text_field( (string) ( $r['phone'] ?? '' ) );
				$email   = sanitize_email( (string) ( $r['email'] ?? '' ) );
				$custom  = isset( $r['custom_data'] ) ? wp_json_encode( $r['custom_data'] ) : null;
				$placeholders[] = '(%d, %s, %s, %s, %s, %s)';
				array_push( $values, $broadcast_id, $name, $phone ? $phone : null, $email ? $email : null, $custom, 'queued' );
			}
			$sql = "INSERT INTO `{$table}` (broadcast_id, name, phone, email, custom_data, status) VALUES " . implode( ', ', $placeholders );
			$wpdb->query( $wpdb->prepare( $sql, $values ) );
			$count += count( $chunk );
		}

		// Update total_count
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE broadcast_id = %d", $broadcast_id ) );
		self::update( $broadcast_id, array( 'total_count' => $total ) );

		self::flush();
		return $count;
	}

	/**
	 * Get next batch of queued recipients for a broadcast.
	 *
	 * @param  int $broadcast_id
	 * @param  int $limit
	 * @return array
	 */
	public static function get_next_batch( $broadcast_id, $limit = 10 ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — get next queued batch
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE broadcast_id = %d AND status = 'queued' ORDER BY id ASC LIMIT %d",
				$broadcast_id, $limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a recipient as sent or failed.
	 *
	 * @param int    $recipient_id
	 * @param bool   $success
	 * @param string $error
	 */
	public static function mark_recipient( $recipient_id, $success, $error = '' ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — mark recipient result
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bizcity_cg_broadcast_recipients',
			array(
				'status'  => $success ? 'sent' : 'failed',
				'error'   => $error ? $error : null,
				'sent_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $recipient_id )
		);
	}

	/**
	 * Get progress counters for a broadcast.
	 *
	 * @param  int $broadcast_id
	 * @return array { total, queued, sent, failed }
	 */
	public static function get_progress( $broadcast_id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — get progress counters
		$broadcast_id = (int) $broadcast_id;
		$cache_key    = 'progress_' . $broadcast_id;

		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS cnt FROM `{$table}` WHERE broadcast_id = %d GROUP BY status",
				$broadcast_id
			),
			ARRAY_A
		);

		$progress = array( 'total' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0 );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$cnt = (int) $row['cnt'];
				$progress['total'] += $cnt;
				if ( isset( $progress[ $row['status'] ] ) ) {
					$progress[ $row['status'] ] = $cnt;
				}
			}
		}

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $progress, 60 ); // 1 min cache for progress
		}

		return $progress;
	}

	/**
	 * Sync sent_count + failed_count from recipients table into broadcasts table.
	 *
	 * @param int $broadcast_id
	 */
	public static function sync_counters( $broadcast_id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — sync counters
		$progress = self::get_progress( $broadcast_id );
		self::update( (int) $broadcast_id, array(
			'sent_count'   => $progress['sent'],
			'failed_count' => $progress['failed'],
			'total_count'  => $progress['total'],
		) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Flush all bzcast cache.
	 */
	private static function flush() {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}

	/**
	 * Decode meta_json field in a broadcast row.
	 *
	 * @param  array $row
	 * @return array
	 */
	private static function decode_meta( array $row ) {
		if ( isset( $row['meta_json'] ) && $row['meta_json'] ) {
			$meta = json_decode( $row['meta_json'], true );
			$row['meta'] = is_array( $meta ) ? $meta : array();
		} else {
			$row['meta'] = array();
		}
		return $row;
	}
}

// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Cache Registry.
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzcast', 'core.cg.broadcast', array(
		'list_{args_hash}'     => array( 'ttl' => 300, 'desc' => 'Paginated broadcast list' ),
		'one_{id}'             => array( 'ttl' => 300, 'desc' => 'Single broadcast row'     ),
		'progress_{id}'        => array( 'ttl' =>  60, 'desc' => 'Broadcast progress counters' ),
	) );
}
